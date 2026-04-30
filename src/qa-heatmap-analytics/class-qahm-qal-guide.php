<?php
defined( 'ABSPATH' ) || exit;

$GLOBALS['qahm_qal_guide'] = new QAHM_Qal_Guide();
class QAHM_Qal_Guide extends QAHM_File_Base {

	public function __construct() {
	}

	public function get_guide_response( $version = 'latest' ) {
		$version = $this->normalize_version( $version );

		if ( ! $this->is_cached( $version ) ) {
			$fetch_result = $this->fetch_from_github( $version );
			if ( is_wp_error( $fetch_result ) ) {
				return array(
					'error'   => true,
					'message' => $fetch_result->get_error_message(),
				);
			}
		}

		$features_detail = $this->load_features_from_manifest( $version );
		return array(
			'version'         => $version,
			'api_update'      => defined( 'QAHM_API_UPDATE' ) ? QAHM_API_UPDATE : '',
			'timestamp'       => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'plugin_version'  => QAHM_PLUGIN_VERSION,
			'features'        => $this->project_features_flat( $features_detail ),
			'features_detail' => $features_detail,
			'sites'           => $this->build_sites_array(),
			'documentation'   => array(
				'source'   => "https://github.com/quarka-org/docs.qazero.com/tree/main/docs/developer-manual/api/{$version}/ai",
				'format'   => 'mixed',
				'sections' => $this->build_sections_array( $version ),
			),
		);
	}

	/**
	 * Load the rich `features` map ({enabled, since}) from the QAL validation
	 * manifest for the given version.
	 *
	 * The manifest is the single source of truth: YAML under
	 * `src/core/yaml/qal-validation-{version}.yaml`, compiled to a PHP
	 * array file by `scripts/build-manifest.php`.
	 *
	 * @param string $version API version (YYYY-MM-DD).
	 * @return array Map of feature name => {enabled: bool, since?: string}.
	 *               Empty array if the manifest is missing or has no features.
	 */
	private function load_features_from_manifest( $version ) {
		$manifest_file = dirname( __FILE__ ) . '/yaml/qal-validation-' . $version . '.php';

		if ( ! file_exists( $manifest_file ) ) {
			return array();
		}

		$manifest = include $manifest_file;

		if ( ! is_array( $manifest ) || empty( $manifest['features'] ) || ! is_array( $manifest['features'] ) ) {
			return array();
		}

		return $manifest['features'];
	}

	/**
	 * Project the rich features map ({enabled, since}) back to the legacy
	 * flat {feature_name: bool} shape for backward compatibility. Entries
	 * that are already plain booleans (from a legacy manifest) are passed
	 * through unchanged.
	 *
	 * @param array $features_detail Rich features map.
	 * @return array Flat {feature_name: bool} map.
	 */
	private function project_features_flat( $features_detail ) {
		$flat = array();
		foreach ( $features_detail as $name => $entry ) {
			if ( is_array( $entry ) ) {
				$flat[ $name ] = ! empty( $entry['enabled'] );
			} else {
				$flat[ $name ] = (bool) $entry;
			}
		}
		return $flat;
	}

	private function normalize_version( $version ) {
		if ( empty( $version ) || $version === 'latest' ) {
			return $this->get_latest_version();
		}
		if ( $version === 'oldest' ) {
			return $this->get_oldest_version();
		}
		return sanitize_text_field( $version );
	}

	/**
	 * Get available versions from the cache directory
	 *
	 * @return array Array of available version strings (YYYY-MM-DD format)
	 */
	public function get_available_versions() {
		$data_dir = $this->get_data_dir_path();
		$api_dir  = $data_dir . 'restapi/developer-manual/api/';

		// Validate that $api_dir is within $data_dir and is a directory
		if ( ! file_exists( $api_dir ) || ! is_dir( $api_dir ) ) {
			return array();
		}
		if ( $this->wrap_strpos( realpath( $api_dir ), realpath( $data_dir ) ) !== 0 ) {
			// $api_dir is not within $data_dir, possible directory traversal attempt
			return array();
		}
		if ( ! is_readable( $api_dir ) ) {
			return array();
		}

		$versions = array();
		$dirs     = @scandir( $api_dir );

		if ( $dirs === false ) {
			return array();
		}

		foreach ( $dirs as $dir ) {
			if ( $dir === '.' || $dir === '..' ) {
				continue;
			}

			$full_path = $api_dir . $dir;
			if ( ! is_dir( $full_path ) ) {
				continue;
			}

			if ( strlen( $dir ) === 10
				&& $dir[4] === '-' && $dir[7] === '-'
				&& ctype_digit( substr( $dir, 0, 4 ) )
				&& ctype_digit( substr( $dir, 5, 2 ) )
				&& ctype_digit( substr( $dir, 8, 2 ) )
			) {
				$versions[] = $dir;
			}
		}

		sort( $versions );

		return $versions;
	}

