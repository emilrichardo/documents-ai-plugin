<?php
/** Real WP_Query/SQL with staging-equivalent fixtures in a rolled-back transaction.
 * Barn2 model/visibility/table boundaries are test doubles because it is absent locally.
 * Live staging QA separately exercises the actual Barn2 implementation.
 */
namespace Barn2\Plugin\Document_Library_Advanced {
	function document_library_pro() { return null; }
}
namespace Barn2\Plugin\Document_Library_Advanced\Util {
	class Visibility {
		public static function is_document_protected( $id ) { return get_post_meta( $id, '_dlp_document_visibility', true ) === 'protected'; }
	}
}
namespace Barn2\Plugin\Document_Library_Advanced\File_Content\Database {
	class File_Content_Table {
		public function __construct( $prefix ) {}
		public function get_table_name() { return 'gsearch_qa_file_content'; }
	}
}
namespace {
	function dlp_get_document( $id ) {
		return new class( $id ) {
			public function __construct( public int $id ) {}
			public function get_file_id() { return (int) get_post_meta( $this->id, '_dlp_attached_file_id', true ); }
			public function get_filename() { return basename( (string) get_post_meta( $this->get_file_id(), '_wp_attached_file', true ) ); }
			public function get_expiry_status() { return get_post_meta( $this->id, '_dlp_expiry_timestamp', true ) ? 'expired' : 'never'; }
		};
	}
	define( 'WP_USE_THEMES', false );
	$_SERVER['HTTP_HOST'] = 'cirlot.local';
	$_SERVER['REQUEST_URI'] = '/';
	$_SERVER['REQUEST_METHOD'] = 'GET';
	require '/Users/elim/Local Sites/cirlot/app/public/wp-load.php';
	$pass = 0; $fail = 0;
	function qa( $label, $condition ) {
		global $pass, $fail;
		$condition ? $pass++ : $fail++;
		echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL;
	}
	$compact = do_shortcode( '[global_search variant="compact" results="inline" show_filters="yes"]' );
	qa( 'compact does not enqueue JavaScript', ! wp_script_is( 'global-search', 'enqueued' ) );
	qa( 'compact ignores forced inline/filters', str_contains( $compact, 'data-mode="form"' ) && ! str_contains( $compact, 'global-search__panel' ) && ! str_contains( $compact, 'global-search__filters' ) );
	do_shortcode( '[global_search]' );
	qa( 'full following compact still enqueues JavaScript', wp_script_is( 'global-search', 'enqueued' ) );
	$staff = new Global_Search_Staff_Provider();
	qa( 'Pods Staff detected', $staff->is_available() );
	$found = $staff->search( 'Stephen Pruitt' );
	qa( 'Stephen Pruitt finds real ID 12103 title', ( $found[0]['title'] ?? '' ) === get_the_title( 12103 ) );
	qa( 'correct Staff profile URL', ( $found[0]['url'] ?? '' ) === get_permalink( 12103 ) );
	qa( 'correct source/type', ( $found[0]['source'] ?? '' ) === 'staff' && ( $found[0]['type'] ?? '' ) === 'staff' );
	qa( 'initial and honorific ignored for exact name', ( $found[0]['score'] ?? 0 ) === 1.0 );
	qa( 'role mapped to meta.title', ( $found[0]['meta']['title'] ?? '' ) === 'President' );
	qa( 'bio excerpt populated', str_starts_with( $found[0]['excerpt'] ?? '', 'Lorem ipsum' ) );
	qa( 'role is searchable', count( $staff->search( 'President' ) ) > 0 );
	qa( 'bio is searchable', count( $staff->search( 'Lorem ipsum' ) ) > 0 );
	qa( 'exact Staff precedes Site mention', ( gsearch_service()->search( 'Stephen Pruitt' )['results'][0]['source'] ?? '' ) === 'staff' );
	$remove_staff = static fn( $providers ) => array_values( array_filter( $providers, static fn( $p ) => $p->get_id() !== 'staff' ) );
	add_filter( 'global_search_providers', $remove_staff );
	qa( 'provider availability key prevents stale Staff cache', ! isset( gsearch_service()->search( 'Stephen Pruitt' )['counts']['staff'] ) );
	remove_filter( 'global_search_providers', $remove_staff );
	$before = get_option( GSEARCH_OPTION );
	$off = gsearch_get_settings();
	$off['providers']['staff']['enabled'] = false;
	$override = static fn() => $off;
	add_filter( 'pre_option_' . GSEARCH_OPTION, $override );
	qa( 'Staff OFF excludes cached results and metadata', ! isset( gsearch_service()->search( 'Stephen Pruitt' )['counts']['staff'] ) && ! in_array( 'staff', array_column( gsearch_service()->get_provider_metadata(), 'id' ), true ) );
	remove_filter( 'pre_option_' . GSEARCH_OPTION, $override );
	qa( 'Document Library absent locally before fixture', ! (new Global_Search_Document_Library_Provider())->is_available() );
	qa( 'missing Document Library does not fatal', (new Global_Search_Document_Library_Provider())->search( 'document' ) === [] );

