<?php
/**
 * One institution's record in the admin.
 *
 * A single screen, read-only, laid out as WordPress lays out its own edit
 * screens: a main column for what the institution *is*, and a narrower side
 * column for what the *record* is — identifiers, the staff contact, sync
 * bookkeeping. That split is the hierarchy: someone opening this screen is
 * almost always asking about the institution, and the plumbing should be
 * present without competing for attention.
 *
 * ── Why a two-column flow and not a grid of cards ──────────────────────────
 *
 * An auto-fitting grid of cards gives every card its own height, so the bottom
 * edges of each row disagree and the screen reads as ragged. Here each panel
 * spans the full width of its column, so there is nothing to misalign. The one
 * place tiles do sit side by side — the key facts strip — uses equal columns and
 * a fixed internal layout, so those are equal height by construction.
 *
 * Nothing is editable, on purpose. The API is the source of truth and the next
 * sync would silently revert a local edit, so the screen says read-only rather
 * than offering an input that lies.
 *
 * Every one of the 43 API fields and every local bookkeeping column appears
 * somewhere below — grouping is for hierarchy, never for hiding.
 * sacscoc_inst_record_field_coverage() asserts that, and the test suite checks
 * it, so a field cannot be added to the map and quietly left off the screen.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The four dates people open this screen to look up.
 *
 * Shown as a strip of tiles above everything else, with the year large and the
 * full date underneath, because in practice the year is the answer and the date
 * is the detail. They repeat further down in the accreditation cycle panel;
 * that is deliberate — this is the summary, that is the record.
 */
function sacscoc_inst_record_key_dates(): array {
    return [
        'next_reaffirm_date' => __( 'Next reaffirmation', 'sacscoc-institutions' ),
        'fifth_year_date'    => __( 'Next fifth-year review', 'sacscoc-institutions' ),
        'reaffirmed_date'    => __( 'Last reaffirmation', 'sacscoc-institutions' ),
        'accreditation_date' => __( 'Accreditation granted', 'sacscoc-institutions' ),
    ];
}

/**
 * The panels, in order, and which column each one belongs to.
 *
 * Keys inside `fields` are local column names; the value is
 * [ label, type, icon ]. `cols` is how many columns of label-and-value pairs the
 * panel lays its fields out in at desktop width — 2 for the long panels, 1 for
 * the narrow side column.
 */
