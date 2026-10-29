/**
 * assets/js/aidocs-docx-structure.js — the .docx → canonical text step.
 *
 * The DOM these cases run against lives in tests/mini-dom.js, which
 * tests/test-docx-numbering.js shares.
 *
 * Run with: node tests/test-docx-links.js
 */

'use strict';

require( './mini-dom.js' );

// ── The runner ──────────────────────────────────────────────────────────

var docx     = require( '../assets/js/aidocs-docx-structure.js' );
var results  = [];

function test( name, fn ) {
    try { fn(); results.push( { name: name, ok: true } ); }
    catch ( e ) { results.push( { name: name, ok: false, error: e.message } ); }
}

function equal( actual, expected, label ) {
    if ( actual !== expected ) {
        throw new Error( ( label || 'equal' ) + ':\n  expected ' + JSON.stringify( expected ) +
                         '\n  got      ' + JSON.stringify( actual ) );
    }
}

// ── Cases ───────────────────────────────────────────────────────────────

test( 'Case A — an external hyperlink becomes canonical link markup', function () {
    equal(
        docx.htmlToCanonical( '<p>Visit <a href="https://example.com">Example Website</a> today.</p>' ),
        'Visit [Example Website](https://example.com) today.'
    );
} );

test( 'Case B — a query string comes through character for character', function () {
    equal(
        docx.htmlToCanonical( '<p>See <a href="https://example.com/page?id=123&amp;source=policy">the notice</a>.</p>' ),
        'See [the notice](https://example.com/page?id=123&source=policy).'
    );
} );

test( 'Case C — an old upload is extracted faithfully, for the server to judge', function () {
    // The extractor never decides: the URL is written out as it stands, and
    // aidocs_classify_link() drops it on the way into blocks. That is what
    // keeps one set of rules rather than two.
    equal(
        docx.htmlToCanonical( '<p>See the <a href="https://oldsite.com/wp-content/uploads/2020/policy.pdf">Appeals Procedures</a> document.</p>' ),
        'See the [Appeals Procedures](https://oldsite.com/wp-content/uploads/2020/policy.pdf) document.'
    );
} );

test( 'Case D — a relative upload path is extracted the same way', function () {
    equal(
        docx.htmlToCanonical( '<p>See the <a href="/wp-content/uploads/old-file.pdf">Appeals Procedures</a> document.</p>' ),
        'See the [Appeals Procedures](/wp-content/uploads/old-file.pdf) document.'
    );
} );

test( 'Case E — a bookmark anchor stays an anchor', function () {
    equal(
        docx.htmlToCanonical( '<p>Jump to <a href="#appeals-procedure">Appeals</a>.</p>' ),
        'Jump to [Appeals](#appeals-procedure).'
    );
} );

test( 'Case F — two links in one paragraph keep their URLs, text and order', function () {
    equal(
        docx.htmlToCanonical( '<p>Read <a href="https://one.example.org/a">the first</a> and then <a href="https://two.example.org/b">the second</a>.</p>' ),
        'Read [the first](https://one.example.org/a) and then [the second](https://two.example.org/b).'
    );
} );

test( 'Case G — a link inside a list item survives', function () {
    equal(
        docx.htmlToCanonical( '<ul><li>Consult <a href="https://example.org/register">the register</a></li><li>Nothing else</li></ul>' ),
        '- Consult [the register](https://example.org/register)\n- Nothing else'
    );
} );

test( 'A link inside a table cell survives', function () {
    equal(
        docx.htmlToCanonical( '<table><tr><td>Agency</td><td><a href="https://www.ed.gov/">Department</a></td></tr></table>' ),
        '| Agency | [Department](https://www.ed.gov/) |'
    );
} );

test( 'Emphasis inside a link, and a link inside emphasis, both survive', function () {
    equal(
        docx.htmlToCanonical( '<p>See <a href="https://www.ed.gov/">the <strong>Department</strong> website</a>.</p>' ),
        'See [the **Department** website](https://www.ed.gov/).'
    );
    equal(
        docx.htmlToCanonical( '<p>See <strong><a href="https://www.ed.gov/">the Department</a></strong> today.</p>' ),
        'See **[the Department](https://www.ed.gov/)** today.'
    );
} );

test( 'Whitespace inside the anchor moves outside the brackets', function () {
    // A link never renders with padding inside its own underline, and the
    // spaces the author put around it are still there.
    equal(
        docx.htmlToCanonical( '<p>Visit<a href="https://example.com"> Example </a>today.</p>' ),
        'Visit [Example](https://example.com) today.'
    );
} );

test( 'A bookmark target with no href is left as plain text', function () {
    equal(
        docx.htmlToCanonical( '<p>An <a id="bookmark-3">anchored phrase</a> here.</p>' ),
        'An anchored phrase here.'
    );
} );

test( 'Square brackets in the link text are escaped so they cannot close it early', function () {
    equal(
        docx.htmlToCanonical( '<p><a href="https://example.com">Form [A]</a></p>' ),
        '[Form \\[A\\]](https://example.com)'
    );
} );

test( 'A URL the canonical grammar cannot express degrades to plain text', function () {
    equal(
        docx.htmlToCanonical( '<p>See <a href="https://example.com/a b(c)">the notice</a>.</p>' ),
        'See the notice.'
    );
} );

test( 'A document with no links extracts exactly as it did before', function () {
    equal(
        docx.htmlToCanonical( '<h1>Appeals Policy</h1><p><strong>Purpose</strong></p><p>Ordinary prose.</p><ol><li>First</li><li>Second</li></ol>' ),
        '# Appeals Policy\n**Purpose**\nOrdinary prose.\n1. First\n2. Second'
    );
} );

// ── Report ──────────────────────────────────────────────────────────────

results.forEach( function ( r ) {
    console.log( ( r.ok ? 'PASS' : 'FAIL' ) + ' — ' + r.name );
    if ( ! r.ok ) console.log( '       ' + r.error.replace( /\n/g, '\n       ' ) );
} );

var failed = results.filter( function ( r ) { return ! r.ok; } ).length;
console.log( '\n' + ( results.length - failed ) + ' passed, ' + failed + ' failed (of ' + results.length + ')' );
process.exit( failed ? 1 : 0 );
