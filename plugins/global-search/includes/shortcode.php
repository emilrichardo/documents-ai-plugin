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

// 'init', not 'wp_enqueue_scripts': the latter never fires in wp-admin, so
// registering there left the block editor with no idea this stylesheet
// existed and the block preview rendered unstyled. Registering (not
// enqueuing) on 'init' works in both contexts; includes/blocks.php then
// hands the 'global-search' style handle to register_block_type()'s own
// 'style' argument, which is what actually gets WordPress to load it in the
// editor iframe as well as the front end.
add_action( 'init', 'gsearch_register_assets' );
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
			'resultsFound'  => __( 'results found', 'global-search' ),
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
		'shape'             => 'rectangular',
	], $atts, 'global_search' );

	return gsearch_render( $atts );
}

/**
 * Shared renderer for the shortcode and the block. $atts uses the same keys
 * as the shortcode's own attributes (strings, as shortcodes always give
 * them) so both call sites can pass through unmodified.
 *
 * Results render into a dropdown panel anchored under the input (like a
 * typeahead), not an always-visible block on the page — closed and empty
 * until a search actually returns something. There is no static "No
 * results" placeholder in this markup at all: an empty state is announced
 * to screen readers only (assets/js/global-search.js writes it into
 * .global-search__status, which is visually hidden but aria-live), never
 * shown as a visible line under the box.
 */
function gsearch_render( array $atts ): string {
	gsearch_enqueue_assets();

	$show_filters = ! in_array( strtolower( (string) $atts['show_filters'] ), [ 'no', 'false', '0', '' ], true );
	$variant      = in_array( $atts['variant'], [ 'default', 'compact' ], true ) ? $atts['variant'] : 'default';
	$shape        = in_array( $atts['shape'] ?? '', [ 'rectangular', 'rounded' ], true ) ? $atts['shape'] : 'rectangular';

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
		class="global-search global-search--<?php echo esc_attr( $variant ); ?> global-search--<?php echo esc_attr( $shape ); ?>"
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
				role="combobox"
				aria-expanded="false"
				aria-haspopup="listbox"
				autocomplete="off"
			/>
			<button type="submit" class="global-search__submit">
				<span class="global-search__submit-icon" aria-hidden="true"></span>
				<span class="global-search__submit-label"><?php esc_html_e( 'Search', 'global-search' ); ?></span>
			</button>
		</form>

		<div class="global-search__panel" hidden>
			<?php if ( $show_filters ) : ?>
				<div class="global-search__filters" role="tablist" aria-label="<?php esc_attr_e( 'Filter results by source', 'global-search' ); ?>" hidden></div>
			<?php endif; ?>

			<p class="global-search__status screen-reader-text" aria-live="polite"></p>
			<div class="global-search__results" role="listbox" aria-label="<?php esc_attr_e( 'Search results', 'global-search' ); ?>"></div>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}
