/**
 * Writing a link the canonical grammar can read back.
 *
 * includes/aidocs-doc-parser.php reads "[visible text](url)" with a
 * deliberately narrow grammar: the label escapes its brackets, and the URL
 * may hold neither whitespace nor a parenthesis. The plugin's own writer,
 * aidocs_link_markup(), obeys both rules. EasyMDE's stock link button does
 * not — it is generic markdown and knows nothing about either — so an editor
 * selecting a label that already reads "[PDF]" and pressing it produced
 *
 *     Credit Hours [[PDF]](https://…/credit-hours/)
 *
 * which published as that literal text rather than as a link. The same
 * button, handed a SharePoint or OneDrive URL, writes parentheses straight
 * into the URL and the link dies just as quietly.
 *
 * This module is the one place that knows how to spell a link for this
 * grammar. It is deliberately free of DOM and of EasyMDE so it can be
 * exercised from Node — see tests/test-link-markup.js.
 *
 * Usable from the browser (window.AidocsLinkMarkup) and from Node, same as
 * the extractor modules.
 */
( function ( root, factory ) {
    if ( typeof module === 'object' && module.exports ) {
        module.exports = factory();
    } else {
        root.AidocsLinkMarkup = factory();
    }
}( typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    /**
     * Escape a link's visible text.
     *
     * The mirror image of aidocs_link_markup() in PHP: a backslash doubles,
     * and each bracket gains one. Done in a single pass so the backslashes
     * this adds are not themselves escaped a second time.
     *
     * @param {string} text
     * @return {string}
     */
    function escapeLabel( text ) {
        return String( text == null ? '' : text ).replace( /[\\\[\]]/g, function ( ch ) {
            return '\\' + ch;
        } );
    }

    /**
     * Make a URL expressible in the canonical grammar.
     *
     * Percent-encoding is not a workaround: a space and a parenthesis are
     * not legal in a URL unencoded, and every browser and server reads
     * "%20"/"%28"/"%29" as the characters they stand for. Encoding them is
     * what lets a SharePoint path such as
     *
     *     https://x.sharepoint.com/Shared Documents/Policy (2026).pdf
     *
     * survive a grammar that stops at the first space or parenthesis.
     * Anything already percent-encoded is left alone, so pasting a URL the
     * browser already encoded does not double-encode it.
     *
     * @param {string} url
     * @return {string}
     */
    function normalizeUrl( url ) {
        var raw = String( url == null ? '' : url ).trim();
        if ( raw === '' ) return '';
        return raw
            .replace( /%(?![0-9A-Fa-f]{2})/g, '%25' )
            .replace( /\s/g, '%20' )
            .replace( /\(/g, '%28' )
            .replace( /\)/g, '%29' );
    }

    /**
     * Whether a URL, once normalized, is something the grammar will read.
     * Used to warn the editor rather than to refuse the insert: the link
     * policy on the server has the final say on whether it is kept.
     *
     * @param {string} url
     * @return {boolean}
     */
    function isExpressible( url ) {
        var normalized = normalizeUrl( url );
        return normalized !== '' && ! /[()\s]/.test( normalized );
    }

    /**
     * "[text](href)" — the whole markup, both halves made safe.
     *
     * @param {string} text
     * @param {string} href
     * @return {string}
     */
    function linkMarkup( text, href ) {
        return '[' + escapeLabel( text ) + '](' + normalizeUrl( href ) + ')';
    }

    return {
        escapeLabel:   escapeLabel,
        normalizeUrl:  normalizeUrl,
        isExpressible: isExpressible,
        linkMarkup:    linkMarkup
    };
} ) );
