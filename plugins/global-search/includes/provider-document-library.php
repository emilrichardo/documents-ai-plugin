<?php
/** Barn2 Document Library Pro Advanced 4.1 adapter, independent of Policies. */
if ( ! defined( 'ABSPATH' ) ) exit;

class Global_Search_Document_Library_Provider implements Global_Search_Provider {
	private const VISIBILITY = 'Barn2\\Plugin\\Document_Library_Advanced\\Util\\Visibility';
	private const FILE_TABLE = 'Barn2\\Plugin\\Document_Library_Advanced\\File_Content\\Database\\File_Content_Table';
	public function get_id(): string { return 'document_library'; }
	public function get_label(): string { return __( 'Document Library', 'global-search' ); }
	public function is_available(): bool {
		$type = get_post_type_object( 'dlp_document' );
		return function_exists( 'Barn2\\Plugin\\Document_Library_Advanced\\document_library_pro' )
			&& function_exists( 'dlp_get_document' ) && class_exists( self::VISIBILITY )
			&& $type && $type->public && $type->publicly_queryable;
	}
	/** Existing plaintext only. Never call Barn2's parser/indexer. */
	private function file_table(): string {
		if ( ! class_exists( self::FILE_TABLE ) ) return '';
		global $wpdb;
		$table = ( new (self::FILE_TABLE)( 'dlp' ) )->get_table_name();
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) return '';
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ? $table : '';
	}
	public function search( string $query, array $args = [] ): array {
		if ( ! $this->is_available() || trim( $query ) === '' ) return [];
		global $wpdb;
		$table = $this->file_table();
		$extra = static function ( $like ) use ( $table, $wpdb ) {
			$matches = [ $wpdb->prepare( "EXISTS (SELECT 1 FROM {$wpdb->term_relationships} gsr JOIN {$wpdb->term_taxonomy} gst ON gst.term_taxonomy_id=gsr.term_taxonomy_id JOIN {$wpdb->terms} gsname ON gsname.term_id=gst.term_id WHERE gsr.object_id={$wpdb->posts}.ID AND gst.taxonomy IN ('doc_categories','doc_tags','doc_author','file_type') AND gsname.name LIKE %s)", $like ),
				$wpdb->prepare( "EXISTS (SELECT 1 FROM {$wpdb->postmeta} gslink JOIN {$wpdb->postmeta} gsfile ON gsfile.post_id=CAST(gslink.meta_value AS UNSIGNED) WHERE gslink.post_id={$wpdb->posts}.ID AND gslink.meta_key='_dlp_attached_file_id' AND gsfile.meta_key='_wp_attached_file' AND SUBSTRING_INDEX(gsfile.meta_value,'/',-1) LIKE %s)", $like ) ];
			if ( $table ) $matches[] = $wpdb->prepare( "EXISTS (SELECT 1 FROM `$table` gsfc JOIN {$wpdb->postmeta} gsattachment ON gsattachment.post_id=gsfc.post_id AND gsattachment.meta_key='_dlp_attached_file_id' AND CAST(gsattachment.meta_value AS UNSIGNED)=gsfc.attachment_id WHERE gsfc.post_id={$wpdb->posts}.ID AND gsfc.content_text LIKE %s AND (gsfc.error_message IS NULL OR gsfc.error_message=''))", $like );
			return $matches;
		};
		$results = [];
		// Optional custom document fields only if the responsible plugin defines them.
		$meta_keys = [];
		if ( class_exists( 'Barn2\\Plugin\\Easy_Post_Types_Fields\\Util' ) ) {
			foreach ( Barn2\Plugin\Easy_Post_Types_Fields\Util::get_custom_fields( 'dlp_document' ) as $field ) {
				if ( ! empty( $field['slug'] ) ) $meta_keys[] = 'dlp_document_' . sanitize_key( $field['slug'] );
			}
		}
		// Direct-link documents can also be found by filename.
		$extra_with_url = static function ( $like ) use ( $extra, $wpdb ) {
			return array_merge( $extra( $like ), [ $wpdb->prepare( "EXISTS (SELECT 1 FROM {$wpdb->postmeta} gsurl WHERE gsurl.post_id={$wpdb->posts}.ID AND gsurl.meta_key='_dlp_direct_link_url' AND SUBSTRING_INDEX(SUBSTRING_INDEX(gsurl.meta_value,'?',1),'/',-1) LIKE %s)", $like ) ] );
		};
		foreach ( gsearch_cpt_candidates( $query, 'dlp_document', $meta_keys, $extra_with_url ) as $post ) {
			$document = dlp_get_document( $post->ID );
			if ( ! $document || $document->get_expiry_status() === 'expired' || (self::VISIBILITY)::is_document_protected( $post->ID ) ) continue;
			$text = $post->post_excerpt ?: $post->post_content;
			if ( trim( $text ) === '' && $table ) {
				$text = (string) $wpdb->get_var( $wpdb->prepare( "SELECT content_text FROM `$table` WHERE post_id=%d AND attachment_id=%d AND (error_message IS NULL OR error_message='')", $post->ID, (int) $document->get_file_id() ) );
			}
			$results[] = gsearch_normalize_result( [
				'source' => 'document_library', 'type' => 'document', 'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				// Link to the document page so downloads retain Barn2's protections.
				'url' => get_permalink( $post ), 'excerpt' => wp_trim_words( wp_strip_all_tags( $text ), 40 ),
				'meta' => [ 'filename' => sanitize_text_field( $document->get_filename() ) ],
				'score' => gsearch_cpt_title_score( $post->post_title, $query ),
			], 'document_library' );
		}
		return gsearch_rank_and_limit( $results, $args );
	}
}
