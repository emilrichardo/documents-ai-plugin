<?php
/**
 * The sync engine: API → WordPress.
 *
 *   GET the directory
 *     ↓
 *   parse and sanity-check the payload
 *     ↓
 *   compare every record against the local fingerprint
 *     ↓
 *   insert the new, rewrite the changed, leave the unchanged alone
 *     ↓
 *   mark anything the API no longer sends as missing — never delete it
 *
 * ── A failing API must never empty the directory ───────────────────────────
 *
 * This is the rule the rest of the file is built around. A timeout, a 500, a
 * body that is not JSON, a `results` array that is suddenly empty, or one that
 * has lost half its records: every one of those ends the sync before a single
 * row is written, records the reason, and leaves the local copy exactly as it
 * was. The next sync tries again. There is no code path anywhere in this
 * plugin that deletes an institution — see sacscoc_inst_mark_presence().
 *
 * ── Why the whole dataset, every time ──────────────────────────────────────
 *
 * The API has no "changed since" endpoint, and its `updated_at` is rewritten on
 * every record on every refresh, so it cannot substitute for one (the detail is
 * in includes/fields.php). So the sync downloads all 1,201 records — one
 * request, ~1.7 MB — and does the comparison locally against a stored hash. The
 * expensive part of a sync is not the download; it is writing rows, and that is
 * what the hash avoids: a run where nothing changed writes no data at all.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Refuse a payload smaller than this fraction of what we already hold.
 *
 * A partial response is the dangerous failure, because it looks like a success:
 * the API answers 200 with well-formed JSON that happens to be missing most of
 * the directory. Losing 780 institutions to a bad upstream query is worse than
 * skipping a sync, so a payload under half the local count is treated as a
 * failure and written off. Filterable, for the case where the directory really
 * does shrink by more than half and someone needs to let it through.
 */
const SACSCOC_INST_MIN_PAYLOAD_RATIO = 0.5;

/** How long a sync may hold its lock before another run may start. */
const SACSCOC_INST_LOCK_TTL = 15 * MINUTE_IN_SECONDS;

/**
 * Synchronise the institution directory.
 *
 * Safe to call from anywhere — the admin's Sync Now button, the cron event, or
 * a script. Never throws: every outcome comes back as a result array, because
 * a sync failing is a normal thing that the admin screens need to display, not
 * an exception for someone else to handle.
 *
 * @param string $trigger 'manual' | 'cron' | 'cli'
 * @return array{status:string,message:string,received:int,processed:int,created:int,updated:int,unchanged:int,skipped:int,missing:int,duration_ms:int}
 */
