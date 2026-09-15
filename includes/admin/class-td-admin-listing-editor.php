<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp-admin create/edit screen for `todaydeal_deal`, added at the user's
 * request so admins/staff aren't limited to calling the REST API directly.
 * The save handler always goes through TD_Listings::update() so wp-admin
 * and the REST API share one validation path (spec 9.5 type rules, single
 * source of truth for meta writes).
 */
class TD_Admin_Listing_Editor {

	const NONCE_ACTION = 'todaydeal_save_listing';
	const NOTICE_TRANSIENT_PREFIX = 'todaydeal_admin_notice_';

	/**
	 * Re-entrancy guard: TD_Listings::update() calls wp_update_post() to bump
	 * post_modified, which re-fires save_post_{post_type} - without this the
	 * hook would call itself forever.
	 */
	private static $saving = false;

	public static function init() {
		add_action( 'add_meta_boxes_' . TD_Post_Type::POST_TYPE, array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_' . TD_Post_Type::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
		add_action( 'admin_footer-post.php', array( __CLASS__, 'footer_script' ) );
		add_action( 'admin_footer-post-new.php', array( __CLASS__, 'footer_script' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue( $hook ) {
		global $post_type;
		if ( TD_Post_Type::POST_TYPE !== $post_type ) {
			return;
		}
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			wp_enqueue_media();
		}
	}

	public static function add_meta_boxes() {
		add_meta_box( 'td_listing_basic', '거래글 기본 정보', array( __CLASS__, 'render_basic' ), TD_Post_Type::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'td_listing_location', '위치 / 약속', array( __CLASS__, 'render_location' ), TD_Post_Type::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'td_listing_media', '미디어', array( __CLASS__, 'render_media' ), TD_Post_Type::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'td_listing_extra', '카테고리별 추가 정보', array( __CLASS__, 'render_extra_fields' ), TD_Post_Type::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'td_listing_readonly', '상태 정보 (읽기 전용)', array( __CLASS__, 'render_readonly' ), TD_Post_Type::POST_TYPE, 'side', 'default' );
	}

	private static function meta( $post_id, $key, $default = '' ) {
		$value = get_post_meta( $post_id, $key, true );
		return '' === $value || false === $value ? $default : $value;
	}

	public static function render_basic( $post ) {
		wp_nonce_field( self::NONCE_ACTION, 'todaydeal_listing_nonce' );

		$listing_type = self::meta( $post->ID, TD_Post_Type::META_LISTING_TYPE, '' );
		$is_new       = '' === $listing_type;
		$status       = self::meta( $post->ID, TD_Post_Type::META_STATUS, 'open' );

		$allowed_statuses = $is_new ? array( 'draft', 'open' ) : array_unique( array_merge( array( $status ), TD_Listings::MANUAL_TRANSITIONS[ $status ] ?? array() ) );
		?>
		<style>
			.td-field-row{margin-bottom:12px}
			.td-field-row label{display:block;font-weight:600;margin-bottom:4px}
			.td-field-row input[type=text],.td-field-row input[type=number],.td-field-row input[type=datetime-local],.td-field-row select{width:100%;max-width:400px}
			.td-field-row input.td-price-input{width:150px;max-width:150px}
			.td-only-sell.is-hidden,.td-only-buy.is-hidden{display:none}
			.td-locked-note{color:#666;font-style:italic}
		</style>

		<div class="td-field-row">
			<label>거래 유형 (listing_type) <?php echo $is_new ? '' : '<span class="td-locked-note">— 등록 후 변경 불가</span>'; ?></label>
			<?php if ( $is_new ) : ?>
				<label><input type="radio" name="todaydeal[listing_type]" value="sell" checked /> 팔아요 (sell)</label>
				<label style="margin-left:16px"><input type="radio" name="todaydeal[listing_type]" value="buy" /> 구해요 (buy)</label>
			<?php else : ?>
				<input type="text" value="<?php echo esc_attr( 'sell' === $listing_type ? '팔아요 (sell)' : '구해요 (buy)' ); ?>" disabled />
				<input type="hidden" name="todaydeal[listing_type]" value="<?php echo esc_attr( $listing_type ); ?>" />
			<?php endif; ?>
		</div>

		<div class="td-field-row">
			<label>상태 (status)</label>
			<select name="todaydeal[status]">
				<?php foreach ( $allowed_statuses as $s ) : ?>
					<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status, $s ); ?>><?php echo esc_html( $s ); ?></option>
				<?php endforeach; ?>
			</select>
			<p class="description">reserved/expired는 시스템이 약속 상태에 따라 자동으로 설정하므로 직접 선택할 수 없습니다.</p>
		</div>

		<div class="td-field-row">
			<label>가격 (price_min<span class="td-only-buy">/ price_max</span>) — 최대 2,000,000,000</label>
			<input type="text" inputmode="numeric" class="td-price-input" maxlength="13" name="todaydeal[price_min]" value="<?php echo esc_attr( number_format( (int) self::meta( $post->ID, TD_Post_Type::META_PRICE_MIN, 0 ) ) ); ?>" placeholder="price_min" />
			<span class="td-only-buy"> ~ <input type="text" inputmode="numeric" class="td-price-input" maxlength="13" name="todaydeal[price_max]" value="<?php echo esc_attr( number_format( (int) self::meta( $post->ID, TD_Post_Type::META_PRICE_MAX, 0 ) ) ); ?>" placeholder="price_max" style="display:inline-block" /></span>
			<p class="description td-only-sell">sell은 price_max가 price_min과 동일하게 서버에서 자동 설정됩니다.</p>
		</div>

		<div class="td-field-row">
			<label>통화 (currency)</label>
			<?php $current_currency = self::meta( $post->ID, TD_Post_Type::META_CURRENCY, TD_Listings::DEFAULT_CURRENCY ); ?>
			<select name="todaydeal[currency]" style="max-width:120px">
				<?php foreach ( TD_Listings::CURRENCIES as $currency_code ) : ?>
					<option value="<?php echo esc_attr( $currency_code ); ?>" <?php selected( $current_currency, $currency_code ); ?>><?php echo esc_html( $currency_code ); ?></option>
				<?php endforeach; ?>
			</select>
			<label style="display:inline-block;font-weight:400;margin-left:16px">
				<input type="checkbox" name="todaydeal[price_negotiable]" value="1" <?php checked( self::meta( $post->ID, TD_Post_Type::META_NEGOTIABLE ), 1 ); ?> /> 가격 협의 가능
			</label>
		</div>

		<div class="td-field-row td-only-sell">
			<label>물품 상태 (condition) — sell 전용</label>
			<input type="text" name="todaydeal[condition]" value="<?php echo esc_attr( self::meta( $post->ID, TD_Post_Type::META_CONDITION ) ); ?>" />
		</div>
		<div class="td-field-row td-only-sell">
			<label>사용 기간 (item_usage_period) — sell 전용</label>
			<input type="text" name="todaydeal[item_usage_period]" value="<?php echo esc_attr( self::meta( $post->ID, TD_Post_Type::META_USAGE_PERIOD ) ); ?>" />
		</div>

		<div class="td-field-row td-only-buy">
			<label>희망 상태 (condition_preference) — buy 전용</label>
			<input type="text" name="todaydeal[condition_preference]" value="<?php echo esc_attr( self::meta( $post->ID, TD_Post_Type::META_CONDITION_PREF ) ); ?>" />
		</div>
		<div class="td-field-row td-only-buy">
			<label>수량 (quantity) — buy 전용, 기본 1</label>
			<input type="number" name="todaydeal[quantity]" min="1" value="<?php echo esc_attr( self::meta( $post->ID, TD_Post_Type::META_QUANTITY, 1 ) ); ?>" style="max-width:120px" />
		</div>

		<div class="td-field-row">
			<label>만료 일시 (expires_at) <span class="td-only-buy">— buy는 필수</span></label>
			<input type="datetime-local" name="todaydeal[expires_at]" value="<?php echo esc_attr( self::to_local_datetime( self::meta( $post->ID, TD_Post_Type::META_EXPIRES_AT ) ) ); ?>" />
		</div>

		<div class="td-field-row">
			<label>선호 장소 / 가능 시간</label>
			<input type="text" name="todaydeal[preferred_place]" value="<?php echo esc_attr( self::meta( $post->ID, TD_Post_Type::META_PREFERRED_PLACE ) ); ?>" placeholder="preferred_place" style="margin-bottom:6px" />
			<input type="text" name="todaydeal[available_time]" value="<?php echo esc_attr( self::meta( $post->ID, TD_Post_Type::META_AVAILABLE_TIME ) ); ?>" placeholder="available_time" />
		</div>
		<?php
	}

	public static function render_location( $post ) {
		?>
		<div class="td-field-row">
			<label>국가(country) / 도시(city)</label>
			<input type="text" name="todaydeal[country]" value="<?php echo esc_attr( self::meta( $post->ID, TD_Post_Type::META_COUNTRY ) ); ?>" placeholder="VN" style="max-width:80px" />
			<input type="text" name="todaydeal[city]" value="<?php echo esc_attr( self::meta( $post->ID, TD_Post_Type::META_CITY ) ); ?>" placeholder="Ho Chi Minh City" style="max-width:220px" />
		</div>
		<div class="td-field-row">
			<label>장소명(place_name)</label>
			<input type="text" name="todaydeal[place_name]" value="<?php echo esc_attr( self::meta( $post->ID, TD_Post_Type::META_PLACE_NAME ) ); ?>" />
		</div>
		<div class="td-field-row">
			<label>좌표 (latitude / longitude)</label>
			<input type="text" name="todaydeal[latitude]" value="<?php echo esc_attr( self::meta( $post->ID, TD_Post_Type::META_LAT ) ); ?>" placeholder="10.7769" style="max-width:150px" />
			<input type="text" name="todaydeal[longitude]" value="<?php echo esc_attr( self::meta( $post->ID, TD_Post_Type::META_LNG ) ); ?>" placeholder="106.7009" style="max-width:150px" />
		</div>
		<div class="td-field-row td-only-buy">
			<label>희망 거래 반경 km (search_radius_km) — buy 전용</label>
			<input type="number" name="todaydeal[search_radius_km]" value="<?php echo esc_attr( self::meta( $post->ID, TD_Post_Type::META_SEARCH_RADIUS ) ); ?>" style="max-width:120px" />
		</div>
		<?php
	}

	public static function render_media( $post ) {
		$media_ids = json_decode( self::meta( $post->ID, TD_Post_Type::META_MEDIA_IDS, '[]' ), true ) ?: array();
		?>
		<div id="td-media-picker" data-selected="<?php echo esc_attr( wp_json_encode( array_map( 'intval', $media_ids ) ) ); ?>">
			<div class="td-media-thumbs" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px"></div>
			<button type="button" class="button" id="td-media-select-btn">이미지 선택 (라이브러리에서)</button>
			<input type="hidden" name="todaydeal[media_ids]" id="td-media-ids-input" value="<?php echo esc_attr( implode( ',', array_map( 'intval', $media_ids ) ) ); ?>" />
			<p class="description">본인이 업로드한 미디어만 사용할 수 있습니다 (sell 1~5장, buy 0~3장).</p>
		</div>
		<?php
	}

	public static function render_extra_fields( $post ) {
		$assigned_terms = wp_get_object_terms( $post->ID, TD_Taxonomy_Adapter::TAXONOMY, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $assigned_terms ) ) {
			$assigned_terms = array();
		}
		$all_terms = TD_Taxonomy_Adapter::is_available() ? get_terms( array( 'taxonomy' => TD_Taxonomy_Adapter::TAXONOMY, 'hide_empty' => false ) ) : array();
		$values    = TD_Category_Fields::get_values_for_listing( $post->ID );

		if ( empty( $all_terms ) || is_wp_error( $all_terms ) ) {
			echo '<p class="description">카테고리를 먼저 지정하면(우측 카테고리 박스), 카테고리에 정의된 추가 필드가 여기 표시됩니다.</p>';
			return;
		}

		foreach ( $all_terms as $term ) {
			$fields = TD_Category_Fields::get_fields_for_term( $term->term_id );
			if ( empty( $fields ) ) {
				continue;
			}
			$visible = in_array( $term->term_id, $assigned_terms, true );
			printf(
				'<div class="td-catfield-group" data-term-id="%d" style="%s"><strong>%s</strong>',
				(int) $term->term_id,
				$visible ? '' : 'display:none',
				esc_html( $term->name )
			);
			foreach ( $fields as $field ) {
				self::render_extra_field_input( $field, $values[ $field['key'] ] ?? '' );
			}
			echo '</div><hr/>';
		}
		echo '<p class="description">카테고리 체크박스를 선택/해제하면 해당 카테고리의 추가 필드가 표시/숨김 처리됩니다. 필수(*) 항목은 저장 시 검증됩니다.</p>';
	}

	private static function render_extra_field_input( array $field, $value ) {
		$name     = 'todaydeal[extra_fields][' . esc_attr( $field['key'] ) . ']';
		$label    = esc_html( $field['label'] ) . ( $field['required'] ? ' *' : '' );
		echo '<div class="td-field-row">';
		echo '<label>' . $label . '</label>';
		switch ( $field['type'] ) {
			case 'select':
				echo '<select name="' . $name . '"><option value="">-- 선택 --</option>';
				foreach ( (array) ( $field['options'] ?? array() ) as $opt ) {
					printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $opt ), selected( $value, $opt, false ) );
				}
				echo '</select>';
				break;
			case 'checkbox':
				printf( '<input type="checkbox" name="%s" value="1" %s />', $name, checked( $value, true, false ) );
				break;
			case 'date':
				printf( '<input type="date" name="%s" value="%s" />', $name, esc_attr( $value ) );
				break;
			case 'number':
				printf( '<input type="number" name="%s" value="%s" />', $name, esc_attr( $value ) );
				break;
			default:
				printf( '<input type="text" name="%s" value="%s" />', $name, esc_attr( $value ) );
		}
		echo '</div>';
	}

