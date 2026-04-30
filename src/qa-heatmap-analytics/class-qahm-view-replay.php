<?php
defined( 'ABSPATH' ) || exit;
/**
 * リプレイビューでの操作をやりやすくするクラス
 *
 * @package qa_heatmap
 */

$GLOBALS['qahm_view_replay'] = new QAHM_View_Replay();

class QAHM_View_Replay extends QAHM_View_Base {

	public function __construct() {
		$this->regist_ajax_func( 'ajax_create_replay_file_to_raw_data' );
		$this->regist_ajax_func( 'ajax_create_replay_file_to_data_base' );
		$this->regist_ajax_func( 'ajax_get_ogp_image' );

		add_action( 'init', array( $this, 'init_wp_filesystem' ) );
	}

	public function get_work_dir_url() {
		return parent::get_data_dir_url() . 'replay-view-work/';
	}

	public function get_event_array( $work_base_name, $replay_id ) {
		global $wp_filesystem;
		$path = $this->get_data_dir_path( 'replay-view-work' ) . $work_base_name . '_' . $replay_id . '-e.php';
		if ( ! $wp_filesystem->exists( $path ) ) {
			return null;
		}

		$event_tsv = $this->wrap_get_contents( $path );
		$event_ary = $this->convert_tsv_to_array( $event_tsv );

		// バージョンチェック
		//if ( 1 === (int) $event_ary[self::DATA_COLUMN_HEADER][DATA_HEADER_VERSION] ) {
		//  unset( $event_ary[ self::DATA_COLUMN_SECURITY ] );
		//  $event_ary = array_values( $event_ary );
		//}

		// body部はTIMEの値を元にソートする
		$sort_ary = array();
		foreach ( $event_ary as $index => $event ) {
			if ( $index === self::DATA_COLUMN_HEADER ) {
				$sort_ary[ $index ] = 0;
			} else {
				$sort_ary[ $index ] = (int) $event[ self::DATA_EVENT_1['TIME'] ];
			}
		}

		// 3.配列をソート
		array_multisort( $sort_ary, SORT_ASC, SORT_NUMERIC, $event_ary );

		return $event_ary;
	}


	// 滞在時間を求める
	public function get_time_on_page( $work_base_name, $replay_id ) {
		global $wp_filesystem;
		$path = $this->get_data_dir_path( 'replay-view-work' ) . $work_base_name . '_' . $replay_id . '-e.php';
		if ( ! $wp_filesystem->exists( $path ) ) {
			return null;
		}

		$event_tsv = $wp_filesystem->get_contents( $path );
		$event_ary = $this->convert_tsv_to_array( $event_tsv );

		// バージョンチェック
		//if ( 1 === (int) $event_ary[self::DATA_COLUMN_HEADER][DATA_HEADER_VERSION] ) {
		//  unset( $event_ary[ self::DATA_COLUMN_SECURITY ] );
		//  $event_ary = array_values( $event_ary );
		//}

		// body部はTIMEの値を元にソートする
		$sort_ary = array();
		foreach ( $event_ary as $index => $event ) {
			if ( $index === self::DATA_COLUMN_HEADER ) {
				$sort_ary[ $index ] = 0;
			} else {
				$sort_ary[ $index ] = (int) $event[ self::DATA_EVENT_1['TIME'] ];
			}
		}

		// 3.配列をソート
		array_multisort( $sort_ary, SORT_ASC, SORT_NUMERIC, $event_ary );

		return $event_ary;
	}

