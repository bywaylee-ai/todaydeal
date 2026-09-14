<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Isolates every WooCommerce product_cat access behind one adapter
 * (spec 4.1, WP-CAT-010) so the slug is never referenced elsewhere and the
 * dependency can be swapped/removed later without touching call sites.
 */
class TD_Taxonomy_Adapter {

	const TAXONOMY = 'product_cat';

	public static function is_available() {
		return taxonomy_exists( self::TAXONOMY );
	}

	private static function unavailable_error() {
		return TD_Response::error(
			'TAXONOMY_UNAVAILABLE',
			'WooCommerce가 비활성화되어 카테고리 기능을 사용할 수 없습니다.'
		);
	}

	/**
	 * WP-CAT-001: register product_cat onto the listing post type.
	 */
	public static function register_for_listings( $post_type ) {
		if ( ! self::is_available() ) {
			return;
		}
		register_taxonomy_for_object_type( self::TAXONOMY, $post_type );
	}

	/**
	 * WP-CAT-002/004/005: active, ordered category tree.
	 *
	 * @return array|WP_Error
	 */
	public static function get_categories( $args = array() ) {
		if ( ! self::is_available() ) {
			return self::unavailable_error();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$active_terms = array_filter(
			$terms,
			function ( $term ) {
				$active = get_term_meta( $term->term_id, '_todaydeal_active', true );
				return '' === $active || '1' === $active;
			}
		);

		// WP-CAT-005: order by the WooCommerce term-order meta when present,
		// falling back to name for terms that were never explicitly ordered.
		// (get_terms() with orderby=meta_value_num would silently drop terms
		// missing that meta entirely, so sorting is done here instead.)
		usort(
			$active_terms,
			function ( $a, $b ) {
				$order_a = get_term_meta( $a->term_id, 'order', true );
				$order_b = get_term_meta( $b->term_id, 'order', true );
				if ( '' !== $order_a && '' !== $order_b && (int) $order_a !== (int) $order_b ) {
					return (int) $order_a <=> (int) $order_b;
				}
				return strcasecmp( $a->name, $b->name );
			}
		);

		return array_values( $active_terms );
	}

	public static function get_term( $term_id ) {
		if ( ! self::is_available() ) {
			return self::unavailable_error();
		}
		$term = get_term( $term_id, self::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}
		return $term;
	}

	public static function term_exists( $term_id ) {
		if ( ! self::is_available() ) {
			return false;
		}
		return (bool) term_exists( (int) $term_id, self::TAXONOMY );
	}

	/**
	 * @return int[] ids of $term_id plus every ancestor, for rollup counting.
	 */
	public static function ancestors_and_self( $term_id ) {
		if ( ! self::is_available() ) {
			return array( (int) $term_id );
		}
		$ancestors   = get_ancestors( $term_id, self::TAXONOMY );
		$ancestors[] = (int) $term_id;
		return array_map( 'intval', $ancestors );
	}

	public static function set_listing_terms( $post_id, array $term_ids ) {
		if ( ! self::is_available() ) {
			return self::unavailable_error();
		}
		return wp_set_object_terms( $post_id, array_map( 'intval', $term_ids ), self::TAXONOMY );
	}

	public static function get_listing_terms( $post_id ) {
		if ( ! self::is_available() ) {
			return array();
		}
		$terms = wp_get_object_terms( $post_id, self::TAXONOMY );
		return is_wp_error( $terms ) ? array() : $terms;
	}
}
