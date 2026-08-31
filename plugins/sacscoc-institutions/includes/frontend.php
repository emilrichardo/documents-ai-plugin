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
    if ( ! $post instanceof WP_Post ) return false;

    $content = (string) $post->post_content;

    // Either publishing path counts: the [sacscoc_institutions] shortcode
    // typed or pasted in by hand, or the Institutions Directory block
    // (includes/blocks.php) — which sacscoc_inst_directory_block_markup()
    // is what Settings writes into a page's content for.
    return has_shortcode( $content, 'sacscoc_institutions' )
        || has_block( 'sacscoc-institutions/directory', $content );
}

/**
 * The Institutions Directory block, as the block markup a page's real
 * post_content is made of.
 *
 * This is what sacscoc_inst_handle_create_directory_page() and
 * sacscoc_inst_handle_insert_directory_block() (both in includes/settings.php)
 * write into a page — never only rendered through a filter, which is what an
 * earlier version of this did and which left the page looking empty in the
 * editor: nothing to see, nothing to move, nothing to add a heading above.
 * Written as a real block instead, the directory is an ordinary part of the
 * page — draggable, deletable, sit-next-to-anything-else, and configurable
 * from its own Inspector Controls (layout, page size, headings, plus
 * background/text colour and font size from the block toolbar's standard
 * controls) — instead of a shortcode with attributes typed by hand. The
 * `[sacscoc_institutions]` shortcode remains fully supported for anyone who
 * prefers it, or whose page has no block editor at all; this is simply what
 * the two "create it for me" actions in Settings reach for by default.
 */
