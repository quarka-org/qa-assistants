<?php
/**
 * ColumnDB 月次変換完了マーカー（manifest）
 *
 * 各データセット（allpv / click_event / datalayer_event）の月ディレクトリに
 * 「日 → 変換状態」の台帳 manifest.php を 1 個持つ。
 *
 *   {dataset_dir}/{YYYYMM}/manifest.php
 *    中身: PHP ヘッダ + JSON  { "YYYYMMDD": { "state": "done", "rows": 1234 }, ... }
 *
 * 目的: 「列ファイルの存在」だけでは区別できない 3 つの失敗モードの検出（Issue #1279）。
 *   F1: flush 途中の読み取り（finalize 前）        → state=converting で読む前に弾ける
 *   F2: 日の途中打ち切り（全列同数で行数不足）     → done が書かれず converting のまま残る
 *   F3: 例外クラッシュ（部分ファイル恒久化）       → 同上。再変換対象に自動で戻せる
 *
 * 更新は「一時ファイルに完全な内容を書く → rename」で原子化する。writer は夜間 cron の
 * 単一プロセス（flock 直列）のため、書き込み競合に対するロックは不要。読者はどの時点で
 * 読んでも完全な manifest だけを見る。
 *
 * 後方互換: manifest（またはその日のエントリ）が無い場合、is_day_done() は従来どおり
 * pv_id 列ファイルの存在で判定する。導入前の月はバックフィルせず従来判定のまま運用できる。
 */

defined( 'ABSPATH' ) || exit;

class QAHM_ColumnDB_Manifest {

    const FILE_NAME  = 'manifest.php';
    // 既存データファイルと同様の Web 直アクセス防御ヘッダ（BinaryIO::PHP_HEADER と同一）
    const PHP_HEADER = "<?php http_response_code(404);die();?>\n";

    const STATE_CONVERTING = 'converting';
    const STATE_DONE       = 'done';

    /**
     * Issue #1420: データ無し日（ゼロアクセス日）の manifest 連続性。
     * ON で ①writer（columndb-cron）がデータ無し日へ done rows=0（+expected=0）を記録し
     * ②reader（is_nodata_day 経由）が「done rows=0＝データ無し日＝行0件」として読み替える
     * ＝ColumnDB ON リーダーの all-or-nothing フォールバックを「真の未変換日」のみに純化する。
     * OFF（既定）＝is_nodata_day が常に false・writer のマーキングも走らない＝現状逐語。
     * writer/reader 同一フラグ（片側だけ ON の中間状態を作らない・設計相談 #1420 ①）。
     * writer 側はさらに PR4∧PR5 ON を code-gate（columndb-cron 側で判定・専属🟡-1）。
     */
    const NODATA_DAY_MANIFEST_ENABLED = false;

    // Issue #1420: nodata スキャンの永続メタ（境界＝最古データ証跡日・スキャン済み日）ファイル名
    const SCAN_META_FILE = 'nodata_scan.php';

    /**
     * プロセス内キャッシュ（path → array|false）。
     * 読者は同月を日数ぶん参照するため、ファイル読みを月 1 回に抑える。
     * update() 成功時に上書きするため writer 自身の参照とも整合する。
     */
    private static $cache = array();

    /**
     * manifest ファイルパス
     *
     * @param string $dataset_dir データセットディレクトリ（例: .../columns-db/allpv/）
     * @param string $year_month  YYYYMM
     * @return string
     */
    public static function path( string $dataset_dir, string $year_month ): string {
        return rtrim( $dataset_dir, '/' ) . '/' . $year_month . '/' . self::FILE_NAME;
    }

    /**
     * 月 manifest を読み込む
     *
     * @param string $dataset_dir データセットディレクトリ
     * @param string $year_month  YYYYMM
     * @return array|false 不在・破損は false（呼び出し側は従来判定へフォールバック）
     */
    public static function load( string $dataset_dir, string $year_month ) {
        $path = self::path( $dataset_dir, $year_month );
        if ( array_key_exists( $path, self::$cache ) ) {
            return self::$cache[ $path ];
        }

        $result = false;
        if ( file_exists( $path ) ) {
            $content = file_get_contents( $path );
            if ( false !== $content ) {
                $pos = strpos( $content, "\n" );
                if ( false !== $pos ) {
                    $data = json_decode( substr( $content, $pos + 1 ), true );
                    if ( is_array( $data ) ) {
                        $result = $data;
                    }
                }
            }
        }

        self::$cache[ $path ] = $result;
        return $result;
    }

