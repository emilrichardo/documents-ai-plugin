/**
 * .docx → canonical policy text.
 *
 * The PDF path (aidocs-pdf-structure.js) has to reverse-engineer structure
 * from layout — font weight, point size, margins — because a PDF's text
 * layer keeps none of it. A .docx file needs none of that: mammoth.js
 * (vendored at assets/js/vendor/mammoth.browser.min.js) already reads Word's
 * own styles and hands back semantic HTML (<h1>-<h6>, <p>, <ul>/<ol>/<li>,
 * <table>, <strong>/<em>). This module only has to walk that HTML and emit
 * the same canonical grammar the PDF path does — documented in
 * EXTRACTION_FORMAT.md — so includes/aidocs-doc-parser.php needs no changes
 * at all to read either source.
 *
 *     ## HEADING                  <h2>
 *     ### Heading                 <h3>
 *     #### Heading                <h4> and deeper
 *     Plain paragraph on one line, never hard-wrapped.
 *     1. ordered item             a list level is two spaces of indent
 *       a. nested item
 *     - bulleted item
 *     | cell | cell |             table row
 *     **bold** and *italic* inline runs
 *     [visible text](https://…)   a hyperlink
 *
 * Usable from the browser (window.AidocsDocxStructure) and from Node, same
 * as the PDF module, so it can be checked against sample files offline.
 */
