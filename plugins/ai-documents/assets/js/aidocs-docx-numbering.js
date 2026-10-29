/**
 * Word's own numbering, read straight from the .docx.
 *
 * mammoth.js hands back <ol> and <ul> and nothing else: a list Word formats
 * as "a. b. c." or "i. ii. iii." arrives as a bare <ol>, and the extractor
 * had no choice but to write "1. 2. 3." for all of them. Measured across the
 * four source compilations, 395 of 1,623 numbered paragraphs use a format
 * that is not decimal — so the published page disagreed with the Word
 * document about how a quarter of its lists are labelled.
 *
 * The format lives in word/numbering.xml, which mammoth reads and does not
 * expose. This module opens the .docx a second time and reads it directly.
 *
 *   - The archive is unpacked with DecompressionStream('deflate-raw'), which
 *     every supported browser and Node 18+ provide, rather than by adding a
 *     zip library to a plugin that vendors its dependencies by hand.
 *   - The two XML parts are read with regular expressions rather than
 *     DOMParser. That is normally a mistake; here the input is
 *     machine-written OOXML with a fixed shape, only four attributes are
 *     wanted, and it keeps the module runnable under Node so the tests can
 *     exercise it against the real documents.
 *
 * What comes back is an index from a paragraph's text to the marker Word
 * would print in front of it. Matching on text, rather than on position, is
 * deliberate: mammoth merges and nests lists on its own rules, and a marker
 * attached to the wrong item is worse than no marker at all. Text that
 * appears twice in one document is dropped from the index for the same
 * reason — see lookup().
 *
 * Usable from the browser (window.AidocsDocxNumbering) and from Node.
 */
