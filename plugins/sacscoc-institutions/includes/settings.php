<?php
/**
 * Institutions → Settings.
 *
 * Three things are configurable and two are read-only status. The one that
 * matters most is API Base URL: the host is not written into the plugin's logic
 * anywhere, only into the default here, so a move to a different API host is a
 * change to this one field.
 *
 * Deliberately absent: anything about AI. This plugin does not use it.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_init', 'sacscoc_inst_register_settings' );
function sacscoc_inst_register_settings(): void {
    register_setting( 'sacscoc_inst_settings', 'sacscoc_inst_api_base_url', [
        'type'              => 'string',
        'sanitize_callback' => 'sacscoc_inst_sanitize_base_url',
        'default'           => SACSCOC_INST_DEFAULT_API_BASE,
    ] );

    register_setting( 'sacscoc_inst_settings', 'sacscoc_inst_api_timeout', [
        'type'              => 'integer',
        'sanitize_callback' => 'sacscoc_inst_sanitize_timeout',
        'default'           => 60,
    ] );

    register_setting( 'sacscoc_inst_settings', 'sacscoc_inst_sync_frequency', [
        'type'              => 'string',
        'sanitize_callback' => 'sacscoc_inst_sanitize_frequency',
        'default'           => SACSCOC_INST_DEFAULT_SCHEDULE,
    ] );

    register_setting( 'sacscoc_inst_settings', 'sacscoc_inst_rewrite_base', [
        'type'              => 'string',
        'sanitize_callback' => 'sacscoc_inst_sanitize_rewrite_base',
        'default'           => 'institutions',
    ] );

    register_setting( 'sacscoc_inst_settings', 'sacscoc_inst_directory_page', [
        'type'              => 'integer',
        'sanitize_callback' => 'absint',
        'default'           => 0,
    ] );

    register_setting( 'sacscoc_inst_settings', 'sacscoc_inst_layout', [
        'type'              => 'string',
        'sanitize_callback' => 'sacscoc_inst_clean_layout',
        'default'           => 'two-column',
    ] );

    register_setting( 'sacscoc_inst_settings', 'sacscoc_inst_per_page', [
        'type'              => 'integer',
        'sanitize_callback' => 'sacscoc_inst_sanitize_per_page',
        'default'           => SACSCOC_INST_PER_PAGE,
    ] );

    register_setting( 'sacscoc_inst_settings', 'sacscoc_inst_delete_data_on_uninstall', [
        'type'              => 'string',
        'sanitize_callback' => 'sacscoc_inst_sanitize_checkbox',
        'default'           => '',
    ] );

    register_setting( 'sacscoc_inst_settings', 'sacscoc_inst_footer_content', [
        'type'              => 'string',
        'sanitize_callback' => 'wp_kses_post',
        'default'           => '',
    ] );

    register_setting( 'sacscoc_inst_settings', 'sacscoc_inst_sites_legend_content', [
        'type'              => 'string',
        'sanitize_callback' => 'wp_kses_post',
        'default'           => sacscoc_inst_default_sites_legend_content(),
    ] );
}

/**
 * The Off-campus Instructional Sites legend, as it reads on the existing
 * production site — the same on every institution, so it is one setting
 * rather than a copy stored on each of the 1,201 records. See "Not in the
 * API" in docs/API-FIELD-MAP.md.
 */
function sacscoc_inst_default_sites_legend_content(): string {
    return "<h3>Types</h3>\n"
        . "<ul>\n"
        . "<li><strong>Approved &gt;=50%:</strong> Site is approved to offer any portion of a program. Additional programs may be offered with no further site notification or approval. Only sites offering 50% or more of a program require approval.</li>\n"
        . "<li><strong>Approved Branch &gt;=50%:</strong> Site is approved as a branch campus to offer any portion of a program. Additional programs may be offered with no further site notification or approval.</li>\n"
        . "<li><strong>Notified 25-49%:</strong> Less than 50% of a program may be offered at the site. Less than 50% of additional programs may be offered with no further site notification.</li>\n"
        . "</ul>\n"
        . "<p>Sites offering less than 25% of a program do not require notification or approval.</p>\n"
        . "<h3>Status</h3>\n"
        . "<ul>\n"
        . "<li><strong>Open:</strong> Instruction may be offered at the site consistent with the site type defined above.</li>\n"
        . "<li><strong>Closed:</strong> Closed sites are not shown. A site is closed when (1) the institution has stopped admitting students to the site and (2) SACSCOC has approved the site teach-out plan. Therefore, instruction may continue at a site under the teach-out plan after the site is closed.</li>\n"
        . "</ul>";
}

/**
 * The URL base for institution pages.
 *
 * Run through sanitize_title() so it can only ever be one clean path segment —
 * a base with a slash or a space in it would produce rewrite rules that never
 * match anything, and the symptom would be every institution URL 404ing.
 */
