/**
 * Global Search block editor UI. Hand-written against the global `wp`
 * object rather than JSX + a bundler, the same choice this monorepo already
 * makes for sacscoc-institutions/assets/js/blocks.js — no npm install, no
 * build step, edit the file and reload.
 *
 * Renders through <ServerSideRender>, calling the exact PHP the shortcode
 * uses (gsearch_render_block() -> gsearch_render()) — there is no separate
 * preview markup to keep in sync with the real thing.
 */
( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var ToggleControl = components.ToggleControl;
	var RangeControl = components.RangeControl;
	var SelectControl = components.SelectControl;
	var CheckboxControl = components.CheckboxControl;
	var RadioControl = components.RadioControl;
	var ServerSideRender = serverSideRender;

	// Populated from PHP (includes/blocks.php) with the providers actually
	// available right now, so a deactivated plugin's checkbox never appears.
	var blockData = window.globalSearchBlockData || { providers: [] };

	function sourcesToArray( sources ) {
		return ( sources || '' ).split( ',' ).map( function ( s ) { return s.trim(); } ).filter( Boolean );
	}

	function sourcesToString( list ) {
		return list.join( ',' );
	}

	blocks.registerBlockType( 'global-search/search', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps();
			var selectedSources = sourcesToArray( attributes.sources );
			var allSelected = selectedSources.length === 0;

			function toggleSource( id, checked ) {
				var current = allSelected
					? blockData.providers.map( function ( p ) { return p.id; } )
					: selectedSources.slice();

				if ( checked && current.indexOf( id ) === -1 ) {
					current.push( id );
				} else if ( ! checked ) {
					current = current.filter( function ( existing ) { return existing !== id; } );
				}

				// Every provider checked is the same as none specified (the
				// service treats an empty `sources` as "all") — normalise so
				// the attribute does not silently drift into a stale allow-list.
				if ( current.length === blockData.providers.length ) {
					setAttributes( { sources: '' } );
				} else {
					setAttributes( { sources: sourcesToString( current ) } );
				}
			}

			return el(
				element.Fragment,
				{},
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'Global Search settings', 'global-search' ) },
						el( TextControl, {
							label: __( 'Placeholder', 'global-search' ),
							value: attributes.placeholder,
							onChange: function ( value ) { setAttributes( { placeholder: value } ); },
						} ),
						el( ToggleControl, {
							label: __( 'Show source filters', 'global-search' ),
							checked: !! attributes.showFilters,
							onChange: function ( value ) { setAttributes( { showFilters: value } ); },
						} ),
						el( RangeControl, {
							label: __( 'Results per page', 'global-search' ),
							value: attributes.resultsPerPage,
							min: 1,
							max: 50,
							onChange: function ( value ) { setAttributes( { resultsPerPage: value } ); },
						} ),
						el( SelectControl, {
							label: __( 'Variant', 'global-search' ),
							value: attributes.variant,
							options: [
								{ label: __( 'Default', 'global-search' ), value: 'default' },
								{ label: __( 'Compact (for headers)', 'global-search' ), value: 'compact' },
							],
							onChange: function ( value ) { setAttributes( { variant: value } ); },
						} ),
						el( RadioControl, {
							label: __( 'Shape', 'global-search' ),
							selected: attributes.shape,
							options: [
								{ label: __( 'Rectangular', 'global-search' ), value: 'rectangular' },
								{ label: __( 'Rounded (navbar style)', 'global-search' ), value: 'rounded' },
							],
							onChange: function ( value ) { setAttributes( { shape: value } ); },
						} ),
						el(
							'p',
							{ className: 'components-base-control__label' },
							__( 'Sources', 'global-search' )
						),
						blockData.providers.map( function ( provider ) {
							return el( CheckboxControl, {
								key: provider.id,
								label: provider.label,
								checked: allSelected || selectedSources.indexOf( provider.id ) !== -1,
								onChange: function ( checked ) { toggleSource( provider.id, checked ); },
							} );
						} )
					)
				),
				el(
					'div',
					blockProps,
					el( ServerSideRender, {
						block: 'global-search/search',
						attributes: attributes,
					} )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.serverSideRender, window.wp.i18n );
