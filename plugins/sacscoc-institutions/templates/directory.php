<?php
/**
 * The institutions directory: search panel and results.
 *
 * Available:
 *   $results     array{rows,total,pages,paged,per_page} from sacscoc_inst_search()
 *   $filters     array{q,state,degree,year,paged} the active filters
 *   $show_count  bool
 *   $show_search bool — false when the search form is rendered separately by
 *                [sacscoc_institutions_search] instead of inline here
 *   $group       string — pairs this directory with that separate form; see
 *                sacscoc_inst_clean_group()
 *   $search_heading  string — replaces "Institution Search" above the inline
 *                form; passed straight through to search-form.php
 *   $results_heading string — replaces "Results" above the list; passed
 *                straight through to results.php, and carried across every
 *                live filter as `data-results-heading` on the wrapper below
 *   $locked      string[] — filter keys ('state','degree','year') this
 *                directory always restricts results to, from the block's own
 *                Inspector Controls or the shortcode's filter_* attributes.
 *                Each one is dropped from the inline form: a field a visitor
 *                could change without it doing anything is worse than no
 *                field at all. See sacscoc_inst_render_directory().
 *
 * Structure follows the existing sacscoc.org/institutions/ directory: search
 * panel and results side by side, search on the right at a third of the width,
 * results on the left. Source order puts the search first so keyboard and
 * screen-reader users reach it before a screenful of results; the visual swap is
 * done in CSS, which is also why it collapses to search-then-results on narrow
 * screens with no markup change.
 *
 * The form itself is templates/search-form.php, included below and also by
 * [sacscoc_institutions_search] directly when `$show_search` is false — the
 * same file either way, so the two can never render different markup for what
 * is meant to be the same form.
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
/** @var bool   $show_search */
/** @var string $group */
/** @var string $search_heading */
/** @var string $results_heading */
/** @var array  $locked */

$rows   = $results['rows'];
$action = sacscoc_inst_directory_url();

$show_search     = $show_search ?? true;
$group           = $group ?? 'default';
$search_heading  = (string) ( $search_heading ?? '' );
$results_heading = (string) ( $results_heading ?? '' );
$locked          = (array) ( $locked ?? [] );

// One template, two layouts: the difference is a class. `--stacked` puts the
// search in a bar across the top with the results full width beneath, which is
// what the site's own Find an Institution page does; the default keeps the
// results left and the search panel right, as sacscoc.org has it. Meaningless
// with no inline search to arrange, so it never applies when $show_search is
// false — a stray `layout="one-column"` on a search-less shortcode does nothing.
$stacked = $show_search && ( ( $layout ?? 'two-column' ) === 'one-column' );

$layout_classes = [ 'sacscoc-layout' ];
if ( $stacked ) {
    $layout_classes[] = 'sacscoc-layout--stacked';
} elseif ( ! $show_search ) {
    // No <aside> is rendered at all below, so the grid's second column has to
    // be told to go away rather than sit there empty.
    $layout_classes[] = 'sacscoc-layout--no-search';
} elseif ( ! $rows ) {
    $layout_classes[] = 'sacscoc-layout--solo';
}
?>
<?php
// per-page, show-count and group travel with the markup: the live filter posts
// them back, so a page listing 50 keeps listing 50 after the first keystroke,
// and a directory paired with a separate search form stays paired with it.
?>
<div class="sacscoc-directory sacscoc-contain-width<?php echo $stacked ? ' sacscoc-directory--stacked' : ''; ?>" id="sacscoc-directory"
     data-sacscoc-directory
     data-sacscoc-group="<?php echo esc_attr( $group ); ?>"
     data-action="<?php echo esc_url( $action ); ?>"
     data-per-page="<?php echo esc_attr( (string) $per_page ); ?>"
     data-show-count="<?php echo $show_count ? 'yes' : 'no'; ?>"
     <?php if ( $results_heading !== '' ) : ?>data-results-heading="<?php echo esc_attr( $results_heading ); ?>"<?php endif; ?>>
    <div class="<?php echo esc_attr( implode( ' ', $layout_classes ) ); ?>" data-sacscoc-layout>

        <?php if ( $show_search ) : ?>
            <aside class="sacscoc-layout__aside">
                <?php
                sacscoc_inst_load_template( 'search-form.php', [
                    'filters' => $filters,
                    'action'  => $action,
                    'group'   => $group,
                    'stacked' => $stacked,
                    'heading' => $search_heading,
                    'locked'  => $locked,
                ] );
                ?>
            </aside>
        <?php endif; ?>

        <?php // Always rendered: with no results this column is what says so. ?>
        <div class="sacscoc-layout__main" data-sacscoc-main>
            <div class="sacscoc-results-region" data-sacscoc-results aria-live="polite" aria-busy="false">
                <?php
                sacscoc_inst_load_template( 'results.php', [
                    'results'    => $results,
                    'filters'    => $filters,
                    'show_count' => $show_count,
                    'heading'    => $results_heading,
                ] );
                ?>
            </div>
        </div>

    </div>
</div>
