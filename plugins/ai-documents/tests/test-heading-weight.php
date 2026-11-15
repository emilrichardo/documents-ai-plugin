<?php
/**
 * Headings in a rendered policy stay bold under a page builder's own rule.
 *
 * Found on staging: Elementor's ".elementor-kit-10 h4" sets weight 500, which
 * is more specific than the plugin's lone ".aidocs-content-h4" class, so the
 * section headings rendered lighter than the text around them. The fix names
 * the container and the tag as well; this guards the selectors, since the
 * cascade itself can only be seen in a browser.
 */

test( 'Each heading level has a bold rule more specific than a theme tag rule', function () {
    $css = aidocs_content_block_css();
    foreach ( [ 2, 3, 4 ] as $level ) {
        $rule = '.aidocs-content h' . $level . '.aidocs-content-h' . $level;
        assert_true( strpos( $css, $rule ) !== false, "missing selector $rule" );
    }
    assert_true(
        (bool) preg_match( '/\.aidocs-content h4\.aidocs-content-h4\s*\{[^}]*font-weight:\s*700/', $css ),
        'the h4 rule must set weight 700'
    );
} );

test( 'The stronger rule leaves size and colour to the theme', function () {
    $css = aidocs_content_block_css();
    preg_match( '/\.aidocs-content h2\.aidocs-content-h2[^{]*\{([^}]*)\}/', $css, $m );
    assert_equal( trim( $m[1] ), 'font-weight:700;', 'only the weight is forced' );
} );
