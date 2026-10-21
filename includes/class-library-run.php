<?php
if ( ! defined( 'ABSPATH' ) ) exit;


/**
 * One confirmed library backfill ("Hela biblioteket"): start, pause, resume,
 * cancel, retry and finalize, firing an action at each point. All datetimes
 * are UTC. Quota and auth pauses are derived from live signals, never stored.
 */
class Auto_Alt_Text_Library_Run {
    public const STATUS_RUNNING   = 'running';
    public const STATUS_PAUSED    = 'paused';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FINISHED  = 'finished';

    private const START_LOCK_OPTION = 'aat_library_start_lock';
    private const START_LOCK_SECONDS = 30;
    private const MAX_FAILURES = 500;
    private const FEED_CHUNK = 200;
    private const FEED_PENDING_CAP_FACTOR = 5;
    private const FEED_BUDGET_SECONDS = 10.0;
    private const UNSUPPORTED_MESSAGE = 'Bildformatet stöds inte.';

    private static function table(): string {
        return Auto_Alt_Text_Activator::get_runs_table_name();
    }

    private static function now(): string {
        return gmdate('Y-m-d H:i:s');
    }

    /** @return array<string,mixed>|WP_Error */
    public static function start(string $coverage, int $user_id) {
        if (!self::take_start_lock()) {
            return self::active_error();
        }
        try {
            return self::start_locked($coverage, $user_id);
        } finally {
            delete_option(self::START_LOCK_OPTION);
        }
    }

    private static function active_error(): WP_Error {
        return new WP_Error('aat_library_run_active', __('Det pågår redan en körning.', 'WP-Auto-Alt-Text'));
    }

    /** Serializes start(); a lock older than 30 s is taken over. */
    private static function take_start_lock(): bool {
        if (add_option(self::START_LOCK_OPTION, time(), '', false)) {
            return true;
        }
        wp_cache_delete(self::START_LOCK_OPTION, 'options');
        wp_cache_delete('alloptions', 'options');
        $held = (int) get_option(self::START_LOCK_OPTION, 0);
        if (time() - $held <= self::START_LOCK_SECONDS) {
            return false;
        }
        update_option(self::START_LOCK_OPTION, time(), false);
        return true;
    }

    /** @return array<string,mixed>|WP_Error */
    private static function start_locked(string $coverage, int $user_id) {
        global $wpdb;
        if (null !== self::active()) {
            return self::active_error();
        }
        $counts = Auto_Alt_Text_Library_Scanner::cached_counts();
        if (!$counts['complete']) {
            return new WP_Error('aat_library_counts_incomplete', __('Biblioteket har inte räknats klart ännu.', 'WP-Auto-Alt-Text'));
        }
        $total = $counts['missing'];
        if (Auto_Alt_Text_Library_Scanner::COVERAGE_MISSING_AI === $coverage) {
            $total += $counts['ai'];
        } else {
            $coverage = Auto_Alt_Text_Library_Scanner::COVERAGE_MISSING;
        }
        if ($total <= 0) {
            return new WP_Error('aat_library_nothing_to_do', __('Det finns inget att behandla.', 'WP-Auto-Alt-Text'));
        }

        $now = self::now();
        $per_1000 = Auto_Alt_Text_Models::cost_per_1000(Auto_Alt_Text_Models::current_id());
        $inserted = $wpdb->insert(self::table(), [
            'status'            => self::STATUS_RUNNING,
            'coverage'          => $coverage,
            'started_by'        => $user_id,
            'started_at'        => $now,
            'total'             => $total,
            'estimate_usd'      => round($total * $per_1000 / 1000, 4),
            'max_attachment_id' => $counts['max_id'],
            'summary'           => wp_json_encode(self::blank_summary()),
            'updated_at'        => $now,
        ]);
        if (!$inserted) {
            return new WP_Error('aat_library_run_failed', __('Kunde inte starta körningen.', 'WP-Auto-Alt-Text'));
        }
        $run = self::get((int) $wpdb->insert_id);
        do_action('aat_library_run_started', $run);
        return $run;
    }

