<?php
/**
 * The API → local field map, in one place.
 *
 * Every field the API returns for an institution has a column here. Nothing is
 * dropped for being unused today: the frontend of the existing directory does
 * not show `sf_owner_id` or `created_at` either, but they are part of the
 * institution record and Cirlot asked for all of it. What the columns are for
 * is documented field by field in docs/API-FIELD-MAP.md.
 *
 * This map is the single source of truth for the writer (includes/sync.php),
 * the reader (includes/repository.php) and the Documentation screen. Adding a
 * field the API starts returning means adding one line here and one column in
 * includes/schema.php — and until then the value is still not lost, because
 * every record's untouched JSON is kept in `raw_json`.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * API field name => [ local column, cast ].
 *
 * The cast decides how the raw JSON value becomes a column value:
 *   text     — string, trimmed; '' becomes NULL
 *   date     — 'YYYY-MM-DD'; anything unparseable becomes NULL
 *   datetime — ISO-8601 from the API, stored as UTC 'YYYY-MM-DD HH:MM:SS'
 *   int      — integer, NULL when absent
 *
 * The five `deg_*` columns are renamed from their bare API names (`associate`,
 * `master`, …) because a column called `master` in a table of institutions
 * reads like a mistake, and because the group is what the frontend's "Approved
 * to Offer" list and the Highest Degree filter act on together.
 */
function sacscoc_inst_field_map(): array {
    return [
        // Identity. `sf_id` is the stable Salesforce id and the key everything
        // is matched on — never the name, which is neither unique (three
        // records are called "Bevill State Community College") nor always
        // present (nineteen records have no `name` at all).
        'sf_id'                        => [ 'sf_id', 'text' ],
        'id'                           => [ 'api_id', 'int' ],
        'sf_owner_id'                  => [ 'sf_owner_id', 'text' ],

        // Names and public links.
        'name'                         => [ 'name', 'text' ],
        'sortable_name'                => [ 'sortable_name', 'text' ],
        'former_names'                 => [ 'former_names', 'text' ],
        'phone'                        => [ 'phone', 'text' ],
        'website'                      => [ 'website', 'text' ],
        'ceo_name'                     => [ 'ceo_name', 'text' ],
        'program_list'                 => [ 'program_list', 'text' ],
        'student_achievement_url'      => [ 'student_achievement_url', 'text' ],
        'general_disclosure_url'       => [ 'general_disclosure_url', 'text' ],

        // Degrees approved to offer. 'Yes' / 'No' in the API.
        'associate'                    => [ 'deg_associate', 'text' ],
        'baccalaureate'                => [ 'deg_baccalaureate', 'text' ],
        'master'                       => [ 'deg_master', 'text' ],
        'education_specialist'         => [ 'deg_education_specialist', 'text' ],
        'doctorate'                    => [ 'deg_doctorate', 'text' ],

        // Accreditation.
        'accreditation_status'         => [ 'accreditation_status', 'text' ],
        'sort__accreditation_status'   => [ 'sort_accreditation_status', 'int' ],
        'level'                        => [ 'level', 'text' ],
        'control'                      => [ 'control', 'text' ],
        'sanctions'                    => [ 'sanctions', 'text' ],
        'accreditation_history'        => [ 'accreditation_history', 'text' ],

        // Dates in the accreditation cycle.
        'candidacy_date'               => [ 'candidacy_date', 'date' ],
        'accreditation_date'           => [ 'accreditation_date', 'date' ],
        'reaffirmed_date'              => [ 'reaffirmed_date', 'date' ],
        'next_reaffirm_date'           => [ 'next_reaffirm_date', 'date' ],
        'fifth_year_date'              => [ 'fifth_year_date', 'date' ],
        'distance_learning_approved'   => [ 'distance_learning_approved', 'date' ],
        'course_credit_based_approved' => [ 'course_credit_based_approved', 'date' ],

        // Address.
        'address_street'               => [ 'address_street', 'text' ],
        'address_city'                 => [ 'address_city', 'text' ],
        'address_state'                => [ 'address_state', 'text' ],
        'address_zip'                  => [ 'address_zip', 'text' ],
        'address_country'              => [ 'address_country', 'text' ],

        // The SACSCOC staff member assigned to the institution.
        'contact_first_name'           => [ 'contact_first_name', 'text' ],
        'contact_last_name'            => [ 'contact_last_name', 'text' ],
        'contact_email'                => [ 'contact_email', 'text' ],
        'contact_phone'                => [ 'contact_phone', 'text' ],

        // The API's own bookkeeping. Kept so a record's history is auditable
        // locally, and so a future incremental sync has something to ask for.
        'delete_flag'                  => [ 'delete_flag', 'int' ],
        'created_at'                   => [ 'api_created_at', 'datetime' ],
        'updated_at'                   => [ 'api_updated_at', 'datetime' ],
        'deleted_at'                   => [ 'api_deleted_at', 'datetime' ],
    ];
}

