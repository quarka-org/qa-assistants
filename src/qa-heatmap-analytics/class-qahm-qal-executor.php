<?php
defined( 'ABSPATH' ) || exit;
/**
 * QAL Executor Class
 *
 * Validates QAL (Query Abstraction Language) execution requests against
 * the validation manifest. The manifest is auto-generated from
 * qal-validation-{version}.yaml.
 * Run: php scripts/build-manifest.php validation {version}
 *
 * @package qa_heatmap
 */

class QAHM_Qal_Executor extends QAHM_File_Base {

	/**
	 * Fixed material names extracted from validation manifest at build time.
	 * Set during qal_validate_qal() and used by is_valid_material_name().
	 * Null means not yet loaded (will use hardcoded fallback).
	 *
	 * @var array|null
	 */
	private $fixed_material_names = null;

	/**
	 * Constructor
	 */
	public function __construct() {
	}

	/**
	 * Validate QAL execution request
	 * 
	 * Validates the provided QAL structure against the validation manifest
	 * and returns either the validated QAL with defaults applied or error information.
	 *
	 * @param array $input_data Array containing 'qal' and optionally 'validation_manifest_path'
	 * @return array Validation result with 'valid' flag and either 'validated_qal' or error details
	 */
	public function qal_validate_qal( $input_data ) {
		$validation_manifest = $this->qal_load_validation_manifest();
		if ( is_wp_error( $validation_manifest ) ) {
			return array(
				'valid' => false,
				'error_code' => 'E_MANIFEST_LOAD_ERROR',
				'message' => $validation_manifest->get_error_message(),
				'location' => 'validation_manifest'
			);
		}

		if ( ! isset( $input_data['qal'] ) || ! is_array( $input_data['qal'] ) ) {
			return array(
				'valid' => false,
				'error_code' => 'E_INVALID_INPUT',
				'message' => 'Input must contain a "qal" array.',
				'location' => 'input'
			);
		}

		$qal = $input_data['qal'];
		$rules = $validation_manifest['rules'];
		$structure = $validation_manifest['structure'];

		// Cache fixed material names from manifest (built from YAML pattern)
		$this->fixed_material_names = isset( $validation_manifest['fixed_material_names'] )
			? $validation_manifest['fixed_material_names']
			: null;

		$required_fields = $structure['required'];
		foreach ( $required_fields as $field ) {
			if ( ! isset( $qal[ $field ] ) ) {
				$error_info = $this->get_error_info( $rules, $field );
				return array(
					'valid' => false,
					'error_code' => $error_info['code'],
					'message' => $error_info['message'],
					'location' => $field,
					'details' => array(
						'missing_field' => $field
					)
				);
			}
		}

		$validation_result = $this->qal_validate_tracking_id( $qal['tracking_id'], $rules['tracking_id'] );
		if ( $validation_result !== true ) {
			return $validation_result;
		}

		$validation_result = $this->qal_validate_materials( $qal['materials'], $rules['materials'] );
		if ( $validation_result !== true ) {
			return $validation_result;
		}

		$validation_result = $this->qal_validate_time( $qal['time'], $rules['time'] );
		if ( $validation_result !== true ) {
			return $validation_result;
		}

		$validation_result = $this->qal_validate_make( $qal['make'], $rules['make'], $qal['materials'] );
		if ( $validation_result !== true ) {
			return $validation_result;
		}

		$validation_result = $this->qal_validate_result( $qal['result'], $rules['result'], $qal['make'] );
		if ( $validation_result !== true ) {
			return $validation_result;
		}

		$validated_qal = $this->qal_apply_defaults( $qal, $rules );

		return array(
			'valid' => true,
			'validated_qal' => $validated_qal
		);
	}

	/**
	 * Load validation manifest from PHP array file
	 *
	 * @return array|WP_Error Validation manifest array or WP_Error on failure
	 */
	private function qal_load_validation_manifest() {
		$manifest_file = dirname( __FILE__ ) . '/yaml/qal-validation-2025-10-20.php';

		if ( ! file_exists( $manifest_file ) ) {
			return new WP_Error(
				'manifest_not_found',
				/* translators: %s: file path */
				sprintf( __( 'Validation manifest not found: %s', 'qa-heatmap-analytics' ), $manifest_file )
			);
		}

		$manifest = include $manifest_file;

		if ( ! is_array( $manifest ) ) {
			return new WP_Error(
				'manifest_parse_error',
				__( 'Failed to load validation manifest file.', 'qa-heatmap-analytics' )
			);
		}

		return $manifest;
	}

	/**
	 * T36c: Get valid column names for a material from the materials manifest
	 *
	 * @param string $material_name Material name
	 * @return array Flipped array of column names (column => true), empty if not found
	 */
	private function get_material_column_names( $material_name ) {
		$manifest_file = dirname( __FILE__ ) . '/yaml/materials-manifest-2025-10-20.php';
		if ( ! file_exists( $manifest_file ) ) {
			return array();
		}
		$manifest = include $manifest_file;
		if ( ! is_array( $manifest ) || ! isset( $manifest['materials'] ) ) {
			return array();
		}

		// Resolve material data (direct, goal_x pattern, events template)
		if ( isset( $manifest['materials'][ $material_name ] ) ) {
			$material_data = $manifest['materials'][ $material_name ];
		} elseif ( strpos( $material_name, 'goal_' ) === 0 && isset( $manifest['materials']['goal_x'] ) ) {
			$material_data = $manifest['materials']['goal_x'];
		} elseif ( strpos( $material_name, 'events.' ) === 0 && isset( $manifest['materials']['events_template'] ) ) {
			$material_data = $manifest['materials']['events_template'];
		} else {
			return array();
		}

		$columns = array();
		if ( isset( $material_data['decoders'] ) && is_array( $material_data['decoders'] ) ) {
			foreach ( $material_data['decoders'] as $decoder ) {
				if ( isset( $decoder['fields'] ) && is_array( $decoder['fields'] ) ) {
					foreach ( $decoder['fields'] as $field ) {
						if ( isset( $field['material_column'] ) ) {
							$columns[ $field['material_column'] ] = true;
						}
					}
				}
			}
		}

		return $columns;
	}

	/**
	 * T45 Phase 3: Get join cardinality for a material from the materials manifest
	 *
	 * @param string $material_name Material name
	 * @return string|null Cardinality string (e.g. "N:M") or null if not defined
	 */
	private function get_material_join_cardinality( $material_name ) {
		$manifest_file = dirname( __FILE__ ) . '/yaml/materials-manifest-2025-10-20.php';
		if ( ! file_exists( $manifest_file ) ) {
			return null;
		}
		$manifest = include $manifest_file;
		if ( ! is_array( $manifest ) || ! isset( $manifest['materials'] ) ) {
			return null;
		}

		if ( ! isset( $manifest['materials'][ $material_name ] ) ) {
			return null;
		}

		$material_data = $manifest['materials'][ $material_name ];
		if ( isset( $material_data['join']['cardinality'] ) ) {
			return $material_data['join']['cardinality'];
		}

		return null;
	}

	/**
	 * Validate tracking_id field
	 *
	 * @param string $tracking_id Tracking ID to validate
	 * @param array $rule Validation rule
	 * @return true|array True if valid, error array otherwise
	 */
	public function qal_validate_tracking_id( $tracking_id, $rule = null ) {
		if ( ! is_string( $tracking_id ) ) {
			return array(
				'valid' => false,
				'error_code' => 'E_UNKNOWN_TRACKING_ID',
				'message' => 'Invalid tracking_id provided.',
				'location' => 'tracking_id',
				'details' => array(
					'expected_type' => 'string',
					'received_type' => gettype( $tracking_id )
				)
			);
		}

		// tracking_id validation: only allow alphanumeric, underscore, hyphen
		if ( $tracking_id === '' || ! $this->is_alnum_dash( $tracking_id ) ) {
			return array(
				'valid' => false,
				'error_code' => 'E_UNKNOWN_TRACKING_ID',
				'message' => 'Invalid tracking_id provided.',
				'location' => 'tracking_id',
				'details' => array(
					'expected_pattern' => '^[a-zA-Z0-9_-]+$',
					'received_value' => $tracking_id
				)
			);
		}

		return true;
	}

