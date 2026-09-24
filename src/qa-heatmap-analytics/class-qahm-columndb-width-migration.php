<?php
defined( 'ABSPATH' ) || exit;
/**
 * 列DB 幅移行（T113 #1477）
 *
 * uint16/uint8 で書かれた既存の拡幅対象12列（QAHM_ColumnDB_Schema::WIDENED_UINT32_COLUMNS）を
 * uint32 へ「列単位の in-place 焼き直し」で一括変換する。あわせて allpv の 4列
 * （source_id / medium_id / campaign_id / version_id）は view_pv 日次ファイルの正値で、
 * content_id は qa_pv_log（保持窓内のみ）の正値で焼き直す（silent wrap の修復）。
 * click_event / datalayer_event の辞書列は復元ソースが無いため旧値をそのまま新幅へ移す（決定 B）。
 *
 * 【実行モデル】
 * - 入口は QAHM_Update::check_version()。唯一の呼び出し元は夜間 cron チェーンの
 *   'Common>Check update' 状態＝**cron の flock 内**で実行される（grep 全数確認済み）。
 *   ゆえに本クラスは自前のロックを取らない。cron の当日変換・reconvert 系との
 *   同一ファイル同時書き込みは呼び出し位置により構造的に排除されている。
 *   ※将来 check_version 以外から呼ぶ場合は、呼び出し側が cron の flock を取ること。
 * - 全ファイル一撃の変換は環境により長時間かかるため、**時間予算つきバッチ**で刻む
 *   （max_execution_time × 0.7・既定 20 秒）。月ディレクトリ単位の watermark
 *   （months_done）を永続 meta に持ち、複数チェーン／複数夜にまたがって完走する。
 * - 冪等: 変換前に実ファイルの幅を自己判定（detect_column_type）し、既に uint32 なら
 *   スキップする。中断・再走で二重変換は起きない。
 * - 書き込みは原子的置換（write_file_atomic＝tmp→長さ突合→rename）。読み手は
 *   旧か新かの完全な状態しか見ない（torn read なし）。
 *
 * 【安全条件】
 * - 当日（実行日）の列ファイルは変換対象外（当夜のチェーンが新幅で書く日）。
 * - manifest エントリが存在し done でない日（converting）はスキップ＝cron の再変換が
 *   新幅で書き直すため本移行の出番はない。
 * - 幅の自己判定を信頼してよいのは manifest が done の日だけ（detect_column_type docblock 参照）。
 *
 * 【永久記録（食い違い行数）】
 * 旧値（wrap 済み）と正値の食い違い行数は、移行のこの瞬間にしか計測できない
 * （旧値は上書きで消える）。日×列ごとの件数を meta に永久記録する。
 * meta の置き場は report/ 直下＝夜間 cron の削除フェーズの除外領域（#1474）＝
 * mtime 経過で消されない。
 *
 * @package qa_heatmap
 */

class QAHM_ColumnDB_Width_Migration extends QAHM_File_Data {

	/**
	 * 永続 meta ファイル名（report/ 直下＝削除フェーズの除外領域 #1474）
	 */
	const META_FILE = 'columndb_width_migration.php';

	/**
	 * meta の PHP セキュリティヘッダー（Manifest と同形式）
	 */
	const PHP_HEADER = "<?php http_response_code(404);die();?>\n";

	/**
	 * view_pv 日次ファイルから正値を復元できる allpv 列
	 * （view_pv 行は serialize＝幅制限なし＝ID が wrap せず正値のまま格納されている）
	 */
	const RESTORE_FROM_VIEWPV = array( 'source_id', 'medium_id', 'campaign_id', 'version_id' );

	/**
	 * 実行1回あたりの時間予算の既定値（秒）。max_execution_time が取れない環境用
	 */
	const DEFAULT_BUDGET_SEC = 20;

	/**
	 * 日単位キャッシュ（view_pv 正値マップ・qa_pv_log content_id マップ・pv_id 配列）
	 */
	private $cache_key     = '';
	private $cache_viewpv  = array();
	private $cache_content = null;
	private $cache_pvids   = null;

	/**
	 * qa_pv_log の保持窓（YYYYMMDD の [min, max]）。プロセス内で1回だけ測る。
	 * 窓外の日は content_id のクエリを打たずに carry する（コピー環境 E2E で
	 * 「窓外日の空振りクエリ＝IN 大量 placeholders の full scan」が所要時間の
	 * 支配項と実測されたため。#1440 と同じ教訓）。
	 */
	private $pvlog_window = null;

