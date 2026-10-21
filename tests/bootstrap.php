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
// WordPress applies before this block, so an unmocked request fails at once.
if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) {
	define( 'WP_HTTP_BLOCK_EXTERNAL', true );
}

// Core's update checks run on admin_init (the AJAX tests fire it) and would call api.wordpress.org.
tests_add_filter( 'init', function () {
	remove_action( 'admin_init', '_maybe_update_core' );
	remove_action( 'admin_init', '_maybe_update_plugins' );
	remove_action( 'admin_init', '_maybe_update_themes' );
} );

require $_tests_dir . '/includes/bootstrap.php';

// The e2e mock needs ABSPATH, so it loads after WordPress's bootstrap. Without AAT_E2E_MOCK it registers nothing.
require_once __DIR__ . '/e2e/mu-plugins/aat-mock-openai.php';
