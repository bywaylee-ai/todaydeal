<?php
/**
 * Listing list body shared by the archive and [todaydeal_listings] pages:
 * search bar, category chips, type tabs + sort, list-style cards, pagination.
 *
 * Expects:
 *   $td_query WP_Query  listings to show (filters already applied)
 *   $td_base  string    URL the filter links/forms point at
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$filters     = TD_Frontend_Views::archive_filters();
$categories  = TD_Frontend_Views::top_categories();
$total       = (int) $td_query->found_posts;
$current_cat = $filters['category'] ? get_term( $filters['category'], TD_Taxonomy_Adapter::TAXONOMY ) : null;
$register    = TD_Frontend_Views::register_url();
$columns     = $filters['cols'] ?: TD_Frontend_Views::DEFAULT_COLUMNS;
?>
<div class="td-page">
	<div class="td-wrap">
		<h1 class="td-sr-only">TodayDeal 거래글 목록 <span lang="vi">Danh sách tin đăng</span></h1>
		<nav class="td-crumbs" aria-label="현재 위치">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></a>
			<span class="sep" aria-hidden="true">›</span>
			<a href="<?php echo esc_url( $td_base ); ?>">TodayDeal</a>
			<?php if ( $filters['city'] ) : ?>
				<span class="sep" aria-hidden="true">›</span><span><?php echo esc_html( TD_Listings::PLACE_PRESETS[ $filters['city'] ]['label'] ); ?></span>
			<?php endif; ?>
			<?php if ( $current_cat && ! is_wp_error( $current_cat ) ) : ?>
				<span class="sep" aria-hidden="true">›</span><span><?php echo esc_html( $current_cat->name ); ?></span>
			<?php endif; ?>
		</nav>

		<div class="td-box">
			<form class="td-search" method="get" action="<?php echo esc_url( $td_base ); ?>" role="search">
				<label class="td-sr-only" for="td-q">검색어 · Từ khóa</label>
				<input type="search" id="td-q" name="q" value="<?php echo esc_attr( $filters['q'] ); ?>" placeholder="찾는 물건을 검색하세요 · Tìm kiếm sản phẩm" />
				<label class="td-sr-only" for="td-city">지역 · Khu vực</label>
				<select name="city" id="td-city">
					<option value="">전체 지역 · Toàn quốc</option>
					<?php foreach ( TD_Listings::PLACE_PRESETS as $key => $preset ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['city'], $key ); ?>><?php echo esc_html( $preset['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php foreach ( array( 'category', 'type', 'sort', 'cols' ) as $keep ) : ?>
					<?php if ( $filters[ $keep ] ) : ?><input type="hidden" name="<?php echo esc_attr( $keep ); ?>" value="<?php echo esc_attr( $filters[ $keep ] ); ?>" /><?php endif; ?>
				<?php endforeach; ?>
				<button type="submit" class="td-btn">검색 <span class="td-vi" lang="vi" style="color:inherit">Tìm</span></button>
				<?php if ( $register ) : ?>
					<a class="td-btn is-ghost" href="<?php echo esc_url( $register ); ?>">+ 판매 등록 <span class="td-vi" lang="vi">Đăng tin</span></a>
				<?php endif; ?>
			</form>

			<?php if ( $categories ) : ?>
				<div class="td-chips" role="navigation" aria-label="카테고리 · Danh mục">
					<a class="td-chip <?php echo $filters['category'] ? '' : 'is-active'; ?>" <?php echo $filters['category'] ? '' : 'aria-current="true"'; ?> href="<?php echo esc_url( TD_Frontend_Views::archive_url( array_merge( $filters, array( 'category' => 0 ) ), $td_base ) ); ?>">전체 · Tất cả</a>
					<?php foreach ( $categories as $cat ) : ?>
						<a class="td-chip <?php echo (int) $cat->term_id === $filters['category'] ? 'is-active' : ''; ?>" <?php echo (int) $cat->term_id === $filters['category'] ? 'aria-current="true"' : ''; ?> href="<?php echo esc_url( TD_Frontend_Views::archive_url( array_merge( $filters, array( 'category' => $cat->term_id ) ), $td_base ) ); ?>"><?php echo esc_html( $cat->name ); ?></a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>

		<div class="td-toolbar">
			<div class="td-tabs" role="navigation" aria-label="거래 유형 · Loại tin">
				<?php
				$tabs = array(
					''     => array( '전체', 'Tất cả' ),
					'sell' => array( '팔아요', 'Bán' ),
					'buy'  => array( '구해요', 'Cần mua' ),
				);
				foreach ( $tabs as $type => $text ) :
					?>
					<a class="<?php echo $filters['type'] === $type ? 'is-active' : ''; ?>" <?php echo $filters['type'] === $type ? 'aria-current="true"' : ''; ?> href="<?php echo esc_url( TD_Frontend_Views::archive_url( array_merge( $filters, array( 'type' => $type ) ), $td_base ) ); ?>"><?php echo esc_html( $text[0] . ' · ' . $text[1] ); ?></a>
				<?php endforeach; ?>
			</div>
			<form class="td-sort" method="get" action="<?php echo esc_url( $td_base ); ?>">
				<span><?php echo TD_Frontend_Views::label( "{$total}건", "{$total} tin" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- label() escapes. ?></span>
				<span class="td-cols" role="group" aria-label="1행당 열 수 · Số cột">
					<span aria-hidden="true"><?php echo TD_Frontend_Views::label( '열', 'Cột' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<?php foreach ( TD_Frontend_Views::COLUMN_CHOICES as $n ) : ?>
						<a class="<?php echo $columns === $n ? 'is-active' : ''; ?>" <?php echo $columns === $n ? 'aria-current="true"' : ''; ?> aria-label="<?php echo esc_attr( "{$n}열" ); ?>" href="<?php echo esc_url( TD_Frontend_Views::archive_url( array_merge( $filters, array( 'cols' => TD_Frontend_Views::DEFAULT_COLUMNS === $n ? 0 : $n ) ), $td_base ) ); ?>"><?php echo (int) $n; ?></a>
					<?php endforeach; ?>
				</span>
				<?php foreach ( array( 'q', 'category', 'city', 'type', 'cols' ) as $keep ) : ?>
					<?php if ( $filters[ $keep ] ) : ?><input type="hidden" name="<?php echo esc_attr( $keep ); ?>" value="<?php echo esc_attr( $filters[ $keep ] ); ?>" /><?php endif; ?>
				<?php endforeach; ?>
				<?php // 선택만으로 페이지가 바뀌지 않도록(WCAG 3.2.2) 자동 제출 대신 적용 버튼을 둔다. ?>
				<label class="td-sr-only" for="td-sort">정렬 · Sắp xếp</label>
				<select name="sort" id="td-sort">
					<option value="">최신순 · Mới nhất</option>
					<option value="price_asc" <?php selected( $filters['sort'], 'price_asc' ); ?>>가격 낮은순 · Giá thấp</option>
					<option value="price_desc" <?php selected( $filters['sort'], 'price_desc' ); ?>>가격 높은순 · Giá cao</option>
				</select>
				<button type="submit" class="td-btn">정렬 <span class="td-vi" lang="vi" style="color:inherit">Áp dụng</span></button>
			</form>
		</div>

		<?php if ( $td_query->have_posts() ) : ?>
			<div class="td-cards td-cols-<?php echo (int) $columns; ?>">
				<?php
				while ( $td_query->have_posts() ) :
					$td_query->the_post();
					TD_Frontend_Views::render_card( get_the_ID(), 'card' );
				endwhile;
				?>
			</div>

			<div class="td-pagination">
				<?php echo wp_kses_post( paginate_links( array( 'total' => $td_query->max_num_pages, 'current' => TD_Frontend_Views::current_page(), 'prev_text' => '‹', 'next_text' => '›' ) ) ?: '' ); ?>
			</div>
		<?php else : ?>
			<div class="td-empty">
				<?php echo TD_Frontend_Views::label( '조건에 맞는 거래글이 없습니다.', 'Không có tin đăng phù hợp.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		<?php endif; ?>
	</div>
</div>
