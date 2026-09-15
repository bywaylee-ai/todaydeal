<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TD_REST_Categories_Controller {

	const NS = 'todaydeal/v1';

	public function register_routes() {
		register_rest_route( self::NS, '/categories', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_categories' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * WP-CAT-001..010. Counts are always null until the dedicated count
	 * table + recompute tooling (spec 10.1) ships in a later stage; per
	 * spec, "아직 계산되지 않은 term은 0이 아니라 null"로 반환한다.
	 */
	public function list_categories( WP_REST_Request $request ) {
		$terms = TD_Taxonomy_Adapter::get_categories();
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$with_counts = 'true' === $request->get_param( 'with_counts' );

		$items = array_map(
			function ( $term ) use ( $with_counts ) {
				$item = array(
					'category_id'  => $term->term_id,
					'parent_id'    => $term->parent ?: null,
					'name'         => $term->name,
					'slug'         => $term->slug,
					'description'  => $term->description,
					'extra_fields' => TD_Category_Fields::get_fields_for_term( $term->term_id ),
					'criteria'     => TD_Criteria::for_category( $term->term_id ),
				);
				if ( $with_counts ) {
					$item['count_sell'] = null;
					$item['count_buy']  = null;
				}
				return $item;
			},
			array_values( $terms )
		);

		return TD_Response::list( $items, null );
	}
}
