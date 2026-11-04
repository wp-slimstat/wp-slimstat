<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Forms;

/** The shared measurement definition for capture, aggregates, erasure and reports. */
final class Metrics {
	public const LOADED    = 1;
	public const SEEN      = 2;
	public const STARTED   = 4;
	public const INVALID   = 8;
	public const SPAM      = 16;
	public const FAILED    = 32;
	public const SUBMITTED = 64;

	/** Return each cohort's contribution without manufacturing missing funnel stages. */
	public static function counters( array $row ): array {
		$flags = (int) ( $row['flags'] ?? 0 );
		$out   = array();
		foreach ( array(
			'loaded'    => 1,
			'seen'      => 2,
			'started'   => 4,
			'submitted' => 64,
			'invalid'   => 8,
			'spam'      => 16,
			'failed'    => 32,
		) as $key => $bit ) {
			$out[ $key ] = (int) ( 0 !== ( $flags & $bit ) );
		}
		$out['converted']        = $out['seen'] * $out['submitted'];
		$out['completed_starts'] = $out['started'] * $out['submitted'];
		$out['timed']            = (int) ( $out['completed_starts'] && (int) ( $row['duration'] ?? 0 ) > 0 );
		$out['duration']         = $out['timed'] ? (int) $row['duration'] : 0;
		return $out;
	}

	/** An absent denominator is not a measured zero-percent conversion rate. */
	public static function rate( int $completed, int $seen ): ?float {
		return $seen > 0 ? round( 100 * $completed / $seen, 1 ) : null;
	}
}