function sacscoc_inst_sync_institutions( string $trigger = 'manual' ): array {
    $started = microtime( true );

    $result = [
        'status'      => 'failed',
        'message'     => '',
        'received'    => 0,
        'processed'   => 0,
        'created'     => 0,
        'updated'     => 0,
        'unchanged'   => 0,
        'skipped'     => 0,
        'missing'     => 0,
        'duration_ms' => 0,
        'trigger'     => $trigger,
    ];

    // The tables have to exist before anything else is worth trying. A plugin
    // deployed over SFTP never fires its activation hook, so this is a real
    // case and not just defensiveness.
    if ( ! sacscoc_inst_tables_ready() ) {
        sacscoc_inst_install_tables();
    }

    // One sync at a time. A cron tick arriving while an administrator is
    // watching Sync Now would otherwise have both runs writing the same rows.
    if ( get_transient( 'sacscoc_inst_sync_lock' ) ) {
        $result['status']  = 'skipped';
        $result['message'] = __( 'Another sync is already running; this one was skipped.', 'sacscoc-institutions' );
        return $result;
    }
    set_transient( 'sacscoc_inst_sync_lock', time(), SACSCOC_INST_LOCK_TTL );

    $log_id = sacscoc_inst_log_start( $trigger );

    try {
        $records = sacscoc_inst_api_fetch_institutions();

        // Transport failure, bad status, unparseable body, unrecognised shape.
        // Nothing has been written and nothing will be.
        if ( is_wp_error( $records ) ) {
            $result['message'] = $records->get_error_message();
            return sacscoc_inst_finish_sync( $result, $log_id, $started );
        }

        $result['received'] = count( $records );
        $local_total        = (int) sacscoc_inst_stats()['total'];

        $guard = sacscoc_inst_guard_payload( $result['received'], $local_total );
        if ( $guard !== null ) {
            $result['message'] = $guard;
            return sacscoc_inst_finish_sync( $result, $log_id, $started );
        }

        // ── The comparison ────────────────────────────────────────────────
        $index = sacscoc_inst_hash_index();

        // Seed the slug set with every slug already in use, so slugs assigned
        // in this run cannot collide with an existing one.
        $taken = [];
        foreach ( $index as $existing ) {
            if ( $existing['slug'] !== '' ) $taken[ $existing['slug'] ] = true;
        }

        $now     = current_time( 'mysql', true );
        $batch   = [];
        $present = [];

        foreach ( $records as $record ) {
            if ( ! is_array( $record ) ) {
                $result['skipped']++;
                continue;
            }

            // No stable id, no record. Matching on the name instead is exactly
            // what this plugin must not do: names repeat and names go missing.
            $sf_id = sacscoc_inst_parse_text( $record['sf_id'] ?? null );
            if ( $sf_id === null ) {
                $result['skipped']++;
                continue;
            }

            $result['processed']++;
            $present[] = $sf_id;

            $hash    = sacscoc_inst_content_hash( $record );
            $existing = $index[ $sf_id ] ?? null;

            // Unchanged: not queued for writing, so its row is not touched.
            if ( $existing !== null && $existing['content_hash'] === $hash ) {
                $result['unchanged']++;
                continue;
            }

            $row = sacscoc_inst_map_record( $record );

            $row['raw_json']      = wp_json_encode( $record );
            $row['content_hash']  = $hash;
            $row['last_seen']     = $now;
            $row['last_synced']   = $now;
            $row['missing_since'] = null;

            if ( $existing !== null ) {
                // The slug is excluded from the UPDATE assignments, so this
                // value is only ever used if the row turns out to be an insert.
                // Carrying the existing one keeps the two paths identical.
                $row['slug']       = $existing['slug'];
                $row['first_seen'] = null;
                $result['updated']++;
            } else {
                $row['slug']       = sacscoc_inst_unique_slug(
                    sacscoc_inst_display_name( $record ), $sf_id, $taken
                );
                $row['first_seen'] = $now;
                $result['created']++;
            }

            $batch[] = $row;
        }

        // ── The write ─────────────────────────────────────────────────────
        sacscoc_inst_write_batch( $batch );
        $result['missing'] = sacscoc_inst_mark_presence( $present );

        $result['status']  = 'success';
        $result['message'] = sprintf(
            /* translators: 1: processed, 2: created, 3: updated, 4: unchanged */
            __( '%1$d institutions processed — %2$d created, %3$d updated, %4$d unchanged.', 'sacscoc-institutions' ),
            $result['processed'],
            $result['created'],
            $result['updated'],
            $result['unchanged']
        );

        return sacscoc_inst_finish_sync( $result, $log_id, $started );

    } catch ( Throwable $e ) {
        // A database error mid-batch, or anything else unforeseen. Whatever was
        // written before it stays written — partial is fine, wrong is not — and
        // the run is reported as the failure it was, not as a success.
        $result['message'] = sprintf(
            /* translators: %s: error message */
            __( 'The sync stopped on an unexpected error: %s', 'sacscoc-institutions' ),
            $e->getMessage()
        );
        return sacscoc_inst_finish_sync( $result, $log_id, $started );

    } finally {
        delete_transient( 'sacscoc_inst_sync_lock' );
    }
}

/**
 * Reject a payload that would destroy data, and say why.
 *
 * Returns null when the payload is safe to apply, or the reason it is not.
 *
 * @return string|null
 */
