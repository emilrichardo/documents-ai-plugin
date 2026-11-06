/**
 * assets/js/aidocs-pdf-structure.js — running headers, footers, page numbers.
 *
 * The client reported page breaks and page numbers landing in the extracted
 * text. The band clip at the top of that module cannot reach them: it cuts
 * 3.5% of the page, 27.7pt on US Letter, and Word's default header and
 * footer distance is half an inch — 36pt — so the furniture sits inside the
 * content area. Widening the bands would take "Last Updated:" and "Document
 * History:" with them, which the authored documents write at the foot of the
 * last page.
 *
 * stripPageFurniture() goes by recurrence instead. These cases pin down both
 * halves of that: what it removes, and what it must not.
 *
 * The last two run against tests/fixtures/pdf-pages.json, which is the real
 * line geometry of two policy PDFs off this site — one that carries page
 * numbers where the band cannot reach them, and one that carries no page
 * furniture at all and must come through untouched. Everything above them is
 * synthetic, built to pin one rule each.
 *
 * Run with: node tests/test-pdf-furniture.js
 */

'use strict';

var pdf     = require( '../assets/js/aidocs-pdf-structure.js' );
var results = [];

function test( name, fn ) {
    try { fn(); results.push( { name: name, ok: true } ); }
    catch ( e ) { results.push( { name: name, ok: false, error: e.message } ); }
}

function equal( actual, expected, label ) {
    var a = JSON.stringify( actual ), e = JSON.stringify( expected );
    if ( a !== e ) {
        throw new Error( ( label || 'equal' ) + ':\n  expected ' + e + '\n  got      ' + a );
    }
}

/** One page: a running header, some body, a running footer, a page number. */
function page( number, body, options ) {
    options = options || {};
    var lines = [];
    if ( ! options.noHeader ) lines.push( { y: 740, text: 'The Commission on Colleges' } );
    body.forEach( function ( text, i ) { lines.push( { y: 680 - i * 14, text: text } ); } );
    if ( ! options.noFooter ) lines.push( { y: 44, text: 'Policy Statement' } );
    lines.push( { y: 30, text: 'Page ' + number + ' of 5' } );
    if ( options.extra ) options.extra.forEach( function ( line ) { lines.push( line ); } );
    return lines;
}

function texts( lines ) {
    return lines.map( function ( line ) { return line.text; } );
}

test( 'A running header, a running footer and a page number all go', function () {
    var pages = [
        page( 1, [ 'Opening paragraph.', 'More of it.' ] ),
        page( 2, [ 'Second page body.', 'Still going.' ] ),
        page( 3, [ 'Third page body.', 'And the end.' ] )
    ];
    var out = pdf.stripPageFurniture( pages );
    equal( texts( out[ 0 ] ), [ 'Opening paragraph.', 'More of it.' ] );
    equal( texts( out[ 1 ] ), [ 'Second page body.', 'Still going.' ] );
    equal( texts( out[ 2 ] ), [ 'Third page body.', 'And the end.' ] );
} );

test( 'A page number is matched across pages although its digits differ', function () {
    // "Page 3 of 5" and "Page 4 of 5" are the same furniture.
    equal( pdf.furnitureKey( { text: 'Page 3 of 5' } ), pdf.furnitureKey( { text: 'Page 41 of 5' } ) );
    equal( pdf.furnitureKey( { text: '  THE   Commission ' } ), 'the commission' );
} );

test( 'Text that appears on the last page only is left alone', function () {
    // This is the case the narrow bands existed to protect, and the reason
    // this pass goes by recurrence rather than by position.
    var pages = [
        page( 1, [ 'Body one.' ] ),
        page( 2, [ 'Body two.' ] ),
        page( 3, [ 'Body three.' ], { extra: [
            { y: 60, text: 'Last Updated: June 2026' },
            { y: 46, text: 'Document History: Adopted 2019' }
        ] } )
    ];
    var out = pdf.stripPageFurniture( pages );
    equal( texts( out[ 2 ] ).indexOf( 'Last Updated: June 2026' ) >= 0, true );
    equal( texts( out[ 2 ] ).indexOf( 'Document History: Adopted 2019' ) >= 0, true );
} );

