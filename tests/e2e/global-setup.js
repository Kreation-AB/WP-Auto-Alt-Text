const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );

// Logs in as the wp-env admin and writes the storage state the specs reuse.
module.exports = async ( config ) => {
	const { baseURL, storageState } = config.projects[ 0 ].use;
	const requestUtils = await RequestUtils.setup( { baseURL, storageStatePath: storageState } );
	await requestUtils.setupRest();
};
