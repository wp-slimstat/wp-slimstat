<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Forms\Adapters;

use SlimStat\Forms\Adapter;
use SlimStat\Forms\Hooks;
use SlimStat\Forms\Integration;
use SlimStat\Forms\Outcome;

/** Public builder hooks only; no entry fields are read. */
final class ContactForm7 implements Adapter {
	/** Register the verified render and submission contracts. */
	public function register(): void {
		Hooks::filter(
			'wpcf7_form_elements',
			static function ( $html ) {
				$form = \WPCF7_ContactForm::get_current();
				return is_string( $html ) && $form ? $html . Integration::marker( 'cf7', $form->id() ) : $html;
			}
		);
		Hooks::action(
			'wpcf7_submit',
			static function ( $form, $result ) {
				if ( is_object( $form ) && is_callable( array( $form, 'id' ) ) ) {
					Integration::outcome( 'cf7', $form->id(), Outcome::classify( 'cf7', $result ) );
				}
			},
			2
		);
	}
}
