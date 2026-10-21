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
    private const CHAIN_MAX_BACKOFF = 60;

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
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND (option_value < %s OR option_value NOT REGEXP '^[0-9]{10}[|]')",
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
        // A value without a 10-digit expiry is garbage and counts as stale.
        return null !== $value && 1 === preg_match('/^\d{10}\|/', (string) $value) && $value >= sprintf('%010d|', time());
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
            $run = $wait_when_idle && 0 === $processed ? Auto_Alt_Text_Library_Run::active() : null;
            if (null !== $run) {
                self::idle_wait(max(0.0, $budget - (microtime(true) - $started)), $run['id']);
            }
        } finally {
            self::release_lock($owner);
        }
        return $processed;
    }

    /**
     * Pending rows the queue could claim now or later, scoped like
     * Queue::claim_batch(): non-run rows (minus the auto types while
     * auto-generate is off) and the given run's rows.
     *
     * @return array{due:int,next:?int,can_feed:bool} Count already due, the earliest future due time, and whether the run could feed more images.
     */
    private static function claimable_state(int $run_id): array {
        global $wpdb;
        $now = current_time('mysql', true);
        $params = [$now, $now, $run_id, Auto_Alt_Text_Queue::STATUS_PENDING];
        $type_clause = '';
        if (!get_option('auto_alt_text_auto_generate', true)) {
            $types = Auto_Alt_Text_Queue::AUTO_GENERATION_TYPES;
            $type_clause = ' AND generation_type NOT IN (' . implode(',', array_fill(0, count($types), '%s')) . ')';
            $params = array_merge($params, $types);
        }
        $params[] = $run_id;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table name and generated placeholders only.
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT COALESCE(SUM(next_attempt_at <= %s), 0) AS due, MIN(CASE WHEN next_attempt_at > %s THEN next_attempt_at END) AS next,
                    COALESCE(SUM(run_id = %d), 0) AS run_total
             FROM ' . Auto_Alt_Text_Queue::get_table_name() . " WHERE status = %s AND ((run_id IS NULL{$type_clause}) OR run_id = %d)",
            $params
        ));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        return [
            'due'      => $row ? (int) $row->due : 0,
            'next'     => ($row && null !== $row->next) ? (int) strtotime($row->next . ' UTC') : null,
            'can_feed' => self::can_feed($run_id, $row ? (int) $row->run_total : 0),
        ];
    }

    private static function run_fed_all(int $run_id): bool {
        $run = Auto_Alt_Text_Library_Run::get($run_id);
        return null !== $run && (bool) $run['fed_all'];
    }

    /** Whether feed() would add images now: not all fed, and below its pending cap. */
    private static function can_feed(int $run_id, int $run_pending): bool {
        $run = Auto_Alt_Text_Library_Run::get($run_id);
        if (null === $run || $run['fed_all']) {
            return false;
        }
        $window = max(1, (int) apply_filters('aat_library_feed_window', 20));
        return $run_pending < 5 * $window;
    }

    /** Waits 1-20 s (and no longer than the remaining budget) until a claimable job is due. */
    private static function idle_wait(float $remaining, int $run_id): void {
        $delay = (new Auto_Alt_Text_Rate_Limiter())->get_recommended_delay();
        $state = self::claimable_state($run_id);
        $next = $state['next'];
        if (null !== $next) {
            $delay = max($delay, $next - time());
        } elseif (!self::run_fed_all($run_id)) {
            $delay = max($delay, 0); // Feed scanning keeps moving: the 1 s floor applies.
        } else {
            $delay = max($delay, 20); // Nothing claimable and nothing to feed: e.g. a crashed row until recovery.
        }
        $wait = max(1.0, min($remaining, (float) $delay, 20.0));
        $wait = max(0.0, min(20.0, (float) apply_filters('aat_library_idle_wait', $wait)));
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
        // Only long backoffs left and nothing to feed: the minutely cron tick restarts the chain.
        $state = self::claimable_state($run['id']);
        if (0 === $state['due'] && null !== $state['next'] && $state['next'] - time() > self::CHAIN_MAX_BACKOFF && !$state['can_feed']) {
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

    /**
     * When a running run next has something claimable: set only while nothing
     * is due and rows wait out a backoff, otherwise null.
     *
     * @return string|null UTC datetime.
     */
    public static function waiting_until(array $run): ?string {
        if ('running' !== Auto_Alt_Text_Library_Run::display_state($run)['state']) {
            return null;
        }
        $state = self::claimable_state((int) $run['id']);
        return (0 === $state['due'] && null !== $state['next']) ? gmdate('Y-m-d H:i:s', $state['next']) : null;
    }

    /** @return array{stalled:bool,cron_disabled:bool,no_api_key:bool} */
    public static function health(array $run): array {
        $stalled = false;
        if ('running' === Auto_Alt_Text_Library_Run::display_state($run)['state']) {
            $since = $run['last_tick_at'] ?? $run['started_at'] ?? null;
            $threshold = (int) apply_filters('aat_library_stall_seconds', 600);
            $quiet = empty($since) || (time() - (int) strtotime($since . ' UTC')) > $threshold;
            if ($quiet) {
                // Waiting out a backoff is not a stall: a claimable job must be
                // due, the run must have nothing pending at all, or it could feed.
                $state = self::claimable_state((int) $run['id']);
                $stalled = $state['due'] > 0 || null === $state['next'] || $state['can_feed'];
            }
        }
        return [
            'stalled'       => $stalled,
            'cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            // The queue processes nothing without a key; the page says so instead of the stall warning.
            'no_api_key'    => !Auto_Alt_Text_Queue::has_api_key(),
        ];
    }
}
