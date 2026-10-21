<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The downloadable run report: plain text a site owner can send to their
 * agency. Holds counts, failures, pauses, versions and the run's warning and
 * error log lines. Never holds the API key, key-like strings or alt texts.
 */
class Auto_Alt_Text_Library_Report {
    private const LOG_LIMIT = 200;
    private const QUEUE_FAILURE_LIMIT = 500;

    public static function build(int $run_id): string {
        $run = Auto_Alt_Text_Library_Run::get($run_id);
        if (null === $run) {
            return '';
        }
        $progress = Auto_Alt_Text_Library_Run::progress($run);
        $state = Auto_Alt_Text_Library_Run::display_state($run);
        $summary = $run['summary'];
        $final = in_array($run['status'], [Auto_Alt_Text_Library_Run::STATUS_CANCELLED, Auto_Alt_Text_Library_Run::STATUS_FINISHED], true);
        $actual = $final ? (float) $summary['cost_usd'] : Auto_Alt_Text_Library_Run::cost_so_far($run_id);

        $lines = [];
        $lines[] = 'Rapport för körning "Hela biblioteket"';
        $lines[] = '';
        $lines[] = '== Körning ==';
        $lines[] = 'Körnings-ID: ' . $run['id'];
        $lines[] = 'Status: ' . $state['state'] . (null !== $state['reason'] ? ' (' . $state['reason'] . ')' : '');
        $lines[] = 'Omfattning: ' . $run['coverage'];
        $lines[] = 'Modell (vid rapporttillfället): ' . Auto_Alt_Text_Models::current_id();
        $lines[] = 'Språkhantering (alternativet "' . AUTO_ALT_TEXT_LANGUAGE_OPTION . '"): ' . (string) get_option(AUTO_ALT_TEXT_LANGUAGE_OPTION, 'en');
        $lines[] = 'Startad: ' . self::times((string) $run['started_at']);
        $lines[] = 'Avslutad: ' . (empty($run['ended_at']) ? '-' : self::times((string) $run['ended_at']));
        $lines[] = '';
        $lines[] = '== Förlopp ==';
        $lines[] = 'Totalt: ' . $progress['total'];
        $lines[] = 'Fyllda: ' . $progress['filled'];
        $lines[] = 'Hoppade över: ' . $progress['skipped'];
        $lines[] = 'Misslyckade: ' . $progress['failed'];
        $lines[] = 'Väntande: ' . $progress['pending'];
        $lines[] = 'Uppskattad kostnad (USD): ' . number_format((float) $run['estimate_usd'], 4, '.', '');
        $lines[] = 'Faktisk kostnad (USD): ' . number_format($actual, 4, '.', '');
        $lines[] = '';
        $lines[] = '== Misslyckade bilder ==';
        array_push($lines, ...self::failure_lines($run, (int) $progress['failed'], $final));
        $lines[] = '';
        $lines[] = '== Pauser och återupptagningar ==';
        if (empty($summary['events'])) {
            $lines[] = '(inga)';
        }
        foreach ($summary['events'] ?? [] as $event) {
            $lines[] = sprintf('%s UTC  %s  %s', $event['at'] ?? '', $event['type'] ?? '', $event['reason'] ?? '');
        }
        $lines[] = '';
        $lines[] = '== Versioner ==';
        $lines[] = 'WP Auto Alt Text: ' . AUTO_ALT_TEXT_VERSION;
        $lines[] = 'WordPress: ' . get_bloginfo('version');
        $lines[] = 'PHP: ' . PHP_VERSION;
        $lines[] = 'Bildredigerare: ' . (string) _wp_image_editor_choose();
        if (defined('WC_VERSION')) {
            $lines[] = 'WooCommerce: ' . WC_VERSION;
        }
        $lines[] = '';
        $lines[] = '== Loggrader (fel och varningar, senaste ' . self::LOG_LIMIT . '; tider i webbplatsens tid) ==';
        array_push($lines, ...self::log_lines($run_id));

        return self::scrub(implode("\n", $lines) . "\n");
    }

    /** Replaces the saved API key and key/org/project-looking strings. */
    public static function scrub(string $text): string {
        // Longest first, so a key containing another is replaced whole.
        $keys = self::saved_keys();
        usort($keys, static fn($a, $b) => strlen($b) <=> strlen($a));
        foreach ($keys as $key) {
            $text = str_replace($key, '[redacted]', $text);
        }
        $text = preg_replace('/sk-[A-Za-z0-9_-]{8,}/', '[redacted]', $text) ?? '';
        $text = preg_replace('/org-[A-Za-z0-9]{8,}/', '[redacted]', $text) ?? '';
        return preg_replace('/proj_[A-Za-z0-9]{6,}/', '[redacted]', $text) ?? '';
    }

    public static function filename(array $run): string {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $host = trim((string) preg_replace('/[^a-z0-9.-]/', '', strtolower($host)), '.-');
        if ('' === $host) {
            $host = 'site';
        }
        $date = substr((string) ($run['started_at'] ?? gmdate('Y-m-d')), 0, 10);
        return sprintf('alt-text-rapport-%s-run%d-%s.txt', $host, (int) $run['id'], $date);
    }

