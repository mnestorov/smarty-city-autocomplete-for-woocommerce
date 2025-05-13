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
 * Text Domain:             smarty-city-autocomplete
 * WC requires at least:    3.5.0
 * WC tested up to:         9.6.0
 * Requires Plugins:        woocommerce
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die;
}

// ==================== FRONTEND FIELD OVERRIDE ==================== //

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
        $enabled_countries = smarty_ca_get_enabled_countries();
        $priority = get_option('smarty_ca_city_priority', 45);

        if (!in_array($country, $enabled_countries)) {
            return $fields;
        }

        $fields['billing']['billing_postcode']['required'] = false;
        $fields['billing']['billing_postcode']['custom_attributes']['readonly'] = 'readonly';
        $fields['billing']['billing_postcode']['class'][] = 'smarty-hidden';

        $fields['billing']['billing_city'] = array(
            'type'      => 'select',
            'label'     => __('City', 'woocommerce'),
            'required'  => true,
            'class'     => array('form-row-wide', 'smarty-select2-city'),
            'options'   => array('' => __('', 'woocommerce')),
            'priority'  => (int) $priority,
        );

        uksort($fields['billing'], function($a, $b) use ($fields) {
            return ($fields['billing'][$a]['priority'] ?? 10) <=> ($fields['billing'][$b]['priority'] ?? 10);
        });

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



// ==================== JS / ASSETS ==================== //

if (!function_exists('smarty_ca_enqueue_admin_scripts')) {
    function smarty_ca_enqueue_admin_scripts($hook) {
     
        wp_enqueue_style('smarty-ca-admin-css', plugin_dir_url(__FILE__) . 'css/smarty-ca-admin.css', array(), '1.0.0');
        wp_enqueue_script('smarty-ca-admin-js', plugin_dir_url(__FILE__) . 'js/smarty-ca-admin.js', ['jquery'], '1.0.0', true);

        wp_localize_script(
            'smarty-ca-admin-js',
            'smartyCityAutocomplete',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('smarty_ca_nonce'),
            ]
        );
    }
    add_action('admin_enqueue_scripts', 'smarty_ca_enqueue_admin_scripts');
}

if (!function_exists('smarty_ca_enqueue_public_scripts')) {
    function smarty_ca_enqueue_public_scripts() {
        if (!is_checkout()) return;

        $country = WC()->customer->get_billing_country() ?: 'BG';
        $enabled = smarty_ca_get_enabled_countries();
        if (!in_array($country, $enabled)) return;

        wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');
        wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], '4.1.0', true);

        wp_enqueue_script('smarty-ca-public-js', plugin_dir_url(__FILE__) . 'js/smarty-ca-public.js', ['jquery', 'select2'], '1.1', true);

       wp_localize_script(
            'smarty-ca-public-js',
            'smartyCityAjax',
            [
                'ajaxUrl'       => admin_url('admin-ajax.php'),
                'country'       => $country,
                'nonce'         => wp_create_nonce('smarty_ca_nonce'),
                'inputTooShort' => __('Please enter 2 or more characters', 'smarty-city-autocomplete'),
                'searching'     => __('Searching…', 'smarty-city-autocomplete'),
                'noResults'     => __('No results found', 'smarty-city-autocomplete'),
                'loadingMore'   => __('Loading more results…', 'smarty-city-autocomplete'),
            ]
        );
    }
    add_action('wp_enqueue_scripts', 'smarty_ca_enqueue_public_scripts');
}

// ==================== AJAX ==================== //

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

        $enabled = smarty_ca_get_enabled_countries();
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
                    $cities[] = ['city' => trim($city), 'postal_code' => $zip];
                }
                fclose($handle);
                set_transient($cache_key, $cities, DAY_IN_SECONDS);
            }
        }

        $filtered = array_filter($cities, function ($entry) use ($term) {
            $normalize = function ($string) {
                // Convert to UTF-8 NFC form
                $string = Normalizer::normalize($string, Normalizer::FORM_D);

                // Remove diacritical marks using regex
                $string = preg_replace('/[\p{Mn}]/u', '', $string);

                return mb_strtolower($string);
            };

            return strpos($normalize($entry['city']), $normalize($term)) !== false;
        });

        wp_send_json(array_slice(array_values($filtered), 0, 10));
    }
    add_action('wp_ajax_smarty_get_city_suggestions', 'smarty_ca_get_city_suggestions');
    add_action('wp_ajax_nopriv_smarty_get_city_suggestions', 'smarty_ca_get_city_suggestions');
}

// ==================== SANITIZE CITY ==================== //

/**
 * Clean city name before saving to the order.
 *
 * @param string $city
 * @return string
 */
function smarty_ca_clean_city_on_checkout($city) {
    if (strpos($city, ' / ') !== false) {
        $city = explode(' / ', $city)[0];
    }
    return trim($city);
}

