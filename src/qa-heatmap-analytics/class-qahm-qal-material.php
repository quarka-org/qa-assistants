<?php
/**
 * QAL Material Class
 *
 * Provides material definition retrieval from the materials manifest
 * defined in materials-manifest-2025-10-20.php (PHP array file).
 *
 * @package qa_heatmap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QAHM_Qal_Material extends QAHM_File_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
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
				'message'    => __( 'Material names must be a string or array.', 'qa-heatmap-analytics' ),
				'location'   => 'material_names',
			);
		}

		$materials_manifest = $this->load_materials_manifest();
		if ( is_wp_error( $materials_manifest ) ) {
			return array(
				'error_code' => 'E_MANIFEST_LOAD_ERROR',
				'message'    => $materials_manifest->get_error_message(),
				'location'   => 'materials_manifest',
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
		} elseif ( preg_match( '/^goal_([1-9]\d*)$/', $material_name ) && isset( $materials_manifest['materials']['goal_x'] ) ) {
			// Pattern-based lookup for goal_x materials (goal_1, goal_2, etc.)
			$material_data = $materials_manifest['materials']['goal_x'];
		} else {
			return array(
				'error_code' => 'E_UNKNOWN_MATERIAL',
				/* translators: %s: material name */
				'message'    => sprintf( __( "Material '%s' not found in manifest", 'qa-heatmap-analytics' ), $material_name ),
				'location'   => 'material_name',
			);
		}

		$decoders = array();
		if ( isset( $material_data['decoders'] ) && is_array( $material_data['decoders'] ) ) {
			foreach ( $material_data['decoders'] as $decoder_def ) {
				$decoder = array(
					'loader'  => isset( $decoder_def['loader'] ) ? $decoder_def['loader'] : null,
					'decoder' => isset( $decoder_def['decoder'] ) ? $decoder_def['decoder'] : null,
					'fields'  => array(),
				);

				if ( isset( $decoder_def['fields'] ) && is_array( $decoder_def['fields'] ) ) {
					foreach ( $decoder_def['fields'] as $field_def ) {
						$decoder['fields'][] = array(
							'material_column' => isset( $field_def['material_column'] ) ? $field_def['material_column'] : '',
							'physical_column' => isset( $field_def['physical_column'] ) ? $field_def['physical_column'] : '',
						);
					}
				}

				$decoders[] = $decoder;
			}
		}

		return array(
			'material_name' => $material_name,
			'decoders'      => $decoders,
		);
	}

	/**
	 * Load materials manifest from PHP array file
	 *
	 * @return array|WP_Error Materials manifest array or WP_Error on failure
	 */
	private function load_materials_manifest() {
		$manifest_file = __DIR__ . '/yaml/materials-manifest-2025-10-20.php';

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
				'message'    => sprintf( __( "View '%s' not found in make section.", 'qa-heatmap-analytics' ), $view_name ),
				'location'   => "make.{$view_name}",
			);
		}

		$view_def = $executable_qal['qal']['make'][ $view_name ];

		if ( ! isset( $view_def['from'][0] ) ) {
			return array(
				'error_code' => 'E_INVALID_FROM',
				'message'    => __( 'From array is empty or missing.', 'qa-heatmap-analytics' ),
				'location'   => "make.{$view_name}.from",
			);
		}

		$material_name     = $view_def['from'][0];
		$tracking_id       = $executable_qal['qal']['tracking_id'];
		$time_range        = $executable_qal['qal']['time'];
		$keep_columns      = isset( $view_def['keep'] ) ? $view_def['keep'] : array();
		$filter_conditions = isset( $view_def['filter'] ) ? $view_def['filter'] : null;

		$material_def = $this->get_material_definition( $material_name );
		if ( isset( $material_def['error_code'] ) ) {
			return $material_def;
		}

		$physical_columns = $this->extract_physical_columns( $keep_columns, $material_name, $material_def );
		if ( isset( $physical_columns['error_code'] ) ) {
			return $physical_columns;
		}

		$count_only = isset( $executable_qal['qal']['result']['count_only'] )
			? $executable_qal['qal']['result']['count_only']
			: false;

		$storage_data = $this->fetch_filtered_data(
			$tracking_id,
			$material_name,
			$time_range,
			$filter_conditions,
			$physical_columns,
			$count_only
		);

		if ( isset( $storage_data['error_code'] ) ) {
			return $storage_data;
		}

		$decoded_data = $this->decode_data( $storage_data['data'], $keep_columns, $material_name, $material_def );

		return array(
			'view_name'     => $view_name,
			'material_name' => $material_name,
			'record_count'  => $this->wrap_count( $decoded_data ),
			'data'          => $decoded_data,
		);
	}

	/**
	 * Extract physical columns from keep list
	 *
	 * Converts logical column names (e.g., 'allpv.url') to physical column names
	 * based on material definition.
	 *
	 * @param array $keep_columns Array of logical column names
	 * @param string $material_name Material name
	 * @param array $material_def Material definition
	 * @return string[]|array Array of physical column names or error array
	 */
	private function extract_physical_columns( $keep_columns, $material_name, $material_def ) {
		if ( ! is_array( $keep_columns ) ) {
			return array(
				'error_code' => 'E_INVALID_INPUT',
				'message'    => __( 'Keep columns must be an array.', 'qa-heatmap-analytics' ),
				'location'   => 'keep',
			);
		}

		$physical_columns = array();

		foreach ( $keep_columns as $logical_column ) {
			if ( ! is_string( $logical_column ) ) {
				return array(
					'error_code' => 'E_UNKNOWN_COLUMN',
					'message'    => __( 'Column name must be a string.', 'qa-heatmap-analytics' ),
					'location'   => 'keep',
				);
			}

			$parts = $this->wrap_explode( '.', $logical_column );
			if ( $this->wrap_count( $parts ) !== 2 ) {
				return array(
					'error_code' => 'E_UNKNOWN_COLUMN',
					/* translators: %s: column name */
					'message'    => sprintf( __( "Invalid column format: '%s'", 'qa-heatmap-analytics' ), $logical_column ),
					'location'   => 'keep',
				);
			}

			$column_material = $parts[0];
			$column_name     = $parts[1];

			if ( $column_material !== $material_name ) {
				return array(
					'error_code' => 'E_UNKNOWN_COLUMN',
					/* translators: 1: column material name, 2: expected material name */
					'message'    => sprintf( __( "Column material '%1\$s' does not match expected material '%2\$s'", 'qa-heatmap-analytics' ), $column_material, $material_name ),
					'location'   => 'keep',
				);
			}

			// Find physical column in material definition
			$physical_column = $this->find_physical_column( $column_name, $material_def );
			if ( $physical_column === null ) {
				return array(
					'error_code' => 'E_UNKNOWN_COLUMN',
					/* translators: %s: column name */
					'message'    => sprintf( __( "Column '%s' not found in material definition", 'qa-heatmap-analytics' ), $column_name ),
					'location'   => 'keep',
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
	private function find_physical_column( $logical_column, $material_def ) {
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
				'message'    => __( 'Required Storage layer dependency is not available.', 'qa-heatmap-analytics' ),
				'location'   => 'material',
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
		foreach ( $keep_columns as $logical_column ) {
			$parts = $this->wrap_explode( '.', $logical_column );
			if ( $this->wrap_count( $parts ) !== 2 ) {
				continue;
			}

			$column_name     = $parts[1];
			$physical_column = $this->find_physical_column( $column_name, $material_def );

			if ( $physical_column !== null ) {
				$column_map[ $column_name ] = $physical_column;
			}
		}

		foreach ( $data as $record ) {
			$decoded_record = array();

			foreach ( $column_map as $logical_name => $physical_name ) {
				if ( isset( $record[ $physical_name ] ) ) {
					$decoded_record[ $logical_name ] = $record[ $physical_name ];
				} else {
					$decoded_record[ $logical_name ] = null;
				}
			}

			$decoded_data[] = $decoded_record;
		}

		return $decoded_data;
	}

	/**
	 * Apply join operation (stub for 2025-10-20 version)
	 *
	 * Executes join operations to combine data from multiple materials or views.
	 * Retrieves existing view data, fetches additional data from Storage layer,
	 * and returns merged data.
	 *
	 * Note: This is a stub implementation for the 2025-10-20 version where join operations
	 * are not yet supported.
	 *
	 * @param array $executable_qal Current ExecutableQAL structure
	 * @param string $view_name Name of the view to process
	 * @return array Result data with view_name, record_count, and data, or error array
	 */
	public function qal_join_apply( $executable_qal, $view_name ) {
		return array(
			'error_code' => 'E_JOIN_NOT_SUPPORTED',
			'message'    => __( 'Join operations are not supported in version 2025-10-20.', 'qa-heatmap-analytics' ),
			'location'   => "make.{$view_name}.join",
		);
	}
}

$GLOBALS['qahm_qal_material'] = new QAHM_Qal_Material();
