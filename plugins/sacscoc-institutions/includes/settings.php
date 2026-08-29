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

    register_setting( 'sacscoc_inst_settings', 'sacscoc_inst_footer_content', [
        'type'              => 'string',
        'sanitize_callback' => 'wp_kses_post',
        'default'           => '',
    ] );
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
        <h1><?php esc_html_e( 'Institutions Settings', 'sacscoc-institutions' ); ?></h1>

        <?php settings_errors( 'sacscoc_inst_api_base_url' ); ?>

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
                    <td>
                        <?php
                        wp_dropdown_pages( [
                            'name'              => 'sacscoc_inst_directory_page',
                            'id'                => 'sacscoc_inst_directory_page',
                            'selected'          => (int) get_option( 'sacscoc_inst_directory_page', 0 ),
                            'show_option_none'  => __( '— Not set —', 'sacscoc-institutions' ),
                            'option_none_value' => 0,
                        ] );
                        ?>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: the directory shortcode */
                                esc_html__( 'The page carrying the %s shortcode. Institution pages link back to it, so it is worth setting — but the shortcode works on any page whether or not it is named here.', 'sacscoc-institutions' ),
                                '<code>[sacscoc_institutions]</code>'
                            );
                            ?>
                        </p>
                    </td>
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
