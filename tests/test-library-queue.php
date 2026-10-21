<?php
class Auto_Alt_Text_Library_Queue_Test extends WP_UnitTestCase {
    private $calls = [];
    /** @var array<int,array{0:int,1:string}> */
    private $pauses = [];

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

    public function test_feed_leaves_no_meta_cached_for_the_chunk_it_scanned() {
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->image();
        }
        $ids[] = $this->image('Skrivet av en person');
        $this->start(5);
        // The window fills after two images: the early break still clears the whole chunk.
        $this->assertSame(2, Auto_Alt_Text_Library_Run::feed(2));
        foreach ($ids as $id) {
            $this->assertFalse(wp_cache_get($id, 'post_meta'), "Meta of attachment $id is still cached");
        }
        $this->assertSame(3, Auto_Alt_Text_Library_Run::feed(5));
        foreach ($ids as $id) {
            $this->assertFalse(wp_cache_get($id, 'post_meta'), "Meta of attachment $id is still cached");
        }
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

    /** Records [run id, reason] of every aat_library_run_paused in $this->pauses. */
    private function capture_pauses(): void {
        $this->pauses = [];
        add_action('aat_library_run_paused', function ($run, $reason) {
            $this->pauses[] = [(int) $run['id'], $reason];
        }, 10, 2);
    }

    public function test_quota_stop_notes_a_pause_event_on_the_run() {
        $this->image();
        $this->image();
        $run = $this->start(2);
        $this->capture_pauses();
        $paused = did_action('aat_library_run_paused');
        $resumed = did_action('aat_library_run_resumed');
        $this->queue('quota')->run();
        $events = Auto_Alt_Text_Library_Run::get($run['id'])['summary']['events'];
        $this->assertSame('pause', end($events)['type']);
        $this->assertSame('quota', end($events)['reason']);
        $this->assertSame(1, did_action('aat_library_run_paused') - $paused);
        $this->assertSame([[$run['id'], 'quota']], $this->pauses);

        // The same incident again (a failed probe) fires nothing more.
        $this->queue('quota')->run();
        $this->assertSame(1, did_action('aat_library_run_paused') - $paused);

        $this->queue('success')->run();
        $events = Auto_Alt_Text_Library_Run::get($run['id'])['summary']['events'];
        $this->assertSame('resume', end($events)['type']);
        $this->assertSame(1, did_action('aat_library_run_resumed') - $resumed, 'Only the first success after the stop resumes');
    }

    public function test_auth_stop_fires_one_pause_and_the_first_success_one_resume() {
        $this->image();
        $this->image();
        $run = $this->start(2);
        $this->capture_pauses();
        $paused = did_action('aat_library_run_paused');
        $resumed = did_action('aat_library_run_resumed');
        $this->queue('auth')->run();
        $this->assertSame([[$run['id'], 'auth']], $this->pauses);
        $this->assertSame(0, did_action('aat_library_run_resumed') - $resumed);

        // A new key lifts the pause; the key is rejected again within the same incident.
        Auto_Alt_Text_Queue::clear_auth_pause();
        $this->queue('auth')->run();
        $this->assertSame(1, did_action('aat_library_run_paused') - $paused);

        Auto_Alt_Text_Queue::clear_auth_pause();
        $this->queue('success')->run();
        $this->assertSame(1, did_action('aat_library_run_resumed') - $resumed);
        $this->assertSame(['pause', 'resume'], wp_list_pluck(Auto_Alt_Text_Library_Run::get($run['id'])['summary']['events'], 'type'));
    }