	global $wpdb;
	$wpdb->query( 'START TRANSACTION' );
	$ids = [];
	$insert_post = static function ( $title, $type, $slug, $excerpt = '', $content = '', $password = '' ) use ( $wpdb, &$ids ) {
		$wpdb->insert( $wpdb->posts, [ 'post_title'=>$title, 'post_type'=>$type, 'post_name'=>$slug, 'post_status'=>'publish', 'post_excerpt'=>$excerpt, 'post_content'=>$content, 'post_password'=>$password ] );
		$ids[] = (int) $wpdb->insert_id;
		return (int) $wpdb->insert_id;
	};
	$meta = static function ( $id, $key, $value ) use ( $wpdb ) {
		$wpdb->insert( $wpdb->postmeta, [ 'post_id'=>$id, 'meta_key'=>$key, 'meta_value'=>$value ] );
	};
	$show = $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( 'gsearch_qa_file_content' ) );
	$temp_table_lookup = static function ( $sql ) use ( $show ) {
		if ( $sql === $show ) return "SELECT 'gsearch_qa_file_content'";
		// MySQL cannot reopen one TEMPORARY table in multiple subqueries.
		// Equivalent copies allow the production query (persistent Barn2 table)
		// to be tested without creating any persistent QA tables.
		$reference = 0;
		return preg_replace_callback( '/FROM `gsearch_qa_file_content` gsfc/', static function () use ( &$reference ) {
			return 'FROM `gsearch_qa_file_content_' . ++$reference . '` gsfc';
		}, $sql );
	};
	try {
		register_post_type( 'dlp_document', [ 'public'=>true, 'publicly_queryable'=>true, 'rewrite'=>['slug'=>'document'] ] );
		$wpdb->query( 'CREATE TEMPORARY TABLE gsearch_qa_file_content (post_id BIGINT, attachment_id BIGINT, content_text LONGTEXT, error_message TEXT)' );
		// SHOW TABLES omits MySQL temporary tables; only this test-double name is remapped.
		add_filter( 'query', $temp_table_lookup );
		$fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/document-library.json' ), true );
		foreach ( $fixture['documents'] as $row ) {
			$id = $insert_post( $row['title'], 'dlp_document', $row['slug'], $row['excerpt'], $row['content'] );
			if ( $row['filename'] ) {
				$attachment = $insert_post( $row['filename'], 'attachment', 'gsearch-qa-file' );
				$meta( $id, '_dlp_attached_file_id', $attachment );
				$meta( $attachment, '_wp_attached_file', '2026/09/' . $row['filename'] );
				$wpdb->insert( 'gsearch_qa_file_content', [ 'post_id'=>$id, 'attachment_id'=>$attachment, 'content_text'=>'QA-only extracted plaintext: basalt lantern', 'error_message'=>null ] );
			}
		}
		for ( $copy = 1; $copy <= 12; $copy++ ) {
			$wpdb->query( "CREATE TEMPORARY TABLE gsearch_qa_file_content_$copy AS SELECT * FROM gsearch_qa_file_content" );
		}
		$provider = new Global_Search_Document_Library_Provider();
		qa( 'Document Library detected at model boundary', $provider->is_available() );
		foreach ( $fixture['documents'] as $row ) {
			$result = $provider->search( $row['title'] );
			qa( 'real document title: ' . $row['title'], ( $result[0]['title'] ?? '' ) === $row['title'] );
			qa( 'document permalink', str_ends_with( $result[0]['url'] ?? '', '/document/' . $row['slug'] . '/' ) );
			qa( 'independent source/type', ( $result[0]['source'] ?? '' ) === 'document_library' && ( $result[0]['type'] ?? '' ) === 'document' );
		}
		qa( 'real description searchable', count( $provider->search( 'Added January 2026' ) ) === 1 );
		qa( 'filename searchable', count( $provider->search( 'SCReportsProgram-Expansion.docx' ) ) === 1 );
		qa( 'existing extracted plaintext searchable', count( $provider->search( 'basalt lantern' ) ) === 1 );
		$body = $insert_post( 'QA-only body fixture', 'dlp_document', 'gsearch-qa-body', '', 'SC Reports Program Expansion and copper comet' );
		qa( 'body searchable', count( $provider->search( 'copper comet' ) ) === 1 );
		qa( 'title beats body before limit', ( $provider->search( 'SC Reports Program Expansion', [ 'limit'=>1 ] )[0]['title'] ?? '' ) === 'SC Reports Program Expansion' );
		$protected = $insert_post( 'QA-only forbidden fragment', 'dlp_document', 'gsearch-qa-protected' );
		$meta( $protected, '_dlp_document_visibility', 'protected' );
		qa( 'Barn2 visibility callback excludes protected document', $provider->search( 'forbidden fragment' ) === [] );
		$insert_post( 'QA-only password fragment', 'dlp_document', 'gsearch-qa-password', '', '', 'qa-only' );
		qa( 'core password content excluded', $provider->search( 'password fragment' ) === [] );
		$expired = $insert_post( 'QA-only expired fragment', 'dlp_document', 'gsearch-qa-expired' );
		$meta( $expired, '_dlp_expiry_timestamp', '1' );
		qa( 'expired document excluded', $provider->search( 'expired fragment' ) === [] );
		$all = gsearch_service()->search( 'a' );
		qa( 'all five sources coexist', count( array_filter( array_intersect_key( $all['counts'], array_flip( ['wordpress','documents','institutions','staff','document_library'] ) ) ) ) === 5 );
		$off = gsearch_get_settings(); $off['providers']['document_library']['enabled'] = false;
		$override = static fn() => $off;
		add_filter( 'pre_option_' . GSEARCH_OPTION, $override );
		qa( 'Document Library OFF removes results and filter', ! isset( gsearch_service()->search( 'a' )['counts']['document_library'] ) && ! in_array( 'document_library', array_column( gsearch_service()->get_provider_metadata(), 'id' ), true ) );
		remove_filter( 'pre_option_' . GSEARCH_OPTION, $override );
		unregister_post_type( 'dlp_document' );
		qa( 'unavailable plugin removes filter and results', ! $provider->is_available() && $provider->search( 'a' ) === [] && ! isset( gsearch_service()->search( 'a' )['counts']['document_library'] ) );
	} finally {
		remove_filter( 'query', $temp_table_lookup );
		$wpdb->query( 'ROLLBACK' );
		$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS gsearch_qa_file_content' );
		for ( $copy = 1; $copy <= 12; $copy++ ) $wpdb->query( "DROP TEMPORARY TABLE IF EXISTS gsearch_qa_file_content_$copy" );
		foreach ( $ids as $id ) clean_post_cache( $id );
		if ( post_type_exists( 'dlp_document' ) ) unregister_post_type( 'dlp_document' );
	}
	qa( 'real saved settings untouched', get_option( GSEARCH_OPTION ) === $before );
	qa( 'fixtures rolled back', ! $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID=%d", $body ?? 0 ) ) );
	echo "$pass passed, $fail failed\n";
	exit( $fail ? 1 : 0 );
}
