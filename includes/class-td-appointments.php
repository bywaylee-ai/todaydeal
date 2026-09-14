<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trade appointments: role derivation, single-accept constraint, derived
 * listing status (spec section 13).
 */
class TD_Appointments {

	const STATUSES        = array( 'proposed', 'accepted', 'rejected', 'cancelled', 'completed', 'expired' );
	const ACTIVE_STATUSES = array( 'proposed', 'accepted' );

	private static function settings() {
		return get_option( 'todaydeal_settings', TD_Install::OPTION_DEFAULTS );
	}

	/* ---------------------------------------------------------------
	 * Create (WP-APPT-001..003, 006..008)
	 * ------------------------------------------------------------- */

	public static function create( $requesting_user_id, array $input, $idempotency_key = '' ) {
		$cached = TD_Idempotency::find( $idempotency_key, 'create_appointment', $requesting_user_id );
		if ( null !== $cached ) {
			return $cached;
		}

		$listing_id = (int) ( $input['listing_id'] ?? 0 );
		$listing    = get_post( $listing_id );
		if ( ! $listing || TD_Post_Type::POST_TYPE !== $listing->post_type || 'trash' === $listing->post_status ) {
			return TD_Response::error( 'LISTING_NOT_FOUND', '거래글을 찾을 수 없습니다.' );
		}

		$listing_status = get_post_meta( $listing_id, TD_Post_Type::META_STATUS, true );
		if ( ! in_array( $listing_status, array( 'open', 'reserved' ), true ) ) {
			return TD_Response::error( 'INVALID_STATUS_TRANSITION', '현재 상태의 거래글에는 약속을 생성할 수 없습니다.' );
		}

		$owner_user_id       = (int) get_post_meta( $listing_id, TD_Post_Type::META_OWNER, true );
		$counterpart_user_id = $requesting_user_id;

		if ( $owner_user_id === $counterpart_user_id ) {
			return TD_Response::error( 'APPOINTMENT_SELF_NOT_ALLOWED', '본인 거래글에는 약속을 생성할 수 없습니다.' );
		}

		if ( self::has_active_pair( $listing_id, $counterpart_user_id ) ) {
			return TD_Response::error( 'APPOINTMENT_DUPLICATE_PARTY', '이미 진행 중인 약속이 있습니다.' );
		}

		global $wpdb;
		$now = current_time( 'mysql', true );

		$wpdb->insert(
			TD_DB::appointments(),
			array(
				'listing_id'           => $listing_id,
				'listing_type'         => get_post_meta( $listing_id, TD_Post_Type::META_LISTING_TYPE, true ),
				'owner_user_id'        => $owner_user_id,
				'counterpart_user_id'  => $counterpart_user_id,
				'meet_at'              => ! empty( $input['meet_at'] ) ? self::to_utc_mysql( $input['meet_at'] ) : null,
				'meet_place'           => sanitize_text_field( $input['meet_place'] ?? '' ),
				'meet_address'         => sanitize_text_field( $input['meet_address'] ?? '' ),
				'meet_latitude'        => self::format_coordinate( $input['meet_latitude'] ?? null ),
				'meet_longitude'       => self::format_coordinate( $input['meet_longitude'] ?? null ),
				'status'               => 'proposed',
				'idempotency_key_hash' => $idempotency_key ? hash( 'sha256', $idempotency_key ) : null,
				'created_at'           => $now,
				'updated_at'           => $now,
			),
			array( '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$appointment_id = (int) $wpdb->insert_id;
		self::record_history( $appointment_id, $counterpart_user_id, null, 'proposed', false, 'created' );
		TD_Audit_Log::record( 'appointment_create', 'appointment', $appointment_id, 'success', array( 'listing_id' => $listing_id ) );

		$response = self::to_response( $appointment_id );
		TD_Idempotency::store( $idempotency_key, 'create_appointment', $requesting_user_id, $response );

		return $response;
	}

	private static function has_active_pair( $listing_id, $counterpart_user_id ) {
		global $wpdb;
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . TD_DB::appointments() . " WHERE listing_id = %d AND counterpart_user_id = %d AND status IN ('proposed','accepted') LIMIT 1",
				$listing_id,
				$counterpart_user_id
			)
		);
		return (bool) $existing;
	}

