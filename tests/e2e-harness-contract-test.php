<?php
/**
 * Source-level: the E2E harness must be able to deliver its own helpers, and to prove it did.
 *
 * THE MU-PLUGIN BIND MOUNT SHADOWED EVERY HELPER setup.ts DEPLOYS.
 *
 * `.wp-env.json` carried `"mappings": {"wp-content/mu-plugins": "./tests/e2e/mu-plugins"}`.
 * That is a Docker bind mount: the repo directory REPLACES `/var/www/html/wp-content/mu-plugins`
 * inside the container, and the underlying directory in the wp-env volume becomes unreachable.
 *
 * `tests/e2e/helpers/setup.ts` writes its helpers to `WP_ROOT/wp-content/mu-plugins` — which in
 * CI is the volume path on the host, i.e. exactly the directory the mount masks. So
 * `installAllTestMuPlugins()` copied sixteen files, reported success, and WordPress loaded the
 * one file that lived in the repo directory. `nonce-helper-mu-plugin.php` never loaded,
 * `admin-ajax.php` answered 400 for the unregistered `test_create_nonce` action, and the suite
 * produced 26 x "Cannot read properties of undefined (reading 'nonce')" plus 12 x
 * "test_create_nonce failed: HTTP 400" — 38 of 50 failures on the WP 6.4 lane, one cause.
 *
 * `.wp-env.override.json`, which CI writes per lane, re-declares only `core`, `phpVersion`,
 * `plugins` and `config`. wp-env merges it OVER the base file, so the mapping stood on every
 * Tier 2 lane. Nothing in the harness could report this: a copy to a masked directory succeeds.
 *
 * The gate is therefore not "the nonce works" — that is what the canary in global-setup.ts is
 * for, and it is the one assertion already proven red in production. This gate pins the four
 * structural properties that made the failure invisible for the life of the suite:
 *
 *   Sec.1  no bind mount may cover wp-content/mu-plugins
 *   Sec.2  every helper mu-plugin must have a named deployer
 *   Sec.3  ci.yml must resolve WP_ROOT deterministically, and fail when it cannot
 *   Sec.4  the run artifacts that would have shown all of this must be collected
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];
$checks      = 0;

/** Read a required file or fail the gate rather than skipping the section. */
$read = static function (string $rel) use ($plugin_root, &$failures): ?string {
    $path = $plugin_root . '/' . $rel;
    if (!is_file($path)) {
        $failures[] = "{$rel} is missing — this gate cannot check what it cannot read";
        return null;
    }
    return (string) file_get_contents($path);
};

// ── Sec.1 — no bind mount may cover wp-content/mu-plugins ──────────────────────────────
//
// Checked across EVERY wp-env config in the tree, not only the base file: an override that
// re-introduced the mapping would reproduce the defect exactly, and wp-env merges overrides on
// top. The env-scoped form (`env.tests.mappings`) is checked too — it is the same mount.

$env_configs = array_values(array_filter([
    '.wp-env.json',
    '.wp-env.override.json',
], static function (string $rel) use ($plugin_root): bool {
    return is_file($plugin_root . '/' . $rel);
}));

if (!$env_configs) {
    $failures[] = 'no .wp-env*.json found — the Tier 2 lanes cannot be configured, so this '
        . 'gate would pass by finding nothing';
}

$mapping_scan = 0;

foreach ($env_configs as $rel) {
    $raw = (string) file_get_contents($plugin_root . '/' . $rel);
    $cfg = json_decode($raw, true);

    if (!is_array($cfg)) {
        $failures[] = "{$rel} is not valid JSON (" . json_last_error_msg() . ')';
        continue;
    }

    // Collect every mappings block: top level, and one per env.
    $blocks = [];
    if (isset($cfg['mappings']) && is_array($cfg['mappings'])) {
        $blocks['mappings'] = $cfg['mappings'];
    }
    if (isset($cfg['env']) && is_array($cfg['env'])) {
        foreach ($cfg['env'] as $env_name => $env_cfg) {
            if (is_array($env_cfg) && isset($env_cfg['mappings']) && is_array($env_cfg['mappings'])) {
                $blocks["env.{$env_name}.mappings"] = $env_cfg['mappings'];
            }
        }
    }

    foreach ($blocks as $where => $block) {
        foreach ($block as $destination => $source) {
            $mapping_scan++;
            $dest = trim(str_replace('\\', '/', (string) $destination), '/');

            // `wp-content/mu-plugins`, anything beneath it, and the parent that would cover it.
            $covers_mu = $dest === 'wp-content/mu-plugins'
                || strpos($dest . '/', 'wp-content/mu-plugins/') === 0
                || $dest === 'wp-content'
                || $dest === '';

            if ($covers_mu) {
                $failures[] = "{$rel} ({$where}) bind-mounts '{$destination}' => '"
                    . (string) $source . "'. That mount REPLACES the directory "
                    . 'tests/e2e/helpers/setup.ts writes its mu-plugins into, so every helper it '
                    . 'deploys is invisible to WordPress while the copy still reports success — '
                    . 'which is how nonce-helper-mu-plugin.php never loaded and 38 of 50 '
                    . 'failures on the WP 6.4 lane came from one unregistered AJAX action';
            }
        }
    }
    $checks++;
}