	/**
	 * Validate materials field
	 *
	 * @param array $materials Materials array to validate
	 * @param array $rule Validation rule
	 * @return true|array True if valid, error array otherwise
	 */
	private function qal_validate_materials( $materials, $rule ) {
		if ( ! is_array( $materials ) ) {
			return array(
				'valid' => false,
				'error_code' => 'E_UNKNOWN_MATERIAL',
				'message' => 'Materials must be an array.',
				'location' => 'materials',
				'details' => array(
					'expected_type' => 'array',
					'received_type' => gettype( $materials )
				)
			);
		}

		if ( empty( $materials ) ) {
			return array(
				'valid' => false,
				'error_code' => 'E_UNKNOWN_MATERIAL',
				'message' => 'Materials array cannot be empty.',
				'location' => 'materials',
				'details' => array(
					'error' => 'Materials array cannot be empty'
				)
			);
		}

		// Support both enum-based and pattern-based validation
		$allowed_materials = isset( $rule['items']['properties']['name']['enum'] ) 
			? $rule['items']['properties']['name']['enum'] 
			: array();
		$material_pattern = isset( $rule['items']['properties']['name']['pattern'] ) 
			? $rule['items']['properties']['name']['pattern'] 
			: null;

		foreach ( $materials as $index => $material ) {
			if ( ! is_array( $material ) ) {
				return array(
					'valid' => false,
					'error_code' => 'E_UNKNOWN_MATERIAL',
					'message' => 'Material must be an object.',
					'location' => "materials[{$index}]",
					'details' => array(
						'expected_type' => 'object',
						'received_type' => gettype( $material )
					)
				);
			}

			if ( ! isset( $material['name'] ) ) {
				return array(
					'valid' => false,
					'error_code' => 'E_UNKNOWN_MATERIAL',
					'message' => 'Material name not found in manifest.',
					'location' => "materials[{$index}].name",
					'details' => array(
						'error' => 'Missing required field: name'
					)
				);
			}

			// Validate material name using enum or pattern
			$is_valid_material = false;
			
			// Check enum first (for backward compatibility)
			if ( ! empty( $allowed_materials ) && $this->wrap_in_array( $material['name'], $allowed_materials, true ) ) {
				$is_valid_material = true;
			}
			
			// Check pattern if enum didn't match — material names are validated
			// by structural checks (goal_N, events.{name}) instead of regex
			if ( ! $is_valid_material && $material_pattern !== null ) {
				if ( $this->is_valid_material_name( $material['name'] ) ) {
					$is_valid_material = true;
				}
			}
			
			if ( ! $is_valid_material ) {
				return array(
					'valid' => false,
					'error_code' => 'E_UNKNOWN_MATERIAL',
					'message' => 'Material name not found in manifest.',
					'location' => "materials[{$index}].name",
					'details' => array(
						'allowed_values' => ! empty( $allowed_materials ) ? $allowed_materials : null,
						'pattern' => $material_pattern,
						'received_value' => $material['name']
					)
				);
			}

			// V1: as key is forbidden in materials
			if ( isset( $material['as'] ) ) {
				return array(
					'valid' => false,
					'error_code' => 'E_ALIAS_FORBIDDEN',
					'message' => 'The "as" key is not allowed. User-defined aliases are forbidden.',
					'location' => "materials[{$index}].as",
					'details' => array(
						'forbidden_key' => 'as'
					)
				);
			}
		}

		return true;
	}

	/**
	 * Validate time field
	 *
	 * @param array $time Time configuration to validate
	 * @param array $rule Validation rule
	 * @return true|array True if valid, error array otherwise
	 */
	private function qal_validate_time( $time, $rule ) {
		if ( ! is_array( $time ) ) {
			return array(
				'valid' => false,
				'error_code' => 'E_TIME_REQUIRED',
				'message' => 'Missing time.start, time.end, or time.tz.',
				'location' => 'time',
				'details' => array(
					'expected_type' => 'object',
					'received_type' => gettype( $time )
				)
			);
		}

		$required_fields = $rule['required'];
		foreach ( $required_fields as $field ) {
			if ( ! isset( $time[ $field ] ) ) {
				return array(
					'valid' => false,
					'error_code' => 'E_TIME_REQUIRED',
					'message' => 'Missing time.start, time.end, or time.tz.',
					'location' => "time.{$field}",
					'details' => array(
						'missing_field' => $field
					)
				);
			}
		}

		if ( ! is_string( $time['start'] ) || ! is_string( $time['end'] ) || ! is_string( $time['tz'] ) ) {
			return array(
				'valid' => false,
				'error_code' => 'E_TIME_REQUIRED',
				'message' => 'time.start, time.end, and time.tz must be strings.',
				'location' => 'time',
				'details' => array(
					'start_type' => gettype( $time['start'] ),
					'end_type' => gettype( $time['end'] ),
					'tz_type' => gettype( $time['tz'] )
				)
			);
		}

		return true;
	}

