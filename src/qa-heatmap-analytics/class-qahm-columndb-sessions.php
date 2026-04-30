<?php
/**
 * 列DB セッションID管理クラス
 *
 * バイナリハッシュテーブル形式でセッションIDを管理する。
 * 同一のqa_id + session_start_time の組み合わせに対して
 * 一意のsession_idを採番・返却する。
 *
 * ファイル構造:
 * - sessions.php: ヘッダー(16バイト) + ハッシュテーブル
 *
 * ヘッダー構造（16バイト）:
 * - [0-3]   magic: "SESS" (4バイト)
 * - [4-7]   version: uint32 (4バイト)
 * - [8-11]  next_session_id: uint32 (4バイト) - 次に採番するID
 * - [12-15] reserved: 4バイト
 *
 * ハッシュテーブル:
 * - 64Kバケット × 20バイト/エントリ = 約1.3MB
 * - 各エントリ: [hash_upper4][session_start_time4][session_id4][chain_offset4][flags4]
 *
 * @package qa_heatmap
 */

class QAHM_ColumnDB_Sessions {

    /**
     * マジックナンバー
     */
    const MAGIC = "SESS";

    /**
     * 現在のバージョン
     */
    const VERSION = 1;

    /**
     * ヘッダーサイズ（バイト）
     */
    const HEADER_SIZE = 16;

    /**
     * バケット数（64K = 65536）
     */
    const BUCKET_COUNT = 65536;

    /**
     * エントリサイズ（バイト）
     */
    const ENTRY_SIZE = 20;

    /**
     * エントリが使用中であることを示すフラグ
     */
    const FLAG_USED = 1;

    /**
     * 追跡ID
     *
     * @var string
     */
    private string $tracking_id;

    /**
     * セッションファイルパス
     *
     * @var string
     */
    private string $filepath;

    /**
     * ファイルハンドル
     *
     * @var resource|null
     */
    private $fp = null;

    /**
     * 次に採番するセッションID
     *
     * @var int
     */
    private int $next_session_id = 1;

    /**
     * メモリキャッシュ（今回の処理で採番したもの）
     *
     * @var array hash_key => session_id
     */
    private array $cache = [];

    /**
     * 変更フラグ
     *
     * @var bool
     */
    private bool $dirty = false;

