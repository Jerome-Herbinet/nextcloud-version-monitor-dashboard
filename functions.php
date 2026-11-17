<?php
/**
 * NIVMP - Nextcloud Instances Versions Monitoring Page
 * Helper functions: HTTP, cache, EOL schedule loading, status.php analysis.
 * Written to stay compatible with PHP >= 7.4 (curl extension recommended).
 */

const NIVMP_VERSION = '0.2.0';

/* ------------------------------------------------------------------ */
/* Small helpers                                                       */
/* ------------------------------------------------------------------ */

/** Escape for HTML output. */
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Get / set the active language strings. */
function nivmp_lang(?array $set = null): array
{
    static $strings = [];
    if ($set !== null) {
        $strings = $set;
    }
    return $strings;
}

/** Translate a key (sprintf-style arguments supported). */
function nivmp_t(string $key, ...$args): string
{
    $strings = nivmp_lang();
    $text = $strings[$key] ?? $key;
    return $args ? vsprintf($text, $args) : $text;
}

/** True if $date is a valid Y-m-d string. */
function nivmp_valid_date($date): bool
{
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}

/** Sortable key for a dotted version string. */
function nivmp_version_key(string $version): string
{
    $parts = array_map('intval', explode('.', $version));
    $parts = array_pad(array_slice($parts, 0, 4), 4, 0);
    return vsprintf('%05d%05d%05d%05d', $parts);
}

/* ------------------------------------------------------------------ */
/* File cache (JSON, atomic writes)                                    */
/* ------------------------------------------------------------------ */

function nivmp_cache_dir(array $cfg): ?string
{
    $dir = $cfg['cache']['dir'] ?? null;
    if (!$dir) {
        $dir = rtrim(sys_get_temp_dir(), '/\\') . '/nivmp-' . substr(md5(__DIR__), 0, 8);
    }
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return null;
    }
    return is_writable($dir) ? $dir : null;
}

function nivmp_cache_file(array $cfg, string $key): ?string
{
    $dir = nivmp_cache_dir($cfg);
    return $dir === null ? null : $dir . '/' . preg_replace('/[^a-z0-9_-]/i', '_', $key) . '.json';
}

/**
 * Read a cache entry. Returns ['t' => timestamp, 'v' => value, 'expired' => bool] or null.
 */
function nivmp_cache_get(array $cfg, string $key, int $ttl): ?array
{
    $file = nivmp_cache_file($cfg, $key);
    if ($file === null || !is_file($file)) {
        return null;
    }
    $envelope = json_decode((string) @file_get_contents($file), true);
    if (!is_array($envelope) || !isset($envelope['t']) || !array_key_exists('v', $envelope)) {
        return null;
    }
    $envelope['expired'] = (time() - (int) $envelope['t']) > $ttl;
    return $envelope;
}

function nivmp_cache_put(array $cfg, string $key, $value): void
{
    $file = nivmp_cache_file($cfg, $key);
    if ($file === null) {
        return;
    }
    $tmp = @tempnam(dirname($file), 'tmp');
    if ($tmp === false) {
        return;
    }
    if (@file_put_contents($tmp, json_encode(['t' => time(), 'v' => $value])) === false || !@rename($tmp, $file)) {
        @unlink($tmp);
    }
}

/* ------------------------------------------------------------------ */
/* HTTP (parallel when curl is available)                              */
/* ------------------------------------------------------------------ */

/**
 * Fetch several URLs. $urls is [id => url].
 * Returns [id => ['code' => int, 'body' => string, 'error' => ?string, 'ms' => int]].
 * 'error' is one of: timeout, dns, connect, tls, too_large, other (or null).
 */
