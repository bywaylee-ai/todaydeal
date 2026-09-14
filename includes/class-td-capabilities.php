<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom capability definitions and role mapping (spec section 6).
 */
class TD_Capabilities {

	public static function all() {
		return array(
			'todaydeal_create_listings',
			'todaydeal_edit_own_listings',
			'todaydeal_manage_all_listings',
			'todaydeal_upload_media',
			'todaydeal_manage_appointments',
			'todaydeal_act_as_manager',
			'todaydeal_write_checklists',
			'todaydeal_assign_managers',
			'todaydeal_manage_checklist_templates',
			'todaydeal_resolve_disputes',
			'todaydeal_manage_settings',
			'todaydeal_view_logs',
		);
	}

	/**
	 * Capabilities granted to a plain authenticated "TodayDeal 회원" (WordPress subscriber).
	 */
	public static function member_caps() {
		return array(
			'todaydeal_create_listings',
			'todaydeal_edit_own_listings',
			'todaydeal_upload_media',
			'todaydeal_manage_appointments',
		);
	}

	public static function staff_caps() {
		return array_merge(
			self::member_caps(),
			array(
				'todaydeal_manage_all_listings',
				'todaydeal_assign_managers',
				'todaydeal_manage_checklist_templates',
				'todaydeal_resolve_disputes',
				'todaydeal_view_logs',
			)
		);
	}

	/**
	 * WordPress' own post-type meta-capabilities for `todaydeal_deal`
	 * (capability_type => array('todaydeal_deal','todaydeal_deals') in
	 * TD_Post_Type::register()). These gate the wp-admin list/edit screens
	 * (spec 9.1 "관리자 목록 화면만 제공") and are separate from the
	 * todaydeal_* app capabilities the REST layer checks directly.
	 */
	public static function post_type_caps() {
		return array(
			'edit_todaydeal_deals',
			'edit_others_todaydeal_deals',
			'edit_private_todaydeal_deals',
			'edit_published_todaydeal_deals',
			'publish_todaydeal_deals',
			'read_private_todaydeal_deals',
			'delete_todaydeal_deals',
			'delete_private_todaydeal_deals',
			'delete_published_todaydeal_deals',
			'delete_others_todaydeal_deals',
		);
	}

	public static function register_on_activation() {
		$subscriber = get_role( 'subscriber' );
		if ( $subscriber ) {
			foreach ( self::member_caps() as $cap ) {
				$subscriber->add_cap( $cap );
			}
		}

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array_merge( self::all(), self::post_type_caps() ) as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		// Dedicated "TodayDeal Staff" role (spec section 6).
		if ( ! get_role( 'todaydeal_staff' ) ) {
			add_role( 'todaydeal_staff', 'TodayDeal Staff', array( 'read' => true ) );
		}
		$staff = get_role( 'todaydeal_staff' );
		if ( $staff ) {
			foreach ( array_merge( self::staff_caps(), self::post_type_caps() ) as $cap ) {
				$staff->add_cap( $cap );
			}
		}
	}
}
