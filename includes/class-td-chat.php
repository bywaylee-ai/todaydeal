<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 거래 대화(채팅) - Chomot 앱 기능정의서 6.6 / 9.2 / 9.4 기준.
 * 앱 서버 대신 플러그인이 채팅방·메시지를 저장하며, 실시간은 클라이언트 폴링으로 대체한다.
 */
class TD_Chat {

	const MAX_LENGTH   = 1000;
	const PAGE_SIZE    = 50;
	const TYPE_TEXT    = 'text';
	const TYPE_SYSTEM  = 'system';

	/**
	 * APP-CHAT-001/002: 거래글·소유자·상대방 조합으로 중복 없는 채팅방을 만들거나 기존 방을 반환한다.
	 */
	public static function open_room( $user_id, $listing_id ) {
		global $wpdb;

		$listing = TD_Listings::get( $listing_id );
		if ( is_wp_error( $listing ) ) {
			return $listing;
		}
		$owner_id = (int) $listing['owner_user_id'];
		if ( $owner_id === $user_id ) {
			return TD_Response::error( 'CHAT_SELF_NOT_ALLOWED', '본인 거래글에는 대화를 시작할 수 없습니다.' );
		}

		$room = self::find_room_by_parties( $listing_id, $owner_id, $user_id );
		if ( $room ) {
			return self::to_response( $room, $user_id );
		}

		if ( ! in_array( $listing['status'], TD_Listings::ACTIVE_STATUSES, true ) ) {
			return TD_Response::error( 'CHAT_ROOM_CLOSED', '거래가 종료된 글에는 대화를 시작할 수 없습니다.' );
		}

		$now = current_time( 'mysql', true );
		// 동시 요청은 UNIQUE(listing_id, owner, counterpart)가 막고, 실패 시 기존 방을 다시 읽는다.
		$inserted = $wpdb->insert(
			TD_DB::chat_rooms(),
			array(
				'listing_id'          => $listing_id,
				'listing_type'        => $listing['listing_type'],
				'owner_user_id'       => $owner_id,
				'counterpart_user_id' => $user_id,
				'created_at'          => $now,
				'updated_at'          => $now,
			)
		);
		$room = self::find_room_by_parties( $listing_id, $owner_id, $user_id );
		if ( ! $room ) {
			return TD_Response::error( 'INTERNAL_ERROR', '채팅방을 만들 수 없습니다.' );
		}
		if ( $inserted && (int) $wpdb->insert_id === (int) $room['id'] ) {
			$system_id = self::insert_message( (int) $room['id'], null, self::TYPE_SYSTEM, '거래 대화가 시작되었습니다.', null );
			// 대화를 시작한 상대방에게는 이 안내가 안 읽은 메시지가 아니다(작성자에게만 +1).
			$wpdb->update( TD_DB::chat_rooms(), array( 'counterpart_last_read_id' => $system_id ), array( 'id' => (int) $room['id'] ) );
			$room = self::find_room( (int) $room['id'] );
		}
		return self::to_response( $room, $user_id );
	}

	/**
	 * APP-CHAT-003: 내 채팅방 목록. `$listing_id`를 주면 해당 거래글의 방만 반환한다.
	 */
	public static function rooms_for_user( $user_id, $listing_id = 0 ) {
		global $wpdb;
		$table = TD_DB::chat_rooms();

		$sql  = "SELECT * FROM {$table} WHERE ( owner_user_id = %d OR counterpart_user_id = %d )";
		$args = array( $user_id, $user_id );
		if ( $listing_id ) {
			$sql   .= ' AND listing_id = %d';
			$args[] = $listing_id;
		}
		$sql .= ' ORDER BY updated_at DESC, id DESC LIMIT 100';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is internal.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return array_map( fn( $row ) => self::to_response( $row, $user_id ), $rows ?: array() );
	}

	public static function get_room( $room_id, $user_id ) {
		$room = self::require_participant( $room_id, $user_id );
		return is_wp_error( $room ) ? $room : self::to_response( $room, $user_id );
	}

