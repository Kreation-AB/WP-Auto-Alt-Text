<?php
/* PHPUnit bootstrap: loads the wp-phpunit test library, WooCommerce (when present) and this plugin. */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$_tests_dir = getenv( 'WP_PHPUNIT__DIR' ) ?: dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', function () {
	// WooCommerce is optional: set WC_PLUGIN_FILE, or install it next to the
	// core in ABSPATH (bin/install-wp-tests.sh does this).
	$_wc_plugin_file = getenv( 'WC_PLUGIN_FILE' ) ?: ABSPATH . 'wp-content/plugins/woocommerce/woocommerce.php';
	if ( file_exists( $_wc_plugin_file ) ) {
		require $_wc_plugin_file;
	}
	require dirname( __DIR__ ) . '/wp-auto-alt-text.php';
} );

tests_add_filter( 'setup_theme', function () {
	// Create the plugin's tables for real, outside any test. Inside a test,
	// start_transaction() turns CREATE TABLE into CREATE TEMPORARY TABLE, and
	// SHOW TABLES never lists those, so activate() would not record the schema.
	Auto_Alt_Text_Activator::activate();

	if ( class_exists( 'WC_Install' ) ) {
		WC_Install::install();
		// Reload roles so the capabilities WooCommerce just added are visible.
		$GLOBALS['wp_roles'] = null;
		wp_roles();
	}
} );

require_once __DIR__ . '/helpers/openai-fixture-redact.php';
require_once __DIR__ . '/helpers/class-aat-openai-fixtures.php';

// Tests never reach the network. Mocks answer through pre_http_request, which
// WordPress applies before the block checks. WP_HTTP_BLOCK_EXTERNAL alone still
// lets localhost and the site's own host through, so block_local_requests
// closes that gap: every unmocked request fails at once.
if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) {
	define( 'WP_HTTP_BLOCK_EXTERNAL', true );
}
tests_add_filter( 'block_local_requests', '__return_true' );

// Core's update checks run on admin_init (the AJAX tests fire it) and would call api.wordpress.org.
tests_add_filter( 'init', function () {
	remove_action( 'admin_init', '_maybe_update_core' );
	remove_action( 'admin_init', '_maybe_update_plugins' );
	remove_action( 'admin_init', '_maybe_update_themes' );
} );

// wp_salt() caches which salts are defined on its first call, so AUTH_SALT has
// to exist before core boots; a define in a test file comes too late and makes
// wp_salt('auth') raise an undefined-array-key notice.
if ( ! defined( 'AUTH_SALT' ) ) {
	define( 'AUTH_SALT', 'test-auth-salt-0123456789abcdef' );
}

require $_tests_dir . '/includes/bootstrap.php';

// Sweep the uploads once per run. About 24 test files upload files and almost
// none delete them, and wp_unique_filename() scans the folder on every upload,
// so leftovers made runs slower and slower. Only the year folders go; the
// basedir root (woocommerce-placeholder.png), wc-logs/ and woocommerce_uploads/
// stay. Only when the basedir is inside ABSPATH, which is the dedicated test
// install that bin/install-wp-tests.sh keeps away from real sites. The test
// library's delete_folders() is an instance method of the test case that only
// removes directories, so this is a small recursive delete instead.
( function () {
	$basedir = wp_upload_dir()['basedir'];
	$abspath = rtrim( wp_normalize_path( ABSPATH ), '/' ) . '/';
	$real    = realpath( $basedir );
	if ( false === $real ) {
		return;
	}
	$real = rtrim( wp_normalize_path( $real ), '/' ) . '/';
	if ( 0 !== strpos( $real, $abspath ) ) {
		return;
	}

	$delete_tree = static function ( $dir ) use ( &$delete_tree ) {
		if ( is_link( $dir ) || ! is_dir( $dir ) ) {
			@unlink( $dir );
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$delete_tree( $dir . '/' . $entry );
			}
		}
		@rmdir( $dir );
	};

	foreach ( glob( $real . '[0-9][0-9][0-9][0-9]', GLOB_ONLYDIR ) ?: array() as $year_dir ) {
		if ( ! is_link( $year_dir ) ) {
			$delete_tree( $year_dir );
		}
	}
} )();

// The e2e mock needs ABSPATH, so it loads after WordPress's bootstrap. Without AAT_E2E_MOCK it registers nothing.
require_once __DIR__ . '/e2e/mu-plugins/aat-mock-openai.php';
