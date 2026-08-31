<?php
/**
 * The setup wizard: sync, choose a layout and page size, choose or create the
 * Directory Page — the four things a fresh install actually needs before the
 * directory shows anything, on one screen, in the order that makes them work
 * correctly together.
 *
 * That order matters. Layout and page size are Settings, and the Institutions
 * Directory block inherits from Settings whenever its own attributes are left
 * blank — which is what "Create Institutions Page" hands out. Set them before
 * creating the page and the new block already reflects the right choice with
 * nothing further to configure; set them after and it would too, since the
 * inheritance is live, but there is no reason to make someone re-check a block
 * they already looked at.
 *
 * Every action on this screen already exists somewhere else — Sync Now, the
 * Directory Page picker, the layout and page-size fields — and is reused here
 * rather than reimplemented; this page is a different arrangement of the same
 * handlers and the same markup, not a second copy of what they do. It is why
 * finishing the wizard needs no "mark this done" step of its own: nothing here
 * is wizard-only state, so there is nothing to reset if it is ever revisited.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Registered with a null parent, which is the standard way to make an admin
 * page reachable by URL without it cluttering a menu it only needs to be
 * found from twice: right after activation, and from the link Settings offers
 * for running it again.
 */
add_action( 'admin_menu', 'sacscoc_inst_register_onboarding_page' );
function sacscoc_inst_register_onboarding_page(): void {
    add_submenu_page(
        null,
        __( 'Institutions Setup', 'sacscoc-institutions' ),
        __( 'Institutions Setup', 'sacscoc-institutions' ),
        'manage_options',
        'sacscoc-institutions-onboarding',
        'sacscoc_inst_onboarding_page'
    );
}

/**
 * Send a freshly activated site here once, before whatever screen the admin
 * would otherwise have landed on next.
 *
 * The flag is set in sacscoc_inst_activate() (sacscoc-institutions.php) and
 * cleared here on the very next admin load — a redirect that only fires once
 * per activation, the same pattern WooCommerce and most setup wizards use.
 * Skipped for a bulk activation (`activate-multi` — many plugins at once, no
 * single one should hijack where that lands) and for anything not a normal
 * admin page load, so an AJAX or REST request during activation can never be
 * redirected into HTML by mistake.
 */
add_action( 'admin_init', 'sacscoc_inst_maybe_activation_redirect' );
function sacscoc_inst_maybe_activation_redirect(): void {
    if ( get_option( 'sacscoc_inst_activation_redirect' ) !== '1' ) return;

    delete_option( 'sacscoc_inst_activation_redirect' );

    if ( isset( $_GET['activate-multi'] ) ) return;
    if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;

    wp_safe_redirect( admin_url( 'admin.php?page=sacscoc-institutions-onboarding' ) );
    exit;
}

