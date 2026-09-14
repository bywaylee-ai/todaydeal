<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-Cron scheduled maintenance (spec 22장 정리 작업): listing auto-expire,
 * appointment auto-expire, temp media cleanup.
 */
class TD_Cron {

	const HOOK_EXPIRE_LISTINGS     = 'todaydeal_cron_expire_listings';
	const HOOK_EXPIRE_APPOINTMENTS = 'todaydeal_cron_expire_appointments';
	const HOOK_CLEANUP_TEMP_MEDIA  = 'todaydeal_cron_cleanup_temp_media';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::HOOK_EXPIRE_LISTINGS, array( 'TD_Listings', 'run_auto_expire' ) );
		add_action( self::HOOK_EXPIRE_APPOINTMENTS, array( 'TD_Appointments', 'run_auto_expire' ) );
		add_action( self::HOOK_CLEANUP_TEMP_MEDIA, array( 'TD_Media', 'cleanup_temp' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK_EXPIRE_LISTINGS ) ) {
			wp_schedule_event( time(), 'hourly', self::HOOK_EXPIRE_LISTINGS );
		}
		if ( ! wp_next_scheduled( self::HOOK_EXPIRE_APPOINTMENTS ) ) {
			wp_schedule_event( time(), 'hourly', self::HOOK_EXPIRE_APPOINTMENTS );
		}
		if ( ! wp_next_scheduled( self::HOOK_CLEANUP_TEMP_MEDIA ) ) {
			wp_schedule_event( time(), 'daily', self::HOOK_CLEANUP_TEMP_MEDIA );
		}
	}
}
