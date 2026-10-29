<?php
/**
 * Outlines: levels, numbering formats and restarts.
 *
 * The client reported that "Antitrust Compliance", tab "Definitions", had
 * lost its formatting. What it had actually lost was its meaning. The Word
 * original reads
 *
 *     A. Antitrust laws of the United States: …
 *        1. Fixing fees …
 *        2. Coordinating conduct …
 *     Higher education related activities … may include:
 *        1. Policies or practices …
 *     B. The Commission representative(s): …
 *
 * and the published page read
 *
 *     A. Antitrust laws of the United States: …
 *     B. Fixing fees …            ← was 1, under A
 *     C. Coordinating conduct …   ← was 2, under A
 *     1. Policies or practices …
 *     2. The Commission representative(s): …   ← was the definition B
 *
 * A definition was published as an example of an antitrust violation. The
 * cause was aidocs_list_from_flat() taking its numbering format from the
 * first marker it saw and letting every later marker join that same list
 * whatever it said — Word writes an outline like this as literal "A." text
 * in ordinary paragraphs with only the digits as a real list, so all of it
 * arrives at the same depth with nothing but the markers to tell them apart.
 */

// ── The outline ─────────────────────────────────────────────────────────

test( 'A deeper numbering starting at one nests under the item above it', function () {
    $blocks = aidocs_parse_structured_content( implode( "\n", [
        'A. Antitrust laws of the United States: these prohibit the following:',
        '1. Fixing fees, dues, prices, or input costs;',
        '2. Coordinating conduct in connection with fees;',
    ] ) );

    assert_equal( count( $blocks ), 1, 'one list, not three' );
    assert_equal( $blocks[0]['style'], 'upper-alpha' );
    assert_equal( count( $blocks[0]['items'] ), 1, 'the digits are not siblings of A' );

    $nested = $blocks[0]['items'][0]['blocks'][0];
    assert_equal( $nested['type'], 'list' );
    assert_equal( $nested['style'], 'decimal' );
    assert_equal( count( $nested['items'] ), 2 );
} );

test( 'A return to the shallower numbering ends the sub-list', function () {
    $blocks = aidocs_parse_structured_content( implode( "\n", [
        'A. First definition:',
        '1. One thing;',
        '2. Another thing;',
        'B. Second definition.',
    ] ) );

    assert_equal( count( $blocks ), 1 );
    assert_equal( $blocks[0]['style'], 'upper-alpha' );
    assert_equal( count( $blocks[0]['items'] ), 2, 'A and B, with the digits under A' );
    assert_equal( $blocks[0]['items'][1]['text'], 'Second definition.' );
} );

test( 'The reported case, end to end', function () {
    $blocks = aidocs_parse_structured_content( implode( "\n", [
        '## Definitions',
        'For purposes of this policy the following definitions apply:',
        'A. Antitrust laws of the United States: these prohibit the following:',
        '1. Fixing fees, dues, prices, or input costs;',
        '2. Rigging or coordinating bids;',
        'Higher education related activities that may violate them include:',
        '1. Policies or practices purposely designed to harm another accreditor;',
        'B. The Commission representative(s): any individual acting on its behalf.',
    ] ) );

    $types = array_column( $blocks, 'type' );
    assert_equal( $types, [ 'heading', 'paragraph', 'list', 'paragraph', 'list', 'list' ] );

    // The definition B is its own upper-alpha item, numbered two — not the
    // third bullet of the antitrust violations.
    $last = $blocks[5];
    assert_equal( $last['style'], 'upper-alpha' );
    assert_equal( $last['start'], 2 );
    assert_equal( count( $last['items'] ), 1 );
    assert_true( strpos( $last['items'][0]['text'], 'The Commission representative' ) === 0 );

    // The list interrupted by the paragraph starts over at one, as Word does.
    assert_equal( $blocks[4]['style'], 'decimal' );
    assert_true( ! isset( $blocks[4]['start'] ), 'a restarted list carries no start' );
} );

