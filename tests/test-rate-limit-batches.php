<?php
/**
 * During a rate-limit cooldown the web batch loops must stop at the first
 * rate-limited image instead of failing the whole list, and a caller that opts
 * in (WP-CLI) waits through the cooldown.
 */
trait Auto_Alt_Text_Rate_Limit_Batch_Helpers {
    private $http_requests = 0;

    private function mock_openai_success() {
        $this->http_requests = 0;
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (strpos($url, 'openai.com') === false) {
                return $pre;
            }
            $this->http_requests++;
            return [
                'headers'  => [],
                'body'     => wp_json_encode([
                    'status' => 'completed',
                    'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'A canola field.']]]],
                    'usage'  => ['total_tokens' => 42],
                ]),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
            ];
        }, 10, 3);
    }

    private function start_cooldown($seconds) {
        $limiter = new Auto_Alt_Text_Rate_Limiter();
        $limiter->reset();
        $limiter->record_rate_limit_error(['retry-after' => (string) $seconds], 1);
    }

    private function make_images($n) {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $ids[] = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        }
        return $ids;
    }

    private function assert_stopped_on_rate_limit(array $results, array $ids) {
        $this->assertSame(0, $this->http_requests);
        $this->assertTrue($results[$ids[0]]['rate_limited']);
        $this->assertFalse($results[$ids[0]]['success']);
        foreach (array_slice($ids, 1) as $id) {
            $this->assertTrue($results[$id]['not_processed']);
            $this->assertFalse($results[$id]['success']);
            $this->assertStringContainsString('rate limit', $results[$id]['message']);
        }
        foreach ($ids as $id) {
            $this->assertSame('', get_post_meta($id, '_wp_attachment_image_alt', true));
        }
    }
}

class Auto_Alt_Text_Rate_Limit_Rest_Batch_Test extends WP_UnitTestCase {
    use Auto_Alt_Text_Rate_Limit_Batch_Helpers;

    public function setUp(): void {
        parent::setUp();
        (new Auto_Alt_Text_OpenAI())->save_api_key('sk-test-not-real');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->mock_openai_success();
    }

    public function tearDown(): void {
        (new Auto_Alt_Text_Rate_Limiter())->reset();
        parent::tearDown();
    }

    public function test_rest_batch_stops_at_the_first_rate_limited_image() {
        $ids = $this->make_images(3);
        $this->start_cooldown(30);

        $request = new WP_REST_Request('POST', '/wp-auto-alt-text/v1/batch');
        $request->set_param('attachment_ids', $ids);
        $data = rest_do_request($request)->get_data();

        $this->assertTrue($data['success']);
        $this->assertTrue($data['rate_limited']);
        $this->assertStringContainsString('rate limit', $data['message']);
        $this->assert_stopped_on_rate_limit($data['results'], $ids);
    }

    public function test_a_caller_with_a_long_max_wait_waits_through_the_cooldown() {
        $ids = $this->make_images(1);
        $this->start_cooldown(3);
        $openai = new Auto_Alt_Text_OpenAI();
        $openai->save_api_key('sk-test-not-real');
        $openai->set_max_wait_seconds(300);

        $start = microtime(true);
        $alt = $openai->generate_alt_text(wp_get_attachment_url($ids[0]), $ids[0], 'cli');

        $this->assertSame('A canola field.', $alt);
        $this->assertGreaterThan(2, microtime(true) - $start, 'It waited for the cooldown');
        $this->assertSame(1, $this->http_requests);
    }
}

class Auto_Alt_Text_Rate_Limit_Ajax_Batch_Test extends WP_Ajax_UnitTestCase {
    use Auto_Alt_Text_Rate_Limit_Batch_Helpers;

    public function setUp(): void {
        parent::setUp();
        (new Auto_Alt_Text_OpenAI())->save_api_key('sk-test-not-real');
        $this->mock_openai_success();
    }

    public function tearDown(): void {
        (new Auto_Alt_Text_Rate_Limiter())->reset();
        parent::tearDown();
    }

    public function test_ajax_batch_stops_at_the_first_rate_limited_image() {
        $this->_setRole('administrator');
        $ids = $this->make_images(3);
        $this->start_cooldown(30);
        $_POST = [
            'nonce' => wp_create_nonce('auto_alt_text_batch_nonce'),
            'ids'   => wp_json_encode($ids),
        ];

        global $wpdb;
        $suppress = $wpdb->suppress_errors(true);
        try {
            $this->_handleAjax('process_image_batch');
        } catch (WPAjaxDieContinueException $e) {
            // wp_send_json_* ends the request.
        } finally {
            $wpdb->suppress_errors($suppress);
        }
        $response = json_decode($this->_last_response, true);

        $this->assertTrue($response['success'], $this->_last_response);
        $this->assertTrue($response['rate_limited']);
        $this->assertStringContainsString('rate limit', $response['message']);
        $this->assert_stopped_on_rate_limit($response['data'], $ids);
    }
}
