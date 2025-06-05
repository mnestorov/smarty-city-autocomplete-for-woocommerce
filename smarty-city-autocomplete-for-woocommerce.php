<?php
/**
 * Plugin Name:             SM - City Autocomplete for WooCommerce
 * Plugin URI:              https://github.com/mnestorov/smarty-city-autocomplete-for-woocommerce
 * Description:             Replaces the WooCommerce city field with autocomplete and auto-fills postcode from a GeoNames TXT file.
 * Version:                 1.0.2
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

/**
 * On plugin activation: pre-warm the city cache for every country file in /data.
 *
 * Rationale
 * ------------------------------------------------------------------
 * - Parsing the large GeoNames TXT files on the first AJAX request
 *   adds a noticeable delay for the first customer.  
 * - By filling the transients here we do the heavy work exactly once,
 *   during activation (or update), instead of in the middle of checkout.
 *
 * How it works
 * ------------------------------------------------------------------
 * 1. `glob()` finds every  <plugin>/data/XX.txt  file that ships
 *    with the plugin (where XX is the ISO-3166-1 alpha-2 code).  
 * 2. For each file we call  smarty_ca_build_city_transient( 'XX' )
 *    which parses the file and stores the result in the transient
 *    `smarty_ca_cities_XX` (TTL is set inside that helper).
 * 3. Subsequent AJAX calls hit the ready-made transient, so the
 *    first keystroke is instant.
 *
 * @internal  Runs automatically once on activation / update.  No hooks
 *            or filters inside the closure to keep scope clean.
 * 
 * @since 1.0.0
 */
register_activation_hook(__FILE__, function() {
    // build transients for all TXT files you actually ship
    $files = glob(plugin_dir_path(__FILE__) . 'data/*.txt');

    foreach ($files as $file) {
        $country = strtoupper(basename($file, '.txt'));
        smarty_ca_build_city_transient($country);
    }
});

/**
 * Symfony polyfill for Normalizer that mimics intl behavior:
 * - Normalizes Unicode characters like ă, ș, î into plain ASCII (a, s, i).
 * - Handles all European accents.
 * - Makes searches accent-insensitive for users.
 * 
 * @since 1.0.0
 */
if (!class_exists('Normalizer')) {
    require_once plugin_dir_path(__FILE__) . 'libs/Normalizer.php';
}

// ==================== FRONTEND FIELD OVERRIDE ==================== //

if (!function_exists('smarty_ca_override_checkout_fields')) {
    /**
     * Override WooCommerce checkout fields:
     * - Hide the postcode field
     * - Replace the city field with an autocomplete text input
     *
     * @since 1.0.0
     * 
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
            'type'              => 'select',
            'label'             => get_option('smarty_ca_hide_city_label') === 'yes' ? '' : __('City', 'woocommerce'),
            'required'          => true,
            'class'             => array('form-row-wide', 'smarty-select2-city'),
            'options'           => array( '' => '' ),
            'custom_attributes' => array(
                'data-placeholder'  => __('Start typing to search for a city', 'smarty-city-autocomplete'),
            ),
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
     * 
     * @return void
     */
    function smarty_ca_hidden_postcode_input() {
        echo '<style>.smarty-hidden {display:none !important;}</style>';
    }
    add_action('woocommerce_after_checkout_billing_form', 'smarty_ca_hidden_postcode_input');
}

// ==================== JS / ASSETS ==================== //

