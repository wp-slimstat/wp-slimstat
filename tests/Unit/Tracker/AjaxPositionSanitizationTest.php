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
            // Valid coordinates — preserved
            'standard coordinates' => ['320,480', '320,480'],
            'origin coordinates' => ['0,0', '0,0'],
            '4k coordinates' => ['3840,2160', '3840,2160'],
            'max boundary' => ['99999,99999', '99999,99999'],
            'leading zeros preserved' => ['007,042', '007,042'],
            'whitespace trimmed' => [' 320,480 ', '320,480'],

            // Invalid — rejected outright (no character stripping)
            'six digit x rejected' => ['100000,200', ''],
            'xss payload rejected' => ['<script>alert(1)</script>', ''],
            'missing comma rejected' => ['320480', ''],
            'multiple commas rejected' => ['1,2,3', ''],
            'empty string rejected' => ['', ''],
            'leading comma rejected' => [',200', ''],
            'trailing comma rejected' => ['200,', ''],
            'negative coordinate rejected' => ['-5,300', ''],
            'inner whitespace rejected' => [' 320 , 480 ', ''],
            'double comma rejected' => ['320,,480', ''],
            'comma only rejected' => [',', ''],
            'letters rejected' => ['abc', ''],
            'sql payload rejected' => ['100,200;DROP TABLE', ''],
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