    /**
     * 日エントリを取得
     *
     * @param array|false $manifest load() の戻り値
     * @param string      $date_ymd YYYYMMDD
     * @return array|null エントリが無ければ null
     */
    public static function get_day( $manifest, string $date_ymd ) {
        if ( ! is_array( $manifest ) || ! isset( $manifest[ $date_ymd ] ) || ! is_array( $manifest[ $date_ymd ] ) ) {
            return null;
        }
        return $manifest[ $date_ymd ];
    }

    /**
     * エントリが完了状態か
     *
     * @param array|null $entry get_day() の戻り値
     * @return bool
     */
    public static function is_done_entry( $entry ): bool {
        return is_array( $entry ) && isset( $entry['state'] ) && self::STATE_DONE === $entry['state'];
    }

    /**
     * 日次変換の完了判定（writer の「処理済みスキップ」用）
     *
     * manifest にその日のエントリがあれば state=done で判定する。converting のまま残った日
     * （中断の証拠）は未完了と判定され、再変換対象に自動で戻る。
     * エントリが無い日（導入前・manifest 不在含む）は従来どおり pv_id 列ファイルの存在で判定する。
     *
     * @param string $dataset_dir  データセットディレクトリ
     * @param string $dataset_name データセット名（allpv / click_event / datalayer_event）
     * @param string $date_ymd     YYYYMMDD
     * @return bool
     */
    public static function is_day_done( string $dataset_dir, string $dataset_name, string $date_ymd ): bool {
        $year_month = substr( $date_ymd, 0, 6 );
        $entry      = self::get_day( self::load( $dataset_dir, $year_month ), $date_ymd );
        if ( null !== $entry ) {
            return self::is_done_entry( $entry );
        }
        $marker = rtrim( $dataset_dir, '/' ) . '/' . $year_month . '/' . $dataset_name . '_' . $date_ymd . '_pv_id.php';
        return file_exists( $marker );
    }

    /**
     * 変換開始を記録（state=converting）
     *
     * @param string   $dataset_dir データセットディレクトリ
     * @param string   $date_ymd    YYYYMMDD
     * @param int|null $expected    変換予定行数（既知の場合のみ。F2 発生時の事後調査用）
     * @return bool
     */
    public static function mark_converting( string $dataset_dir, string $date_ymd, ?int $expected = null ): bool {
        $entry = array( 'state' => self::STATE_CONVERTING );
        if ( null !== $expected ) {
            $entry['expected'] = $expected;
        }
        return self::update( $dataset_dir, $date_ymd, $entry );
    }

    /**
     * 変換完了を記録（state=done + 実書き込み行数）
     *
     * @param string   $dataset_dir データセットディレクトリ
     * @param string   $date_ymd    YYYYMMDD
     * @param int      $rows        実書き込み行数（0 = データ無しで完了扱いの日）
     * @param int|null $expected    変換時の入力行数を明示指定する場合のみ（Issue #1420: nodata マーキングが
     *                              expected=0 を直接書くために追加。null=従来どおり直前 converting からの引き継ぎのみ）
     * @return bool
     */
    public static function mark_done( string $dataset_dir, string $date_ymd, int $rows, ?int $expected = null ): bool {
        $entry = array(
            'state' => self::STATE_DONE,
            'rows'  => $rows,
        );
        if ( null !== $expected ) {
            // Issue #1420: nodata 日は converting を経ずに done を書くため expected を明示指定で受ける。
            // expected=0 を記録しておくと、後からデータが遅延流入した日を PR5 ドリフト再ホームが
            // 「pvlog 行数 > expected(0)」で自動検出して再変換に戻せる（恒久スキップの穴を作らない）。
            $entry['expected'] = $expected;
        } else {
            // P7-D PR5 #1384: 直前の converting エントリが入力行数（expected）を持つ場合、done 後も引き継ぐ。
            // ドリフト検出（get_day_input_count）が「変換時の入力行数」を基準に late 流入を判定するため。
            // 実書き込み行数 rows は write 一部失敗で入力を下回りうるので基準に使えない。expected を記録しない
            // 経路（click/datalayer の mark_converting は expected 無し）は従来どおり rows のみで挙動不変。
            $prev = self::get_day( self::load( $dataset_dir, substr( $date_ymd, 0, 6 ) ), $date_ymd );
            if ( is_array( $prev ) && isset( $prev['expected'] ) && is_int( $prev['expected'] ) ) {
                $entry['expected'] = $prev['expected'];
            }
        }
        return self::update( $dataset_dir, $date_ymd, $entry );
    }

