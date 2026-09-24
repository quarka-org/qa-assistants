<?php
/**
 * QAL WP Material Class - WordPress-backed materials for QAL (wp_posts etc.)
 *
 * Provides fetch logic for WP DB-backed materials. Currently supports wp_posts.
 * Loaded only in QA Assistants environment (QAHM_TYPE === QAHM_TYPE_WP) via
 * qahm-loader.php. In QA ZERO environment, this class is not present and
 * QAHM_Qal_Material::qal_filter_apply() returns E_UNKNOWN_MATERIAL.
 *
 * Design spec: docs/specs/assistant/data-sources.md (and related)
 *
 * @package qa_heatmap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QAHM_Qal_Wp_Material extends QAHM_File_Base {

	/**
	 * Default post types for wp_posts when filter.post_type is not specified.
	 * Excludes attachment / revision / nav_menu_item / etc. to prevent
	 * unintended results in LP analysis use cases.
	 */
	const DEFAULT_POST_TYPES = array( 'post', 'page' );

	/** Default post status when filter.post_status is not specified. */
	const DEFAULT_POST_STATUS = 'publish';

	/** Default page size when result.limit is not specified. */
	const DEFAULT_LIMIT = 100;

	/** Maximum page size for wp_posts (hard cap regardless of result.limit). */
	const MAX_LIMIT = 1000;

	/**
	 * Allowed material_column names for wp_posts.
	 *
	 * Phase 1.6b cleanup (#1197): fetch_wp_posts() validates `keep` columns
	 * against this list and returns E_UNKNOWN_COLUMN for unknown ones,
	 * matching the behavior of other materials.
	 */
	const ALLOWED_COLUMNS = array(
		'id',
		'title',
		'url',
		'excerpt',
		'content',
		'post_type',
		'post_status',
		'published_at',
	);

	public function __construct() {}

	/**
	 * Fetch entry point - routes to material-specific fetch method.
	 *
	 * @param string $material_name      e.g. 'wp_posts'
	 * @param string $tracking_id        Ignored for wp_posts (single-site context)
	 * @param array  $time_range         { start, end, tz }
	 * @param array  $filter_conditions  Filter conditions from QAL view (plain column names as keys)
	 * @param array  $keep_columns       Qualified column names to return (e.g. ['wp_posts.title', ...])
	 * @param int    $result_limit       From $executable_qal['qal']['result']['limit'] (0 = use default)
	 * @param bool   $count_only         From $executable_qal['qal']['result']['count_only']
	 * @return array { view_name, material_name, record_count, data } | error
	 */
	public function fetch( $material_name, $tracking_id, $time_range, $filter_conditions, $keep_columns, $result_limit = 0, $count_only = false ) {
		switch ( $material_name ) {
			case 'wp_posts':
				return $this->fetch_wp_posts( $time_range, $filter_conditions, $keep_columns, $result_limit, $count_only );
			default:
				return array(
					'error_code' => 'E_UNKNOWN_MATERIAL',
					/* translators: %s: material name */
					'message'    => sprintf( __( "Material '%s' is not supported by WP Material handler.", 'qa-heatmap-analytics' ), $material_name ),
					'location'   => 'make.*.from[0]',
				);
		}
	}

	/**
	 * Fetch wp_posts via WP_Query.
	 *
	 * @param array $time_range  { start, end, tz }
	 * @param array $filter      Plain column names as keys (e.g. ['url' => ['eq' => '...'], 'post_type' => ['eq' => 'page']])
	 * @param array $keep        Qualified column names (e.g. ['wp_posts.title', ...])
	 * @param int   $result_limit From QAL result.limit (0 = use default)
	 * @param bool  $count_only  From QAL result.count_only
	 * @return array
	 */
	private function fetch_wp_posts( $time_range, $filter, $keep, $result_limit, $count_only ) {
		// Phase 1.6b cleanup (#1197): validate keep columns up-front.
		// Returns E_UNKNOWN_COLUMN for any wp_posts column not in ALLOWED_COLUMNS,
		// matching the behavior of other materials. Columns from other materials
		// (parse_qualified_column returns null) are intentionally skipped here —
		// they would only appear in join scenarios which are blocked by the
		// E_INVALID_JOIN guard in QAHM_Qal_Material::qal_filter_apply().
		foreach ( $keep as $qualified_col ) {
			$col = $this->parse_qualified_column( $qualified_col, 'wp_posts' );
			if ( null === $col ) {
				continue;
			}
			if ( ! in_array( $col, self::ALLOWED_COLUMNS, true ) ) {
				return array(
					'error_code' => 'E_UNKNOWN_COLUMN',
					'message'    => sprintf(
						/* translators: 1: column name, 2: comma-separated allowed columns */
						__( "Column 'wp_posts.%1\$s' is not defined for wp_posts. Allowed: %2\$s", 'qa-heatmap-analytics' ),
						$col,
						implode( ', ', self::ALLOWED_COLUMNS )
					),
					'location'   => 'make.*.keep',
				);
			}
		}

		$limit = $result_limit > 0
			? min( (int) $result_limit, self::MAX_LIMIT )
			: self::DEFAULT_LIMIT;

		$post_type   = isset( $filter['post_type']['eq'] ) ? $filter['post_type']['eq'] : self::DEFAULT_POST_TYPES;
		$post_status = isset( $filter['post_status']['eq'] ) ? $filter['post_status']['eq'] : self::DEFAULT_POST_STATUS;

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => $post_status,
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => ! $count_only,
			'date_query'     => array(
				array(
					'after'  => isset( $time_range['start'] ) ? $time_range['start'] : '',
					'before' => isset( $time_range['end'] ) ? $time_range['end'] : '',
					'column' => 'post_date',
				),
			),
		);

		// id filter (direct lookup via WP_Query 'p' arg)
		if ( isset( $filter['id']['eq'] ) ) {
			$args['p'] = (int) $filter['id']['eq'];
		}

		// count_only mode: fetch only IDs for efficiency
		if ( $count_only ) {
			$args['fields'] = 'ids';
		}

		$query = new WP_Query( $args );

		if ( $count_only ) {
			return array(
				'view_name'     => '',
				'material_name' => 'wp_posts',
				'record_count'  => (int) $query->found_posts,
				'data'          => array(),
			);
		}

		$posts = $query->posts;

		// url filter (post-query URL normalization compare)
		if ( isset( $filter['url']['eq'] ) ) {
			$target_url = $this->normalize_url_for_compare( $filter['url']['eq'] );
			$filtered = array();
			foreach ( $posts as $post ) {
				if ( $this->normalize_url_for_compare( get_permalink( $post ) ) === $target_url ) {
					$filtered[] = $post;
				}
			}
			$posts = $filtered;
		}

		// Build result rows according to keep columns
		$rows = array();
		foreach ( $posts as $post ) {
			$row = array();
			foreach ( $keep as $qualified_col ) {
				$col = $this->parse_qualified_column( $qualified_col, 'wp_posts' );
				if ( null !== $col ) {
					$row[ $col ] = $this->map_wp_post_field( $post, $col );
				}
			}
			$rows[] = $row;
		}

		return array(
			'view_name'     => '',
			'material_name' => 'wp_posts',
			'record_count'  => count( $rows ),
			'data'          => $rows,
		);
	}

	/**
	 * Map a WP_Post object to a wp_posts material column value.
	 *
	 * Note: 'content' is returned as raw HTML (no apply_filters('the_content')).
	 *
	 * @param WP_Post $post
	 * @param string  $material_column
	 * @return mixed
	 */
	private function map_wp_post_field( $post, $material_column ) {
		switch ( $material_column ) {
			case 'id':           return (int) $post->ID;
			case 'title':        return $post->post_title;
			case 'url':          return get_permalink( $post );
			case 'excerpt':      return $post->post_excerpt;
			case 'content':      return $post->post_content;
			case 'post_type':    return $post->post_type;
			case 'post_status':  return $post->post_status;
			case 'published_at': return $post->post_date;
			default:             return null;
		}
	}

	/**
	 * Normalize a URL for comparison.
	 *
	 * - Scheme: forced to https (for permalink consistency)
	 * - Trailing slash: added
	 * - Host: lowercased
	 *
	 * Use this when comparing user-supplied URL (filter.url) against
	 * get_permalink() result to avoid spurious mismatches.
	 *
	 * @param string $url
	 * @return string Normalized URL or empty string on invalid input
	 */
	private function normalize_url_for_compare( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}
		$url = set_url_scheme( $url, 'https' );
		$url = trailingslashit( $url );
		$parsed = wp_parse_url( $url );
		if ( isset( $parsed['host'] ) ) {
			$url = str_replace( $parsed['host'], strtolower( $parsed['host'] ), $url );
		}
		return $url;
	}

	/**
	 * Parse a qualified column name (e.g. "wp_posts.title") into plain ("title").
	 *
	 * @param string $qualified
	 * @param string $material_name
	 * @return string|null Plain column name on match, null otherwise
	 */
	private function parse_qualified_column( $qualified, $material_name ) {
		if ( ! is_string( $qualified ) ) {
			return null;
		}
		$prefix = $material_name . '.';
		if ( 0 === strpos( $qualified, $prefix ) ) {
			return substr( $qualified, strlen( $prefix ) );
		}
		return null;
	}
}

$GLOBALS['qahm_qal_wp_material'] = new QAHM_Qal_Wp_Material();
