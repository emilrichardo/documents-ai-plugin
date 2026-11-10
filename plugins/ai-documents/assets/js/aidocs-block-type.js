/**
 * What kind of block a line of the canonical text is, and the way to change it.
 *
 * In the editor a paragraph and an h4 look alike, and neither is marked, so an
 * editor cannot tell by looking which of the two a line is — and a bold line
 * is read as a section heading without anything saying so. The toolbar's
 * block-type picker uses this to say what the line under the cursor is and to
 * rewrite its "#" prefix.
 *
 * Free of DOM and of EasyMDE so it can be exercised from Node — see
 * tests/test-block-type.js. Usable from the browser (window.AidocsBlockType).
 */
( function ( root, factory ) {
    if ( typeof module === 'object' && module.exports ) {
        module.exports = factory();
    } else {
        root.AidocsBlockType = factory();
    }
}( typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    /**
     * @param {string} text One line of canonical text.
     * @return {{key:string,label:string,locked:boolean}} `locked` is true for
     *         lines the picker shows but does not change: a list item or table
     *         row would lose its marker if turned into a heading.
     */
    function classify( text ) {
        var t = String( text == null ? '' : text );
        var h = /^(#{1,6})\s/.exec( t );
        if ( h ) {
            var n = Math.min( h[ 1 ].length, 4 );
            return { key: 'h' + n, label: '', locked: false };
        }
        if ( /^\s*$/.test( t ) )                  return { key: 'blank',    label: 'Empty line',           locked: true };
        if ( /^\s*\|.*\|\s*$/.test( t ) )         return { key: 'table',    label: 'Table row',            locked: true };
        if ( /^\s*[-*•]\s/.test( t ) && ! /^\s*\*\*/.test( t ) ) {
            return { key: 'bullet', label: 'Bulleted list', locked: true };
        }
        if ( /^\s*(?:\d{1,3}[.)]|[a-zA-Z][.)]|[ivxlcIVXLC]{2,7}[.)])\s/.test( t ) ) {
            return { key: 'number', label: 'Numbered list', locked: true };
        }
        // A line in bold from end to end is read as a section heading.
        if ( /^\*\*[^*].*\*\*\s*$/.test( t ) )    return { key: 'boldline', label: 'Heading 3 (bold line)', locked: true };
        return { key: 'p', label: 'Paragraph', locked: false };
    }

    /**
     * The line, made a paragraph ("p") or a heading ("h1"…"h4"). A line that
     * cannot be changed comes back as it was.
     *
     * @param {string} text
     * @param {string} key  "p", "h1", "h2", "h3" or "h4".
     * @return {string}
     */
    function restyle( text, key ) {
        var line = String( text == null ? '' : text );
        if ( classify( line ).locked ) return line;
        var level = /^h([1-4])$/.exec( key );
        var bare  = line.replace( /^#{1,6}\s+/, '' );
        return level ? new Array( parseInt( level[ 1 ], 10 ) + 1 ).join( '#' ) + ' ' + bare : bare;
    }

    return { classify: classify, restyle: restyle };
} ) );
