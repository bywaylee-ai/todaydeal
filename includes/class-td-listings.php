<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listing (`todaydeal_deal`) CRUD, type validation, status rules
 * (spec section 9).
 */
class TD_Listings {

	const TYPES        = array( 'sell', 'buy' );
	const STATUSES     = array( 'draft', 'open', 'reserved', 'completed', 'hidden', 'expired', 'deleted' );
	// Statuses that count toward the category/registration-limit "active" bucket.
	const ACTIVE_STATUSES = array( 'open', 'reserved' );

	private static function forbidden_fields_for( $listing_type ) {
		return 'sell' === $listing_type
			? array( 'condition_preference', 'search_radius_km' )
			: array( 'condition', 'item_usage_period' );
	}

	private static function settings() {
		return get_option( 'todaydeal_settings', TD_Install::OPTION_DEFAULTS );
	}

	/* ---------------------------------------------------------------
	 * Create
	 * ------------------------------------------------------------- */

	public static function create( $user_id, array $input, $idempotency_key = '' ) {
		$cached = TD_Idempotency::find( $idempotency_key, 'create_listing', $user_id );
		if ( null !== $cached ) {
			return $cached;
		}

		$listing_type = sanitize_key( $input['listing_type'] ?? '' );
		if ( ! in_array( $listing_type, self::TYPES, true ) ) {
			return TD_Response::error( 'VALIDATION_ERROR', 'listing_type은 sell 또는 buy여야 합니다.', array( 'field' => 'listing_type' ) );
		}

		$limit_check = self::check_registration_limits( $user_id, $listing_type );
		if ( is_wp_error( $limit_check ) ) {
			return $limit_check;
		}

		$validated = self::validate_type_fields( $listing_type, $input );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		if ( ! empty( $input['category_ids'] ) && ! TD_Taxonomy_Adapter::is_available() ) {
			return TD_Response::error( 'TAXONOMY_UNAVAILABLE', 'WooCommerce가 비활성화되어 카테고리를 지정할 수 없습니다.' );
		}

		$media_ids = array_map( 'intval', (array) ( $input['media_ids'] ?? array() ) );
		if ( ! empty( $media_ids ) ) {
			$owned = TD_Media::validate_ownership( $media_ids, $user_id );
			if ( is_wp_error( $owned ) ) {
				return $owned;
			}
		}

		$category_ids = array_map( 'intval', (array) ( $input['category_ids'] ?? array() ) );
		$extra_values = TD_Category_Fields::validate_and_sanitize( $category_ids, (array) ( $input['extra_fields'] ?? array() ) );
		if ( is_wp_error( $extra_values ) ) {
			return $extra_values;
		}

		list( $title, $description, $translations, $source_language ) = self::extract_content( $input );
		if ( '' === trim( (string) $title ) ) {
			return TD_Response::error( 'VALIDATION_ERROR', '제목을 입력해주세요.', array( 'field' => 'title' ) );
		}

		$status = sanitize_key( $input['status'] ?? 'open' );
		if ( ! in_array( $status, array( 'draft', 'open' ), true ) ) {
			return TD_Response::error( 'INVALID_STATUS_TRANSITION', '등록 시점에는 draft 또는 open 상태만 지정할 수 있습니다.' );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => TD_Post_Type::POST_TYPE,
				'post_title'   => wp_strip_all_tags( $title ),
				'post_content' => wp_kses_post( $description ),
				'post_status'  => TD_Post_Type::wp_post_status_for( $status ),
				'post_author'  => $user_id,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return TD_Response::error( 'INTERNAL_ERROR', '거래글 생성에 실패했습니다.' );
		}

		update_post_meta( $post_id, TD_Post_Type::META_OWNER, $user_id );
		update_post_meta( $post_id, TD_Post_Type::META_LISTING_TYPE, $listing_type );
		update_post_meta( $post_id, TD_Post_Type::META_SOURCE_LANGUAGE, $source_language );
		update_post_meta( $post_id, TD_Post_Type::META_TRANSLATIONS, wp_json_encode( $translations, JSON_UNESCAPED_UNICODE ) );

		self::write_common_fields( $post_id, $listing_type, $input );
		self::write_status( $post_id, $status, $listing_type );

		if ( ! empty( $input['category_ids'] ) ) {
			TD_Taxonomy_Adapter::set_listing_terms( $post_id, array_map( 'intval', (array) $input['category_ids'] ) );
		}

		if ( ! empty( $media_ids ) ) {
			update_post_meta( $post_id, TD_Post_Type::META_MEDIA_IDS, wp_json_encode( $media_ids, JSON_UNESCAPED_UNICODE ) );
			TD_Media::attach_to_listing( $media_ids, $post_id );
		}

		TD_Category_Fields::save_values_for_listing( $post_id, $extra_values );

		TD_Audit_Log::record( 'listing_create', 'listing', $post_id, 'success', array( 'listing_type' => $listing_type ) );

		$response = self::to_response( $post_id, $user_id );
		TD_Idempotency::store( $idempotency_key, 'create_listing', $user_id, $response );

		return $response;
	}

