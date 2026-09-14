<?php
/** Presentation-only options shared by the form shortcode and future editors. */
if ( ! defined( 'ABSPATH' ) ) exit;

function aidocs_search_visual_schema(): array {
    return [
        'theme'       => [ 'light', 'dark' ],
        'layout'      => [ 'horizontal', 'vertical' ],
        'size'        => [ 'compact', 'default', 'large' ],
        'width'       => [ 'auto', 'contained', 'full' ],
        'show_labels' => [ 'yes', 'no' ],
    ];
}

/** Empty means inherit the existing form, including its hidden field label. */
function aidocs_search_visual_defaults(): array {
    return array_fill_keys( array_keys( aidocs_search_visual_schema() ), '' );
}

function aidocs_normalize_search_visual_options( array $args ): array {
    $options = aidocs_search_visual_defaults();
    foreach ( aidocs_search_visual_schema() as $name => $allowed ) {
        $value = isset( $args[ $name ] ) && is_string( $args[ $name ] )
            ? strtolower( trim( $args[ $name ] ) ) : '';
        if ( in_array( $value, $allowed, true ) ) $options[ $name ] = $value;
    }
    return $options;
}

function aidocs_search_visual_classes( array $args ): string {
    $options = aidocs_normalize_search_visual_options( $args );
    $classes = [ 'aidocs-policy-search' ];
    if ( array_filter( $options ) ) $classes[] = 'aidocs-policy-search--visual';
    foreach ( $options as $name => $value ) {
        if ( $value === '' ) continue;
        $modifier = $name === 'show_labels' ? 'labels' : $name;
        $classes[] = 'aidocs-policy-search--' . $modifier . '-' . $value;
    }
    return implode( ' ', $classes );
}

/** Render-time loading also works inside Elementor Shortcode widgets. */
function aidocs_search_visual_styles(): void {
    static $printed = false;
    if ( $printed ) return;
    $printed = true;
    echo '<style id="aidocs-policy-search-visual-css">';
    readfile( AIDOCS_DIR . 'assets/css/policy-search.css' );
    echo '</style>';
}
