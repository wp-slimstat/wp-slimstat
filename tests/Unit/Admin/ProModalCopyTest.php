<?php
declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/** Each Pro feature in the upgrade modal explains itself; Export once repeated the Email Reports text. */
class ProModalCopyTest extends TestCase
{
    public function test_every_feature_tooltip_is_its_own(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/view/partials/slimstat-pro-modal.php');
        preg_match_all("~</strong><br>\s*<\?php esc_html_e\('([^']+)'~", $source, $matches);

        self::assertGreaterThan(3, count($matches[1]), 'the tooltip pattern still matches the modal');
        self::assertSame($matches[1], array_values(array_unique($matches[1])), 'two features share one tooltip');
    }
}
