# Changelog

## 0.1.3 - 2026-11-17

### Added
- **Live support end dates** from the Nextcloud "Maintenance and Release Schedule" wiki, with endoflife.date as a second source, a stale-cache safety net and a built-in fallback table. No more manual edits when a new major version is released.
- **Latest patch release** per major version, with a hint when an instance is behind (for example "Update to 35.0.3 available").
- **Instance state** from `status.php`: online, maintenance mode, database upgrade needed, not installed.
- **Edition column**: "Extended support" (from `extendedSupport`) and a manual "Enterprise" flag in `config.php` (the `edition` field of `status.php` is always empty).
- **Reasons for unreachable instances**: timeout, DNS, connection, TLS, HTTP status, invalid response, response too large.
- Summary tiles (total, healthy, attention, critical, unreachable).
- English and French interface (`lang.php`, `?lang=fr`).
- Support for instances in a sub-folder and for friendly names (`config.php`).
- `ownCloud` instances are recognised and shown without support dates.

### Changed
- **New UI and branding**: header, summary tiles, status badges (colour is always paired with a symbol), light and dark themes.
- **Responsive design**: on phones each instance becomes a card and a sort menu replaces the column headers.
- Instances are queried **in parallel** with timeouts (was: one after the other, with PHP's default 60 s timeout each).
- `status.php` is read with `json_decode` (was: fragile string replacement).
- Configuration moved from `index.php` to a dedicated `config.php`.
- Sorting is now numeric where it should be (days, version); it used to sort text such as "12 days left" alphabetically.

### Fixed
- The red threshold (15 days) was never reached because the orange test (30 days) ran first.
- Major versions below 10, or newer than the hard-coded table, caused PHP warnings or wrong results.
- Unreachable instances no longer produce PHP warnings.
- Values received from monitored instances are now HTML-escaped.

### Removed
- jQuery 1.12.4 and `jquery.filtertable` (unused).

### Upgrading from 0.1.x
Replace all files, then copy your instances list into the new `config.php`. The manual major-version/EOL array is no longer needed (keep it as `eol.fallback` if you want an offline safety net).
