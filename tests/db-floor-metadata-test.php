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

// ── The rollback sentence, in the file wordpress.org actually shows people ──────────────
//
// The beta notes say what a downgrade does. The ~70,000 installs on wordpress.org never see
// the beta notes: what their Plugins screen shows is readme.txt's Upgrade Notice, and that
// said "back up your database first" and nothing at all about what going back does or does
// not undo. An owner who rolls back after a bad afternoon deserves to know before they start.
//
// WORDED PER ADR-4, not as "one-way". The update ADDS columns and never removes a row; older
// versions keep working on the updated tables (rehearsed at 5.5.x); the added columns simply
// stay. "One-way" would overstate it and contradict this programme's own rollback leg — so
// this requires the three facts rather than a slogan, and each is checked separately so a
// rewrite that drops one is a named failure rather than a silent loss.
// SECTION FIRST, THEN THE ENTRY `Stable tag:` NAMES — and each half was got wrong once. The
// first version matched the whole Upgrade Notice section (review moved the sentence into
// `= 5.5.1 =` and it stayed green); the second matched the whole FILE and read a dateless
// changelog heading as the notice — `D8-upgrade-notice-moved-to-changelog-01` replays that.
$readme_txt = (string) file_get_contents($plugin_root . '/readme.txt');

$stable_tag = (string) slimstat_header_field($readme_txt, 'Stable tag');

$notice_section = '';
if (preg_match('/^== Upgrade Notice ==[ \t]*$(.*?)(?=^== |\Z)/ms', $readme_txt, $ns)) {
    $notice_section = $ns[1];
}

$upgrade_notice = '';
if ('' === $stable_tag) {
    $failures[] = 'readme.txt declares no `Stable tag:` — the Upgrade Notice check below cannot '
        . 'tell which entry wordpress.org will show, so it would have to read them all';
} elseif ('' === trim($notice_section)) {
    $failures[] = 'readme.txt has no `== Upgrade Notice ==` section at all. wordpress.org renders '
        . 'it on the Plugins screen before the update button; without it a site owner reads '
        . 'nothing';
} elseif (preg_match('/^= ' . preg_quote($stable_tag, '/') . ' =$(.*?)(?=^= |\Z)/ms', $notice_section, $un)) {
    $upgrade_notice = $un[1];
}

if ('' === trim($upgrade_notice)) {
    $failures[] = sprintf(
        'readme.txt has no `= %s =` entry under `== Upgrade Notice ==`. That is the block a '
            . 'site owner reads on the Plugins screen before clicking update, and wordpress.org '
            . 'renders only the entry matching the version it offers',
        $stable_tag
    );
} else {
    // AT MOST 300 CHARACTERS, ROLLBACK FIRST. wordpress.org's sample readme says an upgrade
    // notice is "no more than 300 characters" — a GUIDELINE. Its parser applies no length cap
    // (checked: `class-parser.php` trims other sections, not this one) and core renders the
    // notice in full on Dashboard → Updates. The first version of this check claimed a hard
    // truncation "from the end"; no public source supports that, and the claim is withdrawn.
    // What stands: the entry was 484 characters against a 300 guideline with the rollback fact
    // starting at 285 — the part a reader skimming to the guideline's length would miss — so
    // the length is held to the guideline and the rollback sentence leads.
    //
    // WHERE IT IS READ. Dashboard → Updates, in full. NOT the Plugins-screen row: core hands the
    // notice only to `in_plugin_update_message-{file}`, which this plugin now hooks
    // (admin/index.php) so the warning sits beside the update link a site owner actually clicks.
    $notice_length = strlen(trim($upgrade_notice));
    if ($notice_length > 300) {
        $failures[] = sprintf(
            'the `= %s =` Upgrade Notice entry is %d characters, past the 300-character guideline '
                . 'wordpress.org sets for the field; keep it inside the guideline with the '
                . 'rollback sentence first, and put the long form in the changelog',
            $stable_tag,
            $notice_length
        );
    }

    $rollback_facts = [
        'that older versions keep working on the updated tables'
            => '/older versions keep working|keep working on the updated tables/i',
        'that the added columns stay'
            => '/columns? (?:stay|remain|are not removed)/i',
        'that no row is removed'
            => '/never removes a row|removes no rows/i',
    ];

    foreach ($rollback_facts as $what => $pattern) {
        if (!preg_match($pattern, $upgrade_notice)) {
            $failures[] = sprintf(
                'the readme.txt Upgrade Notice does not say %s. This is the ONLY downgrade '
                    . 'warning the wordpress.org population ever sees — the beta notes reach a '
                    . 'handful of testers and nobody else',
                $what
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

// ── The notice reaches the Plugins screen ───────────────────────────────────────────────
//
// Core does not render `upgrade_notice` in the Plugins-screen row; it hands it to
// `in_plugin_update_message-{file}` and nothing else. Without the hook, everything above pins
// a warning that appears only on Dashboard → Updates — not beside the update link a site owner
// clicks. So the hook is required, on this plugin's own basename, and its callback must escape
// what wordpress.org sends: the notice is remote text printed into wp-admin.
$admin = slimstat_blank_comments((string) file_get_contents($plugin_root . '/admin/index.php'));

if (!preg_match("/add_action\s*\(\s*'in_plugin_update_message-'\s*\.\s*plugin_basename\s*\(\s*SLIMSTAT_FILE\s*\)\s*,\s*\[\s*self::class\s*,\s*'(\w+)'\s*\]/", $admin, $hm)) {
    $failures[] = "admin/index.php does not hook `in_plugin_update_message-` . plugin_basename(SLIMSTAT_FILE). "
        . 'Core renders the Upgrade Notice on Dashboard → Updates only; without this hook the '
        . 'rollback warning never appears beside the update link on the Plugins screen';
} else {
    $callback = slimstat_find_function_body($admin, $hm[1]); // nullable; slimstat_function_body() throws on absence
    if (null === $callback) {
        $failures[] = sprintf('admin/index.php hooks in_plugin_update_message to %s(), which is not defined', $hm[1]);
    } else {
        if (false === strpos($callback, 'upgrade_notice')) {
            $failures[] = sprintf('%s() never reads $response->upgrade_notice, so it prints something '
                . 'other than the readme notice this file pins', $hm[1]);
        }
        if (false === strpos($callback, 'esc_html(')) {
            $failures[] = sprintf('%s() prints the update offer without esc_html(); upgrade_notice is '
                . 'remote text from wordpress.org rendered inside wp-admin', $hm[1]);
        }
    }
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
