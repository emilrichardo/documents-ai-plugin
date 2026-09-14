<?php
/**
 * Small shared helpers: settings storage and the version-by-mtime trick used
 * for cache-busting assets (same approach as the other plugins in this repo —
 * see sacscoc_inst_asset_version()).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const GSEARCH_OPTION = 'gsearch_settings';

/**
 * Everything configurable from Settings → Global Search, with the defaults
 * a fresh install runs on. Kept as one option (one row) rather than many —
 * there is no reason to pay a query per setting for a screen this small.
 */
function gsearch_default_settings(): array {
	return [
		'results_per_page' => 10,
		'search_posts'     => true,
		'search_pages'     => true,
		'post_types'       => [], // additional public CPTs, beyond post/page, opted into by slug
		'providers'        => [
			// Per-provider overrides keyed by provider id. A provider absent
			// here just uses its own get_label()/defaults.
			'wordpress'    => [ 'enabled' => true, 'label' => 'Site' ],
			'documents'    => [ 'enabled' => true, 'label' => 'Policies' ],
			'institutions' => [ 'enabled' => true, 'label' => 'Institutions' ],
		],
		// On by default: a dropdown-panel search box reads as a typeahead,
		// so requiring a separate click/Enter before anything happens is a
		// worse default UX for this presentation than the debounce below.
		'live_search'       => true,
		'min_characters'    => 3,
		'debounce_ms'       => 300,
		// The page carrying the full results — where the compact header box
		// sends a visitor who presses GO or Enter, and where its dropdown's
		// "View all results" goes. 0 means none is configured yet; see
		// gsearch_results_url() for what happens then.
		'results_page'      => 0,
		// How many rows the compact dropdown shows before it stops. Small on
		// purpose: a header typeahead that drops thirty rows over the page is
		// not a quicker way to find something than the results page is.
		'compact_max_results' => 6,
	];
}

/**
 * Where a search box submits to — the full results page.
 *
 * Resolved the four ways the two sibling plugins resolve theirs
 * (sacscoc_inst_results_url(), aidocs_search_results_url()), deliberately:
 * three plugins on one site should not ask an editor to learn three different
 * ways to say "the results are over there".
 *
 *   ''            Settings → Global Search → Results Page
 *   "123"         a page id
 *   "/search/"    a site-relative path
 *   an absolute URL, but only one on this site — an off-site value is
 *                 ignored rather than honoured, so a typo in a shortcode
 *                 attribute cannot send a visitor's search somewhere else.
 *
 * With nothing configured and nothing passed, this returns '' rather than
 * guessing. The caller decides what that means: the compact box falls back to
 * WordPress's own /?s= search, which is never wrong, only plainer.
 */
function gsearch_results_url( string $target = '' ): string {
	$target = trim( $target );

	if ( $target === '' ) {
		$page_id = (int) ( gsearch_get_settings()['results_page'] ?? 0 );
		if ( $page_id > 0 && get_post_status( $page_id ) === 'publish' ) {
			return (string) get_permalink( $page_id );
		}
		return '';
	}

	if ( ctype_digit( $target ) ) {
		$id = (int) $target;
		return ( $id > 0 && get_post_status( $id ) === 'publish' )
			? (string) get_permalink( $id )
			: gsearch_results_url();
	}

	if ( preg_match( '#^https?://#i', $target ) ) {
		$host = wp_parse_url( $target, PHP_URL_HOST );
		$home = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		return ( $host && $home && strcasecmp( $host, $home ) === 0 )
			? esc_url_raw( $target )
			: gsearch_results_url();
	}

	return esc_url_raw( home_url( '/' . ltrim( $target, '/' ) ) );
}

function gsearch_get_settings(): array {
	$stored = get_option( GSEARCH_OPTION, [] );
	if ( ! is_array( $stored ) ) {
		$stored = [];
	}

	$defaults = gsearch_default_settings();
	$settings = array_replace_recursive( $defaults, $stored );

	// array_replace_recursive would append rather than replace a provider's
	// individual keys if $stored only overrides one of them — fine here,
	// since 'enabled'/'label' are the only two keys and both are scalars.
	return $settings;
}

function gsearch_update_settings( array $settings ): void {
	update_option( GSEARCH_OPTION, $settings );
}

/**
 * A provider's configured label, falling back to the provider's own
 * get_label() when the admin has not overridden it — keeps the core
 * label-agnostic per provider while still allowing the "Policies"/"Site"
 * renames the settings screen exposes.
 */
function gsearch_provider_label( Global_Search_Provider $provider ): string {
	$settings = gsearch_get_settings();
	$override = $settings['providers'][ $provider->get_id() ]['label'] ?? '';
	return $override !== '' ? $override : $provider->get_label();
}

function gsearch_provider_enabled( string $provider_id ): bool {
	$settings = gsearch_get_settings();
	return (bool) ( $settings['providers'][ $provider_id ]['enabled'] ?? true );
}

/**
 * mtime-based cache-busting: a deploy or a local edit changes the file's
 * mtime, so browsers never serve a stale asset just because the plugin
 * version header did not change.
 */
function gsearch_asset_version( string $relative_path ): string {
	$mtime = @filemtime( GSEARCH_DIR . $relative_path );
	return $mtime ? GSEARCH_VERSION . '.' . $mtime : GSEARCH_VERSION;
}

/**
 * Defensive shape-enforcement for whatever a provider returns. A provider
 * (ours or a future third-party one registered via the filter) might omit a
 * key or hand back raw HTML in an excerpt; this is the one place that turns
 * that into something safe to render, so providers themselves can stay
 * simple.
 */
function gsearch_normalize_result( array $result, string $fallback_source ): array {
	$excerpt = (string) ( $result['excerpt'] ?? '' );
	$excerpt = wp_strip_all_tags( $excerpt );
	// wp_strip_all_tags() removes markup but leaves entities like "&hellip;"
	// (get_the_excerpt()'s own "[&hellip;]" ending) as literal text; the
	// frontend writes this straight into textContent (deliberately, to stay
	// safe from injected HTML), which never decodes entities — so without
	// this, visitors would see the literal string "&hellip;" in results.
	$excerpt = html_entity_decode( $excerpt, ENT_QUOTES, 'UTF-8' );

	return [
		'source'  => sanitize_key( (string) ( $result['source'] ?? $fallback_source ) ),
		'type'    => sanitize_key( (string) ( $result['type'] ?? 'item' ) ),
		'title'   => sanitize_text_field( (string) ( $result['title'] ?? '' ) ),
		'url'     => esc_url_raw( (string) ( $result['url'] ?? '' ) ),
		'excerpt' => sanitize_textarea_field( $excerpt ),
		'meta'    => is_array( $result['meta'] ?? null ) ? $result['meta'] : [],
		'score'   => is_numeric( $result['score'] ?? null ) ? (float) $result['score'] : 0.0,
	];
}