	// =======================================================================
	// エントリポイント
	// =======================================================================

	/**
	 * 移行を「保留」として開始する（update.php の版数ゲートから1回呼ばれる）
	 *
	 * 冪等: meta が既に存在する（pending/done）場合は何もしない。
	 */
	public static function mark_pending() {
		$inst = new self();
		$meta = $inst->load_meta();
		if ( is_array( $meta ) && isset( $meta['state'] ) ) {
			return; // 既に保留中 or 完了済み
		}
		$saved = $inst->save_meta(
			array(
				'state'       => 'pending',
				'months_done' => array(),
				'diff'        => array(),
				'content_carry' => array(),
				'stats'       => array( 'files' => 0 ),
			)
		);
		global $qahm_log;
		if ( ! $saved ) {
			// meta が書けない（report/ 作成不能等）＝移行が永遠に予約されない。握りつぶさず可視化する
			if ( is_object( $qahm_log ) ) {
				$qahm_log->warning( 'ColumnDB width migration (T113 #1477): mark_pending failed to save meta — migration will NOT run until this is resolved' );
			}
			return;
		}
		if ( is_object( $qahm_log ) ) {
			$qahm_log->info( 'ColumnDB width migration (T113 #1477): marked pending' );
		}
	}

	/**
	 * 保留中なら時間予算内で移行を前進させる（check_version から毎チェーン呼ばれる）
	 *
	 * meta 不在（保留なし）・done は即 return＝通常時のコストは file_exists 1回。
	 */
	public static function run_if_pending() {
		$inst = new self();
		$meta = $inst->load_meta();
		if ( ! is_array( $meta ) || 'pending' !== ( $meta['state'] ?? '' ) ) {
			return;
		}
		$inst->run( $meta );
	}

	// =======================================================================
	// 本体
	// =======================================================================

	/**
	 * 時間予算内で走査・変換を前進させる
	 *
	 * @param array $meta 永続 meta（pending 状態）
	 */
	private function run( array $meta ) {
		global $qahm_log;

		$start_time = microtime( true );
		$budget_sec = $this->calc_budget_sec();
		$today_ymd  = $this->today_ymd();

		$data_dir   = $this->get_data_dir_path();
		$report_dir = $data_dir . 'report/';
		if ( ! is_dir( $report_dir ) ) {
			$meta['state'] = 'done';
			$this->save_meta( $meta );
			return;
		}

		$datasets  = QAHM_ColumnDB_Schema::WIDENED_UINT32_COLUMNS;
		$tids      = $this->list_tracking_dirs( $report_dir );
		$all_clean = true;

		foreach ( $tids as $tid ) {
			foreach ( $datasets as $dataset => $columns ) {
				$base = $report_dir . $tid . '/columns-db/' . $dataset . '/';
				if ( ! is_dir( $base ) ) {
					continue;
				}
				$months = $this->list_month_dirs( $base );
				foreach ( $months as $ym ) {
					$mkey = $tid . '/' . $dataset . '/' . $ym;
					if ( isset( $meta['months_done'][ $mkey ] ) ) {
						continue;
					}

					$result = $this->migrate_month( $tid, $dataset, $columns, $base, $ym, $today_ymd, $meta, $start_time, $budget_sec );

					if ( $result['clean'] ) {
						$meta['months_done'][ $mkey ] = 1;
					} else {
						$all_clean = false;
					}
					// 月ごとに meta を原子保存（進捗と食い違い記録を失わない）
					$this->save_meta( $meta );

					if ( $result['budget_exceeded'] ) {
						if ( is_object( $qahm_log ) ) {
							$qahm_log->info( 'ColumnDB width migration (T113 #1477): budget reached, will resume next run (converted files so far: ' . (int) $meta['stats']['files'] . ')' );
						}
						return;
					}
				}
			}
		}

		if ( $all_clean ) {
			$meta['state'] = 'done';
			$this->save_meta( $meta );
			if ( is_object( $qahm_log ) ) {
				$qahm_log->info( 'ColumnDB width migration (T113 #1477): completed. converted files: ' . (int) $meta['stats']['files'] . ', days with restored values: ' . count( $meta['diff'] ) );
			}
		} else {
			// スキップ（converting 日・破損・書き込み失敗・当日）が残った＝pending のまま次チェーンで再走査。
			// ★無進捗パスを数えて可視化する（恒久 skip 要因が残ると完走しないことを黙らせない）
			$prev_files = (int) ( $meta['stats']['last_pass_files'] ?? -1 );
			$now_files  = (int) ( $meta['stats']['files'] ?? 0 );
			if ( $prev_files === $now_files ) {
				$meta['stats']['stalled_passes'] = (int) ( $meta['stats']['stalled_passes'] ?? 0 ) + 1;
			} else {
				$meta['stats']['stalled_passes'] = 0;
			}
			$meta['stats']['last_pass_files'] = $now_files;
			$this->save_meta( $meta );
			if ( is_object( $qahm_log ) ) {
				if ( $meta['stats']['stalled_passes'] >= 3 ) {
					$qahm_log->warning( 'ColumnDB width migration (T113 #1477): ' . (int) $meta['stats']['stalled_passes'] . ' passes with no progress — permanent skip items likely remain (converting/broken days). Data stays readable (read_column_auto resolves old width), but investigate the warns above.' );
				} else {
					$qahm_log->info( 'ColumnDB width migration (T113 #1477): pass completed with skipped items — will rescan next run' );
				}
			}
		}
	}

