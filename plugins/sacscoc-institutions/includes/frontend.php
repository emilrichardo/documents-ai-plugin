<?php
/**
 * The public directory: the shortcode, the institution URLs, and the templates.
 *
 * Integration is deliberately shallow. The theme keeps ownership of the page —
 * header, footer, typography, width — and this plugin contributes the content
 * and a small stylesheet that inherits from it. That means:
 *
 *   [sacscoc_institutions]   dropped on any WordPress page, which becomes the
 *                            directory: search form, results, pagination.
 *
 *   /institutions/<slug>/    one institution, rendered inside the theme's
 *                            get_header() / get_footer().
 *
 * Both are templates under templates/, and a theme can override either by
 * putting a file of the same name in `sacscoc-institutions/` in the theme.
 *
 * ── Why the institution URLs need care ─────────────────────────────────────
 *
 * The real site has ordinary WordPress pages living under the same base:
 * /institutions/third-party-comments/ and
 * /institutions/accreditation-actions-and-disclosures/. A rewrite rule matching
 * `institutions/([^/]+)` swallows those too, and adding the rule at the bottom
 * instead loses to WordPress's page catch-all so it would never fire at all.
 *
 * So the rule is added at the top and then stood down: if the captured slug is
 * not an institution we hold, sacscoc_inst_release_unmatched() hands the request
 * back to WordPress as a page lookup. Real child pages keep working, and adding
 * one later needs no thought.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** The URL segment institution pages live under. Configurable in Settings. */
function sacscoc_inst_rewrite_base(): string {
    $base = (string) get_option( 'sacscoc_inst_rewrite_base', 'institutions' );
    $base = trim( sanitize_title( $base ) );
    return $base !== '' ? $base : 'institutions';
}

// ──────────────────────────────────────────────
// Routing
// ──────────────────────────────────────────────

add_action( 'init', 'sacscoc_inst_add_rewrite_rules' );
function sacscoc_inst_add_rewrite_rules(): void {
    add_rewrite_rule(
        '^' . sacscoc_inst_rewrite_base() . '/([^/]+)/?$',
        'index.php?sacscoc_institution=$matches[1]',
        'top'
    );
}

add_filter( 'query_vars', 'sacscoc_inst_query_vars' );
function sacscoc_inst_query_vars( array $vars ): array {
    $vars[] = 'sacscoc_institution';
    return $vars;
}

/**
 * Give the request back to WordPress when the slug is not one of ours.
 *
 * Runs before the main query, so rewriting `sacscoc_institution` into `pagename`
 * lets WordPress resolve the URL as the page it actually is.
 */
add_action( 'parse_request', 'sacscoc_inst_release_unmatched' );
function sacscoc_inst_release_unmatched( WP $wp ): void {
    $slug = $wp->query_vars['sacscoc_institution'] ?? '';
    if ( $slug === '' ) return;

    if ( sacscoc_inst_get_by_slug( sanitize_title( (string) $slug ) ) !== null ) return;

    unset( $wp->query_vars['sacscoc_institution'] );
    $wp->query_vars['pagename'] = sacscoc_inst_rewrite_base() . '/' . $slug;
}

/**
 * Flush the rewrite rules once, after the rule above has been registered.
 *
 * Activation runs before `init`, so flushing there would write a rule set that
 * does not yet contain ours. A one-shot flag on the next load does it properly —
 * which also covers a deploy over SFTP, where no activation hook ever fires.
 */
add_action( 'init', 'sacscoc_inst_maybe_flush_rules', 20 );
function sacscoc_inst_maybe_flush_rules(): void {
    if ( get_option( 'sacscoc_inst_flush_rules' ) !== '1' ) return;

    delete_option( 'sacscoc_inst_flush_rules' );
    flush_rewrite_rules( false );
}

/** Ask for a flush on the next load. Called on activation and when the base changes. */
function sacscoc_inst_request_flush(): void {
    update_option( 'sacscoc_inst_flush_rules', '1', false );
}

