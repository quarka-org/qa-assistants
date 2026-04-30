<?php
defined( 'ABSPATH' ) || exit;
/**
 * ヒートマップビューで様々な操作をやりやすくするクラス（予定）
 *
 * @package qa_heatmap
 */

$GLOBALS['qahm_view_heatmap'] = new QAHM_View_Heatmap();

class QAHM_View_Heatmap extends QAHM_View_base {

	const ATTENTION_LIMIT_TIME = 30;
	//熟読度
	const MAX_READING_LEVEL = 37.5;
	const ONE_PER_SIX       = 1 / 6;
	const FOUR_PER_SIX      = 4 / 6;

	public function __construct() {
		$this->regist_ajax_func( 'ajax_create_heatmap_file' );
		$this->regist_ajax_func( 'ajax_init_heatmap_view' );
		$this->regist_ajax_func( 'ajax_update_page_version' );
		//QA ZERO
		$this->regist_ajax_func( 'ajax_get_separate_data' );
		//QA ZERO END
		add_action( 'init', array( $this, 'init_wp_filesystem' ) );
	}

	public function get_heatmap_view_work_dir_url() {
		return parent::get_data_dir_url() . 'heatmap-view-work/';
	}


	/**
	 * ヒートマップ表示用のファイルを作成
	 */
	public function ajax_create_heatmap_file() {
		global $qahm_log;

		try {
			$start_date  = $this->wrap_filter_input( INPUT_POST, 'start_date' );
			$end_date    = $this->wrap_filter_input( INPUT_POST, 'end_date' );
			$tracking_id = $this->wrap_filter_input( INPUT_POST, 'tracking_id' );
			$page_id     = (int) $this->wrap_filter_input( INPUT_POST, 'page_id' );
			$device_name = $this->wrap_filter_input( INPUT_POST, 'device_name' );
			$device_id   = $this->device_name_to_device_id( $device_name );
			$version_id  = $this->get_version_id( $page_id, $device_id );
			if ( ! $version_id ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
				echo $this->wrap_json_encode( __( 'バージョンIDが存在しません。', 'qa-heatmap-analytics' ) );
				die();
			}

			$is_landing_page = $this->wrap_filter_input( INPUT_POST, 'is_landing_page' );
			$media           = $this->wrap_filter_input( INPUT_POST, 'media' );
			$goal            = $this->wrap_filter_input( INPUT_POST, 'goal' );

			$this->create_heatmap_file( $start_date, $end_date, $version_id, $is_landing_page, $tracking_id );

			$query_media = '';
			if ( $media ) {
				$query_media = '&media=' . $media;
			}
			$query_goal = '';
			if ( $goal ) {
				$query_goal = '&goal=' . $goal;
			}
			$heatmap_view_url  = esc_url( plugin_dir_url( __FILE__ ) . 'heatmap-view.php' ) . '?';
			$heatmap_view_url .= 'version_id=' . $version_id . '&start_date=' . $start_date . '&end_date=' . $end_date . '&is_landing_page=' . $is_landing_page . $query_media . $query_goal . '&tracking_id=' . $tracking_id;

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
			echo $this->wrap_json_encode( $heatmap_view_url );

		} catch ( Exception $e ) {
			$log = $qahm_log->error( $e->getMessage() );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
			echo $this->wrap_json_encode( $log );

		} finally {
			die();
		}
	}

	/**
	 * page_idとdevice_idから最新のversion_idを取得
	 */
	public function get_version_id( $page_id, $device_id ) {
		global $qahm_db;

		$table_name   = 'view_page_version_hist';
		$query        = 'SELECT version_id,device_id,version_no FROM ' . $qahm_db->prefix . $table_name . ' WHERE page_id = %d';
		$version_hist = $qahm_db->get_results( $qahm_db->prepare( $query, $page_id ), ARRAY_A );

		// DBに格納されている一番新しいバージョンを取得する
		if ( $version_hist ) {
			$latest_version = null;
			foreach ( $version_hist as $hist ) {
				if ( (int) $hist['device_id'] !== $device_id ) {
					continue;
				}
				if ( is_null( $latest_version ) || $hist['version_no'] > $latest_version['version_no'] ) {
					$latest_version = $hist;
				}
			}
			$version_hist = $latest_version;
			if ( $version_hist ) {
				$version_id = (int) $version_hist['version_id'];
			}
		}

		return $version_id;
	}


	//メモ
	// base_selectorの配列にversion_idごとのキー配列を作る
	// そこを参照する
	// 期間内の全PVをもってくる

