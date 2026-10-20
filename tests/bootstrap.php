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
	if ( class_exists( 'WC_Install' ) ) {
		WC_Install::install();
		// Reload roles so the capabilities WooCommerce just added are visible.
		$GLOBALS['wp_roles'] = null;
		wp_roles();
	}
} );

require_once __DIR__ . '/helpers/openai-fixture-redact.php';
require_once __DIR__ . '/helpers/class-aat-openai-fixtures.php';

require $_tests_dir . '/includes/bootstrap.php';

// The e2e mock needs ABSPATH, so it loads after WordPress's bootstrap. Without AAT_E2E_MOCK it registers nothing.
require_once __DIR__ . '/e2e/mu-plugins/aat-mock-openai.php';
