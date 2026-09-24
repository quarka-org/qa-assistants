<?php
defined( 'ABSPATH' ) || exit;

$GLOBALS['qahm_qal_guide'] = new QAHM_Qal_Guide();
class QAHM_Qal_Guide extends QAHM_File_Base {

	public function __construct() {
	}

	public function get_guide_response( $version = 'latest' ) {
		$version = $this->normalize_version( $version );

		$features_detail = $this->load_features_from_manifest( $version );

		// Gate version-specific response shape: only include observed_events
		// when the version actually advertises datalayer_observed_events.
		// Older versions (e.g. 2025-10-20) skip the Layer 2 manifest walk
		// entirely — this is both capability-negotiation correctness and a
		// cheap perf gate (no FS glob × tenants for clients on old versions).
		$include_observed = ! empty( $features_detail['datalayer_observed_events']['enabled'] );

		return array(
			'version'         => $version,
			'api_update'      => defined( 'QAHM_API_UPDATE' ) ? QAHM_API_UPDATE : '',
			'timestamp'       => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'plugin_version'  => QAHM_PLUGIN_VERSION,
			'features'        => $this->project_features_flat( $features_detail ),
			'features_detail' => $features_detail,
			'sites'           => $this->build_sites_array( $include_observed ),
			'documentation'   => array(
				'source'   => "https://docs.qazero.com/docs/developer-manual/api/{$version}/ai",
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
	 * Get available versions from the bundled src/core/yaml/ directory.
	 *
	 * The plugin ships compiled `qal-validation-{YYYY-MM-DD}.php` files; their
	 * existence is the source of truth for which API versions this plugin
	 * supports. We deliberately scan the bundled `.php` (not `.yaml`) because
	 * /guide only reads the compiled form (see §3.8 of T86 handover) and
	 * `build-manifest.php` always co-generates both.
	 *
	 * @return array Array of available version strings (YYYY-MM-DD format).
	 */
	public function get_available_versions() {
		$yaml_dir = dirname( __FILE__ ) . '/yaml/';

		if ( ! is_dir( $yaml_dir ) || ! is_readable( $yaml_dir ) ) {
			return array();
		}

		$files = @scandir( $yaml_dir );
		if ( $files === false ) {
			return array();
		}

		$prefix       = 'qal-validation-';
		$suffix       = '.php';
		$prefix_len   = strlen( $prefix );
		$suffix_len   = strlen( $suffix );
		$version_len  = 10; // YYYY-MM-DD
		$expected_len = $prefix_len + $version_len + $suffix_len;

		$versions = array();
		foreach ( $files as $file ) {
			if ( strlen( $file ) !== $expected_len ) {
				continue;
			}
			if ( strpos( $file, $prefix ) !== 0 ) {
				continue;
			}
			if ( substr( $file, -$suffix_len ) !== $suffix ) {
				continue;
			}

			$ver = substr( $file, $prefix_len, $version_len );
			if ( strlen( $ver ) === 10
				&& $ver[4] === '-' && $ver[7] === '-'
				&& ctype_digit( substr( $ver, 0, 4 ) )
				&& ctype_digit( substr( $ver, 5, 2 ) )
				&& ctype_digit( substr( $ver, 8, 2 ) )
			) {
				$versions[] = $ver;
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

	private function build_sites_array( $include_observed = true ) {
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

			$entry = array(
				'tracking_id'         => $tracking_id,
				'domain'              => isset( $site['url'] ) ? $site['url'] : '',
				'name'                => isset( $site['name'] ) ? $site['name'] : '',
				'default'             => isset( $site['default'] ) ? $site['default'] : false,
				'data_available_from' => isset( $site['data_available_from'] ) ? $site['data_available_from'] : '',
				'timezone'            => isset( $site['timezone'] ) ? $site['timezone'] : '',
				'goals'               => $this->build_goals_array( $tracking_id ),
			);
			if ( $include_observed ) {
				$entry['observed_events'] = $this->build_observed_events( $tracking_id );
			}
			$sites[] = $entry;
		}

		// Append the cross-tenant aggregated entry (tracking_id='all') so AI
		// clients can ask "what events have we ever observed across the whole
		// account?". This is the same Layer 2 manifest set the nightly cron
		// builds at report/all/columns-db/events/. Schema-equivalent to the
		// per-site entries above so AI clients can iterate sites uniformly.
		$all_entry = array(
			'tracking_id'         => 'all',
			'domain'              => '',
			'name'                => '',
			'default'             => false,
			'data_available_from' => '',
			'timezone'            => '',
			'goals'               => array(),
		);
		if ( $include_observed ) {
			$all_entry['observed_events'] = $this->build_observed_events( 'all' );
		}
		$sites[] = $all_entry;

		return $sites;
	}

	/**
	 * Build the `observed_events` map for one tracking_id by reading the
	 * Layer 2 dataLayer event manifests that the nightly ColumnDB cron
	 * publishes under `report/{tracking_id}/columns-db/events/{ev_dir}/manifest.json.php`.
	 *
	 * NOTE (T86 §3.2 — three-layer boundary):
	 *   This is *not* a QAL query. The dataLayer schema is physical storage
	 *   metadata that QAL Executor/Material/Storage cannot express (the
	 *   `calc` six functions aggregate values; they do not enumerate schema).
	 *   `/guide` therefore acts as an "environment metadata reference layer"
	 *   — the same pattern as `build_goals_array()` reading WP options —
	 *   and reads the manifests directly. Adding QAL layers here would
	 *   be a category error.
	 *
	 * Degrade policy (§7.5): if a manifest file or its expected fields are
	 * missing/corrupt, skip *that one event* (not the whole tenant) so a
	 * single bad manifest cannot blank out the schema view.
	 *
	 * @param string $tracking_id tracking_id or 'all'.
	 * @return array { event_display_name => { material, columns } }.
	 */
	private function build_observed_events( $tracking_id ) {
		global $qahm_log;

		if ( empty( $tracking_id ) ) {
			return array();
		}

		$data_dir   = $this->get_data_dir_path();
		$events_dir = $data_dir . 'report/' . $tracking_id . '/columns-db/events/';

		if ( ! is_dir( $events_dir ) ) {
			return array();
		}

		$ev_subdirs = glob( $events_dir . '*', GLOB_ONLYDIR );
		if ( empty( $ev_subdirs ) ) {
			return array();
		}

		$observed = array();
		foreach ( $ev_subdirs as $ev_subdir ) {
			$manifest_path = $ev_subdir . '/manifest.json.php';
			$manifest      = $this->load_event_manifest( $manifest_path );

			// Parse failure / missing file: skip this event only.
			if ( empty( $manifest ) ) {
				if ( $qahm_log && $this->wrap_exists( $manifest_path ) ) {
					$qahm_log->info( 'QAL /guide: failed to parse event manifest ' . $manifest_path );
				}
				continue;
			}

			// `display_name` is the canonical event_name for the API surface
			// (see §3.4). dict-event-names.php is intentionally not touched.
			if ( empty( $manifest['display_name'] ) ) {
				if ( $qahm_log ) {
					$qahm_log->info( 'QAL /guide: manifest missing display_name at ' . $manifest_path );
				}
				continue;
			}

			$display_name = (string) $manifest['display_name'];

			// Skip events whose display_name is not QAL-safe — i.e. cannot
			// appear inside an `events.{name}` material reference, which the
			// validator restricts to [A-Za-z0-9_]+ (see qal-validation
			// `material.pattern`). Raw dataLayer event names from qtag.js
			// are not restricted at the source, so a manifest *can* hold
			// names with ':', '/', '-', etc. Surfacing them in /guide would
			// advertise a material the /query validator immediately rejects.
			if ( ! $this->is_qal_safe_event_name( $display_name ) ) {
				if ( $qahm_log ) {
					$qahm_log->info( 'QAL /guide: skip non-QAL-safe display_name (' . $display_name . ') at ' . $manifest_path );
				}
				continue;
			}

			$columns = isset( $manifest['columns'] ) && is_array( $manifest['columns'] )
				? $manifest['columns']
				: array();

			$observed[ $display_name ] = array(
				'material' => 'events.' . $display_name,
				'columns'  => $columns,
			);
		}

		return $observed;
	}

	/**
	 * Returns true iff $name matches the QAL validator's `events.{name}`
	 * constraint (`[A-Za-z0-9_]+`). Implemented with strspn instead of
	 * preg_match per the project's no-preg_match rule.
	 *
	 * @param string $name Candidate event name.
	 * @return bool
	 */
	private function is_qal_safe_event_name( $name ) {
		if ( $name === '' ) {
			return false;
		}
		$allowed = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_';
		return strspn( $name, $allowed ) === strlen( $name );
	}

	/**
	 * Read a Layer 2 manifest.json.php file (PHP-header guarded JSON).
	 *
	 * Mirrors `class-qahm-columndb-cron.php::load_json_php()` but kept as a
	 * local private helper to avoid /guide reaching into cron internals.
	 *
	 * @param string $path Manifest file path.
	 * @return array Decoded array; empty array on missing/invalid input.
	 */
	private function load_event_manifest( $path ) {
		if ( ! $this->wrap_exists( $path ) ) {
			return array();
		}

		$raw = $this->wrap_get_contents( $path );
		if ( $raw === false || $raw === '' ) {
			return array();
		}

		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : array();
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

			// #1345: dataLayer の値によるゴール。値は改行区切りの複数指定なので配列で返す。
			case 'gtype_dlevent':
				$dlvalues = isset( $goal['g_dlvalues'] ) ? (string) $goal['g_dlvalues'] : '';
				$dlvalues = str_replace( array( "\r\n", "\r" ), "\n", $dlvalues );
				$values   = array();
				foreach ( explode( "\n", $dlvalues ) as $line ) {
					$line = trim( $line );
					if ( '' !== $line ) {
						$values[] = $line;
					}
				}
				return array(
					'datalayer_key' => isset( $goal['g_dlkey'] ) && '' !== $goal['g_dlkey'] ? $goal['g_dlkey'] : QAHM_DLEVENT_DEFAULT_KEY,
					'values'        => $values,
					'match'         => 'exact',
				);

			default:
				return array();
		}
	}

	/**
	 * Build the documentation sections by reading the bundled YAML and README
	 * directly from `src/core/yaml/` — no GitHub fetch, no cache layer.
	 *
	 * The compiled `.php` form is used elsewhere for executor consumption;
	 * here we deliberately return the raw YAML / Markdown text so AI clients
	 * receive exactly what `docs.qazero.com` publishes as the spec.
	 */
	private function build_sections_array( $version ) {
		$yaml_dir = dirname( __FILE__ ) . '/yaml/';
		$sections = array();

		// Section layout for the AI-facing guide: one concise instruction
		// README, then the two machine-readable YAML specs. Clients SHOULD
		// treat README as prose, and materials / qal-validation as YAML.
		//
		// README and materials-manifest are living documents: a single bundled
		// file each, read by a fixed name with no version interpolation. Their
		// catalogue (which materials/fields exist, how to write a query) is
		// stable across versions, and any field added later carries a `since:`
		// tag instead of forking a per-version copy. Because the filename is
		// fixed, the "no file for this version" condition that previously
		// dropped the README section can no longer occur, so no fallback is
		// needed. Only qal-validation — the unit of breaking change — is read
		// per-version (`qal-validation-{version}.yaml`).
		$file_mappings = array(
			array(
				'local'    => 'README.md',
				'category' => 'instructions',
				'format'   => 'markdown',
				'file'     => 'README.md',
				'title'    => __( 'AI Instructions (how to build a QAL query)', 'qa-heatmap-analytics' ),
			),
			array(
				'local'    => 'materials-manifest.yaml',
				'category' => 'spec',
				'format'   => 'yaml',
				'file'     => 'materials.yaml',
				'title'    => __( 'Materials Manifest (machine-readable)', 'qa-heatmap-analytics' ),
			),
			array(
				'local'    => "qal-validation-{$version}.yaml",
				'category' => 'spec',
				'format'   => 'yaml',
				'file'     => 'qal-validation.yaml',
				'title'    => __( 'QAL Validation Manifest (machine-readable)', 'qa-heatmap-analytics' ),
			),
		);

		foreach ( $file_mappings as $meta ) {
			$file_path = $yaml_dir . $meta['local'];
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
				'file'     => $meta['file'],
				'title'    => $meta['title'],
				'content'  => $content,
			);
		}

		return $sections;
	}
}
