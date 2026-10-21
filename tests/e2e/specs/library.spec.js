const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );
const { wpCli, getOption, restoreOption, provenance } = require( '../utils/wp-cli' );

const SAMPLE = path.resolve( __dirname, '../assets/sample.jpg' );
const LIBRARY = '/wp-admin/admin.php?page=auto-alt-text-library';
const LIBRARY_REST = '/wp-auto-alt-text/v1/library';
const BASELINE_ALT = 'e2e-baseline';
const FINAL = [ 'finished', 'cancelled' ];

// wp post meta get exits non-zero while the meta is missing; that reads as empty.
function altOf( id ) {
	try {
		return wpCli( [ 'post', 'meta', 'get', String( id ), '_wp_attachment_image_alt' ] );
	} catch ( e ) {
		return '';
	}
}

test.describe( 'library backfill', () => {
	// Each wpCli call takes a few seconds and the tests make dozens.
	test.describe.configure( { timeout: 180000 } );

	let requestUtils;
	let ids = [];
	let baselineIds = [];
	let savedAutoGenerate;

	// Auto-generate off: uploads must not queue their own jobs, only the run does.
	test.beforeAll( async ( { baseURL } ) => {
		savedAutoGenerate = getOption( 'auto_alt_text_auto_generate' );
		wpCli( [ 'option', 'update', 'auto_alt_text_auto_generate', '0' ] );
		requestUtils = await RequestUtils.setup( {
			baseURL,
			storageStatePath: 'artifacts/storage-states/admin.json',
		} );
	} );

	test.afterAll( () => {
		restoreOption( 'auto_alt_text_auto_generate', savedAutoGenerate );
	} );

	test.afterEach( async () => {
		// Runs cannot be deleted; cancel an active one so the next test can start its own.
		try {
			const state = await getState();
			if ( state.run && ! FINAL.includes( state.run.display_state ) ) {
				await requestUtils.rest( { method: 'POST', path: `${ LIBRARY_REST }/cancel` } );
			}
		} catch ( e ) {
			console.warn( `library spec cleanup: could not cancel the active run: ${ e.message }` );
		}
		restoreOption( 'aat_e2e_queue_limits', null );
		for ( const id of baselineIds ) {
			try {
				wpCli( [ 'post', 'meta', 'delete', String( id ), '_wp_attachment_image_alt' ] );
			} catch ( e ) {
				console.warn( `library spec cleanup: could not remove the temporary alt text of ${ id }: ${ e.message }` );
			}
		}
		baselineIds = [];
		for ( const id of ids ) {
			await requestUtils.rest( { method: 'DELETE', path: `/wp/v2/media/${ id }`, params: { force: true } } );
		}
		ids = [];
	} );

	function getState() {
		return requestUtils.rest( { path: LIBRARY_REST } );
	}

	// Drives the background recount until the cached counts satisfy `done` (at most 10 runs).
	async function recount( done = () => true ) {
		let counts;
		// Schedules the recount and resets its cursor; uploads do not schedule it.
		await requestUtils.rest( { method: 'POST', path: `${ LIBRARY_REST }/recount` } );
		for ( let i = 0; i < 10; i++ ) {
			try {
				wpCli( [ 'cron', 'event', 'run', 'aat_library_recount' ] );
			} catch ( e ) {
				// WP-Cron already ran it, so the event is gone: just read the counts.
				if ( ! String( e.stderr || e.message ).includes( 'Invalid cron event' ) ) {
					throw e;
				}
			}
			counts = ( await getState() ).counts;
			if ( counts.complete && ! counts.recounting && done( counts ) ) {
				return counts;
			}
		}
		throw new Error( `Recount did not settle: ${ JSON.stringify( counts ) }` );
	}

	async function uploadThree() {
		for ( let i = 0; i < 3; i++ ) {
			const media = await requestUtils.uploadMedia( SAMPLE );
			ids.push( media.id );
		}
	}

	// Baseline first: the site may already hold images, so no literal count is expected.
	async function prepare() {
		const baseline = ( await recount() ).missing;
		await uploadThree();
		const counts = await recount( ( c ) => c.missing === baseline + 3 );
		return counts.missing;
	}

	async function startFromPage( page, missing ) {
		await page.goto( LIBRARY );
		await expect( page.locator( '#aat-library-counts [data-count="missing"]' ) ).toHaveText( String( missing ) );
		// Starta stays disabled until the first fetch has rendered complete counts.
		await expect( page.locator( '#aat-library-start' ) ).toBeEnabled();
		await page.locator( '#aat-library-start' ).click();
		await expect( page.locator( '#aat-library-dialog' ) ).toBeVisible();
		await page.locator( '#aat-library-dialog-confirm' ).click();
		await expect( page.locator( '#aat-library-progress' ) ).toBeVisible();
	}

	test( 'starts a run, shows progress, finishes, and offers the report', async ( { page } ) => {
		const missing = await prepare();
		await startFromPage( page, missing );
		expect( ( await getState() ).run.total ).toBe( missing );

		// Baseline images are processed too, so the bound is generous.
		let state;
		for ( let i = 0; i < 15; i++ ) {
			wpCli( [ 'cron', 'event', 'run', 'aat_queue_tick' ] );
			state = await getState();
			if ( state.run.display_state === 'finished' ) {
				break;
			}
		}
		expect( state.run.display_state ).toBe( 'finished' );
		const filled = state.run.progress.filled;
		expect( state.run.progress.failed ).toBe( 0 );
		expect( filled ).toBe( missing );

		await page.reload();
		await expect( page.locator( '#aat-library-sum-filled' ) ).toHaveText( String( filled ) );
		const report = page.locator( '#aat-library-report' );
		await expect( report ).toBeVisible();
		const href = await report.getAttribute( 'href' );
		expect( href ).toContain( 'action=aat_library_report' );

		// page.request carries the admin cookies.
		const response = await page.request.get( href );
		expect( response.status() ).toBe( 200 );
		const disposition = response.headers()[ 'content-disposition' ];
		expect( disposition ).toContain( 'alt-text-rapport-' );
		expect( disposition ).toContain( `run${ state.run.id }` );
		expect( await response.text() ).toContain( `Körnings-ID: ${ state.run.id }` );
	} );

	test( 'cancel keeps written alt texts and stops the run', async ( { page } ) => {
		// Give every existing image alt text first, so the one image a tick fills is an upload.
		const existing = wpCli( [ 'post', 'list', '--post_type=attachment', '--post_mime_type=image', '--format=ids' ] )
			.split( /\s+/ )
			.filter( Boolean );
		for ( const id of existing ) {
			if ( altOf( id ) === '' ) {
				baselineIds.push( id );
				wpCli( [ 'post', 'meta', 'update', String( id ), '_wp_attachment_image_alt', BASELINE_ALT ] );
			}
		}
		const missing = await prepare();
		expect( missing ).toBe( 3 );
		// The test mu-plugin then feeds and fills one image per tick.
		wpCli( [ 'option', 'update', 'aat_e2e_queue_limits', '1' ] );
		await startFromPage( page, missing );

		let mid;
		for ( let i = 0; i < 5; i++ ) {
			wpCli( [ 'cron', 'event', 'run', 'aat_queue_tick' ] );
			mid = await getState();
			if ( mid.run.progress.filled >= 1 ) {
				break;
			}
		}
		expect( mid.run.display_state ).toBe( 'running' );
		expect( mid.run.progress.filled ).toBe( 1 );

		await page.locator( '#aat-library-cancel' ).click();
		await expect( page.locator( '#aat-library-cancel-dialog' ) ).toBeVisible();
		await page.locator( '#aat-library-cancel-dialog-confirm' ).click();
		await expect( page.locator( '#aat-library-cancel-dialog' ) ).toBeHidden();

		await expect.poll( async () => ( await getState() ).run.display_state ).toBe( 'cancelled' );

		// Feed walks IDs in ascending order: the lowest upload was filled and keeps its text.
		const sorted = [ ...ids ].sort( ( a, b ) => a - b );
		const alts = {};
		for ( const id of sorted ) {
			alts[ id ] = ( await requestUtils.rest( { path: `/wp/v2/media/${ id }` } ) ).alt_text;
		}
		expect( alts[ sorted[ 0 ] ] ).not.toBe( '' );
		expect( provenance( sorted[ 0 ] ).source ).toBe( 'library' );
		expect( alts[ sorted[ 1 ] ] ).toBe( '' );
		expect( alts[ sorted[ 2 ] ] ).toBe( '' );
		expect( ( await getState() ).run.progress.filled ).toBe( 1 );
	} );
} );
