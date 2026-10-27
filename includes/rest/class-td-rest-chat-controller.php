<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 거래 대화 API (Chomot 앱 기능정의서 7.3 /api/chat-rooms, /api/messages 대응).
 */
class TD_REST_Chat_Controller {

	const NS = 'todaydeal/v1';

	public function register_routes() {
		register_rest_route( self::NS, '/chat-rooms', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_rooms' ),
				'permission_callback' => array( $this, 'require_login' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'open_room' ),
				'permission_callback' => array( $this, 'require_login' ),
			),
		) );

		register_rest_route( self::NS, '/chat-rooms/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_room' ),
			'permission_callback' => array( $this, 'require_login' ),
			'args'                => array( 'id' => array( 'validate_callback' => fn( $param ) => is_numeric( $param ) ) ),
		) );

		register_rest_route( self::NS, '/chat-rooms/(?P<id>\d+)/messages', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_messages' ),
				'permission_callback' => array( $this, 'require_login' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'send' ),
				'permission_callback' => array( $this, 'require_login' ),
			),
			'args' => array( 'id' => array( 'validate_callback' => fn( $param ) => is_numeric( $param ) ) ),
		) );

		register_rest_route( self::NS, '/chat-rooms/(?P<id>\d+)/read', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'mark_read' ),
			'permission_callback' => array( $this, 'require_login' ),
			'args'                => array( 'id' => array( 'validate_callback' => fn( $param ) => is_numeric( $param ) ) ),
		) );
	}

	public function require_login() {
		return is_user_logged_in() ? true : TD_Response::error( 'AUTHENTICATION_REQUIRED', '로그인이 필요합니다.' );
	}

	public function list_rooms( WP_REST_Request $request ) {
		return TD_Response::list( TD_Chat::rooms_for_user( get_current_user_id(), absint( $request->get_param( 'listing_id' ) ) ), null );
	}

	public function open_room( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		return $this->wrap( TD_Chat::open_room( get_current_user_id(), absint( $params['listing_id'] ?? 0 ) ) );
	}

	public function get_room( WP_REST_Request $request ) {
		return $this->wrap( TD_Chat::get_room( (int) $request['id'], get_current_user_id() ) );
	}

	public function list_messages( WP_REST_Request $request ) {
		$result = TD_Chat::messages( (int) $request['id'], get_current_user_id(), absint( $request->get_param( 'after' ) ) );
		return is_wp_error( $result ) ? $result : TD_Response::list( $result, null );
	}

	public function send( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		return $this->wrap( TD_Chat::send( (int) $request['id'], get_current_user_id(), $params['content'] ?? '', $params['client_message_id'] ?? '' ) );
	}

	public function mark_read( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		return $this->wrap( TD_Chat::mark_read( (int) $request['id'], get_current_user_id(), absint( $params['message_id'] ?? 0 ) ) );
	}

	private function wrap( $result ) {
		return is_wp_error( $result ) ? $result : TD_Response::success( $result );
	}
}