	/**
	 * 1つの月ディレクトリを変換する
	 *
	 * @param string $tid        tracking_id（'all' 含む）
	 * @param string $dataset    データセット名
	 * @param array  $columns    拡幅対象カラム名の配列
	 * @param string $base       データセットディレクトリ（末尾スラッシュあり）
	 * @param string $ym         YYYYMM
	 * @param string $today_ymd  実行日（YYYYMMDD・変換対象外）
	 * @param array  $meta       永続 meta（参照渡し）
	 * @param float  $start_time 実行開始時刻（microtime）
	 * @param int    $budget_sec 時間予算（秒）
	 * @return array ['clean' => bool（全ファイル処理済みで月を完了にできるか）, 'budget_exceeded' => bool]
	 */
	private function migrate_month( $tid, $dataset, $columns, $base, $ym, $today_ymd, array &$meta, $start_time, $budget_sec ) {
		global $qahm_log;

		$month_dir  = $base . $ym . '/';
		$clean      = true;

		// ハードキル（OOM 等）で残った一時ファイルを掃除する（report/ は #1474 の削除除外
		// ＝放置すると永久残留するため。読み手の glob には当たらず無害だが拭いておく）
		$stale_tmp = glob( $month_dir . '*.tmp.*' );
		if ( $stale_tmp ) {
			foreach ( $stale_tmp as $tmp ) {
				$this->wrap_delete( $tmp );
			}
		}

		$pvid_files = glob( $month_dir . $dataset . '_*_pv_id.php' );
		if ( ! $pvid_files ) {
			return array( 'clean' => true, 'budget_exceeded' => false );
		}
		sort( $pvid_files );

		$manifest = QAHM_ColumnDB_Manifest::load( $base, $ym );

		foreach ( $pvid_files as $pvid_file ) {
			if ( ! preg_match( '/_(\d{8})_pv_id\.php$/', $pvid_file, $m ) ) {
				continue;
			}
			$ymd = $m[1];

			// 当日は変換対象外（当夜のチェーンが新幅で書く日＝競合面を作らない）
			if ( $ymd >= $today_ymd ) {
				continue;
			}

			// converting（変換中/中断）の日は cron の再変換が新幅で書き直す＝本移行はスキップ。
			// 幅の自己判定を信頼してよいのは done な日だけ、という前提とも整合する
			$entry = QAHM_ColumnDB_Manifest::get_day( $manifest, $ymd );
			if ( null !== $entry && ! QAHM_ColumnDB_Manifest::is_done_entry( $entry ) ) {
				$clean = false;
				continue;
			}

			foreach ( $columns as $col ) {
				// 時間予算（列ファイル1本を最小単位に刻む）
				if ( ( microtime( true ) - $start_time ) > $budget_sec ) {
					return array( 'clean' => false, 'budget_exceeded' => true );
				}

				$col_file = $month_dir . $dataset . '_' . $ymd . '_' . $col . '.php';
				if ( ! file_exists( $col_file ) ) {
					continue;
				}

				$actual = QAHM_ColumnDB_BinaryIO::detect_column_type( $col_file, $pvid_file );
				if ( 'uint32' === $actual ) {
					continue; // 変換済み（冪等）
				}
				if ( false === $actual ) {
					// pv_id と行数が合わない破損（done な日では起きない想定）＝warn して残す
					if ( is_object( $qahm_log ) ) {
						$qahm_log->warning( 'ColumnDB width migration (T113 #1477): width detect failed — skip ' . $tid . ' ' . $dataset . ' ' . $ymd . ' ' . $col );
					}
					$clean = false;
					continue;
				}

				$old = ( 'uint8' === $actual )
					? QAHM_ColumnDB_BinaryIO::read_uint8_array( $col_file )
					: QAHM_ColumnDB_BinaryIO::read_uint16_array( $col_file );
				if ( false === $old ) {
					$clean = false;
					continue;
				}

				$diff = 0;
				$new  = $this->restore_values( $tid, $dataset, $col, $ymd, $pvid_file, $old, $diff, $meta );

				$binary = empty( $new ) ? '' : pack( 'V*', ...array_map( 'intval', $new ) );
				if ( ! QAHM_ColumnDB_BinaryIO::write_file_atomic( $col_file, $binary ) ) {
					if ( is_object( $qahm_log ) ) {
						$qahm_log->warning( 'ColumnDB width migration (T113 #1477): atomic write failed — skip ' . $tid . ' ' . $dataset . ' ' . $ymd . ' ' . $col );
					}
					$clean = false;
					continue;
				}

				$meta['stats']['files'] = (int) ( $meta['stats']['files'] ?? 0 ) + 1;
				if ( $diff > 0 ) {
					// ★移行の瞬間にしか作れない永久記録＝「この日この列で何行が wrap 値と食い違っていたか」
					$meta['diff'][ $tid . '/' . $dataset . '/' . $ymd . '/' . $col ] = $diff;
				}
			}
		}

		return array( 'clean' => $clean, 'budget_exceeded' => false );
	}

