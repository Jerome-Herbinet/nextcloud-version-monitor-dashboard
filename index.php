<?php
/**
 * NIVMP - Nextcloud Instances Versions Monitoring Page
 * Edit config.php to set your instances. No database needed.
 */
$cfg   = require __DIR__ . '/config.php';
$langs = require __DIR__ . '/lang.php';
require __DIR__ . '/functions.php';

if (!empty($cfg['timezone'])) {
    date_default_timezone_set($cfg['timezone']);
}

// Session: only used to remember the visitor's language between pages.
if (session_status() === PHP_SESSION_NONE) {
    session_name('nivmp_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    @session_start();
}

// Language: ?lang= (whitelisted, remembered in the session), then the
// session value, then config, then English.
$langCode = 'en';
if (isset($_GET['lang']) && is_string($_GET['lang']) && isset($langs[$_GET['lang']])) {
    $langCode = $_GET['lang'];
    $_SESSION['nivmp_lang'] = $langCode;
} elseif (!empty($_SESSION['nivmp_lang']) && isset($langs[$_SESSION['nivmp_lang']])) {
    $langCode = $_SESSION['nivmp_lang'];
} elseif (isset($cfg['lang']) && isset($langs[$cfg['lang']])) {
    $langCode = $cfg['lang'];
}
nivmp_lang($langs[$langCode]);

// Hardening headers. Everything (CSS, JS, favicon) is served from this folder.
header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self' data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

// --- Collect and analyse -----------------------------------------------------
$today    = new DateTimeImmutable('today');
$targets  = nivmp_normalize_instances($cfg['instances'] ?? []);
$eol      = nivmp_load_eol($cfg);
$statuses = nivmp_fetch_statuses($targets, $cfg);

$rows = [];
$counts = ['total' => 0, 'ok' => 0, 'attention' => 0, 'critical' => 0, 'unreachable' => 0];
$oldest = null;
foreach ($targets as $i => $target) {
    $res = $statuses[$i];
    $row = nivmp_evaluate($target, $res, $eol, $today, $cfg);
    $rows[] = $row;
    $counts['total']++;
    $counts[$row['group']]++;
    $oldest = $oldest === null ? $res['fetched_at'] : min($oldest, $res['fetched_at']);
}
$checkedAt = $oldest ?? time();

$summary = [
    ['key' => 'total',       'label' => nivmp_t('sum_total'),       'class' => 'total'],
    ['key' => 'ok',          'label' => nivmp_t('sum_ok'),          'class' => 'ok'],
    ['key' => 'attention',   'label' => nivmp_t('sum_attention'),   'class' => 'warn'],
    ['key' => 'critical',    'label' => nivmp_t('sum_critical'),    'class' => 'crit'],
    ['key' => 'unreachable', 'label' => nivmp_t('sum_unreachable'), 'class' => 'muted'],
];

// Legend contents (term key => explanation key).
$legendCols = [
    'col_instance' => 'legend_c_instance',
    'col_state'    => 'legend_c_state',
    'col_version'  => 'legend_c_version',
    'col_edition'  => 'legend_c_edition',
    'col_support'  => 'legend_c_support',
    'col_eol'      => 'legend_c_eol',
    'col_days'     => 'legend_c_days',
];
$legendStates = [
    'state_online'        => 'legend_s_online',
    'state_maintenance'   => 'legend_s_maintenance',
    'state_upgrade'       => 'legend_s_upgrade',
    'state_not_installed' => 'legend_s_not_installed',
    'state_unreachable'   => 'legend_s_unreachable',
];
$legendEditions = [
    'ed_standard'   => 'ed_standard_tip',
    'ed_extended'   => 'ed_extended_tip',
    'ed_enterprise' => 'ed_enterprise_tip',
];
$legendSupport = [
    'sup_yes'      => 'legend_sup_yes',
    'sup_no'       => 'legend_sup_no',
    'sup_extended' => 'sup_extended_tip',
    'sup_unknown'  => 'sup_unknown_tip',
];
$legendColors = ['ok', 'warn', 'crit', 'info', 'muted'];
$legendTerms = [
    'term_statusphp' => 'legend_t_statusphp',
    'term_eol'       => 'legend_t_eol',
    'term_major'     => 'legend_t_major',
    'term_approx'    => 'legend_t_approx',
];

// Sort ranks (so that sorting a column makes sense).
$stateRank   = ['online' => 0, 'maintenance' => 1, 'upgrade' => 2, 'not_installed' => 3, 'unreachable' => 4];
$editionRank = ['enterprise' => 0, 'extended' => 1, 'standard' => 2];
$supportRank = ['yes' => 0, 'extended' => 1, 'unknown' => 2, 'no' => 3];

function nivmp_error_text(array $r): string
{
    $e = $r['error'] ?? 'other';
    if ($e === 'http') {
        return nivmp_t('err_http', (int) $r['http']);
    }
    return nivmp_t('err_' . $e);
}
?>
<!DOCTYPE html>
<html lang="<?= h($langCode) ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="color-scheme" content="light dark">
	<meta name="robots" content="noindex, nofollow">
	<meta name="format-detection" content="telephone=no">
	<title><?= h(nivmp_t('title')) ?></title>
	<link rel="icon" type="image/svg+xml" href="favicon.svg">
	<link rel="stylesheet" href="styles.css">
	<script src="scripts.js" defer></script>
</head>
<body>
	<header class="site-header">
		<div class="wrap site-header__inner">
			<svg class="brand__mark" viewBox="0 0 32 32" width="40" height="40" role="img" aria-label="NIVMP">
				<rect width="32" height="32" rx="8" class="brand__bg"/>
				<polyline points="5,17 11,17 14,8 18,25 21,17 27,17" class="brand__pulse"/>
			</svg>
			<div>
				<h1 class="site-header__title"><?= h(nivmp_t('title')) ?></h1>
				<p class="site-header__subtitle"><?= h(nivmp_t('subtitle')) ?></p>
			</div>
			<nav class="lang-nav" aria-label="<?= h(nivmp_t('lang_label')) ?>">
<?php foreach (array_keys($langs) as $code): ?>
<?php if ($code === $langCode): ?>
				<span class="lang-nav__current" aria-current="true"><?= h(strtoupper($code)) ?></span>
<?php else: ?>
				<a href="?lang=<?= h($code) ?>" hreflang="<?= h($code) ?>" lang="<?= h($code) ?>"><?= h(strtoupper($code)) ?></a>
<?php endif; ?>
<?php endforeach; ?>
			</nav>
		</div>
	</header>

	<main class="wrap">
		<section class="summary" aria-label="<?= h(nivmp_t('subtitle')) ?>">
<?php foreach ($summary as $s): ?>
			<div class="tile tile--<?= h($s['class']) ?>">
				<span class="tile__value"><?= (int) $counts[$s['key']] ?></span>
				<span class="tile__label"><?= h($s['label']) ?></span>
			</div>
<?php endforeach; ?>
		</section>

		<div class="toolbar">
			<label class="toolbar__search" for="filter">
				<span class="sr-only"><?= h(nivmp_t('search_label')) ?></span>
				<input type="search" id="filter" placeholder="<?= h(nivmp_t('search_ph')) ?>" autocomplete="off">
			</label>
			<label class="toolbar__sort" for="sort" data-asc="<?= h(nivmp_t('asc')) ?>" data-desc="<?= h(nivmp_t('desc')) ?>">
				<span><?= h(nivmp_t('sort_by')) ?></span>
				<select id="sort"></select>
			</label>
		</div>

		<div class="table-wrap">
			<table id="instances" class="sortable">
				<caption class="sr-only"><?= h(nivmp_t('title')) ?></caption>
				<thead>
					<tr>
						<th scope="col"><button type="button"><?= h(nivmp_t('col_instance')) ?><span aria-hidden="true"></span></button></th>
						<th scope="col" data-type="num"><button type="button"><?= h(nivmp_t('col_state')) ?><span aria-hidden="true"></span></button></th>
						<th scope="col"><button type="button"><?= h(nivmp_t('col_version')) ?><span aria-hidden="true"></span></button></th>
						<th scope="col" data-type="num"><button type="button"><?= h(nivmp_t('col_edition')) ?><span aria-hidden="true"></span></button></th>
						<th scope="col" data-type="num"><button type="button"><?= h(nivmp_t('col_support')) ?><span aria-hidden="true"></span></button></th>
						<th scope="col"><button type="button"><?= h(nivmp_t('col_eol')) ?><span aria-hidden="true"></span></button></th>
						<th scope="col" data-type="num"><button type="button"><?= h(nivmp_t('col_days')) ?><span aria-hidden="true"></span></button></th>
					</tr>
				</thead>
				<tbody>
<?php foreach ($rows as $r): ?>
<?php
	$reachable = $r['state'] !== 'unreachable';

	// State
	if ($reachable) {
		$stateBadge = nivmp_badge($r['state_severity'], nivmp_t('state_' . $r['state']));
	} else {
		$stateBadge = nivmp_badge('muted', nivmp_t('state_unreachable'), nivmp_error_text($r))
			. '<span class="cell__sub">' . h(nivmp_error_text($r)) . '</span>';
	}

	// Edition
	$editionTip = nivmp_t('ed_' . $r['edition'] . '_tip');
	$editionSeverity = $r['edition'] === 'standard' ? 'muted' : 'info';

	// Support
	$supportTips = ['unknown' => nivmp_t('sup_unknown_tip'), 'extended' => nivmp_t('sup_extended_tip')];
	$supportTip = $supportTips[$r['support']] ?? '';
?>
					<tr>
						<th scope="row" data-label="<?= h(nivmp_t('col_instance')) ?>" data-sort="<?= h(strtolower($r['name'])) ?>">
							<a href="<?= h($r['href']) ?>" target="_blank" rel="noopener noreferrer"><?= h($r['name']) ?></a>
<?php if ($r['name'] !== $r['host']): ?>
							<span class="cell__sub"><?= h($r['host']) ?></span>
<?php elseif ($reachable && $r['productname'] !== 'Nextcloud'): ?>
							<span class="cell__sub"><?= h($r['productname']) ?></span>
<?php endif; ?>
						</th>
						<td data-label="<?= h(nivmp_t('col_state')) ?>" data-sort="<?= (int) $stateRank[$r['state']] ?>"><?= $stateBadge ?></td>
<?php if ($reachable): ?>
						<td data-label="<?= h(nivmp_t('col_version')) ?>" data-sort="<?= h($r['version'] !== null ? nivmp_version_key($r['version']) : '') ?>">
<?php if ($r['version'] !== null): ?>
							<span class="mono" title="<?= h(nivmp_t('build_tip', $r['build'] ?? $r['version'])) ?>"><?= h($r['version']) ?></span>
<?php if ($r['outdated']): ?>
							<span class="cell__sub cell__sub--hint"><?= h(nivmp_t('update_to', $r['latest'])) ?></span>
<?php endif; ?>
<?php else: ?>
							<span class="muted"><?= h(nivmp_t('na')) ?></span>
<?php endif; ?>
						</td>
						<td data-label="<?= h(nivmp_t('col_edition')) ?>" data-sort="<?= (int) $editionRank[$r['edition']] ?>"><?= nivmp_badge($editionSeverity, nivmp_t('ed_' . $r['edition']), $editionTip) ?></td>
<?php if ($r['support'] !== 'unknown' && $r['eol'] !== null): ?>
						<td data-label="<?= h(nivmp_t('col_support')) ?>" data-sort="<?= (int) $supportRank[$r['support']] ?>"><?= nivmp_badge($r['support_severity'], nivmp_t('sup_' . $r['support']), $supportTip, $r['support'] === 'yes' && $r['support_severity'] !== 'ok' ? '!' : '') ?></td>
						<td data-label="<?= h(nivmp_t('col_eol')) ?>" data-sort="<?= h($r['eol']) ?>">
							<time datetime="<?= h($r['eol']) ?>"<?= $r['eol_approx'] ? ' title="' . h(nivmp_t('approx_tip')) . '"' : '' ?>><?= $r['eol_approx'] ? '≈ ' : '' ?><?= h($r['eol']) ?></time>
						</td>
						<td data-label="<?= h(nivmp_t('col_days')) ?>" data-sort="<?= (int) $r['days'] ?>"><span class="days days--<?= h($r['support_severity']) ?>"><?= h(nivmp_days_text($r['days'])) ?></span></td>
<?php else: ?>
						<td data-label="<?= h(nivmp_t('col_support')) ?>" data-sort="<?= (int) $supportRank[$r['support']] ?>"><?= nivmp_badge('muted', nivmp_t('sup_unknown'), $supportTip) ?></td>
						<td data-label="<?= h(nivmp_t('col_eol')) ?>" data-sort=""><span class="muted"><?= h(nivmp_t('na')) ?></span></td>
						<td data-label="<?= h(nivmp_t('col_days')) ?>" data-sort=""><span class="muted"><?= h(nivmp_t('na')) ?></span></td>
<?php endif; ?>
<?php else: ?>
<?php foreach (['col_version', 'col_edition', 'col_support', 'col_eol', 'col_days'] as $col): ?>
						<td data-label="<?= h(nivmp_t($col)) ?>" data-sort=""><span class="muted"><?= h(nivmp_t('na')) ?></span></td>
<?php endforeach; ?>
<?php endif; ?>
					</tr>
<?php endforeach; ?>
				</tbody>
			</table>
			<p id="no-results" class="empty" hidden><?= h(nivmp_t('no_results')) ?></p>
		</div>

		<details class="legend">
			<summary><?= h(nivmp_t('legend')) ?></summary>
			<div class="legend__grid">
				<section class="legend__section">
					<h2><?= h(nivmp_t('legend_dash')) ?></h2>
					<dl>
<?php foreach ($summary as $s): ?>
						<dt><?= h($s['label']) ?></dt>
						<dd><?= h(nivmp_t('legend_dash_' . $s['key'])) ?></dd>
<?php endforeach; ?>
					</dl>
				</section>
				<section class="legend__section">
					<h2><?= h(nivmp_t('legend_cols')) ?></h2>
					<dl>
<?php foreach ($legendCols as $term => $exp): ?>
						<dt><?= h(nivmp_t($term)) ?></dt>
						<dd><?= h(nivmp_t($exp)) ?></dd>
<?php endforeach; ?>
					</dl>
				</section>
				<section class="legend__section">
					<h2><?= h(nivmp_t('legend_states')) ?></h2>
					<dl>
<?php foreach ($legendStates as $term => $exp): ?>
						<dt><?= h(nivmp_t($term)) ?></dt>
						<dd><?= h(nivmp_t($exp)) ?></dd>
<?php endforeach; ?>
					</dl>
				</section>
				<section class="legend__section">
					<h2><?= h(nivmp_t('legend_editions')) ?></h2>
					<dl>
<?php foreach ($legendEditions as $term => $exp): ?>
						<dt><?= h(nivmp_t($term)) ?></dt>
						<dd><?= h(nivmp_t($exp)) ?></dd>
<?php endforeach; ?>
					</dl>
				</section>
				<section class="legend__section">
					<h2><?= h(nivmp_t('legend_support')) ?></h2>
					<dl>
<?php foreach ($legendSupport as $term => $exp): ?>
						<dt><?= h(nivmp_t($term)) ?></dt>
						<dd><?= h(nivmp_t($exp)) ?></dd>
<?php endforeach; ?>
					</dl>
				</section>
				<section class="legend__section">
					<h2><?= h(nivmp_t('legend_colors')) ?></h2>
					<dl>
<?php foreach ($legendColors as $sev): ?>
						<dt><?= nivmp_badge($sev, nivmp_t('legend_color_' . $sev)) ?></dt>
						<dd><?= h(nivmp_t('legend_color_' . $sev . '_exp')) ?></dd>
<?php endforeach; ?>
					</dl>
				</section>
				<section class="legend__section">
					<h2><?= h(nivmp_t('legend_terms')) ?></h2>
					<dl>
<?php foreach ($legendTerms as $term => $exp): ?>
						<dt><?= h(nivmp_t($term)) ?></dt>
						<dd><?= h(nivmp_t($exp)) ?></dd>
<?php endforeach; ?>
					</dl>
				</section>
			</div>
		</details>
	</main>

	<footer class="site-footer">
		<div class="wrap">
			<p>
				<?= h(nivmp_t('f_checked', date('Y-m-d H:i T', $checkedAt))) ?>
			</p>
			<p>
<?php
	$sourceKey = ['wiki' => 'f_src_wiki', 'endoflife' => 'f_src_endoflife', 'fallback' => 'f_src_fallback'][$eol['source']];
	$notes = [];
	if ($eol['fetched_at'] !== null) {
		$notes[] = nivmp_t('f_updated', date('Y-m-d H:i T', $eol['fetched_at']));
	}
	if ($eol['stale']) {
		$notes[] = nivmp_t('f_stale');
	}
?>
				<?= h(nivmp_t($sourceKey)) ?><?= $notes ? ' (' . h(implode(', ', $notes)) . ')' : '' ?>
				· <a href="https://docs.nextcloud.com/server/stable/admin_manual/release_schedule.html" target="_blank" rel="noopener noreferrer"><?= h(nivmp_t('f_schedule')) ?></a>
			</p>
			<p>
				<a href="https://github.com/Jerome-Herbinet/Nextcloud-Instances-Versions-Monitoring-Page" target="_blank" rel="noopener noreferrer"><?= h(nivmp_t('f_project', NIVMP_VERSION)) ?></a>
			</p>
		</div>
	</footer>
</body>
</html>
