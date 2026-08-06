<?php
/**
 * Archive template for the aidoc post type — the document listing.
 *
 * Loaded via template_include() (see aidocs_archive_template_include() in
 * ai-documents.php) only when Documents → Settings → Listing Template is set
 * to "Document search".
 *
 * get_header()/get_footer() do nothing here: they locate a classic header.php/
 * footer.php, which a pure block theme like this one does not ship — only
 * parts/header.html and parts/footer.html, which are template PARTS, not full
 * templates, and nothing a plain PHP file can just include. Rendering the
 * core/template-part block directly is what actually pulls the active
 * theme's own header and footer in, keeping the site's real navigation
 * around the [aidocs_search] shortcode's output either way.
 *
 * @package aidocs
 */

defined( 'ABSPATH' ) || exit;

/** Render a theme template part by slug, or nothing if the theme has none by that name. */
function aidocs_render_template_part( $slug, $tag ) {
    echo render_block( [
        'blockName' => 'core/template-part',
        'attrs'     => [ 'slug' => $slug, 'tagName' => $tag, 'theme' => get_stylesheet() ],
        'innerHTML' => '',
    ] );
}

get_header(); // no-op on a pure block theme; harmless, and correct if the theme ever ships header.php.
aidocs_render_template_part( 'header', 'header' );
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
aidocs_render_template_part( 'footer', 'footer' );
get_footer();
