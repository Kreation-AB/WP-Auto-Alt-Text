const fs = require( 'node:fs' );
const path = require( 'node:path' );
const AxeBuilder = require( '@axe-core/playwright' ).default;
const { expect } = require( '@playwright/test' );

const ALLOWLIST = path.resolve( __dirname, '../a11y-allowlist.json' );
const TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];
const BLOCKING = [ 'serious', 'critical' ];

/**
 * Turns axe's `target` (an array of selectors, one per frame/shadow root) into
 * the string stored in the allowlist: the parts are joined with a single space,
 * then digit runs inside `#id` tokens and `[attr="value"]` selectors become
 * `<id>`, so per-run values such as attachment IDs (#attachments-123-x) match
 * on every run. Nothing else is normalized.
 *
 * @param {string[]} target axe node target.
 * @return {string} Stable target string.
 */
function normalizeTarget( target ) {
	return target
		.join( ' ' )
		.replace( /#[\w-]+/g, ( token ) => token.replace( /\d+/g, '<id>' ) )
		.replace( /\[[^\]]*\]/g, ( token ) => token.replace( /\d+/g, '<id>' ) );
}

/**
 * Runs axe on the included part of the page and fails on serious or critical
 * violations that are not in tests/e2e/a11y-allowlist.json for `pageName`.
 *
 * @param {import('@playwright/test').Page} page     Page under test.
 * @param {Object}                          options
 * @param {string}                          options.pageName Allowlist page key.
 * @param {string|string[]}                 options.include  Selector(s) to scan.
 */
async function checkA11y( page, { pageName, include } ) {
	const allowlist = JSON.parse( fs.readFileSync( ALLOWLIST, 'utf8' ) ).filter( ( e ) => e.page === pageName );
	const includes = Array.isArray( include ) ? include : [ include ];

	// Let fade-ins and transitions finish: axe reads colours mid-animation, so
	// color-contrast results would otherwise differ from run to run.
	await page.evaluate( () => Promise.all( document.getAnimations().map( ( a ) => a.finished.catch( () => {} ) ) ) );

	let builder = new AxeBuilder( { page } ).withTags( TAGS );
	for ( const selector of includes ) {
		builder = builder.include( selector );
	}
	const { violations } = await builder.analyze();

	const found = [];
	for ( const violation of violations ) {
		if ( ! BLOCKING.includes( violation.impact ) ) {
			continue;
		}
		for ( const node of violation.nodes ) {
			const target = normalizeTarget( node.target );
			if ( ! allowlist.some( ( e ) => e.rule === violation.id && e.target === target ) ) {
				found.push( `${ violation.id } (${ violation.impact }) ${ target }\n  ${ violation.helpUrl }` );
			}
		}
	}

	expect( found, `New accessibility violations on "${ pageName }":\n${ found.join( '\n' ) }` ).toEqual( [] );
}

module.exports = { checkA11y, normalizeTarget };
