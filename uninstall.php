<?php
/**
 * Cleanup on uninstall: drop the table, remove options, clear cron.
 */
defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'broken_links');
delete_option('wblc_settings');
delete_option('wblc_scan_cursor');
wp_clear_scheduled_hook('wblc_scan_posts');
