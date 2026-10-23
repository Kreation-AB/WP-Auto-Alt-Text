<?php
class Auto_Alt_Text_OpenAI_Errors_Test extends WP_UnitTestCase {
    private $openai;
    /** @var Auto_Alt_Text_Test_OpenAI_Fixtures */
    private $fixtures;

    public function setUp(): void {
        parent::setUp();
        delete_option(Auto_Alt_Text_Quota_Monitor::OPTION);
        add_filter('pre_wp_mail', '__return_true');
        $this->openai = new Auto_Alt_Text_OpenAI();
        $this->openai->save_api_key('sk-test-not-real');
        $this->fixtures = new Auto_Alt_Text_Test_OpenAI_Fixtures();
        $this->fixtures->install();
    }

    public function tear_down(): void {
        $this->fixtures->uninstall();
        parent::tear_down();
    }

    private function request_count() {
        return count($this->fixtures->requests());
    }

    /** The recorded completed response with its output text replaced. */
    private static function message($text) {
        $fixture = Auto_Alt_Text_Test_OpenAI_Fixtures::load('responses-completed');
        $fixture['body']['output'][0]['content'][0]['text'] = $text;
        return $fixture;
    }

    /** A 429 insufficient_quota fixture with its message and code replaced. */
    private static function quota_error($message, $code) {
        $fixture = Auto_Alt_Text_Test_OpenAI_Fixtures::load('insufficient-quota');
        $fixture['body']['error']['message'] = $message;
        $fixture['body']['error']['code'] = $code;
        return $fixture;
    }

    /** A 500 server_error fixture (no recorded one exists; synthetic: it only borrows the rate-limited fixture as a base and replaces status, headers and body). */
    private static function server_error() {
        $fixture = Auto_Alt_Text_Test_OpenAI_Fixtures::load('rate-limited');
        $fixture['status'] = 500;
        $fixture['headers'] = ['content-type' => 'application/json'];
        $fixture['body'] = ['error' => ['message' => 'server error', 'type' => 'server_error']];
        return $fixture;
    }

    /** The recorded model-not-found 404 with its error message replaced. */
    private static function model_not_found($message) {
        $fixture = Auto_Alt_Text_Test_OpenAI_Fixtures::load('model-not-found');
        $fixture['body']['error']['message'] = $message;
        return $fixture;
    }

    private function generate() {
        $id = self::factory()->attachment->create_upload_object(
            DIR_TESTDATA . '/images/canola.jpg'
        );
        return [$this->openai->generate_alt_text(wp_get_attachment_url($id), $id, 'manual'), $id];
    }

    public function test_insufficient_quota_is_terminal_and_records_incident() {
        $this->fixtures->queue('insufficient-quota');
        [$result] = $this->generate();
        $this->assertNull($result);
        $this->assertSame('quota', $this->openai->get_last_error_class());
        $this->assertSame(1, $this->request_count(), 'Quota errors must never be retried');
        $this->assertTrue(Auto_Alt_Text_Quota_Monitor::is_active());
    }

    public function test_billing_hard_limit_is_terminal() {
        $this->fixtures->queue_response(self::quota_error('Billing hard limit has been reached', 'billing_hard_limit_reached'));
        [$result] = $this->generate();
        $this->assertNull($result);
        $this->assertSame('quota', $this->openai->get_last_error_class());
        $this->assertSame(1, $this->request_count());
    }

    public function test_server_error_is_transient_with_two_sync_attempts() {
        $this->fixtures->queue_response(self::server_error())->queue_response(self::server_error());
        [$result] = $this->generate();
        $this->assertNull($result);
        $this->assertSame('transient', $this->openai->get_last_error_class());
        $this->assertSame(2, $this->request_count(), 'Sync path retries once (2 attempts total)');
    }

    public function test_malformed_200_is_transient_and_retried() {
        // 200 with empty body: proxy/transport artifact
        $empty = Auto_Alt_Text_Test_OpenAI_Fixtures::load('responses-completed');
        $empty['body'] = '';
        $this->fixtures->queue_response($empty)->queue_response($empty);
        [$result] = $this->generate();
        $this->assertNull($result);
        $this->assertSame('transient', $this->openai->get_last_error_class(), 'A malformed 200 must not be classified permanent');
        $this->assertSame(2, $this->request_count(), 'Sync path retries a malformed 200 once');
    }

    public function test_max_attempts_one_makes_single_request() {
        $this->fixtures->queue_response(self::server_error());
        $this->openai->set_max_api_attempts(1);
        [$result] = $this->generate();
        $this->assertNull($result);
        $this->assertSame(1, $this->request_count());
    }

    public function test_auth_error_has_its_own_class_and_is_not_a_quota_incident() {
        $this->fixtures->queue('unauthorized');
        [$result] = $this->generate();
        $this->assertNull($result);
        $this->assertSame('auth', $this->openai->get_last_error_class());
        $this->assertSame(1, $this->request_count());
        $this->assertFalse(Auto_Alt_Text_Quota_Monitor::is_active());
    }

    /** A 400 invalid_request_error fixture with its message replaced (synthetic, on the rate-limited base). */
    private static function bad_request($message) {
        $fixture = Auto_Alt_Text_Test_OpenAI_Fixtures::load('rate-limited');
        $fixture['status'] = 400;
        $fixture['headers'] = ['content-type' => 'application/json'];
        $fixture['body'] = ['error' => ['message' => $message, 'type' => 'invalid_request_error']];
        return $fixture;
    }