	/**
	 * Validate make field
	 *
	 * @param array $make Make configuration to validate
	 * @param array $rule Validation rule
	 * @param array $materials Available materials
	 * @return true|array True if valid, error array otherwise
	 */
	private function qal_validate_make( $make, $rule, $materials ) {
		if ( ! is_array( $make ) ) {
			return array(
				'valid' => false,
				'error_code' => 'E_INVALID_MAKE',
				'message' => 'Make must be an object.',
				'location' => 'make',
				'details' => array(
					'expected_type' => 'object',
					'received_type' => gettype( $make )
				)
			);
		}

		if ( empty( $make ) ) {
			return array(
				'valid' => false,
				'error_code' => 'E_INVALID_MAKE',
				'message' => 'Make cannot be empty.',
				'location' => 'make',
				'details' => array(
					'error' => 'At least one view must be defined'
				)
			);
		}

		$available_materials = array();
		foreach ( $materials as $material ) {
			if ( isset( $material['name'] ) ) {
				$available_materials[] = $material['name'];
			}
		}

		// Track defined view names for V4: from can reference previously defined views
		$defined_views = array();

		foreach ( $make as $view_name => $view_def ) {
			if ( $view_name === '' || ! $this->is_alnum_underscore( $view_name ) ) {
				return array(
					'valid' => false,
					'error_code' => 'E_INVALID_VIEW_NAME',
					'message' => 'Invalid view name.',
					'location' => "make.{$view_name}",
					'details' => array(
						'expected_pattern' => '^[a-zA-Z0-9_]+$',
						'received_value' => $view_name
					)
				);
			}

			if ( ! is_array( $view_def ) ) {
				return array(
					'valid' => false,
					'error_code' => 'E_INVALID_VIEW',
					'message' => 'View definition must be an object.',
					'location' => "make.{$view_name}",
					'details' => array(
						'expected_type' => 'object',
						'received_type' => gettype( $view_def )
					)
				);
			}

			if ( ! isset( $view_def['from'] ) ) {
				return array(
					'valid' => false,
					'error_code' => 'E_INVALID_VIEW',
					'message' => 'View must have "from" field.',
					'location' => "make.{$view_name}.from",
					'details' => array(
						'missing_field' => 'from'
					)
				);
			}

			if ( ! isset( $view_def['keep'] ) ) {
				return array(
					'valid' => false,
					'error_code' => 'E_INVALID_VIEW',
					'message' => 'View must have "keep" field.',
					'location' => "make.{$view_name}.keep",
					'details' => array(
						'missing_field' => 'keep'
					)
				);
			}

			if ( ! is_array( $view_def['from'] ) || empty( $view_def['from'] ) ) {
				return array(
					'valid' => false,
					'error_code' => 'E_INVALID_FROM',
					'message' => 'From must be a non-empty array.',
					'location' => "make.{$view_name}.from",
					'details' => array(
						'error' => 'From must contain at least one material'
					)
				);
			}

			if ( $this->wrap_count( $view_def['from'] ) > 1 ) {
				return array(
					'valid' => false,
					'error_code' => 'E_INVALID_FROM',
					'message' => 'From must contain exactly one material.',
					'location' => "make.{$view_name}.from",
					'details' => array(
						'error' => 'Only one material is allowed in from array'
					)
				);
			}

			$from_material = $view_def['from'][0];
			$is_material = $this->wrap_in_array( $from_material, $available_materials, true );
			$is_defined_view = $this->wrap_in_array( $from_material, $defined_views, true );

			if ( ! $is_material && ! $is_defined_view ) {
				// V4: Determine the correct error code based on what was intended
				$error_code = 'E_UNKNOWN_MATERIAL';
				$error_message = 'Material name not found in manifest.';

				// If it looks like a view reference (alphanumeric+underscore, not matching material patterns)
				if ( $this->is_alnum_underscore( $from_material ) && ! $this->is_valid_material_name( $from_material ) ) {
					$error_code = 'E_UNKNOWN_VIEW';
					$error_message = 'from references undefined view: ' . $from_material;
				}

				return array(
					'valid' => false,
					'error_code' => $error_code,
					'message' => $error_message,
					'location' => "make.{$view_name}.from[0]",
					'details' => array(
						'allowed_materials' => $available_materials,
						'defined_views' => $defined_views,
						'received_value' => $from_material
					)
				);
			}

			// V1: as key is forbidden in view definitions
			if ( isset( $view_def['as'] ) ) {
				return array(
					'valid' => false,
					'error_code' => 'E_ALIAS_FORBIDDEN',
					'message' => 'The "as" key is not allowed. User-defined aliases are forbidden.',
					'location' => "make.{$view_name}.as",
					'details' => array(
						'forbidden_key' => 'as'
					)
				);
			}

			if ( ! is_array( $view_def['keep'] ) ) {
				return array(
					'valid' => false,
					'error_code' => 'E_UNKNOWN_COLUMN',
					'message' => 'Invalid column name in keep list.',
					'location' => "make.{$view_name}.keep",
					'details' => array(
						'error' => 'Keep must be an array'
					)
				);
			}

			$has_calc = isset( $view_def['calc'] ) && is_array( $view_def['calc'] ) && ! empty( $view_def['calc'] );

			// V3: Empty keep is allowed only when calc is present (aggregate-all pattern)
			if ( empty( $view_def['keep'] ) && ! $has_calc ) {
				return array(
					'valid' => false,
					'error_code' => 'E_UNKNOWN_COLUMN',
					'message' => 'Invalid column name in keep list.',
					'location' => "make.{$view_name}.keep",
					'details' => array(
						'error' => 'Keep must be a non-empty array (empty keep is only allowed with calc)'
					)
				);
			}

			// V3: When calc is present and add is omitted, auto-derive add from calc keys
			if ( $has_calc && ! isset( $view_def['add'] ) ) {
				$view_def['add'] = array_keys( $view_def['calc'] );
			}

			foreach ( $view_def['keep'] as $keep_index => $column ) {
				if ( ! is_string( $column ) ) {
					return array(
						'valid' => false,
						'error_code' => 'E_UNKNOWN_COLUMN',
						'message' => 'Invalid column name in keep list.',
						'location' => "make.{$view_name}.keep[{$keep_index}]",
						'details' => array(
							'expected_type' => 'string',
							'received_type' => gettype( $column )
						)
					);
				}

				// V5: keep columns must not contain expressions (parentheses)
				if ( strpos( $column, '(' ) !== false ) {
					return array(
						'valid' => false,
						'error_code' => 'E_KEEP_EXPR_FORBIDDEN',
						'message' => 'Expressions are not allowed in keep. Use calc instead.',
						'location' => "make.{$view_name}.keep[{$keep_index}]",
						'details' => array(
							'received_value' => $column,
							'error' => 'Keep columns must be plain column references, not expressions'
						)
					);
				}

				if ( ! $this->is_valid_qualified_column( $column, $available_materials, $defined_views ) ) {
					return array(
						'valid' => false,
						'error_code' => 'E_UNKNOWN_COLUMN',
						'message' => 'Invalid column name in keep list.',
						'location' => "make.{$view_name}.keep[{$keep_index}]",
						'details' => array(
							'expected_pattern' => '<material_or_view>.<column>',
							'received_value' => $column,
							'available_materials' => $available_materials,
							'defined_views' => $defined_views
						)
					);
				}
			}

			// V5: calc/add cross-validation
			if ( $has_calc ) {
				$has_add = isset( $view_def['add'] ) && is_array( $view_def['add'] );
				$calc_keys = array_keys( $view_def['calc'] );
				$add_values = $has_add ? $view_def['add'] : array();

				// E_ADD_NOT_SUBSET_OF_CALC: add contains column not defined in calc
				foreach ( $add_values as $add_col ) {
					if ( ! $this->wrap_in_array( $add_col, $calc_keys, true ) ) {
						return array(
							'valid' => false,
							'error_code' => 'E_ADD_NOT_SUBSET_OF_CALC',
							'message' => 'add contains column not defined in calc: ' . $add_col,
							'location' => "make.{$view_name}.add",
							'details' => array(
								'undefined_column' => $add_col,
								'calc_keys' => $calc_keys
							)
						);
					}
				}

				// E_CALC_NOT_SUBSET_OF_ADD: calc key not listed in add
				foreach ( $calc_keys as $calc_key ) {
					if ( ! $this->wrap_in_array( $calc_key, $add_values, true ) ) {
						return array(
							'valid' => false,
							'error_code' => 'E_CALC_NOT_SUBSET_OF_ADD',
							'message' => 'calc key not listed in add: ' . $calc_key,
							'location' => "make.{$view_name}.calc",
							'details' => array(
								'missing_in_add' => $calc_key,
								'add_values' => $add_values
							)
						);
					}
				}

				// Validate each calc expression
				$allowed_functions = array( 'COUNT', 'COUNTUNIQUE', 'SUM', 'AVERAGE', 'MIN', 'MAX' );

				foreach ( $view_def['calc'] as $calc_key => $calc_expr ) {
					// Type guard: calc_expr must be a string like "COUNT(*)".
					// A nested object/array here is a client-side shape error;
					// return structured validation error instead of crashing strpos().
					if ( ! is_string( $calc_expr ) ) {
						return array(
							'valid' => false,
							'error_code' => 'E_CALC_INVALID_EXPRESSION',
							'message' => 'Invalid calc expression format. Expected FUNC(material.column) as a string.',
							'location' => "make.{$view_name}.calc.{$calc_key}",
							'details' => array(
								'received_type' => gettype( $calc_expr ),
								'expected_format' => 'FUNC(material.column)'
							)
						);
					}

					// E_CALC_INVALID_EXPRESSION: must be FUNC(col) format
					$paren_open = strpos( $calc_expr, '(' );
					$paren_close = strpos( $calc_expr, ')' );

					if ( $paren_open === false || $paren_close === false || $paren_close <= $paren_open + 1 ) {
						return array(
							'valid' => false,
							'error_code' => 'E_CALC_INVALID_EXPRESSION',
							'message' => 'Invalid calc expression format. Expected FUNC(material.column).',
							'location' => "make.{$view_name}.calc.{$calc_key}",
							'details' => array(
								'received_value' => $calc_expr,
								'expected_format' => 'FUNC(material.column)'
							)
						);
					}

					// E_CALC_INVALID_FUNCTION: function name not in whitelist
					$func_name = strtoupper( substr( $calc_expr, 0, $paren_open ) );

					if ( ! $this->wrap_in_array( $func_name, $allowed_functions, true ) ) {
						return array(
							'valid' => false,
							'error_code' => 'E_CALC_INVALID_FUNCTION',
							'message' => 'Unknown calc function: ' . $func_name,
							'location' => "make.{$view_name}.calc.{$calc_key}",
							'details' => array(
								'received_function' => $func_name,
								'allowed_functions' => $allowed_functions
							)
						);
					}

					// T29: Validate column reference inside parentheses
					$full_col = substr( $calc_expr, $paren_open + 1, $paren_close - $paren_open - 1 );
					if ( ! $this->is_valid_qualified_column( $full_col, $available_materials, $defined_views ) ) {
						return array(
							'valid' => false,
							'error_code' => 'E_CALC_INVALID_EXPRESSION',
							'message' => 'Invalid column reference in calc expression. Expected material.column format.',
							'location' => "make.{$view_name}.calc.{$calc_key}",
							'details' => array(
								'received_value' => $calc_expr,
								'invalid_column' => $full_col,
								'expected_format' => 'FUNC(material.column)'
							)
						);
					}
				}
			}

			// T32: view chain + filter is not supported (HAVING equivalent is future work)
			if ( isset( $view_def['filter'] ) && isset( $view_def['from'][0] ) && in_array( $view_def['from'][0], $defined_views, true ) ) {
				return array(
					'valid' => false,
					'error_code' => 'E_FILTER_ON_VIEW_CHAIN',
					'message' => 'filter is not supported when "from" references a defined view. Apply filter in the source view instead.',
					'location' => "make.{$view_name}.filter",
					'details' => array(
						'from_source' => $view_def['from'][0],
						'hint' => 'Move the filter to the view definition of "' . $view_def['from'][0] . '"'
					)
				);
			}

			// T31: flat-format filter validation (spec §4)
			if ( isset( $view_def['filter'] ) ) {
				// T36c: Build valid column names for the from material
				$valid_columns = $this->get_material_column_names( $from_material );
				$filter_result = $this->qal_validate_filter( $view_def['filter'], $view_name, $available_materials, $defined_views, $valid_columns );
				if ( $filter_result !== true ) {
					return $filter_result;
				}
			}

			// T29: join syntax validation
			if ( isset( $view_def['join'] ) ) {
				$join_result = $this->qal_validate_join( $view_def['join'], $view_name, $available_materials, $defined_views, $make );
				if ( $join_result !== true ) {
					return $join_result;
				}
			}

			// T65: sort syntax validation
			if ( isset( $view_def['sort'] ) ) {
				if ( ! is_array( $view_def['sort'] ) ) {
					return array(
						'valid' => false,
						'error_code' => 'E_INVALID_SORT',
						'message' => 'sort must be an object.',
						'location' => "make.{$view_name}.sort",
						'details' => array(
							'expected_type' => 'object',
							'received_type' => gettype( $view_def['sort'] )
						)
					);
				}

				$sort_def = $view_def['sort'];

				if ( ! isset( $sort_def['by'] ) || ! is_string( $sort_def['by'] ) || '' === trim( $sort_def['by'] ) ) {
					return array(
						'valid' => false,
						'error_code' => 'E_INVALID_SORT',
						'message' => 'sort.by must be a non-empty string.',
						'location' => "make.{$view_name}.sort.by",
						'details' => array(
							'expected_type' => 'string',
							'received_value' => isset( $sort_def['by'] ) ? $sort_def['by'] : null
						)
					);
				}

				if ( ! isset( $sort_def['order'] ) || ( $sort_def['order'] !== 'asc' && $sort_def['order'] !== 'desc' ) ) {
					return array(
						'valid' => false,
						'error_code' => 'E_INVALID_SORT',
						'message' => 'sort.order must be "asc" or "desc".',
						'location' => "make.{$view_name}.sort.order",
						'details' => array(
							'expected_values' => array( 'asc', 'desc' ),
							'received_value' => isset( $sort_def['order'] ) ? $sort_def['order'] : null
						)
					);
				}

				if ( isset( $sort_def['top'] ) && ( ! is_int( $sort_def['top'] ) || $sort_def['top'] < 1 ) ) {
					return array(
						'valid' => false,
						'error_code' => 'E_INVALID_SORT',
						'message' => 'sort.top must be a positive integer.',
						'location' => "make.{$view_name}.sort.top",
						'details' => array(
							'expected_type' => 'positive integer',
							'received_value' => $sort_def['top']
						)
					);
				}

				$allowed_sort_keys = array( 'by', 'order', 'top' );
				foreach ( array_keys( $sort_def ) as $sort_key ) {
					if ( ! $this->wrap_in_array( $sort_key, $allowed_sort_keys, true ) ) {
						return array(
							'valid' => false,
							'error_code' => 'E_INVALID_SORT',
							'message' => 'Unknown key in sort: ' . $sort_key,
							'location' => "make.{$view_name}.sort.{$sort_key}",
							'details' => array(
								'unknown_key' => $sort_key,
								'allowed_keys' => $allowed_sort_keys
							)
						);
					}
				}
			}

			// V4: Register this view as defined for subsequent from references
			$defined_views[] = $view_name;
		}

		return true;
	}