function sacscoc_inst_sanitize_rewrite_base( $value ): string {
    $value = sanitize_title( (string) $value );
    return $value !== '' ? $value : 'institutions';
}

/**
 * Queue an admin notice for a settings field, when there is an admin to show it.
 *
 * register_setting() hooks its sanitize callback onto `sanitize_option_{$option}`,
 * which fires on *every* update_option() — including from cron or a CLI script,
 * where wp-admin/includes/template.php is not loaded and add_settings_error()
 * does not exist. Calling it unguarded there is a fatal error, so a setting
 * written outside the admin would take the whole request down.
 */
function sacscoc_inst_settings_notice( string $code, string $message, string $type = 'error' ): void {
    if ( ! function_exists( 'add_settings_error' ) ) return;
    add_settings_error( 'sacscoc_inst_api_base_url', $code, $message, $type );
}

/**
 * The base URL, without a trailing slash and without a path.
 *
 * Rejecting a path is not pedantry: the plugin appends `/api/v1/...` itself, so
 * a base of `https://api.sacscoc.org/api/v1` would produce
 * `/api/v1/api/v1/search` and every request would 404. Keeping the host alone
 * is what makes the endpoints in includes/api.php readable as written.
 */
function sacscoc_inst_sanitize_base_url( $value ): string {
    $value = trim( (string) $value );

    if ( $value === '' ) return SACSCOC_INST_DEFAULT_API_BASE;

    // A bare host is a common way to type this; assume https rather than fail.
    if ( ! preg_match( '#^https?://#i', $value ) ) {
        $value = 'https://' . $value;
    }

    $url = esc_url_raw( $value );
    $parts = wp_parse_url( $url );

    // A host has to look like a host. Without this check, free text survives:
    // esc_url_raw() percent-encodes the spaces in "not a url at all" and
    // wp_parse_url() then reports a perfectly well-formed host, so every
    // subsequent request would go to a nonsense domain and the only symptom
    // would be a sync that fails forever.
    $host  = $parts['host'] ?? '';
    $valid = $host !== ''
        && ! empty( $parts['scheme'] )
        && preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/i', $host )
        // Either a dotted name, or a bare name that resolves on its own.
        && ( str_contains( $host, '.' ) || in_array( strtolower( $host ), [ 'localhost' ], true ) );

    if ( ! $valid ) {
        sacscoc_inst_settings_notice(
            'sacscoc_inst_bad_url',
            sprintf(
                /* translators: %s: the value that was rejected */
                __( '“%s” is not a valid API host; the previous value was kept.', 'sacscoc-institutions' ),
                $value
            )
        );
        return sacscoc_inst_api_base_url();
    }

    $clean = $parts['scheme'] . '://' . $parts['host'];
    if ( ! empty( $parts['port'] ) ) $clean .= ':' . $parts['port'];

    if ( ! empty( $parts['path'] ) && trim( $parts['path'], '/' ) !== '' ) {
        sacscoc_inst_settings_notice(
            'sacscoc_inst_url_path',
            sprintf(
                /* translators: %s: the base URL that was saved */
                __( 'API Base URL should be the host only — the plugin adds the /api/v1/… paths itself. Saved as %s.', 'sacscoc-institutions' ),
                $clean
            ),
            'warning'
        );
    }

    return $clean;
}

function sacscoc_inst_sanitize_timeout( $value ): int {
    $value = (int) $value;
    return $value >= 5 && $value <= 300 ? $value : 60;
}

/**
 * Results per page.
 *
 * Held between 1 and 200 by sacscoc_inst_clamp_per_page(): a page of 0 results
 * would paginate forever, and a page of every institution is a 1,201-row render
 * nobody asked for. An empty or non-numeric value returns the shipped default
 * rather than an error, because there is a sensible answer for it.
 */
function sacscoc_inst_sanitize_per_page( $value ): int {
    return sacscoc_inst_clamp_per_page( $value );
}

/**
 * A checkbox, stored as '1' or ''.
 *
 * An unticked checkbox posts nothing at all, so the absent value has to mean
 * "off" rather than "leave as it was" — which is what makes it possible to turn
 * this one back off again.
 */
function sacscoc_inst_sanitize_checkbox( $value ): string {
    return $value === '1' ? '1' : '';
}

/** True when deleting the plugin should take the data with it. */
function sacscoc_inst_deletes_data_on_uninstall(): bool {
    return get_option( 'sacscoc_inst_delete_data_on_uninstall' ) === '1';
}

function sacscoc_inst_sanitize_frequency( $value ): string {
    $value = (string) $value;
    return isset( sacscoc_inst_schedules()[ $value ] ) ? $value : SACSCOC_INST_DEFAULT_SCHEDULE;
}

