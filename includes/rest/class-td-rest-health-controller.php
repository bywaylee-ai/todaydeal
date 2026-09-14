<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TD_REST_Health_Controller {

	const NS = 'todaydeal/v1';

	public function register_routes() {
		register_rest_route( self::NS, '/health', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'health' ),
			'permission_callback' => array( 'TD_App_Server_Auth', 'permission_admin_or_app_server' ),
		) );
	}

	public function health() {
		return TD_Response::success(
			array(
				'status'             => 'ok',
				'plugin_version'     => TODAYDEAL_VERSION,
				'schema_version'     => TD_DB::get_schema_version(),
				'woocommerce_active' => TD_Install::is_woocommerce_active(),
				'taxonomy_available' => TD_Taxonomy_Adapter::is_available(),
			)
		);
	}
}
