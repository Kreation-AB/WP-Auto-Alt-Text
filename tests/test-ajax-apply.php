<?php
class Auto_Alt_Text_Ajax_Apply_Test extends WP_Ajax_UnitTestCase {
    public function test_applying_unchanged_alt_text_succeeds() {
        $this->_setRole('administrator');
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        update_post_meta($id, '_wp_attachment_image_alt', 'Same text');
        $_POST = [
            'nonce'         => wp_create_nonce('auto_alt_text_nonce'),
            'attachment_id' => $id,
            'alt_text'      => 'Same text',
            'original_text' => 'Same text',
            'is_edited'     => '0',
        ];

        global $wpdb;
        // WooCommerce's HPOS sync check on admin_init prints a temporary-table
        // DB error into the AJAX buffer in the test harness; keep the JSON clean.
        $suppress = $wpdb->suppress_errors(true);
        try {
            $this->_handleAjax('apply_alt_text');
        } catch (WPAjaxDieContinueException $e) {
            // wp_send_json_* ends the request.
        } finally {
            $wpdb->suppress_errors($suppress);
        }

        $response = json_decode($this->_last_response, true);
        $this->assertTrue($response['success'], $this->_last_response);
    }

    /** Runs apply_alt_text with $_POST slashed the way core's wp_magic_quotes() does. */
    private function apply_slashed(array $post) {
        $_POST = wp_slash($post);

        global $wpdb;
        $suppress = $wpdb->suppress_errors(true);
        try {
            $this->_handleAjax('apply_alt_text');
        } catch (WPAjaxDieContinueException $e) {
            // wp_send_json_* ends the request.
        } finally {
            $wpdb->suppress_errors($suppress);
        }

        return json_decode($this->_last_response, true);
    }

    public function test_applying_unchanged_alt_text_with_apostrophe_succeeds() {
        $this->_setRole('administrator');
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        update_post_meta($id, '_wp_attachment_image_alt', "It's a test");

        $response = $this->apply_slashed([
            'nonce'         => wp_create_nonce('auto_alt_text_nonce'),
            'attachment_id' => $id,
            'alt_text'      => "It's a test",
            'original_text' => "It's a test",
            'is_edited'     => '0',
        ]);

        $this->assertTrue($response['success'], $this->_last_response);
        $this->assertSame("It's a test", get_post_meta($id, '_wp_attachment_image_alt', true));
    }

    public function test_applying_edited_alt_text_with_apostrophe_updates_meta_and_stats_unslashed() {
        $this->_setRole('administrator');
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        global $wpdb;
        $stats_table = $wpdb->prefix . 'auto_alt_text_stats';
        $wpdb->insert($stats_table, [
            'image_id'        => $id,
            'user_id'         => get_current_user_id(),
            'generated_text'  => "A dog's bowl",
            'tokens_used'     => 10,
            'generation_type' => 'manual',
            'generation_time' => current_time('mysql'),
        ]);
        $stats_id = $wpdb->insert_id;

        $response = $this->apply_slashed([
            'nonce'         => wp_create_nonce('auto_alt_text_nonce'),
            'attachment_id' => $id,
            'alt_text'      => "A dog's red bowl",
            'original_text' => "A dog's bowl",
            'is_edited'     => '1',
        ]);

        $this->assertTrue($response['success'], $this->_last_response);
        $this->assertSame("A dog's red bowl", get_post_meta($id, '_wp_attachment_image_alt', true));
        $row = $wpdb->get_row($wpdb->prepare("SELECT is_applied, edited_text FROM {$stats_table} WHERE id = %d", $stats_id));
        $this->assertSame('1', $row->is_applied, 'Stats row for a quoted text must be matched');
        $this->assertSame("A dog's red bowl", $row->edited_text);
    }

    private function apply_text($attachment_id, $alt_text, $original_text) {
        $_POST = [
            'nonce'         => wp_create_nonce('auto_alt_text_nonce'),
            'attachment_id' => $attachment_id,
            'alt_text'      => wp_slash($alt_text),
            'original_text' => wp_slash($original_text),
            'is_edited'     => $alt_text === $original_text ? '0' : '1',
        ];
        global $wpdb;
        // WooCommerce's HPOS check on admin_init prints a temp-table DB error in the harness.
        $suppress = $wpdb->suppress_errors(true);
        try {
            $this->_handleAjax('apply_alt_text');
        } catch (WPAjaxDieContinueException $e) {
            // wp_send_json_* ends the request.
        } finally {
            $wpdb->suppress_errors($suppress);
        }
        $response = json_decode($this->_last_response, true);
        $this->_last_response = '';
        return $response;
    }

    public function test_applying_generated_text_unchanged_keeps_it_ai() {
        $this->_setRole('administrator');
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');

        $response = $this->apply_text($id, "A barn's red door", "A barn's red door");

        $this->assertTrue($response['success'], wp_json_encode($response));
        $this->assertSame('ai', Auto_Alt_Text_Provenance::classify($id));
        $this->assertSame('manual', get_post_meta($id, Auto_Alt_Text_Provenance::META_KEY, true)['source']);
    }

    public function test_applying_edited_text_makes_it_human() {
        $this->_setRole('administrator');
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        update_post_meta($id, '_wp_attachment_image_alt', 'Old AI text');
        Auto_Alt_Text_Provenance::record($id, 'upload');

        $response = $this->apply_text($id, 'Editor version', 'Generated version');

        $this->assertTrue($response['success'], wp_json_encode($response));
        $this->assertSame('human', Auto_Alt_Text_Provenance::classify($id));
    }

