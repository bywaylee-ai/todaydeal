<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Structured audit log (spec section 21 #11, 23).
 * Never logs passwords, tokens, cookies, or full request bodies.
 */
class TD_Audit_Log {

	public static function record( $action, $object_type, $object_id, $result = 'success', $details = array(), $actor_id = null ) {
		global $wpdb;

		if ( null === $actor_id ) {
			$actor_id = get_current_user_id() ?: null;
		}

		$wpdb->insert(
			TD_DB::audit_log(),
			array(
				'actor_id'    => $actor_id,
				'action'      => $action,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'request_id'  => TD_Response::current_request_id(),
				'result'      => $result,
				'details'     => ! empty( $details ) ? wp_json_encode( $details, JSON_UNESCAPED_UNICODE ) : null,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}
}
