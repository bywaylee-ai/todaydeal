<?php
/**
 * Plugin Name:       TodayDeal Auto Translate
 * Description:       거래하기 채팅을 서버에서 자동번역합니다. 메시지가 저장되면 상대방 언어(한국어/영어/베트남어/러시아어)로 Claude(Anthropic)가 번역해 보관하고, 메시지 조회 응답에 translated_content 로 제공합니다. todaydeal 의 child 플러그인.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            TodayDeal
 * Text Domain:       todaydeal-auto-translate
 * License:           GPL-2.0-or-later
 *
 * @package TodayDealAutoTranslate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TodayDeal 채팅 자동번역 플러그인.
 *
 * - todaydeal 의 chat_messages / chat_rooms 테이블을 읽어 상대방의 선호 언어(_todaydeal_language)
 *   로 새 TEXT 메시지를 번역해 {prefix}td_chat_translations 에 저장한다.
 * - 메시지 목록(GET)과 전송 응답(POST)이 지나갈 때 REST 응답에 translated_content 를 첨부한다.
 * - 번역 엔진은 WordPress AI Client(Anthropic/Claude)를 사용한다. 키가 없으면 조용히 건너뛴다.
 */
class TD_Auto_Translate_Plugin {

	const VERSION       = '0.1.0';
	const OPT            = 'todaydeal_auto_translate';
	const LANG_META      = '_todaydeal_language';
	const ANTHROPIC_CLS  = 'WordPress\AnthropicAiProvider\Provider\AnthropicProvider';
	const TABLE          = 'td_chat_translations';

	/**
	 * 언어 코드 -> Claude 프롬프트용 영어 이름.
	 */
	private static $lang_names = array(
		'ko' => 'Korean',
		'en' => 'English',
		'vi' => 'Vietnamese',
		'ru' => 'Russian',
	);

	public static function init() {
		register_activation_hook( __FILE__, array( __CLASS__, 'install' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		// todaydeal(10) 이 WP_Error 를 봉투로 감싼 뒤, 성공 응답에 번역을 붙인다(20).
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'on_rest_callbacks' ), 20, 3 );
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install_on_load' ) );
	}

	/* ---------------------------------------------------------------
	 * 설정
	 * ------------------------------------------------------------- */

	public static function options() {
		$default = array(
			'enabled'            => 1,
			'backfill_on_read'   => 1,
			'max_backfill'       => 10,
		);
		return wp_parse_args( (array) get_option( self::OPT, array() ), $default );
	}

	public static function is_enabled() {
		$o = self::options();
		return ! empty( $o['enabled'] );
	}

	/**
	 * Anthropic 키가 설정돼 있는가(WordPress AI 커넥터).
	 */
	public static function ai_configured() {
		if ( ! class_exists( 'WordPress\AiClient\AiClient' ) ) {
			return false;
		}
		try {
			return (bool) WordPress\AiClient\AiClient::isConfigured( 'anthropic' );
		} catch ( Exception $e ) {
			return false;
		}
	}

	public static function lang_name( $code ) {
		$code = strtolower( (string) $code );
		return isset( self::$lang_names[ $code ] ) ? self::$lang_names[ $code ] : 'Korean';
	}

	public static function user_lang( $user_id ) {
		return self::lang_name( get_user_meta( (int) $user_id, self::LANG_META, true ) ?: 'ko' );
	}

	/* ---------------------------------------------------------------
	 * 프론트엔드 자산: todaydeal 채팅 패널 뒤에 번역 표시 JS 로드
	 * ------------------------------------------------------------- */

	public static function enqueue_assets() {
		if ( ! self::is_enabled() ) {
			return;
		}
		if ( class_exists( 'TD_Post_Type' ) && is_singular( TD_Post_Type::POST_TYPE ) && is_user_logged_in() ) {
			wp_enqueue_script(
				'todaydeal-auto-translate',
				plugins_url( 'assets/js/td-auto-translate.js', __FILE__ ),
				array( 'todaydeal-chat' ),
				self::VERSION,
				true
			);
		}
	}