function sacscoc_inst_record_sections(): array {
    return [
        // ── Main column: the institution ──────────────────────────────────
        'general' => [
            'title'  => __( 'General information', 'sacscoc-institutions' ),
            'icon'   => 'dashicons-info-outline',
            'column' => 'main',
            'cols'   => 2,
            'fields' => [
                'ceo_name'                => [ __( 'CEO', 'sacscoc-institutions' ), 'text', 'dashicons-businessperson' ],
                'phone'                   => [ __( 'Institutional phone', 'sacscoc-institutions' ), 'tel', 'dashicons-phone' ],
                'website'                 => [ __( 'Website', 'sacscoc-institutions' ), 'url', 'dashicons-admin-links' ],
                'program_list'            => [ __( 'Programs list', 'sacscoc-institutions' ), 'url', 'dashicons-welcome-learn-more' ],
                'student_achievement_url' => [ __( 'Student achievement data', 'sacscoc-institutions' ), 'url', 'dashicons-chart-bar' ],
            ],
        ],

        'address' => [
            'title'  => __( 'Address', 'sacscoc-institutions' ),
            'icon'   => 'dashicons-location',
            'column' => 'main',
            'cols'   => 2,
            'fields' => [
                'address_street'  => [ __( 'Street', 'sacscoc-institutions' ), 'text', '' ],
                'address_city'    => [ __( 'City', 'sacscoc-institutions' ), 'text', '' ],
                'address_state'   => [ __( 'State', 'sacscoc-institutions' ), 'text', '' ],
                'address_zip'     => [ __( 'ZIP', 'sacscoc-institutions' ), 'text', '' ],
                'address_country' => [ __( 'Country', 'sacscoc-institutions' ), 'text', 'dashicons-admin-site-alt3' ],
            ],
        ],

        'accreditation' => [
            'title'  => __( 'Accreditation', 'sacscoc-institutions' ),
            'icon'   => 'dashicons-awards',
            'column' => 'main',
            'cols'   => 2,
            'fields' => [
                'accreditation_status'      => [ __( 'Status', 'sacscoc-institutions' ), 'text', '' ],
                'level'                     => [ __( 'Degree level', 'sacscoc-institutions' ), 'level', '' ],
                'control'                   => [ __( 'Control', 'sacscoc-institutions' ), 'text', '' ],
                'sanctions'                 => [ __( 'Public sanctions', 'sacscoc-institutions' ), 'sanction', '' ],
                'general_disclosure_url'    => [ __( 'Disclosure statement', 'sacscoc-institutions' ), 'url', 'dashicons-media-document' ],
                'sort_accreditation_status' => [ __( 'Status sort rank', 'sacscoc-institutions' ), 'text', '' ],
            ],
        ],

        // Rendered as a check list rather than five label/value rows: the
        // question is "what can they award?", and five rows each saying
        // "Approved" answers it far less directly than one list does.
        'degrees' => [
            'title'  => __( 'Approved to offer', 'sacscoc-institutions' ),
            'icon'   => 'dashicons-welcome-learn-more',
            'column' => 'main',
            'render' => 'degrees',
            'fields' => [
                'deg_associate'            => [ __( "Associate's degree", 'sacscoc-institutions' ), 'yesno', '' ],
                'deg_baccalaureate'        => [ __( 'Baccalaureate degree', 'sacscoc-institutions' ), 'yesno', '' ],
                'deg_master'               => [ __( "Master's degree", 'sacscoc-institutions' ), 'yesno', '' ],
                'deg_education_specialist' => [ __( 'Education specialist degree', 'sacscoc-institutions' ), 'yesno', '' ],
                'deg_doctorate'            => [ __( 'Doctoral degrees', 'sacscoc-institutions' ), 'yesno', '' ],
            ],
        ],

        'dates' => [
            'title'  => __( 'Accreditation cycle', 'sacscoc-institutions' ),
            'icon'   => 'dashicons-calendar-alt',
            'column' => 'main',
            'cols'   => 2,
            'fields' => [
                'candidacy_date'               => [ __( 'Candidacy', 'sacscoc-institutions' ), 'date', '' ],
                'accreditation_date'           => [ __( 'Accreditation granted', 'sacscoc-institutions' ), 'date', '' ],
                'reaffirmed_date'              => [ __( 'Last reaffirmation', 'sacscoc-institutions' ), 'date', '' ],
                'next_reaffirm_date'           => [ __( 'Next reaffirmation', 'sacscoc-institutions' ), 'date', '' ],
                'fifth_year_date'              => [ __( 'Next fifth-year review', 'sacscoc-institutions' ), 'date', '' ],
                'distance_learning_approved'   => [ __( 'Distance education approved', 'sacscoc-institutions' ), 'date', '' ],
                'course_credit_based_approved' => [ __( 'CBE course/credit approved', 'sacscoc-institutions' ), 'date', '' ],
            ],
        ],

        // ── Side column: the record ───────────────────────────────────────
        'staff' => [
            'title'  => __( 'SACSCOC staff member', 'sacscoc-institutions' ),
            'icon'   => 'dashicons-businessperson',
            'column' => 'side',
            'cols'   => 1,
            'fields' => [
                'contact_first_name' => [ __( 'First name', 'sacscoc-institutions' ), 'text', '' ],
                'contact_last_name'  => [ __( 'Last name', 'sacscoc-institutions' ), 'text', '' ],
                'contact_email'      => [ __( 'Email', 'sacscoc-institutions' ), 'email', 'dashicons-email' ],
                'contact_phone'      => [ __( 'Phone', 'sacscoc-institutions' ), 'tel', 'dashicons-phone' ],
            ],
        ],

        'identity' => [
            'title'  => __( 'Identifiers', 'sacscoc-institutions' ),
            'icon'   => 'dashicons-id-alt',
            'column' => 'side',
            'cols'   => 1,
            'fields' => [
                'name'          => [ __( 'Name', 'sacscoc-institutions' ), 'text', '' ],
                'sortable_name' => [ __( 'Sortable name', 'sacscoc-institutions' ), 'text', '' ],
                'former_names'  => [ __( 'Former names', 'sacscoc-institutions' ), 'text', '' ],
                'slug'          => [ __( 'URL slug', 'sacscoc-institutions' ), 'code', 'dashicons-admin-links' ],
                'sf_id'         => [ __( 'Salesforce ID', 'sacscoc-institutions' ), 'code', '' ],
                'api_id'        => [ __( 'API numeric ID', 'sacscoc-institutions' ), 'code', '' ],
                'sf_owner_id'   => [ __( 'Salesforce owner ID', 'sacscoc-institutions' ), 'code', '' ],
            ],
        ],

        'sync' => [
            'title'  => __( 'Synchronisation', 'sacscoc-institutions' ),
            'icon'   => 'dashicons-update',
            'column' => 'side',
            'cols'   => 1,
            'fields' => [
                'last_synced'    => [ __( 'Data last written', 'sacscoc-institutions' ), 'datetime', '' ],
                'last_seen'      => [ __( 'Last returned by the API', 'sacscoc-institutions' ), 'datetime', '' ],
                'first_seen'     => [ __( 'First seen locally', 'sacscoc-institutions' ), 'datetime', '' ],
                'missing_since'  => [ __( 'Missing from the API since', 'sacscoc-institutions' ), 'datetime', 'dashicons-warning' ],
                'api_created_at' => [ __( 'Created in the API', 'sacscoc-institutions' ), 'datetime', '' ],
                'api_updated_at' => [ __( 'Touched in the API', 'sacscoc-institutions' ), 'datetime', '' ],
                'api_deleted_at' => [ __( 'Soft-deleted in the API', 'sacscoc-institutions' ), 'datetime', '' ],
                'delete_flag'    => [ __( 'Delete flag', 'sacscoc-institutions' ), 'text', '' ],
                'content_hash'   => [ __( 'Content fingerprint', 'sacscoc-institutions' ), 'code', '' ],
            ],
        ],
    ];
}

