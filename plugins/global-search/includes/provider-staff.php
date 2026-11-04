<?php
/** Pods staff CPT, confirmed on Cirlot staging; storage=postmeta. */
if ( ! defined( 'ABSPATH' ) ) exit;

class Global_Search_Staff_Provider implements Global_Search_Provider {
	public function get_id(): string { return 'staff'; }
	public function get_label(): string { return __( 'Staff', 'global-search' ); }
	public function is_available(): bool {
		$type = get_post_type_object( 'staff' );
		return function_exists( 'pods' ) && $type && $type->public && $type->publicly_queryable;
	}
	private function name_key( string $name ): string {
		$words = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $name ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		return implode( ' ', array_filter( $words, static fn( $word ) => mb_strlen( $word ) > 1 && ! in_array( $word, [ 'dr', 'mr', 'mrs', 'ms', 'prof' ], true ) ) );
	}
	public function search( string $query, array $args = [] ): array {
		if ( ! $this->is_available() || trim( $query ) === '' ) return [];
		$results = [];
		foreach ( gsearch_cpt_candidates( $query, 'staff', [ 'last_name', 'position', 'bio_description' ] ) as $post ) {
			$bio = (string) get_post_meta( $post->ID, 'bio_description', true );
			$exact_name = $this->name_key( $post->post_title ) === $this->name_key( $query );
			$results[] = gsearch_normalize_result( [
				'source' => 'staff', 'type' => 'staff', 'title' => get_the_title( $post ),
				'url' => get_permalink( $post ), 'excerpt' => wp_trim_words( wp_strip_all_tags( $bio ), 40 ),
				'meta' => [ 'title' => sanitize_text_field( get_post_meta( $post->ID, 'position', true ) ) ],
				'score' => $exact_name ? 1.0 : gsearch_cpt_title_score( $post->post_title, $query ),
			], 'staff' );
		}
		return gsearch_rank_and_limit( $results, $args );
	}
}
