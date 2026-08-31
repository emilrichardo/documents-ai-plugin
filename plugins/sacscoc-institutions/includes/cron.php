<?php
/**
 * The plugin's own WP-Cron event.
 *
 * One hook, `sacscoc_institutions_sync`, on a configurable schedule that
 * defaults to every six hours. The event is the plugin's alone: it is
 * registered, scheduled, rescheduled and cleared here and nowhere else, and it
 * shares nothing with any other plugin's cron.
 *
 * WP-Cron is traffic-driven — a site nobody visits runs nothing — so the Sync
 * screen shows when the next run is due and how long ago the last one was, and
 * Sync Now is always there. On a quiet staging site a real system cron calling
 * wp-cron.php is the reliable arrangement; the Sync screen says so.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const SACSCOC_INST_CRON_HOOK = 'sacscoc_institutions_sync';

/**
 * The related-data event: off-campus sites and reviews/meetings.
 *
 * Separate from the main sync and on its own, much shorter interval, because
 * the two endpoints it drives have no bulk form — see includes/sync.php's
 * sacscoc_inst_sync_related_batch(), which processes a small, fixed batch of
 * institutions per tick rather than all 1,201 at once. At
 * SACSCOC_INST_RELATED_BATCH_SIZE institutions every 5 minutes, a full cycle
 * through the directory takes roughly half a day — related data changes far
 * less often than the main directory, so this is a background trickle rather
 * than something tuned to match the main sync's frequency.
 */
const SACSCOC_INST_RELATED_CRON_HOOK = 'sacscoc_institutions_sync_related';
const SACSCOC_INST_RELATED_INTERVAL  = 5 * MINUTE_IN_SECONDS;

/** Default schedule for a fresh install. */
const SACSCOC_INST_DEFAULT_SCHEDULE = 'sacscoc_inst_6hours';

/**
 * The schedules the Sync Frequency setting may choose from.
 *
 * `hourly`, `twicedaily` and `daily` are WordPress's own; the two in between
 * are added by this plugin, prefixed so they cannot clash with another
 * plugin's idea of what "every 6 hours" is called.
 */
function sacscoc_inst_schedules(): array {
    return [
        'hourly'              => __( 'Every hour', 'sacscoc-institutions' ),
        'sacscoc_inst_3hours' => __( 'Every 3 hours', 'sacscoc-institutions' ),
        'sacscoc_inst_6hours' => __( 'Every 6 hours', 'sacscoc-institutions' ),
        'twicedaily'          => __( 'Every 12 hours', 'sacscoc-institutions' ),
        'daily'               => __( 'Once a day', 'sacscoc-institutions' ),
    ];
}

add_filter( 'cron_schedules', 'sacscoc_inst_register_schedules' );
function sacscoc_inst_register_schedules( array $schedules ): array {
    $schedules['sacscoc_inst_3hours'] = [
        'interval' => 3 * HOUR_IN_SECONDS,
        'display'  => __( 'Every 3 hours (SACSCOC Institutions)', 'sacscoc-institutions' ),
    ];
    $schedules['sacscoc_inst_6hours'] = [
        'interval' => 6 * HOUR_IN_SECONDS,
        'display'  => __( 'Every 6 hours (SACSCOC Institutions)', 'sacscoc-institutions' ),
    ];
    $schedules['sacscoc_inst_related'] = [
        'interval' => SACSCOC_INST_RELATED_INTERVAL,
        'display'  => __( 'Every 5 minutes (SACSCOC Institutions related data)', 'sacscoc-institutions' ),
    ];
    return $schedules;
}

/** The configured schedule, validated against the list above. */
function sacscoc_inst_schedule_name(): string {
    $name = (string) get_option( 'sacscoc_inst_sync_frequency', SACSCOC_INST_DEFAULT_SCHEDULE );
    return isset( sacscoc_inst_schedules()[ $name ] ) ? $name : SACSCOC_INST_DEFAULT_SCHEDULE;
}

/**
 * Schedule the sync if it is not already scheduled on the right interval.
 *
 * The first run is deliberately a minute out rather than immediate, so
 * activating the plugin does not turn into a 1.7 MB download inside the
 * activation request.
 */
function sacscoc_inst_schedule_sync(): void {
    $wanted = sacscoc_inst_schedule_name();
    $next   = wp_next_scheduled( SACSCOC_INST_CRON_HOOK );

    if ( $next ) {
        // Already scheduled — leave it alone unless the interval has changed.
        $current = wp_get_schedule( SACSCOC_INST_CRON_HOOK );
        if ( $current === $wanted ) return;

        wp_unschedule_hook( SACSCOC_INST_CRON_HOOK );
    }

    wp_schedule_event( time() + MINUTE_IN_SECONDS, $wanted, SACSCOC_INST_CRON_HOOK );
}

/** Clear the event. Called on deactivation; leaves the data alone. */
function sacscoc_inst_unschedule_sync(): void {
    wp_unschedule_hook( SACSCOC_INST_CRON_HOOK );
}

/** Schedule the related-data event if it is not already scheduled. */
function sacscoc_inst_schedule_related_sync(): void {
    if ( wp_next_scheduled( SACSCOC_INST_RELATED_CRON_HOOK ) ) return;
    wp_schedule_event( time() + MINUTE_IN_SECONDS, 'sacscoc_inst_related', SACSCOC_INST_RELATED_CRON_HOOK );
}

/** Clear the related-data event. Called on deactivation; leaves the data alone. */
function sacscoc_inst_unschedule_related_sync(): void {
    wp_unschedule_hook( SACSCOC_INST_RELATED_CRON_HOOK );
}

// Self-healing: a plugin deployed over SFTP never fires its activation hook, so
// the event would otherwise never exist on staging. This costs one option read
// per request and puts the schedule back whenever it is missing or stale.
add_action( 'plugins_loaded', 'sacscoc_inst_ensure_scheduled', 20 );
function sacscoc_inst_ensure_scheduled(): void {
    if ( ! is_admin() && ! wp_doing_cron() ) return;
    sacscoc_inst_schedule_sync();
    sacscoc_inst_schedule_related_sync();
}

// The events themselves.
add_action( SACSCOC_INST_CRON_HOOK, 'sacscoc_inst_run_scheduled_sync' );
function sacscoc_inst_run_scheduled_sync(): void {
    sacscoc_inst_sync_institutions( 'cron' );
}

add_action( SACSCOC_INST_RELATED_CRON_HOOK, 'sacscoc_inst_run_scheduled_related_sync' );
function sacscoc_inst_run_scheduled_related_sync(): void {
    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 90 );
    }
    sacscoc_inst_sync_related_batch( 'cron' );
}

/** When the next automatic sync is due, as a Unix timestamp, or 0. */
function sacscoc_inst_next_sync(): int {
    return (int) wp_next_scheduled( SACSCOC_INST_CRON_HOOK );
}

/**
 * True when something has disabled WP-Cron entirely.
 *
 * Worth surfacing on the Sync screen: with DISABLE_WP_CRON set and no system
 * cron calling wp-cron.php, the schedule below is real but nothing ever fires
 * it, and the directory silently stops updating.
 */
function sacscoc_inst_cron_disabled(): bool {
    return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
}
