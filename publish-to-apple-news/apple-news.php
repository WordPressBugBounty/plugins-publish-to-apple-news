<?php
/**
 * Plugin Name: Publish To Apple News
 * Plugin URI:  http://github.com/alleyinteractive/apple-news
 * Description: Export and sync posts to Apple format.
 * Version:     2.7.1
 * Author:      Alley
 * Author URI:  https://alley.com
 * Text Domain: apple-news
 * License:     GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl.html
 *
 * @package Apple_News
 */

/**
 * A shim for wp_date, if it is not defined.
 *
 * @param string       $format    PHP date format.
 * @param int          $timestamp Optional. Unix timestamp. Defaults to current time.
 * @param DateTimeZone $timezone  Optional. Timezone to output result in. Defaults to timezone from site settings.
 *
 * @return string|false The date, translated if locale specifies it. False on invalid timestamp input.
 */
function apple_news_date( $format, $timestamp = null, $timezone = null ) {
	// If wp_date exists (WP >= 5.3.0) use it.
	if ( function_exists( 'wp_date' ) ) {
		return wp_date( $format, $timestamp, $timezone );
	}

	// Fall back to using the date function if wp_date does not exist, to preserve backwards compatibility.
	return date( $format, $timestamp ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
}

require_once __DIR__ . '/includes/meta.php';

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Activate the plugin.
 */
function apple_news_activate_wp_plugin() {
	// Check for PHP version.
	if ( version_compare( PHP_VERSION, '8.0.0' ) < 0 ) {
		deactivate_plugins( basename( __FILE__ ) );
		wp_die( esc_html__( 'This plugin requires at least PHP 8.0.0', 'apple-news' ) );
	}
}

require __DIR__ . '/includes/apple-exporter/class-settings.php';

/**
 * Deactivate the plugin.
 */
function apple_news_uninstall_wp_plugin() {
	$settings = new Apple_Exporter\Settings();
	foreach ( array_keys( $settings->all() ) as $name ) {
		delete_option( $name );
	}
}

// WordPress VIP plugins do not execute these hooks, so ignore in that environment.
if ( ! defined( 'WPCOM_IS_VIP_ENV' ) || ! WPCOM_IS_VIP_ENV ) {
	register_activation_hook( __FILE__, 'apple_news_activate_wp_plugin' );
	register_uninstall_hook( __FILE__, 'apple_news_uninstall_wp_plugin' );
}

// Initialize plugin class.
require __DIR__ . '/includes/class-apple-news.php';
require __DIR__ . '/admin/class-admin-apple-news.php';

/**
 * Gets plugin data.
 * Used to provide generator info in the metadata class.
 *
 * @since 1.0.4
 *
 * @param bool $translate Whether to translate the plugin data.
 * @return array
 */
function apple_news_get_plugin_data( $translate = true ) {
	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	return get_plugin_data( __DIR__ . '/apple-news.php', true, $translate );
}

new Admin_Apple_News();

/**
 * Reports whether an export is currently happening.
 *
 * @return bool True if exporting, false if not.
 * @since 1.4.0
 */
function apple_news_is_exporting() {
	return Apple_Actions\Index\Export::is_exporting();
}

/**
 * Check if Block Editor is active.
 * Must only be used after plugins_loaded action is fired.
 *
 * @return bool
 */
function apple_news_block_editor_is_active() {
	$active = true;

	// Gutenberg plugin is installed and activated.
	$gutenberg = ! ( false === has_filter( 'replace_editor', 'gutenberg_init' ) );

	// Block editor since 5.0.
	$block_editor = version_compare( $GLOBALS['wp_version'], '5.0-beta', '>' );

	if ( ! $gutenberg && ! $block_editor ) {
		$active = false;
	}

	if ( $active && apple_news_is_classic_editor_plugin_active() ) {
		$editor_option       = get_option( 'classic-editor-replace' );
		$block_editor_active = [ 'no-replace', 'block' ];

		$active = in_array( $editor_option, $block_editor_active, true );
	}

	/**
	 * Overrides whether Apple News thinks the block editor is active or not.
	 *
	 * @since 2.0.0
	 *
	 * @param bool $active Whether Apple News thinks the block editor is active or not.
	 */
	return apply_filters( 'apple_news_block_editor_is_active', $active );
}

/**
 * Check if Block Editor is active for a given post ID.
 *
 * @param int $post_id Optional. The post ID to check. Defaults to the current post ID.
 * @return bool
 */
function apple_news_block_editor_is_active_for_post( $post_id = 0 ) {

	// If get_current_screen is not defined, we can't get info about the view, so bail out.
	if ( ! function_exists( 'get_current_screen' ) || ! function_exists( 'use_block_editor_for_post' ) ) {
		return false;
	}

	// Only return true if we are on the post add/edit screen.
	$screen = get_current_screen();
	if ( empty( $screen->base ) || 'post' !== $screen->base ) {
		return false;
	}

	// If the post ID isn't specified, pull the current post ID.
	if ( empty( $post_id ) ) {
		$post_id = get_the_ID();
	}

	// If the post ID isn't defined, bail out.
	if ( empty( $post_id ) ) {
		return false;
	}

	return use_block_editor_for_post( $post_id );
}

/**
 * Check if Classic Editor plugin is active.
 *
 * @return bool
 */
function apple_news_is_classic_editor_plugin_active() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	return is_plugin_active( 'classic-editor/classic-editor.php' );
}