	/**
	 * infoファイルを読み込み、扱いやすい形に整形した上で配列の形にして返す
	 */
	public function get_contents_info( $path ) {
		global $wp_filesystem;

		if ( ! $wp_filesystem->exists( $path ) ) {
			throw new Exception( 'Info file not found.' );
		}
		$info_ary = $this->wrap_unserialize( $this->wrap_get_contents( $path ) );

		// キーが存在しても中身が空のケースが存在する可能性はあるので、2段階のチェックをかける
		$info_ary['base_url']       = $this->array_key_exists_val( 'base_url', $info_ary );
		$info_ary['qa_id']          = $this->array_key_exists_val( 'qa_id', $info_ary, esc_html__( 'Unknown', 'qa-heatmap-analytics' ) );
		$info_ary['qa_id']          = $this->wrap_substr( $info_ary['qa_id'], 12 );
		$info_ary['reader_id']      = $this->array_key_exists_val( 'reader_id', $info_ary );
		$info_ary['country']        = $this->array_key_exists_val( 'country', $info_ary, esc_html__( 'Unknown', 'qa-heatmap-analytics' ) );
		$info_ary['first_referrer'] = $this->array_key_exists_val( 'first_referrer', $info_ary, esc_html__( 'Unknown', 'qa-heatmap-analytics' ) );
		$info_ary['is_new_user']    = $this->array_key_exists_val( 'is_new_user', $info_ary, esc_html__( 'Unknown', 'qa-heatmap-analytics' ) );
		$info_ary['browser']        = $this->array_key_exists_val( 'browser', $info_ary, esc_html__( 'Unknown', 'qa-heatmap-analytics' ) );
		$info_ary['os']             = $this->array_key_exists_val( 'os', $info_ary, esc_html__( 'Unknown', 'qa-heatmap-analytics' ) );
		$info_ary['device']         = $this->array_key_exists_val( 'device', $info_ary, esc_html__( 'Unknown', 'qa-heatmap-analytics' ) );
		$info_ary['access_time']    = $this->array_key_exists_val( 'access_time', $info_ary, esc_html__( 'Unknown', 'qa-heatmap-analytics' ) );
		$info_ary['data_type']      = $this->array_key_exists_val( 'data_type', $info_ary );

		$info_ary['page_array'] = $this->array_key_exists_val( 'page_array', $info_ary );
		if ( $info_ary['page_array'] ) {
			$page_ary = &$info_ary['page_array'];
			for ( $i = 0, $page_max = $this->wrap_count( $page_ary ); $i < $page_max; $i++ ) {
				$page_ary[ $i ]['url']         = $this->array_key_exists_val( 'url', $page_ary[ $i ] );
				$page_ary[ $i ]['title']       = $this->array_key_exists_val( 'title', $page_ary[ $i ], esc_html__( 'Unknown', 'qa-heatmap-analytics' ) );
				$page_ary[ $i ]['access_time'] = $this->array_key_exists_val( 'access_time', $page_ary[ $i ], esc_html__( 'Unknown', 'qa-heatmap-analytics' ) );
			}
		}

		return $info_ary;
	}

	/**
	 * リプレイ表示用のファイルを作成
	 * この関数でtry-catchによるネストを削減
	 */
	public function ajax_create_replay_file_to_raw_data() {
		try {
			//QA ZERO STSRT
			$work_base_name = $this->wrap_filter_input( INPUT_POST, 'work_base_name' );
			$replay_id      = (int) $this->wrap_filter_input( INPUT_POST, 'replay_id' );
			$url            = $this->create_replay_file_to_raw_data( $work_base_name, $replay_id );
			//QA ZERO END
			echo esc_url_raw( $url );

		} catch ( Exception $e ) {
			$error = 'error: ' . $e->getMessage();
			echo esc_html( $error );

		} finally {
			die();
		}
	}

