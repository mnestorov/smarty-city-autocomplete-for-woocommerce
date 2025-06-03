# SM – City Autocomplete for WooCommerce

[![Licence](https://img.shields.io/badge/LICENSE-GPL2.0+-blue)](./LICENSE)

- **Developed by:** Martin Nestorov  
  Explore more at [nestorov.dev](https://github.com/mnestorov)
- **Plugin URI:** https://github.com/mnestorov/smarty-city-autocomplete-for-woocommerce

## Overview

A smart autocomplete field for WooCommerce checkout that helps users find their city and automatically fills in the postcode. Uses Select2 and a local GeoNames data file for speed and privacy.

## Description

**SM – City Autocomplete for WooCommerce** replaces the default city input with a dynamic, searchable dropdown powered by Select2. This improves the user experience on checkout by allowing customers to quickly find their city and auto-filling the corresponding postcode. It leverages local data files instead of external APIs, ensuring better performance and data privacy. 

Developed for WooCommerce stores with multi-country or multilingual setups, it supports UTF-8 characters, removes Latin translations from saved data, and includes an admin UI for enabling countries based on available datasets.

## Features

- Replaces the default city input with a searchable **Select2 dropdown**
- Autofills the **postcode** based on the selected city
- Supports **case-insensitive** search with UTF-8 character matching
- Automatically **removes the Latin translation** (e.g. "София / Sofia") before saving
- Stores only **clean native city names** in the WooCommerce order meta
- Fully supports **multisite** and **multi-country** installations
- Admin UI to enable/disable by country (based on `/data/*.txt` files)
- Admin setting to control **city cache duration** (1 hour to 1 month)

## How It Works

1. A `data/XX.txt` file contains tab-separated data:  
   `CountryCode [TAB] PostalCode [TAB] CityName`
2. When the customer starts typing their city, an AJAX call suggests cities matching the input.
3. When a city is selected:
   - The `billing_postcode` field is auto-filled.
   - The city label appears as `CityName [PostalCode]`.
   - Only the **native city name** (before ` / `) is saved in the order.
4. Transients cache parsed city data per country – controlled via the new **cache duration** setting.   

## Installation

1. **Download the Plugin**: Clone or download the plugin ZIP from the GitHub repository.
2. **Upload to WordPress**:
   - Navigate to your WordPress admin panel.
   - Go to Plugins > Add New > Upload Plugin.
   - Choose the ZIP file and click "Install Now."
3. **Activate the Plugin**:
   - After installation, click "Activate Plugin."
4. **Configure**:
   - Go to **WooCommerce → City Autocomplete**.
   - Enable countries for which you’ve uploaded `data/XX.txt` files.
   - Set your preferred **cache duration** from the dropdown.

## Settings Options

The plugin adds a settings screen under **WooCommerce > City Autocomplete**, where you can configure:

- ✅ Enabled countries (based on available `*.txt` files in `/data/`)
- 🎯 Priority of the city field (for checkout ordering)
- 🏷️ Option to hide the city label (for compact layouts)
- 🎨 Toggle for loading plugin CSS (for custom styling)
- 🕒 **City Cache Duration** – how long parsed city data is cached:
  - 1 Hour
  - 1 Day
  - 1 Week _(default)_
  - 1 Month   

## Data File Format

Each file should be named using the country code (e.g., `BG.txt`, `RO.txt`) and contain lines formatted like:

- BG 1000 София / Sofia
- BG 3400 Монтана / Montana
- BG 8000 Бургас / Burgas

> Only cities with 3+ fields (country, postcode, city) will be loaded.

## Development Notes

- AJAX requests are cached via WordPress transients.
- The city field is inserted as a `<select>` to enable dynamic search via Select2.
- Postcode field is hidden but still stored and sent with the order.
- City values are filtered before saving and displaying to avoid ` / LatinName`.

## Customization

You can override styles or extend the plugin behavior using the following hook:

```php
add_filter('woocommerce_process_checkout_field_billing_city', 'my_custom_city_cleaner');
```

## Changelog

For a detailed list of changes and updates made to this project, please refer to our [Changelog](./CHANGELOG.md).

## Support The Project

If you find this script helpful and would like to support its development and maintenance, please consider the following options:

- **_Star the repository_**: If you're using this script from a GitHub repository, please give the project a star on GitHub. This helps others discover the project and shows your appreciation for the work done.

- **_Share your feedback_**: Your feedback, suggestions, and feature requests are invaluable to the project's growth. Please open issues on the GitHub repository or contact the author directly to provide your input.

- **_Contribute_**: You can contribute to the project by submitting pull requests with bug fixes, improvements, or new features. Make sure to follow the project's coding style and guidelines when making changes.

- **_Spread the word_**: Share the project with your friends, colleagues, and social media networks to help others benefit from the script as well.

- **_Donate_**: Show your appreciation with a small donation. Your support will help me maintain and enhance the script. Every little bit helps, and your donation will make a big difference in my ability to keep this project alive and thriving.

Your support is greatly appreciated and will help ensure all of the projects continued development and improvement. Thank you for being a part of the community!
You can send me money on Revolut by following this link: https://revolut.me/mnestorovv

---

## License

This project is released under the [GPL-2.0+ License](http://www.gnu.org/licenses/gpl-2.0.txt).
