<?php
defined( 'ABSPATH' ) || exit;
/**
 * プラグインのログを管理（後コードベース + 前機能を移植 / USE_WP_DEBUG_LOG撤去版）
 *
 * @package qa_heatmap
 */

$GLOBALS['qahm_log'] = new QAHM_Log();

class QAHM_Log extends QAHM_Base {

	// 現在設定しているログの出力レベル
	const LEVEL = self::DEBUG;

	// ログの出力レベル一覧
	const ERROR = 0; // エラー
	const WARN  = 1; // エラーではないが例外的な事
	const INFO  = 2; // 記録したい情報
	const DEBUG = 3; // 開発時に必要な情報

	// ログを削除する際に残す最大行数
	const DELETE_LINE = 10000;

	/**
	 * ログファイルのパスを取得
	 */
	public function get_log_file_path( $file_name = 'qalog.txt' ) {
		global $wp_filesystem;

		$dir = trailingslashit( $this->get_data_dir_path() ) . 'log/';
		if ( ! $wp_filesystem->exists( $dir ) ) {
			$wp_filesystem->mkdir( $dir );
		}
		return $dir . $file_name;
	}

	/**
	 * ログの公開鍵ファイルのパスを取得（QA Assistants 用）
	 */
	public function get_key_file_path() {
		return plugin_dir_path( __FILE__ ) . 'key/qalog.pem';
	}

	/**
	 * 一定の行数まで溜まったログを削除
	 */
	public function delete( $file_name = 'qalog.txt' ) {
		global $wp_filesystem;
		$path_log = $this->get_log_file_path( $file_name );

		if ( ! $wp_filesystem->exists( $path_log ) ) {
			return;
		}

		$log_contents = $wp_filesystem->get_contents( $path_log );
		if ( $log_contents === false ) {
			return;
		}

		$log_ary = $this->wrap_explode( PHP_EOL, $log_contents );
		if ( self::DELETE_LINE >= $this->wrap_count( $log_ary ) ) {
			return;
		}

		array_splice( $log_ary, self::DELETE_LINE );
		$log_contents = $this->wrap_implode( PHP_EOL, $log_ary );
		$wp_filesystem->put_contents( $path_log, $log_contents );
	}

	/**
	 * ログ出力（実体）
	 *
	 * @param mixed  $log       文字列または配列
	 * @param string $file_name ログファイル名
	 * @param int    $level     自クラス定義のレベル定数
	 * @param array  $backtrace debug_backtrace() の結果（浅い1件想定）
	 * @return string 出力した（または暗号化前の）ログ文字列（平文）
	 */
	private function log( $log, $file_name, $level, $backtrace ) {
		if ( self::LEVEL < $level ) {
			return '';
		}

		global $wp_filesystem, $qahm_time;

		$path_log = $this->get_log_file_path( $file_name );
		$path_key = $this->get_key_file_path();

		switch ( $level ) {
			case self::ERROR:
				$level_str = 'ERROR';
				break;
			case self::WARN:
				$level_str = 'WARNING';
				break;
			case self::INFO:
				$level_str = 'INFO';
				break;
			case self::DEBUG:
				$level_str = 'DEBUG';
				break;
			default:
				$level_str = 'INFO';
				break;
		}

		// 呼び出し元ファイル/行
		$file = isset( $backtrace[0]['file'] ) ? basename( $backtrace[0]['file'] ) : '-';
		$line = isset( $backtrace[0]['line'] ) ? $backtrace[0]['line'] : '-';

		// 配列は Plugin Check を意識した独自整形
		if ( is_array( $log ) ) {
			$log = $this->array_to_string( $log );
		}

		// タイムスタンプ
		if ( isset( $qahm_time ) && method_exists( $qahm_time, 'now_str' ) ) {
			$time = '[' . $qahm_time->now_str() . ']';
		} else {
			$time = '[Unknown time]';
		}

		$plain = sprintf(
			'%s %s, %s, %s:%s, %s',
			$time,
			$level_str,
			defined( 'QAHM_PLUGIN_VERSION' ) ? QAHM_PLUGIN_VERSION : '0.0.0',
			$file,
			$line,
			$log
		);

		// ---- 製品種別ごとの暗号化方針 ----
		// Differs between ZERO and QA - Start ----------
		$should_encrypt = false;
		if ( defined( 'QAHM_TYPE' ) ) {
			if ( QAHM_TYPE === QAHM_TYPE_WP ) {
				// QA Assistants：原則暗号化
				$should_encrypt = true;
			} elseif ( QAHM_TYPE === QAHM_TYPE_ZERO ) {
				// ZERO：暗号化なし
				$should_encrypt = false;
			}
		}
		// Differs between ZERO and QA - End ----------

		// デバッグ閲覧設定がオンなら暗号化を強制的に無効化（可視化優先）
		if ( ( defined( 'QAHM_DEBUG' ) && defined( 'QAHM_DEBUG_LEVEL' ) && QAHM_DEBUG >= QAHM_DEBUG_LEVEL['debug'] ) ||
			( defined( 'QAHM_CONFIG_VIEW_LOG' ) && QAHM_CONFIG_VIEW_LOG === true )
		) {
			$should_encrypt = false;
		}

		$line_to_write = $plain;

		// QA Assistants かつ暗号化有効の場合だけ公開鍵で暗号化（1行単位）
		if ( $should_encrypt ) {
			if ( ! $wp_filesystem->exists( $path_key ) ) {
				// 公開鍵ファイルが存在しない場合は平文でログ出力
				$line_to_write = $plain;
			} else {
				$key = $wp_filesystem->get_contents( $path_key );
				if ( ! empty( $key ) ) {
					$crypted = '';
                    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.crypto_openssl_public_encrypt  
					if ( openssl_public_encrypt( $plain, $crypted, $key ) && ! empty( $crypted ) ) {
						$line_to_write = base64_encode( $crypted );
					} else {
						// 暗号化に失敗した場合は平文でログ出力
						$line_to_write = $plain;
					}
				} else {
					// 公開鍵の読み込みに失敗した場合は平文でログ出力
					$line_to_write = $plain;
				}
			}
		}

		$this->file_put_contents_prepend( $path_log, $line_to_write . PHP_EOL );
		return $plain;
	}

