<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Caches generated alt text in transients. Keys are built from the attachment
 * ID and everything that shaped the answer (file size and mtime, model,
 * detail, prompt), so the image file is never read just to make a key.
 */
class Auto_Alt_Text_Cache_Manager {
    private const CACHE_PREFIX = 'aat_img_';
    public const VERSION_OPTION = 'aat_cache_version';
    public const CLEAR_ACTION = 'aat_clear_cache';

    /** @param array $parts file fingerprint, model, effort, detail, instructions, input. */
    public static function get_cache_key($attachment_id, array $parts) {
        return self::CACHE_PREFIX . (int) $attachment_id . '_' . md5(wp_json_encode(self::normalize($parts + ['cache_version' => self::version()])));
    }

    /** @return string|false */
    public static function get_cached_response($cache_key) {
        return get_transient($cache_key);
    }

    public static function set_cached_response($cache_key, $alt_text) {
        $days = max(1, (int) get_option('aat_cache_expiration', 30));
        set_transient($cache_key, $alt_text, DAY_IN_SECONDS * $days);
    }

    /** Bumping the version orphans every old key on any host; the rows are also deleted where the cache lives in the database. */
    public static function clear_all_caches(): void {
        self::bump_version();
        if (wp_using_ext_object_cache()) {
            return;
        }
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like('_transient_' . self::CACHE_PREFIX) . '%',
                $wpdb->esc_like('_transient_timeout_' . self::CACHE_PREFIX) . '%'
            )
        );
    }

    /** Number of cached answers, or null when an external object cache hides them from the database. */
    public static function count_entries(): ?int {
        if (wp_using_ext_object_cache()) {
            return null;
        }
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like('_transient_' . self::CACHE_PREFIX) . '%'
            )
        );
    }

    public static function version(): int {
        return max(1, (int) get_option(self::VERSION_OPTION, 1));
    }

    public static function bump_version(): int {
        $next = self::version() + 1;
        update_option(self::VERSION_OPTION, $next, true);
        return $next;
    }

    public static function process_clear_request(): string {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'WP-Auto-Alt-Text'));
        }
        check_admin_referer(self::CLEAR_ACTION);
        self::clear_all_caches();
        return auto_alt_text_settings_url('cache', ['aat_notice' => 'cache_cleared']);
    }

    public static function handle_clear_request(): void {
        wp_safe_redirect(self::process_clear_request());
        exit;
    }

    /** Drops every cached variant for one attachment. */
    public static function invalidate_cache($attachment_id) {
        global $wpdb;
        $prefix = self::CACHE_PREFIX . (int) $attachment_id . '_';
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like('_transient_' . $prefix) . '%',
                $wpdb->esc_like('_transient_timeout_' . $prefix) . '%'
            )
        );
    }

    /** Sorts associative arrays recursively so key order never changes the hash. */
    private static function normalize($value) {
        if (!is_array($value)) {
            return $value;
        }
        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }
        return array_map([__CLASS__, 'normalize'], $value);
    }
}
