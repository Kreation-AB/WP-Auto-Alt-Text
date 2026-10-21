<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The settings page (spec §4–§5): notices, a status strip, a sidebar of
 * section links with one-line summaries, and six sections in one options.php
 * form. Without JavaScript every section shows, stacked; admin/js/settings.js
 * turns the sidebar into tabs and relies on this markup's IDs and classes.
 */
class Auto_Alt_Text_Settings_Page {
    public const SECTIONS = ['anslutning', 'sprak', 'namn', 'automatik', 'cache', 'feedback'];

    /** The section to show first: ?section=, or the first section for a missing or unknown value. */
    public static function active_section(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view state.
        $section = isset($_GET['section']) && is_string($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : '';
        return in_array($section, self::SECTIONS, true) ? $section : self::SECTIONS[0];
    }

    /**
     * One line per section, keyed by slug. Plain text: escape it at output.
     *
     * @return array<string,array{text:string,error:bool}>
     */
    public static function summaries(): array {
        return self::build_summaries(Auto_Alt_Text_Key_Status::current(), Auto_Alt_Text_Cache_Manager::count_entries());
    }

    /** Naming level => its short label, for the summary. */
    public static function naming_labels(): array {
        return [
            Auto_Alt_Text_Prompt::NAMING_VISIBLE       => __('Bara det som syns', 'WP-Auto-Alt-Text'),
            Auto_Alt_Text_Prompt::NAMING_PRODUCT       => __('Produktnamn', 'WP-Auto-Alt-Text'),
            Auto_Alt_Text_Prompt::NAMING_PRODUCT_BRAND => __('Produkt- och varumärkesnamn', 'WP-Auto-Alt-Text'),
        ];
    }

    /** Overwrite policy => its short label, for the summary. */
    public static function policy_labels(): array {
        return [
            Auto_Alt_Text_Provenance::POLICY_MISSING => __('fyller bara saknade', 'WP-Auto-Alt-Text'),
            Auto_Alt_Text_Provenance::POLICY_AI      => __('skriver över oredigerade AI-texter', 'WP-Auto-Alt-Text'),
            Auto_Alt_Text_Provenance::POLICY_ALL     => __('skriver över allt', 'WP-Auto-Alt-Text'),
        ];
    }

    /** The whole page. The caller checks the capability. Reads stored state only; never calls OpenAI. */
    public static function render(): void {
        $key_status = Auto_Alt_Text_Key_Status::current();
        $cache_count = Auto_Alt_Text_Cache_Manager::count_entries();
        $summaries = self::build_summaries($key_status, $cache_count);
        $headings = self::headings();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <div class="aat-settings" data-active-section="<?php echo esc_attr(self::active_section()); ?>">
                <?php
                settings_errors();
                self::render_notice();
                self::render_strip($key_status);
                ?>
                <nav class="aat-settings-nav" aria-label="<?php esc_attr_e('Inställningar', 'WP-Auto-Alt-Text'); ?>">
                    <ul>
                        <?php foreach (self::SECTIONS as $slug) : ?>
                            <?php $summary_class = 'aat-nav-summary' . ($summaries[$slug]['error'] ? ' aat-nav-summary--error' : ''); ?>
                            <li><a href="#aat-section-<?php echo esc_attr($slug); ?>" id="aat-tab-<?php echo esc_attr($slug); ?>" data-section="<?php echo esc_attr($slug); ?>"><span class="aat-nav-name"><?php echo esc_html($headings[$slug]); ?></span> <span class="<?php echo esc_attr($summary_class); ?>" id="aat-summary-<?php echo esc_attr($slug); ?>"><?php echo esc_html($summaries[$slug]['text']); ?></span></a></li>
                        <?php endforeach; ?>
                    </ul>
                </nav>
                <form method="post" action="options.php" id="aat-settings-form">
                    <?php
                    wp_nonce_field('auto_alt_text_nonce_action', 'auto_alt_text_nonce');
                    settings_fields(AUTO_ALT_TEXT_SETTINGS_GROUP);
                    do_settings_sections('auto-alt-text');
                    ?>
                    <?php foreach (self::SECTIONS as $slug) : ?>
                        <section class="aat-settings-section" id="aat-section-<?php echo esc_attr($slug); ?>" aria-labelledby="aat-section-<?php echo esc_attr($slug); ?>-title">
                            <h2 id="aat-section-<?php echo esc_attr($slug); ?>-title"><?php echo esc_html($headings[$slug]); ?></h2>
                            <?php
                            switch ($slug) {
                                case 'anslutning':
                                    self::render_connection($key_status);
                                    break;
                                case 'sprak':
                                    self::render_language();
                                    break;
                                case 'namn':
                                    self::render_naming();
                                    break;
                                case 'automatik':
                                    self::render_automation();
                                    break;
                                case 'cache':
                                    self::render_cache($cache_count);
                                    break;
                                case 'feedback':
                                    self::render_feedback();
                                    break;
                            }
                            ?>
                        </section>
                    <?php endforeach; ?>
                    <?php submit_button(); ?>
                </form>
            </div>
            <?php self::render_side_forms(); ?>
        </div>
        <?php
    }

    private static function headings(): array {
        return [
            'anslutning' => __('Anslutning', 'WP-Auto-Alt-Text'),
            'sprak'      => __('Språk', 'WP-Auto-Alt-Text'),
            'namn'       => __('Namn och varumärke', 'WP-Auto-Alt-Text'),
            'automatik'  => __('Automatik', 'WP-Auto-Alt-Text'),
            'cache'      => __('Cache', 'WP-Auto-Alt-Text'),
            'feedback'   => __('Feedback', 'WP-Auto-Alt-Text'),
        ];
    }

    /** @return array<string,array{text:string,error:bool}> */
    private static function build_summaries(array $key_status, ?int $cache_count): array {
        $on_off = static function ($on): string {
            return $on ? __('På', 'WP-Auto-Alt-Text') : __('Av', 'WP-Auto-Alt-Text');
        };

        $languages = AUTO_ALT_TEXT_LANGUAGES;
        $language = get_option(AUTO_ALT_TEXT_LANGUAGE_OPTION, 'en');

        $naming = Auto_Alt_Text_Prompt::naming();
        $name_text = self::naming_labels()[$naming];
        $brand = get_option('aat_brand_name', '');
        $brand = is_string($brand) ? trim($brand) : '';
        if (Auto_Alt_Text_Prompt::NAMING_PRODUCT_BRAND === $naming && '' !== $brand) {
            $name_text .= ' · ' . $brand;
        }

        $days = (int) get_option('aat_cache_expiration', 30);
        /* translators: %d: number of days */
        $cache_text = sprintf(_n('%d dag', '%d dagar', $days, 'WP-Auto-Alt-Text'), $days);
        if (null !== $cache_count) {
            /* translators: %s: number of cached answers */
            $cache_text .= ' · ' . sprintf(_n('%s sparat', '%s sparade', $cache_count, 'WP-Auto-Alt-Text'), number_format_i18n($cache_count));
        }

        return [
            'anslutning' => self::connection_summary($key_status),
            'sprak'      => self::summary(is_string($language) && isset($languages[$language]) ? $languages[$language] : $languages['en']),
            'namn'       => self::summary($name_text),
            'automatik'  => self::summary($on_off(get_option('auto_alt_text_auto_generate', true)) . ' · ' . self::policy_labels()[Auto_Alt_Text_Provenance::default_policy()]),
            'cache'      => self::summary($cache_text),
            'feedback'   => self::summary($on_off(get_option('auto_alt_text_enable_feedback', true))),
        ];
    }

    /** @return array{text:string,error:bool} */
    private static function summary(string $text, bool $error = false): array {
        return ['text' => $text, 'error' => $error];
    }

    /** @return array{text:string,error:bool} */
    private static function connection_summary(array $key_status): array {
        $state = $key_status['state'];
        if (Auto_Alt_Text_Key_Status::OK === $state) {
            /* translators: %s: model name */
            return self::summary(sprintf(__('✓ Nyckel · %s', 'WP-Auto-Alt-Text'), self::model_label($key_status['model'])));
        }
        $errors = [
            Auto_Alt_Text_Key_Status::NONE              => __('Nyckel saknas', 'WP-Auto-Alt-Text'),
            Auto_Alt_Text_Key_Status::AUTH_FAILED       => __('Nyckeln fungerar inte', 'WP-Auto-Alt-Text'),
            Auto_Alt_Text_Key_Status::MODEL_UNAVAILABLE => __('Modellen är inte tillgänglig', 'WP-Auto-Alt-Text'),
            Auto_Alt_Text_Key_Status::QUOTA             => __('Kvoten är slut', 'WP-Auto-Alt-Text'),
        ];
        return isset($errors[$state]) ? self::summary($errors[$state], true) : self::summary(__('Inte kontrollerad', 'WP-Auto-Alt-Text'));
    }

    /** The Anslutning status line (spec §6.2), with the check's age where the stored check is the source. */
    private static function key_status_text(array $key_status): string {
        $model = self::model_label($key_status['model']);
        $texts = [
            /* translators: %s: model name */
            Auto_Alt_Text_Key_Status::OK                => sprintf(__('✓ Nyckeln fungerar och har tillgång till %s', 'WP-Auto-Alt-Text'), $model),
            Auto_Alt_Text_Key_Status::AUTH_FAILED       => __('Nyckeln godkänns inte av OpenAI', 'WP-Auto-Alt-Text'),
            /* translators: %s: model name */
            Auto_Alt_Text_Key_Status::MODEL_UNAVAILABLE => sprintf(__('Nyckeln fungerar, men %s är inte tillgänglig för den', 'WP-Auto-Alt-Text'), $model),
            Auto_Alt_Text_Key_Status::QUOTA             => __('Kvoten hos OpenAI är slut', 'WP-Auto-Alt-Text'),
            Auto_Alt_Text_Key_Status::UNKNOWN           => __('Kunde inte kontrollera just nu', 'WP-Auto-Alt-Text'),
            Auto_Alt_Text_Key_Status::NONE              => __('Nyckel saknas', 'WP-Auto-Alt-Text'),
        ];
        $text = $texts[$key_status['state']] ?? __('Inte kontrollerad', 'WP-Auto-Alt-Text');

        // auth_failed and quota come from live signals; the stored record's time says nothing about them.
        $from_record = [Auto_Alt_Text_Key_Status::OK, Auto_Alt_Text_Key_Status::MODEL_UNAVAILABLE, Auto_Alt_Text_Key_Status::UNKNOWN];
        if (null !== $key_status['checked_at'] && in_array($key_status['state'], $from_record, true)) {
            /* translators: %s: time since the check, e.g. "3 timmar" */
            $text .= ' (' . sprintf(__('kontrollerad för %s sedan', 'WP-Auto-Alt-Text'), human_time_diff($key_status['checked_at'])) . ')';
        }
        return $text;
    }

    private static function model_label(string $model_id): string {
        $model = Auto_Alt_Text_Models::get($model_id);
        return null !== $model ? $model['label'] : $model_id;
    }

    private static function render_notice(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flags. The form's referer keeps aat_notice, so a later save's page carries it too: show it once.
        if (isset($_GET['aat_notice']) && 'cache_cleared' === $_GET['aat_notice'] && !isset($_GET['settings-updated'])) {
            printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__('Cachen är tömd.', 'WP-Auto-Alt-Text'));
        }
    }

