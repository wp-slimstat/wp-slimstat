<?php
/** Test-only synthetic client addresses; never install on a real site. */
if (!defined('ABSPATH') || !defined('SLIMSTAT_BENCH') || true !== SLIMSTAT_BENCH
    || !defined('SLIMSTAT_HOSTING_DISPOSABLE') || true !== SLIMSTAT_HOSTING_DISPOSABLE
    || !defined('DB_NAME') || 0 !== strpos(DB_NAME, 'tests-')
    || !defined('DB_HOST') || 'db' !== DB_HOST
    || empty($_SERVER['HTTP_X_SLIMSTAT_BENCH'])) {
    return;
}
$slimstat_bench_ip = $_SERVER['HTTP_X_SLIMSTAT_BENCH_IP'] ?? '';
if (is_string($slimstat_bench_ip) && 0 === strpos($slimstat_bench_ip, '198.18.')
    && false !== filter_var($slimstat_bench_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    $_SERVER['REMOTE_ADDR'] = $slimstat_bench_ip;
}
unset($slimstat_bench_ip);
