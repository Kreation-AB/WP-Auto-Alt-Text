<?php
/*
 * Redaction for recorded OpenAI fixtures. Plain PHP with no WordPress and no
 * ABSPATH guard: bin/record-openai-fixtures.php loads it outside WordPress.
 */

/**
 * Strips secrets and identifiers from a fixture: sensitive headers, response
 * and request ids, and anything that looks like an API key.
 *
 * @param array $fixture A fixture in the tests/fixtures/openai format.
 * @return array
 */
function aat_redact_openai_fixture( array $fixture ) {
	$drop = array( 'authorization', 'openai-organization', 'openai-project', 'x-request-id', 'set-cookie', 'cf-ray' );

	if ( isset( $fixture['headers'] ) && is_array( $fixture['headers'] ) ) {
		$headers = array();
		foreach ( $fixture['headers'] as $name => $value ) {
			if ( ! in_array( strtolower( (string) $name ), $drop, true ) ) {
				$headers[ $name ] = $value;
			}
		}
		$fixture['headers'] = $headers;
	}

	return aat_redact_openai_node( $fixture );
}

/**
 * @param mixed $node
 * @return mixed
 */
function aat_redact_openai_node( $node ) {
	if ( is_string( $node ) ) {
		return preg_replace( '/sk-[A-Za-z0-9_-]{10,}/', 'sk-redacted', $node );
	}
	if ( ! is_array( $node ) ) {
		return $node;
	}
	foreach ( $node as $key => $value ) {
		if ( 'id' === $key && is_string( $value ) && preg_match( '/^(resp|msg|rs|req|chatcmpl)_/', $value, $m ) ) {
			$node[ $key ] = $m[1] . '_redacted';
			continue;
		}
		$node[ $key ] = aat_redact_openai_node( $value );
	}
	return $node;
}