	private static function to_utc_mysql( $iso8601 ) {
		$timestamp = strtotime( $iso8601 );
		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : null;
	}

	private static function to_iso8601( $mysql_gmt_datetime ) {
		return $mysql_gmt_datetime ? mysql2date( 'c', $mysql_gmt_datetime, false ) : null;
	}

	/**
	 * Same fixed-precision string format as TD_Listings (spec 20.1 rationale:
	 * fixed decimal places keep string-based comparisons/sorts consistent).
	 */
	private static function format_coordinate( $value ) {
		if ( null === $value || '' === $value ) {
			return '';
		}
		return sprintf( '%+.6f', (float) $value );
	}

	/* ---------------------------------------------------------------
	 * Read
	 * ------------------------------------------------------------- */

	public static function find_row( $appointment_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . TD_DB::appointments() . ' WHERE id = %d', $appointment_id ),
			ARRAY_A
		);
	}

	public static function get( $appointment_id, $viewer_user_id ) {
		$row = self::find_row( $appointment_id );
		if ( ! $row ) {
			return TD_Response::error( 'APPOINTMENT_NOT_FOUND', '약속을 찾을 수 없습니다.' );
		}
		if ( ! self::is_participant( $row, $viewer_user_id ) ) {
			return TD_Response::error( 'FORBIDDEN', '이 약속에 접근할 권한이 없습니다.' );
		}
		return self::row_to_response( $row );
	}

	private static function is_participant( array $row, $user_id ) {
		return (int) $row['owner_user_id'] === (int) $user_id
			|| (int) $row['counterpart_user_id'] === (int) $user_id
			|| current_user_can( 'todaydeal_manage_all_listings' );
	}

	public static function to_response( $appointment_id ) {
		$row = self::find_row( $appointment_id );
		return $row ? self::row_to_response( $row ) : null;
	}

	private static function row_to_response( array $row ) {
		$listing_type = $row['listing_type'];
		$seller_id    = 'sell' === $listing_type ? (int) $row['owner_user_id'] : (int) $row['counterpart_user_id'];
		$buyer_id     = 'sell' === $listing_type ? (int) $row['counterpart_user_id'] : (int) $row['owner_user_id'];

		return array(
			'appointment_id'       => (int) $row['id'],
			'listing_id'           => (int) $row['listing_id'],
			'listing_type'         => $listing_type,
			'owner_user_id'        => (int) $row['owner_user_id'],
			'counterpart_user_id'  => (int) $row['counterpart_user_id'],
			'seller_id'            => $seller_id,
			'buyer_id'             => $buyer_id,
			'status'               => $row['status'],
			'meet_at'              => self::to_iso8601( $row['meet_at'] ),
			'meet_place'           => $row['meet_place'],
			'meet_address'         => $row['meet_address'] ?: null,
			'meet_latitude'        => ( isset( $row['meet_latitude'] ) && '' !== $row['meet_latitude'] ) ? (float) $row['meet_latitude'] : null,
			'meet_longitude'       => ( isset( $row['meet_longitude'] ) && '' !== $row['meet_longitude'] ) ? (float) $row['meet_longitude'] : null,
			'manager_user_id'      => $row['manager_user_id'] ? (int) $row['manager_user_id'] : null,
			'manager_attending'    => null === $row['manager_attending'] ? null : (bool) $row['manager_attending'],
			'owner_confirmed'      => ! empty( $row['owner_completed_at'] ),
			'counterpart_confirmed' => ! empty( $row['counterpart_completed_at'] ),
			'created_at'           => self::to_iso8601( $row['created_at'] ),
			'updated_at'           => self::to_iso8601( $row['updated_at'] ),
		);
	}

	/* ---------------------------------------------------------------
	 * Status transitions (WP-APPT-005, 009..012)
	 * ------------------------------------------------------------- */

	public static function respond( $appointment_id, $user_id, array $input ) {
		$row = self::find_row( $appointment_id );
		if ( ! $row ) {
			return TD_Response::error( 'APPOINTMENT_NOT_FOUND', '약속을 찾을 수 없습니다.' );
		}
		if ( ! self::is_participant( $row, $user_id ) ) {
			return TD_Response::error( 'FORBIDDEN', '이 약속을 변경할 권한이 없습니다.' );
		}

		$action    = sanitize_key( $input['action'] ?? '' );
		$is_owner  = (int) $row['owner_user_id'] === (int) $user_id;

		switch ( $action ) {
			case 'accept':
				if ( ! $is_owner ) {
					return TD_Response::error( 'FORBIDDEN', '약속 수락은 거래글 소유자만 할 수 있습니다.' );
				}
				return self::accept( $row, $user_id );

			case 'reject':
				if ( ! $is_owner ) {
					return TD_Response::error( 'FORBIDDEN', '약속 거절은 거래글 소유자만 할 수 있습니다.' );
				}
				return self::finish_as( $row, $user_id, 'rejected', 'owner_rejected' );

			case 'cancel':
				return self::finish_as( $row, $user_id, 'cancelled', 'cancelled_by_participant' );

			case 'propose_change':
				return self::propose_change( $row, $user_id, $input );

			case 'complete':
				return self::complete( $row, $user_id );

			default:
				return TD_Response::error( 'VALIDATION_ERROR', '알 수 없는 action입니다.', array( 'field' => 'action' ) );
		}
	}

	private static function accept( array $row, $actor_id ) {
		if ( ! in_array( $row['status'], array( 'proposed' ), true ) ) {
			return TD_Response::error( 'INVALID_STATUS_TRANSITION', 'proposed 상태의 약속만 수락할 수 있습니다.' );
		}

		global $wpdb;
		$table = TD_DB::appointments();

		$wpdb->query( 'START TRANSACTION' );

		$existing_accepted = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE listing_id = %d AND accepted_listing_id IS NOT NULL AND id != %d", $row['listing_id'], $row['id'] )
		);

		if ( $existing_accepted ) {
			$wpdb->query( 'ROLLBACK' );
			return TD_Response::error( 'APPOINTMENT_ALREADY_ACCEPTED', '이미 확정된 약속이 존재합니다.' );
		}

		$updated = $wpdb->update(
			$table,
			array(
				'status'              => 'accepted',
				'accepted_listing_id' => $row['listing_id'],
				'updated_at'          => current_time( 'mysql', true ),
			),
			array( 'id' => $row['id'], 'status' => 'proposed' ),
			array( '%s', '%d', '%s' ),
			array( '%d', '%s' )
		);

		if ( ! $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return TD_Response::error( 'APPOINTMENT_ALREADY_ACCEPTED', '이미 확정된 약속이 존재합니다.' );
		}

		$wpdb->query( 'COMMIT' );

		self::record_history( $row['id'], $actor_id, $row['status'], 'accepted', false, 'owner_accepted' );
		TD_Listings::apply_status( $row['listing_id'], 'reserved', 'system', $actor_id, $row['id'] );

		TD_Audit_Log::record( 'appointment_status_change', 'appointment', $row['id'], 'success', array( 'to' => 'accepted' ), $actor_id );

		return self::to_response( $row['id'] );
	}

	/**
	 * Shared path for reject/cancel/expire from an active state; reverts the
	 * listing to `open` when the terminated appointment had been accepted.
	 */
	private static function finish_as( array $row, $actor_id, $new_status, $reason, $is_system = false ) {
		if ( ! in_array( $row['status'], self::ACTIVE_STATUSES, true ) ) {
			return TD_Response::error( 'INVALID_STATUS_TRANSITION', '이미 종료된 약속입니다.' );
		}

		$was_accepted = 'accepted' === $row['status'];

		global $wpdb;
		$wpdb->update(
			TD_DB::appointments(),
			array(
				'status'              => $new_status,
				'accepted_listing_id' => null,
				'updated_at'          => current_time( 'mysql', true ),
			),
			array( 'id' => $row['id'] )
		);

		self::record_history( $row['id'], $actor_id, $row['status'], $new_status, $is_system, $reason );

		if ( $was_accepted ) {
			TD_Listings::apply_status( $row['listing_id'], 'open', 'system', $actor_id, $row['id'] );
		}

		TD_Audit_Log::record( 'appointment_status_change', 'appointment', $row['id'], 'success', array( 'to' => $new_status, 'reason' => $reason ), $actor_id );

		return self::to_response( $row['id'] );
	}

	private static function propose_change( array $row, $actor_id, array $input ) {
		if ( ! in_array( $row['status'], self::ACTIVE_STATUSES, true ) ) {
			return TD_Response::error( 'INVALID_STATUS_TRANSITION', '진행 중인 약속만 변경 제안할 수 있습니다.' );
		}

		global $wpdb;
		$was_accepted = 'accepted' === $row['status'];

		$fields = array(
			'status'              => 'proposed',
			'accepted_listing_id' => null,
			'updated_at'          => current_time( 'mysql', true ),
		);
		if ( isset( $input['meet_at'] ) ) {
			$fields['meet_at'] = self::to_utc_mysql( $input['meet_at'] );
		}
		if ( isset( $input['meet_place'] ) ) {
			$fields['meet_place'] = sanitize_text_field( $input['meet_place'] );
		}
		if ( isset( $input['meet_address'] ) ) {
			$fields['meet_address'] = sanitize_text_field( $input['meet_address'] );
		}
		if ( array_key_exists( 'meet_latitude', $input ) ) {
			$fields['meet_latitude'] = self::format_coordinate( $input['meet_latitude'] );
		}
		if ( array_key_exists( 'meet_longitude', $input ) ) {
			$fields['meet_longitude'] = self::format_coordinate( $input['meet_longitude'] );
		}

		$wpdb->update( TD_DB::appointments(), $fields, array( 'id' => $row['id'] ) );
		self::record_history( $row['id'], $actor_id, $row['status'], 'proposed', false, 'change_proposed' );

		if ( $was_accepted ) {
			TD_Listings::apply_status( $row['listing_id'], 'open', 'system', $actor_id, $row['id'] );
		}

		return self::to_response( $row['id'] );
	}

	/**
	 * WP-APPT-012/013: completion, with owner-only or both-parties
	 * confirmation depending on admin setting `completion_confirmation`.
	 */
	private static function complete( array $row, $actor_id ) {
		if ( 'accepted' !== $row['status'] ) {
			return TD_Response::error( 'INVALID_STATUS_TRANSITION', '확정된 약속만 완료 처리할 수 있습니다.' );
		}

		$mode     = self::settings()['completion_confirmation'] ?? 'owner';
		$is_owner = (int) $row['owner_user_id'] === (int) $actor_id;

		global $wpdb;

		if ( 'owner' === $mode ) {
			if ( ! $is_owner ) {
				return TD_Response::error( 'FORBIDDEN', '거래 완료 확인은 소유자만 할 수 있습니다.' );
			}
			return self::finalize_completion( $row, $actor_id );
		}

		// both-parties mode: record this party's confirmation, finalize once both are in.
		$column = $is_owner ? 'owner_completed_at' : 'counterpart_completed_at';
		$wpdb->update( TD_DB::appointments(), array( $column => current_time( 'mysql', true ) ), array( 'id' => $row['id'] ) );

		$refreshed = self::find_row( $row['id'] );
		if ( ! empty( $refreshed['owner_completed_at'] ) && ! empty( $refreshed['counterpart_completed_at'] ) ) {
			return self::finalize_completion( $refreshed, $actor_id );
		}

		return self::to_response( $row['id'] );
	}

	private static function finalize_completion( array $row, $actor_id ) {
		global $wpdb;
		$wpdb->update(
			TD_DB::appointments(),
			array(
				'status'              => 'completed',
				'accepted_listing_id' => null,
				'updated_at'          => current_time( 'mysql', true ),
			),
			array( 'id' => $row['id'] )
		);

		self::record_history( $row['id'], $actor_id, $row['status'], 'completed', false, 'completed' );
		TD_Listings::apply_status( $row['listing_id'], 'completed', 'system', $actor_id, $row['id'] );

		// WP-APPT-012: auto-cancel the listing's other in-flight appointments.
		self::cancel_all_active_for_listing( $row['listing_id'], 'listing_completed', $row['id'] );

		TD_Audit_Log::record( 'appointment_status_change', 'appointment', $row['id'], 'success', array( 'to' => 'completed' ), $actor_id );

		return self::to_response( $row['id'] );
	}

	private static function record_history( $appointment_id, $actor_id, $from_status, $to_status, $is_system, $reason ) {
		global $wpdb;
		$wpdb->insert(
			TD_DB::appointment_history(),
			array(
				'appointment_id' => $appointment_id,
				'actor_id'       => $actor_id,
				'from_status'    => $from_status,
				'to_status'      => $to_status,
				'is_system'      => $is_system ? 1 : 0,
				'reason'         => sanitize_text_field( $reason ),
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/* ---------------------------------------------------------------
	 * Aggregates used by TD_Listings / TD_Users
	 * ------------------------------------------------------------- */

	public static function active_accepted_id_for_listing( $listing_id ) {
		global $wpdb;
		$id = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . TD_DB::appointments() . " WHERE listing_id = %d AND status = 'accepted' LIMIT 1", $listing_id )
		);
		return $id ? (int) $id : null;
	}

	public static function active_count_for_listing( $listing_id ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . TD_DB::appointments() . " WHERE listing_id = %d AND status IN ('proposed','accepted')", $listing_id )
		);
	}

	public static function cancel_all_active_for_listing( $listing_id, $reason, $except_id = 0 ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . TD_DB::appointments() . " WHERE listing_id = %d AND status IN ('proposed','accepted') AND id != %d",
				$listing_id,
				$except_id
			),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			self::finish_as( $row, null, 'cancelled', $reason, true );
		}
	}

	public static function cancel_all_active_for_user( $user_id, $reason ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . TD_DB::appointments() . " WHERE (owner_user_id = %d OR counterpart_user_id = %d) AND status IN ('proposed','accepted')",
				$user_id,
				$user_id
			),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			self::finish_as( $row, null, 'cancelled', $reason, true );
		}
	}

	public static function list_for_listing( $listing_id, $params = array() ) {
		global $wpdb;
		$where = 'listing_id = %d';
		$args  = array( $listing_id );

		if ( ! empty( $params['status'] ) ) {
			$where .= ' AND status = %s';
			$args[] = sanitize_key( $params['status'] );
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . TD_DB::appointments() . " WHERE {$where} ORDER BY created_at DESC", ...$args ),
			ARRAY_A
		);

		return array_map( array( __CLASS__, 'row_to_response' ), $rows );
	}

	public static function list_for_user( $user_id, $params = array() ) {
		global $wpdb;
		$where = '(owner_user_id = %d OR counterpart_user_id = %d)';
		$args  = array( $user_id, $user_id );

		if ( ! empty( $params['status'] ) ) {
			$where .= ' AND status = %s';
			$args[] = sanitize_key( $params['status'] );
		}
		if ( ! empty( $params['role'] ) && 'owner' === $params['role'] ) {
			$where = 'owner_user_id = %d';
			$args  = array( $user_id );
		} elseif ( ! empty( $params['role'] ) && 'counterpart' === $params['role'] ) {
			$where = 'counterpart_user_id = %d';
			$args  = array( $user_id );
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . TD_DB::appointments() . " WHERE {$where} ORDER BY created_at DESC", ...$args ),
			ARRAY_A
		);

		return array_map( array( __CLASS__, 'row_to_response' ), $rows );
	}

	/**
	 * WP-APPT-014: auto-expire past-due appointments still in an active state.
	 */
	public static function run_auto_expire() {
		global $wpdb;
		$settings = self::settings();
		$cutoff   = gmdate( 'Y-m-d H:i:s', time() - (int) $settings['appointment_expire_grace_hours'] * HOUR_IN_SECONDS );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . TD_DB::appointments() . " WHERE status IN ('proposed','accepted') AND meet_at IS NOT NULL AND meet_at < %s",
				$cutoff
			),
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			self::finish_as( $row, null, 'expired', 'auto_expired', true );
		}
	}
}
