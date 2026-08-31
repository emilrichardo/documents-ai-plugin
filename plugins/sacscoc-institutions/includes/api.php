<?php
/**
 * The SACSCOC API client.
 *
 * Every request goes through sacscoc_inst_api_get(), and every URL is built
 * from the configured base (Institutions → Settings → API Base URL). The host
 * appears literally in exactly one place in this plugin — the
 * SACSCOC_INST_DEFAULT_API_BASE default in the main file — so moving to, say,
 * https://api.commission.org is a change to one setting and nothing else.
 *
 * ── The API, as observed ───────────────────────────────────────────────────
 *
 * GET /api/v1/search?name=&state=&degree=&next_reaffirm_date=
 *     The whole directory in one response: {"results":[ … ]}, 1,201 records,
 *     ~1.7 MB, ~2.5 s. All four parameters accept an empty value, which means
 *     "no filter". There is no pagination, no total, no metadata — the array
 *     is the entire payload.
 *
 * GET /api/v1/institution?sf_institution_id=…    one institution
 * GET /api/v1/sites?sf_institution_id=…          off-campus sites
 * GET /api/v1/recentmeetings?sf_institution_id=…
 * GET /api/v1/inprogressmeetings?sf_institution_id=…
 *
 * There is no endpoint for "institutions changed since X", and `updated_at`
 * cannot stand in for one (see includes/fields.php). So the sync downloads the
 * full dataset and compares locally — which is cheap, because the comparison
 * is a hash lookup and unchanged rows are never written.
 *
 * No endpoint requires authentication.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The configured API base URL, without a trailing slash.
 *
 * Falls back to the shipped default when the setting is empty or has been left
 * as something unusable, so a blank field can never turn into requests against
 * a relative URL.
 */
function sacscoc_inst_api_base_url(): string {
    $base = trim( (string) get_option( 'sacscoc_inst_api_base_url', '' ) );

    if ( $base === '' || ! preg_match( '#^https?://#i', $base ) ) {
        $base = SACSCOC_INST_DEFAULT_API_BASE;
    }

    return untrailingslashit( $base );
}

/** How long to wait for the API, in seconds. The full search takes ~2.5 s. */
function sacscoc_inst_api_timeout(): int {
    $timeout = (int) get_option( 'sacscoc_inst_api_timeout', 60 );
    return $timeout >= 5 && $timeout <= 300 ? $timeout : 60;
}

/**
 * GET one API path and return its decoded `results` array.
 *
 * Returns a WP_Error for anything that is not a usable response — a transport
 * failure, a non-200 status, a body that is not JSON, or JSON without the
 * `results` array the API always sends. The caller is expected to treat every
 * one of those as "no data this time", never as "the directory is empty";
 * that distinction is enforced in includes/sync.php.
 *
 * @param string $path  e.g. '/api/v1/search'
 * @param array  $args  query parameters, added verbatim
 * @return array|WP_Error
 */
