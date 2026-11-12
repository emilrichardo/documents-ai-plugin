<?php
/**
 * The link dialog's search ranking — aidocs_link_title_score().
 *
 * Found on staging with the dialog's first version, which used WordPress's own
 * search: "credit" listed "Separate Accreditation" first, and "dual" listed
 * every news post that mentions dual enrollment in passing above the policy
 * of that name. What an editor types is nearly always the start of a title.
 */

function rank( array $titles, $typed ) {
    $words = array_values( array_filter( preg_split( '/[\s\-_]+/u', $typed ) ) );
    usort( $titles, function ( $a, $b ) use ( $words ) {
        return aidocs_link_title_score( $b, $words ) <=> aidocs_link_title_score( $a, $words );
    } );
    return $titles;
}

test( 'A title that starts with the typed word beats one that only contains it', function () {
    $ranked = rank( [ 'Separate Accreditation', 'Accelerated Process', 'Reduced Credit Policy', 'Credit Hours' ], 'credit' );
    assert_equal( $ranked[0], 'Credit Hours' );
    assert_equal( $ranked[1], 'Reduced Credit Policy', 'a word that starts with it comes next' );
    assert_equal( end( $ranked ), 'Separate Accreditation', 'inside a word comes last' );
} );

test( 'The whole title beats a longer one that starts with it', function () {
    $ranked = rank( [ 'Dual Enrollment Guidelines', 'Dual Enrollment' ], 'dual enrollment' );
    assert_equal( $ranked[0], 'Dual Enrollment' );
} );

test( 'A slug typed with hyphens finds the title written with spaces', function () {
    $ranked = rank( [ 'Hours of Operation', 'Credit Hours' ], 'credit-hours' );
    assert_equal( $ranked[0], 'Credit Hours' );
    assert_true( aidocs_link_title_score( 'Credit Hours', [ 'credit', 'hours' ] ) >= 99, 'exact match' );
} );

test( 'Words in a different order still rank above letters found inside a word', function () {
    $in_order  = aidocs_link_title_score( 'Hours Credit', [ 'credit', 'hours' ] );
    $inside    = aidocs_link_title_score( 'Accreditation Hourly', [ 'credit', 'hours' ] );
    assert_true( $in_order > $inside, 'every word starting a word beats letters inside a word' );
} );

test( 'Shorter titles edge out longer ones of the same kind of match', function () {
    $short = aidocs_link_title_score( 'Credit Hours', [ 'credit' ] );
    $long  = aidocs_link_title_score( 'Credit Hours and Related Policies', [ 'credit' ] );
    assert_true( $short > $long );
} );

test( 'Accented and upper-case titles are compared in lower case', function () {
    assert_true( aidocs_link_title_score( 'ÁREA de Crédito', [ 'área' ] ) >= 79 );
    assert_true( aidocs_link_title_score( 'CREDIT HOURS', [ 'credit', 'hours' ] ) >= 99 );
} );
