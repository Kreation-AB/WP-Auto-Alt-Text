const { test, expect } = require( '@playwright/test' );
const { execFileSync } = require( 'node:child_process' );

test( 'test_plugin_settings_page_loads', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=auto-alt-text' );
	await expect( page.getByRole( 'heading', { level: 1 } ) ).toBeVisible();

	// The plugin is active, whatever the checkout's folder is called.
	const out = execFileSync(
		'npm',
		[ 'run', '--silent', 'wp', '--', 'eval', "echo (int) class_exists('Auto_Alt_Text_OpenAI');" ],
		{ encoding: 'utf8' }
	);
	expect( out.trim() ).toBe( '1' );
} );