add_action( 'update_option_sacscoc_inst_rewrite_base', 'sacscoc_inst_request_flush', 10, 0 );

// ──────────────────────────────────────────────
// Rendering the institution page
// ──────────────────────────────────────────────

/**
 * The institution the current request is for, or null.
 *
 * Resolved once and reused by the title filter, the body class and the template.
 */
function sacscoc_inst_current(): ?array {
    static $resolved = false;
    static $row      = null;

    if ( $resolved ) return $row;
    $resolved = true;

    $slug = get_query_var( 'sacscoc_institution' );
    if ( ! $slug ) return null;

    $row = sacscoc_inst_get_by_slug( sanitize_title( (string) $slug ) );
    return $row;
}

/**
 * Make the request a 200 and remember that it is ours.
 *
 * The rewrite rule matched a real institution, so this is not the 404 that
 * WordPress would otherwise conclude from having found no post. Runs on
 * template_redirect, which fires before the template_include filter below.
 */
add_action( 'template_redirect', 'sacscoc_inst_claim_request' );
function sacscoc_inst_claim_request(): void {
    if ( sacscoc_inst_current() === null ) return;

    global $wp_query;
    $wp_query->is_404 = false;
    status_header( 200 );
}

/**
 * Serve the plugin's own template for an institution page.
 *
 * template_include is the right hook even on a block theme: it is the last
 * filter WordPress applies before deciding which file governs the request.
 * Priority PHP_INT_MAX because page-builder plugins (Elementor Pro's Theme
 * Builder, for one) also filter it at the default priority and would otherwise
 * replace this template with a generic post layout.
 */
add_filter( 'template_include', 'sacscoc_inst_template_include', PHP_INT_MAX );
function sacscoc_inst_template_include( $template ) {
    if ( sacscoc_inst_current() === null ) return $template;

    $found = locate_template( [ 'sacscoc-institutions/single-institution.php' ] );
    return $found ?: SACSCOC_INST_DIR . 'templates/single-institution.php';
}

/**
 * Render a theme template part by slug, or nothing if the theme has none.
 *
 * @param string $slug e.g. 'header'
 * @param string $tag  the wrapping tag the part should use
 */
function sacscoc_inst_render_template_part( string $slug, string $tag ): void {
    echo render_block( [
        'blockName' => 'core/template-part',
        'attrs'     => [ 'slug' => $slug, 'tagName' => $tag, 'theme' => get_stylesheet() ],
        'innerHTML' => '',
    ] );
}

/**
 * Open the document and print the theme's real header.
 *
 * A block theme ships no header.php, so get_header() finds nothing in the theme
 * and falls through to WordPress's own wp-includes/theme-compat/header.php — a
 * deprecated, unstyled stub of a bare site title and an <hr>, with nothing of
 * the active theme's design in it. That is what an institution page looked like
 * before this existed. So on a block theme we print the document wrapper
 * ourselves, exactly as core's template-canvas.php does, and pull in the
 * theme's actual header through its "header" template part. A classic theme's
 * header.php already does the right thing and is left alone.
 *
 * The same reasoning, and the same shape, as AI Documents' own
 * aidocs_document_header() — the two plugins share no code, but this problem
 * has one correct answer and it is worth them behaving identically.
 */
function sacscoc_inst_page_header(): void {
    if ( ! wp_is_block_theme() ) {
        get_header();
        return;
    }
    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <?php wp_head(); ?>
    </head>
    <body <?php body_class(); ?>>
    <?php wp_body_open(); ?>
    <?php
    sacscoc_inst_render_template_part( 'header', 'header' );
}

/** Print the theme's footer and close the document opened above. */
function sacscoc_inst_page_footer(): void {
    if ( ! wp_is_block_theme() ) {
        get_footer();
        return;
    }
    sacscoc_inst_render_template_part( 'footer', 'footer' );
    ?>
    <?php wp_footer(); ?>
    </body>
    </html>
    <?php
}

