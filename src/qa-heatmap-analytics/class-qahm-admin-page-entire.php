<?php
defined( 'ABSPATH' ) || exit;
/**
 *
 *
 * @package qa_heatmap
 */

$GLOBALS['qahm_admin_page_entire'] = new QAHM_Admin_Page_Entire();

class QAHM_Admin_Page_Entire extends QAHM_Admin_Page_Base {

	// スラッグ
	const SLUG = QAHM_NAME . '-entire';

	// nonce
	const NONCE_ACTION = self::SLUG . '-nonce-action';
	const NONCE_NAME   = self::SLUG . '-nonce-name';

	/**
	 * コンストラクタ
	 */
	public function __construct() {
		parent::__construct();

		// コールバック
		add_action( 'init', array( $this, 'init_wp_filesystem' ) );
	}


	/**
	 * ドメインURLをサイト管理に追加するコア機能
	 *
	 * @param string $url サイト管理に追加するURL
	 * @return array 結果ステータスの配列
	 */
	public function set_sitemanage_domainurl( $url ) {
		global $qahm_time;
		global $qahm_log;
		$result_array = array( 'result' => 'success' );

		$parse_url = wp_parse_url( $url );

		if ( ! $parse_url ) {
			return array( 'result' => 'url_invalid_error' );
		}

		$domain_url  = $this->to_domain_url( $parse_url );
		$tracking_id = $this->get_tracking_id( $domain_url );

		$sitemanage = $this->wrap_get_option( 'sitemanage' );
		if ( $sitemanage ) {
			foreach ( $sitemanage as $site ) {
				if ( $site['tracking_id'] == $tracking_id && $site['status'] != 255 ) {
					return array( 'result' => 'registed_domainurl' );
				}
			}
		} else {
			$sitemanage = array();
		}

		$sitemanage[] = array(
			'site_id'                => $this->wrap_count( $sitemanage ),
			'url'                    => $domain_url,
			'domain'                 => $parse_url['host'],
			'tracking_id'            => $tracking_id,
			'memo'                   => '',
			'status'                 => 0,
			'ignore_params'          => '',
			'search_params'          => '',
			'ignore_ips'             => '',
			'url_case_sensitivity'   => 0,
			'get_base_html_periodic' => 0,
			'anontrack'              => ( QAHM_TYPE === QAHM_TYPE_WP ) ? 1 : 0,
			'insert_datetime'        => $qahm_time->now_str(),
		);

		$res = $this->wrap_update_option( 'sitemanage', $sitemanage );

		if ( ! $res ) {
			return array( 'result' => 'db_insert_error' );
		}

		$cq_res = $this->create_qtag( $tracking_id );

		// 指定tracking_idのgscテーブル作成
		$qahm_database_manager = new QAHM_Database_Creator();
		$gsc_table_created     = $qahm_database_manager->create_gsc_query_log_table( $tracking_id );

		if ( ! $gsc_table_created ) {
			// GSCテーブル作成に失敗した場合
			$qahm_log->warning( "QAHM: Failed to create GSC query log table for tracking_id: {$tracking_id}" );

			// サイト管理情報は既に保存されているので、警告レベルのエラーとして返す
			return array(
				'result'      => 'gsc_table_creation_error',
				'message'     => 'Site registration completed, but GSC table creation failed',
				'tracking_id' => $tracking_id,
			);
		}

		// 全て成功した場合
		$result_array['tracking_id'] = $tracking_id;
		return $result_array;
	}
} // end of class
