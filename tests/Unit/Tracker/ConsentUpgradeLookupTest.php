<?php
declare(strict_types=1);
namespace WpSlimstat\Tests\Unit\Tracker;
use SlimStat\Tracker\Processor;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

/** A visit ID or guess must never identify a previous anonymous pageview for upgrade. */
class ConsentUpgradeLookupTest extends WpSlimstatTestCase
{
    public function test_only_a_valid_signed_pageview_can_be_upgraded(): void
    {
        \wp_slimstat::$settings['secret'] = 'privacy-test-secret';
        $signed = '42.' . hash_hmac('sha256', 'pageview|42', 'privacy-test-secret');
        $visit = '42.' . hash_hmac('sha256', '42', 'privacy-test-secret');
        $method = new \ReflectionMethod(Processor::class, 'upgradePageviewId');
        $method->setAccessible(true);
        foreach ([[] , ['visit_id' => $signed], ['id' => $visit], ['id' => '42.' . md5('42privacy-test-secret')], ['id' => '42'], ['id' => '42.forged'], ['id' => []]] as $data) {
            self::assertSame(0, $method->invoke(null, $data));
        }
        self::assertSame(42, $method->invoke(null, ['id' => $signed]));
    }
}
