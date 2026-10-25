<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Social sign-in (Google). The app obtains an ID token from the provider SDK,
 * the server verifies it and issues the usual TodayDeal token pair.
 */
class TD_Social_Auth {

	const META_GOOGLE_SUB = '_todaydeal_google_sub';

	const GOOGLE_TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';
	const GOOGLE_ISSUERS       = array( 'accounts.google.com', 'https://accounts.google.com' );

	/**
	 * Sign in or sign up with a Google ID token.
	 * Lookup order: linked google sub → verified email (auto-link) → new user.
	 */
	public static function login_google( $id_token, array $fields, $device = '' ) {
		$claims = self::verify_google_id_token( $id_token );
		if ( is_wp_error( $claims ) ) {
			TD_Audit_Log::record( 'login_google', 'user', null, 'failure', array( 'reason' => $claims->get_error_code() ) );
			return $claims;
		}

		$sub         = (string) $claims['sub'];
		$email       = sanitize_email( $claims['email'] ?? '' );
		$is_new_user = false;

		$user_id = self::find_user_by_google_sub( $sub );

		if ( ! $user_id && $email ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				$user_id = $user->ID;
				update_user_meta( $user_id, self::META_GOOGLE_SUB, $sub );
				TD_Audit_Log::record( 'social_link', 'user', $user_id, 'success', array( 'provider' => 'google' ) );
			}
		}

		if ( ! $user_id ) {
			$nickname = sanitize_text_field( $fields['nickname'] ?? '' );
			if ( '' === $nickname ) {
				$nickname = sanitize_text_field( $claims['name'] ?? '' ) ?: strstr( $email, '@', true );
			}

			$user_id = TD_Users::register(
				array_merge(
					$fields,
					array(
						'email'    => $email,
						'nickname' => $nickname,
						'password' => wp_generate_password( 32, true, true ),
					)
				)
			);
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}

			update_user_meta( $user_id, self::META_GOOGLE_SUB, $sub );
			$is_new_user = true;
		}

		TD_Audit_Log::record( 'login_google', 'user', $user_id, 'success' );

		return array_merge(
			TD_Auth::issue_token_pair( $user_id, $device ),
			array( 'is_new_user' => $is_new_user )
		);
	}

	/**
	 * Validates signature/expiry via Google's tokeninfo endpoint, then checks
	 * issuer, audience (our client IDs) and that the email is verified.
	 */
	private static function verify_google_id_token( $id_token ) {
		$settings   = get_option( 'todaydeal_settings', TD_Install::OPTION_DEFAULTS );
		$client_ids = (array) ( $settings['google_client_ids'] ?? array() );

		if ( empty( $client_ids ) ) {
			return TD_Response::error( 'INTERNAL_ERROR', 'Google 로그인이 설정되지 않았습니다.' );
		}

		$response = wp_remote_get(
			add_query_arg( 'id_token', rawurlencode( $id_token ), self::GOOGLE_TOKENINFO_URL ),
			array( 'timeout' => 10 )
		);

		if ( is_wp_error( $response ) ) {
			return TD_Response::error( 'INTERNAL_ERROR', 'Google 인증 서버에 연결할 수 없습니다.' );
		}

		$claims = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== wp_remote_retrieve_response_code( $response ) || ! is_array( $claims ) || empty( $claims['sub'] ) ) {
			return TD_Response::error( 'INVALID_CREDENTIALS', 'Google 인증 토큰이 유효하지 않습니다.' );
		}

		if ( ! in_array( $claims['iss'] ?? '', self::GOOGLE_ISSUERS, true )
			|| ! in_array( $claims['aud'] ?? '', $client_ids, true )
			|| (int) ( $claims['exp'] ?? 0 ) < time() ) {
			return TD_Response::error( 'INVALID_CREDENTIALS', 'Google 인증 토큰이 유효하지 않습니다.' );
		}

		// tokeninfo returns booleans as strings.
		if ( 'true' !== (string) ( $claims['email_verified'] ?? '' ) || ! is_email( $claims['email'] ?? '' ) ) {
			return TD_Response::error( 'INVALID_CREDENTIALS', '인증된 Google 이메일이 필요합니다.' );
		}

		return $claims;
	}

	private static function find_user_by_google_sub( $sub ) {
		$users = get_users(
			array(
				'meta_key'   => self::META_GOOGLE_SUB,
				'meta_value' => $sub,
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		return $users ? (int) $users[0] : 0;
	}
}
