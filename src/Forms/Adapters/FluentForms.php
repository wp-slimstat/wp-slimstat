<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Forms\Adapters;

use SlimStat\Forms\Adapter;
use SlimStat\Forms\Hooks;
use SlimStat\Forms\Integration;

/** Public builder hooks only; no entry fields are read. */
final class FluentForms implements Adapter {
	/** Register the verified render and submission contracts. */
	public function register(): void {
		$status   = array();
		$contexts = array();
		Hooks::action(
			'fluentform/form_element_start',
			static function ( $form ) {
				if ( is_object( $form ) ) {
					Integration::printMarker( 'fluent', $form->id ?? null );
				}
			}
		);
		Hooks::action(
			'fluentform/before_insert_submission',
			static function ( $data, $raw ) use ( &$status, &$contexts ) {
				if ( is_array( $data ) && isset( $data['form_id'] ) && is_scalar( $data['form_id'] ) ) {
					$status[ (string) $data['form_id'] ] = $data['status'] ?? 'unread';
					// Only our signed metadata survives here; user fields are never retained.
					$contexts[ (string) $data['form_id'] ] = is_array( $raw ) ? ( $raw['_slimstat_context'] ?? '' ) : '';
				}
			},
			2
		);
		Hooks::action(
			'fluentform/submission_inserted',
			static function ( $unused_entry_id, $unused_data, $form ) use ( &$status, &$contexts ) {
				if ( ! is_object( $form ) || ! isset( $form->id ) || ! is_numeric( $unused_entry_id ) || (int) $unused_entry_id < 1 ) {
					return;
				}
				$state   = $status[ (string) $form->id ] ?? null;
				$outcome = 'spam' === $state ? 'spam' : ( in_array( $state, array( 'unread', 'read' ), true ) ? 'submitted' : 'unknown' );
				Integration::outcome( 'fluent', $form->id, $outcome, $contexts[ (string) $form->id ] ?? '' );
			},
			3
		);
	}
}
