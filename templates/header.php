<?php
/**
 * Default document header for block themes, which ship no header.php.
 * Renders the theme's "header" template part. Rendered before wp_head()
 * so the blocks inside it can enqueue their styles in time.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$td_header_html = do_blocks( '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' );
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div class="wp-site-blocks">
<?php echo $td_header_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered block markup. ?>
