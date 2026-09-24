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
	 * P7-D PR4 #1381: ColumnDB トリガー独立化 gating。
	 * 既定 false で従来どおり view_pv ファイル名（*_viewpv.php）を allpv 候補日ソースに使う＝本番フロー完全不変。
	 * true で候補日を qa_pv_log（access_time）由来にし、view_pv ファイル非依存にする（P7-X = view_pv 生成停止の前提）。
	 * 本番への実 flip は P7-X 直前/別合図。
	 */
	const PR4_COLUMNDB_TRIGGER_INDEPENDENT = true;

	/**
	 * P7-D PR5 #1384: ドリフト自己修復の view_pv 非依存化（再ホーム）gating。
	 * 既定 false で本番フロー完全不変（drift 検出は従来どおり view_pv ベース＝cron-proc の Make view file が担う）。
	 * true で done 済 allpv 日でも「qa_pv_log 当日行数 > 変換時の入力行数（manifest expected）」を late 流入＝
	 * ドリフトとして検出し、当日の allpv/click/datalayer を一括再変換に戻す（view_pv 生成停止 P7-X 後も late を拾う）。
	 * 本番への実 flip は P7-X 直前/別合図。
	 */
	const PR5_DRIFT_REHOME_ENABLED = true;

	/**
	 * Issue #1441: ColumnDB legacy 変換日の欠損バックフィル gating。
	 * 既定 false で本番フロー完全不変。true で「expected を持たない変換済み日（legacy）」を対象に
	 * qa_pv_log 日別行数と allpv 実行数を突合し、不足日（pvlog > allpv）を PR5 と同一の
	 * 3データセット同期 converting へ戻して夜間再変換させる（旧 view_pv ビルドの日境界癖で
	 * 変換から漏れた行の一回性回収。converter は qa_pv_log 直読み #1283 ゆえ正しい暦日に再配置される）。
	 * code-gate: PR4（候補日ソース＝qa_pv_log）∧ PR5（expected レジーム＋日別行数キャッシュ）が前提。
	 * 有効化はテスト協力型配布リリース想定（flip 第2段より前＝healing 済みの状態で読み側を切替える）。
	 */
	const LEGACY_DEFICIT_BACKFILL_ENABLED = false;

	/**
	 * Issue #1462: allpv is_raw 列（is_raw_p/c/e）の過去日バックフィル gating。
	 * 既定 false で本番フロー完全不変。true で「is_raw 列ファイルを持たない done 日」を検出し、
	 * #1441 と同一の 3データセット同期 converting へ戻して夜間再変換させる（is_raw 列を後付け）。
	 * is_raw_p/c/e は qa_pv_log 由来で再変換時に正しく焼き込まれる。行動カラム保護のため raw 実在ガード
	 * （legacy_backfill_inputs_ok）を流用＝raw が消えた古い日は skip（view_pv フォールバックのまま）。
	 * Issue #1499: ヒートマップ QAL 化の本番有効化（#1492）に合わせ true へ（7/31 リリース同梱）。
	 * 保持窓内の過去日の is_raw 列を配布直後の数夜で埋め、1ヶ月ヒートマップの高速化を配布直後から
	 * 効かせる（OFF のままだと新規日の蓄積待ち＝約1ヶ月遅れ）。戻し＝この 1 行を false（完全可逆）。
	 */
	const IS_RAW_BACKFILL_ENABLED = true;

	/**
	 * Issue #1441: allpv の行動カラム（convert_one_date Phase 0 が raw_p/c/e 日次から算出する13列）の
	 * raw 入力グループ対応表。バックフィル安全弁が「raw 不在の日を再変換して実値を 0 に上書きしないか」を
	 * グループ単位で検査するために使う（キー＝raw 日次ディレクトリ名・値＝その raw から算出される列）。
	 * window_inner_width/height は raw_e ヘッダ（w/h）由来（parse_rawe_tsv 参照）。
	 */
	const LEGACY_BACKFILL_RAW_GROUPS = array(
		// T108: is_submit は raw_p ヘッダー（submit イベント観測）由来に移動（旧: raw_c の action_id==2）。
		'raw_p' => array( 'depth_position', 'deep_read', 'stop_max_sec', 'stop_max_pos', 'exit_pos', 'is_submit' ),
		'raw_c' => array( 'dead_click_image_count', 'irritation_click_count' ),
		'raw_e' => array( 'scroll_back_count', 'content_skip_count', 'exploration_count', 'window_inner_width', 'window_inner_height' ),
	);

	/**
	 * P7-D PR4: qa_pv_log 由来の allpv 候補日キャッシュ（cutover ON 時・start() の tid ループで N 回スキャンしない）。
	 * 形: array( 'all' => array(Ymd=>true), 'tids' => array(tracking_id => array(Ymd=>true)) )。null=未構築。
	 *
	 * @var array|null
	 */
	private $pvlog_candidate_cache = null;

	/**
	 * P7-D PR5 #1384: qa_pv_log 由来の「日別行数」キャッシュ（drift 検出 ON 時・start() の tid ループで N 回スキャンしない）。
	 * 形: array( 'all' => array(Ymd=>count), 'tids' => array(tracking_id => array(Ymd=>count)) )。null=未構築。
	 * PR4 の候補日キャッシュ（DISTINCT DATE）とは別クエリ（GROUP BY DATE COUNT）＝PR4 フラグから独立に構築する。
	 *
	 * @var array|null
	 */
	private $pvlog_count_cache = null;

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

		// P7-D PR4 #1381: OFF は view_dir 不在で従来どおり早期 return。ON は候補ソースが qa_pv_log（DB）ゆえ
		// view_dir 存在に従属させない（純閲覧 PV だけで raw_x 無し → view_dir 不在の tid でも allpv を取りこぼさない）。
		if ( ! self::PR4_COLUMNDB_TRIGGER_INDEPENDENT && ! is_dir( $view_dir ) ) {
			return array();
		}

		// allpv 候補日のソース。
		// P7-D PR4 #1381: OFF=view_pv ファイル名（*_viewpv.php）／ON=qa_pv_log（access_time）由来＝view_pv 非依存。
		$view_dates = array();
		if ( self::PR4_COLUMNDB_TRIGGER_INDEPENDENT ) {
			$view_dates = $this->get_allpv_candidate_dates_from_pvlog( $tracking_id );
		} else {
			// view_pvファイルから日付を抽出
			// ファイル名形式: YYYY-MM-DD_*_viewpv.php
			$files = glob( $view_dir . '*_viewpv.php' );
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
		// ファイル名は cron-proc.php の暦日バケットで命名されるため、比較側の「今日」も
		// 同じ基準に揃える必要がある。#1153: 現状ファイル名は WP-TZ だが、View_pv の
		// per-site 化（別 PR）でサイトTZ命名へ移るので、ここも get_site_clock($tracking_id)
		// のサイト暦に揃えておく（現 population は全サイト WP-TZ なので挙動不変・PR でファイル名が
		// サイトTZ化した時点で自動整合）。date('Ymd') はサーバーtz(UTC等)依存でズレるため不可。
		$clock       = QAHM_Time::get_site_clock( $tracking_id );
		$report_dir  = $data_dir . 'report/' . $tracking_id . '/columns-db/';
		$allpv_dir   = $report_dir . 'allpv/';
		$click_dir   = $report_dir . 'click_event/';
		$dl_dir      = $report_dir . 'datalayer_event/';
		$today_ymd   = $clock->today_str( 'Ymd' );
		$unprocessed = array();

		// Issue #1420: データ無し日（ゼロアクセス日）へ manifest 連続性マーカー（done rows=0・expected=0）を記録する。
		// マーク対象は「データ源のある日」（$all_dates）と互いに素なので、候補列挙・変換処理とは独立に走らせてよい。
		$this->mark_nodata_days( $tracking_id, $view_dates, $allpv_dir, $view_dir, $today_ymd );

		// Issue #1441: legacy 変換日（expected 不在）の欠損バックフィル。qa_pv_log 行数との突合で不足日を
		// converting へ戻す＝下の未処理判定ループが自然に拾って再変換する（一回性・watermark 増分・既定 false）。
		$this->mark_legacy_deficit_days( $tracking_id, $view_dates, $rawc_dates, $rawg_dates, $allpv_dir, $click_dir, $dl_dir, $view_dir, $today_ymd );

		// Issue #1462: is_raw 列を持たない done 日を converting へ戻す（下の未処理判定ループが再変換）。
		// 一回性・watermark 増分・既定 false。raw 実在ガードで行動カラムのゼロ化を防ぐ。
		$this->mark_is_raw_backfill_days( $tracking_id, $view_dates, $rawc_dates, $rawg_dates, $allpv_dir, $click_dir, $dl_dir, $view_dir, $today_ymd );

		$all_dates = array_unique( array_merge( array_keys( $view_dates ), array_keys( $rawc_dates ), array_keys( $rawg_dates ) ) );
		foreach ( $all_dates as $date_ymd ) {
			if ( $date_ymd >= $today_ymd ) {
				continue;
			}
			// 完了判定は manifest 優先（無い日は従来どおり pv_id 列ファイルの存在）。
			// converting のまま残った日（中断の証拠）は未処理扱いに戻り、再変換される（Issue #1279）

			// allpv未処理チェック
			$allpv_done = QAHM_ColumnDB_Manifest::is_day_done( $allpv_dir, 'allpv', $date_ymd );

			// P7-D PR5 #1384: ドリフト再ホーム。OFF（既定）は本ブロック未実行＝挙動不変（drift 検出は従来どおり
			// cron-proc の view_pv ベース）。ON は done 済 allpv 日でも qa_pv_log 当日行数が「変換時の入力行数」
			// （manifest expected）を上回れば late 流入＝ドリフトとして再変換へ戻す（view_pv 非依存）。
			if ( self::PR5_DRIFT_REHOME_ENABLED && $allpv_done ) {
				$pvlog_rows    = $this->get_pvlog_row_count( $tracking_id, $date_ymd );
				$baseline_rows = QAHM_ColumnDB_Manifest::get_day_input_count( $allpv_dir, $date_ymd );
				// purge 済み/0件日（$pvlog_rows===null＝GROUP BY に出ない）と legacy（expected 不在＝$baseline_rows===null）は
				// ドリフト対象外＝cron-proc の「0件日スキップ」保護を再現。late 流入は行を増やすのみゆえ '>' のみ判定し、
				// write 一部失敗で実書込が入力を下回るケース（基準は入力 count）では誤検出しない＝再変換ループを防ぐ。
				if ( null !== $pvlog_rows && null !== $baseline_rows && $pvlog_rows > $baseline_rows ) {
					// allpv 単独再変換は session_id（月次 forward-only カウンタ採番）を変えるため、当日に存在する
					// click/datalayer を同期再変換しないと旧 session_id を保持してデシンクする。3 データセットを
					// 一括 converting に戻し、convert_one_date が allpv→click→datalayer 順で session_id を再整合する。
					// （Issue #1441 でロジックを mark_datasets_for_reconvert へ抽出・呼び出し順序と挙動は不変）
					$this->mark_datasets_for_reconvert( $tracking_id, $date_ymd, $allpv_dir, $click_dir, $dl_dir, $rawc_dates, $rawg_dates );
					$allpv_done = false;

					global $qahm_log;
					if ( $qahm_log ) {
						$qahm_log->info( 'ColumnDB drift rehome: ' . $tracking_id . ' / ' . $date_ymd . ' (pvlog=' . $pvlog_rows . ' > baseline=' . $baseline_rows . ') → reconvert' );
					}
				}
			}

			// click_event未処理チェック（rawcが存在する場合のみ）
			$click_done = true;
			if ( isset( $rawc_dates[ $date_ymd ] ) ) {
				$click_done = QAHM_ColumnDB_Manifest::is_day_done( $click_dir, 'click_event', $date_ymd );
			}

			// datalayer_event未処理チェック（rawgが存在する場合のみ）
			$dl_done = true;
			if ( isset( $rawg_dates[ $date_ymd ] ) ) {
				$dl_done = QAHM_ColumnDB_Manifest::is_day_done( $dl_dir, 'datalayer_event', $date_ymd );
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
	 * P7-D PR4 #1381: allpv 候補日を qa_pv_log（access_time）由来で返す（cutover ON 時のみ使用）。
	 *
	 * view_pv ファイル名に依存せず、allpv 変換 load_all_records_from_pvlog が読むのと同じ qa_pv_log の
	 * access_time バケット（per-tid=qa_pages INNER JOIN／'all'=全行）から DISTINCT 日付を作る。よって
	 * 「候補に挙がる日 ⇔ qa_pv_log に当該 tid の当日行が在る ⇔ allpv 変換が非空」が恒真＝取りこぼしゼロ。
	 * start() の tid ループから呼ばれるため、初回に全 tid 分を1パスで構築してキャッシュする（N回スキャン回避）。
	 *
	 * @param string $tracking_id tracking_id（'all' 可）.
	 * @return array allpv 候補日（array( Ymd => true )・既存 $view_dates と同形）.
	 */
	private function get_allpv_candidate_dates_from_pvlog( $tracking_id ) {
		if ( null === $this->pvlog_candidate_cache ) {
			$this->pvlog_candidate_cache = $this->build_pvlog_candidate_cache();
		}
		if ( 'all' === $tracking_id ) {
			return $this->pvlog_candidate_cache['all'];
		}
		return isset( $this->pvlog_candidate_cache['tids'][ $tracking_id ] )
			? $this->pvlog_candidate_cache['tids'][ $tracking_id ]
			: array();
	}

	/**
	 * P7-D PR4 #1381: qa_pv_log から allpv 候補日（'all' ＋ 全 tid）を1パスで構築する。
	 *
	 * - 候補は qa_pv_log の **DISTINCT 実日付**（行のある日だけ＝変換可能な日だけ）。OFF（view_pv glob）が
	 *   全履歴の view_pv ファイルを候補にするのと同じ全保持窓をカバーする（任意の recency 下限は付けない＝
	 *   変換可能な古い日を取りこぼさない。dev5 実測で qa_pv_log 保持は DATA_SAVE_MONTH より長いことがあるため、
	 *   保持月数を仮定した下限は使わない。do を絞るのは後段の manifest 完了判定が担う）。
	 * - 'all' は全行（join なし＝qa_pages 不在の孤児行も含む。load_all_records の 'all' と同集合）。
	 * - per-tid は qa_pages.tracking_id 由来（load_all_records の per-tid INNER JOIN と同規則）。
	 * - start() の tid ループ全体で **本メソッドは1回だけ**実行（呼び出し側がキャッシュ）＝per-tid を N 回スキャンしない。
	 *
	 * @return array array( 'all' => array(Ymd=>true), 'tids' => array(tracking_id => array(Ymd=>true)) ).
	 */
	private function build_pvlog_candidate_cache() {
		global $wpdb;

		$pvlog = $wpdb->prefix . 'qa_pv_log';
		$pages = $wpdb->prefix . 'qa_pages';

		$cache = array( 'all' => array(), 'tids' => array() );

		// 'all' = 全行の DISTINCT 日付。
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $pvlog is $wpdb->prefix . 'qa_pv_log'; no user input.
		$all_dates = $wpdb->get_col( "SELECT DISTINCT DATE(access_time) FROM `{$pvlog}`" );
		foreach ( (array) $all_dates as $d ) {
			if ( $d ) {
				$cache['all'][ str_replace( '-', '', $d ) ] = true;
			}
		}

		// per-tid = qa_pages.tracking_id 由来（1スキャンで全 tid）。
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are $wpdb->prefix based; no user input.
		$tid_rows = $wpdb->get_results( "SELECT DISTINCT pg.tracking_id AS tid, DATE(pl.access_time) AS d FROM `{$pvlog}` pl INNER JOIN `{$pages}` pg ON pg.page_id = pl.page_id" );
		foreach ( (array) $tid_rows as $r ) {
			if ( '' !== (string) $r->tid && $r->d ) {
				$cache['tids'][ $r->tid ][ str_replace( '-', '', $r->d ) ] = true;
			}
		}

		return $cache;
	}

	/**
	 * P7-D PR5 #1384: qa_pv_log の「日別行数」を返す（drift 検出 ON 時のみ・get_unprocessed_dates から呼ばれる）。
	 *
	 * allpv 変換 load_all_records_from_pvlog が読むのと同じ access_time バケット（per-tid=qa_pages INNER JOIN／
	 * 'all'=全行）の当日行数。done 済 allpv 日の manifest expected（＝変換時の入力行数）と比較し、増えていれば
	 * late 流入＝ドリフトと判定する。GROUP BY DATE,COUNT を tid ループ全体で1回だけ実行しキャッシュ（N回スキャン回避）。
	 *
	 * @param string $tracking_id tracking_id（'all' 可）.
	 * @param string $date_ymd    YYYYMMDD.
	 * @return int|null 当日行数。当日行が無い日（purge 済み/0件）は null（＝ドリフト対象外）.
	 */
	private function get_pvlog_row_count( $tracking_id, $date_ymd ) {
		if ( null === $this->pvlog_count_cache ) {
			$this->pvlog_count_cache = $this->build_pvlog_count_cache();
		}
		if ( 'all' === $tracking_id ) {
			return $this->pvlog_count_cache['all'][ $date_ymd ] ?? null;
		}
		return $this->pvlog_count_cache['tids'][ $tracking_id ][ $date_ymd ] ?? null;
	}

	/**
	 * P7-D PR5 #1384: qa_pv_log から「日別行数」（'all' ＋ 全 tid）を1パスで構築する。
	 *
	 * PR4 の候補日キャッシュ（DISTINCT DATE）が「行のある日」を求めるのに対し、PR5 は per-date の COUNT が要る。
	 * GROUP BY は DISTINCT を包含するが、PR4 の build_pvlog_candidate_cache は PR4 フラグ ON 時のみ呼ばれるため
	 * 流用すると PR5 が PR4 ON に暗黙依存する。よって PR5 専用に独立した GROUP BY COUNT クエリを持つ。
	 * - 'all' は全行（join なし＝load_all_records の 'all' と同集合）。
	 * - per-tid は qa_pages.tracking_id 由来（load_all_records の per-tid INNER JOIN と同規則）。
	 * - 任意の recency 下限は付けない（late は DATA_SAVE_MONTH 窓まで来うる・保持月数を仮定した下限は環境で崩れる）。
	 *
	 * @return array array( 'all' => array(Ymd=>count), 'tids' => array(tracking_id => array(Ymd=>count)) ).
	 */
	private function build_pvlog_count_cache() {
		global $wpdb;

		$pvlog = $wpdb->prefix . 'qa_pv_log';
		$pages = $wpdb->prefix . 'qa_pages';

		$cache = array( 'all' => array(), 'tids' => array() );

		// 'all' = 全行の日別件数。
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $pvlog is $wpdb->prefix . 'qa_pv_log'; no user input.
		$all_rows = $wpdb->get_results( "SELECT DATE(access_time) AS d, COUNT(*) AS c FROM `{$pvlog}` GROUP BY DATE(access_time)" );
		foreach ( (array) $all_rows as $r ) {
			if ( $r->d ) {
				$cache['all'][ str_replace( '-', '', $r->d ) ] = (int) $r->c;
			}
		}

		// per-tid = qa_pages.tracking_id 由来の日別件数（1スキャンで全 tid）。
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are $wpdb->prefix based; no user input.
		$tid_rows = $wpdb->get_results( "SELECT pg.tracking_id AS tid, DATE(pl.access_time) AS d, COUNT(*) AS c FROM `{$pvlog}` pl INNER JOIN `{$pages}` pg ON pg.page_id = pl.page_id GROUP BY pg.tracking_id, DATE(pl.access_time)" );
		foreach ( (array) $tid_rows as $r ) {
			if ( '' !== (string) $r->tid && $r->d ) {
				$cache['tids'][ $r->tid ][ str_replace( '-', '', $r->d ) ] = (int) $r->c;
			}
		}

		return $cache;
	}

	/**
	 * Issue #1420: データ無し日（ゼロアクセス日）へ manifest 連続性マーカーを記録する（writer＋過去分バックフィル）。
	 *
	 * 目的: ColumnDB ON リーダーの all-or-nothing フォールバックを「真の未変換日」のみに純化する。
	 * ゼロアクセス日は変換候補（qa_pv_log 由来）に入らず manifest エントリが書かれないため、リーダーが
	 * 「未 done」と区別できず範囲全体をフォールバックしていた（P7-X 後は新日の静かな欠落リスク）。
	 *
	 * マーク条件（3点ガード＋qa_pv_log 補集合）: 次が全て真の日のみ rows=0 を書く。
	 *   ①manifest エントリ無し ②view_pv 行ファイル不在 ③pv_id 列ファイル不在 ④qa_pv_log にその日の行が無い
	 * ②だけでは不足＝ドリフト再構築は delete-then-rebuild（view_pv 先削除・cron-proc）ゆえ、中断痕として
	 * 「view 不在・列在・実データ」日が存在しうる。pre-manifest 変換月（エントリ無しでも列在・読める）も
	 * ③が保護する。3点＋④が揃えば「OLD 寄与ゼロ＝ON 空」の等価が構造的に確定する。
	 *
	 * code-gate: NODATA フラグに加え PR4（④の判定ソース＝qa_pv_log 候補集合）∧ PR5（rows=0/expected=0 日への
	 * 遅延流入をドリフト再ホームが pvlog>expected(0) で自動回収）の ON を要求。PR5 OFF だと遅延流入日が
	 * is_day_done=true のまま恒久スキップになる穴があるため（着手前設計相談 #1420 🟡-1）。
	 *
	 * 境界と増分: 初回に最古データ証跡日（境界）を確定して nodata_scan.php へ永続化（P7-X で view_pv という
	 * 証跡が消えた後も境界が生きる）。以後は scanned_through の翌日〜昨日のみ走査＝夜間コスト一定。
	 * データ証跡ゼロの tid は境界を作らず no-op。
	 *
	 * @param string $tracking_id トラッキングID（'all' 含む）.
	 * @param array  $view_dates  allpv 候補日集合（Ymd=>true・PR4 ON では qa_pv_log 由来）.
	 * @param string $allpv_dir   allpv データセットディレクトリ.
	 * @param string $view_dir    view_pv ディレクトリ.
	 * @param string $today_ymd   サイト暦の今日（Ymd）.
	 * @return void
	 */
	private function mark_nodata_days( $tracking_id, $view_dates, $allpv_dir, $view_dir, $today_ymd ) {
		if ( ! class_exists( 'QAHM_ColumnDB_Manifest' ) || ! QAHM_ColumnDB_Manifest::NODATA_DAY_MANIFEST_ENABLED ) {
			return;
		}
		if ( ! self::PR4_COLUMNDB_TRIGGER_INDEPENDENT || ! self::PR5_DRIFT_REHOME_ENABLED ) {
			return;
		}

		// 昨日（サイト暦）。今日はデータ未確定＝対象外（候補列挙の today スキップと同基準）。
		$yesterday_ymd = gmdate( 'Ymd', gmmktime( 0, 0, 0, (int) substr( $today_ymd, 4, 2 ), (int) substr( $today_ymd, 6, 2 ) - 1, (int) substr( $today_ymd, 0, 4 ) ) );

		// ②view_pv 行ファイルの日付集合（glob 1回・証跡かつ境界の材料）。
		$view_file_dates = array();
		$vp_files        = is_dir( $view_dir ) ? glob( $view_dir . '*_viewpv.php' ) : false;
		if ( $vp_files ) {
			foreach ( $vp_files as $file ) {
				$basename = basename( $file );
				if ( strlen( $basename ) >= 11 && $basename[4] === '-' && $basename[7] === '-' && $basename[10] === '_'
					&& ctype_digit( substr( $basename, 0, 4 ) ) && ctype_digit( substr( $basename, 5, 2 ) ) && ctype_digit( substr( $basename, 8, 2 ) ) ) {
					$view_file_dates[ str_replace( '-', '', substr( $basename, 0, 10 ) ) ] = true;
				}
			}
		}

		$meta = QAHM_ColumnDB_Manifest::load_scan_meta( $allpv_dir );
		if ( false === $meta ) {
			$boundary = $this->find_oldest_allpv_evidence( $view_dates, $view_file_dates, $allpv_dir );
			if ( null === $boundary ) {
				return; // データ証跡ゼロの tid＝境界を作らず no-op。
			}
			$meta = array(
				'boundary'        => $boundary,
				'scanned_through' => '',
			);
		} else {
			// 境界の陳腐化防御: 確定済み境界より古い証跡が現れたら（バックアップ復元・移設等）、
			// 境界を古い方へ更新し全再スキャン（冪等・既存エントリは不触）。手元の集合（今夜分）だけで安価に判定。
			$oldest_now = null;
			if ( $view_dates ) {
				$oldest_now = (string) min( array_keys( $view_dates ) );
			}
			if ( $view_file_dates ) {
				$vf_min     = (string) min( array_keys( $view_file_dates ) );
				$oldest_now = ( null === $oldest_now || $vf_min < $oldest_now ) ? $vf_min : $oldest_now;
			}
			if ( null !== $oldest_now && $oldest_now < $meta['boundary'] ) {
				$meta['boundary']        = $oldest_now;
				$meta['scanned_through'] = '';
			}
		}

		// 走査範囲＝増分（初回は境界から）。
		if ( '' !== (string) $meta['scanned_through'] ) {
			$s    = (string) $meta['scanned_through'];
			$from = gmdate( 'Ymd', gmmktime( 0, 0, 0, (int) substr( $s, 4, 2 ), (int) substr( $s, 6, 2 ) + 1, (int) substr( $s, 0, 4 ) ) );
		} else {
			$from = (string) $meta['boundary'];
		}
		if ( $from > $yesterday_ymd ) {
			QAHM_ColumnDB_Manifest::save_scan_meta( $allpv_dir, $meta ); // 範囲ゼロでも境界は固定・永続化する。
			return;
		}

		$pvid_file_dates = array(); // ③pv_id 列ファイルの日付集合（走査対象月のみ月別 glob）。
		$marked          = 0;
		$scanned_last    = ''; // 走査を完了できた最後の日。mark 失敗・guard 打ち切り日以降は「未スキャン」のまま残す。
		$write_failed    = false;
		$ymd             = $from;
		$guard           = 0;
		while ( $ymd <= $yesterday_ymd && $guard < 36600 ) { // 100年ガード（暴走防止）。
			$hit = isset( $view_dates[ $ymd ] ) || isset( $view_file_dates[ $ymd ] ); // ④qa_pv_log ／ ②view_pv ファイル。
			if ( ! $hit ) {
				$ym = substr( $ymd, 0, 6 );
				if ( ! isset( $pvid_file_dates[ $ym ] ) ) {
					$pvid_file_dates[ $ym ] = array();
					$col_files              = glob( $allpv_dir . $ym . '/allpv_*_pv_id.php' );
					if ( $col_files ) {
						foreach ( $col_files as $file ) {
							$b = basename( $file ); // allpv_YYYYMMDD_pv_id.php
							if ( strlen( $b ) >= 14 && ctype_digit( substr( $b, 6, 8 ) ) ) {
								$pvid_file_dates[ $ym ][ substr( $b, 6, 8 ) ] = true;
							}
						}
					}
				}
				$hit = isset( $pvid_file_dates[ $ym ][ $ymd ] ); // ③変換済み証跡（pre-manifest 月・中断痕を保護）。
			}
			if ( ! $hit ) {
				$entry = QAHM_ColumnDB_Manifest::get_day( QAHM_ColumnDB_Manifest::load( $allpv_dir, substr( $ymd, 0, 6 ) ), $ymd );
				if ( null === $entry ) {
					// ①〜④すべて証跡なし＝データ無し日。expected=0 で遅延流入時に PR5 が自動回収できる形で記録。
					if ( QAHM_ColumnDB_Manifest::mark_done( $allpv_dir, $ymd, 0, 0 ) ) {
						$marked++;
					} else {
						// 書き込み失敗（ディスクフル等）＝この日以降は次回夜間に再訪させる（watermark を進めない）。
						$write_failed = true;
						break;
					}
				}
			}
			$scanned_last = $ymd;
			$ymd          = gmdate( 'Ymd', gmmktime( 0, 0, 0, (int) substr( $ymd, 4, 2 ), (int) substr( $ymd, 6, 2 ) + 1, (int) substr( $ymd, 0, 4 ) ) );
			$guard++;
		}

		// watermark＝完了できた日まで（mark 失敗・guard 打ち切り時は途中まで＝残りは次回再訪）。
		if ( '' !== $scanned_last ) {
			$meta['scanned_through'] = $scanned_last;
		}
		QAHM_ColumnDB_Manifest::save_scan_meta( $allpv_dir, $meta );

		global $qahm_log;
		if ( $qahm_log ) {
			if ( $marked > 0 ) {
				$qahm_log->info( 'ColumnDB nodata scan: ' . $tracking_id . ' marked ' . $marked . ' zero-data day(s) (' . $from . '..' . ( '' !== $scanned_last ? $scanned_last : $from ) . ')' );
			}
			if ( $write_failed ) {
				$qahm_log->warning( 'ColumnDB nodata scan: ' . $tracking_id . ' mark_done failed at ' . $ymd . ' — will retry next night' );
			}
		}
	}

	/**
	 * Issue #1420: 最古データ証跡日（nodata スキャンの境界）を求める。
	 *
	 * 証跡＝qa_pv_log 候補日（④）・view_pv 行ファイル（②）・allpv 側（manifest エントリ／pv_id 列ファイル＝③）の min。
	 * 「最古エントリ日」ではなく「最古データ証跡日」であるのが要点（pre-manifest 変換月＝エントリ無しでも
	 * 列在・読める月を境界の内側に含める）。証跡ゼロは null（データゼロ tid）。
	 *
	 * @param array  $view_dates      allpv 候補日集合（Ymd=>true）.
	 * @param array  $view_file_dates view_pv 行ファイルの日付集合（Ymd=>true）.
	 * @param string $allpv_dir       allpv データセットディレクトリ.
	 * @return string|null YYYYMMDD or null.
	 */
	private function find_oldest_allpv_evidence( $view_dates, $view_file_dates, $allpv_dir ) {
		$candidates = array();
		if ( $view_dates ) {
			$candidates[] = min( array_keys( $view_dates ) );
		}
		if ( $view_file_dates ) {
			$candidates[] = min( array_keys( $view_file_dates ) );
		}
		// allpv 側＝月ディレクトリを昇順に走査し、最初に証跡（manifest エントリ or pv_id 列ファイル）が出た月の min 日。
		$month_dirs = glob( rtrim( $allpv_dir, '/' ) . '/[0-9][0-9][0-9][0-9][0-9][0-9]', GLOB_ONLYDIR );
		if ( $month_dirs ) {
			sort( $month_dirs );
			foreach ( $month_dirs as $mdir ) {
				$ym       = basename( $mdir );
				$dates    = array();
				$manifest = QAHM_ColumnDB_Manifest::load( $allpv_dir, $ym );
				if ( is_array( $manifest ) ) {
					foreach ( array_keys( $manifest ) as $k ) {
						if ( 8 === strlen( (string) $k ) ) {
							$dates[] = (string) $k;
						}
					}
				}
				$col_files = glob( $mdir . '/allpv_*_pv_id.php' );
				if ( $col_files ) {
					foreach ( $col_files as $file ) {
						$b = basename( $file );
						if ( strlen( $b ) >= 14 && ctype_digit( substr( $b, 6, 8 ) ) ) {
							$dates[] = substr( $b, 6, 8 );
						}
					}
				}
				if ( $dates ) {
					$candidates[] = min( $dates );
					break;
				}
			}
		}
		// 数値文字列の配列キーは PHP が int 化するため、Ymd は string に正規化して返す（メタの型を揃える）。
		return $candidates ? (string) min( $candidates ) : null;
	}

	/**
	 * P7-D PR5 #1384 / Issue #1441: 当日の3データセット（allpv/click/datalayer）を一括で converting に戻す。
	 *
	 * allpv 単独再変換は session_id（月次 forward-only カウンタ採番）を変えるため、当日に存在する
	 * click/datalayer を同期再変換しないと旧 session_id を保持してデシンクする。converting に戻せば
	 * convert_one_date が allpv→click→datalayer 順で session_id を再整合する。
	 * 'all' 仮想サイトは raw_c/raw_g を持たない（click/datalayer は per-site 列DBの merge_*_for_all で
	 * 生成）ため rawc_dates/rawg_dates の isset では捕まらない＝無条件で converting に戻し再 merge を
	 * 強制する（merge のゲート is_day_done を false 化する）。
	 *
	 * @param string $tracking_id 追跡ID.
	 * @param string $date_ymd    YYYYMMDD.
	 * @param string $allpv_dir   allpv データセットディレクトリ.
	 * @param string $click_dir   click_event データセットディレクトリ.
	 * @param string $dl_dir      datalayer_event データセットディレクトリ.
	 * @param array  $rawc_dates  raw_c 日次の日付集合（Ymd=>true）.
	 * @param array  $rawg_dates  raw_g 日次の日付集合（Ymd=>true）.
	 * @return bool allpv の converting 書き込みが成功したか（click/dl は従来どおり結果を判定に使わない）.
	 */
	private function mark_datasets_for_reconvert( $tracking_id, $date_ymd, $allpv_dir, $click_dir, $dl_dir, $rawc_dates, $rawg_dates ) {
		$ok = QAHM_ColumnDB_Manifest::mark_converting( $allpv_dir, $date_ymd );
		if ( 'all' === $tracking_id ) {
			QAHM_ColumnDB_Manifest::mark_converting( $click_dir, $date_ymd );
			QAHM_ColumnDB_Manifest::mark_converting( $dl_dir, $date_ymd );
		} else {
			if ( isset( $rawc_dates[ $date_ymd ] ) ) {
				QAHM_ColumnDB_Manifest::mark_converting( $click_dir, $date_ymd );
			}
			if ( isset( $rawg_dates[ $date_ymd ] ) ) {
				QAHM_ColumnDB_Manifest::mark_converting( $dl_dir, $date_ymd );
			}
		}
		return $ok;
	}

	/**
	 * Issue #1441: legacy 欠損バックフィル＝「expected を持たない変換済み日」の行不足を検出し再変換へ戻す。
	 *
	 * 対象＝旧 view_pv ビルドの日境界癖（access_time が翌日の行を当日ファイルへ格納）により、
	 * view_pv 入力世代の ColumnDB 変換が「ファイル日付×実日時の裂け目」で変換しなかった行を持つ日。
	 * 現 converter は qa_pv_log 直読み（#1283）のため、converting に戻すだけで欠損行は正しい暦日に
	 * 再配置される。再変換完了時に expected が記録され、以後は PR5 ドリフトレジームが引き継ぐ（legacy 卒業）。
	 *
	 * 判定（qa_pv_log 候補日のうち今日より前の各日）:
	 *   - state=converting（#1279 中断痕）＝既存の未処理判定が再変換する → 対象外
	 *   - expected 在（get_day_input_count !== null）＝PR5 レジーム → 対象外
	 *   - expected 不在 ∧ pv_id 列ファイル在 → qa_pv_log 日別行数と突合し pvlog > allpv のときだけ対象
	 *     （逆方向＝pvlog purge 途中等は再変換で行が減る＝データ喪失方向ゆえ触らない）
	 *   - pv_id 列ファイル無し ＝ 未変換日（本流が変換）or ゼロ日（NODATA #1420 の領分）→ 対象外
	 *
	 * 安全弁＝legacy_backfill_inputs_ok（行動カラムの静かな劣化・click/dl デシンクを防ぐ）。
	 * skip した日も watermark を前進させる（raw 日次は復活しない前提＝恒久 skip・warn 1行）。
	 * mark 書き込み失敗（ディスクフル等）時のみ前進させず次回夜間に再訪。
	 *
	 * 残余リスク（設計上許容）: 走査時に pvlog==allpv だった legacy 日が watermark 通過後に qa_pv_log へ
	 * 遅延行を得ても、backfill（watermark 済）も PR5（expected 不在）も検出しない silent under-count が
	 * 起こりうる。ただし backfill で再変換された日は expected を得て PR5 レジームへ卒業するため、露出は
	 * 「走査時点で不足でなかった legacy 日」に限定される（旧世代の欠損露出を一回性で潰すのが本メソッドの目的）。
	 *
	 * @param string $tracking_id 追跡ID.
	 * @param array  $view_dates  allpv 候補日集合（Ymd=>true・PR4 ON では qa_pv_log 由来）.
	 * @param array  $rawc_dates  raw_c 日次の日付集合（Ymd=>true）.
	 * @param array  $rawg_dates  raw_g 日次の日付集合（Ymd=>true）.
	 * @param string $allpv_dir   allpv データセットディレクトリ.
	 * @param string $click_dir   click_event データセットディレクトリ.
	 * @param string $dl_dir      datalayer_event データセットディレクトリ.
	 * @param string $view_dir    view_pv ディレクトリ（raw_p/c/e/g 日次の親）.
	 * @param string $today_ymd   サイト暦の今日（Ymd）.
	 * @return void
	 */
	private function mark_legacy_deficit_days( $tracking_id, $view_dates, $rawc_dates, $rawg_dates, $allpv_dir, $click_dir, $dl_dir, $view_dir, $today_ymd ) {
		if ( ! self::LEGACY_DEFICIT_BACKFILL_ENABLED ) {
			return;
		}
		if ( ! self::PR4_COLUMNDB_TRIGGER_INDEPENDENT || ! self::PR5_DRIFT_REHOME_ENABLED ) {
			return;
		}
		if ( ! class_exists( 'QAHM_ColumnDB_Manifest' ) || ! class_exists( 'QAHM_ColumnDB_BinaryIO' ) || ! class_exists( 'QAHM_ColumnDB_Schema' ) ) {
			return;
		}

		global $qahm_log;

		$meta = QAHM_ColumnDB_Manifest::load_backfill_meta( $allpv_dir );
		if ( false === $meta ) {
			$meta = array( 'scanned_through' => '' );
		}
		$watermark = (string) $meta['scanned_through'];

		$dates = array_keys( $view_dates );
		sort( $dates );

		$marked       = 0;
		$skipped      = 0;
		$scanned_last = '';
		$write_failed = false;

		foreach ( $dates as $date_ymd ) {
			$date_ymd = (string) $date_ymd;
			if ( $date_ymd >= $today_ymd ) {
				continue; // 今日以降＝データ未確定（本流の候補列挙と同基準）。
			}
			if ( '' !== $watermark && $date_ymd <= $watermark ) {
				continue; // 走査済み（増分）。
			}

			$entry     = QAHM_ColumnDB_Manifest::get_day( QAHM_ColumnDB_Manifest::load( $allpv_dir, substr( $date_ymd, 0, 6 ) ), $date_ymd );
			$state     = ( is_array( $entry ) && isset( $entry['state'] ) ) ? $entry['state'] : '';
			$is_target = true;
			if ( QAHM_ColumnDB_Manifest::STATE_CONVERTING === $state ) {
				$is_target = false; // #1279 中断痕＝既存の未処理判定が拾って再変換する。
			} elseif ( null !== QAHM_ColumnDB_Manifest::get_day_input_count( $allpv_dir, $date_ymd ) ) {
				$is_target = false; // expected 在＝PR5 レジームが引き継いでいる日。
			}

			if ( $is_target ) {
				$pvid_file = $allpv_dir . substr( $date_ymd, 0, 6 ) . '/allpv_' . $date_ymd . '_pv_id.php';
				if ( file_exists( $pvid_file ) ) {
					$pv_bytes   = QAHM_ColumnDB_Schema::SCHEMA_ALLPV['pv_id']['bytes'];
					$allpv_rows = QAHM_ColumnDB_BinaryIO::get_row_count( $pvid_file, $pv_bytes );
					$pvlog_rows = $this->get_pvlog_row_count( $tracking_id, $date_ymd );
					if ( false !== $allpv_rows && null !== $pvlog_rows && $pvlog_rows > $allpv_rows ) {
						if ( $this->legacy_backfill_inputs_ok( $tracking_id, $date_ymd, $view_dir, $allpv_dir, $click_dir, $dl_dir ) ) {
							if ( $this->mark_datasets_for_reconvert( $tracking_id, $date_ymd, $allpv_dir, $click_dir, $dl_dir, $rawc_dates, $rawg_dates ) ) {
								$marked++;
								if ( $qahm_log ) {
									$qahm_log->info( 'ColumnDB legacy backfill: ' . $tracking_id . ' / ' . $date_ymd . ' (pvlog=' . $pvlog_rows . ' > allpv=' . $allpv_rows . ') → reconvert' );
								}
							} else {
								// 書き込み失敗（ディスクフル等）＝この日以降は次回夜間に再訪させる（watermark を進めない）。
								$write_failed = true;
								break;
							}
						} else {
							$skipped++; // 恒久 skip（watermark は前進）＝欠損は残るが新しい不整合を作らない。
							if ( $qahm_log ) {
								$qahm_log->warning( 'ColumnDB legacy backfill: ' . $tracking_id . ' / ' . $date_ymd . ' skipped (raw daily missing and non-zero values would be lost) — deficit left as-is' );
							}
						}
					}
				}
			}

			$scanned_last = $date_ymd;
		}

		// 走査した日が無ければ（今日以降のみ／全て watermark 済）永続化はスキップ（無変化夜の冗長書き込みを避ける）。
		if ( '' !== $scanned_last && ( '' === $watermark || $scanned_last > $watermark ) ) {
			$meta['scanned_through'] = $scanned_last;
			QAHM_ColumnDB_Manifest::save_backfill_meta( $allpv_dir, $meta );
		}

		if ( $qahm_log ) {
			if ( $marked > 0 || $skipped > 0 ) {
				$qahm_log->info( 'ColumnDB legacy backfill: ' . $tracking_id . ' scan done (marked=' . $marked . ' skipped=' . $skipped . ' through=' . ( '' !== $scanned_last ? $scanned_last : '-' ) . ')' );
			}
			if ( $write_failed ) {
				$qahm_log->warning( 'ColumnDB legacy backfill: ' . $tracking_id . ' mark_converting failed — will retry next night' );
			}
		}
	}

	/**
	 * Issue #1462: is_raw 列（is_raw_p/c/e）を持たない done 日を再変換に戻す（過去日バックフィル）。
	 *
	 * is_raw 列導入前に変換された done 日は is_raw 列ファイルを持たない。当日の allpv に is_raw_p 列ファイルが
	 * 無ければ「is_raw 未導入日」と判定し、#1441 と同一の 3データセット同期 converting へ戻す（下の未処理判定
	 * ループが allpv→click→datalayer を session_id 整合で再変換し、convert_one_date が is_raw を焼き込む）。
	 * is_raw_p/c/e は qa_pv_log 由来ゆえ raw が消えていても正しい値になるが、再変換は行動カラムを raw から
	 * 再算出するため、raw 不在日は #1441 と同じ安全弁（legacy_backfill_inputs_ok）で skip し実値を守る
	 * （その日は is_raw を得られず view_pv フォールバックのまま＝並行生成で担保）。
	 * 一回性・watermark 増分（専用メタ）・既定 false。
	 * 残余リスク（#1441 と同性質）: フラグ ON かつ converting 中に走査（watermark 前進）した日の
	 * 再変換が後でクラッシュし done-without-is_raw で残ると再検出されない。フラグ一回性＋クラッシュ前提で許容。
	 *
	 * @param string $tracking_id 追跡ID.
	 * @param array  $view_dates  view_pv 日次の日付集合（Ymd=>true）.
	 * @param array  $rawc_dates  raw_c 日次の日付集合.
	 * @param array  $rawg_dates  raw_g 日次の日付集合.
	 * @param string $allpv_dir   allpv データセットディレクトリ.
	 * @param string $click_dir   click_event データセットディレクトリ.
	 * @param string $dl_dir      datalayer_event データセットディレクトリ.
	 * @param string $view_dir    view_pv ディレクトリ.
	 * @param string $today_ymd   今日（Ymd）.
	 * @return void
	 */
	private function mark_is_raw_backfill_days( $tracking_id, $view_dates, $rawc_dates, $rawg_dates, $allpv_dir, $click_dir, $dl_dir, $view_dir, $today_ymd ) {
		if ( ! self::IS_RAW_BACKFILL_ENABLED ) {
			return;
		}
		if ( ! self::PR4_COLUMNDB_TRIGGER_INDEPENDENT || ! self::PR5_DRIFT_REHOME_ENABLED ) {
			return;
		}
		if ( ! class_exists( 'QAHM_ColumnDB_Manifest' ) || ! class_exists( 'QAHM_ColumnDB_Schema' ) ) {
			return;
		}

		global $qahm_log;

		$meta = QAHM_ColumnDB_Manifest::load_is_raw_backfill_meta( $allpv_dir );
		if ( false === $meta ) {
			$meta = array( 'scanned_through' => '' );
		}
		$watermark = (string) $meta['scanned_through'];

		$dates = array_keys( $view_dates );
		sort( $dates );

		$marked       = 0;
		$skipped      = 0;
		$scanned_last = '';
		$write_failed = false;

		foreach ( $dates as $date_ymd ) {
			$date_ymd = (string) $date_ymd;
			if ( $date_ymd >= $today_ymd ) {
				continue; // 今日以降＝データ未確定。
			}
			if ( '' !== $watermark && $date_ymd <= $watermark ) {
				continue; // 走査済み（増分）。
			}

			$entry = QAHM_ColumnDB_Manifest::get_day( QAHM_ColumnDB_Manifest::load( $allpv_dir, substr( $date_ymd, 0, 6 ) ), $date_ymd );
			$state = ( is_array( $entry ) && isset( $entry['state'] ) ) ? $entry['state'] : '';

			// converting 中は既存の未処理判定が拾う＝ここでは触らない。
			if ( QAHM_ColumnDB_Manifest::STATE_CONVERTING !== $state ) {
				$ym        = substr( $date_ymd, 0, 6 );
				$pvid_file = $allpv_dir . $ym . '/allpv_' . $date_ymd . '_pv_id.php';
				$israw_file = $allpv_dir . $ym . '/allpv_' . $date_ymd . '_is_raw_p.php';
				// pv_id 列はある（＝変換済み）が is_raw_p 列が無い日＝is_raw 未導入日。
				if ( file_exists( $pvid_file ) && ! file_exists( $israw_file ) ) {
					if ( $this->legacy_backfill_inputs_ok( $tracking_id, $date_ymd, $view_dir, $allpv_dir, $click_dir, $dl_dir ) ) {
						if ( $this->mark_datasets_for_reconvert( $tracking_id, $date_ymd, $allpv_dir, $click_dir, $dl_dir, $rawc_dates, $rawg_dates ) ) {
							$marked++;
							if ( $qahm_log ) {
								$qahm_log->info( 'ColumnDB is_raw backfill: ' . $tracking_id . ' / ' . $date_ymd . ' (is_raw column missing) → reconvert' );
							}
						} else {
							$write_failed = true;
							break; // 書き込み失敗＝watermark を進めず次回夜間に再訪。
						}
					} else {
						$skipped++; // raw 不在＝恒久 skip（is_raw は得られず view_pv フォールバックのまま）。
						if ( $qahm_log ) {
							$qahm_log->warning( 'ColumnDB is_raw backfill: ' . $tracking_id . ' / ' . $date_ymd . ' skipped (raw daily missing — behavioral columns protected)' );
						}
					}
				}
			}

			$scanned_last = $date_ymd;
		}

		if ( '' !== $scanned_last && ( '' === $watermark || $scanned_last > $watermark ) ) {
			$meta['scanned_through'] = $scanned_last;
			QAHM_ColumnDB_Manifest::save_is_raw_backfill_meta( $allpv_dir, $meta );
		}

		if ( $qahm_log ) {
			if ( $marked > 0 || $skipped > 0 ) {
				$qahm_log->info( 'ColumnDB is_raw backfill: ' . $tracking_id . ' scan done (marked=' . $marked . ' skipped=' . $skipped . ' through=' . ( '' !== $scanned_last ? $scanned_last : '-' ) . ')' );
			}
			if ( $write_failed ) {
				$qahm_log->warning( 'ColumnDB is_raw backfill: ' . $tracking_id . ' mark_converting failed — will retry next night' );
			}
		}
	}

	/**
	 * Issue #1441: バックフィル安全弁＝当日を再変換して「静かな劣化・デシンク」を起こさないか検査する。
	 *
	 * (1) 行動カラム: convert_one_date Phase 0（compute_behavioral_columns）は raw_p/c/e 日次を glob で
	 *     読み、無ければ行動カラムへ全行 0 を書く（エラーにならない＝静かな劣化）。raw 不在グループの
	 *     現 allpv 列に非ゼロ実値が残っていれば false（実値を守る）。全ゼロなら失うものが無い＝続行可。
	 *     判定は生バイト走査（固定長 int 列＝値 0 ⇔ 全ゼロバイト・null ビットマップ無し）。
	 *     ※raw 日次の「日数窓削除」（'Files>View dir'）は現行 develop で到達しない遷移のため、
	 *       窓計算に頼らず日ごとの実在チェックで判定する。
	 * (2) click/datalayer デシンク防止: 当日のデータセット列ファイルが存在するのに対応する raw 日次が
	 *     無い場合、click/dl は再変換できず allpv だけ session_id が変わってデシンクする → false。
	 *     'all' は per-site 列DBからの再 merge（raw 非依存）ゆえこの検査は対象外。
	 *
	 * @param string $tracking_id 追跡ID.
	 * @param string $date_ymd    YYYYMMDD.
	 * @param string $view_dir    view_pv ディレクトリ（raw_p/c/e/g 日次の親）.
	 * @param string $allpv_dir   allpv データセットディレクトリ.
	 * @param string $click_dir   click_event データセットディレクトリ.
	 * @param string $dl_dir      datalayer_event データセットディレクトリ.
	 * @return bool true=再変換して安全 / false=skip（恒久）.
	 */
	private function legacy_backfill_inputs_ok( $tracking_id, $date_ymd, $view_dir, $allpv_dir, $click_dir, $dl_dir ) {
		$ym          = substr( $date_ymd, 0, 6 );
		$date_hyphen = substr( $date_ymd, 0, 4 ) . '-' . substr( $date_ymd, 4, 2 ) . '-' . substr( $date_ymd, 6, 2 );

		// raw 日次の実在（compute_behavioral_columns / click / datalayer 変換と同じ glob パターン）。
		$raw_exists = array();
		foreach ( array( 'raw_p', 'raw_c', 'raw_e', 'raw_g' ) as $raw ) {
			$suffix             = '_' . str_replace( '_', '', $raw ) . '.php'; // raw_p → _rawp.php
			$files              = glob( $view_dir . $raw . '/' . $date_hyphen . '_*' . $suffix );
			$raw_exists[ $raw ] = ! empty( $files );
		}

		// (1) 行動カラム: raw 不在グループに非ゼロ実値が残っていないか。
		foreach ( self::LEGACY_BACKFILL_RAW_GROUPS as $raw => $columns ) {
			if ( $raw_exists[ $raw ] ) {
				continue; // 入力在＝再算出される＝劣化なし。
			}
			foreach ( $columns as $column ) {
				$col_file = $allpv_dir . $ym . '/allpv_' . $date_ymd . '_' . $column . '.php';
				if ( ! file_exists( $col_file ) ) {
					continue; // 列ファイル無し＝失うもの無し。
				}
				$content = file_get_contents( $col_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Binary_IO と同じ低レベル層（列DBファイル読み）。
				if ( false === $content ) {
					return false; // 読めない＝安全側（skip）。
				}
				$data = (string) substr( $content, QAHM_ColumnDB_BinaryIO::HEADER_SIZE );
				if ( '' !== trim( $data, "\0" ) ) {
					return false; // 非ゼロ実値あり＝再変換で 0 に上書きされる。
				}
			}
		}

		// (2) click/datalayer のデシンク防止（'all' は再 merge＝raw 非依存ゆえ対象外）。
		if ( 'all' !== $tracking_id ) {
			if ( ! $raw_exists['raw_c'] ) {
				$click_files = glob( $click_dir . $ym . '/click_event_' . $date_ymd . '_*.php' );
				if ( ! empty( $click_files ) ) {
					return false;
				}
			}
			if ( ! $raw_exists['raw_g'] ) {
				$dl_files = glob( $dl_dir . $ym . '/datalayer_event_' . $date_ymd . '_*.php' );
				if ( ! empty( $dl_files ) ) {
					return false;
				}
			}
		}

		return true;
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
		if ( ! QAHM_ColumnDB_Manifest::is_day_done( $allpv_dir, 'allpv', $date_ymd ) ) {
			// 中断痕跡（converting のまま部分列ファイルが残る日）は削除してから再変換する。
			// Writer は追記方式のため、部分ファイルに再変換すると行が二重化する（Issue #1279）
			$removed = QAHM_ColumnDB_Manifest::cleanup_partial_day( $allpv_dir, 'allpv', $date_ymd );
			if ( $removed > 0 && $qahm_log ) {
				$qahm_log->info( 'ColumnDB allpv: removed ' . $removed . ' partial column files for ' . $date_ymd . ' before reconvert' );
			}
			// Issue #1283 (P1.5): 入力を view_pv 日次ファイルから qa_pv_log 直読みへ切替。
			// 行集合・順序・列値は view_pv 日次ファイルと等価（load_all_records_from_pvlog 参照）
			$all_records = $this->load_all_records_from_pvlog( $tracking_id, $date_hyphen );
			if ( ! empty( $all_records ) ) {
				// 変換開始を manifest に記録（中断すると converting のまま残り、次回再変換される）
				QAHM_ColumnDB_Manifest::mark_converting( $allpv_dir, $date_ymd, count( $all_records ) );

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

				// Writer & SessionCounter を初期化（T85-F: 採番カウンタは月別 uint64 1 個）
				$writer   = new QAHM_ColumnDB_Writer( 'allpv', $tracking_id, $allpv_dir );
				$sessions = new QAHM_ColumnDB_SessionCounter( $tracking_id, $year_month, $allpv_dir );
				$next_seq = $sessions->get_next_seq();

				// セッション境界の追跡用: 計測時に確定した qa_pv_log.session_no をそのまま使う（Issue #1285 / 案X）。
				// 旧来の access_time 生差分 30 分則は、計測時判定（readers/temp の最終ビーコン受信時刻基準 =
				// class-qahm-behavioral-data.php の readers/temp タイムアウト）と稀に乖離して過分割していた（実測 0.1%）。
				// session_no は計測時のセッション番号そのものなので、reader_id × session_no のグループ = 計測時と同一の境界。
				// session_no は日が変わると 1 にリセットされるため、日またぎセッションの「翌日こぼれ行」と翌日の
				// 新セッションが同番号でキー衝突しうる → pv=1（計測セッションの先頭行に正確に 1 回だけ出る）を
				// 強制新規採番として残し、衝突時はマップを上書きして正しく分割する
				$session_id_map = array(); // "{reader_id}|{session_no}" => session_id（session_no の ?? 0 は来ない前提の防御値）

				// ========================================
				// パス1: セッション判定 + 行データ構築 + セッション別インデックス蓄積
				// ========================================
				$session_seq = array();
				$rows        = array();

				foreach ( $all_records as $i => $record ) {
					$reader_id   = $record['reader_id'] ?? 0;
					$pv          = (int) ( $record['pv'] ?? 1 );
					$access_time = (int) ( $record['access_time'] ?? 0 );
					$session_key = $reader_id . '|' . (int) ( $record['session_no'] ?? 0 );

					if ( 1 === $pv || ! isset( $session_id_map[ $session_key ] ) ) {
						// T85-F: メモリ上の ++seq で採番。compose は ym × 10^9 + seq。
						$session_id_map[ $session_key ] = $sessions->compose_session_id( $next_seq );
						$next_seq++;
					}

					$session_id = $session_id_map[ $session_key ];

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
						'window_inner_width'     => (int) ( $beh['window_inner_width'] ?? 0 ),
						'window_inner_height'    => (int) ( $beh['window_inner_height'] ?? 0 ),
						'prev_page_id'           => 0,
						'next_page_id'           => 0,
						// Issue #1462: is_raw_p/c/e は qa_pv_log 由来（$record・get_pv_log と同一値）。is_raw_g は follow-up。
						'is_raw_p'               => (int) ( $record['is_raw_p'] ?? 0 ),
						'is_raw_c'               => (int) ( $record['is_raw_c'] ?? 0 ),
						'is_raw_e'               => (int) ( $record['is_raw_e'] ?? 0 ),
					);

					$session_seq[ $session_id ][] = $i;
				}

				// Phase 0メモリ解放
				unset( $behavioral_map );

				// ========================================
				// T85-F: session_id 採番が完了した時点で counter を永続化する。
				// この後の writer 書き込み中に例外/中断が起きても counter は前進
				// 済みのため、再実行で _session_id.php がスキップされる一方で
				// 翌日以降の同月内 session_id 重複を防ぐ
				// （Copilot review #3206596299 対応）。
				// ========================================
				$sessions->set_next_seq( $next_seq );
				$sessions->close();

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

				// ファイナライズ（counter は採番完了直後に永続化済み。設計書 §2.5 + 巻き戻り対策）
				if ( $writer->finalize() ) {
					// 変換完了を manifest に記録（rows = 実書き込み行数）
					if ( ! QAHM_ColumnDB_Manifest::mark_done( $allpv_dir, $date_ymd, $processed ) && $qahm_log ) {
						// done 未記録のままだと次回サイレント再変換になるため痕跡を残す
						$qahm_log->warning( 'ColumnDB allpv: mark_done failed for ' . $date_ymd );
					}
				} elseif ( $qahm_log ) {
					// converting のまま残し、翌晩の自動再変換に委ねる
					$qahm_log->info( 'ColumnDB allpv: finalize failed for ' . $date_ymd . ' — left as converting for retry' );
				}

				if ( $qahm_log ) {
					$qahm_log->debug( 'ColumnDB allpv converted: ' . $tracking_id . ' / ' . $date_ymd . ' (' . $processed . ' records)' );
				}
			}
		}

		// allpvマップ構築: pv_id → session_id, page_id（Phase C, D共通で使用）
		$allpv_pv_file      = $allpv_dir . $year_month . '/allpv_' . $date_ymd . '_pv_id.php';
		$allpv_session_file = $allpv_dir . $year_month . '/allpv_' . $date_ymd . '_session_id.php';
		$allpv_page_file    = $allpv_dir . $year_month . '/allpv_' . $date_ymd . '_page_id.php';

		$allpv_pv_ids      = QAHM_ColumnDB_BinaryIO::read_uint32_array( $allpv_pv_file );
		$allpv_session_ids = QAHM_ColumnDB_BinaryIO::read_uint64_array( $allpv_session_file );
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

		if ( QAHM_ColumnDB_Manifest::is_day_done( $click_dir, 'click_event', $date_ymd ) ) {
			// 処理済み — スキップ
		} elseif ( empty( $rawc_files ) ) {
			// rawcファイルなし — スキップ
		} elseif ( $pv_to_session === null ) {
			if ( $qahm_log ) {
				$qahm_log->debug( 'ColumnDB click_event: allpv data not available for ' . $date_ymd );
			}
			QAHM_ColumnDB_BinaryIO::write_file( $click_check, '' );
			QAHM_ColumnDB_Manifest::mark_done( $click_dir, $date_ymd, 0 );
		} else {
			// 中断痕跡（部分列ファイル）があれば削除してから再変換（Issue #1279）
			QAHM_ColumnDB_Manifest::cleanup_partial_day( $click_dir, 'click_event', $date_ymd );
			// 変換開始を manifest に記録
			QAHM_ColumnDB_Manifest::mark_converting( $click_dir, $date_ymd );

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
						// T113 (#1477): 辞書IDの 65535 クランプは撤去（uint32 拡幅＝辞書IDは正値のまま格納）
						$transition  = $fields[3] ?? '';
						$to_url_id   = 0;
						$is_external = 0;
						if ( ! empty( $transition ) ) {
							$to_url_id = $dict_urls->get_or_create( $transition );
							if ( strpos( $transition, '/' ) === 0 || ( $domain !== '' && ( strpos( $transition, $domain_https ) === 0 || strpos( $transition, $domain_http ) === 0 ) ) ) {
								$is_external = 0;
							} else {
								$is_external = 1;
							}
						}

						// field[5-8]: 各属性を辞書IDに変換（空文字→0）
						$element_text_id  = $dict_element_texts->get_or_create( $fields[5] ?? '' );
						$element_id_id    = $dict_element_ids->get_or_create( $fields[6] ?? '' );
						$element_class_id = $dict_element_classes->get_or_create( $fields[7] ?? '' );
						$element_data_id  = $dict_element_data->get_or_create( $fields[8] ?? '' );

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
			$click_finalized = $click_writer->finalize();

			// 有効行0件の場合、空マーカーファイルを書いて再処理を防止
			if ( $click_processed === 0 && ! file_exists( $click_check ) ) {
				QAHM_ColumnDB_BinaryIO::write_file( $click_check, '' );
			}

			if ( $click_finalized ) {
				// 変換完了を manifest に記録（rows = 実書き込み行数）
				if ( ! QAHM_ColumnDB_Manifest::mark_done( $click_dir, $date_ymd, $click_processed ) && $qahm_log ) {
					$qahm_log->warning( 'ColumnDB click_event: mark_done failed for ' . $date_ymd );
				}
			} elseif ( $qahm_log ) {
				// converting のまま残し、翌晩の自動再変換に委ねる
				$qahm_log->info( 'ColumnDB click_event: finalize failed for ' . $date_ymd . ' — left as converting for retry' );
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

		// datalayer_eventが変換完了済みならスキップ（manifest優先・converting残置日は再変換）
		if ( QAHM_ColumnDB_Manifest::is_day_done( $dl_dir, 'datalayer_event', $date_ymd ) ) {
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
			QAHM_ColumnDB_Manifest::mark_done( $dl_dir, $date_ymd, 0 );
			return $processed > 0 || $click_processed > 0;
		}

		// 中断痕跡（部分列ファイル）があれば削除してから再変換（Issue #1279）
		QAHM_ColumnDB_Manifest::cleanup_partial_day( $dl_dir, 'datalayer_event', $date_ymd );
		// 変換開始を manifest に記録
		QAHM_ColumnDB_Manifest::mark_converting( $dl_dir, $date_ymd );

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

					// T113 (#1477): 辞書IDの 65535 クランプは撤去（uint32 拡幅＝辞書IDは正値のまま格納）
					$row = array(
						'pv_id'         => $pv_id,
						'session_id'    => $session_id,
						'page_id'       => $page_id,
						'event_name_id' => $dict_event_names->get_or_create( $event_name ),
						'params_id'     => $dict_params_json->get_or_create( $params_json ),
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
		$dl_finalized = $dl_writer->finalize();

		// 有効行0件の場合、空マーカーファイルを書いて再処理を防止
		if ( $dl_processed === 0 && ! file_exists( $dl_check ) ) {
			QAHM_ColumnDB_BinaryIO::write_file( $dl_check, '' );
		}

		if ( $dl_finalized ) {
			// 変換完了を manifest に記録（rows = Layer 1 実書き込み行数）
			if ( ! QAHM_ColumnDB_Manifest::mark_done( $dl_dir, $date_ymd, $dl_processed ) && $qahm_log ) {
				$qahm_log->warning( 'ColumnDB datalayer_event: mark_done failed for ' . $date_ymd );
			}
		} elseif ( $qahm_log ) {
			// converting のまま残し、翌晩の自動再変換に委ねる
			$qahm_log->info( 'ColumnDB datalayer_event: finalize failed for ' . $date_ymd . ' — left as converting for retry' );
		}

		if ( $qahm_log ) {
			$qahm_log->debug( 'ColumnDB datalayer_event converted: ' . $tracking_id . ' / ' . $date_ymd . ' (L1: ' . $dl_processed . ' events, L2: ' . $l2_rows_total . ' rows / ' . $l2_tables_count . ' tables)' );
		}

		return $processed > 0 || $click_processed > 0 || $dl_processed > 0;
	}

	/**
	 * qa_pv_log から allpv 変換用の日次レコードを直読みする（Issue #1283 / Epic #1256 P1.5）
	 *
	 * view_pv 日次ファイルと等価な行集合・順序・値を返す:
	 * - 行集合: qa_pages.tracking_id = $tracking_id の行（'all' は全行）× access_time が当日
	 *   （WP ローカル TZ の 00:00:00〜23:59:59。view_pv 生成の SELECT と同じ BETWEEN 比較）
	 * - 順序: pv_id 昇順（view_pv ファイル内の格納順と同一。呼び出し側の access_time usort も従来どおり適用）
	 * - 値: allpv 変換が使用する 13 列 + version_id。access_time は unix timestamp（view_pv と同形）
	 *
	 * version_id は qa_page_version_hist 突合で算出する（view_pv 生成 cron-proc と同式）。
	 * ※ qa_pv_log.version_id 列は存在するが全行 NULL（2026-06-12 dev5/caddy 実測）のため使用しない。
	 *
	 * @param string $tracking_id 追跡ID（'all' は全サイト統合）
	 * @param string $date_hyphen 日付（YYYY-MM-DD形式）
	 * @return array view_pv 由来と等価な $all_records（データ無し日は空配列）
	 */
	private function load_all_records_from_pvlog( $tracking_id, $date_hyphen ) {
		global $wpdb, $qahm_time;

		$pvlog_table = $wpdb->prefix . 'qa_pv_log';
		$pages_table = $wpdb->prefix . 'qa_pages';
		$s_datetime  = $date_hyphen . ' 00:00:00';
		$e_datetime  = $date_hyphen . ' 23:59:59';

		// Issue #1462: is_raw_p/c/e を qa_pv_log から取得（allpv 列 is_raw_* の焼き込み用。get_pv_log と同一ソース＝ヒートマップ等価）。
		$select_cols = 'pl.pv_id, pl.reader_id, pl.page_id, pl.device_id, pl.source_id, pl.medium_id, pl.campaign_id, pl.session_no, pl.access_time, pl.pv, pl.speed_msec, pl.browse_sec, pl.is_last, pl.is_newuser, pl.is_raw_p, pl.is_raw_c, pl.is_raw_e';
		if ( 'all' === $tracking_id ) {
			// 'all' は全サイトの行（view/all/view_pv と同じ行集合 = qa_pages 不在の行も含む）
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$select_cols} FROM `{$pvlog_table}` pl WHERE pl.access_time BETWEEN %s AND %s ORDER BY pl.pv_id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix . 'qa_pv_log', columns are a fixed list
					$s_datetime,
					$e_datetime
				)
			);
		} else {
			// tid 別の行集合は qa_pages.tracking_id 由来（view_pv 生成の $t_newary 分配と同じ規則）
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$select_cols} FROM `{$pvlog_table}` pl INNER JOIN `{$pages_table}` pg ON pg.page_id = pl.page_id WHERE pg.tracking_id = %s AND pl.access_time BETWEEN %s AND %s ORDER BY pl.pv_id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are $wpdb->prefix based, columns are a fixed list
					$tracking_id,
					$s_datetime,
					$e_datetime
				)
			);
		}
		if ( empty( $rows ) ) {
			return array();
		}

		// version_id 突合用に qa_page_version_hist をバルク取得（page_id チャンク分割は content_id 取得と同手法）
		$page_ids = array();
		foreach ( $rows as $r ) {
			$pid = (int) $r->page_id;
			if ( $pid > 0 ) {
				$page_ids[ $pid ] = true;
			}
		}
		$version_data = array();
		if ( ! empty( $page_ids ) ) {
			$hist_table = $wpdb->prefix . 'qa_page_version_hist';
			$chunks     = array_chunk( array_keys( $page_ids ), 50000 );
			foreach ( $chunks as $chunk ) {
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
				$sql          = $wpdb->prepare(
					"SELECT version_id, page_id, device_id, version_no, insert_datetime FROM `{$hist_table}` WHERE page_id IN ({$placeholders}) ORDER BY page_id, version_id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $hist_table is $wpdb->prefix based, $placeholders is array_fill of %d
					$chunk
				);
				$hist         = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is from $wpdb->prepare() above
				foreach ( (array) $hist as $h ) {
					$h->insert_unixtime = $qahm_time->str_to_unixtime( $h->insert_datetime );
					$version_data[ $h->page_id ][ $h->device_id ][] = $h;
				}
			}
		}

		$all_records = array();
		foreach ( $rows as $r ) {
			$lp_time = $qahm_time->str_to_unixtime( $r->access_time );

			// version_id: version_id DESC（新→旧）走査で「version_no==1（初期版）または PV 時点で存在した版」の
			// 最初のマッチを採用（cron-proc の view_pv 生成と同式）
			$version_id = 0;
			if ( isset( $version_data[ $r->page_id ][ $r->device_id ] ) ) {
				foreach ( $version_data[ $r->page_id ][ $r->device_id ] as $vidary ) {
					if ( 1 == $vidary->version_no || $vidary->insert_unixtime <= $lp_time ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- version_no は wpdb の string、view_pv 生成と同じ緩比較
						$version_id = (int) $vidary->version_id;
						break;
					}
				}
			}

			$all_records[] = array(
				'pv_id'       => $r->pv_id,
				'reader_id'   => $r->reader_id,
				'page_id'     => $r->page_id,
				'device_id'   => $r->device_id,
				'source_id'   => $r->source_id,
				'medium_id'   => $r->medium_id,
				'campaign_id' => $r->campaign_id,
				'session_no'  => $r->session_no,
				'access_time' => $lp_time,
				'pv'          => $r->pv,
				'speed_msec'  => $r->speed_msec,
				'browse_sec'  => $r->browse_sec,
				'is_last'     => $r->is_last,
				'is_newuser'  => $r->is_newuser,
				'version_id'  => $version_id,
				// Issue #1462: qa_pv_log の is_raw フラグ（allpv 列へ焼き込み・get_pv_log と同一値）。
				'is_raw_p'    => (int) ( $r->is_raw_p ?? 0 ),
				'is_raw_c'    => (int) ( $r->is_raw_c ?? 0 ),
				'is_raw_e'    => (int) ( $r->is_raw_e ?? 0 ),
			);
		}
		return $all_records;
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
		$pv_submit  = array(); // pv_id => is_submit(0|1)（T108: raw_p ヘッダー由来）
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
					$parsed = $this->parse_rawp_tsv( $raw_p );
					$rows   = $parsed['rows'];
					$pv_rawp[ $pv_id ] = $rows;
					// T108: is_submit は submit イベント観測由来の PV フラグ。同PVで複数
					// ビーコン（複数 raw_p エントリ）があっても、一度でも 1 なら 1。
					if ( ! empty( $parsed['is_submit'] ) ) {
						$pv_submit[ $pv_id ] = 1;
					}

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

			$rawp_rows  = $pv_rawp[ $pv_id ] ?? array();
			$rawc_rows  = $pv_rawc[ $pv_id ] ?? array();
			$rawe_entry = $pv_rawe[ $pv_id ] ?? array();
			$rawe_rows  = $rawe_entry['rows'] ?? array();

			// C-1: raw_p由来 4カラム（depth_position/deep_read/stop_max_sec/stop_max_pos）
			$c1 = $this->calc_rawp_columns( $rawp_rows, $page_height );

			// C-2: raw_c由来 2カラム（T108: is_submit は raw_c から分離。dead_click/irritation のみ）
			$c2 = $this->calc_rawc_columns( $rawc_rows );

			// C-3: raw_e由来 3カラム + 中間値 exit_scroll_y（生px、's'不在は -1）
			$c3 = $this->calc_rawe_columns( $rawe_rows );

			// C-4: raw_e ヘッダー由来のウィンドウサイズ
			$inner_h = (int) ( $rawe_entry['h'] ?? 0 );
			$c4 = array(
				'window_inner_width'  => (int) ( $rawe_entry['w'] ?? 0 ),
				'window_inner_height' => $inner_h,
			);

			// exit_pos（T109）: 離脱位置は raw_e 時系列末尾の scrollTop(px) を中心換算した%。
			// depth_position（raw_p の空間的最大到達）とはデータ源を物理分離した独立値。
			// - 中心換算: raw_p の STAY_HEIGHT は画面中央Y基準、scrollTop はビューポート最上端。
			//   軸を揃えるため exit_center_px = scrollTop + inner_h/2 としてから depth_position と
			//   同じ page_height で%換算する（換算段はここ＝depth_position の page_height 適用と対称）。
			// - 's' 不在（exit_scroll_y < 0）→ exit_pos = 0（uint8 既定値、単一データ源の一本道。
			//   depth_position へはフォールバックしない）。
			// - inner_h=0（ヘッダ欠損）は中心換算不能ゆえ scrollTop 素通しで換算（稀ケースの縮退）。
			// - page_height は上流(:1663-1665)で > 0 が保証済み（depth_position と同流儀）。
			$exit_scroll_y = $c3['exit_scroll_y'];
			unset( $c3['exit_scroll_y'] ); // 中間値。物理カラムには漏らさない。
			if ( $exit_scroll_y < 0 ) {
				$exit_pos = 0;
			} else {
				$exit_center_px = ( $inner_h > 0 ) ? ( $exit_scroll_y + $inner_h / 2 ) : $exit_scroll_y;
				$exit_pos       = min( (int) ( $exit_center_px * 100 / $page_height ), 100 );
			}

			// T108: is_submit は raw_p ヘッダー（submit イベント観測）由来の PV フラグ。
			// クリック行（action_id）からは導出しない。Enter 送信もここで 1 になる。
			$c5 = array(
				'is_submit' => (int) ( $pv_submit[ $pv_id ] ?? 0 ),
			);

			$behavioral_map[ $pv_id ] = $c1 + $c2 + $c3 + $c4 + $c5 + array( 'exit_pos' => $exit_pos );
		}

		return $behavioral_map;
	}

	/**
	 * raw_p TSV文字列をパース
	 *
	 * 1行目はヘッダー: version\tis_submit（T108・is_submit は無い旧データもある）。
	 * 2行目以降が STAY_HEIGHT\tSTAY_TIME。
	 *
	 * @param string $raw_p TSV文字列
	 * @return array { 'is_submit' => int(0|1), 'rows' => [[stay_height(int), stay_time(int)], ...] }
	 */
	private function parse_rawp_tsv( $raw_p ) {
		$lines     = explode( "\n", $raw_p );
		$rows      = array();
		$is_submit = 0;
		foreach ( $lines as $idx => $line ) {
			if ( trim( $line ) === '' ) {
				continue;
			}
			if ( $idx === 0 ) {
				// ヘッダー: index1 の is_submit を取り出す（旧データは欠落→0）。
				$header = explode( "\t", $line );
				if ( isset( $header[1] ) && (int) $header[1] === 1 ) {
					$is_submit = 1;
				}
				continue;
			}
			$fields = explode( "\t", $line );
			if ( ! isset( $fields[0] ) || $fields[0] === 'a' ) {
				continue; // 'a' はアクティブマーカー、スキップ
			}
			$stay_height = (int) $fields[0];
			$stay_time   = isset( $fields[1] ) ? (int) $fields[1] : 0;
			$rows[]      = array( $stay_height, $stay_time );
		}
		return array(
			'is_submit' => $is_submit,
			'rows'      => $rows,
		);
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
	 * @return array { 'w' => int, 'h' => int, 'rows' => array } ヘッダーのW/Hと本文行
	 */
	private function parse_rawe_tsv( $raw_e ) {
		$lines  = explode( "\n", $raw_e );
		$w      = 0;
		$h      = 0;
		$rows   = array();
		foreach ( $lines as $idx => $line ) {
			if ( trim( $line ) === '' ) {
				continue;
			}
			if ( $idx === 0 ) {
				$header = explode( "\t", $line );
				// DATA_EVENT_1: WINDOW_INNER_W=1, WINDOW_INNER_H=2
				$w = isset( $header[1] ) ? (int) $header[1] : 0;
				$h = isset( $header[2] ) ? (int) $header[2] : 0;
				if ( $w < 0 || $w > 65535 ) {
					$w = 0;
				}
				if ( $h < 0 || $h > 65535 ) {
					$h = 0;
				}
				continue;
			}
			$rows[] = explode( "\t", $line );
		}
		return array(
			'w'    => $w,
			'h'    => $h,
			'rows' => $rows,
		);
	}

	/**
	 * C-1: raw_p由来の4カラムを算出
	 *
	 * raw_pは「位置→滞在時間」ヒストグラムで時系列順を持たない。ゆえに
	 * depth_position（空間的最大到達）は算出できるが、離脱位置（時系列的な
	 * 最終視認位置）は算出できない。exit_pos は raw_e 時系列末尾から
	 * calc_rawe_columns + Step 0-e で別途算出する（T109・データ源を物理分離）。
	 *
	 * @param array $rows [[stay_height, stay_time], ...] パース済みraw_p
	 * @param int   $page_height ページ推定高さ（page_height_map値）
	 * @return array 4カラム連想配列
	 */
	private function calc_rawp_columns( $rows, $page_height ) {
		$result = array(
			'depth_position' => 0,
			'deep_read'      => 0,
			'stop_max_sec'   => 0,
			'stop_max_pos'   => 0,
		);

		if ( empty( $rows ) ) {
			return $result;
		}

		$max_height      = 0;
		$deep_read_count = 0;
		$stop_max_sec    = 0;
		$stop_max_height = 0;

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
		}

		$result['depth_position'] = min( (int) ( $max_height * 100 * 100 / $page_height ), 100 );
		$result['deep_read']      = ( $deep_read_count >= 5 ) ? 1 : 0;
		$result['stop_max_sec']   = min( $stop_max_sec, 65535 );
		$result['stop_max_pos']   = min( (int) ( $stop_max_height * 100 * 100 / $page_height ), 100 );

		return $result;
	}

	/**
	 * C-2: raw_c由来の2カラムを算出
	 *
	 * T108: is_submit はここから分離した（旧: action_id==2 の OR 集約）。
	 * is_submit は raw_p ヘッダーの submit イベント観測フラグから供給する。
	 * 本メソッドは dead_click_image / irritation_click の責務のみを持つ。
	 *
	 * @param array $rows パース済みraw_c行（各行はフィールド配列）
	 * @return array 2カラム連想配列
	 */
	private function calc_rawc_columns( $rows ) {
		$result = array(
			'dead_click_image_count' => 0,
			'irritation_click_count' => 0,
		);

		if ( empty( $rows ) ) {
			return $result;
		}

		$event_secs = array();

		foreach ( $rows as $fields ) {
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
	 * C-3: raw_e由来のスクロール/マウス3カラム + 離脱スクロール位置(中間値)を算出
	 *
	 * exit_scroll_y は「時系列的な最終視認位置」の生スクロールtop(px)であり、
	 * depth_position（raw_p 由来の空間的最大到達）とはデータ源・演算・意味が
	 * すべて独立している（T109・D5）:
	 *   - depth_position = 空間的最大到達（raw_p ヒストグラムの max、画面中央%）
	 *   - exit_pos       = 時系列的な最終視認位置（raw_e 時系列末尾 's' の scrollTop）
	 * 両者の一致は「最深点で離脱した」「スクロール未発火」の真に等しいPVでのみ発生する。
	 *
	 * raw_e は保存前に TIME 昇順ソート済み（cron-proc.php:870-887）なので、
	 * 既存の1パスで構築する $scroll_events の末尾要素が最新スクロール＝離脱位置となる。
	 * これは生px(int)。's' サンプルが1件も無い場合は sentinel -1 を返し、
	 * px→% の中心換算は呼び出し側 Step 0-e が担う（page_height は raw_p 由来の尺度）。
	 *
	 * @param array $rows パース済みraw_e行（各行はフィールド配列）
	 * @return array 3カラム + 'exit_scroll_y'(px, 's'不在は -1) 連想配列
	 */
	private function calc_rawe_columns( $rows ) {
		$result = array(
			'scroll_back_count'  => 0,
			'content_skip_count' => 0,
			'exploration_count'  => 0,
			'exit_scroll_y'      => -1,
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

		// exit_scroll_y: 時系列末尾の 's' スクロール位置(px)。TIME昇順ソート済みなので
		// $scroll_events の末尾＝最新スクロール＝離脱位置。's' 不在なら -1 のまま。
		if ( ! empty( $scroll_events ) ) {
			$last_scroll = end( $scroll_events );
			$result['exit_scroll_y'] = $last_scroll[1];
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

		// 既に変換完了済みなら何もしない（manifest優先・converting残置日は再統合 / Issue #1279）
		if ( QAHM_ColumnDB_Manifest::is_day_done( $all_click_dir, 'click_event', $date_ymd ) ) {
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
		$all_allpv_session_ids = QAHM_ColumnDB_BinaryIO::read_uint64_array( $all_allpv_session_file );
		$all_allpv_page_ids    = QAHM_ColumnDB_BinaryIO::read_uint32_array( $all_allpv_page_file );

		if ( $all_allpv_pv_ids === false || $all_allpv_session_ids === false || $all_allpv_page_ids === false ) {
			if ( $qahm_log ) {
				$qahm_log->info( 'ColumnDB click_event (all): all/allpv data not available for ' . $date_ymd . ' — skip merge' );
			}
			QAHM_ColumnDB_BinaryIO::write_file( $all_check, '' );
			QAHM_ColumnDB_Manifest::mark_done( $all_click_dir, $date_ymd, 0 );
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

		// 中断痕跡（部分列ファイル）があれば削除してから再統合（Issue #1279）
		QAHM_ColumnDB_Manifest::cleanup_partial_day( $all_click_dir, 'click_event', $date_ymd );
		// 統合開始を manifest に記録
		QAHM_ColumnDB_Manifest::mark_converting( $all_click_dir, $date_ymd );

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
			// T113 (#1477): 拡幅5列は per-tid 側に旧幅（uint16）ファイルが実在しうる
			// ＝read_column_auto（pv_id 行数からの幅自己判定）で読む。selector_id は元から uint32＝従来どおり
			$site_selector_ids = QAHM_ColumnDB_BinaryIO::read_uint32_array( $site_click . $year_month . '/click_event_' . $date_ymd . '_selector_id.php' );
			$site_text_ids     = QAHM_ColumnDB_BinaryIO::read_column_auto( $site_click . $year_month . '/click_event_' . $date_ymd . '_element_text_id.php', $pv_file );
			$site_id_ids       = QAHM_ColumnDB_BinaryIO::read_column_auto( $site_click . $year_month . '/click_event_' . $date_ymd . '_element_id_id.php', $pv_file );
			$site_class_ids    = QAHM_ColumnDB_BinaryIO::read_column_auto( $site_click . $year_month . '/click_event_' . $date_ymd . '_element_class_id.php', $pv_file );
			$site_data_ids     = QAHM_ColumnDB_BinaryIO::read_column_auto( $site_click . $year_month . '/click_event_' . $date_ymd . '_element_data_id.php', $pv_file );
			$site_to_url_ids   = QAHM_ColumnDB_BinaryIO::read_column_auto( $site_click . $year_month . '/click_event_' . $date_ymd . '_to_url_id.php', $pv_file );

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
			// T113 (#1477): uint32 拡幅により 65535 クランプは撤去済み（build_dict_remap から cap 機構ごと削除）
			$site_selectors_path = $data_dir . 'view/' . $tid . '/global-selectors-dict.php';
			$map_selector = $this->build_dict_remap( $site_selectors_path, $all_selectors_dict );
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
		$all_finalized = $all_writer->finalize();

		if ( $total === 0 && ! file_exists( $all_check ) ) {
			QAHM_ColumnDB_BinaryIO::write_file( $all_check, '' );
		}

		if ( $all_finalized ) {
			// 統合完了を manifest に記録（rows = 統合行数）
			if ( ! QAHM_ColumnDB_Manifest::mark_done( $all_click_dir, $date_ymd, $total ) && $qahm_log ) {
				$qahm_log->warning( 'ColumnDB click_event (all): mark_done failed for ' . $date_ymd );
			}
		} elseif ( $qahm_log ) {
			// converting のまま残し、翌晩の自動再統合に委ねる
			$qahm_log->info( 'ColumnDB click_event (all): finalize failed for ' . $date_ymd . ' — left as converting for retry' );
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

		// 既に変換完了済みなら何もしない（manifest優先・converting残置日は再統合 / Issue #1279）
		if ( QAHM_ColumnDB_Manifest::is_day_done( $all_dl_dir, 'datalayer_event', $date_ymd ) ) {
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
		$all_allpv_session_ids = QAHM_ColumnDB_BinaryIO::read_uint64_array( $all_allpv_session_file );
		$all_allpv_page_ids    = QAHM_ColumnDB_BinaryIO::read_uint32_array( $all_allpv_page_file );

		if ( $all_allpv_pv_ids === false || $all_allpv_session_ids === false || $all_allpv_page_ids === false ) {
			if ( $qahm_log ) {
				$qahm_log->info( 'ColumnDB datalayer_event (all): all/allpv data not available for ' . $date_ymd . ' — skip merge' );
			}
			QAHM_ColumnDB_BinaryIO::write_file( $all_check, '' );
			QAHM_ColumnDB_Manifest::mark_done( $all_dl_dir, $date_ymd, 0 );
			return 0;
		}
		$pv_to_session_all = array_combine( $all_allpv_pv_ids, $all_allpv_session_ids );
		$pv_to_page_all    = array_combine( $all_allpv_pv_ids, $all_allpv_page_ids );
		unset( $all_allpv_pv_ids, $all_allpv_session_ids, $all_allpv_page_ids );

		// Layer 1 辞書
		$all_dict_events = new QAHM_ColumnDB_Dictionary( $all_dl_dir . 'dict-event-names.php' );
		$all_dict_params = new QAHM_ColumnDB_Dictionary( $all_dl_dir . 'dict-params-json.php' );

		// 中断痕跡（部分列ファイル）があれば削除してから再統合（Issue #1279）
		QAHM_ColumnDB_Manifest::cleanup_partial_day( $all_dl_dir, 'datalayer_event', $date_ymd );
		// 統合開始を manifest に記録
		QAHM_ColumnDB_Manifest::mark_converting( $all_dl_dir, $date_ymd );

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
			// T113 (#1477): 拡幅2列は per-tid 側に旧幅（uint16）ファイルが実在しうる＝幅自己判定で読む
			$ev_ids     = QAHM_ColumnDB_BinaryIO::read_column_auto( $site_dl . $year_month . '/datalayer_event_' . $date_ymd . '_event_name_id.php', $pv_file );
			$params_ids = QAHM_ColumnDB_BinaryIO::read_column_auto( $site_dl . $year_month . '/datalayer_event_' . $date_ymd . '_params_id.php', $pv_file );

			if ( $ev_ids === false || $params_ids === false ) {
				if ( $qahm_log ) {
					$qahm_log->info( 'ColumnDB datalayer_event (all): site ' . $tid . ' has corrupted column file for ' . $date_ymd . ' — skip site' );
				}
				continue;
			}

			// T113 (#1477): uint32 拡幅により 65535 クランプは撤去済み（build_dict_remap から cap 機構ごと削除）
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
		$all_finalized = $all_writer->finalize();

		if ( $total === 0 && ! file_exists( $all_check ) ) {
			QAHM_ColumnDB_BinaryIO::write_file( $all_check, '' );
		}

		if ( $all_finalized ) {
			// 統合完了を manifest に記録（rows = Layer 1 統合行数）
			if ( ! QAHM_ColumnDB_Manifest::mark_done( $all_dl_dir, $date_ymd, $total ) && $qahm_log ) {
				$qahm_log->warning( 'ColumnDB datalayer_event (all): mark_done failed for ' . $date_ymd );
			}
		} elseif ( $qahm_log ) {
			// converting のまま残し、翌晩の自動再統合に委ねる
			$qahm_log->info( 'ColumnDB datalayer_event (all): finalize failed for ' . $date_ymd . ' — left as converting for retry' );
		}

		if ( $qahm_log ) {
			$qahm_log->debug( 'ColumnDB datalayer_event (all) merged: ' . $date_ymd . ' (' . $total . ' events)' );
		}

		return $total;
	}

	/**
	 * T68: 個別サイト辞書を読み込み、all 側辞書に文字列を再採番して old_id → new_id マップを返す
	 *
	 * T113 (#1477): 辞書ID列の uint32 拡幅により 65535 クランプ（$cap_uint16 引数）は撤去。
	 * new_id は正値のまま返す（クランプは ID 衝突＝別要素の合算を生む欠陥だった）。
	 *
	 * @param string                  $site_dict_path 個別サイト辞書ファイルの絶対パス
	 * @param QAHM_ColumnDB_Dictionary $all_dict       all 側辞書（書き込み対象）
	 * @return array old_id => new_id マップ（空文字は 0 → 0）
	 */
	private function build_dict_remap( $site_dict_path, $all_dict ) {
		$map = array( 0 => 0 );
		if ( ! file_exists( $site_dict_path ) ) {
			return $map;
		}
		$site_dict = new QAHM_ColumnDB_Dictionary( $site_dict_path );
		$entries   = $site_dict->get_all_entries();
		foreach ( $entries as $old_id => $str ) {
			$map[ (int) $old_id ] = $all_dict->get_or_create( $str );
		}
		return $map;
	}
}