    /** The POST of the media modal applying $text unchanged; expected_alt is what its alt field held. */
    private function apply_post($attachment_id, $text, $expected_alt = null) {
        $post = [
            'nonce'         => wp_create_nonce('auto_alt_text_nonce'),
            'attachment_id' => $attachment_id,
            'alt_text'      => $text,
            'original_text' => $text,
            'is_edited'     => '0',
        ];
        if (null !== $expected_alt) {
            $post['expected_alt'] = $expected_alt;
        }
        return $post;
    }

    /** A stats row for $generated_text, as the preview's Generate request leaves it. */
    private function insert_stats_row($attachment_id, $generated_text) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'auto_alt_text_stats', [
            'image_id'        => $attachment_id,
            'user_id'         => get_current_user_id(),
            'generated_text'  => $generated_text,
            'tokens_used'     => 10,
            'generation_type' => 'manual',
            'generation_time' => current_time('mysql'),
        ]);
        return $wpdb->insert_id;
    }

    private function stats_row($stats_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'auto_alt_text_stats';
        return $wpdb->get_row($wpdb->prepare("SELECT is_applied, is_edited, edited_text FROM {$table} WHERE id = %d", $stats_id), ARRAY_A);
    }

    public function test_apply_with_matching_expected_alt_saves() {
        $this->_setRole('administrator');
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        update_post_meta($id, '_wp_attachment_image_alt', "The editor's old text");
        $stats_id = $this->insert_stats_row($id, 'A red barn');

        $response = $this->apply_slashed($this->apply_post($id, 'A red barn', "The editor's old text"));

        $this->assertTrue($response['success'], $this->_last_response);
        $this->assertSame('A red barn', get_post_meta($id, '_wp_attachment_image_alt', true));
        $this->assertSame('ai', Auto_Alt_Text_Provenance::classify($id));
        $this->assertSame('1', $this->stats_row($stats_id)['is_applied']);
    }

    public function test_apply_with_changed_alt_is_refused_and_writes_nothing() {
        $this->_setRole('administrator');
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        // A person saved their own text after the modal read the field (which was empty).
        update_post_meta($id, '_wp_attachment_image_alt', 'Skriven av redaktören');
        $stats_id = $this->insert_stats_row($id, 'A red barn');
        $stats_before = $this->stats_row($stats_id);

        $response = $this->apply_slashed($this->apply_post($id, 'A red barn', ''));

        $this->assertFalse($response['success'], $this->_last_response);
        $this->assertSame('aat_alt_changed', $response['data']['code']);
        $this->assertSame('Alt-texten har ändrats under tiden. Inget sparades.', $response['data']['message']);
        $this->assertSame('Skriven av redaktören', get_post_meta($id, '_wp_attachment_image_alt', true));
        $this->assertSame('', get_post_meta($id, Auto_Alt_Text_Provenance::META_KEY, true), 'No provenance may be recorded');
        $this->assertSame('human', Auto_Alt_Text_Provenance::classify($id));
        $this->assertSame($stats_before, $this->stats_row($stats_id));
        $this->assertSame('0', $stats_before['is_applied']);
    }

    public function test_apply_without_expected_alt_behaves_as_before() {
        $this->_setRole('administrator');
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        // The block editor sends no expected_alt: the text is applied over whatever is stored.
        update_post_meta($id, '_wp_attachment_image_alt', 'Skriven av redaktören');
        $stats_id = $this->insert_stats_row($id, 'A red barn');

        $response = $this->apply_slashed($this->apply_post($id, 'A red barn'));

        $this->assertTrue($response['success'], $this->_last_response);
        $this->assertSame('A red barn', $response['data']['alt_text']);
        $this->assertSame('A red barn', get_post_meta($id, '_wp_attachment_image_alt', true));
        $this->assertSame('ai', Auto_Alt_Text_Provenance::classify($id));
        $this->assertSame('1', $this->stats_row($stats_id)['is_applied']);
    }

    public function test_expected_alt_compare_trims_whitespace() {
        $this->_setRole('administrator');
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');

        update_post_meta($id, '_wp_attachment_image_alt', '  Gammal text ');
        $response = $this->apply_slashed($this->apply_post($id, 'A red barn', 'Gammal text'));
        $this->assertTrue($response['success'], $this->_last_response);
        $this->assertSame('A red barn', get_post_meta($id, '_wp_attachment_image_alt', true));

        $this->_last_response = '';
        update_post_meta($id, '_wp_attachment_image_alt', 'Gammal text');
        $response = $this->apply_slashed($this->apply_post($id, 'A green barn', " Gammal text\n"));
        $this->assertTrue($response['success'], $this->_last_response);
        $this->assertSame('A green barn', get_post_meta($id, '_wp_attachment_image_alt', true));
    }

    /**
     * The field holds the stored text exactly, and sanitize_text_field() turns a
     * lone "<" into "&lt;": both sides are normalised the same way, or such a
     * text could never be replaced from the media modal.
     */
    public function test_expected_alt_compare_matches_a_stored_text_with_a_less_than_sign() {
        $this->_setRole('administrator');
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        // As core's save-attachment stores it (wp_strip_all_tags() keeps a lone "<").
        update_post_meta($id, '_wp_attachment_image_alt', 'Barn < 3 år');

        $response = $this->apply_slashed($this->apply_post($id, 'A red barn', 'Barn < 3 år'));

        $this->assertTrue($response['success'], $this->_last_response);
        $this->assertSame('A red barn', get_post_meta($id, '_wp_attachment_image_alt', true));
    }
}
