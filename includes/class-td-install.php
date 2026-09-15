<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activation / deactivation lifecycle (spec section 24, 4).
 */
class TD_Install {

	const OPTION_DEFAULTS = array(
		'access_token_ttl'          => 900,        // seconds (15 min)
		'refresh_token_ttl'         => 2592000,    // 30 days
		'default_listing_ttl_days'  => 30,
		'max_open_listings_sell'    => 20,
		'max_open_listings_buy'     => 20,
		'max_daily_listings'        => 10,
		'media_max_bytes'           => 8388608,    // 8MB
		'media_daily_quota_bytes'   => 52428800,   // 50MB
		'temp_media_retention_hours' => 24,
		'appointment_expire_grace_hours' => 24,
		'completion_confirmation'   => 'owner',    // owner | both
		'checklist_required_for_completion' => false,
		'default_language'         => 'ko',
		'supported_languages'      => array( 'ko', 'en', 'vi' ),
	);

	public static function activate() {
		self::check_requirements();
		self::create_tables();
		TD_Capabilities::register_on_activation();
		self::set_default_options();
		self::maybe_add_postmeta_index();
		TD_DB::set_schema_version( TODAYDEAL_SCHEMA_VERSION );
		update_option( 'todaydeal_activated_at', current_time( 'mysql', true ) );

		// Register CPT before flushing rewrite rules.
		TD_Post_Type::register();
		TD_Criteria::register();
		flush_rewrite_rules();
	}