	/**
	 * 生データからリプレイ表示用のファイルを作成
	 */
	public function create_replay_file_to_raw_data( $work_base_name, $replay_id ) {
		global $qahm_log;
		global $wp_filesystem;

		//QA ZERO STSRT
		//      $work_base_name    = $this->wrap_filter_input( INPUT_POST, 'work_base_name' );
		//      $replay_id         = (int) $this->wrap_filter_input( INPUT_POST, 'replay_id' );
		//QA ZERO END
		$session_file_name = $work_base_name . '.php';
		$qa_id             = strstr( $work_base_name, '_', true );
		$work_file_name    = $work_base_name . '_' . $replay_id;

		$readers_dir_path = $this->get_data_dir_path( 'readers' );
		$replay_dir_path  = $this->get_data_dir_path( 'replay-view-work' );
		$info_path        = $replay_dir_path . $work_file_name . '-info.php';
		$cap_path         = $replay_dir_path . $work_file_name . '-cap.php';

		$replay_view_url  = plugin_dir_url( __FILE__ ) . 'replay-view.php' . '?';
		$replay_view_url .= 'work_base_name=' . $work_base_name . '&';
		$replay_view_url .= 'replay_id=' . $replay_id;

		if ( $wp_filesystem->exists( $readers_dir_path . 'finish/' . $session_file_name ) ) {
			$session_ary = $this->wrap_unserialize( $this->wrap_get_contents( $readers_dir_path . 'finish/' . $session_file_name ) );
		} elseif ( $wp_filesystem->exists( $readers_dir_path . 'dbin/' . $session_file_name ) ) {
			$session_ary = $this->wrap_unserialize( $this->wrap_get_contents( $readers_dir_path . 'dbin/' . $session_file_name ) );
		} else {
			throw new Exception( 'session file not found.' );
		}

		// 旧データ対策
		if ( ! isset( $session_ary['head']['version'] ) ) {
			throw new Exception( 'session file is an older version.' );
		}

		$data_ver    = (int) $session_ary['head']['version'];
		$tracking_id = '';
		$dev_name    = '';
		$base_url    = '';
		$pv_num      = 0;

		// replay_idは最低値が1なのでindexに変換する際マイナス1している
		$replay_id_idx = $replay_id - 1;

		// パラメータ取得
		$tracking_id = $session_ary['head']['tracking_id'];
		$dev_name    = $session_ary['head']['device_name'];

		$body        = $session_ary['body'][ $replay_id_idx ];
		$base_url    = $body['page_url'];
		$type        = $body['page_type'];
		$id          = (int) $body['page_id'];
		$access_time = $body['access_time'];

		// 対象ページの合計pv数を調べる
		for ( $i = 0; $i <= $replay_id_idx; $i++ ) {
			if ( $base_url === $session_ary['body'][ $i ]['page_url'] ||
				( $type === $session_ary['body'][ $i ]['page_type'] && $id === (int) $session_ary['body'][ $i ]['page_id'] ) ) {
				++$pv_num;
			}
		}

		// rawデータを読み込んでreplayディレクトリにコピー
		// ファイル名はreadersのリストの順に並べ替えて後々の処理を楽にする
		//QA ZERO START
		$url_hash      = $this->get_url_hash( $base_url );
		$data_dir_path = $this->get_data_dir_path();
		$raw_dir_path  = $data_dir_path . $tracking_id . '/' . $url_hash . '/';
		$raw_file_base = $raw_dir_path . $qa_id . '_' . $access_time;

		// クロスドメイン対応: セッションヘッダーの tracking_id にファイルがない場合、他の tracking_id を探す
		if ( ! $wp_filesystem->exists( $raw_file_base . '-p.php' ) &&
			! $wp_filesystem->exists( $raw_file_base . '-p-done.php' ) &&
			! $wp_filesystem->exists( $raw_file_base . '-e.php' ) &&
			! $wp_filesystem->exists( $raw_file_base . '-e-done.php' ) ) {
			$sitemanage = $this->wrap_get_option( 'sitemanage' );
			if ( $sitemanage ) {
				foreach ( $sitemanage as $site ) {
					if ( ! isset( $site['tracking_id'] ) || $site['tracking_id'] === $tracking_id ) {
						continue;
					}
					$try_dir  = $data_dir_path . $site['tracking_id'] . '/' . $url_hash . '/';
					$try_base = $try_dir . $qa_id . '_' . $access_time;
					if ( $wp_filesystem->exists( $try_base . '-p.php' ) ||
						$wp_filesystem->exists( $try_base . '-p-done.php' ) ||
						$wp_filesystem->exists( $try_base . '-e.php' ) ||
						$wp_filesystem->exists( $try_base . '-e-done.php' ) ) {
						$raw_dir_path  = $try_dir;
						$raw_file_base = $try_base;
						break;
					}
				}
			}
		}
		$time_on_page = 0;

		$raw_p_time = '';
		$raw_p_tsv  = false;
		if ( $wp_filesystem->exists( $raw_file_base . '-p.php' ) ) {
			$raw_p_tsv = $this->wrap_get_contents( $raw_file_base . '-p.php' );
		} elseif ( $wp_filesystem->exists( $raw_file_base . '-p-done.php' ) ) {
			$raw_p_tsv = $this->wrap_get_contents( $raw_file_base . '-p-done.php' );
		}
		if ( $raw_p_tsv ) {
			$raw_p_time = $this->get_time_on_page_to_raw_p( $raw_p_tsv );
			$this->wrap_put_contents( $replay_dir_path . $work_file_name . '-p.php', $raw_p_tsv );
			//$this->wrap_put_contents( $replay_dir_path . $qa_id . '_' . $access_time . '-p.php', $raw_p_tsv );
		}
		$raw_e_time      = '';
		$raw_e_file_name = '';
		$raw_e_tsv       = false;
		if ( $wp_filesystem->exists( $raw_file_base . '-e.php' ) ) {
			$raw_e_tsv       = $this->wrap_get_contents( $raw_file_base . '-e.php' );
			$raw_e_time      = $this->get_time_on_page_to_raw_e( $raw_e_tsv );
			$raw_e_file_name = $raw_file_base . '-e.php';
		} elseif ( $wp_filesystem->exists( $raw_file_base . '-e-done.php' ) ) {
			$raw_e_tsv       = $this->wrap_get_contents( $raw_file_base . '-e-done.php' );
			$raw_e_time      = $this->get_time_on_page_to_raw_e( $raw_e_tsv );
			$raw_e_file_name = $raw_file_base . '-e-done.php';
		}

		//      $raw_dir_path    = $this->get_raw_dir_path( $type, $id, $dev_name, $tracking_id );
		//      $dirlist         = $this->wrap_dirlist( $raw_dir_path );
		//      $match_name      = $work_base_name . '_' . $pv_num;
		//      $time_on_page    = 0;
		//      $raw_p_time      = 0;
		//      $raw_e_time      = 0;
		//      $raw_e_file_name = null;
		//      foreach ( $dirlist as $file ) {
		//          if ( strncmp( $match_name, $file['name'], $this->wrap_strlen( $match_name ) ) !== 0 ) {
		//              continue;
		//          }
		//
		//          if ( $this->wrap_strpos( $file['name'], '-p.php' ) ){
		//              $raw_p_tsv      = $this->wrap_get_contents( $raw_dir_path . $file['name'] );
		//              $raw_p_time     = $this->get_time_on_page_to_raw_p( $raw_p_tsv );
		//              $this->wrap_put_contents( $replay_dir_path . $work_file_name . '-p.php', $raw_p_tsv );
		//          }
		//
		//          if ( $this->wrap_strpos( $file['name'], '-e.php' ) ){
		//              $raw_e_tsv       = $this->wrap_get_contents( $raw_dir_path . $file['name'] );
		//              $raw_e_time      = $this->get_time_on_page_to_raw_e( $raw_e_tsv );
		//              $raw_e_file_name = $file['name'];
		//          }
		//
		//          if ( $raw_p_time !== 0 && $raw_e_time !== 0 ) {
		//              break;
		//          }
		//      }

		// イベントファイルを発見した場合、滞在時間を最終フレームに追加してリプレイディレクトリに保存
		if ( $raw_e_file_name ) {
			$time_on_page = max( $raw_p_time, $raw_e_time ) * 1000;

			// raw_eの最終行に何もしない行を追加してworkディレクトリに保存
			$raw_e_file_cont  = $wp_filesystem->get_contents( $raw_e_file_name );
			$raw_e_file_cont .= PHP_EOL . '@' . "\t" . $time_on_page;
			$wp_filesystem->put_contents( $replay_dir_path . $work_file_name . '-e.php', $raw_e_file_cont );
			//          $wp_filesystem->put_contents( $replay_dir_path . $qa_id . '_' . $access_time . '-e.php', $raw_e_file_cont );
		}

		// base_html取得
		$response      = $this->wrap_remote_get( $base_url, $dev_name );
		$response_code = isset( $response['response']['code'] ) ? intval( $response['response']['code'] ) : 0;
		if ( is_wp_error( $response ) ) {
			throw new Exception( 'wp_remote_get failed.' );
		}
		if ( ! ( $response_code === 200 || $response_code === 404 ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is internal, not user-facing output.
			throw new Exception( 'wp_remote_get status error. status: ' . $response_code );
		}
		$base_html = $response['body'];

		// 最適化
		$opt_php = $this->opt_base_html( $cap_path, $base_html, $base_url, $dev_name );

		// cap
		$wp_filesystem->put_contents( $cap_path, $opt_php );

		// info 準備
		$ses_head    = $session_ary['head'];
		$ses_body    = $session_ary['body'];
		$page_ary    = array();
		$access_time = 0;
		$browser     = $this->user_agent_to_browser_name( $ses_head['user_agent'] );
		$os          = $this->user_agent_to_os_name( $ses_head['user_agent'] );
		$device      = $this->user_agent_to_device_name( $ses_head['user_agent'] );
		$body_cnt    = $this->wrap_count( $ses_body );
		if ( 0 < $body_cnt ) {
			global $qahm_time;
			$access_time = $ses_body[0]['access_time'];
			$access_time = $qahm_time->unixtime_to_str( $access_time );
			for ( $i = 0, $body_cnt = $this->wrap_count( $ses_body ); $i < $body_cnt; $i++ ) {
				$page_ary[ $i ]['url']   = $ses_body[ $i ]['page_url'];
				$page_ary[ $i ]['title'] = $ses_body[ $i ]['page_title'];
			}
		}

		// info put_contents
		$info_ary                   = array();
		$info_ary['base_url']       = $base_url;
		$info_ary['qa_id']          = $qa_id;
		$info_ary['country']        = $ses_head['country'];
		$info_ary['first_referrer'] = $ses_head['first_referrer'];
		$info_ary['is_new_user']    = $ses_head['is_new_user'];
		$info_ary['browser']        = $browser;
		$info_ary['os']             = $os;
		$info_ary['device']         = $device;
		$info_ary['access_time']    = $access_time;
		$info_ary['time_on_page']   = $time_on_page;
		$info_ary['page_array']     = $page_ary;
		$info_ary['data_type']      = 'readers';
		$this->wrap_put_contents( $info_path, $this->wrap_serialize( $info_ary ) );

		return $replay_view_url;
	}

	/**
	 * リプレイ表示用のファイルを作成
	 */
	public function ajax_create_replay_file_to_data_base() {
		try {

			// 別ユーザーが同時刻、同ページ遷移数でアクセスしているケースも想定し、reader_idはaccess_timeから求めるのではなく引数で受け取る
			$reader_id   = (int) $this->wrap_filter_input( INPUT_POST, 'reader_id' );
			$access_time = (int) $this->wrap_filter_input( INPUT_POST, 'access_time' );
			$replay_id   = (int) $this->wrap_filter_input( INPUT_POST, 'replay_id' );

			$res = $this->create_replay_file_to_data_base( $reader_id, $access_time, $replay_id );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
			echo $this->wrap_json_encode( $res );

		} catch ( Exception $e ) {
			global $qahm_log;
			$log = $qahm_log->error( $e->getMessage() );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
			echo $this->wrap_json_encode( $log );

		} finally {
			die();
		}
	}


	/**
	 * データベースからリプレイ表示用のファイルを作成
	 * ※今はviewファイルからもデータを取得しているので、完全なデータベース取得ではない
	 */
	public function create_replay_file_to_data_base( $reader_id, $access_time, $replay_id ) {
		global $qahm_log;
		global $wp_filesystem;
		global $qahm_data_api;
		global $qahm_db;
		global $qahm_time;

		// pv_log基準でデータを取る
		//$before_day = $qahm_time->xday_str( -2, $access_time ) . ' 00:00:00';
		//$after_day  = $qahm_time->xday_str( 2, $access_time ) . ' 23:59:59';

		// zeroではaccess_timeはunix_timeになっているので日付文字列変換してからxday_str関数にわたす
		$access_date = $qahm_time->xday_str( -2, $qahm_time->unixtime_to_str( $access_time ) ) . ' 00:00:00';
		$before_day  = $qahm_time->xday_str( -2, $access_date );
		$after_day   = $qahm_time->xday_str( 2, $access_date );
		$table_name  = $qahm_db->prefix . 'view_pv';
		//$table_name = $wpdb->prefix . 'qa_pv_log';
		//$view_pvs   = $qahm_data_api->select_data( $table_name, '*', 'date = between ' . $before_day . ' and ' . $after_day );
		//$query = "SELECT * FROM " . $table_name . " WHERE access_time BETWEEN %s AND %s";
		//$query = $qahm_db->prepare( $query, $before_day, $after_day );
		//$view_pvs = $qahm_db->get_results( $query );
		//if ( ! $view_pvs ) {
		//  return;
		//}

		// 日付ごとのデータになっているので展開（今だけの処理になるはず）
		$temp_ary = $qahm_data_api->select_data( $table_name, '*', 'date = between ' . $before_day . ' and ' . $after_day );
		if ( ! $temp_ary ) {
			return;
		}
		$view_pvs = array();
		foreach ( $temp_ary as $temp_ary2 ) {
			foreach ( $temp_ary2 as $temp_ary3 ) {
				$view_pvs[] = $temp_ary3;
			}
		}
		// 今だけの処理 ここまで

		// 対象のデータを求める
		$pv_ary      = array();
		$tar_idx     = -1;
		$tar_pv      = -1;
		$view_pv_max = $this->wrap_count( $view_pvs );
		foreach ( $view_pvs as $view_idx => $view_pv ) {
			if ( (int) $view_pv['reader_id'] !== $reader_id ) {
				continue;
			}
			if ( $view_pv['access_time'] === $access_time ) {
				$tar_idx  = $view_idx;
				$tar_pv   = $view_pv['pv'];
				$pv_ary[] = $view_pv;
				break;
			}
		}
		if ( ! $pv_ary ) {
			return;
		}

		// 対象データから同一セッションのみの配列を作成
		if ( $tar_idx > 0 ) {
			for ( $i = $tar_idx - 1, $pv = $tar_pv; $i >= 0; $i-- ) {
				--$pv;
				if ( (int) $view_pvs[ $i ]['pv'] !== $pv ) {
					break;
				}
				$pv_ary[] = $view_pvs[ $i ];
			}
		}

		if ( $tar_idx < $view_pv_max ) {
			for ( $i = $tar_idx + 1, $pv = $tar_pv; $i < $view_pv_max; $i++ ) {
				++$pv;
				if ( (int) $view_pvs[ $i ]['pv'] !== $pv ) {
					break;
				}
				$pv_ary[] = $view_pvs[ $i ];
			}
		}

		// PVが低い順にソート
		$sort = array();
		foreach ( $pv_ary as $key => $value ) {
			$sort[ $key ] = (int) $value['pv'];
		}
		array_multisort( $sort, SORT_ASC, $pv_ary );

		// 遷移した全てのページの情報を格納
		$base_url = '';
		$page_ary = array();
		for ( $i = 0, $pv_max = $this->wrap_count( $pv_ary ); $i < $pv_max; $i++ ) {
			$table_name = $qahm_db->prefix . 'qa_pages';
			$query      = 'SELECT url,title FROM ' . $table_name . ' WHERE page_id = %d';
			$query      = $qahm_db->prepare( $query, $pv_ary[ $i ]['page_id'] );
			$qa_pages   = $qahm_db->get_results( $query, ARRAY_A );
			//$qa_pages   = $qahm_data_api->select_data( $table_name, '*', 'id = ' . $pv_ary[$i]['page_id'] );
			if ( ! $qa_pages ) {
				return;
			}
			$qa_page = $qa_pages[0];

			$page_ary[ $i ]['url']         = $qa_page['url'];
			$page_ary[ $i ]['title']       = $qa_page['title'];
			$page_ary[ $i ]['access_time'] = $pv_ary[ $i ]['access_time'];

			if ( $i + 1 === $replay_id ) {
				$base_url = $qa_page['url'];
			}
		}

		// 基準となるファイル名は1ページ目のpv id基準で作成する
		$replay_dir_path = $this->get_data_dir_path( 'replay-view-work' );
		$work_base_name  = $pv_ary[0]['pv_id'];
		$work_file_name  = $work_base_name . '_' . $replay_id;

		$replay_dir_path = $this->get_data_dir_path( 'replay-view-work' );
		$info_path       = $replay_dir_path . $work_file_name . '-info.php';
		$cap_path        = $replay_dir_path . $work_file_name . '-cap.php';
		$raw_p_path      = $replay_dir_path . $work_file_name . '-p.php';
		$raw_e_path      = $replay_dir_path . $work_file_name . '-e.php';

		$replay_view_url  = plugin_dir_url( __FILE__ ) . 'replay-view.php' . '?';
		$replay_view_url .= 'work_base_name=' . $work_base_name . '&';
		$replay_view_url .= 'replay_id=' . $replay_id;

		foreach ( $pv_ary as $pv ) {

			// access_timeがajax送信された内容と同一のpvのみcapを作成
			if ( $pv['access_time'] === $access_time ) {

				$dev_name       = $this->device_id_to_device_name( $pv['device_id'] );
				$base_html      = null;
				$first_referrer = null;

				if ( $pv['version_id'] ) {
					$table_name               = $qahm_db->prefix . 'view_page_version_hist';
					$query                    = 'SELECT * FROM ' . $table_name . ' WHERE version_id = %d';
					$query                    = $qahm_db->prepare( $query, $pv['version_id'] );
					$qa_page_version_hist_ary = $qahm_db->get_results( $query, ARRAY_A );
					if ( $qa_page_version_hist_ary ) {
						$qa_page_version_hist = $qa_page_version_hist_ary[0];
						$base_html            = $qa_page_version_hist['base_html'];
					}
				}

				if ( $pv['source_id'] > 0 ) {
					$table_name        = $qahm_db->prefix . 'qa_utm_sources';
					$query             = 'SELECT * FROM ' . $table_name . ' WHERE source_id = %d';
					$query             = $qahm_db->prepare( $query, $pv['source_id'] );
					$qa_utm_source_ary = $qahm_db->get_results( $query, ARRAY_A );
					if ( ! $qa_utm_source_ary ) {
						$qa_utm_source  = $qa_utm_source_ary[0];
						$first_referrer = $qa_utm_source['referer'];
					}
				}

				$table_name     = $qahm_db->prefix . 'qa_readers';
				$query          = 'SELECT * FROM ' . $table_name . ' WHERE reader_id = %d';
				$query          = $qahm_db->prepare( $query, $reader_id );
				$qa_readers_ary = $qahm_db->get_results( $query, ARRAY_A );
				if ( $qa_readers_ary ) {
					$qa_readers = $qa_readers_ary[0];
				}

				// base_html取得
				if ( ! $base_html ) {
					$response = $this->wrap_remote_get( $base_url, $dev_name );
					if ( is_wp_error( $response ) ) {
						throw new Exception( 'wp_remote_get failed.' );
					}
					if ( ! ( $response['response']['code'] === 200 || $response['response']['code'] === 404 ) ) {
						throw new Exception( 'wp_remote_get status error.' );
					}
					$base_html = $response['body'];
				}

				// 最適化
				$opt_php = $this->opt_base_html( $cap_path, $base_html, $base_url, $dev_name );

				// cap
				$wp_filesystem->put_contents( $cap_path, $opt_php );

				$raw_p_time = 0;
				$raw_e_time = 0;
				$pv_id      = $pv['pv_id'];
				// クロスドメイン対応: 全トラッキングIDの raw データを参照する（select_data が all から PV を取得するのと一致させる）
				$view_pv_dir = $this->get_data_dir_path( 'view' ) . 'all' . '/view_pv/';

				// raw_p ※データが空の場合、保存しない
				if ( $pv['is_raw_p'] ) {
					// dbから読み込んだデータなので再度セキュリティ対策コードを付与
					//$this->wrap_put_contents( $raw_e_path, $pv->raw_e );

					$raw_p_dirlist = $this->wrap_dirlist( $view_pv_dir . 'raw_p/' );
					$raw_p_tsv     = null;
					foreach ( $raw_p_dirlist as $raw_p_fileobj ) {
						preg_match( '/_(\d+)-(\d+)_/', $raw_p_fileobj['name'], $matches );
						if ( ! ( $matches[1] <= $pv_id && $matches[2] >= $pv_id ) ) {
							continue;
						}

						$raw_p_data_ary = $this->wrap_unserialize( $this->wrap_get_contents( $view_pv_dir . 'raw_p/' . $raw_p_fileobj['name'] ) );

						foreach ( $raw_p_data_ary as $raw_p_data ) {
							if ( $pv_id !== $raw_p_data['pv_id'] ) {
								continue;
							}
							$raw_p_tsv = $raw_p_data['raw_p'];
							break;
						}

						if ( $raw_p_tsv ) {
							break;
						}
					}
					if ( $raw_p_tsv ) {
						$raw_p_time = $this->get_time_on_page_to_raw_p( $raw_p_tsv );
						$this->wrap_put_contents( $raw_p_path, $raw_p_tsv );
					}
				}

				// raw_e ※データが空の場合、保存しない
				if ( $pv['is_raw_e'] ) {
					// dbから読み込んだデータなので再度セキュリティ対策コードを付与
					//$this->wrap_put_contents( $raw_e_path, $pv->raw_e );

					$raw_e_dirlist = $this->wrap_dirlist( $view_pv_dir . 'raw_e/' );
					$raw_e         = null;
					foreach ( $raw_e_dirlist as $raw_e_fileobj ) {
						preg_match( '/_(\d+)-(\d+)_/', $raw_e_fileobj['name'], $matches );
						if ( ! ( $matches[1] <= $pv_id && $matches[2] >= $pv_id ) ) {
							continue;
						}

						$raw_e_data_ary = $this->wrap_unserialize( $this->wrap_get_contents( $view_pv_dir . 'raw_e/' . $raw_e_fileobj['name'] ) );

						foreach ( $raw_e_data_ary as $raw_e_data ) {
							if ( $pv_id !== $raw_e_data['pv_id'] ) {
								continue;
							}
							$raw_e = $raw_e_data['raw_e'];
							break;
						}

						if ( $raw_e ) {
							break;
						}
					}
					if ( $raw_e ) {
						$raw_e_time   = $this->get_time_on_page_to_raw_e( $raw_e );
						$time_on_page = max( $raw_p_time, $raw_e_time );

						//msに変換
						$time_on_page *= 1000;

						// raw_eの最終行に何もしない行を追加してworkディレクトリに保存
						$raw_e .= PHP_EOL . '@' . "\t" . $time_on_page;
						$this->wrap_put_contents( $raw_e_path, $raw_e );
					}
				}

				// info
				$info_ary                   = array();
				$info_ary['base_url']       = $base_url;
				$info_ary['reader_id']      = $reader_id;
				$info_ary['qa_id']          = $qa_readers['qa_id'];
				$info_ary['os']             = $qa_readers['UAos'];
				$info_ary['browser']        = $qa_readers['UAbrowser'];
				$info_ary['first_referrer'] = $first_referrer;
				$info_ary['is_new_user']    = $pv['is_newuser'];
				$info_ary['device']         = $dev_name;
				$info_ary['access_time']    = $access_time;
				$info_ary['page_array']     = $page_ary;
				$info_ary['data_type']      = 'database';
				$this->wrap_put_contents( $info_path, $this->wrap_serialize( $info_ary ) );

				break;
			}
		}

		return $replay_view_url;
	}

	// raw_pファイルから滞在時間（秒）を取得
	public function get_time_on_page_to_raw_p( $raw_p_tsv ) {
		$raw_p_time = 0;
		if ( $raw_p_tsv ) {
			$raw_p_ary = $this->convert_tsv_to_array( $raw_p_tsv );
			$raw_p_max = $this->wrap_count( $raw_p_ary );
			$raw_p_ver = (int) $raw_p_ary[ self::DATA_COLUMN_HEADER ][ self::DATA_HEADER_VERSION ];
			for ( $raw_p_idx = self::DATA_COLUMN_BODY; $raw_p_idx < $raw_p_max; $raw_p_idx++ ) {
				if ( $raw_p_ver === 2 ) {
					if ( $raw_p_ary[ $raw_p_idx ][ self::DATA_POS_2['STAY_HEIGHT'] ] === 'a' ) {
						break;
					}
					$raw_p_time += (int) $raw_p_ary[ $raw_p_idx ][ self::DATA_POS_2['STAY_TIME'] ];
				} elseif ( $raw_p_ver === 1 ) {
					if ( $raw_p_ary[ $raw_p_idx ][ self::DATA_POS_1['PERCENT_HEIGHT'] ] === 'a' ) {
						break;
					}
					$raw_p_time += (int) $raw_p_ary[ $raw_p_idx ][ self::DATA_POS_1['TIME_ON_HEIGHT'] ];
				}
			}
		}
		return $raw_p_time;
	}


	// raw_eファイルから滞在時間（秒）を取得
	public function get_time_on_page_to_raw_e( $raw_e_tsv ) {
		$raw_e_time = 0;
		if ( $raw_e_tsv ) {
			$raw_e_ary      = $this->convert_tsv_to_array( $raw_e_tsv );
			$raw_e_last_idx = $this->wrap_count( $raw_e_ary ) - 1;
			$raw_e_time     = ceil( $raw_e_ary[ $raw_e_last_idx ][ self::DATA_EVENT_1['TIME'] ] / 1000 );
		}
		return $raw_e_time;
	}

	/**
	 * OGP画像取得Ajax関数
	 */
	public function ajax_get_ogp_image() {
		try {
			$url = $this->wrap_filter_input( INPUT_POST, 'url' );
			if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
				throw new Exception( 'Invalid URL' );
			}

			if ( ! ( defined( 'QAHM_TYPE' ) && QAHM_TYPE === QAHM_TYPE_ZERO ) ) {
				throw new Exception( 'Not supported in this environment' );
			}

			$ogp_image_url = $this->get_ogp_image_with_file_cache( $url );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
			echo $this->wrap_json_encode(
				array(
					'success'   => true,
					'image_url' => esc_url( $ogp_image_url ),
				)
			);

		} catch ( Exception $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
			echo $this->wrap_json_encode(
				array(
					'success' => false,
					'error'   => esc_html( $e->getMessage() ),
				)
			);
		} finally {
			die();
		}
	}

