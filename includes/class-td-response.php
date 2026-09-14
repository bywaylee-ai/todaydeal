<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Response envelope + error code registry (spec section 17).
 */
class TD_Response {

	const API_VERSION = '1';

	private static $request_id = null;

	public static function current_request_id() {
		if ( null === self::$request_id ) {
			self::$request_id = wp_generate_uuid4();
		}
		return self::$request_id;
	}

	public static function success( $data, $meta = array() ) {
		return new WP_REST_Response(
			array(
				'data' => $data,
				'meta' => array_merge(
					array(
						'api_version' => self::API_VERSION,
						'request_id'  => self::current_request_id(),
					),
					$meta
				),
			)
		);
	}

	public static function list( $items, $next_cursor = null, $meta = array() ) {
		return self::success(
			array(
				'items'       => $items,
				'next_cursor' => $next_cursor,
				'has_more'    => ! empty( $next_cursor ),
			),
			$meta
		);
	}

	/**
	 * error code => HTTP status map (spec 17장 표).
	 */
	private static function error_map() {
		return array(
			'VALIDATION_ERROR'              => 400,
			'INVALID_LISTING_TYPE_FIELD'    => 400,
			'CHECKLIST_REQUIRED_ITEM_MISSING' => 400,
			'AUTHENTICATION_REQUIRED'       => 401,
			'INVALID_CREDENTIALS'           => 401,
			'TOKEN_EXPIRED'                 => 401,
			'TOKEN_INVALID'                 => 401,
			'FORBIDDEN_LISTING_OWNER'       => 403,
			'APPOINTMENT_SELF_NOT_ALLOWED'  => 403,
			'MANAGER_NOT_ELIGIBLE'          => 403,
			'MANAGER_NOT_ASSIGNED'          => 403,
			'FORBIDDEN'                     => 403,
			'LISTING_NOT_FOUND'             => 404,
			'MEDIA_NOT_FOUND'               => 404,
			'APPOINTMENT_NOT_FOUND'         => 404,
			'USER_NOT_FOUND'                => 404,
			'NOT_FOUND'                     => 404,
			'DUPLICATE_REQUEST'             => 409,
			'VERSION_CONFLICT'              => 409,
			'LISTING_TYPE_IMMUTABLE'        => 409,
			'INVALID_STATUS_TRANSITION'     => 409,
			'APPOINTMENT_ALREADY_ACCEPTED'  => 409,
			'APPOINTMENT_DUPLICATE_PARTY'   => 409,
			'LISTING_LIMIT_EXCEEDED'        => 409,
			'MANAGER_ALREADY_ASSIGNED'      => 409,
			'MANAGER_CONSENT_REQUIRED'      => 409,
			'INVITATION_EXPIRED'            => 409,
			'CHECKLIST_ALREADY_SUBMITTED'   => 409,
			'CHECKLIST_REQUIRED'            => 409,
			'DISPUTE_ALREADY_OPEN'          => 409,
			'RATE_LIMITED'                  => 429,
			'INTERNAL_ERROR'                => 500,
			'TAXONOMY_UNAVAILABLE'          => 503,
		);
	}

	public static function status_for_code( $code ) {
		$map = self::error_map();
		return $map[ $code ] ?? 400;
	}

	public static function error( $code, $message, $details = array() ) {
		return new WP_Error(
			$code,
			$message,
			array(
				'status'  => self::status_for_code( $code ),
				'details' => $details,
			)
		);
	}
}

/**
 * Normalizes every WP_Error returned from a todaydeal/v1 route into the
 * documented { error: { code, message, details, request_id } } envelope.
 */
add_filter(
	'rest_request_after_callbacks',
	function ( $response, $handler, $request ) {
		if ( strpos( $request->get_route(), '/todaydeal/v1' ) !== 0 ) {
			return $response;
		}

		if ( is_wp_error( $response ) ) {
			$data      = $response->get_error_data();
			$status    = is_array( $data ) && isset( $data['status'] ) ? $data['status'] : 400;
			$details   = is_array( $data ) && isset( $data['details'] ) ? $data['details'] : array();

			return new WP_REST_Response(
				array(
					'error' => array(
						'code'       => $response->get_error_code(),
						'message'    => $response->get_error_message(),
						'details'    => $details,
						'request_id' => TD_Response::current_request_id(),
					),
				),
				$status
			);
		}

		return $response;
	},
	10,
	3
);
