<?php
/**
 * GSC列DB 夜間バッチ変換クラス
 *
 * GSCデータファイル（_gsc_lp_query.php）を列DB（gsc）に変換する夜間バッチ処理。
 * 昨日分から処理を開始し、制限時間内で過去に遡って変換する。
 * 既存ColumnDB_Cronとは別クラス（走査ディレクトリ・データ構造が異なるため）。
 *
 * cronフロー:
 *   Night>Make column-db gsc>Start → Night>Make column-db gsc>Loop → Night>Make column-db gsc>End
 *
 * @package qa_heatmap
 */

class QAHM_ColumnDB_GscCron extends QAHM_Base {

	/**
	 * 制限時間（秒）
	 */
	const TIME_LIMIT_SEC = 300;

	/**
	 * メモファイル名
	 */
	const MEMO_FILE = 'cron_columndb_gsc_target_memo.php';

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

		// tracking_id一覧を取得
		$siteary = $qahm_data_api->get_sitemanage();
		$targets = array();

		if ( ! empty( $siteary ) ) {
			foreach ( $siteary as $site ) {
				$tid = $site['tracking_id'];
				$dates = $this->get_unprocessed_dates( $tid );
				foreach ( $dates as $date ) {
					$targets[] = array(
						'tracking_id' => $tid,
						'date'        => $date,
					);
				}
			}
		}

		if ( empty( $targets ) ) {
			if ( $qahm_log ) {
				$qahm_log->info( 'ColumnDB GSC cron: No unprocessed dates found' );
			}
			return 'Night>Make column-db gsc>End';
		}

		// 処理対象リストを保存（index=0から開始）
		$target_memo = array(
			'index'   => 0,
			'targets' => $targets,
		);
		$this->wrap_put_contents(
			$temp_dir . self::MEMO_FILE,
			$this->wrap_serialize( $target_memo )
		);

		if ( $qahm_log ) {
			$qahm_log->info( 'ColumnDB GSC cron: Start (' . count( $targets ) . ' date(s) to process)' );
		}

