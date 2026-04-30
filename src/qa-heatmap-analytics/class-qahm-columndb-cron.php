<?php
/**
 * 列DB 夜間バッチ変換クラス
 *
 * view_pvファイルを列DB（allpv + click_event + datalayer_event）に変換する夜間バッチ処理。
 * 昨日分から処理を開始し、制限時間内で過去に遡って変換する。
 * click_event変換（Phase C）: rawcファイル → 14カラムバイナリ列DB。
 * datalayer_event変換（Phase D）: rawgファイル → Layer 1(5カラムバイナリ列DB) + Layer 2(イベント別表配列)。
 *
 * cronフロー:
 *   Night>Make column-db>Start → Night>Make column-db>Loop → Night>Make column-db>End
 *
 * @package qa_heatmap
 */

class QAHM_ColumnDB_Cron extends QAHM_Base {

	/**
	 * 制限時間（秒）
	 */
	const TIME_LIMIT_SEC = 300;

	/**
	 * cronステップ: Start
	 *
	 * tracking_id一覧と未処理日付を取得し、処理対象リストを作成する。
	 *
	 * @return string 次のcronステータス
	 */
	public function start() {
		global $qahm_data_api, $qahm_log;

		$temp_dir = $this->get_data_dir_path( 'temp' );

		// tracking_id一覧を取得 (末尾に 'all' 仮想サイトを追加 — T68)
		$siteary = $qahm_data_api->get_sitemanage();
		if ( ! is_array( $siteary ) ) {
			$siteary = array();
		}
		$siteary[] = array( 'tracking_id' => 'all', 'domain' => '' );

		$targets = array();
		foreach ( $siteary as $site ) {
			$tid   = $site['tracking_id'];
			$dates = $this->get_unprocessed_dates( $tid );
			foreach ( $dates as $date ) {
				$targets[] = array(
					'tracking_id' => $tid,
					'date'        => $date,
					'domain'      => $site['domain'] ?? '',
				);
			}
		}

		if ( empty( $targets ) ) {
			if ( $qahm_log ) {
				$qahm_log->info( 'ColumnDB cron: No unprocessed dates found' );
			}
			return 'Night>Make column-db>End';
		}

		// 処理対象リストを保存（index=0から開始）
		$target_memo = array(
			'index'   => 0,
			'targets' => $targets,
		);
		$this->wrap_put_contents(
			$temp_dir . 'cron_columndb_target_memo.php',
			$this->wrap_serialize( $target_memo )
		);

		if ( $qahm_log ) {
			$qahm_log->info( 'ColumnDB cron: Start (' . count( $targets ) . ' date(s) to process)' );
		}

		return 'Night>Make column-db>Loop';
	}

	/**
	 * cronステップ: Loop
	 *
	 * プロセス内ループで複数日付のview_pvを列DBに変換する。
	 * TIME_LIMIT_SEC超過時は次のcronプロセスに委譲（yield経由）。
	 * 全処理完了時にEndへ遷移する。
	 *
	 * @return string 次のcronステータス
	 */
	public function process_loop() {
		global $qahm_log;

		$temp_dir   = $this->get_data_dir_path( 'temp' );
		$loop_start = time();

		// target_memoを読み込み
		$memo_slz = $this->wrap_get_contents( $temp_dir . 'cron_columndb_target_memo.php' );
		$memo     = $this->wrap_unserialize( $memo_slz );

		if ( ! $memo || ! isset( $memo['targets'] ) || ! isset( $memo['index'] ) ) {
			return 'Night>Make column-db>End';
		}

		$index   = $memo['index'];
		$targets = $memo['targets'];
		$count   = count( $targets );

		// プロセス内ループ: 時間制限内で複数日付を処理
		while ( $index < $count ) {
			$target      = $targets[ $index ];
			$tracking_id = $target['tracking_id'];
			$date        = $target['date'];

			$domain      = $target['domain'] ?? '';

			$result = $this->convert_one_date( $tracking_id, $date, $domain );

			if ( $result && $qahm_log ) {
				$qahm_log->debug( 'ColumnDB converted: ' . $tracking_id . ' / ' . $date );
			}

			$index++;

			// インデックスを進めて保存（日付単位で中間保存）
			$memo['index'] = $index;
			$this->wrap_put_contents(
				$temp_dir . 'cron_columndb_target_memo.php',
				$this->wrap_serialize( $memo )
			);

			// 時間制限チェック（日付処理完了後に判定）
			if ( ( time() - $loop_start ) > self::TIME_LIMIT_SEC ) {
				if ( $qahm_log ) {
					$qahm_log->info( 'ColumnDB cron: Time limit reached (' . $index . '/' . $count . ')' );
				}
				return 'Night>Make column-db>Loop';
			}
		}

		// 全処理完了
		if ( $qahm_log ) {
			$qahm_log->info( 'ColumnDB cron: All dates processed (' . $count . ')' );
		}
		return 'Night>Make column-db>End';
	}

	/**
	 * cronステップ: End
	 *
	 * 一時ファイルを削除し、元のcronフローに復帰する。
	 *
	 * @return string 次のcronステータス
	 */
	public function end() {
		$temp_dir = $this->get_data_dir_path( 'temp' );

		$this->wrap_delete( $temp_dir . 'cron_columndb_target_memo.php' );

		return 'Night>Delete>Start';
	}

	/**
	 * 未処理日付を取得
	 *
	 * view_pvファイルが存在するがallpvまたはclick_eventが未生成の日付を取得する。
	 * 降順ソート（昨日→過去）で返す。
	 *
	 * @param string $tracking_id 追跡ID
	 * @return array 日付の配列（YYYYMMDD形式）
	 */
	private function get_unprocessed_dates( $tracking_id ) {
		$data_dir = $this->get_data_dir_path();
		$view_dir = $data_dir . 'view/' . $tracking_id . '/view_pv/';

		if ( ! is_dir( $view_dir ) ) {
			return array();
		}

		// view_pvファイルから日付を抽出
		// ファイル名形式: YYYY-MM-DD_*_viewpv.php
		$files = glob( $view_dir . '*_viewpv.php' );
		$view_dates = array();
		if ( $files ) {
			foreach ( $files as $file ) {
				$basename = basename( $file );
				// ファイル名先頭10文字が YYYY-MM-DD 形式（4,7文字目がハイフン、11文字目が_）
				if ( strlen( $basename ) >= 11 && $basename[4] === '-' && $basename[7] === '-' && $basename[10] === '_'
					&& ctype_digit( substr( $basename, 0, 4 ) ) && ctype_digit( substr( $basename, 5, 2 ) ) && ctype_digit( substr( $basename, 8, 2 ) ) ) {
					$date_ymd = str_replace( '-', '', substr( $basename, 0, 10 ) );
					$view_dates[ $date_ymd ] = true;
				}
			}
		}

		// raw_cファイルから日付を抽出（click_event用）
		$rawc_dir = $view_dir . 'raw_c/';
		$rawc_dates = array();
		if ( is_dir( $rawc_dir ) ) {
			$rawc_files = glob( $rawc_dir . '*_rawc.php' );
			if ( $rawc_files ) {
				foreach ( $rawc_files as $file ) {
					$basename = basename( $file );
					if ( strlen( $basename ) >= 11 && $basename[4] === '-' && $basename[7] === '-' && $basename[10] === '_'
					&& ctype_digit( substr( $basename, 0, 4 ) ) && ctype_digit( substr( $basename, 5, 2 ) ) && ctype_digit( substr( $basename, 8, 2 ) ) ) {
						$date_ymd = str_replace( '-', '', substr( $basename, 0, 10 ) );
						$rawc_dates[ $date_ymd ] = true;
					}
				}
			}
		}

		// raw_gファイルから日付を抽出（datalayer_event用）
		$rawg_dir = $view_dir . 'raw_g/';
		$rawg_dates = array();
		if ( is_dir( $rawg_dir ) ) {
			$rawg_files = glob( $rawg_dir . '*_rawg.php' );
			if ( $rawg_files ) {
				foreach ( $rawg_files as $file ) {
					$basename = basename( $file );
					if ( strlen( $basename ) >= 11 && $basename[4] === '-' && $basename[7] === '-' && $basename[10] === '_'
					&& ctype_digit( substr( $basename, 0, 4 ) ) && ctype_digit( substr( $basename, 5, 2 ) ) && ctype_digit( substr( $basename, 8, 2 ) ) ) {
						$date_ymd = str_replace( '-', '', substr( $basename, 0, 10 ) );
						$rawg_dates[ $date_ymd ] = true;
					}
				}
			}
		}

		// allpv, click_event, datalayer_eventが未処理の日付を抽出（今日以降はスキップ — データ未確定）
		$report_dir  = $data_dir . 'report/' . $tracking_id . '/columns-db/';
		$allpv_dir   = $report_dir . 'allpv/';
		$click_dir   = $report_dir . 'click_event/';
		$dl_dir      = $report_dir . 'datalayer_event/';
		$today_ymd   = wp_date( 'Ymd' );
		$unprocessed = array();

		$all_dates = array_unique( array_merge( array_keys( $view_dates ), array_keys( $rawc_dates ), array_keys( $rawg_dates ) ) );
		foreach ( $all_dates as $date_ymd ) {
			if ( $date_ymd >= $today_ymd ) {
				continue;
			}
			$year_month = substr( $date_ymd, 0, 6 );

			// allpv未処理チェック
			$allpv_file = $allpv_dir . $year_month . '/allpv_' . $date_ymd . '_pv_id.php';
			$allpv_done = file_exists( $allpv_file );

			// click_event未処理チェック（rawcが存在する場合のみ）
			$click_done = true;
			if ( isset( $rawc_dates[ $date_ymd ] ) ) {
				$click_file = $click_dir . $year_month . '/click_event_' . $date_ymd . '_pv_id.php';
				$click_done = file_exists( $click_file );
			}

			// datalayer_event未処理チェック（rawgが存在する場合のみ）
			$dl_done = true;
			if ( isset( $rawg_dates[ $date_ymd ] ) ) {
				$dl_file = $dl_dir . $year_month . '/datalayer_event_' . $date_ymd . '_pv_id.php';
				$dl_done = file_exists( $dl_file );
			}

			if ( ! $allpv_done || ! $click_done || ! $dl_done ) {
				$unprocessed[] = $date_ymd;
			}
		}

		// 降順ソート（昨日→過去）
		rsort( $unprocessed );

		return $unprocessed;
	}

