<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin bootstrap / hook registration (spec section 5 Core module).
 */
class TD_Core {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		TD_Install::maybe_upgrade();

		add_action( 'init', array( 'TD_Post_Type', 'register' ) );
		add_action( 'init', array( 'TD_Criteria', 'register' ) );
		add_action( 'init', array( 'TD_Install', 'maybe_flush_rewrite_rules' ), 20 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'apply_cors' ), 10, 4 );
		add_action( 'admin_notices', array( 'TD_Admin', 'maybe_requirements_notice' ) );

		TD_Cron::init();
		TD_Admin::init();
		TD_Admin_Category_Fields::init();
		TD_Admin_Criteria::init();
		TD_Admin_Listing_Editor::init();
		TD_Frontend_Form::init();
		TD_Frontend_Views::init();
	}

	public function register_routes() {
		( new TD_REST_Auth_Controller() )->register_routes();
		( new TD_REST_Users_Controller() )->register_routes();
		( new TD_REST_Categories_Controller() )->register_routes();
		( new TD_REST_Media_Controller() )->register_routes();
		( new TD_REST_Listings_Controller() )->register_routes();
		( new TD_REST_Appointments_Controller() )->register_routes();
		( new TD_REST_Health_Controller() )->register_routes();
	}

	/**
	 * Spec 21 #10: only allowlisted app-server origins may call the API
	 * cross-origin; plain browser access to these routes is not a supported
	 * integration path.
	 */
	public function apply_cors( $served, $result, $request, $server ) {
		if ( strpos( $request->get_route(), '/todaydeal/v1' ) !== 0 ) {
			return $served;
		}

		$allowed_origins = apply_filters( 'todaydeal_allowed_origins', get_option( 'todaydeal_allowed_origins', array() ) );
		$origin          = get_http_origin();

		if ( $origin && in_array( $origin, (array) $allowed_origins, true ) ) {
			header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
			header( 'Access-Control-Allow-Credentials: false' );
			header( 'Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type, Idempotency-Key, X-TD-Timestamp, X-TD-Nonce, X-TD-Signature' );
		}

		return $served;
	}
}
