<?php
// Privacy audit items that had a fix but no test of their own: H3 (banner wording), M1 (legacy
// cookie-name settings hidden, not deleted), M4 (privacy policy text follows the settings),
// M7 (no avatar request unless a site opts in), L3 (readme names the outside services) and
// L6 (a comment that claimed the opposite of the code). Real code runs where it can, and every
// check has a control that shows it can fail.
namespace SlimStat\Heatmap {
    class Store { public static function capturing() { return !empty($GLOBALS['heatmap']); } }
}
namespace {
    define('ABSPATH', __DIR__);
    define('DAY_IN_SECONDS', 86400);
    require __DIR__ . '/lib/source-scan.php';
    $root = dirname(__DIR__);

    function __($text, $domain = '') { return $text; }
    function apply_filters($hook, $value, ...$args) { return array_key_exists($hook, $GLOBALS['filters'] ?? []) ? $GLOBALS['filters'][$hook] : $value; }
    function wp_kses($html, $allowed) { return $html; }
    function wp_kses_post($html) { return $html; }
    function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
    function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
    function current_user_can($capability) { return false; }
    function wp_add_privacy_policy_content($name, $content) { $GLOBALS['policy'] = $content; }
    function check($ok, string $message): void
    {
        if (!$ok) {
            throw new \RuntimeException($message);
        }
    }
    function has(string $haystack, string $needle): bool { return false !== strpos($haystack, $needle); }

    // H3. The default banner asks for a choice and offers both buttons.
    require $root . '/src/Services/GDPRService.php';
    $banner = ['gdpr_enabled' => 'on', 'use_slimstat_banner' => 'on', 'opt_out_message' => ''];
    $html = (new \SlimStat\Services\GDPRService($banner))->getBannerHtml();
    check(has($html, 'Accept or decline below') && !has(strtolower($html), 'by continuing'), 'H3: the default banner text must ask for a choice');
    check(has($html, 'data-consent="accepted"') && has($html, 'data-consent="denied"'), 'H3: the banner must offer Accept and Decline');
    $custom = (new \SlimStat\Services\GDPRService(['opt_out_message' => 'Our own notice'] + $banner))->getBannerHtml();
    check(has($custom, 'Our own notice') && !has($custom, 'Accept or decline below'), 'H3 control: a saved message must replace the default text');
    check('' === (new \SlimStat\Services\GDPRService(['gdpr_enabled' => 'off'] + $banner))->getBannerHtml(), 'H3: no banner markup while GDPR mode is off');
    foreach (slimstat_own_php_files([$root . '/admin', $root . '/src', $root . '/wp-slimstat.php'], $root . '/src/Dependencies') as $file) {
        check(!has(strtolower(file_get_contents($file)), 'by continuing'), 'H3: implied-consent wording in ' . slimstat_rel_path($root, $file));
    }

    // M7. Reports ask for no avatar unless the site opts in, and every avatar call sits behind that switch.
    require $root . '/src/Reports/ReportPrivacy.php';
    check(false === \SlimStat\Reports\ReportPrivacy::avatars(), 'M7: avatars must be off by default');
    $GLOBALS['filters'] = ['slimstat_report_avatars' => true];
    check(true === \SlimStat\Reports\ReportPrivacy::avatars(), 'M7 control: the slimstat_report_avatars filter must turn avatars on');
    $GLOBALS['filters'] = [];

