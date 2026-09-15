<?php
/**
 * Plugin Name: TodayDeal
 * Description: TodayDeal 앱 전용 REST API 플러그인. 거래글(sell/buy), 회원, 인증, 거래 약속을 관리한다.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: TodayDeal
 * Text Domain: todaydeal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TODAYDEAL_VERSION', '0.1.0' );
define( 'TODAYDEAL_SCHEMA_VERSION', 4 );
define( 'TODAYDEAL_PLUGIN_FILE', __FILE__ );
define( 'TODAYDEAL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TODAYDEAL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once TODAYDEAL_PLUGIN_DIR . 'includes/class-td-db.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/class-td-capabilities.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/class-td-install.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/class-td-audit-log.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/class-td-response.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/class-td-jwt.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/class-td-rate-limiter.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/class-td-idempotency.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/class-td-app-server-auth.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/class-td-auth.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/class-td-users.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/class-td-taxonomy-adapter.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/class-td-post-type.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/class-td-frontend-views.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/class-td-media.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/class-td-category-fields.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/class-td-criteria.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/class-td-listings.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/class-td-appointments.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/class-td-ratings.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/class-td-cron.php';

require_once TODAYDEAL_PLUGIN_DIR . 'includes/rest/class-td-rest-auth-controller.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/rest/class-td-rest-users-controller.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/rest/class-td-rest-categories-controller.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/rest/class-td-rest-media-controller.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/rest/class-td-rest-listings-controller.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/rest/class-td-rest-appointments-controller.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/rest/class-td-rest-health-controller.php';

require_once TODAYDEAL_PLUGIN_DIR . 'includes/admin/class-td-admin.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/admin/class-td-admin-category-fields.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/admin/class-td-admin-criteria.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/admin/class-td-admin-listing-editor.php';
require_once TODAYDEAL_PLUGIN_DIR . 'includes/class-td-frontend-form.php';

require_once TODAYDEAL_PLUGIN_DIR . 'includes/class-td-core.php';

register_activation_hook( __FILE__, array( 'TD_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TD_Install', 'deactivate' ) );

TD_Core::instance();
