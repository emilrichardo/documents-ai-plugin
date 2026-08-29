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

// The .docx extractor is JavaScript and cannot be exercised from PHP, so its
// own runner is shelled out to here rather than left as a second command
// somebody has to remember. Skipped, with a line saying so, wherever node is
// not installed — that is a missing tool, not a failing test.
foreach ( glob( __DIR__ . '/test-*.js' ) as $file ) {
    $node = trim( (string) @shell_exec( 'command -v node 2>/dev/null' ) );
    if ( $node === '' ) {
        echo "SKIP — " . basename( $file ) . " (node is not installed)\n";
        continue;
    }
    $output = [];
    $status = 0;
    exec( escapeshellarg( $node ) . ' ' . escapeshellarg( $file ) . ' 2>&1', $output, $status );
    foreach ( $output as $line ) {
        if ( preg_match( '/^(PASS|FAIL) — (.*)$/u', $line, $m ) ) {
            $GLOBALS['__aidocs_test_results'][] = $m[1] === 'PASS'
                ? [ 'name' => $m[2], 'ok' => true ]
                : [ 'name' => $m[2], 'ok' => false, 'error' => 'see node output above' ];
        }
    }
    if ( $status !== 0 ) echo implode( "\n", $output ) . "\n";
}

$total  = count( $GLOBALS['__aidocs_test_results'] );
$failed = array_filter( $GLOBALS['__aidocs_test_results'], fn( $r ) => ! $r['ok'] );

foreach ( $GLOBALS['__aidocs_test_results'] as $r ) {
    echo ( $r['ok'] ? "PASS" : "FAIL" ) . " — {$r['name']}\n";
    if ( ! $r['ok'] ) echo "       {$r['error']}\n";
}

printf( "\n%d passed, %d failed (of %d)\n", $total - count( $failed ), count( $failed ), $total );
exit( $failed ? 1 : 0 );