    public function test_openai_download_failures_say_the_image_could_not_be_fetched() {
        $expected = 'OpenAI kunde inte hämta bilden från webbplatsen. Kontrollera att bildfilen finns och går att nå utifrån.';
        $messages = [
            'Error while downloading file. Upstream status code: 404.',
            'Unable to download content from the provided URL before the timeout. Check that the URL is publicly accessible and responds promptly, or upload the file and provide a file_id instead.',
        ];
        foreach ($messages as $message) {
            $this->fixtures->queue_response(self::bad_request($message));
            [$result] = $this->generate();
            $this->assertNull($result);
            $this->assertSame($expected, $this->openai->get_last_error(), $message);
            $this->assertSame('permanent', $this->openai->get_last_error_class(), $message);
        }
    }

    public function test_other_400_errors_keep_the_format_message() {
        $this->fixtures->queue_response(self::bad_request('Unsupported parameter: max_output_tokens'));
        [$result] = $this->generate();
        $this->assertNull($result);
        $this->assertSame('Model request format error. Please verify plugin compatibility with the current OpenAI model.', $this->openai->get_last_error());
    }

    public function test_model_the_key_cannot_use_is_named_in_a_permanent_error() {
        $this->fixtures->queue_response(self::model_not_found('The model `gpt-6-luna` does not exist or you do not have access to it.'));
        $this->openai->set_run_overrides(['model' => 'gpt-6-luna']);

        [$result, $id] = $this->generate();

        $this->assertNull($result);
        $this->assertSame('permanent', $this->openai->get_last_error_class());
        $this->assertSame(
            'The model gpt-6-luna is not available to this OpenAI API key. Choose another model under Auto Alt Text → Settings.',
            $this->openai->get_last_error()
        );
        $this->assertSame(1, $this->request_count());
        $this->assertSame('', get_post_meta($id, '_wp_attachment_image_alt', true));
        $this->assertSame('', get_post_meta($id, Auto_Alt_Text_Provenance::META_KEY, true));
        $this->assertFalse(get_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT));
    }

    public function test_any_404_from_the_responses_endpoint_names_the_requested_model() {
        $not_found = Auto_Alt_Text_Test_OpenAI_Fixtures::load('model-not-found');
        $not_found['body'] = ['error' => ['message' => 'Not found']];
        $this->fixtures->queue_response($not_found);

        $this->generate();

        $this->assertSame('permanent', $this->openai->get_last_error_class());
        $this->assertStringContainsString('The model ' . Auto_Alt_Text_Models::current_id() . ' is not available', $this->openai->get_last_error());
    }

    public function test_success_lifts_the_queue_auth_pause() {
        set_transient('aat_queue_auth_pause', 1, 15 * MINUTE_IN_SECONDS);
        $this->fixtures->queue_response(self::message('A yellow canola field.'));
        [$result] = $this->generate();
        $this->assertSame('A yellow canola field.', $result);
        $this->assertFalse(get_transient('aat_queue_auth_pause'));
    }

    public function test_success_clears_quota_incident() {
        Auto_Alt_Text_Quota_Monitor::record_failure('previous incident');
        $this->fixtures->queue_response(self::message('A yellow canola field.'));
        [$result] = $this->generate();
        $this->assertSame('A yellow canola field.', $result);
        $this->assertFalse(Auto_Alt_Text_Quota_Monitor::is_active());
    }

    /**
     * Fix 1 regression test. Chosen at the OpenAI level (rather than
     * tests/test-queue.php) because reproducing generate_alt_text()'s exact
     * cache-context (resolved language + instruction) from outside the class
     * is brittle; priming the cache via a real, fixture-backed success
     * generate_alt_text() call guarantees the context matches exactly what
     * the cache-hit branch under test will compute.
     */
    public function test_cache_hit_persists_alt_text() {
        $this->fixtures->queue_response(self::message('A yellow canola field.'));
        $id = self::factory()->attachment->create_upload_object(
            DIR_TESTDATA . '/images/canola.jpg'
        );

        // Prime the cache with a real API call, then clear the meta it wrote
        // so the second call can only pass via the cache-hit branch.
        $first = $this->openai->generate_alt_text(wp_get_attachment_url($id), $id, 'manual');
        $this->assertSame('A yellow canola field.', $first);
        $this->assertSame(1, $this->request_count());
        delete_post_meta($id, '_wp_attachment_image_alt');

        $cached = $this->openai->generate_alt_text(wp_get_attachment_url($id), $id, 'manual');

        $this->assertSame('A yellow canola field.', $cached);
        $this->assertSame(1, $this->request_count(), 'Second call must be served from cache, not the API');
        $this->assertSame(
            'A yellow canola field.',
            get_post_meta($id, '_wp_attachment_image_alt', true),
            'Cache-hit path must persist alt text to post meta'
        );
    }

    public function test_missing_api_key_is_permanent_error_class() {
        delete_option('auto_alt_text_api_key');
        $openai = new Auto_Alt_Text_OpenAI();
        $id = self::factory()->attachment->create_upload_object(
            DIR_TESTDATA . '/images/canola.jpg'
        );

        $result = $openai->generate_alt_text(wp_get_attachment_url($id), $id, 'manual');

        $this->assertNull($result);
        $this->assertSame('permanent', $openai->get_last_error_class());
    }
}
