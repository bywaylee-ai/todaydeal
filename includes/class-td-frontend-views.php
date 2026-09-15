<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public single/archive views for `todaydeal_deal` (spec 9.1 "공개 전환",
 * user-requested full public conversion). Provides its own templates via
 * `template_include` so the feature works regardless of the active theme
 * (spec 기본 전제 #2), and enforces that `draft`/`hidden`/`deleted`
 * listings are never reachable here even by direct URL - `hidden` is
 * stored as WP post_status=publish + a meta flag (spec 9.3), so
 * `publicly_queryable` alone is not enough to keep it hidden.
 */
class TD_Frontend_Views {

	public static function init() {
		add_action( 'pre_get_posts', array( __CLASS__, 'restrict_visibility' ) );
		add_filter( 'template_include', array( __CLASS__, 'template_include' ) );
	}

	public static function restrict_visibility( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$is_archive = $query->is_post_type_archive( TD_Post_Type::POST_TYPE );
		// Not `$query->is_singular( POST_TYPE )`: at this point (pre_get_posts,
		// before the query has actually run) get_queried_object() can't
		// resolve a slug-based singular query yet, so the post-type-specific
		// overload of is_singular() always returns false here. The plain
		// query var is already normalized by parse_query() though.
		$is_singular = $query->is_singular() && TD_Post_Type::POST_TYPE === $query->get( 'post_type' );

		if ( ! $is_archive && ! $is_singular ) {
			return;
		}

		$meta_query   = (array) $query->get( 'meta_query' );
		$meta_query[] = array(
			'key'     => TD_Post_Type::META_STATUS,
			'value'   => TD_Listings::PUBLIC_VISIBLE_STATUSES,
			'compare' => 'IN',
		);

		if ( $is_archive ) {
			$type = sanitize_key( $_GET['type'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, no state change.
			if ( in_array( $type, TD_Listings::TYPES, true ) ) {
				$meta_query[] = array(
					'key'   => TD_Post_Type::META_LISTING_TYPE,
					'value' => $type,
				);
			}
			$query->set( 'posts_per_page', 12 );
			$query->set( 'orderby', 'date' );
			$query->set( 'order', 'DESC' );
		}

		$query->set( 'meta_query', $meta_query );
	}

	public static function template_include( $template ) {
		if ( is_404() ) {
			return $template;
		}
		if ( is_singular( TD_Post_Type::POST_TYPE ) ) {
			return TODAYDEAL_PLUGIN_DIR . 'templates/single-todaydeal-deal.php';
		}
		if ( is_post_type_archive( TD_Post_Type::POST_TYPE ) ) {
			return TODAYDEAL_PLUGIN_DIR . 'templates/archive-todaydeal-deal.php';
		}
		return $template;
	}
}
