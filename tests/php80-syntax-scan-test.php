<?php
/**
 * Source-level: own code uses no PHP 8.0+ language syntax and no 8.0-removed
 * functions, so it parses and runs on the declared 7.4 floor.
 *
 * BASELINE (zero findings expected). Complements php74-no-php80-functions-test.php
 * (which scans 8.0+ *stdlib calls*); this one scans 8.0+ *language constructs*
 * and 8.0-*removed* functions.
 *
 * Scope: wp-slimstat.php, admin/, src/ — excludes src/Dependencies/.
 * Allow-marker: /​* php80-syntax: ok *​/ on the comment line above a site.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root  = dirname(__DIR__);
$deps_prefix  = $plugin_root . '/src/Dependencies';
$allow_marker = 'php80-syntax: ok';
$paths        = [$plugin_root . '/wp-slimstat.php', $plugin_root . '/admin', $plugin_root . '/src'];

// The library's walk, not a tenth private copy of it. slimstat_own_php_files() exists because
// eight source-level tests carried a byte-identical version of this loop and the copies had
// already drifted — one had lost its sort(), so its failure output came back in filesystem
// order and was irreproducible between machines. This file is only in scope to use the library
// at all because of the comment-blindness fixed below; importing it to close one hazard while
// walking past the second one it was written to remove would be the smaller half of the job.
$files = slimstat_own_php_files($paths, $deps_prefix);
if (0 === count($files)) { fwrite(STDERR, "FAIL: scanner found zero own-code .php files\n"); exit(1); }

$patterns = [
    'match expression (8.0)'        => '/(?<![:\w>$])match\s*\(/',
    'enum declaration (8.1)'        => '/^\s*enum\s+[A-Z]\w*/m',
    'readonly property (8.1)'       => '/\breadonly\s+(?:public|protected|private|static|\$)|(?:public|protected|private)\s+readonly\b/',
    'nullsafe operator ?-> (8.0)'   => '/\?->/',
    'each() removed in 8.0'         => '/(?<![:\w>$])each\s*\(/',
    'create_function() removed 8.0' => '/\bcreate_function\s*\(/',
    'libxml_disable_entity_loader removed 8.0' => '/\blibxml_disable_entity_loader\s*\(/',
];

$violations = [];
foreach ($files as $file) {
    $contents = file_get_contents($file);
    if (false === $contents) continue;

    // MATCHED ON THE STRIPPED SOURCE, not the raw bytes. Every pattern above names a
    // CONSTRUCT, and each of those names is also an ordinary English word or a plausible
    // string literal — so scanning raw text asks "does this word appear" when the question
    // is "is this construct used".
    //
    // Not hypothetical: this gate failed a commit because a code comment contained the
    // phrase "the gate resolves each (table, column) pair", which `/each\s*\(/` reported as
    // PHP's removed each(). That is the name-not-construct hazard the tokeniser rewrite
    // exists to remove, in a scanner that had never been routed through it — and no gate
    // could see the omission, because source-scan-strength-test.php only inspects tests that
    // already require the library. Closed there in the same change.
    //
    // Byte length is preserved by the strip, so $offset still indexes into $contents and the
    // allow-marker lookup below — which reads a COMMENT, and must therefore read the raw
    // text — stays correct.
    $scannable = slimstat_strip_comments_and_strings($contents, false);

    foreach ($patterns as $label => $pattern) {
        if (!preg_match_all($pattern, $scannable, $matches, PREG_OFFSET_CAPTURE)) continue;
        foreach ($matches[0] as [$match, $offset]) {
            $line_no = substr_count($contents, "\n", 0, $offset) + 1;
            $prev    = $line_no > 1 ? explode("\n", substr($contents, 0, $offset))[$line_no - 2] : '';
            if (false !== strpos($prev, $allow_marker)) continue;
            $rel = substr($file, strlen($plugin_root) + 1);
            $violations[] = sprintf('%s:%d  [%s]  → %s', $rel, $line_no, $label, trim($match));
        }
    }
}

if ($violations) {
    fwrite(STDERR, "FAIL: PHP 8.0+ syntax / 8.0-removed function in own code (breaks the 7.4 floor):\n");
    foreach ($violations as $v) fwrite(STDERR, "  - {$v}\n");
    exit(1);
}
echo "OK: scanned " . count($files) . " own-code files, no PHP 8.0+ syntax / removed functions\n";