	/**
	 * 先頭行にログを追加（ファイルロック対応版）
	 * ファイルロックを実装し、同時書き込み時の競合状態を防ぐ
	 */
	private function file_put_contents_prepend( $path, $data ) {
		// Plugin Check exclusion: Uses direct file operations with lock for internal logging; WP_Filesystem not available in this context
        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_flock, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		// ファイルが存在しない場合は新規作成
		if ( ! file_exists( $path ) ) {
			file_put_contents( $path, $data, LOCK_EX );
			return;
		}

		// ロック付きで先頭追記
		if ( ! $fp = fopen( $path, 'c+b' ) ) {
			return false;
		}

		flock( $fp, LOCK_EX );
		$existing_contents = stream_get_contents( $fp );
		rewind( $fp );
		ftruncate( $fp, 0 );

		$result = fwrite( $fp, $data . $existing_contents );
		fflush( $fp );
		flock( $fp, LOCK_UN );
		fclose( $fp );

        // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_flock, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $result;
	}

	/**
	 * errorレベルのログを出力
	 */
	public function error( $log, $file_name = 'qalog.txt' ) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace  
		return $this->log( $log, $file_name, self::ERROR, debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 1 ) );
	}

	/**
	 * warningレベルのログを出力
	 */
	public function warning( $log, $file_name = 'qalog.txt' ) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace  
		return $this->log( $log, $file_name, self::WARN, debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 1 ) );
	}

	/**
	 * infoレベルのログを出力
	 */
	public function info( $log, $file_name = 'qalog.txt' ) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace  
		return $this->log( $log, $file_name, self::INFO, debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 1 ) );
	}

	/**
	 * debugレベルのログを出力
	 */
	public function debug( $log, $file_name = 'qalog.txt' ) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace  
		return $this->log( $log, $file_name, self::DEBUG, debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 1 ) );
	}

	/**
	 * 配列を文字列に変換（Plugin Check 対応）
	 * print_r を避け、再帰的に整形出力
	 */
	private function array_to_string( $array, $indent = 0 ) {
		$output = '';
		$prefix = str_repeat( ' ', $indent * 4 ); // インデントをスペースで作成

		foreach ( $array as $key => $value ) {
			$output .= $prefix . '[' . $key . '] => ';

			if ( is_array( $value ) ) {
				$output .= "Array\\n";
				$output .= $this->array_to_string( $value, $indent + 1 ); // 再帰
			} else {
				$output .= $value . "\\n";
			}
		}
		return $output;
	}
}
