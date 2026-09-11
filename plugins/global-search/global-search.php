<?php
/**
 * Plugin Name: Global Search
 * Description: A unified search box that queries WordPress content and, when
 * they are active, the Policies and Institutions plugins — through a small
 * provider interface so any future content source (products, events, a
 * knowledge base, custom post types) can register itself without touching
 * this plugin's core.
 * Version: 0.1.0
 * Author: Cirlot
 * Text Domain: global-search
 *
 * This plugin knows nothing about SACSCOC, policies, or any other concrete
 * source by name. It defines a provider contract (Global_Search_Provider),
 * ships a WordPress-native provider that always works, and adapts to the
 * Policies/Institutions plugins only if it detects them active at runtime —
 * see includes/provider-documents.php and includes/provider-institutions.php.
 * Nothing here requires either plugin to be installed.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'GSEARCH_VERSION', '0.1.0' );
define( 'GSEARCH_FILE', __FILE__ );
define( 'GSEARCH_DIR', plugin_dir_path( __FILE__ ) );
define( 'GSEARCH_URL', plugin_dir_url( __FILE__ ) );

require_once GSEARCH_DIR . 'includes/interface-provider.php';
require_once GSEARCH_DIR . 'includes/functions.php';
require_once GSEARCH_DIR . 'includes/provider-wordpress.php';
require_once GSEARCH_DIR . 'includes/provider-documents.php';
require_once GSEARCH_DIR . 'includes/provider-institutions.php';
require_once GSEARCH_DIR . 'includes/class-search-service.php';
require_once GSEARCH_DIR . 'includes/rest.php';
require_once GSEARCH_DIR . 'includes/shortcode.php';
require_once GSEARCH_DIR . 'includes/blocks.php';
require_once GSEARCH_DIR . 'includes/admin-settings.php';
