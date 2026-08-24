<?php
/**
 * Minimal WordPress stub layer so the plugin's pure logic can be exercised
 * with `php`, without a full WP install/DB. There is no existing test
 * harness in this plugin (no PHPUnit, no wp-phpunit, no CI test stage) —
 * this is intentionally the smallest thing that can load ai-documents.php
 * and let a test call a real function from it (aidocs_save_meta, taxonomy
 * sanitizers, the doc parser, …) and assert on the result.
 *
 * It is NOT a WordPress emulator: only the functions the plugin's top-level
 * code and the functions under test actually call are stubbed. If a test
 * needs a function this file doesn't have yet, add it here rather than
 * reaching for a real WP function you assume exists.
 */

error_reporting( E_ALL & ~E_DEPRECATED );

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

// ── In-memory "database" ────────────────────────────────────────────────
$GLOBALS['__aidocs_test_options']    = [];
$GLOBALS['__aidocs_test_postmeta']   = [];   // post_id => [ meta_key => value ]
$GLOBALS['__aidocs_test_terms']      = [];   // post_id => [ taxonomy => [ term names ] ]
$GLOBALS['__aidocs_test_posts']      = [];   // post_id => stdClass-ish array
$GLOBALS['__aidocs_test_transients'] = [];

function aidocs_test_reset_db() {
    $GLOBALS['__aidocs_test_options']    = [];
    $GLOBALS['__aidocs_test_postmeta']   = [];
    $GLOBALS['__aidocs_test_terms']      = [];
    $GLOBALS['__aidocs_test_posts']      = [];
    $GLOBALS['__aidocs_test_transients'] = [];
}

function aidocs_test_seed_post( $post_id, array $meta = [], array $terms = [] ) {
    $GLOBALS['__aidocs_test_postmeta'][ $post_id ] = $meta;
    $GLOBALS['__aidocs_test_terms'][ $post_id ]    = $terms;
}

// ── Options ──────────────────────────────────────────────────────────────
function get_option( $name, $default = false ) {
    return $GLOBALS['__aidocs_test_options'][ $name ] ?? $default;
}
function update_option( $name, $value, $autoload = null ) {
    $GLOBALS['__aidocs_test_options'][ $name ] = $value;
    return true;
}
function delete_option( $name ) {
    unset( $GLOBALS['__aidocs_test_options'][ $name ] );
    return true;
}
function add_option( $name, $value ) { return update_option( $name, $value ); }

// ── Post meta ────────────────────────────────────────────────────────────
function get_post_meta( $post_id, $key = '', $single = false ) {
    $all = $GLOBALS['__aidocs_test_postmeta'][ $post_id ] ?? [];
    if ( $key === '' ) return $all;
    if ( ! array_key_exists( $key, $all ) ) return $single ? '' : [];
    return $single ? $all[ $key ] : (array) $all[ $key ];
}
function update_post_meta( $post_id, $key, $value ) {
    $GLOBALS['__aidocs_test_postmeta'][ $post_id ][ $key ] = $value;
    return true;
}
function delete_post_meta( $post_id, $key ) {
    unset( $GLOBALS['__aidocs_test_postmeta'][ $post_id ][ $key ] );
    return true;
}

// ── Taxonomy terms ───────────────────────────────────────────────────────
function wp_set_post_terms( $post_id, $terms, $taxonomy, $append = false ) {
    $terms = array_values( array_unique( array_map( 'strval', (array) $terms ) ) );
    if ( $append ) {
        $existing = $GLOBALS['__aidocs_test_terms'][ $post_id ][ $taxonomy ] ?? [];
        $terms    = array_values( array_unique( array_merge( $existing, $terms ) ) );
    }
    $GLOBALS['__aidocs_test_terms'][ $post_id ][ $taxonomy ] = $terms;
    return $terms;
}
function wp_get_post_terms( $post_id, $taxonomy, $args = [] ) {
    return $GLOBALS['__aidocs_test_terms'][ $post_id ][ $taxonomy ] ?? [];
}
function get_the_terms( $post_id, $taxonomy ) {
    $terms = wp_get_post_terms( $post_id, $taxonomy );
    return $terms ?: false;
}

// ── Posts ────────────────────────────────────────────────────────────────
function wp_insert_post( $args, $wp_error = false ) {
    static $next_id = 9000;
    $id = ++$next_id;
    $GLOBALS['__aidocs_test_posts'][ $id ] = $args;
    return $id;
}
function wp_update_post( $args ) {
    $id = $args['ID'];
    $GLOBALS['__aidocs_test_posts'][ $id ] = array_merge( $GLOBALS['__aidocs_test_posts'][ $id ] ?? [], $args );
    return $id;
}
function get_edit_post_link( $id, $context = '' ) { return "http://example.test/wp-admin/post.php?post=$id&action=edit"; }
function get_permalink( $id ) { return "http://example.test/?aidoc=$id"; }

