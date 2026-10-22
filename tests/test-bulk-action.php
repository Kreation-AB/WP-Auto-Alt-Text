<?php
class Auto_Alt_Text_Bulk_Action_Test extends WP_UnitTestCase {
    private $admin;
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
        $this->admin = new Auto_Alt_Text_Admin();
        $this->editor = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($this->editor);
    }

    public function tearDown(): void {
        delete_option('auto_alt_text_api_key');
        delete_option('aat_library_runner_lock');
        unset($_REQUEST['_wpnonce'], $_REQUEST['_wp_http_referer']);
        parent::tearDown();
    }

    private function image(): int {
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        wp_update_post(['ID' => $id, 'post_author' => $this->editor]);
        return $id;
    }

    private function query(string $url): array {
        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $args);
        return $args;
    }

    public function test_bulk_action_queues_and_redirects_with_aat_bulk_queued() {
        $id = $this->image();
        $url = $this->admin->handle_bulk_actions(admin_url('upload.php'), 'generate_alt_text', [$id]);
        $this->assertSame('queued', $this->query($url)['aat_bulk']);
        $this->assertNotNull(Auto_Alt_Text_Bulk::progress($this->editor));
    }

    public function test_bulk_action_keeps_the_lists_view() {
        $from = admin_url('upload.php?mode=list&aat_alt=missing&paged=2&orderby=title&bulk_ids=1,2&processed=0&failed=0');
        $args = $this->query($this->admin->handle_bulk_actions($from, 'generate_alt_text', [$this->image()]));
        $this->assertSame('list', $args['mode']);
        $this->assertSame('missing', $args['aat_alt']);
        $this->assertSame('2', $args['paged']);
        $this->assertSame('title', $args['orderby']);
        $this->assertSame('queued', $args['aat_bulk']);
        $this->assertArrayNotHasKey('bulk_ids', $args);
        $this->assertArrayNotHasKey('processed', $args);
        $this->assertArrayNotHasKey('failed', $args);
    }

    public function test_bulk_action_without_upload_files_queues_nothing() {
        $id = $this->image();
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $url = $this->admin->handle_bulk_actions(admin_url('upload.php'), 'generate_alt_text', [$id]);
        $this->assertArrayNotHasKey('aat_bulk', $this->query($url));
        $this->assertNull(Auto_Alt_Text_Bulk::progress($subscriber));
        $this->assertNull(Auto_Alt_Text_Bulk::progress($this->editor));
    }

    public function test_bulk_action_maps_errors_to_the_query_arg() {
        $url = $this->admin->handle_bulk_actions(admin_url('upload.php'), 'generate_alt_text', []);
        $this->assertSame('empty', $this->query($url)['aat_bulk']);
        $url = $this->admin->handle_bulk_actions(admin_url('upload.php'), 'generate_alt_text', range(1, 1001));
        $this->assertSame('too_many', $this->query($url)['aat_bulk']);
        delete_option('auto_alt_text_api_key');
        $url = $this->admin->handle_bulk_actions(admin_url('upload.php'), 'generate_alt_text', [$this->image()]);
        $this->assertSame('no_key', $this->query($url)['aat_bulk']);
    }

    public function test_bulk_action_reports_rejected_files_next_to_the_queued_ones() {
        $author = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($author);
        $own = $this->image();
        wp_update_post(['ID' => $own, 'post_author' => $author]);
        $others = $this->image();
        $pdf = self::factory()->attachment->create(['post_mime_type' => 'application/pdf', 'post_author' => $author]);
        $from = admin_url('upload.php?mode=list&aat_bulk_rejected=9-9-9');
        $args = $this->query($this->admin->handle_bulk_actions($from, 'generate_alt_text', [$own, $others, $pdf, 999999]));
        $this->assertSame('queued', $args['aat_bulk']);
        $this->assertSame('1-1-1', $args['aat_bulk_rejected']);
        $this->assertSame('list', $args['mode']);
        $this->assertNotNull(Auto_Alt_Text_Bulk::progress($author));
    }

    public function test_bulk_action_with_nothing_queued_reports_only_the_rejections() {
        $pdf = self::factory()->attachment->create(['post_mime_type' => 'application/pdf', 'post_author' => $this->editor]);
        $args = $this->query($this->admin->handle_bulk_actions(admin_url('upload.php'), 'generate_alt_text', [$pdf]));
        $this->assertArrayNotHasKey('aat_bulk', $args);
        $this->assertSame('1-0-0', $args['aat_bulk_rejected']);
        $this->assertNull(Auto_Alt_Text_Bulk::progress($this->editor));
    }

    public function test_bulk_action_without_rejections_adds_no_rejected_arg() {
        $args = $this->query($this->admin->handle_bulk_actions(admin_url('upload.php'), 'generate_alt_text', [$this->image()]));
        $this->assertSame('queued', $args['aat_bulk']);
        $this->assertArrayNotHasKey('aat_bulk_rejected', $args);
    }

    public function test_other_actions_pass_through() {
        $from = admin_url('upload.php?x=1');
        $this->assertSame($from, $this->admin->handle_bulk_actions($from, 'trash', [1]));
    }

    public function test_aat_bulk_is_a_removable_query_arg() {
        $this->assertContains('aat_bulk', apply_filters('removable_query_args', []));
        $this->assertContains('aat_bulk_rejected', apply_filters('removable_query_args', []));
    }

    public function test_admin_post_dismiss_and_cancel_check_nonce_and_capability() {
        $this->image();
        Auto_Alt_Text_Bulk::request($this->editor, [$this->image()]);

        foreach (['dismiss', 'cancel'] as $action) {
            unset($_REQUEST['_wpnonce']);
            try {
                Auto_Alt_Text_Admin::process_bulk_control($action);
                $this->fail('Expected WPDieException for a missing nonce.');
            } catch (WPDieException $e) {
                $this->assertNotNull(Auto_Alt_Text_Bulk::progress($this->editor));
            }
        }

        $_REQUEST['_wpnonce'] = wp_create_nonce('aat_bulk');
        $_REQUEST['_wp_http_referer'] = '/wp-admin/upload.php?mode=list';
        $this->assertStringContainsString('upload.php?mode=list', Auto_Alt_Text_Admin::process_bulk_control('cancel'));
        $this->assertNotNull(Auto_Alt_Text_Bulk::progress($this->editor));
        Auto_Alt_Text_Admin::process_bulk_control('dismiss');
        $this->assertNull(Auto_Alt_Text_Bulk::progress($this->editor));

        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $_REQUEST['_wpnonce'] = wp_create_nonce('aat_bulk');
        $this->expectException(WPDieException::class);
        Auto_Alt_Text_Admin::process_bulk_control('dismiss');
    }
}
