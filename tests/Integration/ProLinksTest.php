<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use Brain\Monkey\Functions;

/**
 * Free's Pro links: administrators land on the matching section of the in-plugin Pro page
 * (#ss-pro-share for Export, #ss-pro-converts for Channels); users who cannot buy get no
 * purchase link at all, only the sentence saying what Pro adds.
 */
class ProLinksTest extends IntegrationTestCase
{
    private bool $admin = true;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('admin_url')->alias(static fn($path = '') => 'https://example.test/wp-admin/' . $path);
        Functions\when('wp_kses')->returnArg(1);
        Functions\when('esc_attr__')->returnArg(1);
        Functions\when('wp_sprintf_l')->alias(static fn($pattern, $items) => implode(', ', $items));
        // Only manage_options varies; activate_plugins stays false so locked() skips the WP_PLUGIN_DIR probe.
        Functions\when('current_user_can')->alias(fn($cap) => 'manage_options' === $cap && $this->admin);
    }

    private static function locked(string $mode): string
    {
        $m = new \ReflectionMethod(\SlimStat\Reports\AcquisitionReport::class, 'locked');
        $m->setAccessible(true);
        ob_start();
        $m->invoke(null, $mode, ['Email', 'Paid Search'], false);
        return (string) ob_get_clean();
    }

    public function test_export_link_opens_the_share_section_for_administrators(): void
    {
        $html = \wp_slimstat_admin::pro_export_link();
        $this->assertStringContainsString('href="https://example.test/wp-admin/admin.php?page=slimpro#ss-pro-share"', $html);
        $this->assertStringContainsString('button-export-to-xls', $html);
        $this->assertStringNotContainsString('wp-slimstat.com', $html);
        $this->assertStringNotContainsString('target=', $html);
        $this->assertStringContainsString(' data-ec-export', \wp_slimstat_admin::pro_export_link(' data-ec-export'));
    }

    public function test_export_link_is_absent_for_users_without_manage_options(): void
    {
        $this->admin = false;
        $this->assertSame('', \wp_slimstat_admin::pro_export_link());
        $this->assertSame('', \wp_slimstat_admin::pro_export_link(' data-ec-export'));
    }

    public function test_channels_unlock_link_opens_the_converts_section_for_administrators(): void
    {
        foreach (['channel' => 'See all channels with Pro', 'utm' => 'See all campaigns with Pro'] as $mode => $text) {
            $html = self::locked($mode);
            $this->assertStringContainsString('Also active this period:', $html);
            $this->assertStringContainsString('href="https://example.test/wp-admin/admin.php?page=slimpro#ss-pro-converts"', $html);
            $this->assertStringContainsString($text, $html);
            $this->assertStringNotContainsString('wp-slimstat.com', $html);
        }
    }

    public function test_channels_teaser_has_no_link_for_users_without_manage_options(): void
    {
        $this->admin = false;
        $html = self::locked('channel');
        $this->assertStringContainsString('Also active this period: <strong>Email</strong>, <strong>Paid Search</strong>.', $html);
        $this->assertStringNotContainsString('<a', $html);
    }
}
