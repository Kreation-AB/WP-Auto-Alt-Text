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
        wp_dequeue_script('auto-alt-text-media');
        wp_deregister_script('auto-alt-text-media');
        // Inline data accumulates on the registered handle; start each test clean.
        wp_dequeue_script('aat-block-editor');
        wp_deregister_script('aat-block-editor');
        wp_dequeue_style('auto_alt_text_css');
        wp_deregister_style('auto_alt_text_css');
        delete_option('wpai_features_enabled');
        delete_option('wpai_feature_alt-text-generation_enabled');
        remove_filter('aat_ai_plugin_loaded', '__return_true');
        wp_set_current_user(0);
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
        $this->assertFalse(wp_script_is('auto-alt-text-media', 'enqueued'));
        $this->assertFalse(wp_style_is('auto_alt_text_css', 'enqueued'));
    }

    public function test_media_library_enqueues_versioned_assets() {
        do_action('admin_enqueue_scripts', 'upload.php');
        $this->assertTrue(wp_style_is('auto_alt_text_css', 'enqueued'));
        $this->assertSame(AUTO_ALT_TEXT_VERSION, wp_styles()->registered['auto_alt_text_css']->ver);
    }

    public function test_media_modal_outside_admin_enqueues_nothing() {
        set_current_screen('front');
        do_action('wp_enqueue_media');
        $this->assertFalse(wp_script_is('auto-alt-text-media', 'enqueued'));
        $this->assertFalse(wp_style_is('auto_alt_text_css', 'enqueued'));
    }

    public function test_style_css_still_loads_on_plugin_pages_and_media_screens() {
        // [screen, hook suffix]: the media screens and the plugin's own pages (spec §5.6).
        $screens = [
            ['upload', 'upload.php'],
            ['post', 'post.php'],
            ['post', 'post-new.php'],
            ['attachment', 'post.php'],
            ['toplevel_page_auto-alt-text', 'toplevel_page_auto-alt-text'],
            ['auto-alt-text_page_auto-alt-text-library', 'auto-alt-text_page_auto-alt-text-library'],
            ['auto-alt-text_page_auto-alt-text-stats', 'auto-alt-text_page_auto-alt-text-stats'],
        ];
        foreach ($screens as [$screen, $hook]) {
            wp_dequeue_style('auto_alt_text_css');
            set_current_screen($screen);
            do_action('admin_enqueue_scripts', $hook);
            $this->assertTrue(wp_style_is('auto_alt_text_css', 'enqueued'), $screen . ' / ' . $hook);
        }

        // Any other admin screen that opens a media frame (site editor, widgets, customizer).
        wp_dequeue_style('auto_alt_text_css');
        set_current_screen('site-editor');
        do_action('wp_enqueue_media');
        $this->assertTrue(wp_style_is('auto_alt_text_css', 'enqueued'), 'wp_enqueue_media');

        wp_dequeue_style('auto_alt_text_css');
        set_current_screen('dashboard');
        do_action('admin_enqueue_scripts', 'index.php');
        $this->assertFalse(wp_style_is('auto_alt_text_css', 'enqueued'), 'index.php');
    }

    /** The before-inline config of the block editor script, decoded. */
    private function block_editor_config(): array {
        $before = implode("\n", array_filter((array) wp_scripts()->get_data('aat-block-editor', 'before')));
        $this->assertSame(1, preg_match('/^var aatBlockEditor = (\{.*\});$/s', $before, $m), $before);
        $cfg = json_decode($m[1], true);
        $this->assertIsArray($cfg);
        return $cfg;
    }

    public function test_block_editor_script_is_classic_with_its_deps_on_enqueue_block_editor_assets() {
        wp_set_current_user(self::factory()->user->create(['role' => 'author']));

        // The post screen alone does not load it: only editors that fire enqueue_block_editor_assets.
        set_current_screen('post');
        do_action('admin_enqueue_scripts', 'post.php');
        $this->assertFalse(wp_script_is('aat-block-editor', 'registered'));

        do_action('enqueue_block_editor_assets');
        do_action('enqueue_block_editor_assets');
        $this->assertTrue(wp_script_is('aat-block-editor', 'enqueued'));
        $script = wp_scripts()->registered['aat-block-editor'];
        $this->assertStringEndsWith('/admin/js/block-editor.js', $script->src);
        $this->assertSame(AUTO_ALT_TEXT_VERSION, $script->ver);
        $this->assertSame(['wp-hooks', 'wp-compose', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-notices'], $script->deps);
        $this->assertSame(1, wp_scripts()->get_data('aat-block-editor', 'group'), 'In the footer, after its deps');

        // A classic script: not a module handle, and its tag is left alone.
        $this->assertNotContains('aat-block-editor', Auto_Alt_Text_Admin::MODULE_HANDLES);
        $this->assertFalse(wp_scripts()->get_data('aat-block-editor', 'type'));
        $tag = "<script id='aat-block-editor-js-before'>\nvar aatBlockEditor = {};\n</script>\n<script src='b.js' id='aat-block-editor-js'></script>\n";
        $this->assertSame($tag, apply_filters('script_loader_tag', $tag, 'aat-block-editor', 'b.js'));

        // The config is JSON, printed once although the hook fired twice.
        $this->assertSame(1, substr_count(implode("\n", (array) wp_scripts()->get_data('aat-block-editor', 'before')), 'var aatBlockEditor'));
        $cfg = $this->block_editor_config();
        $this->assertSame(['ajaxUrl', 'nonce', 'i18n'], array_keys($cfg));
        $this->assertSame(admin_url('admin-ajax.php'), $cfg['ajaxUrl']);
        $this->assertSame(1, wp_verify_nonce($cfg['nonce'], 'auto_alt_text_nonce') ? 1 : 0);
        $this->assertSame(Auto_Alt_Text_Admin::block_editor_strings(), $cfg['i18n']);
        $this->assertSame('Alt-text med AI', $cfg['i18n']['panel']);
        $this->assertSame('Generera alt-text', $cfg['i18n']['generate']);
        $this->assertSame('Förhandsgranska alt-text', $cfg['i18n']['title']);
        $this->assertSame('Alt-text', $cfg['i18n']['text_label']);
        $this->assertSame('Använd', $cfg['i18n']['apply']);
        $this->assertSame('Avbryt', $cfg['i18n']['cancel']);
        $this->assertSame('Alt-texten är sparad på bilden i mediebiblioteket och i blocket.', $cfg['i18n']['saved']);
    }

    public function test_block_editor_panel_title_names_the_plugin_next_to_the_ai_plugin() {
        add_filter('aat_ai_plugin_loaded', '__return_true');
        update_option('wpai_features_enabled', 1);
        update_option('wpai_feature_alt-text-generation_enabled', 1);
        $strings = Auto_Alt_Text_Admin::block_editor_strings();
        $this->assertSame('Alt-text med AI (WP Auto Alt Text)', $strings['panel']);
        $this->assertSame('Generera alt-text', $strings['generate'], 'The panel title already says whose button it is');
    }

    public function test_block_editor_script_needs_upload_files() {
        // The AJAX endpoints refuse users without upload_files, so the panel would only fail.
        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));
        do_action('enqueue_block_editor_assets');
        $this->assertFalse(wp_script_is('aat-block-editor', 'registered'));
    }

    public function test_app_js_handle_is_gone() {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        // Every screen and hook that loaded the old admin script.
        set_current_screen('upload');
        do_action('wp_enqueue_media');
        do_action('admin_enqueue_scripts', 'upload.php');
        set_current_screen('post');
        do_action('wp_enqueue_media');
        do_action('admin_enqueue_scripts', 'post-new.php');
        do_action('enqueue_block_editor_assets');
        set_current_screen('toplevel_page_auto-alt-text');
        do_action('admin_enqueue_scripts', 'toplevel_page_auto-alt-text');

        // The plugin's registered scripts are the rebuilt ones only.
        $admin_url = plugin_dir_url(dirname(__DIR__) . '/admin/class-admin.php');
        $ours = [];
        foreach (wp_scripts()->registered as $handle => $script) {
            if (is_string($script->src) && 0 === strpos($script->src, $admin_url)) {
                $ours[] = $handle;
            }
        }
        $this->assertContains('aat-block-editor', $ours);
        $this->assertSame([], array_values(array_diff($ours, ['auto-alt-text-media-library', 'auto-alt-text-media', 'aat-block-editor', 'auto-alt-text-settings', 'auto-alt-text-library'])));

        // admin/js holds only those scripts.
        $files = array_map('basename', (array) glob(dirname(__DIR__) . '/admin/js/*.js'));
        $this->assertSame([], array_values(array_diff($files, ['block-editor.js', 'library.js', 'media-library.js', 'media.js', 'settings.js'])), 'admin/js holds only the scripts the plugin enqueues');
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

    public function test_stylesheets_only_define_aat_prefixed_plugin_classes() {
        $css = file_get_contents(dirname(__DIR__) . '/css/style.css');
        $this->assertSame(0, preg_match('/^[.#](?!aat-)[A-Za-z]/m', $css), 'Every top-level selector in style.css starts with aat-');
        foreach (['status-badge', 'generation-type', 'edited-text', 'diff-view', 'format-status', 'log-level-', 'tooltip', 'char-count', 'alt-text-preview'] as $old) {
            $this->assertDoesNotMatchRegularExpression('/(?<!aat-)' . preg_quote($old, '/') . '/', $css, $old);
        }
        foreach (['aat-status-badge', 'aat-generation-type', 'aat-edited-text', 'aat-diff-view', 'aat-diff-label', 'aat-format-status', 'aat-format-name', 'aat-log-level-error'] as $new) {
            $this->assertStringContainsString('.' . $new, $css);
        }
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

    private function reset_media_handle(): void {
        wp_dequeue_script('auto-alt-text-media');
        wp_deregister_script('auto-alt-text-media');
        wp_dequeue_style('auto_alt_text_css');
    }

    /** The aatMedia config the page gets, decoded from the 'before' inline script. */
    private function media_config(): array {
        $before = implode("\n", array_filter((array) wp_scripts()->get_data('auto-alt-text-media', 'before')));
        $this->assertSame(1, preg_match('/^var aatMedia = (\{.*\});$/s', $before, $m), $before);
        $cfg = json_decode($m[1], true);
        $this->assertIsArray($cfg);
        return $cfg;
    }

    public function test_media_js_loads_with_wp_enqueue_media_and_on_attachment_screens() {
        // Any admin screen with a media frame.
        set_current_screen('site-editor');
        do_action('wp_enqueue_media');
        $this->assertTrue(wp_script_is('auto-alt-text-media', 'enqueued'));
        $script = wp_scripts()->registered['auto-alt-text-media'];
        $this->assertStringEndsWith('/admin/js/media.js', $script->src);
        $this->assertSame(AUTO_ALT_TEXT_VERSION, $script->ver);
        $this->assertContains('auto-alt-text-media', Auto_Alt_Text_Admin::MODULE_HANDLES);
        $tag = apply_filters('script_loader_tag', "<script src='m.js' id='auto-alt-text-media-js'></script>\n", 'auto-alt-text-media', 'm.js');
        $this->assertSame("<script type=\"module\" src='m.js' id='auto-alt-text-media-js'></script>\n", $tag);

        // Both hooks on one screen print the config once.
        $this->reset_media_handle();
        set_current_screen('upload');
        do_action('wp_enqueue_media');
        do_action('admin_enqueue_scripts', 'upload.php');
        $this->assertTrue(wp_script_is('auto-alt-text-media', 'enqueued'));
        $this->assertSame(1, substr_count(implode("\n", (array) wp_scripts()->get_data('auto-alt-text-media', 'before')), 'var aatMedia'));

        // The Media Library in list mode never calls wp_enqueue_media().
        $this->reset_media_handle();
        do_action('admin_enqueue_scripts', 'upload.php');
        $this->assertTrue(wp_script_is('auto-alt-text-media', 'enqueued'));

        // The attachment edit screen renders the button without a media frame.
        $this->reset_media_handle();
        set_current_screen('attachment');
        do_action('admin_enqueue_scripts', 'post.php');
        $this->assertTrue(wp_script_is('auto-alt-text-media', 'enqueued'));

        // A post edit screen without a media frame does not get it.
        $this->reset_media_handle();
        set_current_screen('edit-post');
        set_current_screen('post');
        do_action('admin_enqueue_scripts', 'post.php');
        $this->assertFalse(wp_script_is('auto-alt-text-media', 'enqueued'));
        $this->assertTrue(wp_style_is('auto_alt_text_css', 'enqueued'), 'style.css keeps loading there');

        // Nor does the front end.
        $this->reset_media_handle();
        set_current_screen('front');
        do_action('wp_enqueue_media');
        $this->assertFalse(wp_script_is('auto-alt-text-media', 'enqueued'));
    }

    public function test_media_js_does_not_load_on_the_plugins_own_pages() {
        foreach (['toplevel_page_auto-alt-text', 'auto-alt-text_page_auto-alt-text-library'] as $hook) {
            $this->reset_media_handle();
            wp_dequeue_style('auto_alt_text_css');
            do_action('admin_enqueue_scripts', $hook);
            $this->assertFalse(wp_script_is('auto-alt-text-media', 'enqueued'), $hook);
            $this->assertTrue(wp_style_is('auto_alt_text_css', 'enqueued'), $hook . ' keeps style.css (spec §5.6)');
        }
    }

    public function test_media_config_booleans_are_real() {
        $config = function (): array {
            $this->reset_media_handle();
            set_current_screen('upload');
            do_action('wp_enqueue_media');
            return $this->media_config();
        };

        update_option('auto_alt_text_enable_feedback', true);
        update_option(Auto_Alt_Text_Prompt::NAMING_OPTION, Auto_Alt_Text_Prompt::NAMING_VISIBLE);
        $cfg = $config();
        $this->assertSame(admin_url('admin-ajax.php'), $cfg['ajaxUrl']);
        $this->assertSame(1, wp_verify_nonce($cfg['nonce'], 'auto_alt_text_nonce') ? 1 : 0);
        $this->assertTrue($cfg['feedbackEnabled']);
        $this->assertFalse($cfg['brandTonalityEnabled']);
        $this->assertSame(Auto_Alt_Text_Admin::media_strings(), $cfg['i18n']);
        $this->assertSame('Förhandsgranska alt-text', $cfg['i18n']['title']);

        update_option('auto_alt_text_enable_feedback', false);
        update_option(Auto_Alt_Text_Prompt::NAMING_OPTION, Auto_Alt_Text_Prompt::NAMING_PRODUCT_BRAND);
        $cfg = $config();
        $this->assertFalse($cfg['feedbackEnabled']);
        $this->assertTrue($cfg['brandTonalityEnabled']);

        // The raw JSON carries real booleans, not "1" or "".
        $before = implode("\n", (array) wp_scripts()->get_data('auto-alt-text-media', 'before'));
        $this->assertStringContainsString('"feedbackEnabled":false', $before);
        $this->assertStringContainsString('"brandTonalityEnabled":true', $before);
        delete_option('auto_alt_text_enable_feedback');
        delete_option(Auto_Alt_Text_Prompt::NAMING_OPTION);
    }
}
