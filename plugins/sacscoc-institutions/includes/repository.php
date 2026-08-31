<?php
/**
 * Reading and writing the institutions table.
 *
 * Every statement that touches the table lives here, so the sync engine can be
 * read as what it decides rather than how it writes. Two things in this file
 * are load-bearing for the sync:
 *
 *   sacscoc_inst_hash_index()  — one query that returns every local row's
 *                                fingerprint, so the sync can classify 1,201
 *                                records as created / updated / unchanged
 *                                without reading a single row's data.
 *
 *   sacscoc_inst_write_batch() — a chunked INSERT … ON DUPLICATE KEY UPDATE,
 *                                so a first sync of the whole directory is a
 *                                dozen statements rather than 1,201.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Local columns the sync writes, and how each one is quoted.
 *
 * Derived from the field map so the two can never disagree, plus the local
 * bookkeeping columns that have no API counterpart.
 */
function sacscoc_inst_writable_columns(): array {
    static $columns = null;
    if ( $columns !== null ) return $columns;

    // Every column that carries an API value. Only three of them are numeric;
    // dates and datetimes are quoted like any other string, which is what
    // MySQL expects for a DATE literal.
    $integers = [ 'api_id', 'sort_accreditation_status', 'delete_flag' ];

    $columns = [];
    foreach ( sacscoc_inst_field_map() as [ $column ] ) {
        $columns[ $column ] = in_array( $column, $integers, true ) ? 'int' : 'text';
    }

    // Local bookkeeping.
    $columns['slug']          = 'text';
    $columns['raw_json']      = 'text';
    $columns['content_hash']  = 'text';
    $columns['first_seen']    = 'text';
    $columns['last_seen']     = 'text';
    $columns['last_synced']   = 'text';
    $columns['missing_since'] = 'text';

    return $columns;
}

/**
 * Columns rewritten when a row already exists.
 *
 * `slug` and `first_seen` are excluded deliberately. A slug is an institution's
 * public URL: once assigned it must survive the institution being renamed, or
 * every link to it breaks. `first_seen` records when we first saw the record
 * and is not ours to revise.
 */
function sacscoc_inst_updatable_columns(): array {
    $columns = sacscoc_inst_writable_columns();
    unset( $columns['slug'], $columns['first_seen'] );
    return array_keys( $columns );
}

/** One value as a safe SQL literal, with real NULLs rather than empty strings. */
function sacscoc_inst_sql_literal( $value, string $type ): string {
    global $wpdb;

    if ( $value === null ) return 'NULL';
    if ( $type === 'int' ) return (string) (int) $value;

    return $wpdb->prepare( '%s', (string) $value );
}

/**
 * Every local row's identity and fingerprint, keyed by sf_id.
 *
 * Deliberately does not select the data columns: this runs on every sync and
 * only needs enough to answer "is this record new, changed, or the same?".
 *
 * @return array<string,array{id:int,content_hash:string,slug:string}>
 */
function sacscoc_inst_hash_index(): array {
    global $wpdb;
    $table = sacscoc_inst_table( 'institutions' );

    $rows  = $wpdb->get_results( "SELECT id, sf_id, content_hash, slug FROM $table", ARRAY_A );
    $index = [];

    foreach ( (array) $rows as $row ) {
        $index[ $row['sf_id'] ] = [
            'id'           => (int) $row['id'],
            'content_hash' => (string) $row['content_hash'],
            'slug'         => (string) $row['slug'],
        ];
    }

    return $index;
}

/**
 * Write a batch of rows, inserting the new and rewriting the changed.
 *
 * Rows arrive as column => value maps and must all carry `sf_id`. Chunked at
 * 100, which keeps each statement comfortably inside max_allowed_packet even
 * though each row carries its ~1.4 KB of raw_json.
 *
 * @return int rows affected as reported by MySQL (2 per updated row, 1 per insert)
 */
