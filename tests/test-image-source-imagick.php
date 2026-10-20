<?php
require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php';

/** Imagick-only behaviour of Auto_Alt_Text_Image_Source; skipped where the extension or editor is missing. */
class Auto_Alt_Text_Image_Source_Imagick_Test extends WP_UnitTestCase {
    private function requireImagick() {
        if (!extension_loaded('imagick') || 'WP_Image_Editor_Imagick' !== _wp_image_editor_choose()) {
            $this->markTestSkipped('Imagick is not the active image editor');
        }
    }

    private function output_blob($file, $detail) {
        $id = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/' . $file);
        $source = Auto_Alt_Text_Image_Source::for_attachment($id, $detail);
        $this->assertNotWPError($source);
        $url = $source->input_image()['image_url'];
        $source->cleanup();
        $this->assertSame(1, preg_match('#^data:image/[a-z]+;base64,(.+)$#', $url, $m));
        return base64_decode($m[1]);
    }

    /** Minimum alpha over a grid of pixels (the fixtures are opaque at (0,0)). */
    private function min_alpha($blob) {
        $image = new Imagick();
        $image->readImageBlob($blob);
        $w = $image->getImageWidth();
        $h = $image->getImageHeight();
        $min = 1.0;
        for ($y = 0; $y < $h; $y += max(1, intdiv($h, 16))) {
            for ($x = 0; $x < $w; $x += max(1, intdiv($w, 16))) {
                $min = min($min, $image->getImagePixelColor($x, $y)->getColorValue(Imagick::COLOR_ALPHA));
            }
        }
        // Include the corners (the AVIF fixture is transparent at the right-hand corners).
        foreach ([[$w - 1, 0], [$w - 1, $h - 1], [0, $h - 1]] as [$x, $y]) {
            $min = min($min, $image->getImagePixelColor($x, $y)->getColorValue(Imagick::COLOR_ALPHA));
        }
        return $min;
    }

    public function test_transparent_png_keeps_alpha_through_imagick() {
        $this->requireImagick();
        $this->assertLessThan(1.0, $this->min_alpha($this->output_blob('transparent.png', 'auto')));
    }

    public function test_transparent_webp_keeps_alpha_through_imagick() {
        $this->requireImagick();
        $this->assertLessThan(1.0, $this->min_alpha($this->output_blob('webp-transparent.webp', 'auto')));
    }

    public function test_transparent_avif_keeps_alpha_through_imagick() {
        $this->requireImagick();
        if (!wp_image_editor_supports(['mime_type' => 'image/avif'])) {
            $this->markTestSkipped('AVIF needs WordPress 6.5+ with editor support');
        }
        $this->assertLessThan(1.0, $this->min_alpha($this->output_blob('avif-transparent.avif', 'auto')));
    }

    private function assert_no_exif($blob) {
        $image = new Imagick();
        $image->readImageBlob($blob);
        $this->assertSame([], $image->getImageProperties('exif:*'));
    }

    public function test_resized_copy_carries_no_exif() {
        $this->requireImagick();
        $file = '2004-07-22-DSC_0007.jpg';
        $this->assertNotEmpty(@exif_read_data(DIR_TESTDATA . '/images/' . $file)['Make'] ?? null, 'The fixture must carry EXIF');
        $this->assert_no_exif($this->output_blob($file, 'low'));
    }

    public function test_unresized_copy_carries_no_exif() {
        $this->requireImagick();
        $file = '2004-07-22-DSC_0007.jpg';
        $this->assertNotEmpty(@exif_read_data(DIR_TESTDATA . '/images/' . $file)['Make'] ?? null, 'The fixture must carry EXIF');
        $blob = $this->output_blob($file, 'auto');
        $info = getimagesizefromstring($blob);
        $this->assertSame([426, 640], [$info[0], $info[1]], 'auto leaves this image unresized');
        $this->assert_no_exif($blob);
    }
}
