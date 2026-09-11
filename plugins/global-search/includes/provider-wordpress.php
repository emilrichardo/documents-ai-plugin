<?php
/**
 * The one provider that always works: native Pages and Posts, searched with
 * WP_Query and WordPress's own 's' parameter (title/content/excerpt) — no
 * manual SQL. Always available, since it depends on nothing but WordPress
 * core itself.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Global_Search_WordPress_Provider implements Global_Search_Provider {

	public function get_id(): string {
		return 'wordpress';
	}

	public function get_label(): string {
		return __( 'Site', 'global-search' );
	}

	public function is_available(): bool {
		return true;
	}

	/**
	 * Which public post types this provider searches: post/page per the
	 * settings toggles, plus any additional slugs an admin opted in (Settings
	 * → Global Search) or another plugin added via the
	 * 'global_search_wordpress_post_types' filter — always intersected with
	 * get_post_types( [ 'public' => true ] ) so a private/internal post type
	 * can never be searched this way, however it got into the list.
	 */
	private function get_post_types(): array {
		$settings = gsearch_get_settings();
		$types    = [];

		if ( ! empty( $settings['search_posts'] ) ) {
			$types[] = 'post';
		}
		if ( ! empty( $settings['search_pages'] ) ) {
			$types[] = 'page';
		}

		foreach ( (array) ( $settings['post_types'] ?? [] ) as $extra ) {
			$types[] = sanitize_key( $extra );
		}

		/**
		 * Let another plugin (or mu-plugin) register additional post types
		 * with the WordPress provider without touching Global Search's own
		 * settings screen.
		 */
		$types = apply_filters( 'global_search_wordpress_post_types', $types );

		$public_types = array_keys( get_post_types( [ 'public' => true ] ) );

		// Attachments are technically public but are media, not content —
		// exclude them explicitly even if someone adds 'attachment' via the
		// filter above.
		$public_types = array_diff( $public_types, [ 'attachment' ] );

		return array_values( array_unique( array_intersect( $types, $public_types ) ) );
	}

	public function search( string $query, array $args = [] ): array {
		$post_types = $this->get_post_types();
		if ( empty( $post_types ) || $query === '' ) {
			return [];
		}

		$limit = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 20;

		$wp_query = new WP_Query( [
			's'                   => $query,
			'post_type'           => $post_types,
			'post_status'         => 'publish', // public content only — never drafts/private/pending
			'posts_per_page'      => $limit,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		] );

		$results = [];
		$needle  = mb_strtolower( $query );

		foreach ( $wp_query->posts as $post ) {
			$results[] = gsearch_normalize_result( [
				'source'  => $this->get_id(),
				'type'    => $post->post_type,
				'title'   => get_the_title( $post ),
				'url'     => get_permalink( $post ),
				'excerpt' => get_the_excerpt( $post ),
				'meta'    => [ 'post_type' => $post->post_type ],
				'score'   => $this->score( $post, $needle ),
			], $this->get_id() );
		}

		wp_reset_postdata();

		usort( $results, static fn( $a, $b ) => $b['score'] <=> $a['score'] );

		return $results;
	}

	/**
	 * WP_Query's 's' gives no relevance score, so V1 approximates one from
	 * where the match landed — see README.md "Ranking" for why: exact title
	 * match first, then a partial title match, then a content-only match.
	 * Meaningful only for ordering results within this provider.
	 */
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