	private static function extract_content( array $input ) {
		$source_language = sanitize_text_field( $input['source_language'] ?? self::settings()['default_language'] ?? 'ko' );
		$translations     = array();

		if ( ! empty( $input['translations'] ) && is_array( $input['translations'] ) ) {
			foreach ( $input['translations'] as $lang => $fields ) {
				$translations[ sanitize_text_field( $lang ) ] = array(
					'title'       => sanitize_text_field( $fields['title'] ?? '' ),
					'description' => wp_kses_post( $fields['description'] ?? '' ),
				);
			}
		}

		if ( isset( $translations[ $source_language ] ) ) {
			$title       = $translations[ $source_language ]['title'];
			$description = $translations[ $source_language ]['description'];
		} else {
			$title       = sanitize_text_field( $input['title'] ?? '' );
			$description = wp_kses_post( $input['description'] ?? '' );
		}

		return array( $title, $description, $translations, $source_language );
	}

	/**
	 * 9.5 유형별 필드 규칙.
	 */
	private static function validate_type_fields( $listing_type, array $input ) {
		foreach ( self::forbidden_fields_for( $listing_type ) as $field ) {
			if ( array_key_exists( $field, $input ) && '' !== $input[ $field ] && null !== $input[ $field ] ) {
				return TD_Response::error(
					'INVALID_LISTING_TYPE_FIELD',
					sprintf( '%s 유형에서는 %s 필드를 사용할 수 없습니다.', $listing_type, $field ),
					array( 'field' => $field )
				);
			}
		}

		$location = $input['location'] ?? array();
		foreach ( array( 'country', 'city', 'latitude', 'longitude' ) as $required_loc_field ) {
			if ( ! isset( $location[ $required_loc_field ] ) || '' === $location[ $required_loc_field ] ) {
				return TD_Response::error( 'VALIDATION_ERROR', '위치 정보(국가/도시/좌표)는 필수입니다.', array( 'field' => 'location.' . $required_loc_field ) );
			}
		}

		$media_ids = array_map( 'intval', (array) ( $input['media_ids'] ?? array() ) );

		if ( 'sell' === $listing_type ) {
			if ( ! isset( $input['price_min'] ) || '' === $input['price_min'] ) {
				return TD_Response::error( 'VALIDATION_ERROR', 'price_min은 필수입니다.', array( 'field' => 'price_min' ) );
			}
			if ( count( $media_ids ) < 1 ) {
				return TD_Response::error( 'VALIDATION_ERROR', '이미지를 1장 이상 등록해야 합니다.', array( 'field' => 'media_ids' ) );
			}
			if ( count( $media_ids ) > 5 ) {
				return TD_Response::error( 'VALIDATION_ERROR', '이미지는 최대 5장까지 등록할 수 있습니다.', array( 'field' => 'media_ids' ) );
			}
		} else { // buy
			if ( empty( $input['expires_at'] ) ) {
				return TD_Response::error( 'VALIDATION_ERROR', 'expires_at은 필수입니다.', array( 'field' => 'expires_at' ) );
			}
			if ( count( $media_ids ) > 3 ) {
				return TD_Response::error( 'VALIDATION_ERROR', '이미지는 최대 3장까지 등록할 수 있습니다.', array( 'field' => 'media_ids' ) );
			}
			if ( isset( $input['price_min'], $input['price_max'] ) && '' !== $input['price_min'] && '' !== $input['price_max'] ) {
				if ( (float) $input['price_max'] < (float) $input['price_min'] ) {
					return TD_Response::error( 'VALIDATION_ERROR', 'price_max는 price_min 이상이어야 합니다.', array( 'field' => 'price_max' ) );
				}
			}
		}

		return true;
	}

