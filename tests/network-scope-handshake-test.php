<?php
/**
 * Source-level: free's half of the network-scope handshake (X1).
 *
 * Pro's Network View UNIONs every subsite's slim_stats into one report. It used to
 * decide that from `$_SERVER['HTTP_REFERER']` — a header the client sets — so any
 * subsite Administrator could read every other tenant's visitor IPs, usernames and
 * e-mail addresses over admin-ajax.php. The authorization now lives in Pro
 * (`WpSlimstatPro\Support\NetworkScope`, which requires `manage_network_options`).
 *
 * Free's half is the *selection* signal: admin-ajax.php carries no screen context,
 * so the network report screen has to say which scope it wants. Two properties
 * have to hold, and neither is visible from Pro:
 *
 *   1. The nonce is minted only for a super admin on a network screen. It is not
 *      the gate — Pro re-checks the capability — but a nonce minted for everyone
 *      is a gate that has quietly become decorative, and the next person to read
 *      this code would reasonably assume it still means something.
 *   2. The parameter names and the nonce action match what Pro reads. Free and Pro
 *      ship as separate plugins on separate release trains; if either side renames
 *      a string, network view degrades silently to main-site data with the label
 *      still saying "network". Silent wrong numbers are worse than an error.
 *
 * Asserts constructs, not vocabulary: the capability literal must sit inside the
 * conditional that guards the mint, not merely somewhere in the file.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);

/**
 * Must match WpSlimstatPro\Support\NetworkScope — and, for the capability, free's
 * own stats_view_capability() network branch, which gates the menu this feature
 * lives behind.
 */
const HANDSHAKE_CAPABILITY   = 'manage_network';
const HANDSHAKE_NONCE_ACTION = 'slimstat_network_scope';
const HANDSHAKE_SCOPE_PARAM  = 'slimstat_network_scope';
const HANDSHAKE_NONCE_PARAM  = 'slimstat_network_nonce';
const HANDSHAKE_JS_KEY       = 'network_scope_nonce';

$failures = [];

// ─── 1) PHP: the mint is gated on multisite + network screen + capability ────
$admin = (string) @file_get_contents($plugin_root . '/admin/index.php');
if ('' === $admin) {
    fwrite(STDERR, "FAIL: cannot read admin/index.php\n");
    exit(1);
}

$tokens = token_get_all($admin);
$count  = count($tokens);

$mint_index = null;
for ($i = 0; $i < $count; $i++) {
    if (!is_array($tokens[$i]) || T_CONSTANT_ENCAPSED_STRING !== $tokens[$i][0]) {
        continue;
    }
    if (trim($tokens[$i][1], "'\"") === HANDSHAKE_JS_KEY) {
        $mint_index = $i;
        break;
    }
}

