<?php
declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . '/');
define('WPINC', 'wp-includes');
define('WP_CONTENT_DIR', dirname(dirname(__DIR__)));

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Brain\Monkey;

// Mockery + Brain Monkey lifecycle is handled per-test in WpSlimstatTestCase
