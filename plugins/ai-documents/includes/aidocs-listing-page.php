<?php
/**
 * The Policies Page: a real page, chosen or created by an admin, that carries
 * the listing — in place of the post type archive WordPress conjures up on its
 * own at /policies/.
 *
 * ── Why ────────────────────────────────────────────────────────────────────
 *
 * `register_post_type( 'aidoc', [ 'has_archive' => 'policies' ] )` produces a
 * listing page that exists only as a rewrite rule. Nothing in wp-admin lists
 * it, nobody can put an intro paragraph above it, it cannot be added to a
 * menu without typing the URL by hand, and the only way to change anything
 * about how it looks is to edit templates/archive-aidoc.php — a plugin file,
 * overwritten by the next update. It is a page that an editor cannot edit.
 *
 * So the listing moves into content an editor owns: the Policies block
 * (includes/aidocs-blocks.php) or the [aidocs_search] shortcode, on a page
 * they picked or that this file created for them. This is the same
 * arrangement the sibling SACSCOC Institutions plugin uses for its Directory
 * Page, deliberately — two plugins on one site should not ask an admin to
 * learn two different ways to answer "where does the listing live?".
 *
 * ── What happens to /policies/ ─────────────────────────────────────────────
 *
 * The archive stays registered and keeps working, so nothing breaks on a site
 * that has not chosen a page yet — including every existing install, where
 * /policies/ is what is linked and indexed today. Once a page *is* chosen,
 * /policies/ redirects to it rather than rendering a second copy of the same
 * listing at a second URL. Individual policies are unaffected either way:
 * /policies/{entry}/ comes from the post type's `rewrite`, not its archive.
 *
 * @package aidocs
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The page an admin picked for the listing, or 0.
 *
 * Validated on the way out rather than trusted: a page that has since been
 * trashed or deleted would otherwise hand every "back to the listing" link on
 * the site a URL that 404s, which is worse than the archive fallback.
 */
function aidocs_policies_page_id() {
    $page_id = (int) get_option( 'aidocs_policies_page', 0 );
    if ( $page_id <= 0 ) return 0;

    $page = get_post( $page_id );
    if ( ! $page instanceof WP_Post || $page->post_type !== 'page' ) return 0;
    if ( ! in_array( $page->post_status, [ 'publish', 'private' ], true ) ) return 0;

    return $page_id;
}

/**
 * Where the listing lives, as a URL — the chosen page when there is one, the
 * post type archive when there is not.
 *
 * This is the "guardar el lugar del listado" half of the feature: everything
 * that links back to the listing (the back link on a single policy, the
 * button the setup notice offers) asks this rather than assembling
 * home_url( '/policies/' ) for itself, so choosing a page moves all of them at
 * once and none of them can drift.
 */
function aidocs_policies_listing_url() {
    $page_id = aidocs_policies_page_id();
    if ( $page_id > 0 ) {
        $url = get_permalink( $page_id );
        if ( is_string( $url ) && $url !== '' ) return $url;
    }

    return home_url( '/' . aidocs_get_archive_slug() . '/' );
}

/**
 * The Policies block as it is stored in a page's post_content.
 *
 * A block comment with no attributes: every setting left at its default means
 * the block renders exactly what [aidocs_search] with no attributes renders,
 * and an editor changes it afterwards through the block's own Inspector
 * controls rather than through anything written here.
 */
function aidocs_policies_block_markup() {
    return '<!-- wp:ai-documents/policies /-->';
}

/**
 * True when the given page's content carries neither the block nor the
 * shortcode — i.e. it is pointed at as the Policies Page but has no listing
 * on it, which is the one state where the setting silently does nothing.
 */
function aidocs_policies_page_needs_listing( $page_id ) {
    $page_id = (int) $page_id;
    if ( $page_id <= 0 ) return false;

    $page = get_post( $page_id );
    if ( ! $page instanceof WP_Post ) return false;

    $content = (string) $page->post_content;

    return ! has_shortcode( $content, 'aidocs_search' )
        && ! has_block( 'ai-documents/policies', $content );
}

// ──────────────────────────────────────────────
// Create the page, or add the listing to the chosen one
// ──────────────────────────────────────────────
// Two buttons on the Settings screen, one handler each. Both end on the page
// editor rather than back on Settings: the point of either is a page the admin
// can now customise, so that is where they should be standing afterwards.

