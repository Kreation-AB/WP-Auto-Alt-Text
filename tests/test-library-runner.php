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
        delete_transient('aat_library_runner_lock');
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
        delete_transient('aat_library_runner_lock');
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
        return $run;
    }

    private function set_tick(int $id, ?string $when): void {
        global $wpdb;
        $wpdb->update(Auto_Alt_Text_Activator::get_runs_table_name(), ['last_tick_at' => $when], ['id' => $id]);
    }

    public function test_run_with_a_budget_processes_until_the_budget_is_spent() {
        for ($i = 0; $i < 20; $i++) {
            $id = self::factory()->attachment->create(['post_mime_type' => 'image/jpeg']);
            (new Auto_Alt_Text_Queue())->enqueue($id, 'upload');
        }
        $processor = function (int $id): array {
            usleep(200000);
            $this->calls[] = $id;
            return ['status' => 'success', 'error' => null];
        };
        (new Auto_Alt_Text_Queue($processor))->run(1.0);
        $this->assertGreaterThanOrEqual(3, count($this->calls));
        $this->assertLessThanOrEqual(7, count($this->calls));

        $this->calls = [];
        $processor = function (int $id): array {
            $this->calls[] = $id;
            return ['status' => 'success', 'error' => null];
        };
        (new Auto_Alt_Text_Queue($processor))->run();
        $this->assertCount(5, $this->calls);
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
        set_transient('aat_library_runner_lock', 1, 60);
        $this->assertFalse(Auto_Alt_Text_Library_Runner::maybe_chain());
        delete_transient('aat_library_runner_lock');

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
        $this->assertFalse(get_transient('aat_library_runner_lock'));
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
        $this->assertFalse(get_transient('aat_library_runner_lock'));
    }

    public function test_cron_tick_skips_while_the_lock_is_held() {
        $run = $this->start_run();
        set_transient('aat_library_runner_lock', 1, 60);
        Auto_Alt_Text_Library_Runner::cron_tick();
        $this->assertNull(Auto_Alt_Text_Library_Run::get($run['id'])['last_tick_at']);
        delete_transient('aat_library_runner_lock');
        Auto_Alt_Text_Library_Runner::cron_tick();
        $this->assertNotNull(Auto_Alt_Text_Library_Run::get($run['id'])['last_tick_at']);
        $this->assertFalse(get_transient('aat_library_runner_lock'));
    }

    public function test_health_reports_a_stalled_run() {
        $run = $this->start_run();
        $id = $run['id'];

        $this->set_tick($id, gmdate('Y-m-d H:i:s', time() - 11 * MINUTE_IN_SECONDS));
        $health = Auto_Alt_Text_Library_Runner::health(Auto_Alt_Text_Library_Run::get($id));
        $this->assertTrue($health['stalled']);
        $this->assertSame(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON, $health['cron_disabled']);

        $this->set_tick($id, null);
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
