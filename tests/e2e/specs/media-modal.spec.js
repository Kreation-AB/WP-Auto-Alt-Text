const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );
const { respText, wpCli, getOption, restoreOption, provenance } = require( '../utils/wp-cli' );

const RESP_TEXT = respText();
const SAMPLE = path.resolve( __dirname, '../assets/sample.jpg' );

/** Matches the admin-ajax request of one plugin action. */
function isAjax( request, action ) {
	return request.url().includes( 'admin-ajax.php' ) && ( request.postData() || '' ).includes( 'action=' + action );
}

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

	/** Uploads an image and opens its details modal in the Media Library grid. */
	async function openDetails( page ) {
		const media = await requestUtils.uploadMedia( SAMPLE );
		ids.push( media.id );
		expect( ( await requestUtils.rest( { path: `/wp/v2/media/${ media.id }` } ) ).alt_text ).toBe( '' );
		// With mode=grid in the URL core drops the item parameter, so set the mode first.
		await page.goto( '/wp-admin/upload.php?mode=grid' );
		await page.goto( `/wp-admin/upload.php?item=${ media.id }` );
		await expect( page.locator( '.aat-alt-context' ) ).toHaveText( 'Ingen produkt eller sida hittades', { timeout: 5000 } );
		return media;
	}

	const altOf = async ( id ) => ( await requestUtils.rest( { path: `/wp/v2/media/${ id }` } ) ).alt_text;

	test( 'generates, previews and applies alt text in the media modal', async ( { page } ) => {
		const media = await openDetails( page );

		await page.locator( '.aat-generate' ).click();
		const dialog = page.locator( 'dialog.aat-preview-dialog' );
		await expect( dialog.locator( '#aat-preview-title' ) ).toHaveText( 'Förhandsgranska alt-text' );
		await expect( dialog ).toHaveAttribute( 'aria-labelledby', 'aat-preview-title' );
		await expect( dialog ).toHaveAttribute( 'aria-describedby', 'aat-preview-context' );
		await expect( dialog.locator( '#aat-preview-context' ) ).toHaveText( 'Alt-textkontext: Ingen produkt eller sida hittades' );
		await expect( dialog.locator( 'label[for="aat-preview-text"]' ) ).toHaveText( 'Alt-text' );
		await expect( dialog.locator( '#aat-preview-text' ) ).toHaveValue( RESP_TEXT );
		await expect( dialog.locator( '#aat-preview-text' ) ).toBeFocused();
		await expect( dialog.locator( 'fieldset.aat-feedback > legend' ) ).toHaveText( 'Förbättra texten' );
		await expect( dialog.locator( '.aat-preview-regenerate' ) ).toHaveCount( 0 );
		expect( await altOf( media.id ) ).toBe( '' );

		await dialog.locator( '.aat-preview-apply' ).click();

		await expect.poll( () => altOf( media.id ) ).toBe( RESP_TEXT );
		// The modal also writes the text into the attachment-details alt field (admin/js/media.js).
		await expect( page.locator( '#attachment-details-two-column-alt-text' ) ).toHaveValue( RESP_TEXT );
		// Apply records the text as written by the modal ('manual'), not by an upload job.
		expect( provenance( media.id ).source ).toBe( 'manual' );
	} );

	test( 'dialog keeps focus, closes on Escape without closing the media modal, and returns focus', async ( { page } ) => {
		await openDetails( page );
		const dialog = page.locator( 'dialog.aat-preview-dialog' );

		await page.locator( '.aat-generate' ).click();
		await expect( dialog.locator( '#aat-preview-text' ) ).toBeFocused();
		await expect( dialog ).toHaveCount( 1 );

		const inDialog = () => page.evaluate( () => !! document.activeElement?.closest( 'dialog.aat-preview-dialog' ) );
		for ( let i = 0; i < 10; i++ ) {
			await page.keyboard.press( 'Tab' );
			expect( await inDialog(), `Tab ${ i + 1 }` ).toBe( true );
		}
		// Shift+Tab from the first control wraps to the last, and Tab wraps back.
		await dialog.locator( '#aat-preview-text' ).focus();
		await page.keyboard.press( 'Shift+Tab' );
		await expect( dialog.locator( '.aat-preview-cancel' ) ).toBeFocused();
		await page.keyboard.press( 'Tab' );
		await expect( dialog.locator( '#aat-preview-text' ) ).toBeFocused();

		await page.keyboard.press( 'Escape' );
		await expect( dialog ).toHaveCount( 0 );
		await expect( page.locator( '.media-modal' ) ).toBeVisible();
		await expect( page.locator( '.aat-generate' ) ).toBeFocused();

		// The unapplied text comes back from the session cache, marked as such.
		let generated = 0;
		page.on( 'request', ( request ) => {
			if ( isAjax( request, 'generate_alt_text_for_attachment' ) ) {
				generated++;
			}
		} );
		await page.keyboard.press( 'Enter' );
		await expect( dialog.locator( '.aat-preview-cached' ) ).toHaveText( '(från cache)' );
		await expect( dialog.locator( '#aat-preview-text' ) ).toHaveValue( RESP_TEXT );
		// The context line comes from the attachment details, not the cache.
		await expect( dialog.locator( '#aat-preview-context' ) ).toHaveText( 'Alt-textkontext: Ingen produkt eller sida hittades' );
		expect( generated ).toBe( 0 );

		// "Generera ny" asks the server again.
		const fresh = page.waitForResponse( ( response ) => isAjax( response.request(), 'generate_alt_text_for_attachment' ) );
		await dialog.locator( '.aat-preview-regenerate' ).click();
		await fresh;
		await expect( dialog.locator( '#aat-preview-text' ) ).toHaveValue( RESP_TEXT );
		await expect( dialog.locator( '.aat-preview-cached' ) ).toHaveCount( 0 );
		await expect( dialog ).toHaveCount( 1 );
		await dialog.locator( '.aat-preview-cancel' ).click();
		await expect( dialog ).toHaveCount( 0 );
		await expect( page.locator( '.aat-generate' ) ).toBeFocused();
	} );

	test( 'apply announces and updates the alt field', async ( { page } ) => {
		const media = await openDetails( page );

		await page.locator( '.aat-generate' ).click();
		const dialog = page.locator( 'dialog.aat-preview-dialog' );
		await expect( dialog.locator( '#aat-preview-text' ) ).toHaveValue( RESP_TEXT );
		await dialog.locator( '.aat-preview-apply' ).click();

		await expect( dialog ).toHaveCount( 0 );
		await expect( page.locator( '.aat-generate-status[role="status"]' ) ).toHaveText( 'Alt-texten är sparad.' );
		await expect( page.locator( '#attachment-details-two-column-alt-text' ) ).toHaveValue( RESP_TEXT );
		await expect( page.locator( '.aat-generate' ) ).toBeFocused();
		await expect.poll( () => altOf( media.id ) ).toBe( RESP_TEXT );
	} );

	test( 'an edited apply is not offered back as the cached AI text', async ( { page } ) => {
		const media = await openDetails( page );
		const dialog = page.locator( 'dialog.aat-preview-dialog' );

		const first = page.waitForResponse( ( response ) => isAjax( response.request(), 'generate_alt_text_for_attachment' ) );
		await page.locator( '.aat-generate' ).click();
		await first;
		await dialog.locator( '#aat-preview-text' ).fill( 'En text som redaktören skrev' );
		await dialog.locator( '.aat-preview-apply' ).click();
		await expect( dialog ).toHaveCount( 0 );
		await expect.poll( () => altOf( media.id ) ).toBe( 'En text som redaktören skrev' );
		// An edited text is the editor's own, not AI-written.
		expect( provenance( media.id ) ).toBeNull();

		// Reopening asks the server again instead of offering the edited text from the cache.
		const second = page.waitForResponse( ( response ) => isAjax( response.request(), 'generate_alt_text_for_attachment' ) );
		await page.locator( '.aat-generate' ).click();
		await second;
		await expect( dialog.locator( '#aat-preview-title' ) ).toHaveText( 'Förhandsgranska alt-text' );
		await expect( dialog.locator( '.aat-preview-cached' ) ).toHaveCount( 0 );
		await expect( dialog.locator( '#aat-preview-text' ) ).toHaveValue( RESP_TEXT );
		await dialog.locator( '.aat-preview-cancel' ).click();
		await expect( dialog ).toHaveCount( 0 );
	} );

	test( 'empty custom feedback shows an inline error', async ( { page } ) => {
		await openDetails( page );
		const dialog = page.locator( 'dialog.aat-preview-dialog' );

		await page.locator( '.aat-generate' ).click();
		await expect( dialog.locator( '#aat-preview-text' ) ).toHaveValue( RESP_TEXT );
		await expect( dialog.locator( 'label[for="aat-feedback-custom"]' ) ).toHaveText( 'Egen instruktion' );

		let improved = 0;
		page.on( 'request', ( request ) => {
			if ( isAjax( request, 'regenerate_alt_text_with_feedback' ) ) {
				improved++;
			}
		} );
		await dialog.locator( '.aat-feedback-submit' ).click();
		await expect( dialog.locator( '.aat-preview-error[role="alert"]' ) ).toHaveText( 'Skriv vad som ska förbättras.' );
		const custom = dialog.locator( '#aat-feedback-custom' );
		await expect( custom ).toBeFocused();
		await expect( custom ).toHaveAttribute( 'aria-invalid', 'true' );
		await expect( custom ).toHaveAttribute( 'aria-describedby', 'aat-preview-error' );
		expect( improved ).toBe( 0 );

		// An option sends the feedback request and announces the new text.
		await dialog.locator( 'button[data-type="more_concise"]' ).click();
		await expect( dialog.locator( '.aat-preview-status[role="status"]' ) ).toHaveText( 'En ny text är klar.' );
		await expect( dialog.locator( '.aat-preview-error' ) ).toHaveText( '' );
		await expect( dialog.locator( '#aat-preview-text' ) ).toHaveValue( RESP_TEXT );
		await expect( dialog.locator( '#aat-preview-text' ) ).toBeEnabled();
		expect( improved ).toBe( 1 );
	} );

	test( 'the attachment edit screen generates and applies without a page-wide observer', async ( { page } ) => {
		const media = await requestUtils.uploadMedia( SAMPLE );
		ids.push( media.id );
		// Record the callers of every page-wide MutationObserver (core's emoji script has one).
		await page.addInitScript( () => {
			window.__aatBodyObservers = [];
			const observe = MutationObserver.prototype.observe;
			MutationObserver.prototype.observe = function ( target, options ) {
				if ( target === document.body && options && options.subtree ) {
					window.__aatBodyObservers.push( String( new Error().stack ) );
				}
				return observe.call( this, target, options );
			};
		} );
		await page.goto( `/wp-admin/post.php?post=${ media.id }&action=edit` );

		// The server renders the context line here; nothing has to fetch it.
		await expect( page.locator( '.aat-alt-context[data-loaded="1"]' ) ).toHaveText( 'Ingen produkt eller sida hittades' );
		await page.locator( '.aat-generate' ).click();
		const dialog = page.locator( 'dialog.aat-preview-dialog' );
		await expect( dialog.locator( '#aat-preview-text' ) ).toHaveValue( RESP_TEXT );
		await expect( dialog.locator( '#aat-preview-context' ) ).toHaveText( 'Alt-textkontext: Ingen produkt eller sida hittades' );
		await dialog.locator( '.aat-preview-apply' ).click();
		await expect( dialog ).toHaveCount( 0 );
		await expect( page.locator( '#attachment_alt' ) ).toHaveValue( RESP_TEXT );
		await expect( page.locator( '.aat-generate' ) ).toBeFocused();
		await expect.poll( () => altOf( media.id ) ).toBe( RESP_TEXT );

		const stacks = await page.evaluate( () => window.__aatBodyObservers );
		expect( stacks.filter( ( stack ) => /plugins\/[^/]+\/admin\/js\//.test( stack ) ) ).toEqual( [] );
	} );

	test( 'the dialog hides the feedback section when the switch is off', async ( { page } ) => {
		const saved = getOption( 'auto_alt_text_enable_feedback' );
		wpCli( [ 'option', 'update', 'auto_alt_text_enable_feedback', '0' ] );
		try {
			await openDetails( page );
			await page.locator( '.aat-generate' ).click();

			const dialog = page.locator( 'dialog.aat-preview-dialog' );
			await expect( dialog.locator( '#aat-preview-text' ) ).toHaveValue( RESP_TEXT );
			await expect( dialog.locator( 'fieldset.aat-feedback' ) ).toHaveCount( 0 );
			await expect( dialog.locator( '.aat-preview-apply' ) ).toBeVisible();
			await expect( dialog.locator( '.aat-preview-cancel' ) ).toBeVisible();
		} finally {
			restoreOption( 'auto_alt_text_enable_feedback', saved );
		}
	} );
} );
