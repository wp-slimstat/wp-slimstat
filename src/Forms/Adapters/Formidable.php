<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Forms\Adapters;

use SlimStat\Forms\Adapter;
use SlimStat\Forms\Hooks;
use SlimStat\Forms\Integration;
use SlimStat\Forms\Outcome;

/** Public builder hooks only; no entry fields are read. */
final class Formidable implements Adapter {
	/** Register the verified render and submission contracts. */
	public function register(): void {
		Hooks::action(
			'frm_entry_form',
			static function ( $form ) {
				if ( is_object( $form ) ) {
					Integration::printMarker( 'formidable', $form->id ?? null );
				}
			}
		);
		Hooks::action(
			'frm_after_create_entry',
			static function ( $entry_id, $form_id, $args = array() ) {
				if ( ! is_numeric( $entry_id ) || ! empty( $args['is_child'] ) ) {
					return;
				}
				// Public entry API; request no field metadata and retain status alone.
				$entry = \FrmEntry::getOne( (int) $entry_id, false );
				if ( ! is_object( $entry ) ) {
					return;
				}
				$state         = array( 'is_draft' => $entry->is_draft ?? null );
				$state['spam'] = defined( 'FrmEntriesHelper::SPAM_ENTRY_STATUS' ) && (string) \FrmEntriesHelper::SPAM_ENTRY_STATUS === (string) $state['is_draft'];
				Integration::outcome( 'formidable', $form_id, Outcome::classify( 'formidable', $state ) );
			},
			3
		);
	}
}
