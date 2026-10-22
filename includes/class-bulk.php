<?php
/**
 * Per-user bulk generation: request, tracking, progress, cancel and dismiss.
 *
 * One tracked bulk per user, in user meta `aat_bulk`:
 * { started_at, finished_at|null, job_ids: int[] }. The jobs are ordinary
 * queue rows of type `batch`; this class only owns the tracking.
 */
class Auto_Alt_Text_Bulk {
    public const META_KEY        = 'aat_bulk';
    public const MAX_PER_REQUEST = 1000;
    public const MAX_TRACKED     = 5000;
    public const FINISHED_TTL    = DAY_IN_SECONDS;
    public const STALL_SECONDS   = 90;
    public const FAILURES_SHOWN  = 20;

    private const FALLBACK_MESSAGE = 'Det gick inte att generera alt-text för bilden.';

    /**
     * Known stored `last_error` texts, by their English source, mapped to a
     * Swedish reason. Each is matched as an English source text and as its
     * translation. A msgid with %s is matched by its longest literal part.
     *
     * @var array<string, array<int, string>>
     */
    private const FAILURE_MAP = [
        'Bilden kunde inte läsas.' => [
            'The image file could not be found.',
            'The image could not be downloaded. Please try again.',
            'The image could not be prepared.',
            'Invalid image source',
        ],
        'Bildformatet stöds inte.' => [
            'Bildformatet stöds inte.',
        ],
        'OpenAI svarade inte i tid.' => [
            'Network error while contacting OpenAI. Please check server connectivity and try again.',
            'Processing timed out (recovered stuck job)',
        ],
        'OpenAI gav inget användbart svar.' => [
            'OpenAI returned an empty response. Please try again.',
            'The model ran out of output tokens.',
            'The model stopped before finishing the alt text.',
        ],
        'OpenAI ville inte beskriva bilden.' => [
            'The model declined to describe this image.',
        ],
        'Modellen är inte tillgänglig för API-nyckeln.' => [
            'The model %s is not available to this OpenAI API key. Choose another model under Auto Alt Text → Settings.',
        ],
    ];

    /**
     * Queues the given attachments as bulk jobs for a user and tracks them.
     *
     * @param array<int|string> $attachment_ids
     * @return array{queued:int,already_queued:int,rejected:array<string,int>,bulk:?array}|WP_Error
     */
    public static function request(int $user_id, array $attachment_ids) {
        $ids = array_values(array_unique(array_filter(array_map('absint', $attachment_ids))));
        if (empty($ids)) {
            return new WP_Error('aat_bulk_empty', 'Markera minst en bild.', ['status' => 400]);
        }
        if (count($ids) > self::MAX_PER_REQUEST) {
            return new WP_Error('aat_bulk_too_many', __('Markera högst 1 000 bilder åt gången. Använd Hela biblioteket för fler.', 'WP-Auto-Alt-Text'), ['status' => 400]);
        }
        if (!Auto_Alt_Text_Queue::has_api_key()) {
            return new WP_Error('aat_bulk_no_key', __('Ange en API-nyckel under Inställningar → Anslutning för att generera alt-text.', 'WP-Auto-Alt-Text'), ['status' => 409]);
        }

        $existing = self::load($user_id);
        if (null !== $existing && null === $existing['finished_at'] && count($existing['job_ids']) + count($ids) > self::MAX_TRACKED) {
            return new WP_Error('aat_bulk_tracked_full', __('Vänta tills den pågående genereringen är klar, eller använd Hela biblioteket.', 'WP-Auto-Alt-Text'), ['status' => 400]);
        }

        $rejected = ['not_image' => 0, 'no_permission' => 0, 'not_found' => 0];
        $queue = new Auto_Alt_Text_Queue();
        $queued = 0;
        $already = 0;
        $job_ids = [];
        foreach ($ids as $id) {
            if (!get_post($id)) {
                $rejected['not_found']++;
            } elseif (!wp_attachment_is_image($id)) {
                $rejected['not_image']++;
            } elseif (!user_can($user_id, 'edit_post', $id)) {
                $rejected['no_permission']++;
            } else {
                $job = $queue->enqueue_bulk($id, 'batch');
                if (null === $job) {
                    continue;
                }
                $job['new'] ? $queued++ : $already++;
                $job_ids[] = $job['job_id'];
            }
        }

        if (!empty($job_ids)) {
            // A finished (or hidden) bulk is replaced; one still in progress grows.
            $current = null !== $existing && null !== self::progress($user_id) ? self::load($user_id) : null;
            if (null !== $current && null === $current['finished_at']) {
                $job_ids = array_values(array_unique(array_merge($current['job_ids'], $job_ids)));
                $started = $current['started_at'];
            } else {
                $job_ids = array_values(array_unique($job_ids));
                $started = time();
            }
            update_user_meta($user_id, self::META_KEY, [
                'started_at'  => $started,
                'finished_at' => null,
                'job_ids'     => $job_ids,
            ]);
            Auto_Alt_Text_Library_Runner::maybe_chain();
        }

        return [
            'queued'         => $queued,
            'already_queued' => $already,
            'rejected'       => $rejected,
            'bulk'           => self::progress($user_id),
        ];
    }

