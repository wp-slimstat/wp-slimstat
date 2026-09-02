<?php
/**
 * A network activation is bounded, records its progress, and is RESUMED by something other
 * than the hook that started it.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────────────────────────
 *
 * `on_activate()` walked `get_sites(['number' => 0])` — every site on the network — and for each
 * one ran `init_environment()`: up to 6 CREATE TABLE, ~13 CREATE INDEX, and a HARD
 * `flush_rewrite_rules()`, which rewrites `.htaccess`. No budget, no batching, no record of
 * where it got to.
 *
 * AND THE FAILURE IS NOT "THE LATER SITES MISS OUT". Core's `activate_plugin()` fires
 * `activate_{$plugin}` and only afterwards writes `active_sitewide_plugins`
 * (wp-admin/includes/plugin.php). So a walk that exhausts `max_execution_time` dies inside the
 * hook, the option is never written, and the plugin IS NOT NETWORK-ACTIVATED AT ALL. What it
 * leaves behind is tables and rewritten `.htaccess` files on the sites it did reach, for a
 * plugin that is not running — and the obvious retry re-runs the same unbounded walk that just
 * died. On a large enough network that is deterministic, not a race.
 *
 * ── WHAT MAKES A CURSOR A RESUME RATHER THAN A MARKER ───────────────────────────────────────
 *
 * WordPress fires activation ONCE. A cursor whose only consumer is `on_activate()` would never
 * be read again, so the work it defers is work nobody does — the shape this repo keeps
 * recording, one altitude up. The consumer therefore has to be a different hook, and this file
 * asserts that BY RESUMING: the behavioural half below runs a pass, checks what is left, and
 * runs another one, proving the second pass picks up where the first stopped rather than
 * starting over or doing nothing.
 *
 * ── WHY A CHILD PROCESS ─────────────────────────────────────────────────────────────────────
 *
 * `wp-slimstat.php` declares functions and classes at file scope, so it can be loaded once per
 * process. The driver spawns one child per scenario, exactly as uninstall-data-safety-test.php
 * does for uninstall.php, and the child stubs the WordPress surface the walk touches. That is
 * the real method under test, not an extracted copy of its loop.
 *
 * ── WHAT THIS DOES NOT ESTABLISH ────────────────────────────────────────────────────────────
 *
 * That a real multisite network survives an interrupted activation. Nothing here switches a
 * blog or writes a table; `init_environment()` is a double that records being called. The
 * kill-mid-walk leg belongs to the topology-C rehearsal cell, and until it runs, "resumable" is
 * proven against doubles and not against a database.
 *
 * The poison-pill scenario is the same caveat one level in: it SIMULATES a pass that never
 * returned by seeding the attempt marker, because a harness cannot kill its own request. What
 * it proves is that the marker is consumed; that the marker is written early enough to survive
 * a real death is a property of the ordering, and only the rehearsal cell can observe it.
 *
 * 7.4-safe: plain PHP, no PHPUnit, no vendor autoload in the driver.
 *
 * Run: php tests/network-activation-bounded-test.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit(1);
}

error_reporting(E_ALL);

$scenario = getenv('SLIMSTAT_ACTIVATION_SCENARIO');

if ($scenario !== false && $scenario !== '') {
    // ─────────────────────────── HARNESS MODE (child process) ───────────────

    // Warnings go to STDERR, never STDOUT. The child's stdout IS the JSON contract with the
    // driver, and one stray notice printed before it turns every assertion in this file into
    // "child returned no JSON" — a failure that names the harness rather than the subject.
    ini_set('display_errors', 'stderr');

    // src/Constants.php opens with `if (!defined('ABSPATH')) { exit; }` — the standard direct-
    // access guard — so without this the child exits 0 having printed nothing, which is exactly
    // as loud as a passing run. It cost one debugging round to notice.
    define('ABSPATH', sys_get_temp_dir() . '/slimstat-activation-abspath/');

    // wp_slimstat's class constants are evaluated at CLASS-LOAD time and one of them is
    // `12 * HOUR_IN_SECONDS`, so without these the file cannot be loaded at all.
    define('MINUTE_IN_SECONDS', 60);
    define('HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS);
    define('DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS);
    define('WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS);

    $GLOBALS['__network_options'] = [];
    $GLOBALS['__inits']           = [];   // blog ids init_environment() ran for, in order
    $GLOBALS['__cursor_writes']   = [];   // every value the cursor option was set to, in order
    $GLOBALS['__cursor_deletes']  = 0;
    $GLOBALS['__marker_deletes']  = 0;
    $GLOBALS['__switches']        = [];
    $GLOBALS['__notice_html']     = '';

    // A stub admin bundle, so `include_once plugin_dir_path(__FILE__) . 'admin/index.php'`
    // reaches a wp_slimstat_admin that records rather than one that runs DDL.
    $stub_root = sys_get_temp_dir() . '/slimstat-activation-' . getmypid();
    @mkdir($stub_root . '/admin', 0777, true);
    file_put_contents($stub_root . '/admin/index.php', '<?php
class wp_slimstat_admin
{
    public static function init_environment()
    {
        $GLOBALS["__inits"][] = $GLOBALS["__current_blog"];
        if (!empty($GLOBALS["__throw_on"]) && $GLOBALS["__current_blog"] === $GLOBALS["__throw_on"]) {
            throw new RuntimeException("this blog refuses");
        }
        return true;
    }
}');
    $GLOBALS['__stub_root'] = $stub_root;

    function plugin_dir_path($file)
    {
        // The real one would return the plugin directory; the walk only uses it to reach the
        // admin bundle, so pointing it at the stub is the whole substitution.
        return $GLOBALS['__stub_root'] . '/';
    }
    function plugins_url($path = '', $plugin = '') { return 'https://example.test/plugins'; }
    function plugin_basename($file) { return 'wp-slimstat/wp-slimstat.php'; }
    function plugin_dir_url($file) { return 'https://example.test/plugins/wp-slimstat/'; }
    function get_admin_url() { return 'https://example.test/wp-admin/'; }
    // Defined so src/Constants.php does not require wp-admin/includes/plugin.php, which is not
    // there to require.
    function get_plugin_data($file, $markup = true, $translate = true) { return []; }

    function get_site_option($name, $default = false)
    {
        return array_key_exists($name, $GLOBALS['__network_options'])
            ? $GLOBALS['__network_options'][$name]
            : $default;
    }
    function update_site_option($name, $value)
    {
        $GLOBALS['__network_options'][$name] = $value;
        if ('slimstat_network_activation_pending' === $name) {
            $GLOBALS['__cursor_writes'][] = $value;
        }
        return true;
    }
    function delete_site_option($name)
    {
        unset($GLOBALS['__network_options'][$name]);
        if ('slimstat_network_activation_pending' === $name) {
            $GLOBALS['__cursor_deletes']++;
        }
        if ('slimstat_network_activation_attempting' === $name) {
            $GLOBALS['__marker_deletes']++;
        }
        return true;
    }
    // Per-blog options, KEYED ON THE CURRENT BLOG — which is the whole point. A single flat
    // store cannot tell a degradation recorded on the site it names from one recorded on the
    // main site while merely naming that site in its key, and the second is what the skip
    // branch did before it gained a switch_to_blog(). A harness that cannot see a defect is a
    // harness that will let it back in.
    $GLOBALS['__options'] = [];
    function get_option($name, $default = false)
    {
        $blog = $GLOBALS['__current_blog'] ?? 1;

        return $GLOBALS['__options'][$blog][$name] ?? $default;
    }
    function update_option($name, $value, $autoload = null)
    {
        $blog = $GLOBALS['__current_blog'] ?? 1;

        $GLOBALS['__options'][$blog][$name] = $value;
        return true;
    }
    // Both varied by scenario, so all three conjuncts of the guard in
    // continue_network_activation() are exercised rather than only read. Before these existed
    // no scenario reached that method at all; the capability half stayed unkillable one round
    // longer, because it was set here and never set to false.
    $GLOBALS['__is_network_admin'] = true;
    $GLOBALS['__can_manage']       = true;
    function is_multisite() { return true; }
    function is_network_admin() { return (bool) $GLOBALS['__is_network_admin']; }
    function current_user_can($cap) { return (bool) $GLOBALS['__can_manage']; }

    function switch_to_blog($blog_id)
    {
        $GLOBALS['__current_blog'] = (int) $blog_id;
        $GLOBALS['__switches'][]   = (int) $blog_id;
        return true;
    }
    function get_sites($args = [])
    {
        return $GLOBALS['__sites'] ?? [];
    }
    function restore_current_blog()
    {
        $GLOBALS['__current_blog'] = 1;
        return true;
    }

    // The notice prints; nothing here needs WordPress's escaping or i18n beyond identity.
    function esc_html($text) { return $text; }
    function _n($single, $plural, $number, $domain = 'default') { return 1 === $number ? $single : $plural; }
    function number_format_i18n($number, $decimals = 0) { return number_format((float) $number, (int) $decimals); }
    function __($text, $domain = 'default') { return $text; }

    // The hook API, recorded rather than no-opped — which turns the registration block at the
    // bottom of wp-slimstat.php into EVIDENCE. Whether the resume is wired is then answered by
    // what the plugin actually registered when loaded, not by a regex over its source.
    //
    // It cannot be skipped, either: the `add_action('wp_ajax_slimstat_clear_cache', …)`
    // registration sits OUTSIDE the `function_exists('add_action')` guard above it, so
    // loading the file without add_action() is a fatal.
    $GLOBALS['__actions'] = [];
    function add_action($hook, $callback, $priority = 10, $args = 1)
    {
        $GLOBALS['__actions'][] = [$hook, is_array($callback) ? implode('::', $callback) : $callback];
        return true;
    }
    function register_activation_hook($file, $callback) { return true; }
    function register_deactivation_hook($file, $callback) { return true; }
    // False, so the guarded `include_once admin/index.php` does not pull in the real bundle and
    // shadow the stub the walk is meant to reach.
    function is_admin() { return false; }

    // network_activation_notice() printf()s straight to output, and this child's STDOUT IS
    // the JSON contract with the driver — an uncaptured notice would corrupt it into "child
    // returned no JSON". Buffered, and the buffer is what gets asserted on.
    function nab_render_notice()
    {
        ob_start();
        wp_slimstat::network_activation_notice();
        $GLOBALS['__notice_html'] .= (string) ob_get_clean();
    }

    // `class slimstat_widget extends WP_Widget` sits at file scope, so the parent has to exist
    // before the require.
    class WP_Widget
    {
        public function __construct(...$args) {}
    }

    require_once __DIR__ . '/../wp-slimstat.php';

    // init() never runs here, so wp_slimstat::$settings is empty and the degradation path —
    // reached by the refusing-blog scenario — reads $settings['auto_purge'] on the way through
    // purge_is_stale(). Seeded rather than suppressed: a harness that hides a warning cannot
    // tell a harness artefact from a product one.
    wp_slimstat::$settings['auto_purge'] = 0;

    $pending = [11, 12, 13, 14, 15];

    switch ($scenario) {
        case 'budget-stops':
            // Budget 0: the deadline is already reached on the SECOND check, so exactly one
            // site must still run. Without the at-least-one guarantee this walk would start
            // nothing, every pass, on any server slow enough to cross the deadline early.
            $GLOBALS['__network_options']['slimstat_network_activation_pending'] = $pending;
            $left = wp_slimstat::walk_pending_activation_sites(0);
            break;

        case 'resume':
            // The property that separates a resume from a marker: a SECOND pass, with the
            // cursor as the only thing carried between them, continues rather than restarting.
            $GLOBALS['__network_options']['slimstat_network_activation_pending'] = $pending;
            wp_slimstat::walk_pending_activation_sites(0);
            wp_slimstat::walk_pending_activation_sites(0);
            $left = wp_slimstat::walk_pending_activation_sites(0);
            break;

        case 'completes':
            $GLOBALS['__network_options']['slimstat_network_activation_pending'] = [11, 12, 13];
            $left = wp_slimstat::walk_pending_activation_sites(60);
            break;

        case 'nothing-pending':
            $left = wp_slimstat::walk_pending_activation_sites(60);
            break;

        case 'resume-refuses-when-not-network-active':
            // The orphan case: an activation that died before core wrote
            // active_sitewide_plugins. The cursor is there and the plugin is NOT recorded as
            // active, so neither the walk nor the notice may act on it.
            //
            // `?? []` IS LOAD-BEARING. Without it, a mutant that removes the guard empties the
            // cursor, count(null) fatals, and the gate reports "child returned no JSON" — red
            // for the harness rather than for the defect. Right verdict, wrong reason is how a
            // gate gets relaxed by the next reader.
            $GLOBALS['__network_options']['slimstat_network_activation_pending'] = $pending;
            wp_slimstat::continue_network_activation();
            nab_render_notice();
            $left = count($GLOBALS['__network_options']['slimstat_network_activation_pending'] ?? []);
            break;

        case 'resume-refuses-without-capability':
            // A network admin screen reached by someone who cannot manage the network. The
            // walk is a burst of DDL and a hard rewrite flush; the capability is the only
            // thing standing between it and any logged-in user who loads that URL.
            $GLOBALS['__can_manage'] = false;
            $GLOBALS['__network_options']['active_sitewide_plugins'] = ['wp-slimstat/wp-slimstat.php' => 1];
            $GLOBALS['__network_options']['slimstat_network_activation_pending'] = $pending;
            wp_slimstat::continue_network_activation();
            nab_render_notice();
            $left = count($GLOBALS['__network_options']['slimstat_network_activation_pending'] ?? []);
            break;

        case 'stored-empty':
            // A cursor stored as an empty array — the one shape the `false` default cannot
            // distinguish by absence. It must be cleaned up, marker included.
            $GLOBALS['__network_options']['slimstat_network_activation_pending']    = [];
            $GLOBALS['__network_options']['slimstat_network_activation_attempting'] = 11;
            $left = wp_slimstat::walk_pending_activation_sites(60);
            break;

        case 'reactivation-clears-a-stale-marker':
            // A previous activation died leaving a marker behind, and the admin activates
            // again. Nothing but on_activate() can clear it, and if it does not, the blog that
            // id names is skipped on a walk that never attempted it.
            $GLOBALS['__network_options']['slimstat_network_activation_attempting'] = 12;
            $GLOBALS['__sites'] = [11, 12, 13];
            wp_slimstat::on_activate(true);
            $left = count($GLOBALS['__network_options']['slimstat_network_activation_pending'] ?? []);
            break;

        case 'resume-runs-when-network-active':
            $GLOBALS['__network_options']['active_sitewide_plugins'] = ['wp-slimstat/wp-slimstat.php' => 1];
            $GLOBALS['__network_options']['slimstat_network_activation_pending'] = $pending;
            // Rendered BEFORE and AFTER: the notice must speak while sites remain and fall
            // silent once they do not. Rendering only after would assert nothing here, because
            // the resume takes no budget and finishes the whole list in one pass.
            nab_render_notice();
            wp_slimstat::continue_network_activation();
            $left = count($GLOBALS['__network_options']['slimstat_network_activation_pending'] ?? []);
            break;

        case 'resume-refuses-outside-network-admin':
            $GLOBALS['__is_network_admin'] = false;
            $GLOBALS['__network_options']['active_sitewide_plugins'] = ['wp-slimstat/wp-slimstat.php' => 1];
            $GLOBALS['__network_options']['slimstat_network_activation_pending'] = $pending;
            wp_slimstat::continue_network_activation();
            nab_render_notice();
            $left = count($GLOBALS['__network_options']['slimstat_network_activation_pending'] ?? []);
            break;

        case 'poison-pill':
            // A blog whose setup never returns leaves its id in the attempt marker AND at the
            // head of the cursor. The next pass must skip it rather than start it again — and
            // must reach the sites behind it.
            $GLOBALS['__network_options']['slimstat_network_activation_pending']    = [11, 12, 13];
            $GLOBALS['__network_options']['slimstat_network_activation_attempting'] = 11;
            $left = wp_slimstat::walk_pending_activation_sites(60);
            break;

        case 'one-site-refuses':
            // A refusing blog must not strand the ones behind it, and must not be retried
            // forever either — it comes off the cursor like any other.
            $GLOBALS['__throw_on'] = 12;
            $GLOBALS['__network_options']['slimstat_network_activation_pending'] = [11, 12, 13];
            $left = wp_slimstat::walk_pending_activation_sites(60);
            break;

        default:
            fwrite(STDERR, "unknown scenario {$scenario}\n");
            exit(2);
    }

    @unlink($stub_root . '/admin/index.php');
    @rmdir($stub_root . '/admin');
    @rmdir($stub_root);

    echo json_encode([
        'left'          => $left,
        'inits'         => $GLOBALS['__inits'],
        'switches'      => $GLOBALS['__switches'],
        'options'       => $GLOBALS['__options'],
        'cursorWrites'  => $GLOBALS['__cursor_writes'],
        'cursorDeletes' => $GLOBALS['__cursor_deletes'],
        'markerDeletes' => $GLOBALS['__marker_deletes'],
        'markerNow'     => $GLOBALS['__network_options']['slimstat_network_activation_attempting'] ?? null,
        'noticeHtml'    => $GLOBALS['__notice_html'],
        'cursorNow'     => $GLOBALS['__network_options']['slimstat_network_activation_pending'] ?? null,
        'actions'       => $GLOBALS['__actions'],
    ]);
    exit(0);
}

// ─────────────────────────────── DRIVER MODE ────────────────────────────────

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$assertions  = 0;
$failures    = [];

function nab_check($condition, string $message): void
{
    global $assertions, $failures;
    $assertions++;
    if (true !== $condition) {
        $failures[] = $message;
    }
}

function nab_same($expected, $actual, string $message): void
{
    nab_check(
        $expected === $actual,
        $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'
    );
}

function nab_run(string $scenario): array
{
    global $failures;

    $child = slimstat_spawn_child(__FILE__, ['SLIMSTAT_ACTIVATION_SCENARIO' => $scenario]);

    if (null === $child) {
        $failures[] = "could not spawn child for scenario '{$scenario}'";
        return [];
    }

    $result = json_decode($child['stdout'], true);
    if (!is_array($result)) {
        $failures[] = "child for scenario '{$scenario}' returned no JSON.\nSTDOUT: "
            . $child['stdout'] . "\nSTDERR: " . $child['stderr'];
        return [];
    }

    return $result;
}

// ── 1. The walk is bounded, and still always starts one site ─────────────────

$stopped = nab_run('budget-stops');
if ($stopped) {
    nab_same([11], $stopped['inits'], 'an exhausted budget still starts exactly one site');
    nab_same(4, $stopped['left'], 'the other four are reported as still owed');
    nab_same([12, 13, 14, 15], $stopped['cursorNow'], 'and they are the ones left on the cursor');

    // THE CURSOR HAS A CONSUMER, and this is runtime evidence rather than a regex over the
    // source: these are the hooks the plugin ACTUALLY registered when the child loaded it.
    // WordPress fires activation once, so a cursor whose only reader is on_activate() would
    // never be read again — the resume has to hang off a hook that fires on later requests.
    $registered = implode(', ', array_map(
        static function ($action) { return $action[0] . ' => ' . $action[1]; },
        $stopped['actions']
    ));
    nab_check(
        in_array(['admin_init', 'wp_slimstat::continue_network_activation'], $stopped['actions'], true),
        'the resume is registered on admin_init; registered hooks were: ' . $registered
    );
    nab_check(
        in_array(['network_admin_notices', 'wp_slimstat::network_activation_notice'], $stopped['actions'], true),
        'and the network admin is told while sites remain; registered hooks were: ' . $registered
    );
}

// ── 2. THE RESUME. Three passes, each budget-0, must consume three sites ─────
//
// This is the assertion the whole change exists for. A cursor that is written and never read
// gives inits = [11, 11, 11]; a cursor read but not advanced gives the same. Only a consumed
// one gives three DIFFERENT sites in order.

$resumed = nab_run('resume');
if ($resumed) {
    nab_same([11, 12, 13], $resumed['inits'], 'three passes consume three DIFFERENT sites, in order');
    nab_same(2, $resumed['left'], 'two remain after three passes');
    nab_same([14, 15], $resumed['cursorNow'], 'and the cursor names exactly those two');
}

// ── 3. Progress is persisted per site, not once at the end ───────────────────

$done = nab_run('completes');
if ($done) {
    nab_same([11, 12, 13], $done['inits'], 'a sufficient budget finishes the network');
    nab_same($done['inits'], $done['switches'], 'every init_environment() ran with that blog switched to');
    nab_same(0, $done['left'], 'nothing is left owed');
    // Written after EVERY site, not once after the loop: a pass killed at site 40 must not
    // re-do 40 sites, and must not skip them either. The exact sequence says that; a count
    // of writes would be entailed by it and could not fail on its own.
    nab_same([[12, 13], [13]], $done['cursorWrites'], 'each write is the REMAINING list, shortening by one');
    nab_same(1, $done['cursorDeletes'], 'the cursor is removed when the list empties, so absence means done');
}

// ── 4. Degenerate and unhappy paths ──────────────────────────────────────────

$idle = nab_run('nothing-pending');
if ($idle) {
    nab_same([], $idle['inits'], 'no cursor means no work');
    nab_same(0, $idle['left'], 'and nothing owed');

    // THE STEADY STATE, which is what this method spends its life doing. An absent cursor must
    // cost NOTHING — no write, and above all no delete: core's delete_network_option() issues
    // an uncached `SELECT meta_id FROM sitemeta` before it can decide there is nothing to
    // delete, so an unconditional delete here is one query on every network-admin page load,
    // forever. Measured green with that delete restored before these two lines existed.
    nab_same([], $idle['cursorWrites'], 'an absent cursor is never written');
    nab_same(0, $idle['cursorDeletes'], 'and never deleted — the delete is a query in itself');
}

$stale = nab_run('stored-empty');
if ($stale) {
    nab_same([], $stale['inits'], 'a cursor stored as an empty array is no work either');
    nab_same(1, $stale['cursorDeletes'], 'but it IS cleaned up — absence is the only "nothing pending"');
    nab_same(1, $stale['markerDeletes'], 'and the attempt marker goes with it, so the two never outlive each other');
}

$refused = nab_run('one-site-refuses');
if ($refused) {
    nab_same([11, 12, 13], $refused['inits'], 'a blog that throws does not strand the blogs behind it');
    nab_same(0, $refused['left'], 'and the walk still completes');

    // THE PROPERTY ONLY THIS SCENARIO CAN PIN, and it was missing: the failing blog is
    // RECORDED. Measured before this assertion existed — deleting the record_degradation()
    // call from the walk left the gate green at 25/25, so the scenario was shape-identical to
    // `completes` and pinned nothing of its own. The degradation notice is the only way an
    // admin learns WHICH site has no tables.
    // ON BLOG 12'S OWN OPTIONS, not the main site's. record_degradation() writes to whichever
    // blog is current, so "which store" is the assertion — the key naming blog 12 is written by
    // the caller either way and proves nothing about where it landed.
    $recorded = json_encode($refused['options'][12]['slimstat_degradations'] ?? []);
    nab_check(
        false !== strpos((string) $recorded, 'blog 12'),
        'the blog that refused is named in ITS OWN degradations, so the admin finds it on the '
            . 'site it concerns; got: ' . $recorded
    );
}

// ── 5. The resume's own guards, EXECUTED rather than read ────────────────────

$orphan = nab_run('resume-refuses-when-not-network-active');
if ($orphan) {
    nab_same([], $orphan['inits'], 'a cursor left by an activation core never recorded is not acted on');
    nab_same(5, $orphan['left'], 'and it is left exactly as it was');

    // AND THE NOTICE AGREES WITH IT. The two used to disagree: the notice checked only the
    // cursor, so this case rendered "reload this page to continue" while the resume refused
    // to act — a notice asking for something that cannot work.
    nab_same('', $orphan['noticeHtml'], 'and the notice stays silent rather than asking for a reload that does nothing');
}

$armed = nab_run('resume-runs-when-network-active');
if ($armed) {
    // The positive control for the three silences above: without it, a notice that never
    // renders at all satisfies every one of them.
    nab_check(
        false !== strpos((string) $armed['noticeHtml'], 'still setting up'),
        'the notice DOES render while sites remain; got: ' . var_export($armed['noticeHtml'], true)
    );
    // The whole list, because continue_network_activation() passes no budget and the doubles
    // are instant — the claim here is that the resume ACTS, not how far one pass gets.
    nab_same([11, 12, 13, 14, 15], $armed['inits'], 'a network-active plugin resumes the walk from admin_init');
    nab_same(0, $armed['left'], 'and the cursor is cleared once the network is done');
}

$elsewhere = nab_run('resume-refuses-outside-network-admin');
if ($elsewhere) {
    nab_same([], $elsewhere['inits'], 'and it does nothing outside the network admin');
    nab_same('', $elsewhere['noticeHtml'], 'nor does the notice render there');
}

// The capability, which was the one conjunct of the guard no scenario varied — measured, the
// suite stayed green with `current_user_can('manage_network')` deleted from the guard entirely.
// What it protects is a burst of DDL and a hard rewrite flush on every site of the network,
// reachable by anyone who can load a network-admin URL.
$unprivileged = nab_run('resume-refuses-without-capability');
if ($unprivileged) {
    nab_same([], $unprivileged['inits'], 'no capability, no walk');
    nab_same(5, $unprivileged['left'], 'and the cursor is untouched');
    nab_same('', $unprivileged['noticeHtml'], 'and no notice');
}

$reactivated = nab_run('reactivation-clears-a-stale-marker');
if ($reactivated) {
    // The failure this pins: a marker surviving from an activation that died would make the
    // next activation SKIP whichever blog it names — either the one that died, which then
    // gets no attempt at all, or a healthy one, named in a degradation that never happened.
    nab_same([11, 12, 13], $reactivated['inits'], 'a re-activation attempts every site, including the one a stale marker named');
    nab_same(null, $reactivated['markerNow'], 'and the walk ends with no marker outstanding');
}

// ── 6. The poison pill: once is a retry, twice is a loop ─────────────────────

$poisoned = nab_run('poison-pill');
if ($poisoned) {
    nab_same(
        [12, 13],
        $poisoned['inits'],
        'a blog whose setup never returned is SKIPPED on the next pass, not started again — '
            . 'otherwise it blocks every site behind it forever, because a pass always starts '
            . 'its first site'
    );
    nab_same(0, $poisoned['left'], 'and the rest of the network completes');
    $skipped = json_encode($poisoned['options'][11]['slimstat_degradations'] ?? []);
    nab_check(
        false !== strpos((string) $skipped, 'blog 11'),
        'the skipped blog is recorded on ITS OWN options rather than dropped in silence, or '
            . 'filed against the main site; got: ' . $skipped
    );
}

// ── 7. Source level: the consumer is a DIFFERENT hook, and the deadline is compared ──
//
// The behavioural half above calls walk_pending_activation_sites() directly, so on its own it
// would pass even if nothing in the plugin ever called it. These assertions are what tie the
// resume to a hook WordPress will actually fire.

$source = slimstat_blank_comments((string) file_get_contents($plugin_root . '/wp-slimstat.php'));

// on_activate() is REQUIRED to exist, so its absence is a throw — a rename there is a
// different problem from the one this file is about. The other two are the methods this change
// introduces, and their absence IS the defect, so it is recorded as a failure and printed
// beside the behavioural ones rather than aborting the run before they are shown. That choice
// is made per call site on purpose; source-scan.php offers both forms for exactly this.
$activate = slimstat_function_body($source, 'on_activate');
$walk     = slimstat_find_function_body($source, 'walk_pending_activation_sites');
$resume   = slimstat_find_function_body($source, 'continue_network_activation');

nab_check(
    null !== $walk,
    'there is no walk_pending_activation_sites(): the activation hook walks every site itself, '
        . 'with no budget and no record of where it got to'
);
nab_check(
    null !== $resume,
    'there is no continue_network_activation(): nothing but the activation hook could resume a '
        . 'walk, and WordPress fires that once'
);

$walk   = (string) $walk;
$resume = (string) $resume;

nab_check(
    false !== strpos($activate, 'ACTIVATION_CURSOR_OPTION'),
    'on_activate() records the site list before walking it'
);

// The unbounded walk is GONE from the activation hook, not merely shorter. A loop here is the
// defect: whatever it iterates, the request that runs it is the one core has not yet recorded
// the activation for.
nab_check(
    false === strpos($activate, 'foreach') && false === strpos($activate, 'while'),
    'on_activate() no longer loops over sites itself — the walk is delegated to a budgeted pass'
);

nab_check(
    false !== strpos($resume, 'walk_pending_activation_sites'),
    'continue_network_activation() drives the same walk on a later request'
);

// COMPARED, not merely computed. A deadline that is assigned and never tested satisfies any
// "the constant is referenced twice" rule while bounding nothing — the bypass recorded against
// tests/migration-deadline-test.php, closed here rather than inherited.
// The same pattern tests/migration-deadline-test.php uses, both operand orders, and scoped
// to the walk's own body rather than the whole file — a comparison sitting in some unrelated
// helper would satisfy a file-wide scan.
nab_check(
    1 === preg_match(
        '/microtime\(\s*true\s*\)\s*>=?\s*\$deadline|\$deadline\s*<=?\s*microtime\(\s*true\s*\)/',
        $walk
    ),
    'the walk COMPARES the clock against its deadline, rather than only computing one (this '
        . 'reads `microtime(true) >= $deadline` and `$deadline <= microtime(true)`; widen it '
        . 'rather than dropping it if a correct spelling differs)'
);

nab_check(
    false !== strpos($walk, 'ACTIVATION_PASS_SECONDS'),
    'the budget comes from the declared constant'
);

// THE ORDERING THE MARKER DEPENDS ON, pinned at source level because no harness can kill its
// own request to observe it. The marker must be written BEFORE init_environment(): written
// after, it would only ever record blogs that already finished, and the poison-pill branch
// would never fire. The behavioural scenario seeds the marker instead, which proves it is
// CONSUMED but says nothing about when it is written.
$marker_write = strpos($walk, 'update_site_option(self::ACTIVATION_ATTEMPT_OPTION');
$work_call    = strpos($walk, 'wp_slimstat_admin::init_environment()');
nab_check(
    false !== $marker_write && false !== $work_call && $marker_write < $work_call,
    'the attempt marker is written before the work it marks, not after'
);

// VACUITY FLOOR. Every source assertion above reads a method body, and slimstat_function_body()
// throws on absence — but an empty-ish body would pass the two `false === strpos` checks by
// containing nothing. A rename that emptied these would otherwise read as a pass.
//
// Set well under the measured lengths on purpose: these kill a `return;` stub and nothing
// finer. Measured at the time of writing — activate 1557, walk 1972, resume 573 — against
// thresholds of 200/400/100, and the measure is comment-INCLUSIVE, because
// slimstat_blank_comments() preserves byte offsets. Raising them to a fraction of today's size
// would fail every future edit that shortens a method.
nab_check(
    strlen($activate) > 200 && strlen($walk) > 400 && strlen($resume) > 100,
    'the three method bodies were actually found and are not stubs (activate=' . strlen($activate)
        . ', walk=' . strlen($walk) . ', resume=' . strlen($resume) . ')'
);

if ($failures) {
    fwrite(STDERR, 'FAIL: network activation is not bounded and resumable (' . count($failures) . " problem(s))\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "OK: {$assertions} assertions passed (the network activation walk is budgeted, "
    . "persists after every site, and is resumed from a hook that fires again)\n");
exit(0);