    // ponytail: "guarded" means the switch is named earlier in the same statement or in the
    // condition of an enclosing if. An inverted ternary would pass; tokenise the branches if one appears.
    function slimstat_unguarded_avatar_calls(string $source): array
    {
        $tokens  = slimstat_tokenize($source);
        $names   = slimstat_name_token_types();
        $guarded = slimstat_guarded_block_ranges($tokens, 'avatars');
        $lines   = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || !isset($names[$token[0]])
                || !in_array(slimstat_last_name_segment($token[1]), ['get_avatar', 'get_avatar_url'], true)
                || '(' !== ($tokens[slimstat_next_significant($tokens, $i)] ?? null)) {
                continue;
            }
            $ok = false;
            foreach ($guarded as $range) {
                $ok = $ok || ($i > $range[0] && $i < $range[1]);
            }
            for ($k = $i - 1; !$ok && $k >= 0 && !in_array($tokens[$k], [';', '{', '}'], true); $k--) {
                $ok = is_array($tokens[$k]) && isset($names[$tokens[$k][0]]) && 'avatars' === slimstat_last_name_segment($tokens[$k][1]);
            }
            if (!$ok) {
                $lines[] = $token[2];
            }
        }
        return $lines;
    }
    check([2] === slimstat_unguarded_avatar_calls("<?php\necho get_avatar(\$id);"), 'M7 control: a bare get_avatar() call must be reported');
    check([3] === slimstat_unguarded_avatar_calls("<?php\n\$on = ReportPrivacy::avatars();\necho get_avatar_url(\$id);"), 'M7 control: a switch read in another statement is not a guard');
    check([] === slimstat_unguarded_avatar_calls('<?php echo ReportPrivacy::avatars() ? get_avatar($id) : "";'), 'M7 control: a guarded ternary must pass');
    check([] === slimstat_unguarded_avatar_calls('<?php if ($a && ReportPrivacy::avatars()) { if ($u) { $x = get_avatar_url($u); } }'), 'M7 control: a guarded block must pass');
    $avatar_files = 0;
    foreach (slimstat_own_php_files([$root . '/admin', $root . '/src', $root . '/wp-slimstat.php'], $root . '/src/Dependencies') as $file) {
        $source = file_get_contents($file);
        if (!has($source, 'get_avatar')) {
            continue;
        }
        $avatar_files++;
        $lines = slimstat_unguarded_avatar_calls($source);
        check([] === $lines, 'M7: avatar call outside ReportPrivacy::avatars() at ' . slimstat_rel_path($root, $file) . ':' . implode(',', $lines));
    }
    check($avatar_files >= 3, 'M7: the avatar scan found fewer files than the reports use, so it proves nothing');

    // M4. The suggested privacy policy text is built from the saved settings.
    $main = file_get_contents($root . '/wp-slimstat.php');
    eval('class PolicyProbe {
        public static $settings = [];
        public static function resolve_geolocation_provider() { return $GLOBALS["geo"] ?? false; }
        public static function text(array $settings) { self::$settings = $settings; $GLOBALS["policy"] = null; self::run(); return (string) $GLOBALS["policy"]; }
        public static function run() {' . slimstat_function_body($main, 'registerPrivacyPolicyContent') . '}
    }');
    $quiet = ['set_tracker_cookie' => 'off', 'ignore_wp_users' => 'on', 'use_slimstat_banner' => 'off', 'auto_purge' => '0', 'archive_retention_days' => '0', 'gdpr_enabled' => 'off', 'anonymous_tracking' => 'off'];
    $base  = PolicyProbe::text($quiet);
    check(has($base, 'SlimStat Analytics') && has($base, 'Browser fingerprint'), 'M4: the policy text was not produced');
    $follows = [
        'tracking cookie'       => [['set_tracker_cookie' => 'on'], 'A tracking cookie is used'],
        'logged-in users'       => [['ignore_wp_users' => 'off'], 'your username and email may be associated'],
        'consent cookie'        => [['use_slimstat_banner' => 'on'], 'The SlimStat consent cookie remembers your choice for one year'],
        'archive after N days'  => [['auto_purge' => '90', 'auto_purge_delete' => 'on'], 'older than 90 days are moved to archive tables'],
        'delete after N days'   => [['auto_purge' => '90', 'auto_purge_delete' => 'no'], 'older than 90 days are permanently deleted'],
        'archive lifetime'      => [['archive_retention_days' => '400'], 'Archived records older than 400 days'],
        'privacy tools'         => [['gdpr_enabled' => 'on'], 'WordPress Privacy Tools'],
        'anonymous mode'        => [['anonymous_tracking' => 'on'], 'Anonymous Tracking Mode'],
    ];
    foreach ($follows as $name => $case) {
        check(!has($base, $case[1]), "M4 control ($name): the line must be absent while the setting is off");
        check(has(PolicyProbe::text($case[0] + $quiet), $case[1]), "M4 ($name): the policy text must follow the setting");
    }
    check(has($base, 'Automatic deletion of live analytics records is disabled') && has($base, 'retained indefinitely'), 'M4: no retention period must be stated as no deletion');
    $GLOBALS['heatmap'] = true;
    $GLOBALS['geo'] = 'dbip';
    $extra = PolicyProbe::text($quiet);
    check(has($extra, 'Heatmaps:') && has($extra, 'Location:') && !has($base, 'Heatmaps:') && !has($base, 'Location:'), 'M4: heatmap and location lines must follow the features');

    // M1. The two legacy cookie-name settings have no row on the Settings page; their saved values stay.
    $config = file_get_contents($root . '/admin/config/index.php');
    $legacy = file_get_contents($root . '/src/Migration/LegacySettings5460.php');
    $defaults = slimstat_function_body($main, 'init_options');
    foreach (['opt_out_cookie_names', 'opt_in_cookie_names'] as $key) {
        check(!has($config, $key), "M1: $key must not be offered on the Settings page");
        check(has($defaults, "'$key'") && has($legacy, "'$key'"), "M1: $key must stay a known option so a saved value is kept");
    }
    check(has($config, "'opt_out_message'"), 'M1 control: the Settings page source was not read');

    // L3. The readme names every outside service in one section.
    $readme = file_get_contents($root . '/readme.txt');
    $from = strpos($readme, '== Privacy and external services ==');
    check(false !== $from, 'L3: readme.txt needs a "Privacy and external services" section');
    $section = substr($readme, $from, strpos($readme, "\n== ", $from + 10) - $from);
    foreach (['DB-IP', 'jsDelivr', 'MaxMind', 'GitHub', 'FingerprintJS monitoring is disabled', 'slimstat_report_avatars'] as $term) {
        check(has($section, $term), "L3: the readme privacy section must name $term");
    }
    check(!has($section, 'Frequently Asked Questions'), 'L3 control: the section must end at the next heading');

    // L6. The comment that said anonymous tracking was on by default is gone, and the default is off.
    foreach (slimstat_own_php_files([$root . '/admin', $root . '/src', $root . '/wp-slimstat.php'], $root . '/src/Dependencies') as $file) {
        check(!has(strtolower(file_get_contents($file)), 'enable anonymous tracking by default'), 'L6: misleading comment in ' . slimstat_rel_path($root, $file));
    }
    check(1 === preg_match("/'anonymous_tracking'\s*=>\s*'off'/", $defaults), 'L6: anonymous_tracking must default to off');

    echo "PASS: banner wording, avatar switch, privacy policy text, legacy cookie settings, readme services and the anonymous-tracking default hold\n";
}
