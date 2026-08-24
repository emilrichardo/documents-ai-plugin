<?php
/**
 * Regression tests for aidocs_save_meta() — specifically the bug where
 * clicking Update on the entry left open after a multi-document import
 * wiped Document Type, Audience and Document History that had just been
 * correctly extracted, because the form's hidden fields for those three
 * still held whatever they loaded with (usually nothing) rather than what
 * the import actually wrote to the post.
 *
 * The fix relies on a "touched" flag per field: aidocs_save_meta() only
 * treats an empty submitted value as "delete this" when the matching
 * `*_touched` field came back "1". These tests exercise that contract
 * directly, independent of the JS that is supposed to set it.
 */

function aidocs_test_base_post( array $meta_overrides = [] ) {
    $post_id = 42;
    aidocs_test_seed_post(
        $post_id,
        array_merge( [
            '_document_history' => 'Adopted March 2020',
        ], $meta_overrides ),
        [
            'document_audience' => [ 'Institution' ],
            'document_type'     => [ 'Policies' ],
        ]
    );
    return $post_id;
}

function aidocs_test_run_save_meta( $post_id, array $post_fields ) {
    $_POST = array_merge( [ 'aidocs_nonce' => 'ok' ], $post_fields );
    aidocs_save_meta( $post_id );
    $_POST = [];
}

// 1. The exact regression: the stale post-import form submits Type,
//    Audience and History as empty strings with their touched flags at
//    "0" (the default before any JS interaction) — Update must NOT erase
//    the data that was already correctly extracted and stored.
test( 'Update with untouched-empty fields preserves existing Type/Audience/History', function () {
    $post_id = aidocs_test_base_post();

    aidocs_test_run_save_meta( $post_id, [
        'document_type_terms'      => '',
        'document_type_touched'    => '0',
        'document_audience_terms'  => '',
        'document_audience_touched'=> '0',
        'document_history'         => '',
        'document_history_touched' => '0',
    ] );

    assert_equal( wp_get_post_terms( $post_id, 'document_type' ), [ 'Policies' ], 'document_type preserved' );
    assert_equal( wp_get_post_terms( $post_id, 'document_audience' ), [ 'Institution' ], 'document_audience preserved' );
    assert_equal( get_post_meta( $post_id, '_document_history', true ), 'Adopted March 2020', 'document_history preserved' );
} );

// 2. A deliberate clear (the editor actually removed every tag / emptied
//    the textarea, so the JS marked the field touched) must still work.
test( 'Update with touched-empty fields clears Type/Audience/History', function () {
    $post_id = aidocs_test_base_post();

    aidocs_test_run_save_meta( $post_id, [
        'document_type_terms'      => '',
        'document_type_touched'    => '1',
        'document_audience_terms'  => '',
        'document_audience_touched'=> '1',
        'document_history'         => '',
        'document_history_touched' => '1',
    ] );

    assert_equal( wp_get_post_terms( $post_id, 'document_type' ), [], 'document_type cleared' );
    assert_equal( wp_get_post_terms( $post_id, 'document_audience' ), [], 'document_audience cleared' );
    assert_equal( get_post_meta( $post_id, '_document_history', true ), '', 'document_history cleared' );
} );

// 3. A normal edit — new non-empty values — always applies, touched or not.
test( 'Update with non-empty values always applies them', function () {
    $post_id = aidocs_test_base_post();

    aidocs_test_run_save_meta( $post_id, [
        'document_type_terms'      => 'Guidelines',
        'document_type_touched'    => '0',
        'document_audience_terms'  => 'Public, Evaluator',
        'document_audience_touched'=> '0',
        'document_history'         => 'Revised June 2026',
        'document_history_touched' => '0',
    ] );

    assert_equal( wp_get_post_terms( $post_id, 'document_type' ), [ 'Guidelines' ], 'document_type updated' );
    assert_equal( wp_get_post_terms( $post_id, 'document_audience' ), [ 'Public', 'Evaluator' ], 'document_audience updated' );
    assert_equal( get_post_meta( $post_id, '_document_history', true ), 'Revised June 2026', 'document_history updated' );
} );

// 4. Fields simply absent from the POST body (e.g. a partial AJAX save)
//    must not touch existing data at all — isset() gates every branch.
test( 'Update with fields entirely absent from POST leaves them untouched', function () {
    $post_id = aidocs_test_base_post();

    aidocs_test_run_save_meta( $post_id, [] );

    assert_equal( wp_get_post_terms( $post_id, 'document_type' ), [ 'Policies' ], 'document_type untouched' );
    assert_equal( wp_get_post_terms( $post_id, 'document_audience' ), [ 'Institution' ], 'document_audience untouched' );
    assert_equal( get_post_meta( $post_id, '_document_history', true ), 'Adopted March 2020', 'document_history untouched' );
} );

