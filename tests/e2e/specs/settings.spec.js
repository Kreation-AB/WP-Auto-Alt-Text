const { test, expect } = require( '@playwright/test' );
const { wpCli, getOption, restoreOption } = require( '../utils/wp-cli' );

const SETTINGS = '/wp-admin/admin.php?page=auto-alt-text';
// Saves round-trip every field; Töm cache bumps the cache version.
const OPTIONS = [ 'auto_alt_text_model', 'auto_alt_text_naming', 'auto_alt_text_auto_generate', 'language', 'aat_cache_version' ];
// Saves and Kontrollera nu store the key check, an array, so it goes through JSON.
const JSON_OPTIONS = [ 'aat_key_status' ];
const SLUGS = [ 'anslutning', 'sprak', 'namn', 'automatik', 'cache', 'feedback' ];

// Submits the form and waits until the saved page (options.php's redirect, settings-updated=true) has loaded.
async function save( page ) {
	// Only a saved page has a settings notice, so its appearance below proves the new page is there:
	// the old page can already show the section in its URL and panel.
	await expect( page.locator( '.settings-error' ) ).toHaveCount( 0 );
	// The redirected GET is a fresh response, so a stale settings-updated URL cannot satisfy this.
	const redirected = page.waitForResponse( ( r ) => r.url().includes( 'settings-updated=true' ) );
	await page.locator( '#submit' ).click();
	await redirected;
	await expect( page.locator( '.settings-error' ).first() ).toBeVisible();
	// Then let it finish parsing.
	await page.waitForLoadState( 'domcontentloaded' );
}

// After a save from a page reached by Töm cache, only the save's notice shows.
async function expectNoCacheNoticeAfterSave( page ) {
	await save( page );
	expect( await page.getByText( 'Cachen är tömd.' ).count() ).toBe( 0 );
}

async function openSection( page, slug ) {
	await page.locator( '#aat-tab-' + slug ).click();
	await expect( page.locator( '#aat-section-' + slug ) ).toBeVisible();
}

/**
 * Clicks Kontrollera nu from an unchecked key, so only the click can produce ✓:
 * every save checks the key while no valid record exists and stores one.
 * The tests site defines AUTO_ALT_TEXT_OPENAI_API_KEY (a fake key), and the mock answers GET /v1/models/{id} with 200.
 * The describe's afterAll restores aat_key_status.
 */
async function checkKeyFromUnchecked( page ) {
	restoreOption( 'aat_key_status', null );
	await page.goto( SETTINGS );
	await openSection( page, 'anslutning' );
	await expect( page.locator( '#aat-key-status' ) ).toHaveText( 'Inte kontrollerad' );

	const posted = page.waitForResponse( ( r ) => r.request().method() === 'POST' && r.url().includes( '/wp-admin/admin-post.php' ) );
	await page.getByRole( 'button', { name: 'Kontrollera nu' } ).click();
	expect( ( await posted ).status() ).toBe( 302 );
	await expect( page.locator( '#aat-key-status' ) ).toHaveText( /^✓ Nyckeln fungerar och har tillgång till / );
}

/** Collects the POSTs the page sends, so a test can assert that a form did not submit. */
function recordPosts( page ) {
	const posts = [];
	page.on( 'request', ( r ) => {
		if ( r.method() === 'POST' ) {
			posts.push( r.url() );
		}
	} );
	return posts;
}

function getJsonOption( name ) {
	try {
		return wpCli( [ 'option', 'get', name, '--format=json' ] );
	} catch ( e ) {
		if ( ! String( e.stderr || '' ).includes( 'Could not get' ) ) {
			throw e;
		}
		return null;
	}
}

function restoreJsonOption( name, json ) {
	if ( json === null ) {
		restoreOption( name, null );
	} else {
		wpCli( [ 'option', 'update', name, json, '--format=json' ] );
	}
}

