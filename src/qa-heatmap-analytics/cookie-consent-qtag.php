<?php
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone file: served directly without WordPress bootstrap. Do not exit.
}

	header( 'Content-Type: application/x-javascript; charset=utf-8' );
	// キャッシュを完全に無効にする
	header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
	header( 'Pragma: no-cache' );
	header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT' );

	$file_name      = './js/cookie-consent-qtag.js';
	$cookie_consent = 'false';

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Standalone file (no WordPress), strict comparison only, output is fixed string.
if ( isset( $_GET['cookie_consent'] ) && 'yes' === $_GET['cookie_consent'] ) {
	$cookie_consent = 'true';
}

if ( file_exists( $file_name ) ) {
	// Plugin Check exclusion: Standalone PHP (no WordPress), outputs JavaScript file directly.
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	echo str_replace( '"{cookie_consent}"', $cookie_consent, file_get_contents( $file_name ) );
}