// ── Sec.2 — every helper mu-plugin must have a named deployer ──────────────────────────
//
// With the mount gone, a file is only in the container if something copies it. A helper with no
// deployer is a spec that will fail for a reason no log explains.

$setup = $read('tests/e2e/helpers/setup.ts');

if ($setup !== null) {
    // Every sourceFile named in MU_PLUGIN_MANIFEST.
    preg_match_all("/sourceFile:\s*'([^']+)'/", $setup, $m);
    $manifest = array_values(array_unique($m[1]));

    // Helpers installed by a spec rather than the manifest. Each entry must name the file that
    // does the installing, so an orphan cannot be silenced by adding a bare filename here.
    $ad_hoc = [
        'jquery-migrate-loader-mu-plugin.php' => 'tests/e2e/wp56-jquery-migrate-console.spec.ts',
    ];

    // The bind-mount source directory must stay gone. Globbing it for orphans would be a check
    // that cannot fire — the directory does not exist, so the glob is permanently empty and would
    // have quietly carried none of the vacuity floor it appears to share. Assert the invariant
    // that actually holds instead: nothing deploys from there any more, so a file appearing there
    // is either dead or a re-introduced mount waiting to happen.
    if (is_dir($plugin_root . '/tests/e2e/mu-plugins')) {
        $failures[] = 'tests/e2e/mu-plugins/ exists again. Nothing deploys from that directory '
            . 'since the bind mount was removed, so a file in it either does nothing or is the '
            . 'first half of re-creating the mount that shadowed the whole helper set';
    }

    $candidates = (array) glob($plugin_root . '/tests/e2e/helpers/*-mu-plugin.php');

    // VACUITY FLOOR. A typo in the glob would empty this list, and an empty list satisfies every
    // assertion below. There are 17 on disk; the manifest names 16 of them.
    if (count($candidates) < 17) {
        $failures[] = 'found only ' . count($candidates) . ' candidate mu-plugin source file(s); '
            . 'expected at least 17 — the glob is wrong and everything below passes vacuously';
    }

    foreach ($candidates as $path) {
        $base = basename($path);
        $checks++;

        if (in_array($base, $manifest, true)) {
            continue;
        }

        if (isset($ad_hoc[$base])) {
            $installer = $plugin_root . '/' . $ad_hoc[$base];
            if (!is_file($installer)) {
                $failures[] = "{$base} is exempted as installed by {$ad_hoc[$base]}, but that "
                    . 'file does not exist — the exemption names nothing';
            } elseif (strpos((string) file_get_contents($installer), $base) === false) {
                $failures[] = "{$base} is exempted as installed by {$ad_hoc[$base]}, but that "
                    . 'file never mentions it';
            }
            continue;
        }

        $failures[] = "tests/e2e/helpers/setup.ts's MU_PLUGIN_MANIFEST does not carry {$base}, "
            . 'and no spec is recorded as installing it. Since the bind mount was removed '
            . 'nothing else copies a file into the container, so this helper is dead: any spec '
            . 'depending on it fails with a symptom that names something else entirely';
    }

    if (count($manifest) < 16) {
        $failures[] = 'MU_PLUGIN_MANIFEST parsed to ' . count($manifest) . ' entries; expected at '
            . 'least 16 — the regex no longer matches the manifest and Sec.2 is vacuous';
    }
}

// ── Sec.3 — WP_ROOT must resolve deterministically, and fail loudly ────────────────────
//
// The heuristic it replaces was `docker ps --format "{{.Names}}" | ... | head -1`, which orders
// by container age. wp-env starts BOTH a development container (port 8888) and a tests container
// (8889); the browser talks to tests. `head -1` picking the right one was luck, and when it
// picked wrong every debug-log assertion — all of them negative — passed against a file from the
// wrong site.

