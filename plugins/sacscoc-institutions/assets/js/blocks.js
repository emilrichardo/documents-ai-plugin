/**
 * The three Gutenberg blocks: Institutions Directory, Institutions Search and
 * Institution.
 *
 * Hand-written against the global `wp` object rather than built with JSX and a
 * bundler — the same choice assets/js/directory.js already makes for the
 * front end, kept here so the whole plugin needs no npm install and no build
 * step to run or to edit. `wp.element.createElement` (aliased `el` below)
 * stands in for JSX; everything else is the same `@wordpress/*` packages any
 * block would use, loaded as WordPress's own bundled scripts (see the
 * `editor_script` dependency list in includes/blocks.php).
 *
 * All three render through <ServerSideRender>, calling the exact PHP that
 * backs their shortcode — sacscoc_inst_render_directory_block(),
 * sacscoc_inst_render_search_block() and sacscoc_inst_render_institution_block()
 * in includes/blocks.php, themselves thin wrappers around
 * sacscoc_inst_render_directory(), sacscoc_inst_render_search() and
 * sacscoc_inst_render_institution(). There is no separate "preview" markup to
 * keep in sync with the real thing: the editor shows the same HTML a visitor
 * would get, including whatever background colour, text colour, padding or
 * font size the block's own toolbar controls (from block.json's `supports`)
 * are set to — those are resolved server-side into the wrapper's `style`
 * attribute by `get_block_wrapper_attributes()`, not reimplemented here.
 */
( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var useState = element.useState;
	var useEffect = element.useEffect;
	var useRef = element.useRef;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var PanelRow = components.PanelRow;
	var SelectControl = components.SelectControl;
	var ToggleControl = components.ToggleControl;
	var TextControl = components.TextControl;
	var Spinner = components.Spinner;
	var Notice = components.Notice;
	var ServerSideRender = serverSideRender;

	// Localised from PHP by wp_localize_script() in includes/blocks.php — the
	// same option lists sacscoc_inst_states()/sacscoc_inst_degrees()/
	// sacscoc_inst_reaffirm_years() offer the live search, so a filter set here
	// can never name a state or year the query itself would not recognise.
	var blockData = window.sacscocInstBlocks || { states: {}, degrees: {}, years: [], searchNonce: '', searchAction: '' };

	/** A blank preview for the moment before the first server render arrives. */
	function loadingPlaceholder() {
		return el( 'p', {}, __( 'Loading the institutions directory…', 'sacscoc-institutions' ) );
	}

	/** { '' : 'Any …', ...blockData.states } — every SelectControl needs the unset option first. */
	function withAnyOption( map, anyLabel ) {
		var options = [ { label: anyLabel, value: '' } ];
		Object.keys( map ).forEach( function ( key ) {
			options.push( { label: map[ key ], value: key } );
		} );
		return options;
	}

	blocks.registerBlockType( 'sacscoc-institutions/directory', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps();

			return el( 'div', blockProps,
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Directory settings', 'sacscoc-institutions' ), initialOpen: true },
						el( ToggleControl, {
							label: __( 'Show the search form', 'sacscoc-institutions' ),
							help: attributes.showSearch
								? __( 'Inline, in the layout below.', 'sacscoc-institutions' )
								: __( 'Off — pair with an Institutions Search block placed elsewhere, using the same group below.', 'sacscoc-institutions' ),
							checked: attributes.showSearch,
							onChange: function ( value ) { setAttributes( { showSearch: value } ); }
						} ),
						attributes.showSearch && el( SelectControl, {
							label: __( 'Layout', 'sacscoc-institutions' ),
							value: attributes.layout,
							options: [
								{ label: __( '— Use Settings —', 'sacscoc-institutions' ), value: '' },
								{ label: __( 'Two columns', 'sacscoc-institutions' ), value: 'two-column' },
								{ label: __( 'One column', 'sacscoc-institutions' ), value: 'one-column' }
							],
							onChange: function ( value ) { setAttributes( { layout: value } ); }
						} ),
						el( TextControl, {
							label: __( 'Results per page', 'sacscoc-institutions' ),
							type: 'number',
							min: 1,
							max: 200,
							value: attributes.perPage ? String( attributes.perPage ) : '',
							placeholder: __( 'Use the Settings default', 'sacscoc-institutions' ),
							help: __( 'Leave blank to use Institutions → Settings → Results Per Page.', 'sacscoc-institutions' ),
							onChange: function ( value ) {
								var n = parseInt( value, 10 );
								setAttributes( { perPage: isNaN( n ) || n < 1 ? 0 : n } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show the result count', 'sacscoc-institutions' ),
							checked: attributes.showCount,
							onChange: function ( value ) { setAttributes( { showCount: value } ); }
						} ),
						! attributes.showSearch && el( TextControl, {
							label: __( 'Group', 'sacscoc-institutions' ),
							value: attributes.group,
							help: __( 'Must match the Institutions Search block’s own Group. Only needed when a page has more than one directory/search pair.', 'sacscoc-institutions' ),
							onChange: function ( value ) { setAttributes( { group: value || 'default' } ); }
						} )
					),
					el( PanelBody, { title: __( 'Restrict results', 'sacscoc-institutions' ), initialOpen: false },
						el( PanelRow, {},
							el( 'p', { className: 'components-base-control__help', style: { marginTop: 0 } },
								__( 'Locks this directory — and its inline search, which drops the matching field entirely — to one value. Leave all three on “Any” for the ordinary, unrestricted directory.', 'sacscoc-institutions' )
							)
						),
						el( SelectControl, {
							label: __( 'State', 'sacscoc-institutions' ),
							value: attributes.filterState,
							options: withAnyOption( blockData.states, __( 'Any State', 'sacscoc-institutions' ) ),
							onChange: function ( value ) { setAttributes( { filterState: value } ); }
						} ),
						el( SelectControl, {
							label: __( 'Highest degree offered', 'sacscoc-institutions' ),
							value: attributes.filterDegree,
							options: withAnyOption( blockData.degrees, __( 'Any Degree', 'sacscoc-institutions' ) ),
							onChange: function ( value ) { setAttributes( { filterDegree: value } ); }
						} ),
						el( SelectControl, {
							label: __( 'Next reaffirmation year', 'sacscoc-institutions' ),
							value: attributes.filterYear,
							options: withAnyOption(
								blockData.years.reduce( function ( acc, y ) { acc[ y ] = String( y ); return acc; }, {} ),
								__( 'Any Year', 'sacscoc-institutions' )
							),
							onChange: function ( value ) { setAttributes( { filterYear: value } ); }
						} )
					),
					el( PanelBody, { title: __( 'Headings', 'sacscoc-institutions' ), initialOpen: false },
						attributes.showSearch && el( TextControl, {
							label: __( 'Search panel heading', 'sacscoc-institutions' ),
							value: attributes.searchHeading,
							placeholder: __( 'Institution Search', 'sacscoc-institutions' ),
							onChange: function ( value ) { setAttributes( { searchHeading: value } ); }
						} ),
						el( TextControl, {
							label: __( 'Results heading', 'sacscoc-institutions' ),
							value: attributes.resultsHeading,
							placeholder: __( 'Results', 'sacscoc-institutions' ),
							onChange: function ( value ) { setAttributes( { resultsHeading: value } ); }
						} ),
						el( PanelRow, {},
							el( 'p', { className: 'components-base-control__help', style: { marginTop: 0 } },
								__( 'Background, text colour, padding and font size are set from this block’s own toolbar and sidebar — the ordinary block controls, not a setting here.', 'sacscoc-institutions' )
							)
						)
					)
				),
				el( ServerSideRender, {
					block: 'sacscoc-institutions/directory',
					attributes: attributes,
					LoadingResponsePlaceholder: loadingPlaceholder,
					EmptyResponsePlaceholder: loadingPlaceholder
				} )
			);
		},
		save: function () {
			return null;
		}
	} );

	blocks.registerBlockType( 'sacscoc-institutions/search', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps();

			return el( 'div', blockProps,
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Search settings', 'sacscoc-institutions' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Layout', 'sacscoc-institutions' ),
							value: attributes.layout,
							options: [
								{ label: __( 'Vertical — the search panel', 'sacscoc-institutions' ), value: 'vertical' },
								{ label: __( 'Horizontal — a single search bar', 'sacscoc-institutions' ), value: 'horizontal' }
							],
							help: attributes.layout === 'horizontal'
								? __( 'Fields joined into one bar, the button welded to the end of it — the shape the site’s own Find an Institution page uses.', 'sacscoc-institutions' )
								: __( 'The same panel the Institutions Directory block’s own inline form uses.', 'sacscoc-institutions' ),
							onChange: function ( value ) { setAttributes( { layout: value } ); }
						} ),
						el( TextControl, {
							label: __( 'Heading', 'sacscoc-institutions' ),
							value: attributes.heading,
							placeholder: __( 'Institution Search', 'sacscoc-institutions' ),
							onChange: function ( value ) { setAttributes( { heading: value } ); }
						} ),
						el( TextControl, {
							label: __( 'Group', 'sacscoc-institutions' ),
							value: attributes.group,
							help: __( 'Must match the Institutions Directory block’s own Group, on the page where that block has “Show the search form” turned off. Only needed when a page has more than one pair.', 'sacscoc-institutions' ),
							onChange: function ( value ) { setAttributes( { group: value || 'default' } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Constrain width to match the directory', 'sacscoc-institutions' ),
							help: attributes.containWidth
								? __( 'Capped at the same measure as the Institutions Directory block, centred — on by default so a search panel placed above a directory lines up with it.', 'sacscoc-institutions' )
								: __( 'Off — the panel fills whatever space it is placed in, edge to edge. Turn this off when the panel lives somewhere narrower than the directory, like a sidebar.', 'sacscoc-institutions' ),
							checked: attributes.containWidth,
							onChange: function ( value ) { setAttributes( { containWidth: value } ); }
						} )
					)
				),
				el( ServerSideRender, {
					block: 'sacscoc-institutions/search',
					attributes: attributes,
					LoadingResponsePlaceholder: loadingPlaceholder,
					EmptyResponsePlaceholder: loadingPlaceholder
				} )
			);
		},
		save: function () {
			return null;
		}
	} );

	blocks.registerBlockType( 'sacscoc-institutions/institution', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps();

			var stateQuery = useState( attributes.institutionName || '' );
			var query = stateQuery[ 0 ];
			var setQuery = stateQuery[ 1 ];

			var stateResults = useState( [] );
			var results = stateResults[ 0 ];
			var setResults = stateResults[ 1 ];

			var stateLoading = useState( false );
			var loading = stateLoading[ 0 ];
			var setLoading = stateLoading[ 1 ];

			var stateOpen = useState( false );
			var open = stateOpen[ 0 ];
			var setOpen = stateOpen[ 1 ];

			var debounceRef = useRef( null );

			// Search-as-you-type against wp_ajax_sacscoc_inst_search_institutions
			// (includes/blocks.php) — the same name matching the public search
			// runs, so what an admin finds here is exactly what a visitor could
			// find. Debounced so picking a name out of 1,200+ institutions does
			// not fire a request per keystroke.
			useEffect( function () {
				if ( debounceRef.current ) { clearTimeout( debounceRef.current ); }

				if ( ! open || query.trim().length < 2 ) {
					setResults( [] );
					return;
				}

				setLoading( true );
				debounceRef.current = setTimeout( function () {
					var url = window.ajaxurl
						+ '?action=' + encodeURIComponent( blockData.searchAction )
						+ '&nonce=' + encodeURIComponent( blockData.searchNonce )
						+ '&q=' + encodeURIComponent( query.trim() );

					fetch( url, { credentials: 'same-origin' } )
						.then( function ( response ) { return response.json(); } )
						.then( function ( body ) {
							setResults( body && body.success ? body.data : [] );
						} )
						.catch( function () { setResults( [] ); } )
						.finally( function () { setLoading( false ); } );
				}, 350 );

				return function () {
					if ( debounceRef.current ) { clearTimeout( debounceRef.current ); }
				};
				// eslint-disable-next-line
			}, [ query, open ] );

			function selectInstitution( item ) {
				setAttributes( { institutionId: item.id, institutionName: item.name } );
				setQuery( item.name );
				setResults( [] );
				setOpen( false );
			}

			return el( 'div', blockProps,
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Institution', 'sacscoc-institutions' ), initialOpen: true },
						el( ToggleControl, {
							label: __( 'Show the “Back to Results” button', 'sacscoc-institutions' ),
							checked: attributes.showBack,
							onChange: function ( value ) { setAttributes( { showBack: value } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Show the “About SACSCOC” block', 'sacscoc-institutions' ),
							checked: attributes.showAbout,
							onChange: function ( value ) { setAttributes( { showAbout: value } ); }
						} )
					)
				),
				el( 'div', { className: 'sacscoc-institution-block-picker', style: { position: 'relative', marginBottom: '12px' } },
					el( TextControl, {
						label: __( 'Institution', 'sacscoc-institutions' ),
						value: query,
						placeholder: __( 'Search by name…', 'sacscoc-institutions' ),
						help: attributes.institutionId
							? __( 'Selected — id ', 'sacscoc-institutions' ) + attributes.institutionId + __( '. Search again to change it.', 'sacscoc-institutions' )
							: __( 'Type at least two letters of the institution’s name.', 'sacscoc-institutions' ),
						onChange: function ( value ) {
							setQuery( value );
							setOpen( true );
							if ( attributes.institutionId ) {
								setAttributes( { institutionId: 0, institutionName: '' } );
							}
						},
						onFocus: function () { setOpen( true ); }
					} ),
					loading && el( Spinner, {} ),
					open && results.length > 0 && el( 'ul', {
						className: 'sacscoc-institution-block-results',
						style: {
							position: 'absolute', zIndex: 10, left: 0, right: 0,
							margin: 0, padding: '4px 0', listStyle: 'none',
							background: '#fff', border: '1px solid #ddd',
							maxHeight: '260px', overflowY: 'auto',
							boxShadow: '0 4px 10px rgba(0,0,0,0.08)'
						}
					}, results.map( function ( item ) {
						return el( 'li', { key: item.id },
							el( 'button', {
								type: 'button',
								className: 'components-button',
								style: { display: 'block', width: '100%', textAlign: 'left', padding: '6px 10px', borderRadius: 0 },
								onClick: function () { selectInstitution( item ); }
							}, item.label )
						);
					} ) ),
					open && ! loading && query.trim().length >= 2 && results.length === 0 && el(
						Notice, { status: 'warning', isDismissible: false },
						__( 'No institution matches that name.', 'sacscoc-institutions' )
					)
				),
				attributes.institutionId
					? el( ServerSideRender, {
						block: 'sacscoc-institutions/institution',
						attributes: attributes,
						LoadingResponsePlaceholder: loadingPlaceholder,
						EmptyResponsePlaceholder: loadingPlaceholder
					} )
					: el( 'p', {}, __( 'Search above and choose an institution to preview its record here.', 'sacscoc-institutions' ) )
			);
		},
		save: function () {
			return null;
		}
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.serverSideRender,
	window.wp.i18n
);
