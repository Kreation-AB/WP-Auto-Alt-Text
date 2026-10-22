const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );
const { wpCli, getOption, restoreOption } = require( '../utils/wp-cli' );
const { checkA11y } = require( '../utils/axe' );

const SAMPLE = path.resolve( __dirname, '../assets/sample.jpg' );

// Drops the admin's tracked bulk; absent meta makes wp-cli fail, which is fine here.
function clearBulk() {
	try {
		wpCli( [ 'user', 'meta', 'delete', '1', 'aat_bulk' ] );
	} catch ( e ) {
		// Nothing tracked.
	}
}

test.describe( 'accessibility', () => {
	let requestUtils;
	let ids = [];
	let savedAutoGenerate;

	// Auto-generate off: no upload job can change the panel behind the test's back.
	test.beforeAll( async ( { baseURL } ) => {
		savedAutoGenerate = getOption( 'auto_alt_text_auto_generate' );
		wpCli( [ 'option', 'update', 'auto_alt_text_auto_generate', '0' ] );
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
		clearBulk();
	} );

	test.afterAll( async ( { browser } ) => {
		restoreOption( 'auto_alt_text_auto_generate', savedAutoGenerate );
		const page = await browser.newPage();
		await page.goto( '/wp-admin/upload.php?mode=grid' );
		await page.close();
	} );

	// With mode=grid in the URL core drops the item parameter, so set the mode first.
	async function openDetails( page ) {
		const media = await requestUtils.uploadMedia( SAMPLE );
		ids.push( media.id );
		await page.goto( '/wp-admin/upload.php?mode=grid' );
		await page.goto( `/wp-admin/upload.php?item=${ media.id }` );
		await expect( page.locator( '.aat-alt-context' ) ).toHaveText( 'Ingen produkt eller sida hittades', { timeout: 5000 } );
	}

	// axe skips hidden panels, so each section is opened and scanned in turn.
	test( 'settings page has no new serious accessibility violations in any section', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=auto-alt-text' );
		await expect( page.locator( '.wrap' ) ).toBeVisible();
		for ( const slug of [ 'anslutning', 'sprak', 'namn', 'automatik', 'cache', 'feedback' ] ) {
			await page.locator( '#aat-tab-' + slug ).click();
			await expect( page.locator( '#aat-section-' + slug ) ).toBeVisible();
			await checkA11y( page, { pageName: 'settings', include: '.wrap' } );
		}
	} );

	test( 'Hela biblioteket has no new serious accessibility violations', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=auto-alt-text-library' );
		await expect( page.locator( '.wrap' ) ).toBeVisible();
		await checkA11y( page, { pageName: 'library', include: [ '.wrap' ] } );
	} );

	test( 'attachment details panel has none', async ( { page } ) => {
		await openDetails( page );
		await checkA11y( page, {
			pageName: 'media-details',
			include: [ '.compat-field-aat_alt_context', '.compat-field-generate_alt_text' ],
		} );
	} );

	test( 'preview dialog has none', async ( { page } ) => {
		await openDetails( page );
		await page.locator( '.aat-generate' ).click();
		await expect( page.locator( '#aat-preview-title' ) ).toBeVisible();
		await expect( page.locator( '#aat-preview-text' ) ).toBeFocused();
		await checkA11y( page, { pageName: 'preview-dialog', include: 'dialog.aat-preview-dialog' } );
	} );

	test( 'bulk progress notice has none while running and when finished', async ( { page } ) => {
		for ( let i = 0; i < 2; i++ ) {
			ids.push( ( await requestUtils.uploadMedia( SAMPLE ) ).id );
		}
		await page.goto( '/wp-admin/upload.php?mode=list' );
		for ( const id of ids ) {
			await page.locator( `#cb-select-${ id }` ).check();
		}
		await page.locator( '#bulk-action-selector-top' ).selectOption( 'generate_alt_text' );
		await page.locator( '#doaction' ).click();
		await expect( page.locator( '#aat-bulk-notice[data-state="running"]' ) ).toBeVisible();
		await checkA11y( page, { pageName: 'bulk-notice', include: '#aat-bulk-notice' } );

		for ( let i = 0; i < 5; i++ ) {
			wpCli( [ 'cron', 'event', 'run', 'aat_queue_tick' ] );
		}
		await expect( page.locator( '#aat-bulk-notice[data-state="finished"]' ) ).toBeVisible( { timeout: 20000 } );
		await checkA11y( page, { pageName: 'bulk-notice', include: '#aat-bulk-notice' } );
		await page.locator( '.aat-bulk-dismiss' ).click();
		await expect( page.locator( '#aat-bulk-notice' ) ).toHaveCount( 0 );
	} );
} );
