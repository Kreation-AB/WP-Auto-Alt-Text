<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class Auto_Alt_Text_Admin {
    private const STYLE_HANDLE = 'auto_alt_text_css';

    private const MEDIA_LIBRARY_HANDLE = 'auto-alt-text-media-library';
    private const MEDIA_HANDLE = 'auto-alt-text-media';
    private const BLOCK_EDITOR_HANDLE = 'aat-block-editor';
    private const BLOCK_EDITOR_DEPS = ['wp-hooks', 'wp-compose', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-notices'];

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
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_block_editor_script']);
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

    /**
     * The list-mode bulk action queues the selection on the server and returns
     * to the same list view: `aat_bulk=queued` when anything is tracked, and
     * the files that could not be queued, per reason, in `aat_bulk_rejected`.
     */
    public function handle_bulk_actions($redirect_to, $doaction, $post_ids) {
        if ($doaction !== 'generate_alt_text') {
            return $redirect_to;
        }
        $url = remove_query_arg(['bulk_generate_alt_text', 'bulk_ids', 'processed', 'failed', 'aat_bulk', Auto_Alt_Text_Bulk_Notice::REJECTED_PARAM], $redirect_to);
        if (!current_user_can('upload_files')) {
            return $url;
        }
        $result = Auto_Alt_Text_Bulk::request(get_current_user_id(), (array) $post_ids);
        if (is_wp_error($result)) {
            return add_query_arg('aat_bulk', preg_replace('/^aat_bulk_/', '', $result->get_error_code()), $url);
        }
        $rejected = array_sum($result['rejected']);
        $args = [];
        if ($result['queued'] + $result['already_queued'] > 0 || 0 === $rejected) {
            $args['aat_bulk'] = 'queued';
        }
        if ($rejected > 0) {
            $args[Auto_Alt_Text_Bulk_Notice::REJECTED_PARAM] = Auto_Alt_Text_Bulk_Notice::encode_rejected($result['rejected']);
        }
        return add_query_arg($args, $url);
    }

    /** One-time flags that core drops from the address bar. */
    public function add_removable_query_args($args) {
        return array_merge((array) $args, ['aat_bulk', Auto_Alt_Text_Bulk_Notice::REJECTED_PARAM]);
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
     * admin_enqueue_scripts callback: the admin stylesheet on the media
     * screens and the plugin's own pages, and the media scripts where the
     * attachment fields appear.
     */
    public function enqueueAutoAltTextScript($hook_suffix = ''): void {
        if (!self::should_load_on($hook_suffix)) {
            return;
        }
        $this->enqueue_style();
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
            'gridFilter'  => [
                'param' => Auto_Alt_Text_Alt_View::PARAM,
                'value' => Auto_Alt_Text_Alt_View::MISSING,
                'label' => __('Saknar alt-text', 'WP-Auto-Alt-Text'),
            ],
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
     * gets the stylesheet and media.js, which drives the modal's Generate button.
     */
    public function enqueue_media_modal_assets(): void {
        if (!is_admin()) {
            return;
        }
        $this->enqueue_style();
        $this->enqueue_media_module();
    }

    /**
     * The admin stylesheet: the plugin's own pages (library, statistics, logs,
     * status) and the media screens depend on it.
     */
    private function enqueue_style(): void {
        wp_enqueue_style(
            self::STYLE_HANDLE,
            plugin_dir_url(dirname(__FILE__)) . 'css/style.css',
            [],
            AUTO_ALT_TEXT_VERSION
        );
    }

    /**
     * enqueue_block_editor_assets callback: the Image block's preview-first
     * panel (admin/js/block-editor.js) with its JSON config. It is a classic
     * script, so its editor.BlockEdit filter is in place before the editor
     * initialises. Users without upload_files get nothing: the AJAX
     * endpoints would refuse them.
     */
    public function enqueue_block_editor_script(): void {
        if (wp_script_is(self::BLOCK_EDITOR_HANDLE, 'enqueued') || !current_user_can('upload_files')) {
            return;
        }
        wp_enqueue_script(
            self::BLOCK_EDITOR_HANDLE,
            plugin_dir_url(__FILE__) . 'js/block-editor.js',
            self::BLOCK_EDITOR_DEPS,
            AUTO_ALT_TEXT_VERSION,
            true
        );
        $config = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('auto_alt_text_nonce'),
            'i18n'    => self::block_editor_strings(),
        ];
        wp_add_inline_script(self::BLOCK_EDITOR_HANDLE, 'var aatBlockEditor = ' . wp_json_encode($config) . ';', 'before');
    }

    /** Every string block-editor.js shows. */
    public static function block_editor_strings(): array {
        return [
            'panel'           => Auto_Alt_Text_Coexistence::label(__('Alt-text med AI', 'WP-Auto-Alt-Text')),
            'generate'        => __('Generera alt-text', 'WP-Auto-Alt-Text'),
            'title'           => __('Förhandsgranska alt-text', 'WP-Auto-Alt-Text'),
            'text_label'      => __('Alt-text', 'WP-Auto-Alt-Text'),
            'generating'      => __('Genererar alt-text …', 'WP-Auto-Alt-Text'),
            'apply'           => __('Använd', 'WP-Auto-Alt-Text'),
            'cancel'          => __('Avbryt', 'WP-Auto-Alt-Text'),
            'saving'          => __('Sparar alt-texten …', 'WP-Auto-Alt-Text'),
            'saved'           => __('Alt-texten är sparad på bilden i mediebiblioteket och i blocket.', 'WP-Auto-Alt-Text'),
            'empty_text'      => __('Skriv en alt-text, eller välj Avbryt.', 'WP-Auto-Alt-Text'),
            'generate_failed' => __('Det gick inte att generera alt-text.', 'WP-Auto-Alt-Text'),
            'apply_failed'    => __('Det gick inte att spara alt-texten.', 'WP-Auto-Alt-Text'),
            'session_expired' => __('Sessionen har gått ut. Ladda om sidan och försök igen.', 'WP-Auto-Alt-Text'),
        ];
    }
}
