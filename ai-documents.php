<?php
/**
 * Plugin Name: AI Documents
 * Description: Document library with AI-assisted metadata entry, semantic search, and a conversational document finder.
 * Version: 1.2.0
 * Requires PHP: 8.0
 * Text Domain: ai-documents
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'AIDOCS_VERSION', '1.2.0' );
define( 'AIDOCS_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIDOCS_URL', plugin_dir_url( __FILE__ ) );

// Document structure: the regex parser and the block renderer. Paired with
// assets/js/aidocs-pdf-structure.js and assets/js/aidocs-docx-structure.js,
// which turn a PDF's layout or a Word file's own styles into the same
// canonical text this parser reads either way.
require_once AIDOCS_DIR . 'includes/aidocs-doc-parser.php';

// Documents → Documentation: the manual, generated from docs/DOCUMENTATION.md
// by tools/build-docs.sh so it never drifts from the version it ships with.
require_once AIDOCS_DIR . 'includes/aidocs-documentation.php';

define( 'AIDOCS_AUDIENCES', [ 'Institution', 'Evaluator', 'Public' ] );
define( 'AIDOCS_TYPES', [
    'Policies', 'Guidelines', 'Good Practices', 'Position Statements',
    'Handbooks', 'Interpretation', 'Guides', 'Rules of the Organization', 'Forms and Templates',
] );

// ──────────────────────────────────────────────
// 0. One-time migration from the legacy prefix
// ──────────────────────────────────────────────
// Everything used to be namespaced `cirlot_*`. This moves existing content and
// settings onto the `aidocs_*` namespace exactly once, then never runs again.
// Post meta (`_document_*`) and both taxonomies were never prefixed, so they
// carry over untouched.
add_action( 'plugins_loaded', 'aidocs_maybe_migrate_legacy' );
function aidocs_maybe_migrate_legacy() {
    if ( get_option( 'aidocs_legacy_migrated' ) ) return;

    global $wpdb;

    // Claim the migration up front so two concurrent requests can't both run it.
    update_option( 'aidocs_legacy_migrated', AIDOCS_VERSION, false );

    // 1. Documents: cirlot_document → aidoc
    $moved = (int) $wpdb->update(
        $wpdb->posts,
        [ 'post_type' => 'aidoc' ],
        [ 'post_type' => 'cirlot_document' ]
    );

    // 2. Settings: cirlot_docs_* → aidocs_*
    $legacy_prefix = 'cirlot_docs_';
    $legacy_names  = $wpdb->get_col(
        "SELECT option_name FROM {$wpdb->options}
         WHERE option_name LIKE '" . $wpdb->esc_like( $legacy_prefix ) . "%'"
    );
    foreach ( (array) $legacy_names as $legacy_name ) {
        $new_name = 'aidocs_' . substr( $legacy_name, strlen( $legacy_prefix ) );
        $value    = get_option( $legacy_name, null );
        if ( $value !== null && get_option( $new_name, null ) === null ) {
            update_option( $new_name, $value );
        }
        delete_option( $legacy_name );
    }

    // 3. Cached embedding-model lookup (rediscovered on next use)
    delete_transient( 'cirlot_docs_embed_model' );

    // 4. Shortcodes already placed on pages
    $sc_updated = (int) $wpdb->query( $wpdb->prepare(
        "UPDATE {$wpdb->posts} SET post_content = REPLACE( post_content, %s, %s )
         WHERE post_content LIKE %s",
        '[cirlot_document_search',
        '[aidocs_search',
        '%' . $wpdb->esc_like( '[cirlot_document_search' ) . '%'
    ) );

    if ( $moved || $sc_updated ) {
        wp_cache_flush();
    }

    // Can't flush_rewrite_rules() here — the post type is not registered until
    // `init`. Dropping the stored rules makes WordPress rebuild them after it is.
    delete_option( 'rewrite_rules' );
}

// ──────────────────────────────────────────────
// 0b. One-time cleanup: drop settings for removed features
// ──────────────────────────────────────────────
// v1.2.0 removed the General settings tab (menu name/icon, archive slug,
// allowed formats, default audience/type) and the configurable Custom Fields
// system in favor of a single fixed Description field. Their option rows are
// dead weight now — nothing reads them — so they're deleted once.
add_action( 'plugins_loaded', 'aidocs_maybe_cleanup_removed_options' );
function aidocs_maybe_cleanup_removed_options() {
    if ( get_option( 'aidocs_removed_options_cleaned' ) ) return;
    update_option( 'aidocs_removed_options_cleaned', AIDOCS_VERSION, false );

    foreach ( [
        'aidocs_menu_name',
        'aidocs_menu_icon',
        'aidocs_archive_slug',
        'aidocs_default_audience',
        'aidocs_default_type',
        'aidocs_allowed_formats',
        'aidocs_global_fields',
    ] as $obsolete ) {
        delete_option( $obsolete );
    }
}

function aidocs_get_audiences() {
    $saved = get_option( 'aidocs_audiences_list', '' );
    if ( $saved !== '' ) {
        return array_values( array_filter( array_map( 'trim', explode( "\n", $saved ) ) ) );
    }
    return AIDOCS_AUDIENCES;
}

function aidocs_get_types() {
    $saved = get_option( 'aidocs_types_list', '' );
    if ( $saved !== '' ) {
        return array_values( array_filter( array_map( 'trim', explode( "\n", $saved ) ) ) );
    }
    return AIDOCS_TYPES;
}

/**
 * How much document text is sent to the AI in one request.
 *
 * Every Gemini model this plugin offers takes a 1,048,576-token context, which
 * is far more than any policy document here needs — the longest in the corpus
 * is ~250 KB of text. So the limit exists only to keep a pathological file
 * from being posted whole; it is not a content decision, and the response
 * reports when it actually clipped something so an editor is never left
 * guessing whether the AI saw the whole document.
 */
const AIDOCS_AI_TEXT_LIMIT = 700000;

/**
 * Selectable Gemini models, newest family first.
 *
 * A starting list only: "Refresh from API" in Settings replaces it with
 * exactly what the configured key can reach, which is the authoritative
 * answer and the one to trust when a model here is rejected. Image, TTS and
 * computer-use variants are deliberately absent — they accept generateContent
 * but are not text models.
 *
 * @return array<string,string> Model id => label.
 */
function aidocs_model_catalog() {
    return [
        'gemini-3.6-flash'          => 'Gemini 3.6 Flash (newest, recommended)',
        'gemini-3.5-flash'          => 'Gemini 3.5 Flash',
        'gemini-3.5-flash-lite'     => 'Gemini 3.5 Flash Lite (cheapest)',
        'gemini-3.1-pro-preview'    => 'Gemini 3.1 Pro Preview (most capable)',
        'gemini-3.1-flash-lite'     => 'Gemini 3.1 Flash Lite',
        'gemini-3-pro-preview'      => 'Gemini 3 Pro Preview',
        'gemini-3-flash-preview'    => 'Gemini 3 Flash Preview',
        'gemini-2.5-pro'            => 'Gemini 2.5 Pro',
        'gemini-2.5-flash'          => 'Gemini 2.5 Flash',
        'gemini-2.5-flash-lite'     => 'Gemini 2.5 Flash Lite',
        'gemini-2.0-flash'          => 'Gemini 2.0 Flash (legacy)',
        'gemini-pro-latest'         => 'Gemini Pro (alias: always newest pro)',
        'gemini-flash-latest'       => 'Gemini Flash (alias: always newest flash)',
        'gemini-flash-lite-latest'  => 'Gemini Flash Lite (alias: always newest lite)',
    ];
}

/**
 * Is this model id a Gemini text model — not an image, speech, music,
 * robotics or research-agent one, and not a Gemma open-weights variant?
 * The API's models.list endpoint mixes all of Google's generateContent-
 * capable product lines into one list with no field that separates them,
 * so the id itself, matched against the "gemini-<version>-..." shape this
 * plugin's own catalog uses, is what has to do it.
 */
function aidocs_is_text_model( $id ) {
    // "gemini-<version>-…" covers a dated model; "gemini-{pro,flash,flash-lite}-latest"
    // covers the rolling aliases, which have no version number of their own.
    if ( ! preg_match( '/^gemini-(?:\d|pro-latest|flash-latest|flash-lite-latest)/', $id ) ) return false;
    return ! preg_match( '/-(?:image|tts|computer-use|embedding|robotics-er)\b/i', $id );
}

// ── Discover first available embedding model ──
function aidocs_get_embed_model( $api_key ) {
    $cached = get_transient( 'aidocs_embed_model' );
    if ( $cached === 'none' ) return null;   // cached negative
    if ( is_array( $cached ) ) return $cached;

    foreach ( [ 'v1beta', 'v1' ] as $ver ) {
        $r = wp_remote_get(
            "https://generativelanguage.googleapis.com/{$ver}/models?key=" . urlencode( $api_key ),
            [ 'timeout' => 10 ]
        );
        if ( is_wp_error( $r ) ) continue;
        $body   = json_decode( wp_remote_retrieve_body( $r ), true );
        $models = $body['models'] ?? [];

        $preferred = [ 'models/text-embedding-004', 'models/embedding-001' ];
        $available = [];
        foreach ( $models as $m ) {
            if ( in_array( 'embedContent', $m['supportedGenerationMethods'] ?? [], true ) ) {
                $available[] = $m['name'];
            }
        }
        foreach ( $preferred as $p ) {
            if ( in_array( $p, $available, true ) ) {
                set_transient( 'aidocs_embed_model', [ 'name' => $p, 'ver' => $ver ], HOUR_IN_SECONDS );
                return [ 'name' => $p, 'ver' => $ver ];
            }
        }
        if ( ! empty( $available ) ) {
            $pick = [ 'name' => $available[0], 'ver' => $ver ];
            set_transient( 'aidocs_embed_model', $pick, HOUR_IN_SECONDS );
            return $pick;
        }
    }
    // Cache the negative result for 30 min to avoid hammering ListModels
    set_transient( 'aidocs_embed_model', 'none', 30 * MINUTE_IN_SECONDS );
    return null;
}

// ── Gemini embedding helper ───────────────────
function aidocs_gemini_embed( $text, $api_key, &$error_msg = null ) {
    $text  = mb_substr( trim( $text ), 0, 9000 );
    $model = aidocs_get_embed_model( $api_key );

    if ( ! $model ) {
        $error_msg = 'No embedding model found for this API key. Open Settings → AI and click Test Connection to verify the key, then check that the Gemini Embedding API is enabled in Google AI Studio.';
        return null;
    }

    $model_id = str_replace( 'models/', '', $model['name'] );
    $url      = "https://generativelanguage.googleapis.com/{$model['ver']}/models/{$model_id}:embedContent?key=" . urlencode( $api_key );

    $r = wp_remote_post( $url, [
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode( [ 'content' => [ 'parts' => [ [ 'text' => $text ] ] ] ] ),
        'timeout' => 30,
    ] );

    if ( is_wp_error( $r ) ) {
        $error_msg = $r->get_error_message();
        return null;
    }
    $code = (int) wp_remote_retrieve_response_code( $r );
    $body = json_decode( wp_remote_retrieve_body( $r ), true );
    if ( $code !== 200 ) {
        $error_msg = $body['error']['message'] ?? "HTTP {$code}";
        return null;
    }
    return $body['embedding']['values'] ?? null;
}

// ── Cosine similarity ─────────────────────────
function aidocs_cosine_similarity( array $a, array $b ) {
    $dot = 0.0; $magA = 0.0; $magB = 0.0;
    $n   = min( count( $a ), count( $b ) );
    for ( $i = 0; $i < $n; $i++ ) {
        $dot  += $a[ $i ] * $b[ $i ];
        $magA += $a[ $i ] * $a[ $i ];
        $magB += $b[ $i ] * $b[ $i ];
    }
    if ( $magA == 0 || $magB == 0 ) return 0.0;
    return (float) ( $dot / ( sqrt( $magA ) * sqrt( $magB ) ) );
}

// ── Build indexable text for a document ───────
function aidocs_doc_index_text( $pid ) {
    $parts   = [];
    $parts[] = get_the_title( $pid );
    $description = get_post_meta( $pid, '_document_description', true );
    if ( $description ) $parts[] = 'Document Description: ' . $description;
    $audience = wp_get_post_terms( $pid, 'document_audience', [ 'fields' => 'names' ] );
    if ( $audience && ! is_wp_error( $audience ) ) $parts[] = 'Audience: ' . implode( ', ', $audience );
    $types = wp_get_post_terms( $pid, 'document_type', [ 'fields' => 'names' ] );
    if ( $types && ! is_wp_error( $types ) ) $parts[] = 'Type: ' . implode( ', ', $types );
    $summary = get_post_meta( $pid, '_document_summary', true );
    if ( $summary ) $parts[] = $summary;
    $content = aidocs_content_plain_text( $pid );
    if ( $content ) $parts[] = mb_substr( $content, 0, 6000 );
    return implode( "\n", $parts );
}

/**
 * Keep a document's semantic embedding current, whenever that's possible.
 *
 * There is no separate "index" action for an editor to remember to click:
 * the basic keyword search (aidocs_search_ajax) never needs an embedding at
 * all, and the embedding that powers semantic matching for the AI assistant
 * is instead refreshed automatically — right after content extraction, and
 * again on every save, so it always reflects what actually ended up in the
 * database. Silent no-op without an API key or without anything to index yet;
 * a failed API call is not surfaced as an error, since nothing the editor did
 * caused it and the previous embedding, if any, is left in place.
 *
 * @return bool Whether an embedding is present after this call.
 */
function aidocs_maybe_reindex( $post_id ) {
    $api_key = get_option( 'aidocs_gemini_api_key', '' );
    if ( ! $api_key ) return (bool) get_post_meta( $post_id, '_document_embedding', true );

    $index_text = aidocs_doc_index_text( $post_id );
    if ( ! trim( $index_text ) ) return (bool) get_post_meta( $post_id, '_document_embedding', true );

    $embedding = aidocs_gemini_embed( $index_text, $api_key, $embed_error );
    if ( $embedding ) {
        update_post_meta( $post_id, '_document_embedding', wp_slash( wp_json_encode( $embedding ) ) );
        return true;
    }
    return (bool) get_post_meta( $post_id, '_document_embedding', true );
}

// ──────────────────────────────────────────────
// Structured content — parse, store, render
// ──────────────────────────────────────────────
// A document's body is stored in `_document_content` as a JSON array of blocks.
// Every block is one of:
//   { "type": "heading",   "level": 2|3, "text": "…" }
//   { "type": "paragraph", "text": "…" }
//   { "type": "list",      "ordered": bool, "items": [ "…" ] }
// Keeping the stored shape this dumb means the parser can get smarter (or be
// swapped per document family) without touching the renderer or the frontend.

/**
 * Read a document's content blocks.
 *
 * @return array List of blocks; empty array when nothing has been extracted.
 */
function aidocs_get_content_blocks( $pid ) {
    $raw = get_post_meta( $pid, '_document_content', true );
    if ( ! $raw ) return [];
    $decoded = json_decode( $raw, true );
    return is_array( $decoded ) ? $decoded : [];
}

/**
 * The canonical text the blocks above were parsed from — whatever
 * aidocs_extract_content_ajax() last received, whether that came from the
 * PDF/Word extractor or from an editor's own hand edit applied afterwards.
 *
 * A document extracted before this meta existed has no text on file — only
 * the blocks it already produced — so one is reconstructed from those blocks
 * with aidocs_blocks_to_canonical_text() instead of leaving the "Edit
 * extracted content" textarea empty. Not persisted here: it becomes the real
 * thing automatically the next time extraction runs or an edit is applied.
 */
function aidocs_get_raw_text( $pid ) {
    $raw = (string) get_post_meta( $pid, '_document_raw_text', true );
    if ( $raw !== '' ) return $raw;

    $blocks = aidocs_get_content_blocks( $pid );
    return $blocks ? aidocs_blocks_to_canonical_text( $blocks ) : '';
}

/**
 * Flatten a document's content blocks back to plain text (search indexing, AI context).
 */
function aidocs_content_plain_text( $pid ) {
    return aidocs_blocks_plain_text( aidocs_get_content_blocks( $pid ) );
}

/**
 * Build a highlighted excerpt around the first place a keyword matches a
 * document's extracted body text (falling back to description/summary), so
 * a keyword search result can show *where* the exact match was found instead
 * of just the title — same idea as a search engine's snippet.
 *
 * @return string Escaped HTML with the match wrapped in <mark>, or '' if the
 *                keyword isn't found in any of those sources.
 */
function aidocs_search_snippet( $pid, $keyword ) {
    if ( ! $keyword ) return '';

    $sources = [
        aidocs_content_plain_text( $pid ),
        get_post_meta( $pid, '_document_description', true ),
        get_post_meta( $pid, '_document_summary', true ),
    ];

    foreach ( $sources as $text ) {
        if ( ! $text ) continue;
        $pos = mb_stripos( $text, $keyword );
        if ( $pos === false ) continue;

        $context = 90;
        $start   = max( 0, $pos - $context );
        $len     = mb_strlen( $keyword ) + $context * 2;
        $excerpt = mb_substr( $text, $start, $len );
        $prefix  = $start > 0 ? '…' : '';
        $suffix  = ( $start + $len ) < mb_strlen( $text ) ? '…' : '';

        // Escape first, then highlight — so the pattern matches (and wraps)
        // the already-escaped keyword rather than risking a mismatch against
        // characters esc_html() may have changed (&, <, >, etc).
        $escaped = esc_html( $excerpt );
        $pattern = '/' . preg_quote( esc_html( $keyword ), '/' ) . '/i';
        $escaped = preg_replace( $pattern, '<mark>$0</mark>', $escaped );

        return $prefix . $escaped . $suffix;
    }

    return '';
}

/**
 * Body-text excerpt for a candidate document, sized generously enough that
 * Gemini has real text to judge relevance from — not just a title and a
 * (usually empty) manual description.
 *
 * This deliberately does NOT try to locate "the relevant passage" for the
 * query: a first pass tried scoring sliding windows by which query words
 * they contained, but short/generic query words collide with unrelated text
 * constantly (e.g. "unit" inside "United States", "level" inside "Level 4
 * travel advisory"), silently steering the excerpt to the wrong paragraph
 * with no way to tell from the output that it happened. A longer straight
 * excerpt from the top of the document is far more predictable, and the
 * candidate list here is already short (the embedding step upstream did the
 * real relevance filtering), so the token cost of a bigger excerpt is trivial.
 *
 * @param int $max_len Scale this down when the candidate list is long (the
 *                      embedding-unavailable fallback can pad it to 40 docs).
 */
function aidocs_candidate_excerpt( $pid, $max_len = 3000 ) {
    return mb_substr( aidocs_content_plain_text( $pid ), 0, $max_len );
}

/**
 * Render a document's provenance line, if it has one.
 *
 * Shown at the end of the body, which is where the source documents carry it.
 */
function aidocs_render_document_history( $pid ) {
    $history = get_post_meta( $pid, '_document_history', true );
    if ( ! $history ) return '';
    return '<div class="aidocs-doc-history">'
        . '<span class="aidocs-doc-history-label">' . esc_html__( 'Document History' ) . '</span>'
        . esc_html( $history )
        . '</div>';
}

