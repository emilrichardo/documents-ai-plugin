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
 *
 * ── The form and the results can be two separate elements ──────────────────
 *
 * [sacscoc_institutions show_search="no"] renders just the results, and
 * [sacscoc_institutions_search] renders just the form — for a form placed
 * somewhere the directory's own layout cannot reach: a custom block, a
 * sidebar, a template part. Nothing on the server links the two; they find
 * each other here, purely at runtime, by `data-sacscoc-group` (default
 * "default", which is why the ordinary one-of-each case needs no attribute on
 * either shortcode). pairedForm() looks first for a form nested inside the
 * directory — the default, single-shortcode case — and only then elsewhere on
 * the page, so a page with one of each simply works.
 */
( function () {
	'use strict';

	var cfg = window.sacscocInstitutions;
	if ( ! cfg || ! window.fetch || ! window.FormData ) return;

	// A form is claimed by at most one directory, so two directories sharing a
	// group by accident cannot both silently bind to the same fields.
	var claimed = [];

	function claim( form ) {
		if ( claimed.indexOf( form ) !== -1 ) return false;
		claimed.push( form );
		return true;
	}

	/**
	 * The form paired with one directory, or null.
	 *
	 * Nested inside it when there is one. Otherwise the first not-yet-claimed
	 * [data-sacscoc-form] elsewhere on the page sharing its group — what a
	 * separate [sacscoc_institutions_search] leaves behind.
	 */
	function pairedForm( root ) {
		var inside = root.querySelector( '[data-sacscoc-form]' );
		if ( inside ) return claim( inside ) ? inside : null;

		var group = root.getAttribute( 'data-sacscoc-group' ) || 'default';
		var elsewhere = document.querySelectorAll(
			'[data-sacscoc-form][data-sacscoc-group="' + group + '"]'
		);
		for ( var i = 0; i < elsewhere.length; i++ ) {
			if ( claim( elsewhere[ i ] ) ) return elsewhere[ i ];
		}
		return null;
	}

	/** Wire one directory to its paired form. Does nothing if either is missing. */
	function initDirectory( root, form ) {
		var region = root.querySelector( '[data-sacscoc-results]' );

		if ( ! form || ! region ) return;

		// Present only in 'always' mode's two-column/stacked grid — a
		// dropdown (`results="on-search"`) has no such grid, so this is
		// legitimately null there and every use of it below is guarded.
		var layout = root.querySelector( '[data-sacscoc-layout]' );

		// Present only when this directory rendered as a floating typeahead
		// with its own inline form (results="on-search", show_search not
		// "no") — null for 'always' mode, and for the paired-elsewhere
		// dropdown variant that has no form of its own to float under. See
		// the "on-search" branch of templates/directory.php.
		var typeahead = form.closest( '.sacscoc-typeahead' );

		var reset   = form.querySelector( '[data-sacscoc-reset]' );
		var spinner = form.querySelector( '[data-sacscoc-spinner]' );
		var submit  = form.querySelector( '[data-sacscoc-submit]' );

		// Filtering is live from here on, so the button is redundant in the
		// panel (the CSS keeps it for the one-column bar, where it is part of
		// the bar's shape — see .sacscoc-search--stacked). Kept in the DOM (and
		// still functional) either way, so a script error later leaves a usable
		// form behind. Marked on the form, not the directory: the two are not
		// always the same element.
		form.classList.add( 'is-live' );
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

			// Nothing asked yet: hide the results region outright, panel chrome
			// and all — matches the "print nothing at all" the PHP side does for
			// the same state (templates/results.php). Harmless on an 'always'
			// mode directory: nothing there selects on `.is-empty`, so this sits
			// on the element unused. See the "Typeahead / on-search dropdown"
			// section of assets/css/sacscoc-institutions.css for what actually
			// reads it.
			region.classList.toggle( 'is-empty', ! anyActive( filters ) );

			// A search that starts typing again after the dropdown was
			// dismissed (Escape, or a click outside it) should bring it back —
			// the visitor is asking something new, and a dropdown that stays
			// closed through that reads as broken, not as respected.
			if ( typeahead ) typeahead.classList.remove( 'is-closed' );
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
			// A customised results heading, same reasoning as per_page above: it
			// only exists as an attribute on this wrapper, so without sending it
			// back the first live filter would silently revert it to "Results".
			body.append( 'results_heading', root.getAttribute( 'data-results-heading' ) || '' );
			// Same reasoning again: a directory told to start empty has to
			// stay empty when the visitor clears the box, and this attribute
			// is the only place that instruction lives.
			body.append( 'results_mode', root.getAttribute( 'data-results-mode' ) || 'always' );
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
					// its search is a full-width bar, so it never wants it; nor does a
					// directory rendered with no search panel at all
					// (`--no-search`), which has no aside to narrow in the first place.
					// None of this exists in dropdown mode — `layout` is null there,
					// there being no grid to adjust in the first place.
					if ( layout ) {
						var hasRows = payload.data.total > 0;
						var stacked = layout.classList.contains( 'sacscoc-layout--stacked' );
						var noSearch = layout.classList.contains( 'sacscoc-layout--no-search' );
						layout.classList.toggle( 'sacscoc-layout--solo', ! hasRows && ! stacked && ! noSearch );
					}

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

		// ── Typing ────────────────────────────────────────────────────────
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

			// Tabbing back into the field with a search still typed should
			// bring the dropdown back — it is exactly what focus normally
			// means for a search box, and Escape (below) closing it earlier
			// should not be a way to lose it for the rest of the visit.
			if ( typeahead ) {
				q.addEventListener( 'focus', function () {
					if ( anyActive( currentFilters() ) ) typeahead.classList.remove( 'is-closed' );
				} );
			}
		}

		// ── Dismissing the dropdown ─────────────────────────────────────
		// Escape and a click outside both close it without touching what was
		// typed — the same thing a browser's own address-bar autocomplete
		// does, and for the same reason: closing a suggestion list is not the
		// same action as clearing a search.
		if ( typeahead ) {
			form.addEventListener( 'keydown', function ( e ) {
				if ( e.key !== 'Escape' ) return;
				typeahead.classList.add( 'is-closed' );
			} );

			document.addEventListener( 'click', function ( e ) {
				if ( ! typeahead.contains( e.target ) ) typeahead.classList.add( 'is-closed' );
			} );
		}

		// ── Selects ───────────────────────────────────────────────────────
		[ 'si_state', 'si_degree', 'si_year' ].forEach( function ( name ) {
			var node = el( name );
			if ( node ) node.addEventListener( 'change', function () { run( 1 ); } );
		} );

		// ── Submit ────────────────────────────────────────────────────────
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			window.clearTimeout( timer );
			run( 1 );
		} );

		// ── Clear one filter, reset all ──────────────────────────────────
		// Delegated on the form itself, because the × buttons are replaced
		// wholesale on every render — and because the form is not necessarily a
		// descendant of the directory (or vice versa) any more.
		form.addEventListener( 'click', function ( e ) {
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
			}
		} );

		// ── Paginate ──────────────────────────────────────────────────────
		// Delegated on the results region, which the form is not always part of.
		region.addEventListener( 'click', function ( e ) {
			var page = e.target.closest( '.sacscoc-pagination a.page-numbers' );
			if ( ! page ) return;

			e.preventDefault();
			var url;
			try { url = new URL( page.href, window.location.origin ); } catch ( err ) { return; }
			run( parseInt( url.searchParams.get( 'si_page' ) || '1', 10 ) );
			region.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		} );

		// The address bar is rewritten with replaceState, so a real back navigation
		// lands on a URL the server renders correctly — no state to restore here.
		syncControls( currentFilters() );
	}

	document.querySelectorAll( '[data-sacscoc-directory]' ).forEach( function ( root ) {
		initDirectory( root, pairedForm( root ) );
	} );

} )();

