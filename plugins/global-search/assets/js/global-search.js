/**
 * Global Search frontend. Plain DOM + fetch, no jQuery, no build step.
 *
 * Filtering decision (brief section 12): the "All | Policies | Institutions
 * | Site" tabs never trigger a new request. Every search already asks the
 * REST endpoint for every enabled/allowed provider at once (each provider
 * capped server-side — see class-search-service.php), so switching tabs is
 * just re-rendering the one response already in memory, grouped or filtered
 * by `result.source`. A second request per tab would only be justified once
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
		var form       = root.querySelector( '.global-search__form' );
		var input      = root.querySelector( '.global-search__input' );
		var filtersEl  = root.querySelector( '.global-search__filters' );
		var statusEl   = root.querySelector( '.global-search__status' );
		var resultsEl  = root.querySelector( '.global-search__results' );

		if ( ! form || ! input || ! resultsEl ) {
			return;
		}

		var configuredSources = ( root.dataset.sources || '' )
			.split( ',' )
			.map( function ( s ) { return s.trim(); } )
			.filter( Boolean );
		var resultsPerPage = parseInt( root.dataset.resultsPerPage, 10 ) || 10;
		var showFilters    = root.dataset.showFilters === '1';

		var state = {
			activeSource:    'all',
			response:        null,
			abortController: null,
			providerLabels:  {},
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

			filtersEl.appendChild( makeFilterButton( 'all', i18n.all || 'All' ) );
			providers.forEach( function ( p ) {
				filtersEl.appendChild( makeFilterButton( p.id, p.label ) );
			} );

			updateFilterCounts();
		}

		function makeFilterButton( id, label ) {
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

		function setStatus( text ) {
			statusEl && ( statusEl.textContent = text );
		}

		function clearResults() {
			resultsEl.innerHTML = '';
		}

		function renderEmptyState( message ) {
			clearResults();
			var p = document.createElement( 'p' );
			p.className = 'global-search__empty';
			p.textContent = message;
			resultsEl.appendChild( p );
		}

		function renderResults() {
			clearResults();

			if ( ! state.response ) {
				renderEmptyState( i18n.noResults || 'No results' );
				return;
			}

			var all = state.response.results || [];
			var filtered = state.activeSource === 'all'
				? all
				: all.filter( function ( r ) { return r.source === state.activeSource; } );

			if ( ! filtered.length ) {
				renderEmptyState( i18n.noResults || 'No results' );
				return;
			}

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
		}

		function renderList( items ) {
			var list = document.createElement( 'ul' );
			list.className = 'global-search__list';
			items.forEach( function ( item ) { list.appendChild( renderItem( item ) ); } );
			return list;
		}

		function renderItem( item ) {
			var li = document.createElement( 'li' );
			li.className = 'global-search__result global-search__result--' + item.type;

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

			if ( item.url ) {
				var view = document.createElement( 'a' );
				view.className = 'global-search__view';
				view.href = item.url;
				view.textContent = ( i18n.viewPrefix || 'View' ) + ' ' + humanize( item.type ) + ' →';
				li.appendChild( view );
			}

			return li;
		}

		function runSearch( query ) {
			if ( state.abortController ) {
				state.abortController.abort();
			}

			if ( query.length === 0 ) {
				state.response = null;
				setStatus( '' );
				renderEmptyState( i18n.noResults || 'No results' );
				updateFilterCounts();
				return;
			}

			var controller = new AbortController();
			state.abortController = controller;

			setStatus( i18n.loading || 'Searching…' );
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
					setStatus( '' );
					updateFilterCounts();
					renderResults();
				} )
				.catch( function ( err ) {
					if ( err && err.name === 'AbortError' ) {
						return; // superseded by a newer request — not an error
					}
					setStatus( i18n.error || 'Something went wrong. Please try again.' );
					renderEmptyState( i18n.error || 'Something went wrong. Please try again.' );
				} )
				.finally( function () {
					resultsEl.removeAttribute( 'aria-busy' );
				} );
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			clearTimeout( debounceTimer );
			runSearch( input.value.trim() );
		} );

		var debounceTimer = null;
		if ( config.liveSearch ) {
			input.addEventListener( 'input', function () {
				clearTimeout( debounceTimer );
				var value = input.value.trim();
				if ( value.length > 0 && value.length < config.minCharacters ) {
					return; // below the minimum — wait for more characters, no request fired
				}
				debounceTimer = setTimeout( function () { runSearch( value ); }, config.debounceMs || 0 );
			} );
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