	/**
	 * 1日分のview_pvを列DB（allpv + click_event + datalayer_event）に変換
	 *
	 * allpv変換後、rawcファイルが存在すればPhase C（click_event列DB変換）を、
	 * rawgファイルが存在すればPhase D（datalayer_event列DB変換）を実行する。
	 * 各変換は既に完了済みの場合スキップする。
	 *
	 * @param string $tracking_id 追跡ID
	 * @param string $date_ymd 日付（YYYYMMDD形式）
	 * @param string $domain サイトドメイン（内部/外部URL判定用）
	 * @return bool 成功/失敗
	 */
	private function convert_one_date( $tracking_id, $date_ymd, $domain = '' ) {
		global $qahm_log;

		$data_dir    = $this->get_data_dir_path();
		$view_dir    = $data_dir . 'view/' . $tracking_id . '/view_pv/';
		$report_dir  = $data_dir . 'report/' . $tracking_id . '/columns-db/';
		$allpv_dir   = $report_dir . 'allpv/';
		$year_month  = substr( $date_ymd, 0, 6 );

		// YYYYMMDD → YYYY-MM-DD
		$date_hyphen = substr( $date_ymd, 0, 4 ) . '-' . substr( $date_ymd, 4, 2 ) . '-' . substr( $date_ymd, 6, 2 );

		$processed = 0;

		// ========================================
		// allpv列DB変換（既存処理）
		// ========================================
		$allpv_check = $allpv_dir . $year_month . '/allpv_' . $date_ymd . '_pv_id.php';
		if ( ! file_exists( $allpv_check ) ) {
			$files = glob( $view_dir . $date_hyphen . '_*_viewpv.php' );
			if ( $files ) {
				// 全レコードを読み込み
				$all_records = array();
				foreach ( $files as $file ) {
					$slz  = $this->wrap_get_contents( $file );
					$data = $this->wrap_unserialize( $slz );
					if ( is_array( $data ) ) {
						foreach ( $data as $item ) {
							$all_records[] = $item;
						}
					}
				}

				if ( ! empty( $all_records ) ) {
					// access_timeでソート
					usort( $all_records, function( $a, $b ) {
						return ( $a['access_time'] ?? 0 ) - ( $b['access_time'] ?? 0 );
					} );

					// qa_pv_logからcontent_idをバルク取得
					$content_id_map = array();
					$pv_ids = array();
					foreach ( $all_records as $rec ) {
						$pid = (int) ( $rec['pv_id'] ?? 0 );
						if ( $pid > 0 ) {
							$pv_ids[] = $pid;
						}
					}
					if ( ! empty( $pv_ids ) ) {
						// max_allowed_packet 対策: 50000件ずつチャンク分割して問い合わせる。
						// array_merge は使わず content_id_map に直接追記（配列コピーでのメモリ倍増を回避）
						global $wpdb;
						$table_name = $wpdb->prefix . 'qa_pv_log';
						$chunks     = array_chunk( $pv_ids, 50000 );
						foreach ( $chunks as $chunk ) {
							$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
							$sql          = $wpdb->prepare(
								"SELECT pv_id, content_id FROM {$table_name} WHERE pv_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is $wpdb->prefix . 'qa_pv_log', $placeholders is array_fill of %d
								$chunk
							);
							$rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is from $wpdb->prepare() above
							if ( $rows ) {
								foreach ( $rows as $r ) {
									$content_id_map[ (int) $r->pv_id ] = (int) $r->content_id;
								}
							}
						}
					}

					// ========================================
					// Phase 0: 行動カラム算出（raw_p/raw_c/raw_e → behavioral_map）
					// ========================================
					$behavioral_map = $this->compute_behavioral_columns( $view_dir, $date_hyphen, $all_records );

					// Writer & Sessions を初期化
					$writer   = new QAHM_ColumnDB_Writer( 'allpv', $tracking_id, $allpv_dir );
					$sessions = new QAHM_ColumnDB_Sessions( $tracking_id, $allpv_dir );

					// セッション開始時刻追跡用
					$current_session_start = array();
					$last_access_time      = array();
					// 30分（1800秒）以上の時間ギャップで新セッションと判定
					// 根拠: class-qahm-behavioral-data.php:278 の readers/temp タイムアウトと一致
					$session_gap_sec = 1800;

					// ========================================
					// パス1: セッション判定 + 行データ構築 + セッション別インデックス蓄積
					// ========================================
					$session_seq = array();
					$rows        = array();

					foreach ( $all_records as $i => $record ) {
						$reader_id   = $record['reader_id'] ?? 0;
						$pv          = (int) ( $record['pv'] ?? 1 );
						$access_time = (int) ( $record['access_time'] ?? 0 );

						// 新セッション判定: pv=1 OR 前回access_timeから30分以上経過 OR 初出
						$is_new_session = false;
						if ( $pv === 1 ) {
							$is_new_session = true;
						} elseif ( ! isset( $current_session_start[ $reader_id ] ) ) {
							$is_new_session = true;
						} elseif ( isset( $last_access_time[ $reader_id ] )
							&& ( $access_time - $last_access_time[ $reader_id ] ) > $session_gap_sec ) {
							$is_new_session = true;
						}

						if ( $is_new_session ) {
							$current_session_start[ $reader_id ] = $access_time;
						}
						$last_access_time[ $reader_id ] = $access_time;

						$session_start = $current_session_start[ $reader_id ];
						$session_id    = $sessions->get_or_create( (string) $reader_id, $session_start );

						$pv_id_int = (int) ( $record['pv_id'] ?? 0 );
						$beh       = $behavioral_map[ $pv_id_int ] ?? array();

						$rows[ $i ] = array(
							'pv_id'       => $pv_id_int,
							'session_id'  => $session_id,
							'reader_id'   => (int) $reader_id,
							'page_id'     => (int) ( $record['page_id'] ?? 0 ),
							'device_id'   => (int) ( $record['device_id'] ?? 1 ),
							'source_id'   => (int) ( $record['source_id'] ?? 0 ),
							'medium_id'   => (int) ( $record['medium_id'] ?? 0 ),
							'campaign_id' => (int) ( $record['campaign_id'] ?? 0 ),
							'content_id'  => $content_id_map[ $pv_id_int ] ?? 0,
							'access_time' => $access_time,
							'pv'          => $pv,
							'speed_msec'  => (int) ( $record['speed_msec'] ?? 0 ),
							'browse_sec'  => (int) ( $record['browse_sec'] ?? 0 ),
							'is_last'     => (int) ( $record['is_last'] ?? 0 ),
							'is_newuser'  => (int) ( $record['is_newuser'] ?? 0 ),
							'version_id'  => (int) ( $record['version_id'] ?? 0 ),
							// 行動カラム（Phase 0で算出済み）
							'depth_position'         => (int) ( $beh['depth_position'] ?? 0 ),
							'deep_read'              => (int) ( $beh['deep_read'] ?? 0 ),
							'stop_max_sec'           => (int) ( $beh['stop_max_sec'] ?? 0 ),
							'stop_max_pos'           => (int) ( $beh['stop_max_pos'] ?? 0 ),
							'exit_pos'               => (int) ( $beh['exit_pos'] ?? 0 ),
							'is_submit'              => (int) ( $beh['is_submit'] ?? 0 ),
							'dead_click_image_count' => (int) ( $beh['dead_click_image_count'] ?? 0 ),
							'irritation_click_count' => (int) ( $beh['irritation_click_count'] ?? 0 ),
							'scroll_back_count'      => (int) ( $beh['scroll_back_count'] ?? 0 ),
							'content_skip_count'     => (int) ( $beh['content_skip_count'] ?? 0 ),
							'exploration_count'      => (int) ( $beh['exploration_count'] ?? 0 ),
							'prev_page_id'           => 0,
							'next_page_id'           => 0,
						);

						$session_seq[ $session_id ][] = $i;
					}

					// Phase 0メモリ解放
					unset( $behavioral_map );

					// ========================================
					// パス2: prev_page_id / next_page_id 算出 + 一括書き込み
					// ========================================
					foreach ( $session_seq as $indices ) {
						$count = count( $indices );
						for ( $j = 0; $j < $count; $j++ ) {
							$idx = $indices[ $j ];
							if ( $j > 0 ) {
								$rows[ $idx ]['prev_page_id'] = $rows[ $indices[ $j - 1 ] ]['page_id'];
							}
							if ( $j < $count - 1 ) {
								$rows[ $idx ]['next_page_id'] = $rows[ $indices[ $j + 1 ] ]['page_id'];
							}
						}
					}
					unset( $session_seq );

					foreach ( $rows as $row ) {
						$result = $writer->write_row( $row, $date_ymd );
						if ( $result ) {
							$processed++;
						}
					}
					unset( $rows );

					// ファイナライズ
					$sessions->close();
					$writer->finalize();

					if ( $qahm_log ) {
						$qahm_log->debug( 'ColumnDB allpv converted: ' . $tracking_id . ' / ' . $date_ymd . ' (' . $processed . ' records)' );
					}
				}
			}
		}

		// allpvマップ構築: pv_id → session_id, page_id（Phase C, D共通で使用）
		$allpv_pv_file      = $allpv_dir . $year_month . '/allpv_' . $date_ymd . '_pv_id.php';
		$allpv_session_file = $allpv_dir . $year_month . '/allpv_' . $date_ymd . '_session_id.php';
		$allpv_page_file    = $allpv_dir . $year_month . '/allpv_' . $date_ymd . '_page_id.php';

		$allpv_pv_ids      = QAHM_ColumnDB_BinaryIO::read_uint32_array( $allpv_pv_file );
		$allpv_session_ids = QAHM_ColumnDB_BinaryIO::read_uint32_array( $allpv_session_file );
		$allpv_page_ids    = QAHM_ColumnDB_BinaryIO::read_uint32_array( $allpv_page_file );

		$pv_to_session = null;
		$pv_to_page    = null;

		if ( $allpv_pv_ids !== false && $allpv_session_ids !== false && $allpv_page_ids !== false ) {
			$pv_to_session = array_combine( $allpv_pv_ids, $allpv_session_ids );
			$pv_to_page    = array_combine( $allpv_pv_ids, $allpv_page_ids );
		}

		// ========================================
		// Phase C: click_event列DB変換
		// rawcファイル（Phase Bマージ済み）→ 14カラムバイナリ列DB
		// 設計書 §5.4, 付録B 参照
		// ========================================
		$click_dir   = $report_dir . 'click_event/';
		$click_check = $click_dir . $year_month . '/click_event_' . $date_ymd . '_pv_id.php';
		$click_processed = 0;

		// T68: tracking_id='all' は個別サイトの click_event 列DBを統合する（raw_c 再解析しない）
		if ( $tracking_id === 'all' ) {
			$click_processed = $this->merge_click_event_for_all( $date_ymd, $click_dir );
			// datalayer_event の all 統合も同時に行って convert_one_date を終了させる
			$dl_processed_all = $this->merge_datalayer_event_for_all( $date_ymd, $report_dir );
			return $processed > 0 || $click_processed > 0 || $dl_processed_all > 0;
		}

		// click_eventが未処理 かつ rawcファイルあり かつ allpvマップ利用可能な場合のみ処理
		$rawc_dir   = $view_dir . 'raw_c/';
		$rawc_files = glob( $rawc_dir . $date_hyphen . '_*_rawc.php' );

		if ( file_exists( $click_check ) ) {
			// 処理済み — スキップ
		} elseif ( empty( $rawc_files ) ) {
			// rawcファイルなし — スキップ
		} elseif ( $pv_to_session === null ) {
			if ( $qahm_log ) {
				$qahm_log->debug( 'ColumnDB click_event: allpv data not available for ' . $date_ymd );
			}
			QAHM_ColumnDB_BinaryIO::write_file( $click_check, '' );
		} else {
			// 6つの属性辞書（click_event/ 配下）
			// ※ Selectorsは不要: gXX→intはsubstrで直接変換（辞書はPhase Aで使用済み）
			$dict_element_texts   = new QAHM_ColumnDB_Dictionary( $click_dir . 'dict-element-texts.php' );
			$dict_element_ids     = new QAHM_ColumnDB_Dictionary( $click_dir . 'dict-element-ids.php' );
			$dict_element_classes = new QAHM_ColumnDB_Dictionary( $click_dir . 'dict-element-classes.php' );
			$dict_element_data    = new QAHM_ColumnDB_Dictionary( $click_dir . 'dict-element-data-attrs.php' );
			$dict_urls            = new QAHM_ColumnDB_Dictionary( $click_dir . 'dict-urls.php' );

			// Writer初期化
			$click_writer = new QAHM_ColumnDB_Writer( 'click_event', $tracking_id, $click_dir );

			// サイトドメインプレフィックス生成（内部/外部遷移判定用）
			$domain_https = 'https://' . $domain;
			$domain_http  = 'http://' . $domain;

			// rawcファイルを処理
			foreach ( $rawc_files as $rawc_file ) {
				$rawc_slz  = $this->wrap_get_contents( $rawc_file );
				$rawc_data = $this->wrap_unserialize( $rawc_slz );

				if ( ! is_array( $rawc_data ) ) {
					continue;
				}

				foreach ( $rawc_data as $pv_entry ) {
					$pv_id = (int) ( $pv_entry['pv_id'] ?? 0 );
					$raw_c = $pv_entry['raw_c'] ?? '';

					if ( empty( $raw_c ) || $pv_id === 0 ) {
						continue;
					}

					// allpvから session_id, page_id を逆引き
					$session_id = $pv_to_session[ $pv_id ] ?? 0;
					$page_id    = $pv_to_page[ $pv_id ] ?? 0;

					// TSV行をパース（先頭のheader行をスキップ）
					$lines = explode( "\n", $raw_c );
					foreach ( $lines as $line_idx => $line ) {
						if ( $line_idx === 0 || trim( $line ) === '' ) {
							continue;
						}

						$fields = explode( "\t", $line );
						if ( count( $fields ) < 12 ) {
							continue;
						}

						// field[0]: gXX → selector_id (int)
						$selector_str = $fields[0];
						$selector_id  = 0;
						if ( strpos( $selector_str, 'g' ) === 0 ) {
							$selector_id = (int) substr( $selector_str, 1 );
						}

						// field[3]: transition → 統合辞書 + 内部/外部フラグ
						$transition  = $fields[3] ?? '';
						$to_url_id   = 0;
						$is_external = 0;
						if ( ! empty( $transition ) ) {
							$to_url_id = min( $dict_urls->get_or_create( $transition ), 65535 );
							if ( strpos( $transition, '/' ) === 0 || ( $domain !== '' && ( strpos( $transition, $domain_https ) === 0 || strpos( $transition, $domain_http ) === 0 ) ) ) {
								$is_external = 0;
							} else {
								$is_external = 1;
							}
						}

						// field[5-8]: 各属性を辞書IDに変換（空文字→0）
						$element_text_id  = min( $dict_element_texts->get_or_create( $fields[5] ?? '' ), 65535 );
						$element_id_id    = min( $dict_element_ids->get_or_create( $fields[6] ?? '' ), 65535 );
						$element_class_id = min( $dict_element_classes->get_or_create( $fields[7] ?? '' ), 65535 );
						$element_data_id  = min( $dict_element_data->get_or_create( $fields[8] ?? '' ), 65535 );

						// field[4,9]: そのままint変換
						$event_sec = min( (int) ( $fields[4] ?? 0 ), 65535 );
						$action_id = min( (int) ( $fields[9] ?? 0 ), 255 );

						// field[10,11]: ×10して精度変換（0-100 → 0-1000）
						$page_x_pct = min( (int) ( $fields[10] ?? 0 ) * 10, 65535 );
						$page_y_pct = min( (int) ( $fields[11] ?? 0 ) * 10, 65535 );

						$row = array(
							'pv_id'            => $pv_id,
							'session_id'       => $session_id,
							'page_id'          => $page_id,
							'event_sec'        => $event_sec,
							'selector_id'      => $selector_id,
							'element_text_id'  => $element_text_id,
							'element_id_id'    => $element_id_id,
							'element_class_id' => $element_class_id,
							'element_data_id'  => $element_data_id,
							'to_url_id'        => $to_url_id,
							'is_external'      => $is_external,
							'action_id'        => $action_id,
							'page_x_pct'       => $page_x_pct,
							'page_y_pct'       => $page_y_pct,
						);

						$click_writer->write_row( $row, $date_ymd );
						$click_processed++;
					}
				}
			}

			// 全辞書 + Writerファイナライズ
			$dict_element_texts->close();
			$dict_element_ids->close();
			$dict_element_classes->close();
			$dict_element_data->close();
			$dict_urls->close();
			$click_writer->finalize();

			// 有効行0件の場合、空マーカーファイルを書いて再処理を防止
			if ( $click_processed === 0 && ! file_exists( $click_check ) ) {
				QAHM_ColumnDB_BinaryIO::write_file( $click_check, '' );
			}

			if ( $qahm_log ) {
				$qahm_log->debug( 'ColumnDB click_event converted: ' . $tracking_id . ' / ' . $date_ymd . ' (' . $click_processed . ' events)' );
			}
		}

		// ========================================
		// Phase D: datalayer_event列DB変換 (Layer 1)
		// rawgファイル → 5カラムバイナリ列DB
		// 設計書 04-4-column-db-datalayer.md 参照
		// ========================================
		$dl_dir    = $report_dir . 'datalayer_event/';
		$dl_check  = $dl_dir . $year_month . '/datalayer_event_' . $date_ymd . '_pv_id.php';

		// datalayer_eventが既に存在すればスキップ
		if ( file_exists( $dl_check ) ) {
			return $processed > 0 || $click_processed > 0;
		}

		// rawgファイルを検索
		$rawg_dir   = $view_dir . 'raw_g/';
		$rawg_files = glob( $rawg_dir . $date_hyphen . '_*_rawg.php' );
		if ( empty( $rawg_files ) ) {
			return $processed > 0 || $click_processed > 0;
		}

		// allpvマップが利用不可の場合（Phase Cでも構築できなかった場合）
		if ( $pv_to_session === null ) {
			if ( $qahm_log ) {
				$qahm_log->info( 'ColumnDB datalayer_event: allpv data not available for ' . $date_ymd );
			}
			QAHM_ColumnDB_BinaryIO::write_file( $dl_check, '' );
			return $processed > 0 || $click_processed > 0;
		}

		// 2つの属性辞書（datalayer_event/ 配下）
		$dict_event_names = new QAHM_ColumnDB_Dictionary( $dl_dir . 'dict-event-names.php' );
		$dict_params_json = new QAHM_ColumnDB_Dictionary( $dl_dir . 'dict-params-json.php' );

		// Writer初期化
		$dl_writer = new QAHM_ColumnDB_Writer( 'datalayer_event', $tracking_id, $dl_dir );

		$dl_processed = 0;

		// --- Layer 2: イベント別テーブル初期化 ---
		$events_dir      = $report_dir . 'events/';
		$event_manifests = array();   // event_dir_name => manifest配列
		$event_tables    = array();   // event_dir_name => {columns, rows}

		// rawgファイルを処理
		foreach ( $rawg_files as $rawg_file ) {
			$rawg_slz  = $this->wrap_get_contents( $rawg_file );
			$rawg_data = $this->wrap_unserialize( $rawg_slz );

			if ( ! is_array( $rawg_data ) ) {
				continue;
			}

			foreach ( $rawg_data as $pv_entry ) {
				$pv_id = (int) ( $pv_entry['pv_id'] ?? 0 );
				$raw_g = $pv_entry['raw_g'] ?? '';

				if ( empty( $raw_g ) || $pv_id === 0 ) {
					continue;
				}

				// allpvから session_id, page_id を逆引き
				$session_id = $pv_to_session[ $pv_id ] ?? 0;
				$page_id    = $pv_to_page[ $pv_id ] ?? 0;

				// raw_gはTSV形式: 行0=ヘッダー(version), 行1+=イベントデータ(event_name\tparams_json)
				$lines = explode( "\n", $raw_g );
				foreach ( $lines as $line_idx => $line ) {
					// ヘッダー行と空行をスキップ
					if ( $line_idx === 0 || trim( $line ) === '' ) {
						continue;
					}

					$fields = explode( "\t", $line );
					if ( count( $fields ) < 2 ) {
						continue;
					}

					$event_name = $fields[0] ?? '';
					$params_json = $fields[1] ?? '';

					if ( empty( $event_name ) ) {
						continue;
					}

					$row = array(
						'pv_id'         => $pv_id,
						'session_id'    => $session_id,
						'page_id'       => $page_id,
						'event_name_id' => min( $dict_event_names->get_or_create( $event_name ), 65535 ),
						'params_id'     => min( $dict_params_json->get_or_create( $params_json ), 65535 ),
					);

					$dl_writer->write_row( $row, $date_ymd );
					$dl_processed++;

					// --- Layer 2: イベント別テーブル蓄積 ---
					$params = json_decode( $params_json, true );
					if ( ! is_array( $params ) || empty( $params ) ) {
						continue; // パラメーターなしイベントはLayer 2スキップ
					}

					$ev_dir_name = $this->sanitize_event_dir( $event_name );

					// イベント初出現時: manifest読み込み
					if ( ! isset( $event_manifests[ $ev_dir_name ] ) ) {
						$manifest_path = $events_dir . $ev_dir_name . '/manifest.json.php';
						$manifest      = $this->load_json_php( $manifest_path );
						if ( empty( $manifest ) ) {
							$manifest = array(
								'display_name' => $event_name,
								'columns'      => array(
									'pv_id'      => array( 'type' => 'num' ),
									'session_id' => array( 'type' => 'num' ),
								),
							);
						}
						$event_manifests[ $ev_dir_name ] = $manifest;
						$event_tables[ $ev_dir_name ]    = array(
							'columns' => array_keys( $manifest['columns'] ),
							'rows'    => array(),
						);
					}

					// 新規パラメーターキー検出 → manifest columns追加
					foreach ( $params as $key => $val ) {
						if ( ! isset( $event_manifests[ $ev_dir_name ]['columns'][ $key ] ) ) {
							$type = $this->is_dl_numeric( $val ) ? 'num' : 'string';
							$event_manifests[ $ev_dir_name ]['columns'][ $key ] = array( 'type' => $type );
							$event_tables[ $ev_dir_name ]['columns'][]          = $key;
						}
					}

					// 行構築: columns順に値を格納
					$l2_row = array();
					foreach ( $event_tables[ $ev_dir_name ]['columns'] as $col ) {
						if ( $col === 'pv_id' ) {
							$l2_row[] = $pv_id;
						} elseif ( $col === 'session_id' ) {
							$l2_row[] = $session_id;
						} else {
							$val = isset( $params[ $col ] ) ? $params[ $col ] : null;
							if ( is_array( $val ) ) {
								$l2_row[] = wp_json_encode( $val, JSON_UNESCAPED_UNICODE );
							} elseif ( $event_manifests[ $ev_dir_name ]['columns'][ $col ]['type'] === 'num'
								&& $this->is_dl_numeric( $val ) ) {
								$l2_row[] = $this->to_num( $val );
							} else {
								$l2_row[] = $val;
							}
						}
					}
					$event_tables[ $ev_dir_name ]['rows'][] = $l2_row;
				}
			}
		}

		// --- Layer 2: イベント別テーブル保存（Layer 1 finalizeより先に保存する） ---
		// Layer 1 finalizeがスキップチェックファイルを生成するため、
		// Layer 2を先に保存しないとクラッシュ時にLayer 2が永久欠損する
		$l2_rows_total  = 0;
		$l2_tables_count = 0;

		// 50+イベント警告
		if ( count( $event_tables ) > 50 && $qahm_log ) {
			$qahm_log->info( 'ColumnDB Layer 2 warning: ' . count( $event_tables ) . ' unique events detected for ' . $date_ymd . ' (possible misconfiguration)' );
		}

		foreach ( $event_tables as $ev_dir_name => $table ) {
			if ( empty( $table['rows'] ) ) {
				continue;
			}

			$event_dir = $events_dir . $ev_dir_name . '/';
			$month_dir = $event_dir . $year_month . '/';
			if ( ! is_dir( $month_dir ) ) {
				wp_mkdir_p( $month_dir );
			}

			$filepath = $month_dir . $ev_dir_name . '_' . $date_ymd . '.php';
			$this->wrap_put_contents( $filepath, $this->wrap_serialize( $table ) );

			$l2_rows_total += count( $table['rows'] );
			$l2_tables_count++;
		}

		// manifest保存
		foreach ( $event_manifests as $ev_dir_name => $manifest ) {
			$event_dir = $events_dir . $ev_dir_name . '/';
			if ( ! is_dir( $event_dir ) ) {
				wp_mkdir_p( $event_dir );
			}
			$this->save_json_php( $event_dir . 'manifest.json.php', $manifest );
		}

		// 辞書 + Writerファイナライズ（スキップチェックファイル生成はここで行われる）
		$dict_event_names->close();
		$dict_params_json->close();
		$dl_writer->finalize();

		// 有効行0件の場合、空マーカーファイルを書いて再処理を防止
		if ( $dl_processed === 0 && ! file_exists( $dl_check ) ) {
			QAHM_ColumnDB_BinaryIO::write_file( $dl_check, '' );
		}

		if ( $qahm_log ) {
			$qahm_log->debug( 'ColumnDB datalayer_event converted: ' . $tracking_id . ' / ' . $date_ymd . ' (L1: ' . $dl_processed . ' events, L2: ' . $l2_rows_total . ' rows / ' . $l2_tables_count . ' tables)' );
		}

		return $processed > 0 || $click_processed > 0 || $dl_processed > 0;
	}