/**
 * Fields excluded from the change-detection hash.
 *
 * `updated_at` cannot be used to detect change: the API rewrites it on every
 * institution on every refresh — the whole dataset shares a single minute of
 * `updated_at` values, clustered a few seconds apart. Hashing it would make
 * every institution look changed on every sync, which is exactly the pointless
 * rewriting the hash exists to avoid. So the hash covers the institution's
 * actual data, and `updated_at` rides along as a stored value only.
 */
const SACSCOC_INST_HASH_EXCLUDE = [ 'created_at', 'updated_at' ];

/**
 * A stable fingerprint of a record's data, given the fields to leave out.
 *
 * Keys are sorted so the API reordering its JSON does not read as a change,
 * and the excluded fields are removed before hashing. Two records with the
 * same hash carry the same information and the local row is left untouched.
 */
function sacscoc_inst_hash_record( array $record, array $exclude ): string {
    foreach ( $exclude as $key ) {
        unset( $record[ $key ] );
    }
    ksort( $record );
    return sha1( (string) wp_json_encode( $record ) );
}

/** A stable fingerprint of one institution's data. */
function sacscoc_inst_content_hash( array $record ): string {
    return sacscoc_inst_hash_record( $record, SACSCOC_INST_HASH_EXCLUDE );
}

/**
 * Turn one API record into a row of column => value, cast per a field map.
 *
 * A field absent from the record is left out of the result rather than mapped
 * to NULL, so the writer can tell "the API stopped sending this" apart from
 * "the API sent an empty value", and a truncated record cannot quietly blank
 * a column.
 */
function sacscoc_inst_apply_field_map( array $record, array $map ): array {
    $row = [];

    foreach ( $map as $api_field => [ $column, $cast ] ) {
        if ( ! array_key_exists( $api_field, $record ) ) continue;

        $row[ $column ] = match ( $cast ) {
            'int'      => is_numeric( $record[ $api_field ] ) ? (int) $record[ $api_field ] : null,
            'date'     => sacscoc_inst_parse_date( $record[ $api_field ] ),
            'datetime' => sacscoc_inst_parse_datetime( $record[ $api_field ] ),
            default    => sacscoc_inst_parse_text( $record[ $api_field ] ),
        };
    }

    return $row;
}

/**
 * Every record the API sends for an institution carries all forty-three
 * fields — verified across the full dataset, where not one record omits a
 * key — so sacscoc_inst_apply_field_map()'s "absent means the API stopped
 * sending it" distinction is safe to rely on here.
 */
function sacscoc_inst_map_record( array $record ): array {
    return sacscoc_inst_apply_field_map( $record, sacscoc_inst_field_map() );
}

// ──────────────────────────────────────────────
// Off-campus instructional sites — /api/v1/sites?sf_institution_id=…
// ──────────────────────────────────────────────

