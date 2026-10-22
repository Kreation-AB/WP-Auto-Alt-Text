const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );
const { respText, wpCli, getOption, restoreOption, provenance } = require( '../utils/wp-cli' );

const RESP_TEXT = respText();
const SAMPLE = path.resolve( __dirname, '../assets/sample.jpg' );
const EXISTING = 'Befintlig text';

// Drops the admin's tracked bulk; absent meta makes wp-cli fail, which is fine here.
function clearBulk() {
	try {
		wpCli( [ 'user', 'meta', 'delete', '1', 'aat_bulk' ] );
	} catch ( e ) {
		// Nothing tracked.
	}
}

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
		// No tracked bulk may leak into the next test.
		clearBulk();
	} );

	test.afterAll( async ( { browser } ) => {
		const page = await browser.newPage();
		await page.goto( '/wp-admin/upload.php?mode=grid' );
		await page.close();
	} );

	const altOf = async ( id ) => ( await requestUtils.rest( { path: `/wp/v2/media/${ id }` } ) ).alt_text;

	async function queueThree( page ) {
		for ( let i = 0; i < 3; i++ ) {
			ids.push( ( await requestUtils.uploadMedia( SAMPLE ) ).id );
		}
		await requestUtils.rest( { method: 'POST', path: `/wp/v2/media/${ ids[ 0 ] }`, data: { alt_text: EXISTING } } );
		await page.goto( '/wp-admin/upload.php?mode=list' );
		for ( const id of ids ) {
			await page.locator( `#cb-select-${ id }` ).check();
		}
		await page.locator( '#bulk-action-selector-top' ).selectOption( 'generate_alt_text' );
		await page.locator( '#doaction' ).click();
		await expect( page.locator( '#aat-bulk-notice' ) ).toContainText( 'Alt-text genereras i bakgrunden' );
	}

	test( 'bulk action queues and the notice reports the finished result', async ( { page } ) => {
		await queueThree( page );
		expect( await altOf( ids[ 1 ] ) ).toBe( '' );

		// The mock swallows the loopback, so cron ticks drive the queue.
		for ( let i = 0; i < 5; i++ ) {
			wpCli( [ 'cron', 'event', 'run', 'aat_queue_tick' ] );
			if ( ( await altOf( ids[ 1 ] ) ) && ( await altOf( ids[ 2 ] ) ) ) {
				break;
			}
		}

		// No reload: the polling module updates the notice in place.
		await expect( page.locator( '#aat-bulk-summary' ) ).toContainText( 'Klart: 2 bilder fick alt-text.', { timeout: 20000 } );
		await expect( page.locator( '#aat-bulk-summary' ) ).toContainText( '1 hoppades över' );

		expect( await altOf( ids[ 0 ] ) ).toBe( EXISTING );
		expect( await altOf( ids[ 1 ] ) ).toBe( RESP_TEXT );
		expect( await altOf( ids[ 2 ] ) ).toBe( RESP_TEXT );
		expect( provenance( ids[ 1 ] ).source ).toBe( 'batch' );
		expect( provenance( ids[ 2 ] ).source ).toBe( 'batch' );

		await page.locator( '.aat-bulk-dismiss' ).click();
		await expect( page.locator( '#aat-bulk-notice' ) ).toHaveCount( 0 );
		await expect( page.locator( '#wpbody-content h1' ).first() ).toBeFocused();
		await page.reload();
		await expect( page.locator( '#aat-bulk-notice' ) ).toHaveCount( 0 );
	} );

	test( 'cancel stops pending images', async ( { page } ) => {
		await queueThree( page );
		await page.locator( '.aat-bulk-cancel' ).click();
		await expect( page.locator( '#aat-bulk-summary' ) ).toContainText( 'avbröts' );
		await expect( page.locator( '.aat-bulk-dismiss' ) ).toBeFocused();

		wpCli( [ 'cron', 'event', 'run', 'aat_queue_tick' ] );
		expect( await altOf( ids[ 1 ] ) ).toBe( '' );
		expect( await altOf( ids[ 2 ] ) ).toBe( '' );
		await page.locator( '.aat-bulk-dismiss' ).click();
		await expect( page.locator( '#aat-bulk-notice' ) ).toHaveCount( 0 );
	} );

	test( 'expired session stops polling', async ( { page } ) => {
		const errors = [];
		page.on( 'pageerror', ( e ) => errors.push( e ) );
		await queueThree( page );
		await page.route( '**/wp-auto-alt-text/v1/bulk*', ( r ) => r.fulfill( { status: 403, contentType: 'application/json', body: '{}' } ) );
		await expect( page.locator( '#aat-bulk-summary' ) ).toContainText( 'Sessionen har gått ut', { timeout: 15000 } );
		expect( errors ).toEqual( [] );
		await page.unroute( '**/wp-auto-alt-text/v1/bulk*' );
		await page.goto( '/wp-admin/upload.php?mode=list' );
		await page.locator( '.aat-bulk-dismiss' ).click();
		await expect( page.locator( '#aat-bulk-notice' ) ).toHaveCount( 0 );
	} );
} );