	// ========================================
	// Phase 0: 行動カラム算出ヘルパーメソッド群
	// ========================================

	/**
	 * Phase 0: raw_p/raw_c/raw_eから行動カラム11個を算出
	 *
	 * @param string $view_dir  view_pvディレクトリパス（末尾/付き）
	 * @param string $date_hyphen 日付（YYYY-MM-DD形式）
	 * @param array  $all_records allpvレコード配列（pv_id, page_id, device_id含む）
	 * @return array $behavioral_map[pv_id] = [11カラム連想配列]
	 */
	private function compute_behavioral_columns( $view_dir, $date_hyphen, $all_records ) {
		// Step 0-a: pv_id → (page_id, device_id) マップ構築
		$pv_info = array();
		foreach ( $all_records as $rec ) {
			$pid = (int) ( $rec['pv_id'] ?? 0 );
			if ( $pid > 0 ) {
				$pv_info[ $pid ] = array(
					'page_id'   => (int) ( $rec['page_id'] ?? 0 ),
					'device_id' => (int) ( $rec['device_id'] ?? 1 ),
				);
			}
		}

		// Step 0-b: raw_pを一括ロード → PV単位のraw_pデータ + page_height_map構築
		$rawp_dir   = $view_dir . 'raw_p/';
		$rawp_files = glob( $rawp_dir . $date_hyphen . '_*_rawp.php' );
		$pv_rawp    = array(); // pv_id => [[STAY_HEIGHT, STAY_TIME], ...]
		$page_height_map = array(); // page_id => [device_id => max_height]

		if ( ! empty( $rawp_files ) ) {
			foreach ( $rawp_files as $file ) {
				$slz  = $this->wrap_get_contents( $file );
				$data = $this->wrap_unserialize( $slz );
				if ( ! is_array( $data ) ) {
					continue;
				}
				foreach ( $data as $entry ) {
					$pv_id = (int) ( $entry['pv_id'] ?? 0 );
					$raw_p = $entry['raw_p'] ?? '';
					if ( $pv_id === 0 || $raw_p === '' ) {
						continue;
					}
					$rows = $this->parse_rawp_tsv( $raw_p );
					$pv_rawp[ $pv_id ] = $rows;

					// page_height_map更新: このPVの max(STAY_HEIGHT) * 100
					$info = $pv_info[ $pv_id ] ?? null;
					if ( $info !== null && ! empty( $rows ) ) {
						$max_h = 0;
						foreach ( $rows as $r ) {
							if ( $r[0] > $max_h ) {
								$max_h = $r[0];
							}
						}
						$height = $max_h * 100;
						$pg = $info['page_id'];
						$dv = $info['device_id'];
						if ( ! isset( $page_height_map[ $pg ][ $dv ] ) || $height > $page_height_map[ $pg ][ $dv ] ) {
							$page_height_map[ $pg ][ $dv ] = $height;
						}
					}
				}
			}
		}

		// Step 0-c: raw_c一括ロード
		$rawc_dir   = $view_dir . 'raw_c/';
		$rawc_files = glob( $rawc_dir . $date_hyphen . '_*_rawc.php' );
		$pv_rawc    = array(); // pv_id => [[fields...], ...]

		if ( ! empty( $rawc_files ) ) {
			foreach ( $rawc_files as $file ) {
				$slz  = $this->wrap_get_contents( $file );
				$data = $this->wrap_unserialize( $slz );
				if ( ! is_array( $data ) ) {
					continue;
				}
				foreach ( $data as $entry ) {
					$pv_id = (int) ( $entry['pv_id'] ?? 0 );
					$raw_c = $entry['raw_c'] ?? '';
					if ( $pv_id === 0 || $raw_c === '' ) {
						continue;
					}
					$pv_rawc[ $pv_id ] = $this->parse_rawc_tsv( $raw_c );
				}
			}
		}

		// Step 0-d: raw_e一括ロード
		$rawe_dir   = $view_dir . 'raw_e/';
		$rawe_files = glob( $rawe_dir . $date_hyphen . '_*_rawe.php' );
		$pv_rawe    = array(); // pv_id => [[TYPE, TIME_MS, X_or_SCROLL_Y, Y], ...]

		if ( ! empty( $rawe_files ) ) {
			foreach ( $rawe_files as $file ) {
				$slz  = $this->wrap_get_contents( $file );
				$data = $this->wrap_unserialize( $slz );
				if ( ! is_array( $data ) ) {
					continue;
				}
				foreach ( $data as $entry ) {
					$pv_id = (int) ( $entry['pv_id'] ?? 0 );
					$raw_e = $entry['raw_e'] ?? '';
					if ( $pv_id === 0 || $raw_e === '' ) {
						continue;
					}
					$pv_rawe[ $pv_id ] = $this->parse_rawe_tsv( $raw_e );
				}
			}
		}

		// Step 0-e: 全PVの行動カラムを算出
		$behavioral_map = array();
		foreach ( $pv_info as $pv_id => $info ) {
			$page_id   = $info['page_id'];
			$device_id = $info['device_id'];
			$page_height = $page_height_map[ $page_id ][ $device_id ] ?? 1000;
			if ( $page_height <= 0 ) {
				$page_height = 1000;
			}

			$rawp_rows = $pv_rawp[ $pv_id ] ?? array();
			$rawc_rows = $pv_rawc[ $pv_id ] ?? array();
			$rawe_rows = $pv_rawe[ $pv_id ] ?? array();

			// C-1: raw_p由来 5カラム
			$c1 = $this->calc_rawp_columns( $rawp_rows, $page_height );

			// C-2: raw_c由来 3カラム
			$c2 = $this->calc_rawc_columns( $rawc_rows );

			// C-3: raw_e由来 3カラム
			$c3 = $this->calc_rawe_columns( $rawe_rows );

			$behavioral_map[ $pv_id ] = $c1 + $c2 + $c3;
		}

		return $behavioral_map;
	}

