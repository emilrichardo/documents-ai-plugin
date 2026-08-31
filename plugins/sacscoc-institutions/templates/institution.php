<?php
/**
 * One institution's record — the content of the page, without the page shell.
 *
 * Available:
 *   $institution  array — a full row from the institutions table
 *   $embedded     bool  — rendered inside another page by [sacscoc_institution]
 *   $show_back    bool  — print the "Back to Results" button
 *   $show_about   bool  — print the shared About SACSCOC block
 *
 * The three flags only ever arrive from the embed shortcode. The institution
 * page passes none of them, so their defaults below are what that page has
 * always done: not embedded, back button, About block.
 *
 * Section order, headings and wording follow the existing
 * sacscoc.org/institutions/ detail view: the identity block, General
 * Information in two columns, Accreditation Information in two columns with the
 * collapsible history, the SACSCOC staff member, and About SACSCOC at the foot.
 *
 * The conditions that hide things are the current site's, not invented: the
 * staff block is hidden for the three "Former …" statuses, the student
 * achievement link needs Accredited or Candidate, "No Sanction" reads as no
 * sanction, and the reaffirmation fields show a year while the rest show a full
 * date.
 *
 * Off-campus Instructional Sites and the two review/meeting sections (matching
 * production's "In-Progress Reviews" and "Most Recent History with SACSCOC")
 * come from the related-data sync (includes/sync.php,
 * sacscoc_inst_sync_related_batch()) rather than the main institutions sync, so
 * they can be empty for an institution whose related data has not been synced
 * yet, or that genuinely has none — each section is hidden entirely when there
 * is nothing to show, same as every other conditional block on this page.
 *
 * Override by copying this file to `sacscoc-institutions/institution.php` in
 * the theme.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** @var array $institution */
/** @var bool  $embedded */
/** @var bool  $show_back */
/** @var bool  $show_about */
$row = $institution;

$embedded   = $embedded ?? false;
$show_back  = $show_back ?? true;
$show_about = $show_about ?? true;

$name      = sacscoc_inst_display_name( $row );
$sanction  = sacscoc_inst_sanction( $row );
$level     = sacscoc_inst_parse_text( $row['level'] );
$level_tip = $level !== null ? ( sacscoc_inst_level_tooltips()[ $level ] ?? null ) : null;
$approved  = sacscoc_inst_approved_degrees( $row );
$history   = sacscoc_inst_history_lines( $row );
$footer    = sacscoc_inst_footer_content();

$sf_id      = sacscoc_inst_parse_text( $row['sf_id'] );
$sites      = $sf_id !== null ? sacscoc_inst_sites_for_institution( $sf_id ) : [];
$inprogress = $sf_id !== null ? sacscoc_inst_meetings_for_institution( $sf_id, 'inprogress' ) : [];
$recent     = $sf_id !== null ? sacscoc_inst_meetings_for_institution( $sf_id, 'recent' ) : [];

/**
 * One label-and-value pair, skipped entirely when there is no value.
 *
 * The icon is decorative — the label beside it already names the field — so an
 * unknown icon name simply returns nothing and the row still reads correctly.
 */
