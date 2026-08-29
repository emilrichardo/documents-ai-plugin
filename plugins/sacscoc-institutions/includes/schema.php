<?php
/**
 * The plugin's own tables.
 *
 * Four tables, all prefixed `sacscoc_`, all created and migrated by dbDelta so
 * a schema change is a matter of editing the CREATE TABLE below and bumping
 * SACSCOC_INST_DB_VERSION.
 *
 *   sacscoc_institutions            one row per institution — the directory
 *   sacscoc_institution_sites       off-campus instructional sites
 *   sacscoc_institution_meetings    reviews / meetings with SACSCOC
 *   sacscoc_sync_log                one row per sync run, for the admin screens
 *
 * Only `sacscoc_institutions` is written to in this release. The two related
 * tables are created now because their shape is already known from the API
 * (see docs/API-FIELD-MAP.md) and because settling the schema once is cheaper
 * than migrating a live directory later; they are populated when the related
 * data is synced, in the next milestone.
 *
 * ── Why real columns and not postmeta ──────────────────────────────────────
 *
 * Forty-two API fields per institution across ~780 accredited (1,201 total)
 * records is ~50,000 postmeta rows, and every filtered search — state plus
 * degree plus reaffirmation year — becomes three meta joins over that. With
 * columns it is one indexed WHERE. The `raw_json` column keeps the original
 * record next to the parsed one so nothing is ever lost to a mapping mistake.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Bump this whenever a CREATE TABLE below changes. sacscoc_inst_maybe_upgrade()
// compares it against the stored value on every load, so a plugin updated over
// SFTP — which never fires the activation hook — still migrates itself.
const SACSCOC_INST_DB_VERSION = '1';

/** Fully-qualified name of one of the plugin's tables. */
function sacscoc_inst_table( string $name ): string {
    global $wpdb;
    return $wpdb->prefix . 'sacscoc_' . $name;
}

/**
 * Create or migrate every table. Safe to call repeatedly — dbDelta only issues
 * the statements needed to reach the described shape.
 */
