<?php
/**
 * Source-level: the rehearsal harness resolves a VINTAGE arm to the bytes wordpress.org served.
 *
 * WHY THIS EXISTS. Cells 7a-7d rehearse the upgrade FROM the versions 70k live sites actually
 * run — 4.8.1, 5.1.5, 5.2.13, 5.4.12 — and those arms cannot be built from git: `build-free.sh`
 * hard-requires `.distignore` at the ref, which the 4.8 line predates by years. So `use_ref` grew
 * a second input, `wp.org:<version>`, and the harness now installs bytes it did not produce.
 *
 * That is the whole reason for this file. A ZIP fetched over the network is trusted input, and
 * the ways it goes wrong are all quiet: a truncated download, a proxy's error page saved under
 * the right name, a re-rolled release, a version typo that resolves to a different vintage. Any
 * of them yields a cell that boots, migrates, asserts and reports PASS — about code no site is
 * running. `resolve_arm_zip` refuses each case; this drives it and watches it refuse.
 *
 * WHAT IT PINS, and why each case is here rather than assumed:
 *
 *   unpinned      A version absent from `arms.sha256` is refused BEFORE the download, not after.
 *                 The other order is the one that rots: fetch first, "add the hash later", and
 *                 the pin becomes a record of what was downloaded rather than a decision about
 *                 what may be.
 *   tampered      A present ZIP whose digest disagrees is refused. This is the substitution case
 *                 and the only one a human would never notice — the file is there, the cell runs.
 *   empty stdout  The function's return VALUE is its stdout, and `err` in this harness writes to
 *                 stdout. Every refusal must therefore print NOTHING on stdout, or a caller
 *                 doing `zip=$(resolve_arm_zip ...)` receives an error message shaped like a path
 *                 and reports a missing file rather than a refused one. This case was RED when
 *                 first run — the redirections it asks for did not exist. PITFALLS 130.
 *   exit 2        A git ref is not an error, it is "build it as you always did". Collapsing 2
 *                 into 1 would make every existing cell fail; collapsing it into 0 would hand
 *                 the installer an empty path.
 *   traversal     `wp.org:../../etc/passwd` is a version string only in the sense that nothing
 *                 had looked at it. Validated before it reaches a path or a URL.
 *   floor         The manifest must still name the five vintages the cells need. Deleting a line
 *                 is otherwise a silent way to make a cell unrunnable months from now.
 *
 * The bash is driven as a SUBPROCESS against a fixture manifest and a `file://` origin, so the
 * test needs no network, no docker and no database, and can guard every commit.
 *
 * 7.4-safe: bare PHP, no WordPress, no vendor autoloader.
 */

declare(strict_types=1);

$plugin_root = dirname(__DIR__);
$lib         = $plugin_root . '/tests/docker/lib.sh';
$manifest    = $plugin_root . '/tests/docker/arms.sha256';
$rehearse    = $plugin_root . '/tests/docker/rehearse-upgrade.sh';
$failures    = [];
$checks      = 0;

/** Run one resolve_arm_zip() call in a fixture world. Returns [rc, stdout, stderr]. */
function arm_resolve(string $lib, string $fixture, string $ref): array
{
    $script = "set -uo pipefail\n"
        . 'export ARMS_DIR=' . escapeshellarg($fixture . '/arms') . "\n"
        . 'export ARMS_MANIFEST=' . escapeshellarg($fixture . '/manifest') . "\n"
        . 'export ARMS_BASE_URL=' . escapeshellarg('file://' . $fixture . '/web') . "\n"
        . '. ' . escapeshellarg($lib) . "\n"
        . 'resolve_arm_zip ' . escapeshellarg($ref) . "\n";

    $proc = proc_open(
        'bash -s',
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($proc)) {
        return [-1, '', 'could not start bash'];
    }
    fwrite($pipes[0], $script);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($proc), trim($out), trim($err)];
}

