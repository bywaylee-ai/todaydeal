<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post-transaction ratings: after a trade appointment is completed, each
 * participant may rate the other once (overall 1-5 stars + optional
 * per-criterion scores drawn from the listing's category, see TD_Criteria).
 * Added at the user's request; not part of the original functional spec.
 */
class TD_Ratings {

	const MIN_SCORE = 1;
	const MAX_SCORE = 5;

	/**
	 * @param array $input { rating: int, criteria: {criterion_id: int}, comment?: string }
	 * @return array|WP_Error
	 */
	public static function submit( $appointment_id, $rater_user_id, array $input ) {
		$appointment = TD_Appointments::find_row( $appointment_id );
		if ( ! $appointment ) {
			return TD_Response::error( 'APPOINTMENT_NOT_FOUND', '약속을 찾을 수 없습니다.' );
		}

		if ( 'completed' !== $appointment['status'] ) {
			return TD_Response::error( 'INVALID_STATUS_TRANSITION', '완료된 약속만 평가할 수 있습니다.' );
		}

		$owner_id       = (int) $appointment['owner_user_id'];
		$counterpart_id = (int) $appointment['counterpart_user_id'];

		if ( $rater_user_id !== $owner_id && $rater_user_id !== $counterpart_id ) {
			return TD_Response::error( 'FORBIDDEN', '이 약속의 참여자만 평가할 수 있습니다.' );
		}

		$ratee_id = ( $rater_user_id === $owner_id ) ? $counterpart_id : $owner_id;

		global $wpdb;
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . TD_DB::ratings() . ' WHERE appointment_id = %d AND rater_user_id = %d',
				$appointment_id,
				$rater_user_id
			)
		);
		if ( $existing ) {
			return TD_Response::error( 'DUPLICATE_REQUEST', '이미 이 약속에 대한 평가를 남겼습니다.' );
		}

		$overall = (int) ( $input['rating'] ?? 0 );
		if ( $overall < self::MIN_SCORE || $overall > self::MAX_SCORE ) {
			return TD_Response::error( 'VALIDATION_ERROR', '전체 별점은 1~5 사이여야 합니다.', array( 'field' => 'rating' ) );
		}

		$category_ids    = wp_list_pluck( TD_Taxonomy_Adapter::get_listing_terms( $appointment['listing_id'] ), 'term_id' );
		$criteria_scores = self::validate_criteria( (array) ( $input['criteria'] ?? array() ), $category_ids );
		if ( is_wp_error( $criteria_scores ) ) {
			return $criteria_scores;
		}

		$wpdb->insert(
			TD_DB::ratings(),
			array(
				'appointment_id'  => $appointment_id,
				'listing_id'      => $appointment['listing_id'],
				'rater_user_id'   => $rater_user_id,
				'ratee_user_id'   => $ratee_id,
				'rating'          => $overall,
				'criteria_scores' => wp_json_encode( $criteria_scores, JSON_UNESCAPED_UNICODE ),
				'comment'         => isset( $input['comment'] ) ? sanitize_textarea_field( $input['comment'] ) : null,
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
		);

		$rating_id = (int) $wpdb->insert_id;
		self::recompute_user_rating( $ratee_id );

		TD_Audit_Log::record( 'rating_submit', 'appointment', $appointment_id, 'success', array( 'ratee_user_id' => $ratee_id ), $rater_user_id );

		return self::to_response( $rating_id );
	}

	/**
	 * @return array {criterion_id: score}|WP_Error, keyed by string id.
	 */
	private static function validate_criteria( array $raw, array $category_ids ) {
		$clean = array();
		foreach ( $raw as $criterion_id => $score ) {
			$criterion_id = (int) $criterion_id;
			$score        = (int) $score;

			if ( ! TD_Criteria::is_valid_for_categories( $criterion_id, $category_ids ) ) {
				return TD_Response::error(
					'VALIDATION_ERROR',
					'이 거래글의 카테고리에 속하지 않는 평가 항목입니다.',
					array( 'field' => 'criteria.' . $criterion_id )
				);
			}
			if ( $score < self::MIN_SCORE || $score > self::MAX_SCORE ) {
				return TD_Response::error(
					'VALIDATION_ERROR',
					'하위 별점 항목 점수는 1~5 사이여야 합니다.',
					array( 'field' => 'criteria.' . $criterion_id )
				);
			}
			$clean[ (string) $criterion_id ] = $score;
		}
		return $clean;
	}

	private static function recompute_user_rating( $user_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT AVG(rating) AS avg_rating, COUNT(*) AS cnt FROM ' . TD_DB::ratings() . ' WHERE ratee_user_id = %d',
				$user_id
			)
		);

		$count = $row ? (int) $row->cnt : 0;
		update_user_meta( $user_id, TD_Users::META_RATING_COUNT, $count );
		update_user_meta( $user_id, TD_Users::META_RATING_AVG, $count ? round( (float) $row->avg_rating, 2 ) : '' );
	}

	public static function find_row( $rating_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . TD_DB::ratings() . ' WHERE id = %d', $rating_id ),
			ARRAY_A
		);
	}

	public static function to_response( $rating_id ) {
		$row = self::find_row( $rating_id );
		return $row ? self::row_to_response( $row ) : null;
	}

	private static function row_to_response( array $row ) {
		return array(
			'rating_id'       => (int) $row['id'],
			'appointment_id'  => (int) $row['appointment_id'],
			'listing_id'      => (int) $row['listing_id'],
			'rater_user_id'   => (int) $row['rater_user_id'],
			'ratee_user_id'   => (int) $row['ratee_user_id'],
			'rating'          => (int) $row['rating'],
			'criteria'        => json_decode( $row['criteria_scores'] ?: '{}', true ),
			'comment'         => $row['comment'],
			'created_at'      => $row['created_at'] ? mysql2date( 'c', $row['created_at'], false ) : null,
		);
	}

	/**
	 * @return array every rating tied to one appointment (both sides, if given).
	 */
	public static function for_appointment( $appointment_id ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . TD_DB::ratings() . ' WHERE appointment_id = %d', $appointment_id ),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'row_to_response' ), $rows );
	}
}