/**
 * Keeping a degree-level tooltip on screen.
 *
 * A second, independent unit deliberately: the directory above returns early
 * when `window.sacscocInstitutions` is missing or the browser has no fetch,
 * and an institution's own page — which renders these hints and no directory
 * at all — is exactly that case. Nesting this inside it meant the nudge never
 * ran on the one page that needs it most.
 */
( function () {
	'use strict';

	//
	// The tooltip itself is CSS and nothing else: it is a real element inside
	// the hint, shown on :hover and :focus with no delay and no script (see
	// the "Level hint" section of assets/css/sacscoc-institutions.css). This
	// is not that. This is the one thing CSS genuinely cannot do — know where
	// in the viewport the bubble has landed.
	//
	// The bubble grows rightwards from its hint, which can never be clipped on
	// the left. On a phone, though, a hint sitting over on the right of a
	// result row puts the bubble's right edge past the screen. Measured once
	// per hover, nudged back by however much it overshoots, and handed to the
	// CSS as a custom property; the arrow subtracts the same amount so it goes
	// on pointing at the "i".
	//
	// Delegated on the document, not bound per hint, because every result row
	// after the first search arrives from AJAX and would otherwise be missed.
	// With this script absent or broken the tooltip still works — it is a nudge
	// of zero, which is where the CSS starts.
	var TIP_MARGIN = 8;

	function placeTip( hint ) {
		var bubble = hint.querySelector( '.sacscoc-hint__bubble' );
		if ( ! bubble ) return;

		// Measure unshifted, or each hover would compound the last one's nudge.
		bubble.style.setProperty( '--sacscoc-tip-shift', '0px' );

		// The bubble is `display: none` until it is shown, so that a hidden
		// tooltip cannot widen the page. `pointerenter` and `focus` fire with
		// :hover / :focus already matching, so by now it is displayed — but
		// forcing it for the measurement means this does not quietly depend on
		// that ordering, and a box of all zeroes cannot silently produce a
		// nonsense shift.
		var wasInline = bubble.style.display;
		bubble.style.display = 'block';

		var box = bubble.getBoundingClientRect();
		var vw  = document.documentElement.clientWidth;
		var shift = 0;

		if ( box.right > vw - TIP_MARGIN ) shift = ( vw - TIP_MARGIN ) - box.right;
		// Pulling it off the right edge must not push it off the left one; a
		// bubble wider than the viewport ends up flush left, which is the best
		// available answer.
		if ( box.left + shift < TIP_MARGIN ) shift = TIP_MARGIN - box.left;

		bubble.style.display = wasInline;
		bubble.style.setProperty( '--sacscoc-tip-shift', Math.round( shift ) + 'px' );
	}

	// Neither event bubbles, so both are captured on the way down.
	[ 'pointerenter', 'focus' ].forEach( function ( type ) {
		document.addEventListener( type, function ( e ) {
			var node = e.target;
			if ( ! node || ! node.closest ) return;

			var hint = node.closest( '.sacscoc-hint' );
			if ( hint ) placeTip( hint );
		}, true );
	} );
} )();