function sacscoc_inst_directory_block_markup(): string {
    return '<!-- wp:sacscoc-institutions/directory /-->';
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

/**
 * The group that pairs a directory with a search form rendered separately.
 *
 * [sacscoc_institutions_search] exists so the search panel can be dropped
 * somewhere [sacscoc_institutions] itself cannot reach — a custom block, a
 * sidebar, a template part — while still driving the same results. The two
 * shortcodes find each other at runtime by this value alone, matched in
 * assets/js/directory.js; there is no server-side link between them.
 *
 * `sanitize_key()` limits it to [a-z0-9_-], which is also what makes it safe to
 * drop straight into a CSS attribute selector in JavaScript with no further
 * escaping. "default" is deliberately the default: the common case is one
 * directory and, at most, one separate search form on the page, and that pair
 * needs no attribute on either shortcode to find each other.
 */
function sacscoc_inst_clean_group( $value ): string {
    $value = sanitize_key( (string) $value );
    return $value !== '' ? $value : 'default';
}

/**
 * The two shapes a standalone search form can take.
 *
 * Independent of the directory's own two-column/one-column choice — this is
 * the Institutions Search block's/[sacscoc_institutions_search]'s own Layout
 * control, for a form that is not necessarily sitting next to a directory at
 * all. "Vertical" is the same panel the directory's own inline form uses;
 * "horizontal" is the joined-fields bar with the button welded to the end of
 * it, the shape [sacscoc_institutions layout="one-column"] gives its own
 * inline form. Both are the same CSS (.sacscoc-search--stacked) either way —
 * see the "one-column layout" section of assets/css/sacscoc-institutions.css.
 */
function sacscoc_inst_search_layouts(): array {
    return [
        'vertical'   => __( 'Vertical — the search panel', 'sacscoc-institutions' ),
        'horizontal' => __( 'Horizontal — a single search bar', 'sacscoc-institutions' ),
    ];
}

/** A search layout key actually offered; anything else becomes "vertical". */
function sacscoc_inst_clean_search_layout( $value ): string {
    $value = sanitize_key( (string) $value );
    return isset( sacscoc_inst_search_layouts()[ $value ] ) ? $value : 'vertical';
}

/**
 * The directory's actual rendering, from already-typed arguments.
 *
 * Both [sacscoc_institutions] and the Institutions Directory block
 * (includes/blocks.php) call this rather than duplicating the per_page/layout
 * resolution or the template render. Called with real types — bool, int,
 * string — never with the raw strings a shortcode tag hands its callback:
 * `shortcode_atts()` comparisons like `$atts['show_count'] !== 'no'` are
 * correct for a shortcode's string attributes, but the same comparison against
 * a block's genuine boolean `false` would be silently wrong (`false !== 'no'`
 * is true), so each caller normalises its own input before this ever sees it.
 *
 * @param array $args {
 *   @type string $layout          '' for the Settings default, otherwise a key
 *                                  from sacscoc_inst_layouts()
 *   @type int    $per_page        0 for the Settings default, otherwise 1–200
 *   @type bool   $show_count
 *   @type bool   $show_search
 *   @type string $group
 *   @type string $search_heading  '' for the built-in "Institution Search"
 *   @type string $results_heading '' for the built-in "Results"
 *   @type string $filter_state    '' for no restriction, otherwise a key from
 *                                  sacscoc_inst_states() — every result, and
 *                                  every result the inline search can ever
 *                                  reach, is held to this state; the field is
 *                                  dropped from the inline form, since a
 *                                  visitor could never actually change it
 *   @type string $filter_degree   same idea, a key from sacscoc_inst_degrees()
 *   @type string $filter_year     same idea, a year from sacscoc_inst_reaffirm_years()
 * }
 */
function sacscoc_inst_render_directory( array $args ): string {
    if ( ! sacscoc_inst_tables_ready() ) {
        return '<p class="sacscoc-notice">' . esc_html__( 'The institutions directory is not available yet.', 'sacscoc-institutions' ) . '</p>';
    }

    $per_page = (int) $args['per_page'] > 0
        ? sacscoc_inst_clamp_per_page( $args['per_page'] )
        : sacscoc_inst_per_page();

    $layout = (string) $args['layout'] !== ''
        ? sacscoc_inst_clean_layout( $args['layout'] )
        : sacscoc_inst_layout();

    $filters = sacscoc_inst_request_filters();
    $locked  = [];

    // A locked filter wins over whatever the visitor's own URL asks for —
    // otherwise "restrict to Texas" would only be a default a crafted link
    // could still bypass. Validated against the same lists the live search
    // itself is limited to, so a stale or mistyped attribute can never turn
    // into a query for a value that does not exist.
    $state = sanitize_text_field( (string) ( $args['filter_state'] ?? '' ) );
    if ( isset( sacscoc_inst_states()[ $state ] ) ) {
        $filters['state'] = $state;
        $locked[]          = 'state';
    }

    $degree = sanitize_key( (string) ( $args['filter_degree'] ?? '' ) );
    if ( isset( sacscoc_inst_degrees()[ $degree ] ) ) {
        $filters['degree'] = $degree;
        $locked[]           = 'degree';
    }

    $year = (string) ( $args['filter_year'] ?? '' );
    if ( $year !== '' && in_array( (int) $year, sacscoc_inst_reaffirm_years(), true ) ) {
        $filters['year'] = $year;
        $locked[]         = 'year';
    }

    $results = sacscoc_inst_search( array_merge( $filters, [
        'per_page' => $per_page,
    ] ) );

    sacscoc_inst_enqueue_styles();
    sacscoc_inst_enqueue_script();

    return sacscoc_inst_load_template( 'directory.php', [
        'results'         => $results,
        'filters'         => $filters,
        'locked'          => $locked,
        'layout'          => $layout,
        'per_page'        => $per_page,
        'show_count'      => (bool) $args['show_count'],
        'show_search'     => (bool) $args['show_search'],
        'group'           => sacscoc_inst_clean_group( (string) ( $args['group'] ?? 'default' ) ),
        'search_heading'  => (string) ( $args['search_heading'] ?? '' ),
        'results_heading' => (string) ( $args['results_heading'] ?? '' ),
    ], true );
}

add_shortcode( 'sacscoc_institutions', 'sacscoc_inst_directory_shortcode' );

/**
 * The directory: search form, results, pagination.
 *
 * Every filter is a GET parameter, so any result set is a shareable URL and the
 * browser's back button works. Prefixed `si_` so the parameters cannot collide
 * with the theme's or another plugin's.
 *
 * The same directory is also the Institutions Directory block
 * (includes/blocks.php) — a native, no-code alternative to typing this out —
 * which offers every attribute below through the block's own Inspector
 * Controls, plus background colour, text colour and font size straight from
 * the block toolbar's standard controls. Both call sacscoc_inst_render_directory();
 * neither can drift from what the other shows.
 *
 * Attributes:
 *   layout          two-column or one-column. Left out, the Settings value is
 *                   used.
 *   per_page        results per page. Left out, the Settings value is used —
 *                   so the size is configured in one place and this is only
 *                   for the odd page that wants a different one.
 *   show_count      whether to print the result count
 *   show_search     whether this shortcode renders its own search form. `no`
 *                   drops it entirely — no <aside>, single full-width column
 *                   of results — for a page that renders the form itself, with
 *                   [sacscoc_institutions_search], somewhere this shortcode's
 *                   own layout cannot put it: a custom block, a sidebar, a
 *                   template part. See sacscoc_inst_search_shortcode().
 *   group           pairs this directory with a search form rendered by that
 *                   other shortcode, when `show_search="no"`. Only worth
 *                   setting when a page carries more than one directory/search
 *                   pair; the default "default" is what makes the ordinary
 *                   one-of-each case need no attribute on either shortcode.
 *   search_heading  replaces "Institution Search" above the inline form.
 *   results_heading replaces "Results" above the list.
 *
 * layout, per_page, show_count, group and results_heading are carried on the
 * wrapper as data attributes and sent back with every live filter, so a page
 * that lists 50 keeps listing 50 — and a custom results heading stays put —
 * after a search rather than silently reverting on the first keystroke.
 */
function sacscoc_inst_directory_shortcode( $atts ): string {
    $atts = shortcode_atts( [
        'layout'          => '',
        'per_page'        => '',
        'show_count'      => 'yes',
        'show_search'     => 'yes',
        'group'           => 'default',
        'search_heading'  => '',
        'results_heading' => '',
        'filter_state'    => '',
        'filter_degree'   => '',
        'filter_year'     => '',
    ], $atts, 'sacscoc_institutions' );

    return sacscoc_inst_render_directory( [
        'layout'          => (string) $atts['layout'],
        'per_page'        => trim( (string) $atts['per_page'] ) !== '' ? (int) $atts['per_page'] : 0,
        'show_count'      => $atts['show_count'] !== 'no',
        'show_search'     => $atts['show_search'] !== 'no',
        'group'           => (string) $atts['group'],
        'search_heading'  => (string) $atts['search_heading'],
        'results_heading' => (string) $atts['results_heading'],
        'filter_state'    => (string) $atts['filter_state'],
        'filter_degree'   => (string) $atts['filter_degree'],
        'filter_year'     => (string) $atts['filter_year'],
    ] );
}

// ──────────────────────────────────────────────
// The search-only shortcode
// ──────────────────────────────────────────────

/**
 * The standalone search form's actual rendering, from already-typed arguments.
 *
 * [sacscoc_institutions_search] and the Institutions Search block
 * (includes/blocks.php) both call this. See sacscoc_inst_render_directory()
 * for why arguments here are real types rather than a shortcode's raw strings.
 *
 * @param array $args {
 *   @type string $group         see sacscoc_inst_clean_group()
 *   @type string $heading       '' for the built-in "Institution Search"
 *   @type string $layout        'vertical' or 'horizontal'; see
 *                  sacscoc_inst_search_layouts()
 *   @type bool   $contain_width true (the default) caps the panel at the same
 *                  1200px measure the directory itself uses, centred, so a
 *                  search block placed above a directory lines up with it
 *                  instead of stretching to whatever full-width section it
 *                  sits in. Off for a form that is meant to fill a narrower
 *                  place of its own — a sidebar, a column — where that cap
 *                  would only ever be wider than the space actually available
 *                  and so would never do anything.
 * }
 */
function sacscoc_inst_render_search( array $args ): string {
    if ( ! sacscoc_inst_tables_ready() ) {
        return '<p class="sacscoc-notice">' . esc_html__( 'The institutions directory is not available yet.', 'sacscoc-institutions' ) . '</p>';
    }

    $filters = sacscoc_inst_request_filters();

    sacscoc_inst_enqueue_styles();
    sacscoc_inst_enqueue_script();

    $form = sacscoc_inst_load_template( 'search-form.php', [
        'filters' => $filters,
        'action'  => sacscoc_inst_directory_page_url(),
        'group'   => sacscoc_inst_clean_group( (string) ( $args['group'] ?? 'default' ) ),
        'stacked' => sacscoc_inst_clean_search_layout( (string) ( $args['layout'] ?? 'vertical' ) ) === 'horizontal',
        'heading' => (string) ( $args['heading'] ?? '' ),
    ], true );

    $contain_width = (bool) ( $args['contain_width'] ?? true );

    // A styling context of its own, not a directory: it carries the same
    // tokens, resets and component styles (buttons, controls, field labels —
    // everything the stylesheet scopes under .sacscoc-directory/.sacscoc-single)
    // without the data-sacscoc-directory marker that tells the script "this is
    // a results region to initialise". Only the form inside carries that
    // marker, via data-sacscoc-form in search-form.php.
    $class = 'sacscoc-directory sacscoc-search-standalone' . ( $contain_width ? ' sacscoc-contain-width' : '' );

    return '<div class="' . esc_attr( $class ) . '">' . $form . '</div>';
}

add_shortcode( 'sacscoc_institutions_search', 'sacscoc_inst_search_shortcode' );

/**
 * Just the search form — the panel [sacscoc_institutions] renders in its own
 * <aside> — for a page that wants it somewhere that shortcode's own two-column
 * or one-column layout cannot put it: a custom block, a sidebar, a template
 * part, anywhere page-builder layout wants it instead.
 *
 *   [sacscoc_institutions show_search="no"]
 *   [sacscoc_institutions_search]
 *
 * The Institutions Search block (includes/blocks.php) is the same thing with a
 * native editor UI in place of typing attributes — both call
 * sacscoc_inst_render_search(), which renders templates/search-form.php: the
 * same partial [sacscoc_institutions] itself includes for its own inline form,
 * so none of the three can drift apart in markup or behaviour.
 * `assets/js/directory.js` pairs this form with a directory rendered elsewhere
 * on the page purely at runtime, by matching `data-sacscoc-group` (see
 * sacscoc_inst_clean_group()): there is no server-side link between the two,
 * and neither has to be told where the other one is.
 *
 * Without JavaScript this is still a complete, working GET form: submitting it
 * loads Settings → Directory Page with the filters in the query string, same
 * as submitting the directory's own inline form does. It resolves to that
 * setting specifically — never to "whatever page this shortcode happens to be
 * on" — because that would be wrong exactly when this shortcode is doing its
 * job: sitting on a page, or in a block, that is not the results.
 *
 * Attributes:
 *   group    see sacscoc_inst_directory_shortcode(). Must match the
 *            directory's own `group` attribute when a page carries more than
 *            one pair; otherwise leave both unset.
 *   heading  replaces "Institution Search" above the form.
 *   layout   `vertical` (default) — the same panel the directory's own inline
 *            form uses — or `horizontal`, a single bar with the fields joined
 *            and the button welded to the end of it: the shape
 *            [sacscoc_institutions layout="one-column"] gives its own inline
 *            form, offered here independently since a standalone form is not
 *            necessarily next to a directory laid out that way at all.
 */
function sacscoc_inst_search_shortcode( $atts ): string {
    $atts = shortcode_atts( [
        'group'         => 'default',
        'heading'       => '',
        'layout'        => 'vertical',
        'contain_width' => 'yes',
    ], $atts, 'sacscoc_institutions_search' );

    return sacscoc_inst_render_search( [
        'group'         => (string) $atts['group'],
        'heading'       => (string) $atts['heading'],
        'layout'        => (string) $atts['layout'],
        'contain_width' => $atts['contain_width'] !== 'no',
    ] );
}

// ──────────────────────────────────────────────
// The single-institution shortcode
// ──────────────────────────────────────────────

add_shortcode( 'sacscoc_institution', 'sacscoc_inst_institution_shortcode' );

/**
 * One institution's record, embedded in any page.
 *
 *   [sacscoc_institution id="1246"]
 *
 * The same record the /institutions/<slug>/ page renders, from the same
 * template — so a record embedded in a page and a record on its own page can
 * never drift apart — minus the two things that only make sense on a page of
 * its own:
 *
 *   back   the "Back to Results" button. Off here, on for the real page: a
 *          record dropped into the middle of an editorial page is not somewhere
 *          the visitor arrived from a list.
 *   about  the shared "About SACSCOC" block from Settings. On by default,
 *          because it is a disclosure that belongs with the record, but worth
 *          turning off when several records sit on one page and it would print
 *          the same 1,500 words each time.
 *
 * The institution is addressed by its API numeric id, which is what the admin
 * offers on every record screen. `slug` and `sf_id` are accepted too, for a
 * page written against a URL or against Salesforce.
 *
 * An id that matches nothing renders nothing for a visitor. An editor gets a
 * short note instead, because a blank space where a record should be is the
 * kind of mistake nobody notices until someone else does.
 */
function sacscoc_inst_institution_shortcode( $atts ): string {
    $atts = shortcode_atts( [
        'id'    => '',
        'slug'  => '',
        'sf_id' => '',
        'back'  => 'no',
        'about' => 'yes',
    ], $atts, 'sacscoc_institution' );

    return sacscoc_inst_render_institution( [
        'id'         => (string) $atts['id'],
        'slug'       => (string) $atts['slug'],
        'sf_id'      => (string) $atts['sf_id'],
        'show_back'  => $atts['back'] === 'yes',
        'show_about' => $atts['about'] !== 'no',
    ] );
}

/**
 * One institution's record, from already-typed arguments.
 *
 * [sacscoc_institution] and the Institutions block (includes/blocks.php) both
 * call this — see sacscoc_inst_render_directory() for why arguments here are
 * real types rather than a shortcode's raw strings.
 *
 * @param array $args {
 *   @type string $id         the API numeric id, as a string; tried first
 *   @type string $slug       tried if $id resolves to nothing
 *   @type string $sf_id      tried last
 *   @type bool   $show_back  the "Back to Results" button
 *   @type bool   $show_about the shared "About SACSCOC" block
 * }
 */
function sacscoc_inst_render_institution( array $args ): string {
    if ( ! sacscoc_inst_tables_ready() ) {
        return sacscoc_inst_embed_problem( __( 'The institutions directory is not available yet.', 'sacscoc-institutions' ) );
    }

    $row = sacscoc_inst_resolve_institution( [
        'id'    => (string) ( $args['id'] ?? '' ),
        'slug'  => (string) ( $args['slug'] ?? '' ),
        'sf_id' => (string) ( $args['sf_id'] ?? '' ),
    ] );

    if ( $row === null ) {
        return sacscoc_inst_embed_problem( __( 'That institution was not found. Check the id against Institutions → All Institutions.', 'sacscoc-institutions' ) );
    }

    sacscoc_inst_enqueue_styles();

    return sacscoc_inst_load_template( 'institution.php', [
        'institution' => $row,
        'embedded'    => true,
        'show_back'   => (bool) ( $args['show_back'] ?? false ),
        'show_about'  => (bool) ( $args['show_about'] ?? true ),
    ], true );
}

/**
 * The institution a set of shortcode attributes points at, or null.
 *
 * Tried in the order the attributes are worth trusting: the API id first, since
 * that is what the admin hands out, then the slug, then the Salesforce id.
 */
function sacscoc_inst_resolve_institution( array $atts ): ?array {
    $id = (int) trim( (string) ( $atts['id'] ?? '' ) );
    if ( $id > 0 ) {
        $row = sacscoc_inst_get_by_api_id( $id );
        if ( $row !== null ) return $row;
    }

    $slug = sanitize_title( (string) ( $atts['slug'] ?? '' ) );
    if ( $slug !== '' ) {
        $row = sacscoc_inst_get_by_slug( $slug );
        if ( $row !== null ) return $row;
    }

    $sf_id = sanitize_text_field( (string) ( $atts['sf_id'] ?? '' ) );
    if ( $sf_id !== '' ) return sacscoc_inst_get_by_sf_id( $sf_id );

    return null;
}

/**
 * What an embed prints when it cannot render.
 *
 * Only to someone who could fix it. A visitor gets nothing at all rather than
 * an error addressed to somebody else.
 */
function sacscoc_inst_embed_problem( string $message ): string {
    if ( ! current_user_can( 'edit_posts' ) ) return '';

    return '<p class="sacscoc-notice">' . esc_html( $message ) . '</p>';
}

/**
 * The shortcode that embeds one institution, ready to copy.
 *
 * One definition, used by the admin screen and by the documentation, so what
 * the screen offers and what the docs describe cannot disagree.
 */
function sacscoc_inst_embed_shortcode( array $row ): string {
    return sprintf( '[sacscoc_institution id="%d"]', (int) ( $row['api_id'] ?? 0 ) );
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
 * sacscoc_inst_directory_page_url() is the fallback, for a request that is not
 * itself a WordPress page — chiefly the institution pages, for their "Back to
 * results" link.
 */
function sacscoc_inst_directory_url(): string {
    if ( is_singular() || is_page() ) {
        $id = get_queried_object_id();
        if ( $id ) return (string) apply_filters( 'sacscoc_inst_directory_url', get_permalink( $id ) );
    }

    return sacscoc_inst_directory_page_url();
}

/**
 * The results page's own URL — Settings → Directory Page — regardless of
 * where the current request happens to be.
 *
 * sacscoc_inst_directory_url() answers "what URL is this request itself on",
 * which is right for the directory's own inline form: that request IS the
 * results page. [sacscoc_institutions_search] is deliberately placed somewhere
 * else — a custom block, a sidebar, another page entirely — so using that
 * function for its no-JavaScript fallback would resolve to wherever the search
 * form happens to sit rather than to the results, which is wrong precisely when
 * the two differ. This one always names the results page on purpose.
 */
function sacscoc_inst_directory_page_url(): string {
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

/**
 * The Off-campus Instructional Sites legend — same reasoning as
 * sacscoc_inst_footer_content(), and pre-filled with the current production
 * wording (sacscoc_inst_default_sites_legend_content(), in includes/settings.php)
 * rather than left empty, since unlike the About block this is not optional
 * disclosure text: the Type and Status values it explains are meaningless
 * without it.
 */
function sacscoc_inst_sites_legend_content(): string {
    $content = (string) get_option( 'sacscoc_inst_sites_legend_content', '' );
    if ( trim( $content ) === '' ) {
        $content = sacscoc_inst_default_sites_legend_content();
    }

    return apply_filters( 'sacscoc_inst_sites_legend_content', wp_kses_post( $content ) );
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
        // The wrapper's own data-results-heading is what the script reads this
        // back from — see assets/js/directory.js — so a customised heading
        // (results_heading on the shortcode, or the block's own Inspector
        // Control) survives every live filter instead of reverting to "Results"
        // the moment someone types into the search box.
        'heading'    => isset( $_POST['results_heading'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['results_heading'] ) ) : '',
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
 * Registered always, auto-enqueued only where sacscoc_inst_is_directory_page()
 * can see the results shortcode by scanning post_content — the same split as
 * the stylesheet, and for the same reason: a shortcode rendered somewhere that
 * scan cannot see (a widget, a reusable block, another shortcode) needs the
 * backstop below instead. `defer` because it only ever binds listeners —
 * nothing it does needs to block parsing.
 */
add_action( 'wp_enqueue_scripts', 'sacscoc_inst_register_script' );
function sacscoc_inst_register_script(): void {
    wp_register_script(
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

    if ( sacscoc_inst_is_directory_page() ) {
        wp_enqueue_script( 'sacscoc-institutions' );
    }
}

/**
 * Force the directory script to load.
 *
 * Called by both shortcode handlers, mirroring sacscoc_inst_enqueue_styles():
 * whichever renders first registers the handle if `wp_enqueue_scripts` has not
 * fired yet, and either way the script ends up enqueued regardless of what the
 * post_content scan above could see. Needed even more here than for the
 * stylesheet, because [sacscoc_institutions_search] is meant for exactly the
 * placements that scan misses.
 */
function sacscoc_inst_enqueue_script(): void {
    if ( wp_script_is( 'sacscoc-institutions', 'enqueued' ) ) return;

    if ( ! wp_script_is( 'sacscoc-institutions', 'registered' ) ) {
        sacscoc_inst_register_script();
        return;
    }

    wp_enqueue_script( 'sacscoc-institutions' );
}
