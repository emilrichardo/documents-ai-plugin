<?php
/**
 * Hyperlinks, end to end.
 *
 * The cases below are the ones the change was specified against: an external
 * link survives extraction, storage, the "Edit content" round trip, an AI
 * restructure and rendering; a link into the previous site's uploads loses its
 * destination and keeps every word of its text.
 *
 * Everything here runs against the real parser and renderer — the only thing
 * stubbed is WordPress itself (tests/bootstrap.php).
 */

// ── The central decision ────────────────────────────────────────────────

test( 'Case A — a plain external link is external_valid', function () {
    assert_equal( aidocs_classify_link( 'https://example.com' ), 'external_valid' );
    assert_equal( aidocs_classify_link( 'https://www.ed.gov/' ), 'external_valid' );
    assert_equal( aidocs_classify_link( 'http://external-site.com/page' ), 'external_valid' );
    assert_equal( aidocs_classify_link( '//example.org/resource' ), 'external_valid' );
} );

test( 'Case B — query strings, anchors, long paths and mailto/tel survive classification', function () {
    assert_equal( aidocs_classify_link( 'https://example.com/page?id=123&source=policy' ), 'external_valid' );
    assert_equal( aidocs_classify_link( 'https://example.org/a/very/long/path/to/a/resource#section-3' ), 'external_valid' );
    assert_equal( aidocs_classify_link( 'mailto:policy@example.org' ), 'external_valid' );
    assert_equal( aidocs_classify_link( 'tel:+1-404-679-4500' ), 'external_valid' );
} );

test( 'Case C — an absolute link into the old site uploads is internal_obsolete', function () {
    assert_equal( aidocs_classify_link( 'https://oldsite.com/wp-content/uploads/2020/policy.pdf' ), 'internal_obsolete' );
    assert_equal( aidocs_classify_link( 'http://oldsite.com/sites/default/files/handbook.doc' ), 'internal_obsolete' );
} );

test( 'Case D — a relative link into the old site uploads is internal_obsolete', function () {
    assert_equal( aidocs_classify_link( '/wp-content/uploads/2020/policy.pdf' ), 'internal_obsolete' );
    assert_equal( aidocs_classify_link( '/2020/appeals-procedures.pdf' ), 'internal_obsolete' );
    assert_equal( aidocs_classify_link( 'old-policy.docx' ), 'internal_obsolete' );
} );

test( 'Case E — an anchor is an anchor, never an obsolete upload', function () {
    assert_equal( aidocs_classify_link( '#appeals-procedure' ), 'anchor' );
    assert_equal( aidocs_classify_link( '#sec-substantive-change' ), 'anchor' );
    assert_true( aidocs_keep_link( '#appeals-procedure' ), 'anchors are kept' );
} );

test( 'An external PDF on a third-party host is not an obsolete upload', function () {
    assert_equal( aidocs_classify_link( 'https://www.ed.gov/sites/ed/files/handbook.pdf' ), 'external_valid' );
} );

test( 'Unsafe schemes are refused, whitespace obfuscation included', function () {
    assert_equal( aidocs_classify_link( 'javascript:alert(1)' ), 'invalid' );
    assert_equal( aidocs_classify_link( "java\nscript:alert(1)" ), 'invalid' );
    assert_equal( aidocs_classify_link( 'data:text/html;base64,PHNjcmlwdD4=' ), 'invalid' );
    assert_equal( aidocs_classify_link( 'vbscript:msgbox(1)' ), 'invalid' );
    assert_equal( aidocs_classify_link( 'file:///etc/passwd' ), 'invalid' );
    assert_equal( aidocs_link_href( 'javascript:alert(1)' ), '' );
} );

test( 'A bare relative path with no document extension is unknown, and is not kept', function () {
    assert_equal( aidocs_classify_link( '/about/staff' ), 'unknown' );
    assert_equal( aidocs_link_href( '/about/staff' ), '' );
} );

// ── Extraction → blocks ─────────────────────────────────────────────────

