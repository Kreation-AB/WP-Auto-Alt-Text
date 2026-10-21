<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The "Hela biblioteket" admin page and the status card on the settings front
 * page. The page prints a server-side first paint from cached figures and the
 * run API only (no count queries over posts); admin/js/library.js takes over
 * from the REST payload.
 */
class Auto_Alt_Text_Library_Page {
    public const SLUG = 'auto-alt-text-library';
    private const SCRIPT_HANDLE = 'auto-alt-text-library';
    private const POLL_SECONDS = 10;
    private const FAILURES_PAGE = 50;

    /** @var array<string,string>|null */
    private static ?array $strings = null;

    /** Script and localized data, on this page only. */
    public static function enqueue($hook_suffix): void {
        if (false === strpos((string) $hook_suffix, self::SLUG)) {
            return;
        }
        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            plugin_dir_url(dirname(__FILE__)) . 'admin/js/library.js',
            [],
            AUTO_ALT_TEXT_VERSION,
            true
        );
        add_filter('script_loader_tag', [self::class, 'module_tag'], 10, 2);
        $offset = wp_timezone()->getOffset(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        wp_localize_script(self::SCRIPT_HANDLE, 'aatLibrary', [
            // Base of the library routes; the script appends /start, /pause and so on.
            'restUrl' => rest_url('wp-auto-alt-text/v1/library'),
            'nonce' => wp_create_nonce('wp_rest'),
            'pollSeconds' => self::POLL_SECONDS,
            'estimatePerImage' => self::estimate_per_image(),
            'now' => time(),
            'siteOffsetMinutes' => (int) ($offset / 60),
            'editUrl' => admin_url('post.php?action=edit&post='),
            'i18n' => self::strings(),
        ]);
    }

    /** Marks the page script as an ES module. */
    public static function module_tag($tag, $handle) {
        if (self::SCRIPT_HANDLE !== $handle) {
            return $tag;
        }
        $tag = preg_replace('/\s+type=([\'"])[^\'"]*\1/', '', (string) $tag);
        return (string) preg_replace('/<script\s/', '<script type="module" ', (string) $tag, 1);
    }

    private static function estimate_per_image(): float {
        return Auto_Alt_Text_Models::cost_per_1000(Auto_Alt_Text_Models::current_id()) / 1000;
    }

    public static function fmt_int(int $n): string {
        return number_format($n, 0, ',', "\u{00A0}");
    }

    public static function fmt_usd(float $usd): string {
        return number_format($usd, $usd < 1 ? 3 : 2, ',', "\u{00A0}");
    }

    private static function minutes_ago(int $timestamp): int {
        return max(0, (int) floor((time() - $timestamp) / 60));
    }