/**
 * Local columns the panels above do not carry.
 *
 * `accreditation_history` gets a panel of its own, being a multi-line narrative
 * rather than a label-and-value pair. `raw_json` is the stored copy of the API
 * payload and is not rendered anywhere on this screen; `id` is the surrogate
 * key and is of no interest.
 */
const SACSCOC_INST_RECORD_ELSEWHERE = [ 'accreditation_history', 'raw_json', 'id' ];

/**
 * Which mapped columns the screen does not account for.
 *
 * Empty is the only acceptable answer — anything else means a field is being
 * silently hidden. Asserted by the test suite.
 */
function sacscoc_inst_record_field_coverage(): array {
    $placed = SACSCOC_INST_RECORD_ELSEWHERE;
    foreach ( sacscoc_inst_record_sections() as $section ) {
        $placed = array_merge( $placed, array_keys( $section['fields'] ) );
    }

    return array_values( array_diff( array_keys( sacscoc_inst_writable_columns() ), $placed ) );
}

// ──────────────────────────────────────────────
// The screen
// ──────────────────────────────────────────────

function sacscoc_inst_render_record( string $sf_id ): void {
    $row = sacscoc_inst_get_by_sf_id( $sf_id );

    echo '<div class="wrap sacscoc-admin">';

    if ( $row === null ) {
        echo '<h1>' . esc_html__( 'Institution not found', 'sacscoc-institutions' ) . '</h1>'
           . '<p><a href="' . esc_url( admin_url( 'admin.php?page=sacscoc-institutions' ) ) . '">'
           . esc_html__( '← Back to all institutions', 'sacscoc-institutions' ) . '</a></p></div>';
        return;
    }

    sacscoc_inst_record_hero( $row );
    sacscoc_inst_record_key_facts( $row );
    sacscoc_inst_record_body( $row );

    echo '</div>';
}

