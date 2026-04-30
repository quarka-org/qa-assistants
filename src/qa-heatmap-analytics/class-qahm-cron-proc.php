<?php
defined( 'ABSPATH' ) || exit;
/**
 *
 *
 * @package qa_heatmap
 */

new QAHM_Cron_Proc();

class QAHM_Cron_Proc extends QAHM_File_Data {

	const TIME_OUT              = 7200;
	const PV_LOOP_MAX           = 5000;
	const ONE_HOUR_CAN_LOOP     = 30;
	const RAW_LOOP_MAX          = 50000;
	const REALTIME_SESSIONS_MAX = 50000;

	const NIGHT_START             = 2;
	const DEFAULT_DELETE_MONTH    = 2 + 1;
	const DEFAULT_DELETE_DATA_DAY = 30 + 1;
	const DEFAULT_DELETE_PV_YEAR  = 2;
	const DEFAULT_DELETE_RAW_DAY  = 28 + 1;
	const PAID_DELETE_YEAR        = 5;
	const DATA_SAVE_MONTH         = 1 + 1;
	// 自動リカバリする日数の統一を行う
	const REBUILD_VIEWPV_MAX_DAYS = -40;
	// one year save for version_hist table
	const DATA_SAVE_ONE_YEAR   = 12;
	const VIEWPV_DAY_LOOP_MAX  = 12;
	const VIEW_READERS_MAX_IDS = 50000;
	const URL_PARAMETER_MAX    = 128;
	const MAX10000             = 10000;
	const ID_INDEX_MAX10MAN    = 100000;
	const PAID_LIMIT_PV_MONTH  = 300000;

	// mk dummy replace
	const PHP404_ELM       = 0;
	const HEADER_ELM       = 1;
	const BODY_ELM         = 2;
	const TEMP_BODY_ELM_NO = 1;
	// mk dummy replace

	const LOOPLAST_MSG   = 'last loop';
	const MAX_WHILECOUNT = 10000;
	const WP_SEARCH_PERM = '?s=';

	public function __construct() {
		$this->init_wp_filesystem();

		// スケジュールイベント用に関数を登録
		add_action( QAHM_OPTION_PREFIX . 'cron_data_manage', array( $this, 'cron_data_manage' ) );
	}

	public function get_status() {
		global $wp_filesystem;

		$status = 'Cron start';
		if ( $wp_filesystem->exists( $this->get_cron_status_path() ) ) {
				$status = $wp_filesystem->get_contents( $this->get_cron_status_path() );
		} else {
			// cron statusファイル生成
			if ( ! $wp_filesystem->put_contents( $this->get_cron_status_path(), 'Cron start' ) ) {
				throw new Exception( 'cronステータスファイルの生成に失敗しました。終了します。' );
			}
		}
		//ステータスチェック
		if ( ! $this->is_status_ok( $status ) ) {
			if ( $wp_filesystem->exists( $this->get_cron_backup_path() ) ) {
				$status = $wp_filesystem->get_contents( $this->get_cron_backup_path() );
				$this->set_next_status( $status );
			}
		}
		if ( ! $this->is_status_ok( $status ) ) {
			$status = 'Cron start';
		}
		if ( QAHM_DEBUG >= QAHM_DEBUG_LEVEL['debug'] ) {
			print 'get:' . esc_html( $status ) . '<br>';
		}
		return $status;
	}

	public function is_status_ok( $status ) {
		global $wp_filesystem;
		$cronfile = $wp_filesystem->get_contents( plugin_dir_path( __FILE__ ) . 'class-qahm-cron-proc.php' );
		$pregstr  = "/case.*'" . $status . "'/";
		return preg_match( $pregstr, $cronfile );
	}

	public function set_next_status( $nextstatus ) {
		global $wp_filesystem;
		if ( ! $wp_filesystem->put_contents( $this->get_cron_status_path(), $nextstatus ) ) {
			throw new Exception( esc_html( $nextstatus ) . 'のセットでcronステータスファイルの書込に失敗しました。終了します。' );
		}
	}

	public function backup_prev_status( $prevstatus ) {
		global $wp_filesystem;
		if ( ! $wp_filesystem->put_contents( $this->get_cron_backup_path(), $prevstatus ) ) {
			throw new Exception( esc_html( $prevstatus ) . 'のセットでcronバックアップファイルの書込に失敗しました。終了します。' );
		}
	}

	public function write_ary_to_temp( $ary, $tempfile ) {

		global  $wp_filesystem;
		$str = '<?php http_response_code(404);exit; ?>' . PHP_EOL;
		$ret = false;
		if ( ! empty( $ary ) ) {
			foreach ( $ary as $lines ) {
				if ( is_array( $lines ) ) {
					$cnt   = $this->wrap_count( $lines );
					$lpmax = $cnt - 1;
					$line  = '';
					for ( $iii = 0; $iii < $cnt; $iii++ ) {
						$elm = $lines[ $iii ];
						// 各種rawファイルに要素が足りない場合、改行コードが入ってくることがあるので除去
						$elm = str_replace( PHP_EOL, '', $elm );
						// 最終行は改行を抜く
						if ( $iii == $lpmax ) {
							$line .= $elm . PHP_EOL;
						} else {
							$line .= $elm . "\t";
						}
					}
				} else {
					$lines = str_replace( PHP_EOL, '', $lines );
					$line  = $lines . PHP_EOL;
				}
				$str .= $line;
			}
			if ( ! empty( $str ) ) {
				$wp_filesystem->put_contents( $tempfile, $str );
				$ret = true;
			}
		}
		return $ret;
	}

	public function write_string_to_tempphp( $str, $tempfile ) {

		global  $wp_filesystem;
		$put = '<?php http_response_code(404);exit; ?>' . PHP_EOL;
		$ret = false;
		if ( ! empty( $str ) ) {
			$put .= $str;
			$wp_filesystem->put_contents( $tempfile, $put );
			$ret = true;
		}
		return $ret;
	}

	/**
	 * 各IDからIDを求めるインデックス配列をセットするための関数
	 */
	private function make_index_array( &$index_ary, $from_id, $to_id, $date_str ) {
		if ( 0 < (int) $from_id && 0 < (int) $to_id && $date_str !== '' ) {
			$nowidx = floor( (int) $from_id / self::ID_INDEX_MAX10MAN );
			if ( ! isset( $index_ary[ $nowidx ] ) ) {
				//初期化
				$start                = self::ID_INDEX_MAX10MAN * $nowidx + 1;
				$index_ary[ $nowidx ] = array_fill( $start, self::ID_INDEX_MAX10MAN, false );
			}
			//version_idの保存
			if ( $index_ary[ $nowidx ][ (int) $from_id ] !== false ) {
				if ( isset( $index_ary[ $nowidx ][ (int) $from_id ][ $date_str ] ) ) {
					$id_ary  = $index_ary[ $nowidx ][ (int) $from_id ][ $date_str ];
					$is_find = false;
					foreach ( $id_ary as $id ) {
						if ( (int) $to_id === (int) $id ) {
							$is_find = true;
							break;
						}
					}
					if ( ! $is_find ) {
						$index_ary[ $nowidx ][ (int) $from_id ][ $date_str ][] = (int) $to_id;
					}
				} else {
					$index_ary[ $nowidx ][ (int) $from_id ][ $date_str ] = array( (int) $to_id );
				}
			} else {
				$index_ary[ $nowidx ][ (int) $from_id ][ $date_str ] = array( (int) $to_id );
			}
		}
	}

	private function save_index_array( $index_ary, $basedir, $filename ) {
		for ( $jjj = 0; $jjj < $this->wrap_count( $index_ary ); $jjj++ ) {
			$start_index       = $jjj * self::ID_INDEX_MAX10MAN + 1;
			$end_index         = $start_index + self::ID_INDEX_MAX10MAN - 1;
			$pageid_index_file = $start_index . '-' . $end_index . '_' . $filename;
			$this->wrap_put_contents( $basedir . 'index/' . $pageid_index_file, $this->wrap_serialize( $index_ary[ $jjj ] ) );
		}
	}

