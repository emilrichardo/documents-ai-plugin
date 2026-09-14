<?php
/**
 * Settings → Global Search. Deliberately small: which providers are on,
 * their labels, how many results each contributes, whether Posts/Pages are
 * searched, and the live-search behaviour — not a general-purpose options
 * framework. Plain manual form + nonce (same pattern as
 * sacscoc-institutions/includes/admin.php's own screens), not the Settings
 * API — there is exactly one screen and one option row here, which the
 * Settings API would not meaningfully simplify.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', 'gsearch_register_settings_page' );
function gsearch_register_settings_page(): void {
	add_options_page(
		__( 'Global Search', 'global-search' ),
		__( 'Global Search', 'global-search' ),
		'manage_options',
		'global-search',
		'gsearch_render_settings_page'
	);
}

function gsearch_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_POST['gsearch_save'] ) ) {
		check_admin_referer( 'gsearch_save_settings' );
		gsearch_handle_settings_save();
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'global-search' ) . '</p></div>';
	}

	$settings  = gsearch_get_settings();
	$providers = gsearch_service()->get_providers(); // includes unavailable ones, so their toggle still shows (greyed) for when they become active
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Global Search', 'global-search' ); ?></h1>
		<form method="post">
			<?php wp_nonce_field( 'gsearch_save_settings' ); ?>

			<h2><?php esc_html_e( 'WordPress content', 'global-search' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Search Posts', 'global-search' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="search_posts" value="1" <?php checked( ! empty( $settings['search_posts'] ) ); ?> />
							<?php esc_html_e( 'Include published Posts in results', 'global-search' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Search Pages', 'global-search' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="search_pages" value="1" <?php checked( ! empty( $settings['search_pages'] ) ); ?> />
							<?php esc_html_e( 'Include published Pages in results', 'global-search' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gsearch_results_per_page"><?php esc_html_e( 'Results per source', 'global-search' ); ?></label></th>
					<td>
						<input type="number" id="gsearch_results_per_page" name="results_per_page" min="1" max="50" value="<?php echo esc_attr( (string) $settings['results_per_page'] ); ?>" />
						<p class="description"><?php esc_html_e( 'How many results each source may contribute to a single search (not a total across all sources).', 'global-search' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Sources', 'global-search' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php foreach ( $providers as $provider ) :
					$id       = $provider->get_id();
					$enabled  = $settings['providers'][ $id ]['enabled'] ?? true;
					$label    = $settings['providers'][ $id ]['label'] ?? $provider->get_label();
					$available = $provider->is_available();
					?>
					<tr>
						<th scope="row"><?php echo esc_html( $provider->get_label() ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="providers[<?php echo esc_attr( $id ); ?>][enabled]" value="1" <?php checked( $enabled ); ?> />
								<?php esc_html_e( 'Enabled', 'global-search' ); ?>
							</label>
							<?php if ( ! $available ) : ?>
								<span class="description"> — <?php esc_html_e( 'plugin not active; this source is skipped until it is.', 'global-search' ); ?></span>
							<?php endif; ?>
							<br />
							<label>
								<?php esc_html_e( 'Label:', 'global-search' ); ?>
								<input type="text" name="providers[<?php echo esc_attr( $id ); ?>][label]" value="<?php echo esc_attr( $label ); ?>" />
							</label>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<h2><?php esc_html_e( 'Results page', 'global-search' ); ?></h2>
			<p class="description" style="max-width:44em;margin-bottom:12px;">
				<?php esc_html_e( 'The page carrying the full results — where a compact header search box sends a visitor who presses Search or Enter, and where its dropdown\'s "View all results" link goes. The page needs a Global Search block, or the [global_search] shortcode, on it. Left as None, a header box falls back to WordPress\'s own search instead: plainer, but never broken.', 'global-search' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="gsearch_results_page"><?php esc_html_e( 'Results page', 'global-search' ); ?></label></th>
					<td>
						<?php
						wp_dropdown_pages( [
							'name'              => 'results_page',
							'id'                => 'gsearch_results_page',
							'selected'          => (int) $settings['results_page'],
							'show_option_none'  => __( '— None (use WordPress search) —', 'global-search' ),
							'option_none_value' => 0,
						] );
						?>
						<?php $resolved = gsearch_results_url(); ?>
						<p class="description">
							<?php if ( $resolved !== '' ) : ?>
								<?php esc_html_e( 'Searches go to:', 'global-search' ); ?>
								<code><?php echo esc_html( $resolved ); ?></code>
							<?php else : ?>
								<?php esc_html_e( 'Searches go to WordPress\'s own search results.', 'global-search' ); ?>
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gsearch_compact_max"><?php esc_html_e( 'Rows in the compact dropdown', 'global-search' ); ?></label></th>
					<td>
						<input type="number" id="gsearch_compact_max" name="compact_max_results" min="1" max="20" value="<?php echo esc_attr( (string) $settings['compact_max_results'] ); ?>" />
						<p class="description"><?php esc_html_e( 'How many matches a header search box shows before "View all results". Small on purpose.', 'global-search' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Live search', 'global-search' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Search while typing', 'global-search' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="live_search" value="1" <?php checked( ! empty( $settings['live_search'] ) ); ?> />
							<?php esc_html_e( 'Run a search automatically as the visitor types (in addition to Enter/Search)', 'global-search' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gsearch_min_characters"><?php esc_html_e( 'Minimum characters', 'global-search' ); ?></label></th>
					<td><input type="number" id="gsearch_min_characters" name="min_characters" min="1" max="10" value="<?php echo esc_attr( (string) $settings['min_characters'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="gsearch_debounce_ms"><?php esc_html_e( 'Debounce (ms)', 'global-search' ); ?></label></th>
					<td><input type="number" id="gsearch_debounce_ms" name="debounce_ms" min="0" max="2000" step="50" value="<?php echo esc_attr( (string) $settings['debounce_ms'] ); ?>" /></td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Changes', 'global-search' ), 'primary', 'gsearch_save' ); ?>
		</form>
	</div>
	<?php
}

function gsearch_handle_settings_save(): void {
	$settings = gsearch_get_settings();

	$settings['search_posts']      = ! empty( $_POST['search_posts'] );
	$settings['search_pages']      = ! empty( $_POST['search_pages'] );
	$settings['results_per_page']  = max( 1, min( 50, absint( $_POST['results_per_page'] ?? 10 ) ) );
	$settings['live_search']       = ! empty( $_POST['live_search'] );
	$settings['min_characters']    = max( 1, min( 10, absint( $_POST['min_characters'] ?? 3 ) ) );
	$settings['debounce_ms']       = max( 0, min( 2000, absint( $_POST['debounce_ms'] ?? 300 ) ) );
	$settings['results_page']        = absint( $_POST['results_page'] ?? 0 );
	$settings['compact_max_results'] = max( 1, min( 20, absint( $_POST['compact_max_results'] ?? 6 ) ) );

	$posted_providers = is_array( $_POST['providers'] ?? null ) ? $_POST['providers'] : [];
	foreach ( $posted_providers as $id => $fields ) {
		$id = sanitize_key( $id );
		$settings['providers'][ $id ] = [
			'enabled' => ! empty( $fields['enabled'] ),
			'label'   => sanitize_text_field( $fields['label'] ?? '' ),
		];
	}

	gsearch_update_settings( $settings );
}