	/**
	 * Runs on every request (cheap: one option read) so a schema bump takes
	 * effect immediately on an already-active install, without requiring a
	 * deactivate/reactivate cycle.
	 */
	public static function maybe_upgrade() {
		if ( TD_DB::get_schema_version() >= TODAYDEAL_SCHEMA_VERSION ) {
			return;
		}
		self::create_tables();
		TD_Capabilities::register_on_activation();
		TD_DB::set_schema_version( TODAYDEAL_SCHEMA_VERSION );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'todaydeal_cron_expire_listings' );
		wp_clear_scheduled_hook( 'todaydeal_cron_expire_appointments' );
		wp_clear_scheduled_hook( 'todaydeal_cron_cleanup_temp_media' );
		flush_rewrite_rules();
	}

	private static function set_default_options() {
		$current = get_option( 'todaydeal_settings', array() );
		$merged  = wp_parse_args( $current, self::OPTION_DEFAULTS );
		update_option( 'todaydeal_settings', $merged );
	}

	/**
	 * Returns a list of unmet requirements, empty when everything is OK.
	 * Section 4 / 4.1: WordPress/PHP version, WooCommerce active, product_cat taxonomy.
	 */
	public static function check_requirements() {
		$issues = array();

		if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
			$issues[] = sprintf( 'PHP 8.0 이상이 필요합니다. 현재: %s', PHP_VERSION );
		}

		global $wp_version;
		if ( version_compare( $wp_version, '6.4', '<' ) ) {
			$issues[] = sprintf( 'WordPress 6.4 이상이 필요합니다. 현재: %s', $wp_version );
		}

		if ( ! self::is_woocommerce_active() ) {
			$issues[] = 'WooCommerce가 활성화되어 있지 않습니다. 카테고리(product_cat) 기능을 사용할 수 없습니다.';
		} elseif ( ! taxonomy_exists( 'product_cat' ) ) {
			$issues[] = 'product_cat taxonomy가 등록되어 있지 않습니다.';
		}

		return $issues;
	}

	public static function is_woocommerce_active() {
		return in_array(
			'woocommerce/woocommerce.php',
			apply_filters( 'active_plugins', get_option( 'active_plugins', array() ) ),
			true
		) || function_exists( 'WC' );
	}

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$refresh_tokens = TD_DB::refresh_tokens();
		$idempotency    = TD_DB::idempotency();
		$appointments   = TD_DB::appointments();
		$appt_history   = TD_DB::appointment_history();
		$audit_log      = TD_DB::audit_log();
		$nonces         = TD_DB::nonces();
		$ratings        = TD_DB::ratings();

		$sql = array();

		$sql[] = "CREATE TABLE {$refresh_tokens} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			token_hash CHAR(64) NOT NULL,
			device VARCHAR(191) NULL,
			issued_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			revoked_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY user_id (user_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$idempotency} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			key_hash CHAR(64) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			operation VARCHAR(64) NOT NULL,
			response_ref LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY key_op_user (key_hash, operation, user_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$appointments} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			listing_id BIGINT UNSIGNED NOT NULL,
			listing_type VARCHAR(10) NOT NULL,
			owner_user_id BIGINT UNSIGNED NOT NULL,
			counterpart_user_id BIGINT UNSIGNED NOT NULL,
			meet_at DATETIME NULL,
			meet_place VARCHAR(255) NULL,
			meet_address VARCHAR(255) NULL,
			meet_latitude VARCHAR(16) NULL,
			meet_longitude VARCHAR(16) NULL,
			status VARCHAR(20) NOT NULL,
			accepted_listing_id BIGINT UNSIGNED NULL,
			manager_user_id BIGINT UNSIGNED NULL,
			manager_attending TINYINT(1) NULL,
			owner_completed_at DATETIME NULL,
			counterpart_completed_at DATETIME NULL,
			idempotency_key_hash CHAR(64) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY accepted_listing_unique (accepted_listing_id),
			KEY listing_id (listing_id),
			KEY owner_user_id (owner_user_id),
			KEY counterpart_user_id (counterpart_user_id),
			KEY status (status)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$appt_history} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			appointment_id BIGINT UNSIGNED NOT NULL,
			actor_id BIGINT UNSIGNED NULL,
			from_status VARCHAR(20) NULL,
			to_status VARCHAR(20) NOT NULL,
			is_system TINYINT(1) NOT NULL DEFAULT 0,
			reason VARCHAR(255) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY appointment_id (appointment_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$audit_log} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			actor_id BIGINT UNSIGNED NULL,
			action VARCHAR(64) NOT NULL,
			object_type VARCHAR(32) NOT NULL,
			object_id BIGINT UNSIGNED NULL,
			request_id VARCHAR(64) NULL,
			result VARCHAR(16) NOT NULL,
			details LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY object (object_type, object_id),
			KEY action (action)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$nonces} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			nonce VARCHAR(191) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY nonce (nonce)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$ratings} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			appointment_id BIGINT UNSIGNED NOT NULL,
			listing_id BIGINT UNSIGNED NOT NULL,
			rater_user_id BIGINT UNSIGNED NOT NULL,
			ratee_user_id BIGINT UNSIGNED NOT NULL,
			rating TINYINT UNSIGNED NOT NULL,
			criteria_scores LONGTEXT NULL,
			comment TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY appointment_rater (appointment_id, rater_user_id),
			KEY ratee_user_id (ratee_user_id)
		) {$charset_collate};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * Section 20.1: add a prefix index on wp_postmeta to speed up
	 * _todaydeal_filter_key / _todaydeal_status lookups. Non-fatal if it fails.
	 */
	private static function maybe_add_postmeta_index() {
		global $wpdb;

		try {
			$index_name = 'td_meta_key_value';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- index name is a static literal, no user input.
			$existing = $wpdb->get_row( "SHOW INDEX FROM {$wpdb->postmeta} WHERE Key_name = '{$index_name}'" );

			if ( $existing ) {
				return;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- index name/columns are static, no user input.
			$result = $wpdb->query(
				"ALTER TABLE {$wpdb->postmeta} ADD INDEX {$index_name} (meta_key(64), meta_value(32), post_id)"
			);

			if ( false === $result ) {
				update_option( 'todaydeal_postmeta_index_warning', $wpdb->last_error );
			} else {
				delete_option( 'todaydeal_postmeta_index_warning' );
			}
		} catch ( Throwable $e ) {
			// Non-fatal per spec 20.1: index creation must never block activation.
			update_option( 'todaydeal_postmeta_index_warning', $e->getMessage() );
		}
	}
}
