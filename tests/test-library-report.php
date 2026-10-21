<?php
class Auto_Alt_Text_Library_Report_Test extends WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        Auto_Alt_Text_Activator::activate();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Activator::get_runs_table_name());
        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Queue::get_table_name());
        $wpdb->query("DELETE FROM {$wpdb->prefix}auto_alt_text_stats");
        $wpdb->query("DELETE FROM {$wpdb->prefix}auto_alt_text_logs");
        update_option('aat_library_counts', [
            'missing' => 3, 'ai' => 0, 'human' => 0, 'unsupported' => 0,
            'max_id' => 500, 'updated_at' => time(), 'complete' => true,
        ], false);
    }

    public function tearDown(): void {
        delete_option('auto_alt_text_api_key');
        delete_option('aat_library_counts');
        delete_option('aat_library_start_lock');
        wp_unschedule_hook(Auto_Alt_Text_Library_Scanner::RECOUNT_HOOK);
        unset($_GET['run'], $_REQUEST['_wpnonce']);
        parent::tearDown();
    }

    /** A finished run with the given summary overrides. */
    private function finished_run(array $summary = []): array {
        global $wpdb;
        $run = Auto_Alt_Text_Library_Run::start('missing', 1);
        $this->assertIsArray($run);
        $summary = array_merge([
            'filled' => 2, 'skipped' => 0, 'failed' => 1, 'cost_usd' => 0.0123,
            'failures' => [['attachment_id' => 77, 'file' => 'canola.jpg', 'reason' => 'Boom']],
            'failures_truncated' => false,
            'events' => [],
        ], $summary);
        $wpdb->update(Auto_Alt_Text_Activator::get_runs_table_name(), [
            'status' => 'finished',
            'ended_at' => gmdate('Y-m-d H:i:s'),
            'summary' => wp_json_encode($summary),
        ], ['id' => $run['id']]);
        return Auto_Alt_Text_Library_Run::get($run['id']);
    }

    private function log_for(int $run_id, string $message, string $level = 'error'): void {
        Auto_Alt_Text_Logger::log($message, $level, ['run_id' => $run_id]);
    }

    public function test_report_has_the_sections_and_numbers() {
        $run = $this->finished_run();
        $report = Auto_Alt_Text_Library_Report::build($run['id']);
        $this->assertStringContainsString((string) $run['id'], $report);
        $this->assertStringContainsString('Boom', $report);
        $this->assertStringContainsString('canola.jpg', $report);
        $this->assertStringContainsString(number_format($run['estimate_usd'], 4, '.', ''), $report);
        $this->assertStringContainsString('0.0123', $report);
        $this->assertStringContainsString(PHP_VERSION, $report);
        $this->assertStringContainsString(AUTO_ALT_TEXT_VERSION, $report);
    }

    public function test_report_lists_pauses() {
        $run = $this->finished_run(['events' => [
            ['type' => 'pause', 'reason' => 'quota', 'at' => '2026-10-21 10:00:00'],
            ['type' => 'resume', 'reason' => '', 'at' => '2026-10-21 11:00:00'],
        ]]);
        $report = Auto_Alt_Text_Library_Report::build($run['id']);
        $this->assertStringContainsString('2026-10-21 10:00:00', $report);
        $this->assertStringContainsString('pause', $report);
        $this->assertStringContainsString('quota', $report);
        $this->assertStringContainsString('resume', $report);
    }

    public function test_report_includes_the_runs_log_lines_only() {
        $x = $this->finished_run();
        $y = $x['id'] + 1;
        $this->log_for($x['id'], 'Message for run X');
        $this->log_for($y, 'Message for run Y');
        $this->log_for($x['id'], 'Info line for run X', 'info');
        $report = Auto_Alt_Text_Library_Report::build($x['id']);
        $this->assertStringContainsString('Message for run X', $report);
        $this->assertStringNotContainsString('Message for run Y', $report);
        $this->assertStringNotContainsString('Info line for run X', $report);
    }

    public function test_report_never_contains_the_api_key_or_key_like_strings() {
        (new Auto_Alt_Text_OpenAI())->save_api_key('sk-test-REALKEY123456');
        $run = $this->finished_run(['failures' => [
            ['attachment_id' => 5, 'file' => 'a.jpg', 'reason' => 'bad key sk-test-REALKEY123456 in org-abcdefgh12'],
        ]]);
        $this->log_for($run['id'], 'Incorrect API key provided: sk-proj-abcdefgh1234');
        $report = Auto_Alt_Text_Library_Report::build($run['id']);
        $this->assertStringNotContainsString('sk-test-REALKEY123456', $report);
        $this->assertStringNotContainsString('sk-proj-abcdefgh1234', $report);
        $this->assertStringNotContainsString('org-abcdefgh12', $report);
        $this->assertStringContainsString('[redacted]', $report);
    }

    public function test_report_never_contains_alt_texts() {
        global $wpdb;
        $run = $this->finished_run();
        $id = self::factory()->attachment->create();
        update_post_meta($id, '_wp_attachment_image_alt', 'Unik alt-text 9f3a');
        $wpdb->insert("{$wpdb->prefix}auto_alt_text_stats", [
            'image_id' => $id, 'run_id' => $run['id'], 'generated_text' => 'Unik alt-text 9f3a',
        ]);
        $report = Auto_Alt_Text_Library_Report::build($run['id']);
        $this->assertStringNotContainsString('Unik alt-text 9f3a', $report);
    }

    public function test_truncated_failures_continue_from_queue_rows() {
        global $wpdb;
        $run = $this->finished_run(['failures_truncated' => true]);
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert(Auto_Alt_Text_Queue::get_table_name(), [
            'attachment_id' => 4242, 'generation_type' => 'library', 'status' => 'failed',
            'last_error' => 'Queue-side failure', 'run_id' => $run['id'],
            'next_attempt_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $report = Auto_Alt_Text_Library_Report::build($run['id']);
        $this->assertStringContainsString('Queue-side failure', $report);
        $this->assertStringContainsString('4242', $report);
    }

    public function test_truncated_failures_without_queue_rows_say_how_many_more() {
        $run = $this->finished_run(['failed' => 503, 'failures_truncated' => true]);
        $report = Auto_Alt_Text_Library_Report::build($run['id']);
        $this->assertStringContainsString('och 502 till', $report);
    }

    public function test_filename_sanitizes_the_host() {
        $name = Auto_Alt_Text_Library_Report::filename(['id' => 9, 'started_at' => '2026-10-21 10:00:00']);
        $this->assertMatchesRegularExpression('/^alt-text-rapport-[a-z0-9.-]+-run9-2026-10-21\.txt$/', $name);
    }

    public function test_download_requires_manage_options() {
        $run = $this->finished_run();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $_GET['run'] = (string) $run['id'];
        $_REQUEST['_wpnonce'] = wp_create_nonce('aat_library_report');
        try {
            Auto_Alt_Text_Library_Report::handle_download();
            $this->fail('Expected wp_die');
        } catch (WPDieException $e) {
            $this->assertSame(403, $e->getCode());
        }
    }

    public function test_download_of_an_unknown_run_is_a_404() {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $_GET['run'] = '999999';
        $_REQUEST['_wpnonce'] = wp_create_nonce('aat_library_report');
        try {
            Auto_Alt_Text_Library_Report::handle_download();
            $this->fail('Expected wp_die');
        } catch (WPDieException $e) {
            $this->assertSame(404, $e->getCode());
        }
    }
}