/** A throwaway world: an arms dir, a wp.org stand-in, and a manifest pinning 4.8.1. */
function arm_fixture(): array
{
    $dir = sys_get_temp_dir() . '/slimstat-arm-' . bin2hex(random_bytes(6));
    mkdir($dir . '/arms', 0777, true);
    mkdir($dir . '/web', 0777, true);
    file_put_contents($dir . '/web/wp-slimstat.4.8.1.zip', 'PK-pretend-this-is-4.8.1');
    // The origin also serves 5.5.0, which the manifest deliberately does NOT pin. Without this
    // file the "refused before it is fetched" case passes for the wrong reason — the download
    // fails because the fixture has nothing to serve, and a resolver that fetched first would
    // still look correct. The origin must be able to satisfy the request the pin refuses.
    file_put_contents($dir . '/web/wp-slimstat.5.5.0.zip', 'PK-pretend-this-is-5.5.0');
    $sha = hash_file('sha256', $dir . '/web/wp-slimstat.4.8.1.zip');
    file_put_contents($dir . '/manifest', "# fixture\n" . $sha . '  wp-slimstat.4.8.1.zip' . "\n");

    return [$dir, $sha];
}

function arm_rmdir(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $path) {
        is_dir($path) ? arm_rmdir($path) : unlink($path);
    }
    @rmdir($dir);
}

$check = function (string $label, bool $ok, string $detail = '') use (&$failures, &$checks): void {
    $checks++;
    if ($ok) {
        echo '  [PASS] ' . $label . ($detail ? ' — ' . $detail : '') . "\n";
    } else {
        $failures[] = $label . ($detail ? ' — ' . $detail : '');
        echo '  [FAIL] ' . $label . ($detail ? ' — ' . $detail : '') . "\n";
    }
};

echo "REHEARSAL ARM RESOLUTION\n";

if (!is_file($lib)) {
    fwrite(STDERR, "tests/docker/lib.sh is missing — the harness this guards does not exist\n");
    exit(1);
}

// ── The refusals ────────────────────────────────────────────────────────────
[$fx, $sha_481] = arm_fixture();

// A: an unpinned version is refused, and is NOT downloaded first.
[$rc, $out, $err] = arm_resolve($lib, $fx, 'wp.org:5.5.0');
$check('an unpinned version is refused', 1 === $rc, 'exit ' . $rc);
$check('...before anything is fetched', !is_file($fx . '/arms/wp-slimstat.5.5.0.zip'));
$check('...and says which file to add the line to', false !== strpos($err, $fx . '/manifest'), trim($err));
$check('...printing nothing on stdout', '' === $out, $out);

// B: a pinned version is fetched from the origin and accepted.
[$rc, $out, $err] = arm_resolve($lib, $fx, 'wp.org:4.8.1');
$check('a pinned version is fetched and accepted', 0 === $rc, 'exit ' . $rc . ' ' . $err);
$check('...and the path it prints is the ZIP', $out === $fx . '/arms/wp-slimstat.4.8.1.zip', $out);
$check('...which now exists on disk', is_file($fx . '/arms/wp-slimstat.4.8.1.zip'));
$check(
    '...with the pinned digest',
    is_file($fx . '/arms/wp-slimstat.4.8.1.zip')
        && hash_file('sha256', $fx . '/arms/wp-slimstat.4.8.1.zip') === $sha_481
);

// C: the substitution case — present, right name, wrong bytes.
file_put_contents($fx . '/arms/wp-slimstat.4.8.1.zip', 'a different release entirely');
[$rc, $out, $err] = arm_resolve($lib, $fx, 'wp.org:4.8.1');
$check('a ZIP whose digest disagrees is refused', 1 === $rc, 'exit ' . $rc);
$check('...naming both digests', false !== strpos($err, 'expected') && false !== strpos($err, 'got'), trim($err));
$check('...printing nothing on stdout', '' === $out, $out);

// D: a git ref is not an error — it is the build path, and must be distinguishable.
foreach (['development', 'HEAD', 'b5844a45'] as $ref) {
    [$rc, $out] = arm_resolve($lib, $fx, $ref);
    $check('a git ref falls through to the build path (' . $ref . ')', 2 === $rc, 'exit ' . $rc);
    $check('...printing no path (' . $ref . ')', '' === $out, $out);
}

// E: a version string that is really a path.
foreach (['../../etc/passwd', '4.8.1/../../x', '', 'latest'] as $bad) {
    [$rc, $out] = arm_resolve($lib, $fx, 'wp.org:' . $bad);
    $check("a version that is not a version is refused ('" . $bad . "')", 1 === $rc, 'exit ' . $rc);
    $check("...printing nothing on stdout ('" . $bad . "')", '' === $out, $out);
}

