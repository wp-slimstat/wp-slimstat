<?php
declare(strict_types=1);

/**
 * Bootstrap for the Integration test suite.
 *
 * Intentionally mirrors tests/bootstrap.php but skips tests/Unit/Tracker/stubs.php,
 * which defines get_option / delete_option as real global functions. Integration
 * tests Monkey-patch those via Brain Monkey, which requires Patchwork to own the
 * definition — so stubs must NOT pre-define them.
 */

$root = dirname(__DIR__);

define('ABSPATH', $root . '/');
define('WPINC', 'wp-includes');
define('WP_CONTENT_DIR', dirname(dirname($root)));

require_once $root . '/vendor/autoload.php';
