/**
 * Global Search frontend. Plain DOM + fetch, no jQuery, no build step.
 *
 * ── What this script is, and is not ────────────────────────────────────────
 *
 * It is an enhancement over a working form. The markup it finds is a real
 * `method="get"` form with a real action and a named field (see
 * gsearch_render()), so pressing GO or Enter navigates to the results page
 * whether or not this file loaded, parsed or threw. Nothing below is the only
 * way to complete a search. The one exception is the legacy in-page typeahead
 * (`data-submit="search"`), which by definition has nowhere to navigate to.
 *
 * ── Two presentations ──────────────────────────────────────────────────────
 *
 *   dropdown  a panel floating under the input, closed until a search returns
 *             something. Capped at `data-max-results` rows — a header has a
 *             few hundred pixels of safe height, not a page — and ended with
 *             "View all results", which is where the rest of them are.
 *   inline    the results page: the panel is ordinary page content, open, with
 *             its source filters showing and a visible empty state. A query in
 *             the URL arrives already in the field, and the search runs on
 *             load without anyone pressing anything.
 *
 * ── Filtering ──────────────────────────────────────────────────────────────
 *
 * The "All | Site | Policies | Institutions" tabs never trigger a new request.
 * Every search already asks the REST endpoint for every enabled provider at
 * once (each capped server-side — see class-search-service.php), so switching
 * tabs re-renders the response already in memory, grouped or filtered by
 * `result.source`. A second request per tab would only be justified once
 * per-source pagination exists, which V1 does not have.
 */
