<?php
declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Tracker;

use SlimStat\Tracker\Ajax;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class AjaxPositionSanitizationTest extends WpSlimstatTestCase
{
    public function test_sanitize_position_preserves_only_valid_coordinate_pairs(): void
    {
        $cases = [
            'standard coordinates' => ['320,480', '320,480'],
            'origin coordinates' => ['0,0', '0,0'],
            '4k coordinates' => ['3840,2160', '3840,2160'],
            'max boundary' => ['99999,99999', '99999,99999'],
            'leading zeros preserved' => ['007,042', '007,042'],
            'six digit x rejected' => ['100000,200', ''],
            'xss payload rejected' => ['<script>alert(1)</script>', ''],
            'missing comma rejected' => ['320480', ''],
            'multiple commas rejected' => ['1,2,3', ''],
            'empty string rejected' => ['', ''],
            'leading comma rejected' => [',200', ''],
            'trailing comma rejected' => ['200,', ''],
            'minus stripped before validation' => ['-5,300', '5,300'],
            'whitespace stripped before validation' => [' 320 , 480 ', '320,480'],
            'double comma rejected' => ['320,,480', ''],
            'comma only rejected' => [',', ''],
            'letters strip to empty' => ['abc', ''],
            'sql payload stripped then validated' => ['100,200;DROP TABLE', '100,200'],
            'non scalar input rejected' => [['320,480'], ''],
        ];

        foreach ($cases as $label => [$input, $expected]) {
            $this->assertSame(
                $expected,
                Ajax::sanitizePosition($input),
                sprintf('Failed asserting sanitizePosition contract for case "%s".', $label)
            );
        }
    }
}
