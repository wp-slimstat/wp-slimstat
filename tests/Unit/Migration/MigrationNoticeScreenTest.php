<?php
/**
 * S2 (release review 2026-10-13): the "migration required" notice showed only on the Dashboard
 * and SlimStat's own screens. An admin who updates from Plugins or Updates — where the update
 * actually happens — never saw that reports were waiting on a database step.
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Migration;

use Brain\Monkey\Functions;
use SlimStat\Migration\Admin\MigrationAdmin;
use SlimStat\Migration\MigrationManager;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class MigrationNoticeScreenTest extends WpSlimstatTestCase
{
    private function noticeOn(string $base, string $id): string
    {
        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_current_screen')->justReturn((object) ['base' => $base, 'id' => $id]);
        Functions\when('admin_url')->returnArg();
        Functions\when('wp_create_nonce')->justReturn('nonce');

        $manager = \Mockery::mock(MigrationManager::class);
        $manager->shouldReceive('needsMigration')->andReturn(true);
        $manager->shouldReceive('getRequiredMigrations')->andReturn([]);

        ob_start();
        (new MigrationAdmin($manager))->maybeShowNotice();
        return (string) ob_get_clean();
    }

    public function test_the_notice_follows_the_admin_to_where_updates_happen(): void
    {
        foreach (['dashboard', 'plugins', 'update-core'] as $base) {
            self::assertStringContainsString('slimstat-migration-notice', $this->noticeOn($base, $base), $base);
        }
    }

    public function test_unrelated_screens_stay_quiet(): void
    {
        self::assertSame('', $this->noticeOn('edit', 'edit-post'));
    }
}