    /**
     * データ無し日（done rows=0 マーカー）か（Issue #1420）
     *
     * フラグ OFF では常に false＝全呼び出し元が現状挙動のまま（OFF 不変を本メソッドに集約）。
     * 「エントリ無し日」は false（pre-manifest 変換月＝エントリ無しでも列ファイルが読める日が
     * 正規に存在するため、エントリ無しをデータ無しと解釈してはならない）。
     *
     * @param string $dataset_dir データセットディレクトリ
     * @param string $date_ymd    YYYYMMDD
     * @return bool
     */
    public static function is_nodata_day( string $dataset_dir, string $date_ymd ): bool {
        if ( ! self::NODATA_DAY_MANIFEST_ENABLED ) {
            return false;
        }
        $entry = self::get_day( self::load( $dataset_dir, substr( $date_ymd, 0, 6 ) ), $date_ymd );
        // expected===0 も要求＝「nodata スキャンが書いたマーカー」に判定を厳密スコープする。
        // converter の異常系（全 write 失敗＋finalize 成功）は done rows=0 でも expected=入力行数(>0) を
        // 引き継ぐため、実データ日を行0件に誤読しない（従来どおり null→フォールバックに落ちる）。
        return self::is_done_entry( $entry )
            && isset( $entry['rows'] ) && 0 === (int) $entry['rows']
            && isset( $entry['expected'] ) && 0 === (int) $entry['expected'];
    }

    /**
     * nodata スキャンの永続メタを読む（Issue #1420）
     *
     * 形: array( 'boundary' => 'YYYYMMDD'（最古データ証跡日）, 'scanned_through' => 'YYYYMMDD' or ''（スキャン済み日） )。
     * 境界（最古データ証跡）を永続化するのは、P7-X で view_pv ファイル（証跡の一つ）が消えた後も
     * 境界判定が生き続けるようにするため。
     *
     * @param string $dataset_dir データセットディレクトリ
     * @return array|false 不在・破損は false
     */
    public static function load_scan_meta( string $dataset_dir ) {
        $path = rtrim( $dataset_dir, '/' ) . '/' . self::SCAN_META_FILE;
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
        // boundary は8桁数字・scanned_through は ''（未スキャン）or 8桁数字のみ受理。
        // 破損値を素通しすると reader クランプが「静かに空を返す」方向へ倒れるため、不正は false（初回扱い）に落とす。
        if ( ! is_array( $data ) || ! isset( $data['boundary'] ) ) {
            return false;
        }
        $boundary = (string) $data['boundary'];
        if ( 8 !== strlen( $boundary ) || ! ctype_digit( $boundary ) ) {
            return false;
        }
        $scanned = isset( $data['scanned_through'] ) ? (string) $data['scanned_through'] : '';
        if ( '' !== $scanned && ( 8 !== strlen( $scanned ) || ! ctype_digit( $scanned ) ) ) {
            return false;
        }
        return array(
            'boundary'        => $boundary,
            'scanned_through' => $scanned,
        );
    }

    /**
     * nodata スキャンの永続メタを保存する（Issue #1420・manifest と同じ tmp+rename 原子保存）
     *
     * @param string $dataset_dir データセットディレクトリ
     * @param array  $meta        メタ（boundary / scanned_through）
     * @return bool
     */
    public static function save_scan_meta( string $dataset_dir, array $meta ): bool {
        $path = rtrim( $dataset_dir, '/' ) . '/' . self::SCAN_META_FILE;
        return self::save_atomic( $path, $meta );
    }

