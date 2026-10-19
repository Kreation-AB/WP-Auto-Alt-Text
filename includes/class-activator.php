<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class Auto_Alt_Text_Activator {
    /**
     * Activates the plugin by creating or updating the necessary database tables.
     *
     * @return void
     */

    private const CURRENT_DB_VERSION = '1.0';
    private const COLUMN_VERSION = 'db_version';
    /** Bump whenever a CREATE TABLE statement below changes. */
    private const SCHEMA_VERSION = '1.3';
    public const DB_VERSION_OPTION = 'auto_alt_text_db_version';

    private const COLUMN_GENERATION_TYPE = 'generation_type';
    private const COLUMN_IS_EDITED = 'is_edited';
    private const COLUMN_EDITED_TEXT = 'edited_text';
    private const COLUMN_IS_APPLIED = 'is_applied';
    private const COLUMN_IMAGE_ID = 'image_id';
    private const COLUMN_USER_ID = 'user_id';
    private const COLUMN_GENERATED_TEXT = 'generated_text';
    private const COLUMN_TOKENS_USED = 'tokens_used';
    private const COLUMN_GENERATION_TIME = 'generation_time';

    /**
     * Retrieves the global WordPress database object.
     *
     * @return wpdb The global WordPress database object.
     */
    private static function get_db() {
        global $wpdb;
        return $wpdb;
    }

    /**
     * Retrieves the name of the stats table in the database.
     *
     * @return string The name of the stats table.
     */
    private static function get_stats_table_name() {
        return self::get_db()->prefix . 'auto_alt_text_stats';
    }

    /**
     * Retrieves the name of the logs table in the database.
     *
     * @return string The name of the logs table.
     */
    private static function get_logs_table_name() {
        return self::get_db()->prefix . 'auto_alt_text_logs';
    }

    /**
     * Retrieves the name of the generation queue table.
     *
     * @return string
     */
    private static function get_queue_table_name() {
        return self::get_db()->prefix . 'aat_queue';
    }

    /**
     * Checks if the specified column exists in the given database table.
     *
     * @param string $table The name of the database table.
     * @param string $column The name of the column to check.
     * @return bool True if the column exists, false otherwise.
     */
    private static function column_exists($table, $column) {
        $wpdb = self::get_db();
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        return $columns !== false && in_array($column, $columns);
    }

    /**
     * (Removed) Version tracking is handled via dbDelta during table creation.
     */

    /** Option holding the time a request took the schema upgrade lock. */
    private const UPGRADE_LOCK_OPTION  = 'aat_schema_upgrade_lock';
    private const UPGRADE_LOCK_SECONDS = 5 * MINUTE_IN_SECONDS;

    /**
     * Cheap per-request check: runs dbDelta only when the schema version changed.
     * A DB-level lock keeps concurrent requests from running the upgrade at the
     * same time.
     *
     * @param bool $require_current True from lazy guards in code that is about to
     *                              write to the plugin tables: while another
     *                              request holds the lock, wait for it (filter
     *                              `aat_schema_upgrade_wait_seconds`, default 5)
     *                              and, if the schema is still outdated, run the
     *                              idempotent upgrade here rather than write
     *                              against missing columns.
     * @return bool Whether the schema was (re)applied by this call.
     */
    public static function maybe_upgrade($require_current = false) {
        if (!empty(self::$schema_verified[get_current_blog_id()])) {
            return false;
        }
        if (get_option(self::DB_VERSION_OPTION) === self::SCHEMA_VERSION) {
            self::$schema_verified[get_current_blog_id()] = true;
            return false;
        }

        if (!self::acquire_upgrade_lock()) {
            if (!$require_current) {
                return false; // Another request is upgrading right now.
            }
            if (self::wait_for_upgrade()) {
                self::$schema_verified[get_current_blog_id()] = true;
                return false;
            }
            self::activate();
            self::$schema_verified[get_current_blog_id()] = self::stored_schema_version() === self::SCHEMA_VERSION;
            return true;
        }

        try {
            // Another request may have finished the upgrade while we waited.
            if (self::stored_schema_version() === self::SCHEMA_VERSION) {
                self::$schema_verified[get_current_blog_id()] = true;
                return false;
            }
            self::activate();
            self::$schema_verified[get_current_blog_id()] = self::stored_schema_version() === self::SCHEMA_VERSION;
        } finally {
            self::release_upgrade_lock();
        }
        return true;
    }

    /** Set once this request has confirmed a site's schema is current (keyed by blog ID), so later lazy guards cost nothing. */
    private static $schema_verified = [];

    /**
     * Forgets the per-request "schema is current" result.
     *
     * @internal Test seam.
     */
    public static function reset_schema_verified() {
        self::$schema_verified = [];
    }

    /**
     * Reads the schema version straight from the DB. get_option() can be stale
     * here (the option is autoloaded, so this request holds the value it started
     * with), which is why a confirmed result is remembered in $schema_verified.
     */
    private static function stored_schema_version() {
        $wpdb = self::get_db();
        $version = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            self::DB_VERSION_OPTION
        ));
        return $version;
    }

    /** Polls until the lock holder has finished, or the wait runs out. @return bool Whether the schema is current. */
    private static function wait_for_upgrade() {
        $deadline = microtime(true) + (float) apply_filters('aat_schema_upgrade_wait_seconds', 5);
        while (self::stored_schema_version() !== self::SCHEMA_VERSION) {
            if (microtime(true) >= $deadline) {
                return false;
            }
            usleep(250000);
        }
        return true;
    }

    /**
     * Takes the upgrade lock like WP_Upgrader::create_lock(): an atomic INSERT
     * IGNORE on the options table (bypasses the options cache, whose notoptions
     * list can hide a lock another request just took). A stale lock is taken
     * over with a compare-and-swap.
     */
    private static function acquire_upgrade_lock() {
        $wpdb = self::get_db();
        for ($try = 0; $try < 2; $try++) {
            $inserted = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                self::UPGRADE_LOCK_OPTION, (string) time()
            ));
            if ($inserted) {
                return true;
            }

            $held = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                self::UPGRADE_LOCK_OPTION
            ));
            if (null === $held) {
                continue; // Released between our INSERT and SELECT: try once more.
            }
            if ((int) $held > time() - self::UPGRADE_LOCK_SECONDS) {
                return false;
            }
            return (bool) $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                (string) time(), self::UPGRADE_LOCK_OPTION, $held
            ));
        }
        return false;
    }

    private static function release_upgrade_lock() {
        $wpdb = self::get_db();
        $wpdb->delete($wpdb->options, ['option_name' => self::UPGRADE_LOCK_OPTION]);
        wp_cache_delete(self::UPGRADE_LOCK_OPTION, 'options');
    }

    /**
     * Activates the plugin by creating or updating the necessary database tables.
     *
     * This method performs the following tasks:
     * - Adds version tracking columns to the stats and logs tables
     * - Adds additional columns to the stats table (generation_type, is_edited, edited_text, is_applied)
     * - Creates or updates the stats and logs tables with the necessary schema
     *
     * If any errors occur during the activation process, an error message is logged and displayed in the WordPress admin area.
     */
    public static function activate() {
        $wpdb = self::get_db();
        $charset_collate = $wpdb->get_charset_collate();
        $stats_table = self::get_stats_table_name();
        $logs_table = self::get_logs_table_name();
        $queue_table = self::get_queue_table_name();

        try {
            // Create or update tables using dbDelta which handles schema changes safely
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

            $create_stats_sql = "CREATE TABLE {$stats_table} (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                " . self::COLUMN_IMAGE_ID . " bigint(20) NOT NULL,
                " . self::COLUMN_USER_ID . " bigint(20) NOT NULL,
                " . self::COLUMN_GENERATED_TEXT . " text NOT NULL,
                " . self::COLUMN_TOKENS_USED . " int(11) NOT NULL DEFAULT 0,
                " . self::COLUMN_GENERATION_TYPE . " varchar(50) NOT NULL DEFAULT 'manual',
                " . self::COLUMN_GENERATION_TIME . " datetime NOT NULL,
                " . self::COLUMN_IS_APPLIED . " tinyint(1) DEFAULT 0,
                " . self::COLUMN_IS_EDITED . " tinyint(1) DEFAULT 0,
                " . self::COLUMN_EDITED_TEXT . " text DEFAULT NULL,
                model varchar(64) DEFAULT NULL,
                input_tokens int(10) unsigned DEFAULT NULL,
                output_tokens int(10) unsigned DEFAULT NULL,
                " . self::COLUMN_VERSION . " varchar(10) NOT NULL DEFAULT '" . self::CURRENT_DB_VERSION . "',
                PRIMARY KEY  (id),
                KEY idx_image (image_id,generation_time)
            ) $charset_collate;";

            $logs_table = $wpdb->prefix . 'auto_alt_text_logs';
            $create_logs_sql = "CREATE TABLE {$logs_table} (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                timestamp datetime DEFAULT CURRENT_TIMESTAMP,
                level varchar(10) NOT NULL,
                message text NOT NULL,
                context text,
                " . self::COLUMN_VERSION . " varchar(10) NOT NULL DEFAULT '" . self::CURRENT_DB_VERSION . "',
                PRIMARY KEY  (id)
            ) $charset_collate;";

            $create_queue_sql = "CREATE TABLE {$queue_table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                attachment_id bigint(20) unsigned NOT NULL,
                generation_type varchar(32) NOT NULL DEFAULT 'upload',
                context_post_id bigint(20) unsigned DEFAULT NULL,
                status varchar(16) NOT NULL DEFAULT 'pending',
                attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
                next_attempt_at datetime NOT NULL,
                last_error text DEFAULT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY idx_attachment (attachment_id),
                KEY idx_claim (status,next_attempt_at)
            ) $charset_collate;";

            dbDelta($create_stats_sql);
            dbDelta($create_logs_sql);
            dbDelta($create_queue_sql);

            self::migrate_settings();

            // Record the version only once the queue table really exists, so a
            // failed CREATE is retried by maybe_upgrade() on the next request.
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($queue_table)))) {
                update_option(self::DB_VERSION_OPTION, self::SCHEMA_VERSION);
            }

            // One-time provenance backfill for alt text generated before 2.0
            // (spec §3). Idempotent: already fingerprinted images are skipped.
            // A string hook name, because this can run before other classes load.
            if (!wp_next_scheduled('aat_provenance_backfill', [0])) {
                wp_schedule_single_event(time() + MINUTE_IN_SECONDS, 'aat_provenance_backfill', [0]);
            }


        } catch (Exception $e) {
            add_action('admin_notices', function() use ($e) {
                echo '<div class="error"><p>Auto Alt Text activation error: ' . esc_html($e->getMessage()) . '</p></div>';
            });
        }
    }

    /**
     * Schema 1.3 (generation-quality spec §3): the brand tonality switch
     * becomes a naming level, and the never-used prompt template option goes.
     * Idempotent. Option names are literals because this can run before the
     * other classes load.
     */
    private static function migrate_settings() {
        if (false === get_option('auto_alt_text_naming', false)) {
            $brand_tonality = get_option('wp_auto_alt_text_enable_brand_tonality', false);
            update_option('auto_alt_text_naming', $brand_tonality ? 'product_brand' : 'visible');
        }
        delete_option('wp_auto_alt_text_enable_brand_tonality');
        delete_option('alt_text_prompt_template');
    }
}