    /**
     * Every UI string, shared by the first paint and the script. %s and %1$s
     * style placeholders are filled by sprintf() here and by the script.
     *
     * @return array<string,string>
     */
    public static function strings(): array {
        if (null === self::$strings) {
            self::$strings = [
                'title' => __('Hela biblioteket', 'WP-Auto-Alt-Text'),
                'intro' => __('Skriv alt-texter för bilderna i mediebiblioteket som saknar dem. Körningen sker i bakgrunden, så du kan lämna sidan.', 'WP-Auto-Alt-Text'),
                'counts_heading' => __('Så ser biblioteket ut', 'WP-Auto-Alt-Text'),
                'count_missing' => __('Saknar alt-text', 'WP-Auto-Alt-Text'),
                'count_ai' => __('Skrivna av pluginet, oredigerade', 'WP-Auto-Alt-Text'),
                'count_human' => __('Skrivna av en person – rörs aldrig', 'WP-Auto-Alt-Text'),
                'count_unsupported' => __('Bildformat som stöds inte – kan inte behandlas', 'WP-Auto-Alt-Text'),
                // translators: placeholders are numbers (counts, minutes, USD) or a clock time.
                'counts_recounting' => __('Räknar om biblioteket… (senast uppdaterat för %d min sedan)', 'WP-Auto-Alt-Text'),
                'counts_first' => __('Räknar biblioteket…', 'WP-Auto-Alt-Text'),
                // translators: placeholders are numbers (counts, minutes, USD) or a clock time.
                'counts_updated' => __('Senast uppdaterat för %d min sedan.', 'WP-Auto-Alt-Text'),
                // translators: placeholders are numbers (counts, minutes, USD) or a clock time.
                'coverage_label' => __('Skriv även om pluginets egna, oredigerade alt-texter (%s)', 'WP-Auto-Alt-Text'),
                'coverage_help' => __('Alt-texter som en person har skrivit eller ändrat rörs aldrig.', 'WP-Auto-Alt-Text'),
                // translators: placeholders are numbers (counts, minutes, USD) or a clock time.
                'estimate' => __('Antal bilder att behandla: %1$s. Uppskattad kostnad: ca %2$s USD.', 'WP-Auto-Alt-Text'),
                'estimate_none' => __('Det finns inga bilder att behandla.', 'WP-Auto-Alt-Text'),
                'start' => __('Starta…', 'WP-Auto-Alt-Text'),
                'new_run_heading' => __('Starta en ny körning', 'WP-Auto-Alt-Text'),
                'start_heading' => __('Starta en körning', 'WP-Auto-Alt-Text'),
                'dialog_start_title' => __('Starta körningen?', 'WP-Auto-Alt-Text'),
                // translators: placeholders are numbers (counts, minutes, USD) or a clock time.
                'dialog_start_body' => __('Antal bilder att behandla: %1$s. Uppskattad kostnad: ca %2$s USD.', 'WP-Auto-Alt-Text'),
                'dialog_background' => __('Körningen fortsätter i bakgrunden även om du stänger fliken.', 'WP-Auto-Alt-Text'),
                'dialog_dismiss' => __('Avbryt', 'WP-Auto-Alt-Text'),
                'dialog_confirm' => __('Starta', 'WP-Auto-Alt-Text'),
                'cancel_dialog_title' => __('Avbryta körningen?', 'WP-Auto-Alt-Text'),
                'cancel_dialog_body' => __('Bilder som redan har fått alt-text behåller den. Resten av bilderna hoppas över.', 'WP-Auto-Alt-Text'),
                'cancel_dialog_keep' => __('Fortsätt köra', 'WP-Auto-Alt-Text'),
                'cancel_dialog_confirm' => __('Ja, avbryt körningen', 'WP-Auto-Alt-Text'),
                'active_heading' => __('Körning pågår', 'WP-Auto-Alt-Text'),
                'paused_heading' => __('Körningen är pausad', 'WP-Auto-Alt-Text'),
                // translators: placeholders are numbers (counts, minutes, USD) or a clock time.
                'progress_text' => __('%1$s av %2$s bilder klara', 'WP-Auto-Alt-Text'),
                // translators: placeholders are numbers (counts, minutes, USD) or a clock time.
                'progress_value' => __('%1$s av %2$s', 'WP-Auto-Alt-Text'),
                // translators: placeholders are numbers (counts, minutes, USD) or a clock time.
                'waiting' => __('Väntar på nytt försök kl %s', 'WP-Auto-Alt-Text'),
                // translators: placeholders are numbers (counts, minutes, USD) or a clock time.
                'eta' => __('Uppskattad tid kvar: %s', 'WP-Auto-Alt-Text'),
                'eta_calculating' => __('Uppskattad tid kvar: beräknas…', 'WP-Auto-Alt-Text'),
                'eta_less_than_minute' => __('mindre än 1 min', 'WP-Auto-Alt-Text'),
                // translators: placeholders are numbers (counts, minutes, USD) or a clock time.
                'eta_minutes' => __('ca %d min', 'WP-Auto-Alt-Text'),
                // translators: placeholders are numbers (counts, minutes, USD) or a clock time.
                'eta_hours' => __('ca %1$d h %2$d min', 'WP-Auto-Alt-Text'),
                // translators: placeholders are numbers (counts, minutes, USD) or a clock time.
                'failed_so_far' => __('Misslyckade hittills: %s', 'WP-Auto-Alt-Text'),
                // translators: placeholders are numbers (counts, minutes, USD) or a clock time.
                'cost_so_far' => __('Kostnad hittills: %1$s USD av uppskattade %2$s USD', 'WP-Auto-Alt-Text'),
                'recent_heading' => __('Senaste resultat', 'WP-Auto-Alt-Text'),
                'recent_none' => __('Inga resultat ännu.', 'WP-Auto-Alt-Text'),
                'outcome_filled' => __('Fylld', 'WP-Auto-Alt-Text'),
                'outcome_skipped' => __('Hoppad över', 'WP-Auto-Alt-Text'),
                'outcome_failed' => __('Misslyckad', 'WP-Auto-Alt-Text'),
                'pause' => __('Pausa', 'WP-Auto-Alt-Text'),
                'resume' => __('Fortsätt', 'WP-Auto-Alt-Text'),
                'cancel' => __('Avbryt', 'WP-Auto-Alt-Text'),
                'paused_user' => __('Pausad av dig.', 'WP-Auto-Alt-Text'),
                'paused_quota' => __('Pausad: OpenAI-kvoten eller betalningen räcker inte. Körningen fortsätter automatiskt när det fungerar igen.', 'WP-Auto-Alt-Text'),
                'paused_auth' => __('Pausad: OpenAI avvisade API-nyckeln. Kontrollera nyckeln under Inställningar.', 'WP-Auto-Alt-Text'),
                'final_finished' => __('Körningen är klar', 'WP-Auto-Alt-Text'),
                'final_cancelled' => __('Körningen avbröts', 'WP-Auto-Alt-Text'),
                'sum_filled' => __('Fyllda', 'WP-Auto-Alt-Text'),
                'sum_failed' => __('Misslyckade', 'WP-Auto-Alt-Text'),
                'sum_skipped' => __('Hoppade över', 'WP-Auto-Alt-Text'),
                'sum_skipped_note' => __('en person hann skriva alt-text under tiden', 'WP-Auto-Alt-Text'),
                'sum_cost' => __('Kostnad', 'WP-Auto-Alt-Text'),
                // translators: placeholders are numbers (counts, minutes, USD) or a clock time.
                'failures_heading' => __('Misslyckade bilder (%s)', 'WP-Auto-Alt-Text'),
                'failures_col_file' => __('Fil', 'WP-Auto-Alt-Text'),
                'failures_col_reason' => __('Orsak', 'WP-Auto-Alt-Text'),
                'failures_unknown' => __('Okänd orsak', 'WP-Auto-Alt-Text'),
                'failures_more' => __('Visa fler', 'WP-Auto-Alt-Text'),
                'retry' => __('Försök igen med de misslyckade', 'WP-Auto-Alt-Text'),
                'report' => __('Ladda ner rapport', 'WP-Auto-Alt-Text'),
                'report_hint' => __('Rapporten innehåller inga nycklar och inga alt-texter, så du kan skicka den till din webbyrå.', 'WP-Auto-Alt-Text'),
                'warning_stalled' => __('Körningen har inte gått framåt på över 10 minuter. Webbplatsen kan inte starta bakgrundsjobb själv – be din webbhotell aktivera WP-Cron eller lägga in ett riktigt cron-jobb.', 'WP-Auto-Alt-Text'),
                'warning_cron' => __('WP-Cron är avstängt (DISABLE_WP_CRON). Körningen behöver ett system-cron som anropar wp-cron.php.', 'WP-Auto-Alt-Text'),
                'error_generic' => __('Något gick fel. Försök igen.', 'WP-Auto-Alt-Text'),
                'error_poll' => __('Kunde inte hämta status just nu. Försöker igen.', 'WP-Auto-Alt-Text'),
                'live_started' => __('Körningen har startat.', 'WP-Auto-Alt-Text'),
                'live_paused' => __('Körningen är pausad.', 'WP-Auto-Alt-Text'),
                'live_running' => __('Körningen pågår.', 'WP-Auto-Alt-Text'),
                'live_finished' => __('Körningen är klar.', 'WP-Auto-Alt-Text'),
                'live_cancelled' => __('Körningen avbröts.', 'WP-Auto-Alt-Text'),
                // translators: placeholders are numbers (counts, minutes, USD) or a clock time.
                'live_progress' => __('%1$s av %2$s bilder klara.', 'WP-Auto-Alt-Text'),
                'card_title' => __('Hela biblioteket', 'WP-Auto-Alt-Text'),
                // translators: placeholders are numbers (counts, minutes, USD) or a clock time.
                'card_missing' => __('Bilder som saknar alt-text: %s', 'WP-Auto-Alt-Text'),
                // translators: placeholders are numbers (counts, minutes, USD) or a clock time.
                'card_updated' => __('uppdaterat för %d min sedan', 'WP-Auto-Alt-Text'),
                'card_counting' => __('Biblioteket räknas…', 'WP-Auto-Alt-Text'),
                'card_not_counted' => __('Öppna Hela biblioteket för att se hur många bilder som saknar alt-text.', 'WP-Auto-Alt-Text'),
                // translators: placeholders are numbers (counts, minutes, USD) or a clock time.
                'card_running' => __('Körning pågår: %1$s av %2$s bilder klara.', 'WP-Auto-Alt-Text'),
                // translators: placeholders are numbers (counts, minutes, USD) or a clock time.
                'card_paused' => __('Körningen är pausad: %1$s av %2$s bilder klara.', 'WP-Auto-Alt-Text'),
                // translators: placeholders are numbers (counts, minutes, USD) or a clock time.
                'card_finished' => __('Senaste körningen är klar: %1$s fyllda, %2$s misslyckade.', 'WP-Auto-Alt-Text'),
                // translators: placeholders are numbers (counts, minutes, USD) or a clock time.
                'card_cancelled' => __('Senaste körningen avbröts: %1$s fyllda, %2$s misslyckade.', 'WP-Auto-Alt-Text'),
                'card_link' => __('Öppna Hela biblioteket', 'WP-Auto-Alt-Text'),
            ];
        }
        return self::$strings;
    }

