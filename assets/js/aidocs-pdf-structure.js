/**
 * PDF → canonical policy text.
 *
 * The policy documents are authored in Word and exported to PDF, so every
 * structural decision the author made survives as layout: the weight of the
 * font (headings and note labels are bold), the point size (a few documents use
 * 14pt/16pt for major sections), the left edge of the line (each list level is
 * indented one step further than the last) and the vertical gap above it (a new
 * paragraph is spaced, a wrapped line is not).
 *
 * A plain text layer throws all of that away, which is why the old extractor
 * could only guess at what was a heading. This module keeps the layout facts
 * that only the PDF knows and writes them back into the text as markers the
 * server-side parser can match with regular expressions:
 *
 *     ## HEADING                  level-2 heading (all caps / 15pt+)
 *     ### Heading                 level-3 heading (bold, at the body margin)
 *     #### Heading                level-4 heading (bold, indented)
 *     Plain paragraph on one line, never hard-wrapped.
 *     1. ordered item             a list level is two spaces of indent
 *       a. nested item
 *         i. deeper item
 *     - bulleted item
 *       continuation paragraph of the item above (indented, no marker)
 *     | cell | cell |             table row
 *     **bold** and *italic* inline runs
 *
 * Everything else — which of those blocks is a note, where the teaser ends,
 * how a note label differs from a section heading — is left to the parser, so
 * the same rules also apply to text pasted in by hand.
 *
 * Usable from the browser (window.AidocsPdfStructure) and from Node, so the
 * extraction can be checked against a corpus of real documents offline.
 */
