<?php
/**
 * The generated release autoloader must contain first-party classes and no dev tooling.
 *
 * Run `composer run build:autoload` first. The release builder does the same in its
 * commit-isolated source tree before creating the ZIP.
 */

declare(strict_types=1);

$root     = dirname(__DIR__);
$static   = @file_get_contents($root . '/vendor/composer/autoload_static.php');
$classmap = @file_get_contents($root . '/vendor/composer/autoload_classmap.php');
$failures = [];

if (!is_string($static) || !is_string($classmap)) {
    fwrite(STDERR, "FAIL: production autoloader is missing; run composer run build:autoload\n");
    exit(1);
}

if (preg_match('/public\s+static\s+\$files\s*=/', $static)) {
    $failures[] = 'autoload_static.php eagerly requires development files';
}

$slimstat = preg_match_all("/'SlimStat\\\\/", $classmap);
if ($slimstat < 99) {
    $failures[] = "autoload_classmap.php contains only {$slimstat} SlimStat entries";
}

$leaked = [];
foreach (['Mockery', 'PHPUnit', 'PhpParser', 'Hamcrest', 'DeepCopy', 'Brain'] as $prefix) {
    $hits = preg_match_all("/'" . $prefix . "\\\\/", $classmap);
    if ($hits > 0) {
        $leaked[] = "{$prefix} ({$hits})";
    }
}
if ($leaked) {
    $failures[] = 'development tooling is classmapped: ' . implode(', ', $leaked);
}

if ($failures) {
    fwrite(STDERR, 'FAIL: generated autoloader is not production-safe: ' . implode('; ', $failures) . "\n");
    exit(1);
}

echo "PASS: generated production autoloader ({$slimstat} SlimStat entries, no eager files or dev tooling)\n";