	/**
	 * APP-CHAT-004: 참여자만 조회. `$after`가 있으면 그 이후 메시지(폴링), 없으면 최근 PAGE_SIZE개.
	 */
	public static function messages( $room_id, $user_id, $after = 0 ) {
		global $wpdb;
		$room = self::require_participant( $room_id, $user_id );
		if ( is_wp_error( $room ) ) {
			return $room;
		}
		$table = TD_DB::chat_messages();

		if ( $after ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE room_id = %d AND id > %d ORDER BY id ASC LIMIT %d", $room_id, $after, self::PAGE_SIZE ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
			$rows = array_reverse( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE room_id = %d ORDER BY id DESC LIMIT %d", $room_id, self::PAGE_SIZE ), ARRAY_A ) ?: array() );
		}
		return array_map( fn( $row ) => self::message_response( $row, $room, $user_id ), $rows ?: array() );
	}

	/**
	 * APP-CHAT-006/010/011: 발신자는 세션에서 결정하고, client_message_id로 재전송 중복을 막으며,
	 * 거래가 종료된 방에는 보낼 수 없다.
	 */
	public static function send( $room_id, $user_id, $content, $client_message_id = '' ) {
		global $wpdb;
		$room = self::require_participant( $room_id, $user_id );
		if ( is_wp_error( $room ) ) {
			return $room;
		}
		if ( self::is_closed( $room ) ) {
			return TD_Response::error( 'CHAT_ROOM_CLOSED', '거래가 종료되어 메시지를 보낼 수 없습니다.' );
		}

		$content = trim( sanitize_textarea_field( (string) $content ) );
		if ( '' === $content || mb_strlen( $content ) > self::MAX_LENGTH ) {
			return TD_Response::error( 'VALIDATION_ERROR', sprintf( '메시지는 1~%d자로 입력해주세요.', self::MAX_LENGTH ), array( 'field' => 'content' ) );
		}

		$client_message_id = substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $client_message_id ), 0, 64 );
		if ( $client_message_id ) {
			$existing = self::find_by_client_id( $room_id, $client_message_id );
			if ( $existing ) {
				return self::message_response( $existing, $room, $user_id );
			}
		}