function sacscoc_inst_write_batch( array $rows ): int {
    global $wpdb;

    if ( ! $rows ) return 0;

    $table    = sacscoc_inst_table( 'institutions' );
    $types    = sacscoc_inst_writable_columns();
    $columns  = array_keys( $types );
    $affected = 0;

    // ON DUPLICATE KEY UPDATE col = VALUES(col), for the columns that may be
    // rewritten. `missing_since` is in that list, which is what brings an
    // institution back into the directory when the API starts sending it again.
    $assignments = [];
    foreach ( sacscoc_inst_updatable_columns() as $column ) {
        $assignments[] = "`$column` = VALUES(`$column`)";
    }

    $column_list = '`' . implode( '`, `', $columns ) . '`';
    $assign_list = implode( ', ', $assignments );

    foreach ( array_chunk( $rows, 100 ) as $chunk ) {
        $tuples = [];

        foreach ( $chunk as $row ) {
            $values = [];
            foreach ( $columns as $column ) {
                $values[] = sacscoc_inst_sql_literal( $row[ $column ] ?? null, $types[ $column ] );
            }
            $tuples[] = '(' . implode( ', ', $values ) . ')';
        }

        // Every value went through sacscoc_inst_sql_literal(), which quotes and
        // escapes through $wpdb — there is no unescaped input in this string.
        $sql = "INSERT INTO $table ($column_list) VALUES "
             . implode( ', ', $tuples )
             . " ON DUPLICATE KEY UPDATE $assign_list";

        $result = $wpdb->query( $sql );

        if ( $result === false ) {
            // Let the sync decide what to do; it must not report success.
            throw new RuntimeException(
                sprintf( 'Database write failed: %s', $wpdb->last_error ?: 'unknown error' )
            );
        }

        $affected += (int) $result;
    }

    return $affected;
}

/**
 * Mark every institution the API just sent as present.
 *
 * Two statements rather than a row-by-row pass, and the second one is the whole
 * of the plugin's delete policy: an institution the API stops sending is
 * *marked*, with the date it went missing, and never removed. Its data, its
 * slug and its URL all survive. If the API sends it again, the
 * `missing_since = NULL` in sacscoc_inst_write_batch() clears the mark.
 *
 * @param string[] $present sf_ids present in this sync's payload
 * @return int institutions newly marked missing
 */
function sacscoc_inst_mark_presence( array $present ): int {
    global $wpdb;

    $table = sacscoc_inst_table( 'institutions' );
    $now   = current_time( 'mysql', true );

    if ( ! $present ) return 0;

    $placeholders = implode( ', ', array_fill( 0, count( $present ), '%s' ) );

    // Seen this round: clear any missing mark and stamp last_seen. This does
    // not touch the data columns, so an unchanged institution's record is still
    // left exactly as it was.
    $wpdb->query( $wpdb->prepare(
        "UPDATE $table SET last_seen = %s, missing_since = NULL WHERE sf_id IN ($placeholders)",
        array_merge( [ $now ], $present )
    ) );

    // Not seen this round, and not already marked.
    $missing = $wpdb->query( $wpdb->prepare(
        "UPDATE $table SET missing_since = %s
          WHERE missing_since IS NULL AND sf_id NOT IN ($placeholders)",
        array_merge( [ $now ], $present )
    ) );

    return max( 0, (int) $missing );
}

/**
 * A slug that is unique in the table, derived from the institution's name.
 *
 * Names are not unique — three institutions are called "Bevill State Community
 * College" — so collisions are normal and are resolved with a numeric suffix.
 * `$taken` is passed by reference and updated, so a single sync assigning
 * hundreds of slugs at once does not need a query per slug.
 *
 * @param array<string,true> $taken slugs already claimed, by reference
 */
function sacscoc_inst_unique_slug( string $name, string $sf_id, array &$taken ): string {
    $base = sanitize_title( $name );

    // A name of only punctuation, or an empty one, sanitizes to nothing. The
    // sf_id is unique by definition, so it makes a usable last-resort slug.
    if ( $base === '' ) {
        $base = 'institution-' . strtolower( $sf_id );
    }
    $base = substr( $base, 0, 180 );

    $slug = $base;
    $n    = 2;
    while ( isset( $taken[ $slug ] ) ) {
        $slug = $base . '-' . $n;
        $n++;
    }

    $taken[ $slug ] = true;
    return $slug;
}

