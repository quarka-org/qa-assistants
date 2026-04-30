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
            if ( ! mkdir( $dir, 0755, true ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
                return false;
            }
        }

        $content = self::PHP_HEADER . $binary_data;
        $result = file_put_contents( $filepath, $content, LOCK_EX );
        return $result !== false;
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

        $fp = fopen( $filepath, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if ( $fp === false ) {
            return false;
        }

        // PHPヘッダーを考慮した実際のオフセット
        $actual_offset = self::HEADER_SIZE + $offset;

        fseek( $fp, $actual_offset );
        $data = fread( $fp, $length ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

        return $data;
    }

    // =======================================================================
    // 型特化書き込み関数
    // =======================================================================

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