/** API field name => [ local column, cast ]. See docs/API-FIELD-MAP.md. */
function sacscoc_inst_site_field_map(): array {
    return [
        'sf_id'             => [ 'sf_id', 'text' ],
        'id'                => [ 'api_id', 'int' ],
        'sf_institution_id' => [ 'sf_institution_id', 'text' ],
        'name'              => [ 'name', 'text' ],
        'status'            => [ 'status', 'text' ],
        'type'              => [ 'type', 'text' ],
        'street'            => [ 'street', 'text' ],
        'city'              => [ 'city', 'text' ],
        'state'             => [ 'state', 'text' ],
        'zip'               => [ 'zip', 'text' ],
        'country'           => [ 'country', 'text' ],
        'created_at'        => [ 'api_created_at', 'datetime' ],
        'updated_at'        => [ 'api_updated_at', 'datetime' ],
        'deleted_at'        => [ 'api_deleted_at', 'datetime' ],
    ];
}

function sacscoc_inst_map_site_record( array $record ): array {
    return sacscoc_inst_apply_field_map( $record, sacscoc_inst_site_field_map() );
}

/** Same reasoning as SACSCOC_INST_HASH_EXCLUDE: timestamps the API rewrites every refresh. */
function sacscoc_inst_site_content_hash( array $record ): string {
    return sacscoc_inst_hash_record( $record, [ 'created_at', 'updated_at' ] );
}

// ──────────────────────────────────────────────
// Reviews / meetings — /api/v1/recentmeetings, /api/v1/inprogressmeetings
// ──────────────────────────────────────────────

/**
 * API field name => [ local column, cast ].
 *
 * `original_data` is deliberately not mapped: it carries the entire raw
 * Salesforce Committee_Review__c record (10–16 KB, none of it public-facing)
 * and is dropped before this ever sees the record — see
 * sacscoc_inst_prepare_meeting_record() in includes/sync.php.
 */
function sacscoc_inst_meeting_field_map(): array {
    return [
        'id'                     => [ 'api_id', 'int' ],
        'sf_institution_id'      => [ 'sf_institution_id', 'text' ],
        'sf_meeting_id'          => [ 'sf_meeting_id', 'text' ],
        'sf_committee_review_id' => [ 'sf_committee_review_id', 'text' ],
        'name'                   => [ 'name', 'text' ],
        'description'            => [ 'description', 'text' ],
        'stage'                  => [ 'stage', 'text' ],
        'action_date'            => [ 'action_date', 'date' ],
        'end_date'               => [ 'end_date', 'text' ],
        'created_at'             => [ 'api_created_at', 'datetime' ],
        'updated_at'             => [ 'api_updated_at', 'datetime' ],
        'deleted_at'             => [ 'api_deleted_at', 'datetime' ],
    ];
}

function sacscoc_inst_map_meeting_record( array $record ): array {
    return sacscoc_inst_apply_field_map( $record, sacscoc_inst_meeting_field_map() );
}

function sacscoc_inst_meeting_content_hash( array $record ): string {
    return sacscoc_inst_hash_record( $record, [ 'created_at', 'updated_at' ] );
}

/**
 * What the frontend shows next to a meeting's name: `end_date` when the API
 * sent one, otherwise the year of `action_date`. Matches the existing
 * production directory, per docs/API-FIELD-MAP.md.
 */
function sacscoc_inst_meeting_display_year( array $record ): ?string {
    $end = sacscoc_inst_parse_text( $record['end_date'] ?? null );
    if ( $end !== null ) return $end;

    $action = sacscoc_inst_parse_text( $record['action_date'] ?? null );
    if ( $action === null ) return null;

    $ts = strtotime( $action );
    return $ts === false ? null : gmdate( 'Y', $ts );
}

/** Trimmed string, or NULL for anything that is not usable text. */
function sacscoc_inst_parse_text( $value ): ?string {
    if ( ! is_scalar( $value ) ) return null;
    $value = trim( (string) $value );
    return $value === '' ? null : $value;
}

/** 'YYYY-MM-DD', or NULL. The API sends plain dates here, never times. */
function sacscoc_inst_parse_date( $value ): ?string {
    $value = sacscoc_inst_parse_text( $value );
    if ( $value === null ) return null;

    $ts = strtotime( $value );
    return $ts === false ? null : gmdate( 'Y-m-d', $ts );
}