/** Identity, state badges, and where to find the institution. */
function sacscoc_inst_record_hero( array $row ): void {
    $name      = sacscoc_inst_display_name( $row );
    $status    = sacscoc_inst_parse_text( $row['accreditation_status'] );
    $level     = sacscoc_inst_parse_text( $row['level'] );
    $sanction  = sacscoc_inst_sanction( $row );
    $permalink = sacscoc_inst_permalink( $row );

    // Status drives the badge colour: a live accreditation reads differently
    // from one that ended, and a sanction has to be impossible to miss.
    $tone = match ( true ) {
        $status === 'Accredited' => 'ok',
        in_array( $status, [ 'Candidate', 'Applicant', 'Inquirer' ], true ) => 'info',
        default => 'muted',
    };
    ?>
    <p class="sacscoc-admin__back">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=sacscoc-institutions' ) ); ?>">
            <span class="dashicons dashicons-arrow-left-alt2"></span>
            <?php esc_html_e( 'All institutions', 'sacscoc-institutions' ); ?>
        </a>
    </p>

    <div class="sacscoc-hero">
        <div class="sacscoc-hero__main">
            <h1 class="sacscoc-hero__name"><?php echo esc_html( $name ); ?></h1>

            <?php if ( $row['former_names'] ) : ?>
                <p class="sacscoc-hero__former">
                    <span class="dashicons dashicons-backup"></span>
                    <?php
                    printf(
                        /* translators: %s: former name(s) */
                        esc_html__( 'Formerly: %s', 'sacscoc-institutions' ),
                        esc_html( $row['former_names'] )
                    );
                    ?>
                </p>
            <?php endif; ?>

            <p class="sacscoc-badges">
                <?php if ( $status !== null ) : ?>
                    <span class="sacscoc-badge sacscoc-badge--<?php echo esc_attr( $tone ); ?>">
                        <?php echo esc_html( $status ); ?></span>
                <?php endif; ?>

                <?php if ( $level !== null ) : ?>
                    <span class="sacscoc-badge sacscoc-badge--plain"
                          title="<?php echo esc_attr( sacscoc_inst_level_tooltips()[ $level ] ?? '' ); ?>">
                        <?php
                        printf(
                            /* translators: %s: Roman numeral degree level */
                            esc_html__( 'Level %s', 'sacscoc-institutions' ),
                            esc_html( $level )
                        );
                        ?>
                    </span>
                <?php endif; ?>

                <?php if ( $row['control'] ) : ?>
                    <span class="sacscoc-badge sacscoc-badge--plain"><?php echo esc_html( $row['control'] ); ?></span>
                <?php endif; ?>

                <?php if ( $sanction !== null ) : ?>
                    <span class="sacscoc-badge sacscoc-badge--crit">
                        <span class="dashicons dashicons-warning"></span>
                        <?php echo esc_html( $sanction ); ?></span>
                <?php endif; ?>

                <?php if ( $row['missing_since'] !== null ) : ?>
                    <span class="sacscoc-badge sacscoc-badge--warn">
                        <span class="dashicons dashicons-hidden"></span>
                        <?php esc_html_e( 'No longer returned by the API', 'sacscoc-institutions' ); ?></span>
                <?php endif; ?>
            </p>

            <p class="sacscoc-hero__where">
                <?php
                $where = array_filter( [
                    sacscoc_inst_parse_text( $row['address_city'] ),
                    sacscoc_inst_parse_text( $row['address_state'] ),
                    sacscoc_inst_parse_text( $row['address_country'] ),
                ] );
                if ( $where ) :
                    ?>
                    <span class="sacscoc-hero__fact">
                        <span class="dashicons dashicons-location"></span>
                        <?php echo esc_html( implode( ', ', $where ) ); ?>
                    </span>
                <?php endif; ?>

                <?php if ( $row['website'] ) : ?>
                    <span class="sacscoc-hero__fact">
                        <span class="dashicons dashicons-admin-links"></span>
                        <a href="<?php echo esc_url( $row['website'] ); ?>" target="_blank" rel="noopener noreferrer">
                            <?php echo esc_html( sacscoc_inst_short_url( (string) $row['website'], 44 ) ); ?></a>
                    </span>
                <?php endif; ?>

                <?php if ( $permalink !== '' ) : ?>
                    <span class="sacscoc-hero__fact">
                        <span class="dashicons dashicons-visibility"></span>
                        <a href="<?php echo esc_url( $permalink ); ?>" target="_blank" rel="noopener noreferrer">
                            <?php esc_html_e( 'View public page', 'sacscoc-institutions' ); ?></a>
                    </span>
                <?php endif; ?>
            </p>
        </div>

        <p class="sacscoc-readonly">
            <span class="dashicons dashicons-lock"></span>
            <span>
                <strong><?php esc_html_e( 'Read-only', 'sacscoc-institutions' ); ?></strong><br />
                <?php esc_html_e( 'The API is the source of truth. Nothing here can be edited, because the next sync would revert it.', 'sacscoc-institutions' ); ?>
            </span>
        </p>
    </div>
    <?php
}