function sacscoc_inst_api_get( string $path, array $args = [] ) {
    $url = sacscoc_inst_api_base_url() . '/' . ltrim( $path, '/' );

    // Built by hand rather than with add_query_arg(), which drops the "=" from
    // a parameter whose value is empty — turning `?name=&state=` into
    // `?name&state`. The API happens to accept both, but an empty value is a
    // meaningful part of its contract ("no filter"), so the request it receives
    // should be the one the endpoint documents.
    if ( $args ) {
        $pairs = [];
        foreach ( $args as $key => $value ) {
            $pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
        }
        $url .= ( str_contains( $url, '?' ) ? '&' : '?' ) . implode( '&', $pairs );
    }

    $response = wp_remote_get( $url, [
        'timeout'     => sacscoc_inst_api_timeout(),
        'redirection' => 3,
        'headers'     => [ 'Accept' => 'application/json' ],
        'user-agent'  => 'SACSCOC Institutions/' . SACSCOC_INST_VERSION . '; ' . home_url( '/' ),
    ] );

    if ( is_wp_error( $response ) ) {
        return new WP_Error(
            'sacscoc_inst_transport',
            sprintf(
                /* translators: 1: API URL, 2: error from the HTTP layer */
                __( 'Could not reach the API at %1$s: %2$s', 'sacscoc-institutions' ),
                $url,
                $response->get_error_message()
            )
        );
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    if ( $code !== 200 ) {
        return new WP_Error(
            'sacscoc_inst_http_status',
            sprintf(
                /* translators: 1: HTTP status code, 2: API URL */
                __( 'The API returned HTTP %1$d for %2$s.', 'sacscoc-institutions' ),
                $code,
                $url
            )
        );
    }

    $body = wp_remote_retrieve_body( $response );
    if ( trim( $body ) === '' ) {
        return new WP_Error(
            'sacscoc_inst_empty_body',
            sprintf(
                /* translators: %s: API URL */
                __( 'The API returned an empty body for %s.', 'sacscoc-institutions' ),
                $url
            )
        );
    }

    $decoded = json_decode( $body, true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return new WP_Error(
            'sacscoc_inst_invalid_json',
            sprintf(
                /* translators: 1: API URL, 2: JSON error message */
                __( 'The API returned invalid JSON for %1$s: %2$s', 'sacscoc-institutions' ),
                $url,
                json_last_error_msg()
            )
        );
    }

    // Every endpoint wraps its payload in `results`. A response without it is a
    // response we do not understand, and guessing at it would be worse than
    // failing: a misread shape is how a directory gets silently emptied.
    if ( ! is_array( $decoded ) || ! array_key_exists( 'results', $decoded ) ) {
        return new WP_Error(
            'sacscoc_inst_unexpected_shape',
            sprintf(
                /* translators: %s: API URL */
                __( 'The API response for %s has no "results" array — the response format may have changed.', 'sacscoc-institutions' ),
                $url
            )
        );
    }

    // `results` is null rather than [] when an endpoint has nothing to return.
    $results = $decoded['results'];
    if ( $results === null ) return [];

    if ( ! is_array( $results ) ) {
        return new WP_Error(
            'sacscoc_inst_unexpected_shape',
            sprintf(
                /* translators: %s: API URL */
                __( 'The API returned a "results" value that is not a list for %s.', 'sacscoc-institutions' ),
                $url
            )
        );
    }

    return $results;
}

/**
 * The full institution directory.
 *
 * All four filter parameters are sent empty on purpose: this is the one request
 * that fetches everything, and the local copy is what gets filtered afterwards.
 *
 * @return array|WP_Error
 */
function sacscoc_inst_api_fetch_institutions() {
    return sacscoc_inst_api_get( '/api/v1/search', [
        'name'              => '',
        'state'             => '',
        'degree'            => '',
        'next_reaffirm_date' => '',
    ] );
}

/** Off-campus instructional sites for one institution. */
function sacscoc_inst_api_fetch_sites( string $sf_institution_id ) {
    return sacscoc_inst_api_get( '/api/v1/sites', [ 'sf_institution_id' => $sf_institution_id ] );
}

/** Completed reviews / meetings for one institution. */
function sacscoc_inst_api_fetch_recent_meetings( string $sf_institution_id ) {
    return sacscoc_inst_api_get( '/api/v1/recentmeetings', [ 'sf_institution_id' => $sf_institution_id ] );
}

/** Reviews currently under way for one institution. */
function sacscoc_inst_api_fetch_inprogress_meetings( string $sf_institution_id ) {
    return sacscoc_inst_api_get( '/api/v1/inprogressmeetings', [ 'sf_institution_id' => $sf_institution_id ] );
}

/**
 * A cheap connectivity check for the Settings and Sync screens.
 *
 * Asks for a single named institution rather than the whole directory, so
 * pressing "Test connection" costs a 1 KB response instead of 1.7 MB. The name
 * is one that has existed since 1929; a zero-result response still proves the
 * API answered, so the check does not depend on it being found.
 *
 * @return array{ok:bool,message:string}
 */
function sacscoc_inst_api_check(): array {
    $started = microtime( true );
    $results = sacscoc_inst_api_get( '/api/v1/search', [
        'name'              => 'Brenau',
        'state'             => '',
        'degree'            => '',
        'next_reaffirm_date' => '',
    ] );
    $ms = (int) round( ( microtime( true ) - $started ) * 1000 );

    if ( is_wp_error( $results ) ) {
        return [ 'ok' => false, 'message' => $results->get_error_message() ];
    }

    return [
        'ok'      => true,
        'message' => sprintf(
            /* translators: 1: response time in milliseconds */
            __( 'Connected — the API answered in %d ms.', 'sacscoc-institutions' ),
            $ms
        ),
    ];
}
