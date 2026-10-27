<?php
/**
 * Public single view for a todaydeal_deal listing: gallery, key attributes,
 * description, spec table, location, safety tips on the left; sticky price /
 * seller / share box on the right; similar listings below.
 * Data comes from TD_Listings::to_response() - the same normalization the
 * REST API uses (spec 9.1 "공개 전환").
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

TD_Frontend_Views::header();

while ( have_posts() ) :
	the_post();
	$listing  = TD_Listings::to_response( get_the_ID() );
	$owner    = TD_Users::public_profile( $listing['owner_user_id'] );
	$owner    = is_wp_error( $owner ) ? null : $owner;
	$fields   = TD_Category_Fields::get_fields_for_terms( $listing['category_ids'] );
	$terms    = TD_Taxonomy_Adapter::get_listing_terms( get_the_ID() );
	$tz       = TD_Listings::city_timezone( $listing['location']['city'] );
	$flag     = TD_Frontend_Views::status_flag( $listing );
	$archive  = get_post_type_archive_link( TD_Post_Type::POST_TYPE );
	$register = TD_Frontend_Views::register_url();
	$lat      = $listing['location']['latitude'];
	$lng      = $listing['location']['longitude'];
	$map_link = $listing['location']['map_url'] ?: ( $lat || $lng ? "https://www.google.com/maps/search/?api=1&query={$lat},{$lng}" : '' );
	$maps_key = TD_Listings::google_maps_api_key();
	$viewer_id = get_current_user_id();
	$is_owner  = $viewer_id && $viewer_id === (int) $listing['owner_user_id'];
	$is_active = in_array( $listing['status'], TD_Listings::ACTIVE_STATUSES, true );

	$city_key = '';
	foreach ( TD_Listings::PLACE_PRESETS as $key => $preset ) {
		if ( in_array( strtolower( $listing['location']['city'] ), array_map( 'strtolower', $preset['aliases'] ), true ) ) {
			$city_key = $key;
		}
	}
	$top_term = null;
	foreach ( $terms as $term ) {
		if ( ! $term->parent ) {
			$top_term = $term;
		}
	}

	// 판매자의 다른 공개 거래글 수.
	$owner_count = ( new WP_Query( array(
		'post_type'      => TD_Post_Type::POST_TYPE,
		'author'         => $listing['owner_user_id'],
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'meta_query'     => array( array( 'key' => TD_Post_Type::META_STATUS, 'value' => TD_Listings::ACTIVE_STATUSES, 'compare' => 'IN' ) ),
	) ) )->found_posts;

	// 같은 카테고리의 비슷한 거래글.
	$similar = $terms ? get_posts( array(
		'post_type'      => TD_Post_Type::POST_TYPE,
		'posts_per_page' => 8,
		'post__not_in'   => array( get_the_ID() ),
		'fields'         => 'ids',
		'tax_query'      => array( array( 'taxonomy' => TD_Taxonomy_Adapter::TAXONOMY, 'field' => 'term_id', 'terms' => wp_list_pluck( $terms, 'term_id' ) ) ),
		'meta_query'     => array( array( 'key' => TD_Post_Type::META_STATUS, 'value' => array( 'open' ), 'compare' => 'IN' ) ),
	) ) : array();

	$specs = array();
	if ( $listing['product_name'] ) {
		$specs[] = array( '상품 고유명', 'Tên sản phẩm', $listing['product_name'] );
	}
	if ( $listing['condition'] ) {
		$specs[] = array( '물품 상태', 'Tình trạng', $listing['condition'] );
	}
	if ( $listing['condition_preference'] ) {
		$specs[] = array( '희망 상태', 'Tình trạng mong muốn', $listing['condition_preference'] );
	}
	if ( $listing['item_usage_period'] ) {
		$specs[] = array( '사용 기간', 'Thời gian sử dụng', $listing['item_usage_period'] );
	}
	if ( 'buy' === $listing['listing_type'] && $listing['quantity'] > 1 ) {
		$specs[] = array( '수량', 'Số lượng', (string) $listing['quantity'] );
	}
	if ( $terms ) {
		$specs[] = array( '카테고리', 'Danh mục', implode( ', ', wp_list_pluck( $terms, 'name' ) ) );
	}
	foreach ( $fields as $field ) {
		$value = $listing['extra_fields'][ $field['key'] ] ?? null;
		if ( null !== $value && '' !== $value ) {
			$specs[] = array( $field['label'], '', is_bool( $value ) ? ( $value ? '예 · Có' : '아니오 · Không' ) : (string) $value );
		}
	}
	if ( $listing['preferred_place'] ) {
		$specs[] = array( '선호 장소', 'Nơi giao dịch', $listing['preferred_place'] );
	}
	if ( $listing['available_time'] ) {
		$specs[] = array( '가능 시간', 'Thời gian', $listing['available_time'] . ( TD_Listings::parse_time_range( $listing['available_time'] ) ? ' ' . $tz['label'] : '' ) );
	}
	if ( $listing['expires_at'] ) {
		$specs[] = array( '만료일', 'Hết hạn', wp_date( 'Y-m-d H:i', strtotime( $listing['expires_at'] . ' UTC' ), $tz['timezone'] ) . ' ' . $tz['label'] );
	}
	?>
	<div class="td-page">
		<div class="td-wrap">
			<nav class="td-crumbs" aria-label="breadcrumb">
				<a href="<?php echo esc_url( $archive ); ?>">TodayDeal</a>
				<?php if ( $top_term ) : ?>
					<span class="sep" aria-hidden="true">›</span><a href="<?php echo esc_url( TD_Frontend_Views::archive_url( array( 'category' => $top_term->term_id ) ) ); ?>"><?php echo esc_html( $top_term->name ); ?></a>
				<?php endif; ?>
				<?php if ( $city_key ) : ?>
					<span class="sep" aria-hidden="true">›</span><a href="<?php echo esc_url( TD_Frontend_Views::archive_url( array( 'category' => $top_term ? $top_term->term_id : 0, 'city' => $city_key ) ) ); ?>"><?php echo esc_html( ( $top_term ? $top_term->name . ' ' : '' ) . TD_Listings::PLACE_PRESETS[ $city_key ]['label'] ); ?></a>
				<?php endif; ?>
				<span class="sep" aria-hidden="true">›</span><span><?php echo esc_html( $listing['title'] ); ?></span>
			</nav>

			<div class="td-detail">
				<aside class="td-side">
					<div class="td-box">
						<h1 class="td-title"><?php echo esc_html( $listing['title'] ); ?></h1>
						<div class="td-item-tags" style="margin-bottom:8px">
							<span class="td-tag"><?php echo esc_html( $listing['type_label'] ); ?></span>
							<?php if ( $flag ) : ?><span class="td-tag"><?php echo esc_html( $flag ); ?></span><?php endif; ?>
							<?php if ( $listing['price_negotiable'] ) : ?><span class="td-tag">가격 협의 · <span lang="vi">Có thương lượng</span></span><?php endif; ?>
						</div>
						<div class="td-big-price"><?php echo TD_Frontend_Views::price_html( $listing ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
						<ul class="td-facts">
							<li><span class="ico" aria-hidden="true">📍</span><span class="td-sr-only">위치: </span><span><?php echo esc_html( TD_Frontend_Views::location_text( $listing ) ); ?></span></li>
							<li><span class="ico" aria-hidden="true">🕒</span><span><?php echo TD_Frontend_Views::time_ago( $listing['created_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> 등록</span></li>
						</ul>
					</div>

					<?php if ( $owner ) : ?>
						<div class="td-box">
							<div class="td-seller">
								<div class="td-avatar" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( $owner['nickname'], 0, 1 ) ) ); ?></div>
								<div>
									<div class="td-seller-name"><?php echo esc_html( $owner['nickname'] ); ?></div>
									<div class="td-seller-meta">
										<?php if ( ! empty( $owner['rating_average'] ) ) : ?><span aria-hidden="true">★</span><span class="td-sr-only">평점</span> <?php echo esc_html( $owner['rating_average'] ); ?> (<?php echo (int) $owner['review_count']; ?>) · <?php endif; ?>
										<?php echo TD_Frontend_Views::label( "거래글 {$owner_count}건", "{$owner_count} tin" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									</div>
								</div>
							</div>
							<div class="td-actions">
								<button type="button" class="td-btn is-ghost is-block" id="td-copy-link" data-url="<?php echo esc_url( get_permalink() ); ?>"><span aria-hidden="true">🔗</span> 링크 복사 · Sao chép liên kết</button>
							</div>
							<p class="td-note">거래 약속은 TodayDeal 앱에서 할 수 있습니다. <span class="td-vi" lang="vi">Hẹn giao dịch trên ứng dụng TodayDeal.</span></p>
						</div>

						<?php // APP-DET-006/007: 상대방에게는 대화 시작, 소유자에게는 이 거래글의 대화 목록. ?>
						<?php if ( ! $viewer_id ) : ?>
							<a class="td-btn is-block td-deal-btn" href="<?php echo esc_url( TD_Frontend_Views::deal_login_url( get_the_ID() ) ); ?>">거래하기 · Deal</a>
							<p class="td-note">로그인 후 작성자와 대화할 수 있습니다. <span class="td-vi" lang="vi">Đăng nhập để trò chuyện với người đăng.</span></p>
						<?php elseif ( $is_owner ) : ?>
							<button type="button" class="td-btn is-block td-deal-btn" id="td-deal-btn" aria-haspopup="dialog" aria-controls="td-chat">거래 대화 보기 · Deal</button>
						<?php elseif ( $is_active ) : ?>
							<button type="button" class="td-btn is-block td-deal-btn" id="td-deal-btn" aria-haspopup="dialog" aria-controls="td-chat">거래하기 · Deal</button>
						<?php else : ?>
							<button type="button" class="td-btn is-block td-deal-btn" disabled>거래하기 · Deal</button>
							<p class="td-note">거래가 종료된 글입니다. <span class="td-vi" lang="vi">Tin này đã kết thúc giao dịch.</span></p>
						<?php endif; ?>
					<?php endif; ?>
				</aside>

				<div class="td-main">
					<div class="td-box" style="padding:0;background:none">
						<div class="td-gallery" id="td-gallery">
							<?php foreach ( $listing['media_ids'] as $i => $media_id ) : ?>
								<img src="<?php echo esc_url( wp_get_attachment_image_url( $media_id, 'large' ) ?: '' ); ?>" alt="<?php echo esc_attr( $listing['title'] ); ?>" class="<?php echo 0 === $i ? 'is-active' : ''; ?>" />
							<?php endforeach; ?>
							<?php if ( $flag ) : ?><span class="td-flag"><?php echo esc_html( $flag ); ?></span><?php endif; ?>
							<?php if ( count( $listing['media_ids'] ) > 1 ) : ?>
								<button type="button" class="td-nav prev" aria-label="이전 사진 · Ảnh trước"><span aria-hidden="true">‹</span></button>
								<button type="button" class="td-nav next" aria-label="다음 사진 · Ảnh sau"><span aria-hidden="true">›</span></button>
								<span class="td-counter" aria-live="polite">1/<?php echo count( $listing['media_ids'] ); ?></span>
							<?php endif; ?>
						</div>
						<?php if ( count( $listing['media_ids'] ) > 1 ) : ?>
							<div class="td-thumbs" role="group" aria-label="사진 선택 · Chọn ảnh">
								<?php foreach ( $listing['media_ids'] as $i => $media_id ) : ?>
									<button type="button" data-index="<?php echo (int) $i; ?>" class="<?php echo 0 === $i ? 'is-active' : ''; ?>" aria-label="<?php echo esc_attr( sprintf( '사진 %d / %d', $i + 1, count( $listing['media_ids'] ) ) ); ?>" aria-pressed="<?php echo 0 === $i ? 'true' : 'false'; ?>"><img src="<?php echo esc_url( wp_get_attachment_image_url( $media_id, 'thumbnail' ) ?: '' ); ?>" alt="" /></button>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>

					<div class="td-box">
						<h2><?php echo TD_Frontend_Views::label( '상세 설명', 'Mô tả chi tiết' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h2>
						<div class="td-desc"><?php echo wp_kses_post( wpautop( $listing['description'] ) ); ?></div>
					</div>

					<?php if ( $specs ) : ?>
						<div class="td-box">
							<h2><?php echo TD_Frontend_Views::label( '상세 정보', 'Thông số chi tiết' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h2>
							<div class="td-specs">
								<?php foreach ( $specs as $spec ) : ?>
									<div class="td-spec"><span><?php echo $spec[1] ? TD_Frontend_Views::label( $spec[0], $spec[1] ) : esc_html( $spec[0] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><strong><?php echo esc_html( $spec[2] ); ?></strong></div>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>

					<div class="td-box">
						<h2><?php echo TD_Frontend_Views::label( '거래 위치', 'Khu vực giao dịch' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h2>
						<div><span aria-hidden="true">📍</span> <?php echo esc_html( TD_Frontend_Views::location_text( $listing ) ); ?>
							<?php if ( $map_link ) : ?> · <a href="<?php echo esc_url( $map_link ); ?>" target="_blank" rel="noopener" class="td-inline-link">지도 보기 · <span lang="vi">Xem bản đồ</span><span class="td-sr-only"> (새 창)</span></a><?php endif; ?>
						</div>
						<?php if ( $maps_key && ( $lat || $lng ) ) : ?>
							<a class="td-map" href="<?php echo esc_url( $map_link ); ?>" target="_blank" rel="noopener" aria-label="<?php echo esc_attr( TD_Frontend_Views::location_text( $listing ) . ' 구글맵에서 보기 (새 창)' ); ?>">
								<img src="<?php echo esc_url( add_query_arg( array( 'center' => "{$lat},{$lng}", 'zoom' => 15, 'size' => '640x260', 'scale' => 2, 'markers' => "color:red|{$lat},{$lng}", 'key' => $maps_key ), 'https://maps.googleapis.com/maps/api/staticmap' ) ); ?>" alt="" loading="lazy" />
							</a>
						<?php endif; ?>
					</div>

					<div class="td-box">
						<h2><?php echo TD_Frontend_Views::label( '안전 거래 팁', 'Mẹo giao dịch an toàn' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h2>
						<ul class="td-tips">
							<li>사람이 많은 공공장소에서 직접 만나 물건을 확인한 뒤 거래하세요. <span class="td-vi" lang="vi">Gặp trực tiếp ở nơi công cộng và kiểm tra hàng trước khi trả tiền.</span></li>
							<li>물건을 받기 전에 선입금하지 마세요. <span class="td-vi" lang="vi">Không chuyển tiền trước khi nhận hàng.</span></li>
							<li>비밀번호·인증번호 등 개인정보를 알려 주지 마세요. <span class="td-vi" lang="vi">Không chia sẻ mật khẩu hoặc mã xác thực.</span></li>
						</ul>
					</div>
				</div>

			</div>

			<?php if ( $similar ) : ?>
				<div class="td-box" style="background:none;padding:16px 0 0">
					<h2><?php echo TD_Frontend_Views::label( '비슷한 매물', 'Tin đăng tương tự' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h2>
					<div class="td-grid">
						<?php foreach ( $similar as $similar_id ) : ?>
							<?php TD_Frontend_Views::render_card( $similar_id, 'grid' ); ?>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $register ) : ?>
				<div class="td-box td-cta">
					<strong><?php echo TD_Frontend_Views::label( '비슷한 물건이 있나요?', 'Bạn có sản phẩm tương tự?' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></strong>
					<a class="td-btn" href="<?php echo esc_url( $register ); ?>">판매 등록 · Đăng bán</a>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( $owner && $viewer_id && ( $is_owner || $is_active ) ) : ?>
			<div class="td-chat-backdrop" id="td-chat-backdrop"></div>
			<section class="td-chat" id="td-chat" role="dialog" aria-modal="true" aria-labelledby="td-chat-title" data-listing="<?php echo (int) $listing['listing_id']; ?>" data-mode="<?php echo $is_owner ? 'owner' : 'counterpart'; ?>">
				<header class="td-chat-head">
					<button type="button" class="td-chat-icon" id="td-chat-back" aria-label="대화 목록 · Danh sách" hidden><span aria-hidden="true">‹</span></button>
					<div class="td-chat-heading">
						<h2 id="td-chat-title">거래 대화 · <span lang="vi">Trò chuyện</span></h2>
						<p class="td-chat-sub" id="td-chat-sub"><?php echo esc_html( $listing['title'] ); ?></p>
					</div>
					<button type="button" class="td-chat-icon" id="td-chat-close" aria-label="닫기 · Đóng"><span aria-hidden="true">×</span></button>
				</header>
				<ul class="td-chat-rooms" id="td-chat-rooms" hidden></ul>
				<ol class="td-chat-log" id="td-chat-log" role="log" aria-live="polite" aria-label="메시지 · Tin nhắn"></ol>
				<p class="td-chat-status" id="td-chat-status" role="status"></p>
				<form class="td-chat-form" id="td-chat-form" hidden>
					<label for="td-chat-input" class="td-sr-only">메시지 입력 · Nhập tin nhắn</label>
					<textarea id="td-chat-input" rows="2" maxlength="<?php echo (int) TD_Chat::MAX_LENGTH; ?>" placeholder="메시지를 입력하세요 · Nhập tin nhắn"></textarea>
					<button type="submit" class="td-btn">전송 · Gửi</button>
				</form>
			</section>
		<?php endif; ?>
	</div>

	<script>
	(function(){
		var g = document.getElementById('td-gallery');
		if (g) {
			var imgs = g.querySelectorAll('img'), thumbs = document.querySelectorAll('.td-thumbs button'), counter = g.querySelector('.td-counter'), cur = 0;
			var show = function(i){
				cur = (i + imgs.length) % imgs.length;
				imgs.forEach(function(el, k){ el.classList.toggle('is-active', k === cur); });
				thumbs.forEach(function(el, k){ el.classList.toggle('is-active', k === cur); el.setAttribute('aria-pressed', k === cur ? 'true' : 'false'); });
				if (counter) { counter.textContent = (cur + 1) + '/' + imgs.length; }
			};
			var prev = g.querySelector('.prev'), next = g.querySelector('.next');
			if (prev) { prev.addEventListener('click', function(){ show(cur - 1); }); }
			if (next) { next.addEventListener('click', function(){ show(cur + 1); }); }
			thumbs.forEach(function(el){ el.addEventListener('click', function(){ show(Number(el.dataset.index)); }); });
		}
		var copy = document.getElementById('td-copy-link');
		if (copy && navigator.clipboard) {
			copy.addEventListener('click', function(){
				navigator.clipboard.writeText(copy.dataset.url).then(function(){ copy.textContent = '✓ 복사됨 · Đã sao chép'; copy.setAttribute('aria-live', 'polite'); });
			});
		}
	})();
	</script>
	<?php
endwhile;

TD_Frontend_Views::footer();
