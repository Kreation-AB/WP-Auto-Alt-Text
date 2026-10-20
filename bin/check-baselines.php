<?php
/**
 * Fails when a baseline total grows compared with a base git ref.
 *
 * Usage: php bin/check-baselines.php <base-ref>
 *
 * A file absent at the base counts as newly created: it is reported as `new`
 * and never fails (baselines only shrink after they are first created).
 *
 * Totals compared:
 * - phpstan-baseline.neon: sum of the `count:` values.
 * - phpcs.baseline.xml: number of <violation elements.
 * - tests/e2e/a11y-allowlist.json: length of the JSON array.
 *
 * Exit 1 and name the file when a total grows; exit 2 when the ref does not
 * resolve; exit 0 otherwise.
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

if ( $argc < 2 || '' === trim( $argv[1] ) ) {
	fwrite( STDERR, "Usage: php bin/check-baselines.php <base-ref>\n" );
	exit( 2 );
}

$root = dirname( __DIR__ );
$base = $argv[1];

exec(
	sprintf(
		'git -C %s rev-parse --verify --quiet %s 2>/dev/null',
		escapeshellarg( $root ),
		escapeshellarg( $base . '^{commit}' )
	),
	$rev_output,
	$rev_status
);
if ( 0 !== $rev_status ) {
	fwrite( STDERR, sprintf( "Base ref '%s' does not resolve to a commit. Fetch it first (e.g. fetch-depth: 0).\n", $base ) );
	exit( 2 );
}

/**
 * Read a file at a git ref, or null when it does not exist there.
 */
function aat_baseline_at_ref( $root, $ref, $path ) {
	$cmd = sprintf(
		'git -C %s show %s 2>/dev/null',
		escapeshellarg( $root ),
		escapeshellarg( $ref . ':' . $path )
	);
	exec( $cmd, $lines, $status );
	return 0 === $status ? implode( "\n", $lines ) : null;
}

/**
 * Read a file at HEAD, or null when it does not exist there.
 */
function aat_baseline_at_head( $root, $path ) {
	return aat_baseline_at_ref( $root, 'HEAD', $path );
}

$counters = array(
	'phpstan-baseline.neon'          => static function ( $text ) {
		return preg_match_all( '/^\s*count:\s*(\d+)\s*$/m', $text, $m ) ? array_sum( array_map( 'intval', $m[1] ) ) : 0;
	},
	'phpcs.baseline.xml'             => static function ( $text ) {
		return preg_match_all( '/<violation\b/', $text ) ?: 0;
	},
	'tests/e2e/a11y-allowlist.json'  => static function ( $text ) {
		$data = json_decode( $text, true );
		return is_array( $data ) ? count( $data ) : 0;
	},
);

$failed = false;

foreach ( $counters as $path => $count ) {
	$before_text = aat_baseline_at_ref( $root, $base, $path );
	$after_text  = aat_baseline_at_head( $root, $path );
	$after       = null === $after_text ? 0 : $count( $after_text );

	if ( null === $before_text ) {
		echo sprintf( "new  %s %d\n", $path, $after );
		continue;
	}

	$before = $count( $before_text );

	if ( $after > $before ) {
		fwrite( STDERR, sprintf( "FAIL %s grew from %d to %d (baselines only shrink).\n", $path, $before, $after ) );
		$failed = true;
	} else {
		echo sprintf( "ok   %s %d -> %d\n", $path, $before, $after );
	}
}

exit( $failed ? 1 : 0 );