    public static function handle_download(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Du har inte behörighet.', 'WP-Auto-Alt-Text'), '', ['response' => 403]);
        }
        check_admin_referer('aat_library_report');
        $run_id = isset($_GET['run']) ? absint(wp_unslash($_GET['run'])) : 0;
        $run = $run_id > 0 ? Auto_Alt_Text_Library_Run::get($run_id) : null;
        if (null === $run) {
            wp_die(esc_html__('Körningen finns inte.', 'WP-Auto-Alt-Text'), '', ['response' => 404]);
        }
        $payload = self::download_payload($run);
        // Drop stray output from other code so it cannot end up in the file.
        while (ob_get_level()) {
            ob_end_clean();
        }
        if (headers_sent()) {
            wp_die(esc_html__('Rapporten kunde inte skickas.', 'WP-Auto-Alt-Text'), '', ['response' => 500]);
        }
        nocache_headers();
        foreach ($payload['headers'] as $header) {
            header($header);
        }
        echo $payload['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text download, scrubbed in build().
        exit;
    }

    /** @return array{headers: string[], body: string} */
    public static function download_payload(array $run): array {
        return [
            'headers' => [
                'Content-Type: text/plain; charset=utf-8',
                'Content-Disposition: attachment; filename="' . self::filename($run) . '"',
                'X-Content-Type-Options: nosniff',
            ],
            'body' => self::build((int) $run['id']),
        ];
    }

    private static function times(string $utc): string {
        return $utc . ' UTC / ' . get_date_from_gmt($utc) . ' (webbplatsens tid)';
    }

    /** @return string[] Every configured key (constant and saved option) plus trimmed variants. */
    private static function saved_keys(): array {
        $keys = [];
        if (defined('AUTO_ALT_TEXT_OPENAI_API_KEY') && AUTO_ALT_TEXT_OPENAI_API_KEY) {
            $keys[] = (string) AUTO_ALT_TEXT_OPENAI_API_KEY;
        }
        $encrypted = get_option('auto_alt_text_api_key');
        if ($encrypted) {
            $keys[] = (string) (new Auto_Alt_Text_OpenAI())->decrypt_api_key($encrypted);
        }
        foreach ($keys as $key) {
            $keys[] = trim($key);
        }
        return array_values(array_filter(array_unique($keys), static fn($k) => '' !== $k));
    }

    /** @return string[] */
    private static function failure_lines(array $run, int $failed_total, bool $final): array {
        $summary = $run['summary'];
        $failures = $final && is_array($summary['failures'] ?? null) ? $summary['failures'] : [];
        $lines = [];
        foreach ($failures as $f) {
            $lines[] = self::failure_line((int) ($f['attachment_id'] ?? 0), (string) ($f['file'] ?? ''), (string) ($f['reason'] ?? ''));
        }
        // Active runs have no summary list yet; a final run may have a capped or empty one.
        if (!$final || !empty($summary['failures_truncated']) || (!$failures && $failed_total > 0)) {
            $extra = self::queue_failures($run['id'], $failures);
            foreach ($extra as $row) {
                $file = (string) get_post_meta((int) $row->attachment_id, '_wp_attached_file', true);
                $lines[] = self::failure_line((int) $row->attachment_id, basename($file), (string) $row->last_error);
            }
            $more = max(0, $failed_total - count($failures) - count($extra));
            if ($more > 0) {
                $lines[] = sprintf('… och %d till', $more);
            }
        }
        return $lines ?: ['(inga)'];
    }

    private static function failure_line(int $id, string $file, string $reason): string {
        return sprintf('#%d  %s  %s', $id, $file, $reason);
    }

    /** Failed queue rows for the run beyond the summary's capped list, newest first. */
    private static function queue_failures(int $run_id, array $listed_failures): array {
        global $wpdb;
        $table = Auto_Alt_Text_Queue::get_table_name();
        $listed = count($listed_failures);
        $ids = array_map(static fn($f) => (int) ($f['attachment_id'] ?? 0), $listed_failures);
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT attachment_id, last_error FROM {$table} WHERE run_id = %d AND status = %s ORDER BY id DESC LIMIT %d",
            $run_id, Auto_Alt_Text_Queue::STATUS_FAILED, self::QUEUE_FAILURE_LIMIT + $listed
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = array_values(array_filter((array) $rows, static fn($r) => !in_array((int) $r->attachment_id, $ids, true)));
        return array_slice($rows, 0, self::QUEUE_FAILURE_LIMIT);
    }

    /** @return string[] */
    private static function log_lines(int $run_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'auto_alt_text_logs';
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT timestamp, level, message, context FROM {$table}
             WHERE level IN ('error', 'warning') AND context REGEXP %s ORDER BY id DESC LIMIT %d",
            '"run_id":"?' . $run_id . '[",}]', self::LOG_LIMIT
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $lines = [];
        foreach ((array) $rows as $row) {
            $context = json_decode((string) $row->context, true);
            if (!is_array($context) || (int) ($context['run_id'] ?? 0) !== $run_id) {
                continue;
            }
            $lines[] = sprintf('%s [%s] %s %s', $row->timestamp, $row->level, $row->message, $row->context);
        }
        return $lines ?: ['(inga)'];
    }
}