if (!function_exists('smarty_ca_enqueue_admin_scripts')) {
    /**
     * Enqueue admin-specific styles and scripts for the City Autocomplete plugin.
     *
     * This function enqueues the admin CSS and JS files only for admin pages.
     * It also localizes the JS script with nonce and AJAX URL.
     *
     * @since 1.0.0
     *
     * @param string $hook The current admin page hook suffix.
     * @return void
     */
    function smarty_ca_enqueue_admin_scripts($hook) {
     
        wp_enqueue_style('smarty-ca-admin-css', plugin_dir_url(__FILE__) . 'css/smarty-ca-admin.css', array(), '1.0.0');
        wp_enqueue_script('smarty-ca-admin-js', plugin_dir_url(__FILE__) . 'js/smarty-ca-admin.js', array('jquery'), '1.0.0', true);

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
    /**
     * Enqueue public-facing scripts and styles for the City Autocomplete plugin on the WooCommerce checkout page.
     *
     * This function only runs on the checkout page and only for enabled countries.
     * It enqueues Select2 library, the plugin's main JS, and localizes strings for use in Select2 UI.
     *
     * @since 1.0.0
     *
     * @return void
     */
    function smarty_ca_enqueue_public_scripts() {
        if (!is_checkout()) return;

        $country = WC()->customer->get_billing_country() ?: 'BG';
        $enabled = smarty_ca_get_enabled_countries();
        if (!in_array($country, $enabled)) return;

        wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');
        
        if (get_option('smarty_ca_enable_custom_css', 'yes') === 'yes') {
            wp_enqueue_style('smarty-ca-public-css', plugin_dir_url(__FILE__) . 'css/smarty-ca-public.css', array(), '1.0.0');
        }
        
        wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array('jquery'), '4.1.0', true);
        wp_enqueue_script('smarty-ca-public-js', plugin_dir_url(__FILE__) . 'js/smarty-ca-public.js', array('jquery', 'select2'), '1.1', true);

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

// ==================== TRANSIENT ==================== //

if (!function_exists('smarty_ca_build_city_transient')) {
    /**
     * Build & store the transient for a given country code.
     * 
     * @since 1.0.1
     * 
     * @param string $country ISO 3166-1 alpha-2 – e.g. BG, RO.
     * @return void
     */
    function smarty_ca_build_city_transient($country) {

        $file_path = plugin_dir_path(__FILE__) . "data/$country.txt";
        if (!file_exists($file_path)) {
            return;
        }

        $cities = [];
        if ($h = fopen($file_path, 'r')) {
            while (($line = fgets($h)) !== false) {
                $parts = explode("\t", $line);
                if (count($parts) < 3) {
                    continue;
                }
                $cities[] = [
                    'city'        => trim($parts[2]),
                    'postal_code' => $parts[1],
                ];
            }
            fclose($h);
        }

        $ttl = get_option('smarty_ca_cache_duration', WEEK_IN_SECONDS);
        set_transient("smarty_ca_cities_$country", $cities, $ttl);
    }
}

// ==================== AJAX ==================== //

if (!function_exists('smarty_ca_get_city_suggestions')) {
    /**
     * Handle AJAX request for city suggestions based on the GeoNames TXT file.
     * Returns a list of matching cities and their postal codes.
     *
     * @since 1.0.0
     * 
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
            smarty_ca_build_city_transient($country);     // fills the cache
            $cities = get_transient($cache_key);          // now it exists
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

        //wp_send_json(array_slice(array_values($filtered), 0, 10));
        wp_send_json(array_values($filtered)); // send every match, no cap
    }
    add_action('wp_ajax_smarty_get_city_suggestions', 'smarty_ca_get_city_suggestions');
    add_action('wp_ajax_nopriv_smarty_get_city_suggestions', 'smarty_ca_get_city_suggestions');
}

// ==================== SANITIZE CITY ==================== //

/**
 * Clean city name before saving to the order.
 *
 * @since 1.0.0
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

/**
 * Save *clean* billing / shipping city names into the Order object.
 *
 * Hook: woocommerce_checkout_create_order  
 * Runs *before* WC writes the order to the DB (priority 20, two args).
 *
 * @since 1.0.0
 * 
 * @param WC_Order        $order Order being created.
 * @param array<string,mixed> $data  Raw posted checkout data.
 */
add_action('woocommerce_checkout_create_order', function($order, $data) {
    if (!empty($data['billing']['billing_city'])) {
        $order->set_billing_city(smarty_ca_clean_city_on_checkout($data['billing']['billing_city']));
    }
    if (!empty($data['shipping']['shipping_city'])) {
        $order->set_shipping_city(smarty_ca_clean_city_on_checkout($data['shipping']['shipping_city']));
    }
}, 20, 2);

/**
 * Filter the formatted **billing** address before display / e-mails.
 *
 * Ensures the “ / Latin” part is stripped even if legacy data is present.
 *
 * @since 1.0.0
 * 
 * @param array<string,string> $address Formatted address parts.
 * @param WC_Order             $order   Order object.
 * @return array<string,string> Modified address parts.
 */
add_filter('woocommerce_order_formatted_billing_address', function($address, $order) {
    if (!empty($address['city'])) {
        $address['city'] = smarty_ca_clean_city_on_checkout($address['city']);
    }
    return $address;
}, 10, 2);

/**
 * Same cleanup for the **shipping** address.
 *
 * @since 1.0.0
 * 
 * @see woocommerce_order_formatted_billing_address filter above.
 */
add_filter('woocommerce_order_formatted_shipping_address', function($address, $order) {
    if (!empty($address['city'])) {
        $address['city'] = smarty_ca_clean_city_on_checkout($address['city']);
    }
    return $address;
}, 10, 2);

/**
 * Force-clean the city fields before they are saved into order meta.
 *
 * @since 1.0.0
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

/**
 * Clean the raw checkout payload **before** WooCommerce persists it.
 *
 * Strips the “ / LatinName” part from both billing_city and
 * shipping_city so no mixed-language values reach the DB.
 *
 * Hook: `woocommerce_checkout_posted_data`
 *
 * @since 1.0.0
 * 
 * @param array<string,mixed> $data Posted checkout data.
 * @return array<string,mixed>       Sanitised data.
 *
 */
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
 * @since 1.0.0
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
     * 
     * @since 1.0.0
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
     * Register all plugin options with the WordPress Settings API.
     *
     * Creates four options in the `smarty_ca_options` group:
     * – enabled countries<br>
     * – city field priority<br>
     * – hide city label<br>
     * – enable custom CSS
     *
     * @since 1.0.0
     */
    function smarty_ca_register_settings() {
        register_setting('smarty_ca_options', 'smarty_ca_enabled_countries', [
            'type'              => 'array',
            'sanitize_callback' => function($val) {
                return is_array($val) ? array_map('sanitize_text_field', $val) : [];
            },
        ]);
        register_setting('smarty_ca_options', 'smarty_ca_city_priority', [
            'type'              => 'integer',
            'sanitize_callback' => function($val) {
                return max(0, min(999, (int)$val));
            },
        ]);
        register_setting('smarty_ca_options', 'smarty_ca_hide_city_label', [
            'type'              => 'string',
            'sanitize_callback' => function($val) {
                return $val === 'yes' ? 'yes' : 'no';
            },
        ]);
        register_setting('smarty_ca_options', 'smarty_ca_enable_custom_css', [
            'type'              => 'string',
            'sanitize_callback' => function($val) {
                return $val === 'yes' ? 'yes' : 'no';
            },
        ]);
        register_setting('smarty_ca_options', 'smarty_ca_cache_duration', [
            'type'              => 'integer',
            'sanitize_callback' => function($val) {
                return (int) $val;
            },
        ]);

        add_settings_section('smarty_ca_main_section', '', null, 'smarty-ca-settings');

        add_settings_field(
            'smarty_ca_enabled_countries',
            __('Enabled Countries', 'smarty-city-autocomplete'),
            'smarty_ca_country_checkboxes_cb',
            'smarty-ca-settings',
            'smarty_ca_main_section'
        );

        add_settings_field(
            'smarty_ca_city_priority',
            __('City Field Priority', 'smarty-city-autocomplete'),
            'smarty_ca_city_priority_input_cb',
            'smarty-ca-settings',
            'smarty_ca_main_section'
        );

        add_settings_field(
            'smarty_ca_hide_city_label',
            __('Hide City Field Label', 'smarty-city-autocomplete'),
            'smarty_ca_hide_city_label_checkbox_cb',
            'smarty-ca-settings',
            'smarty_ca_main_section'
        );

        add_settings_field(
            'smarty_ca_enable_custom_css',
            __('Enable Custom CSS for City Dropdown', 'smarty-city-autocomplete'),
            'smarty_ca_custom_css_checkbox_cb',
            'smarty-ca-settings',
            'smarty_ca_main_section'
        );

        add_settings_field(
            'smarty_ca_cache_duration',
            __('City Cache Duration', 'smarty-city-autocomplete'),
            'smarty_ca_cache_duration_select_cb',
            'smarty-ca-settings',
            'smarty_ca_main_section'
        );
    }
    add_action('admin_init', 'smarty_ca_register_settings');
}

if (!function_exists('smarty_ca_get_enabled_countries')) {
    /**
     * Retrieve the list of enabled countries for the City Autocomplete feature.
     *
     * This function fetches the list of country codes selected in the plugin settings.
     * It ensures the returned value is always an array, even if the setting is empty.
     *
     * @since 1.0.0
     *
     * @return array Array of enabled country codes (e.g., ['BG', 'DE']).
     */
    function smarty_ca_get_enabled_countries() {
        $enabled = get_option('smarty_ca_enabled_countries');
        return is_array($enabled) ? $enabled : [];
    }
}

if (!function_exists('smarty_ca_city_priority_input_cb')) {
    /**
     * Render the City Field Priority input in the settings page.
     *
     * This function outputs an HTML <input> element for administrators
     * to set the field priority (ordering) of the city field on checkout.
     * The value is sanitized and limited between 0 and 999.
     *
     * @since 1.0.0
     *
     * @return void
     */
    function smarty_ca_city_priority_input_cb() {
        $value = get_option('smarty_ca_city_priority', 45);
        echo "<input type='number' name='smarty_ca_city_priority' value='" . esc_attr($value) . "' min='0' max='999' />";
        echo "<p class='description'>" . __('Lower numbers show earlier. Default: 45', 'smarty-city-autocomplete') . "</p>";
    }
}

if (!function_exists('smarty_ca_hide_city_label_checkbox_cb')) {
    /**
     * Checkbox: hide the “City” label on the checkout form.
     *
     * @since 1.0.0
     */
    function smarty_ca_hide_city_label_checkbox_cb() {
        $value = get_option('smarty_ca_hide_city_label', 'no');
        $checked = $value === 'yes' ? 'checked' : '';
        echo "<label><input type='checkbox' name='smarty_ca_hide_city_label' value='yes' $checked> " . __('Yes, hide the city field label', 'smarty-city-autocomplete') . "</label>";
    }
}

if (!function_exists('smarty_ca_custom_css_checkbox_cb')) {
    /**
     * Checkbox: load / skip the plugin’s public-facing CSS.
     *
     * Lets merchants keep their own theme styling.
     *
     * @since 1.0.0
     */
    function smarty_ca_custom_css_checkbox_cb() {
        $value = get_option('smarty_ca_enable_custom_css', 'yes');
        $checked = $value === 'yes' ? 'checked' : '';
        echo "<label><input type='checkbox' name='smarty_ca_enable_custom_css' value='yes' $checked> ";
        echo __('Yes, load the plugin’s public CSS styling', 'smarty-city-autocomplete') . "</label>";
    }
}

if (!function_exists('smarty_ca_country_checkboxes_cb')) {
    /**
     * Output one checkbox per TXT file found in /data.
     *
     * Allows the admin to enable/disable the autocomplete per country.
     *
     * @since 1.0.0
     */
    function smarty_ca_country_checkboxes_cb() {
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
            echo '<input type="hidden" name="smarty_ca_enabled_countries[]" value="" />';
        }
    }
}

if (!function_exists('smarty_ca_cache_duration_select_cb')) {
    /**
     * Render the City Cache Duration <select> dropdown for the plugin settings.
     *
     * This function outputs a `<select>` field allowing the admin to choose how long
     * the city list (loaded from the GeoNames .txt file) should be cached using transients.
     *
     * Available options include:
     * - 1 Hour
     * - 1 Day
     * - 1 Week (default)
     * - 1 Month (approx.)
     *
     * The selected value is stored in the `smarty_ca_cache_duration` option and used
     * when building the transient via `set_transient()`.
     *
     * @since 1.0.2
     *
     * @return void
     */
    function smarty_ca_cache_duration_select_cb() {
        $options = [
            HOUR_IN_SECONDS     => __('1 Hour', 'smarty-city-autocomplete'),
            DAY_IN_SECONDS      => __('1 Day', 'smarty-city-autocomplete'),
            WEEK_IN_SECONDS     => __('1 Week', 'smarty-city-autocomplete'),
            MONTH_IN_SECONDS    => __('1 Month (approx.)', 'smarty-city-autocomplete'),
        ];

        $selected = get_option('smarty_ca_cache_duration', WEEK_IN_SECONDS);

        echo '<select name="smarty_ca_cache_duration">';
        foreach ($options as $value => $label) {
            $is_selected = selected($selected, $value, false);
            echo "<option value='$value' $is_selected>$label</option>";
        }
        echo '</select>';
        echo '<p class="description">' . __('Controls how long city data is cached for each country.', 'smarty-city-autocomplete') . '</p>';
    }
}

if (!function_exists('smarty_ca_render_settings_page')) {
    /**
     * Render the settings screen wrapper and tab layout.
     *
     * @since 1.0.0
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
     * AJAX: return the parsed **README.md** as HTML for the Documentation tab.
     *
     * Permission: `manage_options` • Nonce: `smarty_ca_nonce`
     *
     * @since 1.0.0
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
     * AJAX: return the parsed **CHANGELOG.md** as HTML for the Changelog tab.
     *
     * Permission and nonce identical to `smarty_ca_load_readme()`.
     *
     * @since 1.0.0
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

// Add a links on the Plugins page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $links[] = '<a href="' . admin_url('admin.php?page=smarty-ca-settings') . '">' . __('Settings', 'smarty-city-autocomplete') . '</a>';
    $links[] = '<a href="https://github.com/mnestorov/smarty-city-autocomplete-for-woocommerce" target="_blank">' . __('GitHub', 'smarty-city-autocomplete') . '</a>';
    return $links;
});