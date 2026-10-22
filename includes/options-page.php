<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once plugin_dir_path(__FILE__) . 'config.php';
require_once plugin_dir_path(__FILE__) . 'class-format-checker.php';

add_action('admin_menu', 'auto_alt_text_menu');
add_action('admin_init', 'auto_alt_text_register_settings');

add_action('admin_enqueue_scripts', 'auto_alt_text_admin_styles');
add_action('admin_enqueue_scripts', ['Auto_Alt_Text_Library_Page', 'enqueue']);
add_filter('removable_query_args', 'auto_alt_text_removable_query_args');

/**
 * One-time flags that core drops from the address bar (the admin canonical URL).
 * The form's referer keeps them, so the settings page also checks for a save.
 */
function auto_alt_text_removable_query_args($args) {
    return array_merge((array) $args, ['aat_notice']);
}

/**
 * The settings page's stylesheet and admin/js/settings.js (tabs, live summary,
 * dirty guard), on that page only. The script is an ES module.
 */
function auto_alt_text_admin_styles($hook) {
    if ('toplevel_page_auto-alt-text' !== $hook) {
        return;
    }
    wp_enqueue_style(
        'auto-alt-text-admin',
        plugin_dir_url( dirname( __FILE__ ) ) . 'css/admin-style.css',
        [],
        AUTO_ALT_TEXT_VERSION
    );
    wp_enqueue_script(
        'auto-alt-text-settings',
        plugin_dir_url(dirname(__FILE__)) . 'admin/js/settings.js',
        [],
        AUTO_ALT_TEXT_VERSION,
        true
    );
    add_filter('script_loader_tag', 'auto_alt_text_settings_module_tag', 10, 2);
    // The live summary rebuilds the server's texts (Auto_Alt_Text_Settings_Page::summaries()) from these.
    wp_localize_script('auto-alt-text-settings', 'aatSettings', [
        'labels' => [
            'languages' => auto_alt_text_get_supported_languages(),
            'naming'    => Auto_Alt_Text_Settings_Page::naming_labels(),
            'policy'    => Auto_Alt_Text_Settings_Page::policy_labels(),
            'on'        => __('På', 'WP-Auto-Alt-Text'),
            'off'       => __('Av', 'WP-Auto-Alt-Text'),
            /* translators: %d: number of days */
            'day'       => _n('%d dag', '%d dagar', 1, 'WP-Auto-Alt-Text'),
            /* translators: %d: number of days */
            'days'      => _n('%d dag', '%d dagar', 2, 'WP-Auto-Alt-Text'),
        ],
        'i18n' => [
            'dirty' => __('Spara dina ändringar först – annars försvinner de.', 'WP-Auto-Alt-Text'),
        ],
    ]);
}

/** Marks the settings script as an ES module (as Auto_Alt_Text_Library_Page::module_tag()). */
function auto_alt_text_settings_module_tag($tag, $handle) {
    if ('auto-alt-text-settings' !== $handle) {
        return $tag;
    }
    $tag = preg_replace('/\s+type=([\'"])[^\'"]*\1/', '', (string) $tag);
    return (string) preg_replace('/<script\s/', '<script type="module" ', (string) $tag, 1);
}

/**
 * URL of the settings page, optionally at a section (anslutning, sprak, namn,
 * automatik, cache, feedback), with extra query args.
 */
function auto_alt_text_settings_url(string $section = '', array $args = []): string {
    $query = ['page' => 'auto-alt-text'];
    if ('' !== $section) {
        $query['section'] = $section;
    }
    return admin_url('admin.php?' . http_build_query($query + $args, '', '&'));
}

/**
 * Registers the Auto Alt Text options page in the WordPress admin menu.
 *
 * This function adds an options page for the Auto Alt Text plugin to the WordPress admin menu.
 * The options page is accessible to users with the 'manage_options' capability.
 */
