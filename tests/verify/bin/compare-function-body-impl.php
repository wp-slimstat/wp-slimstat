<?php
/**
 * Equivalence arm: OLD vs NEW slimstat_function_body(), over the real corpus.
 *
 * WHY. tests/lib/source-scan.php is read through by 56 assertions across 26 gating tests.
 * Changing how a function body is extracted changes the INPUT to every one of them, so
 * "the suite is still green" is not evidence — a suite whose assertions now compare
 * against different text can be green for the wrong reason, in either direction.
 *
 * This runs both implementations over every (file, function) pair in the plugin's own
 * source and reports every divergence, so the change can be adjudicated on the actual
 * differences rather than on the claim.
 *
 * ARMS ARE ANONYMOUS. The output labels the two implementations `arm-A` and `arm-B`, and
 * which one is HEAD is written only to the sealed key file. The adjudicator sees the
 * divergences without being told which side is the change — so "the number moved the
 * flattering way" has no direction available to flatter. Same property as
 * tests/verify/bin/seal-arm.sh, applied to a text-equivalence run rather than a timing.
 *
 * Usage:
 *   php tests/verify/bin/compare-function-body-impl.php [--reveal]
 *
 * Exit 0 always: this instrument REPORTS, it does not judge. Judging happens at
 * adjudication with the seal open. Removing the verdict removes the incentive.
 */

declare(strict_types=1);

$plugin_root = dirname(__DIR__, 3);
$out_dir     = $plugin_root . '/tests/verify/results';
$reveal      = in_array('--reveal', $argv, true);

// ── Load the two implementations under distinct function prefixes ───────────
// HEAD's copy comes out of git so the comparison cannot be contaminated by the working
// tree, and so this instrument keeps working after the change is committed.
$head_src = shell_exec('cd ' . escapeshellarg($plugin_root) . ' && git show HEAD:tests/lib/source-scan.php 2>/dev/null');
if (!is_string($head_src) || '' === trim($head_src)) {
    fwrite(STDERR, "CONTROLS: FAIL — cannot read HEAD:tests/lib/source-scan.php\n");
    fwrite(STDERR, "VERDICT: ABORTED (no baseline arm)\n");
    exit(1);
}
$work_src = (string) file_get_contents($plugin_root . '/tests/lib/source-scan.php');

/** Rename every slimstat_* function so both copies can be loaded at once. */
$namespace_impl = static function (string $src, string $prefix): string {
    $src = preg_replace('/\bfunction\s+(slimstat_\w+)/', 'function ' . $prefix . '$1', $src);
    return (string) preg_replace('/\b(slimstat_\w+)\s*\(/', $prefix . '$1(', $src);
};

$tmp = sys_get_temp_dir() . '/ss-impl-' . getmypid();
@mkdir($tmp);
file_put_contents($tmp . '/head.php', $namespace_impl($head_src, 'head_'));
file_put_contents($tmp . '/work.php', $namespace_impl($work_src, 'work_'));
require $tmp . '/head.php';
require $tmp . '/work.php';

// ── CONTROLS — prove the instrument measured the right thing ────────────────
$controls = [];
$controls['head arm loaded']  = function_exists('head_slimstat_function_body');
$controls['work arm loaded']  = function_exists('work_slimstat_function_body');
$controls['arms are distinct'] = md5($head_src) !== md5($work_src);

// Positive control: a fixture both arms MUST agree on. If this diverges, the harness is
// broken, not the implementations.
$trivial = '<?php function t() { return 1; }';
$head_trivial = @head_slimstat_function_body($trivial, 't');
try {
    $work_trivial = work_slimstat_function_body($trivial, 't');
} catch (\Throwable $e) {
    $work_trivial = '<<threw>>';
}
$controls['positive control: both arms agree on a trivial function'] = ($head_trivial === $work_trivial);

// Negative control: a fixture the arms MUST disagree on, or this run proves nothing.
// One `{` inside a string literal is the whole point of the change.
$poisoned = "<?php class P { function a() { \$r = '/\\{x/'; return 'A'; } function b() { return 'B'; } }";
$head_poisoned = @head_slimstat_function_body($poisoned, 'a');
try {
    $work_poisoned = work_slimstat_function_body($poisoned, 'a');
} catch (\Throwable $e) {
    $work_poisoned = '<<threw>>';
}
$controls['negative control: arms disagree on a brace-in-literal fixture'] = ($head_poisoned !== $work_poisoned);

