<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal self-contained HS256 JWT encode/decode.
 * No external dependency, since the plugin must be self-sufficient (spec section 1).
 */
class TD_JWT {

	private static function b64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	private static function b64url_decode( $data ) {
		$padded = str_pad( $data, strlen( $data ) % 4 === 0 ? strlen( $data ) : strlen( $data ) + ( 4 - strlen( $data ) % 4 ), '=' );
		return base64_decode( strtr( $padded, '-_', '+/' ) );
	}

	private static function secret() {
		$secret = get_option( 'todaydeal_token_secret' );
		if ( ! $secret ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( 'todaydeal_token_secret', $secret );
		}
		return $secret;
	}

	/**
	 * @param array $claims must already contain sub, exp, iat, iss, aud, jti.
	 */
	public static function encode( array $claims ) {
		$header = array(
			'alg' => 'HS256',
			'typ' => 'JWT',
		);

		$segments   = array();
		$segments[] = self::b64url_encode( wp_json_encode( $header ) );
		$segments[] = self::b64url_encode( wp_json_encode( $claims ) );

		$signing_input = implode( '.', $segments );
		$signature     = hash_hmac( 'sha256', $signing_input, self::secret(), true );
		$segments[]    = self::b64url_encode( $signature );

		return implode( '.', $segments );
	}

	/**
	 * @return array|WP_Error decoded claims, or WP_Error on invalid/expired token.
	 */
	public static function decode( $token ) {
		$parts = explode( '.', (string) $token );
		if ( count( $parts ) !== 3 ) {
			return TD_Response::error( 'TOKEN_INVALID', '토큰 형식이 올바르지 않습니다.' );
		}

		list( $header_b64, $payload_b64, $sig_b64 ) = $parts;

		$signing_input      = $header_b64 . '.' . $payload_b64;
		$expected_signature = hash_hmac( 'sha256', $signing_input, self::secret(), true );
		$given_signature    = self::b64url_decode( $sig_b64 );

		if ( ! hash_equals( $expected_signature, $given_signature ) ) {
			return TD_Response::error( 'TOKEN_INVALID', '토큰 서명이 올바르지 않습니다.' );
		}

		$claims = json_decode( self::b64url_decode( $payload_b64 ), true );
		if ( ! is_array( $claims ) ) {
			return TD_Response::error( 'TOKEN_INVALID', '토큰 내용을 해석할 수 없습니다.' );
		}

		if ( empty( $claims['exp'] ) || time() >= (int) $claims['exp'] ) {
			return TD_Response::error( 'TOKEN_EXPIRED', '토큰이 만료되었습니다.' );
		}

		return $claims;
	}
}
