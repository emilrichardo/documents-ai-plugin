<?php
/**
 * The admin side: Institutions → All Institutions / Sync / Settings / Documentation.
 *
 * These screens are for inspection, debugging and sync status — not for editing.
 * The API is the source of truth; WordPress holds a copy. Nothing here writes
 * to an institution's data, on purpose: an edit made locally would be silently
 * reverted by the next sync, which is a worse experience than not offering the
 * edit at all.
 *
 * The visitor-facing directory is deliberately not here. It is the next
 * milestone, and it reads the same tables.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', 'sacscoc_inst_admin_menu' );
function sacscoc_inst_admin_menu(): void {
    add_menu_page(
        __( 'Institutions', 'sacscoc-institutions' ),
        __( 'Institutions', 'sacscoc-institutions' ),
        'edit_posts',
        'sacscoc-institutions',
        'sacscoc_inst_list_page',
        'dashicons-bank',
        26
    );

    add_submenu_page(
        'sacscoc-institutions',
        __( 'All Institutions', 'sacscoc-institutions' ),
        __( 'All Institutions', 'sacscoc-institutions' ),
        'edit_posts',
        'sacscoc-institutions',
        'sacscoc_inst_list_page'
    );

    add_submenu_page(
        'sacscoc-institutions',
        __( 'Sync', 'sacscoc-institutions' ),
        __( 'Sync', 'sacscoc-institutions' ),
        'manage_options',
        'sacscoc-institutions-sync',
        'sacscoc_inst_sync_page'
    );

    add_submenu_page(
        'sacscoc-institutions',
        __( 'Institutions Settings', 'sacscoc-institutions' ),
        __( 'Settings', 'sacscoc-institutions' ),
        'manage_options',
        'sacscoc-institutions-settings',
        'sacscoc_inst_settings_page'
    );

    add_submenu_page(
        'sacscoc-institutions',
        __( 'Institutions Documentation', 'sacscoc-institutions' ),
        __( 'Documentation', 'sacscoc-institutions' ),
        'edit_posts',
        'sacscoc-institutions-docs',
        'sacscoc_inst_docs_page'
    );
}

/**
 * A failed sync gets a notice on every Institutions screen.
 *
 * The point of the plugin is that the directory keeps working when the API does
 * not — which means a failure is quiet by design, and something has to make it
 * loud for an administrator.
 */
add_action( 'admin_notices', 'sacscoc_inst_failure_notice' );
function sacscoc_inst_failure_notice(): void {
    $screen = get_current_screen();
    if ( ! $screen || ! str_contains( (string) $screen->id, 'sacscoc-institutions' ) ) return;

    $error = sacscoc_inst_last_error();
    if ( $error === null ) return;

    $stats = sacscoc_inst_stats();
    ?>
    <div class="notice notice-error">
        <p><strong><?php esc_html_e( 'The last institutions sync failed.', 'sacscoc-institutions' ); ?></strong>
           <?php echo esc_html( $error['message'] ); ?></p>
        <p>
            <?php
            printf(
                /* translators: 1: when the failure happened, 2: number of institutions still stored */
                esc_html__( '%1$s. The local directory was not modified — %2$s institutions are still stored and still being served.', 'sacscoc-institutions' ),
                esc_html( sacscoc_inst_format_time( $error['when'] ) ),
                esc_html( number_format_i18n( $stats['total'] ) )
            );
            ?>
        </p>
    </div>
    <?php
}

// ──────────────────────────────────────────────
// Sync Now
// ──────────────────────────────────────────────

add_action( 'admin_post_sacscoc_inst_sync_now', 'sacscoc_inst_handle_sync_now' );
function sacscoc_inst_handle_sync_now(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You are not allowed to run a sync.', 'sacscoc-institutions' ) );
    }
    check_admin_referer( 'sacscoc_inst_sync_now' );

    // A first sync inserts 1,201 rows. Ask for the room to finish; hosts that
    // refuse simply keep their own limit, and the sync is written so that a run
    // cut short leaves consistent data behind.
    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 300 );
    }

    sacscoc_inst_sync_institutions( 'manual' );

    // Where "Sync Now" sends the admin back to: the Sync screen normally, or
    // the onboarding screen when its own Sync step is what submitted this —
    // a real page slug, not an arbitrary URL, and checked against a fixed
    // list rather than trusted from the request, so this can never become an
    // open redirect.
    $page = (string) ( $_POST['redirect_page'] ?? 'sacscoc-institutions-sync' );
    if ( ! in_array( $page, [ 'sacscoc-institutions-sync', 'sacscoc-institutions-onboarding' ], true ) ) {
        $page = 'sacscoc-institutions-sync';
    }

    wp_safe_redirect( add_query_arg(
        [ 'page' => $page, 'synced' => 1 ],
        admin_url( 'admin.php' )
    ) );
    exit;
}

