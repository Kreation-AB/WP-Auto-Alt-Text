<?php
class Auto_Alt_Text_Bulk_Rest_Test extends WP_UnitTestCase {
    private const BASE = '/wp-auto-alt-text/v1/bulk';
    private $editor;

    public function setUp(): void {
        parent::setUp();
        Auto_Alt_Text_Activator::activate();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Queue::get_table_name());
        delete_option('aat_library_runner_lock');
        (new Auto_Alt_Text_OpenAI())->save_api_key('sk-test-not-real');
        update_option('auto_alt_text_auto_generate', 0);
        add_filter('pre_http_request', static fn($pre) => ['headers' => [], 'body' => '', 'response' => ['code' => 204, 'message' => 'No Content'], 'cookies' => [], 'filename' => null]);
        $this->editor = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($this->editor);
        do_action('rest_api_init');
    }

    public function tearDown(): void {
        delete_option('auto_alt_text_api_key');
        delete_option('aat_library_runner_lock');
        parent::tearDown();
    }

    private function image(): int {
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        wp_update_post(['ID' => $id, 'post_author' => $this->editor]);
        return $id;
    }

    private function call(string $method, string $path = '', array $params = []): WP_REST_Response {
        $request = new WP_REST_Request($method, self::BASE . $path);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        return rest_do_request($request);
    }

    public function test_post_bulk_returns_202_with_counts() {
        $response = $this->call('POST', '', ['attachment_ids' => [$this->image(), $this->image()]]);
        $this->assertSame(202, $response->get_status());
        $data = $response->get_data();
        $this->assertSame(2, $data['queued']);
        $this->assertSame(0, $data['already_queued']);
        $this->assertNotNull($data['bulk']);
    }

    public function test_post_bulk_requires_upload_files() {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->assertSame(403, $this->call('POST', '', ['attachment_ids' => [1]])->get_status());
        $this->assertSame(403, $this->call('GET')->get_status());
        $this->assertSame(403, $this->call('POST', '/tick')->get_status());
    }

    public function test_post_bulk_maps_errors_to_status() {
        $this->assertSame(400, $this->call('POST', '', ['attachment_ids' => []])->get_status());
        $this->assertSame(400, $this->call('POST', '', ['attachment_ids' => [-5]])->get_status());
        delete_option('auto_alt_text_api_key');
        $response = $this->call('POST', '', ['attachment_ids' => [$this->image()]]);
        $this->assertSame(409, $response->get_status());
        $this->assertSame('aat_bulk_no_key', $response->get_data()['code']);
    }

    public function test_get_bulk_returns_only_the_current_users_bulk() {
        $this->assertNull($this->call('GET')->get_data()['bulk']);
        $this->call('POST', '', ['attachment_ids' => [$this->image()]]);
        $this->assertNotNull($this->call('GET')->get_data()['bulk']);
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $this->assertNull($this->call('GET')->get_data()['bulk']);
    }

    public function test_dismiss_and_cancel_routes() {
        $this->call('POST', '', ['attachment_ids' => [$this->image(), $this->image()]]);
        $cancel = $this->call('POST', '/cancel');
        $this->assertSame(200, $cancel->get_status());
        $this->assertSame(2, $cancel->get_data()['cancelled']);
        $this->assertNotNull($cancel->get_data()['bulk']);
        $dismiss = $this->call('POST', '/dismiss');
        $this->assertSame(200, $dismiss->get_status());
        $this->assertNull($dismiss->get_data()['bulk']);
        $this->assertNull($this->call('GET')->get_data()['bulk']);
    }

    public function test_tick_route_refuses_without_a_running_bulk() {
        $response = $this->call('POST', '/tick');
        $this->assertSame(409, $response->get_status());
        $this->assertSame('aat_bulk_not_running', $response->get_data()['code']);
    }

    public function test_tick_route_runs_one_budgeted_tick() {
        $this->call('POST', '', ['attachment_ids' => [$this->image()]]);
        $budgets = [];
        add_filter('aat_bulk_tab_tick_budget', static function ($budget) use (&$budgets) {
            $budgets[] = $budget;
            return 0.0;
        });
        $response = $this->call('POST', '/tick');
        $this->assertSame(200, $response->get_status());
        $this->assertTrue($response->get_data()['ticked']);
        $this->assertNotNull($response->get_data()['bulk']);
        $this->assertSame([15.0], $budgets);
    }

    public function test_tick_route_claims_one_job_per_batch() {
        $this->call('POST', '', ['attachment_ids' => [$this->image(), $this->image(), $this->image()]]);
        global $wpdb;
        $table = Auto_Alt_Text_Queue::get_table_name();
        $claimed = [];
        add_filter('pre_http_request', static function ($pre, $args, $url) use (&$claimed, $wpdb, $table) {
            if (false === strpos((string) $url, 'openai.com')) {
                return $pre;
            }
            // Jobs claimed at the moment of each API call: the whole batch.
            $claimed[] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'processing'");
            $body = [
                'status' => 'completed',
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Plugin text.']]]],
                'usage'  => ['input_tokens' => 10, 'output_tokens' => 3, 'total_tokens' => 13],
            ];
            return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
        }, 20, 3);

        $response = $this->call('POST', '/tick');
        $this->assertSame(200, $response->get_status());
        $this->assertSame([1, 1, 1], $claimed);
        $this->assertSame('finished', $response->get_data()['bulk']['state']);
        // The batch size is only forced during the tab tick.
        $this->assertFalse(has_filter('aat_queue_batch_size'));
    }
}