    public static function active(): ?array {
        global $wpdb;
        $table = self::table();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table name and generated %d list only.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE status IN (%s, %s) ORDER BY id DESC LIMIT 1",
            self::STATUS_RUNNING, self::STATUS_PAUSED
        ), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        return $row ? self::hydrate($row) : null;
    }

    public static function latest(): ?array {
        global $wpdb;
        $table = self::table();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table name and generated %d list only.
        $row = $wpdb->get_row("SELECT * FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        return $row ? self::hydrate($row) : null;
    }

    public static function get(int $id): ?array {
        global $wpdb;
        $table = self::table();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table name and generated %d list only.
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        return $row ? self::hydrate($row) : null;
    }

    public static function pause(int $id): bool {
        if (!self::transition($id, self::STATUS_RUNNING, self::STATUS_PAUSED)) {
            return false;
        }
        self::note_event($id, 'pause', 'user');
        do_action('aat_library_run_paused', self::get($id), 'user');
        return true;
    }

    public static function resume(int $id): bool {
        if (!self::transition($id, self::STATUS_PAUSED, self::STATUS_RUNNING)) {
            return false;
        }
        self::note_event($id, 'resume');
        do_action('aat_library_run_resumed', self::get($id));
        return true;
    }

    public static function cancel(int $id): bool {
        global $wpdb;
        $run = self::get($id);
        if (null === $run || !in_array($run['status'], [self::STATUS_RUNNING, self::STATUS_PAUSED], true)) {
            return false;
        }
        $table = Auto_Alt_Text_Queue::get_table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table name and generated %d list only.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE run_id = %d AND status = %s",
            $id, Auto_Alt_Text_Queue::STATUS_PENDING
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        if (!self::close($id, self::STATUS_CANCELLED)) {
            return false;
        }
        do_action('aat_library_run_cancelled', self::get($id));
        return true;
    }

    /** @return array{state:string,reason:?string} */
    public static function display_state(array $run): array {
        switch ($run['status']) {
            case self::STATUS_PAUSED:
                return ['state' => 'paused_user', 'reason' => 'user'];
            case self::STATUS_CANCELLED:
                return ['state' => 'cancelled', 'reason' => null];
            case self::STATUS_FINISHED:
                return ['state' => 'finished', 'reason' => null];
        }
        if (Auto_Alt_Text_Quota_Monitor::is_active()) {
            return ['state' => 'paused_quota', 'reason' => 'quota'];
        }
        if (get_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT)) {
            return ['state' => 'paused_auth', 'reason' => 'auth'];
        }
        return ['state' => 'running', 'reason' => null];
    }

    /** Appends an event; a call identical to the last event is ignored. */
    public static function note_event(int $id, string $type, string $reason = ''): void {
        $run = self::get($id);
        if (null === $run) {
            return;
        }
        $events = $run['summary']['events'];
        $last = end($events);
        if (false !== $last && $last['type'] === $type && $last['reason'] === $reason) {
            return;
        }
        $events[] = ['type' => $type, 'reason' => $reason, 'at' => self::now()];
        $run['summary']['events'] = $events;
        self::save_summary($id, $run['summary']);
    }

    /** @return array{total:int,filled:int,skipped:int,failed:int,pending:int,done:int} */
    public static function progress(array $run): array {
        global $wpdb;
        if (in_array($run['status'], [self::STATUS_CANCELLED, self::STATUS_FINISHED], true)) {
            $s = $run['summary'];
            $filled = (int) $s['filled'];
            $skipped = (int) $s['skipped'];
            $failed = (int) $s['failed'];
            $done = $filled + $skipped + $failed;
            return [
                'total'   => max($run['total'], $done),
                'filled'  => $filled,
                'skipped' => $skipped,
                'failed'  => $failed,
                'pending' => 0,
                'done'    => $done,
            ];
        }
        $table = Auto_Alt_Text_Queue::get_table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table name and generated %d list only.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) AS n FROM {$table} WHERE run_id = %d GROUP BY status",
            $run['id']
        ), OBJECT_K);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $n = static fn(string $status): int => isset($rows[$status]) ? (int) $rows[$status]->n : 0;
        $filled = $n(Auto_Alt_Text_Queue::STATUS_DONE);
        $skipped = $n(Auto_Alt_Text_Queue::STATUS_SKIPPED) + $run['skipped_at_feed'];
        $failed = $n(Auto_Alt_Text_Queue::STATUS_FAILED);
        $done = $filled + $skipped + $failed;
        return [
            'total'   => max($run['total'], $done),
            'filled'  => $filled,
            'skipped' => $skipped,
            'failed'  => $failed,
            'pending' => $n(Auto_Alt_Text_Queue::STATUS_PENDING) + $n(Auto_Alt_Text_Queue::STATUS_PROCESSING),
            'done'    => $done,
        ];
    }

    /**
     * Tops the run's pending rows up to $window with the next candidate
     * images in ascending ID order, resuming from the stored cursor. Images
     * that no longer qualify are passed over without being counted; the run
     * accounts for them when it finishes.
     *
     * @return int Number of jobs enqueued.
     */
    public static function feed(int $window): int {
        global $wpdb;
        $run = self::active();
        if (null === $run || self::STATUS_RUNNING !== $run['status'] || $run['fed_all']) {
            return 0;
        }
        $queue = new Auto_Alt_Text_Queue();
        $table = Auto_Alt_Text_Queue::get_table_name();
        $window = max(1, $window);
        // Only due rows can be claimed, so rows waiting out a backoff do not
        // take up window; a total cap stops an endless pile of them.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table name only.
        $counts = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS total, COALESCE(SUM(next_attempt_at <= %s), 0) AS due FROM {$table} WHERE run_id = %d AND status = %s",
            gmdate('Y-m-d H:i:s'), $run['id'], Auto_Alt_Text_Queue::STATUS_PENDING
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        if ((int) $counts->total >= self::FEED_PENDING_CAP_FACTOR * $window) {
            return 0;
        }
        $needed = $window - (int) $counts->due;
        if ($needed <= 0) {
            return 0;
        }

        $cursor = $run['cursor_id'];
        $enqueued = 0;
        $exhausted = false;
        $supported = Auto_Alt_Text_Image_Source::supported_input_mimes();
        $policy = Auto_Alt_Text_Library_Scanner::policy_for_coverage($run['coverage']);
        $deadline = microtime(true) + self::FEED_BUDGET_SECONDS;
        $runs = self::table();
        while ($needed > 0 && microtime(true) < $deadline) {
            // Stop as soon as the run is no longer running (cancelled or paused mid-feed).
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table name only.
            $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$runs} WHERE id = %d", $run['id']));
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            if (self::STATUS_RUNNING !== $status) {
                break;
            }
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_mime_type FROM {$wpdb->posts}
                 WHERE ID > %d AND ID <= %d AND post_type = 'attachment' AND post_mime_type LIKE %s
                 ORDER BY ID ASC LIMIT %d",
                $cursor, $run['max_attachment_id'], 'image/%', self::FEED_CHUNK
            ));
            if (empty($rows)) {
                $exhausted = true;
                break;
            }
            update_meta_cache('post', wp_list_pluck($rows, 'ID'));
            foreach ($rows as $row) {
                $id = (int) $row->ID;
                $cursor = $id;
                if (in_array($row->post_mime_type, $supported, true)
                    && Auto_Alt_Text_Provenance::allows_overwrite($id, $policy)
                    && $queue->enqueue_for_run($id, $run['id'])) {
                    $enqueued++;
                    if (--$needed <= 0) {
                        break;
                    }
                }
            }
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table name only.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$runs} SET cursor_id = GREATEST(cursor_id, %d), fed_all = GREATEST(fed_all, %d), updated_at = %s WHERE id = %d AND status IN (%s, %s)",
            $cursor, $exhausted ? 1 : 0, self::now(), $run['id'], self::STATUS_RUNNING, self::STATUS_PAUSED
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        return $enqueued;
    }

    /** Finishes the run once everything is fed and nothing is queued or in flight. */
    public static function maybe_finish(int $id): bool {
        global $wpdb;
        $run = self::get($id);
        if (null === $run || !$run['fed_all'] || !in_array($run['status'], [self::STATUS_RUNNING, self::STATUS_PAUSED], true)) {
            return false;
        }
        $table = Auto_Alt_Text_Queue::get_table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table name only.
        $open = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE run_id = %d AND status IN (%s, %s)",
            $id, Auto_Alt_Text_Queue::STATUS_PENDING, Auto_Alt_Text_Queue::STATUS_PROCESSING
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        if ($open > 0) {
            return false;
        }
        // A cancelled run reopened to retry its failures ends cancelled again.
        $final = self::STATUS_CANCELLED === ($run['summary']['reopened_from'] ?? null)
            ? self::STATUS_CANCELLED : self::STATUS_FINISHED;
        self::finalize($id, $final);
        $after = self::get($id);
        return null !== $after && $final === $after['status'];
    }

    /** Stamps the run's last runner tick. */
    public static function touch_tick(int $id): void {
        global $wpdb;
        $wpdb->update(self::table(), ['last_tick_at' => self::now()], ['id' => $id]);
    }

    public static function cost_so_far(int $id): float {
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table name and generated %d list only.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT model, SUM(input_tokens) AS in_tokens, SUM(output_tokens) AS out_tokens
             FROM {$wpdb->prefix}auto_alt_text_stats WHERE run_id = %d GROUP BY model",
            $id
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $cost = 0.0;
        foreach ($rows as $row) {
            $model = Auto_Alt_Text_Models::get((string) $row->model);
            if (null === $model) {
                continue;
            }
            $cost += (float) $row->in_tokens * $model['input_price'] + (float) $row->out_tokens * $model['output_price'];
        }
        return $cost / 1000000;
    }

    /** Closes an active run once; later calls are no-ops with no side effects. */
    public static function finalize(int $id, string $status): void {
        if (!self::close($id, $status)) {
            return;
        }
        if (self::STATUS_FINISHED === $status) {
            do_action('aat_library_run_finished', self::get($id));
            Auto_Alt_Text_Library_Scanner::request_recount();
        } elseif (self::STATUS_CANCELLED === $status) {
            // A cancelled run reopened for a retry and closed again.
            do_action('aat_library_run_cancelled', self::get($id));
            Auto_Alt_Text_Library_Scanner::request_recount();
        }
    }

    /**
     * Atomically moves an active run to a final status and stores its summary.
     *
     * @return bool True only for the call that changed the row.
     */
    private static function close(int $id, string $status): bool {
        global $wpdb;
        $run = self::get($id);
        if (null === $run || !in_array($run['status'], [self::STATUS_RUNNING, self::STATUS_PAUSED], true)) {
            return false;
        }
        $progress = self::progress($run);
        $remainder = 0;
        if (self::STATUS_FINISHED === $status) {
            // Images in the confirmed total that never became jobs (edited by a
            // person, deleted or unsupported since the count) are skipped.
            $remainder = max(0, $progress['total'] - $progress['filled'] - $progress['skipped'] - $progress['failed']);
            $progress['skipped'] += $remainder;
            $progress['done'] += $remainder;
        }
        $failures = [];
        $table = Auto_Alt_Text_Queue::get_table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table name and generated %d list only.
        $failed_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT attachment_id, last_error FROM {$table} WHERE run_id = %d AND status = %s ORDER BY id ASC LIMIT %d",
            $id, Auto_Alt_Text_Queue::STATUS_FAILED, self::MAX_FAILURES + 1
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $truncated = count($failed_rows) > self::MAX_FAILURES;
        foreach (array_slice($failed_rows, 0, self::MAX_FAILURES) as $row) {
            $failures[] = [
                'attachment_id' => (int) $row->attachment_id,
                'file'          => basename((string) get_attached_file((int) $row->attachment_id)),
                'reason'        => (string) $row->last_error,
            ];
        }
        $summary = array_merge($run['summary'], [
            'filled'   => $progress['filled'],
            'skipped'  => $progress['skipped'],
            'failed'   => $progress['failed'],
            'cost_usd' => self::cost_so_far($id),
            'failures' => $failures,
        ]);
        unset($summary['reopened_from']);
        if ($truncated) {
            $summary['failures_truncated'] = true;
        }
        $now = self::now();
        $runs = self::table();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table name and generated %d list only.
        $changed = $wpdb->query($wpdb->prepare(
            "UPDATE {$runs} SET status = %s, ended_at = %s, summary = %s, total = %d, fed_all = 1, skipped_at_feed = skipped_at_feed + %d, updated_at = %s WHERE id = %d AND status IN (%s, %s)",
            $status, $now, wp_json_encode($summary), $progress['total'], $remainder, $now, $id, self::STATUS_RUNNING, self::STATUS_PAUSED
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        return 1 === (int) $changed;
    }

    /** Re-queues the run's failed rows, except unsupported formats; returns the count. */
    public static function retry_failed(int $id): int {
        global $wpdb;
        $run = self::get($id);
        if (null === $run
            || !in_array($run['status'], [self::STATUS_FINISHED, self::STATUS_CANCELLED], true)
            || null !== self::active()) {
            return 0;
        }
        $table = Auto_Alt_Text_Queue::get_table_name();
        $now = self::now();
        $unsupported = array_unique([self::UNSUPPORTED_MESSAGE, __('Bildformatet stöds inte.', 'WP-Auto-Alt-Text')]);
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table name and generated %d list only.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, last_error FROM {$table} WHERE run_id = %d AND status = %s",
            $id, Auto_Alt_Text_Queue::STATUS_FAILED
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $ids = [];
        foreach ($rows as $row) {
            $retryable = true;
            foreach ($unsupported as $text) {
                if (false !== strpos((string) $row->last_error, $text)) {
                    $retryable = false;
                    break;
                }
            }
            if ($retryable) {
                $ids[] = (int) $row->id;
            }
        }
        if (empty($ids)) {
            return 0;
        }

        $runs = self::table();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table name and generated %d list only.
        $summary = $run['summary'];
        $summary['reopened_from'] = $run['status'];
        $flipped = $wpdb->query($wpdb->prepare(
            "UPDATE {$runs} SET status = %s, ended_at = NULL, summary = %s, updated_at = %s WHERE id = %d AND status IN (%s, %s)",
            self::STATUS_RUNNING, wp_json_encode($summary), $now, $id, self::STATUS_FINISHED, self::STATUS_CANCELLED
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        if (1 !== (int) $flipped) {
            return 0;
        }

        $count = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table name and generated %d list only.
            $count += (int) $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status = %s, attempts = 0, next_attempt_at = %s, updated_at = %s WHERE status = %s AND id IN ({$placeholders})",
                array_merge([Auto_Alt_Text_Queue::STATUS_PENDING, $now, $now, Auto_Alt_Text_Queue::STATUS_FAILED], $chunk)
            ));
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        }
        self::note_event($id, 'resume', 'retry');
        do_action('aat_library_run_resumed', self::get($id));
        return $count;
    }

    private static function transition(int $id, string $from, string $to): bool {
        global $wpdb;
        return 1 === (int) $wpdb->update(self::table(), [
            'status'     => $to,
            'updated_at' => self::now(),
        ], ['id' => $id, 'status' => $from]);
    }

    private static function blank_summary(): array {
        return ['filled' => 0, 'skipped' => 0, 'failed' => 0, 'cost_usd' => 0.0, 'failures' => [], 'events' => []];
    }

    private static function save_summary(int $id, array $summary): void {
        global $wpdb;
        $wpdb->update(self::table(), [
            'summary'    => wp_json_encode($summary),
            'updated_at' => self::now(),
        ], ['id' => $id]);
    }

    /** Casts a raw row: integer columns to int, summary decoded with defaults. */
    private static function hydrate(array $row): array {
        foreach (['id', 'started_by', 'total', 'max_attachment_id', 'cursor_id', 'fed_all', 'skipped_at_feed'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['estimate_usd'] = (float) $row['estimate_usd'];
        $summary = is_string($row['summary'] ?? null) ? json_decode($row['summary'], true) : null;
        $row['summary'] = array_merge(self::blank_summary(), is_array($summary) ? $summary : []);
        return $row;
    }
}
