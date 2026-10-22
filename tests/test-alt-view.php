<?php
class Auto_Alt_Text_Alt_View_Test extends WP_UnitTestCase {
    private function image($alt = null) {
        $id = self::factory()->attachment->create(['post_mime_type' => 'image/jpeg']);
        if (null !== $alt) {
            update_post_meta($id, '_wp_attachment_image_alt', $alt);
        }
        return $id;
    }

    public function set_up() {
        parent::set_up();
        add_action('pre_get_posts', [Auto_Alt_Text_Alt_View::class, 'filter_list_query']);
    }

    public function tear_down() {
        remove_action('pre_get_posts', [Auto_Alt_Text_Alt_View::class, 'filter_list_query']);
        unset($_GET['aat_alt']);
        unset($GLOBALS['current_screen']);
        parent::tear_down();
    }

    private function main_query(array $args = []) {
        set_current_screen('upload');
        $_GET['aat_alt'] = 'missing';
        $query = new WP_Query();
        $GLOBALS['wp_the_query'] = $query;
        $query->query(array_merge(['post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC'], $args));
        return $query->posts;
    }

    public function test_missing_filter_matches_absent_empty_and_whitespace_alt() {
        $a = $this->image();
        $b = $this->image('');
        $c = $this->image("  \n");
        $d = $this->image('Text');
        $found = array_map('intval', $this->main_query());
        $this->assertSame([$a, $b, $c], array_values(array_intersect($found, [$a, $b, $c, $d])));
        $this->assertNotContains($d, $found);
    }

    public function test_missing_filter_excludes_non_images() {
        $pdf = self::factory()->attachment->create(['post_mime_type' => 'application/pdf']);
        $img = $this->image();
        $found = array_map('intval', $this->main_query());
        $this->assertContains($img, $found);
        $this->assertNotContains($pdf, $found);
    }

    public function test_missing_clauses_append_to_an_existing_meta_query() {
        $existing = [['key' => 'x', 'value' => 'y']];
        $vars = Auto_Alt_Text_Alt_View::missing_clauses(['meta_query' => $existing, 'post_mime_type' => 'image/png']);
        $this->assertSame('AND', $vars['meta_query']['relation']);
        $this->assertSame($existing, $vars['meta_query'][0]);
        $this->assertSame('OR', $vars['meta_query'][1]['relation']);
        $this->assertSame('image/png', $vars['post_mime_type']);
        $plain = Auto_Alt_Text_Alt_View::missing_clauses([]);
        $this->assertSame('image', $plain['post_mime_type']);
        $this->assertSame('OR', $plain['meta_query'][0]['relation']);
    }

    public function test_filter_does_nothing_without_the_param_off_screen_or_on_secondary_queries() {
        $img_missing = $this->image();
        $img_alt = $this->image('Text');

        // No param.
        set_current_screen('upload');
        $query = new WP_Query();
        $GLOBALS['wp_the_query'] = $query;
        $query->query(['post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids']);
        $this->assertContains($img_alt, array_map('intval', $query->posts));

        // Off screen.
        set_current_screen('dashboard');
        $_GET['aat_alt'] = 'missing';
        $query = new WP_Query();
        $GLOBALS['wp_the_query'] = $query;
        $query->query(['post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids']);
        $this->assertContains($img_alt, array_map('intval', $query->posts));

        // Secondary query.
        set_current_screen('upload');
        $GLOBALS['wp_the_query'] = new WP_Query();
        $secondary = new WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids']);
        $this->assertContains($img_alt, array_map('intval', $secondary->posts));
        $this->assertContains($img_missing, array_map('intval', $secondary->posts));
    }

    public function test_filter_dropdown_has_a_label_and_keeps_the_selection() {
        set_current_screen('upload');
        ob_start();
        Auto_Alt_Text_Alt_View::render_filter('attachment');
        $html = ob_get_clean();
        $this->assertStringContainsString('<label class="screen-reader-text" for="aat-alt-filter">Filtrera på alt-text</label>', $html);
        $this->assertStringContainsString('id="aat-alt-filter"', $html);
        $this->assertStringNotContainsString('selected', $html);

        $_GET['aat_alt'] = 'missing';
        ob_start();
        Auto_Alt_Text_Alt_View::render_filter('attachment');
        $this->assertMatchesRegularExpression('/<option value="missing"\s+selected=\'selected\'>/', ob_get_clean());

        ob_start();
        Auto_Alt_Text_Alt_View::render_filter('post');
        $this->assertSame('', ob_get_clean());
    }

    private function column_html($id) {
        ob_start();
        Auto_Alt_Text_Alt_View::render_column('aat_alt', $id);
        return ob_get_clean();
    }

    public function test_column_shows_escaped_alt_and_provenance_label() {
        $ai = $this->image('<b>x</b>');
        Auto_Alt_Text_Provenance::record($ai, 'batch', null, 'x');
        $html = $this->column_html($ai);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('AI-skriven', $html);

        $human = $this->image('Hund');
        $this->assertStringContainsString('Skriven av en person', $this->column_html($human));

        $missing = $this->column_html($this->image());
        $this->assertStringContainsString('Saknas', $missing);
        $this->assertStringContainsString('aat-alt-col-missing', $missing);
    }

    public function test_column_for_non_images() {
        $pdf = self::factory()->attachment->create(['post_mime_type' => 'application/pdf']);
        $html = $this->column_html($pdf);
        $this->assertStringContainsString('&mdash;', $html);
        $this->assertStringContainsString('<span class="screen-reader-text">Inte en bild</span>', $html);
    }

    public function test_column_is_added_after_title() {
        $cols = Auto_Alt_Text_Alt_View::add_column(['cb' => '', 'title' => 'T', 'author' => 'A']);
        $this->assertSame(['cb', 'title', 'aat_alt', 'author'], array_keys($cols));
    }
}
