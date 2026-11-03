<?php
/** @license GPL-2.0-or-later */
namespace WpSlimstat\Tests\Unit\Reports;

use Brain\Monkey\Functions;
use SlimStat\Reports\ReportData;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class ReportDataTest extends WpSlimstatTestCase {
	public function test_registered_date_scope_and_parameters_reach_one_reader_call(): void {
		foreach ( array( null, false, true ) as $date_scope ) {
			$reader = new class {
				public array $where_args = array();
				public int $calls = 0;
				public function get_combined_where( ...$args ): string {
					$this->where_args = $args;
					return 'prepared WHERE';
				}
				public function rows( array $args ): array {
					++$this->calls;
					return array( array( 'where' => $args['where'], 'counthits' => 3 ) );
				}
			};
			$args = array( 'raw' => array( $reader, 'rows' ), 'where' => 'country = %s', 'where_params' => array( 'DE' ) );
			if ( null !== $date_scope ) {
				$args['use_date_filters'] = $date_scope;
			}
			self::assertSame( array( array( 'where' => 'prepared WHERE', 'counthits' => 3 ) ), ReportData::rawData( $args ) );
			self::assertSame( array( 'country = %s', '', $date_scope ?? true, '', array( 'DE' ) ), $reader->where_args );
			self::assertSame( 'prepared WHERE', $args['where'] );
			self::assertSame( 1, $reader->calls );
		}
	}

	public function test_legacy_non_array_results_remain_empty(): void {
		$args = array( 'raw' => static function () { return false; } );
		self::assertSame( array(), ReportData::rawData( $args ) );
	}

	public function test_summary_units_preserve_zero_rates_and_ordinary_counts(): void {
		Functions\stubTranslationFunctions();
		self::assertSame( '0%', ReportData::summaryValue( array( 'metric' => 'Bounce rate', 'value' => 0 ) ) );
		self::assertSame( '12.5%', ReportData::summaryValue( array( 'metric' => 'New Visitors Rate', 'value' => '12.5' ) ) );
		self::assertSame( '42', ReportData::summaryValue( array( 'metric' => 'Visits', 'value' => 42 ) ) );
	}
}
