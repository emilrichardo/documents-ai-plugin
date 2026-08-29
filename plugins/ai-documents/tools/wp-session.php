<?php
/**
 * Mints a short-lived browser session for an existing administrator, so
 * tools/capture-screenshots.mjs can photograph the admin screens.
 *
 * It uses WordPress's own session API against a site you already own — no
 * password is read, no user is created, and the session expires in an hour.
 * Meant for a local development site; there is no reason to run it anywhere
 * a real person logs in.
 *
 *   WP_LOAD=/path/to/wp-load.php php tools/wp-session.php
 *
 * Prints JSON on stdout: the site URLs and the cookies to set.
 */

if ( PHP_SAPI !== 'cli' ) { fwrite( STDERR, "CLI only.\n" ); exit( 1 ); }

$wp_load = getenv( 'WP_LOAD' );
if ( ! $wp_load ) {
    // Default guess: the WordPress root four levels above wp-content/plugins/x.
    $wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
}
if ( ! is_file( $wp_load ) ) {
    fwrite( STDERR, "wp-load.php not found at $wp_load — set WP_LOAD.\n" );
    exit( 1 );
}

define( 'WP_USE_THEMES', false );
require $wp_load;

$admins = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
if ( ! $admins ) { fwrite( STDERR, "No administrator on this site.\n" ); exit( 1 ); }
$user = $admins[0];

$expiration = time() + 3600;
$token      = WP_Session_Tokens::get_instance( $user->ID )->create( $expiration );

echo wp_json_encode( [
    'user'    => $user->user_login,
    'home'    => home_url(),
    'admin'   => admin_url(),
    'domain'  => parse_url( home_url(), PHP_URL_HOST ),
    'cookies' => [
        LOGGED_IN_COOKIE => wp_generate_auth_cookie( $user->ID, $expiration, 'logged_in', $token ),
        AUTH_COOKIE      => wp_generate_auth_cookie( $user->ID, $expiration, 'auth',      $token ),
    ],
] ), "\n";
