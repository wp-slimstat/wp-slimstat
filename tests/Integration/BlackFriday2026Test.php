<?php
/** @license GPL-2.0-or-later */
declare(strict_types=1);
namespace WpSlimstat\Tests\Integration;
use Brain\Monkey\Functions;
use SlimStat\Services\BlackFriday2026;

/** The Black Friday strip: who sees it, where it links, and what it never touches. */
class BlackFriday2026Test extends IntegrationTestCase
{
    private array $caps = ['manage_options' => true, 'read' => true];
    private array $meta = [];
    private string $now = '2026-11-27 12:00:00';
    private string $screen = 'dashboard';
    protected function setUp(): void
    {
        parent::setUp();
        BlackFriday2026::reset_for_tests();
        $_COOKIE = [];
        Functions\when('current_user_can')->alias(fn($cap) => $this->caps[$cap] ?? false);
        Functions\when('current_datetime')->alias(fn() => new \DateTimeImmutable($this->now, new \DateTimeZone('Asia/Tehran')));
        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('Asia/Tehran'));
        Functions\when('wp_date')->alias(fn($format, $ts, $tz) => (new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->format($format));
        Functions\when('get_current_user_id')->justReturn(7);
        Functions\when('wp_parse_url')->alias(fn($url, $part = -1) => parse_url($url, $part));
        Functions\when('get_current_screen')->alias(fn() => (object) ['id' => $this->screen]);
        Functions\when('get_user_meta')->alias(fn($id, $key) => $this->meta[$key] ?? '');
        Functions\when('update_user_meta')->alias(function ($id, $key, $value) { $this->meta[$key] = $value; return true; });
        Functions\when('_x')->alias(fn($text) => $text);
        Functions\when('add_user_meta')->alias(function ($id, $key, $value) { $this->meta[$key] = $value; return true; });
    }
    protected function tearDown(): void
    {
        $_COOKIE = [];
        parent::tearDown();
    }
    private function render(string $placement = 'bar', bool $pro = false, bool $migration = false): string
    {
        $html = $this->printed_by(static function () use ($placement, $pro, $migration, &$printed) { $printed = BlackFriday2026::render($placement, $pro, $migration); });
        $this->assertSame('' !== $html, $printed, 'render() reports whether it printed');
        return $html;
    }
    public function test_a_free_admin_sees_the_offer_with_code_link_and_dismiss(): void
    {
        $html = $this->render();
        $this->assertSame(1, substr_count($html, 'id="slimstat-bf-2026"'));
        foreach (['role="region"', 'aria-label="Black Friday offer"', 'Black Friday: 30% off SlimStat Pro', '<code class="slimstat-bf26__code" dir="ltr">BFCM2026</code>', 'Copy code', 'aria-label="Dismiss Black Friday offer"', 'role="status"', 'Ends November 30.', 'target="_blank"', 'rel="noopener"', '(opens in a new tab)', 'data-slimstat-bf26-cookie="slimstat_bf2026_dismissed_7"', 'data-slimstat-bf26-path="/wp-admin/"'] as $value) {
            $this->assertStringContainsString($value, $html);
        }
        $this->assertStringContainsString('href="https://wp-slimstat.com/pricing/?utm_source=wp-slimstat&utm_medium=link&utm_campaign=bf-2026&utm_content=banner"', $html);
        $this->assertStringNotContainsString('class="notice', $html, 'the SlimStat-screen strip is not a core notice, so common.js leaves it in place');
        $this->assertStringNotContainsString('$', $html, 'no dollar prices');
        $this->assertStringNotContainsString('—', $html, 'no em dashes');
        preg_match_all('/class="([^"]+)"/', $html, $m);
        foreach (preg_split('/\s+/', implode(' ', $m[1])) as $class) {
            $this->assertMatchesRegularExpression('/^(slimstat-bf26__[a-z]+|screen-reader-text)$/', $class, 'every class is namespaced');
        }
        $this->assertSame('', $this->meta['slimstat_release_60_since'] ?? '', 'the release bar keeps its 14-day clock');
    }
    public function test_the_dashboard_placement_is_a_core_notice_with_its_own_attribution(): void
    {
        $html = $this->render('dashboard');
        $this->assertStringContainsString('id="slimstat-bf-2026" class="notice"', $html);
        $this->assertStringContainsString('utm_content=dashboard', $html);
        $this->assertStringNotContainsString('is-dismissible', $html, 'core would add a second dismiss button');
    }
    public function test_it_renders_once_per_request(): void
    {
        $this->assertNotSame('', $this->render());
        $this->assertSame('', $this->render());
        $this->assertSame('', $this->render('dashboard'));
    }
    /** @return array<string, array{0: callable}> */
    public static function hiddenStates(): array
    {
        return [
            'before the window' => [fn(self $t) => $t->now = '2026-11-22 23:59:59'],
            'after the window' => [fn(self $t) => $t->now = '2026-12-01 00:00:00'],
            'filter returns false' => [fn(self $t) => $t->filterValues['slimstat_black_friday_2026_banner'] = false],
            'dismissed by this user' => [fn() => $_COOKIE['slimstat_bf2026_dismissed_7'] = '1'],
            'notifications off' => [fn() => \wp_slimstat::$settings['display_notifications'] = 'no'],
            'no manage_options' => [fn(self $t) => $t->caps['manage_options'] = false],
            'cannot view stats' => [fn(self $t) => $t->caps['read'] = false],
        ];
    }
    /** @dataProvider hiddenStates */
    public function test_hidden_states_print_nothing(callable $arrange): void
    {
        $arrange($this);
        $this->assertSame('', $this->render());
    }
    public function test_pro_and_an_owed_migration_hide_it(): void
    {
        $this->assertSame('', $this->render('bar', true));
        $this->assertSame('', $this->render('bar', false, true));
        $this->assertNotSame('', $this->render(), 'nothing above marked the strip rendered');
    }
    public function test_visible_states_that_look_like_hidden_ones(): void
    {
        unset(\wp_slimstat::$settings['display_notifications']);
        $_COOKIE['slimstat_bf2026_dismissed_8'] = '1';
        $this->filterValues['slimstat_black_friday_2026_banner'] = '1';
        $this->now = '2026-11-23 00:00:00';
        $this->assertNotSame('', $this->render(), 'missing setting, another user\'s cookie, truthy filter and the first second all show it');
    }
    public function test_a_filter_can_force_the_date_but_not_the_other_gates(): void
    {
        $this->now = '2026-10-01 12:00:00';
        $this->filterValues['slimstat_black_friday_2026_banner'] = true;
        $this->caps['manage_options'] = false;
        $this->assertSame('', $this->render());
        $this->caps['manage_options'] = true;
        $this->assertNotSame('', $this->render());
    }
    public function test_translations_are_escaped(): void
    {
        Functions\when('_x')->alias(fn($text, $context) => 'discount code label' === $context ? '<script>x</script>' : $text);
        Functions\when('esc_html')->alias(fn($v) => htmlspecialchars((string) $v, ENT_QUOTES));
        Functions\when('esc_attr')->alias(fn($v) => htmlspecialchars((string) $v, ENT_QUOTES));
        $html = $this->render();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
    public function test_dashboard_notice_replaces_the_release_notice_only_when_it_renders(): void
    {
        $release = ['wp_slimstat_admin', 'show_latest_news'];
        add_action('admin_notices', $release, 10, 0);
        $this->screen = 'dashboard_page_other-plugin';
        $this->assertSame('', $this->capture());
        $this->assertNotFalse(has_action('admin_notices', $release), 'other index.php subpages are left alone');
        $this->screen = 'dashboard';
        $this->caps['manage_options'] = false;
        $this->assertSame('', $this->capture());
        $this->assertNotFalse(has_action('admin_notices', $release), 'no strip, so the release notice keeps its slot');
        $this->caps['manage_options'] = true;
        $html = $this->capture();
        $this->assertStringContainsString('class="notice"', $html);
        $this->assertFalse(has_action('admin_notices', $release), 'the strip and the release notice never stack');
    }
    private function capture(): string
    {
        return $this->printed_by([BlackFriday2026::class, 'dashboard_notice']);
    }
    private function printed_by(callable $fn): string
    {
        ob_start();
        try { $fn(); return (string) ob_get_contents(); }
        finally { ob_end_clean(); }
    }
}