test( 'Case A — an external link becomes a run carrying its href', function () {
    $runs = aidocs_inline_runs( 'Visit [Example Website](https://example.com) today.' );
    assert_equal( array_column( $runs, 'text' ), [ 'Visit ', 'Example Website', ' today.' ] );
    assert_equal( $runs[1]['h'], 'https://example.com' );
    assert_equal( aidocs_plain_text( 'Visit [Example Website](https://example.com) today.' ), 'Visit Example Website today.' );
} );

test( 'Case B — the whole query string is kept, character for character', function () {
    $url  = 'https://example.com/page?id=123&source=policy';
    $runs = aidocs_inline_runs( "See [the notice]($url)." );
    assert_equal( $runs[1]['h'], $url );
} );

test( 'Case C — an old upload loses its href and keeps every word', function () {
    $line   = 'See the [Appeals Procedures](https://oldsite.com/wp-content/uploads/2020/policy.pdf) document.';
    $blocks = aidocs_parse_structured_content( $line );
    assert_equal( $blocks[0]['text'], 'See the Appeals Procedures document.' );
    assert_equal( $blocks[0]['runs'], [] );
    assert_equal( aidocs_render_content_blocks( $blocks ), '<div class="aidocs-content"><p class="aidocs-content-p">See the Appeals Procedures document.</p></div>' );
} );

test( 'Case D — a relative old upload loses its href and keeps every word', function () {
    $blocks = aidocs_parse_structured_content( 'See the [Appeals Procedures](/wp-content/uploads/old-file.pdf) document.' );
    assert_equal( $blocks[0]['text'], 'See the Appeals Procedures document.' );
    assert_true( strpos( aidocs_render_content_blocks( $blocks ), '<a ' ) === false, 'no anchor is rendered' );
} );

test( 'Case F — two links in one paragraph keep their URLs, their text and their order', function () {
    $line   = 'Read [the first](https://one.example.org/a) and then [the second](https://two.example.org/b?x=1).';
    $blocks = aidocs_parse_structured_content( $line );
    $html   = aidocs_render_content_blocks( $blocks );

    assert_equal( $blocks[0]['text'], 'Read the first and then the second.' );
    assert_true( strpos( $html, '<a class="aidocs-content-link" href="https://one.example.org/a" target="_blank" rel="noopener noreferrer">the first</a>' ) !== false, 'first link' );
    assert_true( strpos( $html, '<a class="aidocs-content-link" href="https://two.example.org/b?x=1" target="_blank" rel="noopener noreferrer">the second</a>' ) !== false, 'second link' );
    assert_true( strpos( $html, 'one.example.org' ) < strpos( $html, 'two.example.org' ), 'order is kept' );
} );

test( 'Case G — a link inside a list item survives', function () {
    $blocks = aidocs_parse_structured_content( "- Consult [the register](https://example.org/register)\n- And nothing else" );
    assert_equal( $blocks[0]['type'], 'list' );
    assert_equal( $blocks[0]['items'][0]['text'], 'Consult the register' );
    assert_true( strpos( aidocs_render_content_blocks( $blocks ), 'href="https://example.org/register"' ) !== false, 'the list item renders a link' );
} );

test( 'A link inside a table cell survives', function () {
    $blocks = aidocs_parse_structured_content( "| Agency | [Department](https://www.ed.gov/) |" );
    assert_equal( $blocks[0]['type'], 'table' );
    assert_true( strpos( aidocs_render_content_blocks( $blocks ), 'href="https://www.ed.gov/"' ) !== false, 'the cell renders a link' );
} );

test( 'A link inside a note keeps its href when the note drops the bold', function () {
    $blocks = aidocs_parse_structured_content( '**Note: see [the register](https://example.org/register) first.**' );
    assert_equal( $blocks[0]['type'], 'note' );
    assert_equal( $blocks[0]['text'], 'see the register first.' );
    assert_true( strpos( aidocs_render_content_blocks( $blocks ), 'href="https://example.org/register"' ) !== false, 'the note renders a link' );
} );

test( 'Emphasis inside a link, and a link inside emphasis, both survive', function () {
    $runs = aidocs_inline_runs( 'See [the **Department** website](https://www.ed.gov/).' );
    assert_equal( $runs[2]['text'], 'Department' );
    assert_equal( $runs[2]['b'], 1 );
    assert_equal( $runs[2]['h'], 'https://www.ed.gov/' );

    $runs = aidocs_inline_runs( 'See **[the Department](https://www.ed.gov/)** today.' );
    assert_equal( $runs[1]['b'], 1 );
    assert_equal( $runs[1]['h'], 'https://www.ed.gov/' );
} );