	/**
	 * Validate filter syntax (flat format)
	 *
	 * Flat format: { "column_name": [values] | { "operator": value } }
	 * Keys are plain column names (not qualified). Multiple conditions are implicitly AND.
	 * Values are either indexed arrays (IN clause) or associative arrays with operator keys.
	 * Operators: eq, neq, gt, gte, lt, lte, in, contains, prefix, between (Storage-layer set)
	 *
	 * @param mixed $filter Filter definition
	 * @param string $view_name View name for error location
	 * @param array $available_materials List of valid material names (unused, kept for signature compatibility)
	 * @param array $defined_views List of previously defined view names (unused, kept for signature compatibility)
	 * @param array $valid_columns Flipped array of valid column names (column => true). Empty array skips check.
	 * @return true|array True if valid, error array otherwise
	 */
	private function qal_validate_filter( $filter, $view_name, $available_materials, $defined_views, $valid_columns = array() ) {
		// Canonical filter syntax examples, shown to AI clients so they can self-correct.
		// Only correct forms are listed (no "bad examples") to reinforce the right patterns.
		$filter_examples = array(
			'list_form'     => array( 'column_name' => array( 'value1', 'value2' ) ),
			'operator_form' => array( 'column_name' => array( 'contains' => 'keyword' ) ),
		);
		$syntax_hint = ' Filter value must be either a list like {"col":["v1","v2"]} or an operator object like {"col":{"contains":"keyword"}}.';

		if ( ! is_array( $filter ) ) {
			return array(
				'valid' => false,
				'error_code' => 'E_FILTER_INVALID',
				'message' => 'Filter must be an object.' . $syntax_hint,
				'location' => "make.{$view_name}.filter",
				'details' => array(
					'expected_type' => 'object',
					'received_type' => gettype( $filter ),
					'examples' => $filter_examples,
				)
			);
		}

		$valid_operators = array( 'eq' => true, 'neq' => true, 'gt' => true, 'gte' => true, 'lt' => true, 'lte' => true, 'in' => true, 'contains' => true, 'prefix' => true, 'between' => true );

		foreach ( $filter as $column => $condition ) {
			// Key must be a valid column name (alphanumeric + underscore)
			if ( ! is_string( $column ) || ! $this->is_alnum_underscore( $column ) ) {
				return array(
					'valid' => false,
					'error_code' => 'E_FILTER_INVALID',
					'message' => 'Filter key must be a valid column name (alphanumeric and underscore). Got: ' . $column,
					'location' => "make.{$view_name}.filter",
					'details' => array(
						'received_key' => $column,
						'expected_pattern' => '^[a-zA-Z0-9_]+$',
						'examples' => $filter_examples,
					)
				);
			}

			// T36c: Check that column name exists in the material definition
			if ( ! empty( $valid_columns ) && ! isset( $valid_columns[ $column ] ) ) {
				return array(
					'valid' => false,
					'error_code' => 'E_FILTER_INVALID',
					'message' => 'Filter key "' . $column . '" is not a valid column name for this material.',
					'location' => "make.{$view_name}.filter",
					'details' => array(
						'received_key' => $column,
						'valid_columns' => array_keys( $valid_columns ),
						'examples' => $filter_examples,
					)
				);
			}

			// Value must be an array (either indexed for IN clause, or associative for operator format)
			if ( ! is_array( $condition ) ) {
				return array(
					'valid' => false,
					'error_code' => 'E_FILTER_INVALID',
					'message' => 'Filter value for "' . $column . '" must be an array (list for IN clause) or an object (operator format).' . $syntax_hint,
					'location' => "make.{$view_name}.filter.{$column}",
					'details' => array(
						'expected_type' => 'array or object',
						'received_type' => gettype( $condition ),
						'examples' => $filter_examples,
					)
				);
			}

			// If associative (operator format), validate operator keys
			if ( $condition !== array() && ! isset( $condition[0] ) ) {
				foreach ( array_keys( $condition ) as $op ) {
					if ( ! isset( $valid_operators[ $op ] ) ) {
						return array(
							'valid' => false,
							'error_code' => 'E_FILTER_INVALID',
							'message' => 'Unknown filter operator "' . $op . '" for column "' . $column . '".' . $syntax_hint,
							'location' => "make.{$view_name}.filter.{$column}",
							'details' => array(
								'received_operator' => $op,
								'allowed_operators' => array_keys( $valid_operators ),
								'examples' => $filter_examples,
							)
						);
					}
				}
			}
		}

		return true;
	}

	/**
	 * Validate join syntax
	 *
	 * Join format: { "with": "<material_or_view>", "on": [ { "left": "...", "right": "..." } ], "if not match": "keep-left"|"drop", "fill": ... }
	 *
	 * @param mixed $join Join definition
	 * @param string $view_name View name for error location
	 * @param array $available_materials List of valid material names
	 * @param array $defined_views List of previously defined view names
	 * @param array $make Full make definition (for M:N filter check)
	 * @return true|array True if valid, error array otherwise
	 */
	private function qal_validate_join( $join, $view_name, $available_materials, $defined_views, $make = array() ) {
		if ( ! is_array( $join ) ) {
			return array(
				'valid' => false,
				'error_code' => 'E_INVALID_JOIN',
				'message' => 'Join must be an object.',
				'location' => "make.{$view_name}.join",
				'details' => array(
					'expected_type' => 'object',
					'received_type' => gettype( $join )
				)
			);
		}

		// "with" is required
		if ( ! isset( $join['with'] ) || ! is_string( $join['with'] ) ) {
			return array(
				'valid' => false,
				'error_code' => 'E_INVALID_JOIN',
				'message' => 'Join must have a string "with" field.',
				'location' => "make.{$view_name}.join",
				'details' => array(
					'missing_field' => 'with'
				)
			);
		}

		// "with" must reference a valid material or defined view
		$with = $join['with'];
		$is_material = $this->wrap_in_array( $with, $available_materials, true );
		$is_view = $this->wrap_in_array( $with, $defined_views, true );
		if ( ! $is_material && ! $is_view ) {
			return array(
				'valid' => false,
				'error_code' => 'E_INVALID_JOIN',
				'message' => 'Join "with" references unknown material or view: ' . $with,
				'location' => "make.{$view_name}.join.with",
				'details' => array(
					'received_value' => $with,
					'available_materials' => $available_materials,
					'defined_views' => $defined_views
				)
			);
		}

		// "on" is required
		if ( ! isset( $join['on'] ) || ! is_array( $join['on'] ) || empty( $join['on'] ) ) {
			return array(
				'valid' => false,
				'error_code' => 'E_INVALID_JOIN',
				'message' => 'Join must have a non-empty "on" array.',
				'location' => "make.{$view_name}.join.on",
				'details' => array(
					'missing_field' => 'on'
				)
			);
		}

		// Validate each on condition
		foreach ( $join['on'] as $on_index => $on_pair ) {
			if ( ! is_array( $on_pair ) ) {
				return array(
					'valid' => false,
					'error_code' => 'E_INVALID_JOIN',
					'message' => 'Each "on" entry must be an object.',
					'location' => "make.{$view_name}.join.on[{$on_index}]",
					'details' => array(
						'expected_type' => 'object',
						'received_type' => gettype( $on_pair )
					)
				);
			}

			if ( ! isset( $on_pair['left'] ) || ! is_string( $on_pair['left'] ) ) {
				return array(
					'valid' => false,
					'error_code' => 'E_INVALID_JOIN',
					'message' => 'Join "on" entry must have a string "left" field.',
					'location' => "make.{$view_name}.join.on[{$on_index}]",
					'details' => array(
						'missing_field' => 'left'
					)
				);
			}

			if ( ! isset( $on_pair['right'] ) || ! is_string( $on_pair['right'] ) ) {
				return array(
					'valid' => false,
					'error_code' => 'E_INVALID_JOIN',
					'message' => 'Join "on" entry must have a string "right" field.',
					'location' => "make.{$view_name}.join.on[{$on_index}]",
					'details' => array(
						'missing_field' => 'right'
					)
				);
			}

			// Validate left/right are qualified column references
			if ( ! $this->is_valid_qualified_column( $on_pair['left'], $available_materials, $defined_views ) ) {
				return array(
					'valid' => false,
					'error_code' => 'E_INVALID_JOIN',
					'message' => 'Invalid column reference in join on.left: ' . $on_pair['left'],
					'location' => "make.{$view_name}.join.on[{$on_index}].left",
					'details' => array(
						'received_value' => $on_pair['left'],
						'available_materials' => $available_materials
					)
				);
			}

			if ( ! $this->is_valid_qualified_column( $on_pair['right'], $available_materials, $defined_views ) ) {
				return array(
					'valid' => false,
					'error_code' => 'E_INVALID_JOIN',
					'message' => 'Invalid column reference in join on.right: ' . $on_pair['right'],
					'location' => "make.{$view_name}.join.on[{$on_index}].right",
					'details' => array(
						'received_value' => $on_pair['right'],
						'available_materials' => $available_materials
					)
				);
			}
		}

		// "if not match" is optional, but if present must be "keep-left" or "drop"
		if ( isset( $join['if not match'] ) ) {
			$if_not_match = $join['if not match'];
			if ( $if_not_match !== 'keep-left' && $if_not_match !== 'drop' ) {
				return array(
					'valid' => false,
					'error_code' => 'E_INVALID_JOIN',
					'message' => 'Join "if not match" must be "keep-left" or "drop". Got: ' . $if_not_match,
					'location' => "make.{$view_name}.join.if not match",
					'details' => array(
						'received_value' => $if_not_match,
						'allowed_values' => array( 'keep-left', 'drop' )
					)
				);
			}
		}

		// T45 Phase 3: M:N cardinality filter check
		$with = $join['with'];
		$resolved_material = null;
		$joined_view_has_filter = false;

		if ( $this->wrap_in_array( $with, $defined_views, true ) ) {
			// "with" is a view — walk the view chain to find the underlying material
			// Also check if any view in the chain has a filter
			$current = $with;
			$depth = 0;
			while ( $this->wrap_in_array( $current, $defined_views, true ) && $depth < 10 ) {
				if ( isset( $make[ $current ]['filter'] ) && ! empty( $make[ $current ]['filter'] ) ) {
					$joined_view_has_filter = true;
				}
				if ( isset( $make[ $current ]['from'][0] ) ) {
					$current = $make[ $current ]['from'][0];
				} else {
					break;
				}
				$depth++;
			}
			// $current is now a material name (or unresolvable)
			if ( $this->wrap_in_array( $current, $available_materials, true ) ) {
				$resolved_material = $current;
			}
		} elseif ( $this->wrap_in_array( $with, $available_materials, true ) ) {
			// "with" is a material directly
			$resolved_material = $with;
		}

		if ( $resolved_material !== null ) {
			$cardinality = $this->get_material_join_cardinality( $resolved_material );
			if ( $cardinality === 'N:M' && ! $joined_view_has_filter ) {
				return array(
					'valid'      => false,
					'error_code' => 'E_JOIN_FILTER_REQUIRED',
					'message'    => 'M:N JOIN requires at least one filter on the joined material. '
					              . 'GSC has multiple rows per page (keywords × days). '
					              . 'Add a filter (e.g. keyword) to narrow rows before JOIN.',
					'location'   => "make.{$view_name}.join",
					'details'    => array(
						'material'    => $resolved_material,
						'cardinality' => 'N:M',
						'hint'        => 'Add filter to the view or material being joined',
					),
				);
			}
		}

		return true;
	}

