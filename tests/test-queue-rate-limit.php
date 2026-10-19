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
        $ids = [$this->image(), $this->image(), $this->image()];
        foreach ($ids as $id) {
            $this->queue->enqueue($id, 'upload');
        }
        $this->queue->run();

        $this->assertSame(1, $this->requests, 'No further OpenAI request after the 429');
        foreach ($ids as $id) {
            $job = $this->get_job($id);
            $this->assertSame(Auto_Alt_Text_Queue::STATUS_PENDING, $job->status);
            $this->assertSame('0', $job->attempts);
        }
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
