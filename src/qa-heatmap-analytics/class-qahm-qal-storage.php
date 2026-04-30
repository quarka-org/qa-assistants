<?php
/**
 * QAL Storage Class
 *
 * Provides data retrieval from storage layer (files or database) for QAL execution.
 * This class is responsible for fetching raw physical data without any decoding or transformation.
 *
 * @package qa_heatmap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QAHM_Qal_Storage extends QAHM_File_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
	}

	/**
	 * Fetch filtered data from storage layer
	 *
	 * Retrieves data from the appropriate storage backend (files or database)
	 * based on material definition. Returns physical column data without any decoding.
	 *
	 * For 2025-10-20 version:
	 * - filter_conditions supports per-material field filtering (allpv: utm_source, utm_medium, utm_campaign, device_type, country_code, url)
	 * - Supports indexed array format (IN clause) and operator object format (eq, prefix, contains, etc.)
	 * - Only time range filtering is applied for time (start and end dates)
	 * - The 'tz' parameter in time_range is currently ignored
	 * - Supported materials: allpv, gsc, goal_x (where x is the goal number)
	 *
	 * @param string $tracking_id Tracking ID
	 * @param string $material_name Material name (e.g., 'allpv', 'gsc')
	 * @param array $time_range Time range configuration with 'start', 'end', 'tz' (Note: 'tz' is currently ignored in 2025-10-20 version)
	 * @param array|null $filter_conditions Filter conditions (currently unused/reserved for future use; not supported in 2025-10-20)
	 * @param array $physical_columns Physical column names to retrieve
	 * @param bool $count_only Whether to return only the count (enables optimization for allpv material)
	 * @return array Result with record_count and data, or error array
	 */
	public function fetch_filtered_data( $tracking_id, $material_name, $time_range, $filter_conditions, $physical_columns, $count_only = false ) {
		// filter_conditions is passed to material-specific fetch methods for field-level filtering.
		global $qahm_file_functions;

		if ( ! is_object( $qahm_file_functions ) ) {
			return array(
				'error_code' => 'E_DEPENDENCY_NOT_AVAILABLE',
				'message'    => __( 'Required file functions dependency is not available.', 'qa-heatmap-analytics' ),
				'location'   => 'storage',
			);
		}

		if ( empty( $tracking_id ) || ! is_string( $tracking_id ) ) {
			return array(
				'error_code' => 'E_INVALID_TRACKING_ID',
				'message'    => __( 'Invalid tracking_id provided.', 'qa-heatmap-analytics' ),
				'location'   => 'storage',
			);
		}

		if ( ! is_array( $time_range ) || ! isset( $time_range['start'] ) || ! isset( $time_range['end'] ) ) {
			return array(
				'error_code' => 'E_INVALID_TIME_RANGE',
				'message'    => __( 'Invalid time_range provided. Must contain start and end.', 'qa-heatmap-analytics' ),
				'location'   => 'storage',
			);
		}

		switch ( $material_name ) {
			case 'allpv':
				return $this->fetch_allpv_data( $tracking_id, $time_range, $physical_columns, $filter_conditions, $count_only );

			case 'gsc':
				return $this->fetch_gsc_data( $tracking_id, $time_range, $physical_columns, $filter_conditions );

			default:
				// Check for goal_x pattern (e.g., goal_1, goal_2, etc.) - goal numbers start from 1
				if ( preg_match( '/^goal_([1-9]\d*)$/', $material_name, $matches ) ) {
					$goal_number = (int) $matches[1];
					return $this->fetch_goal_data( $tracking_id, $time_range, $physical_columns, $goal_number, $filter_conditions );
				}

				return array(
					'error_code' => 'E_MATERIAL_NOT_SUPPORTED',
					/* translators: %s: material name */
					'message'    => sprintf( __( "Material '%s' is not supported in version 2025-10-20", 'qa-heatmap-analytics' ), $material_name ),
					'location'   => 'storage',
				);
		}
	}

	/**
	 * Fetch joined data from storage layer
	 *
	 * Retrieves data for join operations from the appropriate storage backend.
	 * This method is called by Material layer after extracting join keys from existing views.
	 * It fetches data that matches the provided join keys and returns physical column data.
	 *
	 * For 2025-10-20 version:
	 * - Join operations are NOT supported yet
	 * - This is a stub implementation that returns an error
	 * - The interface is designed for future implementation (2026+)
	 *
	 * Future implementation (2026+) will:
	 * - Accept join_keys parameter with key-value pairs (e.g., ["url" => ["https://...", "https://..."]])
	 * - Filter data based on join keys to retrieve only matching records
	 * - Apply time_range filtering if necessary
	 * - Return physical column data (IDs, not decoded values)
	 * - Support materials like 'gsc', 'allpv', etc.
	 *
	 * Expected future parameters:
	 * - tracking_id: Tracking ID for the site
	 * - material_name: Material name to join (e.g., 'gsc')
	 * - time_range: Time range configuration with 'start', 'end', 'tz'
	 * - join_keys: Associative array of join key column names and their values
	 * - physical_columns: Physical column names to retrieve
	 *
	 * Expected future return (success):
	 * - record_count: Number of records retrieved
	 * - data: Array of records with physical column data
	 *
	 * Expected future return (error):
	 * - error_code: Error code (e.g., 'E_JOIN_COLUMN_NOT_FOUND')
	 * - message: Error message
	 * - location: Error location ('storage')
	 *
	 * @param array $params Parameters for join data retrieval (reserved for future use)
	 * @return array Error array indicating join operations are not supported in 2025-10-20 version
	 */
	public function fetch_joined_data( $params ) {
		return array(
			'error_code' => 'E_JOIN_NOT_SUPPORTED',
			'message'    => __( 'Join operations are not supported in version 2025-10-20.', 'qa-heatmap-analytics' ),
			'location'   => 'storage',
		);
	}

	/**
	 * Filter columns from raw data records
	 *
	 * Extracts only the specified physical columns from raw data records.
	 * If no columns are specified, returns all records unchanged.
	 * Missing columns are set to null.
	 *
	 * @param array $raw_data Raw data records from storage
	 * @param array $physical_columns Physical column names to retrieve
	 * @return array Filtered data records
	 */
	private function filter_columns_from_records( $raw_data, $physical_columns ) {
		$filtered_data = array();
		foreach ( $raw_data as $record ) {
			if ( empty( $physical_columns ) ) {
				$filtered_data[] = $record;
			} else {
				$filtered_record = array();
				foreach ( $physical_columns as $column ) {
					if ( isset( $record[ $column ] ) ) {
						$filtered_record[ $column ] = $record[ $column ];
					} else {
						$filtered_record[ $column ] = null;
					}
				}
				$filtered_data[] = $filtered_record;
			}
		}
		return $filtered_data;
	}

	/**
	 * Fetch data for 'allpv' material
	 *
	 * Retrieves pageview data from view_pv files using QAHM_File_Functions::get_view_pv().
	 * Supports filtering by: utm_source, utm_medium, utm_campaign, device_type, country_code
	 *
	 * @param string $tracking_id Tracking ID
	 * @param array $time_range Time range configuration
	 * @param array $physical_columns Physical column names to retrieve
	 * @param array|null $filter_conditions Filter conditions (e.g., ['utm_medium' => ['facebook', 'twitter']])
	 * @param bool $count_only Whether to return only the count (enables optimization using summary_days_access)
	 * @return array Result with record_count and data, or error array
	 */
	private function fetch_allpv_data( $tracking_id, $time_range, $physical_columns, $filter_conditions = null, $count_only = false ) {
		global $qahm_file_functions;

		// COUNT query optimization: use summary_days_access instead of view_pv
		// This optimization is only applied when:
		// 1. count_only is true
		// 2. No filter conditions are specified (filter_conditions is null or empty)
		if ( $count_only && empty( $filter_conditions ) ) {
			return $this->fetch_allpv_count_from_summary( $tracking_id, $time_range );
		}

		if ( ! method_exists( $qahm_file_functions, 'get_view_pv' ) ) {
			return array(
				'error_code' => 'E_METHOD_NOT_AVAILABLE',
				'message'    => __( 'Required method get_view_pv is not available.', 'qa-heatmap-analytics' ),
				'location'   => 'storage',
			);
		}

		$start_datetime = $time_range['start'];
		$end_datetime   = $time_range['end'];

		$raw_data = $qahm_file_functions->get_view_pv( $tracking_id, $start_datetime, $end_datetime );

		if ( ! is_array( $raw_data ) ) {
			return array(
				'error_code' => 'E_DATA_SOURCE_NOT_FOUND',
				/* translators: %s: tracking ID */
				'message'    => sprintf( __( "Data source not found for tracking_id '%s'", 'qa-heatmap-analytics' ), $tracking_id ),
				'location'   => 'storage',
			);
		}

		// Apply filter conditions if provided
		if ( ! empty( $filter_conditions ) && is_array( $filter_conditions ) ) {
			$raw_data = $this->apply_allpv_filters( $raw_data, $filter_conditions );
		}

		$filtered_data = $this->filter_columns_from_records( $raw_data, $physical_columns );

		return array(
			'record_count' => $this->wrap_count( $filtered_data ),
			'data'         => $filtered_data,
		);
	}

	/**
	 * Fetch allpv count from summary_days_access
	 *
	 * Optimized method for COUNT queries on allpv material.
	 * Instead of loading all view_pv data and counting records,
	 * this method uses the pre-aggregated summary_days_access data
	 * which contains daily PV counts.
	 *
	 * This optimization significantly reduces memory usage and improves
	 * performance for simple count queries without filters.
	 *
	 * @param string $tracking_id Tracking ID
	 * @param array $time_range Time range configuration with 'start' and 'end'
	 * @return array Result with record_count=1 and data containing total count, or error array
	 */
	private function fetch_allpv_count_from_summary( $tracking_id, $time_range ) {
		global $qahm_file_functions;

		if ( ! method_exists( $qahm_file_functions, 'get_summary_days_access' ) ) {
			return array(
				'error_code' => 'E_METHOD_NOT_AVAILABLE',
				'message'    => __( 'Required method get_summary_days_access is not available.', 'qa-heatmap-analytics' ),
				'location'   => 'storage',
			);
		}

		$start_date = isset( $time_range['start'] ) && is_string( $time_range['start'] )
			? $this->wrap_substr( $time_range['start'], 0, 10 )
			: '';
		$end_date   = isset( $time_range['end'] ) && is_string( $time_range['end'] )
			? $this->wrap_substr( $time_range['end'], 0, 10 )
			: '';

		if ( $start_date === '' || $end_date === '' ) {
			return array(
				'error_code' => 'E_INVALID_TIME_RANGE',
				'message'    => __( 'Invalid time_range provided. Start and end must be valid date strings.', 'qa-heatmap-analytics' ),
				'location'   => 'storage',
			);
		}

		$summary_data = $qahm_file_functions->get_summary_days_access( $tracking_id, $start_date, $end_date );

		if ( ! is_array( $summary_data ) || empty( $summary_data ) ) {
			return array(
				'error_code' => 'E_DATA_SOURCE_NOT_FOUND',
				/* translators: %s: tracking ID */
				'message'    => sprintf( __( 'Summary data not found or empty for tracking_id: %s', 'qa-heatmap-analytics' ), $tracking_id ),
				'location'   => 'storage',
			);
		}

		$total_count = 0;
		foreach ( $summary_data as $day_data ) {
			$total_count += isset( $day_data['pv_count'] ) ? (int) $day_data['pv_count'] : 0;
		}

		return array(
			'record_count' => 1,
			'data'         => array( array( 'count' => $total_count ) ),
		);
	}

	/**
	 * Fetch data for 'gsc' material
	 *
	 * Retrieves Google Search Console data using QAHM_File_Functions::get_gsc_lp_query().
	 * Supports filtering by: search_type, keyword
	 *
	 * @param string $tracking_id Tracking ID
	 * @param array $time_range Time range configuration
	 * @param array $physical_columns Physical column names to retrieve
	 * @param array|null $filter_conditions Filter conditions (e.g., ['search_type' => ['web']])
	 * @return array Result with record_count and data, or error array
	 */
	private function fetch_gsc_data( $tracking_id, $time_range, $physical_columns, $filter_conditions = null ) {
		global $qahm_file_functions;

		if ( ! method_exists( $qahm_file_functions, 'get_gsc_lp_query' ) ) {
			return array(
				'error_code' => 'E_METHOD_NOT_AVAILABLE',
				'message'    => __( 'Required method get_gsc_lp_query is not available.', 'qa-heatmap-analytics' ),
				'location'   => 'storage',
			);
		}

		$start_date = isset( $time_range['start'] ) && is_string( $time_range['start'] )
			? $this->wrap_substr( $time_range['start'], 0, 10 )
			: '';
		$end_date   = isset( $time_range['end'] ) && is_string( $time_range['end'] )
			? $this->wrap_substr( $time_range['end'], 0, 10 )
			: '';

		if ( $start_date === '' || $end_date === '' ) {
			return array(
				'error_code' => 'E_INVALID_TIME_RANGE',
				'message'    => __( 'Invalid time_range provided. Start and end must be valid date strings.', 'qa-heatmap-analytics' ),
				'location'   => 'storage',
			);
		}

		$raw_data = $qahm_file_functions->get_gsc_lp_query( $tracking_id, $start_date, $end_date );

		if ( ! is_array( $raw_data ) ) {
			return array(
				'error_code' => 'E_DATA_SOURCE_NOT_FOUND',
				/* translators: %s: tracking ID */
				'message'    => sprintf( __( "GSC data source not found for tracking_id '%s'", 'qa-heatmap-analytics' ), $tracking_id ),
				'location'   => 'storage',
			);
		}

		// Apply filter conditions if provided
		if ( ! empty( $filter_conditions ) && is_array( $filter_conditions ) ) {
			$raw_data = $this->apply_gsc_filters( $raw_data, $filter_conditions );
		}

		$filtered_data = $this->filter_columns_from_records( $raw_data, $physical_columns );

		return array(
			'record_count' => $this->wrap_count( $filtered_data ),
			'data'         => $filtered_data,
		);
	}

	/**
	 * Fetch data for 'goal_x' material
	 *
	 * Retrieves goal achievement session data using QAHM_File_Functions::get_goal_data_by_number().
	 * The goal data is structured as sessions containing multiple PV records.
	 * This method flattens the session structure to return individual PV records.
	 * Supports filtering by: utm_source, utm_medium, utm_campaign, device_id, is_reject
	 *
	 * @param string $tracking_id Tracking ID
	 * @param array $time_range Time range configuration
	 * @param array $physical_columns Physical column names to retrieve
	 * @param int $goal_number Goal number to retrieve data for
	 * @param array|null $filter_conditions Filter conditions (e.g., ['is_reject' => [false]])
	 * @return array Result with record_count and data, or error array
	 */
	private function fetch_goal_data( $tracking_id, $time_range, $physical_columns, $goal_number, $filter_conditions = null ) {
		global $qahm_file_functions;

		if ( ! method_exists( $qahm_file_functions, 'get_goal_data_by_number' ) ) {
			return array(
				'error_code' => 'E_METHOD_NOT_AVAILABLE',
				'message'    => __( 'Required method get_goal_data_by_number is not available.', 'qa-heatmap-analytics' ),
				'location'   => 'storage',
			);
		}

		$start_date = isset( $time_range['start'] ) && is_string( $time_range['start'] )
			? $this->wrap_substr( $time_range['start'], 0, 10 )
			: '';
		$end_date   = isset( $time_range['end'] ) && is_string( $time_range['end'] )
			? $this->wrap_substr( $time_range['end'], 0, 10 )
			: '';

		if ( $start_date === '' || $end_date === '' ) {
			return array(
				'error_code' => 'E_INVALID_TIME_RANGE',
				'message'    => __( 'Invalid time_range provided. Start and end must be valid date strings.', 'qa-heatmap-analytics' ),
				'location'   => 'storage',
			);
		}

		// get_goal_data_by_number() always returns an array (empty array when no data found)
		$raw_data = $qahm_file_functions->get_goal_data_by_number( $tracking_id, $goal_number, $start_date, $end_date );

		// Flatten session data structure to individual PV records
		$flattened_data = array();
		foreach ( $raw_data as $session_index => $session_data ) {
			if ( is_array( $session_data ) ) {
				foreach ( $session_data as $pv_index => $pv_data ) {
					if ( is_array( $pv_data ) ) {
						// Add session and pv index for reference
						$pv_data['session_index'] = $session_index;
						$pv_data['pv_index']      = $pv_index;
						$flattened_data[]         = $pv_data;
					}
				}
			}
		}

		// Apply filter conditions if provided
		if ( ! empty( $filter_conditions ) && is_array( $filter_conditions ) ) {
			$flattened_data = $this->apply_goal_filters( $flattened_data, $filter_conditions );
		}

		$filtered_data = $this->filter_columns_from_records( $flattened_data, $physical_columns );

		return array(
			'record_count' => $this->wrap_count( $filtered_data ),
			'data'         => $filtered_data,
		);
	}

	/**
	 * Apply filter conditions to allpv data
	 *
	 * Filters records based on specified conditions.
	 * Supported filter fields: utm_source, utm_medium, utm_campaign, device_type, country_code, url
	 *
	 * Supports two filter formats:
	 * - Indexed array (existing): ['utm_medium' => ['facebook', 'twitter']] — IN clause
	 * - Operator object (new):    ['url' => ['prefix' => 'https://...']]   — operator-based
	 *
	 * @param array $data Raw data records
	 * @param array $filter_conditions Filter conditions
	 * @return array Filtered data records
	 */
	private function apply_allpv_filters( $data, $filter_conditions ) {
		$filtered_data = array();

		foreach ( $data as $record ) {
			$matches = true;

			// utm_medium filter (indexed array format)
			if ( isset( $filter_conditions['utm_medium'] ) && ! empty( $filter_conditions['utm_medium'] ) && ! $this->is_operator_filter( $filter_conditions['utm_medium'] ) ) {
				$utm_medium = isset( $record['utm_medium'] ) ? $record['utm_medium'] : '(not set)';
				if ( ! $this->wrap_in_array( $utm_medium, $filter_conditions['utm_medium'] ) ) {
					$matches = false;
				}
			}

			// utm_source filter (indexed array format)
			if ( $matches && isset( $filter_conditions['utm_source'] ) && ! empty( $filter_conditions['utm_source'] ) && ! $this->is_operator_filter( $filter_conditions['utm_source'] ) ) {
				$utm_source    = isset( $record['utm_source'] ) ? $record['utm_source'] : null;
				$source_domain = isset( $record['source_domain'] ) ? $record['source_domain'] : '(not set)';
				// Use utm_source if available, otherwise use source_domain
				$source = ! empty( $utm_source ) ? $utm_source : $source_domain;
				if ( ! $this->wrap_in_array( $source, $filter_conditions['utm_source'] ) ) {
					$matches = false;
				}
			}

			// utm_campaign filter (indexed array format)
			if ( $matches && isset( $filter_conditions['utm_campaign'] ) && ! empty( $filter_conditions['utm_campaign'] ) && ! $this->is_operator_filter( $filter_conditions['utm_campaign'] ) ) {
				$utm_campaign = isset( $record['utm_campaign'] ) ? $record['utm_campaign'] : '(not set)';
				if ( ! $this->wrap_in_array( $utm_campaign, $filter_conditions['utm_campaign'] ) ) {
					$matches = false;
				}
			}

			// device_type filter (indexed array format)
			if ( $matches && isset( $filter_conditions['device_type'] ) && ! empty( $filter_conditions['device_type'] ) && ! $this->is_operator_filter( $filter_conditions['device_type'] ) ) {
				$device_type = isset( $record['device_type'] ) ? $record['device_type'] : null;
				if ( ! $this->wrap_in_array( $device_type, $filter_conditions['device_type'] ) ) {
					$matches = false;
				}
			}

			// country_code filter (indexed array format)
			if ( $matches && isset( $filter_conditions['country_code'] ) && ! empty( $filter_conditions['country_code'] ) && ! $this->is_operator_filter( $filter_conditions['country_code'] ) ) {
				$country_code = isset( $record['country_code'] ) ? $record['country_code'] : '(not set)';
				if ( ! $this->wrap_in_array( $country_code, $filter_conditions['country_code'] ) ) {
					$matches = false;
				}
			}

			// Generic operator filter handler (supports url, and operator format on any field).
			// Also handles operator format on existing fields (e.g., utm_medium with {"eq": "social"})
			// since the indexed array checks above skip operator format via is_operator_filter() guard.
			if ( $matches ) {
				foreach ( $filter_conditions as $field => $condition ) {
					if ( ! $matches ) {
						break;
					}
					if ( ! is_array( $condition ) || ! $this->is_operator_filter( $condition ) ) {
						continue;
					}
					$value = isset( $record[ $field ] ) ? $record[ $field ] : null;
					if ( ! $this->match_operator_filter( $value, $condition ) ) {
						$matches = false;
					}
				}
			}

			if ( $matches ) {
				$filtered_data[] = $record;
			}
		}

		return $filtered_data;
	}

	/**
	 * Check if a filter condition is in operator object format
	 *
	 * Operator format: associative array with operator keys (e.g., ['eq' => 'value', 'prefix' => 'value'])
	 * Indexed array format: sequential array (e.g., ['organic', 'facebook'])
	 *
	 * @param array $condition Filter condition to check
	 * @return bool True if operator format
	 */
	private function is_operator_filter( $condition ) {
		if ( ! is_array( $condition ) ) {
			return false;
		}
		$operators = array( 'eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'contains', 'prefix', 'between' );
		foreach ( array_keys( $condition ) as $key ) {
			if ( in_array( $key, $operators, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Match a value against operator filter conditions
	 *
	 * @param mixed $value Record field value
	 * @param array $condition Operator conditions (e.g., ['prefix' => 'https://...'])
	 * @return bool True if value matches all conditions
	 */
	private function match_operator_filter( $value, $condition ) {
		foreach ( $condition as $operator => $target ) {
			switch ( $operator ) {
				case 'eq':
					if ( $value !== $target ) {
						return false;
					}
					break;
				case 'neq':
					if ( $value === $target ) {
						return false;
					}
					break;
				case 'prefix':
					if ( null === $value || 0 !== strpos( $value, $target ) ) {
						return false;
					}
					break;
				case 'contains':
					if ( null === $value || false === strpos( $value, $target ) ) {
						return false;
					}
					break;
				case 'gt':
					if ( null === $value || $value <= $target ) {
						return false;
					}
					break;
				case 'gte':
					if ( null === $value || $value < $target ) {
						return false;
					}
					break;
				case 'lt':
					if ( null === $value || $value >= $target ) {
						return false;
					}
					break;
				case 'lte':
					if ( null === $value || $value > $target ) {
						return false;
					}
					break;
				case 'in':
					if ( ! is_array( $target ) || ! $this->wrap_in_array( $value, $target ) ) {
						return false;
					}
					break;
				case 'between':
					if ( ! is_array( $target ) || count( $target ) < 2 ) {
						return false;
					}
					if ( null === $value || $value < $target[0] || $value > $target[1] ) {
						return false;
					}
					break;
			}
		}
		return true;
	}

	/**
	 * Apply filter conditions to gsc data
	 *
	 * Filters records based on specified conditions.
	 * Supported filter fields: search_type, keyword
	 *
	 * @param array $data Raw data records
	 * @param array $filter_conditions Filter conditions (e.g., ['search_type' => ['web']])
	 * @return array Filtered data records
	 */
	private function apply_gsc_filters( $data, $filter_conditions ) {
		$filtered_data = array();

		foreach ( $data as $record ) {
			$matches = true;

			// search_type filter
			if ( isset( $filter_conditions['search_type'] ) && ! empty( $filter_conditions['search_type'] ) ) {
				$search_type = isset( $record['search_type'] ) ? $record['search_type'] : '(not set)';
				if ( ! $this->wrap_in_array( $search_type, $filter_conditions['search_type'] ) ) {
					$matches = false;
				}
			}

			// keyword filter
			if ( $matches && isset( $filter_conditions['keyword'] ) && ! empty( $filter_conditions['keyword'] ) ) {
				$keyword = isset( $record['keyword'] ) ? $record['keyword'] : '(not set)';
				if ( ! $this->wrap_in_array( $keyword, $filter_conditions['keyword'] ) ) {
					$matches = false;
				}
			}

			if ( $matches ) {
				$filtered_data[] = $record;
			}
		}

		return $filtered_data;
	}

	/**
	 * Normalize boolean values for consistent comparison
	 *
	 * Converts boolean, integer, and string representations to a consistent integer format:
	 * - true, 1, "1", "true" -> 1
	 * - false, 0, "0", "false" -> 0
	 * - null -> null
	 *
	 * @param mixed $value Value to normalize
	 * @return int|null Normalized value (1, 0, or null)
	 */
	private function normalize_boolean_value( $value ) {
		if ( $value === null ) {
			return null;
		}
		if ( is_bool( $value ) ) {
			return $value ? 1 : 0;
		}
		if ( is_int( $value ) ) {
			return $value ? 1 : 0;
		}
		if ( is_string( $value ) ) {
			$lower = strtolower( $value );
			if ( $lower === 'true' || $lower === '1' ) {
				return 1;
			}
			if ( $lower === 'false' || $lower === '0' ) {
				return 0;
			}
		}
		return $value ? 1 : 0;
	}

	/**
	 * Apply filter conditions to goal data
	 *
	 * Filters records based on specified conditions.
	 * Supported filter fields: utm_source, utm_medium, utm_campaign, device_id, is_reject
	 *
	 * @param array $data Raw data records
	 * @param array $filter_conditions Filter conditions (e.g., ['is_reject' => [false]])
	 * @return array Filtered data records
	 */
	private function apply_goal_filters( $data, $filter_conditions ) {
		$filtered_data = array();

		foreach ( $data as $record ) {
			$matches = true;

			// utm_medium filter
			if ( isset( $filter_conditions['utm_medium'] ) && ! empty( $filter_conditions['utm_medium'] ) ) {
				$utm_medium = isset( $record['utm_medium'] ) ? $record['utm_medium'] : '(not set)';
				if ( ! $this->wrap_in_array( $utm_medium, $filter_conditions['utm_medium'] ) ) {
					$matches = false;
				}
			}

			// utm_source filter
			if ( $matches && isset( $filter_conditions['utm_source'] ) && ! empty( $filter_conditions['utm_source'] ) ) {
				$utm_source    = isset( $record['utm_source'] ) ? $record['utm_source'] : null;
				$source_domain = isset( $record['source_domain'] ) ? $record['source_domain'] : '(not set)';
				// Use utm_source if available, otherwise use source_domain
				$source = ! empty( $utm_source ) ? $utm_source : $source_domain;
				if ( ! $this->wrap_in_array( $source, $filter_conditions['utm_source'] ) ) {
					$matches = false;
				}
			}

			// utm_campaign filter
			if ( $matches && isset( $filter_conditions['utm_campaign'] ) && ! empty( $filter_conditions['utm_campaign'] ) ) {
				$utm_campaign = isset( $record['utm_campaign'] ) ? $record['utm_campaign'] : '(not set)';
				if ( ! $this->wrap_in_array( $utm_campaign, $filter_conditions['utm_campaign'] ) ) {
					$matches = false;
				}
			}

			// device_id filter
			if ( $matches && isset( $filter_conditions['device_id'] ) && ! empty( $filter_conditions['device_id'] ) ) {
				$device_id = isset( $record['device_id'] ) ? $record['device_id'] : null;
				if ( ! $this->wrap_in_array( $device_id, $filter_conditions['device_id'] ) ) {
					$matches = false;
				}
			}

			// is_reject filter (with type normalization for boolean/string compatibility)
			if ( $matches && isset( $filter_conditions['is_reject'] ) ) {
				$is_reject                = isset( $record['is_reject'] ) ? $record['is_reject'] : null;
				$is_reject_normalized     = $this->normalize_boolean_value( $is_reject );
				$filter_values_normalized = array();
				if ( is_array( $filter_conditions['is_reject'] ) ) {
					foreach ( $filter_conditions['is_reject'] as $filter_value ) {
						$filter_values_normalized[] = $this->normalize_boolean_value( $filter_value );
					}
				}
				if ( ! $this->wrap_in_array( $is_reject_normalized, $filter_values_normalized, true ) ) {
					$matches = false;
				}
			}

			if ( $matches ) {
				$filtered_data[] = $record;
			}
		}

		return $filtered_data;
	}
}

$GLOBALS['qahm_qal_storage'] = new QAHM_Qal_Storage();
