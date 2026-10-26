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
            'x on the left edge preserved' => ['0,480', '0,480'],
            'y on the top edge preserved' => ['320,0', '320,0'],
            '4k coordinates' => ['3840,2160', '3840,2160'],
            'max boundary' => ['99999,99999', '99999,99999'],
            'leading zeros preserved' => ['007,042', '007,042'],
            'whitespace trimmed' => [' 320,480 ', '320,480'],

            // 0,0 is the tracker's "no coordinates" default (keyboard, submit and
            // synthetic clicks), not a click in the corner — stored empty (heatmap D3)
            'origin default emptied' => ['0,0', ''],
            'zero-padded origin emptied' => ['000,00', ''],

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