	/**
	 * Validate result field
	 *
	 * @param array $result Result configuration to validate
	 * @param array $rule Validation rule
	 * @param array $make Make configuration (available views)
	 * @return true|array True if valid, error array otherwise
	 */
	private function qal_validate_result( $result, $rule, $make ) {
		if ( ! is_array( $result ) ) {
			return array(
				'valid' => false,
				'error_code' => 'E_INVALID_RESULT',
				'message' => 'Result must be an object.',
				'location' => 'result',
				'details' => array(
					'expected_type' => 'object',
					'received_type' => gettype( $result )
				)
			);
		}

		// V2: Check for forbidden keys in result
		$allowed_result_keys = array( 'use', 'limit', 'include_count', 'count_only', 'sample', 'return' );
		foreach ( $result as $key => $value ) {
			if ( ! $this->wrap_in_array( $key, $allowed_result_keys, true ) && strpos( $key, 'x-' ) !== 0 ) {
				return array(
					'valid' => false,
					'error_code' => 'E_RESULT_FORBIDDEN_KEY',
					'message' => 'Forbidden key in result: ' . $key,
					'location' => 'result.' . $key,
					'details' => array(
						'forbidden_key' => $key,
						'allowed_keys' => $allowed_result_keys,
						'note' => 'Keys with "x-" prefix are also allowed.'
					)
				);
			}
		}

		if ( ! isset( $result['use'] ) ) {
			return array(
				'valid' => false,
				'error_code' => 'E_UNKNOWN_VIEW',
				'message' => 'Result.use does not match any defined view in make.',
				'location' => 'result.use',
				'details' => array(
					'missing_field' => 'use'
				)
			);
		}

		$available_views = array_keys( $make );
		if ( ! $this->wrap_in_array( $result['use'], $available_views, true ) ) {
			return array(
				'valid' => false,
				'error_code' => 'E_UNKNOWN_VIEW',
				'message' => 'Result.use does not match any defined view in make.',
				'location' => 'result.use',
				'details' => array(
					'available_views' => $available_views,
					'requested_view' => $result['use']
				)
			);
		}

		if ( isset( $result['limit'] ) ) {
			if ( ! is_int( $result['limit'] ) ) {
				return array(
					'valid' => false,
					'error_code' => 'E_INVALID_LIMIT',
					'message' => 'Limit must be an integer.',
					'location' => 'result.limit',
					'details' => array(
						'expected_type' => 'integer',
						'received_type' => gettype( $result['limit'] )
					)
				);
			}

			if ( $result['limit'] < 1 || $result['limit'] > 50000 ) {
				return array(
					'valid' => false,
					'error_code' => 'E_INVALID_LIMIT',
					'message' => 'Limit must be between 1 and 50000.',
					'location' => 'result.limit',
					'details' => array(
						'minimum' => 1,
						'maximum' => 50000,
						'received_value' => $result['limit']
					)
				);
			}
		}

		if ( isset( $result['count_only'] ) && ! is_bool( $result['count_only'] ) ) {
			return array(
				'valid' => false,
				'error_code' => 'E_INVALID_COUNT_ONLY',
				'message' => 'count_only must be a boolean.',
				'location' => 'result.count_only',
				'details' => array(
					'expected_type' => 'boolean',
					'received_type' => gettype( $result['count_only'] )
				)
			);
		}

		return true;
	}

	/**
	 * Apply default values to QAL
	 *
	 * @param array $qal QAL to apply defaults to
	 * @param array $rules Validation rules containing defaults
	 * @return array QAL with defaults applied
	 */
	private function qal_apply_defaults( $qal, $rules ) {
		if ( ! isset( $qal['result']['limit'] ) ) {
			$qal['result']['limit'] = $rules['result']['properties']['limit']['default'];
		}

		if ( ! isset( $qal['result']['count_only'] ) ) {
			$qal['result']['count_only'] = $rules['result']['properties']['count_only']['default'];
		}

		return $qal;
	}

	/**
	 * Get error information from rules
	 *
	 * @param array $rules Validation rules
	 * @param string $field Field name
	 * @return array Error information with code and message
	 */
	private function get_error_info( $rules, $field ) {
		if ( isset( $rules[ $field ]['errors'][0] ) ) {
			return array(
				'code' => $rules[ $field ]['errors'][0]['code'],
				'message' => $rules[ $field ]['errors'][0]['message']
			);
		}

		return array(
			'code' => 'E_VALIDATION_ERROR',
			'message' => 'Validation error occurred.'
		);
	}

