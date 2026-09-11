<?php
/**
 * The results half of the directory — or, in 'on-search' mode, a compact
 * dropdown of matches meant to float under a search field rather than take
 * a place on the page.
 *
 * Available:
 *   $results     array{rows,total,pages,paged,per_page} from sacscoc_inst_search()
 *   $filters     array{q,state,degree,year,paged} the active filters
 *   $show_count  bool — ignored in 'on-search' mode; a floating dropdown has
 *                no room for a tally and nowhere it would sit
 *   $results_mode string 'always' or 'on-search'; see
 *                sacscoc_inst_clean_results_mode()
 *   $heading     string the list's own heading; empty means the built-in
 *                "Results". Set from the shortcode's `results_heading`
 *                attribute or the Institutions Directory block's own
 *                Inspector Control, and carried across every live filter as
 *                `data-results-heading` on the directory's wrapper — without
 *                that, a customised heading would revert to "Results" the
 *                moment someone typed into the search box. Ignored in
 *                'on-search' mode, which has no heading to replace.
 *
 * Split out from directory.php so that the first page load and every live
 * filter afterwards render from the same file — the AJAX endpoint returns
 * exactly this markup. If the two diverged, filtering would quietly restyle the
 * page.
 *
 * Override by copying this file to `sacscoc-institutions/results.php` in the
 * theme.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** @var array  $results */
/** @var array  $filters */
/** @var bool   $show_count */
/** @var string $results_mode */
/** @var string $heading */

$results_mode = (string) ( $results_mode ?? 'always' );

