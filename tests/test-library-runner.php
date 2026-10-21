<?php
class Auto_Alt_Text_Library_Runner_Test extends WP_UnitTestCase {
    private $calls = [];
    private $requests = [];

    public function setUp(): void {
        parent::setUp();
        Auto_Alt_Text_Activator::activate();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Activator::get_runs_table_name());
        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Queue::get_table_name());
        delete_option(Auto_Alt_Text_Quota_Monitor::OPTION);
        delete_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT);
        delete_option('aat_library_runner_lock');
        (new Auto_Alt_Text_OpenAI())->save_api_key('sk-test-not-real');
        update_option('auto_alt_text_auto_generate', 1);
        $this->calls = [];
        $this->requests = [];
        add_filter('pre_http_request', function ($pre, $args, $url) {
            $this->requests[] = ['url' => $url, 'args' => $args];
            return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
        }, 10, 3);
    }

    public function tearDown(): void {
        delete_option(Auto_Alt_Text_Quota_Monitor::OPTION);
        delete_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT);
        delete_option('aat_library_runner_lock');
        delete_option('aat_library_counts');
        delete_option('aat_library_start_lock');
        unset($_POST['token']);
        wp_unschedule_hook(Auto_Alt_Text_Library_Scanner::RECOUNT_HOOK);
        parent::tearDown();
    }

    private function start_run(): array {
        global $wpdb;
        update_option('aat_library_counts', [
            'missing' => 1, 'ai' => 0, 'human' => 0, 'unsupported' => 0,
            'max_id' => (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}"),
            'updated_at' => time(), 'complete' => true,
        ], false);
        $run = Auto_Alt_Text_Library_Run::start('missing', 1);
        $this->assertIsArray($run);
        $this->requests = []; // Starting a run chains a tick of its own.
        return $run;
    }

    private function set_tick(int $id, ?string $when): void {
        global $wpdb;
        $wpdb->update(Auto_Alt_Text_Activator::get_runs_table_name(), ['last_tick_at' => $when], ['id' => $id]);
    }

    private function upload_jobs(int $n): void {
        for ($i = 0; $i < $n; $i++) {
            $id = self::factory()->attachment->create(['post_mime_type' => 'image/jpeg']);
            (new Auto_Alt_Text_Queue())->enqueue($id, 'upload');
        }
    }

    public function test_run_with_a_budget_processes_until_the_budget_is_spent() {
        add_filter('aat_queue_batch_size', fn() => 1);
        $this->upload_jobs(20);
        $processor = function (int $id): array {
            usleep(200000);
            $this->calls[] = $id;
            return ['status' => 'success', 'error' => null];
        };
        $processed = (new Auto_Alt_Text_Queue($processor))->run(1.0);
        $this->assertGreaterThanOrEqual(4, count($this->calls));
        $this->assertLessThanOrEqual(6, count($this->calls));
        $this->assertSame(count($this->calls), $processed);

        $this->calls = [];
        remove_all_filters('aat_queue_batch_size');
        $processor = function (int $id): array {
            $this->calls[] = $id;
            return ['status' => 'success', 'error' => null];
        };
        (new Auto_Alt_Text_Queue($processor))->run();
        $this->assertCount(5, $this->calls);
    }

    public function test_run_with_a_budget_stops_early_when_nothing_is_claimable() {
        $this->upload_jobs(3);
        $processor = function (int $id): array {
            $this->calls[] = $id;
            return ['status' => 'success', 'error' => null];
        };
        $start = microtime(true);
        $processed = (new Auto_Alt_Text_Queue($processor))->run(5.0);
        $this->assertCount(3, $this->calls);
        $this->assertSame(3, $processed);
        $this->assertLessThan(1.0, microtime(true) - $start);
    }

    public function test_the_lock_is_atomic_owned_and_taken_over_when_stale() {
        global $wpdb;
        $first = Auto_Alt_Text_Library_Runner::take_lock();
        $this->assertNotNull($first);
        $this->assertTrue(Auto_Alt_Text_Library_Runner::lock_held());
        $this->assertNull(Auto_Alt_Text_Library_Runner::take_lock());

        Auto_Alt_Text_Library_Runner::release_lock('someone-else');
        $this->assertTrue(Auto_Alt_Text_Library_Runner::lock_held());

        $wpdb->update($wpdb->options, ['option_value' => sprintf('%010d|%s', time() - 5, $first)], ['option_name' => 'aat_library_runner_lock']);
        $this->assertFalse(Auto_Alt_Text_Library_Runner::lock_held());
        $second = Auto_Alt_Text_Library_Runner::take_lock();
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);

        // The first runner's late release must not delete the second's lock.
        Auto_Alt_Text_Library_Runner::release_lock($first);
        $this->assertTrue(Auto_Alt_Text_Library_Runner::lock_held());
        Auto_Alt_Text_Library_Runner::release_lock($second);
        $this->assertFalse(Auto_Alt_Text_Library_Runner::lock_held());
    }

    /** Backs the only pending job off by the given seconds. */
    private function back_off_pending(int $seconds): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . Auto_Alt_Text_Queue::get_table_name() . ' SET next_attempt_at = %s WHERE status = %s',
            gmdate('Y-m-d H:i:s', time() + $seconds), 'pending'
        ));
    }

    /** A run whose only job waits in backoff, so a tick processes nothing. */
    private function start_backed_off_run(int $seconds): array {
        self::factory()->attachment->create(['post_mime_type' => 'image/jpeg']);
        $run = $this->start_run();
        $this->assertSame(1, (new Auto_Alt_Text_Queue(fn() => ['status' => 'retry', 'error' => 'x']))->run(5.0));
        $this->back_off_pending($seconds);
        return $run;
    }

    /** @return array{0:?float,1:int} The idle wait seen (null if none) and the chains sent after it. */
    private function tick_and_observe(): array {
        $log = [];
        add_filter('aat_library_idle_wait', function ($wait) use (&$log) {
            $log[] = ['wait', $wait];
            return 0;
        });
        add_filter('pre_http_request', function ($pre) use (&$log) {
            $log[] = ['chain'];
            return $pre;
        }, 5);
        $_POST['token'] = Auto_Alt_Text_Library_Runner::token();
        try {
            Auto_Alt_Text_Library_Runner::handle_tick_request();
            $this->fail('Expected wp_die');
        } catch (WPDieException $e) {
            $this->assertSame(204, $e->getCode());
        }
        $this->assertSame('wait', $log[0][0] ?? null);
        return [$log[0][1] ?? null, count($log) - 1];
    }

    public function test_an_idle_tick_waits_before_it_chains() {
        $this->start_backed_off_run(30);
        [$wait, $chains] = $this->tick_and_observe();
        $this->assertSame(20.0, $wait);
        $this->assertSame(1, $chains);
    }

    public function test_the_idle_wait_ignores_due_rows_the_queue_cannot_claim() {
        $this->start_backed_off_run(30);
        // A due upload job that auto-generate being off keeps unclaimable.
        update_option('auto_alt_text_auto_generate', 0);
        $id = self::factory()->attachment->create(['post_mime_type' => 'image/jpeg']);
        (new Auto_Alt_Text_Queue())->enqueue($id, 'upload');
        [$wait] = $this->tick_and_observe();
        $this->assertSame(20.0, $wait);
    }

    private function set_fed_all(int $id, int $fed_all): void {
        global $wpdb;
        $wpdb->update(Auto_Alt_Text_Activator::get_runs_table_name(), ['fed_all' => $fed_all], ['id' => $id]);
    }

    public function test_a_long_backoff_with_images_left_to_feed_still_chains() {
        $run = $this->start_backed_off_run(2 * HOUR_IN_SECONDS);
        $this->set_fed_all($run['id'], 0);
        $this->assertTrue(Auto_Alt_Text_Library_Runner::maybe_chain());
        $this->set_tick($run['id'], gmdate('Y-m-d H:i:s', time() - 11 * MINUTE_IN_SECONDS));
        $this->assertTrue(Auto_Alt_Text_Library_Runner::health(Auto_Alt_Text_Library_Run::get($run['id']))['stalled']);
    }

    public function test_nothing_claimable_and_nothing_to_feed_waits_the_full_20_seconds() {
        global $wpdb;
        $run = $this->start_run();
        $id = self::factory()->attachment->create(['post_mime_type' => 'image/jpeg']);
        $wpdb->insert(Auto_Alt_Text_Queue::get_table_name(), [
            'attachment_id' => $id, 'generation_type' => 'library', 'run_id' => $run['id'], 'status' => 'processing',
            'attempts' => 0, 'next_attempt_at' => gmdate('Y-m-d H:i:s'), 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->set_fed_all($run['id'], 1);
        [$wait] = $this->tick_and_observe();
        $this->assertSame(20.0, $wait);
    }

    public function test_a_long_backoff_is_left_to_the_cron_tick() {
        $run = $this->start_backed_off_run(2 * HOUR_IN_SECONDS);
        $this->set_fed_all($run['id'], 1);
        $this->assertFalse(Auto_Alt_Text_Library_Runner::maybe_chain());
        $this->assertSame([], $this->requests);

        $this->set_tick($run['id'], gmdate('Y-m-d H:i:s', time() - 11 * MINUTE_IN_SECONDS));
        $this->assertFalse(Auto_Alt_Text_Library_Runner::health(Auto_Alt_Text_Library_Run::get($run['id']))['stalled']);

        $this->back_off_pending(-5);
        $this->assertTrue(Auto_Alt_Text_Library_Runner::health(Auto_Alt_Text_Library_Run::get($run['id']))['stalled']);
        $this->assertTrue(Auto_Alt_Text_Library_Runner::maybe_chain());
    }

    public function test_a_garbage_lock_value_does_not_hold_the_lock() {
        update_option('aat_library_runner_lock', 'garbage', false);
        $this->assertFalse(Auto_Alt_Text_Library_Runner::lock_held());
        $this->assertNotNull(Auto_Alt_Text_Library_Runner::take_lock());
    }

    public function test_resuming_a_run_stamps_its_tick() {
        $run = $this->start_run();
        Auto_Alt_Text_Library_Run::pause($run['id']);
        $this->assertNull(Auto_Alt_Text_Library_Run::get($run['id'])['last_tick_at']);
        Auto_Alt_Text_Library_Run::resume($run['id']);
        $this->assertNotNull(Auto_Alt_Text_Library_Run::get($run['id'])['last_tick_at']);
    }

    public function test_maybe_chain_needs_an_api_key_and_an_array_token_is_rejected() {
        $this->start_run();
        delete_option('auto_alt_text_api_key');
        if (!defined('AUTO_ALT_TEXT_OPENAI_API_KEY')) {
            $this->assertFalse(Auto_Alt_Text_Library_Runner::maybe_chain());
        }
        $_POST['token'] = ['x'];
        try {
            Auto_Alt_Text_Library_Runner::handle_tick_request();
            $this->fail('Expected wp_die');
        } catch (WPDieException $e) {
            $this->assertSame(403, $e->getCode());
        }
    }

    public function test_without_an_api_key_a_tick_leaves_no_stamp_and_health_says_why() {
        if (defined('AUTO_ALT_TEXT_OPENAI_API_KEY') && AUTO_ALT_TEXT_OPENAI_API_KEY) {
            $this->markTestSkipped('AUTO_ALT_TEXT_OPENAI_API_KEY is defined in this environment');
        }
        self::factory()->attachment->create(['post_mime_type' => 'image/jpeg']);
        $run = $this->start_run();
        $this->assertFalse(Auto_Alt_Text_Library_Runner::health($run)['no_api_key']);

        delete_option('auto_alt_text_api_key');
        Auto_Alt_Text_Library_Runner::cron_tick();
        $after = Auto_Alt_Text_Library_Run::get($run['id']);
        $this->assertSame('running', $after['status']);
        $this->assertNull($after['last_tick_at'], 'A tick that cannot call the API is no progress');
        $this->assertTrue(Auto_Alt_Text_Library_Runner::health($after)['no_api_key']);
    }

    public function test_loopback_args_are_filterable() {
        $this->start_run();
        add_filter('aat_library_loopback_args', fn($args) => $args + ['headers' => ['Authorization' => 'Basic x']]);
        Auto_Alt_Text_Library_Runner::maybe_chain();
        $this->assertSame('Basic x', $this->requests[0]['args']['headers']['Authorization']);
    }

    public function test_maybe_chain_sends_a_non_blocking_loopback_while_a_run_is_active() {
        $this->assertFalse(Auto_Alt_Text_Library_Runner::maybe_chain());
        $this->assertSame([], $this->requests);

        $run = $this->start_run();
        $this->assertTrue(Auto_Alt_Text_Library_Runner::maybe_chain());
        $this->assertCount(1, $this->requests);
        $request = $this->requests[0];
        $this->assertStringEndsWith('admin-post.php', $request['url']);
        $this->assertFalse($request['args']['blocking']);
        $this->assertSame('aat_library_tick', $request['args']['body']['action']);
        $token = get_option(Auto_Alt_Text_Library_Runner::TOKEN_OPTION);
        $this->assertSame(32, strlen($token));
        $this->assertSame($token, $request['args']['body']['token']);

        $this->requests = [];
        $this->assertNotNull(Auto_Alt_Text_Library_Runner::take_lock());
        $this->assertFalse(Auto_Alt_Text_Library_Runner::maybe_chain());
        delete_option('aat_library_runner_lock');

        update_option(Auto_Alt_Text_Quota_Monitor::OPTION, [
            'active' => true, 'detected_at' => time(), 'last_probe_at' => time(),
            'last_error' => 'm', 'email_sent' => true, 'notice_dismissed' => false,
        ]);
        $this->assertFalse(Auto_Alt_Text_Library_Runner::maybe_chain());
        delete_option(Auto_Alt_Text_Quota_Monitor::OPTION);

        set_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT, 1, 60);
        $this->assertFalse(Auto_Alt_Text_Library_Runner::maybe_chain());
        delete_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT);

        Auto_Alt_Text_Library_Run::pause($run['id']);
        $this->assertFalse(Auto_Alt_Text_Library_Runner::maybe_chain());
        $this->assertSame([], $this->requests);
    }

    public function test_tick_request_rejects_a_wrong_token() {
        $run = $this->start_run();
        $_POST['token'] = 'nope';
        try {
            Auto_Alt_Text_Library_Runner::handle_tick_request();
            $this->fail('Expected wp_die');
        } catch (WPDieException $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->assertNull(Auto_Alt_Text_Library_Run::get($run['id'])['last_tick_at']);
        $this->assertFalse(Auto_Alt_Text_Library_Runner::lock_held());
        $this->assertSame([], $this->requests);
    }

    public function test_tick_request_with_the_token_runs_the_queue_and_chains() {
        $run = $this->start_run();
        $_POST['token'] = Auto_Alt_Text_Library_Runner::token();
        try {
            Auto_Alt_Text_Library_Runner::handle_tick_request();
            $this->fail('Expected wp_die');
        } catch (WPDieException $e) {
            $this->assertSame(204, $e->getCode());
        }
        $this->assertNotNull(Auto_Alt_Text_Library_Run::get($run['id'])['last_tick_at']);
        $this->assertFalse(Auto_Alt_Text_Library_Runner::lock_held());
    }

    public function test_cron_tick_skips_while_the_lock_is_held() {
        $run = $this->start_run();
        $this->assertNotNull(Auto_Alt_Text_Library_Runner::take_lock());
        Auto_Alt_Text_Library_Runner::cron_tick();
        $this->assertNull(Auto_Alt_Text_Library_Run::get($run['id'])['last_tick_at']);
        delete_option('aat_library_runner_lock');
        Auto_Alt_Text_Library_Runner::cron_tick();
        $this->assertNotNull(Auto_Alt_Text_Library_Run::get($run['id'])['last_tick_at']);
        $this->assertFalse(Auto_Alt_Text_Library_Runner::lock_held());
    }

    public function test_health_reports_a_stalled_run() {
        $run = $this->start_run();
        $id = $run['id'];

        $this->set_tick($id, gmdate('Y-m-d H:i:s', time() - 11 * MINUTE_IN_SECONDS));
        $health = Auto_Alt_Text_Library_Runner::health(Auto_Alt_Text_Library_Run::get($id));
        $this->assertTrue($health['stalled']);
        $this->assertSame(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON, $health['cron_disabled']);

        $this->set_tick($id, null);
        $this->assertFalse(Auto_Alt_Text_Library_Runner::health(Auto_Alt_Text_Library_Run::get($id))['stalled'], 'just started');
        global $wpdb;
        $wpdb->update(Auto_Alt_Text_Activator::get_runs_table_name(), ['started_at' => gmdate('Y-m-d H:i:s', time() - 11 * MINUTE_IN_SECONDS)], ['id' => $id]);
        $this->assertTrue(Auto_Alt_Text_Library_Runner::health(Auto_Alt_Text_Library_Run::get($id))['stalled']);

        $this->set_tick($id, gmdate('Y-m-d H:i:s', time() - MINUTE_IN_SECONDS));
        $this->assertFalse(Auto_Alt_Text_Library_Runner::health(Auto_Alt_Text_Library_Run::get($id))['stalled']);

        $this->set_tick($id, gmdate('Y-m-d H:i:s', time() - 11 * MINUTE_IN_SECONDS));
        set_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT, 1, 60);
        $this->assertFalse(Auto_Alt_Text_Library_Runner::health(Auto_Alt_Text_Library_Run::get($id))['stalled']);
        delete_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT);

        Auto_Alt_Text_Library_Run::pause($id);
        $this->assertFalse(Auto_Alt_Text_Library_Runner::health(Auto_Alt_Text_Library_Run::get($id))['stalled']);
    }
}
