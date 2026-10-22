<?php
class Auto_Alt_Text_Generate_Button_Test extends WP_UnitTestCase {
    /** @var callable|null */
    private $gettext = null;

    public function setUp(): void {
        parent::setUp();
        update_option('auto_alt_text_api_key', 'sk-test');
    }

    public function tearDown(): void {
        delete_option('auto_alt_text_api_key');
        if ($this->gettext) {
            remove_filter('gettext', $this->gettext, 10);
        }
        parent::tearDown();
    }

    private function field(): array {
        $id = self::factory()->attachment->create(['post_mime_type' => 'image/jpeg', 'file' => 'button-test.jpg']);
        $fields = apply_filters('attachment_fields_to_edit', [], get_post($id));
        $this->assertArrayHasKey('generate_alt_text', $fields);
        return ['id' => $id] + $fields['generate_alt_text'];
    }

    public function test_button_is_type_button_without_div_or_id_and_escaped() {
        $this->gettext = static function ($translation, $text) {
            return 'Generera alt-text' === $text ? 'Generera <b>"alt"</b>' : $translation;
        };
        add_filter('gettext', $this->gettext, 10, 2);

        $field = $this->field();
        $html = $field['html'];

        $this->assertSame('html', $field['input']);
        $this->assertSame('Alt-text med AI', $field['label']);
        $this->assertSame(
            '<button type="button" class="button aat-generate" data-attachment-id="' . $field['id'] . '">Generera &lt;b&gt;&quot;alt&quot;&lt;/b&gt;</button>'
            . '<span class="spinner aat-generate-spinner"></span>',
            $html
        );
        $this->assertStringNotContainsString('<div', $html, 'The spinner is a sibling, not a div inside the button');
        $this->assertStringNotContainsString(' id=', $html);
        $this->assertStringNotContainsString('data-nonce', $html, 'The nonce comes from the config');
    }

    public function test_labels_carry_the_plugin_name_next_to_the_ai_feature() {
        add_filter('aat_ai_plugin_loaded', '__return_true');
        update_option('wpai_features_enabled', 1);
        update_option('wpai_feature_alt-text-generation_enabled', 1);
        try {
            $field = $this->field();
            $this->assertSame('Alt-text med AI (WP Auto Alt Text)', $field['label']);
            $this->assertStringContainsString('>Generera alt-text (WP Auto Alt Text)</button>', $field['html']);
        } finally {
            remove_all_filters('aat_ai_plugin_loaded');
            delete_option('wpai_features_enabled');
            delete_option('wpai_feature_alt-text-generation_enabled');
        }
    }

    public function test_no_key_notice_is_swedish_and_links_to_settings() {
        delete_option('auto_alt_text_api_key');

        $field = $this->field();
        $html = $field['html'];

        $this->assertSame('Alt-text med AI', $field['label']);
        $this->assertStringContainsString('Ange en API-nyckel i inställningarna för att generera alt-text.', $html);
        $this->assertStringContainsString('<a href="' . esc_url(admin_url('admin.php?page=auto-alt-text')) . '">Öppna inställningarna</a>', $html);
        $this->assertStringNotContainsString('aat-generate', $html, 'No button without a key');
        $this->assertStringNotContainsString('OpenAI API key', $html);
    }
}