function auto_alt_text_menu() {
    add_menu_page(
        'Auto Alt Text',          // Page title
        'Auto Alt Text',          // Menu title
        'manage_options',         // Capability
        'auto-alt-text',          // Menu slug
        'auto_alt_text_options',  // Function
        'dashicons-format-image'  // Icon
    );

    add_submenu_page(
        'auto-alt-text',         // Parent slug
        'Inställningar',              // Page title
        'Inställningar',              // Menu title
        'manage_options',        // Capability
        'auto-alt-text',         // Menu slug
        'auto_alt_text_options'  // Function
    );

    add_submenu_page(
        'auto-alt-text',         // Parent slug
        'Hela biblioteket',      // Page title
        'Hela biblioteket',      // Menu title
        'manage_options',        // Capability
        Auto_Alt_Text_Library_Page::SLUG, // Menu slug
        ['Auto_Alt_Text_Library_Page', 'render'] // Function
    );

    add_submenu_page(
        'auto-alt-text',         // Parent slug
        'Status',                // Page title
        'Status',                // Menu title
        'manage_options',        // Capability
        'auto-alt-text-status',  // Menu slug
        'auto_alt_text_render_status_page'  // Function
    );
}

/**
 * Returns an array of supported languages for the Auto Alt Text plugin.
 *
 * The array keys are the language codes, and the values are the corresponding language names.
 *
 * @return array An associative array of supported languages.
 */
function auto_alt_text_get_supported_languages() {
    return AUTO_ALT_TEXT_LANGUAGES;
}

/**
 * A model's label in the model select: name, estimated cost and the recommended marker.
 *
 * @param string $model_id Registry ID.
 * @return string Unescaped label, or '' for an unknown ID.
 */
function auto_alt_text_model_option_label($model_id) {
    $model = Auto_Alt_Text_Models::get($model_id);
    if (null === $model) {
        return '';
    }
    return sprintf(
        /* translators: 1: model name, 2: estimated USD per 1,000 images, 3: " (rekommenderad)" or empty */
        __('%1$s – ca %2$s USD per 1 000 bilder%3$s', 'WP-Auto-Alt-Text'),
        $model['label'],
        number_format_i18n(Auto_Alt_Text_Models::cost_per_1000($model_id), 2),
        $model['recommended'] ? __(' (rekommenderad)', 'WP-Auto-Alt-Text') : ''
    );
}

/**
 * Renders the settings page for administrators (Auto_Alt_Text_Settings_Page).
 */
function auto_alt_text_options() {
    if (!current_user_can('manage_options')) {
        wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'WP-Auto-Alt-Text' ) );
    }

    global $wp_settings_errors;
    $wp_settings_errors = array();
    Auto_Alt_Text_Settings_Page::render();
}

function auto_alt_text_render_status_page() {
    ?>
    <div class="card">
        <h2><?php esc_html_e('Image Format Support', 'WP-Auto-Alt-Text'); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e('Supported Formats', 'WP-Auto-Alt-Text'); ?></th>
                <td>
                    <ul class="aat-format-status">
                        <li>
                            <span class="aat-format-name">JPEG/JPG</span>
                            <span class="aat-status-badge supported">✓ Fully Supported</span>
                        </li>
                        <li>
                            <span class="aat-format-name">PNG</span>
                            <span class="aat-status-badge supported">✓ Fully Supported</span>
                        </li>
                        <li>
                            <span class="aat-format-name">WebP</span>
                            <span class="aat-status-badge supported">✓ Fully Supported</span>
                        </li>
                        <li>
                            <span class="aat-format-name">AVIF</span>
                            <div>
                            <span class="aat-status-badge partial">⚠ Auto-converts to JPEG for processing</span>
                            </div>
                        </li>
                    </ul>
                </td>
            </tr>
        </table>
    </div>
    <div class="card">
        <h2><?php esc_html_e('AVIF Support Status', 'WP-Auto-Alt-Text'); ?></h2>
        <?php
        $avif_status = Auto_Alt_Text_Format_Checker::get_avif_status();
        ?>
        <table class="form-table">
            <tr>
                <td>
                    <ul class="aat-format-status">
                        <li>
                            <span class="aat-format-name">Server Support</span>
                            <span class="aat-status-badge <?php echo esc_attr( $avif_status['server_support'] ? 'supported' : 'not-supported' ); ?>">
                                <?php echo esc_html( $avif_status['server_support'] ? '✓ Available' : '⚠ Not Available' ); ?>
                            </span>
                        </li>
                        <li>
                            <span class="aat-format-name">GD Library</span>
                            <span class="aat-status-badge <?php echo esc_attr( $avif_status['gd_support'] ? 'supported' : 'not-supported' ); ?>">
                                <?php echo esc_html( $avif_status['gd_support'] ? '✓ Supported' : '⚠ Not Supported' ); ?>
                            </span>
                        </li>
                        <li>
                            <span class="aat-format-name">WordPress</span>
                            <span class="aat-status-badge <?php echo esc_attr( $avif_status['wordpress_support'] ? 'supported' : 'not-supported' ); ?>">
                                <?php echo esc_html( $avif_status['wordpress_support'] ? '✓ Compatible' : '⚠ Not Compatible' ); ?>
                            </span>
                        </li>
                    </ul>
                </td>
            </tr>
        </table>
    </div>
    <?php
}

