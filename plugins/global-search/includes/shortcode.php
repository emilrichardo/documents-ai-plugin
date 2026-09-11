<?php
/**
 * [global_search] and the render_callback the Gutenberg block uses
 * (includes/blocks.php) both call gsearch_render() below — one markup
 * source, so a shortcode and the block can never drift apart. Rendering
 * shows only the search box and an empty "No results yet" state; nothing is
 * queried until the visitor actually searches (requirement #13), so this
 * function never touches the search service at all.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_enqueue_scripts', 'gsearch_register_assets' );
function gsearch_register_assets(): void {
	wp_register_style(
		'global-search',
		GSEARCH_URL . 'assets/css/global-search.css',
		[],
		gsearch_asset_version( 'assets/css/global-search.css' )
	);

	wp_register_script(
		'global-search',
		GSEARCH_URL . 'assets/js/global-search.js',
		[],
		gsearch_asset_version( 'assets/js/global-search.js' ),
		true
	);
}

/**
 * Enqueues + localizes on first use per request rather than unconditionally
 * on every page — most pages will not carry the search box at all.
 */
function gsearch_enqueue_assets(): void {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	wp_enqueue_style( 'global-search' );
	wp_enqueue_script( 'global-search' );

	$settings = gsearch_get_settings();

	wp_localize_script( 'global-search', 'globalSearchConfig', [
		'restUrl'       => esc_url_raw( rest_url( 'global-search/v1/' ) ),
		'liveSearch'    => (bool) $settings['live_search'],
		'minCharacters' => max( 1, (int) $settings['min_characters'] ),
		'debounceMs'    => max( 0, (int) $settings['debounce_ms'] ),
		'i18n'          => [
			'searchLabel'   => __( 'Search', 'global-search' ),
			'searchButton'  => __( 'Search', 'global-search' ),
			'noResults'     => __( 'No results', 'global-search' ),
			'loading'       => __( 'Searching…', 'global-search' ),
			'error'         => __( 'Something went wrong. Please try again.', 'global-search' ),
			'all'           => __( 'All', 'global-search' ),
			'viewPrefix'    => __( 'View', 'global-search' ),
		],
	] );
}

add_shortcode( 'global_search', 'gsearch_shortcode' );
function gsearch_shortcode( $atts ): string {
	$atts = shortcode_atts( [
		'show_filters'      => 'yes',
		'sources'           => '',
		'results_per_page'  => '',
		'variant'           => 'default',
		'placeholder'       => '',
	], $atts, 'global_search' );

	return gsearch_render( $atts );
}

/**
 * Shared renderer for the shortcode and the block. $atts uses the same keys
 * as the shortcode's own attributes (strings, as shortcodes always give
 * them) so both call sites can pass through unmodified.
 */
function gsearch_render( array $atts ): string {
	gsearch_enqueue_assets();

	$show_filters = ! in_array( strtolower( (string) $atts['show_filters'] ), [ 'no', 'false', '0', '' ], true );
	$variant      = in_array( $atts['variant'], [ 'default', 'compact' ], true ) ? $atts['variant'] : 'default';

	$sources = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', (string) $atts['sources'] ) ) ) );

	$settings         = gsearch_get_settings();
	$results_per_page = $atts['results_per_page'] !== '' ? (int) $atts['results_per_page'] : (int) $settings['results_per_page'];
	$results_per_page = max( 1, min( 50, $results_per_page ) );

	$placeholder = $atts['placeholder'] !== ''
		? $atts['placeholder']
		: ( $variant === 'compact' ? __( 'Search site…', 'global-search' ) : __( 'Search…', 'global-search' ) );

	$instance_id = 'gsearch-' . wp_unique_id();

	ob_start();
	?>
	<div
		class="global-search global-search--<?php echo esc_attr( $variant ); ?>"
		data-global-search
		data-sources="<?php echo esc_attr( implode( ',', $sources ) ); ?>"
		data-results-per-page="<?php echo esc_attr( (string) $results_per_page ); ?>"
		data-show-filters="<?php echo esc_attr( $show_filters ? '1' : '0' ); ?>"
	>
		<form class="global-search__form" role="search" action="#" autocomplete="off">
			<label class="global-search__label screen-reader-text" for="<?php echo esc_attr( $instance_id ); ?>">
				<?php esc_html_e( 'Search', 'global-search' ); ?>
			</label>
			<input
				type="search"
				id="<?php echo esc_attr( $instance_id ); ?>"
				class="global-search__input"
				placeholder="<?php echo esc_attr( $placeholder ); ?>"
			/>
			<button type="submit" class="global-search__submit">
				<?php esc_html_e( 'Search', 'global-search' ); ?>
			</button>
		</form>

		<?php if ( $show_filters ) : ?>
			<div class="global-search__filters" role="tablist" aria-label="<?php esc_attr_e( 'Filter results by source', 'global-search' ); ?>" hidden></div>
		<?php endif; ?>

		<p class="global-search__status" aria-live="polite"></p>
		<div class="global-search__results" aria-live="polite">
			<p class="global-search__empty"><?php esc_html_e( 'No results', 'global-search' ); ?></p>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}
