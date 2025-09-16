<?php

namespace SlimStat\Tracker;

class Routing
{
	public static function addRewriteRules()
	{
		add_rewrite_tag('%slimstat_tracker%', '([a-f0-9]{32})');
		add_rewrite_rule('^([a-f0-9]{32})\\.js$', 'index.php?slimstat_tracker=$matches[1]', 'top');
		add_rewrite_rule('^([a-f0-9]{32})\\.css$', 'index.php?slimstat_tracker=$matches[1]', 'top');
		add_rewrite_rule('^([a-f0-9]{32})$', 'index.php?slimstat_tracker=$matches[1]', 'top');
	}

	public static function outputTrackerJsIfRequested()
	{
		$tracker_hash = get_query_var('slimstat_tracker');
		if ($tracker_hash) {
			$uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
			$expected_js_hash = md5(site_url() . 'slimstat');
			$expected_css_hash = md5(site_url() . 'slimstat_gdpr_banner_css');

			// Serve hashed JS
			if ($tracker_hash === $expected_js_hash && (false !== strpos($uri, '.js'))) {
				header('Content-Type: application/javascript');
				header('Cache-Control: public, max-age=31536000');
				header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');

				$js_path = SLIMSTAT_ANALYTICS_DIR . 'wp-slimstat.min.js';
				if (file_exists($js_path)) {
					readfile($js_path);
					exit;
				}

				status_header(404);
				echo '// Tracker not found';
				exit;
			}

			// Serve hashed GDPR CSS (used in adblock bypass mode)
			if ($tracker_hash === $expected_css_hash && (false !== strpos($uri, '.css'))) {
				header('Content-Type: text/css; charset=utf-8');
				header('Cache-Control: public, max-age=31536000');
				header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');

				$css_path = SLIMSTAT_ANALYTICS_DIR . 'assets/css/gdpr-consent.css';
				if (file_exists($css_path)) {
					readfile($css_path);
					exit;
				}

				status_header(404);
				echo '/* GDPR CSS not found */';
				exit;
			}
		}
	}
}
