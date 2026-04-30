<?php
/*
Plugin Name: QA Assistants
Plugin URI: https://quarka.org/
Description: Discover insights with QA Assistants — your platform for data-driven assistants that analyze sites from different angles.
Author: QuarkA
Author URI: https://quarka.org/
Version: 5.2.0.0
Text Domain: qa-heatmap-analytics
Requires at least: 5.9
Tested up to: 6.9
Requires PHP: 7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$qahm_plugin_data = get_file_data(
	__FILE__,
	array(
		'name'        => 'Plugin Name',
		'version'     => 'Version',
		'text_domain' => 'Text Domain',
	)
);

$qahm_plugin_type = get_option( 'qahm_plugin_type' );

if ( ! $qahm_plugin_type ) {
	update_option( 'qahm_plugin_type', $qahm_plugin_data['text_domain'] );
}

// プラグイン情報を設定
if ( ! defined( 'QAHM_TYPE_ZERO' ) ) {
	define( 'QAHM_TYPE_ZERO', 1 );
}
if ( ! defined( 'QAHM_TYPE_WP' ) ) {
	define( 'QAHM_TYPE_WP', 2 );
}
if ( ! defined( 'QAHM_TYPE' ) ) {
	define( 'QAHM_TYPE', QAHM_TYPE_WP );
}

if ( ! defined( 'QAHM_PLUGIN_NAME' ) ) {
	define( 'QAHM_PLUGIN_NAME', $qahm_plugin_data['name'] );
}
if ( ! defined( 'QAHM_PLUGIN_VERSION' ) ) {
	define( 'QAHM_PLUGIN_VERSION', $qahm_plugin_data['version'] );
}
if ( ! defined( 'QAHM_TEXT_DOMAIN' ) ) {
	define( 'QAHM_TEXT_DOMAIN', $qahm_plugin_data['text_domain'] );
}

require_once __DIR__ . '/qahm-loader.php';