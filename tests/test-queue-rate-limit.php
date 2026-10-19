<?php
/**
 * A 429 (rate limit) must never consume a queue attempt: the job waits until
 * the limit resets, and the run stops.
 */
class Auto_Alt_Text_Queue_Rate_Limit_Test extends WP_UnitTestCase {
    private $queue;
    private $requests = 0;
    private $mode = 429;

    public function setUp(): void {
        parent::setUp();
        Auto_Alt_Text_Activator::activate();
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . Auto_Alt_Text_Queue::get_table_name());
        (new Auto_Alt_Text_OpenAI())->save_api_key('sk-test-not-real');
        (new Auto_Alt_Text_Rate_Limiter())->reset();
        $this->queue = new Auto_Alt_Text_Queue();
        $this->requests = 0;
        $this->mode = 429;
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (strpos($url, 'openai.com') === false) {
                return $pre;
            }
            $this->requests++;
            if ('too_large' === $this->mode) {
                return [
                    'headers'  => [],
                    'body'     => wp_json_encode(['error' => ['message' => 'Request too large for gpt-x in organization org-1 on tokens per min (TPM): Limit 30000, Requested 45000.', 'type' => 'tokens', 'code' => 'rate_limit_exceeded']]),
                    'response' => ['code' => 429, 'message' => 'Too Many Requests'],
                    'cookies'  => [],
                ];
            }
            if (429 === $this->mode) {
                return [
                    'headers'  => ['retry-after' => '20'],
                    'body'     => wp_json_encode(['error' => ['message' => 'Rate limit reached', 'type' => 'requests', 'code' => 'rate_limit_exceeded']]),
                    'response' => ['code' => 429, 'message' => 'Too Many Requests'],
                    'cookies'  => [],
                ];
            }
            return [
                'headers'  => [],
                'body'     => wp_json_encode([
                    'status' => 'completed',
                    'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'A canola field.']]]],
                    'usage'  => ['input_tokens' => 100, 'output_tokens' => 20, 'total_tokens' => 120],
                ]),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
            ];
        }, 10, 3);
    }

    public function tearDown(): void {
        (new Auto_Alt_Text_Rate_Limiter())->reset();
        parent::tearDown();
    }

    private function image() {
        return self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
    }

    private function get_job($attachment_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Auto_Alt_Text_Queue::get_table_name() . ' WHERE attachment_id = %d', $attachment_id
        ));
    }

    private function make_due($attachment_id) {
        global $wpdb;
        $wpdb->update(Auto_Alt_Text_Queue::get_table_name(),
            ['next_attempt_at' => gmdate('Y-m-d H:i:s', time() - 5)], ['attachment_id' => $attachment_id]);
    }

    public function test_eight_rate_limited_runs_never_fail_the_job() {
        $id = $this->image();
        $this->queue->enqueue($id, 'upload');
        for ($i = 0; $i < 8; $i++) {
            (new Auto_Alt_Text_Rate_Limiter())->reset();
            $this->make_due($id);
            $this->queue->run();
        }
        $job = $this->get_job($id);
        $this->assertSame(8, $this->requests);
        $this->assertSame(Auto_Alt_Text_Queue::STATUS_PENDING, $job->status);
        $this->assertSame('0', $job->attempts);
        $this->assertNotEmpty($job->last_error);
        $this->assertSame('', get_post_meta($id, '_wp_attachment_image_alt', true));
    }

    public function test_rate_limit_mid_run_stops_the_run_and_releases_the_rest() {
        // A processor double that never touches the limiter, so only the
        // `return false` in process_job() can stop the run.
        $calls = 0;
        $queue = new Auto_Alt_Text_Queue(function () use (&$calls) {
            $calls++;
            return ['status' => 'rate_limit', 'error' => 'Slow down'];
        });
        $ids = [$this->image(), $this->image(), $this->image()];
        foreach ($ids as $id) {
            $queue->enqueue($id, 'upload');
        }
        global $wpdb;
        $before = [];
        foreach ($ids as $id) {
            $before[$id] = $this->get_job($id);
        }

        $queue->run();

        $this->assertSame(1, $calls, 'The run stops after the first rate limit');
        $first = true;
        foreach ($ids as $id) {
            $job = $this->get_job($id);
            $this->assertSame(Auto_Alt_Text_Queue::STATUS_PENDING, $job->status);
            $this->assertSame('0', $job->attempts);
            if ($first) {
                $this->assertSame('Slow down', $job->last_error);
                $this->assertGreaterThan($before[$id]->next_attempt_at, $job->next_attempt_at);
                $first = false;
            } else {
                $this->assertSame($before[$id]->last_error, $job->last_error);
                $this->assertSame($before[$id]->next_attempt_at, $job->next_attempt_at);
            }
        }
    }

    public function test_sync_generate_does_not_sleep_through_another_requests_cooldown() {
        (new Auto_Alt_Text_Rate_Limiter())->record_rate_limit_error(['retry-after' => '30'], 1);
        $id = $this->image();
        $openai = new Auto_Alt_Text_OpenAI();
        $openai->save_api_key('sk-test-not-real');

        $start = microtime(true);
        $result = $openai->generate_alt_text(wp_get_attachment_url($id), $id, 'manual');
        $elapsed = microtime(true) - $start;

        $this->assertNull($result);
        $this->assertSame(0, $this->requests, 'No request is sent into a known cooldown');
        $this->assertLessThan(3, $elapsed);
        $this->assertSame('rate_limit', $openai->get_last_error_class());
        $this->assertStringContainsString('rate limit', $openai->get_last_error());
    }

    public function test_request_too_large_429_fails_the_job_at_once_without_a_cooldown() {
        $this->mode = 'too_large';
        $id = $this->image();
        $this->queue->enqueue($id, 'upload');
        $this->queue->run();

        $job = $this->get_job($id);
        $this->assertSame(Auto_Alt_Text_Queue::STATUS_FAILED, $job->status);
        $this->assertStringContainsString('larger than your openai token-per-minute limit', strtolower($job->last_error));
        $this->assertTrue((new Auto_Alt_Text_Rate_Limiter())->can_make_request());
    }

    public function test_limiter_blocks_until_reset_and_job_waits_then_succeeds() {
        $id = $this->image();
        $this->queue->enqueue($id, 'upload');
        $this->queue->run();

        $limiter = new Auto_Alt_Text_Rate_Limiter();
        $this->assertFalse($limiter->can_make_request(), 'The limiter must know about the 429 from the queue\'s single attempt');
        $this->assertGreaterThan(15, $limiter->get_recommended_delay());

        $job = $this->get_job($id);
        $wait = strtotime($job->next_attempt_at . ' UTC') - time();
        $this->assertGreaterThanOrEqual(29, $wait, 'At least the 30 s floor');
        $this->assertLessThanOrEqual(HOUR_IN_SECONDS + 1, $wait);

        // A run before the reset does nothing.
        $this->queue->run();
        $this->assertSame(1, $this->requests);

        // Limit over: the job is due and the limiter allows requests.
        $limiter->reset();
        $this->make_due($id);
        $this->mode = 200;
        $this->queue->run();

        $this->assertSame(Auto_Alt_Text_Queue::STATUS_DONE, $this->get_job($id)->status);
        $this->assertSame('A canola field.', get_post_meta($id, '_wp_attachment_image_alt', true));
    }
}
