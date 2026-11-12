/**
 * The editor's link dialog: search the site for a page, or type an address.
 *
 * window.prompt() asked the editor to type "/policies/credit-hours/" from
 * memory, showed an empty "https://" box even over a link that already had an
 * address, and could not search anything. This replaces it with one box that
 * does both jobs:
 *
 *   - type a few letters of a policy, page or file name and pick it from the
 *     list — the address is filled in and the link made in one click;
 *   - or type / paste an address (anything that reads as one is taken as the
 *     address, not as a search).
 *
 * Over a link that already exists the box opens with its address and offers
 * "Remove link". The dialog knows nothing about the editor's markup: it hands
 * the chosen address to onSave(), which writes the link and may answer with a
 * message to show in the dialog (an address that cannot be stored, say), in
 * which case the dialog stays open.
 *
 * Usable from the browser (window.AidocsLinkDialog); looksLikeAddress() also
 * from Node — see tests/test-link-dialog.js.
 */
( function ( root, factory ) {
    if ( typeof module === 'object' && module.exports ) {
        module.exports = factory( null );
    } else {
        root.AidocsLinkDialog = factory( root.document );
    }
}( typeof self !== 'undefined' ? self : this, function ( doc ) {
    'use strict';

    /**
     * Whether what was typed is an address rather than words to search for.
     *
     * A scheme, a leading "/", "#" or "?", or a host-shaped first segment
     * ("commissioncu.org/x") is an address. "credit hours" and "credit-hours"
     * are searches: no whitespace is allowed in an address, and a bare word
     * with no dot cannot be a host.
     *
     * @param {string} text
     * @return {boolean}
     */
    function looksLikeAddress( text ) {
        var raw = String( text == null ? '' : text ).trim();
        if ( raw === '' || /\s/.test( raw ) ) return false;
        if ( /^(?:[a-z][a-z0-9+.\-]*:|[\/#?])/i.test( raw ) ) return true;
        return /^[^\/?#]*\.[a-z]{2,24}(?::\d+)?(?:[\/?#]|$)/i.test( raw );
    }

    var CSS = [
        '.aidocs-ld-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100100;display:flex;align-items:flex-start;justify-content:center;padding:10vh 16px 16px;box-sizing:border-box}',
        '.aidocs-ld{background:#fff;border-radius:6px;box-shadow:0 8px 30px rgba(0,0,0,.3);width:560px;max-width:100%;max-height:80vh;overflow:auto;padding:20px 22px 18px;box-sizing:border-box;font-size:14px;color:#1d2327}',
        '.aidocs-ld h2{margin:0 0 4px;font-size:18px;line-height:1.3}',
        '.aidocs-ld-words{margin:0 0 14px;color:#50575e}',
        '.aidocs-ld-words strong{color:#1d2327}',
        '.aidocs-ld label{display:block;font-weight:600;margin:0 0 6px}',
        '.aidocs-ld input[type=text]{width:100%;box-sizing:border-box;height:38px;padding:0 10px;font-size:15px;border:1px solid #8c8f94;border-radius:4px}',
        '.aidocs-ld input[type=text]:focus{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1;outline:none}',
        '.aidocs-ld-note{margin:8px 0 0;min-height:18px;color:#50575e;font-size:13px}',
        '.aidocs-ld-error{margin:8px 0 0;color:#b32d2e;font-size:13px}',
        '.aidocs-ld-error:empty{display:none}',
        '.aidocs-ld-results{list-style:none;margin:8px 0 0;padding:0;border:1px solid #dcdcde;border-radius:4px;max-height:260px;overflow:auto}',
        '.aidocs-ld-results:empty{display:none}',
        '.aidocs-ld-results li{margin:0;padding:8px 10px;cursor:pointer;border-bottom:1px solid #f0f0f1;display:grid;grid-template-columns:1fr auto;gap:2px 10px}',
        '.aidocs-ld-results li:last-child{border-bottom:0}',
        '.aidocs-ld-results li[aria-selected=true],.aidocs-ld-results li:hover{background:#f0f6fc}',
        '.aidocs-ld-results .t{font-weight:600}',
        '.aidocs-ld-results .k{font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#50575e;background:#f0f0f1;border-radius:3px;padding:1px 6px;align-self:center}',
        '.aidocs-ld-results .u{grid-column:1 / -1;font-size:12px;color:#646970;word-break:break-all}',
        '.aidocs-ld-actions{display:flex;gap:8px;align-items:center;margin-top:16px}',
        '.aidocs-ld-actions .spacer{flex:1}',
        '.aidocs-ld-actions button{height:34px;padding:0 14px;border-radius:4px;border:1px solid #2271b1;background:#fff;color:#2271b1;font-size:13px;cursor:pointer}',
        '.aidocs-ld-actions .primary{background:#2271b1;color:#fff}',
        '.aidocs-ld-actions .danger{border-color:#b32d2e;color:#b32d2e}',
        '.aidocs-ld-actions button:focus-visible{outline:2px solid #2271b1;outline-offset:2px}'
    ].join( '\n' );

    var styled = false;
    var seq    = 0;

    function el( tag, props, text ) {
        var node = doc.createElement( tag );
        if ( props ) Object.keys( props ).forEach( function ( k ) { node.setAttribute( k, props[ k ] ); } );
        if ( text != null ) node.textContent = text;
        return node;
    }

    /**
     * Open the dialog.
     *
     * @param {Object}   opts
     * @param {string}   [opts.label]    The words being linked, shown for reference.
     * @param {string}   [opts.url]      The address already there; opens the dialog in "edit" mode.
     * @param {Function} opts.search     (term) => Promise<Array<{title,type,url}>>
     * @param {Function} opts.onSave     (url) => string|void — a returned string is shown and keeps the dialog open.
     * @param {Function} [opts.onRemove] Called by "Remove link"; offered only when opts.url is set.
     * @param {Function} [opts.onClose]  Called whenever the dialog goes away, however it did.
     */
    function open( opts ) {
        if ( ! doc ) return;
        if ( ! styled ) {
            var style = el( 'style' );
            style.textContent = CSS;
            doc.head.appendChild( style );
            styled = true;
        }

        var editing = !! ( opts.url );
        var previous = doc.activeElement;
        var id = 'aidocs-ld-' + ( ++seq );
        var results = [];
        var active = -1;
        var timer = null;
        var token = 0;
        var resultsFor = null;      // the words the list on screen was searched for

        var backdrop = el( 'div', { 'class': 'aidocs-ld-backdrop' } );
        var box = el( 'div', { 'class': 'aidocs-ld', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': id + '-h' } );
        box.appendChild( el( 'h2', { id: id + '-h' }, editing ? 'Edit link' : 'Add link' ) );

        if ( opts.label ) {
            var words = el( 'p', { 'class': 'aidocs-ld-words' }, 'Link text: ' );
            words.appendChild( el( 'strong', null, '“' + opts.label + '”' ) );
            box.appendChild( words );
        }

        box.appendChild( el( 'label', { 'for': id + '-in' }, 'Search for a page or file, or type an address' ) );
        var input = el( 'input', {
            type: 'text', id: id + '-in', autocomplete: 'off', spellcheck: 'false',
            placeholder: 'e.g. credit hours — or https://…',
            role: 'combobox', 'aria-expanded': 'false', 'aria-controls': id + '-list', 'aria-autocomplete': 'list'
        } );
        input.value = opts.url || '';
        box.appendChild( input );

        var list  = el( 'ul', { id: id + '-list', role: 'listbox', 'aria-label': 'Matching pages and files', 'class': 'aidocs-ld-results' } );
        var note  = el( 'p', { 'class': 'aidocs-ld-note', 'aria-live': 'polite' } );
        var error = el( 'p', { 'class': 'aidocs-ld-error', role: 'alert' } );
        box.appendChild( list );
        box.appendChild( note );
        box.appendChild( error );

        var actions = el( 'div', { 'class': 'aidocs-ld-actions' } );
        var removeBtn = null;
        if ( editing && opts.onRemove ) {
            removeBtn = el( 'button', { type: 'button', 'class': 'danger' }, 'Remove link' );
            actions.appendChild( removeBtn );
        }
        actions.appendChild( el( 'span', { 'class': 'spacer' } ) );
        var cancelBtn = el( 'button', { type: 'button' }, 'Cancel' );
        var saveBtn   = el( 'button', { type: 'button', 'class': 'primary' }, editing ? 'Update link' : 'Add link' );
        actions.appendChild( cancelBtn );
        actions.appendChild( saveBtn );
        box.appendChild( actions );

        backdrop.appendChild( box );
        doc.body.appendChild( backdrop );

        function close() {
            clearTimeout( timer );
            token++;
            if ( backdrop.parentNode ) backdrop.parentNode.removeChild( backdrop );
            doc.removeEventListener( 'keydown', onKey, true );
            if ( previous && previous.focus ) { try { previous.focus(); } catch ( e ) { /* the editor refocuses itself */ } }
            if ( opts.onClose ) opts.onClose();
        }

        function say( message ) { note.textContent = message || ''; }

        function setActive( index ) {
            var items = list.children;
            active = index;
            for ( var i = 0; i < items.length; i++ ) {
                items[ i ].setAttribute( 'aria-selected', i === index ? 'true' : 'false' );
            }
            if ( index >= 0 && items[ index ] ) {
                input.setAttribute( 'aria-activedescendant', items[ index ].id );
                if ( items[ index ].scrollIntoView ) items[ index ].scrollIntoView( { block: 'nearest' } );
            } else {
                input.removeAttribute( 'aria-activedescendant' );
            }
        }

        function render( found ) {
            results = found || [];
            list.textContent = '';
            results.forEach( function ( r, i ) {
                var li = el( 'li', { id: id + '-opt-' + i, role: 'option', 'aria-selected': 'false' } );
                li.appendChild( el( 'span', { 'class': 't' }, r.title || r.url ) );
                li.appendChild( el( 'span', { 'class': 'k' }, r.type || '' ) );
                li.appendChild( el( 'span', { 'class': 'u' }, r.url ) );
                li.addEventListener( 'mousedown', function ( e ) { e.preventDefault(); } );
                li.addEventListener( 'click', function () { save( r.url ); } );
                list.appendChild( li );
            } );
            input.setAttribute( 'aria-expanded', results.length ? 'true' : 'false' );
            setActive( results.length ? 0 : -1 );
        }

        function save( url ) {
            error.textContent = '';
            var problem = opts.onSave( url );
            if ( problem ) { error.textContent = problem; input.focus(); return; }
            close();
        }

        function applyTyped() {
            var typed = input.value.trim();
            if ( looksLikeAddress( typed ) ) { save( typed ); return; }
            // Enter pressed before the search for these words has answered:
            // run it now and show what it finds, instead of calling the
            // words an error.
            if ( typed !== '' && typed !== resultsFor ) { clearTimeout( timer ); runSearch(); return; }
            if ( active >= 0 && results[ active ] ) { save( results[ active ].url ); return; }
            error.textContent = typed === ''
                ? 'Type a page name to search, or an address.'
                : 'Pick a page from the list, or type an address that starts with https:// or /.';
        }

        function runSearch() {
            var term = input.value.trim();
            var mine = ++token;
            error.textContent = '';
            resultsFor = null;
            if ( looksLikeAddress( term ) ) {
                render( [] );
                say( 'This address will be used as typed.' );
                return;
            }
            if ( term.length < 2 ) {
                render( [] );
                say( editing && term === '' ? 'Clear the address and type a page name to search, or use Remove link.' : 'Start typing a policy, page or file name — or paste an address.' );
                return;
            }
            say( 'Searching…' );
            Promise.resolve( opts.search( term ) ).then( function ( found ) {
                if ( mine !== token ) return;
                render( found );
                resultsFor = term;
                say( found && found.length ? '' : 'No pages or files match “' + term + '”. You can still type an address.' );
            }, function () {
                if ( mine !== token ) return;
                render( [] );
                say( 'Search is not available right now. You can still type an address.' );
            } );
        }

        function onKey( e ) {
            if ( ! backdrop.parentNode ) return;
            if ( e.key === 'Escape' ) { e.preventDefault(); e.stopPropagation(); close(); return; }
            if ( e.key === 'Tab' ) {
                var f = box.querySelectorAll( 'input,button' );
                if ( ! f.length ) return;
                var first = f[ 0 ], last = f[ f.length - 1 ];
                if ( e.shiftKey && doc.activeElement === first ) { e.preventDefault(); last.focus(); }
                else if ( ! e.shiftKey && doc.activeElement === last ) { e.preventDefault(); first.focus(); }
                else if ( ! box.contains( doc.activeElement ) ) { e.preventDefault(); first.focus(); }
            }
        }

        input.addEventListener( 'input', function () {
            clearTimeout( timer );
            timer = setTimeout( runSearch, 220 );
        } );
        input.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'ArrowDown' && results.length ) { e.preventDefault(); setActive( ( active + 1 ) % results.length ); }
            else if ( e.key === 'ArrowUp' && results.length ) { e.preventDefault(); setActive( ( active - 1 + results.length ) % results.length ); }
            else if ( e.key === 'Enter' ) { e.preventDefault(); applyTyped(); }
        } );
        saveBtn.addEventListener( 'click', applyTyped );
        cancelBtn.addEventListener( 'click', close );
        if ( removeBtn ) removeBtn.addEventListener( 'click', function () { opts.onRemove(); close(); } );
        backdrop.addEventListener( 'mousedown', function ( e ) { if ( e.target === backdrop ) close(); } );
        doc.addEventListener( 'keydown', onKey, true );

        if ( editing ) { say( 'This link currently goes to the address above.' ); }
        else           { runSearch(); }
        input.focus();
        input.select();
    }

    return { open: open, looksLikeAddress: looksLikeAddress };
} ) );