/** UTC 'YYYY-MM-DD HH:MM:SS', or NULL. The API sends ISO-8601 with a Z. */
function sacscoc_inst_parse_datetime( $value ): ?string {
    $value = sacscoc_inst_parse_text( $value );
    if ( $value === null ) return null;

    $ts = strtotime( $value );
    return $ts === false ? null : gmdate( 'Y-m-d H:i:s', $ts );
}

/**
 * The name to show for an institution, and the basis for its slug.
 *
 * Nineteen records have no `name`. All of them do have a `sortable_name`, so
 * that is the fallback; `sf_id` is the last resort, so that a record can never
 * end up with no label and no URL at all.
 */
function sacscoc_inst_display_name( array $row ): string {
    foreach ( [ 'name', 'sortable_name' ] as $key ) {
        $value = sacscoc_inst_parse_text( $row[ $key ] ?? null );
        if ( $value !== null ) return $value;
    }
    return (string) ( $row['sf_id'] ?? '' );
}

/**
 * What the existing directory does with each field.
 *
 * The third column of the API → Local → Frontend map Cirlot asked for. It was
 * read off the live application: the markup of https://sacscoc.org/institutions/
 * and the Vue bundle behind it (institution-app.bundle.js), which is where the
 * conditions live — which fields gate a block, which are shown as a year rather
 * than a date, which sentinel value means "nothing to show".
 *
 * "—" means the field is not displayed today. Those are kept anyway: Cirlot's
 * requirement is that no field is dropped for being unused in the new design.
 */