test( 'A bracketed run-in title is not mistaken for a link', function () {
    assert_equal( aidocs_inline_runs( '[Governing Law] The arbitration shall be governed by state law.' ), [] );
    assert_equal( aidocs_plain_text( '[Governing Law] The arbitration shall be governed by state law.' ),
                  '[Governing Law] The arbitration shall be governed by state law.' );
} );

// ── Case H, part 1: the "Edit content" round trip ────────────────────────

test( 'Case H — blocks → canonical text → blocks preserves links exactly', function () {
    $source = "## Contact\nMore information is available on the [U.S. Department of Education website](https://www.ed.gov/).\n"
            . "- See [the register](https://example.org/r?x=1&y=2)\n"
            . "Write to [us](mailto:policy@example.org) or jump to [Appeals](#appeals-procedure).";

    $first  = aidocs_parse_structured_content( $source );
    $text   = aidocs_blocks_to_canonical_text( $first );
    $second = aidocs_parse_structured_content( $text );

    assert_equal( json_encode( $second ), json_encode( $first ), 'the second parse equals the first' );
    assert_true( strpos( $text, '](https://www.ed.gov/)' ) !== false, 'the URL is written back' );
    assert_true( strpos( $text, '](https://example.org/r?x=1&y=2)' ) !== false, 'the query string is written back' );
    assert_true( strpos( $text, '](mailto:policy@example.org)' ) !== false, 'mailto is written back' );
    assert_true( strpos( $text, '](#appeals-procedure)' ) !== false, 'the anchor is written back' );
} );

test( 'A link with a bold word round-trips as one link, not two', function () {
    $blocks = aidocs_parse_structured_content( 'See [the **Department** website](https://www.ed.gov/).' );
    $text   = aidocs_blocks_to_canonical_text( $blocks );
    assert_equal( $text, 'See [the **Department** website](https://www.ed.gov/).' );
} );

test( 'An obsolete link is gone for good after one round trip', function () {
    $blocks = aidocs_parse_structured_content( 'See the [Appeals Procedures](/wp-content/uploads/old.pdf) document.' );
    $text   = aidocs_blocks_to_canonical_text( $blocks );
    assert_equal( $text, 'See the Appeals Procedures document.' );
} );

// ── Case H, part 2: the AI restructure ──────────────────────────────────

test( 'Links leave for the AI as markers, with no URL in the payload', function () {
    $links = [];
    $sent  = aidocs_link_markers(
        "More information is on the [U.S. Department of Education website](https://www.ed.gov/).\n"
        . "See the [Appeals Procedures](/wp-content/uploads/old.pdf) document.",
        $links
    );

    assert_equal( $sent, "More information is on the U.S. Department of Education website{{L1}}.\nSee the Appeals Procedures document." );
    assert_equal( count( $links ), 1 );
    assert_equal( $links[0]['href'], 'https://www.ed.gov/' );
    assert_true( strpos( $sent, 'ed.gov' ) === false, 'no URL is sent to the model' );
} );

test( 'Case H — a reply that kept the marker gets its exact URL back', function () {
    $links  = [];
    aidocs_link_markers( 'More information is on the [U.S. Department of Education website](https://www.ed.gov/).', $links );

    $pieces = aidocs_restore_links_in_pieces( [
        [ 'type' => 'heading', 'level' => 3, 'text' => 'Further Information' ],
        [ 'type' => 'paragraph', 'text' => 'More information is on the U.S. Department of Education website{{L1}}.' ],
    ], $links );

    $blocks = aidocs_blocks_from_ai( $pieces );
    assert_equal( $blocks[1]['text'], 'More information is on the U.S. Department of Education website.' );
    assert_true( strpos( aidocs_render_content_blocks( $blocks ), 'href="https://www.ed.gov/"' ) !== false, 'the link is on the page' );
} );