/**
 * Registers the settings for the Auto Alt Text plugin.
 *
 * This function registers two settings:
 * - 'auto_alt_text_api_key': The API key for the Auto Alt Text service.
 * - 'language': The language to use for the Auto Alt Text service.
 *
 * Both settings are sanitized using the 'auto_alt_text_sanitize' function.
 */
function auto_alt_text_register_settings() {
    register_setting(
        AUTO_ALT_TEXT_SETTINGS_GROUP,                 // Option group
        'auto_alt_text_api_key',        // Option name
        'auto_alt_text_sanitize'        // Sanitization callback
    );
    register_setting(
        AUTO_ALT_TEXT_SETTINGS_GROUP,
        'language',
        'auto_alt_text_sanitize'
    );
    register_setting(
        AUTO_ALT_TEXT_SETTINGS_GROUP,
        'aat_brand_name',
        'auto_alt_text_sanitize'
    );
    register_setting(
        AUTO_ALT_TEXT_SETTINGS_GROUP,
        'aat_brand_description',
        'auto_alt_text_sanitize'
    );
    register_setting(
        AUTO_ALT_TEXT_SETTINGS_GROUP,
        'aat_cache_expiration',
        [
            'type' => 'integer',
            'default' => 30,
            'sanitize_callback' => function($input) {
                $value = absint($input);
                return max(1, min(365, $value)); // Ensure value is between 1 and 365
            }
        ]
    );
    register_setting(
        AUTO_ALT_TEXT_SETTINGS_GROUP,
        'auto_alt_text_auto_generate',
        [
            'type' => 'boolean',
            'default' => true,
            'sanitize_callback' => 'rest_sanitize_boolean'
        ]
    );
    register_setting(
        AUTO_ALT_TEXT_SETTINGS_GROUP,
        'auto_alt_text_enable_feedback',
        [
            'type' => 'boolean',
            'default' => true,
            'sanitize_callback' => 'rest_sanitize_boolean'
        ]
    );
    register_setting(
        AUTO_ALT_TEXT_SETTINGS_GROUP,
        Auto_Alt_Text_Provenance::POLICY_OPTION,
        [
            'type' => 'string',
            'default' => Auto_Alt_Text_Provenance::POLICY_MISSING,
            'sanitize_callback' => ['Auto_Alt_Text_Provenance', 'sanitize_policy'],
        ]
    );
    register_setting(
        AUTO_ALT_TEXT_SETTINGS_GROUP,
        Auto_Alt_Text_Prompt::NAMING_OPTION,
        [
            'type' => 'string',
            'default' => Auto_Alt_Text_Prompt::NAMING_VISIBLE,
            'sanitize_callback' => ['Auto_Alt_Text_Prompt', 'sanitize_naming'],
        ]
    );
    register_setting(
        AUTO_ALT_TEXT_SETTINGS_GROUP,
        Auto_Alt_Text_Prompt::EXTRA_OPTION,
        [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => ['Auto_Alt_Text_Prompt', 'sanitize_extra'],
        ]
    );
    register_setting(
        AUTO_ALT_TEXT_SETTINGS_GROUP,
        Auto_Alt_Text_Models::OPTION,
        [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'auto_alt_text_sanitize_model',
        ]
    );
}

