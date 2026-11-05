<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Forms\Adapters;

use SlimStat\Forms\Adapter;
use SlimStat\Forms\Hooks;
use SlimStat\Forms\Integration;

/** Public builder hooks only; no entry fields are read. */
final class SnowMonkey implements Adapter {
	/** Register the verified render and submission contracts. */
	public function register(): void {
		Hooks::filter(
			'render_block',
			static function ( $html, $block ) {
				if ( is_array( $block ) && 'snow-monkey-forms/snow-monkey-form' === ( $block['blockName'] ?? '' ) ) {
					return Integration::insertMarker( $html, 'snow', $block['attrs']['formId'] ?? null );
				}
				return $html;
			},
			2
		);
		// The administrator-mail hook is too early: auto-reply may still fail.
		Hooks::filter(
			'snow_monkey_forms/complete/message',
			static function ( $message, $unused_response, $setting ) {
				if ( is_object( $setting ) && is_callable( array( $setting, 'get' ) ) ) {
					Integration::outcome( 'snow', $setting->get( 'form_id' ), 'submitted' );
				}
				return $message;
			},
			3
		);
	}
}