test( 'A reply that dropped the marker still gets its link back, by phrase', function () {
    $links  = [];
    aidocs_link_markers( 'More information is on the [U.S. Department of Education website](https://www.ed.gov/).', $links );

    $pieces = aidocs_restore_links_in_pieces(
        [ [ 'type' => 'paragraph', 'text' => 'More information is on the U.S. Department of Education website.' ] ],
        $links
    );
    $blocks = aidocs_blocks_from_ai( $pieces );
    assert_equal( $blocks[0]['text'], 'More information is on the U.S. Department of Education website.' );
    assert_true( strpos( aidocs_render_content_blocks( $blocks ), 'href="https://www.ed.gov/"' ) !== false, 'the link is on the page' );
} );

test( 'Two links in one reply are restored once each, in order', function () {
    $links = [];
    aidocs_link_markers( 'Read [the first](https://one.example.org/a) and [the second](https://two.example.org/b).', $links );

    $pieces = aidocs_restore_links_in_pieces( [
        [ 'type' => 'paragraph', 'text' => 'Read the first{{L1}} and the second{{L2}}.' ],
    ], $links );
    $blocks = aidocs_blocks_from_ai( $pieces );
    $html   = aidocs_render_content_blocks( $blocks );

    assert_equal( $blocks[0]['text'], 'Read the first and the second.' );
    assert_equal( substr_count( $html, '<a class="aidocs-content-link"' ), 2 );
    assert_true( strpos( $html, 'one.example.org' ) < strpos( $html, 'two.example.org' ), 'order is kept' );
} );

test( 'A link split across two restructured pieces is restored once, not twice', function () {
    $links = [];
    aidocs_link_markers( 'Consult [the register](https://example.org/register) often.', $links );

    $pieces = aidocs_restore_links_in_pieces( [
        [ 'type' => 'paragraph', 'text' => 'Consult the register{{L1}} often.' ],
        [ 'type' => 'paragraph', 'text' => 'Consult the register often, again.' ],
    ], $links );

    assert_equal( $pieces[0]['text'], 'Consult [the register](https://example.org/register) often.' );
    assert_equal( $pieces[1]['text'], 'Consult the register often, again.' );
} );

test( 'A marker the model invented is deleted rather than shown to a reader', function () {
    $used  = [];
    $out   = aidocs_restore_link_markers( 'A sentence with a stray {{L7}} marker.', [], $used );
    assert_equal( $out, 'A sentence with a stray  marker.' );

    $links = [];
    aidocs_link_markers( 'Visit [Example](https://example.com).', $links );
    $used = [];
    assert_equal( aidocs_restore_link_markers( 'Nothing here{{L9}}.', $links, $used ), 'Nothing here.' );
} );

test( 'A URL the model wrote by itself is not treated as a link by the restructure path', function () {
    // aidocs_blocks_from_ai() builds runs from the piece text, so a model that
    // invented markup of its own would otherwise produce a link — but a
    // javascript: destination cannot survive the policy, at parse or at render.
    $blocks = aidocs_blocks_from_ai( [ [ 'type' => 'paragraph', 'text' => 'Press [here](javascript:alert) now.' ] ] );
    assert_equal( $blocks[0]['text'], 'Press here now.' );
    assert_true( strpos( aidocs_render_content_blocks( $blocks ), 'href=' ) === false, 'no href at all' );

    // And a URL that the narrow link grammar cannot even express stays literal
    // text rather than becoming an anchor by accident.
    $blocks = aidocs_blocks_from_ai( [ [ 'type' => 'paragraph', 'text' => 'Press [here](javascript:alert(1)) now.' ] ] );
    assert_true( strpos( aidocs_render_content_blocks( $blocks ), '<a ' ) === false, 'no anchor' );
} );

test( 'Restructured list items and table cells get their links back too', function () {
    $links = [];
    aidocs_link_markers( "- Consult [the register](https://example.org/register)\n| Agency | [Department](https://www.ed.gov/) |", $links );

    $pieces = aidocs_restore_links_in_pieces( [
        [ 'type' => 'list_item', 'marker' => '-', 'level' => 1, 'text' => 'Consult the register{{L1}}' ],
        [ 'type' => 'table_row', 'cells' => [ 'Agency', 'Department{{L2}}' ] ],
    ], $links );
    $html = aidocs_render_content_blocks( aidocs_blocks_from_ai( $pieces ) );

    assert_true( strpos( $html, 'href="https://example.org/register"' ) !== false, 'list item link' );
    assert_true( strpos( $html, 'href="https://www.ed.gov/"' ) !== false, 'table cell link' );
} );

