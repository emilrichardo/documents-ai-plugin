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
gsearch_assert( 'shortcode outputs a closed results panel before searching (no visible "no results" text)', preg_match( '/class="global-search__panel"[^>]*\shidden/', $html ) === 1 );
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

echo "\n== Compact (header) variant ==\n";
$compact = do_shortcode( '[global_search variant="compact" shape="rounded" button_label="GO" placeholder="Search Site ..." show_filters="no"]' );
gsearch_assert( 'compact renders', strpos( $compact, 'global-search--compact' ) !== false );
gsearch_assert( 'button_label="GO" is the visible label', strpos( $compact, '>GO</span>' ) !== false );
gsearch_assert( 'the button keeps an accessible name of its own', strpos( $compact, 'aria-label="Search"' ) !== false );
gsearch_assert( 'placeholder matches the header the site already has', strpos( $compact, 'placeholder="Search Site ..."' ) !== false );
gsearch_assert( 'compact is always a dropdown, never inline', strpos( $compact, 'data-mode="dropdown"' ) !== false );
gsearch_assert( 'compact caps its rows', (int) preg_replace( '/\D/', '', (string) ( preg_match( '/data-max-results="(\d+)"/', $compact, $m ) ? $m[1] : 0 ) ) <= 8 );
gsearch_assert( 'no source filters rendered when show_filters="no"', strpos( $compact, 'global-search__filters' ) === false );

echo "\n== The form works without JavaScript ==\n";
gsearch_assert( 'it is a real GET form', strpos( $compact, 'method="get"' ) !== false );
gsearch_assert( 'submitting navigates rather than being intercepted', strpos( $compact, 'data-submit="navigate"' ) !== false );
gsearch_assert( 'GO goes to the results page', strpos( $compact, 'action="' . esc_url( gsearch_results_url() ) . '"' ) !== false );
gsearch_assert( 'the field is named q, which the results page reads', strpos( $compact, 'name="q"' ) !== false );

echo "\n== View all results ==\n";
$_GET['q'] = 'accreditation';
$compact_q = do_shortcode( '[global_search variant="compact"]' );
gsearch_assert( 'the dropdown offers View all results', strpos( $compact_q, 'data-global-search-view-all' ) !== false );
gsearch_assert(
	'its href is the results page carrying the query',
	strpos( $compact_q, 'href="' . esc_url( add_query_arg( 'q', 'accreditation', gsearch_results_url() ) ) . '"' ) !== false
);
gsearch_assert( 'it starts hidden, so it never shows over an empty panel', preg_match( '/class="global-search__view-all"\s+hidden/', $compact_q ) === 1 );
$_GET = [];

echo "\n== Query from the URL ==\n";
$_GET['q'] = 'accreditation';
$inline = do_shortcode( '[global_search]' );
gsearch_assert( 'the field arrives filled in', strpos( $inline, 'value="accreditation"' ) !== false );
gsearch_assert( 'a URL carrying ?q= renders as a results page, not a search box', strpos( $inline, 'data-mode="inline"' ) !== false );
gsearch_assert( 'the results panel is open rather than hidden', preg_match( '/class="global-search__panel"[^>]*\shidden/', $inline ) === 0 );
gsearch_assert( 'source filters are offered on the results page', strpos( $inline, 'global-search__filters' ) !== false );
$_GET = [];
gsearch_assert( 'the same shortcode with no ?q= is still a plain search box', strpos( do_shortcode( '[global_search]' ), 'data-mode="dropdown"' ) !== false );

echo "\n== Where a search is sent ==\n";
gsearch_assert( 'the configured Results Page resolves', gsearch_results_url() !== '' );
gsearch_assert( 'a site-relative path is accepted', gsearch_results_url( '/site-search/' ) === home_url( '/site-search/' ) );
gsearch_assert( 'an off-site URL is ignored, not honoured', gsearch_results_url( 'https://elsewhere.example/x' ) === gsearch_results_url() );
gsearch_assert( 'an unpublished page id falls back', gsearch_results_url( '999999' ) === gsearch_results_url() );

echo "\n== Accessibility of the compact box ==\n";
gsearch_assert( 'the input is a combobox', strpos( $compact, 'role="combobox"' ) !== false );
gsearch_assert( 'it announces its collapsed state', strpos( $compact, 'aria-expanded="false"' ) !== false );
gsearch_assert( 'it points at the list it controls', preg_match( '/aria-controls="([^"]+)"/', $compact, $m ) === 1 && strpos( $compact, 'id="' . $m[1] . '"' ) !== false );
gsearch_assert( 'it declares list autocomplete', strpos( $compact, 'aria-autocomplete="list"' ) !== false );
gsearch_assert( 'the results are a listbox', strpos( $compact, 'role="listbox"' ) !== false );
gsearch_assert( 'the field has a label', strpos( $compact, 'global-search__label' ) !== false );
gsearch_assert( 'there is a polite live region for the states', strpos( $compact, 'aria-live="polite"' ) !== false );

echo "\n== Providers ==\n";
foreach ( $service->get_providers() as $provider ) {
	gsearch_assert( 'provider available: ' . $provider->get_id(), $provider->is_available() );
}
$per_source = $service->search( 'university' );
gsearch_assert( 'WordPress provider returns results', ( $per_source['counts']['wordpress'] ?? 0 ) > 0 );
gsearch_assert( 'Policies provider returns results', ( $service->search( 'policy' )['counts']['documents'] ?? 0 ) > 0 );
gsearch_assert( 'Institutions provider returns results', ( $per_source['counts']['institutions'] ?? 0 ) > 0 );
gsearch_assert( 'source counts add up to the total', array_sum( array_diff_key( $per_source['counts'], [ 'all' => 1 ] ) ) === $per_source['total'] );

echo "\n== A disabled provider contributes nothing ==\n";
$saved = gsearch_get_settings();
$off = $saved;
$off['providers']['institutions']['enabled'] = false;
$disabled_fixture = static function () use ( $off ) { return $off; };
add_filter( 'pre_option_' . GSEARCH_OPTION, $disabled_fixture );
try {
	$without = gsearch_service( true )->search( 'university' );
	gsearch_assert( 'no institutions results once the provider is disabled', ( $without['counts']['institutions'] ?? 0 ) === 0 );
	gsearch_assert( 'the other providers keep working', ( $without['counts']['wordpress'] ?? 0 ) > 0 );
} finally {
	remove_filter( 'pre_option_' . GSEARCH_OPTION, $disabled_fixture );
}
$service = gsearch_service( true );
gsearch_assert( 'and it comes back when re-enabled', ( $service->search( 'university' )['counts']['institutions'] ?? 0 ) > 0 );

echo "\n== An empty query asks nobody anything ==\n";
$empty = $service->search( '' );
gsearch_assert( 'empty query returns zero results', $empty['total'] === 0 );
gsearch_assert( 'empty query returns an empty result list', $empty['results'] === [] );
$blank = $service->search( '   ' );
gsearch_assert( 'a whitespace-only query is empty too', $blank['total'] === 0 );

echo "\n== Plugin activation independence ==\n";
gsearch_assert( 'Global Search main file has no require_once on other plugins', strpos( file_get_contents( GSEARCH_DIR . 'global-search.php' ), 'ai-documents' ) === false && strpos( file_get_contents( GSEARCH_DIR . 'global-search.php' ), 'sacscoc' ) === false );

require __DIR__ . '/harness-admin.php';

echo "\n----\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
