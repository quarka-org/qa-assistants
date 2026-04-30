<?php
/**
 * Assistant Legacy Handler
 *
 * FROZEN: このファイルは凍結済み。今後一切の機能追加・変更を行わないこと。
 * レガシー（main.php方式）アシスタントのセッション管理・状態遷移・クラスインスタンス化を担当。
 * 新規アシスタントはマニフェスト方式を使用すること。
 *
 * @package qa_heatmap_analytics
 */

defined( 'ABSPATH' ) || exit;

$GLOBALS['qahm_assistant_legacy_handler'] = new QAHM_Assistant_Legacy_Handler();

class QAHM_Assistant_Legacy_Handler extends QAHM_File_Data {

	public const NONCE_API = 'api';

	/**
	 * AJAX: Connect to a legacy assistant (session management + state)
	 */
	public function ajax_connect_assistant() {
		if ( ! is_user_logged_in() ) {
			wp_die( 'you don not have privilege to access this page.' );
		}
		// Verify nonce and check maintenance status
		$nonce = $this->wrap_filter_input( INPUT_POST, 'nonce' );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_API ) ) {
			http_response_code( 401 );
			die( 'nonce error' );
		}
		session_start();

		$assistant_slug = $this->wrap_filter_input( INPUT_POST, 'assistant_slug' );
		$state          = $this->wrap_filter_input( INPUT_POST, 'state' );
		$free           = $this->wrap_filter_input( INPUT_POST, 'free' );
		$tracking_id    = $this->wrap_filter_input( INPUT_POST, 'tracking_id' );

		if ( 'start' === $state ) {
			session_unset();
		}
		if ( ! $state ) {
			$state = 'start';
		}

		if ( $free ) {
			$class_name                              = $this->make_assistant_class_name( $assistant_slug );
			$_SESSION[ $class_name ]['session_free'] = $free;
		}

		// Return the JSON response
		$response = $this->connect_assistant( $assistant_slug, $state, $tracking_id );
		if ( ! $response ) {
			http_response_code( 404 );
			die( 'assistant not found' );
		}
		wp_send_json_success( $response );
	}

	/**
	 * Connect to a legacy assistant: require main.php, instantiate, run progress_story
	 *
	 * @param string $assistant_slug  Plugin slug
	 * @param string $state           State identifier
	 * @param string $tracking_id     Tracking ID
	 * @return array|false
	 */
	public function connect_assistant( $assistant_slug, $state, $tracking_id = 'all' ) {
		global $qahm_assistant_manager;
		$assistant_config = $qahm_assistant_manager->get_assistant( $assistant_slug );
		if ( ! isset( $assistant_config[ $assistant_slug ]['assistant_file'] ) ) {
			return false;
		}
		require_once $assistant_config[ $assistant_slug ]['assistant_file'];
		$assistant_dir_name = dirname( $assistant_config[ $assistant_slug ]['assistant_file'] );
		$class_name         = $this->make_assistant_class_name( $assistant_slug );

		// Check if class exists and provide detailed error information
		if ( ! class_exists( $class_name ) ) {
			$error_info = array(
				'error_type'       => 'class_not_found',
				'expected_class'   => $class_name,
				'plugin_slug'      => $assistant_slug,
				'plugin_directory' => $assistant_dir_name,
				'message'          => "Assistant class '{$class_name}' not found",
			);
			wp_send_json_error( $error_info );
		}

		if ( isset( $_SESSION[ $class_name ] ) ) {
			$assistant_class = new $class_name( $tracking_id, $assistant_dir_name, $state, $_SESSION[ $class_name ] );
		} else {
			$assistant_class = new $class_name( $tracking_id, $assistant_dir_name, $state );
		}
		$assistant_class->progress_story();

		$response = array(
			'execute'    => $assistant_class->execute,
			'debug_logs' => $assistant_class->debug_logs ?? array(),
		);
		// Save variables to session
		foreach ( get_object_vars( $assistant_class ) as $key => $value ) {
			if ( $this->wrap_strpos( $key, 'session_' ) === 0 ) {
				$_SESSION[ $class_name ][ $key ] = $value;
			}
		}
		return $response;
	}

	/**
	 * Convert assistant slug to PHP class name
	 *
	 * @param string $assistant_slug Plugin slug
	 * @return string
	 */
	public function make_assistant_class_name( $assistant_slug ) {
		// Convert hyphens to underscores
		$class_name = str_replace( '-', '_', $assistant_slug );

		$class_name = str_replace( 'qa_assistant_', 'QAHM_Assistant_', $class_name );

		// Capitalize first letter of each word
		$class_name = ucwords( $class_name, '_' );

		return $class_name;
	}

	/**
	 * Resolve dot-notation legacy translation key
	 *
	 * @param string $key          Translation key (e.g. "meta.name")
	 * @param array  $translations Translations array
	 * @return string
	 */
	public function resolve_translation( $key, $translations ) {
		if ( $this->wrap_strpos( $key, '.' ) !== false ) {
			list( $section, $subkey ) = $this->wrap_explode( '.', $key, 2 );
			if ( isset( $translations[ $section ][ $subkey ] ) ) {
				return $translations[ $section ][ $subkey ];
			}
		}
		return $key;
	}

	/**
	 * Build a legacy assistant entry from its directory
	 *
	 * Reads config.json, loads legacy translations, resolves image URLs.
	 *
	 * @param string $dir  Full path to assistant plugin directory
	 * @param string $slug Plugin slug
	 * @return array|false Assistant data array or false on failure
	 */
	public function build_legacy_assistant( $dir, $slug ) {
		$assistant_file = $dir . '/main.php';
		if ( ! file_exists( $assistant_file ) ) {
			return false;
		}

		// Get plugin data from header instead of config.json
		$plugin_data = get_plugin_data( $dir . '/' . $slug . '.php', false, true );

		// Get the config file for Assistant-specific settings only
		$config_file = $dir . '/config.json';
		if ( ! file_exists( $config_file ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- WP_Filesystem may use FTP mode which breaks in non-Direct environments.
		$config = json_decode( file_get_contents( $config_file ), true );
		if ( ! $config ) {
			return false;
		}

		// Create the assistant array using plugin header data
		$assistant = array(
			'assistant_file' => $assistant_file,
			'slug'           => $slug,
			'name'           => $config['name'] ?? $plugin_data['Name'],
			'description'    => $config['description'] ?? $plugin_data['Description'],
			'author'         => $config['author'] ?? $plugin_data['Author'],
			'version'        => $plugin_data['Version'] ?? '',
			'images'         => $config['images'] ?? '',
		);

		// Get the character images
		if ( $config['images'] ) {
			$relative_dir = str_replace( WP_CONTENT_DIR, '', $dir );
			foreach ( $config['images'] as $key => $image ) {
				$assistant['images'][ $key ] = content_url( $relative_dir . '/' . $image );
			}
		}

		// Load legacy translations
		$translations = $this->load_legacy_translations( $dir );

		$assistant['name']        = $this->resolve_translation( $assistant['name'], $translations );
		$assistant['description'] = $this->resolve_translation( $assistant['description'], $translations );
		$assistant['author']      = $this->resolve_translation( $assistant['author'], $translations );

		return $assistant;
	}

	/**
	 * Load legacy translations (translations-{locale}.json / translations.json)
	 *
	 * @param string $dir Plugin directory path
	 * @return array
	 */
	private function load_legacy_translations( $dir ) {
		$locale    = get_locale();
		$file_path = $dir . "/translations-{$locale}.json";

		// Fallback (e.g. translations-en.json)
		if ( ! file_exists( $file_path ) ) {
			$lang      = $this->wrap_substr( $locale, 0, 2 );
			$file_path = $dir . "/translations-{$lang}.json";
		}

		// Further fallback (translations.json)
		if ( ! file_exists( $file_path ) ) {
			$file_path = $dir . '/translations.json';
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
}
