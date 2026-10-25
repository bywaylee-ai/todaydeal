<?php
/**
 * Default document footer for block themes, which ship no footer.php.
 * Renders the theme's "footer" template part.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo do_blocks( '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered block markup.
?>
</div>
<?php wp_footer(); ?>
</body>
</html>
