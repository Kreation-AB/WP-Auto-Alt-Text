<?php
/*
 * Plugin Name: AAT staging OpenAI mock
 * Description: Answers OpenAI requests with invented alt texts, so a staging run costs nothing. Active only when AAT_STAGING_MOCK is true in wp-config.php.
 *
 * Testa Hela biblioteket på en stagingsajt utan kostnad
 * =====================================================
 *
 * Filen är en fristående must-use-plugin. Den läser inget från pluginets tests-mapp
 * (den följer inte med i zip-filen) och gör ingenting om konstanten saknas.
 *
 * 1. Ta en snapshot av stagingsajten.
 * 2. Installera den nya plugin-zipen.
 * 3. Kopiera den här filen till wp-content/mu-plugins/ och lägg till i wp-config.php:
 *
 *        define( 'AAT_STAGING_MOCK', true );
 *
 *    Valfritt: AAT_STAGING_MOCK_LATENCY_MS sätter svarstiden per bild i millisekunder
 *    (standard 5000, som OpenAIs verkliga median).
 * 4. Starta Hela biblioteket, stäng fliken och besök inte sajten på minst en timme.
 * 5. Hämta körningens id:
 *
 *        wp db query "SELECT id, status, started_at FROM <prefix>aat_runs ORDER BY id DESC LIMIT 1"
 *
 *    Kontrollera sedan hur många bilder som blev klara per timme:
 *
 *        wp db query "SELECT DATE_FORMAT(updated_at,'%Y-%m-%d %H:00') AS hour, COUNT(*) AS done FROM <prefix>aat_queue WHERE run_id=<id> AND status='done' GROUP BY hour ORDER BY hour"
 *
 *    och åtkomstloggen för admin-post.php.
 * 6. Ta bort filen och konstanten, och återställ sedan snapshoten. Pluginet cachar svar,
 *    så mock-texterna kan annars komma tillbaka senare. Utan snapshot: klicka minst på
 *    "Töm cache" och radera mock-alt-texterna med:
 *
 *        wp db query "DELETE FROM <prefix>postmeta WHERE meta_key='_wp_attachment_image_alt' AND meta_value LIKE 'MOCKTEXT%'"
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Builds a pre_http_request response. */
function aat_staging_mock_response( int $code, array $body, array $extra_headers = array() ): array {
	return array(
		'headers'  => array_merge( array( 'content-type' => 'application/json' ), $extra_headers ),
		'body'     => (string) wp_json_encode( $body ),
		'response' => array(
			'code'    => $code,
			'message' => get_status_header_desc( $code ),
		),
		'cookies'  => array(),
	);
}

/** Milliseconds a mocked /v1/responses call waits. */
function aat_staging_mock_latency_ms(): int {
	$default = defined( 'AAT_STAGING_MOCK_LATENCY_MS' ) ? (int) AAT_STAGING_MOCK_LATENCY_MS : 5000;
	return max( 0, (int) apply_filters( 'aat_staging_mock_latency_ms', $default ) );
}

/** pre_http_request callback: answers api.openai.com requests, leaves every other host alone. */
function aat_staging_mock_filter( $pre, array $args, string $url ) {
	if ( 'api.openai.com' !== strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) ) {
		return $pre;
	}

	$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
	$path   = rtrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );

	if ( 'POST' === $method && '/v1/responses' === $path ) {
		usleep( aat_staging_mock_latency_ms() * 1000 );
		$request = json_decode( is_string( $args['body'] ?? null ) ? $args['body'] : '', true );
		$model   = is_array( $request ) && is_string( $request['model'] ?? null ) ? $request['model'] : 'gpt-6.1-sol';
		return aat_staging_mock_response(
			200,
			array(
				'id'         => 'resp_staging_mock',
				'object'     => 'response',
				'created_at' => time(),
				'status'     => 'completed',
				'error'      => null,
				'model'      => $model,
				'output'     => array(
					array(
						'id'      => 'msg_staging_mock',
						'type'    => 'message',
						'status'  => 'completed',
						'role'    => 'assistant',
						'content' => array(
							array(
								'type'        => 'output_text',
								'annotations' => array(),
								'text'        => 'MOCKTEXT – inte en riktig alt-text.',
							),
						),
					),
				),
				'usage'      => array(
					'input_tokens'          => 1300,
					'output_tokens'         => 60,
					'output_tokens_details' => array( 'reasoning_tokens' => 0 ),
					'total_tokens'          => 1360,
				),
			),
			array(
				'openai-version'                 => '2020-10-01',
				'x-ratelimit-limit-requests'     => '10000',
				'x-ratelimit-limit-tokens'       => '4000000',
				'x-ratelimit-remaining-requests' => '9999',
				'x-ratelimit-remaining-tokens'   => '4000000',
				'x-ratelimit-reset-requests'     => '6ms',
				'x-ratelimit-reset-tokens'       => '0s',
			)
		);
	}

	if ( 'GET' === $method && '/v1/models' === $path ) {
		return aat_staging_mock_response(
			200,
			array(
				'object' => 'list',
				'data'   => array(
					array(
						'id'            => 'gpt-6.1-sol',
						'object'        => 'model',
						'created'       => 1790552874,
						'owned_by'      => 'system',
						'shutdown_date' => null,
					),
				),
			)
		);
	}

	if ( 'GET' === $method && 0 === strpos( $path, '/v1/models/' ) ) {
		return aat_staging_mock_response(
			200,
			array(
				'id'            => rawurldecode( substr( $path, strlen( '/v1/models/' ) ) ),
				'object'        => 'model',
				'created'       => 1790552874,
				'owned_by'      => 'system',
				'shutdown_date' => null,
			)
		);
	}

	// Nothing else is ever sent to the real API.
	return aat_staging_mock_response(
		404,
		array(
			'error' => array(
				'message' => 'AAT staging mock: no such endpoint.',
				'type'    => 'invalid_request_error',
				'code'    => 'not_found',
			),
		)
	);
}

/** Admin notice shown on every admin screen while the mock is active. */
function aat_staging_mock_notice(): void {
	echo '<div class="notice notice-warning"><p>' . esc_html( 'OpenAI-mocken är aktiv: alt-texterna är påhittade och inga anrop går till OpenAI. Ta bort filen och konstanten efter testet.' ) . '</p></div>';
}

function aat_staging_mock_bootstrap( bool $enabled ): void {
	if ( $enabled ) {
		add_filter( 'pre_http_request', 'aat_staging_mock_filter', 10, 3 );
		add_action( 'admin_notices', 'aat_staging_mock_notice' );
	}
}

aat_staging_mock_bootstrap( defined( 'AAT_STAGING_MOCK' ) && AAT_STAGING_MOCK );
