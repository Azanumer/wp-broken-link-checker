<?php
/**
 * Plugin Name: Broken Link Checker
 * Plugin URI: https://github.com/Azanumer/wp-broken-link-checker
 * Description: Scans post content for external links, checks them in batches via cron, and reports broken ones under Tools → Broken Links. No external service required.
 * Version: 1.0.0
 * Author: Azan Umer
 * License: MIT
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

define('WBLC_VERSION', '1.0.0');
define('WBLC_CRON_HOOK', 'wblc_scan_posts');

function wblc_table() {
	global $wpdb;
	return $wpdb->prefix . 'broken_links';
}

/**
 * Activation: create table, defaults, schedule twice-daily scan.
 */
register_activation_hook(__FILE__, 'wblc_activate');
function wblc_activate() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	dbDelta(
		'CREATE TABLE ' . wblc_table() . " (\n" .
		"  url varchar(255) NOT NULL,\n" .
		"  post_id bigint(20) unsigned NOT NULL,\n" .
		"  status_code smallint(6) NOT NULL DEFAULT 0,\n" .
		"  status_label varchar(64) NOT NULL DEFAULT '',\n" .
		"  broken tinyint(1) NOT NULL DEFAULT 0,\n" .
		"  occurrences bigint(20) unsigned NOT NULL DEFAULT 1,\n" .
		"  first_seen datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,\n" .
		"  last_checked datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,\n" .
		"  dismissed tinyint(1) NOT NULL DEFAULT 0,\n" .
		"  PRIMARY KEY (url(191), post_id),\n" .
		"  KEY post_id (post_id),\n" .
		"  KEY broken (broken, dismissed)\n" .
		') ' . $wpdb->get_charset_collate() . ';'
	);

	add_option('wblc_settings', array(
		'batch_posts' => 5,   // posts scanned per cron run
		'timeout'     => 15,  // seconds per link check
	));
	add_option('wblc_scan_cursor', 0);

	if (!wp_next_scheduled(WBLC_CRON_HOOK)) {
		wp_schedule_event(time() + HOUR_IN_SECONDS, 'twicedaily', WBLC_CRON_HOOK);
	}
}

register_deactivation_hook(__FILE__, 'wblc_deactivate');
function wblc_deactivate() {
	wp_clear_scheduled_hook(WBLC_CRON_HOOK);
}

/**
 * Pull every external href out of a post's content.
 * Skips the site's own host and any pattern from the
 * 'wblc_exclude_patterns' filter (array of PCRE patterns).
 */
function wblc_extract_links($content) {
	$host = wp_parse_url(home_url(), PHP_URL_HOST);
	if (!preg_match_all('/<a\s[^>]*href=["\']((?:https?:)?\/\/[^"\']+)["\']/i', (string) $content, $m)) {
		return array();
	}

	$exclude = apply_filters('wblc_exclude_patterns', array());

	$links = array();
	foreach (array_unique($m[1]) as $url) {
		if (strpos($url, '//') === 0) {
			$url = 'https:' . $url; // protocol-relative
		}
		$parts = wp_parse_url($url);
		if (empty($parts['host']) || strcasecmp($parts['host'], (string) $host) === 0) {
			continue; // internal or malformed
		}
		$skip = false;
		foreach ($exclude as $pattern) {
			if (@preg_match($pattern, $url)) {
				$skip = true;
				break;
			}
		}
		if ($skip) {
			continue;
		}
		$links[] = esc_url_raw($url);
	}
	return $links;
}

/**
 * Check one URL: HEAD first (cheap), GET fallback for servers that
 * reject HEAD (405/501). No redirects followed — a 3xx is reported
 * as-is so you can see the redirect target.
 *
 * @return array{0:int, 1:string} [status code, label]
 */
function wblc_check_url($url, $timeout) {
	foreach (array('HEAD', 'GET') as $method) {
		$res = wp_remote_request($url, array(
			'method'      => $method,
			'timeout'     => $timeout,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array(
				'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url() . ' (broken-link-check)',
				'Accept'     => '*/*',
			),
		));

		if (is_wp_error($res)) {
			// GET retry can fix DNS/timeout hiccups; a second error is final.
			if ($method === 'HEAD') {
				continue;
			}
			return array(0, 'unreachable: ' . $res->get_error_message());
		}

		$code = (int) wp_remote_retrieve_response_code($res);
		$msg  = (string) wp_remote_retrieve_response_message($res);
		if (($code === 405 || $code === 501) && $method === 'HEAD') {
			continue; // retry with GET
		}
		$label = $code . ($msg !== '' ? ' ' . $msg : '');
		if ($code >= 300 && $code < 400) {
			$loc = (string) wp_remote_retrieve_header($res, 'location');
			if ($loc !== '') {
				$label .= ' → ' . $loc;
			}
		}
		return array($code, $label);
	}
	return array(0, 'unreachable');
}

