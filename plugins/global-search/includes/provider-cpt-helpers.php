<?php
/** Scoped, read-only title/content/meta search shared by the new CPT adapters. */
if ( ! defined( 'ABSPATH' ) ) exit;

function gsearch_query_tokens( string $query ): array {
	return array_values( array_unique( array_filter( preg_split( '/\s+/u', mb_strtolower( trim( $query ) ) ) ?: [] ) ) );
}

/** All query words must match; metadata is explicitly allowlisted, never arbitrary postmeta. */
function gsearch_cpt_candidates( string $query, string $type, array $meta_keys, ?callable $extra = null ): array {
	$tokens = gsearch_query_tokens( $query );
	if ( ! $tokens ) return [];
	$marker = wp_unique_id( 'gsearch-cpt-' );
	$search = static function ( $sql, $wp_query ) use ( $marker, $tokens, $meta_keys, $extra ) {
		if ( $wp_query->get( 'gsearch_cpt_marker' ) !== $marker ) return $sql;
		global $wpdb;
		$clauses = [];
		foreach ( $tokens as $token ) {
			$like = '%' . $wpdb->esc_like( $token ) . '%';
			$matches = [ $wpdb->prepare( "({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_content LIKE %s OR {$wpdb->posts}.post_excerpt LIKE %s)", $like, $like, $like ) ];
			if ( $meta_keys ) {
				$keys = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
				$matches[] = $wpdb->prepare( "EXISTS (SELECT 1 FROM {$wpdb->postmeta} gsm WHERE gsm.post_id = {$wpdb->posts}.ID AND gsm.meta_key IN ($keys) AND gsm.meta_value LIKE %s)", ...array_merge( $meta_keys, [ $like ] ) );
			}
			if ( $extra ) $matches = array_merge( $matches, $extra( $like ) );
			$clauses[] = '(' . implode( ' OR ', $matches ) . ')';
		}
		return ' AND ' . implode( ' AND ', $clauses ) . " AND {$wpdb->posts}.post_password = '' ";
	};
	add_filter( 'posts_search', $search, 20, 2 );
	try {
		$found = new WP_Query( [
			's' => $query, 'post_type' => $type, 'post_status' => 'publish',
			'gsearch_cpt_marker' => $marker, 'posts_per_page' => -1,
			'no_found_rows' => true, 'ignore_sticky_posts' => true,
		] );
		return $found->posts;
	} finally {
		remove_filter( 'posts_search', $search, 20 );
	}
}

function gsearch_cpt_title_score( string $title, string $query ): float {
	$title = mb_strtolower( $title );
	if ( $title === mb_strtolower( trim( $query ) ) ) return 1.0;
	foreach ( gsearch_query_tokens( $query ) as $token ) {
		if ( ! str_contains( $title, $token ) ) return 0.4;
	}
	return 0.85;
}

function gsearch_rank_and_limit( array $results, array $args ): array {
	usort( $results, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
	return array_slice( $results, 0, max( 1, min( 50, (int) ( $args['limit'] ?? 20 ) ) ) );
}