function nivmp_http_multi(array $urls, array $http): array
{
    $results = [];
    $maxBytes = (int) ($http['max_bytes'] ?? 262144);
    $verify = !empty($http['verify_tls']);

    if (!function_exists('curl_multi_init')) {
        // Sequential fallback without curl.
        foreach ($urls as $id => $url) {
            $start = microtime(true);
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => (float) ($http['timeout'] ?? 6),
                    'user_agent' => (string) ($http['user_agent'] ?? 'NIVMP'),
                    'ignore_errors' => true,
                    'max_redirects' => 3,
                ],
                'ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify],
            ]);
            $body = @file_get_contents($url, false, $ctx, 0, $maxBytes + 1);
            $code = 0;
            if (!empty($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
                $code = (int) $m[1];
            }
            $results[$id] = [
                'code' => $code,
                'body' => $body === false ? '' : (string) $body,
                'error' => $body === false ? 'other' : null,
                'ms' => (int) round((microtime(true) - $start) * 1000),
            ];
        }
        return $results;
    }

    $mh = curl_multi_init();
    $handles = [];
    $bodies = [];
    foreach ($urls as $id => $url) {
        $bodies[$id] = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => (int) ($http['connect_timeout'] ?? 4),
            CURLOPT_TIMEOUT => (int) ($http['timeout'] ?? 6),
            CURLOPT_USERAGENT => (string) ($http['user_agent'] ?? 'NIVMP'),
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: application/json, text/plain, */*'],
            // Cap the response size: remote content is untrusted.
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$bodies, $id, $maxBytes) {
                $bodies[$id] .= $chunk;
                return strlen($bodies[$id]) > $maxBytes ? 0 : strlen($chunk);
            },
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$id] = $ch;
    }

    do {
        $status = curl_multi_exec($mh, $active);
        if ($active && curl_multi_select($mh, 1.0) === -1) {
            usleep(10000);
        }
    } while ($active && $status === CURLM_OK);

    // With the multi interface, per-transfer errors are reported by curl_multi_info_read(),
    // not by curl_errno().
    $idOf = [];
    foreach ($handles as $id => $ch) {
        $idOf[is_object($ch) ? spl_object_id($ch) : (int) $ch] = $id;
    }
    $errnos = [];
    while (($info = curl_multi_info_read($mh)) !== false) {
        $key = is_object($info['handle']) ? spl_object_id($info['handle']) : (int) $info['handle'];
        if (isset($idOf[$key])) {
            $errnos[$idOf[$key]] = (int) $info['result'];
        }
    }

    foreach ($handles as $id => $ch) {
        $errno = $errnos[$id] ?? curl_errno($ch);
        $error = null;
        if ($errno !== 0) {
            if ($errno === 28) {
                $error = 'timeout';
            } elseif ($errno === 6) {
                $error = 'dns';
            } elseif ($errno === 7) {
                $error = 'connect';
            } elseif (in_array($errno, [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91], true)) {
                $error = 'tls';
            } elseif ($errno === 23 && strlen($bodies[$id]) > $maxBytes) {
                $error = 'too_large';
            } else {
                $error = 'other';
            }
        }
        $results[$id] = [
            'code' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'body' => $bodies[$id],
            'error' => $error,
            'ms' => (int) round(((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000),
        ];
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);

    return $results;
}

/* ------------------------------------------------------------------ */
/* EOL schedule: parsers                                               */
/* ------------------------------------------------------------------ */

/**
 * Parse the raw markdown of the Nextcloud wiki page
 * "Maintenance and Release Schedule".
 *
 * - Table rows give: major, name, release date, EOL month (YYYY-MM), latest patch.
 * - The "timeline" block gives the exact "Supported Until" date of supported majors;
 *   it is used only when it agrees with the table's month.
 *
 * Returns [major => ['eol','approx','release','name','latest','latest_date']] or [].
 */
function nivmp_parse_wiki(string $markdown): array
{
    $rows = [];
    $timeline = [];

    foreach (preg_split('/\R/', $markdown) as $line) {
        if (preg_match(
            '/^\|\s*\*\*(\d{1,3})\*\*\s*\|\s*([^|]*?)\s*\|\s*(\d{4}-\d{2}-\d{2})\s*\|\s*(\d{4}-\d{2}(?:-\d{2})?)\s*\|\s*\*{0,2}(\d+(?:\.\d+){1,3})\*{0,2}\s*\((\d{4}-\d{2}-\d{2})\)/',
            $line,
            $m
        )) {
            $rows[$m[1]] = [
                'name' => $m[2],
                'release' => $m[3],
                'eol_raw' => $m[4],
                'latest' => $m[5],
                'latest_date' => $m[6],
            ];
        } elseif (preg_match(
            '/^\s*(\d{1,3})\s*:\s*[^:]+?\s*:\s*\d{1,2}\s+[A-Za-z]{3,9}\s+\d{4}\s*:\s*(\d{1,2}\s+[A-Za-z]{3,9}\s+\d{4})\s*:/',
            $line,
            $m
        )) {
            $ts = strtotime($m[2]);
            if ($ts !== false) {
                $timeline[$m[1]] = date('Y-m-d', $ts);
            }
        }
    }

    $versions = [];
    foreach ($rows as $major => $row) {
        $raw = $row['eol_raw'];
        $approx = true;
        if (strlen($raw) === 7) {
            // Month only: last day of that month.
            $eol = date('Y-m-t', (int) strtotime($raw . '-01'));
            if (isset($timeline[$major]) && substr($timeline[$major], 0, 7) === $raw) {
                $eol = $timeline[$major];
                $approx = false;
            }
        } else {
            $eol = $raw;
            $approx = false;
        }
        if (!nivmp_valid_date($eol) || !nivmp_valid_date($row['release']) || !nivmp_valid_date($row['latest_date'])) {
            continue;
        }
        $versions[(string) $major] = [
            'eol' => $eol,
            'approx' => $approx,
            'release' => $row['release'],
            'name' => $row['name'],
            'latest' => $row['latest'],
            'latest_date' => $row['latest_date'],
        ];
    }

    // Sanity check: refuse a result that looks like a format change.
    return count($versions) >= 5 ? $versions : [];
}

/**
 * Parse the endoflife.date JSON for Nextcloud (v0 array or v1 "result.releases" shape).
 */
function nivmp_parse_endoflife($json): array
{
    if (!is_array($json)) {
        return [];
    }
    $list = (isset($json['result']['releases']) && is_array($json['result']['releases']))
        ? $json['result']['releases']
        : $json;

    $versions = [];
    foreach ($list as $r) {
        if (!is_array($r)) {
            continue;
        }
        $cycle = (string) ($r['cycle'] ?? $r['name'] ?? '');
        if (!preg_match('/^\d{1,3}$/', $cycle)) {
            continue;
        }
        $eol = $r['eol'] ?? ($r['eolFrom'] ?? null);
        if (!nivmp_valid_date($eol)) {
            continue;
        }
        $latest = $r['latest'] ?? null;
        $latestDate = $r['latestReleaseDate'] ?? null;
        if (is_array($latest)) {
            $latestDate = $latest['date'] ?? null;
            $latest = $latest['name'] ?? null;
        }
        $release = $r['releaseDate'] ?? null;
        $versions[$cycle] = [
            'eol' => $eol,
            'approx' => false,
            'release' => nivmp_valid_date($release) ? $release : null,
            'name' => null,
            'latest' => (is_string($latest) && preg_match('/^\d+(\.\d+){1,3}$/', $latest)) ? $latest : null,
            'latest_date' => nivmp_valid_date($latestDate) ? $latestDate : null,
        ];
    }
    return count($versions) >= 5 ? $versions : [];
}

/** Build the schedule from the static fallback table of config.php. */
function nivmp_fallback_versions(array $cfg): array
{
    $versions = [];
    foreach (($cfg['eol']['fallback'] ?? []) as $major => $eol) {
        if (nivmp_valid_date($eol)) {
            $versions[(string) $major] = [
                'eol' => $eol,
                'approx' => false,
                'release' => null,
                'name' => null,
                'latest' => null,
                'latest_date' => null,
            ];
        }
    }
    return $versions;
}

/* ------------------------------------------------------------------ */
/* EOL schedule: loading with cache and fallbacks                      */
/* ------------------------------------------------------------------ */

/** Try each configured live source in order. Returns ['source','versions'] or null. */
function nivmp_eol_fetch(array $cfg): ?array
{
    $ec = $cfg['eol'];
    foreach (($ec['sources'] ?? []) as $source) {
        if ($source === 'wiki') {
            $url = $ec['wiki_url'];
        } elseif ($source === 'endoflife') {
            $url = $ec['endoflife_url'];
        } else {
            continue;
        }
        $res = nivmp_http_multi(['x' => $url], $cfg['http'])['x'];
        if ($res['error'] !== null || $res['code'] !== 200 || $res['body'] === '') {
            continue;
        }
        $versions = ($source === 'wiki')
            ? nivmp_parse_wiki($res['body'])
            : nivmp_parse_endoflife(json_decode($res['body'], true));
        if ($versions) {
            return ['source' => $source, 'versions' => $versions];
        }
    }
    return null;
}

/**
 * Load the support schedule.
 * Order: fresh cache -> live sources -> stale cache -> built-in table.
 * Returns ['source' => wiki|endoflife|fallback, 'versions' => [...], 'fetched_at' => ?int, 'stale' => bool].
 */
function nivmp_load_eol(array $cfg): array
{
    $ttl = (int) ($cfg['eol']['cache_ttl'] ?? 21600);
    $retry = (int) ($cfg['eol']['retry_after'] ?? 300);

    $cached = nivmp_cache_get($cfg, 'eol', $ttl);
    if ($cached && !$cached['expired']) {
        return [
            'source' => $cached['v']['source'],
            'versions' => $cached['v']['versions'],
            'fetched_at' => (int) $cached['t'],
            'stale' => false,
        ];
    }

    // Do not hammer a failing source on every page view.
    $failure = nivmp_cache_get($cfg, 'eol-fail', $retry);
    if (!$failure || $failure['expired']) {
        $live = nivmp_eol_fetch($cfg);
        if ($live) {
            nivmp_cache_put($cfg, 'eol', $live);
            return $live + ['fetched_at' => time(), 'stale' => false];
        }
        nivmp_cache_put($cfg, 'eol-fail', true);
    }

    if ($cached) {
        return [
            'source' => $cached['v']['source'],
            'versions' => $cached['v']['versions'],
            'fetched_at' => (int) $cached['t'],
            'stale' => true,
        ];
    }

    return [
        'source' => 'fallback',
        'versions' => nivmp_fallback_versions($cfg),
        'fetched_at' => null,
        'stale' => false,
    ];
}

/* ------------------------------------------------------------------ */
/* Instances                                                           */
/* ------------------------------------------------------------------ */

/**
 * Normalise the configured instances.
 * Accepts 'host', 'host/subfolder', 'https://host', or 'host' => [options].
 * Options: name (string), enterprise (bool).
 */
function nivmp_normalize_instances(array $list): array
{
    $targets = [];
    foreach ($list as $key => $value) {
        if (is_int($key)) {
            $spec = (string) $value;
            $opts = [];
        } else {
            $spec = (string) $key;
            $opts = is_array($value) ? $value : [];
        }
        $spec = trim($spec);
        if ($spec === '') {
            continue;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $spec) && !preg_match('#^https?://#i', $spec)) {
            continue; // another scheme (ftp://, file://...): not a Nextcloud instance URL
        }
        if (!preg_match('#^https?://#i', $spec)) {
            $spec = 'https://' . $spec;
        }
        $p = parse_url($spec);
        if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme']), ['http', 'https'], true)) {
            continue;
        }
        $path = isset($p['path']) ? preg_replace('#/(status\.php)?$#i', '', $p['path']) : '';
        $hostPath = strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '') . $path;
        $base = strtolower($p['scheme']) . '://' . $hostPath;
        if (isset($targets[$base])) {
            continue;
        }
        $targets[$base] = [
            'base' => $base,
            'status_url' => $base . '/status.php',
            'host' => $hostPath,
            'name' => isset($opts['name']) && $opts['name'] !== '' ? (string) $opts['name'] : $hostPath,
            'enterprise' => !empty($opts['enterprise']),
        ];
    }
    $targets = array_values($targets);
    usort($targets, function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    return $targets;
}

