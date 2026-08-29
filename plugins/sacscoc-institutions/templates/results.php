<?php
/**
 * The results half of the directory: the list and its pagination.
 *
 * Available:
 *   $results     array{rows,total,pages,paged,per_page} from sacscoc_inst_search()
 *   $filters     array{q,state,degree,year,paged} the active filters
 *   $show_count  bool
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

/** @var array $results */
/** @var array $filters */
/** @var bool  $show_count */

$rows = $results['rows'];

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
        <span><?php esc_html_e( 'Results', 'sacscoc-institutions' ); ?></span>
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
                                        <span class="sacscoc-hint" tabindex="0"
                                              aria-label="<?php echo esc_attr( $tip ); ?>"
                                              title="<?php echo esc_attr( $tip ); ?>">i</span>
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