( function ( root, factory ) {
    if ( typeof module === 'object' && module.exports ) {
        module.exports = factory();
    } else {
        root.AidocsPdfStructure = factory();
    }
}( typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    // Running headers and footers sit inside these bands. Kept deliberately
    // narrow: the authored documents put "Last Updated:" and "Document
    // History:" at the very bottom of the last page, and a wider clip eats them.
    var HEADER_BAND = 0.965;
    var FOOTER_BAND = 0.035;

    // Two items on the same line are the same word when they touch, a space
    // apart when they are a fraction of the font size apart, and separate table
    // cells when the gap is wider than a couple of characters.
    var SPACE_GAP  = 0.22;   // × font size
    var COLUMN_GAP = 1.8;    // × font size

    // A line whose baseline is further than this below the previous one starts a
    // new paragraph rather than continuing the current one.
    var PARAGRAPH_GAP = 1.35; // × the document's median line pitch

    // Tolerance, in points, for treating two left edges as the same margin.
    var MARGIN_SLOP = 4;

    // List markers, as authored: "1." "12)" "a." "iv." "A." "•" "–" and the
    // Wingdings bullets Word emits for its second and third bullet levels.
    var MARKER_RE = /^(?:\d{1,2}[.)]|[a-zA-Z][.)]|(?:x{0,2}i{1,3}|iv|vi{0,3}|ix)[.)]|(?:X{0,2}I{1,3}|IV|VI{0,3}|IX)[.)]|[\u2022\u00b7\u25cf\u25aa\u25e6\u2043\u2023\u2013\uf0b7\uf06e\uf098\uf0a7\uf0fc])$/;

    // ─────────────────────────────────────────────
    // Font weight / slant
    // ─────────────────────────────────────────────

    /**
     * Map pdf.js font ids ("g_d0_f2") to weight and slant.
     *
     * getTextContent() only reports a generic fallback family, but the real
     * font object — which carries the embedded name, "Caladea-Bold" — is in
     * commonObjs once the operator list has been fetched. When that is not
     * available (a font without a name, an unusual producer) the flags come
     * back undefined and the caller falls back to geometry alone.
     */
    function fontFlags( page, styles ) {
        var map = {};
        Object.keys( styles || {} ).forEach( function ( id ) {
            var name = '';
            try {
                var font = page.commonObjs.get( id );
                name = ( font && ( font.name || font.loadedName ) ) || '';
                if ( font && ( font.bold || font.italic ) ) {
                    map[ id ] = { bold: !! font.bold, italic: !! font.italic, name: name };
                    return;
                }
            } catch ( e ) {
                name = '';
            }
            if ( ! name ) {
                map[ id ] = { bold: false, italic: false, name: '', unknown: true };
                return;
            }
            map[ id ] = {
                bold:   /bold|black|heavy|semib|demib/i.test( name ),
                italic: /italic|oblique/i.test( name ),
                name:   name
            };
        } );
        return map;
    }

    // ─────────────────────────────────────────────
    // Page → lines
    // ─────────────────────────────────────────────

    /**
     * Group a page's text items into lines carrying their layout.
     *
     * @return {Array} lines: {x, y, size, bold, italic, runs, cells, marker, markerX, contentX, text}
     */
    function pageLines( page, content, fonts ) {
        var viewport = page.getViewport( { scale: 1 } );
        var top      = viewport.height * HEADER_BAND;
        var bottom   = viewport.height * FOOTER_BAND;

        var items = [];
        ( content.items || [] ).forEach( function ( item ) {
            if ( ! item.str ) return;
            var y = item.transform[ 5 ];
            if ( y < bottom || y > top ) return;
            var style = fonts[ item.fontName ] || {};
            items.push( {
                // A whitespace-only item is a real space the producer wrote
                // between two styled runs. Dropping it welds "…June 2026" onto
                // "(Board of Trustees)", so it is kept as a space of its own.
                str:    item.str,
                space:  item.str.trim() === '',
                x:      item.transform[ 4 ],
                y:      y,
                width:  item.width || 0,
                size:   item.height || 0,
                bold:   !! style.bold,
                italic: !! style.italic
            } );
        } );

        items.sort( function ( a, b ) {
            return b.y - a.y || a.x - b.x;
        } );

        // Cluster by baseline. Superscripts and the odd re-positioned glyph sit
        // a point or two off the line they belong to, so the tolerance scales
        // with the font size rather than being a fixed pixel count.
        var groups = [];
        items.forEach( function ( item ) {
            var last = groups[ groups.length - 1 ];
            var slop = Math.max( 2, ( item.size || 12 ) * 0.35 );
            if ( last && Math.abs( last.y - item.y ) <= slop ) {
                last.items.push( item );
                return;
            }
            groups.push( { y: item.y, items: [ item ] } );
        } );

        return groups.map( function ( group ) {
            group.items.sort( function ( a, b ) { return a.x - b.x; } );
            return buildLine( group );
        } ).filter( function ( line ) {
            return line && line.text !== '';
        } );
    }

    /**
     * Assemble one line: its runs, its cells and — when it opens a list item —
     * the marker and the left edge of the text that follows it.
     */
    function buildLine( group ) {
        var items = group.items;
        var words = items.filter( function ( item ) { return ! item.space; } );
        if ( ! words.length ) return null;

        var size = 0;
        words.forEach( function ( item ) { if ( item.size > size ) size = item.size; } );
        if ( ! size ) size = 12;

        var runs  = [];
        var cells = [ { text: '', runs: [] } ];
        var prev  = null;

        items.forEach( function ( item ) {
            if ( item.space ) {
                if ( prev && ! /\s$/.test( cellText( cells ) ) ) pushRun( runs, cells, ' ', prev );
                return;
            }
            if ( prev ) {
                var gap = item.x - ( prev.x + prev.width );
                if ( gap > size * COLUMN_GAP ) {
                    cells.push( { text: '', runs: [], x: item.x } );
                } else if ( gap > size * SPACE_GAP && ! /\s$/.test( cellText( cells ) ) ) {
                    pushRun( runs, cells, ' ', item );
                }
            }
            pushRun( runs, cells, item.str, item );
            prev = item;
        } );

        var line = {
            x:      words[ 0 ].x,
            y:      group.y,
            size:   size,
            runs:   trimRuns( runs ),
            cells:  cells.map( function ( cell ) {
                return { text: cell.text.replace( /\s+/g, ' ' ).trim(), runs: trimRuns( cell.runs ), x: cell.x };
            } ).filter( function ( cell ) { return cell.text !== ''; } )
        };
        line.text   = runsText( line.runs );
        line.bold   = line.runs.length > 0 && line.runs.every( function ( r ) { return r.bold || r.text.trim() === ''; } );
        line.italic = line.runs.length > 0 && line.runs.every( function ( r ) { return r.italic || r.text.trim() === ''; } );

        // A marker is its own item, set apart from the text it introduces by a
        // tab stop — "1." at x=84 with the text at x=108 — or, when the text
        // wrapped onto the next line, alone on the line.
        var first = words[ 0 ];
        var markerText = first.str.trim();
        if ( MARKER_RE.test( markerText ) ) {
            var rest = words.slice( 1 );
            var gapAfter = rest.length ? rest[ 0 ].x - ( first.x + first.width ) : Infinity;
            if ( gapAfter > size * 0.45 ) {
                line.marker   = markerText;
                line.markerX  = first.x;
                line.contentX = rest.length ? rest[ 0 ].x : first.x + size * 2;
                line.runs     = trimRuns( runsAfter( line.runs, markerText ) );
                line.text     = runsText( line.runs );
                line.bold     = line.runs.length > 0 && line.runs.every( function ( r ) { return r.bold || r.text.trim() === ''; } );
            }
        }

        return line;
    }

    function cellText( cells ) {
        return cells[ cells.length - 1 ].text;
    }

    function pushRun( runs, cells, text, item ) {
        var cell = cells[ cells.length - 1 ];
        [ runs, cell.runs ].forEach( function ( target ) {
            var last = target[ target.length - 1 ];
            if ( last && last.bold === item.bold && last.italic === item.italic ) {
                last.text += text;
            } else {
                target.push( { text: text, bold: item.bold, italic: item.italic } );
            }
        } );
        cell.text += text;
    }

    /** Drop a leading marker token from a line's runs. */
    function runsAfter( runs, marker ) {
        var out  = runs.map( function ( r ) { return { text: r.text, bold: r.bold, italic: r.italic }; } );
        var left = marker.length;
        while ( out.length && left > 0 ) {
            var head = out[ 0 ];
            var take = Math.min( left, head.text.length );
            head.text = head.text.slice( take );
            left -= take;
            if ( head.text.trim() === '' ) out.shift();
        }
        return out;
    }

    function trimRuns( runs ) {
        var out = runs.filter( function ( r ) { return r.text !== ''; } );
        if ( out.length ) {
            out[ 0 ] = { text: out[ 0 ].text.replace( /^\s+/, '' ), bold: out[ 0 ].bold, italic: out[ 0 ].italic };
            var last = out[ out.length - 1 ];
            out[ out.length - 1 ] = { text: last.text.replace( /\s+$/, '' ), bold: last.bold, italic: last.italic };
        }
        return out.filter( function ( r ) { return r.text !== ''; } );
    }

    function runsText( runs ) {
        return runs.map( function ( r ) { return r.text; } ).join( '' ).replace( /\s+/g, ' ' ).trim();
    }

    // ─────────────────────────────────────────────
    // Document statistics
    // ─────────────────────────────────────────────

    function mode( values ) {
        var counts = {}, best = null, bestN = 0;
        values.forEach( function ( value ) {
            var key = String( value );
            counts[ key ] = ( counts[ key ] || 0 ) + 1;
            if ( counts[ key ] > bestN ) { bestN = counts[ key ]; best = value; }
        } );
        return best;
    }

    function median( values ) {
        if ( ! values.length ) return 0;
        var sorted = values.slice().sort( function ( a, b ) { return a - b; } );
        return sorted[ Math.floor( sorted.length / 2 ) ];
    }

    /**
     * Work out the document's own conventions: where the body margin is, which
     * point size is body text, how far apart two lines of one paragraph sit,
     * and which left edges are used by list markers (each distinct edge is one
     * nesting level).
     */
    function documentMetrics( pages ) {
        var xs = [], sizes = [], pitches = [], markerXs = [];

        pages.forEach( function ( lines ) {
            var prev = null;
            lines.forEach( function ( line ) {
                sizes.push( Math.round( line.size ) );
                if ( line.marker ) markerXs.push( line.markerX );
                else xs.push( Math.round( line.x ) );
                if ( prev ) {
                    var gap = prev.y - line.y;
                    if ( gap > 4 && gap < 40 ) pitches.push( gap );
                }
                prev = line;
            } );
        } );

        var pitch = median( pitches ) || 14;

        // One cluster of marker edges per nesting level, left to right. An edge
        // seen only once is a stray "(p. 15)"-style reference rather than a real
        // level, so it does not get to shift everything below it down a level.
        var clusters = [];
        markerXs.sort( function ( a, b ) { return a - b; } ).forEach( function ( x ) {
            var last = clusters[ clusters.length - 1 ];
            if ( last && x - last.x <= 10 ) { last.count++; return; }
            clusters.push( { x: x, count: 1 } );
        } );
        var levels = clusters.filter( function ( cluster ) {
            return cluster.count > 1;
        } ).map( function ( cluster ) {
            return cluster.x;
        } ).slice( 0, 6 );

        return {
            baseX:     xs.length ? Math.min.apply( null, xs ) : 72,
            bodyX:     mode( xs ) || 72,
            bodySize:  mode( sizes ) || 12,
            pitch:     pitch,
            paraGap:   pitch * PARAGRAPH_GAP,
            markerXs:  levels
        };
    }

    function markerLevel( metrics, x ) {
        for ( var i = 0; i < metrics.markerXs.length; i++ ) {
            if ( Math.abs( metrics.markerXs[ i ] - x ) <= 10 ) return i + 1;
        }
        return 1;
    }

    // ─────────────────────────────────────────────
    // Lines → canonical text
    // ─────────────────────────────────────────────

    // The document-level labels, which are authored as bold text and must not
    // be mistaken for headings.
    var LABEL_RE = /^(Teaser|Body|Last Updated|Document History|Adopted|Approved|Revised|Edited|Reformatted|Updated)\b\s*:?/i;

    function escapeMarkers( text ) {
        return text.replace( /([*\\])/g, '\\$1' );
    }

    /**
     * Render runs as inline markdown, keeping emphasis outside the whitespace.
     *
     * @param {Array}   runs
     * @param {boolean} plainBold   Treat bold as unremarkable — used for
     *                              headings, which are bold by definition.
     * @param {boolean} plainItalic Same, for a heading set entirely in italics.
     */
    function inline( runs, plainBold, plainItalic ) {
        var out = '';
        runs.forEach( function ( run ) {
            var text   = escapeMarkers( run.text );
            var bold   = run.bold && ! plainBold;
            var italic = run.italic && ! plainItalic;
            if ( text.trim() === '' || ( ! bold && ! italic ) ) { out += text; return; }
            var lead  = text.match( /^\s*/ )[ 0 ];
            var tail  = text.match( /\s*$/ )[ 0 ];
            var core  = text.slice( lead.length, text.length - tail.length );
            var mark  = bold ? '**' : '*';
            out += core === '' ? text : lead + mark + core + mark + tail;
        } );
        return tidy( out );
    }

    /**
     * Normalise a finished line.
     *
     * A run that wrapped across two source lines arrives as two separate marked
     * spans — "the *Principles of* *Accreditation*" — so any emphasis that
     * closes and immediately reopens is welded back into a single span.
     */
    function tidy( text ) {
        return text
            .replace( /\s+/g, ' ' )
            // Whitespace between a closing and a reopening mark is what makes
            // this two spans of one run; without it the marks are a single
            // "**bold**" pair and must be left alone.
            .replace( /\*\*(\s+)\*\*/g, '$1' )
            .replace( /(^|[^*])\*(\s+)\*(?!\*)/g, '$1$2' )
            .trim();
    }

    /**
     * Does this canonical line read as complete?
     *
     * Used at page boundaries, where a sentence broken across the break has to
     * be joined back together and a finished one must not be.
     */
    function finished( line ) {
        if ( line === undefined || line === null || line === '' ) return true;
        if ( /^\s*(#|\|)/.test( line ) ) return true;
        return /[.!?:;”"')\]]\s*$/.test( line );
    }

    /** Join a wrapped line onto the previous one, keeping hyphenated words whole. */
    function joinWrapped( head, tail ) {
        if ( /[-‑]$/.test( head ) && /^[a-zà-ÿ]/.test( tail ) ) return head + tail;
        if ( head === '' ) return tail;
        return head + ' ' + tail;
    }

    function headingLevel( line, metrics ) {
        if ( line.size >= metrics.bodySize + 2.5 || isAllCaps( line.text ) ) return 2;
        if ( line.size >= metrics.bodySize + 1 ) return 3;
        return line.x <= metrics.baseX + MARGIN_SLOP ? 3 : 4;
    }

    /** Is this text set in all capitals — the signal for a document-level title? */
    /**
     * "The Commission" is this corpus's one recurring exception: the org's
     * name, substituted in a fixed mixed case wherever the source used its
     * own, including inside otherwise-all-caps titles — "AND ACTIONS OF The
     * Commission", "OF The Commission STAFF". Left in, that mid-case phrase
     * makes an all-caps title line read as mixed case, which then neither
     * takes the title's own heading level nor continues it. Excluded from
     * the check, the rest of the line still has to be genuinely all caps.
     */
    function isAllCaps( text ) {
        var letters = text.replace( /\bThe Commission\b/g, '' ).replace( /[^A-Za-z]/g, '' );
        return letters !== '' && letters === letters.toUpperCase();
    }

    /**
     * A bold line is a heading when it reads like one: a label, not a sentence.
     * The bold note paragraphs in the source ("Note: An application which fails
     * to provide evidence…") are bold from end to end but are prose, and stay
     * paragraphs so the parser can turn them into callouts.
     */
    function looksLikeHeading( line ) {
        var text = line.text;
        if ( text === '' || text.length > 160 ) return false;
        if ( LABEL_RE.test( text ) ) return false;
        if ( /[.;]$/.test( text ) ) return false;
        // A comma-ending line trails off like prose — unless it is set in all
        // caps, where the comma is just where a wrapped title happened to
        // break ("ACCREDITATION RECORDS RETENTION, MAINTENANCE," continuing
        // onto the next line), not the end of a thought.
        if ( /,$/.test( text ) && ! isAllCaps( text ) ) return false;
        // More than one sentence is prose. The full stop has to follow a word
        // for that to count, so the numbering of "I. Appealable Actions" and
        // "Note: A. Definitions" is not read as the end of a sentence.
        return ( text.match( /\p{Ll}{2}[.?]\s+\p{Lu}/gu ) || [] ).length === 0;
    }

    /**
     * Decide whether a line is a heading.
     *
     * Bold is the usual signal. Where a document does not use it — the
     * appendices set their section titles in the same weight as the body — the
     * remaining signal is the margin: the body text is indented a step in from
     * the left edge, and only headings are set flush to it.
     */
    function isHeading( line, metrics, spaced ) {
        if ( ! looksLikeHeading( line ) ) return false;
        if ( line.bold ) return true;
        var outdented = metrics.bodyX > metrics.baseX + 8
                        && line.x <= metrics.baseX + MARGIN_SLOP;
        return outdented && spaced && ! line.marker;
    }

    /** A heading's text, without the emphasis it is uniformly set in. */
    function headingText( line ) {
        return inline( line.runs, true, line.italic );
    }

    /**
     * Turn the laid-out lines of one document into canonical text.
     *
     * @param {Array} pages Array of per-page line arrays.
     * @return {{text:string, pages:string[]}}
     */
    function canonical( pages, metrics ) {
        var out        = [];      // canonical lines for the whole document
        var pageMarks  = [];      // index in `out` where each page starts
        var openItem   = null;    // the list item currently accepting text
        var openKind   = null;    // 'title' | 'label' | 'heading' | 'paragraph' | 'item' | 'table'
        var openCaps   = false;   // was the heading currently accumulating set in all caps?
        var seenLabel  = false;   // has a document-level label been passed yet?
        var prev       = null;

        function emit( text, kind ) {
            out.push( text );
            openKind = kind;
        }

        function append( text ) {
            var line   = out[ out.length - 1 ];
            var lead   = line.match( /^\s*/ )[ 0 ];
            out[ out.length - 1 ] = lead + tidy( joinWrapped( line.slice( lead.length ), text ) );
        }

        pages.forEach( function ( lines ) {
            pageMarks.push( out.length );
            prev = null;

            lines.forEach( function ( line ) {
                // Vertical gaps say where paragraphs break, but there is no gap
                // to measure across a page boundary. What decides it there is
                // whether the text above finished its sentence.
                var gapAbove = prev ? prev.y - line.y : Infinity;
                var spaced   = prev ? gapAbove > metrics.paraGap
                                    : finished( out[ out.length - 1 ] );
                var text     = inline( line.runs );

                if ( text === '' ) { prev = line; return; }

                // A table row: two or more cells set apart on the same line.
                if ( line.cells.length > 1 ) {
                    openItem = null;
                    var cells = line.cells.map( function ( cell ) { return inline( cell.runs ); } );
                    // A row whose text wrapped inside its cells sits one line
                    // pitch below its own first line, while the next row of the
                    // table is set further apart. Same column count, so the
                    // wrapped text goes back into the cells it came from.
                    var wrapped = openKind === 'table'
                                  && gapAbove <= metrics.pitch * 1.15
                                  && cellCount( out[ out.length - 1 ] ) === cells.length;
                    if ( wrapped ) {
                        out[ out.length - 1 ] = mergeRow( out[ out.length - 1 ], cells );
                    } else {
                        emit( '| ' + cells.join( ' | ' ) + ' |', 'table' );
                    }
                    prev = line;
                    return;
                }

                // Document-level labels always start their own line, and are
                // written without their authored bold so the parser matches the
                // label itself rather than the emphasis around it.
                if ( ! line.marker && LABEL_RE.test( line.text ) && line.x <= metrics.baseX + MARGIN_SLOP ) {
                    openItem  = null;
                    seenLabel = true;
                    emit( inline( line.runs, true ), 'label' );
                    prev = line;
                    return;
                }

                // A new list item.
                if ( line.marker ) {
                    var level = markerLevel( metrics, line.markerX );
                    openItem  = { level: level, contentX: line.contentX };
                    emit( indent( level ) + normalizeMarker( line.marker ) + ' ' + text, 'item' );
                    prev = line;
                    return;
                }

                // Text belonging to the open list item: same left edge as the
                // item's first line. A bold, spaced line at that edge is a
                // sub-heading of its own, not a continuation.
                if ( openItem && Math.abs( line.x - openItem.contentX ) <= MARGIN_SLOP
                     && ! ( line.bold && spaced && looksLikeHeading( line ) ) ) {
                    if ( spaced || openKind !== 'item' ) {
                        emit( indent( openItem.level ) + '  ' + text, 'item' );
                    } else {
                        append( text );
                    }
                    prev = line;
                    return;
                }

                // A line left of the open item's text, or a spaced bold line,
                // has left the list behind.
                if ( openItem && ( line.x < openItem.contentX - MARGIN_SLOP || ( line.bold && spaced ) ) ) {
                    openItem = null;
                }

                if ( isHeading( line, metrics, spaced ) ) {
                    // The opening line of the document, above any label, is its
                    // title rather than a section heading.
                    var level = ( out.length === 0 && ! seenLabel ) ? 1 : headingLevel( line, metrics );
                    var caps  = isAllCaps( line.text );
                    // A line only continues the heading above it when it reads
                    // the same way that heading does. Two consecutive all-caps
                    // lines are one title wrapped across them; a Title Case
                    // line right under an all-caps title — "Policy Statement"
                    // under "GOVERNING, COORDINATING…" — is a second, real
                    // heading that happens to sit close enough to look
                    // unspaced, not a continuation of the first.
                    if ( ( openKind === 'heading' || openKind === 'title' ) && ! spaced && caps === openCaps ) {
                        append( headingText( line ) );        // a heading that wrapped
                    } else {
                        emit( repeat( '#', level ) + ' ' + headingText( line ), level === 1 ? 'title' : 'heading' );
                        openCaps = caps;
                    }
                    prev = line;
                    return;
                }

                // A label that carries its own text — "Teaser: …", "Document
                // History: …" — is a paragraph that happens to start with a
                // label, so its wrapped lines continue it. A label standing
                // alone, "Body:", opens a section instead and takes no text.
                var continues = openKind === 'paragraph'
                    || ( openKind === 'label' && ! /:$/.test( out[ out.length - 1 ] ) );

                if ( continues && ! spaced && ! openItem ) {
                    append( text );
                    openKind = 'paragraph';
                } else {
                    emit( text, 'paragraph' );
                }
                prev = line;
            } );
        } );

        var pageTexts = pageMarks.map( function ( start, index ) {
            var end = index + 1 < pageMarks.length ? pageMarks[ index + 1 ] : out.length;
            return out.slice( start, end ).join( '\n' );
        } );

        return { text: out.join( '\n' ), pages: pageTexts };
    }

    function indent( level ) {
        return repeat( '  ', Math.max( 0, level - 1 ) );
    }

    function rowCells( row ) {
        return row.replace( /^\|\s?|\s?\|$/g, '' ).split( ' | ' );
    }

    function cellCount( row ) {
        return /^\|.*\|$/.test( row || '' ) ? rowCells( row ).length : 0;
    }

    function mergeRow( row, cells ) {
        var current = rowCells( row );
        return '| ' + current.map( function ( cell, index ) {
            return tidy( joinWrapped( cell, cells[ index ] || '' ) );
        } ).join( ' | ' ) + ' |';
    }

    function repeat( text, times ) {
        var out = '';
        for ( var i = 0; i < times; i++ ) out += text;
        return out;
    }

    /** Bullets are all written as "-"; ordered markers keep their own numbering. */
    function normalizeMarker( marker ) {
        if ( /^[\u2022\u00b7\u25cf\u25aa\u25e6\u2043\u2023\u2013\uf0b7\uf06e\uf098\uf0a7\uf0fc]$/.test( marker ) ) return '-';
        return marker.replace( /\)$/, '.' );
    }

    // ─────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────

    /**
     * Extract a whole PDF as canonical text.
     *
     * @param {Object}   pdf         A pdf.js PDFDocumentProxy.
     * @param {Function} onProgress  Optional (pageNumber, pageCount) callback.
     * @return {Promise<{text:string, pages:string[], metrics:Object}>}
     */
    function extract( pdf, onProgress ) {
        var pages = [];

        function next( num ) {
            if ( num > pdf.numPages ) return Promise.resolve();
            if ( onProgress ) onProgress( num, pdf.numPages );
            return pdf.getPage( num ).then( function ( page ) {
                // The operator list is what populates commonObjs, and commonObjs
                // is where the real font names live. Failing to load it costs
                // bold detection, not extraction, so it is not fatal.
                return Promise.resolve()
                    .then( function () { return page.getOperatorList().catch( function () { return null; } ); } )
                    .then( function () { return page.getTextContent(); } )
                    .then( function ( content ) {
                        pages.push( pageLines( page, content, fontFlags( page, content.styles ) ) );
                        return next( num + 1 );
                    } );
            } );
        }

        return next( 1 ).then( function () {
            var metrics = documentMetrics( pages );
            var result  = canonical( pages, metrics );
            result.metrics = metrics;
            return result;
        } );
    }

    return {
        extract:          extract,
        pageLines:        pageLines,
        documentMetrics:  documentMetrics,
        canonical:        canonical,
        fontFlags:        fontFlags
    };
} ) );
