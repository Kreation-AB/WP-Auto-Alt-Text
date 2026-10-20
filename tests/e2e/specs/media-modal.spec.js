const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );
const { respText, wpCli, getOption, restoreOption, provenance } = require( '../utils/wp-cli' );

const RESP_TEXT = respText();
const SAMPLE = path.resolve( __dirname, '../assets/sample.jpg' );

test.describe( 'media modal', () => {
	let requestUtils;
	let ids = [];

	let savedAutoGenerate;

	// Auto-generate off: no upload job can write alt text behind the test's back.
	test.beforeAll( () => {
		savedAutoGenerate = getOption( 'auto_alt_text_auto_generate' );
		wpCli( [ 'option', 'update', 'auto_alt_text_auto_generate', '0' ] );
	} );

	test.afterAll( () => {
		restoreOption( 'auto_alt_text_auto_generate', savedAutoGenerate );
	} );

	test.beforeAll( async ( { baseURL } ) => {
		requestUtils = await RequestUtils.setup( {
			baseURL,
			storageStatePath: 'artifacts/storage-states/admin.json',
		} );
	} );

	test.afterEach( async () => {
		for ( const id of ids ) {
			await requestUtils.rest( { method: 'DELETE', path: `/wp/v2/media/${ id }`, params: { force: true } } );
		}
		ids = [];
	} );

	test.afterAll( async ( { browser } ) => {
		const page = await browser.newPage();
		await page.goto( '/wp-admin/upload.php?mode=grid' );
		await page.close();
	} );

	test( 'generates, previews and applies alt text in the media modal', async ( { page } ) => {
		const media = await requestUtils.uploadMedia( SAMPLE );
		ids.push( media.id );
		expect( ( await requestUtils.rest( { path: `/wp/v2/media/${ media.id }` } ) ).alt_text ).toBe( '' );

		// With mode=grid in the URL core drops the item parameter, so set the mode first.
		await page.goto( '/wp-admin/upload.php?mode=grid' );
		await page.goto( `/wp-admin/upload.php?item=${ media.id }` );
		await expect( page.locator( '.aat-alt-context' ) ).toHaveText( 'Ingen produkt eller sida hittades', { timeout: 5000 } );

		await page.locator( '.generate-alt-text-button' ).click();
		await expect( page.locator( '#preview-title' ) ).toBeVisible();
		await expect( page.locator( '.alt-text-preview-dialog .preview-text' ) ).toHaveValue( RESP_TEXT );
		await expect( page.locator( '#aat-preview-context' ) ).toBeVisible();

		await page.locator( '.apply-alt-text' ).click();

		await expect
			.poll( async () => ( await requestUtils.rest( { path: `/wp/v2/media/${ media.id }` } ) ).alt_text )
			.toBe( RESP_TEXT );
		// Apply records the text as written by the modal ('manual'), not by an upload job.
		expect( provenance( media.id ).source ).toBe( 'manual' );
	} );
} );
