<?php
/**
 * What happens when this plugin is deleted.
 *
 * WordPress runs this file — and only this file, with none of the plugin
 * loaded — when someone deletes the plugin from Plugins → Installed Plugins.
 * Deactivating never gets here, which is the point: deactivating is temporary
 * and must not throw 1,201 institutions away.
 *
 * ── Why there is a setting rather than a prompt ────────────────────────────
 *
 * WordPress asks "are you sure you want to delete this plugin?" and nothing
 * else. There is no hook that can add a second question to that dialog, and
 * this file cannot ask one either: by the time it runs, the plugin's files are
 * about to be removed and there is no screen left to answer on.
 *
 * So the question is asked in advance, in Institutions → Settings → "Delete
 * everything when this plugin is deleted", and this file reads the answer.
 * It is off by default, deliberately: the safe assumption when nobody has said
 * otherwise is that a delete-and-reinstall is a repair, not a fresh start —
 * which is also why reinstalling picks the data back up.
 *
 * With the box ticked, this removes every table, option and transient the
 * plugin has ever written. Nothing else on the site is touched.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

/**
 * The cron event, spelled out.
 *
 * The plugin's constants are not loaded here, so this is the one place the hook
 * name is repeated. It must match SACSCOC_INST_CRON_HOOK in includes/cron.php.
 */
const SACSCOC_INST_UNINSTALL_CRON_HOOK = 'sacscoc_institutions_sync';

/** The option that decides whether any of this runs. */
const SACSCOC_INST_UNINSTALL_FLAG = 'sacscoc_inst_delete_data_on_uninstall';

/**
 * Erase one site's data.
 *
 * Options and transients are matched by prefix rather than listed: everything
 * this plugin writes is named `sacscoc_inst_*` — a rule the whole codebase
 * follows so the two plugins in this repository can never collide — so a prefix
 * sweep cannot fall behind the way a hand-kept list would. `\_` escapes the
 * underscore, which is a single-character wildcard in SQL LIKE.
 */
function sacscoc_inst_uninstall_site(): void {
    global $wpdb;

    // The four tables, in dependency-free order — nothing here has foreign keys.
    foreach ( [ 'institution_meetings', 'institution_sites', 'sync_log', 'institutions' ] as $name ) {
        $table = $wpdb->prefix . 'sacscoc_' . $name;
        $wpdb->query( "DROP TABLE IF EXISTS `$table`" );
    }

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like( 'sacscoc_inst_' ) . '%',
            '_transient_' . $wpdb->esc_like( 'sacscoc_inst_' ) . '%',
            '_transient_timeout_' . $wpdb->esc_like( 'sacscoc_inst_' ) . '%'
        )
    );

    wp_clear_scheduled_hook( SACSCOC_INST_UNINSTALL_CRON_HOOK );
}

// A network install keeps a set of tables and options per site, so each one has
// to be visited. get_sites() is capped: on a very large network the remaining
// sites keep their data rather than the request timing out half-way through,
// which is the failure mode worth avoiding here.
if ( is_multisite() ) {
    $network_flag = (bool) get_site_option( SACSCOC_INST_UNINSTALL_FLAG, false );

    foreach ( get_sites( [ 'number' => 500, 'fields' => 'ids' ] ) as $site_id ) {
        switch_to_blog( (int) $site_id );

        if ( $network_flag || get_option( SACSCOC_INST_UNINSTALL_FLAG ) === '1' ) {
            sacscoc_inst_uninstall_site();
        }

        restore_current_blog();
    }

    if ( $network_flag ) delete_site_option( SACSCOC_INST_UNINSTALL_FLAG );

    return;
}

if ( get_option( SACSCOC_INST_UNINSTALL_FLAG ) !== '1' ) return;

sacscoc_inst_uninstall_site();
