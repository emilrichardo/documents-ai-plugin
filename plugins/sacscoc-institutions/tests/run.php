<?php
/** Standalone renderer contract tests. Run: php plugins/sacscoc-institutions/tests/run.php */
error_reporting( E_ALL );
set_error_handler( static function ( $severity, $message, $file, $line ) {
    if ( error_reporting() & $severity ) throw new ErrorException( $message, 0, $severity, $file, $line );
} );
define( 'ABSPATH', __DIR__ . '/' );
define( 'SACSCOC_INST_DIR', dirname( __DIR__ ) . '/' );
define( 'SACSCOC_INST_URL', 'https://example.test/wp-content/plugins/sacscoc-institutions/' );
define( 'SACSCOC_INST_VERSION', 'test' );
class WP_Post { public $ID = 10; public $post_content = ''; }
$GLOBALS['test_shortcodes'] = [];
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function add_shortcode( $tag, $callback ) { $GLOBALS['test_shortcodes'][ $tag ] = $callback; }
function __( $s, $domain = '' ) { return $s; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $s ) { return esc_attr( $s ); }
function esc_url( $s ) { return esc_attr( $s ); }
function esc_url_raw( $s ) { return $s; }
function esc_html__( $s, $domain = '' ) { return esc_html( $s ); }
function esc_html_e( $s, $domain = '' ) { echo esc_html( $s ); }
function esc_attr_e( $s, $domain = '' ) { echo esc_attr( $s ); }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $s ) ); }
function sanitize_title( $s ) { return sanitize_key( $s ); }
function sanitize_text_field( $s ) { return trim( strip_tags( $s ) ); }
function wp_unslash( $s ) { return stripslashes( $s ); }
function shortcode_atts( $defaults, $atts, $tag = '' ) { return array_merge( $defaults, array_intersect_key( (array) $atts, $defaults ) ); }
function wp_unique_id( $prefix = '' ) { static $counter = 0; return $prefix . ++$counter; }
function selected( $a, $b ) { if ( (string) $a === (string) $b ) echo 'selected="selected"'; }
function sacscoc_inst_tables_ready() { return true; }
function get_transient( $key ) { return [2026, 2027]; }
function get_option( $key, $default = false ) { return $GLOBALS['test_options'][ $key ] ?? $default; }
function get_post_status( $id ) { return $id === 99 ? 'publish' : false; }
function get_permalink( $id ) { return 'https://example.test/directory-page/'; }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function get_query_var( $key ) { return ''; }
function is_singular() { return false; }
function is_page() { return false; }
function get_post() { return $GLOBALS['test_post'] ?? null; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['test_elementor'] ?? ''; }
function has_shortcode( $text, $tag ) { return preg_match( '/\[' . preg_quote( $tag, '/' ) . '(?:\s|\])/', $text ) === 1; }
function has_block( $name, $text ) { return strpos( $text, '<!-- wp:' . $name ) !== false; }
function locate_template( $files ) { return ''; }
function wp_style_is( $handle, $status ) { return ! empty( $GLOBALS['test_styles'][ $status ] ); }
function wp_register_style( ...$args ) { $GLOBALS['test_styles']['registered'] = true; }
function wp_enqueue_style( ...$args ) { $GLOBALS['test_styles']['enqueued'] = true; }
function wp_script_is( $handle, $status ) { return true; }
function get_block_wrapper_attributes() { return 'class="wp-block-sacsoc-search"'; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
require SACSCOC_INST_DIR . 'includes/query.php';
require SACSCOC_INST_DIR . 'includes/icons.php';
require SACSCOC_INST_DIR . 'includes/frontend.php';
require SACSCOC_INST_DIR . 'includes/blocks.php';

$passed = 0; $failed = 0;
function check( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); }
function test( $name, callable $test ) {
    global $passed, $failed;
    $_GET = []; $GLOBALS['test_options'] = []; $GLOBALS['test_styles'] = []; $GLOBALS['test_post'] = null; $GLOBALS['test_elementor'] = '';
    try { $test(); ++$passed; echo "PASS — $name\n"; }
    catch ( Throwable $e ) { ++$failed; echo "FAIL — $name: {$e->getMessage()}\n"; }
}
function markup( $atts = [] ) { return sacscoc_inst_search_shortcode( $atts ); }
function dom( $html ) {
    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors( true );
    $doc->loadHTML( '<?xml encoding="UTF-8">' . $html );
    libxml_clear_errors(); libxml_use_internal_errors( $previous );
    return new DOMXPath( $doc );
}
function contract( $html ) {
    $xpath = dom( $html ); $form = $xpath->query( '//form' )->item( 0 ); $values = [];
    foreach ( $xpath->query( '//form//input[@name] | //form//select[@name]' ) as $field ) {
        $value = $field->getAttribute( 'value' );
        if ( $field->tagName === 'select' ) {
            $selected = $xpath->query( 'option[@selected]', $field );
            $value = $selected->length ? $selected->item( 0 )->getAttribute( 'value' ) : '';
        }
        $values[ $field->getAttribute( 'name' ) ] = $value;
    }
    return [$form->getAttribute( 'method' ), $form->getAttribute( 'action' ), $form->getAttribute( 'data-sacscoc-group' ), $values];
}