	/**
	 * raw_p TSV文字列をパース
	 *
	 * 1行目はヘッダー（バージョン番号）。2行目以降が STAY_HEIGHT\tSTAY_TIME。
	 *
	 * @param string $raw_p TSV文字列
	 * @return array [[stay_height(int), stay_time(int)], ...]
	 */
	private function parse_rawp_tsv( $raw_p ) {
		$lines  = explode( "\n", $raw_p );
		$result = array();
		foreach ( $lines as $idx => $line ) {
			if ( $idx === 0 || trim( $line ) === '' ) {
				continue;
			}
			$fields = explode( "\t", $line );
			if ( ! isset( $fields[0] ) || $fields[0] === 'a' ) {
				continue; // 'a' はアクティブマーカー、スキップ
			}
			$stay_height = (int) $fields[0];
			$stay_time   = isset( $fields[1] ) ? (int) $fields[1] : 0;
			$result[]    = array( $stay_height, $stay_time );
		}
		return $result;
	}

	/**
	 * raw_c TSV文字列をパース
	 *
	 * 1行目はヘッダー。2行目以降がクリックイベント。
	 * DATA_CLICK_2形式: SELECTOR_NAME(0), SELECTOR_X(1), SELECTOR_Y(2), TRANSITION(3),
	 *                   EVENT_SEC(4), ELEMENT_TEXT(5), ELEMENT_ID(6), ELEMENT_CLASS(7),
	 *                   ELEMENT_DATA_ATTR(8), ACTION_ID(9), PAGE_X_PCT(10), PAGE_Y_PCT(11)
	 *
	 * @param string $raw_c TSV文字列
	 * @return array 各行のフィールド配列
	 */
	private function parse_rawc_tsv( $raw_c ) {
		$lines  = explode( "\n", $raw_c );
		$result = array();
		foreach ( $lines as $idx => $line ) {
			if ( $idx === 0 || trim( $line ) === '' ) {
				continue;
			}
			$fields = explode( "\t", $line );
			$result[] = $fields;
		}
		return $result;
	}

