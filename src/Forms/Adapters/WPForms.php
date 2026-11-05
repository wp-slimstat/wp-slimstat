<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Forms\Adapters;

use SlimStat\Forms\Adapter;
use SlimStat\Forms\Hooks;
use SlimStat\Forms\Integration;

/** Public builder hooks only; no entry fields are read. */
final class WPForms implements Adapter {
	/** Register the verified render and submission contracts. */
	public function register(): void {
		Hooks::action(
			'wpforms_frontend_output',
			static function ( $form ) {
				if ( is_array( $form ) ) {
					Integration::printMarker( 'wpforms', $form['id'] ?? null );
				}
			}
		);
		Hooks::action(
			'wpforms_process_complete',
			static function ( $unused_fields, $unused_entry, $form ) {
				if ( is_array( $form ) ) {
					Integration::outcome( 'wpforms', $form['id'] ?? null, 'submitted' );
				}
			},
			3
		);
		Hooks::action(
			'wpforms_process_anti_spam_entry_marked_as_spam',
			static function ( $unused_entry_id, $form_id ) {
				Integration::outcome( 'wpforms', $form_id, 'spam' );
			},
			2
		);
	}
}