/**
 * Cron worker: scan a batch of posts, checking every external link
 * found. The cursor wraps around so a full pass completes over
 * several runs regardless of post count.
 */
add_action(WBLC_CRON_HOOK, 'wblc_scan_batch');
function wblc_scan_batch() {
	global $wpdb;

	$settings = get_option('wblc_settings', array());
	$batch    = max(1, (int) ($settings['batch_posts'] ?? 5));
	$timeout  = max(5, (int) ($settings['timeout'] ?? 15));

	$total  = (int) wp_count_posts('post')->publish + (int) wp_count_posts('page')->publish;
	$cursor = (int) get_option('wblc_scan_cursor', 0);
	if ($total === 0) {
		return;
	}

	$posts = get_posts(array(
		'post_type'      => array('post', 'page'),
		'post_status'    => 'publish',
		'posts_per_page' => $batch,
		'offset'         => $cursor % $total,
		'orderby'        => 'ID',
		'order'          => 'ASC',
		'fields'         => 'ids',
	));

	foreach ($posts as $post_id) {
		$post  = get_post($post_id);
		$links = $post ? wblc_extract_links($post->post_content) : array();

		foreach ($links as $url) {
			list($code, $label) = wblc_check_url($url, $timeout);
			$broken = ($code === 0 || $code >= 400) ? 1 : 0;

			$wpdb->query($wpdb->prepare(
				'INSERT INTO ' . wblc_table() . ' (url, post_id, status_code, status_label, broken, occurrences, last_checked) ' .
				'VALUES (%s, %d, %d, %s, %d, 1, NOW()) ' .
				'ON DUPLICATE KEY UPDATE status_code = VALUES(status_code), status_label = VALUES(status_label), ' .
				'broken = VALUES(broken), occurrences = occurrences + 1, last_checked = NOW(), dismissed = IF(VALUES(broken) = broken, dismissed, 0)',
				$url, $post_id, $code, substr($label, 0, 64), $broken
			));
		}

		// Remove links that no longer appear in this post.
		if (!empty($links)) {
			$placeholders = implode(',', array_fill(0, count($links), '%s'));
			$wpdb->query($wpdb->prepare(
				'DELETE FROM ' . wblc_table() . " WHERE post_id = %d AND url NOT IN ($placeholders)",
				array_merge(array($post_id), $links)
			));
		}
	}

	update_option('wblc_scan_cursor', ($cursor + $batch) % $total);
}

/**
 * Admin page: Tools → Broken Links.
 */
add_action('admin_menu', 'wblc_admin_menu');
function wblc_admin_menu() {
	add_management_page(
		'Broken Links',
		'Broken Links',
		'edit_posts',
		'broken-links',
		'wblc_render_admin_page'
	);
}

