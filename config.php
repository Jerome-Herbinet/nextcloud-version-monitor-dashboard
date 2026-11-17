<?php
/**
 * NIVMP configuration. This is the only file you need to edit.
 */
return [

    // Default interface language: 'en', 'fr', 'es', 'it', 'de' or 'pt' (see lang.php).
    // Visitors can switch at any time with the links in the header (?lang=xx); their
    // choice is then remembered for one year in their browser (cookie) and takes
    // precedence over this setting.
    'lang' => 'en',

    // Nextcloud instances to monitor. Use one of these forms:
    //   'cloud.example.org'                      plain domain (HTTPS is assumed)
    //   'example.org/nextcloud'                  instance installed in a sub-folder
    //   'https://intranet.example.org'           explicit scheme (use http:// only on trusted networks)
    //   'cloud.example.org' => ['name' => 'Main cloud', 'enterprise' => true]
    // Options: 'name' = label shown in the table, 'enterprise' = true to flag an Enterprise
    // instance (status.php cannot reveal this by itself, see README).
    // The list is trusted input: never build it from user-supplied data.
    'instances' => [
        'cloud.nextcloud.com',
        'valise.chapril.org',
        'framadrive.org',
    ],

    // Support dates (end of life). They are fetched live and cached; sources are tried in order.
    'eol' => [
        // 'wiki'      = Nextcloud "Maintenance and Release Schedule" (raw wiki markdown)
        // 'endoflife' = endoflife.date API
        'sources'        => ['wiki', 'endoflife'],
        'wiki_url'       => 'https://raw.githubusercontent.com/wiki/nextcloud/server/Maintenance-and-Release-Schedule.md',
        'endoflife_url'  => 'https://endoflife.date/api/nextcloud.json',
        'cache_ttl'      => 21600,   // seconds a successful fetch is reused (6 h)
        'retry_after'    => 300,     // seconds to wait before retrying after a failure
        'warn_days'      => 30,      // orange when this many days (or fewer) remain
        'critical_days'  => 15,      // red when this many days (or fewer) remain

        // Last-resort table used only when no live source is reachable and nothing is cached.
        // Format: major version => last supported day (Y-m-d). Generated from the wiki on 2026-11-17.
        'fallback' => [
            '20' => '2021-11-30',
            '21' => '2022-02-28',
            '22' => '2022-07-31',
            '23' => '2022-12-31',
            '24' => '2023-04-30',
            '25' => '2023-10-31',
            '26' => '2024-03-31',
            '27' => '2024-06-30',
            '28' => '2024-12-31',
            '29' => '2025-04-30',
            '30' => '2025-10-31',
            '31' => '2026-02-28',
            '32' => '2026-09-30',
            '33' => '2027-02-17',
            '34' => '2027-06-08',
            '35' => '2027-09-15',
        ],
    ],

    // Requests to the monitored instances (and to the support-date sources).
    'http' => [
        'timeout'         => 6,       // total seconds per request
        'connect_timeout' => 4,
        'verify_tls'      => true,    // set to false only for internal instances with self-signed certificates
        'max_bytes'       => 262144,  // responses larger than this are rejected
        'user_agent'      => 'NIVMP/0.2.0 (+https://github.com/Jerome-Herbinet/Nextcloud-Instances-Versions-Monitoring-Page)',
    ],

    // Cache for instance status (avoids hammering your instances on every page view).
    // 'dir' => null uses a private folder in the system temp directory.
    'cache' => [
        'dir'        => null,
        'status_ttl' => 60,           // seconds; 0 disables
    ],

    // Optional: force a time zone for the day counts (e.g. 'Europe/Paris'). null = server default.
    'timezone' => null,
];
