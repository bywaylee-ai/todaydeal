<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TD_REST_Media_Controller {

	const NS = 'todaydeal/v1';

	public function register_routes() {
		register_rest_route( self::NS, '/media', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'upload' ),
			'permission_callback' => array( $this, 'require_login' ),
		) );

		register_rest_route( self::NS, '/media/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete' ),
			'permission_callback' => array( $this, 'require_login' ),
			'args'                => array( 'id' => array( 'validate_callback' => fn( $param ) => is_numeric( $param ) ) ),
		) );
	}

	public function require_login() {
		return is_user_logged_in() ? true : TD_Response::error( 'AUTHENTICATION_REQUIRED', '로그인이 필요합니다.' );
	}

	public function upload( WP_REST_Request $request ) {
		$limited = TD_Rate_Limiter::check( 'media_upload', get_current_user_id(), 30, 3600 );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return TD_Response::error( 'VALIDATION_ERROR', 'file 파트가 필요합니다.' );
		}

		$result = TD_Media::upload( get_current_user_id(), $files['file'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return TD_Response::success( $result );
	}

	public function delete( WP_REST_Request $request ) {
		$result = TD_Media::delete( (int) $request['id'], get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return TD_Response::success( array( 'deleted' => true ) );
	}
}
