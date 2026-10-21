<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Whether the OpenAI key works with the chosen model: a stored, fingerprinted
 * record, so pages and notices never call OpenAI to find out. The record holds
 * a keyed hash of the key, never the key.
 */
class Auto_Alt_Text_Key_Status {
    public const OPTION = 'aat_key_status';
    public const STALE_AFTER = DAY_IN_SECONDS;
    public const TIMEOUT = 5;
    public const CHECK_ACTION = 'aat_check_key';

    public const NONE = 'none';
    public const OK = 'ok';
    public const AUTH_FAILED = 'auth_failed';
    public const MODEL_UNAVAILABLE = 'model_unavailable';
    public const QUOTA = 'quota';
    public const UNKNOWN = 'unknown';
    public const UNCHECKED = 'unchecked';

    /**
     * @return array{state:string,model:string,checked_at:?int}
     */
    public static function current(): array {
        $model = Auto_Alt_Text_Models::current_id();
        $fingerprint = (new Auto_Alt_Text_OpenAI())->key_fingerprint();
        $record = self::record();

        if ('' === $fingerprint) {
            // A future "key can't be read" state (a key lost to an AUTH_SALT rotation, spec §6.2) goes here, next to none.
            return self::result(self::NONE, $model, null);
        }
        // A blocked connector is classed 'auth' too and pauses the queue, but says nothing about the key:
        // the connector notice owns that case, so the status reads as though there were no pause.
        if (
            false !== get_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT)
            && !Auto_Alt_Text_Connector_Status::is_blocked()
        ) {
            return self::result(self::AUTH_FAILED, $model, $record['at'] ?? null);
        }
        if (Auto_Alt_Text_Quota_Monitor::is_active()) {
            return self::result(self::QUOTA, $model, $record['at'] ?? null);
        }
        if (null !== $record && self::is_valid($record, $model, $fingerprint)) {
            return self::result($record['state'], $model, $record['at']);
        }
        return self::result(self::UNCHECKED, $model, null);
    }

    /** True when no valid record exists for this key and model. */
    public static function needs_check(string $model_id): bool {
        $record = self::record();
        return null === $record
            || !self::is_valid($record, $model_id, (new Auto_Alt_Text_OpenAI())->key_fingerprint());
    }

    /**
     * Asks OpenAI once whether the key can use the model, and stores the answer.
     * Returns the state. Without a key: 'none', no call and no record.
     */
    public static function check(string $model_id): string {
        $openai = new Auto_Alt_Text_OpenAI();
        $fingerprint = $openai->key_fingerprint();
        if ('' === $fingerprint) {
            return self::NONE;
        }

        $code = $openai->models_status($model_id, self::TIMEOUT);
        if (200 === $code) {
            $state = self::OK;
        } elseif (404 === $code) {
            $state = self::MODEL_UNAVAILABLE;
        } elseif (401 === $code) {
            $state = self::AUTH_FAILED;
        } else {
            $state = self::UNKNOWN;
        }

        update_option(self::OPTION, [
            'state' => $state,
            'model' => $model_id,
            'at'    => time(),
            'fp'    => $fingerprint,
        ], false);

        // Both answers prove the key authenticates.
        if (self::OK === $state || self::MODEL_UNAVAILABLE === $state) {
            Auto_Alt_Text_Queue::clear_auth_pause();
        }
        return $state;
    }

    /** Runs the "Kontrollera nu" check for an administrator. Returns the URL to go back to. */
    public static function process_check_request(): string {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'WP-Auto-Alt-Text'));
        }
        check_admin_referer(self::CHECK_ACTION);
        self::check(Auto_Alt_Text_Models::current_id());
        return auto_alt_text_settings_url('anslutning');
    }

    public static function handle_check_request(): void {
        wp_safe_redirect(self::process_check_request());
        exit;
    }

    /** Shown on the Media Library and the plugin's own screens, except the settings page, which shows the status inline. */
    public static function render_admin_notice(): void {
        // While the connector is blocked, its own notice says what to do.
        if (!current_user_can('manage_options') || Auto_Alt_Text_Connector_Status::is_blocked()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (
            !$screen
            || 'toplevel_page_auto-alt-text' === $screen->id
            || ('upload' !== $screen->id && false === strpos((string) $screen->id, 'auto-alt-text'))
        ) {
            return;
        }
        $current = self::current();
        if (self::AUTH_FAILED === $current['state']) {
            $message = __('OpenAI godkänner inte API-nyckeln, så inga alt-texter kan genereras.', 'WP-Auto-Alt-Text');
        } elseif (self::MODEL_UNAVAILABLE === $current['state']) {
            /* translators: %s: model ID */
            $message = sprintf(__('Modellen %s är inte tillgänglig för API-nyckeln, så inga alt-texter kan genereras.', 'WP-Auto-Alt-Text'), $current['model']);
        } else {
            return;
        }
        printf(
            '<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
            esc_html__('WP Auto Alt Text:', 'WP-Auto-Alt-Text'),
            esc_html($message),
            esc_url(auto_alt_text_settings_url('anslutning')),
            esc_html__('Öppna inställningarna', 'WP-Auto-Alt-Text')
        );
    }

    private static function record(): ?array {
        $record = get_option(self::OPTION);
        if (
            !is_array($record)
            || !isset($record['state'], $record['model'], $record['at'], $record['fp'])
            || !is_string($record['state']) || !is_string($record['model']) || !is_string($record['fp'])
            || !is_int($record['at'])
        ) {
            return null;
        }
        return $record;
    }

    /**
     * A found failure stays until the key or the model changes or a new check runs;
     * only 'ok' and 'unknown' go stale after STALE_AFTER.
     */
    private static function is_valid(array $record, string $model_id, string $fingerprint): bool {
        return '' !== $fingerprint
            && $record['model'] === $model_id
            && hash_equals($record['fp'], $fingerprint)
            && (
                in_array($record['state'], [self::AUTH_FAILED, self::MODEL_UNAVAILABLE], true)
                || time() - $record['at'] <= self::STALE_AFTER
            );
    }

    private static function result(string $state, string $model, ?int $checked_at): array {
        return ['state' => $state, 'model' => $model, 'checked_at' => $checked_at];
    }
}
