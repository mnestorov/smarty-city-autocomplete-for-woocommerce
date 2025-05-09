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
        $country = WC()->customer->get_billing_country() ?: 'BG';
        $enabled_countries = get_option('smarty_ca_enabled_countries', []);

        if (!in_array($country, $enabled_countries)) {
            return $fields; // Don’t modify fields if country not enabled
        }

        $fields['billing']['billing_postcode']['required'] = false;
        $fields['billing']['billing_postcode']['custom_attributes']['readonly'] = 'readonly';
        $fields['billing']['billing_postcode']['class'][] = 'smarty-hidden';

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
        if (!is_checkout()) return;

        $country = WC()->customer->get_billing_country() ?: 'BG';
        $enabled = get_option('smarty_ca_enabled_countries', []);
        if (!in_array($country, $enabled)) return;

        wp_enqueue_script('jquery-ui-autocomplete');
        wp_enqueue_script('smarty-city-autocomplete', plugin_dir_url(__FILE__) . 'js/smarty-ca-public.js', ['jquery', 'jquery-ui-autocomplete'], '1.1', true);
        wp_localize_script('smarty-city-autocomplete', 'smartyCityAjax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'country' => $country,
        ]);
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

        $enabled = get_option('smarty_ca_enabled_countries', []);
        if (!in_array($country, $enabled)) wp_send_json([]);

        $file_path = plugin_dir_path(__FILE__) . 'data/' . $country . '.txt';
        if (!file_exists($file_path)) wp_send_json([]);

        $cache_key = 'smarty_ca_cities_' . $country;
        $cities = get_transient($cache_key);

        if ($cities === false) {
            $cities = [];
            $handle = fopen($file_path, 'r');
            if ($handle) {
                while (($line = fgets($handle)) !== false) {
                    $parts = explode("\t", $line);
                    if (count($parts) < 3) continue;
                    [$cc, $zip, $city] = [$parts[0], $parts[1], $parts[2]];
                    $cities[] = ['city' => $city, 'postal_code' => $zip];
                }
                fclose($handle);
                set_transient($cache_key, $cities, DAY_IN_SECONDS);
            }
        }

        $filtered = array_filter($cities, function ($entry) use ($term) {
            return stripos($entry['city'], $term) !== false;
        });

        wp_send_json(array_slice(array_values($filtered), 0, 10));
    }
    add_action('wp_ajax_smarty_get_city_suggestions', 'smarty_ca_get_city_suggestions');
    add_action('wp_ajax_nopriv_smarty_get_city_suggestions', 'smarty_ca_get_city_suggestions');
}

// ==================== ADMIN SETTINGS ==================== //

if (!function_exists('smarty_ca_register_menu')) {
    /**
     * Add admin menu item under WooCommerce.
     */
    function smarty_ca_register_menu() {
        add_submenu_page(
            'woocommerce',
            __('City Autocomplete Settings', 'woocommerce'),
            __('City Autocomplete', 'woocommerce'),
            'manage_options',
            'smarty-ca-settings',
            'smarty_ca_render_settings_page'
        );
    }
    add_action('admin_menu', 'smarty_ca_register_menu');
}

if (!function_exists('smarty_ca_register_settings')) {
    /**
     * Register plugin settings.
     */
    function smarty_ca_register_settings() {
        register_setting('smarty_ca_options', 'smarty_ca_enabled_countries');

        add_settings_section('smarty_ca_main_section', '', null, 'smarty-ca-settings');

        add_settings_field(
            'smarty_ca_enabled_countries',
            __('Enabled Countries', 'woocommerce'),
            'smarty_ca_country_checkboxes',
            'smarty-ca-settings',
            'smarty_ca_main_section'
        );
    }
    add_action('admin_init', 'smarty_ca_register_settings');
}

if (!function_exists('smarty_ca_country_checkboxes')) {
    /**
     * Render checkboxes for each TXT file in /data.
     */
    function smarty_ca_country_checkboxes() {
        $enabled = get_option('smarty_ca_enabled_countries', []);
        $files = glob(plugin_dir_path(__FILE__) . 'data/*.txt');

        if (!$files) {
            echo '<p>No country data files found in <code>/data/</code> folder.</p>';
            return;
        }

        foreach ($files as $file) {
            $code = strtoupper(basename($file, '.txt'));
            $checked = in_array($code, $enabled) ? 'checked' : '';
            echo "<label><input type='checkbox' name='smarty_ca_enabled_countries[]' value='$code' $checked> $code</label><br>";
        }
    }
}

if (!function_exists('smarty_ca_render_settings_page')) {
    /**
     * Render the settings page.
     */
    function smarty_ca_render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('City Autocomplete | Settings', 'woocommerce'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('smarty_ca_options');
                do_settings_sections('smarty-ca-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}