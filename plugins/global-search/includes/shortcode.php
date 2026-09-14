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
			'viewAll'       => __( 'View all results', 'global-search' ),
			'noResultsLine' => __( 'No results found', 'global-search' ),
			'errorLine'     => __( 'Search is temporarily unavailable.', 'global-search' ),
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
		'button_label'      => '',
		// Where the form goes when it is submitted. See gsearch_results_url().
		'results_url'       => '',
		// How the results are presented. See gsearch_results_mode().
		'results'           => 'auto',
		// The dropdown's row cap. Empty means "whatever the variant wants".
		'max_results'       => '',
		// Whether the dropdown ends with a link to the full results page.
		// Empty means "yes, if there is a page to link to".
		'view_all'          => '',
	], $atts, 'global_search' );

	return gsearch_render( $atts );
}

/**
 * How the results are presented.
 *
 *   dropdown  a typeahead: a panel floating under the input, closed until a
 *             search returns something, and nothing on the page moves to make
 *             room for it. Right for a search box that is not itself the point
 *             of the page — a header, a hero, a sidebar.
 *   inline    the results page: the panel is ordinary page content, open, with
 *             its source filters showing and a visible empty state. Right where
 *             the results *are* the page.
 *   auto      (the default) inline when the request carries ?q= and this is not
 *             the compact variant, dropdown otherwise. That is what lets a bare
 *             [global_search] on /search/?q=accreditation be a results page
 *             while the same bare shortcode anywhere else stays a search box.
 *
 * Compact is always a dropdown: it exists to live in a header, which is not
 * somewhere a list of results can open into.
 */
function gsearch_results_mode( string $requested, string $variant ): string {
	$requested = strtolower( trim( $requested ) );

	if ( $variant === 'compact' ) {
		return 'dropdown';
	}
	if ( in_array( $requested, [ 'dropdown', 'inline' ], true ) ) {
		return $requested;
	}

	// `q` only, never `s`. `q` is this plugin's own parameter, so its presence
	// is an unambiguous "this URL is a Global Search results URL". `s` belongs
	// to WordPress and turns up on any page a theme's own search box submits
	// to; a search box elsewhere on such a page should stay a search box.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public, read-only search
	return isset( $_GET['q'] ) && trim( (string) $_GET['q'] ) !== '' ? 'inline' : 'dropdown';
}

/**
 * The search in the URL, if there is one — for prefilling the field.
 *
 * `q` first, then WordPress's own `s`, so a box that falls back to core search
 * (see gsearch_render()) still comes back with the visitor's words in it, and
 * so does one dropped onto the theme's own search results template.
 */
function gsearch_request_query(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public, read-only search
	$raw = $_GET['q'] ?? $_GET['s'] ?? '';
	return trim( sanitize_text_field( wp_unslash( (string) $raw ) ) );
}

/**
 * Shared renderer for the shortcode and the block. $atts uses the same keys
 * as the shortcode's own attributes (strings, as shortcodes always give
 * them) so both call sites can pass through unmodified.
 *
 * ── The form is a real form ────────────────────────────────────────────────
 *
 * `method="get"`, an `action`, and an input named `q`. Submitting it is an
 * ordinary navigation to the results page, which is what makes the header's
 * GO button — and the Enter key — work when the script has not loaded, has
 * thrown, or is still fetching. The dropdown is an enhancement layered on top
 * of that and is never the only way through.
 *
 * The one exception is the legacy in-page dropdown (`results="dropdown"` with
 * no results page anywhere to submit to), where there is nowhere to navigate
 * and the script handles submit itself. `data-submit` on the wrapper tells the
 * script which of the two it is looking at, so that decision lives here rather
 * than being re-derived in JavaScript.
 */
