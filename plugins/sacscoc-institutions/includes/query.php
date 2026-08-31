<?php
/**
 * The public directory's reads.
 *
 * Separate from includes/repository.php, which serves the admin: this file is
 * what visitors' searches run through, and it answers the four filters the
 * existing directory offers — name, state, highest degree, next reaffirmation
 * year — against the local tables. The API is never touched here.
 *
 * Defaults copied from the current application so the rebuilt directory behaves
 * the same: 25 results per page — now a setting, see sacscoc_inst_per_page() —
 * ordered by `sortable_name`.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** The shipped default, and the fallback whenever the setting is unusable. */
const SACSCOC_INST_PER_PAGE = 25;

/** The smallest and largest page size an admin — or a shortcode — may ask for. */
const SACSCOC_INST_PER_PAGE_MIN = 1;
const SACSCOC_INST_PER_PAGE_MAX = 200;

/**
 * How many results a page of the directory shows.
 *
 * Set in Institutions → Settings; the `[sacscoc_institutions per_page="…"]`
 * attribute overrides it for one page. Clamped on the way out as well as on the
 * way in, so an option written directly to the database — by a migration, or by
 * WP-CLI — still cannot produce a query for 100,000 rows.
 */
function sacscoc_inst_per_page(): int {
    return sacscoc_inst_clamp_per_page( get_option( 'sacscoc_inst_per_page', SACSCOC_INST_PER_PAGE ) );
}

/** A page size held inside the allowed range; anything unreadable becomes the default. */
function sacscoc_inst_clamp_per_page( $value ): int {
    $value = (int) $value;
    if ( $value < SACSCOC_INST_PER_PAGE_MIN ) return SACSCOC_INST_PER_PAGE;

    return min( SACSCOC_INST_PER_PAGE_MAX, $value );
}

/**
 * Degree levels, highest first.
 *
 * The order is the whole definition of "highest degree offered": an institution
 * matches `master` when `deg_master` is Yes and nothing above it is.
 */
function sacscoc_inst_degrees(): array {
    return [
        'doctorate'            => __( 'Doctoral Degrees', 'sacscoc-institutions' ),
        'education_specialist' => __( 'Education Specialist Degree', 'sacscoc-institutions' ),
        'master'               => __( "Master's Degree", 'sacscoc-institutions' ),
        'baccalaureate'        => __( 'Baccalaureate Degree', 'sacscoc-institutions' ),
        'associate'            => __( "Associate's Degree", 'sacscoc-institutions' ),
    ];
}

/** The eleven member states, plus the International pseudo-option. */
function sacscoc_inst_states(): array {
    return [
        'AL'   => __( 'Alabama', 'sacscoc-institutions' ),
        'FL'   => __( 'Florida', 'sacscoc-institutions' ),
        'GA'   => __( 'Georgia', 'sacscoc-institutions' ),
        'KY'   => __( 'Kentucky', 'sacscoc-institutions' ),
        'LA'   => __( 'Louisiana', 'sacscoc-institutions' ),
        'MS'   => __( 'Mississippi', 'sacscoc-institutions' ),
        'NC'   => __( 'North Carolina', 'sacscoc-institutions' ),
        'SC'   => __( 'South Carolina', 'sacscoc-institutions' ),
        'TN'   => __( 'Tennessee', 'sacscoc-institutions' ),
        'TX'   => __( 'Texas', 'sacscoc-institutions' ),
        'VA'   => __( 'Virginia', 'sacscoc-institutions' ),
        'INTL' => __( 'International', 'sacscoc-institutions' ),
    ];
}

/**
 * What each degree level means, for the tooltip beside a Level numeral.
 *
 * Wording taken verbatim from the existing application so the rebuilt pages say
 * exactly what the current ones say.
 */
function sacscoc_inst_level_tooltips(): array {
    return [
        'I'   => __( 'Highest Degree Level Offered – Associate', 'sacscoc-institutions' ),
        'II'  => __( 'Highest Degree Level Offered – Baccalaureate', 'sacscoc-institutions' ),
        'III' => __( 'Highest Degree Level Offered – Master', 'sacscoc-institutions' ),
        'IV'  => __( 'Highest Degree Level Offered – Educational Specialist', 'sacscoc-institutions' ),
        'V'   => __( 'Highest Degree Level Offered – Doctorate (3 or fewer)', 'sacscoc-institutions' ),
        'VI'  => __( 'Highest Degree Level Offered – Doctorate (4 or more)', 'sacscoc-institutions' ),
    ];
}

/**
 * The reaffirmation years that actually have institutions behind them.
 *
 * The current site hardcodes 2021–2036, and the first five of those now return
 * nothing at all — the data only runs 2026–2036. Reading the years from the
 * table means the dropdown never offers a year with no results, and never
 * misses one the API adds later. Cached for a day; a sync clears it.
 */
