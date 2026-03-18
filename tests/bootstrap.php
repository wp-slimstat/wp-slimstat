<?php
declare(strict_types=1);

$root = dirname(__DIR__);

define('ABSPATH', $root . '/');
define('WPINC', 'wp-includes');
define('WP_CONTENT_DIR', dirname(dirname($root)));

require_once $root . '/vendor/autoload.php';

// Shared stubs required by Tracker unit tests.
require_once __DIR__ . '/unit/Tracker/stubs.php';