( function ( root, factory ) {
    if ( typeof module === 'object' && module.exports ) {
        module.exports = factory();
    } else {
        root.AidocsDocxNumbering = factory();
    }
}( typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    /** Word's numFmt values, in the canonical grammar's names. */
    var FORMATS = {
        decimal:     'decimal',
        lowerLetter: 'lower-alpha',
        upperLetter: 'upper-alpha',
        lowerRoman:  'lower-roman',
        upperRoman:  'upper-roman',
        bullet:      'bullet'
    };

    // ── The archive ─────────────────────────────────────────────────────

    function readU16( view, at ) { return view.getUint16( at, true ); }
    function readU32( view, at ) { return view.getUint32( at, true ); }

    /**
     * Find one file in a zip and return its bytes.
     *
     * Walks the end-of-central-directory record back to the central
     * directory, which is the only reliable way to locate an entry: a local
     * header may carry zeroed sizes with the real ones in a trailing data
     * descriptor.
     *
     * @param {ArrayBuffer} buffer
     * @param {string} wanted Path inside the archive.
     * @return {Promise<Uint8Array|null>}
     */
    function readZipEntry( buffer, wanted ) {
        var bytes = new Uint8Array( buffer );
        var view  = new DataView( buffer );

        // The EOCD is at the end, after a comment of up to 64 KiB.
        var eocd = -1;
        var floor = Math.max( 0, bytes.length - 65557 );
        for ( var i = bytes.length - 22; i >= floor; i-- ) {
            if ( readU32( view, i ) === 0x06054b50 ) { eocd = i; break; }
        }
        if ( eocd < 0 ) return Promise.resolve( null );

        var count  = readU16( view, eocd + 10 );
        var offset = readU32( view, eocd + 16 );

        for ( var n = 0, at = offset; n < count && at + 46 <= bytes.length; n++ ) {
            if ( readU32( view, at ) !== 0x02014b50 ) break;
            var method    = readU16( view, at + 10 );
            var sizeComp  = readU32( view, at + 20 );
            var nameLen   = readU16( view, at + 28 );
            var extraLen  = readU16( view, at + 30 );
            var commentLen = readU16( view, at + 32 );
            var localAt   = readU32( view, at + 42 );
            var name      = decodeUtf8( bytes.subarray( at + 46, at + 46 + nameLen ) );
            at += 46 + nameLen + extraLen + commentLen;

            if ( name !== wanted ) continue;

            // The local header repeats the name and extra fields, and its
            // extra-field length may differ from the central directory's.
            var localNameLen  = readU16( view, localAt + 26 );
            var localExtraLen = readU16( view, localAt + 28 );
            var dataAt = localAt + 30 + localNameLen + localExtraLen;
            var data   = bytes.subarray( dataAt, dataAt + sizeComp );

            if ( method === 0 ) return Promise.resolve( data );
            if ( method !== 8 ) return Promise.resolve( null );
            return inflateRaw( data );
        }
        return Promise.resolve( null );
    }

    function inflateRaw( data ) {
        if ( typeof DecompressionStream === 'undefined' ) return Promise.resolve( null );
        var stream = new Blob( [ data ] ).stream().pipeThrough( new DecompressionStream( 'deflate-raw' ) );
        return new Response( stream ).arrayBuffer().then( function ( out ) {
            return new Uint8Array( out );
        } );
    }

    function decodeUtf8( bytes ) {
        if ( typeof TextDecoder !== 'undefined' ) return new TextDecoder( 'utf-8' ).decode( bytes );
        var out = '';
        for ( var i = 0; i < bytes.length; i++ ) out += String.fromCharCode( bytes[ i ] );
        return out;
    }

    // ── numbering.xml ───────────────────────────────────────────────────

    /**
     * numId → level → { style, start }.
     *
     * Two hops: <w:num> points at an <w:abstractNum>, and the levels live on
     * the latter. A <w:lvlOverride> on the <w:num> wins where present.
     */
    function readNumbering( xml ) {
        var abstracts = {};
        var reAbs = /<w:abstractNum[^>]*w:abstractNumId="(\d+)"[^>]*>([\s\S]*?)<\/w:abstractNum>/g;
        var m;
        while ( ( m = reAbs.exec( xml ) ) ) {
            abstracts[ m[ 1 ] ] = readLevels( m[ 2 ] );
        }

        var nums = {};
        var reNum = /<w:num[ >][^>]*w:numId="(\d+)"[^>]*>([\s\S]*?)<\/w:num>/g;
        while ( ( m = reNum.exec( xml ) ) ) {
            var numId = m[ 1 ];
            var body  = m[ 2 ];
            var ref   = /<w:abstractNumId[^>]*w:val="(\d+)"/.exec( body );
            var levels = ref && abstracts[ ref[ 1 ] ] ? cloneLevels( abstracts[ ref[ 1 ] ] ) : {};
            var reOv = /<w:lvlOverride[^>]*w:ilvl="(\d+)"[^>]*>([\s\S]*?)<\/w:lvlOverride>/g;
            var o;
            while ( ( o = reOv.exec( body ) ) ) {
                var over = readLevels( o[ 2 ] )[ o[ 1 ] ] || readLevels( o[ 2 ] )[ '0' ];
                var startOnly = /<w:startOverride[^>]*w:val="(\d+)"/.exec( o[ 2 ] );
                if ( over ) levels[ o[ 1 ] ] = over;
                if ( startOnly ) {
                    levels[ o[ 1 ] ] = levels[ o[ 1 ] ] || { style: 'decimal', start: 1 };
                    levels[ o[ 1 ] ].start = parseInt( startOnly[ 1 ], 10 );
                }
            }
            nums[ numId ] = levels;
        }
        return nums;
    }

    function readLevels( xml ) {
        var levels = {};
        var reLvl = /<w:lvl[ >][^>]*w:ilvl="(\d+)"[^>]*>([\s\S]*?)<\/w:lvl>/g;
        var m;
        while ( ( m = reLvl.exec( xml ) ) ) {
            var body  = m[ 2 ];
            var fmt   = /<w:numFmt[^>]*w:val="([^"]+)"/.exec( body );
            var start = /<w:start[^>]*w:val="(-?\d+)"/.exec( body );
            levels[ m[ 1 ] ] = {
                style: ( fmt && FORMATS[ fmt[ 1 ] ] ) || 'decimal',
                start: start ? parseInt( start[ 1 ], 10 ) : 1
            };
        }
        return levels;
    }

    function cloneLevels( levels ) {
        var copy = {};
        Object.keys( levels ).forEach( function ( k ) {
            copy[ k ] = { style: levels[ k ].style, start: levels[ k ].start };
        } );
        return copy;
    }

    // ── document.xml ────────────────────────────────────────────────────

    /** The visible text of one <w:p>, as mammoth would render it. */
    function paragraphText( xml ) {
        var out = '';
        var re  = /<w:t(?:\s[^>]*)?>([\s\S]*?)<\/w:t>|<w:tab\s*\/>|<w:br\s*\/>/g;
        var m;
        while ( ( m = re.exec( xml ) ) ) {
            out += m[ 1 ] === undefined ? ' ' : unescapeXml( m[ 1 ] );
        }
        return out;
    }

    function unescapeXml( text ) {
        return text
            .replace( /&lt;/g, '<' ).replace( /&gt;/g, '>' )
            .replace( /&quot;/g, '"' ).replace( /&apos;/g, "'" )
            .replace( /&#(\d+);/g, function ( _, d ) { return String.fromCharCode( parseInt( d, 10 ) ); } )
            .replace( /&amp;/g, '&' );
    }

    /**
     * The key a paragraph is indexed under.
     *
     * Case and whitespace are normalised away because mammoth re-flows runs;
     * everything else is left alone so two genuinely different items cannot
     * collide. Capped, because a key is only ever compared for equality and
     * a 2 KB list item is not more distinctive than its first 200 characters.
     */
    function keyFor( text ) {
        return String( text == null ? '' : text )
            .replace( /\s+/g, ' ' )
            .trim()
            .toLowerCase()
            .slice( 0, 200 );
    }

    /**
     * Walk the body in order, counting each level as Word does.
     *
     * A paragraph at level L restarts every level below it, which is Word's
     * default lvlRestart and the reason a sub-list begins at 1 again under
     * each parent item.
     */
    function indexParagraphs( documentXml, numbering ) {
        var index   = {};
        var counters = {};   // numId → ilvl → next ordinal

        var reP = /<w:p[ >][\s\S]*?<\/w:p>/g;
        var m;
        while ( ( m = reP.exec( documentXml ) ) ) {
            var para = m[ 0 ];
            var numPr = /<w:numPr>([\s\S]*?)<\/w:numPr>/.exec( para );
            if ( ! numPr ) continue;

            var ilvlM  = /<w:ilvl[^>]*w:val="(\d+)"/.exec( numPr[ 1 ] );
            var numIdM = /<w:numId[^>]*w:val="(\d+)"/.exec( numPr[ 1 ] );
            if ( ! numIdM ) continue;
            var ilvl  = ilvlM ? ilvlM[ 1 ] : '0';
            var numId = numIdM[ 1 ];

            var level = ( numbering[ numId ] && numbering[ numId ][ ilvl ] ) || { style: 'decimal', start: 1 };

            counters[ numId ] = counters[ numId ] || {};
            if ( counters[ numId ][ ilvl ] === undefined ) counters[ numId ][ ilvl ] = level.start;
            var ordinal = counters[ numId ][ ilvl ]++;

            Object.keys( counters[ numId ] ).forEach( function ( other ) {
                if ( parseInt( other, 10 ) <= parseInt( ilvl, 10 ) ) return;
                var deeper = ( numbering[ numId ] && numbering[ numId ][ other ] ) || { start: 1 };
                counters[ numId ][ other ] = deeper.start;
            } );

            var key = keyFor( paragraphText( para ) );
            if ( key === '' ) continue;
            var entry = { style: level.style, ordinal: ordinal, level: parseInt( ilvl, 10 ) };

            // Text that appears more than once in a compilation of 49
            // policies is common — standards are quoted verbatim in several
            // of them. 124 of 1,239 keys repeat. Dropping all of them loses
            // a marker the duplicates agree about, so they are merged
            // instead: identical entries stay whole, entries that agree on
            // the format keep it and give up the count, and entries that
            // disagree on the format are the only ones dropped (24 of 124).
            if ( Object.prototype.hasOwnProperty.call( index, key ) ) {
                var prior = index[ key ];
                if ( prior === null ) continue;
                if ( prior.style !== entry.style ) { index[ key ] = null; continue; }
                if ( prior.ordinal !== entry.ordinal ) index[ key ] = { style: prior.style, ordinal: null, level: prior.level };
                continue;
            }
            index[ key ] = entry;
        }

        Object.keys( index ).forEach( function ( key ) {
            if ( index[ key ] === null ) delete index[ key ];
        } );
        return index;
    }

    // ── What callers use ────────────────────────────────────────────────

    /**
     * Read a .docx's numbering.
     *
     * Never rejects: a document with no numbering.xml, an archive this
     * reader cannot open, or a runtime without DecompressionStream all
     * produce an index that answers null to everything, and the extractor
     * falls back to the numbering it has always written.
     *
     * @param {ArrayBuffer} arrayBuffer
     * @return {Promise<{lookup: function(string): ?Object, size: number}>}
     */
    function read( arrayBuffer ) {
        var empty = { lookup: function () { return null; }, size: 0 };
        return readZipEntry( arrayBuffer, 'word/numbering.xml' ).then( function ( numBytes ) {
            if ( ! numBytes ) return empty;
            return readZipEntry( arrayBuffer, 'word/document.xml' ).then( function ( docBytes ) {
                if ( ! docBytes ) return empty;
                var numbering = readNumbering( decodeUtf8( numBytes ) );
                var index     = indexParagraphs( decodeUtf8( docBytes ), numbering );
                return {
                    size: Object.keys( index ).length,
                    lookup: function ( text ) {
                        var key = keyFor( text );
                        return Object.prototype.hasOwnProperty.call( index, key ) ? index[ key ] : null;
                    }
                };
            } );
        } ).catch( function () { return empty; } );
    }

    return {
        read:        read,
        keyFor:      keyFor,
        // Exposed for the tests, which check the pieces separately.
        readNumbering:   readNumbering,
        indexParagraphs: indexParagraphs,
        paragraphText:   paragraphText
    };
} ) );
