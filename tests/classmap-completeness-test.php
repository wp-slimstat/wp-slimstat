<?php
/**
 * Source-level regression: every first-party SlimStat class declared under src/
 * is present in the committed Composer classmap.
 *
 * Why (issue #325): the wp.org release ships the committed
 * vendor/composer/autoload_classmap.php. If a class under src/ is declared but
 * missing from that classmap while the loader is classmap-authoritative (no PSR-4
 * filesystem fallback), every request that references the class fatals with
 * "Class not found" — a site-wide WSOD + admin lockout. This gate fails the build
 * before such a stale classmap can ship.
 *
 * It also asserts the shipped loader is NOT classmap-authoritative, so the PSR-4
 * filesystem fallback stays in place and a missing entry degrades instead of fatals.
 *
 * 7.4-safe: pure file parsing. Requires ONLY the classmap array (never the
 * autoloader, so no classes load). Uses token_get_all() as a lexer (no
 * TOKEN_PARSE, so files containing 8.1 `enum` never ParseError on 7.4) and
 * detects `enum` by token text (never the 8.1-only T_ENUM constant).
 */

declare(strict_types=1);

$plugin_root   = dirname(__DIR__);
$src_dir       = $plugin_root . '/src';
$deps_prefix   = $src_dir . '/Dependencies';
$classmap_file = $plugin_root . '/vendor/composer/autoload_classmap.php';

if (!is_file($classmap_file)) {
    fwrite(STDERR, "FAIL: classmap not found at vendor/composer/autoload_classmap.php\n");
    exit(1);
}

// `require` returns the array WITHOUT registering the autoloader or loading classes.
$classmap = require $classmap_file;
if (!is_array($classmap)) {
    fwrite(STDERR, "FAIL: autoload_classmap.php did not return an array\n");
    exit(1);
}

// --- guard: the shipped loader must NOT be classmap-authoritative ---
// Authoritative mode disables the PSR-4 filesystem fallback, so a single missing
// classmap entry becomes a fatal on every request (issue #325). Keep the fallback.
$autoload_real = $plugin_root . '/vendor/composer/autoload_real.php';
$real_src      = is_file($autoload_real) ? (string) file_get_contents($autoload_real) : '';
if (strpos($real_src, 'setClassMapAuthoritative(true)') !== false) {
    fwrite(STDERR, "FAIL: vendor/composer/autoload_real.php enables setClassMapAuthoritative(true).\n");
    fwrite(STDERR, "That removes the PSR-4 filesystem fallback and makes any missing class a site-wide fatal (issue #325).\n");
    fwrite(STDERR, "Fix: build the autoloader with `composer run build:autoload` (uses -o, non-authoritative) and commit.\n");
    exit(1);
}

// --- walk src/, pruning src/Dependencies/ (third-party, Mozart-scoped) ---
$files = [];
if (is_dir($src_dir)) {
    $directory = new RecursiveDirectoryIterator($src_dir, RecursiveDirectoryIterator::SKIP_DOTS);
    $filtered  = new RecursiveCallbackFilterIterator($directory, function ($file) use ($deps_prefix) {
        return strpos($file->getPathname(), $deps_prefix . DIRECTORY_SEPARATOR) !== 0;
    });
    foreach (new RecursiveIteratorIterator($filtered) as $file) {
        if (substr($file->getPathname(), -4) === '.php') {
            $files[] = $file->getPathname();
        }
    }
}
sort($files);

// --- extract declared class-likes (FQCN => relative path) via the lexer ---
$declared = [];

foreach ($files as $file) {
    $contents = file_get_contents($file);
    if ($contents === false) {
        continue;
    }
    $tokens    = token_get_all($contents); // lexer only, never TOKEN_PARSE
    $count     = count($tokens);
    $namespace = '';

    for ($i = 0; $i < $count; $i++) {
        $tok = $tokens[$i];
        if (!is_array($tok)) {
            continue;
        }

        // namespace declaration → remember the current namespace
        if ($tok[0] === T_NAMESPACE) {
            $ns = '';
            for ($j = $i + 1; $j < $count; $j++) {
                $t = $tokens[$j];
                if ($t === ';' || $t === '{') {
                    break;
                }
                if (is_array($t) && $t[0] !== T_WHITESPACE) {
                    $ns .= $t[1];
                }
            }
            $namespace = $ns;
            continue;
        }

        // class-like declaration. class/interface/trait/enum are reserved words,
        // so a token whose text is one of them is always a declaration keyword
        // (enum lexes as T_ENUM on 8.1 and as T_STRING 'enum' on 7.4).
        if (!in_array(strtolower($tok[1]), ['class', 'interface', 'trait', 'enum'], true)) {
            continue;
        }

        // skip `Foo::class`
        $prev = $i > 0 ? $tokens[$i - 1] : null;
        if (is_array($prev) && $prev[0] === T_DOUBLE_COLON) {
            continue;
        }

        // the class name is the next T_STRING; anything else (e.g. `new class`,
        // `enum(` call) means this is not a named declaration → skip.
        $name = null;
        for ($j = $i + 1; $j < $count; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && $t[0] === T_WHITESPACE) {
                continue;
            }
            if (is_array($t) && $t[0] === T_STRING) {
                $name = $t[1];
            }
            break;
        }
        if ($name === null) {
            continue;
        }

        $fqcn            = $namespace !== '' ? $namespace . '\\' . $name : $name;
        $declared[$fqcn] = substr($file, strlen($plugin_root) + 1);
    }
}

// --- assert every first-party SlimStat class is classmapped ---
// src/Dependencies is already excluded by the file walk, so any remaining
// SlimStat\ class is first-party.
$missing = [];
$checked = 0;
foreach ($declared as $fqcn => $rel) {
    if (strpos($fqcn, 'SlimStat\\') !== 0) {
        continue; // only our own namespace
    }
    $checked++;
    if (!array_key_exists($fqcn, $classmap)) {
        $missing[] = sprintf('%s  (declared in %s)', $fqcn, $rel);
    }
}

if ($missing) {
    fwrite(STDERR, "FAIL: SlimStat classes declared under src/ but missing from the committed classmap:\n");
    foreach ($missing as $m) {
        fwrite(STDERR, "  - {$m}\n");
    }
    fwrite(STDERR, "\nWith a classmap-authoritative loader this is a fatal 'Class not found' on every request (issue #325).\n");
    fwrite(STDERR, "Fix: regenerate the classmap with `composer run build:autoload` and commit vendor/composer/autoload_*.php.\n");
    exit(1);
}

echo "OK: {$checked} declared SlimStat classes are all present in the committed classmap\n";