// Changing the frequency has to move the already-scheduled event, or the new
// setting would only take effect after the next run.
add_action( 'update_option_sacscoc_inst_sync_frequency', 'sacscoc_inst_frequency_changed', 10, 0 );
function sacscoc_inst_frequency_changed(): void {
    sacscoc_inst_unschedule_sync();
    sacscoc_inst_schedule_sync();
}

// ──────────────────────────────────────────────
// The Directory Page: create it, or add the directory to it
// ──────────────────────────────────────────────
// Choosing a page in Settings only points at it; nothing before this wrote
// anything into that page for real. That left two gaps: no way to get a page
// at all without leaving Settings to build one by hand, and no way back once
// an existing page was chosen — the directory never actually landed in its
// content, so opening it in the editor showed nothing to customise.
//
// Both gaps close the same way: writing sacscoc_inst_directory_block_markup()
// — the Institutions Directory block (includes/blocks.php), not a filter that
// only ever rendered something invisible — into a page's real post_content.
// That makes it visible, movable and editable exactly like anything else on
// the page: an admin who wants an intro paragraph above the directory, a
// different layout, or a background colour on it, edits the page directly
// through the block's own controls rather than fighting something invisible.

/** True when a page's stored content has neither the directory shortcode nor the block. */
function sacscoc_inst_directory_page_needs_directory( int $page_id ): bool {
    if ( $page_id <= 0 ) return false;

    $post = get_post( $page_id );
    if ( ! $post instanceof WP_Post ) return false;

    $content = (string) $post->post_content;
    return ! has_shortcode( $content, 'sacscoc_institutions' )
        && ! has_block( 'sacscoc-institutions/directory', $content );
}

add_action( 'admin_post_sacscoc_inst_create_directory_page', 'sacscoc_inst_handle_create_directory_page' );
/**
 * Create a new page, with the Institutions Directory block already in it, set
 * it as Directory Page, and send the admin straight to editing it.
 *
 * Published rather than left as a draft: the point of this button is a
 * working directory with the fewest possible clicks, and a Directory Page
 * setting pointing at an unpublished page would leave institution pages'
 * "Back to Results" link going nowhere until someone remembered to publish it.
 * The title and content are exactly what an admin could build by hand from the
 * block inserter — nothing here that could not be undone by editing or
 * trashing the page afterwards.
 */
function sacscoc_inst_handle_create_directory_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You are not allowed to create pages.', 'sacscoc-institutions' ) );
    }
    check_admin_referer( 'sacscoc_inst_create_directory_page' );

    $page_id = wp_insert_post( [
        'post_title'   => __( 'Institutions', 'sacscoc-institutions' ),
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_content' => sacscoc_inst_directory_block_markup(),
    ], true );

    if ( is_wp_error( $page_id ) ) {
        wp_die( esc_html( $page_id->get_error_message() ) );
    }

    update_option( 'sacscoc_inst_directory_page', $page_id );
    sacscoc_inst_request_flush();

    wp_safe_redirect( admin_url( 'post.php?post=' . (int) $page_id . '&action=edit&sacscoc_inst_notice=created' ) );
    exit;
}

add_action( 'admin_post_sacscoc_inst_insert_directory_block', 'sacscoc_inst_handle_insert_directory_block' );
/**
 * Add the Institutions Directory block to the already-chosen Directory Page,
 * for the page that was selected — new or pre-existing — before this version
 * could write it in automatically, or one an admin later emptied out by
 * removing the block.
 *
 * Appended after whatever the page's content already is, never replacing it,
 * so a hero or an intro paragraph already on the page survives untouched.
 * Re-checks that the directory is still missing at the moment of the click,
 * not just when the button was rendered, so two admins on the same screen (or
 * one with two tabs open) cannot end up doubling it.
 */
function sacscoc_inst_handle_insert_directory_block(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You are not allowed to edit pages.', 'sacscoc-institutions' ) );
    }
    check_admin_referer( 'sacscoc_inst_insert_directory_block' );

    $page_id = (int) get_option( 'sacscoc_inst_directory_page', 0 );

    if ( $page_id && sacscoc_inst_directory_page_needs_directory( $page_id ) ) {
        $post = get_post( $page_id );
        $content = rtrim( (string) $post->post_content );
        $content = ( $content !== '' ? $content . "\n\n" : '' ) . sacscoc_inst_directory_block_markup();

        wp_update_post( [ 'ID' => $page_id, 'post_content' => $content ] );
    }

    if ( $page_id ) {
        wp_safe_redirect( admin_url( 'post.php?post=' . $page_id . '&action=edit&sacscoc_inst_notice=inserted' ) );
    } else {
        wp_safe_redirect( admin_url( 'admin.php?page=sacscoc-institutions-settings' ) );
    }
    exit;
}

/**
 * The one-line confirmation on the page-edit screen after either action above
 * redirects there — so creating or filling in the page does not feel like it
 * silently did nothing.
 */