		$limited = TD_Rate_Limiter::check( 'chat_send', $user_id, 30, 60 );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$message_id = self::insert_message( $room_id, $user_id, self::TYPE_TEXT, $content, $client_message_id ?: null );
		$row        = $message_id ? self::find_message( $message_id ) : ( $client_message_id ? self::find_by_client_id( $room_id, $client_message_id ) : null );
		if ( ! $row ) {
			return TD_Response::error( 'INTERNAL_ERROR', '메시지를 저장할 수 없습니다.' );
		}
		self::mark_read( $room_id, $user_id, (int) $row['id'] );
		return self::message_response( $row, $room, $user_id );
	}

	/**
	 * APP-CHAT-008: 방별 마지막 읽은 메시지 기록. 뒤로 되돌리지 않는다.
	 */
	public static function mark_read( $room_id, $user_id, $message_id ) {
		global $wpdb;
		$room = self::require_participant( $room_id, $user_id );
		if ( is_wp_error( $room ) ) {
			return $room;
		}
		$column  = self::role_of( $room, $user_id ) . '_last_read_id';
		$last_id = min( (int) $message_id, (int) $room['last_message_id'] );
		if ( $last_id > (int) $room[ $column ] ) {
			$wpdb->update( TD_DB::chat_rooms(), array( $column => $last_id ), array( 'id' => $room_id ) );
		}
		return true;
	}

	/* ---------------------------------------------------------------
	 * internals
	 * ------------------------------------------------------------- */

	private static function insert_message( $room_id, $sender_id, $type, $content, $client_message_id ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$ok  = $wpdb->insert(
			TD_DB::chat_messages(),
			array(
				'room_id'           => $room_id,
				'sender_user_id'    => $sender_id,
				'client_message_id' => $client_message_id,
				'type'              => $type,
				'content'           => $content,
				'created_at'        => $now,
			)
		);
		if ( ! $ok ) {
			return 0;
		}
		$message_id = (int) $wpdb->insert_id;
		$wpdb->update( TD_DB::chat_rooms(), array( 'last_message_id' => $message_id, 'updated_at' => $now ), array( 'id' => $room_id ) );
		return $message_id;
	}

	private static function require_participant( $room_id, $user_id ) {
		$room = self::find_room( $room_id );
		if ( ! $room ) {
			return TD_Response::error( 'CHAT_ROOM_NOT_FOUND', '채팅방을 찾을 수 없습니다.' );
		}
		if ( ! self::role_of( $room, $user_id ) ) {
			return TD_Response::error( 'FORBIDDEN', '이 채팅방의 참여자만 접근할 수 있습니다.' );
		}
		return $room;
	}

	/** 'owner' | 'counterpart' | '' */
	private static function role_of( array $room, $user_id ) {
		if ( (int) $room['owner_user_id'] === $user_id ) {
			return 'owner';
		}
		return (int) $room['counterpart_user_id'] === $user_id ? 'counterpart' : '';
	}

	/** 판매자·구매자는 저장하지 않고 listing_type에서 파생한다(앱 기능정의서 9.2). */
	private static function party_label( $listing_type, $role ) {
		$owner_sells = 'sell' === $listing_type;
		return ( 'owner' === $role ) === $owner_sells ? '판매자' : '구매자';
	}

	/** APP-CHAT-010: 거래글이 진행 중(open/reserved)이 아니면 방을 닫힌 것으로 본다. */
	private static function is_closed( array $room ) {
		$post = get_post( (int) $room['listing_id'] );
		if ( ! $post || 'trash' === $post->post_status ) {
			return true;
		}
		return ! in_array( get_post_meta( $post->ID, TD_Post_Type::META_STATUS, true ), TD_Listings::ACTIVE_STATUSES, true );
	}

	private static function to_response( array $room, $user_id ) {
		global $wpdb;
		$role       = self::role_of( $room, $user_id );
		$other_role = 'owner' === $role ? 'counterpart' : 'owner';
		$other      = TD_Users::public_profile( (int) $room[ $other_role . '_user_id' ] );
		$last       = $room['last_message_id'] ? self::find_message( (int) $room['last_message_id'] ) : null;
		$table      = TD_DB::chat_messages();

		// 상대방이 나에게 보낸 가장 최근 메시지(시스템 안내 제외) - 툴바 대화 목록 표시용.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$received = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE room_id = %d AND sender_user_id = %d ORDER BY id DESC LIMIT 1", $room['id'], $room[ $other_role . '_user_id' ] ), ARRAY_A );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$unread = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE room_id = %d AND id > %d AND ( sender_user_id IS NULL OR sender_user_id <> %d )", $room['id'], $room[ $role . '_last_read_id' ], $user_id ) );

		return array(
			'room_id'         => (int) $room['id'],
			'listing_id'      => (int) $room['listing_id'],
			'listing_title'   => get_the_title( (int) $room['listing_id'] ),
			'listing_url'     => self::room_url( $room ),
			'listing_type'    => $room['listing_type'],
			'my_role'         => $role,
			'my_label'        => self::party_label( $room['listing_type'], $role ),
			'other_user'      => array(
				'user_id'  => (int) $room[ $other_role . '_user_id' ],
				'nickname' => is_wp_error( $other ) ? '탈퇴한 사용자' : $other['nickname'],
				'label'    => self::party_label( $room['listing_type'], $other_role ),
			),
			'last_message'    => $last ? array(
				'message_id' => (int) $last['id'],
				'content'    => $last['content'],
				'type'       => $last['type'],
				'is_mine'    => (int) $last['sender_user_id'] === $user_id,
				'created_at' => self::iso( $last['created_at'] ),
			) : null,
			'last_received'   => $received ? array(
				'message_id' => (int) $received['id'],
				'content'    => $received['content'],
				'type'       => $received['type'],
				'created_at' => self::iso( $received['created_at'] ),
			) : null,
			'unread_count'    => $unread,
			'is_closed'       => self::is_closed( $room ),
			'updated_at'      => self::iso( $room['updated_at'] ),
		);
	}

	/** 거래글 상세에서 이 방을 바로 여는 URL(`?td_deal=&td_room=`, chat.js가 처리). 거래글이 없으면 ''. */
	private static function room_url( array $room ) {
		$permalink = get_permalink( (int) $room['listing_id'] );
		return $permalink ? add_query_arg( array( 'td_deal' => (int) $room['listing_id'], 'td_room' => (int) $room['id'] ), $permalink ) : '';
	}

	private static function message_response( array $row, array $room, $user_id ) {
		return array(
			'message_id'        => (int) $row['id'],
			'room_id'           => (int) $row['room_id'],
			'type'              => $row['type'],
			'content'           => $row['content'],
			'sender_user_id'    => $row['sender_user_id'] ? (int) $row['sender_user_id'] : null,
			'is_mine'           => (int) $row['sender_user_id'] === $user_id,
			'client_message_id' => $row['client_message_id'],
			'created_at'        => self::iso( $row['created_at'] ),
		);
	}

	private static function iso( $mysql_gmt ) {
		return $mysql_gmt ? gmdate( 'c', strtotime( $mysql_gmt . ' UTC' ) ) : null;
	}

	private static function find_room( $room_id ) {
		global $wpdb;
		$table = TD_DB::chat_rooms();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $room_id ), ARRAY_A );
	}

	private static function find_room_by_parties( $listing_id, $owner_id, $counterpart_id ) {
		global $wpdb;
		$table = TD_DB::chat_rooms();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE listing_id = %d AND owner_user_id = %d AND counterpart_user_id = %d", $listing_id, $owner_id, $counterpart_id ), ARRAY_A );
	}

	private static function find_message( $message_id ) {
		global $wpdb;
		$table = TD_DB::chat_messages();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $message_id ), ARRAY_A );
	}

	private static function find_by_client_id( $room_id, $client_message_id ) {
		global $wpdb;
		$table = TD_DB::chat_messages();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE room_id = %d AND client_message_id = %s", $room_id, $client_message_id ), ARRAY_A );
	}
}