/**
 * Query status.php of every instance (parallel, short cache).
 * Returns [index => ['ok','http','error','ms','data','fetched_at']].
 */
function nivmp_fetch_statuses(array $targets, array $cfg): array
{
    $ttl = (int) ($cfg['cache']['status_ttl'] ?? 60);
    $out = [];
    $todo = [];

    foreach ($targets as $i => $t) {
        $cached = $ttl > 0 ? nivmp_cache_get($cfg, 'st-' . md5($t['status_url']), $ttl) : null;
        if ($cached && !$cached['expired']) {
            $out[$i] = $cached['v'] + ['fetched_at' => (int) $cached['t']];
        } else {
            $todo[$i] = $t['status_url'];
        }
    }

    if ($todo) {
        foreach (nivmp_http_multi($todo, $cfg['http']) as $i => $res) {
            $data = json_decode($res['body'], true);
            $valid = is_array($data) && (isset($data['version']) || array_key_exists('installed', $data));
            $error = $res['error'];
            if ($error === null && !$valid) {
                $error = ($res['code'] !== 200 && $res['code'] !== 0) ? 'http' : 'invalid';
            }
            $entry = [
                'ok' => $error === null,
                'http' => $res['code'],
                'error' => $error,
                'ms' => $res['ms'],
                'data' => $error === null ? $data : null,
            ];
            if ($ttl > 0) {
                nivmp_cache_put($cfg, 'st-' . md5($targets[$i]['status_url']), $entry);
            }
            $out[$i] = $entry + ['fetched_at' => time()];
        }
    }

    ksort($out);
    return $out;
}

