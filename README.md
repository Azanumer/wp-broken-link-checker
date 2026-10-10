# Broken Link Checker

WordPress plugin that finds **broken external links** in your content — without sending your data to any third-party service.

## How it works

1. A twice-daily cron scans your posts/pages in batches (default 5 posts per run), extracting external links from post content.
2. Each link is checked with `wp_remote_head` (with a `GET` fallback for servers that reject HEAD). Redirects are reported as-is (not followed), so you see the actual `3xx` and its target.
3. Broken links (4xx/5xx or unreachable) show up under **Tools → Broken Links** with the post they appear in, so you can fix them in one click.
4. A dashboard notice warns editors while any broken links are pending.

## Features

- **Batch cron scanning** — a cursor wraps around your posts, so even large sites get fully scanned over a few runs
- **Per-link re-check** and **dismiss/restore** actions
- **Redirects view** — catch affiliate URLs and old links that still 301 somewhere
- **Stale-link cleanup** — links removed from a post disappear from the report on the next scan
- **Broken count resets dismissal** — if a previously dismissed link breaks again, it pops back up
- `wblc_exclude_patterns` filter to skip URL patterns (e.g. your CDN):

```php
add_filter('wblc_exclude_patterns', function ($patterns) {
	$patterns[] = '#^https?://cdn\.example\.com/#';
	return $patterns;
});
```

## Requirements

- WordPress 5.0+, PHP 7.4+
- Outbound HTTP allowed from the server (for the link checks)

## Installation

1. Copy `wp-broken-link-checker.php` and `uninstall.php` into `wp-content/plugins/wp-broken-link-checker/`
2. Activate the plugin — the scan schedule starts immediately
3. Go to **Tools → Broken Links** and click **"Scan next batch now"** to kick off the first scan

## Settings

| Option (`wblc_settings`) | Default | Notes |
|---|---|---|
| `batch_posts` | `5` | Posts scanned per cron run — raise on small sites, lower on shared hosting |
| `timeout` | `15` | Seconds to wait per link check |

Both are stored in the `wblc_settings` option; the scan position lives in `wblc_scan_cursor`.

MIT licensed.
