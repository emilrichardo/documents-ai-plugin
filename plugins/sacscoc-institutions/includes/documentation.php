<?php
/**
 * Institutions → Documentation.
 *
 * Rendered from the plugin's own field map rather than from prose kept beside
 * it, so the table on this screen is the mapping the sync actually applies. A
 * field added to includes/fields.php appears here without anyone remembering to
 * update a document.
 *
 * The same map, in a form that survives outside WordPress, is in
 * docs/API-FIELD-MAP.md.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function sacscoc_inst_docs_page(): void {
    if ( ! current_user_can( 'edit_posts' ) ) return;

    $map   = sacscoc_inst_field_map();
    $usage = sacscoc_inst_field_usage();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Institutions Documentation', 'sacscoc-institutions' ); ?>
            <span class="title-count">v<?php echo esc_html( SACSCOC_INST_VERSION ); ?></span>
        </h1>

        <h2><?php esc_html_e( 'How this plugin works', 'sacscoc-institutions' ); ?></h2>
        <p style="max-width:60em">
            <?php esc_html_e( 'The SACSCOC API is the source of truth for institution data. This plugin keeps a copy of it in WordPress and serves the directory from that copy, so visitors never wait on the API and the directory stays up when the API does not. Nothing is edited locally: the sync would overwrite it.', 'sacscoc-institutions' ); ?>
        </p>

        <h2><?php esc_html_e( 'The API', 'sacscoc-institutions' ); ?></h2>
        <p>
            <?php esc_html_e( 'Base URL, configured in Settings:', 'sacscoc-institutions' ); ?>
            <code><?php echo esc_html( sacscoc_inst_api_base_url() ); ?></code>
        </p>
        <table class="widefat striped" style="max-width:70em">
            <thead><tr>
                <th style="width:24em"><?php esc_html_e( 'Endpoint', 'sacscoc-institutions' ); ?></th>
                <th><?php esc_html_e( 'What it returns', 'sacscoc-institutions' ); ?></th>
            </tr></thead>
            <tbody>
                <tr>
                    <td><code>GET /api/v1/search?name=&amp;state=&amp;degree=&amp;next_reaffirm_date=</code></td>
                    <td><?php esc_html_e( 'The whole directory in one response — every institution, every field. All four parameters accept an empty value meaning "no filter". There is no pagination and no total; the array is the entire payload. This is the only endpoint the sync uses.', 'sacscoc-institutions' ); ?></td>
                </tr>
                <tr>
                    <td><code>GET /api/v1/institution?sf_institution_id=…</code></td>
                    <td><?php esc_html_e( 'One institution. Returns the same fields as the search endpoint, so the sync has no reason to call it.', 'sacscoc-institutions' ); ?></td>
                </tr>
                <tr>
                    <td><code>GET /api/v1/sites?sf_institution_id=…</code></td>
                    <td><?php esc_html_e( 'Off-campus instructional sites for one institution: name, status (Open / Closed), type (Approved ≥ 50%, Approved Branch ≥ 50%, Notified 25–49%) and address.', 'sacscoc-institutions' ); ?></td>
                </tr>
                <tr>
                    <td><code>GET /api/v1/recentmeetings?sf_institution_id=…</code><br />
                        <code>GET /api/v1/inprogressmeetings?sf_institution_id=…</code></td>
                    <td><?php esc_html_e( 'Reviews and meetings for one institution — the "Most Recent History with SACSCOC" and "In-Progress Reviews" sections of the existing detail page. Both return the same record shape.', 'sacscoc-institutions' ); ?></td>
                </tr>
            </tbody>
        </table>
        <p class="description" style="max-width:60em">
            <?php esc_html_e( 'No endpoint requires authentication, and there is no endpoint for "institutions changed since X". The API also rewrites updated_at on every record on every refresh, so it cannot stand in for one — which is why the sync downloads the full dataset and compares it locally against a stored fingerprint.', 'sacscoc-institutions' ); ?>
        </p>

        <h2><?php esc_html_e( 'How a sync decides what to write', 'sacscoc-institutions' ); ?></h2>
        <ol style="max-width:60em">
            <li><?php esc_html_e( 'Download the full directory. Anything that is not a 200 with well-formed JSON containing a "results" list ends the sync here, before anything is written.', 'sacscoc-institutions' ); ?></li>
            <li><?php esc_html_e( 'Sanity-check the size. An empty list, or one holding under half as many records as are already stored, is treated as a failure — a partial response is the dangerous one, because it looks like a success.', 'sacscoc-institutions' ); ?></li>
            <li><?php esc_html_e( 'Fingerprint each record and compare it with the stored fingerprint. Matching means the institution is left completely untouched.', 'sacscoc-institutions' ); ?></li>
            <li><?php esc_html_e( 'Insert the new records and rewrite only the changed ones. Each record is matched on its Salesforce id, never on its name — names repeat and some records have none.', 'sacscoc-institutions' ); ?></li>
            <li><?php esc_html_e( 'Mark any institution the API no longer sends with the date it went missing. It is kept, with its data and its URL. Nothing in this plugin deletes an institution.', 'sacscoc-institutions' ); ?></li>
        </ol>

        <h2><?php esc_html_e( 'API field → Local column → Frontend usage', 'sacscoc-institutions' ); ?></h2>
        <p class="description">
            <?php
            printf(
                /* translators: %d: number of fields */
                esc_html__( 'All %d fields the API returns for an institution. None are dropped for being unused in the current design.', 'sacscoc-institutions' ),
                count( $map )
            );
            ?>
        </p>
        <table class="widefat striped">
            <thead><tr>
                <th style="width:15em"><?php esc_html_e( 'API field', 'sacscoc-institutions' ); ?></th>
                <th style="width:15em"><?php esc_html_e( 'Local column', 'sacscoc-institutions' ); ?></th>
                <th style="width:5em"><?php esc_html_e( 'Type', 'sacscoc-institutions' ); ?></th>
                <th><?php esc_html_e( 'Frontend usage', 'sacscoc-institutions' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $map as $api_field => [ $column, $cast ] ) : ?>
                <tr>
                    <td><code><?php echo esc_html( $api_field ); ?></code></td>
                    <td><code><?php echo esc_html( $column ); ?></code></td>
                    <td><?php echo esc_html( $cast ); ?></td>
                    <td><?php echo esc_html( (string) ( $usage[ $api_field ] ?? '' ) ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h2><?php esc_html_e( 'Local columns with no API counterpart', 'sacscoc-institutions' ); ?></h2>
        <table class="widefat striped" style="max-width:70em">
            <tbody>
                <tr><th scope="row" style="width:12em"><code>slug</code></th>
                    <td><?php esc_html_e( 'The institution\'s public URL segment. Assigned once, on first insert, and never rewritten — so an institution being renamed upstream does not break links to it. Names are not unique, so collisions get a numeric suffix.', 'sacscoc-institutions' ); ?></td></tr>
                <tr><th scope="row"><code>raw_json</code></th>
                    <td><?php esc_html_e( 'The untouched API record. It is what makes a mapping mistake recoverable and a new field adoptable without re-discovering the response, and it is shown on each institution\'s screen next to the parsed values.', 'sacscoc-institutions' ); ?></td></tr>
                <tr><th scope="row"><code>content_hash</code></th>
                    <td><?php esc_html_e( 'Fingerprint of the record, excluding created_at and updated_at. Equal fingerprint means nothing is written.', 'sacscoc-institutions' ); ?></td></tr>
                <tr><th scope="row"><code>first_seen</code></th>
                    <td><?php esc_html_e( 'When this institution first arrived locally. Never revised.', 'sacscoc-institutions' ); ?></td></tr>
                <tr><th scope="row"><code>last_seen</code></th>
                    <td><?php esc_html_e( 'The last sync in which the API returned this institution — whether or not anything changed.', 'sacscoc-institutions' ); ?></td></tr>
                <tr><th scope="row"><code>last_synced</code></th>
                    <td><?php esc_html_e( 'The last time this institution\'s data was actually written. Unchanged records keep an older value, which is the point.', 'sacscoc-institutions' ); ?></td></tr>
                <tr><th scope="row"><code>missing_since</code></th>
                    <td><?php esc_html_e( 'Set when the API stops returning the institution, cleared if it comes back. Never a reason to delete the row.', 'sacscoc-institutions' ); ?></td></tr>
            </tbody>
        </table>

        <h2><?php esc_html_e( 'What the dataset looks like', 'sacscoc-institutions' ); ?></h2>
        <?php $breakdown = sacscoc_inst_tables_ready() ? sacscoc_inst_status_breakdown() : []; ?>
        <?php if ( $breakdown ) : ?>
            <table class="widefat striped" style="max-width:32em">
                <thead><tr>
                    <th><?php esc_html_e( 'Accreditation status', 'sacscoc-institutions' ); ?></th>
                    <th style="text-align:right"><?php esc_html_e( 'Institutions', 'sacscoc-institutions' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $breakdown as $line ) : ?>
                    <tr>
                        <td><?php echo esc_html( $line['status'] ?? __( '(none)', 'sacscoc-institutions' ) ); ?></td>
                        <td style="text-align:right"><?php echo esc_html( number_format_i18n( (int) $line['n'] ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p><em><?php esc_html_e( 'Nothing stored yet — run a sync.', 'sacscoc-institutions' ); ?></em></p>
        <?php endif; ?>

        <h2><?php esc_html_e( 'Independence from AI Documents', 'sacscoc-institutions' ); ?></h2>
        <p style="max-width:60em">
            <?php esc_html_e( 'This plugin shares no code, tables, options or hooks with the AI Documents plugin. Both live in the same repository, but neither requires the other to be installed or active, each carries its own version, and each is deployed on its own. There is no AI in this plugin.', 'sacscoc-institutions' ); ?>
        </p>
    </div>
    <?php
}
