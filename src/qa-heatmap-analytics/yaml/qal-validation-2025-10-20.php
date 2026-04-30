<?php
/**
 * QAL Validation Manifest
 * 
 * This file contains validation rules for QAL JSON execution requests.
 * Converted from YAML to PHP array for better compatibility.
 * 
 * @package qa-heatmap-analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id' => 'qal-validation-2025-10-20',
	'title' => 'QAL Validation Manifest (for Executor and AI)',
	'version' => '2025-10-20',
	'update' => '2025-10-20',
	'type' => 'validation_manifest',
	'description' => 'Defines strict validation rules for QAL JSON execution requests. Used for validating QAL structures before execution.',
	
	'structure' => array(
		'required' => array( 'tracking_id', 'materials', 'time', 'make', 'result' ),
	),
	
	'rules' => array(
		'tracking_id' => array(
			'type' => 'string',
			'description' => 'Unique identifier for the tracking site to query. Must match a tracking_id from the /guide endpoint response.',
			'pattern' => '^[a-zA-Z0-9_-]+$',
			'errors' => array(
				array(
					'code' => 'E_UNKNOWN_TRACKING_ID',
					'message' => 'Invalid tracking_id provided.',
				),
			),
		),
		
		'materials' => array(
			'type' => 'array',
			'description' => 'List of data sources (materials) to use in the query. Each material must have a \'name\' property.',
			'items' => array(
				'type' => 'object',
				'required' => array( 'name' ),
				'properties' => array(
					'name' => array(
						'type' => 'string',
						'description' => 'Material name. Allowed values: \'allpv\' (page view data), \'gsc\' (Google Search Console data), or \'goal_x\' where x is the goal number (e.g., \'goal_1\', \'goal_2\').',
						'pattern' => '^(allpv|gsc|goal_[1-9]\\d*)$',
					),
				),
				'additionalProperties' => false,
			),
			'minItems' => 1,
			'errors' => array(
				array(
					'code' => 'E_UNKNOWN_MATERIAL',
					'message' => 'Material name not found in manifest.',
				),
			),
		),
		
		'time' => array(
			'type' => 'object',
			'required' => array( 'start', 'end', 'tz' ),
			'properties' => array(
				'start' => array( 'type' => 'string', 'format' => 'date-time' ),
				'end' => array( 'type' => 'string', 'format' => 'date-time' ),
				'tz' => array(
					'type' => 'string',
					'description' => 'IANA timezone identifier (e.g., Asia/Tokyo, UTC, America/New_York). Any valid IANA timezone is accepted.',
					'examples' => array( 'Asia/Tokyo', 'UTC', 'Europe/London', 'America/New_York', 'America/Los_Angeles', 'Europe/Paris' ),
				),
			),
			'errors' => array(
				array(
					'code' => 'E_TIME_REQUIRED',
					'message' => 'Missing time.start, time.end, or time.tz.',
				),
			),
		),
		
		'make' => array(
			'type' => 'object',
			'description' => 'Defines views (data transformations) to create from materials. Each key is a view name, and the value defines the view\'s structure.',
			'patternProperties' => array(
				'^[a-zA-Z0-9_]+$' => array(
					'type' => 'object',
					'description' => 'View definition. Must specify \'from\' (source material) and \'keep\' (columns to select). Optionally specify \'filter\' for filtering data.',
					'required' => array( 'from', 'keep' ),
					'properties' => array(
						'from' => array(
							'type' => 'array',
							'description' => 'Specify which material to use. Must contain exactly one material name.',
							'items' => array( 'type' => 'string', 'pattern' => '^(allpv|gsc|goal_[1-9]\\d*)$' ),
							'minItems' => 1,
							'maxItems' => 1,
						),
						'keep' => array(
							'type' => 'array',
							'description' => 'List of columns to include in the result. Must use fully qualified names in the format \'material.column_name\' (e.g., \'allpv.url\', \'gsc.keyword\', \'goal_1.pv_id\').',
							'items' => array(
								'type' => 'string',
								'pattern' => '^(allpv|gsc|goal_[1-9]\\d*)\\.[a-zA-Z0-9_]+$',
							),
							'minItems' => 1,
						),
						'filter' => array(
								'type' => 'object',
								'description' => 'Filter conditions to apply. Supports indexed array format (IN clause: ["value1", "value2"]) and operator object format ({"eq": "value", "prefix": "value"}). Supported fields depend on material type: allpv (utm_source, utm_medium, utm_campaign, device_type, country_code, url), gsc (search_type, keyword), goal_x (utm_source, utm_medium, utm_campaign, device_id, is_reject). Operators: eq, neq, gt, gte, lt, lte, in, contains, prefix, between.',
								'additionalProperties' => array(
									'type' => array( 'array', 'object' ),
								),
							),
					),
					'additionalProperties' => false,
				),
			),
			'errors' => array(
				array(
					'code' => 'E_UNKNOWN_COLUMN',
					'message' => 'Invalid column name in keep list.',
				),
			),
		),
		
		'result' => array(
			'type' => 'object',
			'description' => 'Specifies which view to return and how to format the result.',
			'required' => array( 'use' ),
			'properties' => array(
				'use' => array(
					'type' => 'string',
					'description' => 'Name of the view (defined in \'make\') to return as the result.',
				),
				'limit' => array(
					'type' => 'integer',
					'description' => 'Maximum number of rows to return. Default: 1000, Maximum: 50000.',
					'minimum' => 1,
					'maximum' => 50000,
					'default' => 1000,
				),
				'count_only' => array(
					'type' => 'boolean',
					'description' => 'If true, return only the count of rows instead of the actual data. Default: false.',
					'default' => false,
				),
			),
			'additionalProperties' => false,
			'errors' => array(
				array(
					'code' => 'E_UNKNOWN_VIEW',
					'message' => 'Result.use does not match any defined view in make.',
				),
			),
		),
	),
	
	'features' => array(
		'filter' => true,
		'join' => false,
		'calc' => false,
		'sort' => false,
	),
);
