<?php
declare(strict_types=1);

$root = dirname(__DIR__);

define('ABSPATH', $root . '/');
define('WPINC', 'wp-includes');
define('WP_CONTENT_DIR', dirname(dirname($root)));
define('WP_PLUGIN_DIR', dirname($root));
define('SLIMSTAT_FILE', $root . '/wp-slimstat.php');
preg_match('/^ \* Version:\s*(\S+)/m', file_get_contents(SLIMSTAT_FILE), $pluginVersion);
define('SLIMSTAT_ANALYTICS_VERSION', $pluginVersion[1]);

// WordPress's time constants, with core's values. Class-constant initialisers reference them at
// CLASS-LOAD time, not at call time — MigrationManager::PROBE_TTL is `12 * HOUR_IN_SECONDS` —
// so without these the class cannot be loaded at all under the unit bootstrap and the failure
// reads "Undefined constant SlimStat\Migration\HOUR_IN_SECONDS", which points at the namespace
// rather than at the missing define.
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS);
define('DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS);
define('WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS);

require_once $root . '/vendor/autoload.php';
// Load the function interceptor before shared stubs so integration tests can replace them.
require_once $root . '/vendor/antecedent/patchwork/Patchwork.php';

// Shared stubs required by Tracker unit tests.
require_once __DIR__ . '/Unit/Tracker/stubs.php';
