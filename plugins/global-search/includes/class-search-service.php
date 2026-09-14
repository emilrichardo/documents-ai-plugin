<?php
/**
 * The one place that knows about providers as a list. REST, the shortcode
 * and the block all go through this — none of them ever talks to a
 * provider class directly, so adding a source later never touches those
 * three call sites.
 *
 * Ranking (documented per the brief's request, section 15): provider scores
 * are not comparable across sources — a 0.7 from WP_Query's crude title
 * heuristic means nothing next to a 0.7 from Institutions' name match — so
 * V1 does not attempt to interleave them. Results are grouped by source
 * (option A), in provider-registration order (WordPress, Documents,
 * Institutions, then anything a filter appends), and only sorted by score
 * *within* a single provider's own results. Simple and predictable beats a
 * cross-source ranking model this version has no real signal for.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Global_Search_Service {

	/**
	 * @return Global_Search_Provider[]
	 */
	public function get_providers(): array {
		$default = [
			new Global_Search_WordPress_Provider(),
			new Global_Search_Documents_Provider(),
			new Global_Search_Institutions_Provider(),
		];

		/**
		 * Extend Global Search with another source. Append (don't replace)
		 * unless you mean to remove a default provider:
		 *
		 *   add_filter( 'global_search_providers', function ( array $providers ) {
		 *       $providers[] = new My_Events_Provider();
		 *       return $providers;
		 *   } );
		 */
		$providers = apply_filters( 'global_search_providers', $default );

		return array_values( array_filter(
			(array) $providers,
			static fn( $provider ) => $provider instanceof Global_Search_Provider
		) );
	}

	/**
	 * @return Global_Search_Provider[]
	 */
	public function get_available_providers(): array {
		return array_values( array_filter(
			$this->get_providers(),
			static fn( Global_Search_Provider $provider ) =>
				$provider->is_available() && gsearch_provider_enabled( $provider->get_id() )
		) );
	}

	/**
	 * Metadata the frontend needs to build its own filter tabs without
	 * hardcoding source names — see section 16 of the brief.
	 */
	public function get_provider_metadata(): array {
		$meta = [];
		foreach ( $this->get_available_providers() as $provider ) {
			$meta[] = [
				'id'      => $provider->get_id(),
				'label'   => gsearch_provider_label( $provider ),
				'enabled' => true,
			];
		}
		return $meta;
	}

	/**
	 * @param array $args {
	 *     @type int      $results_per_page Per-provider result cap (not a global total — see README "Ranking").
	 *     @type string[] $sources          Provider ids to include; omit/empty for all available providers.
	 * }
	 */
	public function search( string $query, array $args = [] ): array {
		$query = trim( sanitize_text_field( $query ) );

		$settings = gsearch_get_settings();
		$per_provider = isset( $args['results_per_page'] ) ? (int) $args['results_per_page'] : (int) $settings['results_per_page'];
		$per_provider = max( 1, min( 50, $per_provider ) );

		$requested_sources = null;
		if ( ! empty( $args['sources'] ) && is_array( $args['sources'] ) ) {
			$requested_sources = array_map( 'sanitize_key', $args['sources'] );
		}

		$providers = $this->get_available_providers();

		// Requirement: an empty query returns zero results from every
		// source, with no provider even called — never "list everything".
		if ( $query === '' ) {
			$counts = [ 'all' => 0 ];
			foreach ( $providers as $provider ) {
				$counts[ $provider->get_id() ] = 0;
			}
			return [
				'query'   => '',
				'total'   => 0,
				'counts'  => $counts,
				'results' => [],
			];
		}

		// The settings fingerprint is part of the key, not just the query:
		// without it, turning a provider off in Settings leaves its results
		// being served from cache for another five minutes — which reads as
		// the setting not working.
		$cache_key = 'gsearch_' . md5( implode( '|', [
			$query,
			(string) $per_provider,
			implode( ',', $requested_sources ?? [ '*' ] ),
			(string) wp_json_encode( gsearch_get_settings() ),
		] ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$counts  = [ 'all' => 0 ];
		$results = [];

		foreach ( $providers as $provider ) {
			$id = $provider->get_id();
			$counts[ $id ] = 0;

			if ( $requested_sources !== null && ! in_array( $id, $requested_sources, true ) ) {
				continue;
			}

			$provider_results = $provider->search( $query, [ 'limit' => $per_provider ] );
			$counts[ $id ]    = count( $provider_results );
			$counts['all']   += count( $provider_results );

			foreach ( $provider_results as $result ) {
				$results[] = gsearch_normalize_result( $result, $id );
			}
		}

		$response = [
			'query'   => $query,
			'total'   => count( $results ),
			'counts'  => $counts,
			'results' => $results,
		];

		// Repeated searches (e.g. every visitor typing the same common term)
		// hit the transient instead of re-running every provider's query —
		// see brief section 23. Five minutes: long enough to matter under
		// real traffic, short enough that new/edited content shows up fast.
		set_transient( $cache_key, $response, 5 * MINUTE_IN_SECONDS );

		return $response;
	}
}

/**
 * The one service instance for a request.
 *
 * @param bool $fresh rebuild it rather than reusing the one already made.
 *   The provider list is assembled once in the constructor from the settings,
 *   so a test (or an admin screen) that changes those settings mid-request
 *   needs a new one to see the change.
 */
function gsearch_service( bool $fresh = false ): Global_Search_Service {
	static $instance = null;
	if ( $instance === null || $fresh ) {
		$instance = new Global_Search_Service();
	}
	return $instance;
}
