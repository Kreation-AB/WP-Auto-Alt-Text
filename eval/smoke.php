<?php
/**
 * Live smoke test (test-infrastructure spec §4.7). Dev tooling, never shipped.
 *
 *   wp eval-file <plugin>/eval/smoke.php [dry-run=1]
 *
 * Opt-in, run by hand on the dev site before a release. Generates alt text for
 * five eval images with the real API (about $0.02), checks the quality gates,
 * prints a table and the cost, and deletes what it imported. It never writes
 * alt text. Exits 0 when every gate passes, 1 otherwise. With dry-run=1 it
 * only lists the items, the model and the estimate: no import, no API call.
 */

if (PHP_SAPI !== 'cli') { exit; }

if (!defined('ABSPATH') || !class_exists('Auto_Alt_Text_OpenAI')) {
    fwrite(STDERR, "Run this with: wp eval-file <plugin>/eval/smoke.php\n");
    return;
}

require_once __DIR__ . '/lib/checks.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$aat_brand = 'Nordisk Hem';
$aat_brand_description = 'En svensk webbutik för hem, kök och friluftsliv.';
$aat_args = isset($args) ? (array) $args : [];
foreach ($aat_args as $aat_arg) {
    if (!in_array($aat_arg, ['dry-run=1', 'dry-run=0'], true)) {
        WP_CLI::error(sprintf('Unknown argument "%s". Use: dry-run=1', $aat_arg));
    }
}
$aat_dry_run = in_array('dry-run=1', $aat_args, true);
$aat_items = aat_eval_select_items(aat_eval_manifest(), 'A01,B03,C04,D05,D06');
$aat_model_id = Auto_Alt_Text_Models::default_id();
$aat_model = Auto_Alt_Text_Models::get($aat_model_id);
if (null === $aat_model || 5 !== count($aat_items)) {
    WP_CLI::error('Smoke test setup is broken: unknown default model or the five items are not all in the manifest.');
}

$aat_estimate = count($aat_items) * aat_eval_estimate($aat_model, 'auto');
WP_CLI::log(sprintf('Model: %s. Items: %s.', $aat_model_id, implode(', ', array_column($aat_items, 'id'))));
WP_CLI::log(sprintf('Estimated cost: $%.3f.', $aat_estimate));

// Readiness, checked before any spend: product items need WooCommerce, every image must be on disk.
if (!class_exists('WC_Product_Simple')) {
    WP_CLI::error('WooCommerce is not active, but the smoke items include products. Activate it first.');
}
$aat_missing = aat_eval_missing_files(aat_eval_with_siblings($aat_items, aat_eval_manifest()));
if ($aat_missing) {
    WP_CLI::error(sprintf('Missing images for %s. Run php eval/fetch.php first.', implode(', ', $aat_missing)));
}
if ($aat_dry_run) {
    WP_CLI::success('Dry run: nothing imported, no request made.');
    return;
}

// The smoke test uses the plugin's own prompt for a fictional shop; its uploads must not queue real generations.
add_filter('pre_option_auto_alt_text_auto_generate', '__return_zero');
add_filter('pre_option_aat_brand_name', static fn() => $aat_brand);
add_filter('pre_option_aat_brand_description', static fn() => $aat_brand_description);
add_filter('pre_option_auto_alt_text_extra_instructions', static fn() => '');

$aat_eval_posts = static fn() => get_posts(['post_type' => 'any', 'post_status' => 'any', 'meta_key' => '_aat_eval_id', 'fields' => 'ids', 'numberposts' => -1]);
$aat_before = $aat_eval_posts();
$aat_rows = [];
$aat_total = 0.0;
$aat_failed = false;

try {
    $aat_prepared = aat_eval_import($aat_items);

    $aat_openai = new Auto_Alt_Text_OpenAI();
    $aat_openai->set_max_wait_seconds(300);

    foreach ($aat_items as $aat_item) {
        $aat_target = $aat_prepared[$aat_item['id']] ?? null;
        if (null === $aat_target) {
            $aat_rows[] = [$aat_item['id'], 'not imported', '-', '-', '-', '-'];
            $aat_failed = true;
            continue;
        }
        $aat_result = $aat_openai->run_generation($aat_target['attachment_id'], 'cli', [
            'naming'   => 'product',
            'language' => 'sv',
            'model'    => $aat_model_id,
        ]);
        $aat_cost = aat_eval_cost($aat_model, $aat_result['input_tokens'], $aat_result['output_tokens']);
        $aat_total += $aat_cost;
        $aat_ok = 'ok' === $aat_result['status'];
        $aat_checks = $aat_ok ? aat_eval_checks($aat_item, $aat_result['text'], $aat_result['first_text'], [
            'naming'       => 'product',
            'language'     => 'sv',
            'product_name' => $aat_target['product_name'],
            'brand_name'   => $aat_brand,
            'max_length'   => Auto_Alt_Text_Prompt::DEFAULT_MAX_LENGTH,
        ]) : [];
        $aat_format = $aat_ok && aat_eval_format_ok($aat_checks);
        // A product item with an empty target name would make the name checks "not applicable"; that must fail, not pass.
        $aat_name_missing = !empty($aat_item['shop_name']) && '' === (string) ($aat_target['product_name'] ?? '');
        $aat_name = $aat_ok && !$aat_name_missing && false !== ($aat_checks['name_first_ok'] ?? null);
        if (!$aat_ok || !$aat_format || !$aat_name) {
            $aat_failed = true;
        }
        $aat_rows[] = [
            $aat_item['id'],
            $aat_result['status'] . (!$aat_ok && !empty($aat_result['error']) ? ': ' . $aat_result['error'] : ''),
            $aat_format ? 'pass' : 'FAIL',
            $aat_name ? 'pass' : 'FAIL',
            $aat_ok ? mb_strlen($aat_result['text']) : '-',
            sprintf('$%.4f', $aat_cost),
        ];
    }
} catch (\Throwable $aat_error) {
    $aat_failed = true;
    WP_CLI::warning('Smoke test aborted: ' . $aat_error->getMessage());
} finally {
    // Delete only what this run created; items imported by earlier eval runs stay.
    $aat_created = array_diff($aat_eval_posts(), $aat_before);
    foreach ($aat_created as $aat_post_id) {
        wp_delete_post((int) $aat_post_id, true);
    }
    WP_CLI::log(sprintf('Removed %d imported posts.', count($aat_created)));
}

if ($aat_rows) {
    $aat_table_rows = array_map(static fn($row) => array_combine(['Item', 'Status', 'Format', 'Name', 'Length', 'Cost'], $row), $aat_rows);
    \WP_CLI\Utils\format_items('table', $aat_table_rows, ['Item', 'Status', 'Format', 'Name', 'Length', 'Cost']);
}
WP_CLI::log(sprintf('Total cost: $%.4f', $aat_total));

if ($aat_failed) {
    WP_CLI::error('Smoke test failed.', false);
    WP_CLI::halt(1);
}
WP_CLI::success('Smoke test passed.');
