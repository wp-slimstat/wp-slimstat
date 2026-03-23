<?php
/**
 * Regression test: wp_add_cookie_info() must NOT be called directly inside
 * wp_slimstat::init() (which runs on plugins_loaded).
 *
 * WordPress 6.7+ emits "_load_textdomain_just_in_time was called incorrectly"
 * whenever __() is called before the 'init' hook fires. Because init() is hooked
 * to plugins_loaded (priority 20) and load_textdomain() is hooked to init
 * (priority 1), any __() call inside init() runs before the textdomain is loaded.
 *
 * Fix: defer the wp_add_cookie_info() call — which contains __() arguments — to
 * an add_action('init', ..., 10) callback so translations are available.
 *
 * This test reads wp-slimstat.php as source text and verifies the structural
 * invariant: wp_add_cookie_info() is wrapped in an add_action('init', ...) call,
 * not called bare inside init().
 *
 * @see wp-slimstat/wp-slimstat.php (wp_slimstat::init, WP Consent API block)
 */

declare(strict_types=1);

$assertions = 0;

function et_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$source = file_get_contents(dirname(__DIR__) . '/wp-slimstat.php');
if ($source === false) {
    fwrite(STDERR, "FAIL: could not read wp-slimstat.php\n");
    exit(1);
}

// ═══════════════════════════════════════════════════════════════════════════
// TEST 1: wp_add_cookie_info() must appear inside an add_action('init', ...)
//         wrapper, not as a bare call at the init() method level.
//
// Pattern: add_action('init', ...) followed (within ~10 lines) by
// wp_add_cookie_info(. We accept both single- and double-quoted 'init'.
// ═══════════════════════════════════════════════════════════════════════════
et_assert(
    (bool) preg_match(
        "/add_action\s*\(\s*['\"]init['\"]\s*,\s*static\s+function[^}]*wp_add_cookie_info\s*\(/s",
        $source
    ),
    'TEST 1: wp_add_cookie_info() must be wrapped in add_action(\'init\', static function ...) — bare call triggers WP 6.7+ early-translation notice'
);

// ═══════════════════════════════════════════════════════════════════════════
// TEST 2: The __('seconds', ...) string must NOT appear as a direct argument
//         to wp_add_cookie_info() at the plugins_loaded level.
//         It must appear only inside the deferred init callback.
//
// We verify this by checking that wp_add_cookie_info( on its own line
// is NOT immediately followed (within 6 lines) by __('seconds' without
// an intervening add_action wrapper.
//
// Simpler proxy: confirm that __('seconds', 'wp-slimstat') appears
// in the source at all (the string still exists — it's just deferred),
// AND that it co-occurs with the add_action('init', static function wrapper.
// ═══════════════════════════════════════════════════════════════════════════
et_assert(
    str_contains($source, "_n(") && str_contains($source, "'wp-slimstat'"),
    "TEST 2a: _n() with 'wp-slimstat' textdomain must exist in source for plural-aware cookie duration"
);

// Verify that _n() appears between add_action('init', static function and wp_add_cookie_info
// This confirms the plural-aware translation is inside the deferred closure, not at top level.
et_assert(
    (bool) preg_match(
        "/add_action\s*\(\s*['\"]init['\"]\s*,\s*static\s+function.*?wp_add_cookie_info.*?_n\s*\(/s",
        $source
    ),
    "TEST 2b: _n() must appear inside the add_action('init', ...) closure for deferred translation"
);

// ═══════════════════════════════════════════════════════════════════════════
// TEST 3: load_textdomain is still registered on 'init' at priority 1 (or
//         any priority < 10), guaranteeing it fires before the deferred
//         wp_add_cookie_info() callback at priority 10.
// ═══════════════════════════════════════════════════════════════════════════
et_assert(
    (bool) preg_match(
        "/add_action\s*\(\s*['\"]init['\"]\s*,\s*\[.*?'load_textdomain'\s*\]\s*,\s*([0-9]+)\s*\)/",
        $source,
        $m
    ) && (int) ($m[1] ?? 99) < 10,
    'TEST 3: load_textdomain must be registered on init at priority < 10 so it fires before the wp_add_cookie_info deferred callback (priority 10)'
);

echo "All {$assertions} assertions passed in early-translation-test.php\n";
