<?php
/**
 * Source-level regression: dtr_pton-style IP binarization helpers must
 * initialize $unpacked BEFORE the conditional IPv4/IPv6 branches.
 *
 * Without explicit init, an invalid IP leaves $unpacked undefined. On PHP
 * 8.1+ the downstream `str_split($unpacked[1])` evaluates to `['']` (one
 * empty char) and `ord('') + decbin(0) + str_pad(8)` yields a phantom
 * '00000000' — 8 fake zero bits leaking into IP-filter comparisons.
 *
 * @see src/Tracker/Tracker.php:_dtr_pton (was missing init, fixed in 5.4.16)
 * @see src/Tracker/Utils.php:dtrPton (already had `$unpacked = false;`)
 */

declare(strict_types=1);

$plugin_root = dirname(__DIR__);

$checks = [
    [
        'file'  => $plugin_root . '/src/Tracker/Tracker.php',
        'fn'    => '_dtr_pton',
        'label' => 'Tracker::_dtr_pton',
    ],
    [
        'file'  => $plugin_root . '/src/Tracker/Utils.php',
        'fn'    => 'dtrPton',
        'label' => 'Utils::dtrPton',
    ],
];

$violations = [];
foreach ($checks as $c) {
    $src = file_get_contents($c['file']);
    if ($src === false) {
        $violations[] = "{$c['label']}: cannot read {$c['file']}";
        continue;
    }
    // Match the function body up to the first `if (...)` that uses unpack().
    $pattern = '/function\s+' . preg_quote($c['fn'], '/')
        . '\s*\([^)]*\)\s*(?::\s*\w+\s*)?\{([\s\S]*?)(?:if\s*\(\s*filter_var)/m';
    if (!preg_match($pattern, $src, $m)) {
        $violations[] = "{$c['label']}: could not parse function body";
        continue;
    }
    $pre_branch = $m[1];
    if (!preg_match('/\$unpacked\s*=\s*(false|null|\[\])\s*;/', $pre_branch)) {
        $violations[] = "{$c['label']}: missing `\$unpacked = false;` initialization before filter_var branches in {$c['file']}";
    }
}

if ($violations) {
    fwrite(STDERR, "FAIL: dtr_pton helpers must initialize \$unpacked (PHP 8.1+ regression guard):\n");
    foreach ($violations as $v) fwrite(STDERR, "  - {$v}\n");
    fwrite(STDERR, "\nFix: add `\$unpacked = false;` between the function open-brace and the IPv4/IPv6 filter_var branches.\n");
    exit(1);
}

echo "OK: dtr_pton-style helpers initialize \$unpacked (Tracker::_dtr_pton, Utils::dtrPton)\n";
