<?php
class Auto_Alt_Text_Library_Page_Test extends WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        Auto_Alt_Text_Activator::activate();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Activator::get_runs_table_name());
        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Queue::get_table_name());
        $this->seed_counts(10);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function tearDown(): void {
        delete_option('aat_library_counts');
        delete_option('aat_library_start_lock');
        wp_unschedule_hook(Auto_Alt_Text_Library_Scanner::RECOUNT_HOOK);
        parent::tearDown();
    }

    private function seed_counts(int $missing): void {
        update_option('aat_library_counts', [
            'missing' => $missing, 'ai' => 3, 'human' => 4, 'unsupported' => 2,
            'max_id' => 500, 'updated_at' => time(), 'complete' => true,
        ], false);
    }

    private function output(callable $render): string {
        ob_start();
        $render();
        return (string) ob_get_clean();
    }

    public function test_menu_registers_the_page_for_admins_only() {
        global $submenu, $menu, $_registered_pages, $_parent_pages, $admin_page_hooks;
        $submenu = $menu = $_registered_pages = $_parent_pages = $admin_page_hooks = [];
        do_action('admin_menu');
        $this->assertNotEmpty(menu_page_url('auto-alt-text-library', false));

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $this->expectException(WPDieException::class);
        Auto_Alt_Text_Library_Page::render();
    }

    public function test_render_before_a_run_shows_counts_coverage_and_start() {
        $html = $this->output([Auto_Alt_Text_Library_Page::class, 'render']);
        $this->assertStringContainsString('id="aat-library-start"', $html);
        $this->assertStringContainsString('Starta…', $html);
        $this->assertStringContainsString('kan inte behandlas', $html);
        $this->assertStringContainsString('rörs aldrig', $html);
        $this->assertMatchesRegularExpression('/<input type="checkbox" id="aat-library-coverage"(?![^>]*checked)[^>]*>/', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('<dialog id="aat-library-dialog"', $html);
    }

    public function test_render_while_running_has_an_accessible_progressbar_and_controls() {
        $run = Auto_Alt_Text_Library_Run::start('missing', get_current_user_id());
        $this->assertIsArray($run);
        $html = $this->output([Auto_Alt_Text_Library_Page::class, 'render']);
        $this->assertStringContainsString('role="progressbar"', $html);
        $this->assertStringContainsString('aria-valuemax="10"', $html);
        $this->assertStringContainsString('aria-valuemin="0"', $html);
        $this->assertStringContainsString('aria-valuenow="0"', $html);
        $this->assertStringContainsString('id="aat-library-pause"', $html);
        $this->assertStringContainsString('id="aat-library-report"', $html);
        $this->assertStringContainsString('action=aat_library_report', $html);
    }

    public function test_status_card_has_three_states_and_runs_no_count_query() {
        global $wpdb;
        $posts_selects = 0;
        $spy = function ($sql) use (&$posts_selects, $wpdb) {
            if (false !== stripos($sql, 'SELECT') && false !== strpos($sql, $wpdb->posts)) {
                $posts_selects++;
            }
            return $sql;
        };
        add_filter('query', $spy);

        $none = $this->output([Auto_Alt_Text_Library_Page::class, 'render_status_card']);
        $this->assertStringContainsString('saknar alt-text', $none);
        $this->assertStringContainsString('page=auto-alt-text-library', $none);

        $run = Auto_Alt_Text_Library_Run::start('missing', get_current_user_id());
        $running = $this->output([Auto_Alt_Text_Library_Page::class, 'render_status_card']);
        $this->assertStringContainsString('pågår', $running);

        Auto_Alt_Text_Library_Run::finalize((int) $run['id'], Auto_Alt_Text_Library_Run::STATUS_FINISHED);
        $finished = $this->output([Auto_Alt_Text_Library_Page::class, 'render_status_card']);
        remove_filter('query', $spy);
        $this->assertStringContainsString('klar', $finished);
        $this->assertSame(0, $posts_selects, 'The card must not run SELECTs over the posts table');
    }
}
