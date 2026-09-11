<?php
/**
 * Assertion harness for Global Search — boots WordPress from the CLI
 * (WP_USE_THEMES=false skips the theme, so nothing here depends on Astra or
 * Elementor being intact) and runs real queries against the local site's
 * synced prod data. Not a PHPUnit suite — this repo has none — mirrors the
 * ad hoc harness already used to verify ai-documents/sacscoc-institutions.
 *
 * Run: php tests/harness.php
 */

define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST']    = 'cirlot.local';
$_SERVER['REQUEST_URI']  = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
require '/Users/elim/Local Sites/cirlot/app/public/wp-load.php';

$pass = 0;
$fail = 0;

function gsearch_assert( string $label, bool $condition ): void {
	global $pass, $fail;
	if ( $condition ) {
		$pass++;
		echo "PASS  $label\n";
	} else {
		$fail++;
		echo "FAIL  $label\n";
	}
}

echo "== Plugin loaded ==\n";
gsearch_assert( 'Global_Search_Provider interface exists', interface_exists( 'Global_Search_Provider' ) );
gsearch_assert( 'Global_Search_Service class exists', class_exists( 'Global_Search_Service' ) );
gsearch_assert( 'gsearch_service() returns a service', gsearch_service() instanceof Global_Search_Service );

$service = gsearch_service();

echo "\n== Provider availability (reflects real plugin state) ==\n";
foreach ( $service->get_providers() as $provider ) {
	printf( "  %-14s available=%s enabled=%s\n", $provider->get_id(), $provider->is_available() ? 'yes' : 'no', gsearch_provider_enabled( $provider->get_id() ) ? 'yes' : 'no' );
}
gsearch_assert( 'AI Policies detected active', defined( 'AIDOCS_VERSION' ) );
gsearch_assert( 'Institutions detected active', defined( 'SACSCOC_INST_VERSION' ) );
gsearch_assert( 'Documents provider available given Policies is active', ( new Global_Search_Documents_Provider() )->is_available() );
gsearch_assert( 'Institutions provider available given Institutions is active', ( new Global_Search_Institutions_Provider() )->is_available() );

echo "\n== Empty query returns nothing (requirement #13) ==\n";
$empty = $service->search( '' );
gsearch_assert( 'empty query -> total 0', $empty['total'] === 0 );
gsearch_assert( 'empty query -> no results array entries', count( $empty['results'] ) === 0 );
gsearch_assert( 'empty query -> counts present but all zero', array_sum( $empty['counts'] ) === 0 );

echo "\n== WordPress provider: pages/posts ==\n";
$wp_provider = new Global_Search_WordPress_Provider();
// Pick a real published page title fragment to search for.
$sample_page = get_posts( [ 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => 1 ] );
if ( $sample_page ) {
	$word = strtolower( explode( ' ', $sample_page[0]->post_title )[0] );
	$results = $wp_provider->search( $word );
	gsearch_assert( "WordPress provider finds a result for '$word'", count( $results ) > 0 );
	if ( $results ) {
		gsearch_assert( 'WordPress result has required keys', isset( $results[0]['source'], $results[0]['type'], $results[0]['title'], $results[0]['url'] ) );
		gsearch_assert( "WordPress result source is 'wordpress'", $results[0]['source'] === 'wordpress' );
	}
} else {
	echo "  (skipped — no published pages found)\n";
}

echo "\n== Documents provider ==\n";
$doc_provider = new Global_Search_Documents_Provider();
$sample_doc = get_posts( [ 'post_type' => 'aidoc', 'post_status' => 'publish', 'posts_per_page' => 1 ] );
if ( $sample_doc ) {
	$word = strtolower( explode( ' ', $sample_doc[0]->post_title )[0] );
	$results = $doc_provider->search( $word );
	gsearch_assert( "Documents provider finds a result for '$word'", count( $results ) > 0 );
	if ( $results ) {
		gsearch_assert( "Documents result type is 'policy'", $results[0]['type'] === 'policy' );
		gsearch_assert( 'Documents result excerpt has no HTML tags', $results[0]['excerpt'] === wp_strip_all_tags( $results[0]['excerpt'] ) );
	}
} else {
	echo "  (skipped — no published aidoc posts found)\n";
}
gsearch_assert( 'Documents provider does not fatal when queried with nonsense', is_array( $doc_provider->search( 'zzzz_no_match_zzzz' ) ) );

