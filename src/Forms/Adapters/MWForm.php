<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Forms\Adapters;

use SlimStat\Forms\Adapter;
use SlimStat\Forms\Hooks;
use SlimStat\Forms\Integration;

/** Public builder hooks only; no entry fields are read. */
final class MWForm implements Adapter {
	/** Register the verified render and submission contracts. */
	public function register(): void {
		$form_id = null;
		$invalid = array();
		Hooks::action(
			'mwform_start_main_process',
			static function ( $key ) use ( &$form_id ) {
				if ( ! is_string( $key ) || ! preg_match( '/\Amw-wp-form-([1-9][0-9]*)\z/', $key, $matches ) ) {
					$form_id = null;
					return;
				}
				$form_id = $matches[1];
				Hooks::action(
					'mwform_after_send_' . $key,
					static function () use ( $matches ) {
						Integration::outcome( 'mw', $matches[1], 'submitted' );
					},
					0
				);
			}
		);
		Hooks::filter(
			'mwform_error_message_html',
			static function ( $html, $unused_error, $unused_start, $unused_end, $key ) use ( &$invalid ) {
				if ( is_string( $key ) && preg_match( '/\Amw-wp-form-([1-9][0-9]*)\z/', $key, $matches ) && empty( $invalid[ $matches[1] ] ) ) {
					$invalid[ $matches[1] ] = true;
					Integration::outcome( 'mw', $matches[1], 'invalid' );
				}
				return $html;
			},
			5
		);
		Hooks::filter(
			'mwform_form_end_html',
			static function ( $html ) use ( &$form_id, &$invalid ) {
				return is_string( $html ) ? $html . Integration::marker( 'mw', $form_id, ! empty( $invalid[ $form_id ] ) ) : $html;
			}
		);
	}
}
