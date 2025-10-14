<?php
/**
 * Plugin Name: Blog Import Tool
 * Description: Import blog posts from Squarespace XML export to custom blog post type
 * Version: 1.0.0
 * Author: SpaTheory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BLOG_IMPORT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLOG_IMPORT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load WP-CLI commands
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once BLOG_IMPORT_PLUGIN_DIR . 'includes/class-import-command.php';
}