// ──────────────────────────────────────────────
// 0. Enqueue media uploader scripts
// ──────────────────────────────────────────────
add_action( 'admin_enqueue_scripts', 'aidocs_enqueue_scripts' );
function aidocs_enqueue_scripts( $hook ) {
    if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;
    if ( get_post_type() !== 'aidoc' && get_current_screen()->post_type !== 'aidoc' ) return;
    wp_enqueue_media();
    wp_enqueue_script( 'pdfjs', 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js', [], '3.11.174', true );
    wp_enqueue_script(
        'aidocs-pdf-structure',
        AIDOCS_URL . 'assets/js/aidocs-pdf-structure.js',
        [ 'pdfjs' ],
        AIDOCS_VERSION,
        true
    );
    wp_enqueue_script( 'mammoth', AIDOCS_URL . 'assets/js/vendor/mammoth.browser.min.js', [], '1.12.1', true );
    wp_enqueue_script(
        'aidocs-docx-structure',
        AIDOCS_URL . 'assets/js/aidocs-docx-structure.js',
        [ 'mammoth' ],
        AIDOCS_VERSION,
        true
    );

    // Core's own periodic autosave (wp_autosave(), wp-admin/includes/post.php)
    // turns a fresh entry's auto-draft into a real draft in the background —
    // no button click involved — the moment the heartbeat first fires. That
    // is exactly the "draft nobody asked for" a compilation upload should
    // never leave behind, so autosave is dropped on this screen entirely.
    // Manually saved drafts (single-document mode's own Save Draft) are a
    // deliberate click and go through the normal form submit, untouched by
    // this.
    wp_dequeue_script( 'autosave' );
}

// ──────────────────────────────────────────────
// 1. Register Custom Post Type
// ──────────────────────────────────────────────
add_action( 'init', 'aidocs_register_post_type' );
function aidocs_register_post_type() {
    $slug = aidocs_get_archive_slug();

    register_post_type( 'aidoc', [
        'labels' => [
            'name'               => __( 'Documents' ),
            'singular_name'      => __( 'Document' ),
            'add_new'            => __( 'Add New Document' ),
            'add_new_item'       => __( 'Add New Document' ),
            'edit_item'          => __( 'Edit Document' ),
            'all_items'          => __( 'All Documents' ),
            'search_items'       => __( 'Search Documents' ),
        ],
        'public'       => true,
        // A string, not just true: this is what lets /{slug}/ list documents
        // at the same base the individual documents themselves live under —
        // one setting instead of the archive and the singular slug drifting
        // apart if only one of the two were configurable.
        'has_archive'  => $slug,
        'rewrite'      => [ 'slug' => $slug ],
        'supports'     => [ 'title' ],
        'menu_icon'    => 'dashicons-media-document',
        'show_in_rest' => false,
    ] );
}

/** The URL segment documents live under — /{slug}/ for the archive, /{slug}/{name}/ for one. */
function aidocs_get_archive_slug() {
    return 'documents';
}

/**
 * Serve the plugin's own templates for the document listing and single
 * document view, in place of whatever the active theme would resolve.
 *
 * template_include is the right hook even on a block theme: it is the final
 * filter WordPress applies before deciding which file governs the request,
 * ahead of the block-template resolution that would otherwise fall back to
 * the theme's generic archive/single templates — a bare title-and-excerpt
 * list, or a single-post layout dragging in sidebars, related posts or
 * comments that don't make sense for a document.
 */
add_filter( 'template_include', 'aidocs_document_template_include' );
function aidocs_document_template_include( $template ) {
    if ( is_post_type_archive( 'aidoc' ) ) {
        return AIDOCS_DIR . 'templates/archive-aidoc.php';
    }
    if ( is_singular( 'aidoc' ) ) {
        return AIDOCS_DIR . 'templates/single-aidoc.php';
    }
    return $template;
}

/** Render a theme template part by slug, or nothing if the theme has none by that name. Used by both plugin templates. */
function aidocs_render_template_part( $slug, $tag ) {
    echo render_block( [
        'blockName' => 'core/template-part',
        'attrs'     => [ 'slug' => $slug, 'tagName' => $tag, 'theme' => get_stylesheet() ],
        'innerHTML' => '',
    ] );
}

/**
 * Opens the document (doctype/head/body) and prints the theme's header.
 *
 * A block theme ships no header.php, so get_header() can't find one in the
 * theme and falls through to WordPress's own wp-includes/theme-compat/header.php
 * — a deprecated, unstyled Kubrick-era stub (bare site title + <hr>) that has
 * nothing to do with the active theme's real design. This prints the correct
 * document wrapper ourselves, the same way core's own template-canvas.php
 * does for a block template, and pulls in the theme's actual header via its
 * "header" template part instead. A classic theme's own header.php already
 * gets this right, so it's left alone.
 */
function aidocs_document_header() {
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
    aidocs_render_template_part( 'header', 'header' );
}

/** Prints the theme's footer and closes the document opened by aidocs_document_header(). */
function aidocs_document_footer() {
    if ( ! wp_is_block_theme() ) {
        get_footer();
        return;
    }
    aidocs_render_template_part( 'footer', 'footer' );
    ?>
    <?php wp_footer(); ?>
    </body>
    </html>
    <?php
}

// ──────────────────────────────────────────────
// 2. Register Taxonomies
// ──────────────────────────────────────────────
add_action( 'init', 'aidocs_register_taxonomies' );
function aidocs_register_taxonomies() {
    // Audience
    register_taxonomy( 'document_audience', 'aidoc', [
        'labels' => [
            'name'          => __( 'Audiences' ),
            'singular_name' => __( 'Audience' ),
            'add_new_item'  => __( 'Add New Audience' ),
        ],
        'hierarchical'      => false,
        'show_ui'           => false, // managed via meta box
        'show_in_rest'      => false,
        'rewrite'           => [ 'slug' => 'document-audience' ],
    ] );

    // Document Type
    register_taxonomy( 'document_type', 'aidoc', [
        'labels' => [
            'name'          => __( 'Document Types' ),
            'singular_name' => __( 'Document Type' ),
            'add_new_item'  => __( 'Add New Document Type' ),
        ],
        'hierarchical'      => false,
        'show_ui'           => false, // managed via meta box
        'show_in_rest'      => false,
        'rewrite'           => [ 'slug' => 'document-type' ],
    ] );
}

// ──────────────────────────────────────────────
// 3. Meta Boxes
// ──────────────────────────────────────────────
add_action( 'add_meta_boxes', 'aidocs_add_meta_boxes' );
function aidocs_add_meta_boxes() {
    add_meta_box(
        'aidocs_meta',
        __( 'Documents' ),
        'aidocs_meta_box_html',
        'aidoc',
        'normal',
        'high'
    );
    $post        = get_post();
    $has_content = $post && aidocs_get_content_blocks( $post->ID );

    if ( $post && $post->post_status === 'publish' && $has_content ) {
        add_meta_box(
            'aidocs_shortcode',
            __( 'Shortcode' ),
            'aidocs_shortcode_meta_box_html',
            'aidoc',
            'side',
            'high'
        );
    }

    // Only relevant during setup, before this entry has content of its own —
    // once it does, the "one policy or many?" question (and the mode this
    // button belongs to) is answered and gone.
    if ( $post && ! $has_content ) {
        add_meta_box(
            'aidocs_publish_multi',
            __( 'Publish' ),
            'aidocs_publish_multi_meta_box_html',
            'aidoc',
            'side',
            'high'
        );
    }
}

function aidocs_publish_multi_meta_box_html( $post ) {
    ?>
    <div id="cd-publish-multi" class="cd-mode-multi-only">
        <p class="description" style="margin-top:0;">
            <?php esc_html_e( 'Each policy selected on the left becomes its own published entry — this one included.' ); ?>
        </p>
        <button type="button" id="cd-split-import-btn" class="button button-primary" style="width:100%;text-align:center;">
            &#10133; <?php esc_html_e( 'Create the selected entries' ); ?>
        </button>
        <div id="cd-split-progress" hidden style="margin-top:10px;"><span></span></div>
    </div>
    <?php
}

function aidocs_shortcode_meta_box_html( $post ) {
    ?>
    <div class="cd-shortcode-row" style="display:flex;gap:8px;">
        <input type="text" readonly id="cd-shortcode-field" onclick="this.select();" style="flex:1;font-family:Consolas,Monaco,monospace;font-size:13px;" value='[aidocs_document id="<?php echo (int) $post->ID; ?>"]'>
        <button type="button" class="button" id="cd-shortcode-copy"><?php esc_html_e( 'Copy' ); ?></button>
    </div>
    <script>
    (function(){
        var btn = document.getElementById('cd-shortcode-copy');
        var field = document.getElementById('cd-shortcode-field');
        if ( ! btn || ! field ) return;
        var defaultLabel = btn.textContent;
        btn.addEventListener('click', function(){
            field.select();
            navigator.clipboard && navigator.clipboard.writeText( field.value ).then( function(){
                btn.textContent = '<?php echo esc_js( __( 'Copied!' ) ); ?>';
                setTimeout( function(){ btn.textContent = defaultLabel; }, 1500 );
            } );
        });
    })();
    </script>
    <?php
}

function aidocs_meta_box_html( $post ) {
    wp_nonce_field( 'aidocs_save', 'aidocs_nonce' );

    $file_id     = get_post_meta( $post->ID, '_document_file_id', true );
    $pub_date    = get_post_meta( $post->ID, '_document_pub_date', true );
    $description = get_post_meta( $post->ID, '_document_description', true );
    $source_mode = get_post_meta( $post->ID, '_document_source_mode', true ) === 'multi' ? 'multi' : 'single';

    // Taxonomy terms
    $audience_terms = wp_get_post_terms( $post->ID, 'document_audience', [ 'fields' => 'names' ] );
    $type_terms     = wp_get_post_terms( $post->ID, 'document_type',     [ 'fields' => 'names' ] );

    $audience_val = implode( ', ', (array) $audience_terms );
    $type_val     = implode( ', ', (array) $type_terms );

    // File info
    $file_name = $file_url = $file_size = '';
    if ( $file_id ) {
        $file_path = get_attached_file( $file_id );
        $file_name = basename( $file_path );
        $file_url  = wp_get_attachment_url( $file_id );
        $file_size = $file_path && file_exists( $file_path )
            ? size_format( filesize( $file_path ) )
            : '';
        $title = get_the_title( $file_id );
    }

    $file_ext = $file_id ? strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) ) : '';

    // Add vs. Edit: a document with content of its own has already been set
    // up — through the normal upload flow, or written over by the
    // multi-policy importer, which never touches this form at all — so the
    // "what are you uploading?" question and the source-file upload card have
    // nothing left to ask. Keyed off content rather than post_status so a
    // draft saved before extraction ever ran still gets the setup flow.
    $is_new = ! aidocs_get_content_blocks( $post->ID );
    ?>
    <style>
        .aidocs-wrap { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .aidocs-wrap .cd-row { display: flex; gap: 24px; margin-bottom: 16px; }
        .aidocs-wrap .cd-col { flex: 1; }
        .aidocs-wrap label { display: block; font-weight: 600; margin-bottom: 6px; }
        .aidocs-wrap input[type="text"],
        .aidocs-wrap input[type="date"],
        .aidocs-wrap textarea { width: 100%; box-sizing: border-box; padding: 6px 10px; border: 1px solid #8c8f94; border-radius: 3px; }
        .aidocs-wrap textarea { height: 120px; resize: vertical; }
        .cd-file-card { display: flex; align-items: center; gap: 16px; padding: 14px 16px; border: 1px solid #e0e0e0; border-radius: 6px; margin-bottom: 10px; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
        .cd-file-icon { flex-shrink: 0; width: 44px; height: 52px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; letter-spacing: .5px; color: #fff; position: relative; }
        .cd-file-icon::after { content: ''; position: absolute; top: 0; right: 0; width: 0; height: 0; border-style: solid; border-width: 0 10px 10px 0; border-color: transparent rgba(0,0,0,.15) transparent transparent; }
        .cd-file-icon.pdf  { background: #e74c3c; }
        .cd-file-icon.word { background: #2b5797; }
        .cd-file-icon.excel { background: #1e7145; }
        .cd-file-icon.generic { background: #7f8c8d; }
        .cd-file-meta { flex: 1; min-width: 0; }
        .cd-file-meta strong { display: block; font-size: 14px; color: #1d2327; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .cd-file-meta span { display: inline-block; font-size: 12px; color: #646970; margin-right: 12px; }
        .cd-file-meta a { color: #2271b1; text-decoration: none; font-size: 12px; }
        .cd-file-meta a:hover { text-decoration: underline; }
        .cd-file-actions { display: flex; gap: 8px; flex-shrink: 0; }
        .cd-radio-group label { display: inline-flex; align-items: center; gap: 6px; font-weight: normal; margin-right: 16px; }
        .cd-select-wrap { position: relative; }
        .cd-select-box { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; min-height: 36px; padding: 5px 8px; border: 1px solid #8c8f94; border-radius: 3px; cursor: pointer; background: #fff; }
        .cd-select-box:focus-within { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; }
        .cd-tag { background: #e0e0e0; border-radius: 3px; padding: 2px 8px; font-size: 12px; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; }
        .cd-tag .remove-tag { cursor: pointer; color: #555; font-weight: bold; border: none; background: none; padding: 0; line-height: 1; font-size: 14px; }
        .cd-select-input { border: none; outline: none; flex: 1; min-width: 80px; font-size: 13px; padding: 0; background: transparent; }
        .cd-dropdown { display: none; position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #8c8f94; border-top: none; border-radius: 0 0 3px 3px; z-index: 9999; max-height: 220px; overflow-y: auto; box-shadow: 0 4px 8px rgba(0,0,0,.1); }
        .cd-dropdown.open { display: block; }
        .cd-dropdown li { list-style: none; padding: 8px 12px; cursor: pointer; font-size: 13px; }
        .cd-dropdown li:hover, .cd-dropdown li.highlighted { background: #4a90d9; color: #fff; }
        .cd-dropdown li.disabled { color: #aaa; background: #f5f5f5; cursor: default; }
        .required { color: red; }
        .cd-page-badge { cursor:pointer; background:#f0f6ff !important; border-color:#2271b1 !important; color:#2271b1 !important; font-size:11px !important; }
        .cd-page-badge:hover { background:#2271b1 !important; color:#fff !important; }
        .cd-ai-label { font-weight:normal; font-size:12px; display:inline-flex; align-items:center; gap:5px; color:#555; cursor:pointer; }
        .cd-custom-field { margin-bottom:10px; padding:10px 12px; border:1px solid #e5e5e5; border-radius:4px; background:#fafafa; }
        .cd-custom-field .cd-field-value { width:100%; box-sizing:border-box; margin-top:4px; }
        #cd-ai-process-wrap { padding:15px 18px; background:linear-gradient(135deg,#f0f6ff 0%,#e8f3ff 100%); border:1.5px solid #b8d4f5; border-top:none; border-radius:0 0 8px 8px; }
        #cd-ai-process-btn { font-size:13px !important; padding:6px 20px !important; height:auto !important; }
        /* Step 1: extraction (no AI) */
        #cd-extract-wrap { margin-top:20px; padding:15px 18px; border:1px solid #e0e0e0; border-radius:8px; background:#fafafa; }
        .cd-step-head { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .cd-step-head strong { font-size:13px; }
        .cd-step-hint { margin:6px 0 10px; font-size:12px; color:#646970; line-height:1.6; }
        .cd-step-status { font-size:12px; color:#555; }
        .cd-step-actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .cd-badge { font-size:11px; padding:2px 8px; border-radius:3px; }
        .cd-badge.is-ok { background:#d4edda; color:#155724; }
        .cd-badge.is-off { background:#f8d7da; color:#721c24; }
        .cd-badge.is-warn { background:#fff3cd; color:#7a5b00; }
        /* Content restructuring: set apart from the metadata fields, because it
           is a different task and a much larger request. */
        .cd-ai-content-box { margin-top:14px; padding:12px 14px; background:#fffdf6; border:1px solid #f0e3c4; border-left:3px solid #c8a24a; border-radius:0 6px 6px 0; }
        .cd-ai-fidelity { margin:10px 0; padding:9px 12px; border-radius:4px; font-size:12px; line-height:1.6; }
        .cd-ai-fidelity.is-ok { background:#eefaf1; border:1px solid #b7e2c4; color:#1c6b34; }
        .cd-ai-fidelity.is-warn { background:#fdf6e7; border:1px solid #ecd9a6; color:#7a5b00; }
        .cd-ai-fidelity strong { display:block; margin-bottom:3px; }
        .cd-preview { margin-top:12px; }
        .cd-preview summary { cursor:pointer; font-size:12px; color:#2271b1; }
        .cd-preview-body { max-height:340px; overflow-y:auto; margin-top:10px; padding:12px 14px; background:#fff; border:1px solid #e0e0e0; border-radius:4px; }
        .cd-preview-body .aidocs-content-h2 { font-size:15px; margin:14px 0 6px; }
        .cd-preview-body .aidocs-content-h3 { font-size:13px; margin:12px 0 5px; color:var(--wp--preset--color--secondary,#2c4a7c); }
        .cd-preview-body .aidocs-content-p, .cd-preview-body li { font-size:12.5px; line-height:1.7; color:#3c434a; }
        /* Extracted-content tabs: edit (default) vs. preview */
        .cd-tabs { margin-top:12px; }
        .cd-tabs-nav { display:flex; gap:2px; border-bottom:1px solid #dcdcde; }
        .cd-tab-btn { background:none; border:1px solid transparent; border-bottom:none; border-radius:4px 4px 0 0; padding:8px 14px; font-size:12.5px; font-weight:600; color:#646970; cursor:pointer; margin-bottom:-1px; }
        .cd-tab-btn:hover { color:#1d2327; }
        .cd-tab-btn.is-active { background:#fff; border-color:#dcdcde; color:#1d2327; }
        .cd-tab-panel { padding-top:12px; }
        /* Step 2: AI (opt-in) */
        #cd-ai-panel { margin-top:14px; }
        #cd-ai-panel > summary { cursor:pointer; font-size:13px; font-weight:600; padding:10px 14px; background:#f0f6ff; border:1.5px solid #b8d4f5; border-radius:8px; }
        #cd-ai-panel[open] > summary { border-radius:8px 8px 0 0; }
        .cd-ai-config { margin-bottom:14px; padding:10px 12px; background:#fff; border:1px solid #cfe0f5; border-radius:6px; }
        .cd-ai-config-row { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:8px; }
        .cd-ai-config-row:last-child { margin-bottom:0; }
        .cd-ai-config-row label { font-size:12px; white-space:nowrap; }
        #cd-ai-key { flex:1; min-width:220px; }
        #cd-ai-review { margin-top:14px; padding-top:12px; border-top:1px dashed #b8d4f5; }
        .cd-ai-card { margin-top:10px; padding:10px 12px; background:#fff; border:1px solid #cfe0f5; border-radius:6px; }
        .cd-ai-card-head { display:flex; align-items:center; gap:10px; margin-bottom:6px; }
        .cd-ai-card-head strong { font-size:12px; }
        .cd-ai-card-current { font-size:11px; color:#646970; }
        .cd-ai-card textarea, .cd-ai-card input[type="text"] { width:100%; box-sizing:border-box; font-size:12.5px; }
        .cd-ai-card textarea { height:auto; min-height:60px; }
        .cd-ai-card-actions { display:flex; gap:8px; margin-top:8px; }
        .cd-ai-card.is-applied { border-color:#a7d8b4; background:#f6fdf8; }
        /* The preview shows the same blocks the frontend renders, so it shares
           their stylesheet — notes, nested lists and tables included. */
        <?php echo aidocs_content_block_css(); // phpcs:ignore WordPress.Security.EscapeOutput -- static CSS ?>
        /* Step 0: what kind of upload this is. Everything below depends on it,
           so it is the first thing on the screen and reads as a question. */
        #cd-mode-wrap { margin-bottom:18px; }
        #cd-mode-wrap > label { margin-bottom:8px; }
        #cd-mode-wrap .cd-mode-toggle { display:flex; align-items:stretch; gap:10px; }
        /* Specificity over ".aidocs-wrap label { display:block }" above: without
           the #cd-mode-wrap prefix that rule wins instead (equal-weight class +
           element selector, declared first), display:block replaces the flex
           layout on .cd-mode-option, and the two cards go back to their own
           content height instead of stretching to match each other. */
        #cd-mode-wrap .cd-mode-option { flex:1; display:flex; position:relative; margin:0; cursor:pointer; }
        #cd-mode-wrap .cd-mode-option input { position:absolute; opacity:0; width:0; height:0; }
        #cd-mode-wrap .cd-mode-option-card { flex:1; display:flex; flex-direction:column; padding:14px 40px 14px 16px; border:1.5px solid #dcdcde; border-radius:8px; background:#fff; transition:border-color .15s, background .15s, box-shadow .15s; }
        #cd-mode-wrap .cd-mode-option-card strong { display:block; font-size:13.5px; color:#1d2327; margin-bottom:4px; }
        #cd-mode-wrap .cd-mode-option-card span { display:block; font-size:11.5px; color:#646970; font-weight:normal; line-height:1.5; }
        #cd-mode-wrap .cd-mode-option:hover .cd-mode-option-card { border-color:#b8d4f5; }
        #cd-mode-wrap .cd-mode-option input:checked + .cd-mode-option-card { border-color:#2271b1; background:#f0f6ff; box-shadow:0 0 0 1px #2271b1; }
        #cd-mode-wrap .cd-mode-option input:checked + .cd-mode-option-card strong { color:#0a4b78; }
        #cd-mode-wrap .cd-mode-option input:focus-visible + .cd-mode-option-card { outline:2px solid #2271b1; outline-offset:1px; }
        /* The radio circle itself — a visible bullet, since the styled card
           replaces the browser's own radio appearance entirely. */
        #cd-mode-wrap .cd-mode-radio-dot { position:absolute; top:14px; right:14px; width:18px; height:18px; box-sizing:border-box; border:1.5px solid #b8bfc7; border-radius:50%; background:#fff; }
        #cd-mode-wrap .cd-mode-option input:checked + .cd-mode-option-card .cd-mode-radio-dot { border-color:#2271b1; background:#2271b1; box-shadow:inset 0 0 0 3px #fff; }
        @media(max-width:700px){ #cd-mode-wrap .cd-mode-toggle { flex-direction:column; } }
        /* Several policies in one upload */
        #cd-split-wrap { margin-top:20px; padding:15px 18px; border:1px solid #e0e0e0; border-radius:8px; background:#fafafa; }
        #cd-split-list { margin-top:10px; max-height:420px; overflow-y:auto; border:1px solid #e0e0e0; border-radius:4px; background:#fff; }
        .cd-split-item { display:flex; gap:10px; padding:9px 12px; border-bottom:1px solid #f0f0f1; font-size:12.5px; }
        .cd-split-item:last-child { border-bottom:none; }
        .cd-split-item.is-done { background:#f6fdf8; }
        .cd-split-item-body { flex:1; min-width:0; }
        .cd-split-item-title { font-weight:600; color:#1d2327; }
        .cd-split-item-meta { font-size:11px; color:#646970; margin-top:2px; }
        .cd-split-item-teaser { font-size:11.5px; color:#50575e; margin-top:3px; line-height:1.5; }
        #cd-split-progress { height:6px; margin-top:10px; border-radius:3px; background:#e5e5e5; overflow:hidden; }
        #cd-split-progress span { display:block; height:100%; width:0; background:#2271b1; transition:width .25s; }
        #cd-split-result ul { margin:8px 0 0; }
        #cd-split-result li { font-size:12.5px; margin-bottom:4px; }
        #cd-add-field-form { margin-top:8px; padding:10px 12px; border:1px solid #e0e0e0; border-radius:4px; background:#f9f9f9; }
        .cd-ai-field-option { display:flex; align-items:center; gap:6px; padding:3px 0; font-size:13px; cursor:pointer; font-weight:normal; margin:0; }
        #cd-ai-fields-list { margin-top:4px; padding-left:20px; }
    </style>

    <div class="aidocs-wrap">

        <?php if ( $is_new ) : ?>
        <!-- Step 0 — one policy, or a document holding many. Everything below
             this reads from it: what extraction does with the text, which
             fields are the editor's to fill, and whether the AI panel applies
             at all. Only asked once: a document that already has content has
             already answered it. -->
        <div id="cd-mode-wrap">
            <label><?php esc_html_e( 'What are you uploading?' ); ?></label>
            <div class="cd-mode-toggle">
                <label class="cd-mode-option">
                    <input type="radio" name="document_source_mode" value="single" <?php checked( $source_mode, 'single' ); ?>>
                    <span class="cd-mode-option-card">
                        <span class="cd-mode-radio-dot" aria-hidden="true"></span>
                        <strong><?php esc_html_e( 'One policy' ); ?></strong>
                        <span><?php esc_html_e( 'This entry is the policy.' ); ?></span>
                    </span>
                </label>
                <label class="cd-mode-option">
                    <input type="radio" name="document_source_mode" value="multi" <?php checked( $source_mode, 'multi' ); ?>>
                    <span class="cd-mode-option-card">
                        <span class="cd-mode-radio-dot" aria-hidden="true"></span>
                        <strong><?php esc_html_e( 'A document holding several policies' ); ?></strong>
                        <span><?php esc_html_e( 'Each policy inside it becomes an entry of its own. Works for PDF and Word (.docx) — the splitter reads the same extracted text as everything else, so an Excel file cannot be split; switch to "One policy" and upload each one on its own instead.' ); ?></span>
                    </span>
                </label>
            </div>
        </div>

        <!-- Source file. It is read for its text and nothing else: it is never
             linked, offered for download or shown to a reader. -->
        <div style="margin-bottom:16px;">
            <label><?php esc_html_e( 'Source file' ); ?> <span class="required">*</span></label>

            <?php
            $ext        = $file_ext;
            $icon_class = match( $ext ) { 'pdf' => 'pdf', 'doc', 'docx' => 'word', 'xls', 'xlsx' => 'excel', default => 'generic' };
            $icon_label = match( $ext ) { 'pdf' => 'PDF', 'doc' => 'DOC', 'docx' => 'DOCX', 'xls' => 'XLS', 'xlsx' => 'XLSX', default => strtoupper( $ext ) ?: 'FILE' };
            ?>
            <div class="cd-file-card" id="cd-file-preview" style="margin-bottom:0;<?php echo $file_id ? '' : 'display:none;'; ?>">
                <div class="cd-file-icon <?php echo esc_attr( $icon_class ); ?>" id="cd-file-icon-badge"><?php echo esc_html( $icon_label ); ?></div>
                <div class="cd-file-meta" id="cd-file-meta">
                    <?php if ( $file_id ) : ?>
                    <strong><?php echo esc_html( $title ?: $file_name ); ?></strong>
                    <span><a href="<?php echo esc_url( $file_url ); ?>" target="_blank"><?php echo esc_html( $file_name ); ?></a></span>
                    <?php if ( $file_size ) : ?><span><?php echo esc_html( $file_size ); ?></span><?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="cd-file-actions">
                    <button type="button" id="cd-upload-btn" class="button button-small"><?php esc_html_e( 'Replace' ); ?></button>
                    <button type="button" id="cd-remove-file" class="button button-small"><?php esc_html_e( 'Remove' ); ?></button>
                </div>
            </div>

            <p class="cd-step-hint" style="margin:6px 0 0;">
                <?php esc_html_e( 'Read for its text only — the file itself is never published, linked or offered for download.' ); ?>
            </p>

            <?php if ( ! $file_id ) : ?>
            <button type="button" id="cd-upload-btn" class="button"><?php esc_html_e( 'Upload source file' ); ?></button>
            <?php endif; ?>
        </div>
        <?php elseif ( $file_id ) : ?>
        <!-- Edit mode: the source file was fixed at creation — correct a
             misread extraction through the "Edit content" tab below instead
             of replacing the file. -->
        <p class="cd-step-hint" style="margin:0 0 16px;">
            <?php
            printf(
                /* translators: 1: file name, 2: file extension (e.g. PDF) */
                esc_html__( 'Source: %1$s (%2$s) — read for its text only, never shown to readers.' ),
                esc_html( $title ?: $file_name ),
                esc_html( strtoupper( $file_ext ) )
            );
            ?>
        </p>
        <?php endif; ?>

        <div id="cd-page-badges-wrap" style="<?php echo ( $file_id && in_array( $file_ext, [ 'pdf', 'docx' ], true ) ) ? '' : 'display:none;'; ?>padding:8px 0 4px;">
            <div id="cd-page-badges" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
            <span id="cd-extract-status" style="display:block;font-size:11px;color:#999;margin-top:5px;min-height:14px;"></span>
        </div>

        <input type="hidden" name="document_file_id" id="cd-file-id" value="<?php echo esc_attr( $file_id ); ?>">
        <input type="hidden" id="cd-file-url" value="<?php echo esc_attr( $file_url ); ?>">

        <!-- Last Updated. Read from the document's own "Last Updated" label
             when it carries one, so this is normally left alone. -->
        <div class="cd-row cd-mode-single-only">
            <div class="cd-col">
                <label for="cd-pub-date"><?php esc_html_e( 'Last Updated' ); ?> <span class="required">*</span></label>
                <input type="date" id="cd-pub-date" name="document_pub_date" value="<?php echo esc_attr( $pub_date ); ?>">
            </div>
            <div class="cd-col"></div>
        </div>

        <!-- Audience + Document Type — this document's own. In a multi-policy
             upload there is no single value that fits every policy inside it,
             so this row does not apply there: the split panel below completes
             them per policy with AI instead. -->
        <div class="cd-row cd-mode-single-only">
            <div class="cd-col">
                <label><?php esc_html_e( 'Audience' ); ?></label>
                <div class="cd-select-wrap">
                    <div class="cd-select-box" id="cd-audience-box">
                        <?php foreach ( (array) $audience_terms as $term ) : ?>
                        <span class="cd-tag"><?php echo esc_html( $term ); ?><button type="button" class="remove-tag" aria-label="Remove">×</button></span>
                        <?php endforeach; ?>
                        <input type="text" class="cd-select-input" placeholder="<?php esc_attr_e( 'Select audience…' ); ?>" autocomplete="off">
                    </div>
                    <ul class="cd-dropdown" id="cd-audience-dropdown">
                        <?php foreach ( aidocs_get_audiences() as $opt ) : ?>
                        <li data-value="<?php echo esc_attr( $opt ); ?>"><?php echo esc_html( $opt ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <input type="hidden" name="document_audience_terms" id="cd-audience-value" value="<?php echo esc_attr( $audience_val ); ?>">
            </div>
            <div class="cd-col">
                <label><?php esc_html_e( 'Document Type' ); ?></label>
                <div class="cd-select-wrap">
                    <div class="cd-select-box" id="cd-type-box">
                        <?php foreach ( (array) $type_terms as $term ) : ?>
                        <span class="cd-tag"><?php echo esc_html( $term ); ?><button type="button" class="remove-tag" aria-label="Remove">×</button></span>
                        <?php endforeach; ?>
                        <input type="text" class="cd-select-input" placeholder="<?php esc_attr_e( 'Select type…' ); ?>" autocomplete="off">
                    </div>
                    <ul class="cd-dropdown" id="cd-type-dropdown">
                        <?php foreach ( aidocs_get_types() as $opt ) : ?>
                        <li data-value="<?php echo esc_attr( $opt ); ?>"><?php echo esc_html( $opt ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <input type="hidden" name="document_type_terms" id="cd-type-value" value="<?php echo esc_attr( $type_val ); ?>">
            </div>
        </div>

        <!-- Description -->
        <div style="margin-bottom:16px;" class="cd-mode-single-only">
            <label for="cd-description"><?php esc_html_e( 'Description' ); ?></label>
            <textarea id="cd-description" name="document_description" rows="3"><?php echo esc_textarea( $description ); ?></textarea>
        </div>

        <!-- Document History. Read from the source document's own "Document
             History" label ("Adopted … · Revised …") when it carries one,
             but editable here like every other extracted field. -->
        <div style="margin-bottom:16px;" class="cd-mode-single-only">
            <label for="cd-history"><?php esc_html_e( 'Document History' ); ?></label>
            <textarea id="cd-history" name="document_history" rows="2"><?php echo esc_textarea( get_post_meta( $post->ID, '_document_history', true ) ); ?></textarea>
        </div>

        <?php
        $content_blocks = aidocs_get_content_blocks( $post->ID );
        $has_content    = (bool) $content_blocks;
        $has_emb        = (bool) get_post_meta( $post->ID, '_document_embedding', true );
        $ai_key_set     = (bool) get_option( 'aidocs_gemini_api_key', '' );
        $ai_model       = get_option( 'aidocs_gemini_model', 'gemini-2.5-flash' );
        $can_setup_ai   = current_user_can( 'manage_options' );
        ?>

        <?php if ( $is_new ) : ?>
        <!-- Step 1b — several policies in one upload. The split is regular
             expressions over the same labels extraction already matches, so it
             needs no AI and no API key either. Only relevant while setting a
             document up: an existing entry is always a single policy by now. -->
        <div id="cd-split-wrap" class="cd-mode-multi-only">
            <div class="cd-step-head">
                <strong><?php esc_html_e( 'Policies in this document' ); ?></strong>
                <span id="cd-split-badge" class="cd-badge is-off"><?php esc_html_e( 'Not read yet' ); ?></span>
                <span id="cd-split-status" class="cd-step-status"></span>
            </div>
            <p class="cd-step-hint">
                <?php esc_html_e( 'Each policy inside the file is found by its own Teaser / Body / Last Updated labels — no AI, no API key — and its title, description, date, history and content are read the same way a policy uploaded on its own would be. Review the list, then create one entry per policy. The first one is written over this entry; the rest are added as new ones.' ); ?>
            </p>
            <div class="cd-step-actions">
                <button type="button" id="cd-split-detect-btn" class="button">
                    &#128269; <?php esc_html_e( 'Read the policies again' ); ?>
                </button>
            </div>

            <!-- Audience and Document Type have no label of their own in the
                 source, so — same as the single-policy panel below — the AI
                 fills whichever of these are ticked. It runs once per policy
                 when the entries are created, with no review step in between:
                 reviewing forty-nine proposals one at a time is exactly what
                 this batch flow exists to avoid. -->
            <div id="cd-split-ai">
                <strong style="font-size:13px;display:block;margin:14px 0 6px;"><?php esc_html_e( 'Complete fields with AI, per policy:' ); ?></strong>
                <div id="cd-split-ai-fields">
                    <label class="cd-ai-field-option">
                        <input type="checkbox" class="cd-split-ai-check" data-field-id="title">
                        <?php esc_html_e( 'Title' ); ?>
                    </label>
                    <label class="cd-ai-field-option">
                        <input type="checkbox" class="cd-split-ai-check" data-field-id="description">
                        <?php esc_html_e( 'Description' ); ?>
                    </label>
                    <label class="cd-ai-field-option">
                        <input type="checkbox" class="cd-split-ai-check" data-field-id="audience" checked>
                        <?php esc_html_e( 'Audience' ); ?>
                    </label>
                    <label class="cd-ai-field-option">
                        <input type="checkbox" class="cd-split-ai-check" data-field-id="document_type" checked>
                        <?php esc_html_e( 'Document Type' ); ?>
                    </label>
                </div>
                <p class="cd-step-hint">
                    <?php if ( $ai_key_set ) : ?>
                        <?php esc_html_e( 'Title and Description are already read from the labels above on almost every policy, so they only need this when one is missing. Requires the Gemini key configured below.' ); ?>
                    <?php else : ?>
                        <?php esc_html_e( 'No Gemini API key is configured — a field ticked here is imported empty until an administrator adds one in Documents → Settings.' ); ?>
                    <?php endif; ?>
                </p>
            </div>

            <div id="cd-split-review" hidden>
                <div class="cd-step-head" style="margin-top:14px;">
                    <label class="cd-ai-label">
                        <input type="checkbox" id="cd-split-all" checked>
                        <?php esc_html_e( 'Select all' ); ?>
                    </label>
                </div>
                <div id="cd-split-list"></div>
            </div>

            <div id="cd-split-result" hidden>
                <div class="cd-ai-fidelity is-ok">
                    <strong id="cd-split-result-head"></strong>
                    <ul id="cd-split-result-list"></ul>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Step 1 — extraction, no AI. This is what runs by default. -->
        <div id="cd-extract-wrap" class="cd-mode-single-only">
            <div class="cd-step-head">
                <strong><?php esc_html_e( 'Document content' ); ?></strong>
                <span id="cd-content-badge" class="cd-badge <?php echo $has_content ? 'is-ok' : 'is-off'; ?>">
                    <?php
                    echo $has_content
                        /* translators: %d: number of extracted content blocks. */
                        ? '&#10003; ' . esc_html( sprintf( _n( '%d block', '%d blocks', count( $content_blocks ) ), count( $content_blocks ) ) )
                        : esc_html__( 'No content' );
                    ?>
                </span>
                <?php if ( $ai_key_set || $has_emb ) : ?>
                <span id="cd-embedding-badge" class="cd-badge <?php echo $has_emb ? 'is-ok' : 'is-off'; ?>">
                    <?php echo $has_emb ? '&#10003; ' . esc_html__( 'Indexed for AI search' ) : esc_html__( 'Indexing…' ); ?>
                </span>
                <?php endif; ?>
                <span id="cd-ai-status" class="cd-step-status"></span>
            </div>
            <p class="cd-step-hint">
                <?php esc_html_e( 'Title, teaser, date, headings, notes, lists and tables are read straight from the PDF or Word file — no AI, no API key. This runs on its own when a file is loaded. Basic search always works from this alone; when a Gemini key is configured below, the document is also indexed automatically for semantic AI matching — there is nothing to click for either.' ); ?>
            </p>
            <div class="cd-step-actions">
                <button type="button" id="cd-extract-content-btn" class="button">
                    &#128196; <?php esc_html_e( 'Extract content again' ); ?>
                </button>
            </div>
            <div id="cd-content-tabs" class="cd-tabs" <?php echo $has_content ? '' : 'hidden'; ?>>
                <div class="cd-tabs-nav" role="tablist">
                    <button type="button" class="cd-tab-btn is-active" data-tab="edit" role="tab" aria-selected="true">
                        <?php esc_html_e( 'Edit content' ); ?>
                    </button>
                    <button type="button" class="cd-tab-btn" data-tab="preview" role="tab" aria-selected="false">
                        <?php esc_html_e( 'Preview' ); ?>
                    </button>
                </div>

                <!-- Edit tab — the default: what most edits to a document are,
                     after it already has content. -->
                <div class="cd-tab-panel" data-tab-panel="edit">
                    <p class="cd-step-hint">
                        <?php esc_html_e( 'This is the plain text the parser above reads — the same headings, lists, tables and bold/italic markup (##, -, |, **, *) documented for a PDF. Fix a misread line or add missing text here, then apply it: the content, description, date and history are re-parsed from what you type, exactly as if it had been extracted from the source file.' ); ?>
                    </p>
                    <textarea id="cd-raw-text-edit" rows="16" style="width:100%;font-family:ui-monospace,Consolas,monospace;font-size:12px;"><?php echo esc_textarea( aidocs_get_raw_text( $post->ID ) ); ?></textarea>
                    <div class="cd-step-actions" style="margin-top:8px;">
                        <button type="button" id="cd-apply-raw-text-btn" class="button button-primary">
                            <?php esc_html_e( 'Apply edited content' ); ?>
                        </button>
                    </div>
                </div>

                <!-- Preview tab — the same HTML the frontend renders, to check
                     the result without leaving the editor. -->
                <div class="cd-tab-panel" data-tab-panel="preview" hidden>
                    <div id="cd-content-preview-body" class="cd-preview-body">
                        <?php echo aidocs_render_content_blocks( $content_blocks ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in renderer ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 2 — AI, opt-in, and only ever proposes values. It proposes them
             for one document's fields, so it has nothing to say about an upload
             that is about to become fifty. -->
        <details id="cd-ai-panel" class="cd-mode-single-only">
            <summary>&#9889; <?php esc_html_e( 'Complete fields with AI (optional)' ); ?></summary>
            <div id="cd-ai-process-wrap">
                <p class="cd-step-hint">
                    <?php esc_html_e( 'The AI reads the extracted text and proposes values for the fields you tick. Nothing is written into the form until you apply it.' ); ?>
                </p>

                <div id="cd-ai-config" class="cd-ai-config" data-configured="<?php echo $ai_key_set ? '1' : '0'; ?>">
                    <?php if ( $can_setup_ai ) : ?>
                    <div id="cd-ai-config-state" class="cd-ai-config-row" <?php echo $ai_key_set ? '' : 'hidden'; ?>>
                        <span class="cd-badge is-ok">&#10003; <?php esc_html_e( 'API key saved' ); ?></span>
                        <span class="cd-step-hint" style="margin:0;">
                            <?php esc_html_e( 'Model:' ); ?> <code id="cd-ai-model-label"><?php echo esc_html( $ai_model ); ?></code>
                        </span>
                        <button type="button" id="cd-ai-config-toggle" class="button-link"><?php esc_html_e( 'Change' ); ?></button>
                    </div>
                    <div id="cd-ai-config-form" class="cd-ai-config-form" <?php echo $ai_key_set ? 'hidden' : ''; ?>>
                        <div class="cd-ai-config-row">
                            <label for="cd-ai-key" style="margin:0;"><?php esc_html_e( 'Gemini API key' ); ?></label>
                            <input type="password" id="cd-ai-key" autocomplete="off" spellcheck="false"
                                placeholder="<?php echo $ai_key_set ? esc_attr__( 'Saved — leave blank to keep it' ) : 'AIza…'; ?>">
                            <button type="button" id="cd-ai-config-load" class="button"><?php esc_html_e( 'Check key & list models' ); ?></button>
                        </div>
                        <div class="cd-ai-config-row">
                            <label for="cd-ai-model" style="margin:0;"><?php esc_html_e( 'Model' ); ?></label>
                            <select id="cd-ai-model">
                                <?php
                                $mb_models = aidocs_model_catalog();
                                if ( $ai_model !== '' && ! isset( $mb_models[ $ai_model ] ) ) {
                                    $mb_models = [ $ai_model => $ai_model . ' (saved)' ] + $mb_models;
                                }
                                foreach ( $mb_models as $mid => $mname ) :
                                ?>
                                <option value="<?php echo esc_attr( $mid ); ?>" <?php selected( $ai_model, $mid ); ?>><?php echo esc_html( $mname ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" id="cd-ai-config-save" class="button button-secondary"><?php esc_html_e( 'Save' ); ?></button>
                            <span id="cd-ai-config-status" class="cd-step-status"></span>
                        </div>
                        <p class="cd-step-hint">
                            <?php esc_html_e( 'Stored in Documents → Settings, and used by semantic search and the assistant as well.' ); ?>
                        </p>
                    </div>
                    <?php elseif ( $ai_key_set ) : ?>
                    <p class="cd-step-hint cd-ai-config-row">
                        <span class="cd-badge is-ok">&#10003; <?php esc_html_e( 'API key saved' ); ?></span>
                        <?php esc_html_e( 'Model:' ); ?> <code><?php echo esc_html( $ai_model ); ?></code>
                    </p>
                    <?php else : ?>
                    <p class="cd-step-hint cd-ai-config-row">
                        <span class="cd-badge is-off"><?php esc_html_e( 'AI not configured' ); ?></span>
                        <?php esc_html_e( 'Ask an administrator to add a Gemini API key in Documents → Settings. Extraction above works without it.' ); ?>
                    </p>
                    <?php endif; ?>
                </div>

                <div style="margin-bottom:12px;">
                    <strong style="font-size:13px;display:block;margin-bottom:6px;"><?php esc_html_e( 'Fields to propose:' ); ?></strong>
                    <label style="display:flex;align-items:center;gap:6px;padding:3px 0;font-weight:600;font-size:13px;cursor:pointer;margin:0;">
                        <input type="checkbox" id="cd-ai-select-all">
                        <?php esc_html_e( 'Select All' ); ?>
                    </label>
                    <div id="cd-ai-fields-list">
                        <label class="cd-ai-field-option">
                            <input type="checkbox" class="cd-ai-field-check" data-field-id="title">
                            <?php esc_html_e( 'Title' ); ?>
                        </label>
                        <label class="cd-ai-field-option">
                            <input type="checkbox" class="cd-ai-field-check" data-field-id="description" checked>
                            <?php esc_html_e( 'Description' ); ?>
                        </label>
                        <label class="cd-ai-field-option">
                            <input type="checkbox" class="cd-ai-field-check" data-field-id="audience">
                            <?php esc_html_e( 'Audience' ); ?>
                        </label>
                        <label class="cd-ai-field-option">
                            <input type="checkbox" class="cd-ai-field-check" data-field-id="document_type">
                            <?php esc_html_e( 'Document Type' ); ?>
                        </label>
                    </div>
                </div>

                <div class="cd-step-actions">
                    <button type="button" id="cd-ai-process-btn" class="button button-primary">
                        &#9889; <?php esc_html_e( 'Propose with AI' ); ?>
                    </button>
                </div>

                <div id="cd-ai-review" hidden>
                    <div class="cd-step-head">
                        <strong><?php esc_html_e( 'Proposed values' ); ?></strong>
                        <button type="button" id="cd-ai-apply-all" class="button button-small"><?php esc_html_e( 'Apply all' ); ?></button>
                        <button type="button" id="cd-ai-discard-all" class="button button-small"><?php esc_html_e( 'Discard all' ); ?></button>
                    </div>
                    <div id="cd-ai-review-list"></div>
                </div>

                <!-- Content restructuring: its own request, its own cost, and a
                     different kind of task from the metadata fields above — so
                     it is deliberately not one more checkbox in that list. -->
                <div id="cd-ai-restructure" class="cd-ai-content-box">
                    <div class="cd-step-head">
                        <strong><?php esc_html_e( 'Document content structure' ); ?></strong>
                        <span class="cd-badge is-warn"><?php esc_html_e( 'Whole document · higher cost' ); ?></span>
                    </div>
                    <p class="cd-step-hint">
                        <?php esc_html_e( 'Only for a PDF whose layout the extractor above misread — a heading left as a paragraph, a list flattened into prose. The AI does not write anything: it re-decides which block each piece of the already-extracted text belongs to, reusing that text verbatim. The whole document is sent in one request, so this costs considerably more than the fields above.' ); ?>
                    </p>
                    <div class="cd-step-actions">
                        <button type="button" id="cd-ai-restructure-btn" class="button">
                            &#129518; <?php esc_html_e( 'Restructure content with AI' ); ?>
                        </button>
                        <span id="cd-ai-restructure-status" class="cd-step-status"></span>
                    </div>
                    <div id="cd-ai-restructure-review" hidden>
                        <div id="cd-ai-restructure-report" class="cd-ai-fidelity"></div>
                        <details class="cd-preview">
                            <summary><?php esc_html_e( 'Review the restructured content' ); ?></summary>
                            <div id="cd-ai-restructure-preview" class="cd-preview-body"></div>
                        </details>
                        <div class="cd-step-actions" style="margin-top:10px;">
                            <button type="button" id="cd-ai-restructure-apply" class="button button-primary"><?php esc_html_e( 'Replace content with this' ); ?></button>
                            <button type="button" id="cd-ai-restructure-discard" class="button"><?php esc_html_e( 'Discard' ); ?></button>
                        </div>
                    </div>
                </div>
            </div>
        </details>

    </div><!-- .aidocs-wrap -->

    <!-- Page Text Modal -->
    <div id="cd-page-modal" style="display:none;position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.65);">
        <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:720px;max-width:90vw;max-height:85vh;background:#fff;border-radius:6px;display:flex;flex-direction:column;box-shadow:0 8px 40px rgba(0,0,0,.3);">
            <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-bottom:1px solid #e0e0e0;">
                <h3 id="cd-modal-title" style="margin:0;font-size:15px;"></h3>
                <button type="button" id="cd-modal-close" style="background:none;border:none;cursor:pointer;font-size:24px;color:#666;line-height:1;padding:0;">&times;</button>
            </div>
            <div style="flex:1;overflow-y:auto;padding:20px;">
                <pre id="cd-modal-content" style="white-space:pre-wrap;font-family:ui-monospace,'Cascadia Code',Menlo,monospace;margin:0;font-size:12.5px;line-height:1.7;color:#1d2327;"></pre>
            </div>
            <div style="padding:10px 20px;border-top:1px solid #e0e0e0;display:flex;justify-content:flex-end;">
                <button type="button" id="cd-modal-copy" class="button"><?php esc_html_e( 'Copy Text' ); ?></button>
            </div>
        </div>
    </div>

    <script>
    (function($) {
        var cdAjaxUrl         = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
        var cdAjaxNonce       = <?php echo wp_json_encode( wp_create_nonce( 'aidocs_ai' ) ); ?>;
        var cdAudienceOptions = <?php echo wp_json_encode( aidocs_get_audiences() ); ?>;
        var cdTypeOptions     = <?php echo wp_json_encode( aidocs_get_types() ); ?>;
        var cdDocId           = <?php echo (int) $post->ID; ?>;

        // ── Media uploader ──────────────────────────────
        var mediaFrame;
        var iconMap = { pdf: 'pdf', doc: 'word', docx: 'word', xls: 'excel', xlsx: 'excel' };

        function extOf(filename) {
            return (filename.split('.').pop() || '').toLowerCase();
        }
        function iconClass(ext) { return iconMap[ext] || 'generic'; }
        function iconLabel(ext) {
            var labels = { pdf:'PDF', doc:'DOC', docx:'DOCX', xls:'XLS', xlsx:'XLSX' };
            return labels[ext] || (ext ? ext.toUpperCase() : 'FILE');
        }
        function cdTitleFromFilename(filename) {
            var name = filename.replace(/\.[^/.]+$/, '');
            name = name.replace(/[-_]+/g, ' ').trim();
            return name.replace(/\b\w/g, function(c) { return c.toUpperCase(); });
        }

        $(document).on('click', '#cd-upload-btn', function(e) {
            e.preventDefault();
            if (mediaFrame) { mediaFrame.open(); return; }
            mediaFrame = wp.media({ title: 'Select Document', button: { text: 'Use this file' }, multiple: false });
            mediaFrame.on('select', function() {
                var a   = mediaFrame.state().get('selection').first().toJSON();
                var ext = extOf(a.filename);
                $('#cd-file-id').val(a.id);
                $('#cd-file-url').val(a.url);
                $('#cd-file-icon-badge')
                    .attr('class', 'cd-file-icon ' + iconClass(ext))
                    .text(iconLabel(ext));
                $('#cd-file-meta').html(
                    '<strong>' + $('<span>').text(a.title || a.filename).html() + '</strong>' +
                    '<span><a href="' + a.url + '" target="_blank">' + $('<span>').text(a.filename).html() + '</a></span>' +
                    (a.filesizeHumanReadable ? '<span>' + a.filesizeHumanReadable + '</span>' : '')
                );
                var autoTitle = cdTitleFromFilename(a.filename);
                if (autoTitle) { $('#title').val(autoTitle).trigger('input').trigger('keyup').trigger('focus').trigger('blur'); }
                $('#cd-file-preview').show();
                // swap standalone upload button for the card's Replace button
                $('#cd-upload-btn').not('#cd-file-preview #cd-upload-btn').hide();
                // A different file means the stored content no longer describes
                // this document, so extraction runs again for the new one.
                cdHasContent = false;
                if (ext === 'pdf') cdExtractPdf(a.url);
                else if (ext === 'docx') cdExtractDocx(a.url);
                else $('#cd-page-badges-wrap').hide();
            });
            mediaFrame.open();
        });

        $(document).on('click', '#cd-remove-file', function() {
            $('#cd-file-id').val('');
            $('#cd-file-url').val('');
            $('#cd-file-preview').hide();
            $('#cd-file-meta').html('');
            $('#cd-page-badges-wrap').hide();
            $('#cd-page-badges').empty();
            $('#cd-extract-status').text('');
            cdPageTexts = {};
            // show standalone upload button if visible
            var $standalone = $('button#cd-upload-btn').not('#cd-file-preview button#cd-upload-btn');
            if (!$standalone.length) {
                $('#cd-file-preview').after('<button type="button" id="cd-upload-btn" class="button">Upload source file</button>');
            } else {
                $standalone.show();
            }
        });

        // ── Dropdown tag selects ──────────────────────
        function initDropdownSelect(boxId, dropdownId, hiddenId) {
            var $box      = $('#' + boxId);
            var $dropdown = $('#' + dropdownId);
            var $hidden   = $('#' + hiddenId);
            var $input    = $box.find('.cd-select-input');

            function selectedValues() {
                var vals = [];
                $box.find('.cd-tag').each(function() {
                    vals.push($(this).find('.remove-tag').siblings(':not(.remove-tag)').addBack().not('.remove-tag').map(function(){
                        return this.nodeType === 3 ? this.nodeValue.trim() : '';
                    }).get().join('').trim());
                });
                // simpler: read text excluding the × button
                vals = [];
                $box.find('.cd-tag').each(function() {
                    vals.push($(this).clone().find('.remove-tag').remove().end().text().trim());
                });
                return vals;
            }

            function updateHidden() { $hidden.val(selectedValues().join(', ')); }

            function refreshDropdown(filter) {
                var selected = selectedValues();
                var shown = 0;
                $dropdown.find('li').each(function() {
                    var val  = $(this).data('value');
                    var text = $(this).text().toLowerCase();
                    var hide = selected.indexOf(val) !== -1;
                    var match = !filter || text.indexOf(filter.toLowerCase()) !== -1;
                    $(this).toggle(!hide && match);
                    if (!hide && match) shown++;
                });
                $dropdown.toggleClass('open', shown > 0);
            }

            function addTag(val) {
                if (!val || selectedValues().indexOf(val) !== -1) return;
                $input.before(
                    '<span class="cd-tag">' + $('<span>').text(val).html() +
                    '<button type="button" class="remove-tag" aria-label="Remove">×</button></span>'
                );
                $input.val('');
                updateHidden();
                refreshDropdown('');
            }

            // Open on box click
            $box.on('click', function(e) {
                if ($(e.target).hasClass('remove-tag')) return;
                $input.focus();
                refreshDropdown($input.val());
            });

            // Filter while typing
            $input.on('input', function() {
                refreshDropdown($(this).val());
            });

            // Pick from dropdown
            $dropdown.on('click', 'li', function() {
                addTag($(this).data('value'));
                $input.focus();
            });

            // Remove tag
            $box.on('click', '.remove-tag', function(e) {
                e.stopPropagation();
                $(this).closest('.cd-tag').remove();
                updateHidden();
                refreshDropdown($input.val());
            });

            // Close on outside click
            $(document).on('click', function(e) {
                if (!$box.closest('.cd-select-wrap')[0].contains(e.target)) {
                    $dropdown.removeClass('open');
                    $input.val('');
                }
            });

            // Keyboard: Enter selects highlighted
            $input.on('keydown', function(e) {
                if (e.key === 'Escape') { $dropdown.removeClass('open'); return; }
                var $vis = $dropdown.find('li:visible');
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    var idx = $vis.index($vis.filter('.highlighted'));
                    $vis.removeClass('highlighted');
                    $vis.eq(Math.min(idx + 1, $vis.length - 1)).addClass('highlighted');
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    var idx = $vis.index($vis.filter('.highlighted'));
                    $vis.removeClass('highlighted');
                    $vis.eq(Math.max(idx - 1, 0)).addClass('highlighted');
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    var $h = $vis.filter('.highlighted');
                    if ($h.length) addTag($h.data('value'));
                }
            });

            function clearTags() {
                $box.find('.cd-tag').remove();
                updateHidden();
            }

            return { addTag: addTag, clearTags: clearTags };
        }

        var cdAudienceSelect = initDropdownSelect('cd-audience-box', 'cd-audience-dropdown', 'cd-audience-value');
        var cdTypeSelect     = initDropdownSelect('cd-type-box',     'cd-type-dropdown',     'cd-type-value');

        // ── PDF Text Extraction ───────────────────────
        // Declared ahead of the upload-mode block below: cdApplyMode() calls
        // cdRenderPageBadges() as soon as the page loads, and that function
        // reads cdPageTexts — were it declared any later, that first call would
        // hit the `var`'s hoisted-but-unassigned value (undefined) instead of
        // {}, throw, and silently kill every click handler the rest of this
        // script still had left to register, including Create the selected
        // entries below.
        var cdPageTexts = {};
        // Whether this document already has parsed content stored, which is what
        // decides if extraction runs on its own after the text comes in.
        var cdHasContent = <?php echo $has_content ? 'true' : 'false'; ?>;

        // ── Upload mode ───────────────────────────────
        // One policy or many is the first question on the screen, and the rest of
        // the panel is shown or hidden by the answer: the fields a single policy
        // fills in, and the AI that proposes values for them, have nothing to act
        // on when the upload is a compilation about to be split.
        function cdMode() {
            return $('input[name="document_source_mode"]:checked').val() || 'single';
        }

        function cdApplyMode() {
            var multi = cdMode() === 'multi';
            $('.cd-mode-single-only').toggle(!multi);
            $('.cd-mode-multi-only').toggle(multi);
            cdRenderPageBadges();

            // Publish/Update and Save Draft — WordPress core's own buttons
            // (#publishing-action and #save-post), not part of this meta box —
            // are what write the single-policy fields this mode hides. "Create
            // the selected entries" is this mode's own save action, already
            // writing straight to the database — published, not draft — as each
            // entry is created, so leaving either core button in place would
            // just be a second, misleading way to "save" a screen that has
            // nothing of its own left to save.
            $('#publishing-action, #save-post').toggle(!multi);
        }

        $(document).on('change', 'input[name="document_source_mode"]', function() {
            cdApplyMode();
            // The text is already in hand; what to do with it just changed.
            if (cdMode() === 'multi' && cdRawText()) cdDetectPolicies(true);
        });
        cdApplyMode();

        // ── Extracted content: edit / preview tabs ─────
        $(document).on('click', '#cd-content-tabs .cd-tab-btn', function() {
            var $btn = $(this), tab = $btn.data('tab'), $tabs = $btn.closest('#cd-content-tabs');
            $tabs.find('.cd-tab-btn').removeClass('is-active').attr('aria-selected', 'false');
            $btn.addClass('is-active').attr('aria-selected', 'true');
            $tabs.find('.cd-tab-panel').prop('hidden', true);
            $tabs.find('[data-tab-panel="' + tab + '"]').prop('hidden', false);
        });

        /** The extracted pages as one document, in page order. */
        function cdRawText() {
            return Object.keys(cdPageTexts).sort(function(a, b) { return a - b; }).map(function(p) {
                return cdPageTexts[p];
            }).join('\n');
        }

        /**
         * A clickable badge per page is how a single policy's extraction gets
         * reviewed. A compilation running to hundreds of pages has no use for
         * that — nobody is checking page 214 of 324 by hand before the split
         * does its own, much more useful review — so multi mode collapses the
         * same information down to the one number that matters there.
         */
        function cdRenderPageBadges() {
            var $badges = $('#cd-page-badges').empty();
            var pages   = Object.keys(cdPageTexts);
            if (!pages.length) return;

            if (cdMode() === 'multi') {
                $('<span style="font-size:12px;color:#646970;"></span>')
                    .text(pages.length + ' page' + (pages.length !== 1 ? 's' : ''))
                    .appendTo($badges);
                return;
            }

            pages.sort(function(a, b) { return a - b; }).forEach(function(pageNum) {
                var $badge = $('<button type="button" class="button button-small cd-page-badge"></button>').text('Page ' + pageNum);
                $badge.on('click', function() { cdOpenPageModal(pageNum); });
                $badges.append($badge);
            });
        }

        async function cdExtractPdf(pdfUrl) {
            pdfUrl = pdfUrl || $('#cd-file-url').val();
            if (!pdfUrl) return;

            var $wrap   = $('#cd-page-badges-wrap');
            var $status = $('#cd-extract-status');

            $wrap.show();
            $('#cd-page-badges').empty();
            $status.text('Loading…');
            cdPageTexts = {};

            try {
                if (typeof pdfjsLib === 'undefined') {
                    $status.text('PDF.js not loaded — please refresh.');
                    return;
                }
                if (typeof AidocsPdfStructure === 'undefined') {
                    $status.text('Extractor not loaded — please refresh.');
                    return;
                }
                pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
                var pdf = await pdfjsLib.getDocument(pdfUrl).promise;

                // The extractor reads the layout of the PDF — font weight,
                // point size, left margin, line spacing — and writes it back
                // into the text as the markers the server-side parser matches.
                var extracted = await AidocsPdfStructure.extract(pdf, function(page, total) {
                    $status.text(page + ' / ' + total);
                });

                extracted.pages.forEach(function(text, index) {
                    cdPageTexts[index + 1] = text;
                });
                cdRenderPageBadges();

                $status.text(pdf.numPages + ' page' + (pdf.numPages !== 1 ? 's' : ''));

                // What the text is for depends on what was uploaded. A single
                // policy is parsed into this document's own blocks — the default
                // path, no AI — but only when the document has nothing stored
                // yet, so re-opening the editor never quietly rewrites content an
                // editor has already checked. A compilation is instead read for
                // the policies it holds, and nothing is written until the editor
                // has seen the list.
                if (cdMode() === 'multi') {
                    cdDetectPolicies(true);
                } else if ($('#cd-mode-wrap').length && !cdHasContent) {
                    // The mode picker only exists while setting a document up
                    // (it disappears once content exists), and that is exactly
                    // when it's worth checking on the editor's behalf whether
                    // "One policy" was really the right answer.
                    cdAutoDetectMultiMode();
                } else if (!cdHasContent) {
                    cdExtractContent(true);
                }
            } catch (err) {
                $status.text('Error: ' + err.message);
            }
        }

        /**
         * Silently checks whether the just-extracted text carries more than
         * one policy and, if so, switches the mode picker to "A document
         * holding several policies" — the editor still sees and confirms the
         * list before anything is imported, this just saves the extra click
         * (and re-upload) of picking that mode by hand after noticing the
         * file wasn't a single policy after all. Falls back to treating it as
         * one policy, same as before this existed, if detection finds one or
         * none, or fails outright.
         */
        function cdAutoDetectMultiMode() {
            var rawText = cdRawText();
            $.post(cdAjaxUrl, {
                action:   'aidocs_detect_policies',
                nonce:    cdAjaxNonce,
                post_id:  cdDocId,
                raw_text: rawText
            })
            .done(function(res) {
                if (res.success && res.data.count > 1) {
                    cdPolicies = res.data.policies || [];
                    $('input[name="document_source_mode"][value="multi"]').prop('checked', true);
                    cdApplyMode();
                    $('#cd-split-badge').removeClass('is-off').addClass('is-ok')
                        .text('✓ ' + res.data.count + ' <?php echo esc_js( __( 'policies found' ) ); ?>');
                    $('#cd-split-status').text('<?php echo esc_js( __( 'Several policies were found in this file — switched to "A document holding several policies".' ) ); ?>');
                    cdRenderPolicies();
                } else {
                    cdExtractContent(true);
                }
            })
            .fail(function() {
                // Detection failing is not a reason to leave the editor with
                // nothing extracted — fall back to the single-policy path.
                cdExtractContent(true);
            });
        }

        // Word's own styles already carry the structure a PDF has to be
        // reverse-engineered for, so this is the same flow as cdExtractPdf()
        // without any layout sniffing — mammoth.js reads the .docx directly
        // into semantic HTML, and AidocsDocxStructure walks that into the
        // same canonical text format. No multi-policy split here: that split
        // depends on the same PDF-derived text every other single-file rule
        // does, so a Word compilation still has to be uploaded one policy at
        // a time (same limitation Excel already has).
        async function cdExtractDocx(docxUrl) {
            docxUrl = docxUrl || $('#cd-file-url').val();
            if (!docxUrl) return;

            var $wrap   = $('#cd-page-badges-wrap');
            var $status = $('#cd-extract-status');

            $wrap.show();
            $('#cd-page-badges').empty();
            $status.text('Loading…');
            cdPageTexts = {};

            try {
                if (typeof AidocsDocxStructure === 'undefined') {
                    $status.text('Extractor not loaded — please refresh.');
                    return;
                }
                var response = await fetch(docxUrl);
                var buffer   = await response.arrayBuffer();

                var extracted = await AidocsDocxStructure.extract(buffer);
                cdPageTexts[1] = extracted.text;
                cdRenderPageBadges();

                $status.text('<?php echo esc_js( __( 'Read' ) ); ?>');

                // The split works off the canonical text mammoth.js just
                // produced, the exact same text a PDF would have yielded, so
                // a Word compilation splits the same way a PDF one does.
                if (cdMode() === 'multi') {
                    cdDetectPolicies(true);
                } else if ($('#cd-mode-wrap').length && !cdHasContent) {
                    cdAutoDetectMultiMode();
                } else if (!cdHasContent) {
                    cdExtractContent(true);
                }
            } catch (err) {
                $status.text('Error: ' + err.message);
            }
        }

        // Auto-extract on page load if a PDF or Word file is already set
        <?php
        $file_ext_loaded = $file_id ? strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) ) : '';
        if ( $file_id && $file_ext_loaded === 'pdf' && $file_url ) :
        ?>
        $(function() {
            $('#cd-page-badges-wrap').show();
            cdExtractPdf(<?php echo json_encode( $file_url ); ?>);
        });
        <?php elseif ( $file_id && $file_ext_loaded === 'docx' && $file_url ) : ?>
        $(function() {
            $('#cd-page-badges-wrap').show();
            cdExtractDocx(<?php echo json_encode( $file_url ); ?>);
        });
        <?php endif; ?>

        // ── AI fields select-all ──────────────────────
        function cdUpdateSelectAll() {
            var $checks = $('.cd-ai-field-check');
            var checked = $checks.filter(':checked').length;
            var $all = $('#cd-ai-select-all');
            $all.prop('checked', checked === $checks.length);
            $all.prop('indeterminate', checked > 0 && checked < $checks.length);
        }
        $('#cd-ai-select-all').on('change', function() {
            $('.cd-ai-field-check').prop('checked', $(this).prop('checked'));
        });
        $(document).on('change', '.cd-ai-field-check', cdUpdateSelectAll);
        cdUpdateSelectAll();

        // ── AI setup: API key and model, asked for only when missing ──
        function cdAiConfigured() {
            return $('#cd-ai-config').attr('data-configured') === '1';
        }

        $('#cd-ai-config-toggle').on('click', function() {
            $('#cd-ai-config-form').prop('hidden', false);
            $('#cd-ai-key').trigger('focus');
        });

        // Listing the models doubles as the check that the key works, and it is
        // done before saving anything so a wrong key never gets stored.
        $('#cd-ai-config-load').on('click', function() {
            var $btn = $(this), $status = $('#cd-ai-config-status');
            $btn.prop('disabled', true);
            $status.text('<?php echo esc_js( __( 'Checking…' ) ); ?>');

            $.post(cdAjaxUrl, {
                action:  'aidocs_ai_credentials',
                nonce:   cdAjaxNonce,
                mode:    'probe',
                api_key: $('#cd-ai-key').val()
            })
            .done(function(res) {
                if (!res.success) { $status.text('Error: ' + res.data); return; }
                var $select = $('#cd-ai-model').empty();
                (res.data.models || []).forEach(function(model) {
                    $select.append($('<option>').val(model.id).text(model.label || model.id));
                });
                if (res.data.current) $select.val(res.data.current);
                $status.text(res.data.models.length + ' <?php echo esc_js( __( 'models available' ) ); ?>');
            })
            .fail(function(xhr) { $status.text('Error: ' + (xhr.responseJSON && xhr.responseJSON.data || xhr.statusText)); })
            .always(function() { $btn.prop('disabled', false); });
        });

        $('#cd-ai-config-save').on('click', function() {
            var $btn = $(this), $status = $('#cd-ai-config-status');
            $btn.prop('disabled', true);
            $status.text('<?php echo esc_js( __( 'Saving…' ) ); ?>');

            $.post(cdAjaxUrl, {
                action:  'aidocs_ai_credentials',
                nonce:   cdAjaxNonce,
                mode:    'save',
                api_key: $('#cd-ai-key').val(),
                model:   $('#cd-ai-model').val()
            })
            .done(function(res) {
                if (!res.success) { $status.text('Error: ' + res.data); return; }
                $('#cd-ai-config').attr('data-configured', res.data.configured ? '1' : '0');
                $('#cd-ai-model-label').text(res.data.model);
                $('#cd-ai-key').val('').attr('placeholder', '<?php echo esc_js( __( 'Saved — leave blank to keep it' ) ); ?>');
                $('#cd-ai-config-state').prop('hidden', !res.data.configured);
                $('#cd-ai-config-form').prop('hidden', res.data.configured);
                $status.text('');
            })
            .fail(function(xhr) { $status.text('Error: ' + (xhr.responseJSON && xhr.responseJSON.data || xhr.statusText)); })
            .always(function() { $btn.prop('disabled', false); });
        });

        // ── Propose field values with AI ──────────────
        $('#cd-ai-process-btn').on('click', function() {
            var rawText = Object.keys(cdPageTexts).sort(function(a, b) { return a - b; }).map(function(p) {
                return '--- Page ' + p + ' ---\n' + cdPageTexts[p];
            }).join('\n\n');

            if (!rawText) {
                $('#cd-ai-status').text('<?php esc_html_e( 'No text extracted yet — load a PDF or Word file and wait for extraction.' ); ?>');
                return;
            }

            if (!cdAiConfigured()) {
                $('#cd-ai-config-form').prop('hidden', false);
                $('#cd-ai-config-status').text('<?php echo esc_js( __( 'Add a Gemini API key to use the AI.' ) ); ?>');
                $('#cd-ai-key').trigger('focus');
                return;
            }

            var staticDefs = {
                title:         { id: 'title',         label: '<?php echo esc_js( __( 'Document Title' ) ); ?>',       type: 'text' },
                description:   { id: 'description',   label: '<?php echo esc_js( __( 'Description' ) ); ?>',          type: 'textarea' },
                audience:      { id: 'audience',      label: '<?php echo esc_js( __( 'Audience' ) ); ?>',              type: 'multiselect', options: cdAudienceOptions },
                document_type: { id: 'document_type', label: '<?php echo esc_js( __( 'Document Type' ) ); ?>',         type: 'multiselect', options: cdTypeOptions }
            };

            var fieldsToFill = [];
            $('.cd-ai-field-check:checked').each(function() {
                var fid = $(this).data('field-id');
                if (staticDefs[fid]) fieldsToFill.push(staticDefs[fid]);
            });

            if (!fieldsToFill.length) {
                $('#cd-ai-status').text('<?php esc_html_e( 'Select at least one field.' ); ?>');
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true).text('<?php esc_html_e( 'Processing…' ); ?>');
            $('#cd-ai-status').text('');

            $.post(cdAjaxUrl, {
                action:   'aidocs_ai_process',
                nonce:    cdAjaxNonce,
                post_id:  cdDocId,
                raw_text: rawText,
                fields:   JSON.stringify(fieldsToFill)
            })
            .done(function(res) {
                if (!res.success) {
                    $('#cd-ai-status').text('Error: ' + res.data);
                    return;
                }
                var data = res.data;
                if (data._embedding_saved) cdSetEmbeddingBadge(true);
                // The values are proposals: they go into the review list, not
                // into the form. Applying them is a separate, explicit click.
                cdRenderAiReview(fieldsToFill, data);
            })
            .fail(function(xhr) {
                var msg = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : xhr.statusText;
                $('#cd-ai-status').text('Error: ' + msg);
            })
            .always(function() {
                $btn.prop('disabled', false).html('&#9889; <?php echo esc_js( __( 'Propose with AI' ) ); ?>');
            });
        });

        // ── Review: one card per proposed field, editable, applied on demand ──
        var cdFieldLabels = {
            title:         '<?php echo esc_js( __( 'Title' ) ); ?>',
            description:   '<?php echo esc_js( __( 'Description' ) ); ?>',
            audience:      '<?php echo esc_js( __( 'Audience' ) ); ?>',
            document_type: '<?php echo esc_js( __( 'Document Type' ) ); ?>'
        };

        function cdCurrentValue(fieldId) {
            if (fieldId === 'title')         return $('#title').val() || '';
            if (fieldId === 'description')   return $('#cd-description').val() || '';
            if (fieldId === 'audience')      return $('#cd-audience-value').val() || '';
            if (fieldId === 'document_type') return $('#cd-type-value').val() || '';
            return '';
        }

        function cdApplyValue(fieldId, value) {
            if (fieldId === 'title') {
                $('#title').val(value).trigger('input').trigger('keyup').trigger('focus').trigger('blur');
            } else if (fieldId === 'description') {
                $('#cd-description').val(value);
            } else if (fieldId === 'audience' || fieldId === 'document_type') {
                var select = fieldId === 'audience' ? cdAudienceSelect : cdTypeSelect;
                select.clearTags();
                String(value).split(',').forEach(function(term) {
                    var t = term.trim();
                    if (t) select.addTag(t);
                });
            }
        }

        function cdRenderAiReview(fields, data) {
            var $list = $('#cd-ai-review-list').empty();
            var shown = 0;

            fields.forEach(function(field) {
                var id = field.id;
                if (data[id] === undefined || data[id] === null) return;
                var value = Array.isArray(data[id]) ? data[id].join(', ') : String(data[id]);
                if (!value.trim()) return;
                shown++;

                var current  = cdCurrentValue(id);
                var $card    = $('<div class="cd-ai-card">').attr('data-field-id', id);
                var $head    = $('<div class="cd-ai-card-head">')
                    .append($('<strong>').text(cdFieldLabels[id] || id))
                    .append($('<span class="cd-ai-card-current">').text(
                        current
                            ? '<?php echo esc_js( __( 'replaces:' ) ); ?> ' + (current.length > 60 ? current.slice(0, 60) + '…' : current)
                            : '<?php echo esc_js( __( 'field is empty' ) ); ?>'
                    ));
                var $input = id === 'description'
                    ? $('<textarea rows="3">').val(value)
                    : $('<input type="text">').val(value);
                var $actions = $('<div class="cd-ai-card-actions">')
                    .append($('<button type="button" class="button button-small button-primary cd-ai-apply">').text('<?php echo esc_js( __( 'Apply' ) ); ?>'))
                    .append($('<button type="button" class="button button-small cd-ai-discard">').text('<?php echo esc_js( __( 'Discard' ) ); ?>'));

                $card.append($head, $input, $actions);
                $list.append($card);
            });

            $('#cd-ai-review').prop('hidden', shown === 0);
            $('#cd-ai-status').text(shown
                ? shown + ' <?php echo esc_js( __( 'values proposed — review and apply' ) ); ?>'
                : '<?php echo esc_js( __( 'The AI returned nothing for the selected fields.' ) ); ?>');
        }

        $(document).on('click', '.cd-ai-apply', function() {
            var $card = $(this).closest('.cd-ai-card');
            cdApplyValue($card.data('field-id'), $card.find('textarea, input[type="text"]').val());
            $card.addClass('is-applied').find('.cd-ai-card-actions').html(
                '<span class="cd-badge is-ok">&#10003; <?php echo esc_js( __( 'Applied — remember to update the post' ) ); ?></span>'
            );
        });

        $(document).on('click', '.cd-ai-discard', function() {
            $(this).closest('.cd-ai-card').remove();
            if (!$('#cd-ai-review-list').children().length) $('#cd-ai-review').prop('hidden', true);
        });

        $('#cd-ai-apply-all').on('click', function() {
            $('#cd-ai-review-list .cd-ai-card:not(.is-applied) .cd-ai-apply').trigger('click');
        });

        $('#cd-ai-discard-all').on('click', function() {
            $('#cd-ai-review-list').empty();
            $('#cd-ai-review').prop('hidden', true);
            $('#cd-ai-status').text('');
        });

        // ── Restructure the extracted content with AI ──
        $('#cd-ai-restructure-btn').on('click', function() {
            var rawText = cdRawText();
            var $status = $('#cd-ai-restructure-status');

            if (!rawText.trim()) {
                $status.text('<?php echo esc_js( __( 'No document text — load a PDF and wait for extraction.' ) ); ?>');
                return;
            }
            if (!cdAiConfigured()) {
                $('#cd-ai-config-form').prop('hidden', false);
                $('#cd-ai-config-status').text('<?php echo esc_js( __( 'Add a Gemini API key to use the AI.' ) ); ?>');
                $('#cd-ai-key').trigger('focus');
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true);
            $status.text('<?php echo esc_js( __( 'Sending the whole document — this can take a minute…' ) ); ?>');
            $('#cd-ai-restructure-review').prop('hidden', true);

            $.post(cdAjaxUrl, {
                action:   'aidocs_ai_restructure',
                nonce:    cdAjaxNonce,
                post_id:  cdDocId,
                raw_text: rawText
            })
            .done(function(res) {
                if (!res.success) { $status.text('Error: ' + res.data); return; }
                var d = res.data;

                var summary = d.total + ' blocks (' + d.headings + ' headings, ' + d.paragraphs + ' paragraphs, '
                    + d.lists + ' lists' + (d.notes ? ', ' + d.notes + ' notes' : '')
                    + (d.tables ? ', ' + d.tables + ' tables' : '') + ')';
                if (d.before) summary += ' — currently ' + d.before + ' blocks';
                $status.text(summary);

                // The fidelity numbers are the point of the review: they say
                // whether the AI only moved text around or started writing.
                var f = d.fidelity || {};
                var verbatim = (f.added === 0 && f.removed === 0);
                var $report = $('#cd-ai-restructure-report')
                    .removeClass('is-ok is-warn')
                    .addClass(verbatim ? 'is-ok' : 'is-warn');
                var lines = [];
                lines.push($('<strong>').text(verbatim
                    ? '<?php echo esc_js( __( 'Text is verbatim — every word matches the extracted content.' ) ); ?>'
                    : '<?php echo esc_js( __( 'The wording changed — review before applying.' ) ); ?>'));
                var detail = f.source_words + ' <?php echo esc_js( __( 'words in the source' ) ); ?>'
                    + ' · ' + f.added + ' <?php echo esc_js( __( 'added' ) ); ?>'
                    + ' · ' + f.removed + ' <?php echo esc_js( __( 'dropped' ) ); ?>';
                lines.push($('<div>').text(detail));
                if ((f.added_sample || []).length) {
                    lines.push($('<div>').text('<?php echo esc_js( __( 'Added:' ) ); ?> ' + f.added_sample.join(', ')));
                }
                if ((f.removed_sample || []).length) {
                    lines.push($('<div>').text('<?php echo esc_js( __( 'Dropped:' ) ); ?> ' + f.removed_sample.join(', ')));
                }
                if (d.truncated) {
                    lines.push($('<div>').text('<?php echo esc_js( __( 'Note: the document was too long to send in full.' ) ); ?>'));
                }
                $report.empty().append(lines);

                $('#cd-ai-restructure-preview').html(d.html || '');
                $('#cd-ai-restructure-review').prop('hidden', false);
            })
            .fail(function(xhr) {
                $status.text('Error: ' + (xhr.responseJSON && xhr.responseJSON.data || xhr.statusText));
            })
            .always(function() { $btn.prop('disabled', false); });
        });

        function cdRestructureDecision(decision) {
            var $status = $('#cd-ai-restructure-status');
            $('#cd-ai-restructure-apply, #cd-ai-restructure-discard').prop('disabled', true);

            $.post(cdAjaxUrl, {
                action:   'aidocs_ai_restructure_apply',
                nonce:    cdAjaxNonce,
                post_id:  cdDocId,
                decision: decision
            })
            .done(function(res) {
                if (!res.success) { $status.text('Error: ' + res.data); return; }
                $('#cd-ai-restructure-review').prop('hidden', true);
                if (!res.data.applied) { $status.text('<?php echo esc_js( __( 'Discarded.' ) ); ?>'); return; }

                // The document's stored content is now the AI's version, so the
                // extraction section above has to show that, not its own count.
                cdHasContent = true;
                $('#cd-content-badge').removeClass('is-off').addClass('is-ok').html('✓ ' + res.data.total + ' blocks');
                $('#cd-content-tabs').prop('hidden', false);
                $('#cd-content-preview-body').html(res.data.html || '');
                if (res.data.indexed !== undefined) cdSetEmbeddingBadge(res.data.indexed);
                $status.text('<?php echo esc_js( __( 'Applied — content replaced.' ) ); ?>');
            })
            .fail(function(xhr) {
                $status.text('Error: ' + (xhr.responseJSON && xhr.responseJSON.data || xhr.statusText));
            })
            .always(function() {
                $('#cd-ai-restructure-apply, #cd-ai-restructure-discard').prop('disabled', false);
            });
        }

        $('#cd-ai-restructure-apply').on('click',   function() { cdRestructureDecision('apply'); });
        $('#cd-ai-restructure-discard').on('click', function() { cdRestructureDecision('discard'); });

        // Indexing for the AI's semantic search has no button of its own — it
        // runs automatically after extraction and again on every save — so
        // this only ever reflects what the server already did.
        function cdSetEmbeddingBadge(indexed) {
            var $b = $('#cd-embedding-badge');
            if (! $b.length) return;
            $b.removeClass('is-off is-ok').addClass(indexed ? 'is-ok' : 'is-off');
            $b.html(indexed ? '&#10003; <?php echo esc_js( __( 'Indexed for AI search' ) ); ?>' : '<?php echo esc_js( __( 'Indexing…' ) ); ?>');
        }

        // ── Extract structured content (regex, no AI) ──
        // Runs by itself once a PDF's text is in, and again on demand.
        $('#cd-extract-content-btn').on('click', function() { cdExtractContent(false); });

        // ── Apply a hand-edited version of the extracted text ──
        // Same endpoint as extraction, just fed the textarea's current value
        // instead of what the source file produced.
        $('#cd-apply-raw-text-btn').on('click', function() {
            cdExtractContent(false, $('#cd-raw-text-edit').val(), $(this));
        });

        /**
         * @param {boolean}  automatic
         * @param {string}   [overrideText] Explicit text to parse instead of
         *                   the source file's own extraction — used by the
         *                   "Apply edited content" button.
         * @param {jQuery}   [$triggerBtn]  Button to disable while this runs,
         *                   when it isn't the default "Extract again" one.
         */
        function cdExtractContent(automatic, overrideText, $triggerBtn) {
            var rawText = (typeof overrideText === 'string') ? overrideText : cdRawText();

            if (!rawText.trim()) {
                if (!automatic) $('#cd-ai-status').text('<?php echo esc_js( __( 'No text extracted yet — load a PDF or Word file and wait for extraction.' ) ); ?>');
                return;
            }

            var $btn = $triggerBtn || $('#cd-extract-content-btn');
            var btnLabel = $btn.html();
            $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Extracting…' ) ); ?>');
            $('#cd-ai-status').text('');

            $.post(cdAjaxUrl, {
                action:   'aidocs_extract_content',
                nonce:    cdAjaxNonce,
                post_id:  cdDocId,
                raw_text: rawText
            })
            .done(function(res) {
                if (!res.success) {
                    $('#cd-ai-status').text('Error: ' + res.data);
                    return;
                }
                var d = res.data;
                cdHasContent = true;
                $('#cd-content-badge').removeClass('is-off').addClass('is-ok').html('✓ ' + d.total + ' blocks');

                // The labelled schema carries its own description, date and
                // history, so those come from the document itself, never from
                // the AI. Only empty fields are filled — an editor's
                // correction wins.
                if (d.filled && d.filled.description) {
                    $('#cd-description').val(d.filled.description);
                }
                if (d.filled && d.filled.pub_date) {
                    $('#cd-pub-date').val(d.filled.pub_date);
                }
                if (d.filled && d.filled.document_history) {
                    $('#cd-history').val(d.filled.document_history);
                }
                if (d.title && !$('#title').val()) {
                    $('#title').val(d.title).trigger('input').trigger('keyup').trigger('focus').trigger('blur');
                }

                $('#cd-content-tabs').prop('hidden', false);
                $('#cd-content-preview-body').html(d.html || '');
                $('#cd-raw-text-edit').val(rawText);
                if (d.indexed !== undefined) cdSetEmbeddingBadge(d.indexed);

                var msg = d.headings + ' headings, ' + d.paragraphs + ' paragraphs, ' + d.lists + ' lists';
                if (d.notes)  msg += ', ' + d.notes + ' notes';
                if (d.tables) msg += ', ' + d.tables + ' tables';
                if (d.labeled) {
                    var extra = Object.keys(d.filled || {});
                    msg += ' · labelled schema detected'
                        + (extra.length ? ' — filled ' + extra.join(', ') : '');
                }
                $('#cd-ai-status').text(msg);
            })
            .fail(function(xhr) {
                var msg = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : xhr.statusText;
                $('#cd-ai-status').text('Error: ' + msg);
            })
            .always(function() {
                $btn.prop('disabled', false).html(btnLabel);
            });
        }

        // ── Several policies in one upload ─────────────
        // Reading the file and writing the entries are kept apart on purpose: an
        // upload turning into fifty published entries is not something to do
        // before the editor has seen the list of what was found.
        var cdPolicies = [];

        /**
         * Tell WordPress core there is nothing left here to save.
         *
         * Its own "Leave site? Changes you made may not be saved" prompt
         * (wp-includes/js/autosave.js, postChanged()) compares #title against
         * its value at page load — the only field this post type's own save
         * screen tracks, since it supports no content editor. The moment an
         * import writes a policy over the entry being edited, this script sets
         * #title to match, and that comparison starts reading it as an
         * unsaved edit — when the title is not unsaved at all: the AJAX call
         * that set it already wrote it to the post before this line runs.
         * Without this, an editor who has just finished importing forty-nine
         * entries gets warned about losing a change that is already saved, and
         * either stays on the page out of caution or clicks through without
         * reading it — neither is what the warning is for.
         */
        function cdSuppressLeaveWarning() {
            $(window).off('beforeunload.edit-post');
        }

        $('#cd-split-detect-btn').on('click', function() { cdDetectPolicies(false); });

        function cdDetectPolicies(automatic) {
            var rawText = cdRawText();
            var $status = $('#cd-split-status');

            if (!rawText.trim()) {
                if (!automatic) $status.text('<?php echo esc_js( __( 'No document text — load a file and wait for extraction.' ) ); ?>');
                return;
            }

            var $btn = $('#cd-split-detect-btn').prop('disabled', true);
            $status.text('<?php echo esc_js( __( 'Reading…' ) ); ?>');
            $('#cd-split-result').prop('hidden', true);

            $.post(cdAjaxUrl, {
                action:   'aidocs_detect_policies',
                nonce:    cdAjaxNonce,
                post_id:  cdDocId,
                raw_text: rawText
            })
            .done(function(res) {
                if (!res.success) {
                    $status.text('Error: ' + res.data);
                    $('#cd-split-badge').removeClass('is-ok').addClass('is-off')
                        .text('<?php echo esc_js( __( 'Could not be split' ) ); ?>');
                    $('#cd-split-review').prop('hidden', true);
                    return;
                }
                cdPolicies = res.data.policies || [];
                $status.text('');
                $('#cd-split-badge').removeClass('is-off').addClass('is-ok')
                    .text('✓ ' + res.data.count + ' <?php echo esc_js( __( 'policies found' ) ); ?>');
                cdRenderPolicies();
            })
            .fail(function(xhr) {
                $status.text('Error: ' + (xhr.responseJSON && xhr.responseJSON.data || xhr.statusText));
            })
            .always(function() { $btn.prop('disabled', false); });
        }

        function cdRenderPolicies() {
            var $list = $('#cd-split-list').empty();
            $.each(cdPolicies, function(_, policy) {
                var $item = $('<div class="cd-split-item"></div>').attr('data-index', policy.index);
                $('<input type="checkbox" class="cd-split-check" checked>').val(policy.index).appendTo($item);
                var $body = $('<div class="cd-split-item-body"></div>');
                $('<div class="cd-split-item-title"></div>')
                    .text(policy.title || '<?php echo esc_js( __( '(no title found)' ) ); ?>').appendTo($body);
                var meta = [];
                if (policy.pub_date) meta.push(policy.pub_date);
                meta.push(policy.blocks + ' <?php echo esc_js( __( 'blocks' ) ); ?>');
                $('<div class="cd-split-item-meta"></div>').text(meta.join(' · ')).appendTo($body);
                if (policy.teaser) {
                    $('<div class="cd-split-item-teaser"></div>')
                        .text(policy.teaser.length > 180 ? policy.teaser.slice(0, 180) + '…' : policy.teaser)
                        .appendTo($body);
                }
                $item.append($body);
                $list.append($item);
            });
            $('#cd-split-review').prop('hidden', !cdPolicies.length);
            $('#cd-split-progress').prop('hidden', true).find('span').css('width', 0);
            $('#cd-split-all').prop('checked', true).prop('disabled', false);
            $('.cd-split-check, #cd-split-import-btn').prop('disabled', false);
            cdUpdateImportLabel();
        }

        $('#cd-split-all').on('change', function() {
            $('.cd-split-check').prop('checked', $(this).prop('checked'));
            cdUpdateImportLabel();
        });
        $(document).on('change', '.cd-split-check', cdUpdateImportLabel);

        function cdSelectedPolicies() {
            return $('.cd-split-check:checked').map(function() { return parseInt(this.value, 10); }).get();
        }

        function cdUpdateImportLabel() {
            var count = cdSelectedPolicies().length;
            $('#cd-split-import-btn').prop('disabled', count === 0).html(
                '&#10133; ' + '<?php echo esc_js( __( 'Create' ) ); ?> ' + count + ' '
                + (count === 1 ? '<?php echo esc_js( __( 'entry' ) ); ?>' : '<?php echo esc_js( __( 'entries' ) ); ?>')
            );
        }

        // Every entry written is indexed for semantic search, and fifty embedding
        // calls do not fit in one request — so the import walks the selection a
        // few at a time and reports as it goes.
        function cdSplitAiFields() {
            return $('.cd-split-ai-check:checked').map(function() { return $(this).data('field-id'); }).get();
        }

        $('#cd-split-import-btn').on('click', function() {
            var selection = cdSelectedPolicies();
            if (!selection.length) return;

            $('#cd-split-import-btn, #cd-split-detect-btn, .cd-split-check, #cd-split-all, .cd-split-ai-check').prop('disabled', true);
            $('#cd-split-progress').prop('hidden', false);
            $('#cd-split-result-list').empty();
            $('#cd-split-result').prop('hidden', true);

            cdImportBatch(selection, cdSplitAiFields(), 0);
        });

        function cdImportBatch(selection, aiFields, offset) {
            var $status = $('#cd-split-status');
            $status.text(offset + ' / ' + selection.length);

            $.post(cdAjaxUrl, {
                action:    'aidocs_import_policies',
                nonce:     cdAjaxNonce,
                post_id:   cdDocId,
                indexes:   selection,
                offset:    offset,
                // A batch that also asks the AI to fill fields makes one more
                // Gemini call per policy on top of the embedding it already
                // makes, so it is walked in smaller bites to keep each request
                // inside a reasonable time.
                limit:     aiFields.length ? 2 : 4,
                ai_fields: JSON.stringify(aiFields)
            })
            .done(function(res) {
                if (!res.success) { cdImportFailed(res.data); return; }
                var d = res.data;

                if (d.ai_warning && !$('#cd-split-ai-warning').length) {
                    $('<div id="cd-split-ai-warning" class="cd-ai-fidelity is-warn"></div>')
                        .text(d.ai_warning).insertBefore('#cd-split-progress');
                }

                $.each(d.created || [], function(_, doc) {
                    var $li = $('<li></li>');
                    if (doc.current) {
                        $li.text(doc.title + ' — ')
                           .append($('<em></em>').text('<?php echo esc_js( __( 'this entry' ) ); ?>'));
                    } else {
                        $li.append($('<a target="_blank"></a>').attr('href', doc.edit).text(doc.title));
                    }
                    var meta = [doc.blocks + ' <?php echo esc_js( __( 'blocks' ) ); ?>'];
                    if ((doc.audience || []).length) meta.push(doc.audience.join(', '));
                    if ((doc.type || []).length)     meta.push(doc.type.join(', '));
                    $li.append(document.createTextNode(' (' + meta.join(' · ') + ')'));
                    $('#cd-split-result-list').append($li);

                    // The first policy was written over the post this form is
                    // open on, so the form has to show that — otherwise clicking
                    // Update would put the stale values straight back.
                    if (doc.current) {
                        cdHasContent = true;
                        $('#title').val(doc.title).trigger('input').trigger('keyup').trigger('focus').trigger('blur');
                        if (doc.fields.description) $('#cd-description').val(doc.fields.description);
                        if (doc.fields.pub_date)    $('#cd-pub-date').val(doc.fields.pub_date);
                        cdSuppressLeaveWarning();
                    }
                });

                var done = d.next;
                $('#cd-split-progress span').css('width', Math.round(done / selection.length * 100) + '%');

                // Mark off what has been written, so a run interrupted halfway
                // leaves the list saying where it stopped.
                $('.cd-split-check').each(function() {
                    var index = selection.indexOf(parseInt(this.value, 10));
                    if (index > -1 && index < done) $(this).closest('.cd-split-item').addClass('is-done');
                });

                if (!d.done) { cdImportBatch(selection, aiFields, done); return; }

                $('#cd-split-status').text('');
                $('#cd-split-result-head').text(
                    selection.length + ' <?php echo esc_js( __( 'entries created — remember to update this one so the form and the entry agree.' ) ); ?>'
                );
                $('#cd-split-result').prop('hidden', false);
                // The list stays locked: these entries exist now, and importing
                // the same selection twice would duplicate them. Reading the file
                // again is what starts over.
                $('#cd-split-detect-btn, .cd-split-ai-check').prop('disabled', false);
            })
            .fail(function(xhr) {
                cdImportFailed(xhr.responseJSON && xhr.responseJSON.data || xhr.statusText);
            });
        }

        function cdImportFailed(message) {
            $('#cd-split-status').text('Error: ' + message);
            $('#cd-split-import-btn, #cd-split-detect-btn, .cd-split-check, #cd-split-all, .cd-split-ai-check').prop('disabled', false);
            cdUpdateImportLabel();
        }

        function cdOpenPageModal(pageNum) {
            $('#cd-modal-title').text('Page ' + pageNum);
            $('#cd-modal-content').text(cdPageTexts[pageNum] || '');
            $('#cd-modal-copy').text('<?php esc_html_e( 'Copy Text' ); ?>');
            $('#cd-page-modal').show();
        }

        $('#cd-modal-close').on('click', function() { $('#cd-page-modal').hide(); });
        $('#cd-page-modal').on('click', function(e) {
            if (e.target === this) $(this).hide();
        });
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') $('#cd-page-modal').hide();
        });
        $('#cd-modal-copy').on('click', function() {
            var text = $('#cd-modal-content').text();
            var $btn = $(this);
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function() {
                    $btn.text('Copied!');
                    setTimeout(function() { $btn.text('<?php esc_html_e( 'Copy Text' ); ?>'); }, 1500);
                });
            }
        });

    })(jQuery);
    </script>
    <?php
}

// ──────────────────────────────────────────────
// 4. Save Meta
// ──────────────────────────────────────────────
add_action( 'save_post_aidoc', 'aidocs_save_meta' );
function aidocs_save_meta( $post_id ) {
    if ( ! isset( $_POST['aidocs_nonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['aidocs_nonce'], 'aidocs_save' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    // File ID
    if ( isset( $_POST['document_file_id'] ) ) {
        $file_id = absint( $_POST['document_file_id'] );
        if ( $file_id ) {
            update_post_meta( $post_id, '_document_file_id', $file_id );
        } else {
            delete_post_meta( $post_id, '_document_file_id' );
        }
    }

    // Last Updated
    if ( isset( $_POST['document_pub_date'] ) ) {
        $date = sanitize_text_field( $_POST['document_pub_date'] );
        update_post_meta( $post_id, '_document_pub_date', $date );
    }

    // What the upload was, so re-opening the editor asks the same question the
    // same way round rather than resetting to a single policy.
    if ( isset( $_POST['document_source_mode'] ) ) {
        $mode = $_POST['document_source_mode'] === 'multi' ? 'multi' : 'single';
        update_post_meta( $post_id, '_document_source_mode', $mode );
    }

    // Description
    if ( isset( $_POST['document_description'] ) ) {
        update_post_meta( $post_id, '_document_description', sanitize_textarea_field( $_POST['document_description'] ) );
    }

    // Document History
    if ( isset( $_POST['document_history'] ) ) {
        update_post_meta( $post_id, '_document_history', sanitize_textarea_field( $_POST['document_history'] ) );
    }

    // Audience taxonomy
    if ( isset( $_POST['document_audience_terms'] ) ) {
        $terms = array_filter( array_map( 'trim', explode( ',', sanitize_text_field( $_POST['document_audience_terms'] ) ) ) );
        wp_set_post_terms( $post_id, $terms, 'document_audience' );
    }

    // Document Type taxonomy
    if ( isset( $_POST['document_type_terms'] ) ) {
        $terms = array_filter( array_map( 'trim', explode( ',', sanitize_text_field( $_POST['document_type_terms'] ) ) ) );
        wp_set_post_terms( $post_id, $terms, 'document_type' );
    }

    // Whatever changed above — title, description, audience, type — is what
    // the embedding is built from, so every save is what keeps it current.
    aidocs_maybe_reindex( $post_id );
}

// ──────────────────────────────────────────────
// 5. AI Processing — Gemini AJAX handler
// ──────────────────────────────────────────────
/**
 * The field-description lines of a "complete these fields" Gemini prompt.
 *
 * Shared between the interactive single-document flow and the per-policy
 * completion used while importing a multi-policy upload, so a field reads the
 * same way to the model regardless of which flow is asking.
 */
function aidocs_ai_fields_desc( array $fields ) {
    $available_audiences = implode( ', ', aidocs_get_audiences() );
    $available_types     = implode( ', ', aidocs_get_types() );

    $fields_desc = '';
    foreach ( $fields as $f ) {
        $id    = $f['id']    ?? '';
        $label = $f['label'] ?? '';
        $type  = $f['type']  ?? 'text';

        if ( $id === 'audience' ) {
            $fields_desc .= '- id: "audience", label: "' . $label . '", type: array (JSON array of strings, choose relevant items from: ' . $available_audiences . ')' . "\n";
        } elseif ( $id === 'document_type' ) {
            $fields_desc .= '- id: "document_type", label: "' . $label . '", type: array (JSON array of strings, choose relevant items from: ' . $available_types . ')' . "\n";
        } else {
            $type_hint    = $type === 'list' ? 'list (one item per line)' : $type;
            $fields_desc .= '- id: "' . $id . '", label: "' . $label . '", type: ' . $type_hint . "\n";
        }
    }
    return $fields_desc;
}

/**
 * Ask Gemini to fill a set of fields from a document's text.
 *
 * @param string $raw_text
 * @param array  $fields    [ [ 'id', 'label', 'type' ], … ].
 * @param string $api_key
 * @param string $model
 * @param bool   $truncated Out param: whether the text sent was cut short.
 * @return array|WP_Error Decoded fields (plus "_summary"), or the failure.
 */
function aidocs_ai_complete_fields( $raw_text, array $fields, $api_key, $model, &$truncated = null ) {
    $fields_desc = aidocs_ai_fields_desc( $fields );

    // The whole document goes in: every offered model takes a 1M-token context,
    // and a field like Document Type is often only decidable from a section
    // buried past whatever an arbitrary 30,000-character cut would have kept.
    $sent      = mb_substr( $raw_text, 0, AIDOCS_AI_TEXT_LIMIT );
    $truncated = mb_strlen( $raw_text ) > mb_strlen( $sent );

    $prompt  = "You are a professional document analyst. Read the document text and fill in each field.\n\n";
    $prompt .= "DOCUMENT TEXT:\n" . $sent . "\n\n";
    $prompt .= "FIELDS TO COMPLETE:\n" . $fields_desc . "\n";
    $prompt .= "Return ONLY a valid JSON object. Keys are field ids, values are strings or arrays as specified.\n";
    $prompt .= "For 'list' type, separate items with newline characters.\n";
    $prompt .= "For 'array' type, return a JSON array of strings.\n";
    $prompt .= "No explanation, no markdown fences — just the raw JSON object.\n";
    $prompt .= "Always include an extra field: id \"_summary\", a 1-2 sentence plain-text summary of the document suitable for search indexing.";

    $response = wp_remote_post(
        'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode( $model ) . ':generateContent?key=' . urlencode( $api_key ),
        [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'contents'         => [ [ 'parts' => [ [ 'text' => $prompt ] ] ] ],
                'generationConfig' => [ 'temperature' => 0.1, 'responseMimeType' => 'application/json' ],
            ] ),
            'timeout' => 60,
        ]
    );

    if ( is_wp_error( $response ) ) return $response;

    $code = (int) wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 ) {
        return new WP_Error( 'aidocs_ai_http', $body['error']['message'] ?? 'API error ' . $code );
    }

    $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $text = preg_replace( '/^```(?:json)?\s*/m', '', trim( $text ) );
    $text = preg_replace( '/\s*```\s*$/m', '', $text );

    $result = json_decode( trim( $text ), true );
    if ( ! is_array( $result ) ) {
        return new WP_Error( 'aidocs_ai_parse', __( 'Could not parse AI response. Try again.' ) );
    }
    return $result;
}

add_action( 'wp_ajax_aidocs_ai_process', 'aidocs_ai_process' );
function aidocs_ai_process() {
    check_ajax_referer( 'aidocs_ai', 'nonce' );
    if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'Unauthorized.' );

    $raw_text = isset( $_POST['raw_text'] ) ? wp_strip_all_tags( stripslashes( $_POST['raw_text'] ) ) : '';
    $fields   = json_decode( stripslashes( $_POST['fields'] ?? '[]' ), true );

    if ( ! $raw_text )     wp_send_json_error( __( 'No text provided. Extract PDF pages first.' ) );
    if ( empty( $fields ) ) wp_send_json_error( __( 'No fields selected for AI completion.' ) );

    $api_key = get_option( 'aidocs_gemini_api_key', '' );
    $model   = get_option( 'aidocs_gemini_model', 'gemini-2.5-flash' );

    if ( ! $api_key ) wp_send_json_error( __( 'Gemini API key not configured in Settings.' ) );

    $result = aidocs_ai_complete_fields( $raw_text, $fields, $api_key, $model, $truncated );
    if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );

    // Extract and save summary + embedding
    $summary         = isset( $result['_summary'] ) ? sanitize_textarea_field( $result['_summary'] ) : '';
    unset( $result['_summary'] );

    $post_id         = absint( $_POST['post_id'] ?? 0 );
    $embedding_saved = false;

    if ( $post_id && current_user_can( 'edit_post', $post_id ) ) {
        if ( $summary ) {
            update_post_meta( $post_id, '_document_summary', $summary );
        }
        $index_text = trim( $summary . "\n" . mb_substr( $raw_text, 0, 8000 ) );
        $embedding  = aidocs_gemini_embed( $index_text, $api_key, $embed_error );
        if ( $embedding ) {
            update_post_meta( $post_id, '_document_embedding', wp_slash( wp_json_encode( $embedding ) ) );
            $embedding_saved = true;
        }
    }

    $result['_embedding_saved'] = $embedding_saved;
    $result['_truncated']       = $truncated;
    wp_send_json_success( $result );
}

// ── AJAX: List available Gemini models (diagnostic) ─
add_action( 'wp_ajax_aidocs_list_models', 'aidocs_list_models_ajax' );
function aidocs_list_models_ajax() {
    check_ajax_referer( 'aidocs_ai', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );
    $api_key = get_option( 'aidocs_gemini_api_key', '' );
    if ( ! $api_key ) wp_send_json_error( 'No API key.' );
    $r = wp_remote_get( 'https://generativelanguage.googleapis.com/v1beta/models?key=' . urlencode( $api_key ), [ 'timeout' => 15 ] );
    if ( is_wp_error( $r ) ) wp_send_json_error( $r->get_error_message() );
    $body   = json_decode( wp_remote_retrieve_body( $r ), true );
    $models = $body['models'] ?? [];
    $embed  = array_values( array_filter( $models, function( $m ) {
        return in_array( 'embedContent', $m['supportedGenerationMethods'] ?? [], true );
    } ) );
    wp_send_json_success( array_column( $embed, 'name' ) );
}

/**
 * AJAX: check and store the Gemini credentials from the document editor.
 *
 * The AI is optional, so the key is asked for at the moment it is first needed
 * rather than being a precondition for opening a document. "probe" validates a
 * key and lists the models it can generate with — always before saving, so a
 * mistyped key never replaces a working one. "save" stores the pair.
 */
add_action( 'wp_ajax_aidocs_ai_credentials', 'aidocs_ai_credentials_ajax' );
function aidocs_ai_credentials_ajax() {
    check_ajax_referer( 'aidocs_ai', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Only an administrator can change the API key.' ) );
    }

    $mode    = sanitize_key( $_POST['mode'] ?? 'probe' );
    $posted  = sanitize_text_field( stripslashes( $_POST['api_key'] ?? '' ) );
    $api_key = $posted !== '' ? $posted : get_option( 'aidocs_gemini_api_key', '' );

    if ( ! $api_key ) {
        wp_send_json_error( __( 'Enter a Gemini API key.' ) );
    }

    if ( $mode === 'save' ) {
        $model = sanitize_text_field( $_POST['model'] ?? '' );
        if ( $posted !== '' ) update_option( 'aidocs_gemini_api_key', $posted );
        if ( $model !== '' )  update_option( 'aidocs_gemini_model', $model );
        wp_send_json_success( [
            'configured' => (bool) get_option( 'aidocs_gemini_api_key', '' ),
            'model'      => get_option( 'aidocs_gemini_model', 'gemini-2.5-flash' ),
        ] );
    }

    $response = wp_remote_get(
        'https://generativelanguage.googleapis.com/v1beta/models?key=' . urlencode( $api_key ),
        [ 'timeout' => 15 ]
    );
    if ( is_wp_error( $response ) ) wp_send_json_error( $response->get_error_message() );

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
        wp_send_json_error( $body['error']['message'] ?? __( 'The API rejected this key.' ) );
    }

    // Only the models that can answer a prompt are useful here; the embedding
    // models are picked separately by aidocs_get_embed_model(). Image, speech
    // and computer-use variants advertise generateContent as well, so they are
    // filtered by id — the capability list alone cannot tell them apart.
    $models = [];
    foreach ( (array) ( $body['models'] ?? [] ) as $model ) {
        if ( ! in_array( 'generateContent', $model['supportedGenerationMethods'] ?? [], true ) ) continue;

        $id = preg_replace( '#^models/#', '', (string) ( $model['name'] ?? '' ) );
        if ( $id === '' || ! aidocs_is_text_model( $id ) ) continue;

        $label   = $model['displayName'] ?? $id;
        $context = (int) ( $model['inputTokenLimit'] ?? 0 );
        if ( $context >= 1000 ) {
            /* translators: %s: context window size, e.g. "1M". */
            $label .= sprintf( ' — %s context', $context >= 1000000
                ? round( $context / 1000000 ) . 'M'
                : round( $context / 1000 ) . 'K' );
        }

        $models[] = [ 'id' => $id, 'label' => $label ];
    }

    if ( ! $models ) wp_send_json_error( __( 'This key has no text models available.' ) );

    wp_send_json_success( [
        'models'  => $models,
        'current' => get_option( 'aidocs_gemini_model', 'gemini-2.5-flash' ),
    ] );
}

// ── AJAX: Extract structured content from PDF text (admin) ─
add_action( 'wp_ajax_aidocs_extract_content', 'aidocs_extract_content_ajax' );
function aidocs_extract_content_ajax() {
    check_ajax_referer( 'aidocs_ai', 'nonce' );

    $post_id = absint( $_POST['post_id'] ?? 0 );
    if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( 'Invalid post.' );
    }

    $raw_text = isset( $_POST['raw_text'] ) ? wp_strip_all_tags( stripslashes( $_POST['raw_text'] ) ) : '';
    if ( ! trim( $raw_text ) ) {
        wp_send_json_error( __( 'No text to parse. Load a PDF or Word file and wait for extraction.' ) );
    }

    $parsed = aidocs_parse_labeled_document( $raw_text );
    $blocks = $parsed['blocks'];
    if ( ! $blocks ) {
        wp_send_json_error( __( 'The parser found no content in this document.' ) );
    }

    // wp_slash() is required: update_post_meta() runs wp_unslash() on the value,
    // which would strip the backslashes out of JSON's \uXXXX escapes and leave
    // unparseable meta behind. Only shows up on documents containing non-ASCII
    // characters — curly quotes, em dashes — so it fails silently on the rest.
    update_post_meta( $post_id, '_document_content', wp_slash( wp_json_encode( $blocks ) ) );

    // The canonical text itself, kept alongside the blocks it produced so the
    // editor can hand-correct it later without re-running extraction from the
    // source file — see the "Edit content" tab textarea.
    update_post_meta( $post_id, '_document_raw_text', wp_slash( $raw_text ) );

    // A labelled document carries its own description and date, so there is no
    // reason to ask the AI for either. Existing values are never overwritten —
    // an editor's correction outranks the parser.
    $filled = [];
    if ( $parsed['labeled'] ) {
        if ( $parsed['teaser'] && ! get_post_meta( $post_id, '_document_description', true ) ) {
            update_post_meta( $post_id, '_document_description', sanitize_textarea_field( $parsed['teaser'] ) );
            $filled['description'] = $parsed['teaser'];
        }
        $date = aidocs_normalize_doc_date( $parsed['last_updated'] );
        if ( $date && ! get_post_meta( $post_id, '_document_pub_date', true ) ) {
            update_post_meta( $post_id, '_document_pub_date', $date );
            $filled['pub_date'] = $date;
        }
        if ( $parsed['document_history'] ) {
            update_post_meta( $post_id, '_document_history', sanitize_textarea_field( $parsed['document_history'] ) );
            $filled['document_history'] = $parsed['document_history'];
        }
    }

    // The content just written above is what the embedding is built from, so
    // this is the other point (besides every save) where it needs a refresh.
    $indexed = aidocs_maybe_reindex( $post_id );

    $counts = array_count_values( wp_list_pluck( $blocks, 'type' ) );
    wp_send_json_success( [
        'total'      => count( $blocks ),
        'headings'   => (int) ( $counts['heading'] ?? 0 ),
        'paragraphs' => (int) ( $counts['paragraph'] ?? 0 ),
        'lists'      => (int) ( $counts['list'] ?? 0 ),
        'notes'      => (int) ( $counts['note'] ?? 0 ),
        'tables'     => (int) ( $counts['table'] ?? 0 ),
        'labeled'    => (bool) $parsed['labeled'],
        'filled'     => $filled,
        'title'      => $parsed['title'],
        'html'       => aidocs_render_content_blocks( $blocks ),
        'indexed'    => $indexed,
    ] );
}

// ──────────────────────────────────────────────
// Several policies in one upload
// ──────────────────────────────────────────────
// The compilations the Commission publishes are single files holding dozens of
// standalone policies, and each of those has to end up as its own entry. The
// split itself is the parser's (aidocs_split_multi_policy_text); what lives here
// is the two steps around it: reading the upload to say what it holds, and then
// writing one entry per policy.
//
// Detection and import are separate requests because they answer to different
// questions — "what is in this file?" is something the editor has to see and
// approve before fifty entries appear — and the import is itself batched, since
// every entry written is indexed for semantic search and fifty embedding calls
// do not fit in one request.

/** Where a detected split is held between the two requests. */
function aidocs_policy_batch_key( $post_id ) {
    return 'aidocs_policies_' . get_current_user_id() . '_' . (int) $post_id;
}

/**
 * The fields one policy contributes to an entry, ready to be written.
 *
 * @param array $parsed Output of aidocs_parse_labeled_document().
 * @return array{title:string,description:string,pub_date:string,history:string,blocks:array}
 */
function aidocs_policy_fields( array $parsed ) {
    return [
        'title'       => $parsed['title'],
        'description' => $parsed['teaser'],
        'pub_date'    => aidocs_normalize_doc_date( $parsed['last_updated'] ),
        'history'     => $parsed['document_history'],
        'blocks'      => $parsed['blocks'],
    ];
}

/**
 * Write one split-out policy over a document, whether new or the one being edited.
 *
 * Unlike the single-document path, the deterministic fields here — content,
 * description, date, history — are written unconditionally: the entry either did
 * not exist a moment ago, or is the one the editor pointed at this compilation, so
 * there is no manual correction to outrank. Audience and Document Type are not
 * deterministic at all — the label schema has no section for them — so they come
 * from wherever aidocs_ai_fill_policy_fields() found them, one policy at a time,
 * the same as the interactive "Complete fields with AI" panel would for a single
 * document, just applied without a manual review step: reviewing forty-nine of
 * them one by one is the batching this whole flow exists to avoid.
 *
 * @param int   $post_id Target document.
 * @param array $fields  Output of aidocs_policy_fields().
 * @param array $terms   [ 'audience' => string[], 'type' => string[] ]
 */
function aidocs_write_policy( $post_id, array $fields, array $terms ) {
    // See aidocs_extract_content_ajax() on why the JSON is slashed.
    update_post_meta( $post_id, '_document_content', wp_slash( wp_json_encode( $fields['blocks'] ) ) );

    if ( $fields['description'] !== '' ) {
        update_post_meta( $post_id, '_document_description', sanitize_textarea_field( $fields['description'] ) );
    }
    if ( $fields['pub_date'] !== '' ) {
        update_post_meta( $post_id, '_document_pub_date', $fields['pub_date'] );
    }
    if ( $fields['history'] !== '' ) {
        update_post_meta( $post_id, '_document_history', sanitize_textarea_field( $fields['history'] ) );
    }

    if ( $terms['audience'] ) wp_set_post_terms( $post_id, $terms['audience'], 'document_audience' );
    if ( $terms['type'] )     wp_set_post_terms( $post_id, $terms['type'],     'document_type' );

    return aidocs_maybe_reindex( $post_id );
}

/** Field ids the batch import can ask the AI to complete, and their prompt labels. */
function aidocs_policy_ai_field_labels() {
    return [
        'title'         => __( 'Title' ),
        'description'   => __( 'Description' ),
        'audience'      => __( 'Audience' ),
        'document_type' => __( 'Document Type' ),
    ];
}

/**
 * Keep only the AI's answer for a term field that names a configured term.
 *
 * The prompt already asks Gemini to choose from the configured list, but a model
 * answer is not a guarantee — this is what stops an invented term from reaching
 * the taxonomy no differently than a typo would if it were typed by hand.
 *
 * @return string[] Term names, in their configured casing.
 */
function aidocs_sanitize_ai_terms( $values, array $vocabulary ) {
    $by_lower = array_combine( array_map( 'strtolower', $vocabulary ), $vocabulary );
    $out      = [];
    foreach ( (array) $values as $value ) {
        if ( ! is_string( $value ) ) continue;
        $match = $by_lower[ strtolower( trim( $value ) ) ] ?? null;
        if ( $match !== null ) $out[] = $match;
    }
    return array_values( array_unique( $out ) );
}

/**
 * Ask the AI to fill the requested fields for one policy.
 *
 * @param string $raw_text  The policy's own text — never the whole compilation,
 *                          so the model is not asked to guess which of forty-nine
 *                          policies a field belongs to.
 * @param array  $field_ids Subset of aidocs_policy_ai_field_labels() keys.
 * @return array Field id => value. Empty when nothing was requested, no key is
 *               configured, or the call failed — the caller treats that the same
 *               as the AI simply not having an opinion.
 */
function aidocs_ai_fill_policy_fields( $raw_text, array $field_ids, $api_key, $model ) {
    $labels = aidocs_policy_ai_field_labels();
    $fields = [];
    foreach ( $field_ids as $id ) {
        if ( isset( $labels[ $id ] ) ) $fields[] = [ 'id' => $id, 'label' => $labels[ $id ], 'type' => 'text' ];
    }
    if ( ! $fields || ! $api_key ) return [];

    $result = aidocs_ai_complete_fields( $raw_text, $fields, $api_key, $model );
    if ( is_wp_error( $result ) ) return [];

    $out = [];
    if ( isset( $result['title'] ) && is_string( $result['title'] ) ) {
        $out['title'] = sanitize_text_field( $result['title'] );
    }
    if ( isset( $result['description'] ) && is_string( $result['description'] ) ) {
        $out['description'] = sanitize_textarea_field( $result['description'] );
    }
    if ( isset( $result['audience'] ) ) {
        $out['audience'] = aidocs_sanitize_ai_terms( $result['audience'], aidocs_get_audiences() );
    }
    if ( isset( $result['document_type'] ) ) {
        $out['document_type'] = aidocs_sanitize_ai_terms( $result['document_type'], aidocs_get_types() );
    }
    return $out;
}

/**
 * AJAX: read an upload and report the standalone policies it holds.
 *
 * Nothing is written here. The split is kept for the import that follows so the
 * text — a compilation runs to hundreds of kilobytes — is only sent once.
 */
add_action( 'wp_ajax_aidocs_detect_policies', 'aidocs_detect_policies_ajax' );
function aidocs_detect_policies_ajax() {
    check_ajax_referer( 'aidocs_ai', 'nonce' );

    $post_id = absint( $_POST['post_id'] ?? 0 );
    if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( 'Invalid post.' );
    }

    $raw_text = isset( $_POST['raw_text'] ) ? wp_strip_all_tags( stripslashes( $_POST['raw_text'] ) ) : '';
    if ( ! trim( $raw_text ) ) {
        wp_send_json_error( __( 'No document text to read. Load a file and wait for extraction.' ) );
    }

    $segments = aidocs_split_multi_policy_text( $raw_text );
    if ( ! $segments ) {
        wp_send_json_error( __( 'No policy could be told apart in this document. Splitting needs the Teaser / Body / Last Updated labels — without them, upload the policies one at a time.' ) );
    }

    set_transient( aidocs_policy_batch_key( $post_id ), $segments, HOUR_IN_SECONDS );

    $policies = [];
    foreach ( $segments as $index => $segment ) {
        $fields     = aidocs_policy_fields( aidocs_parse_labeled_document( $segment ) );
        $policies[] = [
            'index'    => $index,
            'title'    => $fields['title'],
            'teaser'   => $fields['description'],
            'pub_date' => $fields['pub_date'],
            'blocks'   => count( $fields['blocks'] ),
        ];
    }

    wp_send_json_success( [
        'count'    => count( $policies ),
        'policies' => $policies,
    ] );
}

/**
 * AJAX: write one entry per detected policy, a batch at a time.
 *
 * The first policy of the selection is written over the document being edited —
 * the editor already created it to point at this file, so leaving it behind as an
 * empty extra entry would be worse than filling it. Every other policy becomes a
 * new document. None of them carries the source file: the file held fifty
 * policies and none of them is it, and there is nothing public left that a file
 * would be shown through anyway.
 */
add_action( 'wp_ajax_aidocs_import_policies', 'aidocs_import_policies_ajax' );
function aidocs_import_policies_ajax() {
    check_ajax_referer( 'aidocs_ai', 'nonce' );

    $post_id = absint( $_POST['post_id'] ?? 0 );
    if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( 'Invalid post.' );
    }
    // Editing the one document open in the editor is not the same permission as
    // publishing a further forty-nine of them, so both are required here.
    if ( ! current_user_can( 'publish_posts' ) ) {
        wp_send_json_error( 'Unauthorized.' );
    }

    $segments = get_transient( aidocs_policy_batch_key( $post_id ) );
    if ( ! is_array( $segments ) || ! $segments ) {
        wp_send_json_error( __( 'The detected policies are no longer held — run the detection again.' ) );
    }

    $selection = array_values( array_unique( array_map( 'absint', (array) ( $_POST['indexes'] ?? [] ) ) ) );
    $selection = array_values( array_filter( $selection, function ( $index ) use ( $segments ) {
        return isset( $segments[ $index ] );
    } ) );
    if ( ! $selection ) {
        wp_send_json_error( __( 'Select at least one policy to import.' ) );
    }

    $offset = absint( $_POST['offset'] ?? 0 );
    $limit  = max( 1, min( 10, absint( $_POST['limit'] ?? 4 ) ) );
    $batch  = array_slice( $selection, $offset, $limit );

    $ai_field_ids = array_values( array_intersect(
        (array) json_decode( stripslashes( $_POST['ai_fields'] ?? '[]' ), true ) ?: [],
        array_keys( aidocs_policy_ai_field_labels() )
    ) );
    $api_key = get_option( 'aidocs_gemini_api_key', '' );
    $model   = get_option( 'aidocs_gemini_model', 'gemini-2.5-flash' );

    $created = [];
    foreach ( $batch as $position => $index ) {
        $fields = aidocs_policy_fields( aidocs_parse_labeled_document( $segments[ $index ] ) );

        // The AI is read from the policy's own text, for exactly the fields the
        // label schema does not cover — one call per policy, same as the
        // interactive panel would make for a single document. A title or
        // description the parser already found still wins; there is nothing
        // for the parser to have found for Audience or Document Type at all, so
        // those come from the AI whenever it was asked to fill them.
        $ai = aidocs_ai_fill_policy_fields( $segments[ $index ], $ai_field_ids, $api_key, $model );
        if ( $fields['title'] === '' && ! empty( $ai['title'] ) )             $fields['title']       = $ai['title'];
        if ( $fields['description'] === '' && ! empty( $ai['description'] ) ) $fields['description'] = $ai['description'];
        $terms = [
            'audience' => $ai['audience']      ?? [],
            'type'     => $ai['document_type'] ?? [],
        ];

        $title = $fields['title'] !== ''
            ? $fields['title']
            /* translators: %d: position of the policy inside the uploaded document. */
            : sprintf( __( 'Untitled policy %d' ), $index + 1 );

        $first  = ( $offset === 0 && $position === 0 );
        $target = $post_id;

        if ( $first ) {
            // The slug goes too. This entry was created to point at the
            // compilation and is being given a policy's identity outright —
            // title, description, date, content — so a URL still derived from
            // the upload's own filename would describe none of it. An empty
            // post_name has WordPress build a fresh one from the new title.
            wp_update_post( [ 'ID' => $post_id, 'post_title' => $title, 'post_name' => '', 'post_status' => 'publish' ] );
        } else {
            $target = wp_insert_post( [
                'post_type'   => 'aidoc',
                'post_status' => 'publish',
                'post_title'  => $title,
            ], true );
            if ( is_wp_error( $target ) ) {
                wp_send_json_error( $target->get_error_message() );
            }
        }

        aidocs_write_policy( $target, $fields, $terms );

        $created[] = [
            'id'       => $target,
            'title'    => $title,
            'current'  => $first,
            'blocks'   => count( $fields['blocks'] ),
            'edit'     => get_edit_post_link( $target, 'raw' ),
            'link'     => get_permalink( $target ),
            'audience' => $terms['audience'],
            'type'     => $terms['type'],
            'fields'   => [
                'description' => $fields['description'],
                'pub_date'    => $fields['pub_date'],
            ],
        ];
    }

    $next = $offset + count( $batch );
    if ( $next >= count( $selection ) ) delete_transient( aidocs_policy_batch_key( $post_id ) );

    wp_send_json_success( [
        'created'    => $created,
        'done'       => $next >= count( $selection ),
        'next'       => $next,
        'total'      => count( $selection ),
        // The fields were requested but there is nothing to fill them with —
        // worth one warning up front rather than forty-nine silently empty
        // Audience/Document Type columns the editor has to notice on their own.
        'ai_warning' => ( $ai_field_ids && ! $api_key )
            ? __( 'AI fields were selected but no Gemini API key is configured — those fields were left empty. Add one in Documents → Settings.' )
            : '',
    ] );
}

/**
 * AJAX: have the AI re-structure the already-extracted content.
 *
 * This is not text generation. The regex extractor gets the structure right on
 * the documents it was built for, but a PDF whose layout it misreads produces
 * mis-typed blocks — a heading left as a paragraph, a list flattened into
 * prose. The AI's whole job here is to re-decide which block each piece of
 * text belongs to, reusing that text verbatim.
 *
 * Three things keep it to that job rather than letting it rewrite the policy:
 *
 *  - The prompt states the constraint, and asks for the source's own wording.
 *  - The reply is a flat list of typed pieces that this function turns into
 *    blocks itself. The AI never supplies markup, ids, note variants or
 *    emphasis runs — those still come from aidocs_heading_block() and friends,
 *    so a hallucinated shape cannot reach storage.
 *  - Every word of the result is compared against the extracted text
 *    (aidocs_text_fidelity) and the drift is reported to the editor, who
 *    approves or discards. Nothing is written to _document_content here.
 */
add_action( 'wp_ajax_aidocs_ai_restructure', 'aidocs_ai_restructure_ajax' );
function aidocs_ai_restructure_ajax() {
    check_ajax_referer( 'aidocs_ai', 'nonce' );

    // The whole document goes into one request, and re-typing every piece of
    // a large one measurably took over 180 seconds in testing. PHP's own
    // script timeout would otherwise cut this request off first, regardless
    // of how generous wp_remote_post's own timeout below is set to.
    if ( function_exists( 'set_time_limit' ) ) set_time_limit( 300 );

    $post_id = absint( $_POST['post_id'] ?? 0 );
    if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( 'Invalid post.' );
    }

    $raw_text = isset( $_POST['raw_text'] ) ? wp_strip_all_tags( stripslashes( $_POST['raw_text'] ) ) : '';
    if ( ! trim( $raw_text ) ) {
        wp_send_json_error( __( 'No document text. Load a PDF or Word file and wait for extraction.' ) );
    }

    $api_key = get_option( 'aidocs_gemini_api_key', '' );
    $model   = get_option( 'aidocs_gemini_model', 'gemini-2.5-flash' );
    if ( ! $api_key ) wp_send_json_error( __( 'Gemini API key not configured.' ) );

    // Only the body is restructured. The title, teaser, "Last Updated" and
    // document history are their own fields, parsed deterministically from
    // their labels — handing them to the AI would invite it to fold the teaser
    // into the content as one more paragraph, duplicating the description.
    $parsed    = aidocs_parse_labeled_document( $raw_text );
    $body_text = trim( $parsed['body_text'] ) !== '' ? $parsed['body_text'] : $raw_text;

    // Plain text, not this plugin's own markdown-flavoured canonical format:
    // the source's "**bold**" and its "\*" escape for a literal asterisk are
    // an internal convention the AI has no reason to know about, and asking
    // it to reuse text "verbatim" means it copies that convention's own
    // punctuation into its reply — including, once, a literal `\*` landing
    // inside a JSON string as an escape sequence JSON does not define, which
    // broke decoding the entire reply. Structural cues ('#', list markers,
    // '|') are untouched, since those are exactly what the prompt asks the
    // model to read; only the inline emphasis markup is stripped.
    $sent      = aidocs_plain_text( mb_substr( $body_text, 0, AIDOCS_AI_TEXT_LIMIT ) );
    $truncated = mb_strlen( $body_text ) > AIDOCS_AI_TEXT_LIMIT;

    $prompt  = "You are re-structuring text that has ALREADY been extracted from a policy document.\n";
    $prompt .= "Your only task is to decide, for each piece of that text, which structural role it has.\n\n";
    $prompt .= "ABSOLUTE RULES:\n";
    $prompt .= "1. Reuse the source text VERBATIM. Do not rewrite, summarise, translate, shorten, correct or explain anything.\n";
    $prompt .= "2. Do not invent text. Every word you output must appear in the source.\n";
    $prompt .= "3. Do not drop content. Every sentence of the source must appear in exactly one piece.\n";
    $prompt .= "4. Keep the source's order.\n";
    $prompt .= "5. Join lines the extractor wrapped mid-sentence back into one piece.\n\n";
    $prompt .= "The source uses these markers, which you should treat as hints and correct where they are plainly wrong:\n";
    $prompt .= "'## '/'### '/'#### ' = heading, two spaces of indent per list level, '| a | b |' = table row.\n\n";
    $prompt .= "PIECE TYPES:\n";
    $prompt .= "- heading: a section title. level 2 for a document-level/all-caps title, 3 for a section, 4 for a sub-section.\n";
    $prompt .= "- paragraph: ordinary prose.\n";
    $prompt .= "- note: a callout the document labels, e.g. 'Note:', 'Note to International Institutions', 'Reminder:', 'Exception:'. Put the label in \"label\" and the rest of the sentence in \"text\".\n";
    $prompt .= "- list_item: one item of a list. \"marker\" is its authored marker ('1.', 'a.', 'iv.', '-'), \"level\" is 1 for a top-level item, 2 for one nested inside it, and so on.\n";
    $prompt .= "- table_row: a row of a table, with \"cells\" as an array of cell strings.\n\n";
    $prompt .= "Return ONLY a JSON object: {\"blocks\":[{\"type\":…,\"level\":…,\"marker\":…,\"label\":…,\"text\":…,\"cells\":[…]}]}\n";
    $prompt .= "Include only the keys each type needs. No markdown fences, no commentary.\n\n";
    $prompt .= "SOURCE TEXT:\n" . $sent;

    $response = wp_remote_post(
        'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode( $model ) . ':generateContent?key=' . urlencode( $api_key ),
        [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'contents'         => [ [ 'parts' => [ [ 'text' => $prompt ] ] ] ],
                // Temperature 0: this is a classification task with one right
                // answer, not a writing task where variety helps. responseSchema
                // constrains token generation to the shape below, which is what
                // stops the model from ever emitting a raw, un-escaped quote or
                // backslash inside a string — a source that quotes an
                // abbreviation ("C&R") or uses "\*" for a literal asterisk was
                // copied verbatim into a string value without the JSON escaping
                // that needs, breaking the whole reply's decode on a reply that
                // was otherwise complete and correct. responseMimeType alone
                // asks for JSON but does not enforce it at this level.
                'generationConfig' => [
                    'temperature'      => 0,
                    'responseMimeType' => 'application/json',
                    'responseSchema'   => aidocs_restructure_response_schema(),
                ],
            ] ),
            'timeout' => 280,
        ]
    );

    if ( is_wp_error( $response ) ) wp_send_json_error( $response->get_error_message() );

    $code = (int) wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( $code !== 200 ) {
        wp_send_json_error( $body['error']['message'] ?? 'API error ' . $code );
    }

    $reply = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $reply = preg_replace( '/^```(?:json)?\s*/m', '', trim( $reply ) );
    $reply = preg_replace( '/\s*```\s*$/m', '', $reply );
    $parsed_reply = json_decode( trim( $reply ), true );

    // A model can still slip in a backslash sequence JSON does not define —
    // most often \* from a source that used it for a literal asterisk, copied
    // into the reply despite the plain text this prompt sends now — which
    // breaks decoding of the entire reply, however long and otherwise correct
    // it is. Repairing just the invalid escapes and re-trying costs nothing
    // when the first decode already succeeded.
    if ( ! is_array( $parsed_reply ) ) {
        $parsed_reply = json_decode( trim( aidocs_repair_json_escapes( $reply ) ), true );
    }

    if ( ! is_array( $parsed_reply ) || empty( $parsed_reply['blocks'] ) || ! is_array( $parsed_reply['blocks'] ) ) {
        $finish = $body['candidates'][0]['finishReason'] ?? '';
        wp_send_json_error( $finish === 'MAX_TOKENS'
            ? __( 'The document is too long for this model to restructure in one reply. Try a model with a larger output limit.' )
            : __( 'Could not read the AI reply as structured content. Try again.' ) );
    }

    $blocks = aidocs_blocks_from_ai( $parsed_reply['blocks'] );
    // The body's echo of the document title is dropped here for the same reason
    // it is dropped from extracted content: the page already shows the title.
    // Doing it on both paths also keeps the fidelity figures below comparable.
    $blocks = aidocs_drop_title_echo( $blocks, $parsed['title'] );
    if ( ! $blocks ) wp_send_json_error( __( 'The AI returned no usable content.' ) );

    // The proposal is parked in its own meta key. Approving it is a separate,
    // explicit request; until then the live content is untouched.
    update_post_meta( $post_id, '_document_content_ai', wp_slash( wp_json_encode( $blocks ) ) );

    // Both sides of this comparison are the body and only the body: the blocks
    // the regex extractor produced for it, against the blocks the AI produced
    // from the same text. Anything the AI adds or loses shows up here.
    $current  = aidocs_get_content_blocks( $post_id );
    $baseline = $current ? aidocs_blocks_plain_text( $current )
                         : aidocs_blocks_plain_text( $parsed['blocks'] );
    $fidelity = aidocs_text_fidelity( $baseline, aidocs_blocks_plain_text( $blocks ) );

    $counts = array_count_values( wp_list_pluck( $blocks, 'type' ) );
    wp_send_json_success( [
        'total'      => count( $blocks ),
        'headings'   => (int) ( $counts['heading'] ?? 0 ),
        'paragraphs' => (int) ( $counts['paragraph'] ?? 0 ),
        'lists'      => (int) ( $counts['list'] ?? 0 ),
        'notes'      => (int) ( $counts['note'] ?? 0 ),
        'tables'     => (int) ( $counts['table'] ?? 0 ),
        'before'     => count( $current ),
        'fidelity'   => $fidelity,
        'truncated'  => $truncated,
        'model'      => $model,
        'html'       => aidocs_render_content_blocks( $blocks ),
    ] );
}

/**
 * AJAX: approve or discard the AI's re-structured content.
 */
add_action( 'wp_ajax_aidocs_ai_restructure_apply', 'aidocs_ai_restructure_apply_ajax' );
function aidocs_ai_restructure_apply_ajax() {
    check_ajax_referer( 'aidocs_ai', 'nonce' );

    $post_id = absint( $_POST['post_id'] ?? 0 );
    if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( 'Invalid post.' );
    }

    $decision = sanitize_key( $_POST['decision'] ?? '' );

    if ( $decision === 'discard' ) {
        delete_post_meta( $post_id, '_document_content_ai' );
        wp_send_json_success( [ 'applied' => false ] );
    }

    $proposal = get_post_meta( $post_id, '_document_content_ai', true );
    $blocks   = $proposal ? json_decode( $proposal, true ) : null;
    if ( ! is_array( $blocks ) || ! $blocks ) {
        wp_send_json_error( __( 'That proposal is no longer available. Run the restructure again.' ) );
    }

    update_post_meta( $post_id, '_document_content', wp_slash( wp_json_encode( $blocks ) ) );
    delete_post_meta( $post_id, '_document_content_ai' );

    $indexed = aidocs_maybe_reindex( $post_id );

    wp_send_json_success( [
        'applied' => true,
        'total'   => count( $blocks ),
        'indexed' => $indexed,
        'html'    => aidocs_render_content_blocks( $blocks ),
    ] );
}

/**
 * The JSON Schema constraining the restructure request's reply.
 *
 * Gemini's structured output enforces this at the grammar level — not just a
 * shape hint the model tries to follow, but a constraint on what tokens it
 * can emit next — which is what guarantees syntactically valid JSON even for
 * a source that contains a quoted phrase or an escaped character of its own.
 * The schema itself intentionally says nothing about content, order or
 * length: it fixes the shape of a piece, not what should be in one, since
 * that is the prompt's job and this is not the place to duplicate it.
 */
function aidocs_restructure_response_schema() {
    return [
        'type'       => 'OBJECT',
        'properties' => [
            'blocks' => [
                'type'  => 'ARRAY',
                'items' => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'type'   => [ 'type' => 'STRING', 'enum' => [ 'heading', 'paragraph', 'note', 'list_item', 'table_row' ] ],
                        'level'  => [ 'type' => 'INTEGER' ],
                        'marker' => [ 'type' => 'STRING' ],
                        'label'  => [ 'type' => 'STRING' ],
                        'text'   => [ 'type' => 'STRING' ],
                        'cells'  => [ 'type' => 'ARRAY', 'items' => [ 'type' => 'STRING' ] ],
                    ],
                    'required' => [ 'type' ],
                ],
            ],
        ],
        'required' => [ 'blocks' ],
    ];
}

/** responseSchema for aidocs_ai_recommend_ajax() — see aidocs_restructure_response_schema() for why this matters. */
function aidocs_recommend_response_schema() {
    return [
        'type'       => 'OBJECT',
        'properties' => [
            'message' => [ 'type' => 'STRING' ],
            'doc_ids' => [ 'type' => 'ARRAY', 'items' => [ 'type' => 'INTEGER' ] ],
        ],
        'required' => [ 'message', 'doc_ids' ],
    ];
}

/** responseSchema for aidocs_ai_search_ajax() — see aidocs_restructure_response_schema() for why this matters. */
function aidocs_search_response_schema() {
    return [
        'type'       => 'OBJECT',
        'properties' => [
            'message' => [ 'type' => 'STRING' ],
            'filters' => [
                'type'       => 'OBJECT',
                'properties' => [
                    'keyword'  => [ 'type' => 'STRING' ],
                    'audience' => [ 'type' => 'STRING' ],
                    'type'     => [ 'type' => 'STRING' ],
                ],
                'required' => [ 'keyword', 'audience', 'type' ],
            ],
        ],
        'required' => [ 'message', 'filters' ],
    ];
}

/**
 * Turn the AI's flat list of typed pieces into content blocks.
 *
 * The AI only says what each piece is; the block itself — its emphasis runs,
 * anchor id, note variant, list nesting — is built here by the same functions
 * the regex parser uses, so AI-restructured content is indistinguishable in
 * shape from extracted content and nothing unvalidated reaches the database.
 *
 * @param array $pieces Raw "blocks" array from the model.
 * @return array Content blocks.
 */
function aidocs_blocks_from_ai( array $pieces ) {
    $blocks = [];
    $items  = [];   // consecutive list_item pieces, flushed as one list

    $flush_items = function () use ( &$blocks, &$items ) {
        if ( ! $items ) return;
        $index    = 0;
        $blocks[] = aidocs_list_from_flat( $items, $index );
        $items    = [];
    };

    foreach ( $pieces as $piece ) {
        if ( ! is_array( $piece ) ) continue;

        $type = sanitize_key( $piece['type'] ?? '' );
        $text = is_string( $piece['text'] ?? null ) ? trim( $piece['text'] ) : '';

        // These documents wrap a run-in sub-title in square brackets —
        // "[Governing Law] The arbitration shall be governed by…". The brackets
        // are the source's markup for that convention, not part of the title,
        // and the extractor strips them the same way when it recovers one.
        if ( $type === 'heading' ) {
            $text = preg_replace( '/^\[\s*([^\]]{2,120})\s*\]$/u', '$1', $text );
        }

        if ( $type === 'list_item' ) {
            if ( $text === '' ) continue;
            $items[] = [
                'level'  => max( 1, min( 6, (int) ( $piece['level'] ?? 1 ) ) ),
                'marker' => is_string( $piece['marker'] ?? null ) ? trim( $piece['marker'] ) : '-',
                'text'   => $text,
                'blocks' => [],
            ];
            continue;
        }

        $flush_items();

        switch ( $type ) {
            case 'heading':
                if ( $text === '' ) break;
                $blocks[] = aidocs_heading_block( max( 2, min( 4, (int) ( $piece['level'] ?? 3 ) ) ), $text );
                break;

            case 'note':
                if ( $text === '' ) break;
                $label = is_string( $piece['label'] ?? null ) ? trim( $piece['label'] ) : '';
                // Re-derive the variant from the label rather than trusting a
                // free-text one, and fall back to the paragraph pipeline when
                // the label is not one this plugin styles.
                $variant = aidocs_note_variant( $label !== '' ? $label . ':' : $text );
                if ( $variant === '' ) {
                    foreach ( aidocs_paragraph_blocks( $text ) as $block ) $blocks[] = $block;
                    break;
                }
                $blocks[] = [
                    'type'    => 'note',
                    'variant' => $variant,
                    'label'   => aidocs_plain_text( $label ),
                    'text'    => aidocs_plain_text( $text ),
                    'runs'    => aidocs_runs_unbold( aidocs_inline_runs( $text ) ),
                ];
                break;

            case 'table_row':
                $cells = array_values( array_filter( array_map(
                    function ( $cell ) { return is_scalar( $cell ) ? trim( (string) $cell ) : ''; },
                    (array) ( $piece['cells'] ?? [] )
                ), 'strlen' ) );
                if ( count( $cells ) < 2 ) break;
                aidocs_append_table_row( $blocks, $cells );
                break;

            case 'paragraph':
            default:
                if ( $text === '' ) break;
                foreach ( aidocs_paragraph_blocks( $text ) as $block ) $blocks[] = $block;
                break;
        }
    }

    $flush_items();
    return $blocks;
}

/**
 * Drop the backslash from any escape sequence JSON does not define.
 *
 * json_decode() rejects the whole document over one `\*` — the model copying
 * a source's own escaping convention into a string literal without knowing
 * JSON does not define that escape — so the fix is the same regardless of
 * how that stray backslash got there: a literal `X` was clearly meant, and a
 * backslash JSON has no rule for is never valid on purpose. Runs only after
 * a first plain json_decode() has already failed.
 *
 * @return string
 */
function aidocs_repair_json_escapes( $text ) {
    $length = strlen( $text );
    $out    = '';
    $in_string = false;

    for ( $i = 0; $i < $length; $i++ ) {
        $char = $text[ $i ];

        if ( $char === '\\' && $in_string && $i + 1 < $length ) {
            $next = $text[ $i + 1 ];
            if ( strpos( '"\\/bfnrtu', $next ) === false ) {
                $out .= $next;   // drop the backslash, keep the character it "escaped"
                $i++;
                continue;
            }
            $out .= $char . $next;
            $i++;
            continue;
        }

        if ( $char === '"' ) $in_string = ! $in_string;
        $out .= $char;
    }

    return $out;
}

/**
 * Compare two bodies of text word by word, ignoring order.
 *
 * The point is to answer one question about an AI restructure: did it only
 * move text around, or did it start writing? Words present in the result but
 * not the source are invented; words in the source but not the result were
 * dropped. Both are reported as counts and as samples, so an editor sees the
 * evidence rather than a verdict.
 *
 * @return array{added:int,removed:int,kept:int,ratio:float,added_sample:array,removed_sample:array}
 */
function aidocs_text_fidelity( $before, $after ) {
    $words = function ( $text ) {
        $text = mb_strtolower( wp_strip_all_tags( (string) $text ) );
        // Curly quotes and dashes differ between the two paths for the same
        // word, and would otherwise read as invented text.
        $text = str_replace( [ '’', '‘', '“', '”', '–', '—' ], [ "'", "'", '"', '"', '-', '-' ], $text );
        preg_match_all( '/[\p{L}\p{N}][\p{L}\p{N}\'\-]*/u', $text, $m );
        return $m[0] ?? [];
    };

    $before_counts = array_count_values( $words( $before ) );
    $after_counts  = array_count_values( $words( $after ) );

    $added = $removed = $kept = 0;
    $added_sample = $removed_sample = [];

    foreach ( $after_counts as $word => $count ) {
        $have = $before_counts[ $word ] ?? 0;
        $kept += min( $count, $have );
        if ( $count > $have ) {
            $added += $count - $have;
            if ( count( $added_sample ) < 12 ) $added_sample[] = (string) $word;
        }
    }
    foreach ( $before_counts as $word => $count ) {
        $have = $after_counts[ $word ] ?? 0;
        if ( $count > $have ) {
            $removed += $count - $have;
            if ( count( $removed_sample ) < 12 ) $removed_sample[] = (string) $word;
        }
    }

    $total = array_sum( $before_counts );

    return [
        'added'          => $added,
        'removed'        => $removed,
        'kept'           => $kept,
        'source_words'   => $total,
        'ratio'          => $total > 0 ? round( $kept / $total, 4 ) : 0.0,
        'added_sample'   => $added_sample,
        'removed_sample' => $removed_sample,
    ];
}

// ── AJAX: Fetch a document's rendered content (frontend, lazy-loaded by the modal) ─
add_action( 'wp_ajax_aidocs_doc_content',        'aidocs_doc_content_ajax' );
add_action( 'wp_ajax_nopriv_aidocs_doc_content', 'aidocs_doc_content_ajax' );
function aidocs_doc_content_ajax() {
    check_ajax_referer( 'aidocs_search', 'nonce' );

    $doc_id = absint( $_POST['doc_id'] ?? 0 );
    $post   = $doc_id ? get_post( $doc_id ) : null;
    if ( ! $post || $post->post_status !== 'publish' || $post->post_type !== 'aidoc' ) {
        wp_send_json_error( 'Document not found.' );
    }

    wp_send_json_success( [
        'html'      => aidocs_render_content_blocks( aidocs_get_content_blocks( $doc_id ) )
                       . aidocs_render_document_history( $doc_id ),
        'permalink' => get_permalink( $doc_id ),
    ] );
}

// ──────────────────────────────────────────────
// 6. Admin Menu — Settings submenu
// ──────────────────────────────────────────────
add_action( 'admin_menu', 'aidocs_admin_menu' );
function aidocs_admin_menu() {
    add_submenu_page(
        'edit.php?post_type=aidoc',
        __( 'Documents Settings' ),
        __( 'Settings' ),
        'manage_options',
        'aidocs-settings',
        'aidocs_settings_page'
    );
}

function aidocs_settings_page() { // phpcs:ignore
    if ( ! current_user_can( 'manage_options' ) ) return;

    if ( isset( $_POST['aidocs_settings_nonce'] ) &&
         wp_verify_nonce( $_POST['aidocs_settings_nonce'], 'aidocs_settings_save' ) ) {

        update_option( 'aidocs_gemini_model', sanitize_text_field( $_POST['aidocs_gemini_model'] ?? 'gemini-2.5-flash' ) );
        if ( ! empty( $_POST['aidocs_gemini_api_key'] ) ) {
            update_option( 'aidocs_gemini_api_key', sanitize_text_field( $_POST['aidocs_gemini_api_key'] ) );
        }

        $raw_audiences = sanitize_textarea_field( $_POST['aidocs_audiences_list'] ?? '' );
        update_option( 'aidocs_audiences_list', $raw_audiences );
        foreach ( array_filter( array_map( 'trim', explode( "\n", $raw_audiences ) ) ) as $term ) {
            if ( ! term_exists( $term, 'document_audience' ) ) wp_insert_term( $term, 'document_audience' );
        }
        $raw_types = sanitize_textarea_field( $_POST['aidocs_types_list'] ?? '' );
        update_option( 'aidocs_types_list', $raw_types );
        foreach ( array_filter( array_map( 'trim', explode( "\n", $raw_types ) ) ) as $term ) {
            if ( ! term_exists( $term, 'document_type' ) ) wp_insert_term( $term, 'document_type' );
        }

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.' ) . '</p></div>';
    }

    /* ---- data ---- */
    $gemini_model      = get_option( 'aidocs_gemini_model', 'gemini-2.5-flash' );
    $gemini_api_key    = get_option( 'aidocs_gemini_api_key', '' );
    $audiences_list    = get_option( 'aidocs_audiences_list', implode( "\n", AIDOCS_AUDIENCES ) );
    $types_list        = get_option( 'aidocs_types_list',     implode( "\n", AIDOCS_TYPES ) );

    $types_arr     = array_filter( array_map( 'trim', explode( "\n", $types_list ) ) );
    $audiences_arr = array_filter( array_map( 'trim', explode( "\n", $audiences_list ) ) );
    $first_type    = reset( $types_arr )     ?: 'Policies';
    $first_aud     = reset( $audiences_arr ) ?: 'Institution';
    $sample_doc    = get_posts( [ 'post_type' => 'aidoc', 'post_status' => 'publish', 'numberposts' => 1 ] );
    $sample_doc_id = $sample_doc ? $sample_doc[0]->ID : 123;
    ?>
    <div class="wrap">
    <h1><?php esc_html_e( 'Documents Settings' ); ?></h1>
    <style>
    .cd-settings-section{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px 24px;margin-bottom:24px;}
    .cd-settings-section h2{margin-top:0;font-size:16px;}
    .cd-sc-box{background:#f6f7f7;border:1px solid #c3c4c7;border-radius:4px;padding:14px 18px;margin-bottom:14px;}
    .cd-sc-box h3{margin:0 0 8px;font-size:13px;color:#1d2327;}
    .cd-sc-code{display:flex;align-items:center;gap:8px;margin-bottom:6px;}
    .cd-sc-code code{background:#fff;border:1px solid #c3c4c7;border-radius:3px;padding:5px 10px;font-size:13px;font-family:ui-monospace,Consolas,monospace;flex:1;color:#1d2327;}
    .cd-sc-copy{background:#2271b1;color:#fff;border:none;border-radius:3px;padding:5px 12px;font-size:12px;cursor:pointer;white-space:nowrap;}
    .cd-sc-copy:hover{background:#135e96;}.cd-sc-copy.copied{background:#46b450;}
    .cd-sc-desc{font-size:12px;color:#646970;margin:4px 0 0;}
    .cd-sc-params{width:100%;border-collapse:collapse;margin-top:14px;font-size:12px;}
    .cd-sc-params th{text-align:left;padding:6px 10px;background:#f0f0f1;border:1px solid #dcdcde;}
    .cd-sc-params td{padding:6px 10px;border:1px solid #dcdcde;color:#50575e;vertical-align:top;}
    .cd-sc-params td code{background:#f0f0f1;padding:1px 5px;border-radius:2px;font-size:11px;}
    </style>

    <form method="post">
    <?php wp_nonce_field( 'aidocs_settings_save', 'aidocs_settings_nonce' ); ?>

    <div class="cd-settings-section">
        <h2><?php esc_html_e( 'AI' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="cd-gemini-model"><?php esc_html_e( 'Gemini Model' ); ?></label></th>
                <td>
                    <?php
                    // A model saved before this list existed, or picked up from
                    // the live API, still has to appear as the current choice.
                    $model_options = aidocs_model_catalog();
                    if ( $gemini_model !== '' && ! isset( $model_options[ $gemini_model ] ) ) {
                        $model_options = [ $gemini_model => $gemini_model . ' (saved)' ] + $model_options;
                    }
                    ?>
                    <select id="cd-gemini-model" name="aidocs_gemini_model">
                        <?php foreach ( $model_options as $mid => $mname ) : ?>
                        <option value="<?php echo esc_attr( $mid ); ?>" <?php selected( $gemini_model, $mid ); ?>><?php echo esc_html( $mname ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" id="cd-gemini-model-refresh" class="button"><?php esc_html_e( 'Refresh from API' ); ?></button>
                    <span id="cd-gemini-model-status" style="font-size:12px;color:#646970;"></span>
                    <p class="description">
                        <?php esc_html_e( 'The list above is a starting point. "Refresh from API" replaces it with exactly the text models the saved key can reach — use it if a model here is rejected. Every model listed takes a 1M-token context, so a whole policy document fits in one request.' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="cd-gemini-key"><?php esc_html_e( 'Gemini API Key' ); ?></label></th>
                <td>
                    <input type="password" id="cd-gemini-key" name="aidocs_gemini_api_key" value="<?php echo esc_attr( $gemini_api_key ); ?>" class="regular-text" autocomplete="new-password">
                    <button type="button" id="cd-gemini-key-test" class="button"><?php esc_html_e( 'Test Connection' ); ?></button>
                    <span id="cd-gemini-key-test-status" style="font-size:12px;margin-left:6px;"></span>
                    <p class="description"><?php esc_html_e( 'Leave blank to keep the current key. "Test Connection" checks the key above against the Gemini API before you save — it does not need to be saved first.' ); ?></p>
                </td>
            </tr>
        </table>
    </div>

    <div class="cd-settings-section">
        <h2><?php esc_html_e( 'Taxonomy' ); ?></h2>
        <p class="description" style="margin-bottom:16px;"><?php esc_html_e( 'One item per line. New items are registered as taxonomy terms automatically.' ); ?></p>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="cd-audiences-list"><?php esc_html_e( 'Audiences' ); ?></label></th>
                <td><textarea id="cd-audiences-list" name="aidocs_audiences_list" rows="6" class="large-text"><?php echo esc_textarea( $audiences_list ); ?></textarea></td>
            </tr>
            <tr>
                <th><label for="cd-types-list"><?php esc_html_e( 'Document Types' ); ?></label></th>
                <td><textarea id="cd-types-list" name="aidocs_types_list" rows="10" class="large-text"><?php echo esc_textarea( $types_list ); ?></textarea></td>
            </tr>
        </table>
    </div>

    <?php submit_button( __( 'Save Settings' ) ); ?>
    </form>

    <div class="cd-settings-section">
        <h2><?php esc_html_e( 'Shortcodes' ); ?></h2>
        <div class="cd-sc-box">
            <h3><?php esc_html_e( 'Basic — all documents with search' ); ?></h3>
            <div class="cd-sc-code"><code id="cd-sc-1">[aidocs_search]</code><button class="cd-sc-copy" data-target="cd-sc-1"><?php esc_html_e( 'Copy' ); ?></button></div>
        </div>
        <div class="cd-sc-box">
            <h3><?php esc_html_e( 'Pre-filtered by Document Type' ); ?></h3>
            <div class="cd-sc-code"><code id="cd-sc-2">[aidocs_search type="<?php echo esc_html( $first_type ); ?>"]</code><button class="cd-sc-copy" data-target="cd-sc-2"><?php esc_html_e( 'Copy' ); ?></button></div>
            <p class="cd-sc-desc"><?php esc_html_e( 'Available:' ); ?> <?php foreach ( $types_arr as $t ) echo '<code>' . esc_html( $t ) . '</code> '; ?></p>
        </div>
        <div class="cd-sc-box">
            <h3><?php esc_html_e( 'Pre-filtered by Audience' ); ?></h3>
            <div class="cd-sc-code"><code id="cd-sc-3">[aidocs_search audience="<?php echo esc_html( $first_aud ); ?>"]</code><button class="cd-sc-copy" data-target="cd-sc-3"><?php esc_html_e( 'Copy' ); ?></button></div>
            <p class="cd-sc-desc"><?php esc_html_e( 'Available:' ); ?> <?php foreach ( $audiences_arr as $a ) echo '<code>' . esc_html( $a ) . '</code> '; ?></p>
        </div>
        <div class="cd-sc-box">
            <h3><?php esc_html_e( 'Combined + custom per_page' ); ?></h3>
            <div class="cd-sc-code"><code id="cd-sc-4">[aidocs_search type="<?php echo esc_html( $first_type ); ?>" audience="<?php echo esc_html( $first_aud ); ?>" per_page="5"]</code><button class="cd-sc-copy" data-target="cd-sc-4"><?php esc_html_e( 'Copy' ); ?></button></div>
        </div>
        <div class="cd-sc-box">
            <h3><?php esc_html_e( 'Without AI inline suggestions' ); ?></h3>
            <p class="cd-sc-desc"><?php esc_html_e( 'Disables AI recommendations in the search bar.' ); ?></p>
            <div class="cd-sc-code"><code id="cd-sc-5">[aidocs_search show_ai="false"]</code><button class="cd-sc-copy" data-target="cd-sc-5"><?php esc_html_e( 'Copy' ); ?></button></div>
        </div>
        <div class="cd-sc-box">
            <h3><?php esc_html_e( 'With the AI chat bubble' ); ?></h3>
            <p class="cd-sc-desc"><?php esc_html_e( 'The floating chat button (bottom-right corner) is off by default; this brings it back.' ); ?></p>
            <div class="cd-sc-code"><code id="cd-sc-6">[aidocs_search show_chat="true"]</code><button class="cd-sc-copy" data-target="cd-sc-6"><?php esc_html_e( 'Copy' ); ?></button></div>
        </div>
        <div class="cd-sc-box">
            <h3><?php esc_html_e( 'Custom results per page' ); ?></h3>
            <p class="cd-sc-desc"><?php esc_html_e( 'Default is 20. Max is 50.' ); ?></p>
            <div class="cd-sc-code"><code id="cd-sc-7">[aidocs_search per_page="10"]</code><button class="cd-sc-copy" data-target="cd-sc-7"><?php esc_html_e( 'Copy' ); ?></button></div>
        </div>
        <div class="cd-sc-box">
            <h3><?php esc_html_e( 'Search only (no AI)' ); ?></h3>
            <p class="cd-sc-desc"><?php esc_html_e( 'Disables all AI features. The chat bubble is already off by default.' ); ?></p>
            <div class="cd-sc-code"><code id="cd-sc-8">[aidocs_search show_ai="false"]</code><button class="cd-sc-copy" data-target="cd-sc-8"><?php esc_html_e( 'Copy' ); ?></button></div>
        </div>
        <div class="cd-sc-box">
            <h3><?php esc_html_e( 'One document, embedded anywhere' ); ?></h3>
            <p class="cd-sc-desc"><?php esc_html_e( 'Shows a single entry\'s own content — the same rendering as its /documents/{entry}/ page — inside any post or page.' ); ?></p>
            <div class="cd-sc-code"><code id="cd-sc-9">[aidocs_document id="<?php echo esc_html( $sample_doc_id ); ?>"]</code><button class="cd-sc-copy" data-target="cd-sc-9"><?php esc_html_e( 'Copy' ); ?></button></div>
            <div class="cd-sc-code"><code id="cd-sc-10">[aidocs_document slug="document-slug"]</code><button class="cd-sc-copy" data-target="cd-sc-10"><?php esc_html_e( 'Copy' ); ?></button></div>
        </div>
        <table class="cd-sc-params" style="margin-top:18px;">
            <thead><tr><th><?php esc_html_e( 'Parameter' ); ?></th><th><?php esc_html_e( 'Default' ); ?></th><th><?php esc_html_e( 'Description' ); ?></th></tr></thead>
            <tbody>
                <tr><td><code>type</code></td><td><?php esc_html_e( '(empty)' ); ?></td><td><?php esc_html_e( 'Pre-select a document type. Also reads ?type= from URL.' ); ?></td></tr>
                <tr><td><code>audience</code></td><td><?php esc_html_e( '(empty)' ); ?></td><td><?php esc_html_e( 'Pre-select an audience. Also reads ?audience= from URL.' ); ?></td></tr>
                <tr><td><code>show_ai</code></td><td><code>true</code></td><td><?php esc_html_e( 'Set "false" to disable inline AI suggestions in the search bar.' ); ?></td></tr>
                <tr><td><code>show_chat</code></td><td><code>false</code></td><td><?php esc_html_e( 'Set "true" to show the floating AI chat bubble.' ); ?></td></tr>
                <tr><td><code>per_page</code></td><td><code>20</code></td><td><?php esc_html_e( 'Results per page (max 50).' ); ?></td></tr>
            </tbody>
        </table>
    </div>

    <script>
    (function() {
        document.querySelectorAll('.cd-sc-copy').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var el = document.getElementById(btn.dataset.target);
                if (!el) return;
                navigator.clipboard.writeText(el.textContent.trim()).then(function() {
                    btn.textContent = '<?php esc_html_e( 'Copied!' ); ?>';
                    btn.classList.add('copied');
                    setTimeout(function() { btn.textContent = '<?php esc_html_e( 'Copy' ); ?>'; btn.classList.remove('copied'); }, 2000);
                });
            });
        });

        // Replace the starting model list with what the saved key can actually
        // reach, keeping the current selection if it is still among them.
        var refresh = document.getElementById('cd-gemini-model-refresh');
        var select  = document.getElementById('cd-gemini-model');
        var status  = document.getElementById('cd-gemini-model-status');
        if (refresh && select) {
            refresh.addEventListener('click', function() {
                refresh.disabled = true;
                status.textContent = '<?php echo esc_js( __( 'Checking…' ) ); ?>';
                var body = new URLSearchParams({
                    action: 'aidocs_ai_credentials',
                    nonce:  <?php echo wp_json_encode( wp_create_nonce( 'aidocs_ai' ) ); ?>,
                    mode:   'probe'
                });
                fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
                    method: 'POST', credentials: 'same-origin', body: body
                })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (!res.success) { status.textContent = 'Error: ' + res.data; return; }
                    var current = select.value;
                    select.innerHTML = '';
                    (res.data.models || []).forEach(function(m) {
                        var opt = document.createElement('option');
                        opt.value = m.id || m;
                        opt.textContent = (m.label || m.id || m);
                        select.appendChild(opt);
                    });
                    select.value = current;
                    if (select.value !== current && select.options.length) select.selectedIndex = 0;
                    status.textContent = select.options.length + ' <?php echo esc_js( __( 'models available' ) ); ?>';
                })
                .catch(function(err) { status.textContent = 'Error: ' + err.message; })
                .finally(function() { refresh.disabled = false; });
            });
        }

        // Validate the key currently typed in the field (saved or not) against
        // the live Gemini API and report a clear pass/fail, distinct from the
        // model-list refresh above.
        var testBtn    = document.getElementById('cd-gemini-key-test');
        var keyField   = document.getElementById('cd-gemini-key');
        var testStatus = document.getElementById('cd-gemini-key-test-status');
        if (testBtn && keyField) {
            testBtn.addEventListener('click', function() {
                testBtn.disabled = true;
                testStatus.style.color = '#646970';
                testStatus.textContent = '<?php echo esc_js( __( 'Testing…' ) ); ?>';
                var body = new URLSearchParams({
                    action:  'aidocs_ai_credentials',
                    nonce:   <?php echo wp_json_encode( wp_create_nonce( 'aidocs_ai' ) ); ?>,
                    mode:    'probe',
                    api_key: keyField.value.trim()
                });
                fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
                    method: 'POST', credentials: 'same-origin', body: body
                })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (!res.success) {
                        testStatus.style.color = '#d63638';
                        testStatus.textContent = '✕ ' + res.data;
                        return;
                    }
                    var count = (res.data.models || []).length;
                    testStatus.style.color = '#46b450';
                    testStatus.textContent = '✓ ' + (
                        count === 1
                            ? <?php echo wp_json_encode( __( 'Key works — 1 model available.' ) ); ?>
                            : <?php echo wp_json_encode( __( 'Key works — %d models available.' ) ); ?>.replace('%d', count)
                    );
                })
                .catch(function(err) {
                    testStatus.style.color = '#d63638';
                    testStatus.textContent = '✕ Error: ' + err.message;
                })
                .finally(function() { testBtn.disabled = false; });
            });
        }
    })();
    </script>
    </div>
    <?php
}


// ──────────────────────────────────────────────
// 6a. Admin Menu — Document Type shortcuts
// ──────────────────────────────────────────────
// One submenu item per configured Document Type, each just a link to the
// standard Documents list pre-filtered by that type — same list table, same
// columns, same bulk actions, nothing duplicated. Audience gets none of
// this: it stays exactly as it is until Cirlot confirms whether it is still
// needed at all, so nothing here assumes it will still exist.
add_action( 'admin_menu', 'aidocs_admin_menu_type_shortcuts', 20 );
function aidocs_admin_menu_type_shortcuts() {
    $parent = 'edit.php?post_type=aidoc';
    foreach ( aidocs_get_types() as $type ) {
        add_submenu_page(
            $parent,
            $type,
            $type,
            'edit_posts',
            $parent . '&document_type=' . sanitize_title( $type )
        );
    }
}

/**
 * WordPress appends every add_submenu_page() call after "Add New", so left
 * alone the Type shortcuts would land after it instead of grouped below it,
 * ahead of Settings, as asked for. Reordered once, late, by matching each
 * existing item's own slug rather than assuming a position.
 *
 * Also where the Type items get set apart visually from "Add New" and
 * "Settings" — a non-clickable "Browse by Type" heading above them and an
 * extra indent on each — since WordPress's admin menu has no native concept
 * of a third level or a separator to group a handful of submenu items as
 * their own cluster. The 5th element of a $submenu entry is an
 * (undocumented but stable — see wp-admin/menu-header.php) extra CSS class
 * on its <li>, which is what aidocs_admin_menu_css() below styles.
 */
add_action( 'admin_menu', 'aidocs_reorder_admin_menu', 999 );
function aidocs_reorder_admin_menu() {
    global $submenu;

    $parent = 'edit.php?post_type=aidoc';
    if ( empty( $submenu[ $parent ] ) ) return;

    $items = $submenu[ $parent ];
    $find  = function ( callable $matches ) use ( $items ) {
        foreach ( $items as $item ) {
            if ( $matches( $item[2] ) ) return $item;
        }
        return null;
    };

    $all_documents = $find( function ( $slug ) use ( $parent ) { return $slug === $parent; } );
    $add_new       = $find( function ( $slug ) { return strpos( $slug, 'post-new.php' ) === 0; } );
    $settings      = $find( function ( $slug ) { return $slug === 'aidocs-settings'; } );
    $documentation = $find( function ( $slug ) { return $slug === 'aidocs-documentation'; } );
    $types         = array_values( array_filter( $items, function ( $item ) use ( $parent ) {
        return strpos( $item[2], $parent . '&document_type=' ) === 0;
    } ) );

    $heading = [];
    if ( $types && current_user_can( 'edit_posts' ) ) {
        $heading = [ [ __( 'Browse by Type' ), 'edit_posts', '#', __( 'Browse by Type' ), 'aidocs-type-heading' ] ];
        $types   = array_map( function ( $item ) {
            $item[4] = trim( ( $item[4] ?? '' ) . ' aidocs-type-item' );
            return $item;
        }, $types );
    }
    if ( $settings && $types ) {
        $settings[4] = trim( ( $settings[4] ?? '' ) . ' aidocs-type-divider-before' );
    }

    $submenu[ $parent ] = array_values( array_filter( array_merge(
        [ $all_documents, $add_new ],
        $heading,
        $types,
        [ $settings, $documentation ]
    ) ) );
}

/**
 * The CSS aidocs_reorder_admin_menu()'s classes style — printed on every
 * admin screen since the sidebar itself is. Scoped to those three classes
 * alone, so it can't affect any other menu.
 */
add_action( 'admin_head', 'aidocs_admin_menu_css' );
function aidocs_admin_menu_css() {
    ?>
    <style>
        #adminmenu .aidocs-type-heading > a {
            pointer-events: none;
            cursor: default;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .5px;
            opacity: .65;
            padding-top: 8px;
            border-top: 1px solid rgba(240,246,252,.15);
            margin-top: 6px;
        }
        #adminmenu .aidocs-type-item > a { padding-left: 26px; }
        #adminmenu .aidocs-type-divider-before > a {
            border-top: 1px solid rgba(240,246,252,.15);
            margin-top: 6px;
            padding-top: 8px;
        }
    </style>
    <?php
}

/**
 * Highlights the right Type shortcut as the current submenu item even when
 * the list is also searched, paginated or filtered by status — matching on
 * the query string alone (WordPress's own default) only works when the URL
 * is exactly the shortcut's own, with nothing else appended.
 */
add_filter( 'submenu_file', 'aidocs_highlight_type_submenu' );
function aidocs_highlight_type_submenu( $submenu_file ) {
    global $pagenow;
    if ( $pagenow !== 'edit.php' ) return $submenu_file;
    if ( ( $_GET['post_type'] ?? '' ) !== 'aidoc' ) return $submenu_file;
    if ( empty( $_GET['document_type'] ) ) return $submenu_file;

    $slug = sanitize_title( wp_unslash( $_GET['document_type'] ) );
    return 'edit.php?post_type=aidoc&document_type=' . $slug;
}

// ──────────────────────────────────────────────
// 6b. Document Type filter on the Documents list
// ──────────────────────────────────────────────
// A dropdown alongside WordPress's own status tabs (All / Published / Draft
// / Trash) and search box — Type is its own, independent axis, not a
// replacement for post status, so it filters on top of whichever status
// view is active rather than instead of it.
add_action( 'restrict_manage_posts', 'aidocs_type_filter_dropdown' );
function aidocs_type_filter_dropdown( $post_type ) {
    if ( $post_type !== 'aidoc' ) return;

    $current = isset( $_GET['document_type'] ) ? sanitize_title( wp_unslash( $_GET['document_type'] ) ) : '';
    ?>
    <select name="document_type">
        <option value=""><?php esc_html_e( 'All Types' ); ?></option>
        <?php foreach ( aidocs_get_types() as $type ) : $slug = sanitize_title( $type ); ?>
        <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current, $slug ); ?>>
            <?php echo esc_html( $type ); ?>
        </option>
        <?php endforeach; ?>
    </select>
    <?php
    // WordPress already prints the "Filter" button once anything hooks
    // restrict_manage_posts, and WP_List_Table::search_box() — already on
    // this screen — preserves every other current query arg (post_status
    // included) as hidden fields in the same #posts-filter form, so
    // submitting this dropdown never drops the active status tab.
}

/**
 * Applies the Type filter above (and the Type shortcut links in the admin
 * menu, which land on this same query string) to the Documents list query.
 * Explicit tax_query rather than relying on document_type's own query var
 * resolving automatically, so this is unambiguous about combining correctly
 * with post_status, search and pagination — all untouched, all still
 * WordPress's own query vars.
 */
add_action( 'pre_get_posts', 'aidocs_filter_admin_list_by_type' );
function aidocs_filter_admin_list_by_type( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) return;
    if ( $query->get( 'post_type' ) !== 'aidoc' ) return;
    if ( empty( $_GET['document_type'] ) ) return;

    $query->set( 'tax_query', [
        [
            'taxonomy' => 'document_type',
            'field'    => 'slug',
            'terms'    => sanitize_title( wp_unslash( $_GET['document_type'] ) ),
        ],
    ] );
}

// ──────────────────────────────────────────────
// 6. Admin Columns
// ──────────────────────────────────────────────
add_filter( 'manage_aidoc_posts_columns', 'aidocs_admin_columns' );
function aidocs_admin_columns( $cols ) {
    $new = [ 'cb' => $cols['cb'], 'title' => $cols['title'] ];
    $new['_document_pub_date'] = __( 'Last Updated' );
    $new['document_audience']  = __( 'Audience' );
    $new['document_type']      = __( 'Type' );
    $new['date']               = $cols['date'];
    return $new;
}

add_action( 'manage_aidoc_posts_custom_column', 'aidocs_admin_column_values', 10, 2 );
function aidocs_admin_column_values( $col, $post_id ) {
    switch ( $col ) {
        case '_document_pub_date':
            echo esc_html( get_post_meta( $post_id, '_document_pub_date', true ) );
            break;
        case 'document_audience':
            $terms = get_the_terms( $post_id, 'document_audience' );
            echo $terms && ! is_wp_error( $terms ) ? esc_html( implode( ', ', wp_list_pluck( $terms, 'name' ) ) ) : '—';
            break;
        case 'document_type':
            $terms = get_the_terms( $post_id, 'document_type' );
            echo $terms && ! is_wp_error( $terms ) ? esc_html( implode( ', ', wp_list_pluck( $terms, 'name' ) ) ) : '—';
            break;
    }
}

// ──────────────────────────────────────────────
// 6. Flush rewrite rules on activation
// ──────────────────────────────────────────────
register_activation_hook( __FILE__, function() {
    aidocs_maybe_migrate_legacy();
    aidocs_register_post_type();
    aidocs_register_taxonomies();

    // Seed predefined terms
    foreach ( AIDOCS_AUDIENCES as $term ) {
        if ( ! term_exists( $term, 'document_audience' ) ) {
            wp_insert_term( $term, 'document_audience' );
        }
    }
    foreach ( AIDOCS_TYPES as $term ) {
        if ( ! term_exists( $term, 'document_type' ) ) {
            wp_insert_term( $term, 'document_type' );
        }
    }

    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

// ──────────────────────────────────────────────
// 8. Frontend Document Search Shortcode
// ──────────────────────────────────────────────
add_shortcode( 'aidocs_search', 'aidocs_search_shortcode' );
function aidocs_search_shortcode( $atts ) {
    $atts = shortcode_atts( [
        'type'      => '',
        'audience'  => '',
        'per_page'  => 20,
        'show_ai'   => 'true',
        // Off by default: every document card already links straight to its
        // own page, so a floating assistant duplicates that with a second,
        // separate way to get there. Pass show_chat="true" to bring it back.
        'show_chat' => 'false',
    ], $atts );

    $url_type     = sanitize_text_field( $_GET['type']     ?? '' );
    $url_audience = sanitize_text_field( $_GET['audience'] ?? '' );

    $default_type     = $url_type     ?: $atts['type'];
    $default_audience = $url_audience ?: $atts['audience'];

    $audiences = aidocs_get_audiences();
    $types     = aidocs_get_types();

    $matched_type = '';
    foreach ( $types as $t ) {
        if ( strtolower( $t ) === strtolower( $default_type ) ) { $matched_type = $t; break; }
    }
    $matched_audience = '';
    foreach ( $audiences as $a ) {
        if ( strtolower( $a ) === strtolower( $default_audience ) ) { $matched_audience = $a; break; }
    }

    $show_ai   = $atts['show_ai']   !== 'false';
    $show_chat = $atts['show_chat'] !== 'false';
    $per_page  = max( 1, min( 50, (int) $atts['per_page'] ) );
    $uid       = 'cds_' . wp_unique_id();
    $nonce     = wp_create_nonce( 'aidocs_search' );
    $ai_nonce  = wp_create_nonce( 'aidocs_ai_search' );
    $ajax_url  = admin_url( 'admin-ajax.php' );

    wp_enqueue_script( 'jquery' );

    // ── Build JS (values substituted at render time, output in footer) ──
    $js_uid     = wp_json_encode( $uid );
    $js_ajax    = wp_json_encode( $ajax_url );
    $js_nonce   = wp_json_encode( $nonce );
    $js_ainonce = wp_json_encode( $ai_nonce );
    $js_pp      = (int) $per_page;
    $js_showai  = $show_ai   ? 'true' : 'false';
    $js_showchat = $show_chat ? 'true' : 'false';
    $js_notext  = esc_js( __( 'No documents found. Try different search terms.' ) );
    $js_errtxt  = esc_js( __( 'Error loading results.' ) );
    $js_loading = esc_js( __( 'Searching…' ) );
    $js_found   = esc_js( __( 'document(s) found' ) );
    $js_page    = esc_js( __( 'Page' ) );
    $js_of      = esc_js( __( 'of' ) );
    $js_sorry      = esc_js( __( 'Sorry, I encountered an error. Please try again.' ) );
    $js_conn       = esc_js( __( 'Connection error. Please try again.' ) );
    $js_send       = esc_js( __( 'Send' ) );
    $js_ai_thinking = esc_js( __( 'AI is analyzing your query…' ) );
    $js_ai_label   = esc_js( __( 'AI Suggestion' ) );
    $js_ai_view    = esc_js( __( 'View details' ) );
    $js_doc_content     = esc_js( __( 'Content' ) );
    $js_loading_content = esc_js( __( 'Loading content…' ) );
    $js_no_content      = esc_js( __( 'No content has been extracted for this entry yet.' ) );

    $js = <<<ENDSCRIPT
jQuery(function($){
    var uid={$js_uid},ajaxUrl={$js_ajax},nonce={$js_nonce},aiNonce={$js_ainonce},perPage={$js_pp},showAi={$js_showai},showChat={$js_showchat};
    var \$wrap=\$('#'+uid),\$results=\$wrap.find('.cd-fs-results'),currentPage=1,botHistory=[],lastFilters=null;
    var \$aiExplain=\$wrap.find('.cd-fs-ai-explain');
    var \$kwWrap=\$wrap.find('.cd-fs-keyword-wrap');
    var \$kw=\$wrap.find('.cd-fs-keyword');
    var \$suggestions=\$('<div class="cd-fs-suggestions"></div>').appendTo(\$kwWrap);
    var _suggTimer,_explainXhr,_aiTimer,_searchTimer;

    \$kw.on('input',function(){
        clearTimeout(_suggTimer);
        clearTimeout(_aiTimer);
        clearTimeout(_searchTimer);
        var val=\$(this).val().trim();
        /* autocomplete dropdown */
        if(val.length>=2){
            _suggTimer=setTimeout(function(){
                \$.post(ajaxUrl,{action:'aidocs_search',nonce:nonce,keyword:val,page:1,per_page:6})
                .done(function(res){
                    \$suggestions.empty();
                    if(!res.success||!res.data.results.length){\$suggestions.hide();return;}
                    \$.each(res.data.results,function(_,doc){
                        var \$s=\$('<div class="cd-fs-suggestion"></div>');
                        \$('<span class="cd-fs-suggestion-title"></span>').text(doc.title).appendTo(\$s);
                        \$s.on('click',function(){\$kw.val(doc.title);\$suggestions.hide().empty();doSearch(1);});
                        \$suggestions.append(\$s);
                    });
                    \$suggestions.show();
                });
            },380);
        } else {
            \$suggestions.hide().empty();
        }
        /* Exact-text results — debounced live search, no button click needed.
           doSearch() renders its own loading/empty states, so it's safe to
           let it own \$results instead of clearing it here first. */
        _searchTimer=setTimeout(function(){doSearch(1);},400);
        /* AI recommendation on typing */
        if(showAi){
            if(val.length<2){\$aiExplain.empty();return;}
            \$aiExplain.empty();
            _aiTimer=setTimeout(function(){
                var aud=\$wrap.find('.cd-fs-audience').val();
                var typ=\$wrap.find('.cd-fs-type').val();
                fetchAiRecommend(val,aud,typ);
            },600);
        }
    });
    \$(document).on('click.cdsugg'+uid,function(e){if(!\$(e.target).closest('.cd-fs-keyword-wrap').length)\$suggestions.hide();});

    var \$kwClear=\$wrap.find('.cd-fs-kw-clear');
    \$kw.on('input.clear',function(){\$kwClear.toggleClass('visible',\$(this).val().length>0);});
    \$kwClear.on('click',function(){
        clearTimeout(_aiTimer);clearTimeout(_suggTimer);
        if(_explainXhr)_explainXhr.abort();
        \$kw.val('');\$kwClear.removeClass('visible');
        \$suggestions.hide().empty();\$aiExplain.empty();
        doSearch(1);\$kw.focus();
    });

    var \$modalOverlay=\$('#cd-doc-modal-overlay-'+uid);
    \$('body').append(\$modalOverlay.detach());
    var \$modalTitle=\$('#cd-doc-modal-title-'+uid);
    var \$modalTags=\$('#cd-doc-modal-tags-'+uid),\$modalBody=\$('#cd-doc-modal-body-'+uid);
    var \$modalFooter=\$('#cd-doc-modal-footer-'+uid);
    var \$modalPermalink=\$('#cd-doc-modal-permalink-'+uid);
    var \$dcMsgs=\$('#cd-doc-chat-msgs-'+uid);
    var \$dcInput=\$('#cd-doc-chat-input-'+uid);
    var \$dcSend=\$('#cd-doc-chat-send-'+uid);
    var \$dcCollapse=\$('#cd-doc-ask-collapse-'+uid);
    var _dcHistory=[],_dcDocId=null;
    var _contentCache={},_contentXhr=null;

    function openModal(doc){
        \$modalTitle.text(doc.title||'');
        var tags='';
        \$.each(doc.audience||[],function(_,a){tags+='<span class="cd-fs-doc-tag audience">'+\$('<span>').text(a).html()+'</span>';});
        \$.each(doc.type||[],function(_,t){tags+='<span class="cd-fs-doc-tag type">'+\$('<span>').text(t).html()+'</span>';});
        \$modalTags.html(tags);
        /* Extracted fields first (the AI-filled metadata), structured body after */
        var body='';
        if(doc.description) body+='<div class="cd-doc-modal-desc">'+\$('<span>').text(doc.description).html()+'</div>';
        var grid='';
        if((doc.audience||[]).length) grid+='<div class="cd-doc-modal-field"><div class="cd-doc-modal-label">Audience</div><div class="cd-doc-modal-value">'+\$('<span>').text(doc.audience.join(', ')).html()+'</div></div>';
        if((doc.type||[]).length)     grid+='<div class="cd-doc-modal-field"><div class="cd-doc-modal-label">Document Type</div><div class="cd-doc-modal-value">'+\$('<span>').text(doc.type.join(', ')).html()+'</div></div>';
        if(doc.pub_date)              grid+='<div class="cd-doc-modal-field"><div class="cd-doc-modal-label">Last Updated</div><div class="cd-doc-modal-value">'+formatDate(doc.pub_date)+'</div></div>';
        if(grid) body+='<div class="cd-doc-modal-grid">'+grid+'</div>';
        body+='<div class="aidocs-section-label">{$js_doc_content}</div>';
        body+='<div class="cd-doc-content-slot"><div class="aidocs-content-loading">{$js_loading_content}</div></div>';
        \$modalBody.html(body);
        loadContent(doc.id);
        \$modalFooter.find('.cd-doc-modal-footer-left').remove();
        var footerLeft=doc.pub_date?'<span class="cd-doc-modal-footer-left">'+formatDate(doc.pub_date)+'</span>':'<span class="cd-doc-modal-footer-left"></span>';
        \$modalFooter.prepend(footerLeft);
        \$modalPermalink.hide().attr('href','#');
        /* Reset the Ask AI bar per doc */
        if(_dcDocId!==doc.id){
            _dcDocId=doc.id;_dcHistory=[];
            \$dcMsgs.empty().removeClass('open');
            \$dcCollapse.hide();
        }
        \$modalOverlay.addClass('open');
        \$('body').css('overflow','hidden');
    }

    /* Structured content is fetched on demand so search results stay light */
    function loadContent(docId){
        var \$slot=\$modalBody.find('.cd-doc-content-slot');
        if(_contentCache[docId]!==undefined){
            \$slot.html(_contentCache[docId]||'<div class="aidocs-content-empty">{$js_no_content}</div>');
            return;
        }
        if(_contentXhr)_contentXhr.abort();
        _contentXhr=\$.post(ajaxUrl,{action:'aidocs_doc_content',nonce:nonce,doc_id:docId})
        .done(function(res){
            if(!res.success){\$slot.html('<div class="aidocs-content-empty">{$js_no_content}</div>');return;}
            _contentCache[docId]=res.data.html||'';
            \$slot.html(res.data.html||'<div class="aidocs-content-empty">{$js_no_content}</div>');
            if(res.data.permalink)\$modalPermalink.attr('href',res.data.permalink).show();
        })
        .fail(function(_,status){
            if(status==='abort')return;
            \$slot.html('<div class="aidocs-content-empty">{$js_no_content}</div>');
        });
    }

    function closeModal(){
        \$modalOverlay.removeClass('open');
        \$('body').css('overflow','');
    }

    function addDocChatTurn(role,text){
        var \$turn=\$('<div class="cd-bot-turn '+role+'"></div>');
        \$turn.append(\$('<div class="cd-bot-msg"></div>').text(text));
        \$dcMsgs.addClass('open').append(\$turn);
        \$dcCollapse.show();
        \$dcMsgs.scrollTop(\$dcMsgs[0].scrollHeight);
    }

    \$dcCollapse.on('click',function(){
        \$dcMsgs.removeClass('open');
        \$(this).hide();
    });
    \$dcInput.on('focus',function(){
        if(\$dcMsgs.children().length)\$dcMsgs.addClass('open'),\$dcCollapse.show();
    });

    function sendDocChat(){
        var msg=\$dcInput.val().trim();if(!msg||!_dcDocId)return;
        addDocChatTurn('user',msg);\$dcInput.val('');\$dcSend.prop('disabled',true).text('…');
        _dcHistory.push({role:'user',text:msg});
        var \$th=\$('<div class="cd-bot-thinking"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg><span class="cd-fs-ai-dots"><span></span><span></span><span></span></span></div>').appendTo(\$dcMsgs);
        \$dcMsgs.scrollTop(\$dcMsgs[0].scrollHeight);
        \$.post(ajaxUrl,{action:'aidocs_ai_doc_chat',nonce:aiNonce,doc_id:_dcDocId,message:msg,history:JSON.stringify(_dcHistory.slice(-6))})
        .done(function(res){
            \$th.remove();
            var reply=res.success?res.data.message:'{$js_sorry}';
            addDocChatTurn('bot',reply);
            if(res.success)_dcHistory.push({role:'model',text:reply});
        }).fail(function(){\$th.remove();addDocChatTurn('bot','{$js_conn}');})
        .always(function(){\$dcSend.prop('disabled',false).text('{$js_send}');});
    }

    \$dcSend.on('click',sendDocChat);
    \$dcInput.on('keydown',function(e){if(e.key==='Enter')sendDocChat();});

    \$('#cd-doc-modal-close-'+uid).on('click',closeModal);
    \$('#cd-doc-modal-cancel-'+uid).on('click',closeModal);
    \$modalOverlay.on('click',function(e){if(\$(e.target).is(\$modalOverlay))closeModal();});
    \$(document).on('keydown.cdmodal'+uid,function(e){if(e.key==='Escape')closeModal();});

    function formatDate(d){
        if(!d)return'';
        var p=d.split('-');if(p.length<3)return d;
        var m=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return m[parseInt(p[1],10)-1]+' '+parseInt(p[2],10)+', '+p[0];
    }

    function renderResults(data){
        \$results.empty();
        if(!data.results.length){\$results.html('<div class="cd-fs-empty">{$js_notext}</div>');return;}
        var hdr=\$('<div class="cd-fs-results-header"></div>').text(data.total+' {$js_found}'+(data.total_pages>1?' \u2014 {$js_page} '+data.page+' {$js_of} '+data.total_pages:''));
        \$results.append(hdr);
        \$.each(data.results,function(i,doc){
            var tags='';
            \$.each(doc.audience||[],function(_,a){tags+='<span class="cd-fs-doc-tag audience">'+\$('<span>').text(a).html()+'</span>';});
            \$.each(doc.type||[],function(_,t){tags+='<span class="cd-fs-doc-tag type">'+\$('<span>').text(t).html()+'</span>';});
            if(doc.pub_date)tags+='<span class="cd-fs-doc-tag date">'+formatDate(doc.pub_date)+'</span>';
            /* A reading glyph, not a file one: what a card leads to is the
               information itself, never a file to open or save. */
            var docSvg='<svg width="22" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>';
            // The card itself already navigates straight to the entry, so a
            // "View"/"Download" pair here would just duplicate that.
            var snippetHtml=doc.snippet?'<p class="cd-fs-doc-snippet">'+doc.snippet+'</p>':'';
            var \$card=\$(
                '<div class="cd-fs-doc-card">'+
                '<div class="cd-fs-doc-icon">'+docSvg+'</div>'+
                '<div class="cd-fs-doc-body">'+
                '<p class="cd-fs-doc-title">'+\$('<span>').text(doc.title).html()+'</p>'+
                snippetHtml+
                '<div class="cd-fs-doc-meta">'+tags+'</div></div>'
            );
            \$card.on('click',function(){if(doc.permalink)location.href=doc.permalink;});
            \$results.append(\$card);
        });
        if(data.total_pages>1){
            var \$pag=\$('<div class="cd-fs-pagination"></div>');
            for(var p=1;p<=data.total_pages;p++){(function(pg){
                var \$b=\$('<button class="cd-fs-page-btn'+(pg===data.page?' active':'')+'" type="button"></button>').text(pg);
                \$b.on('click',function(){doSearch(pg);});\$pag.append(\$b);
            })(p);}
            \$results.append(\$pag);
        }
    }

    function fetchAiRecommend(kw,aud,typ){
        if(_explainXhr)_explainXhr.abort();
        var query=[kw,aud,typ].filter(Boolean).join(' ');
        if(!query){\$aiExplain.empty();return;}
        \$aiExplain.html('<div class="cd-fs-ai-thinking"><div class="cd-fs-ai-thinking-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div><span class="cd-fs-ai-thinking-text">{$js_ai_thinking}<span class="cd-fs-ai-dots"><span></span><span></span><span></span></span></span></div>');
        _explainXhr=\$.post(ajaxUrl,{
            action:'aidocs_ai_recommend',nonce:aiNonce,
            message:query,history:'[]'
        }).done(function(res){
            if(!res.success){\$aiExplain.empty();return;}
            var \$box=\$('<div class="cd-fs-ai-suggest-box"></div>');
            var \$lbl=\$('<div class="cd-fs-ai-suggest-label"></div>');
            \$lbl.append('<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>');
            \$lbl.append(\$('<span>{$js_ai_label}</span>'));
            \$box.append(\$lbl);
            if(res.data.message)\$box.append(\$('<p class="cd-fs-ai-suggest-msg"></p>').text(res.data.message));
            \$.each(res.data.docs||[],function(_,doc){
                var \$card=\$('<div class="cd-fs-ai-suggest-doc"></div>');
                var \$info=\$('<div class="cd-fs-ai-suggest-doc-info"></div>');
                \$('<div class="cd-fs-ai-suggest-doc-title"></div>').text(doc.title).appendTo(\$info);
                var \$actions=\$('<div class="cd-fs-ai-suggest-doc-actions"></div>');
                \$('<button class="cd-fs-ai-suggest-view" type="button">{$js_ai_view}</button>').on('click',function(){if(doc.permalink)location.href=doc.permalink;}).appendTo(\$actions);
                \$info.append(\$actions);
                \$card.append(\$info);
                \$box.append(\$card);
            });
            \$aiExplain.html('').append(\$box);
        }).fail(function(){\$aiExplain.empty();});
    }

    function doSearch(page){
        page=page||1;currentPage=page;
        \$results.html('<div class="cd-fs-loading">{$js_loading}</div>');
        var kw=\$wrap.find('.cd-fs-keyword').val();
        var aud=\$wrap.find('.cd-fs-audience').val();
        var typ=\$wrap.find('.cd-fs-type').val();
        \$suggestions.hide().empty();
        \$.post(ajaxUrl,{
            action:'aidocs_search',nonce:nonce,
            keyword:kw,audience:aud,type:typ,
            page:page,per_page:perPage
        }).done(function(res){
            if(res.success)renderResults(res.data);
            else \$results.html('<div class="cd-fs-empty">{$js_errtxt}</div>');
        }).fail(function(){
            \$results.html('<div class="cd-fs-empty">{$js_errtxt}</div>');
        });
    }

    \$wrap.find('.cd-fs-search-btn').on('click',function(){doSearch(1);});
    \$wrap.find('.cd-fs-keyword').on('keydown',function(e){if(e.key==='Enter')doSearch(1);});
    doSearch(1);

    if(!showChat)return;

    var \$toggle=\$('#cd-bot-toggle-'+uid),\$panel=\$('#cd-bot-panel-'+uid);
    var \$messages=\$('#cd-bot-messages-'+uid),\$input=\$('#cd-bot-input-'+uid),\$send=\$('#cd-bot-send-'+uid);

    \$toggle.on('click',function(){\$panel.toggleClass('open');if(\$panel.hasClass('open'))\$input.focus();});
    \$('#cd-bot-close-'+uid).on('click',function(){\$panel.removeClass('open');});

    function addTurn(role,text,docs){
        var \$turn=\$('<div class="cd-bot-turn '+role+'"></div>');
        \$turn.append(\$('<div class="cd-bot-msg"></div>').text(text));
        if(docs&&docs.length){
            \$.each(docs,function(_,doc){
                var \$card=\$('<div class="cd-bot-doc-card"></div>');
                var \$info=\$('<div class="cd-bot-doc-info"></div>');
                \$('<div class="cd-bot-doc-title"></div>').text(doc.title).appendTo(\$info);
                \$card.append(\$info);
                \$card.on('click',function(e){if(!\$(e.target).closest('a').length&&doc.permalink)location.href=doc.permalink;});
                \$turn.append(\$card);
            });
        }
        \$messages.append(\$turn);\$messages.scrollTop(\$messages[0].scrollHeight);
    }

    function sendBotMessage(){
        var msg=\$input.val().trim();if(!msg)return;
        addTurn('user',msg);\$input.val('');\$send.prop('disabled',true).text('…');
        botHistory.push({role:'user',text:msg});
        var \$thinking=\$('<div class="cd-bot-thinking"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> …</div>').appendTo(\$messages);
        \$messages.scrollTop(\$messages[0].scrollHeight);
        \$.post(ajaxUrl,{action:'aidocs_ai_recommend',nonce:aiNonce,message:msg,history:JSON.stringify(botHistory.slice(-6))})
        .done(function(res){
            \$thinking.remove();
            if(res.success){addTurn('bot',res.data.message,res.data.docs);botHistory.push({role:'model',text:res.data.message});}
            else addTurn('bot','{$js_sorry}');
        }).fail(function(){\$thinking.remove();addTurn('bot','{$js_conn}');})
        .always(function(){\$send.prop('disabled',false).text('{$js_send}');});
    }

    \$send.on('click',sendBotMessage);
    \$input.on('keydown',function(e){if(e.key==='Enter')sendBotMessage();});
});
ENDSCRIPT;

    add_action( 'wp_footer', function() use ( $js ) {
        echo '<script>' . $js . '</script>' . "\n";
    }, 99 );

    ob_start();
    ?>
    <style>
    .cd-fs-wrap{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:900px;margin:0 auto;}
    .cd-fs-card{background:#fff;border:1.5px solid #d8dde6;border-radius:14px;padding:36px 40px 32px;}
    .cd-fs-title{text-align:center;font-size:26px;font-weight:700;color:var(--wp--preset--color--contrast,#1a2744);margin:0 0 6px;display:flex;align-items:center;gap:16px;}
    .cd-fs-title::before,.cd-fs-title::after{content:'';flex:1;height:1.5px;background:linear-gradient(to right,transparent,#c8d0dc);}
    .cd-fs-title::after{background:linear-gradient(to left,transparent,#c8d0dc);}
    .cd-fs-subtitle{text-align:center;font-size:13px;color:#6b7280;margin:0 0 24px;display:flex;align-items:center;justify-content:center;gap:6px;}
    .cd-fs-subtitle-badge{display:inline-flex;align-items:center;gap:5px;background:linear-gradient(135deg,#e8f0fb,#dbeafe);border:1px solid #bfdbfe;border-radius:20px;padding:3px 10px;font-size:12px;font-weight:600;color:var(--wp--preset--color--secondary,#2c4a7c);}
    /* Single-row controls */
    .cd-fs-controls{display:flex;gap:10px;align-items:center;margin-bottom:0;}
    .cd-fs-keyword-wrap{flex:2;min-width:0;position:relative;}
    .cd-fs-keyword-wrap>svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;pointer-events:none;}
    /* input[type="text"] in this theme's own global stylesheet carries an
       attribute selector, which counts as a class for specificity — putting
       it a notch above a single plain class like .cd-fs-keyword and letting
       its own padding win, which left this input's text sitting under the
       search icon rather than clear of it. The wrapper-qualified selector
       below outweighs that regardless of style order. */
    .cd-fs-keyword-wrap .cd-fs-keyword{width:100%;box-sizing:border-box;height:46px;padding:0 36px 0 38px;border:1.5px solid #c8d0dc;border-radius:8px;font-size:14px;color:var(--wp--preset--color--contrast,#1a2744);background:#fff;outline:none;transition:border-color .18s;}
    .cd-fs-keyword:focus{border-color:var(--wp--preset--color--secondary,#2c4a7c);}
    .cd-fs-kw-clear{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#9ca3af;padding:4px;line-height:1;font-size:16px;display:none;border-radius:50%;transition:color .15s,background .15s;}
    .cd-fs-kw-clear:hover{color:var(--wp--preset--color--contrast,#1a2744);background:#f0f2f5;}
    .cd-fs-kw-clear.visible{display:flex;align-items:center;justify-content:center;}
    .cd-fs-select-wrap{flex:1;min-width:120px;}
    .cd-fs-select-wrap select{width:100%;height:46px;padding:0 36px 0 12px;border:1.5px solid #c8d0dc;border-radius:8px;font-size:13px;color:var(--wp--preset--color--contrast,#1a2744);background:#fff;outline:none;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%232c4a7c' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;cursor:pointer;box-sizing:border-box;transition:border-color .18s;}
    .cd-fs-select-wrap select:focus{border-color:var(--wp--preset--color--secondary,#2c4a7c);}
    /* Search button */
    .cd-fs-search-btn{height:46px;padding:0 22px;background:var(--wp--preset--color--primary,#1e3a5f);color:#fff;border:none;border-radius:var(--wp--custom--button-border-radius,8px);font-size:14px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:background .18s;flex-shrink:0;}
    .cd-fs-search-btn:hover{background:var(--wp--preset--color--secondary,#2c4a7c);}
    /* Results */
    .cd-fs-results{margin-top:24px;}
    .cd-fs-results-header{font-size:13px;color:#6b7280;margin-bottom:14px;}
    .cd-fs-doc-card{display:flex;gap:16px;padding:18px 20px;border:1px solid #e5e9ef;border-radius:10px;margin-bottom:12px;background:#fff;transition:box-shadow .18s,border-color .18s;}
    .cd-fs-doc-card:hover{box-shadow:0 3px 14px rgba(0,0,0,.08);border-color:#b8cce4;}
    .cd-fs-doc-icon{flex-shrink:0;width:44px;height:54px;border-radius:8px;display:flex;align-items:center;justify-content:center;background:#dbeafe;}
    .cd-fs-doc-icon svg{color:#2563eb;}
    .cd-fs-doc-body{flex:1;min-width:0;}
    .cd-fs-doc-title{font-size:15px;font-weight:700;color:var(--wp--preset--color--contrast,#1a2744);margin:0 0 6px;}
    .cd-fs-doc-title a{color:inherit;text-decoration:none;}.cd-fs-doc-title a:hover{color:var(--wp--preset--color--secondary,#2c4a7c);text-decoration:underline;}
    .cd-fs-doc-desc{font-size:13px;color:#6b7280;margin:0 0 10px;line-height:1.55;}
    .cd-fs-doc-snippet{font-size:13px;color:#4b5563;margin:0 0 10px;line-height:1.55;background:#f9fafb;border-left:2.5px solid #bfdbfe;padding:6px 10px;border-radius:0 6px 6px 0;}
    .cd-fs-doc-snippet mark{background:#fef08a;color:inherit;padding:0 1px;border-radius:2px;}
    .cd-fs-doc-meta{display:flex;flex-wrap:wrap;gap:6px;align-items:center;}
    .cd-fs-doc-tag{font-size:11px;padding:3px 9px;border-radius:20px;font-weight:600;display:inline-flex;align-items:center;gap:4px;}
    .cd-fs-doc-tag.type{background:#e8f0fb;color:var(--wp--preset--color--secondary,#2c4a7c);}.cd-fs-doc-tag.audience{background:#f0faf4;color:#1e6e45;}.cd-fs-doc-tag.date{background:#f5f5f5;color:#6b7280;}
    .cd-fs-empty{text-align:center;padding:40px 20px;color:#9ca3af;font-size:14px;}
    .cd-fs-pagination{display:flex;gap:6px;justify-content:center;margin-top:18px;}
    .cd-fs-page-btn{height:34px;min-width:34px;padding:0 10px;border:1.5px solid #d8dde6;background:#fff;border-radius:var(--wp--custom--button-border-radius,6px);font-size:13px;cursor:pointer;transition:background .15s,border-color .15s;}
    .cd-fs-page-btn:hover,.cd-fs-page-btn.active{background:var(--wp--preset--color--primary,#1e3a5f);color:#fff;border-color:var(--wp--preset--color--primary,#1e3a5f);}
    .cd-fs-loading{text-align:center;padding:32px;color:#9ca3af;font-size:14px;}
    /* Autocomplete suggestions */
    .cd-fs-suggestions{position:absolute;top:calc(100% + 2px);left:0;right:0;background:#fff;border:1.5px solid #c8d0dc;border-radius:0 0 10px 10px;z-index:200;box-shadow:0 6px 20px rgba(0,0,0,.1);max-height:220px;overflow-y:auto;display:none;}
    .cd-fs-suggestion{padding:9px 14px 9px 38px;font-size:13px;color:#374151;cursor:pointer;display:flex;align-items:center;gap:8px;border-bottom:1px solid #f0f2f5;}
    .cd-fs-suggestion:last-child{border-bottom:none;}
    .cd-fs-suggestion:hover,.cd-fs-suggestion.highlighted{background:#f0f6ff;color:var(--wp--preset--color--contrast,#1a2744);}
    .cd-fs-suggestion-title{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    /* AI explanation */
    .cd-fs-ai-explain{margin-top:16px;}
    .cd-fs-ai-suggest-box{background:linear-gradient(135deg,#f0f6ff,#e8f3ff);border:1.5px solid #b8d0f0;border-radius:12px;padding:16px 18px;margin-bottom:8px;}
    .cd-fs-ai-suggest-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--wp--preset--color--secondary,#2c4a7c);margin-bottom:10px;display:flex;align-items:center;gap:6px;}
    .cd-fs-ai-suggest-msg{font-size:13px;color:#374151;line-height:1.65;margin:0 0 14px;}
    .cd-fs-ai-suggest-doc{display:flex;gap:12px;align-items:center;background:#fff;border:1px solid #d0dce8;border-radius:9px;padding:11px 14px;margin-bottom:8px;}
    .cd-fs-ai-suggest-doc:last-child{margin-bottom:0;}
    .cd-fs-ai-suggest-doc-info{flex:1;min-width:0;}
    .cd-fs-ai-suggest-doc-title{font-size:14px;font-weight:600;color:var(--wp--preset--color--contrast,#1a2744);margin-bottom:8px;line-height:1.4;}
    .cd-fs-ai-suggest-doc-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
    .cd-fs-ai-suggest-view{height:32px;padding:0 14px;background:var(--wp--preset--color--primary,#1e3a5f);color:#fff;border:none;border-radius:var(--wp--custom--button-border-radius,6px);font-size:12px;font-weight:600;cursor:pointer;transition:background .15s;}
    .cd-fs-ai-suggest-view:hover{background:var(--wp--preset--color--secondary,#2c4a7c);}
    @keyframes cd-dot-bounce{0%,80%,100%{transform:translateY(0);opacity:.4;}40%{transform:translateY(-5px);opacity:1;}}
    .cd-fs-ai-thinking{display:flex;align-items:center;gap:10px;padding:14px 18px;background:linear-gradient(135deg,#f0f6ff,#e8f3ff);border:1.5px solid #b8d0f0;border-radius:12px;}
    .cd-fs-ai-thinking-icon{width:30px;height:30px;background:linear-gradient(135deg,var(--wp--preset--color--primary,#1e3a5f),var(--wp--preset--color--secondary,#2c4a7c));border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
    .cd-fs-ai-thinking-icon svg{color:#fff;}
    .cd-fs-ai-thinking-text{font-size:13px;color:var(--wp--preset--color--secondary,#2c4a7c);font-weight:500;}
    .cd-fs-ai-dots{display:inline-flex;align-items:center;gap:3px;margin-left:4px;vertical-align:middle;}
    .cd-fs-ai-dots span{width:5px;height:5px;background:var(--wp--preset--color--secondary,#2c4a7c);border-radius:50%;animation:cd-dot-bounce 1.2s infinite ease-in-out;}
    .cd-fs-ai-dots span:nth-child(2){animation-delay:.2s;}
    .cd-fs-ai-dots span:nth-child(3){animation-delay:.4s;}
    /* Document modal */
    .cd-doc-modal-overlay{position:fixed;inset:0;background:rgba(10,18,35,.6);z-index:99990;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .22s;}
    .cd-doc-modal-overlay.open{opacity:1;pointer-events:auto;}
    .cd-doc-modal{background:#fff;border-radius:18px;width:100%;max-width:820px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 24px 80px rgba(0,0,0,.28);transform:translateY(20px) scale(.97);transition:transform .24s cubic-bezier(.22,.68,0,1.2),opacity .22s;opacity:0;overflow:hidden;}
    .cd-doc-modal-overlay.open .cd-doc-modal{transform:translateY(0) scale(1);opacity:1;}
    .cd-doc-modal-header{display:flex;align-items:flex-start;gap:18px;padding:22px 24px 18px;border-bottom:1px solid #f0f2f5;flex-shrink:0;}
    .cd-doc-modal-title-wrap{flex:1;min-width:0;padding-top:2px;}
    .cd-doc-modal-title{font-size:17px;font-weight:700;color:var(--wp--preset--color--contrast,#1a2744);margin:0 0 10px;line-height:1.4;}
    .cd-doc-modal-tags{display:flex;flex-wrap:wrap;gap:5px;}
    .cd-doc-modal-close{background:none;border:none;cursor:pointer;color:#b0b8c8;padding:4px;line-height:1;flex-shrink:0;font-size:22px;border-radius:6px;transition:color .15s,background .15s;}
    .cd-doc-modal-close:hover{color:var(--wp--preset--color--contrast,#1a2744);background:#f0f2f5;}
    /* Modal tabs */
    .cd-doc-modal-pane{display:none;flex-direction:column;flex:1;overflow:hidden;}
    .cd-doc-modal-pane.active{display:flex;}
    /* Details pane */
    .cd-doc-modal-body{padding:22px 24px;overflow-y:auto;flex:1;}
    .cd-doc-modal-desc{font-size:14px;color:#374151;line-height:1.7;margin-bottom:20px;padding-bottom:20px;border-bottom:1px solid #f0f2f5;}
    .cd-doc-modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
    .cd-doc-modal-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#b0b8c8;margin-bottom:5px;}
    .cd-doc-modal-value{font-size:14px;color:var(--wp--preset--color--contrast,#1a2744);font-weight:500;line-height:1.5;}
    /* Chat pane */
    /* Ask AI — persistent bar pinned below the panes */
    .cd-doc-ask{flex-shrink:0;border-top:1px solid #e5e9ef;background:#fbfcfd;display:flex;flex-direction:column;}
    .cd-doc-ask-answers{display:none;max-height:240px;overflow-y:auto;padding:14px 18px;flex-direction:column;gap:10px;border-bottom:1px solid #edf0f4;background:#fff;}
    .cd-doc-ask-answers.open{display:flex;}
    .cd-doc-ask-bar{display:flex;align-items:center;gap:10px;padding:11px 16px;position:relative;}
    .cd-doc-ask-icon{color:var(--wp--preset--color--secondary,#2c4a7c);flex-shrink:0;}
    .cd-doc-ask-input{flex:1;height:40px;padding:0 14px;border:1.5px solid #c8d0dc;border-radius:8px;font-size:13px;outline:none;background:#fff;color:var(--wp--preset--color--contrast,#1a2744);}
    .cd-doc-ask-input:focus{border-color:var(--wp--preset--color--secondary,#2c4a7c);}
    .cd-doc-ask-send{height:40px;padding:0 18px;background:var(--wp--preset--color--primary,#1e3a5f);color:#fff;border:none;border-radius:var(--wp--custom--button-border-radius,8px);font-size:13px;font-weight:600;cursor:pointer;transition:background .15s;flex-shrink:0;}
    .cd-doc-ask-send:hover{background:var(--wp--preset--color--secondary,#2c4a7c);}
    .cd-doc-ask-send:disabled{opacity:.5;cursor:default;}
    .cd-doc-ask-collapse{background:none;border:none;cursor:pointer;font-size:20px;color:#9ca3af;line-height:1;padding:0 4px;flex-shrink:0;}
    .cd-doc-ask-collapse:hover{color:var(--wp--preset--color--contrast,#1a2744);}
    .cd-doc-modal-permalink{display:inline-flex;align-items:center;font-size:13px;color:var(--wp--preset--color--primary,#1e3a5f);border:1.5px solid var(--wp--preset--color--primary,#1e3a5f);border-radius:var(--wp--custom--button-border-radius,7px);text-decoration:none;font-weight:600;padding:7px 14px;margin-right:8px;transition:background .15s,color .15s;}
    .cd-doc-modal-permalink:hover{background:var(--wp--preset--color--primary,#1e3a5f);color:#fff;}
    /* Structured document content */
    .aidocs-content{margin-top:4px;}
    .aidocs-content-h2{font-size:17px;font-weight:700;color:var(--wp--preset--color--contrast,#1a2744);margin:26px 0 10px;line-height:1.35;}
    .aidocs-content-h2:first-child{margin-top:0;}
    .aidocs-content-h3{font-size:14px;font-weight:700;color:var(--wp--preset--color--secondary,#2c4a7c);margin:20px 0 8px;text-transform:uppercase;letter-spacing:.4px;}
    .aidocs-content-h3:first-child{margin-top:0;}
    .aidocs-content-p{font-size:14px;color:#374151;line-height:1.75;margin:0 0 12px;}
    .aidocs-content-list{margin:0 0 14px;padding-left:22px;}
    .aidocs-content-list li{font-size:14px;color:#374151;line-height:1.7;margin-bottom:7px;}
    .aidocs-content-empty{font-size:13px;color:#9ca3af;font-style:italic;padding:6px 0;}
    .aidocs-content-loading{font-size:13px;color:#9ca3af;padding:6px 0;}
    <?php echo aidocs_content_block_css(); // phpcs:ignore WordPress.Security.EscapeOutput -- static CSS ?>
    .aidocs-doc-history{margin-top:22px;padding:12px 14px;background:#f8f9fb;border-left:3px solid #d0dce8;border-radius:0 6px 6px 0;font-size:12px;color:#6b7280;line-height:1.65;}
    .aidocs-doc-history-label{display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:#b0b8c8;margin-bottom:4px;}
    .aidocs-section-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#b0b8c8;margin:24px 0 10px;padding-top:18px;border-top:1px solid #f0f2f5;}
    .cd-doc-modal-footer{padding:14px 24px;background:#f8f9fb;border-top:1px solid #edf0f4;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
    .cd-doc-modal-footer-left{font-size:12px;color:#9ca3af;}
    .cd-doc-modal-footer-right{display:flex;gap:10px;align-items:center;}
    .cd-doc-modal-cancel{height:42px;padding:0 18px;border:1.5px solid #d8dde6;background:#fff;border-radius:8px;font-size:14px;color:#374151;cursor:pointer;transition:border-color .15s,background .15s;}
    .cd-doc-modal-cancel:hover{border-color:var(--wp--preset--color--secondary,#2c4a7c);background:#f0f6ff;}
    /* card clickable */
    .cd-fs-doc-card{cursor:pointer;}
    /* AI Bot */
    .cd-bot-toggle{position:fixed;bottom:28px;right:28px;z-index:9990;background:linear-gradient(135deg,var(--wp--preset--color--primary,#1e3a5f),var(--wp--preset--color--secondary,#2c4a7c));color:#fff;border:none;border-radius:50px;padding:13px 22px;font-size:14px;font-weight:600;cursor:pointer;box-shadow:0 4px 20px rgba(30,58,95,.4);display:flex;align-items:center;gap:8px;transition:transform .12s,box-shadow .18s;}
    .cd-bot-toggle:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(30,58,95,.5);}
    .cd-bot-panel{position:fixed;bottom:90px;right:28px;z-index:9991;width:380px;max-width:calc(100vw - 40px);background:#fff;border:1.5px solid #d8dde6;border-radius:16px;box-shadow:0 12px 50px rgba(0,0,0,.18);display:none;flex-direction:column;max-height:540px;}
    .cd-bot-panel.open{display:flex;}
    .cd-bot-header{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #e5e9ef;flex-shrink:0;background:linear-gradient(135deg,#f0f6ff,#e8f3ff);border-radius:14px 14px 0 0;}
    .cd-bot-header-info{display:flex;flex-direction:column;gap:2px;}
    .cd-bot-header strong{font-size:14px;color:var(--wp--preset--color--contrast,#1a2744);}
    .cd-bot-header span{font-size:11px;color:#6b7280;}
    .cd-bot-close{background:none;border:none;cursor:pointer;font-size:20px;color:#9ca3af;line-height:1;padding:0;}
    .cd-bot-messages{flex:1;overflow-y:auto;padding:14px 16px;display:flex;flex-direction:column;gap:10px;}
    .cd-bot-turn{display:flex;flex-direction:column;gap:8px;max-width:92%;}
    .cd-bot-turn.user{align-self:flex-end;align-items:flex-end;}
    .cd-bot-turn.bot{align-self:flex-start;align-items:flex-start;}
    .cd-bot-msg{padding:10px 13px;border-radius:10px;font-size:13px;line-height:1.55;}
    .cd-bot-turn.bot .cd-bot-msg{background:#f0f6ff;color:var(--wp--preset--color--contrast,#1a2744);border-bottom-left-radius:3px;}
    .cd-bot-turn.user .cd-bot-msg{background:var(--wp--preset--color--primary,#1e3a5f);color:#fff;border-bottom-right-radius:3px;}
    .cd-bot-doc-card{display:flex;gap:10px;align-items:center;background:#fff;border:1.5px solid #d0dce8;border-radius:10px;padding:10px 12px;cursor:pointer;transition:box-shadow .15s,border-color .15s;width:100%;box-sizing:border-box;}
    .cd-bot-doc-card:hover{box-shadow:0 3px 12px rgba(0,0,0,.1);border-color:var(--wp--preset--color--secondary,#2c4a7c);}
    .cd-bot-doc-info{flex:1;min-width:0;}
    .cd-bot-doc-title{font-size:12px;font-weight:600;color:var(--wp--preset--color--contrast,#1a2744);margin-bottom:5px;line-height:1.4;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;line-clamp:2;-webkit-box-orient:vertical;}
    .cd-bot-thinking{font-size:12px;color:#9ca3af;padding:4px 2px;display:flex;align-items:center;gap:6px;align-self:flex-start;}
    .cd-bot-input-wrap{display:flex;gap:8px;padding:12px 14px;border-top:1px solid #e5e9ef;flex-shrink:0;}
    .cd-bot-input{flex:1;height:38px;padding:0 12px;border:1.5px solid #c8d0dc;border-radius:8px;font-size:13px;outline:none;}
    .cd-bot-input:focus{border-color:var(--wp--preset--color--secondary,#2c4a7c);}
    .cd-bot-send{height:38px;padding:0 14px;background:var(--wp--preset--color--primary,#1e3a5f);color:#fff;border:none;border-radius:var(--wp--custom--button-border-radius,8px);font-size:13px;cursor:pointer;}
    .cd-bot-send:disabled{opacity:.5;cursor:default;}
    @media(max-width:600px){.cd-fs-controls{flex-wrap:wrap;}.cd-fs-keyword-wrap{flex:none;width:100%;}.cd-fs-select-wrap{flex:1;min-width:calc(50% - 5px);}.cd-fs-search-btn{width:100%;justify-content:center;}.cd-fs-card{padding:24px 18px;}.cd-bot-panel{width:calc(100vw - 40px);}}
    </style>

    <div class="cd-fs-wrap" id="<?php echo esc_attr( $uid ); ?>">
        <div class="cd-fs-card">
            <h2 class="cd-fs-title"><?php esc_html_e( 'Find what applies to you' ); ?></h2>
            <p class="cd-fs-subtitle">
                <span class="cd-fs-subtitle-badge">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <?php esc_html_e( 'AI-powered' ); ?>
                </span>
                <?php esc_html_e( 'Ask in any language and the AI will point you to what answers it.' ); ?>
            </p>

            <!-- Single-row controls: keyword + audience + type + search -->
            <div class="cd-fs-controls">
                <div class="cd-fs-keyword-wrap">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" class="cd-fs-keyword" placeholder="<?php esc_attr_e( 'e.g. reduced credit for undergraduate degree…' ); ?>" value="<?php echo esc_attr( sanitize_text_field( $_GET['q'] ?? '' ) ); ?>">
                    <button type="button" class="cd-fs-kw-clear" aria-label="<?php esc_attr_e( 'Clear' ); ?>">&times;</button>
                </div>
                <div class="cd-fs-select-wrap">
                    <select class="cd-fs-audience">
                        <option value=""><?php esc_html_e( 'Any Audience' ); ?></option>
                        <?php foreach ( $audiences as $a ) : ?>
                        <option value="<?php echo esc_attr( $a ); ?>" <?php selected( $a, $matched_audience ); ?>><?php echo esc_html( $a ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="cd-fs-select-wrap">
                    <select class="cd-fs-type">
                        <option value=""><?php esc_html_e( 'Any Type' ); ?></option>
                        <?php foreach ( $types as $t ) : ?>
                        <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $t, $matched_type ); ?>><?php echo esc_html( $t ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="cd-fs-search-btn">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <?php esc_html_e( 'Search' ); ?>
                </button>
            </div>

            <div class="cd-fs-ai-explain"></div>
            <div class="cd-fs-results"></div>
        </div>
    </div>

    <!-- Document detail modal -->
    <div class="cd-doc-modal-overlay" id="cd-doc-modal-overlay-<?php echo esc_attr( $uid ); ?>" role="dialog" aria-modal="true">
        <div class="cd-doc-modal">
            <div class="cd-doc-modal-header">
                <div class="cd-doc-modal-title-wrap">
                    <p class="cd-doc-modal-title" id="cd-doc-modal-title-<?php echo esc_attr( $uid ); ?>"></p>
                    <div class="cd-doc-modal-tags" id="cd-doc-modal-tags-<?php echo esc_attr( $uid ); ?>"></div>
                </div>
                <button class="cd-doc-modal-close" id="cd-doc-modal-close-<?php echo esc_attr( $uid ); ?>" aria-label="Close">&times;</button>
            </div>
            <!-- The content itself: extracted fields, then the structured body.
                 There is no second pane beside it — the original file is not part
                 of what this repository offers a reader. -->
            <div class="cd-doc-modal-pane active" id="cd-doc-modal-pane-content-<?php echo esc_attr( $uid ); ?>">
                <div class="cd-doc-modal-body" id="cd-doc-modal-body-<?php echo esc_attr( $uid ); ?>"></div>
            </div>
            <!-- Ask AI: fixed bar spanning the whole entry, not a tab -->
            <div class="cd-doc-ask" id="cd-doc-ask-<?php echo esc_attr( $uid ); ?>">
                <div class="cd-doc-ask-answers" id="cd-doc-chat-msgs-<?php echo esc_attr( $uid ); ?>"></div>
                <div class="cd-doc-ask-bar">
                    <svg class="cd-doc-ask-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <input type="text" class="cd-doc-ask-input" id="cd-doc-chat-input-<?php echo esc_attr( $uid ); ?>" placeholder="<?php esc_attr_e( 'Ask AI anything about this document…' ); ?>">
                    <button class="cd-doc-ask-send" id="cd-doc-chat-send-<?php echo esc_attr( $uid ); ?>"><?php esc_html_e( 'Send' ); ?></button>
                    <button class="cd-doc-ask-collapse" id="cd-doc-ask-collapse-<?php echo esc_attr( $uid ); ?>" type="button" aria-label="<?php esc_attr_e( 'Hide answers' ); ?>" style="display:none;">&times;</button>
                </div>
            </div>
            <div class="cd-doc-modal-footer" id="cd-doc-modal-footer-<?php echo esc_attr( $uid ); ?>">
                <div class="cd-doc-modal-footer-right">
                    <a href="#" class="cd-doc-modal-permalink" id="cd-doc-modal-permalink-<?php echo esc_attr( $uid ); ?>" style="display:none;"><?php esc_html_e( 'View document' ); ?></a>
                    <button class="cd-doc-modal-cancel" id="cd-doc-modal-cancel-<?php echo esc_attr( $uid ); ?>"><?php esc_html_e( 'Close' ); ?></button>
                </div>
            </div>
        </div>
    </div>

    <?php if ( $show_chat ) : ?>
    <button class="cd-bot-toggle" id="cd-bot-toggle-<?php echo esc_attr( $uid ); ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        <?php esc_html_e( 'Ask AI' ); ?>
    </button>
    <div class="cd-bot-panel" id="cd-bot-panel-<?php echo esc_attr( $uid ); ?>">
        <div class="cd-bot-header">
            <div class="cd-bot-header-info">
                <strong><?php esc_html_e( 'Document Advisor' ); ?></strong>
                <span><?php esc_html_e( 'Powered by AI · any language' ); ?></span>
            </div>
            <button class="cd-bot-close" id="cd-bot-close-<?php echo esc_attr( $uid ); ?>">&times;</button>
        </div>
        <div class="cd-bot-messages" id="cd-bot-messages-<?php echo esc_attr( $uid ); ?>">
            <div class="cd-bot-msg bot"><?php esc_html_e( 'Hi! Describe what you\'re looking for and I\'ll recommend the most relevant document.' ); ?></div>
        </div>
        <div class="cd-bot-input-wrap">
            <input type="text" class="cd-bot-input" id="cd-bot-input-<?php echo esc_attr( $uid ); ?>" placeholder="<?php esc_attr_e( 'e.g. How do I apply for reduced tuition credit?' ); ?>">
            <button class="cd-bot-send" id="cd-bot-send-<?php echo esc_attr( $uid ); ?>"><?php esc_html_e( 'Send' ); ?></button>
        </div>
    </div>
    <?php endif; ?>

    <?php
    return ob_get_clean();
}

// ──────────────────────────────────────────────
// 8b. Single document shortcode
// ──────────────────────────────────────────────
// Embeds one document's own content — the same rendering
// aidocs_render_single_document() gives the dedicated /documents/{entry}/ page
// — anywhere a shortcode can run, for the entries an editor wants to show
// inline instead of only through search or a direct link.
add_shortcode( 'aidocs_document', 'aidocs_document_shortcode' );
function aidocs_document_shortcode( $atts ) {
    $atts = shortcode_atts( [
        'id'   => '',
        'slug' => '',
    ], $atts );

    $post = null;
    if ( $atts['id'] !== '' ) {
        $post = get_post( absint( $atts['id'] ) );
    } elseif ( $atts['slug'] !== '' ) {
        $post = get_page_by_path( sanitize_title( $atts['slug'] ), OBJECT, 'aidoc' );
    }

    if ( ! $post || $post->post_type !== 'aidoc' || $post->post_status !== 'publish' ) {
        return current_user_can( 'edit_posts' )
            ? '<p>' . esc_html__( '[aidocs_document] could not find a published document for this id/slug.' ) . '</p>'
            : '';
    }

    return aidocs_render_single_document( $post->ID, false );
}

// ──────────────────────────────────────────────
// 9. Single document view
// ──────────────────────────────────────────────
// Documents have no post_content of their own, so the singular view is composed
// from the same pieces the modal shows: header, extracted fields, structured
// body, and the persistent Ask AI bar. Rendered by templates/single-aidoc.php
// (served via template_include, see aidocs_document_template_include()) rather
// than through the theme's own singular template, so the page stays a simple
// header/content/footer shell instead of whatever sidebar, related-posts or
// comments markup the active theme's single-post layout happens to add.
function aidocs_render_single_document( $pid, $standalone = true ) {
    $pub_date  = get_post_meta( $pid, '_document_pub_date', true );
    $desc      = get_post_meta( $pid, '_document_description', true );
    $audience  = wp_get_post_terms( $pid, 'document_audience', [ 'fields' => 'names' ] );
    $types     = wp_get_post_terms( $pid, 'document_type',     [ 'fields' => 'names' ] );
    $audience  = is_wp_error( $audience ) ? [] : $audience;
    $types     = is_wp_error( $types ) ? [] : $types;
    $blocks    = aidocs_get_content_blocks( $pid );

    ob_start();
    aidocs_single_view_styles();
    ?>
    <article class="aidocs-single">

        <?php if ( $standalone ) : ?>
        <a href="<?php echo esc_url( home_url( '/' . aidocs_get_archive_slug() . '/' ) ); ?>" class="aidocs-single-back">
            &larr; <?php esc_html_e( 'Back to all topics' ); ?>
        </a>
        <?php endif; ?>

        <header class="aidocs-single-header">
            <h1 class="aidocs-single-title"><?php echo esc_html( get_the_title( $pid ) ); ?></h1>
            <div class="aidocs-single-heading">
                <div class="aidocs-single-tags">
                    <?php foreach ( $audience as $a ) : ?>
                        <span class="cd-fs-doc-tag audience"><?php echo esc_html( $a ); ?></span>
                    <?php endforeach; ?>
                    <?php foreach ( $types as $t ) : ?>
                        <span class="cd-fs-doc-tag type"><?php echo esc_html( $t ); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </header>

        <?php if ( $desc ) : ?>
        <div class="aidocs-single-desc"><?php echo esc_html( $desc ); ?></div>
        <?php endif; ?>

        <?php if ( $audience || $types || $pub_date ) : ?>
        <div class="aidocs-single-grid">
            <?php if ( $audience ) : ?>
            <div><div class="aidocs-single-label"><?php esc_html_e( 'Audience' ); ?></div>
                 <div class="aidocs-single-value"><?php echo esc_html( implode( ', ', $audience ) ); ?></div></div>
            <?php endif; ?>
            <?php if ( $types ) : ?>
            <div><div class="aidocs-single-label"><?php esc_html_e( 'Document Type' ); ?></div>
                 <div class="aidocs-single-value"><?php echo esc_html( implode( ', ', $types ) ); ?></div></div>
            <?php endif; ?>
            <?php if ( $pub_date ) : ?>
            <div><div class="aidocs-single-label"><?php esc_html_e( 'Last Updated' ); ?></div>
                 <div class="aidocs-single-value"><?php echo esc_html( mysql2date( get_option( 'date_format' ), $pub_date ) ); ?></div></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="aidocs-section-label"><?php esc_html_e( 'Content' ); ?></div>
        <?php if ( $blocks ) : ?>
            <?php echo aidocs_render_toc( $blocks ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in renderer ?>
            <?php echo aidocs_render_content_blocks( $blocks ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in renderer ?>
        <?php else : ?>
            <p class="aidocs-content-empty"><?php esc_html_e( 'No content has been extracted for this entry yet.' ); ?></p>
        <?php endif; ?>
        <?php echo aidocs_render_document_history( $pid ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in renderer ?>

        <?php if ( get_option( 'aidocs_gemini_api_key', '' ) ) : ?>
        <div class="aidocs-single-ask" id="aidocs-single-ask">
            <div class="aidocs-single-ask-answers" id="aidocs-single-ask-answers"></div>
            <div class="aidocs-single-ask-bar">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="aidocs-single-ask-icon"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                <input type="text" id="aidocs-single-ask-input" placeholder="<?php esc_attr_e( 'Ask AI anything about this document…' ); ?>">
                <button type="button" id="aidocs-single-ask-send"><?php esc_html_e( 'Send' ); ?></button>
            </div>
        </div>
        <script>
        (function(){
            var docId=<?php echo (int) $pid; ?>;
            var ajaxUrl=<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var nonce=<?php echo wp_json_encode( wp_create_nonce( 'aidocs_ai_search' ) ); ?>;
            var strings=<?php echo wp_json_encode( [
                'send'  => __( 'Send' ),
                'error' => __( 'Sorry, I encountered an error. Please try again.' ),
                'conn'  => __( 'Connection error. Please try again.' ),
            ] ); ?>;
            var answers=document.getElementById('aidocs-single-ask-answers');
            var input=document.getElementById('aidocs-single-ask-input');
            var send=document.getElementById('aidocs-single-ask-send');
            var history=[];

            function addTurn(role,text){
                var turn=document.createElement('div');
                turn.className='aidocs-ask-turn '+role;
                var msg=document.createElement('div');
                msg.className='aidocs-ask-msg';
                msg.textContent=text;
                turn.appendChild(msg);
                answers.appendChild(turn);
                answers.classList.add('open');
                answers.scrollTop=answers.scrollHeight;
                return turn;
            }

            function ask(){
                var msg=input.value.trim();
                if(!msg)return;
                addTurn('user',msg);
                input.value='';
                send.disabled=true;send.textContent='…';
                history.push({role:'user',text:msg});
                var thinking=addTurn('bot','…');
                var body=new URLSearchParams({
                    action:'aidocs_ai_doc_chat',nonce:nonce,doc_id:docId,
                    message:msg,history:JSON.stringify(history.slice(-6))
                });
                fetch(ajaxUrl,{method:'POST',body:body,credentials:'same-origin'})
                    .then(function(r){return r.json();})
                    .then(function(res){
                        thinking.remove();
                        var reply=(res&&res.success)?res.data.message:strings.error;
                        addTurn('bot',reply);
                        if(res&&res.success)history.push({role:'model',text:reply});
                    })
                    .catch(function(){thinking.remove();addTurn('bot',strings.conn);})
                    .finally(function(){send.disabled=false;send.textContent=strings.send;});
            }

            send.addEventListener('click',ask);
            input.addEventListener('keydown',function(e){if(e.key==='Enter')ask();});
        })();
        </script>
        <?php endif; ?>
        <?php if ( $blocks ) : ?>
        <script>
        (function(){
            // A section is a closed <details> by default, so jumping to one of
            // its headings by id — from the table of contents above, or from an
            // externally shared #anchor link — has to open it first, or the
            // browser scrolls to a heading with nothing visible under it.
            function openTarget(){
                var hash = window.location.hash;
                if(!hash || hash.length<2) return;
                var el;
                try { el = document.querySelector(hash); } catch(e){ return; }
                if(!el) return;
                var item = el.closest ? el.closest('.aidocs-accordion-item') : null;
                if(item && !item.open){
                    item.open = true;
                    setTimeout(function(){ el.scrollIntoView({block:'start'}); }, 0);
                }
            }
            window.addEventListener('hashchange', openTarget);
            openTarget();
        })();
        </script>
        <?php endif; ?>
    </article>
    <?php
    return ob_get_clean();
}

/**
 * A table of contents linking to each collapsible section of the content,
 * built from the same grouping aidocs_render_sections() uses internally
 * (aidocs_group_sections() in includes/aidocs-doc-parser.php). Skipped when
 * there is nothing to navigate between.
 */
function aidocs_render_toc( array $blocks ) {
    $sections = array_values( array_filter(
        aidocs_group_sections( $blocks ),
        function ( $section ) {
            return $section['heading'] && ! empty( $section['heading']['id'] ) && $section['blocks'];
        }
    ) );

    if ( count( $sections ) < 2 ) return '';

    $html = '<nav class="aidocs-toc" aria-label="' . esc_attr__( 'Sections' ) . '">'
          . '<div class="aidocs-toc-label">' . esc_html__( 'In this document' ) . '</div><ul>';
    foreach ( $sections as $section ) {
        $heading = $section['heading'];
        $html   .= '<li><a href="#' . esc_attr( $heading['id'] ) . '">' . aidocs_render_runs( $heading ) . '</a></li>';
    }
    return $html . '</ul></nav>';
}

/**
 * Styles for the singular document view. Printed inline once per request.
 */
function aidocs_single_view_styles() {
    static $printed = false;
    if ( $printed ) return;
    $printed = true;
    ?>
    <style>
    .aidocs-single-page{max-width:820px;margin:0 auto;padding:40px 20px;}
    .aidocs-single-title{font-size:28px;font-weight:700;margin:0 0 20px;}
    .aidocs-single{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:820px;margin:0 auto;padding-bottom:90px;}
    .aidocs-single-back{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:var(--wp--preset--color--secondary,#2c4a7c);text-decoration:none;margin-bottom:18px;}
    .aidocs-single-back:hover{text-decoration:underline;}
    .aidocs-single-header{display:flex;align-items:flex-start;gap:18px;padding-bottom:18px;border-bottom:1px solid #edf0f4;margin-bottom:20px;}
    .aidocs-single-heading{flex:1;min-width:0;display:flex;align-items:center;}
    .aidocs-single-tags{display:flex;flex-wrap:wrap;gap:5px;}
    .cd-fs-doc-tag{font-size:11px;padding:3px 9px;border-radius:20px;font-weight:600;display:inline-flex;align-items:center;gap:4px;}
    .cd-fs-doc-tag.type{background:#e8f0fb;color:var(--wp--preset--color--secondary,#2c4a7c);}
    .cd-fs-doc-tag.audience{background:#f0faf4;color:#1e6e45;}
    .aidocs-single-desc{font-size:15px;color:#374151;line-height:1.75;margin-bottom:22px;}
    .aidocs-single-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;padding-bottom:4px;}
    .aidocs-single-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#b0b8c8;margin-bottom:5px;}
    .aidocs-single-value{font-size:14px;color:var(--wp--preset--color--contrast,#1a2744);font-weight:500;line-height:1.5;}
    .aidocs-section-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#b0b8c8;margin:26px 0 12px;padding-top:18px;border-top:1px solid #f0f2f5;}
    .aidocs-content-h2{font-size:18px;font-weight:700;color:var(--wp--preset--color--contrast,#1a2744);margin:28px 0 10px;line-height:1.35;}
    .aidocs-content-h2:first-child{margin-top:0;}
    .aidocs-content-h3{font-size:14px;font-weight:700;color:var(--wp--preset--color--secondary,#2c4a7c);margin:22px 0 8px;text-transform:uppercase;letter-spacing:.4px;}
    .aidocs-content-h3:first-child{margin-top:0;}
    .aidocs-content-p{font-size:15px;color:#374151;line-height:1.8;margin:0 0 14px;}
    .aidocs-content-list{margin:0 0 16px;padding-left:24px;}
    .aidocs-content-list li{font-size:15px;color:#374151;line-height:1.75;margin-bottom:8px;}
    .aidocs-content-empty{font-size:14px;color:#9ca3af;font-style:italic;}
    .aidocs-toc{margin:0 0 22px;padding:14px 16px;background:#f8f9fb;border:1px solid #edf0f4;border-radius:8px;}
    .aidocs-toc-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:#b0b8c8;margin-bottom:8px;}
    .aidocs-toc ul{list-style:none;margin:0;padding:0;columns:2;column-gap:24px;}
    .aidocs-toc li{margin-bottom:6px;break-inside:avoid;}
    .aidocs-toc a{font-size:13px;color:var(--wp--preset--color--secondary,#2c4a7c);text-decoration:none;font-weight:500;}
    .aidocs-toc a:hover{text-decoration:underline;}
    <?php echo aidocs_content_block_css(); // phpcs:ignore WordPress.Security.EscapeOutput -- static CSS ?>
    .aidocs-doc-history{margin-top:26px;padding:14px 16px;background:#f8f9fb;border-left:3px solid #d0dce8;border-radius:0 6px 6px 0;font-size:13px;color:#6b7280;line-height:1.7;}
    .aidocs-doc-history-label{display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:#b0b8c8;margin-bottom:5px;}
    /* Ask AI — pinned to the bottom of the viewport while reading */
    .aidocs-single-ask{position:sticky;bottom:0;z-index:50;margin-top:32px;background:#fbfcfd;border:1px solid #e5e9ef;border-radius:12px;box-shadow:0 -2px 16px rgba(0,0,0,.06);overflow:hidden;}
    .aidocs-single-ask-answers{display:none;max-height:260px;overflow-y:auto;padding:14px 18px;flex-direction:column;gap:10px;background:#fff;border-bottom:1px solid #edf0f4;}
    .aidocs-single-ask-answers.open{display:flex;}
    .aidocs-ask-turn{display:flex;flex-direction:column;max-width:92%;}
    .aidocs-ask-turn.user{align-self:flex-end;align-items:flex-end;}
    .aidocs-ask-turn.bot{align-self:flex-start;align-items:flex-start;}
    .aidocs-ask-msg{padding:10px 13px;border-radius:10px;font-size:13px;line-height:1.6;}
    .aidocs-ask-turn.bot .aidocs-ask-msg{background:#f0f6ff;color:var(--wp--preset--color--contrast,#1a2744);border-bottom-left-radius:3px;}
    .aidocs-ask-turn.user .aidocs-ask-msg{background:var(--wp--preset--color--primary,#1e3a5f);color:#fff;border-bottom-right-radius:3px;}
    .aidocs-single-ask-bar{display:flex;align-items:center;gap:10px;padding:11px 16px;}
    .aidocs-single-ask-icon{color:var(--wp--preset--color--secondary,#2c4a7c);flex-shrink:0;}
    #aidocs-single-ask-input{flex:1;height:40px;padding:0 14px;border:1.5px solid #c8d0dc;border-radius:8px;font-size:13px;outline:none;background:#fff;color:var(--wp--preset--color--contrast,#1a2744);}
    #aidocs-single-ask-input:focus{border-color:var(--wp--preset--color--secondary,#2c4a7c);}
    #aidocs-single-ask-send{height:40px;padding:0 18px;background:var(--wp--preset--color--primary,#1e3a5f);color:#fff;border:none;border-radius:var(--wp--custom--button-border-radius,8px);font-size:13px;font-weight:600;cursor:pointer;flex-shrink:0;}
    #aidocs-single-ask-send:hover{background:var(--wp--preset--color--secondary,#2c4a7c);}
    #aidocs-single-ask-send:disabled{opacity:.5;cursor:default;}
    @media(max-width:600px){.aidocs-single-grid{grid-template-columns:1fr;}.aidocs-single-header{flex-wrap:wrap;}}
    </style>
    <?php
}

// ── AJAX: Search documents ────────────────────
add_action( 'wp_ajax_aidocs_search',        'aidocs_search_ajax' );
add_action( 'wp_ajax_nopriv_aidocs_search', 'aidocs_search_ajax' );
function aidocs_search_ajax() {
    check_ajax_referer( 'aidocs_search', 'nonce' );

    $keyword  = sanitize_text_field( $_POST['keyword']  ?? '' );
    $audience = sanitize_text_field( $_POST['audience'] ?? '' );
    $type     = sanitize_text_field( $_POST['type']     ?? '' );
    $page     = max( 1, absint( $_POST['page']     ?? 1 ) );
    $per_page = max( 1, min( 50, absint( $_POST['per_page'] ?? 10 ) ) );

    $args = [
        'post_type'      => 'aidoc',
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];

    if ( $keyword ) {
        // WP_Query's native 's' only matches post_title/post_excerpt/post_content,
        // but a document's extracted body text lives in postmeta (_document_content),
        // not post_content (see aidocs_render_single_document above). Without this,
        // a phrase copied straight out of a document's body never matches here —
        // only the AI/semantic search sees it, since that indexes the same meta.
        // So: match on title OR that extracted text, exact-substring, no AI needed.
        global $wpdb;
        $title_ids = get_posts( [
            'post_type'      => 'aidoc',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            's'              => $keyword,
            'fields'         => 'ids',
        ] );

        $like = '%' . $wpdb->esc_like( $keyword ) . '%';
        $content_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type = 'aidoc' AND p.post_status = 'publish'
             AND pm.meta_key IN ('_document_content','_document_description','_document_summary')
             AND pm.meta_value LIKE %s",
            $like
        ) );

        $matched_ids = array_unique( array_map( 'intval', array_merge( $title_ids, $content_ids ) ) );

        // Explicit empty match must still yield zero results, not "no filter".
        $args['post__in'] = $matched_ids ?: [ 0 ];
    }

    $tax_query = [];
    if ( $audience ) {
        $tax_query[] = [ 'taxonomy' => 'document_audience', 'field' => 'name', 'terms' => [ $audience ] ];
    }
    if ( $type ) {
        $tax_query[] = [ 'taxonomy' => 'document_type', 'field' => 'name', 'terms' => [ $type ] ];
    }
    if ( $tax_query ) {
        $args['tax_query'] = array_merge( [ 'relation' => 'AND' ], $tax_query );
    }

    $query   = new WP_Query( $args );
    $results = [];

    while ( $query->have_posts() ) {
        $query->the_post();
        $pid = get_the_ID();

        // The source file the content was read from is deliberately not here:
        // what this repository publishes is the information, not the file it
        // arrived in, so nothing downstream is given a way to link to one.
        $results[] = [
            'id'          => $pid,
            'title'       => get_the_title(),
            'description' => get_post_meta( $pid, '_document_description', true ),
            'permalink'   => get_permalink( $pid ),
            'pub_date'    => get_post_meta( $pid, '_document_pub_date', true ),
            'audience'    => wp_get_post_terms( $pid, 'document_audience', [ 'fields' => 'names' ] ),
            'type'        => wp_get_post_terms( $pid, 'document_type',     [ 'fields' => 'names' ] ),
            'snippet'     => $keyword ? aidocs_search_snippet( $pid, $keyword ) : '',
        ];
    }
    wp_reset_postdata();

    wp_send_json_success( [
        'results'     => $results,
        'total'       => $query->found_posts,
        'total_pages' => (int) $query->max_num_pages,
        'page'        => $page,
    ] );
}

// ── AJAX: AI Explain search results ──────────
add_action( 'wp_ajax_aidocs_ai_explain',        'aidocs_ai_explain_ajax' );
add_action( 'wp_ajax_nopriv_aidocs_ai_explain', 'aidocs_ai_explain_ajax' );
function aidocs_ai_explain_ajax() {
    check_ajax_referer( 'aidocs_ai_search', 'nonce' );

    $keyword  = sanitize_text_field( $_POST['keyword']  ?? '' );
    $audience = sanitize_text_field( $_POST['audience'] ?? '' );
    $type     = sanitize_text_field( $_POST['type']     ?? '' );
    $titles   = json_decode( stripslashes( $_POST['titles'] ?? '[]' ), true );
    if ( ! is_array( $titles ) ) $titles = [];
    $titles = array_slice( array_map( 'sanitize_text_field', $titles ), 0, 8 );

    if ( ! $titles ) wp_send_json_error( 'No results.' );

    $api_key = get_option( 'aidocs_gemini_api_key', '' );
    $model   = get_option( 'aidocs_gemini_model', 'gemini-2.5-flash' );
    if ( ! $api_key ) wp_send_json_error( 'AI not configured.' );

    $query_parts = [];
    if ( $keyword )  $query_parts[] = 'keyword: "' . $keyword . '"';
    if ( $audience ) $query_parts[] = 'audience: "' . $audience . '"';
    if ( $type )     $query_parts[] = 'type: "' . $type . '"';
    $query_desc = $query_parts ? implode( ', ', $query_parts ) : 'no specific filters';

    $titles_list = implode( "\n- ", $titles );
    $prompt  = "A user searched a document library with {$query_desc}. ";
    $prompt .= "The following documents were returned:\n- {$titles_list}\n\n";
    $prompt .= "Write 1-2 sentences in the same language the search query implies (default to the site language) explaining why these results are relevant to the user's search. ";
    $prompt .= "Be concise and helpful. Do not use markdown.";

    $response = wp_remote_post(
        'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode( $model ) . ':generateContent?key=' . urlencode( $api_key ),
        [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'contents'         => [ [ 'role' => 'user', 'parts' => [ [ 'text' => $prompt ] ] ] ],
                'generationConfig' => [ 'temperature' => 0.4, 'maxOutputTokens' => 120 ],
            ] ),
            'timeout' => 20,
        ]
    );

    if ( is_wp_error( $response ) ) wp_send_json_error( $response->get_error_message() );

    $code = (int) wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 ) wp_send_json_error( $body['error']['message'] ?? 'API error ' . $code );

    $text = trim( $body['candidates'][0]['content']['parts'][0]['text'] ?? '' );

    wp_send_json_success( [ 'explanation' => $text ] );
}

// ── AJAX: AI Document Recommendation ─────────
add_action( 'wp_ajax_aidocs_ai_recommend',        'aidocs_ai_recommend_ajax' );
add_action( 'wp_ajax_nopriv_aidocs_ai_recommend', 'aidocs_ai_recommend_ajax' );
function aidocs_ai_recommend_ajax() {
    check_ajax_referer( 'aidocs_ai_search', 'nonce' );

    $message = sanitize_textarea_field( $_POST['message'] ?? '' );
    $history = json_decode( stripslashes( $_POST['history'] ?? '[]' ), true );

    if ( ! $message ) wp_send_json_error( 'Empty message.' );

    $api_key = get_option( 'aidocs_gemini_api_key', '' );
    $model   = get_option( 'aidocs_gemini_model', 'gemini-2.5-flash' );
    if ( ! $api_key ) wp_send_json_error( 'AI not configured.' );

    // Semantic search: embed query → cosine similarity → top candidates
    $query_embedding = aidocs_gemini_embed( $message, $api_key );

    $all_ids = get_posts( [
        'post_type'      => 'aidoc',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ] );

    $candidate_ids = [];

    if ( $query_embedding && ! empty( $all_ids ) ) {
        $scored        = [];
        $unindexed_ids = [];

        foreach ( $all_ids as $pid ) {
            $emb_raw = get_post_meta( (int) $pid, '_document_embedding', true );
            if ( $emb_raw ) {
                $doc_emb = json_decode( $emb_raw, true );
                if ( is_array( $doc_emb ) ) {
                    $scored[] = [
                        'pid'   => (int) $pid,
                        'score' => aidocs_cosine_similarity( $query_embedding, $doc_emb ),
                    ];
                } else {
                    $unindexed_ids[] = (int) $pid;
                }
            } else {
                $unindexed_ids[] = (int) $pid;
            }
        }

        usort( $scored, function ( $a, $b ) {
            return $b['score'] <=> $a['score'];
        } );

        $candidate_ids = array_column( array_slice( $scored, 0, 12 ), 'pid' );

        // Supplement with unindexed docs: first try keyword match, then pad with all unindexed
        if ( count( $candidate_ids ) < 6 && ! empty( $unindexed_ids ) ) {
            $kw_q = new WP_Query( [
                'post_type'      => 'aidoc',
                'post_status'    => 'publish',
                'posts_per_page' => 10,
                'post__in'       => $unindexed_ids,
                's'              => $message,
                'fields'         => 'ids',
            ] );
            $candidate_ids = array_unique( array_merge( $candidate_ids, array_map( 'intval', $kw_q->posts ) ) );

            // Still few results? pad with all unindexed (Gemini will pick the best)
            if ( count( $candidate_ids ) < 6 ) {
                $remaining     = array_diff( $unindexed_ids, $candidate_ids );
                $candidate_ids = array_merge( $candidate_ids, array_slice( $remaining, 0, 40 ) );
            }
            $candidate_ids = array_slice( $candidate_ids, 0, 40 );
        }
    } else {
        // Embedding unavailable — keyword search then fall back to ALL docs
        $kw_q = new WP_Query( [
            'post_type'      => 'aidoc',
            'post_status'    => 'publish',
            'posts_per_page' => 40,
            's'              => $message,
            'fields'         => 'ids',
        ] );
        $candidate_ids = array_map( 'intval', $kw_q->posts );

        // Keyword found nothing → send all docs so Gemini can reason semantically
        if ( empty( $candidate_ids ) ) {
            $candidate_ids = array_slice( array_map( 'intval', $all_ids ), 0, 40 );
        }
    }

    // Build candidate data array
    // Scale each excerpt down when the candidate list is long — the
    // embedding-unavailable fallback can pad this out to 40 docs, where a
    // 3000-char excerpt each would balloon the prompt for no real benefit.
    $excerpt_len = count( $candidate_ids ) > 15 ? 300 : 3000;
    $candidates  = [];
    foreach ( $candidate_ids as $pid ) {
        $pid          = (int) $pid;
        $candidates[] = [
            'id'          => $pid,
            'title'       => get_the_title( $pid ),
            'description' => get_post_meta( $pid, '_document_description', true ),
            // The embedding that got this doc shortlisted is built from the full
            // extracted body text, but Gemini's final pick was only ever shown
            // title/type/audience/description — nothing from the actual content.
            // A short manually-written description is frequently empty, so Gemini
            // had no real text to confirm relevance against, and rejected docs
            // that merely used *different wording* than the query. Give it an
            // actual body excerpt to judge from.
            'excerpt'     => aidocs_candidate_excerpt( $pid, $excerpt_len ),
            'audience'    => wp_get_post_terms( $pid, 'document_audience', [ 'fields' => 'names' ] ),
            'type'        => wp_get_post_terms( $pid, 'document_type',     [ 'fields' => 'names' ] ),
            'pub_date'    => get_post_meta( $pid, '_document_pub_date', true ),
            'permalink'   => get_permalink( $pid ),
        ];
    }

    // Build catalog for Gemini
    $catalog = '';
    foreach ( $candidates as $i => $doc ) {
        $line  = ( $i + 1 ) . ". [ID:{$doc['id']}] {$doc['title']}";
        if ( $doc['type'] )        $line .= ' | ' . implode( ', ', (array) $doc['type'] );
        if ( $doc['audience'] )    $line .= ' | Audience: ' . implode( ', ', (array) $doc['audience'] );
        if ( $doc['description'] ) $line .= ' | ' . mb_substr( $doc['description'], 0, 160 );
        if ( $doc['excerpt'] )     $line .= ' | Excerpt: ' . $doc['excerpt'];
        $catalog .= $line . "\n";
    }

    $site   = get_bloginfo( 'name' );
    $system = "You are a document recommendation assistant for {$site}. ";
    $system .= 'Your job is to understand the user\'s need and recommend the most relevant document(s) from the catalog. ';
    $system .= 'Each catalog entry\'s Excerpt is real text pulled from that document\'s body — judge relevance by whether it covers the same topic as the user\'s need, even if it uses different wording, synonyms, or phrasing than the query. Do not require an exact wording match. ';
    $system .= 'CRITICAL: Always respond in the EXACT SAME LANGUAGE the user used. ';
    $system .= 'Be conversational — briefly explain why the recommended document(s) answer their question. ';
    $system .= 'Return ONLY valid JSON (no markdown): {"message":"your friendly explanation","doc_ids":[array of integer IDs]}. ';
    $system .= 'Recommend 1–3 documents maximum. If nothing matches, return empty array and explain kindly.';

    $first_turn = $system . "\n\nDocument catalog:\n" . $catalog . "\n\nUser: " . $message;
    $contents   = [ [ 'role' => 'user', 'parts' => [ [ 'text' => $first_turn ] ] ] ];

    foreach ( (array) $history as $turn ) {
        if ( isset( $turn['role'], $turn['text'] ) && in_array( $turn['role'], [ 'user', 'model' ], true ) ) {
            $contents[] = [ 'role' => $turn['role'], 'parts' => [ [ 'text' => $turn['text'] ] ] ];
        }
    }

    $response = wp_remote_post(
        'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode( $model ) . ':generateContent?key=' . urlencode( $api_key ),
        [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'contents'         => $contents,
                // responseSchema (not just responseMimeType) is what stops the
                // model from ever emitting a raw, un-escaped quote inside a
                // string — expected here since the message routinely quotes a
                // document title or a phrase copied from its text — breaking
                // the whole reply's decode on a reply that was otherwise
                // complete and correct (see aidocs_restructure_response_schema
                // for the same fix applied to document extraction).
                'generationConfig' => [
                    'temperature'      => 0.5,
                    'responseMimeType' => 'application/json',
                    'responseSchema'   => aidocs_recommend_response_schema(),
                ],
            ] ),
            'timeout' => 30,
        ]
    );

    if ( is_wp_error( $response ) ) wp_send_json_error( $response->get_error_message() );

    $code = (int) wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( $code !== 200 ) wp_send_json_error( $body['error']['message'] ?? 'API error ' . $code );

    $text   = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $text   = preg_replace( '/^```(?:json)?\s*/m', '', trim( $text ) );
    $text   = preg_replace( '/\s*```\s*$/m', '', $text );
    $result = json_decode( trim( $text ), true );

    // Gemini occasionally wraps the JSON in stray text despite the JSON
    // response mode; re-try on just the outermost {...} before giving up,
    // so a friendly fallback shows instead of the raw JSON as the message.
    if ( ! is_array( $result ) || ! isset( $result['message'] ) ) {
        if ( preg_match( '/\{.*\}/s', $text, $m ) ) {
            $result = json_decode( $m[0], true );
        }
    }

    if ( ! is_array( $result ) || ! isset( $result['message'] ) ) {
        $result = [ 'message' => __( 'I couldn\'t process that. Please try again.' ), 'doc_ids' => [] ];
    }

    // Map doc_ids to full document data
    $id_map   = array_column( $candidates, null, 'id' );
    $rec_docs = [];
    foreach ( (array) ( $result['doc_ids'] ?? [] ) as $rid ) {
        $rid = (int) $rid;
        if ( isset( $id_map[ $rid ] ) ) {
            $rec_docs[] = $id_map[ $rid ];
        }
    }

    wp_send_json_success( [ 'message' => $result['message'], 'docs' => $rec_docs ] );
}

// ── AJAX: AI Document Chat ────────────────────
add_action( 'wp_ajax_aidocs_ai_doc_chat',        'aidocs_ai_doc_chat_ajax' );
add_action( 'wp_ajax_nopriv_aidocs_ai_doc_chat', 'aidocs_ai_doc_chat_ajax' );
function aidocs_ai_doc_chat_ajax() {
    check_ajax_referer( 'aidocs_ai_search', 'nonce' );

    $doc_id  = absint( $_POST['doc_id']  ?? 0 );
    $message = sanitize_textarea_field( $_POST['message'] ?? '' );
    $history = json_decode( stripslashes( $_POST['history'] ?? '[]' ), true );

    if ( ! $doc_id || ! $message ) wp_send_json_error( 'Invalid request.' );

    $api_key = get_option( 'aidocs_gemini_api_key', '' );
    $model   = get_option( 'aidocs_gemini_model', 'gemini-2.5-flash' );
    if ( ! $api_key ) wp_send_json_error( 'AI not configured.' );

    $post = get_post( $doc_id );
    if ( ! $post || $post->post_status !== 'publish' || $post->post_type !== 'aidoc' ) {
        wp_send_json_error( 'Document not found.' );
    }

    // Build document context
    $ctx  = 'Title: ' . $post->post_title . "\n";
    $desc = get_post_meta( $doc_id, '_document_description', true );
    if ( $desc ) $ctx .= "Description: {$desc}\n";

    $audiences = wp_get_post_terms( $doc_id, 'document_audience', [ 'fields' => 'names' ] );
    if ( $audiences && ! is_wp_error( $audiences ) ) $ctx .= 'Audience: ' . implode( ', ', $audiences ) . "\n";

    $types = wp_get_post_terms( $doc_id, 'document_type', [ 'fields' => 'names' ] );
    if ( $types && ! is_wp_error( $types ) ) $ctx .= 'Type: ' . implode( ', ', $types ) . "\n";

    $pub = get_post_meta( $doc_id, '_document_pub_date', true );
    if ( $pub ) $ctx .= "Last updated: {$pub}\n";

    $system  = "You are a helpful assistant answering questions about a specific document. ";
    $system .= "Answer ONLY based on the document context provided below. ";
    $system .= "CRITICAL: Always respond in the EXACT SAME LANGUAGE the user writes in. ";
    $system .= "Be concise and helpful. If the answer is not in the context, say so kindly.\n\n";
    $system .= "Document context:\n{$ctx}";

    $contents = [ [ 'role' => 'user', 'parts' => [ [ 'text' => $system . "\n\nUser: " . $message ] ] ] ];
    foreach ( (array) $history as $turn ) {
        if ( isset( $turn['role'], $turn['text'] ) && in_array( $turn['role'], [ 'user', 'model' ], true ) ) {
            $contents[] = [ 'role' => $turn['role'], 'parts' => [ [ 'text' => $turn['text'] ] ] ];
        }
    }

    $response = wp_remote_post(
        'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode( $model ) . ':generateContent?key=' . urlencode( $api_key ),
        [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'contents'         => $contents,
                'generationConfig' => [ 'temperature' => 0.4, 'maxOutputTokens' => 350 ],
            ] ),
            'timeout' => 25,
        ]
    );

    if ( is_wp_error( $response ) ) wp_send_json_error( $response->get_error_message() );
    $code = (int) wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( $code !== 200 ) wp_send_json_error( $body['error']['message'] ?? 'API error ' . $code );

    $text = trim( $body['candidates'][0]['content']['parts'][0]['text'] ?? '' );
    wp_send_json_success( [ 'message' => $text ] );
}

// ── AJAX: AI Search Assistant ─────────────────
add_action( 'wp_ajax_aidocs_ai_search',        'aidocs_ai_search_ajax' );
add_action( 'wp_ajax_nopriv_aidocs_ai_search', 'aidocs_ai_search_ajax' );
function aidocs_ai_search_ajax() {
    check_ajax_referer( 'aidocs_ai_search', 'nonce' );

    $message = sanitize_textarea_field( $_POST['message'] ?? '' );
    $history = json_decode( stripslashes( $_POST['history'] ?? '[]' ), true );

    if ( ! $message ) wp_send_json_error( 'Empty message.' );

    $api_key = get_option( 'aidocs_gemini_api_key', '' );
    $model   = get_option( 'aidocs_gemini_model', 'gemini-2.5-flash' );
    if ( ! $api_key ) wp_send_json_error( 'AI not configured.' );

    $types     = aidocs_get_types();
    $audiences = aidocs_get_audiences();
    $site      = get_bloginfo( 'name' );

    $system  = "You are a helpful document search assistant for {$site}. ";
    $system .= 'Available document types: ' . implode( ', ', $types ) . '. ';
    $system .= 'Available audiences: ' . implode( ', ', $audiences ) . '. ';
    $system .= 'Help users find documents by understanding their natural language requests. ';
    $system .= 'Respond ONLY with a valid JSON object with two keys: ';
    $system .= '"message" (your friendly, concise response in the same language the user wrote in) and ';
    $system .= '"filters" (object with keys: keyword (string), audience (string, must match one of available audiences or empty), type (string, must match one of available document types or empty)). ';
    $system .= 'Only suggest audience/type values from the available lists. No markdown, no fences.';

    $contents = [ [ 'role' => 'user', 'parts' => [ [ 'text' => $system . "\n\nUser: " . $message ] ] ] ];

    foreach ( (array) $history as $turn ) {
        if ( isset( $turn['role'], $turn['text'] ) && in_array( $turn['role'], [ 'user', 'model' ], true ) ) {
            $contents[] = [ 'role' => $turn['role'], 'parts' => [ [ 'text' => $turn['text'] ] ] ];
        }
    }

    $response = wp_remote_post(
        'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode( $model ) . ':generateContent?key=' . urlencode( $api_key ),
        [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'contents'         => $contents,
                'generationConfig' => [
                    'temperature'      => 0.4,
                    'responseMimeType' => 'application/json',
                    'responseSchema'   => aidocs_search_response_schema(),
                ],
            ] ),
            'timeout' => 30,
        ]
    );

    if ( is_wp_error( $response ) ) wp_send_json_error( $response->get_error_message() );

    $code = (int) wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 ) wp_send_json_error( $body['error']['message'] ?? 'API error ' . $code );

    $text   = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $text   = preg_replace( '/^```(?:json)?\s*/m', '', trim( $text ) );
    $text   = preg_replace( '/\s*```\s*$/m', '', $text );
    $result = json_decode( trim( $text ), true );

    if ( ! is_array( $result ) || ! isset( $result['message'] ) ) {
        $result = [
            'message' => trim( $text ) ?: __( 'I couldn\'t process that. Please try again.' ),
            'filters' => [ 'keyword' => '', 'audience' => '', 'type' => '' ],
        ];
    }

    if ( ! isset( $result['filters'] ) ) {
        $result['filters'] = [ 'keyword' => '', 'audience' => '', 'type' => '' ];
    }

    wp_send_json_success( $result );
}
