/**
 * Deep Word headings and the links that point at them.
 *
 * Cirlot's review of Substantive Change asked why the headings were plain text
 * and why none of the in-document links went anywhere. One cause: the Word
 * file sets 97 headings in "Heading 7" and "Heading 8", which mammoth does not
 * read, so they arrived as ordinary paragraphs — and the 109 links that point
 * at them had nowhere to land.
 *
 * Run with: node tests/test-docx-anchors.js
 */

'use strict';

require( './mini-dom.js' );

global.self    = global;
global.mammoth = require( '../assets/js/vendor/mammoth.browser.min.js' );

var fs   = require( 'fs' );
var path = require( 'path' );
var docx = require( '../assets/js/aidocs-docx-structure.js' );

var results = [];
function test( name, fn ) {
    return Promise.resolve().then( fn ).then(
        function () { results.push( { name: name, ok: true } ); },
        function ( e ) { results.push( { name: name, ok: false, error: e.message } ); }
    );
}
function equal( actual, expected, label ) {
    if ( actual !== expected ) {
        throw new Error( ( label || 'equal' ) + ':\n  expected ' + JSON.stringify( expected ) +
                         '\n  got      ' + JSON.stringify( actual ) );
    }
}
function has( text, needle, label ) {
    if ( text.indexOf( needle ) === -1 ) throw new Error( ( label || 'has' ) + ': ' + JSON.stringify( needle ) + ' not in\n' + text );
}

var buffer = fs.readFileSync( path.join( __dirname, 'fixtures', 'deep-headings-anchors.docx' ) );
var ab     = buffer.buffer.slice( buffer.byteOffset, buffer.byteOffset + buffer.byteLength );

var run = docx.extract( ab ).then( function ( r ) { return r.text; } );

Promise.all( [
    test( 'A Heading 7 and a Heading 8 are headings, not body text', function () {
        return run.then( function ( text ) {
            has( text, '#### Acquisition', 'Heading 7' );
            has( text, '#### Common Content B', 'Heading 8' );
            has( text, '#### Plain Section', 'a deep heading nobody links to' );
        } );
    } ),

    test( 'A bookmark that a link points at is written onto its heading', function () {
        return run.then( function ( text ) {
            has( text, '#### Acquisition {#bm-acquisition}' );
            has( text, '#### Common Content B {#bm-X45dceaa26c00c0e9738b6bc00dd4a329387b408}' );
        } );
    } ),

    test( 'A bookmark that nothing links to is left out of the heading line', function () {
        return run.then( function ( text ) {
            has( text, '#### Plain Section\n', 'no marker on an unlinked heading' );
            if ( text.indexOf( 'never-linked' ) !== -1 ) throw new Error( 'an unreferenced bookmark was written out' );
        } );
    } ),

    test( 'A link to a heading bookmark is rewritten to the same id', function () {
        return run.then( function ( text ) {
            has( text, '[See Acquisition](#bm-acquisition)' );
            has( text, '[See Common Content B](#bm-X45dceaa26c00c0e9738b6bc00dd4a329387b408)' );
        } );
    } ),

    test( 'A bookmark on a paragraph lands on the heading above it', function () {
        return run.then( function ( text ) {
            has( text, '[See the detail](#bm-acquisition)' );
        } );
    } ),

    test( 'A link to a bookmark that does not exist is left as Word had it', function () {
        return run.then( function ( text ) {
            has( text, '[See nothing](#does-not-exist)' );
        } );
    } ),

    test( 'A heading without a marker keeps the id the server gives it', function () {
        equal( docx.headingSlugId( 'Common Content B – Faculty Qualifications' ), 'sec-common-content-b-faculty-qualifications' );
        equal( docx.headingSlugId( 'Federal Regulation 34 CFR 602.22' ), 'sec-federal-regulation-34-cfr-602-22' );
        equal( docx.headingSlugId( '' ), '' );
        var long = docx.headingSlugId( new Array( 30 ).join( 'abcdef ' ) );
        equal( long.length, 'sec-'.length + 60, 'cut at 60 characters like aidocs_anchor_id()' );
    } )
] ).then( function () {
    var failed = results.filter( function ( r ) { return ! r.ok; } );
    results.forEach( function ( r ) {
        console.log( ( r.ok ? 'PASS' : 'FAIL' ) + ' — ' + r.name + ( r.ok ? '' : '\n' + r.error ) );
    } );
    console.log( '\n' + ( results.length - failed.length ) + ' passed, ' + failed.length + ' failed (of ' + results.length + ')' );
    process.exit( failed.length ? 1 : 0 );
} );
