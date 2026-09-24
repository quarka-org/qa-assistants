<?php
/**
 * 列DB セッションID 採番カウンタ（T85-F）
 *
 * 月別ファイルに「次に採番する月内連番（month_seq）」だけを永続化する、
 * 軽量な auto-increment カウンタ。session_id は `ym_int × 10^9 + month_seq` で合成する。
 *
 * T85-B のハッシュテーブル（385MB/月）は廃止。本クラスは uint64 1個（実ファイルは
 * PHPセキュリティヘッダー込みで 50 バイト前後）だけを保持する。
 *
 * ファイル構造:
 *   report/{tracking_id}/columns-db/allpv/{YYYYMM}/session_counter.php
 *
 *   [PHPセキュリティヘッダー (39 byte)]
 *   [next_month_seq: uint64 (8 byte, little-endian, pack 'P')]
 *
 * 使い方（cron Phase 1 が想定するシーケンス）:
 *   $counter   = new QAHM_ColumnDB_SessionCounter( $tracking_id, $year_month, $allpv_dir );
 *   $next_seq  = $counter->get_next_seq();   // 日処理開始時に 1 度だけ read
 *
 *   foreach ( $rows as $row ) {
 *       if ( $is_new_session ) {
 *           $session_id = $counter->compose_session_id( $next_seq );
 *           $next_seq++;
 *       }
 *       // ... write_row ...
 *   }
 *
 *   $counter->set_next_seq( $next_seq );
 *   $counter->close();   // 日処理完了時に 1 度だけ flock + write
 *
 * @package qa_heatmap
 */

class QAHM_ColumnDB_SessionCounter {

	const SESSION_ID_BASE = 1000000000; // 10^9

	/**
	 * @var string
	 */
	private string $tracking_id;

	/**
	 * 対象年月（YYYYMM）
	 *
	 * @var string
	 */
	private string $ym;

	/**
	 * 対象年月の整数表現
	 *
	 * @var int
	 */
	private int $ym_int;

	/**
	 * カウンタファイルパス
	 *
	 * @var string
	 */
	private string $filepath;

	/**
	 * 次に採番する月内シーケンス（1始まり）
	 *
	 * @var int
	 */
	private int $next_month_seq = 1;

