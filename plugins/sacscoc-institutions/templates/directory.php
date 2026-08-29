<?php
/**
 * The institutions directory: search panel and results.
 *
 * Available:
 *   $results     array{rows,total,pages,paged,per_page} from sacscoc_inst_search()
 *   $filters     array{q,state,degree,year,paged} the active filters
 *   $show_count  bool
 *
 * Structure follows the existing sacscoc.org/institutions/ directory: search
 * panel and results side by side, search on the right at a third of the width,
 * results on the left. Source order puts the search first so keyboard and
 * screen-reader users reach it before a screenful of results; the visual swap is
 * done in CSS, which is also why it collapses to search-then-results on narrow
 * screens with no markup change.
 *
 * ── Progressive enhancement ────────────────────────────────────────────────
 *
 * This is a plain GET form and works completely without JavaScript: submitting
 * it reloads the page with the filters in the query string. assets/js/directory.js
 * then upgrades it in place — filters apply as you type or change a select, and
 * only the results region is re-rendered. Nothing here depends on that script
 * having loaded, and every clear button is a real link with a working href.
 *
 * Override by copying this file to `sacscoc-institutions/directory.php` in the
 * theme.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** @var array  $results */
/** @var array  $filters */
/** @var string $layout */
/** @var int    $per_page */
/** @var bool   $show_count */

$rows   = $results['rows'];
$action = sacscoc_inst_directory_url();
$active = sacscoc_inst_has_filters( $filters );

// One template, two layouts: the difference is a class. `--stacked` puts the
// search in a bar across the top with the results full width beneath, which is
// what the site's own Find an Institution page does; the default keeps the
// results left and the search panel right, as sacscoc.org has it.
$stacked = ( $layout ?? 'two-column' ) === 'one-column';

/**
 * The × that clears one filter.
 *
 * A real link to the same results without that filter, so it works with
 * JavaScript off; the script intercepts it. Rendered only when the filter has a
 * value, so there is never a control that does nothing.
 */