function gsearch_render( array $atts ): string {
	gsearch_enqueue_assets();

	$show_filters = ! in_array( strtolower( (string) $atts['show_filters'] ), [ 'no', 'false', '0', '' ], true );
	$variant      = in_array( $atts['variant'], [ 'default', 'compact' ], true ) ? $atts['variant'] : 'default';
	$shape        = in_array( $atts['shape'] ?? '', [ 'rectangular', 'rounded' ], true ) ? $atts['shape'] : 'rectangular';
	$mode         = gsearch_results_mode( (string) ( $atts['results'] ?? 'auto' ), $variant );

	$sources = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', (string) $atts['sources'] ) ) ) );

	$settings         = gsearch_get_settings();
	$results_per_page = $atts['results_per_page'] !== '' ? (int) $atts['results_per_page'] : (int) $settings['results_per_page'];
	$results_per_page = max( 1, min( 50, $results_per_page ) );

	// The dropdown's own, smaller cap. A header typeahead has a few hundred
	// pixels of safe height, not a page; the rest of the matches are what the
	// results page is for. Inline mode has a page, so it shows everything the
	// service returned.
	$max_results = $atts['max_results'] !== ''
		? max( 1, min( 50, (int) $atts['max_results'] ) )
		: ( $variant === 'compact' ? max( 1, (int) $settings['compact_max_results'] ) : $results_per_page );

	$results_url = gsearch_results_url( (string) ( $atts['results_url'] ?? '' ) );

	// Nowhere configured to send a search, and this box is one a visitor can
	// submit: fall back to WordPress's own search rather than a dead button.
	// Plainer than the results page, never broken.
	$action = $results_url !== '' ? $results_url : home_url( '/' );
	$fallback_to_wp_search = $results_url === '';

	// …and the field is named for wherever it is going, so the fallback is a
	// working search rather than a parameter core does not read.
	$query_param = $fallback_to_wp_search ? 's' : 'q';

	// The one case with nothing to navigate to and no reason to: the classic
	// in-page typeahead on a page that is not a results page.
	$submit_mode = ( $mode === 'dropdown' && $fallback_to_wp_search && $variant !== 'compact' )
		? 'search'
		: 'navigate';

	$view_all = $atts['view_all'] !== ''
		? ! in_array( strtolower( (string) $atts['view_all'] ), [ 'no', 'false', '0' ], true )
		: ( $mode === 'dropdown' && $results_url !== '' );

	$placeholder = $atts['placeholder'] !== ''
		? $atts['placeholder']
		: ( $variant === 'compact' ? __( 'Search site…', 'global-search' ) : __( 'Search…', 'global-search' ) );

	// The button says "Search" unless told otherwise. Worth being able to say
	// something else: the header this is a candidate to replace has said "GO"
	// on its button for as long as the site has existed, and a replacement
	// that quietly renames a control visitors already know is a worse
	// replacement, however much better the results behind it are.
	$button_label = (string) $atts['button_label'] !== ''
		? (string) $atts['button_label']
		: __( 'Search', 'global-search' );

	$instance_id = 'gsearch-' . wp_unique_id();
	$panel_id    = $instance_id . '-panel';
	$results_id  = $instance_id . '-results';

	$query = gsearch_request_query();

	ob_start();
	?>
	<div
		class="global-search global-search--<?php echo esc_attr( $variant ); ?> global-search--<?php echo esc_attr( $shape ); ?> global-search--<?php echo esc_attr( $mode ); ?>"
		data-global-search
		data-sources="<?php echo esc_attr( implode( ',', $sources ) ); ?>"
		data-results-per-page="<?php echo esc_attr( (string) $results_per_page ); ?>"
		data-max-results="<?php echo esc_attr( (string) $max_results ); ?>"
		data-show-filters="<?php echo esc_attr( $show_filters ? '1' : '0' ); ?>"
		data-mode="<?php echo esc_attr( $mode ); ?>"
		data-submit="<?php echo esc_attr( $submit_mode ); ?>"
		data-results-url="<?php echo esc_attr( $results_url ); ?>"
		data-variant="<?php echo esc_attr( $variant ); ?>"
	>
		<form class="global-search__form" role="search" method="get"
		      action="<?php echo esc_url( $action ); ?>" autocomplete="off">
			<label class="global-search__label screen-reader-text" for="<?php echo esc_attr( $instance_id ); ?>">
				<?php esc_html_e( 'Search', 'global-search' ); ?>
			</label>
			<input
				type="search"
				name="<?php echo esc_attr( $query_param ); ?>"
				id="<?php echo esc_attr( $instance_id ); ?>"
				class="global-search__input"
				value="<?php echo esc_attr( $query ); ?>"
				placeholder="<?php echo esc_attr( $placeholder ); ?>"
				role="combobox"
				aria-expanded="false"
				aria-haspopup="listbox"
				aria-autocomplete="list"
				aria-controls="<?php echo esc_attr( $results_id ); ?>"
				autocomplete="off"
			/>
			<button type="submit" class="global-search__submit" aria-label="<?php esc_attr_e( 'Search', 'global-search' ); ?>">
				<span class="global-search__submit-icon" aria-hidden="true"></span>
				<span class="global-search__submit-label"><?php echo esc_html( $button_label ); ?></span>
			</button>
		</form>

		<div class="global-search__panel" id="<?php echo esc_attr( $panel_id ); ?>" <?php echo $mode === 'inline' ? '' : 'hidden'; ?>>
			<?php if ( $show_filters ) : ?>
				<div class="global-search__filters" role="tablist" aria-label="<?php esc_attr_e( 'Filter results by source', 'global-search' ); ?>" hidden></div>
			<?php endif; ?>

			<?php
			// Announced to everyone in inline mode, where there is a page to
			// say it on, and to screen readers only in the dropdown, where a
			// line of copy under a header would be noise. One element either
			// way, so the script never has to know which it is writing to.
			?>
			<p class="global-search__status<?php echo $mode === 'inline' ? '' : ' screen-reader-text'; ?>" aria-live="polite"></p>
			<div class="global-search__results" id="<?php echo esc_attr( $results_id ); ?>" role="listbox" aria-label="<?php esc_attr_e( 'Search results', 'global-search' ); ?>"></div>

			<?php if ( $view_all ) : ?>
				<?php
				// A real link with a real href, written server-side and only
				// re-pointed by the script as the query changes — so it is
				// followable the moment it is visible, script or no script.
				?>
				<a class="global-search__view-all" hidden
				   href="<?php echo esc_url( add_query_arg( 'q', $query, $results_url ) ); ?>"
				   data-global-search-view-all>
					<?php esc_html_e( 'View all results', 'global-search' ); ?> <span aria-hidden="true">&rarr;</span>
				</a>
			<?php endif; ?>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}
