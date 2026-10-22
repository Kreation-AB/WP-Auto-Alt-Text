<?php
/**
 * Dev tool (spec 4.5): measures bulk throughput against the mocked API with a 5 s latency.
 *
 * Run in the wp-env tests site:
 *   npx wp-env run tests-cli wp eval-file wp-content/aat-e2e-tests/e2e/tools/measure-bulk-throughput.php
 *
 * Cleans up after itself, also on failure.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

$aat_total        = 30;
$aat_latency      = 5000;
$aat_timeout      = 300.0;
$aat_tick_budget  = 25.0;
$aat_attachments  = array();
$aat_missing      = new stdClass();
$aat_old_latency  = get_option( 'aat_e2e_latency_ms', $aat_missing );
$aat_old_limits   = get_option( 'aat_e2e_queue_limits', $aat_missing );
$aat_queue        = new Auto_Alt_Text_Queue();
$aat_processed    = 0;
$aat_seconds      = 0.0;

try {
	$aat_sample = dirname( __DIR__ ) . '/assets/sample.jpg';
	for ( $i = 0; $i < $aat_total; $i++ ) {
		$aat_tmp = wp_tempnam( 'aat-throughput.jpg' );
		copy( $aat_sample, $aat_tmp );
		$aat_id = media_handle_sideload(
			array(
				'name'     => 'aat-throughput-' . $i . '.jpg',
				'tmp_name' => $aat_tmp,
			)
		);
		if ( is_wp_error( $aat_id ) ) {
			throw new RuntimeException( $aat_id->get_error_message() );
		}
		$aat_attachments[] = (int) $aat_id;
		$aat_queue->enqueue_bulk( (int) $aat_id, 'batch' );
	}

	update_option( 'aat_e2e_latency_ms', $aat_latency, false );
	delete_option( 'aat_e2e_queue_limits' );

	$aat_pending = static function () use ( $aat_attachments ): int {
		global $wpdb;
		$ids = implode( ',', array_map( 'intval', $aat_attachments ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Auto_Alt_Text_Queue::get_table_name() . " WHERE generation_type = 'batch' AND status = %s AND attachment_id IN ({$ids})", Auto_Alt_Text_Queue::STATUS_PENDING ) );
	};

	$aat_start = microtime( true );
	while ( $aat_pending() > 0 && ( microtime( true ) - $aat_start ) < $aat_timeout ) {
		$aat_done = Auto_Alt_Text_Library_Runner::tick_for_request( $aat_tick_budget );
		if ( null === $aat_done ) {
			usleep( 500000 ); // Another runner holds the lock.
			continue;
		}
		$aat_processed += $aat_done;
	}
	$aat_seconds = microtime( true ) - $aat_start;

	WP_CLI::log( 'processed=' . $aat_processed );
	WP_CLI::log( 'seconds=' . round( $aat_seconds, 1 ) );
	WP_CLI::log( 'per_minute=' . ( $aat_seconds > 0 ? round( $aat_processed / $aat_seconds * 60, 1 ) : 0 ) );
} finally {
	foreach ( $aat_attachments as $aat_id ) {
		wp_delete_attachment( $aat_id, true );
	}
	if ( $aat_attachments ) {
		global $wpdb;
		$aat_ids = implode( ',', array_map( 'intval', $aat_attachments ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DELETE FROM ' . Auto_Alt_Text_Queue::get_table_name() . " WHERE attachment_id IN ({$aat_ids})" );
	}
	foreach ( array(
		'aat_e2e_latency_ms'   => $aat_old_latency,
		'aat_e2e_queue_limits' => $aat_old_limits,
	) as $aat_name => $aat_old ) {
		if ( $aat_old === $aat_missing ) {
			delete_option( $aat_name );
		} else {
			update_option( $aat_name, $aat_old, false );
		}
	}
}
