<?php
/**
 * A heading line can carry an explicit anchor — "#### Acquisition {#bm-acquisition}" —
 * which is how a Word bookmark that other text links to survives the import.
 */

test( 'A trailing {#bm-…} becomes the heading id and leaves its text alone', function () {
    $blocks = aidocs_parse_structured_content( implode( "\n", [
        '### Procedures',
        '#### Acquisition {#bm-acquisition}',
        'See [Acquisition](#bm-acquisition) for the detail.',
    ] ) );
    $heading = $blocks[1] ?? null;
    assert_true( $heading && $heading['type'] === 'heading', 'the second block is a heading' );
    assert_equal( $heading['id'], 'bm-acquisition' );
    assert_equal( $heading['text'], 'Acquisition', 'the marker is not part of the title' );
} );

test( 'A heading without a marker keeps the id made from its text', function () {
    $blocks = aidocs_parse_structured_content( "### Procedures\n#### Acquisition" );
    assert_equal( $blocks[1]['id'], 'sec-acquisition' );
} );

test( 'Only bm- ids are read, so typed text cannot claim another id', function () {
    foreach ( [ '{#content}', '{#sec-overview}', '{#bm-}', '{#bm-a b}' ] as $marker ) {
        $blocks = aidocs_parse_structured_content( "### Procedures\n#### Acquisition $marker" );
        $id     = $blocks[1]['id'] ?? '';
        assert_true( strpos( $id, 'sec-' ) === 0, "marker $marker must leave the text-made id, got '$id'" );
    }
} );

test( 'An explicit anchor is written back out, so editing the text keeps it', function () {
    $blocks = aidocs_parse_structured_content( "### Procedures\n#### Acquisition {#bm-acquisition}" );
    $text   = aidocs_blocks_to_canonical_text( $blocks );
    assert_true( strpos( $text, '#### Acquisition {#bm-acquisition}' ) !== false, 'round trip keeps the marker' );
    $again = aidocs_parse_structured_content( $text );
    assert_equal( $again[1]['id'], 'bm-acquisition' );
} );

test( 'The rendered heading carries the id the link points at', function () {
    $blocks = aidocs_parse_structured_content( "### Procedures\n#### Acquisition {#bm-acquisition}\nSee [Acquisition](#bm-acquisition)." );
    $html   = aidocs_render_blocks( $blocks );
    assert_true( strpos( $html, 'id="bm-acquisition"' ) !== false, 'heading has the id' );
    assert_true( strpos( $html, 'href="#bm-acquisition"' ) !== false, 'link to it is kept' );
} );

test( 'A policy title never shows the marker', function () {
    $parsed = aidocs_parse_labeled_document( implode( "\n", [
        '# Sample Policy {#bm-sample}',
        'Teaser: A short summary.',
        'Body:',
        '### Overview',
        'Some text.',
    ] ) );
    assert_equal( $parsed['title'] ?? '', 'Sample Policy' );
} );