    public function test_an_automatic_stop_while_the_user_paused_fires_no_second_pause() {
        $run = $this->start(1);
        $this->capture_pauses();
        Auto_Alt_Text_Library_Run::pause($run['id']);
        $upload = $this->image();
        (new Auto_Alt_Text_Queue())->enqueue($upload, 'upload');
        $this->queue('quota')->run();
        $events = Auto_Alt_Text_Library_Run::get($run['id'])['summary']['events'];
        $this->assertSame('quota', end($events)['reason'], 'The stop is still recorded for the report');
        $this->assertSame([[$run['id'], 'user']], $this->pauses);
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

    private function age_rows(int $days): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . Auto_Alt_Text_Queue::get_table_name() . ' SET updated_at = %s',
            gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)
        ));
    }

    private function run_row_count(int $run_id): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Auto_Alt_Text_Queue::get_table_name() . ' WHERE run_id = %d', $run_id
        ));
    }

    public function test_retrying_a_cancelled_run_never_feeds_the_cancelled_remainder() {
        $ids = [];
        for ($i = 0; $i < 12; $i++) {
            $ids[] = $this->image();
        }
        $run = $this->start(12);
        add_filter('aat_library_feed_window', fn() => 3);
        $failing = new Auto_Alt_Text_Queue(function (int $id): array {
            $this->calls[] = $id;
            return ['status' => 'failed', 'error' => 'Boom'];
        });
        $failing->run();
        $this->assertSame(3, count($this->calls));
        Auto_Alt_Text_Library_Run::cancel($run['id']);
        $this->assertSame(3, Auto_Alt_Text_Library_Run::retry_failed($run['id']));

        $this->calls = [];
        for ($i = 0; $i < 6; $i++) {
            $this->queue()->run();
        }
        $this->assertSame(array_slice($ids, 0, 3), $this->calls);
        $done = Auto_Alt_Text_Library_Run::get($run['id']);
        $this->assertSame('cancelled', $done['status']);
        $this->assertSame(3, $done['summary']['filled']);
        $this->assertSame(0, $done['summary']['skipped']);
        $this->assertSame(3, $this->run_row_count($run['id']));
    }

    public function test_rows_in_backoff_do_not_take_up_the_feed_window() {
        global $wpdb;
        for ($i = 0; $i < 12; $i++) {
            $this->image();
        }
        $run = $this->start(12);
        $this->assertSame(5, Auto_Alt_Text_Library_Run::feed(5));
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . Auto_Alt_Text_Queue::get_table_name() . ' SET next_attempt_at = %s WHERE run_id = %d',
            gmdate('Y-m-d H:i:s', time() + 3600), $run['id']
        ));
        $this->assertSame(5, Auto_Alt_Text_Library_Run::feed(5));
        $this->queue()->run();
        $this->assertCount(5, $this->calls);
    }

    public function test_feed_stops_at_the_pending_cap() {
        global $wpdb;
        for ($i = 0; $i < 40; $i++) {
            $this->image();
        }
        $run = $this->start(40);
        for ($i = 0; $i < 5; $i++) {
            Auto_Alt_Text_Library_Run::feed(2);
            $wpdb->query($wpdb->prepare(
                'UPDATE ' . Auto_Alt_Text_Queue::get_table_name() . ' SET next_attempt_at = %s WHERE run_id = %d',
                gmdate('Y-m-d H:i:s', time() + 3600), $run['id']
            ));
        }
        $this->assertSame(10, $this->pending_count());
        $this->assertSame(0, Auto_Alt_Text_Library_Run::feed(2));
    }

    public function test_a_late_retry_of_a_finished_run_keeps_its_report() {
        global $wpdb;
        for ($i = 0; $i < 3; $i++) {
            $this->image();
        }
        $run = $this->start(3);
        $mixed = new Auto_Alt_Text_Queue(function (int $id): array {
            static $n = 0;
            return ++$n === 3 ? ['status' => 'failed', 'error' => 'Boom'] : ['status' => 'success', 'error' => null];
        });
        $mixed->run();
        $done = Auto_Alt_Text_Library_Run::get($run['id']);
        $this->assertSame('finished', $done['status']);
        $this->assertSame(2, $done['summary']['filled']);

        $this->age_rows(8);
        $this->queue()->run(); // prunes; the latest run's rows stay.
        $this->assertSame(3, $this->run_row_count($run['id']));
        $this->assertSame(1, Auto_Alt_Text_Library_Run::retry_failed($run['id']));
        $this->queue()->run();
        $after = Auto_Alt_Text_Library_Run::get($run['id']);
        $this->assertSame('finished', $after['status']);
        $this->assertSame(3, $after['summary']['filled']);
        $this->assertSame(0, $after['summary']['skipped']);
    }

    public function test_prune_keeps_only_the_latest_runs_rows() {
        $old = $this->image();
        $first = $this->start(1);
        $this->queue()->run();
        $this->assertSame('finished', Auto_Alt_Text_Library_Run::get($first['id'])['status']);
        update_post_meta($old, '_wp_attachment_image_alt', 'Skrivet av en person');
        $this->image();
        $this->image();
        $second = $this->start(2);
        add_filter('aat_library_feed_window', fn() => 2);
        // One row is processed (done), the other waits in backoff so the run stays open.
        $once = new Auto_Alt_Text_Queue(function (int $id): array {
            static $n = 0;
            return ++$n === 1 ? ['status' => 'success', 'error' => null] : ['status' => 'retry', 'error' => 'later'];
        });
        $once->run();
        $this->assertSame('running', Auto_Alt_Text_Library_Run::get($second['id'])['status']);
        $this->assertSame(2, $this->run_row_count($second['id']));
        $this->age_rows(8);
        $this->queue('quota')->run();
        $this->assertSame(0, $this->run_row_count($first['id']));
        $this->assertSame(2, $this->run_row_count($second['id']));
    }

    public function test_a_pause_during_feed_does_not_skip_the_rest_of_the_chunk() {
        for ($i = 0; $i < 12; $i++) {
            $this->image();
        }
        $run = $this->start(12);
        $paused = false;
        $filter = function ($value, $object_id, $meta_key) use (&$paused, $run) {
            if (!$paused && '_wp_attachment_image_alt' === $meta_key) {
                $paused = true;
                Auto_Alt_Text_Library_Run::pause($run['id']);
            }
            return $value;
        };
        add_filter('get_post_metadata', $filter, 10, 3);
        Auto_Alt_Text_Library_Run::feed(3);
        remove_filter('get_post_metadata', $filter, 10);
        $this->assertSame(0, $this->run_row_count($run['id']));
        $this->assertSame(0, Auto_Alt_Text_Library_Run::get($run['id'])['cursor_id']);

        Auto_Alt_Text_Library_Run::resume($run['id']);
        for ($i = 0; $i < 8; $i++) {
            $this->queue()->run();
        }
        $done = Auto_Alt_Text_Library_Run::get($run['id']);
        $this->assertSame('finished', $done['status']);
        $this->assertSame(12, $done['summary']['filled']);
        $this->assertSame(0, $done['summary']['skipped']);
        $this->assertCount(12, $this->calls);
    }

    public function test_cancel_during_feed_leaves_the_run_closed_and_a_retry_feeds_nothing_more() {
        $ids = [];
        for ($i = 0; $i < 12; $i++) {
            $ids[] = $this->image();
        }
        $run = $this->start(12);
        $cancelled = false;
        $filter = function ($value, $object_id, $meta_key) use (&$cancelled, $run) {
            if (!$cancelled && '_wp_attachment_image_alt' === $meta_key) {
                $cancelled = true;
                Auto_Alt_Text_Library_Run::cancel($run['id']);
            }
            return $value;
        };
        add_filter('get_post_metadata', $filter, 10, 3);
        Auto_Alt_Text_Library_Run::feed(3);
        remove_filter('get_post_metadata', $filter, 10);

        $after = Auto_Alt_Text_Library_Run::get($run['id']);
        $this->assertSame('cancelled', $after['status']);
        $this->assertSame(1, $after['fed_all']);
        $rows_at_cancel = $this->run_row_count($run['id']);
        $this->assertSame(0, $rows_at_cancel);
        $this->assertSame(0, Auto_Alt_Text_Library_Run::feed(3));
        $this->assertSame($rows_at_cancel, $this->run_row_count($run['id']));
    }

    public function test_retrying_after_a_mid_feed_cancel_processes_only_the_failed_rows() {
        $ids = [];
        for ($i = 0; $i < 12; $i++) {
            $ids[] = $this->image();
        }
        $run = $this->start(12);
        add_filter('aat_library_feed_window', fn() => 3);
        (new Auto_Alt_Text_Queue(function (int $id): array {
            $this->calls[] = $id;
            return ['status' => 'failed', 'error' => 'Boom'];
        }))->run();
        $failed = count($this->calls);
        $this->assertSame(3, $failed);
        Auto_Alt_Text_Library_Run::cancel($run['id']);
        $this->assertSame(1, Auto_Alt_Text_Library_Run::get($run['id'])['fed_all']);
        Auto_Alt_Text_Library_Run::retry_failed($run['id']);
        $this->assertSame(1, Auto_Alt_Text_Library_Run::get($run['id'])['fed_all']);
        $this->calls = [];
        for ($i = 0; $i < 6; $i++) {
            $this->queue()->run();
        }
        $this->assertCount(3, $this->calls);
        $this->assertSame('cancelled', Auto_Alt_Text_Library_Run::get($run['id'])['status']);
    }

    public function test_the_sweep_spares_a_paused_runs_pending_rows() {
        $this->image();
        $this->image();
        $run = $this->start(2);
        Auto_Alt_Text_Library_Run::feed(5);
        Auto_Alt_Text_Library_Run::pause($run['id']);
        $this->queue()->run();
        $this->assertSame(2, $this->pending_count());
    }

    public function test_a_claimed_row_of_a_run_paused_meanwhile_returns_to_pending() {
        global $wpdb;
        $id = $this->image();
        $run = $this->start(1);
        Auto_Alt_Text_Library_Run::feed(5);
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . Auto_Alt_Text_Queue::get_table_name() . ' SET status = %s WHERE attachment_id = %d', 'processing', $id
        ));
        Auto_Alt_Text_Library_Run::pause($run['id']);
        $method = new ReflectionMethod(Auto_Alt_Text_Queue::class, 'process_job');
        $method->setAccessible(true);
        $job = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Auto_Alt_Text_Queue::get_table_name() . ' WHERE attachment_id = %d', $id
        ));
        $method->invoke($this->queue(), $job);
        $this->assertSame([], $this->calls);
        $this->assertSame('pending', $this->row_status($id));
    }

    public function test_uploads_stay_excluded_while_auto_generate_is_off_during_a_run() {
        update_option('auto_alt_text_auto_generate', 0);
        $upload = $this->image('Has alt');
        (new Auto_Alt_Text_Queue())->enqueue($upload, 'upload');
        $run_image = $this->image();
        $this->start(1);
        $this->queue()->run();
        $this->assertSame([$run_image], $this->calls);
        $this->assertSame('pending', $this->row_status($upload));
    }

    public function test_global_retry_failed_leaves_run_rows_alone() {
        global $wpdb;
        $this->image();
        $run = $this->start(1);
        (new Auto_Alt_Text_Queue(fn() => ['status' => 'failed', 'error' => 'Boom']))->run();
        $this->assertSame(0, (new Auto_Alt_Text_Queue())->retry_failed());
        $this->assertSame(1, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Auto_Alt_Text_Queue::get_table_name() . " WHERE status = 'failed'"));
    }

    public function test_a_person_writing_alt_text_before_the_write_keeps_it_through_the_real_path() {
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $run = $this->start(1);
        $hits = 0;
        add_filter('pre_http_request', function ($pre, $args, $url) use ($id, &$hits) {
            if (false === strpos($url, 'openai.com')) {
                return $pre;
            }
            $hits++;
            update_post_meta($id, '_wp_attachment_image_alt', 'Skrivet av en person');
            $body = [
                'status' => 'completed',
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Plugin text.']]]],
                'usage'  => ['input_tokens' => 10, 'output_tokens' => 3, 'total_tokens' => 13],
            ];
            return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
        }, 10, 3);
        (new Auto_Alt_Text_Queue())->run();
        $this->assertSame(1, $hits);
        $this->assertSame('Skrivet av en person', get_post_meta($id, '_wp_attachment_image_alt', true));
        $this->assertSame('', (string) get_post_meta($id, Auto_Alt_Text_Provenance::META_KEY, true));
        $this->assertSame('skipped', $this->row_status($id));
        $this->assertSame(1, Auto_Alt_Text_Library_Run::get($run['id'])['summary']['skipped']);
    }

    /**
     * An editor's save in another request never clears this request's meta
     * cache. A direct row insert stands in for it: update_post_meta() here
     * would clear the cache and hide the problem.
     */
    private function write_alt_in_another_request(int $id, string $alt): void {
        global $wpdb;
        $wpdb->insert($wpdb->postmeta, ['post_id' => $id, 'meta_key' => '_wp_attachment_image_alt', 'meta_value' => $alt]);
    }

    /** The alt text in the database, past any cache. */
    private function stored_alt(int $id): ?string {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1",
            $id, '_wp_attachment_image_alt'
        ));
    }

    /** Answers OpenAI calls with a fixed text; $during runs inside each call. */
    private function mock_openai(callable $during): void {
        add_filter('pre_http_request', function ($pre, $args, $url) use ($during) {
            if (false === strpos($url, 'openai.com')) {
                return $pre;
            }
            $during();
            $body = [
                'status' => 'completed',
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Plugin text.']]]],
                'usage'  => ['input_tokens' => 10, 'output_tokens' => 3, 'total_tokens' => 13],
            ];
            return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
        }, 10, 3);
    }

    public function test_alt_saved_in_another_request_during_the_api_call_is_kept() {
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $run = $this->start(1);
        $hits = 0;
        $this->mock_openai(function () use ($id, &$hits) {
            $hits++;
            // The claim-time check has read (and cached) the empty alt text by now.
            $this->write_alt_in_another_request($id, 'Skrivet av en person');
        });
        (new Auto_Alt_Text_Queue())->run();
        $this->assertSame(1, $hits);
        $this->assertSame('Skrivet av en person', $this->stored_alt($id));
        $this->assertSame('skipped', $this->row_status($id));
        $this->assertSame(1, Auto_Alt_Text_Library_Run::get($run['id'])['summary']['skipped']);
    }

    public function test_alt_saved_in_another_request_for_a_queued_image_skips_it_before_the_api_call() {
        $first = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $second = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $this->start(2);
        $this->assertSame(2, Auto_Alt_Text_Library_Run::feed(20));
        // Something earlier in the request (or a persistent object cache) holds the second image's meta.
        get_post_meta($second, '_wp_attachment_image_alt', true);
        $hits = 0;
        $this->mock_openai(function () use ($second, &$hits) {
            $hits++;
            $this->write_alt_in_another_request($second, 'Skrivet av en person');
        });
        (new Auto_Alt_Text_Queue())->run();
        $this->assertSame(1, $hits, 'The second image must be skipped before any API call is paid for');
        $this->assertSame('Plugin text.', $this->stored_alt($first));
        $this->assertSame('Skrivet av en person', $this->stored_alt($second));
        $this->assertSame('skipped', $this->row_status($second));
    }
}
