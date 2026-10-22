const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );
const { respText, wpCli, getOption, restoreOption, provenance } = require( '../utils/wp-cli' );

const RESP_TEXT = respText();
const SAMPLE = path.resolve( __dirname, '../assets/sample.jpg' );

// WP-Cron does not load wp-admin/includes, so this goes through a real wp-cron.php request.
test.describe( 'wp-cron', () => {
	test.setTimeout( 90000 );
	let requestUtils;
	let id = null;
	let savedAutoGenerate;

	test.beforeAll( async ( { baseURL } ) => {
		savedAutoGenerate = getOption( 'auto_alt_text_auto_generate' );
		wpCli( [ 'option', 'update', 'auto_alt_text_auto_generate', '0' ] );
		requestUtils = await RequestUtils.setup( {
			baseURL,
			storageStatePath: 'artifacts/storage-states/admin.json',
		} );
	} );

	test.afterAll( async () => {
		restoreOption( 'auto_alt_text_auto_generate', savedAutoGenerate );
		if ( id ) {
			await requestUtils.rest( { method: 'DELETE', path: `/wp/v2/media/${ id }`, params: { force: true } } );
		}
		try {
			wpCli( [ 'user', 'meta', 'delete', '1', 'aat_bulk' ] );
		} catch ( e ) {
			// Nothing tracked.
		}
	} );

	test( 'queue jobs are processed by a real wp-cron.php request', async ( { request } ) => {
		id = ( await requestUtils.uploadMedia( SAMPLE ) ).id;
		const altOf = async () => ( await requestUtils.rest( { path: `/wp/v2/media/${ id }` } ) ).alt_text;
		expect( await altOf() ).toBe( '' );

		await requestUtils.rest( { method: 'POST', path: '/wp-auto-alt-text/v1/bulk', data: { attachment_ids: [ id ] } } );
		expect( await altOf() ).toBe( '' );

		// A one-off tick due now; recurring ones would pile up across runs.
		try {
			wpCli( [ 'cron', 'event', 'schedule', 'aat_queue_tick', 'now' ] );
		} catch ( e ) {
			// A tick is already due.
		}

		let alt = '';
		for ( let i = 0; i < 6 && ! alt; i++ ) {
			// Mirrors spawn_cron(): the lock is set first and the request carries its key. The wp-env
			// container cannot loop back to itself, so a stale lock from an earlier spawn would
			// otherwise make wp-cron.php exit without running anything.
			const key = `${ Math.floor( Date.now() / 1000 ) }.${ i }`;
			wpCli( [ 'transient', 'set', 'doing_cron', key ] );
			const res = await request.get( `/wp-cron.php?doing_wp_cron=${ key }` );
			expect( res.status() ).toBe( 200 );
			for ( let j = 0; j < 5 && ! alt; j++ ) {
				alt = await altOf();
				if ( ! alt ) {
					await new Promise( ( resolve ) => setTimeout( resolve, 500 ) );
				}
			}
		}

		expect( alt ).toBe( RESP_TEXT );
		expect( provenance( id ).source ).toBe( 'batch' );
	} );
} );