// 5. End-to-end simulation of the original bug scenario: a multi-import
//    writes real data straight to the DB (as aidocs_write_policy() does),
//    then a save_post fires with the fields the (fixed) JS now sends —
//    non-empty values, touched — and the result must match what was
//    imported, not wipe it.
test( 'Multi-import then Update: JS-synced fields round-trip correctly', function () {
    $post_id = aidocs_test_base_post( [ '_document_history' => '' ] );
    // Simulate what aidocs_write_policy() does on import.
    wp_set_post_terms( $post_id, [ 'Good Practices' ], 'document_type' );
    wp_set_post_terms( $post_id, [ 'Evaluator' ], 'document_audience' );
    update_post_meta( $post_id, '_document_history', 'Adopted January 2019 (Board of Trustees)' );

    // The fixed cdImportBatch() JS now syncs the hidden fields (and marks
    // them touched via clearTags()/addTag()'s change event) before any
    // Update can be clicked.
    aidocs_test_run_save_meta( $post_id, [
        'document_type_terms'       => 'Good Practices',
        'document_type_touched'     => '1',
        'document_audience_terms'   => 'Evaluator',
        'document_audience_touched' => '1',
        'document_history'          => 'Adopted January 2019 (Board of Trustees)',
        'document_history_touched'  => '1',
    ] );

    assert_equal( wp_get_post_terms( $post_id, 'document_type' ), [ 'Good Practices' ], 'document_type survives Update after import' );
    assert_equal( wp_get_post_terms( $post_id, 'document_audience' ), [ 'Evaluator' ], 'document_audience survives Update after import' );
    assert_equal( get_post_meta( $post_id, '_document_history', true ), 'Adopted January 2019 (Board of Trustees)', 'document_history survives Update after import' );
} );

// 6. Document Type vocabulary lock: only the 4 confirmed types exist.
test( 'aidocs_get_types() returns exactly the 4 confirmed types', function () {
    assert_equal( aidocs_get_types(), [ 'Policies', 'Guidelines', 'Good Practices', 'Position Statements' ], 'exactly 4 types' );
} );

// 7. A disallowed or hallucinated Document Type (AI output, manual typo, or
//    a crafted request) must never reach wp_set_post_terms() — this is the
//    single choke point aidocs_save_meta() funnels every save through.
test( 'Update cannot create a new Document Type term', function () {
    $post_id = aidocs_test_base_post();

    aidocs_test_run_save_meta( $post_id, [
        'document_type_terms'   => 'Policy Statements, Handbooks',
        'document_type_touched' => '1',
    ] );

    // Both values are outside the 4 confirmed types, so nothing survives
    // sanitization — the taxonomy is left with no (invalid) term rather
    // than either invented name being written.
    assert_equal( wp_get_post_terms( $post_id, 'document_type' ), [], 'invalid AI/typed types are stripped, not created' );
} );

// 8. A request that mixes one valid and one invalid type keeps only the
//    valid one — sanitization is per-value, not all-or-nothing.
test( 'Update keeps the valid type and drops the invalid one from a mixed value', function () {
    $post_id = aidocs_test_base_post();

    aidocs_test_run_save_meta( $post_id, [
        'document_type_terms'   => 'guidelines, Handbooks',
        'document_type_touched' => '1',
    ] );

    // Case-insensitive match against the vocabulary, restored to its
    // configured casing ("Guidelines", not "guidelines").
    assert_equal( wp_get_post_terms( $post_id, 'document_type' ), [ 'Guidelines' ], 'valid type kept in configured casing, invalid one dropped' );
} );

// 9. aidocs_write_policy() — the multi-import write path — applies the same
//    vocabulary gate as aidocs_save_meta(), whether the value came from the
//    AI or from the "fixed for every entry" manual picker.
test( 'aidocs_write_policy() cannot write a disallowed Document Type', function () {
    $post_id = 43;
    aidocs_test_seed_post( $post_id, [], [] );

    aidocs_write_policy(
        $post_id,
        [ 'blocks' => [], 'description' => '', 'pub_date' => '', 'history' => '' ],
        [ 'audience' => [ 'Public' ], 'type' => [ 'Handbooks' ] ]
    );

    assert_equal( wp_get_post_terms( $post_id, 'document_type' ), [], 'disallowed type never written' );
    assert_equal( wp_get_post_terms( $post_id, 'document_audience' ), [ 'Public' ], 'audience is unaffected by the type check' );
} );