/**
 * The dates people came for, as a strip of equal tiles.
 *
 * Year large, full date small: the year is almost always the answer, and the
 * exact date is the supporting detail. Equal grid columns and an identical
 * internal structure make these the same height without any stretching.
 */
function sacscoc_inst_record_key_facts( array $row ): void {
    ?>
    <div class="sacscoc-keyfacts">
        <?php foreach ( sacscoc_inst_record_key_dates() as $column => $label ) :
            $raw  = sacscoc_inst_parse_text( $row[ $column ] ?? null );
            $year = $raw !== null ? sacscoc_inst_year( $raw ) : null;
            $full = $raw !== null ? sacscoc_inst_date( $raw ) : null;
            ?>
            <div class="sacscoc-keyfact<?php echo $year === null ? ' is-empty' : ''; ?>">
                <span class="sacscoc-keyfact__label"><?php echo esc_html( $label ); ?></span>
                <span class="sacscoc-keyfact__value"><?php echo esc_html( $year ?? '—' ); ?></span>
                <span class="sacscoc-keyfact__note"><?php
                    echo esc_html( $full ?? __( 'Not set', 'sacscoc-institutions' ) );
                ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}

/** The two columns of panels. */
function sacscoc_inst_record_body( array $row ): void {
    $sections = sacscoc_inst_record_sections();
    $history  = sacscoc_inst_history_lines( $row );
    ?>
    <div class="sacscoc-record">
        <div class="sacscoc-record__col">
            <?php
            foreach ( $sections as $key => $section ) {
                if ( $section['column'] !== 'main' ) continue;
                sacscoc_inst_record_panel( $key, $section, $row );
            }
            sacscoc_inst_record_history_panel( $history );
            ?>
        </div>

        <div class="sacscoc-record__col sacscoc-record__col--side">
            <?php
            // First in the side column: it is the one thing on this read-only
            // screen anybody can *do* with the record.
            sacscoc_inst_record_embed_panel( $row );

            foreach ( $sections as $key => $section ) {
                if ( $section['column'] !== 'side' ) continue;
                sacscoc_inst_record_panel( $key, $section, $row );
            }
            ?>
        </div>
    </div>
    <?php
}

