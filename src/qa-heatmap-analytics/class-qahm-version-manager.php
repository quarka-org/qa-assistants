<?php
defined( 'ABSPATH' ) || exit;
/**
 * QAHM_Version_Manager
 *
 * ページバージョン管理の共通機能を提供する基底クラス
 * QA ZEROとQA Advisorの両製品で利用可能
 *
 * @package qa-heatmap-analytics
 */

class QAHM_Version_Manager extends QAHM_File_Base {

	/**
	 * コンストラクタ
	 */
	public function __construct() {
		$this->init_wp_filesystem();
	}

	/**
	 * 開発用：ページバージョンを手動で更新
	 *
	 * 指定されたページとデバイスに対して新しいバージョンを作成する
	 *
	 * @param int $page_id ページID
	 * @param int $device_id デバイスID（1=desktop, 2=tablet, 3=mobile）
	 * @param string $base_html ベースHTML
	 * @return int|false 新しいバージョン番号、失敗時はfalse
	 */
	public function refresh_version_for_dev( $page_id, $device_id, $base_html ) {
		global $qahm_db;
		global $qahm_time;
		global $wpdb;
		global $wp_filesystem;

		if ( ! $base_html || ! $page_id || ! $device_id ) {
			return false;
		}
		$table_name = $qahm_db->prefix . 'qa_pages';
		$query      = 'SELECT page_id FROM ' . $table_name . ' WHERE page_id = %d';
		$qa_page_id = $qahm_db->get_results( $qahm_db->prepare( $query, $page_id ) );
		if ( ! $qa_page_id ) {
			return false;
		}
		if ( $device_id < QAHM_DEVICES['desktop']['id'] || QAHM_DEVICES['smartphone']['id'] < $device_id ) {
			return false;
		}
		$data_dir      = $this->get_data_dir_path();
		$view_dir      = $data_dir . 'view/';
		$myview_dir    = $view_dir . $this->get_tracking_id() . '/';
		$vw_verhst_dir = $myview_dir . 'version_hist/';

		$today_str = $qahm_time->today_str();
		$now_str   = $qahm_time->now_str();

		$pageid_in_num     = floor( $page_id / QAHM_Cron_Proc::ID_INDEX_MAX10MAN );
		$start_index       = $pageid_in_num * QAHM_Cron_Proc::ID_INDEX_MAX10MAN + 1;
		$end_index         = $start_index + QAHM_Cron_Proc::ID_INDEX_MAX10MAN - 1;
		$pageid_index_file = $start_index . '-' . $end_index . '_pageid.php';

		$table_name     = $qahm_db->prefix . 'qa_page_version_hist';
		$query          = 'SELECT version_no FROM ' . $table_name . ' WHERE page_id=%d AND device_id=%d';
		$version_no_ary = $qahm_db->get_results( $qahm_db->prepare( $query, $page_id, $device_id ), ARRAY_A );

		$cur_ver_no = 1;
		if ( $version_no_ary ) {
			foreach ( $version_no_ary as $ver_no ) {
				if ( $cur_ver_no < $ver_no['version_no'] ) {
					$cur_ver_no = $ver_no['version_no'];
				}
			}
		} else {
			if ( ! is_file( $vw_verhst_dir . 'index/' . $pageid_index_file ) ) {
				$this->remake_indexfile_from_versionfile( $pageid_index_file );
			}
			$table_name           = $qahm_db->prefix . 'view_page_version_hist';
			$query                = 'SELECT version_id,device_id,version_no FROM ' . $table_name . ' WHERE page_id = %d';
			$qa_page_version_hist = $qahm_db->get_results( $qahm_db->prepare( $query, $page_id ) );
			if ( $qa_page_version_hist ) {
				foreach ( $qa_page_version_hist as $hist ) {
					if ( $cur_ver_no < (int) $hist['version_no'] && (int) $hist['device_id'] === (int) $device_id ) {
						$cur_ver_no = (int) $hist['version_no'];
					}
				}
			}
		}
		$new_ver_no = $cur_ver_no + 1;

		// バージョン追加
		$ver_id               = 0;
		$table_name           = $qahm_db->prefix . 'qa_page_version_hist';
		$query                = 'SELECT version_id FROM ' . $table_name . ' WHERE page_id=%d AND device_id=%d AND version_no=%d';
		$already_version_hist = $qahm_db->get_results( $qahm_db->prepare( $query, $page_id, $device_id, $new_ver_no ) );
		if ( $already_version_hist ) {
			$ver_id = (int) $already_version_hist[0]->version_id;
		} else {
			$query  = 'INSERT INTO ' . $table_name . ' ' .
					'(page_id, device_id, version_no, base_html, update_date, insert_datetime) ' .
					'VALUES( %d, %d, %d, %s, %s, %s )';
			$result = $qahm_db->query( $qahm_db->prepare( $query, $page_id, $device_id, $new_ver_no, '', $today_str, $now_str ) );
			if ( $result !== false ) {
				$ver_id = (int) $wpdb->insert_id;
			}
		}
		$makever = false;
		if ( $ver_id !== 0 ) {
			$newfile   = $ver_id . '_version.php';
			$verary    = array();
			$verary[0] = (object) array(
				'version_id'      => (string) $ver_id,
				'page_id'         => (string) $page_id,
				'device_id'       => (string) $device_id,
				'version_no'      => (string) $new_ver_no,
				'base_html'       => $base_html,
				'base_selector'   => null,
				'update_date'     => $today_str,
				'insert_datetime' => $now_str,
			);
			$makever   = $this->wrap_put_contents( $vw_verhst_dir . $newfile, $this->wrap_serialize( $verary ) );

			if ( $wp_filesystem->exists( $vw_verhst_dir . 'index/' ) ) {
				if ( is_file( $vw_verhst_dir . 'index/' . $pageid_index_file ) ) {
					$verhst_pageid_index               = $this->wrap_unserialize( $this->wrap_get_contents( $vw_verhst_dir . 'index/' . $pageid_index_file ) );
					$verhst_pageid_index[ $page_id ][] = $ver_id;
					$makever                           = $this->wrap_put_contents( $vw_verhst_dir . 'index/' . $pageid_index_file, $this->wrap_serialize( $verhst_pageid_index ) );
				} else {
					$this->remake_indexfile_from_versionfile( $pageid_index_file );
				}
			} else {
				$wp_filesystem->mkdir( $vw_verhst_dir . 'index/' );
				$this->remake_indexfile_from_versionfile( $pageid_index_file );
			}
		}

		$retval = $new_ver_no;
		if ( $makever === false ) {
			$retval = false;
		}
		return $retval;
	}