$clear = static function ( array $filters, string $key, string $label ): void {
    $url = sacscoc_inst_filter_url( $filters, [ $key => '', 'paged' => 1 ] );
    printf(
        '<a class="sacscoc-field__clear" href="%s" data-sacscoc-clear="%s" aria-label="%s" title="%s">&times;</a>',
        esc_url( $url ),
        esc_attr( $key ),
        esc_attr( $label ),
        esc_attr( $label )
    );
};
?>
<?php
// per-page and show-count travel with the markup: the live filter posts them
// back, so a page listing 50 keeps listing 50 after the first keystroke.
?>
<div class="sacscoc-directory<?php echo $stacked ? ' sacscoc-directory--stacked' : ''; ?>" id="sacscoc-directory"
     data-sacscoc-directory
     data-action="<?php echo esc_url( $action ); ?>"
     data-per-page="<?php echo esc_attr( (string) $per_page ); ?>"
     data-show-count="<?php echo $show_count ? 'yes' : 'no'; ?>">
    <div class="sacscoc-layout<?php echo $stacked ? ' sacscoc-layout--stacked' : ''; ?><?php echo $rows || $stacked ? '' : ' sacscoc-layout--solo'; ?>" data-sacscoc-layout>

        <aside class="sacscoc-layout__aside">
            <form class="sacscoc-block sacscoc-search" method="get"
                  action="<?php echo esc_url( $action ); ?>" role="search"
                  data-sacscoc-form>
                <h2 class="sacscoc-block__heading">
                    <?php echo sacscoc_inst_icon( 'search', 'sacscoc-icon--heading' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <span><?php esc_html_e( 'Institution Search', 'sacscoc-institutions' ); ?></span>
                </h2>

                <div class="sacscoc-search__fields">
                    <p class="sacscoc-field">
                        <label class="sacscoc-field__label" for="si_q"><?php esc_html_e( 'Institution Name', 'sacscoc-institutions' ); ?></label>
                        <span class="sacscoc-field__control">
                            <input class="sacscoc-control" type="search" id="si_q" name="si_q"
                                   value="<?php echo esc_attr( $filters['q'] ); ?>"
                                   autocomplete="off"
                                   placeholder="<?php esc_attr_e( 'Search…', 'sacscoc-institutions' ); ?>" />
                            <?php if ( $filters['q'] !== '' ) {
                                $clear( $filters, 'q', __( 'Clear the name search', 'sacscoc-institutions' ) );
                            } ?>
                        </span>
                    </p>

                    <p class="sacscoc-field">
                        <label class="sacscoc-field__label" for="si_state"><?php esc_html_e( 'State', 'sacscoc-institutions' ); ?></label>
                        <span class="sacscoc-field__control">
                            <select class="sacscoc-control sacscoc-control--select" id="si_state" name="si_state">
                                <option value=""><?php esc_html_e( 'Any State', 'sacscoc-institutions' ); ?></option>
                                <?php foreach ( sacscoc_inst_states() as $code => $label ) : ?>
                                    <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $filters['state'], $code ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ( $filters['state'] !== '' ) {
                                $clear( $filters, 'state', __( 'Clear the state filter', 'sacscoc-institutions' ) );
                            } ?>
                        </span>
                    </p>

                    <p class="sacscoc-field">
                        <label class="sacscoc-field__label" for="si_degree"><?php esc_html_e( 'Highest Degree Offered', 'sacscoc-institutions' ); ?></label>
                        <span class="sacscoc-field__control">
                            <select class="sacscoc-control sacscoc-control--select" id="si_degree" name="si_degree">
                                <option value=""><?php esc_html_e( 'Any Degree', 'sacscoc-institutions' ); ?></option>
                                <?php
                                // Lowest to highest, the order the current site lists them in.
                                foreach ( array_reverse( sacscoc_inst_degrees(), true ) as $key => $label ) : ?>
                                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['degree'], $key ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ( $filters['degree'] !== '' ) {
                                $clear( $filters, 'degree', __( 'Clear the degree filter', 'sacscoc-institutions' ) );
                            } ?>
                        </span>
                    </p>

                    <p class="sacscoc-field">
                        <label class="sacscoc-field__label" for="si_year"><?php esc_html_e( 'Next Reaffirmation Year', 'sacscoc-institutions' ); ?></label>
                        <span class="sacscoc-field__control">
                            <select class="sacscoc-control sacscoc-control--select" id="si_year" name="si_year">
                                <option value=""><?php esc_html_e( 'Any Year', 'sacscoc-institutions' ); ?></option>
                                <?php foreach ( sacscoc_inst_reaffirm_years() as $year ) : ?>
                                    <option value="<?php echo esc_attr( (string) $year ); ?>" <?php selected( $filters['year'], (string) $year ); ?>>
                                        <?php echo esc_html( (string) $year ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ( $filters['year'] !== '' ) {
                                $clear( $filters, 'year', __( 'Clear the year filter', 'sacscoc-institutions' ) );
                            } ?>
                        </span>
                    </p>
                </div>

                <p class="sacscoc-search__actions">
                    <?php // Hidden once the script takes over — filtering is live by then. ?>
                    <button type="submit" class="sacscoc-btn" data-sacscoc-submit>
                        <?php echo sacscoc_inst_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <?php esc_html_e( 'Search', 'sacscoc-institutions' ); ?>
                    </button>

                    <a class="sacscoc-plus-link sacscoc-reset<?php echo $active ? '' : ' is-hidden'; ?>"
                       href="<?php echo esc_url( $action ); ?>" data-sacscoc-reset>
                        <?php echo sacscoc_inst_icon( 'reset' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <?php esc_html_e( 'Reset filters', 'sacscoc-institutions' ); ?>
                    </a>

                    <span class="sacscoc-spinner" data-sacscoc-spinner aria-hidden="true"></span>
                </p>
            </form>
        </aside>

        <?php // Always rendered: with no results this column is what says so. ?>
        <div class="sacscoc-layout__main" data-sacscoc-main>
            <div class="sacscoc-results-region" data-sacscoc-results aria-live="polite" aria-busy="false">
                <?php
                sacscoc_inst_load_template( 'results.php', [
                    'results'    => $results,
                    'filters'    => $filters,
                    'show_count' => $show_count,
                ] );
                ?>
            </div>
        </div>

    </div>
</div>