	/**
	 * Build execution plan from QAL
	 * 
	 * Validates the provided QAL and converts it to ExecutableQAL by adding
	 * execution_status metadata for tracking the progress of filter, join, and calc operations.
	 * 
	 * In the validation manifest version 2025-10-20 (see yaml/qal-validation-2025-10-20.php),
	 * filter/join/calc operations are not yet supported, so all has_operation flags are set to false.
	 *
	 * @param array $input_data Array containing 'qal' key with QAL structure
	 * @return array ExecutableQAL with execution_status on success, or error array on failure
	 */
	public function qal_build_execute_plan( $input_data ) {
		$validation_result = $this->qal_validate_qal( $input_data );

		if ( ! $validation_result['valid'] ) {
			$error_response = array(
				'error_code' => $validation_result['error_code'],
				'message' => $validation_result['message'],
				'location' => $validation_result['location']
			);
			if ( isset( $validation_result['details'] ) ) {
				$error_response['details'] = $validation_result['details'];
			}
			return $error_response;
		}

		$validated_qal = $validation_result['validated_qal'];

		$execution_status = array();

		foreach ( $validated_qal['make'] as $view_name => $view_def ) {
			$has_join = isset( $view_def['join'] ) && is_array( $view_def['join'] ) && ! empty( $view_def['join'] );
			$has_calc = isset( $view_def['calc'] ) && is_array( $view_def['calc'] ) && ! empty( $view_def['calc'] );
			$has_sort = isset( $view_def['sort'] ) && is_array( $view_def['sort'] ) && ! empty( $view_def['sort'] );

			// Auto-derive add from calc keys when add is omitted
			if ( $has_calc && ! isset( $validated_qal['make'][ $view_name ]['add'] ) ) {
				$validated_qal['make'][ $view_name ]['add'] = array_keys( $view_def['calc'] );
			}

			$execution_status[ $view_name ] = array(
				'filter' => array(
					'completed' => false,
					'has_operation' => true  // Always true: filter step loads initial data from material
				),
				'join' => array(
					'completed' => false,
					'has_operation' => $has_join
				),
				'calc' => array(
					'completed' => false,
					'has_operation' => $has_calc
				),
				'sort' => array(
					'completed' => false,
					'has_operation' => $has_sort
				)
			);
		}

		$executable_qal = array(
			'qal' => $validated_qal,
			'execution_status' => $execution_status
		);

		return $executable_qal;
	}

	/**
	 * Execute QAL query
	 * 
	 * Main orchestrator function that executes filter → join → calc operations in fixed order
	 * for each make step. Checks execution_status to determine which operations to execute,
	 * and updates the status after each step completion.
	 * 
	 * In the 2025-10-20 version, join and calc are not yet supported, so only filter operations
	 * are executed. Steps with has_operation=false are skipped and marked as completed.
	 *
	 * @param array $executable_qal ExecutableQAL structure with qal and execution_status
	 * @return array Final response from qal_build_response on success, or error array on failure
	 */
	public function qal_executor( $executable_qal ) {
		// Validate input structure
		if ( ! isset( $executable_qal['qal'] ) || ! isset( $executable_qal['execution_status'] ) ) {
			return array(
				'error_code' => 'E_INVALID_EXECUTABLE_QAL',
				'message' => __( 'ExecutableQAL must contain qal and execution_status.', 'qa-heatmap-analytics' ),
				'location' => 'executable_qal'
			);
		}

		$qal = $executable_qal['qal'];
		$execution_status = $executable_qal['execution_status'];

		if ( ! isset( $executable_qal['views'] ) ) {
			$executable_qal['views'] = array();
		}

		foreach ( $qal['make'] as $view_name => $view_def ) {
			if ( ! isset( $execution_status[ $view_name ] ) ) {
				return array(
					'error_code' => 'E_EXECUTION_STATUS_MISSING',
					/* translators: %s: view name */
					'message' => sprintf( __( 'Execution status missing for view: %s', 'qa-heatmap-analytics' ), $view_name ),
					'location' => "execution_status.{$view_name}"
				);
			}

			$view_status = $executable_qal['execution_status'][ $view_name ];

			if ( ! $view_status['filter']['completed'] ) {
				if ( $view_status['filter']['has_operation'] ) {
					$from_source = $view_def['from'][0];

					if ( isset( $executable_qal['views'][ $from_source ] ) ) {
						// View chain: use data from previously computed view
						$source_view = $executable_qal['views'][ $from_source ];
						$source_data = isset( $source_view['data'] ) ? $source_view['data'] : array();

						// T33: Apply keep columns as in-memory filter for view chain
						// Preserve columns referenced by calc and join in addition to keep columns
						if ( ! empty( $view_def['keep'] ) ) {
							$prefix_list = array( $from_source );
							if ( isset( $executable_qal['qal']['materials'] ) ) {
								foreach ( $executable_qal['qal']['materials'] as $m ) {
									if ( isset( $m['name'] ) ) {
										$prefix_list[] = $m['name'];
									}
								}
							}
							$keep_short = array();
							foreach ( $view_def['keep'] as $col ) {
								$keep_short[] = $this->strip_material_prefix( $col, $prefix_list );
							}
							// Also preserve columns referenced by calc expressions
							if ( ! empty( $view_def['calc'] ) ) {
								foreach ( $view_def['calc'] as $calc_expr ) {
									$paren_open = strpos( $calc_expr, '(' );
									$paren_close = strpos( $calc_expr, ')' );
									if ( false !== $paren_open && false !== $paren_close ) {
										$full_col = substr( $calc_expr, $paren_open + 1, $paren_close - $paren_open - 1 );
										$keep_short[] = $this->strip_material_prefix( $full_col, $prefix_list );
									}
								}
							}
							// Also preserve columns referenced by join keys
							if ( ! empty( $view_def['join'] ) ) {
								$on_conditions = isset( $view_def['join']['on'] ) ? $view_def['join']['on'] : array();
								foreach ( $on_conditions as $on_pair ) {
									if ( isset( $on_pair['left'] ) ) {
										$keep_short[] = $this->strip_material_prefix( $on_pair['left'], $prefix_list );
									}
								}
							}
							$keep_set = array_flip( $keep_short );
							$filtered_data = array();
							foreach ( $source_data as $record ) {
								$filtered_data[] = array_intersect_key( $record, $keep_set );
							}
							$source_data = $filtered_data;
						}

						$executable_qal = $this->qal_save_view( $executable_qal, array(
							'view_name'     => $view_name,
							'material_name' => isset( $source_view['material_name'] ) ? $source_view['material_name'] : $from_source,
							'record_count'  => $this->wrap_count( $source_data ),
							'data'          => $source_data,
						), $view_name, 'filter' );
					} else {
						// Material source: fetch from storage via Material layer
						global $qahm_qal_material;
						$filter_result = $qahm_qal_material->qal_filter_apply( $executable_qal, $view_name );

						if ( isset( $filter_result['error_code'] ) ) {
							return $filter_result;
						}

						$executable_qal = $this->qal_save_view( $executable_qal, $filter_result, $view_name, 'filter' );
					}
				} else {
					$executable_qal['execution_status'][ $view_name ]['filter']['completed'] = true;
				}
			}

			// Refresh view_status after filter operation
			$view_status = $executable_qal['execution_status'][ $view_name ];

			if ( ! $view_status['join']['completed'] ) {
				if ( $view_status['join']['has_operation'] ) {
					global $qahm_qal_material;
					$join_result = $qahm_qal_material->qal_join_apply( $executable_qal, $view_name );

					if ( isset( $join_result['error_code'] ) ) {
						return $join_result;
					}

					$executable_qal = $this->qal_save_view( $executable_qal, $join_result, $view_name, 'join' );
				} else {
					$executable_qal['execution_status'][ $view_name ]['join']['completed'] = true;
				}
			}

			// Refresh view_status after join operation
			$view_status = $executable_qal['execution_status'][ $view_name ];

			if ( ! $view_status['calc']['completed'] ) {
				if ( $view_status['calc']['has_operation'] ) {
					$calc_result = $this->qal_execute_calc( $executable_qal, $view_name );

					if ( isset( $calc_result['error_code'] ) ) {
						return $calc_result;
					}

					$executable_qal = $this->qal_save_view( $executable_qal, $calc_result, $view_name, 'calc' );
				} else {
					$executable_qal['execution_status'][ $view_name ]['calc']['completed'] = true;
				}
			}

			// Refresh view_status after calc operation
			$view_status = $executable_qal['execution_status'][ $view_name ];

			if ( ! $view_status['sort']['completed'] ) {
				if ( $view_status['sort']['has_operation'] ) {
					$sort_result = $this->qal_execute_sort( $executable_qal, $view_name );

					if ( isset( $sort_result['error_code'] ) ) {
						return $sort_result;
					}

					$executable_qal = $this->qal_save_view( $executable_qal, $sort_result, $view_name, 'sort' );
				} else {
					$executable_qal['execution_status'][ $view_name ]['sort']['completed'] = true;
				}
			}
		}

		return $this->qal_build_response( $executable_qal );
	}

	/**
	 * Save view data to ExecutableQAL
	 * 
	 * Stores the result data from Material or Executor layer operations into the views
	 * section of ExecutableQAL and updates the execution_status for the completed step.
	 *
	 * @param array $executable_qal Current ExecutableQAL structure
	 * @param array $result_data Result data from filter/join/calc operation
	 * @param string $view_name Name of the view being processed
	 * @param string $step_name Step name: 'filter', 'join', 'calc', or 'sort'
	 * @return array Updated ExecutableQAL with saved view and updated status
	 */
	public function qal_save_view( $executable_qal, $result_data, $view_name, $step_name ) {
		if ( isset( $result_data['view_name'] ) && $result_data['view_name'] !== $view_name ) {
			global $qahm_log;
			if ( is_object( $qahm_log ) && method_exists( $qahm_log, 'warning' ) ) {
				$result_view_name = $this->wrap_substr( (string) $result_data['view_name'], 0, 100 );
				$param_view_name = $this->wrap_substr( (string) $view_name, 0, 100 );
			
				if ( function_exists( 'esc_html' ) ) {
					$result_view_name = esc_html( $result_view_name );
					$param_view_name = esc_html( $param_view_name );
				} else {
					$result_view_name = htmlspecialchars( $result_view_name, ENT_QUOTES, 'UTF-8' );
					$param_view_name = htmlspecialchars( $param_view_name, ENT_QUOTES, 'UTF-8' );
				}
			
				$qahm_log->warning( 
					sprintf(
						"View name mismatch in qal_save_view: result_data['view_name']=%s, parameter view_name=%s. Using parameter value for consistency.",
						$result_view_name,
						$param_view_name
					)
				);
			}
		}
		
		if ( isset( $result_data['view_name'] ) && isset( $result_data['data'] ) ) {
			$executable_qal['views'][ $view_name ] = array(
				'material_name' => isset( $result_data['material_name'] ) ? $result_data['material_name'] : '',
				'record_count' => isset( $result_data['record_count'] ) 
					? $result_data['record_count'] 
					: ( is_array( $result_data['data'] ) ? $this->wrap_count( $result_data['data'] ) : 0 ),
				'data' => $result_data['data']
			);
		}

		if ( isset( $executable_qal['execution_status'][ $view_name ][ $step_name ] ) ) {
			$executable_qal['execution_status'][ $view_name ][ $step_name ]['completed'] = true;
		}

		return $executable_qal;
	}

