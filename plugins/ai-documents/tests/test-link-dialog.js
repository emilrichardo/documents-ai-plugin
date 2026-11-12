/**
 * assets/js/aidocs-link-dialog.js — telling an address from a search.
 *
 * The dialog has one box for two jobs. What decides which job it is is
 * looksLikeAddress(): an address is applied as typed, anything else is words
 * to search the site for. Getting it wrong either way is visible — a search
 * term used as an address makes a dead link, an address sent to the search
 * finds nothing — so the cases below are the ones editors actually type.
 *
 * The dialog itself is DOM and is checked in the browser, on staging.
 *
 * Run with: node tests/test-link-dialog.js
 */

'use strict';

var dialog  = require( '../assets/js/aidocs-link-dialog.js' );
var results = [];

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

test( 'A scheme, a leading slash, a fragment or a query is an address', function () {
    [ 'https://commissioncu.org/x', 'http://a.org', 'mailto:a@b.org', 'tel:+15551234',
      '/policies/credit-hours/', '#section-2', '?page=2' ].forEach( function ( text ) {
        equal( dialog.looksLikeAddress( text ), true, text );
    } );
} );

test( 'A host-shaped first segment is an address, with or without a path', function () {
    equal( dialog.looksLikeAddress( 'commissioncu.org' ), true );
    equal( dialog.looksLikeAddress( 'commissioncu.org/policies/credit-hours/' ), true );
    equal( dialog.looksLikeAddress( 'sacscoc.org:8080/x' ), true );
} );

test( 'Words are a search, however they are spelled', function () {
    [ 'credit hours', 'credit-hours', 'credit', 'Dual Enrollment', 'a', 'Standard 14.2' ].forEach( function ( text ) {
        equal( dialog.looksLikeAddress( text ), false, text );
    } );
} );

test( 'Anything with a space in it is never an address', function () {
    equal( dialog.looksLikeAddress( 'https://a.org/x y' ), false );
    equal( dialog.looksLikeAddress( '/policies/credit hours/' ), false );
} );

test( 'Empty and null are not addresses', function () {
    equal( dialog.looksLikeAddress( '' ), false );
    equal( dialog.looksLikeAddress( '   ' ), false );
    equal( dialog.looksLikeAddress( null ), false );
    equal( dialog.looksLikeAddress( undefined ), false );
} );

test( 'open() without a document is a harmless no-op', function () {
    equal( typeof dialog.open, 'function' );
    dialog.open( { search: function () {}, onSave: function () {} } );
} );

results.forEach( function ( r ) {
    console.log( ( r.ok ? 'PASS' : 'FAIL' ) + ' — ' + r.name + ( r.ok ? '' : '\n' + r.error ) );
} );
var failed = results.filter( function ( r ) { return ! r.ok; } ).length;
console.log( '\n' + ( results.length - failed ) + ' passed, ' + failed + ' failed (of ' + results.length + ')' );
process.exit( failed ? 1 : 0 );
