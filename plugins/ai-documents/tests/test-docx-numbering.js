/**
 * assets/js/aidocs-docx-numbering.js — Word's numbering, read from the .docx.
 *
 * The client reported that "Antitrust Compliance", tab "Definitions", lost
 * its formatting. Two separate things were wrong, and this file covers the
 * second: mammoth emits <ol> for every ordered list whatever format Word
 * prints, so "a. b. c." and "i. ii. iii." were published as "1. 2. 3." —
 * 395 of the corpus's 1,623 numbered paragraphs. (The first, an outline
 * collapsing into one flat list, is a parser problem and lives in
 * tests/test-lists.php.)
 *
 * The XML below is the shape Word writes, cut down to the parts this module
 * reads. The archive reader itself is exercised by the extractor's own run
 * against real .docx files, not from here.
 *
 * Run with: node tests/test-docx-numbering.js
 */

'use strict';

require( './mini-dom.js' );

var numbering = require( '../assets/js/aidocs-docx-numbering.js' );
var structure = require( '../assets/js/aidocs-docx-structure.js' );
var results   = [];

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

var NUMBERING_XML =
    '<w:numbering>' +
      '<w:abstractNum w:abstractNumId="7">' +
        '<w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="lowerLetter"/></w:lvl>' +
        '<w:lvl w:ilvl="1"><w:start w:val="1"/><w:numFmt w:val="lowerRoman"/></w:lvl>' +
      '</w:abstractNum>' +
      '<w:abstractNum w:abstractNumId="8">' +
        '<w:lvl w:ilvl="0"><w:start w:val="3"/><w:numFmt w:val="decimal"/></w:lvl>' +
      '</w:abstractNum>' +
      '<w:num w:numId="101"><w:abstractNumId w:val="7"/></w:num>' +
      '<w:num w:numId="102"><w:abstractNumId w:val="8"/></w:num>' +
      '<w:num w:numId="103"><w:abstractNumId w:val="7"/></w:num>' +
    '</w:numbering>';

function para( numId, ilvl, text ) {
    return '<w:p><w:pPr><w:numPr><w:ilvl w:val="' + ilvl + '"/><w:numId w:val="' + numId +
           '"/></w:numPr></w:pPr><w:r><w:t>' + text + '</w:t></w:r></w:p>';
}

test( 'A level carries its format and its start', function () {
    var nums = numbering.readNumbering( NUMBERING_XML );
    equal( nums[ '101' ][ '0' ], { style: 'lower-alpha', start: 1 } );
    equal( nums[ '101' ][ '1' ], { style: 'lower-roman', start: 1 } );
    equal( nums[ '102' ][ '0' ], { style: 'decimal', start: 3 } );
} );

test( 'Items are counted in document order, per numbering and per level', function () {
    var doc = '<w:body>' + para( 101, 0, 'First' ) + para( 101, 0, 'Second' ) + para( 101, 0, 'Third' ) + '</w:body>';
    var index = numbering.indexParagraphs( doc, numbering.readNumbering( NUMBERING_XML ) );
    equal( index[ 'first' ],  { style: 'lower-alpha', ordinal: 1, level: 0 } );
    equal( index[ 'second' ], { style: 'lower-alpha', ordinal: 2, level: 0 } );
    equal( index[ 'third' ],  { style: 'lower-alpha', ordinal: 3, level: 0 } );
} );

test( 'A list that Word starts at three starts at three here', function () {
    var doc = '<w:body>' + para( 102, 0, 'Continues' ) + para( 102, 0, 'And on' ) + '</w:body>';
    var index = numbering.indexParagraphs( doc, numbering.readNumbering( NUMBERING_XML ) );
    equal( index[ 'continues' ].ordinal, 3 );
    equal( index[ 'and on' ].ordinal, 4 );
} );

test( 'A deeper level restarts under each parent item', function () {
    var doc = '<w:body>' +
        para( 101, 0, 'Parent one' ) + para( 101, 1, 'Child A' ) + para( 101, 1, 'Child B' ) +
        para( 101, 0, 'Parent two' ) + para( 101, 1, 'Child C' ) +
        '</w:body>';
    var index = numbering.indexParagraphs( doc, numbering.readNumbering( NUMBERING_XML ) );
    equal( index[ 'parent one' ].ordinal, 1 );
    equal( index[ 'parent two' ].ordinal, 2 );
    equal( index[ 'child a' ].ordinal, 1 );
    equal( index[ 'child b' ].ordinal, 2 );
    // The reason this file exists: under "Parent two" the count starts over.
    equal( index[ 'child c' ].ordinal, 1 );
} );

test( 'Text repeated with the same format and count keeps its marker', function () {
    // A compilation of 49 policies quotes the same standard in several of
    // them; 124 of 1,239 keys repeat, and most agree with themselves.
    // Two separate numberings of the same shape, each on its first item.
    var doc = '<w:body>' + para( 101, 0, 'Shared' ) + para( 102, 0, 'Filler' ) + para( 103, 0, 'Shared' ) + '</w:body>';
    var index = numbering.indexParagraphs( doc, numbering.readNumbering( NUMBERING_XML ) );
    equal( index[ 'shared' ], { style: 'lower-alpha', ordinal: 1, level: 0 } );
} );

