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
    public const LOCK_OPTION  = 'aat_library_runner_lock';
    private const LOCK_SECONDS = 120;
    private const EXTRA_TIME_LIMIT = 30;

    /** The shared secret of the loopback request; created on first use. */
    public static function token(): string {
        $token = get_option(self::TOKEN_OPTION);
        if (!is_string($token) || '' === $token) {
            $token = bin2hex(random_bytes(16));
            if (!add_option(self::TOKEN_OPTION, $token, '', false)) {
                // Lost a race on first use: the stored value is the one in circulation.
                $stored = get_option(self::TOKEN_OPTION);
                if (is_string($stored) && '' !== $stored) {
                    return $stored;
                }
            }
        }
        return $token;
    }

    private static function budget(): float {
        return (float) apply_filters('aat_queue_time_budget', 25.0);
    }

    /**
     * Atomic owner lock in the options table, value "expires|owner" with a
     * zero-padded expiry so stale locks compare as strings.
     *
     * @return string|null The owner id, or null when another runner holds it.
     */
    public static function take_lock(): ?string {
        global $wpdb;
        $owner = wp_generate_uuid4();
        $value = self::lock_value($owner);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic lock; the options API has no insert-if-absent.
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            self::LOCK_OPTION, $value
        ));
        $taken = 1 === (int) $wpdb->rows_affected;
        if (!$taken) {
            // Takeover of an expired lock, in one conditional statement.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $taken = 1 === (int) $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value < %s",
                $value, self::LOCK_OPTION, sprintf('%010d|', time())
            ));
        }
        return $taken ? $owner : null;
    }

    /** Extends the owner's lock; a lock someone else holds is left alone. */
    public static function refresh_lock(string $owner): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value LIKE %s",
            self::lock_value($owner), self::LOCK_OPTION, '%|' . $wpdb->esc_like($owner)
        ));
    }

    /** Deletes the lock only when this owner holds it. */
    public static function release_lock(string $owner): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
            self::LOCK_OPTION, '%|' . $wpdb->esc_like($owner)
        ));
    }

    public static function lock_held(): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION
        ));
        return null !== $value && $value >= sprintf('%010d|', time());
    }

    private static function lock_value(string $owner): string {
        return sprintf('%010d|%s', time() + self::LOCK_SECONDS, $owner);
    }

    /** The cron entry point. Without an active run it is a plain queue run. */
    public static function cron_tick(): void {
        if (null === Auto_Alt_Text_Library_Run::active()) {
            (new Auto_Alt_Text_Queue())->run();
            return;
        }
        if (null !== self::run_locked(false)) {
            self::maybe_chain();
        }
    }

    /**
     * Runs the budgeted queue under the runner lock. After an idle tick the
     * background request waits (still holding the lock) so a chain of idle
     * ticks cannot spin.
     *
     * @return int|null Jobs processed, or null when another runner holds the lock.
     */
    private static function run_locked(bool $wait_when_idle): ?int {
        $owner = self::take_lock();
        if (null === $owner) {
            return null;
        }
        try {
            $started = microtime(true);
            $budget = self::budget();
            $processed = (new Auto_Alt_Text_Queue())->run($budget, static function () use ($owner): void {
                self::refresh_lock($owner);
            });
            if ($wait_when_idle && 0 === $processed) {
                self::idle_wait(max(0.0, $budget - (microtime(true) - $started)));
            }
        } finally {
            self::release_lock($owner);
        }
        return $processed;
    }

    /** Seconds until something is due again, at least 1, at most 20 and the remaining budget. */
    private static function idle_wait(float $remaining): void {
        global $wpdb;
        $delay = (new Auto_Alt_Text_Rate_Limiter())->get_recommended_delay();
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Internal table name only.
        $next = $wpdb->get_var($wpdb->prepare(
            'SELECT MIN(next_attempt_at) FROM ' . Auto_Alt_Text_Queue::get_table_name() . ' WHERE status = %s',
            Auto_Alt_Text_Queue::STATUS_PENDING
        ));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        if (null !== $next) {
            $delay = max($delay, (int) strtotime($next . ' UTC') - time());
        }
        $wait = max(1.0, min($remaining, (float) $delay, 20.0));
        $wait = (float) apply_filters('aat_library_idle_wait', $wait);
        if ($wait > 0) {
            usleep((int) round($wait * 1000000));
        }
    }

    /** Hooked to run start and resume: begin processing at once. */
    public static function on_run_event(): void {
        self::maybe_chain();
    }

    /**
     * Sends the loopback request that runs the next tick, unless the run is
     * not working (none, paused by the user, quota or auth stop, no API key)
     * or a runner is already busy.
     */
    public static function maybe_chain(): bool {
        $run = Auto_Alt_Text_Library_Run::active();
        if (null === $run || 'running' !== Auto_Alt_Text_Library_Run::display_state($run)['state']) {
            return false;
        }
        if (!Auto_Alt_Text_Queue::has_api_key() || self::lock_held()) {
            return false;
        }
        $url = admin_url('admin-post.php');
        $args = [
            'blocking'  => false,
            'timeout'   => 0.01,
            'body'      => ['action' => self::TICK_ACTION, 'token' => self::token()],
            'sslverify' => (bool) apply_filters('https_local_ssl_verify', false),
        ];
        wp_remote_post($url, apply_filters('aat_library_loopback_args', $args, $url));
        return true;
    }

    /** admin-post handler of the loopback tick. */
    public static function handle_tick_request(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Authenticated by the shared secret token.
        $given = isset($_POST['token']) && is_string($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        if (!hash_equals(self::token(), $given)) {
            wp_die('', '', ['response' => 403]);
        }

        ignore_user_abort(true);
        wp_raise_memory_limit('admin');
        if (function_exists('set_time_limit')) {
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- A tick may outlast the default limit.
            @set_time_limit((int) ceil(self::budget()) + 20 + self::EXTRA_TIME_LIMIT);
        }

        if (null !== self::run_locked(true)) {
            self::maybe_chain();
        }
        wp_die('', '', ['response' => 204]);
    }

    /** @return array{stalled:bool,cron_disabled:bool} */
    public static function health(array $run): array {
        $stalled = false;
        if ('running' === Auto_Alt_Text_Library_Run::display_state($run)['state']) {
            $since = $run['last_tick_at'] ?? $run['started_at'] ?? null;
            $threshold = (int) apply_filters('aat_library_stall_seconds', 600);
            $stalled = empty($since) || (time() - (int) strtotime($since . ' UTC')) > $threshold;
        }
        return [
            'stalled'       => $stalled,
            'cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
        ];
    }
}
