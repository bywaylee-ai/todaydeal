<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `todaydeal_deal` post type (spec section 9.1). Not public, no REST
 * exposure via core - the plugin's own /todaydeal/v1 routes are the only API.
 */
class TD_Post_Type {

	const POST_TYPE = 'todaydeal_deal';

	const META_OWNER          = '_todaydeal_owner_user_id';
	const META_LISTING_TYPE   = '_todaydeal_listing_type';
	const META_STATUS         = '_todaydeal_status';
	const META_FILTER_KEY     = '_todaydeal_filter_key';
	const META_COUNTRY        = '_todaydeal_country';
	const META_CITY           = '_todaydeal_city';
	const META_PLACE_NAME     = '_todaydeal_place_name';
	const META_LAT            = '_todaydeal_latitude';
	const META_LNG            = '_todaydeal_longitude';
	const META_SEARCH_RADIUS  = '_todaydeal_search_radius_km';
	const META_PRICE_MIN      = '_todaydeal_price_min';
	const META_PRICE_MAX      = '_todaydeal_price_max';
	const META_CURRENCY       = '_todaydeal_currency';
	const META_NEGOTIABLE     = '_todaydeal_price_negotiable';
	const META_CONDITION      = '_todaydeal_condition';
	const META_CONDITION_PREF = '_todaydeal_condition_preference';
	const META_USAGE_PERIOD   = '_todaydeal_item_usage_period';
	const META_QUANTITY       = '_todaydeal_quantity';
	const META_PREFERRED_PLACE = '_todaydeal_preferred_place';
	const META_AVAILABLE_TIME = '_todaydeal_available_time';
	const META_AVAILABLE_LANGS = '_todaydeal_available_languages';
	const META_MEDIA_IDS      = '_todaydeal_media_ids';
	const META_EXPIRES_AT     = '_todaydeal_expires_at';
	const META_SOURCE_LANGUAGE = '_todaydeal_source_language';
	const META_TRANSLATIONS   = '_todaydeal_translations';

	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'               => 'TodayDeal 거래글',
				// Full public conversion (spec 9.1/28, user-requested): real
				// single pages + archive. Visibility of hidden/draft/deleted
				// listings is enforced separately by TD_Frontend_Views'
				// pre_get_posts guard, since `hidden` is stored as
				// post_status=publish + a meta flag (spec 9.3) - public=>true
				// alone would otherwise leak it.
				'public'              => true,
				'publicly_queryable'  => true,
				'show_in_nav_menus'   => true,
				'show_in_admin_bar'   => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => false,
				'supports'            => array( 'title', 'editor', 'author', 'custom-fields' ),
				'capability_type'     => array( 'todaydeal_deal', 'todaydeal_deals' ),
				'map_meta_cap'        => true,
				'hierarchical'        => false,
				'has_archive'         => 'listings',
				'rewrite'             => array( 'slug' => 'listings', 'with_front' => false ),
				// Stays excluded from WP's generic multi-post-type search:
				// that query mixes post types under one meta_query, which
				// can't cleanly exclude hidden/draft listings without also
				// mis-filtering unrelated post types in the same results.
				// The dedicated archive (own pre_get_posts guard) and the
				// REST API's own search param are the supported ways to
				// browse listings.
				'exclude_from_search' => true,
			)
		);

		TD_Taxonomy_Adapter::register_for_listings( self::POST_TYPE );
	}

	/**
	 * WordPress-facing status for a given app-facing status (spec 9.3 table).
	 */
	public static function wp_post_status_for( $status ) {
		if ( in_array( $status, array( 'draft' ), true ) ) {
			return 'draft';
		}
		if ( 'deleted' === $status ) {
			return 'trash';
		}
		return 'publish';
	}

	public static function status_labels() {
		return array(
			'draft'     => array( 'sell' => '작성중', 'buy' => '작성중' ),
			'open'      => array( 'sell' => '판매중', 'buy' => '구하는 중' ),
			'reserved'  => array( 'sell' => '예약중', 'buy' => '예약중' ),
			'completed' => array( 'sell' => '판매완료', 'buy' => '구매완료' ),
			'hidden'    => array( 'sell' => '숨김', 'buy' => '숨김' ),
			'expired'   => array( 'sell' => '기간 만료', 'buy' => '기간 만료' ),
			'deleted'   => array( 'sell' => '삭제됨', 'buy' => '삭제됨' ),
		);
	}

	public static function status_label( $status, $listing_type ) {
		$labels = self::status_labels();
		return $labels[ $status ][ $listing_type ] ?? $status;
	}

	public static function type_label( $listing_type ) {
		return 'sell' === $listing_type ? '팔아요' : '구해요';
	}

	public static function build_filter_key( $listing_type, $status, $country ) {
		return $listing_type . '|' . $status . '|' . $country;
	}
}
