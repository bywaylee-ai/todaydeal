<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TD_REST_Appointments_Controller {

	const NS = 'todaydeal/v1';

	public function register_routes() {
		register_rest_route( self::NS, '/appointments', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'create' ),
			'permission_callback' => array( $this, 'require_login' ),
		) );

		register_rest_route( self::NS, '/appointments/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_one' ),
				'permission_callback' => array( $this, 'require_login' ),
			),
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'respond' ),
				'permission_callback' => array( $this, 'require_login' ),
			),
			'args' => array( 'id' => array( 'validate_callback' => fn( $param ) => is_numeric( $param ) ) ),
		) );
	}

	public function require_login() {
		return is_user_logged_in() ? true : TD_Response::error( 'AUTHENTICATION_REQUIRED', '로그인이 필요합니다.' );
	}

	public function create( WP_REST_Request $request ) {
		$limited = TD_Rate_Limiter::check( 'appointment_create', get_current_user_id(), 30, 3600 );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$result = TD_Appointments::create( get_current_user_id(), (array) $request->get_json_params(), TD_Idempotency::key_from_request( $request ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return TD_Response::success( $result );
	}

	public function get_one( WP_REST_Request $request ) {
		$result = TD_Appointments::get( (int) $request['id'], get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return TD_Response::success( $result );
	}

	public function respond( WP_REST_Request $request ) {
		$result = TD_Appointments::respond( (int) $request['id'], get_current_user_id(), (array) $request->get_json_params() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return TD_Response::success( $result );
	}
}
