<?php
/** The visual API must leave the form's submitted controls unchanged. */

function aidocs_test_visual_form( array $args = [], string $tag = 'aidocs_policy_search' ): string {
    return preg_replace( '#<style[^>]*>.*?</style>#s', '', aidocs_search_shortcode( $args, '', $tag ) );
}

function aidocs_test_visual_document( string $html ): DOMXPath {
    $doc = new DOMDocument();
    $old = libxml_use_internal_errors( true );
    $doc->loadHTML( '<!doctype html><html><body>' . $html . '</body></html>' );
    libxml_clear_errors();
    libxml_use_internal_errors( $old );
    return new DOMXPath( $doc );
}

function aidocs_test_form_contract( string $html ): array {
    $xpath = aidocs_test_visual_document( $html );
    $form = $xpath->query( '//form' )->item( 0 );
    $contract = [ $form->getAttribute( 'method' ), $form->getAttribute( 'action' ) ];
    foreach ( $xpath->query( '//form//*[@name]' ) as $control ) {
        $contract[] = [ $control->tagName, $control->getAttribute( 'type' ), $control->getAttribute( 'name' ), $control->getAttribute( 'value' ) ];
    }
    return $contract;
}

foreach ( aidocs_search_visual_schema() as $name => $values ) {
    foreach ( $values as $value ) {
        test( "Policy visual attribute {$name}={$value} renders and preserves submission", function () use ( $name, $value ) {
            $_GET = [ 'q' => 'transfer & credit', 'type' => 'Policies' ];
            try {
                $args = [ 'results_url' => '/policies/' ];
                $legacy = aidocs_test_visual_form( $args );
                $html = aidocs_test_visual_form( array_merge( $args, [ $name => $value ] ) );
                $modifier = $name === 'show_labels' ? 'labels' : $name;
                assert_true( strpos( $html, 'aidocs-policy-search--' . $modifier . '-' . $value ) !== false, 'validated modifier present' );
                assert_true( strpos( $html, 'aidocs-policy-search--visual' ) !== false, 'visual layer enabled' );
                assert_true( aidocs_test_form_contract( $html ) === aidocs_test_form_contract( $legacy ), 'all controls and the GET destination are identical' );
                assert_true( strpos( $html, 'cd-fs-results' ) === false && strpos( $html, '<script' ) === false, 'no results or script' );
            } finally { $_GET = []; }
        } );
    }
}

test( 'Policy visual options omit all modifiers on existing shortcodes', function () {
    $html = aidocs_test_visual_form();
    assert_true( strpos( $html, 'aidocs-policy-search--' ) === false, 'legacy presentation has no visual modifiers' );
    assert_true( strpos( $html, 'class="screen-reader-text"' ) !== false, 'legacy hidden label remains hidden' );
    assert_true( strpos( $html, 'class="cd-fs-search-btn"' ) !== false, 'legacy Search button retains its styles' );
} );

test( 'Invalid policy visual values fall back to legacy without leaking markup', function () {
    foreach ( [ 'invalid', '" onclick="bad()', '<dark>', [], 123, true, null ] as $invalid ) {
        $args = array_fill_keys( array_keys( aidocs_search_visual_schema() ), $invalid );
        $html = aidocs_test_visual_form( $args );
        assert_true( aidocs_normalize_search_visual_options( $args ) === aidocs_search_visual_defaults(), 'invalid enums inherit legacy defaults' );
        assert_true( strpos( $html, 'aidocs-policy-search--' ) === false, 'invalid options cannot enable visual layer' );
        assert_true( strpos( $html, 'onclick' ) === false, 'invalid values cannot inject markup' );
    }
} );

test( 'Policy visual attributes normalize surrounding spaces and case', function () {
    $options = aidocs_normalize_search_visual_options( [ 'theme' => ' DARK ', 'size' => ' Large ', 'show_labels' => ' YES ' ] );
    assert_equal( $options['theme'], 'dark' );
    assert_equal( $options['size'], 'large' );
    assert_equal( $options['show_labels'], 'yes' );
} );

