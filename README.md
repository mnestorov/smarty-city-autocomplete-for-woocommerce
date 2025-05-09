# SM – City Autocomplete for WooCommerce

**Contributors:** [mnestorov](https://github.com/mnestorov)  
**Tags:** woocommerce, city autocomplete, geonames, checkout, select2  
**Requires at least:** 5.8  
**Tested up to:** 6.5  
**WC requires at least:** 3.5  
**WC tested up to:** 9.6  
**Stable tag:** 1.0.0  
**License:** GPLv2 or later  
**License URI:** http://www.gnu.org/licenses/gpl-2.0.html  

A smart autocomplete field for WooCommerce checkout that helps users find their city and automatically fills in the postcode. Uses Select2 and a local GeoNames data file for speed and privacy.

---

## 🧩 Features

- Replaces the default city input with a searchable **Select2 dropdown**
- Autofills the **postcode** based on the selected city
- Supports **case-insensitive** search with UTF-8 character matching
- Automatically **removes the Latin translation** (e.g. "София / Sofia") before saving
- Stores only **clean native city names** in the WooCommerce order meta
- Fully supports **multisite** and **multi-country** installations
- Admin UI to enable/disable by country (based on `/data/*.txt` files)

---

## 🏗️ How It Works

1. A `data/XX.txt` file contains tab-separated data:  
   `CountryCode [TAB] PostalCode [TAB] CityName`

2. When the customer starts typing their city, an AJAX call suggests cities matching the input.

3. When a city is selected:
   - The `billing_postcode` field is auto-filled.
   - The city label appears as `CityName [PostalCode]`.
   - Only the **native city name** (before ` / `) is saved in the order.

---

## 📦 Installation

1. Upload the plugin folder to `/wp-content/plugins/` or install it from the Plugins screen.
2. Activate the plugin.
3. Go to **WooCommerce → City Autocomplete** and enable countries for which you’ve uploaded a `data/XX.txt` file.
4. Upload your `.txt` data file(s) in `wp-content/plugins/smarty-city-autocomplete-for-woocommerce/data/`.

---

## ⚙️ Data File Format

Each file should be named using the country code (e.g. `BG.txt`, `RO.txt`) and contain lines formatted like:

- BG 1000 София / Sofia
- BG 3400 Монтана / Montana
- BG 8000 Бургас / Burgas

> Only cities with 3+ fields (country, postcode, city) will be loaded.

---

## 🧪 Development Notes

- AJAX requests are cached via WordPress transients.
- The city field is inserted as a `<select>` to enable dynamic search via Select2.
- Postcode field is hidden but still stored and sent with the order.
- City values are filtered before saving and displaying to avoid ` / LatinName`.

---

## 🛠️ Customization

You can override styles or extend the plugin behavior using the following hooks:

```php
add_filter('woocommerce_process_checkout_field_billing_city', 'my_custom_city_cleaner');
```

## 📜 License

This plugin is licensed under the GPLv2+ license. See LICENSE for details.