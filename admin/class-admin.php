<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class Auto_Alt_Text_Admin {
    private const SCRIPT_HANDLE = 'auto-alt-text-admin-js';
    private const SCRIPT_PATH = 'js/app.js';
    private const STYLE_HANDLE = 'auto_alt_text_css';

    private const MEDIA_LIBRARY_HANDLE = 'auto-alt-text-media-library';
    private const MEDIA_HANDLE = 'auto-alt-text-media';

    /** Script handles loaded as ES modules by module_tag(). */
    public const MODULE_HANDLES = [self::MEDIA_LIBRARY_HANDLE, self::MEDIA_HANDLE];

    /** Core screens that host the media modal or the Media Library. */
    private const MEDIA_SCREENS = ['upload.php', 'post.php', 'post-new.php'];

    /**
     * Whether the plugin's admin assets belong on this screen: the media
     * screens above plus the plugin's own pages.
     */
    public static function should_load_on($hook_suffix) {
        $hook_suffix = (string) $hook_suffix;
        return in_array($hook_suffix, self::MEDIA_SCREENS, true)
            || false !== strpos($hook_suffix, 'auto-alt-text');
    }

    /**
     * Constructor for the admin class.
    */
    public function __construct() {
        add_action('bulk_actions-upload', [$this, 'add_bulk_actions']);
        add_filter('handle_bulk_actions-upload', [$this, 'handle_bulk_actions'], 10, 3);
        add_filter('removable_query_args', [$this, 'add_removable_query_args']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAutoAltTextScript']);
        add_filter('script_loader_tag', [self::class, 'module_tag'], 10, 2);
        // The Generate button is rendered in every wp.media modal (site editor,
        // widgets, customizer, term screens...), not only on MEDIA_SCREENS.
        add_action('wp_enqueue_media', [$this, 'enqueue_media_modal_assets']);
    }

    /**
     * Adds a 'Generate Alt Text' bulk action to the media library.
     *
     * @param array $bulk_actions The existing bulk actions.
     * @return array The updated bulk actions array.
     */
    public function add_bulk_actions($bulk_actions) {
        $bulk_actions['generate_alt_text'] = Auto_Alt_Text_Coexistence::label(__('Generera alt-text', 'WP-Auto-Alt-Text'));
        return $bulk_actions;
    }

    /** The list-mode bulk action queues the selection on the server and returns to the same list view. */
    public function handle_bulk_actions($redirect_to, $doaction, $post_ids) {
        if ($doaction !== 'generate_alt_text') {
            return $redirect_to;
        }
        $url = remove_query_arg(['bulk_generate_alt_text', 'bulk_ids', 'processed', 'failed', 'aat_bulk'], $redirect_to);
        if (!current_user_can('upload_files')) {
            return $url;
        }
        $result = Auto_Alt_Text_Bulk::request(get_current_user_id(), (array) $post_ids);
        $flag = is_wp_error($result) ? preg_replace('/^aat_bulk_/', '', $result->get_error_code()) : 'queued';
        return add_query_arg('aat_bulk', $flag, $url);
    }

    /** One-time flag that core drops from the address bar. */
    public function add_removable_query_args($args) {
        return array_merge((array) $args, ['aat_bulk']);
    }

    /** Verifies the bulk controls' nonce and capability, runs the action and returns the URL to go back to. */
    public static function process_bulk_control(string $action): string {
        check_admin_referer('aat_bulk');
        if (!current_user_can('upload_files')) {
            wp_die(esc_html__('Du har inte behörighet att göra det här.', 'WP-Auto-Alt-Text'), '', ['response' => 403]);
        }
        if ('cancel' === $action) {
            Auto_Alt_Text_Bulk::cancel(get_current_user_id());
        } else {
            Auto_Alt_Text_Bulk::dismiss(get_current_user_id());
        }
        $back = wp_get_referer();
        return $back ? $back : admin_url('upload.php');
    }

    public static function handle_bulk_dismiss(): void {
        wp_safe_redirect(self::process_bulk_control('dismiss'));
        exit;
    }

    public static function handle_bulk_cancel(): void {
        wp_safe_redirect(self::process_bulk_control('cancel'));
        exit;
    }

    /**
     * admin_enqueue_scripts callback: loads the plugin's admin script and
     * styles on the media screens and the plugin's own pages.
     */
    public function enqueueAutoAltTextScript($hook_suffix = ''): void {
        if (!self::should_load_on($hook_suffix)) {
            return;
        }
        $this->enqueue_assets();
        if ('upload.php' === (string) $hook_suffix) {
            $this->enqueue_media_library_module();
            $this->enqueue_media_module();
        } elseif ('post.php' === (string) $hook_suffix && self::is_attachment_screen()) {
            // The attachment edit screen renders the Generate button without a media frame.
            $this->enqueue_media_module();
        }
    }

    private static function is_attachment_screen(): bool {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        return $screen && 'attachment' === $screen->post_type;
    }

    /**
     * Adds type="module" to the main <script> of a module handle. The tag can
     * also hold the handle's `before` inline script, which has to stay classic,
     * so only the element whose id is "{handle}-js" is changed.
     */
    public static function module_tag($tag, $handle): string {
        $tag = (string) $tag;
        if (!in_array($handle, self::MODULE_HANDLES, true)) {
            return $tag;
        }
        $id = $handle . '-js';
        return (string) preg_replace_callback('/<script\b[^>]*>/i', static function (array $m) use ($id): string {
            if (!preg_match('/\sid=([\'"])' . preg_quote($id, '/') . '\1/', $m[0])) {
                return $m[0];
            }
            $open = (string) preg_replace('/\s+type=([\'"])[^\'"]*\1/', '', $m[0]);
            return '<script type="module"' . substr($open, 7);
        }, $tag);
    }

    /** The Media Library's progress notice script and its JSON config (upload.php only). */
    private function enqueue_media_library_module(): void {
        if (wp_script_is(self::MEDIA_LIBRARY_HANDLE, 'enqueued')) {
            return;
        }
        wp_enqueue_script(
            self::MEDIA_LIBRARY_HANDLE,
            plugin_dir_url(__FILE__) . 'js/media-library.js',
            [],
            AUTO_ALT_TEXT_VERSION,
            true
        );
        $config = [
            'restUrl'     => rest_url('wp-auto-alt-text/v1/bulk'),
            'nonce'       => wp_create_nonce('wp_rest'),
            'pollSeconds' => 5,
            'i18n'        => Auto_Alt_Text_Bulk_Notice::strings(),
            'links'       => Auto_Alt_Text_Bulk_Notice::links(),
        ];
        wp_add_inline_script(self::MEDIA_LIBRARY_HANDLE, 'var aatMediaLibrary = ' . wp_json_encode($config) . ';', 'before');
    }

    /**
     * The Generate button, the preview dialog and the context line
     * (admin/js/media.js) with its JSON config: wherever attachment fields
     * appear, never on the plugin's own pages.
     */
    private function enqueue_media_module(): void {
        if (wp_script_is(self::MEDIA_HANDLE, 'enqueued')) {
            return;
        }
        wp_enqueue_script(
            self::MEDIA_HANDLE,
            plugin_dir_url(__FILE__) . 'js/media.js',
            [],
            AUTO_ALT_TEXT_VERSION,
            true
        );
        $config = [
            'ajaxUrl'              => admin_url('admin-ajax.php'),
            'nonce'                => wp_create_nonce('auto_alt_text_nonce'),
            'feedbackEnabled'      => (bool) get_option('auto_alt_text_enable_feedback', true),
            'brandTonalityEnabled' => Auto_Alt_Text_Prompt::NAMING_VISIBLE !== Auto_Alt_Text_Prompt::naming(),
            'i18n'                 => self::media_strings(),
        ];
        wp_add_inline_script(self::MEDIA_HANDLE, 'var aatMedia = ' . wp_json_encode($config) . ';', 'before');
    }

    /** Every string media.js shows. */
    public static function media_strings(): array {
        return [
            'title'           => __('Förhandsgranska alt-text', 'WP-Auto-Alt-Text'),
            'cached'          => __('(från cache)', 'WP-Auto-Alt-Text'),
            /* translators: %s: the product, page or post the alt text belongs to, or why none was found. */
            'context'         => __('Alt-textkontext: %s', 'WP-Auto-Alt-Text'),
            'text_label'      => __('Alt-text', 'WP-Auto-Alt-Text'),
            'feedback_legend' => __('Förbättra texten', 'WP-Auto-Alt-Text'),
            'feedback_note'   => __('Varje förbättring räknas som en ny begäran, med tillkommande kostnad.', 'WP-Auto-Alt-Text'),
            'feedback_types'  => [
                'more_descriptive'   => __('Mer beskrivande', 'WP-Auto-Alt-Text'),
                'more_concise'       => __('Mer kortfattad', 'WP-Auto-Alt-Text'),
                'more_accessible'    => __('Mer tillgänglig', 'WP-Auto-Alt-Text'),
                'better_seo'         => __('Sökmotorsanpassad', 'WP-Auto-Alt-Text'),
                'technical_accuracy' => __('Teknisk noggrannhet', 'WP-Auto-Alt-Text'),
                'brand_voice'        => __('Varumärkets ton', 'WP-Auto-Alt-Text'),
            ],
            'custom_label'    => __('Egen instruktion', 'WP-Auto-Alt-Text'),
            'custom_submit'   => __('Förbättra', 'WP-Auto-Alt-Text'),
            'custom_empty'    => __('Skriv vad som ska förbättras.', 'WP-Auto-Alt-Text'),
            'generating'      => __('Genererar alt-text …', 'WP-Auto-Alt-Text'),
            'improving'       => __('Genererar en ny text …', 'WP-Auto-Alt-Text'),
            'improved'        => __('En ny text är klar.', 'WP-Auto-Alt-Text'),
            'apply'           => __('Använd', 'WP-Auto-Alt-Text'),
            'saving'          => __('Sparar alt-texten …', 'WP-Auto-Alt-Text'),
            'saved'           => __('Alt-texten är sparad.', 'WP-Auto-Alt-Text'),
            'empty_text'      => __('Skriv en alt-text, eller välj Avbryt.', 'WP-Auto-Alt-Text'),
            'regenerate'      => __('Generera ny', 'WP-Auto-Alt-Text'),
            'cancel'          => __('Avbryt', 'WP-Auto-Alt-Text'),
            'generate_failed' => __('Det gick inte att generera alt-text.', 'WP-Auto-Alt-Text'),
            'apply_failed'    => __('Det gick inte att spara alt-texten.', 'WP-Auto-Alt-Text'),
            'improve_failed'  => __('Det gick inte att förbättra texten.', 'WP-Auto-Alt-Text'),
            'session_expired' => __('Sessionen har gått ut. Ladda om sidan och försök igen.', 'WP-Auto-Alt-Text'),
        ];
    }

    /**
     * wp_enqueue_media callback: any admin screen that opens a media modal
     * gets media.js, which drives the modal's Generate button, and app.js.
     * app.js's own body.upload-php check keeps the batch UI off those screens.
     */
    public function enqueue_media_modal_assets(): void {
        if (!is_admin()) {
            return;
        }
        $this->enqueue_assets();
        $this->enqueue_media_module();
    }

    /**
     * Enqueues the admin stylesheet and app.js (the block editor panel and
     * the bulk loops), localized with the AJAX URL and nonces. Media screens
     * reach this from both wp_enqueue_media and admin_enqueue_scripts; the
     * guard keeps the localized data from being printed twice.
     */
    private function enqueue_assets(): void {
        if (wp_script_is(self::SCRIPT_HANDLE, 'enqueued')) {
            return;
        }

        wp_enqueue_style(
            self::STYLE_HANDLE,
            plugin_dir_url(dirname(__FILE__)) . 'css/style.css',
            [],
            AUTO_ALT_TEXT_VERSION
        );

        $scriptUrl = plugin_dir_url(__FILE__) . self::SCRIPT_PATH;
        $dependencies = [];

        if ($this->is_block_editor_screen()) {
            $dependencies = [
                'wp-block-editor',
                'wp-components',
                'wp-element',
                'wp-hooks',
            ];
        }

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            $scriptUrl,
            $dependencies,
            AUTO_ALT_TEXT_VERSION,
            true
        );

        wp_script_add_data(self::SCRIPT_HANDLE, 'type', 'module');

        wp_localize_script(self::SCRIPT_HANDLE, 'autoAltTextData', [
            'ajaxurl' => admin_url('admin-ajax.php'),
			'actionNonce' => wp_create_nonce('auto_alt_text_nonce'),
        ]);
    }

    /**
     * Checks whether the current admin screen is using the block editor.
     *
     * @return bool True when the current screen is the block editor.
     */
    private function is_block_editor_screen(): bool {
        if (!function_exists('get_current_screen')) {
            return false;
        }

        $screen = get_current_screen();

        return $screen && method_exists($screen, 'is_block_editor') && $screen->is_block_editor();
    }
}
