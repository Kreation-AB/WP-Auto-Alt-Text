const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );
const { wpCli, getOption, restoreOption, provenance } = require( '../utils/wp-cli' );

const SAMPLE = path.resolve( __dirname, '../assets/sample.jpg' );
const LIBRARY = '/wp-admin/admin.php?page=auto-alt-text-library';
const LIBRARY_REST = '/wp-auto-alt-text/v1/library';
const FINAL = [ 'finished', 'cancelled' ];

test.describe( 'library backfill', () => {
	let requestUtils;
	let ids = [];
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
			// No run to cancel.
		}
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
		for ( let i = 0; i < 10; i++ ) {
			wpCli( [ 'cron', 'event', 'run', 'aat_library_recount' ] );
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
		expect( filled ).toBeGreaterThanOrEqual( 3 );

		await page.reload();
		await expect( page.locator( '#aat-library-sum-filled' ) ).toHaveText( String( filled ) );
		const report = page.locator( '#aat-library-report' );
		await expect( report ).toBeVisible();
		const href = await report.getAttribute( 'href' );
		expect( href ).toContain( 'action=aat_library_report' );

		// page.request carries the admin cookies.
		const response = await page.request.get( href );
		expect( response.status() ).toBe( 200 );
		expect( response.headers()[ 'content-disposition' ] ).toContain( 'alt-text-rapport-' );
		const body = await response.text();
		expect( body.includes( 'run' ) || body.includes( String( state.run.id ) ) ).toBe( true );
	} );

	test( 'cancel keeps written alt texts and stops the run', async ( { page } ) => {
		const missing = await prepare();
		await startFromPage( page, missing );

		wpCli( [ 'cron', 'event', 'run', 'aat_queue_tick' ] );
		const mid = await getState();
		// A tick that already finished the whole run leaves nothing to cancel.
		test.skip( mid.run.display_state === 'finished', 'One tick finished the run before it could be cancelled.' );

		await page.locator( '#aat-library-cancel' ).click();
		await expect( page.locator( '#aat-library-cancel-dialog' ) ).toBeVisible();
		await page.locator( '#aat-library-cancel-dialog-confirm' ).click();
		await expect( page.locator( '#aat-library-cancel-dialog' ) ).toBeHidden();

		await expect.poll( async () => ( await getState() ).run.display_state ).toBe( 'cancelled' );

		// Whatever was filled before the cancel keeps its text and is recorded as a library write.
		for ( const id of ids ) {
			const alt = ( await requestUtils.rest( { path: `/wp/v2/media/${ id }` } ) ).alt_text;
			if ( alt !== '' ) {
				expect( provenance( id ).source ).toBe( 'library' );
			}
		}
	} );
} );
