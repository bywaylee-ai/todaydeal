<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reusable rating sub-items ("하위 별점 항목") and their category links.
 * Added at the user's request, mirroring the BrandTalk plugin's
 * category/criterion pattern but implemented natively in TodayDeal:
 *
 *  - Sub rating item = `todaydeal_criterion` taxonomy term (plain WP term CRUD).
 *  - Category <-> item link = `product_cat` term meta `_todaydeal_criteria`
 *    (ordered array of criterion term ids).
 *  - The same item can be reused across multiple categories.
 */
class TD_Criteria {

	const TAXONOMY      = 'todaydeal_criterion';
	const TERM_META_KEY = '_todaydeal_criteria';

	public static function register() {
		register_taxonomy(
			self::TAXONOMY,
			TD_Post_Type::POST_TYPE,
			array(
				'label'             => '하위 별점 항목',
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => true,
				'show_admin_column' => false,
				'hierarchical'      => false,
				'show_in_rest'      => false,
				'meta_box_cb'       => false, // not assigned per-listing; it's a rating vocabulary.
				'rewrite'           => false,
				'labels'            => array(
					'name'          => '하위 별점 항목',
					'singular_name' => '하위 별점 항목',
					'menu_name'     => '하위 별점 항목',
					'all_items'     => '모든 하위 별점 항목',
					'add_new_item'  => '하위 별점 항목 추가',
					'edit_item'     => '하위 별점 항목 편집',
					'search_items'  => '하위 별점 항목 검색',
					'not_found'     => '하위 별점 항목이 없습니다.',
				),
			)
		);

		add_action( 'delete_' . self::TAXONOMY, array( __CLASS__, 'prune_deleted_criterion' ) );
	}

	/**
	 * Removes a deleted criterion from every category's link list.
	 */
	public static function prune_deleted_criterion( $term_id ) {
		if ( ! TD_Taxonomy_Adapter::is_available() ) {
			return;
		}

		$term_id    = (int) $term_id;
		$categories = get_terms(
			array(
				'taxonomy'   => TD_Taxonomy_Adapter::TAXONOMY,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);

		if ( is_wp_error( $categories ) ) {
			return;
		}

		foreach ( $categories as $cat_id ) {
			$ids = array_values( array_diff( self::ids_for_category( $cat_id ), array( $term_id ) ) );
			if ( $ids ) {
				update_term_meta( (int) $cat_id, self::TERM_META_KEY, $ids );
			} else {
				delete_term_meta( (int) $cat_id, self::TERM_META_KEY );
			}
		}
	}

	/**
	 * @return array [{id, slug, name, description}] every defined criterion.
	 */
	public static function all() {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		return array_map( array( __CLASS__, 'shape' ), $terms );
	}

	/**
	 * @return int[] criterion ids linked to a category, in saved order.
	 */
	public static function ids_for_category( $category_id ) {
		$ids = get_term_meta( (int) $category_id, self::TERM_META_KEY, true );
		if ( ! is_array( $ids ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'intval', $ids ) ) );
	}

	public static function for_category( $category_id ) {
		$out = array();
		foreach ( self::ids_for_category( $category_id ) as $criterion_id ) {
			$term = get_term( $criterion_id, self::TAXONOMY );
			if ( $term && ! is_wp_error( $term ) ) {
				$out[] = self::shape( $term );
			}
		}
		return $out;
	}

	/**
	 * Union of criteria across several categories (order preserved, deduped).
	 */
	public static function for_categories( array $category_ids ) {
		$seen = array();
		$out  = array();
		foreach ( $category_ids as $cat_id ) {
			foreach ( self::for_category( $cat_id ) as $criterion ) {
				if ( ! isset( $seen[ $criterion['id'] ] ) ) {
					$seen[ $criterion['id'] ] = true;
					$out[]                    = $criterion;
				}
			}
		}
		return $out;
	}

	public static function is_valid_for_categories( $criterion_id, array $category_ids ) {
		foreach ( $category_ids as $cat_id ) {
			if ( in_array( (int) $criterion_id, self::ids_for_category( $cat_id ), true ) ) {
				return true;
			}
		}
		return false;
	}

	public static function save_links_for_category( $category_id, array $criterion_ids ) {
		$valid = array();
		foreach ( $criterion_ids as $id ) {
			$id   = (int) $id;
			$term = $id ? get_term( $id, self::TAXONOMY ) : null;
			if ( $term && ! is_wp_error( $term ) ) {
				$valid[] = $id;
			}
		}
		$valid = array_values( array_unique( $valid ) );

		if ( $valid ) {
			update_term_meta( (int) $category_id, self::TERM_META_KEY, $valid );
		} else {
			delete_term_meta( (int) $category_id, self::TERM_META_KEY );
		}
	}

	public static function shape( $term ) {
		return array(
			'id'          => (int) $term->term_id,
			'slug'        => $term->slug,
			'name'        => $term->name,
			'description' => $term->description,
		);
	}
}
