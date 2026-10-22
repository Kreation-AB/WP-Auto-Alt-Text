<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Labels that stay short, unless the AI plugin's own alt-text feature could be confused with ours. */
class Auto_Alt_Text_Coexistence {
    /** Whether the WordPress AI plugin's alt-text generation is on. */
    public static function ai_alt_text_active(): bool {
        return (bool) apply_filters('aat_ai_plugin_loaded', defined('WPAI_VERSION'))
            && (bool) get_option('wpai_features_enabled')
            && (bool) apply_filters('wpai_feature_alt-text-generation_enabled', (bool) get_option('wpai_feature_alt-text-generation_enabled', false));
    }

    public static function label(string $label): string {
        return self::ai_alt_text_active() ? $label . ' (WP Auto Alt Text)' : $label;
    }
}
