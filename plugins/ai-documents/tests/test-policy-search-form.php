<?php
/**
 * The standalone policy search form — [aidocs_policy_search], and
 * [aidocs_search mode="form"], which are one shortcode.
 *
 * What matters about this form is not how it looks but what it submits and
 * where: the whole point of it is that a search typed on one page arrives
 * intact on another. So these tests read the rendered HTML for the four
 * things that carry a search across that redirect —
 *
 *   · the form's action (aidocs_search_results_url())
 *   · the parameter names, `q` and `type`, which are the ones the listing
 *     already reads back
 *   · the values, prefilled from the query string so a form rendered on a
 *     results URL comes back showing the search that produced it
 *   · that no results are rendered underneath, which is the requirement
 *     that made this exist
 *
 * — plus the one piece of HTML semantics the type tabs depend on: the hidden
 * `type` field must come *before* the tab buttons, or the tab a visitor
 * clicked loses to it. See aidocs_render_search_form().
 */


/**
 * The form's markup with its <style> blocks removed.
 *
 * aidocs_search_styles() is guarded to once per request, so whether the CSS
 * lands in a given call's output depends on whether something already asked
 * for it — which would make "the form renders no results" pass or fail
 * depending on test order, since the stylesheet names .cd-fs-results whether
 * or not anything renders one. Assert on the markup alone.
 */
function aidocs_test_form_markup( array $args = [] ) {
    return preg_replace( '#<style[^>]*>.*?</style>#s', '', aidocs_render_search_form( $args ) );
}

test( 'The search form posts to the Policies Page when no results_url is given', function () {
    update_option( 'aidocs_policies_page', 77 );
    aidocs_test_seed_page( 77 );

    $html = aidocs_test_form_markup();

    assert_true(
        strpos( $html, 'action="' . get_permalink( 77 ) . '"' ) !== false,
        'form action is the configured Policies Page'
    );
} );

test( 'The search form falls back to the /policies/ archive with no page configured', function () {
    $html = aidocs_test_form_markup();

    assert_true(
        strpos( $html, 'action="http://example.test/' . aidocs_get_archive_slug() . '/"' ) !== false,
        'form action falls back to the archive'
    );
} );

test( 'results_url accepts a path, a page id and an on-site URL', function () {
    aidocs_test_seed_page( 91 );

    assert_equal(
        aidocs_search_results_url( '/policies/' ),
        'http://example.test/policies/',
        'a site-relative path'
    );
    assert_equal(
        aidocs_search_results_url( '91' ),
        get_permalink( 91 ),
        'a page id'
    );
    assert_equal(
        aidocs_search_results_url( 'http://example.test/policies/' ),
        'http://example.test/policies/',
        'an absolute URL on this site'
    );
} );

test( 'results_url ignores an off-site URL rather than honouring it', function () {
    // A typo in a shortcode attribute must not send a visitor's search to
    // somebody else's website.
    assert_equal(
        aidocs_search_results_url( 'https://elsewhere.example/policies/' ),
        'http://example.test/' . aidocs_get_archive_slug() . '/',
        'an off-site URL falls back to the listing'
    );
} );

test( 'results_url ignores a page id that is not a published page', function () {
    aidocs_test_seed_page( 42, 'draft' );

    assert_equal(
        aidocs_search_results_url( '42' ),
        'http://example.test/' . aidocs_get_archive_slug() . '/',
        'an unpublished page id falls back to the listing'
    );
} );

test( 'The search form submits q and type, and renders no results', function () {
    $html = aidocs_test_form_markup( [ 'results_url' => '/policies/' ] );

    assert_true( strpos( $html, 'name="q"' ) !== false, 'the keyword field is named q' );
    assert_true( strpos( $html, 'name="type"' ) !== false, 'the type is submitted as type' );
    assert_true( strpos( $html, 'method="get"' ) !== false, 'it is a GET form' );

    // The two containers the listing renders its results into. Neither may be
    // here: this is the requirement the whole mode exists for.
    assert_true( strpos( $html, 'cd-fs-results' ) === false, 'no results container' );
    assert_true( strpos( $html, 'cd-fs-ai-explain' ) === false, 'no AI suggestion container' );

    // And nothing that would go and fetch some.
    assert_true( strpos( $html, 'admin-ajax' ) === false, 'no AJAX endpoint' );
    assert_true( strpos( $html, '<script' ) === false, 'no script at all' );
} );

