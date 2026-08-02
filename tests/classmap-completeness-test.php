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

/**
 * Read a repo file as it is COMMITTED (what ships to wp.org via SVN), not the
 * working-tree copy. CI restores a cached vendor/ over the checkout, so the
 * working-tree autoload_* files can be stale/sparse at test time; the committed
 * blob is what actually ships and is what this gate must validate. Falls back to
 * the working-tree file when git is unavailable (e.g. an exported tarball).
 */
function slimstat_committed_source(string $root, string $rel): string
{
    // Presence of .git — a directory normally, a FILE when the repo is a submodule
    // or a linked worktree — is what decides whether a committed blob even exists to
    // read. If it does and we cannot read it, this gate is broken and must say so;
    // silently validating the working tree instead would let a bad artifact ship
    // while still printing OK. The working-tree fallback is legitimate only for a
    // source export (wp.org SVN, `git archive`), which carries no .git at all.
    $has_repo = file_exists($root . '/.git');

    $bail = static function (string $why) use ($rel, $has_repo) {
        if (!$has_repo) {
            return;
        }
        fwrite(STDERR, "FAIL: cannot read the committed blob for {$rel} ({$why}).\n");
        fwrite(STDERR, "That blob is what ships to wp.org — refusing to validate the working tree instead,\n");
        fwrite(STDERR, "because doing so would pass this gate without checking what actually gets released.\n");
        exit(1);
    };

    // Disabled via disable_functions on hardened hosts. Checking rather than calling
    // keeps this a clear failure instead of an "undefined function" fatal on PHP 8.
    if (!function_exists('shell_exec')) {
        $bail('shell_exec is disabled');
    } else {
        // The INDEX first, then HEAD. This gate ran green immediately before the commit that
        // shipped a classmap with 961 dev classes and zero SlimStat ones — because reading
        // `HEAD:` validates the PREVIOUS commit, so the blob about to be committed was the one
        // blob it never looked at. Locally the index is what is about to ship; in CI, with
        // nothing staged, `git show :path` and `git show HEAD:path` are the same bytes, so this
        // costs that lane nothing.
        foreach ([':' . $rel, 'HEAD:' . $rel] as $rev) {
            $out = @shell_exec('git -C ' . escapeshellarg($root) . ' show ' . escapeshellarg($rev) . ' 2>/dev/null');
            if (is_string($out) && '' !== $out) {
                return $out;
            }
        }

        $bail('the git binary is unavailable, or the path is neither staged nor committed');
    }

    $path = $root . '/' . $rel;
    return is_file($path) ? (string) file_get_contents($path) : '';
}

$plugin_root = dirname(__DIR__);
$src_dir     = $plugin_root . '/src';
$deps_prefix = $src_dir . '/Dependencies';

// --- load the committed classmap array (FQCN => absolute path; both are used) ---
$classmap_src = slimstat_committed_source($plugin_root, 'vendor/composer/autoload_classmap.php');
if ('' === $classmap_src) {
    fwrite(STDERR, "FAIL: could not read the committed vendor/composer/autoload_classmap.php\n");
    exit(1);
}
// Write the copy INSIDE vendor/composer/ — the classmap header computes
// `$baseDir = dirname(dirname(__DIR__))`, so anywhere else (a system temp dir)
// resolves every path to garbage and the returned values become unusable.
$classmap_tmp = $plugin_root . '/vendor/composer/.slimstat-classmap-test.php';
file_put_contents($classmap_tmp, $classmap_src);

// The copy lands inside the repo, so a fatal in the require below (a malformed
// committed blob — exactly what this gate exists to catch) must not leave it behind
// for someone to commit by accident.
register_shutdown_function(static function () use ($classmap_tmp) {
    if (is_file($classmap_tmp)) {
        @unlink($classmap_tmp);
    }
});

$classmap = require $classmap_tmp; // returns the array; defines no classes
@unlink($classmap_tmp);
if (!is_array($classmap)) {
    fwrite(STDERR, "FAIL: autoload_classmap.php did not return an array\n");
    exit(1);
}

