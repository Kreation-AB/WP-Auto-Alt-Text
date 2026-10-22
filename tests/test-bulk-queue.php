<?php
class Auto_Alt_Text_Bulk_Queue_Test extends WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        Auto_Alt_Text_Activator::activate();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Activator::get_runs_table_name());
        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Queue::get_table_name());
        delete_option(Auto_Alt_Text_Provenance::POLICY_OPTION);
        delete_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT);
        (new Auto_Alt_Text_OpenAI())->save_api_key('sk-test-not-real');
    }

    public function tearDown(): void {
        delete_option(Auto_Alt_Text_Provenance::POLICY_OPTION);
        delete_option('aat_library_counts');
        delete_option('aat_library_start_lock');
        delete_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT);
        wp_unschedule_hook(Auto_Alt_Text_Library_Scanner::RECOUNT_HOOK);
        parent::tearDown();
    }

    private function image(): int {
        return self::factory()->attachment->create(['post_mime_type' => 'image/jpeg']);
    }

    private function queue(): Auto_Alt_Text_Queue {
        return new Auto_Alt_Text_Queue(function (): array {
            return ['status' => 'success', 'error' => null];
        });
    }

    private function run_id(): int {
        global $wpdb;
        update_option('aat_library_counts', [
            'missing' => 1, 'ai' => 0, 'human' => 0, 'unsupported' => 0,
            'max_id' => (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}"),
            'updated_at' => time(), 'complete' => true,
        ], false);
        $run = Auto_Alt_Text_Library_Run::start('missing', 1);
        $this->assertIsArray($run);
        return (int) $run['id'];
    }

    /** Inserts a row directly and returns its id. */
    private function row(int $attachment_id, string $type, string $status = 'pending', ?int $run_id = null, array $extra = []): int {
        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->insert(Auto_Alt_Text_Queue::get_table_name(), array_merge([
            'attachment_id'   => $attachment_id,
            'generation_type' => $type,
            'run_id'          => $run_id,
            'status'          => $status,
            'attempts'        => 0,
            'next_attempt_at' => $now,
            'created_at'      => $now,
            'updated_at'      => $now,
        ], $extra));
        return (int) $wpdb->insert_id;
    }

    private function get_row(int $id): array {
        global $wpdb;
        return (array) $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Auto_Alt_Text_Queue::get_table_name() . ' WHERE id = %d', $id
        ), ARRAY_A);
    }

    public function test_enqueue_bulk_creates_a_batch_job() {
        $att = $this->image();
        $res = $this->queue()->enqueue_bulk($att);
        $this->assertIsArray($res);
        $this->assertTrue($res['new']);
        $this->assertIsInt($res['job_id']);
        $row = $this->get_row($res['job_id']);
        $this->assertSame('batch', $row['generation_type']);
        $this->assertSame('pending', $row['status']);
        $this->assertNull($row['run_id']);
    }

    public function test_enqueue_bulk_promotes_a_pending_upload_job() {
        $att = $this->image();
        $id = $this->row($att, 'upload', 'pending', null, ['attempts' => 2]);
        $res = $this->queue()->enqueue_bulk($att);
        $this->assertFalse($res['new']);
        $this->assertSame($id, $res['job_id']);
        $row = $this->get_row($id);
        $this->assertSame('batch', $row['generation_type']);
        $this->assertSame('2', (string) $row['attempts']);
    }

    public function test_enqueue_bulk_takes_over_a_pending_run_job() {
        $att = $this->image();
        $run = $this->run_id();
        $id = $this->row($att, 'library', 'pending', $run, [
            'attempts' => 3, 'last_error' => 'boom',
            'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);
        $res = $this->queue()->enqueue_bulk($att);
        $this->assertFalse($res['new']);
        $this->assertSame($id, $res['job_id']);
        $row = $this->get_row($id);
        $this->assertNull($row['run_id']);
        $this->assertSame('batch', $row['generation_type']);
        $this->assertSame('0', (string) $row['attempts']);
        $this->assertNull($row['last_error']);
        $this->assertLessThanOrEqual(time(), strtotime($row['next_attempt_at'] . ' UTC'));
    }

    public function test_enqueue_bulk_tracks_a_processing_job_unchanged() {
        $att = $this->image();
        $run = $this->run_id();
        $id = $this->row($att, 'library', 'processing', $run);
        $res = $this->queue()->enqueue_bulk($att);
        $this->assertSame($id, $res['job_id']);
        $this->assertFalse($res['new']);
        $row = $this->get_row($id);
        $this->assertSame((string) $run, (string) $row['run_id']);
        $this->assertSame('library', $row['generation_type']);
        $this->assertSame('processing', $row['status']);
    }

    public function test_enqueue_bulk_rejects_non_positive_ids() {
        $this->assertNull($this->queue()->enqueue_bulk(0));
    }

    public function test_promote_pending_ignores_run_rows() {
        $att = $this->image();
        $run = $this->run_id();
        $id = $this->row($att, 'library', 'pending', $run);
        $this->assertFalse($this->queue()->promote_pending($att, 'woocommerce_bulk'));
        $row = $this->get_row($id);
        $this->assertSame('library', $row['generation_type']);
        $this->assertSame((string) $run, (string) $row['run_id']);
    }

    public function test_enqueue_for_run_leaves_pending_bulk_rows_alone() {
        $run = $this->run_id();
        $queue = $this->queue();
        $ids = [];
        foreach (['batch', 'woocommerce_bulk'] as $type) {
            $att = $this->image();
            $ids[] = $this->row($att, $type);
            $this->assertFalse($queue->enqueue_for_run($att, $run));
        }
        foreach ($ids as $id) {
            $this->assertNull($this->get_row($id)['run_id']);
        }
        $att = $this->image();
        $this->row($att, 'upload');
        $this->assertTrue($queue->enqueue_for_run($att, $run));
    }

    private function blocked_job(string $type): int {
        $att = $this->image();
        update_post_meta($att, '_wp_attachment_image_alt', 'Skriven av en person');
        update_option(Auto_Alt_Text_Provenance::POLICY_OPTION, 'missing');
        $id = $this->row($att, $type);
        $queue = new Auto_Alt_Text_Queue(function () {
            $this->fail('The processor must not be called for a policy-blocked job.');
            return ['status' => 'success', 'error' => null];
        });
        $queue->run();
        return $id;
    }

    public function test_policy_blocked_explicit_job_ends_skipped() {
        $id = $this->blocked_job('batch');
        $this->assertSame('skipped', $this->get_row($id)['status']);
    }

    public function test_policy_blocked_automatic_job_still_ends_done() {
        $id = $this->blocked_job('upload');
        $this->assertSame('done', $this->get_row($id)['status']);
    }

    public function test_cancel_pending_skips_only_pending_non_run_batch_rows() {
        $run = $this->run_id();
        $a = $this->row($this->image(), 'batch');
        $b = $this->row($this->image(), 'batch', 'processing');
        $c = $this->row($this->image(), 'upload');
        $d = $this->row($this->image(), 'library', 'pending', $run);
        $this->assertSame(1, $this->queue()->cancel_pending([$a, $b, $c, $d]));
        $row = $this->get_row($a);
        $this->assertSame('skipped', $row['status']);
        $this->assertSame('cancelled', $row['last_error']);
        $this->assertSame('processing', $this->get_row($b)['status']);
        $this->assertSame('pending', $this->get_row($c)['status']);
        $this->assertSame('pending', $this->get_row($d)['status']);
        $this->assertSame(0, $this->queue()->cancel_pending([]));
    }

    public function test_due_bulk_count_counts_due_pending_non_run_bulk_rows() {
        $run = $this->run_id();
        $this->row($this->image(), 'batch');
        $this->row($this->image(), 'woocommerce_bulk');
        $this->row($this->image(), 'batch', 'pending', null, ['next_attempt_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
        $this->row($this->image(), 'library', 'pending', $run);
        $this->row($this->image(), 'upload');
        $this->assertSame(2, Auto_Alt_Text_Queue::due_bulk_count());
    }
}