function sacscoc_inst_reaffirm_years(): array {
    $years = get_transient( 'sacscoc_inst_reaffirm_years' );
    if ( is_array( $years ) ) return $years;

    global $wpdb;
    $table = sacscoc_inst_table( 'institutions' );

    $years = (array) $wpdb->get_col(
        "SELECT DISTINCT YEAR(next_reaffirm_date) AS y FROM $table
          WHERE next_reaffirm_date IS NOT NULL ORDER BY y ASC"
    );
    $years = array_values( array_filter( array_map( 'intval', $years ) ) );

    set_transient( 'sacscoc_inst_reaffirm_years', $years, DAY_IN_SECONDS );
    return $years;
}

/**
 * Search the local directory.
 *
 * @param array $args q, state, degree, year, paged, per_page
 * @return array{rows:array,total:int,pages:int,paged:int,per_page:int}
 */
function sacscoc_inst_search( array $args = [] ): array {
    global $wpdb;

    $args = wp_parse_args( $args, [
        'q'        => '',
        'state'    => '',
        'degree'   => '',
        'year'     => '',
        'paged'    => 1,
        'per_page' => sacscoc_inst_per_page(),
    ] );

    $empty = [ 'rows' => [], 'total' => 0, 'pages' => 0, 'paged' => 1, 'per_page' => (int) $args['per_page'] ];
    if ( ! sacscoc_inst_tables_ready() ) return $empty;

    $table = sacscoc_inst_table( 'institutions' );
    $where = [];
    $bind  = [];

    // An institution the API has stopped returning stays in the directory. The
    // whole point of the local copy is that an upstream hiccup does not remove
    // institutions from the site; a genuine removal is visible in the admin.
    if ( ! apply_filters( 'sacscoc_inst_show_missing', true ) ) {
        $where[] = 'missing_since IS NULL';
    }

    if ( $args['q'] !== '' ) {
        $like    = '%' . $wpdb->esc_like( $args['q'] ) . '%';
        $where[] = '(name LIKE %s OR sortable_name LIKE %s OR former_names LIKE %s)';
        array_push( $bind, $like, $like, $like );
    }

    if ( $args['state'] !== '' ) {
        if ( $args['state'] === 'INTL' ) {
            // "International" is not a state. The current site's INTL option
            // keys on the country, and returns exactly the 38 institutions
            // whose country is not the United States.
            $where[] = 'address_country IS NOT NULL AND address_country <> %s';
            $bind[]  = 'United States';
        } else {
            $where[] = 'address_state = %s';
            $bind[]  = $args['state'];
        }
    }

    if ( isset( sacscoc_inst_degrees()[ $args['degree'] ] ) ) {
        $where[] = sacscoc_inst_highest_degree_sql( $args['degree'] );
    }

    if ( $args['year'] !== '' && (int) $args['year'] > 0 ) {
        $where[] = 'YEAR(next_reaffirm_date) = %d';
        $bind[]  = (int) $args['year'];
    }

    $where_sql = $where ? implode( ' AND ', $where ) : '1=1';

    $count_sql = "SELECT COUNT(*) FROM $table WHERE $where_sql";
    $total     = (int) $wpdb->get_var( $bind ? $wpdb->prepare( $count_sql, $bind ) : $count_sql );

    $per_page = sacscoc_inst_clamp_per_page( $args['per_page'] );
    $pages    = (int) ceil( $total / $per_page );
    $paged    = max( 1, min( max( 1, $pages ), (int) $args['paged'] ) );
    $offset   = ( $paged - 1 ) * $per_page;

    // Ordered by sortable_name, which is what the current application sorts on
    // and the reason the field exists — it is populated on every record, unlike
    // `name`, and it drops leading articles.
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $table WHERE $where_sql ORDER BY sortable_name ASC, id ASC LIMIT %d OFFSET %d",
            array_merge( $bind, [ $per_page, $offset ] )
        ),
        ARRAY_A
    );

    return [
        'rows'     => (array) $rows,
        'total'    => $total,
        'pages'    => $pages,
        'paged'    => $paged,
        'per_page' => $per_page,
    ];
}

/**
 * SQL for "the highest degree this institution offers is $degree".
 *
 * Contains a deliberate fix for a bug in the API's own filter. The API's version
 * excludes any institution whose `master` field is null rather than the string
 * "No" — a condition written as `master = 'No'` never matches NULL — which
 * silently loses 13 institutions from the associate and baccalaureate results,
 * among them Kentucky College of Art and Design. COALESCE closes that hole, so
 * these results are correct and will not match the current site's counts.
 *
 * `deg_master` is null on 68 records; the other four flags are never null.
 */
function sacscoc_inst_highest_degree_sql( string $degree ): string {
    $levels = array_keys( sacscoc_inst_degrees() );   // highest first
    $index  = array_search( $degree, $levels, true );

    if ( $index === false ) return '1=1';

    // Everything above this level must be absent…
    $clauses = [];
    for ( $i = 0; $i < $index; $i++ ) {
        $clauses[] = sprintf( "COALESCE(deg_%s, 'No') <> 'Yes'", $levels[ $i ] );
    }
    // …and this level must be present.
    $clauses[] = sprintf( "deg_%s = 'Yes'", $degree );

    return '(' . implode( ' AND ', $clauses ) . ')';
}

