<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class Auto_Alt_Text_REST_API {
    private $openai = null;
    private $namespace = 'wp-auto-alt-text/v1';

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /** Built on first use so ordinary requests never construct an API client. */
    private function openai() {
        if (null === $this->openai) {
            $this->openai = new Auto_Alt_Text_OpenAI();
        }
        return $this->openai;
    }

    /**
     * Registers the REST API routes for the Auto Alt Text plugin.
     *
     * Includes two routes:
     * - '/generate': Generates alt text for a single image attachment.
     * - '/batch': Generates alt text for a batch of image attachments.
     *
     * Both routes require the 'upload_files' capability to access, and the
     * user must be able to edit each attachment they generate for.
     */
    public function register_routes() {
        register_rest_route($this->namespace, '/generate', [
            'methods' => 'POST',
            'callback' => [$this, 'generate_alt_text'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'attachment_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => function($param) {
                        return is_numeric($param) && wp_attachment_is_image($param);
                    }
                ]
            ]
        ]);

        register_rest_route($this->namespace, '/batch', [
            'methods' => 'POST',
            'callback' => [$this, 'process_batch'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'attachment_ids' => [
                    'required' => true,
                    'type' => 'array'
                ]
            ]
        ]);

        $this->register_library_routes();
    }

    private function register_library_routes() {
        $permission = static fn() => current_user_can('manage_options');
        register_rest_route($this->namespace, '/library', [
            'methods' => 'GET',
            'callback' => [$this, 'library_get'],
            'permission_callback' => $permission,
            'args' => ['failures_offset' => ['type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0]],
        ]);
        register_rest_route($this->namespace, '/library/start', [
            'methods' => 'POST',
            'callback' => [$this, 'library_start'],
            'permission_callback' => $permission,
        ]);
        foreach (['pause', 'resume', 'cancel', 'retry-failed', 'recount'] as $action) {
            register_rest_route($this->namespace, '/library/' . $action, [
                'methods' => 'POST',
                'callback' => function ($request) use ($action) {
                    return $this->library_action($action, $request);
                },
                'permission_callback' => $permission,
            ]);
        }
    }

    public function library_get($request) {
        return new WP_REST_Response($this->library_payload(absint($request->get_param('failures_offset'))), 200);
    }

    public function library_start($request) {
        $coverage = $request->get_param('coverage');
        if (!in_array($coverage, [Auto_Alt_Text_Library_Scanner::COVERAGE_MISSING, Auto_Alt_Text_Library_Scanner::COVERAGE_MISSING_AI], true)) {
            return new WP_Error('aat_library_bad_coverage', __('Ogiltig omfattning.', 'WP-Auto-Alt-Text'), ['status' => 400]);
        }
        $run = Auto_Alt_Text_Library_Run::start($coverage, get_current_user_id());
        if (is_wp_error($run)) {
            $statuses = ['aat_library_run_active' => 409, 'aat_library_run_failed' => 500];
            return new WP_Error($run->get_error_code(), $run->get_error_message(), ['status' => $statuses[$run->get_error_code()] ?? 400]);
        }
        return new WP_REST_Response($this->library_payload(0), 200);
    }

    public function library_action($action, $request) {
        if ('recount' === $action) {
            Auto_Alt_Text_Library_Scanner::request_recount();
            return new WP_REST_Response($this->library_payload(0), 200);
        }
        $run = 'retry-failed' === $action ? Auto_Alt_Text_Library_Run::latest() : (Auto_Alt_Text_Library_Run::active() ?? Auto_Alt_Text_Library_Run::latest());
        if (null === $run) {
            return new WP_Error('aat_library_no_run', __('Det finns ingen körning.', 'WP-Auto-Alt-Text'), ['status' => 404]);
        }
        $id = (int) $run['id'];
        switch ($action) {
            case 'pause':
                Auto_Alt_Text_Library_Run::pause($id);
                break;
            case 'resume':
                Auto_Alt_Text_Library_Run::resume($id);
                break;
            case 'cancel':
                Auto_Alt_Text_Library_Run::cancel($id);
                break;
            default:
                Auto_Alt_Text_Library_Run::retry_failed($id);
        }
        return new WP_REST_Response($this->library_payload(0), 200);
    }

    private function library_payload(int $failures_offset): array {
        $counts = Auto_Alt_Text_Library_Scanner::cached_counts();
        if (!$counts['complete'] || (time() - $counts['updated_at']) > HOUR_IN_SECONDS) {
            Auto_Alt_Text_Library_Scanner::request_recount();
        }
        $run = Auto_Alt_Text_Library_Run::active() ?? Auto_Alt_Text_Library_Run::latest();
        $per_1000 = Auto_Alt_Text_Models::cost_per_1000(Auto_Alt_Text_Models::current_id());
        return [
            'counts' => $counts,
            'estimate_per_image_usd' => $per_1000 / 1000,
            'run' => null === $run ? null : $this->library_run_payload($run, $failures_offset),
            'health' => null === $run ? null : Auto_Alt_Text_Library_Runner::health($run),
        ];
    }

    private function library_run_payload(array $run, int $failures_offset): array {
        $id = (int) $run['id'];
        $state = Auto_Alt_Text_Library_Run::display_state($run);
        $final = in_array($run['status'], [Auto_Alt_Text_Library_Run::STATUS_CANCELLED, Auto_Alt_Text_Library_Run::STATUS_FINISHED], true);
        $progress = Auto_Alt_Text_Library_Run::progress($run);
        [$failures, $failures_total] = $this->library_failures($run, $final, (int) $progress['failed'], $failures_offset);
        return [
            'id' => $id,
            'status' => $run['status'],
            'display_state' => $state['state'],
            'reason' => $state['reason'],
            'coverage' => $run['coverage'],
            'started_at' => $run['started_at'],
            'ended_at' => $run['ended_at'] ?? null,
            'total' => $run['total'],
            'estimate_usd' => $run['estimate_usd'],
            'progress' => $progress,
            'cost_so_far' => $final ? (float) $run['summary']['cost_usd'] : Auto_Alt_Text_Library_Run::cost_so_far($id),
            'waiting_until' => Auto_Alt_Text_Library_Runner::waiting_until($run),
            'recent' => $this->library_recent($id),
            'failures_page' => $failures,
            'failures_offset' => $failures_offset,
            'failures_total' => $failures_total,
            // wp_nonce_url() HTML-escapes the ampersands; JSON consumers need the plain URL.
            'report_url' => wp_specialchars_decode(wp_nonce_url(admin_url('admin-post.php?action=aat_library_report&run=' . $id), 'aat_library_report')),
        ];
    }

    /** @return array{0: array<int,array{attachment_id:int,file:string,reason:string}>, 1:int} */
    private function library_failures(array $run, bool $final, int $failed_total, int $offset): array {
        $page = 50;
        $listed = $final && is_array($run['summary']['failures'] ?? null) ? $run['summary']['failures'] : [];
        $items = [];
        if ($listed) {
            foreach (array_slice($listed, $offset, $page) as $f) {
                $items[] = [
                    'attachment_id' => (int) ($f['attachment_id'] ?? 0),
                    'file' => (string) ($f['file'] ?? ''),
                    'reason' => Auto_Alt_Text_Library_Report::scrub((string) ($f['reason'] ?? '')),
                ];
            }
            // A capped summary list stands for fewer failures than were counted.
            return [$items, max(count($listed), $failed_total)];
        }
        foreach (Auto_Alt_Text_Library_Report::queue_failure_rows((int) $run['id'], $page, $offset) as $row) {
            $items[] = [
                'attachment_id' => (int) $row->attachment_id,
                'file' => basename((string) get_post_meta((int) $row->attachment_id, '_wp_attached_file', true)),
                'reason' => Auto_Alt_Text_Library_Report::scrub((string) $row->last_error),
            ];
        }
        return [$items, $failed_total];
    }

    /** The last 8 processed rows, newest first. Excerpts are for the page only, never the report. */
    private function library_recent(int $run_id): array {
        global $wpdb;
        $table = Auto_Alt_Text_Queue::get_table_name();
        $outcomes = [
            Auto_Alt_Text_Queue::STATUS_DONE => 'filled',
            Auto_Alt_Text_Queue::STATUS_SKIPPED => 'skipped',
            Auto_Alt_Text_Queue::STATUS_FAILED => 'failed',
        ];
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT attachment_id, status, last_error FROM {$table} WHERE run_id = %d AND status IN (%s, %s, %s) ORDER BY updated_at DESC, id DESC LIMIT 8",
            $run_id, Auto_Alt_Text_Queue::STATUS_DONE, Auto_Alt_Text_Queue::STATUS_SKIPPED, Auto_Alt_Text_Queue::STATUS_FAILED
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $recent = [];
        foreach ((array) $rows as $row) {
            $id = (int) $row->attachment_id;
            $item = ['file' => basename((string) get_attached_file($id)), 'outcome' => $outcomes[$row->status]];
            if ('filled' === $item['outcome']) {
                $item['excerpt'] = mb_substr((string) get_post_meta($id, '_wp_attachment_image_alt', true), 0, 80);
            } elseif (!empty($row->last_error)) {
                $item['reason'] = Auto_Alt_Text_Library_Report::scrub((string) $row->last_error);
            }
            $recent[] = $item;
        }
        return $recent;
    }

    /**
     * Checks if the current user has the 'upload_files' capability, which is required to access the REST API routes.
     *
     * @return bool True if the current user has the 'upload_files' capability, false otherwise.
     */
    public function check_permission() {
        return current_user_can('upload_files');
    }

    /**
     * Generates alt text for a single image attachment using the OpenAI API.
     *
     * @param WP_REST_Request $request The REST API request object, containing the 'attachment_id' parameter.
     * @return WP_REST_Response|WP_Error A response object with the generated alt text or an error message;
     *                                   a 403 error when the user cannot edit the attachment.
     */
    public function generate_alt_text($request) {
        $attachment_id = $request->get_param('attachment_id');
        if (!current_user_can('edit_post', $attachment_id)) {
            return new WP_Error(
                'rest_forbidden',
                __('Sorry, you are not allowed to edit this attachment.', 'WP-Auto-Alt-Text'),
                ['status' => 403]
            );
        }
        $image_url = wp_get_attachment_url($attachment_id);

        $alt_text = $this->openai()->generate_alt_text($image_url, $attachment_id, 'api');

        if ($alt_text) {
            return new WP_REST_Response([
                'success' => true,
                'alt_text' => $alt_text,
                'attachment_id' => $attachment_id
            ], 200);
        }

        return new WP_REST_Response([
            'success' => false,
            'message' => $this->openai()->get_last_error()
        ], 400);
    }

    /**
     * Processes a batch of image attachment IDs, generates alt text for each image using the OpenAI API, and returns the results.
     *
     * @param WP_REST_Request $request The REST API request object, containing the 'attachment_ids' parameter.
     * @return WP_REST_Response A response object with the generated alt text for each image or an error message.
     */
    public function process_batch($request) {
        $attachment_ids = $request->get_param('attachment_ids');
        $results = [];
        $rate_limit_message = null;

        foreach ($attachment_ids as $id) {
            // After a rate limit the rest of the list would fail the same way:
            // report it as not processed and write nothing for it.
            if (null !== $rate_limit_message) {
                $results[$id] = [
                    'success'       => false,
                    'not_processed' => true,
                    'message'       => $rate_limit_message,
                ];
                continue;
            }

            if (!wp_attachment_is_image($id)) {
                $results[$id] = [
                    'success' => false,
                    'message' => __('Invalid attachment ID.', 'WP-Auto-Alt-Text')
                ];
                continue;
            }

            if (!current_user_can('edit_post', $id)) {
                $results[$id] = [
                    'success' => false,
                    'message' => __('Permission denied', 'WP-Auto-Alt-Text'),
                ];
                continue;
            }

            if (!Auto_Alt_Text_Provenance::allows_overwrite($id, Auto_Alt_Text_Provenance::default_policy())) {
                $results[$id] = [
                    'success' => false,
                    'skipped' => true,
                    'message' => __('Skipped: the existing alt text is kept by the overwrite setting.', 'WP-Auto-Alt-Text'),
                ];
                continue;
            }

            $image_url = wp_get_attachment_url($id);
            $alt_text = $this->openai()->generate_alt_text($image_url, $id, 'api_batch');

            if ($alt_text) {
                $results[$id] = [
                    'success' => true,
                    'alt_text' => $alt_text,
                ];
                continue;
            }

            $results[$id] = [
                'success' => false,
                'message' => $this->openai()->get_last_error(),
            ];
            if ('rate_limit' === $this->openai()->get_last_error_class()) {
                $results[$id]['rate_limited'] = true;
                $rate_limit_message = $results[$id]['message'];
            }
        }

        $response = [
            'success' => true,
            'results' => $results
        ];
        if (null !== $rate_limit_message) {
            $response['rate_limited'] = true;
            $response['message']      = $rate_limit_message;
        }
        return new WP_REST_Response($response, 200);
    }
}