function sacscoc_inst_install_tables(): void {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset  = $wpdb->get_charset_collate();
    $inst     = sacscoc_inst_table( 'institutions' );
    $sites    = sacscoc_inst_table( 'institution_sites' );
    $meetings = sacscoc_inst_table( 'institution_meetings' );
    $log      = sacscoc_inst_table( 'sync_log' );

    // ── Institutions ──────────────────────────────────────────────────────
    // Column order follows docs/API-FIELD-MAP.md so the two can be read side
    // by side. The last block of columns is local bookkeeping with no API
    // counterpart; everything above it maps to an API field.
    dbDelta( "CREATE TABLE $inst (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        sf_id varchar(20) NOT NULL,
        api_id bigint(20) unsigned DEFAULT NULL,
        sf_owner_id varchar(20) DEFAULT NULL,
        slug varchar(190) NOT NULL,
        name varchar(255) DEFAULT NULL,
        sortable_name varchar(255) DEFAULT NULL,
        former_names text DEFAULT NULL,
        phone varchar(64) DEFAULT NULL,
        website varchar(500) DEFAULT NULL,
        ceo_name varchar(255) DEFAULT NULL,
        program_list varchar(500) DEFAULT NULL,
        student_achievement_url varchar(500) DEFAULT NULL,
        general_disclosure_url varchar(500) DEFAULT NULL,
        deg_associate varchar(8) DEFAULT NULL,
        deg_baccalaureate varchar(8) DEFAULT NULL,
        deg_master varchar(8) DEFAULT NULL,
        deg_education_specialist varchar(8) DEFAULT NULL,
        deg_doctorate varchar(8) DEFAULT NULL,
        accreditation_status varchar(64) DEFAULT NULL,
        sort_accreditation_status smallint(6) DEFAULT NULL,
        level varchar(8) DEFAULT NULL,
        control varchar(64) DEFAULT NULL,
        sanctions varchar(128) DEFAULT NULL,
        accreditation_history longtext DEFAULT NULL,
        candidacy_date date DEFAULT NULL,
        accreditation_date date DEFAULT NULL,
        reaffirmed_date date DEFAULT NULL,
        next_reaffirm_date date DEFAULT NULL,
        fifth_year_date date DEFAULT NULL,
        distance_learning_approved date DEFAULT NULL,
        course_credit_based_approved date DEFAULT NULL,
        address_street varchar(255) DEFAULT NULL,
        address_city varchar(128) DEFAULT NULL,
        address_state varchar(64) DEFAULT NULL,
        address_zip varchar(32) DEFAULT NULL,
        address_country varchar(128) DEFAULT NULL,
        contact_first_name varchar(128) DEFAULT NULL,
        contact_last_name varchar(128) DEFAULT NULL,
        contact_email varchar(255) DEFAULT NULL,
        contact_phone varchar(64) DEFAULT NULL,
        delete_flag tinyint(3) unsigned NOT NULL DEFAULT 0,
        api_created_at datetime DEFAULT NULL,
        api_updated_at datetime DEFAULT NULL,
        api_deleted_at datetime DEFAULT NULL,
        raw_json longtext DEFAULT NULL,
        content_hash char(40) NOT NULL DEFAULT '',
        first_seen datetime DEFAULT NULL,
        last_seen datetime DEFAULT NULL,
        last_synced datetime DEFAULT NULL,
        missing_since datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY sf_id (sf_id),
        UNIQUE KEY slug (slug),
        KEY api_id (api_id),
        KEY sortable_name (sortable_name(100)),
        KEY address_state (address_state),
        KEY address_country (address_country),
        KEY accreditation_status (accreditation_status),
        KEY next_reaffirm_date (next_reaffirm_date),
        KEY missing_since (missing_since)
    ) $charset" );

    // ── Off-campus instructional sites ────────────────────────────────────
    // From /api/v1/sites?sf_institution_id=… — a flat list, joined back to the
    // institution by sf_institution_id.
    dbDelta( "CREATE TABLE $sites (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        sf_id varchar(20) NOT NULL,
        api_id bigint(20) unsigned DEFAULT NULL,
        sf_institution_id varchar(20) NOT NULL,
        name varchar(255) DEFAULT NULL,
        status varchar(32) DEFAULT NULL,
        type varchar(64) DEFAULT NULL,
        street varchar(255) DEFAULT NULL,
        city varchar(128) DEFAULT NULL,
        state varchar(64) DEFAULT NULL,
        zip varchar(32) DEFAULT NULL,
        country varchar(128) DEFAULT NULL,
        api_created_at datetime DEFAULT NULL,
        api_updated_at datetime DEFAULT NULL,
        api_deleted_at datetime DEFAULT NULL,
        raw_json longtext DEFAULT NULL,
        content_hash char(40) NOT NULL DEFAULT '',
        last_synced datetime DEFAULT NULL,
        missing_since datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY sf_id (sf_id),
        KEY sf_institution_id (sf_institution_id),
        KEY status (status)
    ) $charset" );

    // ── Reviews / meetings ────────────────────────────────────────────────
    // From /api/v1/recentmeetings and /api/v1/inprogressmeetings, which return
    // the same record shape; `kind` records which list it came from, because
    // the frontend shows them as two separate sections.
    //
    // The API also returns an `original_data` blob per meeting: the entire raw
    // Salesforce Committee_Review__c record, 10–16 KB of internal fields —
    // hotel amenities, staff evaluation form links, box.com folder ids. It is
    // deliberately not stored. It is not institution data, none of it is
    // public-facing, and at ~3 meetings per institution it would add well over
    // 30 MB of Salesforce internals to the database. `raw_json` here keeps the
    // meeting record with that one key removed.
    dbDelta( "CREATE TABLE $meetings (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        api_id bigint(20) unsigned DEFAULT NULL,
        sf_institution_id varchar(20) NOT NULL,
        sf_meeting_id varchar(20) DEFAULT NULL,
        sf_committee_review_id varchar(20) DEFAULT NULL,
        kind varchar(20) NOT NULL,
        name varchar(255) DEFAULT NULL,
        description text DEFAULT NULL,
        stage varchar(64) DEFAULT NULL,
        action_date date DEFAULT NULL,
        end_date varchar(32) DEFAULT NULL,
        display_year varchar(8) DEFAULT NULL,
        api_created_at datetime DEFAULT NULL,
        api_updated_at datetime DEFAULT NULL,
        api_deleted_at datetime DEFAULT NULL,
        raw_json longtext DEFAULT NULL,
        content_hash char(40) NOT NULL DEFAULT '',
        last_synced datetime DEFAULT NULL,
        missing_since datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY api_id_kind (api_id,kind),
        KEY sf_institution_id (sf_institution_id),
        KEY kind (kind),
        KEY display_year (display_year)
    ) $charset" );

    // ── Sync log ──────────────────────────────────────────────────────────
    // One row per attempt, successful or not. Failures are the interesting
    // ones: they are what Institutions → Sync shows an administrator when the
    // API is misbehaving, and they are why a failure needs no other trace.
    dbDelta( "CREATE TABLE $log (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        started_at datetime NOT NULL,
        finished_at datetime DEFAULT NULL,
        trigger_source varchar(20) NOT NULL DEFAULT 'cron',
        status varchar(20) NOT NULL DEFAULT 'failed',
        duration_ms int(10) unsigned DEFAULT NULL,
        received int(10) unsigned NOT NULL DEFAULT 0,
        processed int(10) unsigned NOT NULL DEFAULT 0,
        created int(10) unsigned NOT NULL DEFAULT 0,
        updated int(10) unsigned NOT NULL DEFAULT 0,
        unchanged int(10) unsigned NOT NULL DEFAULT 0,
        skipped int(10) unsigned NOT NULL DEFAULT 0,
        missing int(10) unsigned NOT NULL DEFAULT 0,
        message text DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY started_at (started_at),
        KEY status (status)
    ) $charset" );

    update_option( 'sacscoc_inst_db_version', SACSCOC_INST_DB_VERSION, false );
}

/** True when the institutions table exists — guards the admin screens. */
function sacscoc_inst_tables_ready(): bool {
    global $wpdb;
    $table = sacscoc_inst_table( 'institutions' );
    return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
}