	/**
	 * 旧値配列から新値配列（正値）を作る
	 *
	 * - allpv の source/medium/campaign/version_id: view_pv 日次ファイル（serialize＝正値）から pv_id 突合
	 * - allpv の content_id: qa_pv_log（保持窓内のみ）から pv_id 突合。窓外は旧値をそのまま（決定 A＝可逆）
	 * - click_event / datalayer_event: 復元ソースなし＝旧値をそのまま新幅へ（決定 B）
	 *
	 * @param string $tid       tracking_id
	 * @param string $dataset   データセット名
	 * @param string $col       カラム名
	 * @param string $ymd       YYYYMMDD
	 * @param string $pvid_file pv_id 列ファイルパス
	 * @param array  $old       旧値配列
	 * @param int    $diff      食い違い行数（出力）
	 * @param array  $meta      永続 meta（content_carry 記録用・参照渡し）
	 * @return array 新値配列（$old と同じ行数・行順）
	 */
	private function restore_values( $tid, $dataset, $col, $ymd, $pvid_file, array $old, &$diff, array &$meta ) {
		$diff = 0;

		if ( 'allpv' !== $dataset ) {
			return $old; // 決定 B: click/datalayer は幅だけ変換
		}

		$this->prime_day_cache( $tid, $ymd, $pvid_file );

		$pv_ids = $this->cache_pvids;
		if ( ! is_array( $pv_ids ) || count( $pv_ids ) !== count( $old ) ) {
			// pv_id と行数が合わない（detect が通っていればここには来ない保険）＝復元せず幅だけ変換
			return $old;
		}

		$new = $old;

		if ( in_array( $col, self::RESTORE_FROM_VIEWPV, true ) ) {
			$vmap = $this->cache_viewpv;
			if ( ! empty( $vmap ) ) {
				foreach ( $pv_ids as $i => $pv_id ) {
					if ( isset( $vmap[ $pv_id ][ $col ] ) ) {
						$val = (int) $vmap[ $pv_id ][ $col ];
						if ( $val !== (int) $old[ $i ] ) {
							$diff++;
						}
						$new[ $i ] = $val;
					}
				}
			}
		} elseif ( 'content_id' === $col ) {
			$cmap = $this->in_pvlog_window( $ymd ) ? $this->load_content_map( $pv_ids ) : array();
			if ( empty( $cmap ) ) {
				// qa_pv_log 保持窓外＝復元不能＝旧値をそのまま引き継ぐ（決定 A・「未修復」として日単位で永久記録）
				$meta['content_carry'][ $tid . '/' . $ymd ] = 1;
			} else {
				foreach ( $pv_ids as $i => $pv_id ) {
					if ( isset( $cmap[ $pv_id ] ) ) {
						$val = (int) $cmap[ $pv_id ];
						if ( $val !== (int) $old[ $i ] ) {
							$diff++;
						}
						$new[ $i ] = $val;
					}
				}
			}
		}

		return $new;
	}

