<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Forms;

/** A broken optional integration must never break a visitor's form. */
final class Hooks {
	/** Register an action with a failure boundary that never logs submitted data. */
	public static function action( string $hook, callable $callback, int $arguments = 1 ): void {
		add_action(
			$hook,
			static function ( ...$args ) use ( $callback ) {
				try {
					$callback( ...$args );
				} catch ( \Throwable $error ) {
					\wp_slimstat::record_degradation( 'form adapter', 'The form integration callback was unavailable.' );
				}
			},
			100,
			$arguments
		);
	}

	/** Return the builder's original value if an optional filter cannot run. */
	public static function filter( string $hook, callable $callback, int $arguments = 1 ): void {
		add_filter(
			$hook,
			static function ( $value, ...$args ) use ( $callback ) {
				try {
					return $callback( $value, ...$args );
				} catch ( \Throwable $error ) {
					\wp_slimstat::record_degradation( 'form adapter', 'The form integration callback was unavailable.' );
					return $value;
				}
			},
			100,
			$arguments
		);
	}
}
