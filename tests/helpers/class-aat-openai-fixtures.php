<?php
/*
 * Serves OpenAI fixtures from tests/fixtures/openai through pre_http_request,
 * so tests never reach the network. Used by the PHPUnit tests and the e2e mock.
 */

class Auto_Alt_Text_Test_OpenAI_Fixtures {
	/** @var array<int,array> */
	private $queue = array();

	/** @var array<int,array{method:string,url:string,body:?array}> */
	private $requests = array();

	/** @var callable|null */
	private $callback;

	public static function path(): string {
		return dirname( __DIR__ ) . '/fixtures/openai';
	}

	public static function load( string $name ): array {
		$file = self::path() . '/' . $name . '.json';
		if ( ! is_readable( $file ) ) {
			throw new RuntimeException( 'OpenAI fixture not found: ' . $file );
		}
		$decoded = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $decoded ) ) {
			throw new RuntimeException( 'OpenAI fixture is not valid JSON: ' . $file );
		}
		return $decoded;
	}

	public static function to_http_response( array $fixture ): array {
		$headers = array();
		foreach ( (array) ( $fixture['headers'] ?? array() ) as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = $value;
		}
		$body = $fixture['body'] ?? '';
		$code = (int) ( $fixture['status'] ?? 200 );
		return array(
			'headers'  => $headers,
			'body'     => is_string( $body ) ? $body : (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => $code,
				'message' => get_status_header_desc( $code ),
			),
			'cookies'  => array(),
		);
	}

	public function install(): void {
		$this->uninstall();
		$this->callback = function ( $preempt, $args, $url ) {
			if ( false === strpos( (string) $url, 'openai.com' ) ) {
				return $preempt;
			}
			$body             = isset( $args['body'] ) && is_string( $args['body'] ) ? json_decode( $args['body'], true ) : null;
			$this->requests[] = array(
				'method' => (string) ( $args['method'] ?? 'GET' ),
				'url'    => (string) $url,
				'body'   => is_array( $body ) ? $body : null,
			);
			if ( ! $this->queue ) {
				return new WP_Error( 'aat_fixture_unmatched', 'No OpenAI fixture queued for ' . $url );
			}
			return self::to_http_response( array_shift( $this->queue ) );
		};
		add_filter( 'pre_http_request', $this->callback, 10, 3 );
	}

	public function queue( string ...$names ): self {
		foreach ( $names as $name ) {
			$this->queue[] = self::load( $name );
		}
		return $this;
	}

	public function queue_response( array $fixture ): self {
		$this->queue[] = $fixture;
		return $this;
	}

	public function requests(): array {
		return $this->requests;
	}

	public function uninstall(): void {
		if ( $this->callback ) {
			remove_filter( 'pre_http_request', $this->callback, 10 );
			$this->callback = null;
		}
	}
}
