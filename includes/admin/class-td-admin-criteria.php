<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Category (product_cat) edit screen: pick which reusable "하위 별점 항목"
 * (TD_Criteria) apply to that category's listings.
 */
class TD_Admin_Criteria {

	const NONCE_ACTION = 'todaydeal_save_category_criteria';
	const NONCE_NAME   = 'todaydeal_category_criteria_nonce';

	public static function init() {
		add_action( 'product_cat_edit_form_fields', array( __CLASS__, 'render_edit_field' ) );
		add_action( 'product_cat_add_form_fields', array( __CLASS__, 'render_add_field' ) );
		add_action( 'edited_product_cat', array( __CLASS__, 'save_field' ) );
		add_action( 'created_product_cat', array( __CLASS__, 'save_field' ) );
	}

	public static function render_add_field() {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<div class="form-field">
			<label><?php esc_html_e( '하위 별점 항목', 'todaydeal' ); ?></label>
			<?php self::render_checkboxes( array() ); ?>
			<p class="description"><?php esc_html_e( '이 카테고리 거래글의 완료된 약속을 평가할 때 사용할 하위 별점 항목입니다. 항목은 여러 카테고리에서 공유됩니다.', 'todaydeal' ); ?></p>
		</div>
		<?php
	}

	public static function render_edit_field( $term ) {
		$selected = TD_Criteria::ids_for_category( $term->term_id );
		?>
		<tr class="form-field">
			<th scope="row"><label><?php esc_html_e( '하위 별점 항목', 'todaydeal' ); ?></label></th>
			<td>
				<?php
				wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
				self::render_checkboxes( $selected );
				?>
				<p class="description">
					<?php esc_html_e( '체크한 항목이 이 카테고리 거래의 평가 항목이 됩니다. 항목 자체는', 'todaydeal' ); ?>
					<a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=' . TD_Criteria::TAXONOMY . '&post_type=' . TD_Post_Type::POST_TYPE ) ); ?>"><?php esc_html_e( '하위 별점 항목', 'todaydeal' ); ?></a>
					<?php esc_html_e( '화면에서 추가·편집·삭제합니다.', 'todaydeal' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	private static function render_checkboxes( $selected ) {
		$all = TD_Criteria::all();

		if ( ! $all ) {
			printf(
				'<p><em>%s</em> <a href="%s">%s</a></p>',
				esc_html__( '등록된 하위 별점 항목이 없습니다.', 'todaydeal' ),
				esc_url( admin_url( 'edit-tags.php?taxonomy=' . TD_Criteria::TAXONOMY . '&post_type=' . TD_Post_Type::POST_TYPE ) ),
				esc_html__( '지금 추가', 'todaydeal' )
			);
			return;
		}

		$ordered = array();
		foreach ( $selected as $id ) {
			foreach ( $all as $c ) {
				if ( $c['id'] === (int) $id ) {
					$ordered[] = $c;
				}
			}
		}
		foreach ( $all as $c ) {
			if ( ! in_array( $c['id'], $selected, true ) ) {
				$ordered[] = $c;
			}
		}

		echo '<ul style="margin:.25em 0;max-height:220px;overflow:auto;border:1px solid #dcdcde;padding:.5em .75em;border-radius:4px;">';
		foreach ( $ordered as $c ) {
			printf(
				'<li style="margin:.15em 0;"><label><input type="checkbox" name="todaydeal_criteria[]" value="%1$d" %2$s> %3$s</label></li>',
				(int) $c['id'],
				checked( in_array( $c['id'], $selected, true ), true, false ),
				esc_html( $c['name'] )
			);
		}
		echo '</ul>';
	}

	public static function save_field( $term_id ) {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_product_terms' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$raw = isset( $_POST['todaydeal_criteria'] ) ? (array) wp_unslash( $_POST['todaydeal_criteria'] ) : array();
		TD_Criteria::save_links_for_category( $term_id, $raw );
	}
}
