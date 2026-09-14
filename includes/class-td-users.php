<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Member features (spec section 8).
 */
class TD_Users {

	const META_COUNTRY   = '_todaydeal_country';
	const META_CITY      = '_todaydeal_city';
	const META_LANGUAGE  = '_todaydeal_language';
	const META_RATING_AVG = '_todaydeal_rating_average';
	const META_RATING_COUNT = '_todaydeal_rating_count';
	const META_DELETED   = '_todaydeal_deleted';

	/**
	 * WP-USER-001/002: create a WordPress user from app registration fields.
	 */
	public static function register( array $fields ) {
		$email    = sanitize_email( $fields['email'] ?? '' );
		$nickname = sanitize_text_field( $fields['nickname'] ?? '' );
		$password = (string) ( $fields['password'] ?? '' );
		$country  = strtoupper( sanitize_text_field( $fields['country'] ?? '' ) );
		$city     = sanitize_text_field( $fields['city'] ?? '' );
		$language = sanitize_text_field( $fields['language'] ?? get_option( 'todaydeal_settings', array() )['default_language'] ?? 'ko' );

		if ( ! is_email( $email ) ) {
			return TD_Response::error( 'VALIDATION_ERROR', '올바른 이메일 주소를 입력해주세요.', array( 'field' => 'email' ) );
		}
		if ( strlen( $password ) < 8 ) {
			return TD_Response::error( 'VALIDATION_ERROR', '비밀번호는 8자 이상이어야 합니다.', array( 'field' => 'password' ) );
		}
		if ( '' === $nickname ) {
			return TD_Response::error( 'VALIDATION_ERROR', '닉네임을 입력해주세요.', array( 'field' => 'nickname' ) );
		}

		if ( email_exists( $email ) ) {
			return TD_Response::error( 'VALIDATION_ERROR', '이미 사용 중인 이메일입니다.', array( 'field' => 'email' ) );
		}

		$username = self::unique_username_from( $nickname );

		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $email,
				'user_pass'    => $password,
				'display_name' => $nickname,
				'nickname'     => $nickname,
				'role'         => 'subscriber',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return TD_Response::error( 'VALIDATION_ERROR', $user_id->get_error_message() );
		}

		update_user_meta( $user_id, self::META_COUNTRY, $country );
		update_user_meta( $user_id, self::META_CITY, $city );
		update_user_meta( $user_id, self::META_LANGUAGE, $language );

		TD_Audit_Log::record( 'register', 'user', $user_id, 'success' );

		return $user_id;
	}

	private static function unique_username_from( $nickname ) {
		$base = sanitize_user( strtolower( $nickname ), true );
		if ( '' === $base ) {
			$base = 'user';
		}
		$candidate = $base;
		$i         = 1;
		while ( username_exists( $candidate ) ) {
			$candidate = $base . $i;
			$i++;
		}
		return $candidate;
	}

	/**
	 * WP-USER-003: public profile fields only.
	 */
	public static function public_profile( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user || get_user_meta( $user_id, self::META_DELETED, true ) ) {
			return TD_Response::error( 'USER_NOT_FOUND', '사용자를 찾을 수 없습니다.' );
		}

		return array(
			'user_id'       => $user->ID,
			'nickname'      => $user->display_name,
			'country'       => get_user_meta( $user_id, self::META_COUNTRY, true ) ?: null,
			'city'          => get_user_meta( $user_id, self::META_CITY, true ) ?: null,
			'rating_average' => self::rating_average( $user_id ),
			'review_count'  => (int) get_user_meta( $user_id, self::META_RATING_COUNT, true ),
		);
	}

	/**
	 * WP-USER-003/009: full self profile, including the stable app-facing user id.
	 */
	public static function private_profile( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return TD_Response::error( 'USER_NOT_FOUND', '사용자를 찾을 수 없습니다.' );
		}

		return array(
			'user_id'        => $user->ID,
			'email'          => $user->user_email,
			'nickname'       => $user->display_name,
			'country'        => get_user_meta( $user_id, self::META_COUNTRY, true ) ?: null,
			'city'           => get_user_meta( $user_id, self::META_CITY, true ) ?: null,
			'language'       => get_user_meta( $user_id, self::META_LANGUAGE, true ) ?: null,
			'rating_average' => self::rating_average( $user_id ),
			'review_count'   => (int) get_user_meta( $user_id, self::META_RATING_COUNT, true ),
			'created_at'     => $user->user_registered,
		);
	}

	private static function rating_average( $user_id ) {
		$value = get_user_meta( $user_id, self::META_RATING_AVG, true );
		return ( '' === $value ) ? null : (float) $value;
	}

	const ALLOWED_PROFILE_FIELDS = array( 'nickname', 'country', 'city', 'language' );

	/**
	 * WP-USER-004: allowlist-only profile update.
	 */
	public static function update_profile( $user_id, array $fields ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return TD_Response::error( 'USER_NOT_FOUND', '사용자를 찾을 수 없습니다.' );
		}

		foreach ( $fields as $key => $value ) {
			if ( ! in_array( $key, self::ALLOWED_PROFILE_FIELDS, true ) ) {
				continue;
			}
			switch ( $key ) {
				case 'nickname':
					wp_update_user(
						array(
							'ID'           => $user_id,
							'display_name' => sanitize_text_field( $value ),
							'nickname'     => sanitize_text_field( $value ),
						)
					);
					break;
				case 'country':
					update_user_meta( $user_id, self::META_COUNTRY, strtoupper( sanitize_text_field( $value ) ) );
					break;
				case 'city':
					update_user_meta( $user_id, self::META_CITY, sanitize_text_field( $value ) );
					break;
				case 'language':
					update_user_meta( $user_id, self::META_LANGUAGE, sanitize_text_field( $value ) );
					break;
			}
		}

		TD_Audit_Log::record( 'profile_update', 'user', $user_id, 'success', array( 'fields' => array_keys( $fields ) ) );

		return self::private_profile( $user_id );
	}

	/**
	 * WP-USER-008: anonymize account, revoke tokens, wind down open listings
	 * and in-flight appointments. Data (listings/appointment history) is kept
	 * for record-keeping, matching the WordPress default "keep by default"
	 * posture; a full export/erase policy is section 21 #14 / stage 6 scope.
	 */
	public static function delete_account( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return TD_Response::error( 'USER_NOT_FOUND', '사용자를 찾을 수 없습니다.' );
		}

		if ( class_exists( 'TD_Listings' ) ) {
			TD_Listings::hide_all_open_for_owner( $user_id, 'owner_account_deleted' );
		}
		if ( class_exists( 'TD_Appointments' ) ) {
			TD_Appointments::cancel_all_active_for_user( $user_id, 'counterpart_account_deleted' );
		}

		TD_Auth::logout_all( $user_id );

		$anon_email = 'deleted-' . $user_id . '@todaydeal.invalid';
		wp_update_user(
			array(
				'ID'           => $user_id,
				'display_name' => '탈퇴한 회원',
				'nickname'     => '탈퇴한 회원',
				'user_email'   => $anon_email,
			)
		);
		update_user_meta( $user_id, self::META_DELETED, 1 );

		TD_Audit_Log::record( 'account_delete', 'user', $user_id, 'success' );

		return true;
	}
}
