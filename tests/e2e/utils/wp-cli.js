const { execFileSync } = require( 'node:child_process' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

/**
 * Runs a WP-CLI command in the wp-env tests container and returns stdout, trimmed.
 * wp-env splits arguments on spaces, so no argument may contain one.
 *
 * @param {string[]} args WP-CLI arguments, e.g. [ 'option', 'get', 'blogname' ].
 * @return {string} Trimmed stdout. Throws when the command exits non-zero.
 */
function wpCli( args ) {
	for ( const arg of args ) {
		if ( /\s/.test( arg ) ) {
			throw new Error( `wp-env run splits arguments on spaces: "${ arg }"` );
		}
	}
	return execFileSync( 'npx', [ 'wp-env', 'run', 'tests-cli', 'wp', ...args ], {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
		cwd: path.resolve( __dirname, '../../..' ),
	} ).trim();
}

/** An option's value, or null when it does not exist. */
function getOption( name ) {
	try {
		return wpCli( [ 'option', 'get', name ] );
	} catch ( e ) {
		return null;
	}
}

/** Restores an option saved with getOption(): null deletes it. */
function restoreOption( name, value ) {
	if ( value === null ) {
		try {
			wpCli( [ 'option', 'delete', name ] );
		} catch ( e ) {
			// Already absent.
		}
	} else {
		wpCli( [ 'option', 'update', name, value ] );
	}
}

/** The text of the recorded completed response, read from the fixture. */
function respText() {
	const file = path.resolve( __dirname, '../../fixtures/openai/responses-completed.json' );
	const fixture = JSON.parse( fs.readFileSync( file, 'utf8' ) );
	const message = fixture.body.output.find( ( item ) => item.type === 'message' );
	return message.content.find( ( part ) => part.type === 'output_text' ).text;
}

module.exports = { wpCli, getOption, restoreOption, respText };
