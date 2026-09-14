<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TD_REST_Users_Controller {

	const NS = 'todaydeal/v1';

	public function register_routes() {
		register_rest_route( self::NS, '/users/me', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_me' ),
				'permission_callback' => array( $this, 'require_login' ),
			),
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'update_me' ),
				'permission_callback' => array( $this, 'require_login' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_me' ),
				'permission_callback' => array( $this, 'require_login' ),
			),
		) );

		register_rest_route( self::NS, '/users/(?P<id>\d+)/public', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_public' ),
			'permission_callback' => '__return_true',
			'args'                => array( 'id' => array( 'validate_callback' => fn( $param ) => is_numeric( $param ) ) ),
		) );

		register_rest_route( self::NS, '/users/me/listings', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_my_listings' ),
			'permission_callback' => array( $this, 'require_login' ),
		) );

		register_rest_route( self::NS, '/users/me/appointments', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_my_appointments' ),
			'permission_callback' => array( $this, 'require_login' ),
		) );
	}

	public function require_login() {
		return is_user_logged_in() ? true : TD_Response::error( 'AUTHENTICATION_REQUIRED', '로그인이 필요합니다.' );
	}

	public function get_me() {
		return TD_Response::success( TD_Users::private_profile( get_current_user_id() ) );
	}

	public function update_me( WP_REST_Request $request ) {
		$result = TD_Users::update_profile( get_current_user_id(), (array) $request->get_json_params() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return TD_Response::success( $result );
	}

	public function delete_me() {
		$result = TD_Users::delete_account( get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return TD_Response::success( array( 'deleted' => true ) );
	}

	public function get_public( WP_REST_Request $request ) {
		$result = TD_Users::public_profile( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return TD_Response::success( $result );
	}

	public function get_my_listings( WP_REST_Request $request ) {
		list( $items, $next_cursor ) = TD_Listings::query_my_listings( get_current_user_id(), $request->get_query_params() );
		return TD_Response::list( $items, $next_cursor );
	}

	public function get_my_appointments( WP_REST_Request $request ) {
		$items = TD_Appointments::list_for_user( get_current_user_id(), $request->get_query_params() );
		return TD_Response::list( $items, null );
	}
}