	/**
	 * @param string      $tracking_id 追跡ID
	 * @param string      $ym          対象年月（YYYYMM、例: "202606"）
	 * @param string|null $base_dir    allpv ベースディレクトリ（末尾スラッシュあり、null=デフォルト）
	 */
	public function __construct( string $tracking_id, string $ym, ?string $base_dir = null ) {
		// 32bit PHP では intval(2.02e14) が overflow して uint64 session_id が
		// サイレントに破壊される。pack/unpack 'P*' も同様に 64bit 整数を扱えない。
		if ( PHP_INT_SIZE !== 8 ) {
			throw new Exception(
				esc_html( 'uint64 session_id requires 64-bit PHP (PHP_INT_SIZE=' . PHP_INT_SIZE . ')' )
			);
		}

		$this->tracking_id = $tracking_id;

		if ( strlen( $ym ) !== 6 || ! ctype_digit( $ym ) ) {
			throw new InvalidArgumentException(
				esc_html( 'Invalid $ym format (expected YYYYMM): ' . $ym )
			);
		}
		$year  = (int) substr( $ym, 0, 4 );
		$month = (int) substr( $ym, 4, 2 );
		if ( $year < 2000 || $year > 2099 || $month < 1 || $month > 12 ) {
			throw new InvalidArgumentException(
				esc_html( 'Invalid $ym range (expected 200001 - 209912): ' . $ym )
			);
		}
		$this->ym     = $ym;
		$this->ym_int = (int) $ym;

		if ( $base_dir === null ) {
			if ( defined( 'WP_CONTENT_DIR' ) ) {
				$base_dir = WP_CONTENT_DIR . '/qa-zero-data/report/' . $tracking_id . '/columns-db/allpv/';
			} else {
				throw new Exception( 'WP_CONTENT_DIR is not defined and base_dir is not specified' );
			}
		}

		$month_dir = rtrim( $base_dir, '/' ) . '/' . $ym . '/';
		if ( ! is_dir( $month_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Analytics data directories are pinned to 0755. wp_mkdir_p() inherits the parent directory mode, which is more permissive on hosts where wp-content is 0777.
			mkdir( $month_dir, 0755, true );
		}

		$this->filepath = $month_dir . 'session_counter.php';
		$this->load();
	}

	/**
	 * カウンタを読み込み（不在なら 1 から開始）。
	 *
	 * 値域分離（旧 < 10^7、新 ≥ 10^14）により、不在時に 1 から始めても
	 * 旧値と衝突しないため scan による max 取得は行わない。
	 */
	private function load(): void {
		if ( ! file_exists( $this->filepath ) ) {
			$this->next_month_seq = 1;
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Needs flock() and fseek() on the handle. WP_Filesystem exposes neither.
		$fp = fopen( $this->filepath, 'rb' );
		if ( $fp === false ) {
			throw new Exception( esc_html( 'Failed to open session_counter file: ' . $this->filepath ) );
		}

		if ( ! flock( $fp, LOCK_SH ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the handle opened above.
			fclose( $fp );
			throw new Exception( esc_html( 'Failed to lock session_counter file: ' . $this->filepath ) );
		}

		// PHPセキュリティヘッダーをスキップして uint64 1 個を読む
		fseek( $fp, QAHM_ColumnDB_BinaryIO::HEADER_SIZE );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reads 8 bytes after seeking past the security header. WP_Filesystem has no partial-read API.
		$binary = fread( $fp, 8 );
		flock( $fp, LOCK_UN );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the handle opened above.
		fclose( $fp );

		if ( $binary === false || strlen( $binary ) !== 8 ) {
			// PHPヘッダー後の payload (uint64 8 byte) が読み取れない。
			// 同月の _session_id.php が既に存在する場合、ここで 1 にフォールバック
			// すると同一月内で session_id が重複するため fail-fast する
			// （値域分離は旧値域 vs 新値域の話で、新値域内同月の重複は防げない）。
			// 復旧は: counter ファイルを手動削除 → 新月扱いとして 1 から再開（運用判断）。
			throw new Exception(
				esc_html(
					'session_counter file is corrupted (header only / partial payload): '
					. $this->filepath
					. ' (read=' . ( $binary === false ? 'false' : strlen( $binary ) ) . ' bytes)'
				)
			);
		}

		$unpacked              = unpack( 'Pseq', $binary );
		$this->next_month_seq  = (int) $unpacked['seq'];
		if ( $this->next_month_seq < 1 ) {
			$this->next_month_seq = 1;
		}
	}

	/**
	 * 月内シーケンスから session_id を合成
	 *
	 * @param int $month_seq 1 以上の月内連番
	 * @return int session_id（uint64）
	 */
	public function compose_session_id( int $month_seq ): int {
		return $this->ym_int * self::SESSION_ID_BASE + $month_seq;
	}

	/**
	 * 次に採番すべき月内シーケンスを取得（cron 日処理開始時の起点）
	 *
	 * @return int
	 */
	public function get_next_seq(): int {
		return $this->next_month_seq;
	}

	/**
	 * 採番済みの月内シーケンスをセット（cron 日処理完了時の最終値）
	 *
	 * 値は close() / persist() でファイルに書き戻される。
	 *
	 * @param int $next_seq 次に採番すべき値（最後に採番した値の +1）
	 */
	public function set_next_seq( int $next_seq ): void {
		if ( $next_seq < 1 ) {
			$next_seq = 1;
		}
		$this->next_month_seq = $next_seq;
	}

	/**
	 * 対象年月を取得
	 *
	 * @return string YYYYMM
	 */
	public function get_ym(): string {
		return $this->ym;
	}

	/**
	 * カウンタを永続化（tmp + rename によるアトミック書き換え）
	 *
	 * 旧実装（ftruncate(0) → fwrite）は kill / Fatal / ディスクフルで途中終了すると
	 * 既存 counter ファイルが「PHPヘッダーのみ」「payload 欠損」の壊れた状態に
	 * 残り、month_seq 巻き戻りで同月内 session_id 重複を引き起こすリスクがあった。
	 *
	 * 本実装は tmp ファイルに全量を書いてから POSIX rename で差し替える。
	 * rename は同一ファイルシステム内でアトミックなため、リーダーは旧版または
	 * 新版のどちらかを必ず取得し、半端な状態を観測しない。
	 *
	 * fwrite の戻り値も検証して部分書き込みを検出する。
	 *
	 * 設計書 T85F-sessions-table-elimination.md §2.5 / Copilot review #3206596236 対応。
	 *
	 * @throws Exception
	 */
	public function persist(): void {
		$php_header = QAHM_ColumnDB_BinaryIO::PHP_HEADER;
		$payload    = pack( 'P', $this->next_month_seq );
		$data       = $php_header . $payload;
		$expected   = strlen( $data );

		$tmp = $this->filepath . '.tmp.' . getmypid() . '.' . bin2hex( random_bytes( 8 ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Writes a temp file that is atomically renamed into place, verifying the written byte count. WP_Filesystem cannot express write-verify-rename.
		$fp = fopen( $tmp, 'wb' );
		if ( $fp === false ) {
			throw new Exception( esc_html( 'Failed to open tmp session_counter for write: ' . $tmp ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The byte count is compared against strlen() to detect short writes (disk full). WP_Filesystem::put_contents() does not report it.
		$written = fwrite( $fp, $data );
		if ( $written === false || $written !== $expected ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the handle opened above.
			fclose( $fp );
			wp_delete_file( $tmp );
			throw new Exception( esc_html( sprintf(
				'Partial write to session_counter tmp: expected=%d, written=%s, path=%s',
				$expected,
				( $written === false ? 'false' : (int) $written ),
				$tmp
			) ) );
		}

		if ( ! fflush( $fp ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the handle opened above.
			fclose( $fp );
			wp_delete_file( $tmp );
			throw new Exception( esc_html( 'Failed to fflush session_counter tmp: ' . $tmp ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the handle opened above.
		fclose( $fp );

		// POSIX rename はアトミック（同一ファイルシステム内）
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic replace on the same filesystem, required so readers never observe a partial counter. WP_Filesystem::move() gives no atomicity guarantee.
		if ( ! @rename( $tmp, $this->filepath ) ) {
			wp_delete_file( $tmp );
			throw new Exception(
				esc_html( 'Failed to rename session_counter tmp: ' . $tmp . ' to ' . $this->filepath )
			);
		}
	}

	/**
	 * 永続化して終了。
	 */
	public function close(): void {
		$this->persist();
	}
}
