<?php
/*
 * Plugin Name: AAT E2E OpenAI mock
 * Description: Answers OpenAI requests from recorded fixtures. Active only when AAT_E2E_MOCK is true (wp-env tests site).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Auto_Alt_Text_Test_OpenAI_Fixtures' ) ) {
	// In wp-env, wp-content/aat-e2e-tests is the mapped tests/ directory.
	require_once WP_CONTENT_DIR . '/aat-e2e-tests/helpers/class-aat-openai-fixtures.php';
}

/**
 * Picks the fixture for an OpenAI request.
 *
 * @return array|WP_Error A pre_http_request response, or an error for an unmatched request.
 */
function aat_e2e_mock_route( string $method, string $url ) {
	$method = strtoupper( $method );
	$path   = (string) wp_parse_url( $url, PHP_URL_PATH );

	$name = null;
	if ( 'GET' === $method && '/v1/models' === $path ) {
		$name = 'models-list';
	} elseif ( 'GET' === $method && 0 === strpos( $path, '/v1/models/' ) ) {
		$name = 'model-get';
	} elseif ( 'POST' === $method && '/v1/responses' === $path ) {
		$name = 'responses-completed';
		$next = get_option( 'aat_e2e_next_fixture' );
		if ( is_string( $next ) && '' !== $next ) {
			$name = $next;
			delete_option( 'aat_e2e_next_fixture' );
		}
	}

	if ( null === $name ) {
		$message = sprintf( 'AAT e2e mock: unmatched OpenAI request %s %s', $method, $url );
		error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		return new WP_Error( 'aat_e2e_unmatched_openai_request', $message );
	}

	return Auto_Alt_Text_Test_OpenAI_Fixtures::to_http_response( Auto_Alt_Text_Test_OpenAI_Fixtures::load( $name ) );
}

/** pre_http_request callback: answers openai.com requests, leaves everything else alone. */
function aat_e2e_mock_filter( $pre, array $args, string $url ) {
	if ( false === strpos( $url, 'openai.com' ) ) {
		return $pre;
	}
	return aat_e2e_mock_route( (string) ( $args['method'] ?? 'GET' ), $url );
}

function aat_e2e_mock_bootstrap( bool $enabled ): void {
	if ( $enabled ) {
		add_filter( 'pre_http_request', 'aat_e2e_mock_filter', 10, 3 );
	}
}

aat_e2e_mock_bootstrap( defined( 'AAT_E2E_MOCK' ) && AAT_E2E_MOCK );
