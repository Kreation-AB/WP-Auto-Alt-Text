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
        // The format must really go through Imagick (a CI image can lack a WebP or AVIF delegate, which
        // would silently fall back to GD).
        if (!(wp_get_image_editor(DIR_TESTDATA . '/images/' . $file) instanceof WP_Image_Editor_Imagick)) {
            $this->markTestSkipped('Imagick cannot handle ' . $file);
        }
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

    /** The profile names a file carries, e.g. ['icc']. */
    private function profile_names($blob) {
        $image = new Imagick();
        $image->readImageBlob($blob);
        return array_keys($image->getImageProfiles('*', false) ?: []) ?: [];
    }

    private function assert_stripped($file, $blob) {
        $source = new Imagick(DIR_TESTDATA . '/images/' . $file);
        $this->assertNotEmpty($source->getImageProperties('exif:*'), 'The fixture must carry EXIF');
        $expected = array_values(array_intersect(array_keys($source->getImageProfiles('*', false)), ['icc', 'icm']));
        sort($expected);

        $image = new Imagick();
        $image->readImageBlob($blob);
        $this->assertSame([], $image->getImageProperties('exif:*'));
        $names = $this->profile_names($blob);
        sort($names);
        $this->assertSame($expected, $names, 'Only the colour profile may remain');
    }

    public function test_resized_copy_carries_no_exif() {
        $this->requireImagick();
        $file = '2004-07-22-DSC_0007.jpg';
        $blob = $this->output_blob($file, 'low');
        $info = getimagesizefromstring($blob);
        $this->assertSame([340, 512], [$info[0], $info[1]], 'low resizes this image');
        $this->assert_stripped($file, $blob);
    }

    public function test_unresized_copy_carries_no_exif() {
        $this->requireImagick();
        $file = '2004-07-22-DSC_0007.jpg';
        $blob = $this->output_blob($file, 'auto');
        $info = getimagesizefromstring($blob);
        $this->assertSame([426, 640], [$info[0], $info[1]], 'auto leaves this image unresized');
        $this->assert_stripped($file, $blob);
    }
}
