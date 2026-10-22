<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class Auto_Alt_Text_Image_Process {
  private $openai;
  private $language_manager;

  public function __construct(Auto_Alt_Text_OpenAI $openai, Auto_Alt_Text_Language_Manager $language_manager) {
      $this->openai = $openai;
      $this->language_manager = $language_manager;

      // Priority 20: WPML and Polylang assign the attachment language at 10.
      add_action('add_attachment', array($this, 'handle_new_attachment'), 20);
      add_filter('attachment_fields_to_edit', array($this, 'add_custom_generate_alt_text_button'), 10, 2);
      add_filter('attachment_fields_to_edit', array($this, 'add_context_field'), 11, 2);
  }

  /**
   * Queues a newly uploaded image for background alt text generation.
   *
   * The attachment's language belongs to WPML/Polylang; generation reads it
   * when the job runs. No API call happens in the upload request.
   *
   * @param int $attachment_id The ID of the newly uploaded attachment.
   */
  public function handle_new_attachment($attachment_id) {
    if (!wp_attachment_is_image($attachment_id)) {
        return;
    }

    if (!get_option('auto_alt_text_auto_generate', true)) {
        return;
    }

    $queued = (new Auto_Alt_Text_Queue())->enqueue($attachment_id, 'upload');

    Auto_Alt_Text_Logger::log("Attachment queued for alt text generation", "info", [
        'attachment_id' => $attachment_id,
        'queued' => $queued,
    ]);
  }

  /**
   * The "Alt-text med AI" field in attachment details: the Generate button,
   * which admin/js/media.js drives, or a notice linking to the settings when
   * no API key is configured. The nonce comes from media.js's config.
   *
   * @param array $form_fields The attachment form fields.
   * @param WP_Post $post The attachment post object.
   * @return array The modified attachment form fields.
   */
  public function add_custom_generate_alt_text_button($form_fields, $post) {
    $api_key = get_option('auto_alt_text_api_key');
    // Core prints compat field labels as they are.
    $label = esc_html(Auto_Alt_Text_Coexistence::label(__('Alt-text med AI', 'WP-Auto-Alt-Text')));

    if (empty($api_key) && !defined('AUTO_ALT_TEXT_OPENAI_API_KEY')) {
        $form_fields['generate_alt_text'] = array(
            'label' => $label,
            'input' => 'html',
            'html' => '<div class="notice notice-warning inline"><p>' .
                    esc_html__('Ange en API-nyckel i inställningarna för att generera alt-text.', 'WP-Auto-Alt-Text') .
                    ' <a href="' . esc_url(admin_url('admin.php?page=auto-alt-text')) . '">' .
                    esc_html__('Öppna inställningarna', 'WP-Auto-Alt-Text') . '</a></p></div>'
        );
        return $form_fields;
    }

    $form_fields['generate_alt_text'] = array(
        'label' => $label,
        'input' => 'html',
        'html' => sprintf(
            '<button type="button" class="button aat-generate" data-attachment-id="%s">%s</button><span class="spinner aat-generate-spinner"></span>',
            esc_attr((string) (int) $post->ID),
            esc_html(Auto_Alt_Text_Coexistence::label(__('Generera alt-text', 'WP-Auto-Alt-Text')))
        ),
    );

    return $form_fields;
  }

  /**
   * "Alt-textkontext" (generation-quality spec §4a): which product, page or
   * post the alt text is tied to. Resolved here only on the attachment edit
   * screen. Media modals receive these fields in AJAX grid responses for
   * every attachment, so there a placeholder is rendered and media.js fills it.
   */
  public function add_context_field($form_fields, $post) {
    if (!$post instanceof WP_Post || !wp_attachment_is_image($post->ID)) {
        return $form_fields;
    }

    if (self::is_attachment_edit_screen()) {
        $text = Auto_Alt_Text_Context::describe(Auto_Alt_Text_Context::explain($post->ID));
        $html = '<span class="aat-alt-context" data-loaded="1">' . esc_html($text) . '</span>';
    } else {
        // Filled later by media.js: a status region named after the field's label.
        $html = '<span class="aat-alt-context" role="status" aria-live="polite" aria-label="' . esc_attr__('Alt-textkontext', 'WP-Auto-Alt-Text')
            . '" data-attachment-id="' . esc_attr($post->ID)
            . '" data-nonce="' . esc_attr(wp_create_nonce('auto_alt_text_nonce')) . '"></span>';
    }

    $form_fields['aat_alt_context'] = array(
        'label' => __('Alt-textkontext', 'WP-Auto-Alt-Text'),
        'input' => 'html',
        'html'  => $html,
    );
    return $form_fields;
  }

  private static function is_attachment_edit_screen() {
    if (wp_doing_ajax() || !function_exists('get_current_screen')) {
        return false;
    }
    $screen = get_current_screen();
    return $screen && 'post' === $screen->base && 'attachment' === $screen->post_type;
  }
}
?>
