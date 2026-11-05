<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Forms;

use SlimStat\Tracker\Utils;

/** Public form markers and the shared, consent-gated outcome boundary. */
final class Integration {
	/** Register hooks only. Optional state is read when a form actually renders. */
	public static function boot(): void {
		Store::boot();
		add_filter(
			'slimstat_column_names',
			static function ( $columns ) {
				$columns[ Report::FORM_FILTER ] = array( __( 'Form', 'wp-slimstat' ), 'varchar' );
				return $columns;
			}
		);
		foreach ( Registry::active() as $class ) {
			try {
				$adapter = new $class();
				$adapter->register();
			} catch ( \Throwable $error ) {
				\wp_slimstat::record_degradation( 'form adapter initialization', 'A form integration could not be initialized.' );
			}
		}
	}

	/** Restrict stable form identity to a known builder and positive numeric identifier. */
	public static function key( string $adapter, $id ): string {
		return isset( Registry::labels()[ $adapter ] ) && ( is_int( $id ) || is_string( $id ) )
			&& preg_match( '/\A[1-9][0-9]{0,18}\z/', (string) $id ) ? $adapter . ':' . $id : '';
	}

	/** A cache-safe form identity, not a visitor identity or permission to confirm success. */
	public static function token( string $key ): string {
		return $key . '.' . hash_hmac( 'sha256', 'slimstat-form-v1:' . get_current_blog_id() . ':' . $key, wp_salt( 'auth' ) );
	}

	/** Validate a rendered marker before accepting browser behavior for its form. */
	public static function verify( $token ): string {
		if ( ! is_string( $token ) || strlen( $token ) > 110 || false === strpos( $token, '.' ) ) {
			return '';
		}
		$key   = strstr( $token, '.', true );
		$parts = explode( ':', $key );
		if ( 2 !== count( $parts ) || self::key( $parts[0], $parts[1] ) !== $key || ! isset( Registry::active()[ $parts[0] ] ) ) {
			return '';
		}
		return hash_equals( self::token( $key ), $token ) ? $key : '';
	}

	/** Add the module only through a builder's actual form-rendering hook. */
	public static function marker( string $adapter, $id, bool $invalid = false ): string {
		$key = self::key( $adapter, $id );
		if ( '' === $key || 'on' !== ( \wp_slimstat::$settings['is_tracking'] ?? 'on' ) || ! Store::enabled() || ! Store::ready() ) {
			return '';
		}
		wp_enqueue_script( 'slimstat-forms', plugins_url( 'wp-slimstat-forms.js', SLIMSTAT_FILE ), array( 'wp_slimstat' ), SLIMSTAT_ANALYTICS_VERSION, true );
		wp_script_add_data( 'slimstat-forms', 'strategy', 'defer' );
		do_action( 'slimstat_forms_render', $key );
		return '<input type="hidden" name="_slimstat_form"' . ( $invalid ? ' data-slimstat-invalid="1"' : '' ) . ' data-slimstat-form="' . esc_attr( $key ) . '" value="' . esc_attr( self::token( $key ) ) . '" />';
	}

	/** Output only our escaped, inert markup within a public form-render action. */
	public static function printMarker( string $adapter, $id ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- marker() escapes both attribute values.
		echo self::marker( $adapter, $id );
	}

	/** Insert at the HTML form boundary, independent of builder classes and wrappers. */
	public static function insertMarker( $html, string $adapter, $id ) {
		if ( ! is_string( $html ) || ! preg_match( '/<\/form\s*>/i', $html, $match, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		return substr_replace( $html, self::marker( $adapter, $id ), $match[0][1], 0 );
	}

	/** Accept only server-hook outcomes, never a success flag from a tracking request. */
	public static function outcome( string $adapter, $id, string $outcome, $context = '' ): void {
		$key = self::key( $adapter, $id );
		if ( '' === $key || ! in_array( $outcome, array( 'submitted', 'invalid', 'spam', 'failed' ), true ) || ! Store::allowed() || ! Store::enabled() || ! Store::ready() ) {
			return;
		}
		if ( '' === $context ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The builder validates its submission; this separate signed page context is verified below.
			$context = isset( $_POST['_slimstat_context'] ) && is_string( $_POST['_slimstat_context'] ) ? sanitize_text_field( wp_unslash( $_POST['_slimstat_context'] ) ) : '';
		}
		$page_id = is_string( $context ) ? Utils::getValueWithoutChecksum( $context ) : false;
		Store::confirm( $key, $outcome, is_scalar( $page_id ) && ctype_digit( (string) $page_id ) ? (int) $page_id : 0 );
	}
}
