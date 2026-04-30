<?php
defined( 'ABSPATH' ) || exit;
/**
 * QAL Executor Class
 *
 * Validates QAL (Query Abstraction Language) execution requests against
 * the validation manifest defined in qal-validation-2025-10-20.php (PHP array file).
 *
 * @package qa_heatmap
 */

class QAHM_Qal_Executor extends QAHM_File_Base {

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
				'valid'      => false,
				'error_code' => 'E_MANIFEST_LOAD_ERROR',
				'message'    => $validation_manifest->get_error_message(),
				'location'   => 'validation_manifest',
			);
		}

		if ( ! isset( $input_data['qal'] ) || ! is_array( $input_data['qal'] ) ) {
			return array(
				'valid'      => false,
				'error_code' => 'E_INVALID_INPUT',
				'message'    => 'Input must contain a "qal" array.',
				'location'   => 'input',
			);
		}

		$qal       = $input_data['qal'];
		$rules     = $validation_manifest['rules'];
		$structure = $validation_manifest['structure'];

		$required_fields = $structure['required'];
		foreach ( $required_fields as $field ) {
			if ( ! isset( $qal[ $field ] ) ) {
				$error_info = $this->get_error_info( $rules, $field );
				return array(
					'valid'      => false,
					'error_code' => $error_info['code'],
					'message'    => $error_info['message'],
					'location'   => $field,
					'details'    => array(
						'missing_field' => $field,
					),
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
			'valid'         => true,
			'validated_qal' => $validated_qal,
		);
	}

	/**
	 * Load validation manifest from PHP array file
	 *
	 * @return array|WP_Error Validation manifest array or WP_Error on failure
	 */
	private function qal_load_validation_manifest() {
		$manifest_file = __DIR__ . '/yaml/qal-validation-2025-10-20.php';

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
	 * Validate tracking_id field
	 *
	 * @param string $tracking_id Tracking ID to validate
	 * @param array $rule Validation rule
	 * @return true|array True if valid, error array otherwise
	 */
	public function qal_validate_tracking_id( $tracking_id, $rule = null ) {
		if ( ! is_string( $tracking_id ) ) {
			return array(
				'valid'      => false,
				'error_code' => 'E_UNKNOWN_TRACKING_ID',
				'message'    => 'Invalid tracking_id provided.',
				'location'   => 'tracking_id',
				'details'    => array(
					'expected_type' => 'string',
					'received_type' => gettype( $tracking_id ),
				),
			);
		}

		if ( isset( $rule['pattern'] ) && is_string( $rule['pattern'] ) && $rule['pattern'] !== '' ) {
			$pattern      = '/' . $rule['pattern'] . '/';
			$match_result = @preg_match( $pattern, $tracking_id );

			if ( $match_result === false ) {
				return array(
					'valid'      => false,
					'error_code' => 'E_INVALID_REGEX_PATTERN',
					'message'    => 'Invalid regex pattern provided for tracking_id validation.',
					'location'   => 'tracking_id',
					'details'    => array(
						'pattern' => $rule['pattern'],
					),
				);
			}

			if ( ! $match_result ) {
				return array(
					'valid'      => false,
					'error_code' => 'E_UNKNOWN_TRACKING_ID',
					'message'    => 'Invalid tracking_id provided.',
					'location'   => 'tracking_id',
					'details'    => array(
						'expected_pattern' => $rule['pattern'],
						'received_value'   => $tracking_id,
					),
				);
			}
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
				'valid'      => false,
				'error_code' => 'E_UNKNOWN_MATERIAL',
				'message'    => 'Materials must be an array.',
				'location'   => 'materials',
				'details'    => array(
					'expected_type' => 'array',
					'received_type' => gettype( $materials ),
				),
			);
		}

		if ( empty( $materials ) ) {
			return array(
				'valid'      => false,
				'error_code' => 'E_UNKNOWN_MATERIAL',
				'message'    => 'Materials array cannot be empty.',
				'location'   => 'materials',
				'details'    => array(
					'error' => 'Materials array cannot be empty',
				),
			);
		}

		// Support both enum-based and pattern-based validation
		$allowed_materials = isset( $rule['items']['properties']['name']['enum'] )
			? $rule['items']['properties']['name']['enum']
			: array();
		$material_pattern  = isset( $rule['items']['properties']['name']['pattern'] )
			? $rule['items']['properties']['name']['pattern']
			: null;

		foreach ( $materials as $index => $material ) {
			if ( ! is_array( $material ) ) {
				return array(
					'valid'      => false,
					'error_code' => 'E_UNKNOWN_MATERIAL',
					'message'    => 'Material must be an object.',
					'location'   => "materials[{$index}]",
					'details'    => array(
						'expected_type' => 'object',
						'received_type' => gettype( $material ),
					),
				);
			}

			if ( ! isset( $material['name'] ) ) {
				return array(
					'valid'      => false,
					'error_code' => 'E_UNKNOWN_MATERIAL',
					'message'    => 'Material name not found in manifest.',
					'location'   => "materials[{$index}].name",
					'details'    => array(
						'error' => 'Missing required field: name',
					),
				);
			}

			// Validate material name using enum or pattern
			$is_valid_material = false;

			// Check enum first (for backward compatibility)
			if ( ! empty( $allowed_materials ) && $this->wrap_in_array( $material['name'], $allowed_materials, true ) ) {
				$is_valid_material = true;
			}

			// Check pattern if enum didn't match
			if ( ! $is_valid_material && $material_pattern !== null ) {
				$regex = '/' . $material_pattern . '/';
				if ( preg_match( $regex, $material['name'] ) ) {
					$is_valid_material = true;
				}
			}

			if ( ! $is_valid_material ) {
				return array(
					'valid'      => false,
					'error_code' => 'E_UNKNOWN_MATERIAL',
					'message'    => 'Material name not found in manifest.',
					'location'   => "materials[{$index}].name",
					'details'    => array(
						'allowed_values' => ! empty( $allowed_materials ) ? $allowed_materials : null,
						'pattern'        => $material_pattern,
						'received_value' => $material['name'],
					),
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
				'valid'      => false,
				'error_code' => 'E_TIME_REQUIRED',
				'message'    => 'Missing time.start, time.end, or time.tz.',
				'location'   => 'time',
				'details'    => array(
					'expected_type' => 'object',
					'received_type' => gettype( $time ),
				),
			);
		}

		$required_fields = $rule['required'];
		foreach ( $required_fields as $field ) {
			if ( ! isset( $time[ $field ] ) ) {
				return array(
					'valid'      => false,
					'error_code' => 'E_TIME_REQUIRED',
					'message'    => 'Missing time.start, time.end, or time.tz.',
					'location'   => "time.{$field}",
					'details'    => array(
						'missing_field' => $field,
					),
				);
			}
		}

		if ( ! is_string( $time['start'] ) || ! is_string( $time['end'] ) || ! is_string( $time['tz'] ) ) {
			return array(
				'valid'      => false,
				'error_code' => 'E_TIME_REQUIRED',
				'message'    => 'time.start, time.end, and time.tz must be strings.',
				'location'   => 'time',
				'details'    => array(
					'start_type' => gettype( $time['start'] ),
					'end_type'   => gettype( $time['end'] ),
					'tz_type'    => gettype( $time['tz'] ),
				),
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
				'valid'      => false,
				'error_code' => 'E_INVALID_MAKE',
				'message'    => 'Make must be an object.',
				'location'   => 'make',
				'details'    => array(
					'expected_type' => 'object',
					'received_type' => gettype( $make ),
				),
			);
		}

		if ( empty( $make ) ) {
			return array(
				'valid'      => false,
				'error_code' => 'E_INVALID_MAKE',
				'message'    => 'Make cannot be empty.',
				'location'   => 'make',
				'details'    => array(
					'error' => 'At least one view must be defined',
				),
			);
		}

		$available_materials = array();
		foreach ( $materials as $material ) {
			if ( isset( $material['name'] ) ) {
				$available_materials[] = $material['name'];
			}
		}

		foreach ( $make as $view_name => $view_def ) {
			if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $view_name ) ) {
				return array(
					'valid'      => false,
					'error_code' => 'E_INVALID_VIEW_NAME',
					'message'    => 'Invalid view name.',
					'location'   => "make.{$view_name}",
					'details'    => array(
						'expected_pattern' => '^[a-zA-Z0-9_]+$',
						'received_value'   => $view_name,
					),
				);
			}

			if ( ! is_array( $view_def ) ) {
				return array(
					'valid'      => false,
					'error_code' => 'E_INVALID_VIEW',
					'message'    => 'View definition must be an object.',
					'location'   => "make.{$view_name}",
					'details'    => array(
						'expected_type' => 'object',
						'received_type' => gettype( $view_def ),
					),
				);
			}

			if ( ! isset( $view_def['from'] ) ) {
				return array(
					'valid'      => false,
					'error_code' => 'E_INVALID_VIEW',
					'message'    => 'View must have "from" field.',
					'location'   => "make.{$view_name}.from",
					'details'    => array(
						'missing_field' => 'from',
					),
				);
			}

			if ( ! isset( $view_def['keep'] ) ) {
				return array(
					'valid'      => false,
					'error_code' => 'E_INVALID_VIEW',
					'message'    => 'View must have "keep" field.',
					'location'   => "make.{$view_name}.keep",
					'details'    => array(
						'missing_field' => 'keep',
					),
				);
			}

			if ( ! is_array( $view_def['from'] ) || empty( $view_def['from'] ) ) {
				return array(
					'valid'      => false,
					'error_code' => 'E_INVALID_FROM',
					'message'    => 'From must be a non-empty array.',
					'location'   => "make.{$view_name}.from",
					'details'    => array(
						'error' => 'From must contain at least one material',
					),
				);
			}

			if ( $this->wrap_count( $view_def['from'] ) > 1 ) {
				return array(
					'valid'      => false,
					'error_code' => 'E_INVALID_FROM',
					'message'    => 'From must contain exactly one material.',
					'location'   => "make.{$view_name}.from",
					'details'    => array(
						'error' => 'Only one material is allowed in from array',
					),
				);
			}

			$from_material = $view_def['from'][0];
			if ( ! $this->wrap_in_array( $from_material, $available_materials, true ) ) {
				return array(
					'valid'      => false,
					'error_code' => 'E_UNKNOWN_MATERIAL',
					'message'    => 'Material name not found in manifest.',
					'location'   => "make.{$view_name}.from[0]",
					'details'    => array(
						'allowed_values' => $available_materials,
						'received_value' => $from_material,
					),
				);
			}

			if ( ! is_array( $view_def['keep'] ) || empty( $view_def['keep'] ) ) {
				return array(
					'valid'      => false,
					'error_code' => 'E_UNKNOWN_COLUMN',
					'message'    => 'Invalid column name in keep list.',
					'location'   => "make.{$view_name}.keep",
					'details'    => array(
						'error' => 'Keep must be a non-empty array',
					),
				);
			}

			foreach ( $view_def['keep'] as $keep_index => $column ) {
				if ( ! is_string( $column ) ) {
					return array(
						'valid'      => false,
						'error_code' => 'E_UNKNOWN_COLUMN',
						'message'    => 'Invalid column name in keep list.',
						'location'   => "make.{$view_name}.keep[{$keep_index}]",
						'details'    => array(
							'expected_type' => 'string',
							'received_type' => gettype( $column ),
						),
					);
				}

				$material_pattern = '/^(' . $this->wrap_implode(
					'|',
					$this->wrap_array_map(
						function ( $m ) {
							return preg_quote( $m, '/' );
						},
						$available_materials
					)
				) . ')\.[a-zA-Z0-9_]+$/';

				if ( ! preg_match( $material_pattern, $column ) ) {
					return array(
						'valid'      => false,
						'error_code' => 'E_UNKNOWN_COLUMN',
						'message'    => 'Invalid column name in keep list.',
						'location'   => "make.{$view_name}.keep[{$keep_index}]",
						'details'    => array(
							'expected_pattern'    => $material_pattern,
							'received_value'      => $column,
							'available_materials' => $available_materials,
						),
					);
				}
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
				'valid'      => false,
				'error_code' => 'E_INVALID_RESULT',
				'message'    => 'Result must be an object.',
				'location'   => 'result',
				'details'    => array(
					'expected_type' => 'object',
					'received_type' => gettype( $result ),
				),
			);
		}

		if ( ! isset( $result['use'] ) ) {
			return array(
				'valid'      => false,
				'error_code' => 'E_UNKNOWN_VIEW',
				'message'    => 'Result.use does not match any defined view in make.',
				'location'   => 'result.use',
				'details'    => array(
					'missing_field' => 'use',
				),
			);
		}

		$available_views = array_keys( $make );
		if ( ! $this->wrap_in_array( $result['use'], $available_views, true ) ) {
			return array(
				'valid'      => false,
				'error_code' => 'E_UNKNOWN_VIEW',
				'message'    => 'Result.use does not match any defined view in make.',
				'location'   => 'result.use',
				'details'    => array(
					'available_views' => $available_views,
					'requested_view'  => $result['use'],
				),
			);
		}

		if ( isset( $result['limit'] ) ) {
			if ( ! is_int( $result['limit'] ) ) {
				return array(
					'valid'      => false,
					'error_code' => 'E_INVALID_LIMIT',
					'message'    => 'Limit must be an integer.',
					'location'   => 'result.limit',
					'details'    => array(
						'expected_type' => 'integer',
						'received_type' => gettype( $result['limit'] ),
					),
				);
			}

			if ( $result['limit'] < 1 || $result['limit'] > 50000 ) {
				return array(
					'valid'      => false,
					'error_code' => 'E_INVALID_LIMIT',
					'message'    => 'Limit must be between 1 and 50000.',
					'location'   => 'result.limit',
					'details'    => array(
						'minimum'        => 1,
						'maximum'        => 50000,
						'received_value' => $result['limit'],
					),
				);
			}
		}

		if ( isset( $result['offset'] ) ) {
			if ( ! is_int( $result['offset'] ) ) {
				return array(
					'valid'      => false,
					'error_code' => 'E_INVALID_OFFSET',
					'message'    => 'Offset must be an integer.',
					'location'   => 'result.offset',
					'details'    => array(
						'expected_type' => 'integer',
						'received_type' => gettype( $result['offset'] ),
					),
				);
			}

			if ( $result['offset'] < 0 ) {
				return array(
					'valid'      => false,
					'error_code' => 'E_INVALID_OFFSET',
					'message'    => 'Offset must be 0 or greater.',
					'location'   => 'result.offset',
					'details'    => array(
						'minimum'        => 0,
						'received_value' => $result['offset'],
					),
				);
			}
		}

		if ( isset( $result['count_only'] ) && ! is_bool( $result['count_only'] ) ) {
			return array(
				'valid'      => false,
				'error_code' => 'E_INVALID_COUNT_ONLY',
				'message'    => 'count_only must be a boolean.',
				'location'   => 'result.count_only',
				'details'    => array(
					'expected_type' => 'boolean',
					'received_type' => gettype( $result['count_only'] ),
				),
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

		if ( ! isset( $qal['result']['offset'] ) ) {
			$qal['result']['offset'] = 0;
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
				'code'    => $rules[ $field ]['errors'][0]['code'],
				'message' => $rules[ $field ]['errors'][0]['message'],
			);
		}

		return array(
			'code'    => 'E_VALIDATION_ERROR',
			'message' => 'Validation error occurred.',
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
			return array(
				'error_code' => $validation_result['error_code'],
				'message'    => $validation_result['message'],
				'location'   => $validation_result['location'],
			);
		}

		$validated_qal = $validation_result['validated_qal'];

		$execution_status = array();

		foreach ( array_keys( $validated_qal['make'] ) as $view_name ) {
			$execution_status[ $view_name ] = array(
				'filter' => array(
					'completed'     => false,
					'has_operation' => true,  // Always true: filter step loads initial data from material
				),
				'join'   => array(
					'completed'     => false,
					'has_operation' => false,
				),
				'calc'   => array(
					'completed'     => false,
					'has_operation' => false,
				),
			);
		}

		$executable_qal = array(
			'qal'              => $validated_qal,
			'execution_status' => $execution_status,
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
				'message'    => __( 'ExecutableQAL must contain qal and execution_status.', 'qa-heatmap-analytics' ),
				'location'   => 'executable_qal',
			);
		}

		$qal              = $executable_qal['qal'];
		$execution_status = $executable_qal['execution_status'];

		if ( ! isset( $executable_qal['views'] ) ) {
			$executable_qal['views'] = array();
		}

		foreach ( $qal['make'] as $view_name => $view_def ) {
			if ( ! isset( $execution_status[ $view_name ] ) ) {
				return array(
					'error_code' => 'E_EXECUTION_STATUS_MISSING',
					/* translators: %s: view name */
					'message'    => sprintf( __( 'Execution status missing for view: %s', 'qa-heatmap-analytics' ), $view_name ),
					'location'   => "execution_status.{$view_name}",
				);
			}

			$view_status = $executable_qal['execution_status'][ $view_name ];

			if ( ! $view_status['filter']['completed'] ) {
				if ( $view_status['filter']['has_operation'] ) {
					global $qahm_qal_material;
					$filter_result = $qahm_qal_material->qal_filter_apply( $executable_qal, $view_name );

					if ( isset( $filter_result['error_code'] ) ) {
						return $filter_result;
					}

					$executable_qal = $this->qal_save_view( $executable_qal, $filter_result, $view_name, 'filter' );
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
	 * @param string $step_name Step name: 'filter', 'join', or 'calc'
	 * @return array Updated ExecutableQAL with saved view and updated status
	 */
	public function qal_save_view( $executable_qal, $result_data, $view_name, $step_name ) {
		if ( isset( $result_data['view_name'] ) && $result_data['view_name'] !== $view_name ) {
			global $qahm_log;
			if ( is_object( $qahm_log ) && method_exists( $qahm_log, 'warning' ) ) {
				$result_view_name = $this->wrap_substr( (string) $result_data['view_name'], 0, 100 );
				$param_view_name  = $this->wrap_substr( (string) $view_name, 0, 100 );

				if ( function_exists( 'esc_html' ) ) {
					$result_view_name = esc_html( $result_view_name );
					$param_view_name  = esc_html( $param_view_name );
				} else {
					$result_view_name = htmlspecialchars( $result_view_name, ENT_QUOTES, 'UTF-8' );
					$param_view_name  = htmlspecialchars( $param_view_name, ENT_QUOTES, 'UTF-8' );
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
				'record_count'  => isset( $result_data['record_count'] )
					? $result_data['record_count']
					: ( is_array( $result_data['data'] ) ? $this->wrap_count( $result_data['data'] ) : 0 ),
				'data'          => $result_data['data'],
			);
		}

		if ( isset( $executable_qal['execution_status'][ $view_name ][ $step_name ] ) ) {
			$executable_qal['execution_status'][ $view_name ][ $step_name ]['completed'] = true;
		}

		return $executable_qal;
	}

	/**
	 * Execute calc operations (stub for 2025-10-20 version)
	 *
	 * Performs group-by aggregation operations on view data. Groups data by keep columns
	 * and applies calc functions to each group.
	 *
	 * Note: This is a stub implementation for the 2025-10-20 version where calc is not yet supported.
	 *
	 * @param array $executable_qal Current ExecutableQAL structure
	 * @param string $view_name Name of the view to process
	 * @return array Result data with view_name, record_count, and data, or error array
	 */
	public function qal_execute_calc( $executable_qal, $view_name ) {
		return array(
			'error_code' => 'E_CALC_NOT_SUPPORTED',
			'message'    => __( 'Calc operations are not supported in version 2025-10-20.', 'qa-heatmap-analytics' ),
			'location'   => "make.{$view_name}.calc",
		);
	}

	/**
	 * Build final response from ExecutableQAL
	 *
	 * Processes the result section of ExecutableQAL, selects the specified view,
	 * applies limit and offset, and constructs the final response to return to the user.
	 *
	 * The limit parameter controls how many records are returned:
	 * - Limit comes from ExecutableQAL (validated by qal-validation manifests)
	 * - Validation enforces: default 1000, maximum 50000
	 * - Fallback default: 1000 (only used if validation is bypassed)
	 * - No additional capping is performed at execution layer
	 * - The meta.total_count always reflects the full dataset size
	 * - The meta.returned_count reflects the actual number of records returned after limit is applied
	 *
	 * The offset parameter controls where to start returning records:
	 * - Default: 0 (start from beginning)
	 * - Used for pagination when total_count > limit
	 *
	 * @param array $executable_qal ExecutableQAL with completed views
	 * @return array Final response with data and meta information, or error array
	 */
	public function qal_build_response( $executable_qal ) {
		$qal    = $executable_qal['qal'];
		$result = $qal['result'];

		$view_name = $result['use'];

		if ( ! isset( $executable_qal['views'][ $view_name ] ) ) {
			return array(
				'error_code' => 'E_UNKNOWN_VIEW',
				/* translators: %s: view name */
				'message'    => sprintf( __( "Result.use specifies view '%s' which does not exist.", 'qa-heatmap-analytics' ), $view_name ),
				'location'   => 'result.use',
			);
		}

		$view        = $executable_qal['views'][ $view_name ];
		$data        = isset( $view['data'] ) && is_array( $view['data'] ) ? $view['data'] : array();
		$total_count = isset( $view['record_count'] ) ? $view['record_count'] : $this->wrap_count( $data );

		$limit  = isset( $result['limit'] ) ? (int) $result['limit'] : 1000;
		$offset = isset( $result['offset'] ) ? (int) $result['offset'] : 0;

		$limited_data   = array_slice( $data, $offset, $limit );
		$returned_count = $this->wrap_count( $limited_data );

		return array(
			'data' => $limited_data,
			'meta' => array(
				'total_count'    => $total_count,
				'returned_count' => $returned_count,
				'limit'          => $limit,
				'offset'         => $offset,
			),
		);
	}
}

$GLOBALS['qahm_qal_executor'] = new QAHM_Qal_Executor();
