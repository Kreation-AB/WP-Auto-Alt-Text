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

/**
 * An option's value, or null when it does not exist. Any other failure (for
 * example a stopped container) is rethrown so state is never lost silently.
 */
function getOption( name ) {
	try {
		return wpCli( [ 'option', 'get', name ] );
	} catch ( e ) {
		if ( ! String( e.stderr || '' ).includes( 'Could not get' ) ) {
			throw e;
		}
		return null;
	}
}

/**
 * Restores an option saved with getOption(): null deletes it. An empty string
 * also deletes it, because wp-env run drops empty arguments; that is only
 * right for options whose absence reads the same as '' (the model option).
 */
function restoreOption( name, value ) {
	if ( value === null || value === '' ) {
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

/** The decoded _aat_alt_provenance meta of an attachment, or null when unset. */
function provenance( id ) {
	try {
		return JSON.parse( wpCli( [ 'post', 'meta', 'get', String( id ), '_aat_alt_provenance', '--format=json' ] ) );
	} catch ( e ) {
		return null;
	}
}

module.exports = { provenance, wpCli, getOption, restoreOption, respText };