	/**
	 * raw_e TSV文字列をパース
	 *
	 * 1行目はヘッダー: version\tWINDOW_INNER_W\tWINDOW_INNER_H
	 * 2行目以降: TYPE(0)\tTIME(1)\tX_or_SCROLL_Y(2)\tY(3)
	 *
	 * @param string $raw_e TSV文字列
	 * @return array 各行のフィールド配列（ヘッダー除外）
	 */
	private function parse_rawe_tsv( $raw_e ) {
		$lines  = explode( "\n", $raw_e );
		$result = array();
		foreach ( $lines as $idx => $line ) {
			if ( $idx === 0 || trim( $line ) === '' ) {
				continue;
			}
			$fields = explode( "\t", $line );
			$result[] = $fields;
		}
		return $result;
	}

	/**
	 * C-1: raw_p由来の5カラムを算出
	 *
	 * @param array $rows [[stay_height, stay_time], ...] パース済みraw_p
	 * @param int   $page_height ページ推定高さ（page_height_map値）
	 * @return array 5カラム連想配列
	 */
	private function calc_rawp_columns( $rows, $page_height ) {
		$result = array(
			'depth_position' => 0,
			'deep_read'      => 0,
			'stop_max_sec'   => 0,
			'stop_max_pos'   => 0,
			'exit_pos'       => 0,
		);

		if ( empty( $rows ) ) {
			return $result;
		}

		$max_height      = 0;
		$deep_read_count = 0;
		$stop_max_sec    = 0;
		$stop_max_height = 0;
		$last_height     = 0;

		foreach ( $rows as $r ) {
			$h = $r[0]; // STAY_HEIGHT
			$t = $r[1]; // STAY_TIME

			if ( $h > $max_height ) {
				$max_height = $h;
			}
			if ( $t >= 3 ) {
				$deep_read_count++;
			}
			if ( $t > $stop_max_sec ) {
				$stop_max_sec    = $t;
				$stop_max_height = $h;
			}
			$last_height = $h;
		}

		$result['depth_position'] = min( (int) ( $max_height * 100 * 100 / $page_height ), 100 );
		$result['deep_read']      = ( $deep_read_count >= 5 ) ? 1 : 0;
		$result['stop_max_sec']   = min( $stop_max_sec, 65535 );
		$result['stop_max_pos']   = min( (int) ( $stop_max_height * 100 * 100 / $page_height ), 100 );
		$result['exit_pos']       = min( (int) ( $last_height * 100 * 100 / $page_height ), 100 );

		return $result;
	}

	/**
	 * C-2: raw_c由来の3カラムを算出
	 *
	 * @param array $rows パース済みraw_c行（各行はフィールド配列）
	 * @return array 3カラム連想配列
	 */
	private function calc_rawc_columns( $rows ) {
		$result = array(
			'is_submit'              => 0,
			'dead_click_image_count' => 0,
			'irritation_click_count' => 0,
		);

		if ( empty( $rows ) ) {
			return $result;
		}

		$event_secs = array();

		foreach ( $rows as $fields ) {
			// is_submit: ACTION_ID(index 9) == 2
			$action_id = (int) ( $fields[9] ?? 0 );
			if ( $action_id === 2 ) {
				$result['is_submit'] = 1;
			}

			// dead_click_image: TRANSITION(3)が空 かつ SELECTOR_NAME(0)にimg判定
			$transition    = $fields[3] ?? '';
			$selector_name = $fields[0] ?? '';
			if ( $transition === '' && $selector_name !== '' ) {
				// gXX形式セレクタにimg/IMG等が含まれるかをチェック
				// SELECTOR_NAMEはgXX形式だが、ELEMENT_ID(6)やELEMENT_CLASS(7)にimg関連があるかも確認
				// 簡易方式: ELEMENT_DATA_ATTR(8)やELEMENT_CLASS(7)にimgが含まれるか、
				// またはSELECTOR_NAME自体を見る（gXXは数値IDなので直接判定不可）
				// ここではELEMENT_TEXT(5)が空で、ELEMENT_ID(6)やELEMENT_CLASS(7)から推定
				// → 最も確実なのはELEMENT_CLASS(7)にimgが含まれるかstrpos判定
				$element_class = $fields[7] ?? '';
				$element_id    = $fields[6] ?? '';
				if ( strpos( $element_class, 'img' ) !== false
					|| strpos( $element_class, 'IMG' ) !== false
					|| strpos( $element_class, 'image' ) !== false
					|| strpos( $element_class, 'Image' ) !== false
					|| strpos( $element_id, 'img' ) !== false
					|| strpos( $element_id, 'image' ) !== false ) {
					$result['dead_click_image_count'] = min( $result['dead_click_image_count'] + 1, 255 );
				}
			}

			// irritation_click用: EVENT_SEC収集（DATA_CLICK_2のみ）
			if ( isset( $fields[4] ) && $fields[4] !== '' ) {
				$event_secs[] = (int) $fields[4];
			}
		}

		// irritation_click_count: 3秒ウィンドウで5回以上のバースト検出
		if ( count( $event_secs ) >= 5 ) {
			sort( $event_secs );
			$burst_count = 0;
			$len         = count( $event_secs );
			$start       = 0;

			for ( $end = 0; $end < $len; $end++ ) {
				// ウィンドウ先頭を進める
				while ( $event_secs[ $end ] - $event_secs[ $start ] > 3 ) {
					$start++;
				}
				// ウィンドウ内のクリック数
				if ( ( $end - $start + 1 ) >= 5 ) {
					$burst_count++;
					// このバーストを消費: startをend+1に進める
					$start = $end + 1;
				}
			}
			$result['irritation_click_count'] = min( $burst_count, 255 );
		}

		return $result;
	}