echo "\n== Institutions provider ==\n";
$inst_provider = new Global_Search_Institutions_Provider();
if ( function_exists( 'sacscoc_inst_search' ) ) {
	$found = sacscoc_inst_search( [ 'q' => '', 'per_page' => 1 ] );
	$sample_row = $found['rows'][0] ?? null;
	if ( $sample_row ) {
		$word = strtolower( explode( ' ', $sample_row['name'] )[0] );
		$results = $inst_provider->search( $word );
		gsearch_assert( "Institutions provider finds a result for '$word'", count( $results ) > 0 );
		if ( $results ) {
			gsearch_assert( "Institutions result type is 'institution'", $results[0]['type'] === 'institution' );
			gsearch_assert( 'Institutions result has a permalink', $results[0]['url'] !== '' );
		}
	}
}

echo "\n== Multi-provider combine + source filter ==\n";
$common = 'a';
$combined = $service->search( $common );
gsearch_assert( 'combined search returns a counts array with all + provider keys', isset( $combined['counts']['all'] ) );
$doc_only = $service->search( $common, [ 'sources' => [ 'documents' ] ] );
$non_doc = array_filter( $doc_only['results'], fn( $r ) => $r['source'] !== 'documents' );
gsearch_assert( 'source=documents filter returns only documents results', count( $non_doc ) === 0 );

echo "\n== Sanitization: script-tag query never reaches output unescaped ==\n";
$xss = $service->search( '<script>alert(1)</script>' );
gsearch_assert( 'query field has tags stripped', strpos( $xss['query'], '<script>' ) === false );

echo "\n== Shortcode renders ==\n";
$html = do_shortcode( '[global_search]' );
gsearch_assert( 'shortcode outputs the search wrapper', strpos( $html, 'global-search' ) !== false );
gsearch_assert( 'shortcode outputs a closed results panel before searching (no visible "no results" text)', strpos( $html, 'global-search__panel" hidden' ) !== false );
gsearch_assert( 'shortcode markup carries no visible empty-state copy', strpos( $html, 'global-search__empty' ) === false );
$html_compact = do_shortcode( '[global_search variant="compact" show_filters="no"]' );
gsearch_assert( 'compact variant renders', strpos( $html_compact, 'global-search--compact' ) !== false );
$html_rounded = do_shortcode( '[global_search shape="rounded"]' );
gsearch_assert( 'rounded shape renders', strpos( $html_rounded, 'global-search--rounded' ) !== false );

echo "\n== Gutenberg block registered ==\n";
$block_registry = WP_Block_Type_Registry::get_instance();
gsearch_assert( 'block type is registered', $block_registry->is_registered( 'global-search/search' ) );
$block_type = $block_registry->get_registered( 'global-search/search' );
gsearch_assert( 'block declares its stylesheet handle (so the block editor loads real CSS, not browser defaults)', $block_type && $block_type->style === 'global-search' );
gsearch_assert( 'the style handle is actually registered', wp_style_is( 'global-search', 'registered' ) );

echo "\n== REST route registered ==\n";
$routes = rest_get_server()->get_routes();
gsearch_assert( 'search route registered', isset( $routes['/global-search/v1/search'] ) );
gsearch_assert( 'providers route registered', isset( $routes['/global-search/v1/providers'] ) );

echo "\n== REST search end-to-end (JSON valid) ==\n";
$request = new WP_REST_Request( 'GET', '/global-search/v1/search' );
$request->set_param( 'q', $common );
$response = rest_get_server()->dispatch( $request );
gsearch_assert( 'REST search returns 200', $response->get_status() === 200 );
$data = $response->get_data();
gsearch_assert( 'REST response has query/total/counts/results', isset( $data['query'], $data['total'], $data['counts'], $data['results'] ) );
$json = wp_json_encode( $data );
gsearch_assert( 'REST response encodes to valid JSON', $json !== false && json_decode( $json ) !== null );

echo "\n== Plugin activation independence ==\n";
gsearch_assert( 'Global Search main file has no require_once on other plugins', strpos( file_get_contents( GSEARCH_DIR . 'global-search.php' ), 'ai-documents' ) === false && strpos( file_get_contents( GSEARCH_DIR . 'global-search.php' ), 'sacscoc' ) === false );

echo "\n----\n$pass passed, $fail failed\n";
