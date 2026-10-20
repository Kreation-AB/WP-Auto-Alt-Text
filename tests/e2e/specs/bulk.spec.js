const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );
const { respText, wpCli, getOption, restoreOption, provenance } = require( '../utils/wp-cli' );

const RESP_TEXT = respText();
const SAMPLE = path.resolve( __dirname, '../assets/sample.jpg' );
const EXISTING = 'Befintlig text';

test.describe( 'bulk generation', () => {
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

	const altOf = async ( id ) => ( await requestUtils.rest( { path: `/wp/v2/media/${ id }` } ) ).alt_text;

	test( 'bulk generation skips images that already have alt text', async ( { page } ) => {
		for ( let i = 0; i < 3; i++ ) {
			ids.push( ( await requestUtils.uploadMedia( SAMPLE ) ).id );
		}
		await requestUtils.rest( { method: 'POST', path: `/wp/v2/media/${ ids[ 0 ] }`, data: { alt_text: EXISTING } } );

		expect( await altOf( ids[ 1 ] ) ).toBe( '' );
		expect( await altOf( ids[ 2 ] ) ).toBe( '' );

		await page.goto( '/wp-admin/upload.php?mode=list' );
		for ( const id of ids ) {
			await page.locator( `#cb-select-${ id }` ).check();
		}
		await page.locator( '#bulk-action-selector-top' ).selectOption( 'generate_alt_text' );
		await page.locator( '#doaction' ).click();

		await expect( page.locator( '.aat-batch-complete' ) ).toBeVisible( { timeout: 30000 } );
		await expect( page.locator( '.aat-batch-skipped' ) ).toContainText( '1 image(s) skipped' );

		expect( await altOf( ids[ 0 ] ) ).toBe( EXISTING );
		expect( await altOf( ids[ 1 ] ) ).toBe( RESP_TEXT );
		expect( await altOf( ids[ 2 ] ) ).toBe( RESP_TEXT );
		// The bulk path records 'batch' (class-ajax-handler.php:140), not 'upload'.
		expect( provenance( ids[ 1 ] ).source ).toBe( 'batch' );
		expect( provenance( ids[ 2 ] ).source ).toBe( 'batch' );
	} );
} );