$fact = static function ( string $label, ?string $value, string $icon = '' ): void {
    if ( $value === null || $value === '' ) return;
    printf(
        '<dl class="sacscoc-plus-list"><dt>%s%s</dt><dd>%s</dd></dl>',
        sacscoc_inst_icon( $icon ),
        esc_html( $label ),
        esc_html( $value )
    );
};
?>
<article class="sacscoc-single<?php echo $embedded ? ' sacscoc-single--embedded' : ''; ?>">

    <?php if ( $show_back ) : ?>
    <p class="sacscoc-back">
        <a class="sacscoc-btn sacscoc-btn--back" href="<?php echo esc_url( sacscoc_inst_directory_url() ); ?>">
            <?php echo sacscoc_inst_icon( 'back' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <?php esc_html_e( 'Back to Results', 'sacscoc-institutions' ); ?>
        </a>
    </p>
    <?php endif; ?>

    <!-- ── Identity ── -->
    <section class="sacscoc-block">
        <h1 class="sacscoc-block__heading sacscoc-block__heading--title">
            <span><?php echo esc_html( $name ); ?></span>
        </h1>

        <p class="sacscoc-asof">
            <em><?php esc_html_e( 'As of', 'sacscoc-institutions' ); ?></em>
            <?php echo esc_html( wp_date( get_option( 'date_format' ) ) ); ?>
            <?php if ( $row['former_names'] ) : ?>
                <br /><em class="sacscoc-former">
                    <?php
                    printf(
                        /* translators: %s: the institution's former name(s) */
                        esc_html__( 'Former Name: %s', 'sacscoc-institutions' ),
                        esc_html( $row['former_names'] )
                    );
                    ?>
                </em>
            <?php endif; ?>
        </p>

        <p class="sacscoc-intro">
            <?php esc_html_e( 'The information on this page describes the accreditation relationship between this institution and the Southern Association of Colleges and Schools Commission on Colleges. General information about the Commission and the accreditation process is provided at the end of this document.', 'sacscoc-institutions' ); ?>
        </p>

        <h2 class="sacscoc-block__subheading">
                    <?php echo sacscoc_inst_icon( 'building', 'sacscoc-icon--heading' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <?php esc_html_e( 'General Information', 'sacscoc-institutions' ); ?>
                </h2>

        <div class="sacscoc-cols">
            <div class="sacscoc-col">
                <?php $fact( __( 'CEO Name', 'sacscoc-institutions' ), sacscoc_inst_parse_text( $row['ceo_name'] ), 'user' ); ?>

                <?php
                $street = sacscoc_inst_parse_text( $row['address_street'] );
                $locality = trim( implode( ', ', array_filter( [
                    sacscoc_inst_parse_text( $row['address_city'] ),
                    trim( ( (string) $row['address_state'] ) . ' ' . ( (string) $row['address_zip'] ) ),
                ] ) ), ', ' );
                ?>
                <?php if ( $street !== null || $locality !== '' ) : ?>
                    <dl class="sacscoc-plus-list">
                        <dt><?php echo sacscoc_inst_icon( 'pin' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Address', 'sacscoc-institutions' ); ?></dt>
                        <dd><address>
                            <?php if ( $street !== null ) : ?>
                                <span><?php echo esc_html( $street ); ?></span>
                            <?php endif; ?>
                            <?php if ( $locality !== '' ) : ?>
                                <span><?php echo esc_html( $locality ); ?></span>
                            <?php endif; ?>
                        </address></dd>
                    </dl>
                <?php endif; ?>

                <?php $fact( __( 'Country', 'sacscoc-institutions' ), sacscoc_inst_parse_text( $row['address_country'] ), 'globe' ); ?>
                <?php $fact( __( 'Institutional Phone', 'sacscoc-institutions' ), sacscoc_inst_parse_text( $row['phone'] ), 'phone' ); ?>
            </div>

            <div class="sacscoc-col">
                <?php if ( $approved ) : ?>
                    <dl class="sacscoc-plus-list">
                        <dt><?php echo sacscoc_inst_icon( 'degrees' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Approved to Offer', 'sacscoc-institutions' ); ?></dt>
                        <dd>
                            <?php foreach ( $approved as $label ) : ?>
                                <span><?php echo esc_html( $label ); ?></span>
                            <?php endforeach; ?>
                        </dd>
                    </dl>
                <?php endif; ?>

                <p class="sacscoc-links">
                    <?php if ( $row['website'] ) : ?>
                        <a class="sacscoc-plus-link" href="<?php echo esc_url( $row['website'] ); ?>"
                           target="_blank" rel="noopener noreferrer">
                            <?php echo sacscoc_inst_icon( 'external' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Visit Website', 'sacscoc-institutions' ); ?></a>
                    <?php endif; ?>

                    <?php if ( $row['program_list'] ) : ?>
                        <a class="sacscoc-plus-link" href="<?php echo esc_url( $row['program_list'] ); ?>"
                           target="_blank" rel="noopener noreferrer">
                            <?php echo sacscoc_inst_icon( 'programs' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'View Available Programs', 'sacscoc-institutions' ); ?></a>
                    <?php endif; ?>

                    <?php if ( sacscoc_inst_shows_achievement( $row ) ) : ?>
                        <a class="sacscoc-plus-link" href="<?php echo esc_url( $row['student_achievement_url'] ); ?>"
                           target="_blank" rel="noopener noreferrer">
                            <?php echo sacscoc_inst_icon( 'chart' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'View Student Achievement Data', 'sacscoc-institutions' ); ?></a>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </section>

    <!-- ── Accreditation ── -->
    <section class="sacscoc-block sacscoc-block--no-heading">
        <h2 class="sacscoc-block__subheading">
                    <?php echo sacscoc_inst_icon( 'seal', 'sacscoc-icon--heading' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <?php esc_html_e( 'Accreditation Information', 'sacscoc-institutions' ); ?>
                </h2>

        <div class="sacscoc-cols">
            <div class="sacscoc-col">
                <?php $fact( __( 'Status', 'sacscoc-institutions' ), sacscoc_inst_parse_text( $row['accreditation_status'] ), 'status' ); ?>

                <dl class="sacscoc-plus-list">
                    <dt<?php echo $sanction !== null ? ' class="sacscoc-error"' : ''; ?>>
                        <?php echo sacscoc_inst_icon( $sanction !== null ? 'sanction' : 'status' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <?php esc_html_e( 'Public Sanctions', 'sacscoc-institutions' ); ?>
                    </dt>
                    <dd>
                        <span<?php echo $sanction !== null ? ' class="sacscoc-error"' : ''; ?>>
                            <?php echo esc_html( $sanction ?? __( 'None', 'sacscoc-institutions' ) ); ?>
                        </span>
                        <?php if ( $sanction !== null && $row['general_disclosure_url'] ) : ?>
                            <a class="sacscoc-plus-link" href="<?php echo esc_url( $row['general_disclosure_url'] ); ?>"
                               target="_blank" rel="noopener noreferrer">
                                <?php echo sacscoc_inst_icon( 'external' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><small><?php esc_html_e( 'Accreditation Actions &amp; Disclosure Statements', 'sacscoc-institutions' ); ?></small>
                            </a>
                        <?php endif; ?>
                    </dd>
                </dl>

                <?php $fact( __( 'Candidacy Date', 'sacscoc-institutions' ), sacscoc_inst_date( $row['candidacy_date'] ), 'calendar' ); ?>
                <?php $fact( __( 'Accreditation Granted', 'sacscoc-institutions' ), sacscoc_inst_date( $row['accreditation_date'] ), 'calendar' ); ?>
                <?php $fact( __( 'Reaffirmation', 'sacscoc-institutions' ), sacscoc_inst_year( $row['reaffirmed_date'] ), 'calendar' ); ?>
                <?php $fact( __( 'Distance Education Approval Date', 'sacscoc-institutions' ), sacscoc_inst_date( $row['distance_learning_approved'] ), 'calendar' ); ?>
            </div>

            <div class="sacscoc-col">
                <?php $fact( __( 'Next Reaffirmation', 'sacscoc-institutions' ), sacscoc_inst_year( $row['next_reaffirm_date'] ), 'calendar' ); ?>
                <?php $fact( __( 'Next Fifth-Year Review', 'sacscoc-institutions' ), sacscoc_inst_year( $row['fifth_year_date'] ), 'calendar' ); ?>

                <?php if ( $level !== null ) : ?>
                    <dl class="sacscoc-plus-list">
                        <dt><?php echo sacscoc_inst_icon( 'level' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Degree Level', 'sacscoc-institutions' ); ?></dt>
                        <dd>
                            <?php echo esc_html( $level ); ?>
                            <?php if ( $level_tip ) : ?>
                                <span class="sacscoc-hint" tabindex="0"
                                      aria-label="<?php echo esc_attr( $level_tip ); ?>"
                                      title="<?php echo esc_attr( $level_tip ); ?>">i</span>
                                <small class="sacscoc-hint__note">
                                    <?php esc_html_e( 'See Approved to Offer above for the complete list.', 'sacscoc-institutions' ); ?>
                                </small>
                            <?php endif; ?>
                        </dd>
                    </dl>
                <?php endif; ?>

                <?php $fact( __( 'Control', 'sacscoc-institutions' ), sacscoc_inst_parse_text( $row['control'] ), 'control' ); ?>
                <?php $fact( __( 'CBE Course/Credit-based Approved', 'sacscoc-institutions' ), sacscoc_inst_date( $row['course_credit_based_approved'] ), 'calendar' ); ?>
            </div>
        </div>

        <?php if ( $history ) : ?>
            <details class="sacscoc-history">
                <summary class="sacscoc-btn sacscoc-btn--toggle">
                    <?php echo sacscoc_inst_icon( 'history' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <?php esc_html_e( 'Full Accreditation History', 'sacscoc-institutions' ); ?>
                </summary>
                <h3 class="sacscoc-history__title"><?php esc_html_e( 'Accreditation History', 'sacscoc-institutions' ); ?></h3>
                <table class="sacscoc-table">
                    <?php foreach ( $history as $line ) : ?>
                        <tr><td><?php echo esc_html( $line ); ?></td></tr>
                    <?php endforeach; ?>
                </table>
            </details>
        <?php endif; ?>
    </section>

    <!-- ── SACSCOC staff member ── -->
    <?php if ( sacscoc_inst_has_staff_contact( $row ) ) :
        $staff = trim( ( (string) $row['contact_first_name'] ) . ' ' . ( (string) $row['contact_last_name'] ) );
        ?>
        <?php if ( $staff !== '' || $row['contact_email'] || $row['contact_phone'] ) : ?>
            <section class="sacscoc-block sacscoc-block--no-heading">
                <h2 class="sacscoc-block__subheading">
                    <?php echo sacscoc_inst_icon( 'staff', 'sacscoc-icon--heading' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <?php esc_html_e( 'SACSCOC Staff Member', 'sacscoc-institutions' ); ?>
                </h2>

                <?php if ( $staff !== '' ) : ?>
                    <p class="sacscoc-staff"><strong><?php echo esc_html( $staff ); ?></strong></p>
                <?php endif; ?>

                <p class="sacscoc-links sacscoc-links--inline">
                    <?php if ( $row['contact_phone'] ) : ?>
                        <a class="sacscoc-plus-link" href="<?php echo esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', (string) $row['contact_phone'] ) ); ?>">
                            <?php echo sacscoc_inst_icon( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html( $row['contact_phone'] ); ?></a>
                    <?php endif; ?>
                    <?php if ( $row['contact_email'] ) : ?>
                        <a class="sacscoc-plus-link" href="<?php echo esc_url( 'mailto:' . $row['contact_email'] ); ?>">
                            <?php echo sacscoc_inst_icon( 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Email', 'sacscoc-institutions' ); ?></a>
                    <?php endif; ?>
                </p>
            </section>
        <?php endif; ?>
    <?php endif; ?>

    <!-- ── In-Progress Reviews ── -->
    <?php if ( $inprogress ) : ?>
        <section class="sacscoc-block sacscoc-block--no-heading">
            <h2 class="sacscoc-block__subheading">
                <?php echo sacscoc_inst_icon( 'calendar', 'sacscoc-icon--heading' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <?php esc_html_e( 'In-Progress Reviews', 'sacscoc-institutions' ); ?>
            </h2>
            <ul class="sacscoc-meetings">
                <?php foreach ( $inprogress as $meeting ) : ?>
                    <li>
                        <?php if ( $meeting['display_year'] ) : ?><strong><?php echo esc_html( $meeting['display_year'] ); ?></strong> <?php endif; ?>
                        <?php echo esc_html( sacscoc_inst_parse_text( $meeting['name'] ) ?? '' ); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <!-- ── Most Recent History with SACSCOC ── -->
    <?php if ( $recent ) : ?>
        <section class="sacscoc-block sacscoc-block--no-heading">
            <h2 class="sacscoc-block__subheading">
                <?php echo sacscoc_inst_icon( 'calendar', 'sacscoc-icon--heading' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <?php esc_html_e( 'Most Recent History with SACSCOC', 'sacscoc-institutions' ); ?>
            </h2>
            <ul class="sacscoc-meetings">
                <?php foreach ( $recent as $meeting ) : ?>
                    <li>
                        <?php if ( $meeting['display_year'] ) : ?><strong><?php echo esc_html( $meeting['display_year'] ); ?></strong> <?php endif; ?>
                        <?php echo esc_html( sacscoc_inst_parse_text( $meeting['name'] ) ?? '' ); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <!-- ── Off-campus Instructional Sites ── -->
    <?php if ( $sites ) : ?>
        <section class="sacscoc-block sacscoc-block--no-heading">
            <h2 class="sacscoc-block__subheading">
                <?php echo sacscoc_inst_icon( 'building', 'sacscoc-icon--heading' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <?php esc_html_e( 'Off-campus Instructional Sites (Additional Locations)', 'sacscoc-institutions' ); ?>
            </h2>

            <div class="sacscoc-sites-legend">
                <?php echo sacscoc_inst_sites_legend_content(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            </div>

            <ul class="sacscoc-sites">
                <?php foreach ( $sites as $site ) :
                    $site_locality = trim( implode( ', ', array_filter( [
                        sacscoc_inst_parse_text( $site['city'] ),
                        trim( ( (string) $site['state'] ) . ' ' . ( (string) $site['zip'] ) ),
                    ] ) ), ', ' );
                    ?>
                    <li class="sacscoc-site">
                        <strong class="sacscoc-site__name"><?php echo esc_html( sacscoc_inst_parse_text( $site['name'] ) ?? '' ); ?></strong>
                        <address>
                            <?php if ( $site['street'] ) : ?><span><?php echo esc_html( $site['street'] ); ?></span><?php endif; ?>
                            <?php if ( $site_locality !== '' ) : ?><span><?php echo esc_html( $site_locality ); ?></span><?php endif; ?>
                            <?php if ( $site['country'] ) : ?><span><?php echo esc_html( $site['country'] ); ?></span><?php endif; ?>
                        </address>
                        <p class="sacscoc-site__meta">
                            <?php if ( $site['type'] ) : ?>
                                <span><?php printf( /* translators: %s: site type */ esc_html__( 'Type: %s', 'sacscoc-institutions' ), esc_html( $site['type'] ) ); ?></span>
                            <?php endif; ?>
                            <?php if ( $site['status'] ) : ?>
                                <span><?php printf( /* translators: %s: site status */ esc_html__( 'Status: %s', 'sacscoc-institutions' ), esc_html( $site['status'] ) ); ?></span>
                            <?php endif; ?>
                        </p>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <!-- ── About SACSCOC ── -->
    <?php if ( $show_about && $footer !== '' ) : ?>
        <section class="sacscoc-block sacscoc-block--no-heading sacscoc-about">
            <?php
            // Set once in Institutions → Settings rather than stored 1,201 times.
            // Already run through wp_kses_post() in sacscoc_inst_footer_content().
            echo $footer; // phpcs:ignore WordPress.Security.EscapeOutput
            ?>
        </section>
    <?php endif; ?>

</article>