	/**
	 * versionファイルのindex再作成
	 *
	 * @param string $pageid_index_file インデックスファイル名
	 * @return array|false インデックス配列、失敗時はfalse
	 */
	public function remake_indexfile_from_versionfile( $pageid_index_file ) {
		$data_dir        = $this->get_data_dir_path();
		$view_dir        = $data_dir . 'view/';
		$myview_dir      = $view_dir . $this->get_tracking_id() . '/';
		$vw_verhst_dir   = $myview_dir . 'version_hist/';
		$vw_verhst_index = $vw_verhst_dir . 'index/';
		$version_dirs    = $this->wrap_dirlist( $vw_verhst_dir );
		if ( ! $version_dirs ) {
			return false;
		}
		$verhst_pageid_index = array();
		$page_ids            = $this->wrap_explode( '-', $pageid_index_file );
		$start_page_id       = (int) $page_ids[0];
		$end_page_id         = (int) $page_ids[1];
		foreach ( $version_dirs as $version_file ) {
			if ( is_file( $vw_verhst_dir . $version_file['name'] ) ) {
				$version_file_name = $version_file['name'];
				$version_file_path = $vw_verhst_dir . $version_file_name;
				$version_file_ary  = $this->wrap_unserialize( $this->wrap_get_contents( $version_file_path ) );
				if ( $version_file_ary ) {
					foreach ( $version_file_ary as $version ) {
						$page_id = (int) $version->page_id;
						if ( $start_page_id <= $page_id && $page_id <= $end_page_id ) {
							$versionstrs                       = $this->wrap_explode( '_', $version_file_name );
							$ver_id                            = (int) $versionstrs[0];
							$verhst_pageid_index[ $page_id ][] = $ver_id;
						}
					}
				}
			}
		}
		if ( $verhst_pageid_index ) {
			$this->wrap_put_contents( $vw_verhst_index . $pageid_index_file, $this->wrap_serialize( $verhst_pageid_index ) );
		}
		return $verhst_pageid_index;
	}
}