// ── Rendering and security ──────────────────────────────────────────────

test( 'An external link renders with target and rel; an anchor renders with neither', function () {
    $html = aidocs_render_runs( [ 'text' => 'ed.gov', 'runs' => [ [ 'text' => 'ed.gov', 'h' => 'https://www.ed.gov/' ] ] ] );
    assert_equal( $html, '<a class="aidocs-content-link" href="https://www.ed.gov/" target="_blank" rel="noopener noreferrer">ed.gov</a>' );

    $html = aidocs_render_runs( [ 'text' => 'Appeals', 'runs' => [ [ 'text' => 'Appeals', 'h' => '#appeals-procedure' ] ] ] );
    assert_equal( $html, '<a class="aidocs-content-link" href="#appeals-procedure">Appeals</a>' );
} );

test( 'A stored href the policy refuses is not rendered, and its text still is', function () {
    foreach ( [ 'javascript:alert(1)', '/wp-content/uploads/old.pdf', 'data:text/html,x' ] as $href ) {
        $html = aidocs_render_runs( [ 'text' => 'See this', 'runs' => [ [ 'text' => 'See this', 'h' => $href ] ] ] );
        assert_equal( $html, 'See this', $href );
    }
} );

test( 'The href and the text are both escaped', function () {
    $html = aidocs_render_runs( [
        'text' => 'x " onmouseover=y',
        'runs' => [ [ 'text' => 'x " onmouseover=y', 'h' => 'https://example.com/?a="><script>' ] ],
    ] );
    assert_true( strpos( $html, '<script>' ) === false, 'no raw script tag' );
    assert_true( strpos( $html, 'onmouseover=y</a>' ) !== false, 'the text is still shown in full' );
    assert_equal( substr_count( $html, '&quot;' ), 2, 'both quotes are escaped' );
} );

test( 'The table of contents never nests one anchor inside another', function () {
    $blocks = aidocs_parse_structured_content(
        "## [Appeals](https://example.org/appeals)\nFirst section body.\n## Reviews\nSecond section body."
    );
    $toc = aidocs_render_toc( $blocks );
    assert_true( strpos( $toc, 'aidocs-content-link' ) === false, 'no inner anchor in the TOC' );
    assert_true( strpos( $toc, 'Appeals' ) !== false, 'the heading text is still there' );
} );

// ── Regressions the change must not cause ───────────────────────────────

test( 'Text with no links at all still produces no runs', function () {
    assert_equal( aidocs_inline_runs( 'An ordinary sentence.' ), [] );
    assert_equal( aidocs_inline_runs( 'A sentence with [brackets] in it.' ), [] );
    $blocks = aidocs_parse_structured_content( 'An ordinary sentence.' );
    assert_equal( $blocks[0]['runs'], [] );
} );

test( 'The prompts that read a document see the words, not the markup', function () {
    assert_equal(
        aidocs_strip_link_markup( 'More information is on the [U.S. Department of Education website](https://www.ed.gov/).' ),
        'More information is on the U.S. Department of Education website.'
    );
    assert_equal( aidocs_strip_link_markup( 'No links here.' ), 'No links here.' );
} );

test( 'Headings, anchors and the document labels still parse with links present', function () {
    $parsed = aidocs_parse_labeled_document(
        "# Appeals Policy\nTeaser: How appeals work.\nBody:\n## Procedure\nSee [the register](https://example.org/r).\nLast Updated: June 2026\n"
    );
    assert_equal( $parsed['title'], 'Appeals Policy' );
    assert_equal( $parsed['teaser'], 'How appeals work.' );
    assert_equal( $parsed['blocks'][0]['type'], 'heading' );
    assert_equal( $parsed['blocks'][0]['text'], 'Procedure' );
    assert_equal( $parsed['blocks'][0]['id'], 'sec-procedure' );
    assert_equal( $parsed['blocks'][1]['text'], 'See the register.' );
} );