/* ------------------------------------------------------------------ */
/* Analysis of one instance                                            */
/* ------------------------------------------------------------------ */

/**
 * Combine status.php data with the support schedule.
 *
 * Severity (for colours): ok | warn | crit | info | muted
 * Group (for the summary): ok | attention | critical | unreachable
 */
function nivmp_evaluate(array $target, array $res, array $eol, DateTimeImmutable $today, array $cfg): array
{
    $warnDays = (int) ($cfg['eol']['warn_days'] ?? 30);
    $critDays = (int) ($cfg['eol']['critical_days'] ?? 15);

    $r = [
        'name' => $target['name'],
        'host' => $target['host'],
        'href' => $target['base'],
        'http' => $res['http'] ?? 0,
        'ms' => $res['ms'] ?? 0,
        'error' => $res['error'] ?? null,
        'state' => 'unreachable',
        'state_severity' => 'muted',
        'productname' => null,
        'version' => null,
        'build' => null,
        'major' => null,
        'edition' => 'standard',
        'support' => null,
        'support_severity' => 'muted',
        'eol' => null,
        'eol_approx' => false,
        'days' => null,
        'latest' => null,
        'outdated' => false,
        'group' => 'unreachable',
    ];

    if (empty($res['ok']) || !is_array($res['data'])) {
        return $r;
    }
    $d = $res['data'];

    // --- State -------------------------------------------------------
    if (empty($d['installed'])) {
        $r['state'] = 'not_installed';
        $r['state_severity'] = 'warn';
    } elseif (!empty($d['maintenance'])) {
        $r['state'] = 'maintenance';
        $r['state_severity'] = 'warn';
    } elseif (!empty($d['needsDbUpgrade'])) {
        $r['state'] = 'upgrade';
        $r['state_severity'] = 'warn';
    } else {
        $r['state'] = 'online';
        $r['state_severity'] = 'ok';
    }

    // --- Identity ----------------------------------------------------
    $product = (isset($d['productname']) && is_string($d['productname']) && $d['productname'] !== '')
        ? $d['productname'] : 'Nextcloud';
    $r['productname'] = $product;

    $build = (isset($d['version']) && is_string($d['version']) && preg_match('/^\d+(\.\d+){1,3}$/', $d['version']))
        ? $d['version'] : null;
    $versionString = (isset($d['versionstring']) && is_string($d['versionstring']) && preg_match('/^\d+(\.\d+){1,3}/', $d['versionstring'], $vm))
        ? $vm[0] : null;
    if ($versionString === null && $build !== null) {
        $versionString = implode('.', array_slice(explode('.', $build), 0, 3));
    }
    $r['build'] = $build;
    $r['version'] = $versionString;
    $r['major'] = $versionString !== null ? (int) explode('.', $versionString)[0] : null;

    // --- Edition -----------------------------------------------------
    // Note: status.php hard-codes "edition" to an empty string, so it cannot reveal
    // an Enterprise edition. "extendedSupport" is the only automatic signal.
    $editionField = (isset($d['edition']) && is_string($d['edition'])) ? $d['edition'] : '';
    if ($target['enterprise'] || stripos($editionField, 'enterprise') !== false) {
        $r['edition'] = 'enterprise';
    } elseif (!empty($d['extendedSupport'])) {
        $r['edition'] = 'extended';
    }
    $hasExtended = !empty($d['extendedSupport']);

    // --- Support -----------------------------------------------------
    $isOwnCloud = stripos($product, 'owncloud') !== false;
    $schedule = ($r['major'] !== null && !$isOwnCloud) ? ($eol['versions'][(string) $r['major']] ?? null) : null;

    if ($schedule === null) {
        $r['support'] = 'unknown';
        $r['support_severity'] = 'muted';
    } else {
        $eolDate = DateTimeImmutable::createFromFormat('!Y-m-d', $schedule['eol']);
        $r['eol'] = $schedule['eol'];
        $r['eol_approx'] = !empty($schedule['approx']);
        $r['days'] = (int) $today->diff($eolDate)->format('%r%a');
        $r['latest'] = $schedule['latest'] ?? null;

        if ($r['days'] >= 0) {
            $r['support'] = 'yes';
            if ($r['days'] <= $critDays) {
                $r['support_severity'] = 'crit';
            } elseif ($r['days'] <= $warnDays) {
                $r['support_severity'] = 'warn';
            } else {
                $r['support_severity'] = 'ok';
            }
        } elseif ($hasExtended) {
            $r['support'] = 'extended';
            $r['support_severity'] = 'info';
        } else {
            $r['support'] = 'no';
            $r['support_severity'] = 'crit';
        }

        if ($r['latest'] !== null && $versionString !== null
            && version_compare($versionString, $r['latest'], '<')) {
            $r['outdated'] = true;
        }
    }

    // --- Group for the summary ----------------------------------------
    if ($r['support'] === 'no' || $r['support_severity'] === 'crit') {
        $r['group'] = 'critical';
    } elseif ($r['state_severity'] === 'warn' || $r['support_severity'] === 'warn' || $r['support'] === 'unknown') {
        $r['group'] = 'attention';
    } else {
        $r['group'] = 'ok';
    }

    return $r;
}

/* ------------------------------------------------------------------ */
/* Rendering helpers                                                   */
/* ------------------------------------------------------------------ */

/** A status pill. Severity picks colour AND symbol (never colour alone). */
function nivmp_badge(string $severity, string $text, string $title = '', string $symbol = ''): string
{
    $symbols = ['ok' => '✓', 'warn' => '!', 'crit' => '✕', 'info' => 'i', 'muted' => '–'];
    return '<span class="badge badge--' . h($severity) . '"'
        . ($title !== '' ? ' title="' . h($title) . '"' : '')
        . '><span class="badge__icon" aria-hidden="true">' . h($symbol !== '' ? $symbol : $symbols[$severity]) . '</span>'
        . h($text) . '</span>';
}

/** Human text for a signed number of days (positive = left, negative = ago). */
function nivmp_days_text(int $days): string
{
    if ($days === 0) {
        return nivmp_t('days_last');
    }
    if ($days > 0) {
        return $days === 1 ? nivmp_t('days_left_1') : nivmp_t('days_left_n', $days);
    }
    $ago = abs($days);
    return $ago === 1 ? nivmp_t('days_ago_1') : nivmp_t('days_ago_n', $ago);
}
