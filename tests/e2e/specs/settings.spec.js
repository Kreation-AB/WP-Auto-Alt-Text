const { test, expect } = require( '@playwright/test' );
const { getOption, restoreOption } = require( '../utils/wp-cli' );

const SETTINGS = '/wp-admin/admin.php?page=auto-alt-text';
const OPTIONS = [ 'auto_alt_text_model', 'auto_alt_text_naming', 'auto_alt_text_auto_generate' ];

// Submits the form and waits for options.php's redirect (settings-updated=true).
async function save( page ) {
	// The redirected GET is a fresh response, so a stale settings-updated URL cannot satisfy this.
	const redirected = page.waitForResponse( ( r ) => r.url().includes( 'settings-updated=true' ) );
	await page.locator( '#submit' ).click();
	await redirected;
}

test.describe( 'settings page', () => {
	const saved = {};

	test.beforeAll( () => {
		for ( const name of OPTIONS ) {
			saved[ name ] = getOption( name );
		}
	} );

	test.afterAll( () => {
		for ( const name of OPTIONS ) {
			restoreOption( name, saved[ name ] );
		}
	} );

	test( 'saving settings keeps the default model unpinned and persists the naming level', async ( { page } ) => {
		restoreOption( 'auto_alt_text_model', null );

		await page.goto( SETTINGS );
		const initiallyOn = await page.locator( '#auto_generate' ).isChecked();
		// The checkbox sits inside the switch, so click the switch's label.
		await page.locator( 'label.switch:has(#auto_generate)' ).click();
		await save( page );
		await page.goto( SETTINGS );
		await expect( page.locator( '#auto_generate' ) ).toBeChecked( { checked: ! initiallyOn } );
		await expect( page.locator( '#model' ) ).toHaveValue( '' );
		expect( [ null, '' ] ).toContain( getOption( 'auto_alt_text_model' ) );

		await page.locator( '#naming' ).selectOption( 'product' );
		await save( page );
		await page.goto( SETTINGS );
		await expect( page.locator( '#naming' ) ).toHaveValue( 'product' );

		// Put the toggle back.
		await page.locator( 'label.switch:has(#auto_generate)' ).click();
		await save( page );
		await page.goto( SETTINGS );
		await expect( page.locator( '#auto_generate' ) ).toBeChecked( { checked: initiallyOn } );
	} );
} );
