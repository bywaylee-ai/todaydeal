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
			.td-form fieldset{border:0;padding:0;margin:0}
			.td-form legend{font-weight:600;margin-bottom:4px;padding:0}
			.td-form :focus-visible{outline:3px solid #1d4ed8;outline-offset:2px}
			.td-form input,.td-form select,.td-form textarea{border:1px solid #8c929b;border-radius:4px}
			.td-form .td-help{display:block;color:#5b616b;font-size:.9em;font-weight:400}
		</style>
		<?php
	}

	private static function render_markup() {
		$categories = TD_Taxonomy_Adapter::is_available() ? TD_Taxonomy_Adapter::get_categories() : array();
		?>
		<form class="td-form" id="td-listing-form">
			<fieldset class="td-row">
				<legend>거래 유형</legend>
				<label style="display:inline-block"><input type="radio" name="listing_type" value="sell" checked /> 팔아요</label>
				<label style="display:inline-block;margin-left:16px"><input type="radio" name="listing_type" value="buy" /> 구해요</label>
			</fieldset>

			<div class="td-row"><label for="td-f-title">제목 (필수)</label><input type="text" id="td-f-title" name="title" required /></div>
			<div class="td-row"><label for="td-f-product">상품 고유명</label><input type="text" id="td-f-product" name="product_name" placeholder="예: iPhone 15 Pro 256GB" /></div>
			<div class="td-row"><label for="td-f-desc">설명</label><textarea id="td-f-desc" name="description" rows="4"></textarea></div>

			<div class="td-row">
				<label for="td-f-price">가격 (필수) <span class="td-only-buy td-hidden">— 최소 ~ 최대</span><span class="td-help" id="td-f-price-help">최대 2,000,000,000</span></label>
				<input type="text" inputmode="numeric" class="td-price-input" maxlength="13" id="td-f-price" name="price_min" aria-describedby="td-f-price-help" required />
				<input type="text" inputmode="numeric" class="td-price-input td-only-buy td-hidden" maxlength="13" name="price_max" placeholder="최대 가격" aria-label="최대 가격" />
			</div>
			<div class="td-row">
				<label for="td-f-currency">통화</label>
				<select id="td-f-currency" name="currency" style="max-width:120px">
					<option value="VND" selected>VND</option>
					<option value="USD">USD</option>
					<option value="KRW">KRW</option>
				</select>
			</div>

			<div class="td-row td-only-sell"><label for="td-f-cond">물품 상태</label><input type="text" id="td-f-cond" name="condition" /></div>
			<div class="td-row td-only-buy td-hidden"><label for="td-f-condpref">희망 상태</label><input type="text" id="td-f-condpref" name="condition_preference" /></div>
			<div class="td-row td-only-buy td-hidden"><label for="td-f-qty">수량</label><input type="number" id="td-f-qty" name="quantity" value="1" min="1" /></div>

			<div class="td-row td-only-buy td-hidden"><label for="td-f-expires">만료 일시 (필수)</label>
				<span class="td-expires-presets" role="group" aria-label="만료 일시 빠른 지정">
					<button type="button" data-weeks="1">1주</button>
					<button type="button" data-weeks="2">2주</button>
					<button type="button" data-weeks="4">4주</button>
				</span>
				<input type="datetime-local" id="td-f-expires" name="expires_at" style="max-width:240px;display:inline-block" />
				<span class="td-tz-label"></span></div>

			<fieldset class="td-row">
				<legend>선호 장소 (도시 / 거점)</legend>
				<?php self::render_place_picker(); ?>
			</fieldset>
			<div class="td-row td-only-buy td-hidden"><label for="td-f-radius">희망 거래 반경(km)</label><input type="number" id="td-f-radius" name="search_radius_km" /></div>

			<fieldset class="td-row">
				<legend>카테고리</legend>
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
			</fieldset>

			<div class="td-row">
				<label for="td-media-input">이미지 <span class="td-help" id="td-media-help">팔아요 1~5장, 구해요 0~3장 (JPEG·PNG·WebP)</span></label>
				<input type="file" name="media" id="td-media-input" aria-describedby="td-media-help" accept="image/jpeg,image/png,image/webp" multiple />
			</div>

			<button type="submit">거래글 등록</button>
			<div class="td-msg td-hidden" id="td-form-msg" role="status" aria-live="polite"></div>
		</form>
		<?php
	}

	/**
	 * 선호 장소 선택 UI (도시 → 거점 → 별도 지정). wp-admin 편집 화면도 같은
	 * 마크업을 쓰도록 공개한다. `$prefix`가 있으면 필드명이 `prefix[key]`가 된다.
	 * `$current`는 저장된 location 값(없으면 첫 도시의 첫 거점이 기본 선택).
	 */
	public static function render_place_picker( $prefix = '', array $current = array() ) {
		$name = function ( $key ) use ( $prefix ) {
			return $prefix ? $prefix . '[' . $key . ']' : $key;
		};
		?>
		<div class="td-place-picker" data-presets="<?php echo esc_attr( wp_json_encode( TD_Listings::PLACE_PRESETS ) ); ?>" data-current="<?php echo esc_attr( wp_json_encode( $current ) ); ?>">
			<select class="td-place-city" aria-label="도시" style="max-width:200px;display:inline-block">
				<?php foreach ( TD_Listings::PLACE_PRESETS as $key => $preset ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $preset['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<select class="td-place-spot" aria-label="거점" style="max-width:260px;display:inline-block"></select>
			<input type="hidden" name="<?php echo esc_attr( $name( 'country' ) ); ?>" />
			<input type="hidden" name="<?php echo esc_attr( $name( 'city' ) ); ?>" />
			<br/>
			<input type="text" name="<?php echo esc_attr( $name( 'place_name' ) ); ?>" placeholder="장소명" aria-label="장소명" style="margin-top:6px" />
			<br/>
			<input type="text" name="<?php echo esc_attr( $name( 'map_url' ) ); ?>" placeholder="구글맵 링크 (https://maps.app.goo.gl/...)" aria-label="구글맵 링크" style="margin-top:6px" />
			<br/>
			<input type="text" name="<?php echo esc_attr( $name( 'latitude' ) ); ?>" placeholder="위도" aria-label="위도 (필수)" style="max-width:150px;display:inline-block;margin-top:6px" required />
			<input type="text" name="<?php echo esc_attr( $name( 'longitude' ) ); ?>" placeholder="경도" aria-label="경도 (필수)" style="max-width:150px;display:inline-block;margin-top:6px" required />
			<?php $maps_key = TD_Listings::google_maps_api_key(); ?>
			<?php if ( $maps_key ) : ?>
				<div class="td-place-map" role="application" aria-label="위치 선택 지도: 클릭하면 좌표가 입력됩니다. 키보드 사용자는 위도·경도 칸에 직접 입력하세요." data-key="<?php echo esc_attr( $maps_key ); ?>" style="height:320px;max-width:640px;margin-top:8px;border:1px solid #ddd"></div>
				<p class="description" style="margin-top:4px">지도를 클릭하면 그 위치가 "별도 지정"으로 선택되고 장소명(장소 이름 또는 주소, 뒤에 베트남어 이름)·좌표·구글맵 링크가 채워집니다. 장소명은 고칠 수 있습니다.</p>
			<?php elseif ( current_user_can( 'manage_options' ) ) : ?>
				<p class="description" style="margin-top:6px">지도에서 위치를 고르려면 TodayDeal &gt; Status Info에서 Google Maps API 키를 입력하세요.</p>
			<?php endif; ?>
		</div>
		<script>
		// 시간대 변환 도우미: 특정 IANA 시간대의 "벽시계" 시각(YYYY-MM-DDTHH:mm)과 UTC 간 변환.
		window.tdZoned = window.tdZoned || {
			local: function(ms, tz){
				var p = {};
				new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
					.formatToParts(new Date(ms)).forEach(function(x){ p[x.type] = x.value; });
				return p.year + '-' + p.month + '-' + p.day + 'T' + p.hour + ':' + p.minute;
			},
			toIso: function(local, tz){
				var guess = Date.parse(local + 'Z');
				var offset = Date.parse(window.tdZoned.local(guess, tz) + 'Z') - guess;
				return new Date(guess - offset).toISOString();
			}
		};
		(function(root){
			var presets = JSON.parse(root.dataset.presets);
			var cityEl = root.querySelector('.td-place-city');
			var spotEl = root.querySelector('.td-place-spot');
			var field = function(key){ return root.querySelector('[name="' + <?php echo wp_json_encode( $prefix ? $prefix . '[' : '' ); ?> + key + <?php echo wp_json_encode( $prefix ? ']' : '' ); ?> + '"]'); };
			var editable = ['place_name', 'map_url', 'latitude', 'longitude'];

			function fillSpots(){
				spotEl.innerHTML = '';
				var places = presets[cityEl.value].places || {};
				Object.keys(places).forEach(function(key){ spotEl.add(new Option(places[key].place_name, key)); });
				spotEl.add(new Option('별도 지정', 'custom'));
			}
			function apply(values){
				var city = presets[cityEl.value];
				field('country').value = city.country;
				field('city').value = city.city;
				(root.closest('form') || document).querySelectorAll('.td-tz-label').forEach(function(el){ el.textContent = city.tz_label; });
				var place = (city.places || {})[spotEl.value];
				editable.forEach(function(key){
					var el = field(key);
					if (place) { el.value = place[key]; }
					else if (values) { el.value = values[key] || ''; }
					else if (el.readOnly) { el.value = ''; }
					el.readOnly = !!place;
				});
				syncMap();
			}

			// 지도: 현재 좌표에 마커를 두고, 좌표가 없으면 도시의 첫 거점을 중심으로 보여 준다.
			// 클릭하면 "별도 지정"으로 바꾸고 좌표와 구글맵 링크를 채운다.
			var mapEl = root.querySelector('.td-place-map'), map, marker;
			function currentLatLng(){
				var lat = parseFloat(field('latitude').value), lng = parseFloat(field('longitude').value);
				return isNaN(lat) || isNaN(lng) ? null : { lat: lat, lng: lng };
			}
			function syncMap(){
				if (!map) return;
				var pos = currentLatLng();
				if (!pos) {
					var places = presets[cityEl.value].places || {}, first = places[Object.keys(places)[0]];
					if (first) { map.setCenter({ lat: parseFloat(first.latitude), lng: parseFloat(first.longitude) }); }
				} else {
					map.setCenter(pos);
				}
				marker.setPosition(pos);
				marker.setVisible(!!pos);
			}
			var lookupSeq = 0;
			function pickOnMap(e){
				if (spotEl.value !== 'custom') { spotEl.value = 'custom'; apply(null); }
				var lat = e.latLng.lat().toFixed(7), lng = e.latLng.lng().toFixed(7);
				var url = 'https://www.google.com/maps/search/?api=1&query=' + lat + ',' + lng;
				field('latitude').value = lat;
				field('longitude').value = lng;
				field('map_url').value = e.placeId ? url + '&query_place_id=' + encodeURIComponent(e.placeId) : url;
				syncMap();

				// 장소명: 지도 위 장소(POI)를 찍었으면 그 장소 이름, 아니면 좌표의 주소(역지오코딩).
				// 사용자 언어 이름 뒤에 베트남어 이름을 괄호로 붙인다(같으면 생략).
				var seq = ++lookupSeq, nameEl = field('place_name');
				nameEl.value = '';
				nameEl.placeholder = '장소명 찾는 중...';
				function placeName(lang){
					return google.maps.importLibrary('places').then(function(lib){
						var place = new lib.Place(lang ? { id: e.placeId, requestedLanguage: lang } : { id: e.placeId });
						return place.fetchFields({ fields: ['displayName'] }).then(function(){ return place.displayName; });
					});
				}
				function addressName(lang){
					var req = { location: e.latLng };
					if (lang) { req.language = lang; }
					return new google.maps.Geocoder().geocode(req).then(function(res){ return res.results[0] && res.results[0].formatted_address; });
				}
				function multiLang(lookup){
					var soft = function(p){ return p.catch(function(){ return null; }); };
					return Promise.all([lookup(null), soft(lookup('vi'))]).then(function(r){
						var local = r[0], vi = r[1];
						if (!local) { throw new Error('no name'); }
						return vi && vi !== local ? local + ' (' + vi + ')' : local;
					});
				}
				(e.placeId ? multiLang(placeName) : Promise.reject()).catch(function(){
					return multiLang(addressName);
				}).then(function(name){
					if (seq !== lookupSeq) return;
					nameEl.value = name || '';
					nameEl.placeholder = name ? '장소명' : '장소명 (주소를 찾지 못했습니다. 직접 입력하세요)';
				}, function(){
					if (seq !== lookupSeq) return;
					nameEl.placeholder = '장소명 (주소를 찾지 못했습니다. 직접 입력하세요)';
				});
			}
			if (mapEl) {
				window.tdMapsReady = window.tdMapsReady || new Promise(function(resolve){
					window.tdMapsLoaded = resolve;
					var s = document.createElement('script');
					s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(mapEl.dataset.key) + '&loading=async&callback=tdMapsLoaded';
					s.async = true;
					document.head.appendChild(s);
				});
				window.tdMapsReady.then(function(){ return google.maps.importLibrary('maps'); }).then(function(){ return google.maps.importLibrary('marker'); }).then(function(){
					map = new google.maps.Map(mapEl, { zoom: 16, center: { lat: 0, lng: 0 }, streetViewControl: false, mapTypeControl: false });
					marker = new google.maps.Marker({ map: map });
					map.addListener('click', function(e){
						if (e.placeId) { e.stop(); } // 기본 정보창 대신 우리가 선택 처리
						pickOnMap(e);
					});
					syncMap();
				});
				['latitude', 'longitude'].forEach(function(key){ field(key).addEventListener('change', syncMap); });
			}
			function init(current){
				cityEl.selectedIndex = 0;
				var cityName = (current && current.city || '').toLowerCase();
				Object.keys(presets).forEach(function(key){
					if (presets[key].aliases.some(function(a){ return a.toLowerCase() === cityName; })) { cityEl.value = key; }
				});
				fillSpots();
				if (current && current.city) {
					var places = presets[cityEl.value].places || {};
					var match = Object.keys(places).filter(function(key){ return places[key].place_name === current.place_name; })[0];
					spotEl.value = match || 'custom';
					apply(match ? null : current);
				} else {
					apply(null);
				}
			}
			root.tdTimezone = function(){ return presets[cityEl.value].timezone; };
			cityEl.addEventListener('change', function(){ fillSpots(); apply(null); });
			spotEl.addEventListener('change', function(){ apply(null); });
			var form = root.closest('form');
			if (form) { form.addEventListener('reset', function(){ setTimeout(function(){ init(null); }, 0); }); }
			init(JSON.parse(root.dataset.current));
		})(document.currentScript.previousElementSibling);
		</script>
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

			// 만료 일시 프리셋(1/2/4주). 기본값은 1주. 시각은 선택한 도시의 표준시 기준.
			var placePicker = form.querySelector('.td-place-picker');
			function setExpiresWeeks(weeks){
				form.querySelector('input[name=expires_at]').value = tdZoned.local(Date.now() + weeks * 7 * 86400000, placePicker.tdTimezone());
			}
			form.querySelectorAll('.td-expires-presets button').forEach(function(btn){
				btn.addEventListener('click', function(){ setExpiresWeeks(Number(btn.dataset.weeks)); });
			});
			setExpiresWeeks(1);

			form.querySelectorAll('.td-category-checkbox').forEach(function(cb){
				cb.addEventListener('change', function(){
					var group = form.querySelector('.td-cat-fields[data-term-id="' + cb.value + '"]');
					if (group) { group.classList.toggle('td-hidden', !cb.checked); }
				});
			});

			var MAX_PRICE = 2000000000;
			function formatPriceInput(el){
				var digits = el.value.replace(/[^\d]/g, '');
				if (digits === '') { el.value = ''; return; }
				var num = Math.min(parseInt(digits, 10), MAX_PRICE);
				el.value = num.toLocaleString('en-US');
			}
			form.querySelectorAll('.td-price-input').forEach(function(el){
				el.addEventListener('input', function(){ formatPriceInput(el); });
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

					var stripCommas = function(v){ return v ? v.replace(/,/g, '') : v; };

					var payload = {
						listing_type: type,
						title: fd.get('title'),
						description: fd.get('description'),
						price_min: stripCommas(fd.get('price_min')) || undefined,
						currency: fd.get('currency') || undefined,
						product_name: fd.get('product_name') || undefined,
						condition: type === 'sell' ? (fd.get('condition') || undefined) : undefined,
						condition_preference: type === 'buy' ? (fd.get('condition_preference') || undefined) : undefined,
						quantity: type === 'buy' ? Number(fd.get('quantity') || 1) : undefined,
						expires_at: type === 'buy' ? (fd.get('expires_at') ? tdZoned.toIso(fd.get('expires_at'), placePicker.tdTimezone()) : undefined) : undefined,
						category_ids: fd.getAll('category_ids[]').map(Number),
						media_ids: mediaIds,
						extra_fields: extraFields,
						location: {
							country: fd.get('country'),
							city: fd.get('city'),
							place_name: fd.get('place_name') || undefined,
							map_url: fd.get('map_url') || undefined,
							latitude: Number(fd.get('latitude')),
							longitude: Number(fd.get('longitude')),
							search_radius_km: type === 'buy' && fd.get('search_radius_km') ? Number(fd.get('search_radius_km')) : undefined
						}
					};
					if (type === 'buy' && fd.get('price_max')) { payload.price_max = stripCommas(fd.get('price_max')); }

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
					setExpiresWeeks(1);
				} catch (err) {
					showMsg(err.message, true);
				}
			});
		})();
		</script>
		<?php
	}
}
