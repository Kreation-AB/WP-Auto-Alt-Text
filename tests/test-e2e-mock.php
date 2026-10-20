<?php
/* Tests for the e2e OpenAI mock mu-plugin (tests/e2e/mu-plugins/aat-mock-openai.php). */

class Auto_Alt_Text_E2E_Mock_Test extends WP_UnitTestCase {
	public function set_up() {
		parent::set_up();
		add_filter( 'pre_http_request', 'aat_e2e_mock_filter', 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', 'aat_e2e_mock_filter', 10 );
		delete_option( 'aat_e2e_next_fixture' );
		parent::tear_down();
	}

	public function test_mock_registers_nothing_without_the_e2e_constant() {
		remove_filter( 'pre_http_request', 'aat_e2e_mock_filter', 10 );
		$this->assertFalse( defined( 'AAT_E2E_MOCK' ) );
		$this->assertFalse( has_filter( 'pre_http_request', 'aat_e2e_mock_filter' ) );

		// Positive control: the check can fail.
		aat_e2e_mock_bootstrap( true );
		$this->assertNotFalse( has_filter( 'pre_http_request', 'aat_e2e_mock_filter' ) );
		remove_filter( 'pre_http_request', 'aat_e2e_mock_filter' );
		$this->assertFalse( has_filter( 'pre_http_request', 'aat_e2e_mock_filter' ) );
	}

	public function test_routes_models_and_responses_to_fixtures() {
		$list = wp_remote_get( 'https://api.openai.com/v1/models' );
		$this->assertSame( 200, wp_remote_retrieve_response_code( $list ) );
		$this->assertArrayHasKey( 'data', json_decode( wp_remote_retrieve_body( $list ), true ) );

		$model = wp_remote_get( 'https://api.openai.com/v1/models/gpt-6.1-sol' );
		$this->assertSame( 200, wp_remote_retrieve_response_code( $model ) );

		$response = wp_remote_post( 'https://api.openai.com/v1/responses', array( 'body' => '{}' ) );
		$this->assertSame( 200, wp_remote_retrieve_response_code( $response ) );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$this->assertSame( 'completed', $body['status'] );
	}

	public function test_next_fixture_is_used_once() {
		update_option( 'aat_e2e_next_fixture', 'responses-refusal' );
		$expected = Auto_Alt_Text_Test_OpenAI_Fixtures::load( 'responses-refusal' )['body'];
		$expected = is_string( $expected ) ? json_decode( $expected, true ) : $expected;

		$first = wp_remote_post( 'https://api.openai.com/v1/responses', array( 'body' => '{}' ) );
		$this->assertSame( $expected, json_decode( wp_remote_retrieve_body( $first ), true ) );
		$this->assertFalse( get_option( 'aat_e2e_next_fixture' ) );

		$second = wp_remote_post( 'https://api.openai.com/v1/responses', array( 'body' => '{}' ) );
		$this->assertSame( 'completed', json_decode( wp_remote_retrieve_body( $second ), true )['status'] );
	}

	public function test_unmatched_openai_request_is_an_error() {
		$result = wp_remote_get( 'https://api.openai.com/v1/files' );
		$this->assertWPError( $result );
		$this->assertSame( 'aat_e2e_unmatched_openai_request', $result->get_error_code() );
	}
}