	/**
	 * C-3: raw_e由来の3カラムを算出
	 *
	 * @param array $rows パース済みraw_e行（各行はフィールド配列）
	 * @return array 3カラム連想配列
	 */
	private function calc_rawe_columns( $rows ) {
		$result = array(
			'scroll_back_count'  => 0,
			'content_skip_count' => 0,
			'exploration_count'  => 0,
		);

		if ( empty( $rows ) ) {
			return $result;
		}

		// スクロールイベントと mousemoveイベントを分離
		$scroll_events = array(); // [time_sec, scroll_y]
		$mouse_events  = array(); // [time_ms, mouse_x]

		foreach ( $rows as $fields ) {
			$type = $fields[0] ?? '';
			$time_ms = (int) ( $fields[1] ?? 0 );

			if ( $type === 's' ) {
				$scroll_y = (int) ( $fields[2] ?? 0 );
				$scroll_events[] = array( $time_ms, $scroll_y );
			} elseif ( $type === 'm' ) {
				$mouse_x = (int) ( $fields[2] ?? 0 );
				$mouse_events[] = array( $time_ms, $mouse_x );
			}
		}

		// scroll_back_count / content_skip_count: 3秒(3000ms)以内にSCROLL_Yが1000px以上変化
		$scroll_len = count( $scroll_events );
		if ( $scroll_len >= 2 ) {
			$scroll_back_count  = 0;
			$content_skip_count = 0;

			for ( $i = 1; $i < $scroll_len; $i++ ) {
				$dt = $scroll_events[ $i ][0] - $scroll_events[ $i - 1 ][0]; // ms差分
				$dy = $scroll_events[ $i ][1] - $scroll_events[ $i - 1 ][1]; // Y差分

				if ( $dt > 0 && $dt <= 3000 ) {
					if ( $dy <= -1000 ) {
						$scroll_back_count++;
					} elseif ( $dy >= 1000 ) {
						$content_skip_count++;
					}
				}
			}
			$result['scroll_back_count']  = min( $scroll_back_count, 255 );
			$result['content_skip_count'] = min( $content_skip_count, 255 );
		}

		// exploration_count: 5秒(5000ms)以内にmousemoveで200px以上横移動が2回以上折り返し
		$mouse_len = count( $mouse_events );
		if ( $mouse_len >= 3 ) {
			$exploration_count = 0;
			$window_start      = 0;

			for ( $i = 1; $i < $mouse_len; $i++ ) {
				// 5秒ウィンドウ先頭を進める
				while ( $window_start < $i && ( $mouse_events[ $i ][0] - $mouse_events[ $window_start ][0] ) > 5000 ) {
					$window_start++;
				}

				// ウィンドウ内で折り返し回数を数える
				$reversals = 0;
				$prev_dir  = 0; // 1=right, -1=left

				for ( $j = $window_start + 1; $j <= $i; $j++ ) {
					$dx = $mouse_events[ $j ][1] - $mouse_events[ $j - 1 ][1];
					if ( abs( $dx ) >= 200 ) {
						$dir = ( $dx > 0 ) ? 1 : -1;
						if ( $prev_dir !== 0 && $dir !== $prev_dir ) {
							$reversals++;
						}
						$prev_dir = $dir;
					}
				}

				if ( $reversals >= 2 ) {
					$exploration_count++;
					// このパターンを消費: ウィンドウを次に進める
					$window_start = $i;
				}
			}
			$result['exploration_count'] = min( $exploration_count, 255 );
		}

		return $result;
	}

	/**
	 * データレイヤー値のnum判定（preg_match禁止・文字比較のみ）
	 *
	 * 半角数字とカンマのみで構成され、先頭が0でない値をnumとする。
	 * 符号(+/-)、小数点(.)も許容。
	 *
	 * @param mixed $val 判定対象
	 * @return bool numならtrue
	 */
	private function is_dl_numeric( $val ) {
		if ( ! is_string( $val ) && ! is_int( $val ) && ! is_float( $val ) ) {
			return false;
		}
		$s   = (string) $val;
		$len = strlen( $s );
		if ( $len === 0 ) {
			return false;
		}

		// カンマを除去
		$s   = str_replace( ',', '', $s );
		$len = strlen( $s );
		if ( $len === 0 ) {
			return false;
		}

		// 符号チェック
		$start = 0;
		if ( $s[0] === '-' || $s[0] === '+' ) {
			if ( $len === 1 ) {
				return false;
			}
			$start = 1;
		}

		// 先頭が0で2文字以上 → 電話番号等（"0120", "+0120"）なのでstring
		if ( $s[ $start ] === '0' && ( $len - $start ) > 1 && $s[ $start + 1 ] !== '.' ) {
			return false;
		}

		// 残りが数字とドット（1個まで）のみか（数字が1つもなければfalse）
		$dot_count   = 0;
		$digit_found = false;
		for ( $i = $start; $i < $len; $i++ ) {
			$c = $s[ $i ];
			if ( $c === '.' ) {
				$dot_count++;
				if ( $dot_count > 1 ) {
					return false;
				}
			} elseif ( $c < '0' || $c > '9' ) {
				return false;
			} else {
				$digit_found = true;
			}
		}
		return $digit_found;
	}

	/**
	 * num値をカンマ除去してint/floatにキャスト
	 *
	 * @param mixed $val 変換対象（is_dl_numeric()がtrueの値を想定）
	 * @return int|float
	 */
	private function to_num( $val ) {
		$s = str_replace( ',', '', (string) $val );
		return strpos( $s, '.' ) !== false ? (float) $s : (int) $s;
	}

