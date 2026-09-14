<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a "TodayDeal 추가 필드" repeater to the product_cat term edit screen,
 * so operators can define category-specific required/optional fields
 * (spec extension - not in the original functional doc).
 */
class TD_Admin_Category_Fields {

	const NONCE_ACTION = 'todaydeal_save_category_fields';

	public static function init() {
		add_action( 'product_cat_edit_form_fields', array( __CLASS__, 'render_edit_form' ), 20 );
		add_action( 'product_cat_add_form_fields', array( __CLASS__, 'render_add_form' ), 20 );
		add_action( 'edited_product_cat', array( __CLASS__, 'save' ) );
		add_action( 'created_product_cat', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue( $hook ) {
		if ( ! in_array( $hook, array( 'term.php', 'edit-tags.php' ), true ) ) {
			return;
		}
		wp_add_inline_script( 'jquery', self::repeater_js(), 'after' );
	}

	public static function render_edit_form( $term ) {
		$fields = TD_Category_Fields::get_fields_for_term( $term->term_id );
		wp_nonce_field( self::NONCE_ACTION, 'todaydeal_fields_nonce' );
		?>
		<tr class="form-field">
			<th scope="row"><label>TodayDeal 추가 필드</label></th>
			<td><?php self::render_repeater( $fields ); ?></td>
		</tr>
		<?php
	}

	public static function render_add_form() {
		wp_nonce_field( self::NONCE_ACTION, 'todaydeal_fields_nonce' );
		?>
		<div class="form-field">
			<label>TodayDeal 추가 필드</label>
			<?php self::render_repeater( array() ); ?>
		</div>
		<?php
	}

	private static function render_repeater( array $fields ) {
		?>
		<table class="td-fields-repeater widefat" style="max-width:720px">
			<thead>
				<tr>
					<th>키(영문)</th>
					<th>표시명</th>
					<th>유형</th>
					<th>선택지(콤마구분)</th>
					<th>필수</th>
					<th></th>
				</tr>
			</thead>
			<tbody class="td-fields-rows">
				<?php foreach ( $fields as $field ) : ?>
					<?php self::render_row( $field ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><button type="button" class="button td-add-field-row">+ 필드 추가</button></p>
		<template class="td-field-row-template"><table><tbody><?php self::render_row( array() ); ?></tbody></table></template>
		<?php
	}

	private static function render_row( array $field ) {
		$key      = esc_attr( $field['key'] ?? '' );
		$label    = esc_attr( $field['label'] ?? '' );
		$type     = $field['type'] ?? 'text';
		$options  = esc_attr( implode( ', ', $field['options'] ?? array() ) );
		$required = ! empty( $field['required'] );
		?>
		<tr class="td-field-row">
			<td><input type="text" name="todaydeal_fields[key][]" value="<?php echo $key; ?>" placeholder="warranty_period" /></td>
			<td><input type="text" name="todaydeal_fields[label][]" value="<?php echo $label; ?>" placeholder="보증기간" /></td>
			<td>
				<select name="todaydeal_fields[type][]">
					<?php foreach ( TD_Category_Fields::TYPES as $t ) : ?>
						<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $type, $t ); ?>><?php echo esc_html( $t ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><input type="text" name="todaydeal_fields[options][]" value="<?php echo $options; ?>" placeholder="1개월, 6개월, 1년" /></td>
			<td style="text-align:center"><input type="checkbox" name="todaydeal_fields[required][]" value="<?php echo $key ?: '__new__'; ?>" <?php checked( $required ); ?> /></td>
			<td><button type="button" class="button-link td-remove-field-row" style="color:#b32d2e">삭제</button></td>
		</tr>
		<?php
	}

	public static function save( $term_id ) {
		if ( ! isset( $_POST['todaydeal_fields_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['todaydeal_fields_nonce'] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_product_terms' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$raw = wp_unslash( $_POST['todaydeal_fields'] ?? array() );
		$keys     = (array) ( $raw['key'] ?? array() );
		$labels   = (array) ( $raw['label'] ?? array() );
		$types    = (array) ( $raw['type'] ?? array() );
		$options  = (array) ( $raw['options'] ?? array() );
		$required_keys = (array) ( $raw['required'] ?? array() );

		$fields = array();
		foreach ( $keys as $i => $key ) {
			$key = sanitize_key( $key );
			if ( '' === $key ) {
				continue;
			}
			$fields[] = array(
				'key'      => $key,
				'label'    => $labels[ $i ] ?? $key,
				'type'     => $types[ $i ] ?? 'text',
				'options'  => $options[ $i ] ?? '',
				'required' => in_array( $key, $required_keys, true ),
			);
		}

		TD_Category_Fields::save_fields_for_term( $term_id, $fields );
	}

	private static function repeater_js() {
		return <<<JS
jQuery(function(\$){
	function bindRemove(scope){
		scope.find('.td-remove-field-row').off('click').on('click', function(){
			\$(this).closest('tr').remove();
		});
	}
	\$(document).on('click', '.td-add-field-row', function(){
		var tpl = \$(this).closest('td, div').find('.td-field-row-template');
		if (!tpl.length) { tpl = \$('.td-field-row-template'); }
		var row = \$(tpl.html()).find('tr.td-field-row');
		var body = \$(this).closest('td, div').find('.td-fields-rows');
		if (!body.length) { body = \$('.td-fields-rows'); }
		body.append(row);
		bindRemove(row);
	});
	bindRemove(\$(document));
});
JS;
	}
}
