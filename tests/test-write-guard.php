<?php
class Auto_Alt_Text_Write_Guard_Test extends WP_UnitTestCase {
    private const FIXTURE_TEXT = 'Plugin text.';

    public function setUp(): void {
        parent::setUp();
        Auto_Alt_Text_Activator::activate();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Queue::get_table_name());
        delete_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT);
        (new Auto_Alt_Text_OpenAI())->save_api_key('sk-test-not-real');
        Auto_Alt_Text_Run_Context::set_write_guard(null, null);
    }

    public function tearDown(): void {
        Auto_Alt_Text_Run_Context::set_write_guard(null, null);
        delete_option('auto_alt_text_overwrite_policy');
        parent::tearDown();
    }

    private function image(): int {
        return self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
    }

    /** Answers OpenAI calls with a fixed text; $during runs inside each call. */
    private function mock_openai(?callable $during = null): void {
        add_filter('pre_http_request', function ($pre, $args, $url) use ($during) {
            if (false === strpos($url, 'openai.com')) {
                return $pre;
            }
            if ($during) {
                $during();
            }
            $body = [
                'status' => 'completed',
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => self::FIXTURE_TEXT]]]],
                'usage'  => ['input_tokens' => 10, 'output_tokens' => 3, 'total_tokens' => 13],
            ];
            return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
        }, 10, 3);
    }

    private function row_status(int $id): ?string {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            'SELECT status FROM ' . Auto_Alt_Text_Queue::get_table_name() . ' WHERE attachment_id = %d ORDER BY id DESC LIMIT 1',
            $id
        ));
    }

    public function test_batch_job_skips_when_alt_changes_during_the_call() {
        $id = $this->image();
        $this->mock_openai(function () use ($id) {
            update_post_meta($id, '_wp_attachment_image_alt', 'Ändrad av redaktören');
        });
        (new Auto_Alt_Text_Queue())->enqueue_bulk($id, 'batch');
        (new Auto_Alt_Text_Queue())->run();
        $this->assertSame('skipped', $this->row_status($id));
        $this->assertSame('Ändrad av redaktören', get_post_meta($id, '_wp_attachment_image_alt', true));
        $this->assertSame('', (string) get_post_meta($id, Auto_Alt_Text_Provenance::META_KEY, true));
    }

    public function test_snapshot_blocks_under_policy_all() {
        update_option('auto_alt_text_overwrite_policy', 'all');
        $id = $this->image();
        update_post_meta($id, '_wp_attachment_image_alt', 'AI-text');
        $this->mock_openai(function () use ($id) {
            update_post_meta($id, '_wp_attachment_image_alt', 'Ny text');
        });
        (new Auto_Alt_Text_Queue())->enqueue_bulk($id, 'batch');
        (new Auto_Alt_Text_Queue())->run();
        $this->assertSame('skipped', $this->row_status($id));
        $this->assertSame('Ny text', get_post_meta($id, '_wp_attachment_image_alt', true));
    }

    public function test_unchanged_alt_is_written_under_policy_all() {
        update_option('auto_alt_text_overwrite_policy', 'all');
        $id = $this->image();
        update_post_meta($id, '_wp_attachment_image_alt', 'Gammal');
        $this->mock_openai();
        (new Auto_Alt_Text_Queue())->enqueue_bulk($id, 'batch');
        (new Auto_Alt_Text_Queue())->run();
        $this->assertSame('done', $this->row_status($id));
        $this->assertSame(self::FIXTURE_TEXT, get_post_meta($id, '_wp_attachment_image_alt', true));
    }

    public function test_cache_hit_path_respects_the_guard() {
        $id = $this->image();
        $this->mock_openai();
        $openai = new Auto_Alt_Text_OpenAI();
        $this->assertSame(self::FIXTURE_TEXT, $openai->generate_alt_text(wp_get_attachment_url($id), $id, 'manual'));
        delete_post_meta($id, '_wp_attachment_image_alt');

        Auto_Alt_Text_Run_Context::set_write_guard('missing', '');
        update_post_meta($id, '_wp_attachment_image_alt', 'Person');
        $this->assertNull($openai->generate_alt_text(wp_get_attachment_url($id), $id, 'manual'));
        $this->assertSame('Person', get_post_meta($id, '_wp_attachment_image_alt', true));
    }

    public function test_guard_is_cleared_after_each_job() {
        $id = $this->image();
        (new Auto_Alt_Text_Queue(fn() => ['status' => 'success', 'error' => null]))->enqueue_bulk($id, 'batch');
        (new Auto_Alt_Text_Queue(fn() => ['status' => 'success', 'error' => null]))->run();
        $this->assertNull(Auto_Alt_Text_Run_Context::write_guard());

        $second = $this->image();
        (new Auto_Alt_Text_Queue())->enqueue_bulk($second, 'batch');
        $seen = null;
        (new Auto_Alt_Text_Queue(function () use (&$seen) {
            $seen = Auto_Alt_Text_Run_Context::write_guard();
            throw new RuntimeException('boom');
        }))->run();
        $this->assertIsArray($seen);
        $this->assertNull(Auto_Alt_Text_Run_Context::write_guard());
    }

    public function test_no_guard_outside_the_queue() {
        $id = $this->image();
        update_post_meta($id, '_wp_attachment_image_alt', 'Person');
        $this->mock_openai();
        $text = (new Auto_Alt_Text_OpenAI())->generate_alt_text(wp_get_attachment_url($id), $id, 'manual');
        $this->assertSame(self::FIXTURE_TEXT, $text);
        $this->assertSame(self::FIXTURE_TEXT, get_post_meta($id, '_wp_attachment_image_alt', true));
    }
}
