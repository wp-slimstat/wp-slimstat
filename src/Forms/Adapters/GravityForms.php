<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Forms\Adapters;

use SlimStat\Forms\Adapter;
use SlimStat\Forms\Hooks;
use SlimStat\Forms\Integration;
use SlimStat\Forms\Outcome;

/** Public builder hooks only; no entry fields are read. */
final class GravityForms implements Adapter {
	/** Register the verified render and submission contracts. */
	public function register(): void {
		Hooks::filter(
			'gform_form_tag',
			static function ( $tag, $form ) {
				return is_string( $tag ) && is_array( $form ) ? $tag . Integration::marker( 'gravity', $form['id'] ?? null ) : $tag;
			},
			2
		);
		Hooks::action(
			'gform_after_submission',
			static function ( $entry, $form ) {
				if ( is_array( $form ) ) {
					Integration::outcome( 'gravity', $form['id'] ?? null, Outcome::classify( 'gravity', $entry ) );
				}
			},
			2
		);
	}
}