test.describe( 'settings page', () => {
	const saved = {};

	test.beforeAll( () => {
		for ( const name of OPTIONS ) {
			saved[ name ] = getOption( name );
		}
		for ( const name of JSON_OPTIONS ) {
			saved[ name ] = getJsonOption( name );
		}
	} );

	test.afterAll( () => {
		for ( const name of OPTIONS ) {
			restoreOption( name, saved[ name ] );
		}
		for ( const name of JSON_OPTIONS ) {
			restoreJsonOption( name, saved[ name ] );
		}
	} );

	test( 'saving settings keeps the default model unpinned and persists the naming level', async ( { page } ) => {
		restoreOption( 'auto_alt_text_model', null );

		await page.goto( SETTINGS );
		const initiallyOn = await page.locator( '#auto_generate' ).isChecked();
		await openSection( page, 'automatik' );
		// The checkbox sits inside the switch, so click the switch's label.
		await page.locator( 'label.switch:has(#auto_generate)' ).click();
		await save( page );
		await page.goto( SETTINGS );
		await expect( page.locator( '#auto_generate' ) ).toBeChecked( { checked: ! initiallyOn } );
		await expect( page.locator( '#model' ) ).toHaveValue( '' );
		expect( [ null, '' ] ).toContain( getOption( 'auto_alt_text_model' ) );

		await openSection( page, 'namn' );
		await page.locator( '#naming' ).selectOption( 'product' );
		await save( page );
		await page.goto( SETTINGS );
		await expect( page.locator( '#naming' ) ).toHaveValue( 'product' );

		// Put the toggle back.
		await openSection( page, 'automatik' );
		await page.locator( 'label.switch:has(#auto_generate)' ).click();
		await save( page );
		await page.goto( SETTINGS );
		await expect( page.locator( '#auto_generate' ) ).toBeChecked( { checked: initiallyOn } );
	} );

	test( 'each tab is named by its section and described by its summary', async ( { page } ) => {
		await page.goto( SETTINGS );
		await expect( page.getByRole( 'tablist' ) ).toHaveAttribute( 'aria-orientation', 'vertical' );
		await expect( page.getByRole( 'tab' ) ).toHaveCount( SLUGS.length );
		for ( const slug of [ 'anslutning', 'sprak' ] ) {
			const tab = page.locator( '#aat-tab-' + slug );
			const name = ( await tab.locator( '.aat-nav-name' ).textContent() ).trim();
			const summary = ( await page.locator( '#aat-summary-' + slug ).textContent() ).trim();
			await expect( tab ).toHaveAccessibleName( name );
			await expect( tab ).toHaveAccessibleDescription( summary );
		}
		await expect( page.getByRole( 'tabpanel', { name: 'Anslutning', exact: true } ) ).toBeVisible();
		await expect( page.getByRole( 'tabpanel' ) ).toHaveCount( 1 );
	} );

	test( 'arrow keys move between sections', async ( { page } ) => {
		await page.goto( SETTINGS );
		await page.locator( '#aat-tab-anslutning' ).focus();

		await page.keyboard.press( 'ArrowDown' );
		await expect( page.locator( '#aat-tab-sprak' ) ).toHaveAttribute( 'aria-selected', 'true' );
		await expect( page.locator( '#aat-tab-sprak' ) ).toBeFocused();
		await expect( page.locator( '#aat-tab-anslutning' ) ).toHaveAttribute( 'aria-selected', 'false' );
		await expect( page.locator( '#aat-section-sprak' ) ).toBeVisible();
		await expect( page.locator( '#aat-section-anslutning' ) ).toBeHidden();

		await page.keyboard.press( 'End' );
		await expect( page.locator( '#aat-tab-feedback' ) ).toHaveAttribute( 'aria-selected', 'true' );
		await expect( page.locator( '#aat-section-feedback' ) ).toBeVisible();

		// Down from the last tab wraps to the first, and Up wraps back.
		await page.keyboard.press( 'ArrowDown' );
		await expect( page.locator( '#aat-tab-anslutning' ) ).toHaveAttribute( 'aria-selected', 'true' );
		await page.keyboard.press( 'ArrowUp' );
		await expect( page.locator( '#aat-tab-feedback' ) ).toHaveAttribute( 'aria-selected', 'true' );

		await page.keyboard.press( 'Home' );
		await expect( page.locator( '#aat-tab-anslutning' ) ).toHaveAttribute( 'aria-selected', 'true' );
		await expect( page.locator( '#aat-tab-anslutning' ) ).toBeFocused();
		await expect( page.locator( '#aat-section-anslutning' ) ).toBeVisible();
		await expect( page.locator( '#aat-section-feedback' ) ).toBeHidden();

		// Only the active tab is in the Tab order.
		await expect( page.locator( '[role="tab"][tabindex="0"]' ) ).toHaveCount( 1 );
		await expect( page.locator( '[role="tab"][tabindex="-1"]' ) ).toHaveCount( SLUGS.length - 1 );
	} );

	test( 'saving returns to the same section', async ( { page } ) => {
		await page.goto( SETTINGS );
		await openSection( page, 'sprak' );
		expect( page.url() ).toContain( 'section=sprak' );

		await save( page );
		expect( page.url() ).toContain( 'section=sprak' );
		await expect( page.locator( '#aat-section-sprak' ) ).toBeVisible();
		await expect( page.locator( '#aat-tab-sprak' ) ).toHaveAttribute( 'aria-selected', 'true' );
		await expect( page.locator( '#aat-section-anslutning' ) ).toBeHidden();
	} );

	test( 'the summary updates before saving', async ( { page } ) => {
		await page.goto( SETTINGS );
		await openSection( page, 'sprak' );
		await page.locator( '#aat_language' ).selectOption( 'en' );
		await expect( page.locator( '#aat-summary-sprak' ) ).toHaveText( 'Engelska' );
		await page.locator( '#aat_language' ).selectOption( 'sv' );
		await expect( page.locator( '#aat-summary-sprak' ) ).toHaveText( 'Svenska' );

		await openSection( page, 'cache' );
		await page.locator( '#cache_duration' ).fill( '1' );
		await expect( page.locator( '#aat-summary-cache' ) ).toHaveText( /^1 dag( · |$)/ );
		await page.locator( '#cache_duration' ).fill( '7' );
		await expect( page.locator( '#aat-summary-cache' ) ).toHaveText( /^7 dagar( · |$)/ );

		await openSection( page, 'feedback' );
		const on = await page.locator( '#enable_feedback' ).isChecked();
		await page.locator( 'label.switch:has(#enable_feedback)' ).click();
		await expect( page.locator( '#aat-summary-feedback' ) ).toHaveText( on ? 'Av' : 'På' );
	} );

	test( 'brand names appear literally in the summary', async ( { page } ) => {
		await page.goto( SETTINGS );
		await openSection( page, 'namn' );
		await page.locator( '#naming' ).selectOption( 'product_brand' );
		await page.locator( '#aat_brand_name' ).fill( '<b>R</b> "AB"' );

		await expect( page.locator( '#aat-summary-namn' ) ).toHaveText( 'Produkt- och varumärkesnamn · <b>R</b> "AB"' );
		await expect( page.locator( '#aat-summary-namn b' ) ).toHaveCount( 0 );

		// The brand is only part of the summary at the level that uses it.
		await page.locator( '#naming' ).selectOption( 'product' );
		await expect( page.locator( '#aat-summary-namn' ) ).toHaveText( 'Produktnamn' );
	} );

	test( 'an invalid field in a hidden section is revealed', async ( { page } ) => {
		await page.goto( SETTINGS );
		await openSection( page, 'cache' );
		await page.locator( '#cache_duration' ).fill( '0' );
		await openSection( page, 'sprak' );
		await expect( page.locator( '#aat-section-cache' ) ).toBeHidden();

		const posts = recordPosts( page );
		await page.locator( '#submit' ).click();

		await expect( page.locator( '#aat-section-cache' ) ).toBeVisible();
		await expect( page.locator( '#aat-tab-cache' ) ).toHaveAttribute( 'aria-selected', 'true' );
		await expect( page.locator( '#aat-section-sprak' ) ).toBeHidden();
		await expect( page.locator( '#cache_duration' ) ).toBeFocused();
		expect( posts ).toEqual( [] );
	} );

	test( 'Töm cache is held back while there are unsaved changes, then works', async ( { page } ) => {
		await page.goto( SETTINGS );
		await openSection( page, 'sprak' );
		const language = await page.locator( '#aat_language' ).inputValue();
		await page.locator( '#aat_language' ).selectOption( language === 'sv' ? 'en' : 'sv' );
		await openSection( page, 'cache' );

		const before = page.url();
		const posts = recordPosts( page );
		await page.getByRole( 'button', { name: 'Töm cache' } ).click();

		const note = page.locator( '#aat-section-cache .aat-dirty-note' );
		await expect( note ).toBeVisible();
		await expect( note ).toHaveAttribute( 'role', 'alert' );
		await expect( note ).toHaveText( 'Spara dina ändringar först – annars försvinner de.' );
		expect( page.url() ).toBe( before );
		expect( posts ).toEqual( [] );

		// A second click keeps one note.
		await page.getByRole( 'button', { name: 'Töm cache' } ).click();
		await expect( page.locator( '.aat-dirty-note' ) ).toHaveCount( 1 );

		await page.reload();
		await expect( page.locator( '#aat-section-cache' ) ).toBeVisible();
		await page.getByRole( 'button', { name: 'Töm cache' } ).click();
		await expect( page.getByText( 'Cachen är tömd.' ) ).toBeVisible();
		expect( page.url() ).toContain( 'section=cache' );
		// Core's admin canonical URL drops the one-time flag from the address bar, and a tab switch does not bring it back.
		expect( page.url() ).not.toContain( 'aat_notice' );
		await expect( page.locator( '#aat-section-cache' ) ).toBeVisible();
		await openSection( page, 'sprak' );
		expect( page.url() ).not.toContain( 'aat_notice' );

		await expectNoCacheNoticeAfterSave( page );
		expect( page.url() ).toContain( 'section=sprak' );
		await expect( page.locator( '#aat-section-sprak' ) ).toBeVisible();
	} );

	test( 'Kontrollera nu shows a working key', async ( { page } ) => {
		await checkKeyFromUnchecked( page );
		expect( page.url() ).toContain( 'section=anslutning' );
		await expect( page.locator( '#aat-summary-anslutning' ) ).toContainText( '✓ Nyckel' );
	} );

	test( 'the strip link and other in-page links open their section', async ( { page } ) => {
		await page.goto( SETTINGS );
		await openSection( page, 'cache' );
		// The strip's "Till Anslutning" link shows only without a key, and the tests site always has one.
		await page.evaluate( () => {
			const link = document.createElement( 'a' );
			link.href = '#aat-section-anslutning';
			link.id = 'aat-e2e-link';
			link.textContent = 'Till Anslutning';
			document.querySelector( '.aat-settings-strip' ).append( link );
		} );

		await page.locator( '#aat-e2e-link' ).click();
		await expect( page.locator( '#aat-section-anslutning' ) ).toBeVisible();
		await expect( page.locator( '#aat-tab-anslutning' ) ).toHaveAttribute( 'aria-selected', 'true' );
		await expect( page.locator( '#aat-tab-anslutning' ) ).toBeFocused();
		await expect( page.locator( '#aat-section-cache' ) ).toBeHidden();
	} );

	test( 'wide screens put the sidebar beside the panel', async ( { page } ) => {
		await page.setViewportSize( { width: 1280, height: 900 } );
		await page.goto( SETTINGS );
		const nav = await page.locator( '.aat-settings-nav' ).boundingBox();
		const form = await page.locator( '#aat-settings-form' ).boundingBox();
		expect( nav.x + nav.width ).toBeLessThanOrEqual( form.x );
		expect( Math.abs( nav.y - form.y ) ).toBeLessThan( 2 );
	} );

	test( 'narrow screens stack the sidebar above the panel', async ( { page } ) => {
		await page.setViewportSize( { width: 600, height: 900 } );
		await page.goto( SETTINGS );
		const nav = await page.locator( '.aat-settings-nav' ).boundingBox();
		const form = await page.locator( '#aat-settings-form' ).boundingBox();
		expect( nav.y + nav.height ).toBeLessThanOrEqual( form.y );

		for ( const slug of SLUGS ) {
			await openSection( page, slug );
		}
		// No sideways scrolling at this width.
		expect( await page.evaluate( () => document.documentElement.scrollWidth ) ).toBeLessThanOrEqual( 600 );
	} );

	test.describe( 'without JavaScript', () => {
		test.use( { javaScriptEnabled: false } );

		test( 'every section shows, stacked, as plain in-page links', async ( { page } ) => {
			await page.goto( SETTINGS );
			for ( const slug of SLUGS ) {
				await expect( page.locator( '#aat-section-' + slug ) ).toBeVisible();
			}
			await expect( page.locator( '[role="tablist"], [role="tab"], [role="tabpanel"]' ) ).toHaveCount( 0 );
			await expect( page.locator( '#aat-tab-cache' ) ).toHaveAttribute( 'href', '#aat-section-cache' );
		} );

		test( 'saving, Töm cache and Kontrollera nu still work', async ( { page } ) => {
			await page.goto( SETTINGS + '&section=sprak' );
			await save( page );
			expect( page.url() ).toContain( 'section=sprak' );

			await page.getByRole( 'button', { name: 'Töm cache' } ).click();
			await expect( page.getByText( 'Cachen är tömd.' ) ).toBeVisible();
			// Without JS the flag stays in the URL and the form's referer; the save's page still must not repeat it.
			await expectNoCacheNoticeAfterSave( page );

			await checkKeyFromUnchecked( page );
		} );
	} );
} );
