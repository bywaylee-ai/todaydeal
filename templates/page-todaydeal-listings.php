<?php
/**
 * Full-width template for a page holding [todaydeal_listings] (e.g. the
 * "TodayDeal Listing" page, usable as the static homepage). Renders the
 * same list as the archive instead of the theme's narrow page content.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

TD_Frontend_Views::header();

$td_query = TD_Frontend_Views::page_list_query( TD_Frontend_Views::archive_filters() );
$td_base  = is_front_page() ? home_url( '/' ) : get_permalink( get_queried_object_id() );
require TODAYDEAL_PLUGIN_DIR . 'templates/parts/listings.php';
wp_reset_postdata();

TD_Frontend_Views::footer();
