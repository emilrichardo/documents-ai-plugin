/**
 * The smallest DOM the extractor modules actually use.
 *
 * aidocs-docx-structure.js walks the HTML mammoth produces, so testing it
 * needs a DOM. There is no jsdom in this repo and no package.json to add one
 * to, so this is a hand-written stand-in for exactly what that module
 * touches: createElement, innerHTML, childNodes/children, tagName,
 * textContent, getAttribute, appendChild, cloneNode and querySelectorAll.
 * It is a test fixture, not a browser — it parses the well-formed HTML
 * mammoth emits and nothing more.
 *
 * Requiring this module installs `global.document`, which is what the
 * modules under test look for.
 */

'use strict';

var VOID_TAGS = { br: 1, img: 1, hr: 1, input: 1 };

function TextNode( text ) {
    this.nodeType    = 3;
    this.textContent = text;
}
TextNode.prototype.cloneNode = function () { return new TextNode( this.textContent ); };

function Element( tag ) {
    this.nodeType   = 1;
    this.tagName    = tag.toUpperCase();
    this.attributes = {};
    this.childNodes = [];
}
Object.defineProperty( Element.prototype, 'children', {
    get: function () {
        return this.childNodes.filter( function ( n ) { return n.nodeType === 1; } );
    }
} );
Object.defineProperty( Element.prototype, 'textContent', {
    get: function () {
        return this.childNodes.map( function ( n ) { return n.textContent; } ).join( '' );
    }
} );
Object.defineProperty( Element.prototype, 'innerHTML', {
    set: function ( html ) { this.childNodes = parseHtml( html ); }
} );
Element.prototype.getAttribute = function ( name ) {
    return Object.prototype.hasOwnProperty.call( this.attributes, name ) ? this.attributes[ name ] : null;
};
Element.prototype.appendChild = function ( node ) { this.childNodes.push( node ); return node; };
Element.prototype.cloneNode = function () {
    var copy = new Element( this.tagName );
    copy.attributes = Object.assign( {}, this.attributes );
    copy.childNodes = this.childNodes.map( function ( n ) { return n.cloneNode( true ); } );
    return copy;
};
Element.prototype.querySelectorAll = function ( selector ) {
    var wanted = selector.split( ',' ).map( function ( s ) { return s.trim().toUpperCase(); } );
    var found  = [];
    ( function walk( node ) {
        node.childNodes.forEach( function ( child ) {
            if ( child.nodeType !== 1 ) return;
            if ( wanted.indexOf( child.tagName ) !== -1 ) found.push( child );
            walk( child );
        } );
    } )( this );
    return found;
};

var TAG_RE = /<(\/?)([a-zA-Z][a-zA-Z0-9]*)((?:\s+[a-zA-Z-]+\s*=\s*"[^"]*")*)\s*(\/?)>/g;

function decode( text ) {
    return text.replace( /&lt;/g, '<' ).replace( /&gt;/g, '>' )
               .replace( /&quot;/g, '"' ).replace( /&#39;/g, "'" )
               .replace( /&amp;/g, '&' );
}

function parseHtml( html ) {
    var root  = new Element( 'div' );
    var stack = [ root ];
    var last  = 0;
    var match;

    TAG_RE.lastIndex = 0;
    while ( ( match = TAG_RE.exec( html ) ) !== null ) {
        if ( match.index > last ) {
            stack[ stack.length - 1 ].appendChild( new TextNode( decode( html.slice( last, match.index ) ) ) );
        }
        last = TAG_RE.lastIndex;

        var closing = match[ 1 ] === '/';
        var tag     = match[ 2 ].toLowerCase();

        if ( closing ) {
            if ( stack.length > 1 ) stack.pop();
            continue;
        }

        var el = new Element( tag );
        ( match[ 3 ] || '' ).replace( /([a-zA-Z-]+)\s*=\s*"([^"]*)"/g, function ( _, name, value ) {
            el.attributes[ name ] = decode( value );
            return '';
        } );
        stack[ stack.length - 1 ].appendChild( el );
        if ( ! VOID_TAGS[ tag ] && match[ 4 ] !== '/' ) stack.push( el );
    }
    if ( html.length > last ) {
        stack[ stack.length - 1 ].appendChild( new TextNode( decode( html.slice( last ) ) ) );
    }
    return root.childNodes;
}

global.document = { createElement: function ( tag ) { return new Element( tag ); } };


global.document = { createElement: function ( tag ) { return new Element( tag ); } };

module.exports = { Element: Element, TextNode: TextNode, parseHtml: parseHtml };
