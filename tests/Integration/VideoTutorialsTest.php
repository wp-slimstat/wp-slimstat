<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use Brain\Monkey\Functions;
use SlimStat\Services\Admin\VideoTutorials;

/**
 * SlimStat > Videos lists tutorials from a static catalog on wp-slimstat.com, and marks the
 * SlimStat menu when new ones are published.
 *
 * The PHP half is deliberately inert: it registers the screen and enqueues two scripts. The
 * catalog is fetched by the browser, after an opt-in, and every piece of state lives in that
 * browser (tests/video-catalog.cjs covers the client, tests/e2e/video-tutorials.spec.ts the page).
 * What is pinned here is the server-side cost and reach of the feature:
 *   - the screen sits in the sidebar between Settings and Upgrade to Pro, for anyone who can
 *     view stats, with no "New"/"Pro" pill anchor (an anchor is an option write);
 *   - the marker script loads exactly where WordPress added the Videos item, so a user denied
 *     it, or a filter that removes it, gets no script;
 *   - the page's own stylesheet and renderer load on that screen and nowhere else;
 *   - nothing on the server writes options, user meta, transients or cron, or makes an HTTP
 *     request, and the scripts never build HTML from strings.
 */
class VideoTutorialsTest extends IntegrationTestCase
{
    /** Server-side I/O the feature must never perform. */
    private const FORBIDDEN = [
        'wp_remote_get', 'wp_remote_post', 'wp_remote_request', 'wp_safe_remote_get', 'wp_safe_remote_post', 'wp_safe_remote_request',
        'update_option', 'add_option', 'update_site_option', 'set_transient', 'set_site_transient',
        'update_user_meta', 'update_user_option', 'wp_schedule_event', 'wp_schedule_single_event',
    ];