// ──────────────────────────────────────────────
// Off-campus instructional sites and reviews/meetings
//
// Unlike the institutions table, these two have no single bulk endpoint: the
// API only answers "sites/meetings for this one sf_institution_id", so they
// are synced institution by institution (see sacscoc_inst_sync_related_batch()
// in includes/sync.php) rather than all at once. Every write and presence
// check below is scoped to one institution's sf_id for the same reason: a
// batch that only fetched 40 institutions this tick must never mark the other
// 1,161 institutions' sites as missing.
// ──────────────────────────────────────────────

/** Columns sacscoc_institution_sites writes, and how each is quoted. */
function sacscoc_inst_site_writable_columns(): array {
    static $columns = null;
    if ( $columns !== null ) return $columns;

    $columns = [];
    foreach ( sacscoc_inst_site_field_map() as [ $column ] ) {
        $columns[ $column ] = $column === 'api_id' ? 'int' : 'text';
    }
    $columns['raw_json']      = 'text';
    $columns['content_hash']  = 'text';
    $columns['last_synced']   = 'text';
    $columns['missing_since'] = 'text';

    return $columns;
}

/** Every local site row for one institution, keyed by sf_id — for change detection. */
function sacscoc_inst_site_hash_index( string $sf_institution_id ): array {
    global $wpdb;
    $table = sacscoc_inst_table( 'institution_sites' );

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT sf_id, content_hash FROM $table WHERE sf_institution_id = %s", $sf_institution_id
    ), ARRAY_A );

    $index = [];
    foreach ( (array) $rows as $row ) {
        $index[ $row['sf_id'] ] = (string) $row['content_hash'];
    }
    return $index;
}

/** Insert the new sites and rewrite the changed ones. See sacscoc_inst_write_batch(). */
function sacscoc_inst_write_sites_batch( array $rows ): int {
    global $wpdb;
    if ( ! $rows ) return 0;

    $table   = sacscoc_inst_table( 'institution_sites' );
    $types   = sacscoc_inst_site_writable_columns();
    $columns = array_keys( $types );

    $assignments = [];
    foreach ( $columns as $column ) {
        if ( $column === 'sf_id' ) continue;
        $assignments[] = "`$column` = VALUES(`$column`)";
    }

    $column_list = '`' . implode( '`, `', $columns ) . '`';
    $assign_list = implode( ', ', $assignments );
    $affected    = 0;

    foreach ( array_chunk( $rows, 100 ) as $chunk ) {
        $tuples = [];
        foreach ( $chunk as $row ) {
            $values = [];
            foreach ( $columns as $column ) {
                $values[] = sacscoc_inst_sql_literal( $row[ $column ] ?? null, $types[ $column ] );
            }
            $tuples[] = '(' . implode( ', ', $values ) . ')';
        }

        $sql = "INSERT INTO $table ($column_list) VALUES "
             . implode( ', ', $tuples )
             . " ON DUPLICATE KEY UPDATE $assign_list";

        $result = $wpdb->query( $sql );
        if ( $result === false ) {
            throw new RuntimeException( sprintf( 'Database write failed: %s', $wpdb->last_error ?: 'unknown error' ) );
        }
        $affected += (int) $result;
    }

    return $affected;
}

/**
 * Mark sites the API stopped returning for this one institution, and clear the
 * mark on any that came back. Never deletes — same policy as
 * sacscoc_inst_mark_presence().
 */
function sacscoc_inst_mark_sites_presence( string $sf_institution_id, array $present_sf_ids ): int {
    global $wpdb;
    $table = sacscoc_inst_table( 'institution_sites' );
    $now   = current_time( 'mysql', true );

    if ( ! $present_sf_ids ) {
        return (int) $wpdb->query( $wpdb->prepare(
            "UPDATE $table SET missing_since = %s WHERE sf_institution_id = %s AND missing_since IS NULL",
            $now, $sf_institution_id
        ) );
    }

    $placeholders = implode( ', ', array_fill( 0, count( $present_sf_ids ), '%s' ) );

    $wpdb->query( $wpdb->prepare(
        "UPDATE $table SET missing_since = NULL WHERE sf_institution_id = %s AND sf_id IN ($placeholders)",
        array_merge( [ $sf_institution_id ], $present_sf_ids )
    ) );

    return (int) $wpdb->query( $wpdb->prepare(
        "UPDATE $table SET missing_since = %s
          WHERE sf_institution_id = %s AND missing_since IS NULL AND sf_id NOT IN ($placeholders)",
        array_merge( [ $now, $sf_institution_id ], $present_sf_ids )
    ) );
}

