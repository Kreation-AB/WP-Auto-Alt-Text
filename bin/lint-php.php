<?php
/**
 * Lints every .php file under the given paths with `php -l`.
 *
 * Usage: php bin/lint-php.php <path>...
 * Exits 0 when all files pass, 1 otherwise (failing files are listed).
 * Plain PHP, no WordPress. Skips vendor/ and node_modules/.
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$paths  = array_slice( $argv, 1 );
$root   = dirname( __DIR__ );
$skip   = array( 'vendor', 'node_modules' );
$failed = array();
$count  = 0;

if ( ! $paths ) {
	fwrite( STDERR, "Usage: php bin/lint-php.php <path>...\n" );
	exit( 1 );
}

$lint = static function ( string $file ) use ( &$failed, &$count ): void {
	++$count;
	$output = array();
	$status = 0;
	exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $file ) . ' 2>&1', $output, $status );
	if ( 0 !== $status ) {
		$failed[ $file ] = implode( "\n", $output );
	}
};

foreach ( $paths as $path ) {
	if ( ! file_exists( $path ) && file_exists( $root . '/' . $path ) ) {
		$path = $root . '/' . $path;
	}
	if ( is_file( $path ) ) {
		if ( 'php' === pathinfo( $path, PATHINFO_EXTENSION ) ) {
			$lint( $path );
		}
		continue;
	}
	if ( ! is_dir( $path ) ) {
		$failed[ $path ] = 'Path does not exist.';
		continue;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
			static function ( SplFileInfo $item ) use ( $skip ): bool {
				return ! ( $item->isDir() && in_array( $item->getFilename(), $skip, true ) );
			}
		)
	);
	foreach ( $iterator as $item ) {
		if ( $item->isFile() && 'php' === $item->getExtension() ) {
			$lint( $item->getPathname() );
		}
	}
}

if ( $failed ) {
	fwrite( STDERR, 'PHP lint failed in ' . count( $failed ) . " file(s):\n" );
	foreach ( $failed as $file => $message ) {
		fwrite( STDERR, "  {$file}\n    " . str_replace( "\n", "\n    ", $message ) . "\n" );
	}
	exit( 1 );
}

echo "PHP lint OK ({$count} files).\n";
exit( 0 );
