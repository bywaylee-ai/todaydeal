<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Idempotency-Key handling (spec WP-LST-014 / WP-APPT-007).
 */
class TD_Idempotency {

	/**
	 * @return array|null previously stored response (decoded), or null if this is a new key.
	 */
	public static function find( $key, $operation, $user_id ) {
		if ( empty( $key ) ) {
			return null;
		}

		global $wpdb;
		$hash = hash( 'sha256', $key );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT response_ref FROM " . TD_DB::idempotency() . " WHERE key_hash = %s AND operation = %s AND user_id = %d AND expires_at > %s",
				$hash,
				$operation,
				$user_id,
				current_time( 'mysql', true )
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return json_decode( $row['response_ref'], true );
	}

	public static function store( $key, $operation, $user_id, $response_data, $ttl_seconds = 86400 ) {
		if ( empty( $key ) ) {
			return;
		}

		global $wpdb;
		$hash = hash( 'sha256', $key );

		// Best-effort cache write; a duplicate-key race just means another
		// concurrent request already stored the canonical response first.
		$wpdb->insert(
			TD_DB::idempotency(),
			array(
				'key_hash'     => $hash,
				'user_id'      => $user_id,
				'operation'    => $operation,
				'response_ref' => wp_json_encode( $response_data, JSON_UNESCAPED_UNICODE ),
				'created_at'   => current_time( 'mysql', true ),
				'expires_at'   => gmdate( 'Y-m-d H:i:s', time() + $ttl_seconds ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	public static function key_from_request( WP_REST_Request $request ) {
		$key = $request->get_header( 'Idempotency-Key' );
		return $key ? sanitize_text_field( $key ) : '';
	}
}