/**
 * Page-scoped layout CSS.
 *
 * One job: give both pages the theme's own measure and the theme's own air
 * around them. `<main id="sacscoc-single">` stands in for the theme's content
 * column — a classic theme's layout wrapper is often a flex row sized around
 * that column, so without a width our <main> shrinks to its content and sits
 * flush left. This makes it fill the row, adds the gutters the theme would have
 * provided, and gives the record the same generous top and bottom space the
 * theme's own sections have.
 *
 * The stylesheet handles everything else: it reads the theme's Elementor
 * globals for colour and type, so there is nothing to compute here.
 */
add_action( 'wp_head', 'sacscoc_inst_layout_css' );
function sacscoc_inst_layout_css(): void {
    $single    = sacscoc_inst_current() !== null;
    $directory = sacscoc_inst_is_directory_page();

    if ( ! $single && ! $directory ) return;

    /**
     * How wide the directory and the institution record may get.
     *
     * 1200px is the theme's own container width, so the directory lines up
     * with every other section of the site instead of sitting at a measure of
     * its own. A theme that wants a different one can filter this or set the
     * max-width on #sacscoc-directory itself.
     *
     * @param string $width any CSS length
     */
    $width = (string) apply_filters( 'sacscoc_inst_max_width', 'min(1200px, 100%)' );
    ?>
    <style id="sacscoc-institutions-layout">
    <?php if ( $single ) : ?>
    /* Stand in for the theme's content column: a classic theme's layout wrapper
       is often a flex row sized around it, and without this the <main> shrinks
       to its content and sits flush left. */
    #sacscoc-single{flex:1 1 100%;width:100%;max-width:100%;box-sizing:border-box;padding:clamp(40px,6vw,80px) clamp(16px,5vw,40px);}
    #sacscoc-single>.sacscoc-single{max-width:<?php echo esc_html( $width ); ?>;margin-left:auto;margin-right:auto;}
    <?php endif; ?>
    <?php if ( $directory ) : ?>
    /* The directory is two columns and needs more room than a theme's reading
       measure. A block theme's constrained layout caps every child of
       .entry-content at its contentSize — 740px here, which squeezes the search
       panel into a column of wrapped words. That cap is written entirely with
       :where(), so it carries no specificity and this id selector lifts it
       without !important and without touching the theme. */
    #sacscoc-directory{max-width:<?php echo esc_html( $width ); ?>;margin-left:auto;margin-right:auto;}
    <?php endif; ?>
    </style>
    <?php
}

/** True when the current page is the one carrying the directory shortcode. */
function sacscoc_inst_is_directory_page(): bool {
    if ( ! is_singular() && ! is_page() ) return false;

    $post = get_post();
    return $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'sacscoc_institutions' );
}

/** The institution's name as the document title. */
add_filter( 'document_title_parts', 'sacscoc_inst_document_title' );
function sacscoc_inst_document_title( array $parts ): array {
    $row = sacscoc_inst_current();
    if ( $row === null ) return $parts;

    $parts['title'] = sacscoc_inst_display_name( $row );
    unset( $parts['tagline'] );

    return $parts;
}

add_filter( 'body_class', 'sacscoc_inst_body_class' );
function sacscoc_inst_body_class( array $classes ): array {
    if ( sacscoc_inst_current() !== null ) {
        $classes[] = 'sacscoc-institution';
        $classes[] = 'sacscoc-institution-single';
    }
    return $classes;
}

// ──────────────────────────────────────────────
// The directory shortcode
// ──────────────────────────────────────────────

/**
 * The two layouts the directory can be rendered in.
 *
 * Both render the same markup from the same template — the difference is where
 * the search sits, and it is a class on the wrapper, not a second set of
 * templates to keep in step:
 *
 *   two-column  results left, search panel right. The layout the existing
 *               sacscoc.org directory uses.
 *   one-column  a horizontal search bar across the top and the results full
 *               width beneath it, as on the site's own Find an Institution
 *               page (/students-families/find-an-instituition/).
 */
