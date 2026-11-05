<?php
/** Source and registration canaries supplement, but never replace, real submissions. @license GPL-2.0-or-later */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! preg_match( '/^test_form_analytics_/', DB_NAME ) ) { throw new RuntimeException( 'Use a disposable Form Analytics site.' ); }
$manifest = json_decode( file_get_contents( __DIR__ . '/compatibility.json' ), true );
$active = \SlimStat\Forms\Registry::active();
$checked = 0;
foreach ( $manifest as $slug => $contract ) {
	$path = WP_PLUGIN_DIR . '/' . $slug;
	if ( ! is_dir( $path ) ) { continue; }
	if ( ! isset( $active[ $contract['adapter'] ] ) ) { throw new RuntimeException( 'Installed builder was not registered: ' . $slug ); }
	$source = '';
	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $files as $file ) {
		if ( 'php' === $file->getExtension() && false === strpos( $file->getPathname(), '/vendor/' ) ) { $source .= file_get_contents( $file->getPathname() ); }
	}
	foreach ( $contract['hooks'] as $hook ) {
		if ( false === strpos( $source, "'" . $hook . "'" ) && false === strpos( $source, '"' . $hook . '"' ) ) { throw new RuntimeException( 'Public hook contract changed: ' . $slug . ' / ' . $hook ); }
		if ( false === has_filter( $hook ) ) { throw new RuntimeException( 'Adapter callback missing: ' . $hook ); }
	}
	++$checked;
	echo 'PASS source/registration canary: ' . $slug . "\n";
}
if ( 8 !== $checked ) { throw new RuntimeException( 'Install every public builder before running the complete compatibility canary.' ); }
