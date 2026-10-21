<?php
class Auto_Alt_Text_Library_Schema_Test extends WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        Auto_Alt_Text_Activator::activate();
    }

    public function tearDown(): void {
        Auto_Alt_Text_Run_Context::set(null);
        parent::tearDown();
    }

    public function test_schema_is_1_4_with_the_runs_table_and_run_columns() {
        global $wpdb;
        $this->assertSame('1.4', get_option('auto_alt_text_db_version'));
        $runs = $wpdb->prefix . 'aat_runs';
        $this->assertSame($runs, Auto_Alt_Text_Activator::get_runs_table_name());
        $this->assertSame($runs, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $runs)));
        foreach ([$wpdb->prefix . 'aat_queue', $wpdb->prefix . 'auto_alt_text_stats'] as $table) {
            $this->assertContains('run_id', $wpdb->get_col("SHOW COLUMNS FROM {$table}"), $table);
        }
    }

    public function test_track_generation_stores_the_current_run_id() {
        global $wpdb;
        $stats = new Auto_Alt_Text_Statistics();
        $id = self::factory()->attachment->create();
        $table = $stats->get_table_name();

        Auto_Alt_Text_Run_Context::set(42);
        $stats->track_generation($id, 'x', 10, 'library');
        $row = $wpdb->get_row("SELECT * FROM {$table} ORDER BY id DESC LIMIT 1");
        $this->assertSame('42', $row->run_id);

        Auto_Alt_Text_Run_Context::set(null);
        $stats->track_generation($id, 'y', 10, 'library');
        $row = $wpdb->get_row("SELECT * FROM {$table} ORDER BY id DESC LIMIT 1");
        $this->assertNull($row->run_id);
    }

    public function test_log_lines_carry_the_current_run_id() {
        global $wpdb;
        $table = $wpdb->prefix . 'auto_alt_text_logs';

        Auto_Alt_Text_Run_Context::set(7);
        Auto_Alt_Text_Logger::log('m', 'error', ['attachment_id' => 1]);
        $ctx = json_decode($wpdb->get_var("SELECT context FROM {$table} ORDER BY id DESC LIMIT 1"), true);
        $this->assertSame(7, $ctx['run_id']);
        $this->assertSame(1, $ctx['attachment_id']);

        Auto_Alt_Text_Run_Context::set(null);
        Auto_Alt_Text_Logger::log('m2', 'error', ['attachment_id' => 1]);
        $ctx = json_decode($wpdb->get_var("SELECT context FROM {$table} ORDER BY id DESC LIMIT 1"), true);
        $this->assertArrayNotHasKey('run_id', $ctx);
    }
}
