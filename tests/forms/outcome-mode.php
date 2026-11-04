<?php
/** QA outcome injection only through documented builder/WordPress filters. @license GPL-2.0-or-later */
if (PHP_SAPI !== 'cli' || !defined('WP_CLI') || !WP_CLI || !preg_match('/^test_form_analytics_/', DB_NAME)) { throw new RuntimeException('Use a disposable Form Analytics site.'); }
$mode = $args[0] ?? '';
if (!in_array($mode, array('spam', 'failed', ''), true)) { throw new RuntimeException('Invalid fixture outcome.'); }
update_option('slimstat_forms_qa_outcome', $mode, false);