add_action( 'admin_notices', 'sacscoc_inst_directory_page_notice' );
function sacscoc_inst_directory_page_notice(): void {
    $notice = isset( $_GET['sacscoc_inst_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['sacscoc_inst_notice'] ) ) : '';
    if ( $notice === '' ) return;

    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'page' ) return;

    if ( $notice === 'created' ) {
        $message = __( 'This page was created for the institutions directory, with the shortcode already added below and published. Customise it however you like, then Update.', 'sacscoc-institutions' );
    } elseif ( $notice === 'inserted' ) {
        $message = __( 'The institutions directory shortcode was added to this page, below whatever content it already had. Move it, edit around it, or leave it as is.', 'sacscoc-institutions' );
    } else {
        return;
    }

    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
}

// ──────────────────────────────────────────────
// Start from scratch
// ──────────────────────────────────────────────
// Emptying the tables without uninstalling: the answer to "I want to resync
// everything from nothing" that does not involve deleting the plugin.
//
// Two steps, and the first one is a plain link rather than a JavaScript
// confirm(): a browser with the script blocked would run a confirm-less delete,
// and this is not an action to leave to a dialog that might not appear. The
// second step is a POST to admin-post.php with its own nonce.

add_action( 'admin_post_sacscoc_inst_reset', 'sacscoc_inst_handle_reset' );
function sacscoc_inst_handle_reset(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You are not allowed to delete the stored institutions.', 'sacscoc-institutions' ) );
    }
    check_admin_referer( 'sacscoc_inst_reset' );

    $removed = sacscoc_inst_delete_all_data();

    wp_safe_redirect( add_query_arg(
        [
            'page'          => 'sacscoc-institutions-settings',
            'sacscoc_reset' => 'done',
            'removed'       => (int) ( $removed['institutions'] ?? 0 ),
        ],
        admin_url( 'admin.php' )
    ) );
    exit;
}

/** True when the screen should show the confirmation step instead of the button. */
function sacscoc_inst_reset_requested(): bool {
    if ( ( $_GET['sacscoc_reset'] ?? '' ) !== 'confirm' ) return false;

    return (bool) check_admin_referer( 'sacscoc_inst_reset_confirm' );
}

/**
 * The confirmation step: what is about to go, what is not, and what it costs.
 *
 * Rendered in place of the button, at the top of the screen, because a
 * destructive confirmation that scrolls off the fold is not a confirmation.
 */
function sacscoc_inst_reset_confirmation(): void {
    $stats = sacscoc_inst_stats();
    ?>
    <div class="notice notice-error" style="padding:12px 14px">
        <h2 style="margin-top:0"><?php esc_html_e( 'Delete every stored institution?', 'sacscoc-institutions' ); ?></h2>

        <p>
            <?php
            printf(
                /* translators: %s: number of institutions */
                esc_html__( 'This empties the four tables — %s institutions, their sites and meetings, and the sync log — and clears the last sync’s result. It cannot be undone from here.', 'sacscoc-institutions' ),
                '<strong>' . esc_html( number_format_i18n( (int) $stats['total'] ) ) . '</strong>'
            );
            ?>
        </p>

        <p>
            <?php esc_html_e( 'Every setting on this screen is kept, so the next sync — automatic or by pressing Sync Now — downloads the directory again and fills the tables from the API.', 'sacscoc-institutions' ); ?>
        </p>

        <p>
            <strong><?php esc_html_e( 'One thing does not come back identical:', 'sacscoc-institutions' ); ?></strong>
            <?php esc_html_e( 'institution URLs are assigned on first insert, so they are assigned again on the next sync. Institutions whose names collide get a numeric suffix in whatever order they arrive, which may not be the order they had — any link to one of those could point at the other.', 'sacscoc-institutions' ); ?>
        </p>

        <p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                <input type="hidden" name="action" value="sacscoc_inst_reset" />
                <?php wp_nonce_field( 'sacscoc_inst_reset' ); ?>
                <button type="submit" class="button button-primary"
                        style="background:#b32d2e;border-color:#b32d2e">
                    <?php
                    printf(
                        /* translators: %s: number of institutions */
                        esc_html__( 'Yes, delete %s institutions', 'sacscoc-institutions' ),
                        esc_html( number_format_i18n( (int) $stats['total'] ) )
                    );
                    ?>
                </button>
            </form>

            <a class="button"
               href="<?php echo esc_url( add_query_arg( [ 'page' => 'sacscoc-institutions-settings' ], admin_url( 'admin.php' ) ) ); ?>">
                <?php esc_html_e( 'Cancel', 'sacscoc-institutions' ); ?>
            </a>
        </p>
    </div>
    <?php
}

