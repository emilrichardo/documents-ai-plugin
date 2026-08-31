<?php
/**
 * The search form: name, state, degree, next-reaffirmation-year, submit, reset.
 *
 * Available:
 *   $filters  array{q,state,degree,year,paged} the active filters
 *   $action   string the form's GET target
 *   $group    string pairs this form with a directory rendered separately —
 *             see sacscoc_inst_clean_group()
 *   $stacked  bool   true renders the single-bar shape instead of the ordinary
 *             panel — for the directory's own inline form, true exactly when
 *             the overall page layout is one-column; for a standalone form,
 *             true exactly when its own Layout control (shortcode's `layout`
 *             attribute, or the Institutions Search block's Inspector
 *             Control) is set to "horizontal". Adds `.sacscoc-search--stacked`
 *             to the form itself, which is what the CSS keys off — see the
 *             "one-column layout" section of assets/css/sacscoc-institutions.css.
 *   $heading  string the panel's own heading; empty means the built-in
 *             "Institution Search". Set from the shortcode's `heading`
 *             attribute or the Institutions Search block's own Inspector
 *             Control — a page that wants the panel to say something else
 *             ("Find Your School") does not have to override this template.
 *   $locked   string[] filter keys ('state','degree','year') the directory
 *             this form belongs to always restricts results to. Dropped from
 *             the form entirely — see $locked in templates/directory.php.
 *             Always empty for the standalone [sacscoc_institutions_search] /
 *             Institutions Search block, which has no directory of its own to
 *             restrict.
 *
 * A single, self-contained <form>: heading, fields, submit, reset and the
 * spinner are all inside it, nothing outside is assumed. That is what makes it
 * safe to render on its own, which is exactly what happens twice:
 *
 *   - templates/directory.php includes this file for [sacscoc_institutions]'s
 *     own inline form, inside its <aside>.
 *   - [sacscoc_institutions_search] includes it directly, with no <aside> and
 *     no surrounding directory markup, for a form placed somewhere that
 *     shortcode's own layout cannot reach — a custom block, a sidebar, a
 *     template part.
 *
 * Both render this exact file, so the two can never drift apart. What differs
 * between them is entirely outside this file: `$action` (this page for the
 * inline form, Settings → Directory Page for the standalone one) and the
 * wrapper the standalone shortcode puts around it for styling. Neither
 * shortcode nor this file has to know which case it is in.
 *
 * assets/js/directory.js finds this form by `data-sacscoc-form` and pairs it
 * with a directory by `data-sacscoc-group` — nested inside it when there is
 * one, elsewhere on the page sharing the same group when there is not. Nothing
 * here talks to that directory directly.
 *
 * Override by copying this file to `sacscoc-institutions/search-form.php` in
 * the theme.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** @var array  $filters */
/** @var string $action */
/** @var string $group */
/** @var bool   $stacked */
/** @var string $heading */
/** @var array  $locked */

$locked  = (array) ( $locked ?? [] );
$active  = sacscoc_inst_has_filters( $filters );
$heading = trim( (string) ( $heading ?? '' ) );
$heading = $heading !== '' ? $heading : __( 'Institution Search', 'sacscoc-institutions' );

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
<form class="sacscoc-block sacscoc-search<?php echo $stacked ? ' sacscoc-search--stacked' : ''; ?>" method="get"
      action="<?php echo esc_url( $action ); ?>" role="search"
      data-sacscoc-form data-sacscoc-group="<?php echo esc_attr( $group ); ?>">
    <h2 class="sacscoc-block__heading">
        <?php echo sacscoc_inst_icon( 'search', 'sacscoc-icon--heading' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
        <span><?php echo esc_html( $heading ); ?></span>
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

        <?php if ( ! in_array( 'state', $locked, true ) ) : ?>
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
        <?php endif; ?>

        <?php if ( ! in_array( 'degree', $locked, true ) ) : ?>
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
        <?php endif; ?>

        <?php if ( ! in_array( 'year', $locked, true ) ) : ?>
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
        <?php endif; ?>
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
