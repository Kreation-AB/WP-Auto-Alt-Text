<?php
class Auto_Alt_Text_Bulk_Notice_Test extends WP_UnitTestCase {
    private $editor;

    public function setUp(): void {
        parent::setUp();
        Auto_Alt_Text_Activator::activate();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Auto_Alt_Text_Queue::get_table_name());
        delete_option('aat_library_runner_lock');
        (new Auto_Alt_Text_OpenAI())->save_api_key('sk-test-not-real');
        update_option('auto_alt_text_auto_generate', 0);
        $this->editor = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($this->editor);
        set_current_screen('upload');
    }

    public function tearDown(): void {
        unset($_GET['aat_bulk'], $_GET['aat_bulk_rejected']);
        delete_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT);
        delete_option('auto_alt_text_api_key');
        set_current_screen('dashboard');
        parent::tearDown();
    }

    private function image(string $title = ''): int {
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        wp_update_post(['ID' => $id, 'post_author' => $this->editor] + ('' !== $title ? ['post_title' => $title] : []));
        return $id;
    }

    private function render(): string {
        ob_start();
        Auto_Alt_Text_Bulk_Notice::render();
        return (string) ob_get_clean();
    }

    private function queue(int $n): array {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $ids[] = $this->image();
        }
        $result = Auto_Alt_Text_Bulk::request($this->editor, $ids);
        $this->assertIsArray($result);
        return $ids;
    }

    private function set_rows(string $status, int $limit, ?string $error = null): void {
        global $wpdb;
        $table = Auto_Alt_Text_Queue::get_table_name();
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET status = %s, last_error = %s WHERE id IN (SELECT id FROM (SELECT id FROM {$table} ORDER BY id LIMIT %d) t)", $status, (string) $error, $limit));
    }

    public function test_running_notice_shows_counts_progress_and_controls() {
        $this->queue(4);
        $this->set_rows('done', 1);
        $html = $this->render();
        $this->assertStringContainsString('id="aat-bulk-notice"', $html);
        $this->assertStringContainsString('notice-info', $html);
        $this->assertStringContainsString('data-state="running"', $html);
        $this->assertStringContainsString('Alt-text genereras i bakgrunden: 1 av 4 bilder klara.', $html);
        $this->assertStringContainsString('Du kan lämna sidan', $html);
        $this->assertMatchesRegularExpression('/<progress[^>]*aria-labelledby="aat-bulk-summary"[^>]*>/', $html);
        $this->assertMatchesRegularExpression('/<progress[^>]*max="4"[^>]*value="1"|<progress[^>]*value="1"[^>]*max="4"/', $html);
        $this->assertStringContainsString('aat-bulk-cancel', $html);
        $this->assertStringContainsString('aat-bulk-dismiss', $html);
        $this->assertStringContainsString('action=aat_bulk_cancel', $html);
        $this->assertStringContainsString('_wpnonce=', $html);
        $this->assertMatchesRegularExpression('/id="aat-bulk-live"[^>]*aria-live="polite"[^>]*aria-atomic="true"|id="aat-bulk-live"[^>]*aria-atomic="true"[^>]*aria-live="polite"/', $html);
        $this->assertStringContainsString('screen-reader-text', $html);
    }

    public function test_paused_variants() {
        $this->queue(2);
        $this->set_rows('done', 1);
        $admin = self::factory()->user->create(['role' => 'administrator']);

        set_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT, 1, 600);
        $html = $this->render();
        $this->assertStringContainsString('notice-warning', $html);
        $this->assertStringContainsString('data-state="paused_auth"', $html);
        $this->assertStringContainsString('OpenAI avvisade API-nyckeln', $html);
        // The progress bar's text equivalent stays visible while paused.
        $this->assertStringContainsString('fortsätter när nyckeln fungerar. 1 av 2 bilder klara.', $html);
        // An editor can't open the settings page: no link, but who to ask.
        $this->assertStringNotContainsString('Öppna Anslutning', $html);
        $this->assertStringContainsString('Kontakta webbplatsens administratör.', $html);
        delete_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT);

        $progress = Auto_Alt_Text_Bulk::progress($this->editor);
        wp_set_current_user($admin);
        $auth = Auto_Alt_Text_Bulk_Notice::html(array_merge($progress, ['state' => 'paused_auth']));
        $this->assertStringContainsString('Öppna Anslutning', $auth);
        $this->assertStringNotContainsString('Kontakta webbplatsens administratör.', $auth);

        $quota = Auto_Alt_Text_Bulk_Notice::html(array_merge($progress, ['state' => 'paused_quota']));
        $this->assertStringContainsString('kvoten på OpenAI-kontot är slut', $quota);
        $this->assertStringContainsString('1 av 2 bilder klara.', $quota);

        $connector = Auto_Alt_Text_Bulk_Notice::html(array_merge($progress, ['state' => 'paused_connector']));
        $this->assertStringContainsString('WordPress AI-anslutningen behöver godkännas', $connector);
        $this->assertStringContainsString(esc_url(Auto_Alt_Text_Connector_Status::approval_url()), $connector);
        wp_set_current_user($this->editor);
        $connector = Auto_Alt_Text_Bulk_Notice::html(array_merge($progress, ['state' => 'paused_connector']));
        $this->assertStringNotContainsString(esc_url(Auto_Alt_Text_Connector_Status::approval_url()), $connector);
        $this->assertStringContainsString('Kontakta webbplatsens administratör.', $connector);

        delete_option('auto_alt_text_api_key');
        $html = $this->render();
        $this->assertStringContainsString('data-state="paused_no_key"', $html);
        $this->assertStringContainsString('ingen API-nyckel är angiven', $html);
    }

    public function test_finished_notice_lists_failures_with_edit_links_and_hides_zero_counts() {
        $ids = $this->queue(3);
        $this->set_rows('done', 1);
        global $wpdb;
        $table = Auto_Alt_Text_Queue::get_table_name();
        $wpdb->query("UPDATE {$table} SET status = 'skipped' WHERE attachment_id = {$ids[1]}");
        $wpdb->query("UPDATE {$table} SET status = 'failed', last_error = 'Image could not be read' WHERE attachment_id = {$ids[2]}");
        $html = $this->render();
        $this->assertStringContainsString('data-state="finished"', $html);
        $this->assertStringContainsString('Klart: 1 bild fick alt-text.', $html);
        $this->assertStringContainsString('1 hoppades över', $html);
        $this->assertStringContainsString('1 misslyckades.', $html);
        $this->assertStringNotContainsString('avbröts', $html);
        $this->assertStringNotContainsString('tagits bort', $html);
        $this->assertStringContainsString('notice-warning', $html);
        $this->assertStringContainsString('Visa bilder som misslyckades', $html);
        $this->assertStringContainsString(esc_url(get_edit_post_link($ids[2], 'raw')), $html);
        $this->assertStringContainsString('Stäng', $html);
        $this->assertStringContainsString('action=aat_bulk_dismiss', $html);
        $this->assertStringNotContainsString('action=aat_bulk_cancel"', $html);
    }

    public function test_finished_without_failures_is_a_success_notice() {
        $this->queue(2);
        $this->set_rows('done', 2);
        $html = $this->render();
        $this->assertStringContainsString('notice-success', $html);
        $this->assertStringContainsString('Klart: 2 bilder fick alt-text.', $html);
        $this->assertStringNotContainsString('misslyckades.', $html);
    }

    public function test_stalled_text_replaces_leave_the_page_without_js() {
        $this->queue(2);
        $progress = Auto_Alt_Text_Bulk::progress($this->editor);
        $html = Auto_Alt_Text_Bulk_Notice::html(array_merge($progress, ['stalled' => true]));
        $this->assertStringContainsString('Bakgrundskörningen verkar inte starta', $html);
        $this->assertStringNotContainsString('Du kan lämna sidan', $html);
        $plain = Auto_Alt_Text_Bulk_Notice::html(array_merge($progress, ['stalled' => false, 'retrying' => 1]));
        $this->assertStringContainsString('Du kan lämna sidan', $plain);
        $this->assertStringContainsString('1 väntar på ett nytt försök', $plain);
    }

    public function test_error_codes_render_their_messages() {
        $expected = [
            'too_many'     => 'Markera högst 1',
            'no_key'       => 'Ange en API-nyckel',
            'tracked_full' => 'Vänta tills den pågående genereringen är klar',
            'empty'        => 'Markera minst en bild.',
        ];
        foreach ($expected as $code => $text) {
            $_GET['aat_bulk'] = $code;
            $html = $this->render();
            $this->assertStringContainsString('notice-error', $html, $code);
            $this->assertStringContainsString($text, $html, $code);
        }
        $_GET['aat_bulk'] = 'queued';
        $this->assertSame('', $this->render());
        $_GET['aat_bulk'] = 'bogus';
        $this->assertSame('', $this->render());
    }

    public function test_rejected_counts_render_a_warning_with_the_reasons_that_occurred() {
        $_GET['aat_bulk_rejected'] = '2-1-0';
        $html = $this->render();
        $this->assertStringContainsString('notice-warning', $html);
        $this->assertStringContainsString('aat-bulk-rejected', $html);
        $this->assertStringContainsString('3 av de markerade filerna kunde inte köas: 2 är inte bilder, 1 får du inte redigera.', $html);
        $this->assertStringNotContainsString('finns inte längre', $html);
        $this->assertStringNotContainsString('id="aat-bulk-notice"', $html);

        $_GET['aat_bulk_rejected'] = '1-0-3';
        $this->assertStringContainsString('4 av de markerade filerna kunde inte köas: 1 är inte en bild, 3 finns inte längre.', $this->render());

        foreach (['0-0-0', 'x', '', '-5-a'] as $junk) {
            $_GET['aat_bulk_rejected'] = $junk;
            $this->assertStringNotContainsString('aat-bulk-rejected', $this->render(), $junk);
        }
    }

    public function test_rejected_warning_renders_next_to_the_progress_notice() {
        $this->queue(1);
        $_GET['aat_bulk_rejected'] = '0-2-0';
        $html = $this->render();
        $this->assertStringContainsString('2 av de markerade filerna kunde inte köas: 2 får du inte redigera.', $html);
        $this->assertStringContainsString('id="aat-bulk-notice"', $html);
        $this->assertLessThan(strpos($html, 'id="aat-bulk-notice"'), strpos($html, 'aat-bulk-rejected'));
    }

    public function test_rejected_counts_round_trip_and_are_capped() {
        $encoded = Auto_Alt_Text_Bulk_Notice::encode_rejected(['not_image' => 3, 'no_permission' => 0, 'not_found' => 1]);
        $this->assertSame('3-0-1', $encoded);
        $this->assertSame(['not_image' => 3, 'no_permission' => 0, 'not_found' => 1], Auto_Alt_Text_Bulk_Notice::decode_rejected($encoded));
        $this->assertSame(Auto_Alt_Text_Bulk::MAX_PER_REQUEST, Auto_Alt_Text_Bulk_Notice::decode_rejected('99999-0-0')['not_image']);
        $this->assertSame('', Auto_Alt_Text_Bulk_Notice::rejected_text(['not_image' => 0, 'no_permission' => 0, 'not_found' => 0]));
    }

    public function test_titles_are_escaped() {
        $id = $this->image('&lt;script&gt;x&lt;/script&gt; Fish &amp; Chips\'s');
        Auto_Alt_Text_Bulk::request($this->editor, [$id]);
        $this->set_rows('failed', 1, 'Image could not be read');
        $html = $this->render();
        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $html);
        $this->assertStringContainsString('Fish &amp; Chips’s', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringNotContainsString('&#8217;', $html);
        $this->assertStringNotContainsString('&amp;#8217;', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    public function test_nothing_renders_off_the_upload_screen() {
        $this->queue(1);
        set_current_screen('dashboard');
        $this->assertSame('', $this->render());
        $_GET['aat_bulk'] = 'empty';
        $this->assertSame('', $this->render());
    }

    public function test_strings_include_the_session_text() {
        $this->assertSame('Sessionen har gått ut. Ladda om sidan för att se hur det går.', Auto_Alt_Text_Bulk_Notice::strings()['session_expired']);
    }
}