/**
 * Whether a string looks like an OpenAI secret key.
 */
function auto_alt_text_is_valid_api_key_format($key) {
    return (bool) preg_match('/^sk-(proj-)?[A-Za-z0-9_-]{80,}$/', (string) $key);
}

/**
 * Model setting: registry IDs only. Warns, but still saves, when the key
 * cannot use the effective model (the chosen one, or the registry default
 * when no explicit choice is saved; spec §6).
 */
function auto_alt_text_sanitize_model($value) {
    $value = Auto_Alt_Text_Models::sanitize($value);
    if (in_array('model_unavailable', wp_list_pluck(get_settings_errors(Auto_Alt_Text_Models::OPTION), 'code'), true)) {
        return $value;
    }
    $effective = Auto_Alt_Text_Models::resolve($value);
    if (
        Auto_Alt_Text_Key_Status::needs_check($effective)
        && Auto_Alt_Text_Key_Status::MODEL_UNAVAILABLE === Auto_Alt_Text_Key_Status::check($effective)
    ) {
        add_settings_error(
            Auto_Alt_Text_Models::OPTION,
            'model_unavailable',
            sprintf(
                /* translators: %s: model ID */
                esc_html__('Modellen %s är inte tillgänglig för den här API-nyckeln. Välj en annan modell eller byt nyckel, annars misslyckas genereringen.', 'WP-Auto-Alt-Text'),
                esc_html($effective)
            ),
            'warning'
        );
    }
    return $value;
}

/**
 * Sanitizes the input value for the Auto Alt Text API key setting.
 *
 * Validates the API key format to ensure it starts with "sk-" and is at least 32 characters long.
 * If the input is invalid, an error is added to the settings API and the current API key value is returned.
 * Otherwise, the input is sanitized using `sanitize_text_field()`.
 *
 * @param string $input The input value to be sanitized.
 * @return string The sanitized input value.
 */
function auto_alt_text_sanitize($input) {
    $option_name = current_filter();

    if ($option_name === 'sanitize_option_auto_alt_text_api_key') {
        add_settings_error(
            'auto_alt_text_settings',
            'settings_updated',
            esc_html__('Settings saved successfully.', 'WP-Auto-Alt-Text'),
            'updated'
        );
    }

    switch($option_name) {
        case 'sanitize_option_auto_alt_text_api_key':
            $trimmed_input = trim((string) $input);
            $current = get_option('auto_alt_text_api_key');

            // The field never echoes the key back, so empty means "keep it".
            if ('' === $trimmed_input) {
                return $current;
            }

            $openai = new Auto_Alt_Text_OpenAI();

            // On the first save update_option() falls through to add_option(),
            // which sanitizes again: the value is then already encrypted.
            if (auto_alt_text_is_valid_api_key_format($openai->decrypt_api_key($trimmed_input))) {
                return $trimmed_input;
            }

            if (!auto_alt_text_is_valid_api_key_format($trimmed_input)) {
                add_settings_error(
                    'auto_alt_text_api_key',
                    'invalid_api_key',
                    esc_html__('Invalid OpenAI API key format. The key must start with "sk-" or "sk-proj-" followed by at least 80 characters.', 'WP-Auto-Alt-Text')
                );
                return $current;
            }

            return $openai->encrypt_api_key($trimmed_input);

        case 'sanitize_option_' . AUTO_ALT_TEXT_LANGUAGE_OPTION:
            // 'language' is a generic option name: only validate values coming
            // from this plugin's settings form and leave other writers alone.
            if (!isset($_POST['option_page']) || AUTO_ALT_TEXT_SETTINGS_GROUP !== $_POST['option_page']) {
                return $input;
            }
            if (!is_string($input) || !array_key_exists($input, auto_alt_text_get_supported_languages())) {
                return 'en'; // Default to English if invalid
            }
            break;
    }
    return sanitize_text_field($input);
}
?>