/**
 * The open off-campus sites shown on an institution's page, alphabetically.
 * Closed sites are stored (the API sends them) but never displayed — the
 * production site's own rule, in the sites legend.
 */
function sacscoc_inst_sites_for_institution( string $sf_institution_id ): array {
    global $wpdb;
    $table = sacscoc_inst_table( 'institution_sites' );

    return (array) $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $table
          WHERE sf_institution_id = %s AND missing_since IS NULL AND status = 'Open'
          ORDER BY name ASC",
        $sf_institution_id
    ), ARRAY_A );
}

/** Columns sacscoc_institution_meetings writes, and how each is quoted. */
function sacscoc_inst_meeting_writable_columns(): array {
    static $columns = null;
    if ( $columns !== null ) return $columns;

    $columns = [];
    foreach ( sacscoc_inst_meeting_field_map() as [ $column ] ) {
        $columns[ $column ] = $column === 'api_id' ? 'int' : 'text';
    }
    // Local bookkeeping: not from the API map, see includes/fields.php.
    $columns['kind']          = 'text';
    $columns['display_year']  = 'text';
    $columns['raw_json']      = 'text';
    $columns['content_hash']  = 'text';
    $columns['last_synced']   = 'text';
    $columns['missing_since'] = 'text';

    return $columns;
}

/** Every local meeting row for one institution and kind, keyed by api_id. */
function sacscoc_inst_meeting_hash_index( string $sf_institution_id, string $kind ): array {
    global $wpdb;
    $table = sacscoc_inst_table( 'institution_meetings' );

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT api_id, content_hash FROM $table WHERE sf_institution_id = %s AND kind = %s",
        $sf_institution_id, $kind
    ), ARRAY_A );

    $index = [];
    foreach ( (array) $rows as $row ) {
        $index[ (int) $row['api_id'] ] = (string) $row['content_hash'];
    }
    return $index;
}

/** Insert the new meetings and rewrite the changed ones. See sacscoc_inst_write_batch(). */
function sacscoc_inst_write_meetings_batch( array $rows ): int {
    global $wpdb;
    if ( ! $rows ) return 0;

    $table   = sacscoc_inst_table( 'institution_meetings' );
    $types   = sacscoc_inst_meeting_writable_columns();
    $columns = array_keys( $types );

    $assignments = [];
    foreach ( $columns as $column ) {
        if ( $column === 'api_id' || $column === 'kind' ) continue;
        $assignments[] = "`$column` = VALUES(`$column`)";
    }

    $column_list = '`' . implode( '`, `', $columns ) . '`';
    $assign_list = implode( ', ', $assignments );
    $affected    = 0;

    foreach ( array_chunk( $rows, 100 ) as $chunk ) {
        $tuples = [];
        foreach ( $chunk as $row ) {
            $values = [];
            foreach ( $columns as $column ) {
                $values[] = sacscoc_inst_sql_literal( $row[ $column ] ?? null, $types[ $column ] );
            }
            $tuples[] = '(' . implode( ', ', $values ) . ')';
        }

        $sql = "INSERT INTO $table ($column_list) VALUES "
             . implode( ', ', $tuples )
             . " ON DUPLICATE KEY UPDATE $assign_list";

        $result = $wpdb->query( $sql );
        if ( $result === false ) {
            throw new RuntimeException( sprintf( 'Database write failed: %s', $wpdb->last_error ?: 'unknown error' ) );
        }
        $affected += (int) $result;
    }

    return $affected;
}