test( 'The search form prefills the keyword and the type from the query string', function () {
    $_GET['q']    = 'transfer credit';
    $_GET['type'] = 'Policies';

    $html = aidocs_test_form_markup( [ 'results_url' => '/policies/' ] );

    assert_true(
        strpos( $html, 'value="transfer credit"' ) !== false,
        'the keyword comes back in the field'
    );
    assert_true(
        substr_count( $html, 'name="type" value="Policies"' ) >= 1,
        'the type comes back on the buttons that carry it'
    );

    $_GET = [];
} );

test( 'An unknown type in the URL is dropped rather than submitted onward', function () {
    $_GET['type'] = 'Not A Real Type';

    $html = aidocs_test_form_markup();

    assert_true(
        strpos( $html, 'class="cd-fs-default-submit"' ) !== false
            && preg_match( '/name="type" value=""[^>]*cd-fs-default-submit/', $html ) === 1,
        'an unconfigured type resolves to All'
    );

    $_GET = [];
} );

test( 'Exactly one type is ever submitted, whichever control sends the form', function () {
    // Every control that can submit this form carries name="type", and a
    // button contributes its value only when it is the one that submitted —
    // so the query string gets one `type`, never two. There is deliberately
    // no hidden <input name="type">, which would be sent alongside whichever
    // button was clicked and put the parameter in twice.
    $html = aidocs_test_form_markup();

    assert_true(
        strpos( $html, 'type="hidden" name="type"' ) === false,
        'no hidden type field to double up with the buttons'
    );

    // The Enter key submits through the form's first submit button. That must
    // be the one carrying the active type, not the "All" tab that happens to
    // come first on the page.
    $first  = strpos( $html, 'name="type"' );
    $tab    = strpos( $html, 'class="cd-fs-type-tab' );
    assert_true( $first !== false && $tab !== false, 'both are rendered' );
    assert_true( $first < $tab, 'the default submit button precedes the first tab' );
    assert_true(
        strpos( $html, 'class="cd-fs-default-submit" tabindex="-1" aria-hidden="true"' ) !== false,
        'and it is invisible to sight, tab order and screen readers alike'
    );
} );

test( 'Every type tab is a submit button carrying its own type', function () {
    $html = aidocs_test_form_markup();

    // "All" plus one per configured type, each able to submit the form on its
    // own — which is what makes the tabs work with no JavaScript.
    // One per configured type, plus "All", plus the two that carry the active
    // type: the invisible default-submit and the Search button.
    $expected = count( aidocs_get_types() ) + 1 + 2;
    assert_equal(
        substr_count( $html, '<button type="submit" name="type"' ),
        $expected,
        'one submit button per type, plus All, plus the two default submitters'
    );
} );

test( 'show_heading="no" drops the card title but keeps the form', function () {
    $with    = aidocs_test_form_markup();
    $without = aidocs_test_form_markup( [ 'show_heading' => false ] );

    assert_true( strpos( $with, 'cd-fs-title' ) !== false, 'the heading is on by default' );
    assert_true( strpos( $without, 'cd-fs-title' ) === false, 'show_heading=no drops it' );
    assert_true( strpos( $without, 'name="q"' ) !== false, 'the field survives' );
} );

test( 'The shortcode tag chooses the mode: policy_search is the form, search is the listing', function () {
    // shortcode_atts() is handed the tag as its third argument by WordPress;
    // that is what makes [aidocs_policy_search] mean mode="form" without a
    // second copy of the renderer behind it.
    $strip = fn( $html ) => preg_replace( '#<style[^>]*>.*?</style>#s', '', $html );

    $form = $strip( aidocs_search_shortcode( [], '', 'aidocs_policy_search' ) );
    assert_true( strpos( $form, 'cd-fs-results' ) === false, 'the form tag renders no results' );
    assert_true( strpos( $form, 'method="get"' ) !== false, 'the form tag renders a GET form' );

    $explicit = $strip( aidocs_search_shortcode( [ 'mode' => 'form' ], '', 'aidocs_search' ) );
    assert_true( strpos( $explicit, 'method="get"' ) !== false, 'mode="form" does the same' );
} );
