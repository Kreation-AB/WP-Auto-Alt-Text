<?php
class Auto_Alt_Text_Bulk_Runner_Test extends WP_UnitTestCase {
    private $loopbacks = 0;

    public function setUp(): void {
        parent::setUp();
        Auto_Alt_Text_Activator::activate();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Activator::get_runs_table_name());
        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Queue::get_table_name());
        delete_option(Auto_Alt_Text_Quota_Monitor::OPTION);
        delete_option(Auto_Alt_Text_Library_Runner::LAST_TICK_OPTION);
        delete_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT);
        delete_option('aat_library_runner_lock');
        (new Auto_Alt_Text_OpenAI())->save_api_key('sk-test-not-real');
        update_option('auto_alt_text_auto_generate', 1);
        $this->loopbacks = 0;
        add_filter('pre_http_request', function ($pre, $args, $url) {
            $action = is_array($args['body'] ?? null) ? ($args['body']['action'] ?? '') : '';
            if (false !== strpos($url, 'admin-post.php') && Auto_Alt_Text_Library_Runner::TICK_ACTION === $action) {
                $this->loopbacks++;
                return ['headers' => [], 'body' => '', 'response' => ['code' => 204, 'message' => 'No Content'], 'cookies' => [], 'filename' => null];
            }
            if (false !== strpos($url, 'openai.com')) {
                $body = [
                    'status' => 'completed',
                    'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Plugin text.']]]],
                    'usage'  => ['input_tokens' => 10, 'output_tokens' => 3, 'total_tokens' => 13],
                ];
                return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
            }
            return $pre;
        }, 10, 3);
    }

    public function tearDown(): void {
        delete_option(Auto_Alt_Text_Quota_Monitor::OPTION);
        delete_option(Auto_Alt_Text_Library_Runner::LAST_TICK_OPTION);
        delete_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT);
        remove_all_filters('aat_queue_batch_size');
        remove_all_filters('aat_queue_time_budget');
        remove_all_filters('aat_queue_batch_size');
        remove_all_filters('aat_queue_time_budget');
        delete_option('aat_library_runner_lock');
        delete_option('aat_library_counts');
        delete_option('aat_library_start_lock');
        wp_unschedule_hook(Auto_Alt_Text_Library_Scanner::RECOUNT_HOOK);
        parent::tearDown();
    }

    private function image(): int {
        return self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
    }

    private function bulk_job(string $type = 'batch'): int {
        $id = $this->image();
        (new Auto_Alt_Text_Queue())->enqueue_bulk($id, $type);
        return $id;
    }

    private function row_status(int $id): ?string {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            'SELECT status FROM ' . Auto_Alt_Text_Queue::get_table_name() . ' WHERE attachment_id = %d ORDER BY id DESC LIMIT 1',
            $id
        ));
    }

    public function test_maybe_chain_sends_the_loopback_for_due_bulk_work_without_a_run() {
        $this->bulk_job();
        $this->assertTrue(Auto_Alt_Text_Library_Runner::maybe_chain());
        $this->assertSame(1, $this->loopbacks);
    }

    public function test_maybe_chain_does_not_chain_for_upload_only_work() {
        (new Auto_Alt_Text_Queue())->enqueue($this->image(), 'upload');
        $this->assertFalse(Auto_Alt_Text_Library_Runner::maybe_chain());
        $this->assertSame(0, $this->loopbacks);
    }

    public function test_maybe_chain_does_not_chain_bulk_work_under_auth_pause() {
        $this->bulk_job();
        set_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT, 1, 60);
        $this->assertFalse(Auto_Alt_Text_Library_Runner::maybe_chain());
        $this->assertSame(0, $this->loopbacks);
    }

    public function test_maybe_chain_does_not_chain_bulk_work_under_quota_incident() {
        $this->bulk_job();
        Auto_Alt_Text_Quota_Monitor::record_failure('quota');
        $this->assertFalse(Auto_Alt_Text_Library_Runner::maybe_chain());
        $this->assertSame(0, $this->loopbacks);
    }

    public function test_maybe_chain_does_not_chain_bulk_work_without_a_key() {
        $this->bulk_job();
        delete_option('auto_alt_text_api_key');
        $this->assertFalse(Auto_Alt_Text_Library_Runner::maybe_chain());
        $this->assertSame(0, $this->loopbacks);
    }

    public function test_maybe_chain_does_not_chain_bulk_work_while_the_lock_is_held() {
        $this->bulk_job();
        $owner = Auto_Alt_Text_Library_Runner::take_lock();
        $this->assertNotNull($owner);
        $this->assertFalse(Auto_Alt_Text_Library_Runner::maybe_chain());
        $this->assertSame(0, $this->loopbacks);
        Auto_Alt_Text_Library_Runner::release_lock($owner);
    }

    public function test_cron_tick_runs_bulk_work_under_the_lock_and_chains() {
        // One job per tick, so work is left over and the tick chains.
        add_filter('aat_queue_batch_size', fn() => 1);
        add_filter('aat_queue_time_budget', fn() => 0.001);
        $id = $this->bulk_job();
        $this->bulk_job();
        Auto_Alt_Text_Library_Runner::cron_tick();
        $this->assertSame('done', $this->row_status($id));
        $this->assertSame(1, $this->loopbacks);
        $this->assertFalse(Auto_Alt_Text_Library_Runner::lock_held());
    }

    public function test_tick_for_request_returns_null_while_the_lock_is_held() {
        $this->bulk_job();
        $owner = Auto_Alt_Text_Library_Runner::take_lock();
        $this->assertNull(Auto_Alt_Text_Library_Runner::tick_for_request(5.0));
        Auto_Alt_Text_Library_Runner::release_lock($owner);
    }

    public function test_tick_for_request_processes_and_records_last_tick() {
        $this->assertSame(0, Auto_Alt_Text_Library_Runner::last_tick_at());
        $id = $this->bulk_job();
        $before = time();
        $processed = Auto_Alt_Text_Library_Runner::tick_for_request(5.0);
        $this->assertGreaterThanOrEqual(1, $processed);
        $this->assertSame('done', $this->row_status($id));
        $this->assertGreaterThanOrEqual($before, Auto_Alt_Text_Library_Runner::last_tick_at());
    }

    public function test_active_run_chain_rules_are_unchanged() {
        global $wpdb;
        update_option('aat_library_counts', [
            'missing' => 1, 'ai' => 0, 'human' => 0, 'unsupported' => 0,
            'max_id' => (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}"),
            'updated_at' => time(), 'complete' => true,
        ], false);
        $run = Auto_Alt_Text_Library_Run::start('missing', 1);
        $this->assertIsArray($run);
        Auto_Alt_Text_Library_Run::pause($run['id']);
        $this->bulk_job();
        $this->loopbacks = 0;
        $this->assertTrue(Auto_Alt_Text_Library_Runner::maybe_chain(), 'A paused run no longer blocks chaining for bulk work');
        $this->assertSame(1, $this->loopbacks);
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Auto_Alt_Text_Queue::get_table_name() . ' WHERE run_id = %d AND status <> %s',
            $run['id'], Auto_Alt_Text_Queue::STATUS_PENDING
        )));
    }
}
