<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Persistent generation queue. Jobs are enqueued by automatic triggers
 * (uploads, WooCommerce hooks) and processed in the background by the
 * cron runner, one API attempt per job per run.
 */
class Auto_Alt_Text_Queue {
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE       = 'done';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_SKIPPED    = 'skipped';

    /** Jobs fed by a library run; not an automatic type, so they run while auto-generation is off. */
    public const LIBRARY_TYPE = 'library';

    public const MAX_ATTEMPTS = 8;

    /** Explicit bulk-action types: owned by the bulk feed, never adopted by a library run. */
    public const BULK_TYPES = ['batch', 'woocommerce_bulk'];

    /** last_error of a job the user cancelled before it ran. */
    public const CANCELLED_ERROR = 'cancelled';

    /** Job types created by automatic triggers; paused while auto-generation is off. */
    public const AUTO_GENERATION_TYPES = ['upload', 'woocommerce', 'woocommerce_variation'];

    /** Set when OpenAI rejects the API key (HTTP 401); run() waits it out. */
    public const AUTH_PAUSE_TRANSIENT = 'aat_queue_auth_pause';
    private const AUTH_PAUSE_SECONDS = 15 * MINUTE_IN_SECONDS;

    /** @var callable|null */
    private $processor;

    /** Set by a batch that hit a quota, auth or rate-limit stop. */
    private bool $halted = false;

    /** @var callable|null Called after every job and batch of a budgeted run. */
    private $after_batch = null;

