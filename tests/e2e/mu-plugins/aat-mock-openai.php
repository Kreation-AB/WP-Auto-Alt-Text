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

/** pre_http_request callback: swallows the library runner's non-blocking loopback tick. */
function aat_e2e_block_library_loopback( $pre, array $args, string $url ) {
	$action = is_array( $args['body'] ?? null ) ? ( $args['body']['action'] ?? '' ) : '';
	if ( 'aat_library_tick' === $action && false !== strpos( $url, 'admin-post.php' ) ) {
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 204,
				'message' => 'No Content',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
	return $pre;
}

/** pre_http_request callback: answers openai.com requests, leaves everything else alone. */
function aat_e2e_mock_filter( $pre, array $args, string $url ) {
	if ( false === strpos( $url, 'openai.com' ) ) {
		return $pre;
	}
	// Optional latency, to measure throughput against a realistic API.
	usleep( (int) get_option( 'aat_e2e_latency_ms', 0 ) * 1000 );
	return aat_e2e_mock_route( (string) ( $args['method'] ?? 'GET' ), $url );
}

function aat_e2e_mock_bootstrap( bool $enabled ): void {
	if ( $enabled ) {
		add_filter( 'pre_http_request', 'aat_e2e_mock_filter', 10, 3 );
		// Specs drive library ticks through WP-CLI. The loopback chain would otherwise race them and finish a run at once.
		add_filter( 'pre_http_request', 'aat_e2e_block_library_loopback', 5, 3 );
		// With aat_e2e_queue_limits set, one queue tick feeds and fills a single library image (cancel spec).
		$aat_e2e_one = static function ( $value ) {
			return get_option( 'aat_e2e_queue_limits' ) ? 1 : $value;
		};
		add_filter( 'aat_library_feed_window', $aat_e2e_one );
		add_filter( 'aat_queue_batch_size', $aat_e2e_one );
	}
}

/**
 * With aat_e2e_extra_columns set, adds three fixed-width media list columns, as other plugins do
 * (FileBird, file size, Imagify), to check that the core file-name column keeps its room.
 */
function aat_e2e_extra_columns( array $columns ): array {
	if ( get_option( 'aat_e2e_extra_columns' ) ) {
		$columns['aat_extra_a'] = 'Extra A';
		$columns['aat_extra_b'] = 'Extra B';
		$columns['aat_extra_c'] = 'Extra C';
	}
	return $columns;
}

function aat_e2e_extra_columns_css(): void {
	if ( get_option( 'aat_e2e_extra_columns' ) ) {
		echo '<style>.column-aat_extra_a{width:11%}.column-aat_extra_b{width:16.5%}.column-aat_extra_c{width:150px}.fixed .column-parent{width:15%}</style>';
	}
}

add_filter( 'manage_media_columns', 'aat_e2e_extra_columns' );
add_action( 'admin_head-upload.php', 'aat_e2e_extra_columns_css' );

aat_e2e_mock_bootstrap( defined( 'AAT_E2E_MOCK' ) && AAT_E2E_MOCK );
