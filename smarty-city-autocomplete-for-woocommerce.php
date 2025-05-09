<?php
/**
 * Plugin Name:             SM - City Autocomplete for WooCommerce
 * Plugin URI:              https://github.com/mnestorov/smarty-city-autocomplete-for-woocommerce
 * Description:             Replaces the WooCommerce city field with autocomplete and auto-fills postcode from a GeoNames TXT file.
 * Version:                 1.0.0
 * Author:                  Martin Nestorov
 * Author URI:              https://github.com/mnestorov
 * License:                 GPL-2.0+
 * License URI:             http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:             smarty-google-feed-generator
 * WC requires at least:    3.5.0
 * WC tested up to:         9.6.0
 * Requires Plugins:        woocommerce
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die;
}

if (!function_exists('smarty_ca_override_checkout_fields')) {
    /**
     * Override WooCommerce checkout fields:
     * - Hide the postcode field
     * - Replace the city field with an autocomplete text input
     *
     * @since 1.0.0
     * @param array $fields WooCommerce checkout fields.
     * @return array Modified checkout fields.
     */
    function smarty_ca_override_checkout_fields($fields) {
        // Hide postcode field
        $fields['billing']['billing_postcode']['required'] = false;
        $fields['billing']['billing_postcode']['custom_attributes']['readonly'] = 'readonly';
        $fields['billing']['billing_postcode']['class'][] = 'smarty-hidden';

        // Replace city field with custom text input
        $fields['billing']['billing_city'] = array(
            'type' => 'text',
            'label' => __('City / Village', 'woocommerce'),
            'required' => true,
            'class' => array('form-row-wide'),
            'custom_attributes' => array('autocomplete' => 'off', 'id' => 'smarty-autocomplete-city'),
        );

        return $fields;
    }
    add_filter('woocommerce_checkout_fields', 'smarty_ca_override_checkout_fields');
}

if (!function_exists('smarty_ca_hidden_postcode_input')) {
    /**
     * Output inline CSS to hide postcode field via the "smarty-hidden" class.
     *
     * @since 1.0.0
     * @return void
     */
    function smarty_ca_hidden_postcode_input() {
        echo '<style>.smarty-hidden {display:none !important;}</style>';
    }
    add_action('woocommerce_after_checkout_billing_form', 'smarty_ca_hidden_postcode_input');
}

if (!function_exists('smarty_ca_enqueue_scripts')) {
    /**
     * Enqueue JavaScript dependencies for city autocomplete on WooCommerce checkout.
     *
     * @since 1.0.0
     * @return void
     */
    function smarty_ca_enqueue_scripts() {
        if (is_checkout()) {
            wp_enqueue_script('jquery-ui-autocomplete');
            wp_enqueue_script('smarty-city-autocomplete', plugin_dir_url(__FILE__) . 'js/smarty-ca-public.js', ['jquery', 'jquery-ui-autocomplete'], '1.0', true);
            wp_localize_script('smarty-city-autocomplete', 'smartyCityAjax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'country' => WC()->customer->get_billing_country() ?: 'BG',
            ]);
        }
    }
    add_action('wp_enqueue_scripts', 'smarty_ca_enqueue_scripts');
}

if (!function_exists('smarty_ca_get_city_suggestions')) {
    /**
     * Handle AJAX request for city suggestions based on the GeoNames TXT file.
     * Returns a list of matching cities and their postal codes.
     *
     * @since 1.0.0
     * @return void JSON response.
     */
    function smarty_ca_get_city_suggestions() {
        $term = sanitize_text_field($_GET['term'] ?? '');
        $country = sanitize_text_field($_GET['country'] ?? 'BG');
        if (strlen($term) < 2) wp_send_json([]);

        $file_path = plugin_dir_path(__FILE__) . 'data/' . $country . '.txt';
        if (!file_exists($file_path)) wp_send_json([]);

        $results = [];
        $handle = fopen($file_path, 'r');
        if ($handle) {
            while (($line = fgets($handle)) !== false && count($results) < 10) {
                $parts = explode("\t", $line);
                if (count($parts) < 3) continue;

                [$cc, $zip, $city] = [$parts[0], $parts[1], $parts[2]];

                if (stripos($city, $term) !== false) {
                    $results[] = [
                        'city' => $city,
                        'postal_code' => $zip
                    ];
                }
            }
            fclose($handle);
        }

        wp_send_json($results);
    }
    add_action('wp_ajax_smarty_get_city_suggestions', 'smarty_ca_get_city_suggestions');
    add_action('wp_ajax_nopriv_smarty_get_city_suggestions', 'smarty_ca_get_city_suggestions');
}
