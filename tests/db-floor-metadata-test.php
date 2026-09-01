<?php
/**
 * Source-level: the declared database floor is one number, and nothing enforces it by version.
 *
 * THE SHIPPING METADATA CONTRADICTED ITSELF, IN ONE FILE.
 *
 * `readme.txt` line 55 declared `MySQL 5.0.3+` while line 158 of the same file said the parity
 * set was "verified byte-identical across MySQL 5.6, 5.7 and 8.0 — the declared database floor
 * is tested, not assumed." Both cannot be the declared floor. `README.md` carried the same
 * 5.0.3 claim.
 *
 * 5.0.3 is not merely optimistic, it is impossible: the schema creates `utf8mb4` tables, and
 * utf8mb4 landed in MySQL 5.5.3. ADR-2 sets the floor at MySQL 5.6 / MariaDB 10.0, and the code
 * assumes it in several places (`Schema.php`'s collation resolution, `Chart.php`'s window
 * handling, `AddUserAgentDimension`'s INPLACE hint).
 *
 * AND THE SECOND HALF IS THE ONE THAT MATTERS MORE. ADR-2's C12 amendment says, in terms:
 * capability-detect, NEVER `version_compare` against 5.6. MariaDB reports `$wpdb->db_version()`
 * as `5.5.5-10.x` — a version string that begins 5.5.5 for compatibility reasons — so
 * `version_compare(db_version(), '5.6', '<')` is TRUE on every MariaDB install in existence,
 * all of which are inside the supported floor. A refusal written that way bricks them.
 *
 * There is no such check in the tree today, and this gate exists so that stays true. Writing
 * one is the obvious next step for anyone who reads the corrected floor line and decides to
 * enforce it, which is exactly why the prohibition needs to be mechanical rather than a
 * sentence in a decision record nobody greps.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

// The one true floor, per ADR-2. Changing it means changing the ADR first.
const SLIMSTAT_DB_FLOOR = 'MySQL 5.6+ (or MariaDB 10.0+)';

// ── One number, in both shipping files ──────────────────────────────────────────────────
$declared = [];

foreach (['readme.txt', 'README.md'] as $file) {
    $text = (string) file_get_contents($plugin_root . '/' . $file);

    if (!preg_match('/^\*\s*(MySQL[^\r\n]*)$/mi', $text, $m)) {
        $failures[] = "{$file} declares no MySQL requirement line — re-anchor this gate rather "
            . 'than deleting it; a floor that is stated nowhere is not a floor';
        continue;
    }

    $declared[$file] = trim($m[1]);
}

foreach ($declared as $file => $line) {
    if (SLIMSTAT_DB_FLOOR !== $line) {
        $failures[] = sprintf(
            "%s declares '%s' but ADR-2's floor is '%s'. 5.0.3 was impossible on its face — the "
                . 'schema creates utf8mb4 tables and utf8mb4 landed in MySQL 5.5.3 — and the same '
                . 'file claimed elsewhere that the floor is tested at 5.6',
            $file,
            $line,
            SLIMSTAT_DB_FLOOR
        );
    }
}

// A "the two files disagree with each other" branch used to sit here. It could never fire on
// its own: both are already compared against SLIMSTAT_DB_FLOOR, so a disagreement between them
// means at least one has already failed above. A second message with no discriminating power.

// ── Nothing may gate behaviour on a compared server version ─────────────────────────────
//
// The prohibition is specific: comparing db_version() against a floor. MariaDB self-reports
// 5.5.5-10.x, so any such comparison excludes every MariaDB install inside the supported range.
$sources = array_merge(
    [$plugin_root . '/wp-slimstat.php', $plugin_root . '/uninstall.php'],
    slimstat_own_php_files(
        [$plugin_root . '/src', $plugin_root . '/admin'],
        $plugin_root . '/src/Dependencies'
    )
);

$scanned = 0;

foreach ($sources as $file) {
    // Comments blanked, strings kept: a version literal is a string, and stripping strings
    // would erase the thing being looked for. Comments are blanked so ADR-2's own explanation,
    // quoted in several docblocks, cannot trip it.
    $code = slimstat_blank_comments((string) file_get_contents($file));
    $scanned++;

    // THE VERSION IS OFTEN IN A VARIABLE BY THEN. Matching only the inline spelling
    //     version_compare($wpdb->db_version(), '5.6', '<')
    // catches one of four realistic forms and misses
    //     $v = $wpdb->db_version(); ... version_compare($v, '5.6', '<')
    // which is the more natural way to write it. Measured: 3 of 4 spellings escaped, and the
    // mutation was written in the one spelling that was caught — a guard that looked like it
    // worked. So first collect anything assigned from a server-version source, then look for
    // those names too.
    $needle = 'db_version|mysql_version|server_info|DB_VERSION';

    if (preg_match_all('/(\$\w+(?:->\w+)?)\s*=[^;]{0,200}?(?:db_version|mysql_version|server_info)\s*\(/i', $code, $vars)) {
        foreach (array_unique($vars[1]) as $carrier) {
            $needle .= '|' . preg_quote($carrier, '/');
        }
    }

    if (!preg_match_all('/version_compare\s*\(([^;]{0,200})/', $code, $hits)) {
        continue;
    }

    foreach ($hits[1] as $call) {
        if (preg_match('/' . $needle . '/i', $call)) {
            $failures[] = sprintf(
                '%s compares a database server version with version_compare(). MariaDB reports '
                    . '5.5.5-10.x, so any comparison against a 5.6 floor is true on EVERY MariaDB '
                    . 'install — all of which are supported. ADR-2 C12: capability-detect instead',
                slimstat_rel_path($plugin_root, $file)
            );
        }
    }
}

if ($scanned < 20) {
    $failures[] = sprintf(
        'only %d shipped PHP file(s) were scanned — the file list has stopped resolving, so the '
            . 'version_compare prohibition above proves nothing',
        $scanned
    );
}

if ($failures) {
    fwrite(STDERR, 'FAIL: database floor metadata (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo 'PASS: both shipping files declare ' . SLIMSTAT_DB_FLOOR . '; no server-version comparison '
    . 'in ' . $scanned . " shipped file(s)\n";
