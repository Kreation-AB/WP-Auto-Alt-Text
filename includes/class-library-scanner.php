<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Counts the media library by alt-text state for the library backfill, in
 * chunks, in the background. Counts are cached in an option; a recount first
 * drains the pre-2.0 provenance backfill so "ai" is accurate.
 */
class Auto_Alt_Text_Library_Scanner {
    public const COUNTS_OPTION = 'aat_library_counts';
    public const RECOUNT_HOOK = 'aat_library_recount';
    public const COVERAGE_MISSING = 'missing';
    public const COVERAGE_MISSING_AI = 'missing_ai';

    private const CHUNK = 500;
    private const BACKFILL_CHUNK = 200;
    private const RESCHEDULE_SECONDS = 15;
    private const DEFAULT_BUDGET = 20.0;

    /** @return string Provenance policy for a coverage; never "all". */
    public static function policy_for_coverage(string $coverage): string {
        return self::COVERAGE_MISSING_AI === $coverage
            ? Auto_Alt_Text_Provenance::POLICY_AI
            : Auto_Alt_Text_Provenance::POLICY_MISSING;
    }

    /** Whether a run with this coverage may (re)generate this image. */
    public static function is_candidate(int $id, string $coverage): bool {
        $mime = (string) get_post_mime_type($id);
        if (!in_array($mime, Auto_Alt_Text_Image_Source::supported_input_mimes(), true)) {
            return false;
        }
        return Auto_Alt_Text_Provenance::allows_overwrite($id, self::policy_for_coverage($coverage));
    }

    /**
     * Counts up to $limit image attachments with ID > $after_id.
     *
     * @return array{missing:int,ai:int,human:int,unsupported:int,last_id:int} last_id is 0 when there were no rows.
     */
    public static function count_chunk(int $after_id, int $limit): array {
        global $wpdb;
        $counts = ['missing' => 0, 'ai' => 0, 'human' => 0, 'unsupported' => 0, 'last_id' => 0];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_mime_type FROM {$wpdb->posts}
             WHERE ID > %d AND post_type = 'attachment' AND post_mime_type LIKE %s
             ORDER BY ID ASC LIMIT %d",
            $after_id,
            'image/%',
            max(1, $limit)
        ));
        if (empty($rows)) {
            return $counts;
        }

        $ids = array_map('intval', wp_list_pluck($rows, 'ID'));
        update_meta_cache('post', $ids);
        $supported = Auto_Alt_Text_Image_Source::supported_input_mimes();
        foreach ($rows as $row) {
            $id = (int) $row->ID;
            $counts['last_id'] = $id;
            if (!in_array($row->post_mime_type, $supported, true)) {
                $counts['unsupported']++;
                continue;
            }
            $counts[Auto_Alt_Text_Provenance::classify($id)]++;
        }
        // A recount loops over chunks: keeping every chunk's meta cached would exhaust memory on a large library.
        foreach ($ids as $id) {
            wp_cache_delete($id, 'post_meta');
        }
        return $counts;
    }

    /**
     * The last complete figures. A recount in flight never touches them; its
     * progress lives in separate keys until it completes.
     *
     * @return array{missing:int,ai:int,human:int,unsupported:int,max_id:int,updated_at:int,complete:bool,cursor:int,recounting:bool}
     */
    public static function cached_counts(): array {
        $state = self::state();
        $counts = [];
        foreach (['missing', 'ai', 'human', 'unsupported', 'max_id', 'updated_at'] as $key) {
            $counts[$key] = (int) ($state[$key] ?? 0);
        }
        $counts['complete'] = !empty($state['complete']);
        $counts['cursor'] = (int) ($state['cursor'] ?? 0);
        $counts['recounting'] = self::in_flight($state);
        return $counts;
    }

    /** Starts a fresh count in the background. A no-op while one is already in flight. */
    public static function request_recount(): void {
        $state = self::state();
        if (self::in_flight($state)) {
            self::reschedule();
            return;
        }
        unset($state['pending']);
        $state['cursor'] = 0;
        $state['backfill_cursor'] = 0;
        update_option(self::COUNTS_OPTION, $state, false);
        wp_schedule_single_event(time(), self::RECOUNT_HOOK);
    }

    /** Cron callback. */
    public static function run_recount(): void {
        self::recount_step(self::DEFAULT_BUDGET);
    }

    /**
     * Does as much of the recount as fits in the budget (at least one chunk).
     *
     * @return bool True when the count is complete.
     */
    public static function recount_step(float $budget_seconds): bool {
        $deadline = microtime(true) + $budget_seconds;
        $state = self::state();

        // 1. Make "ai" accurate for pre-2.0 text first.
        if (Auto_Alt_Text_Provenance::backfill_pending()) {
            $after = (int) ($state['backfill_cursor'] ?? 0);
            $drained = false;
            do {
                $after = Auto_Alt_Text_Provenance::backfill_batch($after, self::BACKFILL_CHUNK);
                if (0 === $after) {
                    wp_unschedule_hook(Auto_Alt_Text_Provenance::BACKFILL_HOOK);
                    $drained = true;
                    break;
                }
            } while (microtime(true) < $deadline);
            $state['backfill_cursor'] = $drained ? 0 : $after;
            update_option(self::COUNTS_OPTION, $state, false);
            if (!$drained) {
                self::reschedule();
                return false;
            }
        }

        // 2. Count in chunks into the pending tally.
        $cursor = (int) ($state['cursor'] ?? 0);
        $zero = ['missing' => 0, 'ai' => 0, 'human' => 0, 'unsupported' => 0, 'max_id' => 0];
        $pending = ($cursor > 0 && isset($state['pending']) && is_array($state['pending']))
            ? array_map('intval', array_merge($zero, $state['pending']))
            : $zero;
        $chunk_size = max(1, (int) apply_filters('aat_library_recount_chunk', self::CHUNK));

        $done = false;
        do {
            $chunk = self::count_chunk($cursor, $chunk_size);
            if (0 === $chunk['last_id']) {
                $done = true;
                break;
            }
            foreach (['missing', 'ai', 'human', 'unsupported'] as $key) {
                $pending[$key] += $chunk[$key];
            }
            $cursor = $chunk['last_id'];
            $pending['max_id'] = max($pending['max_id'], $cursor);
        } while (microtime(true) < $deadline);

        if ($done) {
            $state = array_merge($state, $pending, ['updated_at' => time(), 'complete' => true, 'cursor' => 0, 'backfill_cursor' => 0]);
            unset($state['pending']);
            update_option(self::COUNTS_OPTION, $state, false);
            wp_unschedule_hook(self::RECOUNT_HOOK);
            return true;
        }

        $state['pending'] = $pending;
        $state['cursor'] = $cursor;
        update_option(self::COUNTS_OPTION, $state, false);
        self::reschedule();
        return false;
    }

    private static function state(): array {
        $state = get_option(self::COUNTS_OPTION, []);
        return is_array($state) ? $state : [];
    }

    private static function in_flight(array $state): bool {
        return (int) ($state['cursor'] ?? 0) > 0
            || (int) ($state['backfill_cursor'] ?? 0) > 0
            || false !== wp_next_scheduled(self::RECOUNT_HOOK);
    }

    private static function reschedule(): void {
        if (!wp_next_scheduled(self::RECOUNT_HOOK)) {
            wp_schedule_single_event(time() + self::RESCHEDULE_SECONDS, self::RECOUNT_HOOK);
        }
    }
}