test( 'Both form shortcode spellings share visual options and the destination', function () {
    $args = [ 'results_url' => '/policies/', 'theme' => 'dark', 'layout' => 'vertical', 'size' => 'compact', 'width' => 'full', 'show_labels' => 'yes' ];
    $policy = aidocs_test_visual_form( $args );
    $explicit = aidocs_test_visual_form( array_merge( $args, [ 'mode' => 'form' ] ), 'aidocs_search' );
    $without_ids = fn( $html ) => preg_replace( '/cdsf_\d+/', 'unique-field', $html );
    assert_equal( $without_ids( $policy ), $without_ids( $explicit ), 'same form markup' );
} );

test( 'Visible and hidden policy labels both identify a unique field', function () {
    $combined = '';
    foreach ( [ 'yes', 'no', 'yes' ] as $labels ) {
        $html = aidocs_test_visual_form( [ 'show_labels' => $labels ] );
        $combined .= $html;
        $xpath = aidocs_test_visual_document( $html );
        $label = $xpath->query( '//label' )->item( 0 );
        $field = $xpath->query( '//input[@name="q"]' )->item( 0 );
        assert_equal( $label->getAttribute( 'for' ), $field->getAttribute( 'id' ), 'label points to its own input' );
        assert_equal( trim( $label->textContent ), 'Search policies', 'both labels have an accessible name' );
        assert_true( ( strpos( $label->getAttribute( 'class' ), '__label--hidden' ) !== false ) === ( $labels === 'no' ), 'only no visually hides the label' );
        assert_true( $field->getAttribute( 'placeholder' ) !== '', 'field keeps its descriptive placeholder' );
    }
    $ids = [];
    foreach ( aidocs_test_visual_document( $combined )->query( '//*[@id]' ) as $element ) $ids[] = $element->getAttribute( 'id' );
    assert_equal( count( $ids ), count( array_unique( $ids ) ), 'multiple forms have unique field IDs' );
} );

test( 'All 72 policy visual combinations preserve the GET submission contract', function () {
    $_GET = [ 'q' => 'accreditation review', 'type' => 'Guidelines' ];
    try {
        $legacy = aidocs_test_form_contract( aidocs_test_visual_form( [ 'results_url' => '/policies/' ] ) );
        $count = 0;
        foreach ( [ 'light', 'dark' ] as $theme )
        foreach ( [ 'horizontal', 'vertical' ] as $layout )
        foreach ( [ 'compact', 'default', 'large' ] as $size )
        foreach ( [ 'auto', 'contained', 'full' ] as $width )
        foreach ( [ 'yes', 'no' ] as $labels ) {
            $html = aidocs_test_visual_form( [ 'theme' => $theme, 'layout' => $layout, 'size' => $size, 'width' => $width, 'show_labels' => $labels, 'results_url' => '/policies/' ] );
            assert_true( aidocs_test_form_contract( $html ) === $legacy, "$theme/$layout/$size/$width/$labels keeps all submitted controls" );
            $count++;
        }
        assert_equal( $count, 72 );
    } finally { $_GET = []; }
} );

test( 'Visual CSS is printed from the shortcode without Gutenberg or scripts', function () {
    $script = 'require ' . var_export( __DIR__ . '/bootstrap.php', true ) . '; echo aidocs_search_shortcode(["theme"=>"dark"], "", "aidocs_policy_search"); echo aidocs_search_shortcode(["theme"=>"light"], "", "aidocs_policy_search");';
    $html = shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $script ) );
    assert_equal( substr_count( $html, 'id="aidocs-policy-search-visual-css"' ), 1, 'visual stylesheet is delivered once for multiple shortcode instances' );
    assert_true( strpos( $html, '--search-field-height:' ) !== false, 'rendered CSS contains visual tokens' );
    assert_true( strpos( $html, '<script' ) === false, 'styling has no script dependency' );
} );

test( 'Form visual styles remain scoped and introduce no important overrides', function () {
    $css = file_get_contents( AIDOCS_DIR . 'assets/css/policy-search.css' );
    assert_true( strpos( $css, '!important' ) === false, 'no important declarations' );
    assert_true( strpos( $css, ':focus-visible' ) !== false && strpos( $css, 'outline-offset:' ) !== false, 'keyboard focus is explicit' );
    assert_true( strpos( $css, '--search-placeholder:' ) !== false && strpos( $css, '::placeholder' ) !== false, 'placeholder contrast has a dedicated token' );
    assert_true( preg_match( '/(?:^|\})\s*(input|button|label)\b/m', $css ) !== 1, 'no global element selectors' );
} );