function sacscoc_inst_guard_payload( int $received, int $local_total ): ?string {
    // Nothing held locally yet: any payload is an improvement, including an
    // empty one, and there is nothing to protect.
    if ( $local_total === 0 ) {
        return $received === 0
            ? __( 'The API returned no institutions at all, and there are none stored locally yet. Nothing to do.', 'sacscoc-institutions' )
            : null;
    }

    if ( $received === 0 ) {
        return sprintf(
            /* translators: %d: number of institutions stored locally */
            __( 'The API returned an empty list while %d institutions are stored locally. Treated as a failure — the local directory was left untouched.', 'sacscoc-institutions' ),
            $local_total
        );
    }

    $ratio = (float) apply_filters( 'sacscoc_inst_min_payload_ratio', SACSCOC_INST_MIN_PAYLOAD_RATIO );
    $floor = (int) floor( $local_total * $ratio );

    if ( $received < $floor ) {
        return sprintf(
            /* translators: 1: records received, 2: records stored locally, 3: percentage threshold */
            __( 'The API returned only %1$d institutions against %2$d stored locally — under the %3$d%% safety threshold. Treated as a failure — the local directory was left untouched.', 'sacscoc-institutions' ),
            $received,
            $local_total,
            (int) round( $ratio * 100 )
        );
    }

    return null;
}

/**
 * Close out a sync: stamp the duration, write the log row, update the options
 * the admin screens read, and prune the log.
 *
 * `sacscoc_inst_last_error` is what puts a red notice on the admin screens, and
 * it is cleared on success — so a failure stays visible until a sync actually
 * works, and then stops nagging.
 */
