<?php
/**
 * Plugin Name: SACSCOC Institutions
 * Description: Keeps a local copy of the SACSCOC institution directory in WordPress, synchronised from the SACSCOC API, and publishes it.
 * Version: 0.2.0
 * Requires PHP: 8.0
 * Text Domain: sacscoc-institutions
 *
 * ── What this plugin is ────────────────────────────────────────────────────
 *
 * The SACSCOC API is the source of truth for institution data; WordPress holds
 * a copy. Visitors are never sent to the API: every search, filter and detail
 * page reads the local tables, so the directory stays fast and stays up even
 * when the API does not.
 *
 * The copy is kept in the plugin's own tables rather than in posts and
 * postmeta. An institution has forty-three fields from the API plus related
 * sites and reviews; as postmeta that is ~50 rows per institution, ~60,000
 * rows for the directory, and every filtered search becomes a pile of meta
 * joins. One table with real columns and real indexes is the right shape for
 * this data. See includes/schema.php.
 *
 * ── Independence ───────────────────────────────────────────────────────────
 *
 * This plugin shares no code, tables, options or hooks with AI Documents. Both
 * live in the same repository but neither requires the other; each can be
 * installed, activated, updated and deployed on its own. Everything here is
 * prefixed `sacscoc_inst_` / `SACSCOC_INST_` so the two can never collide.
 *
 * ── What the visitor sees ──────────────────────────────────────────────────
 *
 * The [sacscoc_institutions] shortcode turns any WordPress page into the
 * directory, and /institutions/<slug>/ serves one institution inside the
 * theme's own header and footer. Both live in includes/frontend.php and render
 * templates/ files a theme can override. Nothing in the public path talks to
 * the API; it all reads the local tables.
 *
 * There is no AI in this plugin, by design.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'SACSCOC_INST_VERSION', '0.2.0' );
define( 'SACSCOC_INST_FILE', __FILE__ );
define( 'SACSCOC_INST_DIR', plugin_dir_path( __FILE__ ) );
define( 'SACSCOC_INST_URL', plugin_dir_url( __FILE__ ) );

// The API base URL is never hardcoded outside of this one default. It is a
// setting (Institutions → Settings), so moving to a different host later is a
// change in one field and nothing else. See sacscoc_inst_api_base_url().
define( 'SACSCOC_INST_DEFAULT_API_BASE', 'https://api.sacscoc.org' );

require_once SACSCOC_INST_DIR . 'includes/schema.php';
require_once SACSCOC_INST_DIR . 'includes/fields.php';
require_once SACSCOC_INST_DIR . 'includes/api.php';
require_once SACSCOC_INST_DIR . 'includes/repository.php';
require_once SACSCOC_INST_DIR . 'includes/sync.php';
require_once SACSCOC_INST_DIR . 'includes/query.php';
require_once SACSCOC_INST_DIR . 'includes/icons.php';
require_once SACSCOC_INST_DIR . 'includes/frontend.php';
require_once SACSCOC_INST_DIR . 'includes/settings.php';
require_once SACSCOC_INST_DIR . 'includes/cron.php';
require_once SACSCOC_INST_DIR . 'includes/admin.php';
require_once SACSCOC_INST_DIR . 'includes/admin-record.php';
require_once SACSCOC_INST_DIR . 'includes/documentation.php';

// ──────────────────────────────────────────────
// Activation / deactivation
// ──────────────────────────────────────────────
// Activation creates the tables and schedules the sync, but deliberately does
// not run a sync: activating a plugin should not block on a 1,700 KB download
// from a third party. The first sync happens on the first cron tick, or when
// an administrator presses Sync Now.

register_activation_hook( __FILE__, 'sacscoc_inst_activate' );
function sacscoc_inst_activate() {
    sacscoc_inst_install_tables();
    sacscoc_inst_schedule_sync();

    // Not flush_rewrite_rules() here: activation runs before `init`, so our
    // rule is not registered yet and the flush would write a rule set without
    // it. sacscoc_inst_maybe_flush_rules() does it on the next load.
    sacscoc_inst_request_flush();
}

// Deactivation clears the schedule and nothing else. The tables and their data
// survive, so deactivating and reactivating does not throw the directory away
// and does not force a full re-download.
register_deactivation_hook( __FILE__, 'sacscoc_inst_deactivate' );
function sacscoc_inst_deactivate() {
    sacscoc_inst_unschedule_sync();

    // Drop our institution URLs from the rule set, so nothing keeps claiming
    // /institutions/<slug>/ once the plugin is off.
    flush_rewrite_rules( false );
}

// Tables are also checked on every load, cheaply, against a stored version
// number. That way a plugin updated by SFTP — which never fires the activation
// hook — still gets its schema changes.
add_action( 'plugins_loaded', 'sacscoc_inst_maybe_upgrade' );
function sacscoc_inst_maybe_upgrade() {
    if ( get_option( 'sacscoc_inst_db_version' ) !== SACSCOC_INST_DB_VERSION ) {
        sacscoc_inst_install_tables();
    }
}
