<?php
/**
 * Source-level: the dead, unloaded src/symfony/ Symfony deprecation-contracts
 * directory stays removed, and no tooling config references it.
 *
 * PINS the Phase-7 cleanup. The directory's sole file used the PHP 8.0 `mixed`
 * type hint (a latent 7.4 parse hazard) but was never autoloaded — a stray
 * vendor copy. It was deleted; this guards against it (or its config refs)
 * coming back, and against a stale rector/pint skip entry pointing at a
 * non-existent path.
 */

declare(strict_types=1);

$plugin_root = dirname(__DIR__);
$violations  = [];

if (is_dir($plugin_root . '/src/symfony')) {
    $violations[] = 'src/symfony/ still exists (dead, unloaded Symfony deprecation-contracts)';
}

foreach (['rector.php', 'pint.json'] as $cfg) {
    $contents = @file_get_contents($plugin_root . '/' . $cfg);
    if (false !== $contents && false !== strpos($contents, 'src/symfony')) {
        $violations[] = "{$cfg} still references the removed src/symfony path";
    }
}

if ($violations) {
    fwrite(STDERR, "FAIL: dead src/symfony cleanup regressed:\n");
    foreach ($violations as $v) fwrite(STDERR, "  - {$v}\n");
    exit(1);
}

echo "OK: src/symfony removed and unreferenced in rector.php / pint.json\n";
