/**
 * The two Gutenberg blocks: Policies and Policy.
 *
 * Hand-written against the global `wp` object rather than built with JSX and a
 * bundler — this plugin ships no npm install and no build step, and the rest of
 * its JavaScript (aidocs-pdf-structure.js, aidocs-docx-structure.js) is written
 * the same way. `wp.element.createElement`, aliased `el` below, stands in for
 * JSX; everything else is the same `@wordpress/*` packages any block would use,
 * loaded as WordPress's own bundled scripts (see the `editor_script` dependency
 * list in includes/aidocs-blocks.php).
 *
 * Both render through <ServerSideRender>, calling the exact PHP that backs
 * their shortcode — aidocs_render_policies_block() and
 * aidocs_render_policy_block(), themselves wrappers around
 * aidocs_search_shortcode() and aidocs_document_shortcode(). There is no
 * separate "preview" markup to keep in sync with the real thing: the editor
 * shows the same HTML a visitor gets, including whatever background colour,
 * padding or font size the block's own controls are set to — resolved
 * server-side by get_block_wrapper_attributes(), not reimplemented here.
 *
 * The one thing the preview cannot show is behaviour: the search panel's
 * JavaScript is printed at `wp_footer`, which never fires for the REST request
 * ServerSideRender makes, so results only appear on the published page. The
 * render callback says so, in the preview, rather than leaving an empty list
 * looking like a fault.
 */
( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var ToggleControl = components.ToggleControl;
	var RangeControl = components.RangeControl;
	var ServerSideRender = serverSideRender;

	// Localised from PHP by wp_localize_script() in includes/aidocs-blocks.php —
	// the same Document Types Settings offers and the same published policies
	// the shortcode would find, so a type chosen here can never be one the
	// search itself would not recognise.
	var blockData = window.aidocsBlocks || { types: [], policies: [] };

	/** The moment before the first server render arrives. */
	function placeholder( text ) {
		return el( 'p', { style: { color: '#646970', fontStyle: 'italic' } }, text );
	}

	blocks.registerBlockType( 'ai-documents/policies', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps();

			var typeOptions = [ { label: __( 'All types' ), value: '' } ];
			( blockData.types || [] ).forEach( function ( type ) {
				typeOptions.push( { label: type, value: type } );
			} );

			return el( 'div', blockProps,
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Listing' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Document type' ),
							help: __( 'Show only one type. A ?type= in the URL still overrides this.' ),
							value: attributes.type,
							options: typeOptions,
							onChange: function ( value ) { setAttributes( { type: value } ); }
						} ),
						el( RangeControl, {
							label: __( 'Results per page' ),
							value: attributes.perPage,
							min: 1,
							max: 50,
							onChange: function ( value ) { setAttributes( { perPage: value || 20 } ); }
						} ),
						el( ToggleControl, {
							label: __( 'AI suggestions in the search bar' ),
							help: attributes.showAi
								? __( 'On — typing a question also returns an AI reading of it.' )
								: __( 'Off — keyword search only.' ),
							checked: attributes.showAi,
							onChange: function ( value ) { setAttributes( { showAi: value } ); }
						} )
					)
				),
				el( ServerSideRender, {
					block: 'ai-documents/policies',
					attributes: attributes,
					EmptyResponsePlaceholder: function () {
						return placeholder( __( 'No policies to list yet.' ) );
					},
					LoadingResponsePlaceholder: function () {
						return placeholder( __( 'Loading the policy listing…' ) );
					}
				} )
			);
		},
		save: function () { return null; }
	} );

	blocks.registerBlockType( 'ai-documents/policy', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps();

			var policyOptions = [ { label: __( '— Choose a policy —' ), value: 0 } ];
			( blockData.policies || [] ).forEach( function ( policy ) {
				policyOptions.push( { label: policy.label, value: policy.id } );
			} );

			return el( 'div', blockProps,
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Policy' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Which policy' ),
							help: __( 'Published policies, by title. The list stops at 200 — past that, use [aidocs_document id="…"].' ),
							value: attributes.documentId,
							options: policyOptions,
							onChange: function ( value ) { setAttributes( { documentId: parseInt( value, 10 ) || 0 } ); }
						} )
					)
				),
				el( ServerSideRender, {
					block: 'ai-documents/policy',
					attributes: attributes,
					EmptyResponsePlaceholder: function () {
						return placeholder( __( 'Nothing to show — this policy has no extracted content yet.' ) );
					},
					LoadingResponsePlaceholder: function () {
						return placeholder( __( 'Loading the policy…' ) );
					}
				} )
			);
		},
		save: function () { return null; }
	} );

} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.serverSideRender,
	window.wp.i18n
);
