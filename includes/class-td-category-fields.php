<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Category-scoped extra fields (admin-managed, required/optional per
 * category). Not in the original spec's fixed 9.4/9.5 field set - added at
 * the user's request so operators can collect category-specific info
 * (e.g. "warranty period" for electronics) without a code change.
 */
class TD_Category_Fields {

	const META_KEY   = '_todaydeal_extra_fields';
	const VALUE_META = '_todaydeal_extra_values';
	const TYPES      = array( 'text', 'number', 'date', 'select', 'checkbox' );

	/**
	 * @return array list of {key,label,type,required,options[]} for one term.
	 */
	public static function get_fields_for_term( $term_id ) {
		$raw = get_term_meta( $term_id, self::META_KEY, true );
		$decoded = $raw ? json_decode( $raw, true ) : array();
		return is_array( $decoded ) ? $decoded : array();
	}

	public static function save_fields_for_term( $term_id, array $fields ) {
		$clean = array();
		foreach ( $fields as $field ) {
			$key = sanitize_key( $field['key'] ?? '' );
			if ( '' === $key ) {
				continue;
			}
			$type = in_array( $field['type'] ?? '', self::TYPES, true ) ? $field['type'] : 'text';
			$entry = array(
				'key'      => $key,
				'label'    => sanitize_text_field( $field['label'] ?? $key ),
				'type'     => $type,
				'required' => ! empty( $field['required'] ),
			);
			if ( 'select' === $type ) {
				$options = array_filter( array_map( 'trim', explode( ',', (string) ( $field['options'] ?? '' ) ) ) );
				$entry['options'] = array_values( $options );
			}
			$clean[] = $entry;
		}
		update_term_meta( $term_id, self::META_KEY, wp_json_encode( $clean, JSON_UNESCAPED_UNICODE ) );
		return $clean;
	}

	/**
	 * Merges field definitions across every category a listing belongs to.
	 * A duplicate `key` keeps the first definition seen and becomes required
	 * if required in ANY of the listing's categories.
	 *
	 * @param int[] $term_ids
	 */
	public static function get_fields_for_terms( array $term_ids ) {
		$merged = array();
		foreach ( $term_ids as $term_id ) {
			foreach ( self::get_fields_for_term( $term_id ) as $field ) {
				if ( isset( $merged[ $field['key'] ] ) ) {
					$merged[ $field['key'] ]['required'] = $merged[ $field['key'] ]['required'] || $field['required'];
					continue;
				}
				$merged[ $field['key'] ] = $field;
			}
		}
		return array_values( $merged );
	}

	/**
	 * Validates + sanitizes submitted extra-field values against the
	 * definitions for the listing's chosen categories.
	 *
	 * @return array|WP_Error sanitized {key: value} map, or a VALIDATION_ERROR.
	 */
	public static function validate_and_sanitize( array $term_ids, array $raw_values ) {
		$defs  = self::get_fields_for_terms( $term_ids );
		$clean = array();

		foreach ( $defs as $field ) {
			$key   = $field['key'];
			$value = $raw_values[ $key ] ?? null;

			if ( $field['required'] && ( null === $value || '' === $value ) ) {
				return TD_Response::error(
					'VALIDATION_ERROR',
					sprintf( '%s 항목은 필수입니다.', $field['label'] ),
					array( 'field' => 'extra_fields.' . $key )
				);
			}

			if ( null === $value || '' === $value ) {
				continue;
			}

			switch ( $field['type'] ) {
				case 'number':
					$clean[ $key ] = (float) $value;
					break;
				case 'checkbox':
					$clean[ $key ] = (bool) $value;
					break;
				case 'date':
					$ts = strtotime( (string) $value );
					if ( ! $ts ) {
						return TD_Response::error( 'VALIDATION_ERROR', sprintf( '%s 날짜 형식이 올바르지 않습니다.', $field['label'] ), array( 'field' => 'extra_fields.' . $key ) );
					}
					$clean[ $key ] = gmdate( 'Y-m-d', $ts );
					break;
				case 'select':
					if ( ! empty( $field['options'] ) && ! in_array( (string) $value, $field['options'], true ) ) {
						return TD_Response::error( 'VALIDATION_ERROR', sprintf( '%s 값이 올바르지 않습니다.', $field['label'] ), array( 'field' => 'extra_fields.' . $key ) );
					}
					$clean[ $key ] = sanitize_text_field( $value );
					break;
				default:
					$clean[ $key ] = sanitize_text_field( $value );
			}
		}

		return $clean;
	}

	public static function get_values_for_listing( $post_id ) {
		$raw = get_post_meta( $post_id, self::VALUE_META, true );
		$decoded = $raw ? json_decode( $raw, true ) : array();
		return is_array( $decoded ) ? $decoded : array();
	}

	public static function save_values_for_listing( $post_id, array $values ) {
		update_post_meta( $post_id, self::VALUE_META, wp_json_encode( $values, JSON_UNESCAPED_UNICODE ) );
	}
}