add_action( 'admin_post_aidocs_create_policies_page', 'aidocs_handle_create_policies_page' );
/**
 * Create a page with the Policies block already in it, point the setting at
 * it, and open it in the editor.
 *
 * Published rather than drafted: a Policies Page setting aimed at an
 * unpublished page would leave every policy's "Back to all topics" link going
 * nowhere until someone remembered to press Publish. Nothing here is anything
 * an admin could not have built from the block inserter by hand, and nothing
 * here cannot be undone by editing or trashing the page.
 */
function aidocs_handle_create_policies_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You are not allowed to create pages.' ) );
    }
    check_admin_referer( 'aidocs_create_policies_page' );

    $page_id = wp_insert_post( [
        'post_title'   => __( 'Policies' ),
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_content' => aidocs_policies_block_markup(),
    ], true );

    if ( is_wp_error( $page_id ) ) {
        wp_die( esc_html( $page_id->get_error_message() ) );
    }

    update_option( 'aidocs_policies_page', (int) $page_id );

    wp_safe_redirect( admin_url( 'post.php?post=' . (int) $page_id . '&action=edit&aidocs_notice=created' ) );
    exit;
}

add_action( 'admin_post_aidocs_insert_policies_block', 'aidocs_handle_insert_policies_block' );
/**
 * Add the Policies block to the page already chosen — one an admin picked from
 * the dropdown, or one they later emptied out by deleting the block.
 *
 * Appended below whatever the page already contains, never replacing it, so a
 * hero image or an intro paragraph survives. The "is it still missing?" check
 * runs at the moment of the click rather than when the button was drawn, so
 * two open tabs cannot produce two listings on one page.
 */
function aidocs_handle_insert_policies_block() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You are not allowed to edit pages.' ) );
    }
    check_admin_referer( 'aidocs_insert_policies_block' );

    $page_id = aidocs_policies_page_id();

    if ( $page_id > 0 && aidocs_policies_page_needs_listing( $page_id ) ) {
        $content = rtrim( (string) get_post( $page_id )->post_content );
        $content = ( $content !== '' ? $content . "\n\n" : '' ) . aidocs_policies_block_markup();

        wp_update_post( [ 'ID' => $page_id, 'post_content' => $content ] );
    }

    if ( $page_id > 0 ) {
        wp_safe_redirect( admin_url( 'post.php?post=' . $page_id . '&action=edit&aidocs_notice=inserted' ) );
    } else {
        wp_safe_redirect( admin_url( 'edit.php?post_type=aidoc&page=aidocs-settings' ) );
    }
    exit;
}

/**
 * The one-line confirmation on the page editor both handlers redirect into, so
 * that pressing either button does not look like it did nothing.
 */
