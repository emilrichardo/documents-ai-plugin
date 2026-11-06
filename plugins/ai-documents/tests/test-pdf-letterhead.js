/**
 * assets/js/aidocs-pdf-structure.js — the letterhead at the top of page 1.
 *
 * The client's policy PDFs open with the organisation's name and postal
 * address, centred above the title. It is the same on every policy and was
 * coming through as an italic paragraph in front of the title. The client
 * asked for it gone.
 *
 * stripLetterhead() recognises it by its address — a street line directly
 * above a "City, State ZIP" line, near the top of page 1, followed by a gap
 * wider than a paragraph's — and not by whose it is. These cases pin what it
 * removes and, more to the point, what it must leave alone.
 *
 * Run with: node tests/test-pdf-letterhead.js
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

function texts( lines ) {
    return lines.map( function ( line ) { return line.text; } );
}

/** Body lines at an 11pt pitch below the given y. */
function body( top, count ) {
    var out = [];
    for ( var i = 0; i < count; i++ ) out.push( { y: top - i * 12, text: 'Body line ' + ( i + 1 ) + '.' } );
    return out;
}

var LETTERHEAD = [
    { y: 643, text: 'Southern Association of Colleges and Schools' },
    { y: 632, text: 'Commission on Colleges' },
    { y: 620, text: '1866 Southern Lane' },
    { y: 609, text: 'Decatur, Georgia  30033-4097' }
];

function firstPage( head ) {
    return head.concat( [ { y: 566, text: 'ANTITRUST COMPLIANCE' }, { y: 530, text: 'Policy Statement' } ], body( 503, 10 ) );
}

test( 'The name and address above the title go, and the title stays first', function () {
    var out = pdf.stripLetterhead( [ firstPage( LETTERHEAD ) ] );
    equal( texts( out[ 0 ] ).slice( 0, 2 ), [ 'ANTITRUST COMPLIANCE', 'Policy Statement' ] );
    equal( out[ 0 ].length, 12 );
} );

test( 'Only page 1 is touched', function () {
    var second = body( 700, 6 );
    var out = pdf.stripLetterhead( [ firstPage( LETTERHEAD ), second ] );
    equal( out[ 1 ], second );
} );

test( 'A page 1 with no address is returned as it came', function () {
    var pages = [ firstPage( [] ), body( 700, 6 ) ];
    equal( pdf.stripLetterhead( pages ), pages );
} );

test( 'A street line with no "City, State ZIP" under it stays', function () {
    var head = LETTERHEAD.slice( 0, 3 );
    var pages = [ firstPage( head ) ];
    equal( texts( pdf.stripLetterhead( pages )[ 0 ] ).slice( 0, 3 ), texts( head ) );
} );

test( 'An address further down the page is content, not a letterhead', function () {
    var lines = body( 700, 12 );
    lines.push( { y: 520, text: '1866 Southern Lane' }, { y: 508, text: 'Decatur, Georgia 30033-4097' } );
    lines = lines.concat( body( 440, 5 ) );
    equal( pdf.stripLetterhead( [ lines ] )[ 0 ].length, lines.length );
} );

test( 'An address set tight against the text below is not set apart, so it stays', function () {
    var tight = LETTERHEAD.slice( 0, 2 ).concat( [
        { y: 620, text: '1866 Southern Lane' },
        { y: 609, text: 'Decatur, Georgia 30033-4097' }
    ] ).concat( body( 597, 10 ) );
    equal( pdf.stripLetterhead( [ tight ] )[ 0 ].length, tight.length );
} );

test( 'Empty and tiny inputs do not throw', function () {
    equal( pdf.stripLetterhead( [] ), [] );
    equal( pdf.stripLetterhead( null ), [] );
    equal( pdf.stripLetterhead( [ [] ] ), [ [] ] );
    equal( pdf.stripLetterhead( [ [ { y: 700, text: 'x' } ] ] ).length, 1 );
} );

var failed = results.filter( function ( r ) { return ! r.ok; } );
results.forEach( function ( r ) {
    console.log( ( r.ok ? 'PASS' : 'FAIL' ) + ' — ' + r.name + ( r.ok ? '' : '\n' + r.error ) );
} );
console.log( '\n' + ( results.length - failed.length ) + ' passed, ' + failed.length + ' failed (of ' + results.length + ')' );
process.exit( failed.length ? 1 : 0 );
