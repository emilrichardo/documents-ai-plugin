<?php
/**
 * Command-line check for the document parser.
 *
 * Runs includes/aidocs-doc-parser.php over text files outside WordPress, so the
 * extraction rules can be verified against a corpus of real documents:
 *
 *     php tools/parse-check.php document.txt              # block census
 *     php tools/parse-check.php --blocks document.txt      # the parsed tree
 *     php tools/parse-check.php --html document.txt        # rendered HTML
 *     php tools/parse-check.php corpus/*.txt               # one line per file
 *
 * The input is the canonical text written by assets/js/aidocs-pdf-structure.js
 * (copy it out of the "Page" preview in the editor), or any plain text.
 *
 * @package aidocs
 */

define( 'AIDOCS_PARSER_CLI', true );

// The parser escapes on output; outside WordPress these are the same functions.
if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
    function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}

// The link policy (aidocs_classify_link() and the renderer around it) reaches
// for these three. Only esc_url() needs to actually do something: it is the
// last gate on an href, and a stub that waved everything through would make
// this tool disagree with the site about what a document renders as.
if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) {
        return $component === -1 ? parse_url( $url ) : parse_url( $url, $component );
    }
}
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $hook, $value, ...$args ) { return $value; }
}
if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( $url ) {
        $url = preg_replace( '/[\r\n\t\x00]+/', '', trim( (string) $url ) );
        if ( $url === '' ) return '';
        if ( $url[0] === '#' || $url[0] === '/' ) return $url;
        if ( ! preg_match( '#^([a-z][a-z0-9+.\-]*):#i', $url, $m ) ) return $url;
        return in_array( strtolower( $m[1] ), [ 'http', 'https', 'mailto', 'tel' ], true ) ? $url : '';
    }
}

require __DIR__ . '/../includes/aidocs-doc-parser.php';

$argv  = $_SERVER['argv'];
$mode  = 'census';
$files = [];

foreach ( array_slice( $argv, 1 ) as $arg ) {
    if ( $arg === '--blocks' )     { $mode = 'blocks'; continue; }
    if ( $arg === '--html' )       { $mode = 'html';   continue; }
    if ( $arg === '--fields' )     { $mode = 'fields'; continue; }
    if ( substr( $arg, 0, 2 ) === '--' ) { fwrite( STDERR, "unknown option $arg\n" ); exit( 2 ); }
    $files[] = $arg;
}

if ( ! $files ) {
    fwrite( STDERR, "usage: php tools/parse-check.php [--census|--blocks|--html|--fields] <file.txt> …\n" );
    exit( 2 );
}

$totals = [];

foreach ( $files as $file ) {
    if ( ! is_readable( $file ) ) {
        fwrite( STDERR, "cannot read $file\n" );
        continue;
    }

    $parsed = aidocs_parse_labeled_document( file_get_contents( $file ) );

    if ( $mode === 'blocks' ) {
        echo json_encode( $parsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), "\n";
        continue;
    }

    if ( $mode === 'html' ) {
        echo aidocs_render_content_blocks( $parsed['blocks'] ), "\n";
        continue;
    }

    if ( $mode === 'fields' ) {
        printf(
            "%s\n  title:   %s\n  teaser:  %s\n  updated: %s (%s)\n  history: %s\n",
            basename( $file ),
            $parsed['title'],
            mb_strimwidth( $parsed['teaser'], 0, 90, '…' ),
            $parsed['last_updated'],
            aidocs_normalize_doc_date( $parsed['last_updated'] ) ?: 'no date',
            mb_strimwidth( $parsed['document_history'], 0, 90, '…' )
        );
        continue;
    }

    $census = aidocs_census( $parsed['blocks'] );
    foreach ( $census as $key => $value ) {
        $totals[ $key ] = ( $totals[ $key ] ?? 0 ) + $value;
    }

    $problems = aidocs_problems( $parsed, $census );

    printf(
        "%-72s %s%s\n",
        mb_strimwidth( basename( $file ), 0, 72 ),
        aidocs_census_line( $census ),
        $problems ? '  ⚠ ' . implode( '; ', $problems ) : ''
    );
}

if ( $mode === 'census' && count( $files ) > 1 ) {
    printf( "%-72s %s\n", 'TOTAL (' . count( $files ) . ' files)', aidocs_census_line( $totals ) );
}

/** Count the blocks of a parsed document by type, nested lists included. */
function aidocs_census( array $blocks, array $census = [] ) {
    foreach ( $blocks as $block ) {
        $type = $block['type'] ?? '?';
        if ( $type === 'heading' ) {
            $type = 'h' . ( $block['level'] ?? '?' );
            if ( ! empty( $block['note'] ) ) $census['note-heading'] = ( $census['note-heading'] ?? 0 ) + 1;
        }
        $census[ $type ] = ( $census[ $type ] ?? 0 ) + 1;

        if ( $type === 'list' ) {
            $census['items'] = ( $census['items'] ?? 0 ) + count( $block['items'] ?? [] );
            foreach ( (array) ( $block['items'] ?? [] ) as $item ) {
                if ( ! empty( $item['blocks'] ) ) $census = aidocs_census( $item['blocks'], $census );
            }
        }
        if ( $type === 'table' ) {
            $census['rows'] = ( $census['rows'] ?? 0 ) + count( $block['rows'] ?? [] );
        }
    }
    return $census;
}

function aidocs_census_line( array $census ) {
    $order = [ 'h2', 'h3', 'h4', 'note-heading', 'paragraph', 'note', 'list', 'items', 'table', 'rows' ];
    $parts = [];
    foreach ( $order as $key ) {
        if ( ! empty( $census[ $key ] ) ) $parts[] = $key . '=' . $census[ $key ];
    }
    foreach ( $census as $key => $value ) {
        if ( ! in_array( $key, $order, true ) ) $parts[] = $key . '=' . $value;
    }
    return implode( ' ', $parts );
}

/** Findings worth a second look, rather than hard failures. */
function aidocs_problems( array $parsed, array $census ) {
    $problems = [];

    if ( $parsed['title'] === '' )                     $problems[] = 'no title';
    if ( ! $parsed['labeled'] )                        $problems[] = 'unlabelled';
    if ( $parsed['labeled'] && $parsed['teaser'] === '' ) $problems[] = 'no teaser';
    if ( $parsed['last_updated'] === '' )              $problems[] = 'no last-updated';
    elseif ( aidocs_normalize_doc_date( $parsed['last_updated'] ) === '' ) $problems[] = 'unparsable date';
    if ( empty( $census['paragraph'] ) )               $problems[] = 'no paragraphs';

    // A stray label left inside the body means the split above it went wrong.
    foreach ( aidocs_flat_texts( $parsed['blocks'] ) as $text ) {
        if ( preg_match( '/^(Teaser|Body|Last Updated|Document History)\s*:/i', $text ) ) {
            $problems[] = 'label in body: ' . mb_strimwidth( $text, 0, 40, '…' );
            break;
        }
    }

    return $problems;
}

function aidocs_flat_texts( array $blocks ) {
    $texts = [];
    foreach ( $blocks as $block ) {
        if ( isset( $block['text'] ) ) $texts[] = $block['text'];
        foreach ( (array) ( $block['items'] ?? [] ) as $item ) {
            if ( isset( $item['text'] ) ) $texts[] = $item['text'];
            if ( ! empty( $item['blocks'] ) ) $texts = array_merge( $texts, aidocs_flat_texts( $item['blocks'] ) );
        }
    }
    return $texts;
}