/**
 * The dropdown, its Edit/View links, and whichever of "Create Institutions
 * Page" or the "add the directory to this page" warning applies — everything
 * Settings shows for the Directory Page field.
 *
 * Pulled out so Institutions → Settings and the setup wizard
 * (includes/onboarding.php) render the exact same picker rather than two
 * copies that could drift; the wizard's own "choose or create the page" step
 * is this function, wrapped in its own layout instead of a form-table row.
 */
function sacscoc_inst_render_directory_page_picker(): void {
    $directory_page_id = (int) get_option( 'sacscoc_inst_directory_page', 0 );
    wp_dropdown_pages( [
        'name'              => 'sacscoc_inst_directory_page',
        'id'                => 'sacscoc_inst_directory_page',
        'selected'          => $directory_page_id,
        'show_option_none'  => __( '— Not set —', 'sacscoc-institutions' ),
        'option_none_value' => 0,
    ] );
    ?>

    <?php if ( $directory_page_id > 0 ) : ?>
        <a class="button button-small" style="margin-left:8px"
           href="<?php echo esc_url( (string) get_edit_post_link( $directory_page_id, 'raw' ) ); ?>">
            <?php esc_html_e( 'Edit Page', 'sacscoc-institutions' ); ?>
        </a>
        <a class="button button-small"
           href="<?php echo esc_url( (string) get_permalink( $directory_page_id ) ); ?>" target="_blank" rel="noopener noreferrer">
            <?php esc_html_e( 'View Page', 'sacscoc-institutions' ); ?>
        </a>
    <?php endif; ?>

    <p class="description">
        <?php esc_html_e( 'Where the directory lives. Institution pages link back to it too.', 'sacscoc-institutions' ); ?>
    </p>

    <?php if ( $directory_page_id === 0 ) : ?>
        <p>
            <a class="button button-secondary"
               href="<?php echo esc_url( wp_nonce_url(
                   add_query_arg( [ 'action' => 'sacscoc_inst_create_directory_page' ], admin_url( 'admin-post.php' ) ),
                   'sacscoc_inst_create_directory_page'
               ) ); ?>">
                <?php esc_html_e( 'Create Institutions Page', 'sacscoc-institutions' ); ?>
            </a>
            <br />
            <span class="description">
                <?php esc_html_e( 'Creates and publishes a new page named “Institutions”, with an Institutions Directory block already added to it, and sets it as the page above. Opens it for editing right after, so it can be customised — an intro paragraph, a different layout, its own colours — before or after it goes live.', 'sacscoc-institutions' ); ?>
            </span>
        </p>
    <?php elseif ( sacscoc_inst_directory_page_needs_directory( $directory_page_id ) ) : ?>
        <p class="notice notice-warning inline" style="padding:8px 12px;margin:1em 0">
            <?php esc_html_e( 'This page does not have the directory on it yet — neither the shortcode nor the Institutions Directory block — so nothing will show there.', 'sacscoc-institutions' ); ?>
            <br />
            <a class="button button-secondary" style="margin-top:6px"
               href="<?php echo esc_url( wp_nonce_url(
                   add_query_arg( [ 'action' => 'sacscoc_inst_insert_directory_block' ], admin_url( 'admin-post.php' ) ),
                   'sacscoc_inst_insert_directory_block'
               ) ); ?>">
                <?php esc_html_e( 'Add the directory to this page now', 'sacscoc-institutions' ); ?>
            </a>
        </p>
    <?php endif; ?>
    <?php
}

// ──────────────────────────────────────────────
// The screen
// ──────────────────────────────────────────────

