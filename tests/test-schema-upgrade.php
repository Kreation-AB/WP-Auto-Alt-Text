<?php
/**
 * The schema upgrade runs only in admin, cron, CLI and REST requests, is
 * guarded lazily in the writers, and is serialised with a lock.
 */
class Auto_Alt_Text_Schema_Upgrade_Test extends WP_UnitTestCase {
    private const LOCK = 'aat_schema_upgrade_lock';

    public function setUp(): void {
        parent::setUp();
        Auto_Alt_Text_Activator::activate();
        delete_option(self::LOCK);
    }

    public function tearDown(): void {
        delete_option(self::LOCK);
        set_current_screen('front');
        parent::tearDown();
    }

    private function outdate() {
        update_option(Auto_Alt_Text_Activator::DB_VERSION_OPTION, '0.1');
    }

    private function version_is_outdated() {
        return '0.1' === get_option(Auto_Alt_Text_Activator::DB_VERSION_OPTION);
    }

    private function plugins_loaded() {
        WP_Auto_Alt_Text_Plugin::get_instance()->handle_plugins_loaded();
    }

    public function test_frontend_request_does_not_upgrade() {
        $this->outdate();
        set_current_screen('front');
        $this->plugins_loaded();
        $this->assertTrue($this->version_is_outdated());
    }

    public function test_admin_request_upgrades() {
        $this->outdate();
        set_current_screen('dashboard');
        $this->plugins_loaded();
        $this->assertFalse($this->version_is_outdated());
    }

    public function test_cron_request_upgrades() {
        $this->outdate();
        add_filter('wp_doing_cron', '__return_true');
        $this->plugins_loaded();
        remove_filter('wp_doing_cron', '__return_true');
        $this->assertFalse($this->version_is_outdated());
    }

    public function test_rest_request_upgrades() {
        $this->outdate();
        set_current_screen('front');
        $this->plugins_loaded();
        $this->assertTrue($this->version_is_outdated());
        do_action('rest_api_init');
        $this->assertFalse($this->version_is_outdated());
    }

    public function test_enqueue_upgrades_first_and_stores_the_context() {
        global $wpdb;
        $table = Auto_Alt_Text_Queue::get_table_name();
        $wpdb->query("ALTER TABLE {$table} DROP COLUMN context_post_id");
        $this->outdate();

        $id = self::factory()->attachment->create();
        $post_id = self::factory()->post->create();
        $this->assertTrue((new Auto_Alt_Text_Queue())->enqueue($id, 'upload', $post_id));

        $this->assertFalse($this->version_is_outdated());
        $this->assertSame((string) $post_id, $wpdb->get_var($wpdb->prepare(
            "SELECT context_post_id FROM {$table} WHERE attachment_id = %d", $id
        )));
    }

    public function test_track_generation_upgrades_first_and_stores_the_model() {
        global $wpdb;
        $stats = new Auto_Alt_Text_Statistics();
        $table = $stats->get_table_name();
        $wpdb->query("ALTER TABLE {$table} DROP COLUMN model");
        $this->outdate();

        $id = self::factory()->attachment->create();
        $this->assertNotFalse($stats->track_generation($id, 'Text', 10, 'upload', false, false, null, 'gpt-test', 7, 3));

        $this->assertFalse($this->version_is_outdated());
        $this->assertSame('gpt-test', $wpdb->get_var($wpdb->prepare(
            "SELECT model FROM {$table} WHERE image_id = %d", $id
        )));
    }

    public function test_fresh_lock_blocks_the_upgrade() {
        $this->outdate();
        add_option(self::LOCK, time(), '', 'no');
        $this->assertFalse(Auto_Alt_Text_Activator::maybe_upgrade());
        $this->assertTrue($this->version_is_outdated());
        $this->assertNotFalse(get_option(self::LOCK), 'Another request\'s lock is left alone');
    }

    public function test_stale_lock_is_taken_over() {
        $this->outdate();
        add_option(self::LOCK, time() - 6 * MINUTE_IN_SECONDS, '', 'no');
        $this->assertTrue(Auto_Alt_Text_Activator::maybe_upgrade());
        $this->assertFalse($this->version_is_outdated());
        $this->assertFalse(get_option(self::LOCK));
    }

    public function test_lock_is_released_after_a_successful_upgrade() {
        $this->outdate();
        $this->assertTrue(Auto_Alt_Text_Activator::maybe_upgrade());
        $this->assertFalse(get_option(self::LOCK));
    }

    public function test_lock_is_released_when_activate_throws() {
        $this->outdate();
        // An Error is not caught by activate()'s own catch (Exception).
        $thrower = function () {
            throw new Error('boom');
        };
        add_filter('dbdelta_create_queries', $thrower);
        try {
            Auto_Alt_Text_Activator::maybe_upgrade();
            $this->fail('The Error should propagate');
        } catch (Error $e) {
            $this->assertSame('boom', $e->getMessage());
        } finally {
            remove_filter('dbdelta_create_queries', $thrower);
        }
        $this->assertFalse(get_option(self::LOCK));
        $this->assertTrue($this->version_is_outdated());
    }
}
