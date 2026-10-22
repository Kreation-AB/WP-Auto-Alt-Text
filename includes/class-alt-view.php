<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Read-only "Saknar alt-text" view of the media library list mode: a filter
 * next to core's own and an "Alt-text" column with the provenance.
 */
class Auto_Alt_Text_Alt_View {
    public const PARAM = 'aat_alt';
    public const MISSING = 'missing';
    public const COLUMN = 'aat_alt';

    public static function register(): void {
        add_action('restrict_manage_posts', [self::class, 'render_filter'], 10, 1);
        add_filter('ajax_query_attachments_args', [self::class, 'filter_grid_query']);
        add_action('pre_get_posts', [self::class, 'filter_list_query']);
        add_filter('manage_media_columns', [self::class, 'add_column']);
        add_action('manage_media_custom_column', [self::class, 'render_column'], 10, 2);
    }

    /**
     * Query vars restricted to images without alt text. Appends to an existing
     * meta_query (other plugins, for example WP Media Folder, add their own).
     *
     * @param array<string, mixed> $query_vars
     * @return array<string, mixed>
     */
    public static function missing_clauses(array $query_vars): array {
        $alt_key = Auto_Alt_Text_Provenance::ALT_META_KEY;
        $missing = [
            'relation' => 'OR',
            ['key' => $alt_key, 'compare' => 'NOT EXISTS'],
            ['key' => $alt_key, 'value' => '^[[:space:]]*$', 'compare' => 'REGEXP'],
        ];
        $existing = $query_vars['meta_query'] ?? [];
        $query_vars['meta_query'] = empty($existing) || !is_array($existing)
            ? [$missing]
            : ['relation' => 'AND', $existing, $missing];

        $mime = $query_vars['post_mime_type'] ?? '';
        $is_image = is_string($mime) && ('image' === $mime || 0 === strpos($mime, 'image/'));
        if (!$is_image) {
            $query_vars['post_mime_type'] = 'image';
        }
        return $query_vars;
    }

    private static function on_media_screen(): bool {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        return $screen && 'upload' === $screen->base;
    }

    private static function requested(): bool {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
        $value = isset($_GET[self::PARAM]) ? sanitize_key(wp_unslash((string) $_GET[self::PARAM])) : '';
        return self::MISSING === $value;
    }

    public static function filter_list_query(WP_Query $query): void {
        if (!is_admin() || !$query->is_main_query() || !self::on_media_screen() || !self::requested()) {
            return;
        }
        foreach (self::missing_clauses($query->query_vars) as $key => $value) {
            if ('meta_query' === $key || 'post_mime_type' === $key) {
                $query->set($key, $value);
            }
        }
    }

    /** URL of the list-mode view of images without alt text. */
    public static function list_url(): string {
        return admin_url('upload.php?mode=list&' . self::PARAM . '=' . self::MISSING);
    }

    /**
     * Grid mode: core whitelists the query keys before this filter runs, so the
     * custom param is read from the raw request. Only "missing" is accepted.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public static function filter_grid_query(array $query): array {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- read-only filter; the value is compared to a constant, core's handler checks capabilities.
        $request = isset($_REQUEST['query']) && is_array($_REQUEST['query']) ? wp_unslash($_REQUEST['query']) : [];
        $raw = $request[self::PARAM] ?? '';
        if (!is_string($raw) || self::MISSING !== $raw) {
            return $query;
        }
        return self::missing_clauses($query);
    }

    public static function render_filter(string $post_type): void {
        if ('attachment' !== $post_type || !self::on_media_screen()) {
            return;
        }
        $selected = self::requested();
        echo '<label class="screen-reader-text" for="aat-alt-filter">' . esc_html__('Filtrera på alt-text', 'WP-Auto-Alt-Text') . '</label>';
        echo '<select name="' . esc_attr(self::PARAM) . '" id="aat-alt-filter">';
        echo '<option value="">' . esc_html__('Alla alt-texter', 'WP-Auto-Alt-Text') . '</option>';
        echo '<option value="' . esc_attr(self::MISSING) . '"' . selected($selected, true, false) . '>' . esc_html__('Saknar alt-text', 'WP-Auto-Alt-Text') . '</option>';
        echo '</select>';
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public static function add_column(array $columns): array {
        $label = __('Alt-text', 'WP-Auto-Alt-Text');
        $out = [];
        $added = false;
        foreach ($columns as $key => $value) {
            $out[$key] = $value;
            if ('title' === $key) {
                $out[self::COLUMN] = $label;
                $added = true;
            }
        }
        if (!$added) {
            $out[self::COLUMN] = $label;
        }
        return $out;
    }

    /** @return array{state: string, label: string} */
    public static function state_label(int $attachment_id): array {
        if (0 !== strpos((string) get_post_mime_type($attachment_id), 'image/')) {
            return ['state' => 'not_image', 'label' => __('Inte en bild', 'WP-Auto-Alt-Text')];
        }
        $state = Auto_Alt_Text_Provenance::classify($attachment_id);
        switch ($state) {
            case Auto_Alt_Text_Provenance::STATE_AI:
                return ['state' => 'ai', 'label' => __('AI-skriven', 'WP-Auto-Alt-Text')];
            case Auto_Alt_Text_Provenance::STATE_HUMAN:
                return ['state' => 'human', 'label' => __('Skriven av en person', 'WP-Auto-Alt-Text')];
            default:
                return ['state' => 'missing', 'label' => __('Saknas', 'WP-Auto-Alt-Text')];
        }
    }

    public static function render_column(string $column, int $post_id): void {
        if (self::COLUMN !== $column) {
            return;
        }
        $info = self::state_label($post_id);
        if ('not_image' === $info['state']) {
            echo '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">' . esc_html($info['label']) . '</span>';
            return;
        }
        if ('missing' === $info['state']) {
            echo '<span class="aat-alt-col-missing">' . esc_html($info['label']) . '</span>';
            return;
        }
        $alt = trim((string) get_post_meta($post_id, Auto_Alt_Text_Provenance::ALT_META_KEY, true));
        echo '<span class="aat-alt-col-text">' . esc_html($alt) . '</span>';
        echo '<span class="aat-alt-col-source aat-alt-col-' . esc_attr($info['state']) . '">' . esc_html($info['label']) . '</span>';
    }
}
