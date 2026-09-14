<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * User authentication: login, access/refresh tokens (spec section 7.1).
 */
class TD_Auth {

	private static function settings() {
		return get_option( 'todaydeal_settings', TD_Install::OPTION_DEFAULTS );
	}

	/**
	 * WP-AUTH-001/002/003: verify credentials and issue a token pair.
	 */
	public static function login( $login, $password, $device = '' ) {
		$ip = TD_Rate_Limiter::client_ip();

		$limited = TD_Rate_Limiter::check_login( $ip, $login );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$user = wp_authenticate( $login, $password );

		if ( is_wp_error( $user ) ) {
			TD_Rate_Limiter::record_login_failure( $ip, $login );
			TD_Audit_Log::record( 'login', 'user', null, 'failure', array( 'login' => $login ) );
			return TD_Response::error( 'INVALID_CREDENTIALS', '이메일/사용자명 또는 비밀번호가 올바르지 않습니다.' );
		}

		TD_Rate_Limiter::clear_login_failures( $ip, $login );
		TD_Audit_Log::record( 'login', 'user', $user->ID, 'success' );

		return self::issue_token_pair( $user->ID, $device );
	}

	public static function issue_token_pair( $user_id, $device = '' ) {
		$settings = self::settings();

		$access_token = self::issue_access_token( $user_id );
		$refresh      = self::issue_refresh_token( $user_id, $device );

		return array(
			'access_token'       => $access_token,
			'access_expires_in'  => (int) $settings['access_token_ttl'],
			'refresh_token'      => $refresh,
			'refresh_expires_in' => (int) $settings['refresh_token_ttl'],
			'user_id'            => $user_id,
		);
	}

	private static function issue_access_token( $user_id ) {
		$settings = self::settings();
		$user     = get_userdata( $user_id );
		$now      = time();

		$claims = array(
			'sub'   => $user_id,
			'caps'  => $user ? array_keys( array_filter( (array) $user->allcaps ) ) : array(),
			'iat'   => $now,
			'exp'   => $now + (int) $settings['access_token_ttl'],
			'iss'   => get_bloginfo( 'url' ),
			'aud'   => 'todaydeal-app',
			'jti'   => wp_generate_uuid4(),
		);

		return TD_JWT::encode( $claims );
	}

	private static function issue_refresh_token( $user_id, $device = '' ) {
		global $wpdb;
		$settings = self::settings();

		$raw  = wp_generate_password( 64, false );
		$hash = hash( 'sha256', $raw );

		$wpdb->insert(
			TD_DB::refresh_tokens(),
			array(
				'user_id'    => $user_id,
				'token_hash' => $hash,
				'device'     => sanitize_text_field( $device ),
				'issued_at'  => current_time( 'mysql', true ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + (int) $settings['refresh_token_ttl'] ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		return $raw;
	}

	/**
	 * WP-AUTH-004: rotate refresh token, issue new access token.
	 */
	public static function refresh( $refresh_token, $device = '' ) {
		global $wpdb;
		$hash = hash( 'sha256', (string) $refresh_token );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . TD_DB::refresh_tokens() . ' WHERE token_hash = %s',
				$hash
			),
			ARRAY_A
		);

		if ( ! $row || $row['revoked_at'] || strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
			return TD_Response::error( 'TOKEN_EXPIRED', '갱신 토큰이 유효하지 않습니다.' );
		}

		// Revoke the used token (rotation).
		$wpdb->update(
			TD_DB::refresh_tokens(),
			array( 'revoked_at' => current_time( 'mysql', true ) ),
			array( 'id' => $row['id'] )
		);

		return self::issue_token_pair( (int) $row['user_id'], $device );
	}

	/**
	 * WP-AUTH-005: revoke a single device's refresh token.
	 */
	public static function logout( $refresh_token ) {
		global $wpdb;
		$hash = hash( 'sha256', (string) $refresh_token );

		$wpdb->update(
			TD_DB::refresh_tokens(),
			array( 'revoked_at' => current_time( 'mysql', true ) ),
			array( 'token_hash' => $hash )
		);

		return true;
	}

	/**
	 * WP-AUTH-006: revoke every refresh token for a user (password change, deletion).
	 */
	public static function logout_all( $user_id ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . TD_DB::refresh_tokens() . ' SET revoked_at = %s WHERE user_id = %d AND revoked_at IS NULL',
				current_time( 'mysql', true ),
				$user_id
			)
		);
		return true;
	}

	/**
	 * Resolves the current REST request's authenticated user from the
	 * Authorization: Bearer <access token> header. Hooked into
	 * determine_current_user so core capability checks work as usual.
	 */
	public static function determine_current_user( $user_id ) {
		if ( $user_id ) {
			return $user_id;
		}

		if ( empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return $user_id;
		}

		$header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		if ( stripos( $header, 'Bearer ' ) !== 0 ) {
			return $user_id;
		}

		$token  = trim( substr( $header, 7 ) );
		$claims = TD_JWT::decode( $token );

		if ( is_wp_error( $claims ) || empty( $claims['sub'] ) ) {
			return $user_id;
		}

		if ( ! get_userdata( (int) $claims['sub'] ) ) {
			return $user_id;
		}

		return (int) $claims['sub'];
	}
}

add_filter( 'determine_current_user', array( 'TD_Auth', 'determine_current_user' ), 20 );