    /**
     * legacy 欠損バックフィル（Issue #1441）の走査 watermark ファイル名。
     *
     * nodata_scan.php（Issue #1420）とはフラグ・ライフサイクルが独立のため別ファイルにする
     * （nodata 側の escape hatch＝「nodata_scan.php 削除で境界再導出」を汚さない）。
     * boundary は持たない（対象日集合が qa_pv_log 候補日由来＝自然に保持窓へ閉じるため）。
     */
    const LEGACY_BACKFILL_META_FILE = 'legacy_backfill_scan.php';

    /**
     * legacy 欠損バックフィルの永続メタを読み出す（Issue #1441）
     *
     * 形: array( 'scanned_through' => '' or 'YYYYMMDD' )。
     *
     * @param string $dataset_dir データセットディレクトリ
     * @return array|false 不在・破損は false（初回扱い）
     */
    public static function load_backfill_meta( string $dataset_dir ) {
        $path = rtrim( $dataset_dir, '/' ) . '/' . self::LEGACY_BACKFILL_META_FILE;
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
        // scanned_through は ''（未スキャン）or 8桁数字のみ受理。破損は false（初回扱い＝再走査・冪等）。
        if ( ! is_array( $data ) || ! isset( $data['scanned_through'] ) ) {
            return false;
        }
        $scanned = (string) $data['scanned_through'];
        if ( '' !== $scanned && ( 8 !== strlen( $scanned ) || ! ctype_digit( $scanned ) ) ) {
            return false;
        }
        return array( 'scanned_through' => $scanned );
    }

    /**
     * legacy 欠損バックフィルの永続メタを保存する（Issue #1441・manifest と同じ tmp+rename 原子保存）
     *
     * @param string $dataset_dir データセットディレクトリ
     * @param array  $meta        メタ（scanned_through）
     * @return bool
     */
    public static function save_backfill_meta( string $dataset_dir, array $meta ): bool {
        $path = rtrim( $dataset_dir, '/' ) . '/' . self::LEGACY_BACKFILL_META_FILE;
        return self::save_atomic( $path, $meta );
    }

    /**
     * Issue #1462: is_raw 列バックフィルの走査 watermark メタファイル名。
     * legacy 欠損バックフィル（#1441）とは対象・判定が別（is_raw 列ファイル欠落）なので別ファイルで独立管理する。
     */
    const IS_RAW_BACKFILL_META_FILE = 'is_raw_backfill_scan.php';

    /**
     * is_raw 列バックフィルの永続メタを読み出す（Issue #1462・load_backfill_meta と同形式）
     *
     * @param string $dataset_dir データセットディレクトリ
     * @return array|false 不在・破損は false（初回扱い）
     */
    public static function load_is_raw_backfill_meta( string $dataset_dir ) {
        $path = rtrim( $dataset_dir, '/' ) . '/' . self::IS_RAW_BACKFILL_META_FILE;
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
        if ( ! is_array( $data ) || ! isset( $data['scanned_through'] ) ) {
            return false;
        }
        $scanned = (string) $data['scanned_through'];
        if ( '' !== $scanned && ( 8 !== strlen( $scanned ) || ! ctype_digit( $scanned ) ) ) {
            return false;
        }
        return array( 'scanned_through' => $scanned );
    }

    /**
     * is_raw 列バックフィルの永続メタを保存する（Issue #1462・原子保存）
     *
     * @param string $dataset_dir データセットディレクトリ
     * @param array  $meta        メタ（scanned_through）
     * @return bool
     */
    public static function save_is_raw_backfill_meta( string $dataset_dir, array $meta ): bool {
        $path = rtrim( $dataset_dir, '/' ) . '/' . self::IS_RAW_BACKFILL_META_FILE;
        return self::save_atomic( $path, $meta );
    }