/** Mark meetings of one kind the API stopped returning for this institution. Never deletes. */
function sacscoc_inst_mark_meetings_presence( string $sf_institution_id, string $kind, array $present_api_ids ): int {
    global $wpdb;
    $table = sacscoc_inst_table( 'institution_meetings' );
    $now   = current_time( 'mysql', true );

    if ( ! $present_api_ids ) {
        return (int) $wpdb->query( $wpdb->prepare(
            "UPDATE $table SET missing_since = %s WHERE sf_institution_id = %s AND kind = %s AND missing_since IS NULL",
            $now, $sf_institution_id, $kind
        ) );
    }

    $placeholders = implode( ', ', array_fill( 0, count( $present_api_ids ), '%d' ) );

    $wpdb->query( $wpdb->prepare(
        "UPDATE $table SET missing_since = NULL
          WHERE sf_institution_id = %s AND kind = %s AND api_id IN ($placeholders)",
        array_merge( [ $sf_institution_id, $kind ], $present_api_ids )
    ) );

    return (int) $wpdb->query( $wpdb->prepare(
        "UPDATE $table SET missing_since = %s
          WHERE sf_institution_id = %s AND kind = %s AND missing_since IS NULL AND api_id NOT IN ($placeholders)",
        array_merge( [ $now, $sf_institution_id, $kind ], $present_api_ids )
    ) );
}

/**
 * One institution's meetings of one kind, for the frontend.
 *
 * `inprogress` sorts soonest-first (what's coming up); `recent` sorts
 * newest-first (what happened most recently) — matching the production
 * "In-Progress Reviews" and "Most Recent History with SACSCOC" sections.
 */
function sacscoc_inst_meetings_for_institution( string $sf_institution_id, string $kind ): array {
    global $wpdb;
    $table = sacscoc_inst_table( 'institution_meetings' );
    $order = $kind === 'recent' ? 'DESC' : 'ASC';

    return (array) $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $table
          WHERE sf_institution_id = %s AND kind = %s AND missing_since IS NULL
          ORDER BY display_year $order, action_date $order",
        $sf_institution_id, $kind
    ), ARRAY_A );
}

/** Headline counts for the related-data section of the Sync screen. */
function sacscoc_inst_related_stats(): array {
    global $wpdb;

    if ( ! sacscoc_inst_tables_ready() ) {
        return [ 'sites' => 0, 'inprogress' => 0, 'recent' => 0 ];
    }

    $sites    = sacscoc_inst_table( 'institution_sites' );
    $meetings = sacscoc_inst_table( 'institution_meetings' );

    return [
        'sites'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $sites WHERE missing_since IS NULL AND status = 'Open'" ),
        'inprogress' => (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $meetings WHERE missing_since IS NULL AND kind = %s", 'inprogress'
        ) ),
        'recent'     => (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $meetings WHERE missing_since IS NULL AND kind = %s", 'recent'
        ) ),
    ];
}

/** Institutions due for a related-data refresh, oldest cursor position first. */
function sacscoc_inst_related_sync_targets( int $cursor, int $limit ): array {
    global $wpdb;
    $table = sacscoc_inst_table( 'institutions' );

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, sf_id FROM $table WHERE id > %d ORDER BY id ASC LIMIT %d", $cursor, $limit
    ), ARRAY_A );

    // Reached the end of the table: wrap around and start the next cycle.
    if ( ! $rows ) {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, sf_id FROM $table WHERE id > 0 ORDER BY id ASC LIMIT %d", $limit
        ), ARRAY_A );
    }

    return (array) $rows;
}

// ──────────────────────────────────────────────
// Reads for the admin screens
// ──────────────────────────────────────────────

/** Headline counts for the Sync and Settings screens. */
function sacscoc_inst_stats(): array {
    global $wpdb;

    if ( ! sacscoc_inst_tables_ready() ) {
        return [ 'total' => 0, 'missing' => 0, 'accredited' => 0, 'last_synced' => null ];
    }

    $table = sacscoc_inst_table( 'institutions' );

    return [
        'total'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ),
        'missing'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE missing_since IS NOT NULL" ),
        'accredited'  => (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE accreditation_status = %s", 'Accredited'
        ) ),
        'last_synced' => $wpdb->get_var( "SELECT MAX(last_synced) FROM $table" ),
    ];
}