	/**
	 * 日単位キャッシュ（pv_id 配列・view_pv 正値マップ）を用意する
	 *
	 * @param string $tid       tracking_id
	 * @param string $ymd       YYYYMMDD
	 * @param string $pvid_file pv_id 列ファイルパス
	 */
	private function prime_day_cache( $tid, $ymd, $pvid_file ) {
		$key = $tid . '/' . $ymd;
		if ( $key === $this->cache_key ) {
			return;
		}
		$this->cache_key     = $key;
		$this->cache_content = null;

		$pv_ids = QAHM_ColumnDB_BinaryIO::read_uint32_array( $pvid_file );
		$this->cache_pvids = ( false === $pv_ids ) ? null : $pv_ids;

		// view_pv 日次ファイル（{Y-m-d}_{start}-{end}_viewpv.php・複数分割あり）を全て読む。
		// 行は wrap_serialize（igbinary/serialize）された assoc 配列＝wrap_unserialize で読む
		$this->cache_viewpv = array();
		$date_hyphen = substr( $ymd, 0, 4 ) . '-' . substr( $ymd, 4, 2 ) . '-' . substr( $ymd, 6, 2 );
		$view_dir    = $this->get_data_dir_path() . 'view/' . $tid . '/view_pv/';
		$files       = glob( $view_dir . $date_hyphen . '_*viewpv.php' );
		if ( $files ) {
			foreach ( $files as $file ) {
				$rows = $this->wrap_unserialize( $this->wrap_get_contents( $file ) );
				if ( ! is_array( $rows ) ) {
					continue;
				}
				foreach ( $rows as $row ) {
					if ( isset( $row['pv_id'] ) ) {
						$this->cache_viewpv[ (int) $row['pv_id'] ] = $row;
					}
				}
			}
		}
	}

