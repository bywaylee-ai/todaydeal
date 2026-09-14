<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media upload/validation/derivatives (spec section 12).
 */
class TD_Media {

	const META_UPLOADED_BY = '_todaydeal_uploaded_by';
	const META_TEMP        = '_todaydeal_temp';
	const META_LISTING_ID  = '_todaydeal_listing_id';

	const ALLOWED_MIME_MAGIC = array(
		'jpeg' => array( 'mime' => 'image/jpeg', 'magic' => "\xFF\xD8\xFF" ),
		'png'  => array( 'mime' => 'image/png', 'magic' => "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A" ),
		'webp' => array( 'mime' => 'image/webp', 'magic' => 'RIFF' ), // + WEBP marker at offset 8, checked separately
	);

	private static function settings() {
		return get_option( 'todaydeal_settings', TD_Install::OPTION_DEFAULTS );
	}

	/**
	 * WP-MED-002: magic-byte sniffing, not file extension.
	 */
	private static function detect_real_mime( $tmp_path ) {
		$handle = fopen( $tmp_path, 'rb' );
		if ( ! $handle ) {
			return false;
		}
		$bytes = fread( $handle, 16 );
		fclose( $handle );

		if ( 0 === strncmp( $bytes, "\xFF\xD8\xFF", 3 ) ) {
			return 'image/jpeg';
		}
		if ( 0 === strncmp( $bytes, "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A", 8 ) ) {
			return 'image/png';
		}
		if ( 0 === strncmp( $bytes, 'RIFF', 4 ) && strlen( $bytes ) >= 12 && false !== strpos( $bytes, 'WEBP' ) ) {
			return 'image/webp';
		}
		return false;
	}

	/**
	 * WP-MED-001/002/003/004/005/006/009.
	 *
	 * @return array|WP_Error attachment summary, or an error.
	 */
	public static function upload( $user_id, array $file, $listing_type_hint = null ) {
		if ( ! current_user_can( 'todaydeal_upload_media' ) ) {
			return TD_Response::error( 'FORBIDDEN', '미디어 업로드 권한이 없습니다.' );
		}

		$settings = self::settings();

		if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== $file['error'] ) {
			return TD_Response::error( 'VALIDATION_ERROR', '파일 업로드에 실패했습니다.' );
		}

		if ( (int) $file['size'] > (int) $settings['media_max_bytes'] ) {
			return TD_Response::error( 'VALIDATION_ERROR', '파일 크기가 제한을 초과했습니다.', array( 'max_bytes' => $settings['media_max_bytes'] ) );
		}

		$real_mime = self::detect_real_mime( $file['tmp_name'] );
		if ( ! $real_mime ) {
			return TD_Response::error( 'VALIDATION_ERROR', 'JPEG, PNG, WebP 이미지만 업로드할 수 있습니다.' );
		}

		$daily_used = self::daily_bytes_used( $user_id );
		if ( $daily_used + (int) $file['size'] > (int) $settings['media_daily_quota_bytes'] ) {
			return TD_Response::error( 'VALIDATION_ERROR', '일일 업로드 용량을 초과했습니다.' );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		// Force a safe, opaque filename - never trust the original filename (spec section 12).
		$ext      = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' )[ $real_mime ];
		$filename = wp_generate_uuid4() . '.' . $ext;

		$upload = wp_upload_bits( $filename, null, file_get_contents( $file['tmp_name'] ) );
		if ( ! empty( $upload['error'] ) ) {
			return TD_Response::error( 'INTERNAL_ERROR', '파일 저장에 실패했습니다.' );
		}

		// WP-MED-004: re-encode + strip EXIF (image editor re-save discards metadata) and fix orientation.
		$editor = wp_get_image_editor( $upload['file'] );
		if ( is_wp_error( $editor ) ) {
			@unlink( $upload['file'] );
			return TD_Response::error( 'VALIDATION_ERROR', '손상되었거나 지원하지 않는 이미지입니다.' );
		}
		$editor->maybe_exif_rotate();
		$saved = $editor->save( $upload['file'] );
		if ( is_wp_error( $saved ) ) {
			@unlink( $upload['file'] );
			return TD_Response::error( 'INTERNAL_ERROR', '이미지 재인코딩에 실패했습니다.' );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $real_mime,
				'post_title'     => $filename,
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$upload['file']
		);

		if ( is_wp_error( $attachment_id ) ) {
			return TD_Response::error( 'INTERNAL_ERROR', '미디어 등록에 실패했습니다.' );
		}

		// WP-MED-005: derivative sizes (thumbnail + medium list/detail sizes).
		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		update_post_meta( $attachment_id, self::META_UPLOADED_BY, $user_id );
		update_post_meta( $attachment_id, self::META_TEMP, 1 );
		update_post_meta( $attachment_id, '_todaydeal_bytes', filesize( $upload['file'] ) );

		TD_Audit_Log::record( 'media_upload', 'media', $attachment_id, 'success', array( 'bytes' => filesize( $upload['file'] ) ) );

		return self::to_response( $attachment_id );
	}