$ci = $read('.github/workflows/ci.yml');

if ($ci !== null) {
    $checks++;

    // EVERY check below reads $ci_code, never $ci. Comments are stripped first for two distinct
    // reasons, and only the first was obvious. (1) The replacement's own comment QUOTES the old
    // `docker ps` command to explain it, so an unstripped scan would demand that the explanation
    // be deleted to pass — a gate whose remedy is "remove the paragraph describing the defect" is
    // a gate against records. (2) The far worse direction: a check for the PRESENCE of code is
    // satisfied by prose. `strpos($ci, 'npx wp-env install-path')` is true when the only
    // occurrence is a comment saying the command is missing, and the artifact-collection check
    // below was true for a commented-out upload step. Both were live in the first draft of this
    // file — a gate against vacuity, passing vacuously.
    $ci_code = slimstat_yaml_strip_comments($ci);

    // Scoped to the step that resolves the root, so "resolves it" and "can fail" are asserted of
    // the SAME step rather than of the file. Six-space `- `, not `- name:`: a step whose first
    // key is `uses:` folds into its predecessor under the other dialect.
    $resolve_step = '';
    foreach (slimstat_ci_steps($ci_code) as $step) {
        if (strpos($step, 'wp-env install-path') !== false) {
            $resolve_step = $step;
            break;
        }
    }

    if (strpos($ci_code, 'npx wp-env install-path') === false) {
        $failures[] = '.github/workflows/ci.yml does not resolve the WordPress root with '
            . '`npx wp-env install-path`. Deriving it by listing containers picks between the '
            . 'development and tests sites by luck, and every debug-log assertion in the suite '
            . 'is negative — so pointing at the wrong site is indistinguishable from a pass';
    }

    // The hazard is selecting a container by what it is NOT (`grep -v`), which is why the WP
    // container and the MySQL container are not the same case: `grep -i "tests-mysql"` names its
    // target, and there is exactly one match. Matching every `docker ps ... | head -1` would flag
    // that line too, and the only way to satisfy it would be to make a correct line worse.
    if (preg_match('/docker\s+ps[^\n]*grep\s+-v[^\n]*head\s+-1/', $ci_code)) {
        $failures[] = '.github/workflows/ci.yml still selects a container by exclusion — '
            . '`docker ps ... | grep -v ... | head -1`. That is the heuristic being replaced, '
            . 'not a second opinion on it';
    }

    // The resolution must be able to fail. A `|| true` with no subsequent check leaves WP_ROOT
    // empty, and an empty WP_ROOT makes every filesystem assertion read a path under `/`.
    // Within the resolving step itself: it must refuse, and it must say so. The window-of-six-
    // lines approximation this replaces would have been satisfied by an `exit 1` belonging to a
    // neighbouring step.
    if ($resolve_step === '' || !preg_match('/\bexit\s+1\b/', $resolve_step)) {
        $failures[] = '.github/workflows/ci.yml resolves the install path without failing the '
            . 'job when it cannot. An unresolved WP_ROOT is empty, not absent, so the specs '
            . 'read paths under / and their negative assertions pass having read nothing';
    }
}

// ── Sec.4 — the run artifacts must be collected ───────────────────────────────────────
//
// playwright.config.ts writes the JSON and blob reports under tests/e2e/run-artifacts/. Only the
// HTML report was uploaded, so "read results.json for the per-spec census" was not a thing anyone
// could do after a CI run — which is why the 38-failure common cause went unnamed for so long.

if ($ci !== null) {
    $checks++;
    $pw_config = $read('tests/e2e/playwright.config.ts');

    if ($pw_config !== null && strpos($pw_config, "'run-artifacts'") !== false
        && strpos($ci_code, 'tests/e2e/run-artifacts') === false) {
        $failures[] = 'playwright.config.ts writes the JSON and blob reports into '
            . 'tests/e2e/run-artifacts/, but .github/workflows/ci.yml never uploads that '
            . 'directory. The per-spec census exists on the runner and is discarded, leaving '
            . 'only a log tail — which is why one shared cause behind 38 failures read as 38 '
            . 'unrelated ones';
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: E2E harness contract (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo 'PASS: no bind mount covers wp-content/mu-plugins, every helper mu-plugin has a named '
    . 'deployer, WP_ROOT resolves deterministically and fails loudly, and the run artifacts are '
    . 'collected (' . $checks . " checks, {$mapping_scan} wp-env mapping(s) inspected)\n";