function wblc_render_admin_page() {
	global $wpdb;

	// Handle actions (nonce-protected).
	if (isset($_POST['wblc_nonce']) && wp_verify_nonce($_POST['wblc_nonce'], 'wblc_bulk')) {
		if (isset($_POST['wblc_rescan'])) {
			update_option('wblc_scan_cursor', 0);
			wblc_scan_batch();
			echo '<div class="notice notice-success"><p>Scan finished for one batch of posts. The cron will keep scanning the rest.</p></div>';
		}
		if (isset($_POST['wblc_recheck'], $_POST['wblc_url'], $_POST['wblc_post'])) {
			$settings = get_option('wblc_settings', array());
			list($code, $label) = wblc_check_url(
				esc_url_raw(wp_unslash($_POST['wblc_url'])),
				max(5, (int) ($settings['timeout'] ?? 15))
			);
			$wpdb->query($wpdb->prepare(
				'UPDATE ' . wblc_table() . ' SET status_code = %d, status_label = %s, broken = %d, last_checked = NOW() WHERE url = %s AND post_id = %d',
				$code, substr($label, 0, 64), ($code === 0 || $code >= 400) ? 1 : 0,
				esc_url_raw(wp_unslash($_POST['wblc_url'])), (int) $_POST['wblc_post']
			));
			echo '<div class="notice notice-success"><p>Link re-checked.</p></div>';
		}
		if (isset($_POST['wblc_toggle'], $_POST['wblc_url'], $_POST['wblc_post'])) {
			$wpdb->query($wpdb->prepare(
				'UPDATE ' . wblc_table() . ' SET dismissed = 1 - dismissed WHERE url = %s AND post_id = %d',
				esc_url_raw(wp_unslash($_POST['wblc_url'])), (int) $_POST['wblc_post']
			));
		}
	}

	$filter  = isset($_GET['wblc_filter']) ? sanitize_key($_GET['wblc_filter']) : 'broken';
	$where   = 'WHERE broken = 1 AND dismissed = 0';
	if ($filter === 'redirects') {
		$where = 'WHERE status_code BETWEEN 300 AND 399 AND dismissed = 0';
	} elseif ($filter === 'all') {
		$where = 'WHERE 1 = 1';
	}

	$rows = $wpdb->get_results(
		'SELECT url, post_id, status_code, status_label, last_checked, occurrences, dismissed ' .
		'FROM ' . wblc_table() . " $where ORDER BY last_checked DESC LIMIT 500"
	);
	$broken_count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . wblc_table() . ' WHERE broken = 1 AND dismissed = 0');

	echo '<div class="wrap"><h1>Broken Links' .
		($broken_count > 0 ? ' <span class="update-plugins count-' . $broken_count . '"><span class="plugin-count">' . $broken_count . '</span></span>' : '') .
		'</h1>';

	echo '<p><a href="?page=broken-links&wblc_filter=broken"' . ($filter === 'broken' ? ' class="current"' : '') . '>Broken</a> | ' .
		'<a href="?page=broken-links&wblc_filter=redirects"' . ($filter === 'redirects' ? ' class="current"' : '') . '>Redirects</a> | ' .
		'<a href="?page=broken-links&wblc_filter=all"' . ($filter === 'all' ? ' class="current"' : '') . '>All checked</a></p>';

	echo '<form method="post">' . wp_nonce_field('wblc_bulk', 'wblc_nonce', true, false) .
		'<input type="submit" name="wblc_rescan" class="button button-primary" value="Scan next batch now"></form><br>';

	if (empty($rows)) {
		echo '<p>No links in this view. The scanner runs twice daily via cron.</p></div>';
		return;
	}

	echo '<table class="widefat striped"><thead><tr><th>Link</th><th>Status</th><th>Found in</th><th>Last checked</th><th>Actions</th></tr></thead><tbody>';
	foreach ($rows as $r) {
		$edit = get_edit_post_link((int) $r->post_id);
		echo '<tr>';
		echo '<td><a href="' . esc_url($r->url) . '" target="_blank" rel="noopener">' . esc_html(wp_trim_words($r->url, 12, '…')) . '</a></td>';
		echo '<td><code>' . esc_html($r->status_label) . '</code></td>';
		echo '<td><a href="' . esc_url($edit) . '">' . esc_html(get_the_title((int) $r->post_id)) . '</a></td>';
		echo '<td>' . esc_html($r->last_checked) . '</td>';
		echo '<td><form method="post" style="display:inline">' . wp_nonce_field('wblc_bulk', 'wblc_nonce', true, false) .
			'<input type="hidden" name="wblc_url" value="' . esc_attr($r->url) . '">' .
			'<input type="hidden" name="wblc_post" value="' . (int) $r->post_id . '">' .
			'<input type="submit" name="wblc_recheck" class="button button-small" value="Re-check"> ' .
			'<input type="submit" name="wblc_toggle" class="button button-small" value="' . ((int) $r->dismissed ? 'Restore' : 'Dismiss') . '"></form></td>';
		echo '</tr>';
	}
	echo '</tbody></table></div>';
}

/**
 * Dashboard glance: broken-link count on the Tools menu badge via
 * admin notice when there are fresh broken links.
 */
add_action('admin_notices', 'wblc_admin_notice');
function wblc_admin_notice() {
	global $wpdb, $pagenow;
	if ($pagenow === 'tools.php' && isset($_GET['page']) && $_GET['page'] === 'broken-links') {
		return;
	}
	$count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . wblc_table() . ' WHERE broken = 1 AND dismissed = 0');
	if ($count > 0 && current_user_can('edit_posts')) {
		echo '<div class="notice notice-warning"><p><strong>Broken Link Checker:</strong> ' .
			$count . ' broken link' . ($count === 1 ? '' : 's') . ' found. ' .
			'<a href="' . esc_url(admin_url('tools.php?page=broken-links')) . '">Review them</a>.</p></div>';
	}
}
