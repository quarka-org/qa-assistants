<?php
/**
 * 列DB 日次バッファクラス（中間層）
 *
 * 1日分のデータをメモリにバッファリングし、
 * 一定量または日が変わる際にファイルに書き込む。
 *
 * @package qa_heatmap
 */

class QAHM_ColumnDB_DailyBuffer {

    /**
     * フラッシュ閾値（行数）
     * この行数を超えるとファイルに書き込む
     */
    const FLUSH_THRESHOLD = 10000;

    /**
     * データセットID
     *
     * @var int
     */
    private int $dataset_id;

    /**
     * 日付（YYYYMMDD形式）
     *
     * @var string
     */
    private string $date;

    /**
     * スキーマ定義
     *
     * @var array
     */
    private array $schema;

    /**
     * カラムごとのバッファ
     *
     * @var array column_name => [values...]
     */
    private array $buffers = [];

    /**
     * 累計行数
     *
     * @var int
     */
    private int $total_rows = 0;

    /**
     * コンストラクタ
     *
     * @param int $dataset_id データセットID
     * @param string $date 日付（YYYYMMDD形式）
     */
    public function __construct( int $dataset_id, string $date ) {
        $this->dataset_id = $dataset_id;
        $this->date = $date;

        $this->schema = QAHM_ColumnDB_Schema::get_schema( $dataset_id );
        if ( $this->schema === null ) {
            throw new Exception( 'Unknown dataset ID: ' . $dataset_id ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal value, not user input.
        }

        // 各カラムのバッファを初期化
        foreach ( array_keys( $this->schema ) as $column_name ) {
            $this->buffers[ $column_name ] = [];
        }
    }

    /**
     * 1行追加
     *
     * @param array $row_data カラム名 => 値 の連想配列
     * @return bool フラッシュが必要な場合true
     */
    public function add_row( array $row_data ): bool {
        foreach ( $this->schema as $column_name => $column_def ) {
            // 値を取得（nullableの場合はデフォルト値を使用）
            if ( isset( $row_data[ $column_name ] ) ) {
                $value = $row_data[ $column_name ];
            } elseif ( $column_def['nullable'] ) {
                $value = $column_def['default'] ?? 0;
            } else {
                throw new Exception( 'Required column missing: ' . $column_name ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal value, not user input.
            }

            // NULL値の処理
            if ( $value === null ) {
                $value = $column_def['default'] ?? 0;
            }

            $this->buffers[ $column_name ][] = (int) $value;
        }

        $this->total_rows++;

        return $this->should_flush();
    }

    /**
     * フラッシュが必要か判定
     *
     * @return bool
     */
    public function should_flush(): bool {
        $current_count = count( $this->buffers[ array_key_first( $this->buffers ) ] );
        return $current_count >= self::FLUSH_THRESHOLD;
    }

    /**
     * バッファの内容をファイルに書き込み
     *
     * @param string $base_dir ベースディレクトリ（末尾スラッシュあり）
     * @return bool 成功/失敗
     */
    public function flush( string $base_dir ): bool {
        $dataset_name = QAHM_ColumnDB_Schema::get_dataset_name( $this->dataset_id );

        // 日付から年月ディレクトリを計算
        $year_month = substr( $this->date, 0, 6 );
        $output_dir = $base_dir . $year_month . '/';

        // ディレクトリ作成
        if ( ! is_dir( $output_dir ) ) {
            if ( ! mkdir( $output_dir, 0755, true ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
                return false;
            }
        }

        // 各カラムをファイルに追記
        foreach ( $this->schema as $column_name => $column_def ) {
            $filepath = $output_dir . $dataset_name . '_' . $this->date . '_' . $column_name . '.php';
            $values = $this->buffers[ $column_name ];

            if ( empty( $values ) ) {
                continue;
            }

            $success = $this->write_column_values( $filepath, $values, $column_def['type'] );
            if ( ! $success ) {
                return false;
            }
        }

        // バッファをクリア
        foreach ( array_keys( $this->buffers ) as $column_name ) {
            $this->buffers[ $column_name ] = [];
        }

        return true;
    }

    /**
     * カラム値をファイルに書き込み
     *
     * @param string $filepath ファイルパス
     * @param array $values 値の配列
     * @param string $type データ型
     * @return bool 成功/失敗
     */
    private function write_column_values( string $filepath, array $values, string $type ): bool {
        switch ( $type ) {
            case QAHM_ColumnDB_Schema::TYPE_UINT32:
                return QAHM_ColumnDB_BinaryIO::append_uint32_array( $filepath, $values );
            case QAHM_ColumnDB_Schema::TYPE_UINT16:
                return QAHM_ColumnDB_BinaryIO::append_uint16_array( $filepath, $values );
            case QAHM_ColumnDB_Schema::TYPE_UINT8:
                return QAHM_ColumnDB_BinaryIO::append_uint8_array( $filepath, $values );
            default:
                return false;
        }
    }

    /**
     * バッファ内の行数を取得
     *
     * @return int
     */
    public function get_buffered_rows(): int {
        if ( empty( $this->buffers ) ) {
            return 0;
        }
        return count( $this->buffers[ array_key_first( $this->buffers ) ] );
    }

    /**
     * 累計行数を取得
     *
     * @return int
     */
    public function get_total_rows(): int {
        return $this->total_rows;
    }

    /**
     * 日付を取得
     *
     * @return string YYYYMMDD形式
     */
    public function get_date(): string {
        return $this->date;
    }

    /**
     * データセットIDを取得
     *
     * @return int
     */
    public function get_dataset_id(): int {
        return $this->dataset_id;
    }

    /**
     * バッファが空かどうか
     *
     * @return bool
     */
    public function is_empty(): bool {
        return $this->get_buffered_rows() === 0;
    }
}
