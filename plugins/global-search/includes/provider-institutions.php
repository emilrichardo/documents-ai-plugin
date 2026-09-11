<?php
/**
 * Adapter over the SACSCOC Institutions plugin. This is the "reuse, don't
 * re-query" case the brief calls out explicitly: Institutions already syncs
 * the external API into its own DB tables (see that plugin's
 * includes/schema.php) and exposes sacscoc_inst_search() over them
 * (includes/query.php) — Global Search calls that function and normalizes
 * its rows. It never queries the SACSCOC API itself, and never touches the
 * `wp_sacscoc_*` tables directly.
 *
 * Guards on SACSCOC_INST_VERSION + sacscoc_inst_tables_ready() first, so a
 * missing or not-yet-synced Institutions install is skipped, never fatals.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Global_Search_Institutions_Provider implements Global_Search_Provider {

	public function get_id(): string {
		return 'institutions';
	}

	public function get_label(): string {
		return __( 'Institutions', 'global-search' );
	}

	public function is_available(): bool {
		return defined( 'SACSCOC_INST_VERSION' )
			&& function_exists( 'sacscoc_inst_search' )
			&& function_exists( 'sacscoc_inst_tables_ready' )
			&& sacscoc_inst_tables_ready();
	}

	public function search( string $query, array $args = [] ): array {
		if ( ! $this->is_available() || $query === '' ) {
			return [];
		}

		$limit = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 20;

		$found = sacscoc_inst_search( [
			'q'        => $query,
			'paged'    => 1,
			'per_page' => $limit,
		] );

		$rows    = is_array( $found['rows'] ?? null ) ? $found['rows'] : [];
		$results = [];
		$needle  = mb_strtolower( $query );

		foreach ( $rows as $row ) {
			$name = (string) ( $row['name'] ?? '' );
			$city = (string) ( $row['address_city'] ?? '' );
			$state = (string) ( $row['address_state'] ?? '' );

			$results[] = gsearch_normalize_result( [
				'source'  => $this->get_id(),
				'type'    => 'institution',
				'title'   => $name,
				'url'     => function_exists( 'sacscoc_inst_permalink' ) ? sacscoc_inst_permalink( $row ) : '',
				'excerpt' => trim( $city . ( $city && $state ? ', ' . $state : $state ) ),
				'meta'    => [
					'city'    => $city,
					'state'   => $state,
					'website' => (string) ( $row['website'] ?? '' ),
				],
				'score'   => $this->score( $name, $needle ),
			], $this->get_id() );
		}

		return $results;
	}

	private function score( string $name, string $needle ): float {
		$name = mb_strtolower( $name );
		if ( $name === $needle ) {
			return 1.0;
		}
		if ( str_contains( $name, $needle ) ) {
			return 0.7;
		}
		return 0.4;
	}
}
