<?php
class Auto_Alt_Text_Library_Page_Test extends WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        Auto_Alt_Text_Activator::activate();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Activator::get_runs_table_name());
        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Queue::get_table_name());
        $this->seed_counts(10);
        (new Auto_Alt_Text_OpenAI())->save_api_key('sk-test-not-real');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function tearDown(): void {
        delete_option('auto_alt_text_api_key');
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

    public function test_a_running_run_without_an_api_key_shows_one_warning() {
        if (defined('AUTO_ALT_TEXT_OPENAI_API_KEY') && AUTO_ALT_TEXT_OPENAI_API_KEY) {
            $this->markTestSkipped('AUTO_ALT_TEXT_OPENAI_API_KEY is defined in this environment');
        }
        $run = Auto_Alt_Text_Library_Run::start('missing', get_current_user_id());
        $this->assertIsArray($run);
        $html = $this->output([Auto_Alt_Text_Library_Page::class, 'render']);
        $this->assertMatchesRegularExpression('/id="aat-library-warning"[^>]* hidden/', $html);
        $this->assertMatchesRegularExpression('/id="aat-library-warning-no-key"[^>]* hidden/', $html);

        delete_option('auto_alt_text_api_key');
        // Quiet long enough to count as stalled: the key warning replaces the stall warning.
        global $wpdb;
        $wpdb->update(Auto_Alt_Text_Activator::get_runs_table_name(), ['started_at' => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS)], ['id' => $run['id']]);
        $html = $this->output([Auto_Alt_Text_Library_Page::class, 'render']);
        $this->assertMatchesRegularExpression('/id="aat-library-warning"(?![^>]*hidden)/', $html);
        $this->assertMatchesRegularExpression('/<p id="aat-library-warning-no-key"(?![^>]*hidden)[^>]*>Ingen API-nyckel är sparad – körningen kan inte fortsätta\. Lägg till nyckeln under Inställningar\.</', $html);
        $this->assertMatchesRegularExpression('/id="aat-library-warning-stalled"[^>]* hidden/', $html);
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
        $this->assertStringContainsString('Bilder som saknar alt-text: 10', $none);
        $this->assertStringNotContainsString('pågår', $none);
        $this->assertStringContainsString('page=auto-alt-text-library', $none);

        $run = Auto_Alt_Text_Library_Run::start('missing', get_current_user_id());
        $running = $this->output([Auto_Alt_Text_Library_Page::class, 'render_status_card']);
        $this->assertStringContainsString('Körning pågår: 0 av 10 bilder klara', $running);
        $this->assertStringNotContainsString('Senaste körningen', $running);
        $this->assertStringNotContainsString('Bilder som saknar alt-text', $running);

        Auto_Alt_Text_Library_Run::finalize((int) $run['id'], Auto_Alt_Text_Library_Run::STATUS_FINISHED);
        $finished = $this->output([Auto_Alt_Text_Library_Page::class, 'render_status_card']);
        remove_filter('query', $spy);
        $this->assertStringContainsString('Senaste körningen är klar', $finished);
        $this->assertStringNotContainsString('pågår', $finished);
        // The cached count stays visible under the outcome.
        $this->assertStringContainsString('Bilder som saknar alt-text: 10', $finished);
        $this->assertLessThan(strpos($finished, 'Bilder som saknar alt-text'), strpos($finished, 'Senaste körningen är klar'));
        $this->assertSame(0, $posts_selects, 'The card must not run SELECTs over the posts table');
    }

    public function test_status_card_links_to_the_filtered_list_when_missing_is_positive() {
        $html = $this->output([Auto_Alt_Text_Library_Page::class, 'render_status_card']);
        $this->assertStringContainsString('Bilder som saknar alt-text: 10', $html);
        $this->assertMatchesRegularExpression('#<a [^>]*href="[^"]*upload\.php\?mode=list&(?:\#038;|amp;)?aat_alt=missing"[^>]*>Visa dem i mediebiblioteket</a>#', $html);
        $page = $this->output([Auto_Alt_Text_Library_Page::class, 'render']);
        $this->assertStringContainsString('Visa dem i mediebiblioteket', $page);
    }

    public function test_no_link_when_missing_is_zero() {
        update_option('aat_library_counts', array_merge((array) get_option('aat_library_counts'), ['missing' => 0]));
        $this->assertSame(0, Auto_Alt_Text_Library_Scanner::cached_counts()['missing']);
        $html = $this->output([Auto_Alt_Text_Library_Page::class, 'render_status_card']);
        $this->assertStringContainsString('Bilder som saknar alt-text: 0', $html);
        $this->assertStringNotContainsString('Visa dem i mediebiblioteket', $html);
        $page = $this->output([Auto_Alt_Text_Library_Page::class, 'render']);
        $this->assertStringNotContainsString('Visa dem i mediebiblioteket', $page);
    }

    public function test_status_card_schedules_the_first_count_when_there_is_none() {
        delete_option('aat_library_counts');
        wp_unschedule_hook(Auto_Alt_Text_Library_Scanner::RECOUNT_HOOK);
        $html = $this->output([Auto_Alt_Text_Library_Page::class, 'render_status_card']);
        $this->assertNotFalse(wp_next_scheduled(Auto_Alt_Text_Library_Scanner::RECOUNT_HOOK));
        $this->assertStringContainsString('Biblioteket räknas…', $html);
    }

    public function test_skipped_note_covers_every_cause() {
        $this->finished_run([], 0);
        $html = $this->output([Auto_Alt_Text_Library_Page::class, 'render']);
        $this->assertStringContainsString('(hade redan fått alt-text eller togs bort under körningen)', $html);
    }

    private function finished_run(array $failures, int $failed): array {
        global $wpdb;
        $run = Auto_Alt_Text_Library_Run::start('missing', get_current_user_id());
        Auto_Alt_Text_Library_Run::finalize((int) $run['id'], Auto_Alt_Text_Library_Run::STATUS_FINISHED);
        $summary = Auto_Alt_Text_Library_Run::get((int) $run['id'])['summary'];
        $summary['filled'] = 6;
        $summary['failed'] = $failed;
        $summary['failures'] = $failures;
        $wpdb->update(Auto_Alt_Text_Activator::get_runs_table_name(), ['summary' => wp_json_encode($summary)], ['id' => $run['id']]);
        return $run;
    }

    public function test_render_after_a_final_run_shows_summary_failures_retry_and_new_run() {
        $this->finished_run([['attachment_id' => 5, 'file' => 'broken.jpg', 'reason' => 'Timeout hos OpenAI']], 1);
        $html = $this->output([Auto_Alt_Text_Library_Page::class, 'render']);
        $this->assertStringContainsString('Körningen är klar', $html);
        $this->assertStringContainsString('id="aat-library-sum-filled">6<', $html);
        $this->assertStringContainsString('broken.jpg', $html);
        $this->assertStringContainsString('Timeout hos OpenAI', $html);
        $this->assertMatchesRegularExpression('/id="aat-library-retry"(?![^>]*hidden)/', $html);
        $this->assertMatchesRegularExpression('/id="aat-library-report-section"(?![^>]*hidden)/', $html);
        $this->assertStringContainsString('Starta en ny körning', $html);
        $this->assertMatchesRegularExpression('/id="aat-library-before"(?![^>]*hidden)/', $html);
        $this->assertLessThan(strpos($html, 'id="aat-library-before"'), strpos($html, 'id="aat-library-report-section"'));
    }

    public function test_retry_is_hidden_when_every_failure_is_an_unsupported_format() {
        $this->finished_run([['attachment_id' => 5, 'file' => 'a.tiff', 'reason' => 'Bildformatet stöds inte.']], 1);
        $html = $this->output([Auto_Alt_Text_Library_Page::class, 'render']);
        $this->assertMatchesRegularExpression('/id="aat-library-retry"[^>]* hidden/', $html);
        $this->assertMatchesRegularExpression('/id="aat-library-retry-note"(?![^>]*hidden)/', $html);
    }
}
