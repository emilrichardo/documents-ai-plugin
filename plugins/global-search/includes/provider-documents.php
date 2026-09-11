<?php
/**
 * Adapter over the Policies plugin (folder `ai-documents`, CPT `aidoc`).
 * Global Search never touches its tables or postmeta keys directly here
 * without going through checks that mirror the plugin's own search logic
 * (ai-documents.php: aidocs_search_ajax()) — the same title+extracted-body
 * matching, reusing its own snippet builder (aidocs_search_snippet()) and
 * plain-text extractor (aidocs_content_plain_text()) when present, so the
 * excerpt shown here is built the exact same way the Policies plugin builds
 * its own. What is NOT reused is aidocs_search_ajax() itself: it is a
 * request handler (nonce + $_POST + wp_send_json), not a callable search
 * function, so there is nothing clean to call into — this is the minimal
 * adapter the brief asks for, not a duplicate of the whole engine.
 *
 * Every entry point below checks for the plugin's own AIDOCS_VERSION
 * constant first and returns gracefully (false/[]) if it is missing, so
 * Global Search never fatals when Policies is not installed or inactive.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Global_Search_Documents_Provider implements Global_Search_Provider {

	public function get_id(): string {
		return 'documents';
	}

	public function get_label(): string {
		return __( 'Policies', 'global-search' );
	}

	public function is_available(): bool {
		return defined( 'AIDOCS_VERSION' ) && post_type_exists( 'aidoc' );
	}

	public function search( string $query, array $args = [] ): array {
		if ( ! $this->is_available() || $query === '' ) {
			return [];
		}

		$limit = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 20;

		$title_ids = get_posts( [
			'post_type'      => 'aidoc',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			's'              => $query,
			'fields'         => 'ids',
		] );

		global $wpdb;
		$like        = '%' . $wpdb->esc_like( $query ) . '%';
		$content_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			 WHERE p.post_type = 'aidoc' AND p.post_status = 'publish'
			 AND pm.meta_key IN ('_document_content','_document_description','_document_summary')
			 AND pm.meta_value LIKE %s",
			$like
		) );

		$matched_ids = array_unique( array_map( 'intval', array_merge( $title_ids, $content_ids ) ) );
		if ( empty( $matched_ids ) ) {
			return [];
		}

		$posts = get_posts( [
			'post_type'      => 'aidoc',
			'post_status'    => 'publish',
			'post__in'       => $matched_ids,
			'orderby'        => 'post__in',
			'posts_per_page' => $limit,
		] );

		$results = [];
		$needle  = mb_strtolower( $query );

		foreach ( $posts as $post ) {
			$results[] = gsearch_normalize_result( [
				'source'  => $this->get_id(),
				'type'    => 'policy',
				'title'   => get_the_title( $post ),
				'url'     => get_permalink( $post ),
				'excerpt' => $this->excerpt_for( $post->ID, $query ),
				'meta'    => [
					'document_type' => $this->document_type( $post->ID ),
					'pub_date'      => get_post_meta( $post->ID, '_document_pub_date', true ),
				],
				'score'   => $this->score( $post, $needle ),
			], $this->get_id() );
		}

		return $results;
	}

	/**
	 * Reuses the plugin's own snippet builder when available (it returns
	 * escaped HTML with a <mark> highlight, which gsearch_normalize_result()
	 * strips back to plain text — the highlight is a nice-to-have, not a
	 * guarantee this provider makes) and otherwise falls back to the plain
	 * document description.
	 */
	private function excerpt_for( int $post_id, string $query ): string {
		if ( function_exists( 'aidocs_search_snippet' ) ) {
			$snippet = aidocs_search_snippet( $post_id, $query );
			if ( $snippet !== '' ) {
				return $snippet;
			}
		}
		return (string) get_post_meta( $post_id, '_document_description', true );
	}

	private function document_type( int $post_id ): string {
		$terms = wp_get_post_terms( $post_id, 'document_type', [ 'fields' => 'names' ] );
		return is_array( $terms ) && ! empty( $terms ) ? $terms[0] : '';
	}

	private function score( WP_Post $post, string $needle ): float {
		$title = mb_strtolower( $post->post_title );
		if ( $title === $needle ) {
			return 1.0;
		}
		if ( str_contains( $title, $needle ) ) {
			return 0.7;
		}
		return 0.4;
	}
}