	private static function write_common_fields( $post_id, $listing_type, array $input ) {
		$price_min = isset( $input['price_min'] ) ? (string) $input['price_min'] : '';
		$price_max = 'sell' === $listing_type ? $price_min : (string) ( $input['price_max'] ?? '' );

		update_post_meta( $post_id, TD_Post_Type::META_PRICE_MIN, $price_min );
		update_post_meta( $post_id, TD_Post_Type::META_PRICE_MAX, $price_max );
		update_post_meta( $post_id, TD_Post_Type::META_CURRENCY, sanitize_text_field( $input['currency'] ?? '' ) );
		update_post_meta( $post_id, TD_Post_Type::META_NEGOTIABLE, ! empty( $input['price_negotiable'] ) ? 1 : 0 );

		if ( 'sell' === $listing_type ) {
			update_post_meta( $post_id, TD_Post_Type::META_CONDITION, sanitize_text_field( $input['condition'] ?? '' ) );
			update_post_meta( $post_id, TD_Post_Type::META_USAGE_PERIOD, sanitize_text_field( $input['item_usage_period'] ?? '' ) );
			update_post_meta( $post_id, TD_Post_Type::META_QUANTITY, 1 );
		} else {
			update_post_meta( $post_id, TD_Post_Type::META_CONDITION_PREF, sanitize_text_field( $input['condition_preference'] ?? '' ) );
			update_post_meta( $post_id, TD_Post_Type::META_QUANTITY, max( 1, (int) ( $input['quantity'] ?? 1 ) ) );
			if ( isset( $input['location']['search_radius_km'] ) ) {
				update_post_meta( $post_id, TD_Post_Type::META_SEARCH_RADIUS, (float) $input['location']['search_radius_km'] );
			}
		}

		$location = $input['location'] ?? array();
		update_post_meta( $post_id, TD_Post_Type::META_COUNTRY, strtoupper( sanitize_text_field( $location['country'] ?? '' ) ) );
		update_post_meta( $post_id, TD_Post_Type::META_CITY, sanitize_text_field( $location['city'] ?? '' ) );
		update_post_meta( $post_id, TD_Post_Type::META_PLACE_NAME, sanitize_text_field( $location['place_name'] ?? '' ) );
		update_post_meta( $post_id, TD_Post_Type::META_LAT, self::format_coordinate( $location['latitude'] ?? null ) );
		update_post_meta( $post_id, TD_Post_Type::META_LNG, self::format_coordinate( $location['longitude'] ?? null ) );

		update_post_meta( $post_id, TD_Post_Type::META_PREFERRED_PLACE, sanitize_text_field( $input['preferred_place'] ?? '' ) );
		update_post_meta( $post_id, TD_Post_Type::META_AVAILABLE_TIME, sanitize_text_field( $input['available_time'] ?? '' ) );
		update_post_meta( $post_id, TD_Post_Type::META_AVAILABLE_LANGS, wp_json_encode( array_map( 'sanitize_text_field', (array) ( $input['available_languages'] ?? array() ) ), JSON_UNESCAPED_UNICODE ) );

		if ( ! empty( $input['expires_at'] ) ) {
			update_post_meta( $post_id, TD_Post_Type::META_EXPIRES_AT, self::to_utc_mysql( $input['expires_at'] ) );
		} elseif ( 'sell' === $listing_type ) {
			$days = (int) self::settings()['default_listing_ttl_days'];
			update_post_meta( $post_id, TD_Post_Type::META_EXPIRES_AT, gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ) );
		}
	}

	private static function format_coordinate( $value ) {
		if ( null === $value || '' === $value ) {
			return '';
		}
		return sprintf( '%+.6f', (float) $value );
	}

	private static function to_utc_mysql( $iso8601 ) {
		$timestamp = strtotime( $iso8601 );
		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : '';
	}

	private static function to_iso8601( $mysql_gmt_datetime ) {
		return $mysql_gmt_datetime ? mysql2date( 'c', $mysql_gmt_datetime, false ) : null;
	}

	private static function write_status( $post_id, $status, $listing_type ) {
		$country = get_post_meta( $post_id, TD_Post_Type::META_COUNTRY, true );
		update_post_meta( $post_id, TD_Post_Type::META_STATUS, $status );
		update_post_meta( $post_id, TD_Post_Type::META_FILTER_KEY, TD_Post_Type::build_filter_key( $listing_type, $status, $country ) );

		if ( 'deleted' === $status ) {
			wp_trash_post( $post_id );
		} elseif ( get_post_status( $post_id ) !== TD_Post_Type::wp_post_status_for( $status ) ) {
			wp_update_post( array( 'ID' => $post_id, 'post_status' => TD_Post_Type::wp_post_status_for( $status ) ) );
		}
	}

	private static function check_registration_limits( $user_id, $listing_type ) {
		$settings = self::settings();
		$max_open = 'sell' === $listing_type ? (int) $settings['max_open_listings_sell'] : (int) $settings['max_open_listings_buy'];

		$open_count = self::count_by_owner( $user_id, $listing_type, self::ACTIVE_STATUSES );
		if ( $open_count >= $max_open ) {
			return TD_Response::error( 'LISTING_LIMIT_EXCEEDED', '동시 등록 가능한 거래글 수를 초과했습니다.' );
		}

		$today_count = self::count_created_today( $user_id );
		if ( $today_count >= (int) $settings['max_daily_listings'] ) {
			return TD_Response::error( 'LISTING_LIMIT_EXCEEDED', '일일 거래글 등록 한도를 초과했습니다.' );
		}

		return true;
	}

	private static function count_by_owner( $user_id, $listing_type, array $statuses ) {
		$query = new WP_Query(
			array(
				'post_type'      => TD_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'author'         => $user_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array( 'key' => TD_Post_Type::META_LISTING_TYPE, 'value' => $listing_type ),
					array( 'key' => TD_Post_Type::META_STATUS, 'value' => $statuses, 'compare' => 'IN' ),
				),
			)
		);
		return (int) $query->found_posts;
	}

	private static function count_created_today( $user_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => TD_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'trash' ),
				'author'         => $user_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'date_query'     => array( array( 'after' => '-24 hours' ) ),
			)
		);
		return (int) $query->found_posts;
	}

	/* ---------------------------------------------------------------
	 * Read
	 * ------------------------------------------------------------- */

	public static function get( $post_id, $viewer_user_id = 0 ) {
		$post = get_post( $post_id );
		if ( ! $post || TD_Post_Type::POST_TYPE !== $post->post_type || 'trash' === $post->post_status ) {
			return TD_Response::error( 'LISTING_NOT_FOUND', '거래글을 찾을 수 없습니다.' );
		}
		return self::to_response( $post_id, $viewer_user_id );
	}

	public static function owner_id( $post_id ) {
		return (int) get_post_meta( $post_id, TD_Post_Type::META_OWNER, true );
	}

	public static function to_response( $post_id, $viewer_user_id = 0 ) {
		$post         = get_post( $post_id );
		$listing_type = get_post_meta( $post_id, TD_Post_Type::META_LISTING_TYPE, true );
		$status       = get_post_meta( $post_id, TD_Post_Type::META_STATUS, true );
		$owner_id     = self::owner_id( $post_id );
		$price_min    = get_post_meta( $post_id, TD_Post_Type::META_PRICE_MIN, true );
		$price_max    = get_post_meta( $post_id, TD_Post_Type::META_PRICE_MAX, true );
		$media_ids    = json_decode( get_post_meta( $post_id, TD_Post_Type::META_MEDIA_IDS, true ) ?: '[]', true );
		$categories   = TD_Taxonomy_Adapter::get_listing_terms( $post_id );

		$response = array(
			'listing_id'          => $post_id,
			'listing_type'        => $listing_type,
			'owner_user_id'       => $owner_id,
			'title'               => get_the_title( $post_id ),
			'description'         => $post->post_content,
			'category_ids'        => wp_list_pluck( $categories, 'term_id' ),
			'price_min'           => $price_min,
			'price_max'           => $price_max,
			'price'               => 'sell' === $listing_type ? $price_min : null,
			'currency'            => get_post_meta( $post_id, TD_Post_Type::META_CURRENCY, true ),
			'price_negotiable'    => (bool) get_post_meta( $post_id, TD_Post_Type::META_NEGOTIABLE, true ),
			'condition'           => get_post_meta( $post_id, TD_Post_Type::META_CONDITION, true ) ?: null,
			'condition_preference' => get_post_meta( $post_id, TD_Post_Type::META_CONDITION_PREF, true ) ?: null,
			'item_usage_period'   => get_post_meta( $post_id, TD_Post_Type::META_USAGE_PERIOD, true ) ?: null,
			'quantity'            => (int) get_post_meta( $post_id, TD_Post_Type::META_QUANTITY, true ),
			'search_radius_km'    => get_post_meta( $post_id, TD_Post_Type::META_SEARCH_RADIUS, true ) ?: null,
			'location'            => array(
				'country'     => get_post_meta( $post_id, TD_Post_Type::META_COUNTRY, true ),
				'city'        => get_post_meta( $post_id, TD_Post_Type::META_CITY, true ),
				'place_name'  => get_post_meta( $post_id, TD_Post_Type::META_PLACE_NAME, true ),
				'latitude'    => (float) get_post_meta( $post_id, TD_Post_Type::META_LAT, true ),
				'longitude'   => (float) get_post_meta( $post_id, TD_Post_Type::META_LNG, true ),
			),
			'preferred_place'     => get_post_meta( $post_id, TD_Post_Type::META_PREFERRED_PLACE, true ) ?: null,
			'available_time'      => get_post_meta( $post_id, TD_Post_Type::META_AVAILABLE_TIME, true ) ?: null,
			'available_languages' => json_decode( get_post_meta( $post_id, TD_Post_Type::META_AVAILABLE_LANGS, true ) ?: '[]', true ),
			'media_ids'           => array_map( 'intval', (array) $media_ids ),
			'status'              => $status,
			'status_label'        => TD_Post_Type::status_label( $status, $listing_type ),
			'type_label'          => TD_Post_Type::type_label( $listing_type ),
			'expires_at'          => get_post_meta( $post_id, TD_Post_Type::META_EXPIRES_AT, true ) ?: null,
			'created_at'          => self::to_iso8601( $post->post_date_gmt ),
			'updated_at'          => self::to_iso8601( $post->post_modified_gmt ),
			'source_language'     => get_post_meta( $post_id, TD_Post_Type::META_SOURCE_LANGUAGE, true ),
			'extra_fields'        => TD_Category_Fields::get_values_for_listing( $post_id ),
		);

		// WP-LST-017: counterpart activity is owner/admin-only.
		if ( $viewer_user_id && ( $viewer_user_id === $owner_id || current_user_can( 'todaydeal_manage_all_listings' ) ) ) {
			$response['active_appointment_id'] = TD_Appointments::active_accepted_id_for_listing( $post_id );
			$response['appointment_count']     = TD_Appointments::active_count_for_listing( $post_id );
		}

		return $response;
	}

	/* ---------------------------------------------------------------
	 * List / search (spec 15.3 + 20.1)
	 * ------------------------------------------------------------- */

	public static function query_list( array $params, $viewer_user_id = 0 ) {
		$type = sanitize_key( $params['type'] ?? 'sell' );
		if ( ! in_array( $type, array( 'sell', 'buy', 'all' ), true ) ) {
			$type = 'sell';
		}

		$limit  = min( 100, max( 1, (int) ( $params['limit'] ?? 20 ) ) );
		$cursor = max( 0, (int) base64_decode( (string) ( $params['cursor'] ?? '' ) ) );

		$meta_query = array( 'relation' => 'AND' );

		if ( 'all' !== $type ) {
			$status = sanitize_key( $params['status'] ?? 'open' );
			$country = isset( $params['country'] ) ? strtoupper( sanitize_text_field( $params['country'] ) ) : '';
			if ( $country ) {
				$meta_query[] = array( 'key' => TD_Post_Type::META_FILTER_KEY, 'value' => TD_Post_Type::build_filter_key( $type, $status, $country ) );
			} else {
				$meta_query[] = array( 'key' => TD_Post_Type::META_LISTING_TYPE, 'value' => $type );
				$meta_query[] = array( 'key' => TD_Post_Type::META_STATUS, 'value' => $status );
			}
		} else {
			$meta_query[] = array( 'key' => TD_Post_Type::META_STATUS, 'value' => sanitize_key( $params['status'] ?? 'open' ) );
		}

		if ( ! empty( $params['category'] ) ) {
			$term_ids = array_map( 'intval', (array) $params['category'] );
		}

		$query_args = array(
			'post_type'      => TD_Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'offset'         => $cursor,
			'meta_query'     => $meta_query,
			'fields'         => 'ids',
		);

		if ( ! empty( $params['search'] ) ) {
			$query_args['s'] = sanitize_text_field( $params['search'] );
		}

		if ( ! empty( $term_ids ) ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => TD_Taxonomy_Adapter::TAXONOMY,
					'field'    => 'term_id',
					'terms'    => $term_ids,
				),
			);
		}

		$has_distance = isset( $params['lat'], $params['lng'] ) && '' !== $params['lat'] && '' !== $params['lng'];
		if ( $has_distance ) {
			$lat    = (float) $params['lat'];
			$lng    = (float) $params['lng'];
			$radius = isset( $params['radius_km'] ) ? (float) $params['radius_km'] : 20.0;
			$box    = self::bounding_box( $lat, $lng, $radius );

			$query_args['meta_query'][] = array( 'key' => TD_Post_Type::META_LAT, 'value' => array( sprintf( '%+.6f', $box['min_lat'] ), sprintf( '%+.6f', $box['max_lat'] ) ), 'compare' => 'BETWEEN', 'type' => 'DECIMAL(10,6)' );
			$query_args['meta_query'][] = array( 'key' => TD_Post_Type::META_LNG, 'value' => array( sprintf( '%+.6f', $box['min_lng'] ), sprintf( '%+.6f', $box['max_lng'] ) ), 'compare' => 'BETWEEN', 'type' => 'DECIMAL(10,6)' );
			$query_args['posts_per_page'] = -1; // candidates only, real limit applied after distance sort
			$query_args['offset']         = 0;
		}

		$sort = sanitize_key( $params['sort'] ?? 'recent' );
		$price_meta_key = 'buy' === $type ? TD_Post_Type::META_PRICE_MAX : TD_Post_Type::META_PRICE_MIN;

		if ( ! $has_distance ) {
			switch ( $sort ) {
				case 'price_asc':
					$query_args['meta_key'] = $price_meta_key;
					$query_args['orderby']  = 'meta_value_num';
					$query_args['order']    = 'ASC';
					break;
				case 'price_desc':
					$query_args['meta_key'] = $price_meta_key;
					$query_args['orderby']  = 'meta_value_num';
					$query_args['order']    = 'DESC';
					break;
				default:
					$query_args['orderby'] = 'date';
					$query_args['order']   = 'DESC';
			}
		}

		$query = new WP_Query( $query_args );
		$ids   = $query->posts;

		$meta = array();
		if ( 'all' === $type ) {
			$meta['price_basis'] = 'mixed (sell: price_min, buy: price_max)';
		}

		if ( $has_distance ) {
			$items = array();
			foreach ( $ids as $id ) {
				$lat2 = (float) get_post_meta( $id, TD_Post_Type::META_LAT, true );
				$lng2 = (float) get_post_meta( $id, TD_Post_Type::META_LNG, true );
				$dist = self::haversine_km( $lat, $lng, $lat2, $lng2 );
				if ( $dist <= $radius ) {
					$items[] = array( 'id' => $id, 'distance_km' => $dist );
				}
			}
			usort( $items, fn( $a, $b ) => $a['distance_km'] <=> $b['distance_km'] );
			$page  = array_slice( $items, $cursor, $limit );
			$more  = ( $cursor + $limit ) < count( $items );
			$responses = array();
			foreach ( $page as $item ) {
				$r                 = self::to_response( $item['id'], $viewer_user_id );
				$r['distance_km']  = round( $item['distance_km'], 2 );
				$responses[]       = $r;
			}
			$next_cursor = $more ? base64_encode( (string) ( $cursor + $limit ) ) : null;
			return array( $responses, $next_cursor, $meta );
		}

		$responses = array_map( fn( $id ) => self::to_response( $id, $viewer_user_id ), $ids );
		$more      = count( $ids ) === $limit;
		$next_cursor = $more ? base64_encode( (string) ( $cursor + $limit ) ) : null;

		return array( $responses, $next_cursor, $meta );
	}

	private static function bounding_box( $lat, $lng, $radius_km ) {
		$lat_delta = $radius_km / 111.0;
		$lng_delta = $radius_km / ( 111.0 * max( 0.01, cos( deg2rad( $lat ) ) ) );
		return array(
			'min_lat' => $lat - $lat_delta,
			'max_lat' => $lat + $lat_delta,
			'min_lng' => $lng - $lng_delta,
			'max_lng' => $lng + $lng_delta,
		);
	}

	private static function haversine_km( $lat1, $lng1, $lat2, $lng2 ) {
		$earth_radius = 6371;
		$d_lat = deg2rad( $lat2 - $lat1 );
		$d_lng = deg2rad( $lng2 - $lng1 );
		$a = sin( $d_lat / 2 ) ** 2 + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $d_lng / 2 ) ** 2;
		$c = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
		return $earth_radius * $c;
	}

	public static function query_my_listings( $user_id, array $params ) {
		$args = array(
			'post_type'      => TD_Post_Type::POST_TYPE,
			'author'         => $user_id,
			'post_status'    => array( 'publish', 'draft', 'trash' ),
			'posts_per_page' => min( 100, max( 1, (int) ( $params['limit'] ?? 20 ) ) ),
			'offset'         => max( 0, (int) base64_decode( (string) ( $params['cursor'] ?? '' ) ) ),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'meta_query'     => array(),
		);

		if ( ! empty( $params['type'] ) ) {
			$args['meta_query'][] = array( 'key' => TD_Post_Type::META_LISTING_TYPE, 'value' => sanitize_key( $params['type'] ) );
		}
		if ( ! empty( $params['status'] ) ) {
			$args['meta_query'][] = array( 'key' => TD_Post_Type::META_STATUS, 'value' => sanitize_key( $params['status'] ) );
		}

		$query = new WP_Query( $args );
		$items = array_map( fn( $id ) => self::to_response( $id, $user_id ), $query->posts );

		return array( $items, null, array() );
	}

	/* ---------------------------------------------------------------
	 * Update / status / delete
	 * ------------------------------------------------------------- */

	private static function assert_owner_or_error( $post_id, $user_id ) {
		$post = get_post( $post_id );
		if ( ! $post || TD_Post_Type::POST_TYPE !== $post->post_type ) {
			return TD_Response::error( 'LISTING_NOT_FOUND', '거래글을 찾을 수 없습니다.' );
		}
		if ( self::owner_id( $post_id ) !== (int) $user_id && ! current_user_can( 'todaydeal_manage_all_listings' ) ) {
			return TD_Response::error( 'FORBIDDEN_LISTING_OWNER', '이 거래글을 수정할 권한이 없습니다.' );
		}
		return $post;
	}

	const EDITABLE_FIELDS = array(
		'title', 'description', 'translations', 'category_ids', 'price_min', 'price_max', 'currency',
		'price_negotiable', 'condition', 'condition_preference', 'item_usage_period', 'quantity',
		'location', 'preferred_place', 'available_time', 'available_languages', 'media_ids', 'expires_at',
	);

	public static function update( $post_id, $user_id, array $input, $partial = true ) {
		$post = self::assert_owner_or_error( $post_id, $user_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$existing_type = get_post_meta( $post_id, TD_Post_Type::META_LISTING_TYPE, true );

		if ( $existing_type && array_key_exists( 'listing_type', $input ) && $input['listing_type'] !== $existing_type ) {
			return TD_Response::error( 'LISTING_TYPE_IMMUTABLE', 'listing_type은 변경할 수 없습니다.' );
		}

		// WP-LST-015: optimistic concurrency via caller-supplied expected_updated_at.
		if ( ! empty( $input['expected_updated_at'] ) ) {
			$current = self::to_iso8601( $post->post_modified_gmt );
			if ( strtotime( $input['expected_updated_at'] ) !== strtotime( $current ) ) {
				return TD_Response::error( 'VERSION_CONFLICT', '다른 요청이 먼저 이 거래글을 수정했습니다.' );
			}
		}

		// A post with no listing_type meta yet is being initialized for the
		// first time through this same path (e.g. the wp-admin editor, which
		// has WordPress create the post row before our save handler runs).
		$is_new = ! $existing_type;

		if ( $is_new ) {
			$listing_type = sanitize_key( $input['listing_type'] ?? '' );
			if ( ! in_array( $listing_type, self::TYPES, true ) ) {
				return TD_Response::error( 'VALIDATION_ERROR', 'listing_type은 sell 또는 buy여야 합니다.', array( 'field' => 'listing_type' ) );
			}
			update_post_meta( $post_id, TD_Post_Type::META_OWNER, $post->post_author );
			update_post_meta( $post_id, TD_Post_Type::META_LISTING_TYPE, $listing_type );
			update_post_meta( $post_id, TD_Post_Type::META_SOURCE_LANGUAGE, sanitize_text_field( $input['source_language'] ?? self::settings()['default_language'] ?? 'ko' ) );
			update_post_meta( $post_id, TD_Post_Type::META_TRANSLATIONS, wp_json_encode( array(), JSON_UNESCAPED_UNICODE ) );
		} else {
			$listing_type = $existing_type;
		}

		$merged = self::merge_for_validation( $post_id, $listing_type, $input, $partial );
		$validated = self::validate_type_fields( $listing_type, $merged );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		if ( array_key_exists( 'media_ids', $input ) && ! empty( $input['media_ids'] ) ) {
			$owned = TD_Media::validate_ownership( array_map( 'intval', $input['media_ids'] ), $user_id );
			if ( is_wp_error( $owned ) ) {
				return $owned;
			}
		}

		if ( array_key_exists( 'title', $input ) || array_key_exists( 'description', $input ) || array_key_exists( 'translations', $input ) ) {
			list( $title, $description, $translations, $source_language ) = self::extract_content( $merged );
			wp_update_post( array( 'ID' => $post_id, 'post_title' => wp_strip_all_tags( $title ), 'post_content' => wp_kses_post( $description ) ) );
			if ( ! empty( $translations ) ) {
				update_post_meta( $post_id, TD_Post_Type::META_TRANSLATIONS, wp_json_encode( $translations, JSON_UNESCAPED_UNICODE ) );
			}
		}

		self::write_common_fields( $post_id, $listing_type, $merged );

		if ( $is_new ) {
			// Mirrors create()'s own initial-status handling (only draft/open
			// may be set directly; anything else falls back to open).
			$initial_status = in_array( $input['status'] ?? 'open', array( 'draft', 'open' ), true ) ? $input['status'] : 'open';
			self::write_status( $post_id, $initial_status, $listing_type );
		}

		if ( array_key_exists( 'category_ids', $input ) || array_key_exists( 'extra_fields', $input ) ) {
			if ( array_key_exists( 'category_ids', $input ) && ! TD_Taxonomy_Adapter::is_available() ) {
				return TD_Response::error( 'TAXONOMY_UNAVAILABLE', 'WooCommerce가 비활성화되어 카테고리를 변경할 수 없습니다.' );
			}

			$effective_category_ids = array_key_exists( 'category_ids', $input )
				? array_map( 'intval', (array) $input['category_ids'] )
				: wp_list_pluck( TD_Taxonomy_Adapter::get_listing_terms( $post_id ), 'term_id' );

			$incoming_values = array_key_exists( 'extra_fields', $input ) ? (array) $input['extra_fields'] : TD_Category_Fields::get_values_for_listing( $post_id );
			$extra_values    = TD_Category_Fields::validate_and_sanitize( $effective_category_ids, $incoming_values );
			if ( is_wp_error( $extra_values ) ) {
				return $extra_values;
			}

			if ( array_key_exists( 'category_ids', $input ) ) {
				TD_Taxonomy_Adapter::set_listing_terms( $post_id, $effective_category_ids );
			}
			TD_Category_Fields::save_values_for_listing( $post_id, $extra_values );
		}

		if ( array_key_exists( 'media_ids', $input ) ) {
			$media_ids = array_map( 'intval', (array) $input['media_ids'] );
			update_post_meta( $post_id, TD_Post_Type::META_MEDIA_IDS, wp_json_encode( $media_ids, JSON_UNESCAPED_UNICODE ) );
			TD_Media::attach_to_listing( $media_ids, $post_id );
		}

		// Refresh filter key (status/country/type may have shifted via the fields above).
		$status  = get_post_meta( $post_id, TD_Post_Type::META_STATUS, true );
		self::write_status( $post_id, $status, $listing_type );

		// A meta-only update doesn't touch post_modified on its own; bump it
		// explicitly so `updated_at` / WP-LST-015 optimistic locking stay accurate.
		wp_update_post( array( 'ID' => $post_id ) );

		TD_Audit_Log::record( 'listing_update', 'listing', $post_id, 'success' );

		return self::to_response( $post_id, $user_id );
	}

	/**
	 * Builds a full field set for type validation by overlaying partial
	 * update input onto the listing's current stored values.
	 */
	private static function merge_for_validation( $post_id, $listing_type, array $input, $partial ) {
		if ( ! $partial ) {
			return $input;
		}

		$current = array(
			'price_min'            => get_post_meta( $post_id, TD_Post_Type::META_PRICE_MIN, true ),
			'price_max'            => get_post_meta( $post_id, TD_Post_Type::META_PRICE_MAX, true ),
			'condition'            => get_post_meta( $post_id, TD_Post_Type::META_CONDITION, true ),
			'condition_preference' => get_post_meta( $post_id, TD_Post_Type::META_CONDITION_PREF, true ),
			'item_usage_period'    => get_post_meta( $post_id, TD_Post_Type::META_USAGE_PERIOD, true ),
			'search_radius_km'     => get_post_meta( $post_id, TD_Post_Type::META_SEARCH_RADIUS, true ),
			'expires_at'           => get_post_meta( $post_id, TD_Post_Type::META_EXPIRES_AT, true ),
			'media_ids'            => json_decode( get_post_meta( $post_id, TD_Post_Type::META_MEDIA_IDS, true ) ?: '[]', true ),
			'location'             => array(
				'country'    => get_post_meta( $post_id, TD_Post_Type::META_COUNTRY, true ),
				'city'       => get_post_meta( $post_id, TD_Post_Type::META_CITY, true ),
				'place_name' => get_post_meta( $post_id, TD_Post_Type::META_PLACE_NAME, true ),
				'latitude'   => get_post_meta( $post_id, TD_Post_Type::META_LAT, true ),
				'longitude'  => get_post_meta( $post_id, TD_Post_Type::META_LNG, true ),
			),
		);

		return array_replace_recursive( $current, $input );
	}

	const MANUAL_TRANSITIONS = array(
		'draft'     => array( 'open' ),
		'open'      => array( 'hidden', 'completed' ),
		'hidden'    => array( 'open' ),
		'completed' => array( 'open' ),
		'expired'   => array( 'open' ),
	);

	/**
	 * WP-LST-007/013: manual status changes. `reserved`/`expired` are
	 * system-derived and rejected here (they're set via self::apply_status()).
	 */
	public static function change_status( $post_id, $user_id, $new_status ) {
		$post = self::assert_owner_or_error( $post_id, $user_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( in_array( $new_status, array( 'reserved', 'expired' ), true ) ) {
			return TD_Response::error( 'INVALID_STATUS_TRANSITION', '해당 상태는 시스템에 의해서만 파생됩니다.' );
		}

		$listing_type   = get_post_meta( $post_id, TD_Post_Type::META_LISTING_TYPE, true );
		$current_status = get_post_meta( $post_id, TD_Post_Type::META_STATUS, true );

		if ( 'deleted' === $new_status ) {
			return self::trash( $post_id, $user_id );
		}

		$allowed = self::MANUAL_TRANSITIONS[ $current_status ] ?? array();
		if ( ! in_array( $new_status, $allowed, true ) ) {
			return TD_Response::error( 'INVALID_STATUS_TRANSITION', "{$current_status}에서 {$new_status}(으)로 전이할 수 없습니다." );
		}

		if ( 'completed' === $new_status ) {
			TD_Appointments::cancel_all_active_for_listing( $post_id, 'listing_marked_completed' );
		}

		if ( in_array( $current_status, array( 'expired', 'completed' ), true ) && 'open' === $new_status ) {
			// WP-LST-013: re-listing refreshes the expiry window.
			$days = (int) self::settings()['default_listing_ttl_days'];
			update_post_meta( $post_id, TD_Post_Type::META_EXPIRES_AT, gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ) );
		}

		self::apply_status( $post_id, $new_status, 'user', $user_id, null );

		return self::to_response( $post_id, $user_id );
	}

	/**
	 * Internal status setter used both by user-triggered transitions and by
	 * system-derived transitions (appointments, cron). Always keeps the
	 * filter key in sync and writes an audit entry.
	 */
	public static function apply_status( $post_id, $new_status, $actor_type = 'system', $actor_id = null, $appointment_id = null ) {
		$listing_type = get_post_meta( $post_id, TD_Post_Type::META_LISTING_TYPE, true );
		self::write_status( $post_id, $new_status, $listing_type );

		TD_Audit_Log::record(
			'listing_status_change',
			'listing',
			$post_id,
			'success',
			array(
				'to_status'      => $new_status,
				'actor_type'     => $actor_type,
				'appointment_id' => $appointment_id,
			),
			$actor_id
		);
	}

	public static function trash( $post_id, $user_id ) {
		$post = self::assert_owner_or_error( $post_id, $user_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		TD_Appointments::cancel_all_active_for_listing( $post_id, 'listing_deleted' );
		self::apply_status( $post_id, 'deleted', 'user', $user_id, null );

		TD_Audit_Log::record( 'listing_delete', 'listing', $post_id, 'success' );

		return true;
	}

	/* ---------------------------------------------------------------
	 * System hooks: account deletion, cron auto-expire
	 * ------------------------------------------------------------- */

	public static function hide_all_open_for_owner( $user_id, $reason ) {
		$query = new WP_Query(
			array(
				'post_type'      => TD_Post_Type::POST_TYPE,
				'author'         => $user_id,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array( 'key' => TD_Post_Type::META_STATUS, 'value' => self::ACTIVE_STATUSES, 'compare' => 'IN' ),
				),
			)
		);
		foreach ( $query->posts as $post_id ) {
			TD_Appointments::cancel_all_active_for_listing( $post_id, $reason );
			self::apply_status( $post_id, 'hidden', 'system', null, null );
		}
	}

	/**
	 * WP-LST-012: cron-driven auto expiry. `reserved` listings are skipped
	 * per spec 9.3 rule 5 - they're re-evaluated once the appointment ends.
	 */
	public static function run_auto_expire() {
		$now = current_time( 'mysql', true );

		$query = new WP_Query(
			array(
				'post_type'      => TD_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_query'     => array(
					array( 'key' => TD_Post_Type::META_STATUS, 'value' => 'open' ),
					array( 'key' => TD_Post_Type::META_EXPIRES_AT, 'value' => $now, 'compare' => '<', 'type' => 'DATETIME' ),
					array( 'key' => TD_Post_Type::META_EXPIRES_AT, 'value' => '', 'compare' => '!=' ),
				),
			)
		);

		foreach ( $query->posts as $post_id ) {
			self::apply_status( $post_id, 'expired', 'system', null, null );
		}
	}
}
