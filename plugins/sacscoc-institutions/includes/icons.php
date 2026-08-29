<?php
/**
 * The icon set.
 *
 * Inline SVG, one small map, no icon font and no image requests: an icon that
 * is part of the markup inherits `currentColor`, scales with the type around it
 * and cannot arrive late or broken. Nothing here is loaded from a URL, so the
 * plugin directory can move without breaking a single glyph.
 *
 * Every icon is decorative — it sits beside a real label that already says what
 * the thing is — so they are all `aria-hidden` and never announced. If an icon
 * ever becomes the only label for something, give that control its own
 * `aria-label`; do not make these focusable.
 *
 * Drawn on a 24×24 grid, stroked rather than filled, so one CSS rule
 * (`.sacscoc-icon`) sets size, colour and weight for all of them.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * One icon as inline SVG, ready to echo.
 *
 * @param string $name  a key from the map below. An unknown name returns '',
 *                      so a typo loses an icon rather than printing a broken
 *                      element or a PHP notice.
 * @param string $class extra classes, e.g. 'sacscoc-icon--heading'
 */
function sacscoc_inst_icon( string $name, string $class = '' ): string {
    $paths = sacscoc_inst_icon_paths();
    if ( ! isset( $paths[ $name ] ) ) return '';

    $classes = trim( 'sacscoc-icon ' . $class );

    return sprintf(
        '<svg class="%s" viewBox="0 0 24 24" aria-hidden="true" focusable="false">%s</svg>',
        esc_attr( $classes ),
        $paths[ $name ]
    );
}

/**
 * The map itself.
 *
 * Kept in one function rather than as a constant so the paths can be filtered:
 * a theme that ships its own icon language can replace any of these without
 * touching a template.
 */
function sacscoc_inst_icon_paths(): array {
    static $paths = null;
    if ( $paths !== null ) return $paths;

    $paths = [
        // Directory
        'search'    => '<circle cx="11" cy="11" r="7"/><path d="m20.5 20.5-4.2-4.2"/>',
        'no-results' => '<circle cx="11" cy="11" r="7"/><path d="m20.5 20.5-4.2-4.2"/><path d="m9 9 4 4m0-4-4 4"/>',
        'results'   => '<path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/>',
        'back'      => '<path d="M19 12H5"/><path d="m11 18-6-6 6-6"/>',
        'reset'     => '<path d="M3.5 12a8.5 8.5 0 1 0 2.6-6.1L3 8.5"/><path d="M3 3.5v5h5"/>',

        // The record's sections
        'building'  => '<path d="M4 21h16"/><path d="M6 21V7l6-3 6 3v14"/><path d="M10 10h.01M14 10h.01M10 14h.01M14 14h.01M11 21v-3h2v3"/>',
        'seal'      => '<circle cx="12" cy="9" r="5.5"/><path d="m9 13.5-1 7 4-2 4 2-1-7"/><path d="m10 9 1.4 1.5L14.5 7.5"/>',
        'staff'     => '<circle cx="12" cy="8" r="3.5"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/>',

        // Fields
        'user'      => '<circle cx="12" cy="8" r="3.5"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/>',
        'pin'       => '<path d="M12 21s7-6.4 7-11a7 7 0 1 0-14 0c0 4.6 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'globe'     => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18 14 14 0 0 1 0-18z"/>',
        'phone'     => '<path d="M6.4 3h3.1l1.5 4-2 1.4a12.4 12.4 0 0 0 5.6 5.6L16 12l4 1.5v3.1a2 2 0 0 1-2.2 2A16.6 16.6 0 0 1 4 6.2 2 2 0 0 1 6.4 3z"/>',
        'mail'      => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 7.5 8.5 6 8.5-6"/>',
        'degrees'   => '<path d="m2.5 8 9.5-4 9.5 4-9.5 4z"/><path d="M6.5 10.2V15c0 1.7 2.5 3 5.5 3s5.5-1.3 5.5-3v-4.8"/>',
        'level'     => '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3.5 13.5 8.5 4.7 8.5-4.7"/>',
        'status'    => '<circle cx="12" cy="12" r="9"/><path d="m8.2 12.4 2.6 2.6 5-5.4"/>',
        'sanction'  => '<path d="M12 3.5 2.6 20h18.8z"/><path d="M12 10v4"/><path d="M12 17.2h.01"/>',
        'calendar'  => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10.5h18"/>',
        'control'   => '<path d="M4 21h16"/><path d="M6 21V9.5L12 6l6 3.5V21"/><path d="M10.5 21v-4h3v4"/>',
        'programs'  => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H19v15H6.5A2.5 2.5 0 0 0 4 20.5z"/><path d="M4 20.5A2.5 2.5 0 0 1 6.5 18H19v3H6.5"/>',
        'chart'     => '<path d="M3.5 20.5h17"/><path d="M7 20.5v-6M12 20.5V6M17 20.5v-9"/>',
        'external'  => '<path d="M14 4h6v6"/><path d="M20 4l-8.5 8.5"/><path d="M18 14.5V19a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 4 19V8a1.5 1.5 0 0 1 1.5-1.5H10"/>',
        'history'   => '<path d="M3.5 12a8.5 8.5 0 1 0 2.6-6.1L3 8.5"/><path d="M3 3.5v5h5"/><path d="M12 7.5V12l3 2"/>',
        'info'      => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5"/><path d="M12 7.8h.01"/>',
    ];

    /**
     * The icon paths, keyed by name.
     *
     * The values are raw SVG children of a 0 0 24 24 viewBox and are printed
     * unescaped, so a filter must only ever return markup it trusts.
     *
     * @param array $paths name => SVG path markup
     */
    $paths = (array) apply_filters( 'sacscoc_inst_icon_paths', $paths );

    return $paths;
}