// ── The dropdown ──────────────────────────────────────────────────────────
// A different shape entirely, not a smaller version of the block below: no
// heading, no count, no pagination — floating under a search field is the
// whole point, and none of those make sense there. See templates/directory.php
// for how this gets positioned, and the "Typeahead / on-search dropdown"
// section of assets/css/sacscoc-institutions.css for how it is hidden and
// shown.
if ( $results_mode === 'on-search' ) {
    $rows = $results['rows'];

    // Nothing asked yet. Printing nothing at all — not even an invitation —
    // is deliberate: a dropdown with a prompt sitting open under an untouched
    // navbar search is exactly the "why is there a box floating here" a
    // typeahead is supposed to avoid. The panel itself is hidden by the same
    // signal (no active filter) on the CSS/JS side, so this and that are one
    // decision made twice, not two different ones.
    if ( ! sacscoc_inst_has_filters( $filters ) ) return;

    if ( ! $rows ) :
        ?>
        <p class="sacscoc-typeahead__empty">
            <?php esc_html_e( 'No institutions match that search.', 'sacscoc-institutions' ); ?>
        </p>
        <?php
        return;
    endif;

    $more = max( 0, (int) $results['total'] - count( $rows ) );
    ?>
    <ul class="sacscoc-typeahead__list">
        <?php foreach ( $rows as $row ) :
            $name  = sacscoc_inst_display_name( $row );
            $where = trim( implode( ', ', array_filter( [
                (string) ( $row['address_city']  ?? '' ),
                (string) ( $row['address_state'] ?? '' ),
            ] ) ) );
            ?>
            <li class="sacscoc-typeahead__item">
                <a class="sacscoc-typeahead__link" href="<?php echo esc_url( sacscoc_inst_permalink( $row ) ); ?>">
                    <span class="sacscoc-typeahead__text">
                        <span class="sacscoc-typeahead__name"><?php echo esc_html( $name ); ?></span>
                        <?php if ( $where !== '' ) : ?>
                            <span class="sacscoc-typeahead__meta"><?php echo esc_html( $where ); ?></span>
                        <?php endif; ?>
                    </span>
                    <?php echo sacscoc_inst_icon( 'chevron-right', 'sacscoc-typeahead__chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if ( $more > 0 ) : ?>
        <p class="sacscoc-typeahead__more">
            <?php
            printf(
                /* translators: %s: how many more institutions match, beyond the ones shown above */
                esc_html__( '+ %s more — narrow your search to see them', 'sacscoc-institutions' ),
                esc_html( number_format_i18n( $more ) )
            );
            ?>
        </p>
    <?php endif; ?>
    <?php
    return;
}

// ── The ordinary results block ───────────────────────────────────────────
$rows    = $results['rows'];
$heading = trim( (string) ( $heading ?? '' ) );
$heading = $heading !== '' ? $heading : __( 'Results', 'sacscoc-institutions' );

if ( ! $rows ) :
    ?>
    <p class="sacscoc-empty">
        <?php echo sacscoc_inst_icon( 'no-results', 'sacscoc-icon--empty' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
        <?php esc_html_e( 'No results found matching that search, please try again.', 'sacscoc-institutions' ); ?>
    </p>
    <?php
    return;
endif;
?>
<div class="sacscoc-block sacscoc-results">
    <h2 class="sacscoc-block__heading">
        <?php echo sacscoc_inst_icon( 'results', 'sacscoc-icon--heading' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
        <span><?php echo esc_html( $heading ); ?></span>
    </h2>

    <?php // Instruction and tally share a line: one is guidance, the other is context. ?>
    <div class="sacscoc-results__meta">
        <p class="sacscoc-results__hint">
            <?php esc_html_e( 'Click an institution’s name for its full record.', 'sacscoc-institutions' ); ?>
        </p>

        <?php if ( $show_count ) : ?>
            <p class="sacscoc-results__count">
                <?php
                printf(
                    /* translators: 1: first result number, 2: last result number, 3: total results */
                    esc_html__( 'Showing %1$s–%2$s of %3$s institutions', 'sacscoc-institutions' ),
                    esc_html( number_format_i18n( ( $results['paged'] - 1 ) * $results['per_page'] + 1 ) ),
                    esc_html( number_format_i18n( min( $results['total'], $results['paged'] * $results['per_page'] ) ) ),
                    esc_html( number_format_i18n( $results['total'] ) )
                );
                ?>
            </p>
        <?php endif; ?>
    </div>

    <ul class="sacscoc-list">
        <?php foreach ( $rows as $row ) :
            $name     = sacscoc_inst_display_name( $row );
            $sanction = sacscoc_inst_sanction( $row );
            $level    = sacscoc_inst_parse_text( $row['level'] );
            $tip      = $level !== null ? ( sacscoc_inst_level_tooltips()[ $level ] ?? null ) : null;
            ?>
            <li class="sacscoc-result">
                <a class="sacscoc-result__hit" href="<?php echo esc_url( sacscoc_inst_permalink( $row ) ); ?>">
                    <span class="screen-reader-text"><?php echo esc_html( $name ); ?></span>
                </a>

                <div class="sacscoc-result__identity">
                    <p class="sacscoc-result__name"><?php echo esc_html( $name ); ?></p>

                    <?php if ( $row['former_names'] ) : ?>
                        <p class="sacscoc-result__former">
                            <?php
                            printf(
                                /* translators: %s: the institution's former name(s) */
                                esc_html__( 'Former Name: %s', 'sacscoc-institutions' ),
                                esc_html( $row['former_names'] )
                            );
                            ?>
                        </p>
                    <?php endif; ?>

                    <?php if ( $row['website'] ) : ?>
                        <a class="sacscoc-plus-link sacscoc-result__site"
                           href="<?php echo esc_url( $row['website'] ); ?>"
                           target="_blank" rel="noopener noreferrer">
                            <?php echo sacscoc_inst_icon( 'external' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            <?php esc_html_e( 'View Website', 'sacscoc-institutions' ); ?>
                        </a>
                    <?php endif; ?>

                    <?php if ( $sanction !== null ) : ?>
                        <p class="sacscoc-result__sanction sacscoc-error">
                            <strong><?php esc_html_e( 'Public Sanctions:', 'sacscoc-institutions' ); ?></strong>
                            <?php echo esc_html( $sanction ); ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="sacscoc-result__facts">
                    <dl class="sacscoc-kv">
                        <?php if ( $row['address_city'] ) : ?>
                            <div><dt><?php esc_html_e( 'City', 'sacscoc-institutions' ); ?></dt>
                                <dd><?php echo esc_html( $row['address_city'] ); ?></dd></div>
                        <?php endif; ?>
                        <?php if ( $row['address_state'] ) : ?>
                            <div><dt><?php esc_html_e( 'State', 'sacscoc-institutions' ); ?></dt>
                                <dd><?php echo esc_html( $row['address_state'] ); ?></dd></div>
                        <?php endif; ?>
                        <?php if ( $row['address_zip'] ) : ?>
                            <div><dt><?php esc_html_e( 'ZIP', 'sacscoc-institutions' ); ?></dt>
                                <dd><?php echo esc_html( $row['address_zip'] ); ?></dd></div>
                        <?php endif; ?>
                    </dl>

                    <dl class="sacscoc-kv">
                        <?php if ( $row['address_country'] ) : ?>
                            <div><dt><?php esc_html_e( 'Country', 'sacscoc-institutions' ); ?></dt>
                                <dd><?php echo esc_html( $row['address_country'] ); ?></dd></div>
                        <?php endif; ?>
                        <?php if ( $row['accreditation_status'] ) : ?>
                            <div><dt><?php esc_html_e( 'Status', 'sacscoc-institutions' ); ?></dt>
                                <dd><?php echo esc_html( $row['accreditation_status'] ); ?></dd></div>
                        <?php endif; ?>
                        <?php if ( $level !== null ) : ?>
                            <div><dt><?php esc_html_e( 'Level', 'sacscoc-institutions' ); ?></dt>
                                <dd>
                                    <?php echo esc_html( $level ); ?>
                                    <?php if ( $tip ) : ?>
                                        <span class="sacscoc-hint" tabindex="0" role="note"
                                              aria-label="<?php echo esc_attr( $tip ); ?>"
                                        >i<span class="sacscoc-hint__bubble" aria-hidden="true"><?php echo esc_html( $tip ); ?></span></span>
                                    <?php endif; ?>
                                </dd></div>
                        <?php endif; ?>
                    </dl>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<?php if ( $results['pages'] > 1 ) : ?>
    <nav class="sacscoc-pagination" aria-label="<?php esc_attr_e( 'Results pages', 'sacscoc-institutions' ); ?>">
        <?php
        echo wp_kses_post( paginate_links( [
            'base'      => str_replace( '__PAGE__', '%#%', sacscoc_inst_filter_url( $filters, [ 'paged' => '__PAGE__' ] ) ),
            'format'    => '',
            'current'   => $results['paged'],
            'total'     => $results['pages'],
            'prev_text' => __( 'Previous', 'sacscoc-institutions' ),
            'next_text' => __( 'Next', 'sacscoc-institutions' ),
            'mid_size'  => 2,
        ] ) );
        ?>
    </nav>
<?php endif; ?>
