<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transient-based rate limiting (spec section 7.1 WP-AUTH-007, 21 #8).
 */
class TD_Rate_Limiter {

	/**
	 * Generic fixed-window limiter.
	 *
	 * @return true|WP_Error true if allowed, WP_Error(RATE_LIMITED) otherwise.
	 */
	public static function check( $bucket, $identifier, $max_attempts, $window_seconds ) {
		$key   = 'td_rl_' . md5( $bucket . '|' . $identifier );
		$count = (int) get_transient( $key );

		if ( $count >= $max_attempts ) {
			return TD_Response::error(
				'RATE_LIMITED',
				'요청이 너무 많습니다. 잠시 후 다시 시도해주세요.',
				array( 'retry_after' => $window_seconds )
			);
		}

		set_transient( $key, $count + 1, $window_seconds );
		return true;
	}

	/**
	 * Login-specific limiter combining IP and account identifiers (WP-AUTH-007).
	 * Applies an increasing lockout window as failures accumulate.
	 */
	public static function check_login( $ip, $login ) {
		foreach ( array( 'ip:' . $ip, 'login:' . strtolower( $login ) ) as $identifier ) {
			$key         = 'td_login_fail_' . md5( $identifier );
			$fail_count  = (int) get_transient( $key );

			if ( $fail_count >= 10 ) {
				return TD_Response::error( 'RATE_LIMITED', '로그인 시도가 너무 많습니다. 잠시 후 다시 시도해주세요.', array( 'retry_after' => 900 ) );
			}
		}
		return true;
	}

	public static function record_login_failure( $ip, $login ) {
		foreach ( array( 'ip:' . $ip, 'login:' . strtolower( $login ) ) as $identifier ) {
			$key        = 'td_login_fail_' . md5( $identifier );
			$fail_count = (int) get_transient( $key );
			$window     = min( 900, 30 * max( 1, $fail_count + 1 ) ); // widening lockout window
			set_transient( $key, $fail_count + 1, $window );
		}
	}

	public static function clear_login_failures( $ip, $login ) {
		delete_transient( 'td_login_fail_' . md5( 'ip:' . $ip ) );
		delete_transient( 'td_login_fail_' . md5( 'login:' . strtolower( $login ) ) );
	}

	public static function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
	}
}