    /**
     * コンストラクタ
     *
     * @param string $tracking_id 追跡ID
     * @param string|null $base_dir ベースディレクトリ（null=デフォルト）
     */
    public function __construct( string $tracking_id, ?string $base_dir = null ) {
        $this->tracking_id = $tracking_id;

        if ( $base_dir === null ) {
            // WordPressのデータディレクトリを使用
            if ( defined( 'WP_CONTENT_DIR' ) ) {
                $base_dir = WP_CONTENT_DIR . '/qa-zero-data/report/' . $tracking_id . '/columns-db/allpv/';
            } else {
                throw new Exception( 'WP_CONTENT_DIR is not defined and base_dir is not specified' );
            }
        }

        // ディレクトリ作成
        if ( ! is_dir( $base_dir ) ) {
            mkdir( $base_dir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
        }

        $this->filepath = $base_dir . 'sessions.php';
        $this->initialize();
    }

    /**
     * ファイルを初期化または読み込み
     */
    private function initialize(): void {
        $exists = file_exists( $this->filepath );

        if ( $exists ) {
            // 既存ファイルを読み込み
            $this->fp = fopen( $this->filepath, 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            if ( $this->fp === false ) {
                throw new Exception( 'Failed to open sessions file: ' . $this->filepath ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal path.
            }

            // PHPヘッダーをスキップ
            fseek( $this->fp, QAHM_ColumnDB_BinaryIO::HEADER_SIZE );

            // ヘッダーを読み込み
            $header = fread( $this->fp, self::HEADER_SIZE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
            $magic = substr( $header, 0, 4 );
            if ( $magic !== self::MAGIC ) {
                throw new Exception( 'Invalid sessions file magic: ' . bin2hex( $magic ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal binary data.
            }

            $unpacked = unpack( 'Vversion/Vnext_session_id', substr( $header, 4, 8 ) );
            $this->next_session_id = $unpacked['next_session_id'];
        } else {
            // 新規作成
            $this->create_new_file();
        }
    }

    /**
     * 新しいセッションファイルを作成
     */
    private function create_new_file(): void {
        // ディレクトリ作成
        $dir = dirname( $this->filepath );
        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
        }

        // PHPセキュリティヘッダー + ヘッダー + 空のハッシュテーブルを作成
        $php_header = QAHM_ColumnDB_BinaryIO::PHP_HEADER;

        // ヘッダー
        $header = self::MAGIC;
        $header .= pack( 'V', self::VERSION );
        $header .= pack( 'V', 1 ); // next_session_id = 1
        $header .= pack( 'V', 0 ); // reserved

        // 空のハッシュテーブル（全バケットをゼロで初期化）
        $empty_table = str_repeat( "\x00", self::BUCKET_COUNT * self::ENTRY_SIZE );

        // ファイル書き込み
        file_put_contents( $this->filepath, $php_header . $header . $empty_table, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

        // ファイルを開く
        $this->fp = fopen( $this->filepath, 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if ( $this->fp === false ) {
            throw new Exception( 'Failed to create sessions file: ' . $this->filepath ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal path.
        }

        $this->next_session_id = 1;
    }

    /**
     * セッションIDを取得または新規作成
     *
     * @param string $qa_id ユーザー識別子
     * @param int $session_start_time セッション開始時刻（Unix時刻）
     * @return int セッションID
     */
    public function get_or_create( string $qa_id, int $session_start_time ): int {
        // ハッシュキーを生成
        $hash_key = $qa_id . ':' . $session_start_time;

        // メモリキャッシュを確認
        if ( isset( $this->cache[ $hash_key ] ) ) {
            return $this->cache[ $hash_key ];
        }

        // FNV-1a 64bitハッシュを計算
        $hash = QAHM_ColumnDB_BinaryIO::fnv1a_64( $hash_key );
        $hash_parts = unpack( 'Vlower/Vupper', $hash );
        $hash_lower = $hash_parts['lower'];
        $hash_upper = $hash_parts['upper'];

        // バケットインデックスを計算（下位16bit）
        $bucket_index = $hash_lower & 0xFFFF;

        // バケットを検索
        $session_id = $this->lookup_bucket( $bucket_index, $hash_upper, $session_start_time );

        if ( $session_id !== null ) {
            // 既存のセッションIDを返す
            $this->cache[ $hash_key ] = $session_id;
            return $session_id;
        }

        // 新しいセッションIDを採番
        $session_id = $this->next_session_id++;
        $this->dirty = true;

        // バケットに書き込み
        $this->write_to_bucket( $bucket_index, $hash_upper, $session_start_time, $session_id );

        // キャッシュに追加
        $this->cache[ $hash_key ] = $session_id;

        return $session_id;
    }

    /**
     * バケットからセッションIDを検索
     *
     * @param int $bucket_index バケットインデックス
     * @param int $hash_upper ハッシュ上位32bit
     * @param int $session_start_time セッション開始時刻
     * @return int|null セッションID（見つからない場合はnull）
     */
    private function lookup_bucket( int $bucket_index, int $hash_upper, int $session_start_time ): ?int {
        // PHPヘッダー + Sessionsヘッダー + バケット位置
        $offset = QAHM_ColumnDB_BinaryIO::HEADER_SIZE + self::HEADER_SIZE + ( $bucket_index * self::ENTRY_SIZE );

        fseek( $this->fp, $offset );
        $entry = fread( $this->fp, self::ENTRY_SIZE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread

        // エントリを解析
        $unpacked = unpack( 'Vhash_upper/Vsession_start/Vsession_id/Vchain/Vflags', $entry );

        // 未使用バケットの場合
        if ( ( $unpacked['flags'] & self::FLAG_USED ) === 0 ) {
            return null;
        }

        // マッチ確認
        if ( $unpacked['hash_upper'] === $hash_upper && $unpacked['session_start'] === $session_start_time ) {
            return $unpacked['session_id'];
        }

        // チェーンを辿る（線形プロービング）
        $chain = $unpacked['chain'];
        $max_probes = 100; // 無限ループ防止
        $probe_count = 0;

        while ( $chain !== 0 && $probe_count < $max_probes ) {
            $next_offset = QAHM_ColumnDB_BinaryIO::HEADER_SIZE + self::HEADER_SIZE + ( $chain * self::ENTRY_SIZE );
            fseek( $this->fp, $next_offset );
            $entry = fread( $this->fp, self::ENTRY_SIZE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
            $unpacked = unpack( 'Vhash_upper/Vsession_start/Vsession_id/Vchain/Vflags', $entry );

            if ( $unpacked['hash_upper'] === $hash_upper && $unpacked['session_start'] === $session_start_time ) {
                return $unpacked['session_id'];
            }

            $chain = $unpacked['chain'];
            $probe_count++;
        }

        return null;
    }

    /**
     * バケットにエントリを書き込み
     *
     * @param int $bucket_index バケットインデックス
     * @param int $hash_upper ハッシュ上位32bit
     * @param int $session_start_time セッション開始時刻
     * @param int $session_id セッションID
     */
    private function write_to_bucket( int $bucket_index, int $hash_upper, int $session_start_time, int $session_id ): void {
        $offset = QAHM_ColumnDB_BinaryIO::HEADER_SIZE + self::HEADER_SIZE + ( $bucket_index * self::ENTRY_SIZE );

        fseek( $this->fp, $offset );
        $entry = fread( $this->fp, self::ENTRY_SIZE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        $unpacked = unpack( 'Vhash_upper/Vsession_start/Vsession_id/Vchain/Vflags', $entry );

        // 未使用バケットの場合は直接書き込み
        if ( ( $unpacked['flags'] & self::FLAG_USED ) === 0 ) {
            $new_entry = pack(
                'VVVVV',
                $hash_upper,
                $session_start_time,
                $session_id,
                0, // chain = 0
                self::FLAG_USED
            );

            fseek( $this->fp, $offset );
            fwrite( $this->fp, $new_entry ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            return;
        }

        // 衝突: 線形プロービングで空きスロットを探す
        $current_index = $bucket_index;
        $max_probes = self::BUCKET_COUNT;

        for ( $i = 1; $i < $max_probes; $i++ ) {
            $probe_index = ( $bucket_index + $i ) % self::BUCKET_COUNT;
            $probe_offset = QAHM_ColumnDB_BinaryIO::HEADER_SIZE + self::HEADER_SIZE + ( $probe_index * self::ENTRY_SIZE );

            fseek( $this->fp, $probe_offset );
            $probe_entry = fread( $this->fp, self::ENTRY_SIZE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
            $probe_unpacked = unpack( 'Vhash_upper/Vsession_start/Vsession_id/Vchain/Vflags', $probe_entry );

            if ( ( $probe_unpacked['flags'] & self::FLAG_USED ) === 0 ) {
                // 空きスロットを発見
                // 新しいエントリを書き込み
                $new_entry = pack(
                    'VVVVV',
                    $hash_upper,
                    $session_start_time,
                    $session_id,
                    0,
                    self::FLAG_USED
                );

                fseek( $this->fp, $probe_offset );
                fwrite( $this->fp, $new_entry ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

                // 元のエントリのchainを更新（チェーンの最後を見つけて更新）
                $this->update_chain_link( $bucket_index, $probe_index );
                return;
            }
        }

        throw new Exception( 'Hash table is full' );
    }

    /**
     * チェーンリンクを更新
     *
     * @param int $start_index 開始バケットインデックス
     * @param int $new_index 新しいエントリのインデックス
     */
    private function update_chain_link( int $start_index, int $new_index ): void {
        $current_index = $start_index;
        $max_probes = self::BUCKET_COUNT;

        for ( $i = 0; $i < $max_probes; $i++ ) {
            $offset = QAHM_ColumnDB_BinaryIO::HEADER_SIZE + self::HEADER_SIZE + ( $current_index * self::ENTRY_SIZE );
            fseek( $this->fp, $offset );
            $entry = fread( $this->fp, self::ENTRY_SIZE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
            $unpacked = unpack( 'Vhash_upper/Vsession_start/Vsession_id/Vchain/Vflags', $entry );

            if ( $unpacked['chain'] === 0 ) {
                // チェーンの最後を発見、新しいインデックスを設定
                $updated_entry = pack(
                    'VVVVV',
                    $unpacked['hash_upper'],
                    $unpacked['session_start'],
                    $unpacked['session_id'],
                    $new_index,
                    $unpacked['flags']
                );

                fseek( $this->fp, $offset );
                fwrite( $this->fp, $updated_entry ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
                return;
            }

            $current_index = $unpacked['chain'];
        }
    }

    /**
     * 既存のセッションIDを検索（作成しない）
     *
     * @param string $qa_id ユーザー識別子
     * @param int $session_start_time セッション開始時刻
     * @return int|null セッションID（見つからない場合はnull）
     */
    public function lookup( string $qa_id, int $session_start_time ): ?int {
        $hash_key = $qa_id . ':' . $session_start_time;

        // メモリキャッシュを確認
        if ( isset( $this->cache[ $hash_key ] ) ) {
            return $this->cache[ $hash_key ];
        }

        // ハッシュ計算
        $hash = QAHM_ColumnDB_BinaryIO::fnv1a_64( $hash_key );
        $hash_parts = unpack( 'Vlower/Vupper', $hash );
        $hash_lower = $hash_parts['lower'];
        $hash_upper = $hash_parts['upper'];

        $bucket_index = $hash_lower & 0xFFFF;

        return $this->lookup_bucket( $bucket_index, $hash_upper, $session_start_time );
    }

    /**
     * ファイルをフラッシュして閉じる
     */
    public function close(): void {
        if ( $this->fp === null ) {
            return;
        }

        // next_session_idをヘッダーに書き込み
        if ( $this->dirty ) {
            $header_offset = QAHM_ColumnDB_BinaryIO::HEADER_SIZE + 8; // magic + version の後
            fseek( $this->fp, $header_offset );
            fwrite( $this->fp, pack( 'V', $this->next_session_id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        }

        fflush( $this->fp );
        fclose( $this->fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        $this->fp = null;
    }

    /**
     * デストラクタ
     */
    public function __destruct() {
        $this->close();
    }

    /**
     * 現在の次セッションIDを取得
     *
     * @return int 次に採番されるセッションID
     */
    public function get_next_session_id(): int {
        return $this->next_session_id;
    }

    /**
     * 採番済みセッション数を取得
     *
     * @return int セッション数
     */
    public function get_session_count(): int {
        return $this->next_session_id - 1;
    }
}
