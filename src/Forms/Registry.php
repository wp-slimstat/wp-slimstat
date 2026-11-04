<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Forms;

/** Load only integrations whose builder has booted; no plugin-list or database probes. */
final class Registry {
	/** Installed integrations, keyed by stable storage identity. */
	public static function active(): array {
		$adapters = array();
		$classes  = array(
			'cf7'        => array( 'WPCF7_ContactForm', Adapters\ContactForm7::class ),
			'wpforms'    => array( 'WPForms', Adapters\WPForms::class ),
			'gravity'    => array( 'GFForms', Adapters\GravityForms::class ),
			'formidable' => array( 'FrmAppHelper', Adapters\Formidable::class ),
			'ninja'      => array( 'Ninja_Forms', Adapters\NinjaForms::class ),
			'forminator' => array( 'Forminator', Adapters\Forminator::class ),
			'mw'         => array( 'MW_WP_Form', Adapters\MWForm::class ),
		);
		foreach ( $classes as $key => $pair ) {
			if ( class_exists( $pair[0], false ) ) {
				$adapters[ $key ] = $pair[1];
			}
		}
		if ( defined( 'FLUENTFORM' ) ) {
			$adapters['fluent'] = Adapters\FluentForms::class;
		}
		if ( defined( 'SNOW_MONKEY_FORMS_PATH' ) ) {
			$adapters['snow'] = Adapters\SnowMonkey::class;
		}
		return $adapters;
	}

	/** Product names remain unchanged across locales. */
	public static function labels(): array {
		return array(
			'cf7'        => 'Contact Form 7',
			'wpforms'    => 'WPForms',
			'gravity'    => 'Gravity Forms',
			'formidable' => 'Formidable Forms',
			'ninja'      => 'Ninja Forms',
			'forminator' => 'Forminator',
			'fluent'     => 'Fluent Forms',
			'snow'       => 'Snow Monkey Forms',
			'mw'         => 'MW WP Form',
		);
	}
}