    /**
     * @param callable|null $processor Overridable job processor for testing.
     *                                 Signature: fn(int $attachment_id, string $generation_type, ?int $context_post_id): array
     *                                 returning ['status' => 'success'|'retry'|'failed'|'quota'|'auth'|'rate_limit', 'error' => ?string].
     */
    public function __construct(?callable $processor = null) {
        $this->processor = $processor;
    }

    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'aat_queue';
    }

    /**
     * Adds a job unless an active (pending/processing) job for the same
     * attachment already exists. A context hint (the post the image belongs
     * to, e.g. the saved product) replaces an existing job's hint; without
     * one the existing hint is kept.
     *
     * @return bool Whether a row was created.
     */
    public function enqueue($attachment_id, $generation_type, $context_post_id = null) {
        global $wpdb;
        // Lazy guard: the schema upgrade does not run on frontend requests.
        Auto_Alt_Text_Activator::maybe_upgrade(true);
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) {
            return false;
        }
        $context_post_id = $context_post_id ? (int) $context_post_id : null;

        $table = self::get_table_name();
        $active = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE attachment_id = %d AND status IN (%s, %s) LIMIT 1",
            $attachment_id, self::STATUS_PENDING, self::STATUS_PROCESSING
        ));
        if ($active) {
            $this->add_context_hint((int) $active, $context_post_id);
            return false;
        }

        $now = current_time('mysql', true);
        $inserted = $wpdb->insert($table, [
            'attachment_id'   => $attachment_id,
            'generation_type' => sanitize_key($generation_type),
            'context_post_id' => $context_post_id,
            'status'          => self::STATUS_PENDING,
            'attempts'        => 0,
            'next_attempt_at' => $now,
            'created_at'      => $now,
            'updated_at'      => $now,
        ], ['%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s']);

        return (bool) $inserted;
    }

    /**
     * Adds an attachment to a library run. A pending job without a run is
     * adopted by the run (re-typed as a library job); an active job that
     * already belongs to a run is left alone.
     *
     * @return bool Whether the attachment is now queued for this run.
     */
    public function enqueue_for_run(int $attachment_id, int $run_id): bool {
        global $wpdb;
        Auto_Alt_Text_Activator::maybe_upgrade(true);
        if ($attachment_id <= 0 || $run_id <= 0) {
            return false;
        }
        $table = self::get_table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table name only.
        $active = $wpdb->get_row($wpdb->prepare(
            "SELECT id, run_id, status, generation_type FROM {$table} WHERE attachment_id = %d AND status IN (%s, %s) LIMIT 1",
            $attachment_id, self::STATUS_PENDING, self::STATUS_PROCESSING
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        if ($active) {
            if (null !== $active->run_id) {
                // Already this run's job, or another run's: only a pending row of a run that is over is taken over.
                if ((int) $active->run_id === $run_id || self::STATUS_PENDING !== $active->status) {
                    return false;
                }
                $other = Auto_Alt_Text_Library_Run::get((int) $active->run_id);
                if (null !== $other && in_array($other['status'], [Auto_Alt_Text_Library_Run::STATUS_RUNNING, Auto_Alt_Text_Library_Run::STATUS_PAUSED], true)) {
                    return false;
                }
            } elseif (self::STATUS_PENDING !== $active->status || in_array($active->generation_type, self::BULK_TYPES, true)) {
                // A pending bulk row belongs to the bulk feed.
                return false;
            }
            $runs = Auto_Alt_Text_Activator::get_runs_table_name();
            $now = current_time('mysql', true);
            // Only while the run is running, checked in the same statement.
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table names only.
            return 1 === (int) $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET attempts = IF(run_id IS NULL, attempts, 0), last_error = IF(run_id IS NULL, last_error, NULL),
                 next_attempt_at = IF(run_id IS NULL, next_attempt_at, %s), run_id = %d, generation_type = %s, updated_at = %s
                 WHERE id = %d AND status = %s AND ((run_id IS NULL AND %d = 0) OR run_id = %d)
                 AND EXISTS (SELECT 1 FROM {$runs} WHERE id = %d AND status = %s)",
                $now, $run_id, self::LIBRARY_TYPE, $now, (int) $active->id, self::STATUS_PENDING,
                null === $active->run_id ? 0 : 1, (int) $active->run_id,
                $run_id, Auto_Alt_Text_Library_Run::STATUS_RUNNING
            ));
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        }

        // One row per attachment and run, whatever its status.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table name only.
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE attachment_id = %d AND run_id = %d LIMIT 1",
            $attachment_id, $run_id
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        if ($existing) {
            return false;
        }

        $now = current_time('mysql', true);
        $runs = Auto_Alt_Text_Activator::get_runs_table_name();
        // Insert only while the run is running, checked in the same statement.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table names only.
        return 1 === (int) $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (attachment_id, generation_type, run_id, status, attempts, next_attempt_at, created_at, updated_at)
             SELECT %d, %s, id, %s, 0, %s, %s, %s FROM {$runs} WHERE id = %d AND status = %s",
            $attachment_id, self::LIBRARY_TYPE, self::STATUS_PENDING, $now, $now, $now,
            $run_id, Auto_Alt_Text_Library_Run::STATUS_RUNNING
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
    }

    /**
     * Queues an explicit bulk-action job for an attachment and returns the job
     * that now carries it. An active job is taken over, not duplicated: a
     * pending automatic job is re-typed, a pending library-run job is released
     * from its run and reset; a processing job is only tracked.
     *
     * @return array{job_id:int,new:bool}|null Null for an invalid attachment id (or a failed insert).
     */
    public function enqueue_bulk(int $attachment_id, string $generation_type = 'batch'): ?array {
        global $wpdb;
        Auto_Alt_Text_Activator::maybe_upgrade(true);
        if ($attachment_id <= 0) {
            return null;
        }
        $generation_type = sanitize_key($generation_type);
        $table = self::get_table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table name only.
        $active = $wpdb->get_row($wpdb->prepare(
            "SELECT id, run_id, status, generation_type FROM {$table} WHERE attachment_id = %d AND status IN (%s, %s) LIMIT 1",
            $attachment_id, self::STATUS_PENDING, self::STATUS_PROCESSING
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        if ($active) {
            $job_id = (int) $active->id;
            if (self::STATUS_PENDING !== $active->status) {
                return ['job_id' => $job_id, 'new' => false];
            }
            $now = current_time('mysql', true);
            if (null === $active->run_id) {
                if ($active->generation_type !== $generation_type) {
                    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table name only.
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$table} SET generation_type = %s, updated_at = %s WHERE id = %d AND status = %s AND run_id IS NULL",
                        $generation_type, $now, $job_id, self::STATUS_PENDING
                    ));
                    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                }
            } else {
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table name only.
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET run_id = NULL, generation_type = %s, attempts = 0, last_error = NULL, next_attempt_at = %s, updated_at = %s
                     WHERE id = %d AND status = %s AND run_id IS NOT NULL",
                    $generation_type, $now, $now, $job_id, self::STATUS_PENDING
                ));
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            }
            // A guarded UPDATE that matched nothing means the row was claimed meanwhile: still the same job.
            return ['job_id' => $job_id, 'new' => false];
        }

        $now = current_time('mysql', true);
        $inserted = $wpdb->insert($table, [
            'attachment_id'   => $attachment_id,
            'generation_type' => $generation_type,
            'status'          => self::STATUS_PENDING,
            'attempts'        => 0,
            'next_attempt_at' => $now,
            'created_at'      => $now,
            'updated_at'      => $now,
        ], ['%d', '%s', '%s', '%d', '%s', '%s', '%s']);

        return $inserted ? ['job_id' => (int) $wpdb->insert_id, 'new' => true] : null;
    }

    /**
     * Skips pending bulk jobs the user cancelled. Only pending, run-less rows of
     * a bulk type are touched; a processing job finishes, a run job belongs to its run.
     *
     * @param array<int> $job_ids
     * @return int Rows changed.
     */
    public function cancel_pending(array $job_ids): int {
        global $wpdb;
        $job_ids = array_values(array_unique(array_filter(array_map('intval', $job_ids), static function (int $id): bool {
            return $id > 0;
        })));
        if (empty($job_ids)) {
            return 0;
        }
        $table = self::get_table_name();
        $id_placeholders   = implode(', ', array_fill(0, count($job_ids), '%d'));
        $type_placeholders = implode(', ', array_fill(0, count(self::BULK_TYPES), '%s'));
        $args = array_merge(
            [self::STATUS_SKIPPED, self::CANCELLED_ERROR, current_time('mysql', true)],
            $job_ids,
            [self::STATUS_PENDING],
            self::BULK_TYPES
        );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table name and generated placeholders only.
        return (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, last_error = %s, updated_at = %s
             WHERE id IN ({$id_placeholders}) AND status = %s AND run_id IS NULL AND generation_type IN ({$type_placeholders})",
            $args
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
    }

    /** Number of pending bulk jobs that are due now (the bulk feed's work). */
    public static function due_bulk_count(): int {
        global $wpdb;
        $table = self::get_table_name();
        $type_placeholders = implode(', ', array_fill(0, count(self::BULK_TYPES), '%s'));
        $args = array_merge([self::STATUS_PENDING], self::BULK_TYPES, [current_time('mysql', true)]);
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table name and generated placeholders only.
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE status = %s AND run_id IS NULL AND generation_type IN ({$type_placeholders}) AND next_attempt_at <= %s",
            $args
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
    }

    /** Sets a job's context hint when a new one is given; the newest hint wins. */
    private function add_context_hint($job_id, $context_post_id) {
        if (!$context_post_id) {
            return;
        }
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::get_table_name() . ' SET context_post_id = %d WHERE id = %d',
            $context_post_id, $job_id
        ));
    }

    /**
     * Re-labels an attachment's pending job, e.g. so an explicit bulk request
     * for an image whose automatic job is paused runs anyway.
     *
     * @return bool Whether the attachment has a pending job, now of that type.
     */
    public function promote_pending($attachment_id, $generation_type, $context_post_id = null) {
        global $wpdb;
        $table = self::get_table_name();
        $generation_type = sanitize_key($generation_type);
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table name only.
        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT id, generation_type FROM {$table} WHERE attachment_id = %d AND status = %s AND run_id IS NULL LIMIT 1",
            (int) $attachment_id, self::STATUS_PENDING
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        if (!$job) {
            return false;
        }
        if ($job->generation_type !== $generation_type) {
            $wpdb->update($table, [
                'generation_type' => $generation_type,
                'updated_at'      => current_time('mysql', true),
            ], ['id' => (int) $job->id, 'status' => self::STATUS_PENDING]);
        }
        $this->add_context_hint((int) $job->id, $context_post_id ? (int) $context_post_id : null);
        return true;
    }

    private const STUCK_MINUTES   = 15;
    private const PRUNE_DONE_DAYS = 7;
    private const PRUNE_FAIL_DAYS = 30;

    /**
     * Cron entry point: recover stuck jobs, prune old rows, then claim and
     * process a bounded batch. One API attempt per job — retry timing lives
     * in next_attempt_at, never in sleep().
     *
     * With a time budget (seconds) batches repeat until it is spent, nothing
     * is due or a quota, auth or rate-limit stop happens. The budget is
     * checked between batches, so a started batch always finishes.
     * Maintenance runs once per call, not per batch.
     *
     * @param callable|null $after_batch Called after every job and after every batch (e.g. to refresh a lock).
     * @return int Number of jobs processed.
     */
    public function run(?float $time_budget = null, ?callable $after_batch = null): int {
        $run = Auto_Alt_Text_Library_Run::active();
        if (null !== $run) {
            Auto_Alt_Text_Library_Run::feed(Auto_Alt_Text_Library_Run::feed_window());
        }
        $this->after_batch = $after_batch;
        $deadline = null !== $time_budget ? microtime(true) + $time_budget : null;
        $total = 0;
        if ($this->start_tick()) {
            do {
                $this->halted = false;
                $count = $this->run_batch();
                $total += $count;
                if (null !== $after_batch) {
                    call_user_func($after_batch);
                }
            } while ($count > 0 && !$this->halted && null !== $deadline && microtime(true) < $deadline);
        }
        if (null !== $run) {
            Auto_Alt_Text_Library_Run::maybe_finish($run['id']);
            // start_tick() bails without a key: that tick is no progress, and
            // leaving the stamp lets health() report the stalled run.
            if (self::has_api_key()) {
                Auto_Alt_Text_Library_Run::touch_tick($run['id']);
            }
        }
        return $total;
    }

    /** Whether the tick may process jobs; does the once-per-tick maintenance. */
    private function start_tick(): bool {
        global $wpdb;
        // Idle fast-path: an empty table needs no maintenance writes.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Internal table name only.
        if (!$wpdb->get_var('SELECT id FROM ' . self::get_table_name() . ' LIMIT 1')) {
            return false;
        }

        // Without a working API key every job would burn its attempts and
        // fail: leave the backlog pending until a key is set or the pause ends.
        if (!self::has_api_key() || get_transient(self::AUTH_PAUSE_TRANSIENT)) {
            return false;
        }

        $this->recover_stuck_jobs();
        $this->prune();
        $this->skip_orphaned_run_jobs();
        return true;
    }

    /**
     * @phpstan-impure
     * @return int Jobs processed by this batch; sets $halted on a stop.
     */
    private function run_batch(): int {
        $probing = false;
        if (Auto_Alt_Text_Quota_Monitor::is_active()) {
            if (!Auto_Alt_Text_Quota_Monitor::should_probe()) {
                $this->halted = true;
                return 0; // Paused: jobs wait as pending; backlog drains after recovery.
            }
            $probing = true;
        }

        $batch_size = $probing ? 1 : max(1, (int) apply_filters('aat_queue_batch_size', 5));
        $rate_limiter = new Auto_Alt_Text_Rate_Limiter();
        // The auto-generate toggle pauses jobs created by automatic triggers:
        // they stay pending until it is re-enabled. Explicit jobs (bulk
        // actions) are user requests and always run.
        $excluded_types = get_option('auto_alt_text_auto_generate', true) ? [] : self::AUTO_GENERATION_TYPES;
        $active_run = Auto_Alt_Text_Library_Run::active();
        $run_id = (null !== $active_run && Auto_Alt_Text_Library_Run::STATUS_RUNNING === $active_run['status'])
            ? $active_run['id'] : null;
        $jobs = $this->claim_batch($batch_size, $excluded_types, $run_id);

        $processed = 0;
        $unprocessed_ids = wp_list_pluck($jobs, 'id');
        foreach ($jobs as $job) {
            if (!$rate_limiter->can_make_request()) {
                $this->halted = true;
                break; // Rate limited: stop, remaining claimed jobs are released below.
            }
            // This job is about to be processed; drop it from the release list
            // regardless of outcome (process_job() always finalizes or reschedules it).
            $unprocessed_ids = array_diff($unprocessed_ids, [$job->id]);
            $processed++;
            $continue = $this->process_job($job);
            if (null !== $this->after_batch) {
                call_user_func($this->after_batch);
            }
            if (!$continue) {
                $this->halted = true;
                break; // quota or rate-limit stop: remaining claimed jobs are released below.
            }
        }

        if (!empty($unprocessed_ids)) {
            $this->release_claimed($unprocessed_ids);
        }
        return $processed;
    }

    /**
     * Claims due pending jobs one row at a time so ownership is unambiguous
     * under concurrent runners: a per-row UPDATE guarded by status='pending'
     * either affects exactly one row (we own it) or zero (another runner
     * already claimed it, so we skip it).
     *
     * @return array<object> Rows this call successfully claimed.
     */
    private function claim_batch($batch_size, array $excluded_types = [], ?int $run_id = null) {
        // Uploads and other explicit jobs first, then the active run's backlog.
        $owned_ids = $this->claim_rows('run_id IS NULL', [], $batch_size, $excluded_types);
        if (null !== $run_id && count($owned_ids) < $batch_size) {
            $owned_ids = array_merge(
                $owned_ids,
                $this->claim_rows('run_id = %d', [$run_id], $batch_size - count($owned_ids), [])
            );
        }
        if (empty($owned_ids)) {
            return [];
        }

        global $wpdb;
        $table = self::get_table_name();
        $placeholders = implode(',', array_fill(0, count($owned_ids), '%d'));
        // No status filter needed here: we just claimed these rows above, so
        // we own them regardless of what a concurrent runner does next.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table name and generated placeholder list only.
        $jobs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id IN ({$placeholders}) ORDER BY FIELD(id, {$placeholders})",
            array_merge($owned_ids, $owned_ids)
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        return $jobs;
    }

    /**
     * @param string       $scope    SQL condition on run_id, with %d placeholders for $scope_args.
     * @param array<int>   $scope_args
     * @return array<int> Ids of the rows this call claimed.
     */
    private function claim_rows($scope, array $scope_args, $limit, array $excluded_types) {
        global $wpdb;
        $table = self::get_table_name();
        $now = current_time('mysql', true);

        $type_clause = '';
        $params = array_merge([self::STATUS_PENDING, $now], $scope_args);
        if (!empty($excluded_types)) {
            $type_clause = ' AND generation_type NOT IN (' . implode(',', array_fill(0, count($excluded_types), '%s')) . ')';
            $params = array_merge($params, array_values($excluded_types));
        }
        $params[] = (int) $limit;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table name and fixed scope fragments only.
        $candidate_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table} WHERE status = %s AND next_attempt_at <= %s AND {$scope}{$type_clause}
             ORDER BY next_attempt_at ASC, id ASC LIMIT %d",
            $params
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        $owned_ids = [];
        foreach ($candidate_ids as $id) {
            $id = (int) $id;
            $affected = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status = %s, updated_at = %s WHERE id = %d AND status = %s",
                self::STATUS_PROCESSING, $now, $id, self::STATUS_PENDING
            ));
            if ($affected) {
                $owned_ids[] = $id;
            }
        }
        return $owned_ids;
    }

    /**
     * Processes one claimed job.
     *
     * Outcomes: success finishes the job; quota and auth release it untouched and
     * stop the run; a rate limit (429) puts it back to pending without using an
     * attempt, scheduled after the limiter's reset, and stops the run; any other
     * transient error retries with backoff and uses an attempt.
     *
     * @return bool False when the whole run must stop (quota exhausted / auth / rate limited).
     */
    private function process_job($job) {
        $attachment_id = (int) $job->attachment_id;
        $run_id = isset($job->run_id) ? (int) $job->run_id : null;
        $run = null;

        if (null !== $run_id) {
            // A run job is processed only while its run is active and not
            // paused by the user. A paused run keeps its job for resume; a
            // run that is over (cancelled, finished) skips it.
            $run = Auto_Alt_Text_Library_Run::get($run_id);
            if (null === $run || !in_array($run['status'], [Auto_Alt_Text_Library_Run::STATUS_RUNNING, Auto_Alt_Text_Library_Run::STATUS_PAUSED], true)) {
                $this->finish($job, self::STATUS_SKIPPED);
                return true;
            }
            if (Auto_Alt_Text_Library_Run::STATUS_RUNNING !== $run['status']) {
                $this->update_job($job->id, ['status' => self::STATUS_PENDING]);
                return true;
            }
        }

        // Attachment gone → nothing to do.
        if (!get_post($attachment_id)) {
            $this->finish($job, null !== $run_id ? self::STATUS_SKIPPED : self::STATUS_DONE);
            return true;
        }

        // Automatic jobs only fill missing alt text; explicit jobs (bulk
        // actions) follow the overwrite setting; library jobs follow their
        // run's coverage and never touch an unsupported format. Checked at
        // claim time, so an edit or a setting change made while the job
        // waited is respected: text a person wrote is never overwritten.
        // An edit saved in another request never clears this request's meta
        // cache (feed() and earlier jobs fill it), so read the meta fresh.
        wp_cache_delete($attachment_id, 'post_meta');
        if (null !== $run) {
            if (!Auto_Alt_Text_Library_Scanner::is_candidate($attachment_id, $run['coverage'])) {
                $this->finish($job, self::STATUS_SKIPPED);
                return true;
            }
            $policy = Auto_Alt_Text_Library_Scanner::policy_for_coverage($run['coverage']);
        } else {
            $policy = in_array($job->generation_type, self::AUTO_GENERATION_TYPES, true)
                ? Auto_Alt_Text_Provenance::POLICY_MISSING
                : Auto_Alt_Text_Provenance::default_policy();
            if (!Auto_Alt_Text_Provenance::allows_overwrite($attachment_id, $policy)) {
                // An explicit request that was not carried out is skipped; an automatic job is simply done.
                $this->finish($job, in_array($job->generation_type, self::AUTO_GENERATION_TYPES, true) ? self::STATUS_DONE : self::STATUS_SKIPPED);
                return true;
            }
        }

        $processor = $this->processor ?: [$this, 'default_processor'];
        try {
            // The write-time guard: the generator re-checks the policy and that
            // the alt text is still what it was when the job was claimed.
            Auto_Alt_Text_Run_Context::set_write_guard($policy, trim((string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true)));
            if (null !== $run_id) {
                Auto_Alt_Text_Run_Context::set($run_id);
            }
            $context_post_id = isset($job->context_post_id) ? (int) $job->context_post_id : 0;
            $result = call_user_func($processor, $attachment_id, $job->generation_type, $context_post_id ?: null);
        } catch (Throwable $e) {
            // A fatal inside the processor (e.g. missing AVIF support in GD)
            // must never kill the cron tick or strand the job in 'processing'
            // with a frozen attempt counter; route it through normal backoff.
            Auto_Alt_Text_Logger::log('Queue processor crashed', 'error', [
                'attachment_id' => $attachment_id,
                'error'         => $e->getMessage(),
            ]);
            $result = ['status' => 'retry', 'error' => $e->getMessage()];
        } finally {
            Auto_Alt_Text_Run_Context::set_write_guard(null, null);
            if (null !== $run_id) {
                Auto_Alt_Text_Run_Context::set(null);
            }
        }
        $status = is_array($result) ? ($result['status'] ?? 'failed') : 'failed';
        $error  = is_array($result) ? ($result['error'] ?? null) : null;

        switch ($status) {
            case 'success':
                $this->finish($job, self::STATUS_DONE);
                $this->note_run_resume();
                return true;

            case 'skipped':
                // The write-time guard found text a person wrote: nothing was stored.
                $this->finish($job, self::STATUS_SKIPPED);
                return true;

            case 'quota':
                // Job untouched: release back to pending, stop the run.
                $this->note_run_pause('quota');
                $this->update_job($job->id, ['status' => self::STATUS_PENDING]);
                return false;

            case 'auth':
                // API key rejected: every job would fail the same way. Pause the
                // queue (a new key or any successful call lifts it early), leave
                // the job untouched and stop the run.
                set_transient(self::AUTH_PAUSE_TRANSIENT, 1, self::AUTH_PAUSE_SECONDS);
                $this->note_run_pause('auth');
                $this->update_job($job->id, ['status' => self::STATUS_PENDING]);
                return false;

            case 'rate_limit':
                // A 429 says nothing about this job: keep its attempts, wait for
                // the limiter's reset (30 s to 1 h) and stop the run.
                $delay = (int) ceil((new Auto_Alt_Text_Rate_Limiter())->get_recommended_delay());
                $delay = max(30, min(HOUR_IN_SECONDS, $delay));
                $this->update_job($job->id, [
                    'status'          => self::STATUS_PENDING,
                    'last_error'      => (string) $error,
                    'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + $delay),
                ]);
                return false;

            case 'retry':
                $attempts = (int) $job->attempts + 1;
                if ($attempts >= self::MAX_ATTEMPTS) {
                    $this->finish($job, self::STATUS_FAILED, $error);
                } else {
                    $this->update_job($job->id, [
                        'status'          => self::STATUS_PENDING,
                        'attempts'        => $attempts,
                        'last_error'      => (string) $error,
                        'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + self::retry_delay($attempts)),
                    ]);
                }
                return true;

            case 'failed':
            default:
                $this->finish($job, self::STATUS_FAILED, $error);
                return true;
        }
    }

    /**
     * Pending jobs of a run that is over (cancelled, or finished while a job
     * was released back) are skipped, never processed.
     */
    private function skip_orphaned_run_jobs(): void {
        global $wpdb;
        $table = self::get_table_name();
        $runs = Auto_Alt_Text_Activator::get_runs_table_name();
        // One guarded statement: a run reopened by retry_failed() is running
        // again and its rows are never swept.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table names only.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} q LEFT JOIN {$runs} r ON r.id = q.run_id
             SET q.status = %s, q.updated_at = %s
             WHERE q.status = %s AND q.run_id IS NOT NULL AND (r.id IS NULL OR r.status IN (%s, %s))",
            self::STATUS_SKIPPED, current_time('mysql', true), self::STATUS_PENDING,
            Auto_Alt_Text_Library_Run::STATUS_CANCELLED, Auto_Alt_Text_Library_Run::STATUS_FINISHED
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
    }

    /**
     * Records a quota or auth stop on the active library run. A new incident
     * fires aat_library_run_paused once; a repeat of the last event does not.
     * A run the user paused has already announced its pause.
     */
    private function note_run_pause(string $reason): void {
        $run = Auto_Alt_Text_Library_Run::active();
        if (null === $run || !Auto_Alt_Text_Library_Run::note_event($run['id'], 'pause', $reason)) {
            return;
        }
        if (Auto_Alt_Text_Library_Run::STATUS_RUNNING === $run['status']) {
            do_action('aat_library_run_paused', Auto_Alt_Text_Library_Run::get($run['id']), $reason);
        }
    }

    /** After a quota or auth pause, the first success marks the run as resumed and fires aat_library_run_resumed. */
    private function note_run_resume(): void {
        $run = Auto_Alt_Text_Library_Run::active();
        if (null === $run || Auto_Alt_Text_Library_Run::STATUS_RUNNING !== $run['status']) {
            return;
        }
        $events = $run['summary']['events'];
        $last = end($events);
        if (false !== $last && 'pause' === $last['type'] && in_array($last['reason'], ['quota', 'auth'], true)
            && Auto_Alt_Text_Library_Run::note_event($run['id'], 'resume')) {
            do_action('aat_library_run_resumed', Auto_Alt_Text_Library_Run::get($run['id']));
        }
    }

    /**
     * Default processor: one real OpenAI attempt. Returns a status the queue
     * understands: success, quota, auth, rate_limit (429, not counted as an
     * attempt), failed (permanent) or retry (transient, counted).
     */
    private function default_processor($attachment_id, $generation_type, $context_post_id = null) {
        $openai = new Auto_Alt_Text_OpenAI();
        $openai->set_max_api_attempts(1);
        $openai->set_context_post_id($context_post_id);
        $alt_text = $openai->generate_alt_text(
            wp_get_attachment_url($attachment_id),
            $attachment_id,
            $generation_type
        );

        if (!empty($alt_text)) {
            return ['status' => 'success', 'error' => null];
        }

        $class = $openai->get_last_error_class();
        $map = ['quota' => 'quota', 'auth' => 'auth', 'rate_limit' => 'rate_limit', 'permanent' => 'failed', 'skipped' => 'skipped'];
        return [
            'status' => $map[$class] ?? 'retry',
            'error'  => $openai->get_last_error(),
        ];
    }

    /** Backoff after the given number of attempts: 2^n minutes, capped at 6 hours. */
    private static function retry_delay($attempts) {
        return min((int) pow(2, $attempts) * MINUTE_IN_SECONDS, 6 * HOUR_IN_SECONDS);
    }

    /** Lifts the 401 pause: hooked to API key changes, called on API success. */
    public static function clear_auth_pause() {
        delete_transient(self::AUTH_PAUSE_TRANSIENT);
    }

    public static function has_api_key() {
        if (defined('AUTO_ALT_TEXT_OPENAI_API_KEY') && AUTO_ALT_TEXT_OPENAI_API_KEY) {
            return true;
        }
        return '' !== (string) get_option('auto_alt_text_api_key', '');
    }

    private function finish($job, $status, $error = null) {
        $data = ['status' => $status];
        if (null !== $error) {
            $data['last_error'] = (string) $error;
        }
        $this->update_job($job->id, $data);
    }

    private function update_job($id, array $data) {
        global $wpdb;
        $data['updated_at'] = current_time('mysql', true);
        $wpdb->update(self::get_table_name(), $data, ['id' => (int) $id]);
    }

    /**
     * Releases specific jobs claimed by this run but never processed (early
     * stop, e.g. quota exhausted mid-batch). Scoped to the exact ids this
     * run claimed, so a concurrent runner's own in-flight jobs are untouched.
     *
     * @param array<int> $ids
     */
    private function release_claimed(array $ids) {
        global $wpdb;
        if (empty($ids)) {
            return;
        }
        $ids = array_map('intval', $ids);
        $table = self::get_table_name();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, updated_at = %s WHERE status = %s AND id IN ({$placeholders})",
            array_merge([self::STATUS_PENDING, current_time('mysql', true), self::STATUS_PROCESSING], $ids)
        ));
    }

    /**
     * Recovers processing rows older than STUCK_MINUTES (the runner died
     * mid-job, e.g. a fatal or memory exhaustion). That counts as a used
     * attempt with the normal backoff, so a job that kills the runner every
     * time ends up failed instead of being retried first on every tick.
     */
    private function recover_stuck_jobs() {
        global $wpdb;
        $table = self::get_table_name();
        $threshold = gmdate('Y-m-d H:i:s', time() - self::STUCK_MINUTES * MINUTE_IN_SECONDS);
        $stuck = $wpdb->get_results($wpdb->prepare(
            "SELECT id, attempts, updated_at FROM {$table} WHERE status = %s AND updated_at < %s",
            self::STATUS_PROCESSING, $threshold
        ));

        foreach ($stuck as $job) {
            $attempts = (int) $job->attempts + 1;
            $data = [
                'attempts'   => $attempts,
                'last_error' => 'Processing timed out (recovered stuck job)',
                'updated_at' => current_time('mysql', true),
            ];
            if ($attempts >= self::MAX_ATTEMPTS) {
                $data['status'] = self::STATUS_FAILED;
            } else {
                $data['status'] = self::STATUS_PENDING;
                $data['next_attempt_at'] = gmdate('Y-m-d H:i:s', time() + self::retry_delay($attempts));
            }
            // Guarded on the row as read, so a concurrent runner recovering
            // the same job cannot count the attempt twice.
            $wpdb->update($table, $data, [
                'id'         => (int) $job->id,
                'status'     => self::STATUS_PROCESSING,
                'updated_at' => $job->updated_at,
            ]);
        }
    }

    /**
     * Prunes done and skipped rows older than 7 days and failed rows older
     * than 30 days. Rows of the latest run are kept: its progress and report
     * are counted from them, also after a late retry.
     */
    private function prune() {
        global $wpdb;
        $table = self::get_table_name();
        $run = Auto_Alt_Text_Library_Run::latest();
        $keep = null !== $run ? $wpdb->prepare(' AND (run_id IS NULL OR run_id <> %d)', $run['id']) : '';
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table name and a prepared fragment only.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE status IN (%s, %s) AND updated_at < %s{$keep}",
            self::STATUS_DONE, self::STATUS_SKIPPED, gmdate('Y-m-d H:i:s', time() - self::PRUNE_DONE_DAYS * DAY_IN_SECONDS)
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE status = %s AND updated_at < %s{$keep}",
            self::STATUS_FAILED, gmdate('Y-m-d H:i:s', time() - self::PRUNE_FAIL_DAYS * DAY_IN_SECONDS)
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
    }

    /** @return array{pending:int,processing:int,failed:int,done:int,skipped:int} */
    public function get_counts() {
        global $wpdb;
        $table = self::get_table_name();
        $counts = ['pending' => 0, 'processing' => 0, 'failed' => 0, 'done' => 0, 'skipped' => 0];
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name only.
        $rows = $wpdb->get_results("SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status");
        foreach ($rows as $row) {
            if (isset($counts[$row->status])) {
                $counts[$row->status] = (int) $row->n;
            }
        }
        return $counts;
    }

    /** @return array<object> */
    public function get_jobs($status = null, $limit = 50) {
        global $wpdb;
        $table = self::get_table_name();
        $limit = max(1, (int) $limit);
        if ($status) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = %s ORDER BY next_attempt_at ASC, id ASC LIMIT %d",
                sanitize_key($status), $limit
            ));
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY next_attempt_at ASC, id ASC LIMIT %d", $limit
        ));
    }

    /** @return int Number of failed jobs re-queued. */
    public function retry_failed() {
        global $wpdb;
        $table = self::get_table_name();
        $now = current_time('mysql', true);
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table name only.
        return (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, attempts = 0, next_attempt_at = %s, updated_at = %s WHERE status = %s AND run_id IS NULL",
            self::STATUS_PENDING, $now, $now, self::STATUS_FAILED
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
    }
}
