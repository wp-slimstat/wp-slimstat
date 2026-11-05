<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Forms;

/** Conservative prompts to investigate differences, never causal conclusions. */
final class Insights {
	/** Require adequate cohorts and non-overlapping 95% Wilson intervals. */
	public static function mobileGap( array $mobile, array $desktop ): bool {
		foreach ( array( $mobile, $desktop ) as $row ) {
			if ( (int) ( $row['seen'] ?? 0 ) < 100 || (int) ( $row['converted'] ?? 0 ) < 10 || $row['converted'] > $row['seen'] ) {
				return false;
			}
		}
		return self::interval( (int) $mobile['converted'], (int) $mobile['seen'] )[1]
			< self::interval( (int) $desktop['converted'], (int) $desktop['seen'] )[0];
	}

	/** Wilson score interval behaves well near zero and one. */
	private static function interval( int $count, int $total ): array {
		$p           = $count / $total;
		$z           = 1.96;
		$denominator = 1 + $z * $z / $total;
		$center      = ( $p + $z * $z / ( 2 * $total ) ) / $denominator;
		$width       = $z * sqrt( $p * ( 1 - $p ) / $total + $z * $z / ( 4 * $total * $total ) ) / $denominator;
		return array( $center - $width, $center + $width );
	}
}