test( 'Text repeated at a different count keeps the format and drops the count', function () {
    var doc = '<w:body>' + para( 101, 0, 'Shared' ) + para( 101, 0, 'Shared' ) + '</w:body>';
    var index = numbering.indexParagraphs( doc, numbering.readNumbering( NUMBERING_XML ) );
    equal( index[ 'shared' ].style, 'lower-alpha' );
    equal( index[ 'shared' ].ordinal, null );
} );

test( 'Text repeated under two different formats is dropped outright', function () {
    var doc = '<w:body>' + para( 101, 0, 'Shared' ) + para( 102, 0, 'Shared' ) + '</w:body>';
    var index = numbering.indexParagraphs( doc, numbering.readNumbering( NUMBERING_XML ) );
    equal( Object.prototype.hasOwnProperty.call( index, 'shared' ), false );
} );

test( 'An unnumbered paragraph is not indexed', function () {
    var doc = '<w:body><w:p><w:r><w:t>Just prose</w:t></w:r></w:p>' + para( 101, 0, 'Listed' ) + '</w:body>';
    var index = numbering.indexParagraphs( doc, numbering.readNumbering( NUMBERING_XML ) );
    equal( Object.prototype.hasOwnProperty.call( index, 'just prose' ), false );
    equal( Object.prototype.hasOwnProperty.call( index, 'listed' ), true );
} );

// ── The markers that get written ────────────────────────────────────────

test( 'Each format is spelled the way the canonical grammar reads it', function () {
    equal( structure.formatOrdinal( 'decimal', 3 ),     '3.' );
    equal( structure.formatOrdinal( 'lower-alpha', 3 ), 'c.' );
    equal( structure.formatOrdinal( 'upper-alpha', 2 ), 'B.' );
    equal( structure.formatOrdinal( 'lower-roman', 4 ), 'iv.' );
    equal( structure.formatOrdinal( 'upper-roman', 9 ), 'IX.' );
    equal( structure.formatOrdinal( 'lower-roman', 13 ), 'xiii.' );
} );

test( 'Past twenty-six letters the format is dropped rather than spelled "aa."', function () {
    // The grammar's letter marker is one character on purpose: two would
    // read "Mr. Smith" at the start of a line as a list item.
    equal( structure.formatOrdinal( 'lower-alpha', 26 ), 'z.' );
    equal( structure.formatOrdinal( 'lower-alpha', 27 ), '27.' );
} );

// ── End to end, through the extractor ───────────────────────────────────

function fakeIndex( map ) {
    return { lookup: function ( text ) {
        var key = numbering.keyFor( text );
        return Object.prototype.hasOwnProperty.call( map, key ) ? map[ key ] : null;
    } };
}

test( "Word's format reaches the canonical text", function () {
    var html = '<ol><li>Alpha</li><li>Beta</li></ol>';
    equal( structure.htmlToCanonical( html, fakeIndex( {
        alpha: { style: 'lower-alpha', ordinal: 1, level: 0 },
        beta:  { style: 'lower-alpha', ordinal: 2, level: 0 }
    } ) ), 'a. Alpha\nb. Beta' );
} );

test( "Word's level wins over the depth mammoth nested at", function () {
    // Word writes an outline as separate level-0 lists with the parent's
    // letter typed into an ordinary paragraph, so mammoth's nesting and
    // Word's levels disagree. Word is the document.
    var html = '<ol><li>Top<ol><li>Under</li></ol></li></ol>';
    equal( structure.htmlToCanonical( html, fakeIndex( {
        top:   { style: 'decimal', ordinal: 1, level: 0 },
        under: { style: 'lower-alpha', ordinal: 1, level: 1 }
    } ) ), '1. Top\n  a. Under' );
} );

test( 'With no index at all, the extractor writes what it always wrote', function () {
    var html = '<ol><li>First</li><li>Second</li></ol><ul><li>Bullet</li></ul>';
    equal( structure.htmlToCanonical( html, null ), '1. First\n2. Second\n- Bullet' );
} );

test( 'An item the index does not know falls back to its position', function () {
    var html = '<ol><li>Known</li><li>Unknown</li></ol>';
    equal( structure.htmlToCanonical( html, fakeIndex( {
        known: { style: 'decimal', ordinal: 1, level: 0 }
    } ) ), '1. Known\n2. Unknown' );
} );

results.forEach( function ( r ) {
    console.log( ( r.ok ? 'PASS' : 'FAIL' ) + ' — ' + r.name );
    if ( ! r.ok ) console.log( '       ' + r.error.replace( /\n/g, '\n       ' ) );
} );
var failed = results.filter( function ( r ) { return ! r.ok; } ).length;
console.log( '\n' + ( results.length - failed ) + ' passed, ' + failed + ' failed (of ' + results.length + ')' );
process.exit( failed ? 1 : 0 );
