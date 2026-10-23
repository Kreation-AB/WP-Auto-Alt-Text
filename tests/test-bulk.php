<?php
class Auto_Alt_Text_Bulk_Test extends WP_UnitTestCase {
    private $loopbacks = 0;
    private $editor;
    private $author;

    public function setUp(): void {
        parent::setUp();
        Auto_Alt_Text_Activator::activate();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Queue::get_table_name());
        delete_option(Auto_Alt_Text_Quota_Monitor::OPTION);
        delete_option(Auto_Alt_Text_Library_Runner::LAST_TICK_OPTION);
        delete_option('aat_library_runner_lock');
        delete_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT);
        Auto_Alt_Text_Connector_Status::clear();
        (new Auto_Alt_Text_OpenAI())->save_api_key('sk-test-not-real');
        $this->editor = self::factory()->user->create(['role' => 'editor']);
        $this->author = self::factory()->user->create(['role' => 'author']);
        update_option('auto_alt_text_auto_generate', 0);
        $this->loopbacks = 0;
        add_filter('pre_http_request', function ($pre, $args, $url) {
            $action = is_array($args['body'] ?? null) ? ($args['body']['action'] ?? '') : '';
            if (false !== strpos($url, 'admin-post.php') && Auto_Alt_Text_Library_Runner::TICK_ACTION === $action) {
                $this->loopbacks++;
                return ['headers' => [], 'body' => '', 'response' => ['code' => 204, 'message' => 'No Content'], 'cookies' => [], 'filename' => null];
            }
            return $pre;
        }, 10, 3);
    }

    public function tearDown(): void {
        delete_option(Auto_Alt_Text_Quota_Monitor::OPTION);
        delete_option(Auto_Alt_Text_Library_Runner::LAST_TICK_OPTION);
        delete_option('aat_library_runner_lock');
        delete_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT);
        Auto_Alt_Text_Connector_Status::clear();
        parent::tearDown();
    }

    private function image(int $author = 0): int {
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        wp_update_post(['ID' => $id, 'post_author' => $author ?: $this->editor]);
        return $id;
    }

    private function images(int $n, int $author = 0): array {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $ids[] = $this->image($author);
        }
        return $ids;
    }

    private function meta(int $user): array {
        return (array) get_user_meta($user, Auto_Alt_Text_Bulk::META_KEY, true);
    }

    private function row_for(int $attachment_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Auto_Alt_Text_Queue::get_table_name() . ' WHERE attachment_id = %d ORDER BY id DESC LIMIT 1', $attachment_id));
    }

    private function set_row(int $attachment_id, array $data): void {
        global $wpdb;
        $row = $this->row_for($attachment_id);
        $wpdb->update(Auto_Alt_Text_Queue::get_table_name(), $data, ['id' => (int) $row->id]);
    }

    private function row_count(): int {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Auto_Alt_Text_Queue::get_table_name());
    }

    public function test_request_queues_images_and_tracks_job_ids() {
        $ids = $this->images(3);
        $result = Auto_Alt_Text_Bulk::request($this->editor, $ids);
        $this->assertIsArray($result);
        $this->assertSame(3, $result['queued']);
        $this->assertSame(0, $result['already_queued']);
        $job_ids = $this->meta($this->editor)['job_ids'];
        $this->assertCount(3, array_unique($job_ids));
        foreach ($job_ids as $job_id) {
            $this->assertIsInt($job_id);
        }
        $this->assertIsArray($result['bulk']);
        $this->assertSame(3, $result['bulk']['total']);
    }

    public function test_request_rejects_non_images_missing_posts_and_uneditable_images() {
        $pdf = self::factory()->attachment->create(['post_mime_type' => 'application/pdf', 'post_author' => $this->editor]);
        $others = $this->image($this->editor);
        $result = Auto_Alt_Text_Bulk::request($this->author, [$pdf, 999999, $others]);
        $this->assertIsArray($result);
        $this->assertSame(0, $result['queued']);
        $this->assertSame(['not_image' => 1, 'no_permission' => 1, 'not_found' => 1], $result['rejected']);
        $this->assertNull($result['bulk']);
        $this->assertSame(0, $this->row_count());
    }

    public function test_request_caps_at_1000_and_5000_tracked() {
        $too_many = Auto_Alt_Text_Bulk::request($this->editor, range(1, 1001));
        $this->assertWPError($too_many);
        $this->assertSame('aat_bulk_too_many', $too_many->get_error_code());
        $this->assertSame(400, $too_many->get_error_data()['status']);
        $this->assertSame('Markera högst 1 000 bilder åt gången. Använd Hela biblioteket för fler.', $too_many->get_error_message());

        $this->assertWPError(Auto_Alt_Text_Bulk::request($this->editor, []));
        $this->assertSame('aat_bulk_empty', Auto_Alt_Text_Bulk::request($this->editor, [])->get_error_code());

        update_user_meta($this->editor, Auto_Alt_Text_Bulk::META_KEY, [
            'started_at' => time(), 'finished_at' => null, 'job_ids' => range(100001, 104998),
        ]);
        $real = $this->images(1);
        (new Auto_Alt_Text_Queue())->enqueue_bulk($real[0], 'batch');
        $meta = $this->meta($this->editor);
        $meta['job_ids'][] = (int) $this->row_for($real[0])->id;
        update_user_meta($this->editor, Auto_Alt_Text_Bulk::META_KEY, $meta);
        $full = Auto_Alt_Text_Bulk::request($this->editor, $this->images(2));
        $this->assertWPError($full);
        $this->assertSame('aat_bulk_tracked_full', $full->get_error_code());
        $this->assertSame(400, $full->get_error_data()['status']);
    }

    public function test_request_without_a_key_queues_nothing() {
        delete_option('auto_alt_text_api_key');
        if (defined('AUTO_ALT_TEXT_OPENAI_API_KEY') && AUTO_ALT_TEXT_OPENAI_API_KEY) {
            $this->markTestSkipped('API key constant defined.');
        }
        $result = Auto_Alt_Text_Bulk::request($this->editor, $this->images(1));
        $this->assertWPError($result);
        $this->assertSame('aat_bulk_no_key', $result->get_error_code());
        $this->assertSame(409, $result->get_error_data()['status']);
        $this->assertSame(0, $this->row_count());
    }

    public function test_double_submit_does_not_duplicate() {
        $ids = $this->images(2);
        $first = Auto_Alt_Text_Bulk::request($this->editor, $ids);
        $second = Auto_Alt_Text_Bulk::request($this->editor, $ids);
        $this->assertSame(2, $first['queued']);
        $this->assertSame(0, $second['queued']);
        $this->assertSame(2, $second['already_queued']);
        $this->assertCount(2, $this->meta($this->editor)['job_ids']);
        $this->assertSame(2, $this->row_count());
    }

    public function test_request_appends_while_running_and_replaces_after_finish() {
        $first = $this->images(2);
        Auto_Alt_Text_Bulk::request($this->editor, $first);
        $started = $this->meta($this->editor)['started_at'];
        Auto_Alt_Text_Bulk::request($this->editor, $this->images(1));
        $this->assertCount(3, $this->meta($this->editor)['job_ids']);
        $this->assertSame($started, $this->meta($this->editor)['started_at']);

        foreach ($first as $id) {
            $this->set_row($id, ['status' => 'done']);
        }
        $rest = $this->meta($this->editor)['job_ids'];
        global $wpdb;
        $wpdb->query('UPDATE ' . Auto_Alt_Text_Queue::get_table_name() . " SET status = 'done'");
        // Finished but never polled: request() refreshes it itself and replaces it.
        $this->assertNull($this->meta($this->editor)['finished_at'] ?? null);

        Auto_Alt_Text_Bulk::request($this->editor, $this->images(1));
        $meta = $this->meta($this->editor);
        $this->assertCount(1, $meta['job_ids']);
        $this->assertNull($meta['finished_at']);
        $this->assertNotContains($rest[0], $meta['job_ids']);
    }

    public function test_large_finished_unpolled_bulk_is_replaced_not_capped() {
        $done = $this->images(1);
        Auto_Alt_Text_Bulk::request($this->editor, $done);
        $this->set_row($done[0], ['status' => 'done']);
        $meta = $this->meta($this->editor);
        $meta['job_ids'] = array_merge($meta['job_ids'], range(100001, 104999));
        update_user_meta($this->editor, Auto_Alt_Text_Bulk::META_KEY, $meta);
        $this->assertNull($this->meta($this->editor)['finished_at'] ?? null);

        $new = $this->images(2);
        $result = Auto_Alt_Text_Bulk::request($this->editor, $new);
        $this->assertIsArray($result);
        $this->assertSame(2, $result['queued']);
        $this->assertCount(2, $this->meta($this->editor)['job_ids']);
    }

    public function test_progress_counts() {
        $ids = $this->images(7);
        Auto_Alt_Text_Bulk::request($this->editor, $ids);
        $this->set_row($ids[0], ['status' => 'done']);
        $this->set_row($ids[1], ['status' => 'skipped']);
        $this->set_row($ids[2], ['status' => 'skipped', 'last_error' => Auto_Alt_Text_Queue::CANCELLED_ERROR]);
        $this->set_row($ids[3], ['status' => 'failed', 'last_error' => 'x']);
        $this->set_row($ids[4], ['status' => 'pending', 'attempts' => 2]);
        $this->set_row($ids[5], ['status' => 'processing']);
        // $ids[6] stays plain pending; one attachment is deleted.
        $removed = $ids[6];
        wp_delete_attachment($removed, true);

        $p = Auto_Alt_Text_Bulk::progress($this->editor);
        $this->assertSame(7, $p['total']);
        $this->assertSame(1, $p['written']);
        $this->assertSame(1, $p['skipped']);
        $this->assertSame(1, $p['cancelled']);
        $this->assertSame(1, $p['failed']);
        $this->assertSame(2, $p['pending']);
        $this->assertSame(1, $p['retrying']);
        $this->assertSame(1, $p['removed']);
    }

    public function test_progress_states() {
        $ids = $this->images(1);
        Auto_Alt_Text_Bulk::request($this->editor, $ids);
        $this->assertSame('running', Auto_Alt_Text_Bulk::progress($this->editor)['state']);

        set_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT, 1, 600);
        $this->assertSame('paused_auth', Auto_Alt_Text_Bulk::progress($this->editor)['state']);

        Auto_Alt_Text_Connector_Status::mark_blocked('blocked');
        $this->assertSame('paused_connector', Auto_Alt_Text_Bulk::progress($this->editor)['state']);
        Auto_Alt_Text_Connector_Status::clear();
        delete_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT);

        Auto_Alt_Text_Quota_Monitor::record_failure('You exceeded your current quota');
        $this->assertTrue(Auto_Alt_Text_Quota_Monitor::is_active());
        $this->assertSame('paused_quota', Auto_Alt_Text_Bulk::progress($this->editor)['state']);
        delete_option(Auto_Alt_Text_Quota_Monitor::OPTION);

        $saved = get_option('auto_alt_text_api_key');
        if (!defined('AUTO_ALT_TEXT_OPENAI_API_KEY') || !AUTO_ALT_TEXT_OPENAI_API_KEY) {
            delete_option('auto_alt_text_api_key');
            $this->assertSame('paused_no_key', Auto_Alt_Text_Bulk::progress($this->editor)['state']);
            update_option('auto_alt_text_api_key', $saved);
        }

        $this->set_row($ids[0], ['status' => 'done']);
        $this->assertSame('finished', Auto_Alt_Text_Bulk::progress($this->editor)['state']);
    }

    public function test_progress_stalled() {
        $ids = $this->images(1);
        Auto_Alt_Text_Bulk::request($this->editor, $ids);
        $meta = $this->meta($this->editor);

        $meta['started_at'] = time() - 120;
        update_user_meta($this->editor, Auto_Alt_Text_Bulk::META_KEY, $meta);
        update_option(Auto_Alt_Text_Library_Runner::LAST_TICK_OPTION, time() - 120, false);
        $this->assertTrue(Auto_Alt_Text_Bulk::progress($this->editor)['stalled']);

        update_option(Auto_Alt_Text_Library_Runner::LAST_TICK_OPTION, time(), false);
        $this->assertFalse(Auto_Alt_Text_Bulk::progress($this->editor)['stalled']);

        $meta['started_at'] = time();
        update_user_meta($this->editor, Auto_Alt_Text_Bulk::META_KEY, $meta);
        update_option(Auto_Alt_Text_Library_Runner::LAST_TICK_OPTION, 0, false);
        $this->assertFalse(Auto_Alt_Text_Bulk::progress($this->editor)['stalled']);
    }

    public function test_finished_bulk_expires_after_24h() {
        $ids = $this->images(1);
        Auto_Alt_Text_Bulk::request($this->editor, $ids);
        $this->set_row($ids[0], ['status' => 'done']);
        $p = Auto_Alt_Text_Bulk::progress($this->editor);
        $this->assertSame('finished', $p['state']);
        $this->assertGreaterThan(0, $this->meta($this->editor)['finished_at']);
        $this->assertNotNull($p['finished_at']);

        $meta = $this->meta($this->editor);
        $meta['finished_at'] = time() - 25 * HOUR_IN_SECONDS;
        update_user_meta($this->editor, Auto_Alt_Text_Bulk::META_KEY, $meta);
        $this->assertNull(Auto_Alt_Text_Bulk::progress($this->editor));
        $this->assertSame('', get_user_meta($this->editor, Auto_Alt_Text_Bulk::META_KEY, true));
    }

    public function test_failures_are_mapped_capped_and_newest_first() {
        wp_set_current_user($this->editor);
        $ids = $this->images(25);
        Auto_Alt_Text_Bulk::request($this->editor, $ids);
        foreach ($ids as $i => $id) {
            $this->set_row($id, [
                'status'     => 'failed',
                'last_error' => 0 === $i % 2 ? 'The model declined to describe this image.' : 'secret raw text sk-123',
                'updated_at' => gmdate('Y-m-d H:i:s', time() - 1000 + $i),
            ]);
        }
        $p = Auto_Alt_Text_Bulk::progress($this->editor);
        $this->assertSame(25, $p['failed']);
        $this->assertCount(20, $p['failures']);
        $this->assertSame($ids[24], $p['failures'][0]['attachment_id']);
        $this->assertSame($ids[24], $p['failures'][0]['attachment_id']);
        $this->assertSame(get_edit_post_link($ids[24], 'raw'), $p['failures'][0]['edit_url']);
        $this->assertSame('OpenAI ville inte beskriva bilden.', $p['failures'][0]['message']);
        $this->assertSame(Auto_Alt_Text_Bulk::failure_message('secret raw text sk-123'), $p['failures'][1]['message']);
        $this->assertStringNotContainsString('secret', wp_json_encode($p));
        $this->assertStringNotContainsString('&#', $p['failures'][0]['title']);
        $this->assertStringNotContainsString('&amp;', $p['failures'][0]['title']);
    }

    public function test_failure_message_maps_known_errors_and_falls_back() {
        $this->assertSame('Det gick inte att generera alt-text för bilden.', Auto_Alt_Text_Bulk::failure_message('whatever'));
        $this->assertSame('Det gick inte att generera alt-text för bilden.', Auto_Alt_Text_Bulk::failure_message(null));
        $cases = [
            'The image file could not be found.'                                   => 'Bilden kunde inte läsas.',
            'The image could not be downloaded. Please try again.'                 => 'Bilden kunde inte läsas.',
            'The image could not be prepared.'                                     => 'Bilden kunde inte läsas.',
            'Invalid image source'                                                 => 'Bilden kunde inte läsas.',
            'Bildformatet stöds inte.'                                             => 'Bildformatet stöds inte.',
            'Network error while contacting OpenAI. Please check server connectivity and try again.' => 'OpenAI svarade inte i tid.',
            'Processing timed out (recovered stuck job)'                           => 'OpenAI svarade inte i tid.',
            'OpenAI returned an empty response. Please try again.'                 => 'OpenAI gav inget användbart svar.',
            'The model ran out of output tokens.'                                  => 'OpenAI gav inget användbart svar.',
            'The model stopped before finishing the alt text.'                     => 'OpenAI gav inget användbart svar.',
            'The model declined to describe this image.'                           => 'OpenAI ville inte beskriva bilden.',
            'The model gpt-x is not available to this OpenAI API key. Choose another model under Auto Alt Text → Settings.' => 'Modellen är inte tillgänglig för API-nyckeln.',
            'OpenAI kunde inte hämta bilden från webbplatsen. Kontrollera att bildfilen finns och går att nå utifrån.' => 'OpenAI kunde inte hämta bilden.',
            'Bildfilen saknas på servern (HTTP 404). Ladda upp bilden igen.'       => 'Bildfilen saknas på servern.',
            'Bildfilen saknas på servern (HTTP 410). Ladda upp bilden igen.'       => 'Bildfilen saknas på servern.',
        ];
        foreach ($cases as $error => $expected) {
            $this->assertSame($expected, Auto_Alt_Text_Bulk::failure_message($error), $error);
            $this->assertSame($expected, Auto_Alt_Text_Bulk::failure_message(strtoupper($error)), $error);
        }
    }

    public function test_dismiss_stops_tracking_but_jobs_continue() {
        $ids = $this->images(2);
        Auto_Alt_Text_Bulk::request($this->editor, $ids);
        $this->assertSame('running', Auto_Alt_Text_Bulk::progress($this->editor)['state']);
        Auto_Alt_Text_Bulk::dismiss($this->editor);
        $this->assertSame('', get_user_meta($this->editor, Auto_Alt_Text_Bulk::META_KEY, true));
        $this->assertNull(Auto_Alt_Text_Bulk::progress($this->editor));
        foreach ($ids as $id) {
            $this->assertSame('pending', $this->row_for($id)->status);
        }
    }

    public function test_cancel_skips_only_this_users_pending_batch_rows() {
        $mine = $this->images(2);
        $theirs = $this->images(1, $this->author);
        Auto_Alt_Text_Bulk::request($this->editor, $mine);
        Auto_Alt_Text_Bulk::request($this->author, $theirs);
        $this->set_row($mine[1], ['status' => 'processing']);

        $this->assertSame(1, Auto_Alt_Text_Bulk::cancel($this->editor));
        $this->assertSame('skipped', $this->row_for($mine[0])->status);
        $this->assertSame(Auto_Alt_Text_Queue::CANCELLED_ERROR, $this->row_for($mine[0])->last_error);
        $this->assertSame('processing', $this->row_for($mine[1])->status);
        $this->assertSame('pending', $this->row_for($theirs[0])->status);
        $this->assertSame(0, Auto_Alt_Text_Bulk::cancel($this->editor));
    }

    public function test_request_kicks_the_runner() {
        Auto_Alt_Text_Bulk::request($this->editor, $this->images(1));
        $this->assertSame(1, $this->loopbacks);
    }
}
