<?php
/**
 * Public single view for a todaydeal_deal listing.
 * Data comes from TD_Listings::to_response() - the same normalization the
 * REST API uses (spec 9.1 "공개 전환").
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

TD_Frontend_Views::header();

while ( have_posts() ) :
	the_post();
	$listing = TD_Listings::to_response( get_the_ID() );
	$owner   = TD_Users::public_profile( $listing['owner_user_id'] );
	$fields  = TD_Category_Fields::get_fields_for_terms( $listing['category_ids'] );
	?>
	<style>
		.td-single{max-width:840px;margin:0 auto;padding:24px 16px}
		.td-single h1{margin:0 0 8px}
		.td-single .td-badges{margin-bottom:16px}
		.td-single .td-badge{display:inline-block;padding:3px 10px;border-radius:12px;background:#eee;font-size:13px;margin-right:6px}
		.td-single .td-price{font-size:24px;font-weight:700;margin:12px 0}
		.td-single .td-gallery{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
		.td-single .td-gallery img{width:160px;height:160px;object-fit:cover;border-radius:8px}
		.td-single .td-section{margin:20px 0;padding-top:16px;border-top:1px solid #e5e5e5}
		.td-single .td-section h2{font-size:16px;margin:0 0 8px}
		.td-single .td-row{display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px dashed #eee}
		.td-single .td-owner{display:flex;align-items:center;gap:8px}
	</style>

	<article class="td-single">
		<div class="td-badges">
			<span class="td-badge"><?php echo esc_html( $listing['type_label'] ); ?></span>
			<span class="td-badge"><?php echo esc_html( $listing['status_label'] ); ?></span>
		</div>

		<h1><?php echo esc_html( $listing['title'] ); ?></h1>

		<?php if ( ! empty( $listing['media_ids'] ) ) : ?>
			<div class="td-gallery">
				<?php foreach ( $listing['media_ids'] as $media_id ) : ?>
					<img src="<?php echo esc_url( wp_get_attachment_image_url( $media_id, 'medium' ) ?: '' ); ?>" alt="" />
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<div class="td-price">
			<?php if ( 'sell' === $listing['listing_type'] ) : ?>
				<?php echo esc_html( number_format( (float) $listing['price_min'] ) . ' ' . $listing['currency'] ); ?>
				<?php echo $listing['price_negotiable'] ? '<span class="td-badge">가격 협의 가능</span>' : ''; ?>
			<?php else : ?>
				<?php echo esc_html( number_format( (float) $listing['price_min'] ) . ' ~ ' . number_format( (float) $listing['price_max'] ) . ' ' . $listing['currency'] ); ?>
			<?php endif; ?>
		</div>

		<div class="td-section">
			<h2>설명</h2>
			<p><?php echo wp_kses_post( wpautop( $listing['description'] ) ); ?></p>
		</div>

		<div class="td-section">
			<h2>거래 정보</h2>
			<?php if ( $listing['product_name'] ) : ?><div class="td-row"><span>상품 고유명</span><strong><?php echo esc_html( $listing['product_name'] ); ?></strong></div><?php endif; ?>
			<?php if ( $listing['condition'] ) : ?><div class="td-row"><span>물품 상태</span><strong><?php echo esc_html( $listing['condition'] ); ?></strong></div><?php endif; ?>
			<?php if ( $listing['condition_preference'] ) : ?><div class="td-row"><span>희망 상태</span><strong><?php echo esc_html( $listing['condition_preference'] ); ?></strong></div><?php endif; ?>
			<div class="td-row"><span>위치</span><strong><?php echo esc_html( trim( $listing['location']['city'] . ' ' . $listing['location']['place_name'] ) ); ?></strong><?php if ( $listing['location']['map_url'] ) : ?> <a href="<?php echo esc_url( $listing['location']['map_url'] ); ?>" target="_blank" rel="noopener">지도 보기</a><?php endif; ?></div>
			<?php if ( $listing['preferred_place'] ) : ?><div class="td-row"><span>선호 장소</span><strong><?php echo esc_html( $listing['preferred_place'] ); ?></strong></div><?php endif; ?>
			<?php $tz = TD_Listings::city_timezone( $listing['location']['city'] ); ?>
			<?php if ( $listing['available_time'] ) : ?><div class="td-row"><span>가능 시간</span><strong><?php echo esc_html( $listing['available_time'] . ( TD_Listings::parse_time_range( $listing['available_time'] ) ? ' ' . $tz['label'] : '' ) ); ?></strong></div><?php endif; ?>
			<?php if ( $listing['expires_at'] ) : ?><div class="td-row"><span>만료일</span><strong><?php echo esc_html( wp_date( 'Y-m-d H:i', strtotime( $listing['expires_at'] . ' UTC' ), $tz['timezone'] ) . ' ' . $tz['label'] ); ?></strong></div><?php endif; ?>
		</div>

		<?php if ( ! empty( $fields ) ) : ?>
			<div class="td-section">
				<h2>추가 정보</h2>
				<?php foreach ( $fields as $field ) :
					$value = $listing['extra_fields'][ $field['key'] ] ?? null;
					if ( null === $value || '' === $value ) {
						continue;
					}
					?>
					<div class="td-row"><span><?php echo esc_html( $field['label'] ); ?></span><strong><?php echo esc_html( is_bool( $value ) ? ( $value ? '예' : '아니오' ) : $value ); ?></strong></div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<div class="td-section td-owner">
			<div>
				<strong><?php echo esc_html( $owner['nickname'] ?? '' ); ?></strong>
				<?php if ( ! empty( $owner['rating_average'] ) ) : ?>
					· ★ <?php echo esc_html( $owner['rating_average'] ); ?> (<?php echo (int) $owner['review_count']; ?>)
				<?php endif; ?>
			</div>
		</div>
	</article>
	<?php
endwhile;
?>

<?php TD_Frontend_Views::footer(); ?>
