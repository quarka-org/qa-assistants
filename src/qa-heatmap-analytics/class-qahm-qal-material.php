<?php
/**
 * QAL Material Class
 *
 * Provides material definition retrieval from the materials manifest.
 * The manifest is auto-generated from materials-manifest-{version}.yaml.
 * Run: php scripts/build-manifest.php {version}
 *
 * @package qa_heatmap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QAHM_Qal_Material extends QAHM_File_Base {

	/**
	 * Master resolver map: ID column => [ accessor, id_key, column mappings ]
	 *
	 * Each entry defines:
	 *   'accessor'  - Static method on QAHM_DB_Functions to call
	 *   'id_key'    - The primary key column name in DB results
	 *   'columns'   - Map of material_column => DB result column name
	 *
	 * device_id is special: uses fixed mapping instead of DB query
	 */
	const MASTER_RESOLVER_MAP = array(
		'page_id' => array(
			'accessor' => 'get_qa_pages',
			'id_key'   => 'page_id',
			'columns'  => array(
				'url'               => 'url',
				'title'             => 'title',
				'page_type'         => 'page_type',
				'page_fetch_status' => 'page_fetch_status',
				'is_article'        => 'is_article',
				'is_product'        => 'is_product',
				'is_list'           => 'is_list',
				'is_form'           => 'is_form',
				'is_trust_info'     => 'is_trust_info',
				'is_faq'            => 'is_faq',
				'is_landing'        => 'is_landing',
				'is_search'         => 'is_search',
				'is_account'        => 'is_account',
				'is_cart'           => 'is_cart',
				'is_checkout'       => 'is_checkout',
				'is_confirm'        => 'is_confirm',
				'is_thanks'         => 'is_thanks',
				'is_top_page'       => 'is_top_page',
				'is_event'          => 'is_event',
				'is_recipe'         => 'is_recipe',
				'is_job'            => 'is_job',
				'is_video'          => 'is_video',
				'is_howto'          => 'is_howto',
				'is_qa_forum'       => 'is_qa_forum',
			),
		),
		'prev_page_id' => array(
			'accessor' => 'get_qa_pages',
			'id_key'   => 'page_id',
			'columns'  => array(
				'prev_url'   => 'url',
				'prev_title' => 'title',
			),
		),
		'next_page_id' => array(
			'accessor' => 'get_qa_pages',
			'id_key'   => 'page_id',
			'columns'  => array(
				'next_url'   => 'url',
				'next_title' => 'title',
			),
		),
		'source_id' => array(
			'accessor' => 'get_utm_sources',
			'id_key'   => 'source_id',
			'columns'  => array(
				'utm_source'    => 'utm_source',
				'source_domain' => 'source_domain',
				'referrer'      => 'referer',
				'utm_term'      => 'utm_term',
			),
		),
		'medium_id' => array(
			'accessor' => 'get_utm_media',
			'id_key'   => 'medium_id',
			'columns'  => array(
				'utm_medium' => 'utm_medium',
			),
		),
		'campaign_id' => array(
			'accessor' => 'get_utm_campaigns',
			'id_key'   => 'campaign_id',
			'columns'  => array(
				'utm_campaign' => 'utm_campaign',
			),
		),
		'content_id' => array(
			'accessor' => 'get_utm_content',
			'id_key'   => 'content_id',
			'columns'  => array(
				'utm_content' => 'utm_content',
			),
		),
		'reader_id' => array(
			'accessor' => 'get_qa_readers',
			'id_key'   => 'reader_id',
			'columns'  => array(
				'os'           => 'UAos',
				'browser'      => 'UAbrowser',
				'language'     => 'language',
				'country_code' => 'country_code',
				'original_id'  => 'original_id',
				'ua'           => '_composite_ua',
			),
		),
		'device_id' => array(
			'accessor' => null,
			'id_key'   => 'device_id',
			'columns'  => array(
				'device_type' => '_fixed_device_type',
			),
		),
		'query_id' => array(
			'accessor' => '_gsc_query_log',
			'id_key'   => 'query_id',
			'columns'  => array(
				'keyword' => 'keyword',
			),
		),
		'age_bracket' => array(
			'accessor' => null,
			'id_key'   => 'age_bracket',
			'columns'  => array(
				'age_label' => '_fixed_age_bracket',
			),
		),
		'gender' => array(
			'accessor' => null,
			'id_key'   => 'gender',
			'columns'  => array(
				'gender_label' => '_fixed_gender',
			),
		),
		'country_id' => array(
			'accessor' => null,
			'id_key'   => 'country_id',
			'columns'  => array(
				'country_code' => '_fixed_country_code',
			),
		),
		'region_id' => array(
			'accessor' => null,
			'id_key'   => 'region_id',
			'columns'  => array(
				'region_name' => '_fixed_region_name',
			),
		),
	);

	/**
	 * Fixed device_id to device_type mapping
	 */
	const DEVICE_TYPE_MAP = array(
		1 => 'PC',
		2 => 'tablet',
		3 => 'SP',
	);

	/**
	 * Fixed age_bracket to age label mapping
	 */
	const AGE_BRACKET_MAP = array(
		0 => 'unknown',
		1 => '18-24',
		2 => '25-34',
		3 => '35-44',
		4 => '45-54',
		5 => '55-64',
		6 => '65+',
	);

	/**
	 * Fixed gender ID to gender label mapping
	 */
	const GENDER_MAP = array(
		0 => 'unknown',
		1 => 'male',
		2 => 'female',
	);

	/**
	 * Fixed region_id to region name mapping (Japanese prefectures)
	 */
	const REGION_MAP = array(
		0 => 'other',
		1 => 'Hokkaido', 2 => 'Aomori', 3 => 'Iwate', 4 => 'Miyagi',
		5 => 'Akita', 6 => 'Yamagata', 7 => 'Fukushima',
		8 => 'Ibaraki', 9 => 'Tochigi', 10 => 'Gunma', 11 => 'Saitama',
		12 => 'Chiba', 13 => 'Tokyo', 14 => 'Kanagawa',
		15 => 'Niigata', 16 => 'Toyama', 17 => 'Ishikawa', 18 => 'Fukui',
		19 => 'Yamanashi', 20 => 'Nagano',
		21 => 'Gifu', 22 => 'Shizuoka', 23 => 'Aichi', 24 => 'Mie',
		25 => 'Shiga', 26 => 'Kyoto', 27 => 'Osaka', 28 => 'Hyogo',
		29 => 'Nara', 30 => 'Wakayama',
		31 => 'Tottori', 32 => 'Shimane', 33 => 'Okayama', 34 => 'Hiroshima', 35 => 'Yamaguchi',
		36 => 'Tokushima', 37 => 'Kagawa', 38 => 'Ehime', 39 => 'Kochi',
		40 => 'Fukuoka', 41 => 'Saga', 42 => 'Nagasaki', 43 => 'Kumamoto',
		44 => 'Oita', 45 => 'Miyazaki', 46 => 'Kagoshima', 47 => 'Okinawa',
	);

	/**
	 * T133: Month bucket virtual column.
	 *
	 * QAL has no way to round a date down to a month (calc is a fixed function whitelist and
	 * transform has no date granularity), so a monthly breakdown is only expressible by exposing
	 * the bucket as a column. Declared `virtual: true` for allpv and gsc in materials-manifest.yaml.
	 *
	 * The source of the row's date differs per material:
	 *  - allpv: access_time (a real column, present on both the columndb and view_pv paths)
	 *  - gsc:   no date exists on the row at all, so Storage injects the date of the per-day
	 *           column file it read the row from (see scan_and_extract).
	 *
	 * Both are cut with the site clock, which is the same clock the per-day files are bucketed
	 * with, so month boundaries agree with the day partitioning.
	 */
	const TIME_BUCKET_COLUMN     = 'month';
	const TIME_BUCKET_SOURCE_MAP = array(
		'allpv' => 'access_time',
		// 注入する側（Storage）が名前の持ち主。ここで文字列を書き直すと片側だけ変わる
		'gsc'   => QAHM_Qal_Storage::DATE_YMD_COLUMN,
	);

	/**
	 * Constructor
	 */
	public function __construct() {
	}

	/**
	 * Get the column carrying the row date for the month virtual column
	 *
	 * @param string $material_name Material name
	 * @return string|null Source column name, or null if the material has no month column
	 */
	private function get_time_bucket_source( $material_name ) {
		return isset( self::TIME_BUCKET_SOURCE_MAP[ $material_name ] )
			? self::TIME_BUCKET_SOURCE_MAP[ $material_name ]
			: null;
	}

	/**
	 * Compute the month virtual column ("YYYY-MM") for each row
	 *
	 * allpv carries a unixtime (int on the columndb path, possibly a datetime string on the
	 * view_pv fallback path); gsc carries the YYYYMMDD of the day file Storage read it from.
	 *
	 * @param array  $data          Row array
	 * @param string $material_name Material name
	 * @param string $tracking_id   Tracking ID (selects the site clock)
	 * @return array Rows with the month column added
	 */
	private function compute_month_column( $data, $material_name, $tracking_id ) {
		$source = $this->get_time_bucket_source( $material_name );
		if ( $source === null || ! is_array( $data ) || empty( $data ) ) {
			return $data;
		}

		$clock = QAHM_Time::get_site_clock( $tracking_id );

		foreach ( $data as &$row ) {
			$raw = isset( $row[ $source ] ) ? $row[ $source ] : null;
			if ( $raw === null || $raw === '' ) {
				$row[ self::TIME_BUCKET_COLUMN ] = null;
				continue;
			}

			if ( $source === self::TIME_BUCKET_SOURCE_MAP['gsc'] ) {
				// YYYYMMDD (already a site-local calendar day: the day file it was read from)
				$ymd = (string) $raw;
				$row[ self::TIME_BUCKET_COLUMN ] = strlen( $ymd ) >= 6
					? substr( $ymd, 0, 4 ) . '-' . substr( $ymd, 4, 2 )
					: null;
				continue;
			}

			// access_time: unixtime, or a 'Y-m-d H:i:s' string on the view_pv fallback path
			if ( is_numeric( $raw ) ) {
				$unixtime = (int) $raw;
			} else {
				$norm = trim( str_replace( 'T', ' ', (string) $raw ) );
				if ( strlen( $norm ) <= 10 ) {
					$norm .= ' 00:00:00';
				}
				$unixtime = $clock->str_to_unixtime( $norm, 'Y-m-d H:i:s' );
			}

			$row[ self::TIME_BUCKET_COLUMN ] = $unixtime
				? $clock->unixtime_to_str( $unixtime, 'Y-m' )
				: null;
		}
		unset( $row );

		return $data;
	}

	/**
	 * Get material definition
	 * 
	 * Retrieves the definition for specified material(s) from the materials manifest.
	 * Returns loader, decoder, and fields information for each material.
	 *
	 * @param string|array $material_names Single material name or array of material names
	 * @return array Material definition(s) or error information
	 */
	public function get_material_definition( $material_names ) {
		if ( is_string( $material_names ) ) {
			$material_names = array( $material_names );
		}

		if ( ! is_array( $material_names ) ) {
			return array(
				'error_code' => 'E_INVALID_INPUT',
				'message' => __( 'Material names must be a string or array.', 'qa-heatmap-analytics' ),
				'location' => 'material_names'
			);
		}

		$materials_manifest = $this->load_materials_manifest();
		if ( is_wp_error( $materials_manifest ) ) {
			return array(
				'error_code' => 'E_MANIFEST_LOAD_ERROR',
				'message' => $materials_manifest->get_error_message(),
				'location' => 'materials_manifest'
			);
		}

		if ( $this->wrap_count( $material_names ) === 1 ) {
			$material_name = $material_names[0];
			return $this->get_single_material_definition( $material_name, $materials_manifest );
		}

		$definitions = array();
		foreach ( $material_names as $material_name ) {
			$definition = $this->get_single_material_definition( $material_name, $materials_manifest );
			if ( isset( $definition['error_code'] ) ) {
				return $definition; // Return error immediately
			}
			$definitions[] = $definition;
		}

		return $definitions;
	}

	/**
	 * Get single material definition
	 *
	 * @param string $material_name Material name to retrieve
	 * @param array $materials_manifest Loaded materials manifest
	 * @return array Material definition or error information
	 */
	private function get_single_material_definition( $material_name, $materials_manifest ) {
		// Direct lookup first
		if ( isset( $materials_manifest['materials'][ $material_name ] ) ) {
			$material_data = $materials_manifest['materials'][ $material_name ];
		} elseif ( strpos( $material_name, 'goal_' ) === 0 && strlen( $material_name ) > 5 && ctype_digit( substr( $material_name, 5 ) ) && substr( $material_name, 5, 1 ) !== '0' && isset( $materials_manifest['materials']['goal_x'] ) ) {
			// Pattern-based lookup for goal_x materials (goal_1, goal_2, etc.)
			$material_data = $materials_manifest['materials']['goal_x'];
		} elseif ( strpos( $material_name, 'events.' ) === 0 && isset( $materials_manifest['materials']['events_template'] ) ) {
			// Pattern-based lookup for events.{name} materials (Layer 2 event detail tables)
			$material_data = $materials_manifest['materials']['events_template'];
		} else {
			return array(
				'error_code' => 'E_UNKNOWN_MATERIAL',
				/* translators: %s: material name */
				'message' => sprintf( __( "Material '%s' not found in manifest", 'qa-heatmap-analytics' ), $material_name ),
				'location' => 'material_name'
			);
		}

		$decoders = array();
		if ( isset( $material_data['decoders'] ) && is_array( $material_data['decoders'] ) ) {
			foreach ( $material_data['decoders'] as $decoder_def ) {
				$decoder = array(
					'loader' => isset( $decoder_def['loader'] ) ? $decoder_def['loader'] : null,
					'decoder' => isset( $decoder_def['decoder'] ) ? $decoder_def['decoder'] : null,
					'fields' => array()
				);

				if ( isset( $decoder_def['fields'] ) && is_array( $decoder_def['fields'] ) ) {
					foreach ( $decoder_def['fields'] as $field_def ) {
						$field = array(
							'material_column' => isset( $field_def['material_column'] ) ? $field_def['material_column'] : '',
							'physical_column' => isset( $field_def['physical_column'] ) ? $field_def['physical_column'] : ''
						);
						if ( isset( $field_def['resolve_via'] ) ) {
							$field['resolve_via'] = $field_def['resolve_via'];
						}
						if ( ! empty( $field_def['virtual'] ) ) {
							$field['virtual'] = true;
						}
						// T100b-h: value_mappings — 固定マップ駆動の label 列自動付与
						if ( isset( $field_def['value_mappings'] ) && is_array( $field_def['value_mappings'] )
							&& isset( $field_def['value_mappings']['label_column'] )
							&& isset( $field_def['value_mappings']['map'] )
							&& is_array( $field_def['value_mappings']['map'] ) ) {
							$field['value_mappings'] = array(
								'label_column' => (string) $field_def['value_mappings']['label_column'],
								'map'          => $field_def['value_mappings']['map'],
							);
						}
						$decoder['fields'][] = $field;
					}
				}

				$decoders[] = $decoder;
			}
		}

		return array(
			'material_name' => $material_name,
			'decoders' => $decoders
		);
	}

	/**
	 * Load materials manifest from PHP array file
	 *
	 * @return array|WP_Error Materials manifest array or WP_Error on failure
	 */
	private function load_materials_manifest() {
		$manifest_file = dirname( __FILE__ ) . '/yaml/materials-manifest.php';

		if ( ! file_exists( $manifest_file ) ) {
			return new WP_Error(
				'manifest_not_found',
				/* translators: %s: file path */
				sprintf( __( 'Materials manifest not found: %s', 'qa-heatmap-analytics' ), $manifest_file )
			);
		}

		$manifest = include $manifest_file;

		if ( ! is_array( $manifest ) ) {
			return new WP_Error(
				'manifest_parse_error',
				__( 'Failed to load materials manifest file.', 'qa-heatmap-analytics' )
			);
		}

		return $manifest;
	}

	/**
	 * Get all available material names
	 *
	 * @return array|WP_Error Array of material names or WP_Error on failure
	 */
	public function get_available_materials() {
		$materials_manifest = $this->load_materials_manifest();
		if ( is_wp_error( $materials_manifest ) ) {
			return $materials_manifest;
		}

		if ( ! isset( $materials_manifest['materials'] ) || ! is_array( $materials_manifest['materials'] ) ) {
			return array();
		}

		return array_keys( $materials_manifest['materials'] );
	}

	/**
	 * Apply filter operation
	 * 
	 * Executes filter operations on material data. Retrieves data from Storage layer,
	 * applies filter conditions, and returns decoded data ready for view storage.
	 * 
	 * Supports filter conditions for each material type:
	 * - allpv: utm_source, utm_medium, utm_campaign, device_type, country_code
	 * - gsc: search_type, keyword
	 * - goal_x: utm_source, utm_medium, utm_campaign, device_id, is_reject
	 *
	 * @param array $executable_qal Current ExecutableQAL structure
	 * @param string $view_name Name of the view to process
	 * @return array Result data with view_name, material_name, record_count, and data, or error array
	 */
	public function qal_filter_apply( $executable_qal, $view_name ) {
		if ( ! isset( $executable_qal['qal']['make'][ $view_name ] ) ) {
			return array(
				'error_code' => 'E_UNKNOWN_VIEW',
				/* translators: %s: view name */
				'message' => sprintf( __( "View '%s' not found in make section.", 'qa-heatmap-analytics' ), $view_name ),
				'location' => "make.{$view_name}"
			);
		}

		$view_def = $executable_qal['qal']['make'][ $view_name ];

		if ( ! isset( $view_def['from'][0] ) ) {
			return array(
				'error_code' => 'E_INVALID_FROM',
				'message' => __( 'From array is empty or missing.', 'qa-heatmap-analytics' ),
				'location' => "make.{$view_name}.from"
			);
		}

		$material_name = $view_def['from'][0];
		$tracking_id = $executable_qal['qal']['tracking_id'];
		$time_range = $executable_qal['qal']['time'];
		$keep_columns = isset( $view_def['keep'] ) ? $view_def['keep'] : array();
		$filter_conditions = isset( $view_def['filter'] ) ? $view_def['filter'] : null;

		// WP-backed materials (wp_posts etc.) are delegated to a dedicated class
		// loaded only in QA Assistants environment (see qahm-loader.php).
		// In QA ZERO environment, the class is not loaded and we return
		// E_UNKNOWN_MATERIAL with an explicit hint.
		// Keep this list in sync with qal_join_apply()'s $wp_materials (Issue #1199).
		$wp_materials = array( 'wp_posts' );
		if ( in_array( $material_name, $wp_materials, true ) ) {
			if ( ! isset( $GLOBALS['qahm_qal_wp_material'] ) ) {
				return array(
					'error_code' => 'E_UNKNOWN_MATERIAL',
					/* translators: %s: material name */
					'message'    => sprintf( __( "Material '%s' is only available in QA Assistants environment.", 'qa-heatmap-analytics' ), $material_name ),
					'location'   => "make.{$view_name}.from[0]",
				);
			}
			// Phase 1.6b cleanup (#1197): join is not supported for WP-backed materials.
			// Returning E_INVALID_JOIN early prevents Executor from passing wp_posts data
			// to qal_join_apply() which assumes column-DB-backed materials.
			if ( isset( $view_def['join'] ) && is_array( $view_def['join'] ) && ! empty( $view_def['join'] ) ) {
				return array(
					'error_code' => 'E_INVALID_JOIN',
					/* translators: %s: material name */
					'message'    => sprintf( __( "Material '%s' does not support join. See docs/specs/assistant/data-sources.md.", 'qa-heatmap-analytics' ), $material_name ),
					'location'   => "make.{$view_name}.join",
				);
			}
			$result_limit = isset( $executable_qal['qal']['result']['limit'] ) ? (int) $executable_qal['qal']['result']['limit'] : 0;
			$count_only   = ! empty( $executable_qal['qal']['result']['count_only'] );
			$filter_for_wp = is_array( $filter_conditions ) ? $filter_conditions : array();
			global $qahm_qal_wp_material;
			$wp_result = $qahm_qal_wp_material->fetch(
				$material_name,
				$tracking_id,
				$time_range,
				$filter_for_wp,
				$keep_columns,
				$result_limit,
				$count_only
			);
			// WP material returns view_name='' on success; overwrite with caller's view_name.
			// On error responses ('error_code' set), preserve as-is.
			if ( is_array( $wp_result ) && ! isset( $wp_result['error_code'] ) ) {
				$wp_result['view_name'] = $view_name;
			}
			return $wp_result;
		}

		$material_def = $this->get_material_definition( $material_name );
		if ( isset( $material_def['error_code'] ) ) {
			return $material_def;
		}

		$has_join = isset( $view_def['join'] ) && is_array( $view_def['join'] ) && ! empty( $view_def['join'] );
		$physical_columns = $this->extract_physical_columns( $keep_columns, $material_name, $material_def, $has_join );
		if ( isset( $physical_columns['error_code'] ) ) {
			return $physical_columns;
		}

		// When join is present, ensure the left join key column is included in physical columns
		// so that qal_join_apply can extract key values from left view data
		if ( $has_join && isset( $view_def['join']['on'][0]['left'] ) ) {
			$left_join_col = $this->parse_column_ref( $view_def['join']['on'][0]['left'], $material_name );
			if ( $left_join_col !== null ) {
				$left_join_physical = $this->find_physical_column( $left_join_col, $material_def, $material_name );
				if ( $left_join_physical === null ) {
					$left_join_physical = $left_join_col;
				}
				if ( ! in_array( $left_join_physical, $physical_columns, true ) ) {
					$physical_columns[] = $left_join_physical;
				}
				// Also ensure it appears in keep_columns for decode_data
				$qualified = $material_name . '.' . $left_join_col;
				if ( ! $this->wrap_in_array( $qualified, $keep_columns, true ) ) {
					$keep_columns[] = $qualified;
				}
			}
		}

		// When calc is present, ensure calc-referenced columns are included in physical columns
		// so that qal_execute_calc can access them (same pattern as join key at L224-242)
		$has_calc = isset( $view_def['calc'] ) && is_array( $view_def['calc'] ) && ! empty( $view_def['calc'] );
		if ( $has_calc ) {
			foreach ( $view_def['calc'] as $calc_key => $calc_expr ) {
				$paren_open = strpos( $calc_expr, '(' );
				$paren_close = strpos( $calc_expr, ')' );
				if ( $paren_open !== false && $paren_close !== false ) {
					$full_col = substr( $calc_expr, $paren_open + 1, $paren_close - $paren_open - 1 );
					$calc_col = $this->parse_column_ref( $full_col, $material_name );
					if ( $calc_col !== null ) {
						$calc_physical = $this->find_physical_column( $calc_col, $material_def, $material_name );
						if ( $calc_physical === null ) {
							$calc_physical = $calc_col;
						}
						if ( ! in_array( $calc_physical, $physical_columns, true ) ) {
							$physical_columns[] = $calc_physical;
						}
						$qualified = $material_name . '.' . $calc_col;
						if ( ! $this->wrap_in_array( $qualified, $keep_columns, true ) ) {
							$keep_columns[] = $qualified;
						}
					}
				}
			}
		}

		// Classify columns: separate master-reference columns from direct column DB columns
		// Master-ref columns (url, utm_source, etc.) are converted to their ID columns (page_id, source_id, etc.)
		$classified = $this->classify_columns_for_storage( $physical_columns, $material_def );
		$storage_columns = $classified['storage_columns'];
		$master_ref = $classified['master_ref'];
		$virtual_columns = $classified['virtual_columns'];
		// T100b-h: value_mappings 自動付与対象（label_col => [ id_col, map ]）
		$value_mapped_columns = isset( $classified['value_mapped_columns'] )
			? $classified['value_mapped_columns']
			: array();

		// Detect is_goal_N virtual columns in keep list
		$goal_keep_numbers = $this->detect_goal_virtual_columns( $virtual_columns );

		// When goal virtual columns are in keep, ensure pv_id is fetched for matching
		if ( ! empty( $goal_keep_numbers ) && ! in_array( 'pv_id', $storage_columns, true ) ) {
			$storage_columns[] = 'pv_id';
		}

		// When GSC virtual columns (ctr, position, position_weighted) are in keep, ensure source columns are fetched
		if ( $material_name === 'gsc' && ! empty( $virtual_columns ) ) {
			if ( in_array( 'ctr', $virtual_columns, true ) ) {
				if ( ! in_array( 'clicks', $storage_columns, true ) ) {
					$storage_columns[] = 'clicks';
				}
				if ( ! in_array( 'impressions', $storage_columns, true ) ) {
					$storage_columns[] = 'impressions';
				}
			}
			if ( in_array( 'position', $virtual_columns, true ) ) {
				if ( ! in_array( 'position_x100', $storage_columns, true ) ) {
					$storage_columns[] = 'position_x100';
				}
			}
			if ( in_array( 'position_weighted', $virtual_columns, true ) ) {
				if ( ! in_array( 'position_x100', $storage_columns, true ) ) {
					$storage_columns[] = 'position_x100';
				}
				if ( ! in_array( 'impressions', $storage_columns, true ) ) {
					$storage_columns[] = 'impressions';
				}
			}
		}

		// T133: month virtual column — ensure the column carrying the row's date is fetched.
		// allpv has access_time on both paths (columndb / view_pv fallback); gsc rows carry no
		// date at all, so Storage injects TIME_BUCKET_SOURCE_GSC from its per-day file loop.
		if ( in_array( self::TIME_BUCKET_COLUMN, $virtual_columns, true ) ) {
			$bucket_source = $this->get_time_bucket_source( $material_name );
			if ( $bucket_source !== null && ! in_array( $bucket_source, $storage_columns, true ) ) {
				$storage_columns[] = $bucket_source;
			}
		}

		// Convert is_goal_N filter conditions to pv_id IN conditions
		$goal_filter_pv_ids = null;
		if ( ! empty( $filter_conditions ) && is_array( $filter_conditions ) ) {
			$goal_filter_result = $this->convert_goal_filters_to_pv_id(
				$filter_conditions, $tracking_id, $time_range
			);
			$filter_conditions = $goal_filter_result['filter_conditions'];
			$goal_filter_pv_ids = $goal_filter_result['pv_id_set'];

			// When goal filter is active, ensure pv_id is fetched
			if ( $goal_filter_pv_ids !== null && ! in_array( 'pv_id', $storage_columns, true ) ) {
				$storage_columns[] = 'pv_id';
			}
		}

		$count_only = isset( $executable_qal['qal']['result']['count_only'] )
			? $executable_qal['qal']['result']['count_only']
			: false;

		// T83: Rewrite virtual column filter conditions before reverse_lookup.
		// A 系 (linear) -> physical column filter; B 系 (multi-source) -> material_post_filters.
		// May downgrade $count_only to false when B 系 filter is present (D9).
		// Snapshot storage_columns before rewrite so we can identify B 系 source columns
		// for the explicit strip step (D5).
		$pre_rewrite_storage_columns = $storage_columns;
		$material_post_filters = array();
		if ( ! empty( $filter_conditions ) && is_array( $filter_conditions ) ) {
			$rewrite_result = $this->rewrite_virtual_filters_for_storage(
				$filter_conditions, $material_name, $material_def, $storage_columns, $count_only
			);
			$filter_conditions = $rewrite_result['storage_filters'];
			$material_post_filters = $rewrite_result['material_post_filters'];
		}

		// Compute newly added physical columns (B 系 source columns added solely for filter).
		// These will be stripped after apply_virtual_post_filters if not in keep.
		$b_series_added_columns = array();
		if ( ! empty( $material_post_filters ) ) {
			foreach ( $storage_columns as $col ) {
				if ( ! in_array( $col, $pre_rewrite_storage_columns, true ) ) {
					$b_series_added_columns[] = $col;
				}
			}
		}

		// Reverse-lookup filters: convert string filter values to ID-based filters
		$storage_filters = $this->reverse_lookup_filters( $filter_conditions, $material_def );

		// count_only: fetch count from Storage and return immediately (skip restore/decode pipeline)
		if ( $count_only ) {
			$count_data = $this->fetch_filtered_data(
				$tracking_id,
				$material_name,
				$time_range,
				$storage_filters,
				$storage_columns,
				true
			);

			if ( isset( $count_data['error_code'] ) ) {
				return $count_data;
			}

			$count_value = isset( $count_data['data'][0]['count'] )
				? (int) $count_data['data'][0]['count']
				: $count_data['record_count'];

			return array(
				'view_name'     => $view_name,
				'material_name' => $material_name,
				'record_count'  => $count_value,
				'data'          => array( array( 'count' => $count_value ) ),
			);
		}

		// Full pipeline: fetch → [goal virtual column injection] → restore master columns → decode
		$storage_data = $this->fetch_filtered_data(
			$tracking_id,
			$material_name,
			$time_range,
			$storage_filters,
			$storage_columns,
			false
		);

		if ( isset( $storage_data['error_code'] ) ) {
			return $storage_data;
		}

		// Inject goal virtual columns (is_goal_N flags) into fetched data
		$injected_data = $storage_data['data'];
		if ( ! empty( $goal_keep_numbers ) && is_array( $injected_data ) && ! empty( $injected_data ) ) {
			$injected_data = $this->inject_goal_virtual_columns(
				$injected_data, $goal_keep_numbers, $tracking_id, $time_range
			);
		}

		// Restore master columns: convert ID values to string values using DB master tables
		$restored_data = $injected_data;
		if ( ! empty( $master_ref ) && is_array( $restored_data ) && ! empty( $restored_data ) ) {
			$restored_data = $this->restore_master_columns( $restored_data, $master_ref, $tracking_id );
		}

		// T100b-h: Apply value_mappings (yaml 駆動の固定マップで label 列を自動付与)
		// resolve_via (MASTER_RESOLVER_MAP 駆動) と併存。yaml に value_mappings 宣言がある
		// フィールドに対して、ID 列の値をラベル文字列に変換した新列を結果行へ追加する。
		if ( ! empty( $value_mapped_columns ) && is_array( $restored_data ) && ! empty( $restored_data ) ) {
			$restored_data = $this->apply_value_mappings( $restored_data, $value_mapped_columns );
		}

		// Compute GSC virtual columns (ctr, position)
		if ( $material_name === 'gsc' && ! empty( $virtual_columns ) ) {
			$restored_data = $this->compute_gsc_virtual_columns( $restored_data, $virtual_columns );
		}

		// T133: Compute the month bucket ("YYYY-MM") from the row's date column.
		// Also needed when month appears only in a filter (B 系 post-filter below reads the value).
		if ( in_array( self::TIME_BUCKET_COLUMN, $virtual_columns, true )
			|| isset( $material_post_filters[ self::TIME_BUCKET_COLUMN ] ) ) {
			$restored_data = $this->compute_month_column( $restored_data, $material_name, $tracking_id );
		}

		// T83: Apply Material-layer post-filter for B 系 virtual columns (ctr, position_weighted).
		// compute_gsc_virtual_columns must have run first so the values exist on each row.
		// For A 系 the storage filter already reduced the rowset; this step is a no-op then.
		if ( ! empty( $material_post_filters ) ) {
			// If a virtual column was used in filter but not present in computed data
			// (e.g. material is not gsc), compute it on demand for filtering.
			if ( $material_name === 'gsc' ) {
				$post_filter_virtuals = array();
				foreach ( $material_post_filters as $col => $_unused ) {
					if ( ! in_array( $col, $virtual_columns, true ) ) {
						$post_filter_virtuals[] = $col;
					}
				}
				if ( ! empty( $post_filter_virtuals ) ) {
					$restored_data = $this->compute_gsc_virtual_columns( $restored_data, $post_filter_virtuals );
				}
			}
			$restored_data = $this->apply_virtual_post_filters( $restored_data, $material_post_filters );
		}

		// T83: Strip filter-only physical columns added solely to evaluate B 系 filters.
		// decode_data drops them via column_map, but explicit strip keeps Material self-contained.
		if ( ! empty( $b_series_added_columns ) ) {
			$restored_data = $this->strip_filter_only_columns( $restored_data, $keep_columns, $material_name, $b_series_added_columns );
		}

		$decoded_data = $this->decode_data( $restored_data, $keep_columns, $material_name, $material_def );

		return array(
			'view_name' => $view_name,
			'material_name' => $material_name,
			'record_count' => $this->wrap_count( $decoded_data ),
			'data' => $decoded_data
		);
	}

	/**
	 * Extract physical columns from keep list
	 *
	 * Converts logical column names (e.g., 'allpv.url') to physical column names
	 * based on material definition.
	 *
	 * When $skip_other_materials is true, columns belonging to a different material
	 * are silently skipped instead of causing an error. This is used in join operations
	 * where keep columns reference both left and right materials.
	 *
	 * @param array $keep_columns Array of logical column names
	 * @param string $material_name Material name
	 * @param array $material_def Material definition
	 * @param bool $skip_other_materials If true, skip columns from other materials instead of error
	 * @return string[]|array Array of physical column names or error array
	 */
	private function extract_physical_columns( $keep_columns, $material_name, $material_def, $skip_other_materials = false ) {
		if ( ! is_array( $keep_columns ) ) {
			return array(
				'error_code' => 'E_INVALID_INPUT',
				'message' => __( 'Keep columns must be an array.', 'qa-heatmap-analytics' ),
				'location' => 'keep'
			);
		}
		
		$physical_columns = array();

		foreach ( $keep_columns as $logical_column ) {
			if ( ! is_string( $logical_column ) ) {
				return array(
					'error_code' => 'E_UNKNOWN_COLUMN',
					'message' => __( 'Column name must be a string.', 'qa-heatmap-analytics' ),
					'location' => 'keep'
				);
			}
			
			// マテリアル名にドットを含む場合（events.purchase等）、
			// "events.purchase.value" → material="events.purchase", column="value"
			// マテリアル名にドットを含まない場合、
			// "allpv.pv_id" → material="allpv", column="pv_id"
			if ( strpos( $material_name, '.' ) !== false ) {
				// ドット付きマテリアル名: プレフィックスとして material_name を除去
				$prefix = $material_name . '.';
				if ( strpos( $logical_column, $prefix ) !== 0 ) {
					if ( $skip_other_materials ) {
						continue;
					}
					return array(
						'error_code' => 'E_UNKNOWN_COLUMN',
						/* translators: 1: column name, 2: expected material name */
						'message' => sprintf( __( "Column '%1\$s' does not match expected material '%2\$s'", 'qa-heatmap-analytics' ), $logical_column, $material_name ),
						'location' => 'keep'
					);
				}
				$column_material = $material_name;
				$column_name = substr( $logical_column, strlen( $prefix ) );
			} else {
				$parts = $this->wrap_explode( '.', $logical_column );
				$parts_count = $this->wrap_count( $parts );
				if ( $parts_count < 2 ) {
					return array(
						'error_code' => 'E_UNKNOWN_COLUMN',
						/* translators: %s: column name */
						'message' => sprintf( __( "Invalid column format: '%s'", 'qa-heatmap-analytics' ), $logical_column ),
						'location' => 'keep'
					);
				}
				if ( $parts_count > 2 ) {
					// ドット付きマテリアル名 (e.g., "events.purchase.value" → material="events.purchase", column="value")
					$column_name = $parts[ $parts_count - 1 ];
					$column_material = implode( '.', array_slice( $parts, 0, $parts_count - 1 ) );
				} else {
					$column_material = $parts[0];
					$column_name = $parts[1];
				}
			}

			if ( $column_material !== $material_name ) {
				if ( $skip_other_materials ) {
					continue;
				}
				return array(
					'error_code' => 'E_UNKNOWN_COLUMN',
					/* translators: 1: column material name, 2: expected material name */
					'message' => sprintf( __( "Column material '%1\$s' does not match expected material '%2\$s'", 'qa-heatmap-analytics' ), $column_material, $material_name ),
					'location' => 'keep'
				);
			}

			// Find physical column in material definition
			$physical_column = $this->find_physical_column( $column_name, $material_def, $material_name );
			if ( $physical_column === null ) {
				return array(
					'error_code' => 'E_UNKNOWN_COLUMN',
					/* translators: %s: column name */
					'message' => sprintf( __( "Column '%s' not found in material definition", 'qa-heatmap-analytics' ), $column_name ),
					'location' => 'keep'
				);
			}

			if ( ! $this->wrap_in_array( $physical_column, $physical_columns, true ) ) {
				$physical_columns[] = $physical_column;
			}
		}

		return $physical_columns;
	}

	/**
	 * Find physical column name for a logical column
	 *
	 * @param string $logical_column Logical column name
	 * @param array $material_def Material definition
	 * @return string|null Physical column name or null if not found
	 */
	private function find_physical_column( $logical_column, $material_def, $material_name = '' ) {
		if ( ! isset( $material_def['decoders'] ) || ! is_array( $material_def['decoders'] ) ) {
			return null;
		}

		foreach ( $material_def['decoders'] as $decoder ) {
			if ( ! isset( $decoder['fields'] ) || ! is_array( $decoder['fields'] ) ) {
				continue;
			}

			foreach ( $decoder['fields'] as $field ) {
				if ( isset( $field['material_column'] ) && $field['material_column'] === $logical_column ) {
					return isset( $field['physical_column'] ) ? $field['physical_column'] : null;
				}
			}
		}

		// T100b-h: value_mappings の label_column も論理列として受理する
		// （Material 層が ID 列から自動付与する合成列なので、物理名は label_column 自身を返す）
		foreach ( $material_def['decoders'] as $decoder ) {
			if ( ! isset( $decoder['fields'] ) || ! is_array( $decoder['fields'] ) ) {
				continue;
			}
			foreach ( $decoder['fields'] as $field ) {
				if ( isset( $field['value_mappings']['label_column'] )
					&& $field['value_mappings']['label_column'] === $logical_column ) {
					return $logical_column;
				}
			}
		}

		// events_template（Layer 2）: 動的カラム — 物理名=マテリアル名（パススルー）
		if ( strpos( $material_name, 'events.' ) === 0 ) {
			return $logical_column;
		}

		return null;
	}

	/**
	 * Fetch filtered data from storage layer
	 * 
	 * Delegates to the Storage layer to retrieve data from the appropriate 
	 * storage backend (files or database) based on material definition.
	 *
	 * @param string $tracking_id Tracking ID
	 * @param string $material_name Material name
	 * @param array $time_range Time range configuration
	 * @param array|null $filter_conditions Filter conditions (not supported in 2025-10-20)
	 * @param array $physical_columns Physical column names to retrieve
	 * @param bool $count_only Whether to return only the count (enables optimization for allpv material)
	 * @return array Result with record_count and data, or error array
	 */
	private function fetch_filtered_data( $tracking_id, $material_name, $time_range, $filter_conditions, $physical_columns, $count_only = false ) {
		global $qahm_qal_storage;

		if ( ! is_object( $qahm_qal_storage ) || ! method_exists( $qahm_qal_storage, 'fetch_filtered_data' ) ) {
			return array(
				'error_code' => 'E_DEPENDENCY_NOT_AVAILABLE',
				'message' => __( 'Required Storage layer dependency is not available.', 'qa-heatmap-analytics' ),
				'location' => 'material'
			);
		}

		// Delegate to Storage layer
		return $qahm_qal_storage->fetch_filtered_data( 
			$tracking_id, 
			$material_name, 
			$time_range, 
			$filter_conditions, 
			$physical_columns,
			$count_only 
		);
	}

	/**
	 * Decode data from physical columns to logical values
	 * 
	 * For 2025-10-20 version with 'allpv' material, most columns don't need decoding
	 * as physical columns are the same as logical columns. This function primarily
	 * filters and renames columns according to the keep list.
	 *
	 * @param array $data Raw data with physical column names
	 * @param array $keep_columns Logical column names to keep
	 * @param string $material_name Material name
	 * @param array $material_def Material definition
	 * @return array Decoded data with logical column names
	 */
	private function decode_data( $data, $keep_columns, $material_name, $material_def ) {
		if ( ! is_array( $data ) ) {
			global $qahm_log;
			if ( is_object( $qahm_log ) && method_exists( $qahm_log, 'warning' ) ) {
				$qahm_log->warning( 
					'[QAHM_Qal_Material::decode_data] Invalid $data input: expected array, got ' . gettype( $data )
				);
			}
			$data = array();
		}
		
		$decoded_data = array();

		$column_map = array();
		$has_dot_material = ( strpos( $material_name, '.' ) !== false );
		foreach ( $keep_columns as $logical_column ) {
			if ( $has_dot_material ) {
				$prefix = $material_name . '.';
				if ( strpos( $logical_column, $prefix ) !== 0 ) {
					continue;
				}
				$column_name = substr( $logical_column, strlen( $prefix ) );
			} else {
				$parts = $this->wrap_explode( '.', $logical_column );
				$parts_count = $this->wrap_count( $parts );
				if ( $parts_count < 2 ) {
					continue;
				}
				if ( $parts_count > 2 ) {
					$column_name = $parts[ $parts_count - 1 ];
					$column_material = implode( '.', array_slice( $parts, 0, $parts_count - 1 ) );
				} else {
					$column_material = $parts[0];
					$column_name = $parts[1];
				}
				if ( $column_material !== $material_name ) {
					continue;
				}
			}

			$physical_column = $this->find_physical_column( $column_name, $material_def, $material_name );

			if ( $physical_column !== null ) {
				$column_map[ $column_name ] = $physical_column;
			}
		}

		foreach ( $data as $record ) {
			$decoded_record = array();

			foreach ( $column_map as $logical_name => $physical_name ) {
				if ( isset( $record[ $physical_name ] ) ) {
					// 物理カラム名でヒット（通常パス）
					$decoded_record[ $logical_name ] = $record[ $physical_name ];
				} elseif ( $physical_name !== $logical_name && isset( $record[ $logical_name ] ) ) {
					// Storage層の辞書引きで既にマテリアルカラム名に変換済みの場合
					// 例: event_name_id → event_name への変換が Storage 層で完了済み
					$decoded_record[ $logical_name ] = $record[ $logical_name ];
				} else {
					$decoded_record[ $logical_name ] = null;
				}
			}

			$decoded_data[] = $decoded_record;
		}

		return $decoded_data;
	}

	/**
	 * Classify keep columns into direct (column DB) and master-reference columns
	 *
	 * For master-reference columns (those with resolve_via), returns the ID column
	 * that Storage should fetch, plus a mapping for restore_master_columns.
	 *
	 * @param array $physical_columns Physical column names from extract_physical_columns
	 * @param array $material_def Material definition with resolve_via info
	 * @return array [ 'storage_columns' => [...], 'master_ref' => [material_col => id_col, ...] ]
	 */
	private function classify_columns_for_storage( $physical_columns, $material_def ) {
		// Build resolve_via and virtual lookup from material definition
		$resolve_map = array();
		$virtual_set = array();
		// T100b-h: value_mappings lookup — label_column => [ id_col, map ]
		$value_mapping_by_label = array();
		if ( isset( $material_def['decoders'] ) && is_array( $material_def['decoders'] ) ) {
			foreach ( $material_def['decoders'] as $decoder ) {
				if ( ! isset( $decoder['fields'] ) || ! is_array( $decoder['fields'] ) ) {
					continue;
				}
				foreach ( $decoder['fields'] as $field ) {
					if ( isset( $field['resolve_via'] ) ) {
						$resolve_map[ $field['physical_column'] ] = $field['resolve_via'];
					}
					if ( ! empty( $field['virtual'] ) ) {
						$virtual_set[ $field['physical_column'] ] = true;
					}
					if ( isset( $field['value_mappings']['label_column'] )
						&& isset( $field['value_mappings']['map'] )
						&& isset( $field['physical_column'] ) ) {
						$value_mapping_by_label[ $field['value_mappings']['label_column'] ] = array(
							'id_col' => $field['physical_column'],
							'map'    => $field['value_mappings']['map'],
						);
					}
				}
			}
		}

		$storage_columns = array();
		$master_ref = array();
		$virtual_columns = array();
		$value_mapped_columns = array();   // T100b-h: label_col => [ id_col, map ]

		foreach ( $physical_columns as $col ) {
			if ( isset( $virtual_set[ $col ] ) ) {
				// Virtual column: not in Storage, computed at Material layer
				$virtual_columns[] = $col;
			} elseif ( isset( $value_mapping_by_label[ $col ] ) ) {
				// T100b-h: value_mappings label column. Fetch underlying ID column instead.
				$id_col = $value_mapping_by_label[ $col ]['id_col'];
				$value_mapped_columns[ $col ] = array(
					'id_col' => $id_col,
					'map'    => $value_mapping_by_label[ $col ]['map'],
				);
				if ( ! in_array( $id_col, $storage_columns, true ) ) {
					$storage_columns[] = $id_col;
				}
			} elseif ( isset( $resolve_map[ $col ] ) ) {
				// Master reference column: need ID column for Storage
				$id_col = $resolve_map[ $col ];
				$master_ref[ $col ] = $id_col;
				if ( ! in_array( $id_col, $storage_columns, true ) ) {
					$storage_columns[] = $id_col;
				}
			} else {
				// Direct column DB column
				if ( ! in_array( $col, $storage_columns, true ) ) {
					$storage_columns[] = $col;
				}
			}
		}

		return array(
			'storage_columns'      => $storage_columns,
			'master_ref'           => $master_ref,
			'virtual_columns'      => $virtual_columns,
			'value_mapped_columns' => $value_mapped_columns,
		);
	}

	/**
	 * Check if a filter condition uses operator format (e.g., ['prefix' => 'https://...'])
	 *
	 * @param mixed $condition Filter condition value
	 * @return bool True if condition is an associative array with a known operator key
	 */
	private function is_operator_condition( $condition ) {
		if ( ! is_array( $condition ) ) {
			return false;
		}
		$operator_keys = array( 'eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'contains', 'prefix', 'between' );
		foreach ( $operator_keys as $op ) {
			if ( array_key_exists( $op, $condition ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Match a record value against an operator-format condition
	 *
	 * @param mixed $value Record value to test
	 * @param array $condition Operator condition (e.g., ['prefix' => 'https://...'])
	 * @return bool True if value matches the condition
	 */
	private function match_operator_condition( $value, $condition ) {
		foreach ( $condition as $op => $target ) {
			switch ( $op ) {
				case 'eq':
					if ( is_numeric( $value ) && is_numeric( $target ) ) {
						if ( (float) $value !== (float) $target ) { return false; }
					} elseif ( $value !== $target ) {
						return false;
					}
					break;
				case 'neq':
					if ( is_numeric( $value ) && is_numeric( $target ) ) {
						if ( (float) $value === (float) $target ) { return false; }
					} elseif ( $value === $target ) {
						return false;
					}
					break;
				case 'prefix':
					if ( ! is_string( $value ) || ! is_string( $target ) || strpos( $value, $target ) !== 0 ) { return false; }
					break;
				case 'contains':
					if ( ! is_string( $value ) || ! is_string( $target ) || strpos( $value, $target ) === false ) { return false; }
					break;
				case 'gt':
					if ( $value <= $target ) { return false; }
					break;
				case 'gte':
					if ( $value < $target ) { return false; }
					break;
				case 'lt':
					if ( $value >= $target ) { return false; }
					break;
				case 'lte':
					if ( $value > $target ) { return false; }
					break;
				case 'in':
					if ( ! is_array( $target ) || ! in_array( $value, $target, false ) ) { return false; }
					break;
				case 'between':
					if ( ! is_array( $target ) || count( $target ) < 2 || $value < $target[0] || $value > $target[1] ) { return false; }
					break;
				default:
					return false;
			}
		}
		return true;
	}

	/**
	 * Reverse-lookup filter conditions: convert string filter values to ID values
	 *
	 * For filters on master-reference columns (e.g., utm_source:"google"),
	 * fetches the master table and converts to ID-based filter (e.g., source_id:1).
	 *
	 * @param array|null $filter_conditions Original filter conditions
	 * @param array $material_def Material definition with resolve_via info
	 * @return array|null Converted filter conditions (ID-based) or null
	 */
	private function reverse_lookup_filters( $filter_conditions, $material_def ) {
		if ( empty( $filter_conditions ) || ! is_array( $filter_conditions ) ) {
			return $filter_conditions;
		}

		// Build resolve_via lookup
		$resolve_map = array();
		if ( isset( $material_def['decoders'] ) && is_array( $material_def['decoders'] ) ) {
			foreach ( $material_def['decoders'] as $decoder ) {
				if ( ! isset( $decoder['fields'] ) || ! is_array( $decoder['fields'] ) ) {
					continue;
				}
				foreach ( $decoder['fields'] as $field ) {
					if ( isset( $field['resolve_via'] ) ) {
						$resolve_map[ $field['material_column'] ] = $field['resolve_via'];
					}
				}
			}
		}

		if ( empty( $resolve_map ) ) {
			return $filter_conditions;
		}

		$converted = array();
		foreach ( $filter_conditions as $col => $condition ) {
			if ( ! isset( $resolve_map[ $col ] ) ) {
				// Not a master-reference column, pass through (with intersection if key already exists)
				if ( isset( $converted[ $col ] ) ) {
					$existing = is_array( $converted[ $col ] ) ? $converted[ $col ] : array( $converted[ $col ] );
					$new_vals = is_array( $condition ) ? $condition : array( $condition );
					$converted[ $col ] = array_values( array_intersect( $existing, $new_vals ) );
				} else {
					$converted[ $col ] = $condition;
				}
				continue;
			}

			$id_col = $resolve_map[ $col ];

			if ( ! isset( self::MASTER_RESOLVER_MAP[ $id_col ] ) ) {
				$converted[ $col ] = $condition;
				continue;
			}

			$resolver = self::MASTER_RESOLVER_MAP[ $id_col ];

			// device_type: fixed mapping reverse lookup
			if ( $resolver['accessor'] === null && $id_col === 'device_id' ) {
				$is_operator = is_array( $condition ) && $this->is_operator_condition( $condition );
				if ( $is_operator ) {
					// Operator format: test each device type against the condition
					$id_values = array();
					foreach ( self::DEVICE_TYPE_MAP as $dev_id => $dev_type ) {
						if ( $this->match_operator_condition( $dev_type, $condition ) ) {
							$id_values[] = $dev_id;
						}
					}
				} else {
					$reverse_device = array_flip( self::DEVICE_TYPE_MAP );
					$filter_values = is_array( $condition ) ? $condition : array( $condition );
					$id_values = array();
					foreach ( $filter_values as $val ) {
						if ( isset( $reverse_device[ $val ] ) ) {
							$id_values[] = $reverse_device[ $val ];
						}
					}
				}
				// Sentinel: if no IDs matched, use -1 to guarantee zero results
				$converted[ $id_col ] = ! empty( $id_values ) ? $id_values : array( -1 );
				continue;
			}

			// tracking_id依存accessor: reverse_lookupでは処理せずStorage層に委譲
			if ( $resolver['accessor'] === '_gsc_query_log' ) {
				$converted[ $col ] = $condition;
				continue;
			}

			// DB-based reverse lookup: fetch all master records, find matching IDs
			$db_col = isset( $resolver['columns'][ $col ] ) ? $resolver['columns'][ $col ] : null;
			if ( $db_col === null ) {
				$converted[ $col ] = $condition;
				continue;
			}

			// Fetch all records from master table
			// Some accessors have default LIMIT; override with PHP_INT_MAX
			if ( $resolver['accessor'] === 'get_qa_readers' ) {
				$all_records = QAHM_DB_Functions::get_qa_readers( null, null, null, null, PHP_INT_MAX );
			} elseif ( $resolver['accessor'] === 'get_qa_pages' ) {
				$all_records = QAHM_DB_Functions::get_qa_pages( null, '', PHP_INT_MAX );
			} else {
				$all_records = QAHM_DB_Functions::{$resolver['accessor']}( null );
			}
			if ( ! is_array( $all_records ) ) {
				$all_records = array();
			}

			$is_operator = $this->is_operator_condition( $condition );
			$filter_values = ( ! $is_operator && is_array( $condition ) ) ? $condition : array( $condition );
			$id_key = $resolver['id_key'];
			$id_values = array();

			foreach ( $all_records as $record ) {
				// _composite_ua: synthesize from UAos + UAbrowser (same as restore_master_columns)
				if ( $db_col === '_composite_ua' ) {
					$ua_os = isset( $record['UAos'] ) ? $record['UAos'] : '';
					$ua_browser = isset( $record['UAbrowser'] ) ? $record['UAbrowser'] : '';
					$record_val = $ua_os . ' ' . $ua_browser;
				} else {
					$record_val = isset( $record[ $db_col ] ) ? $record[ $db_col ] : null;
				}
				if ( $record_val === null ) {
					continue;
				}
				if ( $is_operator ) {
					// Operator format: match using operator semantics
					if ( $this->match_operator_condition( $record_val, $condition ) ) {
						$id_values[] = (int) $record[ $id_key ];
					}
				} else {
					// Flat array: exact match via in_array
					if ( in_array( $record_val, $filter_values, false ) ) {
						$id_values[] = (int) $record[ $id_key ];
					}
				}
			}

			// Sentinel: if no IDs matched, use -1 to guarantee zero results
			if ( ! empty( $id_values ) ) {
				// Merge with existing filter on same ID column if present
				if ( isset( $converted[ $id_col ] ) ) {
					$existing = is_array( $converted[ $id_col ] ) ? $converted[ $id_col ] : array( $converted[ $id_col ] );
					$intersected = array_values( array_intersect( $existing, $id_values ) );
					$converted[ $id_col ] = ! empty( $intersected ) ? $intersected : array( -1 );
				} else {
					$converted[ $id_col ] = $id_values;
				}
			} else {
				$converted[ $id_col ] = array( -1 );
			}
		}

		return ! empty( $converted ) ? $converted : null;
	}

	/**
	 * Restore master columns from ID-based records to string values
	 *
	 * Converts ID columns (page_id, source_id, etc.) to their string equivalents
	 * (url, utm_source, etc.) using DB master tables. All columns are processed
	 * by the same routine — no column-specific branching.
	 *
	 * 4-step process:
	 * 1. Collect unique IDs per resolver group
	 * 2. Bulk DB fetch per resolver group (one query each)
	 * 3. Build hash maps from DB results
	 * 4. Bulk replace IDs with string values in records
	 *
	 * @param array $data Records with ID columns from Storage
	 * @param array $needed_columns Map of material_column => resolve_via (only master-ref columns that are needed)
	 * @return array Records with ID columns replaced by string columns
	 */
	private function restore_master_columns( $data, $needed_columns, $tracking_id = null ) {
		if ( empty( $data ) || empty( $needed_columns ) ) {
			return $data;
		}

		// Group needed columns by their resolve_via (ID column)
		// e.g., [ 'page_id' => ['url','title'], 'source_id' => ['utm_source','source_domain','referrer'] ]
		$groups = array();
		foreach ( $needed_columns as $material_col => $id_col ) {
			if ( ! isset( $groups[ $id_col ] ) ) {
				$groups[ $id_col ] = array();
			}
			$groups[ $id_col ][] = $material_col;
		}

		// Step 1: Collect unique IDs per group
		$unique_ids = array();
		foreach ( $groups as $id_col => $cols ) {
			$ids = array();
			// Fixed-mapping resolvers (accessor=null) allow ID=0 (e.g., GA4 unknown age/gender/region)
			$allow_zero = isset( self::MASTER_RESOLVER_MAP[ $id_col ] )
				&& self::MASTER_RESOLVER_MAP[ $id_col ]['accessor'] === null;
			foreach ( $data as $row ) {
				if ( isset( $row[ $id_col ] ) ) {
					$val = (int) $row[ $id_col ];
					if ( $val > 0 || ( $allow_zero && $val === 0 ) ) {
						$ids[ $val ] = true;
					}
				}
			}
			$unique_ids[ $id_col ] = array_keys( $ids );
		}

		// Step 2 + 3: Bulk DB fetch + build hash maps
		$hash_maps = array();
		foreach ( $groups as $id_col => $cols ) {
			if ( empty( $unique_ids[ $id_col ] ) ) {
				$hash_maps[ $id_col ] = array();
				continue;
			}

			if ( ! isset( self::MASTER_RESOLVER_MAP[ $id_col ] ) ) {
				continue;
			}

			$resolver = self::MASTER_RESOLVER_MAP[ $id_col ];

			if ( $resolver['accessor'] === null ) {
				// Fixed mapping (device_id, age_bracket, gender, country_id, region_id)
				$map = array();
				foreach ( $unique_ids[ $id_col ] as $id ) {
					$map[ $id ] = array();
					foreach ( $resolver['columns'] as $mat_col => $db_col ) {
						if ( ! in_array( $mat_col, $cols, true ) ) {
							continue;
						}
						if ( $db_col === '_fixed_device_type' ) {
							$map[ $id ][ $mat_col ] = isset( self::DEVICE_TYPE_MAP[ $id ] ) ? self::DEVICE_TYPE_MAP[ $id ] : null;
						} elseif ( $db_col === '_fixed_age_bracket' ) {
							$map[ $id ][ $mat_col ] = isset( self::AGE_BRACKET_MAP[ $id ] ) ? self::AGE_BRACKET_MAP[ $id ] : null;
						} elseif ( $db_col === '_fixed_gender' ) {
							$map[ $id ][ $mat_col ] = isset( self::GENDER_MAP[ $id ] ) ? self::GENDER_MAP[ $id ] : null;
						} elseif ( $db_col === '_fixed_country_code' ) {
							// uint16 → 2文字国コードに逆変換
							$ch1 = chr( ( $id >> 8 ) & 0xFF );
							$ch2 = chr( $id & 0xFF );
							$map[ $id ][ $mat_col ] = ( $id > 0 ) ? $ch1 . $ch2 : null;
						} elseif ( $db_col === '_fixed_region_name' ) {
							$map[ $id ][ $mat_col ] = isset( self::REGION_MAP[ $id ] ) ? self::REGION_MAP[ $id ] : null;
						}
					}
				}
				$hash_maps[ $id_col ] = $map;
				continue;
			}

			// DB query — special handling for tracking_id-dependent accessors
			if ( $resolver['accessor'] === '_gsc_query_log' ) {
				// GSC query_id → keyword: requires tracking_id for table name
				$db_rows = $tracking_id
					? QAHM_DB_Functions::get_gsc_query_logs( $tracking_id, $unique_ids[ $id_col ] )
					: array();
			} elseif ( $resolver['accessor'] === 'get_qa_readers' ) {
				$db_rows = QAHM_DB_Functions::get_qa_readers( $unique_ids[ $id_col ], null, null, null, PHP_INT_MAX );
			} else {
				$db_rows = QAHM_DB_Functions::{$resolver['accessor']}( $unique_ids[ $id_col ] );
			}
			if ( ! is_array( $db_rows ) ) {
				$db_rows = array();
			}

			$map = array();
			$id_key = $resolver['id_key'];
			foreach ( $db_rows as $db_row ) {
				$id = (int) $db_row[ $id_key ];
				$entry = array();
				foreach ( $resolver['columns'] as $mat_col => $db_col ) {
					if ( ! in_array( $mat_col, $cols, true ) ) {
						continue;
					}
					if ( $db_col === '_composite_ua' ) {
						// ua = UAos + " " + UAbrowser
						$ua_os = isset( $db_row['UAos'] ) ? $db_row['UAos'] : '';
						$ua_browser = isset( $db_row['UAbrowser'] ) ? $db_row['UAbrowser'] : '';
						$entry[ $mat_col ] = $ua_os . ' ' . $ua_browser;
					} else {
						$entry[ $mat_col ] = isset( $db_row[ $db_col ] ) ? $db_row[ $db_col ] : null;
					}
				}
				$map[ $id ] = $entry;
			}
			$hash_maps[ $id_col ] = $map;
		}

		// Step 4: Bulk replace — iterate records once, add string columns from ID values
		// ID columns are kept in the record (user may have them in keep list)
		// decode_data will select only the columns the user requested
		foreach ( $data as &$row ) {
			foreach ( $groups as $id_col => $cols ) {
				if ( ! isset( $row[ $id_col ] ) ) {
					foreach ( $cols as $mat_col ) {
						$row[ $mat_col ] = null;
					}
					continue;
				}

				$id_val = (int) $row[ $id_col ];
				if ( isset( $hash_maps[ $id_col ][ $id_val ] ) ) {
					foreach ( $hash_maps[ $id_col ][ $id_val ] as $mat_col => $str_val ) {
						$row[ $mat_col ] = $str_val;
					}
				} else {
					foreach ( $cols as $mat_col ) {
						$row[ $mat_col ] = null;
					}
				}
			}
		}
		unset( $row );

		// #1105: 広告トラフィックの source_domain 補完（表示時のみ）
		foreach ( $data as &$row ) {
			if ( isset( $row['source_domain'] ) && isset( $row['utm_source'] ) ) {
				$row['source_domain'] = QAHM_Base::resolve_ad_source_domain(
					$row['source_domain'],
					$row['utm_source'],
					isset( $row['utm_medium'] ) ? $row['utm_medium'] : ''
				);
			}
		}
		unset( $row );

		return $data;
	}

	/**
	 * 辞書解決（allpv の ID 列 → 文字列列）の public ファサード（view_pv 廃止 RM3-A #1391・共有インフラ）
	 *
	 * Material/QAL スタック外（CSV エクスポート＝RM3 本体・将来の R1 等）から、allpv 列DB の ID 列
	 * （page_id/source_id/medium_id/campaign_id/content_id/reader_id 等）を持つ行配列に対し、
	 * MASTER_RESOLVER_MAP 駆動の一括 DB 解決で文字列列（url・title・utm_source 等の utm 系・os・browser・country_code 等）を付与する。
	 *
	 * 呼び出し側は「欲しい文字列列名のリスト」を渡すだけでよく、内部の ID 対応は知らなくてよい。
	 * 解決の実体は private restore_master_columns（唯一の正本）へ委譲＝辞書解決の二重実装を防ぐ。
	 * 既存の Material パイプライン（restore_master_columns の直接利用）には一切影響しない（純増）。
	 *
	 * 注意: material 列名が複数 resolver に現れる場合は MASTER_RESOLVER_MAP の宣言順で先頭一致を取る。
	 * 現状の唯一の重複 'country_code' は reader_id 経由で解決される（country_id の GA4 固定マップ側ではない）。
	 * allpv 由来の行は reader_id を持つため reader_id 解決が正しい。
	 *
	 * @param array  $rows             ID 列を含む行配列。
	 * @param array  $material_columns 付与したい文字列列名の配列（例 array( 'url', 'title', 'utm_source', 'os', 'country_code' )）。
	 *                                 MASTER_RESOLVER_MAP に対応の無い列は無視する。
	 * @param string $tracking_id      tracking_id（GSC 等 tid 依存の解決に使用・省略可）。
	 * @return array 文字列列を付与した行配列（ID 列は保持）。$rows か $material_columns が空ならそのまま返す。
	 */
	public function resolve_master_columns( $rows, $material_columns, $tracking_id = null ) {
		if ( empty( $rows ) || empty( $material_columns ) ) {
			return $rows;
		}

		// 要求された material 列名を MASTER_RESOLVER_MAP の逆引きで ( material_col => id_col ) へ。
		$needed_columns = array();
		foreach ( (array) $material_columns as $material_col ) {
			foreach ( self::MASTER_RESOLVER_MAP as $id_col => $resolver ) {
				if ( isset( $resolver['columns'][ $material_col ] ) ) {
					$needed_columns[ $material_col ] = $id_col;
					break;
				}
			}
		}

		if ( empty( $needed_columns ) ) {
			return $rows;
		}

		return $this->restore_master_columns( $rows, $needed_columns, $tracking_id );
	}

	/**
	 * T100b-h: Apply value_mappings — 固定マップ駆動の label 列自動付与
	 *
	 * yaml の `value_mappings` 宣言で定義された ID→ラベル マップを使い、
	 * 結果行の ID 列値を引いて label_column を新規追加する。resolve_via
	 * （MASTER_RESOLVER_MAP 駆動の動的解決）と併存する静的解決機構。
	 *
	 * 設計判断: マップに存在しない ID は防御として `(string)$id` で文字列化する。
	 * 既存のラベル列値があった場合は上書きしない（resolve_via 経路で既に解決済みの場合
	 * を尊重する。allpv.device_type は resolve_via 経由で先に解決され、value_mappings は
	 * 同じ値を上書きしようとするが、no-op で済ます方が安全）。
	 *
	 * @param array $data 結果行配列
	 * @param array $value_mapped_columns label_col => [ id_col, map ] のマップ
	 * @return array label 列が追加された結果行配列
	 */
	private function apply_value_mappings( $data, $value_mapped_columns ) {
		if ( empty( $data ) || empty( $value_mapped_columns ) ) {
			return $data;
		}

		// T100b-h: 行値・map キー両方の型ゆれに耐える正規化マップを事前構築。
		// PHP 配列は整数キー int(1) を string '1' でも引けるが、Storage 経路が
		// '01' / float 1.0 / 真の string 'PC' 等を返す可能性まで含めて死なないよう、
		// int / string 両キーで二重インデックスしたルックアップ表を一度だけ作る。
		$normalized = array();
		foreach ( $value_mapped_columns as $label_col => $spec ) {
			$lookup = array();
			foreach ( $spec['map'] as $k => $v ) {
				// yaml は integer key で来るのが通常だが、string key も保険で受ける
				$lookup[ (string) $k ] = $v;
				if ( is_numeric( $k ) ) {
					$lookup[ (string) (int) $k ] = $v; // '01' 排除 → '1'
				}
			}
			$normalized[ $label_col ] = array(
				'id_col' => $spec['id_col'],
				'lookup' => $lookup,
			);
		}

		foreach ( $data as &$row ) {
			foreach ( $normalized as $label_col => $spec ) {
				// 既存値がある場合は尊重（resolve_via 経路で既に解決済みの可能性、設計書 §4.2）。
				// no-op で idempotent を担保し、両経路の結果差異を生まない。
				// 値が null でも「キーは既に存在する」ので array_key_exists で判定（isset
				// だと null を「未設定」と誤判定して上書きしてしまう）。
				if ( array_key_exists( $label_col, $row ) ) {
					continue;
				}
				$id_col = $spec['id_col'];
				if ( ! isset( $row[ $id_col ] ) ) {
					$row[ $label_col ] = null;
					continue;
				}
				$id_val = $row[ $id_col ];

				// 行値を string に正規化してルックアップ。
				// 数値型なら (int) 経由で '01' のような先頭ゼロも吸収する。
				if ( is_numeric( $id_val ) ) {
					$key = (string) (int) $id_val;
				} else {
					$key = (string) $id_val;
				}
				if ( isset( $spec['lookup'][ $key ] ) ) {
					$row[ $label_col ] = $spec['lookup'][ $key ];
				} else {
					// マップ外: 生値の文字列化で防御（id_col の値をそのまま表示）。
					// null や error にしないのは、データ破損時にレポート全体が落ちないため。
					$row[ $label_col ] = (string) $id_val;
				}
			}
		}
		unset( $row );

		return $data;
	}

	/**
	 * Compute GSC virtual columns (ctr, position)
	 *
	 * @param array $data    レコード配列
	 * @param array $virtual_columns 仮想カラム名リスト
	 * @return array 仮想カラムが追加されたレコード配列
	 */
	private function compute_gsc_virtual_columns( $data, $virtual_columns ) {
		$need_ctr               = in_array( 'ctr', $virtual_columns, true );
		$need_position          = in_array( 'position', $virtual_columns, true );
		$need_position_weighted = in_array( 'position_weighted', $virtual_columns, true );

		if ( ! $need_ctr && ! $need_position && ! $need_position_weighted ) {
			return $data;
		}

		foreach ( $data as &$row ) {
			if ( $need_ctr ) {
				$clicks      = isset( $row['clicks'] ) ? (int) $row['clicks'] : 0;
				$impressions = isset( $row['impressions'] ) ? (int) $row['impressions'] : 0;
				$row['ctr']  = $impressions > 0 ? round( $clicks / $impressions, 4 ) : 0.0;
			}
			if ( $need_position ) {
				$pos_x100        = isset( $row['position_x100'] ) ? (int) $row['position_x100'] : 0;
				$row['position'] = $pos_x100 / 100.0;
			}
			if ( $need_position_weighted ) {
				$pos_x100    = isset( $row['position_x100'] ) ? (int) $row['position_x100'] : 0;
				$impressions = isset( $row['impressions'] ) ? (int) $row['impressions'] : 0;
				$row['position_weighted'] = ( $pos_x100 / 100.0 ) * $impressions;
			}
		}
		unset( $row );

		return $data;
	}

	/**
	 * Apply join operation
	 *
	 * Executes join between left view (from filter step) and right material/view.
	 * Sequence: extract join keys from left -> fetch right data -> hash-map merge O(N+M).
	 *
	 * @param array $executable_qal Current ExecutableQAL structure
	 * @param string $view_name Name of the view to process
	 * @return array Result data with view_name, material_name, record_count, and data, or error array
	 */
	public function qal_join_apply( $executable_qal, $view_name ) {
		// --- Validate view definition ---
		if ( ! isset( $executable_qal['qal']['make'][ $view_name ] ) ) {
			return array(
				'error_code' => 'E_UNKNOWN_VIEW',
				/* translators: %s: view name */
				'message' => sprintf( __( "View '%s' not found in make section.", 'qa-heatmap-analytics' ), $view_name ),
				'location' => "make.{$view_name}"
			);
		}

		$view_def = $executable_qal['qal']['make'][ $view_name ];
		$join_def = isset( $view_def['join'] ) ? $view_def['join'] : null;

		// T29: Minimal null/type checks only — full syntax validation is done by Executor.qal_validate_join()
		if ( ! is_array( $join_def ) || ! isset( $join_def['with'] ) || ! isset( $join_def['on'] ) ) {
			return array(
				'error_code' => 'E_INVALID_JOIN',
				'message' => __( 'Join definition must contain "with" and "on" fields.', 'qa-heatmap-analytics' ),
				'location' => "make.{$view_name}.join"
			);
		}

		$right_name = $join_def['with'];
		$on_conditions = $join_def['on'];
		$if_not_match = isset( $join_def['if not match'] ) ? $join_def['if not match'] : 'keep-left';
		$fill_value = isset( $join_def['fill'] ) ? $join_def['fill'] : null;

		// Issue #1199: WP-backed materials (wp_posts etc.) cannot be used on the right side of a join.
		// qal_filter_apply() already rejects them when they appear on the left side (from[0]).
		// Without this guard, the executor proceeds to column-DB extraction paths
		// (extract_physical_columns / classify_columns_for_storage etc.) for the right material,
		// producing unrelated errors like E_UNKNOWN_COLUMN that don't reveal the root cause.
		// Keep the material list in sync with qal_filter_apply()'s $wp_materials.
		$wp_materials = array( 'wp_posts' );
		if ( in_array( $right_name, $wp_materials, true ) ) {
			return array(
				'error_code' => 'E_INVALID_JOIN',
				/* translators: %s: material name */
				'message'    => sprintf( __( "Material '%s' does not support join. See docs/specs/assistant/data-sources.md.", 'qa-heatmap-analytics' ), $right_name ),
				'location'   => "make.{$view_name}.join.with",
			);
		}

		if ( ! is_array( $on_conditions ) || empty( $on_conditions ) ) {
			return array(
				'error_code' => 'E_INVALID_JOIN',
				'message' => __( 'Join "on" must be a non-empty array.', 'qa-heatmap-analytics' ),
				'location' => "make.{$view_name}.join.on"
			);
		}

		$on = $on_conditions[0];
		if ( ! isset( $on['left'] ) || ! isset( $on['right'] ) ) {
			return array(
				'error_code' => 'E_INVALID_JOIN',
				'message' => __( 'Each "on" entry must have "left" and "right" fields.', 'qa-heatmap-analytics' ),
				'location' => "make.{$view_name}.join.on[0]"
			);
		}

		// --- Parse on column references ---
		// T36b: from[0] may be a view name in view chain scenarios.
		// Use views[view_name]['material_name'] (saved by filter step) to resolve the original material.
		$from_source = $view_def['from'][0];
		if ( isset( $executable_qal['views'][ $from_source ]['material_name'] ) ) {
			$left_material_name = $executable_qal['views'][ $view_name ]['material_name'];
		} else {
			$left_material_name = $from_source;
		}
		$left_on_col = $this->parse_column_ref( $on['left'], $left_material_name );
		$right_on_col = $this->parse_column_ref( $on['right'], $right_name );

		// T87: side-aware error message. Pre-validation in Executor.qal_validate_join()
		// should normally catch these, so this is a defensive path. We still report
		// which side was malformed and what prefix was expected.
		if ( $left_on_col === null ) {
			$left_expected_prefix = $left_material_name . '.';
			return array(
				'error_code' => 'E_INVALID_JOIN',
				/* translators: 1: expected material prefix, 2: received column reference */
				'message' => sprintf( __( 'Join on.left must reference the from material "%1$s". Got: %2$s', 'qa-heatmap-analytics' ), $left_material_name, $on['left'] ),
				'location' => "make.{$view_name}.join.on[0].left",
				'details' => array(
					'side' => 'left',
					'received_value' => $on['left'],
					'expected_prefix' => $left_expected_prefix,
					'hint' => "change '" . $on['left'] . "' to '" . $left_expected_prefix . "<column>'",
				)
			);
		}
		if ( $right_on_col === null ) {
			$right_expected_prefix = $right_name . '.';
			return array(
				'error_code' => 'E_INVALID_JOIN',
				/* translators: 1: expected join.with name, 2: received column reference */
				'message' => sprintf( __( 'Join on.right must reference join.with "%1$s". Got: %2$s', 'qa-heatmap-analytics' ), $right_name, $on['right'] ),
				'location' => "make.{$view_name}.join.on[0].right",
				'details' => array(
					'side' => 'right',
					'received_value' => $on['right'],
					'expected_prefix' => $right_expected_prefix,
					'hint' => "change '" . $on['right'] . "' to '" . $right_expected_prefix . "<column>'",
				)
			);
		}

		// --- Get left view data (already loaded by filter step) ---
		if ( ! isset( $executable_qal['views'][ $view_name ] ) ) {
			return array(
				'error_code' => 'E_UNKNOWN_VIEW',
				/* translators: %s: view name */
				'message' => sprintf( __( "View '%s' data not found. Filter step may not have completed.", 'qa-heatmap-analytics' ), $view_name ),
				'location' => "make.{$view_name}"
			);
		}

		$left_view = $executable_qal['views'][ $view_name ];
		$left_data = isset( $left_view['data'] ) ? $left_view['data'] : array();

		// Phase 1: Extract join key values from left view
		// The left data has been decoded by decode_data (logical column names, integer IDs kept as-is)
		$left_key_values = array();
		foreach ( $left_data as $row ) {
			if ( isset( $row[ $left_on_col ] ) ) {
				$val = (int) $row[ $left_on_col ];
				if ( $val > 0 ) {
					$left_key_values[ $val ] = true;
				}
			}
		}
		$match_ids = array_keys( $left_key_values );

		if ( empty( $match_ids ) ) {
			// No keys to match -- if keep-left, return left data with null fills
			if ( $if_not_match === 'keep-left' ) {
				$merged = $this->fill_right_columns( $left_data, $view_def['keep'], $right_name, $fill_value );
				return array(
					'view_name'    => $view_name,
					'material_name' => $left_material_name,
					'record_count' => $this->wrap_count( $merged ),
					'data'         => $merged,
				);
			}
			// drop: no matches means no rows
			return array(
				'view_name'    => $view_name,
				'material_name' => $left_material_name,
				'record_count' => 0,
				'data'         => array(),
			);
		}

		// Phase 2: Determine right material physical columns
		$keep_columns = isset( $view_def['keep'] ) ? $view_def['keep'] : array();

		// T87c: Extract calc-referenced columns for left and right materials so the
		// JOIN pipeline fetches them, decodes them, merges them, and preserves them
		// through the strip step. Calc input columns are not emitted in the final
		// output (Executor.qal_execute_calc groups by keep_short only); they live
		// only long enough for the aggregator to read them.
		$left_calc_cols  = array(); // logical column names on left material
		$right_calc_cols = array(); // logical column names on right material
		if ( isset( $view_def['calc'] ) && is_array( $view_def['calc'] ) ) {
			foreach ( $view_def['calc'] as $calc_expr ) {
				if ( ! is_string( $calc_expr ) ) {
					continue;
				}
				$paren_open  = strpos( $calc_expr, '(' );
				$paren_close = strpos( $calc_expr, ')' );
				if ( $paren_open === false || $paren_close === false || $paren_close <= $paren_open + 1 ) {
					continue;
				}
				$full_col = substr( $calc_expr, $paren_open + 1, $paren_close - $paren_open - 1 );

				// Resolve to whichever side the prefix actually belongs to.
				// Try both materials and pick the longer prefix match so that
				// dotted names like "events.purchase" win over "events" when
				// both are valid material names in the same view scope.
				$right_col = $this->parse_column_ref( $full_col, $right_name );
				$left_col  = $this->parse_column_ref( $full_col, $left_material_name );
				$pick_right = false;
				if ( $right_col !== null && $left_col !== null ) {
					$pick_right = ( strlen( $right_name ) >= strlen( $left_material_name ) );
				} elseif ( $right_col !== null ) {
					$pick_right = true;
				}

				if ( $pick_right ) {
					if ( ! in_array( $right_col, $right_calc_cols, true ) ) {
						$right_calc_cols[] = $right_col;
					}
				} elseif ( $left_col !== null ) {
					if ( ! in_array( $left_col, $left_calc_cols, true ) ) {
						$left_calc_cols[] = $left_col;
					}
				}
			}
		}

		// Check if right_name is a view or a material
		$is_view_join = isset( $executable_qal['views'][ $right_name ] );

		if ( $is_view_join ) {
			// --- View join: get data from existing view ---
			$right_data = isset( $executable_qal['views'][ $right_name ]['data'] )
				? $executable_qal['views'][ $right_name ]['data']
				: array();
		} else {
			// --- Material join: delegate to Storage layer ---
			$right_material_def = $this->get_material_definition( $right_name );
			if ( isset( $right_material_def['error_code'] ) ) {
				return $right_material_def;
			}

			$right_physical_columns = $this->extract_physical_columns( $keep_columns, $right_name, $right_material_def, true );
			if ( isset( $right_physical_columns['error_code'] ) ) {
				return $right_physical_columns;
			}

			// T87c: add right calc-referenced physical columns so Storage fetches them.
			// Same pattern as the right join key extension below (L1619-1622).
			foreach ( $right_calc_cols as $calc_col ) {
				$calc_physical = $this->find_physical_column( $calc_col, $right_material_def, $right_name );
				if ( $calc_physical === null ) {
					$calc_physical = $calc_col;
				}
				if ( ! in_array( $calc_physical, $right_physical_columns, true ) ) {
					$right_physical_columns[] = $calc_physical;
				}
			}

			// T99: Classify right physical columns — separate master-reference columns
			// (resolve_via, e.g. allpv.url -> page_id) from direct columnDB columns.
			// Mirrors the non-JOIN path (qal_filter_apply): Storage fetches ID columns;
			// master_ref restored below.
			$right_classified = $this->classify_columns_for_storage( $right_physical_columns, $right_material_def );
			$right_storage_columns = $right_classified['storage_columns'];
			$right_master_ref = $right_classified['master_ref'];
			// T100b-h: JOIN 経路も value_mappings 自動付与に対応
			$right_value_mapped_columns = isset( $right_classified['value_mapped_columns'] )
				? $right_classified['value_mapped_columns']
				: array();

			// Find physical column name for the right join key
			$right_physical_key = $this->find_physical_column( $right_on_col, $right_material_def, $right_name );
			if ( $right_physical_key === null ) {
				// The join key might be a physical column name already
				$right_physical_key = $right_on_col;
			}

			$tracking_id = $executable_qal['qal']['tracking_id'];
			$time_range = $executable_qal['qal']['time'];

			global $qahm_qal_storage;
			if ( ! is_object( $qahm_qal_storage ) || ! method_exists( $qahm_qal_storage, 'fetch_joined_data' ) ) {
				return array(
					'error_code' => 'E_DEPENDENCY_NOT_AVAILABLE',
					'message' => __( 'Required Storage layer dependency is not available.', 'qa-heatmap-analytics' ),
					'location' => 'material'
				);
			}

			$storage_result = $qahm_qal_storage->fetch_joined_data(
				$tracking_id,
				$right_name,
				$time_range,
				$right_physical_key,
				$match_ids,
				$right_storage_columns
			);

			if ( isset( $storage_result['error_code'] ) ) {
				return $storage_result;
			}

			// T99: Restore master columns (ID -> string) before decode_data and before
			// Phase 4 1:N expansion. Mirrors the non-JOIN path (qal_filter_apply).
			// Must run on the pre-expanded right rowset so id-collection cost stays
			// O(right_rows), not O(left*right).
			$right_restored = $storage_result['data'];
			if ( ! empty( $right_master_ref ) && is_array( $right_restored ) && ! empty( $right_restored ) ) {
				$right_restored = $this->restore_master_columns( $right_restored, $right_master_ref, $tracking_id );
			}
			// T100b-h: value_mappings の label 列を JOIN 右側にも付与
			if ( ! empty( $right_value_mapped_columns ) && is_array( $right_restored ) && ! empty( $right_restored ) ) {
				$right_restored = $this->apply_value_mappings( $right_restored, $right_value_mapped_columns );
			}

			// Decode right data (ID to string conversion for right material columns)
			// Ensure right join key is in keep_columns so decode_data preserves it for hash-map merge
			$right_key_qualified = $right_name . '.' . $right_on_col;
			if ( ! $this->wrap_in_array( $right_key_qualified, $keep_columns, true ) ) {
				$keep_columns[] = $right_key_qualified;
			}
			// T87c: ensure right calc-referenced columns are decoded too
			foreach ( $right_calc_cols as $calc_col ) {
				$qualified = $right_name . '.' . $calc_col;
				if ( ! $this->wrap_in_array( $qualified, $keep_columns, true ) ) {
					$keep_columns[] = $qualified;
				}
			}
			$right_data = $this->decode_data( $right_restored, $keep_columns, $right_name, $right_material_def );
		}

		// Phase 3: Build hash map from right data for O(N+M) merge
		$right_map = array();
		foreach ( $right_data as $row ) {
			$key_val = null;
			if ( isset( $row[ $right_on_col ] ) ) {
				$key_val = (int) $row[ $right_on_col ];
			}
			if ( $key_val !== null && $key_val > 0 ) {
				// 1:N support -- use array of rows
				if ( ! isset( $right_map[ $key_val ] ) ) {
					$right_map[ $key_val ] = array();
				}
				$right_map[ $key_val ][] = $row;
			}
		}

		// Determine right column names for merge.
		// T87c: include right calc-referenced columns so the aggregator can read them.
		$right_column_names = $this->get_right_column_names( $view_def['keep'], $right_name );
		foreach ( $right_calc_cols as $calc_col ) {
			if ( ! in_array( $calc_col, $right_column_names, true ) ) {
				$right_column_names[] = $calc_col;
			}
		}

		// Phase 4: Merge left and right
		$merged_data = array();
		foreach ( $left_data as $left_row ) {
			$left_key = isset( $left_row[ $left_on_col ] ) ? (int) $left_row[ $left_on_col ] : 0;

			if ( isset( $right_map[ $left_key ] ) ) {
				// Match found -- expand for 1:N
				foreach ( $right_map[ $left_key ] as $right_row ) {
					$merged_row = $left_row;
					foreach ( $right_column_names as $col ) {
						$merged_row[ $col ] = isset( $right_row[ $col ] ) ? $right_row[ $col ] : $fill_value;
					}
					$merged_data[] = $merged_row;
				}
			} else {
				// No match
				if ( $if_not_match === 'keep-left' ) {
					$merged_row = $left_row;
					foreach ( $right_column_names as $col ) {
						$merged_row[ $col ] = $fill_value;
					}
					$merged_data[] = $merged_row;
				}
				// if 'drop': skip this row
			}
		}

		return array(
			'view_name'    => $view_name,
			'material_name' => $left_material_name,
			'record_count' => $this->wrap_count( $merged_data ),
			'data'         => $merged_data,
		);
	}

	/**
	 * Parse a qualified column reference (e.g., "allpv.pv_id") and return the column name part
	 *
	 * @param string $ref Qualified column reference
	 * @param string $expected_material Expected material/view name
	 * @return string|null Column name or null if reference is invalid
	 */
	private function parse_column_ref( $ref, $expected_material ) {
		if ( ! is_string( $ref ) ) {
			return null;
		}
		$prefix = $expected_material . '.';
		if ( strpos( $ref, $prefix ) === 0 ) {
			$col = substr( $ref, strlen( $prefix ) );
			return ( $col !== '' && $col !== false ) ? $col : null;
		}
		return null;
	}

	/**
	 * Get column names belonging to the right material from keep list
	 *
	 * @param array $keep_columns Full keep list
	 * @param string $right_name Right material/view name
	 * @return array Column names (without material prefix)
	 */
	private function get_right_column_names( $keep_columns, $right_name ) {
		$cols = array();
		$prefix = $right_name . '.';
		foreach ( $keep_columns as $col ) {
			if ( strpos( $col, $prefix ) === 0 ) {
				$name = substr( $col, strlen( $prefix ) );
				if ( $name !== '' && $name !== false ) {
					$cols[] = $name;
				}
			}
		}
		return $cols;
	}

	/**
	 * Fill right material columns with fill_value for all rows
	 *
	 * @param array $data Left data rows
	 * @param array $keep_columns Full keep list
	 * @param string $right_name Right material name
	 * @param mixed $fill_value Fill value for unmatched rows
	 * @return array Data with right columns filled
	 */
	private function fill_right_columns( $data, $keep_columns, $right_name, $fill_value ) {
		$right_cols = $this->get_right_column_names( $keep_columns, $right_name );
		if ( empty( $right_cols ) ) {
			return $data;
		}
		foreach ( $data as &$row ) {
			foreach ( $right_cols as $col ) {
				$row[ $col ] = $fill_value;
			}
		}
		unset( $row );
		return $data;
	}

	// =========================================================================
	// Goal Virtual Columns (is_goal_1..10)
	// =========================================================================

	/**
	 * Detect is_goal_N columns from virtual columns list
	 *
	 * @param array $virtual_columns Virtual column names from classify_columns_for_storage
	 * @return int[] Goal numbers found (e.g., [1, 3, 8])
	 */
	private function detect_goal_virtual_columns( $virtual_columns ) {
		$goal_numbers = array();
		foreach ( $virtual_columns as $col ) {
			if ( strpos( $col, 'is_goal_' ) === 0 ) {
				$num_str = substr( $col, 8 );
				if ( ctype_digit( $num_str ) ) {
					$num = (int) $num_str;
					if ( $num >= 1 && $num <= 10 ) {
						$goal_numbers[] = $num;
					}
				}
			}
		}
		return $goal_numbers;
	}

	/**
	 * Convert is_goal_N filter conditions to pv_id IN conditions
	 *
	 * Detects is_goal_N keys in filter conditions, fetches goal cache data,
	 * builds pv_id sets, and replaces with pv_id IN filter.
	 *
	 * @param array $filter_conditions Original filter conditions
	 * @param string $tracking_id Tracking ID
	 * @param array $time_range Time range with 'start' and 'end'
	 * @return array ['filter_conditions' => modified filters, 'pv_id_set' => array|null]
	 */
	private function convert_goal_filters_to_pv_id( $filter_conditions, $tracking_id, $time_range ) {
		global $qahm_file_functions;
		$per_goal_sets = array();
		$goal_filter_keys = array();

		foreach ( $filter_conditions as $col => $condition ) {
			if ( strpos( $col, 'is_goal_' ) !== 0 ) {
				continue;
			}
			$num_str = substr( $col, 8 );
			if ( ! ctype_digit( $num_str ) ) {
				continue;
			}
			$goal_number = (int) $num_str;
			if ( $goal_number < 1 || $goal_number > 10 ) {
				continue;
			}

			// Always mark for removal to prevent virtual column leaking to Storage
			$goal_filter_keys[] = $col;

			// Only build pv_id set when condition value is 1 (goal achieved)
			$filter_value = is_array( $condition ) ? reset( $condition ) : $condition;
			if ( (int) $filter_value !== 1 ) {
				continue;
			}

			// #1598: 期間の入口で日付だけの形に揃える（Storage 側の他材料と同じ作法）。
			// ISO 8601 のまま渡すと get_goal_data_by_number 内の 'Y-m-d' 比較で開始日が落ちる。
			$start_date = isset( $time_range['start'] ) && is_string( $time_range['start'] )
				? $this->wrap_substr( $time_range['start'], 0, 10 )
				: '';
			$end_date = isset( $time_range['end'] ) && is_string( $time_range['end'] )
				? $this->wrap_substr( $time_range['end'], 0, 10 )
				: '';

			$goal_pv_set = array();
			$goal_data = $qahm_file_functions->get_goal_data_by_number( $tracking_id, $goal_number, $start_date, $end_date );
			if ( ! empty( $goal_data ) ) {
				foreach ( $goal_data as $session ) {
					foreach ( $session as $pv ) {
						if ( isset( $pv['pv_id'] ) ) {
							$goal_pv_set[ $pv['pv_id'] ] = true;
						}
					}
				}
			}
			$per_goal_sets[] = $goal_pv_set;
		}

		// Remove all is_goal_N keys from filter conditions (prevent leaking to Storage)
		foreach ( $goal_filter_keys as $key ) {
			unset( $filter_conditions[ $key ] );
		}

		if ( empty( $per_goal_sets ) ) {
			return array(
				'filter_conditions' => $filter_conditions,
				'pv_id_set'         => null,
			);
		}

		// AND semantics: intersect all per-goal pv_id sets
		$goal_pv_ids = array_shift( $per_goal_sets );
		foreach ( $per_goal_sets as $set ) {
			$goal_pv_ids = array_intersect_key( $goal_pv_ids, $set );
		}

		// Add pv_id IN condition
		if ( ! empty( $goal_pv_ids ) ) {
			$pv_id_list = array_keys( $goal_pv_ids );
			if ( isset( $filter_conditions['pv_id'] ) ) {
				// Intersect with existing pv_id filter
				$existing = is_array( $filter_conditions['pv_id'] )
					? $filter_conditions['pv_id']
					: array( $filter_conditions['pv_id'] );
				$pv_id_list = array_values( array_intersect( $pv_id_list, $existing ) );
			}
			if ( empty( $pv_id_list ) ) {
				$pv_id_list = array( -1 );
			}
			$filter_conditions['pv_id'] = $pv_id_list;
		} else {
			// No goal achievements found: use sentinel value to guarantee zero results
			$filter_conditions['pv_id'] = array( -1 );
		}

		return array(
			'filter_conditions' => $filter_conditions,
			'pv_id_set'         => $goal_pv_ids,
		);
	}

	/**
	 * Rewrite virtual column filter conditions for Storage layer (T83).
	 *
	 * Splits filter conditions referencing virtual columns into two streams:
	 * - A 系 (linear-transform): rewritten to physical column filters that Storage
	 *   can evaluate (e.g. position lte 10 -> position_x100 lte 1000).
	 * - B 系 (multi-column compute): retained as material_post_filters to be
	 *   applied after compute_gsc_virtual_columns.
	 *
	 * Side effects:
	 * - Adds the source physical columns required for B 系 computation to
	 *   $storage_columns (mirrors the keep-side handling at L488-511).
	 * - Pre-emptively downgrades $count_only to false when B 系 filters are
	 *   present, so the pipeline always runs single-path (D9 of design doc).
	 *
	 * Implementation constraint (performance-critic note #4):
	 *   This method performs scalar coefficient transforms only. No row loops,
	 *   no file I/O, no DB queries.
	 *
	 * Future extension: $virtual_rewrite_map could move into the materials
	 * manifest YAML when GA4 / DataForSEO add their own virtual columns.
	 *
	 * @param array       $filter_conditions Original filter conditions
	 * @param string      $material_name     Material name (e.g. 'gsc')
	 * @param array       $material_def      Material definition
	 * @param array       &$storage_columns  Storage columns (mutated)
	 * @param bool        &$count_only       count_only flag (mutated to false if B 系 present)
	 * @return array {storage_filters, material_post_filters}
	 */
	private function rewrite_virtual_filters_for_storage( $filter_conditions, $material_name, $material_def, &$storage_columns, &$count_only ) {
		$storage_filters       = $filter_conditions;
		$material_post_filters = array();

		if ( empty( $filter_conditions ) || ! is_array( $filter_conditions ) ) {
			return array(
				'storage_filters'       => $storage_filters,
				'material_post_filters' => $material_post_filters,
			);
		}

		// Build virtual_set from material definition (same pattern as classify_columns_for_storage)
		$virtual_set = array();
		if ( isset( $material_def['decoders'] ) && is_array( $material_def['decoders'] ) ) {
			foreach ( $material_def['decoders'] as $decoder ) {
				if ( ! isset( $decoder['fields'] ) || ! is_array( $decoder['fields'] ) ) {
					continue;
				}
				foreach ( $decoder['fields'] as $field ) {
					if ( ! empty( $field['virtual'] ) ) {
						$virtual_set[ $field['physical_column'] ] = true;
					}
				}
			}
		}

		if ( empty( $virtual_set ) ) {
			return array(
				'storage_filters'       => $storage_filters,
				'material_post_filters' => $material_post_filters,
			);
		}

		// A 系 / B 系 mapping for GSC virtual columns.
		// 'a': linear scalar rewrite (operator value * coefficient -> physical column filter)
		// 'b': post-filter on computed value, requires source physical columns to be fetched
		// NOTE: Material-internal map is acceptable today (3 columns).
		// When GA4 / DataForSEO add virtuals, migrate to manifest-driven (per yaml/materials-manifest).
		// T133: month is B 系 for every material that declares it — the bucket is derived from the
		// row's date column, so that column must be fetched or the post-filter would silently
		// evaluate against a missing value and drop every row.
		$virtual_rewrite_map = array(
			'allpv' => array(
				self::TIME_BUCKET_COLUMN => array(
					'kind'    => 'b',
					'sources' => array( self::TIME_BUCKET_SOURCE_MAP['allpv'] ),
				),
			),
			'gsc' => array(
				self::TIME_BUCKET_COLUMN => array(
					'kind'    => 'b',
					'sources' => array( self::TIME_BUCKET_SOURCE_MAP['gsc'] ),
				),
				'position' => array(
					'kind'        => 'a',
					'physical'    => 'position_x100',
					'coefficient' => 100,
				),
				'ctr' => array(
					'kind'    => 'b',
					'sources' => array( 'clicks', 'impressions' ),
				),
				'position_weighted' => array(
					'kind'    => 'b',
					'sources' => array( 'position_x100', 'impressions' ),
				),
			),
		);

		$material_map = isset( $virtual_rewrite_map[ $material_name ] ) ? $virtual_rewrite_map[ $material_name ] : array();

		$has_b_series = false;
		$rewritten    = array();

		foreach ( $storage_filters as $col => $condition ) {
			if ( ! isset( $virtual_set[ $col ] ) ) {
				$rewritten[ $col ] = $condition;
				continue;
			}

			// Virtual column: must be classified by virtual_rewrite_map
			$entry = isset( $material_map[ $col ] ) ? $material_map[ $col ] : null;
			if ( $entry === null ) {
				// Unknown virtual column: drop from storage filters to avoid silent 0-row,
				// retain as post-filter so it still narrows results (defensive default).
				$material_post_filters[ $col ] = $condition;
				continue;
			}

			if ( $entry['kind'] === 'a' ) {
				// A 系: scalar coefficient transform
				$physical    = $entry['physical'];
				$coefficient = $entry['coefficient'];
				$rewritten_condition = $this->scale_filter_condition( $condition, $coefficient );

				// Add the physical column to storage_columns (extra-fetch for filter)
				if ( ! in_array( $physical, $storage_columns, true ) ) {
					$storage_columns[] = $physical;
				}

				// Intersect-or-set: if the same physical column already has a filter,
				// keep the existing one and let post_filter combine (AND semantics).
				if ( isset( $rewritten[ $physical ] ) ) {
					// Both conditions need to pass: append as material_post_filter for AND-combine.
					$material_post_filters[ $col ] = $condition;
				} else {
					$rewritten[ $physical ] = $rewritten_condition;
				}
				continue;
			}

			// B 系: retain for Material post-filter, ensure source physical columns are fetched
			$has_b_series = true;
			$material_post_filters[ $col ] = $condition;
			if ( isset( $entry['sources'] ) && is_array( $entry['sources'] ) ) {
				foreach ( $entry['sources'] as $src ) {
					if ( ! in_array( $src, $storage_columns, true ) ) {
						$storage_columns[] = $src;
					}
				}
			}
		}

		// D9: pre-emptive count_only downgrade when B 系 filter is present.
		// Storage cannot apply B 系 post-filter, so its count would be inaccurate.
		if ( $has_b_series && $count_only ) {
			$count_only = false;
			if ( defined( 'QAHM_DEBUG' ) && defined( 'QAHM_DEBUG_LEVEL' ) && QAHM_DEBUG >= QAHM_DEBUG_LEVEL['staging'] ) {
				error_log( '[T83] count_only downgraded to false: B-series virtual filter present (' . $material_name . ')' );
			}
		}

		return array(
			'storage_filters'       => $rewritten,
			'material_post_filters' => $material_post_filters,
		);
	}

	/**
	 * Sentinel value used when an A 系 filter condition cannot be exactly
	 * represented on the physical column (e.g. eq 1.234 against position_x100
	 * which is integer). The physical column never holds this value, so
	 * `eq IMPOSSIBLE` is logically false-for-all-rows and `neq IMPOSSIBLE` is
	 * true-for-all-rows. Using a sentinel keeps a single rewrite shape and
	 * avoids special-casing "skip the condition" paths.
	 */
	const SCALE_IMPOSSIBLE_VALUE = -2147483647;

	/**
	 * Scale numeric operator-format filter condition by a coefficient (T83 helper).
	 *
	 * Used to rewrite A 系 virtual filters (e.g. position) into physical column filters
	 * (e.g. position_x100) by multiplying boundary values.
	 *
	 * Boundary handling preserves comparison semantics when the user supplies
	 * a value that is not an exact 0.01 step (Copilot review #154):
	 *   - gte X  -> physical >= ceil(X * coef)   (smallest physical that satisfies X)
	 *   - gt  X  -> physical >  floor(X * coef)  (== >= floor+1; X integer-multiple → strict)
	 *   - lte X  -> physical <= floor(X * coef)
	 *   - lt  X  -> physical <  ceil(X * coef)
	 *   - between [lo,hi] -> [ceil(lo*coef), floor(hi*coef)]  (lo>hi naturally yields no rows)
	 *   - eq / neq: only meaningful when X*coef is an integer; otherwise rewrite to
	 *     SCALE_IMPOSSIBLE_VALUE (eq → always false, neq → always true).
	 *   - in [...] : keep only exactly-representable elements; if all are dropped,
	 *     replace with `[SCALE_IMPOSSIBLE_VALUE]` so the IN clause matches no row.
	 *
	 * Supports operator forms (eq/neq/gt/gte/lt/lte/in/between) and bare value forms
	 * (scalar -> eq, array -> in). All transforms are scalar; no row loop.
	 *
	 * @param mixed $condition  Operator-format condition or scalar/array
	 * @param int   $coefficient Multiplier (e.g. 100 for position -> position_x100)
	 * @return mixed Scaled condition
	 */
	private function scale_filter_condition( $condition, $coefficient ) {
		if ( ! is_array( $condition ) ) {
			// Bare scalar: treat as eq
			if ( is_numeric( $condition ) ) {
				$exact = $this->scale_exact_or_null( (float) $condition, $coefficient );
				return array( 'eq' => $exact === null ? self::SCALE_IMPOSSIBLE_VALUE : $exact );
			}
			return $condition;
		}

		// IN-clause form (sequential numeric array)
		if ( ! $this->is_operator_condition( $condition ) ) {
			$scaled = array();
			foreach ( $condition as $val ) {
				if ( is_numeric( $val ) ) {
					$exact = $this->scale_exact_or_null( (float) $val, $coefficient );
					if ( $exact !== null ) {
						$scaled[] = $exact;
					}
					// non-representable values are dropped; if all are dropped, fall through
				} else {
					$scaled[] = $val;
				}
			}
			if ( empty( $scaled ) ) {
				$scaled[] = self::SCALE_IMPOSSIBLE_VALUE;
			}
			return $scaled;
		}

		// Operator form
		$result = array();
		foreach ( $condition as $op => $target ) {
			switch ( $op ) {
				case 'eq':
				case 'neq':
					if ( is_numeric( $target ) ) {
						$exact = $this->scale_exact_or_null( (float) $target, $coefficient );
						$result[ $op ] = $exact === null ? self::SCALE_IMPOSSIBLE_VALUE : $exact;
					} else {
						$result[ $op ] = $target;
					}
					break;
				case 'gte':
					// position >= X  → position_x100 >= ceil(X * coef)
					if ( is_numeric( $target ) ) {
						$result[ $op ] = (int) ceil( (float) $target * $coefficient );
					} else {
						$result[ $op ] = $target;
					}
					break;
				case 'gt':
					// position > X  → position_x100 > floor(X * coef)
					if ( is_numeric( $target ) ) {
						$result[ $op ] = (int) floor( (float) $target * $coefficient );
					} else {
						$result[ $op ] = $target;
					}
					break;
				case 'lte':
					// position <= X  → position_x100 <= floor(X * coef)
					if ( is_numeric( $target ) ) {
						$result[ $op ] = (int) floor( (float) $target * $coefficient );
					} else {
						$result[ $op ] = $target;
					}
					break;
				case 'lt':
					// position < X  → position_x100 < ceil(X * coef)
					if ( is_numeric( $target ) ) {
						$result[ $op ] = (int) ceil( (float) $target * $coefficient );
					} else {
						$result[ $op ] = $target;
					}
					break;
				case 'in':
					if ( is_array( $target ) ) {
						$scaled = array();
						foreach ( $target as $v ) {
							if ( is_numeric( $v ) ) {
								$exact = $this->scale_exact_or_null( (float) $v, $coefficient );
								if ( $exact !== null ) {
									$scaled[] = $exact;
								}
							} else {
								$scaled[] = $v;
							}
						}
						if ( empty( $scaled ) ) {
							$scaled[] = self::SCALE_IMPOSSIBLE_VALUE;
						}
						$result[ $op ] = $scaled;
					} else {
						$result[ $op ] = $target;
					}
					break;
				case 'between':
					if ( is_array( $target ) && count( $target ) >= 2 ) {
						// lower bound: ceil (smallest physical satisfying >= lo)
						// upper bound: floor (largest physical satisfying <= hi)
						$lo = is_numeric( $target[0] ) ? (int) ceil( (float) $target[0] * $coefficient )  : $target[0];
						$hi = is_numeric( $target[1] ) ? (int) floor( (float) $target[1] * $coefficient ) : $target[1];
						$result[ $op ] = array( $lo, $hi );
					} else {
						$result[ $op ] = $target;
					}
					break;
				default:
					// contains / prefix don't apply to numeric A 系 columns; pass through unchanged
					$result[ $op ] = $target;
					break;
			}
		}
		return $result;
	}

	/**
	 * Return value * coefficient as int when the product is exactly an integer,
	 * otherwise null. Used by scale_filter_condition for eq / neq / in handling
	 * where non-integer products mean the user-supplied value is unrepresentable
	 * on the integer physical column.
	 *
	 * @param float $value
	 * @param int   $coefficient
	 * @return int|null
	 */
	private function scale_exact_or_null( $value, $coefficient ) {
		$scaled = $value * $coefficient;
		// tolerance for binary float drift (e.g. 0.1 * 100 = 10.000000000000002)
		if ( abs( $scaled - round( $scaled ) ) < 1e-9 ) {
			return (int) round( $scaled );
		}
		return null;
	}

	/**
	 * Apply Material-layer post filters to virtual columns (T83).
	 *
	 * Runs after compute_gsc_virtual_columns has injected ctr / position /
	 * position_weighted into rows. Filters rows whose virtual values fail the
	 * supplied conditions.
	 *
	 * Independent implementation (D3 of design): does not call into Storage's
	 * apply_post_filters / match_operator_filter. Reuses Material's own
	 * is_operator_condition / match_operator_condition.
	 *
	 * @param array $data                  Rows with virtual columns already computed
	 * @param array $material_post_filters Map of virtual column -> condition
	 * @return array Filtered rows
	 */
	private function apply_virtual_post_filters( $data, $material_post_filters ) {
		if ( empty( $material_post_filters ) || ! is_array( $data ) || empty( $data ) ) {
			return $data;
		}

		$filtered = array();
		foreach ( $data as $row ) {
			$matches = true;
			foreach ( $material_post_filters as $col => $condition ) {
				$value = array_key_exists( $col, $row ) ? $row[ $col ] : null;
				if ( is_array( $condition ) && $this->is_operator_condition( $condition ) ) {
					if ( ! $this->match_operator_condition( $value, $condition ) ) {
						$matches = false;
						break;
					}
				} elseif ( is_array( $condition ) ) {
					// IN clause
					if ( ! in_array( $value, $condition, false ) ) {
						$matches = false;
						break;
					}
				} else {
					// Bare scalar => eq
					if ( $value != $condition ) { // loose compare for numeric/string mix
						$matches = false;
						break;
					}
				}
			}
			if ( $matches ) {
				$filtered[] = $row;
			}
		}
		return $filtered;
	}

	/**
	 * Strip filter-only physical columns from rows before decode_data (T83 / D5).
	 *
	 * Material adds source physical columns (e.g. clicks, impressions, position_x100)
	 * solely so that B 系 virtual filters can be evaluated. Those columns must not
	 * leak to the final result if they were not requested in keep_columns.
	 *
	 * decode_data already drops them (it builds column_map from keep_columns), but
	 * this explicit strip keeps the cleanup local to Material and removes any
	 * dependency on decode_data's behavior (defensive design per performance-critic note #6).
	 *
	 * @param array $data         Rows
	 * @param array $keep_columns Qualified keep columns ('material.col')
	 * @param string $material_name Material name (used to qualify physical names)
	 * @param array $b_series_sources Physical columns added solely for B 系 evaluation
	 * @return array Rows with non-keep physical columns removed
	 */
	private function strip_filter_only_columns( $data, $keep_columns, $material_name, $b_series_sources ) {
		if ( empty( $b_series_sources ) || empty( $data ) ) {
			return $data;
		}

		// Build set of physical columns the user actually requested in keep
		$keep_physical = array();
		foreach ( $keep_columns as $qcol ) {
			$dot = strpos( $qcol, '.' );
			if ( $dot !== false ) {
				$keep_physical[ substr( $qcol, $dot + 1 ) ] = true;
			} else {
				$keep_physical[ $qcol ] = true;
			}
		}

		// Determine which sources should be stripped
		$strip = array();
		foreach ( $b_series_sources as $col ) {
			if ( ! isset( $keep_physical[ $col ] ) ) {
				$strip[] = $col;
			}
		}
		if ( empty( $strip ) ) {
			return $data;
		}

		foreach ( $data as &$row ) {
			foreach ( $strip as $col ) {
				if ( array_key_exists( $col, $row ) ) {
					unset( $row[ $col ] );
				}
			}
		}
		unset( $row );

		return $data;
	}

	/**
	 * Inject goal virtual columns (is_goal_N) into fetched data
	 *
	 * For each requested goal number, builds a pv_id lookup set from goal cache,
	 * then scans all rows and sets is_goal_N = 1 or 0.
	 *
	 * @param array $data Fetched allpv data rows
	 * @param int[] $goal_numbers Goal numbers to inject (e.g., [1, 3, 8])
	 * @param string $tracking_id Tracking ID
	 * @param array $time_range Time range with 'start' and 'end'
	 * @return array Data with is_goal_N columns injected
	 */
	private function inject_goal_virtual_columns( $data, $goal_numbers, $tracking_id, $time_range ) {
		global $qahm_file_functions;
		// #1598: 期間の入口で日付だけの形に揃える（Storage 側の他材料と同じ作法）。
		// ISO 8601 のまま渡すと get_goal_data_by_number 内の 'Y-m-d' 比較で開始日が落ちる。
		$start_date = isset( $time_range['start'] ) && is_string( $time_range['start'] )
			? $this->wrap_substr( $time_range['start'], 0, 10 )
			: '';
		$end_date = isset( $time_range['end'] ) && is_string( $time_range['end'] )
			? $this->wrap_substr( $time_range['end'], 0, 10 )
			: '';

		// Fetch all goal data at once
		$all_goals_data = $qahm_file_functions->get_multiple_goals_data( $tracking_id, $goal_numbers, $start_date, $end_date );

		// Build pv_id sets for each goal
		$goal_pv_sets = array();
		foreach ( $goal_numbers as $num ) {
			$goal_pv_sets[ $num ] = array();
			if ( isset( $all_goals_data[ $num ] ) ) {
				foreach ( $all_goals_data[ $num ] as $session ) {
					foreach ( $session as $pv ) {
						if ( isset( $pv['pv_id'] ) ) {
							$goal_pv_sets[ $num ][ $pv['pv_id'] ] = true;
						}
					}
				}
			}
		}

		// Inject flags into each row
		foreach ( $data as &$row ) {
			$row_pv_id = isset( $row['pv_id'] ) ? $row['pv_id'] : null;
			foreach ( $goal_numbers as $num ) {
				$col_name = 'is_goal_' . $num;
				$row[ $col_name ] = ( $row_pv_id !== null && isset( $goal_pv_sets[ $num ][ $row_pv_id ] ) ) ? 1 : 0;
			}
		}
		unset( $row );

		return $data;
	}
}

$GLOBALS['qahm_qal_material'] = new QAHM_Qal_Material();
