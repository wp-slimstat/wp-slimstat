<?php
/**
 * Every admin script must parse. A `//` comment dropped into the middle of a one-line
 * object in ecommerce.js swallowed the rest of the line, so the whole file threw a
 * SyntaxError and the Ecommerce chart, toolbar and tabs never initialised; php -l and
 * every PHP suite stayed green. `node --check` parses without running.
 *
 * node is required, not optional: GitHub's ubuntu runners ship it, and a skipped
 * parse check reads exactly like a passing one.
 */
$root  = dirname(__DIR__);
$node  = trim((string) shell_exec('command -v node 2>/dev/null'));
if ('' === $node) {
    fwrite(STDERR, "FAIL: node is not on PATH; admin JS syntax was not checked\n");
    exit(1);
}

$files = new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/admin/assets/js')), '~(?<!\.min)\.js$~');
$failed = [];
$count  = 0;
foreach ($files as $file) {
    $count++;
    exec(escapeshellarg($node) . ' --check ' . escapeshellarg((string) $file) . ' 2>&1', $output, $status);
    if (0 !== $status) {
        $failed[] = substr((string) $file, strlen($root) + 1) . "\n  " . implode("\n  ", array_slice($output, 0, 4));
    }
    $output = [];
}

if ($count < 5) {
    fwrite(STDERR, "FAIL: only {$count} admin scripts found; the file pattern no longer matches\n");
    exit(1);
}
if ($failed) {
    fwrite(STDERR, "FAIL: admin scripts that do not parse:\n" . implode("\n", $failed) . "\n");
    exit(1);
}
echo "PASS: {$count} admin scripts parse\n";
