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
    $description = get_post_meta( $post->ID, '_document_description', true );

    $custom_fields = [];
    $raw_cf = get_post_meta( $post->ID, '_document_custom_fields', true );
    if ( $raw_cf ) {
        $decoded = json_decode( $raw_cf, true );
        if ( is_array( $decoded ) ) $custom_fields = $decoded;
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

        <!-- Description -->
        <div style="margin-bottom:16px;">
            <label for="cd-description"><?php esc_html_e( 'Document Description' ); ?></label>
            <textarea id="cd-description" name="document_description"><?php echo esc_textarea( $description ); ?></textarea>
        </div>

        <!-- Custom AI Fields -->
        <div id="cd-custom-fields-wrap">
            <div id="cd-custom-fields-list">
                <?php foreach ( $custom_fields as $field ) :
                    $fid = esc_attr( $field['id'] );
                    $flabel = esc_html( $field['label'] ?? '' );
                    $ftype  = $field['type'] ?? 'text';
                    $fval   = $field['value'] ?? '';
                ?>
                <div class="cd-custom-field" data-id="<?php echo $fid; ?>">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <strong style="font-size:13px;"><?php echo $flabel; ?></strong>
                        <button type="button" class="cd-field-remove button button-small">&times;</button>
                    </div>
                    <?php if ( $ftype === 'text' ) : ?>
                    <input type="text" class="cd-field-value large-text" value="<?php echo esc_attr( $fval ); ?>">
                    <?php else : ?>
                    <textarea class="cd-field-value large-text" rows="<?php echo $ftype === 'list' ? 4 : 3; ?>"><?php echo esc_textarea( $fval ); ?></textarea>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <div style="margin-top:8px;">
                <button type="button" id="cd-add-field-btn" class="button">+ <?php esc_html_e( 'Add Field' ); ?></button>
                <div id="cd-add-field-form" style="display:none;">
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <input type="text" id="cd-new-field-label" placeholder="<?php esc_attr_e( 'Field name…' ); ?>" style="flex:1;min-width:150px;">
                        <select id="cd-new-field-type">
                            <option value="text"><?php esc_html_e( 'Text' ); ?></option>
                            <option value="textarea"><?php esc_html_e( 'Textarea' ); ?></option>
                            <option value="list"><?php esc_html_e( 'List' ); ?></option>
                        </select>
                        <button type="button" id="cd-new-field-add" class="button button-primary"><?php esc_html_e( 'Add' ); ?></button>
                        <button type="button" id="cd-new-field-cancel" class="button"><?php esc_html_e( 'Cancel' ); ?></button>
                    </div>
                </div>
            </div>

            <input type="hidden" name="document_custom_fields" id="cd-custom-fields-data"
                   value="<?php echo esc_attr( wp_json_encode( $custom_fields ) ); ?>">
        </div>

        <!-- Process with AI -->
        <div id="cd-ai-process-wrap">
            <div style="margin-bottom:12px;">
                <strong style="font-size:13px;display:block;margin-bottom:6px;"><?php esc_html_e( 'Fields to complete:' ); ?></strong>
                <label style="display:flex;align-items:center;gap:6px;padding:3px 0;font-weight:600;font-size:13px;cursor:pointer;margin:0;">
                    <input type="checkbox" id="cd-ai-select-all" checked>
                    <?php esc_html_e( 'Select All' ); ?>
                </label>
                <div id="cd-ai-fields-list">
                    <label class="cd-ai-field-option">
                        <input type="checkbox" class="cd-ai-field-check" data-field-id="title">
                        <?php esc_html_e( 'Title' ); ?>
                    </label>
                    <label class="cd-ai-field-option">
                        <input type="checkbox" class="cd-ai-field-check" data-field-id="description" checked>
                        <?php esc_html_e( 'Document Description' ); ?>
                    </label>
                    <label class="cd-ai-field-option">
                        <input type="checkbox" class="cd-ai-field-check" data-field-id="file_format" checked>
                        <?php esc_html_e( 'File Format' ); ?>
                    </label>
                    <label class="cd-ai-field-option">
                        <input type="checkbox" class="cd-ai-field-check" data-field-id="audience" checked>
                        <?php esc_html_e( 'Audience' ); ?>
                    </label>
                    <label class="cd-ai-field-option">
                        <input type="checkbox" class="cd-ai-field-check" data-field-id="document_type" checked>
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
        var cdAjaxUrl        = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
        var cdAjaxNonce      = <?php echo wp_json_encode( wp_create_nonce( 'cirlot_docs_ai' ) ); ?>;
        var cdFields         = <?php echo wp_json_encode( $custom_fields ); ?>;
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

        // ── Custom AI Fields ──────────────────────────
        function cdUid() {
            return 'field_' + Math.random().toString(36).slice(2, 9);
        }

        function cdRenderField(field) {
            var esc = function(s) { return $('<span>').text(s).html(); };
            var inputHtml;
            if (field.type === 'text') {
                inputHtml = '<input type="text" class="cd-field-value large-text" value="' + esc(field.value || '') + '">';
            } else {
                var rows = field.type === 'list' ? 4 : 3;
                inputHtml = '<textarea class="cd-field-value large-text" rows="' + rows + '">' + esc(field.value || '') + '</textarea>';
            }
            return $(
                '<div class="cd-custom-field" data-id="' + esc(field.id) + '">' +
                    '<div style="display:flex;justify-content:space-between;align-items:center;">' +
                        '<strong style="font-size:13px;">' + esc(field.label) + '</strong>' +
                        '<button type="button" class="cd-field-remove button button-small">&times;</button>' +
                    '</div>' +
                    inputHtml +
                '</div>'
            );
        }

        function cdSerializeFields() {
            var result = [];
            $('#cd-custom-fields-list .cd-custom-field').each(function() {
                var id    = $(this).data('id');
                var field = cdFields.find(function(f) { return f.id === id; });
                if (!field) return;
                field.value = $(this).find('.cd-field-value').val() || '';
                result.push(field);
            });
            cdFields = result;
            $('#cd-custom-fields-data').val(JSON.stringify(result));
        }

        $(document).on('click', '.cd-field-remove', function() {
            var id = $(this).closest('.cd-custom-field').data('id');
            cdFields = cdFields.filter(function(f) { return f.id !== id; });
            $(this).closest('.cd-custom-field').remove();
            cdSerializeFields();
            cdSyncAiFieldList();
        });

        $(document).on('input change', '.cd-field-value', cdSerializeFields);

        $('#cd-add-field-btn').on('click', function() {
            $('#cd-add-field-form').show();
            $('#cd-new-field-label').focus();
        });
        $('#cd-new-field-cancel').on('click', function() {
            $('#cd-add-field-form').hide();
            $('#cd-new-field-label').val('');
        });

        function cdAddField() {
            var label = $('#cd-new-field-label').val().trim();
            if (!label) { $('#cd-new-field-label').focus(); return; }
            var field = { id: cdUid(), label: label, type: $('#cd-new-field-type').val(), value: '' };
            cdFields.push(field);
            $('#cd-custom-fields-list').append(cdRenderField(field));
            cdSerializeFields();
            cdSyncAiFieldList();
            $('#cd-add-field-form').hide();
            $('#cd-new-field-label').val('');
        }
        $('#cd-new-field-add').on('click', cdAddField);
        $('#cd-new-field-label').on('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); cdAddField(); }
        });

        // ── AI fields checklist sync ──────────────────
        function cdSyncAiFieldList() {
            $('#cd-ai-fields-list .cd-ai-custom-field-option').remove();
            cdFields.forEach(function(f) {
                var esc = function(s) { return $('<span>').text(s).html(); };
                var $opt = $('<label class="cd-ai-field-option cd-ai-custom-field-option"></label>');
                $opt.append('<input type="checkbox" class="cd-ai-field-check" data-field-id="' + esc(f.id) + '" checked> ');
                $opt.append($('<span>').text(f.label));
                $('#cd-ai-fields-list').append($opt);
            });
            cdUpdateSelectAll();
        }

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

        cdSyncAiFieldList();

        // ── Process with AI ───────────────────────────
        $('#cd-ai-process-btn').on('click', function() {
            cdSerializeFields();

            var rawText = Object.keys(cdPageTexts).sort(function(a, b) { return a - b; }).map(function(p) {
                return '--- Page ' + p + ' ---\n' + cdPageTexts[p];
            }).join('\n\n');

            if (!rawText) {
                $('#cd-ai-status').text('<?php esc_html_e( 'No PDF text — load a PDF and wait for extraction.' ); ?>');
                return;
            }

            var staticDefs = {
                title:         { id: 'title',         label: '<?php esc_js( __( 'Document Title' ) ); ?>',       type: 'text' },
                description:   { id: 'description',   label: '<?php esc_js( __( 'Document Description' ) ); ?>', type: 'textarea' },
                file_format:   { id: 'file_format',   label: '<?php esc_js( __( 'File Format' ) ); ?>',          type: 'file_format' },
                audience:      { id: 'audience',      label: '<?php esc_js( __( 'Audience' ) ); ?>',             type: 'multiselect', options: cdAudienceOptions },
                document_type: { id: 'document_type', label: '<?php esc_js( __( 'Document Type' ) ); ?>',        type: 'multiselect', options: cdTypeOptions }
            };

            var fieldsToFill = [];
            $('.cd-ai-field-check:checked').each(function() {
                var fid = $(this).data('field-id');
                if (staticDefs[fid]) {
                    fieldsToFill.push(staticDefs[fid]);
                } else {
                    var f = cdFields.find(function(cf) { return cf.id === fid; });
                    if (f) fieldsToFill.push({ id: f.id, label: f.label, type: f.type });
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
                    $('#title').val(data.title);
                }
                if (data.description !== undefined) {
                    $('#cd-description').val(data.description);
                }
                if (data.file_format !== undefined) {
                    $('input[name="document_file_format"][value="' + data.file_format + '"]').prop('checked', true);
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
                cdFields.forEach(function(f) {
                    if (data[f.id] !== undefined) {
                        $('#cd-custom-fields-list .cd-custom-field[data-id="' + f.id + '"] .cd-field-value').val(data[f.id]);
                        f.value = data[f.id];
                    }
                });
                cdSerializeFields();
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

    // Description
    if ( isset( $_POST['document_description'] ) ) {
        update_post_meta( $post_id, '_document_description', sanitize_textarea_field( $_POST['document_description'] ) );
    }
    update_post_meta( $post_id, '_document_description_ai', ! empty( $_POST['document_description_ai'] ) ? '1' : '' );

    // Custom AI fields
    if ( isset( $_POST['document_custom_fields'] ) ) {
        $raw_json = stripslashes( $_POST['document_custom_fields'] );
        $decoded  = json_decode( $raw_json, true );
        if ( is_array( $decoded ) ) {
            $allowed_types = [ 'text', 'textarea', 'list' ];
            $clean = array_map( function( $f ) use ( $allowed_types ) {
                return [
                    'id'    => preg_replace( '/[^a-z0-9_]/', '', $f['id'] ?? '' ),
                    'label' => sanitize_text_field( $f['label'] ?? '' ),
                    'type'  => in_array( $f['type'] ?? '', $allowed_types, true ) ? $f['type'] : 'text',
                    'value' => sanitize_textarea_field( $f['value'] ?? '' ),
                    'ai'    => ! empty( $f['ai'] ),
                ];
            }, array_values( $decoded ) );
            update_post_meta( $post_id, '_document_custom_fields', wp_json_encode( $clean ) );
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

function cirlot_docs_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    if ( isset( $_POST['cirlot_docs_settings_nonce'] ) &&
         wp_verify_nonce( $_POST['cirlot_docs_settings_nonce'], 'cirlot_docs_settings_save' ) ) {

        update_option( 'cirlot_docs_archive_slug',      sanitize_text_field( $_POST['cirlot_docs_archive_slug'] ?? 'documents' ) );
        update_option( 'cirlot_docs_default_audience',  sanitize_text_field( $_POST['cirlot_docs_default_audience'] ?? '' ) );
        update_option( 'cirlot_docs_default_type',      sanitize_text_field( $_POST['cirlot_docs_default_type'] ?? '' ) );
        update_option( 'cirlot_docs_allowed_formats',   array_map( 'sanitize_text_field', (array) ( $_POST['cirlot_docs_allowed_formats'] ?? [ 'pdf', 'word', 'excel' ] ) ) );
        update_option( 'cirlot_docs_gemini_model',      sanitize_text_field( $_POST['cirlot_docs_gemini_model'] ?? 'gemini-2.5-flash' ) );
        if ( isset( $_POST['cirlot_docs_gemini_api_key'] ) && $_POST['cirlot_docs_gemini_api_key'] !== '' ) {
            update_option( 'cirlot_docs_gemini_api_key', sanitize_text_field( $_POST['cirlot_docs_gemini_api_key'] ) );
        }

        // Audiences list
        $raw_audiences = sanitize_textarea_field( $_POST['cirlot_docs_audiences_list'] ?? '' );
        update_option( 'cirlot_docs_audiences_list', $raw_audiences );
        foreach ( array_filter( array_map( 'trim', explode( "\n", $raw_audiences ) ) ) as $term ) {
            if ( ! term_exists( $term, 'document_audience' ) ) wp_insert_term( $term, 'document_audience' );
        }

        // Document Types list
        $raw_types = sanitize_textarea_field( $_POST['cirlot_docs_types_list'] ?? '' );
        update_option( 'cirlot_docs_types_list', $raw_types );
        foreach ( array_filter( array_map( 'trim', explode( "\n", $raw_types ) ) ) as $term ) {
            if ( ! term_exists( $term, 'document_type' ) ) wp_insert_term( $term, 'document_type' );
        }

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.' ) . '</p></div>';
        flush_rewrite_rules();
    }

    $slug             = get_option( 'cirlot_docs_archive_slug', 'documents' );
    $default_audience = get_option( 'cirlot_docs_default_audience', '' );
    $default_type     = get_option( 'cirlot_docs_default_type', '' );
    $allowed_formats  = (array) get_option( 'cirlot_docs_allowed_formats', [ 'pdf', 'word', 'excel' ] );
    $gemini_model     = get_option( 'cirlot_docs_gemini_model', 'gemini-2.5-flash' );
    $gemini_api_key   = get_option( 'cirlot_docs_gemini_api_key', '' );

    $audiences_list = get_option( 'cirlot_docs_audiences_list', implode( "\n", CIRLOT_DOCS_AUDIENCES ) );
    $types_list     = get_option( 'cirlot_docs_types_list',     implode( "\n", CIRLOT_DOCS_TYPES ) );

    // For datalist suggestions (default audience / default type fields)
    $audience_terms = get_terms( [ 'taxonomy' => 'document_audience', 'hide_empty' => false ] );
    $type_terms     = get_terms( [ 'taxonomy' => 'document_type',     'hide_empty' => false ] );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Documents Settings' ); ?></h1>
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

                <tr>
                    <td colspan="2"><hr><h2 style="margin:0 0 4px;"><?php esc_html_e( 'AI Settings (Gemini)' ); ?></h2></td>
                </tr>

                <tr>
                    <th scope="row"><label for="cd-gemini-model"><?php esc_html_e( 'Gemini Model' ); ?></label></th>
                    <td>
                        <?php
                        $gemini_models = [
                            'gemini-2.5-flash' => 'Gemini 2.5 Flash',
                            'gemini-3.0-flash' => 'Gemini 3.0 Flash',
                            'gemini-3.1-flash' => 'Gemini 3.1 Flash',
                            'gemini-2.5-pro'   => 'Gemini 2.5 Pro',
                            'gemini-3.1-pro'   => 'Gemini 3.1 Pro',
                        ];
                        ?>
                        <select id="cd-gemini-model" name="cirlot_docs_gemini_model">
                            <?php foreach ( $gemini_models as $model_id => $model_name ) : ?>
                            <option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( $gemini_model, $model_id ); ?>>
                                <?php echo esc_html( $model_name ); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="cd-gemini-api-key"><?php esc_html_e( 'Gemini API Key' ); ?></label></th>
                    <td>
                        <input type="password" id="cd-gemini-api-key" name="cirlot_docs_gemini_api_key"
                               value="<?php echo esc_attr( $gemini_api_key ); ?>"
                               class="regular-text" autocomplete="new-password">
                        <p class="description"><?php esc_html_e( 'Google Gemini API key for AI document processing. Leave blank to keep the current key.' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <td colspan="2"><hr><h2 style="margin:0 0 4px;"><?php esc_html_e( 'Taxonomy Lists' ); ?></h2>
                    <p class="description" style="margin:4px 0 0;"><?php esc_html_e( 'One item per line. Adding new items also registers them as taxonomy terms. Removing an item from this list does not delete the term.' ); ?></p></td>
                </tr>

                <tr>
                    <th scope="row"><label for="cd-audiences-list"><?php esc_html_e( 'Audiences' ); ?></label></th>
                    <td>
                        <textarea id="cd-audiences-list" name="cirlot_docs_audiences_list"
                                  rows="6" class="large-text"><?php echo esc_textarea( $audiences_list ); ?></textarea>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="cd-types-list"><?php esc_html_e( 'Document Types' ); ?></label></th>
                    <td>
                        <textarea id="cd-types-list" name="cirlot_docs_types_list"
                                  rows="12" class="large-text"><?php echo esc_textarea( $types_list ); ?></textarea>
                    </td>
                </tr>

            </table>
            <?php submit_button( __( 'Save Settings' ) ); ?>
        </form>
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