add_action('woocommerce_checkout_create_order', function($order, $data) {
    if (!empty($data['billing']['billing_city'])) {
        $order->set_billing_city(smarty_ca_clean_city_on_checkout($data['billing']['billing_city']));
    }
    if (!empty($data['shipping']['shipping_city'])) {
        $order->set_shipping_city(smarty_ca_clean_city_on_checkout($data['shipping']['shipping_city']));
    }
}, 20, 2);

add_filter('woocommerce_order_formatted_billing_address', function($address, $order) {
    if (!empty($address['city'])) {
        $address['city'] = smarty_ca_clean_city_on_checkout($address['city']);
    }
    return $address;
}, 10, 2);

add_filter('woocommerce_order_formatted_shipping_address', function($address, $order) {
    if (!empty($address['city'])) {
        $address['city'] = smarty_ca_clean_city_on_checkout($address['city']);
    }
    return $address;
}, 10, 2);

/**
 * Force-clean the city fields before they are saved into order meta.
 *
 * @param int $order_id
 */
add_action('woocommerce_checkout_update_order_meta', function($order_id) {
    $order = wc_get_order($order_id);

    $billing_city = $order->get_billing_city();
    if ($billing_city && strpos($billing_city, ' / ') !== false) {
        $clean_billing_city = smarty_ca_clean_city_on_checkout($billing_city);
        update_post_meta($order_id, '_billing_city', $clean_billing_city);
    }

    $shipping_city = $order->get_shipping_city();
    if ($shipping_city && strpos($shipping_city, ' / ') !== false) {
        $clean_shipping_city = smarty_ca_clean_city_on_checkout($shipping_city);
        update_post_meta($order_id, '_shipping_city', $clean_shipping_city);
    }
});

// Ensure city is cleaned in $_POST before WC saves to DB
add_filter('woocommerce_process_checkout_field_billing_city', 'smarty_ca_clean_city_on_checkout');
add_filter('woocommerce_process_checkout_field_shipping_city', 'smarty_ca_clean_city_on_checkout');

add_filter('woocommerce_checkout_posted_data', function($data) {
    if (!empty($data['billing_city'])) {
        $data['billing_city'] = smarty_ca_clean_city_on_checkout($data['billing_city']);
    }

    if (!empty($data['shipping_city'])) {
        $data['shipping_city'] = smarty_ca_clean_city_on_checkout($data['shipping_city']);
    }

    return $data;
});

/**
 * Final cleanup after WooCommerce saves the meta.
 * This ensures / translations are removed from DB after save.
 *
 * @param int $order_id
 */
add_action('woocommerce_checkout_order_processed', function($order_id) {
    $billing_city = get_post_meta($order_id, '_billing_city', true);
    if ($billing_city && strpos($billing_city, ' / ') !== false) {
        update_post_meta($order_id, '_billing_city', smarty_ca_clean_city_on_checkout($billing_city));
    }

    $shipping_city = get_post_meta($order_id, '_shipping_city', true);
    if ($shipping_city && strpos($shipping_city, ' / ') !== false) {
        update_post_meta($order_id, '_shipping_city', smarty_ca_clean_city_on_checkout($shipping_city));
    }
}, 100); // priority 100 ensures it runs AFTER Woo saves meta

// ==================== ADMIN SETTINGS ==================== //