function sacscoc_inst_layouts(): array {
    return [
        'two-column' => __( 'Two columns — results left, search panel right', 'sacscoc-institutions' ),
        'one-column' => __( 'One column — search bar across the top, results below', 'sacscoc-institutions' ),
    ];
}

/** The configured layout, or the two-column default. */
function sacscoc_inst_layout(): string {
    return sacscoc_inst_clean_layout( get_option( 'sacscoc_inst_layout', 'two-column' ) );
}

/** A layout key we actually offer; anything else becomes the default. */
function sacscoc_inst_clean_layout( $value ): string {
    $value = sanitize_key( (string) $value );
    return isset( sacscoc_inst_layouts()[ $value ] ) ? $value : 'two-column';
}

add_shortcode( 'sacscoc_institutions', 'sacscoc_inst_directory_shortcode' );

/**
 * The directory: search form, results, pagination.
 *
 * Every filter is a GET parameter, so any result set is a shareable URL and the
 * browser's back button works. Prefixed `si_` so the parameters cannot collide
 * with the theme's or another plugin's.
 *
 * Attributes:
 *   layout     two-column or one-column. Left out, the Settings value is used.
 *   per_page   results per page. Left out, the Settings value is used — so the
 *              size is configured in one place and this is only for the odd
 *              page that wants a different one.
 *   show_count whether to print the result count
 *
 * Both are carried on the wrapper as data attributes and sent back with every
 * live filter, so a page that lists 50 keeps listing 50 after a search rather
 * than silently reverting to the default on the first keystroke.
 */
function sacscoc_inst_directory_shortcode( $atts ): string {
    $atts = shortcode_atts( [
        'layout'     => '',
        'per_page'   => '',
        'show_count' => 'yes',
    ], $atts, 'sacscoc_institutions' );

    if ( ! sacscoc_inst_tables_ready() ) {
        return '<p class="sacscoc-notice">' . esc_html__( 'The institutions directory is not available yet.', 'sacscoc-institutions' ) . '</p>';
    }

    $per_page = trim( (string) $atts['per_page'] ) !== ''
        ? sacscoc_inst_clamp_per_page( $atts['per_page'] )
        : sacscoc_inst_per_page();

    $layout = trim( (string) $atts['layout'] ) !== ''
        ? sacscoc_inst_clean_layout( $atts['layout'] )
        : sacscoc_inst_layout();

    $filters = sacscoc_inst_request_filters();

    $results = sacscoc_inst_search( array_merge( $filters, [
        'per_page' => $per_page,
    ] ) );

    sacscoc_inst_enqueue_styles();

    return sacscoc_inst_load_template( 'directory.php', [
        'results'    => $results,
        'filters'    => $filters,
        'layout'     => $layout,
        'per_page'   => $per_page,
        'show_count' => $atts['show_count'] !== 'no',
    ], true );
}

/**
 * The filters from the query string, sanitised and validated.
 *
 * A value that is not on the offered list is dropped rather than passed through,
 * so a hand-edited URL cannot produce a query nobody designed.
 */
