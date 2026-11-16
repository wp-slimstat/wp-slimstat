<?php
/**
 * A standalone `// phpcs:ignore` silences only the NEXT line. When a comment or a blank line
 * lands between it and the code it covered, it silences nothing and the findings come back in
 * Plugin Check. #405 did exactly that in admin/view/wp-slimstat-db.php, and only a Plugin Check
 * run on the release ZIP noticed (6 warnings).
 *
 * Scans every shipped PHP file outside vendor/, vendor-prefixed/ and tests/.
 */

$root = dirname(__DIR__);
$skip = '#/(vendor|vendor-prefixed|tests|node_modules|packages)/#';

/** @return list<string> "file:line" of each standalone ignore whose next line is not code. */
function phpcs_orphaned_ignores(string $source, string $label): array
{
    $lines = preg_split('/\R/', $source);
    $bad = [];
    foreach ($lines as $i => $line) {
        if (!preg_match('#^\s*//\s*phpcs:ignore\b#', $line)) {
            continue;
        }
        $next = $lines[$i + 1] ?? '';
        if ('' === trim($next) || preg_match('#^\s*(//|/\*|\*|\#)#', $next)) {
            $bad[] = $label . ':' . ($i + 1);
        }
    }
    return $bad;
}

// The check must be able to fail: the #405 shape is caught, the fixed shape is not.
$broken = "<?php\n// phpcs:ignore WordPress.Security.NonceVerification.Recommended\n// explains the condition\nif (\$_REQUEST['page']) {}\n";
$fixed  = "<?php\n// explains the condition\n// phpcs:ignore WordPress.Security.NonceVerification.Recommended\nif (\$_REQUEST['page']) {}\n";
if (['fixture:2'] !== phpcs_orphaned_ignores($broken, 'fixture') || [] !== phpcs_orphaned_ignores($fixed, 'fixture')) {
    fwrite(STDERR, "FAIL: phpcs-ignore placement self-check\n");
    exit(1);
}

$bad = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $path = $file->getPathname();
    if ('php' !== $file->getExtension() || preg_match($skip, str_replace('\\', '/', substr($path, strlen($root))))) {
        continue;
    }
    $bad = array_merge($bad, phpcs_orphaned_ignores((string) file_get_contents($path), substr($path, strlen($root) + 1)));
}

if ($bad) {
    fwrite(STDERR, "FAIL: phpcs:ignore not directly above code (it silences nothing):\n  " . implode("\n  ", $bad) . "\n");
    exit(1);
}
echo "PASS: every standalone phpcs:ignore sits directly above code\n";
