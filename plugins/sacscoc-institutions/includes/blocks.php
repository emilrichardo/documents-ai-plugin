<?php
/**
 * The two Gutenberg blocks: a native alternative to typing out the shortcodes.
 *
 * [sacscoc_institutions] and [sacscoc_institutions_search] remain the whole
 * story for anyone who prefers them, or whose page has no block editor at
 * all — nothing here changes what those do or removes them. This is the
 * no-shortcode-typed-by-hand path: Institutions Directory and Institutions
 * Search, configured through the block editor's own Inspector Controls
 * (layout, page size, headings, the same attributes the shortcodes take)
 * plus, for the things a shortcode attribute is a clumsy way to express,
 * WordPress's own block toolbar and sidebar — background colour, text
 * colour, padding, font size — declared once in each block's own block.json
 * `supports` and needing no code of ours to apply.
 *
 * Both blocks are dynamic: block.json carries no `save` output (assets/js/blocks.js
 * returns null from each block's own save()), and PHP renders them on every
 * request through the render_callback below — which is a thin wrapper around
 * sacscoc_inst_render_directory() / sacscoc_inst_render_search() in
 * includes/frontend.php, the exact same functions the two shortcodes call. A
 * block and a shortcode can never show something different for the same
 * settings, because there is only ever one renderer underneath either of them.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'init', 'sacscoc_inst_register_blocks' );
function sacscoc_inst_register_blocks(): void {
    // One script for both blocks' editor UI: they are small, they share every
    // dependency, and nothing is gained by two requests over one.
    wp_register_script(
        'sacscoc-institutions-blocks',
        SACSCOC_INST_URL . 'assets/js/blocks.js',
        [
            'wp-blocks',
            'wp-element',
            'wp-block-editor',
            'wp-components',
            'wp-server-side-render',
            'wp-i18n',
        ],
        sacscoc_inst_asset_version( 'assets/js/blocks.js' ),
        true
    );

    if ( function_exists( 'wp_set_script_translations' ) ) {
        wp_set_script_translations( 'sacscoc-institutions-blocks', 'sacscoc-institutions' );
    }

    // The option lists behind three Inspector Controls (Directory's state/
    // degree/year filters, and the Institution block's own search-as-you-type
    // picker), handed to the editor once rather than re-derived in JS — the
    // block editor has no code-free way to call a PHP function, and hardcoding
    // a second copy of, say, sacscoc_inst_states() in blocks.js is exactly the
    // kind of drift the rest of this plugin goes out of its way to avoid.
    wp_localize_script( 'sacscoc-institutions-blocks', 'sacscocInstBlocks', [
        'states'        => sacscoc_inst_states(),
        'degrees'       => array_reverse( sacscoc_inst_degrees(), true ),
        'years'         => sacscoc_inst_reaffirm_years(),
        'searchNonce'   => wp_create_nonce( 'sacscoc_inst_blocks' ),
        'searchAction'  => 'sacscoc_inst_search_institutions',
    ] );

    register_block_type( SACSCOC_INST_DIR . 'blocks/directory', [
        'editor_script'   => 'sacscoc-institutions-blocks',
        'render_callback' => 'sacscoc_inst_render_directory_block',
    ] );

    register_block_type( SACSCOC_INST_DIR . 'blocks/search', [
        'editor_script'   => 'sacscoc-institutions-blocks',
        'render_callback' => 'sacscoc_inst_render_search_block',
    ] );

    register_block_type( SACSCOC_INST_DIR . 'blocks/institution', [
        'editor_script'   => 'sacscoc-institutions-blocks',
        'render_callback' => 'sacscoc_inst_render_institution_block',
    ] );
}

/**
 * The Institution block's own search-as-you-type: an admin typing a name
 * needs a real match out of 1,200+ institutions, not a numeric id memorised
 * off another screen. Reuses sacscoc_inst_search()'s own name matching
 * (includes/query.php) — the exact same query the public search runs — capped
 * at 20 results, which is plenty for picking one out of a narrowed list and
 * not so many the response is slow to type through.
 */
add_action( 'wp_ajax_sacscoc_inst_search_institutions', 'sacscoc_inst_ajax_search_institutions' );
function sacscoc_inst_ajax_search_institutions(): void {
    check_ajax_referer( 'sacscoc_inst_blocks', 'nonce' );

    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( [], 403 );
    }

    $q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
    if ( strlen( trim( $q ) ) < 2 ) {
        wp_send_json_success( [] );
    }

    $results = sacscoc_inst_search( [ 'q' => $q, 'per_page' => 20, 'paged' => 1 ] );

    $items = array_map( static function ( array $row ): array {
        $where = trim( implode( ', ', array_filter( [
            (string) ( $row['address_city'] ?? '' ),
            (string) ( $row['address_state'] ?? '' ),
        ] ) ) );

        return [
            'id'    => (int) $row['api_id'],
            'name'  => (string) $row['name'],
            'label' => $where !== '' ? $row['name'] . ' — ' . $where : (string) $row['name'],
        ];
    }, $results['rows'] );

    wp_send_json_success( array_values( $items ) );
}