test( 'Items after a numbering change are not dropped on the floor', function () {
    // aidocs_list_from_flat() used to be called once per run of items and
    // its cursor discarded, so everything after a style change vanished.
    $blocks = aidocs_parse_structured_content( implode( "\n", [
        '1. One;',
        '2. Two;',
        'A. Something else entirely.',
    ] ) );
    $text = '';
    foreach ( $blocks as $block ) {
        foreach ( $block['items'] as $item ) $text .= $item['text'] . "\n";
    }
    assert_true( strpos( $text, 'Something else entirely.' ) !== false );
} );

test( 'A numbering arriving mid-count is a new list, not a sub-list', function () {
    // "3." after "A." is not the opening of a sub-list: the count says it
    // continues something, so it opens a sibling rather than nesting.
    $blocks = aidocs_parse_structured_content( implode( "\n", [
        'A. First definition:',
        '3. A continuation of something above;',
    ] ) );
    assert_equal( count( $blocks ), 2 );
    assert_equal( $blocks[1]['style'], 'decimal' );
    assert_equal( $blocks[1]['start'], 3 );
} );

test( 'A bullet after a numbered item is a separate list, never a numbered one', function () {
    $blocks = aidocs_parse_structured_content( implode( "\n", [
        '1. One;',
        '- A bullet;',
    ] ) );
    assert_equal( count( $blocks ), 2 );
    assert_equal( $blocks[0]['style'], 'decimal' );
    assert_equal( $blocks[1]['style'], 'bullet' );
} );

test( 'Indentation still nests, and still wins where it is present', function () {
    $blocks = aidocs_parse_structured_content( implode( "\n", [
        '**Heading made of bold**',
        '1. One;',
        '  a. One a;',
        '  b. One b;',
        '2. Two;',
    ] ) );
    $list = $blocks[1];
    assert_equal( $list['style'], 'decimal' );
    assert_equal( count( $list['items'] ), 2 );
    assert_equal( $list['items'][0]['blocks'][0]['style'], 'lower-alpha' );
    assert_equal( count( $list['items'][0]['blocks'][0]['items'] ), 2 );
} );

// ── The markers the grammar accepts ─────────────────────────────────────

test( 'A multi-character roman marker is read as a list item', function () {
    // The .docx extractor writes these now that it reads Word's own
    // numbering formats; before, every list was spelled with digits.
    $blocks = aidocs_parse_structured_content( implode( "\n", [
        'i. First point;',
        'ii. Second point;',
        'iii. Third point;',
    ] ) );
    assert_equal( count( $blocks ), 1 );
    assert_equal( $blocks[0]['style'], 'lower-roman' );
    assert_equal( count( $blocks[0]['items'] ), 3 );
} );

test( 'A three-digit marker is read as a list item', function () {
    $blocks = aidocs_parse_structured_content( "100. One hundred;\n101. One hundred and one;" );
    assert_equal( $blocks[0]['style'], 'decimal' );
    assert_equal( $blocks[0]['start'], 100 );
} );

test( 'A title and an abbreviation at the start of a line are not markers', function () {
    // This is why the letter marker stays one character long.
    foreach ( [ 'Mr. Smith chaired the committee.', 'Dr. Jones dissented.', 'St. Louis hosted it.' ] as $line ) {
        $blocks = aidocs_parse_structured_content( $line );
        assert_equal( $blocks[0]['type'], 'paragraph', $line );
    }
} );

test( 'An upper-roman marker and an upper-alpha one are told apart', function () {
    assert_equal( aidocs_marker_style( 'IV.' ), 'upper-roman' );
    assert_equal( aidocs_marker_style( 'B.' ), 'upper-alpha' );
    assert_equal( aidocs_marker_style( 'iii)' ), 'lower-roman' );
    assert_equal( aidocs_marker_ordinal( 'iv.', 'lower-roman' ), 4 );
} );

test( 'The outline ranks the numberings the way Word nests them', function () {
    assert_true( aidocs_outline_is_deeper( 'upper-alpha', 'decimal' ) );
    assert_true( aidocs_outline_is_deeper( 'decimal', 'lower-alpha' ) );
    assert_true( aidocs_outline_is_deeper( 'lower-alpha', 'lower-roman' ) );
    assert_true( ! aidocs_outline_is_deeper( 'decimal', 'upper-alpha' ) );
    assert_true( ! aidocs_outline_is_deeper( 'decimal', 'bullet' ) );
    assert_true( ! aidocs_outline_is_deeper( 'decimal', 'decimal' ) );
} );