// --- guard: the committed loader must NOT be classmap-authoritative ---
// Authoritative mode disables the PSR-4 filesystem fallback, so a single missing
// classmap entry becomes a fatal on every request (issue #325). Keep the fallback.
$real_src = slimstat_committed_source($plugin_root, 'vendor/composer/autoload_real.php');
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
    if (false === $contents) {
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
        if (T_NAMESPACE === $tok[0]) {
            $ns = '';
            for ($j = $i + 1; $j < $count; $j++) {
                $t = $tokens[$j];
                if (';' === $t || '{' === $t) {
                    break;
                }
                if (is_array($t) && T_WHITESPACE !== $t[0]) {
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
        if (is_array($prev) && T_DOUBLE_COLON === $prev[0]) {
            continue;
        }

        // the class name is the next T_STRING; anything else (e.g. `new class`,
        // `enum(` call) means this is not a named declaration → skip.
        $name = null;
        for ($j = $i + 1; $j < $count; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && T_WHITESPACE === $t[0]) {
                continue;
            }
            if (is_array($t) && T_STRING === $t[0]) {
                $name = $t[1];
            }
            break;
        }
        if (null === $name) {
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

// --- reverse pass: every first-party classmap entry must point at a real file ---
// The PSR-4 fallback does NOT rescue this: ClassLoader::findFile() returns the
// classmap hit before it ever consults the filesystem prefixes, so a stale entry
// left behind by a moved/renamed file is an include() warning and an undefined
// class — even with authoritative mode off.
$dangling = [];
foreach ($classmap as $fqcn => $path) {
    if (strpos($fqcn, 'SlimStat\\') !== 0 || strpos($fqcn, 'SlimStat\\Dependencies\\') === 0) {
        continue;
    }
    if (!is_file($path)) {
        $dangling[] = sprintf('%s  ->  %s', $fqcn, $path);
    }
}

if ($dangling) {
    fwrite(STDERR, "FAIL: committed classmap entries pointing at files that do not exist:\n");
    foreach ($dangling as $d) {
        fwrite(STDERR, "  - {$d}\n");
    }
    fwrite(STDERR, "\nThe classmap short-circuits before PSR-4, so these fatal even with the fallback in place.\n");
    fwrite(STDERR, "Fix: regenerate with `composer run build:autoload` and commit vendor/composer/autoload_*.php.\n");
    exit(1);
}

// --- guard: the committed loader must not eagerly require a dev `files` list ---
// A dev-flavoured dump adds an autoload_files.php + $filesToLoad loop that requires
// packages excluded from the wp.org package by .distignore — that combination has
// shipped and fatalled more than once.
if (strpos($real_src, '$filesToLoad') !== false || strpos($real_src, 'autoload_files.php') !== false) {
    fwrite(STDERR, "FAIL: vendor/composer/autoload_real.php eagerly loads a `files` list.\n");
    fwrite(STDERR, "That is a dev-flavoured dump; it requires packages .distignore strips from the release.\n");
    fwrite(STDERR, "Fix: rebuild with `composer run build:autoload` (uses --no-dev) and commit.\n");
    exit(1);
}

// --- the committed classmap must carry NO development classes ---
//
// `composer dump-autoload` (what you run so PHPUnit can load) writes the dev classmap;
// `composer run build:autoload` writes the shipped one. Staging after the former puts ~961
// Mockery / PHPUnit / Hamcrest / PhpParser entries into the file that goes to wp.org, parsed on
// every WordPress request, referencing paths that `.distignore` excludes from the ZIP — so the
// entries are not merely wasteful, they point at files that will not be there. That is the
// shape of #325.
//
// This is mechanised because warning about it did not work. It is entry 2 in PITFALLS.md,
// `/simplify` caught it twice, and it still landed in a commit on this branch — found only
// because an unrelated seam happened to rebuild the autoloader and read the diff. A gate that
// fails is worth more than a paragraph that warns.
$dev_namespaces = ['Mockery', 'PHPUnit', 'Hamcrest', 'PhpParser', 'Prophecy', 'SebastianBergmann', 'DeepCopy', 'Brain'];
$dev_found      = [];

foreach ($classmap as $class => $path) {
    foreach ($dev_namespaces as $ns) {
        if (0 === strpos($class, $ns . '\\') || $class === $ns) {
            $dev_found[$ns] = ($dev_found[$ns] ?? 0) + 1;
            break;
        }
    }
}

if ($dev_found !== []) {
    $summary = [];
    foreach ($dev_found as $ns => $count) {
        $summary[] = "{$ns} ({$count})";
    }

    fwrite(STDERR, "FAIL: the committed classmap contains development classes: " . implode(', ', $summary) . "\n");
    fwrite(STDERR, "This is `composer dump-autoload` output, not `composer run build:autoload` output.\n");
    fwrite(STDERR, "It ships to wp.org, is parsed on every request, and references paths .distignore excludes.\n");
    fwrite(STDERR, "Fix: composer run build:autoload  (never `dump-autoload -a`), then re-stage vendor/composer/.\n");
    exit(1);
}

// --- self-check: this gate must FAIL, not pass, when it cannot see the blob ---
// The worst failure mode for a build gate is a green run that checked nothing. Re-run
// ourselves with git removed from PATH and require a non-zero exit. The child sets the
// env guard so it does not recurse.
if (false === getenv('SLIMSTAT_CLASSMAP_SELFCHECK') && $has_repo_for_selfcheck = file_exists($plugin_root . '/.git')) {
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $child       = proc_open(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__),
        $descriptors,
        $pipes,
        null,
        ['SLIMSTAT_CLASSMAP_SELFCHECK' => '1', 'PATH' => '/nonexistent'] + $_ENV
    );

    if (is_resource($child)) {
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (0 === proc_close($child)) {
            fwrite(STDERR, "FAIL: with git unavailable this gate still exited 0.\n");
            fwrite(STDERR, "It would validate the working tree instead of the committed blob and report success —\n");
            fwrite(STDERR, "a green run that checked nothing. slimstat_committed_source() must bail out instead.\n");
            exit(1);
        }
    }
}

echo "OK: {$checked} declared SlimStat classes are all present in the committed classmap, "
    . "all first-party entries resolve, loader is non-authoritative with no eager file list "
    . "(and the gate fails closed when the blob is unreadable)\n";