	/**
	 * 指定日が qa_pv_log の保持窓内かを判定する（窓はプロセス内で1回だけ実測）
	 *
	 * 窓外の日に content_id クエリを打っても空振りするだけでなく、IN 大量 placeholders が
	 * full scan に反転して1日あたり数秒を浪費する（E2E 実測）＝窓判定で丸ごと省く。
	 *
	 * @param string $ymd YYYYMMDD
	 * @return bool
	 */
	private function in_pvlog_window( $ymd ) {
		if ( null === $this->pvlog_window ) {
			global $wpdb;
			$table = $wpdb->prefix . 'qa_pv_log';
			$row   = $wpdb->get_row( "SELECT DATE_FORMAT(MIN(access_time),'%Y%m%d') AS min_ymd, DATE_FORMAT(MAX(access_time),'%Y%m%d') AS max_ymd FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix . 'qa_pv_log'
			$this->pvlog_window = ( $row && $row->min_ymd ) ? array( (string) $row->min_ymd, (string) $row->max_ymd ) : array( '99999999', '00000000' );
		}
		return ( $ymd >= $this->pvlog_window[0] && $ymd <= $this->pvlog_window[1] );
	}

	/**
	 * qa_pv_log から content_id の正値マップを引く（日単位キャッシュ・保持窓内のみヒット）
	 *
	 * チャンクは 5000 件＝IN の placeholders が閾値を超えると optimizer が range→full scan に
	 * 反転する（#1440 の実測知見）ため、大きくしないこと。
	 *
	 * @param array $pv_ids 当日の pv_id 配列
	 * @return array pv_id => content_id（窓外・不在は空配列）
	 */
	private function load_content_map( array $pv_ids ) {
		if ( null !== $this->cache_content ) {
			return $this->cache_content;
		}
		$this->cache_content = array();

		global $wpdb;
		$table  = $wpdb->prefix . 'qa_pv_log';
		$chunks = array_chunk( array_map( 'intval', $pv_ids ), 5000 );
		foreach ( $chunks as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$sql          = $wpdb->prepare(
				"SELECT pv_id, content_id FROM {$table} WHERE pv_id IN ({$placeholders}) AND content_id IS NOT NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $table is $wpdb->prefix . 'qa_pv_log', $placeholders is array_fill of %d (プレースホルダは補間後の文字列内にあるため sniff からは見えない)
				$chunk
			);
			$rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is from $wpdb->prepare() above
			if ( $rows ) {
				foreach ( $rows as $row ) {
					$this->cache_content[ (int) $row->pv_id ] = (int) $row->content_id;
				}
			}
		}
		return $this->cache_content;
	}

	// =======================================================================
	// meta I/O（Manifest::save_atomic と同形式＝JSON＋PHPヘッダー＋原子保存）
	// =======================================================================

	/**
	 * meta ファイルパス（report/ 直下＝削除フェーズの除外領域 #1474＝永久記録が消されない）
	 *
	 * @return string
	 */
	private function get_meta_path() {
		return $this->get_data_dir_path() . 'report/' . self::META_FILE;
	}

	/**
	 * 永続 meta を読む
	 *
	 * @return array|false 不在・破損は false
	 */
	private function load_meta() {
		$path = $this->get_meta_path();
		if ( ! file_exists( $path ) ) {
			return false;
		}
		$content = file_get_contents( $path );
		if ( false === $content ) {
			return false;
		}
		$pos = strpos( $content, "\n" );
		if ( false === $pos ) {
			return false;
		}
		$data = json_decode( substr( $content, $pos + 1 ), true );
		return is_array( $data ) ? $data : false;
	}

	/**
	 * 永続 meta を原子保存する
	 *
	 * @param array $meta meta 全体
	 * @return bool
	 */
	private function save_meta( array $meta ) {
		$path = $this->get_meta_path();
		$dir  = dirname( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Analytics data directories are pinned to 0755. wp_mkdir_p() inherits the parent directory mode, which is more permissive on hosts where wp-content is 0777.
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) ) {
			return false;
		}
		$json = wp_json_encode( $meta );
		if ( false === $json ) {
			return false;
		}
		$tmp     = $path . '.tmp.' . getmypid();
		$content = self::PHP_HEADER . $json;
		if ( file_put_contents( $tmp, $content, LOCK_EX ) !== strlen( $content ) ) {
			if ( file_exists( $tmp ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- wp_delete_file() suppresses errors with @; the PHP warning is kept to diagnose cleanup failures.
				unlink( $tmp );
			}
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic replace on the same filesystem, so readers only ever see complete meta. WP_Filesystem::move() gives no atomicity guarantee.
		if ( ! rename( $tmp, $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- wp_delete_file() suppresses errors with @; the PHP warning is kept to diagnose cleanup failures.
			unlink( $tmp );
			return false;
		}
		return true;
	}

	// =======================================================================
	// 補助
	// =======================================================================

	/**
	 * 実行1回の時間予算（秒）＝max_execution_time × 0.7（環境の実制限に自動追従）
	 *
	 * @return int
	 */
	private function calc_budget_sec() {
		$max = (int) ini_get( 'max_execution_time' );
		if ( $max > 0 ) {
			return max( 5, min( 60, (int) floor( $max * 0.7 ) ) );
		}
		return self::DEFAULT_BUDGET_SEC; // 0（無制限）や取得不能時の既定
	}

	/**
	 * 実行日（YYYYMMDD・ストアタイムゾーン基準）
	 *
	 * @return string
	 */
	private function today_ymd() {
		global $qahm_time;
		if ( is_object( $qahm_time ) && method_exists( $qahm_time, 'today_str' ) ) {
			return str_replace( '-', '', $qahm_time->today_str() );
		}
		return gmdate( 'Ymd' );
	}

	/**
	 * report/ 直下の tracking_id ディレクトリ一覧（'all' 含む・ソート済み）
	 *
	 * @param string $report_dir report ディレクトリ（末尾スラッシュあり）
	 * @return array
	 */
	private function list_tracking_dirs( $report_dir ) {
		$result  = array();
		$entries = scandir( $report_dir );
		if ( false === $entries ) {
			return $result;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			if ( is_dir( $report_dir . $entry ) ) {
				$result[] = $entry;
			}
		}
		sort( $result );
		return $result;
	}

	/**
	 * データセットディレクトリ直下の月ディレクトリ一覧（YYYYMM・ソート済み）
	 *
	 * @param string $base データセットディレクトリ（末尾スラッシュあり）
	 * @return array
	 */
	private function list_month_dirs( $base ) {
		$result  = array();
		$entries = scandir( $base );
		if ( false === $entries ) {
			return $result;
		}
		foreach ( $entries as $entry ) {
			if ( 6 === strlen( $entry ) && ctype_digit( $entry ) && is_dir( $base . $entry ) ) {
				$result[] = $entry;
			}
		}
		sort( $result );
		return $result;
	}
}
