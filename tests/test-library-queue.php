<?php
class Auto_Alt_Text_Library_Queue_Test extends WP_UnitTestCase {
    private $calls = [];

    public function setUp(): void {
        parent::setUp();
        Auto_Alt_Text_Activator::activate();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Activator::get_runs_table_name());
        $wpdb->query('TRUNCATE TABLE ' . Auto_Alt_Text_Queue::get_table_name());
        $wpdb->query("DELETE FROM {$wpdb->prefix}auto_alt_text_stats");
        delete_option(Auto_Alt_Text_Quota_Monitor::OPTION);
        delete_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT);
        (new Auto_Alt_Text_OpenAI())->save_api_key('sk-test-not-real');
        update_option('auto_alt_text_auto_generate', 1);
        $this->calls = [];
    }

    public function tearDown(): void {
        delete_option(Auto_Alt_Text_Quota_Monitor::OPTION);
        delete_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT);
        delete_option('aat_library_counts');
        delete_option('aat_library_start_lock');
        wp_unschedule_hook(Auto_Alt_Text_Library_Scanner::RECOUNT_HOOK);
        parent::tearDown();
    }

    private function image(?string $alt = null, bool $ai = false): int {
        $id = self::factory()->attachment->create(['post_mime_type' => 'image/jpeg']);
        if (null !== $alt) {
            update_post_meta($id, '_wp_attachment_image_alt', $alt);
            if ($ai) {
                Auto_Alt_Text_Provenance::record($id, 'test');
            }
        }
        return $id;
    }

    private function start(int $missing, int $ai = 0, string $coverage = 'missing'): array {
        global $wpdb;
        update_option('aat_library_counts', [
            'missing' => $missing, 'ai' => $ai, 'human' => 0, 'unsupported' => 0,
            'max_id' => (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}"),
            'updated_at' => time(), 'complete' => true,
        ], false);
        $run = Auto_Alt_Text_Library_Run::start($coverage, 1);
        $this->assertIsArray($run);
        return $run;
    }

    private function queue(string $status = 'success'): Auto_Alt_Text_Queue {
        return new Auto_Alt_Text_Queue(function (int $id, string $type, ?int $ctx) use ($status): array {
            $this->calls[] = $id;
            return ['status' => $status, 'error' => null];
        });
    }

    private function row_status(int $attachment_id): ?string {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            'SELECT status FROM ' . Auto_Alt_Text_Queue::get_table_name() . ' WHERE attachment_id = %d ORDER BY id DESC LIMIT 1',
            $attachment_id
        ));
    }

    private function pending_count(): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Auto_Alt_Text_Queue::get_table_name() . ' WHERE status = %s', 'pending'
        ));
    }

    public function test_feed_fills_a_window_and_walks_the_cursor() {
        $ids = [];
        for ($i = 0; $i < 30; $i++) {
            $ids[] = $this->image();
        }
        $this->start(30);
        add_filter('aat_library_feed_window', fn() => 5);
        $this->assertSame(5, Auto_Alt_Text_Library_Run::feed(5));
        $this->assertSame(0, Auto_Alt_Text_Library_Run::feed(5));

        $this->queue()->run();
        $this->assertSame([$ids[0], $ids[1], $ids[2], $ids[3], $ids[4]], $this->calls);
        $this->assertSame(5, Auto_Alt_Text_Library_Run::feed(5));
        $this->queue()->run();
        $this->assertSame($ids[5], $this->calls[5]);
    }

    public function test_library_jobs_run_while_auto_generate_is_off() {
        update_option('auto_alt_text_auto_generate', 0);
        $id = $this->image();
        $this->start(1);
        $this->queue()->run();
        $this->assertSame([$id], $this->calls);
    }

    public function test_upload_job_is_claimed_before_the_run_backlog() {
        for ($i = 0; $i < 5; $i++) {
            $this->image();
        }
        $this->start(5);
        Auto_Alt_Text_Library_Run::feed(10);
        $upload = $this->image();
        (new Auto_Alt_Text_Queue())->enqueue($upload, 'upload');
        add_filter('aat_queue_batch_size', fn() => 1);
        $this->queue()->run();
        $this->assertSame([$upload], $this->calls);
    }

    public function test_alt_written_by_a_person_mid_run_is_skipped() {
        $id = $this->image();
        $this->start(1);
        Auto_Alt_Text_Library_Run::feed(5);
        update_post_meta($id, '_wp_attachment_image_alt', 'Skrivet av en person');
        $this->queue()->run();
        $this->assertSame([], $this->calls);
        $this->assertSame('skipped', $this->row_status($id));
        $this->assertSame('Skrivet av en person', get_post_meta($id, '_wp_attachment_image_alt', true));
    }

    public function test_missing_ai_coverage_rewrites_plugin_text_but_never_human_text() {
        $ai = $this->image('Plugin text', true);
        $human = $this->image('Human text');
        $this->start(0, 2, 'missing_ai');
        Auto_Alt_Text_Library_Run::feed(5);
        // Feed only enqueues candidates; force the human row in to prove the claim-time check.
        (new Auto_Alt_Text_Queue())->enqueue_for_run($human, Auto_Alt_Text_Library_Run::active()['id']);
        $this->queue()->run();
        $this->assertSame([$ai], $this->calls);
        $this->assertSame('skipped', $this->row_status($human));
        $this->assertSame('Human text', get_post_meta($human, '_wp_attachment_image_alt', true));
    }

    public function test_paused_run_jobs_are_not_claimed_and_feed_stops() {
        $this->image();
        $this->image();
        $run = $this->start(2);
        Auto_Alt_Text_Library_Run::feed(1);
        Auto_Alt_Text_Library_Run::pause($run['id']);
        $this->queue()->run();
        $this->assertSame([], $this->calls);
        $this->assertSame(0, Auto_Alt_Text_Library_Run::feed(5));
        $this->assertSame(1, $this->pending_count());
    }

    public function test_existing_pending_upload_job_joins_the_run() {
        global $wpdb;
        $id = $this->image();
        (new Auto_Alt_Text_Queue())->enqueue($id, 'upload');
        $run = $this->start(1);
        Auto_Alt_Text_Library_Run::feed(5);
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Auto_Alt_Text_Queue::get_table_name() . ' WHERE attachment_id = %d AND status IN (%s, %s)',
            $id, 'pending', 'processing'
        ));
        $this->assertCount(1, $rows);
        $this->assertSame((string) $run['id'], (string) $rows[0]->run_id);
        $this->assertSame('library', $rows[0]->generation_type);
    }

    public function test_deleted_attachment_counts_as_skipped() {
        $id = $this->image();
        $this->start(1);
        Auto_Alt_Text_Library_Run::feed(5);
        wp_delete_attachment($id, true);
        $this->queue()->run();
        $this->assertSame('skipped', $this->row_status($id));
        $this->assertSame([], $this->calls);
    }

    public function test_quota_stop_notes_a_pause_event_on_the_run() {
        $this->image();
        $this->image();
        $run = $this->start(2);
        $this->queue('quota')->run();
        $events = Auto_Alt_Text_Library_Run::get($run['id'])['summary']['events'];
        $this->assertSame('pause', end($events)['type']);
        $this->assertSame('quota', end($events)['reason']);

        $this->queue('success')->run();
        $events = Auto_Alt_Text_Library_Run::get($run['id'])['summary']['events'];
        $this->assertSame('resume', end($events)['type']);
    }

    public function test_run_finishes_when_fed_and_drained() {
        for ($i = 0; $i < 3; $i++) {
            $this->image();
        }
        $run = $this->start(3);
        $before = did_action('aat_library_run_finished');
        $this->queue()->run();
        $done = Auto_Alt_Text_Library_Run::get($run['id']);
        $this->assertSame('finished', $done['status']);
        $this->assertSame(3, $done['summary']['filled']);
        $this->assertSame(1, did_action('aat_library_run_finished') - $before);
        $this->queue()->run();
        $this->assertSame(1, did_action('aat_library_run_finished') - $before);
    }

    public function test_image_edited_before_feed_ends_as_skipped_in_the_summary() {
        $ids = [$this->image(), $this->image(), $this->image()];
        $run = $this->start(3);
        update_post_meta($ids[1], '_wp_attachment_image_alt', 'Skrivet av en person');
        $this->queue()->run();
        $done = Auto_Alt_Text_Library_Run::get($run['id']);
        $this->assertSame('finished', $done['status']);
        $this->assertSame(2, $done['summary']['filled']);
        $this->assertSame(1, $done['summary']['skipped']);
        $this->assertSame(3, $done['total']);
    }

    public function test_total_is_raised_when_done_exceeds_it() {
        $this->image();
        $this->image();
        $run = $this->start(1);
        $this->queue()->run();
        $done = Auto_Alt_Text_Library_Run::get($run['id']);
        $this->assertSame(2, $done['total']);
        $this->assertSame(2, $done['summary']['filled']);
    }

    public function test_jobs_of_a_cancelled_run_are_skipped_not_processed() {
        $id = $this->image();
        $run = $this->start(1);
        Auto_Alt_Text_Library_Run::feed(5);
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . Auto_Alt_Text_Queue::get_table_name() . ' SET status = %s WHERE attachment_id = %d',
            'processing', $id
        ));
        Auto_Alt_Text_Library_Run::cancel($run['id']);
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . Auto_Alt_Text_Queue::get_table_name() . ' SET status = %s WHERE attachment_id = %d',
            'pending', $id
        ));
        $this->queue()->run();
        $this->assertSame([], $this->calls);
        $this->assertSame('skipped', $this->row_status($id));
    }

    public function test_stats_rows_of_run_jobs_carry_the_run_id() {
        global $wpdb;
        $id = $this->image();
        $run = $this->start(1);
        $queue = new Auto_Alt_Text_Queue(function (int $attachment_id): array {
            (new Auto_Alt_Text_Statistics())->track_generation($attachment_id, 'x', 1, 'library');
            return ['status' => 'success', 'error' => null];
        });
        $queue->run();
        $this->assertSame((string) $run['id'], (string) $wpdb->get_var($wpdb->prepare(
            "SELECT run_id FROM {$wpdb->prefix}auto_alt_text_stats WHERE image_id = %d", $id
        )));
        $this->assertNull(Auto_Alt_Text_Run_Context::current());
    }
}
