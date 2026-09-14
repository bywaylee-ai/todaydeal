<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal admin status screen (spec 19장 대시보드 / activation requirement
 * "조건이 충족되지 않으면 원인을 관리자 화면에 표시한다"). Settings/webhook/
 * moderation screens are stage 6 scope and not built here.
 */
class TD_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	public static function register_menu() {
		add_menu_page(
			'TodayDeal',
			'TodayDeal',
			'manage_options',
			'todaydeal-status',
			array( __CLASS__, 'render_status_page' ),
			'dashicons-store',
			56
		);
	}

	public static function maybe_requirements_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$issues = TD_Install::check_requirements();
		if ( empty( $issues ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>TodayDeal:</strong> ' . esc_html( implode( ' / ', $issues ) ) . '</p></div>';
	}

	public static function render_status_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$issues       = TD_Install::check_requirements();
		$schema_ver   = TD_DB::get_schema_version();
		$index_warn   = get_option( 'todaydeal_postmeta_index_warning' );
		$wc_active    = TD_Install::is_woocommerce_active();
		$tax_ok       = TD_Taxonomy_Adapter::is_available();

		echo '<div class="wrap"><h1>TodayDeal 상태</h1>';

		echo '<h2>환경 검사</h2><table class="widefat"><tbody>';
		printf( '<tr><td>플러그인 버전</td><td>%s</td></tr>', esc_html( TODAYDEAL_VERSION ) );
		printf( '<tr><td>스키마 버전</td><td>%s</td></tr>', esc_html( $schema_ver ) );
		printf( '<tr><td>WooCommerce 활성화</td><td>%s</td></tr>', $wc_active ? '✅' : '❌' );
		printf( '<tr><td>product_cat taxonomy</td><td>%s</td></tr>', $tax_ok ? '✅' : '❌ TAXONOMY_UNAVAILABLE' );
		printf( '<tr><td>postmeta 보조 인덱스</td><td>%s</td></tr>', $index_warn ? '⚠️ ' . esc_html( $index_warn ) : '✅' );
		echo '</tbody></table>';

		if ( ! empty( $issues ) ) {
			echo '<h2>미해결 항목</h2><ul>';
			foreach ( $issues as $issue ) {
				echo '<li style="color:#b32d2e;">' . esc_html( $issue ) . '</li>';
			}
			echo '</ul>';
		}

		echo '<h2>설정</h2><table class="widefat"><tbody>';
		foreach ( get_option( 'todaydeal_settings', array() ) as $key => $value ) {
			printf( '<tr><td>%s</td><td>%s</td></tr>', esc_html( $key ), esc_html( is_scalar( $value ) ? $value : wp_json_encode( $value ) ) );
		}
		echo '</tbody></table>';

		echo '</div>';
	}
}
