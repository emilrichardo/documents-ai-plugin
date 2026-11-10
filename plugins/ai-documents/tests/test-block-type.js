/**
 * assets/js/aidocs-block-type.js — what a line of the editor is, and changing it.
 *
 * Run with: node tests/test-block-type.js
 */

'use strict';

var bt      = require( '../assets/js/aidocs-block-type.js' );
var results = [];

function test( name, fn ) {
    try { fn(); results.push( { name: name, ok: true } ); }
    catch ( e ) { results.push( { name: name, ok: false, error: e.message } ); }
}

function equal( actual, expected, label ) {
    var a = JSON.stringify( actual ), e = JSON.stringify( expected );
    if ( a !== e ) throw new Error( ( label || 'equal' ) + ':\n  expected ' + e + '\n  got      ' + a );
}

test( 'Headings are told apart by their level, and #####+ counts as 4', function () {
    equal( bt.classify( '# Title' ).key, 'h1' );
    equal( bt.classify( '## Two' ).key, 'h2' );
    equal( bt.classify( '### Three' ).key, 'h3' );
    equal( bt.classify( '#### Using This Document' ).key, 'h4' );
    equal( bt.classify( '###### Deep' ).key, 'h4' );
} );

test( 'A plain sentence is a paragraph, and a hashtag without a space is not a heading', function () {
    equal( bt.classify( 'Each substantive change type is described.' ), { key: 'p', label: 'Paragraph', locked: false } );
    equal( bt.classify( '#nospace' ).key, 'p' );
} );

test( 'List items, table rows and empty lines are named and locked', function () {
    equal( bt.classify( '- a bullet' ).key, 'bullet' );
    equal( bt.classify( '  - nested bullet' ).key, 'bullet' );
    equal( bt.classify( '1. one' ).key, 'number' );
    equal( bt.classify( '  a. nested' ).key, 'number' );
    equal( bt.classify( 'iii. roman' ).key, 'number' );
    equal( bt.classify( '| a | b |' ).key, 'table' );
    equal( bt.classify( '' ).key, 'blank' );
    ['- x', '1. x', '| a |', ''].forEach( function ( l ) { equal( bt.classify( l ).locked, true, l ); } );
} );

test( 'A bold line says it will be read as a heading', function () {
    equal( bt.classify( '**Purposes of a Credential**' ), { key: 'boldline', label: 'Heading 3 (bold line)', locked: true } );
    // Bold at the start only is a paragraph with a bold label.
    equal( bt.classify( '**Note:** something follows' ).key, 'p' );
} );

test( 'A line starting with a word and a period is not a list unless it is one character', function () {
    equal( bt.classify( 'Mr. Smith arrived.' ).key, 'p' );
    equal( bt.classify( 'St. Louis' ).key, 'p' );
} );

test( 'restyle sets, changes and clears a heading', function () {
    equal( bt.restyle( 'Using This Document', 'h4' ), '#### Using This Document' );
    equal( bt.restyle( '#### Using This Document', 'h2' ), '## Using This Document' );
    equal( bt.restyle( '### Procedures', 'p' ), 'Procedures' );
    equal( bt.restyle( 'Plain', 'p' ), 'Plain' );
} );

test( 'restyle leaves a list item, a table row and a bold line alone', function () {
    equal( bt.restyle( '- item', 'h3' ), '- item' );
    equal( bt.restyle( '1. item', 'h2' ), '1. item' );
    equal( bt.restyle( '| a | b |', 'h3' ), '| a | b |' );
    equal( bt.restyle( '**Bold heading**', 'p' ), '**Bold heading**' );
} );

results.forEach( function ( r ) {
    console.log( ( r.ok ? 'PASS' : 'FAIL' ) + ' — ' + r.name + ( r.ok ? '' : '\n' + r.error ) );
} );
var failed = results.filter( function ( r ) { return ! r.ok; } );
console.log( '\n' + ( results.length - failed.length ) + ' passed, ' + failed.length + ' failed (of ' + results.length + ')' );
process.exit( failed.length ? 1 : 0 );
