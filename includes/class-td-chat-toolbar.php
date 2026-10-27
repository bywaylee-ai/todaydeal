<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 상단 툴바(사용자·관리자 화면 공통)의 채팅 아이콘과 새 메시지 브라우저 알림.
 * 대화 1개: 아이콘이 해당 거래 상세의 대화방으로 바로 이동한다.
 * 대화 2개 이상: 대화 목록을 펼친다. 배지는 대화 수와 무관하게 안 읽은 메시지가 있으면 그 수를 표시한다.
 * 서버는 아이콘·배지·첫 화면용 대화 목록 데이터만 내려주고, 목록 렌더링과 이후 갱신은
 * assets/js/chat-notify.js가 새로고침 없이 처리한다.
 */
class TD_Chat_Toolbar {

	private static $rooms = null;

	public static function init() {
		// top-secondary 안의 항목은 DOM 순서대로 왼쪽→오른쪽(li float:left)으로 놓이므로
		// my-account(코어 우선순위 9991) 바로 앞에 추가해야 계정 아이콘 왼쪽에 붙는다.
		add_action( 'admin_bar_menu', array( __CLASS__, 'add_node' ), 9990 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/** 거래글이 남아 있는 내 대화방(요청당 1회 조회). */
	private static function rooms() {
		if ( null === self::$rooms ) {
			self::$rooms = array_values( array_filter( TD_Chat::rooms_for_user( get_current_user_id() ), fn( $r ) => $r['listing_url'] ) );
		}
		return self::$rooms;
	}

	/**
	 * 펼침 메뉴(자식 노드) 없이 출력한다. 코어 admin-bar.js는 페이지 로드 때 있던 li.menupop에만
	 * 마우스·터치·키보드 처리를 붙이므로, 목록과 열기·닫기는 chat-notify.js가 직접 맡는다.
	 */
	public static function add_node( WP_Admin_Bar $bar ) {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$rooms  = self::rooms();
		$count  = count( $rooms );
		$unread = array_sum( wp_list_pluck( $rooms, 'unread_count' ) );

		$title = '<span class="ab-icon" aria-hidden="true"></span><span class="screen-reader-text td-ab-sr">' . esc_html( sprintf( '거래 대화 %d개, 안 읽은 메시지 %d개', $count, $unread ) ) . '</span>';
		if ( $unread > 0 ) {
			$title .= '<span class="td-ab-count" aria-hidden="true">' . (int) $unread . '</span>';
		}
		$bar->add_node( array(
			'id'     => 'td-chat',
			'parent' => 'top-secondary',
			'title'  => $title,
			'href'   => 1 === $count ? $rooms[0]['listing_url'] : '#',
			'meta'   => array( 'title' => '거래 대화 · Trò chuyện' ),
		) );
	}

	public static function enqueue() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		wp_enqueue_script( 'todaydeal-chat-notify', plugins_url( 'assets/js/chat-notify.js', TODAYDEAL_PLUGIN_FILE ), array(), TODAYDEAL_VERSION, true );
		wp_localize_script( 'todaydeal-chat-notify', 'tdChatNotify', array(
			'root'  => esc_url_raw( rest_url( 'todaydeal/v1/' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'rooms' => is_admin_bar_showing() ? self::rooms() : array(),
		) );
		if ( is_admin_bar_showing() ) {
			wp_add_inline_style( 'admin-bar', self::css() );
		}
	}

	/** 툴바 배경(#1d2327/#2c3338) 위 글자 대비 AA 이상. */
	private static function css() {
		return '#wpadminbar #wp-admin-bar-td-chat > .ab-item .ab-icon:before{content:"\f125";top:2px}'
			. '#wpadminbar #wp-admin-bar-td-chat > .ab-item{display:flex;align-items:center}'
			. '#wpadminbar .td-ab-count{display:inline-block;min-width:18px;height:18px;padding:0 5px;margin-left:2px;border-radius:9px;background:#d63638;color:#fff;font-size:11px;font-weight:600;line-height:18px;text-align:center;box-sizing:border-box}'
			. '#wpadminbar #wp-admin-bar-td-chat .ab-submenu{width:320px;max-width:calc(100vw - 16px)}'
			. '#wpadminbar #wp-admin-bar-td-chat .ab-submenu .ab-item{height:auto;line-height:1.4;padding:8px 12px;white-space:normal}'
			. '#wpadminbar .td-ab-room{display:flex;gap:10px;align-items:flex-start}'
			. '#wpadminbar .td-ab-avatar{flex:0 0 32px;width:32px;height:32px;border-radius:50%;background:#e6f4f1;color:#0f766e;font-weight:700;font-size:15px;line-height:32px;text-align:center}'
			. '#wpadminbar .td-ab-body{min-width:0;flex:1;display:flex;flex-direction:column}'
			. '#wpadminbar .td-ab-title{font-weight:600;color:#f0f0f1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
			. '#wpadminbar .td-ab-msg{color:#f0f0f1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
			. '#wpadminbar .td-ab-meta{color:#c3c4c7;font-size:12px}'
			. '#wpadminbar .td-ab-title-row{display:flex;align-items:center;gap:6px;min-width:0}'
			. '#wpadminbar .td-ab-title-row .td-ab-title{flex:1}'
			. '#wpadminbar .td-ab-title-row .td-ab-count{flex:0 0 auto;margin-left:0}'
			. '@media screen and (max-width:782px){#wpadminbar #wp-admin-bar-td-chat{display:block}#wpadminbar #wp-admin-bar-td-chat > .ab-item .ab-icon:before{font-size:28px;top:4px}'
			. '#wpadminbar #wp-admin-bar-td-chat{position:static}#wpadminbar #wp-admin-bar-td-chat > .ab-sub-wrapper{position:fixed;top:46px;right:8px;left:auto}}';
	}
}
