<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Forms\Adapters;

use SlimStat\Forms\Adapter;
use SlimStat\Forms\Hooks;
use SlimStat\Forms\Integration;
use SlimStat\Forms\Outcome;

/** Public builder hooks only; no entry fields are read. */
final class Forminator implements Adapter {
	/** Register the verified render and submission contracts. */
	public function register(): void {
		$states = array();
		$forms  = array();
		Hooks::action(
			'forminator_before_form_render',
			static function ( $id, $type ) use ( &$forms ) {
				if ( is_scalar( $id ) ) {
					$forms[ (string) $id ] = 'custom-form' === $type;
					if ( $forms[ (string) $id ] ) {
						Integration::marker( 'forminator', $id ); }
				}
			},
			2
		);
		Hooks::filter(
			'forminator_render_form_submit_markup',
			static function ( $html, $form_id ) use ( &$forms ) {
				return is_string( $html ) && is_scalar( $form_id ) && ! empty( $forms[ (string) $form_id ] ) ? $html . Integration::marker( 'forminator', $form_id ) : $html;
			},
			2
		);
		Hooks::action(
			'forminator_custom_form_submit_before_set_fields',
			static function ( $entry, $form_id ) use ( &$states ) {
				if ( is_object( $entry ) && is_scalar( $form_id ) ) {
					$states[ (string) $form_id ] = $entry->status ?? null;
				}
			},
			2
		);
		$finish = static function ( $form_id, $response ) use ( &$states ) {
			if ( ! is_scalar( $form_id ) || ! is_array( $response ) ) {
				return;
			}
			$evidence = array(
				'success'      => $response['success'] ?? null,
				'entry_status' => $states[ (string) $form_id ] ?? null,
			);
			Integration::outcome( 'forminator', $form_id, Outcome::classify( 'forminator', $evidence ) );
		};
		Hooks::action( 'forminator_form_after_save_entry', $finish, 2 );
		Hooks::action( 'forminator_form_after_handle_submit', $finish, 2 );
	}
}
