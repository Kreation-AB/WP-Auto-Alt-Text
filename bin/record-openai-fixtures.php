<?php
/**
 * Records real OpenAI responses as test fixtures (tests/fixtures/openai).
 *
 * Usage (keeps the key out of shell history):
 *   read -rs OPENAI_API_KEY; export OPENAI_API_KEY; composer fixtures:record; unset OPENAI_API_KEY
 * AAT_RECORD_MODEL overrides the model.
 * Nothing is written unless all six recordings pass validation.
 *
 * Plain PHP with curl, no WordPress. The key is read from the environment and
 * is never printed or written. Every recording passes through
 * aat_redact_openai_fixture() before it is saved.
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

// Keep arguments (and so the key) out of exception stack traces.
ini_set( 'zend.exception_ignore_args', '1' );

$key = getenv( 'OPENAI_API_KEY' );
if ( ! is_string( $key ) || '' === trim( $key ) ) {
	fwrite( STDERR, "Set OPENAI_API_KEY in your shell; it is never stored.\n" );
	exit( 1 );
}

$root = dirname( __DIR__ );

// Minimal shims so the model registry loads outside WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		return $value;
	}
}
require_once $root . '/includes/class-models.php';
require_once $root . '/tests/helpers/openai-fixture-redact.php';

$model_id = getenv( 'AAT_RECORD_MODEL' );
if ( ! is_string( $model_id ) || '' === $model_id ) {
	$model_id = Auto_Alt_Text_Models::default_id();
}
$model = Auto_Alt_Text_Models::get( $model_id );
if ( null === $model ) {
	// An override outside the registry: send no reasoning field, no known price.
	$model = array(
		'id'                => $model_id,
		'input_price'       => 0.0,
		'output_price'      => 0.0,
		'effort'            => null,
		'max_output_tokens' => 1500,
		'unpriced'          => true,
	);
}

$dir  = $root . '/tests/fixtures/openai';
$base = 'https://api.openai.com';

/**
 * One request; returns a fixture array (status, lowercase headers, decoded body).
 */
function aat_record_request( $method, $url, $bearer, $payload = null ) {
	$headers = array( 'Authorization: Bearer ' . $bearer );
	$ch      = curl_init( $url );
	if ( false === $ch ) {
		fwrite( STDERR, "Could not initialise curl.\n" );
		exit( 1 );
	}
	if ( 'POST' === $method ) {
		$headers[] = 'Content-Type: application/json';
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $payload ) );
	}
	$received = array();
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_HEADERFUNCTION => function ( $curl, $line ) use ( &$received ) {
				$parts = explode( ':', $line, 2 );
				if ( 2 === count( $parts ) ) {
					$received[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
				}
				return strlen( $line );
			},
		)
	);
	$raw    = curl_exec( $ch );
	$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	if ( false === $raw ) {
		fwrite( STDERR, 'Request failed: ' . curl_error( $ch ) . "\n" );
		exit( 1 );
	}
	curl_close( $ch );

	$body = json_decode( (string) $raw, true );
	return array(
		'status'  => $status,
		'headers' => $received,
		'body'    => null === $body ? (string) $raw : $body,
	);
}

$image = 'data:image/jpeg;base64,' . base64_encode( (string) file_get_contents( $dir . '/record-image.jpg' ) );

/** The plugin's request shape (Auto_Alt_Text_OpenAI::build_response_request()). */
$responses_payload = function ( $max_output_tokens ) use ( $model, $image ) {
	$request = array(
		'model'             => $model['id'],
		'input'             => array(
			array(
				'role'    => 'user',
				'content' => array(
					array( 'type' => 'input_text', 'text' => 'Describe this image in one short sentence.' ),
					array( 'type' => 'input_image', 'image_url' => $image, 'detail' => 'auto' ),
				),
			),
		),
		'max_output_tokens' => $max_output_tokens,
		'store'             => false,
		'instructions'      => 'You write concise alt text for images. Reply with the alt text only.',
	);
	if ( null !== $model['effort'] ) {
		$request['reasoning'] = array( 'effort' => $model['effort'] );
	}
	return $request;
};

$recordings = array(
	'models-list'                    => array( 'GET', '/v1/models', $key, null ),
	'model-get'                      => array( 'GET', '/v1/models/' . rawurlencode( $model['id'] ), $key, null ),
	'model-not-found'                => array( 'GET', '/v1/models/aat-no-such-model', $key, null ),
	'responses-completed'            => array( 'POST', '/v1/responses', $key, $responses_payload( $model['max_output_tokens'] ) ),
	'responses-incomplete-max-tokens' => array( 'POST', '/v1/responses', $key, $responses_payload( 16 ) ),
	'unauthorized'                   => array( 'GET', '/v1/models', 'sk-aat-invalid', null ),
);

$total_cost = 0.0;
$results    = array();
$failed     = false;
foreach ( $recordings as $name => $spec ) {
	list( $method, $path, $bearer, $payload ) = $spec;
	$fixture                 = aat_record_request( $method, $base . $path, $bearer, $payload );
	$usage                   = is_array( $fixture['body'] ) ? ( $fixture['body']['usage'] ?? array() ) : array();
	$total_cost             += ( (int) ( $usage['input_tokens'] ?? 0 ) * $model['input_price'] + (int) ( $usage['output_tokens'] ?? 0 ) * $model['output_price'] ) / 1000000;
	$fixture['_recorded_at'] = gmdate( 'Y-m-d' );
	$fixture['_model']       = $model['id'];
	$fixture                 = aat_redact_openai_fixture( $fixture );

	$error = aat_validate_recorded_fixture( $name, $fixture );
	if ( null !== $error ) {
		// Never print the body; only OpenAI's error code and message, with anything key-like masked.
		$api_error = is_array( $fixture['body'] ) && is_array( $fixture['body']['error'] ?? null ) ? $fixture['body']['error'] : array();
		$detail    = trim( ( is_string( $api_error['code'] ?? null ) ? $api_error['code'] . ': ' : '' ) . ( is_string( $api_error['message'] ?? null ) ? $api_error['message'] : '' ) );
		$detail    = '' === $detail ? '' : ' (OpenAI: ' . substr( (string) preg_replace( '/sk-\S+/', '[key]', $detail ), 0, 300 ) . ')';
		fwrite( STDERR, 'Recording ' . $name . ' failed (HTTP ' . $fixture['status'] . '): ' . $error . $detail . "\n" );
		$failed = true;
		continue;
	}
	$results[ $name ] = $fixture;
}

if ( $failed ) {
	fwrite( STDERR, "No fixture was written.\n" );
	exit( 1 );
}

foreach ( $results as $name => $fixture ) {
	$file = $dir . '/' . $name . '.json';
	if ( false === file_put_contents( $file, json_encode( $fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" ) ) {
		fwrite( STDERR, 'Could not write ' . $file . "\n" );
		exit( 1 );
	}
	echo 'Wrote ' . $file . ' (HTTP ' . $fixture['status'] . ")\n";
}

if ( ! empty( $model['unpriced'] ) ) {
	echo 'Cost unknown (no registry price for ' . $model['id'] . ")\n";
} else {
	printf( "Cost of this run: about USD %.5f (registry prices for %s)\n", $total_cost, $model['id'] );
}