function sacscoc_inst_request_filters(): array {
    $q     = isset( $_GET['si_q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['si_q'] ) ) : '';
    $state = isset( $_GET['si_state'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['si_state'] ) ) : '';
    $deg   = isset( $_GET['si_degree'] ) ? sanitize_key( wp_unslash( (string) $_GET['si_degree'] ) ) : '';
    $year  = isset( $_GET['si_year'] ) ? (int) $_GET['si_year'] : 0;
    $paged = isset( $_GET['si_page'] ) ? (int) $_GET['si_page'] : 1;

    return [
        'q'      => trim( $q ),
        'state'  => isset( sacscoc_inst_states()[ $state ] ) ? $state : '',
        'degree' => isset( sacscoc_inst_degrees()[ $deg ] ) ? $deg : '',
        'year'   => in_array( $year, sacscoc_inst_reaffirm_years(), true ) ? (string) $year : '',
        'paged'  => max( 1, $paged ),
    ];
}

/** True when any filter is active — drives whether the Reset link is offered. */
function sacscoc_inst_has_filters( array $filters ): bool {
    return $filters['q'] !== '' || $filters['state'] !== ''
        || $filters['degree'] !== '' || $filters['year'] !== '';
}

/** The current URL with one filter changed, for pagination and Reset. */
function sacscoc_inst_filter_url( array $filters, array $changes = [] ): string {
    $filters = array_merge( $filters, $changes );

    $args = [];
    if ( $filters['q'] !== '' )      $args['si_q']      = $filters['q'];
    if ( $filters['state'] !== '' )  $args['si_state']  = $filters['state'];
    if ( $filters['degree'] !== '' ) $args['si_degree'] = $filters['degree'];
    if ( $filters['year'] !== '' )   $args['si_year']   = $filters['year'];

    // `paged` is compared as a string, not cast to int, because paginate_links()
    // needs a placeholder ("__PAGE__") to survive this function and come back
    // out in the URL. Casting would turn it into 0 and drop the parameter, and
    // every pagination link would point at page one.
    $paged = (string) $filters['paged'];
    if ( $paged !== '' && $paged !== '1' ) $args['si_page'] = $paged;

    $base = sacscoc_inst_directory_url();

    return $args ? add_query_arg( $args, $base ) : $base;
}

/**
 * The URL of the page holding the shortcode.
 *
 * Taken from the current permalink when we are on it, which is the normal case.
 * The stored Directory Page setting is the fallback, and is what the institution
 * pages use for their "Back to results" link — they are not WordPress pages, so
 * there is no permalink to read.
 */
function sacscoc_inst_directory_url(): string {
    if ( is_singular() || is_page() ) {
        $id = get_queried_object_id();
        if ( $id ) return (string) apply_filters( 'sacscoc_inst_directory_url', get_permalink( $id ) );
    }

    $page_id = (int) get_option( 'sacscoc_inst_directory_page', 0 );
    if ( $page_id && get_post_status( $page_id ) === 'publish' ) {
        return (string) apply_filters( 'sacscoc_inst_directory_url', get_permalink( $page_id ) );
    }

    return (string) apply_filters( 'sacscoc_inst_directory_url', home_url( '/' ) );
}

// ──────────────────────────────────────────────
// Templates and assets
// ──────────────────────────────────────────────

/**
 * Load a template, letting the theme override it.
 *
 * A theme can replace either template by dropping a file of the same name in
 * `sacscoc-institutions/` in the theme or child theme, which is the same
 * convention WooCommerce and friends use, so it needs no explaining.
 *
 * @param bool $return capture the output instead of printing it
 */
function sacscoc_inst_load_template( string $file, array $vars = [], bool $return = false ): string {
    $found = locate_template( [ 'sacscoc-institutions/' . $file ] );
    $path  = $found ?: SACSCOC_INST_DIR . 'templates/' . $file;

    if ( ! is_readable( $path ) ) return '';

    // Named for the template's own use; the templates document what they expect.
    extract( $vars, EXTR_SKIP );   // phpcs:ignore WordPress.PHP.DontExtract

    if ( ! $return ) {
        require $path;
        return '';
    }

    ob_start();
    require $path;
    return (string) ob_get_clean();
}

/**
 * The directory stylesheet.
 *
 * Registered always, enqueued only on the pages that render the directory.
 * Hooked on wp_enqueue_scripts rather than left to the shortcode, so the
 * stylesheet lands in <head> with everything else instead of being appended in
 * the footer after the markup it styles has already painted.
 *
 * The shortcode still calls this as a backstop, for the case where the
 * directory is rendered somewhere sacscoc_inst_is_directory_page() cannot see
 * it — inside a widget, a template part, or another shortcode.
 */
/**
 * Version string for an asset, taken from the file's own modification time.
 *
 * The plugin version alone is not enough: editing a stylesheet without bumping
 * the plugin leaves every browser serving the old file from cache, which looks
 * exactly like the CSS not working. mtime changes whenever the file does, so a
 * deploy or an edit busts the cache on its own.
 *
 * Falls back to the plugin version if the file cannot be stat'd.
 */
function sacscoc_inst_asset_version( string $relative_path ): string {
    $mtime = @filemtime( SACSCOC_INST_DIR . $relative_path );
    return $mtime ? SACSCOC_INST_VERSION . '.' . $mtime : SACSCOC_INST_VERSION;
}

add_action( 'wp_enqueue_scripts', 'sacscoc_inst_register_styles' );
function sacscoc_inst_register_styles(): void {
    wp_register_style(
        'sacscoc-institutions',
        SACSCOC_INST_URL . 'assets/css/sacscoc-institutions.css',
        [],
        sacscoc_inst_asset_version( 'assets/css/sacscoc-institutions.css' )
    );

    if ( sacscoc_inst_current() !== null || sacscoc_inst_is_directory_page() ) {
        wp_enqueue_style( 'sacscoc-institutions' );
    }
}

function sacscoc_inst_enqueue_styles(): void {
    if ( wp_style_is( 'sacscoc-institutions', 'enqueued' ) ) return;

    // Registered on wp_enqueue_scripts; if that has not run yet (or the handle
    // is unknown because this fired earlier), register it here too.
    if ( ! wp_style_is( 'sacscoc-institutions', 'registered' ) ) {
        sacscoc_inst_register_styles();
        return;
    }

    wp_enqueue_style( 'sacscoc-institutions' );
}

// ──────────────────────────────────────────────
// Global footer content (About SACSCOC)
// ──────────────────────────────────────────────

/**
 * The block that appears at the foot of every institution page.
 *
 * The current site repeats the same ~1,500 words of "About SACSCOC and
 * Accreditation" and complaints procedure on all 1,201 pages. It is not
 * institution data, so it is one setting rather than 1,201 stored copies.
 *
 * Kept deliberately decoupled: the template calls this, and if Cirlot would
 * rather the theme own the content, deleting the one call in
 * templates/institution.php removes it with nothing left behind.
 */
function sacscoc_inst_footer_content(): string {
    $content = (string) get_option( 'sacscoc_inst_footer_content', '' );
    if ( trim( $content ) === '' ) return '';

    return apply_filters( 'sacscoc_inst_footer_content', wpautop( wp_kses_post( $content ) ) );
}

// ──────────────────────────────────────────────
// Live filtering
// ──────────────────────────────────────────────

/**
 * Return the results region for a set of filters.
 *
 * Renders templates/results.php — the same file the first page load uses — so
 * live filtering can never drift from the server-rendered markup.
 *
 * Registered for logged-out visitors as well as logged-in ones: this is a public
 * directory, and the nonce is here to bind the request to a session rather than
 * to gate access. A stale nonce (a cached page older than the nonce's lifetime)
 * gets a 403 and the script falls back to a normal form submit, so an expired
 * nonce degrades to a page reload rather than to a broken filter.
 */
add_action( 'wp_ajax_sacscoc_inst_filter', 'sacscoc_inst_ajax_filter' );
add_action( 'wp_ajax_nopriv_sacscoc_inst_filter', 'sacscoc_inst_ajax_filter' );
function sacscoc_inst_ajax_filter(): void {
    check_ajax_referer( 'sacscoc_inst_filter', 'nonce' );

    if ( ! sacscoc_inst_tables_ready() ) {
        wp_send_json_error( [ 'message' => __( 'The institutions directory is not available yet.', 'sacscoc-institutions' ) ], 503 );
    }

    // The same sanitising the page load uses. sacscoc_inst_request_filters()
    // reads $_GET, and jQuery-free fetch() posts these, so they are copied over
    // rather than re-implemented — one definition of what a valid filter is.
    $_GET['si_q']      = wp_unslash( (string) ( $_POST['si_q'] ?? '' ) );
    $_GET['si_state']  = wp_unslash( (string) ( $_POST['si_state'] ?? '' ) );
    $_GET['si_degree'] = wp_unslash( (string) ( $_POST['si_degree'] ?? '' ) );
    $_GET['si_year']   = wp_unslash( (string) ( $_POST['si_year'] ?? '' ) );
    $_GET['si_page']   = wp_unslash( (string) ( $_POST['si_page'] ?? '1' ) );

    $filters = sacscoc_inst_request_filters();

    // The page says how many results it is showing; clamped like every other
    // page size, so a crafted request cannot ask for the whole table.
    $per_page = isset( $_POST['per_page'] )
        ? sacscoc_inst_clamp_per_page( wp_unslash( (string) $_POST['per_page'] ) )
        : sacscoc_inst_per_page();

    $results = sacscoc_inst_search( array_merge( $filters, [ 'per_page' => $per_page ] ) );

    // The results template builds pagination URLs from the directory page's
    // permalink, which an admin-ajax request has no way to infer. The page tells
    // us which URL it is on; it is validated against this site so a crafted
    // request cannot make the markup point somewhere else.
    $origin = isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['page_url'] ) ) : '';
    if ( $origin !== '' && wp_parse_url( $origin, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
        add_filter( 'sacscoc_inst_directory_url', static fn() => $origin );
    }

    $html = sacscoc_inst_load_template( 'results.php', [
        'results'    => $results,
        'filters'    => $filters,
        'show_count' => ( $_POST['show_count'] ?? 'yes' ) !== 'no',
    ], true );

    wp_send_json_success( [
        'html'    => $html,
        'total'   => $results['total'],
        'pages'   => $results['pages'],
        'paged'   => $results['paged'],
        'filters' => $filters,
        'query'   => sacscoc_inst_filter_query( $filters ),
    ] );
}

/**
 * The filters as a query string, for the address bar.
 *
 * Keeping the URL in step with the live results is what makes a filtered view
 * shareable and the back button meaningful, which is the one thing live
 * filtering usually takes away.
 */
function sacscoc_inst_filter_query( array $filters ): string {
    $args = [];
    if ( $filters['q'] !== '' )      $args['si_q']      = $filters['q'];
    if ( $filters['state'] !== '' )  $args['si_state']  = $filters['state'];
    if ( $filters['degree'] !== '' ) $args['si_degree'] = $filters['degree'];
    if ( $filters['year'] !== '' )   $args['si_year']   = $filters['year'];
    if ( (int) $filters['paged'] > 1 ) $args['si_page'] = (int) $filters['paged'];

    return $args ? '?' . http_build_query( $args ) : '';
}

/**
 * The directory script.
 *
 * Enqueued alongside the stylesheet, on the same two conditions. `defer` because
 * it only ever binds listeners — nothing it does needs to block parsing.
 */
add_action( 'wp_enqueue_scripts', 'sacscoc_inst_register_script' );
function sacscoc_inst_register_script(): void {
    if ( ! sacscoc_inst_is_directory_page() ) return;

    wp_enqueue_script(
        'sacscoc-institutions',
        SACSCOC_INST_URL . 'assets/js/directory.js',
        [],
        sacscoc_inst_asset_version( 'assets/js/directory.js' ),
        [ 'strategy' => 'defer', 'in_footer' => true ]
    );

    wp_localize_script( 'sacscoc-institutions', 'sacscocInstitutions', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'sacscoc_inst_filter' ),
        'i18n'    => [
            'searching' => __( 'Searching…', 'sacscoc-institutions' ),
            'failed'    => __( 'The search could not be completed. Please try again.', 'sacscoc-institutions' ),
        ],
    ] );
}