/** How many institutions there are per accreditation status. */
function sacscoc_inst_status_breakdown(): array {
    global $wpdb;
    $table = sacscoc_inst_table( 'institutions' );

    return (array) $wpdb->get_results(
        "SELECT accreditation_status AS status, COUNT(*) AS n
           FROM $table GROUP BY accreditation_status ORDER BY n DESC",
        ARRAY_A
    );
}

/**
 * The list behind Institutions → All Institutions.
 *
 * Inspection and debugging, not a public search: it exists so an administrator
 * can confirm what actually landed locally and look at one record's raw JSON.
 * The visitor-facing directory is a separate thing and reads its own queries.
 *
 * @return array{rows:array,total:int}
 */
function sacscoc_inst_admin_query( array $args ): array {
    global $wpdb;

    $args = wp_parse_args( $args, [
        'search'   => '',
        'state'    => '',
        'status'   => '',
        'missing'  => false,
        'orderby'  => 'sortable_name',
        'order'    => 'ASC',
        'per_page' => 50,
        'page'     => 1,
    ] );

    $table = sacscoc_inst_table( 'institutions' );
    $where = [ '1=1' ];
    $bind  = [];

    if ( $args['search'] !== '' ) {
        $like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
        $where[] = '(name LIKE %s OR sortable_name LIKE %s OR former_names LIKE %s OR sf_id LIKE %s)';
        array_push( $bind, $like, $like, $like, $like );
    }
    if ( $args['state'] !== '' ) {
        $where[] = 'address_state = %s';
        $bind[]  = $args['state'];
    }
    if ( $args['status'] !== '' ) {
        $where[] = 'accreditation_status = %s';
        $bind[]  = $args['status'];
    }
    if ( $args['missing'] ) {
        $where[] = 'missing_since IS NOT NULL';
    }

    $where_sql = implode( ' AND ', $where );

    // Allow-list, because these two go into the SQL unquoted.
    $sortable = [ 'sortable_name', 'name', 'address_state', 'accreditation_status', 'next_reaffirm_date', 'last_synced' ];
    $orderby  = in_array( $args['orderby'], $sortable, true ) ? $args['orderby'] : 'sortable_name';
    $order    = strtoupper( $args['order'] ) === 'DESC' ? 'DESC' : 'ASC';

    $per_page = max( 1, min( 200, (int) $args['per_page'] ) );
    $offset   = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

    $count_sql = "SELECT COUNT(*) FROM $table WHERE $where_sql";
    $total     = (int) $wpdb->get_var( $bind ? $wpdb->prepare( $count_sql, $bind ) : $count_sql );

    $rows_sql = "SELECT * FROM $table WHERE $where_sql ORDER BY $orderby $order LIMIT %d OFFSET %d";
    $rows     = $wpdb->get_results(
        $wpdb->prepare( $rows_sql, array_merge( $bind, [ $per_page, $offset ] ) ),
        ARRAY_A
    );

    return [ 'rows' => (array) $rows, 'total' => $total ];
}

/** One institution by sf_id, every column. */
function sacscoc_inst_get_by_sf_id( string $sf_id ): ?array {
    global $wpdb;
    $table = sacscoc_inst_table( 'institutions' );

    $row = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM $table WHERE sf_id = %s", $sf_id ),
        ARRAY_A
    );

    return $row ?: null;
}

/** Distinct values of a column, for the admin filter dropdowns. */
function sacscoc_inst_distinct( string $column ): array {
    global $wpdb;

    $allowed = [ 'address_state', 'accreditation_status', 'address_country', 'level', 'control' ];
    if ( ! in_array( $column, $allowed, true ) ) return [];

    $table = sacscoc_inst_table( 'institutions' );

    return (array) $wpdb->get_col(
        "SELECT DISTINCT $column FROM $table
          WHERE $column IS NOT NULL AND $column <> '' ORDER BY $column ASC"
    );
}

// ──────────────────────────────────────────────
// The sync log
// ──────────────────────────────────────────────

