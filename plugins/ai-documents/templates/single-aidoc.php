<?php
/**
 * Single template for the aidoc post type — one document's own page.
 *
 * Loaded via template_include() (see aidocs_document_template_include() in
 * ai-documents.php) for every aidoc, in place of the theme's own singular
 * template — this keeps the page a simple header/content/footer shell
 * instead of whatever sidebar, related-posts or comments markup the active
 * theme's single-post layout happens to add.
 *
 * aidocs_document_header()/aidocs_document_footer() (also in ai-documents.php)
 * print the theme's real header/footer — on a block theme via its "header"/
 * "footer" template parts, wrapped in the doctype/head/body markup those
 * themes need but ship no header.php/footer.php to provide; on a classic
 * theme via its own get_header()/get_footer().
 *
 * @package aidocs
 */

defined( 'ABSPATH' ) || exit;

aidocs_document_header();
?>

<main id="aidocs-single-page" class="aidocs-single-page">
    <?php while ( have_posts() ) : the_post(); ?>
    <?php echo aidocs_render_single_document( get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in renderer ?>
    <?php endwhile; ?>
</main>

<?php
aidocs_document_footer();
