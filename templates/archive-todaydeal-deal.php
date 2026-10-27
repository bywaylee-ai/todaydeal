<?php
/**
 * Public archive view for todaydeal_deal listings. The body lives in
 * templates/parts/listings.php (shared with the [todaydeal_listings] page).
 * Data comes from TD_Listings::to_response() - the same normalization the
 * REST API uses (spec 9.1 "공개 전환"). Filters are applied to the main
 * query in TD_Frontend_Views::restrict_visibility().
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

TD_Frontend_Views::header();

$td_query = $GLOBALS['wp_query'];
$td_base  = get_post_type_archive_link( TD_Post_Type::POST_TYPE );
require TODAYDEAL_PLUGIN_DIR . 'templates/parts/listings.php';

TD_Frontend_Views::footer();
