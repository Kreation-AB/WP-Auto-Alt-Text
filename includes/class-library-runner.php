<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Background runner for a library run: a time-budgeted queue tick chained by
 * a non-blocking loopback request, so the queue keeps working without visits
 * or an open tab. WP-Cron remains the fallback that restarts a broken chain.
 */
class Auto_Alt_Text_Library_Runner {
    public const TICK_ACTION  = 'aat_library_tick';
    public const TOKEN_OPTION = 'aat_library_runner_token';
    public const LOCK_TRANSIENT = 'aat_library_runner_lock';
    private const LOCK_SECONDS = 60;
    private const EXTRA_TIME_LIMIT = 30;

    /** The shared secret of the loopback request; created on first use. */
    public static function token(): string {
        $token = get_option(self::TOKEN_OPTION);
        if (!is_string($token) || '' === $token) {
            $token = bin2hex(random_bytes(16));
            add_option(self::TOKEN_OPTION, $token, '', false);
        }
        return $token;
    }

    private static function budget(): float {
        return (float) apply_filters('aat_queue_time_budget', 25.0);
    }

    /** The cron entry point. Without an active run it is a plain queue run. */
    public static function cron_tick(): void {
        if (null === Auto_Alt_Text_Library_Run::active()) {
            (new Auto_Alt_Text_Queue())->run();
            return;
        }
        if (!self::run_locked()) {
            return;
        }
        self::maybe_chain();
    }

    /**
     * Runs the budgeted queue under the runner lock.
     *
     * @return bool False when another runner holds the lock.
     */
    private static function run_locked(): bool {
        if (get_transient(self::LOCK_TRANSIENT)) {
            return false;
        }
        set_transient(self::LOCK_TRANSIENT, 1, self::LOCK_SECONDS);
        try {
            (new Auto_Alt_Text_Queue())->run(self::budget());
        } finally {
            delete_transient(self::LOCK_TRANSIENT);
        }
        return true;
    }

    /**
     * Sends the loopback request that runs the next tick, unless the run is
     * not working (none, paused by the user, quota or auth stop) or a runner
     * is already busy.
     */
    public static function maybe_chain(): bool {
        $run = Auto_Alt_Text_Library_Run::active();
        if (null === $run || 'running' !== Auto_Alt_Text_Library_Run::display_state($run)['state']) {
            return false;
        }
        if (get_transient(self::LOCK_TRANSIENT)) {
            return false;
        }
        wp_remote_post(admin_url('admin-post.php'), [
            'blocking'  => false,
            'timeout'   => 0.01,
            'body'      => ['action' => self::TICK_ACTION, 'token' => self::token()],
            'sslverify' => (bool) apply_filters('https_local_ssl_verify', false),
        ]);
        return true;
    }

    /** admin-post handler of the loopback tick. */
    public static function handle_tick_request(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Authenticated by the shared secret token.
        $given = isset($_POST['token']) ? sanitize_text_field(wp_unslash((string) $_POST['token'])) : '';
        if (!hash_equals(self::token(), $given)) {
            wp_die('', '', ['response' => 403]);
        }

        ignore_user_abort(true);
        wp_raise_memory_limit('admin');
        if (function_exists('set_time_limit')) {
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- A tick may outlast the default limit.
            @set_time_limit((int) ceil(self::budget()) + self::EXTRA_TIME_LIMIT);
        }

        if (self::run_locked()) {
            self::maybe_chain();
        }
        wp_die('', '', ['response' => 204]);
    }

    /** @return array{stalled:bool,cron_disabled:bool} */
    public static function health(array $run): array {
        $stalled = false;
        if ('running' === Auto_Alt_Text_Library_Run::display_state($run)['state']) {
            $last = $run['last_tick_at'] ?? null;
            $threshold = (int) apply_filters('aat_library_stall_seconds', 600);
            $stalled = empty($last) || (time() - (int) strtotime($last . ' UTC')) > $threshold;
        }
        return [
            'stalled'       => $stalled,
            'cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
        ];
    }
}
