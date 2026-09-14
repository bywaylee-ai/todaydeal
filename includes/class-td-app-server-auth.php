<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * App-server (server-to-server) HMAC authentication, separate from user tokens
 * (spec section 7.2). Verifies X-TD-Timestamp / X-TD-Nonce / X-TD-Signature.
 */
class TD_App_Server_Auth {

	const MAX_SKEW_SECONDS = 300;

	private static function secret() {
		$secret = get_option( 'todaydeal_app_server_secret' );
		if ( ! $secret ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( 'todaydeal_app_server_secret', $secret );
		}
		return $secret;
	}

	public static function verify( WP_REST_Request $request ) {
		$timestamp = $request->get_header( 'X-TD-Timestamp' );
		$nonce     = $request->get_header( 'X-TD-Nonce' );
		$signature = $request->get_header( 'X-TD-Signature' );

		if ( ! $timestamp || ! $nonce || ! $signature ) {
			return false;
		}

		if ( abs( time() - (int) $timestamp ) > self::MAX_SKEW_SECONDS ) {
			return false;
		}

		if ( self::nonce_seen( $nonce ) ) {
			return false;
		}

		$body           = (string) $request->get_body();
		$body_hash      = hash( 'sha256', $body );
		$signing_string = $timestamp . '.' . $nonce . '.' . $body_hash;
		$expected       = hash_hmac( 'sha256', $signing_string, self::secret() );

		if ( ! hash_equals( $expected, (string) $signature ) ) {
			return false;
		}

		self::store_nonce( $nonce );
		return true;
	}

	private static function nonce_seen( $nonce ) {
		global $wpdb;
		$exists = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . TD_DB::nonces() . ' WHERE nonce = %s', $nonce )
		);
		return (bool) $exists;
	}

	private static function store_nonce( $nonce ) {
		global $wpdb;
		$wpdb->insert(
			TD_DB::nonces(),
			array(
				'nonce'      => $nonce,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s' )
		);
	}

	/**
	 * Permission callback usable on routes open to either an authenticated
	 * admin/staff user, or a correctly signed app-server request.
	 */
	public static function permission_admin_or_app_server( WP_REST_Request $request ) {
		if ( current_user_can( 'manage_options' ) || current_user_can( 'todaydeal_view_logs' ) ) {
			return true;
		}
		if ( self::verify( $request ) ) {
			return true;
		}
		return TD_Response::error( 'AUTHENTICATION_REQUIRED', '관리자 또는 앱 서버 인증이 필요합니다.' );
	}
}
