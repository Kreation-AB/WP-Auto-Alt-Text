<?php
/* Tests for the staging OpenAI mock (tests/e2e/tools/staging-openai-mock.php). */

require_once __DIR__ . '/e2e/tools/staging-openai-mock.php';

class Auto_Alt_Text_Staging_Openai_Mock_Test extends WP_UnitTestCase {
	public function set_up() {
		parent::set_up();
		add_filter( 'aat_staging_mock_latency_ms', '__return_zero' );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', 'aat_staging_mock_filter', 10 );
		remove_action( 'admin_notices', 'aat_staging_mock_notice' );
		remove_filter( 'aat_staging_mock_latency_ms', '__return_zero' );
		parent::tear_down();
	}

	public function test_registers_nothing_without_the_constant() {
		$this->assertFalse( defined( 'AAT_STAGING_MOCK' ) );
		$this->assertFalse( has_filter( 'pre_http_request', 'aat_staging_mock_filter' ) );
		$this->assertFalse( has_action( 'admin_notices', 'aat_staging_mock_notice' ) );

		// Positive control: the check can fail.
		aat_staging_mock_bootstrap( true );
		$this->assertNotFalse( has_filter( 'pre_http_request', 'aat_staging_mock_filter' ) );
		$this->assertNotFalse( has_action( 'admin_notices', 'aat_staging_mock_notice' ) );
	}

	public function test_responses_returns_the_mock_text_and_request_model() {
		aat_staging_mock_bootstrap( true );
		$response = wp_remote_post( 'https://api.openai.com/v1/responses', array( 'body' => '{"model":"gpt-test"}' ) );
		$this->assertSame( 200, wp_remote_retrieve_response_code( $response ) );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$this->assertSame( 'completed', $body['status'] );
		$this->assertSame( 'gpt-test', $body['model'] );
		$this->assertStringStartsWith( 'MOCKTEXT', $body['output'][0]['content'][0]['text'] );
		$this->assertSame( 1360, $body['usage']['total_tokens'] );
		$this->assertSame( '10000', wp_remote_retrieve_header( $response, 'x-ratelimit-limit-requests' ) );
	}

	public function test_the_plugin_accepts_the_mock_answer() {
		aat_staging_mock_bootstrap( true );
		$id     = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$openai = new Auto_Alt_Text_OpenAI();
		$openai->save_api_key( 'sk-test-not-real' );
		$alt = $openai->generate_alt_text( wp_get_attachment_url( $id ), $id, 'manual' );
		$this->assertSame( 'MOCKTEXT – inte en riktig alt-text.', $alt, (string) $openai->get_last_error() );
	}

	public function test_models_answer_includes_the_requested_id() {
		aat_staging_mock_bootstrap( true );
		$list = wp_remote_get( 'https://api.openai.com/v1/models' );
		$this->assertSame( 200, wp_remote_retrieve_response_code( $list ) );
		$this->assertSame( 'list', json_decode( wp_remote_retrieve_body( $list ), true )['object'] );

		$model = wp_remote_get( 'https://api.openai.com/v1/models/gpt-6.1-sol' );
		$this->assertSame( 'gpt-6.1-sol', json_decode( wp_remote_retrieve_body( $model ), true )['id'] );
	}

	public function test_other_openai_paths_are_404_and_other_hosts_untouched() {
		aat_staging_mock_bootstrap( true );
		$files = wp_remote_get( 'https://api.openai.com/v1/files' );
		$this->assertSame( 404, wp_remote_retrieve_response_code( $files ) );

		$this->assertSame( 'untouched', aat_staging_mock_filter( 'untouched', array( 'method' => 'GET' ), 'https://example.com/v1/responses' ) );
	}

	public function test_notice_is_escaped_swedish_text() {
		ob_start();
		aat_staging_mock_notice();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'OpenAI-mocken är aktiv', $html );
		$this->assertStringContainsString( 'notice', $html );
	}
}