    /** Stops tracking; the jobs keep running. */
    public static function dismiss(int $user_id): void {
        delete_user_meta($user_id, self::META_KEY);
    }

    /**
     * Skips the user's tracked jobs that are still pending.
     *
     * @return int Jobs cancelled.
     */
    public static function cancel(int $user_id): int {
        $bulk = self::load($user_id);
        if (null === $bulk) {
            return 0;
        }
        return (new Auto_Alt_Text_Queue())->cancel_pending($bulk['job_ids']);
    }

    /** Swedish reason for a stored queue `last_error`; never the raw text. */
    public static function failure_message(?string $last_error): string {
        $text = (string) $last_error;
        if ('' === $text) {
            return __(self::FALLBACK_MESSAGE, 'WP-Auto-Alt-Text'); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
        }
        foreach (self::FAILURE_MAP as $swedish => $msgids) {
            foreach ($msgids as $msgid) {
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
                foreach ([$msgid, __($msgid, 'WP-Auto-Alt-Text')] as $variant) {
                    $parts = explode('%s', $variant);
                    usort($parts, static function (string $a, string $b): int {
                        return strlen($b) <=> strlen($a);
                    });
                    if ('' !== $parts[0] && false !== stripos($text, $parts[0])) {
                        return __($swedish, 'WP-Auto-Alt-Text'); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
                    }
                }
            }
        }
        return __(self::FALLBACK_MESSAGE, 'WP-Auto-Alt-Text'); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
    }

