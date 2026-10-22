const path = require( 'node:path' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { respText, wpCli, getOption, restoreOption, provenance } = require( '../utils/wp-cli' );
const { checkA11y } = require( '../utils/axe' );

const RESP_TEXT = respText();
const SAMPLE = path.resolve( __dirname, '../assets/sample.jpg' );
const SAVED = 'Alt-texten är sparad på bilden i mediebiblioteket och i blocket.';

/** Matches the admin-ajax request of one plugin action. */
function isAjax( request, action ) {
	return request.url().includes( 'admin-ajax.php' ) && ( request.postData() || '' ).includes( 'action=' + action );
}

test.describe( 'block editor', () => {
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

	test.afterEach( async ( { requestUtils } ) => {
		for ( const id of ids ) {
			await requestUtils.deleteMedia( id );
		}
		ids = [];
		await requestUtils.deleteAllPosts();
	} );

	/** A new post holding a selected Image block for a freshly uploaded image, with the block settings open. */
	async function imageBlock( { admin, editor, page, requestUtils } ) {
		const media = await requestUtils.uploadMedia( SAMPLE );
		ids.push( media.id );
		expect( media.alt_text ).toBe( '' );
		await admin.createNewPost();
		await editor.insertBlock( { name: 'core/image', attributes: { id: media.id, url: media.source_url } } );
		await editor.openDocumentSettingsSidebar();
		// WP 7.x splits the block inspector into Content, Settings and Styles; the panel is in Settings.
		const settingsTab = page.getByRole( 'region', { name: 'Editor settings' } ).getByRole( 'tab', { name: 'Settings', exact: true } );
		if ( await settingsTab.count() ) {
			await settingsTab.click();
		}
		return media;
	}

	async function altOf( requestUtils, id ) {
		return ( await requestUtils.rest( { path: `/wp/v2/media/${ id }`, params: { context: 'edit' } } ) ).alt_text;
	}

	async function blockAlt( editor ) {
		const [ block ] = await editor.getBlocks();
		return block.attributes.alt;
	}

	function generateButton( page ) {
		return page.getByRole( 'region', { name: 'Editor settings' } ).getByRole( 'button', { name: 'Generera alt-text', exact: true } );
	}

	test( 'generate shows a preview and writes nothing before Använd', async ( { admin, editor, page, requestUtils } ) => {
		const media = await imageBlock( { admin, editor, page, requestUtils } );
		const panel = page.getByRole( 'region', { name: 'Editor settings' } );
		await expect( panel.getByRole( 'button', { name: 'Alt-text med AI', exact: true } ) ).toHaveAttribute( 'aria-expanded', 'true' );

		let applied = 0;
		page.on( 'request', ( request ) => {
			if ( isAjax( request, 'apply_alt_text' ) ) {
				applied++;
			}
		} );
		const generated = page.waitForRequest( ( request ) => isAjax( request, 'generate_alt_text_for_attachment' ) );
		await generateButton( page ).click();
		const sent = new URLSearchParams( ( await generated ).postData() );
		expect( sent.get( 'preview' ) ).toBe( 'true' );
		expect( sent.get( 'attachment_id' ) ).toBe( String( media.id ) );

		const modal = page.getByRole( 'dialog', { name: 'Förhandsgranska alt-text' } );
		const text = modal.getByRole( 'textbox', { name: 'Alt-text' } );
		await expect( text ).toHaveValue( RESP_TEXT );
		await expect( text ).toBeFocused();
		await expect( modal.getByRole( 'button', { name: 'Använd', exact: true } ) ).toBeEnabled();
		await expect( modal.getByRole( 'button', { name: 'Avbryt', exact: true } ) ).toBeVisible();

		// The preview is only a preview: neither the attachment nor the block has the text.
		expect( await altOf( requestUtils, media.id ) ).toBe( '' );
		expect( await blockAlt( editor ) ).toBe( '' );

		await modal.getByRole( 'button', { name: 'Avbryt', exact: true } ).click();
		await expect( modal ).toHaveCount( 0 );
		await expect( generateButton( page ) ).toBeFocused();
		expect( await altOf( requestUtils, media.id ) ).toBe( '' );
		expect( await blockAlt( editor ) ).toBe( '' );
		expect( provenance( media.id ) ).toBeNull();
		expect( applied ).toBe( 0 );
	} );

	test( 'Använd writes the attachment and the block', async ( { admin, editor, page, requestUtils } ) => {
		const media = await imageBlock( { admin, editor, page, requestUtils } );
		await generateButton( page ).click();
		const modal = page.getByRole( 'dialog', { name: 'Förhandsgranska alt-text' } );
		await expect( modal.getByRole( 'textbox', { name: 'Alt-text' } ) ).toHaveValue( RESP_TEXT );

		await modal.getByRole( 'button', { name: 'Använd', exact: true } ).click();
		await expect( modal ).toHaveCount( 0 );
		await expect( page.locator( '.components-snackbar' ).filter( { hasText: SAVED } ) ).toBeVisible();
		await expect( generateButton( page ) ).toBeFocused();

		expect( await altOf( requestUtils, media.id ) ).toBe( RESP_TEXT );
		expect( await blockAlt( editor ) ).toBe( RESP_TEXT );
		// Applied unchanged, so it stays AI-written, as from the media modal.
		expect( provenance( media.id ).source ).toBe( 'manual' );
	} );

	test( 'an edited text is saved as the editor\'s own', async ( { admin, editor, page, requestUtils } ) => {
		const media = await imageBlock( { admin, editor, page, requestUtils } );
		await generateButton( page ).click();
		const modal = page.getByRole( 'dialog', { name: 'Förhandsgranska alt-text' } );
		const text = modal.getByRole( 'textbox', { name: 'Alt-text' } );
		await expect( text ).toHaveValue( RESP_TEXT );

		// An empty text is not sent.
		await text.fill( '   ' );
		const apply = modal.getByRole( 'button', { name: 'Använd', exact: true } );
		await apply.click();
		await expect( modal.locator( '.components-notice.is-error' ) ).toHaveText( /Skriv en alt-text, eller välj Avbryt\./ );
		await expect( text ).toBeFocused();
		await expect( text ).toHaveAttribute( 'aria-invalid', 'true' );
		const describedBy = await text.getAttribute( 'aria-describedby' );
		await expect( page.locator( `[id="${ describedBy }"]` ) ).toContainText( 'Skriv en alt-text, eller välj Avbryt.' );

		await text.fill( 'En text som redaktören skrev' );
		await expect( text ).not.toHaveAttribute( 'aria-invalid', 'true' );
		const sent = page.waitForRequest( ( request ) => isAjax( request, 'apply_alt_text' ) );
		await apply.click();
		const params = new URLSearchParams( ( await sent ).postData() );
		expect( params.get( 'is_edited' ) ).toBe( '1' );
		expect( params.get( 'original_text' ) ).toBe( RESP_TEXT );
		await expect( modal ).toHaveCount( 0 );

		expect( await altOf( requestUtils, media.id ) ).toBe( 'En text som redaktören skrev' );
		expect( await blockAlt( editor ) ).toBe( 'En text som redaktören skrev' );
		expect( provenance( media.id ) ).toBeNull();
	} );

	test( 'errors show in the modal and leave the attachment alone', async ( { admin, editor, page, requestUtils } ) => {
		const media = await imageBlock( { admin, editor, page, requestUtils } );
		let failGenerate = true;
		await page.route( '**/wp-admin/admin-ajax.php', async ( route ) => {
			if ( failGenerate && isAjax( route.request(), 'generate_alt_text_for_attachment' ) ) {
				await route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( { success: false, data: { message: 'Kvoten är slut.' } } ) } );
				return;
			}
			// An expired nonce: admin-ajax answers -1 with HTTP 403, not JSON.
			if ( isAjax( route.request(), 'apply_alt_text' ) ) {
				await route.fulfill( { status: 403, contentType: 'text/html', body: '-1' } );
				return;
			}
			await route.fallback();
		} );

		// A failed generation: the error, and nothing to apply.
		await generateButton( page ).click();
		const modal = page.getByRole( 'dialog', { name: 'Förhandsgranska alt-text' } );
		await expect( modal.locator( '.components-notice.is-error' ) ).toContainText( 'Det gick inte att generera alt-text. Kvoten är slut.' );
		await expect( modal.getByRole( 'textbox' ) ).toHaveCount( 0 );
		await expect( modal.getByRole( 'button', { name: 'Använd', exact: true } ) ).toHaveCount( 0 );
		await modal.getByRole( 'button', { name: 'Avbryt', exact: true } ).click();
		await expect( modal ).toHaveCount( 0 );
		await expect( generateButton( page ) ).toBeFocused();

		// A failed save keeps the modal open with the text.
		failGenerate = false;
		await generateButton( page ).click();
		await expect( modal.getByRole( 'textbox', { name: 'Alt-text' } ) ).toHaveValue( RESP_TEXT );
		await modal.getByRole( 'button', { name: 'Använd', exact: true } ).click();

		await expect( modal.locator( '.components-notice.is-error' ) ).toContainText( 'Sessionen har gått ut. Ladda om sidan och försök igen.' );
		await expect( modal.getByRole( 'textbox', { name: 'Alt-text' } ) ).toHaveValue( RESP_TEXT );
		await expect( modal.getByRole( 'button', { name: 'Använd', exact: true } ) ).toBeFocused();
		expect( await blockAlt( editor ) ).toBe( '' );
		expect( await altOf( requestUtils, media.id ) ).toBe( '' );

		await page.keyboard.press( 'Escape' );
		await expect( modal ).toHaveCount( 0 );
		await expect( generateButton( page ) ).toBeFocused();
	} );

	test( 'the preview modal passes axe', async ( { admin, editor, page, requestUtils } ) => {
		await imageBlock( { admin, editor, page, requestUtils } );
		await generateButton( page ).click();
		const modal = page.getByRole( 'dialog', { name: 'Förhandsgranska alt-text' } );
		await expect( modal.getByRole( 'textbox', { name: 'Alt-text' } ) ).toHaveValue( RESP_TEXT );
		await checkA11y( page, { pageName: 'block-editor-preview', include: '.components-modal__frame' } );
	} );

	// Review Focus 4: media.js and block-editor.js are both loaded in the editor.
	test( 'generate from the media modal inside the editor opens one dialog', async ( { admin, editor, page, requestUtils } ) => {
		const media = await imageBlock( { admin, editor, page, requestUtils } );
		await expect( page.locator( 'script#aat-block-editor-js' ) ).toHaveCount( 1 );
		await expect( page.locator( 'script#auto-alt-text-media-js' ) ).toHaveCount( 1 );

		// The Image block's Replace → Open Media Library.
		await editor.clickBlockToolbarButton( 'Replace' );
		await page.getByRole( 'menuitem', { name: 'Open Media Library' } ).click();
		const frame = page.locator( '.media-modal' );
		await expect( frame ).toBeVisible();
		await frame.getByRole( 'tab', { name: 'Media Library' } ).click();
		const tile = frame.locator( `li.attachment[data-id="${ media.id }"]` );
		await expect( tile ).toBeVisible();
		// Clicking the single selected attachment would deselect it.
		if ( ( await tile.getAttribute( 'aria-checked' ) ) !== 'true' ) {
			await tile.click();
		}
		const button = frame.locator( `.aat-generate[data-attachment-id="${ media.id }"]` );
		await expect( button ).toBeVisible();

		await button.click();
		const dialog = page.locator( 'dialog.aat-preview-dialog' );
		await expect( dialog.locator( '#aat-preview-text' ) ).toHaveValue( RESP_TEXT );
		await expect( dialog ).toHaveCount( 1 );
		await expect( page.locator( '.components-modal__frame' ) ).toHaveCount( 0 );

		await page.keyboard.press( 'Escape' );
		await expect( dialog ).toHaveCount( 0 );
		await expect( frame ).toBeVisible();
		await expect( button ).toBeFocused();
		expect( await altOf( requestUtils, media.id ) ).toBe( '' );
	} );
} );
