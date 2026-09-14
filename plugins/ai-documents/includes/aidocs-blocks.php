<?php
/**
 * The two Gutenberg blocks: a native alternative to typing the shortcodes.
 *
 * [aidocs_search] and [aidocs_document] remain the whole story for anyone who
 * prefers them, or whose page has no block editor at all — nothing here
 * changes what they do or removes them. This is the no-shortcode-typed-by-hand
 * path: Policies and Policy, configured through the block editor's own
 * Inspector Controls (document type, results per page, the AI suggestions
 * toggle — the same attributes the shortcodes take) plus, for the things a
 * shortcode attribute is a clumsy way to express, WordPress's own block
 * toolbar and sidebar: background colour, text colour, padding, font size,
 * declared once in each block's block.json `supports` and needing no code of
 * ours to apply.
 *
 * Both blocks are dynamic: block.json carries no `save` output (assets/js/blocks.js
 * returns null from each save()), and PHP renders them on every request
 * through the render callbacks below — themselves thin wrappers around
 * aidocs_search_shortcode() and aidocs_document_shortcode(), the exact
 * functions the two shortcodes call. A block and a shortcode can never show
 * something different for the same settings, because there is only ever one
 * renderer underneath either of them.
 *
 * @package aidocs
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'init', 'aidocs_register_blocks' );
function aidocs_register_blocks() {
    // One script for both blocks' editor UI: they are small, they share every
    // dependency, and nothing is gained by two requests over one.
    wp_register_script(
        'aidocs-blocks',
        AIDOCS_URL . 'assets/js/blocks.js',
        [
            'wp-blocks',
            'wp-element',
            'wp-block-editor',
            'wp-components',
            'wp-server-side-render',
            'wp-i18n',
        ],
        AIDOCS_VERSION,
        true
    );

    // The option lists behind two Inspector Controls — the document types and
    // the published policies — handed to the editor once rather than re-derived
    // in JS. The block editor has no code-free way to call a PHP function, and
    // the `aidoc` post type is registered `show_in_rest => false`, so there is
    // no entity endpoint for the editor to read the list from either.
    //
    // Only in wp-admin: this list costs a query, the script it feeds is an
    // editor script, and a visitor reading a page has no use for either.
    if ( is_admin() ) {
        wp_localize_script( 'aidocs-blocks', 'aidocsBlocks', [
            'types'    => array_values( aidocs_get_types() ),
            'policies' => aidocs_blocks_policy_options(),
        ] );
    }

    if ( function_exists( 'wp_set_script_translations' ) ) {
        wp_set_script_translations( 'aidocs-blocks', 'ai-documents' );
    }

    register_block_type( AIDOCS_DIR . 'blocks/policies', [
        'editor_script'   => 'aidocs-blocks',
        'render_callback' => 'aidocs_render_policies_block',
    ] );

    register_block_type( AIDOCS_DIR . 'blocks/policy', [
        'editor_script'   => 'aidocs-blocks',
        'render_callback' => 'aidocs_render_policy_block',
    ] );
}

/**
 * Published policies as { id, label } for the Policy block's picker.
 *
 * Capped at 200 and titles only: an editor picking one entry needs a list they
 * can read, not every row of the table, and the cap is what keeps the editor
 * script from growing with the library. A site past that cap still has the
 * shortcode's `id`/`slug` attributes.
 */
function aidocs_blocks_policy_options() {
    $posts = get_posts( [
        'post_type'        => 'aidoc',
        'post_status'      => 'publish',
        'numberposts'      => 200,
        'orderby'          => 'title',
        'order'            => 'ASC',
        'suppress_filters' => false,
    ] );

    return array_map( static function ( WP_Post $post ) {
        return [
            'id'    => (int) $post->ID,
            'label' => (string) get_the_title( $post ),
        ];
    }, $posts );
}

/**
 * Policies block → aidocs_search_shortcode().
 *
 * Block attributes arrive already typed by block.json's schema — a real
 * boolean, a real number — and the shortcode expects the raw strings a
 * shortcode gets, so each one is handed over in the spelling shortcode_atts()
 * will read: 'false' rather than false, a numeric string rather than an int.
 *
 * The wrapper comes from get_block_wrapper_attributes(): that call is what
 * turns whatever the editor's colour, spacing and typography controls are set
 * to (block.json's `supports`) into the class and style this element needs.
 * Nothing about those is reasoned about here.
 */
function aidocs_render_policies_block( $attributes ) {
    $content = aidocs_search_shortcode( [
        'type'     => (string) ( $attributes['type'] ?? '' ),
        'per_page' => (string) (int) ( $attributes['perPage'] ?? 20 ),
        'show_ai'  => ( $attributes['showAi'] ?? true ) ? 'true' : 'false',
    ] );

    return sprintf( '<div %s>%s%s</div>', get_block_wrapper_attributes(), $content, aidocs_blocks_editor_note(
        __( 'Results, search and AI suggestions run on the published page — this preview shows the panel only.' )
    ) );
}

/** Policy block → aidocs_document_shortcode(). See above. */
function aidocs_render_policy_block( $attributes ) {
    $id = (int) ( $attributes['documentId'] ?? 0 );

    if ( $id <= 0 ) {
        return sprintf(
            '<div %s><p>%s</p></div>',
            get_block_wrapper_attributes(),
            esc_html__( 'Choose a policy in the block settings.' )
        );
    }

    $content = aidocs_document_shortcode( [ 'id' => (string) $id ] );

    return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes(), $content );
}

/**
 * A line of explanation shown inside the editor's preview and nowhere else.
 *
 * The search panel is driven by JavaScript this plugin prints at `wp_footer`
 * (see aidocs_search_shortcode()), and `wp_footer` never fires for the REST
 * request ServerSideRender makes — so the preview is real, correctly styled
 * markup with an empty results list, which looks like something broken unless
 * it says otherwise.
 */
function aidocs_blocks_editor_note( $text ) {
    if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) return '';

    return '<p style="margin:12px 0 0;font-size:12px;color:#646970;font-style:italic;">'
        . esc_html( $text )
        . '</p>';
}