	public static function render_readonly( $post ) {
		$owner_id = TD_Listings::owner_id( $post->ID );
		echo '<p><strong>소유자(owner_user_id):</strong> ' . ( $owner_id ? esc_html( get_the_author_meta( 'display_name', $owner_id ) . " (#{$owner_id})" ) : '— (저장 시 나로 지정됨)' ) . '</p>';
		if ( $owner_id ) {
			echo '<p><strong>진행 중 약속:</strong> ' . (int) TD_Appointments::active_count_for_listing( $post->ID ) . '건</p>';
			echo '<p><strong>확정 약속 ID:</strong> ' . ( TD_Appointments::active_accepted_id_for_listing( $post->ID ) ?: '없음' ) . '</p>';
		}
	}

	private static function to_local_datetime( $mysql_utc ) {
		if ( ! $mysql_utc ) {
			return '';
		}
		return mysql2date( 'Y-m-d\TH:i', $mysql_utc, false );
	}

	/**
	 * Maps the wp-admin form's flat `todaydeal[...]` fields onto the same
	 * shaped array TD_Listings::update() expects from the REST API.
	 */
	private static function collect_input() {
		$raw = wp_unslash( $_POST['todaydeal'] ?? array() );

		$input = array();
		foreach ( array( 'listing_type', 'status', 'price_min', 'price_max', 'currency', 'condition', 'condition_preference', 'item_usage_period', 'quantity', 'preferred_place', 'available_time' ) as $key ) {
			if ( isset( $raw[ $key ] ) && '' !== $raw[ $key ] ) {
				$input[ $key ] = $raw[ $key ];
			}
		}
		$input['price_negotiable'] = ! empty( $raw['price_negotiable'] );

		if ( ! empty( $raw['expires_at'] ) ) {
			$input['expires_at'] = get_gmt_from_date( str_replace( 'T', ' ', $raw['expires_at'] ), 'Y-m-d\TH:i:s\Z' );
		}

		$input['location'] = array(
			'country'          => $raw['country'] ?? '',
			'city'             => $raw['city'] ?? '',
			'place_name'       => $raw['place_name'] ?? '',
			'latitude'         => $raw['latitude'] ?? '',
			'longitude'        => $raw['longitude'] ?? '',
			'search_radius_km' => $raw['search_radius_km'] ?? '',
		);

		if ( isset( $_POST['tax_input'][ TD_Taxonomy_Adapter::TAXONOMY ] ) ) {
			$input['category_ids'] = array_map( 'intval', (array) wp_unslash( $_POST['tax_input'][ TD_Taxonomy_Adapter::TAXONOMY ] ) );
		}

		if ( isset( $raw['media_ids'] ) ) {
			$input['media_ids'] = array_filter( array_map( 'intval', explode( ',', (string) $raw['media_ids'] ) ) );
		}

		if ( isset( $raw['extra_fields'] ) && is_array( $raw['extra_fields'] ) ) {
			$input['extra_fields'] = $raw['extra_fields'];
		}

		return $input;
	}

