const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );
const { respText, wpCli, getOption, restoreOption } = require( '../utils/wp-cli' );

const RESP_TEXT = respText();
const SAMPLE = path.resolve( __dirname, '../assets/sample.jpg' );
const AI_TEXT = 'AI-text';
const HUMAN_TEXT = 'Mänsklig text';

// Drops the admin's tracked bulk; absent meta makes wp-cli fail, which is fine here.
function clearBulk() {
	try {
		wpCli( [ 'user', 'meta', 'delete', '1', 'aat_bulk' ] );
	} catch ( e ) {
		// Nothing tracked.
	}
}

test.describe( 'alt-text view in list mode', () => {
	let requestUtils;
	let ids = [];
	let savedAutoGenerate;

	test.beforeAll( async ( { baseURL } ) => {
		savedAutoGenerate = getOption( 'auto_alt_text_auto_generate' );
		wpCli( [ 'option', 'update', 'auto_alt_text_auto_generate', '0' ] );
		requestUtils = await RequestUtils.setup( {
			baseURL,
			storageStatePath: 'artifacts/storage-states/admin.json',
		} );
	} );

	test.beforeEach( async () => {
		// ids[0] missing, ids[1] AI-written, ids[2] human-written.
		for ( let i = 0; i < 3; i++ ) {
			ids.push( ( await requestUtils.uploadMedia( SAMPLE ) ).id );
		}
		// No spaces in the argument: wp-env splits on them.
		wpCli( [ 'eval', `update_post_meta(${ ids[ 1 ] },'_wp_attachment_image_alt','${ AI_TEXT }');Auto_Alt_Text_Provenance::record(${ ids[ 1 ] },'batch',null,'x');` ] );
		await requestUtils.rest( { method: 'POST', path: `/wp/v2/media/${ ids[ 2 ] }`, data: { alt_text: HUMAN_TEXT } } );
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

	const altOf = async ( id ) => ( await requestUtils.rest( { path: `/wp/v2/media/${ id }` } ) ).alt_text;

	test( 'list filter shows only images without alt text', async ( { page } ) => {
		await page.goto( '/wp-admin/upload.php?mode=list' );
		for ( const id of ids ) {
			await expect( page.locator( `#post-${ id }` ) ).toBeVisible();
		}
		await expect( page.getByLabel( 'Filtrera på alt-text' ) ).toBeVisible();
		await page.getByLabel( 'Filtrera på alt-text' ).selectOption( 'missing' );
		await page.locator( '#post-query-submit' ).click();
		await expect( page ).toHaveURL( /aat_alt=missing/ );
		await expect( page.locator( `#post-${ ids[ 0 ] }` ) ).toBeVisible();
		await expect( page.locator( `#post-${ ids[ 1 ] }` ) ).toHaveCount( 0 );
		await expect( page.locator( `#post-${ ids[ 2 ] }` ) ).toHaveCount( 0 );
		await expect( page.getByLabel( 'Filtrera på alt-text' ) ).toHaveValue( 'missing' );
	} );

	test( 'column labels', async ( { page } ) => {
		await page.goto( '/wp-admin/upload.php?mode=list' );
		await expect( page.locator( '.wp-list-table thead th.column-aat_alt' ) ).toContainText( 'Alt-text' );
		const cell = ( id ) => page.locator( `#post-${ id } td.column-aat_alt` );
		await expect( cell( ids[ 0 ] ) ).toContainText( 'Saknas' );
		await expect( cell( ids[ 0 ] ).locator( '.aat-alt-col-missing' ) ).toBeVisible();
		await expect( cell( ids[ 1 ] ) ).toContainText( AI_TEXT );
		await expect( cell( ids[ 1 ] ) ).toContainText( 'AI-skriven' );
		await expect( cell( ids[ 2 ] ) ).toContainText( HUMAN_TEXT );
		await expect( cell( ids[ 2 ] ) ).toContainText( 'Skriven av en person' );
	} );

	test( 'filter, select all and bulk generate', async ( { page } ) => {
		await page.goto( '/wp-admin/upload.php?mode=list&aat_alt=missing' );
		await expect( page.locator( `#post-${ ids[ 0 ] }` ) ).toBeVisible();
		await page.locator( '#cb-select-all-1' ).check();
		await page.locator( '#bulk-action-selector-top' ).selectOption( 'generate_alt_text' );
		await page.locator( '#doaction' ).click();
		await expect( page.locator( '#aat-bulk-notice' ) ).toContainText( 'Alt-text genereras i bakgrunden' );
		await expect( page ).toHaveURL( /aat_alt=missing/ );

		for ( let i = 0; i < 5; i++ ) {
			wpCli( [ 'cron', 'event', 'run', 'aat_queue_tick' ] );
			if ( await altOf( ids[ 0 ] ) ) {
				break;
			}
		}
		expect( await altOf( ids[ 0 ] ) ).toBe( RESP_TEXT );
		expect( await altOf( ids[ 1 ] ) ).toBe( AI_TEXT );
		expect( await altOf( ids[ 2 ] ) ).toBe( HUMAN_TEXT );
		await page.locator( '.aat-bulk-dismiss' ).click();
	} );
} );
