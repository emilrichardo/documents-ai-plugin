<?php
/**
 * Shared admin regression checks, included after WordPress by both harnesses.
 * Option fixtures are intercepted in memory: rendering and submitting the
 * form never overwrite the local site's saved configuration.
 */

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

function gsearch_test_capture_page( callable $render ): string {
	ob_start();
	try {
		$render();
		return (string) ob_get_contents();
	} finally {
		ob_end_clean();
	}
}

function gsearch_test_admin(): void {
	global $menu, $submenu, $admin_page_hooks, $_registered_pages, $_parent_pages, $pagenow;

	$original_user     = wp_get_current_user();
	$original_post     = $_POST;
	$original_get      = $_GET;
	$original_request  = $_REQUEST;
	$original_pagenow  = $pagenow;
	$original_method   = $_SERVER['REQUEST_METHOD'];
	$original_menu     = $menu ?? [];
	$original_submenu  = $submenu ?? [];
	$original_hooks    = $admin_page_hooks ?? [];
	$original_pages    = $_registered_pages ?? [];
	$original_parents  = $_parent_pages ?? [];
	$original_option   = get_option( GSEARCH_OPTION );
	$fixture          = gsearch_get_settings();
	$fixture['providers']['documents'] = [ 'enabled' => false, 'label' => 'Policy archive' ];
	$fixture['providers']['institutions']['label'] = 'Member institutions';
	$fixture['post_types'] = [ 'gsearch_test_content' ];
	$fixture['extension_setting'] = [ 'preserve' => 'existing integration data' ];
	$fixture['results_per_page'] = 17;
	$fixture['compact_max_results'] = 7;
	$fixture['min_characters'] = 4;
	$fixture['debounce_ms'] = 450;
	$fixture['search_posts'] = false;
	$fixture['search_pages'] = true;
	$fixture['live_search'] = false;
	$captured_save = null;
	$read_fixture = static function () use ( &$fixture ) { return $fixture; };
	$capture_save = static function ( $value, $old_value ) use ( &$captured_save ) {
		$captured_save = $value;
		return $old_value; // update_option() short-circuits before any DB write.
	};
	add_filter( 'pre_option_' . GSEARCH_OPTION, $read_fixture );
	add_filter( 'pre_update_option_' . GSEARCH_OPTION, $capture_save, 10, 2 );

	try {
		// A request-local user object tests the actual capability gate without
		// creating accounts or changing any real user's roles.
		$admin = new WP_User( 0 );
		$admin->allcaps = [ 'read' => true, 'manage_options' => true ];
		$GLOBALS['current_user'] = $admin;
		$_POST = [];
		$_REQUEST = [];
		$_SERVER['REQUEST_METHOD'] = 'GET';

		echo "\n== Admin menu and permissions ==\n";
		$menu = [];
		$submenu = [];
		$admin_page_hooks = [];
		$_registered_pages = [];
		$_parent_pages = [];
		gsearch_register_settings_page();
		$matches = array_values( array_filter( $menu, static fn( $item ) => $item[2] === 'global-search' ) );
		$main = $matches[0] ?? [];
		gsearch_assert( 'exactly one Global Search top-level menu exists', count( $matches ) === 1 );
		gsearch_assert( 'top-level label and page title are Global Search', ( $main[0] ?? '' ) === 'Global Search' && ( $main[3] ?? '' ) === 'Global Search' );
		gsearch_assert( 'top-level menu requires manage_options', ( $main[1] ?? '' ) === 'manage_options' );
		gsearch_assert( 'top-level menu uses the built-in search Dashicon', ( $main[6] ?? '' ) === 'dashicons-search' );
		gsearch_assert( 'top-level page renders Overview', isset( $main[5] ) && has_action( $main[5], 'gsearch_render_overview_page' ) !== false );
		$children = array_values( $submenu['global-search'] ?? [] );
		$overview = array_values( array_filter( $children, static fn( $item ) => $item[2] === 'global-search' ) );
		$settings = array_values( array_filter( $children, static fn( $item ) => $item[2] === 'global-search-settings' ) );
		gsearch_assert( 'Overview submenu exists once and requires manage_options', count( $overview ) === 1 && $overview[0][0] === 'Overview' && $overview[0][1] === 'manage_options' );
		gsearch_assert( 'Settings submenu exists once and requires manage_options', count( $settings ) === 1 && $settings[0][0] === 'Settings' && $settings[0][1] === 'manage_options' );
		gsearch_assert( 'Settings submenu reuses the existing settings renderer', has_action( get_plugin_page_hookname( 'global-search-settings', 'global-search' ), 'gsearch_render_settings_page' ) !== false );
		$legacy = array_filter( $submenu['options-general.php'] ?? [], static fn( $item ) => $item[2] === 'global-search' );
		gsearch_assert( 'no duplicate settings entry is left under WordPress Settings', count( $legacy ) === 0 );

		echo "\n== Overview reflects saved configuration ==\n";
		$before = get_option( GSEARCH_OPTION );
		$overview_html = gsearch_test_capture_page( 'gsearch_render_overview_page' );
		$overview_text = html_entity_decode( wp_strip_all_tags( $overview_html ), ENT_QUOTES, 'UTF-8' );
		$header_shortcode = '[global_search variant="compact" shape="rounded" button_label="GO" placeholder="Search Site ..." show_filters="no"]';
		gsearch_assert( 'Overview displays the installed plugin version', strpos( $overview_text, GSEARCH_VERSION ) !== false );
		gsearch_assert( 'Overview documents the unchanged full-search shortcode', strpos( $overview_text, '[global_search]' ) !== false );
		gsearch_assert( 'Overview documents the exact requested header shortcode', strpos( $overview_text, $header_shortcode ) !== false );
		gsearch_assert( 'Overview links to Global Search Settings', strpos( $overview_html, esc_url( admin_url( 'admin.php?page=global-search-settings' ) ) ) !== false );
		foreach ( gsearch_service()->get_providers() as $provider ) {
			gsearch_assert( 'Overview displays saved label for ' . $provider->get_id(), strpos( $overview_text, gsearch_provider_label( $provider ) ) !== false );
			preg_match( '/<li\b[^>]*>(?:(?!<\/li>).)*' . preg_quote( esc_html( gsearch_provider_label( $provider ) ), '/' ) . '(?:(?!<\/li>).)*<\/li>/s', $overview_html, $source_row );
			$status = ! $provider->is_available() ? 'unavailable' : ( gsearch_provider_enabled( $provider->get_id() ) ? 'Active' : 'Disabled in Settings' );
			gsearch_assert( 'Overview reports the actual status of ' . $provider->get_id(), isset( $source_row[0] ) && strpos( $source_row[0], $status ) !== false );
		}
		$results_url = gsearch_results_url();
		if ( $results_url !== '' ) {
			gsearch_assert( 'Overview displays the current results destination', strpos( $overview_html, esc_url( $results_url ) ) !== false );
		}
		gsearch_assert( 'opening Overview preserves every saved setting', get_option( GSEARCH_OPTION ) === $before && $captured_save === null );
		$configured_page = $fixture['results_page'];
		$fixture['results_page'] = 0;
		$fallback_html = gsearch_test_capture_page( 'gsearch_render_overview_page' );
		gsearch_assert( 'Overview explains the WordPress fallback when no results page is configured', strpos( $fallback_html, 'No published results page is configured.' ) !== false && gsearch_results_url() === '' );
		$fixture['results_page'] = $configured_page;

		echo "\n== Settings preserves the existing option contract ==\n";
		$settings_html = gsearch_test_capture_page( 'gsearch_render_settings_page' );
		gsearch_assert( 'the existing option name is unchanged', GSEARCH_OPTION === 'gsearch_settings' );
		gsearch_assert( 'Settings groups providers under Search Sources', strpos( $settings_html, 'Search Sources' ) !== false );
		foreach ( [ 'search_posts', 'search_pages', 'results_per_page', 'results_page', 'compact_max_results', 'live_search', 'min_characters', 'debounce_ms' ] as $field ) {
			gsearch_assert( 'Settings retains the ' . $field . ' field', preg_match( '/\bname=[\'"]' . $field . '[\'"]/', $settings_html ) === 1 );
		}
		foreach ( gsearch_service()->get_providers() as $provider ) {
			$id = $provider->get_id();
			gsearch_assert( 'Settings retains enable and label controls for ' . $id, strpos( $settings_html, 'name="providers[' . $id . '][enabled]"' ) !== false && strpos( $settings_html, 'name="providers[' . $id . '][label]"' ) !== false );
			if ( ! $provider->is_available() ) {
				// Tie the status to this provider's own row, rather than accepting
				// a generic warning elsewhere on the page.
				preg_match( '/<tr\b[^>]*>(?:(?!<\/tr>).)*name="providers\[' . preg_quote( $id, '/' ) . '\]\[label\]"(?:(?!<\/tr>).)*<\/tr>/s', $settings_html, $row );
				gsearch_assert( 'Settings explicitly marks ' . $id . ' unavailable', isset( $row[0] ) && stripos( $row[0], 'unavailable' ) !== false );
			}
		}
		gsearch_assert( 'opening Settings preserves every saved setting', get_option( GSEARCH_OPTION ) === $before && $captured_save === null );
		gsearch_assert( 'Settings includes a nonce for the existing save action', strpos( $settings_html, 'name="_wpnonce"' ) !== false );

		// Submit the rendered form's current values with a valid nonce. Change
		// one field so update_option() reaches the capture filter; everything
		// else, including settings not exposed by the form, must survive.
		$_POST = [
			'gsearch_save' => 'Save Changes',
			'_wpnonce' => wp_create_nonce( 'gsearch_save_settings' ),
			'search_pages' => '1',
			'results_per_page' => '18',
			'results_page' => (string) $fixture['results_page'],
			'compact_max_results' => '7',
			'min_characters' => '4',
			'debounce_ms' => '450',
			'providers' => [],
		];
		foreach ( gsearch_service()->get_providers() as $provider ) {
			$id = $provider->get_id();
			$_POST['providers'][ $id ] = [ 'label' => $fixture['providers'][ $id ]['label'] ];
			if ( ! empty( $fixture['providers'][ $id ]['enabled'] ) ) {
				$_POST['providers'][ $id ]['enabled'] = '1';
			}
		}
		$_REQUEST = $_POST;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$saved_html = gsearch_test_capture_page( 'gsearch_render_settings_page' );
		$expected = $fixture;
		$expected['results_per_page'] = 18;
		gsearch_assert( 'saving Settings updates only the submitted setting and preserves all other option values', $captured_save === $expected );
		gsearch_assert( 'saving keeps the Results Page and all provider preferences', is_array( $captured_save ) && $captured_save['results_page'] === $fixture['results_page'] && $captured_save['providers'] === $fixture['providers'] );
		gsearch_assert( 'saving keeps existing additional post types and integration settings', is_array( $captured_save ) && $captured_save['post_types'] === $fixture['post_types'] && $captured_save['extension_setting'] === $fixture['extension_setting'] );
		gsearch_assert( 'a successful authenticated save displays confirmation', strpos( $saved_html, 'Settings saved.' ) !== false );

		echo "\n== Legacy settings links and form submissions ==\n";
		$redirect_url = null;
		$intercept_redirect = static function ( $location ) use ( &$redirect_url ) {
			$redirect_url = $location;
			throw new RuntimeException( 'gsearch_test_redirect' );
		};
		add_filter( 'wp_redirect', $intercept_redirect );
		try {
			$pagenow = 'options-general.php';
			$_GET = [ 'page' => 'global-search' ];
			$valid_post = $_POST;
			$_POST = [];
			$_REQUEST = $_GET;
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$captured_save = null;
			try {
				gsearch_redirect_legacy_settings_page();
			} catch ( RuntimeException $error ) {
				if ( $error->getMessage() !== 'gsearch_test_redirect' ) { throw $error; }
			}
			gsearch_assert( 'old Settings bookmark redirects to the new Settings submenu', $redirect_url === admin_url( 'admin.php?page=global-search-settings' ) );
			gsearch_assert( 'opening the old Settings bookmark never saves options', $captured_save === null );
			$_POST = $valid_post;
			$_REQUEST = array_merge( $_GET, $_POST );
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$redirect_url = null;
			try {
				gsearch_redirect_legacy_settings_page();
			} catch ( RuntimeException $error ) {
				if ( $error->getMessage() !== 'gsearch_test_redirect' ) { throw $error; }
			}
			gsearch_assert( 'an already-open legacy form preserves its submitted settings', $captured_save === $expected );
			gsearch_assert( 'legacy form submission redirects with a save confirmation', $redirect_url === add_query_arg( 'settings-updated', 'true', admin_url( 'admin.php?page=global-search-settings' ) ) );
		} finally {
			remove_filter( 'wp_redirect', $intercept_redirect );
		}
		$links = apply_filters( 'plugin_action_links_' . plugin_basename( GSEARCH_FILE ), [ '<a href="plugins.php">Existing action</a>' ] );
		gsearch_assert( 'Plugins list Settings shortcut reaches the new Settings page', count( $links ) === 2 && strpos( $links[0], esc_url( admin_url( 'admin.php?page=global-search-settings' ) ) ) !== false );

		echo "\n== Invalid nonces cannot save settings ==\n";
		$reject_nonce = static function () { throw new RuntimeException( 'gsearch_test_nonce_rejected' ); };
		$die_handler = static function () use ( $reject_nonce ) { return $reject_nonce; };
		add_filter( 'wp_die_handler', $die_handler );
		try {
			$_POST['_wpnonce'] = 'invalid-test-nonce';
			$_REQUEST = array_merge( $_GET, $_POST );
			foreach ( [ 'gsearch_render_settings_page', 'gsearch_redirect_legacy_settings_page' ] as $callback ) {
				$captured_save = null;
				$rejected = false;
				try {
					gsearch_test_capture_page( $callback );
				} catch ( RuntimeException $error ) {
					if ( $error->getMessage() !== 'gsearch_test_nonce_rejected' ) { throw $error; }
					$rejected = true;
				}
				gsearch_assert( $callback . ' rejects an invalid nonce before saving', $rejected && $captured_save === null );
			}
		} finally {
			remove_filter( 'wp_die_handler', $die_handler );
		}

		echo "\n== Users without manage_options cannot read or save admin screens ==\n";
		$limited_user = new WP_User( 0 );
		$limited_user->allcaps = [ 'read' => true, 'edit_posts' => true ];
		$GLOBALS['current_user'] = $limited_user;
		$captured_save = null;
		gsearch_assert( 'Overview is protected independently of menu visibility', gsearch_test_capture_page( 'gsearch_render_overview_page' ) === '' );
		gsearch_assert( 'Settings is protected independently of menu visibility', gsearch_test_capture_page( 'gsearch_render_settings_page' ) === '' );
		gsearch_assert( 'a forged settings POST without manage_options does not save', $captured_save === null );
		gsearch_assert( 'the legacy settings route also requires manage_options', gsearch_test_capture_page( 'gsearch_redirect_legacy_settings_page' ) === '' && $captured_save === null );
		gsearch_assert( 'users without manage_options receive no Plugins list Settings shortcut', gsearch_settings_action_link( [] ) === [] );
	} finally {
		remove_filter( 'pre_option_' . GSEARCH_OPTION, $read_fixture );
		remove_filter( 'pre_update_option_' . GSEARCH_OPTION, $capture_save );
		$GLOBALS['current_user'] = $original_user;
		$_POST = $original_post;
		$_GET = $original_get;
		$_REQUEST = $original_request;
		$pagenow = $original_pagenow;
		$_SERVER['REQUEST_METHOD'] = $original_method;
		$menu = $original_menu;
		$submenu = $original_submenu;
		$admin_page_hooks = $original_hooks;
		$_registered_pages = $original_pages;
		$_parent_pages = $original_parents;
	}
	gsearch_assert( 'admin regression tests leave the real saved option untouched', get_option( GSEARCH_OPTION ) === $original_option );

	echo "\n== Public search contracts remain intact ==\n";
	$providers = gsearch_service()->get_providers();
	gsearch_assert( 'default provider ids and order are unchanged', array_map( static fn( $provider ) => $provider->get_id(), $providers ) === [ 'wordpress', 'documents', 'institutions' ] );
	gsearch_assert( 'default provider implementations are unchanged', array_map( 'get_class', $providers ) === [ 'Global_Search_WordPress_Provider', 'Global_Search_Documents_Provider', 'Global_Search_Institutions_Provider' ] );
	$routes = rest_get_server()->get_routes();
	foreach ( [ '/global-search/v1/search' => 'gsearch_rest_search', '/global-search/v1/providers' => 'gsearch_rest_providers' ] as $route => $callback ) {
		$endpoint = $routes[ $route ][0] ?? [];
		gsearch_assert( $route . ' retains its public GET callback', ( $endpoint['callback'] ?? null ) === $callback && ( $endpoint['permission_callback'] ?? null ) === '__return_true' && ! empty( $endpoint['methods']['GET'] ) );
	}
	$request = new WP_REST_Request( 'GET', '/global-search/v1/providers' );
	$response = rest_get_server()->dispatch( $request );
	gsearch_assert( 'public providers endpoint reflects available, enabled providers', $response->get_status() === 200 && $response->get_data() === [ 'providers' => gsearch_service()->get_provider_metadata() ] );
}

gsearch_test_admin();