function sacscoc_inst_settings_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $stats     = sacscoc_inst_stats();
    $last_ok   = get_option( 'sacscoc_inst_last_successful_sync' );
    $frequency = sacscoc_inst_schedule_name();

    // The connection is only tested when asked. Every load of a settings page
    // firing an outbound HTTP request is a slow settings page.
    $check = null;
    if ( isset( $_GET['sacscoc_test'] ) && check_admin_referer( 'sacscoc_inst_test' ) ) {
        $check = sacscoc_inst_api_check();
    }
    ?>
    <div class="wrap">
        <h1>
            <?php esc_html_e( 'Institutions Settings', 'sacscoc-institutions' ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sacscoc-institutions-onboarding' ) ); ?>" class="page-title-action">
                <?php esc_html_e( 'Run Setup Wizard', 'sacscoc-institutions' ); ?>
            </a>
        </h1>

        <?php settings_errors( 'sacscoc_inst_api_base_url' ); ?>

        <?php if ( ( $_GET['sacscoc_reset'] ?? '' ) === 'done' ) : ?>
            <div class="notice notice-success">
                <p><?php
                    printf(
                        /* translators: %s: number of institutions removed */
                        esc_html__( 'Deleted %s institutions. Run a sync to fill the directory again.', 'sacscoc-institutions' ),
                        esc_html( number_format_i18n( (int) ( $_GET['removed'] ?? 0 ) ) )
                    );
                ?></p>
            </div>
        <?php endif; ?>

        <?php if ( sacscoc_inst_reset_requested() ) sacscoc_inst_reset_confirmation(); ?>

        <?php if ( $check !== null ) : ?>
            <div class="notice notice-<?php echo $check['ok'] ? 'success' : 'error'; ?>">
                <p><strong><?php esc_html_e( 'API status:', 'sacscoc-institutions' ); ?></strong>
                   <?php echo esc_html( $check['message'] ); ?></p>
            </div>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields( 'sacscoc_inst_settings' ); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="sacscoc_inst_api_base_url"><?php esc_html_e( 'API Base URL', 'sacscoc-institutions' ); ?></label>
                    </th>
                    <td>
                        <input name="sacscoc_inst_api_base_url" id="sacscoc_inst_api_base_url"
                               type="url" class="regular-text code"
                               value="<?php echo esc_attr( sacscoc_inst_api_base_url() ); ?>" />
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: the shipped default base URL */
                                esc_html__( 'Host only, no path — the plugin adds %1$s itself. Default: %2$s', 'sacscoc-institutions' ),
                                '<code>/api/v1/…</code>',
                                '<code>' . esc_html( SACSCOC_INST_DEFAULT_API_BASE ) . '</code>'
                            );
                            ?>
                            <br />
                            <?php esc_html_e( 'This is the only place the API host is configured. If the API moves, change it here and nothing else.', 'sacscoc-institutions' ); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="sacscoc_inst_sync_frequency"><?php esc_html_e( 'Sync Frequency', 'sacscoc-institutions' ); ?></label>
                    </th>
                    <td>
                        <select name="sacscoc_inst_sync_frequency" id="sacscoc_inst_sync_frequency">
                            <?php foreach ( sacscoc_inst_schedules() as $key => $label ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $frequency, $key ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e( 'How often the automatic sync runs. Saving a new value reschedules the next run immediately.', 'sacscoc-institutions' ); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="sacscoc_inst_api_timeout"><?php esc_html_e( 'API Timeout', 'sacscoc-institutions' ); ?></label>
                    </th>
                    <td>
                        <input name="sacscoc_inst_api_timeout" id="sacscoc_inst_api_timeout"
                               type="number" min="5" max="300" step="5" class="small-text"
                               value="<?php echo esc_attr( (string) sacscoc_inst_api_timeout() ); ?>" />
                        <?php esc_html_e( 'seconds', 'sacscoc-institutions' ); ?>
                        <p class="description">
                            <?php esc_html_e( 'The full directory is ~1.7 MB and normally answers in about 3 seconds. 60 leaves plenty of room for a slow day.', 'sacscoc-institutions' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'Directory', 'sacscoc-institutions' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="sacscoc_inst_directory_page"><?php esc_html_e( 'Directory Page', 'sacscoc-institutions' ); ?></label>
                    </th>
                    <td><?php sacscoc_inst_render_directory_page_picker(); ?></td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="sacscoc_inst_layout"><?php esc_html_e( 'Directory Layout', 'sacscoc-institutions' ); ?></label>
                    </th>
                    <td>
                        <select name="sacscoc_inst_layout" id="sacscoc_inst_layout">
                            <?php foreach ( sacscoc_inst_layouts() as $key => $label ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( sacscoc_inst_layout(), $key ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e( 'Where the search sits. Two columns is the layout of the current sacscoc.org directory; one column puts a search bar across the top and the results full width beneath it, like the site’s own Find an Institution page.', 'sacscoc-institutions' ); ?>
                            <br />
                            <?php
                            printf(
                                /* translators: %s: the shortcode with the layout attribute */
                                esc_html__( 'One page can override it with %s.', 'sacscoc-institutions' ),
                                '<code>[sacscoc_institutions layout="one-column"]</code>'
                            );
                            ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="sacscoc_inst_per_page"><?php esc_html_e( 'Results Per Page', 'sacscoc-institutions' ); ?></label>
                    </th>
                    <td>
                        <input name="sacscoc_inst_per_page" id="sacscoc_inst_per_page"
                               type="number" min="<?php echo esc_attr( (string) SACSCOC_INST_PER_PAGE_MIN ); ?>"
                               max="<?php echo esc_attr( (string) SACSCOC_INST_PER_PAGE_MAX ); ?>"
                               step="1" class="small-text"
                               value="<?php echo esc_attr( (string) sacscoc_inst_per_page() ); ?>" />
                        <?php esc_html_e( 'institutions', 'sacscoc-institutions' ); ?>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: 1: the shipped default, 2: the maximum, 3: the shortcode attribute */
                                esc_html__( 'How many results a page of the directory lists. Default %1$s, as the current site; %2$s at most. One page can override it with %3$s.', 'sacscoc-institutions' ),
                                '<code>' . esc_html( (string) SACSCOC_INST_PER_PAGE ) . '</code>',
                                '<code>' . esc_html( (string) SACSCOC_INST_PER_PAGE_MAX ) . '</code>',
                                '<code>[sacscoc_institutions per_page="50"]</code>'
                            );
                            ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="sacscoc_inst_rewrite_base"><?php esc_html_e( 'Institution URL Base', 'sacscoc-institutions' ); ?></label>
                    </th>
                    <td>
                        <code><?php echo esc_html( trailingslashit( home_url() ) ); ?></code>
                        <input name="sacscoc_inst_rewrite_base" id="sacscoc_inst_rewrite_base"
                               type="text" class="small-text code"
                               value="<?php echo esc_attr( sacscoc_inst_rewrite_base() ); ?>" />
                        <code>/&lt;institution&gt;/</code>
                        <p class="description">
                            <?php esc_html_e( 'One path segment. Existing WordPress pages under the same base keep working — a URL that is not an institution is handed back to WordPress.', 'sacscoc-institutions' ); ?>
                            <br />
                            <?php esc_html_e( 'Changing this changes every institution URL, so old links will stop resolving. Permalinks are refreshed automatically.', 'sacscoc-institutions' ); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="sacscoc_inst_footer_content"><?php esc_html_e( 'Institution Footer Content', 'sacscoc-institutions' ); ?></label>
                    </th>
                    <td>
                        <textarea name="sacscoc_inst_footer_content" id="sacscoc_inst_footer_content"
                                  rows="10" class="large-text code"><?php
                            echo esc_textarea( (string) get_option( 'sacscoc_inst_footer_content', '' ) );
                        ?></textarea>
                        <p class="description">
                            <?php esc_html_e( 'Shown at the foot of every institution page — the "About SACSCOC and Accreditation" block and anything else common to all of them. Stored once here, not copied onto each of the 1,201 records.', 'sacscoc-institutions' ); ?>
                            <br />
                            <?php esc_html_e( 'Basic HTML is allowed. Leave it empty and the block does not appear at all, which is also how you hand this content over to the theme later.', 'sacscoc-institutions' ); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="sacscoc_inst_sites_legend_content"><?php esc_html_e( 'Off-campus Sites Legend', 'sacscoc-institutions' ); ?></label>
                    </th>
                    <td>
                        <textarea name="sacscoc_inst_sites_legend_content" id="sacscoc_inst_sites_legend_content"
                                  rows="10" class="large-text code"><?php
                            echo esc_textarea( (string) get_option( 'sacscoc_inst_sites_legend_content', '' ) );
                        ?></textarea>
                        <p class="description">
                            <?php esc_html_e( 'The Types and Status explanations shown above an institution\'s Off-campus Instructional Sites list — the same on every institution, so it is one setting rather than a copy stored on each record.', 'sacscoc-institutions' ); ?>
                            <br />
                            <?php esc_html_e( 'Basic HTML is allowed. Leave it empty to use the built-in wording, matching the current production site.', 'sacscoc-institutions' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'Deleting this plugin', 'sacscoc-institutions' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Stored data', 'sacscoc-institutions' ); ?></th>
                    <td>
                        <label for="sacscoc_inst_delete_data_on_uninstall">
                            <input name="sacscoc_inst_delete_data_on_uninstall" id="sacscoc_inst_delete_data_on_uninstall"
                                   type="checkbox" value="1"
                                   <?php checked( sacscoc_inst_deletes_data_on_uninstall() ); ?> />
                            <?php esc_html_e( 'Delete everything when this plugin is deleted', 'sacscoc-institutions' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'WordPress asks nothing but “are you sure?” when a plugin is deleted, and there is no way to add a second question to that dialog — so this box is that question, answered in advance.', 'sacscoc-institutions' ); ?>
                        </p>
                        <p class="description">
                            <?php if ( sacscoc_inst_deletes_data_on_uninstall() ) : ?>
                                <strong><?php
                                    printf(
                                        /* translators: %s: number of institutions */
                                        esc_html__( 'On: deleting the plugin will drop its four tables — %s institutions and the sync log — and every setting on this screen. Deactivating still changes nothing.', 'sacscoc-institutions' ),
                                        esc_html( number_format_i18n( (int) $stats['total'] ) )
                                    );
                                ?></strong>
                            <?php else : ?>
                                <?php
                                printf(
                                    /* translators: %s: number of institutions */
                                    esc_html__( 'Off: deleting the plugin leaves the tables and settings in place, so reinstalling picks up the %s institutions already stored instead of re-downloading them. Tick the box to start from scratch instead.', 'sacscoc-institutions' ),
                                    esc_html( number_format_i18n( (int) $stats['total'] ) )
                                );
                                ?>
                            <?php endif; ?>
                        </p>
                        <p class="description">
                            <?php esc_html_e( 'Either way, nothing outside this plugin is touched: no posts, no pages, and no settings belonging to anything else.', 'sacscoc-institutions' ); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e( 'Start from scratch', 'sacscoc-institutions' ); ?></th>
                    <td>
                        <?php // A link, not a button in this form: it must not be reachable by pressing Enter in a text field, and it must not travel with Save Changes. ?>
                        <a class="button button-secondary"
                           href="<?php echo esc_url( wp_nonce_url(
                               add_query_arg(
                                   [ 'page' => 'sacscoc-institutions-settings', 'sacscoc_reset' => 'confirm' ],
                                   admin_url( 'admin.php' )
                               ),
                               'sacscoc_inst_reset_confirm'
                           ) ); ?>">
                            <?php esc_html_e( 'Delete all stored data now…', 'sacscoc-institutions' ); ?>
                        </a>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: number of institutions currently stored */
                                esc_html__( 'Empties the tables without uninstalling: the %s institutions stored now, their sites and meetings, the sync log and the last sync’s result. The tables themselves and every setting stay, so the next sync refills the directory from the API.', 'sacscoc-institutions' ),
                                esc_html( number_format_i18n( (int) $stats['total'] ) )
                            );
                            ?>
                            <br />
                            <?php esc_html_e( 'You are asked to confirm on the next screen, which says exactly what will go and what it costs.', 'sacscoc-institutions' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <h2><?php esc_html_e( 'Status', 'sacscoc-institutions' ); ?></h2>
        <table class="widefat striped" style="max-width:52em">
            <tbody>
                <tr>
                    <th scope="row" style="width:16em"><?php esc_html_e( 'API Status', 'sacscoc-institutions' ); ?></th>
                    <td>
                        <?php
                        $error = sacscoc_inst_last_error();
                        if ( $check !== null ) {
                            echo $check['ok']
                                ? '<strong style="color:#008a20">' . esc_html__( 'Connected', 'sacscoc-institutions' ) . '</strong>'
                                : '<strong style="color:#d63638">' . esc_html__( 'Not reachable', 'sacscoc-institutions' ) . '</strong>';
                        } elseif ( $error !== null ) {
                            echo '<strong style="color:#d63638">' . esc_html__( 'Last sync failed', 'sacscoc-institutions' ) . '</strong>';
                        } elseif ( $last_ok ) {
                            echo '<strong style="color:#008a20">' . esc_html__( 'Connected', 'sacscoc-institutions' ) . '</strong>';
                        } else {
                            echo '<em>' . esc_html__( 'Not tested yet', 'sacscoc-institutions' ) . '</em>';
                        }
                        ?>
                        &nbsp;
                        <a class="button button-small"
                           href="<?php echo esc_url( wp_nonce_url(
                               add_query_arg( [ 'page' => 'sacscoc-institutions-settings', 'sacscoc_test' => 1 ], admin_url( 'admin.php' ) ),
                               'sacscoc_inst_test'
                           ) ); ?>">
                            <?php esc_html_e( 'Test connection', 'sacscoc-institutions' ); ?>
                        </a>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Last Successful Sync', 'sacscoc-institutions' ); ?></th>
                    <td><?php echo esc_html( sacscoc_inst_format_time( $last_ok ) ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Next Scheduled Sync', 'sacscoc-institutions' ); ?></th>
                    <td><?php echo esc_html( sacscoc_inst_format_time( sacscoc_inst_next_sync() ) ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Institutions Stored', 'sacscoc-institutions' ); ?></th>
                    <td><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></td>
                </tr>
            </tbody>
        </table>

        <p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sacscoc-institutions-sync' ) ); ?>">
                <?php esc_html_e( 'Go to Sync →', 'sacscoc-institutions' ); ?>
            </a>
        </p>
    </div>
    <?php
}

/**
 * A timestamp or MySQL datetime in the site's timezone, or an em dash.
 *
 * Everything the plugin stores is UTC, and everything it shows is local — the
 * conversion lives here so no screen has to think about it.
 */
function sacscoc_inst_format_time( $when ): string {
    if ( empty( $when ) ) return '—';

    $timestamp = is_numeric( $when ) ? (int) $when : strtotime( $when . ' UTC' );
    if ( ! $timestamp ) return '—';

    $format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
    $shown  = wp_date( $format, $timestamp );

    $diff = human_time_diff( $timestamp );
    $rel  = $timestamp <= time()
        /* translators: %s: human-readable time difference, e.g. "2 hours" */
        ? sprintf( __( '%s ago', 'sacscoc-institutions' ), $diff )
        /* translators: %s: human-readable time difference, e.g. "2 hours" */
        : sprintf( __( 'in %s', 'sacscoc-institutions' ), $diff );

    return $shown . ' (' . $rel . ')';
}