// ── Auth / capability / nonce ────────────────────────────────────────────
function current_user_can( $cap, ...$args ) { return true; }
function wp_verify_nonce( $nonce, $action = -1 ) { return $nonce !== 'INVALID'; }
function check_ajax_referer( $action, $query_arg = false, $die = true ) { return true; }
function wp_create_nonce( $action = -1 ) { return 'test-nonce'; }

// ── Sanitizers (real enough for tests: trims + strips tags) ─────────────
function sanitize_text_field( $str ) { return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $str ) ) ); }
function sanitize_textarea_field( $str ) { return trim( strip_tags( (string) $str ) ); }
function wp_strip_all_tags( $str ) { return trim( strip_tags( (string) $str ) ); }
function esc_html( $str ) { return htmlspecialchars( (string) $str, ENT_QUOTES ); }
function esc_attr( $str ) { return htmlspecialchars( (string) $str, ENT_QUOTES ); }
function esc_html_e( $str, $domain = null ) { echo esc_html( $str ); }
function esc_html__( $str, $domain = null ) { return $str; }
function esc_attr_e( $str, $domain = null ) { echo esc_attr( $str ); }
function esc_attr__( $str, $domain = null ) { return $str; }
function esc_js( $str ) { return addslashes( (string) $str ); }
function esc_textarea( $str ) { return htmlspecialchars( (string) $str, ENT_QUOTES ); }
function esc_url( $str ) { return $str; }
function __( $str, $domain = null ) { return $str; }
function _e( $str, $domain = null ) { echo $str; }
function absint( $n ) { return abs( (int) $n ); }
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) ); }
function get_current_user_id() { return 1; }
function wp_slash( $v ) { return $v; }
function wp_unslash( $v ) { return $v; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function checked( $a, $b = true, $echo = true ) { $r = ( (string) $a === (string) $b ) ? ' checked="checked"' : ''; if ( $echo ) echo $r; return $r; }
function selected( $a, $b = true, $echo = true ) { $r = ( (string) $a === (string) $b ) ? ' selected="selected"' : ''; if ( $echo ) echo $r; return $r; }

// ── Transients ───────────────────────────────────────────────────────────
function set_transient( $key, $value, $expiration = 0 ) { $GLOBALS['__aidocs_test_transients'][ $key ] = $value; return true; }
function get_transient( $key ) { return $GLOBALS['__aidocs_test_transients'][ $key ] ?? false; }
function delete_transient( $key ) { unset( $GLOBALS['__aidocs_test_transients'][ $key ] ); return true; }

// ── HTTP (never actually called in these tests: no Gemini API key is ever
//    seeded, so aidocs_maybe_reindex() and friends short-circuit first) ──
function wp_remote_post( $url, $args = [] ) { return new WP_Error( 'test_no_network', 'Network disabled in tests' ); }
function wp_remote_get( $url, $args = [] ) { return new WP_Error( 'test_no_network', 'Network disabled in tests' ); }
function wp_remote_retrieve_body( $r ) { return ''; }
function wp_remote_retrieve_response_code( $r ) { return 0; }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
class WP_Error {
    public $code; public $message;
    function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
    function get_error_message() { return $this->message; }
}

// ── Hook registry (no-ops: tests call functions directly, not via do_action) ──
function add_action( $hook, $cb, $priority = 10, $args = 1 ) {}
function add_filter( $hook, $cb, $priority = 10, $args = 1 ) {}
function do_action( $hook, ...$args ) {}
function apply_filters( $hook, $value, ...$args ) { return $value; }
function add_shortcode( $tag, $cb ) {}
function register_activation_hook( $file, $cb ) {}
function register_deactivation_hook( $file, $cb ) {}
function flush_rewrite_rules() {}
function plugin_dir_path( $file ) { return rtrim( dirname( $file ), '/' ) . '/'; }
function plugin_dir_url( $file ) { return 'http://example.test/wp-content/plugins/ai-documents/'; }
function wp_cache_flush() {}
// Real wp_send_json_*() calls wp_die(), which halts the request — code after
// it in the same function never runs. A test that only echoed and returned
// would let that code run anyway and could pass (or fail) for reasons a real
// request never hits. This throws to unwind the same way, and
// aidocs_test_call_ajax() below is what catches it.
class Aidocs_Test_Json_Response extends Exception {
    public $payload;
    function __construct( $payload ) { $this->payload = $payload; parent::__construct( 'json response' ); }
}
function wp_send_json_success( $data = null ) { throw new Aidocs_Test_Json_Response( [ 'success' => true, 'data' => $data ] ); }
function wp_send_json_error( $data = null ) { throw new Aidocs_Test_Json_Response( [ 'success' => false, 'data' => $data ] ); }

require_once dirname( __DIR__ ) . '/ai-documents.php';