	/**
	 * Execute sort operation on view data
	 *
	 * Sorts view data by a single column and optionally limits to top N rows.
	 * The 'by' column is normalized (strip material prefix) and validated against
	 * keep + add columns. record_count preserves the pre-top count so clients
	 * can display "N of M".
	 *
	 * @param array $executable_qal Current ExecutableQAL structure
	 * @param string $view_name Name of the view to process
	 * @return array Result data with view_name, record_count, and data, or error array
	 */
	public function qal_execute_sort( $executable_qal, $view_name ) {
		$rows = isset( $executable_qal['views'][ $view_name ]['data'] )
			? $executable_qal['views'][ $view_name ]['data']
			: array();
		$view_def = $executable_qal['qal']['make'][ $view_name ];
		$sort_location = "make.{$view_name}.sort";

		if ( ! isset( $view_def['sort'] ) || ! is_array( $view_def['sort'] ) ) {
			return array(
				'error_code' => 'E_INVALID_SORT',
				'message' => __( 'Invalid sort definition: sort must be an object.', 'qa-heatmap-analytics' ),
				'location' => $sort_location
			);
		}

		$sort_def = $view_def['sort'];

		if ( ! isset( $sort_def['by'] ) || ! is_string( $sort_def['by'] ) || '' === trim( $sort_def['by'] ) ) {
			return array(
				'error_code' => 'E_INVALID_SORT',
				'message' => __( 'Invalid sort definition: "by" must be a non-empty string.', 'qa-heatmap-analytics' ),
				'location' => $sort_location
			);
		}

		if ( ! isset( $sort_def['order'] ) || ( $sort_def['order'] !== 'asc' && $sort_def['order'] !== 'desc' ) ) {
			return array(
				'error_code' => 'E_INVALID_SORT',
				'message' => __( 'Invalid sort definition: "order" must be "asc" or "desc".', 'qa-heatmap-analytics' ),
				'location' => $sort_location
			);
		}

		if ( isset( $sort_def['top'] ) && ( ! is_int( $sort_def['top'] ) || $sort_def['top'] < 1 ) ) {
			return array(
				'error_code' => 'E_INVALID_SORT',
				'message' => __( 'Invalid sort definition: "top" must be a positive integer.', 'qa-heatmap-analytics' ),
				'location' => $sort_location
			);
		}

		$sort_order = $sort_def['order'];
		$sort_top = isset( $sort_def['top'] ) ? (int) $sort_def['top'] : 0;

		// Build material name list for prefix matching (reused by strip_material_prefix)
		$material_names = array();
		if ( isset( $executable_qal['qal']['materials'] ) ) {
			foreach ( $executable_qal['qal']['materials'] as $m ) {
				if ( isset( $m['name'] ) ) {
					$material_names[] = $m['name'];
				}
			}
		}
		if ( isset( $executable_qal['views'] ) ) {
			foreach ( array_keys( $executable_qal['views'] ) as $vn ) {
				$material_names[] = $vn;
			}
		}

		// Normalize sort column using the same logic as calc (strip_material_prefix)
		$sort_by = $this->strip_material_prefix( $sort_def['by'], $material_names );

		// Build valid column set: keep (short names) + add
		$valid_columns = array();
		$keep_cols = isset( $view_def['keep'] ) ? $view_def['keep'] : array();
		foreach ( $keep_cols as $col ) {
			$valid_columns[] = $this->strip_material_prefix( $col, $material_names );
		}
		$add_cols = isset( $view_def['add'] ) ? $view_def['add'] : array();
		foreach ( $add_cols as $col ) {
			$valid_columns[] = $col;
		}

		// Validate sort column exists in keep + add
		if ( ! in_array( $sort_by, $valid_columns, true ) ) {
			return array(
				'error_code' => 'E_UNKNOWN_SORT_COLUMN',
				/* translators: %1$s: column name, %2$s: view name */
				'message' => sprintf( __( "Sort column '%1\$s' not found in keep or add columns of view '%2\$s'.", 'qa-heatmap-analytics' ), $sort_by, $view_name ),
				'location' => "make.{$view_name}.sort.by"
			);
		}

		$record_count = $this->wrap_count( $rows );

		// Sort using usort
		$is_desc = ( $sort_order === 'desc' );
		$col_name = $sort_by;
		usort( $rows, function ( $a, $b ) use ( $col_name, $is_desc ) {
			$va = isset( $a[ $col_name ] ) ? $a[ $col_name ] : null;
			$vb = isset( $b[ $col_name ] ) ? $b[ $col_name ] : null;

			if ( $va === null && $vb === null ) {
				return 0;
			}
			if ( $va === null ) {
				return 1;
			}
			if ( $vb === null ) {
				return -1;
			}

			if ( is_numeric( $va ) && is_numeric( $vb ) ) {
				$cmp = ( (float) $va <=> (float) $vb );
			} else {
				$cmp = strcmp( (string) $va, (string) $vb );
			}

			return $is_desc ? -$cmp : $cmp;
		} );

		// Apply top limit
		if ( $sort_top > 0 ) {
			$rows = array_slice( $rows, 0, $sort_top );
		}

		return array(
			'view_name' => $view_name,
			'record_count' => $record_count,
			'data' => $rows
		);
	}

	/**
	 * Execute calc operations
	 *
	 * Performs group-by aggregation operations on view data. Groups data by keep columns
	 * and applies calc functions (COUNT, COUNTUNIQUE, SUM, AVERAGE, MIN, MAX) to each group.
	 *
	 * @param array $executable_qal Current ExecutableQAL structure
	 * @param string $view_name Name of the view to process
	 * @return array Result data with view_name, record_count, and data, or error array
	 */
	public function qal_execute_calc( $executable_qal, $view_name ) {
		$rows = isset( $executable_qal['views'][ $view_name ]['data'] )
			? $executable_qal['views'][ $view_name ]['data']
			: array();
		$view_def = $executable_qal['qal']['make'][ $view_name ];
		$keep_cols = $view_def['keep'];
		$calc_defs = $view_def['calc'];
		$add_cols = isset( $view_def['add'] ) ? $view_def['add'] : array_keys( $calc_defs );

		// Build material name list for prefix matching (handles dot-containing names like "events.purchase")
		$material_names = array();
		if ( isset( $executable_qal['qal']['materials'] ) ) {
			foreach ( $executable_qal['qal']['materials'] as $m ) {
				if ( isset( $m['name'] ) ) {
					$material_names[] = $m['name'];
				}
			}
		}
		// T33: Add defined view names as prefixes for view chain calc support
		if ( isset( $executable_qal['views'] ) ) {
			foreach ( array_keys( $executable_qal['views'] ) as $vn ) {
				$material_names[] = $vn;
			}
		}

		// Parse calc expressions: "FUNC(material.column)" -> [func, short_col]
		$parsed_calcs = array();
		foreach ( $calc_defs as $result_col => $expr ) {
			$paren_open = strpos( $expr, '(' );
			$paren_close = strpos( $expr, ')' );
			$func_name = strtoupper( substr( $expr, 0, $paren_open ) );
			$full_col = substr( $expr, $paren_open + 1, $paren_close - $paren_open - 1 );
			// T29: Remove material prefix using prefix matching for dot-containing material names
			$short_col = $this->strip_material_prefix( $full_col, $material_names );
			$parsed_calcs[ $result_col ] = array( 'func' => $func_name, 'col' => $short_col );
		}

		// Build short keep column names
		$keep_short = array();
		foreach ( $keep_cols as $col ) {
			$keep_short[] = $this->strip_material_prefix( $col, $material_names );
		}

		// Group rows by keep columns using "\0" separator
		$groups = array();
		$group_first = array();
		foreach ( $rows as $row ) {
			if ( empty( $keep_short ) ) {
				$group_key = '';
			} else {
				$key_parts = array();
				foreach ( $keep_short as $kcol ) {
					$key_parts[] = isset( $row[ $kcol ] ) ? (string) $row[ $kcol ] : '';
				}
				$group_key = implode( "\0", $key_parts );
			}
			if ( ! isset( $groups[ $group_key ] ) ) {
				$groups[ $group_key ] = array();
				$group_first[ $group_key ] = $row;
			}
			$groups[ $group_key ][] = $row;
		}

		// Apply calc functions to each group
		$result_data = array();
		foreach ( $groups as $group_key => $group_rows ) {
			$record = array();

			// Restore keep columns from first row of group
			foreach ( $keep_short as $kcol ) {
				$record[ $kcol ] = isset( $group_first[ $group_key ][ $kcol ] ) ? $group_first[ $group_key ][ $kcol ] : null;
			}

			// Apply each calc function
			foreach ( $parsed_calcs as $result_col => $calc_info ) {
				$func = $calc_info['func'];
				$col = $calc_info['col'];

				switch ( $func ) {
					case 'COUNT':
						$count = 0;
						foreach ( $group_rows as $r ) {
							if ( isset( $r[ $col ] ) && $r[ $col ] !== null ) {
								$count++;
							}
						}
						$record[ $result_col ] = $count;
						break;

					case 'COUNTUNIQUE':
						$seen = array();
						foreach ( $group_rows as $r ) {
							if ( isset( $r[ $col ] ) && $r[ $col ] !== null ) {
								$seen[ (string) $r[ $col ] ] = true;
							}
						}
						$record[ $result_col ] = $this->wrap_count( $seen );
						break;

					case 'SUM':
						$sum = 0;
						foreach ( $group_rows as $r ) {
							if ( isset( $r[ $col ] ) && is_numeric( $r[ $col ] ) ) {
								$sum += $r[ $col ];
							}
						}
						$record[ $result_col ] = $sum;
						break;

					case 'AVERAGE':
						$sum = 0;
						$count = 0;
						foreach ( $group_rows as $r ) {
							if ( isset( $r[ $col ] ) && is_numeric( $r[ $col ] ) ) {
								$sum += $r[ $col ];
								$count++;
							}
						}
						$record[ $result_col ] = ( $count > 0 ) ? $sum / $count : 0;
						break;

					case 'MIN':
						$min_val = null;
						foreach ( $group_rows as $r ) {
							if ( isset( $r[ $col ] ) && is_numeric( $r[ $col ] ) ) {
								if ( $min_val === null || $r[ $col ] < $min_val ) {
									$min_val = $r[ $col ];
								}
							}
						}
						$record[ $result_col ] = $min_val;
						break;

					case 'MAX':
						$max_val = null;
						foreach ( $group_rows as $r ) {
							if ( isset( $r[ $col ] ) && is_numeric( $r[ $col ] ) ) {
								if ( $max_val === null || $r[ $col ] > $max_val ) {
									$max_val = $r[ $col ];
								}
							}
						}
						$record[ $result_col ] = $max_val;
						break;
				}
			}

			$result_data[] = $record;
		}

		return array(
			'view_name' => $view_name,
			'record_count' => $this->wrap_count( $result_data ),
			'data' => $result_data
		);
	}