$variants = [ 'theme' => ['light','dark'], 'layout' => ['horizontal','vertical'], 'size' => ['compact','default','large'], 'width' => ['auto','contained','full'], 'show_labels' => ['yes','no'] ];
foreach ( $variants as $key => $values ) foreach ( $values as $value ) {
    test( "$key=$value renders its visual variant without changing GET submission", static function () use ( $key, $value ) {
        $_GET = ['si_q' => 'Sample College', 'si_state' => 'TX', 'si_degree' => 'master', 'si_year' => '2027'];
        $atts = ['results_url' => '/institutions/', 'group' => 'hero'];
        $legacy = contract( markup( $atts ) );
        $html = markup( $atts + [$key => $value] );
        $class = 'sacscoc-institution-search--' . ( $key === 'show_labels' ? 'labels' : $key ) . '-' . $value;
        check( strpos( $html, $class ) !== false, 'missing class ' . $class );
        check( contract( $html ) === $legacy, 'GET destination, field names, group or current values changed' );
    } );
}

test( 'Legacy shortcode defaults keep the vertical panel, width cap and heading', static function () {
    $html = markup();
    check( strpos( $html, 'sacscoc-institution-search--visual' ) === false, 'new presentation should be opt-in' );
    check( strpos( $html, 'sacscoc-contain-width' ) !== false, 'legacy width missing' );
    check( strpos( $html, 'sacscoc-search--stacked' ) === false, 'legacy vertical layout changed' );
    check( dom( $html )->query( '//form/h2' )->length === 1, 'heading missing' );
    check( contract( $html )[3] === ['si_q'=>'','si_state'=>'','si_degree'=>'','si_year'=>''], 'query names changed' );
} );
test( 'Invalid visual values fall back to the existing shortcode appearance', static function () {
    $html = markup( ['theme'=>'purple','size'=>'huge','width'=>'200px','show_labels'=>'sometimes','layout'=>'diagonal'] );
    check( strpos( $html, 'sacscoc-institution-search--visual' ) === false, 'invalid values enable new styles' );
    check( strpos( $html, 'sacscoc-contain-width' ) !== false, 'invalid width removes old cap' );
    check( strpos( $html, 'sacscoc-institution-search--layout-vertical' ) !== false, 'invalid layout fallback changed' );
} );
test( 'Non-scalar or injected visual values are ignored', static function () {
    $options = sacscoc_inst_search_visual_options( ['theme'=>['dark'],'size'=>new stdClass(),'width'=>'full" onclick="bad','show_labels'=>'<yes>'] );
    check( $options === ['theme'=>'','size'=>'default','width'=>'','show_labels'=>''], 'invalid enum accepted' );
} );
test( 'Legacy horizontal layout and contain_width=no remain supported', static function () {
    $html = markup( ['layout'=>'horizontal','contain_width'=>'no','show_heading'=>'no'] );
    check( strpos( $html, 'sacscoc-search--stacked' ) !== false, 'horizontal class changed' );
    check( strpos( $html, 'sacscoc-institution-search--visual' ) === false, 'legacy horizontal enables new presentation' );
    check( strpos( $html, 'sacscoc-contain-width' ) === false, 'old no-width setting ignored' );
    check( dom( $html )->query( '//form/h2' )->length === 0, 'show_heading=no ignored' );
} );
test( 'Explicit width overrides contain_width in either direction', static function () {
    foreach ( ['auto','contained','full'] as $width ) {
        foreach ( ['yes','no'] as $old ) {
            $html = markup( ['width'=>$width,'contain_width'=>$old] );
            check( strpos( $html, 'sacscoc-contain-width' ) === false, 'legacy cap wins over explicit width' );
            check( strpos( $html, '--width-' . $width ) !== false, 'explicit width missing' );
        }
    }
} );
test( 'Singular and plural shortcode aliases use one renderer', static function () {
    check( $GLOBALS['test_shortcodes']['sacscoc_institution_search'] === $GLOBALS['test_shortcodes']['sacscoc_institutions_search'], 'aliases diverge' );
} );
test( 'Every field label targets a unique control across multiple embeds', static function () {
    $xpath = dom( markup( ['show_labels'=>'no'] ) . markup( ['show_labels'=>'yes'] ) );
    $ids = [];
    foreach ( $xpath->query( '//*[@id]' ) as $node ) { $id = $node->getAttribute( 'id' ); check( !isset($ids[$id]), 'duplicate id'); $ids[$id]=true; }
    check( count( $ids ) === 8, 'expected eight individually identified controls' );
    foreach ( $xpath->query( '//label' ) as $label ) {
        check( trim( $label->textContent ) !== '', 'empty accessible label' );
        check( isset($ids[$label->getAttribute('for')]), 'label has no control' );
    }
} );
test( 'Recommended hero keeps native GET action and explicit submit button', static function () {
    $html = markup( ['results_url'=>'/institutions/','theme'=>'dark','layout'=>'vertical','size'=>'compact','width'=>'full','show_labels'=>'yes','show_heading'=>'no'] );
    check( contract( $html )[0] === 'get', 'form method changed' );
    check( contract( $html )[1] === 'https://example.test/institutions/', 'results URL changed' );
    check( dom( $html )->query( '//button[@type="submit"][@data-sacscoc-submit]' )->length === 1, 'submit control missing' );
    check( strpos($html, 'data-sacscoc-directory') === false, 'form became a results region' );
} );
test( 'Results URL path, page id, same-site URL and off-site fallback remain intact', static function () {
    $GLOBALS['test_options']['sacscoc_inst_directory_page'] = 99;
    foreach ( ['/institutions/'=>'https://example.test/institutions/', '99'=>'https://example.test/directory-page/', 'https://example.test/custom/'=>'https://example.test/custom/', 'https://outside.test/'=>'https://example.test/directory-page/'] as $target=>$expected ) {
        check( contract( markup(['results_url'=>$target,'theme'=>'dark']) )[1] === $expected, 'results resolution changed: ' . $target );
    }
} );
test( 'Gutenberg forwards the same visual attributes as the shortcode', static function () {
    $html = sacscoc_inst_render_search_block( ['theme'=>'dark','layout'=>'horizontal','size'=>'large','width'=>'full','showLabels'=>'yes','resultsUrl'=>'/institutions/'] );
    foreach ( ['theme-dark','layout-horizontal','size-large','width-full','labels-yes'] as $suffix ) check( strpos($html,'sacscoc-institution-search--'.$suffix)!==false,'missing block attribute '.$suffix );
    $schema = json_decode( file_get_contents(SACSCOC_INST_DIR.'blocks/search/block.json'), true );
    foreach ( ['theme','layout','size','width','showLabels'] as $key ) check(isset($schema['attributes'][$key]),'missing block schema '.$key);
} );
test( 'Elementor multiline shortcodes are detected before styles print', static function () {
    $GLOBALS['test_post'] = new WP_Post();
    foreach ( ['sacscoc_institution_search','sacscoc_institutions_search'] as $tag ) {
        $GLOBALS['test_elementor'] = json_encode([['elements'=>[['settings'=>['shortcode'=>"[$tag\n theme=\"dark\"]"]]]]]);
        check(sacscoc_inst_page_uses_plugin(), 'Elementor shortcode was not detected');
        $GLOBALS['test_styles'] = [];
        sacscoc_inst_register_styles();
        check(wp_style_is('sacscoc-institutions','enqueued'),'Elementor styles not enqueued before render');
    }
} );
test( 'Shortcode stylesheet backstop works without Gutenberg or a scanned page', static function () {
    markup(['theme'=>'dark']);
    check(wp_style_is('sacscoc-institutions','enqueued'),'unregistered stylesheet was not enqueued');
} );
printf("\n%d passed, %d failed (of %d)\n", $passed, $failed, $passed+$failed);
exit($failed ? 1 : 0);