	/**
	 * OGP画像取得とファイル保存機能
	 */
	private function get_ogp_image_with_file_cache( $url ) {
		global $wp_filesystem;

		$url_hash        = md5( $url );
		$replay_dir_path = $this->get_data_dir_path( 'replay-view-work' );
		$cache_file_path = $replay_dir_path . 'ogp-' . $url_hash . '.jpg';
		$cache_file_url  = $this->get_work_dir_url() . 'ogp-' . $url_hash . '.jpg';

		if ( $wp_filesystem->exists( $cache_file_path ) ) {
			return $cache_file_url;
		}

		$ogp_image_url = $this->get_ogp_image( $url );
		if ( ! $ogp_image_url ) {
			return null;
		}

		$response = $this->wrap_remote_get( $ogp_image_url );
		if ( is_wp_error( $response ) || $response['response']['code'] !== 200 ) {
			return null;
		}

		$wp_filesystem->put_contents( $cache_file_path, $response['body'] );
		return $cache_file_url;
	}

	/**
	 * OGP画像URLを取得
	 */
	private function get_ogp_image( $url ) {
		$response = $this->wrap_remote_get( $url );
		if ( is_wp_error( $response ) || $response['response']['code'] !== 200 ) {
			return null;
		}

		$html = $response['body'];

		if ( preg_match( '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $matches ) ) {
			return $matches[1];
		}

		return null;
	}
}
