/**
 * assets/js/aidocs-pdf-structure.js — list markers on a hanging indent.
 *
 * Found on the PDF the client uploaded for "Antitrust Compliance". Two
 * things went wrong, both from the same geometry: Word sets the text of a
 * list item on a tab stop, so the gap after a marker depends on how wide the
 * marker is.
 *
 *   - "10." is wider than "9.", the tab stop is the same distance from the
 *     margin, so the gap after it is 4.2pt against 9.7pt — under the 0.45em
 *     bar. Items 10–15 stopped being list items and were joined into one
 *     paragraph.
 *   - "A." at the margin with its text on a tab stop 36pt away is further
 *     apart than a table's column gap, so the marker was counted as a cell
 *     and the item came out as a one-row table.
 *
 * The numbers below are the measured ones: 11pt Times, text at x=126, "10."
 * 13.8pt wide, "9." 8.3pt wide, "A." at x=72 with its text at x=108.
 *
 * Run with: node tests/test-pdf-markers.js
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

function item( str, x, y, width ) {
    return { str: str, fontName: 'f', transform: [ 11, 0, 0, 11, x, y ], width: width, height: 11 };
}

/** Lines of one fake page, as pageLines() builds them. */
function lines( items ) {
    var fakePage = { getViewport: function () { return { height: 792 }; } };
    return pdf.pageLines( fakePage, { items: items, styles: {} }, {} );
}

test( 'A two-digit marker on a hanging indent is still a marker', function () {
    var out = lines( [
        item( '9.',  107.9, 558, 8.3 ),  item( 'Limiting output', 125.9, 558, 70 ),
        item( '10.', 107.9, 545, 13.8 ), item( 'Monopolization;', 125.9, 545, 70 )
    ] );
    equal( out.map( function ( l ) { return l.marker; } ), [ '9.', '10.' ] );
});

test( 'A two-digit number followed by an ordinary word space is not', function () {
    var out = lines( [
        item( '10.', 107.9, 545, 13.8 ), item( 'of the code', 124.9, 545, 70 )   // 3.2pt: a space
    ] );
    equal( out[ 0 ].marker, undefined );
});

test( 'A letter marker far from its text is a marker, not a table cell', function () {
    var out = lines( [
        item( 'A.', 72, 697, 10.7 ),
        item( 'Antitrust laws of the United States:', 108, 697, 170 )
    ] );
    equal( out[ 0 ].marker, 'A.' );
    equal( out[ 0 ].cells.length, 1 );
});

test( 'A real two-column row still is a table row', function () {
    var out = lines( [
        item( 'Fee', 72, 600, 20 ), item( 'Amount', 300, 600, 40 )
    ] );
    equal( out[ 0 ].cells.length, 2 );
});

var failed = results.filter( function ( r ) { return ! r.ok; } );
results.forEach( function ( r ) {
    console.log( ( r.ok ? 'PASS' : 'FAIL' ) + ' — ' + r.name + ( r.ok ? '' : '\n' + r.error ) );
} );
console.log( '\n' + ( results.length - failed.length ) + ' passed, ' + failed.length + ' failed (of ' + results.length + ')' );
process.exit( failed.length ? 1 : 0 );
