/**
 * assets/js/aidocs-link-markup.js — writing a link the parser can read back.
 *
 * The cases here are the ones the client's report produced. An editor
 * selected the label "[PDF]" — a spelling these documents use everywhere —
 * pressed EasyMDE's link button, and the page published
 *
 *     Credit Hours [[PDF]](https://stage.commissioncu.org/document/credit-hours/)
 *
 * as literal text. tests/test-links.php has the other half: that the parser
 * now reads that spelling too, so the pages already saved with it recover.
 *
 * Run with: node tests/test-link-markup.js
 */

'use strict';

var markup  = require( '../assets/js/aidocs-link-markup.js' );
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

test( 'The reported case — a label that is itself bracketed', function () {
    equal(
        markup.linkMarkup( '[PDF]', 'https://stage.commissioncu.org/document/credit-hours/' ),
        '[\\[PDF\\]](https://stage.commissioncu.org/document/credit-hours/)'
    );
} );

test( 'A plain label is written exactly as it was', function () {
    equal( markup.linkMarkup( 'Credit Hours', 'https://example.org/a' ), '[Credit Hours](https://example.org/a)' );
} );

test( 'A backslash doubles, and the brackets it gains are not escaped twice', function () {
    equal( markup.escapeLabel( 'a\\b' ), 'a\\\\b' );
    equal( markup.escapeLabel( '[a]' ), '\\[a\\]' );
    equal( markup.escapeLabel( '\\[a\\]' ), '\\\\\\[a\\\\\\]' );
} );

test( 'Spaces and parentheses in a URL are percent-encoded, not dropped', function () {
    // The grammar stops at the first space or parenthesis, which is how a
    // SharePoint path lost its destination without saying so.
    equal(
        markup.normalizeUrl( 'https://x.sharepoint.com/Shared Documents/Policy (2026).pdf' ),
        'https://x.sharepoint.com/Shared%20Documents/Policy%20%282026%29.pdf'
    );
} );

test( 'A URL the browser already encoded is not encoded a second time', function () {
    equal( markup.normalizeUrl( 'https://a.org/x%20y' ), 'https://a.org/x%20y' );
    equal( markup.normalizeUrl( 'https://a.org/a%2Fb' ), 'https://a.org/a%2Fb' );
} );

test( 'A bare percent sign is encoded, so it cannot read as an escape', function () {
    equal( markup.normalizeUrl( 'https://a.org/100%' ), 'https://a.org/100%25' );
    equal( markup.normalizeUrl( 'https://a.org/50%off' ), 'https://a.org/50%25off' );
} );

test( 'Surrounding whitespace is trimmed; an empty URL stays empty', function () {
    equal( markup.normalizeUrl( '  https://a.org/  ' ), 'https://a.org/' );
    equal( markup.normalizeUrl( '   ' ), '' );
    equal( markup.normalizeUrl( null ), '' );
} );

test( 'isExpressible answers for the normalised URL, not the typed one', function () {
    equal( markup.isExpressible( 'https://a.org/a b(c)' ), true );
    equal( markup.isExpressible( '' ), false );
} );

test( 'A null or undefined label does not become the string "null"', function () {
    equal( markup.escapeLabel( null ), '' );
    equal( markup.escapeLabel( undefined ), '' );
} );

test( 'A web address typed without its scheme gets https://', function () {
    equal( markup.completeUrl( 'commissioncu.org/policies/credit-hours/' ), 'https://commissioncu.org/policies/credit-hours/' );
    equal( markup.completeUrl( 'www.sacscoc.org' ), 'https://www.sacscoc.org' );
} );

test( 'A scheme, a leading slash, a fragment and mailto are left as typed', function () {
    equal( markup.completeUrl( 'https://a.org/x' ), 'https://a.org/x' );
    equal( markup.completeUrl( '/policies/credit-hours/' ), '/policies/credit-hours/' );
    equal( markup.completeUrl( '#section-2' ), '#section-2' );
    equal( markup.completeUrl( 'mailto:a@b.org' ), 'mailto:a@b.org' );
    equal( markup.completeUrl( '   ' ), '' );
} );