if (null === $mint_index) {
    $failures[] = sprintf(
        "admin/index.php never localizes '%s'. Without it the network report screen cannot ask for "
            . 'network scope, and Pro falls back to main-site data while still labelling it network-wide',
        HANDSHAKE_JS_KEY
    );
} else {
    // The value expression runs from the `=>` to the end of that array element.
    // Bounded by the next top-level `,` at the array's own depth so a nested
    // ternary or function call cannot terminate it early.
    $depth = 0;
    $end   = $count;
    for ($k = $mint_index; $k < $count; $k++) {
        $t = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
        if ('(' === $t || '[' === $t) {
            $depth++;
        } elseif (')' === $t || ']' === $t) {
            if ($depth <= 0) {
                $end = $k;
                break;
            }
            $depth--;
        } elseif (',' === $t && 0 === $depth) {
            $end = $k;
            break;
        }
    }

    // The two calls carrying a literal are asserted through that literal, not
    // through their mere presence — a missing wp_create_nonce() then fails on the
    // action message rather than needing a second, weaker check of its own.
    $required      = ['is_multisite', 'is_network_admin'];
    $seen          = [];
    $capability_ok = false;
    $action_ok     = false;

    /** The literal each call must carry, inside its own argument span. */
    $literals = [
        'current_user_can' => HANDSHAKE_CAPABILITY,
        'wp_create_nonce'  => HANDSHAKE_NONCE_ACTION,
    ];

    for ($k = $mint_index; $k < $end; $k++) {
        if (!is_array($tokens[$k]) || T_STRING !== $tokens[$k][0]) {
            continue;
        }
        $name = $tokens[$k][1];

        if (in_array($name, $required, true)) {
            $seen[$name] = true;
            continue;
        }
        if (!isset($literals[$name])) {
            continue;
        }

        $args_end = slimstat_token_paren_end($tokens, $k, $end);
        if (null === $args_end) {
            continue;
        }
        for ($a = $k; $a < $args_end; $a++) {
            if (is_array($tokens[$a]) && T_CONSTANT_ENCAPSED_STRING === $tokens[$a][0]
                && trim($tokens[$a][1], "'\"") === $literals[$name]) {
                if ('current_user_can' === $name) {
                    $capability_ok = true;
                } else {
                    $action_ok = true;
                }
            }
        }
    }

    foreach ($required as $name) {
        if (!isset($seen[$name])) {
            $failures[] = sprintf(
                "the '%s' value must call %s() — minting it outside that guard hands a network-scope "
                    . 'token to users who have no business holding one',
                HANDSHAKE_JS_KEY,
                $name
            );
        }
    }
    if (!$capability_ok) {
        $failures[] = sprintf(
            "the nonce mint must gate on current_user_can('%s') — the same capability Pro verifies "
                . 'and the same one stats_view_capability() returns on a network screen. Naming a '
                . 'different one degrades network view to main-site data with no error anywhere',
            HANDSHAKE_CAPABILITY
        );
    }
    if (!$action_ok) {
        $failures[] = sprintf(
            "the nonce must be created for the action '%s' — Pro verifies that exact string",
            HANDSHAKE_NONCE_ACTION
        );
    }
}

// ─── 2) JS: the report request carries both halves, under the same names ─────
$js = (string) @file_get_contents($plugin_root . '/admin/assets/js/admin.js');
if ('' === $js) {
    $failures[] = 'cannot read admin/assets/js/admin.js';
} else {
    // Strip comments so the prose explaining the handshake cannot satisfy the scan.
    $js_code = preg_replace('#/\*.*?\*/#s', '', $js);
    $js_code = preg_replace('#(^|[^:])//.*$#m', '$1', (string) $js_code);
    $js_code = (string) $js_code;

    $js_constructs = [
        '/\bdata\.' . preg_quote(HANDSHAKE_SCOPE_PARAM, '/') . '\s*=/'
            => sprintf("admin.js must send data.%s on the report request", HANDSHAKE_SCOPE_PARAM),
        '/\bdata\.' . preg_quote(HANDSHAKE_NONCE_PARAM, '/') . '\s*=/'
            => sprintf("admin.js must send data.%s on the report request", HANDSHAKE_NONCE_PARAM),
        '/SlimStatAdminParams\.' . preg_quote(HANDSHAKE_JS_KEY, '/') . '\b/'
            => sprintf('admin.js must read the minted nonce from SlimStatAdminParams.%s', HANDSHAKE_JS_KEY),
    ];

    foreach ($js_constructs as $pattern => $message) {
        if (!preg_match($pattern, $js_code)) {
            $failures[] = $message;
        }
    }

    // The send must be conditional on the nonce existing. An unconditional send
    // puts an empty nonce on every single-site request, where Pro's
    // check_ajax_referer() then fails — turning a silent degrade into a broken
    // report on installs that have no network at all.
    if (!preg_match('/if\s*\(\s*SlimStatAdminParams\.' . preg_quote(HANDSHAKE_JS_KEY, '/') . '\s*\)/', $js_code)) {
        $failures[] = sprintf(
            'the scope parameters must be sent only when SlimStatAdminParams.%s is non-empty',
            HANDSHAKE_JS_KEY
        );
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: network-scope handshake (' . count($failures) . " problem(s))\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: network-scope nonce is capability-gated and the free/Pro parameter names agree\n");
exit(0);