test( 'A phrase repeated in the body, not at a page edge, survives', function () {
    var repeated = 'The Commission on Colleges';
    var pages = [
        page( 1, [ 'Opening.', repeated, 'Closing.' ] ),
        page( 2, [ 'Opening.', repeated, 'Closing.' ] ),
        page( 3, [ 'Opening.', repeated, 'Closing.' ] )
    ];
    var out = pdf.stripPageFurniture( pages );
    // Gone from the header band, kept in the body — same words, different job.
    equal( texts( out[ 0 ] ), [ 'Opening.', repeated, 'Closing.' ] );
} );

test( 'A one- or two-page document keeps its header and footer text', function () {
    var pages = [ page( 1, [ 'Only body.' ] ), page( 2, [ 'Second body.' ] ) ];
    var out = pdf.stripPageFurniture( pages );
    equal( texts( out[ 0 ] ), [ 'The Commission on Colleges', 'Only body.', 'Policy Statement', 'Page 1 of 5' ] );
} );

/** A body of evenly spaced lines, so the page's line pitch is a real one. */
function bodyPage( numberLine, body ) {
    var lines = body.map( function ( text, i ) { return { y: 700 - i * 14, text: text }; } );
    if ( numberLine ) lines.push( numberLine );
    return lines;
}
var BODY = [ 'One.', 'Two.', 'Three.', 'Four.', 'Five.', 'Six.', 'Seven.', 'Eight.' ];

test( 'A lone page number at the foot of a short document goes', function () {
    var out = pdf.stripPageFurniture( [ bodyPage( { y: 50, text: '1' }, BODY ), bodyPage( { y: 50, text: '2' }, BODY ) ] );
    equal( texts( out[ 0 ] ).indexOf( '1' ), -1 );
    equal( texts( out[ 1 ] ).indexOf( '2' ), -1 );
    equal( out[ 0 ].length, BODY.length );
} );

test( 'So does a page number at the head, and "Page 2 of 2"', function () {
    var out = pdf.stripPageFurniture( [ [ { y: 760, text: '3' } ].concat( bodyPage( { y: 50, text: 'Page 2 of 2' }, BODY ) ) ] );
    equal( texts( out[ 0 ] ), BODY );
} );

test( 'A number that is part of the text, or any other line, stays', function () {
    // Directly under the text, not set apart from it: a list item, not a folio.
    var tight = bodyPage( { y: 700 - BODY.length * 14, text: '9' }, BODY );
    equal( texts( pdf.stripPageFurniture( [ tight ] )[ 0 ] ).slice( -1 ), [ '9' ] );
    // Set apart, but a word in it: not a page number.
    var word = bodyPage( { y: 50, text: 'Appendix 1' }, BODY );
    equal( texts( pdf.stripPageFurniture( [ word ] )[ 0 ] ).slice( -1 ), [ 'Appendix 1' ] );
    var amount = bodyPage( { y: 50, text: '10%' }, BODY );
    equal( texts( pdf.stripPageFurniture( [ amount ] )[ 0 ] ).slice( -1 ), [ '10%' ] );
} );

test( 'A page that is only a number is kept', function () {
    equal( texts( pdf.stripPageFurniture( [ [ { y: 400, text: '1' } ] ] )[ 0 ] ), [ '1' ] );
} );

test( 'A header on a minority of pages is not furniture', function () {
    var pages = [
        page( 1, [ 'A.' ] ),
        page( 2, [ 'B.' ], { noHeader: true } ),
        page( 3, [ 'C.' ], { noHeader: true } ),
        page( 4, [ 'D.' ], { noHeader: true } ),
        page( 5, [ 'E.' ], { noHeader: true } )
    ];
    var out = pdf.stripPageFurniture( pages );
    equal( texts( out[ 0 ] ).indexOf( 'The Commission on Colleges' ) >= 0, true );
    // The page number is on every page, so that one does go.
    equal( texts( out[ 0 ] ).indexOf( 'Page 1 of 5' ), -1 );
} );

