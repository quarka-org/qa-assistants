<?php
/**
 * 列DB ライタークラス（高レベル層）
 *
 * 列DBへのデータ書き込みを統括する。
 * 日次バッファを管理し、日付が変わる際にファイル出力を行う。
 *
 * @package qa_heatmap
 */

class QAHM_ColumnDB_Writer {

    /**
     * データセット名
     *
     * @var string
     */
    private string $dataset_name;

    /**
     * データセットID
     *
     * @var int
     */
    private int $dataset_id;

    /**
     * 追跡ID
     *
     * @var string
     */
    private string $tracking_id;

    /**
     * 出力ベースディレクトリ
     *
     * @var string
     */
    private string $base_dir;

    /**
     * 日次バッファのマップ
     *
     * @var array date => QAHM_ColumnDB_DailyBuffer
     */
    private array $buffers = [];

    /**
     * 日付ごとの行数
     *
     * @var array date => row_count
     */
    private array $row_counts = [];

    /**
     * コンストラクタ
     *
     * @param string $dataset_name データセット名（'allpv' or 'click_event'）
     * @param string $tracking_id 追跡ID
     * @param string|null $base_dir ベースディレクトリ（null=デフォルト）
     */
    public function __construct( string $dataset_name, string $tracking_id, ?string $base_dir = null ) {
        $this->dataset_name = $dataset_name;
        $this->tracking_id = $tracking_id;

        $this->dataset_id = QAHM_ColumnDB_Schema::get_dataset_id( $dataset_name );
        if ( $this->dataset_id === null ) {
            throw new Exception( 'Unknown dataset name: ' . $dataset_name ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal value, not user input.
        }

        if ( $base_dir === null ) {
            if ( defined( 'WP_CONTENT_DIR' ) ) {
                $base_dir = WP_CONTENT_DIR . '/qa-zero-data/report/' . $tracking_id . '/columns-db/' . $dataset_name . '/';
            } else {
                throw new Exception( 'WP_CONTENT_DIR is not defined and base_dir is not specified' );
            }
        }

        $this->base_dir = $base_dir;

        // ディレクトリ作成
        if ( ! is_dir( $this->base_dir ) ) {
            mkdir( $this->base_dir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
        }
    }

    /**
     * 1行書き込み
     *
     * @param array $row_data カラム名 => 値 の連想配列
     * @param string $date 日付（YYYYMMDD形式）
     * @return bool 成功/失敗
     */
    public function write_row( array $row_data, string $date ): bool {
        // 日付のバッファを取得または作成
        if ( ! isset( $this->buffers[ $date ] ) ) {
            $this->buffers[ $date ] = new QAHM_ColumnDB_DailyBuffer( $this->dataset_id, $date );
            $this->row_counts[ $date ] = 0;
        }

        $buffer = $this->buffers[ $date ];

        // 行を追加
        $need_flush = $buffer->add_row( $row_data );
        $this->row_counts[ $date ]++;

        // フラッシュが必要な場合
        if ( $need_flush ) {
            return $buffer->flush( $this->base_dir );
        }

        return true;
    }

    /**
     * 特定日付のバッファをフラッシュ
     *
     * @param string $date 日付（YYYYMMDD形式）
     * @return bool 成功/失敗
     */
    public function flush_day( string $date ): bool {
        if ( ! isset( $this->buffers[ $date ] ) ) {
            return true;
        }

        $buffer = $this->buffers[ $date ];
        if ( $buffer->is_empty() ) {
            return true;
        }

        return $buffer->flush( $this->base_dir );
    }

    /**
     * 特定日付を完了（バッファをフラッシュ）
     *
     * @param string $date 日付（YYYYMMDD形式）
     * @return bool 成功/失敗
     */
    public function finalize_day( string $date ): bool {
        return $this->flush_day( $date );
    }

    /**
     * 全日付を完了（全バッファをフラッシュ）
     *
     * @return bool 成功/失敗
     */
    public function finalize(): bool {
        foreach ( array_keys( $this->buffers ) as $date ) {
            if ( ! $this->finalize_day( $date ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * 日付一覧を取得（ファイルシステムから）
     *
     * @return array 日付の配列（YYYYMMDD形式）
     */
    public function get_dates(): array {
        $dates = [];
        $schema = QAHM_ColumnDB_Schema::get_schema( $this->dataset_id );
        $first_column = array_key_first( $schema );

        // 月ディレクトリを検索
        $month_dirs = glob( $this->base_dir . '*/'. '', GLOB_ONLYDIR );
        foreach ( $month_dirs as $month_dir ) {
            // 代表カラムでファイル名から日付を抽出
            $pattern = $month_dir . $this->dataset_name . '_*_' . $first_column . '.php';
            $files = glob( $pattern );
            foreach ( $files as $file ) {
                if ( preg_match( '/_(\d{8})_/', basename( $file ), $m ) ) {
                    $dates[] = $m[1];
                }
            }
        }

        sort( $dates );
        return $dates;
    }

    /**
     * 特定日付の行数を取得（ファイルサイズから計算）
     *
     * @param string $date 日付（YYYYMMDD形式）
     * @return int|false 行数、またはデータがない場合はfalse
     */
    public function get_row_count( string $date ) {
        $schema = QAHM_ColumnDB_Schema::get_schema( $this->dataset_id );
        $first_column = array_key_first( $schema );
        $bytes_per_value = $schema[ $first_column ]['bytes'];

        $year_month = substr( $date, 0, 6 );
        $filepath = $this->base_dir . $year_month . '/' . $this->dataset_name . '_' . $date . '_' . $first_column . '.php';

        if ( ! file_exists( $filepath ) ) {
            return false;
        }

        $data_size = filesize( $filepath ) - QAHM_ColumnDB_BinaryIO::HEADER_SIZE;
        return intdiv( $data_size, $bytes_per_value );
    }

    /**
     * ベースディレクトリを取得
     *
     * @return string
     */
    public function get_base_dir(): string {
        return $this->base_dir;
    }

    /**
     * データセット名を取得
     *
     * @return string
     */
    public function get_dataset_name(): string {
        return $this->dataset_name;
    }

    /**
     * 追跡IDを取得
     *
     * @return string
     */
    public function get_tracking_id(): string {
        return $this->tracking_id;
    }
}
