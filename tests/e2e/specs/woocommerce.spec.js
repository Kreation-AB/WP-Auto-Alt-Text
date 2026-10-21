const { test, expect } = require( '@playwright/test' );
const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );
const { wpCli, getOption, restoreOption, provenance } = require( '../utils/wp-cli' );

// Inside the wp-env tests container the plugin's tests/ directory is mapped here.
const SAMPLE_IN_CONTAINER = '/var/www/html/wp-content/aat-e2e-tests/e2e/assets/sample.jpg';
const PRODUCT_NAME = 'Sandlåda Robust 350 L';

// wp post meta get exits non-zero while the meta is missing; that reads as empty.
function altOf( id ) {
	try {
		return wpCli( [ 'post', 'meta', 'get', String( id ), '_wp_attachment_image_alt' ] );
	} catch ( e ) {
		return '';
	}
}

test.describe( 'WooCommerce product save', () => {
	// Quarantined on CI: the alt text sometimes stays empty after the 10 queue
	// ticks (failed twice on the v2.0 push run of 2a6fe49, passed on its PR run).
	// Suspected cause: WP-Cron on the tests site runs aat_queue_tick concurrently
	// and reschedules the job. Follow-up: DISABLE_WP_CRON in .wp-env.json's tests
	// config, print the queue rows when the alt text stays empty, then remove
	// this skip. The spec still runs locally.
	test.skip( !! process.env.CI, 'Flaky on CI; see the comment above.' );

	let requestUtils;
	let savedNaming;
	let savedAutoGenerate;
	let productId;
	let attachmentId;

	test.beforeAll( async ( { baseURL } ) => {
		requestUtils = await RequestUtils.setup( {
			baseURL,
			storageStatePath: 'artifacts/storage-states/admin.json',
		} );
		savedNaming = getOption( 'auto_alt_text_naming' );
		wpCli( [ 'option', 'update', 'auto_alt_text_naming', 'product' ] );
		savedAutoGenerate = getOption( 'auto_alt_text_auto_generate' );
	} );

	test.afterEach( async () => {
		if ( productId ) {
			await requestUtils.rest( { method: 'DELETE', path: `/wc/v3/products/${ productId }`, params: { force: true } } );
		}
		if ( attachmentId ) {
			await requestUtils.rest( { method: 'DELETE', path: `/wp/v2/media/${ attachmentId }`, params: { force: true } } );
		}
		productId = undefined;
		attachmentId = undefined;
	} );

	test.afterAll( async () => {
		restoreOption( 'auto_alt_text_naming', savedNaming );
		restoreOption( 'auto_alt_text_auto_generate', savedAutoGenerate );
	} );

	test( 'a product save queues its image and the queue writes alt text naming the product', async ( { page } ) => {
		// Off during the import so no upload job exists; the product save must queue the image itself.
		wpCli( [ 'option', 'update', 'auto_alt_text_auto_generate', '0' ] );
		attachmentId = Number( wpCli( [ 'media', 'import', SAMPLE_IN_CONTAINER, '--porcelain' ] ) );
		const product = await requestUtils.rest( {
			method: 'POST',
			path: '/wc/v3/products',
			data: { name: PRODUCT_NAME, images: [ { id: attachmentId } ] },
		} );
		productId = product.id;
		// Both the save hook and the queue tick need auto-generate on.
		wpCli( [ 'option', 'update', 'auto_alt_text_auto_generate', '1' ] );
		expect( altOf( attachmentId ) ).toBe( '' );
		expect( provenance( attachmentId ) ).toBeNull();

		await page.goto( `/wp-admin/post.php?post=${ productId }&action=edit` );
		await page.locator( '#publish' ).click();
		await expect( page.locator( '#message.updated, .notice-success' ).first() ).toBeVisible();

		// Earlier upload jobs may sit ahead in the queue, so tick a bounded number of times.
		let alt = '';
		for ( let i = 0; i < 10 && ! alt; i++ ) {
			wpCli( [ 'cron', 'event', 'run', 'aat_queue_tick' ] );
			alt = altOf( attachmentId );
		}

		expect( alt ).toContain( PRODUCT_NAME );
		// The provenance source is the job's generation type: the product save queued it.
		expect( provenance( attachmentId ).source ).toBe( 'woocommerce' );
	} );
} );