echo "CONTROLS\n";
$controls_ok = true;
foreach ($controls as $label => $ok) {
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $label);
    $controls_ok = $controls_ok && $ok;
}
echo "\n";
if (!$controls_ok) {
    fwrite(STDERR, "VERDICT: ABORTED — a control failed, so no result from this run is valid.\n");
    exit(1);
}

// ── Corpus: every function defined in the plugin's own source ───────────────
$files = work_slimstat_own_php_files(
    [$plugin_root . '/wp-slimstat.php', $plugin_root . '/admin', $plugin_root . '/src', $plugin_root . '/uninstall.php'],
    $plugin_root . '/src/Dependencies'
);

$rows        = [];
$divergences = [];
$pairs       = 0;

foreach ($files as $file) {
    $src = (string) file_get_contents($file);
    $rel = substr($file, strlen($plugin_root) + 1);

    if (!preg_match_all('/\bfunction\s+&?\s*(\w+)\s*\(/', $src, $m)) {
        continue;
    }

    foreach (array_unique($m[1]) as $fn) {
        $pairs++;

        $a = @head_slimstat_function_body($src, $fn);
        try {
            $b = work_slimstat_function_body($src, $fn);
        } catch (\Throwable $e) {
            $b = '<<THREW: not defined>>';
        }

        if ($a === $b) {
            continue;
        }

        $divergences[] = [
            'file'      => $rel,
            'function'  => $fn,
            'arm_A_len' => '<<THREW: not defined>>' === $a ? -1 : strlen((string) $a),
            'arm_B_len' => '<<THREW: not defined>>' === $b ? -1 : strlen((string) $b),
            'arm_A'     => (string) $a,
            'arm_B'     => (string) $b,
        ];
    }
}

// ── Shuffle the arm labels; the mapping goes only into the sealed key ───────
// crc32 of the corpus makes the shuffle deterministic for a given tree (so a rerun is
// reproducible) while still being opaque to a reader of the report.
$flip = 1 === (crc32(implode(',', array_column($divergences, 'function'))) % 2);
foreach ($divergences as &$d) {
    if ($flip) {
        [$d['arm_A'], $d['arm_B']]         = [$d['arm_B'], $d['arm_A']];
        [$d['arm_A_len'], $d['arm_B_len']] = [$d['arm_B_len'], $d['arm_A_len']];
    }
}
unset($d);

@mkdir($out_dir, 0755, true);
$report = [
    'corpus_files'      => count($files),
    'pairs_compared'    => $pairs,
    'divergences'       => count($divergences),
    'identical'         => $pairs - count($divergences),
    'controls'          => $controls,
    'arm_key_sealed_in' => 'tests/verify/results/function-body-armkey.txt',
    'rows'              => $divergences,
];
file_put_contents($out_dir . '/function-body-equivalence.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
file_put_contents($out_dir . '/function-body-armkey.txt', $flip ? "arm-A=working-tree arm-B=HEAD\n" : "arm-A=HEAD arm-B=working-tree\n");

printf("corpus:      %d files\n", count($files));
printf("compared:    %d (file, function) pairs\n", $pairs);
printf("identical:   %d\n", $pairs - count($divergences));
printf("divergent:   %d\n\n", count($divergences));

foreach ($divergences as $i => $d) {
    printf("[%d] %s :: %s()   arm-A=%s bytes  arm-B=%s bytes\n",
        $i + 1, $d['file'], $d['function'],
        $d['arm_A_len'] < 0 ? 'ABSENT' : (string) $d['arm_A_len'],
        $d['arm_B_len'] < 0 ? 'ABSENT' : (string) $d['arm_B_len']);
}

echo "\nfull rows -> tests/verify/results/function-body-equivalence.json\n";
if ($reveal) {
    echo "ARM KEY: " . (string) file_get_contents($out_dir . '/function-body-armkey.txt');
} else {
    echo "arm key sealed (pass --reveal after adjudication)\n";
}
echo "VERDICT: REPORTED (no pass/fail — judgement happens at adjudication)\n";

array_map('unlink', glob($tmp . '/*.php') ?: []);
@rmdir($tmp);
