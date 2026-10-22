<?php
class Auto_Alt_Text_Admin_Assets_Test extends WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        // WooCommerce's admin_enqueue_scripts listener reads get_current_screen()->id.
        set_current_screen('dashboard');
    }

    public function tearDown(): void {
        wp_dequeue_script('auto-alt-text-settings');
        wp_deregister_script('auto-alt-text-settings');
        remove_filter('script_loader_tag', 'auto_alt_text_settings_module_tag', 10);
        wp_dequeue_script('auto-alt-text-media-library');
        wp_deregister_script('auto-alt-text-media-library');
        wp_dequeue_script('auto-alt-text-admin-js');
        wp_dequeue_style('auto_alt_text_css');
        // Localized data accumulates on the registered handle; start each test clean.
        wp_deregister_script('auto-alt-text-admin-js');
        wp_deregister_style('auto_alt_text_css');
        parent::tearDown();
    }

    public function test_assets_load_only_on_media_editor_and_plugin_screens() {
        foreach (['upload.php', 'post.php', 'post-new.php', 'toplevel_page_auto-alt-text', 'auto-alt-text_page_auto-alt-text-stats'] as $hook) {
            $this->assertTrue(Auto_Alt_Text_Admin::should_load_on($hook), $hook);
        }
        foreach (['index.php', 'edit.php', 'plugins.php', 'woocommerce_page_wc-settings', 'woocommerce_page_wc-orders'] as $hook) {
            $this->assertFalse(Auto_Alt_Text_Admin::should_load_on($hook), $hook);
        }
    }

    public function test_dashboard_does_not_enqueue_plugin_assets() {
        do_action('admin_enqueue_scripts', 'index.php');
        $this->assertFalse(wp_script_is('auto-alt-text-admin-js', 'enqueued'));
        $this->assertFalse(wp_style_is('auto_alt_text_css', 'enqueued'));
    }

    public function test_media_library_enqueues_versioned_assets() {
        do_action('admin_enqueue_scripts', 'upload.php');
        $this->assertTrue(wp_script_is('auto-alt-text-admin-js', 'enqueued'));
        $this->assertSame(AUTO_ALT_TEXT_VERSION, wp_scripts()->registered['auto-alt-text-admin-js']->ver);
    }

    public function test_media_modal_on_other_admin_screens_enqueues_assets() {
        // attachment_fields_to_edit renders the Generate button in every wp.media
        // modal, e.g. the site editor, widgets and customizer.
        set_current_screen('site-editor');
        do_action('wp_enqueue_media');
        $this->assertTrue(wp_script_is('auto-alt-text-admin-js', 'enqueued'));
        $this->assertTrue(wp_style_is('auto_alt_text_css', 'enqueued'));
        $this->assertStringContainsString('autoAltTextData', (string) wp_scripts()->get_data('auto-alt-text-admin-js', 'data'));
    }

    public function test_media_modal_assets_are_localized_once_when_both_hooks_fire() {
        // upload.php and the editors call wp_enqueue_media() and then fire admin_enqueue_scripts.
        set_current_screen('upload');
        do_action('wp_enqueue_media');
        do_action('admin_enqueue_scripts', 'upload.php');
        $this->assertSame(1, substr_count((string) wp_scripts()->get_data('auto-alt-text-admin-js', 'data'), 'var autoAltTextData'));
    }

    public function test_media_modal_assets_localize_the_feedback_switch() {
        // Read the JS the page gets, not the PHP array.
        $localized = static function (): string {
            wp_dequeue_script('auto-alt-text-admin-js');
            wp_deregister_script('auto-alt-text-admin-js');
            set_current_screen('upload');
            do_action('wp_enqueue_media');
            $scripts = wp_scripts();
            return $scripts->get_data('auto-alt-text-admin-js', 'data') . implode("\n", (array) $scripts->get_data('auto-alt-text-admin-js', 'before'));
        };

        update_option('auto_alt_text_enable_feedback', true);
        $this->assertMatchesRegularExpression('/feedbackEnabled = true;/', $localized());

        update_option('auto_alt_text_enable_feedback', false);
        $this->assertMatchesRegularExpression('/feedbackEnabled = false;/', $localized());
    }

    public function test_media_modal_outside_admin_enqueues_nothing() {
        set_current_screen('front');
        do_action('wp_enqueue_media');
        $this->assertFalse(wp_script_is('auto-alt-text-admin-js', 'enqueued'));
        $this->assertFalse(wp_style_is('auto_alt_text_css', 'enqueued'));
    }

    public function test_settings_page_loads_the_tabs_script_as_a_module_with_its_labels() {
        do_action('admin_enqueue_scripts', 'upload.php');
        $this->assertFalse(wp_script_is('auto-alt-text-settings', 'enqueued'), 'Only on the settings page');

        do_action('admin_enqueue_scripts', 'toplevel_page_auto-alt-text');
        $this->assertTrue(wp_script_is('auto-alt-text-settings', 'enqueued'));
        $script = wp_scripts()->registered['auto-alt-text-settings'];
        $this->assertStringEndsWith('/admin/js/settings.js', $script->src);
        $this->assertSame(AUTO_ALT_TEXT_VERSION, $script->ver);

        $tag = apply_filters('script_loader_tag', "<script type='text/javascript' src='s.js' id='auto-alt-text-settings-js'></script>\n", 'auto-alt-text-settings', 's.js');
        $this->assertSame("<script type=\"module\" src='s.js' id='auto-alt-text-settings-js'></script>\n", $tag);
        $other = "<script src='o.js' id='other-js'></script>\n";
        $this->assertSame($other, apply_filters('script_loader_tag', $other, 'other', 'o.js'));

        $data = (string) wp_scripts()->get_data('auto-alt-text-settings', 'data');
        $this->assertSame(1, preg_match('/^var aatSettings = (\{.*\});$/s', $data, $match));
        $settings = json_decode($match[1], true);
        $this->assertSame(AUTO_ALT_TEXT_LANGUAGES, $settings['labels']['languages']);
        $this->assertSame(Auto_Alt_Text_Settings_Page::naming_labels(), $settings['labels']['naming']);
        $this->assertSame(Auto_Alt_Text_Settings_Page::policy_labels(), $settings['labels']['policy']);
        $this->assertSame('På', $settings['labels']['on']);
        $this->assertSame('Av', $settings['labels']['off']);
        $this->assertSame('%d dag', $settings['labels']['day']);
        $this->assertSame('%d dagar', $settings['labels']['days']);
        $this->assertSame('Spara dina ändringar först – annars försvinner de.', $settings['i18n']['dirty']);
    }

    public function test_list_table_pagination_rule_is_scoped_to_the_stats_page() {
        $css = file_get_contents(dirname(__DIR__) . '/css/style.css');
        $this->assertDoesNotMatchRegularExpression('/(^|\})\s*\.tablenav \.tablenav-pages\s*\{/m', $css);
        $this->assertStringContainsString('.aat-stats-page .tablenav .tablenav-pages', $css);
        $this->assertStringNotContainsString('disabled-field', $css, 'Nothing renders that class any more');
    }

    public function test_media_library_module_loads_on_upload_only() {
        do_action('admin_enqueue_scripts', 'post.php');
        $this->assertFalse(wp_script_is('auto-alt-text-media-library', 'enqueued'));
        do_action('admin_enqueue_scripts', 'upload.php');
        $this->assertTrue(wp_script_is('auto-alt-text-media-library', 'enqueued'));
        $this->assertSame(AUTO_ALT_TEXT_VERSION, wp_scripts()->registered['auto-alt-text-media-library']->ver);
    }

    public function test_module_tag_targets_the_handles_own_script_not_its_before_inline_script() {
        $before = "<script id='auto-alt-text-media-library-js-before'>\nvar aatMediaLibrary = {};\n</script>\n";
        $main = "<script src='https://example.test/media-library.js?ver=2' id='auto-alt-text-media-library-js'></script>\n";
        $out = Auto_Alt_Text_Admin::module_tag($before . $main, 'auto-alt-text-media-library');
        $this->assertSame(1, substr_count($out, 'type="module"'));
        $this->assertMatchesRegularExpression('/<script type="module"[^>]*id=\'auto-alt-text-media-library-js\'/', $out);
        $this->assertStringStartsWith("<script id='auto-alt-text-media-library-js-before'>", $out);
        $this->assertSame($main, Auto_Alt_Text_Admin::module_tag($main, 'other-handle'));
    }

    public function test_media_library_config_is_json_with_real_values() {
        do_action('admin_enqueue_scripts', 'upload.php');
        $before = implode("\n", array_filter((array) wp_scripts()->get_data('auto-alt-text-media-library', 'before')));
        $this->assertSame(1, preg_match('/aatMediaLibrary = (\{.*\});/s', $before, $m));
        $cfg = json_decode($m[1], true);
        $this->assertIsArray($cfg);
        $this->assertSame(rest_url('wp-auto-alt-text/v1/bulk'), $cfg['restUrl']);
        $this->assertSame(5, $cfg['pollSeconds']);
        $this->assertNotEmpty($cfg['nonce']);
        $this->assertSame(1, wp_verify_nonce($cfg['nonce'], 'wp_rest') ? 1 : 0);
        $this->assertSame(Auto_Alt_Text_Bulk_Notice::strings(), $cfg['i18n']);
        foreach (['dismiss', 'cancel'] as $key) {
            $this->assertStringContainsString('&_wpnonce=', $cfg['links'][$key]);
            $this->assertStringNotContainsString('&amp;', $cfg['links'][$key]);
        }
    }
}