	/**
	 * ヒートマップビュー用の一時ファイルを作成
	 * 関数名は一時的なもの。残ったQAの処理をこちらの関数に移行した際にzeroを外す予定
	 */
	public function create_heatmap_file( $start_date, $end_date, $version_id, $is_landing_page, $tracking_id = 'all' ) {
		global $qahm_db;
		global $wp_filesystem;
		global $qahm_log;
		global $qahm_time;

		$file_base_name = $version_id . '_' . preg_replace( '/[\s:-]+/', '', $start_date ) . '_' . preg_replace( '/[\s:-]+/', '', $end_date ) . '_' . $is_landing_page . '_' . $tracking_id;

		//ゴールセッションを取得
		global $qahm_data_api;
		$dateterm = 'date = between ' . $this->wrap_substr( $start_date, 0, 10 ) . ' and ' . $this->wrap_substr( $end_date, 0, 10 );
		// $goals_sessions配列をキーをpv_idとする新しい配列に変換します。
		$goals_sessions_keys = array();
		$all_goals_sessions  = $qahm_data_api->get_goals_sessions( $dateterm, $tracking_id );
		foreach ( $all_goals_sessions as $goals_sessions ) {
			foreach ( $goals_sessions as $goal_session ) {
				foreach ( $goal_session as $session ) {
					$goals_sessions_keys[ $session['pv_id'] ] = true;
				}
			}
		}
		//ヒートマップ用の一時ファイル保存先
		$heatmap_view_work_dir = $this->get_data_dir_path( 'heatmap-view-work' );
		$this->wrap_mkdir( $heatmap_view_work_dir );

		// base.htmlをdbから取得
		$base_html            = null;
		$base_selector_ary    = array();
		$qa_pages             = null;
		$qa_page_version_hist = null;
		$base_url             = null;
		$version_no           = null;
		$device_id            = null;
		$wp_qa_type           = null;
		$wp_qa_id             = null;
		$start_unixtime       = $qahm_time->str_to_unixtime( $start_date );
		$end_unixtime         = $qahm_time->str_to_unixtime( $end_date );

		$merge_att_scr_ary_v1 = array();
		$merge_att_scr_ary_v2 = array();
		$merge_click_ary      = array();
		//QA ZERO
		// QA ZERO はv2のみ
		$pkey_merge_att_scr_ary_v2     = array();
		$separate_merge_att_scr_ary_v2 = array();
		$separate_total_stay_time      = array();
		$separate_exit_idx             = array();
		$separate_merge_click_ary      = array();
		$separate_data_num             = array();
		$separate_time_on_page         = array();
		//QA ZERO END
		$data_num     = 0;
		$time_on_page = 0;

		$table_name           = 'view_page_version_hist';
		$query                = 'SELECT version_id,page_id,device_id,version_no,base_html,base_selector FROM ' . $qahm_db->prefix . $table_name . ' WHERE version_id = %d';
		$qa_page_version_hist = $qahm_db->get_results( $qahm_db->prepare( $query, $version_id ), ARRAY_A );
		if ( ! $qa_page_version_hist ) {
			return null;
		}

		$qa_page_version_hist = $qa_page_version_hist[0];

		// ルール
		// ヒートマップビューでは最新のbase_htmlを使用する
		// 格納する$version_idや$version_noも最新のものを使用する
		// base_selectorはversion_idをキーとした配列を作成する
		$base_selector_ary = $qa_page_version_hist['base_selector'];
		$version_no        = (int) $qa_page_version_hist['version_no'];
		$page_id           = (int) $qa_page_version_hist['page_id'];
		$device_id         = (int) $qa_page_version_hist['device_id'];
		$device_name       = $this->device_id_to_device_name( $device_id );
		$base_html         = $qa_page_version_hist['base_html'];

		// 同じpage_idかつ同じdevice_idの全てのqa_page_version_histを取得
		// この処理はヒートマップビューでバージョン変更できるようにするため
		//
		// $qahm_dbでは「AND device_id = %d ORDER BY version_no DESC';」のようなクエリが2024/06/18時点で使えないので、
		// page_idで取得した後にdevice_idでフィルタリングする

		// 同じpage_idの全てのqa_page_version_histを取得
		$query            = 'SELECT version_id,device_id,version_no,insert_datetime FROM ' . $qahm_db->prefix . $table_name . ' WHERE page_id = %d';
		$all_version_hist = $qahm_db->get_results( $qahm_db->prepare( $query, $page_id ), ARRAY_A );
		if ( ! $all_version_hist ) {
			return null;
		}

		// 最新のversion_noを持つ各デバイスのversion_idを配列に格納
		$device_version_ary        = array();
		$device_version_no_max_ary = array();
		foreach ( $all_version_hist as $hist ) {
			$temp_device_id   = $hist['device_id'];
			$temp_device_name = $this->device_id_to_device_name( $temp_device_id );
			$temp_version_no  = (int) $hist['version_no'];
			$temp_version_id  = (int) $hist['version_id'];
			if ( ! isset( $device_version_ary[ $temp_device_name ] ) || $temp_version_no > $device_version_no_max_ary[ $temp_device_name ] ) {
				$device_version_ary[ $temp_device_name ]        = $temp_version_id;
				$device_version_no_max_ary[ $temp_device_name ] = $temp_version_no;
			}
		}
		unset( $device_version_no_max_ary );

		// 同デバイスの全バージョンを$all_version_ary配列に格納
		// device_idが一致するレコードをフィルタリング
		$filtered_version_hist = array();
		foreach ( $all_version_hist as $hist ) {
			if ( $hist['device_id'] == $device_id ) {
				$filtered_version_hist[] = $hist;
			}
		}

		if ( empty( $filtered_version_hist ) ) {
			return null;
		}

		// version_noの降順でソート
		usort(
			$filtered_version_hist,
			function ( $a, $b ) {
				return $b['version_no'] - $a['version_no'];
			}
		);

		$all_version_ary   = array();
		$previous_datetime = null; // 以前の日時を格納する変数
		$current_date      = $qahm_time->today_str( 'Y/m/d' ); // 現在の日付を取得
		//$current_date = $qahm_time->xday_str( '-1', 'now', 'Y/m/d' ); // 現在の日付に-1日した文字列を取得

		for ( $i = 0; $i < $this->wrap_count( $filtered_version_hist ); $i++ ) {
			$hist             = $filtered_version_hist[ $i ];
			$current_datetime = gmdate( 'Y/m/d', strtotime( $hist['insert_datetime'] ) ); // datetimeを日付のみに変換

			if ( $i == 0 ) {
				// 先頭のループでは開始日時から現在日時までの期間
				$period_string = $current_datetime . ' - ' . $current_date;
			} else {
				// それ以降のループでは期間を作成
				$period_string = $current_datetime . ' - ' . $previous_datetime;
			}

			$all_version_ary[] = array(
				'version_id'     => $hist['version_id'],
				'version_no'     => $hist['version_no'],
				'version_period' => $period_string,
			);

			// 現在の日時を以前の日時として更新
			$previous_datetime = $current_datetime;
		}

		// wp_qa_type,wp_qa_idはZEROではいらないがエラー回避のため今は残しておく
		$table_name = 'qa_pages';
		$query      = 'SELECT wp_qa_type,wp_qa_id,url FROM ' . $qahm_db->prefix . $table_name . ' WHERE page_id = %d';
		$qa_pages   = $qahm_db->get_results( $qahm_db->prepare( $query, $page_id ), ARRAY_A );
		if ( $qa_pages ) {
			$page       = $qa_pages[0];
			$wp_qa_type = $page['wp_qa_type'];
			$wp_qa_id   = $page['wp_qa_id'];
			$base_url   = $page['url'];
		}

		//speed up 2023/12/03 by maruyama
		$table_name = 'view_pv';
		$query      = 'SELECT pv_id,device_id,access_time,pv,version_id,is_raw_p,is_raw_c,is_raw_e,utm_medium,utm_source,source_domain,utm_campaign FROM ' . $qahm_db->prefix . $table_name . ' WHERE page_id = %d and access_time between %s and %s';
		$res_ary    = $qahm_db->get_results( $qahm_db->prepare( $query, $page_id, $start_date, $end_date ), ARRAY_A );
		//$query      = 'SELECT pv_id,device_id,access_time,version_id,is_raw_p,is_raw_c,is_raw_e,utm_medium,utm_source,source_domain,utm_campaign FROM ' . $qahm_db->prefix . $table_name . ' WHERE page_id = %d';
		//$res_ary    = $qahm_db->get_results( $qahm_db->prepare( $query, $page_id ), ARRAY_A, $tracking_id );
		//$query      = 'SELECT pv_id,device_id,access_time,version_id,is_raw_p,is_raw_c,is_raw_e,utm_medium,utm_source,source_domain,utm_campaign FROM ' . $qahm_db->prefix . $table_name . ' WHERE version_id = %d';
		//$res_ary    = $qahm_db->get_results( $qahm_db->prepare( $query, $version_id ), ARRAY_A, $tracking_id );

		if ( $res_ary ) {
			foreach ( $res_ary as $pv ) {
				if ( $pv['access_time'] < $start_unixtime || $pv['access_time'] > $end_unixtime ) {
					continue;
				}
				if ( (int) $pv['device_id'] !== $device_id ) {
					continue;
				}
				if ( $is_landing_page && (int) $pv['pv'] !== 1 ) {
					continue;
				}
				$qa_pv_log[] = $pv;
			}
		}

		if ( $qa_pv_log ) {

			usort(
				$qa_pv_log,
				function ( $a, $b ) {
					return $a['access_time'] <=> $b['access_time'];
				}
			);

			// 100分率 + 精読率100%の分配列を用意
			for ( $iii = 0; $iii < 100 + 1; $iii++ ) {
				$merge_att_scr_ary_v1[ $iii ] = array( $iii, 0, 0, 0 );
			}

			$total_stay_time = 0;
			$merge_stay_num  = array();

			$view_pv_dir = $this->get_data_dir_path( 'view' ) . $tracking_id . '/view_pv/';

			$raw_p_filemap = $this->get_files_in_date_range( $view_pv_dir . 'raw_p/', $start_date, $end_date );
			$raw_c_filemap = $this->get_files_in_date_range( $view_pv_dir . 'raw_c/', $start_date, $end_date );
			$last_date     = null; // 前回処理した日付を追跡するための変数

			foreach ( $qa_pv_log as $pv_log ) {
				$raw_p_tsv = null;
				$raw_c_tsv = null;

				//QA ZERO
				// $separate_merge_click_aryの作成
				$utm_medium   = isset( $pv_log['utm_medium'] ) ? $pv_log['utm_medium'] : null;
				$utm_source   = isset( $pv_log['utm_source'] ) ? $pv_log['utm_source'] : null;
				$utm_campaign = isset( $pv_log['utm_campaign'] ) ? $pv_log['utm_campaign'] : null;

				if ( ! empty( $utm_source ) ) {
					$source_domain = $utm_source;
				} else {
					$source_domain = isset( $pv_log['source_domain'] ) ? $pv_log['source_domain'] : '(not set)';
				}

				if ( empty( $utm_medium ) ) {
					$utm_medium = '(not set)';
				}
				if ( empty( $source_domain ) ) {
					$source_domain = '(not set)';
				}
				if ( empty( $utm_campaign ) ) {
					$utm_campaign = '(not set)';
				}

				// pv_idが$goals_sessions_keys配列のキーに存在するかどうかを確認します。
				if ( isset( $pv_log['pv_id'] ) && is_array( $goals_sessions_keys ) !== null ) {
					$is_goal = $this->wrap_array_key_exists( $pv_log['pv_id'], $goals_sessions_keys ) ? '○' : '×';
				} else {
					$is_goal = '(不明)';
				}

				// キーを作成します。
				$key = $utm_medium . '_' . $source_domain . '_' . $utm_campaign . '_' . $is_goal;

				if ( ! isset( $separate_merge_att_scr_ary_v2[ $key ] ) ) {
					$separate_merge_att_scr_ary_v2[ $key ] = array();
					$separate_total_stay_time[ $key ]      = 0;
				}
				//QA ZERO END

				if ( $this->wrap_array_key_exists( 'is_raw_p', $pv_log ) ) {
					// view_pv
					if ( $pv_log['is_raw_p'] || $pv_log['is_raw_c'] || $pv_log['is_raw_e'] ) {
						$pv_id = $pv_log['pv_id'];
						// 日付を取得し、ファイル名を特定
						$current_date = $qahm_time->unixtime_to_str( $pv_log['access_time'], 'Y-m-d' );
						if ( $last_date !== $current_date ) {
							$last_date = $current_date; // 日付を更新
							if ( isset( $raw_p_filemap[ $current_date ] ) ) {
								$raw_p_file       = $raw_p_filemap[ $current_date ];
								$raw_c_file       = $raw_c_filemap[ $current_date ];
								$raw_p_data_ary   = $this->wrap_unserialize( $this->wrap_get_contents( $view_pv_dir . 'raw_p/' . $raw_p_file ) );
								$raw_p_cached_ary = array();
								foreach ( $raw_p_data_ary as $raw_p_data ) {
									$raw_p_cached_ary[ $raw_p_data['pv_id'] ] = $raw_p_data['raw_p'];
								}
								$raw_c_data_ary   = $this->wrap_unserialize( $this->wrap_get_contents( $view_pv_dir . 'raw_c/' . $raw_c_file ) );
								$raw_c_cached_ary = array();
								foreach ( $raw_c_data_ary as $raw_c_data ) {
									$raw_c_cached_ary[ $raw_c_data['pv_id'] ] = $raw_c_data['raw_c'];
								}
							} else {
								continue;
							}
						}
						if ( $pv_log['is_raw_p'] ) {
							if ( isset( $raw_p_cached_ary[ $pv_id ] ) ) {
								$raw_p_tsv = $raw_p_cached_ary[ $pv_id ];
							}
						}

						if ( $pv_log['is_raw_c'] ) {
							if ( isset( $raw_c_cached_ary[ $pv_id ] ) ) {
								$raw_c_tsv = $raw_c_cached_ary[ $pv_id ];
							}
						}

						if ( $raw_p_tsv || $raw_c_tsv ) {
							++$data_num;
							//QA ZERO
							if ( ! isset( $separate_data_num[ $key ] ) ) {
								$separate_data_num[ $key ] = 0;
							}
							++$separate_data_num[ $key ];
							//QA ZERO END
						}
					}
				} else {
					// qa_pv_log
					if ( $pv_log['raw_p'] || $pv_log['raw_c'] || $pv_log['raw_e'] ) {
						$raw_p_tsv = $pv_log['raw_p'];
						$raw_c_tsv = $pv_log['raw_c'];

						++$data_num;
						//QA ZERO
						++$separate_data_num[ $key ];
						//QA ZERO END
					}
				}

				if ( $raw_p_tsv ) {
					$raw_p_ary = $this->convert_tsv_to_array( $raw_p_tsv );
					//QA ZERO
					$separate_exit_idx[ $key ] = -1;
					$merge_exit_idx            = -1;
					$raw_p_max                 = $this->wrap_count( $raw_p_ary );
					//QA ZERO END
					// 滞在時間
					$ver = (int) $raw_p_ary[ self::DATA_COLUMN_HEADER ][ self::DATA_HEADER_VERSION ];
					if ( $ver === 2 ) {
						// 最大の滞在時間を取得
						$max_stay_time = 0;
						foreach ( $raw_p_ary as $p ) {
							if ( isset( $p[ self::DATA_POS_2['STAY_TIME'] ] ) ) {
								if ( $p[ self::DATA_POS_2['STAY_TIME'] ] > $max_stay_time ) {
									$max_stay_time = $p[ self::DATA_POS_2['STAY_TIME'] ];
								}
							}
						}
						if ( $max_stay_time <= 0 ) {
							$max_stay_time = 1;
						}
						if ( self::ATTENTION_LIMIT_TIME < $max_stay_time ) {
							$max_stay_time = self::ATTENTION_LIMIT_TIME;
						}
						//1pvの滞在時間を全ポジションごとに処理する
						for ( $raw_p_idx = self::DATA_COLUMN_BODY; $raw_p_idx < $raw_p_max; $raw_p_idx++ ) {
							$p = $raw_p_ary[ $raw_p_idx ];
							if ( ! isset( $p[ self::DATA_POS_2['STAY_HEIGHT'] ] ) ) {
								break;
							}

							if ( $p[ self::DATA_POS_2['STAY_HEIGHT'] ] === 'a' ) {
								// あとでこの処理を詰める
								break;
							}

							$stay_time = min( (int) $p[ self::DATA_POS_2['STAY_TIME'] ], self::ATTENTION_LIMIT_TIME );
							// 滞在時間を熟読度に変換。センターに4/6、その前後に1/6ずつ割り振る（正規分布）
							if ( $max_stay_time <= 2 ) {
								// max_stay_timeが2秒以下の場合、reading_levelを固定値に設定
								$reading_level = $stay_time == 2 ? 4 : 2;
							} else {
								$reading_level = ( $stay_time / $max_stay_time ) * self::MAX_READING_LEVEL;
							}
							//$merge_att_scr_ary_v2[STAY_HEIGHT]の中身
							// STAY_HEIGHT：ユーザーが滞在したページの高さを示す値。100pxで割った値
							// STAY_TIME：その高さでユーザーが滞在した時間。秒単位だったが熟読度に変更。
							// STAY_NUM：その高さで滞在したユーザーの数。
							// EXIT_NUM：その高さでページを離脱したユーザーの数。

							// 合計滞在時間に加算
							$total_stay_time += $stay_time;

							//QA ZERO
							$separate_merge_att_scr_idx = (int) $p[ self::DATA_POS_2['STAY_HEIGHT'] ];

							// standard 用 STAY_NUM カウンタ（旧コードと等価な初期化値を維持）
							if ( isset( $merge_stay_num[ $separate_merge_att_scr_idx ] ) ) {
								++$merge_stay_num[ $separate_merge_att_scr_idx ];
							} else {
								$merge_stay_num[ $separate_merge_att_scr_idx ] = self::FOUR_PER_SIX;
							}
							if ( $separate_merge_att_scr_idx - 1 >= 0 ) {
								if ( isset( $merge_stay_num[ $separate_merge_att_scr_idx - 1 ] ) ) {
									++$merge_stay_num[ $separate_merge_att_scr_idx - 1 ];
								} else {
									$merge_stay_num[ $separate_merge_att_scr_idx - 1 ] = self::ONE_PER_SIX;
								}
							}
							if ( isset( $merge_stay_num[ $separate_merge_att_scr_idx + 1 ] ) ) {
								++$merge_stay_num[ $separate_merge_att_scr_idx + 1 ];
							} else {
								$merge_stay_num[ $separate_merge_att_scr_idx + 1 ] = self::ONE_PER_SIX;
							}
							if ( $merge_exit_idx < $separate_merge_att_scr_idx ) {
								$merge_exit_idx = $separate_merge_att_scr_idx;
							}

							// 中心のインデックスに4/6を割り振る
							if ( isset( $separate_merge_att_scr_ary_v2[ $key ][ $separate_merge_att_scr_idx ] ) ) {
								$separate_merge_att_scr_ary_v2[ $key ][ $separate_merge_att_scr_idx ][ self::DATA_MERGE_ATTENTION_SCROLL_2['STAY_TIME'] ] += $reading_level * 4 / 6;
								++$separate_merge_att_scr_ary_v2[ $key ][ $separate_merge_att_scr_idx ][ self::DATA_MERGE_ATTENTION_SCROLL_2['STAY_NUM'] ];
							} else {
								$separate_merge_att_scr_ary_v2[ $key ][ $separate_merge_att_scr_idx ] = array(
									(int) $p[ self::DATA_POS_2['STAY_HEIGHT'] ],
									$reading_level * self::FOUR_PER_SIX,
									self::FOUR_PER_SIX,
									0,
								);
							}

							// 前後のインデックスが存在する場合、それぞれに1/6を割り振る
							if ( $separate_merge_att_scr_idx - 1 >= 0 ) {
								if ( isset( $separate_merge_att_scr_ary_v2[ $key ][ $separate_merge_att_scr_idx - 1 ] ) ) {
									$separate_merge_att_scr_ary_v2[ $key ][ $separate_merge_att_scr_idx - 1 ][ self::DATA_MERGE_ATTENTION_SCROLL_2['STAY_TIME'] ] += $reading_level * 1 / 6;
									++$separate_merge_att_scr_ary_v2[ $key ][ $separate_merge_att_scr_idx - 1 ][ self::DATA_MERGE_ATTENTION_SCROLL_2['STAY_NUM'] ];
								} else {
									$separate_merge_att_scr_ary_v2[ $key ][ $separate_merge_att_scr_idx - 1 ] = array(
										(int) $p[ self::DATA_POS_2['STAY_HEIGHT'] ] - 1,
										$reading_level * self::ONE_PER_SIX,
										self::ONE_PER_SIX,
										0,
									);
								}
							}

							if ( isset( $separate_merge_att_scr_ary_v2[ $key ][ $separate_merge_att_scr_idx + 1 ] ) ) {
								$separate_merge_att_scr_ary_v2[ $key ][ $separate_merge_att_scr_idx + 1 ][ self::DATA_MERGE_ATTENTION_SCROLL_2['STAY_TIME'] ] += $reading_level * 1 / 6;
								++$separate_merge_att_scr_ary_v2[ $key ][ $separate_merge_att_scr_idx + 1 ][ self::DATA_MERGE_ATTENTION_SCROLL_2['STAY_NUM'] ];
							} else {
								$separate_merge_att_scr_ary_v2[ $key ][ $separate_merge_att_scr_idx + 1 ] = array(
									(int) $p[ self::DATA_POS_2['STAY_HEIGHT'] ] + 1,
									$reading_level * self::ONE_PER_SIX,
									self::ONE_PER_SIX,
									0,
								);
							}
							// 離脱位置を更新。既にソートされた配列なので比較の必要なし
							if ( $separate_exit_idx[ $key ] < $separate_merge_att_scr_idx ) {
								$separate_exit_idx[ $key ] = $separate_merge_att_scr_idx;
							}

							// 合計滞在時間に加算
							$separate_total_stay_time[ $key ] += $stay_time;
							//QA ZERO END

						}

						// standard 用離脱位置の STAY_NUM 処理
						if ( -1 === $merge_exit_idx ) {
							$merge_exit_idx = 0;
						}
						if ( ! isset( $merge_stay_num[ $merge_exit_idx ] ) ) {
							$merge_stay_num[ $merge_exit_idx ] = 1;
						}

						// QA ZEROでもbody部が存在しなかったユーザーの対策。離脱位置を強制的に0の部分にする。
						// これにより、ヒートマップビューのデータ数とスクロールマップトップのデータ数との見た目上の整合性を合わせる
						if ( $separate_exit_idx[ $key ] === -1 ) {
							$separate_exit_idx[ $key ] = 0;
						}
						if ( isset( $separate_merge_att_scr_ary_v2[ $key ][ $separate_exit_idx[ $key ] ] ) ) {
							// 離脱ユーザーの位置を増やす
							++$separate_merge_att_scr_ary_v2[ $key ][ $separate_exit_idx[ $key ] ][ self::DATA_MERGE_ATTENTION_SCROLL_2['EXIT_NUM'] ];
						} else {
							// 離脱ユーザーの位置を新たに作成
							$separate_merge_att_scr_ary_v2[ $key ][ $separate_exit_idx[ $key ] ] = array(
								0,
								0,
								1,
								0,
							);
						}
					}
				}

				if ( $raw_c_tsv && $base_selector_ary ) {
					$raw_c_ary     = $this->convert_tsv_to_array( $raw_c_tsv );
					$base_selector = $this->wrap_explode( "\t", $base_selector_ary );

					foreach ( $raw_c_ary as $index => $c ) {
						if ( $index === self::DATA_COLUMN_HEADER ) {
							// header部。現在は何もしない
						} else {
							// body部
							if ( ! isset( $c[ self::DATA_CLICK_1['SELECTOR_NAME'] ] ) || ! isset( $base_selector[ $c[ self::DATA_CLICK_1['SELECTOR_NAME'] ] ] ) ) {
								continue;
							}
							//QA ZERO
							if ( ! isset( $separate_merge_click_ary[ $key ] ) ) {
								$separate_merge_click_ary[ $key ] = array();
							}
							$separate_merge_click_ary[ $key ][] = array(
								self::DATA_MERGE_CLICK_1['SELECTOR_NAME'] => $base_selector[ $c[ self::DATA_CLICK_1['SELECTOR_NAME'] ] ],
								self::DATA_MERGE_CLICK_1['SELECTOR_X'] => $c[ self::DATA_CLICK_1['SELECTOR_X'] ],
								self::DATA_MERGE_CLICK_1['SELECTOR_Y'] => $c[ self::DATA_CLICK_1['SELECTOR_Y'] ],
							);
							//QA ZERO END
						}
					}
				}
			}

			// separate 配列から standard の merge 配列を導出（改善A: ループ内の重複計算を排除）
			$merge_att_scr_ary_v2 = array();
			foreach ( $separate_merge_att_scr_ary_v2 as $sep_key => $sep_data ) {
				foreach ( $sep_data as $idx => $values ) {
					if ( isset( $merge_att_scr_ary_v2[ $idx ] ) ) {
						$merge_att_scr_ary_v2[ $idx ][ self::DATA_MERGE_ATTENTION_SCROLL_2['STAY_TIME'] ] += $values[ self::DATA_MERGE_ATTENTION_SCROLL_2['STAY_TIME'] ];
						$merge_att_scr_ary_v2[ $idx ][ self::DATA_MERGE_ATTENTION_SCROLL_2['STAY_NUM'] ]  += $values[ self::DATA_MERGE_ATTENTION_SCROLL_2['STAY_NUM'] ];
						$merge_att_scr_ary_v2[ $idx ][ self::DATA_MERGE_ATTENTION_SCROLL_2['EXIT_NUM'] ]  += $values[ self::DATA_MERGE_ATTENTION_SCROLL_2['EXIT_NUM'] ];
					} else {
						$merge_att_scr_ary_v2[ $idx ] = $values;
					}
				}
			}

			// STAY_NUM を standard 用カウンタで上書き（separate 集約では初期化回数が異なるため）
			foreach ( $merge_stay_num as $idx => $num ) {
				if ( isset( $merge_att_scr_ary_v2[ $idx ] ) ) {
					$merge_att_scr_ary_v2[ $idx ][ self::DATA_MERGE_ATTENTION_SCROLL_2['STAY_NUM'] ] = $num;
				}
			}

			$merge_click_ary = array();
			if ( ! isset( $separate_merge_click_ary ) ) {
				$separate_merge_click_ary = array();
			}
			foreach ( $separate_merge_click_ary as $sep_key => $sep_clicks ) {
				foreach ( $sep_clicks as $click ) {
					$merge_click_ary[] = $click;
				}
			}

			if ( $data_num > 0 ) {
				$time_on_page = round( $total_stay_time / $data_num, 2 );
			}

			// 合算したデータの平均値を求める
			$merge_max = $this->wrap_count( $merge_att_scr_ary_v2 );
			if ( $merge_max > 0 ) {
				for ( $merge_idx = 0; $merge_idx < $merge_max; $merge_idx++ ) {
					if ( isset( $merge_att_scr_ary_v2[ $merge_idx ] ) && isset( $merge_att_scr_ary_v2[ $merge_idx ][ self::DATA_MERGE_ATTENTION_SCROLL_2['STAY_NUM'] ] ) && $merge_att_scr_ary_v2[ $merge_idx ][ self::DATA_MERGE_ATTENTION_SCROLL_2['STAY_NUM'] ] > 1 ) {
						$merge_att_scr_ary_v2[ $merge_idx ][ self::DATA_MERGE_ATTENTION_SCROLL_2['STAY_TIME'] ] /= $merge_att_scr_ary_v2[ $merge_idx ][ self::DATA_MERGE_ATTENTION_SCROLL_2['STAY_NUM'] ];
						$merge_att_scr_ary_v2[ $merge_idx ][ self::DATA_MERGE_ATTENTION_SCROLL_2['STAY_TIME'] ]  = round( $merge_att_scr_ary_v2[ $merge_idx ][ self::DATA_MERGE_ATTENTION_SCROLL_2['STAY_TIME'] ], 3 );
					}
				}
			}
			//QA ZERO
			//separateはサーバー側では平均せず、クライアント側で自由に合算し、平均を求める。
			//          foreach ( $separate_merge_att_scr_ary_v2 as $key => $value ) {
			//              if ( $separate_data_num[ $key ] > 0 ) {
			//                  $separate_time_on_page[ $key ] = round( $separate_total_stay_time[ $key ] / $separate_data_num[ $key ], 2 );
			//              }
			//              // 合算したデータの平均値を求める
			//
			//              $separate_merge_max = $this->wrap_count( $separate_merge_att_scr_ary_v2[ $key ] );
			//              if ( $separate_merge_max > 0 ) {
			//                  for ( $separate_merge_idx = 0; $separate_merge_idx < $separate_merge_max; $separate_merge_idx++ ) {
			//                      if ( $separate_merge_att_scr_ary_v2[ $key ][ $separate_merge_idx ][ self::DATA_MERGE_ATTENTION_SCROLL_2[ 'STAY_NUM' ] ] > 1 ) {
			//                          $separate_merge_att_scr_ary_v2[ $key ][ $separate_merge_idx ][ self::DATA_MERGE_ATTENTION_SCROLL_2[ 'STAY_TIME' ] ] /= $separate_merge_att_scr_ary_v2[ $key ][ $separate_merge_idx ][ self::DATA_MERGE_ATTENTION_SCROLL_2[ 'STAY_NUM' ] ];
			//                          $separate_merge_att_scr_ary_v2[ $key ][ $separate_merge_idx ][ self::DATA_MERGE_ATTENTION_SCROLL_2[ 'STAY_TIME' ] ] = round( $separate_merge_att_scr_ary_v2[ $key ][ $separate_merge_idx ][ self::DATA_MERGE_ATTENTION_SCROLL_2[ 'STAY_TIME' ] ], 3 );
			//                      }
			//                  }
			//              }
			//          }

			//QA ZERO END
		}

		// dbにbase_htmlが存在しない場合は作る
		if ( ! $base_html ) {
			$http_response_header = null;
			$response             = $this->wrap_remote_get( $base_url, $device_name );
			$response_code        = isset( $response['response']['code'] ) ? intval( $response['response']['code'] ) : 0;
			if ( is_wp_error( $response ) ) {
				throw new Exception( 'wp_remote_get failed.' );
			}
			if ( ! ( $response_code === 200 || $response_code === 404 ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is internal, not user-facing output.
				throw new Exception( 'wp_remote_get status error. status: ' . $response_code );
			}
			$base_html = $response['body'];
			if ( $this->is_zip( $base_html ) ) {
				$temphtml = gzdecode( $base_html );
				if ( $temphtml !== false ) {
					$base_html = $temphtml;
				}
			}
		}

		// baseが存在した場合、cap.phpを作成する
		if ( $base_html ) {
			// capはbaseを加工
			$cap_path = $heatmap_view_work_dir . $file_base_name . '-cap.php';
			//$cap_content = $this->opt_html( $cap_path, $base_html, $type, $id, $ver, $device_name );
			$cap_content = $this->opt_base_html( $cap_path, $base_html, $base_url, $device_name );

			if ( $cap_content ) {
				// cap
				$wp_filesystem->put_contents( $cap_path, $cap_content );

				// マージファイル
				if ( $merge_att_scr_ary_v2 ) {
					// ソート後、tsvに変換して保存
					$sort_ary = array();
					foreach ( $merge_att_scr_ary_v2 as $val_idx => $val_ary ) {
						$sort_ary[ $val_idx ] = $val_ary[ self::DATA_MERGE_ATTENTION_SCROLL_2['STAY_HEIGHT'] ];
					}
					array_multisort( $sort_ary, SORT_ASC, $merge_att_scr_ary_v2 );
					$head                 = array(
						array(
							self::DATA_HEADER_VERSION => 2,
						),
					);
					$merge_att_scr_ary_v2 = $this->wrap_array_merge( $head, $merge_att_scr_ary_v2 );
					$merge_att_scr_tsv    = $this->convert_array_to_tsv( $merge_att_scr_ary_v2 );

					$path = $heatmap_view_work_dir . $file_base_name . '-merge-as-v2.php';
					$this->wrap_put_contents( $path, $merge_att_scr_tsv );
				}

				if ( $merge_click_ary ) {
					$head            = array(
						array(
							self::DATA_HEADER_VERSION => 1,
						),
					);
					$merge_click_ary = $this->wrap_array_merge( $head, $merge_click_ary );
					$merge_click_tsv = $this->convert_array_to_tsv( $merge_click_ary );

					$path = $heatmap_view_work_dir . $file_base_name . '-merge-c.php';
					$this->wrap_put_contents( $path, $merge_click_tsv );
				}
				//QA ZERO
				// Separate マージファイル
				if ( $separate_merge_att_scr_ary_v2 ) {
					//各キーごとにソート
					foreach ( $separate_merge_att_scr_ary_v2 as $key => &$array ) {
						ksort( $array );
					}
					unset( $array ); // remove reference

					//ヘッダー付与
					$head                          = array(
						array(
							self::DATA_HEADER_VERSION => 2,
						),
					);
					$separate_merge_att_scr_ary_v2 = $this->wrap_array_merge( $head, $separate_merge_att_scr_ary_v2 );
					$separate_merge_att_scr_slz    = $this->wrap_serialize( $separate_merge_att_scr_ary_v2 );

					$path = $heatmap_view_work_dir . $file_base_name . '-separate-merge-as-v2-slz.php';
					$this->wrap_put_contents( $path, $separate_merge_att_scr_slz );
				}

				if ( $separate_merge_click_ary ) {
					$head                     = array(
						array(
							self::DATA_HEADER_VERSION => 1,
						),
					);
					$separate_merge_click_ary = $this->wrap_array_merge( $head, $separate_merge_click_ary );
					$separate_merge_click_tsv = $this->wrap_serialize( $separate_merge_click_ary );

					$path = $heatmap_view_work_dir . $file_base_name . '-separate-merge-c-slz.php';
					$this->wrap_put_contents( $path, $separate_merge_click_tsv );
				}
				//QA ZERO END

				// 情報を格納するinfoファイル
				// iniファイルと同じような書き方。シンプルにしたいが為にセクションは無し
				// $separate_time_on_pageをJSONに変換して追加
				//$json_separate_time_on_page = $this->wrap_json_encode($separate_time_on_page);
				$info_str  = '';
				$info_str .= 'base_url=' . $base_url . PHP_EOL;
				$info_str .= 'data_num=' . $data_num . PHP_EOL;
				$info_str .= 'wp_qa_type=' . $wp_qa_type . PHP_EOL;
				$info_str .= 'wp_qa_id=' . $wp_qa_id . PHP_EOL;
				$info_str .= 'version_no=' . $version_no . PHP_EOL;
				$info_str .= 'device_name=' . $device_name . PHP_EOL;
				$info_str .= 'time_on_page=' . $time_on_page . PHP_EOL;
				//$info_str .= 'separate_time_on_page=' . $json_separate_time_on_page;
				$info_str .= 'separate_data_num=' . $this->wrap_json_encode( $separate_data_num ) . PHP_EOL;
				$info_str .= 'separate_total_stay_time=' . $this->wrap_json_encode( $separate_total_stay_time ) . PHP_EOL;
				$info_str .= 'all_version_ary=' . $this->wrap_json_encode( $all_version_ary ) . PHP_EOL;
				$info_str .= 'device_version_ary=' . $this->wrap_json_encode( $device_version_ary ) . PHP_EOL;

				$path = $heatmap_view_work_dir . $file_base_name . '-info.php';
				$this->wrap_put_contents( $path, $info_str );
			}
		}

		if ( isset( $merge_att_scr_ary_v2 ) ) {
			unset( $merge_att_scr_ary_v2 );
		}
		if ( isset( $merge_click_ary ) ) {
			unset( $merge_click_ary );
		}
		if ( isset( $separate_merge_att_scr_ary_v2 ) ) {
			unset( $separate_merge_att_scr_ary_v2 );
		}
		if ( isset( $separate_merge_click_ary ) ) {
			unset( $separate_merge_click_ary );
		}
		if ( isset( $raw_p_ary ) ) {
			unset( $raw_p_ary );
		}
		if ( isset( $raw_c_ary ) ) {
			unset( $raw_c_ary );
		}

		if ( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}

		return $version_id;
	}

	/**
	 * Phase 1: 日付範囲でファイルを効率的に取得する
	 */
	private function get_files_in_date_range( $dir, $start_date, $end_date ) {
		static $cache = array();
		$cache_key    = $dir . '_' . $start_date . '_' . $end_date;

		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		$dirlist = $this->wrap_dirlist( $dir );
		if ( ! $dirlist ) {
			$cache[ $cache_key ] = array();
			return array();
		}

		$file_mapping = $this->file_mapping_cache( $dirlist );

		$filtered_files = array();
		foreach ( $file_mapping as $date => $filename ) {
			if ( $date >= $start_date && $date <= $end_date ) {
				$filtered_files[ $date ] = $filename;
			}
		}

		$cache[ $cache_key ] = $filtered_files;
		return $filtered_files;
	}

	/**
	 * ファイルサーチ高速化のための関数
	 */
	private function file_mapping_cache( $dirlist ) {
		$file_mapping_cache = array();
		foreach ( $dirlist as $file_info ) {
			if ( preg_match( '/^(\d{4}-\d{2}-\d{2})_/', $file_info['name'], $matches ) ) {
				// ファイル名から日付を抽出
				$file_date = $matches[1];

				// 日付が $date と一致する場合、ファイルマッピングキャッシュに追加
				if ( $file_date ) {
					$file_mapping_cache[ $file_date ] = $file_info['name'];
				}
			}
		}
		return $file_mapping_cache;
	}


	public function get_html_bar_text( $id, $html, $tooltip, $both = false, $link = '' ) {
		$clear_both = '';
		if ( $both ) {
			$clear_both = ' style="clear: both;"';
		}
		$link_start = '';
		$link_end   = '';
		if ( $link ) {
			$link_start = '<a href="' . $link . '" target="_blank" rel="noopener noreferrer"">';
			$link_end   = '</a>';
		}

		$element_name = str_replace( 'heatmap-bar-', '', $id );
		$bem_class    = 'heatmap-bar__item heatmap-bar__item--' . $element_name;

		return '<li class="' . $bem_class . '" data-id="' . $id . '"' . $clear_both . '>' .
				$link_start .
				'<span class="qahm-tooltip-bottom" data-qahm-tooltip="' . $tooltip . '">' .
				$html .
				'</span>' .
				$link_end .
				'</li>';
	}

	public function get_html_bar_checkbox( $id, $html, $tooltip, $is_check ) {
		$check_html = '';
		if ( $is_check ) {
			$check_html = ' checked';
		}

		$element_name = str_replace( 'heatmap-bar-', '', $id );
		$bem_class    = 'heatmap-bar__item heatmap-bar__item--checkbox heatmap-bar__item--' . $element_name;

		return '<li class="' . $bem_class . '" data-id="' . $id . '">' .
				'<label class="heatmap-bar__checkbox-label">' .
				'<span class="qahm-tooltip-bottom" data-qahm-tooltip="' . $tooltip . '">' .
				'<input class="heatmap-bar__checkbox-input ' . $id . '" type="checkbox"' . $check_html . ' disabled>' .
				$html .
				'</span>' .
				'</label>' .
				'</li>';
	}

	// ヒートマップ表示画面上で必要な初期情報を取得
	public function ajax_init_heatmap_view() {
		$data = array();

		global $wp_filesystem;

		$type           = $this->wrap_filter_input( INPUT_POST, 'type' );
		$id             = $this->wrap_filter_input( INPUT_POST, 'id' );
		$ver            = $this->wrap_filter_input( INPUT_POST, 'ver' );
		$dev            = $this->wrap_filter_input( INPUT_POST, 'dev' );
		$file_base_name = $this->wrap_filter_input( INPUT_POST, 'file_base_name' );

		// cap.phpは一日ごとの更新のため、リアルタイムに変わってほしい変数や
		// QAHMバーを初期化する際に必須の情報を受け取る
		$data['debug_level']   = QAHM_DEBUG_LEVEL;
		$data['debug']         = QAHM_DEBUG;
		$data['type']          = QAHM_TYPE;
		$data['type_zero']     = QAHM_TYPE_ZERO;
		$data['type_wp']       = QAHM_TYPE_WP;
		$data['locale']        = get_locale();
		$data['data_num']      = 0;            // データ数はcap.phpに移動予定
		$data['ver_max']       = 1;             // 後々修正 imai
		$data['heatmap']       = false;
		$data['attention']     = false;
		$data['free_rec_flag'] = false;

		$heatmap_view_work_dir = $this->get_data_dir_path( 'heatmap-view-work' );

		$data['merge_c']  = null;
		$data['merge_as'] = null;
		$data['data_num'] = 0;

		// infoファイルの読み込み
		$info_ary = $wp_filesystem->get_contents_array( $heatmap_view_work_dir . $file_base_name . '-info.php' );
		foreach ( $info_ary as $info ) {
			$info_param = $this->wrap_explode( '=', $info );
			if ( $this->wrap_count( $info_param ) === 2 ) {
				switch ( $info_param[0] ) {
					case 'data_num':
						$data['data_num'] = (int) $info_param[1];
						break;
				}
			}
		}

		$lists = $this->wrap_dirlist( $heatmap_view_work_dir );
		foreach ( $lists as $list ) {
			// 内部でデータヘッダーを削除しているが、今後js側で必要になるようならデータヘッダーも残す
			if ( $list['name'] === $file_base_name . '-merge-c.php' ) {
				$merge_c_str = $this->wrap_get_contents( $heatmap_view_work_dir . $list['name'] );
				$merge_c_ary = $this->convert_tsv_to_array( $merge_c_str );

				// ここでバージョンの差異を吸収

				// ヘッダー情報は不要なため削除。必要な時が来れば別ファイルに出力すればリスクも低そう
				unset( $merge_c_ary[ self::DATA_COLUMN_HEADER ] );
				$merge_c_ary = array_values( $merge_c_ary );

				// 型変換
				foreach ( $merge_c_ary as &$merge_c ) {
					$merge_c[ self::DATA_MERGE_CLICK_1['SELECTOR_X'] ] = (int) $merge_c[ self::DATA_MERGE_CLICK_1['SELECTOR_X'] ];
					$merge_c[ self::DATA_MERGE_CLICK_1['SELECTOR_Y'] ] = (int) $merge_c[ self::DATA_MERGE_CLICK_1['SELECTOR_Y'] ];
				}

				$data['merge_c'] = $merge_c_ary;

			} elseif ( $list['name'] === $file_base_name . '-merge-as-v2.php' ) {
				$merge_as_str = $this->wrap_get_contents( $heatmap_view_work_dir . $list['name'] );
				$merge_as_ary = $this->convert_tsv_to_array( $merge_as_str );

				// ここでバージョンの差異を吸収

				// ヘッダー情報は不要なため削除。必要な時が来れば別ファイルに出力すればリスクも低そう
				unset( $merge_as_ary[ self::DATA_COLUMN_HEADER ] );
				$merge_as_ary = array_values( $merge_as_ary );

				// 型変換
				foreach ( $merge_as_ary as &$merge_as ) {
					$merge_as[ self::DATA_MERGE_ATTENTION_SCROLL_2['STAY_HEIGHT'] ] = (int) $merge_as[ self::DATA_MERGE_ATTENTION_SCROLL_2['STAY_HEIGHT'] ];
					$merge_as[ self::DATA_MERGE_ATTENTION_SCROLL_2['STAY_TIME'] ]   = (float) $merge_as[ self::DATA_MERGE_ATTENTION_SCROLL_2['STAY_TIME'] ];
					$merge_as[ self::DATA_MERGE_ATTENTION_SCROLL_2['STAY_NUM'] ]    = (int) $merge_as[ self::DATA_MERGE_ATTENTION_SCROLL_2['STAY_NUM'] ];
					$merge_as[ self::DATA_MERGE_ATTENTION_SCROLL_2['EXIT_NUM'] ]    = (int) $merge_as[ self::DATA_MERGE_ATTENTION_SCROLL_2['EXIT_NUM'] ];
				}
				$data['merge_as_v2'] = $merge_as_ary;

			} elseif ( $list['name'] === $file_base_name . '-merge-as-v1.php' || $list['name'] === $file_base_name . '-merge-as-v1.php' ) {
				$merge_as_str = $this->wrap_get_contents( $heatmap_view_work_dir . $list['name'] );
				$merge_as_ary = $this->convert_tsv_to_array( $merge_as_str );

				// ここでバージョンの差異を吸収

				// ヘッダー情報は不要なため削除。必要な時が来れば別ファイルに出力すればリスクも低そう
				unset( $merge_as_ary[ self::DATA_COLUMN_HEADER ] );
				$merge_as_ary = array_values( $merge_as_ary );

				// 型変換
				foreach ( $merge_as_ary as &$merge_as ) {
					$merge_as[ self::DATA_MERGE_ATTENTION_SCROLL_1['PERCENT'] ]   = (int) $merge_as[ self::DATA_MERGE_ATTENTION_SCROLL_1['PERCENT'] ];
					$merge_as[ self::DATA_MERGE_ATTENTION_SCROLL_1['STAY_TIME'] ] = (float) $merge_as[ self::DATA_MERGE_ATTENTION_SCROLL_1['STAY_TIME'] ];
					$merge_as[ self::DATA_MERGE_ATTENTION_SCROLL_1['STAY_NUM'] ]  = (int) $merge_as[ self::DATA_MERGE_ATTENTION_SCROLL_1['STAY_NUM'] ];
					$merge_as[ self::DATA_MERGE_ATTENTION_SCROLL_1['EXIT_NUM'] ]  = (int) $merge_as[ self::DATA_MERGE_ATTENTION_SCROLL_1['EXIT_NUM'] ];
				}
				$data['merge_as_v1'] = $merge_as_ary;

			} elseif ( $data['merge_c'] && $data['merge_as'] ) {
				break;
			}
		}

		//ブラウザ側でマージデータのフォーマットを知るために必要
		$data['DATA_HEATMAP_SELECTOR_NAME'] = self::DATA_MERGE_CLICK_1['SELECTOR_NAME'];
		$data['DATA_HEATMAP_SELECTOR_X']    = self::DATA_MERGE_CLICK_1['SELECTOR_X'];
		$data['DATA_HEATMAP_SELECTOR_Y']    = self::DATA_MERGE_CLICK_1['SELECTOR_Y'];

		$data['DATA_ATTENTION_SCROLL_PERCENT_V1']   = self::DATA_MERGE_ATTENTION_SCROLL_1['PERCENT'];
		$data['DATA_ATTENTION_SCROLL_STAY_TIME_V1'] = self::DATA_MERGE_ATTENTION_SCROLL_1['STAY_TIME'];
		$data['DATA_ATTENTION_SCROLL_STAY_NUM_V1']  = self::DATA_MERGE_ATTENTION_SCROLL_1['STAY_NUM'];
		$data['DATA_ATTENTION_SCROLL_EXIT_NUM_V1']  = self::DATA_MERGE_ATTENTION_SCROLL_1['EXIT_NUM'];

		$data['DATA_ATTENTION_SCROLL_STAY_HEIGHT_V2'] = self::DATA_MERGE_ATTENTION_SCROLL_2['STAY_HEIGHT'];
		$data['DATA_ATTENTION_SCROLL_STAY_TIME_V2']   = self::DATA_MERGE_ATTENTION_SCROLL_2['STAY_TIME'];
		$data['DATA_ATTENTION_SCROLL_STAY_NUM_V2']    = self::DATA_MERGE_ATTENTION_SCROLL_2['STAY_NUM'];
		$data['DATA_ATTENTION_SCROLL_EXIT_NUM_V2']    = self::DATA_MERGE_ATTENTION_SCROLL_2['EXIT_NUM'];

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
		echo $this->wrap_json_encode( $data );
		die();
	}

	/**
	 * ページバージョンを手動で更新するAJAXメソッド
	 *
	 * 指定されたpage_idに対して、全デバイス分のバージョンを更新する
	 */
	public function ajax_update_page_version() {
		global $qahm_log;
		global $wpdb;

		try {
			$page_id = (int) $this->wrap_filter_input( INPUT_POST, 'page_id' );

			if ( ! $page_id ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
				echo $this->wrap_json_encode(
					array(
						'success' => false,
						'message' => esc_html__( 'page_idが必要です', 'qa-heatmap-analytics' ),
					)
				);
				die();
			}

			$version_manager = new QAHM_Version_Manager();

			// page_idからURLを取得
			$table_name = $wpdb->prefix . 'qa_pages';
			$query      = 'SELECT url FROM ' . $table_name . ' WHERE page_id = %d';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This SQL query uses placeholders and $wpdb->prepare(), but it may trigger warnings due to the dynamic construction of the SQL string. Direct database call is necessary in this case due to the complexity of the SQL query. Caching would not provide significant performance benefits in this context.
			$qa_pages = $wpdb->get_results( $wpdb->prepare( $query, $page_id ), ARRAY_A );

			if ( ! $qa_pages || empty( $qa_pages[0]['url'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
				echo $this->wrap_json_encode(
					array(
						'success' => false,
						'message' => esc_html__( 'URLの取得に失敗しました', 'qa-heatmap-analytics' ),
					)
				);
				die();
			}

			$url = $qa_pages[0]['url'];

			$device_ids   = array_column( QAHM_DEVICES, 'id' );
			$device_names = array_column( QAHM_DEVICES, 'name' );
			$devices_map  = array_combine( $device_ids, $device_names );

			$results = array();
			foreach ( $devices_map as $device_id => $device_name ) {
				$base_html = $version_manager->curl_get( $url, 10, 10, $device_name );

				if ( $base_html ) {
					$new_version             = $version_manager->refresh_version_for_dev( $page_id, $device_id, $base_html );
					$results[ $device_name ] = $new_version ? $new_version : false;
				} else {
					$results[ $device_name ] = false;
				}

				usleep( 500000 ); // 0.5秒待機
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
			echo $this->wrap_json_encode(
				array(
					'success' => true,
					'results' => $results, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Array of internal version data, properly encoded by wrap_json_encode().
				)
			);

		} catch ( Exception $e ) {
			$qahm_log->error( $e->getMessage() );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
			echo $this->wrap_json_encode(
				array(
					'success' => false,
					'message' => esc_html( $e->getMessage() ),
				)
			);
		} finally {
			die();
		}
	}

	//QA ZERO
	public function ajax_get_separate_data() {
		// Check nonce, authentication, or any other necessary verification here.

		// Get the version_id from the request.
		$file_base_name = $this->wrap_filter_input( INPUT_POST, 'file_base_name' );
		$data           = $this->get_separate_data( $file_base_name );
		// Return the data as JSON.
		wp_send_json( $data );
	}

	public function get_separate_data( $file_base_name ) {
		// Get the heatmap view work directory.
		$heatmap_view_work_dir = $this->get_data_dir_path( 'heatmap-view-work' );

		// Initialize the data array.
		$data = array(
			'merge_c'  => null,
			'merge_as' => null,
		);

		// Define the file paths.
		$merge_c_file  = $heatmap_view_work_dir . $file_base_name . '-separate-merge-c-slz.php';
		$merge_as_file = $heatmap_view_work_dir . $file_base_name . '-separate-merge-as-v2-slz.php';

		// Check if the files exist and read the data from the files.
		if ( file_exists( $merge_c_file ) ) {
			$merge_c_data = $this->wrap_get_contents( $merge_c_file );
			// Unserialize the data if it's not empty.
			if ( ! empty( $merge_c_data ) ) {
				$data['merge_c'] = $this->wrap_unserialize( $merge_c_data );
			}
		}

		if ( file_exists( $merge_as_file ) ) {
			$merge_as_data = $this->wrap_get_contents( $merge_as_file );
			// Unserialize the data if it's not empty.
			if ( ! empty( $merge_as_data ) ) {
				$data['merge_as'] = $this->wrap_unserialize( $merge_as_data );
			}
		}

		return $data;
	}
	//QA ZERO END
}