	/* ---------------------------------------------------------------
	 * 스키마
	 * ------------------------------------------------------------- */

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		$charset = $wpdb->get_charset_collate();
		// SQLite(Studio) 와 MySQL 둘 다에서 동작하도록 dbDelta 로 생성.
		$sql = "CREATE TABLE {$table} (
			message_id BIGINT UNSIGNED NOT NULL,
			lang VARCHAR(10) NOT NULL,
			translated_content LONGTEXT NOT NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (message_id),
			KEY lang (lang)
		) {$charset};";
		dbDelta( $sql );
		update_option( self::OPT . '_schema_version', self::VERSION );
	}

	public static function maybe_install_on_load() {
		if ( get_option( self::OPT . '_schema_version' ) !== self::VERSION ) {
			self::install();
		}
	}

	/* ---------------------------------------------------------------
	 * 번역 코어
	 * ------------------------------------------------------------- */

	/**
	 * 메시지를 상대방 언어로 번역해 저장한다.
	 *
	 * @param int $message_id
	 * @return string|null 번역본. 번역 불필요/실패 시 null.
	 */
	public static function translate_message( $message_id ) {
		if ( ! self::is_enabled() || ! self::ai_configured() ) {
			return null;
		}
		global $wpdb;
		$msg = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::messages_table() . " WHERE id = %d", (int) $message_id ), ARRAY_A );
		if ( ! $msg || ( $msg['type'] ?? 'text' ) !== 'text' || '' === trim( (string) $msg['content'] ) ) {
			return null;
		}
		$room = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::rooms_table() . " WHERE id = %d", (int) $msg['room_id'] ), ARRAY_A );
		if ( ! $room || empty( $msg['sender_user_id'] ) ) {
			return null;
		}
		$sender  = (int) $msg['sender_user_id'];
		$owner   = (int) $room['owner_user_id'];
		$cp      = (int) $room['counterpart_user_id'];
		$other   = ( $sender === $owner ) ? $cp : $owner; // 상대방 = 받는 사람.
		if ( ! $other || $other === $sender ) {
			return null;
		}
		$sender_lang = self::user_lang( $sender );
		$target_lang = self::user_lang( $other );
		if ( $sender_lang === $target_lang ) {
			return null; // 같은 언어면 번역할 필요 없음.
		}

		$translated = self::call_claude( (string) $msg['content'], $target_lang );
		if ( null === $translated ) {
			return null;
		}

		$table = self::table();
		$now   = current_time( 'mysql', true );
		// upsert
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT message_id FROM {$table} WHERE message_id = %d", (int) $message_id ) );
		if ( $existing ) {
			$wpdb->update( $table, array( 'translated_content' => $translated, 'lang' => $target_lang, 'updated_at' => $now ), array( 'message_id' => (int) $message_id ) );
		} else {
			$wpdb->insert(
				$table,
				array(
					'message_id'         => (int) $message_id,
					'lang'               => $target_lang,
					'translated_content' => $translated,
					'updated_at'         => $now,
				)
			);
		}
		return $translated;
	}

	/**
	 * 저장된 번역 조회(있으면 최근 번역).
	 */
	public static function lookup_translation( $message_id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_var( $wpdb->prepare( "SELECT translated_content FROM {$table} WHERE message_id = %d ORDER BY updated_at DESC LIMIT 1", (int) $message_id ) );
	}

	/**
	 * Claude 로 번역. 실패 시 null.
	 */
	protected static function call_claude( $content, $target_lang ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) || ! self::ai_configured() ) {
			return null;
		}
		$system = sprintf(
			'You are a professional chat translator. Translate the user\'s message into %1$s. ' .
			'Return ONLY the translated text — no quotes, no explanations, no "Translation:" prefix. ' .
			'Preserve tone, emoji, and meaning. Keep line breaks. If the text is already %1$s, return it unchanged.',
			$target_lang
		);
		try {
			$result = wp_ai_client_prompt( (string) $content )
				->using_provider( self::ANTHROPIC_CLS )
				->using_system_instruction( $system )
				->using_temperature( 0 )
				->generate_text();
			if ( is_wp_error( $result ) ) {
				self::log( 'Claude 번역 실패: ' . $result->get_error_message() );
				return null;
			}
			$out = trim( (string) $result );
			return '' === $out ? null : $out;
		} catch ( Exception $e ) {
			self::log( 'Claude 예외: ' . $e->getMessage() );
			return null;
		}
	}

	/* ---------------------------------------------------------------
	 * REST 응답 가로채기
	 * ------------------------------------------------------------- */

	public static function on_rest_callbacks( $response, $handler, $request ) {
		if ( ! self::is_enabled() || ! $response instanceof WP_REST_Response ) {
			return $response;
		}
		$route = (string) $request->get_route();
		if ( false === strpos( $route, '/todaydeal/v1/chat-rooms' ) ) {
			return $response;
		}

		$reader = get_current_user_id();
		$method = (string) $request->get_method();
		$data   = $response->get_data();

		// 메시지 전송(POST) — 방금 저장된 메시지 번역 시작.
		if ( 'POST' === $method && preg_match( '#/chat-rooms/\d+/messages$#', $route ) ) {
			$sent = is_array( $data ) && isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();
			if ( ! empty( $sent['message_id'] ) ) {
				self::translate_message( (int) $sent['message_id'] );
				$trans = self::lookup_translation( (int) $sent['message_id'] );
				// 발신자는 원문을 보므로, 번역은 null 유지. (저장 후 수신 폴링에서 확인됨)
				$data['data']['translated']          = null !== $trans;
				$data['data']['translated_content']  = null;
				$response->set_data( $data );
			}
			return $response;
		}

		// 메시지 목록(GET messages) 및 방 상세 — 수신자 입장에서 번역 첨부.
		if ( 'GET' === $method ) {
			$items = ( is_array( $data ) && isset( $data['data']['items'] ) && is_array( $data['data']['items'] ) )
				? $data['data']['items'] : array();
			if ( $items ) {
				$items    = self::enrich_items( $items, $reader );
				$data['data']['items'] = $items;
				$response->set_data( $data );
			}
		}
		return $response;
	}

	/**
	 * 각 메시지에 상대방 언어 번역을 첨부하고, 누락분을 소량 백필한다.
	 */
	protected static function enrich_items( array $items, $reader_id ) {
		$backfills = 0;
		$max       = (int) self::options()['max_backfill'];

		foreach ( $items as $i => $item ) {
			$items[ $i ]['translated']         = false;
			$items[ $i ]['translated_content'] = null;
			if ( empty( $item['message_id'] ) ) {
				continue;
			}
			$mid    = (int) $item['message_id'];
			$sender = isset( $item['sender_user_id'] ) ? (int) $item['sender_user_id'] : 0;
			// 내가 보낸 메시지는 번역 대상이 아니다(상대방용).
			if ( $sender && $sender === (int) $reader_id ) {
				continue;
			}
			$trans = self::lookup_translation( $mid );
			if ( null === $trans && self::options()['backfill_on_read'] && $backfills < $max ) {
				$backfills++;
				$trans = self::translate_message( $mid );
			}
			if ( null !== $trans ) {
				$items[ $i ]['translated']         = true;
				$items[ $i ]['translated_content'] = $trans;
			}
		}
		return $items;
	}

	/* ---------------------------------------------------------------
	 * 관리자 설정 화면
	 * ------------------------------------------------------------- */

	public static function admin_menu() {
		add_options_page(
			'TodayDeal 자동번역',
			'TodayDeal 번역',
			'manage_options',
			'todaydeal-auto-translate',
			array( __CLASS__, 'render_admin' )
		);
	}

	public static function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_POST['_tdt_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_tdt_nonce'] ) ), 'tdt_save' ) ) {
			$o = self::options();
			$o['enabled']          = empty( $_POST['enabled'] ) ? 0 : 1;
			$o['backfill_on_read'] = empty( $_POST['backfill_on_read'] ) ? 0 : 1;
			$o['max_backfill']     = max( 0, min( 100, (int) ( $_POST['max_backfill'] ?? 10 ) ) );
			update_option( self::OPT, $o );
			echo '<div class="notice notice-success"><p>저장되었습니다.</p></div>';
		}
		$o = self::options();
		$state = self::ai_configured() ? '<b style="color:#007017">연결됨 (Anthropic 키)</b>' : '<b style="color:#b00000">키 없음 — wp-admin → 설정 → AI 에서 Anthropic 키를 입력해 주세요.</b>';
		?>
		<div class="wrap">
			<h1>TodayDeal 거래 채팅 자동번역</h1>
			<p>현재 AI 연결 상태: <?php echo wp_kses_post( $state ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'tdt_save', '_tdt_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="enabled">자동번역 활성</label></th>
						<td>
							<input name="enabled" id="enabled" type="checkbox" value="1" <?php checked( $o['enabled'] ); ?> />
							<p class="description">메시지가 저장되면 상대방 언어로 Claude 번역을 실행합니다.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="backfill_on_read">조회 시 백필</label></th>
						<td>
							<input name="backfill_on_read" id="backfill_on_read" type="checkbox" value="1" <?php checked( $o['backfill_on_read'] ); ?> />
							<p class="description">메시지 목록 조회 시 번역이 없는 과거 메시지를 요청당 일정 수만큼 자동으로 뒤늦게 번역합니다.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="max_backfill">요청당 최대 백필</label></th>
						<td>
							<input name="max_backfill" id="max_backfill" type="number" min="0" max="100" value="<?php echo esc_attr( $o['max_backfill'] ); ?>" />
						</td>
					</tr>
				</table>
				<?php submit_button( '변경사항 저장' ); ?>
			</form>
			<h2>동작 방식</h2>
			<ul>
				<li>번역 테이블: <code><?php echo esc_html( self::table() ); ?></code></li>
				<li>각 사용자의 선호 언어는 사용자 프로필 필드 <code>language</code> (<code>ko/en/vi/ru</code>) 기준.</li>
				<li>번역은 메시지의 <b>받는 사람(상대방)</b> 언어로 만들어집니다.</li>
				<li>클라이언트는 메시지 응답의 <code>translated_content</code> 필드를 쓰면 상대방 메시지가 자기 언어로 보입니다.</li>
			</ul>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------
	 * util
	 * ------------------------------------------------------------- */

	protected static function messages_table() {
		global $wpdb;
		return $wpdb->prefix . 'td_chat_messages';
	}

	protected static function rooms_table() {
		global $wpdb;
		return $wpdb->prefix . 'td_chat_rooms';
	}

	protected static function log( $msg ) {
		error_log( '[todaydeal-auto-translate] ' . $msg );
	}
}

TD_Auto_Translate_Plugin::init();