	/**
	 * Get the latest stable version
	 *
	 * @return string Latest version string or default version
	 */
	public function get_latest_version() {
		$versions = $this->get_available_versions();

		if ( empty( $versions ) ) {
			return defined( 'QAHM_API_VERSION' ) ? QAHM_API_VERSION : '2025-10-20';
		}

		return end( $versions );
	}

	/**
	 * Get the oldest stable version
	 *
	 * @return string Oldest version string or default version
	 */
	public function get_oldest_version() {
		$versions = $this->get_available_versions();

		if ( empty( $versions ) ) {
			return defined( 'QAHM_API_VERSION' ) ? QAHM_API_VERSION : '2025-10-20';
		}

		return reset( $versions );
	}

	private function build_sites_array() {
		global $qahm_options_functions;

		$sitemanage = $qahm_options_functions->get_sitemanage();
		if ( empty( $sitemanage ) || ! is_array( $sitemanage ) ) {
			return array();
		}

		$sites = array();
		foreach ( $sitemanage as $site ) {
			if ( ! is_array( $site ) ) {
				continue;
			}

			$tracking_id = isset( $site['tracking_id'] ) ? $site['tracking_id'] : '';
			if ( empty( $tracking_id ) ) {
				continue;
			}

			$sites[] = array(
				'tracking_id'         => $tracking_id,
				'domain'              => isset( $site['url'] ) ? $site['url'] : '',
				'name'                => isset( $site['name'] ) ? $site['name'] : '',
				'default'             => isset( $site['default'] ) ? $site['default'] : false,
				'data_available_from' => isset( $site['data_available_from'] ) ? $site['data_available_from'] : '',
				'timezone'            => isset( $site['timezone'] ) ? $site['timezone'] : '',
				'goals'               => $this->build_goals_array( $tracking_id ),
			);
		}

		return $sites;
	}

	private function build_goals_array( $tracking_id ) {
		global $qahm_data_api;

		$goals_ary = $qahm_data_api->get_goals_preferences( $tracking_id );
		if ( empty( $goals_ary ) || ! is_array( $goals_ary ) ) {
			return array();
		}

		$goals = array();
		foreach ( $goals_ary as $goal_id => $goal ) {
			if ( ! is_array( $goal ) ) {
				continue;
			}

			$goals[] = array(
				'id'        => $goal_id,
				'name'      => isset( $goal['gtitle'] ) ? $goal['gtitle'] : '',
				'type'      => isset( $goal['gtype'] ) ? $goal['gtype'] : '',
				'condition' => $this->build_goal_condition( $goal ),
			);
		}

		return $goals;
	}

	private function build_goal_condition( $goal ) {
		if ( ! is_array( $goal ) ) {
			return array();
		}

		$gtype = isset( $goal['gtype'] ) ? $goal['gtype'] : '';

		switch ( $gtype ) {
			case 'gtype_page':
				return array(
					'url'   => isset( $goal['g_goalpage'] ) ? $goal['g_goalpage'] : '',
					'match' => isset( $goal['g_pagematch'] ) ? $goal['g_pagematch'] : '',
				);

			case 'gtype_click':
				return array(
					'page'     => isset( $goal['g_clickpage'] ) ? $goal['g_clickpage'] : '',
					'selector' => isset( $goal['g_clickselector'] ) ? $goal['g_clickselector'] : '',
				);

			case 'gtype_event':
				return array(
					'type'     => isset( $goal['g_eventtype'] ) ? $goal['g_eventtype'] : '',
					'selector' => isset( $goal['g_eventselector'] ) ? $goal['g_eventselector'] : '',
				);

			default:
				return array();
		}
	}

	private function get_cache_dir( $version ) {
		$data_dir = $this->get_data_dir_path();
		return $data_dir . 'restapi/developer-manual/api/' . $version . '/';
	}

	private function is_cached( $version ) {
		$cache_dir = $this->get_cache_dir( $version );
		if ( ! file_exists( $cache_dir ) || ! is_dir( $cache_dir ) ) {
			return false;
		}
		// Guard against stale caches left over from an older file layout
		// (pre-2026-04-14 the guide cached index.md / endpoints.md / materials.md / qal.md).
		// We require at least the current AI-facing README.md to be present; otherwise
		// fetch_from_github() must run to populate the new file set.
		return file_exists( $cache_dir . 'README.md' );
	}

