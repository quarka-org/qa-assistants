<?php
defined( 'ABSPATH' ) || exit;
/**
 * Assistant Manager
 *
 * Thin router: discovers installed assistants (manifest or legacy),
 * delegates to RuntimeHandler or LegacyHandler accordingly.
 *
 * @package qa_heatmap_analytics
 */

$GLOBALS['qahm_assistant_manager'] = new QAHM_Assistant_Manager();

class QAHM_Assistant_Manager extends QAHM_File_Data {

	public const NONCE_API = 'api';

	public function __construct() {
		// Register AJAX functions
		$this->regist_ajax_func( 'ajax_get_assistant' );
		$this->regist_ajax_func( 'ajax_connect_assistant' );
	}

	/**
	 * AJAX: Get assistant list
	 */
	public function ajax_get_assistant() {
		if ( ! is_user_logged_in() ) {
			wp_die( 'you don not have privilege to access this page.' );
		}
		// Verify nonce and check maintenance status
		$nonce = $this->wrap_filter_input( INPUT_POST, 'nonce' );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_API ) || $this->is_maintenance() ) {
			http_response_code( 400 );
			die( 'nonce error' );
		}
		// Retrieve the assistantSlug from the POST data
		$assistant_slug = $this->wrap_filter_input( INPUT_POST, 'assistant_slug' );
		$response       = $this->get_assistant( $assistant_slug );
		// Return the JSON response
		wp_send_json_success( $response );
	}

	/**
	 * AJAX: Connect assistant — proxy to LegacyHandler
	 */
	public function ajax_connect_assistant() {
		global $qahm_assistant_legacy_handler;
		$qahm_assistant_legacy_handler->ajax_connect_assistant();
	}

	/**
	 * Discover installed assistants
	 *
	 * Loops through qa-assistant-* plugin directories.
	 * Manifest-based → delegates to RuntimeHandler.
	 * Legacy (main.php) → delegates to LegacyHandler.
	 *
	 * @param string|null $assistant_slug Optional slug to filter
	 * @return array
	 */
	public function get_assistant( $assistant_slug = null ) {
		global $qahm_assistant_runtime_handler;
		global $qahm_assistant_legacy_handler;

		// Request-scoped memoization: discovery is a pure read (glob + manifest
		// parse), so the same argument always yields the same result within one
		// request. null and '' share a key because both mean "no slug filter".
		static $cache = array();
		$cache_key = ( null === $assistant_slug ) ? '' : (string) $assistant_slug;
		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		$assistant_dir = WP_PLUGIN_DIR . '/';
		$assistant_ary = array();

		// Ensure get_plugins function is loaded
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Get the list of assistant directories
		$dirs = glob( $assistant_dir . '*', GLOB_ONLYDIR );

		foreach ( $dirs as $dir ) {
			$slug = basename( $dir );

			// Skip if not a qa-assistant-* pattern
			if ( $this->wrap_strpos( $slug, 'qa-assistant-' ) !== 0 ) {
				continue;
			}

			// Check if the plugin is active
			$plugin_file = $slug . '/' . $slug . '.php';
			if ( ! is_plugin_active( $plugin_file ) ) {
				continue;
			}

			// Skip if a specific assistant slug is requested and this is not it
			if ( $assistant_slug && $slug !== $assistant_slug ) {
				continue;
			}

			// --- Manifest-based assistant (new path) ---
			$manifest_assistant = $qahm_assistant_runtime_handler->build_manifest_assistant( $dir, $slug );
			if ( $manifest_assistant ) {
				$assistant_ary[ $slug ] = $manifest_assistant;
				continue;
			}

			// --- Legacy assistant (existing path) ---
			$legacy_assistant = $qahm_assistant_legacy_handler->build_legacy_assistant( $dir, $slug );
			if ( $legacy_assistant ) {
				$assistant_ary[ $slug ] = $legacy_assistant;
			}
		}

		$cache[ $cache_key ] = $assistant_ary;
		return $assistant_ary;
	}
}
