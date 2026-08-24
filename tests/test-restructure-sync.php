<?php
/**
 * Regression test: approving an AI restructure has to update
 * _document_raw_text (the "Edit content" textarea's backing meta) to match
 * the blocks just written to _document_content. Before this fix, only
 * _document_content was updated — the textarea kept showing pre-restructure
 * text, so editing it afterwards (or running Restructure again, which reads
 * that same text as its baseline) would silently discard the approved
 * result.
 */

function aidocs_test_call_ajax( callable $fn, array $post ) {
    $_POST = $post;
    try {
        $fn();
        throw new Exception( 'Expected ' . ( is_string( $fn ) ? $fn : 'callback' ) . ' to call wp_send_json_success/error.' );
    } catch ( Aidocs_Test_Json_Response $r ) {
        return $r->payload;
    } finally {
        $_POST = [];
    }
}

test( 'Approving a restructure syncs _document_raw_text to the new blocks', function () {
    $post_id = 77;
    $blocks  = [
        [ 'type' => 'heading',   'level' => 2, 'text' => 'Restructured Title' ],
        [ 'type' => 'paragraph', 'text' => 'Restructured paragraph text.' ],
    ];
    aidocs_test_seed_post( $post_id, [
        '_document_content_ai' => wp_json_encode( $blocks ),
        '_document_raw_text'   => 'Stale pre-restructure text that must not survive.',
        '_document_content'    => wp_json_encode( [ [ 'type' => 'paragraph', 'text' => 'old' ] ] ),
    ] );

    $res = aidocs_test_call_ajax( 'aidocs_ai_restructure_apply_ajax', [
        'post_id'  => $post_id,
        'decision' => 'apply',
    ] );

    assert_true( $res['success'], 'request succeeds' );
    assert_true( $res['data']['applied'], 'restructure is applied' );

    $stored_raw_text = get_post_meta( $post_id, '_document_raw_text', true );
    assert_true( strpos( $stored_raw_text, 'Restructured Title' ) !== false, '_document_raw_text reflects the new blocks' );
    assert_true( strpos( $stored_raw_text, 'Stale pre-restructure' ) === false, 'old text no longer lingers' );
    assert_equal( $res['data']['raw_text'], $stored_raw_text, 'response echoes the same text the client syncs into the editor' );
} );

test( 'Discarding a restructure proposal leaves _document_raw_text untouched', function () {
    $post_id = 78;
    aidocs_test_seed_post( $post_id, [
        '_document_content_ai' => wp_json_encode( [ [ 'type' => 'paragraph', 'text' => 'proposal' ] ] ),
        '_document_raw_text'   => 'Original text.',
    ] );

    $res = aidocs_test_call_ajax( 'aidocs_ai_restructure_apply_ajax', [
        'post_id'  => $post_id,
        'decision' => 'discard',
    ] );

    assert_true( $res['success'], 'request succeeds' );
    assert_true( ! $res['data']['applied'], 'nothing is applied' );
    assert_equal( get_post_meta( $post_id, '_document_raw_text', true ), 'Original text.', 'raw text untouched on discard' );
} );