test( 'A footer that drifts a point or two between pages is still one footer', function () {
    var pages = [
        [ { y: 740, text: 'Header' }, { y: 400, text: 'Body one.' }, { y: 44.0, text: 'Policy Statement' } ],
        [ { y: 740, text: 'Header' }, { y: 400, text: 'Body two.' }, { y: 46.5, text: 'Policy Statement' } ],
        [ { y: 740, text: 'Header' }, { y: 400, text: 'Body three.' }, { y: 42.2, text: 'Policy Statement' } ]
    ];
    var out = pdf.stripPageFurniture( pages );
    out.forEach( function ( lines ) { equal( texts( lines ).indexOf( 'Policy Statement' ), -1 ); } );
} );

test( 'A footer that moves half the page is two different lines', function () {
    var pages = [
        [ { y: 740, text: 'Header' }, { y: 44, text: 'Policy Statement' } ],
        [ { y: 740, text: 'Header' }, { y: 44, text: 'Policy Statement' } ],
        [ { y: 740, text: 'Header' }, { y: 380, text: 'Policy Statement' } ]
    ];
    var out = pdf.stripPageFurniture( pages );
    equal( texts( out[ 2 ] ).indexOf( 'Policy Statement' ) >= 0, true );
} );

test( 'An empty page list, and a page of nothing, do not throw', function () {
    equal( pdf.stripPageFurniture( [] ), [] );
    equal( pdf.stripPageFurniture( null ), [] );
    equal( pdf.stripPageFurniture( [ [], [], [] ] ), [ [], [], [] ] );
} );

// ── Real documents ──────────────────────────────────────────────────────

var real = require( './fixtures/pdf-pages.json' );

function lines( pages ) {
    return pages.reduce( function ( total, page ) { return total + page.length; }, 0 );
}

function removed( name ) {
    var before = real[ name ];
    var after  = pdf.stripPageFurniture( before.map( function ( p ) { return p.slice(); } ) );
    var gone   = [];
    before.forEach( function ( page, i ) {
        page.forEach( function ( line ) {
            if ( after[ i ].indexOf( line ) === -1 ) gone.push( line );
        } );
    } );
    return { gone: gone, kept: lines( after ), total: lines( before ) };
}

test( 'A real policy PDF loses its page numbers and nothing else', function () {
    // Reduced-Credit-Undergraduate-Degree-Guideline.pdf, 4 pages. Its footer
    // is a bare numeral on a baseline 52.6pt up from the bottom edge — well
    // clear of the 27.7pt band, which is why it was reaching the page.
    var r = removed( 'Reduced-Credit-Undergraduate-Degree-Guideline.pdf' );
    equal( r.gone.length, 4, 'one page number per page' );
    equal( r.gone.map( function ( l ) { return l.text; } ), [ '1', '2', '3', '4' ] );
    equal( r.kept, r.total - 4 );

    // The labels the narrow band existed to protect are still there.
    var text = JSON.stringify( real[ 'Reduced-Credit-Undergraduate-Degree-Guideline.pdf' ] );
    equal( text.indexOf( 'Document History' ) >= 0, true, 'the fixture has one to protect' );
    equal( r.gone.some( function ( l ) { return l.text.indexOf( 'Document History' ) >= 0; } ), false );
    equal( r.gone.some( function ( l ) { return l.text.indexOf( 'Revised' ) >= 0; } ), false );
} );

test( 'A real policy PDF with no page furniture is not touched', function () {
    // 24-Credit-Hours.pdf, 5 pages, no running header or footer at all. The
    // pass has to come back empty-handed rather than find something.
    var r = removed( '24-Credit-Hours.pdf' );
    equal( r.gone.length, 0 );
    equal( r.kept, r.total );
} );

results.forEach( function ( r ) {
    console.log( ( r.ok ? 'PASS' : 'FAIL' ) + ' — ' + r.name );
    if ( ! r.ok ) console.log( '       ' + r.error.replace( /\n/g, '\n       ' ) );
} );
var failed = results.filter( function ( r ) { return ! r.ok; } ).length;
console.log( '\n' + ( results.length - failed ) + ' passed, ' + failed + ' failed (of ' + results.length + ')' );
process.exit( failed ? 1 : 0 );
