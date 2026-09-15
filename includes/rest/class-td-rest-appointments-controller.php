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

		register_rest_route( self::NS, '/appointments/(?P<id>\d+)/rating', array(
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'submit_rating' ),
				'permission_callback' => array( $this, 'require_login' ),
			),
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_ratings' ),
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

	private function require_participant( $appointment_id, $user_id ) {
		$row = TD_Appointments::find_row( $appointment_id );
		if ( ! $row ) {
			return TD_Response::error( 'APPOINTMENT_NOT_FOUND', '약속을 찾을 수 없습니다.' );
		}
		if ( (int) $row['owner_user_id'] !== $user_id && (int) $row['counterpart_user_id'] !== $user_id ) {
			return TD_Response::error( 'FORBIDDEN', '이 약속의 참여자만 접근할 수 있습니다.' );
		}
		return true;
	}

	public function submit_rating( WP_REST_Request $request ) {
		$appointment_id = (int) $request['id'];
		$guard          = $this->require_participant( $appointment_id, get_current_user_id() );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$result = TD_Ratings::submit( $appointment_id, get_current_user_id(), (array) $request->get_json_params() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return TD_Response::success( $result );
	}

	public function get_ratings( WP_REST_Request $request ) {
		$appointment_id = (int) $request['id'];
		$guard          = $this->require_participant( $appointment_id, get_current_user_id() );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		return TD_Response::list( TD_Ratings::for_appointment( $appointment_id ), null );
	}
}