( function () {
	'use strict';

	var config = window.globalSearchConfig || {};
	var restUrl = config.restUrl || '';
	var i18n = config.i18n || {};

	var providersPromise = null;
	function getProviders() {
		if ( ! providersPromise ) {
			providersPromise = fetch( restUrl + 'providers' )
				.then( function ( r ) { return r.json(); } )
				.then( function ( data ) { return data.providers || []; } )
				.catch( function () { return []; } );
		}
		return providersPromise;
	}

	function humanize( id ) {
		return id.charAt( 0 ).toUpperCase() + id.slice( 1 );
	}

	function initInstance( root ) {
		var form      = root.querySelector( '.global-search__form' );
		var input     = root.querySelector( '.global-search__input' );
		var panel     = root.querySelector( '.global-search__panel' );
		var filtersEl = root.querySelector( '.global-search__filters' );
		var statusEl  = root.querySelector( '.global-search__status' );
		var resultsEl = root.querySelector( '.global-search__results' );
		var viewAllEl = root.querySelector( '[data-global-search-view-all]' );

		if ( ! form || ! input || ! resultsEl || ! panel ) {
			return;
		}

		var configuredSources = ( root.dataset.sources || '' )
			.split( ',' )
			.map( function ( s ) { return s.trim(); } )
			.filter( Boolean );
		var resultsPerPage = parseInt( root.dataset.resultsPerPage, 10 ) || 10;
		var maxResults     = parseInt( root.dataset.maxResults, 10 ) || resultsPerPage;
		var showFilters    = root.dataset.showFilters === '1';
		var inline         = root.dataset.mode === 'inline';
		var navigates      = root.dataset.submit === 'navigate';
		var resultsUrl     = root.dataset.resultsUrl || '';

		var state = {
			activeSource:    'all',
			response:        null,
			abortController: null,
			providerLabels:  {},
			options:         [],   // the rendered rows, for arrow-key navigation
			activeIndex:     -1,
			phase:           'idle', // idle | loading | results | empty | error
		};

		getProviders().then( function ( providers ) {
			var available = configuredSources.length
				? providers.filter( function ( p ) { return configuredSources.indexOf( p.id ) !== -1; } )
				: providers;

			available.forEach( function ( p ) { state.providerLabels[ p.id ] = p.label; } );

			if ( showFilters && filtersEl && available.length ) {
				renderFilters( available );
			}
		} );

		function renderFilters( providers ) {
			filtersEl.innerHTML = '';
			filtersEl.hidden = false;

			filtersEl.appendChild( makeFilterLink( 'all', i18n.all || 'All' ) );
			providers.forEach( function ( p ) {
				filtersEl.appendChild( makeFilterLink( p.id, p.label ) );
			} );

			updateFilterCounts();
		}

		function makeFilterLink( id, label ) {
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'global-search__filter' + ( id === state.activeSource ? ' is-active' : '' );
			btn.setAttribute( 'role', 'tab' );
			btn.setAttribute( 'aria-selected', id === state.activeSource ? 'true' : 'false' );
			btn.dataset.source = id;
			btn.dataset.label = label;
			btn.textContent = label;
			btn.addEventListener( 'click', function () { setActiveSource( id ); } );
			return btn;
		}

		function setActiveSource( id ) {
			state.activeSource = id;
			if ( filtersEl ) {
				Array.prototype.forEach.call( filtersEl.querySelectorAll( '.global-search__filter' ), function ( btn ) {
					var isActive = btn.dataset.source === id;
					btn.classList.toggle( 'is-active', isActive );
					btn.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
				} );
			}
			renderResults();
		}

		function updateFilterCounts() {
			if ( ! filtersEl || ! state.response ) {
				return;
			}
			var counts = state.response.counts || {};
			Array.prototype.forEach.call( filtersEl.querySelectorAll( '.global-search__filter' ), function ( btn ) {
				var id = btn.dataset.source;
				var count = counts[ id ] || 0;
				btn.textContent = btn.dataset.label + ' (' + count + ')';
			} );
		}

		function labelFor( sourceId ) {
			return state.providerLabels[ sourceId ] || humanize( sourceId );
		}

		/**
		 * The one place a state is written.
		 *
		 * The same sentence goes to the aria-live region every time; whether it
		 * is also *seen* is the stylesheet's business — inline mode shows that
		 * element, the dropdown hides it visually — plus, in the dropdown, a
		 * visible line inside the panel so "Searching…", "No results found"
		 * and the error all read where the results would have been. Never a
		 * resting state: nothing is said before a search has been asked for.
		 */
		function setPhase( phase, text ) {
			state.phase = phase;
			if ( statusEl ) {
				statusEl.textContent = text || '';
			}
			root.classList.toggle( 'is-loading', phase === 'loading' );

			if ( inline ) {
				return; // statusEl is already visible there
			}

			var line = panel.querySelector( '.global-search__message' );
			if ( phase === 'idle' || phase === 'results' ) {
				if ( line ) line.remove();
				return;
			}
			if ( ! line ) {
				line = document.createElement( 'p' );
				line.className = 'global-search__message';
				resultsEl.parentNode.insertBefore( line, resultsEl );
			}
			line.className = 'global-search__message global-search__message--' + phase;
			line.textContent = text || '';
		}

		function openPanel() {
			if ( ! inline ) {
				panel.hidden = false;
			}
			input.setAttribute( 'aria-expanded', 'true' );
		}

		function closePanel() {
			if ( ! inline ) {
				panel.hidden = true;
			}
			input.setAttribute( 'aria-expanded', 'false' );
			setActiveOption( -1 );
		}

		function syncViewAll( query ) {
			if ( ! viewAllEl ) {
				return;
			}
			if ( ! resultsUrl || ! query ) {
				viewAllEl.hidden = true;
				return;
			}
			var sep = resultsUrl.indexOf( '?' ) === -1 ? '?' : '&';
			viewAllEl.href = resultsUrl + sep + 'q=' + encodeURIComponent( query );
			viewAllEl.hidden = state.phase !== 'results';
		}

		function renderResults() {
			resultsEl.innerHTML = '';
			state.options = [];
			state.activeIndex = -1;
			input.removeAttribute( 'aria-activedescendant' );

			var all = state.response ? ( state.response.results || [] ) : [];
			var filtered = state.activeSource === 'all'
				? all
				: all.filter( function ( r ) { return r.source === state.activeSource; } );

			// The dropdown's cap. Applied after filtering so switching to
			// "Policies" shows that many policies, not that many of whatever
			// happened to rank first overall.
			if ( ! inline ) {
				filtered = state.activeSource === 'all'
					? capAcrossSources( filtered, maxResults )
					: filtered.slice( 0, maxResults );
			}

			if ( ! filtered.length ) {
				if ( inline ) {
					openPanel();
				} else if ( state.phase === 'results' ) {
					// A search that came back with nothing for this filter:
					// say so where the rows would be, rather than closing the
					// panel out from under the visitor's cursor.
					setPhase( 'empty', i18n.noResultsLine || 'No results found' );
					openPanel();
				} else {
					closePanel();
				}
				syncViewAll( input.value.trim() );
				return;
			}

			openPanel();

			if ( state.activeSource === 'all' ) {
				var order = [];
				var groups = {};
				filtered.forEach( function ( r ) {
					if ( ! groups[ r.source ] ) {
						groups[ r.source ] = [];
						order.push( r.source );
					}
					groups[ r.source ].push( r );
				} );

				order.forEach( function ( sourceId ) {
					var heading = document.createElement( 'h3' );
					heading.className = 'global-search__group-heading';
					heading.textContent = labelFor( sourceId );
					resultsEl.appendChild( heading );
					resultsEl.appendChild( renderList( groups[ sourceId ] ) );
				} );
			} else {
				resultsEl.appendChild( renderList( filtered ) );
			}

			syncViewAll( input.value.trim() );
		}

		/**
		 * Six rows across three sources, without letting one of them take all
		 * six.
		 *
		 * A plain `slice( 0, 6 )` of a list ranked by score is the obvious
		 * cap and the wrong one here: "accreditation" scores six site pages
		 * above everything else, so the dropdown says the site has no policies
		 * and no institutions about accreditation, which is false. Dealing
		 * round-robin — each source's best, then each source's second best —
		 * gives every source that matched at least one row, and still fills
		 * the remaining places in score order when only one source matched.
		 *
		 * Source order follows first appearance in the response, which is
		 * already ranked, so the best match overall stays the first row.
		 */
		function capAcrossSources( items, cap ) {
			var order = [];
			var bySource = {};

			items.forEach( function ( item ) {
				if ( ! bySource[ item.source ] ) {
					bySource[ item.source ] = [];
					order.push( item.source );
				}
				bySource[ item.source ].push( item );
			} );

			var picked = [];
			var round = 0;
			while ( picked.length < cap ) {
				var tookOne = false;
				for ( var i = 0; i < order.length && picked.length < cap; i++ ) {
					var list = bySource[ order[ i ] ];
					if ( list[ round ] ) {
						picked.push( list[ round ] );
						tookOne = true;
					}
				}
				if ( ! tookOne ) {
					break; // every source exhausted
				}
				round++;
			}

			// Back into the response's own order, so rows inside a group stay
			// ranked and the groups stay in the order they first appeared.
			return items.filter( function ( item ) { return picked.indexOf( item ) !== -1; } );
		}

		function renderList( items ) {
			var list = document.createElement( 'ul' );
			list.className = 'global-search__list';
			items.forEach( function ( item ) { list.appendChild( renderItem( item ) ); } );
			return list;
		}

		var optionSeq = 0;

		function renderItem( item ) {
			var li = document.createElement( 'li' );
			li.className = 'global-search__result global-search__result--' + item.type;
			li.id = ( input.id || 'gsearch' ) + '-option-' + ( ++optionSeq );
			li.setAttribute( 'role', 'option' );
			li.setAttribute( 'aria-selected', 'false' );
			li.dataset.url = item.url || '';

			var badge = document.createElement( 'span' );
			badge.className = 'global-search__badge';
			badge.textContent = item.type.replace( /_/g, ' ' ).toUpperCase();
			li.appendChild( badge );

			var title = document.createElement( 'a' );
			title.className = 'global-search__title';
			title.href = item.url || '#';
			title.textContent = item.title;
			li.appendChild( title );

			if ( item.excerpt ) {
				var excerpt = document.createElement( 'p' );
				excerpt.className = 'global-search__excerpt';
				excerpt.textContent = item.excerpt;
				li.appendChild( excerpt );
			}

			// The "View Policy →" affordance is for the results page, where
			// there is room for it. In the dropdown the whole row is the link.
			if ( item.url && inline ) {
				var view = document.createElement( 'a' );
				view.className = 'global-search__view';
				view.href = item.url;
				view.textContent = ( i18n.viewPrefix || 'View' ) + ' ' + humanize( item.type ) + ' →';
				li.appendChild( view );
			}

			if ( item.url && ! inline ) {
				li.addEventListener( 'click', function ( e ) {
					// The title anchor handles its own click; this is for the
					// rest of the row.
					if ( ! e.target.closest( 'a' ) ) {
						window.location.href = item.url;
					}
				} );
			}

			state.options.push( li );
			return li;
		}

		// ── Keyboard navigation over the rows ────────────────────────────
		// A dropdown reachable only with a mouse is not a dropdown anyone can
		// use. Arrow keys move an `aria-selected` row, Enter follows it, and
		// Enter with nothing selected falls through to the form — which is
		// what makes "type, Enter" go to the full results page.

		function setActiveOption( index ) {
			state.options.forEach( function ( li ) {
				li.classList.remove( 'is-active' );
				li.setAttribute( 'aria-selected', 'false' );
			} );

			state.activeIndex = index;

			if ( index < 0 || ! state.options[ index ] ) {
				input.removeAttribute( 'aria-activedescendant' );
				return;
			}

			var li = state.options[ index ];
			li.classList.add( 'is-active' );
			li.setAttribute( 'aria-selected', 'true' );
			input.setAttribute( 'aria-activedescendant', li.id );
			if ( li.scrollIntoView ) {
				li.scrollIntoView( { block: 'nearest' } );
			}
		}

		function moveActive( delta ) {
			if ( ! state.options.length ) {
				return;
			}
			var next = state.activeIndex + delta;
			if ( next < 0 ) next = state.options.length - 1;
			if ( next >= state.options.length ) next = 0;
			setActiveOption( next );
		}

		function runSearch( query ) {
			if ( state.abortController ) {
				state.abortController.abort();
			}

			if ( query.length === 0 ) {
				state.response = null;
				setPhase( 'idle', '' );
				renderResults();
				closePanel();
				updateFilterCounts();
				syncViewAll( '' );
				return;
			}

			var controller = new AbortController();
			state.abortController = controller;

			setPhase( 'loading', i18n.loading || 'Searching…' );
			openPanel();
			resultsEl.setAttribute( 'aria-busy', 'true' );

			var url = restUrl + 'search?q=' + encodeURIComponent( query ) + '&results_per_page=' + encodeURIComponent( resultsPerPage );
			if ( configuredSources.length ) {
				url += '&sources=' + encodeURIComponent( configuredSources.join( ',' ) );
			}

			fetch( url, { signal: controller.signal } )
				.then( function ( r ) {
					if ( ! r.ok ) {
						throw new Error( 'gsearch: bad response status ' + r.status );
					}
					return r.json();
				} )
				.then( function ( data ) {
					state.response = data;
					if ( data.total > 0 ) {
						setPhase( 'results', data.total + ' ' + ( i18n.resultsFound || 'results found' ) );
					} else {
						setPhase( 'empty', i18n.noResultsLine || 'No results found' );
					}
					updateFilterCounts();
					renderResults();
				} )
				.catch( function ( err ) {
					if ( err && err.name === 'AbortError' ) {
						return; // superseded by a newer request — not an error
					}
					// Never take the header down with the search: the panel
					// says it is unavailable, the form underneath still submits.
					state.response = null;
					resultsEl.innerHTML = '';
					state.options = [];
					setPhase( 'error', i18n.errorLine || 'Search is temporarily unavailable.' );
					if ( inline ) {
						openPanel();
					}
					syncViewAll( query );
				} )
				.finally( function () {
					resultsEl.removeAttribute( 'aria-busy' );
				} );
		}

		form.addEventListener( 'submit', function ( e ) {
			// `navigate`: leave the browser to it. The action and the field
			// name are already right, so this is a plain GET to the results
			// page — the behaviour a visitor gets with this script absent.
			if ( navigates ) {
				return;
			}
			e.preventDefault();
			clearTimeout( debounceTimer );
			runSearch( input.value.trim() );
		} );

		input.addEventListener( 'focus', function () {
			if ( ! inline && state.response && ( state.response.results || [] ).length ) {
				openPanel();
			}
		} );

		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) {
				closePanel();
				return;
			}
			if ( e.key === 'ArrowDown' ) {
				e.preventDefault();
				moveActive( 1 );
				return;
			}
			if ( e.key === 'ArrowUp' ) {
				e.preventDefault();
				moveActive( -1 );
				return;
			}
			if ( e.key === 'Enter' ) {
				// A row is selected: follow it. Nothing selected: do not touch
				// the event, and the form submits — which is the whole point
				// of requirement "Enter must reach the results page".
				var li = state.options[ state.activeIndex ];
				if ( li && li.dataset.url ) {
					e.preventDefault();
					window.location.href = li.dataset.url;
				}
			}
		} );

		document.addEventListener( 'click', function ( e ) {
			if ( ! root.contains( e.target ) ) {
				closePanel();
			}
		} );

		// A panel left open across a back/forward navigation is a panel
		// showing a search the visitor has already left.
		window.addEventListener( 'pagehide', closePanel );

		var debounceTimer = null;
		if ( config.liveSearch ) {
			input.addEventListener( 'input', function () {
				clearTimeout( debounceTimer );
				var value = input.value.trim();
				if ( value.length === 0 ) {
					runSearch( value );
					return;
				}
				if ( value.length < config.minCharacters ) {
					return; // below the minimum — wait for more characters, no request fired
				}
				debounceTimer = setTimeout( function () { runSearch( value ); }, config.debounceMs || 0 );
			} );
		}

		// The results page: a query arrives in the URL already in the field,
		// so run it without waiting to be asked. This is what makes
		// /search/?q=accreditation a page rather than an empty search box.
		if ( inline && input.value.trim() !== '' ) {
			runSearch( input.value.trim() );
		}
	}

	function init() {
		var roots = document.querySelectorAll( '[data-global-search]' );
		Array.prototype.forEach.call( roots, initInstance );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