    /** Without a key: a warning that leads to Anslutning. Otherwise the library status card. */
    private static function render_strip(array $key_status): void {
        if (Auto_Alt_Text_Key_Status::NONE === $key_status['state']) {
            printf(
                '<div class="notice notice-warning inline aat-settings-strip"><p>%1$s <a href="#aat-section-anslutning">%2$s</a></p></div>',
                esc_html__('Ange en API-nyckel för att börja generera alt-texter.', 'WP-Auto-Alt-Text'),
                esc_html__('Till Anslutning', 'WP-Auto-Alt-Text')
            );
            return;
        }
        echo '<div class="aat-settings-strip">';
        Auto_Alt_Text_Library_Page::render_status_card();
        echo '</div>';
    }

    /** The side actions' own forms, after the options form (buttons use form="…"). Nonce without id or referer: handlers redirect to fixed URLs. */
    private static function render_side_forms(): void {
        $forms = [
            'aat-check-key-form'   => Auto_Alt_Text_Key_Status::CHECK_ACTION,
            'aat-clear-cache-form' => Auto_Alt_Text_Cache_Manager::CLEAR_ACTION,
        ];
        foreach ($forms as $id => $action) : ?>
            <form id="<?php echo esc_attr($id); ?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce($action)); ?>">
            </form>
        <?php endforeach;
    }