test( 'A bare file name is not mistaken for a host', function () {
    equal( markup.completeUrl( 'form.pdf' ), 'form.pdf' );
    equal( markup.needsScheme( markup.completeUrl( 'form.pdf' ) ), true );
} );

test( 'A bare path is flagged, because WordPress would make it http://policies/…', function () {
    equal( markup.needsScheme( markup.completeUrl( 'policies/credit-hours' ) ), true );
    equal( markup.needsScheme( 'https://a.org' ), false );
    equal( markup.needsScheme( '/policies/x/' ), false );
    equal( markup.needsScheme( '#top' ), false );
    equal( markup.needsScheme( '' ), false );
} );

test( 'A cursor inside an existing link finds that link', function () {
    var line = 'b. [Credit Hours](/document/credit-hours/) [PDF];';
    var at   = function ( ch ) { return markup.findLinkAt( line, ch, ch ); };
    var link = at( 8 );
    equal( link.label, 'Credit Hours' );
    equal( link.url, '/document/credit-hours/' );
    equal( line.slice( link.from, link.to ), '[Credit Hours](/document/credit-hours/)' );
    equal( at( link.from ).url, '/document/credit-hours/', 'at the opening bracket' );
    equal( at( link.to ).url, '/document/credit-hours/', 'at the end of the markup' );
    equal( at( 30 ).url, '/document/credit-hours/', 'inside the address' );
} );

test( 'A selection of part of the label still edits the link', function () {
    var line = 'b. [Credit Hours](/document/credit-hours/) [PDF];';
    equal( markup.findLinkAt( line, 4, 10 ).label, 'Credit Hours' );
} );

test( 'Outside a link, or spanning past it, is a new link', function () {
    var line = 'b. [Credit Hours](/document/credit-hours/) [PDF];';
    equal( markup.findLinkAt( line, 1, 1 ), null, 'before it' );
    equal( markup.findLinkAt( line, 46, 46 ), null, 'on the plain [PDF] after it' );
    equal( markup.findLinkAt( line, 0, 20 ), null, 'selection runs out of it' );
    equal( markup.findLinkAt( 'no links here', 3, 3 ), null );
} );

test( 'With two links on a line, the one under the cursor is found', function () {
    var line = '[One](/a/) and [Two](/b/)';
    equal( markup.findLinkAt( line, 3, 3 ).url, '/a/' );
    equal( markup.findLinkAt( line, 18, 18 ).url, '/b/' );
} );

test( 'A label holding [PDF] and escaped brackets is one link, kept as stored', function () {
    var line = 'x [Credit Hours [PDF]](/c/) y [a \\[b\\]](/d/)';
    equal( markup.findLinkAt( line, 5, 5 ).label, 'Credit Hours [PDF]' );
    var second = markup.findLinkAt( line, line.length - 6, line.length - 6 );
    equal( second.label, 'a \\[b\\]' );
    equal( markup.unescapeLabel( second.label ), 'a [b]' );
} );

test( 'Changing the address keeps the label exactly and encodes the new one', function () {
    equal( markup.relink( 'Credit Hours', '/x/' ), '[Credit Hours](/x/)' );
    equal( markup.relink( 'a \\[b\\]', 'https://x.org/a b' ), '[a \\[b\\]](https://x.org/a%20b)' );
} );

test( 'An address with spaces around it still counts, as the parser allows', function () {
    equal( markup.findLinkAt( '[A]( /a/ )', 2, 2 ).url, '/a/' );
} );

results.forEach( function ( r ) {
    console.log( ( r.ok ? 'PASS' : 'FAIL' ) + ' — ' + r.name );
    if ( ! r.ok ) console.log( '       ' + r.error.replace( /\n/g, '\n       ' ) );
} );

var failed = results.filter( function ( r ) { return ! r.ok; } ).length;
console.log( '\n' + ( results.length - failed ) + ' passed, ' + failed + ' failed (of ' + results.length + ')' );
process.exit( failed ? 1 : 0 );
