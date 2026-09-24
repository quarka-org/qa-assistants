<?php
/**
 * 列DB 低レベルバイナリI/Oクラス
 *
 * 全ファイルにPHPセキュリティヘッダー（39バイト）を付与し、
 * 直接アクセスを防止する。
 *
 * @package qa_heatmap
 */

class QAHM_ColumnDB_BinaryIO {

    /**
     * PHPセキュリティヘッダー
     * ブラウザから直接アクセスされた場合、404を返して終了する
     */
    const PHP_HEADER = "<?php http_response_code(404);die();?>\n";
    const HEADER_SIZE = 39; // PHPヘッダー文字列（38文字）+ 改行（1バイト）

    // =======================================================================
    // ファイル書き込み関数
    // =======================================================================

    /**
     * ファイルに新規書き込み（PHPヘッダー付与）
     *
     * @param string $filepath 書き込み先ファイルパス
     * @param string $binary_data バイナリデータ
     * @return bool 成功/失敗
     */
    public static function write_file( string $filepath, string $binary_data ): bool {
        $dir = dirname( $filepath );
        if ( ! is_dir( $dir ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Analytics data directories are pinned to 0755. wp_mkdir_p() inherits the parent directory mode, which is more permissive on hosts where wp-content is 0777.
            if ( ! mkdir( $dir, 0755, true ) ) {
                return false;
            }
        }

        $content = self::PHP_HEADER . $binary_data;
        $result = file_put_contents( $filepath, $content, LOCK_EX );
        return $result !== false;
    }

    /**
     * ファイルを原子的に置換する（T113 #1477・幅移行の in-place 書き直し用）
     *
     * write_file()（file_put_contents の truncate 上書き）は書き換え中のファイルを
     * 読み手が途中まで読める（torn read）ため、稼働中データの置換には使えない。
     * Manifest::save_atomic と同方式＝一時ファイルへ書き込み → 書き込み長を strlen と
     * 突合（ディスクフル等の短縮書き込みを検出）→ rename（同一 FS で原子的）。
     * 読み手は「旧か新か」のどちらか完全な状態しか見ない。
     *
     * @param string $filepath 置換先ファイルパス
     * @param string $binary_data バイナリデータ（PHPヘッダーは本関数が付与）
     * @return bool 成功/失敗（失敗時は一時ファイルを掃除し、元ファイルは無傷）
     */
    public static function write_file_atomic( string $filepath, string $binary_data ): bool {
        $dir = dirname( $filepath );
        if ( ! is_dir( $dir ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Analytics data directories are pinned to 0755. wp_mkdir_p() inherits the parent directory mode, which is more permissive on hosts where wp-content is 0777.
            if ( ! mkdir( $dir, 0755, true ) ) {
                return false;
            }
        }

        $content = self::PHP_HEADER . $binary_data;
        $tmp     = $filepath . '.tmp.' . getmypid();
        if ( file_put_contents( $tmp, $content, LOCK_EX ) !== strlen( $content ) ) {
            if ( file_exists( $tmp ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- wp_delete_file() suppresses errors with @; the PHP warning is kept to diagnose cleanup failures.
                unlink( $tmp );
            }
            return false;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic replace on the same filesystem, required to prevent torn reads. WP_Filesystem::move() gives no atomicity guarantee.
        if ( ! rename( $tmp, $filepath ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- wp_delete_file() suppresses errors with @; the PHP warning is kept to diagnose cleanup failures.
            unlink( $tmp );
            return false;
        }
        return true;
    }

    /**
     * ファイルに追記（PHPヘッダーは書かない）
     * 既存ファイルの末尾にデータを追加
     *
     * @param string $filepath 追記先ファイルパス
     * @param string $binary_data バイナリデータ
     * @return bool 成功/失敗
     */
    public static function append_file( string $filepath, string $binary_data ): bool {
        // ファイルが存在しない場合は新規作成
        if ( ! file_exists( $filepath ) ) {
            return self::write_file( $filepath, $binary_data );
        }

        $result = file_put_contents( $filepath, $binary_data, FILE_APPEND | LOCK_EX );
        return $result !== false;
    }

    /**
     * ファイル全体を読み込み（PHPヘッダーをスキップ）
     *
     * @param string $filepath 読み込み元ファイルパス
     * @return string|false バイナリデータ（失敗時はfalse）
     */
    public static function read_file( string $filepath ) {
        if ( ! file_exists( $filepath ) ) {
            return false;
        }

        $content = file_get_contents( $filepath );
        if ( $content === false ) {
            return false;
        }

        // PHPヘッダーをスキップして返す
        return substr( $content, self::HEADER_SIZE );
    }

    /**
     * ファイルの部分読み込み（PHPヘッダーを考慮したオフセット計算）
     *
     * @param string $filepath ファイルパス
     * @param int $offset データ部分からのオフセット（バイト）
     * @param int $length 読み込みバイト数
     * @return string|false バイナリデータ（失敗時はfalse）
     */
    public static function read_file_partial( string $filepath, int $offset, int $length ) {
        if ( ! file_exists( $filepath ) ) {
            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Byte-offset partial read of a binary column file. WP_Filesystem has no seek or partial-read API.
        $fp = fopen( $filepath, 'rb' );
        if ( $fp === false ) {
            return false;
        }

        // PHPヘッダーを考慮した実際のオフセット
        $actual_offset = self::HEADER_SIZE + $offset;

        fseek( $fp, $actual_offset );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Byte-offset partial read of a binary column file. WP_Filesystem has no seek or partial-read API.
        $data = fread( $fp, $length );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the handle opened above for partial binary reads.
        fclose( $fp );

        return $data;
    }

    // =======================================================================
    // 型特化書き込み関数
    // =======================================================================

    /**
     * uint64配列をファイルに書き込み
     *
     * @param string $filepath ファイルパス
     * @param array $values uint64値の配列
     * @return bool 成功/失敗
     */
    public static function write_uint64_array( string $filepath, array $values ): bool {
        if ( empty( $values ) ) {
            return self::write_file( $filepath, '' );
        }
        // 一括pack（リトルエンディアン uint64）
        $binary = pack( 'P*', ...array_map( 'intval', $values ) );
        return self::write_file( $filepath, $binary );
    }

    /**
     * uint32配列をファイルに書き込み
     *
     * @param string $filepath ファイルパス
     * @param array $values uint32値の配列
     * @return bool 成功/失敗
     */
    public static function write_uint32_array( string $filepath, array $values ): bool {
        if ( empty( $values ) ) {
            return self::write_file( $filepath, '' );
        }
        // 一括pack（リトルエンディアン uint32）
        $binary = pack( 'V*', ...array_map( 'intval', $values ) );
        return self::write_file( $filepath, $binary );
    }

    /**
     * uint16配列をファイルに書き込み
     *
     * @param string $filepath ファイルパス
     * @param array $values uint16値の配列
     * @return bool 成功/失敗
     */
    public static function write_uint16_array( string $filepath, array $values ): bool {
        if ( empty( $values ) ) {
            return self::write_file( $filepath, '' );
        }
        // 一括pack（リトルエンディアン uint16）
        $binary = pack( 'v*', ...array_map( 'intval', $values ) );
        return self::write_file( $filepath, $binary );
    }

    /**
     * uint8配列をファイルに書き込み
     *
     * @param string $filepath ファイルパス
     * @param array $values uint8値の配列
     * @return bool 成功/失敗
     */
    public static function write_uint8_array( string $filepath, array $values ): bool {
        if ( empty( $values ) ) {
            return self::write_file( $filepath, '' );
        }
        // 一括pack（unsigned char 8bit）
        $binary = pack( 'C*', ...array_map( 'intval', $values ) );
        return self::write_file( $filepath, $binary );
    }

    /**
     * uint64配列をファイルに追記
     *
     * @param string $filepath ファイルパス
     * @param array $values uint64値の配列
     * @return bool 成功/失敗
     */
    public static function append_uint64_array( string $filepath, array $values ): bool {
        if ( empty( $values ) ) {
            return true;
        }
        // 一括pack（リトルエンディアン uint64）
        $binary = pack( 'P*', ...array_map( 'intval', $values ) );
        return self::append_file( $filepath, $binary );
    }

    /**
     * uint32配列をファイルに追記
     *
     * @param string $filepath ファイルパス
     * @param array $values uint32値の配列
     * @return bool 成功/失敗
     */
    public static function append_uint32_array( string $filepath, array $values ): bool {
        if ( empty( $values ) ) {
            return true;
        }
        // 一括pack（リトルエンディアン uint32）
        $binary = pack( 'V*', ...array_map( 'intval', $values ) );
        return self::append_file( $filepath, $binary );
    }

    /**
     * uint16配列をファイルに追記
     *
     * @param string $filepath ファイルパス
     * @param array $values uint16値の配列
     * @return bool 成功/失敗
     */
    public static function append_uint16_array( string $filepath, array $values ): bool {
        if ( empty( $values ) ) {
            return true;
        }
        // 一括pack（リトルエンディアン uint16）
        $binary = pack( 'v*', ...array_map( 'intval', $values ) );
        return self::append_file( $filepath, $binary );
    }

    /**
     * uint8配列をファイルに追記
     *
     * @param string $filepath ファイルパス
     * @param array $values uint8値の配列
     * @return bool 成功/失敗
     */
    public static function append_uint8_array( string $filepath, array $values ): bool {
        if ( empty( $values ) ) {
            return true;
        }
        // 一括pack（unsigned char 8bit）
        $binary = pack( 'C*', ...array_map( 'intval', $values ) );
        return self::append_file( $filepath, $binary );
    }

    // =======================================================================
    // 型特化読み込み関数
    // =======================================================================

    /**
     * uint64配列をファイルから読み込み
     *
     * @param string $filepath ファイルパス
     * @param int|null $offset 開始インデックス（行番号、0始まり）
     * @param int|null $count 読み込み件数（null=全件）
     * @return array|false uint64値の配列（失敗時はfalse）
     */
    public static function read_uint64_array( string $filepath, ?int $offset = null, ?int $count = null ) {
        if ( $offset !== null && $count !== null ) {
            // 部分読み込み
            $byte_offset = $offset * 8;
            $byte_length = $count * 8;
            $binary = self::read_file_partial( $filepath, $byte_offset, $byte_length );
        } else {
            // 全件読み込み
            $binary = self::read_file( $filepath );
        }

        if ( $binary === false ) {
            return false;
        }

        if ( $binary === '' ) {
            return [];
        }

        // 一括unpack（リトルエンディアン uint64）
        return array_values( unpack( 'P*', $binary ) );
    }

    /**
     * uint32配列をファイルから読み込み
     *
     * @param string $filepath ファイルパス
     * @param int|null $offset 開始インデックス（行番号、0始まり）
     * @param int|null $count 読み込み件数（null=全件）
     * @return array|false uint32値の配列（失敗時はfalse）
     */
    public static function read_uint32_array( string $filepath, ?int $offset = null, ?int $count = null ) {
        if ( $offset !== null && $count !== null ) {
            // 部分読み込み
            $byte_offset = $offset * 4;
            $byte_length = $count * 4;
            $binary = self::read_file_partial( $filepath, $byte_offset, $byte_length );
        } else {
            // 全件読み込み
            $binary = self::read_file( $filepath );
        }

        if ( $binary === false ) {
            return false;
        }

        if ( $binary === '' ) {
            return [];
        }

        // 一括unpack（リトルエンディアン uint32）
        return array_values( unpack( 'V*', $binary ) );
    }

    /**
     * uint16配列をファイルから読み込み
     *
     * @param string $filepath ファイルパス
     * @param int|null $offset 開始インデックス（行番号、0始まり）
     * @param int|null $count 読み込み件数（null=全件）
     * @return array|false uint16値の配列（失敗時はfalse）
     */
    public static function read_uint16_array( string $filepath, ?int $offset = null, ?int $count = null ) {
        if ( $offset !== null && $count !== null ) {
            $byte_offset = $offset * 2;
            $byte_length = $count * 2;
            $binary = self::read_file_partial( $filepath, $byte_offset, $byte_length );
        } else {
            $binary = self::read_file( $filepath );
        }

        if ( $binary === false ) {
            return false;
        }

        if ( $binary === '' ) {
            return [];
        }

        // 一括unpack（リトルエンディアン uint16）
        return array_values( unpack( 'v*', $binary ) );
    }

    /**
     * uint8配列をファイルから読み込み
     *
     * @param string $filepath ファイルパス
     * @param int|null $offset 開始インデックス（行番号、0始まり）
     * @param int|null $count 読み込み件数（null=全件）
     * @return array|false uint8値の配列（失敗時はfalse）
     */
    public static function read_uint8_array( string $filepath, ?int $offset = null, ?int $count = null ) {
        if ( $offset !== null && $count !== null ) {
            $byte_offset = $offset;
            $byte_length = $count;
            $binary = self::read_file_partial( $filepath, $byte_offset, $byte_length );
        } else {
            $binary = self::read_file( $filepath );
        }

        if ( $binary === false ) {
            return false;
        }

        if ( $binary === '' ) {
            return [];
        }

        // 一括unpack（unsigned char 8bit）
        return array_values( unpack( 'C*', $binary ) );
    }

    // =======================================================================
    // 幅の自己判定読み込み（T113 #1477）
    // =======================================================================

    /**
     * 拡幅列（旧幅ファイルが実在しうる列）の実際の型を、実ファイルの大きさから自己判定する
     *
     * T113 (#1477) で uint16/uint8 → uint32 へ拡幅した列は、拡幅前に書かれた
     * 旧幅の列ファイルが実在する。列ファイルは自己記述的でない（ヘッダーに型情報が無い）ため、
     * 同日の pv_id 列（常に uint32・必ず存在＝manifest の後方互換判定が依存する既存の不変条件）
     * から行数を割り出し、対象列のデータサイズ ÷ 行数 で実際の幅を読む直前に判定する。
     * ファイルは自分の大きさについて嘘をつけない＝外部の記録（manifest 等）との
     * 乖離が原理的に存在しない。
     *
     * 【重要・本判定の前提（変更するな）】
     * 1. 適用は QAHM_ColumnDB_Schema::WIDENED_UINT32_COLUMNS の12列のみ。
     *    元から uint32 の列（pv_id 等）はこの関数を通さず従来どおりスキーマ型で読むこと
     *    （誤判定リスクの面積を無用に広げない）。スキーマ（SCHEMA_*）が「あるべき型」の正であり、
     *    本判定は「旧幅か新幅か」の解決だけに使う。型推定システムではない。
     * 2. 自己判定を信頼してよいのは manifest が done の日だけ。
     *    行数不一致の破損日（部分書き込み等）では偶然サイズが割り切れて幅を誤判定しうるが、
     *    そのような日は manifest が done にならず（converting で弾かれ）読み手が元々 skip する。
     *    将来 done 判定を緩めるとこの前提が静かに崩れる。
     *
     * @param string $filepath 対象列ファイルパス（{dataset}_{YYYYMMDD}_{column}.php）
     * @param string $pvid_filepath 同日・同データセットの pv_id 列ファイルパス
     * @return string|false 型名（'uint32'|'uint16'|'uint8'）。判定不能・破損時は false
     */
    public static function detect_column_type( string $filepath, string $pvid_filepath ) {
        // 行数の正＝pv_id 列（常に uint32）
        $pvid_size = self::get_data_size( $pvid_filepath );
        if ( $pvid_size === false || $pvid_size % 4 !== 0 ) {
            return false;
        }
        $rows = (int) ( $pvid_size / 4 );

        $data_size = self::get_data_size( $filepath );
        if ( $data_size === false ) {
            return false;
        }

        // 空日（rows=0）は判定材料が無いが読むものも無い＝どの型で読んでも空配列。新幅を返す
        if ( $rows === 0 ) {
            return $data_size === 0 ? 'uint32' : false;
        }

        if ( $data_size === $rows * 4 ) {
            return 'uint32';                 // 新幅
        }
        if ( $data_size === $rows * 2 ) {
            return 'uint16';                 // 旧幅（拡幅前に書かれたファイル）
        }
        if ( $data_size === $rows ) {
            return 'uint8';                  // 旧幅（medium_id の拡幅前ファイル）
        }

        // どの幅でも行数が合わない＝破損（健全性チェックとして検出できる）
        return false;
    }

    /**
     * 拡幅列を、実ファイルの幅を自己判定して全件読み込む
     *
     * detect_column_type() の前提・制約はそのまま本関数にも適用される。
     * 部分読み込み（offset/count）には対応しない（拡幅12列の読み手は全件読みのみ）。
     *
     * @param string $filepath 対象列ファイルパス
     * @param string $pvid_filepath 同日・同データセットの pv_id 列ファイルパス
     * @return array|false 値の配列（判定不能・破損・読み失敗時は false）
     */
    public static function read_column_auto( string $filepath, string $pvid_filepath ) {
        $type = self::detect_column_type( $filepath, $pvid_filepath );
        switch ( $type ) {
            case 'uint32':
                return self::read_uint32_array( $filepath );
            case 'uint16':
                return self::read_uint16_array( $filepath );
            case 'uint8':
                return self::read_uint8_array( $filepath );
            default:
                return false;
        }
    }

    // =======================================================================
    // ユーティリティ関数
    // =======================================================================

    /**
     * ファイルのデータサイズを取得（PHPヘッダーを除く）
     *
     * @param string $filepath ファイルパス
     * @return int|false データサイズ（バイト）、失敗時はfalse
     */
    public static function get_data_size( string $filepath ) {
        if ( ! file_exists( $filepath ) ) {
            return false;
        }

        $total_size = filesize( $filepath );
        if ( $total_size === false ) {
            return false;
        }

        return max( 0, $total_size - self::HEADER_SIZE );
    }

    /**
     * 指定した型でのレコード数を取得
     *
     * @param string $filepath ファイルパス
     * @param int $bytes_per_record 1レコードあたりのバイト数
     * @return int|false レコード数、失敗時はfalse
     */
    public static function get_row_count( string $filepath, int $bytes_per_record ) {
        $data_size = self::get_data_size( $filepath );
        if ( $data_size === false ) {
            return false;
        }

        return (int) ( $data_size / $bytes_per_record );
    }

    /**
     * FNV-1a 64bit ハッシュを計算
     * セッションIDのハッシュテーブル用
     *
     * @param string $data ハッシュ対象データ
     * @return string 8バイトのバイナリハッシュ
     */
    public static function fnv1a_64( string $data ): string {
        // PHPのhash関数を使用
        $hash = hash( 'fnv1a64', $data, true );
        return $hash;
    }

    /**
     * FNV-1a 64bit ハッシュを計算してuint64として取得
     *
     * @param string $data ハッシュ対象データ
     * @return array [lower32, upper32] の配列
     */
    public static function fnv1a_64_as_uint64( string $data ): array {
        $hash = self::fnv1a_64( $data );

        // 8バイトを2つの32bitに分割
        $unpacked = unpack( 'Vlower/Vupper', $hash );
        return [ $unpacked['lower'], $unpacked['upper'] ];
    }
}
