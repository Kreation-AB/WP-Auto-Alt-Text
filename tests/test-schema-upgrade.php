<?php
/**
 * The schema upgrade runs only in admin, cron and CLI requests, is guarded
 * lazily in the writers, and is serialised with a lock.
 */
class Auto_Alt_Text_Schema_Upgrade_Test extends WP_UnitTestCase {
    private const LOCK = 'aat_schema_upgrade_lock';

    public function setUp(): void {
        parent::setUp();
        Auto_Alt_Text_Activator::activate();
        $this->drop_lock();
        Auto_Alt_Text_Activator::reset_schema_verified();
        // Never really wait in tests; the lazy guard falls through to activate().
        add_filter('aat_schema_upgrade_wait_seconds', '__return_zero');
    }

    public function tearDown(): void {
        $this->drop_lock();
        set_current_screen('front');
        parent::tearDown();
    }

    /** Inserts the lock row as another request would: straight into the DB, bypassing this request's cache. */
    private function foreign_lock($taken_at) {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            self::LOCK, (string) $taken_at
        ));
    }

    private function lock_row() {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK
        ));
    }

    private function drop_lock() {
        global $wpdb;
        $wpdb->delete($wpdb->options, ['option_name' => self::LOCK]);
        wp_cache_delete(self::LOCK, 'options');
        wp_cache_delete('notoptions', 'options');
    }

    private function outdate() {
        Auto_Alt_Text_Activator::reset_schema_verified();
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

    public function test_rest_api_init_does_not_upgrade() {
        // WooCommerce's block checkout fires rest_api_init while rendering the page.
        $this->outdate();
        set_current_screen('front');
        $this->plugins_loaded();
        do_action('rest_api_init');
        $this->assertTrue($this->version_is_outdated());
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
        $this->foreign_lock(time());
        $this->assertFalse(Auto_Alt_Text_Activator::maybe_upgrade());
        $this->assertTrue($this->version_is_outdated());
        $this->assertNotNull($this->lock_row(), 'Another request\'s lock is left alone');
    }

    public function test_fresh_lock_blocks_the_upgrade_after_an_options_api_cycle() {
        // add_option()+delete_option() leaves the name in the shared notoptions cache.
        add_option(self::LOCK, time(), '', 'no');
        delete_option(self::LOCK);
        $this->outdate();
        $this->foreign_lock(time());
        $this->assertFalse(Auto_Alt_Text_Activator::maybe_upgrade());
        $this->assertTrue($this->version_is_outdated());
        $this->assertNotNull($this->lock_row());
    }

    public function test_stale_lock_is_taken_over() {
        $this->outdate();
        $this->foreign_lock(time() - 6 * MINUTE_IN_SECONDS);
        $this->assertTrue(Auto_Alt_Text_Activator::maybe_upgrade());
        $this->assertFalse($this->version_is_outdated());
        $this->assertNull($this->lock_row());
    }

    public function test_upgrade_finished_by_another_request_is_not_repeated() {
        $current = get_option(Auto_Alt_Text_Activator::DB_VERSION_OPTION);
        $this->outdate(); // this request's cache now holds the old version
        global $wpdb;
        $wpdb->update($wpdb->options, ['option_value' => $current], ['option_name' => Auto_Alt_Text_Activator::DB_VERSION_OPTION]);

        $runs = 0;
        $counter = function ($queries) use (&$runs) {
            $runs++;
            return $queries;
        };
        add_filter('dbdelta_create_queries', $counter);
        $result = Auto_Alt_Text_Activator::maybe_upgrade();
        remove_filter('dbdelta_create_queries', $counter);

        $this->assertFalse($result);
        $this->assertSame(0, $runs, 'activate() must not run again');
        $this->assertNull($this->lock_row());
    }

    public function test_lock_is_released_after_a_successful_upgrade() {
        $this->outdate();
        $this->assertTrue(Auto_Alt_Text_Activator::maybe_upgrade());
        $this->assertNull($this->lock_row());
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
        $this->assertNull($this->lock_row());
        $this->assertTrue($this->version_is_outdated());
    }

    public function test_enqueue_still_stores_the_job_while_another_request_holds_the_lock() {
        global $wpdb;
        $table = Auto_Alt_Text_Queue::get_table_name();
        $wpdb->query("ALTER TABLE {$table} DROP COLUMN context_post_id");
        $this->outdate();
        $this->foreign_lock(time());

        $id = self::factory()->attachment->create();
        $post_id = self::factory()->post->create();
        $this->assertTrue((new Auto_Alt_Text_Queue())->enqueue($id, 'upload', $post_id));
        $this->assertSame((string) $post_id, $wpdb->get_var($wpdb->prepare(
            "SELECT context_post_id FROM {$table} WHERE attachment_id = %d", $id
        )));
    }

    public function test_lazy_guard_checks_the_schema_once_per_request() {
        $current = get_option(Auto_Alt_Text_Activator::DB_VERSION_OPTION);
        $this->outdate(); // this request's cache holds the old version
        global $wpdb;
        $wpdb->update($wpdb->options, ['option_value' => $current], ['option_name' => Auto_Alt_Text_Activator::DB_VERSION_OPTION]);

        Auto_Alt_Text_Activator::maybe_upgrade(true);

        $queries = 0;
        $counter = function ($query) use (&$queries) {
            $queries++;
            return $query;
        };
        add_filter('query', $counter);
        Auto_Alt_Text_Activator::maybe_upgrade(true);
        remove_filter('query', $counter);

        $this->assertSame(0, $queries, 'Once verified, later guards in the request cost nothing');
    }

    public function test_a_failed_activate_does_not_mark_the_schema_verified() {
        $this->outdate();
        $runs = 0;
        $fail = function ($queries) use (&$runs) {
            $runs++;
            throw new Exception('db down'); // swallowed by activate(); the version is not recorded
        };
        add_filter('dbdelta_create_queries', $fail);
        Auto_Alt_Text_Activator::maybe_upgrade(true);
        Auto_Alt_Text_Activator::maybe_upgrade(true);
        remove_filter('dbdelta_create_queries', $fail);

        $this->assertSame(2, $runs, 'The lazy guard retries after a failed upgrade');
        $this->assertTrue($this->version_is_outdated());
    }
}
