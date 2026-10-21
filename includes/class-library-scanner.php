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

        update_meta_cache('post', wp_list_pluck($rows, 'ID'));
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
        return $counts;
    }

    /** @return array{missing:int,ai:int,human:int,unsupported:int,max_id:int,updated_at:int,complete:bool,cursor:int} */
    public static function cached_counts(): array {
        $defaults = [
            'missing' => 0, 'ai' => 0, 'human' => 0, 'unsupported' => 0,
            'max_id' => 0, 'updated_at' => 0, 'complete' => false, 'cursor' => 0,
        ];
        $stored = get_option(self::COUNTS_OPTION, []);
        $counts = array_merge($defaults, is_array($stored) ? $stored : []);
        foreach (['missing', 'ai', 'human', 'unsupported', 'max_id', 'updated_at', 'cursor'] as $key) {
            $counts[$key] = (int) $counts[$key];
        }
        $counts['complete'] = (bool) $counts['complete'];
        return $counts;
    }

    /** Starts a fresh count: resets the cursor and schedules the background job. */
    public static function request_recount(): void {
        $previous = self::cached_counts();
        // Keep the old figures visible until the new count completes.
        $previous['cursor'] = 0;
        $previous['complete'] = false;
        update_option(self::COUNTS_OPTION, $previous, false);
        if (!wp_next_scheduled(self::RECOUNT_HOOK)) {
            wp_schedule_single_event(time(), self::RECOUNT_HOOK);
        }
    }

    /** Cron callback. */
    public static function run_recount(): void {
        self::recount_step(self::DEFAULT_BUDGET);
    }

    /**
     * Does as much of the recount as fits in the budget.
     *
     * @return bool True when the count is complete.
     */
    public static function recount_step(float $budget_seconds): bool {
        $deadline = microtime(true) + $budget_seconds;

        // 1. Make "ai" accurate for pre-2.0 text first.
        if (Auto_Alt_Text_Provenance::backfill_pending()) {
            $after = self::backfill_cursor();
            $drained = false;
            do {
                $after = Auto_Alt_Text_Provenance::backfill_batch($after, self::BACKFILL_CHUNK);
                if (0 === $after) {
                    wp_unschedule_hook(Auto_Alt_Text_Provenance::BACKFILL_HOOK);
                    $drained = true;
                    break;
                }
            } while (microtime(true) < $deadline);
            if (!$drained) {
                self::save_backfill_cursor($after);
                self::reschedule();
                return false;
            }
            self::save_backfill_cursor(0);
        }

        // 2. Count in chunks.
        $state = get_option(self::COUNTS_OPTION, []);
        $state = is_array($state) ? $state : [];
        $cursor = (int) ($state['cursor'] ?? 0);
        $tally = isset($state['pending']) && is_array($state['pending']) && $cursor > 0
            ? array_map('intval', $state['pending'] + ['missing' => 0, 'ai' => 0, 'human' => 0, 'unsupported' => 0])
            : ['missing' => 0, 'ai' => 0, 'human' => 0, 'unsupported' => 0];
        $max_id = $cursor > 0 ? (int) ($state['max_id'] ?? 0) : 0;

        $done = false;
        do {
            $chunk = self::count_chunk($cursor, self::CHUNK);
            if (0 === $chunk['last_id']) {
                $done = true;
                break;
            }
            foreach (['missing', 'ai', 'human', 'unsupported'] as $key) {
                $tally[$key] += $chunk[$key];
            }
            $cursor = $chunk['last_id'];
            $max_id = max($max_id, $cursor);
        } while (microtime(true) < $deadline);

        if ($done) {
            $tally['max_id'] = $max_id;
            $tally['updated_at'] = time();
            $tally['complete'] = true;
            $tally['cursor'] = 0;
            update_option(self::COUNTS_OPTION, $tally, false);
            return true;
        }

        update_option(self::COUNTS_OPTION, $tally + [
            'max_id' => $max_id, 'updated_at' => (int) ($state['updated_at'] ?? 0),
            'complete' => false, 'cursor' => $cursor,
            'pending' => $tally,
        ], false);
        self::reschedule();
        return false;
    }

    private static function reschedule(): void {
        if (!wp_next_scheduled(self::RECOUNT_HOOK)) {
            wp_schedule_single_event(time() + self::RESCHEDULE_SECONDS, self::RECOUNT_HOOK);
        }
    }

    private static function backfill_cursor(): int {
        return (int) get_option('aat_library_backfill_cursor', 0);
    }

    private static function save_backfill_cursor(int $after): void {
        update_option('aat_library_backfill_cursor', $after, false);
    }
}