	private static function daily_bytes_used( $user_id ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) );

		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(CAST(pm2.meta_value AS UNSIGNED))
				 FROM {$wpdb->postmeta} pm1
				 JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = pm1.post_id AND pm2.meta_key = '_todaydeal_bytes'
				 JOIN {$wpdb->posts} p ON p.ID = pm1.post_id
				 WHERE pm1.meta_key = %s AND pm1.meta_value = %d AND p.post_date_gmt >= %s",
				self::META_UPLOADED_BY,
				$user_id,
				$since
			)
		);

		return (int) $total;
	}

	public static function to_response( $attachment_id ) {
		$meta = wp_get_attachment_metadata( $attachment_id );
		return array(
			'media_id' => $attachment_id,
			'url'      => wp_get_attachment_url( $attachment_id ),
			'width'    => $meta['width'] ?? null,
			'height'   => $meta['height'] ?? null,
			'mime'     => get_post_mime_type( $attachment_id ),
			'bytes'    => (int) get_post_meta( $attachment_id, '_todaydeal_bytes', true ),
		);
	}

	/**
	 * WP-MED-008: refuse to delete media still attached to a listing.
	 */
	public static function delete( $attachment_id, $user_id ) {
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return TD_Response::error( 'MEDIA_NOT_FOUND', '미디어를 찾을 수 없습니다.' );
		}

		if ( (int) get_post_meta( $attachment_id, self::META_UPLOADED_BY, true ) !== (int) $user_id
			&& ! current_user_can( 'todaydeal_manage_all_listings' ) ) {
			return TD_Response::error( 'FORBIDDEN', '본인이 업로드한 미디어만 삭제할 수 있습니다.' );
		}

		if ( ! get_post_meta( $attachment_id, self::META_TEMP, true ) ) {
			return TD_Response::error( 'VALIDATION_ERROR', '거래글에 사용 중인 미디어는 삭제할 수 없습니다.' );
		}

		wp_delete_attachment( $attachment_id, true );
		TD_Audit_Log::record( 'media_delete', 'media', $attachment_id, 'success' );

		return true;
	}

	/**
	 * Called by listing create/update: marks a set of media ids as attached
	 * (no longer temp) and records which listing owns them.
	 */
	public static function attach_to_listing( array $media_ids, $listing_id ) {
		foreach ( $media_ids as $media_id ) {
			update_post_meta( $media_id, self::META_TEMP, 0 );
			update_post_meta( $media_id, self::META_LISTING_ID, $listing_id );
		}
	}

	public static function validate_ownership( array $media_ids, $user_id ) {
		foreach ( $media_ids as $media_id ) {
			$attachment = get_post( $media_id );
			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				return TD_Response::error( 'MEDIA_NOT_FOUND', "미디어 {$media_id}를 찾을 수 없습니다." );
			}
			if ( (int) get_post_meta( $media_id, self::META_UPLOADED_BY, true ) !== (int) $user_id ) {
				return TD_Response::error( 'FORBIDDEN', '본인이 업로드한 미디어만 사용할 수 있습니다.' );
			}
		}
		return true;
	}

	/**
	 * WP-MED-007: cleanup unattached temp uploads older than the retention window.
	 */
	public static function cleanup_temp() {
		$settings = self::settings();
		$cutoff   = gmdate( 'Y-m-d H:i:s', strtotime( '-' . (int) $settings['temp_media_retention_hours'] . ' hours' ) );

		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 100,
				'date_query'     => array( array( 'before' => $cutoff ) ),
				'meta_query'     => array(
					array(
						'key'   => self::META_TEMP,
						'value' => 1,
					),
				),
				'fields'         => 'ids',
			)
		);

		foreach ( $query->posts as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}
}