add_action( 'admin_post_sacscoc_inst_sync_related_now', 'sacscoc_inst_handle_sync_related_now' );
function sacscoc_inst_handle_sync_related_now(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You are not allowed to run a sync.', 'sacscoc-institutions' ) );
    }
    check_admin_referer( 'sacscoc_inst_sync_related_now' );

    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 120 );
    }

    sacscoc_inst_sync_related_batch( 'manual' );

    wp_safe_redirect( add_query_arg(
        [ 'page' => 'sacscoc-institutions-sync', 'related_synced' => 1 ],
        admin_url( 'admin.php' )
    ) );
    exit;
}

// ──────────────────────────────────────────────
// Institutions → Sync
// ──────────────────────────────────────────────

function sacscoc_inst_sync_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $stats   = sacscoc_inst_stats();
    $last    = sacscoc_inst_last_result();
    $last_ok = get_option( 'sacscoc_inst_last_successful_sync' );
    $error   = sacscoc_inst_last_error();
    $just_ran = isset( $_GET['synced'] );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Institutions Sync', 'sacscoc-institutions' ); ?></h1>

        <?php if ( $just_ran && $last !== null && $last['status'] === 'success' ) : ?>
            <div class="notice notice-success">
                <p><strong><?php esc_html_e( 'Sync complete.', 'sacscoc-institutions' ); ?></strong></p>
                <p><?php
                    printf(
                        /* translators: 1: received, 2: created, 3: updated, 4: unchanged, 5: duration in seconds */
                        esc_html__( '%1$s institutions processed — %2$s created, %3$s updated, %4$s unchanged. Took %5$s seconds.', 'sacscoc-institutions' ),
                        esc_html( number_format_i18n( $last['processed'] ) ),
                        esc_html( number_format_i18n( $last['created'] ) ),
                        esc_html( number_format_i18n( $last['updated'] ) ),
                        esc_html( number_format_i18n( $last['unchanged'] ) ),
                        esc_html( number_format_i18n( $last['duration_ms'] / 1000, 1 ) )
                    );
                ?></p>
            </div>
        <?php elseif ( $just_ran && $last !== null && $last['status'] === 'skipped' ) : ?>
            <div class="notice notice-warning"><p><?php echo esc_html( $last['message'] ); ?></p></div>
        <?php endif; ?>

        <table class="widefat striped" style="max-width:52em">
            <tbody>
                <tr>
                    <th scope="row" style="width:16em"><?php esc_html_e( 'API Status', 'sacscoc-institutions' ); ?></th>
                    <td>
                        <?php if ( $error !== null ) : ?>
                            <strong style="color:#d63638"><?php esc_html_e( 'Last attempt failed', 'sacscoc-institutions' ); ?></strong>
                        <?php elseif ( $last_ok ) : ?>
                            <strong style="color:#008a20"><?php esc_html_e( 'Connected', 'sacscoc-institutions' ); ?></strong>
                        <?php else : ?>
                            <em><?php esc_html_e( 'Never synced', 'sacscoc-institutions' ); ?></em>
                        <?php endif; ?>
                        <br /><code><?php echo esc_html( sacscoc_inst_api_base_url() ); ?></code>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Last Sync', 'sacscoc-institutions' ); ?></th>
                    <td><?php echo esc_html( sacscoc_inst_format_time( $last_ok ) ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Next Scheduled Sync', 'sacscoc-institutions' ); ?></th>
                    <td>
                        <?php echo esc_html( sacscoc_inst_format_time( sacscoc_inst_next_sync() ) ); ?>
                        <?php
                        // The labels already read as a frequency ("Every 6 hours"),
                        // so they are shown as-is rather than wrapped in another
                        // "every".
                        printf( ' — %s',
                            esc_html( strtolower( sacscoc_inst_schedules()[ sacscoc_inst_schedule_name() ] ) ) );
                        ?>
                        <?php if ( sacscoc_inst_cron_disabled() ) : ?>
                            <br /><span style="color:#d63638"><?php
                                esc_html_e( 'WP-Cron is disabled on this site (DISABLE_WP_CRON). The schedule above will not fire unless a system cron calls wp-cron.php.', 'sacscoc-institutions' );
                            ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Institutions', 'sacscoc-institutions' ); ?></th>
                    <td>
                        <strong><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></strong>
                        <?php
                        printf(
                            /* translators: %s: number of accredited institutions */
                            ' — ' . esc_html__( '%s of them accredited', 'sacscoc-institutions' ),
                            esc_html( number_format_i18n( $stats['accredited'] ) )
                        );
                        ?>
                        <?php if ( $stats['missing'] > 0 ) : ?>
                            <br /><span style="color:#996800"><?php
                                printf(
                                    /* translators: %s: number of institutions the API no longer returns */
                                    esc_html__( '%s are no longer returned by the API. They are kept, marked, and never deleted.', 'sacscoc-institutions' ),
                                    esc_html( number_format_i18n( $stats['missing'] ) )
                                );
                            ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:1.5em 0">
            <input type="hidden" name="action" value="sacscoc_inst_sync_now" />
            <?php wp_nonce_field( 'sacscoc_inst_sync_now' ); ?>
            <?php submit_button( __( 'Sync Now', 'sacscoc-institutions' ), 'primary large', 'submit', false ); ?>
            <span class="description" style="margin-left:1em">
                <?php esc_html_e( 'Downloads the full directory and applies only what changed. A first sync takes longer than the rest.', 'sacscoc-institutions' ); ?>
            </span>
        </form>

        <h2><?php esc_html_e( 'Related data', 'sacscoc-institutions' ); ?></h2>
        <p class="description">
            <?php esc_html_e( 'Off-campus instructional sites and reviews/meetings have no bulk API endpoint — each institution is fetched on its own, a small batch at a time, on a separate 5-minute schedule. A full pass over every institution takes roughly half a day.', 'sacscoc-institutions' ); ?>
        </p>
        <?php
        $related_stats = sacscoc_inst_related_stats();
        $related_last  = sacscoc_inst_related_last_result();
        $related_ok    = get_option( 'sacscoc_inst_related_last_sync' );
        ?>
        <?php if ( isset( $_GET['related_synced'] ) && $related_last !== null ) : ?>
            <div class="notice notice-<?php echo $related_last['status'] === 'success' ? 'success' : 'warning'; ?>">
                <p><?php echo esc_html( $related_last['message'] ); ?></p>
            </div>
        <?php endif; ?>
        <table class="widefat striped" style="max-width:52em">
            <tbody>
                <tr>
                    <th scope="row" style="width:16em"><?php esc_html_e( 'Open Sites Stored', 'sacscoc-institutions' ); ?></th>
                    <td><?php echo esc_html( number_format_i18n( $related_stats['sites'] ) ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'In-Progress Reviews Stored', 'sacscoc-institutions' ); ?></th>
                    <td><?php echo esc_html( number_format_i18n( $related_stats['inprogress'] ) ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Recent History Records Stored', 'sacscoc-institutions' ); ?></th>
                    <td><?php echo esc_html( number_format_i18n( $related_stats['recent'] ) ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Last Related-Data Batch', 'sacscoc-institutions' ); ?></th>
                    <td><?php echo esc_html( sacscoc_inst_format_time( $related_ok ) ); ?></td>
                </tr>
                <?php if ( $related_last !== null && $related_last['errors'] ) : ?>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Last Batch Errors', 'sacscoc-institutions' ); ?></th>
                    <td style="color:#d63638">
                        <?php foreach ( array_slice( $related_last['errors'], 0, 10 ) as $error ) : ?>
                            <div><?php echo esc_html( $error ); ?></div>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:1.5em 0">
            <input type="hidden" name="action" value="sacscoc_inst_sync_related_now" />
            <?php wp_nonce_field( 'sacscoc_inst_sync_related_now' ); ?>
            <?php submit_button( __( 'Sync Related Data Now', 'sacscoc-institutions' ), 'secondary', 'submit', false ); ?>
            <span class="description" style="margin-left:1em">
                <?php esc_html_e( 'Runs one batch immediately, same as the 5-minute schedule.', 'sacscoc-institutions' ); ?>
            </span>
        </form>

        <h2><?php esc_html_e( 'Recent syncs', 'sacscoc-institutions' ); ?></h2>
        <?php $log = sacscoc_inst_log_recent( 20 ); ?>
        <?php if ( ! $log ) : ?>
            <p><em><?php esc_html_e( 'No sync has run yet.', 'sacscoc-institutions' ); ?></em></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'When', 'sacscoc-institutions' ); ?></th>
                        <th><?php esc_html_e( 'Trigger', 'sacscoc-institutions' ); ?></th>
                        <th><?php esc_html_e( 'Result', 'sacscoc-institutions' ); ?></th>
                        <th style="text-align:right"><?php esc_html_e( 'Received', 'sacscoc-institutions' ); ?></th>
                        <th style="text-align:right"><?php esc_html_e( 'Created', 'sacscoc-institutions' ); ?></th>
                        <th style="text-align:right"><?php esc_html_e( 'Updated', 'sacscoc-institutions' ); ?></th>
                        <th style="text-align:right"><?php esc_html_e( 'Unchanged', 'sacscoc-institutions' ); ?></th>
                        <th style="text-align:right"><?php esc_html_e( 'Took', 'sacscoc-institutions' ); ?></th>
                        <th><?php esc_html_e( 'Detail', 'sacscoc-institutions' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $log as $row ) :
                    $colour = match ( $row['status'] ) {
                        'success' => '#008a20',
                        'skipped' => '#996800',
                        default   => '#d63638',
                    };
                    ?>
                    <tr>
                        <td><?php echo esc_html( sacscoc_inst_format_time( $row['started_at'] ) ); ?></td>
                        <td><?php echo esc_html( $row['trigger_source'] ); ?></td>
                        <td><strong style="color:<?php echo esc_attr( $colour ); ?>">
                            <?php echo esc_html( $row['status'] ); ?></strong></td>
                        <td style="text-align:right"><?php echo esc_html( number_format_i18n( (int) $row['received'] ) ); ?></td>
                        <td style="text-align:right"><?php echo esc_html( number_format_i18n( (int) $row['created'] ) ); ?></td>
                        <td style="text-align:right"><?php echo esc_html( number_format_i18n( (int) $row['updated'] ) ); ?></td>
                        <td style="text-align:right"><?php echo esc_html( number_format_i18n( (int) $row['unchanged'] ) ); ?></td>
                        <td style="text-align:right"><?php
                            echo $row['duration_ms'] !== null
                                ? esc_html( number_format_i18n( (int) $row['duration_ms'] / 1000, 1 ) . ' s' )
                                : '—';
                        ?></td>
                        <td><?php echo esc_html( (string) $row['message'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

// ──────────────────────────────────────────────
// Institutions → All Institutions
// ──────────────────────────────────────────────

function sacscoc_inst_list_page(): void {
    if ( ! current_user_can( 'edit_posts' ) ) return;

    if ( ! sacscoc_inst_tables_ready() ) {
        echo '<div class="wrap"><h1>' . esc_html__( 'Institutions', 'sacscoc-institutions' ) . '</h1>'
           . '<div class="notice notice-warning"><p>'
           . esc_html__( 'The plugin tables do not exist yet. Deactivating and reactivating the plugin will create them.', 'sacscoc-institutions' )
           . '</p></div></div>';
        return;
    }

    // One institution's full record, for debugging what actually landed.
    $view = isset( $_GET['sacscoc_view'] ) ? sanitize_text_field( wp_unslash( $_GET['sacscoc_view'] ) ) : '';
    if ( $view !== '' ) {
        sacscoc_inst_render_record( $view );
        return;
    }

    $search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
    $state   = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
    $status  = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
    $missing = ! empty( $_GET['missing'] );
    $paged   = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
    $per     = 50;

    $query = sacscoc_inst_admin_query( [
        'search'   => $search,
        'state'    => $state,
        'status'   => $status,
        'missing'  => $missing,
        'per_page' => $per,
        'page'     => $paged,
    ] );

    $pages = (int) ceil( $query['total'] / $per );
    $stats = sacscoc_inst_stats();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'All Institutions', 'sacscoc-institutions' ); ?>
            <span class="title-count"><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></span>
        </h1>

        <p class="description">
            <?php esc_html_e( 'The local copy of the SACSCOC directory, for inspection and debugging. The API is the source of truth — these records are not editable here, because the next sync would overwrite any change.', 'sacscoc-institutions' ); ?>
        </p>

        <form method="get">
            <input type="hidden" name="page" value="sacscoc-institutions" />
            <p class="search-box" style="float:none;margin:1em 0">
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>"
                       placeholder="<?php esc_attr_e( 'Name, former name or sf_id', 'sacscoc-institutions' ); ?>"
                       style="width:22em" />

                <select name="state">
                    <option value=""><?php esc_html_e( 'Any state', 'sacscoc-institutions' ); ?></option>
                    <?php foreach ( sacscoc_inst_distinct( 'address_state' ) as $value ) : ?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $state, $value ); ?>>
                            <?php echo esc_html( $value ); ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="status">
                    <option value=""><?php esc_html_e( 'Any status', 'sacscoc-institutions' ); ?></option>
                    <?php foreach ( sacscoc_inst_distinct( 'accreditation_status' ) as $value ) : ?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>>
                            <?php echo esc_html( $value ); ?></option>
                    <?php endforeach; ?>
                </select>

                <label style="margin:0 .5em">
                    <input type="checkbox" name="missing" value="1" <?php checked( $missing ); ?> />
                    <?php esc_html_e( 'Only missing from API', 'sacscoc-institutions' ); ?>
                </label>

                <?php submit_button( __( 'Filter', 'sacscoc-institutions' ), '', '', false ); ?>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=sacscoc-institutions' ) ); ?>">
                    <?php esc_html_e( 'Reset', 'sacscoc-institutions' ); ?>
                </a>
            </p>
        </form>

        <p><?php
            printf(
                /* translators: %s: number of matching institutions */
                esc_html__( '%s matching institutions.', 'sacscoc-institutions' ),
                esc_html( number_format_i18n( $query['total'] ) )
            );
        ?></p>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Name', 'sacscoc-institutions' ); ?></th>
                    <th><?php esc_html_e( 'City', 'sacscoc-institutions' ); ?></th>
                    <th><?php esc_html_e( 'State', 'sacscoc-institutions' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'sacscoc-institutions' ); ?></th>
                    <th><?php esc_html_e( 'Level', 'sacscoc-institutions' ); ?></th>
                    <th><?php esc_html_e( 'Next Reaffirm', 'sacscoc-institutions' ); ?></th>
                    <th><?php esc_html_e( 'sf_id', 'sacscoc-institutions' ); ?></th>
                    <th><?php esc_html_e( 'Last written', 'sacscoc-institutions' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if ( ! $query['rows'] ) : ?>
                <tr><td colspan="8"><em><?php esc_html_e( 'No institutions match.', 'sacscoc-institutions' ); ?></em></td></tr>
            <?php endif; ?>
            <?php foreach ( $query['rows'] as $row ) : ?>
                <tr>
                    <td>
                        <a href="<?php echo esc_url( add_query_arg(
                            [ 'page' => 'sacscoc-institutions', 'sacscoc_view' => $row['sf_id'] ],
                            admin_url( 'admin.php' )
                        ) ); ?>"><strong><?php echo esc_html( sacscoc_inst_display_name( $row ) ); ?></strong></a>
                        <?php if ( $row['missing_since'] !== null ) : ?>
                            <br /><span style="color:#996800"><?php
                                printf(
                                    /* translators: %s: date the record stopped appearing in the API */
                                    esc_html__( 'Missing from the API since %s', 'sacscoc-institutions' ),
                                    esc_html( sacscoc_inst_format_time( $row['missing_since'] ) )
                                );
                            ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( (string) $row['address_city'] ); ?></td>
                    <td><?php echo esc_html( (string) $row['address_state'] ); ?></td>
                    <td><?php echo esc_html( (string) $row['accreditation_status'] ); ?></td>
                    <td><?php echo esc_html( (string) $row['level'] ); ?></td>
                    <td><?php echo esc_html( $row['next_reaffirm_date'] ? substr( (string) $row['next_reaffirm_date'], 0, 4 ) : '—' ); ?></td>
                    <td><code style="font-size:11px"><?php echo esc_html( $row['sf_id'] ); ?></code></td>
                    <td><?php echo esc_html( sacscoc_inst_format_time( $row['last_synced'] ) ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ( $pages > 1 ) : ?>
            <div class="tablenav"><div class="tablenav-pages"><?php
                echo wp_kses_post( paginate_links( [
                    'base'      => add_query_arg( 'paged', '%#%' ),
                    'format'    => '',
                    'current'   => $paged,
                    'total'     => $pages,
                    'prev_text' => '‹',
                    'next_text' => '›',
                ] ) );
            ?></div></div>
        <?php endif; ?>
    </div>
    <?php
}

// ──────────────────────────────────────────────
// Assets
// ──────────────────────────────────────────────

add_action( 'admin_enqueue_scripts', 'sacscoc_inst_admin_assets' );
function sacscoc_inst_admin_assets( string $hook ): void {
    // Every one of this plugin's screens carries its page slug in the hook, and
    // no other plugin's does, so this loads the stylesheet on exactly our pages
    // and nowhere else in wp-admin.
    if ( ! str_contains( $hook, 'sacscoc-institutions' ) ) return;

    wp_enqueue_style( 'dashicons' );
    wp_enqueue_style(
        'sacscoc-institutions-admin',
        SACSCOC_INST_URL . 'assets/css/sacscoc-admin.css',
        [],
        sacscoc_inst_asset_version( 'assets/css/sacscoc-admin.css' )
    );
}