/** One panel: a heading and either a field grid or a custom rendering. */
function sacscoc_inst_record_panel( string $key, array $section, array $row ): void {
    ?>
    <section class="sacscoc-panel sacscoc-panel--<?php echo esc_attr( $key ); ?>">
        <h2 class="sacscoc-panel__title">
            <span class="dashicons <?php echo esc_attr( $section['icon'] ); ?>"></span>
            <?php echo esc_html( $section['title'] ); ?>
        </h2>

        <?php if ( ( $section['render'] ?? '' ) === 'degrees' ) : ?>
            <ul class="sacscoc-degrees">
                <?php foreach ( $section['fields'] as $column => [ $label ] ) :
                    $yes = strcasecmp( (string) ( $row[ $column ] ?? '' ), 'Yes' ) === 0;
                    ?>
                    <li class="<?php echo $yes ? 'is-yes' : 'is-no'; ?>">
                        <span class="dashicons <?php echo $yes ? 'dashicons-yes-alt' : 'dashicons-minus'; ?>"></span>
                        <?php echo esc_html( $label ); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else : ?>
            <dl class="sacscoc-kv sacscoc-kv--cols-<?php echo (int) ( $section['cols'] ?? 1 ); ?>">
                <?php foreach ( $section['fields'] as $column => [ $label, $type, $icon ] ) : ?>
                    <?php sacscoc_inst_record_field( $label, $row[ $column ] ?? null, $type, $icon, $row ); ?>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>
    </section>
    <?php
}

/**
 * The shortcode that puts this record on a page, ready to copy.
 *
 * Every institution has one and it is addressed by the API id, so the way to
 * embed a record is on the record rather than in a document somebody has to go
 * and find. Read-only and selected on click: the value is meant to be copied,
 * never typed over — and it is not a form field, so nothing here can be saved.
 */
function sacscoc_inst_record_embed_panel( array $row ): void {
    $shortcode = sacscoc_inst_embed_shortcode( $row );
    $permalink = sacscoc_inst_permalink( $row );
    ?>
    <section class="sacscoc-panel sacscoc-panel--embed">
        <h2 class="sacscoc-panel__title">
            <span class="dashicons dashicons-shortcode"></span>
            <?php esc_html_e( 'Embed this record', 'sacscoc-institutions' ); ?>
        </h2>

        <p class="sacscoc-panel__note">
            <?php esc_html_e( 'Paste this into any page or post to render this institution’s record there.', 'sacscoc-institutions' ); ?>
        </p>

        <input class="sacscoc-embed" type="text" readonly
               value="<?php echo esc_attr( $shortcode ); ?>"
               onclick="this.select();"
               aria-label="<?php esc_attr_e( 'Shortcode for this institution', 'sacscoc-institutions' ); ?>" />

        <p class="sacscoc-panel__note">
            <?php
            printf(
                /* translators: 1: back attribute, 2: about attribute */
                esc_html__( 'Add %1$s for the “Back to Results” button, or %2$s to leave off the shared About SACSCOC block.', 'sacscoc-institutions' ),
                '<code>back="yes"</code>',
                '<code>about="no"</code>'
            );
            ?>
        </p>

        <?php if ( $permalink !== '' ) : ?>
            <p class="sacscoc-panel__note">
                <?php esc_html_e( 'Its own page:', 'sacscoc-institutions' ); ?>
                <a href="<?php echo esc_url( $permalink ); ?>" target="_blank" rel="noopener noreferrer">
                    <?php echo esc_html( sacscoc_inst_short_url( $permalink, 38 ) ); ?></a>
            </p>
        <?php endif; ?>
    </section>
    <?php
}

/** The accreditation history, or a note that the API sends none. */
function sacscoc_inst_record_history_panel( array $history ): void {
    ?>
    <section class="sacscoc-panel">
        <h2 class="sacscoc-panel__title">
            <span class="dashicons dashicons-backup"></span>
            <?php esc_html_e( 'Accreditation history', 'sacscoc-institutions' ); ?>
        </h2>

        <?php if ( $history ) : ?>
            <ol class="sacscoc-history">
                <?php foreach ( $history as $line ) : ?>
                    <li><?php echo esc_html( $line ); ?></li>
                <?php endforeach; ?>
            </ol>
        <?php else : ?>
            <p class="sacscoc-none">
                <?php esc_html_e( 'The API sends no accreditation history for this institution.', 'sacscoc-institutions' ); ?>
            </p>
        <?php endif; ?>
    </section>
    <?php
}