	public static function save( $post_id, $post ) {
		if ( self::$saving ) {
			return;
		}
		if ( ! isset( $_POST['todaydeal_listing_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['todaydeal_listing_nonce'] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$input           = self::collect_input();
		$was_new_listing = ! get_post_meta( $post_id, TD_Post_Type::META_LISTING_TYPE, true );
		$requested_status = $input['status'] ?? null;

		self::$saving = true;
		$result       = TD_Listings::update( $post_id, get_current_user_id(), $input, true );

		// A status change on an EXISTING listing must go through the same
		// transition validation as PATCH /listings/:id/status - update()
		// itself never touches status once a listing already exists.
		if ( ! is_wp_error( $result ) && ! $was_new_listing && $requested_status && $requested_status !== get_post_meta( $post_id, TD_Post_Type::META_STATUS, true ) ) {
			$status_result = TD_Listings::change_status( $post_id, get_current_user_id(), $requested_status );
			if ( is_wp_error( $status_result ) ) {
				$result = $status_result;
			}
		}
		self::$saving = false;

		if ( is_wp_error( $result ) ) {
			set_transient( self::NOTICE_TRANSIENT_PREFIX . get_current_user_id(), array(
				'type'    => 'error',
				'message' => $result->get_error_code() . ': ' . $result->get_error_message(),
			), 60 );
		}
	}

	public static function render_notice() {
		$user_id = get_current_user_id();
		$notice  = get_transient( self::NOTICE_TRANSIENT_PREFIX . $user_id );
		if ( ! $notice ) {
			return;
		}
		delete_transient( self::NOTICE_TRANSIENT_PREFIX . $user_id );
		printf( '<div class="notice notice-%s is-dismissible"><p><strong>TodayDeal 저장 오류:</strong> %s</p></div>', esc_attr( $notice['type'] ), esc_html( $notice['message'] ) );
	}

	public static function footer_script() {
		global $post_type;
		if ( TD_Post_Type::POST_TYPE !== $post_type ) {
			return;
		}
		?>
		<script>
		jQuery(function($){
			function currentType(){
				var checked = $('input[name="todaydeal[listing_type]"]:checked').val();
				return checked || $('input[name="todaydeal[listing_type]"][type=hidden]').val();
			}
			function applyTypeVisibility(){
				var t = currentType();
				$('.td-only-sell').toggleClass('is-hidden', t !== 'sell');
				$('.td-only-buy').toggleClass('is-hidden', t !== 'buy');
			}
			$(document).on('change', 'input[name="todaydeal[listing_type]"]', applyTypeVisibility);
			applyTypeVisibility();

			function toggleCatFields(){
				$('.td-catfield-group').each(function(){
					var termId = $(this).data('term-id');
					var checked = $('input[name^="tax_input[product_cat]"][value="' + termId + '"]').is(':checked');
					$(this).toggle(!!checked);
				});
			}
			$(document).on('change', 'input[name^="tax_input[product_cat]"]', toggleCatFields);
			setTimeout(toggleCatFields, 300);

			var MAX_PRICE = 2000000000;
			function formatPriceInput(el){
				var digits = el.value.replace(/[^\d]/g, '');
				if (digits === '') { el.value = ''; return; }
				var num = Math.min(parseInt(digits, 10), MAX_PRICE);
				el.value = num.toLocaleString('en-US');
			}
			$(document).on('input', '.td-price-input', function(){ formatPriceInput(this); });
			$('.td-price-input').each(function(){ formatPriceInput(this); });

			var frame;
			var picker = $('#td-media-picker');
			var input  = $('#td-media-ids-input');
			var thumbs = $('.td-media-thumbs');
			var maxItems = 5;

			function renderThumbs(ids){
				thumbs.empty();
				ids.forEach(function(id){
					wp.media.attachment(id).fetch().then(function(){
						var url = wp.media.attachment(id).get('sizes') && wp.media.attachment(id).get('sizes').thumbnail
							? wp.media.attachment(id).get('sizes').thumbnail.url
							: wp.media.attachment(id).get('url');
						var el = $('<div style="position:relative"><img src="' + url + '" style="width:80px;height:80px;object-fit:cover;border:1px solid #ccc" /><a href="#" data-id="' + id + '" class="td-remove-media" style="position:absolute;top:0;right:0;background:#fff;padding:0 4px">x</a></div>');
						thumbs.append(el);
					});
				});
			}

			var initial = [];
			try { initial = JSON.parse(picker.data('selected') || '[]'); } catch(e){}
			if (initial.length) { renderThumbs(initial); }

			$('#td-media-select-btn').on('click', function(e){
				e.preventDefault();
				if (!frame) {
					frame = wp.media({ title: '이미지 선택', multiple: true, library: { type: 'image' } });
					frame.on('select', function(){
						var selection = frame.state().get('selection').map(function(a){ return a.get('id'); });
						var current = (input.val() ? input.val().split(',').filter(Boolean).map(Number) : []);
						var merged = current.concat(selection).filter(function(v,i,a){ return a.indexOf(v) === i; }).slice(0, maxItems);
						input.val(merged.join(','));
						renderThumbs(merged);
					});
				}
				frame.open();
			});

			thumbs.on('click', '.td-remove-media', function(e){
				e.preventDefault();
				var id = parseInt($(this).data('id'), 10);
				var current = (input.val() ? input.val().split(',').filter(Boolean).map(Number) : []).filter(function(v){ return v !== id; });
				input.val(current.join(','));
				renderThumbs(current);
			});
		});
		</script>
		<?php
	}
}
