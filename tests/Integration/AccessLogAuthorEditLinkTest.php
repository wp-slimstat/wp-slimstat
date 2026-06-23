<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Fix 1 (#273 follow-up) — placement guard for the Access Log author pencil.
 *
 * The behavioural contract of get_edit_profile_link() (present when editable,
 * empty otherwise) is unit-tested in tests/access-log-author-edit-link-test.php
 * and exercised end-to-end in tests/e2e. This deterministic structure guard
 * locks the *placement* inside admin/view/right-now.php so a future edit can't
 * silently drop the pencil or move it out of the author cell — mirroring the
 * file-content approach of GoalsFunnelsEmptyStateTest.
 */
class AccessLogAuthorEditLinkTest extends TestCase
{
    private function rightNow(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/admin/view/right-now.php');
    }

    public function test_pencil_is_gated_on_a_known_wp_user(): void
    {
        $php = $this->rightNow();
        // Only resolved logins get a pencil; guests/unknown usernames must not.
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$user\s*\)\s*\{\s*\$ip_address\s*\.=\s*wp_slimstat_reports::get_edit_profile_link\(\s*\$user->ID\s*\)/',
            $php,
            'The pencil append must be guarded by if ($user) and keyed on $user->ID.'
        );
    }

    public function test_pencil_sits_before_the_ip_filter_link(): void
    {
        $php = $this->rightNow();
        $pencilPos = strpos($php, 'get_edit_profile_link($user->ID)');
        $this->assertNotFalse($pencilPos, 'Access Log must call get_edit_profile_link($user->ID).');
        // The IP "(x.x.x.x)" filter link is appended after the author block; the
        // pencil belongs to the author, so it must come first.
        $ipPos = strpos($php, "fs_url('ip equals ' . \$results[\$i]['ip'])", $pencilPos);
        $this->assertNotFalse($ipPos, 'The IP filter link must follow the author pencil.');
    }
}
