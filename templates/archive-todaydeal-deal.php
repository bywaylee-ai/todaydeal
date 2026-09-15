<?php
/**
 * Public archive view for todaydeal_deal listings.
 * Data comes from TD_Listings::to_response() - the same normalization the
 * REST API uses (spec 9.1 "공개 전환").
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$current_type = sanitize_key( $_GET['type'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter link, no state change.
$archive_url  = get_post_type_archive_link( TD_Post_Type::POST_TYPE );
?>
<style>
	.td-archive{max-width:1080px;margin:0 auto;padding:24px 16px}
	.td-archive .td-filters{margin-bottom:20px}
	.td-archive .td-filters a{display:inline-block;padding:6px 14px;border-radius:14px;background:#eee;color:#333;text-decoration:none;margin-right:8px}
	.td-archive .td-filters a.is-active{background:#333;color:#fff}
	.td-archive .td-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px}
	.td-archive .td-card{display:block;color:inherit;text-decoration:none;border:1px solid #eee;border-radius:8px;overflow:hidden}
	.td-archive .td-card img{width:100%;aspect-ratio:1;object-fit:cover;display:block;background:#f5f5f5}
	.td-archive .td-card .td-body{padding:10px}
	.td-archive .td-card .td-title{font-weight:600;margin:0 0 4px;font-size:15px}
	.td-archive .td-card .td-price{font-weight:700}
	.td-archive .td-card .td-meta{color:#777;font-size:12px;margin-top:4px}
	.td-archive .td-empty{padding:40px 0;text-align:center;color:#777}
	.td-archive .td-pagination{margin-top:24px;display:flex;gap:8px}
</style>

<div class="td-archive">
	<div class="td-filters">
		<a href="<?php echo esc_url( $archive_url ); ?>" class="<?php echo '' === $current_type ? 'is-active' : ''; ?>">전체</a>
		<a href="<?php echo esc_url( add_query_arg( 'type', 'sell', $archive_url ) ); ?>" class="<?php echo 'sell' === $current_type ? 'is-active' : ''; ?>">팔아요</a>
		<a href="<?php echo esc_url( add_query_arg( 'type', 'buy', $archive_url ) ); ?>" class="<?php echo 'buy' === $current_type ? 'is-active' : ''; ?>">구해요</a>
	</div>

	<?php if ( have_posts() ) : ?>
		<div class="td-grid">
			<?php
			while ( have_posts() ) :
				the_post();
				$listing   = TD_Listings::to_response( get_the_ID() );
				$thumb_id  = $listing['media_ids'][0] ?? null;
				$price     = 'sell' === $listing['listing_type'] ? $listing['price_min'] : $listing['price_min'] . '~' . $listing['price_max'];
				?>
				<a class="td-card" href="<?php the_permalink(); ?>">
					<img src="<?php echo esc_url( $thumb_id ? ( wp_get_attachment_image_url( $thumb_id, 'medium' ) ?: '' ) : '' ); ?>" alt="" />
					<div class="td-body">
						<p class="td-title"><?php echo esc_html( $listing['title'] ); ?></p>
						<p class="td-price"><?php echo esc_html( number_format( (float) str_replace( '~', '', $price ) ) . ' ' . $listing['currency'] ); ?></p>
						<p class="td-meta"><?php echo esc_html( $listing['status_label'] . ' · ' . $listing['location']['city'] ); ?></p>
					</div>
				</a>
			<?php endwhile; ?>
		</div>

		<div class="td-pagination">
			<?php echo wp_kses_post( paginate_links( array( 'total' => $GLOBALS['wp_query']->max_num_pages ) ) ?: '' ); ?>
		</div>
	<?php else : ?>
		<p class="td-empty">등록된 거래글이 없습니다.</p>
	<?php endif; ?>
</div>
<?php
get_footer();
