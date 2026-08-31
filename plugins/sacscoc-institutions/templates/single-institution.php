<?php
/**
 * Full-page template for one institution.
 *
 * Loaded via template_include() (see sacscoc_inst_template_include() in
 * includes/frontend.php) in place of whatever the active theme would resolve —
 * which keeps the page a header/content/footer shell instead of a single-post
 * layout dragging in sidebars, related posts and comments that make no sense
 * for an institution record.
 *
 * sacscoc_inst_page_header()/sacscoc_inst_page_footer() print the theme's real
 * header and footer: on a block theme through its "header"/"footer" template
 * parts, wrapped in the doctype/head/body markup those themes need but ship no
 * header.php to provide; on a classic theme through its own get_header().
 *
 * The content itself is templates/institution.php, so a theme overriding just
 * the record layout does not have to reproduce this shell.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$institution = sacscoc_inst_current();
if ( $institution === null ) return;

sacscoc_inst_page_header();
?>

<main id="sacscoc-single" class="sacscoc-single-page">
    <?php
    /**
     * Fires before the institution record.
     *
     * A theme or another plugin can add a breadcrumb, a page title or anything
     * else specific to this listing without editing a file a plugin update
     * would overwrite.
     */
    do_action( 'sacscoc_inst_single_before', $institution );

    sacscoc_inst_load_template( 'institution.php', [
        'institution' => $institution,
        // The theme's own footer already carries this same "About SACSCOC and
        // Accreditation" disclosure — plus "Components of the Review Process"
        // and "Complaints Against SACSCOC Accredited Institutions" — on every
        // institution page specifically (an Elementor Theme Builder footer
        // scoped to this template, not the site's global footer: it is absent
        // from the homepage). sacscoc_inst_page_footer() below prints that
        // same theme footer, so showing ours too repeated the identical
        // paragraph twice on the page. Embedded elsewhere via
        // [sacscoc_institution] or the Institution block, there is no such
        // footer to collide with, so $show_about there keeps its default of
        // true — this override is this page only.
        'show_about'  => false,
    ] );

    do_action( 'sacscoc_inst_single_after', $institution );
    ?>
</main>

<?php
sacscoc_inst_page_footer();