    /**
     * 変換時の入力行数（expected）を読み出す（P7-D PR5 #1384・ドリフト検出のベースライン）
     *
     * mark_converting が記録し mark_done が引き継ぐ「変換時の入力行数」を返す。late 流入の判定は
     * 「qa_pv_log 当日行数 > この入力行数」で行う。expected を持たない日（legacy / expected 無しで
     * converting した日）は null を返し、呼び出し側でドリフト対象外とする（実害の小さい古い日を保護）。
     *
     * @param string $dataset_dir データセットディレクトリ
     * @param string $date_ymd    YYYYMMDD
     * @return int|null 入力行数。エントリ不在・expected 未記録は null
     */
    public static function get_day_input_count( string $dataset_dir, string $date_ymd ): ?int {
        $entry = self::get_day( self::load( $dataset_dir, substr( $date_ymd, 0, 6 ) ), $date_ymd );
        if ( is_array( $entry ) && isset( $entry['expected'] ) && is_int( $entry['expected'] ) ) {
            return $entry['expected'];
        }
        return null;
    }

    /**
     * 中断痕跡日の部分列ファイルを削除する
     *
     * Writer は追記方式（FILE_APPEND）のため、部分ファイルを残したまま再変換すると
     * 行が二重化する。再変換の前に当日の列ファイルをすべて消す。
     * glob パターンは {dataset}_{ymd}_*.php のため manifest.php 自身や辞書には触れない。
     *
     * @param string $dataset_dir  データセットディレクトリ
     * @param string $dataset_name データセット名
     * @param string $date_ymd     YYYYMMDD
     * @return int 削除したファイル数
     */
    public static function cleanup_partial_day( string $dataset_dir, string $dataset_name, string $date_ymd ): int {
        $year_month = substr( $date_ymd, 0, 6 );
        $files      = glob( rtrim( $dataset_dir, '/' ) . '/' . $year_month . '/' . $dataset_name . '_' . $date_ymd . '_*.php' );
        $deleted    = 0;
        if ( $files ) {
            foreach ( $files as $file ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- wp_delete_file() returns void; the return value is needed to count the files actually deleted.
                if ( unlink( $file ) ) {
                    $deleted++;
                }
            }
        }
        return $deleted;
    }

    /**
     * 日エントリを更新して原子保存する
     *
     * @param string $dataset_dir データセットディレクトリ
     * @param string $date_ymd    YYYYMMDD
     * @param array  $entry       エントリ
     * @return bool
     */
    private static function update( string $dataset_dir, string $date_ymd, array $entry ): bool {
        $year_month = substr( $date_ymd, 0, 6 );
        $manifest   = self::load( $dataset_dir, $year_month );
        if ( false === $manifest ) {
            $manifest = array();
        }
        $manifest[ $date_ymd ] = $entry;
        ksort( $manifest );

        $path = self::path( $dataset_dir, $year_month );
        if ( ! self::save_atomic( $path, $manifest ) ) {
            return false;
        }
        self::$cache[ $path ] = $manifest;
        return true;
    }

    /**
     * 一時ファイル + rename による原子保存
     *
     * @param string $path     保存先パス
     * @param array  $manifest manifest 全体
     * @return bool
     */
    private static function save_atomic( string $path, array $manifest ): bool {
        $dir = dirname( $path );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Analytics data directories are pinned to 0755. wp_mkdir_p() inherits the parent directory mode, which is more permissive on hosts where wp-content is 0777.
        if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) ) {
            return false;
        }
        $json = wp_json_encode( $manifest );
        if ( false === $json ) {
            return false;
        }
        $tmp     = $path . '.tmp.' . getmypid();
        $content = self::PHP_HEADER . $json;
        // 書き込み長まで検証する: file_put_contents はディスクフル等で短縮書き込みしても
        // バイト数（int）を返すため、false チェックだけでは破損 tmp を rename しうる
        if ( file_put_contents( $tmp, $content, LOCK_EX ) !== strlen( $content ) ) {
            if ( file_exists( $tmp ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- wp_delete_file() suppresses errors with @; the PHP warning is kept to diagnose cleanup failures.
                unlink( $tmp );
            }
            return false;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic replace on the same filesystem, so readers only ever see a complete manifest. WP_Filesystem::move() gives no atomicity guarantee.
        if ( ! rename( $tmp, $path ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- wp_delete_file() suppresses errors with @; the PHP warning is kept to diagnose cleanup failures.
            unlink( $tmp );
            return false;
        }
        return true;
    }
}