    /** @var array<string, array{0: string, 1: array, 2: mixed}> handle => [src, deps, footer] */
    private array $scripts = [];
    /** @var array<string, array{0: string, 1: array}> handle => [src, deps] */
    private array $styles = [];
    /** @var array<string, array<string, mixed>> "handle:object" => data */
    private array $localized = [];
    private int $userId = 7;
    private string $home = 'https://example.test/';

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_enqueue_script')->alias(function ($handle, $src = '', $deps = [], $ver = false, $footer = false) {
            $this->scripts[$handle] = [$src, $deps, $footer];
            if (false !== strpos($handle, 'video')) {
                $this->assertSame(SLIMSTAT_ANALYTICS_VERSION, $ver, "$handle is versioned with the plugin");
            }
        });
        Functions\when('wp_enqueue_style')->alias(function ($handle, $src = '', $deps = []) {
            $this->styles[$handle] = [$src, $deps];
        });
        Functions\when('wp_register_style')->justReturn(true);
        Functions\when('wp_add_inline_style')->justReturn(true);
        Functions\when('wp_localize_script')->alias(function ($handle, $name, $data) {
            $this->localized[$handle . ':' . $name] = $data;
            return true;
        });
        Functions\when('wp_script_is')->alias(fn ($handle) => isset($this->scripts[$handle]));
        Functions\when('get_current_user_id')->alias(fn () => $this->userId);
        Functions\when('home_url')->alias(fn ($path = '') => $this->home . ltrim((string) $path, '/'));
        foreach (self::FORBIDDEN as $function) {
            Functions\expect($function)->never();
        }
        \wp_slimstat::$settings['display_notifications'] = 'off';
        \wp_slimstat_admin::$meta_user_reports = [];
        \wp_slimstat_admin::$current_screen = 'slimview1';
    }

    /** Runs add_menus() over a sidebar holding one report screen and the Videos screen. */
    private function buildMenu(?array $screens = null): void
    {
        $screens ??= [
            'slimview1' => ['is_report_group' => true, 'show_in_sidebar' => true, 'title' => 'Real-time', 'capability' => 'can_view', 'callback' => '__return_null'],
            VideoTutorials::SCREEN => ['is_report_group' => false, 'show_in_sidebar' => true, 'title' => 'Videos', 'capability' => 'can_view', 'callback' => '__return_null'],
        ];
        \wp_slimstat_admin::$screens_info = $screens;
        Functions\when('add_menu_page')->justReturn('toplevel_page_slimview1');
        // WordPress returns false instead of a hook for a user without the capability.
        Functions\when('add_submenu_page')->alias(fn ($parent, $title, $label, $cap, $slug) => $this->capability ? 'slimstat_page_' . $slug : false);
        \wp_slimstat_admin::add_menus();
    }

    private function markerHooked(): bool
    {
        return false !== has_action('admin_enqueue_scripts', [VideoTutorials::class, 'enqueue_client']);
    }

    public function test_the_screen_is_registered_between_settings_and_upgrade_to_pro(): void
    {
        $source = self::adminSource();
        preg_match_all("/^\s+'(\w+)'\s*=>\s*\[\s*$/m", $source, $m);
        $at = array_search(VideoTutorials::SCREEN, $m[1], true);
        $this->assertNotFalse($at, 'slimvideos is a key in $screens_info');
        $this->assertSame(['slimconfig', 'slimvideos', 'slimpro'], array_slice($m[1], $at - 1, 3), 'Videos follows Settings and precedes Upgrade to Pro');

        $entry = self::screenEntry($source, VideoTutorials::SCREEN);
        $this->assertMatchesRegularExpression("/'show_in_sidebar'\s*=>\s*true,/", $entry);
        $this->assertMatchesRegularExpression("/'is_report_group'\s*=>\s*false,/", $entry, 'Customize never offers it as a place for reports');
        $this->assertMatchesRegularExpression("/'title'\s*=>\s*__\('Videos', 'wp-slimstat'\),/", $entry);
        $this->assertMatchesRegularExpression("/'capability'\s*=>\s*'can_view',/", $entry, 'Whoever can view stats can watch the tutorials');
        $this->assertMatchesRegularExpression("/'callback'\s*=>\s*\[self::class, 'wp_slimstat_include_videos'\],/", $entry);
        $this->assertDoesNotMatchRegularExpression("/'new_badge'|'pro_badge'/", $entry, 'A New pill anchor is an option write; the marker is client-side');
        $this->assertTrue(method_exists('wp_slimstat_admin', 'wp_slimstat_include_videos'));
    }

    public function test_the_marker_loads_where_wordpress_added_the_videos_item(): void
    {
        $this->buildMenu();
        $this->assertTrue($this->markerHooked());
    }

    public function test_the_marker_does_not_load_for_a_user_denied_the_item(): void
    {
        $this->capability = false;
        $this->buildMenu();
        $this->assertFalse($this->markerHooked());
    }

    public function test_the_marker_does_not_load_when_a_filter_removes_the_screen(): void
    {
        $this->buildMenu(['slimview1' => ['is_report_group' => true, 'show_in_sidebar' => true, 'title' => 'Real-time', 'capability' => 'can_view', 'callback' => '__return_null']]);
        $this->assertFalse($this->markerHooked(), 'removing slimvideos through slimstat_screens_info turns the feature off');
    }

    public function test_the_marker_does_not_load_when_the_layout_hides_the_item(): void
    {
        \wp_slimstat_admin::$meta_user_reports = [VideoTutorials::SCREEN => ''];
        $this->buildMenu();
        $this->assertFalse($this->markerHooked());
    }

    public function test_the_client_config_is_per_site_and_per_user(): void
    {
        VideoTutorials::enqueue_client();
        [$src, $deps, $footer] = $this->scripts['slimstat-video-catalog'];
        $this->assertStringEndsWith('admin/assets/js/video-catalog.js', $src);
        $this->assertSame([], $deps, 'no jQuery or wp-* dependency on every admin page');
        $this->assertTrue($footer);
        $this->assertSame([], $this->styles, 'its few CSS rules ride on the menu style already printed on every admin page');

        $config = $this->localized['slimstat-video-catalog:SlimStatVideoConfig'];
        $this->assertSame('https://wp-slimstat.com/videos/videos.json', $config['catalogUrl']);
        $this->assertSame('https://wp-slimstat.com', $config['origin']);
        $this->assertSame('https://wp-slimstat.com/videos/', $config['watchBase']);
        $this->assertSame('slimvideos', $config['screen']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{12}$/', $config['scope'], 'the scope never exposes the user ID or site URL');

        // Enqueued twice (the page's load- hook, then admin_enqueue_scripts): localized once.
        $this->localized = [];
        VideoTutorials::enqueue_client();
        $this->assertSame([], $this->localized);

        // Another user, then another site on the same origin: separate scopes.
        $scopes = [$config['scope']];
        foreach ([[8, 'https://example.test/'], [7, 'https://example.test/second/']] as [$user, $home]) {
            $this->scripts = $this->localized = [];
            [$this->userId, $this->home] = [$user, $home];
            VideoTutorials::enqueue_client();
            $scopes[] = $this->localized['slimstat-video-catalog:SlimStatVideoConfig']['scope'];
        }
        $this->assertCount(3, array_unique($scopes));
    }

    public function test_the_page_assets_load_on_the_videos_screen_only(): void
    {
        foreach (['slimview1', 'slimconfig'] as $screen) {
            \wp_slimstat_admin::$current_screen = $screen;
            \wp_slimstat_admin::wp_slimstat_stylesheet();
            $this->assertArrayNotHasKey('wp-slimstat-videos', $this->styles, "nothing of the Videos page on $screen");
            $this->assertArrayNotHasKey('slimstat-videos', $this->scripts);
        }

        \wp_slimstat_admin::$current_screen = VideoTutorials::SCREEN;
        \wp_slimstat_admin::wp_slimstat_stylesheet();
        $this->assertStringEndsWith('admin/assets/css/videos.css', $this->styles['wp-slimstat-videos'][0]);
        $this->assertSame(['wp-slimstat-tokens'], $this->styles['wp-slimstat-videos'][1], 'built on the shared design tokens');
        $this->assertArrayHasKey('wp-slimstat-header-modern', $this->styles, 'and the shared SlimStat header styles');
        $this->assertStringEndsWith('admin/assets/js/videos.js', $this->scripts['slimstat-videos'][0]);
        $this->assertSame(['slimstat-video-catalog'], $this->scripts['slimstat-videos'][1]);
        $this->assertArrayHasKey('slimstat-video-catalog', $this->scripts, 'the page brings its own client');

        $page = $this->localized['slimstat-videos:SlimStatVideosPage'];
        $this->assertSame(
            ['getting-started', 'dashboard-reports', 'real-time', 'woocommerce', 'heatmaps', 'goals-funnels', 'advanced', 'pro'],
            array_column($page['categories'], 'id'),
            'the eight built-in categories, in their default order'
        );
        $this->assertNotContains('', array_column($page['categories'], 'label'));
        foreach (['more', 'newTab', 'loading', 'loaded', 'offline'] as $key) {
            $this->assertIsString($page[$key] ?? null, "$key copy is localized");
        }
    }

    public function test_the_view_guards_access_and_ships_every_state(): void
    {
        $view = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/view/videos.php');
        $this->assertMatchesRegularExpression("/if \(!defined\('ABSPATH'\)\)/", $view);
        $this->assertStringContainsString('wp_slimstat_admin::can_view_stats()', $view);
        foreach (['consent', 'loading', 'error', 'empty', 'ready'] as $state) {
            $this->assertMatchesRegularExpression('/data-show="[^"]*\b' . $state . '\b/', $view, "the $state state is rendered");
        }
        $this->assertStringContainsString('<noscript>', $view);
        $markup = (string) preg_replace('/<\?php.*?\?>/s', '', $view);
        $this->assertGreaterThan(0, substr_count($markup, 'target="_blank"'));
        $this->assertSame(substr_count($markup, 'target="_blank"'), substr_count($markup, 'target="_blank" rel="noopener noreferrer"'), 'every new-tab link drops opener and referrer');
    }

    public function test_the_feature_never_stores_or_fetches_server_side(): void
    {
        $php = file_get_contents(dirname(__DIR__, 2) . '/src/Services/Admin/VideoTutorials.php') . file_get_contents(dirname(__DIR__, 2) . '/admin/view/videos.php');
        foreach (array_merge(self::FORBIDDEN, ['setcookie', 'curl_', 'file_get_contents', 'fopen', '$wpdb']) as $call) {
            $this->assertStringNotContainsString($call, $php);
        }
    }

    public function test_the_scripts_build_the_dom_without_html_strings(): void
    {
        foreach (['admin/assets/js/video-catalog.js', 'admin/assets/js/videos.js'] as $file) {
            $js = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);
            foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'jQuery', 'setInterval'] as $sink) {
                $this->assertStringNotContainsString($sink, $js, "$file must not use $sink");
            }
        }
    }

    public function test_the_readme_discloses_the_service(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/readme.txt');
        $this->assertStringContainsString(VideoTutorials::CATALOG_URL, $readme);
        $this->assertStringContainsString('https://wp-slimstat.com/privacy-policy/', $readme);
        $this->assertMatchesRegularExpression('/Videos.{0,400}(opt|choose|turn)/is', $readme, 'and says it waits for the user to choose');
    }
}
