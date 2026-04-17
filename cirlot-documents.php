<?php
/**
 * Plugin Name: Cirlot Documents
 * Description: Custom post type for managing documents with file upload, metadata, and taxonomies.
 * Version: 1.0.0
 * Author: Cirlot
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'CIRLOT_DOCS_DIR', plugin_dir_path( __FILE__ ) );
define( 'CIRLOT_DOCS_URL', plugin_dir_url( __FILE__ ) );

define( 'CIRLOT_DOCS_AUDIENCES', [ 'Institution', 'Evaluator', 'Public' ] );
define( 'CIRLOT_DOCS_TYPES', [
    'Policies', 'Guidelines', 'Good Practices', 'Position Statements',
    'Handbooks', 'Interpretation', 'Guides', 'Rules of the Organization', 'Forms and Templates',
] );

// ──────────────────────────────────────────────
// 0. Enqueue media uploader scripts
// ──────────────────────────────────────────────
add_action( 'admin_enqueue_scripts', 'cirlot_docs_enqueue_scripts' );
function cirlot_docs_enqueue_scripts( $hook ) {
    if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;
    if ( get_post_type() !== 'cirlot_document' && get_current_screen()->post_type !== 'cirlot_document' ) return;
    wp_enqueue_media();
}

// ──────────────────────────────────────────────
// 1. Register Custom Post Type
// ──────────────────────────────────────────────
add_action( 'init', 'cirlot_docs_register_post_type' );
function cirlot_docs_register_post_type() {
    register_post_type( 'cirlot_document', [
        'labels' => [
            'name'               => __( 'Documents' ),
            'singular_name'      => __( 'Document' ),
            'add_new'            => __( 'Add New Document' ),
            'add_new_item'       => __( 'Add New Document' ),
            'edit_item'          => __( 'Edit Document' ),
            'all_items'          => __( 'All Documents' ),
            'search_items'       => __( 'Search Documents' ),
        ],
        'public'       => true,
        'has_archive'  => true,
        'rewrite'      => [ 'slug' => get_option( 'cirlot_docs_archive_slug', 'documents' ) ],
        'supports'     => [ 'title' ],
        'menu_icon'    => 'dashicons-media-document',
        'show_in_rest' => false,
    ] );
}

// ──────────────────────────────────────────────
// 2. Register Taxonomies
// ──────────────────────────────────────────────
add_action( 'init', 'cirlot_docs_register_taxonomies' );
function cirlot_docs_register_taxonomies() {
    // Audience
    register_taxonomy( 'document_audience', 'cirlot_document', [
        'labels' => [
            'name'          => __( 'Audiences' ),
            'singular_name' => __( 'Audience' ),
            'add_new_item'  => __( 'Add New Audience' ),
        ],
        'hierarchical'      => false,
        'show_ui'           => false, // managed via meta box
        'show_in_rest'      => false,
        'rewrite'           => [ 'slug' => 'document-audience' ],
    ] );

    // Document Type
    register_taxonomy( 'document_type', 'cirlot_document', [
        'labels' => [
            'name'          => __( 'Document Types' ),
            'singular_name' => __( 'Document Type' ),
            'add_new_item'  => __( 'Add New Document Type' ),
        ],
        'hierarchical'      => false,
        'show_ui'           => false, // managed via meta box
        'show_in_rest'      => false,
        'rewrite'           => [ 'slug' => 'document-type' ],
    ] );
}

// ──────────────────────────────────────────────
// 3. Meta Boxes
// ──────────────────────────────────────────────
add_action( 'add_meta_boxes', 'cirlot_docs_add_meta_boxes' );
function cirlot_docs_add_meta_boxes() {
    add_meta_box(
        'cirlot_documents_meta',
        __( 'Documents' ),
        'cirlot_docs_meta_box_html',
        'cirlot_document',
        'normal',
        'high'
    );
}

function cirlot_docs_meta_box_html( $post ) {
    wp_nonce_field( 'cirlot_docs_save', 'cirlot_docs_nonce' );

    $file_id     = get_post_meta( $post->ID, '_document_file_id', true );
    $pub_date    = get_post_meta( $post->ID, '_document_pub_date', true );
    $file_format = get_post_meta( $post->ID, '_document_file_format', true );
    $description = get_post_meta( $post->ID, '_document_description', true );

    // Taxonomy terms
    $audience_terms = wp_get_post_terms( $post->ID, 'document_audience', [ 'fields' => 'names' ] );
    $type_terms     = wp_get_post_terms( $post->ID, 'document_type',     [ 'fields' => 'names' ] );

    $audience_val = implode( ', ', (array) $audience_terms );
    $type_val     = implode( ', ', (array) $type_terms );

    // File info
    $file_name = $file_url = $file_size = '';
    if ( $file_id ) {
        $file_path = get_attached_file( $file_id );
        $file_name = basename( $file_path );
        $file_url  = wp_get_attachment_url( $file_id );
        $file_size = $file_path && file_exists( $file_path )
            ? size_format( filesize( $file_path ) )
            : '';
        $title = get_the_title( $file_id );
    }

    $formats = [ 'pdf' => 'PDF', 'word' => 'Word', 'excel' => 'Excel' ];
    ?>
    <style>
        .cirlot-docs-wrap { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .cirlot-docs-wrap .cd-row { display: flex; gap: 24px; margin-bottom: 16px; }
        .cirlot-docs-wrap .cd-col { flex: 1; }
        .cirlot-docs-wrap label { display: block; font-weight: 600; margin-bottom: 6px; }
        .cirlot-docs-wrap input[type="text"],
        .cirlot-docs-wrap input[type="date"],
        .cirlot-docs-wrap textarea { width: 100%; box-sizing: border-box; padding: 6px 10px; border: 1px solid #8c8f94; border-radius: 3px; }
        .cirlot-docs-wrap textarea { height: 120px; resize: vertical; }
        .cd-file-card { display: flex; align-items: center; gap: 16px; padding: 14px 16px; border: 1px solid #e0e0e0; border-radius: 6px; margin-bottom: 10px; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
        .cd-file-icon { flex-shrink: 0; width: 44px; height: 52px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; letter-spacing: .5px; color: #fff; position: relative; }
        .cd-file-icon::after { content: ''; position: absolute; top: 0; right: 0; width: 0; height: 0; border-style: solid; border-width: 0 10px 10px 0; border-color: transparent rgba(0,0,0,.15) transparent transparent; }
        .cd-file-icon.pdf  { background: #e74c3c; }
        .cd-file-icon.word { background: #2b5797; }
        .cd-file-icon.excel { background: #1e7145; }
        .cd-file-icon.generic { background: #7f8c8d; }
        .cd-file-meta { flex: 1; min-width: 0; }
        .cd-file-meta strong { display: block; font-size: 14px; color: #1d2327; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .cd-file-meta span { display: inline-block; font-size: 12px; color: #646970; margin-right: 12px; }
        .cd-file-meta a { color: #2271b1; text-decoration: none; font-size: 12px; }
        .cd-file-meta a:hover { text-decoration: underline; }
        .cd-file-actions { display: flex; gap: 8px; flex-shrink: 0; }
        .cd-radio-group label { display: inline-flex; align-items: center; gap: 6px; font-weight: normal; margin-right: 16px; }
        .cd-select-wrap { position: relative; }
        .cd-select-box { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; min-height: 36px; padding: 5px 8px; border: 1px solid #8c8f94; border-radius: 3px; cursor: pointer; background: #fff; }
        .cd-select-box:focus-within { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; }
        .cd-tag { background: #e0e0e0; border-radius: 3px; padding: 2px 8px; font-size: 12px; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; }
        .cd-tag .remove-tag { cursor: pointer; color: #555; font-weight: bold; border: none; background: none; padding: 0; line-height: 1; font-size: 14px; }
        .cd-select-input { border: none; outline: none; flex: 1; min-width: 80px; font-size: 13px; padding: 0; background: transparent; }
        .cd-dropdown { display: none; position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #8c8f94; border-top: none; border-radius: 0 0 3px 3px; z-index: 9999; max-height: 220px; overflow-y: auto; box-shadow: 0 4px 8px rgba(0,0,0,.1); }
        .cd-dropdown.open { display: block; }
        .cd-dropdown li { list-style: none; padding: 8px 12px; cursor: pointer; font-size: 13px; }
        .cd-dropdown li:hover, .cd-dropdown li.highlighted { background: #4a90d9; color: #fff; }
        .cd-dropdown li.disabled { color: #aaa; background: #f5f5f5; cursor: default; }
        .required { color: red; }
    </style>

    <div class="cirlot-docs-wrap">

        <!-- File Upload -->
        <div style="margin-bottom:16px;">
            <label><?php esc_html_e( 'Document' ); ?> <span class="required">*</span></label>

            <?php
            $ext       = $file_id ? strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) ) : '';
            $icon_class = match( $ext ) { 'pdf' => 'pdf', 'doc', 'docx' => 'word', 'xls', 'xlsx' => 'excel', default => 'generic' };
            $icon_label = match( $ext ) { 'pdf' => 'PDF', 'doc' => 'DOC', 'docx' => 'DOCX', 'xls' => 'XLS', 'xlsx' => 'XLSX', default => strtoupper( $ext ) ?: 'FILE' };
            ?>
            <div class="cd-file-card" id="cd-file-preview" <?php echo $file_id ? '' : 'style="display:none"'; ?>>
                <div class="cd-file-icon <?php echo esc_attr( $icon_class ); ?>" id="cd-file-icon-badge"><?php echo esc_html( $icon_label ); ?></div>
                <div class="cd-file-meta" id="cd-file-meta">
                    <?php if ( $file_id ) : ?>
                    <strong><?php echo esc_html( $title ?: $file_name ); ?></strong>
                    <span><a href="<?php echo esc_url( $file_url ); ?>" target="_blank"><?php echo esc_html( $file_name ); ?></a></span>
                    <?php if ( $file_size ) : ?><span><?php echo esc_html( $file_size ); ?></span><?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="cd-file-actions">
                    <button type="button" id="cd-upload-btn" class="button button-small"><?php esc_html_e( 'Replace' ); ?></button>
                    <button type="button" id="cd-remove-file" class="button button-small"><?php esc_html_e( 'Remove' ); ?></button>
                </div>
            </div>

            <input type="hidden" name="document_file_id" id="cd-file-id" value="<?php echo esc_attr( $file_id ); ?>">
            <?php if ( ! $file_id ) : ?>
            <button type="button" id="cd-upload-btn" class="button"><?php esc_html_e( 'Upload File' ); ?></button>
            <?php endif; ?>
        </div>

        <!-- Publication Date + File Format -->
        <div class="cd-row">
            <div class="cd-col">
                <label for="cd-pub-date"><?php esc_html_e( 'Publication Date' ); ?> <span class="required">*</span></label>
                <input type="date" id="cd-pub-date" name="document_pub_date" value="<?php echo esc_attr( $pub_date ); ?>">
            </div>
            <div class="cd-col">
                <label><?php esc_html_e( 'File Format' ); ?> <span class="required">*</span></label>
                <div class="cd-radio-group">
                    <?php foreach ( $formats as $val => $label ) : ?>
                    <label>
                        <input type="radio" name="document_file_format" value="<?php echo esc_attr( $val ); ?>"
                            <?php checked( $file_format, $val ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Audience + Document Type -->
        <div class="cd-row">
            <div class="cd-col">
                <label><?php esc_html_e( 'Audience' ); ?></label>
                <div class="cd-select-wrap">
                    <div class="cd-select-box" id="cd-audience-box">
                        <?php foreach ( (array) $audience_terms as $term ) : ?>
                        <span class="cd-tag"><?php echo esc_html( $term ); ?><button type="button" class="remove-tag" aria-label="Remove">×</button></span>
                        <?php endforeach; ?>
                        <input type="text" class="cd-select-input" placeholder="<?php esc_attr_e( 'Select audience…' ); ?>" autocomplete="off">
                    </div>
                    <ul class="cd-dropdown" id="cd-audience-dropdown">
                        <?php foreach ( CIRLOT_DOCS_AUDIENCES as $opt ) : ?>
                        <li data-value="<?php echo esc_attr( $opt ); ?>"><?php echo esc_html( $opt ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <input type="hidden" name="document_audience_terms" id="cd-audience-value" value="<?php echo esc_attr( $audience_val ); ?>">
            </div>
            <div class="cd-col">
                <label><?php esc_html_e( 'Document Type' ); ?></label>
                <div class="cd-select-wrap">
                    <div class="cd-select-box" id="cd-type-box">
                        <?php foreach ( (array) $type_terms as $term ) : ?>
                        <span class="cd-tag"><?php echo esc_html( $term ); ?><button type="button" class="remove-tag" aria-label="Remove">×</button></span>
                        <?php endforeach; ?>
                        <input type="text" class="cd-select-input" placeholder="<?php esc_attr_e( 'Select type…' ); ?>" autocomplete="off">
                    </div>
                    <ul class="cd-dropdown" id="cd-type-dropdown">
                        <?php foreach ( CIRLOT_DOCS_TYPES as $opt ) : ?>
                        <li data-value="<?php echo esc_attr( $opt ); ?>"><?php echo esc_html( $opt ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <input type="hidden" name="document_type_terms" id="cd-type-value" value="<?php echo esc_attr( $type_val ); ?>">
            </div>
        </div>

        <!-- Description -->
        <div>
            <label for="cd-description"><?php esc_html_e( 'Document Description' ); ?></label>
            <textarea id="cd-description" name="document_description"><?php echo esc_textarea( $description ); ?></textarea>
        </div>

    </div><!-- .cirlot-docs-wrap -->

    <script>
    (function($) {
        // ── Media uploader ──────────────────────────────
        var mediaFrame;
        var iconMap = { pdf: 'pdf', doc: 'word', docx: 'word', xls: 'excel', xlsx: 'excel' };

        function extOf(filename) {
            return (filename.split('.').pop() || '').toLowerCase();
        }
        function iconClass(ext) { return iconMap[ext] || 'generic'; }
        function iconLabel(ext) {
            var labels = { pdf:'PDF', doc:'DOC', docx:'DOCX', xls:'XLS', xlsx:'XLSX' };
            return labels[ext] || (ext ? ext.toUpperCase() : 'FILE');
        }

        $(document).on('click', '#cd-upload-btn', function(e) {
            e.preventDefault();
            if (mediaFrame) { mediaFrame.open(); return; }
            mediaFrame = wp.media({ title: 'Select Document', button: { text: 'Use this file' }, multiple: false });
            mediaFrame.on('select', function() {
                var a   = mediaFrame.state().get('selection').first().toJSON();
                var ext = extOf(a.filename);
                $('#cd-file-id').val(a.id);
                $('#cd-file-icon-badge')
                    .attr('class', 'cd-file-icon ' + iconClass(ext))
                    .text(iconLabel(ext));
                $('#cd-file-meta').html(
                    '<strong>' + $('<span>').text(a.title || a.filename).html() + '</strong>' +
                    '<span><a href="' + a.url + '" target="_blank">' + $('<span>').text(a.filename).html() + '</a></span>' +
                    (a.filesizeHumanReadable ? '<span>' + a.filesizeHumanReadable + '</span>' : '')
                );
                $('#cd-file-preview').show();
                // swap standalone upload button for the card's Replace button
                $('#cd-upload-btn').not('#cd-file-preview #cd-upload-btn').hide();
            });
            mediaFrame.open();
        });

        $(document).on('click', '#cd-remove-file', function() {
            $('#cd-file-id').val('');
            $('#cd-file-preview').hide();
            $('#cd-file-meta').html('');
            // show standalone upload button if visible
            var $standalone = $('button#cd-upload-btn').not('#cd-file-preview button#cd-upload-btn');
            if (!$standalone.length) {
                $('#cd-file-preview').after('<button type="button" id="cd-upload-btn" class="button">Upload File</button>');
            } else {
                $standalone.show();
            }
        });

        // ── Dropdown tag selects ──────────────────────
        function initDropdownSelect(boxId, dropdownId, hiddenId) {
            var $box      = $('#' + boxId);
            var $dropdown = $('#' + dropdownId);
            var $hidden   = $('#' + hiddenId);
            var $input    = $box.find('.cd-select-input');

            function selectedValues() {
                var vals = [];
                $box.find('.cd-tag').each(function() {
                    vals.push($(this).find('.remove-tag').siblings(':not(.remove-tag)').addBack().not('.remove-tag').map(function(){
                        return this.nodeType === 3 ? this.nodeValue.trim() : '';
                    }).get().join('').trim());
                });
                // simpler: read text excluding the × button
                vals = [];
                $box.find('.cd-tag').each(function() {
                    vals.push($(this).clone().find('.remove-tag').remove().end().text().trim());
                });
                return vals;
            }

            function updateHidden() { $hidden.val(selectedValues().join(', ')); }

            function refreshDropdown(filter) {
                var selected = selectedValues();
                var shown = 0;
                $dropdown.find('li').each(function() {
                    var val  = $(this).data('value');
                    var text = $(this).text().toLowerCase();
                    var hide = selected.indexOf(val) !== -1;
                    var match = !filter || text.indexOf(filter.toLowerCase()) !== -1;
                    $(this).toggle(!hide && match);
                    if (!hide && match) shown++;
                });
                $dropdown.toggleClass('open', shown > 0);
            }

            function addTag(val) {
                if (!val || selectedValues().indexOf(val) !== -1) return;
                $input.before(
                    '<span class="cd-tag">' + $('<span>').text(val).html() +
                    '<button type="button" class="remove-tag" aria-label="Remove">×</button></span>'
                );
                $input.val('');
                updateHidden();
                refreshDropdown('');
            }

            // Open on box click
            $box.on('click', function(e) {
                if ($(e.target).hasClass('remove-tag')) return;
                $input.focus();
                refreshDropdown($input.val());
            });

            // Filter while typing
            $input.on('input', function() {
                refreshDropdown($(this).val());
            });

            // Pick from dropdown
            $dropdown.on('click', 'li', function() {
                addTag($(this).data('value'));
                $input.focus();
            });

            // Remove tag
            $box.on('click', '.remove-tag', function(e) {
                e.stopPropagation();
                $(this).closest('.cd-tag').remove();
                updateHidden();
                refreshDropdown($input.val());
            });

            // Close on outside click
            $(document).on('click', function(e) {
                if (!$box.closest('.cd-select-wrap')[0].contains(e.target)) {
                    $dropdown.removeClass('open');
                    $input.val('');
                }
            });

            // Keyboard: Enter selects highlighted
            $input.on('keydown', function(e) {
                if (e.key === 'Escape') { $dropdown.removeClass('open'); return; }
                var $vis = $dropdown.find('li:visible');
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    var idx = $vis.index($vis.filter('.highlighted'));
                    $vis.removeClass('highlighted');
                    $vis.eq(Math.min(idx + 1, $vis.length - 1)).addClass('highlighted');
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    var idx = $vis.index($vis.filter('.highlighted'));
                    $vis.removeClass('highlighted');
                    $vis.eq(Math.max(idx - 1, 0)).addClass('highlighted');
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    var $h = $vis.filter('.highlighted');
                    if ($h.length) addTag($h.data('value'));
                }
            });
        }

        initDropdownSelect('cd-audience-box', 'cd-audience-dropdown', 'cd-audience-value');
        initDropdownSelect('cd-type-box',     'cd-type-dropdown',     'cd-type-value');

    })(jQuery);
    </script>
    <?php
}

// ──────────────────────────────────────────────
// 4. Save Meta
// ──────────────────────────────────────────────
add_action( 'save_post_cirlot_document', 'cirlot_docs_save_meta' );
function cirlot_docs_save_meta( $post_id ) {
    if ( ! isset( $_POST['cirlot_docs_nonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['cirlot_docs_nonce'], 'cirlot_docs_save' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    // File ID
    if ( isset( $_POST['document_file_id'] ) ) {
        $file_id = absint( $_POST['document_file_id'] );
        if ( $file_id ) {
            update_post_meta( $post_id, '_document_file_id', $file_id );
        } else {
            delete_post_meta( $post_id, '_document_file_id' );
        }
    }

    // Publication Date
    if ( isset( $_POST['document_pub_date'] ) ) {
        $date = sanitize_text_field( $_POST['document_pub_date'] );
        update_post_meta( $post_id, '_document_pub_date', $date );
    }

    // File Format
    $allowed_formats = [ 'pdf', 'word', 'excel' ];
    if ( isset( $_POST['document_file_format'] ) && in_array( $_POST['document_file_format'], $allowed_formats, true ) ) {
        update_post_meta( $post_id, '_document_file_format', $_POST['document_file_format'] );
    }

    // Description
    if ( isset( $_POST['document_description'] ) ) {
        update_post_meta( $post_id, '_document_description', sanitize_textarea_field( $_POST['document_description'] ) );
    }

    // Audience taxonomy
    if ( isset( $_POST['document_audience_terms'] ) ) {
        $terms = array_filter( array_map( 'trim', explode( ',', sanitize_text_field( $_POST['document_audience_terms'] ) ) ) );
        wp_set_post_terms( $post_id, $terms, 'document_audience' );
    }

    // Document Type taxonomy
    if ( isset( $_POST['document_type_terms'] ) ) {
        $terms = array_filter( array_map( 'trim', explode( ',', sanitize_text_field( $_POST['document_type_terms'] ) ) ) );
        wp_set_post_terms( $post_id, $terms, 'document_type' );
    }
}

// ──────────────────────────────────────────────
// 5. Admin Menu — Configuration submenu
// ──────────────────────────────────────────────
add_action( 'admin_menu', 'cirlot_docs_admin_menu' );
function cirlot_docs_admin_menu() {
    add_submenu_page(
        'edit.php?post_type=cirlot_document',
        __( 'Documents Configuration' ),
        __( 'Configuration' ),
        'manage_options',
        'cirlot-docs-settings',
        'cirlot_docs_settings_page'
    );
}

function cirlot_docs_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    if ( isset( $_POST['cirlot_docs_settings_nonce'] ) &&
         wp_verify_nonce( $_POST['cirlot_docs_settings_nonce'], 'cirlot_docs_settings_save' ) ) {

        update_option( 'cirlot_docs_archive_slug',      sanitize_text_field( $_POST['cirlot_docs_archive_slug'] ?? 'documents' ) );
        update_option( 'cirlot_docs_default_audience',  sanitize_text_field( $_POST['cirlot_docs_default_audience'] ?? '' ) );
        update_option( 'cirlot_docs_default_type',      sanitize_text_field( $_POST['cirlot_docs_default_type'] ?? '' ) );
        update_option( 'cirlot_docs_allowed_formats',   array_map( 'sanitize_text_field', (array) ( $_POST['cirlot_docs_allowed_formats'] ?? [ 'pdf', 'word', 'excel' ] ) ) );

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.' ) . '</p></div>';
        flush_rewrite_rules();
    }

    $slug             = get_option( 'cirlot_docs_archive_slug', 'documents' );
    $default_audience = get_option( 'cirlot_docs_default_audience', '' );
    $default_type     = get_option( 'cirlot_docs_default_type', '' );
    $allowed_formats  = (array) get_option( 'cirlot_docs_allowed_formats', [ 'pdf', 'word', 'excel' ] );

    // Existing taxonomy terms for datalist suggestions
    $audience_terms = get_terms( [ 'taxonomy' => 'document_audience', 'hide_empty' => false ] );
    $type_terms     = get_terms( [ 'taxonomy' => 'document_type',     'hide_empty' => false ] );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Documents Configuration' ); ?></h1>
        <form method="post">
            <?php wp_nonce_field( 'cirlot_docs_settings_save', 'cirlot_docs_settings_nonce' ); ?>
            <table class="form-table" role="presentation">

                <tr>
                    <th scope="row"><label for="cd-archive-slug"><?php esc_html_e( 'Archive Slug' ); ?></label></th>
                    <td>
                        <input type="text" id="cd-archive-slug" name="cirlot_docs_archive_slug"
                               value="<?php echo esc_attr( $slug ); ?>" class="regular-text">
                        <p class="description"><?php esc_html_e( 'URL slug for the documents archive. Default: documents' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e( 'Allowed File Formats' ); ?></th>
                    <td>
                        <?php foreach ( [ 'pdf' => 'PDF', 'word' => 'Word', 'excel' => 'Excel' ] as $val => $label ) : ?>
                        <label style="display:inline-flex;align-items:center;gap:6px;margin-right:16px;">
                            <input type="checkbox" name="cirlot_docs_allowed_formats[]"
                                   value="<?php echo esc_attr( $val ); ?>"
                                   <?php checked( in_array( $val, $allowed_formats, true ) ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </label>
                        <?php endforeach; ?>
                        <p class="description"><?php esc_html_e( 'Which file format options appear on the document edit screen.' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="cd-default-audience"><?php esc_html_e( 'Default Audience' ); ?></label></th>
                    <td>
                        <input type="text" id="cd-default-audience" name="cirlot_docs_default_audience"
                               value="<?php echo esc_attr( $default_audience ); ?>"
                               class="regular-text" list="cd-audience-list">
                        <datalist id="cd-audience-list">
                            <?php foreach ( (array) $audience_terms as $t ) : ?>
                            <option value="<?php echo esc_attr( $t->name ); ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <p class="description"><?php esc_html_e( 'Pre-filled audience term when creating a new document.' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="cd-default-type"><?php esc_html_e( 'Default Document Type' ); ?></label></th>
                    <td>
                        <input type="text" id="cd-default-type" name="cirlot_docs_default_type"
                               value="<?php echo esc_attr( $default_type ); ?>"
                               class="regular-text" list="cd-type-list">
                        <datalist id="cd-type-list">
                            <?php foreach ( (array) $type_terms as $t ) : ?>
                            <option value="<?php echo esc_attr( $t->name ); ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <p class="description"><?php esc_html_e( 'Pre-filled document type term when creating a new document.' ); ?></p>
                    </td>
                </tr>

            </table>
            <?php submit_button( __( 'Save Settings' ) ); ?>
        </form>

        <hr>
        <h2><?php esc_html_e( 'Taxonomy Terms' ); ?></h2>
        <div style="display:flex;gap:40px;flex-wrap:wrap;">
            <div>
                <h3><?php esc_html_e( 'Audiences' ); ?></h3>
                <?php if ( $audience_terms && ! is_wp_error( $audience_terms ) ) : ?>
                <ul>
                    <?php foreach ( $audience_terms as $t ) : ?>
                    <li><?php echo esc_html( $t->name ); ?> <span style="color:#888;">(<?php echo (int) $t->count; ?>)</span></li>
                    <?php endforeach; ?>
                </ul>
                <?php else : ?>
                <p><?php esc_html_e( 'No audience terms yet.' ); ?></p>
                <?php endif; ?>
            </div>
            <div>
                <h3><?php esc_html_e( 'Document Types' ); ?></h3>
                <?php if ( $type_terms && ! is_wp_error( $type_terms ) ) : ?>
                <ul>
                    <?php foreach ( $type_terms as $t ) : ?>
                    <li><?php echo esc_html( $t->name ); ?> <span style="color:#888;">(<?php echo (int) $t->count; ?>)</span></li>
                    <?php endforeach; ?>
                </ul>
                <?php else : ?>
                <p><?php esc_html_e( 'No document type terms yet.' ); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

// ──────────────────────────────────────────────
// 6. Admin Columns
// ──────────────────────────────────────────────
add_filter( 'manage_cirlot_document_posts_columns', 'cirlot_docs_admin_columns' );
function cirlot_docs_admin_columns( $cols ) {
    $new = [ 'cb' => $cols['cb'], 'title' => $cols['title'] ];
    $new['_document_pub_date']    = __( 'Publication Date' );
    $new['_document_file_format'] = __( 'Format' );
    $new['document_audience']     = __( 'Audience' );
    $new['document_type']         = __( 'Type' );
    $new['date']                  = $cols['date'];
    return $new;
}

add_action( 'manage_cirlot_document_posts_custom_column', 'cirlot_docs_admin_column_values', 10, 2 );
function cirlot_docs_admin_column_values( $col, $post_id ) {
    switch ( $col ) {
        case '_document_pub_date':
            echo esc_html( get_post_meta( $post_id, '_document_pub_date', true ) );
            break;
        case '_document_file_format':
            $f = get_post_meta( $post_id, '_document_file_format', true );
            echo $f ? '<span style="text-transform:uppercase;">' . esc_html( $f ) . '</span>' : '—';
            break;
        case 'document_audience':
            $terms = get_the_terms( $post_id, 'document_audience' );
            echo $terms && ! is_wp_error( $terms ) ? esc_html( implode( ', ', wp_list_pluck( $terms, 'name' ) ) ) : '—';
            break;
        case 'document_type':
            $terms = get_the_terms( $post_id, 'document_type' );
            echo $terms && ! is_wp_error( $terms ) ? esc_html( implode( ', ', wp_list_pluck( $terms, 'name' ) ) ) : '—';
            break;
    }
}

// ──────────────────────────────────────────────
// 6. Flush rewrite rules on activation
// ──────────────────────────────────────────────
register_activation_hook( __FILE__, function() {
    cirlot_docs_register_post_type();
    cirlot_docs_register_taxonomies();

    // Seed predefined terms
    foreach ( CIRLOT_DOCS_AUDIENCES as $term ) {
        if ( ! term_exists( $term, 'document_audience' ) ) {
            wp_insert_term( $term, 'document_audience' );
        }
    }
    foreach ( CIRLOT_DOCS_TYPES as $term ) {
        if ( ! term_exists( $term, 'document_type' ) ) {
            wp_insert_term( $term, 'document_type' );
        }
    }

    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
