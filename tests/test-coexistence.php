<?php
class Auto_Alt_Text_Coexistence_Test extends WP_UnitTestCase {
    public function tearDown(): void {
        delete_option('wpai_features_enabled');
        delete_option('wpai_feature_alt-text-generation_enabled');
        parent::tearDown();
    }

    private function activate_ai_feature(): void {
        add_filter('aat_ai_plugin_loaded', '__return_true');
        update_option('wpai_features_enabled', 1);
        update_option('wpai_feature_alt-text-generation_enabled', 1);
    }

    public function test_labels_are_short_without_the_ai_feature() {
        $this->assertFalse(Auto_Alt_Text_Coexistence::ai_alt_text_active());
        $this->assertSame('Generera alt-text', Auto_Alt_Text_Coexistence::label('Generera alt-text'));
    }

    public function test_labels_carry_the_plugin_name_when_the_ai_feature_is_active() {
        $this->activate_ai_feature();
        $this->assertTrue(Auto_Alt_Text_Coexistence::ai_alt_text_active());
        $this->assertSame('Foo (WP Auto Alt Text)', Auto_Alt_Text_Coexistence::label('Foo'));
    }

    public function test_the_feature_needs_all_three_conditions() {
        add_filter('aat_ai_plugin_loaded', '__return_true');
        update_option('wpai_features_enabled', 1);
        $this->assertFalse(Auto_Alt_Text_Coexistence::ai_alt_text_active());
    }

    public function test_bulk_action_label_is_swedish_and_suffixed() {
        $admin = new Auto_Alt_Text_Admin();
        $this->assertSame('Generera alt-text', $admin->add_bulk_actions([])['generate_alt_text']);
        $this->activate_ai_feature();
        $this->assertSame('Generera alt-text (WP Auto Alt Text)', $admin->add_bulk_actions([])['generate_alt_text']);
    }
}