    private static function page_url(): string {
        return admin_url('admin.php?page=' . self::SLUG);
    }

    private static function is_active_run(?array $run): bool {
        return null !== $run && in_array($run['status'], [Auto_Alt_Text_Library_Run::STATUS_RUNNING, Auto_Alt_Text_Library_Run::STATUS_PAUSED], true);
    }

    private static function reason_text(string $display_state): string {
        $s = self::strings();
        $map = ['paused_user' => 'paused_user', 'paused_quota' => 'paused_quota', 'paused_auth' => 'paused_auth'];
        return isset($map[$display_state]) ? $s[$map[$display_state]] : '';
    }

    /** The settings front page card. Cached figures and the run API only. */
    public static function render_status_card(): void {
        $s = self::strings();
        $run = Auto_Alt_Text_Library_Run::active() ?? Auto_Alt_Text_Library_Run::latest();
        if (null === $run) {
            $counts = Auto_Alt_Text_Library_Scanner::cached_counts();
            if ($counts['complete']) {
                $text = sprintf($s['card_missing'], self::fmt_int($counts['missing']))
                    . ' (' . sprintf($s['card_updated'], self::minutes_ago($counts['updated_at'])) . ')';
            } elseif ($counts['recounting']) {
                $text = $s['card_counting'];
            } else {
                $text = $s['card_not_counted'];
            }
        } else {
            $progress = Auto_Alt_Text_Library_Run::progress($run);
            $done = self::fmt_int($progress['done']);
            $total = self::fmt_int($progress['total']);
            if (self::is_active_run($run)) {
                $state = Auto_Alt_Text_Library_Run::display_state($run);
                $running = 'running' === $state['state'];
                $text = sprintf($running ? $s['card_running'] : $s['card_paused'], $done, $total);
                $reason = self::reason_text($state['state']);
                if ('' !== $reason) {
                    $text .= ' ' . $reason;
                }
            } else {
                $key = Auto_Alt_Text_Library_Run::STATUS_FINISHED === $run['status'] ? 'card_finished' : 'card_cancelled';
                $text = sprintf($s[$key], self::fmt_int($progress['filled']), self::fmt_int($progress['failed']));
            }
        }
        ?>
        <section class="aat-lib-card" aria-labelledby="aat-library-card-title">
            <h2 id="aat-library-card-title"><?php echo esc_html($s['card_title']); ?></h2>
            <p><?php echo esc_html($text); ?></p>
            <p><a class="button" href="<?php echo esc_url(self::page_url()); ?>"><?php echo esc_html($s['card_link']); ?></a></p>
        </section>
        <?php
    }

