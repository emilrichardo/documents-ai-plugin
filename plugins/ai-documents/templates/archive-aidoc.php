<?php
/**
 * Archive template for the aidoc post type — the document listing.
 *
 * Loaded via template_include() (see aidocs_document_template_include() in
 * ai-documents.php) for the document archive.
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

<main id="aidocs-archive" class="aidocs-archive">
    <?php
    /**
     * Fires before the document search shortcode on the archive template.
     *
     * A theme or another plugin can hook here to add a page title or other
     * header content specific to this listing, without editing this file
     * (which a plugin update would overwrite).
     */
    do_action( 'aidocs_archive_before' );

    echo do_shortcode( '[aidocs_search]' );

    do_action( 'aidocs_archive_after' );
    ?>
</main>

<?php
aidocs_document_footer();
