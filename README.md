# A simple Nextcloud instances versions monitoring page

## Description

Simple page for displaying and tracking versions of several Nextcloud instances.

For each instance it reads the public `status.php` file, then shows:

- the **state** (online, maintenance mode, database upgrade needed, not installed, unreachable and why),
- the **version** (and the patch release you should update to),
- the **edition** (standard, extended support, or Enterprise),
- whether the major version is **still maintained**, its **support end date** and the number of days left or elapsed.

Support end dates are **fetched live** from the official Nextcloud
[Maintenance and Release Schedule](https://github.com/nextcloud/server/wiki/Maintenance-and-Release-Schedule)
(with [endoflife.date](https://endoflife.date/nextcloud) as a second source and a built-in table as a last resort),
so you no longer have to edit a table when a new major version is released.

A collapsible **legend** below the table explains the dashboard, every column, every possible value (states, editions, "Maintained"), the colour/symbol codes and a few terms.

Languages: PHP, HTML, CSS, vanilla JavaScript (no jQuery, no database).

## Languages

The interface is available in **English, French, Spanish, Italian, German and Portuguese**. A switcher in the page header (`EN · FR · ES · IT · DE · PT`) reloads the page in the chosen language (`?lang=xx`), and the choice is remembered for one year in the visitor's browser (a small `HttpOnly` cookie), so it survives navigation, reloads and future visits. The default language is set in `config.php`.

To add another language, copy one block in `lang.php` and translate it: the legend and the switcher pick it up automatically.

## Demo

<https://www.herbinet.fr/NIVMP-demo/>

## Requirements

- A web server with PHP. The code is written to stay compatible with PHP 7.4 and newer, but has only been tested with PHP 8.3. The `curl` extension is recommended: with it, all instances are queried in parallel.
- Outbound HTTPS access from the server to your instances, to `raw.githubusercontent.com` and (optionally) `endoflife.date`.
- A writable temporary directory (used for a small cache; the page still works without it).

## Installation

1. Upload all the files behind a regular web environment and an `https://...` URL. There is no database.
2. Open `config.php` and list your instances (domain names, without `https://`). Everything else is optional.

```php
'instances' => [
    'cloud.example.org',
    'example.org/nextcloud',                                   // installed in a sub-folder
    'intranet.example.org' => ['name' => 'Intranet', 'enterprise' => true],
],
```

Other settings in `config.php`: default interface language (`en`, `fr`, `es`, `it`, `de` or `pt`; visitors can
switch in the header and the choice is kept for one year in their browser), timeouts, TLS verification, cache
durations, the orange/red warning thresholds (30 / 15 days by default) and the support-date sources. Wordings
live in `lang.php`; copy a block to add a language.

## How support dates are found

1. The raw markdown of the Nextcloud wiki page is downloaded and parsed (cached for 6 hours).
2. If that fails, the endoflife.date API is tried.
3. If that fails too, the last cached copy is used and flagged as such in the page footer.
4. With no cache at all, the `fallback` table of `config.php` is used.

The wiki gives an exact end date only for the versions that are still supported. For older ones it gives a month,
and the last day of that month is used (shown with a leading `≈`).

## About the "Enterprise" display

`status.php` always returns an empty `edition` field, so an Enterprise edition **cannot be detected directly**. This page therefore shows:

- **Extended support** when `status.php` reports `extendedSupport: true` (this comes from the instance's support subscription),
- **Enterprise** when you flag the instance yourself with `'enterprise' => true` in `config.php`,
- **Standard** otherwise. An Enterprise instance that does not report extended support will show as Standard unless you flag it.

An instance past the community support end date but reporting extended support is shown as "Extended" rather than "No".

## Restrictions

This program does not work:

- with "hidden" Nextcloud instances (only reachable through a VPN, for example) unless the web server running this page can reach them,
- with Nextcloud instances whose access to `status.php` has been blocked.

ownCloud instances are recognised from their product name and shown without support dates.

## Security notes

- The instance list is trusted configuration: never build it from user input.
- `status.php` is public and reveals the exact version of an instance. This page is sent with `noindex` headers, but you may still want to protect it (HTTP authentication, IP restriction) if it lists instances you do not want to advertise.
- Data coming from the monitored instances is escaped before display, responses are size-limited, and only `http(s)` is followed.
- The page sends a strict Content-Security-Policy: inline scripts and styles are not allowed, so keep custom code in `scripts.js` / `styles.css`.

## Development roadmap

- JSON output for use with Uptime Kuma, Nagios or Zabbix
- Notifications before a support end date

See [CHANGELOG.md](CHANGELOG.md) for what is already done.
