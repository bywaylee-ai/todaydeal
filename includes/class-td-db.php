<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Table name helpers and schema version bookkeeping.
 */
class TD_DB {

	public static function table( $suffix ) {
		global $wpdb;
		return $wpdb->prefix . 'td_' . $suffix;
	}

	public static function refresh_tokens() {
		return self::table( 'refresh_tokens' );
	}

	public static function idempotency() {
		return self::table( 'idempotency' );
	}

	public static function appointments() {
		return self::table( 'appointments' );
	}

	public static function appointment_history() {
		return self::table( 'appointment_history' );
	}

	public static function audit_log() {
		return self::table( 'audit_log' );
	}

	public static function nonces() {
		return self::table( 'server_nonces' );
	}

	public static function get_schema_version() {
		return (int) get_option( 'todaydeal_schema_version', 0 );
	}

	public static function set_schema_version( $version ) {
		update_option( 'todaydeal_schema_version', (int) $version );
	}
}