/** One institution by its slug, for the detail page. */
function sacscoc_inst_get_by_slug( string $slug ): ?array {
    global $wpdb;

    if ( $slug === '' || ! sacscoc_inst_tables_ready() ) return null;

    $table = sacscoc_inst_table( 'institutions' );
    $row   = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM $table WHERE slug = %s", $slug ),
        ARRAY_A
    );

    return $row ?: null;
}

/**
 * One institution by its API numeric id.
 *
 * The id the `[sacscoc_institution]` shortcode takes. It comes from the API, so
 * it survives the local table being dropped and rebuilt — which the surrogate
 * `id` column would not, and which is why that one is never published.
 */
function sacscoc_inst_get_by_api_id( int $api_id ): ?array {
    global $wpdb;

    if ( $api_id <= 0 || ! sacscoc_inst_tables_ready() ) return null;

    $table = sacscoc_inst_table( 'institutions' );
    $row   = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM $table WHERE api_id = %d", $api_id ),
        ARRAY_A
    );

    return $row ?: null;
}

// ──────────────────────────────────────────────
// Presentation helpers
// ──────────────────────────────────────────────
// The conditions here are not invented. Each one was read off the existing
// application, so the rebuilt pages hide and show the same things the current
// ones do.

/**
 * The degrees an institution is approved to offer, lowest to highest.
 *
 * The detail page's "Approved to Offer" list — every flag set to Yes, not just
 * the highest one.
 */
function sacscoc_inst_approved_degrees( array $row ): array {
    $order = array_reverse( sacscoc_inst_degrees(), true );
    $out   = [];

    foreach ( $order as $key => $label ) {
        if ( ( $row[ 'deg_' . $key ] ?? null ) === 'Yes' ) $out[ $key ] = $label;
    }

    return $out;
}

/**
 * The sanction to display, or null when there is none.
 *
 * The API uses the literal string "No Sanction" to mean "none", so an
 * institution with that value must read as unsanctioned, not as carrying a
 * sanction called "No Sanction".
 */
function sacscoc_inst_sanction( array $row ): ?string {
    $value = sacscoc_inst_parse_text( $row['sanctions'] ?? null );
    if ( $value === null || strcasecmp( $value, 'No Sanction' ) === 0 ) return null;
    return $value;
}

/**
 * True when the SACSCOC staff block should be shown.
 *
 * The current site hides it for the three "Former …" statuses: an institution
 * that left the Commission has no assigned staff member to contact.
 */
function sacscoc_inst_has_staff_contact( array $row ): bool {
    $status = (string) ( $row['accreditation_status'] ?? '' );
    return ! in_array( $status, [ 'Former Accredited', 'Former Applicant', 'Former Candidate' ], true );
}

/**
 * True when the student achievement link should be shown.
 *
 * Gated on status in the current site — the data is only meaningful for an
 * institution currently accredited or in candidacy.
 */
function sacscoc_inst_shows_achievement( array $row ): bool {
    if ( sacscoc_inst_parse_text( $row['student_achievement_url'] ?? null ) === null ) return false;
    return in_array( (string) ( $row['accreditation_status'] ?? '' ), [ 'Accredited', 'Candidate' ], true );
}

/**
 * The accreditation history, split into the lines the current site tabulates.
 *
 * One free-text block in the API, up to ~1,500 characters, present on 401
 * records.
 */
function sacscoc_inst_history_lines( array $row ): array {
    $history = sacscoc_inst_parse_text( $row['accreditation_history'] ?? null );
    if ( $history === null ) return [];

    $lines = preg_split( '/\r\n|\r|\n/', $history );
    $lines = array_map( 'trim', (array) $lines );

    return array_values( array_filter( $lines, static fn( $line ) => $line !== '' ) );
}

/** A full date in the site's format, or null. Dates are stored as plain dates. */
function sacscoc_inst_date( ?string $date ): ?string {
    if ( ! $date || $date === '0000-00-00' ) return null;

    $timestamp = strtotime( $date . ' 00:00:00 UTC' );
    return $timestamp ? wp_date( get_option( 'date_format' ), $timestamp, new DateTimeZone( 'UTC' ) ) : null;
}

/** Just the year, which is how the reaffirmation dates are shown. */
function sacscoc_inst_year( ?string $date ): ?string {
    if ( ! $date || $date === '0000-00-00' ) return null;
    $year = substr( $date, 0, 4 );
    return $year !== '0000' ? $year : null;
}

/** The permalink for one institution. */
function sacscoc_inst_permalink( array $row ): string {
    $slug = (string) ( $row['slug'] ?? '' );
    if ( $slug === '' ) return '';

    return home_url( '/' . trailingslashit( sacscoc_inst_rewrite_base() ) . $slug . '/' );
}