	/**
	 * Build final response from ExecutableQAL
	 *
	 * Processes the result section of ExecutableQAL, selects the specified view,
	 * applies limit, and constructs the final response to return to the user.
	 *
	 * The limit parameter controls how many records are returned:
	 * - Limit comes from ExecutableQAL (validated by qal-validation manifests)
	 * - Validation enforces: default 1000, maximum 50000
	 * - Fallback default: 1000 (only used if validation is bypassed)
	 * - No additional capping is performed at execution layer
	 * - The meta.total_count always reflects the full dataset size
	 * - The meta.returned_count reflects the actual number of records returned after limit is applied
	 *
	 * @param array $executable_qal ExecutableQAL with completed views
	 * @return array Final response with data and meta information, or error array
	 */
	public function qal_build_response( $executable_qal ) {
		$qal = $executable_qal['qal'];
		$result = $qal['result'];

		$view_name = $result['use'];

		if ( ! isset( $executable_qal['views'][ $view_name ] ) ) {
			return array(
				'error_code' => 'E_UNKNOWN_VIEW',
				/* translators: %s: view name */
				'message' => sprintf( __( "Result.use specifies view '%s' which does not exist.", 'qa-heatmap-analytics' ), $view_name ),
				'location' => 'result.use'
			);
		}

		$view = $executable_qal['views'][ $view_name ];
		$data = isset( $view['data'] ) && is_array( $view['data'] ) ? $view['data'] : array();
		$total_count = isset( $view['record_count'] ) ? $view['record_count'] : $this->wrap_count( $data );

		$limit = isset( $result['limit'] ) ? (int) $result['limit'] : 1000;

		$limited_data = array_slice( $data, 0, $limit );
		$returned_count = $this->wrap_count( $limited_data );

		return array(
			'data' => $limited_data,
			'meta' => array(
				'total_count' => $total_count,
				'returned_count' => $returned_count,
				'limit' => $limit
			)
		);
	}

	// =======================================================================
	// String validation helpers (preg_match replacement)
	// =======================================================================

	/**
	 * Check if string contains only alphanumeric, underscore, and hyphen characters
	 *
	 * @param string $str String to check
	 * @return bool True if valid
	 */
	private function is_alnum_dash( $str ) {
		$len = strlen( $str );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $str[ $i ];
			if ( ! ctype_alnum( $c ) && $c !== '_' && $c !== '-' ) {
				return false;
			}
		}
		return $len > 0;
	}

	/**
	 * Strip material prefix from a qualified column name.
	 * Handles dot-containing material names (e.g., "events.purchase.value" -> "value").
	 * Falls back to first-dot split if no material prefix matches.
	 *
	 * @param string $qualified_col Qualified column name (e.g., "events.purchase.value")
	 * @param array $material_names List of material names to try as prefixes
	 * @return string Short column name
	 */
	private function strip_material_prefix( $qualified_col, $material_names ) {
		foreach ( $material_names as $material ) {
			$prefix = $material . '.';
			if ( strpos( $qualified_col, $prefix ) === 0 ) {
				$col_name = substr( $qualified_col, strlen( $prefix ) );
				if ( $col_name !== '' ) {
					return $col_name;
				}
			}
		}
		// Fallback: split on first dot
		$dot_pos = strpos( $qualified_col, '.' );
		return ( $dot_pos !== false ) ? substr( $qualified_col, $dot_pos + 1 ) : $qualified_col;
	}

	/**
	 * Check if string contains only alphanumeric and underscore characters
	 *
	 * @param string $str String to check
	 * @return bool True if valid
	 */
	private function is_alnum_underscore( $str ) {
		$len = strlen( $str );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $str[ $i ];
			if ( ! ctype_alnum( $c ) && $c !== '_' ) {
				return false;
			}
		}
		return $len > 0;
	}

	/**
	 * Check if a column reference is valid: <material_or_view>.<column> format
	 * where material is one of available_materials or defined_views and column is alphanumeric+underscore.
	 * Handles dot-containing material names (e.g., events.purchase.value).
	 *
	 * @param string $column Column reference string
	 * @param array $available_materials List of valid material names
	 * @param array $defined_views List of previously defined view names (optional)
	 * @return bool True if valid
	 */
	private function is_valid_qualified_column( $column, $available_materials, $defined_views = array() ) {
		// Check materials first (longer prefixes like "events.purchase" before shorter ones)
		foreach ( $available_materials as $material ) {
			$prefix = $material . '.';
			if ( strpos( $column, $prefix ) === 0 ) {
				$col_name = substr( $column, strlen( $prefix ) );
				if ( $col_name !== '' && $this->is_alnum_underscore( $col_name ) ) {
					return true;
				}
			}
		}
		// Check defined views
		foreach ( $defined_views as $view ) {
			$prefix = $view . '.';
			if ( strpos( $column, $prefix ) === 0 ) {
				$col_name = substr( $column, strlen( $prefix ) );
				if ( $col_name !== '' && $this->is_alnum_underscore( $col_name ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Check if a material name matches allowed patterns:
	 * allpv, gsc, click_event, datalayer_event, goal_N (N>=1), events.{name}
	 *
	 * @param string $name Material name
	 * @return bool True if valid
	 */
	private function is_valid_material_name( $name ) {
		// Use manifest-derived names if available, otherwise fall back to hardcoded list
		// for compatibility with old builds that lack fixed_material_names
		$fixed_names = $this->fixed_material_names !== null
			? $this->fixed_material_names
			: array( 'allpv', 'gsc', 'click_event', 'datalayer_event', 'ga4_age_gender', 'ga4_country', 'ga4_region', 'page_version' );
		if ( in_array( $name, $fixed_names, true ) ) {
			return true;
		}

		// goal_N pattern (N is a positive integer, no leading zeros)
		if ( strpos( $name, 'goal_' ) === 0 && strlen( $name ) > 5 ) {
			$suffix = substr( $name, 5 );
			return ctype_digit( $suffix ) && $suffix[0] !== '0';
		}

		// events.{name} pattern
		if ( strpos( $name, 'events.' ) === 0 && strlen( $name ) > 7 ) {
			$event_name = substr( $name, 7 );
			return $event_name !== '' && $this->is_alnum_underscore( $event_name );
		}

		return false;
	}
}

$GLOBALS['qahm_qal_executor'] = new QAHM_Qal_Executor();
