<?php
/**
 * Same idea as harness.php, run with AI Policies and Institutions both
 * deactivated, to prove Global Search alone still works and never fatals.
 * Run only while those two plugins are deactivated; tests/harness.php
 * covers the "all three active" case.
 */

define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST']    = 'cirlot.local';
$_SERVER['REQUEST_URI']  = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
require '/Users/elim/Local Sites/cirlot/app/public/wp-load.php';

$pass = 0; $fail = 0;
function gsearch_assert( string $label, bool $condition ): void {
	global $pass, $fail;
	if ( $condition ) { $pass++; echo "PASS  $label\n"; }
	else { $fail++; echo "FAIL  $label\n"; }
}

gsearch_assert( 'AI Policies is NOT active', ! defined( 'AIDOCS_VERSION' ) );
gsearch_assert( 'Institutions is NOT active', ! defined( 'SACSCOC_INST_VERSION' ) );
gsearch_assert( 'Global Search still loaded', class_exists( 'Global_Search_Service' ) );

$service = gsearch_service();
gsearch_assert( 'Documents provider reports unavailable', ! ( new Global_Search_Documents_Provider() )->is_available() );
gsearch_assert( 'Institutions provider reports unavailable', ! ( new Global_Search_Institutions_Provider() )->is_available() );
gsearch_assert( 'Documents provider search() returns [] without fatal', ( new Global_Search_Documents_Provider() )->search( 'policy' ) === [] );
gsearch_assert( 'Institutions provider search() returns [] without fatal', ( new Global_Search_Institutions_Provider() )->search( 'university' ) === [] );

$result = $service->search( 'staff' );
gsearch_assert( 'combined search still works with only WordPress provider', is_array( $result['results'] ) );
$non_wp = array_filter( $result['results'], fn( $r ) => $r['source'] !== 'wordpress' );
gsearch_assert( 'no documents/institutions results leak in when those plugins are inactive', count( $non_wp ) === 0 );

$html = do_shortcode( '[global_search]' );
gsearch_assert( 'shortcode still renders standalone', strpos( $html, 'global-search' ) !== false );

echo "\n----\n$pass passed, $fail failed\n";