function sacscoc_inst_field_usage(): array {
    return [
        'sf_id'                        => __( 'Not displayed. The key the detail, sites and meetings endpoints are queried by, and the key this plugin matches records on.', 'sacscoc-institutions' ),
        'id'                           => __( '—  The API\'s own numeric id. Kept for cross-referencing against the API.', 'sacscoc-institutions' ),
        'sf_owner_id'                  => __( '—  Salesforce owner of the record.', 'sacscoc-institutions' ),
        'name'                         => __( 'Result title and detail heading. The Institution Name search matches on it.', 'sacscoc-institutions' ),
        'sortable_name'                => __( 'Not displayed. Sort order of the result list.', 'sacscoc-institutions' ),
        'former_names'                 => __( 'Result list "Former Name:" line, and the "Former Name" note under the detail heading.', 'sacscoc-institutions' ),
        'phone'                        => __( 'Detail → General Information → "Institutional Phone".', 'sacscoc-institutions' ),
        'website'                      => __( 'Result list "View Website" link.', 'sacscoc-institutions' ),
        'ceo_name'                     => __( 'Detail → General Information → "CEO Name". The row is hidden when empty.', 'sacscoc-institutions' ),
        'program_list'                 => __( 'Detail → "View Available Programs" link.', 'sacscoc-institutions' ),
        'student_achievement_url'      => __( 'Detail → "View Student Achievement Data" link — shown only when the status is Accredited or Candidate.', 'sacscoc-institutions' ),
        'general_disclosure_url'       => __( 'Detail → the "Accreditation Actions & Disclosure Statements" link beside a public sanction.', 'sacscoc-institutions' ),
        'associate'                    => __( 'Detail → "Approved to Offer" list. Also the Highest Degree Offered filter (degree=associate).', 'sacscoc-institutions' ),
        'baccalaureate'                => __( 'Detail → "Approved to Offer" list. Also the Highest Degree Offered filter (degree=baccalaureate).', 'sacscoc-institutions' ),
        'master'                       => __( 'Detail → "Approved to Offer" list. Also the Highest Degree Offered filter (degree=master).', 'sacscoc-institutions' ),
        'education_specialist'         => __( 'Detail → "Approved to Offer" list. Also the Highest Degree Offered filter (degree=education_specialist).', 'sacscoc-institutions' ),
        'doctorate'                    => __( 'Detail → "Approved to Offer" list. Also the Highest Degree Offered filter (degree=doctorate).', 'sacscoc-institutions' ),
        'accreditation_status'         => __( 'Result list "Status" and detail → "Status". Also gates two blocks: the SACSCOC Staff Member block is hidden for the three "Former …" statuses, and the student achievement link needs Accredited or Candidate.', 'sacscoc-institutions' ),
        'sort__accreditation_status'   => __( '—  A numeric rank for the status, for ordering.', 'sacscoc-institutions' ),
        'level'                        => __( 'Result list "Level" and detail → "Degree Level", each with a tooltip naming the highest degree that level offers (I = Associate … VI = Doctorate, 4 or more).', 'sacscoc-institutions' ),
        'control'                      => __( 'Detail → "Control" (Public / Private, Not-For-Profit / Private, For-Profit).', 'sacscoc-institutions' ),
        'sanctions'                    => __( 'Result list and detail → "Public Sanctions", shown in red. The literal value "No Sanction" means there is none and is treated as empty.', 'sacscoc-institutions' ),
        'accreditation_history'        => __( 'Detail → the collapsed "View Full Accreditation History" table. One free-text block, split into lines for the table.', 'sacscoc-institutions' ),
        'candidacy_date'               => __( 'Detail → "Candidacy Date", as a full date.', 'sacscoc-institutions' ),
        'accreditation_date'           => __( 'Detail → "Accreditation Granted", as a full date.', 'sacscoc-institutions' ),
        'reaffirmed_date'              => __( 'Detail → "Reaffirmation" — the year only.', 'sacscoc-institutions' ),
        'next_reaffirm_date'           => __( 'Detail → "Next Reaffirmation" — the year only. Also the Next Reaffirmation Year filter, which matches on the year.', 'sacscoc-institutions' ),
        'fifth_year_date'              => __( 'Detail → "Next Fifth-Year Review" — the year only.', 'sacscoc-institutions' ),
        'distance_learning_approved'   => __( 'Detail → "Distance Education Approval Date", as a full date.', 'sacscoc-institutions' ),
        'course_credit_based_approved' => __( 'Detail → "CBE Course/Credit-based Approved", as a full date.', 'sacscoc-institutions' ),
        'address_street'               => __( 'Detail → General Information → the address block.', 'sacscoc-institutions' ),
        'address_city'                 => __( 'Result list "City" and the detail address block.', 'sacscoc-institutions' ),
        'address_state'                => __( 'Result list "State" and the detail address block. Also the State filter — where the "International" option is not a state at all but everything whose country is not the United States.', 'sacscoc-institutions' ),
        'address_zip'                  => __( 'Result list "ZIP" and the detail address block.', 'sacscoc-institutions' ),
        'address_country'              => __( 'Result list "Country" and detail → "Country". What the State filter\'s "International" option actually keys on.', 'sacscoc-institutions' ),
        'contact_first_name'           => __( 'Detail → "SACSCOC Staff Member" name.', 'sacscoc-institutions' ),
        'contact_last_name'            => __( 'Detail → "SACSCOC Staff Member" name.', 'sacscoc-institutions' ),
        'contact_email'                => __( 'Detail → "SACSCOC Staff Member" — the Email link (mailto:).', 'sacscoc-institutions' ),
        'contact_phone'                => __( 'Detail → "SACSCOC Staff Member" — the phone link (tel:). Absent for most institutions.', 'sacscoc-institutions' ),
        'delete_flag'                  => __( '—  Soft-delete marker. 0 for every record in the current dataset.', 'sacscoc-institutions' ),
        'created_at'                   => __( '—  When the API created the record.', 'sacscoc-institutions' ),
        'updated_at'                   => __( '—  When the API last touched the record. Not usable for change detection: the API rewrites it on every record on every refresh.', 'sacscoc-institutions' ),
        'deleted_at'                   => __( '—  Soft-delete timestamp. Null for every record in the current dataset.', 'sacscoc-institutions' ),
    ];
}
