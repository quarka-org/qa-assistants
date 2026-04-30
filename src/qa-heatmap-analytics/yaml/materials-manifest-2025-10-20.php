<?php
/**
 * Materials Manifest
 * 
 * This file contains material definitions for QAL execution.
 * Converted from YAML to PHP array for better compatibility.
 * 
 * @package qa-heatmap-analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'id' => 'materials-manifest',
	'Plugin version' => array(
		'QA ZERO' => '3.0.0.0',
		'QA Assistants' => '5.0.0.0',
	),
	'type' => 'material_definition',
	
	'materials' => array(
		'allpv' => array(
			'decoders' => array(
				array(
					'loader' => 'file_functions.view_pv',
					'decoder' => null,
					'fields' => array(
						array( 'material_column' => 'pv_id', 'physical_column' => 'pv_id' ),
						array( 'material_column' => 'reader_id', 'physical_column' => 'reader_id' ),
						array( 'material_column' => 'page_id', 'physical_column' => 'page_id' ),
						array( 'material_column' => 'url', 'physical_column' => 'url' ),
						array( 'material_column' => 'title', 'physical_column' => 'title' ),
						array( 'material_column' => 'source_domain', 'physical_column' => 'source_domain' ),
						array( 'material_column' => 'referrer', 'physical_column' => 'referrer' ),
						array( 'material_column' => 'utm_source', 'physical_column' => 'utm_source' ),
						array( 'material_column' => 'utm_medium', 'physical_column' => 'utm_medium' ),
						array( 'material_column' => 'utm_campaign', 'physical_column' => 'utm_campaign' ),
						array( 'material_column' => 'ua', 'physical_column' => 'ua' ),
						array( 'material_column' => 'device_type', 'physical_column' => 'device_type' ),
						array( 'material_column' => 'os', 'physical_column' => 'os' ),
						array( 'material_column' => 'browser', 'physical_column' => 'browser' ),
						array( 'material_column' => 'language', 'physical_column' => 'language' ),
						array( 'material_column' => 'country_code', 'physical_column' => 'country_code' ),
						array( 'material_column' => 'access_time', 'physical_column' => 'access_time' ),
						array( 'material_column' => 'pv', 'physical_column' => 'pv' ),
						array( 'material_column' => 'speed_msec', 'physical_column' => 'speed_msec' ),
						array( 'material_column' => 'browse_sec', 'physical_column' => 'browse_sec' ),
						array( 'material_column' => 'is_last', 'physical_column' => 'is_last' ),
						array( 'material_column' => 'is_newuser', 'physical_column' => 'is_newuser' ),
					),
				),
			),
		),
		
		'gsc' => array(
			'decoders' => array(
				array(
					'loader' => 'file_functions.get_gsc_lp_query',
					'decoder' => null,
					'fields' => array(
						array( 'material_column' => 'page_id', 'physical_column' => 'page_id' ),
						array( 'material_column' => 'title', 'physical_column' => 'title' ),
						array( 'material_column' => 'url', 'physical_column' => 'url' ),
						array( 'material_column' => 'search_type', 'physical_column' => 'search_type' ),
						array( 'material_column' => 'keyword', 'physical_column' => 'keyword' ),
						array( 'material_column' => 'clicks_sum', 'physical_column' => 'clicks_sum' ),
						array( 'material_column' => 'impressions_sum', 'physical_column' => 'impressions_sum' ),
						array( 'material_column' => 'ctr', 'physical_column' => 'ctr' ),
						array( 'material_column' => 'position_wavg', 'physical_column' => 'position_wavg' ),
						array( 'material_column' => 'first_position', 'physical_column' => 'first_position' ),
						array( 'material_column' => 'latest_position', 'physical_column' => 'latest_position' ),
						array( 'material_column' => 'position_history', 'physical_column' => 'position_history' ),
					),
				),
			),
		),

		'goal_x' => array(
			'decoders' => array(
				array(
					'loader' => 'file_functions.get_goal_data_by_number',
					'decoder' => null,
					'fields' => array(
						array( 'material_column' => 'session_index', 'physical_column' => 'session_index' ),
						array( 'material_column' => 'pv_index', 'physical_column' => 'pv_index' ),
						array( 'material_column' => 'pv_id', 'physical_column' => 'pv_id' ),
						array( 'material_column' => 'reader_id', 'physical_column' => 'reader_id' ),
						array( 'material_column' => 'UAos', 'physical_column' => 'UAos' ),
						array( 'material_column' => 'UAbrowser', 'physical_column' => 'UAbrowser' ),
						array( 'material_column' => 'language', 'physical_column' => 'language' ),
						array( 'material_column' => 'is_reject', 'physical_column' => 'is_reject' ),
						array( 'material_column' => 'page_id', 'physical_column' => 'page_id' ),
						array( 'material_column' => 'url', 'physical_column' => 'url' ),
						array( 'material_column' => 'title', 'physical_column' => 'title' ),
						array( 'material_column' => 'access_time', 'physical_column' => 'access_time' ),
						array( 'material_column' => 'device_id', 'physical_column' => 'device_id' ),
						array( 'material_column' => 'version_id', 'physical_column' => 'version_id' ),
						array( 'material_column' => 'source_id', 'physical_column' => 'source_id' ),
						array( 'material_column' => 'utm_source', 'physical_column' => 'utm_source' ),
						array( 'material_column' => 'source_domain', 'physical_column' => 'source_domain' ),
						array( 'material_column' => 'medium_id', 'physical_column' => 'medium_id' ),
						array( 'material_column' => 'utm_medium', 'physical_column' => 'utm_medium' ),
						array( 'material_column' => 'campaign_id', 'physical_column' => 'campaign_id' ),
						array( 'material_column' => 'utm_campaign', 'physical_column' => 'utm_campaign' ),
						array( 'material_column' => 'session_no', 'physical_column' => 'session_no' ),
						array( 'material_column' => 'pv', 'physical_column' => 'pv' ),
						array( 'material_column' => 'speed_msec', 'physical_column' => 'speed_msec' ),
						array( 'material_column' => 'browse_sec', 'physical_column' => 'browse_sec' ),
						array( 'material_column' => 'is_last', 'physical_column' => 'is_last' ),
						array( 'material_column' => 'is_newuser', 'physical_column' => 'is_newuser' ),
						array( 'material_column' => 'is_raw_p', 'physical_column' => 'is_raw_p' ),
						array( 'material_column' => 'is_raw_c', 'physical_column' => 'is_raw_c' ),
						array( 'material_column' => 'is_raw_e', 'physical_column' => 'is_raw_e' ),
						array( 'material_column' => 'version_no', 'physical_column' => 'version_no' ),
					),
				),
			),
		),
	),
);
