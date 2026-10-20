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
					'authorization'       => 'Bearer sk-proj-ABCDEFGHIJKLMNOP',
					'openai-organization' => 'org-secret',
					'x-request-id'        => 'req_123',
					'content-type'        => 'application/json',
				),
				'body'    => array(
					'id'     => 'resp_abc123',
					'output' => array(
						array(
							'id'   => 'msg_x',
							'text' => 'leaked sk-proj-ABCDEFGHIJKLMNOP here',
						),
					),
				),
			)
		);

		$this->assertArrayNotHasKey( 'authorization', $redacted['headers'] );
		$this->assertArrayNotHasKey( 'openai-organization', $redacted['headers'] );
		$this->assertArrayNotHasKey( 'x-request-id', $redacted['headers'] );
		$this->assertSame( 'application/json', $redacted['headers']['content-type'] );
		$this->assertSame( 'resp_redacted', $redacted['body']['id'] );
		$this->assertSame( 'msg_redacted', $redacted['body']['output'][0]['id'] );
		$this->assertStringContainsString( 'sk-redacted', $redacted['body']['output'][0]['text'] );
		$this->assertStringNotContainsString( 'ABCDEFGHIJKLMNOP', wp_json_encode( $redacted ) );
	}

	public function test_every_synthetic_fixture_is_marked() {
		$names = array( 'responses-refusal', 'rate-limited', 'insufficient-quota', 'responses-incomplete-content-filter' );
		foreach ( $names as $name ) {
			$this->assertTrue( Auto_Alt_Text_Test_OpenAI_Fixtures::load( $name )['_synthetic'] ?? false, $name );
		}
	}
}