/**
 * Make the front-end stylesheet load inside the block editor's own canvas.
 *
 * Without this, ServerSideRender's preview is real markup with no styling at
 * all: raw stacked labels and inputs, and — most visibly — every SVG icon at
 * its unstyled intrinsic size instead of the 1em `.sacscoc-icon` sets, which
 * is what a "giant icon" in the editor turns out to be.
 *
 * `enqueue_block_assets` is the hook Gutenberg mirrors into the editor's
 * iframe — unlike `enqueue_block_editor_assets`, which loads only in the
 * top-level admin document the iframe sits inside, where a stylesheet meant
 * for the rendered block content would never reach it. It also fires on every
 * front-end page load, which is why this only acts `is_admin()`: the front
 * end keeps its own conditional loading below (sacscoc_inst_register_styles(),
 * enqueued only where the directory or an institution actually renders), and
 * this must not loosen that by enqueueing site-wide.
 *
 * sacscoc_inst_enqueue_styles() cannot be reused as-is here: outside the
 * editor, `wp_enqueue_scripts` runs before any shortcode or block renders, so
 * the stylesheet is always already registered by the time that helper's fast
 * path checks for it. Nothing fires `wp_enqueue_scripts` in wp-admin, so on
 * this hook the handle is never pre-registered — that helper's slower path
 * would only register it and, going by conditions written for a front-end
 * request (`is_page()`, `sacscoc_inst_current()` — both false in wp-admin),
 * stop short of actually enqueueing it. Registering (if needed) and then
 * enqueueing unconditionally, spelled out here, is what the editor actually
 * needs: the block's own presence in the editor is enough of a reason to
 * load its styles, with no page-type condition to satisfy first.
 */
add_action( 'enqueue_block_assets', 'sacscoc_inst_enqueue_editor_preview_style' );
function sacscoc_inst_enqueue_editor_preview_style(): void {
    if ( ! is_admin() ) return;

    if ( ! wp_style_is( 'sacscoc-institutions', 'registered' ) ) {
        sacscoc_inst_register_styles();
    }

    wp_enqueue_style( 'sacscoc-institutions' );
}

/**
 * Institutions Directory block → sacscoc_inst_render_directory().
 *
 * Block attributes arrive already typed by block.json's own schema — a real
 * boolean, a real number — which is exactly what sacscoc_inst_render_directory()
 * expects and a shortcode's raw string attributes are not; see that function's
 * own docblock for why the two cannot share a normalising step.
 *
 * The block is wrapped in get_block_wrapper_attributes(): that call is what
 * turns whatever the editor's colour, spacing and typography controls are set
 * to (block.json's `supports`) into the `class`/`style` this element actually
 * needs — background colour, text colour, padding, font size. Nothing about
 * those is read or reasoned about here; the wrapper attributes are the whole
 * mechanism, supplied by core for any block that declares those supports.
 */
function sacscoc_inst_render_directory_block( array $attributes ): string {
    $content = sacscoc_inst_render_directory( [
        'layout'          => (string) ( $attributes['layout'] ?? '' ),
        'per_page'        => (int) ( $attributes['perPage'] ?? 0 ),
        'show_count'      => (bool) ( $attributes['showCount'] ?? true ),
        'show_search'     => (bool) ( $attributes['showSearch'] ?? true ),
        'group'           => (string) ( $attributes['group'] ?? 'default' ),
        'search_heading'  => (string) ( $attributes['searchHeading'] ?? '' ),
        'results_heading' => (string) ( $attributes['resultsHeading'] ?? '' ),
        'filter_state'    => (string) ( $attributes['filterState'] ?? '' ),
        'filter_degree'   => (string) ( $attributes['filterDegree'] ?? '' ),
        'filter_year'     => (string) ( $attributes['filterYear'] ?? '' ),
    ] );

    return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes(), $content );
}

/** Institutions Search block → sacscoc_inst_render_search(). See above. */
function sacscoc_inst_render_search_block( array $attributes ): string {
    $content = sacscoc_inst_render_search( [
        'group'         => (string) ( $attributes['group'] ?? 'default' ),
        'heading'       => (string) ( $attributes['heading'] ?? '' ),
        'layout'        => (string) ( $attributes['layout'] ?? 'vertical' ),
        'contain_width' => (bool) ( $attributes['containWidth'] ?? true ),
    ] );

    return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes(), $content );
}

/** Institution block → sacscoc_inst_render_institution(). See above. */
function sacscoc_inst_render_institution_block( array $attributes ): string {
    $id      = (int) ( $attributes['institutionId'] ?? 0 );
    $content = sacscoc_inst_render_institution( [
        'id'         => $id > 0 ? (string) $id : '',
        'show_back'  => (bool) ( $attributes['showBack'] ?? false ),
        'show_about' => (bool) ( $attributes['showAbout'] ?? true ),
    ] );

    return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes(), $content );
}
