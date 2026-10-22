<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class Auto_Alt_Text_Ajax_Handler {
    private const NONCE_ACTION = 'auto_alt_text_nonce';
    private const LOG_TYPE = 'debug';

    private const ERROR_MISSING_ID = 'Missing attachment ID';
    private const ERROR_INVALID_ATTACHMENT = 'Invalid attachment ID';
    private const ERROR_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions';
    /** Exception code of an apply refused because the alt text changed after the editor read it. */
    private const ALT_CHANGED = 409;
    /**
     * Registers AJAX actions for generating alt text for attachments.
     */
    public function __construct() {
        add_action('wp_ajax_generate_alt_text_for_attachment', [$this, 'generate_alt_text_for_attachment']);
        add_action('wp_ajax_aat_get_context', [$this, 'get_context']);
        add_action('wp_ajax_regenerate_alt_text_with_feedback', [$this, 'regenerate_alt_text_with_feedback']);
    }

    /**
     * Generates alt text for an attachment using the OpenAI API.
     *
     * This function is called via an AJAX request to generate alt text for a specific attachment.
     * It checks the user's permissions, retrieves the attachment's image URL, and then uses the
     * Auto_Alt_Text_OpenAI class to generate the alt text. The generated alt text is then
     * returned as a JSON response.
     *
     * @return void
     */
    public function generate_alt_text_for_attachment(): void {
        if (!isset($_POST['attachment_id'])) {
            wp_send_json_error(['message' => self::ERROR_MISSING_ID]);
            return;
        }

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => self::ERROR_INSUFFICIENT_PERMISSIONS]);
            return;
        }

        Auto_Alt_Text_Logger::log("AJAX request received", self::LOG_TYPE, [
            'attachment_id' => $_POST['attachment_id'] ?? null
        ]);

        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $attachment_id = intval($_POST['attachment_id']);
        if (!current_user_can('edit_post', $attachment_id)) {
            wp_send_json_error(['message' => self::ERROR_INSUFFICIENT_PERMISSIONS]);
            return;
        }
        $preview_mode = isset($_POST['preview']) && $_POST['preview'] === 'true';
        $image_url = wp_get_attachment_url($attachment_id);

        if (!$image_url) {
            wp_send_json_error(['message' => self::ERROR_INVALID_ATTACHMENT]);
            return;
        }

        $openai = new Auto_Alt_Text_OpenAI();
        $alt_text = $openai->generate_alt_text($image_url, $attachment_id, 'manual', $preview_mode);

        if ($alt_text) {
            wp_send_json_success([
                'alt_text' => $alt_text,
                'context'  => Auto_Alt_Text_Context::describe(Auto_Alt_Text_Context::explain($attachment_id)),
            ]);
        } else {
            wp_send_json_error(['message' => $openai->get_last_error()]);
        }
    }

    /**
     * Applies the generated alt text to the specified image attachment.
     *
     * This function is called via an AJAX request to update the alt text for a specific image attachment.
     * It first checks the security token to ensure the request is valid. It then retrieves the necessary
     * parameters from the request, including the attachment ID, the generated alt text, and whether the
     * text was manually edited. It updates the alt text for the attachment and also updates the statistics
     * record for the generated text. Finally, it returns a success response with the updated alt text.
     */
    public function apply_alt_text() {
        try {
            if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
                throw new Exception('Invalid security token');
            }

            $attachment_id = intval($_POST['attachment_id']);
            // $_POST is slashed (wp_magic_quotes); work with the real text.
            $alt_text = sanitize_text_field(wp_unslash($_POST['alt_text'] ?? ''));
            $original_text = sanitize_text_field(wp_unslash($_POST['original_text'] ?? ''));
            $is_edited = isset($_POST['is_edited']) && $_POST['is_edited'] === '1';
            $tokens_used = isset($_POST['tokens_used']) ? intval($_POST['tokens_used']) : 0;
            // The alt text the media modal's field held when the editor clicked
            // "Använd"; the block editor sends none, and then nothing is compared.
            $expected_alt = isset($_POST['expected_alt']) ? trim(sanitize_text_field(wp_unslash($_POST['expected_alt']))) : null;

            if (!$attachment_id || !$alt_text) {
                throw new Exception('Missing required parameters');
            }

            if (!current_user_can('edit_post', $attachment_id)) {
                throw new Exception('Permission denied');
            }

            // A person saved another text meanwhile (typically the editor, after
            // closing the preview): text a person wrote is never overwritten.
            // The plugin's own unedited text (the upload queue or a library run
            // filled the alt after the field was rendered) may be replaced.
            if (null !== $expected_alt
                && $expected_alt !== self::stored_alt($attachment_id)
                && Auto_Alt_Text_Provenance::STATE_AI !== Auto_Alt_Text_Provenance::classify($attachment_id)) {
                throw new Exception(__('Alt-texten har ändrats under tiden. Inget sparades. Ladda om sidan för att se den nya texten.', 'WP-Auto-Alt-Text'), self::ALT_CHANGED);
            }

            // Metadata functions unslash their input, so slash it once here.
            $result = update_post_meta($attachment_id, '_wp_attachment_image_alt', wp_slash($alt_text));

            // update_post_meta() also returns false when the value is unchanged.
            if (false === $result && get_post_meta($attachment_id, '_wp_attachment_image_alt', true) !== $alt_text) {
                throw new Exception('Failed to update alt text');
            }

            // Provenance (design D1): applying the generated text unchanged keeps
            // it AI-written; any edit makes it the editor's own text.
            if ($is_edited || $alt_text !== $original_text) {
                Auto_Alt_Text_Provenance::forget($attachment_id);
            } else {
                Auto_Alt_Text_Provenance::record($attachment_id, 'manual', wp_slash($alt_text));
            }

            // Update the existing statistics record
            global $wpdb;
            $table_name = $wpdb->prefix . 'auto_alt_text_stats';

            $wpdb->query($wpdb->prepare(
                "UPDATE {$table_name}
                    SET is_applied = 1,
                        is_edited = %d,
                        edited_text = %s
                    WHERE image_id = %d
                    AND generated_text = %s
                    AND generation_time = (
                        SELECT max_time FROM (
                            SELECT MAX(generation_time) as max_time
                            FROM {$table_name}
                            WHERE image_id = %d
                        ) as sub
                    )",
                $is_edited,
                $is_edited ? $alt_text : null,
                $attachment_id,
                $original_text,
                $attachment_id
            ));

        } catch (Exception $e) {
            error_log('[Auto Alt Text Error] ' . $e->getMessage());
            wp_send_json_error([
                'message' => $e->getMessage(),
                'code' => self::ALT_CHANGED === $e->getCode() ? 'aat_alt_changed' : $e->getCode()
            ]);
            return;
        }

        wp_send_json_success([
            'message' => 'Alt text updated successfully',
            'alt_text' => $alt_text,
            'attachment_id' => $attachment_id
        ]);
    }

    /**
     * The attachment's alt text as stored now, read past the object cache, and
     * normalised like expected_alt: the field holds the stored text exactly,
     * and sanitize_text_field() changes some texts (a lone "<" for one).
     */
    private static function stored_alt(int $attachment_id): string {
        wp_cache_delete($attachment_id, 'post_meta');
        return trim(sanitize_text_field((string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true)));
    }

    /**
     * Regenerates alt text based on user feedback.
     *
     * This method takes the original alt text and user feedback to generate
     * an improved version using more specific instructions to OpenAI.
     */
    public function regenerate_alt_text_with_feedback() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => self::ERROR_INSUFFICIENT_PERMISSIONS]);
            return;
        }

        // No return: wp_send_json_error() ends the request, and a return here would grow the PHPStan "unreachable" baseline.
        if (!get_option('auto_alt_text_enable_feedback', true)) {
            wp_send_json_error(['message' => __('Feedback är avstängt i inställningarna.', 'WP-Auto-Alt-Text')]);
        }

        if (!isset($_POST['attachment_id']) || !isset($_POST['improvement_type'])) {
            wp_send_json_error(['message' => 'Missing required parameters']);
            return;
        }

        $attachment_id = intval($_POST['attachment_id']);
        if (!current_user_can('edit_post', $attachment_id)) {
            wp_send_json_error(['message' => self::ERROR_INSUFFICIENT_PERMISSIONS]);
            return;
        }
        $improvement_type = sanitize_text_field($_POST['improvement_type']);
        $custom_feedback = isset($_POST['custom_feedback']) ? sanitize_textarea_field($_POST['custom_feedback']) : '';
        $original_alt_text = isset($_POST['original_alt_text']) ? sanitize_textarea_field($_POST['original_alt_text']) : '';

        $image_url = wp_get_attachment_url($attachment_id);

        if (!$image_url) {
            wp_send_json_error(['message' => self::ERROR_INVALID_ATTACHMENT]);
            return;
        }

        Auto_Alt_Text_Logger::log("Alt text regeneration requested", "info", [
            'attachment_id' => $attachment_id,
            'improvement_type' => $improvement_type,
            'custom_feedback' => $custom_feedback,
            'original_text' => $original_alt_text
        ]);

        $openai = new Auto_Alt_Text_OpenAI();
        $improved_alt_text = $openai->regenerate_alt_text_with_feedback(
            $image_url,
            $attachment_id,
            $improvement_type,
            $custom_feedback,
            $original_alt_text
        );

        if ($improved_alt_text) {
            wp_send_json_success(['alt_text' => $improved_alt_text]);
        } else {
            wp_send_json_error(['message' => $openai->get_last_error()]);
        }
    }

    /**
     * The "Alt-textkontext" line for one image (generation-quality spec §4a).
     */
    public function get_context(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $attachment_id = isset($_POST['attachment_id']) ? (int) $_POST['attachment_id'] : 0;
        if ($attachment_id <= 0 || !current_user_can('edit_post', $attachment_id)) {
            wp_send_json_error(['message' => self::ERROR_INSUFFICIENT_PERMISSIONS]);
            return;
        }

        $explanation = Auto_Alt_Text_Context::explain($attachment_id);
        wp_send_json_success([
            'text'        => Auto_Alt_Text_Context::describe($explanation),
            'explanation' => $explanation,
        ]);
    }
}
