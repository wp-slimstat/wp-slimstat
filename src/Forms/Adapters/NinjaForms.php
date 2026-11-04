<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Forms\Adapters;

use SlimStat\Forms\Adapter;
use SlimStat\Forms\Hooks;
use SlimStat\Forms\Integration;
use SlimStat\Forms\Outcome;

/** Public builder hooks only; no entry fields are read. */
final class NinjaForms implements Adapter {
	/** Register the verified render and submission contracts. */
	public function register(): void {
		Hooks::filter(
			'ninja_forms_display_before_fields',
			static function ( $html, $form_id, $preview = false ) {
				return is_string( $html ) && ! $preview ? $html . Integration::marker( 'ninja', $form_id ) : $html;
			},
			3
		);
		Hooks::action(
			'ninja_forms_after_submission',
			static function ( $data ) {
				if ( is_array( $data ) ) {
					Integration::outcome( 'ninja', $data['form_id'] ?? null, Outcome::classify( 'ninja', $data ), $data['extra']['slimstat_context'] ?? '' );
				}
			}
		);
	}
}