    private static function render_connection(array $key_status): void {
        $key_from_constant = defined('AUTO_ALT_TEXT_OPENAI_API_KEY') && AUTO_ALT_TEXT_OPENAI_API_KEY;
        $has_saved_key = !empty(get_option('auto_alt_text_api_key'));
        // The raw setting, not current_id(): with no saved choice the site follows the default (spec §6),
        // and saving the page must not pin today's default.
        $selected_model = Auto_Alt_Text_Models::sanitize(get_option(Auto_Alt_Text_Models::OPTION, ''));
        ?>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="api_key"><?php esc_html_e('OpenAI API-nyckel', 'WP-Auto-Alt-Text'); ?></label></th>
                <td>
                    <input type="password"
                        id="api_key"
                        name="auto_alt_text_api_key"
                        class="regular-text"
                        value=""
                        autocomplete="off"
                        placeholder="<?php echo esc_attr($has_saved_key ? __('Sparad nyckel – lämna tomt för att behålla', 'WP-Auto-Alt-Text') : 'sk-…'); ?>"
                        <?php disabled($key_from_constant); ?>
                    />
                    <?php if ($key_from_constant) : ?>
                        <p class="description"><?php esc_html_e('Nyckeln är definierad i wp-config.php (AUTO_ALT_TEXT_OPENAI_API_KEY).', 'WP-Auto-Alt-Text'); ?></p>
                    <?php endif; ?>
                    <p class="description"><?php esc_html_e('Pluginen använder OpenAI Responses API och anpassar automatiskt takten efter OpenAI:s aktuella rate limit-headers.', 'WP-Auto-Alt-Text'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Status', 'WP-Auto-Alt-Text'); ?></th>
                <td>
                    <p class="aat-key-status aat-key-status--<?php echo esc_attr(sanitize_html_class($key_status['state'])); ?>" id="aat-key-status"><?php echo esc_html(self::key_status_text($key_status)); ?></p>
                    <?php if (Auto_Alt_Text_Key_Status::NONE !== $key_status['state']) : ?>
                        <p><button type="submit" class="button" form="aat-check-key-form"><?php esc_html_e('Kontrollera nu', 'WP-Auto-Alt-Text'); ?></button></p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="model"><?php esc_html_e('Modell', 'WP-Auto-Alt-Text'); ?></label></th>
                <td>
                    <select id="model" name="<?php echo esc_attr(Auto_Alt_Text_Models::OPTION); ?>">
                        <option value="" <?php selected($selected_model, ''); ?>>
                            <?php
                            echo esc_html(sprintf(
                                /* translators: %s: the default model's name and cost hint */
                                __('Standard: %s', 'WP-Auto-Alt-Text'),
                                auto_alt_text_model_option_label(Auto_Alt_Text_Models::resolve(''))
                            ));
                            ?>
                        </option>
                        <?php foreach (array_keys(Auto_Alt_Text_Models::all()) as $model_id) : ?>
                            <option value="<?php echo esc_attr($model_id); ?>" <?php selected($selected_model, $model_id); ?>>
                                <?php echo esc_html(auto_alt_text_model_option_label($model_id)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php esc_html_e('Vattenmärkning: slås på i det OpenAI-konto som äger nyckeln (Organization/Project settings → Data controls → Text provenance), inte här.', 'WP-Auto-Alt-Text'); ?>
                        <a href="https://help.openai.com/en/articles/8912793-provenance-signals-in-openai-generated-content" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Läs mer', 'WP-Auto-Alt-Text'); ?></a>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    private static function render_language(): void {
        $current_language = get_option(AUTO_ALT_TEXT_LANGUAGE_OPTION, 'en');
        $current_language = is_string($current_language) ? $current_language : '';
        ?>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="aat_language"><?php esc_html_e('Alt-textens språk', 'WP-Auto-Alt-Text'); ?></label></th>
                <td>
                    <select id="aat_language" name="<?php echo esc_attr(AUTO_ALT_TEXT_LANGUAGE_OPTION); ?>" class="regular-text">
                        <?php foreach (auto_alt_text_get_supported_languages() as $code => $name) : ?>
                            <option value="<?php echo esc_attr($code); ?>" <?php selected($current_language, $code); ?>><?php echo esc_html($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e('Välj standardspråk för genererade alternativa texter. När WPML eller Polylang används följer genereringen i stället den aktuella språkversionen för bilagan.', 'WP-Auto-Alt-Text'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    private static function render_naming(): void {
        $naming = Auto_Alt_Text_Prompt::naming();
        ?>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="naming"><?php esc_html_e('Namn i alt-texten', 'WP-Auto-Alt-Text'); ?></label></th>
                <td>
                    <select id="naming" name="<?php echo esc_attr(Auto_Alt_Text_Prompt::NAMING_OPTION); ?>">
                        <option value="visible" <?php selected($naming, 'visible'); ?>><?php esc_html_e('Bara det som syns i bilden', 'WP-Auto-Alt-Text'); ?></option>
                        <option value="product" <?php selected($naming, 'product'); ?>><?php esc_html_e('Produktnamn', 'WP-Auto-Alt-Text'); ?></option>
                        <option value="product_brand" <?php selected($naming, 'product_brand'); ?>><?php esc_html_e('Produkt- och varumärkesnamn', 'WP-Auto-Alt-Text'); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e('Gäller bara bilder som hör till en enda produkt. Övriga bilder beskriver bara det som syns.', 'WP-Auto-Alt-Text'); ?></p>
                    <p class="description"><?php esc_html_e('Med Produktnamn finns produktens namn alltid med i alt-texten när bilden hör till en enda produkt. Sidor och inlägg används bara som sammanhang.', 'WP-Auto-Alt-Text'); ?></p>
                </td>
            </tr>
            <tr class="aat-brand-name-row">
                <th scope="row"><label for="aat_brand_name"><?php esc_html_e('Varumärkets namn', 'WP-Auto-Alt-Text'); ?></label></th>
                <td>
                    <input type="text" id="aat_brand_name" name="aat_brand_name" value="<?php echo esc_attr(get_option('aat_brand_name')); ?>" aria-describedby="aat-brand-name-note" />
                    <p class="description" id="aat-brand-name-note"><?php esc_html_e('Används med "Produkt- och varumärkesnamn".', 'WP-Auto-Alt-Text'); ?></p>
                </td>
            </tr>
            <tr class="aat-brand-description-row">
                <th scope="row"><label for="aat_brand_description"><?php esc_html_e('Varumärkesbeskrivning', 'WP-Auto-Alt-Text'); ?></label></th>
                <td>
                    <textarea id="aat_brand_description" name="aat_brand_description" rows="3" placeholder="<?php echo esc_attr__('Ange en beskrivning av ditt varumärke', 'WP-Auto-Alt-Text'); ?>" aria-describedby="aat-brand-description-note"><?php echo esc_textarea(get_option('aat_brand_description')); ?></textarea>
                    <p class="description" id="aat-brand-description-note"><?php esc_html_e('Används med "Produktnamn" och "Produkt- och varumärkesnamn". Används som bakgrund för ordval, aldrig ordagrant.', 'WP-Auto-Alt-Text'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="extra_instructions"><?php esc_html_e('Extra instruktioner', 'WP-Auto-Alt-Text'); ?></label></th>
                <td>
                    <textarea id="extra_instructions" name="<?php echo esc_attr(Auto_Alt_Text_Prompt::EXTRA_OPTION); ?>" rows="3" class="large-text" maxlength="<?php echo (int) Auto_Alt_Text_Prompt::EXTRA_MAX_LENGTH; ?>"><?php echo esc_textarea(get_option(Auto_Alt_Text_Prompt::EXTRA_OPTION, '')); ?></textarea>
                    <p class="description"><?php esc_html_e('Läggs till sist i pluginets instruktioner, till exempel "Nämn alltid färgen". De kan inte ändra svarets format.', 'WP-Auto-Alt-Text'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    private static function render_automation(): void {
        $overwrite_policy = Auto_Alt_Text_Provenance::default_policy();
        ?>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="auto_generate"><?php esc_html_e('Generera alt-texter automatiskt', 'WP-Auto-Alt-Text'); ?></label></th>
                <td>
                    <label class="switch">
                        <input type="checkbox"
                            id="auto_generate"
                            name="auto_alt_text_auto_generate"
                            value="1"
                            <?php checked(get_option('auto_alt_text_auto_generate', true)); ?>>
                        <span class="slider round"></span>
                    </label>
                    <p class="description"><?php esc_html_e('Köar nya bilder och produktbilder som saknar alt-text. Manuell generering (knappen i mediebiblioteket, massåtgärder, WP-CLI och REST) fungerar alltid.', 'WP-Auto-Alt-Text'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="overwrite_policy"><?php esc_html_e('Skriv över befintliga alt-texter vid massgenerering', 'WP-Auto-Alt-Text'); ?></label></th>
                <td>
                    <select id="overwrite_policy" name="<?php echo esc_attr(Auto_Alt_Text_Provenance::POLICY_OPTION); ?>">
                        <option value="missing" <?php selected($overwrite_policy, 'missing'); ?>><?php esc_html_e('Nej – fyll bara i saknade alt-texter', 'WP-Auto-Alt-Text'); ?></option>
                        <option value="ai" <?php selected($overwrite_policy, 'ai'); ?>><?php esc_html_e('Ja, men bara oredigerade AI-texter', 'WP-Auto-Alt-Text'); ?></option>
                        <option value="all" <?php selected($overwrite_policy, 'all'); ?>><?php esc_html_e('Ja, alla – även texter skrivna av en redaktör', 'WP-Auto-Alt-Text'); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e('Gäller massåtgärder, REST /batch och WP-CLI (som kan välja med --overwrite). Generera-knappen för en enskild bild skriver alltid över.', 'WP-Auto-Alt-Text'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    private static function render_cache(?int $cache_count): void {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="cache_duration"><?php esc_html_e('Spara svar i (dagar)', 'WP-Auto-Alt-Text'); ?></label></th>
                <td>
                    <input type="number"
                        id="cache_duration"
                        name="aat_cache_expiration"
                        value="<?php echo absint(get_option('aat_cache_expiration', 30)); ?>"
                        min="1"
                        max="365"
                    />
                    <p class="description"><?php esc_html_e('Samma bild med samma inställningar ger samma svar utan ny kostnad. Ändrar du en inställning som påverkar texten skapas en ny. I WP-CLI hoppar --no-cache över sparade svar.', 'WP-Auto-Alt-Text'); ?></p>
                </td>
            </tr>
        </table>
        <?php if (null !== $cache_count) : ?>
            <p class="aat-cache-count">
                <?php
                /* translators: %s: number of cached answers */
                echo esc_html(sprintf(_n('%s sparat svar', '%s sparade svar', $cache_count, 'WP-Auto-Alt-Text'), number_format_i18n($cache_count)));
                ?>
            </p>
        <?php endif; ?>
        <p><button type="submit" class="button" form="aat-clear-cache-form"><?php esc_html_e('Töm cache', 'WP-Auto-Alt-Text'); ?></button></p>
        <?php
    }

    private static function render_feedback(): void {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="enable_feedback"><?php esc_html_e('Aktivera feedback i alt-text-dialogen', 'WP-Auto-Alt-Text'); ?></label></th>
                <td>
                    <label class="switch">
                        <input type="checkbox"
                            name="auto_alt_text_enable_feedback"
                            id="enable_feedback"
                            value="1"
                            <?php checked(get_option('auto_alt_text_enable_feedback', true)); ?>>
                        <span class="slider round"></span>
                    </label>
                    <p class="description"><?php esc_html_e('Visar Förbättra-delen i dialogen där en genererad alt-text granskas.', 'WP-Auto-Alt-Text'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }
}
