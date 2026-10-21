<?php
class Auto_Alt_Text_Cache_Manager_Test extends WP_UnitTestCase {
    private const PARTS = ['model' => 'gpt-6.1-sol', 'detail' => 'low'];

    public function set_up() {
        parent::set_up();
        delete_option(Auto_Alt_Text_Cache_Manager::VERSION_OPTION);
    }

    public function tear_down() {
        wp_using_ext_object_cache(false);
        unset($_REQUEST['_wpnonce']);
        parent::tear_down();
    }

    private function as_user(string $role): void {
        wp_set_current_user(self::factory()->user->create(['role' => $role]));
    }

    private function row_count(): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
            '%' . $wpdb->esc_like('aat_img_') . '%'
        ));
    }

    public function test_the_cache_key_changes_when_the_version_is_bumped() {
        $before = Auto_Alt_Text_Cache_Manager::get_cache_key(5, self::PARTS);
        Auto_Alt_Text_Cache_Manager::bump_version();
        $after = Auto_Alt_Text_Cache_Manager::get_cache_key(5, self::PARTS);

        $this->assertNotSame($before, $after);
        $this->assertStringStartsWith('aat_img_5_', $before);
        $this->assertStringStartsWith('aat_img_5_', $after);
    }

    public function test_the_version_defaults_to_one_and_bump_returns_the_new_value() {
        $this->assertSame(1, Auto_Alt_Text_Cache_Manager::version());
        $this->assertSame(2, Auto_Alt_Text_Cache_Manager::bump_version());
        $this->assertSame(2, Auto_Alt_Text_Cache_Manager::version());
    }

    public function test_a_bumped_version_misses_an_existing_entry() {
        $key = Auto_Alt_Text_Cache_Manager::get_cache_key(5, self::PARTS);
        Auto_Alt_Text_Cache_Manager::set_cached_response($key, 'x');
        $this->assertSame('x', Auto_Alt_Text_Cache_Manager::get_cached_response($key));

        Auto_Alt_Text_Cache_Manager::bump_version();

        $this->assertFalse(Auto_Alt_Text_Cache_Manager::get_cached_response(Auto_Alt_Text_Cache_Manager::get_cache_key(5, self::PARTS)));
    }

    public function test_clearing_removes_value_and_timeout_rows_without_an_object_cache() {
        Auto_Alt_Text_Cache_Manager::set_cached_response(Auto_Alt_Text_Cache_Manager::get_cache_key(5, self::PARTS), 'a');
        Auto_Alt_Text_Cache_Manager::set_cached_response(Auto_Alt_Text_Cache_Manager::get_cache_key(6, self::PARTS), 'b');
        $this->assertSame(4, $this->row_count());

        Auto_Alt_Text_Cache_Manager::clear_all_caches();

        $this->assertSame(0, $this->row_count());
        $this->assertSame(2, Auto_Alt_Text_Cache_Manager::version());
    }

    public function test_clearing_with_an_object_cache_still_bumps_the_version() {
        wp_using_ext_object_cache(true);
        Auto_Alt_Text_Cache_Manager::clear_all_caches();
        $this->assertSame(2, Auto_Alt_Text_Cache_Manager::version());
    }

    public function test_count_entries_is_null_with_an_object_cache_and_counts_rows_without() {
        Auto_Alt_Text_Cache_Manager::set_cached_response(Auto_Alt_Text_Cache_Manager::get_cache_key(5, self::PARTS), 'a');
        Auto_Alt_Text_Cache_Manager::set_cached_response(Auto_Alt_Text_Cache_Manager::get_cache_key(6, self::PARTS), 'b');
        $this->assertSame(2, Auto_Alt_Text_Cache_Manager::count_entries());

        wp_using_ext_object_cache(true);
        $this->assertNull(Auto_Alt_Text_Cache_Manager::count_entries());
    }

    public function test_clear_request_needs_manage_options_and_its_nonce() {
        $this->as_user('editor');
        $_REQUEST['_wpnonce'] = wp_create_nonce('aat_clear_cache');
        try {
            Auto_Alt_Text_Cache_Manager::process_clear_request();
            $this->fail('Expected WPDieException for an editor.');
        } catch (WPDieException $e) {
            $this->assertSame(1, Auto_Alt_Text_Cache_Manager::version());
        }

        $this->as_user('administrator');
        unset($_REQUEST['_wpnonce']);
        $this->expectException(WPDieException::class);
        Auto_Alt_Text_Cache_Manager::process_clear_request();
    }

    public function test_clear_request_returns_the_cache_section_with_the_notice() {
        $this->as_user('administrator');
        $_REQUEST['_wpnonce'] = wp_create_nonce('aat_clear_cache');

        $url = Auto_Alt_Text_Cache_Manager::process_clear_request();

        $this->assertStringContainsString('section=cache', $url);
        $this->assertStringContainsString('aat_notice=cache_cleared', $url);
        $this->assertSame(2, Auto_Alt_Text_Cache_Manager::version());
    }
}