    /**
     * The user's bulk as the progress object (spec §4.3), or null when none is
     * tracked. Sets finished_at the first time it is finished; clears the
     * meta 24 hours after.
     */
    public static function progress(int $user_id): ?array {
        global $wpdb;
        $bulk = self::load($user_id);
        if (null === $bulk) {
            return null;
        }
        if (null !== $bulk['finished_at'] && time() - $bulk['finished_at'] > self::FINISHED_TTL) {
            delete_user_meta($user_id, self::META_KEY);
            return null;
        }

        $job_ids = $bulk['job_ids'];
        $table = Auto_Alt_Text_Queue::get_table_name();
        $placeholders = implode(', ', array_fill(0, count($job_ids), '%d'));
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table name and generated placeholders only.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT q.id, q.attachment_id, q.status, q.attempts, q.next_attempt_at, q.last_error, q.updated_at, p.ID AS post_exists
             FROM {$table} q LEFT JOIN {$wpdb->posts} p ON p.ID = q.attachment_id
             WHERE q.id IN ({$placeholders})",
            $job_ids
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        $counts = ['written' => 0, 'skipped' => 0, 'cancelled' => 0, 'removed' => 0, 'failed' => 0, 'pending' => 0, 'retrying' => 0];
        $due = false;
        $failed_rows = [];
        $now = time();
        $now_mysql = gmdate('Y-m-d H:i:s', $now);
        $soon = gmdate('Y-m-d H:i:s', $now + MINUTE_IN_SECONDS);
        $seen = 0;
        foreach ((array) $rows as $row) {
            $seen++;
            if (null === $row->post_exists) {
                $counts['removed']++;
                continue;
            }
            switch ($row->status) {
                case Auto_Alt_Text_Queue::STATUS_DONE:
                    $counts['written']++;
                    break;
                case Auto_Alt_Text_Queue::STATUS_SKIPPED:
                    $counts[Auto_Alt_Text_Queue::CANCELLED_ERROR === $row->last_error ? 'cancelled' : 'skipped']++;
                    break;
                case Auto_Alt_Text_Queue::STATUS_FAILED:
                    $counts['failed']++;
                    $failed_rows[] = $row;
                    break;
                case Auto_Alt_Text_Queue::STATUS_PENDING:
                    $counts['pending']++;
                    if ((int) $row->attempts > 0 || (string) $row->next_attempt_at > $soon) {
                        $counts['retrying']++;
                    }
                    if ((string) $row->next_attempt_at <= $now_mysql) {
                        $due = true;
                    }
                    break;
                case Auto_Alt_Text_Queue::STATUS_PROCESSING:
                    $counts['pending']++;
                    break;
            }
        }
        // Tracked jobs whose row is gone (pruned) count as removed.
        $counts['removed'] += count($job_ids) - $seen;

        $state = self::state($counts['pending']);
        if ('finished' === $state && null === $bulk['finished_at']) {
            $bulk['finished_at'] = $now;
            update_user_meta($user_id, self::META_KEY, $bulk);
        }

        $stalled = 'running' === $state && $due && !Auto_Alt_Text_Library_Runner::lock_held()
            && $now - max(Auto_Alt_Text_Library_Runner::last_tick_at(), $bulk['started_at']) > self::STALL_SECONDS;

        usort($failed_rows, static function ($a, $b): int {
            return [(string) $b->updated_at, (int) $b->id] <=> [(string) $a->updated_at, (int) $a->id];
        });
        $failures = [];
        foreach (array_slice($failed_rows, 0, self::FAILURES_SHOWN) as $row) {
            $attachment_id = (int) $row->attachment_id;
            $failures[] = [
                'attachment_id' => $attachment_id,
                'title'         => get_the_title($attachment_id),
                'edit_url'      => (string) get_edit_post_link($attachment_id, 'raw'),
                'message'       => self::failure_message($row->last_error),
            ];
        }

        return array_merge(['state' => $state, 'stalled' => $stalled, 'total' => count($job_ids)], $counts, [
            'failures'    => $failures,
            'started_at'  => $bulk['started_at'],
            'finished_at' => $bulk['finished_at'],
        ]);
    }

    private static function state(int $pending): string {
        if (0 === $pending) {
            return 'finished';
        }
        $paused = (bool) get_transient(Auto_Alt_Text_Queue::AUTH_PAUSE_TRANSIENT);
        if (!Auto_Alt_Text_Queue::has_api_key()) {
            return 'paused_no_key';
        }
        if (Auto_Alt_Text_Quota_Monitor::is_active()) {
            return 'paused_quota';
        }
        if ($paused && Auto_Alt_Text_Connector_Status::is_blocked()) {
            return 'paused_connector';
        }
        return $paused ? 'paused_auth' : 'running';
    }

    /** @return array{started_at:int,finished_at:?int,job_ids:array<int>}|null */
    private static function load(int $user_id): ?array {
        $meta = get_user_meta($user_id, self::META_KEY, true);
        if (!is_array($meta) || empty($meta['job_ids']) || !is_array($meta['job_ids'])) {
            return null;
        }
        return [
            'started_at'  => (int) ($meta['started_at'] ?? time()),
            'finished_at' => empty($meta['finished_at']) ? null : (int) $meta['finished_at'],
            'job_ids'     => array_values(array_unique(array_map('intval', $meta['job_ids']))),
        ];
    }
}
