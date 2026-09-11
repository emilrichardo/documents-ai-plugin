<?php
/**
 * The contract every search source implements. The service
 * (class-search-service.php) never knows it is talking to WordPress core, the
 * Policies plugin, or Institutions — it only ever calls these four methods,
 * which is what lets a future WooCommerceProvider or EventsProvider slot in
 * through the `global_search_providers` filter without a single change here.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

interface Global_Search_Provider {

	/**
	 * Stable machine id, e.g. 'wordpress'. Used as the `source` value on every
	 * result it returns and as the `?source=`/`&sources=` REST filter value.
	 */
	public function get_id(): string;

	/**
	 * Human label for filter UI, e.g. 'Site'. Configurable — see
	 * includes/admin-settings.php — so the core stays unaware of what a
	 * source is actually called by editors.
	 */
	public function get_label(): string;

	/**
	 * Whether this provider can run right now (its backing plugin active,
	 * its tables/data ready). A provider that returns false is skipped
	 * silently — this is what lets Global Search run standalone.
	 */
	public function is_available(): bool;

	/**
	 * Run the search and return a list of normalized result arrays, each
	 * shaped as:
	 *
	 *   [
	 *     'source'  => string,   // this provider's get_id()
	 *     'type'    => string,   // e.g. 'page', 'post', 'policy', 'institution'
	 *     'title'   => string,
	 *     'url'     => string,
	 *     'excerpt' => string,   // plain text, already trimmed — never raw HTML
	 *     'meta'    => array,    // provider-specific extras (city/state, etc.)
	 *     'score'   => float,    // 0..1, meaningful only within this provider
	 *   ]
	 *
	 * $args may include 'limit' (int, per-provider result cap) and any
	 * provider-specific option a caller knows to pass; unknown keys must be
	 * ignored rather than erroring.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function search( string $query, array $args = [] ): array;
}
