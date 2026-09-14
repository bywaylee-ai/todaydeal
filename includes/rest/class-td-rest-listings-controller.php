<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TD_REST_Listings_Controller {

	const NS = 'todaydeal/v1';

	public function register_routes() {
		register_rest_route( self::NS, '/listings', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_listings' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create' ),
				'permission_callback' => array( $this, 'require_create_cap' ),
			),
		) );

		register_rest_route( self::NS, '/listings/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_one' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'replace' ),
				'permission_callback' => array( $this, 'require_login' ),
			),
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'patch' ),
				'permission_callback' => array( $this, 'require_login' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete' ),
				'permission_callback' => array( $this, 'require_login' ),
			),
			'args' => array( 'id' => array( 'validate_callback' => fn( $param ) => is_numeric( $param ) ) ),
		) );

		register_rest_route( self::NS, '/listings/(?P<id>\d+)/status', array(
			'methods'             => 'PATCH',
			'callback'            => array( $this, 'change_status' ),
			'permission_callback' => array( $this, 'require_login' ),
			'args'                => array( 'id' => array( 'validate_callback' => fn( $param ) => is_numeric( $param ) ) ),
		) );

		register_rest_route( self::NS, '/listings/(?P<id>\d+)/appointments', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_appointments' ),
			'permission_callback' => array( $this, 'require_login' ),
			'args'                => array( 'id' => array( 'validate_callback' => fn( $param ) => is_numeric( $param ) ) ),
		) );
	}

	public function require_login() {
		return is_user_logged_in() ? true : TD_Response::error( 'AUTHENTICATION_REQUIRED', '로그인이 필요합니다.' );
	}

	public function require_create_cap() {
		if ( ! is_user_logged_in() ) {
			return TD_Response::error( 'AUTHENTICATION_REQUIRED', '로그인이 필요합니다.' );
		}
		return current_user_can( 'todaydeal_create_listings' )
			? true
			: TD_Response::error( 'FORBIDDEN', '거래글 등록 권한이 없습니다.' );
	}

	public function list_listings( WP_REST_Request $request ) {
		list( $items, $next_cursor, $meta ) = TD_Listings::query_list( $request->get_query_params(), get_current_user_id() );
		return TD_Response::list( $items, $next_cursor, $meta );
	}

	public function get_one( WP_REST_Request $request ) {
		$result = TD_Listings::get( (int) $request['id'], get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return TD_Response::success( $result );
	}

	public function create( WP_REST_Request $request ) {
		$limited = TD_Rate_Limiter::check( 'listing_create', get_current_user_id(), 30, 3600 );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$result = TD_Listings::create( get_current_user_id(), (array) $request->get_json_params(), TD_Idempotency::key_from_request( $request ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return TD_Response::success( $result );
	}

	public function replace( WP_REST_Request $request ) {
		$result = TD_Listings::update( (int) $request['id'], get_current_user_id(), (array) $request->get_json_params(), false );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return TD_Response::success( $result );
	}

	public function patch( WP_REST_Request $request ) {
		$result = TD_Listings::update( (int) $request['id'], get_current_user_id(), (array) $request->get_json_params(), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return TD_Response::success( $result );
	}

	public function change_status( WP_REST_Request $request ) {
		$body   = $request->get_json_params();
		$result = TD_Listings::change_status( (int) $request['id'], get_current_user_id(), sanitize_key( $body['status'] ?? '' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return TD_Response::success( $result );
	}

	public function delete( WP_REST_Request $request ) {
		$result = TD_Listings::trash( (int) $request['id'], get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return TD_Response::success( array( 'deleted' => true ) );
	}

	/**
	 * WP-LST-017: owner-only view of in-flight appointments on this listing.
	 */
	public function get_appointments( WP_REST_Request $request ) {
		$listing_id = (int) $request['id'];
		if ( TD_Listings::owner_id( $listing_id ) !== get_current_user_id() && ! current_user_can( 'todaydeal_manage_all_listings' ) ) {
			return TD_Response::error( 'FORBIDDEN_LISTING_OWNER', '이 거래글의 약속 목록에 접근할 권한이 없습니다.' );
		}

		$items = TD_Appointments::list_for_listing( $listing_id, $request->get_query_params() );
		return TD_Response::list( $items, null );
	}
}
