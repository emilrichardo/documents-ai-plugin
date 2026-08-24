<?php
/**
 * Tiny standalone test runner — no PHPUnit, no WP test suite, no DB.
 * Usage: php tests/run.php
 *
 * Loads bootstrap.php (which stubs just enough of WordPress to require
 * ai-documents.php once), then every tests/test-*.php file. Each of those
 * calls test('name', function () { ... assert_*(...) ... }) at the top
 * level; failures are collected and reported at the end with a non-zero
 * exit code, so this can gate the SFTP deploy workflow later.
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['__aidocs_test_results'] = [];

function test( $name, callable $fn ) {
    aidocs_test_reset_db();
    try {
        $fn();
        $GLOBALS['__aidocs_test_results'][] = [ 'name' => $name, 'ok' => true ];
    } catch ( Throwable $e ) {
        $GLOBALS['__aidocs_test_results'][] = [ 'name' => $name, 'ok' => false, 'error' => $e->getMessage() ];
    }
}

function assert_equal( $actual, $expected, $label = '' ) {
    $a = is_array( $actual ) ? sort_and_dump( $actual ) : $actual;
    $e = is_array( $expected ) ? sort_and_dump( $expected ) : $expected;
    if ( $a !== $e ) {
        throw new Exception( sprintf(
            "%s: expected %s, got %s",
            $label ?: 'assert_equal',
            var_export( $expected, true ),
            var_export( $actual, true )
        ) );
    }
}

function assert_true( $value, $label = '' ) {
    if ( ! $value ) throw new Exception( ( $label ?: 'assert_true' ) . ': expected truthy value' );
}

function sort_and_dump( array $arr ) {
    sort( $arr );
    return json_encode( $arr );
}

// Discover and run every test-*.php file in this directory.
foreach ( glob( __DIR__ . '/test-*.php' ) as $file ) {
    require $file;
}

$total  = count( $GLOBALS['__aidocs_test_results'] );
$failed = array_filter( $GLOBALS['__aidocs_test_results'], fn( $r ) => ! $r['ok'] );

foreach ( $GLOBALS['__aidocs_test_results'] as $r ) {
    echo ( $r['ok'] ? "PASS" : "FAIL" ) . " — {$r['name']}\n";
    if ( ! $r['ok'] ) echo "       {$r['error']}\n";
}

printf( "\n%d passed, %d failed (of %d)\n", $total - count( $failed ), count( $failed ), $total );
exit( $failed ? 1 : 0 );
