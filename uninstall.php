<?php
/**
 * Uninstall handler (spec section 24 삭제).
 * Operational data is never deleted automatically - only when the
 * administrator has explicitly opted in via `todaydeal_delete_data_on_uninstall`.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! get_option( 'todaydeal_delete_data_on_uninstall' ) ) {
	return;
}

global $wpdb;

$tables = array(
	$wpdb->prefix . 'td_refresh_tokens',
	$wpdb->prefix . 'td_idempotency',
	$wpdb->prefix . 'td_appointments',
	$wpdb->prefix . 'td_appointment_history',
	$wpdb->prefix . 'td_audit_log',
	$wpdb->prefix . 'td_server_nonces',
);

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names are static, no user input.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

$posts = get_posts( array( 'post_type' => 'todaydeal_deal', 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids' ) );
foreach ( $posts as $post_id ) {
	wp_delete_post( $post_id, true );
}

$options = array(
	'todaydeal_settings',
	'todaydeal_schema_version',
	'todaydeal_activated_at',
	'todaydeal_token_secret',
	'todaydeal_app_server_secret',
	'todaydeal_allowed_origins',
	'todaydeal_postmeta_index_warning',
	'todaydeal_delete_data_on_uninstall',
);
foreach ( $options as $option ) {
	delete_option( $option );
}
