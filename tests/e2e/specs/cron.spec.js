const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );
const { respText, wpCli, getOption, restoreOption, provenance } = require( '../utils/wp-cli' );

const RESP_TEXT = respText();
const SAMPLE = path.resolve( __dirname, '../assets/sample.jpg' );

/**
 * Makes the recurring queue tick due now. A one-off `cron event schedule … now`
 * cannot: wp_schedule_single_event() refuses an event within ten minutes of an
 * identical one, and the minutely event always exists. An earlier `cron event
 * run` (bulk.spec.js) also moves it up to a minute ahead. No spaces: wp-env
 * splits arguments on them.
 */
function makeTickDue() {
	wpCli( [ 'eval', "wp_unschedule_hook('aat_queue_tick');wp_schedule_event(time()-1,'aat_minutely','aat_queue_tick');" ] );
}

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
		// The recurring tick stays scheduled as the plugin schedules it, and no cron lock is left behind.
		wpCli( [ 'eval', "wp_unschedule_hook('aat_queue_tick');wp_schedule_event(time(),'aat_minutely','aat_queue_tick');" ] );
		try {
			wpCli( [ 'transient', 'delete', 'doing_cron' ] );
		} catch ( e ) {
			// No lock set.
		}
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

		let alt = '';
		for ( let i = 0; i < 6 && ! alt; i++ ) {
			makeTickDue();
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