if (!function_exists('smarty_ca_register_menu')) {
    /**
     * Add admin menu item under WooCommerce.
     */
    function smarty_ca_register_menu() {
        add_submenu_page(
            'woocommerce',
            __('City Autocomplete Settings', 'smarty-city-autocomplete'),
            __('City Autocomplete', 'smarty-city-autocomplete'),
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
        register_setting('smarty_ca_options', 'smarty_ca_city_priority', [
            'type' => 'integer',
            'sanitize_callback' => function($val) {
                return max(0, min(999, (int)$val));
            },
        ]);

        add_settings_section('smarty_ca_main_section', '', null, 'smarty-ca-settings');

        add_settings_field(
            'smarty_ca_enabled_countries',
            __('Enabled Countries', 'smarty-city-autocomplete'),
            'smarty_ca_country_checkboxes',
            'smarty-ca-settings',
            'smarty_ca_main_section'
        );

        add_settings_field(
            'smarty_ca_city_priority',
            __('City Field Priority', 'smarty-city-autocomplete'),
            'smarty_ca_city_priority_input',
            'smarty-ca-settings',
            'smarty_ca_main_section'
        );
    }
    add_action('admin_init', 'smarty_ca_register_settings');
}

if (!function_exists('smarty_ca_get_enabled_countries')) {
    function smarty_ca_get_enabled_countries() {
        $enabled = get_option('smarty_ca_enabled_countries');
        return is_array($enabled) ? $enabled : [];
    }
}

if (!function_exists('smarty_ca_city_priority_input')) {
    function smarty_ca_city_priority_input() {
        $value = get_option('smarty_ca_city_priority', 45);
        echo "<input type='number' name='smarty_ca_city_priority' value='" . esc_attr($value) . "' min='0' max='999' />";
        echo "<p class='description'>" . __('Lower numbers show earlier. Default: 45', 'smarty-city-autocomplete') . "</p>";
    }
}

if (!function_exists('smarty_ca_country_checkboxes')) {
    /**
     * Render checkboxes for each TXT file in /data.
     */
    function smarty_ca_country_checkboxes() {
        $enabled = smarty_ca_get_enabled_countries();
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
            <h1><?php esc_html_e('City Autocomplete | Settings', 'smarty-city-autocomplete'); ?></h1>
            <div id="smarty-ca-settings-container">
                <div>
                    <form method="post" action="options.php">
                        <?php
                        settings_fields('smarty_ca_options');
                        do_settings_sections('smarty-ca-settings');
                        submit_button();
                        ?>
                    </form>
                </div>
                <div id="smarty-ca-tabs-container">
                    <div>
                        <h2 class="smarty-ca-nav-tab-wrapper">
                            <a href="#smarty-ca-documentation" class="smarty-ca-nav-tab smarty-ca-nav-tab-active"><?php esc_html_e('Documentation', 'smarty-city-autocomplete'); ?></a>
                            <a href="#smarty-ca-changelog" class="smarty-ca-nav-tab"><?php esc_html_e('Changelog', 'smarty-city-autocomplete'); ?></a>
                        </h2>
                        <div id="smarty-ca-documentation" class="smarty-ca-tab-content active">
                            <div class="smarty-ca-view-more-container">
                                <p><?php esc_html_e('Click "View More" to load the plugin documentation.', 'smarty-city-autocomplete'); ?></p>
                                <button id="smarty-ca-load-readme-btn" class="button button-primary">
                                    <?php esc_html_e('View More', 'smarty-city-autocomplete'); ?>
                                </button>
                            </div>
                            <div id="smarty-ca-readme-content" style="margin-top: 20px;"></div>
                        </div>
                        <div id="smarty-ca-changelog" class="smarty-ca-tab-content">
                            <div class="smarty-ca-view-more-container">
                                <p><?php esc_html_e('Click "View More" to load the plugin changelog.', 'smarty-city-autocomplete'); ?></p>
                                <button id="smarty-ca-load-changelog-btn" class="button button-primary">
                                    <?php esc_html_e('View More', 'smarty-city-autocomplete'); ?>
                                </button>
                            </div>
                            <div id="smarty-ca-changelog-content" style="margin-top: 20px;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

if (!function_exists('smarty_ca_load_readme')) {
    /**
     * AJAX handler to load and parse the README.md content.
     */
    function smarty_ca_load_readme() {
        check_ajax_referer('smarty_ca_nonce', 'nonce');
    
        if (!current_user_can('manage_options')) {
            wp_send_json_error('You do not have sufficient permissions.');
        }
    
        $readme_path = plugin_dir_path(__FILE__) . 'README.md';
        if (file_exists($readme_path)) {
            // Include Parsedown library
            if (!class_exists('Parsedown')) {
                require_once plugin_dir_path(__FILE__) . 'libs/Parsedown.php';
            }
    
            $parsedown = new Parsedown();
            $markdown_content = file_get_contents($readme_path);
            $html_content = $parsedown->text($markdown_content);
    
            // Remove <img> tags from the content
            $html_content = preg_replace('/<img[^>]*>/', '', $html_content);
    
            wp_send_json_success($html_content);
        } else {
            wp_send_json_error('README.md file not found.');
        }
    }    
    add_action('wp_ajax_smarty_ca_load_readme', 'smarty_ca_load_readme');
}

if (!function_exists('smarty_ca_load_changelog')) {
    /**
     * AJAX handler to load and parse the CHANGELOG.md content.
     */
    function smarty_ca_load_changelog() {
        check_ajax_referer('smarty_ca_nonce', 'nonce');
    
        if (!current_user_can('manage_options')) {
            wp_send_json_error('You do not have sufficient permissions.');
        }
    
        $changelog_path = plugin_dir_path(__FILE__) . 'CHANGELOG.md';
        if (file_exists($changelog_path)) {
            if (!class_exists('Parsedown')) {
                require_once plugin_dir_path(__FILE__) . 'libs/Parsedown.php';
            }
    
            $parsedown = new Parsedown();
            $markdown_content = file_get_contents($changelog_path);
            $html_content = $parsedown->text($markdown_content);
    
            wp_send_json_success($html_content);
        } else {
            wp_send_json_error('CHANGELOG.md file not found.');
        }
    }
    add_action('wp_ajax_smarty_ca_load_changelog', 'smarty_ca_load_changelog');
}