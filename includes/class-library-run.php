<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Interpolated values are internal table names and generated %d placeholder lists only.

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

    private const UNSUPPORTED_MESSAGE = 'Bildformatet stöds inte.';

    private static function table(): string {
        return Auto_Alt_Text_Activator::get_runs_table_name();
    }

    private static function now(): string {
        return gmdate('Y-m-d H:i:s');
    }

    /** @return array<string,mixed>|WP_Error */
    public static function start(string $coverage, int $user_id) {
        global $wpdb;
        if (null !== self::active()) {
            return new WP_Error('aat_library_run_active', __('Det pågår redan en körning.', 'WP-Auto-Alt-Text'));
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
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE status IN (%s, %s) ORDER BY id DESC LIMIT 1",
            self::STATUS_RUNNING, self::STATUS_PAUSED
        ), ARRAY_A);
        return $row ? self::hydrate($row) : null;
    }

    public static function latest(): ?array {
        global $wpdb;
        $table = self::table();
        $row = $wpdb->get_row("SELECT * FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A);
        return $row ? self::hydrate($row) : null;
    }

    public static function get(int $id): ?array {
        global $wpdb;
        $table = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
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
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE run_id = %d AND status = %s",
            $id, Auto_Alt_Text_Queue::STATUS_PENDING
        ));
        self::finalize($id, self::STATUS_CANCELLED);
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
            return [
                'total'   => $run['total'],
                'filled'  => $filled,
                'skipped' => $skipped,
                'failed'  => $failed,
                'pending' => 0,
                'done'    => $filled + $skipped + $failed,
            ];
        }
        $table = Auto_Alt_Text_Queue::get_table_name();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) AS n FROM {$table} WHERE run_id = %d GROUP BY status",
            $run['id']
        ), OBJECT_K);
        $n = static fn(string $status): int => isset($rows[$status]) ? (int) $rows[$status]->n : 0;
        $filled = $n(Auto_Alt_Text_Queue::STATUS_DONE);
        $skipped = $n(Auto_Alt_Text_Queue::STATUS_SKIPPED) + $run['skipped_at_feed'];
        $failed = $n(Auto_Alt_Text_Queue::STATUS_FAILED);
        return [
            'total'   => $run['total'],
            'filled'  => $filled,
            'skipped' => $skipped,
            'failed'  => $failed,
            'pending' => $n(Auto_Alt_Text_Queue::STATUS_PENDING) + $n(Auto_Alt_Text_Queue::STATUS_PROCESSING),
            'done'    => $filled + $skipped + $failed,
        ];
    }

    public static function cost_so_far(int $id): float {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT model, SUM(input_tokens) AS in_tokens, SUM(output_tokens) AS out_tokens
             FROM {$wpdb->prefix}auto_alt_text_stats WHERE run_id = %d GROUP BY model",
            $id
        ));
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

    public static function finalize(int $id, string $status): void {
        global $wpdb;
        $run = self::get($id);
        if (null === $run) {
            return;
        }
        $progress = self::progress($run);
        $failures = [];
        $table = Auto_Alt_Text_Queue::get_table_name();
        $failed_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT attachment_id, last_error FROM {$table} WHERE run_id = %d AND status = %s ORDER BY id ASC",
            $id, Auto_Alt_Text_Queue::STATUS_FAILED
        ));
        foreach ($failed_rows as $row) {
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
        $now = self::now();
        $wpdb->update(self::table(), [
            'status'     => $status,
            'ended_at'   => $now,
            'summary'    => wp_json_encode($summary),
            'updated_at' => $now,
        ], ['id' => $id]);

        if (self::STATUS_FINISHED === $status) {
            do_action('aat_library_run_finished', self::get($id));
            Auto_Alt_Text_Library_Scanner::request_recount();
        }
    }

    /** Re-queues the run's failed rows, except unsupported formats; returns the count. */
    public static function retry_failed(int $id): int {
        global $wpdb;
        $table = Auto_Alt_Text_Queue::get_table_name();
        $now = self::now();
        $unsupported = array_unique([self::UNSUPPORTED_MESSAGE, __('Bildformatet stöds inte.', 'WP-Auto-Alt-Text')]);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, last_error FROM {$table} WHERE run_id = %d AND status = %s",
            $id, Auto_Alt_Text_Queue::STATUS_FAILED
        ));
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
        $count = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $count += (int) $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status = %s, attempts = 0, next_attempt_at = %s, updated_at = %s WHERE status = %s AND id IN ({$placeholders})",
                array_merge([Auto_Alt_Text_Queue::STATUS_PENDING, $now, $now, Auto_Alt_Text_Queue::STATUS_FAILED], $chunk)
            ));
        }
        $wpdb->update(self::table(), [
            'status'     => self::STATUS_RUNNING,
            'ended_at'   => null,
            'updated_at' => $now,
        ], ['id' => $id]);
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
