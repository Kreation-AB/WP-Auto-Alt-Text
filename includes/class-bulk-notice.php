<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The bulk progress notice on the Media Library (spec 4.7). Server-rendered so
 * it works without JS; media-library.js keeps the same structure up to date.
 */
class Auto_Alt_Text_Bulk_Notice {
    /** List-mode redirect arg: the rejected counts, as "not_image-no_permission-not_found". */
    public const REJECTED_PARAM = 'aat_bulk_rejected';
    private const REJECTED_KEYS = ['not_image', 'no_permission', 'not_found'];
    private const ERROR_CODES = ['too_many', 'no_key', 'tracked_full', 'empty'];

    /** Prints the notice(s) on the Media Library for the current user. */
    public static function render(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || 'upload' !== $screen->id || !current_user_can('upload_files')) {
            return;
        }
        $code = isset($_GET['aat_bulk']) ? sanitize_key(wp_unslash($_GET['aat_bulk'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag chosen from a fixed list.
        if (in_array($code, self::ERROR_CODES, true)) {
            $messages = self::error_messages();
            printf(
                '<div class="notice notice-error is-dismissible aat-bulk-error"><p>%s</p></div>',
                esc_html($messages[$code])
            );
        }
        $rejected = isset($_GET[self::REJECTED_PARAM]) ? sanitize_text_field(wp_unslash($_GET[self::REJECTED_PARAM])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only counts, decoded to integers.
        $rejected_text = self::rejected_text(self::decode_rejected($rejected));
        if ('' !== $rejected_text) {
            printf(
                '<div class="notice notice-warning is-dismissible aat-bulk-rejected"><p>%s</p></div>',
                esc_html($rejected_text)
            );
        }
        $progress = Auto_Alt_Text_Bulk::progress(get_current_user_id());
        if (null !== $progress) {
            echo self::html($progress); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in html().
        }
    }

    /** @return array<string,string> */
    private static function error_messages(): array {
        $s = self::strings();
        return [
            'too_many'     => $s['err_too_many'],
            'no_key'       => $s['err_no_key'],
            'tracked_full' => $s['err_tracked_full'],
            'empty'        => $s['err_empty'],
        ];
    }

    /**
     * Every notice string, also passed to JS. Templates use %s placeholders.
     *
     * @return array<string,string>
     */
    public static function strings(): array {
        return [
            /* translators: 1: images done, 2: images in total. */
            'running'         => __('Alt-text genereras i bakgrunden: %1$s av %2$s bilder klara.', 'WP-Auto-Alt-Text'),
            'leave'           => __('Du kan lämna sidan – arbetet fortsätter.', 'WP-Auto-Alt-Text'),
            /* translators: %s: number of images. */
            'retrying'        => __('(%s väntar på ett nytt försök)', 'WP-Auto-Alt-Text'),
            'stalled'         => __('Bakgrundskörningen verkar inte starta på den här servern. Låt sidan vara öppen så fortsätter genereringen härifrån.', 'WP-Auto-Alt-Text'),
            'paused_auth'     => __('Genereringen är pausad: OpenAI avvisade API-nyckeln. Bilderna ligger kvar i kön och fortsätter när nyckeln fungerar.', 'WP-Auto-Alt-Text'),
            'paused_quota'    => __('Genereringen är pausad: kvoten på OpenAI-kontot är slut. Bilderna ligger kvar i kön och fortsätter när kvoten har fyllts på.', 'WP-Auto-Alt-Text'),
            'paused_connector' => __('Genereringen är pausad: WordPress AI-anslutningen behöver godkännas.', 'WP-Auto-Alt-Text'),
            'paused_no_key'   => __('Genereringen är pausad: ingen API-nyckel är angiven.', 'WP-Auto-Alt-Text'),
            /* translators: 1: images done, 2: images in total. */
            'paused_count'    => __('%1$s av %2$s bilder klara.', 'WP-Auto-Alt-Text'),
            'open_connection' => __('Öppna Anslutning', 'WP-Auto-Alt-Text'),
            'open_approval'   => __('Öppna godkännandesidan', 'WP-Auto-Alt-Text'),
            'contact_admin'   => __('Kontakta webbplatsens administratör.', 'WP-Auto-Alt-Text'),
            'finished_none'   => __('Klart: ingen bild fick alt-text.', 'WP-Auto-Alt-Text'),
            'finished_one'    => __('Klart: 1 bild fick alt-text.', 'WP-Auto-Alt-Text'),
            /* translators: %s: number of images. */
            'finished_many'   => __('Klart: %s bilder fick alt-text.', 'WP-Auto-Alt-Text'),
            /* translators: %s: number of images. */
            'skipped'         => __('%s hoppades över eftersom de redan har en alt-text som inställningarna inte låter pluginet skriva över.', 'WP-Auto-Alt-Text'),
            /* translators: %s: number of images. */
            'failed'          => __('%s misslyckades.', 'WP-Auto-Alt-Text'),
            /* translators: %s: number of images. */
            'cancelled'       => __('%s avbröts.', 'WP-Auto-Alt-Text'),
            'removed_one'     => __('1 bild har tagits bort.', 'WP-Auto-Alt-Text'),
            /* translators: %s: number of images. */
            'removed_many'    => __('%s bilder har tagits bort.', 'WP-Auto-Alt-Text'),
            'failures_toggle' => __('Visa bilder som misslyckades', 'WP-Auto-Alt-Text'),
            'refresh'         => __('Uppdatera', 'WP-Auto-Alt-Text'),
            'cancel'          => __('Avbryt', 'WP-Auto-Alt-Text'),
            'hide'            => __('Dölj', 'WP-Auto-Alt-Text'),
            'close'           => __('Stäng', 'WP-Auto-Alt-Text'),
            'session_expired' => __('Sessionen har gått ut. Ladda om sidan för att se hur det går.', 'WP-Auto-Alt-Text'),
            'err_too_many'    => __('Markera högst 1 000 bilder åt gången. Använd Hela biblioteket för fler.', 'WP-Auto-Alt-Text'),
            'err_no_key'      => __('Ange en API-nyckel under Inställningar → Anslutning för att generera alt-text.', 'WP-Auto-Alt-Text'),
            'err_tracked_full' => __('Vänta tills den pågående genereringen är klar, eller använd Hela biblioteket.', 'WP-Auto-Alt-Text'),
            'err_generic'     => __('Det gick inte att starta genereringen. Försök igen om en stund.', 'WP-Auto-Alt-Text'),
            'grid_generate'   => __('Generera alt-text för markerade', 'WP-Auto-Alt-Text'),
            'err_empty'       => __('Markera minst en bild.', 'WP-Auto-Alt-Text'),
            /* translators: 1: number of files, 2: the reasons, separated by commas. */
            'rejected'        => __('%1$s av de markerade filerna kunde inte köas: %2$s.', 'WP-Auto-Alt-Text'),
            'rejected_not_image_one' => __('1 är inte en bild', 'WP-Auto-Alt-Text'),
            /* translators: %s: number of files. */
            'rejected_not_image_many' => __('%s är inte bilder', 'WP-Auto-Alt-Text'),
            /* translators: %s: number of images. */
            'rejected_no_permission' => __('%s får du inte redigera', 'WP-Auto-Alt-Text'),
            /* translators: %s: number of files. */
            'rejected_not_found' => __('%s finns inte längre', 'WP-Auto-Alt-Text'),
        ];
    }

    /** The rejected counts of a bulk request, compact for the list-mode redirect. */
    public static function encode_rejected(array $rejected): string {
        return implode('-', array_map(static function (string $key) use ($rejected): string {
            return (string) absint($rejected[$key] ?? 0);
        }, self::REJECTED_KEYS));
    }

    /** @return array<string,int> The counts per reason; all zero unless the value has the encoded form. */
    public static function decode_rejected(string $value): array {
        $parts = 1 === preg_match('/^\d{1,5}-\d{1,5}-\d{1,5}$/', $value) ? explode('-', $value) : [];
        $counts = [];
        foreach (self::REJECTED_KEYS as $i => $key) {
            $counts[$key] = min(Auto_Alt_Text_Bulk::MAX_PER_REQUEST, absint($parts[$i] ?? 0));
        }
        return $counts;
    }

    /**
     * "3 av de markerade filerna kunde inte köas: 2 är inte bilder, 1 får du
     * inte redigera." with only the reasons that occurred; '' when none did.
     * media-library.js builds the same text from a grid request's response.
     */
    public static function rejected_text(array $rejected): string {
        $s = self::strings();
        $not_image = (int) ($rejected['not_image'] ?? 0);
        $no_permission = (int) ($rejected['no_permission'] ?? 0);
        $not_found = (int) ($rejected['not_found'] ?? 0);
        $parts = [];
        if ($not_image > 0) {
            $parts[] = 1 === $not_image ? $s['rejected_not_image_one'] : sprintf($s['rejected_not_image_many'], self::num($not_image));
        }
        if ($no_permission > 0) {
            $parts[] = sprintf($s['rejected_no_permission'], self::num($no_permission));
        }
        if ($not_found > 0) {
            $parts[] = sprintf($s['rejected_not_found'], self::num($not_found));
        }
        if (empty($parts)) {
            return '';
        }
        return sprintf($s['rejected'], self::num($not_image + $no_permission + $not_found), implode(', ', $parts));
    }

    /** Admin-post links and target pages, shared with JS for notices it builds itself. */
    public static function links(): array {
        return [
            'dismiss'    => add_query_arg('_wpnonce', wp_create_nonce('aat_bulk'), admin_url('admin-post.php?action=aat_bulk_dismiss')),
            'cancel'     => add_query_arg('_wpnonce', wp_create_nonce('aat_bulk'), admin_url('admin-post.php?action=aat_bulk_cancel')),
            'connection' => admin_url('admin.php?page=auto-alt-text#aat-section-anslutning'),
            'connector'  => Auto_Alt_Text_Connector_Status::approval_url(),
        ];
    }

    private static function num(int $n): string {
        return number_format_i18n($n);
    }

    /** The headline text for a progress object. */
    public static function summary_text(array $p): string {
        $s = self::strings();
        $state = (string) $p['state'];
        if ('finished' !== $state) {
            $done = self::num(max(0, (int) $p['total'] - (int) $p['pending']));
            if (0 === strpos($state, 'paused_')) {
                // The count is the progress bar's text equivalent.
                return ($s[$state] ?? $s['paused_auth']) . ' ' . sprintf($s['paused_count'], $done, self::num((int) $p['total']));
            }
            $text = sprintf($s['running'], $done, self::num((int) $p['total']));
            return empty($p['stalled']) ? $text . ' ' . $s['leave'] : $text;
        }
        $written = (int) $p['written'];
        $parts = [0 === $written ? $s['finished_none'] : (1 === $written ? $s['finished_one'] : sprintf($s['finished_many'], self::num($written)))];
        foreach (['skipped', 'failed', 'cancelled'] as $key) {
            if ((int) $p[$key] > 0) {
                $parts[] = sprintf($s[$key], self::num((int) $p[$key]));
            }
        }
        if ((int) $p['removed'] > 0) {
            $parts[] = 1 === (int) $p['removed'] ? $s['removed_one'] : sprintf($s['removed_many'], self::num((int) $p['removed']));
        }
        return implode(' ', $parts);
    }

    /** The notice markup for a progress object. Same structure as media-library.js builds. */
    public static function html(array $p): string {
        $s = self::strings();
        $links = self::links();
        $state = (string) $p['state'];
        $finished = 'finished' === $state;
        $paused = 0 === strpos($state, 'paused_');
        $class = $finished ? ((int) $p['failed'] > 0 ? 'notice-warning' : 'notice-success') : ($paused ? 'notice-warning' : 'notice-info');
        $total = (int) $p['total'];
        $done = max(0, $total - (int) $p['pending']);

        $link_url = '';
        $link_text = '';
        if ('paused_connector' === $state) {
            $link_url = $links['connector'];
            $link_text = $s['open_approval'];
        } elseif ('paused_auth' === $state || 'paused_no_key' === $state) {
            $link_url = $links['connection'];
            $link_text = $s['open_connection'];
        }

        $retrying = !$finished && !$paused && (int) $p['retrying'] > 0;
        $stalled = !$finished && !$paused && !empty($p['stalled']);
        $failures = $finished ? (array) ($p['failures'] ?? []) : [];

        ob_start();
        printf('<div id="aat-bulk-notice" class="notice %s aat-bulk-notice" data-state="%s">', esc_attr($class), esc_attr($state));
        printf('<p id="aat-bulk-summary">%s</p>', esc_html(self::summary_text($p)));
        printf(
            '<progress id="aat-bulk-progress" class="aat-bulk-progress" aria-labelledby="aat-bulk-summary" max="%d" value="%d"%s></progress>',
            absint($total),
            absint($done),
            $finished ? ' hidden' : ''
        );
        printf('<p class="aat-bulk-retrying"%s>%s</p>', $retrying ? '' : ' hidden', $retrying ? esc_html(sprintf($s['retrying'], self::num((int) $p['retrying']))) : '');
        printf('<p class="aat-bulk-stalled"%s>%s</p>', $stalled ? '' : ' hidden', $stalled ? esc_html($s['stalled']) : '');
        if ('' === $link_url || current_user_can('manage_options')) {
            printf(
                '<p class="aat-bulk-pause-link"%s><a href="%s">%s</a></p>',
                '' !== $link_url ? '' : ' hidden',
                esc_url($link_url),
                esc_html($link_text)
            );
        } else {
            // The linked pages need manage_options: point to the person who has it.
            printf('<p class="aat-bulk-pause-link">%s</p>', esc_html($s['contact_admin']));
        }
        printf('<details class="aat-bulk-failures"%s><summary>%s</summary><ul>', $failures ? '' : ' hidden', esc_html($s['failures_toggle']));
        foreach ($failures as $failure) {
            printf(
                '<li><a href="%s">%s</a> <span>%s</span></li>',
                esc_url((string) $failure['edit_url']),
                esc_html((string) $failure['title']),
                esc_html((string) $failure['message'])
            );
        }
        echo '</ul></details>';
        echo '<p class="aat-bulk-controls">';
        printf('<a class="aat-bulk-refresh" href="%s"%s>%s</a> ', esc_url(remove_query_arg('aat_bulk') ?: admin_url('upload.php')), $finished ? ' hidden' : '', esc_html($s['refresh']));
        printf('<a class="aat-bulk-cancel" href="%s"%s>%s</a> ', esc_url($links['cancel']), ($finished || (int) $p['pending'] < 1) ? ' hidden' : '', esc_html($s['cancel']));
        printf('<a class="aat-bulk-dismiss" href="%s">%s</a>', esc_url($links['dismiss']), esc_html($finished ? $s['close'] : $s['hide']));
        echo '</p>';
        echo '<p id="aat-bulk-live" class="screen-reader-text" aria-live="polite" aria-atomic="true"></p>';
        echo '</div>';
        return (string) ob_get_clean();
    }
}
