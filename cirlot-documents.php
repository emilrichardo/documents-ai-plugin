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

function cirlot_docs_get_audiences() {
    $saved = get_option( 'cirlot_docs_audiences_list', '' );
    if ( $saved !== '' ) {
        return array_values( array_filter( array_map( 'trim', explode( "\n", $saved ) ) ) );
    }
    return CIRLOT_DOCS_AUDIENCES;
}

function cirlot_docs_get_types() {
    $saved = get_option( 'cirlot_docs_types_list', '' );
    if ( $saved !== '' ) {
        return array_values( array_filter( array_map( 'trim', explode( "\n", $saved ) ) ) );
    }
    return CIRLOT_DOCS_TYPES;
}

function cirlot_docs_get_global_fields() {
    $saved = get_option( 'cirlot_docs_global_fields', '' );
    if ( $saved !== '' ) {
        $decoded = json_decode( $saved, true );
        if ( is_array( $decoded ) ) return $decoded;
    }
    return [ [ 'id' => 'description', 'label' => 'Document Description', 'type' => 'textarea' ] ];
}

// ──────────────────────────────────────────────
// 0. Enqueue media uploader scripts
// ──────────────────────────────────────────────
add_action( 'admin_enqueue_scripts', 'cirlot_docs_enqueue_scripts' );
function cirlot_docs_enqueue_scripts( $hook ) {
    if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;
    if ( get_post_type() !== 'cirlot_document' && get_current_screen()->post_type !== 'cirlot_document' ) return;
    wp_enqueue_media();
    wp_enqueue_script( 'pdfjs', 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js', [], '3.11.174', true );
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

    $global_fields = cirlot_docs_get_global_fields();
    $global_field_values = [];
    foreach ( $global_fields as $gf ) {
        $fid = $gf['id'];
        if ( $fid === 'description' ) {
            $global_field_values[$fid] = get_post_meta( $post->ID, '_document_description', true );
        } else {
            $global_field_values[$fid] = get_post_meta( $post->ID, '_document_cf_' . $fid, true );
        }
    }

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
        .cd-page-badge { cursor:pointer; background:#f0f6ff !important; border-color:#2271b1 !important; color:#2271b1 !important; font-size:11px !important; }
        .cd-page-badge:hover { background:#2271b1 !important; color:#fff !important; }
        .cd-ai-label { font-weight:normal; font-size:12px; display:inline-flex; align-items:center; gap:5px; color:#555; cursor:pointer; }
        .cd-custom-field { margin-bottom:10px; padding:10px 12px; border:1px solid #e5e5e5; border-radius:4px; background:#fafafa; }
        .cd-custom-field .cd-field-value { width:100%; box-sizing:border-box; margin-top:4px; }
        #cd-ai-process-wrap { margin-top:20px; padding:15px 18px; background:linear-gradient(135deg,#f0f6ff 0%,#e8f3ff 100%); border:1.5px solid #b8d4f5; border-radius:8px; }
        #cd-ai-process-btn { font-size:13px !important; padding:6px 20px !important; height:auto !important; }
        #cd-add-field-form { margin-top:8px; padding:10px 12px; border:1px solid #e0e0e0; border-radius:4px; background:#f9f9f9; }
        .cd-ai-field-option { display:flex; align-items:center; gap:6px; padding:3px 0; font-size:13px; cursor:pointer; font-weight:normal; margin:0; }
        #cd-ai-fields-list { margin-top:4px; padding-left:20px; }
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
            <div class="cd-file-card" id="cd-file-preview" style="margin-bottom:0;<?php echo $file_id ? '' : 'display:none;'; ?>">
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

            <div id="cd-page-badges-wrap" style="<?php echo ( $file_id && $file_format === 'pdf' ) ? '' : 'display:none;'; ?>padding:8px 0 4px;">
                <div id="cd-page-badges" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
                <span id="cd-extract-status" style="display:block;font-size:11px;color:#999;margin-top:5px;min-height:14px;"></span>
            </div>

            <input type="hidden" name="document_file_id" id="cd-file-id" value="<?php echo esc_attr( $file_id ); ?>">
            <input type="hidden" id="cd-file-url" value="<?php echo esc_attr( $file_url ); ?>">
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
                        <?php foreach ( cirlot_docs_get_audiences() as $opt ) : ?>
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
                        <?php foreach ( cirlot_docs_get_types() as $opt ) : ?>
                        <li data-value="<?php echo esc_attr( $opt ); ?>"><?php echo esc_html( $opt ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <input type="hidden" name="document_type_terms" id="cd-type-value" value="<?php echo esc_attr( $type_val ); ?>">
            </div>
        </div>

        <!-- Global Custom Fields -->
        <div id="cd-global-fields-wrap">
            <?php foreach ( $global_fields as $gf ) :
                $gf_id   = esc_attr( $gf['id'] );
                $gf_name = 'document_cf[' . esc_attr( $gf['id'] ) . ']';
                $gf_val  = $global_field_values[ $gf['id'] ] ?? '';
            ?>
            <div style="margin-bottom:16px;">
                <label for="cd-gf-<?php echo $gf_id; ?>"><?php echo esc_html( $gf['label'] ); ?></label>
                <?php if ( ( $gf['type'] ?? 'text' ) === 'text' ) : ?>
                <input type="text" id="cd-gf-<?php echo $gf_id; ?>" name="<?php echo $gf_name; ?>"
                       value="<?php echo esc_attr( $gf_val ); ?>" class="large-text">
                <?php else : ?>
                <textarea id="cd-gf-<?php echo $gf_id; ?>" name="<?php echo $gf_name; ?>"
                          rows="<?php echo ( $gf['type'] ?? '' ) === 'list' ? 4 : 3; ?>"
                ><?php echo esc_textarea( $gf_val ); ?></textarea>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Process with AI -->
        <div id="cd-ai-process-wrap">
            <div style="margin-bottom:12px;">
                <strong style="font-size:13px;display:block;margin-bottom:6px;"><?php esc_html_e( 'Fields to complete:' ); ?></strong>
                <label style="display:flex;align-items:center;gap:6px;padding:3px 0;font-weight:600;font-size:13px;cursor:pointer;margin:0;">
                    <input type="checkbox" id="cd-ai-select-all">
                    <?php esc_html_e( 'Select All' ); ?>
                </label>
                <div id="cd-ai-fields-list">
                    <label class="cd-ai-field-option">
                        <input type="checkbox" class="cd-ai-field-check" data-field-id="title">
                        <?php esc_html_e( 'Title' ); ?>
                    </label>
                    <?php foreach ( $global_fields as $gf ) : ?>
                    <label class="cd-ai-field-option">
                        <input type="checkbox" class="cd-ai-field-check" data-field-id="<?php echo esc_attr( $gf['id'] ); ?>" checked>
                        <?php echo esc_html( $gf['label'] ); ?>
                    </label>
                    <?php endforeach; ?>
                    <label class="cd-ai-field-option">
                        <input type="checkbox" class="cd-ai-field-check" data-field-id="audience">
                        <?php esc_html_e( 'Audience' ); ?>
                    </label>
                    <label class="cd-ai-field-option">
                        <input type="checkbox" class="cd-ai-field-check" data-field-id="document_type">
                        <?php esc_html_e( 'Document Type' ); ?>
                    </label>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <button type="button" id="cd-ai-process-btn" class="button button-primary">
                    &#9889; <?php esc_html_e( 'Process with AI' ); ?>
                </button>
                <span id="cd-ai-status" style="font-size:12px;color:#555;"></span>
            </div>
        </div>

    </div><!-- .cirlot-docs-wrap -->

    <!-- Page Text Modal -->
    <div id="cd-page-modal" style="display:none;position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.65);">
        <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:720px;max-width:90vw;max-height:85vh;background:#fff;border-radius:6px;display:flex;flex-direction:column;box-shadow:0 8px 40px rgba(0,0,0,.3);">
            <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-bottom:1px solid #e0e0e0;">
                <h3 id="cd-modal-title" style="margin:0;font-size:15px;"></h3>
                <button type="button" id="cd-modal-close" style="background:none;border:none;cursor:pointer;font-size:24px;color:#666;line-height:1;padding:0;">&times;</button>
            </div>
            <div style="flex:1;overflow-y:auto;padding:20px;">
                <pre id="cd-modal-content" style="white-space:pre-wrap;font-family:ui-monospace,'Cascadia Code',Menlo,monospace;margin:0;font-size:12.5px;line-height:1.7;color:#1d2327;"></pre>
            </div>
            <div style="padding:10px 20px;border-top:1px solid #e0e0e0;display:flex;justify-content:flex-end;">
                <button type="button" id="cd-modal-copy" class="button"><?php esc_html_e( 'Copy Text' ); ?></button>
            </div>
        </div>
    </div>

    <script>
    (function($) {
        var cdAjaxUrl         = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
        var cdAjaxNonce       = <?php echo wp_json_encode( wp_create_nonce( 'cirlot_docs_ai' ) ); ?>;
        var cdGlobalFields    = <?php echo wp_json_encode( $global_fields ); ?>;
        var cdAudienceOptions = <?php echo wp_json_encode( cirlot_docs_get_audiences() ); ?>;
        var cdTypeOptions     = <?php echo wp_json_encode( cirlot_docs_get_types() ); ?>;

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
        function cdTitleFromFilename(filename) {
            var name = filename.replace(/\.[^/.]+$/, '');
            name = name.replace(/[-_]+/g, ' ').trim();
            return name.replace(/\b\w/g, function(c) { return c.toUpperCase(); });
        }

        $(document).on('click', '#cd-upload-btn', function(e) {
            e.preventDefault();
            if (mediaFrame) { mediaFrame.open(); return; }
            mediaFrame = wp.media({ title: 'Select Document', button: { text: 'Use this file' }, multiple: false });
            mediaFrame.on('select', function() {
                var a   = mediaFrame.state().get('selection').first().toJSON();
                var ext = extOf(a.filename);
                $('#cd-file-id').val(a.id);
                $('#cd-file-url').val(a.url);
                $('#cd-file-icon-badge')
                    .attr('class', 'cd-file-icon ' + iconClass(ext))
                    .text(iconLabel(ext));
                $('#cd-file-meta').html(
                    '<strong>' + $('<span>').text(a.title || a.filename).html() + '</strong>' +
                    '<span><a href="' + a.url + '" target="_blank">' + $('<span>').text(a.filename).html() + '</a></span>' +
                    (a.filesizeHumanReadable ? '<span>' + a.filesizeHumanReadable + '</span>' : '')
                );
                var autoTitle = cdTitleFromFilename(a.filename);
                if (autoTitle) { $('#title').val(autoTitle).trigger('keyup').trigger('focus').trigger('blur'); }
                if (ext === 'pdf') {
                    $('input[name="document_file_format"][value="pdf"]').prop('checked', true);
                }
                $('#cd-file-preview').show();
                // swap standalone upload button for the card's Replace button
                $('#cd-upload-btn').not('#cd-file-preview #cd-upload-btn').hide();
                if (ext === 'pdf') cdExtractPdf(a.url);
                else $('#cd-page-badges-wrap').hide();
            });
            mediaFrame.open();
        });

        $(document).on('click', '#cd-remove-file', function() {
            $('#cd-file-id').val('');
            $('#cd-file-url').val('');
            $('#cd-file-preview').hide();
            $('#cd-file-meta').html('');
            $('#cd-page-badges-wrap').hide();
            $('#cd-page-badges').empty();
            $('#cd-extract-status').text('');
            cdPageTexts = {};
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

            function clearTags() {
                $box.find('.cd-tag').remove();
                updateHidden();
            }

            return { addTag: addTag, clearTags: clearTags };
        }

        var cdAudienceSelect = initDropdownSelect('cd-audience-box', 'cd-audience-dropdown', 'cd-audience-value');
        var cdTypeSelect     = initDropdownSelect('cd-type-box',     'cd-type-dropdown',     'cd-type-value');

        // ── PDF Text Extraction ───────────────────────
        var cdPageTexts = {};

        async function cdExtractPdf(pdfUrl) {
            pdfUrl = pdfUrl || $('#cd-file-url').val();
            if (!pdfUrl) return;

            var $wrap   = $('#cd-page-badges-wrap');
            var $status = $('#cd-extract-status');
            var $badges = $('#cd-page-badges');

            $wrap.show();
            $badges.empty();
            $status.text('Loading…');
            cdPageTexts = {};

            try {
                if (typeof pdfjsLib === 'undefined') {
                    $status.text('PDF.js not loaded — please refresh.');
                    return;
                }
                pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
                var pdf      = await pdfjsLib.getDocument(pdfUrl).promise;
                var numPages = pdf.numPages;

                for (var i = 1; i <= numPages; i++) {
                    $status.text(i + ' / ' + numPages);
                    var page    = await pdf.getPage(i);
                    var vp      = page.getViewport({ scale: 1 });
                    var content = await page.getTextContent();

                    var pageH = vp.height;
                    var top   = pageH * 0.93;
                    var bot   = pageH * 0.07;

                    var lineMap = {};
                    content.items.forEach(function(item) {
                        var y = item.transform[5];
                        if (y < bot || y > top) return;
                        var bucket = Math.round(y / 3) * 3;
                        if (!lineMap[bucket]) lineMap[bucket] = [];
                        lineMap[bucket].push(item);
                    });

                    var sortedY = Object.keys(lineMap).map(Number).sort(function(a, b) { return b - a; });
                    var lines   = sortedY.map(function(y) {
                        return lineMap[y].map(function(it) { return it.str; }).join('');
                    }).filter(function(l) { return l.trim() !== ''; });

                    cdPageTexts[i] = lines.join('\n');

                    (function(pageNum) {
                        var $badge = $('<button type="button" class="button button-small cd-page-badge"></button>').text('Page ' + pageNum);
                        $badge.on('click', function() { cdOpenPageModal(pageNum); });
                        $badges.append($badge);
                    })(i);
                }

                $status.text(numPages + ' page' + (numPages !== 1 ? 's' : ''));
            } catch (err) {
                $status.text('Error: ' + err.message);
            }
        }

        // Auto-extract on page load if a PDF is already set
        <?php
        $file_ext_loaded = $file_id ? strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) ) : '';
        if ( $file_id && $file_ext_loaded === 'pdf' && $file_url ) :
        ?>
        $(function() {
            $('input[name="document_file_format"][value="pdf"]').prop('checked', true);
            $('#cd-page-badges-wrap').show();
            cdExtractPdf(<?php echo json_encode( $file_url ); ?>);
        });
        <?php endif; ?>

        // ── AI fields select-all ──────────────────────
        function cdUpdateSelectAll() {
            var $checks = $('.cd-ai-field-check');
            var checked = $checks.filter(':checked').length;
            var $all = $('#cd-ai-select-all');
            $all.prop('checked', checked === $checks.length);
            $all.prop('indeterminate', checked > 0 && checked < $checks.length);
        }
        $('#cd-ai-select-all').on('change', function() {
            $('.cd-ai-field-check').prop('checked', $(this).prop('checked'));
        });
        $(document).on('change', '.cd-ai-field-check', cdUpdateSelectAll);
        cdUpdateSelectAll();

        // ── Process with AI ───────────────────────────
        $('#cd-ai-process-btn').on('click', function() {
            var rawText = Object.keys(cdPageTexts).sort(function(a, b) { return a - b; }).map(function(p) {
                return '--- Page ' + p + ' ---\n' + cdPageTexts[p];
            }).join('\n\n');

            if (!rawText) {
                $('#cd-ai-status').text('<?php esc_html_e( 'No PDF text — load a PDF and wait for extraction.' ); ?>');
                return;
            }

            var staticDefs = {
                title:         { id: 'title',         label: '<?php echo esc_js( __( 'Document Title' ) ); ?>',  type: 'text' },
                audience:      { id: 'audience',      label: '<?php echo esc_js( __( 'Audience' ) ); ?>',        type: 'multiselect', options: cdAudienceOptions },
                document_type: { id: 'document_type', label: '<?php echo esc_js( __( 'Document Type' ) ); ?>',   type: 'multiselect', options: cdTypeOptions }
            };

            var fieldsToFill = [];
            $('.cd-ai-field-check:checked').each(function() {
                var fid = $(this).data('field-id');
                if (staticDefs[fid]) {
                    fieldsToFill.push(staticDefs[fid]);
                } else {
                    var gf = cdGlobalFields.find(function(f) { return f.id === fid; });
                    if (gf) fieldsToFill.push({ id: gf.id, label: gf.label, type: gf.type });
                }
            });

            if (!fieldsToFill.length) {
                $('#cd-ai-status').text('<?php esc_html_e( 'Select at least one field.' ); ?>');
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true).text('<?php esc_html_e( 'Processing…' ); ?>');
            $('#cd-ai-status').text('');

            $.post(cdAjaxUrl, {
                action:   'cirlot_docs_ai_process',
                nonce:    cdAjaxNonce,
                raw_text: rawText,
                fields:   JSON.stringify(fieldsToFill)
            })
            .done(function(res) {
                if (!res.success) {
                    $('#cd-ai-status').text('Error: ' + res.data);
                    return;
                }
                var data = res.data;
                if (data.title !== undefined) {
                    $('#title').val(data.title).trigger('keyup').trigger('focus').trigger('blur');
                }
                if (data.audience !== undefined) {
                    var audiences = Array.isArray(data.audience) ? data.audience : String(data.audience).split(',');
                    cdAudienceSelect.clearTags();
                    audiences.forEach(function(a) { var t = a.trim(); if (t) cdAudienceSelect.addTag(t); });
                }
                if (data.document_type !== undefined) {
                    var types = Array.isArray(data.document_type) ? data.document_type : String(data.document_type).split(',');
                    cdTypeSelect.clearTags();
                    types.forEach(function(t) { var s = t.trim(); if (s) cdTypeSelect.addTag(s); });
                }
                cdGlobalFields.forEach(function(f) {
                    if (data[f.id] !== undefined) {
                        $('[name="document_cf[' + f.id + ']"]').val(data[f.id]);
                    }
                });
                $('#cd-ai-status').text('<?php esc_html_e( 'Done.' ); ?>');
                setTimeout(function() { $('#cd-ai-status').text(''); }, 3000);
            })
            .fail(function(xhr) {
                var msg = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : xhr.statusText;
                $('#cd-ai-status').text('Error: ' + msg);
            })
            .always(function() {
                $btn.prop('disabled', false).html('&#9889; <?php esc_html_e( 'Process with AI' ); ?>');
            });
        });

        function cdOpenPageModal(pageNum) {
            $('#cd-modal-title').text('Page ' + pageNum);
            $('#cd-modal-content').text(cdPageTexts[pageNum] || '');
            $('#cd-modal-copy').text('<?php esc_html_e( 'Copy Text' ); ?>');
            $('#cd-page-modal').show();
        }

        $('#cd-modal-close').on('click', function() { $('#cd-page-modal').hide(); });
        $('#cd-page-modal').on('click', function(e) {
            if (e.target === this) $(this).hide();
        });
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') $('#cd-page-modal').hide();
        });
        $('#cd-modal-copy').on('click', function() {
            var text = $('#cd-modal-content').text();
            var $btn = $(this);
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function() {
                    $btn.text('Copied!');
                    setTimeout(function() { $btn.text('<?php esc_html_e( 'Copy Text' ); ?>'); }, 1500);
                });
            }
        });

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

    // Global custom fields
    if ( isset( $_POST['document_cf'] ) && is_array( $_POST['document_cf'] ) ) {
        foreach ( cirlot_docs_get_global_fields() as $gf ) {
            $fid = preg_replace( '/[^a-z0-9_]/', '', $gf['id'] ?? '' );
            if ( ! $fid ) continue;
            $val = sanitize_textarea_field( $_POST['document_cf'][ $fid ] ?? '' );
            if ( $fid === 'description' ) {
                update_post_meta( $post_id, '_document_description', $val );
            } else {
                update_post_meta( $post_id, '_document_cf_' . $fid, $val );
            }
        }
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
// 5. AI Processing — Gemini AJAX handler
// ──────────────────────────────────────────────
add_action( 'wp_ajax_cirlot_docs_ai_process', 'cirlot_docs_ai_process' );
function cirlot_docs_ai_process() {
    check_ajax_referer( 'cirlot_docs_ai', 'nonce' );
    if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'Unauthorized.' );

    $raw_text = isset( $_POST['raw_text'] ) ? wp_strip_all_tags( stripslashes( $_POST['raw_text'] ) ) : '';
    $fields   = json_decode( stripslashes( $_POST['fields'] ?? '[]' ), true );

    if ( ! $raw_text )     wp_send_json_error( __( 'No text provided. Extract PDF pages first.' ) );
    if ( empty( $fields ) ) wp_send_json_error( __( 'No fields selected for AI completion.' ) );

    $api_key = get_option( 'cirlot_docs_gemini_api_key', '' );
    $model   = get_option( 'cirlot_docs_gemini_model', 'gemini-2.5-flash' );

    if ( ! $api_key ) wp_send_json_error( __( 'Gemini API key not configured in Settings.' ) );

    $available_audiences = implode( ', ', cirlot_docs_get_audiences() );
    $available_types     = implode( ', ', cirlot_docs_get_types() );

    $fields_desc = '';
    foreach ( $fields as $f ) {
        $id    = $f['id']    ?? '';
        $label = $f['label'] ?? '';
        $type  = $f['type']  ?? 'text';

        if ( $id === 'file_format' ) {
            $fields_desc .= '- id: "file_format", label: "' . $label . '", type: text (respond with EXACTLY one of: pdf, word, excel)' . "\n";
        } elseif ( $id === 'audience' ) {
            $fields_desc .= '- id: "audience", label: "' . $label . '", type: array (JSON array of strings, choose relevant items from: ' . $available_audiences . ')' . "\n";
        } elseif ( $id === 'document_type' ) {
            $fields_desc .= '- id: "document_type", label: "' . $label . '", type: array (JSON array of strings, choose relevant items from: ' . $available_types . ')' . "\n";
        } else {
            $type_hint    = $type === 'list' ? 'list (one item per line)' : $type;
            $fields_desc .= '- id: "' . $id . '", label: "' . $label . '", type: ' . $type_hint . "\n";
        }
    }

    $prompt  = "You are a professional document analyst. Read the document text and fill in each field.\n\n";
    $prompt .= "DOCUMENT TEXT:\n" . mb_substr( $raw_text, 0, 30000 ) . "\n\n";
    $prompt .= "FIELDS TO COMPLETE:\n" . $fields_desc . "\n";
    $prompt .= "Return ONLY a valid JSON object. Keys are field ids, values are strings or arrays as specified.\n";
    $prompt .= "For 'list' type, separate items with newline characters.\n";
    $prompt .= "For 'array' type, return a JSON array of strings.\n";
    $prompt .= "No explanation, no markdown fences — just the raw JSON object.";

    $response = wp_remote_post(
        'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode( $model ) . ':generateContent?key=' . urlencode( $api_key ),
        [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'contents'         => [ [ 'parts' => [ [ 'text' => $prompt ] ] ] ],
                'generationConfig' => [ 'temperature' => 0.1, 'responseMimeType' => 'application/json' ],
            ] ),
            'timeout' => 60,
        ]
    );

    if ( is_wp_error( $response ) ) wp_send_json_error( $response->get_error_message() );

    $code = (int) wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 ) {
        wp_send_json_error( $body['error']['message'] ?? 'API error ' . $code );
    }

    $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $text = preg_replace( '/^```(?:json)?\s*/m', '', trim( $text ) );
    $text = preg_replace( '/\s*```\s*$/m', '', $text );

    $result = json_decode( trim( $text ), true );
    if ( ! is_array( $result ) ) wp_send_json_error( __( 'Could not parse AI response. Try again.' ) );

    wp_send_json_success( $result );
}

// ──────────────────────────────────────────────
// 6. Admin Menu — Settings submenu
// ──────────────────────────────────────────────
add_action( 'admin_menu', 'cirlot_docs_admin_menu' );
function cirlot_docs_admin_menu() {
    add_submenu_page(
        'edit.php?post_type=cirlot_document',
        __( 'Documents Settings' ),
        __( 'Settings' ),
        'manage_options',
        'cirlot-docs-settings',
        'cirlot_docs_settings_page'
    );
}

function cirlot_docs_settings_page() { // phpcs:ignore
    if ( ! current_user_can( 'manage_options' ) ) return;

    $active_tab = sanitize_key( $_GET['tab'] ?? 'general' );

    if ( isset( $_POST['cirlot_docs_settings_nonce'] ) &&
         wp_verify_nonce( $_POST['cirlot_docs_settings_nonce'], 'cirlot_docs_settings_save' ) ) {

        $tab = sanitize_key( $_POST['cd_active_tab'] ?? 'general' );

        if ( $tab === 'general' ) {
            update_option( 'cirlot_docs_archive_slug',     sanitize_text_field( $_POST['cirlot_docs_archive_slug'] ?? 'documents' ) );
            update_option( 'cirlot_docs_default_audience', sanitize_text_field( $_POST['cirlot_docs_default_audience'] ?? '' ) );
            update_option( 'cirlot_docs_default_type',     sanitize_text_field( $_POST['cirlot_docs_default_type'] ?? '' ) );
            update_option( 'cirlot_docs_allowed_formats',  array_map( 'sanitize_text_field', (array) ( $_POST['cirlot_docs_allowed_formats'] ?? [ 'pdf', 'word', 'excel' ] ) ) );
            flush_rewrite_rules();
        }
        if ( $tab === 'ai' ) {
            update_option( 'cirlot_docs_gemini_model', sanitize_text_field( $_POST['cirlot_docs_gemini_model'] ?? 'gemini-2.5-flash' ) );
            if ( ! empty( $_POST['cirlot_docs_gemini_api_key'] ) ) {
                update_option( 'cirlot_docs_gemini_api_key', sanitize_text_field( $_POST['cirlot_docs_gemini_api_key'] ) );
            }
        }
        if ( $tab === 'taxonomy' ) {
            $raw_audiences = sanitize_textarea_field( $_POST['cirlot_docs_audiences_list'] ?? '' );
            update_option( 'cirlot_docs_audiences_list', $raw_audiences );
            foreach ( array_filter( array_map( 'trim', explode( "\n", $raw_audiences ) ) ) as $term ) {
                if ( ! term_exists( $term, 'document_audience' ) ) wp_insert_term( $term, 'document_audience' );
            }
            $raw_types = sanitize_textarea_field( $_POST['cirlot_docs_types_list'] ?? '' );
            update_option( 'cirlot_docs_types_list', $raw_types );
            foreach ( array_filter( array_map( 'trim', explode( "\n", $raw_types ) ) ) as $term ) {
                if ( ! term_exists( $term, 'document_type' ) ) wp_insert_term( $term, 'document_type' );
            }
        }
        if ( $tab === 'fields' ) {
            $raw_json = stripslashes( $_POST['cirlot_docs_global_fields_json'] ?? '[]' );
            $decoded  = json_decode( $raw_json, true );
            if ( is_array( $decoded ) ) {
                $allowed_types = [ 'text', 'textarea', 'list' ];
                $clean = array_values( array_filter( array_map( function( $f ) use ( $allowed_types ) {
                    $id    = preg_replace( '/[^a-z0-9_]/', '', strtolower( $f['id']    ?? '' ) );
                    $label = sanitize_text_field( $f['label'] ?? '' );
                    $type  = in_array( $f['type'] ?? '', $allowed_types, true ) ? $f['type'] : 'text';
                    return ( $id && $label ) ? compact( 'id', 'label', 'type' ) : null;
                }, $decoded ) ) );
                update_option( 'cirlot_docs_global_fields', wp_json_encode( $clean ) );
            }
        }
        $active_tab = $tab;
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.' ) . '</p></div>';
    }

    /* ---- data ---- */
    $slug             = get_option( 'cirlot_docs_archive_slug', 'documents' );
    $default_audience = get_option( 'cirlot_docs_default_audience', '' );
    $default_type     = get_option( 'cirlot_docs_default_type', '' );
    $allowed_formats  = (array) get_option( 'cirlot_docs_allowed_formats', [ 'pdf', 'word', 'excel' ] );
    $gemini_model     = get_option( 'cirlot_docs_gemini_model', 'gemini-2.5-flash' );
    $gemini_api_key   = get_option( 'cirlot_docs_gemini_api_key', '' );
    $audiences_list   = get_option( 'cirlot_docs_audiences_list', implode( "\n", CIRLOT_DOCS_AUDIENCES ) );
    $types_list       = get_option( 'cirlot_docs_types_list',     implode( "\n", CIRLOT_DOCS_TYPES ) );
    $global_fields    = cirlot_docs_get_global_fields();
    $audience_terms   = get_terms( [ 'taxonomy' => 'document_audience', 'hide_empty' => false ] );
    $type_terms       = get_terms( [ 'taxonomy' => 'document_type',     'hide_empty' => false ] );
    $tabs = [ 'general' => 'General', 'ai' => 'AI', 'taxonomy' => 'Taxonomy', 'fields' => 'Custom Fields', 'shortcodes' => 'Shortcodes' ];

    $types_arr     = array_filter( array_map( 'trim', explode( "\n", $types_list ) ) );
    $audiences_arr = array_filter( array_map( 'trim', explode( "\n", $audiences_list ) ) );
    $first_type    = reset( $types_arr )     ?: 'Policies';
    $first_aud     = reset( $audiences_arr ) ?: 'Institution';
    ?>
    <div class="wrap">
    <h1><?php esc_html_e( 'Documents Settings' ); ?></h1>
    <style>
    .cd-settings-tabs{display:flex;border-bottom:1px solid #c3c4c7;margin-bottom:20px;}
    .cd-settings-tab{padding:10px 18px;font-size:13px;font-weight:500;color:#50575e;text-decoration:none;border:1px solid transparent;border-bottom:none;margin-bottom:-1px;border-radius:3px 3px 0 0;}
    .cd-settings-tab:hover{color:#2271b1;}.cd-settings-tab.active{background:#fff;border-color:#c3c4c7;color:#1d2327;font-weight:600;}
    .cd-tab-pane{display:none;}.cd-tab-pane.active{display:block;}
    .cd-gf-list{border:1px solid #c3c4c7;border-radius:4px;overflow:hidden;margin-bottom:12px;background:#fff;}
    .cd-gf-item{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid #e0e0e0;}
    .cd-gf-item:last-child{border-bottom:none;}.cd-gf-item:hover{background:#f9f9f9;}
    .cd-gf-drag{color:#bbb;cursor:grab;font-size:16px;flex-shrink:0;}
    .cd-gf-label{flex:1;font-size:13px;font-weight:600;color:#1d2327;}
    .cd-gf-type{font-size:11px;color:#646970;background:#f0f0f1;padding:2px 8px;border-radius:3px;}
    .cd-gf-remove{background:none;border:none;cursor:pointer;color:#b32d2e;font-size:20px;line-height:1;padding:0;flex-shrink:0;}
    .cd-gf-remove:hover{color:#8a2020;}
    .cd-gf-add-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:4px;}
    .cd-gf-add-row input,.cd-gf-add-row select{height:32px;font-size:13px;}
    .cd-sc-box{background:#f6f7f7;border:1px solid #c3c4c7;border-radius:4px;padding:14px 18px;margin-bottom:14px;}
    .cd-sc-box h3{margin:0 0 8px;font-size:13px;color:#1d2327;}
    .cd-sc-code{display:flex;align-items:center;gap:8px;margin-bottom:6px;}
    .cd-sc-code code{background:#fff;border:1px solid #c3c4c7;border-radius:3px;padding:5px 10px;font-size:13px;font-family:ui-monospace,Consolas,monospace;flex:1;color:#1d2327;}
    .cd-sc-copy{background:#2271b1;color:#fff;border:none;border-radius:3px;padding:5px 12px;font-size:12px;cursor:pointer;white-space:nowrap;}
    .cd-sc-copy:hover{background:#135e96;}.cd-sc-copy.copied{background:#46b450;}
    .cd-sc-desc{font-size:12px;color:#646970;margin:4px 0 0;}
    .cd-sc-params{width:100%;border-collapse:collapse;margin-top:14px;font-size:12px;}
    .cd-sc-params th{text-align:left;padding:6px 10px;background:#f0f0f1;border:1px solid #dcdcde;}
    .cd-sc-params td{padding:6px 10px;border:1px solid #dcdcde;color:#50575e;vertical-align:top;}
    .cd-sc-params td code{background:#f0f0f1;padding:1px 5px;border-radius:2px;font-size:11px;}
    </style>

    <div class="cd-settings-tabs">
        <?php foreach ( $tabs as $key => $label ) : ?>
        <a class="cd-settings-tab<?php echo $active_tab === $key ? ' active' : ''; ?>"
           href="<?php echo esc_url( add_query_arg( [ 'tab' => $key ], remove_query_arg( 'tab' ) ) ); ?>">
           <?php echo esc_html( $label ); ?>
        </a>
        <?php endforeach; ?>
    </div>

    <form method="post">
    <?php wp_nonce_field( 'cirlot_docs_settings_save', 'cirlot_docs_settings_nonce' ); ?>
    <input type="hidden" name="cd_active_tab" value="<?php echo esc_attr( $active_tab ); ?>">

    <!-- General -->
    <div class="cd-tab-pane<?php echo $active_tab === 'general' ? ' active' : ''; ?>">
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="cd-archive-slug"><?php esc_html_e( 'Archive Slug' ); ?></label></th>
                <td>
                    <input type="text" id="cd-archive-slug" name="cirlot_docs_archive_slug" value="<?php echo esc_attr( $slug ); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e( 'Default: documents' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Allowed File Formats' ); ?></th>
                <td><?php foreach ( [ 'pdf' => 'PDF', 'word' => 'Word', 'excel' => 'Excel' ] as $fv => $fl ) : ?>
                    <label style="display:inline-flex;align-items:center;gap:5px;margin-right:14px;">
                        <input type="checkbox" name="cirlot_docs_allowed_formats[]" value="<?php echo esc_attr( $fv ); ?>" <?php checked( in_array( $fv, $allowed_formats, true ) ); ?>>
                        <?php echo esc_html( $fl ); ?>
                    </label>
                <?php endforeach; ?></td>
            </tr>
            <tr>
                <th><label for="cd-def-aud"><?php esc_html_e( 'Default Audience' ); ?></label></th>
                <td>
                    <input type="text" id="cd-def-aud" name="cirlot_docs_default_audience" value="<?php echo esc_attr( $default_audience ); ?>" class="regular-text" list="cd-aud-dl">
                    <datalist id="cd-aud-dl"><?php foreach ( (array) $audience_terms as $t ) echo '<option value="' . esc_attr( $t->name ) . '">'; ?></datalist>
                </td>
            </tr>
            <tr>
                <th><label for="cd-def-type"><?php esc_html_e( 'Default Document Type' ); ?></label></th>
                <td>
                    <input type="text" id="cd-def-type" name="cirlot_docs_default_type" value="<?php echo esc_attr( $default_type ); ?>" class="regular-text" list="cd-type-dl">
                    <datalist id="cd-type-dl"><?php foreach ( (array) $type_terms as $t ) echo '<option value="' . esc_attr( $t->name ) . '">'; ?></datalist>
                </td>
            </tr>
        </table>
        <?php submit_button( __( 'Save General Settings' ) ); ?>
    </div>

    <!-- AI -->
    <div class="cd-tab-pane<?php echo $active_tab === 'ai' ? ' active' : ''; ?>">
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="cd-gemini-model"><?php esc_html_e( 'Gemini Model' ); ?></label></th>
                <td>
                    <select id="cd-gemini-model" name="cirlot_docs_gemini_model">
                        <?php foreach ( [ 'gemini-2.5-flash' => 'Gemini 2.5 Flash', 'gemini-2.5-pro' => 'Gemini 2.5 Pro', 'gemini-1.5-flash' => 'Gemini 1.5 Flash', 'gemini-1.5-pro' => 'Gemini 1.5 Pro' ] as $mid => $mname ) : ?>
                        <option value="<?php echo esc_attr( $mid ); ?>" <?php selected( $gemini_model, $mid ); ?>><?php echo esc_html( $mname ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="cd-gemini-key"><?php esc_html_e( 'Gemini API Key' ); ?></label></th>
                <td>
                    <input type="password" id="cd-gemini-key" name="cirlot_docs_gemini_api_key" value="<?php echo esc_attr( $gemini_api_key ); ?>" class="regular-text" autocomplete="new-password">
                    <p class="description"><?php esc_html_e( 'Leave blank to keep the current key.' ); ?></p>
                </td>
            </tr>
        </table>
        <?php submit_button( __( 'Save AI Settings' ) ); ?>
    </div>

    <!-- Taxonomy -->
    <div class="cd-tab-pane<?php echo $active_tab === 'taxonomy' ? ' active' : ''; ?>">
        <p class="description" style="margin-bottom:16px;"><?php esc_html_e( 'One item per line. New items are registered as taxonomy terms automatically.' ); ?></p>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="cd-audiences-list"><?php esc_html_e( 'Audiences' ); ?></label></th>
                <td><textarea id="cd-audiences-list" name="cirlot_docs_audiences_list" rows="6" class="large-text"><?php echo esc_textarea( $audiences_list ); ?></textarea></td>
            </tr>
            <tr>
                <th><label for="cd-types-list"><?php esc_html_e( 'Document Types' ); ?></label></th>
                <td><textarea id="cd-types-list" name="cirlot_docs_types_list" rows="10" class="large-text"><?php echo esc_textarea( $types_list ); ?></textarea></td>
            </tr>
        </table>
        <?php submit_button( __( 'Save Taxonomy Settings' ) ); ?>
    </div>

    <!-- Custom Fields -->
    <div class="cd-tab-pane<?php echo $active_tab === 'fields' ? ' active' : ''; ?>">
        <p class="description" style="margin-bottom:16px;"><?php esc_html_e( 'Define the custom fields that appear on every document and in frontend results.' ); ?></p>
        <input type="hidden" name="cirlot_docs_global_fields_json" id="cd-gf-json" value="<?php echo esc_attr( wp_json_encode( $global_fields ) ); ?>">
        <div class="cd-gf-list" id="cd-gf-list">
            <?php foreach ( $global_fields as $gf ) : ?>
            <div class="cd-gf-item" data-id="<?php echo esc_attr( $gf['id'] ); ?>">
                <span class="cd-gf-drag">⠿</span>
                <span class="cd-gf-label"><?php echo esc_html( $gf['label'] ); ?></span>
                <span class="cd-gf-type"><?php echo esc_html( $gf['type'] ); ?></span>
                <?php if ( $gf['id'] !== 'description' ) : ?>
                <button type="button" class="cd-gf-remove" title="<?php esc_attr_e( 'Remove' ); ?>">&times;</button>
                <?php else : ?>
                <span style="width:22px;display:inline-block;"></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="cd-gf-add-row">
            <input type="text" id="cd-gf-new-label" placeholder="<?php esc_attr_e( 'Field name…' ); ?>" style="flex:1;min-width:160px;">
            <select id="cd-gf-new-type">
                <option value="text"><?php esc_html_e( 'Text' ); ?></option>
                <option value="textarea" selected><?php esc_html_e( 'Textarea' ); ?></option>
                <option value="list"><?php esc_html_e( 'List' ); ?></option>
            </select>
            <button type="button" id="cd-gf-add-btn" class="button button-primary"><?php esc_html_e( '+ Add Field' ); ?></button>
        </div>
        <?php submit_button( __( 'Save Custom Fields' ) ); ?>
    </div>

    </form>

    <!-- Shortcodes (no form) -->
    <div class="cd-tab-pane<?php echo $active_tab === 'shortcodes' ? ' active' : ''; ?>">
        <div class="cd-sc-box">
            <h3><?php esc_html_e( 'Basic — all documents with search' ); ?></h3>
            <div class="cd-sc-code"><code id="cd-sc-1">[cirlot_document_search]</code><button class="cd-sc-copy" data-target="cd-sc-1"><?php esc_html_e( 'Copy' ); ?></button></div>
        </div>
        <div class="cd-sc-box">
            <h3><?php esc_html_e( 'Pre-filtered by Document Type' ); ?></h3>
            <div class="cd-sc-code"><code id="cd-sc-2">[cirlot_document_search type="<?php echo esc_html( $first_type ); ?>"]</code><button class="cd-sc-copy" data-target="cd-sc-2"><?php esc_html_e( 'Copy' ); ?></button></div>
            <p class="cd-sc-desc"><?php esc_html_e( 'Available:' ); ?> <?php foreach ( $types_arr as $t ) echo '<code>' . esc_html( $t ) . '</code> '; ?></p>
        </div>
        <div class="cd-sc-box">
            <h3><?php esc_html_e( 'Pre-filtered by Audience' ); ?></h3>
            <div class="cd-sc-code"><code id="cd-sc-3">[cirlot_document_search audience="<?php echo esc_html( $first_aud ); ?>"]</code><button class="cd-sc-copy" data-target="cd-sc-3"><?php esc_html_e( 'Copy' ); ?></button></div>
            <p class="cd-sc-desc"><?php esc_html_e( 'Available:' ); ?> <?php foreach ( $audiences_arr as $a ) echo '<code>' . esc_html( $a ) . '</code> '; ?></p>
        </div>
        <div class="cd-sc-box">
            <h3><?php esc_html_e( 'Combined + custom per_page' ); ?></h3>
            <div class="cd-sc-code"><code id="cd-sc-4">[cirlot_document_search type="<?php echo esc_html( $first_type ); ?>" audience="<?php echo esc_html( $first_aud ); ?>" per_page="5"]</code><button class="cd-sc-copy" data-target="cd-sc-4"><?php esc_html_e( 'Copy' ); ?></button></div>
        </div>
        <div class="cd-sc-box">
            <h3><?php esc_html_e( 'Without AI assistant' ); ?></h3>
            <div class="cd-sc-code"><code id="cd-sc-5">[cirlot_document_search show_ai="false"]</code><button class="cd-sc-copy" data-target="cd-sc-5"><?php esc_html_e( 'Copy' ); ?></button></div>
        </div>
        <table class="cd-sc-params" style="margin-top:18px;">
            <thead><tr><th><?php esc_html_e( 'Parameter' ); ?></th><th><?php esc_html_e( 'Default' ); ?></th><th><?php esc_html_e( 'Description' ); ?></th></tr></thead>
            <tbody>
                <tr><td><code>type</code></td><td><?php esc_html_e( '(empty)' ); ?></td><td><?php esc_html_e( 'Pre-select a document type. Also reads ?type= from URL.' ); ?></td></tr>
                <tr><td><code>audience</code></td><td><?php esc_html_e( '(empty)' ); ?></td><td><?php esc_html_e( 'Pre-select an audience. Also reads ?audience= from URL.' ); ?></td></tr>
                <tr><td><code>per_page</code></td><td><code>10</code></td><td><?php esc_html_e( 'Results per page (max 50).' ); ?></td></tr>
                <tr><td><code>show_ai</code></td><td><code>true</code></td><td><?php esc_html_e( 'Set "false" to hide the AI assistant.' ); ?></td></tr>
            </tbody>
        </table>
    </div>

    <script>
    (function() {
        // Copy shortcode buttons
        document.querySelectorAll('.cd-sc-copy').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var el = document.getElementById(btn.dataset.target);
                if (!el) return;
                navigator.clipboard.writeText(el.textContent.trim()).then(function() {
                    btn.textContent = '<?php esc_html_e( 'Copied!' ); ?>';
                    btn.classList.add('copied');
                    setTimeout(function() { btn.textContent = '<?php esc_html_e( 'Copy' ); ?>'; btn.classList.remove('copied'); }, 2000);
                });
            });
        });

        // Custom Fields manager
        var gfData = JSON.parse(document.getElementById('cd-gf-json').value || '[]');

        function gfUid(label) {
            return label.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0,30)
                + '_' + Math.random().toString(36).slice(2,6);
        }
        function gfSerialize() {
            var items = [];
            document.querySelectorAll('#cd-gf-list .cd-gf-item').forEach(function(el) {
                var id = el.dataset.id;
                var entry = gfData.find(function(f) { return f.id === id; });
                if (entry) items.push(entry);
            });
            gfData = items;
            document.getElementById('cd-gf-json').value = JSON.stringify(items);
        }
        function gfMakeRow(id, label, type, removable) {
            var item = document.createElement('div');
            item.className = 'cd-gf-item';
            item.dataset.id = id;
            var drag = document.createElement('span'); drag.className = 'cd-gf-drag'; drag.textContent = '⠿';
            var lbl  = document.createElement('span'); lbl.className  = 'cd-gf-label'; lbl.textContent = label;
            var typ  = document.createElement('span'); typ.className  = 'cd-gf-type';  typ.textContent = type;
            item.append(drag, lbl, typ);
            if (removable) {
                var btn = document.createElement('button');
                btn.type = 'button'; btn.className = 'cd-gf-remove'; btn.title = 'Remove'; btn.textContent = '\u00d7';
                item.appendChild(btn);
            } else {
                var pad = document.createElement('span'); pad.style.cssText = 'width:22px;display:inline-block;';
                item.appendChild(pad);
            }
            return item;
        }
        document.getElementById('cd-gf-add-btn').addEventListener('click', function() {
            var label = document.getElementById('cd-gf-new-label').value.trim();
            var type  = document.getElementById('cd-gf-new-type').value;
            if (!label) { document.getElementById('cd-gf-new-label').focus(); return; }
            var id = gfUid(label);
            gfData.push({ id: id, label: label, type: type });
            document.getElementById('cd-gf-list').appendChild(gfMakeRow(id, label, type, true));
            document.getElementById('cd-gf-json').value = JSON.stringify(gfData);
            document.getElementById('cd-gf-new-label').value = '';
        });
        document.getElementById('cd-gf-list').addEventListener('click', function(e) {
            if (!e.target.classList.contains('cd-gf-remove')) return;
            var item = e.target.closest('.cd-gf-item');
            if (!item) return;
            gfData = gfData.filter(function(f) { return f.id !== item.dataset.id; });
            item.remove();
            gfSerialize();
        });
    })();
    </script>
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

// ──────────────────────────────────────────────
// 8. Frontend Document Search Shortcode
// ──────────────────────────────────────────────
add_shortcode( 'cirlot_document_search', 'cirlot_docs_search_shortcode' );
function cirlot_docs_search_shortcode( $atts ) {
    $atts = shortcode_atts( [
        'type'     => '',
        'audience' => '',
        'per_page' => 10,
        'show_ai'  => 'true',
    ], $atts );

    $url_type     = sanitize_text_field( $_GET['type']     ?? '' );
    $url_audience = sanitize_text_field( $_GET['audience'] ?? '' );

    $default_type     = $url_type     ?: $atts['type'];
    $default_audience = $url_audience ?: $atts['audience'];

    $audiences = cirlot_docs_get_audiences();
    $types     = cirlot_docs_get_types();

    $matched_type = '';
    foreach ( $types as $t ) {
        if ( strtolower( $t ) === strtolower( $default_type ) ) { $matched_type = $t; break; }
    }
    $matched_audience = '';
    foreach ( $audiences as $a ) {
        if ( strtolower( $a ) === strtolower( $default_audience ) ) { $matched_audience = $a; break; }
    }

    $show_ai   = $atts['show_ai'] !== 'false';
    $per_page  = max( 1, min( 50, (int) $atts['per_page'] ) );
    $uid       = 'cds_' . wp_unique_id();
    $nonce     = wp_create_nonce( 'cirlot_docs_search' );
    $ai_nonce  = wp_create_nonce( 'cirlot_docs_ai_search' );
    $ajax_url  = admin_url( 'admin-ajax.php' );

    wp_enqueue_script( 'jquery' );

    // ── Build JS (values substituted at render time, output in footer) ──
    $js_uid     = wp_json_encode( $uid );
    $js_ajax    = wp_json_encode( $ajax_url );
    $js_nonce   = wp_json_encode( $nonce );
    $js_ainonce = wp_json_encode( $ai_nonce );
    $js_pp      = (int) $per_page;
    $js_showai  = $show_ai ? 'true' : 'false';
    $js_notext  = esc_js( __( 'No documents found. Try different search terms.' ) );
    $js_errtxt  = esc_js( __( 'Error loading results.' ) );
    $js_loading = esc_js( __( 'Searching…' ) );
    $js_found   = esc_js( __( 'document(s) found' ) );
    $js_page    = esc_js( __( 'Page' ) );
    $js_of      = esc_js( __( 'of' ) );
    $js_dl      = esc_js( __( 'Download' ) );
    $js_apply   = esc_js( __( 'Apply filters →' ) );
    $js_sorry   = esc_js( __( 'Sorry, I encountered an error. Please try again.' ) );
    $js_conn    = esc_js( __( 'Connection error. Please try again.' ) );
    $js_send    = esc_js( __( 'Send' ) );
    $js_nopdf        = esc_js( __( 'No PDF text — load a PDF and wait for extraction.' ) );
    $js_selone       = esc_js( __( 'Select at least one field.' ) );
    $js_globalfields = wp_json_encode( cirlot_docs_get_global_fields() );

    $js = <<<ENDSCRIPT
jQuery(function($){
    var uid={$js_uid},ajaxUrl={$js_ajax},nonce={$js_nonce},aiNonce={$js_ainonce},perPage={$js_pp},showAi={$js_showai};
    var globalFields={$js_globalfields};
    var \$wrap=\$('#'+uid),\$results=\$wrap.find('.cd-fs-results'),currentPage=1,botHistory=[],lastFilters=null;
    var \$aiExplain=\$wrap.find('.cd-fs-ai-explain');
    var \$kwWrap=\$wrap.find('.cd-fs-keyword-wrap');
    var \$kw=\$wrap.find('.cd-fs-keyword');
    var \$suggestions=\$('<div class="cd-fs-suggestions"></div>').appendTo(\$kwWrap);
    var _suggTimer,_explainXhr;

    \$kw.on('input',function(){
        clearTimeout(_suggTimer);
        var val=\$(this).val().trim();
        if(val.length<2){\$suggestions.hide().empty();return;}
        _suggTimer=setTimeout(function(){
            \$.post(ajaxUrl,{action:'cirlot_docs_search',nonce:nonce,keyword:val,page:1,per_page:6})
            .done(function(res){
                \$suggestions.empty();
                if(!res.success||!res.data.results.length){\$suggestions.hide();return;}
                \$.each(res.data.results,function(_,doc){
                    var fmt=doc.format||'generic';
                    var lbl={pdf:'PDF',word:'DOC',excel:'XLS',powerpoint:'PPT'}[fmt]||'FILE';
                    var \$s=\$('<div class="cd-fs-suggestion"></div>');
                    \$('<span class="cd-fs-suggestion-title"></span>').text(doc.title).appendTo(\$s);
                    \$s.append(\$('<span class="cd-fs-doc-tag format-'+fmt+'">'+lbl+'</span>'));
                    \$s.on('click',function(){\$kw.val(doc.title);\$suggestions.hide().empty();doSearch(1);});
                    \$suggestions.append(\$s);
                });
                \$suggestions.show();
            });
        },380);
    });
    \$(document).on('click.cdsugg'+uid,function(e){if(!\$(e.target).closest('.cd-fs-keyword-wrap').length)\$suggestions.hide();});

    var \$modalOverlay=\$('#cd-doc-modal-overlay-'+uid);
    \$('body').append(\$modalOverlay.detach());
    var \$modalIcon=\$('#cd-doc-modal-icon-'+uid),\$modalTitle=\$('#cd-doc-modal-title-'+uid);
    var \$modalTags=\$('#cd-doc-modal-tags-'+uid),\$modalBody=\$('#cd-doc-modal-body-'+uid);
    var \$modalFooter=\$('#cd-doc-modal-footer-'+uid);

    function openModal(doc){
        var fmt=doc.format||'generic';
        var lbl={pdf:'PDF',word:'DOC',excel:'XLS',powerpoint:'PPT'}[fmt]||fmt.toUpperCase()||'FILE';
        \$modalOverlay.find('.cd-doc-modal').attr('class','cd-doc-modal fmt-'+fmt);
        \$modalIcon.attr('class','cd-doc-modal-icon '+fmt).text(lbl);
        \$modalTitle.text(doc.title||'');
        var tags='<span class="cd-fs-doc-tag format-'+fmt+'">'+lbl+'</span>';
        \$.each(doc.audience||[],function(_,a){tags+='<span class="cd-fs-doc-tag audience">'+\$('<span>').text(a).html()+'</span>';});
        \$.each(doc.type||[],function(_,t){tags+='<span class="cd-fs-doc-tag type">'+\$('<span>').text(t).html()+'</span>';});
        \$modalTags.html(tags);
        var body='';
        var cf=doc.custom_fields||{};
        var descVal=(cf.description!==undefined)?cf.description:doc.description;
        if(descVal) body+='<div class="cd-doc-modal-desc">'+\$('<span>').text(descVal).html()+'</div>';
        var grid='';
        if((doc.audience||[]).length) grid+='<div class="cd-doc-modal-field"><div class="cd-doc-modal-label">Audience</div><div class="cd-doc-modal-value">'+\$('<span>').text(doc.audience.join(', ')).html()+'</div></div>';
        if((doc.type||[]).length)     grid+='<div class="cd-doc-modal-field"><div class="cd-doc-modal-label">Document Type</div><div class="cd-doc-modal-value">'+\$('<span>').text(doc.type.join(', ')).html()+'</div></div>';
        if(doc.pub_date)              grid+='<div class="cd-doc-modal-field"><div class="cd-doc-modal-label">Publication Date</div><div class="cd-doc-modal-value">'+formatDate(doc.pub_date)+'</div></div>';
        if(doc.format)                grid+='<div class="cd-doc-modal-field"><div class="cd-doc-modal-label">Format</div><div class="cd-doc-modal-value">'+\$('<span>').text(doc.format.toUpperCase()).html()+'</div></div>';
        \$.each(globalFields||[],function(_,gf){
            if(gf.id==='description')return;
            var v=cf[gf.id];
            if(v) grid+='<div class="cd-doc-modal-field"><div class="cd-doc-modal-label">'+\$('<span>').text(gf.label).html()+'</div><div class="cd-doc-modal-value">'+\$('<span>').text(v).html()+'</div></div>';
        });
        if(grid) body+='<div class="cd-doc-modal-grid">'+grid+'</div>';
        \$modalBody.html(body||'');
        \$modalFooter.find('.cd-doc-modal-dl').remove();
        \$modalFooter.find('.cd-doc-modal-footer-left').remove();
        var footerLeft=doc.pub_date?'<span class="cd-doc-modal-footer-left">'+formatDate(doc.pub_date)+'</span>':'<span class="cd-doc-modal-footer-left"></span>';
        \$modalFooter.prepend(footerLeft);
        if(doc.file_url){
            \$modalFooter.find('.cd-doc-modal-footer-right').prepend('<a href="'+doc.file_url+'" target="_blank" class="cd-doc-modal-dl" download>\u2193 Download</a>');
        }
        \$modalOverlay.addClass('open');
        \$('body').css('overflow','hidden');
    }

    function closeModal(){
        \$modalOverlay.removeClass('open');
        \$('body').css('overflow','');
    }

    \$('#cd-doc-modal-close-'+uid).on('click',closeModal);
    \$('#cd-doc-modal-cancel-'+uid).on('click',closeModal);
    \$modalOverlay.on('click',function(e){if(\$(e.target).is(\$modalOverlay))closeModal();});
    \$(document).on('keydown.cdmodal'+uid,function(e){if(e.key==='Escape')closeModal();});

    function formatDate(d){
        if(!d)return'';
        var p=d.split('-');if(p.length<3)return d;
        var m=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return m[parseInt(p[1],10)-1]+' '+parseInt(p[2],10)+', '+p[0];
    }

    function renderResults(data){
        \$results.empty();
        if(!data.results.length){\$results.html('<div class="cd-fs-empty">{$js_notext}</div>');return;}
        var hdr=\$('<div class="cd-fs-results-header"></div>').text(data.total+' {$js_found}'+(data.total_pages>1?' \u2014 {$js_page} '+data.page+' {$js_of} '+data.total_pages:''));
        \$results.append(hdr);
        \$.each(data.results,function(i,doc){
            var fmt=doc.format||'generic';
            var lbl={pdf:'PDF',word:'DOC',excel:'XLS',powerpoint:'PPT'}[fmt]||fmt.toUpperCase()||'FILE';
            var tags='<span class="cd-fs-doc-tag format-'+fmt+'">'+lbl+'</span>';
            \$.each(doc.audience||[],function(_,a){tags+='<span class="cd-fs-doc-tag audience">'+\$('<span>').text(a).html()+'</span>';});
            \$.each(doc.type||[],function(_,t){tags+='<span class="cd-fs-doc-tag type">'+\$('<span>').text(t).html()+'</span>';});
            if(doc.pub_date)tags+='<span class="cd-fs-doc-tag date">'+formatDate(doc.pub_date)+'</span>';
            var dl=doc.file_url?'<a href="'+doc.file_url+'" target="_blank" class="cd-fs-doc-dl" download>{$js_dl} \u2193</a>':'';
            var \$card=\$(
                '<div class="cd-fs-doc-card">'+
                '<div class="cd-fs-doc-icon '+fmt+'">'+lbl+'</div>'+
                '<div class="cd-fs-doc-body">'+
                '<p class="cd-fs-doc-title">'+\$('<span>').text(doc.title).html()+'</p>'+
                '<div class="cd-fs-doc-meta">'+tags+'</div></div>'+
                '<div class="cd-fs-doc-actions">'+dl+'</div></div>'
            );
            \$card.on('click',function(e){if(\$(e.target).closest('a').length)return;openModal(doc);});
            \$results.append(\$card);
        });
        if(data.total_pages>1){
            var \$pag=\$('<div class="cd-fs-pagination"></div>');
            for(var p=1;p<=data.total_pages;p++){(function(pg){
                var \$b=\$('<button class="cd-fs-page-btn'+(pg===data.page?' active':'')+'" type="button"></button>').text(pg);
                \$b.on('click',function(){doSearch(pg);});\$pag.append(\$b);
            })(p);}
            \$results.append(\$pag);
        }
    }

    function fetchAiExplain(results,kw,aud,typ){
        if(_explainXhr)_explainXhr.abort();
        \$aiExplain.html('<div class="cd-fs-ai-thinking"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Analyzing results…</div>');
        var titles=[];
        \$.each(results.slice(0,8),function(_,d){titles.push(d.title);});
        _explainXhr=\$.post(ajaxUrl,{
            action:'cirlot_docs_ai_explain',nonce:aiNonce,
            keyword:kw,audience:aud,type:typ,
            titles:JSON.stringify(titles)
        }).done(function(res){
            if(res.success&&res.data.explanation){
                var \$box=\$('<div class="cd-fs-ai-explain-box"></div>');
                \$box.append('<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>');
                \$box.append(\$('<span></span>').text(res.data.explanation));
                \$aiExplain.html('').append(\$box);
            } else {
                \$aiExplain.empty();
            }
        }).fail(function(){\$aiExplain.empty();});
    }

    function doSearch(page){
        page=page||1;currentPage=page;
        \$results.html('<div class="cd-fs-loading">{$js_loading}</div>');
        var kw=\$wrap.find('.cd-fs-keyword').val();
        var aud=\$wrap.find('.cd-fs-audience').val();
        var typ=\$wrap.find('.cd-fs-type').val();
        \$suggestions.hide().empty();
        \$.post(ajaxUrl,{
            action:'cirlot_docs_search',nonce:nonce,
            keyword:kw,audience:aud,type:typ,
            page:page,per_page:perPage
        }).done(function(res){
            if(res.success){
                renderResults(res.data);
                if(showAi&&res.data.results.length)fetchAiExplain(res.data.results,kw,aud,typ);
                else \$aiExplain.empty();
            } else {
                \$results.html('<div class="cd-fs-empty">{$js_errtxt}</div>');
                \$aiExplain.empty();
            }
        }).fail(function(){
            \$results.html('<div class="cd-fs-empty">{$js_errtxt}</div>');
            \$aiExplain.empty();
        });
    }

    \$wrap.find('.cd-fs-search-btn').on('click',function(){doSearch(1);});
    \$wrap.find('.cd-fs-keyword').on('keydown',function(e){if(e.key==='Enter')doSearch(1);});
    doSearch(1);

    if(!showAi)return;

    var \$toggle=\$('#cd-bot-toggle-'+uid),\$panel=\$('#cd-bot-panel-'+uid);
    var \$messages=\$('#cd-bot-messages-'+uid),\$input=\$('#cd-bot-input-'+uid),\$send=\$('#cd-bot-send-'+uid);

    \$toggle.on('click',function(){\$panel.toggleClass('open');if(\$panel.hasClass('open'))\$input.focus();});
    \$('#cd-bot-close-'+uid).on('click',function(){\$panel.removeClass('open');});

    function addMsg(text,role,filters){
        var \$msg=\$('<div class="cd-bot-msg '+role+'"></div>').text(text);
        if(filters&&(filters.keyword||filters.audience||filters.type)){
            lastFilters=filters;
            var \$btn=\$('<button class="cd-bot-apply" type="button">{$js_apply}</button>');
            \$btn.on('click',function(){
                if(filters.keyword)\$wrap.find('.cd-fs-keyword').val(filters.keyword);
                if(filters.audience)\$wrap.find('.cd-fs-audience').val(filters.audience);
                if(filters.type)\$wrap.find('.cd-fs-type').val(filters.type);
                doSearch(1);\$panel.removeClass('open');
            });
            \$msg.append(\$('<br>')).append(\$btn);
        }
        \$messages.append(\$msg);\$messages.scrollTop(\$messages[0].scrollHeight);
    }

    function sendBotMessage(){
        var msg=\$input.val().trim();if(!msg)return;
        addMsg(msg,'user');\$input.val('');\$send.prop('disabled',true).text('…');
        botHistory.push({role:'user',text:msg});
        \$.post(ajaxUrl,{action:'cirlot_docs_ai_search',nonce:aiNonce,message:msg,history:JSON.stringify(botHistory.slice(-6))})
        .done(function(res){
            if(res.success){addMsg(res.data.message,'bot',res.data.filters);botHistory.push({role:'model',text:res.data.message});}
            else addMsg('{$js_sorry}','bot');
        }).fail(function(){addMsg('{$js_conn}','bot');})
        .always(function(){\$send.prop('disabled',false).text('{$js_send}');});
    }

    \$send.on('click',sendBotMessage);
    \$input.on('keydown',function(e){if(e.key==='Enter')sendBotMessage();});
});
ENDSCRIPT;

    add_action( 'wp_footer', function() use ( $js ) {
        echo '<script>' . $js . '</script>' . "\n";
    }, 99 );

    ob_start();
    ?>
    <style>
    .cd-fs-wrap{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:900px;margin:0 auto;}
    .cd-fs-card{background:#fff;border:1.5px solid #d8dde6;border-radius:14px;padding:36px 40px 32px;}
    .cd-fs-title{text-align:center;font-size:26px;font-weight:700;color:#1a2744;margin:0 0 28px;display:flex;align-items:center;gap:16px;}
    .cd-fs-title::before,.cd-fs-title::after{content:'';flex:1;height:1.5px;background:linear-gradient(to right,transparent,#c8d0dc);}
    .cd-fs-title::after{background:linear-gradient(to left,transparent,#c8d0dc);}
    /* Single-row controls */
    .cd-fs-controls{display:flex;gap:10px;align-items:center;margin-bottom:0;}
    .cd-fs-keyword-wrap{flex:2;min-width:0;position:relative;}
    .cd-fs-keyword-wrap svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;pointer-events:none;}
    .cd-fs-keyword{width:100%;box-sizing:border-box;height:46px;padding:0 14px 0 38px;border:1.5px solid #c8d0dc;border-radius:8px;font-size:14px;color:#1a2744;background:#fff;outline:none;transition:border-color .18s;}
    .cd-fs-keyword:focus{border-color:#2c4a7c;}
    .cd-fs-select-wrap{flex:1;min-width:120px;}
    .cd-fs-select-wrap select{width:100%;height:46px;padding:0 36px 0 12px;border:1.5px solid #c8d0dc;border-radius:8px;font-size:13px;color:#1a2744;background:#fff;outline:none;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%232c4a7c' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;cursor:pointer;box-sizing:border-box;transition:border-color .18s;}
    .cd-fs-select-wrap select:focus{border-color:#2c4a7c;}
    /* Search button */
    .cd-fs-search-btn{height:46px;padding:0 22px;background:#1e3a5f;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:background .18s;flex-shrink:0;}
    .cd-fs-search-btn:hover{background:#2c4a7c;}
    /* Results */
    .cd-fs-results{margin-top:24px;}
    .cd-fs-results-header{font-size:13px;color:#6b7280;margin-bottom:14px;}
    .cd-fs-doc-card{display:flex;gap:16px;padding:18px 20px;border:1px solid #e5e9ef;border-radius:10px;margin-bottom:12px;background:#fff;transition:box-shadow .18s,border-color .18s;}
    .cd-fs-doc-card:hover{box-shadow:0 3px 14px rgba(0,0,0,.08);border-color:#b8cce4;}
    .cd-fs-doc-icon{flex-shrink:0;width:44px;height:54px;border-radius:5px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#fff;letter-spacing:.3px;}
    .cd-fs-doc-icon.pdf{background:#e74c3c;}.cd-fs-doc-icon.word{background:#2b5797;}.cd-fs-doc-icon.excel{background:#1e7145;}.cd-fs-doc-icon.generic{background:#7f8c8d;}
    .cd-fs-doc-body{flex:1;min-width:0;}
    .cd-fs-doc-title{font-size:15px;font-weight:700;color:#1a2744;margin:0 0 6px;}
    .cd-fs-doc-title a{color:inherit;text-decoration:none;}.cd-fs-doc-title a:hover{color:#2c4a7c;text-decoration:underline;}
    .cd-fs-doc-desc{font-size:13px;color:#6b7280;margin:0 0 10px;line-height:1.55;}
    .cd-fs-doc-meta{display:flex;flex-wrap:wrap;gap:6px;align-items:center;}
    .cd-fs-doc-tag{font-size:11px;padding:3px 9px;border-radius:20px;font-weight:600;display:inline-flex;align-items:center;gap:4px;}
    .cd-fs-doc-tag.format-pdf{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;}
    .cd-fs-doc-tag.format-word{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;}
    .cd-fs-doc-tag.format-excel{background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;}
    .cd-fs-doc-tag.format-generic{background:#f3f4f6;color:#6b7280;border:1px solid #e5e7eb;}
    .cd-fs-doc-tag.type{background:#e8f0fb;color:#2c4a7c;}.cd-fs-doc-tag.audience{background:#f0faf4;color:#1e6e45;}.cd-fs-doc-tag.date{background:#f5f5f5;color:#6b7280;}
    .cd-fs-doc-actions{flex-shrink:0;display:flex;align-items:flex-start;padding-top:2px;}
    .cd-fs-doc-dl{display:inline-flex;align-items:center;gap:6px;background:#1e3a5f;color:#fff;border-radius:7px;padding:8px 16px;font-size:13px;font-weight:600;text-decoration:none;transition:background .15s;white-space:nowrap;}
    .cd-fs-doc-dl:hover{background:#2c4a7c;color:#fff;}
    .cd-fs-empty{text-align:center;padding:40px 20px;color:#9ca3af;font-size:14px;}
    .cd-fs-pagination{display:flex;gap:6px;justify-content:center;margin-top:18px;}
    .cd-fs-page-btn{height:34px;min-width:34px;padding:0 10px;border:1.5px solid #d8dde6;background:#fff;border-radius:6px;font-size:13px;cursor:pointer;transition:background .15s,border-color .15s;}
    .cd-fs-page-btn:hover,.cd-fs-page-btn.active{background:#1e3a5f;color:#fff;border-color:#1e3a5f;}
    .cd-fs-loading{text-align:center;padding:32px;color:#9ca3af;font-size:14px;}
    /* Autocomplete suggestions */
    .cd-fs-suggestions{position:absolute;top:calc(100% + 2px);left:0;right:0;background:#fff;border:1.5px solid #c8d0dc;border-radius:0 0 10px 10px;z-index:200;box-shadow:0 6px 20px rgba(0,0,0,.1);max-height:220px;overflow-y:auto;display:none;}
    .cd-fs-suggestion{padding:9px 14px 9px 38px;font-size:13px;color:#374151;cursor:pointer;display:flex;align-items:center;gap:8px;border-bottom:1px solid #f0f2f5;}
    .cd-fs-suggestion:last-child{border-bottom:none;}
    .cd-fs-suggestion:hover,.cd-fs-suggestion.highlighted{background:#f0f6ff;color:#1a2744;}
    .cd-fs-suggestion-title{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    /* AI explanation */
    .cd-fs-ai-explain{margin-top:14px;}
    .cd-fs-ai-explain-box{display:flex;align-items:flex-start;gap:10px;background:linear-gradient(135deg,#f0f6ff 0%,#e8f3ff 100%);border:1px solid #c0d4f0;border-radius:10px;padding:12px 16px;font-size:13px;color:#1a2744;line-height:1.65;}
    .cd-fs-ai-explain-box svg{flex-shrink:0;color:#2c4a7c;margin-top:1px;}
    .cd-fs-ai-thinking{font-size:12px;color:#9ca3af;padding:6px 2px;display:flex;align-items:center;gap:6px;}
    /* Document modal */
    .cd-doc-modal-overlay{position:fixed;inset:0;background:rgba(10,18,35,.6);z-index:99990;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .22s;}
    .cd-doc-modal-overlay.open{opacity:1;pointer-events:auto;}
    .cd-doc-modal{background:#fff;border-radius:18px;width:100%;max-width:640px;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 24px 80px rgba(0,0,0,.28);transform:translateY(20px) scale(.97);transition:transform .24s cubic-bezier(.22,.68,0,1.2),opacity .22s;opacity:0;overflow:hidden;}
    .cd-doc-modal-overlay.open .cd-doc-modal{transform:translateY(0) scale(1);opacity:1;}
    /* colored top accent based on format */
    .cd-doc-modal.fmt-pdf{border-top:4px solid #e74c3c;}
    .cd-doc-modal.fmt-word{border-top:4px solid #2b5797;}
    .cd-doc-modal.fmt-excel{border-top:4px solid #1e7145;}
    .cd-doc-modal.fmt-generic{border-top:4px solid #7f8c8d;}
    .cd-doc-modal-header{display:flex;align-items:flex-start;gap:18px;padding:22px 24px 18px;border-bottom:1px solid #f0f2f5;flex-shrink:0;}
    .cd-doc-modal-icon{flex-shrink:0;width:50px;height:62px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#fff;letter-spacing:.5px;}
    .cd-doc-modal-icon.pdf{background:linear-gradient(145deg,#e74c3c,#c0392b);}
    .cd-doc-modal-icon.word{background:linear-gradient(145deg,#2b5797,#1a3d7a);}
    .cd-doc-modal-icon.excel{background:linear-gradient(145deg,#1e7145,#145232);}
    .cd-doc-modal-icon.generic{background:linear-gradient(145deg,#7f8c8d,#636e72);}
    .cd-doc-modal-title-wrap{flex:1;min-width:0;padding-top:2px;}
    .cd-doc-modal-title{font-size:17px;font-weight:700;color:#1a2744;margin:0 0 10px;line-height:1.4;}
    .cd-doc-modal-tags{display:flex;flex-wrap:wrap;gap:5px;}
    .cd-doc-modal-close{background:none;border:none;cursor:pointer;color:#b0b8c8;padding:4px;line-height:1;flex-shrink:0;font-size:22px;border-radius:6px;transition:color .15s,background .15s;}
    .cd-doc-modal-close:hover{color:#1a2744;background:#f0f2f5;}
    .cd-doc-modal-body{padding:22px 24px;overflow-y:auto;flex:1;}
    .cd-doc-modal-desc{font-size:14px;color:#374151;line-height:1.7;margin-bottom:20px;padding-bottom:20px;border-bottom:1px solid #f0f2f5;}
    .cd-doc-modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
    .cd-doc-modal-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#b0b8c8;margin-bottom:5px;}
    .cd-doc-modal-value{font-size:14px;color:#1a2744;font-weight:500;line-height:1.5;}
    .cd-doc-modal-footer{padding:14px 24px;background:#f8f9fb;border-top:1px solid #edf0f4;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
    .cd-doc-modal-footer-left{font-size:12px;color:#9ca3af;}
    .cd-doc-modal-footer-right{display:flex;gap:10px;align-items:center;}
    .cd-doc-modal-dl{display:inline-flex;align-items:center;gap:8px;background:#1e3a5f;color:#fff;border-radius:8px;padding:10px 22px;font-size:14px;font-weight:600;text-decoration:none;transition:background .15s;}
    .cd-doc-modal-dl:hover{background:#2c4a7c;color:#fff;}
    .cd-doc-modal-cancel{height:42px;padding:0 18px;border:1.5px solid #d8dde6;background:#fff;border-radius:8px;font-size:14px;color:#374151;cursor:pointer;transition:border-color .15s,background .15s;}
    .cd-doc-modal-cancel:hover{border-color:#2c4a7c;background:#f0f6ff;}
    /* card clickable */
    .cd-fs-doc-card{cursor:pointer;}
    /* AI Bot */
    .cd-bot-toggle{position:fixed;bottom:28px;right:28px;z-index:9990;background:#1e3a5f;color:#fff;border:none;border-radius:50px;padding:13px 22px;font-size:14px;font-weight:600;cursor:pointer;box-shadow:0 4px 16px rgba(30,58,95,.35);display:flex;align-items:center;gap:8px;transition:background .18s,transform .12s;}
    .cd-bot-toggle:hover{background:#2c4a7c;transform:translateY(-2px);}
    .cd-bot-panel{position:fixed;bottom:90px;right:28px;z-index:9991;width:360px;max-width:calc(100vw - 40px);background:#fff;border:1.5px solid #d8dde6;border-radius:14px;box-shadow:0 8px 40px rgba(0,0,0,.15);display:none;flex-direction:column;max-height:480px;}
    .cd-bot-panel.open{display:flex;}
    .cd-bot-header{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #e5e9ef;flex-shrink:0;}
    .cd-bot-header strong{font-size:14px;color:#1a2744;}
    .cd-bot-close{background:none;border:none;cursor:pointer;font-size:20px;color:#9ca3af;line-height:1;padding:0;}
    .cd-bot-messages{flex:1;overflow-y:auto;padding:14px 16px;display:flex;flex-direction:column;gap:10px;}
    .cd-bot-msg{max-width:90%;padding:10px 13px;border-radius:10px;font-size:13px;line-height:1.5;}
    .cd-bot-msg.bot{background:#f0f6ff;color:#1a2744;align-self:flex-start;border-bottom-left-radius:3px;}
    .cd-bot-msg.user{background:#1e3a5f;color:#fff;align-self:flex-end;border-bottom-right-radius:3px;}
    .cd-bot-apply{display:inline-block;margin-top:7px;padding:5px 12px;background:#2c4a7c;color:#fff;border:none;border-radius:5px;font-size:12px;cursor:pointer;}
    .cd-bot-input-wrap{display:flex;gap:8px;padding:12px 14px;border-top:1px solid #e5e9ef;flex-shrink:0;}
    .cd-bot-input{flex:1;height:38px;padding:0 12px;border:1.5px solid #c8d0dc;border-radius:8px;font-size:13px;outline:none;}
    .cd-bot-input:focus{border-color:#2c4a7c;}
    .cd-bot-send{height:38px;padding:0 14px;background:#1e3a5f;color:#fff;border:none;border-radius:8px;font-size:13px;cursor:pointer;}
    .cd-bot-send:disabled{opacity:.5;cursor:default;}
    @media(max-width:600px){.cd-fs-controls{flex-wrap:wrap;}.cd-fs-keyword-wrap{flex:none;width:100%;}.cd-fs-select-wrap{flex:1;min-width:calc(50% - 5px);}.cd-fs-search-btn{width:100%;justify-content:center;}.cd-fs-card{padding:24px 18px;}.cd-bot-panel{width:calc(100vw - 40px);}}
    </style>

    <div class="cd-fs-wrap" id="<?php echo esc_attr( $uid ); ?>">
        <div class="cd-fs-card">
            <h2 class="cd-fs-title"><?php esc_html_e( 'Document Search' ); ?></h2>

            <!-- Single-row controls: keyword + audience + type + search -->
            <div class="cd-fs-controls">
                <div class="cd-fs-keyword-wrap">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" class="cd-fs-keyword" placeholder="<?php esc_attr_e( 'Search documents…' ); ?>" value="<?php echo esc_attr( sanitize_text_field( $_GET['q'] ?? '' ) ); ?>">
                </div>
                <div class="cd-fs-select-wrap">
                    <select class="cd-fs-audience">
                        <option value=""><?php esc_html_e( 'Any Audience' ); ?></option>
                        <?php foreach ( $audiences as $a ) : ?>
                        <option value="<?php echo esc_attr( $a ); ?>" <?php selected( $a, $matched_audience ); ?>><?php echo esc_html( $a ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="cd-fs-select-wrap">
                    <select class="cd-fs-type">
                        <option value=""><?php esc_html_e( 'Any Type' ); ?></option>
                        <?php foreach ( $types as $t ) : ?>
                        <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $t, $matched_type ); ?>><?php echo esc_html( $t ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="cd-fs-search-btn">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <?php esc_html_e( 'Search' ); ?>
                </button>
            </div>

            <div class="cd-fs-ai-explain"></div>
            <div class="cd-fs-results"></div>
        </div>
    </div>

    <!-- Document detail modal -->
    <div class="cd-doc-modal-overlay" id="cd-doc-modal-overlay-<?php echo esc_attr( $uid ); ?>" role="dialog" aria-modal="true">
        <div class="cd-doc-modal">
            <div class="cd-doc-modal-header">
                <div class="cd-doc-modal-icon" id="cd-doc-modal-icon-<?php echo esc_attr( $uid ); ?>">PDF</div>
                <div class="cd-doc-modal-title-wrap">
                    <p class="cd-doc-modal-title" id="cd-doc-modal-title-<?php echo esc_attr( $uid ); ?>"></p>
                    <div class="cd-doc-modal-tags" id="cd-doc-modal-tags-<?php echo esc_attr( $uid ); ?>"></div>
                </div>
                <button class="cd-doc-modal-close" id="cd-doc-modal-close-<?php echo esc_attr( $uid ); ?>" aria-label="Close">&times;</button>
            </div>
            <div class="cd-doc-modal-body" id="cd-doc-modal-body-<?php echo esc_attr( $uid ); ?>"></div>
            <div class="cd-doc-modal-footer" id="cd-doc-modal-footer-<?php echo esc_attr( $uid ); ?>">
                <div class="cd-doc-modal-footer-right">
                    <button class="cd-doc-modal-cancel" id="cd-doc-modal-cancel-<?php echo esc_attr( $uid ); ?>"><?php esc_html_e( 'Close' ); ?></button>
                </div>
            </div>
        </div>
    </div>

    <?php if ( $show_ai ) : ?>
    <button class="cd-bot-toggle" id="cd-bot-toggle-<?php echo esc_attr( $uid ); ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <?php esc_html_e( 'AI Assistant' ); ?>
    </button>
    <div class="cd-bot-panel" id="cd-bot-panel-<?php echo esc_attr( $uid ); ?>">
        <div class="cd-bot-header">
            <strong><?php esc_html_e( '✨ Document AI Assistant' ); ?></strong>
            <button class="cd-bot-close" id="cd-bot-close-<?php echo esc_attr( $uid ); ?>">&times;</button>
        </div>
        <div class="cd-bot-messages" id="cd-bot-messages-<?php echo esc_attr( $uid ); ?>">
            <div class="cd-bot-msg bot"><?php esc_html_e( 'Hello! Tell me what document you\'re looking for and I\'ll help you find it.' ); ?></div>
        </div>
        <div class="cd-bot-input-wrap">
            <input type="text" class="cd-bot-input" id="cd-bot-input-<?php echo esc_attr( $uid ); ?>" placeholder="<?php esc_attr_e( 'e.g. policies for new faculty…' ); ?>">
            <button class="cd-bot-send" id="cd-bot-send-<?php echo esc_attr( $uid ); ?>"><?php esc_html_e( 'Send' ); ?></button>
        </div>
    </div>
    <?php endif; ?>

    <?php
    return ob_get_clean();
}

// ── AJAX: Search documents ────────────────────
add_action( 'wp_ajax_cirlot_docs_search',        'cirlot_docs_search_ajax' );
add_action( 'wp_ajax_nopriv_cirlot_docs_search', 'cirlot_docs_search_ajax' );
function cirlot_docs_search_ajax() {
    check_ajax_referer( 'cirlot_docs_search', 'nonce' );

    $keyword  = sanitize_text_field( $_POST['keyword']  ?? '' );
    $audience = sanitize_text_field( $_POST['audience'] ?? '' );
    $type     = sanitize_text_field( $_POST['type']     ?? '' );
    $page     = max( 1, absint( $_POST['page']     ?? 1 ) );
    $per_page = max( 1, min( 50, absint( $_POST['per_page'] ?? 10 ) ) );

    $args = [
        'post_type'      => 'cirlot_document',
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];

    if ( $keyword ) {
        $args['s'] = $keyword;
    }

    $tax_query = [];
    if ( $audience ) {
        $tax_query[] = [ 'taxonomy' => 'document_audience', 'field' => 'name', 'terms' => [ $audience ] ];
    }
    if ( $type ) {
        $tax_query[] = [ 'taxonomy' => 'document_type', 'field' => 'name', 'terms' => [ $type ] ];
    }
    if ( $tax_query ) {
        $args['tax_query'] = array_merge( [ 'relation' => 'AND' ], $tax_query );
    }

    $query   = new WP_Query( $args );
    $results = [];

    while ( $query->have_posts() ) {
        $query->the_post();
        $pid      = get_the_ID();
        $file_id  = get_post_meta( $pid, '_document_file_id', true );
        $file_url = $file_id ? wp_get_attachment_url( $file_id ) : '';

        $cf_values = [];
        foreach ( cirlot_docs_get_global_fields() as $gf ) {
            $fid = $gf['id'];
            $cf_values[ $fid ] = $fid === 'description'
                ? get_post_meta( $pid, '_document_description', true )
                : get_post_meta( $pid, '_document_cf_' . $fid, true );
        }

        $results[] = [
            'id'            => $pid,
            'title'         => get_the_title(),
            'description'   => get_post_meta( $pid, '_document_description', true ),
            'file_url'      => $file_url,
            'pub_date'      => get_post_meta( $pid, '_document_pub_date', true ),
            'format'        => get_post_meta( $pid, '_document_file_format', true ),
            'audience'      => wp_get_post_terms( $pid, 'document_audience', [ 'fields' => 'names' ] ),
            'type'          => wp_get_post_terms( $pid, 'document_type',     [ 'fields' => 'names' ] ),
            'custom_fields' => $cf_values,
        ];
    }
    wp_reset_postdata();

    wp_send_json_success( [
        'results'     => $results,
        'total'       => $query->found_posts,
        'total_pages' => (int) $query->max_num_pages,
        'page'        => $page,
    ] );
}

// ── AJAX: AI Explain search results ──────────
add_action( 'wp_ajax_cirlot_docs_ai_explain',        'cirlot_docs_ai_explain_ajax' );
add_action( 'wp_ajax_nopriv_cirlot_docs_ai_explain', 'cirlot_docs_ai_explain_ajax' );
function cirlot_docs_ai_explain_ajax() {
    check_ajax_referer( 'cirlot_docs_ai_search', 'nonce' );

    $keyword  = sanitize_text_field( $_POST['keyword']  ?? '' );
    $audience = sanitize_text_field( $_POST['audience'] ?? '' );
    $type     = sanitize_text_field( $_POST['type']     ?? '' );
    $titles   = json_decode( stripslashes( $_POST['titles'] ?? '[]' ), true );
    if ( ! is_array( $titles ) ) $titles = [];
    $titles = array_slice( array_map( 'sanitize_text_field', $titles ), 0, 8 );

    if ( ! $titles ) wp_send_json_error( 'No results.' );

    $api_key = get_option( 'cirlot_docs_gemini_api_key', '' );
    $model   = get_option( 'cirlot_docs_gemini_model', 'gemini-2.5-flash' );
    if ( ! $api_key ) wp_send_json_error( 'AI not configured.' );

    $query_parts = [];
    if ( $keyword )  $query_parts[] = 'keyword: "' . $keyword . '"';
    if ( $audience ) $query_parts[] = 'audience: "' . $audience . '"';
    if ( $type )     $query_parts[] = 'type: "' . $type . '"';
    $query_desc = $query_parts ? implode( ', ', $query_parts ) : 'no specific filters';

    $titles_list = implode( "\n- ", $titles );
    $prompt  = "A user searched a document library with {$query_desc}. ";
    $prompt .= "The following documents were returned:\n- {$titles_list}\n\n";
    $prompt .= "Write 1-2 sentences in the same language the search query implies (default to the site language) explaining why these results are relevant to the user's search. ";
    $prompt .= "Be concise and helpful. Do not use markdown.";

    $response = wp_remote_post(
        'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode( $model ) . ':generateContent?key=' . urlencode( $api_key ),
        [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'contents'         => [ [ 'role' => 'user', 'parts' => [ [ 'text' => $prompt ] ] ] ],
                'generationConfig' => [ 'temperature' => 0.4, 'maxOutputTokens' => 120 ],
            ] ),
            'timeout' => 20,
        ]
    );

    if ( is_wp_error( $response ) ) wp_send_json_error( $response->get_error_message() );

    $code = (int) wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 ) wp_send_json_error( $body['error']['message'] ?? 'API error ' . $code );

    $text = trim( $body['candidates'][0]['content']['parts'][0]['text'] ?? '' );

    wp_send_json_success( [ 'explanation' => $text ] );
}

// ── AJAX: AI Search Assistant ─────────────────
add_action( 'wp_ajax_cirlot_docs_ai_search',        'cirlot_docs_ai_search_ajax' );
add_action( 'wp_ajax_nopriv_cirlot_docs_ai_search', 'cirlot_docs_ai_search_ajax' );
function cirlot_docs_ai_search_ajax() {
    check_ajax_referer( 'cirlot_docs_ai_search', 'nonce' );

    $message = sanitize_textarea_field( $_POST['message'] ?? '' );
    $history = json_decode( stripslashes( $_POST['history'] ?? '[]' ), true );

    if ( ! $message ) wp_send_json_error( 'Empty message.' );

    $api_key = get_option( 'cirlot_docs_gemini_api_key', '' );
    $model   = get_option( 'cirlot_docs_gemini_model', 'gemini-2.5-flash' );
    if ( ! $api_key ) wp_send_json_error( 'AI not configured.' );

    $types     = cirlot_docs_get_types();
    $audiences = cirlot_docs_get_audiences();
    $site      = get_bloginfo( 'name' );

    $system  = "You are a helpful document search assistant for {$site}. ";
    $system .= 'Available document types: ' . implode( ', ', $types ) . '. ';
    $system .= 'Available audiences: ' . implode( ', ', $audiences ) . '. ';
    $system .= 'Help users find documents by understanding their natural language requests. ';
    $system .= 'Respond ONLY with a valid JSON object with two keys: ';
    $system .= '"message" (your friendly, concise response in the same language the user wrote in) and ';
    $system .= '"filters" (object with keys: keyword (string), audience (string, must match one of available audiences or empty), type (string, must match one of available document types or empty)). ';
    $system .= 'Only suggest audience/type values from the available lists. No markdown, no fences.';

    $contents = [ [ 'role' => 'user', 'parts' => [ [ 'text' => $system . "\n\nUser: " . $message ] ] ] ];

    foreach ( (array) $history as $turn ) {
        if ( isset( $turn['role'], $turn['text'] ) && in_array( $turn['role'], [ 'user', 'model' ], true ) ) {
            $contents[] = [ 'role' => $turn['role'], 'parts' => [ [ 'text' => $turn['text'] ] ] ];
        }
    }

    $response = wp_remote_post(
        'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode( $model ) . ':generateContent?key=' . urlencode( $api_key ),
        [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'contents'         => $contents,
                'generationConfig' => [ 'temperature' => 0.4, 'responseMimeType' => 'application/json' ],
            ] ),
            'timeout' => 30,
        ]
    );

    if ( is_wp_error( $response ) ) wp_send_json_error( $response->get_error_message() );

    $code = (int) wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 ) wp_send_json_error( $body['error']['message'] ?? 'API error ' . $code );

    $text   = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $text   = preg_replace( '/^```(?:json)?\s*/m', '', trim( $text ) );
    $text   = preg_replace( '/\s*```\s*$/m', '', $text );
    $result = json_decode( trim( $text ), true );

    if ( ! is_array( $result ) || ! isset( $result['message'] ) ) {
        $result = [
            'message' => trim( $text ) ?: __( 'I couldn\'t process that. Please try again.' ),
            'filters' => [ 'keyword' => '', 'audience' => '', 'type' => '' ],
        ];
    }

    if ( ! isset( $result['filters'] ) ) {
        $result['filters'] = [ 'keyword' => '', 'audience' => '', 'type' => '' ];
    }

    wp_send_json_success( $result );
}
