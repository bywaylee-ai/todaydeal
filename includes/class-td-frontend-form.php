<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `[todaydeal_listing_form]` shortcode: lets a logged-in member register a
 * listing from the site's front end. Added at the user's request - spec
 * section 8 basic premise #8 explicitly does not require a front-end
 * screen, so this is additive. It is a thin client over the existing,
 * already-validated REST API (same-origin fetch + the standard WP REST
 * cookie/nonce auth) rather than a second, parallel save path.
 */
class TD_Frontend_Form {

	public static function init() {
		add_shortcode( 'todaydeal_listing_form', array( __CLASS__, 'render' ) );
	}

	public static function render() {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( '거래글을 등록하려면 로그인이 필요합니다.', 'todaydeal' ) . '</p>';
		}
		if ( ! current_user_can( 'todaydeal_create_listings' ) ) {
			return '<p>' . esc_html__( '거래글 등록 권한이 없습니다.', 'todaydeal' ) . '</p>';
		}

		ob_start();
		self::render_styles();
		self::render_markup();
		self::render_script();
		return ob_get_clean();
	}

	private static function render_styles() {
		?>
		<style>
			.td-form .td-row{margin-bottom:14px}
			.td-form label{display:block;font-weight:600;margin-bottom:4px}
			.td-form input[type=text],.td-form input[type=number],.td-form input[type=datetime-local],.td-form textarea,.td-form select{width:100%;max-width:480px;padding:6px}
			.td-form .td-hidden{display:none}
			.td-form .td-cat-fields{border-left:3px solid #ddd;padding-left:12px;margin:8px 0}
			.td-form button{padding:8px 18px}
			.td-form .td-msg{margin-top:12px;padding:10px;border-radius:4px}
			.td-form .td-msg.error{background:#fdecea;color:#611a15}
			.td-form .td-msg.success{background:#e6f4ea;color:#1e4620}
		</style>
		<?php
	}

	private static function render_markup() {
		$categories = TD_Taxonomy_Adapter::is_available() ? TD_Taxonomy_Adapter::get_categories() : array();
		?>
		<form class="td-form" id="td-listing-form">
			<div class="td-row">
				<label>거래 유형</label>
				<label><input type="radio" name="listing_type" value="sell" checked /> 팔아요</label>
				<label style="display:inline-block;margin-left:16px"><input type="radio" name="listing_type" value="buy" /> 구해요</label>
			</div>

			<div class="td-row"><label>제목</label><input type="text" name="title" required /></div>
			<div class="td-row"><label>설명</label><textarea name="description" rows="4"></textarea></div>

			<div class="td-row">
				<label>가격 (price_min <span class="td-only-buy td-hidden">~ price_max</span>)</label>
				<input type="number" name="price_min" required />
				<input type="number" name="price_max" class="td-only-buy td-hidden" placeholder="price_max" />
			</div>
			<div class="td-row"><label>통화</label><input type="text" name="currency" placeholder="VND" style="max-width:120px" /></div>

			<div class="td-row td-only-sell"><label>물품 상태 (condition)</label><input type="text" name="condition" /></div>
			<div class="td-row td-only-buy td-hidden"><label>희망 상태 (condition_preference)</label><input type="text" name="condition_preference" /></div>
			<div class="td-row td-only-buy td-hidden"><label>수량</label><input type="number" name="quantity" value="1" min="1" /></div>

			<div class="td-row td-only-buy td-hidden"><label>만료 일시 (필수)</label><input type="datetime-local" name="expires_at" /></div>

			<div class="td-row">
				<label>위치</label>
				<input type="text" name="country" placeholder="국가 코드 (VN)" style="max-width:100px;display:inline-block" required />
				<input type="text" name="city" placeholder="도시" style="max-width:220px;display:inline-block" required />
				<br/>
				<input type="text" name="place_name" placeholder="장소명" style="margin-top:6px" />
				<br/>
				<input type="text" name="latitude" placeholder="위도" style="max-width:150px;display:inline-block;margin-top:6px" required />
				<input type="text" name="longitude" placeholder="경도" style="max-width:150px;display:inline-block;margin-top:6px" required />
			</div>
			<div class="td-row td-only-buy td-hidden"><label>희망 거래 반경(km)</label><input type="number" name="search_radius_km" /></div>

			<div class="td-row">
				<label>카테고리</label>
				<?php foreach ( $categories as $cat ) : ?>
					<?php $extra_fields = TD_Category_Fields::get_fields_for_term( $cat->term_id ); ?>
					<label style="display:inline-block;margin-right:12px">
						<input type="checkbox" class="td-category-checkbox" name="category_ids[]" value="<?php echo esc_attr( $cat->term_id ); ?>" />
						<?php echo esc_html( $cat->name ); ?>
					</label>
					<?php if ( ! empty( $extra_fields ) ) : ?>
						<div class="td-cat-fields td-hidden" data-term-id="<?php echo esc_attr( $cat->term_id ); ?>">
							<?php foreach ( $extra_fields as $field ) : ?>
								<div class="td-row">
									<label><?php echo esc_html( $field['label'] . ( $field['required'] ? ' *' : '' ) ); ?></label>
									<?php if ( 'select' === $field['type'] ) : ?>
										<select name="extra_fields[<?php echo esc_attr( $field['key'] ); ?>]">
											<option value="">-- 선택 --</option>
											<?php foreach ( (array) ( $field['options'] ?? array() ) as $opt ) : ?>
												<option value="<?php echo esc_attr( $opt ); ?>"><?php echo esc_html( $opt ); ?></option>
											<?php endforeach; ?>
										</select>
									<?php elseif ( 'checkbox' === $field['type'] ) : ?>
										<input type="checkbox" name="extra_fields[<?php echo esc_attr( $field['key'] ); ?>]" value="1" />
									<?php else : ?>
										<input type="<?php echo esc_attr( 'date' === $field['type'] ? 'date' : ( 'number' === $field['type'] ? 'number' : 'text' ) ); ?>" name="extra_fields[<?php echo esc_attr( $field['key'] ); ?>]" />
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>

			<div class="td-row">
				<label>이미지 (sell 1~5장, buy 0~3장)</label>
				<input type="file" name="media" id="td-media-input" accept="image/jpeg,image/png,image/webp" multiple />
			</div>

			<button type="submit">거래글 등록</button>
			<div class="td-msg td-hidden" id="td-form-msg"></div>
		</form>
		<?php
	}

	private static function render_script() {
		$rest_url = esc_url_raw( rest_url( 'todaydeal/v1' ) );
		$nonce    = wp_create_nonce( 'wp_rest' );
		?>
		<script>
		(function(){
			var form = document.getElementById('td-listing-form');
			if (!form) return;
			var REST_URL = <?php echo wp_json_encode( $rest_url ); ?>;
			var NONCE = <?php echo wp_json_encode( $nonce ); ?>;

			function applyTypeVisibility(){
				var type = form.querySelector('input[name=listing_type]:checked').value;
				form.querySelectorAll('.td-only-sell').forEach(function(el){ el.classList.toggle('td-hidden', type !== 'sell'); });
				form.querySelectorAll('.td-only-buy').forEach(function(el){ el.classList.toggle('td-hidden', type !== 'buy'); });
			}
			form.querySelectorAll('input[name=listing_type]').forEach(function(r){ r.addEventListener('change', applyTypeVisibility); });
			applyTypeVisibility();

			form.querySelectorAll('.td-category-checkbox').forEach(function(cb){
				cb.addEventListener('change', function(){
					var group = form.querySelector('.td-cat-fields[data-term-id="' + cb.value + '"]');
					if (group) { group.classList.toggle('td-hidden', !cb.checked); }
				});
			});

			function showMsg(text, isError){
				var msg = document.getElementById('td-form-msg');
				msg.textContent = text;
				msg.className = 'td-msg ' + (isError ? 'error' : 'success');
			}

			async function uploadMedia(files){
				var ids = [];
				for (var i = 0; i < files.length; i++) {
					var fd = new FormData();
					fd.append('file', files[i]);
					var res = await fetch(REST_URL + '/media', {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'X-WP-Nonce': NONCE },
						body: fd
					});
					var json = await res.json();
					if (!res.ok) { throw new Error(json.error ? json.error.message : '이미지 업로드 실패'); }
					ids.push(json.data.media_id);
				}
				return ids;
			}

			form.addEventListener('submit', async function(e){
				e.preventDefault();
				showMsg('등록 중...', false);
				try {
					var fd = new FormData(form);
					var type = fd.get('listing_type');
					var mediaIds = await uploadMedia(document.getElementById('td-media-input').files);

					var extraFields = {};
					form.querySelectorAll('[name^="extra_fields["]').forEach(function(el){
						var key = el.name.match(/extra_fields\[(.+)\]/)[1];
						if (el.type === 'checkbox') { extraFields[key] = el.checked; }
						else if (el.value !== '') { extraFields[key] = el.value; }
					});

					var payload = {
						listing_type: type,
						title: fd.get('title'),
						description: fd.get('description'),
						price_min: fd.get('price_min') || undefined,
						currency: fd.get('currency') || undefined,
						condition: type === 'sell' ? (fd.get('condition') || undefined) : undefined,
						condition_preference: type === 'buy' ? (fd.get('condition_preference') || undefined) : undefined,
						quantity: type === 'buy' ? Number(fd.get('quantity') || 1) : undefined,
						expires_at: type === 'buy' ? (fd.get('expires_at') ? new Date(fd.get('expires_at')).toISOString() : undefined) : undefined,
						category_ids: fd.getAll('category_ids[]').map(Number),
						media_ids: mediaIds,
						extra_fields: extraFields,
						location: {
							country: fd.get('country'),
							city: fd.get('city'),
							place_name: fd.get('place_name') || undefined,
							latitude: Number(fd.get('latitude')),
							longitude: Number(fd.get('longitude')),
							search_radius_km: type === 'buy' && fd.get('search_radius_km') ? Number(fd.get('search_radius_km')) : undefined
						}
					};
					if (type === 'buy' && fd.get('price_max')) { payload.price_max = fd.get('price_max'); }

					var res = await fetch(REST_URL + '/listings', {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' },
						body: JSON.stringify(payload)
					});
					var json = await res.json();
					if (!res.ok) { throw new Error((json.error ? json.error.message : '등록 실패') + (json.error && json.error.details && json.error.details.field ? ' (' + json.error.details.field + ')' : '')); }

					showMsg('등록되었습니다 (listing_id: ' + json.data.listing_id + ')', false);
					form.reset();
				} catch (err) {
					showMsg(err.message, true);
				}
			});
		})();
		</script>
		<?php
	}
}
