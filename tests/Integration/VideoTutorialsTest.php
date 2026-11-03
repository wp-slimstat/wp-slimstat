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
 * browser (tests/video-catalog.cjs covers the client). What is pinned here is the server-side
 * cost and reach of the feature:
 *   - the screen sits in the sidebar between Settings and Upgrade to Pro, for anyone who can
 *     view stats, with no "New"/"Pro" pill anchor (an anchor is an option write);
 *   - the marker script loads only for users who can see the menu, and only while the screen is
 *     registered, so filtering the screen out of slimstat_screens_info turns the feature off;
 *   - the page's own stylesheet and renderer load on that screen and nowhere else;
 *   - nothing on the server reads or writes options, user meta, transients or cron, or makes an
 *     HTTP request, and the scripts never build HTML from strings.
 */
class VideoTutorialsTest extends IntegrationTestCase
{
    /** @var array<string, array{0: string, 1: string, 2: array, 3: mixed, 4: mixed}> */
    private array $scripts = [];
    /** @var array<string, array{0: string, 1: string, 2: array}> */
    private array $styles = [];
    /** @var array<string, array<string, mixed>> */
    private array $localized = [];
    private int $userId = 7;
    private string $home = 'https://example.test/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->scripts = $this->styles = $this->localized = [];
        Functions\when('wp_enqueue_script')->alias(function ($handle, $src = '', $deps = [], $ver = false, $footer = false) {
            $this->scripts[$handle] = [$handle, $src, $deps, $ver, $footer];
        });
        Functions\when('wp_enqueue_style')->alias(function ($handle, $src = '', $deps = [], $ver = false) {
            $this->styles[$handle] = [$handle, $src, $deps];
        });
        Functions\when('wp_localize_script')->alias(function ($handle, $name, $data) {
            $this->localized[$handle . ':' . $name] = $data;
            return true;
        });
        Functions\when('wp_script_is')->alias(fn ($handle) => isset($this->scripts[$handle]));
        Functions\when('get_current_user_id')->alias(fn () => $this->userId);
        Functions\when('home_url')->alias(fn ($path = '') => $this->home . ltrim((string) $path, '/'));
        foreach (['wp_remote_get', 'wp_remote_post', 'wp_remote_request', 'wp_safe_remote_get', 'wp_safe_remote_request', 'update_user_meta', 'add_option', 'update_site_option', 'wp_schedule_event', 'wp_schedule_single_event'] as $forbidden) {
            Functions\expect($forbidden)->never();
        }
        Functions\expect('update_option')->never();
        Functions\expect('set_transient')->never();
        \wp_slimstat_admin::$screens_info = [VideoTutorials::SCREEN => ['show_in_sidebar' => true]];
        \wp_slimstat_admin::$current_screen = 'slimview1';
    }

    private static function source(string $relative): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
        self::assertNotFalse($source, "Could not read $relative");
        return $source;
    }

    public function test_the_screen_is_registered_between_settings_and_upgrade_to_pro(): void
    {
        $source = self::source('admin/index.php');
        preg_match_all("/^ {12}'(\w+)' => \[\s*$/m", substr($source, (int) strpos($source, 'self::$screens_info = ['), 12000), $m);
        $order = $m[1];
        $at = array_search(VideoTutorials::SCREEN, $order, true);
        $this->assertNotFalse($at, 'slimvideos is a key in $screens_info: ' . implode(', ', $order));
        $this->assertSame('slimconfig', $order[$at - 1], 'Videos follows Settings');
        $this->assertSame('slimpro', $order[$at + 1], 'and precedes Upgrade to Pro');

        $found = preg_match("/^ {12}'slimvideos' => \[(.*?)^ {12}\],/ms", $source, $entry);
        $this->assertSame(1, $found);
        $this->assertMatchesRegularExpression("/'show_in_sidebar'\s*=>\s*true,/", $entry[1]);
        $this->assertMatchesRegularExpression("/'is_report_group'\s*=>\s*false,/", $entry[1], 'Customize never offers it as a place for reports');
        $this->assertMatchesRegularExpression("/'title'\s*=>\s*__\('Videos', 'wp-slimstat'\),/", $entry[1]);
        $this->assertMatchesRegularExpression("/'capability'\s*=>\s*'can_view',/", $entry[1], 'Whoever can view stats can watch the tutorials');
        $this->assertMatchesRegularExpression("/'callback'\s*=>\s*\[self::class, 'wp_slimstat_include_videos'\],/", $entry[1]);
        $this->assertDoesNotMatchRegularExpression("/'new_badge'|'pro_badge'/", $entry[1], 'A New pill anchor is an option write; the marker is client-side');
        $this->assertTrue(method_exists('wp_slimstat_admin', 'wp_slimstat_include_videos'));
    }

    public function test_the_marker_script_loads_for_viewers_with_per_site_per_user_scope(): void
    {
        \wp_slimstat_admin::enqueue_video_catalog();

        $this->assertArrayHasKey('slimstat-video-catalog', $this->scripts);
        [, $src, $deps, $ver, $footer] = $this->scripts['slimstat-video-catalog'];
        $this->assertStringEndsWith('admin/assets/js/video-catalog.js', $src);
        $this->assertSame([], $deps, 'no jQuery or wp-* dependency on every admin page');
        $this->assertSame(SLIMSTAT_ANALYTICS_VERSION, $ver);
        $this->assertTrue($footer);
        $this->assertSame([], $this->styles, 'its few CSS rules ride on the menu style already printed on every admin page');

        $config = $this->localized['slimstat-video-catalog:SlimStatVideoConfig'];
        $this->assertSame('https://wp-slimstat.com/videos/videos.json', $config['catalogUrl']);
        $this->assertSame('https://wp-slimstat.com', $config['origin']);
        $this->assertSame('https://wp-slimstat.com/videos/', $config['watchBase']);
        $this->assertSame('slimvideos', $config['screen']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{12}$/', $config['scope'], 'the scope never exposes the user ID or site URL');

        // Another user, then another site on the same origin: separate scopes.
        $scopes = [$config['scope']];
        foreach ([[8, 'https://example.test/'], [7, 'https://example.test/second/']] as [$user, $home]) {
            $this->scripts = $this->localized = [];
            [$this->userId, $this->home] = [$user, $home];
            \wp_slimstat_admin::enqueue_video_catalog();
            $scopes[] = $this->localized['slimstat-video-catalog:SlimStatVideoConfig']['scope'];
        }
        $this->assertCount(3, array_unique($scopes));
    }

    public function test_the_marker_script_is_not_loaded_without_the_menu(): void
    {
        $this->capability = false;
        \wp_slimstat_admin::enqueue_video_catalog();
        $this->assertSame([], $this->scripts, 'a user who cannot see the SlimStat menu gets nothing');

        $this->capability = true;
        \wp_slimstat_admin::$screens_info = [];
        \wp_slimstat_admin::enqueue_video_catalog();
        $this->assertSame([], $this->scripts, 'filtering the screen out of slimstat_screens_info turns the feature off');

        \wp_slimstat_admin::$screens_info = [VideoTutorials::SCREEN => ['show_in_sidebar' => false]];
        \wp_slimstat_admin::enqueue_video_catalog();
        $this->assertSame([], $this->scripts);
    }

    public function test_enqueueing_twice_localizes_once(): void
    {
        VideoTutorials::enqueue_client();
        $this->localized = [];
        VideoTutorials::enqueue_client();
        $this->assertSame([], $this->localized);
    }

    public function test_the_page_assets_load_on_the_videos_screen_only(): void
    {
        $source = self::source('admin/index.php');
        $this->assertSame(1, preg_match_all('/VideoTutorials::enqueue_page\(\)/', $source), 'one call site');
        $this->assertMatchesRegularExpression('/if \(VideoTutorials::SCREEN === self::\$current_screen\) \{\s*VideoTutorials::enqueue_page\(\);/', $source);
        foreach (['videos.css', 'videos.js'] as $asset) {
            $this->assertStringNotContainsString($asset, $source, "$asset is enqueued by VideoTutorials::enqueue_page() only");
        }

        VideoTutorials::enqueue_page();
        $this->assertStringEndsWith('admin/assets/css/videos.css', $this->styles['wp-slimstat-videos'][1]);
        $this->assertSame(['wp-slimstat-tokens'], $this->styles['wp-slimstat-videos'][2], 'built on the shared design tokens');
        $this->assertStringEndsWith('admin/assets/js/videos.js', $this->scripts['slimstat-videos'][1]);
        $this->assertSame(['slimstat-video-catalog'], $this->scripts['slimstat-videos'][2]);
        $this->assertArrayHasKey('slimstat-video-catalog', $this->scripts, 'the page brings its own client');

        $page = $this->localized['slimstat-videos:SlimStatVideosPage'];
        $this->assertSame(
            ['getting-started', 'dashboard-reports', 'real-time', 'woocommerce', 'heatmaps', 'goals-funnels', 'advanced', 'pro'],
            array_column($page['categories'], 'id'),
            'the eight built-in categories, in their default order'
        );
        foreach ($page['categories'] as $category) {
            $this->assertNotSame('', $category['label']);
        }
        foreach (['more', 'newPill', 'newTab', 'loading', 'loaded', 'failed', 'offline'] as $key) {
            $this->assertIsString($page[$key] ?? null, "$key copy is localized");
        }
    }

    public function test_the_view_guards_access_and_ships_every_state(): void
    {
        $view = self::source('admin/view/videos.php');
        $this->assertMatchesRegularExpression("/if \(!defined\('ABSPATH'\)\)/", $view);
        $this->assertStringContainsString('wp_slimstat_admin::can_view_stats()', $view);
        foreach (['consent', 'loading', 'error', 'empty', 'ready'] as $state) {
            $this->assertMatchesRegularExpression('/data-show="[^"]*\b' . $state . '\b/', $view, "the $state state is rendered");
        }
        $this->assertStringContainsString('<noscript>', $view);
        $this->assertSame(0, preg_match('/<a\b(?![^>]*rel="noopener noreferrer")[^>]*target="_blank"/', $view), 'every new-tab link drops opener and referrer');
    }

    public function test_the_server_side_never_stores_or_fetches_anything(): void
    {
        $php = self::source('src/Services/Admin/VideoTutorials.php') . self::source('admin/view/videos.php');
        foreach (['wp_remote_', 'wp_safe_remote_', 'curl_', 'file_get_contents', 'fopen', 'update_option', 'add_option', 'set_transient', 'set_site_transient', 'update_user_meta', 'update_user_option', 'wp_schedule', '$wpdb', 'setcookie'] as $call) {
            $this->assertStringNotContainsString($call, $php);
        }
    }

    public function test_the_scripts_build_the_dom_without_html_strings(): void
    {
        foreach (['admin/assets/js/video-catalog.js', 'admin/assets/js/videos.js'] as $file) {
            $js = self::source($file);
            foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'jQuery', 'setInterval'] as $sink) {
                $this->assertStringNotContainsString($sink, $js, "$file must not use $sink");
            }
        }
        $page = self::source('admin/assets/js/videos.js');
        $this->assertStringContainsString("link.target = '_blank';", $page);
        $this->assertStringContainsString("link.rel = 'noopener noreferrer';", $page);
        $this->assertStringContainsString('api.watchUrl(', $page, 'destinations come from the slug, never from the catalog');
    }

    public function test_the_readme_discloses_the_service(): void
    {
        $readme = self::source('readme.txt');
        $this->assertStringContainsString(VideoTutorials::CATALOG_URL, $readme);
        $this->assertStringContainsString('https://wp-slimstat.com/privacy-policy/', $readme);
        $this->assertMatchesRegularExpression('/Videos.{0,400}(opt|choose|turn)/is', $readme, 'and says it waits for the user to choose');
    }
}