	private function get_qaz_pid() {

		$qaz_pid = $this->wrap_get_option( 'qaz_pid', 0 );

		++$qaz_pid;

		if ( $qaz_pid > 99 ) {
			$qaz_pid = 1;
		}

		$this->wrap_update_option( 'qaz_pid', $qaz_pid );

		return $qaz_pid;
	}
	public function get_oldest_date_from_viewpv_create_hist() {
		// dir
		global $wp_filesystem;
		$data_dir = $this->get_data_dir_path();
		$temp_dir = $data_dir . 'temp/';

		$oldest_date = false;
		if ( $wp_filesystem->exists( $temp_dir . 'viewpv_create_hist.php' ) ) {
			$rebuild_viewpv_histary     = array();
			$rebuild_viewpv_histary_slz = $this->wrap_get_contents( $temp_dir . 'viewpv_create_hist.php' );
			$rebuild_viewpv_histary     = $this->wrap_unserialize( $rebuild_viewpv_histary_slz );
			// 配列のキー（日付）を取得
			if ( is_array( $rebuild_viewpv_histary ) ) {
				$dates = array_keys( $rebuild_viewpv_histary );
				// 日付チェック: 全てのキーが日付フォーマットかどうか確認
				$is_dates_valid = true;
				foreach ( $dates as $date ) {
					if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) { // YYYY-MM-DD形式の簡易チェック
						$is_dates_valid = false;
						break;
					}
				}
				// 最も古い日付を取得（最初の要素）
				if ( $is_dates_valid ) {
					$oldest_date = min( $dates );
				}
			}
		}
		return $oldest_date;
	}


	// cron処理
	public function cron_data_manage() {
		global $qahm_log;

		// multi_proc_step の判定（flock 取得前に行う）
		// ※ ここで読むステータスは判定専用。data_manage() 内で改めて取得する
		$current_status  = $this->get_status();
		$cron_step_array = $this->wrap_explode( '>', $current_status );
		$multi_proc_step = (
			'Night' === $cron_step_array[0]
			&& isset( $cron_step_array[1] ) && 'Make view file' === $cron_step_array[1]
			&& isset( $cron_step_array[2] ) && 'View_pv' === $cron_step_array[2]
			&& isset( $cron_step_array[3] ) && 'Make loop' === $cron_step_array[3]
		);

		// flock によるプロセス排他制御
		$lock_fp = null;
		if ( ! $multi_proc_step ) {
			$lock_path = $this->get_cron_lock_path();
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$lock_fp = fopen( $lock_path, 'c' );
			if ( false === $lock_fp ) {
				$qahm_log->warning( 'cron ロックファイルを開けません: ' . $lock_path );
				return;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
			if ( ! flock( $lock_fp, LOCK_EX | LOCK_NB ) ) {
				// 他プロセスが稼働中 → 何もせず終了
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $lock_fp );
				return;
			}
		}

		try {
			$this->data_manage();
		} catch ( Throwable $e ) {
			$qahm_log->error( 'Catch, ' . basename( $e->getFile() ) . ':' . $e->getLine() . ', ' . $e->getMessage() );
		} finally {
			if ( $lock_fp ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
				flock( $lock_fp, LOCK_UN );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $lock_fp );
			}
		}
	}

	// cron処理 本体
	public function data_manage() {

		$desired_limit = QAHM_MEMORY_LIMIT_MIN . 'M';
		$current_limit = ini_get( 'memory_limit' );

		if ( $current_limit !== '-1' ) {
			if ( function_exists( 'wp_convert_hr_to_bytes' ) ) {
				$current_bytes = wp_convert_hr_to_bytes( $current_limit );
				$desired_bytes = wp_convert_hr_to_bytes( $desired_limit );
			} else {
				$current_bytes = (int) preg_replace( '/[^0-9]/', '', $current_limit ) * 1048576; // assume MB
				$desired_bytes = QAHM_MEMORY_LIMIT_MIN * 1048576;
			}

			if ( $current_bytes < $desired_bytes ) {
				@ini_set( 'memory_limit', $desired_limit );
			}
		}

		global $wpdb;
		global $qahm_db;
		global $wp_filesystem;
		global $qahm_license;
		global $qahm_time;
		global $qahm_log;
		global $qahm_google_api;
		global $qahm_article_list;

		// dir
		$data_dir          = $this->get_data_dir_path();
		$readers_dir       = $data_dir . 'readers/';
		$readerstemp_dir   = $data_dir . 'readers/temp/';
		$readersfinish_dir = $data_dir . 'readers/finish/';
		$readersdbin_dir   = $data_dir . 'readers/dbin/';
		$temp_dir          = $data_dir . 'temp/';
		$tempdelete_dir    = $data_dir . 'temp/delete/';
		$heatmapwork_dir   = $data_dir . 'heatmap-view-work/';
		$replaywork_dir    = $data_dir . 'replay-view-work/';
		$cache_dir         = $data_dir . 'cache/';
		$view_dir          = $data_dir . 'view/';
		//get_tracking_idは引数url無しの時、Defaultでallを返す
		$myview_dir     = $view_dir . $this->get_tracking_id() . '/';
		$viewpv_dir     = $myview_dir . 'view_pv/';
		$raw_p_dir      = $viewpv_dir . 'raw_p/';
		$raw_c_dir      = $viewpv_dir . 'raw_c/';
		$raw_e_dir      = $viewpv_dir . 'raw_e/';
		$vw_reader_dir  = $myview_dir . 'readers/';
		$vw_verhst_dir  = $myview_dir . 'version_hist/';
		$vw_summary_dir = $myview_dir . 'summary/';
		$vw_bshtml_dir  = $vw_verhst_dir . 'base_html/';

		// yday
		$dbin_session_file   = $temp_dir . 'dbin_session_file.php';
		$yday_loopcount_file = $temp_dir . 'ydayloopfile.php';
		$yday_pvmaxcnt_file  = $temp_dir . 'yday_pvmaxcnt_file';
		$ary_readers_file    = $temp_dir . 'ary_readers_file.php';
		$ary_media_file      = $temp_dir . 'ary_media_file.php';
		$ary_sources_file    = $temp_dir . 'ary_sources_file.php';
		$ary_campaigns_file  = $temp_dir . 'ary_campaigns_file.php';
		$ary_pages_file      = $temp_dir . 'ary_pages_file.php';
		$ary_pv_file         = $temp_dir . 'ary_pv_file.php';
		$ary_wp_s_file       = $temp_dir . 'ary_wp_s_file.php';
		$ary_tids_file       = $temp_dir . 'ary_tids_file.php';
		$ary_tids_for_i_file = $temp_dir . 'ary_tids_for_i_file.php';
		$ary_tids_for_s_file = $temp_dir . 'ary_tids_for_s_file.php';
		$ary_utmcontent_file = $temp_dir . 'ary_utmcontent_file.php';

		// raw
		$raw_loopcount_file  = $temp_dir . 'raw_loopcount_file.php';
		$ary_new_pvrows_file = $temp_dir . 'ary_new_pvrows_file.php';

		// cache
		$cache_heatmap_list_file          = $cache_dir . 'heatmap_list.php';
		$cache_heatmap_list_temp_file     = $cache_dir . 'heatmap_list_temp.php';
		$cache_heatmap_list_idx_temp_file = $cache_dir . 'heatmap_list_idx_temp.php';
		$cache_post_list_file             = $cache_dir . 'post_list.php';
		$cache_page_list_file             = $cache_dir . 'page_list.php';
		$cache_custom_list_file           = $cache_dir . 'custom_list.php';
		$cache_post_list_file30           = $cache_dir . 'post_list30.php';
		$cache_page_list_file30           = $cache_dir . 'page_list30.php';
		$cache_custom_list_file30         = $cache_dir . 'custom_list30.php';

		$days_access_file        = 'days_access.php';
		$days_access_detail_file = 'days_access_detail.php';

		// loop count max
		$now_pv_loop_maxfile  = $temp_dir . 'now_pv_loop_maxfile.php';
		$NOW_PV_LOOP_MAX      = self::PV_LOOP_MAX;
		$now_raw_loop_maxfile = $temp_dir . 'now_raw_loop_maxfile.php';
		$NOW_RAW_LOOP_MAX     = self::RAW_LOOP_MAX;

		$now_pvlog_count_fetchfile = $temp_dir . 'now_pvlog_count_fetchfile.php';

		// delete files list
		$del_rawfileslist_temp = $tempdelete_dir . 'del';
		$del_rawfileslist_file = $data_dir . 'del_rawfileslist_file.php';

		// start
		$while_lpcnt        = 0;
		$is_night_comp_file = $data_dir . 'is_night_comp_file.php';
		$is_night_complete  = false;

		// ----------
		// cron ステータス取得（排他制御は cron_data_manage() の flock で実施済み）
		// ----------

		$cron_status = $this->get_status();

		// ログの削除
		$qahm_log->delete();

		// Idle / Cron start は Determine セクションで $common_result_status に解決するため、
		// ここでの正規化は不要（旧コードでは switch 内の case 'Cron start' に遷移させていた）

		// ----------
		// Common processing (every cycle, regardless of cron_status)
		// ----------

		$cron_start_time   = microtime( true );
		$saved_cron_status = $cron_status;
		$need_dbinit       = false;
		// Step 6 (Check time) で Day/Night 判定結果が設定される。
		// $need_dbinit = true の場合は Step 3〜6 がスキップされるが、
		// その場合は $need_dbinit 分岐が先に評価されるため参照されない。
		$common_result_status = 'Day>Start';

		try {

			$qahm_log->info( 'cron_status:Common>Start' );

			// Common Step 1: Check base dir
			// NOTE: backup_prev_status は各ステップで呼び出すが、set_next_status は呼ばない。
			// Common の中間状態を永続化しない設計。バックアップファイルには最後の Common ステータスが
			// 残るが、メインループの最初の case で上書きされるため実害なし。
			$qahm_log->info( 'cron_status:Common>Check base dir' );
			$this->backup_prev_status( 'Common>Check base dir' );

			// dataディレクトリはこのタイミングで作成
			if ( ! $wp_filesystem->exists( $data_dir ) ) {
				$wp_filesystem->mkdir( $data_dir );
			}
			if ( ! $wp_filesystem->exists( $readers_dir ) ) {
				$wp_filesystem->mkdir( $readers_dir );
			}
			if ( ! $wp_filesystem->exists( $readerstemp_dir ) ) {
				$wp_filesystem->mkdir( $readerstemp_dir );
			}
			if ( ! $wp_filesystem->exists( $readersfinish_dir ) ) {
				$wp_filesystem->mkdir( $readersfinish_dir );
			}
			if ( ! $wp_filesystem->exists( $readersdbin_dir ) ) {
				$wp_filesystem->mkdir( $readersdbin_dir );
			}
			if ( ! $wp_filesystem->exists( $temp_dir ) ) {
				$wp_filesystem->mkdir( $temp_dir );
			}
			if ( ! $wp_filesystem->exists( $tempdelete_dir ) ) {
				$wp_filesystem->mkdir( $tempdelete_dir );
			}
			if ( ! $wp_filesystem->exists( $heatmapwork_dir ) ) {
				$wp_filesystem->mkdir( $heatmapwork_dir );
			}
			if ( ! $wp_filesystem->exists( $replaywork_dir ) ) {
				$wp_filesystem->mkdir( $replaywork_dir );
			}
			if ( ! $wp_filesystem->exists( $cache_dir ) ) {
				$wp_filesystem->mkdir( $cache_dir );
			}
			//view_base
			if ( ! $wp_filesystem->exists( $view_dir ) ) {
				$wp_filesystem->mkdir( $view_dir );
			}
			if ( ! $wp_filesystem->exists( $myview_dir ) ) {
				$wp_filesystem->mkdir( $myview_dir );
			}
			//view_pv
			if ( ! $wp_filesystem->exists( $viewpv_dir ) ) {
				$wp_filesystem->mkdir( $viewpv_dir );
			}
			if ( ! $wp_filesystem->exists( $raw_p_dir ) ) {
				$wp_filesystem->mkdir( $raw_p_dir );
			}
			if ( ! $wp_filesystem->exists( $raw_c_dir ) ) {
				$wp_filesystem->mkdir( $raw_c_dir );
			}
			if ( ! $wp_filesystem->exists( $raw_e_dir ) ) {
				$wp_filesystem->mkdir( $raw_e_dir );
			}
			// reader
			if ( ! $wp_filesystem->exists( $vw_reader_dir ) ) {
				$wp_filesystem->mkdir( $vw_reader_dir );
			}
			// version_hist
			if ( ! $wp_filesystem->exists( $vw_verhst_dir ) ) {
				$wp_filesystem->mkdir( $vw_verhst_dir );
			}
			if ( ! $wp_filesystem->exists( $vw_bshtml_dir ) ) {
				$wp_filesystem->mkdir( $vw_bshtml_dir );
			}

			// Common Step 2: Check update
			$qahm_log->info( 'cron_status:Common>Check update' );
			$this->backup_prev_status( 'Common>Check update' );

			$qahm_update = new QAHM_Update();
			$qahm_update->check_version();

			$check_exists = -123454321;
			$ver          = $this->wrap_get_option( 'qa_readers_version', $check_exists );
			if ( $ver === $check_exists ) {
				$need_dbinit = true;
			}

			if ( ! $need_dbinit ) {

				// Common Step 3: Check free
				$qahm_log->info( 'cron_status:Common>Check free' );
				$this->backup_prev_status( 'Common>Check free' );

				$skip_license_check = false;
				$license_authorized = $this->wrap_get_option( 'license_authorized' );
				// ライセンス認証前は、ライセンスチェックをスキップ
				if ( ! $license_authorized ) {
					$skip_license_check = true;
				}

				// Common Step 4: Check license
				if ( ! $skip_license_check ) {
					$qahm_log->info( 'cron_status:Common>Check license' );
					$this->backup_prev_status( 'Common>Check license' );

					$license_activate_time = $this->wrap_get_option( 'license_activate_time' );
					$today_str             = $qahm_time->today_str();
					$today_start           = $qahm_time->str_to_unixtime( $today_str . ' 00:00:00' );

					// 本日ライセンス未確認だったら実行。アクセスが少ないサイトではいつ実行されるかわからないので、分は40分で分散させる。
					if ( $license_activate_time < $today_start ) {
						$myip       = ip2long( sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ?? '' ) ) );
						$now_hour   = $qahm_time->hour();
						$now_min    = $qahm_time->minute();
						$check_hour = $myip % 24;
						$check_min  = $myip % 40;

						if ( $check_hour <= $now_hour && $check_min <= $now_min ) {
							global $qahm_license;
							$key = $this->wrap_get_option( 'license_key' );
							$id  = $this->wrap_get_option( 'license_id' );
							$qahm_license->activate( $key, $id );
						}
					}
				}

				// Common Step 5: Session check
				$qahm_log->info( 'cron_status:Common>Session check' );
				$this->backup_prev_status( 'Common>Session check' );

				$session_files     = $this->wrap_dirlist( $readerstemp_dir );
				$now_unixtime      = $qahm_time->now_unixtime();
				$pv_limit_exceeded = false;
				// セッションファイルを一つずつ処理
				if ( $session_files ) {

					// Specific to QA - Start ---------------
					// メール送信判定
					$count_pv   = $this->count_this_month_pv();
					$limit_pv   = QAHM_CONFIG_LIMIT_PV_MONTH;
					$this_month = $qahm_time->monthstr();

					if ( $count_pv >= $limit_pv ) {
						$this->wrap_update_option( 'pv_limit_rate', 100 );

						$mail_month = $this->wrap_get_option( 'pv_over_mail_month' );
						if ( $this_month !== $mail_month ) {

							$subject = sprintf(
							/* translators: 1: plugin name, 2: site name */
								__( '[%1$s] Monthly Pageview Limit Reached — %2$s', 'qa-heatmap-analytics' ),
								QAHM_PLUGIN_NAME_FOR_MAIL,
								get_bloginfo( 'name' )
							);
							$message = sprintf(
								/* translators: 1: plugin name, 2: site name, 3: current PV count, 4: PV limit */
								__( 'The pageview count for %1$s on %2$s has reached the monthly limit (%3$s / %4$s PV).', 'qa-heatmap-analytics' ),
								QAHM_PLUGIN_NAME_FOR_MAIL,
								get_bloginfo( 'name' ),
								number_format_i18n( $count_pv ),
								number_format_i18n( $limit_pv )
							) . PHP_EOL;
							$message .= __( 'Data recording is paused until the 1st of next month.', 'qa-heatmap-analytics' ) . PHP_EOL . PHP_EOL;
							$message .= __( 'To change the monthly limit, see the documentation:', 'qa-heatmap-analytics' ) . PHP_EOL;
							$message .= QAHM_DOCUMENTATION_URL . PHP_EOL;

							$this->qa_mail( $subject, $message );
							$this->wrap_update_option( 'pv_warning_mail_month', $this_month );
							$this->wrap_update_option( 'pv_over_mail_month', $this_month );
						}

						// PV上限超過時はrawファイル & セッションファイルの削除
						foreach ( $session_files as $session_file ) {
							$elapsed_sec = $now_unixtime - $session_file['lastmodunix'];

							// 作成されてから30分以上たってたら削除
							$min = 30;
							if ( $elapsed_sec > ( $min * 60 ) ) {
								$readers_temp_ary = $this->wrap_unserialize( $this->wrap_get_contents( $readerstemp_dir . $session_file['name'] ) );
								if ( ! $readers_temp_ary ) {
									$qahm_log->warning( 'Broken session file (unserialize failed): ' . $session_file['name'] );
									$this->wrap_delete( $readerstemp_dir . $session_file['name'] );
									continue;
								}
								if ( ! isset( $readers_temp_ary['head'] ) || ! isset( $readers_temp_ary['body'] ) ) {
									$qahm_log->warning( 'Broken session file (missing head/body): ' . $session_file['name'] );
									$this->wrap_delete( $readerstemp_dir . $session_file['name'] );
									continue;
								}
								if ( ! isset( $readers_temp_ary['head']['tracking_id'] ) || ! isset( $readers_temp_ary['head']['device_name'] ) ) {
									$qahm_log->warning( 'Broken session file (missing head keys): ' . $session_file['name'] );
									$this->wrap_delete( $readerstemp_dir . $session_file['name'] );
									continue;
								}

								$dev_name              = $readers_temp_ary['head']['device_name'];
								$readers_temp_body_max = $this->wrap_count( $readers_temp_ary['body'] );

								// セッションファイルに記録されているPVを一つずつ処理
								for ( $iii = 0; $iii < $readers_temp_body_max; $iii++ ) {
									$body = $readers_temp_ary['body'][ $iii ];

									// Detect position file
									$raw_dir   = $this->get_raw_dir_path( $body['page_type'], $body['page_id'], $dev_name );
									$qa_id_ary = $this->wrap_explode( '.', $session_file['name'] );

									$access_time = $body['access_time'];

									$raw_p_path = $raw_dir . $qa_id_ary[0] . '_' . $access_time . '-p.php';
									$raw_p_tsv  = null;
									if ( $wp_filesystem->exists( $raw_p_path ) ) {
										$raw_p_tsv = $this->wrap_delete( $raw_p_path );
									}

									$raw_c_path = $raw_dir . $qa_id_ary[0] . '_' . $access_time . '-c.php';
									$raw_c_tsv  = null;
									if ( $wp_filesystem->exists( $raw_c_path ) ) {
										$raw_c_tsv = $this->wrap_delete( $raw_c_path );
									}

									$raw_e_path = $raw_dir . $qa_id_ary[0] . '_' . $access_time . '-e.php';
									$raw_e_tsv  = null;
									if ( $wp_filesystem->exists( $raw_e_path ) ) {
										$raw_e_tsv = $this->wrap_delete( $raw_e_path );
									}
								}

								$this->wrap_delete( $readerstemp_dir . $session_file['name'] );
							}
						}

						// PV超過: 残りのセッション処理をスキップ
						$pv_limit_exceeded = true;
					} else {
						$rate = ( $count_pv / $limit_pv ) * 100;
						$rate = floor( $rate );
						$this->wrap_update_option( 'pv_limit_rate', $rate );

						if ( $rate >= 80 ) {
							$mail_month = $this->wrap_get_option( 'pv_warning_mail_month' );
							if ( $this_month !== $mail_month ) {
								$lang_set = get_bloginfo( 'language' );

								$subject = sprintf(
								/* translators: 1: plugin name, 2: site name */
									__( '[%1$s] Pageview Count at 80%% of Monthly Limit — %2$s', 'qa-heatmap-analytics' ),
									QAHM_PLUGIN_NAME_FOR_MAIL,
									get_bloginfo( 'name' )
								);
								$message = sprintf(
									/* translators: 1: plugin name, 2: site name, 3: current PV count, 4: PV limit */
									__( 'The pageview count for %1$s on %2$s has reached 80%% of the monthly limit (%3$s / %4$s PV).', 'qa-heatmap-analytics' ),
									QAHM_PLUGIN_NAME_FOR_MAIL,
									get_bloginfo( 'name' ),
									number_format_i18n( $count_pv ),
									number_format_i18n( $limit_pv )
								) . PHP_EOL;
								$message .= __( 'When the limit is reached, data recording will be paused until the 1st of next month.', 'qa-heatmap-analytics' ) . PHP_EOL . PHP_EOL;
								$message .= __( 'To change the monthly limit, see the documentation:', 'qa-heatmap-analytics' ) . PHP_EOL;
								$message .= QAHM_DOCUMENTATION_URL . PHP_EOL;

								$this->qa_mail( $subject, $message );
								$this->wrap_update_option( 'pv_warning_mail_month', $this_month );
							}
						}
					}
					// Specific to QA - End -----------------

					if ( ! $pv_limit_exceeded ) {
						//QA ZERO DELETE 100000 over check
						global $qahm_view_replay;

						//for speed up
						$realtime_view_recent_ary = array();

						foreach ( $session_files as $session_file ) {
							$elapsed_sec = $now_unixtime - $session_file['lastmodunix'];

							// 作成されてから30分以上たってたらfinishへ
							$min = 30;
							if ( $elapsed_sec > ( $min * 60 ) ) {
								$readers_temp_ary = $this->wrap_unserialize( $this->wrap_get_contents( $readerstemp_dir . $session_file['name'] ) );
								if ( ! $readers_temp_ary ) {
									$qahm_log->warning( 'Broken session file (unserialize failed): ' . $session_file['name'] );
									$this->wrap_delete( $readerstemp_dir . $session_file['name'] );
									continue;
								}
								if ( ! isset( $readers_temp_ary['head'] ) || ! isset( $readers_temp_ary['body'] ) ) {
									$qahm_log->warning( 'Broken session file (missing head/body): ' . $session_file['name'] );
									$this->wrap_delete( $readerstemp_dir . $session_file['name'] );
									continue;
								}
								if ( ! isset( $readers_temp_ary['head']['tracking_id'] ) || ! isset( $readers_temp_ary['head']['device_name'] ) ) {
									$qahm_log->warning( 'Broken session file (missing head keys): ' . $session_file['name'] );
									$this->wrap_delete( $readerstemp_dir . $session_file['name'] );
									continue;
								}

								$readers_finish_ary                           = array();
								$readers_finish_ary['head']['version']        = 1;
								$readers_finish_ary['head']['tracking_id']    = $readers_temp_ary['head']['tracking_id'];
								$readers_finish_ary['head']['device_name']    = $readers_temp_ary['head']['device_name'];
								$readers_finish_ary['head']['is_new_user']    = $readers_temp_ary['head']['is_new_user'];
								$readers_finish_ary['head']['is_reject']      = $readers_temp_ary['head']['is_reject'];
								$readers_finish_ary['head']['user_agent']     = $readers_temp_ary['head']['user_agent'];
								$readers_finish_ary['head']['first_referrer'] = $readers_temp_ary['head']['first_referrer'];
								$readers_finish_ary['head']['utm_source']     = $readers_temp_ary['head']['utm_source'];
								$readers_finish_ary['head']['utm_medium']     = $readers_temp_ary['head']['utm_medium'];
								$readers_finish_ary['head']['utm_campaign']   = $readers_temp_ary['head']['utm_campaign'];
								$readers_finish_ary['head']['utm_term']       = $readers_temp_ary['head']['utm_term'];
								$readers_finish_ary['head']['utm_content']    = $readers_temp_ary['head']['utm_content'];
								$readers_finish_ary['head']['original_id']    = $readers_temp_ary['head']['original_id'];
								$readers_finish_ary['head']['country']        = $readers_temp_ary['head']['country'];
								$readers_finish_ary['head']['country_code']   = $readers_temp_ary['head']['country_code'];
								$readers_finish_ary['body']                   = array();

								$dev_name              = $readers_temp_ary['head']['device_name'];
								$readers_temp_body_max = $this->wrap_count( $readers_temp_ary['body'] );

								$first_access  = '';
								$first_url     = '';
								$first_title   = '';
								$last_exit     = '';
								$last_url      = '';
								$last_title    = '';
								$total_pv      = 0;
								$sec_on_site   = 0;
								$is_raw_e      = false;
								$tracking_id   = $readers_temp_ary['head']['tracking_id'];
								$data_dir_path = $this->get_data_dir_path();
								$qa_id         = $this->get_qaid_from_sessionfile( $session_file['name'] );

								// セッションファイルに記録されているPVを一つずつ処理
								for ( $iii = 0; $iii < $readers_temp_body_max; $iii++ ) {
									$body = $readers_temp_ary['body'][ $iii ];

									// Detect position file
									$base_url    = $body['page_url'];
									$access_time = $body['access_time'];

									$url_hash      = $this->get_url_hash( $base_url );
									$raw_dir_path  = $data_dir_path . $tracking_id . '/' . $url_hash . '/';
									$raw_file_base = $raw_dir_path . $qa_id . '_' . $access_time;

									// クロスドメイン対応: セッションヘッダーの tracking_id にファイルがない場合、他の tracking_id を探す
									if ( ! $wp_filesystem->exists( $raw_file_base . '-p.php' ) &&
									! $wp_filesystem->exists( $raw_file_base . '-e.php' ) ) {
										$sitemanage = $this->wrap_get_option( 'sitemanage' );
										if ( $sitemanage ) {
											foreach ( $sitemanage as $site ) {
												if ( ! isset( $site['tracking_id'] ) || $site['tracking_id'] === $tracking_id ) {
													continue;
												}
												$try_dir  = $data_dir_path . $site['tracking_id'] . '/' . $url_hash . '/';
												$try_base = $try_dir . $qa_id . '_' . $access_time;
												if ( $wp_filesystem->exists( $try_base . '-p.php' ) ||
												$wp_filesystem->exists( $try_base . '-e.php' ) ) {
													$raw_dir_path  = $try_dir;
													$raw_file_base = $try_base;
													break;
												}
											}
										}
									}

									$qa_id_ary = $this->wrap_explode( '.', $session_file['name'] );

									// 遡って同じページを見ていないか確認する。
									// 2ページ目を見ている場合など、ページタイプとページIDが同じなのにURLが違うケースもあるのでそこも想定
									$pv_num = 1;
									if ( $iii > 0 ) {
										for ( $jjj = $iii - 1; $jjj >= 0; $jjj-- ) {
											$prev_body = $readers_temp_ary['body'][ $jjj ];
											if ( $prev_body['page_url'] === $body['page_url'] ||
											( $prev_body['page_type'] === $body['page_type'] && $prev_body['page_id'] === $body['page_id'] ) ) {
												++$pv_num;
											}
										}
									}

									$raw_p_path = $raw_file_base . '-p.php';
									$raw_p_tsv  = null;
									if ( $wp_filesystem->exists( $raw_p_path ) ) {
										$raw_p_tsv = $this->wrap_get_contents( $raw_p_path );
									}

									$raw_e_path = $raw_file_base . '-e.php';
									$raw_e_tsv  = null;
									if ( $wp_filesystem->exists( $raw_e_path ) ) {
										$raw_e_tsv = $this->wrap_get_contents( $raw_e_path );
									}

									/*
									ajaxの通信タイミングによっては意図しないデータになる可能性があるので、念の為ソートする
									*/
									// raw_pのソート（おそらく必要なし）
									if ( $raw_p_tsv ) {
										$raw_p_ary                            = null;
										$raw_p_ary                            = $this->convert_tsv_to_array( $raw_p_tsv );
										$sort_ary                             = array();
										$sort_ary[ self::DATA_COLUMN_HEADER ] = -1;

										if ( 2 === (int) $raw_p_ary[ self::DATA_COLUMN_HEADER ][ self::DATA_HEADER_VERSION ] ) {
											for ( $raw_p_idx = self::DATA_COLUMN_BODY, $raw_p_max = $this->wrap_count( $raw_p_ary ); $raw_p_idx < $raw_p_max; $raw_p_idx++ ) {
												$sort_ary[ $raw_p_idx ] = $raw_p_ary[ $raw_p_idx ][ self::DATA_POS_2['STAY_HEIGHT'] ];
											}
										}

										array_multisort( $sort_ary, SORT_ASC, $raw_p_ary );
										$raw_p_tsv = $this->convert_array_to_tsv( $raw_p_ary );
										$this->wrap_put_contents( $raw_p_path, $raw_p_tsv );
									}

									// raw_eのソート
									if ( $raw_e_tsv ) {
										$is_raw_e                             = true;
										$raw_e_ary                            = null;
										$raw_e_ary                            = $this->convert_tsv_to_array( $raw_e_tsv );
										$sort_ary                             = array();
										$sort_ary[ self::DATA_COLUMN_HEADER ] = -1;

										if ( 1 === (int) $raw_e_ary[ self::DATA_COLUMN_HEADER ][ self::DATA_HEADER_VERSION ] ) {
											for ( $raw_e_idx = self::DATA_COLUMN_BODY, $raw_e_max = $this->wrap_count( $raw_e_ary ); $raw_e_idx < $raw_e_max; $raw_e_idx++ ) {
												$sort_ary[ $raw_e_idx ] = $raw_e_ary[ $raw_e_idx ][ self::DATA_EVENT_1['TIME'] ];
											}
										}

										array_multisort( $sort_ary, SORT_ASC, $raw_e_ary );
										$raw_e_tsv = $this->convert_array_to_tsv( $raw_e_ary );
										$this->wrap_put_contents( $raw_e_path, $raw_e_tsv );
									}

									// 滞在時間（秒）をraw_p, raw_eから求める
									$raw_p_time = $qahm_view_replay->get_time_on_page_to_raw_p( $raw_p_tsv );
									$raw_e_time = $qahm_view_replay->get_time_on_page_to_raw_e( $raw_e_tsv );

									$sec_on_page         = max( $raw_p_time, $raw_e_time );
									$body['sec_on_page'] = $sec_on_page;
									array_push( $readers_finish_ary['body'], $body );

									// set tsv variables
									$sec_on_site += $sec_on_page;
									++$total_pv;
									if ( $iii === 0 ) {
										$first_access = $body['access_time'];
										$first_url    = $body['page_url'];
										$first_title  = $body['page_title'];
									}
									if ( $iii === $readers_temp_body_max - 1 ) {
										$last_exit_time = $body['access_time'] + $sec_on_page;
										$last_url       = $body['page_url'];
										$last_title     = $body['page_title'];
									}
								}

								// finishの生成 & tempの削除
								if ( $readers_finish_ary ) {
									$this->wrap_mkdir( $readersfinish_dir );

									//オンライン処理軽量化のため、オンラインでセッション通番が被らないことは保証しない事とする
									//そこで、ここで書き込み時に重複があるかどうか見て採番しなおす

									preg_match( '/^(.*)_(\d+)\.php$/', $session_file['name'], $matches );
									if ( $this->wrap_count( $matches ) !== 3 ) {
										//例外
										$qahm_log->warning( 'invalid session file name. ' . $session_file['name'] );
										continue;
									}

									$max_session_number = 99; // 無限ループ防止

									$base_name              = $matches[1];
									$default_session_number = (int) $matches[2];
									$new_session_number     = $default_session_number;

									//書き込む対象ファイルが存在しなくなるまで探索
									while ( $new_session_number <= $max_session_number ) {
										$new_session_file_name = $base_name . '_' . $new_session_number . '.php';
										if ( ! file_exists( $readersfinish_dir . $new_session_file_name ) ) {
											// ファイルが存在しない場合、ループを抜ける
											break;
										}
										++$new_session_number;
									}

									if ( $new_session_number > $max_session_number ) {
										//例外
										$qahm_log->warning( 'maximum limit session number.' );
										continue;
									}

									$this->wrap_put_contents( $readersfinish_dir . $new_session_file_name, $this->wrap_serialize( $readers_finish_ary ) );

									// realtime viewの要素を追加
									if ( $is_raw_e ) {
										$realtime_view_body         = array(
											'file_name'    => $new_session_file_name,
											'tracking_id'  => $readers_finish_ary['head']['tracking_id'],
											'device_name'  => $readers_finish_ary['head']['device_name'],
											'is_new_user'  => $readers_finish_ary['head']['is_new_user'],
											'user_agent'   => $readers_finish_ary['head']['user_agent'],
											'first_referrer' => $readers_finish_ary['head']['first_referrer'],
											'utm_source'   => $readers_finish_ary['head']['utm_source'],
											'utm_medium'   => $readers_finish_ary['head']['utm_medium'],
											'utm_campaign' => $readers_finish_ary['head']['utm_campaign'],
											'utm_term'     => $readers_finish_ary['head']['utm_term'],
											'original_id'  => $readers_finish_ary['head']['original_id'],
											'country'      => $readers_finish_ary['head']['country'],
											'country_code' => $readers_finish_ary['head']['country_code'],
											'first_access_time' => $first_access,
											'first_url'    => $first_url,
											'first_title'  => $first_title,
											'last_exit_time' => $last_exit_time,
											'last_url'     => $last_url,
											'last_title'   => $last_title,
											'page_view'    => $total_pv,
											'sec_on_site'  => $sec_on_site,
										);
										$realtime_view_recent_ary[] = $realtime_view_body;
									}
								}
								$wp_filesystem->delete( $readerstemp_dir . $session_file['name'] );
							}
						}

						// realtime_viewを離脱時刻でソート
						$recent_session_count = $this->wrap_count( $realtime_view_recent_ary );
						if ( $recent_session_count > 1 ) {
							// バブルソート
							for ( $ooo = $recent_session_count; $ooo > 0; $ooo-- ) {
								for ( $sss = 0; $sss < $ooo - 1; $sss++ ) {
									$now_exit_time  = $realtime_view_recent_ary[ $sss ]['last_exit_time'];
									$next_exit_time = $realtime_view_recent_ary[ $sss + 1 ]['last_exit_time'];

									if ( $now_exit_time < $next_exit_time ) {
										$temp_ary                             = $realtime_view_recent_ary[ $sss ];
										$realtime_view_recent_ary[ $sss ]     = $realtime_view_recent_ary[ $sss + 1 ];
										$realtime_view_recent_ary[ $sss + 1 ] = $temp_ary;
									}
								}
							}
						}

						if ( $recent_session_count > 0 ) {
							$realtime_view_path   = $readers_dir . 'realtime_view.php';
							$remain_session_count = self::REALTIME_SESSIONS_MAX - $recent_session_count;
							if ( $wp_filesystem->exists( $realtime_view_path ) ) {
								$realtime_view_ary     = $this->wrap_unserialize( $this->wrap_get_contents( $realtime_view_path ) );
								$already_session_count = $this->wrap_count( $realtime_view_ary['body'] );
								if ( $remain_session_count <= $already_session_count ) {
									for ( $iii = 0; $iii < $remain_session_count; $iii++ ) {
										$realtime_view_recent_ary[] = $realtime_view_ary['body'][ $iii ];
									}
								} else {
									for ( $iii = 0; $iii < $already_session_count; $iii++ ) {
										$realtime_view_recent_ary[] = $realtime_view_ary['body'][ $iii ];
									}
								}
							} else {
								$realtime_view_ary                    = array();
								$realtime_view_ary['head']['version'] = 1;
							}
							$realtime_view_ary['body'] = $realtime_view_recent_ary;
							$this->wrap_put_contents( $realtime_view_path, $this->wrap_serialize( $realtime_view_ary ) );
						}
					}
				}

				// Common Step 6: Check time
				$qahm_log->info( 'cron_status:Common>Check time' );
				$this->backup_prev_status( 'Common>Check time' );

				// 標準はDay
				$common_result_status = 'Day>Start';

				// 夜間バッチの状態を確認。ファイルがない＝インストール直後は夜間バッチは未完了状態とする
				if ( $wp_filesystem->exists( $is_night_comp_file ) ) {
					$night_comp_mtime = $wp_filesystem->mtime( $is_night_comp_file );
					$today_str        = $qahm_time->today_str();
					$today_start      = $qahm_time->str_to_unixtime( $today_str . ' 00:00:00' );

					if ( $today_start < $night_comp_mtime ) {
						$is_night_complete = true;
					} else {
						$is_night_complete = false;
					}
				} else {
					$is_night_complete = false;
				}

				// 定時になったら夜間バッチを開始。一旦開始すると夜間が終了までこの「Check time」は発生しない。終わっていたら常にDay Startに
				$nowhour = (int) $qahm_time->hour();
				if ( $nowhour >= self::NIGHT_START ) {
					$common_result_status = 'Night>Start';

					if ( QAHM_CONFIG_TWO_SYSTEM_MODE && QAHM_CONFIG_SYSTEM_MODE == 1 ) {
						$common_result_status = 'Night_SP>Start';
					}

					if ( $is_night_complete ) {
						$common_result_status = 'Day>Start';
					}
				}
			} // end if ( ! $need_dbinit )

			$qahm_log->info( 'cron_status:Common>End' );

		} catch ( Exception $e ) {
			$qahm_log->error( 'Common processing failed: ' . $e->getMessage() );
			// Common 失敗でも Day/Night 処理は実行する
		}

		// ----------
		// Determine main loop start status
		// ----------
		if ( $need_dbinit ) {
			$cron_status = 'Night>Dbinit>Start';
		} else {
			$cron_step_array = $this->wrap_explode( '>', $saved_cron_status );
			$first_step      = $cron_step_array[0];
			if ( 'Idle' === $saved_cron_status || 'Cron start' === $saved_cron_status || 'Common' === $first_step ) {
				$cron_status = $common_result_status;
			} else {
				// Night>Delete>... 等、中断した処理から再開
				$cron_status = $saved_cron_status;
			}
		}
		$this->set_next_status( $cron_status );

		// ----------
		// Main loop (Day/Night processing)
		// ----------
		$while_continue = true;
		while ( $while_continue ) {
			$qahm_log->info( 'cron_status:' . $cron_status );
			switch ( $cron_status ) {

				// ----------
				// Daytime
				// ----------
				case 'Day>Start':
					$this->backup_prev_status( $cron_status );

					// ---next
					$cron_status = 'Day>Session over mail';
					$this->set_next_status( $cron_status );
					break;

				case 'Day>Session over mail':
					$this->backup_prev_status( $cron_status );

					/*
					ZEROはメールを送らないのでコメントアウト
					$over_mail_time = $this->wrap_get_option( 'over_mail_time' );
					$today_str      = $qahm_time->today_str();
					$today_start    = $qahm_time->str_to_unixtime( $today_str . ' 00:00:00' );

					// 本日メール未送信だったら実行。アクセスが少ないサイトではいつ実行されるかわからないので、分は40分で分散させる。
					if ( $over_mail_time < $today_start || $over_mail_time === false ) {
						$myip       = ip2long( sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ?? '' ) ) );
						$now_hour   = $qahm_time->hour();
						$now_min    = $qahm_time->minute();
						$check_hour = $myip % 24;
						$check_min  = $myip % 40;

						if ( $check_hour <= $now_hour && $check_min <= $now_min ) {
							$subject = esc_html__( 'Sessions per month has exceeded 100,000', 'qa-heatmap-analytics' );
							$message = esc_html__( 'QA Analytics has stopped collecting data because the number of sessions per month has exceeded 100,000. If you wish to collect data, please purchase a license.', 'qa-heatmap-analytics' );
							$message = $message . PHP_EOL . 'https://quarka.org/plan/';
							$this->qa_mail( $subject, $message );
							$this->wrap_update_option('over_mail_time', $qahm_time->now_unixtime() );
						}
					}
					*/

					// ---next
					// Differs between ZERO and QA - Start ----------
					if ( defined( 'QAHM_TYPE' ) && QAHM_TYPE === QAHM_TYPE_ZERO ) {
						$cron_status = 'Day>HTML periodic>Start';
					} else {
						$cron_status = 'Day>End';
					}
					// Differs between ZERO and QA - End ----------
					$this->set_next_status( $cron_status );
					break;

				// ----------
				// HTML定期取得（ZERO専用）
				// ----------
				case 'Day>HTML periodic>Start':
					$this->backup_prev_status( $cron_status );

					// 走査完了フラグがあれば Create/Organize/Update をスキップ
					$html_scan_complete_path_day = $data_dir . 'html_periodic_scan_complete.php';
					if ( file_exists( $html_scan_complete_path_day ) ) {
						$cron_status = 'Day>HTML periodic>End';
						$this->set_next_status( $cron_status );
						break;
					}

					$cron_status = 'Day>HTML periodic>Create';
					$this->set_next_status( $cron_status );
					break;

				case 'Day>HTML periodic>Create':
					$this->backup_prev_status( $cron_status );

					$html_periodic = new QAHM_Html_Periodic_Proc();
					$state         = $html_periodic->create_html_periodic_list();
					if ( $state ) {
						$cron_status = 'Day>HTML periodic>Organize';
					} else {
						// 対象サイトなし or エラー → スキップ
						$cron_status = 'Day>HTML periodic>End';
					}
					$this->set_next_status( $cron_status );
					break;

				case 'Day>HTML periodic>Organize':
					$this->backup_prev_status( $cron_status );

					$html_periodic = new QAHM_Html_Periodic_Proc();
					$state         = $html_periodic->organize_html_periodic_list();
					if ( $state ) {
						$cron_status = 'Day>HTML periodic>Update';
					} else {
						// データ破損 → リスト再作成
						$cron_status = 'Day>HTML periodic>Create';
					}
					$this->set_next_status( $cron_status );
					break;

				case 'Day>HTML periodic>Update':
					$this->backup_prev_status( $cron_status );

					$html_periodic = new QAHM_Html_Periodic_Proc();
					$result        = $html_periodic->process_urls_with_budget( $cron_start_time );
					$cron_status   = 'Day>HTML periodic>End';
					if ( false === $result ) {
						// 時間切れ → cron 終了（次回は Day>HTML periodic>End から再開）
						$while_continue = false;
					}
					$this->set_next_status( $cron_status );
					break;

				case 'Day>HTML periodic>End':
					$this->backup_prev_status( $cron_status );
					$cron_status = 'Day>End';
					$this->set_next_status( $cron_status );
					break;

				case 'Day>End':
					$this->backup_prev_status( $cron_status );

					// ---next
					$cron_status = 'Cron end';
					$this->set_next_status( $cron_status );
					break;

				// ----------
				// Night
				// ----------
				case 'Night>Start':
					$this->backup_prev_status( $cron_status );

					// HTML定期取得の走査完了フラグとカウンタを削除（翌日の走査を有効化）
					$html_periodic_cleanup_files = array(
						$data_dir . 'html_periodic_scan_complete.php',
						$data_dir . 'html_periodic_base_write_count.php',
						$data_dir . 'html_periodic_scan_pass_count.php',
					);
					foreach ( $html_periodic_cleanup_files as $cleanup_file ) {
						if ( file_exists( $cleanup_file ) ) {
							$this->wrap_delete( $cleanup_file );
						}
					}

					// ---next
					$cron_status = 'Night>Data verification>Start';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Data verification>Start':
					$this->backup_prev_status( $cron_status );
					$this->verify_dbin_pv_log_data();
					// ---next
					$cron_status = 'Night>Tracking tag>Update';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Tracking tag>Update':
					$this->backup_prev_status( $cron_status );

					// tracking tag update
					global $qahm_data_api;
					$siteary = $qahm_data_api->get_sitemanage();
					if ( ! empty( $siteary ) ) {
						foreach ( $siteary as $site ) {
							$tid = $site['tracking_id'];
							$this->create_qtag( $tid );
						}
					}

					// ---next
					$cron_status = 'Night>Create yesterday data>Start';
					// if Immediately after 1st install -> db create -> today's night end (exit)-> day start
					$check_exists = -123454321;
					$ver          = $this->wrap_get_option( 'qa_readers_version', $check_exists );
					if ( $ver === $check_exists ) {
						$cron_status = 'Night>Dbinit>Start';
					}
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Dbinit>Start':
					$this->backup_prev_status( $cron_status );

					$cron_status = 'Night>Dbinit>Exec';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Dbinit>Exec':
					$this->backup_prev_status( $cron_status );

					// クエリの実行
					$qahm_database_manager = new QAHM_Database_Creator();
					$qahm_database_manager->initialize_database();
					// upper is a long time execution. set next and exit
					$cron_status = 'Night>Dbinit>End';
					$this->set_next_status( $cron_status );
					$while_continue = false;
					break;

				case 'Night>Dbinit>End':
					$this->backup_prev_status( $cron_status );

					$cron_status = 'Night>End';
					$this->set_next_status( $cron_status );
					break;

				// ----------
				// Create yesterday data
				// ----------
				case 'Night>Create yesterday data>Start':
					$this->backup_prev_status( $cron_status );

					$loopstart = 0;
					$wp_filesystem->put_contents( $yday_loopcount_file, $loopstart );
					$wp_filesystem->put_contents( $yday_pvmaxcnt_file, $loopstart );

					// finish dir search and making loop list
					$fin_session_files = $this->wrap_dirlist( $readersfinish_dir );
					// yesterday
					$yday_session_files = array();

					$yesterday_str = $qahm_time->xday_str( -1 );
					// 昨日のセッションファイルを取得
					$iii = 0;
					if ( is_array( $fin_session_files ) ) {
						$dbin_session_files = array();
						foreach ( $fin_session_files as $fin_session_file ) {
							preg_match( '/_(\d{4}-\d{2}-\d{2})_/', $fin_session_file['name'], $fname_date_matches );
							$fname_date_str = isset( $fname_date_matches[1] ) ? $fname_date_matches[1] : '';
							if ( '' !== $fname_date_str && $fname_date_str <= $yesterday_str ) {
								$yday_session_files[ $iii ] = $fin_session_file;
								$dbin_session_files[ $iii ] = $readersfinish_dir . $fin_session_file['name'];
								++$iii;
							}
						}
					}

					if ( $iii > 0 ) {
						$NOW_PV_LOOP_MAX = ceil( $iii / self::ONE_HOUR_CAN_LOOP );
					}
					if ( $NOW_PV_LOOP_MAX < self::PV_LOOP_MAX ) {
						$NOW_PV_LOOP_MAX = self::PV_LOOP_MAX;
					}
					$wp_filesystem->put_contents( $now_pv_loop_maxfile, $NOW_PV_LOOP_MAX );

					// ---next
					$cron_status = 'Night>Create yesterday data>Loop>Start';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Create yesterday data>Loop>Start':
					$this->backup_prev_status( $cron_status );

					// temp file delete
					if ( $wp_filesystem->exists( $ary_readers_file ) ) {
						$wp_filesystem->delete( $ary_readers_file );
					}
					if ( $wp_filesystem->exists( $ary_pages_file ) ) {
						$wp_filesystem->delete( $ary_pages_file );
					}
					if ( $wp_filesystem->exists( $ary_media_file ) ) {
						$wp_filesystem->delete( $ary_media_file );
					}
					if ( $wp_filesystem->exists( $ary_sources_file ) ) {
						$wp_filesystem->delete( $ary_sources_file );
					}
					if ( $wp_filesystem->exists( $ary_campaigns_file ) ) {
						$wp_filesystem->delete( $ary_campaigns_file );
					}
					if ( $wp_filesystem->exists( $ary_pv_file ) ) {
						$wp_filesystem->delete( $ary_pv_file );
					}
					if ( $wp_filesystem->exists( $ary_wp_s_file ) ) {
						$wp_filesystem->delete( $ary_wp_s_file );
					}
					if ( $wp_filesystem->exists( $ary_utmcontent_file ) ) {
						$wp_filesystem->delete( $ary_utmcontent_file );
					}

					// ---next
					$cron_status = 'Night>Create yesterday data>Loop>Make Array';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Create yesterday data>Loop>Make Array':
					$this->backup_prev_status( $cron_status );

					global $qahm_data_api;
					$sitemanage = $qahm_data_api->get_sitemanage();

					// finish dir search and making loop list
					$fin_session_files = $this->wrap_dirlist( $readersfinish_dir );
					// yesterday
					$yday_session_files = array();

					$yesterday_str = $qahm_time->xday_str( -1 );
					// 昨日のセッションファイルを取得
					$iii = 0;
					if ( is_array( $fin_session_files ) ) {
						$dbin_session_files = array();
						foreach ( $fin_session_files as $fin_session_file ) {
							preg_match( '/_(\d{4}-\d{2}-\d{2})_/', $fin_session_file['name'], $fname_date_matches );
							$fname_date_str = isset( $fname_date_matches[1] ) ? $fname_date_matches[1] : '';
							if ( '' !== $fname_date_str && $fname_date_str <= $yesterday_str ) {
								$yday_session_files[ $iii ] = $fin_session_file;
								$dbin_session_files[ $iii ] = $readersfinish_dir . $fin_session_file['name'];
								++$iii;
							}
						}
					}

					if ( $iii > 0 ) {

						$nowloop = 0;
						if ( $wp_filesystem->exists( $yday_loopcount_file ) ) {
							$nowloop_ary = $wp_filesystem->get_contents_array( $yday_loopcount_file );
							$nowloop     = (int) $nowloop_ary[0];
						} else {
							$wp_filesystem->put_contents( $yday_loopcount_file, $nowloop );
						}

						$loopadd = self::PV_LOOP_MAX;
						if ( $wp_filesystem->exists( $now_pv_loop_maxfile ) ) {
							$loopadd = $wp_filesystem->get_contents( $now_pv_loop_maxfile );
						}
						$thisloopmax = $nowloop + $loopadd;

						$yday_loopmax = $this->wrap_count( $yday_session_files );
						if ( $yday_loopmax < $thisloopmax ) {
							$thisloopmax = $yday_loopmax;
							$loop_str    = $yday_loopmax . PHP_EOL . self::LOOPLAST_MSG;
							$wp_filesystem->put_contents( $yday_loopcount_file, $loop_str );
						}
						$readers_ary    = array();
						$media_ary      = array();
						$utmcontent_ary = array();
						$sources_ary    = array();
						$campaigns_ary  = array();
						$pages_ary      = array();
						$pv_ary         = array();
						$wp_s_ary       = array();

						$qa_id_memory_ary = array();

						// 小分けにした（初期800回）ループで昨日のセッションファイルを処理して各種Table用の配列を作成
						for ( $iii = $nowloop; $iii < $thisloopmax; $iii++ ) {
							$yday_session_file = $yday_session_files[ $iii ];
							$yday_filename_str = $yday_session_file['name'];
							$yday_file_ary     = $this->wrap_unserialize( $this->wrap_get_contents( $readersfinish_dir . $yday_filename_str ) );
							if ( ! $yday_file_ary ) {
								$qahm_log->warning( 'Broken finish file (unserialize failed): ' . $yday_filename_str );
								$this->wrap_delete( $readersfinish_dir . $yday_filename_str );
								continue;
							}
							if ( ! isset( $yday_file_ary['head'] ) || ! isset( $yday_file_ary['body'] ) ) {
								$qahm_log->warning( 'Broken finish file (missing head/body): ' . $yday_filename_str );
								$this->wrap_delete( $readersfinish_dir . $yday_filename_str );
								continue;
							}
							if ( ! isset( $yday_file_ary['head']['tracking_id'] ) || ! isset( $yday_file_ary['head']['device_name'] ) ) {
								$qahm_log->warning( 'Broken finish file (missing head keys): ' . $yday_filename_str );
								$this->wrap_delete( $readersfinish_dir . $yday_filename_str );
								continue;
							}

							// set variables
							$qa_id = $this->wrap_substr( $yday_filename_str, 0, 28 );
							preg_match( '/_([0-9]*).php/', $yday_filename_str, $matches );
							$session_no   = $matches[1];
							$tracking_id  = $yday_file_ary['head']['tracking_id'];
							$device       = $yday_file_ary['head']['device_name'];
							$user_agent   = $yday_file_ary['head']['user_agent'];
							$referer      = $yday_file_ary['head']['first_referrer'];
							$source       = $yday_file_ary['head']['utm_source'];
							$media        = $yday_file_ary['head']['utm_medium'];
							$utm_content  = $yday_file_ary['head']['utm_content'];
							$campaign     = mb_substr( urldecode( $yday_file_ary['head']['utm_campaign'] ), 0, 127 );
							$utm_term     = mb_substr( urldecode( $yday_file_ary['head']['utm_term'] ), 0, 255 );
							$original_id  = $yday_file_ary['head']['original_id'];
							$is_new_user  = $yday_file_ary['head']['is_new_user'];
							$is_reject    = $yday_file_ary['head']['is_reject'];
							$language     = $yday_file_ary['head']['country'];
							$country_code = $yday_file_ary['head']['country_code'];

							$device_code = QAHM_DEVICES['desktop']['id'];
							foreach ( QAHM_DEVICES as $qahm_dev ) {
								if ( $device === $qahm_dev['name'] ) {
									$device_code = $qahm_dev['id'];
									break;
								}
							}

							// each array add , more faster than array_push
							$device_os = $this->os_from_ua( $user_agent );
							$browser   = $this->browser_from_ua( $user_agent );
							if ( ! empty( $qa_id ) && ! $this->wrap_in_array( $qa_id, $qa_id_memory_ary ) ) {
								$readers_ary[]      = array( $qa_id, $original_id, $device_os, $browser, $language, $country_code, $is_reject );
								$qa_id_memory_ary[] = $qa_id;
							}
							if ( ! empty( $media ) ) {
								$media_ary[] = $media;
							}
							if ( ! empty( $utm_content ) ) {
								$utmcontent_ary[] = $utm_content;
							}

							$source_domain = '';
							if ( ! empty( $referer ) ) {
								//20220415 add all referer strings are must be lower.
								$referer = mb_strtolower( $referer );

								if ( $referer == 'direct' ) {
									$source_domain = 'direct';
								} else {
									$parse_url = wp_parse_url( $referer );
									if ( $parse_url['host'] ) {
										$ref_host      = $parse_url['host'];
										$source_domain = $ref_host;
									}
									if ( isset( $parse_url['query'] ) ) {
										$param_url = $parse_url['query'];
										$newref    = $referer;
										parse_str( $param_url, $param_ary );
										foreach ( $param_ary as $key => $param ) {
											if ( self::URL_PARAMETER_MAX < mb_strlen( $param ) ) {
												$orgparam   = urlencode( $param );
												$shortparam = $this->wrap_substr( $orgparam, 0, self::URL_PARAMETER_MAX );
												$newref     = str_replace( $orgparam, $shortparam, $newref );
											}
										}
										$referer = $newref;
									}
								}
							}
							$sources_ary[] = array( $source, $referer, $source_domain, $media, $utm_term );

							if ( ! empty( $campaign ) ) {
								$campaigns_ary[] = $campaign;
							}
							// PVをチェック。PV関連の配列を作る
							$pvline_max = $this->wrap_count( $yday_file_ary['body'] );
							for ( $jjj = 0; $jjj < $pvline_max; $jjj++ ) {
								// Detect pv
								$pvline_ary   = $yday_file_ary['body'][ $jjj ];
								$page_id      = $pvline_ary['page_id'];
								$type         = $pvline_ary['page_type'];
								$lp_time      = $pvline_ary['access_time'];
								$page_url     = $pvline_ary['page_url'];
								$page_title   = mb_substr( $pvline_ary['page_title'], 0, 64 );
								$page_speed   = $pvline_ary['page_speed'];
								$time_on_page = $pvline_ary['sec_on_page'];

								// site search?
								if ( ! empty( $page_url ) ) {
									// 検索キーワードの結果を取得
									$search_keywords = null;
									foreach ( $sitemanage as $site ) {
										if ( $site['tracking_id'] == $tracking_id ) {
											$search_keywords = $this->wrap_explode( ',', $site['search_params'] );
											break;
										}
									}
									// URLからクエリパートをパース
									$url_components = wp_parse_url( $page_url );
									$query_string   = isset( $url_components['query'] ) ? $url_components['query'] : '';
									// クエリ文字列を解析して連想配列に変換
									parse_str( $query_string, $query_params );

									$keyword_values = array();
									foreach ( $search_keywords as $keyword ) {
										if ( $this->wrap_array_key_exists( $keyword, $query_params ) ) {
											// キーワードに対応する値を取り出し、配列に追加
											$keyword_values[] = urldecode( $query_params[ $keyword ] );
											// キーワードの値を空にする
											$query_params[ $keyword ] = '';
										}
									}

									if ( $this->wrap_count( $keyword_values ) != 0 ) {

										$combined_keywords = $this->wrap_implode( ';', $keyword_values );
										$combined_keywords = $this->wrap_substr( $combined_keywords, 0, 128 );

										if ( ! empty( $combined_keywords ) ) {
											$wp_s_ary[] = array( $qa_id, $lp_time, $combined_keywords );
										}

										// クエリパラメータを再構築してURLを生成
										$modified_query_string = http_build_query( $query_params );
										$modified_url          = $url_components['scheme'] . '://' . $url_components['host'] . $url_components['path'] . '?' . $modified_query_string;

										// 必要に応じて $modified_url を使用
										$page_url = $modified_url;
									}
								}
								$url_hash = hash( 'fnv164', $page_url );

								$is_last = 0;
								if ( $jjj === $pvline_max - 1 ) {
									$is_last = 1;
								}
								$path_url      = $this->to_path_url( $page_url );
								$path_url_hash = hash( 'fnv164', $path_url );

								if ( ! empty( $page_url ) ) {
									$pages_ary[] = array( $tracking_id, $type, $page_id, $page_url, $url_hash, $page_title, $path_url_hash );
								}
								if ( ! empty( $qa_id ) ) {
									$pv_num    = $jjj + 1;
									$islast    = (string) $is_last;
									$isnewuser = (string) $is_new_user;
									$pv_ary[]  = array( $qa_id, $url_hash, $page_url, $device_code, $source, $referer, $source_domain, $media, $campaign, $utm_term, $session_no, $lp_time, $pv_num, $page_speed, $time_on_page, $islast, $isnewuser, $utm_content );
								}
							}
						}

						// 作成した配列をファイルに書き出して終了。ユニークチェック処理は行わない。セパレートした数百行程度ではあまり発生しないし、DBにやらせた方が速そうなため
						if ( ! empty( $dbin_session_files ) ) {
							$this->write_ary_to_temp( $dbin_session_files, $dbin_session_file );
						}
						if ( ! empty( $readers_ary ) ) {
							$this->write_ary_to_temp( $readers_ary, $ary_readers_file );
						}
						if ( ! empty( $media_ary ) ) {
							$this->write_ary_to_temp( $media_ary, $ary_media_file );
						}
						if ( ! empty( $utmcontent_ary ) ) {
							$this->write_ary_to_temp( $utmcontent_ary, $ary_utmcontent_file );
						}
						if ( ! empty( $sources_ary ) ) {
							$this->write_ary_to_temp( $sources_ary, $ary_sources_file );
						}
						if ( ! empty( $campaigns_ary ) ) {
							$this->write_ary_to_temp( $campaigns_ary, $ary_campaigns_file );
						}
						if ( ! empty( $pages_ary ) ) {
							$this->wrap_put_contents( $ary_pages_file, $this->wrap_serialize( $pages_ary ) );
						}
						if ( ! empty( $pv_ary ) ) {
							$this->write_ary_to_temp( $pv_ary, $ary_pv_file );
						}
						if ( ! empty( $wp_s_ary ) ) {
							$this->write_ary_to_temp( $wp_s_ary, $ary_wp_s_file );
						}
						// ---next
						$cron_status = 'Night>Create yesterday data>Loop>Insert>Readers';
						$this->set_next_status( $cron_status );
					} else {
						// ---no data. end
						$cron_status = 'Night>Delete>Start';
						$this->set_next_status( $cron_status );
					}
					break;

				case 'Night>Create yesterday data>Loop>Insert>Readers':
					$this->backup_prev_status( $cron_status );

					// insert readers (if Dupplicate,then error)
					// ---next
					$cron_status = 'Night>Create yesterday data>Loop>Insert>Media';
					$nowstr      = $qahm_time->now_str();
					$data_ary    = $wp_filesystem->get_contents_array( $ary_readers_file );
					if ( ! empty( $data_ary ) ) {
						// プレースホルダーとインサートするデータ配列
						$arrayValues   = array();
						$place_holders = array();

						// $data_aryはインサートするデータ配列が入っている
						// qa_idのユニークはDB側で保証されないので、自分で調査し、アップデート
						$is_first_line = true;

						$place_holder = '(%s, %s, %s, %s, %s, %s)';
						$col_statment = '(qa_id, original_id, UAos, UAbrowser, language, update_date) ';

						$table_name = $wpdb->prefix . 'qa_readers';
						//is_rejectの存在を確認し、存在しない場合はwarningを出す
						$exists_is_reject = false;
						$column_name      = 'is_reject';
						$result           = $wpdb->get_results(
							$wpdb->prepare(
								"SHOW COLUMNS FROM `{$table_name}` LIKE %s",
								$column_name
							)
						);
						if ( empty( $result ) ) {
							$qahm_log->warning( 'The column is_reject does not exist in the qa_readers table' );
						} else {
							$exists_is_reject = true;
							$place_holder     = '(%s, %s, %s, %s, %s, %s, %s)';
							$col_statment     = '(qa_id, original_id, UAos, UAbrowser, language, is_reject, update_date) ';
						}

						//countryの存在を確認し、存在しない場合はwarningを出す
						$exists_country = false;
						$column_name    = 'country_code';
						$result         = $wpdb->get_results(
							$wpdb->prepare(
								"SHOW COLUMNS FROM `{$table_name}` LIKE %s",
								$column_name
							)
						);
						if ( empty( $result ) ) {
							$qahm_log->warning( 'The column country_code does not exist in the qa_readers table' );
						} else {
							$exists_country = true;
							if ( $exists_is_reject ) {
								$place_holder = '(%s, %s, %s, %s, %s, %s, %s, %s)';
								$col_statment = '(qa_id, original_id, UAos, UAbrowser, language, country_code, is_reject, update_date) ';
							} else {
								$place_holder = '(%s, %s, %s, %s, %s, %s, %s)';
								$col_statment = '(qa_id, original_id, UAos, UAbrowser, language, country_code, update_date) ';
							}
						}

						foreach ( $data_ary as $line ) {
							if ( ! $is_first_line ) {
								// インサートするデータを格納
								$line        = str_replace( PHP_EOL, '', $line );
								$data        = $this->wrap_explode( "\t", $line );
								$qa_id       = $data[0];
								$original_id = $data[1];
								$device_os   = $data[2];
								$browser     = $data[3];
								$language    = $data[4];

								$is_reject = null;
								if ( isset( $data[6] ) ) {
									$is_reject = $data[6];
								}

								//既に存在するか？
								if ( ! empty( $qa_id ) ) {
									$table_name = $wpdb->prefix . 'qa_readers';
									$result     = $wpdb->get_results(
										$wpdb->prepare(
											"SELECT reader_id FROM `{$table_name}` WHERE qa_id = %s",
											$qa_id
										)
									);
									if ( ! empty( $result ) ) {
										$reader_id = $result[0]->reader_id;
										// 既に存在する場合はupdate_dateを更新し、次のデータへ
										$table_name = $wpdb->prefix . 'qa_readers';
										$result     = $wpdb->query(
											$wpdb->prepare(
												"UPDATE `{$table_name}` SET original_id = %s, update_date = CURDATE() WHERE reader_id = %d",
												$original_id,
												$reader_id
											)
										);
										continue;
									}
								}

								// プレースホルダーの作成
								$arrayValues[] = $qa_id;
								$arrayValues[] = $original_id;
								$arrayValues[] = $device_os;
								$arrayValues[] = $browser;
								$arrayValues[] = $language;

								if ( $exists_country ) {
									$country = null;
									if ( isset( $data[5] ) ) {
										$country = $data[5];
									}
									$arrayValues[] = $country;
								}

								if ( $exists_is_reject ) {
									$arrayValues[] = $is_reject;
								}

								$arrayValues[] = $nowstr;

								$place_holders[] = $place_holder;
							} else {
								$is_first_line = false;
							}
						}

						if ( ! empty( $arrayValues ) ) {
							// SQLの生成
							$table_name = $wpdb->prefix . 'qa_readers';

							$sql = 'INSERT INTO ' . $table_name . ' ' .
								$col_statment .
								//'(qa_id, original_id, UAos, UAbrowser, language, is_reject, update_date) ' .
								'VALUES ' . join( ',', $place_holders ) . ' ' .
								'ON DUPLICATE KEY UPDATE ' .
								'original_id = VALUES(original_id), update_date = CURDATE()';

							// SQL実行
							// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified inline $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted.
							$result = $wpdb->query( $wpdb->prepare( $sql, $arrayValues ) );
							if ( $result === false && $wpdb->last_error !== '' ) {
								$qahm_log->error( 'DB Error: ' . $wpdb->last_error . ' | Last Query: ' . $wpdb->last_query );
								// ---next
								$cron_status = 'Night>Sql error';
							}
						}
					}
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Create yesterday data>Loop>Insert>Media':
					// insert readers (if Dupplicate,then error)
					$data_ary = $wp_filesystem->get_contents_array( $ary_media_file );
					if ( ! empty( $data_ary ) ) {
						// プレースホルダーとインサートするデータ配列
						$arrayValues   = array();
						$place_holders = array();
						$seen_values   = array();

						$existing_media = QAHM_DB_Functions::get_utm_media();
						if ( $existing_media ) {
							foreach ( $existing_media as $media ) {
								$seen_values[ $media['utm_medium'] ] = true;
							}
						}

						// $data_aryはインサートするデータ配列が入っている
						// mediaのユニークはDB側で保証
						$is_first_line = true;
						foreach ( $data_ary as $line ) {
							if ( ! $is_first_line ) {
								// インサートするデータを格納
								$line   = str_replace( PHP_EOL, '', $line );
								$data   = $this->wrap_explode( "\t", $line );
								$medium = $this->wrap_trim( $data[0] ); // media
								if ( ! empty( $medium ) && ! isset( $seen_values[ $medium ] ) ) {
									$arrayValues[]          = $medium;
									$place_holders[]        = '(%s)';
									$seen_values[ $medium ] = true;
								}
							} else {
								$is_first_line = false;
							}
						}
						if ( ! empty( $arrayValues ) ) {
							$table_name = $wpdb->prefix . 'qa_utm_media';
							/*
							$sql        = 'INSERT IGNORE INTO ' . $table_name . ' ' .
								'(utm_medium) ' .
								'VALUES ' . join( ',', $place_holders );
							*/
							$sql = 'INSERT INTO ' . $table_name . ' ' .
							'(utm_medium) ' .
							'VALUES ' . join( ',', $place_holders ) .
							' ON DUPLICATE KEY UPDATE utm_medium = VALUES(utm_medium)';
							// SQL実行
						// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified inline $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted.
							$result = $wpdb->query( $wpdb->prepare( $sql, $arrayValues ) );
							if ( $result === false && $wpdb->last_error !== '' ) {
								$qahm_log->error( 'DB Error: ' . $wpdb->last_error . ' | Last Query: ' . $wpdb->last_query );
							}
						}
						// SQLの生成
					}
					// ---next
					$cron_status = 'Night>Create yesterday data>Loop>Insert>Utmcontent';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Create yesterday data>Loop>Insert>Utmcontent':
					$this->backup_prev_status( $cron_status );
					$data_ary = $wp_filesystem->get_contents_array( $ary_utmcontent_file );

					if ( ! empty( $data_ary ) ) {
						// プレースホルダーとインサートするデータ配列
						$arrayValues   = array();
						$place_holders = array();
						$seen_values   = array();

						$existing_content = QAHM_DB_Functions::get_utm_content();
						if ( $existing_content ) {
							foreach ( $existing_content as $content ) {
								$seen_values[ $content['utm_content'] ] = true;
							}
						}

						// $data_aryはインサートするデータ配列が入っている
						// utm_contentのユニークはDB側で保証
						$is_first_line = true;
						foreach ( $data_ary as $line ) {
							if ( ! $is_first_line ) {
								// インサートするデータを格納
								$line        = str_replace( PHP_EOL, '', $line );
								$data        = $this->wrap_explode( "\t", $line );
								$utm_content = $this->wrap_trim( $data[0] ); // utm_content
								if ( ! empty( $utm_content ) && ! isset( $seen_values[ $utm_content ] ) ) {
									$arrayValues[]               = $utm_content;
									$place_holders[]             = '(%s)';
									$seen_values[ $utm_content ] = true;
								}
							} else {
								$is_first_line = false;
							}
						}
						if ( ! empty( $arrayValues ) ) {
							$table_name = $wpdb->prefix . 'qa_utm_content';
							$sql        = 'INSERT INTO ' . $table_name . ' ' .
								'(utm_content) ' .
								'VALUES ' . join( ',', $place_holders ) .
								' ON DUPLICATE KEY UPDATE utm_content = VALUES(utm_content)';
							// SQL実行
							// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified inline $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted.
							$result = $wpdb->query( $wpdb->prepare( $sql, $arrayValues ) );
							if ( $result === false && $wpdb->last_error !== '' ) {
								$qahm_log->error( 'DB Error: ' . $wpdb->last_error . ' | Last Query: ' . $wpdb->last_query );
							}
						}
						// SQLの生成
					}

					$cron_status = 'Night>Create yesterday data>Loop>Insert>Sources';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Create yesterday data>Loop>Insert>Sources':
					$this->backup_prev_status( $cron_status );

					// insert utm_sources (If dupulicate,then nothing do)
					$data_ary = $wp_filesystem->get_contents_array( $ary_sources_file );

					if ( ! empty( $data_ary ) ) {
						// プレースホルダーとインサートするデータ配列
						$arrayValues   = array();
						$place_holders = array();
						$seen_lines    = array(); // 重複を避けるために見たデータを格納

						//高速化のため、先にメディアを全取得し、$media_table配列にセット
						// If medium is not null, This is original medium. Check midium_id.
						$table_name = $wpdb->prefix . 'qa_utm_media';
						$result     = $wpdb->get_results(
							"SELECT medium_id, utm_medium FROM `{$table_name}`"
						);

						$media_table = array();
						if ( $result ) {
							foreach ( $result as $row ) {
								$media_table[ $row->utm_medium ] = $row->medium_id;
							}
						}
						// 重複を避けながら、挿入データを作っていく
						$newdata_ary      = array();
						$domains_to_check = array();
						$maxcnt           = $this->wrap_count( $data_ary );
						for ( $iii = QAHM_Cron_Proc::TEMP_BODY_ELM_NO; $iii <= $maxcnt - 1; $iii++ ) {
							$dataline = str_replace( PHP_EOL, '', $data_ary[ $iii ] );
							if ( isset( $seen_lines[ $dataline ] ) ) {
								continue;
							}
							$seen_lines[ $dataline ] = true;
							$data                    = $this->wrap_explode( "\t", $dataline );
							$source                  = $data[0];
							$referer                 = $data[1];
							$source_domain           = $data[2];
							$medium                  = $data[3];
							$utm_term                = $data[4];
							$keyword                 = '';

							$is_uniq_source = true;
							$medium_id      = 0;
							if ( isset( $media_table[ $medium ] ) ) {
								$medium_id = $media_table[ $medium ];
							}

							// first source check
							if ( empty( $source ) ) {
								// 1st search engine check
								foreach ( SEARCH_ENGINES as $se ) {
									if ( $source_domain == $se['DOMAIN'] ) {
										if ( $se['SOURCE_ID'] > 0 ) {
											$is_uniq_source = false;
											break;
										} else {
											// other search engine. check keyword
											$parse_ref  = wp_parse_url( $referer );
											$query_perm = '';
											if ( isset( $parse_ref['query'] ) ) {
												$query_perm = $parse_ref['query'];
											}
											if ( ! empty( $query_perm ) ) {
												$keyword_perm_ary = $this->wrap_explode( ',', $se['QUERY_PERM'] );
												$perm_ary         = array();
												parse_str( $query_perm, $perm_ary );
												foreach ( $keyword_perm_ary as $keyword ) {
													if ( ! empty( $perm_ary[ $keyword ] ) ) {
														$source    = $se['NAME'];
														$keyword   = urldecode( $perm_ary[ $keyword ] );
														$medium_id = UTM_MEDIUM_ID['ORGANIC']; // organic
														break 2;

													}
												}
											} elseif ( $se['NOT_PROVIDED'] == 1 ) {
												if ( preg_match( '/' . preg_quote( $se['DOMAIN'] ) . '.$/', $referer ) ) {
													//no queryの検索エンジン
													$source    = $se['NAME'];
													$keyword   = '';
													$medium_id = UTM_MEDIUM_ID['ORGANIC']; // organic
													break;
												}
											}
										}
									}
								}
								// 2nd social check
								foreach ( SOCIAL_DOMAIN as $social ) {
									if ( $source_domain == $social['DOMAIN'] ) {
										if ( $social['SOURCE_ID'] > 0 ) {
											$is_uniq_source = false;
											break;
										}
									}
								}
							} else {
								// 3rd GCLID check
								foreach ( GCLID as $gclid ) {
									if ( $source_domain == $gclid['DOMAIN'] ) {
										if ( $medium_id == UTM_MEDIUM_ID['GCLID'] ) {
											if ( empty( $utm_term ) ) {
												$is_uniq_source = false;
											}
											break;
										}
									}
								}
							}

							// Is this really unique source ?
							// 1st db
							if ( $is_uniq_source ) {
								// 一時的に配列に保存
								$domains_to_check[ $source_domain ][] = array(
									'source'    => $source,
									'referer'   => $referer,
									'medium_id' => $medium_id,
									'utm_term'  => $utm_term,
									'keyword'   => $keyword,
								);
							}
						}
						//DBに存在しないか、チェックしていく。
						foreach ( $domains_to_check as $source_domain => $entries ) {
							$table_name = $wpdb->prefix . 'qa_utm_sources';
							$resids     = $wpdb->get_results(
								$wpdb->prepare(
									"SELECT source_id, utm_source, referer, medium_id, utm_term FROM `{$table_name}` WHERE source_domain = %s",
									$source_domain
								)
							);

							// データベースの結果を比較して一意性を確認
							foreach ( $entries as $entry ) {
								$is_uniq_source = true;

								foreach ( $resids as $raw ) {
									if ( $raw->utm_source == $entry['source'] && $raw->medium_id == $entry['medium_id'] && $raw->utm_term == $entry['utm_term'] && $raw->referer == $entry['referer'] ) {
										$is_uniq_source = false;
										break;
									}
								}

								// 一意だったものを配列に追加
								if ( $is_uniq_source ) {
									$arrayValues[] = $entry['source'];
									$arrayValues[] = $entry['referer'];
									$arrayValues[] = $source_domain;
									$arrayValues[] = $entry['medium_id'];
									$arrayValues[] = $entry['utm_term'];
									$arrayValues[] = mb_substr( $entry['keyword'], 0, 255 );

									$place_holders[] = '(%s, %s, %s, %d, %s, %s)';
								}
							}
						}

						if ( ! empty( $arrayValues ) ) {
							// SQLの生成
							$table_name = $wpdb->prefix . 'qa_utm_sources';
							$sql        = 'INSERT INTO ' . $table_name . ' ' .
								'(utm_source, referer, source_domain, medium_id, utm_term, keyword) ' .
								'VALUES ' . join( ',', $place_holders );
							// SQL実行
							// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders array used; identifiers fixed/whitelisted.
							$result = $wpdb->query( $wpdb->prepare( $sql, $arrayValues ) );
							if ( $result === false && $wpdb->last_error !== '' ) {
								$qahm_log->error( 'DB Error: ' . $wpdb->last_error . ' | Last Query: ' . $wpdb->last_query );
							}
						}
					}
					// ---next
					$cron_status = 'Night>Create yesterday data>Loop>Insert>Campaigns';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Create yesterday data>Loop>Insert>Campaigns':
					$this->backup_prev_status( $cron_status );

					// insert utm_campaigns (all recored innsert)
					$data_ary = $wp_filesystem->get_contents_array( $ary_campaigns_file );
					if ( ! empty( $data_ary ) ) {
						// プレースホルダーとインサートするデータ配列
						$arrayValues   = array();
						$place_holders = array();
						$seen_values   = array();

						$existing_campaigns = QAHM_DB_Functions::get_utm_campaigns();
						if ( $existing_campaigns ) {
							foreach ( $existing_campaigns as $campaign ) {
								$seen_values[ $campaign['utm_campaign'] ] = true;
							}
						}

						// $data_aryはインサートするデータ配列が入っている
						// campaignのユニークはDB側で保証
						$is_first_line = true;
						foreach ( $data_ary as $line ) {
							if ( ! $is_first_line ) {
								$line = str_replace( PHP_EOL, '', $line );
								$data = $this->wrap_explode( "\t", $line );
								// インサートするデータを格納
								$campaign = $this->wrap_trim( $data[0] );
								if ( ! empty( $campaign ) && ! isset( $seen_values[ $campaign ] ) ) {
									$arrayValues[]            = $campaign;
									$place_holders[]          = '(%s)';
									$seen_values[ $campaign ] = true;
								}
							} else {
								$is_first_line = false;
							}
						}
						if ( ! empty( $arrayValues ) ) {
							// SQLの生成
							$table_name = $wpdb->prefix . 'qa_utm_campaigns';
							$sql        = 'INSERT INTO ' . $table_name . ' ' .
								'(utm_campaign) ' .
								'VALUES ' . join( ',', $place_holders ) .
								' ON DUPLICATE KEY UPDATE utm_campaign = VALUES(utm_campaign)';
							// SQL実行
							// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified inline $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted.
							$result = $wpdb->query( $wpdb->prepare( $sql, $arrayValues ) );
							if ( $result === false && $wpdb->last_error !== '' ) {
								$qahm_log->error( 'DB Error: ' . $wpdb->last_error . ' | Last Query: ' . $wpdb->last_query );
							}
						}
					}
					// ---next
					$cron_status = 'Night>Create yesterday data>Loop>Insert>Pages';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Create yesterday data>Loop>Insert>Pages':
					$this->backup_prev_status( $cron_status );

					// insert pages (if Dupplicate,then update or nothing do)
					$data_ary = $this->wrap_unserialize( $this->wrap_get_contents( $ary_pages_file ) );
					// ---next
					$cron_status = 'Night>Create yesterday data>Loop>Insert>Pv_log';
					if ( ! empty( $data_ary ) ) {
						$today_str = $qahm_time->today_str();
						// プレースホルダーとインサートするデータ配列
						$arrayValues   = array();
						$place_holders = array();

						// verion hist用のプレースホルダーとインサートするデータ配列
						$verhist_values = array();
						$verhist_holers = array();
						$verhist_urlhsh = array();

						// $data_aryはインサートするデータ配列が入っている
						// もし入っていない場合はデフォルト値を挿入

						// url uniq check in array
						$uniq_ary     = array();
						$data_ary_max = $this->wrap_count( $data_ary );
						for ( $iii = self::TEMP_BODY_ELM_NO; $iii < $data_ary_max; $iii++ ) {
							$data = $data_ary[ $iii ];
							// インサートするデータを格納

							$page_url = $data[3];
							$url_hash = $data[4];

							// url uniq check in array
							if ( ! empty( $url_hash ) ) {
								// last
								if ( $iii == $data_ary_max - 1 ) {
									$uniq_ary[] = $data;
								} else {
									$is_url_uniq = true;
									$jjj         = $iii + 1;
									while ( $is_url_uniq ) {
										$cmpdata     = $data_ary[ $jjj ];
										$cmppage_url = $cmpdata[3];
										$cmpurl_hash = $cmpdata[4];

										if ( $url_hash == $cmpurl_hash ) {
											if ( $page_url == $cmppage_url ) {
												$is_url_uniq = false;
											}
										}
										++$jjj;
										// last
										if ( $jjj == $data_ary_max ) {
												break;
										}
									}
									if ( $is_url_uniq ) {
										$uniq_ary[] = $data;
									}
								}
							}
						}

						//サブドメインとメインドメインが両方登録されている場合
						//サブドメインのurlがメインドメインのtracking_idで記録されている
						//可能性があるため(同一qa_idでサブ・メインのセッションが記録された場合)
						//ここで補正を掛ける
						global $qahm_data_api;
						$siteary             = $qahm_data_api->get_sitemanage();
						$dmurl_to_tid_ary    = array();
						$need_tid_correction = true; //page_urlによるtracking_idの補正を必要とするか？

						foreach ( $siteary as $site ) {
							if ( $site['status'] == 255 ) {
								continue;
							}
							$site_info                        = array(
								'tracking_id' => $site['tracking_id'],
								'dmurl_len'   => $this->wrap_strlen( $site['url'] ),
							);
							$dmurl_to_tid_ary[ $site['url'] ] = $site_info;
						}

						//キーの長さ、つまりドメインURLの長さで降順ソート
						uksort(
							$dmurl_to_tid_ary,
							function ( $a, $b ) {
								return $this->wrap_strlen( $b ) - $this->wrap_strlen( $a );
							}
						);

						//そもそもドメインURLが1つしか登録がなければ、tracking_idの補正不要
						if ( $this->wrap_count( $dmurl_to_tid_ary ) <= 1 ) {
							$need_tid_correction = false;
						}

						// make insert data
						foreach ( $uniq_ary as $uniqline ) {
							//$uniqdata = $this->wrap_explode( "\t", $uniqline );
							$uniqdata = $uniqline;

							$tracking_id = $uniqdata[0];
							$page_url    = $uniqdata[3];
							// インサートするデータを格納
							//page_urlによるトラッキングIDの補正が必要
							if ( $need_tid_correction ) {
								//マッチング前にpage_urlからスキーマ除去
								$scheme = $this->wrap_substr( $page_url, 0, 8 );  // 最初の8文字を取得
								if ( $scheme === 'https://' ) {
									$no_sc_page_url = $this->wrap_substr( $page_url, 8 );
								} else {
									$no_sc_page_url = $this->wrap_substr( $page_url, 7 );  //'http://'の場合、またはそれ以外の場合
								}

								foreach ( $dmurl_to_tid_ary as $dmurl => $tid_and_dmurl_len ) {
									if ( strncmp( $no_sc_page_url, $dmurl, $tid_and_dmurl_len['dmurl_len'] ) === 0 ) {
										$tracking_id = $tid_and_dmurl_len['tracking_id'];
										break;
									}
								}
							}

							$type = $uniqdata[1];
							$id   = $uniqdata[2];
							//$page_url    = $uniqdata[3];
							$url_hash      = $uniqdata[4];
							$page_title    = $uniqdata[5];
							$page_title    = mb_substr( $page_title, 0, 128 );
							$path_url_hash = $uniqdata[6];

							$page_id        = 0;
							$update_page_id = 0;
							if ( ! empty( $url_hash ) ) {
								$table_name = $wpdb->prefix . 'qa_pages';
								$query      = 'SELECT page_id, url, title FROM ' . $table_name . ' WHERE url_hash = %s';
								// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted.
								$result = $wpdb->get_results( $wpdb->prepare( $query, $url_hash ) );
								if ( ! empty( $result ) ) {
									foreach ( $result as $raw ) {
										if ( $page_url === $raw->url ) {
											$page_id = (int) $raw->page_id;
											if ( $page_title !== $raw->title ) {
												$update_page_id = (int) $raw->page_id;
											}
											break;
										}
									}
								}
							}

							// qa_pages への挿入配列を作成（常に作成）
							$arrayValues[]   = $tracking_id;
							$arrayValues[]   = $type;
							$arrayValues[]   = $id;
							$arrayValues[]   = $page_url;
							$arrayValues[]   = $url_hash;
							$arrayValues[]   = $path_url_hash;
							$arrayValues[]   = $page_title;
							$arrayValues[]   = $today_str;
							$place_holders[] = '(%s, %s, %d, %s, %s, %s, %s, %s)';

							// version_hist の既存レコード確認
							$version_id_ary_from_db = false;
							if ( 0 < $page_id ) {
								$table_name             = $qahm_db->prefix . 'qa_page_version_hist';
								$query                  = 'SELECT version_id FROM ' . $table_name . ' WHERE page_id = %d';
								$version_id_ary_from_db = $qahm_db->get_results( $qahm_db->prepare( $query, $page_id ), ARRAY_A );
							}

							if ( false === $version_id_ary_from_db || empty( $version_id_ary_from_db ) ) {
								foreach ( QAHM_DEVICES as $qahm_dev ) {
									$verhist_values[] = $page_id;
									$verhist_values[] = $qahm_dev['id'];
									$verhist_values[] = 1;
									$verhist_values[] = ''; // base_html
									$verhist_holers[] = '(%d, %d, %d, %s, now(), now())';
									if ( 0 === $page_id ) {
										$verhist_urlhsh[] = $url_hash;
									}
								}
							}
						}

						/*
						 * エラーハンドリングの設計方針:
						 *
						 * qa_pages および qa_page_version_hist でのエラーは以下の理由で処理を継続します:
						 *
						 * 1. $ary_tids_file 生成の重要性: 後続のインデックス作成・サマリー作成に必須
						 * 2. システム全体の安定性: 部分的なエラーでバッチ全体を停止させない
						 * 3. エラーの記録: すべてのエラーはログに記録され、後で確認可能
						 * 4. データ整合性: 重複チェックロジックにより、データの整合性を保証
						 *
						 * この設計により、QA Analytics から QA Assistants へのデータ移行時の
						 * 問題（SQL エラーによる処理中断）を回避します。
						 */

						if ( ! empty( $arrayValues ) ) {
							$table_name = $wpdb->prefix . 'qa_pages';
							$sql        = 'INSERT INTO ' . $table_name . ' ' .
								'(tracking_id, wp_qa_type, wp_qa_id, url, url_hash, path_url_hash, title, update_date) ' .
								'VALUES ' . $this->wrap_implode( ',', $place_holders ) . ' ' .
								'ON DUPLICATE KEY UPDATE ' .
								'title = VALUES(title), update_date = VALUES(update_date)';

							// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders array used; identifiers fixed/whitelisted.
							$result = $wpdb->query( $wpdb->prepare( $sql, $arrayValues ) );
							if ( false === $result && '' !== $wpdb->last_error ) {
								$qahm_log->error( 'DB Error in qa_pages insert: ' . $wpdb->last_error . ' | Last Query: ' . $wpdb->last_query );
							}
						}

						if ( ! empty( $verhist_values ) ) {
							// page_id が 0 の場合、url_hash から実際の page_id を取得
							$urlhsh_idx = 0;
							foreach ( $verhist_values as $idx => $verhist_value ) {
								if ( 0 === $verhist_value ) {
									$table_name = $wpdb->prefix . 'qa_pages';
									// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is prefixed and safe.
									$result = $wpdb->get_results(
										$wpdb->prepare(
											"SELECT page_id FROM `{$table_name}` WHERE url_hash = %s",
											$verhist_urlhsh[ $urlhsh_idx ]
										)
									);
									if ( ! empty( $result ) ) {
										foreach ( $result as $raw ) {
											$verhist_values[ $idx ] = (int) $raw->page_id;
										}
									}
									++$urlhsh_idx;
								}
							}

							$table_name = $wpdb->prefix . 'qa_page_version_hist';
							$query      = 'INSERT INTO ' . $table_name . ' (page_id, device_id, version_no, base_html, update_date, insert_datetime) ' .
								'VALUES ' . $this->wrap_implode( ',', $verhist_holers );

							// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders array used; identifiers fixed/whitelisted.
							$result = $wpdb->query( $wpdb->prepare( $query, $verhist_values ) );
							if ( false === $result && '' !== $wpdb->last_error ) {
								$qahm_log->error( 'DB Error in qa_page_version_hist insert: ' . $wpdb->last_error . ' | Last Query: ' . $wpdb->last_query );
							}
						}
					}

					$qahm_db->version_hist_dirlist_mem_reset();

					$this->set_next_status( $cron_status );
					break;

				case 'Night>Create yesterday data>Loop>Insert>Pv_log':
					$this->backup_prev_status( $cron_status );

					// ---next
					$cron_status = 'Night>Create yesterday data>Loop>Insert>Search_log';

					// insert pv_log (All recored insert)
					$data_ary = $wp_filesystem->get_contents_array( $ary_pv_file );
					if ( ! empty( $data_ary ) ) {
						// プレースホルダーとインサートするデータ配列
						$arrayValues   = array();
						$place_holders = array();

						// $data_aryはインサートするデータ配列が入っている
						// もし入っていない場合はデフォルト値を挿入
						$is_first_line = true;
						foreach ( $data_ary as $line ) {
							if ( ! $is_first_line ) {
								$line = str_replace( PHP_EOL, '', $line );
								$data = $this->wrap_explode( "\t", $line );
								// インサートするデータを格納

								$qa_id         = $data[0];
								$url_hash      = $data[1];
								$page_url      = $data[2];
								$device_code   = $data[3];
								$source        = $data[4];
								$referer       = $data[5];
								$source_domain = $data[6];
								$medium        = $data[7];
								$campaign      = $data[8];
								$utm_term      = $data[9];
								$session_no    = $data[10];
								$lp_time       = $data[11];
								$now_pv        = $data[12];
								$page_speed    = $data[13];
								$time_on_page  = $data[14];
								$is_last       = $data[15];
								$is_new_user   = $data[16];
								$utm_content   = $data[17];

								// each id check

								$reader_id = 0;
								if ( ! empty( $qa_id ) ) {
									$table_name = $wpdb->prefix . 'qa_readers';
									$result     = $wpdb->get_results(
										$wpdb->prepare(
											"SELECT reader_id FROM `{$table_name}` WHERE qa_id = %s",
											$qa_id
										)
									);
									if ( ! empty( $result ) ) {
										$reader_id = $result[0]->reader_id;
									}
								}

								$page_id     = 0;
								$tracking_id = '';
								if ( ! empty( $url_hash ) ) {
									$table_name = $wpdb->prefix . 'qa_pages';
									$result     = $wpdb->get_results(
										$wpdb->prepare(
											"SELECT page_id, url, tracking_id FROM `{$table_name}` WHERE url_hash = %s",
											$url_hash
										)
									);
									if ( ! empty( $result ) ) {
										foreach ( $result as $raw ) {
											if ( $page_url == $raw->url ) {
												$page_id     = $raw->page_id;
												$tracking_id = $raw->tracking_id;
											}
										}
									}
								}

								$medium_id = 0;

								// check medium_id
								if ( ! empty( $medium ) ) {
									$table_name = $wpdb->prefix . 'qa_utm_media';
									$result     = $wpdb->get_results(
										$wpdb->prepare(
											"SELECT medium_id FROM `{$table_name}` WHERE utm_medium = %s",
											$medium
										)
									);

									foreach ( $result as $raw ) {
										$medium_id = $raw->medium_id;
									}
								}

								$utmcontent_id = 0;
								if ( ! empty( $utm_content ) ) {
									$table_name = $wpdb->prefix . 'qa_utm_content';
									$result     = $wpdb->get_results(
										$wpdb->prepare(
											"SELECT content_id FROM `{$table_name}` WHERE utm_content = %s",
											$utm_content
										)
									);

									foreach ( $result as $raw ) {
										$utmcontent_id = $raw->content_id;
									}
								}

								$source_id = 0;
								// first source_id check
								if ( empty( $source ) ) {
									// 1st search engine check
									foreach ( SEARCH_ENGINES as $se ) {
										if ( $source_domain == $se['DOMAIN'] ) {
											$source    = $se['NAME'];
											$medium_id = UTM_MEDIUM_ID['ORGANIC'];
											if ( $se['SOURCE_ID'] > 0 ) {
												$source_id = $se['SOURCE_ID'];
												break;
											}
										}
									}
									if ( $source_id == 0 ) {
										// 2nd social check
										foreach ( SOCIAL_DOMAIN as $social ) {
											if ( $source_domain == $social['DOMAIN'] ) {
												$medium_id = UTM_MEDIUM_ID['SOCIAL'];
												if ( $social['SOURCE_ID'] > 0 ) {
													$source_id = $social['SOURCE_ID'];
													break;
												}
											}
										}
									}
								} else {
									// 3rd only GLCID check
									foreach ( GCLID as $gclid ) {
										if ( $source_domain == $gclid['DOMAIN'] ) {
											if ( $medium_id == UTM_MEDIUM_ID['GCLID'] ) {
												if ( empty( $utm_term ) ) {
													$source_id = $gclid['SOURCE_ID'];
													break;
												}
											}
										}
									}
								}

								if ( $source_id == 0 ) {
									$table_name = $wpdb->prefix . 'qa_utm_sources';
									$result     = $wpdb->get_results(
										$wpdb->prepare(
											"SELECT source_id, utm_source, referer, medium_id, utm_term, keyword FROM `{$table_name}` WHERE source_domain = %s",
											$source_domain
										)
									);

									foreach ( $result as $raw ) {
										if ( $raw->utm_source == $source && $raw->medium_id == $medium_id ) {
											if ( $raw->utm_term == $utm_term && $raw->referer == $referer ) {
													$source_id = $raw->source_id;
											}
										}
									}
								}

								$campaign_id = 0;
								if ( ! empty( $campaign ) ) {
									$table_name = $wpdb->prefix . 'qa_utm_campaigns';
									$result     = $wpdb->get_results(
										$wpdb->prepare(
											"SELECT campaign_id FROM `{$table_name}` WHERE utm_campaign = %s",
											$campaign
										)
									);

									if ( ! empty( $result ) ) {
										$campaign_id = $result[0]->campaign_id;
									}
								}

								if ( $page_id > 0 ) {
									// make insert array
									$arrayValues[] = (int) $reader_id;
									$arrayValues[] = (int) $page_id;
									$arrayValues[] = (int) $device_code;
									$arrayValues[] = (int) $source_id;
									$arrayValues[] = (int) $medium_id;
									$arrayValues[] = (int) $campaign_id;
									$arrayValues[] = (int) $utmcontent_id;
									$arrayValues[] = (int) $session_no;
									$arrayValues[] = $qahm_time->unixtime_to_str( $lp_time );
									$arrayValues[] = (int) $now_pv;
									$arrayValues[] = (int) $page_speed;
									$arrayValues[] = (int) $time_on_page;
									$arrayValues[] = (int) $is_last;
									$arrayValues[] = (int) $is_new_user;

									//search raw_X file
									$raw_dir    = $this->get_raw_dir_path( $tracking_id, $url_hash );
									$raw_name   = $qa_id . '_' . $lp_time;
									$ver_row_no = 1;

									$file_p      = $raw_dir . $raw_name . '-p' . '.php';
									$file_p_done = $raw_dir . $raw_name . '-p-done' . '.php';
									$is_raw_p    = 0;
									if ( $wp_filesystem->exists( $file_p ) ) {
										$p_ary = $wp_filesystem->get_contents_array( $file_p );
										$p_str = '';
										if ( ! empty( $p_ary ) ) {
											$max_p_no = $this->wrap_count( $p_ary );
											for ( $jjj = self::HEADER_ELM; $jjj < $max_p_no; $jjj++ ) {
												if ( $jjj === $ver_row_no ) {
													$is_raw_p = (int) $p_ary[ $jjj ];
												}
												$p_str .= $p_ary[ $jjj ];
											}
										}
										if ( $p_str !== '' ) {
											$wp_filesystem->put_contents( $file_p_done, $p_str );
											$wp_filesystem->delete( $file_p );
										}
									}
									// raw_cファイルは base_selecorのcaseで処理
									$file_c   = $raw_dir . $raw_name . '-c' . '.php';
									$is_raw_c = 0;
									if ( $wp_filesystem->exists( $file_c ) ) {
										$c_ary = $wp_filesystem->get_contents_array( $file_c );
										if ( ! empty( $c_ary ) ) {
											$is_raw_c = (int) $c_ary[ $ver_row_no ];
										}
									}

									$file_e      = $raw_dir . $raw_name . '-e' . '.php';
									$file_e_done = $raw_dir . $raw_name . '-e-done' . '.php';
									$is_raw_e    = 0;
									if ( $wp_filesystem->exists( $file_e ) ) {
										$e_ary = $wp_filesystem->get_contents_array( $file_e );
										$e_str = '';
										if ( ! empty( $e_ary ) ) {
											$max_e_no = $this->wrap_count( $e_ary );
											for ( $jjj = self::HEADER_ELM; $jjj < $max_e_no; $jjj++ ) {
												if ( $jjj === $ver_row_no ) {
													$is_raw_e = (int) $e_ary[ $jjj ];
												}
												$e_str .= $e_ary[ $jjj ];
											}
										}
										if ( $e_str !== '' ) {
											$wp_filesystem->put_contents( $file_e_done, $e_str );
											$wp_filesystem->delete( $file_e );
										}
									}

									$arrayValues[]   = (int) $is_raw_p;
									$arrayValues[]   = (int) $is_raw_c;
									$arrayValues[]   = (int) $is_raw_e;
									$place_holders[] = '(%d, %d, %d, %d, %d, %d, %d, %d, %s, %d, %d, %d, %d, %d, %d, %d, %d)';
								}
							} else {
								$is_first_line = false;
							}
						}
						if ( ! empty( $arrayValues ) ) {
							// SQLの生成
							$table_name = $wpdb->prefix . 'qa_pv_log';
							$sql        = 'INSERT IGNORE INTO ' . $table_name . ' ' .
								'(reader_id, page_id, device_id, source_id, medium_id, campaign_id, content_id, session_no, access_time, pv, speed_msec, browse_sec, is_last, is_newuser, is_raw_p, is_raw_c, is_raw_e) ' .
								'VALUES ' . join( ',', $place_holders );

							// SQL実行
							// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified inline $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted.
							$result = $wpdb->query( $wpdb->prepare( $sql, $arrayValues ) );

							if ( $result > 0 ) {
								$cnt = 0;
								if ( $wp_filesystem->exists( $yday_pvmaxcnt_file ) ) {
									$cnt = $wp_filesystem->get_contents( $yday_pvmaxcnt_file );
								}
								$yday_pvmaxcnt = $result + (int) $cnt;
								$wp_filesystem->put_contents( $yday_pvmaxcnt_file, $yday_pvmaxcnt );

							}

							if ( $result === false && $wpdb->last_error !== '' ) {
								$qahm_log->error( 'DB Error: ' . $wpdb->last_error . ' | Last Query: ' . $wpdb->last_query );
								// ---next
								$cron_status = 'Night>Sql error';
							}
						}
					}
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Create yesterday data>Loop>Insert>Search_log':
					$this->backup_prev_status( $cron_status );

					// insert search log (数が少ないし、where句必須なので一行ずつインサート)
					$data_ary = $wp_filesystem->get_contents_array( $ary_wp_s_file );
					if ( ! empty( $data_ary ) ) {
						// プレースホルダーとインサートするデータ配列
						$arrayValues   = array();
						$place_holders = array();

						// $data_aryはインサートするデータ配列が入っている
						// もし入っていない場合はデフォルト値を挿入
						$is_first_line = true;
						foreach ( $data_ary as $line ) {
							if ( ! $is_first_line ) {
								$line = str_replace( PHP_EOL, '', $line );
								$data = $this->wrap_explode( "\t", $line );
								// データを取り出し
								$qa_id        = $data[0];
								$lp_time      = $data[1];
								$wp_s_keyword = $data[2];

								$reader_id = 0;
								// get readers id.
								if ( ! empty( $qa_id ) ) {
									$table_name = $wpdb->prefix . 'qa_readers';
									$result     = $wpdb->get_results(
										$wpdb->prepare(
											"SELECT reader_id FROM `{$table_name}` WHERE qa_id = %s",
											$qa_id
										)
									);
									$reader_id  = $result[0]->reader_id;
								}

								$pv_id = 0;
								// get readers id.
								if ( ! empty( $lp_time ) && ! empty( $reader_id ) ) {
									$search_time = $qahm_time->unixtime_to_str( $lp_time );
									$table_name  = $wpdb->prefix . 'qa_pv_log';
									$result      = $wpdb->get_results(
										$wpdb->prepare(
											"SELECT pv_id FROM `{$table_name}` WHERE access_time = %s AND reader_id = %d",
											$search_time,
											$reader_id
										)
									);
									$pv_id       = $result[0]->pv_id;
								}

								if ( ! empty( $pv_id ) && ! empty( $wp_s_keyword ) ) {
									// make insert array
									$arrayValues[] = (int) $pv_id;
									$arrayValues[] = (string) $wp_s_keyword;
									// プレースホルダーの作成
									$place_holders[] = '(%d, %s)';
								}
							} else {
								$is_first_line = false;
							}
						}
						if ( ! empty( $arrayValues ) ) {
							// SQLの生成
							$table_name = $wpdb->prefix . 'qa_search_log';
							$sql        = 'INSERT INTO ' . $table_name . ' ' .
							'(pv_id, query) ' .
							'VALUES ' . join( ',', $place_holders );

							// SQL実行
						// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified inline $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted.
							$result = $wpdb->query( $wpdb->prepare( $sql, $arrayValues ) );
							if ( $result === false && $wpdb->last_error !== '' ) {
								$qahm_log->error( 'DB Error: ' . $wpdb->last_error . ' | Last Query: ' . $wpdb->last_query );
							}
						}
					}
					// ---next
					$cron_status = 'Night>Create yesterday data>Loop>End';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Create yesterday data>Loop>End':
					$this->backup_prev_status( $cron_status );

					$loopstart = 0;
					if ( $wp_filesystem->exists( $yday_loopcount_file ) ) {
						$loopstart_ary = $wp_filesystem->get_contents_array( $yday_loopcount_file );
						$is_loop_end   = '';
						if ( $this->wrap_count( $loopstart_ary ) >= 2 ) {
							$is_loop_end = $loopstart_ary[1];
						}

						if ( $is_loop_end == self::LOOPLAST_MSG ) {
							$cron_status = 'Night>Create yesterday data>End';
						} else {
							$nowloop = (int) $loopstart_ary[0] + self::PV_LOOP_MAX;
							$wp_filesystem->put_contents( $yday_loopcount_file, $nowloop );
							$cron_status = 'Night>Create yesterday data>Loop>Start';
						}
					} else {
						// 　ファイルがない。異常終了
						$cron_status = 'Night>End';
						throw new Exception( 'cronステータスファイルの生成に失敗しました。終了します。' );
					}
					// ---next
					$errmsg = $this->set_next_status( $cron_status );

					// loop exit
					$while_continue = false;
					break;

				case 'Night>Create yesterday data>End':
					$this->backup_prev_status( $cron_status );

					//dbinに移動する
					if ( $wp_filesystem->exists( $dbin_session_file ) ) {
						$dbin_ary      = $wp_filesystem->get_contents_array( $dbin_session_file );
						$is_first_line = true;
						foreach ( $dbin_ary as $del ) {
							if ( ! $is_first_line ) {
								$del      = $this->wrap_trim( $del );
								$filename = basename( $del );
								$contents = $wp_filesystem->get_contents( $del );
								$putfile  = $readersdbin_dir . $filename;
								$putfile  = $this->wrap_trim( $putfile );
								$wp_filesystem->put_contents( $putfile, $contents );
								$wp_filesystem->delete( $del );
							} else {
								$is_first_line = false;
							}
						}
						if ( QAHM_DEBUG <= QAHM_DEBUG_LEVEL['staging'] ) {
							$wp_filesystem->delete( $dbin_session_file );
						}
					}

					if ( QAHM_DEBUG <= QAHM_DEBUG_LEVEL['staging'] ) {
						// delete temp files
						if ( $wp_filesystem->exists( $yday_loopcount_file ) ) {
							$wp_filesystem->delete( $yday_loopcount_file );
						}
						if ( $wp_filesystem->exists( $ary_readers_file ) ) {
							$wp_filesystem->delete( $ary_readers_file );
						}
						if ( $wp_filesystem->exists( $ary_pages_file ) ) {
							$wp_filesystem->delete( $ary_pages_file );
						}
						if ( $wp_filesystem->exists( $ary_media_file ) ) {
							$wp_filesystem->delete( $ary_media_file );
						}
						if ( $wp_filesystem->exists( $ary_utmcontent_file ) ) {
							$wp_filesystem->delete( $ary_utmcontent_file );
						}
						if ( $wp_filesystem->exists( $ary_sources_file ) ) {
							$wp_filesystem->delete( $ary_sources_file );
						}
						if ( $wp_filesystem->exists( $ary_campaigns_file ) ) {
							$wp_filesystem->delete( $ary_campaigns_file );
						}
						if ( $wp_filesystem->exists( $ary_pv_file ) ) {
							$wp_filesystem->delete( $ary_pv_file );
						}
						if ( $wp_filesystem->exists( $ary_wp_s_file ) ) {
							$wp_filesystem->delete( $ary_wp_s_file );
						}
						if ( $wp_filesystem->exists( $now_pv_loop_maxfile ) ) {
							$wp_filesystem->delete( $now_pv_loop_maxfile );
						}
						if ( $wp_filesystem->exists( $now_pvlog_count_fetchfile ) ) {
							$wp_filesystem->delete( $now_pvlog_count_fetchfile );
						}
					}
					// ---next
					$cron_status = 'Night>Make view file>Start';
					$this->set_next_status( $cron_status );
					break;

				// ----------
				// SQL Error
				// ----------
				case 'Night>Sql error':
					$this->backup_prev_status( $cron_status );

					$qahm_log->error( 'SQL Error:' . $wpdb->last_query );
					// ---next
					$cron_status = 'Night>Delete>Start';
					$this->set_next_status( $cron_status );
					break;

				// ----------
				// Make view data
				// ----------
				case 'Night>Make view file>Start':
					$this->backup_prev_status( $cron_status );

					// ---next
					$cron_status = 'Night>Make view file>Version_hist>Start';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Make view file>Version_hist>Start':
					$this->backup_prev_status( $cron_status );

					// reader
					if ( ! $wp_filesystem->exists( $vw_verhst_dir ) ) {
						$wp_filesystem->mkdir( $vw_verhst_dir );
					}
					if ( ! $wp_filesystem->exists( $vw_verhst_dir . 'index/' ) ) {
						$wp_filesystem->mkdir( $vw_verhst_dir . 'index/' );
					}

					// >Update selector
					// loop max init
					$loopstart = 0;
					$wp_filesystem->put_contents( $raw_loopcount_file, $loopstart );
					$wp_filesystem->put_contents( $now_raw_loop_maxfile, self::RAW_LOOP_MAX );

					// get 7 days pv_ids
					$yday_pvmaxcnt = (int) $wp_filesystem->get_contents( $yday_pvmaxcnt_file );
					$table_pv      = $wpdb->prefix . 'qa_pv_log';
					$table_readers = $wpdb->prefix . 'qa_readers';
					$table_pages   = $wpdb->prefix . 'qa_pages';
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query uses only $wpdb->prefix (internal) and fixed strings; no user input.
					$result        = $wpdb->get_results(
						"
						SELECT pv.page_id, pv.device_id, pv.access_time, readers.qa_id, pages.tracking_id, pages.url_hash
						FROM `{$table_pv}` AS pv
						INNER JOIN `{$table_readers}` AS readers ON pv.reader_id = readers.reader_id
						INNER JOIN `{$table_pages}` AS pages ON pv.page_id = pages.page_id
						WHERE pv.access_time >= (NOW() - INTERVAL 3 DAY) AND 0 < pv.is_raw_c
						ORDER BY pv.page_id DESC
						",
						ARRAY_A
					);

					if ( ! empty( $result ) ) {
						$tmpslz = $this->wrap_serialize( $result );
						$this->wrap_put_contents( $ary_new_pvrows_file, $tmpslz );
						unset( $tmpslz );
						// next
						$cron_status = 'Night>Make view file>Version_hist>Make';
					} else {
						// データがない場合も正常ルートを通る
						$cron_status = 'Night>Make view file>Version_hist>End';
					}

					// ---next
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Make view file>Version_hist>Make':
					$this->backup_prev_status( $cron_status );

					global $qahm_db;
					global $wp_filesystem;
					//最初のpage_id用のindex配列を作る。page_id用の配列はIDですぐ飛べるように、1スタートの固定長にする。
					if ( $wp_filesystem->exists( $vw_verhst_dir . 'index/' ) ) {
						$allindexfiles = $this->wrap_dirlist( $vw_verhst_dir . 'index/' );
						if ( $allindexfiles ) {
							foreach ( $allindexfiles as $file ) {
								$filename = $file['name'];
								if ( is_file( $vw_verhst_dir . 'index/' . $filename ) ) {
									$indexary                         = $this->wrap_explode( '-', $filename );
									$aryindex                         = floor( (int) $indexary[0] / self::ID_INDEX_MAX10MAN );
									$verhst_pageid_index[ $aryindex ] = $this->wrap_unserialize( $this->wrap_get_contents( $vw_verhst_dir . 'index/' . $filename ) );
								}
							}
						}
					}
					if ( ! isset( $verhst_pageid_index[0] ) ) {
						$verhst_pageid_index[0] = array_fill( 1, self::ID_INDEX_MAX10MAN, false );
					}

					//version_hist tableから本日インサートされたversion no1のファイルを保存していく。
					$table_name = $wpdb->prefix . 'qa_page_version_hist';
					$query      = 'SELECT version_id,page_id FROM ' . $table_name . ' WHERE version_no = 1 ORDER BY page_id DESC';
					$allverids  = $qahm_db->get_results( $query, ARRAY_A );

					if ( $allverids ) {
						foreach ( $allverids as $verid ) {
							//ファイルが存在してたら飛ばす
							$chkfile = $verid['version_id'] . '_version.php';
							$pageid  = (int) $verid['page_id'];
							if ( $wp_filesystem->exists( $vw_verhst_dir . $chkfile ) ) {
								continue;
							}
							//存在しないので作成
							$table_name = $wpdb->prefix . 'qa_page_version_hist';
							$allrecords = $qahm_db->get_results(
								$wpdb->prepare(
									"SELECT * FROM `{$table_name}` WHERE version_no = 1 AND page_id = %d",
									$pageid
								)
							);

							if ( $allrecords ) {
								foreach ( $allrecords as $allrecord ) {
									//page_idのindexを作成(1スタート
									$alr_verid = (int) $allrecord->version_id;
									if ( $pageid === 0 ) {
										continue;
									}
									$nowidx = floor( $pageid / self::ID_INDEX_MAX10MAN );
									if ( ! isset( $verhst_pageid_index[ $nowidx ] ) ) {
										//初期化
										$start                          = self::ID_INDEX_MAX10MAN * $nowidx + 1;
										$verhst_pageid_index[ $nowidx ] = array_fill( $start, self::ID_INDEX_MAX10MAN, false );
									}
									//version_idの保存
									if ( $verhst_pageid_index[ $nowidx ][ $pageid ] !== false ) {
										$verid_ary  = $verhst_pageid_index[ $nowidx ][ $pageid ];
										$is_verfind = false;
										foreach ( $verid_ary as $verid ) {
											if ( (int) $alr_verid === (int) $verid ) {
												$is_verfind = true;
												break;
											}
										}
										if ( ! $is_verfind ) {
											$verhst_pageid_index[ $nowidx ][ $pageid ][] = $alr_verid;
										}
									} else {
										$verhst_pageid_index[ $nowidx ][ $pageid ] = array( $alr_verid );
									}
									//ファイルに保存
									$newfile = $alr_verid . '_version.php';
									//file フォーマットをQAアナリティクスにあわせる
									$ary    = array();
									$ary[0] = $allrecord;
									$this->wrap_put_contents( $vw_verhst_dir . $newfile, $this->wrap_serialize( $ary ) );
									unset( $ary );
								}
							}
						}
					}
					//最後にpageidのインデックスを保存しておく
					for ( $jjj = 0; $jjj < $this->wrap_count( $verhst_pageid_index ); $jjj++ ) {
						$start_index       = $jjj * self::ID_INDEX_MAX10MAN + 1;
						$end_index         = $start_index + self::ID_INDEX_MAX10MAN - 1;
						$pageid_index_file = $start_index . '-' . $end_index . '_pageid.php';
						$this->wrap_put_contents( $vw_verhst_dir . 'index/' . $pageid_index_file, $this->wrap_serialize( $verhst_pageid_index[ $jjj ] ) );
					}

					$cron_status = 'Night>Make view file>Version_hist>Update selector';
					$this->set_next_status( $cron_status );
					$while_continue = false;
					break;

				case 'Night>Make view file>Version_hist>Update selector':
					$this->backup_prev_status( $cron_status );

					//1st get today's pv array
					$data_ary = $this->wrap_get_contents( $ary_new_pvrows_file );
					$data_ary = $this->wrap_unserialize( $data_ary );

					if ( ! empty( $data_ary ) ) {
						// initialize
						$loop_start = (int) $wp_filesystem->get_contents( $raw_loopcount_file );

						$loopadd = self::RAW_LOOP_MAX;
						if ( $wp_filesystem->exists( $now_raw_loop_maxfile ) ) {
							$loopadd = $wp_filesystem->get_contents( $now_raw_loop_maxfile );
						}
						$loop_end = $loop_start + $loopadd;

						if ( $loop_end > $this->wrap_count( $data_ary ) ) {
							$loop_end = $this->wrap_count( $data_ary );
						}
						$prev_page_id = 0;
						$recent_vid   = array();
						for ( $iii = $loop_start; $iii < $loop_end; $iii++ ) {

							$page_id     = (int) $data_ary[ $iii ]['page_id'];
							$device_id   = (int) $data_ary[ $iii ]['device_id'];
							$access_time = $data_ary[ $iii ]['access_time'];
							$qa_id       = $data_ary[ $iii ]['qa_id'];
							$tracking_id = $data_ary[ $iii ]['tracking_id'];
							$url_hash    = $data_ary[ $iii ]['url_hash'];

							$lp_time = $qahm_time->str_to_unixtime( $access_time );

							$raw_dir  = $this->get_raw_dir_path( $tracking_id, $url_hash );
							$raw_name = $qa_id . '_' . $lp_time;
							$file_c   = $raw_dir . $raw_name . '-c' . '.php';
							if ( ! $wp_filesystem->exists( $file_c ) ) {
								//file_cがないということは、このpvは処理済み
								$wp_filesystem->put_contents( $raw_loopcount_file, $iii );
								continue;
							}
							//file_cがあるので実行

							if ( $prev_page_id != (int) $page_id ) {
								$prev_page_id = (int) $page_id;
								$recent_vid   = array();
								//1st open version_hist 3device files
								$table_name     = $qahm_db->prefix . 'view_page_version_hist';
								$query          = 'SELECT version_id,device_id FROM ' . $table_name . ' WHERE page_id = %d';
								$version_id_ary = $qahm_db->get_results( $qahm_db->prepare( $query, $page_id ), ARRAY_A );
								$base_selectors = array();
								if ( ! empty( $version_id_ary ) ) {
									$recent_vid = array(
										QAHM_DEVICES['desktop']['id']    => 0,
										QAHM_DEVICES['tablet']['id']     => 0,
										QAHM_DEVICES['smartphone']['id'] => 0,
									);
									foreach ( $version_id_ary as $vidary ) {
										switch ( $vidary['device_id'] ) {
											case QAHM_DEVICES['desktop']['id']:
												if ( $recent_vid[ QAHM_DEVICES['desktop']['id'] ] < (int) $vidary['version_id'] ) {
													$recent_vid[ QAHM_DEVICES['desktop']['id'] ] = (int) $vidary['version_id'];
												}
												break;
											case QAHM_DEVICES['tablet']['id']:
												if ( $recent_vid[ QAHM_DEVICES['tablet']['id'] ] < (int) $vidary['version_id'] ) {
													$recent_vid[ QAHM_DEVICES['tablet']['id'] ] = (int) $vidary['version_id'];
												}
												break;
											case QAHM_DEVICES['smartphone']['id']:
												if ( $recent_vid[ QAHM_DEVICES['smartphone']['id'] ] < (int) $vidary['version_id'] ) {
													$recent_vid[ QAHM_DEVICES['smartphone']['id'] ] = (int) $vidary['version_id'];
												}
												break;
										}
									}
									foreach ( QAHM_DEVICES as $dev ) {
										$tmpfile = $recent_vid[ $dev['id'] ] . '_version.php';
										if ( $wp_filesystem->exists( $vw_verhst_dir . $tmpfile ) ) {
											$tmpslz                       = $this->wrap_get_contents( $vw_verhst_dir . $tmpfile );
											$tmpary                       = $this->wrap_unserialize( $tmpslz );
											$base_selectors[ $dev['id'] ] = $tmpary[0]->base_selector;
										}
									}
								}
							}

							//search raw_c file
							$raw_dir     = $this->get_raw_dir_path( $tracking_id, $url_hash );
							$raw_name    = $qa_id . '_' . $lp_time;
							$file_c      = $raw_dir . $raw_name . '-c' . '.php';
							$file_c_done = $raw_dir . $raw_name . '-c-done' . '.php';
							if ( $wp_filesystem->exists( $file_c ) && ! empty( $recent_vid ) ) {
								$c_ary         = $wp_filesystem->get_contents_array( $file_c );
								$base_selector = $base_selectors[ $device_id ];
								// まず今回のraw_fileに対応するbase_selectorをGETし、selector_aryに変換。
								if ( empty( $base_selector ) ) {
									$selector_ary = array();
								} else {
									$selector_ary    = $this->wrap_explode( "\t", $base_selector );
									$max_selector_no = $this->wrap_count( $selector_ary );
								}

								// selector_aryがなければ新しくselectorを作る必要がある
								$c_str = '';
								if ( empty( $selector_ary ) ) {
									$is_selector_exist = false;
								} else {
									$is_selector_exist = true;
								}

								// raw_cファイルの全行を確認し、Selector Indexを作成、変換しながらc_strを作っていく
								if ( ! empty( $c_ary ) ) {
									$max_c_no = $this->wrap_count( $c_ary );

									// raw_cファイルの全行を確認
									for ( $jjj = self::HEADER_ELM; $jjj < $max_c_no; $jjj++ ) {
										if ( $jjj == self::HEADER_ELM ) {
											$c_str .= $c_ary[ $jjj ];

										} else {
											$c_line        = str_replace( PHP_EOL, '', $c_ary[ $jjj ] );
											$c_line_ary    = $this->wrap_explode( "\t", $c_line );
											$max_c_line_no = $this->wrap_count( $c_line_ary );
											$selector      = $c_line_ary[0];

											// search selector
											if ( $is_selector_exist ) {
												$selector_not_found = true;
												for ( $selector_idx = 0; $selector_idx < $max_selector_no; $selector_idx++ ) {
													if ( $selector == $selector_ary[ $selector_idx ] ) {
														$c_line_ary[0]      = $selector_idx;
														$selector_not_found = false;
														break;
													}
												}

												if ( $selector_not_found ) {
													// add new selector and index
													$selector_ary[] = $selector;
													$c_line_ary[0]  = $selector_idx;
													++$max_selector_no;
												}
											} else {
												// this is 1st selector
												$selector_ary[]    = $selector;
												$c_line_ary[0]     = 0;
												$is_selector_exist = true;
												$max_selector_no   = 1;
											}
											// make new line
											$new_c_line = '';
											for ( $kkk = 0; $kkk < $max_c_line_no; $kkk++ ) {
												if ( $kkk == $max_c_line_no - 1 ) {
													if ( $jjj == $max_c_no - 1 ) {
														$new_c_line .= $c_line_ary[ $kkk ];
													} else {
														$new_c_line .= $c_line_ary[ $kkk ] . PHP_EOL;
													}
												} else {
													$new_c_line .= $c_line_ary[ $kkk ] . "\t";
												}
											}
											// make new c_string
											$c_str .= $new_c_line;
										}
									}
									// all c line check end. UPDATE page_version_hist(base_selector)
									if ( ! empty( $selector_ary ) ) {
										$max_selector_no   = $this->wrap_count( $selector_ary );
										$new_base_selector = '';
										for ( $selector_idx = 0; $selector_idx < $max_selector_no; $selector_idx++ ) {
											if ( $selector_idx == $max_selector_no - 1 ) {
												// last
												$new_base_selector .= $selector_ary[ $selector_idx ];
											} else {
												$new_base_selector .= $selector_ary[ $selector_idx ] . "\t";
											}
										}
										$base_selectors[ $device_id ] = $new_base_selector;
									}
								}
								if ( isset( $base_selectors[ $device_id ] ) ) {
									// update page_version_hist
									$tmpfile = $recent_vid[ $device_id ] . '_version.php';
									if ( $wp_filesystem->exists( $vw_verhst_dir . $tmpfile ) ) {
										$tmpslz                   = $this->wrap_get_contents( $vw_verhst_dir . $tmpfile );
										$tmpary                   = $this->wrap_unserialize( $tmpslz );
										$tmpary[0]->base_selector = $base_selectors[ $device_id ];
										$tmpslz                   = $this->wrap_serialize( $tmpary );
										$this->wrap_put_contents( $vw_verhst_dir . $tmpfile, $tmpslz );
									}
								}
								if ( ! empty( $c_str ) ) {
									// update raw-c file
									$wp_filesystem->put_contents( $file_c_done, $c_str );
									$wp_filesystem->delete( $file_c );
								}
							}
							$wp_filesystem->put_contents( $raw_loopcount_file, $iii );
						}
						if ( ( $loop_end - 1 ) == (int) $wp_filesystem->get_contents( $raw_loopcount_file ) ) {
							// ---next
							$cron_status = 'Night>Make view file>Version_hist>End';
							$this->set_next_status( $cron_status );
						}
					} else {
						// ---next
						$cron_status = 'Night>Make view file>Version_hist>End';
						$this->set_next_status( $cron_status );
					}
					$while_continue = false;
					break;

				case 'Night>Make view file>Version_hist>End':
					$this->backup_prev_status( $cron_status );
					if ( $wp_filesystem->exists( $raw_loopcount_file ) ) {
						$wp_filesystem->delete( $raw_loopcount_file );
					}

					// ---next
					$cron_status = 'Night>Make view file>View_pv>Start';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Make view file>View_pv>Start':
					$this->backup_prev_status( $cron_status );

					//check dir
					//view_base
					if ( ! $wp_filesystem->exists( $view_dir ) ) {
						$wp_filesystem->mkdir( $view_dir );
					}
					if ( ! $wp_filesystem->exists( $myview_dir ) ) {
						$wp_filesystem->mkdir( $myview_dir );
					}
					//view_pv
					if ( ! $wp_filesystem->exists( $viewpv_dir ) ) {
						$wp_filesystem->mkdir( $viewpv_dir );
					}
					if ( ! $wp_filesystem->exists( $raw_p_dir ) ) {
						$wp_filesystem->mkdir( $raw_p_dir );
					}
					if ( ! $wp_filesystem->exists( $raw_c_dir ) ) {
						$wp_filesystem->mkdir( $raw_c_dir );
					}
					if ( ! $wp_filesystem->exists( $raw_e_dir ) ) {
						$wp_filesystem->mkdir( $raw_e_dir );
					}

					//index
					if ( ! $wp_filesystem->exists( $viewpv_dir . 'index/' ) ) {
						$wp_filesystem->mkdir( $viewpv_dir . 'index/' );
					}

					// init delete file list.
					$yesterday_str = $qahm_time->xday_str( -1 );
					$yesterday_end = $qahm_time->str_to_unixtime( $yesterday_str . ' 23:59:59' );

					if ( $wp_filesystem->exists( $del_rawfileslist_file . '_old1.php' ) ) {
						// 異常終了じゃない=昨日作成
						$make_time = $wp_filesystem->mtime( $del_rawfileslist_file . '_old1.php' );
						if ( $make_time <= $yesterday_end ) {
							$temp_str = $wp_filesystem->get_contents( $del_rawfileslist_file . '_old1.php' );
							$wp_filesystem->put_contents( $del_rawfileslist_file . '_old2.php', $temp_str );
						}
					}
					if ( $wp_filesystem->exists( $del_rawfileslist_file ) ) {
						// 異常終了じゃない=昨日作成
						$make_time = $wp_filesystem->mtime( $del_rawfileslist_file );
						if ( $make_time <= $yesterday_end ) {
							$temp_str = $wp_filesystem->get_contents( $del_rawfileslist_file );
							$wp_filesystem->put_contents( $del_rawfileslist_file . '_old1.php', $temp_str );
						}
					}
					$wp_filesystem->put_contents( $del_rawfileslist_file, '' );

					//new loop start.initialize tracking_id file
					if ( ! $wp_filesystem->exists( $ary_tids_file ) ) {
						$wp_filesystem->delete( $ary_tids_file );
					}
					//初期値を書込
					$ary_tids     = array();
					$ary_tids[]   = $this->get_tracking_id();
					$ary_tids_slz = $this->wrap_serialize( $ary_tids );
					$this->wrap_put_contents( $ary_tids_file, $ary_tids_slz );

					// ---next
					$cron_status = 'Night>Make view file>View_pv>Make loop';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Make view file>View_pv>Make loop':
					$this->backup_prev_status( $cron_status );

					//qaz_pid作成
					$qaz_pid = $this->get_qaz_pid();

					$qahm_log->info( 'Multi cron process qaz_pid:' . $qaz_pid . ' start.' );
					//pv処理依頼ファイル、プロセスファイルリスト取得

					$all_temp_files = $this->wrap_dirlist( $temp_dir );

					$procfiles     = array(); //プロセスファイルのリスト
					$reqfiles      = array(); //pv処理依頼ファイル(処理前)のリスト
					$proc_reqfiles = array(); //pv処理依頼ファイル(処理中)のリスト：キーはqaz-pid
					$done_reqfiles = array(); //pv処理依頼ファイル(処理済)のリスト

					if ( $all_temp_files !== false ) {
						foreach ( $all_temp_files as $temp_file ) {
							$filename = $temp_file['name'];

							if ( preg_match( '/^viewpv_proc_request-(\d+-\d+-\d+)-\d+\.php$/', $filename, $matches ) ) {
								$reqfile_date = $matches[1];
								$reqfiles[]   = array(
									'path' => $temp_dir . $filename,
									'date' => $reqfile_date,
								);
							}

							if ( preg_match( '/^processing_by-(\d+)-viewpv_proc_request-\d+-\d+-\d+-\d+\.php$/', $filename, $matches ) ) {
								$proc_reqfile_pid                   = $matches[1];
								$proc_reqfiles[ $proc_reqfile_pid ] = $temp_dir . $filename;
							}

							if ( preg_match( '/^done_by-\d+-viewpv_proc_request-\d+-\d+-\d+-\d+\.php$/', $filename ) ) {
								$done_reqfiles[] = $temp_dir . $filename;
							}

							if ( preg_match( '/^view_pv_process-(\d+)\.php$/', $filename, $matches ) ) {
								$procfile_pid               = $matches[1];
								$procfiles[ $procfile_pid ] = $temp_dir . $filename;
							}
						}
					}

					// 非ロックファイルリスト削除
					foreach ( $procfiles as $procfile_pid => $procfile ) {

						// NOTE: WP_Filesystem has no file-lock API. fopen()/flock() is required for multi-cron process control.
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
						$fp_procfile = fopen( $procfile, 'c+' );

						// NOTE: WP_Filesystem has no file-lock API. fopen()/flock() is required for multi-cron process control.
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
						if ( flock( $fp_procfile, LOCK_EX | LOCK_NB ) ) {

							// プロセスが異常終了したことを検知したメッセージ
							$qahm_log->warning( 'Detected abnormal termination of pid:' . $procfile_pid );

							// NOTE: WP_Filesystem has no file-lock API. fopen()/flock() is required for multi-cron process control.
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
							fclose( $fp_procfile );

							if ( $wp_filesystem->delete( $procfile ) ) {
								unset( $procfiles[ $procfile_pid ] ); // 削除されたファイルを配列から取り除く
							}
						}
					}

					//プロセスファイルカウント
					$process_num = $this->wrap_count( $procfiles ); //プロセスファイル数が並行起動数

					if ( QAHM_CONFIG_CPROC_NUM_MAX <= $process_num ) { //自身が並行起動数を超えている場合
						$qahm_log->info( 'The current number of concurrent launches has reached the limit. Current process count:' . $process_num );
						$while_continue = false;
						break;
					}

					//自身のプロセスファイル作成
					$my_pfile_path = $temp_dir . 'view_pv_process-' . $qaz_pid . '.php';
					// NOTE: WP_Filesystem has no file-lock API. fopen()/flock() is required for multi-cron process control.
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
					$fp = fopen( $my_pfile_path, 'c+' );
					if ( ! $fp ) {
						//エラーメッセージ
						$qahm_log->warning( 'Cannot create view pv process file!' );
						$while_continue = false;
						break;
					}
					// NOTE: WP_Filesystem has no file-lock API. fopen()/flock() is required for multi-cron process control.
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
					if ( ! flock( $fp, LOCK_EX | LOCK_NB ) ) {
						//エラーメッセージ
						$qahm_log->warning( 'Cannot lock view pv process file!' );
						$while_continue = false;
						// NOTE: WP_Filesystem has no file-lock API. fopen()/flock() is required for multi-cron process control.
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						fclose( $fp );
						break;
					}
					$this->wrap_put_contents( $my_pfile_path, '' );

					//プロセス異常終了した依頼ファイルの復元
					foreach ( $proc_reqfiles as $r_qaz_pid => $proc_reqfile ) {

						//処理中になっている依頼ファイルに対応するプロセスファイルがあるか確認する
						if ( ! $this->wrap_array_key_exists( $r_qaz_pid, $procfiles ) ) {

							//存在しなければその処理をやっていたプロセスが異常終了したということなので
							//依頼ファイルをリネームして処理中を処理前に戻す
							$pathInfo             = pathinfo( $proc_reqfile );
							$proc_reqfile_dir     = $pathInfo['dirname'];
							$proc_reqfile_name    = $pathInfo['basename'];
							$proc_reqfile_newname = preg_replace( '/^processing_by-\d+-/', '', $proc_reqfile_name );
							$proc_reqfile_newpath = $proc_reqfile_dir . '/' . $proc_reqfile_newname;

							// NOTE: rename() is required for concurrent process recovery (reverts 'processing_by-*' → 'request-*').
							// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
							rename( $proc_reqfile, $proc_reqfile_newpath );

							//リストも修正
							unset( $proc_reqfiles[ $r_qaz_pid ] );

							if ( preg_match( '/^viewpv_proc_request-(\d+-\d+-\d+)-\d+\.php$/', $proc_reqfile_newname, $matches ) ) {
								$reqfile_date = $matches[1];
								$reqfiles[]   = array(
									'path' => $temp_dir . $proc_reqfile_newname,
									'date' => $reqfile_date,
								);
							}
						}
					}

					//トラッキングID一覧取得
					$tids = array();
					global $qahm_data_api;
					$siteary = $qahm_data_api->get_sitemanage();
					if ( ! empty( $siteary ) ) {
						foreach ( $siteary as $site ) {

							if ( $site['status'] == 255 ) {
								continue;
							}

							$tids[] = $site['tracking_id'];
						}
					}

					//pv処理依頼ファイル作成

					if ( $this->wrap_count( $reqfiles ) == 0 && $this->wrap_count( $proc_reqfiles ) == 0 && $this->wrap_count( $done_reqfiles ) == 0 ) { //依頼ファイルが存在するか

						//この時点で
						//・依頼ファイル
						//・依頼ファイル処理結果
						//・削除対象rawファイルのaryの中間域ファイル
						//が残っていたらそれは何らかの要因で残ってしまったゴミなので、ここで消しちゃう
						$del_files = array(); //削除対象ファイル

						//依頼ファイル処理結果の収集
						$types     = array( 'raw_p', 'raw_c', 'raw_e', 'view_pv' );  // 処理するファイルタイプ
						$base_dirs = array( $view_dir );

						foreach ( $tids as $tid ) {
							$base_dirs[] = $view_dir . $tid . '/';
						}

						foreach ( $types as $type ) {
							foreach ( $base_dirs as $base_dir ) {
								if ( $type == 'view_pv' ) {
									$del_files = glob( $base_dir . '*' . $type . '-split.php' );
								} else {
									$del_files = glob( $base_dir . $type . '/*' . $type . '-split.php' );  // 特定のファイルタイプのファイルを取得
								}
							}
						}

						//依頼ファイルの収集
						$request_files = glob( $temp_dir . '*viewpv_proc_request-*.php' );
						$del_files     = $this->wrap_array_merge(
							is_array( $del_files ) ? $del_files : array(),
							is_array( $request_files ) ? $request_files : array()
						);

						//削除対象rawファイルのaryの中間域ファイルの収集
						$delete_rawfiles_ary_temps = glob( $temp_dir . '*delete_rawfiles_ary_temp-*.php' );
						$del_files                 = $this->wrap_array_merge(
							is_array( $del_files ) ? $del_files : array(),
							is_array( $delete_rawfiles_ary_temps ) ? $delete_rawfiles_ary_temps : array()
						);

						//削除
						foreach ( $del_files as $del_file ) {
							$wp_filesystem->delete( $del_file );
						}

						//存在しない場合は依頼ファイル作成
						//koji maruyama 20240805 start ７日前からチェックにし、PV数も確認するルーチンに変更していく
						$rebuild_viewpv_histary = array();
						//                      $s_datetime = $qahm_time->xday_str( -2 ) . ' 00:00:00';
						$s_datetime   = $qahm_time->xday_str( self::REBUILD_VIEWPV_MAX_DAYS ) . ' 00:00:00';
						$e_datetime   = $qahm_time->xday_str( -1 ) . ' 23:59:59';
						$max_datetime = $e_datetime;

						//koji maruyama 20240805 rawx系は最後に削除することになったので、その削除ファイル履歴をファイルに記録しておく
						$delete_rawx_files = array();

						//日付判定
						if ( $qahm_time->str_to_unixtime( $max_datetime ) < $qahm_time->str_to_unixtime( $s_datetime ) ) {
							$is_endday = true;
						} else {
							//今回のタスク内でファイルを作成し続ける場合はis_endday = false;
							$is_endday = false;
						}

						while ( ! $is_endday ) {

							$s_date    = $this->wrap_substr( $s_datetime, 0, 10 );
							$s_dateend = $s_date . ' 23:59:59';

							//koji maruyama 20240805 今までより先にDBのSELECTを行ってPV数チェックにも使う
							$table_name = $wpdb->prefix . 'qa_pv_log';
							$result     = $wpdb->get_results(
								$wpdb->prepare(
									"SELECT pv_id, reader_id, page_id, device_id, source_id, medium_id, campaign_id, session_no, access_time, pv, speed_msec, browse_sec, is_last, is_newuser, is_raw_p, is_raw_c, is_raw_e
									FROM `{$table_name}`
									WHERE access_time BETWEEN %s AND %s",
									$s_datetime,
									$s_dateend
								)
							);

							$allpvcount = 0;
							if ( ! empty( $result ) ) {
								$allpvcount = $this->wrap_count( $result );
							} else {
								//データが一件もないのでこの日は飛ばす
								$s_datetime = $qahm_time->xday_str( 1, $s_datetime, QAHM_Time::DEFAULT_DATETIME_FORMAT );
								if ( $qahm_time->str_to_unixtime( $e_datetime ) <= $qahm_time->str_to_unixtime( $s_dateend ) ) {
									$is_endday = true;
								}
								continue;
							}
							//koji maruyama 20240805 SELECT終了

							//仮に同一日付の旧ファイルがあればゴミなので、先に消しておく。
							//新ロジックでは平行起動するので、それぞれで消すとバッティングして他のプロセス分を消してしまう。
							//そのため、まだプロセスが平行起動していない今のうちに消しておく。

							$allfiles = $this->wrap_dirlist( $viewpv_dir );
							//日付が同じでもpv_idが異なるので、日付で探さないといけない。
							//まずはallから探す
							$delete_viewfile = '';
							//koji maruyama 20240805 PV数をチェックする
							$is_nextday = false;
							$is_find    = false;
							if ( $allfiles ) {
								foreach ( $allfiles as $file ) {
									$filename = $file['name'];
									if ( is_file( $viewpv_dir . $filename ) ) {
										$f_date = $this->wrap_substr( $filename, 0, 10 );

										//koji maruyama 20240805 PV数をチェックする
										if ( $f_date === $s_date ) {
											$is_find = true;
											$tmpary  = $this->wrap_unserialize( $this->wrap_get_contents( $viewpv_dir . $filename ) );
											$tmppvs  = $this->wrap_count( $tmpary );
											unset( $tmpary );
											if ( $allpvcount !== $tmppvs ) {
												$delete_viewfile                                    = $filename;
												$rebuild_viewpv_histary[ $s_date ]                  = array();
												$rebuild_viewpv_histary[ $s_date ]['exist']         = true;
												$rebuild_viewpv_histary[ $s_date ]['pv_log_count']  = $allpvcount;
												$rebuild_viewpv_histary[ $s_date ]['view_pv_count'] = $tmppvs;
												$is_nextday = false;
											} else {
												$is_nextday = true;
											}
											break;
										}
									}
								}
							}
							//koji maruyama 20240805 ファイルがない場合は新規作成の履歴を残す
							if ( $is_find === false && ! isset( $rebuild_viewpv_histary[ $s_date ] ) ) {
								//ファイルがない場合は、新規作成
								$rebuild_viewpv_histary[ $s_date ]          = array();
								$rebuild_viewpv_histary[ $s_date ]['exist'] = false;
							}
							//koji maruyama 20240805 履歴終了

							// koji maruyama 20240805 view_pvのPV数が同一で正しく作成されていた場合、日付を一つ進めて次の日に移動。
							if ( $is_nextday === true ) {
								//既にファイルが存在するので、startするdatetimeは次の日付になる。
								$s_datetime = $qahm_time->xday_str( 1, $s_datetime, QAHM_Time::DEFAULT_DATETIME_FORMAT );
								if ( $qahm_time->str_to_unixtime( $e_datetime ) <= $qahm_time->str_to_unixtime( $s_dateend ) ) {
									$is_endday = true;
								}
								continue;
							}
							// koji maruyama 20240805 作成対象の日付が確定したので、各tracking_idのview_pvを消して、依頼ファイルを作成していく
							if ( $delete_viewfile !== '' ) {
								$delete_base = str_replace( 'viewpv.php', '', $delete_viewfile );
								if ( $wp_filesystem->exists( $viewpv_dir . $delete_base . 'viewpv.php' ) ) {
									$wp_filesystem->delete( $viewpv_dir . $delete_base . 'viewpv.php' );
								}
								// koji maruyama 20240805変更 rawXの生ファイルが残っていないので、rawx系ファイルはここでは削除せず、削除リストに入れ後半でrawx再作成後に消すようにする。ここではviewpvのみ消す。
								if ( $wp_filesystem->exists( $raw_p_dir . $delete_base . 'rawp.php' ) ) {
									$delete_rawx_files[] = $raw_p_dir . $delete_base . 'rawp.php';
								}
								if ( $wp_filesystem->exists( $raw_c_dir . $delete_base . 'rawc.php' ) ) {
									$delete_rawx_files[] = $raw_c_dir . $delete_base . 'rawc.php';
								}
								if ( $wp_filesystem->exists( $raw_e_dir . $delete_base . 'rawe.php' ) ) {
									$delete_rawx_files[] = $raw_e_dir . $delete_base . 'rawe.php';
								}
								// koji maruyama end
							}

							//ZEROでは、allには全サイト分、各tracking_idフォルダには同様のファイルフォーマットで各サイト分のviewpvデータを保存する。そこで事前にアップデートがかかるviewpvファイルを消しておく
							$t_delete_viewfile = array();
							foreach ( $tids as $tracking_id ) {
								// for 不要なファイル削除用
								$t_allfiles = $this->wrap_dirlist( $view_dir . $tracking_id . '/view_pv/' );
								if ( $t_allfiles !== false ) {
									foreach ( $t_allfiles as $file ) {
										$filename = $file['name'];
										if ( is_file( $view_dir . $tracking_id . '/view_pv/' . $filename ) ) {
											$f_date = $this->wrap_substr( $filename, 0, 10 );
											if ( $f_date === $s_date ) {
												$t_delete_viewfile[ $tracking_id ] = $filename;
												break;
											}
										}
									}
								}
							}

							foreach ( $t_delete_viewfile as $tracking_id => $delete_viewfile ) {
								if ( $delete_viewfile !== '' ) {
									$delete_base = str_replace( 'viewpv.php', '', $delete_viewfile );
									if ( $wp_filesystem->exists( $view_dir . $tracking_id . '/view_pv/' . $delete_base . 'viewpv.php' ) ) {
										$wp_filesystem->delete( $view_dir . $tracking_id . '/view_pv/' . $delete_base . 'viewpv.php' );
									}
									// koji maruyama 20240805変更 rawXの生ファイルが残っていないので、rawx系ファイルはここでは削除せず、削除リストに入れ後半でrawx再作成後に消すようにする。ここではviewpvのみ消す。
									if ( $wp_filesystem->exists( $view_dir . $tracking_id . '/view_pv/raw_p/' . $delete_base . 'rawp.php' ) ) {
										$delete_rawx_files[] = $view_dir . $tracking_id . '/view_pv/raw_p/' . $delete_base . 'rawp.php';
									}
									if ( $wp_filesystem->exists( $view_dir . $tracking_id . '/view_pv/raw_c/' . $delete_base . 'rawc.php' ) ) {
										$delete_rawx_files[] = $view_dir . $tracking_id . '/view_pv/raw_c/' . $delete_base . 'rawc.php';
									}
									if ( $wp_filesystem->exists( $view_dir . $tracking_id . '/view_pv/raw_e/' . $delete_base . 'rawe.php' ) ) {
										$delete_rawx_files[] = $view_dir . $tracking_id . '/view_pv/raw_e/' . $delete_base . 'rawe.php';
									}
									// koji maruyama end
								}
							}

							if ( ! empty( $result ) ) {

								//QAHM_CONFIG_RCNK_MAX件ごとに分割して依頼ファイルの形式で書き出す
								$result_chunks = array_chunk( $result, QAHM_CONFIG_RCNK_MAX );

								foreach ( $result_chunks as $c_index => $result_chunk ) {

									$request_index    = $c_index + 1;
									$request_filepath = $temp_dir . 'viewpv_proc_request-' . $s_date . '-' . sprintf( '%02d', $request_index ) . '.php';
									$result_chunk_slz = $this->wrap_serialize( $result_chunk );
									$this->wrap_put_contents( $request_filepath, $result_chunk_slz );

									$reqfiles[] = array(
										'path' => $request_filepath,
										'date' => $s_date,
									); //今回作った分をpv処理依頼ファイル(処理前)のリストに追加
								}
							}

							// is end day?
							if ( $qahm_time->str_to_unixtime( $e_datetime ) <= $qahm_time->str_to_unixtime( $s_dateend ) ) {
								$is_endday = true;
							} else {
								//次の日へ進める
								$is_endday  = false;
								$s_datetime = $qahm_time->xday_str( 1, $s_datetime, QAHM_Time::DEFAULT_DATETIME_FORMAT );
							}
						}
						// koji maruyama 20240805 これから新規作成されるview_pvの日付履歴を保存しておく
						if ( ! empty( $rebuild_viewpv_histary ) ) {
							$viewpv_hist_slz = $this->wrap_serialize( $rebuild_viewpv_histary );
							$this->wrap_put_contents( $temp_dir . 'viewpv_create_hist.php', $viewpv_hist_slz );
						}
						if ( ! empty( $delete_rawx_files ) ) {
							$delete_rawx_slz = $this->wrap_serialize( $delete_rawx_files );
							$this->wrap_put_contents( $temp_dir . 'delete_rawx_files.php', $delete_rawx_slz );
						}
						// koji maruyama 20240805 end

						//pv依頼ファイルが作成されなかったら=処理衣装がなかったら、cronstepを次に進める
						if ( $this->wrap_count( $reqfiles ) == 0 ) {
							$cron_status = 'Night>View_pv>Merge delete file';
						}
					}
					//pv処理依頼ファイルの処理

					if ( $this->wrap_count( $reqfiles ) > 0 ) {

						$target_reqfile         = $reqfiles[0]['path'];
						$target_reqfile_datestr = $reqfiles[0]['date'];
						$s_date                 = $target_reqfile_datestr;

						$e_datetime       = $qahm_time->xday_str( -1 ) . ' 23:59:59';
						$max_datetime     = $e_datetime;
						$s_dateend        = $s_date . ' 23:59:59';
						$before_yesterday = true;
						if ( $qahm_time->str_to_unixtime( $max_datetime ) <= $qahm_time->str_to_unixtime( $s_dateend ) ) {
							$before_yesterday = false;
						}

						//依頼ファイルのリネーム(processing)
						$proc_target_reqfile = 'processing_by-' . $qaz_pid . '-' . basename( $target_reqfile );

						// 新しいファイルパスの生成
						$proc_target_reqfile_path = dirname( $target_reqfile ) . '/' . $proc_target_reqfile;

						// ファイルのリネーム
						// NOTE: rename() is required for atomic state transition between 'request' and 'processing_by-*' files.
						// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
						if ( ! rename( $target_reqfile, $proc_target_reqfile_path ) ) {
							$qahm_log->warning( 'Cannot rename view pv process file!' );
							$while_continue = false;
							break;
						}

						$qa_readers_col_state = 'UAos,UAbrowser,language,qa_id';

						//country_codeの存在を確認し、存在しない場合はwarningを出す
						$table_name  = $wpdb->prefix . 'qa_readers';
						$column_name = 'country_code';
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This SQL query uses $wpdb->prepare(), but it may trigger warnings due to the dynamic construction of the SQL string.
						$result = $wpdb->get_results(
							$wpdb->prepare(
								"SHOW COLUMNS FROM `{$table_name}` LIKE %s",
								$column_name
							)
						);

						if ( empty( $result ) ) {
							$qahm_log->warning( 'The column country_code does not exist in the qa_readers table' );
						} else {
							$qa_readers_col_state .= ',country_code';
						}

						//is_rejectの存在を確認し、存在しない場合はwarningを出す
						$column_name = 'is_reject';
						$result      = $wpdb->get_results(
							$wpdb->prepare(
								"SHOW COLUMNS FROM `{$table_name}` LIKE %s",
								$column_name
							)
						);

						if ( empty( $result ) ) {
							$qahm_log->warning( 'The column is_reject does not exist in the qa_readers table' );
						} else {
							$qa_readers_col_state .= ',is_reject';
						}

						$raw_p_dirlist = $this->wrap_dirlist( $raw_p_dir );
						$raw_c_dirlist = $this->wrap_dirlist( $raw_c_dir );
						$raw_e_dirlist = $this->wrap_dirlist( $raw_e_dir );

						//依頼ファイルからpv_logレコードを読み取る
						if ( $wp_filesystem->exists( $proc_target_reqfile_path ) ) {
							$qa_pv_log_slz = $this->wrap_get_contents( $proc_target_reqfile_path );
							$result        = $this->wrap_unserialize( $qa_pv_log_slz );
						}

						if ( ! empty( $result ) ) {
							$newary    = array();
							$raw_p_ary = array();
							$raw_c_ary = array();
							$raw_e_ary = array();

							$t_newary            = array();
							$t_raw_p_ary         = array();
							$t_raw_c_ary         = array();
							$t_raw_e_ary         = array();
							$delete_rawfiles_ary = array();

							$verid_update_targets_ary = array(); //並行起動するが、バージョン更新は最後に１プロセスで実施

							// 事前に該当日付のデータをセット
							$raw_p_data_ary = array();
							$raw_c_data_ary = array();
							$raw_e_data_ary = array();

							if ( $before_yesterday ) {
								for ( $i = 0, $raw_p_file_max = $this->wrap_count( $raw_p_dirlist ); $i < $raw_p_file_max; $i++ ) {
									if ( $this->wrap_substr( $raw_p_dirlist[ $i ]['name'], 0, 10 ) !== $s_date ) {
										continue;
									}
									$raw_p_data_ary = $this->wrap_unserialize( $this->wrap_get_contents( $raw_p_dir . $raw_p_dirlist[ $i ]['name'] ) );
								}

								for ( $i = 0, $raw_c_file_max = $this->wrap_count( $raw_c_dirlist ); $i < $raw_c_file_max; $i++ ) {
									if ( $this->wrap_substr( $raw_c_dirlist[ $i ]['name'], 0, 10 ) !== $s_date ) {
										continue;
									}
									$raw_c_data_ary = $this->wrap_unserialize( $this->wrap_get_contents( $raw_c_dir . $raw_c_dirlist[ $i ]['name'] ) );
								}

								for ( $i = 0, $raw_e_file_max = $this->wrap_count( $raw_e_dirlist ); $i < $raw_e_file_max; $i++ ) {
									if ( $this->wrap_substr( $raw_e_dirlist[ $i ]['name'], 0, 10 ) !== $s_date ) {
										continue;
									}
									$raw_e_data_ary = $this->wrap_unserialize( $this->wrap_get_contents( $raw_e_dir . $raw_e_dirlist[ $i ]['name'] ) );
								}
							}

							// 20240813 koji maruyama for view_pv speedup
							// 全体のループを始める前に、テーブルから必要なデータを一括取得してメモリに展開
							// IN句に使用するIDのリストを作成
							$reader_ids   = $qahm_db->get_unique_ids( 'reader_id', $result );
							$page_ids     = $qahm_db->get_unique_ids( 'page_id', $result );
							$source_ids   = $qahm_db->get_unique_ids( 'source_id', $result );
							$medium_ids   = $qahm_db->get_unique_ids( 'medium_id', $result );
							$campaign_ids = $qahm_db->get_unique_ids( 'campaign_id', $result );

							$reader_ids_placeholders   = $qahm_db->create_in_placeholders( $reader_ids );
							$page_ids_placeholders     = $qahm_db->create_in_placeholders( $page_ids );
							$source_ids_placeholders   = $qahm_db->create_in_placeholders( $source_ids );
							$medium_ids_placeholders   = $qahm_db->create_in_placeholders( $medium_ids );
							$campaign_ids_placeholders = $qahm_db->create_in_placeholders( $campaign_ids );

							// 各テーブルからデータを取得し、配列に格納
							$readers_data   = array();
							$pages_data     = array();
							$sources_data   = array();
							$media_data     = array();
							$campaigns_data = array();
							$version_data   = array();

							// qa_readers テーブル
							$table_name = $wpdb->prefix . 'qa_readers';
							$query      = "SELECT reader_id, UAos, UAbrowser, language, country_code, qa_id, is_reject FROM $table_name WHERE reader_id IN ($reader_ids_placeholders)";
                            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified inline $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
							$readers_data_raw = $wpdb->get_results( $wpdb->prepare( $query, ...$reader_ids ) );
							foreach ( $readers_data_raw as $row ) {
								$readers_data[ $row->reader_id ] = $row; // IDをキーにしてデータを保存
							}

							// qa_pages テーブル
							$table_name = $wpdb->prefix . 'qa_pages';
							$query      = "SELECT page_id, url, title, tracking_id, url_hash FROM $table_name WHERE page_id IN ($page_ids_placeholders)";
                            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified inline $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
							$pages_data_raw = $wpdb->get_results( $wpdb->prepare( $query, ...$page_ids ) );
							foreach ( $pages_data_raw as $row ) {
								$pages_data[ $row->page_id ] = $row;
							}

							// qa_utm_sources テーブル
							$table_name = $wpdb->prefix . 'qa_utm_sources';
							$query      = "SELECT source_id, utm_source, source_domain FROM $table_name WHERE source_id IN ($source_ids_placeholders)";
                            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified inline $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
							$sources_data_raw = $wpdb->get_results( $wpdb->prepare( $query, ...$source_ids ) );
							foreach ( $sources_data_raw as $row ) {
								$sources_data[ $row->source_id ] = $row;
							}

							// qa_utm_media テーブル
							$table_name = $wpdb->prefix . 'qa_utm_media';
							$query      = "SELECT medium_id, utm_medium FROM $table_name WHERE medium_id IN ($medium_ids_placeholders)";
                            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified inline $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
							$media_data_raw = $wpdb->get_results( $wpdb->prepare( $query, ...$medium_ids ) );
							foreach ( $media_data_raw as $row ) {
								$media_data[ $row->medium_id ] = $row;
							}

							// qa_utm_campaigns テーブル
							$table_name = $wpdb->prefix . 'qa_utm_campaigns';
							$query      = "SELECT campaign_id, utm_campaign FROM $table_name WHERE campaign_id IN ($campaign_ids_placeholders)";
                            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified inline $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
							$campaigns_data_raw = $wpdb->get_results( $wpdb->prepare( $query, ...$campaign_ids ) );
							foreach ( $campaigns_data_raw as $row ) {
								$campaigns_data[ $row->campaign_id ] = $row;
							}

							// view_page_version_hist テーブル
							$table_name       = $qahm_db->prefix . 'qa_page_version_hist';
							$query            = "SELECT version_id, page_id, device_id, version_no, insert_datetime FROM $table_name WHERE page_id IN ($page_ids_placeholders) ORDER BY page_id, version_id DESC";
							$version_data_raw = $qahm_db->get_results( $qahm_db->prepare( $query, ...$page_ids ) );
							foreach ( $version_data_raw as $row ) {
								// page_id をキーにしてデータを保存し、複数のバージョンをデバイス別に保存
								if ( ! isset( $version_data[ $row->page_id ] ) ) {
									$version_data[ $row->page_id ] = array();
								}
								if ( ! isset( $version_data[ $row->page_id ][ $row->device_id ] ) ) {
									$version_data[ $row->page_id ][ $row->device_id ] = array();
								}
								// insert_datetime を UNIX タイムスタンプに変換して直接保持
								$row->insert_unixtime                               = $qahm_time->str_to_unixtime( $row->insert_datetime );
								$version_data[ $row->page_id ][ $row->device_id ][] = $row;
							}

							foreach ( $result as $idx => $row ) {
								$newary[ $idx ]['pv_id']        = $row->pv_id;
								$newary[ $idx ]['reader_id']    = $row->reader_id;
								$newary[ $idx ]['UAos']         = isset( $readers_data[ $row->reader_id ] ) ? $readers_data[ $row->reader_id ]->UAos : '';
								$newary[ $idx ]['UAbrowser']    = isset( $readers_data[ $row->reader_id ] ) ? $readers_data[ $row->reader_id ]->UAbrowser : '';
								$newary[ $idx ]['language']     = isset( $readers_data[ $row->reader_id ] ) ? $readers_data[ $row->reader_id ]->language : '';
								$newary[ $idx ]['country_code'] = isset( $readers_data[ $row->reader_id ] ) ? $readers_data[ $row->reader_id ]->country_code : '';
								$newary[ $idx ]['is_reject']    = isset( $readers_data[ $row->reader_id ] ) ? $readers_data[ $row->reader_id ]->is_reject : null;
								$qa_id                          = isset( $readers_data[ $row->reader_id ] ) ? $readers_data[ $row->reader_id ]->qa_id : '';

								$newary[ $idx ]['page_id']     = $row->page_id;
								$newary[ $idx ]['url']         = isset( $pages_data[ $row->page_id ] ) ? $pages_data[ $row->page_id ]->url : '';
								$newary[ $idx ]['title']       = isset( $pages_data[ $row->page_id ] ) ? esc_html( $pages_data[ $row->page_id ]->title ) : '';
								$tracking_id                   = isset( $pages_data[ $row->page_id ] ) ? $pages_data[ $row->page_id ]->tracking_id : '';
								$url_hash                      = isset( $pages_data[ $row->page_id ] ) ? $pages_data[ $row->page_id ]->url_hash : '';
								$lp_time                       = $qahm_time->str_to_unixtime( $row->access_time );
								$newary[ $idx ]['access_time'] = $lp_time;
								$newary[ $idx ]['device_id']   = $row->device_id;
								// version_id の処理
								$newary[ $idx ]['version_id'] = 0;
								if ( isset( $version_data[ $row->page_id ][ $row->device_id ] ) ) {
									foreach ( $version_data[ $row->page_id ][ $row->device_id ] as $vidary ) {
										// 条件をチェックして、version_id を取得
										if ( $vidary->version_no == 1 || $vidary->insert_unixtime <= $lp_time ) {
											$newary[ $idx ]['version_id'] = (int) $vidary->version_id;
											$verid_update_targets_ary[]   = $vidary->version_id;
											break;
										}
									}
								}
								$newary[ $idx ]['source_id']     = $row->source_id;
								$newary[ $idx ]['utm_source']    = isset( $sources_data[ $row->source_id ] ) ? $sources_data[ $row->source_id ]->utm_source : '';
								$newary[ $idx ]['source_domain'] = isset( $sources_data[ $row->source_id ] ) ? $sources_data[ $row->source_id ]->source_domain : '';

								$newary[ $idx ]['medium_id']  = $row->medium_id;
								$newary[ $idx ]['utm_medium'] = isset( $media_data[ $row->medium_id ] ) ? $media_data[ $row->medium_id ]->utm_medium : '';

								$newary[ $idx ]['campaign_id']  = $row->campaign_id;
								$newary[ $idx ]['utm_campaign'] = isset( $campaigns_data[ $row->campaign_id ] ) ? $campaigns_data[ $row->campaign_id ]->utm_campaign : '';

								$newary[ $idx ]['session_no'] = $row->session_no;

								$newary[ $idx ]['pv']         = $row->pv;
								$newary[ $idx ]['speed_msec'] = $row->speed_msec;
								$newary[ $idx ]['browse_sec'] = $row->browse_sec;
								$newary[ $idx ]['is_last']    = $row->is_last;
								$newary[ $idx ]['is_newuser'] = $row->is_newuser;
								$newary[ $idx ]['is_raw_p']   = $row->is_raw_p;
								$newary[ $idx ]['is_raw_c']   = $row->is_raw_c;
								$newary[ $idx ]['is_raw_e']   = $row->is_raw_e;
								// 20240813 koji maruyama for view_pv speedup end

								//set each tracking_id data
								$t_newary[ $tracking_id ][] = $newary[ $idx ];

								//search raw_X file
								$raw_dir  = $this->get_raw_dir_path( $tracking_id, $url_hash );
								$raw_name = $qa_id . '_' . $lp_time;

								if ( $row->is_raw_p ) {
									$p_str  = '';
									$file_p = $raw_dir . $raw_name . '-p-done' . '.php';
									if ( $wp_filesystem->exists( $file_p ) ) {
										$p_str = $wp_filesystem->get_contents( $file_p );
									} elseif ( $before_yesterday ) {
										foreach ( $raw_p_data_ary as $raw_p_data ) {
											if ( (int) $newary[ $idx ]['pv_id'] !== (int) $raw_p_data['pv_id'] ) {
												continue;
											}
											$p_str = $raw_p_data['raw_p'];
											break;
										}
									}
									$raw_p_ary[]                   = array(
										'pv_id' => $row->pv_id,
										'raw_p' => $p_str,
									);
									$t_raw_p_ary[ $tracking_id ][] = array(
										'pv_id' => $row->pv_id,
										'raw_p' => $p_str,
									);
									$delete_rawfiles_ary[]         = $file_p;
								}
								if ( $row->is_raw_c ) {
									$c_str  = '';
									$file_c = $raw_dir . $raw_name . '-c-done' . '.php';
									if ( $wp_filesystem->exists( $file_c ) ) {
										$c_str = $wp_filesystem->get_contents( $file_c );
									} elseif ( $before_yesterday ) {
										foreach ( $raw_c_data_ary as $raw_c_data ) {
											if ( (int) $newary[ $idx ]['pv_id'] !== (int) $raw_c_data['pv_id'] ) {
												continue;
											}
											$c_str = $raw_c_data['raw_c'];
											break;
										}
									}
									$raw_c_ary[]                   = array(
										'pv_id' => $row->pv_id,
										'raw_c' => $c_str,
									);
									$t_raw_c_ary[ $tracking_id ][] = array(
										'pv_id' => $row->pv_id,
										'raw_c' => $c_str,
									);
									$delete_rawfiles_ary[]         = $file_c;
								}
								if ( $row->is_raw_e ) {
									$e_str  = '';
									$file_e = $raw_dir . $raw_name . '-e-done' . '.php';
									if ( $wp_filesystem->exists( $file_e ) ) {
										$e_str = $wp_filesystem->get_contents( $file_e );
									} elseif ( $before_yesterday ) {
										foreach ( $raw_e_data_ary as $raw_e_data ) {
											if ( (int) $newary[ $idx ]['pv_id'] !== (int) $raw_e_data['pv_id'] ) {
												continue;
											}
											$e_str = $raw_e_data['raw_e'];
											break;
										}
									}
									$raw_e_ary[]                   = array(
										'pv_id' => $row->pv_id,
										'raw_e' => $e_str,
									);
									$t_raw_e_ary[ $tracking_id ][] = array(
										'pv_id' => $row->pv_id,
										'raw_e' => $e_str,
									);
									$delete_rawfiles_ary[]         = $file_e;
								}
							}

							//書込
							$filename_base = $target_reqfile_datestr . '_' . (string) $newary[0]['pv_id'] . '-' . (string) $newary[ $this->wrap_count( $newary ) - 1 ]['pv_id'] . '_';
							$this->wrap_put_contents( $raw_p_dir . $filename_base . 'raw_p-split.php', $this->wrap_serialize( $raw_p_ary ) );
							$this->wrap_put_contents( $raw_c_dir . $filename_base . 'raw_c-split.php', $this->wrap_serialize( $raw_c_ary ) );
							$this->wrap_put_contents( $raw_e_dir . $filename_base . 'raw_e-split.php', $this->wrap_serialize( $raw_e_ary ) );
							$this->wrap_put_contents( $viewpv_dir . $filename_base . 'view_pv-split.php', $this->wrap_serialize( $newary ) );

							// each tracking_id分も書込。同時に各indexやキャッシュ作成のために、アクセスが記録されたtracking_idを一時保存しておく
							foreach ( $t_newary as $tracking_id => $ary ) {
								$filename_base = $target_reqfile_datestr . '_' . (string) $ary[0]['pv_id'] . '-' . (string) $ary[ $this->wrap_count( $ary ) - 1 ]['pv_id'] . '_';
								if ( ! $wp_filesystem->exists( $view_dir . $tracking_id ) ) {
									$wp_filesystem->mkdir( $view_dir . $tracking_id );
								}
								if ( ! $wp_filesystem->exists( $view_dir . $tracking_id . '/view_pv/' ) ) {
									$wp_filesystem->mkdir( $view_dir . $tracking_id . '/view_pv/' );
								}
								if ( ! $wp_filesystem->exists( $view_dir . $tracking_id . '/view_pv/raw_p/' ) ) {
									$wp_filesystem->mkdir( $view_dir . $tracking_id . '/view_pv/raw_p/' );
								}
								if ( ! $wp_filesystem->exists( $view_dir . $tracking_id . '/view_pv/raw_c/' ) ) {
									$wp_filesystem->mkdir( $view_dir . $tracking_id . '/view_pv/raw_c/' );
								}
								if ( ! $wp_filesystem->exists( $view_dir . $tracking_id . '/view_pv/raw_e/' ) ) {
									$wp_filesystem->mkdir( $view_dir . $tracking_id . '/view_pv/raw_e/' );
								}
								$this->wrap_put_contents( $view_dir . $tracking_id . '/view_pv/raw_p/' . $filename_base . 'raw_p-split.php', $this->wrap_serialize( $t_raw_p_ary[ $tracking_id ] ) );
								$this->wrap_put_contents( $view_dir . $tracking_id . '/view_pv/raw_c/' . $filename_base . 'raw_c-split.php', $this->wrap_serialize( $t_raw_c_ary[ $tracking_id ] ) );
								$this->wrap_put_contents( $view_dir . $tracking_id . '/view_pv/raw_e/' . $filename_base . 'raw_e-split.php', $this->wrap_serialize( $t_raw_e_ary[ $tracking_id ] ) );
								$this->wrap_put_contents( $view_dir . $tracking_id . '/view_pv/' . $filename_base . 'view_pv-split.php', $this->wrap_serialize( $ary ) );

								//アクセスのあったtracking_idを保存
								$ary_tids = array();
								if ( $wp_filesystem->exists( $ary_tids_file ) ) {
									$ary_tids_slz = $this->wrap_get_contents( $ary_tids_file );
									$ary_tids     = $this->wrap_unserialize( $ary_tids_slz );
									$is_exist     = false;
									foreach ( $ary_tids as $tid ) {
										if ( $tid === $tracking_id ) {
											$is_exist = true;
											break;
										}
									}
									if ( ! $is_exist ) {
										$ary_tids[]   = $tracking_id;
										$ary_tids_slz = $this->wrap_serialize( $ary_tids );
										$this->wrap_put_contents( $ary_tids_file, $ary_tids_slz );
									}
								}
							}

							//並行起動ではdelete raw files listは一時域に書き出しておく

							$del_rawfiles_ary_temp_path = $temp_dir . 'delete_rawfiles_ary_temp-' . $qaz_pid . '.php';
							$this->wrap_put_contents( $del_rawfiles_ary_temp_path, $this->wrap_serialize( $delete_rawfiles_ary ) );

							//並行起動ではversion更新は一括でやるので、その対象も書き出しておく

							$verid_update_targets_ary_temp_path = $temp_dir . 'verid_update_targets_ary_temp-' . $qaz_pid . '.php';
							$this->wrap_put_contents( $verid_update_targets_ary_temp_path, $this->wrap_serialize( $verid_update_targets_ary ) );

						}

						//依頼ファイルのリネーム(done)
						$done_target_reqfile = 'done_by-' . $qaz_pid . '-' . basename( $target_reqfile );

						// 新しいファイルパスの生成
						$done_target_reqfile_path = dirname( $target_reqfile ) . '/' . $done_target_reqfile;

						// ファイルのリネーム
						// NOTE: rename() is required for atomic state transition.
						// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
						if ( ! rename( $proc_target_reqfile_path, $done_target_reqfile_path ) ) {
							$qahm_log->warning( 'Cannot rename view pv process file!' );
							$while_continue = false;
							break;
						}
					}

					//処理対象がなくなったら
					if ( $this->wrap_count( $reqfiles ) == 0 && $this->wrap_count( $proc_reqfiles ) == 0 && $this->wrap_count( $done_reqfiles ) > 0 ) {

						//最終作業は１プロセスのみでないとプロセス同士が競合するのでプロセス数1でなければ落とす
						if ( 1 <= $process_num ) { //自身が並行起動数を超えている場合
							$qahm_log->info( 'The current number of concurrent launches has reached the limit(last process allow 1 process). Current process count:' . $process_num );
							$while_continue = false;
							//自分のプロセスファイル削除
							$wp_filesystem->delete( $my_pfile_path );
							$qahm_log->info( 'Multi cron process qaz_pid:' . $qaz_pid . ' end.' );
							break;
						}

						//raw_deleteの結合
						$tempdelete_dir_list = $this->wrap_dirlist( $tempdelete_dir );
						if ( $tempdelete_dir_list === false ) {
							$tempdelete_dir_list = array();
						}
						$tempdelete_dir_count = $this->wrap_count( $tempdelete_dir_list );
						$daycnt               = 1;

						$comb_delete_rawfiles_ary  = array();
						$delete_rawfiles_ary_temps = array();

						$comb_verid_update_targets_ary  = array();
						$verid_update_targets_ary_temps = array();

						foreach ( $all_temp_files as $temp_file_ary ) {

							$temp_file = $temp_dir . $temp_file_ary['name'];
							if ( preg_match( '/^delete_rawfiles_ary_temp-\d+.php$/', $temp_file_ary['name'] ) ) {
								// シリアライズされたデータをアンシリアライズ
								$delete_rawfiles_ary = $this->wrap_unserialize( $this->wrap_get_contents( $temp_file ) );
								// アンシリアライズしたデータを結合
								$comb_delete_rawfiles_ary    = $this->wrap_array_merge(
									is_array( $comb_delete_rawfiles_ary ) ? $comb_delete_rawfiles_ary : array(),
									is_array( $delete_rawfiles_ary ) ? $delete_rawfiles_ary : array()
								);
								$delete_rawfiles_ary_temps[] = $temp_file;
							}

							if ( preg_match( '/^verid_update_targets_ary_temp-\d+.php$/', $temp_file_ary['name'] ) ) {
								// シリアライズされたデータをアンシリアライズ
								$verid_update_targets_ary = $this->wrap_unserialize( $this->wrap_get_contents( $temp_file ) );
								// アンシリアライズしたデータを結合
								$comb_verid_update_targets_ary    = $this->wrap_array_merge(
									is_array( $comb_verid_update_targets_ary ) ? $comb_verid_update_targets_ary : array(),
									is_array( $verid_update_targets_ary ) ? $verid_update_targets_ary : array()
								);
								$verid_update_targets_ary_temps[] = $temp_file;
							}
						}

						// delete raw files listを書込
						$nowfile_count = $tempdelete_dir_count + $daycnt;
						$this->write_ary_to_temp( $comb_delete_rawfiles_ary, $del_rawfileslist_temp . $nowfile_count );
						++$daycnt;

						// delete_rawfiles_ary_tempを後片付け
						foreach ( $delete_rawfiles_ary_temps as $file ) {
							$wp_filesystem->delete( $file );
						}

						// versionファイル更新
						foreach ( $comb_verid_update_targets_ary as $version_id ) {
							$tmpfile = $version_id . '_version.php';
							if ( $wp_filesystem->exists( $vw_verhst_dir . $tmpfile ) ) {
								$tmpslz                 = $this->wrap_get_contents( $vw_verhst_dir . $tmpfile );
								$tmpary                 = $this->wrap_unserialize( $tmpslz );
								$tmpary[0]->update_date = $qahm_time->today_str();
								$tmpslz                 = $this->wrap_serialize( $tmpary );
								$this->wrap_put_contents( $vw_verhst_dir . $tmpfile, $tmpslz );
							}
						}

						// verid_update_targets_ary_tempを後片付け
						foreach ( $verid_update_targets_ary_temps as $file ) {
							$wp_filesystem->delete( $file );
						}
						//koji maruyama 20240805 昔のrawxファイルを削除
						$delete_rawx_files_file = $temp_dir . 'delete_rawx_files.php';
						if ( $wp_filesystem->exists( $delete_rawx_files_file ) ) {
							$tmpslz            = $this->wrap_get_contents( $delete_rawx_files_file );
							$delete_rawx_files = $this->wrap_unserialize( $tmpslz );
							if ( is_array( $delete_rawx_files ) ) {
								foreach ( $delete_rawx_files as $delete_filepath ) {
									if ( $wp_filesystem->exists( $delete_filepath ) ) {
										$wp_filesystem->delete( $delete_filepath );
									}
								}
							}
						}
						//koji maruyama 20240805 昔のrawxファイルを削除 END

						//依頼処理結果ファイルを結合して最終的な出力を作る
						//依頼処理結果ファイルを結合
						$types = array( 'view_pv', 'raw_p', 'raw_c', 'raw_e' );  // 処理するファイルタイプ

						foreach ( $tids as $tid ) {
							$base_dirs[] = $view_dir . $tid . '/view_pv/';
						}
						$base_dirs[] = $view_dir . 'all' . '/view_pv/';

						$oldest_date = null; // 最も古い日付を保持する変数→これをもとにindex更新を実施する

						foreach ( $types as $type ) {

							foreach ( $base_dirs as $base_dir ) {

								if ( $type == 'view_pv' ) {
									$files = glob( $base_dir . '*' . $type . '-split.php' );
								} else {
									$files = glob( $base_dir . $type . '/*' . $type . '-split.php' );  // 特定のファイルタイプのファイルを取得
								}

								$date_files = array();
								// 日付ごとにファイルをグループ化
								foreach ( $files as $file ) {
									preg_match( '/(\d{4}-\d{2}-\d{2})_/', $file, $matches );  // ファイル名から日付を抽出
									$date                  = $matches[1];
									$date_files[ $date ][] = $file;
								}

								// 各日付ごとにファイルを処理
								foreach ( $date_files as $date => $files ) {
									$all_data = array();
									// ファイル名に含まれる最初のpv_idでファイルをソート
									usort(
										$files,
										function ( $a, $b ) {
											preg_match( '/_(\d+)-\d+_/', $a, $matchesA );
											preg_match( '/_(\d+)-\d+_/', $b, $matchesB );
											return $matchesA[1] <=> $matchesB[1];
										}
									);

									foreach ( $files as $file ) {
										$content  = $this->wrap_get_contents( $file );
										$data     = $this->wrap_unserialize( $content );  // データをunserializeして配列に戻す
										$all_data = $this->wrap_array_merge(
											is_array( $all_data ) ? $all_data : array(),
											is_array( $data ) ? $data : array()
										); // データを結合
									}

									if ( $this->wrap_count( $all_data ) > 0 ) {

										$first_pv_id = $all_data[0]['pv_id'];
										$last_pv_id  = end( $all_data )['pv_id'];

										// view_pvの場合、ID範囲を保存
										if ( $type == 'view_pv' ) {
											$date_pv_ids[ $date ] = array( $first_pv_id, $last_pv_id );
										}

										// 他のファイルタイプの場合、view_pvのID範囲を使用
										$first_pv_id = $date_pv_ids[ $date ][0] ?? $first_pv_id;
										$last_pv_id  = $date_pv_ids[ $date ][1] ?? $last_pv_id;

										if ( $type == 'view_pv' ) {
											$output_filename = $base_dir . $date . '_' . $first_pv_id . '-' . $last_pv_id . '_' . str_replace( '_', '', $type ) . '.php';
										} else {
											$output_filename = $base_dir . $type . '/' . $date . '_' . $first_pv_id . '-' . $last_pv_id . '_' . str_replace( '_', '', $type ) . '.php';
										}

										$this->wrap_put_contents( $output_filename, $this->wrap_serialize( $all_data ) );  // 結合されたデータを新しいファイルに保存
									}

									//結合済みのファイルは削除する
									foreach ( $files as $file ) {

										$wp_filesystem->delete( $file );

									}
								}
							}
						}

						//依頼ファイルの後片付け
						foreach ( $done_reqfiles as $done_reqfile ) {
							$wp_filesystem->delete( $done_reqfile );
						}

						$cron_status = 'Night>View_pv>Merge delete file';

					}

					//プロセスファイル削除
					$wp_filesystem->delete( $my_pfile_path );
					$qahm_log->info( 'Multi cron process qaz_pid:' . $qaz_pid . ' end.' );

					$this->set_next_status( $cron_status );
					$while_continue = false;
					break;

				case 'Night>View_pv>Merge delete file':
					$this->backup_prev_status( $cron_status );

					$delete_files              = $this->wrap_dirlist( $tempdelete_dir );
					$del_rawfileslist_file_ary = array();
					// ファイルを一つずつ処理
					if ( $delete_files ) {
						foreach ( $delete_files as $delete_file ) {
							$filenames_ary = $wp_filesystem->get_contents_array( $tempdelete_dir . $delete_file['name'] );
							$is_first_line = true;
							foreach ( $filenames_ary as $del_raw_file ) {
								if ( ! $is_first_line ) {
									$del_rawfileslist_file_ary[] = $del_raw_file;
								} else {
									$is_first_line = false;
								}
							}
						}
					}
					$this->write_ary_to_temp( $del_rawfileslist_file_ary, $del_rawfileslist_file );
					// 書き込んだらtempファイルは削除
					if ( $delete_files ) {
						foreach ( $delete_files as $delete_file ) {
							$wp_filesystem->delete( $tempdelete_dir . $delete_file['name'] );
						}
					}

					$cron_status = 'Night>Make view file>View_pv>Make index loop>Start';
					// ---next
					$errmsg = $this->set_next_status( $cron_status );
					break;

				case 'Night>Make view file>View_pv>Make index loop>Start':
					$this->backup_prev_status( $cron_status );
					//copy
					$is_success = copy( $ary_tids_file, $ary_tids_for_i_file );
					if ( $is_success ) {
						$cron_status = 'Night>Make view file>View_pv>Make index loop>Make';
					} else {
						$cron_status = 'Night>Make view file>View_pv>Make index loop>End';
					}
					// ---next
					$errmsg = $this->set_next_status( $cron_status );
					break;

				case 'Night>Make view file>View_pv>Make index loop>Make':
					$this->backup_prev_status( $cron_status );
					$ary_tids_for_i = '';
					if ( $wp_filesystem->exists( $ary_tids_for_i_file ) ) {
						$ary_tids_for_i_slz = $this->wrap_get_contents( $ary_tids_for_i_file );
						$ary_tids_for_i     = $this->wrap_unserialize( $ary_tids_for_i_slz );
					}
					if ( isset( $ary_tids_for_i[0] ) ) {
						$now_tid        = $ary_tids_for_i[0];
						$now_viewpv_dir = $view_dir . $now_tid . '/view_pv/';
						if ( ! $wp_filesystem->exists( $now_viewpv_dir . 'index/' ) ) {
							$wp_filesystem->mkdir( $now_viewpv_dir . 'index/' );
						}
						$indexids = array( 'reader', 'page', 'source', 'medium', 'campaign', 'version' );
						foreach ( $indexids as $indexid ) {
							// 20240815 koji maruyama indexも再構築されたview_pvだけでよい
							$s_datetime  = $qahm_time->xday_str( self::REBUILD_VIEWPV_MAX_DAYS ) . ' 00:00:00'; //暫定対応
							$oldest_date = $this->get_oldest_date_from_viewpv_create_hist();
							if ( $oldest_date ) {
								$s_datetime = $oldest_date . ' 00:00:00';
							}
							// 20240815 koji maruyama indexも再構築されたview_pvだけでよい END
							$e_datetime = $qahm_time->xday_str( -1 ) . ' 23:59:59';

								//最初のpage_id用のindex配列を作る。page_id用の配列はIDですぐ飛べるように、1スタートの固定長にする。
							$is_already_done = false;
							if ( $wp_filesystem->exists( $now_viewpv_dir . 'index/' ) ) {
								$allindexfiles = $this->wrap_dirlist( $now_viewpv_dir . 'index/' );
								if ( $allindexfiles ) {
									foreach ( $allindexfiles as $file ) {
										$filename = $file['name'];
										if ( is_file( $now_viewpv_dir . 'index/' . $filename ) ) {
											if ( $this->wrap_strpos( $filename, $indexid . 'id' ) !== false ) {
												$file_unixtime = $file['lastmodunix'];
												$yesterday_end = $qahm_time->xday_str( -1 ) . ' 23:59:59';
												if ( $qahm_time->str_to_unixtime( $yesterday_end ) < $file_unixtime ) {
													//既に本日作成済みは次のIDへ
													$is_already_done = true;
													continue;
												} else {
													$indexary                     = $this->wrap_explode( '-', $filename );
													$aryindex                     = floor( (int) $indexary[0] / self::ID_INDEX_MAX10MAN );
													$getconts                     = $this->wrap_get_contents( $now_viewpv_dir . 'index/' . $filename );
													$viewpv_id_index[ $aryindex ] = $this->wrap_unserialize( $getconts );
													unset( $getconts );
													$is_already_done = false;
												}
											}
										}
									}
								}
							}
							if ( $is_already_done ) {
								continue;
							}
							if ( ! isset( $viewpv_id_index[0] ) ) {
								$viewpv_id_index[0] = array_fill( 1, self::ID_INDEX_MAX10MAN, false );
							}

							//make file
							$table_id  = $indexid . '_id';
							$is_endday = false;

							//make index array and save
							while ( ! $is_endday ) {
								$s_date    = $this->wrap_substr( $s_datetime, 0, 10 );
								$s_dateend = $s_date . ' 23:59:59';

								global $qahm_data_api;
								$dateid     = 'date = between ' . $s_date . ' and ' . $s_date;
								$someday_pv = $qahm_data_api->select_data( 'view_pv', '*', $dateid, false, '', $now_tid );
								$result     = array();
								if ( isset( $someday_pv[0] ) ) {
									$result = $someday_pv[0];
								}
								if ( ! empty( $result ) ) {
									foreach ( $result as $idx => $row ) {
										$this->make_index_array( $viewpv_id_index, (int) $row[ $table_id ], (int) $row['pv_id'], $s_date );
									}
									unset( $result );
									unset( $someday_pv );
								}
								// is end day?
								if ( $qahm_time->str_to_unixtime( $e_datetime ) <= $qahm_time->str_to_unixtime( $s_dateend ) ) {
									$is_endday = true;
									//index配列を保存
									$this->save_index_array( $viewpv_id_index, $now_viewpv_dir, $indexid . 'id.php' );
									unset( $viewpv_id_index );
								} else {
									$is_endday  = false;
									$s_datetime = $qahm_time->xday_str( 1, $s_datetime, QAHM_Time::DEFAULT_DATETIME_FORMAT );
								}
							}
						}
						$new_ary_tids_for_i = array();
						for ( $iii = 1; $iii < $this->wrap_count( $ary_tids_for_i ); $iii++ ) {
							$new_ary_tids_for_i[] = $ary_tids_for_i[ $iii ];
						}
						$ary_tids_for_i_slz = $this->wrap_serialize( $new_ary_tids_for_i );
						$this->wrap_put_contents( $ary_tids_for_i_file, $ary_tids_for_i_slz );
					}
					//次へ
					if ( $wp_filesystem->exists( $ary_tids_for_i_file ) ) {
						$ary_tids_for_i     = '';
						$ary_tids_for_i_slz = $this->wrap_get_contents( $ary_tids_for_i_file );
						$ary_tids_for_i     = $this->wrap_unserialize( $ary_tids_for_i_slz );
						if ( empty( $ary_tids_for_i ) ) {
							$cron_status = 'Night>Make view file>View_pv>Make index loop>End';
						} else {
							$cron_status = 'Night>Make view file>View_pv>Make index loop>Make';
						}
						if ( $ary_tids_for_i === '' ) {
							//異常なので終了
							$qahm_log->error( 'tracking_id for index file is bad format' );
							$cron_status = 'Night>Make view file>View_pv>Make index loop>End';
						}
					} else {
						//異常なので終了
						$qahm_log->error( 'tracking_id for index file is not found' );
						$cron_status = 'Night>Make view file>View_pv>Make index loop>End';
					}
					$this->set_next_status( $cron_status );
					$while_continue = false;
					break;

				case 'Night>Make view file>View_pv>Make index loop>End':
					$this->backup_prev_status( $cron_status );
					if ( $wp_filesystem->exists( $ary_tids_for_i_file ) ) {
						$wp_filesystem->delete( $ary_tids_for_i_file );
					}

					// ---next
					$cron_status = 'Night>Make view file>View_pv>End';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Make view file>View_pv>End':
					$this->backup_prev_status( $cron_status );

					// ---next
					$cron_status = 'Night>Make view file>Readers>Start';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Make view file>Readers>Start':
					$this->backup_prev_status( $cron_status );

					// reader
					if ( ! $wp_filesystem->exists( $vw_reader_dir ) ) {
						$wp_filesystem->mkdir( $vw_reader_dir );
					}
					// ---next
					$cron_status = 'Night>Make view file>Readers>Make';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Make view file>Readers>Make':
					$this->backup_prev_status( $cron_status );

					global $qahm_db;

					$save_s_id = 1;
					$save_e_id = 1;
					//search dir
					$allfiles   = $this->wrap_dirlist( $vw_reader_dir );
					$beforefile = '';
					$beforestat = 0;
					$beforeend  = 0;
					if ( $allfiles ) {
						foreach ( $allfiles as $file ) {
							$filename = $file['name'];
							if ( is_file( $vw_reader_dir . $filename ) ) {
								$tmpary     = $this->wrap_explode( '_', $filename );
								$reader_ids = $this->wrap_explode( '-', $tmpary[0] );
								if ( $save_s_id < $reader_ids[0] ) {
									$save_s_id = $reader_ids[0];
								}

								if ( $save_e_id < $reader_ids[1] ) {
									$save_e_id = $reader_ids[1];
								}
							}
							if ( $reader_ids[0] === $beforestat && $beforeend < $reader_ids[1] ) {
								if ( $beforefile ) {
									$wp_filesystem->delete( $vw_reader_dir . $beforefile );
								}
							}
							$beforefile = $filename;
							$beforestat = $reader_ids[0];
							$beforeend  = $reader_ids[1];
						}
					}

					//現在の最終IDを調査
					$table_name = $wpdb->prefix . 'qa_readers';
					$stat_id    = $wpdb->get_var(
						"SELECT reader_id FROM `{$table_name}` ORDER BY reader_id ASC LIMIT 1"
					);

					$last_id = $wpdb->get_var(
						"SELECT reader_id FROM `{$table_name}` ORDER BY reader_id DESC LIMIT 1"
					);

					if ( $save_s_id < $stat_id ) {
						$save_s_id = $stat_id;
					}
					$lastdist = $last_id - $save_s_id;
					if ( $lastdist <= self::VIEW_READERS_MAX_IDS ) {
						if ( $save_e_id !== $last_id ) {
							//最終IDだけ保存すればOK
							$allrecord = $qahm_db->get_results(
								$wpdb->prepare(
									"SELECT * FROM `{$table_name}` WHERE reader_id BETWEEN %d AND %d",
									$save_s_id,
									$last_id
								)
							);

							//既存のファイルをオープンし、新しくカラムを追加して保存する
							$oldfile = $save_s_id . '-' . $save_e_id . '_readers.php';
							$newfile = $save_s_id . '-' . $last_id . '_readers.php';
							if ( $wp_filesystem->exists( $vw_reader_dir . $oldfile ) ) {
								$oldary   = $this->wrap_get_contents( $vw_reader_dir . $oldfile );
								$newary   = array();
								$newary[] = $oldary;
								foreach ( $allrecord as $row ) {
									if ( $save_e_id < $row->reader_id ) {
										$newary[] = $row;
									}
								}
								$this->wrap_put_contents( $vw_reader_dir . $newfile, $this->wrap_serialize( $newary ) );
								if ( $newfile !== $oldfile ) {
									$wp_filesystem->delete( $vw_reader_dir . $oldfile );
								}
							} else {
								$this->wrap_put_contents( $vw_reader_dir . $newfile, $this->wrap_serialize( $allrecord ) );
							}
						}
					} else {
						//最後まで保存ループが必要
						$is_last = false;
						while ( ! $is_last ) {
							$now_lastid = $save_s_id + self::VIEW_READERS_MAX_IDS;
							if ( $last_id < $now_lastid ) {
								$now_lastid = $last_id;
								$is_last    = true;
							}
							$allrecord = $qahm_db->get_results(
								$wpdb->prepare(
									"SELECT * FROM `{$table_name}` WHERE reader_id BETWEEN %d AND %d",
									$save_s_id,
									$now_lastid
								)
							);

							$allcount = $this->wrap_count( $allrecord );
							$dbstatid = $allrecord[0]->reader_id;
							$dblastid = $allrecord[ $allcount - 1 ]->reader_id;

							//新しく保存する
							$newfile = $dbstatid . '-' . $dblastid . '_readers.php';
							$this->wrap_put_contents( $vw_reader_dir . $newfile, $this->wrap_serialize( $allrecord ) );
							//値を進める
							$save_s_id = $dblastid + 1;
						}
					}
					$cron_status = 'Night>Make view file>Readers>End';
					$this->set_next_status( $cron_status );
					$while_continue = false;
					break;

				case 'Night>Make view file>Readers>End':
					$this->backup_prev_status( $cron_status );

					// ---next
					$cron_status = 'Night>Make view file>End';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Make view file>End':
					$this->backup_prev_status( $cron_status );

					// ---next
					$cron_status = 'Night>Delete>Start';
					$this->set_next_status( $cron_status );
					break;

				// ----------
				// Delete
				// ----------
				case 'Night>Delete>Start':
					$this->backup_prev_status( $cron_status );

					// ---next
					$cron_status = 'Night>Delete>Files>Start';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Delete>Files>Start':
					$this->backup_prev_status( $cron_status );

					// ---next
					$cron_status = 'Night>Delete>Files>Readers';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Delete>Files>Readers':
					$this->backup_prev_status( $cron_status );

					// readers finish dir search and delete
					$readersfin_files = $this->wrap_dirlist( $readersdbin_dir );
					// 2days before
					$day2before_str = $qahm_time->xday_str( -2 );
					$day2before_end = $qahm_time->str_to_unixtime( $day2before_str . ' 23:59:59' );
					// 一昨日のセッションファイルを削除
					if ( is_array( $readersfin_files ) ) {
						foreach ( $readersfin_files as $readersfin_file ) {
							$make_time = $readersfin_file['lastmodunix'];
							if ( $make_time <= $day2before_end ) {
								$wp_filesystem->delete( $this->wrap_trim( $readersdbin_dir . $readersfin_file['name'] ) );
							}
						}
					}

					// ---next
					$cron_status = 'Night>Delete>Files>Work';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Delete>Files>Work':
					$this->backup_prev_status( $cron_status );

					// works dir search and delete
					$heatmapwork_files = $this->wrap_dirlist( $heatmapwork_dir );
					$replaywork_files  = $this->wrap_dirlist( $replaywork_dir );

					$day1before_str = $qahm_time->xday_str( -1 );
					$day1before_end = $qahm_time->str_to_unixtime( $day1before_str . ' 23:59:59' );
					$day2before_str = $qahm_time->xday_str( -2 );
					$day2before_end = $qahm_time->str_to_unixtime( $day2before_str . ' 23:59:59' );
					if ( is_array( $heatmapwork_files ) ) {
						foreach ( $heatmapwork_files as $heatmapwork_file ) {
							$make_time = $heatmapwork_file['lastmodunix'];
							if ( $make_time <= $day1before_end ) {
								$wp_filesystem->delete( $this->wrap_trim( $heatmapwork_dir . $heatmapwork_file['name'] ) );
							}
						}
					}

					if ( is_array( $replaywork_files ) ) {
						foreach ( $replaywork_files as $replaywork_file ) {
							$make_time = $replaywork_file['lastmodunix'];
							if ( $make_time <= $day2before_end ) {
								$wp_filesystem->delete( $this->wrap_trim( $replaywork_dir . $replaywork_file['name'] ) );
							}
						}
					}

					// ---next
					$cron_status = 'Night>Delete>Files>Realtime tsv';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Delete>Files>Realtime tsv':
					$this->backup_prev_status( $cron_status );

					$realtime_file = $readers_dir . 'realtime_view.php';
					if ( $wp_filesystem->exists( $realtime_file ) ) {
						$realtime_ary   = $this->wrap_unserialize( $this->wrap_get_contents( $realtime_file ) );
						$day2before_str = $qahm_time->xday_str( -2 );
						$day2before_end = $qahm_time->str_to_unixtime( $day2before_str . ' 23:59:59' );

						// 一昨日のデータは不要
						$new_body = array();
						for ( $iii = 0; $iii < $this->wrap_count( $realtime_ary['body'] ); $iii++ ) {
							$body      = $realtime_ary['body'][ $iii ];
							$exit_time = $body['last_exit_time'];
							if ( $exit_time <= $day2before_end ) {
								break;
							}
							array_push( $new_body, $body );
						}

						//indexを詰めて保存
						$realtime_ary['body'] = $new_body;
						$this->wrap_put_contents( $realtime_file, $this->wrap_serialize( $realtime_ary ) );
					}

					// ---next
					$cron_status = 'Night>Delete>Files>Raw';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Delete>Files>Raw':
					$this->backup_prev_status( $cron_status );

					if ( $wp_filesystem->exists( $del_rawfileslist_file . '_old2.php' ) ) {
						$del_ary       = $wp_filesystem->get_contents_array( $del_rawfileslist_file . '_old2.php' );
						$is_first_line = true;
						foreach ( $del_ary as $del ) {
							if ( ! $is_first_line ) {
								$wp_filesystem->delete( $this->wrap_trim( $del ) );
							} else {
								$is_first_line = false;
							}
						}
						$wp_filesystem->delete( $del_rawfileslist_file . '_old2.php' );
					}
					// ---next
					$cron_status = 'Night>Delete>Files>Xmonth ago';
					$this->set_next_status( $cron_status );
					$while_continue = false;
					break;

				case 'Night>Delete>Files>Xmonth ago':
					$this->backup_prev_status( $cron_status );

					$yearx     = $qahm_time->year();
					$month     = $qahm_time->month();
					$del_month = $month - self::DEFAULT_DELETE_MONTH;
					if ( $del_month <= 0 ) {
						$del_month = 12 + $del_month;
						$yearx     = $yearx - 1;
					}

					// 現在の年月を YYYYMM 形式の整数として計算
					$current_period = $yearx * 100 + $del_month;

					$search_dirs = array( $data_dir );
					// 再帰検索を行いつつdataディレクトリ内の3ヶ月前のファイルを削除していく
					for ( $iii = 0; $iii < $this->wrap_count( $search_dirs ); $iii++ ) {   // 再帰のためループ毎にcount関数を実行しなければならない
						$dir = $search_dirs[ $iii ];
						if ( ! $wp_filesystem->is_dir( $dir ) ) {
							continue;
						}

						// 検索対象外のディレクトリ
						if ( false !== $this->wrap_strpos( $dir, 'qa-zero-data/view/' ) ||
							false !== $this->wrap_strpos( $dir, 'qa-zero-data/crawler/' ) ) {
							continue;
						}

						// ディレクトリ内に存在するファイルのリストを取得
						$file_list = $this->wrap_dirlist( $dir );
						if ( $file_list ) {
							// ディレクトリ内のファイルを全てチェック
							foreach ( $file_list as $file ) {
								// ディレクトリなら再帰検索用の配列にディレクトリを登録
								if ( is_dir( $dir . $file['name'] ) ) {
									$search_dirs[] = $dir . $file['name'] . '/';
								} else {
									// 削除対象外のファイル
									if ( $file['name'] === 'qa-config.php' ) {  // QA設定ファイル
										continue;
									}
									// ファイルの更新日時を取得
									$file_date  = $qahm_time->unixtime_to_str( $file['lastmodunix'] );
									$file_year  = $qahm_time->year( $file_date );
									$file_month = $qahm_time->month( $file_date );

									// ファイルの更新年月を YYYYMM 形式で計算
									$file_period = $file_year * 100 + $file_month;

									// 現在からDEFAULT_DELETE_MONTHを引いた月よりファイルの更新月が古いかどうかで判断
									if ( $file_period <= $current_period ) {
										if ( ! $wp_filesystem->delete( $dir . $file['name'] ) ) {
											$qahm_log->warning( '$wp_filesystem->delete()に失敗しました。パス：' . $dir . $file['name'] );
										}
									}
								}
							}
						}
					}

					// ---next
					$cron_status = 'Night>Delete>Files>End';
					$this->set_next_status( $cron_status );
					$while_continue = false;
					break;

				case 'Night>Delete>Files>View dir':
					$this->backup_prev_status( $cron_status );

					$base_date = $qahm_time->today_str();
					$leap_date = $qahm_time->year() . '-02-29';
					if ( $qahm_time->xday_num( $base_date, $leap_date ) !== 0 ) {

						$del_date = $this->get_data_retention_days();
						if ( $del_date ) {
							$del_date = $qahm_time->diff_str( $base_date, '-' . $del_date . ' day' );
							$del_date = $qahm_time->diff_str( $del_date, '-1 day' );
						} else {
							$del_date = $qahm_time->diff_str( $base_date, '-' . self::DEFAULT_DELETE_DATA_DAY . ' day' );
						}

						$search_dirs = array( $data_dir . 'view/' );
						// 再帰検索を行いつつview_pv
						for ( $iii = 0; $iii < $this->wrap_count( $search_dirs ); $iii++ ) {   // 再帰のためループ毎にcount関数を実行しなければならない
							$dir = $search_dirs[ $iii ];
							if ( $wp_filesystem->is_dir( $dir ) && $wp_filesystem->exists( $dir ) ) {
								$is_pv_search = false;
								$is_sm_search = false;
								$is_hm_search = false;

								// 調査対象のディレクトリか判定
								if ( 0 === $this->wrap_strpos( $this->wrap_substr( $dir, -$this->wrap_strlen( 'view_pv/' ) ), 'view_pv/' ) ) {
									$is_pv_search = true;
								}

								if ( 0 === $this->wrap_strpos( $this->wrap_substr( $dir, -$this->wrap_strlen( 'summary/' ) ), 'summary/' ) ) {
									$is_pv_search = true;
									$is_sm_search = true;
								}

								if ( 0 === $this->wrap_strpos( $this->wrap_substr( $dir, -$this->wrap_strlen( 'raw_p/' ) ), 'raw_p/' ) ||
									0 === $this->wrap_strpos( $this->wrap_substr( $dir, -$this->wrap_strlen( 'raw_c/' ) ), 'raw_c/' ) ||
									0 === $this->wrap_strpos( $this->wrap_substr( $dir, -$this->wrap_strlen( 'raw_e/' ) ), 'raw_e/' ) ) {
									$is_hm_search = true;
								}

								// ディレクトリ内に存在するファイルのリストを取得
								$file_list = $this->wrap_dirlist( $dir );
								if ( $file_list ) {
									// ディレクトリ内のファイルを全てチェック
									foreach ( $file_list as $file ) {
										// 対象viewディレクトリの処理
										if ( $is_pv_search ) {
											// ディレクトリなら再帰検索用の配列にディレクトリを登録
											if ( is_dir( $dir . $file['name'] ) ) {
												$search_dirs[] = $dir . $file['name'] . '/';
											} else {
												$is_days_file = false;
												if ( $is_sm_search ) {
													if ( 'days_access.php' === $file['name'] || 'days_access_detail.php' === $file['name'] ) {
														$days_ary = $this->wrap_unserialize( $this->wrap_get_contents( $dir . $file['name'] ) );
														if ( $days_ary ) {
															for ( $days_idx = 0, $days_max = $this->wrap_count( $days_ary ); $days_idx < $days_max; $days_idx++ ) {
																$f_date   = $days_ary[ $days_idx ]['date'];
																$diff_day = $qahm_time->xday_num( $f_date, $del_date );

																// 期限が過ぎた時の処理
																if ( 0 >= $diff_day ) {
																	unset( $days_ary[ $days_idx ] );
																}
															}
															$days_ary = array_values( $days_ary );
															$this->wrap_put_contents( $dir . $file['name'], $this->wrap_serialize( $days_ary ) );
														}
														$is_days_file = true;
													}
												}

												if ( ! $is_days_file ) {
													$f_date = $this->wrap_substr( $file['name'], 0, 10 );
													if ( $qahm_time->is_date( $f_date ) ) {
														$diff_day = $qahm_time->xday_num( $f_date, $del_date );

														// 期限が過ぎた時の処理
														if ( 0 >= $diff_day ) {
															$this->wrap_delete( $dir . $file['name'] );
														}
													}
												}
											}
											// rawディレクトリの処理
										} elseif ( $is_hm_search ) {
											if ( ! is_dir( $dir . $file['name'] ) ) {
												$f_date   = $this->wrap_substr( $file['name'], 0, 10 );
												$diff_day = $qahm_time->xday_num( $f_date, $del_date );

												// 期限が過ぎた時の処理
												if ( 0 >= $diff_day ) {
													$this->wrap_delete( $dir . $file['name'] );
												}
											}
											// 上記以外の処理
										} else {
											// ディレクトリなら再帰検索用の配列にディレクトリを登録
											if ( is_dir( $dir . $file['name'] ) ) {
												$search_dirs[] = $dir . $file['name'] . '/';
											}
										}
									}
								}
							}
						}
					}

					// ---next
					$cron_status = 'Night>Delete>Files>End';
					$this->set_next_status( $cron_status );
					$while_continue = false;
					break;

				case 'Night>Delete>Files>End':
					$this->backup_prev_status( $cron_status );

					// ---next
					$cron_status = 'Night>Delete>Db>Start';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Delete>Db>Start':
					$this->backup_prev_status( $cron_status );

					// ---next
					$cron_status = 'Night>Delete>Db>Truncate partition';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Delete>Db>Truncate partition':
					$this->backup_prev_status( $cron_status );

					$yearx           = $qahm_time->year();
					$month           = $qahm_time->month();
					$data_save_month = self::DATA_SAVE_MONTH;
					// delete same_month -1
					$del_month = $month - $data_save_month - 1;
					if ( $del_month <= 0 ) {
						$del_month = 12 + $del_month;
						$yearx     = $yearx - 1;
					}
					$del_month = sprintf( '%02d', $del_month );

					$del_partition_name = 'p' . $yearx . $del_month;

					// qa_pv_log
					$table_name = $wpdb->prefix . 'qa_pv_log';
					$query      = "ALTER TABLE `{$table_name}` TRUNCATE PARTITION {$del_partition_name}";

					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- No user input; identifiers fixed/whitelisted.
					$result = $wpdb->query( $query );

					//20231107 delete 1years ago for version hist 20240401 add for readers
					$yearx_vh           = $qahm_time->year();
					$month_vh           = $qahm_time->month();
					$data_save_month_vh = self::DATA_SAVE_ONE_YEAR;
					// delete same_month -1
					$del_month_vh = $month_vh - $data_save_month_vh - 1;
					while ( $del_month_vh <= 0 ) {
						$del_month_vh += 12;
						$yearx_vh     -= 1;
					}
					$del_month_vh = sprintf( '%02d', $del_month_vh );

					$del_partition_name_vh = 'p' . $yearx_vh . $del_month_vh;
					// qa_page_version_hist
					$table_name = $wpdb->prefix . 'qa_page_version_hist';
					$query      = "ALTER TABLE `{$table_name}` TRUNCATE PARTITION {$del_partition_name_vh}";
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- No user input; identifiers fixed/whitelisted.
					$result = $wpdb->query( $query );

					// qa_readers
					$table_name = $wpdb->prefix . 'qa_readers';
					$query      = "ALTER TABLE `{$table_name}` TRUNCATE PARTITION {$del_partition_name_vh}";
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- No user input; identifiers fixed/whitelisted.
					$result = $wpdb->query( $query );

					// ---next
					$cron_status = 'Night>Delete>Db>End';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Delete>Db>End':
					$this->backup_prev_status( $cron_status );

					// ---next
					$cron_status = 'Night>Delete>End';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Delete>End':
					$this->backup_prev_status( $cron_status );

					// ---next
					$cron_status = 'Night>Make summary file>Start';
					$this->set_next_status( $cron_status );
					break;

				// ----------
				// Making Summary File
				// ----------
				case 'Night>Make summary file>Start':
					$this->backup_prev_status( $cron_status );

					if ( $wp_filesystem->exists( $ary_tids_file ) ) {
						//copy
						$is_success = copy( $ary_tids_file, $ary_tids_for_s_file );
						if ( $is_success ) {
							$ary_tids_for_s_slz = $this->wrap_get_contents( $ary_tids_for_s_file );
							$ary_tids_for_s     = $this->wrap_unserialize( $ary_tids_for_s_slz );
							foreach ( $ary_tids_for_s as $tid ) {
								$make_summay_dir = $view_dir . $tid . '/summary/';
								//check dir
								if ( ! $wp_filesystem->exists( $make_summay_dir ) ) {
									$wp_filesystem->mkdir( $make_summay_dir );
								}
							}
							$cron_status = 'Night>Make summary file>Days access>Start';
						} else {
							$qahm_log->warning( 'copy tracking_id file failed.' );
							$cron_status = 'Night>Make summary file>End';
						}
					} else {
						//ファイルが存在しない = アクセスが0の時
						$cron_status = 'Night>Make summary file>End';
					}
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Make summary file>Days access>Start':
					$this->backup_prev_status( $cron_status );

					// ---next
					$cron_status = 'Night>Make summary file>Days access>Make loop';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Make summary file>Days access>Make loop':
					$this->backup_prev_status( $cron_status );
					$ary_tids_for_s = '';
					if ( $wp_filesystem->exists( $ary_tids_for_s_file ) ) {
						$ary_tids_for_s_slz = $this->wrap_get_contents( $ary_tids_for_s_file );
						$ary_tids_for_s     = $this->wrap_unserialize( $ary_tids_for_s_slz );
					}
					if ( isset( $ary_tids_for_s[0] ) ) {
						$now_tid                  = $ary_tids_for_s[0];
						$now_summay_dir           = $view_dir . $now_tid . '/summary/';
						$now_viewpv_dir           = $view_dir . $now_tid . '/view_pv/';
						$summary_days_access_file = $now_summay_dir . 'days_access.php';

						$days_access_ary = array();
						$s_datetime      = '1999-12-31 00:00:00';
						if ( $wp_filesystem->exists( $summary_days_access_file ) ) {
							$days_access_ary = $this->wrap_unserialize( $this->wrap_get_contents( $summary_days_access_file ) );
							if ( 0 < $this->wrap_count( $days_access_ary ) ) {
								//　結局、毎月DBから上書きして値が増えるので、DBの期間分は上書きが必要
								// x month ago
								$yearx           = $qahm_time->year();
								$month           = $qahm_time->month();
								$data_save_month = self::DATA_SAVE_MONTH;
								//  same_month
								$save_yearx = $yearx;
								$save_month = $month - $data_save_month;
								if ( $save_month <= 0 ) {
									$save_month = 12 + $save_month;
									$save_yearx = $yearx - 1;
								}

								//below is ok
								$save_month = sprintf( '%02d', $save_month );
								$s_datetime = $save_yearx . '-' . $save_month . '-01 00:00:00';
							}
						}

						//search
						$start_idx = 0;
						foreach ( $days_access_ary as $idx => $days_access ) {
							if ( isset( $days_access['sum_datetime'] ) ) {
								if ( ( $qahm_time->now_unixtime() - 3 * 60 * 60 ) < $qahm_time->str_to_unixtime( $days_access['sum_datetime'] ) ) {
									//本日集計済みなので、この日付は飛ばすべき
									$s_datetime = $days_access['date'] . ' 23:59:59';
									$start_idx  = $idx + 1;
								}
							} else {
								//dummyの古い値を入れる
								$tmpary                  = $this->wrap_array_merge( $days_access, array( 'sum_datetime' => '1999-12-31 00:00:00' ) );
								$days_access_ary[ $idx ] = $tmpary;
							}
							if ( isset( $days_access['date'] ) ) {
								$ary_datetime = $days_access['date'] . ' 00:00:00';
								if ( $qahm_time->str_to_unixtime( $s_datetime ) <= $qahm_time->str_to_unixtime( $ary_datetime ) ) {
									if ( $start_idx === 0 ) {
										$start_idx = $idx;
									}
								}
							}
						}
						if ( $this->wrap_count( $days_access_ary ) <= $start_idx && $start_idx !== 0 ) {
							$start_idx = -1;
						}

						// search view_pv dir
						$allfiles = $this->wrap_dirlist( $now_viewpv_dir );
						if ( $allfiles ) {
							foreach ( $allfiles as $file ) {
								$filename = $file['name'];
								if ( is_file( $now_viewpv_dir . $filename ) ) {
									$f_date     = $this->wrap_substr( $filename, 0, 10 );
									$f_datetime = $f_date . ' 00:00:00';
									if ( $qahm_time->str_to_unixtime( $s_datetime ) <= $qahm_time->str_to_unixtime( $f_datetime ) ) {
										//集計対象
										$view_pv_ary = $this->wrap_unserialize( $this->wrap_get_contents( $now_viewpv_dir . $filename ) );
										$pv_cnt      = $this->wrap_count( $view_pv_ary );
										$session_cnt = 0;
										$all_readers = array();
										foreach ( $view_pv_ary as $pv_ary ) {
											//count session 当日の1ページ目（LP着地）をカウント
											if ( (int) $pv_ary['pv'] === 1 ) {
												++$session_cnt;
												$all_readers[] = (int) $pv_ary['reader_id'];
											}
										}
										$user_cnt = $this->wrap_count( array_unique( $all_readers, SORT_NUMERIC ) );
										//set array
										$access_ary = array(
											'date'         => $f_date,
											'pv_count'     => $pv_cnt,
											'session_count' => $session_cnt,
											'user_count'   => $user_cnt,
											'sum_datetime' => $qahm_time->now_str(),
										);

										// 今回のファイルは既存aryの中にないので追加する
										if ( $start_idx < 0 ) {
											$days_access_ary[] = $access_ary;
											// 今回の再計算した対象ファイルは既存aryの中に入る予定なので、どこに追加するかをチェック
										} else {
											$is_find  = false;
											$afterary = array();
											//既存aryの中で一致する日付を検索していれる
											for ( $ddd = $start_idx; $ddd < $this->wrap_count( $days_access_ary ); $ddd++ ) {
												if ( isset( $days_access_ary[ $ddd ]['date'] ) ) {
													$ary_datetime = $days_access_ary[ $ddd ]['date'] . ' 00:00:00';
													if ( $qahm_time->str_to_unixtime( $ary_datetime ) <= $qahm_time->str_to_unixtime( $f_datetime ) ) {
														++$start_idx;
													} else {
														$afterary[] = $days_access_ary[ $ddd ];
													}
													if ( $days_access_ary[ $ddd ]['date'] === $f_date ) {
														$days_access_ary[ $ddd ] = $access_ary;
														$is_find                 = true;
														break;
													}
												}
											}
											//まったく見つからなかった場合は、aryのおしりか間に追加
											if ( ! $is_find ) {
												//そもそも日付がオーバーした時は、おしりに追加
												if ( $this->wrap_count( $days_access_ary ) <= $start_idx ) {
													$days_access_ary[] = $access_ary;
													//以後の日付はお尻に追加
													$start_idx = -1;
													//日付がオーバーしていない場合は、間に追加
												} else {
													$new_days_access_ary = array();
													for ( $ccc = 0; $ccc < $start_idx; $ccc++ ) {
														$new_days_access_ary[] = $days_access_ary[ $ccc ];
													}
													//start_idxのところに挿入
													$new_days_access_ary[] = $access_ary;
													//お尻はいままで通り
													for ( $ccc = 0; $ccc < $this->wrap_count( $afterary ); $ccc++ ) {
														$new_days_access_ary[] = $afterary[ $ccc ];
													}
													$days_access_ary = $new_days_access_ary;
													// 次の$fileの日付検索は次のstart_idxから
													++$start_idx;
													if ( $this->wrap_count( $days_access_ary ) <= $start_idx ) {
														//以後の日付はお尻に追加
														$start_idx = -1;
													}
												}
											}
										}
										//write today access
										$this->wrap_put_contents( $summary_days_access_file, $this->wrap_serialize( $days_access_ary ) );

										// startするdatetimeは次の日付になる。
										$s_datetime = $qahm_time->xday_str( 1, $f_datetime, QAHM_Time::DEFAULT_DATETIME_FORMAT );
									}
								}
							}
						}
					}
					$cron_status = 'Night>Make summary file>Days access detail>Start';
					$this->set_next_status( $cron_status );
					$while_continue = false;
					break;

				case 'Night>Make summary file>Days access detail>Start':
					$this->backup_prev_status( $cron_status );

					// ---next
					$cron_status = 'Night>Make summary file>Days access detail>Make loop';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Make summary file>Days access detail>Make loop':
					$this->backup_prev_status( $cron_status );
					global $qahm_db;

					$ary_tids_for_s = '';
					if ( $wp_filesystem->exists( $ary_tids_for_s_file ) ) {
						$ary_tids_for_s_slz = $this->wrap_get_contents( $ary_tids_for_s_file );
						$ary_tids_for_s     = $this->wrap_unserialize( $ary_tids_for_s_slz );
					}
					if ( isset( $ary_tids_for_s[0] ) ) {
						$now_tid = $ary_tids_for_s[0];
						$qahm_db->make_summary_days_access_detail( $now_tid );
					}
					$cron_status = 'Night>Make summary file>Days access detail>End';
					$this->set_next_status( $cron_status );
					$while_continue = false;
					break;

				case 'Night>Make summary file>Days access detail>End':
					$this->backup_prev_status( $cron_status );
					$cron_status = 'Night>Make summary file>End';
					if ( $wp_filesystem->exists( $ary_tids_for_s_file ) ) {
						$ary_tids_for_s_slz = $this->wrap_get_contents( $ary_tids_for_s_file );
						$ary_tids_for_s     = $this->wrap_unserialize( $ary_tids_for_s_slz );

						//積分形式ファイル作成
						global $qahm_db;
						$now_tid = $ary_tids_for_s[0];
						//先に過去の不整合ファイルを削除。但し、days_access_detailは毎回新規作成されるので削除する必要なし。
						$oldest_date = $this->get_oldest_date_from_viewpv_create_hist();
						if ( $oldest_date ) {
							$qahm_db->delete_integral_summary_file( 'allpage', $oldest_date, $now_tid );
							$qahm_db->delete_integral_summary_file( 'landingpage', $oldest_date, $now_tid );
						}
						//再作成
						$qahm_db->make_integral_summary_file( 'allpage', 1, $now_tid );
						$qahm_db->make_integral_summary_file( 'landingpage', 1, $now_tid );
						$qahm_db->make_integral_days_access_detail_file( 1, $now_tid );

						$new_ary_tids_for_s = array();
						for ( $iii = 1; $iii < $this->wrap_count( $ary_tids_for_s ); $iii++ ) {
							$new_ary_tids_for_s[] = $ary_tids_for_s[ $iii ];
						}

						if ( empty( $new_ary_tids_for_s ) ) {
							$cron_status = 'Night>Make summary file>End';
						} else {
							//次のtracking_idのサマリー作成へ
							$ary_tids_for_s_slz = $this->wrap_serialize( $new_ary_tids_for_s );
							$this->wrap_put_contents( $ary_tids_for_s_file, $ary_tids_for_s_slz );
							$cron_status = 'Night>Make summary file>Days access>Start';
						}
					} else {
						//異常なので終了
						$qahm_log->error( 'tracking_id for index file is not found' );
						$cron_status = 'Night>Make summary file>End';
					}

					// ---next
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Make summary file>End':
					$this->backup_prev_status( $cron_status );
					if ( $wp_filesystem->exists( $ary_tids_for_s_file ) ) {
						$wp_filesystem->delete( $ary_tids_for_s_file );
					}
					// ---next
					$cron_status = 'Night>SC get>Start';
					$this->set_next_status( $cron_status );
					break;

				// ----------
				// Making Search Console File
				// ----------
				case 'Night>SC get>Start':
					$this->backup_prev_status( $cron_status );

					//最初のcron開始時間をメモ
					$this->wrap_put_contents( $temp_dir . 'cron_first_gsc_start_time.php', $qahm_time->now_str() );

					//処理対象をメモ
					$gsc_target_memo = array(
						'processing_site_idx' => 0,
						'sites'               => array(),
					);

					global $qahm_data_api;
					$siteary = $qahm_data_api->get_sitemanage();

					foreach ( $siteary as $site ) {
						if ( $site['status'] == 255 ) {
							continue;
						}
						$target_site_info                      = array();
						$target_site_info['tracking_id']       = $site['tracking_id'];
						$target_site_info['url']               = $site['url'];
						$target_site_info['pvterm_start_date'] = $this->get_pvterm_start_date( $site['tracking_id'] );
						$target_site_info['loop_memo']         = array(
							'now_search_cnt' => 0,
							'y'              => $qahm_time->year(),
							'm'              => $qahm_time->month(),
							'd'              => $qahm_time->day(),
						);

						$gsc_target_memo['sites'][] = $target_site_info;

						// ディレクトリがなければ作成
						if ( ! $wp_filesystem->exists( $view_dir . $site['tracking_id'] ) ) {
							$wp_filesystem->mkdir( $view_dir . $site['tracking_id'] );
						}
					}

					if ( ! empty( $gsc_target_memo['sites'] ) ) {
						$this->wrap_put_contents( $temp_dir . 'cron_gsc_target_memo.php', $this->wrap_serialize( $gsc_target_memo ) );
					}

					// ---next
					$cron_status = 'Night>SC get>Each day>Start';

					$this->set_next_status( $cron_status );
					break;

				case 'Night>SC get>Each day>Start':
					$this->backup_prev_status( $cron_status );

					$first_cron_start_time_file = $temp_dir . 'cron_first_gsc_start_time.php'; // 最初のcron開始時間
					$target_memo_file           = $temp_dir . 'cron_gsc_target_memo.php'; // 処理対象サイトメモ
					if ( ! $wp_filesystem->exists( $first_cron_start_time_file ) || ! $wp_filesystem->exists( $target_memo_file ) ) {
						$cron_status = 'Night>SC get>Each day>End';
						$this->set_next_status( $cron_status );
						break;
					}

					$cron_sc_get_limit_sec = 60 * 45;
					$first_cron_start_time = $this->wrap_get_contents( $first_cron_start_time_file );
					// 最初のcronから$cron_sc_get_limit_sec秒以上が経過していたら終了
					if ( $qahm_time->xsec_num( $qahm_time->now_str(), $first_cron_start_time ) > $cron_sc_get_limit_sec ) {
						$qahm_log->info( 'SC get limit time over.' );
						$cron_status = 'Night>SC get>Each day>End';
						$this->set_next_status( $cron_status );
						break;
					}

					// GSCデータ取得
					// target_memoファイルのメモからターゲットサイトを決定
					$gsc_target_memo     = $this->wrap_unserialize( $this->wrap_get_contents( $target_memo_file ) );
					$processing_site_idx = $gsc_target_memo['processing_site_idx'];

					// 全てのサイト分を終えていたら終了
					if ( $this->wrap_count( $gsc_target_memo['sites'] ) <= $processing_site_idx ) {
						$cron_status = 'Night>SC get>Each day>End';
						$this->set_next_status( $cron_status );
						break;
					}

					$tracking_id = $gsc_target_memo['sites'][ $processing_site_idx ]['tracking_id'];
					$url         = $gsc_target_memo['sites'][ $processing_site_idx ]['url'];

					// $qahm_google_apiに$tracking_idをセット（$qahm_google_apiクラスの共通変数$thisにセットされる。これにより後出の関数insert_serach_console_...やcreate_...に$tracking_idを渡さなくてもよい。）
					$qahm_google_api->set_tracking_id( $tracking_id, $url );

					$is_init = $qahm_google_api->init_for_background(
						'Google API Integration',
						array( 'https://www.googleapis.com/auth/webmasters.readonly' )
					);

					if ( ! $is_init ) {
						// （API連携していない$tracking_idもfalseが返る。次のサイトへ）
						$gsc_target_memo['processing_site_idx'] = $processing_site_idx + 1;
						$this->wrap_put_contents( $target_memo_file, $this->wrap_serialize( $gsc_target_memo ) );

						$cron_status    = 'Night>SC get>Each day>Start';
						$while_continue = false;
						$this->set_next_status( $cron_status );
						break;
					}

					// qa_logに取得ドメインを記録
					$qahm_log->info( 'GSC Target Domain: ' . $url );

					// ループ開始
					$site_pvterm_start_date = $gsc_target_memo['sites'][ $processing_site_idx ]['pvterm_start_date'];
					$loop_memo              = $gsc_target_memo['sites'][ $processing_site_idx ]['loop_memo'];

					$first_y = $loop_memo['y'];
					$first_m = null;
					$first_d = null;

					$last_y = $qahm_time->year() - 2; //サーチコンソールAPIで取得できる情報の上限は486日前まで
					$last_m = 1;
					$last_d = 1;

					$sc_max_search_cnt = 486;
					$qa_max_search_cnt = $qahm_time->xday_num( $qahm_time->now_str(), $site_pvterm_start_date );
					$max_search_cnt    = $sc_max_search_cnt < $qa_max_search_cnt ? $sc_max_search_cnt : $qa_max_search_cnt;
					$now_search_cnt    = $loop_memo['now_search_cnt']; // 初期値 0

					// 1つのcronプロセスの制限時間　※超えたら抜けて、次のcronプロセスに処理を託す。max_execution_time以内に余裕をもって収めないと、うまくcronロックファイルが削除できず、処理が回らない。
					$me_cronproc_limit_sec  = 90; // < max_execution_time
					$me_cronproc_start_time = $qahm_time->now_str();

					$timelimit_forced_end = false;
					$db_insert_failed_end = false;
					$api_error_end        = false;
					$is_loop_end          = false;
					for ( $y = $first_y; $y >= $last_y; $y-- ) {
						if ( $first_m === null ) {
							$first_m = $loop_memo['m'];
						} else {
							$first_m = 12;
						}

						for ( $m = $first_m; $m >= $last_m; $m-- ) {
							if ( $first_d === null ) {
								$first_d = $loop_memo['d'];
							} else {
								$first_d = (int) gmdate( 't', strtotime( 'last day of ' . $y . '-' . $m ) );
							}

							// 一日ごとのデータ
							for ( $d = $first_d; $d >= $last_d; $d-- ) {
								if ( $now_search_cnt > $max_search_cnt ) {
									$is_loop_end = true;
									break;
								}
								$me_elapsed_sec     = $qahm_time->xsec_num( $qahm_time->now_str(), $me_cronproc_start_time );
								$total_elaplsed_sec = $qahm_time->xsec_num( $qahm_time->now_str(), $first_cron_start_time );
								if ( $me_elapsed_sec > $me_cronproc_limit_sec || $total_elaplsed_sec > $cron_sc_get_limit_sec ) {
									$timelimit_forced_end = true;
									$is_loop_end          = true;
									break;
								}

								$date = gmdate( 'Y-m-d', strtotime( $y . '-' . $m . '-' . $d ) );

								// ループ用のメモ更新（現在地）
								$loop_memo['now_search_cnt'] = $now_search_cnt;
								$loop_memo['y']              = $y;
								$loop_memo['m']              = $m;
								$loop_memo['d']              = $d;

								// DBにquery(keyword)を登録・更新
								$result_insert_gsc_keyword = $qahm_google_api->insert_search_console_keyword( $date, $date );
								if ( $result_insert_gsc_keyword === false ) {
									$qahm_log->warning( 'DB Query Error. ' . $date );
									$db_insert_failed_end = true;
									$is_loop_end          = true;
									// 失敗した場合、データファイル作成はスキップして次の日付へ
									break;
								}

								// gsc_lp_queryデータファイル作成
								$me_elapsed_sec         = $qahm_time->xsec_num( $qahm_time->now_str(), $me_cronproc_start_time );
								$remain_sec             = $me_cronproc_limit_sec - $me_elapsed_sec;
								$result_create_gsc_data = $qahm_google_api->create_search_console_data( $date, $date, false, $remain_sec );
								if ( $result_create_gsc_data === false ) {
									$qahm_log->warning( 'GSC API Error. ' . $date );
									$api_error_end = true;
									$is_loop_end   = true;
									break;
								} elseif ( $result_create_gsc_data === 'timed_out' ) {
									$timelimit_forced_end = true;
									$is_loop_end          = true;
									break;
								}

								++$now_search_cnt;

							} // end for $d

							if ( $is_loop_end ) {
								break;
							}

							// 月データ
							$start_date             = gmdate( 'Y-m-d', strtotime( $y . '-' . $m . '-01' ) );
							$end_date               = gmdate( 'Y-m-t', strtotime( $y . '-' . $m . '-01' ) );
							$me_elapsed_sec         = $qahm_time->xsec_num( $qahm_time->now_str(), $me_cronproc_start_time );
							$remain_sec             = $me_cronproc_limit_sec - $me_elapsed_sec;
							$result_create_gsc_data = $qahm_google_api->create_search_console_data( $start_date, $end_date, true, $remain_sec );
							if ( $result_create_gsc_data === false ) {
								$qahm_log->warning( 'GSC API Error. ' . $start_date . ' - ' . $end_date );
								$api_error_end = true;
								$is_loop_end   = true;
								break;
							} elseif ( $result_create_gsc_data === 'timed_out' ) {
								$timelimit_forced_end = true;
								$is_loop_end          = true;
								break;
							}
						} // end for $m

						if ( $is_loop_end ) {
							break;
						}
					} // end for $y

					if ( $timelimit_forced_end ) {
						// 時間不足＝途中＝同じ日付へ
						$gsc_target_memo['sites'][ $processing_site_idx ]['loop_memo'] = $loop_memo;
						$this->wrap_put_contents( $target_memo_file, $this->wrap_serialize( $gsc_target_memo ) );
						$qahm_log->info( 'GSC get loop timed out. ' . $now_search_cnt . ' / ' . $max_search_cnt );

					} elseif ( $db_insert_failed_end || $api_error_end ) {
						// 次の日付へ
						$loop_memo['now_search_cnt'] = $now_search_cnt + 1;
						$current_ymd                 = sprintf( '%04d-%02d-%02d', $loop_memo['y'], $loop_memo['m'], $loop_memo['d'] );
						$new_ymd                     = $qahm_time->xday_str( -1, $current_ymd, 'Y-m-d' );
						$loop_memo['y']              = (int) gmdate( 'Y', strtotime( $new_ymd ) );
						$loop_memo['m']              = (int) gmdate( 'm', strtotime( $new_ymd ) );
						$loop_memo['d']              = (int) gmdate( 'd', strtotime( $new_ymd ) );
						$gsc_target_memo['sites'][ $processing_site_idx ]['loop_memo'] = $loop_memo;
						$this->wrap_put_contents( $target_memo_file, $this->wrap_serialize( $gsc_target_memo ) );
						$qahm_log->info( 'GSC get skipped loop. ' . ( $now_search_cnt + 1 ) . ' / ' . $max_search_cnt );

					} else {
						// サイト分は正常に取得終了。次のサイトへ
						$gsc_target_memo['processing_site_idx'] = $processing_site_idx + 1;
						$this->wrap_put_contents( $target_memo_file, $this->wrap_serialize( $gsc_target_memo ) );
					}

					// ---next
					$cron_status    = 'Night>SC get>Each day>Start';
					$while_continue = false;
					$this->set_next_status( $cron_status );
					break;

				case 'Night>SC get>Each day>End':
					// ---next
					$cron_status = 'Night>SC get>End';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>SC get>End':
					$this->wrap_delete( $temp_dir . 'cron_first_gsc_start_time.php' );
					$this->wrap_delete( $temp_dir . 'cron_gsc_target_memo.php' );

					// ---next
					$cron_status = 'Night>Make Goal file>Start';
					$this->set_next_status( $cron_status );
					break;

				// ----------
				// Making Goal File
				// ----------
				case 'Night>Make Goal file>Start':
					$this->backup_prev_status( $cron_status );

					$current_unixtime = $qahm_time->now_unixtime();
					$this->wrap_put_contents( $temp_dir . 'cron_first_make_goal_file.php', $current_unixtime );

					// ---next
					$cron_status = 'Night>Make Goal file>making loop>Start';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Make Goal file>making loop>Start':
					$this->backup_prev_status( $cron_status );

					// 最初のヤツから指定時間以上経っていたら、Make Goal fileは終了
					//if ( $wp_filesystem->exists( $temp_dir . 'cron_first_make_goal_file.php' ) ) {
						$first_make_gfile_unixtime = $this->wrap_get_contents( $temp_dir . 'cron_first_make_goal_file.php' );
						$elapsed_time              = $qahm_time->now_unixtime() - $first_make_gfile_unixtime;
					if ( $elapsed_time >= ( 60 * 30 ) ) {
						$qahm_log->warning( 'goalファイル作成で30分以上経過しました。' );
						$cron_status = 'Night>Make Goal file>End';
						$this->set_next_status( $cron_status );
						break;
					}
					//}
						// me cron process time-check
						$gfile_make_start_time = $qahm_time->now_str();
						$exec_limit_sec        = 60 * 10; // 10分でループ抜ける  < max_execution_time
						$timelimit_exceeded    = false;

						// 進捗メモファイルの導入（再開可能化のため）
						$goal_memo_file = $temp_dir . 'cron_goal_making_memo.php';
					if ( $wp_filesystem->exists( $goal_memo_file ) ) {
						$goal_memo = $this->wrap_unserialize( $this->wrap_get_contents( $goal_memo_file ) );
					} else {
						$goal_memo = array(
							'processing_tracking_idx' => 0,
							'processing_month_idx'    => 0,
							'processing_goal_idx'     => 0,
							'phase'                   => 'recent', // 'recent' or 'remaining'
						);
					}

						//global $qahm_db; // 521行目で定義済み
						global $qahm_data_api;

						// tracking_id
						$sitemanage        = $qahm_data_api->get_sitemanage();
						$site_tracking_ids = array_column( $sitemanage, 'tracking_id' );

						// 直近３カ月分を先に取る
						$k_month_ago   = 3 - 1;
						$latest_ym_ary = array();
					foreach ( $site_tracking_ids as $tracking_idx => $tracking_id ) {
						// メモから再開位置を判定（recentフェーズの場合のみ）
						if ( $goal_memo['phase'] === 'recent' && $tracking_idx < $goal_memo['processing_tracking_idx'] ) {
							continue;
						}

						$goals_ary = $qahm_data_api->get_goals_preferences( $tracking_id );
						if ( empty( $goals_ary ) ) {
							continue;
						}

						// dir
						$myview_dir         = $view_dir . $tracking_id . '/';
						$myview_summary_dir = $myview_dir . 'summary/';
						//log dir
						$log_dir   = $data_dir . 'log/';
						$mylog_dir = $log_dir . $tracking_id . '/';

						// pvterm_start, pvterm_latest（データのある最初の日付と最新の日付）
						$pvterm_both_end = $qahm_data_api->get_pvterm_both_end_date( $tracking_id );
						if ( empty( $pvterm_both_end ) ) {
							continue;
						}
						$pvterm_start                  = $pvterm_both_end['start'];
						$pvterm_latest                 = $pvterm_both_end['latest'];
						$latest_ym_ary[ $tracking_id ] = $this->wrap_substr( $pvterm_latest, 0, 7 );

						$start_date = gmdate( 'Y-m-01', strtotime( "-$k_month_ago month", strtotime( $pvterm_latest ) ) );
						if ( $pvterm_start > $start_date ) {
							$start_date = $pvterm_start;
						}

						$start_dtobj  = new DateTime( $start_date );
						$latest_dtobj = new DateTime( $pvterm_latest );
						$months_diff  = ( $latest_dtobj->format( 'Y' ) - $start_dtobj->format( 'Y' ) ) * 12 + ( $latest_dtobj->format( 'n' ) - $start_dtobj->format( 'n' ) );
						$months_range = $k_month_ago + 1;
						if ( $months_diff < $k_month_ago ) {
							$months_range = $months_diff + 1;
						}

						$goal_files_dateranges = array();
						$current_month_date    = strtotime( $pvterm_latest );
						for ( $iii = 0; $iii < $months_range; $iii++ ) {
							$ym_str = gmdate( 'Y-m', $current_month_date );
							$a_date = $ym_str . '-01';
							if ( $qahm_time->xday_num( $a_date, $pvterm_start ) < 0 ) {
								$a_date = $pvterm_start;
							}
							$is_month_lastday = true;
							$month_lastday    = $ym_str . '-' . strval( $qahm_time->month_daynum( $a_date ) );
							if ( $iii === 0 ) {
								$b_date = $pvterm_latest;
								if ( $b_date !== $month_lastday ) {
									$is_month_lastday = false;
								}
							} else {
								$b_date = $month_lastday;
							}

							$goal_files_dateranges[] = array(
								'a_date'           => $a_date,
								'b_date'           => $b_date,
								'is_month_lastday' => $is_month_lastday,
								'Y-m_str'          => $ym_str,
							);

							// 1ヶ月前の日付にする（月ごとに遡っていく）
							$current_month_date = strtotime( gmdate( 'Y-m-01', $current_month_date ) . ' -1 month' );

						}

						// make goal file __ {tracking_id}/summary/ goal file
						foreach ( $goal_files_dateranges as $month_idx => $each_month ) {
							// メモから再開位置を判定（recentフェーズで同じtracking_idの場合）
							if ( $goal_memo['phase'] === 'recent' &&
								$tracking_idx === $goal_memo['processing_tracking_idx'] &&
								$month_idx < $goal_memo['processing_month_idx'] ) {
								continue;
							}

							// goals_aryのキーを配列化してインデックスでアクセスできるようにする
							$goal_keys = array_keys( $goals_ary );
							foreach ( $goal_keys as $goal_idx => $gid ) {
								$goal_ary = $goals_ary[ $gid ];

								// メモから再開位置を判定（recentフェーズで同じtracking_id、同じmonthの場合）
								if ( $goal_memo['phase'] === 'recent' &&
									$tracking_idx === $goal_memo['processing_tracking_idx'] &&
									$month_idx === $goal_memo['processing_month_idx'] &&
									$goal_idx < $goal_memo['processing_goal_idx'] ) {
									continue;
								}

								$goal_file = $myview_summary_dir . $each_month['Y-m_str'] . '-01_goal_' . $gid . '_1mon.php';

								$log_file = $mylog_dir . 'goal_' . $gid . '_file_making.log';
								$log_ary  = array();
								if ( $wp_filesystem->exists( $log_file ) ) {
									$log_file_contents = $this->wrap_get_contents( $log_file );
									$log_ary           = $this->wrap_unserialize( $log_file_contents );
									// ゴール設定でのファイル作成中はcron側の実行を防ぐ
									if ( isset( $log_ary['info']['prevent_cron'] ) && $log_ary['info']['prevent_cron'] ) {
										continue;
									}
									// ログにない新しい月の場合は、ログに追加
									if ( ! isset( $log_ary[ $each_month['Y-m_str'] ] ) ) {
										$log_ary[ $each_month['Y-m_str'] ] = array(
											'done'  => false,
											'doing' => false,
										);
										$this->wrap_put_contents( $log_file, $this->wrap_serialize( $log_ary ) );
									}
								} else {
									// $mylog_dirが存在しない場合は作成
									if ( ! $wp_filesystem->exists( $mylog_dir ) ) {
										$wp_filesystem->mkdir( $mylog_dir );
									}
									// ログファイルが存在しない場合は作成　（データ作成開始のところですぐ保存するから、ここではしない。）
									if ( ! $wp_filesystem->exists( $log_file ) ) {
										//$pvterm_start = $qahm_data_api->get_pvterm_start_date( $tracking_id ); //すでに取得済み
										$log_ary['info'] = array(
											'pvterm_start' => $pvterm_start,
											'going_back_done' => false,
										);
										$log_startDate   = new DateTime( $pvterm_start );
										$log_endDate     = new DateTime( $pvterm_latest );
										$intervalMonths  = ( $log_endDate->format( 'Y' ) - $log_startDate->format( 'Y' ) ) * 12 + ( $log_endDate->format( 'n' ) - $log_startDate->format( 'n' ) );
										$loop_limit      = $intervalMonths + 1; // 端の月を含むため、+1
										$log_start       = strtotime( $pvterm_start );
										for ( $iii = 0; $iii < $loop_limit; $iii++ ) {
											$yyyymm_key             = gmdate( 'Y-m', $log_start );
											$log_ary[ $yyyymm_key ] = array(
												'done'  => false,
												'doing' => false,
											);
											$log_start              = strtotime( gmdate( 'Y-m-01', $log_start ) . ' +1 month' ); // strtotimeで月を進める
										}
										$pvterm_start_ym                             = $this->wrap_substr( $pvterm_start, 0, 7 );
										$log_ary[ $pvterm_start_ym ]['starting_end'] = true;
									}
								}

								if ( ! $log_ary[ $each_month['Y-m_str'] ]['done'] ) {
									$goal_comp_sessions = array();
									$make_goal_file     = true;
									$gfile_complete     = false;

									// 他のcronプロセスが実行中の場合はスキップ
									if ( $log_ary[ $each_month['Y-m_str'] ]['doing'] ) {
										continue;
									}

									// Earlier part （ゴール設定時に作成されたファイルのデータ補完）
									if ( isset( $log_ary[ $each_month['Y-m_str'] ]['earlier_part_done'] ) && ! $log_ary[ $each_month['Y-m_str'] ]['earlier_part_done'] && $wp_filesystem->exists( $goal_file ) ) {

										// log（次のcronプロセスが同時に実行しないように）
										$log_ary[ $each_month['Y-m_str'] ]['doing'] = true;
										$this->wrap_put_contents( $log_file, $this->wrap_serialize( $log_ary ) );

										// ゴール設定時にpvterm_startか否かの判定済みなので、cronでは日付比較なし
										$from_date                  = $each_month['a_date'];
										$to_date                    = $qahm_time->xday_str( -1, $log_ary[ $each_month['Y-m_str'] ]['earliest'], 'Y-m-d' );
										$earlier_goal_comp_sessions = $qahm_data_api->fetch_goal_comp_sessions_in_month( $tracking_id, $gid, $goal_ary, $from_date, $to_date );
										if ( ! is_null( $earlier_goal_comp_sessions ) ) {
											$exist_gdata              = $this->wrap_get_contents( $goal_file );
											$exist_gdata_unserialized = $this->wrap_unserialize( $exist_gdata );
											$goal_comp_sessions       = $this->wrap_array_merge(
												is_array( $earlier_goal_comp_sessions ) ? $earlier_goal_comp_sessions : array(),
												is_array( $exist_gdata_unserialized ) ? $exist_gdata_unserialized : array()
											);
											$gfile_complete           = $this->wrap_put_contents( $goal_file, $this->wrap_serialize( $goal_comp_sessions ) );
											if ( $gfile_complete ) {
												$log_ary[ $each_month['Y-m_str'] ]['earlier_part_done'] = true;
												if ( $each_month['is_month_lastday'] && isset( $log_ary[ $each_month['Y-m_str'] ]['latest'] ) && $log_ary[ $each_month['Y-m_str'] ]['latest'] === $each_month['b_date'] ) {
													$log_ary[ $each_month['Y-m_str'] ]['done']  = true;
													$log_ary[ $each_month['Y-m_str'] ]['doing'] = false;
												}
											}
											// 他のcronが書き換えているかもしれないので、最新のログを取得してから書き込む
											$newest_log                               = $this->wrap_get_contents( $log_file );
											$newest_log_ary                           = $this->wrap_unserialize( $newest_log );
											$newest_log_ary[ $each_month['Y-m_str'] ] = $log_ary[ $each_month['Y-m_str'] ];
											$this->wrap_put_contents( $log_file, $this->wrap_serialize( $newest_log_ary ) );

											//reset
											$goal_comp_sessions = array();
											$gfile_complete     = false;
											$log_ary            = $newest_log_ary;
										}
									}

									// Later part, Regular part
									if ( isset( $log_ary[ $each_month['Y-m_str'] ]['latest'] ) && $log_ary[ $each_month['Y-m_str'] ]['latest'] === $each_month['b_date'] ) {
										continue;
									}

									// log
									$log_ary[ $each_month['Y-m_str'] ]['doing'] = true;
									$this->wrap_put_contents( $log_file, $this->wrap_serialize( $log_ary ) );

									// 旧goalファイルがあるとき
									if ( ! isset( $log_ary['info']['set_after_ver2.2'] ) && $wp_filesystem->exists( $goal_file ) && $each_month['is_month_lastday'] ) {
										$file_mtime = $wp_filesystem->mtime( $goal_file );
										if ( $file_mtime ) {
											// ファイル作成日が、当月末日23:59:59＋1日よりも新しければ、当月の旧goalファイルは完成している
											$okutime = $qahm_time->str_to_unixtime( $each_month['b_date'] . ' 23:59:59' );
											$okutime = $okutime + ( 3600 * 24 );
											if ( $okutime < $file_mtime ) {
												switch ( $goal_ary['gtype'] ) {
													case 'gtype_page':
													case 'gtype_event':
														$make_goal_file = false;
														$gfile_complete = true;
														break;
													case 'gtype_click':
														$goal_comp_sessions = $qahm_data_api->extract_click_goal_comp_sessions( $tracking_id, $gid, $goal_ary, $each_month['a_date'], $each_month['b_date'], true, $goal_file );
														if ( ! is_null( $goal_comp_sessions ) ) {
															$make_goal_file = false;
															$gfile_complete = $this->wrap_put_contents( $goal_file, $this->wrap_serialize( $goal_comp_sessions ) );
														} else {
															$qahm_log->info( 'extract_click_goal_comp_sessions returned null. tracking_id: ' . $tracking_id . ', gid: ' . $gid . ', from_date: ' . $each_month['a_date'] . ', to_date: ' . $each_month['b_date'] );
														}
														break;
												}
											}
										}
									}

									if ( $make_goal_file ) {
										$add_to_exist_gfile = false;

										if ( isset( $log_ary[ $each_month['Y-m_str'] ]['latest'] ) && $wp_filesystem->exists( $goal_file ) ) {
											$from_date          = $qahm_time->xday_str( 1, $log_ary[ $each_month['Y-m_str'] ]['latest'], 'Y-m-d' );
											$add_to_exist_gfile = true;
										} else {
											$from_date = $each_month['a_date'];
										}
										$to_date = $each_month['b_date'];

										$goal_comp_sessions = $qahm_data_api->fetch_goal_comp_sessions_in_month( $tracking_id, $gid, $goal_ary, $from_date, $to_date );

										if ( ! is_null( $goal_comp_sessions ) ) {
											if ( $add_to_exist_gfile ) {
												$exist_gfile              = $this->wrap_get_contents( $goal_file );
												$exist_gfile_unserialized = $this->wrap_unserialize( $exist_gfile );
												$goal_comp_sessions       = $this->wrap_array_merge(
													is_array( $exist_gfile_unserialized ) ? $exist_gfile_unserialized : array(),
													is_array( $goal_comp_sessions ) ? $goal_comp_sessions : array()
												);
											}
											// 保存
											$gfile_complete = $this->wrap_put_contents( $goal_file, $this->wrap_serialize( $goal_comp_sessions ) );
										} else {
											$qahm_log->info( 'fetch_goal_comp_sessions returned null. tracking_id: ' . $tracking_id . ', gid: ' . $gid . ', from_date: ' . $from_date . ', to_date: ' . $to_date );
										}
									} // end if( $make_goal_file )

									// log
									if ( $gfile_complete ) {
										$log_ary[ $each_month['Y-m_str'] ]['latest'] = $each_month['b_date'];
										if ( $each_month['is_month_lastday'] ) {
											$log_ary[ $each_month['Y-m_str'] ]['done'] = true;
										}
									}
									$log_ary[ $each_month['Y-m_str'] ]['doing'] = false;
									// 他のcronが書き換えているかもしれないので、最新のログを取得してから書き込む
									$newest_log                               = $this->wrap_get_contents( $log_file );
									$newest_log_ary                           = $this->wrap_unserialize( $newest_log );
									$newest_log_ary[ $each_month['Y-m_str'] ] = $log_ary[ $each_month['Y-m_str'] ];
									$this->wrap_put_contents( $log_file, $this->wrap_serialize( $newest_log_ary ) );

								} // end if( ! $log_ary[ $each_month['Y-m_str'] ]['done'] )

								// 時間チェック（ゴールループ内）
								if ( $qahm_time->xsec_num( $qahm_time->now_str(), $gfile_make_start_time ) > $exec_limit_sec ) {
									$timelimit_exceeded = true;
									// 進捗を保存
									$goal_memo['processing_tracking_idx'] = $tracking_idx;
									$goal_memo['processing_month_idx']    = $month_idx;
									$goal_memo['processing_goal_idx']     = $goal_idx;
									$goal_memo['phase']                   = 'recent';
									$this->wrap_put_contents( $goal_memo_file, $this->wrap_serialize( $goal_memo ) );
									break;
								}
							} // end foreach( $goal_keys as $goal_idx => $gid )

							if ( $timelimit_exceeded ) {
								break;
							}
						} // end foreach( $goal_files_dateranges as $month_idx => $each_month )

						if ( $timelimit_exceeded ) {
							break;
						}
					} // end foreach( $site_tracking_ids as $tracking_idx => $tracking_id )

					if ( $timelimit_exceeded ) {
						// die()を削除し、正常終了に変更
						$qahm_log->info( 'Goal file making (recent) timed out. 次回継続します。' );
						$cron_status    = 'Night>Make Goal file>making loop>Start'; // 次回同じステップから再開
						$while_continue = false;
						$this->set_next_status( $cron_status );
						break;
					}

					// 残りの月分（月まるまる取る）
						// 直近3ヶ月が完了したら、フェーズを切り替え
					if ( $goal_memo['phase'] === 'recent' ) {
						$goal_memo['phase']                   = 'remaining';
						$goal_memo['processing_tracking_idx'] = 0;
						$goal_memo['processing_month_idx']    = 0;
						$goal_memo['processing_goal_idx']     = 0;
						$this->wrap_put_contents( $goal_memo_file, $this->wrap_serialize( $goal_memo ) );

						// $latest_ym_aryを再構築（recentフェーズと同じロジック）
						$latest_ym_ary = array();
						foreach ( $site_tracking_ids as $tracking_id ) {
							$pvterm_both_end = $qahm_data_api->get_pvterm_both_end_date( $tracking_id );
							if ( ! empty( $pvterm_both_end ) ) {
								$latest_ym_ary[ $tracking_id ] = substr( $pvterm_both_end['latest'], 0, 7 );
							}
						}
					}

					foreach ( $site_tracking_ids as $tracking_idx => $tracking_id ) {
						// メモから再開位置を判定（remainingフェーズの場合のみ）
						if ( $goal_memo['phase'] === 'remaining' && $tracking_idx < $goal_memo['processing_tracking_idx'] ) {
							continue;
						}

						$goals_ary = $qahm_data_api->get_goals_preferences( $tracking_id );
						if ( empty( $goals_ary ) ) {
							continue;
						}
						if ( ! isset( $latest_ym_ary[ $tracking_id ] ) ) {
							continue;
						}

						// dir
						$myview_dir         = $view_dir . $tracking_id . '/';
						$myview_summary_dir = $myview_dir . 'summary/';
						$log_dir            = $data_dir . 'log/';
						$mylog_dir          = $log_dir . $tracking_id . '/';

						$ym_keys         = array();
						$goal_1_log_file = $mylog_dir . 'goal_1_file_making.log';
						if ( $wp_filesystem->exists( $goal_1_log_file ) ) {
							$goal_1_log_contents = $this->wrap_get_contents( $goal_1_log_file );
							$goal_1_log          = $this->wrap_unserialize( $goal_1_log_contents );
							$ym_keys             = array_keys( $goal_1_log );
							arsort( $ym_keys );
						}

						// ym_keysを配列化してインデックスでアクセスできるようにする
						$ym_keys_indexed = array_values( $ym_keys );
						foreach ( $ym_keys_indexed as $month_idx => $ym_key ) {
							if ( $ym_key === 'info' ) {
								continue;
							}
							// 直近は除く
							if ( $ym_key === $latest_ym_ary[ $tracking_id ] ) {
								continue;
							}

							// メモから再開位置を判定（remainingフェーズで同じtracking_idの場合）
							if ( $goal_memo['phase'] === 'remaining' &&
								$tracking_idx === $goal_memo['processing_tracking_idx'] &&
								$month_idx < $goal_memo['processing_month_idx'] ) {
								continue;
							}

							// goals_aryのキーを配列化してインデックスでアクセスできるようにする
							$goal_keys = array_keys( $goals_ary );
							foreach ( $goal_keys as $goal_idx => $gid ) {
								$goal_ary = $goals_ary[ $gid ];

								// メモから再開位置を判定（remainingフェーズで同じtracking_id、同じmonthの場合）
								if ( $goal_memo['phase'] === 'remaining' &&
									$tracking_idx === $goal_memo['processing_tracking_idx'] &&
									$month_idx === $goal_memo['processing_month_idx'] &&
									$goal_idx < $goal_memo['processing_goal_idx'] ) {
									continue;
								}

								$log_file = $mylog_dir . 'goal_' . $gid . '_file_making.log';
								$log_ary  = array();
								if ( $wp_filesystem->exists( $log_file ) ) {
									$log_file_contents = $this->wrap_get_contents( $log_file );
									$log_ary           = $this->wrap_unserialize( $log_file_contents );
								} else {
									continue;
								}

								$status_ary = $log_ary[ $ym_key ];

								if ( ! $status_ary['done'] ) {
									if ( isset( $log_ary['info']['prevent_cron'] ) && $log_ary['info']['prevent_cron'] ) {
										continue;
									}
									if ( $status_ary['doing'] ) {
										continue;
									}
									$log_ary[ $ym_key ]['doing'] = true;
									$this->wrap_put_contents( $log_file, $this->wrap_serialize( $log_ary ) );

									$goal_comp_sessions = array();
									$make_goal_file     = true;
									$gfile_complete     = false;

									$goal_file = $myview_summary_dir . $ym_key . '-01_goal_' . $gid . '_1mon.php';
									$from_date = $ym_key . '-01';
									if ( isset( $status_ary['starting_end'] ) && $status_ary['starting_end'] ) {
										$from_date = $log_ary['info']['pvterm_start'];
									}
									$to_date = $ym_key . '-' . strval( $qahm_time->month_daynum( $from_date ) );

									// 旧goalファイルがあるとき
									if ( ! isset( $log_ary['info']['set_after_ver2.2'] ) && $wp_filesystem->exists( $goal_file ) ) {
										$file_mtime = $wp_filesystem->mtime( $goal_file );
										if ( $file_mtime ) {
											// ファイル作成日が、当月末日23:59:59＋1日よりも新しければ、当月の旧goalファイルは完成している
											$okutime = $qahm_time->str_to_unixtime( $to_date . ' 23:59:59' );
											$okutime = $okutime + ( 3600 * 24 );
											if ( $okutime < $file_mtime ) {
												switch ( $goal_ary['gtype'] ) {
													case 'gtype_page':
													case 'gtype_event':
														$make_goal_file = false;
														$gfile_complete = true;
														break;
													case 'gtype_click':
														$goal_comp_sessions = $qahm_data_api->extract_click_goal_comp_sessions( $tracking_id, $gid, $goal_ary, $from_date, $to_date, true, $goal_file );
														if ( ! is_null( $goal_comp_sessions ) ) {
															$make_goal_file = false;
															$gfile_complete = $this->wrap_put_contents( $goal_file, $this->wrap_serialize( $goal_comp_sessions ) );
														} else {
															$qahm_log->info( 'extract_click_goal_comp_sessions returned null. tracking_id: ' . $tracking_id . ', gid: ' . $gid . ', from_date: ' . $from_date . ', to_date: ' . $to_date );
														}
														break;
												}
											}
										}
									}

									if ( $make_goal_file ) {
										$goal_comp_sessions = $qahm_data_api->fetch_goal_comp_sessions_in_month( $tracking_id, $gid, $goal_ary, $from_date, $to_date );
										if ( ! is_null( $goal_comp_sessions ) ) {
											$gfile_complete = $this->wrap_put_contents( $goal_file, $this->wrap_serialize( $goal_comp_sessions ) );
										} else {
											$qahm_log->info( 'fetch_goal_comp_sessions returned null. tracking_id: ' . $tracking_id . ', gid: ' . $gid . ', from_date: ' . $from_date . ', to_date: ' . $to_date );
										}
									}
									if ( $gfile_complete ) {
										$log_ary[ $ym_key ]['done'] = true;
									}
									$log_ary[ $ym_key ]['doing'] = false;
									// 他のcronが書き換えているかもしれないので、最新のログを取得してから書き込む
									$newest_log                = $this->wrap_get_contents( $log_file );
									$newest_log_ary            = $this->wrap_unserialize( $newest_log );
									$newest_log_ary[ $ym_key ] = $log_ary[ $ym_key ];
									$this->wrap_put_contents( $log_file, $this->wrap_serialize( $newest_log_ary ) );

								}

								// 時間チェック（ゴールループ内）
								if ( $qahm_time->xsec_num( $qahm_time->now_str(), $gfile_make_start_time ) > $exec_limit_sec ) {
									$timelimit_exceeded = true;
									// 進捗を保存
									$goal_memo['processing_tracking_idx'] = $tracking_idx;
									$goal_memo['processing_month_idx']    = $month_idx;
									$goal_memo['processing_goal_idx']     = $goal_idx;
									$goal_memo['phase']                   = 'remaining';
									$this->wrap_put_contents( $goal_memo_file, $this->wrap_serialize( $goal_memo ) );
									break;
								}
							} // end foreach( $goal_keys as $goal_idx => $gid )

							if ( $timelimit_exceeded ) {
								break;
							}
						} // end foreach( $ym_keys_indexed as $month_idx => $ym_key )

						if ( $timelimit_exceeded ) {
							break;
						}
					} // end foreach( $site_tracking_ids as $tracking_idx => $tracking_id )

					if ( $timelimit_exceeded ) {
						// die()を削除し、正常終了に変更
						$qahm_log->info( 'Goal file making (remaining) timed out. 次回継続します。' );
						$cron_status    = 'Night>Make Goal file>making loop>Start'; // 次回同じステップから再開
						$while_continue = false;
						$this->set_next_status( $cron_status );
						break;
					}

					// ---next
					$cron_status = 'Night>Make Goal file>making loop>End';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Make Goal file>making loop>End':
						$this->backup_prev_status( $cron_status );
						$this->wrap_delete( $temp_dir . 'cron_first_make_goal_file.php' );
						$this->wrap_delete( $temp_dir . 'cron_goal_making_memo.php' ); // 進捗メモファイルを削除

						// ---next
						$cron_status = 'Night>Make Goal file>End';
						$this->set_next_status( $cron_status );
					break;

				case 'Night>Make Goal file>End':
					$this->backup_prev_status( $cron_status );
					$this->wrap_delete( $temp_dir . 'cron_first_make_goal_file.php' );

					// ---next
					$cron_status = 'Night>Make cache file>Start';
					$this->set_next_status( $cron_status );
					break;

				// ----------
				// Making Cache File
				// ----------
				case 'Night>Make cache file>Start':
					$this->backup_prev_status( $cron_status );
					//make cache dir root
					if ( ! $wp_filesystem->exists( $cache_dir ) ) {
						$wp_filesystem->mkdir( $cache_dir );
					}
					//search view dirname and make each tracking cache dir
					$tracking_dirs = $this->wrap_dirlist( $view_dir );
					if ( ! $tracking_dirs ) {
						$tracking_dir = array();
					}
					foreach ( $tracking_dirs as $dir ) {
						$dirname = $dir['name'];
						if ( is_dir( $view_dir . $dirname ) ) {
							if ( ! $wp_filesystem->exists( $cache_dir . $dirname ) ) {
								$wp_filesystem->mkdir( $cache_dir . $dirname );
							}
						}
					}
					// ---next
					$cron_status = 'Night>Make cache file>Admin heatmap>Start';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Make cache file>Admin heatmap>Start':
					$this->backup_prev_status( $cron_status );
					// 再作成するので既存のゴミファイルを削除
					//最上階層
					if ( $wp_filesystem->exists( $cache_heatmap_list_idx_temp_file ) ) {
						$wp_filesystem->delete( $cache_heatmap_list_idx_temp_file );
					}
					if ( $wp_filesystem->exists( $cache_heatmap_list_file ) ) {
						$wp_filesystem->delete( $cache_heatmap_list_file );
					}
					//allディレクトリ
					$alldir          = $this->get_tracking_id();
					$all_hmlist_file = $cache_dir . $alldir . '/heatmap_list.php';
					if ( $wp_filesystem->exists( $all_hmlist_file ) ) {
						$wp_filesystem->delete( $all_hmlist_file );
					}

					//各tracking_idディレクトリ
					$view_dir = $data_dir . 'view/';
					//search view dirname and make each tracking cache dir
					$tracking_dirs = $this->wrap_dirlist( $view_dir );
					foreach ( $tracking_dirs as $dir ) {
						$dirname = $dir['name'];
						//add maruyama for bug 20230617
						if ( $wp_filesystem->exists( $cache_dir . $dirname . '/heatmap_list.php' ) ) {
							$wp_filesystem->delete( $cache_dir . $dirname . '/heatmap_list.php' );
						}
					}

					// ---next
					$cron_status = 'Night>Make cache file>End';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Make cache file>End':
					$this->backup_prev_status( $cron_status );

					// ---next
					$cron_status = 'Night>AI Report>Start';
					$this->set_next_status( $cron_status );
					break;

				// ----------
				// AI Report Generation
				// ----------
				case 'Night>AI Report>Start':
					$this->backup_prev_status( $cron_status );

					// ---next
					$cron_status = 'Night>AI Report>Generate';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>AI Report>Generate':
					$this->backup_prev_status( $cron_status );

					// Generate AI reports for all sites.
					global $qahm_ai_report_generator;
					if ( isset( $qahm_ai_report_generator ) ) {
						try {
							$report_results = $qahm_ai_report_generator->run_cron_report_generation();

							if ( ! empty( $report_results['errors'] ) ) {
								global $qahm_log;
								if ( is_object( $qahm_log ) && method_exists( $qahm_log, 'warning' ) ) {
									foreach ( $report_results['errors'] as $error ) {
										$qahm_log->warning( 'AI Report generation error: ' . $error );
									}
								}
							}
						} catch ( Exception $e ) {
							global $qahm_log;
							if ( is_object( $qahm_log ) && method_exists( $qahm_log, 'error' ) ) {
								$qahm_log->error( 'AI Report generation exception: ' . $e->getMessage() );
							}
						}
					}

					// ---next
					$cron_status = 'Night>AI Report>End';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>AI Report>End':
					$this->backup_prev_status( $cron_status );

					// ---next
					$cron_status = 'Night>Storage stats cache';
					$this->set_next_status( $cron_status );
					$while_continue = false;
					break;

				// ----------
				// Storage stats cache (Issue #1037)
				// チャンク処理: 時間バジェット内で走査し、未完了なら次回 cron で再開
				// ----------
				case 'Night>Storage stats cache':
					$this->backup_prev_status( $cron_status );

					// ---next
					$cron_status = 'Night>Storage stats cache>Loop';
					$this->set_next_status( $cron_status );
					break;

				case 'Night>Storage stats cache>Loop':
					$this->backup_prev_status( $cron_status );

					// 先にステータスを Night>End に進めておく。
					// count_files_chunked() が Fatal Error で死んでも
					// 次回 cron は Night>End から再開し永久ループにならない。
					$cron_status = 'Night>End';
					$this->set_next_status( $cron_status );

					try {
						$storage_stats = $this->count_files_chunked( 60 );

						if ( $storage_stats['done'] ) {
							// 走査完了 — キャッシュに保存
							$storage_stats['updated'] = time();
							$this->wrap_put_contents(
								$cache_dir . 'storage_stats_cache.php',
								$this->wrap_serialize( $storage_stats )
							);
							$qahm_log->info( 'Storage stats cache updated: files=' . $storage_stats['filecount'] . ', size=' . $storage_stats['size'] );
							// $cron_status は既に Night>End
						} else {
							// 未完了 — 次回 cron で続きから再開
							$qahm_log->info( 'Storage stats cache chunked: progress files=' . $storage_stats['filecount'] . ', continuing next cron' );
							$cron_status = 'Night>Storage stats cache>Loop';
							$this->set_next_status( $cron_status );
							$while_continue = false;
						}
					} catch ( Throwable $e ) {
						$qahm_log->warning( 'Storage stats cache failed: ' . $e->getMessage() );
						// $cron_status は既に Night>End — キャッシュなしで続行
					}

					break;

				// ----------
				// Night End
				// ----------
				case 'Night>End':
					$this->backup_prev_status( $cron_status );

					if ( ! $this->wrap_put_contents( $is_night_comp_file, '1' ) ) {
						throw new Exception( 'cronのnight do file生成に失敗しました。終了します。' );
					}
					// ---next
					$cron_status = 'Cron end';
					$this->set_next_status( $cron_status );
					break;

				// ----------
				// End
				// ----------

				//2系統システムのプライマリーではトラッキングハッシュの更新のみ
				case 'Night_SP>Start':
					$this->backup_prev_status( $cron_status );

					// ---next
					$cron_status = 'Night_SP>Tracking tag>Update';
					$this->set_next_status( $cron_status );
					break;

				case 'Night_SP>Tracking tag>Update':
					$this->backup_prev_status( $cron_status );

					global $qahm_data_api;
					$siteary = $qahm_data_api->get_sitemanage();
					if ( ! empty( $siteary ) ) {
						foreach ( $siteary as $site ) {
							$tid = $site['tracking_id'];
							$this->create_qtag( $tid );
						}
					}

					$cron_status = 'Night>End'; //夜間処理が行われたものとみなす

					$this->set_next_status( $cron_status );
					break;

				case 'Cron end':
					$this->backup_prev_status( $cron_status );

					// ---next
					$cron_status = 'Idle';
					$this->set_next_status( $cron_status );
					break;

				case 'Idle':
					$this->backup_prev_status( $cron_status );

					$while_continue = false;
					break;

				case 'error':
					$this->backup_prev_status( $cron_status );

					// ---next
					$cron_status = 'Idle';
					$this->set_next_status( $cron_status );
					$while_continue = false;
					break;

				default:
					$qahm_log->warning( '不明な cron_status を検出しました: ' . $cron_status . ' — Idle にリセットして終了します。' );
					$cron_status = 'Idle';
					$this->set_next_status( $cron_status );
					$while_continue = false;
					break;
			}
			usleep( '30' );
			++$while_lpcnt;
			if ( $while_lpcnt > self::MAX_WHILECOUNT ) {
					$while_continue = false;
			}
		}
	}

	private function get_all_tracking_ids() {
		$tracking_ids       = array( 'all' );
		$valid_tracking_ids = $this->get_valid_tracking_ids_with_cache();
		if ( $valid_tracking_ids ) {
			$tracking_ids = $this->wrap_array_merge( $tracking_ids, $valid_tracking_ids );
		}
		return array_unique( $tracking_ids );
	}

	/*
	 * Verify dbin files have corresponding pv_log data
	 * Issue #54: Server load data verification with efficient mapping approach
	 */
	private function verify_dbin_pv_log_data() {
		global $wp_filesystem;

		$target_files = $this->get_dbin_target_files();

		if ( empty( $target_files ) ) {
			return;
		}

		$file_access_time_map = array();
		$all_access_times     = array();

		foreach ( $target_files as $file_path ) {
			$access_times = $this->extract_access_times( $file_path );
			if ( ! empty( $access_times ) ) {
				$file_access_time_map[ $file_path ] = $access_times;
				$all_access_times                   = $this->wrap_array_merge( $all_access_times, $access_times );
			}
		}

		if ( empty( $all_access_times ) ) {
			return;
		}

		$existing_access_times = $this->get_existing_access_times( $all_access_times );

		$recovery_count = 0;
		foreach ( $file_access_time_map as $file_path => $access_times ) {
			$has_missing_data = false;
			foreach ( $access_times as $access_time ) {
				if ( ! $this->wrap_in_array( $access_time, $existing_access_times ) ) {
					$has_missing_data = true;
					break;
				}
			}

			if ( $has_missing_data ) {
				$this->recover_file_to_finish( $file_path );
				++$recovery_count;
			}
		}

		if ( $recovery_count > 0 ) {
			global $qahm_log;
			$qahm_log->info( "Recovered {$recovery_count} files from dbin to finish" );
		}
	}

	/**
	 * Get dbin target files for deletion (2 days before or older)
	 */
	private function get_dbin_target_files() {
		global $wp_filesystem, $qahm_time;

		$data_dir        = $this->get_data_dir_path();
		$readersdbin_dir = $data_dir . 'readers/dbin/';

		if ( ! $wp_filesystem->exists( $readersdbin_dir ) ) {
			return array();
		}

		$dbin_files   = $this->wrap_dirlist( $readersdbin_dir );
		$target_files = array();

		$day2before_str = $qahm_time->xday_str( -2 );
		$day2before_end = $qahm_time->str_to_unixtime( $day2before_str . ' 23:59:59' );

		foreach ( $dbin_files as $file ) {
			if ( $file['lastmodunix'] <= $day2before_end ) {
				$target_files[] = $readersdbin_dir . $file['name'];
			}
		}

		return $target_files;
	}

	/**
	 * Extract access_times from session file
	 */
	private function extract_access_times( $file_path ) {
		global $wp_filesystem;

		if ( ! $wp_filesystem->exists( $file_path ) ) {
			return array();
		}

		$contents     = $this->wrap_get_contents( $file_path );
		$session_data = $this->wrap_unserialize( $contents );

		if ( ! isset( $session_data['body'] ) || ! is_array( $session_data['body'] ) ) {
			return array();
		}

		$access_times = array();
		foreach ( $session_data['body'] as $pv_data ) {
			if ( isset( $pv_data['access_time'] ) ) {
				$access_times[] = $pv_data['access_time'];
			}
		}

		return $access_times;
	}

	/**
	 * Get existing access_times from pv_log table (efficient bulk SELECT)
	 */
	private function get_existing_access_times( $all_access_times ) {
		global $wpdb, $qahm_time;

		if ( empty( $all_access_times ) ) {
			return array();
		}

		$unique_access_times = array_unique( $all_access_times );

		$datetime_values      = array();
		$unix_to_datetime_map = array();
		foreach ( $unique_access_times as $unix_time ) {
			$datetime                          = $qahm_time->unixtime_to_str( $unix_time, 'Y-m-d H:i:s' );
			$datetime_values[]                 = $datetime;
			$unix_to_datetime_map[ $datetime ] = $unix_time;
		}

		$placeholders = $this->wrap_implode( ',', array_fill( 0, $this->wrap_count( $datetime_values ), '%s' ) );
		$query        = $wpdb->prepare(
			"SELECT DISTINCT access_time FROM `{$wpdb->prefix}qa_pv_log` WHERE access_time IN ($placeholders)",
			$datetime_values
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders array used; identifiers fixed/whitelisted.
		$found_datetimes = $wpdb->get_col( $query );

		$existing_unix_times = array();
		foreach ( $found_datetimes as $datetime ) {
			if ( isset( $unix_to_datetime_map[ $datetime ] ) ) {
				$existing_unix_times[] = $unix_to_datetime_map[ $datetime ];
			}
		}

		return $existing_unix_times;
	}

	/**
	 * Recover file from dbin to finish directory
	 */
	private function recover_file_to_finish( $dbin_file_path ) {
		global $wp_filesystem;

		$finish_file_path = str_replace( '/dbin/', '/finish/', $dbin_file_path );

		if ( $wp_filesystem->exists( $dbin_file_path ) ) {
			$contents = $wp_filesystem->get_contents( $dbin_file_path );
			$wp_filesystem->put_contents( $finish_file_path, $contents );
			$wp_filesystem->delete( $dbin_file_path );
		}
	}

	/**
	 * Generate monthly CSV reports for all tracking IDs
	 */
	private function generate_monthly_csv_reports() {
		$tracking_ids  = $this->get_all_tracking_ids();
		$csv_generator = new QAHM_CSV_Report_Generator();

		foreach ( $tracking_ids as $tracking_id ) {
			try {
				$csv_generator->generate_system_monthly_reports( $tracking_id );
			} catch ( Exception $e ) {
				global $qahm_log;
				if ( isset( $qahm_log ) ) {
					$qahm_log->error( 'Monthly CSV generation failed for tracking_id: ' . $tracking_id . ' - ' . $e->getMessage() );
				}
			}
		}
	}
} // end of class