// F: a bare .zip path — the escape hatch for an arm built by hand.
file_put_contents($fx . '/local-arm.zip', 'PK-local');
[$rc, $out] = arm_resolve($lib, $fx, $fx . '/local-arm.zip');
$check('an explicit .zip path resolves to itself', 0 === $rc && $out === $fx . '/local-arm.zip', $out);
[$rc, $out] = arm_resolve($lib, $fx, $fx . '/absent.zip');
$check('an explicit .zip that does not exist is refused', 1 === $rc, 'exit ' . $rc);
$check('...printing nothing on stdout', '' === $out, $out);

// G: arm_ref_version is the single definition of "which vintage did the ref name".
$ver_probe = function (string $ref) use ($lib, $fx): string {
    $script = '. ' . escapeshellarg($lib) . "\narm_ref_version " . escapeshellarg($ref) . "\n";
    $out    = [];
    $rc     = 0;
    exec('bash -c ' . escapeshellarg($script) . ' 2>/dev/null', $out, $rc);

    return trim(implode('', $out));
};
$check('arm_ref_version reads the vintage out of the ref', '4.8.1' === $ver_probe('wp.org:4.8.1'));
$check('...and is empty for a git ref', '' === $ver_probe('development'));

arm_rmdir($fx);

// ── The shipped manifest ────────────────────────────────────────────────────
$lines   = is_file($manifest) ? file($manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
$pinned  = [];
$malformed = [];
foreach ($lines as $line) {
    if ('' === $line || '#' === $line[0]) {
        continue;
    }
    if (preg_match('/^([0-9a-f]{64})  wp-slimstat\.([0-9]+(?:\.[0-9]+){1,3})\.zip$/', $line, $m)) {
        $pinned[$m[2]] = $m[1];
    } else {
        $malformed[] = $line;
    }
}
$check('every manifest line is a sha256 and a plugin ZIP', [] === $malformed, implode(' | ', $malformed));
$check('the manifest has no duplicate versions', count($pinned) === count(array_unique(array_keys($pinned))));

// The floor. Cell 1 runs 5.5.0; cells 7a-7d run these four. A deleted line is otherwise a cell
// that cannot run, discovered the day someone tries to run it.
foreach (['5.5.0' => 'cell 1 · U1', '4.8.1' => 'cell 7a', '5.1.5' => 'cell 7b', '5.2.13' => 'cell 7c', '5.4.12' => 'cell 7d'] as $ver => $cell) {
    $check('the manifest still pins ' . $ver . ' (' . $cell . ')', isset($pinned[$ver]));
}

// ── The container-side control is wired ─────────────────────────────────────
// H1's other half asserts, from inside the cell, that WordPress reports the version we asked
// for — `wp plugin install --force` can succeed while leaving a previous arm in place. That
// assertion needs a booted stack, so this file cannot execute it; what it CAN do is refuse to
// let it be deleted or left uncalled. Named for what it is: a wiring check, not a proof.
$rehearse_src = is_file($rehearse) ? (string) file_get_contents($rehearse) : '';
$defined      = 1 === preg_match('/^assert_arm_vintage\(\)/m', $rehearse_src);
$call_sites   = preg_match_all('/^\s*assert_arm_vintage\s+"/m', $rehearse_src);
$check('the vintage control is defined in rehearse-upgrade.sh', $defined);
$check(
    'and called on both install paths (provision + upgrade)',
    $call_sites >= 2,
    $call_sites . ' call site(s)'
);
// The reading itself moved into lib.sh when the corpus builder became a second caller (H3), so
// the literal is no longer in this file — but the control is only a control if it still asks
// WordPress. Both halves are named: the cell calls the helper, and the helper runs the command.
$libsrc = is_file(dirname($rehearse) . '/lib.sh') ? (string) file_get_contents(dirname($rehearse) . '/lib.sh') : '';
$check(
    'the control compares against what WordPress reports, not against the ref',
    false !== strpos($rehearse_src, 'arm_installed_version')
        && false !== strpos($libsrc, 'wpc plugin get wp-slimstat --field=version')
);

echo "\nSLIMSTAT-REHEARSAL-ARM-RESOLUTION checks=" . $checks . ' failures=' . count($failures) . "\n";
if ([] !== $failures) {
    fwrite(STDERR, "FAIL: rehearsal arm resolution\n  - " . implode("\n  - ", $failures) . "\n");
    exit(1);
}
echo "PASS: rehearsal arm resolution — " . $checks . " checks\n";
exit(0);
