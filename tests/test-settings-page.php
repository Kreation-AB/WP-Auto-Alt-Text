<?php
class Auto_Alt_Text_Settings_Page_Test extends WP_UnitTestCase {
    private function key(): string {
        return 'sk-proj-' . str_repeat('a', 80);
    }

    public function setUp(): void {
        parent::setUp();
        $GLOBALS['wp_settings_errors'] = [];
        auto_alt_text_register_settings();
        delete_option('auto_alt_text_api_key');
        delete_option(Auto_Alt_Text_Key_Status::OPTION);
    }

    public function tearDown(): void {
        unset($_GET['section'], $_GET['aat_notice']);
        delete_option('auto_alt_text_api_key');
        delete_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT);
        wp_unschedule_hook(Auto_Alt_Text_Library_Scanner::RECOUNT_HOOK);
        parent::tearDown();
    }

    private function skip_if_key_constant(): void {
        if (defined('AUTO_ALT_TEXT_OPENAI_API_KEY') && AUTO_ALT_TEXT_OPENAI_API_KEY) {
            $this->markTestSkipped('AUTO_ALT_TEXT_OPENAI_API_KEY is defined in this environment');
        }
    }

    private function render_settings_page(): string {
        require_once ABSPATH . 'wp-admin/includes/template.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $GLOBALS['title'] = 'Auto Alt Text';

        ob_start();
        auto_alt_text_options();
        return (string) ob_get_clean();
    }

    /** Answers every openai.com request with $code and counts every request. */
    private function count_requests(int $code, int &$count): void {
        add_filter('pre_http_request', function ($pre, $args, $url) use ($code, &$count) {
            $count++;
            if (false === strpos($url, 'openai.com')) {
                return $pre;
            }
            return ['headers' => [], 'body' => '{}', 'response' => ['code' => $code, 'message' => 'x'], 'cookies' => []];
        }, 10, 3);
    }

    private function summary_html(string $html, string $slug): string {
        $this->assertSame(1, preg_match('#<span class="aat-nav-summary[^"]*" id="aat-summary-' . $slug . '">(.*?)</span>#s', $html, $match));
        return $match[1];
    }

    public function test_page_renders_the_six_sections_in_order_with_nav_links() {
        $this->assertSame(['anslutning', 'sprak', 'namn', 'automatik', 'cache', 'feedback'], Auto_Alt_Text_Settings_Page::SECTIONS);

        $html = $this->render_settings_page();

        $last = -1;
        foreach (Auto_Alt_Text_Settings_Page::SECTIONS as $slug) {
            $at = strpos($html, '<section class="aat-settings-section" id="aat-section-' . $slug . '" aria-labelledby="aat-section-' . $slug . '-title">');
            $this->assertNotFalse($at, "Section $slug is rendered");
            $this->assertGreaterThan($last, $at, "Section $slug comes in order");
            $last = $at;
            $this->assertStringContainsString('<a href="#aat-section-' . $slug . '" id="aat-tab-' . $slug . '" data-section="' . $slug . '">', $html);
        }
        $this->assertSame(1, preg_match('#<nav class="aat-settings-nav".*?</nav>#s', $html, $nav));
        $this->assertSame(6, substr_count($nav[0], 'href="#aat-section-'));
        $this->assertStringContainsString('<h2 id="aat-section-namn-title">Namn och varumärke</h2>', $html);
        $this->assertStringContainsString('<nav class="aat-settings-nav" aria-label="Inställningar">', $html);
        $this->assertStringContainsString('<form method="post" action="options.php" id="aat-settings-form">', $html);
        $this->assertLessThan(strpos($html, '<form method="post" action="options.php"'), strpos($html, '<nav class="aat-settings-nav"'));
        $this->assertStringContainsString("name='option_page' value='" . AUTO_ALT_TEXT_SETTINGS_GROUP . "'", $html);
        $this->assertStringContainsString('name="auto_alt_text_nonce"', $html);
        $this->assertStringContainsString('id="submit"', $html);
        $this->assertStringNotContainsString('class="card"', $html);
    }

    public function test_summaries_reflect_saved_values() {
        update_option('language', 'sv');
        update_option('auto_alt_text_naming', 'product_brand');
        update_option('aat_brand_name', 'Robust');
        update_option('auto_alt_text_auto_generate', true);
        update_option(Auto_Alt_Text_Provenance::POLICY_OPTION, 'missing');
        update_option('aat_cache_expiration', 14);
        delete_option('auto_alt_text_enable_feedback');
        add_option('auto_alt_text_enable_feedback', false);

        $summaries = Auto_Alt_Text_Settings_Page::summaries();

        $this->assertSame(Auto_Alt_Text_Settings_Page::SECTIONS, array_keys($summaries));
        $this->assertSame(['text' => 'Svenska', 'error' => false], $summaries['sprak']);
        $this->assertSame(['text' => 'Produkt- och varumärkesnamn · Robust', 'error' => false], $summaries['namn']);
        $this->assertSame(['text' => 'På · fyller bara saknade', 'error' => false], $summaries['automatik']);
        $this->assertStringStartsWith('14 dagar', $summaries['cache']['text']);
        $this->assertSame(['text' => 'Av', 'error' => false], $summaries['feedback']);
    }

    public function test_summaries_name_the_other_levels_and_policies() {
        update_option('aat_brand_name', 'Robust');
        update_option('auto_alt_text_naming', 'product');
        update_option('auto_alt_text_auto_generate', false);
        update_option(Auto_Alt_Text_Provenance::POLICY_OPTION, 'ai');
        $summaries = Auto_Alt_Text_Settings_Page::summaries();
        $this->assertSame('Produktnamn', $summaries['namn']['text'], 'The brand name shows only where it is used');
        $this->assertSame('Av · skriver över oredigerade AI-texter', $summaries['automatik']['text']);

        update_option('auto_alt_text_naming', 'visible');
        update_option(Auto_Alt_Text_Provenance::POLICY_OPTION, 'all');
        $summaries = Auto_Alt_Text_Settings_Page::summaries();
        $this->assertSame('Bara det som syns', $summaries['namn']['text']);
        $this->assertSame('Av · skriver över allt', $summaries['automatik']['text']);
    }

    public function test_cache_summary_and_count_line_show_the_saved_answers() {
        if (wp_using_ext_object_cache()) {
            $this->markTestSkipped('An external object cache hides the count');
        }
        update_option('aat_cache_expiration', 14);
        Auto_Alt_Text_Cache_Manager::set_cached_response(Auto_Alt_Text_Cache_Manager::get_cache_key(1, []), 'Text');

        $this->assertSame(['text' => '14 dagar · 1 sparade', 'error' => false], Auto_Alt_Text_Settings_Page::summaries()['cache']);
        $html = $this->render_settings_page();
        $this->assertStringContainsString('1 sparade svar', $html);
        $this->assertStringContainsString('I WP-CLI hoppar --no-cache över sparade svar.', $html);
    }

    public function test_anslutning_summary_flags_a_missing_key_as_an_error() {
        $this->skip_if_key_constant();

        $this->assertSame(['text' => 'Nyckel saknas', 'error' => true], Auto_Alt_Text_Settings_Page::summaries()['anslutning']);

        $html = $this->render_settings_page();
        $this->assertStringContainsString('<span class="aat-nav-summary aat-nav-summary--error" id="aat-summary-anslutning">Nyckel saknas</span>', $html);
        $this->assertStringContainsString('<p class="aat-key-status aat-key-status--none" id="aat-key-status">Nyckel saknas', $html);
        $this->assertStringNotContainsString('form="aat-check-key-form"', $html, 'Nothing to check without a key');
    }

    public function test_key_status_line_shows_the_age_only_for_stored_results() {
        $this->skip_if_key_constant();
        $requests = 0;
        (new Auto_Alt_Text_OpenAI())->save_api_key($this->key());
        $this->count_requests(200, $requests);
        Auto_Alt_Text_Key_Status::check(Auto_Alt_Text_Models::current_id());
        $label = Auto_Alt_Text_Models::current()['label'];

        $this->assertSame(['text' => '✓ Nyckel · ' . $label, 'error' => false], Auto_Alt_Text_Settings_Page::summaries()['anslutning']);
        $html = $this->render_settings_page();
        $this->assertMatchesRegularExpression('#<p class="aat-key-status aat-key-status--ok" id="aat-key-status">✓ Nyckeln fungerar och har tillgång till ' . preg_quote($label, '#') . ' \(kontrollerad för [^)]+ sedan\)</p>#', $html);
        $this->assertStringContainsString('<button type="submit" class="button" form="aat-check-key-form">Kontrollera nu</button>', $html);

        // The auth pause comes from real traffic, so the stored record's time says nothing about it.
        set_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT, 1, 60);
        $this->assertSame(['text' => 'Nyckeln fungerar inte', 'error' => true], Auto_Alt_Text_Settings_Page::summaries()['anslutning']);
        $html = $this->render_settings_page();
        $this->assertStringContainsString('<p class="aat-key-status aat-key-status--auth_failed" id="aat-key-status">Nyckeln godkänns inte av OpenAI</p>', $html);
        $this->assertStringNotContainsString('kontrollerad för', $html);
    }

    public function test_strip_shows_the_key_warning_without_a_key_and_the_library_status_with_one() {
        $this->skip_if_key_constant();

        $html = $this->render_settings_page();
        $this->assertStringContainsString('<div class="notice notice-warning inline aat-settings-strip"><p>Ange en API-nyckel för att börja generera alt-texter. <a href="#aat-section-anslutning">Till Anslutning</a></p></div>', $html);
        $this->assertStringNotContainsString('aat-lib-card', $html);

        (new Auto_Alt_Text_OpenAI())->save_api_key($this->key());
        $html = $this->render_settings_page();
        $this->assertStringNotContainsString('Ange en API-nyckel', $html);
        $this->assertMatchesRegularExpression('#<div class="aat-settings-strip">\s*<section class="aat-lib-card"#', $html);
        $this->assertLessThan(strpos($html, '<nav class="aat-settings-nav"'), strpos($html, 'aat-settings-strip'));
    }

    public function test_summary_text_is_escaped() {
        update_option('auto_alt_text_naming', 'product_brand');
        // The sanitizer strips tags on save; a value written another way must still render literally.
        remove_all_filters('sanitize_option_aat_brand_name');
        update_option('aat_brand_name', '<b>Robust</b> "AB"');

        $this->assertSame('Produkt- och varumärkesnamn · <b>Robust</b> "AB"', Auto_Alt_Text_Settings_Page::summaries()['namn']['text']);
        $html = $this->render_settings_page();
        $this->assertSame('Produkt- och varumärkesnamn · &lt;b&gt;Robust&lt;/b&gt; &quot;AB&quot;', $this->summary_html($html, 'namn'));
        $this->assertStringContainsString('&lt;b&gt;Robust&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>Robust</b>', $html);
    }

    public function test_language_select_and_feedback_switch_have_labels() {
        $html = $this->render_settings_page();

        $this->assertStringContainsString('<label for="aat_language">Alt-textens språk</label>', $html);
        $this->assertMatchesRegularExpression('#<select id="aat_language" name="language"#', $html);
        $this->assertStringContainsString('<label for="enable_feedback">Aktivera feedback i alt-text-dialogen</label>', $html);
        $this->assertMatchesRegularExpression('#<label class="switch">\s*<input type="checkbox"\s+name="auto_alt_text_enable_feedback"\s+id="enable_feedback"#', $html);
        $this->assertMatchesRegularExpression('#<label class="switch">\s*<input type="checkbox"\s+id="auto_generate"#', $html);
        $this->assertStringContainsString('Visar Förbättra-delen i dialogen där en genererad alt-text granskas.', $html);
    }

    public function test_brand_rows_are_not_dimmed_and_point_to_their_notes() {
        $html = $this->render_settings_page();

        $this->assertStringNotContainsString('disabled-field', $html);
        $this->assertStringNotContainsString('toggleBrandFields', $html);
        $this->assertMatchesRegularExpression('#<input type="text" id="aat_brand_name" name="aat_brand_name"[^>]* aria-describedby="aat-brand-name-note"#', $html);
        $this->assertStringContainsString('<p class="description" id="aat-brand-name-note">Används med &quot;Produkt- och varumärkesnamn&quot;.</p>', $html);
        $this->assertMatchesRegularExpression('#<textarea id="aat_brand_description" name="aat_brand_description"[^>]* aria-describedby="aat-brand-description-note"#', $html);
        $this->assertStringContainsString('<p class="description" id="aat-brand-description-note">Används med &quot;Produktnamn&quot; och &quot;Produkt- och varumärkesnamn&quot;. Används som bakgrund för ordval, aldrig ordagrant.</p>', $html);
        $this->assertStringContainsString('Gäller bara bilder som hör till en enda produkt. Övriga bilder beskriver bara det som syns.', $html);
    }

    public function test_side_buttons_submit_their_own_forms_outside_the_options_form() {
        (new Auto_Alt_Text_OpenAI())->save_api_key($this->key());

        $html = $this->render_settings_page();

        $open = strpos($html, 'id="aat-settings-form"');
        $this->assertNotFalse($open);
        $close = strpos($html, '</form>', $open);
        $options_form = substr($html, $open, $close - $open);
        $this->assertStringNotContainsString('<form', $options_form, 'No nested forms');
        $this->assertStringContainsString('<button type="submit" class="button" form="aat-clear-cache-form">Töm cache</button>', $options_form);
        $this->assertStringContainsString('form="aat-check-key-form"', $options_form);

        $admin_post = esc_url(admin_url('admin-post.php'));
        foreach (['aat-check-key-form' => 'aat_check_key', 'aat-clear-cache-form' => 'aat_clear_cache'] as $id => $action) {
            $at = strpos($html, '<form id="' . $id . '" method="post" action="' . $admin_post . '">');
            $this->assertNotFalse($at, "Form $id is rendered");
            $this->assertGreaterThan($close, $at, "Form $id comes after the options form");
            $side_form = substr($html, $at, strpos($html, '</form>', $at) - $at);
            $this->assertStringContainsString('<input type="hidden" name="action" value="' . $action . '"', $side_form);
            $this->assertStringContainsString('value="' . wp_create_nonce($action) . '"', $side_form);
        }
    }

    public function test_cache_cleared_notice() {
        $this->assertStringNotContainsString('Cachen är tömd.', $this->render_settings_page());

        $_GET['aat_notice'] = 'cache_cleared';
        $html = $this->render_settings_page();

        $this->assertStringContainsString('<div class="notice notice-success is-dismissible"><p>Cachen är tömd.</p></div>', $html);
        $this->assertLessThan(strpos($html, 'aat-settings-strip'), strpos($html, 'Cachen är tömd.'));
    }

    public function test_settings_errors_print_above_the_strip() {
        $_GET['settings-updated'] = 'true';
        set_transient('settings_errors', [['setting' => 'general', 'code' => 'settings_updated', 'message' => 'Inställningarna sparades.', 'type' => 'success']], 30);

        $html = $this->render_settings_page();
        unset($_GET['settings-updated']);

        $this->assertStringContainsString('Inställningarna sparades.', $html);
        $this->assertLessThan(strpos($html, 'aat-settings-strip'), strpos($html, 'Inställningarna sparades.'));
        $this->assertLessThan(strpos($html, 'Inställningarna sparades.'), strpos($html, 'class="aat-settings"'));
    }

    public function test_active_section_falls_back_for_unknown_values() {
        $this->assertSame('anslutning', Auto_Alt_Text_Settings_Page::active_section());

        $_GET['section'] = 'nope';
        $this->assertSame('anslutning', Auto_Alt_Text_Settings_Page::active_section());

        $_GET['section'] = ['cache'];
        $this->assertSame('anslutning', Auto_Alt_Text_Settings_Page::active_section());

        $_GET['section'] = 'cache';
        $this->assertSame('cache', Auto_Alt_Text_Settings_Page::active_section());
        $this->assertStringContainsString('<div class="aat-settings" data-active-section="cache">', $this->render_settings_page());
    }

    public function test_render_makes_no_remote_call() {
        $this->skip_if_key_constant();
        $openai = new Auto_Alt_Text_OpenAI();
        $openai->save_api_key($this->key());
        update_option(Auto_Alt_Text_Key_Status::OPTION, [
            'state' => 'ok',
            'model' => Auto_Alt_Text_Models::current_id(),
            'at'    => time() - 2 * DAY_IN_SECONDS,
            'fp'    => $openai->key_fingerprint(),
        ], false);
        $requests = 0;
        $this->count_requests(200, $requests);

        $html = $this->render_settings_page();

        $this->assertSame(0, $requests);
        $this->assertStringContainsString('<p class="aat-key-status aat-key-status--unchecked" id="aat-key-status">Inte kontrollerad</p>', $html);
        $this->assertSame(['text' => 'Inte kontrollerad', 'error' => false], Auto_Alt_Text_Settings_Page::summaries()['anslutning']);
    }
}
