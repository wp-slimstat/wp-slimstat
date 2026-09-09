<?php
/** Fresh catalog, repeat generation, and deliberately stale source control. */
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit(1);
}
passthru('python3 ' . escapeshellarg(__DIR__ . '/pot_freshness_check.py'), $status);
exit($status);
