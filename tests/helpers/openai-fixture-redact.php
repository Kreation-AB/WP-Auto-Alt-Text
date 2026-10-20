<?php
/*
 * Redaction and validation for recorded OpenAI fixtures. Plain PHP with no
 * WordPress and no ABSPATH guard: bin/record-openai-fixtures.php loads it
 * outside WordPress.
 */

/**
 * Strips secrets and identifiers from a fixture: every header outside an
 * allowlist, response and request ids, key-like strings, organisation, project
 * and user ids, and non-OpenAI entries of a models list.
 *
 * @param array $fixture A fixture in the tests/fixtures/openai format.
 * @return array
 */
function aat_redact_openai_fixture( array $fixture ) {
	if ( isset( $fixture['headers'] ) && is_array( $fixture['headers'] ) ) {
		$headers = array();
		foreach ( $fixture['headers'] as $name => $value ) {
			$lower = strtolower( (string) $name );
			if ( in_array( $lower, array( 'content-type', 'retry-after', 'openai-processing-ms', 'openai-version' ), true ) || 0 === strpos( $lower, 'x-ratelimit-' ) ) {
				$headers[ $name ] = $value;
			}
		}
		$fixture['headers'] = $headers;
	}

	$body = $fixture['body'] ?? null;
	if ( is_array( $body ) && 'list' === ( $body['object'] ?? null ) && is_array( $body['data'] ?? null ) ) {
		$data = array();
		foreach ( $body['data'] as $entry ) {
			$owner = is_array( $entry ) ? ( $entry['owned_by'] ?? null ) : null;
			$id    = is_array( $entry ) ? (string) ( $entry['id'] ?? '' ) : '';
			if ( in_array( $owner, array( 'system', 'openai', 'openai-internal' ), true ) && 0 !== strpos( $id, 'ft:' ) ) {
				$data[] = $entry;
			}
		}
		$fixture['body']['data'] = $data;
	}

	return aat_redact_openai_node( $fixture );
}

/**
 * @param mixed $node
 * @return mixed
 */
function aat_redact_openai_node( $node ) {
	if ( is_string( $node ) ) {
		$node = preg_replace( '/(?<![A-Za-z0-9])sk-[A-Za-z0-9_*.-]{4,}/', 'sk-redacted', $node );
		$node = preg_replace( '/(?<![A-Za-z0-9])org-[A-Za-z0-9]+/', 'org-redacted', (string) $node );
		$node = preg_replace( '/(?<![A-Za-z0-9])proj_[A-Za-z0-9]+/', 'proj_redacted', (string) $node );
		return preg_replace( '/(?<![A-Za-z0-9])user-[A-Za-z0-9]+/', 'user-redacted', (string) $node );
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

/**
 * Checks a recording before it may replace a committed fixture.
 *
 * @param string $name    Recording name, e.g. responses-completed.
 * @param array  $fixture The (redacted) fixture.
 * @return string|null An error that never includes the body, or null when valid.
 */
function aat_validate_recorded_fixture( string $name, array $fixture ) {
	$expected = array(
		'models-list'                     => 200,
		'model-get'                       => 200,
		'model-not-found'                 => 404,
		'responses-completed'             => 200,
		'responses-incomplete-max-tokens' => 200,
		'unauthorized'                    => 401,
	);
	if ( ! isset( $expected[ $name ] ) ) {
		return 'unknown recording';
	}
	$status = (int) ( $fixture['status'] ?? 0 );
	if ( $status !== $expected[ $name ] ) {
		return sprintf( 'expected HTTP %d, got %d', $expected[ $name ], $status );
	}
	$body = $fixture['body'] ?? null;
	if ( ! is_array( $body ) ) {
		return 'the body is not JSON';
	}
	if ( 'responses-completed' === $name ) {
		if ( 'completed' !== ( $body['status'] ?? null ) ) {
			return 'the response status is not completed';
		}
		foreach ( (array) ( $body['output'] ?? array() ) as $item ) {
			foreach ( (array) ( is_array( $item ) ? ( $item['content'] ?? array() ) : array() ) as $part ) {
				if ( is_array( $part ) && 'output_text' === ( $part['type'] ?? null ) ) {
					return null;
				}
			}
		}
		return 'the response has no output_text item';
	}
	if ( 'responses-incomplete-max-tokens' === $name ) {
		if ( 'incomplete' !== ( $body['status'] ?? null ) ) {
			return 'the response status is not incomplete';
		}
		if ( 'max_output_tokens' !== ( $body['incomplete_details']['reason'] ?? null ) ) {
			return 'the incomplete reason is not max_output_tokens';
		}
	}
	return null;
}