function sacscoc_inst_onboarding_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $stats       = sacscoc_inst_stats();
    $last        = sacscoc_inst_last_result();
    $just_synced = isset( $_GET['synced'] );
    $has_data    = $stats['total'] > 0;
    ?>
    <div class="wrap sacscoc-onboarding">
        <h1><?php esc_html_e( 'Institutions Setup', 'sacscoc-institutions' ); ?></h1>
        <p style="max-width:46em">
            <?php esc_html_e( 'Three things, in order: pull in the directory, decide how it should look, then put it on a page. Each is exactly what Institutions → Sync and Institutions → Settings already offer — nothing here is separate from those, so anything set on this screen shows up there too, and anything changed there later still applies.', 'sacscoc-institutions' ); ?>
        </p>

        <?php if ( $just_synced && $last !== null && $last['status'] === 'success' ) : ?>
            <div class="notice notice-success">
                <p><strong><?php esc_html_e( 'Sync complete.', 'sacscoc-institutions' ); ?></strong></p>
                <p><?php
                    printf(
                        /* translators: 1: received, 2: created, 3: updated, 4: unchanged */
                        esc_html__( '%1$s institutions processed — %2$s created, %3$s updated, %4$s unchanged.', 'sacscoc-institutions' ),
                        esc_html( number_format_i18n( $last['processed'] ) ),
                        esc_html( number_format_i18n( $last['created'] ) ),
                        esc_html( number_format_i18n( $last['updated'] ) ),
                        esc_html( number_format_i18n( $last['unchanged'] ) )
                    );
                ?></p>
            </div>
        <?php elseif ( $just_synced && $last !== null && $last['status'] === 'skipped' ) : ?>
            <div class="notice notice-warning"><p><?php echo esc_html( $last['message'] ); ?></p></div>
        <?php endif; ?>

        <div class="sacscoc-onboarding__step">
            <h2><?php esc_html_e( '1. Sync the directory', 'sacscoc-institutions' ); ?></h2>
            <p class="description" style="max-width:44em">
                <?php esc_html_e( 'Downloads every institution from the SACSCOC API and stores a local copy — nothing shows on the site until this has run at least once. It also runs on its own from here on, on the schedule set in Institutions → Settings.', 'sacscoc-institutions' ); ?>
            </p>
            <p>
                <?php if ( $has_data ) : ?>
                    <span class="dashicons dashicons-yes-alt" style="color:#008a20"></span>
                    <?php
                    printf(
                        /* translators: %s: number of institutions currently stored */
                        esc_html__( '%s institutions stored.', 'sacscoc-institutions' ),
                        '<strong>' . esc_html( number_format_i18n( $stats['total'] ) ) . '</strong>'
                    );
                    ?>
                <?php else : ?>
                    <em><?php esc_html_e( 'Nothing stored yet.', 'sacscoc-institutions' ); ?></em>
                <?php endif; ?>
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="sacscoc_inst_sync_now" />
                <input type="hidden" name="redirect_page" value="sacscoc-institutions-onboarding" />
                <?php wp_nonce_field( 'sacscoc_inst_sync_now' ); ?>
                <?php submit_button( $has_data ? __( 'Sync Again', 'sacscoc-institutions' ) : __( 'Sync Now', 'sacscoc-institutions' ), 'primary', 'submit', false ); ?>
            </form>
        </div>

        <div class="sacscoc-onboarding__step">
            <h2><?php esc_html_e( '2. Choose a layout and a page size', 'sacscoc-institutions' ); ?></h2>
            <p class="description" style="max-width:44em">
                <?php esc_html_e( 'Both are Settings — changing them here changes them there, and a page created below picks them up automatically, with nothing further to set on the page itself.', 'sacscoc-institutions' ); ?>
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
                <?php settings_fields( 'sacscoc_inst_settings' ); ?>
                <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( add_query_arg( [ 'page' => 'sacscoc-institutions-onboarding' ], admin_url( 'admin.php' ) ) ); ?>" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="sacscoc_inst_layout"><?php esc_html_e( 'Layout', 'sacscoc-institutions' ); ?></label></th>
                        <td>
                            <select name="sacscoc_inst_layout" id="sacscoc_inst_layout">
                                <?php foreach ( sacscoc_inst_layouts() as $key => $label ) : ?>
                                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( sacscoc_inst_layout(), $key ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sacscoc_inst_per_page"><?php esc_html_e( 'Results Per Page', 'sacscoc-institutions' ); ?></label></th>
                        <td>
                            <input name="sacscoc_inst_per_page" id="sacscoc_inst_per_page"
                                   type="number" min="<?php echo esc_attr( (string) SACSCOC_INST_PER_PAGE_MIN ); ?>"
                                   max="<?php echo esc_attr( (string) SACSCOC_INST_PER_PAGE_MAX ); ?>"
                                   step="1" class="small-text"
                                   value="<?php echo esc_attr( (string) sacscoc_inst_per_page() ); ?>" />
                            <?php esc_html_e( 'institutions', 'sacscoc-institutions' ); ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Save Layout and Page Size', 'sacscoc-institutions' ), 'secondary', 'submit', false ); ?>
            </form>
        </div>

        <div class="sacscoc-onboarding__step">
            <h2><?php esc_html_e( '3. Choose or create the directory page', 'sacscoc-institutions' ); ?></h2>
            <p class="description" style="max-width:44em">
                <?php esc_html_e( 'Where visitors actually see the directory. Create a new page for it, or point this at a page you already have.', 'sacscoc-institutions' ); ?>
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
                <?php settings_fields( 'sacscoc_inst_settings' ); ?>
                <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( add_query_arg( [ 'page' => 'sacscoc-institutions-onboarding' ], admin_url( 'admin.php' ) ) ); ?>" />
                <?php sacscoc_inst_render_directory_page_picker(); ?>
                <?php submit_button( __( 'Use This Page', 'sacscoc-institutions' ), 'secondary', 'submit', false ); ?>
            </form>
        </div>

        <div class="sacscoc-onboarding__step sacscoc-onboarding__step--last">
            <h2><?php esc_html_e( 'That\'s it', 'sacscoc-institutions' ); ?></h2>
            <p style="max-width:44em">
                <?php esc_html_e( 'Everything above lives in Institutions → Settings and Institutions → Sync — come back to either any time, or run this wizard again from the link Settings offers.', 'sacscoc-institutions' ); ?>
            </p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=sacscoc-institutions-settings' ) ); ?>">
                    <?php esc_html_e( 'Go to Settings', 'sacscoc-institutions' ); ?>
                </a>
                <?php $page_id = (int) get_option( 'sacscoc_inst_directory_page', 0 ); ?>
                <?php if ( $page_id > 0 ) : ?>
                    <a class="button" href="<?php echo esc_url( (string) get_permalink( $page_id ) ); ?>" target="_blank" rel="noopener noreferrer">
                        <?php esc_html_e( 'View the Directory', 'sacscoc-institutions' ); ?>
                    </a>
                <?php endif; ?>
            </p>
        </div>
    </div>
    <style>
        .sacscoc-onboarding__step { max-width: 52em; margin: 1.5em 0; padding: 1.25em 1.5em; background: #fff; border: 1px solid #dcdcde; border-radius: 4px; }
        .sacscoc-onboarding__step h2 { margin-top: 0; }
        .sacscoc-onboarding__step--last { background: #f6f7f7; }
    </style>
    <?php
}
