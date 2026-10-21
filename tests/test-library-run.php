<?php
class Auto_Alt_Text_Library_Run_Test extends WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        Auto_Alt_Text_Activator::activate();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Activator::get_runs_table_name());
        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Queue::get_table_name());
        $wpdb->query("DELETE FROM {$wpdb->prefix}auto_alt_text_stats");
        delete_option(Auto_Alt_Text_Quota_Monitor::OPTION);
        delete_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT);
        $this->seed_counts(10, 4);
    }

    public function tearDown(): void {
        delete_option(Auto_Alt_Text_Quota_Monitor::OPTION);
        delete_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT);
        delete_option('aat_library_counts');
        wp_unschedule_hook(Auto_Alt_Text_Library_Scanner::RECOUNT_HOOK);
        parent::tearDown();
    }

    private function seed_counts(int $missing, int $ai, bool $complete = true): void {
        update_option('aat_library_counts', [
            'missing' => $missing, 'ai' => $ai, 'human' => 3, 'unsupported' => 1,
            'max_id' => 500, 'updated_at' => time(), 'complete' => $complete,
        ], false);
    }

    private function queue_row(int $run_id, string $status, ?string $error = null): int {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert(Auto_Alt_Text_Queue::get_table_name(), [
            'attachment_id' => self::factory()->attachment->create(),
            'generation_type' => 'library',
            'status' => $status,
            'last_error' => $error,
            'run_id' => $run_id,
            'next_attempt_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int) $wpdb->insert_id;
    }

    private function start(string $coverage = 'missing'): array {
        $run = Auto_Alt_Text_Library_Run::start($coverage, 1);
        $this->assertIsArray($run);
        return $run;
    }

    public function test_start_stores_total_estimate_and_fires_the_action() {
        $run = $this->start('missing');
        $this->assertSame(10, $run['total']);
        $this->assertSame(
            round(10 * Auto_Alt_Text_Models::cost_per_1000(Auto_Alt_Text_Models::current_id()) / 1000, 4),
            (float) $run['estimate_usd']
        );
        $this->assertSame('running', $run['status']);
        $this->assertSame(500, $run['max_attachment_id']);
        $this->assertSame(1, did_action('aat_library_run_started'));

        Auto_Alt_Text_Library_Run::cancel($run['id']);
        $second = $this->start('missing_ai');
        $this->assertSame(14, $second['total']);
    }

    public function test_only_one_active_run() {
        $this->start();
        $again = Auto_Alt_Text_Library_Run::start('missing', 1);
        $this->assertWPError($again);
        $this->assertSame('aat_library_run_active', $again->get_error_code());
    }

    public function test_start_refuses_incomplete_counts_and_empty_libraries() {
        $this->seed_counts(10, 4, false);
        $e = Auto_Alt_Text_Library_Run::start('missing', 1);
        $this->assertWPError($e);
        $this->assertSame('aat_library_counts_incomplete', $e->get_error_code());

        $this->seed_counts(0, 4);
        $e = Auto_Alt_Text_Library_Run::start('missing', 1);
        $this->assertWPError($e);
        $this->assertSame('aat_library_nothing_to_do', $e->get_error_code());
    }

    public function test_pause_resume_cancel_fire_actions_and_record_events() {
        $run = $this->start();
        $id = $run['id'];
        $this->queue_row($id, 'pending');
        $this->queue_row($id, 'pending');
        $done = $this->queue_row($id, 'done');

        $this->assertTrue(Auto_Alt_Text_Library_Run::pause($id));
        $this->assertSame('paused', Auto_Alt_Text_Library_Run::get($id)['status']);
        $this->assertTrue(Auto_Alt_Text_Library_Run::resume($id));
        $this->assertSame('running', Auto_Alt_Text_Library_Run::get($id)['status']);
        $this->assertTrue(Auto_Alt_Text_Library_Run::cancel($id));

        $this->assertSame(1, did_action('aat_library_run_paused'));
        $this->assertSame(1, did_action('aat_library_run_resumed'));
        $this->assertSame(1, did_action('aat_library_run_cancelled'));

        $final = Auto_Alt_Text_Library_Run::get($id);
        $this->assertSame('cancelled', $final['status']);
        $this->assertNotEmpty($final['ended_at']);
        $this->assertSame(['pause', 'resume'], wp_list_pluck($final['summary']['events'], 'type'));

        global $wpdb;
        $left = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Auto_Alt_Text_Queue::get_table_name() . ' WHERE run_id = %d', $id));
        $this->assertSame([(string) $done], $left);
        $this->assertNull(Auto_Alt_Text_Library_Run::active());
        $this->assertSame($id, Auto_Alt_Text_Library_Run::latest()['id']);
    }

    public function test_note_event_ignores_consecutive_duplicates() {
        $id = $this->start()['id'];
        Auto_Alt_Text_Library_Run::note_event($id, 'pause', 'quota');
        Auto_Alt_Text_Library_Run::note_event($id, 'pause', 'quota');
        Auto_Alt_Text_Library_Run::note_event($id, 'resume');
        $events = Auto_Alt_Text_Library_Run::get($id)['summary']['events'];
        $this->assertSame(['pause', 'resume'], wp_list_pluck($events, 'type'));
        $this->assertSame('quota', $events[0]['reason']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $events[0]['at']);
    }

    public function test_display_state_derives_quota_pause_from_the_live_signal() {
        $id = $this->start()['id'];
        $run = Auto_Alt_Text_Library_Run::get($id);
        $this->assertSame('running', Auto_Alt_Text_Library_Run::display_state($run)['state']);

        update_option(Auto_Alt_Text_Quota_Monitor::OPTION, [
            'active' => true, 'detected_at' => time(), 'last_probe_at' => time(),
            'last_error' => 'm', 'email_sent' => true, 'notice_dismissed' => false,
        ]);
        $this->assertSame('paused_quota', Auto_Alt_Text_Library_Run::display_state($run)['state']);
        delete_option(Auto_Alt_Text_Quota_Monitor::OPTION);
        $this->assertSame('running', Auto_Alt_Text_Library_Run::display_state($run)['state']);

        set_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT, 1, 60);
        $this->assertSame('paused_auth', Auto_Alt_Text_Library_Run::display_state($run)['state']);

        Auto_Alt_Text_Library_Run::pause($id);
        $paused = Auto_Alt_Text_Library_Run::get($id);
        $this->assertSame('paused_user', Auto_Alt_Text_Library_Run::display_state($paused)['state']);
    }

    public function test_progress_and_finalize_copy_totals_and_failures() {
        global $wpdb;
        $id = $this->start()['id'];
        $wpdb->update(Auto_Alt_Text_Activator::get_runs_table_name(), ['skipped_at_feed' => 1], ['id' => $id]);
        $this->queue_row($id, 'done');
        $this->queue_row($id, 'done');
        $this->queue_row($id, 'skipped');
        $failed_row = $this->queue_row($id, 'failed', 'Boom');
        $file = basename((string) get_attached_file((int) $wpdb->get_var($wpdb->prepare(
            'SELECT attachment_id FROM ' . Auto_Alt_Text_Queue::get_table_name() . ' WHERE id = %d', $failed_row
        ))));

        $expected = ['total' => 10, 'filled' => 2, 'skipped' => 2, 'failed' => 1, 'pending' => 0, 'done' => 5];
        $this->assertSame($expected, Auto_Alt_Text_Library_Run::progress(Auto_Alt_Text_Library_Run::get($id)));

        Auto_Alt_Text_Library_Run::finalize($id, Auto_Alt_Text_Library_Run::STATUS_FINISHED);
        $run = Auto_Alt_Text_Library_Run::get($id);
        $this->assertSame('finished', $run['status']);
        $this->assertSame('Boom', $run['summary']['failures'][0]['reason']);
        $this->assertSame($file, $run['summary']['failures'][0]['file']);
        $this->assertSame(1, did_action('aat_library_run_finished'));

        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Queue::get_table_name());
        $this->assertSame($expected, Auto_Alt_Text_Library_Run::progress(Auto_Alt_Text_Library_Run::get($id)));
    }

    public function test_cost_so_far_prices_the_runs_stats_rows() {
        global $wpdb;
        $id = $this->start()['id'];
        $table = $wpdb->prefix . 'auto_alt_text_stats';
        for ($i = 0; $i < 2; $i++) {
            $wpdb->insert($table, [
                'image_id' => 1, 'user_id' => 1, 'generated_text' => 'x', 'tokens_used' => 1360,
                'generation_type' => 'library', 'generation_time' => gmdate('Y-m-d H:i:s'),
                'model' => 'gpt-6.1-sol', 'input_tokens' => 1300, 'output_tokens' => 60, 'run_id' => $id,
            ]);
        }
        $this->assertEqualsWithDelta(0.0064, Auto_Alt_Text_Library_Run::cost_so_far($id), 0.00001);
    }

    public function test_retry_failed_requeues_all_but_unsupported_formats() {
        global $wpdb;
        $id = $this->start()['id'];
        $this->queue_row($id, 'failed', 'Boom');
        $unsupported = $this->queue_row($id, 'failed', 'Bildformatet stöds inte.');
        Auto_Alt_Text_Library_Run::finalize($id, Auto_Alt_Text_Library_Run::STATUS_FINISHED);

        $this->assertSame(1, Auto_Alt_Text_Library_Run::retry_failed($id));
        $run = Auto_Alt_Text_Library_Run::get($id);
        $this->assertSame('running', $run['status']);
        $this->assertNull($run['ended_at']);
        $this->assertSame(1, did_action('aat_library_run_resumed'));
        $this->assertSame('failed', $wpdb->get_var($wpdb->prepare(
            'SELECT status FROM ' . Auto_Alt_Text_Queue::get_table_name() . ' WHERE id = %d', $unsupported
        )));
    }
}
