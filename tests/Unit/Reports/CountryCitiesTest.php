<?php
/** @license GPL-2.0-or-later */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Reports;

use SlimStat\Reports\CountryCities;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

/**
 * The Free/Pro boundary of the Country → City drilldown is applied here, on the server,
 * before anything is rendered: Free must never receive a second city.
 */
class CountryCitiesTest extends WpSlimstatTestCase
{
    /** @return array<int, array<string, mixed>> */
    private function rows(): array
    {
        return [
            ['city' => 'New York (NY)', 'counthits' => '25'],
            ['city' => 'Los Angeles (CA)', 'counthits' => '14'],
            ['city' => 'Chicago (IL)', 'counthits' => '7'],
            ['city' => 'Houston (TX)', 'counthits' => '5'],
        ];
    }

    public function test_free_receives_only_the_top_city_and_a_locked_flag(): void
    {
        $data = CountryCities::shape($this->rows(), 62, 56, false, 0, 20);

        $this->assertSame([['city' => 'New York (NY)', 'count' => 25, 'share' => 40.3, 'blog_id' => 0]], $data['rows']);
        $this->assertTrue($data['locked']);
        $this->assertSame(0, $data['next'], 'Free never pages');
    }

    public function test_free_cannot_page_past_the_top_city(): void
    {
        $data = CountryCities::shape($this->rows(), 62, 56, false, 2, 20);

        $this->assertCount(1, $data['rows']);
        $this->assertSame('New York (NY)', $data['rows'][0]['city'], 'a start offset must not reveal another city to Free');
    }

    public function test_free_with_a_single_city_has_nothing_locked(): void
    {
        $data = CountryCities::shape([['city' => 'Berlin (BE)', 'counthits' => 9]], 9, 9, false, 0, 20);

        $this->assertFalse($data['locked'], 'no upsell placeholders when no other city exists');
        $this->assertSame(100.0, $data['rows'][0]['share']);
    }

    public function test_pro_pages_through_every_city(): void
    {
        $first = CountryCities::shape($this->rows(), 62, 51, true, 0, 3);
        $this->assertSame(['New York (NY)', 'Los Angeles (CA)', 'Chicago (IL)'], array_column($first['rows'], 'city'));
        $this->assertFalse($first['locked']);
        $this->assertSame(3, $first['next']);

        $last = CountryCities::shape($this->rows(), 62, 51, true, 3, 3);
        $this->assertSame(['Houston (TX)'], array_column($last['rows'], 'city'));
        $this->assertSame(0, $last['next']);
    }

    public function test_shares_use_the_country_total_including_pageviews_without_a_city(): void
    {
        $data = CountryCities::shape($this->rows(), 62, 51, true, 0, 20);

        // 25 / 62, not 25 / 51: the denominator is the country row that was clicked.
        $this->assertSame([40.3, 22.6, 11.3, 8.1], array_column($data['rows'], 'share'));
        $this->assertSame(11, $data['unknown']);
    }

    public function test_degenerate_totals_never_divide_by_zero_or_go_negative(): void
    {
        $data = CountryCities::shape([], 0, 0, true, 5, 20);

        $this->assertSame([], $data['rows']);
        $this->assertSame(0, $data['unknown']);
        $this->assertSame(0, $data['next']);

        // A cached total older than its rows must not print a negative remainder or a share over 100.
        $stale = CountryCities::shape([['city' => 'Paris (IDF)', 'counthits' => 8]], 6, 8, true, 0, 20);
        $this->assertSame(0, $stale['unknown']);
        $this->assertSame(100.0, $stale['rows'][0]['share']);
    }
}
