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

	test( 'list mode reports the files it could not queue', async ( { page } ) => {
		const note = await requestUtils.uploadMedia( { name: 'anteckning.txt', mimeType: 'text/plain', buffer: Buffer.from( 'Ingen bild.' ) } );
		ids.push( note.id );
		ids.push( ( await requestUtils.uploadMedia( SAMPLE ) ).id );
		const rejected = page.locator( '.aat-bulk-rejected' );
		const runBulk = async ( selected ) => {
			await page.goto( '/wp-admin/upload.php?mode=list' );
			for ( const id of selected ) {
				await page.locator( `#cb-select-${ id }` ).check();
			}
			await page.locator( '#bulk-action-selector-top' ).selectOption( 'generate_alt_text' );
			await page.locator( '#doaction' ).click();
		};

		// Only a non-image: the warning alone, nothing tracked.
		await runBulk( [ note.id ] );
		await expect( rejected ).toHaveClass( /notice-warning/ );
		await expect( rejected.locator( 'p' ) ).toHaveText( '1 av de markerade filerna kunde inte köas: 1 är inte en bild.' );
		await expect( page.locator( '#aat-bulk-notice' ) ).toHaveCount( 0 );

		// With an image too: the warning next to the progress notice.
		await runBulk( ids );
		await expect( rejected.locator( 'p' ) ).toHaveText( '1 av de markerade filerna kunde inte köas: 1 är inte en bild.' );
		await expect( page.locator( '#aat-bulk-summary' ) ).toContainText( 'Alt-text genereras i bakgrunden: 0 av 1 bilder klara.' );
		// Core drops the one-time args from the address bar.
		await expect( page ).not.toHaveURL( /aat_bulk_rejected/ );
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

	test( 'grid bulk button queues the selection', async ( { page } ) => {
		const errors = [];
		page.on( 'pageerror', ( e ) => errors.push( e ) );
		for ( let i = 0; i < 2; i++ ) {
			ids.push( ( await requestUtils.uploadMedia( SAMPLE ) ).id );
		}
		let posts = 0;
		await page.route( '**/wp-auto-alt-text/v1/bulk', async ( route ) => {
			if ( route.request().method() === 'POST' ) {
				posts++;
				await new Promise( ( resolve ) => setTimeout( resolve, 500 ) );
			}
			await route.continue();
		} );
		await page.goto( '/wp-admin/upload.php?mode=grid' );

		const button = page.locator( 'button.aat-grid-generate' );
		await expect( button ).toBeHidden();
		await page.locator( '.select-mode-toggle-button' ).click();
		await expect( button ).toBeVisible();
		await expect( button ).toBeDisabled();
		await expect( button ).toHaveText( 'Generera alt-text för markerade' );

		for ( const id of ids ) {
			await page.locator( `.attachment[data-id="${ id }"]` ).click();
		}
		await expect( button ).toBeEnabled();

		// Two quick clicks send one request.
		await button.dblclick();
		await expect( page.locator( '#aat-bulk-notice' ) ).toBeVisible();
		await expect( page.locator( '#aat-bulk-notice' ) ).toContainText( 'Alt-text genereras i bakgrunden' );
		expect( posts ).toBe( 1 );

		for ( let i = 0; i < 5; i++ ) {
			wpCli( [ 'cron', 'event', 'run', 'aat_queue_tick' ] );
			if ( ( await altOf( ids[ 0 ] ) ) && ( await altOf( ids[ 1 ] ) ) ) {
				break;
			}
		}
		await expect( page.locator( '#aat-bulk-summary' ) ).toContainText( 'Klart: 2 bilder fick alt-text.', { timeout: 20000 } );
		expect( await altOf( ids[ 0 ] ) ).toBe( RESP_TEXT );
		expect( errors ).toEqual( [] );

		await page.locator( '.aat-bulk-dismiss' ).click();
		await expect( page.locator( '#aat-bulk-notice' ) ).toHaveCount( 0 );
	} );

	test( 'grid bulk button shows a rejected request in the notice area', async ( { page } ) => {
		ids.push( ( await requestUtils.uploadMedia( SAMPLE ) ).id );
		await page.route( '**/wp-auto-alt-text/v1/bulk', ( route ) => {
			if ( route.request().method() !== 'POST' ) {
				return route.continue();
			}
			return route.fulfill( {
				status: 409,
				contentType: 'application/json',
				body: JSON.stringify( { code: 'aat_bulk_no_key', message: 'Ange en API-nyckel för test.', data: { status: 409 } } ),
			} );
		} );
		await page.goto( '/wp-admin/upload.php?mode=grid' );
		await page.locator( '.select-mode-toggle-button' ).click();
		await page.locator( `.attachment[data-id="${ ids[ 0 ] }"]` ).click();
		await page.locator( 'button.aat-grid-generate' ).click();
		await expect( page.locator( '#aat-grid-error' ) ).toContainText( 'Ange en API-nyckel för test.' );
		await expect( page.locator( '#aat-bulk-notice' ) ).toHaveCount( 0 );
		await expect( page.locator( 'button.aat-grid-generate' ) ).toBeEnabled();
	} );

	test( 'grid bulk button reports images it could not queue', async ( { page } ) => {
		ids.push( ( await requestUtils.uploadMedia( SAMPLE ) ).id );
		ids.push( ( await requestUtils.uploadMedia( SAMPLE ) ).id );
		// As for an Author who selected other people's images: nothing queued, so no notice for an earlier bulk either.
		await page.route( '**/wp-auto-alt-text/v1/bulk', ( route ) => {
			if ( route.request().method() !== 'POST' ) {
				return route.continue();
			}
			return route.fulfill( {
				status: 202,
				contentType: 'application/json',
				body: JSON.stringify( {
					queued: 0,
					already_queued: 0,
					rejected: { not_image: 0, no_permission: 2, not_found: 0 },
					bulk: { state: 'finished', stalled: false, total: 1, written: 1, skipped: 0, cancelled: 0, removed: 0, failed: 0, pending: 0, retrying: 0, failures: [] },
				} ),
			} );
		} );
		await page.goto( '/wp-admin/upload.php?mode=grid' );
		await page.locator( '.select-mode-toggle-button' ).click();
		for ( const id of ids ) {
			await page.locator( `.attachment[data-id="${ id }"]` ).click();
		}
		await page.locator( 'button.aat-grid-generate' ).click();
		const rejected = page.locator( '#aat-grid-rejected' );
		await expect( rejected ).toHaveClass( /notice-warning/ );
		await expect( rejected.locator( '[role="status"]' ) ).toHaveText( '2 av de markerade filerna kunde inte köas: 2 får du inte redigera.' );
		await expect( page.locator( '#aat-grid-error' ) ).toHaveCount( 0 );
		await expect( page.locator( '#aat-bulk-notice' ) ).toHaveCount( 0 );
	} );
} );
