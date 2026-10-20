<?php
/* Tests for the OpenAI fixture helper, the synthetic fixtures and the redaction function. */

class Auto_Alt_Text_OpenAI_Fixtures_Test extends WP_UnitTestCase {
	/** @var Auto_Alt_Text_Test_OpenAI_Fixtures */
	private $fixtures;

	public function set_up() {
		parent::set_up();
		$this->fixtures = new Auto_Alt_Text_Test_OpenAI_Fixtures();
		$this->fixtures->install();
	}

	public function tear_down() {
		$this->fixtures->uninstall();
		parent::tear_down();
	}

	private function post() {
		return wp_remote_post( 'https://api.openai.com/v1/responses', array( 'body' => '{"a":1}' ) );
	}

	public function test_load_reads_a_fixture_and_lowercases_headers() {
		$fixture = Auto_Alt_Text_Test_OpenAI_Fixtures::load( 'rate-limited' );
		$this->assertSame( 429, $fixture['status'] );
		$this->assertArrayHasKey( 'retry-after', $fixture['headers'] );
		$this->assertSame( 'requests', $fixture['body']['error']['type'] );

		$response = Auto_Alt_Text_Test_OpenAI_Fixtures::to_http_response(
			array(
				'status'  => 429,
				'headers' => array(
					'Retry-After'               => '20',
					'X-RateLimit-Reset-Requests' => '1s',
				),
				'body'    => array( 'error' => array() ),
			)
		);
		foreach ( array_keys( $response['headers'] ) as $key ) {
			$this->assertSame( strtolower( $key ), $key );
		}
		$this->assertSame( '20', $response['headers']['retry-after'] );
	}

	public function test_installed_fixtures_are_served_in_order_and_recorded() {
		$this->fixtures->queue( 'responses-refusal', 'rate-limited' )
			->queue_response( array( 'status' => 200, 'headers' => array(), 'body' => '' ) );

		$first  = $this->post();
		$second = $this->post();
		$third  = $this->post();

		$this->assertSame( 200, wp_remote_retrieve_response_code( $first ) );
		$this->assertSame( 429, wp_remote_retrieve_response_code( $second ) );
		$this->assertSame( 200, wp_remote_retrieve_response_code( $third ) );
		$this->assertSame( '', wp_remote_retrieve_body( $third ) );
		$this->assertSame( array( 'a' => 1 ), $this->fixtures->requests()[0]['body'] );
		$this->assertCount( 3, $this->fixtures->requests() );
	}

	public function test_empty_queue_returns_an_error() {
		$response = $this->post();
		$this->assertWPError( $response );
		$this->assertSame( 'aat_fixture_unmatched', $response->get_error_code() );
	}

	public function test_redaction_removes_ids_headers_and_key_like_strings() {
		$redacted = aat_redact_openai_fixture(
			array(
				'status'  => 200,
				'headers' => array(
					'Authorization'              => 'Bearer sk-proj-ABCDEFGHIJKLMNOP',
					'OpenAI-Organization'        => 'org-secret',
					'X-Request-Id'               => 'req_123',
					'Set-Cookie'                 => 'a=b',
					'Content-Type'               => 'application/json',
					'Retry-After'                => '20',
					'X-RateLimit-Reset-Tokens'   => '6m0s',
					'x-unknown-header'           => 'drop me',
				),
				'body'    => array(
					'id'     => 'resp_abc123',
					'error'  => array( 'message' => 'Incorrect key sk-proj-****lq0A for org-AbC123 in proj_Xyz9 by user-Q1w2' ),
					'output' => array(
						array(
							'id'   => 'msg_x',
							'text' => 'leaked sk-proj-ABCDEFGHIJKLMNOP here',
						),
						array( 'id' => 'rs_abc' ),
					),
				),
			)
		);

		$this->assertSame(
			array( 'Content-Type', 'Retry-After', 'X-RateLimit-Reset-Tokens' ),
			array_keys( $redacted['headers'] )
		);
		$this->assertSame( 'resp_redacted', $redacted['body']['id'] );
		$this->assertSame( 'msg_redacted', $redacted['body']['output'][0]['id'] );
		$this->assertSame( 'rs_redacted', $redacted['body']['output'][1]['id'] );
		$this->assertStringContainsString( 'sk-redacted', $redacted['body']['output'][0]['text'] );
		$this->assertSame(
			'Incorrect key sk-redacted for org-redacted in proj_redacted by user-redacted',
			$redacted['body']['error']['message']
		);
		$json = wp_json_encode( $redacted );
		foreach ( array( 'ABCDEFGHIJKLMNOP', 'lq0A', 'AbC123', 'Xyz9', 'Q1w2', 'org-secret' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $json );
		}
	}

	public function test_redaction_filters_a_models_list_to_openai_models() {
		$redacted = aat_redact_openai_fixture(
			array(
				'status' => 200,
				'body'   => array(
					'object' => 'list',
					'data'   => array(
						array( 'id' => 'gpt-6.1-sol', 'owned_by' => 'system' ),
						array( 'id' => 'whisper-1', 'owned_by' => 'openai-internal' ),
						array( 'id' => 'custom', 'owned_by' => 'org-AbC123' ),
						array( 'id' => 'ft:gpt:acme', 'owned_by' => 'system' ),
					),
				),
			)
		);
		$this->assertSame( array( 'gpt-6.1-sol', 'whisper-1' ), array_column( $redacted['body']['data'], 'id' ) );
	}

	public function test_validation_accepts_good_and_rejects_bad_recordings() {
		$ok = array( 'status' => 404, 'body' => array( 'error' => array() ) );
		$this->assertNull( aat_validate_recorded_fixture( 'model-not-found', $ok ) );

		$this->assertStringContainsString( '429', (string) aat_validate_recorded_fixture( 'models-list', array( 'status' => 429, 'body' => array( 'error' => array() ) ) ) );
		$this->assertNotNull( aat_validate_recorded_fixture( 'models-list', array( 'status' => 200, 'body' => '<html>Cloudflare</html>' ) ) );

		$incomplete = array(
			'status' => 200,
			'body'   => array( 'status' => 'incomplete', 'incomplete_details' => array( 'reason' => 'content_filter' ) ),
		);
		$this->assertNotNull( aat_validate_recorded_fixture( 'responses-incomplete-max-tokens', $incomplete ) );
		$incomplete['body']['incomplete_details']['reason'] = 'max_output_tokens';
		$this->assertNull( aat_validate_recorded_fixture( 'responses-incomplete-max-tokens', $incomplete ) );

		$completed = array( 'status' => 200, 'body' => array( 'status' => 'completed', 'output' => array( array( 'content' => array( array( 'type' => 'refusal' ) ) ) ) ) );
		$this->assertNotNull( aat_validate_recorded_fixture( 'responses-completed', $completed ) );
		$completed['body']['output'][0]['content'][0]['type'] = 'output_text';
		$this->assertNull( aat_validate_recorded_fixture( 'responses-completed', $completed ) );
	}

	public function test_every_synthetic_fixture_is_marked() {
		$names = array( 'responses-refusal', 'rate-limited', 'insufficient-quota', 'responses-incomplete-content-filter' );
		foreach ( $names as $name ) {
			$this->assertTrue( Auto_Alt_Text_Test_OpenAI_Fixtures::load( $name )['_synthetic'] ?? false, $name );
		}
	}
}
