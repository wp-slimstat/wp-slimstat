<?php

namespace SlimStat\Tracker;

class Routing
{
	public static function addRewriteRules()
	{
		add_rewrite_tag('%slimstat_tracker%', '([a-f0-9]{32})');
		add_rewrite_rule('^([a-f0-9]{32})\\.js$', 'index.php?slimstat_tracker=$matches[1]', 'top');
		add_rewrite_rule('^([a-f0-9]{32})$', 'index.php?slimstat_tracker=$matches[1]', 'top');
	}

	public static function outputTrackerJsIfRequested()
	{
		$tracker_hash = get_query_var('slimstat_tracker');
		if ($tracker_hash) {
			$expected_hash = md5(site_url() . 'slimstat');
			if ($tracker_hash === $expected_hash) {
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
		}
	}
}
