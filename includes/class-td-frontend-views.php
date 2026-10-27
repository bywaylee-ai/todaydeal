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

	// 목록 1행당 열 수: 선택 가능 값과 기본값.
	const COLUMN_CHOICES  = array( 2, 3, 4, 5, 6 );
	const DEFAULT_COLUMNS = 4;

	// 비로그인 "거래하기": 로그인/가입 후 이 거래글로 돌아와 대화를 자동으로 연다.
	const DEAL_PARAM  = 'td_deal';
	const DEAL_COOKIE = 'td_deal_listing';

	public static function init() {
		add_action( 'login_init', array( __CLASS__, 'remember_deal_listing' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'deal_login_redirect' ), 10, 3 );
		add_action( 'pre_get_posts', array( __CLASS__, 'restrict_visibility' ) );
		add_filter( 'template_include', array( __CLASS__, 'template_include' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_shortcode( 'todaydeal_listings', array( __CLASS__, 'shortcode_listings' ) );
	}

	public static function enqueue() {
		if ( is_singular( TD_Post_Type::POST_TYPE ) || is_post_type_archive( TD_Post_Type::POST_TYPE ) || self::is_listings_page() ) {
			wp_enqueue_style( 'todaydeal-frontend', plugins_url( 'assets/css/frontend.css', TODAYDEAL_PLUGIN_FILE ), array(), TODAYDEAL_VERSION );
		}
		if ( is_singular( TD_Post_Type::POST_TYPE ) && is_user_logged_in() ) {
			wp_enqueue_script( 'todaydeal-chat', plugins_url( 'assets/js/chat.js', TODAYDEAL_PLUGIN_FILE ), array(), TODAYDEAL_VERSION, true );
			wp_localize_script( 'todaydeal-chat', 'tdChat', array(
				'root'  => esc_url_raw( rest_url( 'todaydeal/v1/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			) );
		}
	}

	/** 로그인 버튼 URL: 로그인 후 `?td_deal={ID}`로 돌아온다. */
	public static function deal_login_url( $listing_id ) {
		return wp_login_url( add_query_arg( self::DEAL_PARAM, (int) $listing_id, get_permalink( $listing_id ) ) );
	}

	/**
	 * wp-login.php 진입 시 redirect_to의 거래글 ID를 쿠키에 기억한다.
	 * 회원가입 화면으로 넘어가면 redirect_to가 사라지므로 쿠키로 유지한다.
	 */
	public static function remember_deal_listing() {
		$redirect = isset( $_REQUEST['redirect_to'] ) ? wp_unslash( $_REQUEST['redirect_to'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only an integer is extracted.
		parse_str( (string) wp_parse_url( $redirect, PHP_URL_QUERY ), $query );
		$listing_id = absint( $query[ self::DEAL_PARAM ] ?? 0 );
		if ( $listing_id && TD_Post_Type::POST_TYPE === get_post_type( $listing_id ) ) {
			setcookie( self::DEAL_COOKIE, (string) $listing_id, time() + DAY_IN_SECONDS, SITECOOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		}
	}

	/** 로그인 성공 시 기억한 거래글로 보내고 쿠키를 지운다. */
	public static function deal_login_redirect( $redirect_to, $requested, $user ) {
		if ( ! $user instanceof WP_User || empty( $_COOKIE[ self::DEAL_COOKIE ] ) ) {
			return $redirect_to;
		}
		$listing_id = absint( $_COOKIE[ self::DEAL_COOKIE ] );
		setcookie( self::DEAL_COOKIE, '', time() - HOUR_IN_SECONDS, SITECOOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		if ( TD_Post_Type::POST_TYPE !== get_post_type( $listing_id ) ) {
			return $redirect_to;
		}
		return add_query_arg( self::DEAL_PARAM, $listing_id, get_permalink( $listing_id ) );
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
			$vars       = self::list_query_vars( self::archive_filters() );
			$meta_query = array_merge( $meta_query, $vars['meta_query'] );
			unset( $vars['meta_query'] );
			foreach ( $vars as $key => $value ) {
				$query->set( $key, $value );
			}
		}

		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Query vars for the listing list (archive and the [todaydeal_listings]
	 * page) from the sanitized filters: 검색어 q, 카테고리 category, 도시 city,
	 * 유형 type, 정렬 sort. Does not include the public-status clause.
	 */
	public static function list_query_vars( array $filters ) {
		$vars = array(
			'posts_per_page' => 24, // 2·3·4·6열에서 줄이 딱 맞는 개수
			'meta_query'     => array(),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( $filters['type'] ) {
			$vars['meta_query'][] = array( 'key' => TD_Post_Type::META_LISTING_TYPE, 'value' => $filters['type'] );
		}
		if ( $filters['city'] ) {
			$vars['meta_query'][] = array(
				'key'     => TD_Post_Type::META_CITY,
				'value'   => TD_Listings::PLACE_PRESETS[ $filters['city'] ]['aliases'],
				'compare' => 'IN',
			);
		}
		if ( '' !== $filters['q'] ) {
			$vars['s'] = $filters['q'];
		}
		// WooCommerce가 꺼져 product_cat이 없으면 카테고리 필터는 무시한다(0건 방지).
		if ( $filters['category'] && TD_Taxonomy_Adapter::is_available() ) {
			$vars['tax_query'] = array( array( 'taxonomy' => TD_Taxonomy_Adapter::TAXONOMY, 'field' => 'term_id', 'terms' => $filters['category'] ) );
		}
		if ( $filters['sort'] ) {
			$vars['meta_key'] = TD_Post_Type::META_PRICE_MIN;
			$vars['orderby']  = 'meta_value_num';
			$vars['order']    = 'price_asc' === $filters['sort'] ? 'ASC' : 'DESC';
		}
		return $vars;
	}

	/** Builds the list query for a non-archive page ([todaydeal_listings]). */
	public static function page_list_query( array $filters ) {
		$vars = self::list_query_vars( $filters );
		$vars['meta_query'][] = array( 'key' => TD_Post_Type::META_STATUS, 'value' => TD_Listings::PUBLIC_VISIBLE_STATUSES, 'compare' => 'IN' );
		return new WP_Query( array_merge( $vars, array(
			'post_type'   => TD_Post_Type::POST_TYPE,
			'post_status' => 'publish',
			'paged'       => self::current_page(),
		) ) );
	}

	/** Current page number on archives, pages (`paged`) and a static front page (`page`). */
	public static function current_page() {
		return max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
	}

	/** Whether the current request is a page holding [todaydeal_listings]. */
	public static function is_listings_page() {
		$post = get_queried_object();
		return is_page() && $post instanceof WP_Post && has_shortcode( $post->post_content, 'todaydeal_listings' );
	}

	/**
	 * [todaydeal_listings] - the listing list as page content. Pages holding it
	 * normally use templates/page-todaydeal-listings.php (full width); this is
	 * the fallback when the content is rendered elsewhere.
	 */
	public static function shortcode_listings() {
		wp_enqueue_style( 'todaydeal-frontend', plugins_url( 'assets/css/frontend.css', TODAYDEAL_PLUGIN_FILE ), array(), TODAYDEAL_VERSION );
		$td_query = self::page_list_query( self::archive_filters() );
		$td_base  = get_permalink();
		ob_start();
		include TODAYDEAL_PLUGIN_DIR . 'templates/parts/listings.php';
		wp_reset_postdata();
		return ob_get_clean();
	}

	/**
	 * Sanitized archive filter values from the query string.
	 */
	public static function archive_filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$city = sanitize_key( $_GET['city'] ?? '' );
		$sort = sanitize_key( $_GET['sort'] ?? '' );
		$type = sanitize_key( $_GET['type'] ?? '' );
		$filters = array(
			'q'    => sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ),
			'category' => absint( $_GET['category'] ?? 0 ),
			'city' => isset( TD_Listings::PLACE_PRESETS[ $city ] ) ? $city : '',
			'sort' => in_array( $sort, array( 'price_asc', 'price_desc' ), true ) ? $sort : '',
			'type' => in_array( $type, TD_Listings::TYPES, true ) ? $type : '',
			// 1행당 열 수. 기본값(DEFAULT_COLUMNS)은 URL에 남기지 않도록 0으로 둔다.
			'cols' => in_array( absint( $_GET['cols'] ?? 0 ), self::COLUMN_CHOICES, true ) && self::DEFAULT_COLUMNS !== absint( $_GET['cols'] ) ? absint( $_GET['cols'] ) : 0,
		);
		// phpcs:enable
		return $filters;
	}

	/** List URL with the given filters (empty values dropped); defaults to the archive. */
	public static function archive_url( array $filters, $base = '' ) {
		return add_query_arg( array_filter( $filters ), $base ?: get_post_type_archive_link( TD_Post_Type::POST_TYPE ) );
	}

	/** "한국어 <베트남어>" 병기 라벨. */
	public static function label( $ko, $vi ) {
		return esc_html( $ko ) . ' <span class="td-vi" lang="vi">' . esc_html( $vi ) . '</span>';
	}

	public static function price_html( array $listing ) {
		if ( 'sell' === $listing['listing_type'] ) {
			return esc_html( number_format( (float) $listing['price_min'] ) . ' ' . $listing['currency'] );
		}
		return esc_html( number_format( (float) $listing['price_min'] ) . ' ~ ' . number_format( (float) $listing['price_max'] ) . ' ' . $listing['currency'] );
	}

	/** "3일 전 · 3 ngày trước" */
	public static function time_ago( $iso8601 ) {
		$diff = max( 0, time() - strtotime( $iso8601 ) );
		$units = array(
			array( YEAR_IN_SECONDS, '년', 'năm' ),
			array( MONTH_IN_SECONDS, '개월', 'tháng' ),
			array( WEEK_IN_SECONDS, '주', 'tuần' ),
			array( DAY_IN_SECONDS, '일', 'ngày' ),
			array( HOUR_IN_SECONDS, '시간', 'giờ' ),
			array( MINUTE_IN_SECONDS, '분', 'phút' ),
		);
		foreach ( $units as $unit ) {
			if ( $diff >= $unit[0] ) {
				$n = (int) floor( $diff / $unit[0] );
				return self::label( "{$n}{$unit[1]} 전", "{$n} {$unit[2]} trước" );
			}
		}
		return self::label( '방금 전', 'Vừa xong' );
	}

	/** Short location: "Nha Trang · Smile Jewelry" */
	public static function location_text( array $listing ) {
		return trim( implode( ' · ', array_filter( array( $listing['location']['city'], $listing['location']['place_name'] ) ) ) );
	}

	/** Status flag for non-open listings (예약중/거래완료/기간만료), else ''. */
	public static function status_flag( array $listing ) {
		return 'open' === $listing['status'] ? '' : $listing['status_label'];
	}

	/** Top-level categories for the chip row (Uncategorized excluded). */
	public static function top_categories() {
		if ( ! TD_Taxonomy_Adapter::is_available() ) {
			return array();
		}
		$terms = get_terms( array( 'taxonomy' => TD_Taxonomy_Adapter::TAXONOMY, 'hide_empty' => false, 'parent' => 0 ) );
		return is_wp_error( $terms ) ? array() : array_values( array_filter( $terms, function ( $t ) {
			return 'uncategorized' !== $t->slug;
		} ) );
	}

	/** URL of the page holding [todaydeal_listing_form], or ''. */
	public static function register_url() {
		$pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 's' => '[todaydeal_listing_form', 'posts_per_page' => 1, 'fields' => 'ids' ) );
		return $pages ? get_permalink( $pages[0] ) : '';
	}

	/**
	 * One listing card. `$layout` = 'row' (archive list) or 'grid' (similar listings).
	 */
	public static function render_card( $post_id, $layout = 'row' ) {
		$listing = TD_Listings::to_response( $post_id );
		$thumb   = $listing['media_ids'][0] ?? null;
		$img     = $thumb ? wp_get_attachment_image_url( $thumb, 'medium' ) : '';
		$flag    = self::status_flag( $listing );
		$cats    = wp_list_pluck( TD_Taxonomy_Adapter::get_listing_terms( $post_id ), 'name' );
		$thumb_html = sprintf(
			'<div class="td-thumb">%s%s%s</div>',
			$img ? '<img src="' . esc_url( $img ) . '" alt="" loading="lazy" />' : '',
			$flag ? '<span class="td-flag">' . esc_html( $flag ) . '</span>' : '',
			count( $listing['media_ids'] ) > 1 ? '<span class="td-count">' . count( $listing['media_ids'] ) . '</span>' : ''
		);
		$tags = '';
		if ( $listing['condition'] ) {
			$tags .= '<span class="td-tag">' . esc_html( $listing['condition'] ) . '</span>';
		}
		if ( $cats ) {
			$tags .= '<span class="td-tag">' . esc_html( end( $cats ) ) . '</span>';
		}
		if ( 'buy' === $listing['listing_type'] ) {
			$tags = '<span class="td-tag">' . esc_html( $listing['type_label'] ) . '</span>' . $tags;
		}
		$link = get_permalink( $post_id );

		if ( 'grid' === $layout ) {
			printf(
				'<a class="td-gcard" href="%s">%s<div class="td-gbody"><h3 class="td-item-title">%s</h3><span class="td-price">%s</span><span class="td-item-meta">%s</span></div></a>',
				esc_url( $link ),
				$thumb_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
				esc_html( $listing['title'] ),
				self::price_html( $listing ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $listing['location']['city'] )
			);
			return;
		}

		if ( 'card' === $layout ) {
			printf(
				'<a class="td-gcard" href="%s">%s<div class="td-gbody"><h2 class="td-item-title">%s</h2><div class="td-item-tags">%s</div><span class="td-price">%s</span><div class="td-item-meta"><span>%s</span><span>%s</span></div></div></a>',
				esc_url( $link ),
				$thumb_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $listing['title'] ),
				$tags, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				self::price_html( $listing ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( self::location_text( $listing ) ),
				self::time_ago( $listing['created_at'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
			return;
		}

		$owner = TD_Users::public_profile( $listing['owner_user_id'] );
		printf(
			'<a class="td-item" href="%s">%s<div class="td-item-body"><p class="td-item-title">%s</p><div class="td-item-tags">%s</div><span class="td-price">%s</span><div class="td-item-meta"><span>%s</span><span>%s</span><span>%s</span></div></div></a>',
			esc_url( $link ),
			$thumb_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html( $listing['title'] ),
			$tags, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			self::price_html( $listing ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html( self::location_text( $listing ) ),
			self::time_ago( $listing['created_at'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html( is_wp_error( $owner ) ? '' : $owner['nickname'] )
		);
	}

	/**
	 * get_header()/get_footer() when the theme has header.php/footer.php;
	 * otherwise (block themes) the plugin's default ones, which avoids the
	 * "Theme without header.php is deprecated" notice.
	 */
	public static function header() {
		if ( self::theme_has( 'header.php' ) ) {
			get_header();
		} else {
			require TODAYDEAL_PLUGIN_DIR . 'templates/header.php';
		}
	}

	public static function footer() {
		if ( self::theme_has( 'footer.php' ) ) {
			get_footer();
		} else {
			require TODAYDEAL_PLUGIN_DIR . 'templates/footer.php';
		}
	}

	// Not locate_template(): it falls back to wp-includes/theme-compat/, the
	// very files that trigger the deprecation notice.
	private static function theme_has( $file ) {
		return file_exists( get_stylesheet_directory() . '/' . $file )
			|| file_exists( get_template_directory() . '/' . $file );
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
		if ( self::is_listings_page() ) {
			return TODAYDEAL_PLUGIN_DIR . 'templates/page-todaydeal-listings.php';
		}
		return $template;
	}
}