	/**
	 * JSON.phpファイルを読み込み
	 *
	 * PHPセキュリティヘッダー除去はwrap_get_contentsが自動処理。
	 * ファイル未存在なら空配列を返す。
	 *
	 * @param string $path ファイルパス
	 * @return array デコード済み配列
	 */
	private function load_json_php( $path ) {
		if ( ! file_exists( $path ) ) {
			return array();
		}
		$raw = $this->wrap_get_contents( $path );
		if ( $raw === false ) {
			return array();
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * JSON.phpファイルを保存
	 *
	 * wrap_put_contentsがPHPセキュリティヘッダーを自動付与。
	 *
	 * @param string $path ファイルパス
	 * @param array  $data 保存データ
	 */
	private function save_json_php( $path, $data ) {
		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		$this->wrap_put_contents( $path, $json );
	}

	/**
	 * イベント名をディレクトリ名にサニタイズ
	 *
	 * FS禁止文字を_に置換。空文字なら_empty_を返す。
	 *
	 * @param string $event_name イベント名
	 * @return string ディレクトリ名
	 */
	private function sanitize_event_dir( $event_name ) {
		if ( $event_name === '' ) {
			return '_empty_';
		}
		$name = str_replace(
			array( '/', "\0", '\\', ':', '*', '?', '"', '<', '>', '|' ),
			'_',
			$event_name
		);
		// ディレクトリトラバーサル防止（. や .. が親ディレクトリに解決される）
		if ( $name === '.' || $name === '..' ) {
			return '_' . $name . '_';
		}
		return $name;
	}

	// ============================================================
	// T68: tracking_id='all' 用の統合ヘルパー群
	// ============================================================

	/**
	 * T68: individual sites の click_event 列DBを 'all' 配下に統合
	 *
	 * 6辞書（selectors, element_texts, element_ids, element_classes, element_data, urls）を
	 * all 側で新規作成し、個別サイトの古いIDを文字列経由で all 側の新規IDに再採番する。
	 *
	 * @param string $date_ymd 日付（YYYYMMDD）
	 * @param string $all_click_dir report/all/columns-db/click_event/ の絶対パス
	 * @return int 統合行数
	 */
	private function merge_click_event_for_all( $date_ymd, $all_click_dir ) {
		global $qahm_data_api, $qahm_log;

		$year_month  = substr( $date_ymd, 0, 6 );
		$all_check   = $all_click_dir . $year_month . '/click_event_' . $date_ymd . '_pv_id.php';

		// 既に処理済みなら何もしない
		if ( file_exists( $all_check ) ) {
			return 0;
		}

		$data_dir = $this->get_data_dir_path();
		$siteary  = $qahm_data_api->get_sitemanage();
		if ( empty( $siteary ) ) {
			return 0;
		}

		// T68: all 側 allpv 列DBから pv_id → session_id / page_id マップを構築。
		// session_id は tracking_id 単位で独立採番されるため、merge 時には個別サイトの session_id を捨てて
		// all/allpv の番号で再解決する必要がある（page_id も同様）
		$all_allpv_dir         = dirname( rtrim( $all_click_dir, '/' ) ) . '/allpv/';
		$all_allpv_pv_file      = $all_allpv_dir . $year_month . '/allpv_' . $date_ymd . '_pv_id.php';
		$all_allpv_session_file = $all_allpv_dir . $year_month . '/allpv_' . $date_ymd . '_session_id.php';
		$all_allpv_page_file    = $all_allpv_dir . $year_month . '/allpv_' . $date_ymd . '_page_id.php';

		$all_allpv_pv_ids      = QAHM_ColumnDB_BinaryIO::read_uint32_array( $all_allpv_pv_file );
		$all_allpv_session_ids = QAHM_ColumnDB_BinaryIO::read_uint32_array( $all_allpv_session_file );
		$all_allpv_page_ids    = QAHM_ColumnDB_BinaryIO::read_uint32_array( $all_allpv_page_file );

		if ( $all_allpv_pv_ids === false || $all_allpv_session_ids === false || $all_allpv_page_ids === false ) {
			if ( $qahm_log ) {
				$qahm_log->info( 'ColumnDB click_event (all): all/allpv data not available for ' . $date_ymd . ' — skip merge' );
			}
			QAHM_ColumnDB_BinaryIO::write_file( $all_check, '' );
			return 0;
		}
		$pv_to_session_all = array_combine( $all_allpv_pv_ids, $all_allpv_session_ids );
		$pv_to_page_all    = array_combine( $all_allpv_pv_ids, $all_allpv_page_ids );
		unset( $all_allpv_pv_ids, $all_allpv_session_ids, $all_allpv_page_ids );

		// all 側 6 辞書を初期化
		$all_selectors_dict = new QAHM_ColumnDB_Dictionary( $all_click_dir . 'global-selectors-dict.php' );
		$all_dict_texts     = new QAHM_ColumnDB_Dictionary( $all_click_dir . 'dict-element-texts.php' );
		$all_dict_ids       = new QAHM_ColumnDB_Dictionary( $all_click_dir . 'dict-element-ids.php' );
		$all_dict_classes   = new QAHM_ColumnDB_Dictionary( $all_click_dir . 'dict-element-classes.php' );
		$all_dict_data      = new QAHM_ColumnDB_Dictionary( $all_click_dir . 'dict-element-data-attrs.php' );
		$all_dict_urls      = new QAHM_ColumnDB_Dictionary( $all_click_dir . 'dict-urls.php' );

		$all_writer = new QAHM_ColumnDB_Writer( 'click_event', 'all', $all_click_dir );
		$total      = 0;

		foreach ( $siteary as $site ) {
			$tid        = $site['tracking_id'];
			$site_click = $data_dir . 'report/' . $tid . '/columns-db/click_event/';
			$pv_file    = $site_click . $year_month . '/click_event_' . $date_ymd . '_pv_id.php';

			if ( ! file_exists( $pv_file ) ) {
				continue; // 未処理 or データなし
			}

			// pv_id 列をロード（他のすべての列の長さと一致する前提）
			$pv_ids = QAHM_ColumnDB_BinaryIO::read_uint32_array( $pv_file );
			if ( $pv_ids === false || empty( $pv_ids ) ) {
				continue;
			}
			$row_count = count( $pv_ids );

			// 非辞書カラムを一括ロード（session_id / page_id は all/allpv 側で再解決するため読まない）
			$event_secs   = QAHM_ColumnDB_BinaryIO::read_uint16_array( $site_click . $year_month . '/click_event_' . $date_ymd . '_event_sec.php' );
			$is_externals = QAHM_ColumnDB_BinaryIO::read_uint8_array(  $site_click . $year_month . '/click_event_' . $date_ymd . '_is_external.php' );
			$action_ids   = QAHM_ColumnDB_BinaryIO::read_uint8_array(  $site_click . $year_month . '/click_event_' . $date_ymd . '_action_id.php' );
			$page_x_pcts  = QAHM_ColumnDB_BinaryIO::read_uint16_array( $site_click . $year_month . '/click_event_' . $date_ymd . '_page_x_pct.php' );
			$page_y_pcts  = QAHM_ColumnDB_BinaryIO::read_uint16_array( $site_click . $year_month . '/click_event_' . $date_ymd . '_page_y_pct.php' );

			// 辞書カラム: 古いID配列をロード
			$site_selector_ids = QAHM_ColumnDB_BinaryIO::read_uint32_array( $site_click . $year_month . '/click_event_' . $date_ymd . '_selector_id.php' );
			$site_text_ids     = QAHM_ColumnDB_BinaryIO::read_uint16_array( $site_click . $year_month . '/click_event_' . $date_ymd . '_element_text_id.php' );
			$site_id_ids       = QAHM_ColumnDB_BinaryIO::read_uint16_array( $site_click . $year_month . '/click_event_' . $date_ymd . '_element_id_id.php' );
			$site_class_ids    = QAHM_ColumnDB_BinaryIO::read_uint16_array( $site_click . $year_month . '/click_event_' . $date_ymd . '_element_class_id.php' );
			$site_data_ids     = QAHM_ColumnDB_BinaryIO::read_uint16_array( $site_click . $year_month . '/click_event_' . $date_ymd . '_element_data_id.php' );
			$site_to_url_ids   = QAHM_ColumnDB_BinaryIO::read_uint16_array( $site_click . $year_month . '/click_event_' . $date_ymd . '_to_url_id.php' );

			// 列ファイルが1つでも欠損していたらサイトごとスキップ（破損ガード／フォールバックは持たない）
			if ( $event_secs === false || $is_externals === false || $action_ids === false || $page_x_pcts === false || $page_y_pcts === false
				|| $site_selector_ids === false || $site_text_ids === false || $site_id_ids === false
				|| $site_class_ids === false || $site_data_ids === false || $site_to_url_ids === false ) {
				if ( $qahm_log ) {
					$qahm_log->info( 'ColumnDB click_event (all): site ' . $tid . ' has corrupted column file for ' . $date_ymd . ' — skip site' );
				}
				continue;
			}

			// 個別サイト辞書をロード → old_id → new_id マップ構築
			// selector_id は uint32 のため uint16 キャップ不要、それ以外は uint16 列なのでキャップ必須
			$site_selectors_path = $data_dir . 'view/' . $tid . '/global-selectors-dict.php';
			$map_selector = $this->build_dict_remap( $site_selectors_path, $all_selectors_dict, false );
			$map_text     = $this->build_dict_remap( $site_click . 'dict-element-texts.php', $all_dict_texts );
			$map_id       = $this->build_dict_remap( $site_click . 'dict-element-ids.php', $all_dict_ids );
			$map_class    = $this->build_dict_remap( $site_click . 'dict-element-classes.php', $all_dict_classes );
			$map_data     = $this->build_dict_remap( $site_click . 'dict-element-data-attrs.php', $all_dict_data );
			$map_url      = $this->build_dict_remap( $site_click . 'dict-urls.php', $all_dict_urls );

			// 行単位で Writer に追記
			// session_id / page_id は all/allpv の pv_id 逆引きで再解決（個別サイトの値は捨てる）
			for ( $i = 0; $i < $row_count; $i++ ) {
				$pv_id = (int) $pv_ids[ $i ];
				$row   = array(
					'pv_id'            => $pv_id,
					'session_id'       => $pv_to_session_all[ $pv_id ] ?? 0,
					'page_id'          => $pv_to_page_all[ $pv_id ] ?? 0,
					'event_sec'        => (int) $event_secs[ $i ],
					'selector_id'      => $map_selector[ (int) $site_selector_ids[ $i ] ] ?? 0,
					'element_text_id'  => $map_text[ (int) $site_text_ids[ $i ] ] ?? 0,
					'element_id_id'    => $map_id[ (int) $site_id_ids[ $i ] ] ?? 0,
					'element_class_id' => $map_class[ (int) $site_class_ids[ $i ] ] ?? 0,
					'element_data_id'  => $map_data[ (int) $site_data_ids[ $i ] ] ?? 0,
					'to_url_id'        => $map_url[ (int) $site_to_url_ids[ $i ] ] ?? 0,
					'is_external'      => (int) $is_externals[ $i ],
					'action_id'        => (int) $action_ids[ $i ],
					'page_x_pct'       => (int) $page_x_pcts[ $i ],
					'page_y_pct'       => (int) $page_y_pcts[ $i ],
				);
				$all_writer->write_row( $row, $date_ymd );
				$total++;
			}

			// サイト別の大容量配列を解放
			unset( $pv_ids, $event_secs, $is_externals, $action_ids, $page_x_pcts, $page_y_pcts );
			unset( $site_selector_ids, $site_text_ids, $site_id_ids, $site_class_ids, $site_data_ids, $site_to_url_ids );
			unset( $map_selector, $map_text, $map_id, $map_class, $map_data, $map_url );
		}

		$all_selectors_dict->close();
		$all_dict_texts->close();
		$all_dict_ids->close();
		$all_dict_classes->close();
		$all_dict_data->close();
		$all_dict_urls->close();
		$all_writer->finalize();

		if ( $total === 0 && ! file_exists( $all_check ) ) {
			QAHM_ColumnDB_BinaryIO::write_file( $all_check, '' );
		}

		if ( $qahm_log ) {
			$qahm_log->debug( 'ColumnDB click_event (all) merged: ' . $date_ymd . ' (' . $total . ' events)' );
		}

		return $total;
	}

	/**
	 * T68: individual sites の datalayer_event 列DB + Layer 2 を 'all' 配下に統合
	 *
	 * @param string $date_ymd 日付（YYYYMMDD）
	 * @param string $all_report_dir report/all/columns-db/ の絶対パス
	 * @return int Layer 1 統合行数
	 */
	private function merge_datalayer_event_for_all( $date_ymd, $all_report_dir ) {
		global $qahm_data_api, $qahm_log;

		$year_month = substr( $date_ymd, 0, 6 );
		$all_dl_dir = $all_report_dir . 'datalayer_event/';
		$all_check  = $all_dl_dir . $year_month . '/datalayer_event_' . $date_ymd . '_pv_id.php';

		if ( file_exists( $all_check ) ) {
			return 0;
		}

		$data_dir = $this->get_data_dir_path();
		$siteary  = $qahm_data_api->get_sitemanage();
		if ( empty( $siteary ) ) {
			return 0;
		}

		// T68: all 側 allpv 列DBから pv_id → session_id / page_id マップを構築（merge_click_event_for_all と同じ理由）
		$all_allpv_dir          = $all_report_dir . 'allpv/';
		$all_allpv_pv_file      = $all_allpv_dir . $year_month . '/allpv_' . $date_ymd . '_pv_id.php';
		$all_allpv_session_file = $all_allpv_dir . $year_month . '/allpv_' . $date_ymd . '_session_id.php';
		$all_allpv_page_file    = $all_allpv_dir . $year_month . '/allpv_' . $date_ymd . '_page_id.php';

		$all_allpv_pv_ids      = QAHM_ColumnDB_BinaryIO::read_uint32_array( $all_allpv_pv_file );
		$all_allpv_session_ids = QAHM_ColumnDB_BinaryIO::read_uint32_array( $all_allpv_session_file );
		$all_allpv_page_ids    = QAHM_ColumnDB_BinaryIO::read_uint32_array( $all_allpv_page_file );

		if ( $all_allpv_pv_ids === false || $all_allpv_session_ids === false || $all_allpv_page_ids === false ) {
			if ( $qahm_log ) {
				$qahm_log->info( 'ColumnDB datalayer_event (all): all/allpv data not available for ' . $date_ymd . ' — skip merge' );
			}
			QAHM_ColumnDB_BinaryIO::write_file( $all_check, '' );
			return 0;
		}
		$pv_to_session_all = array_combine( $all_allpv_pv_ids, $all_allpv_session_ids );
		$pv_to_page_all    = array_combine( $all_allpv_pv_ids, $all_allpv_page_ids );
		unset( $all_allpv_pv_ids, $all_allpv_session_ids, $all_allpv_page_ids );

		// Layer 1 辞書
		$all_dict_events = new QAHM_ColumnDB_Dictionary( $all_dl_dir . 'dict-event-names.php' );
		$all_dict_params = new QAHM_ColumnDB_Dictionary( $all_dl_dir . 'dict-params-json.php' );

		$all_writer = new QAHM_ColumnDB_Writer( 'datalayer_event', 'all', $all_dl_dir );
		$total      = 0;

		// Layer 2 統合用バッファ
		$all_events_dir    = $all_report_dir . 'events/';
		$merged_manifests  = array(); // ev_dir_name => manifest
		$merged_tables     = array(); // ev_dir_name => ['columns'=>[...], 'rows'=>[...]]

		foreach ( $siteary as $site ) {
			$tid        = $site['tracking_id'];
			$site_dl    = $data_dir . 'report/' . $tid . '/columns-db/datalayer_event/';
			$pv_file    = $site_dl . $year_month . '/datalayer_event_' . $date_ymd . '_pv_id.php';

			if ( ! file_exists( $pv_file ) ) {
				continue;
			}

			$pv_ids = QAHM_ColumnDB_BinaryIO::read_uint32_array( $pv_file );
			if ( $pv_ids === false || empty( $pv_ids ) ) {
				continue;
			}
			$row_count = count( $pv_ids );

			// session_id / page_id 列は all/allpv 側で再解決するため読まない
			$ev_ids     = QAHM_ColumnDB_BinaryIO::read_uint16_array( $site_dl . $year_month . '/datalayer_event_' . $date_ymd . '_event_name_id.php' );
			$params_ids = QAHM_ColumnDB_BinaryIO::read_uint16_array( $site_dl . $year_month . '/datalayer_event_' . $date_ymd . '_params_id.php' );

			if ( $ev_ids === false || $params_ids === false ) {
				if ( $qahm_log ) {
					$qahm_log->info( 'ColumnDB datalayer_event (all): site ' . $tid . ' has corrupted column file for ' . $date_ymd . ' — skip site' );
				}
				continue;
			}

			// uint16 列なのでキャップ必須
			$map_events = $this->build_dict_remap( $site_dl . 'dict-event-names.php', $all_dict_events );
			$map_params = $this->build_dict_remap( $site_dl . 'dict-params-json.php', $all_dict_params );

			for ( $i = 0; $i < $row_count; $i++ ) {
				$pv_id = (int) $pv_ids[ $i ];
				$row   = array(
					'pv_id'         => $pv_id,
					'session_id'    => $pv_to_session_all[ $pv_id ] ?? 0,
					'page_id'       => $pv_to_page_all[ $pv_id ] ?? 0,
					'event_name_id' => $map_events[ (int) $ev_ids[ $i ] ] ?? 0,
					'params_id'     => $map_params[ (int) $params_ids[ $i ] ] ?? 0,
				);
				$all_writer->write_row( $row, $date_ymd );
				$total++;
			}

			unset( $pv_ids, $ev_ids, $params_ids, $map_events, $map_params );

			// Layer 2: 個別サイトの events/{ev_dir}/{YYYYMM}/{ev_dir}_{date}.php を統合
			$site_events_dir = $data_dir . 'report/' . $tid . '/columns-db/events/';
			if ( is_dir( $site_events_dir ) ) {
				$ev_subdirs = glob( $site_events_dir . '*', GLOB_ONLYDIR );
				if ( $ev_subdirs ) {
					foreach ( $ev_subdirs as $ev_subdir ) {
						$ev_dir_name = basename( $ev_subdir );
						$day_file    = $ev_subdir . '/' . $year_month . '/' . $ev_dir_name . '_' . $date_ymd . '.php';
						if ( ! file_exists( $day_file ) ) {
							continue;
						}

						// manifest ロード
						$site_manifest = $this->load_json_php( $ev_subdir . '/manifest.json.php' );
						$day_slz       = $this->wrap_get_contents( $day_file );
						$day_table     = $this->wrap_unserialize( $day_slz );
						if ( ! is_array( $day_table ) || empty( $day_table['columns'] ) || empty( $day_table['rows'] ) ) {
							continue;
						}

						// all 側 manifest & table を初期化
						if ( ! isset( $merged_manifests[ $ev_dir_name ] ) ) {
							if ( is_array( $site_manifest ) && ! empty( $site_manifest['columns'] ) ) {
								$merged_manifests[ $ev_dir_name ] = $site_manifest;
							} else {
								$merged_manifests[ $ev_dir_name ] = array(
									'display_name' => $ev_dir_name,
									'columns'      => array(
										'pv_id'      => array( 'type' => 'num' ),
										'session_id' => array( 'type' => 'num' ),
									),
								);
							}
							$merged_tables[ $ev_dir_name ] = array(
								'columns' => array_keys( $merged_manifests[ $ev_dir_name ]['columns'] ),
								'rows'    => array(),
							);
						}

						// 新規カラムを追加（site_manifest 由来）
						if ( is_array( $site_manifest ) && ! empty( $site_manifest['columns'] ) ) {
							foreach ( $site_manifest['columns'] as $col => $meta ) {
								if ( ! isset( $merged_manifests[ $ev_dir_name ]['columns'][ $col ] ) ) {
									$merged_manifests[ $ev_dir_name ]['columns'][ $col ] = $meta;
									$merged_tables[ $ev_dir_name ]['columns'][]          = $col;
								}
							}
						}

						// 行をマージ。site の columns 順 → all の columns 順にマッピング
						$site_col_index = array_flip( $day_table['columns'] );
						$merged_cols    = $merged_tables[ $ev_dir_name ]['columns'];
						foreach ( $day_table['rows'] as $src_row ) {
							$new_row = array();
							foreach ( $merged_cols as $col ) {
								if ( isset( $site_col_index[ $col ] ) ) {
									$new_row[] = $src_row[ $site_col_index[ $col ] ] ?? null;
								} else {
									$new_row[] = null;
								}
							}
							$merged_tables[ $ev_dir_name ]['rows'][] = $new_row;
						}
					}
				}
			}
		}

		// Layer 2 保存
		foreach ( $merged_tables as $ev_dir_name => $table ) {
			if ( empty( $table['rows'] ) ) {
				continue;
			}
			$event_dir = $all_events_dir . $ev_dir_name . '/';
			$month_dir = $event_dir . $year_month . '/';
			if ( ! is_dir( $month_dir ) ) {
				wp_mkdir_p( $month_dir );
			}
			$filepath = $month_dir . $ev_dir_name . '_' . $date_ymd . '.php';
			$this->wrap_put_contents( $filepath, $this->wrap_serialize( $table ) );
		}

		foreach ( $merged_manifests as $ev_dir_name => $manifest ) {
			$event_dir = $all_events_dir . $ev_dir_name . '/';
			if ( ! is_dir( $event_dir ) ) {
				wp_mkdir_p( $event_dir );
			}
			$this->save_json_php( $event_dir . 'manifest.json.php', $manifest );
		}

		$all_dict_events->close();
		$all_dict_params->close();
		$all_writer->finalize();

		if ( $total === 0 && ! file_exists( $all_check ) ) {
			QAHM_ColumnDB_BinaryIO::write_file( $all_check, '' );
		}

		if ( $qahm_log ) {
			$qahm_log->debug( 'ColumnDB datalayer_event (all) merged: ' . $date_ymd . ' (' . $total . ' events)' );
		}

		return $total;
	}

	/**
	 * T68: 個別サイト辞書を読み込み、all 側辞書に文字列を再採番して old_id → new_id マップを返す
	 *
	 * @param string                  $site_dict_path 個別サイト辞書ファイルの絶対パス
	 * @param QAHM_ColumnDB_Dictionary $all_dict       all 側辞書（書き込み対象）
	 * @param bool                    $cap_uint16     true なら new_id を 65535 でクランプ（uint16 列向け、selector_id のみ false）
	 * @return array old_id => new_id マップ（空文字は 0 → 0）
	 */
	private function build_dict_remap( $site_dict_path, $all_dict, $cap_uint16 = true ) {
		$map = array( 0 => 0 );
		if ( ! file_exists( $site_dict_path ) ) {
			return $map;
		}
		$site_dict = new QAHM_ColumnDB_Dictionary( $site_dict_path );
		$entries   = $site_dict->get_all_entries();
		foreach ( $entries as $old_id => $str ) {
			$new_id = $all_dict->get_or_create( $str );
			if ( $cap_uint16 && $new_id > 65535 ) {
				$new_id = 65535;
			}
			$map[ (int) $old_id ] = $new_id;
		}
		return $map;
	}
}
