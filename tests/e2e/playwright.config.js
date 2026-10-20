const { defineConfig, devices } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: 'specs',
	globalSetup: './global-setup.js',
	outputDir: '../../test-results',
	retries: 0, // Flaky tests are fixed, not retried.
	workers: 1,
	reporter: [ [ 'list' ], [ 'html', { open: 'never', outputFolder: '../../playwright-report' } ] ],
	use: {
		...devices[ 'Desktop Chrome' ],
		baseURL: 'http://localhost:8889',
		storageState: 'artifacts/storage-states/admin.json',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
} );