		return 'Night>Make column-db gsc>Loop';
	}

	/**
	 * cronステップ: Loop
	 *
	 * プロセス内ループで複数日付のGSCデータを列DBに変換する。
	 * TIME_LIMIT_SEC超過時は次のcronプロセスに委譲。
	 *
	 * @return string 次のcronステータス
	 */
	public function process_loop() {
		global $qahm_log;

		$temp_dir   = $this->get_data_dir_path( 'temp' );
		$loop_start = time();

		// target_memoを読み込み
		$memo_slz = $this->wrap_get_contents( $temp_dir . self::MEMO_FILE );
		$memo     = $this->wrap_unserialize( $memo_slz );

		if ( ! $memo || ! isset( $memo['targets'] ) || ! isset( $memo['index'] ) ) {
			return 'Night>Make column-db gsc>End';
		}

		$index   = $memo['index'];
		$targets = $memo['targets'];
		$count   = count( $targets );

		// プロセス内ループ: 時間制限内で複数日付を処理
		while ( $index < $count ) {
			$target      = $targets[ $index ];
			$tracking_id = $target['tracking_id'];
			$date        = $target['date'];

			$result = $this->convert_gsc_one_date( $tracking_id, $date );

			if ( ! $result && $qahm_log ) {
				$qahm_log->warning( 'ColumnDB GSC conversion failed: ' . $tracking_id . ' / ' . $date );
			}

			$index++;

			// インデックスを進めて保存（日付単位で中間保存）
			$memo['index'] = $index;
			$this->wrap_put_contents(
				$temp_dir . self::MEMO_FILE,
				$this->wrap_serialize( $memo )
			);

			// 時間制限チェック
			if ( ( time() - $loop_start ) > self::TIME_LIMIT_SEC ) {
				if ( $qahm_log ) {
					$qahm_log->info( 'ColumnDB GSC cron: Time limit reached (' . $index . '/' . $count . ')' );
				}
				return 'Night>Make column-db gsc>Loop';
			}
		}

		// 全処理完了
		if ( $qahm_log ) {
			$qahm_log->info( 'ColumnDB GSC cron: All dates processed (' . $count . ')' );
		}
		return 'Night>Make column-db gsc>End';
	}

	/**
	 * cronステップ: End
	 *
	 * 一時ファイルを削除し、次のcronフローに遷移する。
	 *
	 * @return string 次のcronステータス
	 */
	public function end() {
		$temp_dir = $this->get_data_dir_path( 'temp' );
		$this->wrap_delete( $temp_dir . self::MEMO_FILE );

		return 'Night>Make Goal file>Start';
	}

	/**
	 * 未処理日付を取得
	 *
	 * _gsc_lp_query.phpファイルが存在するがgsc列DBが未生成の日付を取得する。
	 * 降順ソート（昨日→過去）で返す。
	 *
	 * @param string $tracking_id 追跡ID
	 * @return array 日付の配列（YYYYMMDD形式）
	 */
	private function get_unprocessed_dates( $tracking_id ) {
		$data_dir = $this->get_data_dir_path();
		$gsc_dir  = $data_dir . 'view/' . $tracking_id . '/gsc/';

		if ( ! is_dir( $gsc_dir ) ) {
			return array();
		}

		// _gsc_lp_query.phpファイルから日付を抽出
		// ファイル名形式: YYYY-MM-DD_gsc_lp_query.php
		$files     = glob( $gsc_dir . '*_gsc_lp_query.php' );
		$gsc_dates = array();
		if ( $files ) {
			foreach ( $files as $file ) {
				$basename = basename( $file );
				// ファイル名先頭10文字が YYYY-MM-DD 形式
				if ( strlen( $basename ) >= 11 && $basename[4] === '-' && $basename[7] === '-' && $basename[10] === '_'
					&& ctype_digit( substr( $basename, 0, 4 ) ) && ctype_digit( substr( $basename, 5, 2 ) ) && ctype_digit( substr( $basename, 8, 2 ) ) ) {
					$date_ymd = str_replace( '-', '', substr( $basename, 0, 10 ) );
					$gsc_dates[ $date_ymd ] = true;
				}
			}
		}

		// gsc列DBが未処理の日付を抽出（今日以降はスキップ）
		$report_dir = $data_dir . 'report/' . $tracking_id . '/columns-db/gsc/';
		$today_ymd  = wp_date( 'Ymd' );
		$unprocessed = array();

		foreach ( array_keys( $gsc_dates ) as $date_ymd ) {
			if ( $date_ymd >= $today_ymd ) {
				continue;
			}
			$year_month = substr( $date_ymd, 0, 6 );

			// gsc列DB未処理チェック（page_idカラムの存在で判定）
			$gsc_check = $report_dir . $year_month . '/gsc_' . $date_ymd . '_page_id.php';
			if ( ! file_exists( $gsc_check ) ) {
				$unprocessed[] = $date_ymd;
			}
		}

		// 降順ソート（昨日→過去）
		rsort( $unprocessed );

		return $unprocessed;
	}

	/**
	 * 1日分のGSCデータを列DBに変換
	 *
	 * _gsc_lp_query.php のネスト構造（ページ→クエリ配列）をフラット化し、
	 * 6カラムのバイナリファイルとして書き出す。
	 *
	 * @param string $tracking_id 追跡ID
	 * @param string $date_ymd 日付（YYYYMMDD形式）
	 * @return bool 成功/失敗
	 */
	private function convert_gsc_one_date( $tracking_id, $date_ymd ) {
		global $qahm_log;

		$data_dir   = $this->get_data_dir_path();
		$report_dir = $data_dir . 'report/' . $tracking_id . '/columns-db/';
		$gsc_dir    = $report_dir . 'gsc/';

		// 既に変換済みならスキップ
		$year_month = substr( $date_ymd, 0, 6 );
		$gsc_check  = $gsc_dir . $year_month . '/gsc_' . $date_ymd . '_page_id.php';
		if ( file_exists( $gsc_check ) ) {
			return true;
		}

		// YYYYMMDD → YYYY-MM-DD
		$date_hyphen = substr( $date_ymd, 0, 4 ) . '-' . substr( $date_ymd, 4, 2 ) . '-' . substr( $date_ymd, 6, 2 );

		// ソースファイルを読み込み
		$gsc_source_dir = $data_dir . 'view/' . $tracking_id . '/gsc/';
		$source_file    = $gsc_source_dir . $date_hyphen . '_gsc_lp_query.php';

		if ( ! $this->wrap_exists( $source_file ) ) {
			return false;
		}

		$slz  = $this->wrap_get_contents( $source_file );
		$data = $this->wrap_unserialize( $slz );

		if ( ! is_array( $data ) ) {
			if ( $qahm_log ) {
				$qahm_log->warning( 'ColumnDB GSC: Invalid data in ' . $source_file );
			}
			return false;
		}

		// Writer初期化
		$writer = new QAHM_ColumnDB_Writer( 'gsc', $tracking_id, $gsc_dir );

		$processed = 0;

		// ネスト構造をフラット化して列DBに書き込み
		foreach ( $data as $page_entry ) {
			$page_id = (int) ( $page_entry['page_id'] ?? 0 );
			$queries = $page_entry['query'] ?? array();

			if ( ! is_array( $queries ) || empty( $queries ) ) {
				continue;
			}

			foreach ( $queries as $query ) {
				$position_float = (float) ( $query['position'] ?? 0.0 );
				$position_x100  = (int) round( $position_float * 100 );
				// uint16上限クランプ
				if ( $position_x100 > 65535 ) {
					$position_x100 = 65535;
				}
				if ( $position_x100 < 0 ) {
					$position_x100 = 0;
				}

				$row = array(
					'page_id'       => $page_id,
					'query_id'      => (int) ( $query['query_id'] ?? 0 ),
					'search_type'   => (int) ( $query['search_type'] ?? 1 ),
					'clicks'        => (int) ( $query['clicks'] ?? 0 ),
					'impressions'   => (int) ( $query['impressions'] ?? 0 ),
					'position_x100' => $position_x100,
				);

				$result = $writer->write_row( $row, $date_ymd );
				if ( $result ) {
					$processed++;
				}
			}
		}

		// ファイナライズ
		$writer->finalize();

		// 有効行0件の場合、空マーカーファイルを書いて再処理を防止
		if ( $processed === 0 && ! file_exists( $gsc_check ) ) {
			$marker_dir = $gsc_dir . $year_month . '/';
			if ( ! is_dir( $marker_dir ) ) {
				mkdir( $marker_dir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			}
			QAHM_ColumnDB_BinaryIO::write_file( $gsc_check, '' );
		}

		if ( $qahm_log ) {
			$qahm_log->info( 'ColumnDB GSC converted: ' . $tracking_id . ' / ' . $date_ymd . ' (' . $processed . ' records)' );
		}

		return true;
	}
}