function sacscoc_inst_finish_sync( array $result, int $log_id, float $started ): array {
    $result['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

    sacscoc_inst_log_finish( $log_id, $result );

    update_option( 'sacscoc_inst_last_sync_result', $result, false );

    if ( $result['status'] === 'success' ) {
        update_option( 'sacscoc_inst_last_successful_sync', current_time( 'mysql', true ), false );
        delete_option( 'sacscoc_inst_last_error' );
    } else {
        update_option( 'sacscoc_inst_last_error', [
            'message' => $result['message'],
            'when'    => current_time( 'mysql', true ),
        ], false );
    }

    sacscoc_inst_log_prune();

    return $result;
}

/** The last recorded sync result, or null before the first run. */
function sacscoc_inst_last_result(): ?array {
    $result = get_option( 'sacscoc_inst_last_sync_result' );
    return is_array( $result ) ? $result : null;
}

/** The last recorded failure, or null when the last sync succeeded. */
function sacscoc_inst_last_error(): ?array {
    $error = get_option( 'sacscoc_inst_last_error' );
    return is_array( $error ) ? $error : null;
}

// ──────────────────────────────────────────────
// Related data: off-campus sites and reviews/meetings
//
// The API has no bulk endpoint for these — only "sites/meetings for this one
// sf_institution_id" — so there is no single request to compare a fingerprint
// against the way sacscoc_inst_sync_institutions() does. Instead this walks the
// institutions table in fixed-size batches, one batch per cron tick, cycling
// through all of them over time rather than making 1,201 × 3 requests in one
// run. See sacscoc_inst_related_sync_targets() in includes/repository.php for
// the cursor that tracks where the last batch left off.
// ──────────────────────────────────────────────

/**
 * Institutions per related-data sync tick.
 *
 * Three HTTP requests per institution, measured at roughly 0.7–1 s each against
 * the live API — a batch of 8 takes well under 30 seconds, which matters
 * because WP-Cron's pseudo-cron runs inside an ordinary page request on hosts
 * without a real system cron, and 30 seconds is a common host-imposed
 * max_execution_time.
 */
const SACSCOC_INST_RELATED_BATCH_SIZE = 8;

const SACSCOC_INST_RELATED_LOCK_TTL = 10 * MINUTE_IN_SECONDS;

/**
 * Sync off-campus sites and reviews for the next batch of institutions.
 *
 * Never throws and never empties existing related data: an endpoint failing
 * for one institution is recorded as an error for that institution and its
 * previously synced sites/meetings are left exactly as they were — the same
 * "a failing API must never destroy data" rule sacscoc_inst_sync_institutions()
 * follows for the main directory.
 *
 * @param string $trigger 'cron' | 'manual'
 * @return array{status:string,message:string,institutions:int,sites:array,recent:array,inprogress:array,errors:string[],duration_ms:int}
 */
function sacscoc_inst_sync_related_batch( string $trigger = 'cron' ): array {
    $started = microtime( true );

    $result = [
        'status'       => 'success',
        'message'      => '',
        'institutions' => 0,
        'sites'        => [ 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'missing' => 0 ],
        'recent'       => [ 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'missing' => 0 ],
        'inprogress'   => [ 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'missing' => 0 ],
        'errors'       => [],
        'duration_ms'  => 0,
        'trigger'      => $trigger,
    ];

    if ( ! sacscoc_inst_tables_ready() ) {
        sacscoc_inst_install_tables();
    }

    if ( get_transient( 'sacscoc_inst_related_sync_lock' ) ) {
        $result['status']  = 'skipped';
        $result['message'] = __( 'Another related-data sync is already running; this one was skipped.', 'sacscoc-institutions' );
        return $result;
    }
    set_transient( 'sacscoc_inst_related_sync_lock', time(), SACSCOC_INST_RELATED_LOCK_TTL );

    try {
        $cursor  = (int) get_option( 'sacscoc_inst_related_cursor', 0 );
        $targets = sacscoc_inst_related_sync_targets( $cursor, SACSCOC_INST_RELATED_BATCH_SIZE );

        if ( ! $targets ) {
            // No institutions at all yet — nothing to do until the main sync runs.
            $result['message'] = __( 'No institutions to sync related data for yet.', 'sacscoc-institutions' );
        } else {
            foreach ( $targets as $target ) {
                $sf_id = (string) $target['sf_id'];

                foreach ( sacscoc_inst_sync_related_one( $sf_id ) as $key => $counts ) {
                    if ( $key === 'errors' ) {
                        foreach ( $counts as $error ) {
                            $result['errors'][] = sprintf( '%s: %s', $sf_id, $error );
                        }
                        continue;
                    }
                    foreach ( $counts as $stat => $n ) {
                        $result[ $key ][ $stat ] += $n;
                    }
                }

                $result['institutions']++;
            }

            // sacscoc_inst_related_sync_targets() always returns rows ordered by
            // id ASC — whether this is a plain slice past the old cursor or a
            // wrapped-around read from the start of the table — so the last
            // row's id is always the correct next cursor position.
            $last = end( $targets );
            update_option( 'sacscoc_inst_related_cursor', (int) $last['id'], false );

            $result['message'] = sprintf(
                /* translators: 1: institutions processed, 2: number of errors */
                __( '%1$d institutions checked for related data — %2$d errors.', 'sacscoc-institutions' ),
                $result['institutions'],
                count( $result['errors'] )
            );

            update_option( 'sacscoc_inst_related_last_sync', current_time( 'mysql', true ), false );
        }

    } catch ( Throwable $e ) {
        $result['status']  = 'failed';
        $result['message'] = sprintf(
            /* translators: %s: error message */
            __( 'The related-data sync stopped on an unexpected error: %s', 'sacscoc-institutions' ),
            $e->getMessage()
        );
    } finally {
        delete_transient( 'sacscoc_inst_related_sync_lock' );
    }

    $result['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
    update_option( 'sacscoc_inst_related_last_sync_result', $result, false );

    return $result;
}

/**
 * Fetch and write one institution's sites, recent meetings and in-progress
 * meetings. Each of the three endpoints is independent: one failing does not
 * stop the other two, and its own previously-synced rows are left untouched.
 *
 * @return array{sites:array,recent:array,inprogress:array,errors:string[]}
 */
function sacscoc_inst_sync_related_one( string $sf_id ): array {
    $out = [
        'sites'      => [ 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'missing' => 0 ],
        'recent'     => [ 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'missing' => 0 ],
        'inprogress' => [ 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'missing' => 0 ],
        'errors'     => [],
    ];

    $sites = sacscoc_inst_api_fetch_sites( $sf_id );
    if ( is_wp_error( $sites ) ) {
        $out['errors'][] = 'sites: ' . $sites->get_error_message();
    } else {
        $out['sites'] = sacscoc_inst_sync_sites_for( $sf_id, $sites );
    }

    $recent = sacscoc_inst_api_fetch_recent_meetings( $sf_id );
    if ( is_wp_error( $recent ) ) {
        $out['errors'][] = 'recentmeetings: ' . $recent->get_error_message();
    } else {
        $out['recent'] = sacscoc_inst_sync_meetings_for( $sf_id, 'recent', $recent );
    }

    $inprogress = sacscoc_inst_api_fetch_inprogress_meetings( $sf_id );
    if ( is_wp_error( $inprogress ) ) {
        $out['errors'][] = 'inprogressmeetings: ' . $inprogress->get_error_message();
    } else {
        $out['inprogress'] = sacscoc_inst_sync_meetings_for( $sf_id, 'inprogress', $inprogress );
    }

    return $out;
}

/** Apply one institution's /api/v1/sites response. @return array{created:int,updated:int,unchanged:int,missing:int} */
function sacscoc_inst_sync_sites_for( string $sf_id, array $records ): array {
    $stats = [ 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'missing' => 0 ];
    $index = sacscoc_inst_site_hash_index( $sf_id );
    $now   = current_time( 'mysql', true );

    $batch   = [];
    $present = [];

    foreach ( $records as $record ) {
        if ( ! is_array( $record ) ) continue;

        $record_sf_id = sacscoc_inst_parse_text( $record['sf_id'] ?? null );
        if ( $record_sf_id === null ) continue;

        $present[] = $record_sf_id;
        $hash      = sacscoc_inst_site_content_hash( $record );

        if ( ( $index[ $record_sf_id ] ?? null ) === $hash ) {
            $stats['unchanged']++;
            continue;
        }

        $row                 = sacscoc_inst_map_site_record( $record );
        $row['raw_json']     = wp_json_encode( $record );
        $row['content_hash'] = $hash;
        $row['last_synced']  = $now;

        $batch[] = $row;
        $stats[ isset( $index[ $record_sf_id ] ) ? 'updated' : 'created' ]++;
    }

    sacscoc_inst_write_sites_batch( $batch );
    $stats['missing'] = sacscoc_inst_mark_sites_presence( $sf_id, $present );

    return $stats;
}

/**
 * Apply one institution's /api/v1/recentmeetings or /api/v1/inprogressmeetings
 * response. @return array{created:int,updated:int,unchanged:int,missing:int}
 */
function sacscoc_inst_sync_meetings_for( string $sf_id, string $kind, array $records ): array {
    $stats = [ 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'missing' => 0 ];
    $index = sacscoc_inst_meeting_hash_index( $sf_id, $kind );
    $now   = current_time( 'mysql', true );

    $batch   = [];
    $present = [];

    foreach ( $records as $record ) {
        if ( ! is_array( $record ) ) continue;

        // `original_data` is the 10–16 KB raw Salesforce blob — dropped before
        // it ever reaches the hash, the mapping or raw_json. See
        // docs/API-FIELD-MAP.md.
        unset( $record['original_data'] );

        $api_id = isset( $record['id'] ) && is_numeric( $record['id'] ) ? (int) $record['id'] : null;
        if ( $api_id === null ) continue;

        $present[] = $api_id;
        $hash      = sacscoc_inst_meeting_content_hash( $record );

        if ( ( $index[ $api_id ] ?? null ) === $hash ) {
            $stats['unchanged']++;
            continue;
        }

        $row                 = sacscoc_inst_map_meeting_record( $record );
        $row['kind']         = $kind;
        $row['display_year'] = sacscoc_inst_meeting_display_year( $record );
        $row['raw_json']     = wp_json_encode( $record );
        $row['content_hash'] = $hash;
        $row['last_synced']  = $now;

        $batch[] = $row;
        $stats[ isset( $index[ $api_id ] ) ? 'updated' : 'created' ]++;
    }

    sacscoc_inst_write_meetings_batch( $batch );
    $stats['missing'] = sacscoc_inst_mark_meetings_presence( $sf_id, $kind, $present );

    return $stats;
}

/** The last recorded related-data sync result, or null before the first run. */
function sacscoc_inst_related_last_result(): ?array {
    $result = get_option( 'sacscoc_inst_related_last_sync_result' );
    return is_array( $result ) ? $result : null;
}
