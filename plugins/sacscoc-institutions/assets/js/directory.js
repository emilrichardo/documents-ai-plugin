/**
 * Live filtering for the institutions directory.
 *
 * A progressive enhancement, not a requirement. The directory is a plain GET
 * form that works fully server-rendered; this script intercepts it so that
 * typing a name or changing a select re-renders only the results, and keeps the
 * address bar in step so a filtered view stays shareable and the back button
 * still means something.
 *
 * If anything here fails — a network error, an expired nonce, a browser without
 * fetch — the form is submitted normally and the page reloads with the same
 * filters. There is no state that only exists in JavaScript.
 */
( function () {
	'use strict';

	var cfg = window.sacscocInstitutions;
	if ( ! cfg || ! window.fetch || ! window.FormData ) return;

	var root = document.querySelector( '[data-sacscoc-directory]' );
	if ( ! root ) return;

	var form    = root.querySelector( '[data-sacscoc-form]' );
	var region  = root.querySelector( '[data-sacscoc-results]' );
	var main    = root.querySelector( '[data-sacscoc-main]' );
	var layout  = root.querySelector( '[data-sacscoc-layout]' );
	var reset   = root.querySelector( '[data-sacscoc-reset]' );
	var spinner = root.querySelector( '[data-sacscoc-spinner]' );
	var submit  = root.querySelector( '[data-sacscoc-submit]' );

	if ( ! form || ! region || ! main || ! layout ) return;

	// Filtering is live from here on, so the button is redundant. Kept in the
	// DOM (and still functional) rather than removed, so a script error later
	// leaves a usable form behind.
	root.classList.add( 'is-live' );
	if ( submit ) submit.setAttribute( 'aria-hidden', 'true' );

	var TYPING_DELAY = 300;
	var fields = [ 'si_q', 'si_state', 'si_degree', 'si_year' ];
	var timer  = null;
	var inFlight = null;
	var seq = 0;

	function el( name ) { return form.querySelector( '[name="' + name + '"]' ); }

	function currentFilters() {
		var out = {};
		fields.forEach( function ( name ) {
			var node = el( name );
			out[ name ] = node ? node.value : '';
		} );
		return out;
	}

	function anyActive( filters ) {
		return fields.some( function ( name ) { return filters[ name ] !== ''; } );
	}

	/** Show or hide the × beside each field, and the Reset link. */
	function syncControls( filters ) {
		fields.forEach( function ( name ) {
			var node = el( name );
			if ( ! node ) return;

			var wrap = node.closest( '.sacscoc-field__control' );
			if ( ! wrap ) return;

			var key = name.replace( 'si_', '' );
			var btn = wrap.querySelector( '[data-sacscoc-clear]' );
			var wanted = filters[ name ] !== '';

			if ( wanted && ! btn ) {
				btn = document.createElement( 'a' );
				btn.className = 'sacscoc-field__clear';
				btn.href = '#';
				btn.setAttribute( 'data-sacscoc-clear', key );
				btn.innerHTML = '&times;';
				wrap.appendChild( btn );
			} else if ( ! wanted && btn ) {
				btn.remove();
			}

			if ( btn ) {
				var label = node.labels && node.labels[ 0 ] ? node.labels[ 0 ].textContent.trim() : key;
				btn.setAttribute( 'aria-label', 'Clear: ' + label );
				btn.setAttribute( 'title', 'Clear: ' + label );
			}
		} );

		if ( reset ) reset.classList.toggle( 'is-hidden', ! anyActive( filters ) );
	}

	function busy( on ) {
		region.setAttribute( 'aria-busy', on ? 'true' : 'false' );
		root.classList.toggle( 'is-loading', on );
		if ( spinner ) spinner.classList.toggle( 'is-active', on );
	}

	/** Keep the URL honest, without adding a history entry per keystroke. */
	function pushUrl( query ) {
		if ( ! window.history || ! window.history.replaceState ) return;
		var base = root.getAttribute( 'data-action' ) || window.location.pathname;
		window.history.replaceState( { sacscoc: true }, '', base + query );
	}

	function fallback() {
		// Let the browser do what it would have done without this script.
		form.submit();
	}

	function run( page ) {
		var filters = currentFilters();
		syncControls( filters );

		var body = new FormData();
		body.append( 'action', 'sacscoc_inst_filter' );
		body.append( 'nonce', cfg.nonce );
		body.append( 'si_page', page || 1 );
		body.append( 'page_url', root.getAttribute( 'data-action' ) || window.location.href );
		// The page size and the count are the shortcode's, not the default's:
		// without these a directory set to 50 per page would drop back to 25 on
		// the first keystroke.
		body.append( 'per_page', root.getAttribute( 'data-per-page' ) || '' );
		body.append( 'show_count', root.getAttribute( 'data-show-count' ) || 'yes' );
		fields.forEach( function ( name ) { body.append( name, filters[ name ] ); } );

		var mine = ++seq;
		if ( inFlight ) inFlight.abort();

		var controller = window.AbortController ? new AbortController() : null;
		inFlight = controller;

		busy( true );

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			body: body,
			credentials: 'same-origin',
			signal: controller ? controller.signal : undefined
		} )
			.then( function ( res ) {
				if ( ! res.ok ) throw new Error( 'HTTP ' + res.status );
				return res.json();
			} )
			.then( function ( payload ) {
				// A slower earlier request must never overwrite a newer result.
				if ( mine !== seq ) return;
				if ( ! payload || ! payload.success ) throw new Error( 'bad payload' );

				region.innerHTML = payload.data.html;

				// The column stays in the page when a search comes back empty:
				// it is where "no results found" is printed. Only the two-column
				// grid stands down, so the search panel gets the width.
				// `--solo` narrows the search panel when it is the only thing on
				// the page. The one-column layout is already a single column and
				// its search is a full-width bar, so it never wants it.
				var hasRows = payload.data.total > 0;
				var stacked = layout.classList.contains( 'sacscoc-layout--stacked' );
				layout.classList.toggle( 'sacscoc-layout--solo', ! hasRows && ! stacked );

				pushUrl( payload.data.query || '' );
				busy( false );
				inFlight = null;
			} )
			.catch( function ( err ) {
				if ( err && err.name === 'AbortError' ) return;
				if ( mine !== seq ) return;

				busy( false );
				inFlight = null;
				fallback();
			} );
	}

	// ── Typing ────────────────────────────────────────────────────────────
	var q = el( 'si_q' );
	if ( q ) {
		q.addEventListener( 'input', function () {
			window.clearTimeout( timer );
			timer = window.setTimeout( function () { run( 1 ); }, TYPING_DELAY );
		} );

		// Enter should apply immediately rather than wait out the debounce.
		q.addEventListener( 'keydown', function ( e ) {
			if ( e.key !== 'Enter' ) return;
			e.preventDefault();
			window.clearTimeout( timer );
			run( 1 );
		} );
	}

	// ── Selects ───────────────────────────────────────────────────────────
	[ 'si_state', 'si_degree', 'si_year' ].forEach( function ( name ) {
		var node = el( name );
		if ( node ) node.addEventListener( 'change', function () { run( 1 ); } );
	} );

	// ── Submit ────────────────────────────────────────────────────────────
	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		window.clearTimeout( timer );
		run( 1 );
	} );

	// ── Clear one filter, reset all, paginate ─────────────────────────────
	// Delegated, because the × buttons and the pagination links are replaced
	// wholesale on every render.
	root.addEventListener( 'click', function ( e ) {
		var clear = e.target.closest( '[data-sacscoc-clear]' );
		if ( clear ) {
			e.preventDefault();
			var node = el( 'si_' + clear.getAttribute( 'data-sacscoc-clear' ) );
			if ( node ) {
				node.value = '';
				node.focus();
			}
			run( 1 );
			return;
		}

		if ( e.target.closest( '[data-sacscoc-reset]' ) ) {
			e.preventDefault();
			fields.forEach( function ( name ) {
				var f = el( name );
				if ( f ) f.value = '';
			} );
			run( 1 );
			return;
		}

		var page = e.target.closest( '.sacscoc-pagination a.page-numbers' );
		if ( page ) {
			e.preventDefault();
			var url;
			try { url = new URL( page.href, window.location.origin ); } catch ( err ) { return; }
			run( parseInt( url.searchParams.get( 'si_page' ) || '1', 10 ) );
			region.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}
	} );

	// The address bar is rewritten with replaceState, so a real back navigation
	// lands on a URL the server renders correctly — no state to restore here.
	syncControls( currentFilters() );
} )();
