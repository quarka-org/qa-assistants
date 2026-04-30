<?php
/**
 * Assistant Runtime Handler
 *
 * Server-side handler for manifest-based assistant plugins.
 * Provides AJAX endpoints for manifest retrieval, QAL data fetching,
 * and config read/write operations.
 *
 * Design spec: docs/specs/assistant-manifest.md
 *
 * @package qa_heatmap_analytics
 */

defined( 'ABSPATH' ) || exit;

$GLOBALS['qahm_assistant_runtime_handler'] = new QAHM_Assistant_Runtime_Handler();

class QAHM_Assistant_Runtime_Handler extends QAHM_File_Data {

	public const NONCE_API = 'api';

	// Category whitelists: use shared constants from qahm-const.php
	// QAHM_CONFIG_READABLE_CATEGORIES, QAHM_CONFIG_WRITABLE_CATEGORIES

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->regist_ajax_func( 'ajax_get_assistant_manifest' );
		$this->regist_ajax_func( 'ajax_fetch_assistant_data' );
		$this->regist_ajax_func( 'ajax_read_config' );
		$this->regist_ajax_func( 'ajax_write_config' );
	}

	/**
	 * AJAX: Get assistant manifest + translations + system vars
	 */
	public function ajax_get_assistant_manifest() {
		if ( ! is_user_logged_in() ) {
			wp_die( 'you do not have privilege to access this page.' );
		}

		$nonce = $this->wrap_filter_input( INPUT_POST, 'nonce' );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_API ) ) {
			http_response_code( 400 );
			die( 'nonce error' );
		}

		$slug = sanitize_text_field( $this->wrap_filter_input( INPUT_POST, 'slug' ) );
		if ( empty( $slug ) ) {
			wp_send_json_error( array( 'message' => 'Missing slug parameter.' ) );
			return;
		}

		// Path traversal prevention
		if ( preg_match( '/[\/\\\\.]/', $slug ) ) {
			wp_send_json_error( array( 'message' => 'Invalid slug.' ) );
			return;
		}

		$plugin_dir    = WP_PLUGIN_DIR . '/' . $slug;
		$manifest_path = $plugin_dir . '/manifest.json';

		if ( ! file_exists( $manifest_path ) ) {
			wp_send_json_error( array( 'message' => 'Manifest not found.' ) );
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- WP_Filesystem may use FTP mode.
		$manifest_json = file_get_contents( $manifest_path );
		$manifest      = json_decode( $manifest_json, true );

		if ( ! is_array( $manifest ) ) {
			wp_send_json_error( array( 'message' => 'Invalid manifest JSON.' ) );
			return;
		}

		// Load translations
		$translations = $this->load_manifest_translations( $plugin_dir );

		// Build system vars
		$tracking_id = sanitize_text_field( $this->wrap_filter_input( INPUT_POST, 'tracking_id' ) );
		if ( empty( $tracking_id ) ) {
			$tracking_id = 'all';
		}

		$locale       = get_locale();
		$locale_short = $this->wrap_substr( $locale, 0, 2 );

		$system_vars = array(
			'tracking_id' => $tracking_id,
			'locale'      => $locale_short,
		);

		wp_send_json_success(
			array(
				'manifest'     => $manifest,
				'translations' => $translations,
				'system_vars'  => $system_vars,
			)
		);
	}

	/**
	 * AJAX: Fetch assistant data via QAL
	 */
	public function ajax_fetch_assistant_data() {
		if ( ! is_user_logged_in() ) {
			wp_die( 'you do not have privilege to access this page.' );
		}

		$nonce = $this->wrap_filter_input( INPUT_POST, 'nonce' );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_API ) ) {
			http_response_code( 400 );
			die( 'nonce error' );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$query_raw = isset( $_POST['query'] ) ? wp_unslash( $_POST['query'] ) : '';
		$query     = json_decode( $query_raw, true );

		if ( ! is_array( $query ) || empty( $query['material'] ) ) {
			wp_send_json_error( array( 'message' => 'Invalid query parameter.' ) );
			return;
		}

		$tracking_id = sanitize_text_field( $this->wrap_filter_input( INPUT_POST, 'tracking_id' ) );
		if ( empty( $tracking_id ) ) {
			$tracking_id = 'all';
		}

		// Convert manifest simplified query to QAL native format
		$qal_native = $this->convert_to_qal_native( $query, $tracking_id );

		if ( isset( $qal_native['error'] ) ) {
			wp_send_json_error( array( 'message' => $qal_native['error'] ) );
			return;
		}

		// Execute QAL
		global $qahm_qal_executor;

		if ( ! isset( $qahm_qal_executor ) || ! $qahm_qal_executor ) {
			wp_send_json_error( array( 'message' => 'QAL executor is not available.' ) );
			return;
		}

		$executable_qal = $qahm_qal_executor->qal_build_execute_plan( array( 'qal' => $qal_native ) );

		if ( isset( $executable_qal['error_code'] ) ) {
			wp_send_json_error(
				array(
					'message'    => isset( $executable_qal['message'] ) ? $executable_qal['message'] : 'QAL validation failed.',
					'error_code' => $executable_qal['error_code'],
				)
			);
			return;
		}

		// qal_executor internally calls qal_build_response, returning { data, meta }
		$response = $qahm_qal_executor->qal_executor( $executable_qal );

		if ( isset( $response['error_code'] ) ) {
			wp_send_json_error(
				array(
					'message'    => isset( $response['message'] ) ? $response['message'] : 'QAL execution failed.',
					'error_code' => $response['error_code'],
				)
			);
			return;
		}

		wp_send_json_success( $response );
	}

	/**
	 * AJAX: Read config data for assistant plugins
	 *
	 * Security: 4-layer check (permissions, whitelist, capability, nonce)
	 */
	public function ajax_read_config() {
		if ( ! is_user_logged_in() ) {
			wp_die( 'you do not have privilege to access this page.' );
		}

		$nonce = $this->wrap_filter_input( INPUT_POST, 'nonce' );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_API ) ) {
			http_response_code( 400 );
			die( 'nonce error' );
		}

		// Layer 3: WordPress capability
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
			return;
		}

		$category    = sanitize_text_field( $this->wrap_filter_input( INPUT_POST, 'category' ) );
		$tracking_id = sanitize_text_field( $this->wrap_filter_input( INPUT_POST, 'tracking_id' ) );
		$plugin_id   = sanitize_text_field( $this->wrap_filter_input( INPUT_POST, 'plugin_id' ) );

		if ( empty( $category ) || empty( $tracking_id ) || empty( $plugin_id ) ) {
			wp_send_json_error( array( 'message' => 'Missing required parameters.' ) );
			return;
		}

		// Config is site-specific — reject tracking_id=all
		if ( 'all' === $tracking_id ) {
			wp_send_json_error( array( 'message' => 'tracking_id "all" is not allowed for config_read.' ) );
			return;
		}

		// Layer 2: Whitelist check
		if ( ! in_array( $category, QAHM_CONFIG_READABLE_CATEGORIES, true ) ) {
			wp_send_json_error( array( 'message' => 'Category not readable: ' . $category ) );
			return;
		}

		// Layer 1: Permission check (manifest declares what it needs)
		if ( ! $this->check_plugin_permission( $plugin_id, 'config_read', $category ) ) {
			wp_send_json_error( array( 'message' => 'Plugin does not have config_read permission for: ' . $category ) );
			return;
		}

		// Read data by category
		$data = $this->read_config_data( $category, $tracking_id );
		wp_send_json_success( $data );
	}

	/**
	 * AJAX: Write config data for assistant plugins
	 *
	 * Security: 4-layer check + operation-specific nonce + audit log
	 */
	public function ajax_write_config() {
		if ( ! is_user_logged_in() ) {
			wp_die( 'you do not have privilege to access this page.' );
		}

		$nonce = $this->wrap_filter_input( INPUT_POST, 'nonce' );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_API ) ) {
			http_response_code( 400 );
			die( 'nonce error' );
		}

		// Layer 3: WordPress capability
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
			return;
		}

		$category    = sanitize_text_field( $this->wrap_filter_input( INPUT_POST, 'category' ) );
		$tracking_id = sanitize_text_field( $this->wrap_filter_input( INPUT_POST, 'tracking_id' ) );
		$plugin_id   = sanitize_text_field( $this->wrap_filter_input( INPUT_POST, 'plugin_id' ) );
		$key         = sanitize_text_field( $this->wrap_filter_input( INPUT_POST, 'key' ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$value_raw = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : '';
		$value     = json_decode( $value_raw, true );

		if ( empty( $category ) || empty( $tracking_id ) || empty( $plugin_id ) ) {
			wp_send_json_error( array( 'message' => 'Missing required parameters.' ) );
			return;
		}

		// tracking_id 'all' is not allowed for writing
		if ( 'all' === $tracking_id ) {
			wp_send_json_error( array( 'message' => 'tracking_id "all" is not allowed for config_write.' ) );
			return;
		}

		// Layer 2: Whitelist check
		if ( ! in_array( $category, QAHM_CONFIG_WRITABLE_CATEGORIES, true ) ) {
			wp_send_json_error( array( 'message' => 'Category not writable: ' . $category ) );
			return;
		}

		// Layer 1: Permission check
		if ( ! $this->check_plugin_permission( $plugin_id, 'config_write', $category ) ) {
			wp_send_json_error( array( 'message' => 'Plugin does not have config_write permission for: ' . $category ) );
			return;
		}

		if ( ! is_array( $value ) ) {
			wp_send_json_error( array( 'message' => 'Invalid value parameter.' ) );
			return;
		}

		// Layer 4: Audit log
		global $qahm_log;
		$qahm_log->info(
			sprintf(
				'[QA Config API] write: plugin=%s category=%s tracking_id=%s key=%s user=%d',
				$plugin_id,
				$category,
				$tracking_id,
				$key,
				get_current_user_id()
			)
		);

		// Write data by category
		$result = $this->write_config_data( $category, $tracking_id, $key, $value );
		if ( isset( $result['error'] ) ) {
			wp_send_json_error(
				array(
					'message' => $result['error'],
					'reason'  => $result['reason'],
				)
			);
			return;
		}

		// If response was already sent early (in_progress pattern), just die
		if ( ! empty( $result['sent_early'] ) ) {
			die();
		}

		wp_send_json_success( $result );
	}

	/**
	 * Check if an assistant plugin has the requested permission
	 *
	 * @param string $plugin_id  Plugin slug (manifest.id)
	 * @param string $permission Permission type (config_read, config_write)
	 * @param string $category   Config category
	 * @return bool
	 */
	private function check_plugin_permission( $plugin_id, $permission, $category ) {
		// Sanitize plugin_id to prevent path traversal
		if ( preg_match( '/[\/\\\\.]/', $plugin_id ) ) {
			return false;
		}

		$plugin_dir = WP_PLUGIN_DIR . '/' . $plugin_id;

		// Try manifest.json first
		$manifest_path = $plugin_dir . '/manifest.json';
		if ( file_exists( $manifest_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- WP_Filesystem may use FTP mode.
			$manifest_json = file_get_contents( $manifest_path );
			$manifest      = json_decode( $manifest_json, true );
			if ( is_array( $manifest ) && isset( $manifest['permissions'][ $permission ] ) ) {
				$allowed = $manifest['permissions'][ $permission ];
				return is_array( $allowed ) && in_array( $category, $allowed, true );
			}
			return false;
		}

		// Fallback: try config.json (Legacy plugins)
		$config_path = $plugin_dir . '/config.json';
		if ( file_exists( $config_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- WP_Filesystem may use FTP mode.
			$config_json = file_get_contents( $config_path );
			$config      = json_decode( $config_json, true );
			if ( is_array( $config ) && isset( $config['permissions'][ $permission ] ) ) {
				$allowed = $config['permissions'][ $permission ];
				return is_array( $allowed ) && in_array( $category, $allowed, true );
			}
			return false;
		}

		return false;
	}

	/**
	 * Read config data by category
	 *
	 * @param string $category    Config category
	 * @param string $tracking_id Tracking ID
	 * @return array Data with meta information
	 */
	private function read_config_data( $category, $tracking_id ) {
		global $qahm_data_api;

		switch ( $category ) {
			case 'goals':
				$items = $qahm_data_api->get_goals_preferences( $tracking_id );
				if ( ! is_array( $items ) ) {
					$items = array();
				}
				$count = count( $items );
				$max   = QAHM_CONFIG_GOALMAX;

				// Find next available gid (default: max+1 = invalid, caught by validation)
				$next_id = $max + 1;
				for ( $i = 1; $i <= $max; $i++ ) {
					if ( ! isset( $items[ $i ] ) ) {
						$next_id = $i;
						break;
					}
				}

				return array(
					'items'             => $items,
					'count'             => $count,
					'next_available_id' => $next_id,
					'is_max_reached'    => ( $count >= $max ),
				);

			case 'siteinfo':
				$siteinfo = $qahm_data_api->wrap_get_option( 'siteinfo' );
				$items    = array();
				if ( is_array( $siteinfo ) && isset( $siteinfo[ $tracking_id ] ) ) {
					$items = $siteinfo[ $tracking_id ];
				}
				return array(
					'items' => $items,
				);

			default:
				return array( 'items' => array() );
		}
	}

	/**
	 * Write config data by category
	 *
	 * @param string $category    Config category
	 * @param string $tracking_id Tracking ID
	 * @param string $key         Item key (e.g. gid for goals)
	 * @param array  $value       Data to write
	 * @return array Result or error
	 */
	private function write_config_data( $category, $tracking_id, $key, $value ) {
		global $qahm_data_api;

		switch ( $category ) {
			case 'goals':
				return $this->write_goals_config( $tracking_id, $key, $value );

			default:
				return array(
					'error'  => 'Unsupported category for write.',
					'reason' => 'server_error',
				);
		}
	}

	/**
	 * Write a goal via the shared validate + save + generate_files pipeline
	 *
	 * @param string $tracking_id Tracking ID
	 * @param string $key         Goal ID (gid)
	 * @param array  $value       Goal data from manifest
	 * @return array Result
	 */
	private function write_goals_config( $tracking_id, $key, $value ) {
		global $qahm_data_api;

		// Validate gid
		$gid = intval( $key );
		if ( $gid < 1 || $gid > QAHM_CONFIG_GOALMAX ) {
			return array(
				'error'  => 'Invalid goal ID.',
				'reason' => 'validation_error',
			);
		}

		// Check max goals
		$existing = $qahm_data_api->get_goals_preferences( $tracking_id );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		if ( ! isset( $existing[ $gid ] ) && count( $existing ) >= QAHM_CONFIG_GOALMAX ) {
			return array(
				'error'  => 'Maximum goals reached.',
				'reason' => 'max_reached',
			);
		}

		// Merge defaults for missing fields (shared constant from qahm-const.php)
		$params = array_merge( QAHM_GOAL_DEFAULTS, $value );

		// Validate
		$validation = $qahm_data_api->validate_goal( $params );
		if ( ! $validation['valid'] ) {
			return array(
				'error'  => 'Validation failed: ' . $validation['error'],
				'reason' => $validation['error'],
			);
		}

		$goal_data = $validation['goal_data'];

		// Save to DB with timing
		$start_dbsave_time = microtime( true );
		$saved             = $qahm_data_api->save_goal_to_db( $tracking_id, $gid, $goal_data );
		if ( ! $saved ) {
			return array(
				'error'  => 'Failed to save goal.',
				'reason' => 'server_error',
			);
		}

		// Generate goal files with in_progress early-response pattern.
		// Same approach as ajax_save_goal_x: if DB save or estimated file generation
		// takes too long, send JSON response early via header+echo (NOT wp_send_json_success
		// which calls die()) and continue file generation in background.
		ignore_user_abort( true );
		$sent_early   = false;
		$db_save_time = microtime( true ) - $start_dbsave_time;

		if ( $db_save_time > 3 ) {
			$sent_early = true;
			$json       = wp_json_encode(
				array(
					'success' => true,
					'data'    => array(
						'status' => 'in_progress',
						'gid'    => $gid,
					),
				)
			);
			header( 'Content-type: application/json; charset=UTF-8' );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
			echo $json;
			$this->flush_and_close_connection( strlen( $json ) );
		}

		// Estimate file generation time using first day of data
		if ( ! $sent_early ) {
			global $qahm_time;
			$pvterm_both_end = $qahm_data_api->get_pvterm_both_end_date( $tracking_id );
			if ( ! empty( $pvterm_both_end ) && $pvterm_both_end['start'] !== $pvterm_both_end['latest'] ) {
				$start_filemake_time = microtime( true );
				$qahm_data_api->fetch_goal_comp_sessions_in_month( $tracking_id, $gid, $goal_data, $pvterm_both_end['latest'], $pvterm_both_end['latest'] );
				$time_taken  = microtime( true ) - $start_filemake_time;
				$daterange   = 29;
				$range_start = $qahm_time->xday_str( -$daterange, $pvterm_both_end['latest'] );
				if ( $qahm_time->xday_num( $range_start, $pvterm_both_end['start'] ) < 0 ) {
					$daterange = $qahm_time->xday_num( $pvterm_both_end['latest'], $pvterm_both_end['start'] );
				}
				$estimated_time = round( $time_taken * $daterange, 0 );
				if ( $estimated_time + $db_save_time > 3 ) {
					$sent_early = true;
					$json       = wp_json_encode(
						array(
							'success' => true,
							'data'    => array(
								'status'        => 'in_progress',
								'gid'           => $gid,
								'estimated_sec' => $estimated_time,
							),
						)
					);
					header( 'Content-type: application/json; charset=UTF-8' );
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
					echo $json;
					$this->flush_and_close_connection( strlen( $json ) );
				}
			}
		}

		$file_result = $qahm_data_api->generate_goal_files( $tracking_id, $gid, $goal_data );

		if ( $sent_early ) {
			return array( 'sent_early' => true );
		}

		return array(
			'status'        => 'done',
			'gid'           => $gid,
			'goal_comp_flg' => isset( $file_result['goal_comp_flg'] ) ? $file_result['goal_comp_flg'] : 0,
		);
	}

	/**
	 * Convert manifest simplified query to QAL native format
	 *
	 * Manifest format:
	 *   { material: "allpv", columns: ["page_id", "title"], filter: { tracking_id: { eq: "..." }, date: { between: [...] } } }
	 *
	 * QAL native format:
	 *   { tracking_id: "...", materials: [{ name: "allpv" }], time: { start, end, tz }, make: { view: { from: [...], keep: [...] } }, result: { use: "view", limit: 10000 } }
	 *
	 * @param array  $query       Manifest simplified query
	 * @param string $tracking_id Tracking ID
	 * @return array QAL native format or error array
	 */
	private function convert_to_qal_native( $query, $tracking_id ) {
		$material = sanitize_text_field( $query['material'] );

		// Allowed materials
		$allowed_materials = array( 'allpv', 'gsc', 'goal_x' );
		if ( ! in_array( $material, $allowed_materials, true ) ) {
			return array( 'error' => 'Unknown material: ' . $material );
		}

		// Build time from date filter
		$time = $this->extract_time_from_filter( $query );

		// Build keep columns with material prefix
		$keep = array();
		if ( ! empty( $query['columns'] ) && is_array( $query['columns'] ) ) {
			foreach ( $query['columns'] as $col ) {
				$keep[] = $material . '.' . sanitize_text_field( $col );
			}
		}

		// Build QAL native
		$qal_native = array(
			'tracking_id' => $tracking_id,
			'materials'   => array( array( 'name' => $material ) ),
			'time'        => $time,
			'make'        => array(
				'view' => array(
					'from' => array( $material ),
				),
			),
			'result'      => array(
				'use'   => 'view',
				'limit' => 10000,
			),
		);

		if ( ! empty( $keep ) ) {
			$qal_native['make']['view']['keep'] = $keep;
		}

		// Build filter conditions from manifest filter (excluding tracking_id and date)
		$filter_conditions = $this->build_filter_conditions( $query );
		if ( ! empty( $filter_conditions ) ) {
			$qal_native['make']['view']['filter'] = $filter_conditions;
		}

		return $qal_native;
	}

	/**
	 * Build QAL filter conditions from manifest filter
	 *
	 * Converts manifest filter operators to QAL native filter format.
	 * Skips tracking_id (handled as top-level param) and date (handled as time).
	 *
	 * @param array $query Manifest query
	 * @return array Filter conditions for QAL native, or empty array
	 */
	private function build_filter_conditions( $query ) {
		if ( ! isset( $query['filter'] ) || ! is_array( $query['filter'] ) ) {
			return array();
		}

		$filter         = $query['filter'];
		$conditions     = array();
		$special_fields = array( 'tracking_id', 'date' );

		foreach ( $filter as $field => $operators ) {
			$field = sanitize_text_field( $field );
			if ( in_array( $field, $special_fields, true ) ) {
				continue;
			}
			if ( ! is_array( $operators ) ) {
				continue;
			}

			$sanitized = array();
			foreach ( $operators as $op => $value ) {
				$op = sanitize_text_field( $op );
				// TODO: Use esc_url_raw() for URL values when field type info is available from manifest
				$sanitized[ $op ] = $this->sanitize_filter_value( $value );
			}

			if ( ! empty( $sanitized ) ) {
				$conditions[ $field ] = $sanitized;
			}
		}

		return $conditions;
	}

	/**
	 * Sanitize a filter value
	 *
	 * @param mixed $value Value to sanitize
	 * @return mixed Sanitized value
	 */
	private function sanitize_filter_value( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'sanitize_text_field', $value );
		}
		return sanitize_text_field( $value );
	}

	/**
	 * Build a manifest-based assistant entry from its directory
	 *
	 * Reads manifest.json, loads translations, resolves icon URL.
	 *
	 * @param string $dir  Full path to assistant plugin directory
	 * @param string $slug Plugin slug
	 * @return array|false Assistant data array or false on failure
	 */
	public function build_manifest_assistant( $dir, $slug ) {
		$manifest_file = $dir . '/manifest.json';
		if ( ! file_exists( $manifest_file ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- WP_Filesystem may use FTP mode.
		$manifest_json = file_get_contents( $manifest_file );
		$manifest      = json_decode( $manifest_json, true );
		if ( ! $manifest ) {
			return false;
		}

		// Get plugin data from header
		$plugin_data = get_plugin_data( $dir . '/' . $slug . '.php', false, true );

		// Load translations
		$translations = $this->load_manifest_translations( $dir );

		// Build icon URL
		$relative_dir = str_replace( WP_CONTENT_DIR, '', $dir );
		$icon_file    = isset( $manifest['icon'] ) ? $manifest['icon'] : 'icon.png';
		$icon_url     = content_url( $relative_dir . '/' . $icon_file );

		return array(
			'slug'         => $slug,
			'name'         => $this->resolve_manifest_translation( isset( $manifest['name'] ) ? $manifest['name'] : $slug, $translations ),
			'description'  => $this->resolve_manifest_translation( isset( $manifest['description'] ) ? $manifest['description'] : '', $translations ),
			'author'       => $plugin_data['Author'] ?? '',
			'version'      => isset( $manifest['version'] ) ? $manifest['version'] : ( $plugin_data['Version'] ?? '' ),
			'images'       => array( 'default' => $icon_url ),
			'manifest_url' => true,
		);
	}

	/**
	 * Load translations for manifest-based assistants
	 *
	 * Loads lang/{locale_short}.json with fallback to lang/en.json
	 *
	 * @param string $dir Plugin directory path
	 * @return array Translations array
	 */
	public function load_manifest_translations( $dir ) {
		$locale = get_locale();
		$lang   = $this->wrap_substr( $locale, 0, 2 );

		$file_path = $dir . '/lang/' . $lang . '.json';

		// Fallback to en.json
		if ( ! file_exists( $file_path ) ) {
			$file_path = $dir . '/lang/en.json';
		}

		if ( ! file_exists( $file_path ) ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- WP_Filesystem may use FTP mode.
		$json         = file_get_contents( $file_path );
		$translations = json_decode( $json, true );

		if ( ! is_array( $translations ) ) {
			return array();
		}

		return $translations;
	}

	/**
	 * Resolve t: prefixed translation keys using nested lookup
	 *
	 * @param string $value Value that may contain t: prefix
	 * @param array $translations Translations array (nested)
	 * @return string Resolved value
	 */
	public function resolve_manifest_translation( $value, $translations ) {
		if ( ! is_string( $value ) || $this->wrap_strpos( $value, 't:' ) !== 0 ) {
			return $value;
		}

		$key_path = $this->wrap_substr( $value, 2 );
		$parts    = $this->wrap_explode( '.', $key_path );
		$current  = $translations;

		foreach ( $parts as $part ) {
			if ( is_array( $current ) && isset( $current[ $part ] ) ) {
				$current = $current[ $part ];
			} else {
				return $value; // Key not found, return original
			}
		}

		return is_string( $current ) ? $current : $value;
	}

	/**
	 * Extract time parameters from manifest filter
	 *
	 * @param array $query Manifest query with filter
	 * @return array Time array for QAL { start, end, tz }
	 */
	private function extract_time_from_filter( $query ) {
		$start = '';
		$end   = '';

		if ( isset( $query['filter']['date']['between'] ) && is_array( $query['filter']['date']['between'] ) ) {
			$between = $query['filter']['date']['between'];
			$start   = isset( $between[0] ) ? sanitize_text_field( $between[0] ) : '';
			$end     = isset( $between[1] ) ? sanitize_text_field( $between[1] ) : '';
		}

		// Use WordPress timezone setting
		$tz = wp_timezone_string();
		if ( empty( $tz ) ) {
			$tz = 'Asia/Tokyo';
		}

		return array(
			'start' => $start,
			'end'   => $end,
			'tz'    => $tz,
		);
	}
}
