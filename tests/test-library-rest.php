<?php
class Auto_Alt_Text_Library_Rest_Test extends WP_UnitTestCase {
    private const BASE = '/wp-auto-alt-text/v1/library';

    public function setUp(): void {
        parent::setUp();
        Auto_Alt_Text_Activator::activate();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Activator::get_runs_table_name());
        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Queue::get_table_name());
        $this->seed_counts(10);
        (new Auto_Alt_Text_OpenAI())->save_api_key('sk-test-not-real');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        do_action('rest_api_init');
    }

    public function tearDown(): void {
        delete_option('auto_alt_text_api_key');
        delete_option('aat_library_counts');
        delete_option('aat_library_start_lock');
        wp_unschedule_hook(Auto_Alt_Text_Library_Scanner::RECOUNT_HOOK);
        parent::tearDown();
    }

    private function seed_counts(int $missing, ?int $updated_at = null): void {
        update_option('aat_library_counts', [
            'missing' => $missing, 'ai' => 0, 'human' => 0, 'unsupported' => 0,
            'max_id' => 500, 'updated_at' => $updated_at ?? time(), 'complete' => true,
        ], false);
    }

    private function call(string $method, string $path, array $params = []): WP_REST_Response {
        $request = new WP_REST_Request($method, self::BASE . $path);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        return rest_do_request($request);
    }

    private function start(string $coverage = 'missing'): WP_REST_Response {
        return $this->call('POST', '/start', ['coverage' => $coverage]);
    }

    public function test_library_routes_require_manage_options() {
        $routes = [
            ['GET', ''], ['POST', '/start'], ['POST', '/pause'], ['POST', '/resume'],
            ['POST', '/cancel'], ['POST', '/retry-failed'], ['POST', '/recount'],
        ];
        $registered = array_filter(array_keys(rest_get_server()->get_routes()), static fn($route) => 0 === strpos($route, self::BASE));
        $this->assertCount(count($routes), $registered, 'Every library route is listed here');

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        foreach ($routes as [$method, $path]) {
            $params = '/start' === $path ? ['coverage' => 'missing'] : [];
            $this->assertSame(403, $this->call($method, $path, $params)->get_status(), "$method library$path");
        }
        $this->assertNull(Auto_Alt_Text_Library_Run::latest());
        $this->assertFalse(wp_next_scheduled(Auto_Alt_Text_Library_Scanner::RECOUNT_HOOK));
    }

    public function test_get_returns_counts_estimate_and_null_run() {
        $response = $this->call('GET', '');
        $data = $response->get_data();
        $this->assertSame(200, $response->get_status());
        $this->assertSame(10, $data['counts']['missing']);
        $this->assertNull($data['run']);
        $this->assertNull($data['health']);
        $per_1000 = Auto_Alt_Text_Models::cost_per_1000(Auto_Alt_Text_Models::current_id());
        $this->assertEqualsWithDelta($per_1000 / 1000, $data['estimate_per_image_usd'], 0.000001);
    }

    public function test_start_then_get_shows_the_run_and_a_report_url() {
        $started = $this->start();
        $this->assertSame(200, $started->get_status());
        $run = $this->call('GET', '')->get_data()['run'];
        $this->assertSame(10, $run['total']);
        $this->assertSame('running', $run['display_state']);
        $this->assertStringContainsString('action=aat_library_report', $run['report_url']);
        $this->assertStringContainsString('run=' . $run['id'], $run['report_url']);
        $this->assertNull($run['waiting_until']);
        $this->assertIsArray($this->call('GET', '')->get_data()['health']);
        $this->assertStringNotContainsString('&amp;', $run['report_url']);
    }

    public function test_start_validates_coverage_and_maps_errors() {
        $this->assertSame(400, $this->call('POST', '/start', ['coverage' => 'all'])->get_status());
        $this->assertSame(400, $this->call('POST', '/start')->get_status());
        $this->seed_counts(0);
        $this->assertSame(400, $this->start()->get_status());
        update_option('aat_library_counts', ['missing' => 3, 'complete' => false], false);
        $this->assertSame(400, $this->start()->get_status());
    }

    public function test_start_without_an_api_key_is_400_with_its_message() {
        if (defined('AUTO_ALT_TEXT_OPENAI_API_KEY') && AUTO_ALT_TEXT_OPENAI_API_KEY) {
            $this->markTestSkipped('AUTO_ALT_TEXT_OPENAI_API_KEY is defined in this environment');
        }
        delete_option('auto_alt_text_api_key');
        $response = $this->start();
        $this->assertSame(400, $response->get_status());
        $this->assertSame('aat_library_no_api_key', $response->get_data()['code']);
        $this->assertStringContainsString('API-nyckel', $response->get_data()['message']);
        $this->assertNull($this->call('GET', '')->get_data()['run']);
    }

    public function test_second_start_is_409() {
        $this->assertSame(200, $this->start()->get_status());
        $this->assertSame(409, $this->start()->get_status());
    }

    public function test_pause_resume_cancel_round_trip() {
        $this->start();
        $this->assertSame('paused_user', $this->call('POST', '/pause')->get_data()['run']['display_state']);
        $this->assertSame('running', $this->call('POST', '/resume')->get_data()['run']['display_state']);
        $this->assertSame('cancelled', $this->call('POST', '/cancel')->get_data()['run']['display_state']);
    }

    public function test_actions_without_a_run_are_404() {
        foreach (['/pause', '/resume', '/cancel', '/retry-failed'] as $path) {
            $this->assertSame(404, $this->call('POST', $path)->get_status(), $path);
        }
    }

    public function test_recount_is_requested_for_stale_counts_but_cached_figures_returned() {
        $this->seed_counts(10, time() - 2 * HOUR_IN_SECONDS);
        $this->assertFalse(wp_next_scheduled(Auto_Alt_Text_Library_Scanner::RECOUNT_HOOK));
        $data = $this->call('GET', '')->get_data();
        $this->assertSame(10, $data['counts']['missing']);
        $this->assertNotFalse(wp_next_scheduled(Auto_Alt_Text_Library_Scanner::RECOUNT_HOOK));
    }

    public function test_recount_route_requests_a_recount() {
        $this->assertSame(200, $this->call('POST', '/recount')->get_status());
        $this->assertNotFalse(wp_next_scheduled(Auto_Alt_Text_Library_Scanner::RECOUNT_HOOK));
    }

    private function queue_row(int $run_id, int $attachment_id, string $status, array $extra = []): void {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert(Auto_Alt_Text_Queue::get_table_name(), array_merge([
            'attachment_id' => $attachment_id, 'generation_type' => Auto_Alt_Text_Queue::LIBRARY_TYPE,
            'run_id' => $run_id, 'status' => $status, 'attempts' => 0,
            'next_attempt_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ], $extra));
    }

    public function test_recent_shows_excerpts_but_never_more_than_80_chars() {
        $run = $this->start()->get_data()['run'];
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        update_post_meta($id, '_wp_attachment_image_alt', str_repeat('å', 200));
        $this->queue_row($run['id'], $id, 'done');
        $bad = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $this->queue_row($run['id'], $bad, 'failed', ['last_error' => 'bad key sk-abcdefgh12345678']);
        $this->queue_row($run['id'], $id + 1000, 'pending');

        $recent = $this->call('GET', '')->get_data()['run']['recent'];
        $this->assertCount(2, $recent);
        $by = array_column($recent, null, 'outcome');
        $this->assertSame(80, mb_strlen($by['filled']['excerpt']));
        $this->assertArrayNotHasKey('reason', $by['filled']);
        $this->assertSame(basename(get_attached_file($id)), $by['filled']['file']);
        $this->assertArrayNotHasKey('excerpt', $by['failed']);
        $this->assertStringNotContainsString('sk-abcdefgh', $by['failed']['reason']);
    }

    public function test_recent_lists_the_newest_rows_first_by_id() {
        $run = $this->start()->get_data()['run'];
        $older = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $newer = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        // The older row was touched later; the order stays by id, which needs no sort over every processed row.
        $this->queue_row($run['id'], $older, 'failed', ['last_error' => 'older', 'updated_at' => gmdate('Y-m-d H:i:s', time() + 60)]);
        $this->queue_row($run['id'], $newer, 'failed', ['last_error' => 'newer']);
        $recent = $this->call('GET', '')->get_data()['run']['recent'];
        $this->assertSame(['newer', 'older'], array_column($recent, 'reason'));
    }

    public function test_active_run_failures_come_from_the_queue_and_page() {
        $run = $this->start()->get_data()['run'];
        for ($i = 1; $i <= 55; $i++) {
            $this->queue_row($run['id'], 9000 + $i, 'failed', ['last_error' => "err $i"]);
        }
        $page = $this->call('GET', '')->get_data()['run']['failures_page'];
        $this->assertSame(55, $this->call('GET', '')->get_data()['run']['failures_total']);
        $this->assertCount(50, $page);
        $second = $this->call('GET', '', ['failures_offset' => 50])->get_data()['run'];
        $this->assertCount(5, $second['failures_page']);
        $this->assertSame(55, $second['failures_total']);
    }

    public function test_final_run_failures_come_from_the_summary() {
        global $wpdb;
        $run = $this->start()->get_data()['run'];
        $failures = [];
        for ($i = 1; $i <= 60; $i++) {
            $failures[] = ['attachment_id' => $i, 'file' => "f$i.jpg", 'reason' => "r$i"];
        }
        $wpdb->update(Auto_Alt_Text_Activator::get_runs_table_name(), [
            'status' => 'finished', 'ended_at' => gmdate('Y-m-d H:i:s'),
            'summary' => wp_json_encode(['filled' => 0, 'skipped' => 0, 'failed' => 60, 'cost_usd' => 0, 'failures' => $failures, 'events' => []]),
        ], ['id' => $run['id']]);
        $run = $this->call('GET', '', ['failures_offset' => 50])->get_data()['run'];
        $this->assertSame(60, $run['failures_total']);
        $this->assertCount(10, $run['failures_page']);
        $this->assertSame('f51.jpg', $run['failures_page'][0]['file']);
    }

    public function test_waiting_until_is_set_when_only_backoff_rows_remain() {
        $run = $this->start()->get_data()['run'];
        $when = gmdate('Y-m-d H:i:s', time() + 600);
        $this->queue_row($run['id'], 9001, 'pending', ['next_attempt_at' => $when]);
        $data = $this->call('GET', '')->get_data();
        $this->assertSame($when, $data['run']['waiting_until']);
    }
}