    /** The page. Server-side first paint; library.js updates the same IDs. */
    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'WP-Auto-Alt-Text'));
        }
        $s = self::strings();

        $counts = Auto_Alt_Text_Library_Scanner::cached_counts();
        if (!$counts['recounting'] && (!$counts['complete'] || (time() - $counts['updated_at']) > HOUR_IN_SECONDS)) {
            // Only schedules the recount; nothing is counted while the page renders.
            Auto_Alt_Text_Library_Scanner::request_recount();
            $counts = Auto_Alt_Text_Library_Scanner::cached_counts();
        }

        $run = Auto_Alt_Text_Library_Run::active() ?? Auto_Alt_Text_Library_Run::latest();
        $active = self::is_active_run($run);
        $final = null !== $run && !$active;
        $state = null === $run ? null : Auto_Alt_Text_Library_Run::display_state($run);
        $progress = null === $run ? null : Auto_Alt_Text_Library_Run::progress($run);
        $health = $active ? Auto_Alt_Text_Library_Runner::health($run) : ['stalled' => false, 'cron_disabled' => false];
        $waiting = $active ? Auto_Alt_Text_Library_Runner::waiting_until($run) : null;
        $paused = $active && 'running' !== $state['state'];
        $per_image = self::estimate_per_image();
        $candidates = $counts['missing'];
        $hidden = static function (bool $hide): void {
            if ($hide) {
                echo ' hidden';
            }
        };

        if ($counts['recounting'] && $counts['complete']) {
            $count_status = sprintf($s['counts_recounting'], self::minutes_ago($counts['updated_at']));
        } elseif (!$counts['complete']) {
            $count_status = $s['counts_first'];
        } else {
            $count_status = sprintf($s['counts_updated'], self::minutes_ago($counts['updated_at']));
        }

        $done = $progress ? $progress['done'] : 0;
        $total = $progress ? $progress['total'] : 0;
        $pct = $total > 0 ? min(100, (int) round($done / $total * 100)) : 0;
        $cost = null === $run ? 0.0 : ($final ? (float) $run['summary']['cost_usd'] : Auto_Alt_Text_Library_Run::cost_so_far((int) $run['id']));
        $estimate_text = $candidates > 0
            ? sprintf($s['estimate'], self::fmt_int($candidates), self::fmt_usd($candidates * $per_image))
            : $s['estimate_none'];
        $failures = [];
        if ($final && is_array($run['summary']['failures'] ?? null)) {
            $failures = array_slice($run['summary']['failures'], 0, self::FAILURES_PAGE);
        }
        $failures_total = $progress ? max((int) $progress['failed'], count($failures)) : 0;
        ?>
        <div class="wrap aat-library" id="aat-library">
            <h1><?php echo esc_html($s['title']); ?></h1>
            <p class="aat-lib-intro"><?php echo esc_html($s['intro']); ?></p>

            <div id="aat-library-warning" class="aat-lib-warning"<?php $hidden(!$health['stalled'] || null !== $waiting); ?>>
                <p id="aat-library-warning-stalled"><?php echo esc_html($s['warning_stalled']); ?></p>
                <p id="aat-library-warning-cron"<?php $hidden(!$health['cron_disabled']); ?>><?php echo esc_html($s['warning_cron']); ?></p>
            </div>
            <p id="aat-library-error" class="aat-lib-error" role="alert" hidden></p>

            <section id="aat-library-active" class="aat-lib-section" aria-labelledby="aat-library-active-title"<?php $hidden(!$active); ?>>
                <h2 id="aat-library-active-title" tabindex="-1"><?php echo esc_html($paused ? $s['paused_heading'] : $s['active_heading']); ?></h2>
                <p id="aat-library-pause-reason" class="aat-lib-note"<?php $hidden(!$paused); ?>><?php echo esc_html($state ? self::reason_text($state['state']) : ''); ?></p>
                <p id="aat-library-progress-text" class="aat-lib-progress-text"><?php echo esc_html(sprintf($s['progress_text'], self::fmt_int($done), self::fmt_int($total))); ?></p>
                <div id="aat-library-progress" class="aat-lib-bar" role="progressbar"
                    aria-labelledby="aat-library-progress-text"
                    aria-valuemin="0" aria-valuemax="<?php echo esc_attr((string) $total); ?>" aria-valuenow="<?php echo esc_attr((string) $done); ?>"
                    aria-valuetext="<?php echo esc_attr(sprintf($s['progress_value'], self::fmt_int($done), self::fmt_int($total))); ?>">
                    <div class="aat-lib-bar-fill" id="aat-library-progress-fill" style="width:<?php echo esc_attr((string) $pct); ?>%"></div>
                </div>
                <p id="aat-library-waiting" class="aat-lib-note"<?php $hidden(null === $waiting); ?>><?php
                    echo esc_html(null === $waiting ? '' : sprintf($s['waiting'], wp_date('H:i', (int) strtotime($waiting . ' UTC'))));
                ?></p>
                <ul class="aat-lib-stats">
                    <li id="aat-library-eta"<?php $hidden($paused || null !== $waiting); ?>><?php echo esc_html($s['eta_calculating']); ?></li>
                    <li id="aat-library-failed-count"><?php echo esc_html(sprintf($s['failed_so_far'], self::fmt_int($progress ? $progress['failed'] : 0))); ?></li>
                    <li id="aat-library-cost"><?php echo esc_html(sprintf($s['cost_so_far'], self::fmt_usd($cost), self::fmt_usd($run ? (float) $run['estimate_usd'] : 0.0))); ?></li>
                </ul>
                <h3 id="aat-library-recent-title"><?php echo esc_html($s['recent_heading']); ?></h3>
                <ul id="aat-library-recent" class="aat-lib-recent" aria-labelledby="aat-library-recent-title"><li><?php echo esc_html($s['recent_none']); ?></li></ul>
                <p class="aat-lib-actions">
                    <button type="button" class="button" id="aat-library-pause"<?php $hidden(!$active || Auto_Alt_Text_Library_Run::STATUS_RUNNING !== $run['status']); ?>><?php echo esc_html($s['pause']); ?></button>
                    <button type="button" class="button" id="aat-library-resume"<?php $hidden(!$active || Auto_Alt_Text_Library_Run::STATUS_PAUSED !== $run['status']); ?>><?php echo esc_html($s['resume']); ?></button>
                    <button type="button" class="button" id="aat-library-cancel"><?php echo esc_html($s['cancel']); ?></button>
                </p>
            </section>

            <section id="aat-library-final" class="aat-lib-section" aria-labelledby="aat-library-final-title"<?php $hidden(!$final); ?>>
                <h2 id="aat-library-final-title" tabindex="-1"><?php echo esc_html($final && Auto_Alt_Text_Library_Run::STATUS_CANCELLED === $run['status'] ? $s['final_cancelled'] : $s['final_finished']); ?></h2>
                <dl class="aat-lib-summary">
                    <div><dt><?php echo esc_html($s['sum_filled']); ?></dt><dd id="aat-library-sum-filled"><?php echo esc_html(self::fmt_int($progress ? $progress['filled'] : 0)); ?></dd></div>
                    <div><dt><?php echo esc_html($s['sum_failed']); ?></dt><dd id="aat-library-sum-failed"><?php echo esc_html(self::fmt_int($progress ? $progress['failed'] : 0)); ?></dd></div>
                    <div><dt><?php echo esc_html($s['sum_skipped']); ?></dt><dd><span id="aat-library-sum-skipped"><?php echo esc_html(self::fmt_int($progress ? $progress['skipped'] : 0)); ?></span> <span class="aat-lib-note">(<?php echo esc_html($s['sum_skipped_note']); ?>)</span></dd></div>
                    <div><dt><?php echo esc_html($s['sum_cost']); ?></dt><dd id="aat-library-sum-cost"><?php echo esc_html(self::fmt_usd($cost)); ?> USD</dd></div>
                </dl>
                <div id="aat-library-failures"<?php $hidden(0 === $failures_total); ?>>
                    <h3 id="aat-library-failures-title"><?php echo esc_html(sprintf($s['failures_heading'], self::fmt_int($failures_total))); ?></h3>
                    <table class="widefat striped aat-lib-failures-table" aria-labelledby="aat-library-failures-title">
                        <thead><tr><th scope="col"><?php echo esc_html($s['failures_col_file']); ?></th><th scope="col"><?php echo esc_html($s['failures_col_reason']); ?></th></tr></thead>
                        <tbody id="aat-library-failures-body">
                            <?php foreach ($failures as $failure) : ?>
                                <tr>
                                    <td><?php echo esc_html((string) ($failure['file'] ?? '')); ?></td>
                                    <td><?php echo esc_html('' !== (string) ($failure['reason'] ?? '') ? (string) $failure['reason'] : $s['failures_unknown']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p><button type="button" class="button" id="aat-library-failures-more"<?php $hidden(count($failures) >= $failures_total); ?>><?php echo esc_html($s['failures_more']); ?></button></p>
                </div>
                <p><button type="button" class="button button-primary" id="aat-library-retry"<?php $hidden(!$progress || 0 === (int) $progress['failed']); ?>><?php echo esc_html($s['retry']); ?></button></p>
            </section>

            <section id="aat-library-before" class="aat-lib-section" aria-labelledby="aat-library-before-title"<?php $hidden($active); ?>>
                <h2 id="aat-library-before-title"><?php echo esc_html($final ? $s['new_run_heading'] : $s['counts_heading']); ?></h2>
                <dl id="aat-library-counts" class="aat-lib-counts">
                    <div><dt><?php echo esc_html($s['count_missing']); ?></dt><dd data-count="missing"><?php echo esc_html(self::fmt_int($counts['missing'])); ?></dd></div>
                    <div><dt><?php echo esc_html($s['count_ai']); ?></dt><dd data-count="ai"><?php echo esc_html(self::fmt_int($counts['ai'])); ?></dd></div>
                    <div><dt><?php echo esc_html($s['count_human']); ?></dt><dd data-count="human"><?php echo esc_html(self::fmt_int($counts['human'])); ?></dd></div>
                    <div><dt><?php echo esc_html($s['count_unsupported']); ?></dt><dd data-count="unsupported"><?php echo esc_html(self::fmt_int($counts['unsupported'])); ?></dd></div>
                </dl>
                <p id="aat-library-count-status" class="aat-lib-note"><?php echo esc_html($count_status); ?></p>
                <p>
                    <input type="checkbox" id="aat-library-coverage" aria-describedby="aat-library-coverage-help">
                    <label for="aat-library-coverage" id="aat-library-coverage-label"><?php echo esc_html(sprintf($s['coverage_label'], self::fmt_int($counts['ai']))); ?></label><br>
                    <span id="aat-library-coverage-help" class="aat-lib-note"><?php echo esc_html($s['coverage_help']); ?></span>
                </p>
                <p id="aat-library-estimate" data-per-image="<?php echo esc_attr((string) $per_image); ?>"><?php echo esc_html($estimate_text); ?></p>
                <p><button type="button" class="button button-primary" id="aat-library-start" aria-describedby="aat-library-estimate"<?php echo disabled(!$counts['complete'] || $candidates < 1, true, false); ?>><?php echo esc_html($s['start']); ?></button></p>
            </section>

            <section id="aat-library-report-section" class="aat-lib-section"<?php $hidden(null === $run); ?>>
                <p>
                    <a id="aat-library-report" class="button" href="<?php echo esc_url($run ? wp_specialchars_decode(wp_nonce_url(admin_url('admin-post.php?action=aat_library_report&run=' . (int) $run['id']), 'aat_library_report')) : '#'); ?>"><?php echo esc_html($s['report']); ?></a>
                    <span class="aat-lib-note"><?php echo esc_html($s['report_hint']); ?></span>
                </p>
            </section>

            <p id="aat-library-live" class="screen-reader-text" aria-live="polite" aria-atomic="true"></p>

            <dialog id="aat-library-dialog" class="aat-lib-dialog" aria-labelledby="aat-library-dialog-title">
                <h2 id="aat-library-dialog-title"><?php echo esc_html($s['dialog_start_title']); ?></h2>
                <p id="aat-library-dialog-summary"><?php echo esc_html($estimate_text); ?></p>
                <p><?php echo esc_html($s['dialog_background']); ?></p>
                <p class="aat-lib-actions">
                    <button type="button" class="button" id="aat-library-dialog-dismiss"><?php echo esc_html($s['dialog_dismiss']); ?></button>
                    <button type="button" class="button button-primary" id="aat-library-dialog-confirm"><?php echo esc_html($s['dialog_confirm']); ?></button>
                </p>
            </dialog>

            <dialog id="aat-library-cancel-dialog" class="aat-lib-dialog" aria-labelledby="aat-library-cancel-dialog-title">
                <h2 id="aat-library-cancel-dialog-title"><?php echo esc_html($s['cancel_dialog_title']); ?></h2>
                <p><?php echo esc_html($s['cancel_dialog_body']); ?></p>
                <p class="aat-lib-actions">
                    <button type="button" class="button" id="aat-library-cancel-dialog-keep"><?php echo esc_html($s['cancel_dialog_keep']); ?></button>
                    <button type="button" class="button button-primary" id="aat-library-cancel-dialog-confirm"><?php echo esc_html($s['cancel_dialog_confirm']); ?></button>
                </p>
            </dialog>
        </div>
        <?php
    }
}
