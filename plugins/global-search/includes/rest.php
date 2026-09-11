<?php
/**
 * GET /wp-json/global-search/v1/search — public, read-only. This is the
 * first REST route in the monorepo (both other plugins use AJAX/GET-form
 * instead); it was chosen here because the brief calls for it explicitly and
 * because a stateless GET search endpoint is exactly what REST is for.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', 'gsearch_register_rest_routes' );
function gsearch_register_rest_routes(): void {
	register_rest_route( 'global-search/v1', '/search', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'gsearch_rest_search',
		'permission_callback' => '__return_true',
		'args'                => [
			'q' => [
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'source' => [
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
			],
			'sources' => [
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'results_per_page' => [
				'type'              => 'integer',
				'default'           => 0, // 0 = use the configured default
				'sanitize_callback' => 'absint',
			],
		],
	] );

	register_rest_route( 'global-search/v1', '/providers', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'gsearch_rest_providers',
		'permission_callback' => '__return_true',
	] );
}

function gsearch_rest_search( WP_REST_Request $request ) {
	$query = (string) $request->get_param( 'q' );

	// ?source=documents (singular) or ?sources=documents,institutions
	// (plural, comma-separated) — accept either, per the brief's own two
	// examples.
	$sources = [];
	$single  = (string) $request->get_param( 'source' );
	$plural  = (string) $request->get_param( 'sources' );

	if ( $single !== '' ) {
		$sources[] = $single;
	}
	if ( $plural !== '' ) {
		foreach ( explode( ',', $plural ) as $source ) {
			$source = sanitize_key( trim( $source ) );
			if ( $source !== '' ) {
				$sources[] = $source;
			}
		}
	}

	$args = [];
	if ( ! empty( $sources ) ) {
		$args['sources'] = array_unique( $sources );
	}

	$per_page = (int) $request->get_param( 'results_per_page' );
	if ( $per_page > 0 ) {
		$args['results_per_page'] = $per_page;
	}

	$response = gsearch_service()->search( $query, $args );

	return new WP_REST_Response( $response, 200 );
}

function gsearch_rest_providers( WP_REST_Request $request ) {
	return new WP_REST_Response( [
		'providers' => gsearch_service()->get_provider_metadata(),
	], 200 );
}
