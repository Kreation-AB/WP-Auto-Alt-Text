const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );
const { respText, wpCli, getOption, restoreOption, provenance } = require( '../utils/wp-cli' );

const RESP_TEXT = respText();
const SAMPLE = path.resolve( __dirname, '../assets/sample.jpg' );
const HUMAN_TEXT = 'En text som en person skrev';

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

	/**
	 * Uploads B (with a person's alt text), then A, dated after B so the grid's
	 * details modal moves from A to B with its "next" button, and opens A.
	 */
	async function openNeighbours( page ) {
		const b = await requestUtils.uploadMedia( SAMPLE );
		ids.push( b.id );
		await requestUtils.rest( { method: 'POST', path: `/wp/v2/media/${ b.id }`, data: { alt_text: HUMAN_TEXT } } );
		const a = await requestUtils.uploadMedia( SAMPLE );
		ids.push( a.id );
		const later = new Date( Date.now() + 120000 ).toISOString().slice( 0, 19 );
		await requestUtils.rest( { method: 'POST', path: `/wp/v2/media/${ a.id }`, data: { date_gmt: later } } );
		await page.goto( '/wp-admin/upload.php?mode=grid' );
		await page.goto( `/wp-admin/upload.php?item=${ a.id }` );
		await expect( page.locator( `.aat-generate[data-attachment-id="${ a.id }"]` ) ).toBeVisible();
		return { a: a.id, b: b.id };
	}

	/** Holds the matching admin-ajax request until release() is called. */
	async function holdAjax( page, action ) {
		let release;
		const gate = new Promise( ( resolve ) => {
			release = resolve;
		} );
		await page.route( '**/wp-admin/admin-ajax.php', async ( route ) => {
			if ( isAjax( route.request(), action ) ) {
				await gate;
			}
			await route.continue();
		} );
		return () => release();
	}

	test( 'a preview that arrives after moving to another image never writes into it', async ( { page } ) => {
		const { a, b } = await openNeighbours( page );
		const dialog = page.locator( 'dialog.aat-preview-dialog' );
		const release = await holdAjax( page, 'generate_alt_text_for_attachment' );

		const generateA = page.locator( `.aat-generate[data-attachment-id="${ a }"]` );
		await generateA.focus();
		await page.keyboard.press( 'Enter' );
		// Busy without dropping focus: aria-disabled, not disabled.
		await expect( generateA ).toHaveAttribute( 'aria-disabled', 'true' );
		await expect( generateA ).not.toHaveAttribute( 'disabled' );
		await expect( generateA ).toBeFocused();

		// Core's "next" button moves the details modal to B while the request runs.
		await page.locator( '.edit-media-header .right' ).click();
		await expect( page ).toHaveURL( new RegExp( `item=${ b }` ) );
		await expect( page.locator( '#attachment-details-two-column-alt-text' ) ).toHaveValue( HUMAN_TEXT );

		const answered = page.waitForResponse( ( response ) => isAjax( response.request(), 'generate_alt_text_for_attachment' ) );
		release();
		await answered;
		// A's text waits in the session cache; no dialog opens over B.
		await expect.poll( () => page.evaluate( ( id ) => window.sessionStorage.getItem( 'aat_alt_' + id ), a ) ).toBe( RESP_TEXT );
		await expect( dialog ).toHaveCount( 0 );
		await expect( page.locator( '#attachment-details-two-column-alt-text' ) ).toHaveValue( HUMAN_TEXT );
		expect( await altOf( b ) ).toBe( HUMAN_TEXT );
		expect( await altOf( a ) ).toBe( '' );

		// Back on A, the next click offers the cached text, and Apply writes A only.
		await page.locator( '.edit-media-header .left' ).click();
		await page.locator( `.aat-generate[data-attachment-id="${ a }"]` ).click();
		await expect( dialog.locator( '.aat-preview-cached' ) ).toHaveText( '(från cache)' );
		await dialog.locator( '.aat-preview-apply' ).click();
		await expect( dialog ).toHaveCount( 0 );
		await expect.poll( () => altOf( a ) ).toBe( RESP_TEXT );
		await expect( page.locator( '#attachment-details-two-column-alt-text' ) ).toHaveValue( RESP_TEXT );
		expect( await altOf( b ) ).toBe( HUMAN_TEXT );
	} );

	test( 'an Apply that finishes after Escape and moving on writes only its own image', async ( { page } ) => {
		const { a, b } = await openNeighbours( page );
		const dialog = page.locator( 'dialog.aat-preview-dialog' );

		await page.locator( `.aat-generate[data-attachment-id="${ a }"]` ).click();
		await expect( dialog.locator( '#aat-preview-text' ) ).toHaveValue( RESP_TEXT );
		const release = await holdAjax( page, 'apply_alt_text' );
		const apply = dialog.locator( '.aat-preview-apply' );
		await apply.click();
		// Saving keeps focus in the dialog.
		await expect( apply ).toHaveAttribute( 'aria-disabled', 'true' );
		await expect( apply ).toBeFocused();
		await page.keyboard.press( 'Escape' );
		await expect( dialog ).toHaveCount( 0 );

		await page.locator( '.edit-media-header .right' ).click();
		await expect( page ).toHaveURL( new RegExp( `item=${ b }` ) );
		const answered = page.waitForResponse( ( response ) => isAjax( response.request(), 'apply_alt_text' ) );
		release();
		await answered;

		await expect.poll( () => altOf( a ) ).toBe( RESP_TEXT );
		await expect( page.locator( '#attachment-details-two-column-alt-text' ) ).toHaveValue( HUMAN_TEXT );
		expect( await altOf( b ) ).toBe( HUMAN_TEXT );

		// A's model took the saved text, so going back shows it.
		await page.locator( '.edit-media-header .left' ).click();
		await expect( page.locator( '#attachment-details-two-column-alt-text' ) ).toHaveValue( RESP_TEXT );
		expect( await altOf( b ) ).toBe( HUMAN_TEXT );
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
