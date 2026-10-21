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
        (new Auto_Alt_Text_OpenAI())->save_api_key('sk-test-not-real');
        $this->seed_counts(10, 4);
    }

    public function tearDown(): void {
        delete_option('auto_alt_text_api_key');
        delete_option(Auto_Alt_Text_Quota_Monitor::OPTION);
        delete_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT);
        delete_option('aat_library_counts');
        delete_option('aat_library_start_lock');
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
        $before = did_action('aat_library_run_started');
        $run = $this->start('missing');
        $this->assertSame(10, $run['total']);
        $this->assertSame(
            round(10 * Auto_Alt_Text_Models::cost_per_1000(Auto_Alt_Text_Models::current_id()) / 1000, 4),
            (float) $run['estimate_usd']
        );
        $this->assertSame('running', $run['status']);
        $this->assertSame(500, $run['max_attachment_id']);
        $this->assertSame(1, did_action('aat_library_run_started') - $before);

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
        $before = [];
        foreach (['paused', 'resumed', 'cancelled'] as $name) {
            $before[$name] = did_action("aat_library_run_{$name}");
        }
        $pause_args = null;
        add_action('aat_library_run_paused', static function ($run, $reason) use (&$pause_args) {
            $pause_args = [$run['status'], $reason];
        }, 10, 2);
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

        $this->assertSame(1, did_action('aat_library_run_paused') - $before['paused']);
        $this->assertSame(1, did_action('aat_library_run_resumed') - $before['resumed']);
        $this->assertSame(1, did_action('aat_library_run_cancelled') - $before['cancelled']);

        $this->assertSame(['paused', 'user'], $pause_args);
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

    public function test_start_refuses_without_an_api_key() {
        if (defined('AUTO_ALT_TEXT_OPENAI_API_KEY') && AUTO_ALT_TEXT_OPENAI_API_KEY) {
            $this->markTestSkipped('AUTO_ALT_TEXT_OPENAI_API_KEY is defined in this environment');
        }
        delete_option('auto_alt_text_api_key');
        $before = did_action('aat_library_run_started');
        $e = Auto_Alt_Text_Library_Run::start('missing', 1);
        $this->assertWPError($e);
        $this->assertSame('aat_library_no_api_key', $e->get_error_code());
        $this->assertStringContainsString('API-nyckel', $e->get_error_message());
        $this->assertNull(Auto_Alt_Text_Library_Run::latest());
        $this->assertSame($before, did_action('aat_library_run_started'));
        $this->assertFalse(get_option('aat_library_start_lock'));
    }

    public function test_note_event_ignores_consecutive_duplicates() {
        $id = $this->start()['id'];
        $this->assertTrue(Auto_Alt_Text_Library_Run::note_event($id, 'pause', 'quota'));
        $this->assertFalse(Auto_Alt_Text_Library_Run::note_event($id, 'pause', 'quota'));
        $this->assertTrue(Auto_Alt_Text_Library_Run::note_event($id, 'resume'));
        $this->assertFalse(Auto_Alt_Text_Library_Run::note_event(999999, 'resume'));
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
        $before = did_action('aat_library_run_finished');
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
        $this->assertSame(1, did_action('aat_library_run_finished') - $before);

        // A finished run adds the images of the total that never became jobs (5) to skipped.
        $final = ['total' => 10, 'filled' => 2, 'skipped' => 7, 'failed' => 1, 'pending' => 0, 'done' => 10];
        $this->assertSame($final, Auto_Alt_Text_Library_Run::progress($run));
        $this->assertSame(6, $run['skipped_at_feed']);

        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Queue::get_table_name());
        $this->assertSame($final, Auto_Alt_Text_Library_Run::progress(Auto_Alt_Text_Library_Run::get($id)));
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
        $before = did_action('aat_library_run_resumed');
        $id = $this->start()['id'];
        $this->queue_row($id, 'failed', 'Boom');
        $unsupported = $this->queue_row($id, 'failed', 'Bildformatet stöds inte.');
        Auto_Alt_Text_Library_Run::finalize($id, Auto_Alt_Text_Library_Run::STATUS_FINISHED);

        $this->assertSame(1, Auto_Alt_Text_Library_Run::retry_failed($id));
        $run = Auto_Alt_Text_Library_Run::get($id);
        $this->assertSame('running', $run['status']);
        $this->assertNull($run['ended_at']);
        $this->assertSame(1, did_action('aat_library_run_resumed') - $before);
        $this->assertSame('failed', $wpdb->get_var($wpdb->prepare(
            'SELECT status FROM ' . Auto_Alt_Text_Queue::get_table_name() . ' WHERE id = %d', $unsupported
        )));
    }

    public function test_feed_window_applies_the_filter_and_never_drops_below_one() {
        $this->assertSame(20, Auto_Alt_Text_Library_Run::feed_window());
        add_filter('aat_library_feed_window', fn() => 7);
        $this->assertSame(7, Auto_Alt_Text_Library_Run::feed_window());
        add_filter('aat_library_feed_window', fn() => 0, 20);
        $this->assertSame(1, Auto_Alt_Text_Library_Run::feed_window());
    }

    public function test_cancelling_a_reopened_run_keeps_its_retried_failures_in_the_report() {
        global $wpdb;
        $id = $this->start()['id'];
        $this->queue_row($id, 'failed', 'Boom');
        $this->queue_row($id, 'failed', 'Timeout');
        Auto_Alt_Text_Library_Run::finalize($id, Auto_Alt_Text_Library_Run::STATUS_FINISHED);
        $this->assertSame(2, Auto_Alt_Text_Library_Run::retry_failed($id));

        $this->assertTrue(Auto_Alt_Text_Library_Run::cancel($id));
        $run = Auto_Alt_Text_Library_Run::get($id);
        $this->assertSame('cancelled', $run['status']);
        $this->assertSame(2, $run['summary']['failed']);
        $this->assertSame(['Boom', 'Timeout'], wp_list_pluck($run['summary']['failures'], 'reason'));
        $this->assertSame(2, (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Auto_Alt_Text_Queue::get_table_name() . ' WHERE run_id = %d AND status = %s', $id, 'failed'
        )));
    }

    public function test_finalize_twice_fires_once_and_keeps_failures() {
        global $wpdb;
        $id = $this->start()['id'];
        $this->queue_row($id, 'failed', 'Boom');
        $before = did_action('aat_library_run_finished');

        Auto_Alt_Text_Library_Run::finalize($id, Auto_Alt_Text_Library_Run::STATUS_FINISHED);
        $first = Auto_Alt_Text_Library_Run::get($id);
        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Queue::get_table_name());
        Auto_Alt_Text_Library_Run::finalize($id, Auto_Alt_Text_Library_Run::STATUS_FINISHED);

        $this->assertSame(1, did_action('aat_library_run_finished') - $before);
        $this->assertSame($first['summary']['failures'], Auto_Alt_Text_Library_Run::get($id)['summary']['failures']);
        $this->assertCount(1, $first['summary']['failures']);
        $this->assertFalse(Auto_Alt_Text_Library_Run::cancel($id));
    }

    public function test_retry_failed_refuses_unknown_active_and_nothing_retryable() {
        $this->assertSame(0, Auto_Alt_Text_Library_Run::retry_failed(999999));

        // Still active: not finished or cancelled.
        $id = $this->start()['id'];
        $this->queue_row($id, 'failed', 'Boom');
        $this->assertSame(0, Auto_Alt_Text_Library_Run::retry_failed($id));
        $this->assertSame('running', Auto_Alt_Text_Library_Run::get($id)['status']);

        // Finished, but another run is active.
        Auto_Alt_Text_Library_Run::finalize($id, Auto_Alt_Text_Library_Run::STATUS_FINISHED);
        $other = $this->start()['id'];
        $resumed = did_action('aat_library_run_resumed');
        $this->assertSame(0, Auto_Alt_Text_Library_Run::retry_failed($id));
        $this->assertSame('finished', Auto_Alt_Text_Library_Run::get($id)['status']);
        $this->assertSame($resumed, did_action('aat_library_run_resumed'));
        Auto_Alt_Text_Library_Run::cancel($other);

        // Finished with only unsupported failures: nothing to retry, status untouched.
        $third = $this->start()['id'];
        $this->queue_row($third, 'failed', 'Bildformatet stöds inte.');
        Auto_Alt_Text_Library_Run::finalize($third, Auto_Alt_Text_Library_Run::STATUS_FINISHED);
        $this->assertSame(0, Auto_Alt_Text_Library_Run::retry_failed($third));
        $this->assertSame('finished', Auto_Alt_Text_Library_Run::get($third)['status']);
        $this->assertSame($resumed, did_action('aat_library_run_resumed'));
    }

    public function test_start_respects_a_fresh_lock_and_takes_over_a_stale_one() {
        update_option('aat_library_start_lock', time(), false);
        $e = Auto_Alt_Text_Library_Run::start('missing', 1);
        $this->assertWPError($e);
        $this->assertSame('aat_library_run_active', $e->get_error_code());
        $this->assertNotFalse(get_option('aat_library_start_lock'));

        update_option('aat_library_start_lock', time() - 60, false);
        $this->assertIsArray(Auto_Alt_Text_Library_Run::start('missing', 1));
        $this->assertFalse(get_option('aat_library_start_lock'));
    }

    public function test_finalize_caps_the_failures_list() {
        global $wpdb;
        $id = $this->start()['id'];
        $table = Auto_Alt_Text_Queue::get_table_name();
        $now = gmdate('Y-m-d H:i:s');
        for ($i = 0; $i < 502; $i++) {
            $wpdb->insert($table, [
                'attachment_id' => 100000 + $i, 'generation_type' => 'library', 'status' => 'failed',
                'last_error' => 'E', 'run_id' => $id, 'next_attempt_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        Auto_Alt_Text_Library_Run::finalize($id, Auto_Alt_Text_Library_Run::STATUS_FINISHED);
        $summary = Auto_Alt_Text_Library_Run::get($id)['summary'];
        $this->assertCount(500, $summary['failures']);
        $this->assertTrue($summary['failures_truncated']);
        $this->assertSame(502, $summary['failed']);
    }

    public function test_cost_so_far_skips_unknown_models_and_null_tokens_and_other_runs() {
        global $wpdb;
        $id = $this->start()['id'];
        $table = $wpdb->prefix . 'auto_alt_text_stats';
        $row = static fn(?string $model, ?int $in, ?int $out, ?int $run) => [
            'image_id' => 1, 'user_id' => 1, 'generated_text' => 'x', 'tokens_used' => 0,
            'generation_type' => 'library', 'generation_time' => gmdate('Y-m-d H:i:s'),
            'model' => $model, 'input_tokens' => $in, 'output_tokens' => $out, 'run_id' => $run,
        ];
        $wpdb->insert($table, $row('gpt-6.1-sol', 1300, 60, $id));
        $wpdb->insert($table, $row('no-such-model', 999999, 999999, $id));
        $wpdb->insert($table, $row('gpt-6.1-sol', null, null, $id));
        $wpdb->insert($table, $row('gpt-6.1-sol', 1300, 60, $id + 1000));
        $wpdb->insert($table, $row(null, 1300, 60, $id));
        $this->assertEqualsWithDelta(0.0032, Auto_Alt_Text_Library_Run::cost_so_far($id), 0.00001);
    }
}