	private function fetch_from_github( $version ) {
		$data_dir = $this->get_data_dir_path();

		$restapi_dir = $data_dir . 'restapi/';
		if ( ! $this->wrap_mkdir( $restapi_dir ) ) {
			return new WP_Error( 'cache_dir_error', __( 'Failed to create restapi directory', 'qa-heatmap-analytics' ) );
		}

		$developer_dir = $restapi_dir . 'developer-manual/';
		if ( ! $this->wrap_mkdir( $developer_dir ) ) {
			return new WP_Error( 'cache_dir_error', __( 'Failed to create developer-manual directory', 'qa-heatmap-analytics' ) );
		}

		$api_dir = $developer_dir . 'api/';
		if ( ! $this->wrap_mkdir( $api_dir ) ) {
			return new WP_Error( 'cache_dir_error', __( 'Failed to create api directory', 'qa-heatmap-analytics' ) );
		}

		$version_dir = $api_dir . $version . '/';
		if ( ! $this->wrap_mkdir( $version_dir ) ) {
			return new WP_Error(
				'cache_dir_error',
				sprintf(
				/* translators: %s: version number */
					__( 'Failed to create version directory: %s', 'qa-heatmap-analytics' ),
					$version
				)
			);
		}

		// AI-facing subset only. Human-readable pages are intentionally NOT fetched;
		// the /guide endpoint is optimized for AI / MCP consumers who only need
		// the machine-readable spec (YAML) plus a concise instruction README.
		$files    = array( 'README.md', 'materials.yaml', 'qal-validation.yaml' );
		$base_url = "https://raw.githubusercontent.com/quarka-org/docs.qazero.com/main/docs/developer-manual/api/{$version}/ai/";

		foreach ( $files as $file ) {
			$url      = $base_url . $file;
			$response = wp_remote_get( $url, array( 'timeout' => 30 ) );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			if ( $status_code !== 200 ) {
				return new WP_Error(
					'github_fetch_error',
					sprintf(
					/* translators: 1: file name, 2: HTTP status code */
						__( 'Failed to fetch %1$s from GitHub. Status code: %2$s', 'qa-heatmap-analytics' ),
						$file,
						$status_code
					)
				);
			}

			$content = wp_remote_retrieve_body( $response );
			if ( empty( $content ) ) {
				return new WP_Error(
					'github_fetch_error',
					sprintf(
					/* translators: %s: file name */
						__( 'Empty content returned for %s', 'qa-heatmap-analytics' ),
						$file
					)
				);
			}

			$file_path = $version_dir . $file;
			if ( ! $this->wrap_put_contents( $file_path, $content ) ) {
				return new WP_Error(
					'cache_write_error',
					sprintf(
					/* translators: %s: file name */
						__( 'Failed to write %s to cache directory', 'qa-heatmap-analytics' ),
						$file
					)
				);
			}
		}

		return true;
	}

	private function build_sections_array( $version ) {
		$cache_dir = $this->get_cache_dir( $version );
		$sections  = array();

		// Section layout for the AI-facing guide: one concise instruction
		// README, then the two machine-readable YAML specs. Clients SHOULD
		// treat README as prose, and materials / qal-validation as YAML.
		$file_mappings = array(
			'README.md'            => array(
				'category' => 'instructions',
				'format'   => 'markdown',
				'title'    => __( 'AI Instructions (how to build a QAL query)', 'qa-heatmap-analytics' ),
			),
			'materials.yaml'       => array(
				'category' => 'spec',
				'format'   => 'yaml',
				'title'    => __( 'Materials Manifest (machine-readable)', 'qa-heatmap-analytics' ),
			),
			'qal-validation.yaml'  => array(
				'category' => 'spec',
				'format'   => 'yaml',
				'title'    => __( 'QAL Validation Manifest (machine-readable)', 'qa-heatmap-analytics' ),
			),
		);

		foreach ( $file_mappings as $file => $meta ) {
			$file_path = $cache_dir . $file;
			if ( ! $this->wrap_exists( $file_path ) ) {
				continue;
			}

			$content = $this->wrap_get_contents( $file_path );
			if ( $content === false ) {
				continue;
			}

			$sections[] = array(
				'category' => $meta['category'],
				'format'   => $meta['format'],
				'file'     => $file,
				'title'    => $meta['title'],
				'content'  => $content,
			);
		}

		return $sections;
	}
}
