<?php
class Auto_Alt_Text_Library_Scanner_Test extends WP_UnitTestCase {
    private $base_id = 0;

    public function setUp(): void {
        parent::setUp();
        Auto_Alt_Text_Activator::activate();
        global $wpdb;
        $this->base_id = (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}");
        delete_option('aat_library_counts');
        wp_unschedule_hook(Auto_Alt_Text_Provenance::BACKFILL_HOOK);
        wp_unschedule_hook('aat_library_recount');
    }

    private function image($alt = null, $mime = 'image/jpeg') {
        $id = self::factory()->attachment->create(['post_mime_type' => $mime]);
        if (null !== $alt) {
            update_post_meta($id, '_wp_attachment_image_alt', $alt);
        }
        return $id;
    }

    private function ai_image($alt = 'An AI text') {
        $id = $this->image($alt);
        Auto_Alt_Text_Provenance::record($id, 'test');
        return $id;
    }

    public function test_count_chunk_splits_missing_ai_human_and_unsupported() {
        $this->image();
        $this->image();
        $this->ai_image();
        $this->image('Written by an editor');
        $svg = $this->image(null, 'image/svg+xml');

        $counts = Auto_Alt_Text_Library_Scanner::count_chunk($this->base_id, 100);

        $this->assertSame(2, $counts['missing']);
        $this->assertSame(1, $counts['ai']);
        $this->assertSame(1, $counts['human']);
        $this->assertSame(1, $counts['unsupported']);
        $this->assertSame($svg, $counts['last_id']);
    }

    public function test_count_chunk_pages_by_id() {
        $this->image();
        $this->image();
        $third = $this->image();

        $first = Auto_Alt_Text_Library_Scanner::count_chunk($this->base_id, 2);
        $this->assertSame(2, $first['missing']);
        $second = Auto_Alt_Text_Library_Scanner::count_chunk($first['last_id'], 2);
        $this->assertSame(1, $second['missing']);
        $this->assertSame($third, $second['last_id']);
        $third_call = Auto_Alt_Text_Library_Scanner::count_chunk($second['last_id'], 2);
        $this->assertSame(0, $third_call['last_id']);
    }

    public function test_recount_drains_the_provenance_backfill_first() {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}auto_alt_text_stats");
        $id = $this->image('Latest AI text');
        $wpdb->insert($wpdb->prefix . 'auto_alt_text_stats', [
            'image_id'        => $id,
            'user_id'         => 0,
            'generated_text'  => 'Latest AI text',
            'tokens_used'     => 0,
            'generation_type' => 'upload',
            'generation_time' => '2026-01-01 10:00:00',
        ]);
        wp_schedule_single_event(time() + 30, Auto_Alt_Text_Provenance::BACKFILL_HOOK, [0]);
        $this->assertTrue(Auto_Alt_Text_Provenance::backfill_pending());

        Auto_Alt_Text_Library_Scanner::request_recount();
        $this->assertTrue(Auto_Alt_Text_Library_Scanner::recount_step(5.0));

        $this->assertFalse(Auto_Alt_Text_Provenance::backfill_pending());
        $counts = Auto_Alt_Text_Library_Scanner::cached_counts();
        $this->assertTrue($counts['complete']);
        $this->assertGreaterThanOrEqual(1, $counts['ai']);
        $this->assertSame('ai', Auto_Alt_Text_Provenance::classify($id));
    }

    public function test_cached_counts_default_to_zero_and_incomplete() {
        $counts = Auto_Alt_Text_Library_Scanner::cached_counts();
        $this->assertFalse($counts['complete']);
        $this->assertSame(0, $counts['missing']);
        $this->assertSame(0, $counts['cursor']);
    }

    public function test_avif_is_supported_only_when_the_editor_can_convert_it() {
        add_filter('wp_image_editors', function () { return ['Auto_Alt_Text_Stub_Editor_Yes']; });
        $this->assertContains('image/avif', Auto_Alt_Text_Image_Source::supported_input_mimes());

        add_filter('wp_image_editors', function () { return ['Auto_Alt_Text_Stub_Editor_No']; }, 20);
        $this->assertNotContains('image/avif', Auto_Alt_Text_Image_Source::supported_input_mimes());
        $this->assertContains('image/jpeg', Auto_Alt_Text_Image_Source::supported_input_mimes());
    }

    public function test_is_candidate_follows_coverage() {
        $ai = $this->ai_image();
        $human = $this->image('Written by an editor');
        $missing = $this->image();
        $svg = $this->image(null, 'image/svg+xml');
        $S = 'Auto_Alt_Text_Library_Scanner';

        $this->assertFalse($S::is_candidate($ai, $S::COVERAGE_MISSING));
        $this->assertTrue($S::is_candidate($ai, $S::COVERAGE_MISSING_AI));
        $this->assertFalse($S::is_candidate($human, $S::COVERAGE_MISSING));
        $this->assertFalse($S::is_candidate($human, $S::COVERAGE_MISSING_AI));
        $this->assertTrue($S::is_candidate($missing, $S::COVERAGE_MISSING));
        $this->assertFalse($S::is_candidate($svg, $S::COVERAGE_MISSING));
        $this->assertSame(Auto_Alt_Text_Provenance::POLICY_AI, $S::policy_for_coverage($S::COVERAGE_MISSING_AI));
        $this->assertSame(Auto_Alt_Text_Provenance::POLICY_MISSING, $S::policy_for_coverage('bogus'));
    }
}

class Auto_Alt_Text_Stub_Editor_Yes extends WP_Image_Editor {
    public static function test($args = []) { return true; }
    public static function supports_mime_type($mime_type) { return 'image/avif' === $mime_type; }
    public function load() {}
    public function save($destfilename = null, $mime_type = null) {}
    public function resize($max_w, $max_h, $crop = false) {}
    public function multi_resize($sizes) {}
    public function crop($src_x, $src_y, $src_w, $src_h, $dst_w = null, $dst_h = null, $src_abs = false) {}
    public function rotate($angle) {}
    public function flip($horz, $vert) {}
    public function stream($mime_type = null) {}
}

class Auto_Alt_Text_Stub_Editor_No extends Auto_Alt_Text_Stub_Editor_Yes {
    public static function supports_mime_type($mime_type) { return false; }
}
