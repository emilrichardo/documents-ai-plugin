<?php
/**
 * The Global Search block: a dynamic block (no `save` output — see
 * assets/js/blocks.js) rendered server-side through gsearch_render(), the
 * exact same function [global_search] calls. A block and the shortcode can
 * never show different markup for the same settings, because there is only
 * one renderer underneath either of them (same approach as
 * sacscoc-institutions/includes/blocks.php).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'init', 'gsearch_register_block' );
function gsearch_register_block(): void {
	wp_register_script(
		'global-search-block',
		GSEARCH_URL . 'assets/js/blocks.js',
		[ 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ],
		gsearch_asset_version( 'assets/js/blocks.js' ),
		true
	);

	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( 'global-search-block', 'global-search' );
	}

	// The editor's "Sources" checkboxes need to know which providers exist
	// right now (so a deactivated Institutions plugin never offers an
	// Institutions checkbox) without a second, hardcoded list drifting out
	// of sync with the real provider list.
	wp_localize_script( 'global-search-block', 'globalSearchBlockData', [
		'providers' => gsearch_service()->get_provider_metadata(),
	] );

	register_block_type( GSEARCH_DIR . 'blocks/search', [
		// Handing WordPress the registered style handle (not enqueuing it
		// ourselves) is what gets the real global-search.css loaded inside
		// the block editor's iframe, not just on the published page — without
		// this the editor preview rendered with browser-default styles.
		'style'           => 'global-search',
		'editor_script'   => 'global-search-block',
		'render_callback' => 'gsearch_render_block',
	] );
}

function gsearch_render_block( array $attributes ): string {
	return gsearch_render( [
		'show_filters'     => ! empty( $attributes['showFilters'] ) ? 'yes' : 'no',
		'sources'          => (string) ( $attributes['sources'] ?? '' ),
		'results_per_page' => (string) ( $attributes['resultsPerPage'] ?? '' ),
		'variant'          => (string) ( $attributes['variant'] ?? 'default' ),
		'placeholder'      => (string) ( $attributes['placeholder'] ?? '' ),
		'button_label'     => (string) ( $attributes['buttonLabel'] ?? '' ),
		'shape'            => (string) ( $attributes['shape'] ?? 'rectangular' ),
		'results_url'      => (string) ( $attributes['resultsUrl'] ?? '' ),
		'results'          => (string) ( $attributes['results'] ?? 'auto' ),
		// 0 is the block's "leave it to the variant" value; the renderer's is ''.
		'max_results'      => ! empty( $attributes['maxResults'] ) ? (string) (int) $attributes['maxResults'] : '',
		'view_all'         => '',
	] );
}
