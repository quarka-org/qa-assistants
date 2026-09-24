<?php
/**
 * Assistant Runtime Handler
 *
 * Server-side handler for manifest-based assistant plugins.
 * Provides AJAX endpoints for manifest retrieval, QAL data fetching,
 * and config read/write operations.
 *
 * Design spec: docs/specs/assistant/overview.md (and related)
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

		$manifest_json = self::read_file_cached( $manifest_path );
		$manifest      = json_decode( (string) $manifest_json, true );

		if ( ! is_array( $manifest ) ) {
			wp_send_json_error( array( 'message' => 'Invalid manifest JSON.' ) );
			return;
		}

		// Compatibility gate (Issue #1433): if the manifest declares a minimum
		// assistant-spec version this core does not implement, return an actionable
		// "please update" error instead of a confusing schema failure. Format-guarded:
		// only a well-formed 3-part semver string is honored, so unvalidated input
		// cannot crash version_compare() (non-string) nor let a 4-part product version
		// ("5.2.1.0") be mistaken for a spec requirement. Anything else falls through
		// to the schema validator below (E_SCHEMA_PATTERN).
		// /D anchors $ to the very end (no trailing newline), matching JS/ajv's default
		// so a value like "2.9.0\n" is treated the same by PHP and the schema validator.
		$min_core = isset( $manifest['min_core_version'] ) ? $manifest['min_core_version'] : null;
		if ( is_string( $min_core ) && preg_match( '/^\d+\.\d+\.\d+$/D', $min_core ) ) {
			if ( version_compare( $min_core, QAHM_ASSISTANT_SPEC_VERSION, '>' ) ) {
				wp_send_json_error(
					array(
						'message'    => __( 'This assistant requires a newer version of QA Assistants. Please update the plugin.', 'qa-heatmap-analytics' ),
						'error_code' => 'E_CORE_TOO_OLD',
						'required'   => $min_core,
						'current'    => QAHM_ASSISTANT_SPEC_VERSION,
					),
					409
				);
				return;
			}
		}

		// Defense line: validate the manifest against the JSON Schema (Draft 7) before
		// delivering it, so a client that bypasses the JS validator cannot feed a
		// malformed manifest to the runtime. Structural checks (E_SCHEMA_*) AND
		// reference-integrity checks (E_REF_*) — Issue #1581 で JS 側と同じ検査を PHP が持ち、
		// 翻訳（lang/*.json）も渡す＝「門番はホストの PHP に1人」（親 #1578）。翻訳の読み込みは
		// QAHM_Assistant_Schema_Validator::load_translations() が唯一の正本（ここに複製しない）。
		// 検査に渡す翻訳は全 locale。配信で返す $translations（下）は表示用＝現在 locale の1枚のまま。
		$validation = QAHM_Assistant_Schema_Validator::validate(
			$manifest_json,
			QAHM_Assistant_Schema_Validator::load_translations( $plugin_dir )
		);
		if ( ! $validation['valid'] ) {
			wp_send_json_error(
				array(
					'message'    => 'Manifest failed schema validation.',
					'error_code' => 'E_SCHEMA_VALIDATION',
					'errors'     => $validation['errors'],
				),
				400
			);
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
			'tz'          => wp_timezone_string() ?: 'Asia/Tokyo',
		);

		// Issue #1535: 会話エクスポートの記録メタは system_vars と別キーで運ぶ。
		// system_vars に足すと runtime の $sys.* 解決（許可リスト無し）で manifest から
		// 読めてしまい「validator は INVALID・runtime は解決」の宣言⇔実装ドリフトになる
		// （セルフレビュー 🟡-1）。別キーなら $sys.* の語彙面は 1 バイトも変わらない。
		// 読み手は launcher（assistant-ai-manifest.js）→ exporter のみ。
		$export_meta = array(
			'spec_version' => QAHM_ASSISTANT_SPEC_VERSION,
			'site_label'   => $this->get_site_label( $tracking_id ),
		);

		wp_send_json_success(
			array(
				'manifest'     => $manifest,
				'translations' => $translations,
				'system_vars'  => $system_vars,
				'export_meta'  => $export_meta,
			)
		);
	}

	/**
	 * tracking_id からサイトの表示ラベル（url / domain）を引く — Issue #1535。
	 *
	 * 会話エクスポートの記録メタは数ヶ月後に人が読む＝tracking_id（ハッシュ）だけでは
	 * どのサイトか判らないため、sitemanage の url（無ければ domain）を併記する。
	 * 'all'・未登録 id は空文字（呼び出し側で tracking_id のみ表示に縮退）。
	 * status は意図的に見ない（255＝削除済みサイトでも label を返す）＝「どのサイトを
	 * 分析した会話か」の記録用途では、後から削除されたサイトも名前で残る方が正しい。
	 *
	 * @param string $tracking_id トラッキング ID。
	 * @return string サイトラベル（例: "example.com/"）。不明なら ''。
	 */
	private function get_site_label( $tracking_id ) {
		if ( ! is_string( $tracking_id ) || '' === $tracking_id || 'all' === $tracking_id ) {
			return '';
		}
		$sitemanage = $this->wrap_get_option( 'sitemanage' );
		if ( ! is_array( $sitemanage ) ) {
			return '';
		}
		foreach ( $sitemanage as $site ) {
			if ( is_array( $site ) && isset( $site['tracking_id'] ) && $site['tracking_id'] === $tracking_id ) {
				if ( isset( $site['url'] ) && is_string( $site['url'] ) && '' !== $site['url'] ) {
					return $site['url'];
				}
				if ( isset( $site['domain'] ) && is_string( $site['domain'] ) && '' !== $site['domain'] ) {
					return $site['domain'];
				}
				return '';
			}
		}
		return '';
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

		if ( ! is_array( $query ) ) {
			wp_send_json_error( array( 'message' => 'Invalid query parameter.' ) );
			return;
		}

		$qal_native = $query;

		// Execute QAL
		global $qahm_qal_executor;

		if ( ! isset( $qahm_qal_executor ) || ! $qahm_qal_executor ) {
			wp_send_json_error( array( 'message' => 'QAL executor is not available.' ) );
			return;
		}

		$executable_qal = $qahm_qal_executor->qal_build_execute_plan( array( 'qal' => $qal_native ) );

		if ( isset( $executable_qal['error_code'] ) ) {
			$error_payload = array(
				'message'    => isset( $executable_qal['message'] ) ? $executable_qal['message'] : 'QAL validation failed.',
				'error_code' => $executable_qal['error_code'],
			);
			if ( isset( $executable_qal['location'] ) ) {
				$error_payload['location'] = $executable_qal['location'];
			}
			if ( isset( $executable_qal['details'] ) ) {
				$error_payload['details'] = $executable_qal['details'];
			}
			wp_send_json_error( $error_payload );
			return;
		}

		// qal_executor internally calls qal_build_response, returning { data, meta }
		$response = $qahm_qal_executor->qal_executor( $executable_qal );

		if ( isset( $response['error_code'] ) ) {
			$error_payload = array(
				'message'    => isset( $response['message'] ) ? $response['message'] : 'QAL execution failed.',
				'error_code' => $response['error_code'],
			);
			if ( isset( $response['location'] ) ) {
				$error_payload['location'] = $response['location'];
			}
			if ( isset( $response['details'] ) ) {
				$error_payload['details'] = $response['details'];
			}
			wp_send_json_error( $error_payload );
			return;
		}

		wp_send_json_success( $response );
	}

	/**
	 * AJAX: Read config data for assistant plugins
	 *
	 * Security: login + shared nonce ('api', shared by the data-API and
	 * assistant AJAX endpoints) + manage_options capability + category
	 * whitelist + manifest-declared permission. The manifest permission is
	 * self-declared by the plugin — a convention that keeps plugins honest,
	 * not a trust boundary. The effective boundary is the manage_options
	 * capability check.
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

		// Capability check — the effective trust boundary
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
			return;
		}

		$category    = sanitize_text_field( $this->wrap_filter_input( INPUT_POST, 'category' ) );
		$tracking_id = sanitize_text_field( $this->wrap_filter_input( INPUT_POST, 'tracking_id' ) );
		$plugin_id   = sanitize_text_field( $this->wrap_filter_input( INPUT_POST, 'plugin_id' ) );
		$store       = sanitize_text_field( $this->wrap_filter_input( INPUT_POST, 'store' ) );
		$key         = sanitize_text_field( $this->wrap_filter_input( INPUT_POST, 'key' ) );

		if ( empty( $category ) || empty( $tracking_id ) || empty( $plugin_id ) ) {
			wp_send_json_error( array( 'message' => 'Missing required parameters.' ) );
			return;
		}

		// Config is site-specific — reject tracking_id=all
		if ( 'all' === $tracking_id ) {
			wp_send_json_error( array( 'message' => 'tracking_id "all" is not allowed for config_read.' ) );
			return;
		}

		// Category whitelist
		if ( ! in_array( $category, QAHM_CONFIG_READABLE_CATEGORIES, true ) ) {
			wp_send_json_error( array( 'message' => 'Category not readable: ' . $category ) );
			return;
		}

		// custom_data requires a store name
		if ( 'custom_data' === $category && empty( $store ) ) {
			wp_send_json_error( array( 'message' => 'Missing store for custom_data.' ) );
			return;
		}

		// Manifest-declared permission (self-declared by the plugin; advisory — see docblock)
		// For custom_data, permission is granted per store (not the literal 'custom_data' string)
		$permission_target = ( 'custom_data' === $category ) ? $store : $category;
		if ( ! $this->check_plugin_permission( $plugin_id, 'config_read', $permission_target ) ) {
			wp_send_json_error( array( 'message' => 'Plugin does not have config_read permission for: ' . $permission_target ) );
			return;
		}

		// Read data by category
		$data = $this->read_config_data( $category, $tracking_id, $plugin_id, $store, $key );
		wp_send_json_success( $data );
	}

	/**
	 * AJAX: Write config data for assistant plugins
	 *
	 * Security: same checks as ajax_read_config (login + shared 'api' nonce +
	 * manage_options + writable-category whitelist + manifest-declared
	 * permission), plus an audit log entry for every write attempt that
	 * passes value validation. The nonce is the shared NONCE_API ('api'),
	 * not operation-specific.
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

		// Capability check — the effective trust boundary
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
			return;
		}

		$category    = sanitize_text_field( $this->wrap_filter_input( INPUT_POST, 'category' ) );
		$tracking_id = sanitize_text_field( $this->wrap_filter_input( INPUT_POST, 'tracking_id' ) );
		$plugin_id   = sanitize_text_field( $this->wrap_filter_input( INPUT_POST, 'plugin_id' ) );
		$store       = sanitize_text_field( $this->wrap_filter_input( INPUT_POST, 'store' ) );
		$key         = sanitize_text_field( $this->wrap_filter_input( INPUT_POST, 'key' ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$value_raw = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : '';
		$value     = json_decode( $value_raw, true );
		// Issue #1228 (Stage A): optional rotate option for custom_data.
		// Format: { "max_entries": 1000, "strategy": "oldest" }
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$rotate_raw = isset( $_POST['rotate'] ) ? wp_unslash( $_POST['rotate'] ) : '';
		$rotate     = ( '' !== $rotate_raw ) ? json_decode( $rotate_raw, true ) : null;

		if ( empty( $category ) || empty( $tracking_id ) || empty( $plugin_id ) ) {
			wp_send_json_error( array( 'message' => 'Missing required parameters.' ) );
			return;
		}

		// tracking_id 'all' is not allowed for writing
		if ( 'all' === $tracking_id ) {
			wp_send_json_error( array( 'message' => 'tracking_id "all" is not allowed for config_write.' ) );
			return;
		}

		// Category whitelist
		if ( ! in_array( $category, QAHM_CONFIG_WRITABLE_CATEGORIES, true ) ) {
			wp_send_json_error( array( 'message' => 'Category not writable: ' . $category ) );
			return;
		}

		// custom_data requires a store name
		if ( 'custom_data' === $category && empty( $store ) ) {
			wp_send_json_error( array( 'message' => 'Missing store for custom_data.' ) );
			return;
		}

		// Manifest-declared permission (self-declared by the plugin; advisory — see docblock)
		// For custom_data, permission is granted per store (not the literal 'custom_data' string)
		$permission_target = ( 'custom_data' === $category ) ? $store : $category;
		if ( ! $this->check_plugin_permission( $plugin_id, 'config_write', $permission_target ) ) {
			wp_send_json_error( array( 'message' => 'Plugin does not have config_write permission for: ' . $permission_target ) );
			return;
		}

		if ( ! is_array( $value ) ) {
			wp_send_json_error( array( 'message' => 'Invalid value parameter.' ) );
			return;
		}

		// Audit log
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
		$result = $this->write_config_data( $category, $tracking_id, $plugin_id, $store, $key, $value, $rotate );
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
	 * Read a file with request-scoped memoization.
	 *
	 * Assistant manifests / lang files cannot change mid-request, so cache the
	 * raw contents. With the init-hook scan removed, in-request duplicate reads
	 * are rare; this is cheap insurance so call sites (list building, delivery,
	 * permission checks, translations) never need to care how often they read. Negative
	 * results (missing or unreadable file) are cached too. Returns the same
	 * string|false shape as file_get_contents() so call sites keep their
	 * original guards unchanged.
	 *
	 * @param string $path Absolute file path.
	 * @return string|false File contents, or false if missing/unreadable.
	 */
	private static function read_file_cached( $path ) {
		static $cache = array();
		if ( array_key_exists( $path, $cache ) ) {
			return $cache[ $path ];
		}
		$content = false;
		if ( file_exists( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- WP_Filesystem may use FTP mode.
			$content = file_get_contents( $path );
		}
		$cache[ $path ] = $content;
		return $content;
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
			$manifest_json = self::read_file_cached( $manifest_path );
			$manifest      = json_decode( (string) $manifest_json, true );
			if ( is_array( $manifest ) && isset( $manifest['permissions'][ $permission ] ) ) {
				$allowed = $manifest['permissions'][ $permission ];
				return is_array( $allowed ) && in_array( $category, $allowed, true );
			}
			return false;
		}

		// Fallback: try config.json (Legacy plugins)
		$config_path = $plugin_dir . '/config.json';
		if ( file_exists( $config_path ) ) {
			$config_json = self::read_file_cached( $config_path );
			$config      = json_decode( (string) $config_json, true );
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
	 * @param string $plugin_id   Plugin slug (required for custom_data)
	 * @param string $store Store name (required for custom_data)
	 * @param string $key         Item key (optional, custom_data returns specific key when given)
	 * @return array Data with meta information
	 */
	private function read_config_data( $category, $tracking_id, $plugin_id = '', $store = '', $key = '' ) {
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

			case 'custom_data':
				return $this->read_custom_data( $plugin_id, $store, $key );

			default:
				return array( 'items' => array() );
		}
	}

	/**
	 * Write config data by category
	 *
	 * @param string $category    Config category
	 * @param string $tracking_id Tracking ID
	 * @param string $plugin_id   Plugin slug (required for custom_data)
	 * @param string $store Store name (required for custom_data)
	 * @param string     $key         Item key (e.g. gid for goals, arbitrary key for custom_data)
	 * @param array      $value       Data to write
	 * @param array|null $rotate      Optional rotate config from manifest (Issue #1228), custom_data only.
	 *                                Expected shape: { max_entries: int, strategy: string }. Null = no rotate.
	 * @return array Result or error
	 */
	private function write_config_data( $category, $tracking_id, $plugin_id, $store, $key, $value, $rotate = null ) {
		global $qahm_data_api;

		switch ( $category ) {
			case 'goals':
				return $this->write_goals_config( $tracking_id, $key, $value );

			case 'custom_data':
				return $this->write_custom_data( $plugin_id, $store, $key, $value, $rotate );

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

		$manifest_json = self::read_file_cached( $manifest_file );
		$manifest      = json_decode( (string) $manifest_json, true );
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

		// Issue #1580（検査 段2）: パッケージ検査＝一覧に出す直前の門番。
		// 既定は「警告のみ」＝不合格でも一覧から消さず、理由を持たせて画面に出す
		// （QAHM_ASSISTANT_PKG_STRICT が真のときだけ一覧から外す）。判定は QAHM_Assistant_Schema_Validator
		// に集約（門番はホストの PHP に1人＝#1578）。検査自体が例外で落ちても一覧は壊さない。
		$pkg = array( 'valid' => true, 'strict' => false, 'errors' => array() );
		try {
			$pkg = QAHM_Assistant_Schema_Validator::validate_package( $dir, $slug );
		} catch ( \Throwable $e ) {
			$pkg = array( 'valid' => false, 'strict' => false, 'errors' => array( array( 'code' => 'E_PKG_INTERNAL', 'path' => '/', 'message' => $e->getMessage() ) ) );
		}
		if ( ! $pkg['valid'] && ! empty( $pkg['strict'] ) ) {
			return false; // ブロックモード＝一覧に出さない（既定 OFF）
		}

		return array(
			'slug'         => $slug,
			'name'         => $this->resolve_manifest_translation( isset( $manifest['name'] ) ? $manifest['name'] : $slug, $translations ),
			'description'  => $this->resolve_manifest_translation( isset( $manifest['description'] ) ? $manifest['description'] : '', $translations ),
			'author'       => $plugin_data['Author'] ?? '',
			'version'      => isset( $manifest['version'] ) ? $manifest['version'] : ( $plugin_data['Version'] ?? '' ),
			'images'       => array( 'default' => $icon_url ),
			'manifest_url' => true,
			// 段2＝パッケージ検査の結果（警告のみモードでは画面にバッジ＋理由を出す材料）
			'package'      => array(
				'valid'  => (bool) $pkg['valid'],
				'errors' => array_values( array_map( function ( $e ) {
					return array( 'code' => $e['code'], 'path' => $e['path'], 'message' => $e['message'] );
				}, $pkg['errors'] ) ),
			),
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

		$json         = self::read_file_cached( $file_path );
		$translations = json_decode( (string) $json, true );

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
	 * Build a sanitized path for a custom_data file.
	 *
	 * Both plugin_id and store are validated against a strict whitelist
	 * to prevent path traversal.
	 *
	 * @param string $plugin_id   Plugin slug
	 * @param string $store Store name
	 * @return string|false Full file path on success, false on validation failure
	 */
	private function get_custom_data_path( $plugin_id, $store ) {
		if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $plugin_id ) ) {
			return false;
		}
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $store ) ) {
			return false;
		}
		return WP_CONTENT_DIR . '/qa-zero-data/assistants/' . $plugin_id . '/' . $store . '.json';
	}

	/**
	 * Read custom_data file.
	 *
	 * @param string $plugin_id   Plugin slug
	 * @param string $store Store name
	 * @param string $key         Optional specific key to extract (empty = full object)
	 * @return array { items: mixed } Full object when key is empty, single value when key is given, null when missing
	 */
	private function read_custom_data( $plugin_id, $store, $key = '' ) {
		$path = $this->get_custom_data_path( $plugin_id, $store );
		if ( false === $path || ! $this->wrap_exists( $path ) ) {
			return array( 'items' => '' === $key ? array() : null );
		}

		$json = $this->wrap_get_contents( $path );
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		if ( '' !== $key ) {
			return array( 'items' => isset( $data[ $key ] ) ? $data[ $key ] : null );
		}
		return array( 'items' => $data );
	}

	/**
	 * Write to a custom_data file.
	 *
	 * Reads the existing file, sets the given key to the value, and writes back.
	 * Creates the parent directory if missing.
	 *
	 * @param string     $plugin_id   Plugin slug
	 * @param string     $store Store name
	 * @param string     $key         Item key
	 * @param array      $value       Value to store
	 * @param array|null $rotate      Optional rotate config from manifest (Issue #1228).
	 *                                Expected shape: { max_entries: int, strategy: string }. Null = no rotate.
	 * @return array Result with status or error
	 */
	private function write_custom_data( $plugin_id, $store, $key, $value, $rotate = null ) {
		$path = $this->get_custom_data_path( $plugin_id, $store );
		if ( false === $path ) {
			return array(
				'error'  => 'Invalid plugin_id or store.',
				'reason' => 'validation_error',
			);
		}
		if ( '' === $key ) {
			return array(
				'error'  => 'Missing key for custom_data write.',
				'reason' => 'validation_error',
			);
		}

		// Issue #1200: enforce value size limit before touching the filesystem.
		// Done early so callers fail fast with a clear reason ('size_limit_value') instead
		// of wasting work on mkdir/read/merge for a value that will be rejected.
		$value_json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
		if ( false === $value_json ) {
			// Encoding failure (invalid UTF-8 / resource type / recursion) is a validation problem,
			// not a size problem — separate reason so AI / developer can react accordingly.
			return array(
				'error'  => 'Failed to encode custom_data value as JSON.',
				'reason' => 'validation_error',
			);
		}
		if ( strlen( $value_json ) > QAHM_CUSTOM_DATA_VALUE_MAX_BYTES ) {
			return array(
				'error'  => sprintf(
					/* translators: %d: maximum value size in bytes */
					'custom_data value exceeds limit (%d bytes). Trim the value or split across multiple keys.',
					QAHM_CUSTOM_DATA_VALUE_MAX_BYTES
				),
				'reason' => 'size_limit_value',
			);
		}

		// Ensure parent directory exists (recursive).
		// wrap_mkdir() creates only a single level via $wp_filesystem->mkdir() and does not create
		// parent directories. For first-time custom_data write, the path is
		// {WP_CONTENT_DIR}/qa-zero-data/assistants/{plugin_id}/{store}.json
		// (e.g. .../qa-assistant-lp-inspector/lp_history.json) — three levels deep — so
		// wp_mkdir_p() (WP core, recursive) is required.
		// Note: the wrap_exists() short-circuit is technically redundant since wp_mkdir_p() is
		// idempotent, but kept for filesystem-layer consistency with other write paths.
		$dir = dirname( $path );
		if ( ! $this->wrap_exists( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return array(
				'error'  => 'Failed to create custom_data directory.',
				'reason' => 'server_error',
			);
		}

		// Merge with existing data if any.
		$data = array();
		if ( $this->wrap_exists( $path ) ) {
			$existing_json = $this->wrap_get_contents( $path );
			$existing      = json_decode( $existing_json, true );
			if ( is_array( $existing ) ) {
				$data = $existing;
			}
		}

		$data[ $key ] = $value;

		// Issue #1228 (Stage A): enforce key count limit with optional rotate.
		// rotate option is declared via manifest's config_write step:
		//   "rotate": { "max_entries": 1000, "strategy": "oldest" }
		// When declared and valid, oldest keys are auto-evicted to maintain the limit
		// (PHP associative arrays preserve insertion order; first key = oldest).
		// When undeclared or invalid, returns 'limit_key_count' reason so manifest
		// author can react via on_error scene.
		$rotate_max_entries = null;
		if ( is_array( $rotate ) && isset( $rotate['max_entries'] ) ) {
			// R9 validation: max_entries must be a positive integer within hard limit,
			// strategy must be in allowlist. Invalid rotate falls back to limit_key_count
			// (same error path as missing rotate) so behavior is predictable.
			$max = $rotate['max_entries'];
			if ( is_int( $max ) && $max > 0 && $max <= QAHM_CUSTOM_DATA_KEY_MAX_COUNT ) {
				$strategy = isset( $rotate['strategy'] ) ? $rotate['strategy'] : 'oldest';
				if ( in_array( $strategy, array( 'oldest' ), true ) ) {
					$rotate_max_entries = $max;
				}
			}
		}

		if ( null !== $rotate_max_entries ) {
			// rotate declared and valid — auto-evict oldest keys.
			// Existing key updates do not increase count (PHP array semantics),
			// so updates remain possible even at the limit.
			while ( count( $data ) > $rotate_max_entries ) {
				reset( $data );
				unset( $data[ key( $data ) ] );
			}
		} elseif ( count( $data ) > QAHM_CUSTOM_DATA_KEY_MAX_COUNT ) {
			// No (valid) rotate declared and key count exceeds hard limit.
			return array(
				'error'  => sprintf(
					/* translators: %d: maximum key count per store file */
					'custom_data key count exceeds limit (%d entries). Declare a rotate option (config_write.rotate.max_entries) or prune older keys.',
					QAHM_CUSTOM_DATA_KEY_MAX_COUNT
				),
				'reason' => 'limit_key_count',
			);
		}

		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

		// Issue #1200: enforce per-file size limit after merge. The merged size depends on
		// pre-existing keys, so this check has to happen post-merge and can't be moved earlier.
		if ( false === $json ) {
			// Encoding the merged data failed — surface as validation error, not size error.
			return array(
				'error'  => 'Failed to encode custom_data file as JSON after merge.',
				'reason' => 'validation_error',
			);
		}
		if ( strlen( $json ) > QAHM_CUSTOM_DATA_FILE_MAX_BYTES ) {
			return array(
				'error'  => sprintf(
					/* translators: %d: maximum file size in bytes */
					'custom_data file would exceed limit (%d bytes) after merge. Rotate / prune older entries before writing.',
					QAHM_CUSTOM_DATA_FILE_MAX_BYTES
				),
				'reason' => 'size_limit_file',
			);
		}

		$written = $this->wrap_put_contents( $path, $json );
		if ( false === $written ) {
			return array(
				'error'  => 'Failed to write custom_data file.',
				'reason' => 'server_error',
			);
		}

		return array(
			'status' => 'done',
			'key'    => $key,
		);
	}
}