/** Open a log row for a sync about to start; returns its id. */
function sacscoc_inst_log_start( string $trigger ): int {
    global $wpdb;

    $wpdb->insert( sacscoc_inst_table( 'sync_log' ), [
        'started_at'     => current_time( 'mysql', true ),
        'trigger_source' => $trigger,
        'status'         => 'running',
    ] );

    return (int) $wpdb->insert_id;
}

/** Close a log row with the outcome. */
function sacscoc_inst_log_finish( int $log_id, array $result ): void {
    global $wpdb;

    if ( ! $log_id ) return;

    $wpdb->update(
        sacscoc_inst_table( 'sync_log' ),
        [
            'finished_at' => current_time( 'mysql', true ),
            'status'      => $result['status'],
            'duration_ms' => $result['duration_ms'] ?? null,
            'received'    => $result['received'] ?? 0,
            'processed'   => $result['processed'] ?? 0,
            'created'     => $result['created'] ?? 0,
            'updated'     => $result['updated'] ?? 0,
            'unchanged'   => $result['unchanged'] ?? 0,
            'skipped'     => $result['skipped'] ?? 0,
            'missing'     => $result['missing'] ?? 0,
            'message'     => $result['message'] ?? '',
        ],
        [ 'id' => $log_id ]
    );
}

/** The most recent sync attempts, newest first. */
function sacscoc_inst_log_recent( int $limit = 20 ): array {
    global $wpdb;

    if ( ! sacscoc_inst_tables_ready() ) return [];

    $table = sacscoc_inst_table( 'sync_log' );

    return (array) $wpdb->get_results(
        $wpdb->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT %d", $limit ),
        ARRAY_A
    );
}

/**
 * Drop log rows beyond the most recent 200.
 *
 * The log is a debugging aid, not an archive: at four syncs a day, 200 rows is
 * about seven weeks, which is more history than anyone reads and small enough
 * to never become a problem.
 */
function sacscoc_inst_log_prune(): void {
    global $wpdb;
    $table = sacscoc_inst_table( 'sync_log' );

    $cutoff = (int) $wpdb->get_var( "SELECT id FROM $table ORDER BY id DESC LIMIT 1 OFFSET 200" );
    if ( $cutoff > 0 ) {
        $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id <= %d", $cutoff ) );
    }
}

// ──────────────────────────────────────────────
// Starting over
// ──────────────────────────────────────────────

/**
 * Empty every table this plugin fills, and forget everything derived from them.
 *
 * The tables stay — this is "start from scratch", not "uninstall": the next sync
 * repopulates them from the API. Configuration is untouched for the same reason;
 * wiping the API base URL along with the data would leave a plugin that cannot
 * refill itself.
 *
 * What goes with the rows is the state that only describes them: the cached year
 * list behind the reaffirmation filter, the last sync's result and error, and
 * the lock, in case a run died holding it and would otherwise block the sync
 * that is about to be wanted.
 *
 * TRUNCATE, so the next sync starts numbering from 1 rather than from 1,202 —
 * with DELETE as the fallback, because TRUNCATE needs the DROP privilege and
 * some managed hosts do not grant it.
 *
 * @return array<string,int> rows removed, per table
 */
function sacscoc_inst_delete_all_data(): array {
    global $wpdb;

    $removed = [];

    foreach ( [ 'institutions', 'institution_sites', 'institution_meetings', 'sync_log' ] as $name ) {
        $table = sacscoc_inst_table( $name );

        if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
            $removed[ $name ] = 0;
            continue;
        }

        $removed[ $name ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );

        if ( $wpdb->query( "TRUNCATE TABLE $table" ) === false ) {
            $wpdb->query( "DELETE FROM $table" );
        }
    }

    delete_transient( 'sacscoc_inst_reaffirm_years' );
    delete_transient( 'sacscoc_inst_sync_lock' );
    delete_transient( 'sacscoc_inst_related_sync_lock' );
    delete_option( 'sacscoc_inst_last_sync_result' );
    delete_option( 'sacscoc_inst_last_error' );
    delete_option( 'sacscoc_inst_last_successful_sync' );
    delete_option( 'sacscoc_inst_related_cursor' );
    delete_option( 'sacscoc_inst_related_last_sync' );
    delete_option( 'sacscoc_inst_related_last_sync_result' );

    return $removed;
}
