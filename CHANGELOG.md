# Changelog

### 1.0.0 (2025.05.09)
- Initial release

### 1.0.1 (2025.05.28)
- **New – Cold-start killer**  
  - The plugin now pre-warms its city cache on activation (`register_activation_hook`) so the first visitor no longer waits while the TXT files are parsed.
- **New – Helper** `smarty_ca_build_city_transient()` exposed for reuse.  
  - Called by both the activation hook and the AJAX fallback path.
- **Perf – Longer TTL**  
  - Transient lifetime extended to `WEEK_IN_SECONDS` (was one day).

### 1.0.2 (2025.06.03)
- **New – Cache Duration Selector**  
  - Admins can now choose how long city data should be cached via a new dropdown setting:
    - 1 Hour, 1 Day, 1 Week (default), or 1 Month.
  - Controlled by the new `smarty_ca_cache_duration` option and used in transient expiration logic.
- **Refactor – Consistent Naming**  
  - All field renderer functions used in `add_settings_field()` now use `_cb` suffix for clarity and convention.