/**
 * One label-and-value row.
 *
 * An empty value still prints its label, greyed: on an inspection screen "the
 * API sends nothing here" is information, and a row that disappears is
 * indistinguishable from a field nobody thought about.
 */
function sacscoc_inst_record_field( string $label, $value, string $type, string $icon, array $row ): void {
    $text = sacscoc_inst_parse_text( is_scalar( $value ) ? (string) $value : null );

    // For most fields an absent value is just absent, and an em dash says so.
    // `sanction` is the exception: the API expresses "no sanction" as null (or
    // as the literal string "No Sanction"), so an em dash there would read as
    // "we do not know" when in fact we know the answer is None.
    $empty = $text === null && $type !== 'sanction';

    echo '<div class="sacscoc-kv__row' . ( $empty ? ' is-empty' : '' ) . '">';

    echo '<dt>';
    if ( $icon !== '' ) echo '<span class="dashicons ' . esc_attr( $icon ) . '"></span>';
    echo esc_html( $label ) . '</dt>';

    echo '<dd>';

    if ( $empty ) {
        echo '<span class="sacscoc-empty-value" title="'
           . esc_attr__( 'The API sends no value for this field', 'sacscoc-institutions' ) . '">—</span>';
    } else {
        switch ( $type ) {
            case 'url':
                printf(
                    '<a href="%s" title="%s" target="_blank" rel="noopener noreferrer">%s <span class="dashicons dashicons-external"></span></a>',
                    esc_url( $text ),
                    esc_attr( $text ),
                    esc_html( sacscoc_inst_short_url( $text ) )
                );
                break;

            case 'email':
                printf( '<a href="%s">%s</a>', esc_url( 'mailto:' . $text ), esc_html( $text ) );
                break;

            case 'tel':
                printf(
                    '<a href="%s">%s</a>',
                    esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $text ) ),
                    esc_html( $text )
                );
                break;

            case 'date':
                echo esc_html( sacscoc_inst_date( $text ) ?? $text );
                break;

            case 'datetime':
                echo esc_html( sacscoc_inst_format_time( $text ) );
                break;

            case 'level':
                $tip = sacscoc_inst_level_tooltips()[ $text ] ?? '';
                echo esc_html( $text );
                if ( $tip !== '' ) {
                    echo ' <span class="sacscoc-tip dashicons dashicons-info-outline" tabindex="0" title="'
                       . esc_attr( $tip ) . '" aria-label="' . esc_attr( $tip ) . '"></span>';
                }
                break;

            case 'sanction':
                $sanction = sacscoc_inst_sanction( $row );
                if ( $sanction === null ) {
                    printf(
                        '<span class="sacscoc-flag is-yes"><span class="dashicons dashicons-yes-alt"></span>%s</span>',
                        esc_html__( 'None', 'sacscoc-institutions' )
                    );
                } else {
                    printf(
                        '<span class="sacscoc-flag is-crit"><span class="dashicons dashicons-warning"></span>%s</span>',
                        esc_html( $sanction )
                    );
                }
                break;

            case 'code':
                echo '<code>' . esc_html( $text ) . '</code>';
                break;

            default:
                echo esc_html( $text );
        }
    }

    echo '</dd></div>';
}

/**
 * A URL short enough to sit in a label-and-value grid.
 *
 * Some of these run to 200 characters, which stretches a cell tall enough to
 * throw the column out. The host always survives; the path is elided from the
 * middle so both ends stay recognisable, and the full URL is in the link's href
 * and title.
 */
function sacscoc_inst_short_url( string $url, int $max = 34 ): string {
    $shown = preg_replace( '#^https?://(www\.)?#', '', $url );
    $shown = rtrim( (string) $shown, '/' );

    if ( mb_strlen( $shown ) <= $max ) return $shown;

    $head = mb_substr( $shown, 0, (int) floor( ( $max - 1 ) * 0.62 ) );
    $tail = mb_substr( $shown, -(int) floor( ( $max - 1 ) * 0.32 ) );

    return $head . '…' . $tail;
}