add_action( 'admin_notices', 'aidocs_policies_page_notice' );
function aidocs_policies_page_notice() {
    $notice = isset( $_GET['aidocs_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['aidocs_notice'] ) ) : '';
    if ( $notice === '' ) return;

    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'page' ) return;

    if ( $notice === 'created' ) {
        $message = __( 'This page was created for the policy listing, published, with the Policies block already on it. Add anything you like around the block, then Update.' );
    } elseif ( $notice === 'inserted' ) {
        $message = __( 'The Policies block was added to this page, below whatever it already contained. Move it, edit around it, or leave it as it is.' );
    } else {
        return;
    }

    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
}

// ──────────────────────────────────────────────
// The Settings field
// ──────────────────────────────────────────────

/**
 * The dropdown, its Edit/View links, and whichever of "Create Policies Page"
 * or the "this page has no listing on it" warning applies.
 *
 * Rendered inside the Settings form, so the <select> is saved by that form's
 * own POST handler (aidocs_settings_page()). The two buttons are links with
 * their own nonces rather than submits — a form cannot be nested inside
 * another form, and neither action is a settings change.
 */
function aidocs_render_policies_page_picker() {
    $page_id = aidocs_policies_page_id();
    $raw_id  = (int) get_option( 'aidocs_policies_page', 0 );

    wp_dropdown_pages( [
        'name'              => 'aidocs_policies_page',
        'id'                => 'aidocs_policies_page',
        'selected'          => $page_id,
        'show_option_none'  => __( '— Not set —' ),
        'option_none_value' => 0,
    ] );

    if ( $page_id > 0 ) :
        ?>
        <a class="button button-small" style="margin-left:8px" href="<?php echo esc_url( (string) get_edit_post_link( $page_id, 'raw' ) ); ?>">
            <?php esc_html_e( 'Edit Page' ); ?>
        </a>
        <a class="button button-small" href="<?php echo esc_url( (string) get_permalink( $page_id ) ); ?>" target="_blank" rel="noopener noreferrer">
            <?php esc_html_e( 'View Page' ); ?>
        </a>
        <?php
    endif;
    ?>

    <p class="description">
        <?php esc_html_e( 'The page visitors see the policy listing on. Every policy links back to it, and /policies/ redirects to it.' ); ?>
        <?php esc_html_e( 'Leave it unset and the listing stays on the automatic /policies/ archive, which nobody can edit.' ); ?>
    </p>

    <?php if ( $raw_id > 0 && $page_id === 0 ) : ?>
        <div class="notice notice-warning inline" style="margin:10px 0;padding:8px 12px;">
            <p><?php esc_html_e( 'The page this was set to is gone — trashed, deleted, or no longer published. Pick another one, or create a new page below.' ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( $page_id === 0 ) : ?>
        <p>
            <a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url(
                add_query_arg( [ 'action' => 'aidocs_create_policies_page' ], admin_url( 'admin-post.php' ) ),
                'aidocs_create_policies_page'
            ) ); ?>">
                <?php esc_html_e( 'Create Policies Page' ); ?>
            </a>
            <span class="cd-sc-desc" style="margin-left:6px;">
                <?php esc_html_e( 'Makes a published page called "Policies" with the block already on it, and selects it here.' ); ?>
            </span>
        </p>
    <?php elseif ( aidocs_policies_page_needs_listing( $page_id ) ) : ?>
        <div class="notice notice-warning inline" style="margin:10px 0;padding:8px 12px;">
            <p>
                <?php esc_html_e( 'This page has no policy listing on it yet — no Policies block and no [aidocs_search] shortcode — so visitors would find it empty.' ); ?>
            </p>
            <p>
                <a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url(
                    add_query_arg( [ 'action' => 'aidocs_insert_policies_block' ], admin_url( 'admin-post.php' ) ),
                    'aidocs_insert_policies_block'
                ) ); ?>">
                    <?php esc_html_e( 'Add the Policies block to it' ); ?>
                </a>
            </p>
        </div>
    <?php endif; ?>
    <?php
}

// ──────────────────────────────────────────────
// /policies/ → the chosen page
// ──────────────────────────────────────────────

/**
 * Send the post type archive to the Policies Page once one has been chosen.
 *
 * Two URLs rendering the same listing is the kind of thing that quietly splits
 * a page's search ranking in half, and an archive nobody can edit is exactly
 * what choosing a page was meant to replace — so the archive stops being a
 * destination the moment there is somewhere better to send it.
 *
 * 302, not 301: the target is a setting, and a setting can be changed. A
 * permanent redirect would be cached by every browser that ever followed it,
 * so picking a different page later would strand those visitors on the old one
 * with nothing the site could do about it.
 *
 * The path comparison is a loop guard, not an optimisation: a Policies Page
 * whose own slug is `policies` resolves to the same URL as the archive, and
 * redirecting a URL to itself is an infinite redirect.
 */
add_action( 'template_redirect', 'aidocs_redirect_archive_to_policies_page' );
function aidocs_redirect_archive_to_policies_page() {
    if ( ! is_post_type_archive( 'aidoc' ) ) return;

    /**
     * Filters whether the /policies/ archive redirects to the Policies Page.
     *
     * Return false to keep both — a site that wants the archive to stay a
     * working URL of its own, for instance because something external links
     * to it and cannot be changed.
     */
    if ( ! apply_filters( 'aidocs_redirect_archive_to_page', true ) ) return;

    $page_id = aidocs_policies_page_id();
    if ( $page_id === 0 ) return;

    $target = (string) get_permalink( $page_id );
    if ( $target === '' ) return;

    $current_path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
    $target_path  = (string) wp_parse_url( $target, PHP_URL_PATH );
    if ( untrailingslashit( $current_path ) === untrailingslashit( $target_path ) ) return;

    $query = (string) ( $_SERVER['QUERY_STRING'] ?? '' );
    if ( $query !== '' ) {
        $target .= ( strpos( $target, '?' ) === false ? '?' : '&' ) . $query;
    }

    wp_safe_redirect( $target, 302 );
    exit;
}
