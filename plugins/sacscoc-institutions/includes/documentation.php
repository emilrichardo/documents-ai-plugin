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
 *
 * The first section is the exception to "rendered from the field map": it is
 * about publishing rather than about data, and it reads the live settings and
 * a real institution rather than describing them, so what it shows is what the
 * site is actually configured to do.
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

        <h2><?php esc_html_e( 'Putting it on the site', 'sacscoc-institutions' ); ?></h2>
        <?php sacscoc_inst_docs_frontend(); ?>

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

/**
 * The publishing half of the documentation: the three blocks, the three
 * shortcodes underneath them, and what the settings currently say.
 *
 * Everything here is read live — the layout, the page size, the URL base, and a
 * real institution id for the example — so this screen cannot describe a
 * configuration the site does not have.
 */
function sacscoc_inst_docs_frontend(): void {
    $layouts  = sacscoc_inst_layouts();
    $layout   = sacscoc_inst_layout();
    $per_page = sacscoc_inst_per_page();
    $page_id  = (int) get_option( 'sacscoc_inst_directory_page', 0 );

    // A real id makes the example copyable. Any institution will do; the first
    // one alphabetically is stable enough for a document.
    $example = sacscoc_inst_search( [ 'per_page' => 1 ] )['rows'][0] ?? null;
    $embed   = $example !== null
        ? sacscoc_inst_embed_shortcode( $example )
        : '[sacscoc_institution id="1246"]';
    ?>
    <h3><?php esc_html_e( 'The blocks', 'sacscoc-institutions' ); ?></h3>
    <p class="description" style="max-width:60em">
        <?php esc_html_e( 'The no-code way to publish: add either from the block inserter, then configure it from its own Inspector Controls in the sidebar. Background colour, text colour, padding and font size are the block’s own toolbar controls — the ordinary way any block offers those — not a setting here.', 'sacscoc-institutions' ); ?>
    </p>
    <table class="widefat striped" style="max-width:70em">
        <thead><tr>
            <th style="width:22em"><?php esc_html_e( 'Block', 'sacscoc-institutions' ); ?></th>
            <th><?php esc_html_e( 'What it puts on the page', 'sacscoc-institutions' ); ?></th>
        </tr></thead>
        <tbody>
            <tr>
                <td><?php esc_html_e( 'Institutions Directory', 'sacscoc-institutions' ); ?></td>
                <td>
                    <?php esc_html_e( 'The whole directory: the search, the results and the pagination. One per page.', 'sacscoc-institutions' ); ?>
                    <br />
                    <?php esc_html_e( 'Inspector Controls: Layout, Results per page, Show the result count, Show the search form, a State / Highest degree / Reaffirmation year restriction, and the two headings. Turning the search form off reveals a Group field, for pairing with an Institutions Search block placed elsewhere.', 'sacscoc-institutions' ); ?>
                </td>
            </tr>
            <tr>
                <td><?php esc_html_e( 'Institutions Search', 'sacscoc-institutions' ); ?></td>
                <td>
                    <?php esc_html_e( 'Just the search form, on its own — for a page that puts an Institutions Directory block with “Show the search form” off somewhere that block’s own layout cannot reach: a sidebar, a header, another column.', 'sacscoc-institutions' ); ?>
                    <br />
                    <?php esc_html_e( 'Inspector Controls: Layout (Vertical, the panel, or Horizontal, a single bar — independent of whatever layout a directory elsewhere on the page is using), Heading, Group, and Constrain width to match the directory (on by default). The Directory and Search blocks find each other purely in the browser, by matching Group — default “default” on both, so the ordinary one-of-each page needs neither field touched.', 'sacscoc-institutions' ); ?>
                </td>
            </tr>
            <tr>
                <td><?php esc_html_e( 'Institution', 'sacscoc-institutions' ); ?></td>
                <td>
                    <?php esc_html_e( 'One institution’s record. Search its name in the block’s own Inspector Controls and pick it from the matches — no id to look up first.', 'sacscoc-institutions' ); ?>
                    <br />
                    <?php esc_html_e( 'Inspector Controls: the institution search itself, plus Show the “Back to Results” button and Show the “About SACSCOC” block.', 'sacscoc-institutions' ); ?>
                </td>
            </tr>
        </tbody>
    </table>

    <h3><?php esc_html_e( 'The shortcodes', 'sacscoc-institutions' ); ?></h3>
    <p class="description" style="max-width:60em">
        <?php esc_html_e( 'The blocks above call exactly these underneath, so nothing here can show something the blocks do not — this is what to reach for on a page with no block editor, or for the odd attribute a control does not offer.', 'sacscoc-institutions' ); ?>
    </p>
    <table class="widefat striped" style="max-width:70em">
        <thead><tr>
            <th style="width:22em"><?php esc_html_e( 'Shortcode', 'sacscoc-institutions' ); ?></th>
            <th><?php esc_html_e( 'What it puts on the page', 'sacscoc-institutions' ); ?></th>
        </tr></thead>
        <tbody>
            <tr>
                <td><code>[sacscoc_institutions]</code></td>
                <td>
                    <?php esc_html_e( 'The whole directory: the search, the results and the pagination. One per page.', 'sacscoc-institutions' ); ?>
                    <br />
                    <?php
                    printf(
                        /* translators: 1: layout attribute, 2: per_page attribute, 3: show_count attribute, 4: show_search attribute, 5: filter_state/filter_degree/filter_year attributes */
                        esc_html__( 'Attributes: %1$s, %2$s, %3$s. Each one overrides its setting for that page only; left out, the settings below apply. %4$s drops the inline search entirely, for pairing with the shortcode below. %5$s restrict every result to one value each, and drop the matching field from the inline search.', 'sacscoc-institutions' ),
                        '<code>layout</code>',
                        '<code>per_page</code>',
                        '<code>show_count</code>',
                        '<code>show_search="no"</code>',
                        '<code>filter_state</code> / <code>filter_degree</code> / <code>filter_year</code>'
                    );
                    ?>
                </td>
            </tr>
            <tr>
                <td><code>[sacscoc_institutions_search]</code></td>
                <td>
                    <?php esc_html_e( 'Just the search form, on its own — for a page that puts [sacscoc_institutions show_search="no"] somewhere its own layout cannot reach: a custom block, a sidebar, a template part.', 'sacscoc-institutions' ); ?>
                    <br />
                    <?php
                    printf(
                        /* translators: 1: group attribute, 2: show_search attribute */
                        esc_html__( 'They find each other purely in the browser, by matching a %1$s attribute — default “default” on both, so the ordinary one-of-each page needs neither. Set %1$s on this and on %2$s only when a page carries more than one directory/search pair.', 'sacscoc-institutions' ),
                        '<code>group</code>',
                        '<code>show_search="no"</code>'
                    );
                    ?>
                    <br />
                    <?php
                    printf(
                        /* translators: %s: layout attribute */
                        esc_html__( '%s chooses the form’s own shape — the ordinary panel, or a single bar — independent of whatever layout a directory elsewhere on the page is using.', 'sacscoc-institutions' ),
                        '<code>layout="vertical"</code> / <code>layout="horizontal"</code>'
                    );
                    ?>
                    <br />
                    <?php
                    printf(
                        /* translators: %s: contain_width attribute */
                        esc_html__( '%s caps the panel at the same measure the directory itself uses, centred — on by default; set it to “no” for a panel placed somewhere narrower, like a sidebar.', 'sacscoc-institutions' ),
                        '<code>contain_width="no"</code>'
                    );
                    ?>
                </td>
            </tr>
            <tr>
                <td><code><?php echo esc_html( $embed ); ?></code></td>
                <td>
                    <?php esc_html_e( 'One institution’s full record, on any page. The id is the API numeric id, printed ready to copy on every institution’s own screen under “Embed this record” — it comes from the API, so it survives the local table being rebuilt.', 'sacscoc-institutions' ); ?>
                    <br />
                    <?php
                    printf(
                        /* translators: 1: back attribute, 2: about attribute, 3: slug attribute, 4: sf_id attribute */
                        esc_html__( 'Attributes: %1$s adds the “Back to Results” button (off here, on for the institution’s own page), %2$s leaves off the shared About SACSCOC block — worth doing when several records share a page. %3$s and %4$s address an institution instead of the id.', 'sacscoc-institutions' ),
                        '<code>back="yes"</code>',
                        '<code>about="no"</code>',
                        '<code>slug</code>',
                        '<code>sf_id</code>'
                    );
                    ?>
                </td>
            </tr>
        </tbody>
    </table>

    <p class="description" style="max-width:60em">
        <?php esc_html_e( 'Every one of these — all three blocks and all three shortcodes — renders the same templates: the search form is templates/search-form.php and the results are templates/results.php, whichever one is asking for them. Nothing rendered by a block can drift apart from what its shortcode shows, or from the pages themselves. A theme can override any of them by copying the file into a sacscoc-institutions/ folder in the theme.', 'sacscoc-institutions' ); ?>
    </p>

    <h3><?php esc_html_e( 'What the settings currently say', 'sacscoc-institutions' ); ?></h3>
    <table class="widefat striped" style="max-width:70em">
        <tbody>
            <tr>
                <th scope="row" style="width:14em"><?php esc_html_e( 'Directory layout', 'sacscoc-institutions' ); ?></th>
                <td>
                    <strong><?php echo esc_html( $layouts[ $layout ] ?? $layout ); ?></strong><br />
                    <span class="description"><?php
                        esc_html_e( 'Two columns puts the results left and the search panel right. One column puts a search bar across the top and the results full width beneath it, as the site’s own Find an Institution page does; there the field labels are read but not seen, and the Search button stays visible because it is part of the shape of the bar.', 'sacscoc-institutions' );
                    ?></span>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Results per page', 'sacscoc-institutions' ); ?></th>
                <td>
                    <strong><?php echo esc_html( number_format_i18n( $per_page ) ); ?></strong><br />
                    <span class="description"><?php
                        printf(
                            /* translators: 1: minimum, 2: maximum */
                            esc_html__( 'Held between %1$s and %2$s, on the way in and on the way out — a value written straight to the database still cannot produce a query for the whole table.', 'sacscoc-institutions' ),
                            esc_html( (string) SACSCOC_INST_PER_PAGE_MIN ),
                            esc_html( (string) SACSCOC_INST_PER_PAGE_MAX )
                        );
                    ?></span>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Directory page', 'sacscoc-institutions' ); ?></th>
                <td>
                    <?php if ( $page_id > 0 ) : ?>
                        <a href="<?php echo esc_url( (string) get_permalink( $page_id ) ); ?>" target="_blank" rel="noopener noreferrer">
                            <?php echo esc_html( (string) get_the_title( $page_id ) ); ?></a>
                    <?php else : ?>
                        <em><?php esc_html_e( 'Not set', 'sacscoc-institutions' ); ?></em>
                    <?php endif; ?>
                    <br />
                    <?php if ( $page_id > 0 && sacscoc_inst_directory_page_needs_directory( $page_id ) ) : ?>
                        <span class="description" style="color:#b32d2e">
                            <?php esc_html_e( 'This page’s content does not have the directory on it yet — neither the shortcode nor the Institutions Directory block — so nothing shows there. Institutions → Settings has an “Add the directory to this page now” button for exactly this.', 'sacscoc-institutions' ); ?>
                        </span>
                    <?php else : ?>
                        <span class="description"><?php esc_html_e( 'Written into the page as a real, editable Institutions Directory block — either by Settings → Create Institutions Page, or by pasting the block in by hand, or the shortcode for more control (an intro above it, or attributes such as per_page). Institution pages link back to this page too.', 'sacscoc-institutions' ); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'On deleting the plugin', 'sacscoc-institutions' ); ?></th>
                <td>
                    <?php if ( sacscoc_inst_deletes_data_on_uninstall() ) : ?>
                        <strong><?php esc_html_e( 'Everything is deleted', 'sacscoc-institutions' ); ?></strong><br />
                        <span class="description"><?php esc_html_e( 'The four tables and every setting go with the plugin. Deactivating still changes nothing — only deleting does this.', 'sacscoc-institutions' ); ?></span>
                    <?php else : ?>
                        <strong><?php esc_html_e( 'Data is kept', 'sacscoc-institutions' ); ?></strong><br />
                        <span class="description"><?php esc_html_e( 'Deleting the plugin leaves the tables and settings behind, so reinstalling picks up where it left off. Tick the box in Settings → Deleting this plugin to start from scratch instead; WordPress cannot ask at the moment of deleting, so that box is the question asked in advance.', 'sacscoc-institutions' ); ?></span>
                    <?php endif; ?>
                    <br />
                    <span class="description"><?php esc_html_e( 'To empty the tables without uninstalling — a full resync from nothing — use “Delete all stored data now…” in the same section. It confirms first, and it keeps every setting.', 'sacscoc-institutions' ); ?></span>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Institution URLs', 'sacscoc-institutions' ); ?></th>
                <td>
                    <code><?php echo esc_html( trailingslashit( home_url() ) . sacscoc_inst_rewrite_base() ); ?>/&lt;slug&gt;/</code><br />
                    <span class="description"><?php esc_html_e( 'Ordinary WordPress pages can live under the same base: a URL that is not an institution is handed back to WordPress and resolves as the page it is.', 'sacscoc-institutions' ); ?></span>
                </td>
            </tr>
        </tbody>
    </table>
    <?php
}