( function ( root, factory ) {
    if ( typeof module === 'object' && module.exports ) {
        module.exports = factory();
    } else {
        root.AidocsDocxStructure = factory();
    }
}( typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    // The document-level labels, authored as bold or plain paragraphs and
    // matched here the same way the PDF path's canonical text is later
    // matched by the server-side parser — see LABEL_RE in
    // aidocs-pdf-structure.js and AIDOCS_DOC_LABELS in aidocs-doc-parser.php.
    var LABEL_RE = /^(Teaser|Body|Last Updated|Document History|Adopted|Approved|Revised|Edited|Reformatted|Updated)\b\s*:?/i;

    function escapeMarkers( text ) {
        return text.replace( /([*\\])/g, '\\$1' );
    }

    /** Inside a link's own text, the brackets have to be escaped as well. */
    function escapeLinkText( text ) {
        return text.replace( /([*\\\[\]])/g, '\\$1' );
    }

    /**
     * A hyperlink's destination, as it should appear in the canonical text.
     *
     * Nothing is judged here. mammoth gives back whatever Word stored —
     * an external site, a leftover link to a file on the previous site, a
     * bookmark inside the document — and all three are written out the same
     * way. Which of them is worth keeping is decided in exactly one place,
     * aidocs_classify_link() in includes/aidocs-doc-parser.php, on the server
     * that also renders them; duplicating that judgement here would mean two
     * sets of rules that could disagree, and would throw away the original
     * URL before an editor could ever see it in "Edit content".
     *
     * A URL holding whitespace or a parenthesis cannot be written in this
     * grammar, so such a link is emitted as its text alone.
     */
    function linkHref( el ) {
        var href = ( el.getAttribute( 'href' ) || '' ).trim();
        if ( href === '' ) return '';               // <a id="…"> — a bookmark target, not a link
        if ( /[\s()<>"']/.test( href ) ) return '';
        return href;
    }

    function tidy( text ) {
        return text.replace( /\s+/g, ' ' ).trim();
    }

    /**
     * Inline text of a node, with **bold** / *italic* markup for emphasis runs
     * and [text](url) for hyperlinks.
     *
     * @param {Node}    node
     * @param {boolean} inLink Whether this call is already inside an <a>, in
     *                         which case square brackets in the text need
     *                         escaping too so they cannot close the link early.
     */
    function inline( node, inLink ) {
        var out = '';
        ( node.childNodes || [] ).forEach( function ( child ) {
            if ( child.nodeType === 3 ) { // Text
                out += inLink ? escapeLinkText( child.textContent ) : escapeMarkers( child.textContent );
                return;
            }
            if ( child.nodeType !== 1 ) return; // skip comments etc.
            var tag  = child.tagName.toLowerCase();
            var text = inline( child, inLink || tag === 'a' );
            if ( text === '' ) return;

            if ( tag === 'br' ) { out += ' '; return; }

            if ( tag === 'a' ) {
                var href = linkHref( child );
                if ( href === '' ) { out += text; return; }
                // Leading and trailing spaces stay outside the brackets, so a
                // link never renders with padding inside its own underline.
                var pre  = text.match( /^\s*/ )[ 0 ];
                var post = text.match( /\s*$/ )[ 0 ];
                var body = text.slice( pre.length, text.length - post.length );
                if ( body === '' ) { out += text; return; }
                out += pre + '[' + body + '](' + href + ')' + post;
                return;
            }

            var bold   = tag === 'strong' || tag === 'b';
            var italic = tag === 'em' || tag === 'i';
            if ( ! bold && ! italic ) { out += text; return; }

            var lead = text.match( /^\s*/ )[ 0 ];
            var tail = text.match( /\s*$/ )[ 0 ];
            var core = text.slice( lead.length, text.length - tail.length );
            if ( core === '' ) { out += text; return; }
            var mark = bold ? '**' : '*';
            out += lead + mark + core + mark + tail;
        } );
        return out;
    }

    function plainText( node ) {
        return tidy( node.textContent || '' );
    }

    function repeat( text, times ) {
        var out = '';
        for ( var i = 0; i < times; i++ ) out += text;
        return out;
    }

    /** Bullets are all written as "-"; ordered markers get real numbering. */
    function listMarker( ordered, index ) {
        return ordered ? ( index + 1 ) + '.' : '-';
    }

    /**
     * Walk a <ul>/<ol>, emitting its items at the given nesting depth (1-based,
     * matching the PDF path's two-space-per-level indent).
     */
    function renderList( listEl, depth, out ) {
        var ordered = listEl.tagName.toLowerCase() === 'ol';
        var index   = 0;
        Array.prototype.forEach.call( listEl.children, function ( li ) {
            if ( li.tagName.toLowerCase() !== 'li' ) return;
            var marker = listMarker( ordered, index++ );
            var indent = repeat( '  ', depth - 1 );

            // A list item's own text is whatever is not itself a nested list —
            // mammoth nests a sub-list as a child <ul>/<ol> inside the <li>,
            // alongside (not instead of) its own text node content.
            var nested = [];
            var textNode = document.createElement( 'div' );
            Array.prototype.forEach.call( li.childNodes, function ( child ) {
                if ( child.nodeType === 1 && /^(ul|ol)$/i.test( child.tagName ) ) {
                    nested.push( child );
                } else {
                    textNode.appendChild( child.cloneNode( true ) );
                }
            } );

            var text = tidy( inline( textNode ) );
            if ( text !== '' ) {
                out.push( indent + marker + ' ' + text );
            }
            nested.forEach( function ( sub ) { renderList( sub, depth + 1, out ); } );
        } );
    }

    function renderTable( tableEl, out ) {
        Array.prototype.forEach.call( tableEl.querySelectorAll( 'tr' ), function ( tr ) {
            var cells = Array.prototype.map.call(
                tr.querySelectorAll( 'th,td' ),
                function ( cell ) { return tidy( inline( cell ) ); }
            );
            if ( cells.length ) out.push( '| ' + cells.join( ' | ' ) + ' |' );
        } );
    }

    var HEADING_RE = /^h([1-6])$/i;

    /**
     * Turn mammoth's HTML output into canonical text.
     *
     * @param {string} html
     * @return {string}
     */
    function htmlToCanonical( html ) {
        var container = document.createElement( 'div' );
        container.innerHTML = html;

        var out       = [];
        var sawTitle  = false;
        var sawLabel  = false;

        Array.prototype.forEach.call( container.children, function ( el ) {
            var tag = el.tagName.toLowerCase();

            if ( HEADING_RE.test( tag ) ) {
                var text = tidy( inline( el ) );
                if ( text === '' ) return;
                // The very first heading in the document is its title — same
                // "first heading wins level 1" rule the PDF path applies —
                // everything after maps h2→##, h3→###, h4 and deeper→####.
                var level;
                if ( ! sawTitle && ! sawLabel ) {
                    level = 1;
                    sawTitle = true;
                } else {
                    var n = parseInt( HEADING_RE.exec( tag )[ 1 ], 10 );
                    level = n <= 2 ? 2 : ( n === 3 ? 3 : 4 );
                }
                out.push( repeat( '#', level ) + ' ' + text );
                return;
            }

            if ( tag === 'p' ) {
                var raw = plainText( el );
                if ( raw === '' ) return;

                // A document-level label ("Teaser:", "Body:", "Last Updated:",
                // "Document History:") starts its own canonical line, written
                // without the bold it's usually authored in, exactly like the
                // PDF path — so the label schema matches regardless of source.
                if ( LABEL_RE.test( raw ) ) {
                    sawLabel = true;
                    out.push( inline( el ).replace( /\*\*/g, '' ) );
                    return;
                }
                out.push( tidy( inline( el ) ) );
                return;
            }

            if ( tag === 'ul' || tag === 'ol' ) {
                renderList( el, 1, out );
                return;
            }

            if ( tag === 'table' ) {
                renderTable( el, out );
                return;
            }

            // Anything else (mammoth's own wrapper elements, images, etc.) —
            // fall back to its plain text as a paragraph rather than dropping
            // content the author put there silently.
            var fallback = tidy( inline( el ) );
            if ( fallback !== '' ) out.push( fallback );
        } );

        return out.join( '\n' );
    }

    /**
     * Extract a whole .docx as canonical text.
     *
     * @param {ArrayBuffer} arrayBuffer
     * @return {Promise<{text: string, pages: string[]}>} `pages` always holds
     *         the whole text as a single entry — .docx carries no page
     *         boundaries mammoth exposes, unlike the PDF path.
     */
    function extract( arrayBuffer ) {
        if ( typeof mammoth === 'undefined' ) {
            return Promise.reject( new Error( 'mammoth.js not loaded' ) );
        }
        return mammoth.convertToHtml( { arrayBuffer: arrayBuffer } ).then( function ( result ) {
            var text = htmlToCanonical( result.value );
            return { text: text, pages: [ text ] };
        } );
    }

    return {
        extract:         extract,
        htmlToCanonical: htmlToCanonical
    };
} ) );
