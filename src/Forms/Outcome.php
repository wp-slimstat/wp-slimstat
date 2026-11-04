<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Forms;

/** Conservative interpretation of server hook metadata, never browser success messages. */
final class Outcome {
	/**
	 * Classify evidence from a verified lifecycle hook.
	 *
	 * @param string $adapter Adapter key.
	 * @param mixed  $evidence Status metadata only; field values are not needed.
	 * @return string Shared outcome or unknown when the contract is not satisfied.
	 */
	public static function classify( string $adapter, $evidence ): string {
		if ( ! is_array( $evidence ) ) {
			return 'unknown';
		}
		switch ( $adapter ) {
			case 'cf7':
				if ( ! empty( $evidence['demo_mode'] ) ) {
					return 'unknown';
				}
				$states = array(
					'mail_sent'          => 'submitted',
					'validation_failed'  => 'invalid',
					'acceptance_missing' => 'invalid',
					'spam'               => 'spam',
					'mail_failed'        => 'failed',
					'aborted'            => 'failed',
				);
				return is_string( $evidence['status'] ?? null ) ? ( $states[ $evidence['status'] ] ?? 'unknown' ) : 'unknown';
			case 'gravity':
				return 'active' === ( $evidence['status'] ?? null ) ? 'submitted' : ( 'spam' === ( $evidence['status'] ?? null ) ? 'spam' : 'unknown' );
			case 'formidable':
				if ( true === ( $evidence['spam'] ?? false ) ) {
					return 'spam';
				}
				return in_array( $evidence['is_draft'] ?? null, array( 0, '0' ), true ) ? 'submitted' : 'unknown';
			case 'forminator':
				if ( 'spam' === ( $evidence['entry_status'] ?? null ) ) {
					return 'spam';
				}
				if ( false === ( $evidence['success'] ?? null ) ) {
					return 'failed';
				}
				// Honeypots also return success: require the separate accepted-entry hook.
				return true === ( $evidence['success'] ?? null ) && 'active' === ( $evidence['entry_status'] ?? null ) ? 'submitted' : 'unknown';
			case 'ninja':
				if ( ! empty( $evidence['errors'] ) ) {
					return 'invalid';
				}
				return isset( $evidence['form_id'] ) && is_array( $evidence['processed_actions'] ?? null )
					&& false === ( $evidence['settings']['is_preview'] ?? null ) && empty( $evidence['halt'] ) ? 'submitted' : 'unknown';
		}
		return 'unknown';
	}
}
