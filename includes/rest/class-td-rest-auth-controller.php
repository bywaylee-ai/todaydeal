<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TD_REST_Auth_Controller {

	const NS = 'todaydeal/v1';

	public function register_routes() {
		register_rest_route( self::NS, '/auth/register', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'register' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/auth/login', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'login' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/auth/refresh', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'refresh' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/auth/logout', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'logout' ),
			'permission_callback' => array( $this, 'require_login' ),
		) );

		register_rest_route( self::NS, '/auth/logout-all', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'logout_all' ),
			'permission_callback' => array( $this, 'require_login' ),
		) );
	}

	public function require_login() {
		return is_user_logged_in() ? true : TD_Response::error( 'AUTHENTICATION_REQUIRED', '로그인이 필요합니다.' );
	}

	public function register( WP_REST_Request $request ) {
		$limited = TD_Rate_Limiter::check( 'register', TD_Rate_Limiter::client_ip(), 5, 3600 );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$user_id = TD_Users::register( $request->get_json_params() );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$tokens = TD_Auth::issue_token_pair( $user_id, $request->get_header( 'X-Device-Id' ) );
		return TD_Response::success( $tokens );
	}

	public function login( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		$login    = sanitize_text_field( $body['login'] ?? $body['email'] ?? $body['username'] ?? '' );
		$password = (string) ( $body['password'] ?? '' );

		if ( '' === $login || '' === $password ) {
			return TD_Response::error( 'VALIDATION_ERROR', '이메일/사용자명과 비밀번호를 입력해주세요.' );
		}

		$result = TD_Auth::login( $login, $password, $request->get_header( 'X-Device-Id' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return TD_Response::success( $result );
	}

	public function refresh( WP_REST_Request $request ) {
		$body          = $request->get_json_params();
		$refresh_token = (string) ( $body['refresh_token'] ?? '' );

		if ( '' === $refresh_token ) {
			return TD_Response::error( 'VALIDATION_ERROR', 'refresh_token이 필요합니다.' );
		}

		$result = TD_Auth::refresh( $refresh_token, $request->get_header( 'X-Device-Id' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return TD_Response::success( $result );
	}

	public function logout( WP_REST_Request $request ) {
		$body          = $request->get_json_params();
		$refresh_token = (string) ( $body['refresh_token'] ?? '' );
		TD_Auth::logout( $refresh_token );
		return TD_Response::success( array( 'logged_out' => true ) );
	}

	public function logout_all( WP_REST_Request $request ) {
		TD_Auth::logout_all( get_current_user_id() );
		return TD_Response::success( array( 'logged_out' => true ) );
	}
}
