<?php
defined( 'ABSPATH' ) || exit;
/**
 * QA Analytics から QA Platform への移行処理
 *
 * @package qa_heatmap
 */

class QAHM_Analytics_Migration extends QAHM_File_Data {
	/**
	 * QA Analyticsデータディレクトリを変換
	 */
	public function convert_qa_analytics() {
		// tracking_idを変換
		$parse_url          = wp_parse_url( get_home_url() );
		$id                 = 'a&' . $parse_url['host'];
		$source_tracking_id = hash( 'fnv164', $id );
		$target_tracking_id = $this->get_tracking_id( $this->to_domain_url( $parse_url ) );

		$this->convert_qa_analytics_data_directory( $source_tracking_id, $target_tracking_id );
		$this->convert_qa_analytics_data_base( $source_tracking_id, $target_tracking_id );
	}

	/**
	 * QA Analyticsデータディレクトリを変換
	 *
	 * IMPORTANT:
	 * - This migration runs **once** on upgrade and must be **atomic and fast**.
	 * - We intentionally use native `rename()` for directory moves because it is
	 *   atomic on the same filesystem and significantly faster than abstracted
	 *   file APIs in most environments.
	 * - Some WP_Filesystem backends (FTP/SSH) perform copy+delete, which is
	 *   non-atomic and slower; for this **one-time, critical migration** we keep
	 *   `rename()` and suppress the Plugin Check warning with a precise ignore tag.
	 * - Any destructive steps (like removing the target dir) are **expected** on
	 *   the first run of this migration and are covered by our release test.
	 *
	 * Note for reviewers:
	 * - We document the rationale here and scope the PHPCS ignore to only the
	 *   `rename()` lines used for the one-time migration.
	 */
	public function convert_qa_analytics_data_directory( $source_tracking_id, $target_tracking_id ) {
		global $wp_filesystem;

		$source_dir = WP_CONTENT_DIR . '/qa-heatmap-analytics-data/';
		$target_dir = WP_CONTENT_DIR . '/qa-zero-data/';

		// QA Analyticsデータが存在しない場合はスキップ
		if ( ! file_exists( $source_dir ) ) {
			return false;
		}

		// 新しいログファイルは空の状態から開始
		if ( file_exists( $source_dir . 'log/qalog.txt' ) ) {
			$wp_filesystem->delete( $source_dir . 'log/qalog.txt' );
		}

		// リネーム前にターゲットディレクトリが存在する場合がある。そのため確実に削除
		if ( file_exists( $target_dir ) ) {
			$wp_filesystem->rmdir( $target_dir, true );
		}

		// ディレクトリをリネーム
		// Perform the atomic directory move on the same filesystem.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- One-time atomic move during upgrade; WP_Filesystem backends may be non-atomic (copy+delete).
		rename( $source_dir, $target_dir );

		// qa-config.phpの設置
		$qahm_activate = new QAHM_Activate();
		$qahm_activate->setup_config_file();

		$this->migrate_config_values();

		// tracking_idディレクトリのリネーム
		// Rename tracking_id directory at the root level (atomic on same FS).
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- One-time atomic rename for ID swap during upgrade.
		rename( $target_dir . $source_tracking_id, $target_dir . $target_tracking_id );
		// Rename view/<tracking_id> directory to match the new ID.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- One-time atomic rename for view path during upgrade.
		rename( $target_dir . 'view/' . $source_tracking_id, $target_dir . 'view/' . $target_tracking_id );

		// readers, view_pvディレクトリの変換
		$this->convert_readers_directory( $target_dir );
		$this->convert_view_pv_directory( $target_dir );

		// qtag_jsの作成
		$this->create_qtag( $target_tracking_id, true );

		return true;
	}


	/**
	 * goalsオプションにtracking_idを追加
	 * QA Analytics から QA Platform への移行処理
	 *
	 * @param string $target_tracking_id 変換先のtracking_id
	 */
	public function convert_goals_to_tracking_id_format( $target_tracking_id ) {
		global $qahm_log;

		$goals = $this->wrap_get_option( 'goals' );

		// 既にtracking_id形式の場合はスキップ
		if ( is_array( $goals ) && isset( $goals[ $target_tracking_id ] ) ) {
			$qahm_log->info( "Goals already in tracking_id format for: {$target_tracking_id}" );
			return true;
		}

		// JSON形式の場合はデコード
		if ( ! is_array( $goals ) ) {
			$goals = json_decode( $goals, true );
		}

		// 旧形式（tracking_idなし）の場合
		if ( is_array( $goals ) && ! empty( $goals ) ) {
			// 最初のキーが数値の場合、旧形式と判断
			$first_key = array_key_first( $goals );
			if ( is_numeric( $first_key ) ) {
				$qahm_log->info( 'Converting goals from old format to tracking_id format' );

				// 新形式に変換
				$new_goals = array(
					$target_tracking_id => $goals,
				);

				$result = $this->wrap_update_option( 'goals', $new_goals );

				if ( $result ) {
					$qahm_log->info( "Successfully converted goals to tracking_id format: {$target_tracking_id}" );
				} else {
					$qahm_log->error( 'Failed to convert goals to tracking_id format' );
				}

				return $result;
			}
		}

		$qahm_log->info( 'No goals conversion needed' );
		return true;
	}

	/**
	 * Google関連パラメータをデフォルト値にリセット
	 * QA Analytics から QA Assistants への移行処理
	 * QA AssistantsはGoogle API連携未対応のため
	 */
	public function reset_google_credentials() {
		global $qahm_log;

		// google_credentialsを空文字列にリセット
		$this->wrap_update_option( 'google_credentials', '' );
		$qahm_log->info( 'Reset google_credentials to empty string' );

		// google_is_redirectをfalseにリセット
		$this->wrap_update_option( 'google_is_redirect', false );
		$qahm_log->info( 'Reset google_is_redirect to false' );

		return true;
	}


	public function copy_directory_recursive( $source, $destination ) {
		global $wp_filesystem;

		if ( ! $wp_filesystem->is_dir( $source ) ) {
			return false;
		}

		if ( ! $this->wrap_mkdir( $destination ) ) {
			return false;
		}

		$files = $this->wrap_dirlist( $source );
		if ( ! $files ) {
			return true;
		}

		foreach ( $files as $file ) {
			$source_path = $source . $file['name'];
			$target_path = $destination . $file['name'];

			if ( $wp_filesystem->is_dir( $source_path ) ) {
				$this->copy_directory_recursive( $source_path . '/', $target_path . '/' );
			} else {
				$wp_filesystem->copy( $source_path, $target_path );
			}
		}

		return true;
	}

	/**
	 * readersディレクトリのセッションファイルを変換
	 */
	public function convert_readers_directory( $target_dir ) {
		$readers_dirs = array( 'temp/', 'finish/', 'dbin/' );

		foreach ( $readers_dirs as $dir ) {
			$dir_path = $target_dir . 'readers/' . $dir;
			if ( ! file_exists( $dir_path ) ) {
				continue;
			}

			$session_files = $this->wrap_dirlist( $dir_path );
			if ( $session_files ) {
				foreach ( $session_files as $file ) {
					$file_path   = $dir_path . $file['name'];
					$legacy_data = $this->wrap_unserialize( $this->wrap_get_contents( $file_path ) );

					if ( $legacy_data ) {
						$converted_data = $this->convert_legacy_session_format( $legacy_data );
						$this->wrap_put_contents( $file_path, $this->wrap_serialize( $converted_data ) );
					}
				}
			}
		}
	}

	/**
	 * レガシーセッション形式を新形式に変換
	 */
	public function convert_legacy_session_format( $legacy_data ) {
		$converted = $legacy_data;

		if ( ! isset( $converted['head']['language'] ) ) {
			$converted['head']['language'] = $converted['head']['country'] ?? null;
		}
		if ( ! isset( $converted['head']['country_code'] ) ) {
			$converted['head']['country_code'] = null;
		}
		if ( ! isset( $converted['head']['is_reject'] ) ) {
			$converted['head']['is_reject'] = 0;
		}

		// データ整合性の確認
		if ( ! isset( $converted['head']['version'] ) ) {
			$converted['head']['version'] = 1;
		}

		return $converted;
	}

	/**
	 * view_pvディレクトリの構造変更処理
	 */
	public function convert_view_pv_directory( $target_dir ) {
		global $wp_filesystem, $qahm_log;

		$view_dir = $target_dir . 'view/';
		if ( ! $wp_filesystem->exists( $view_dir ) ) {
			return true; // viewディレクトリが存在しない場合はスキップ
		}

		$this->convert_qa_analytics_view_pv_access_time();

		// all ディレクトリの作成
		$all_dir = $view_dir . 'all/';
		if ( ! $wp_filesystem->exists( $all_dir ) ) {
			$wp_filesystem->mkdir( $all_dir );
		}

		// tracking_idディレクトリを取得
		$tracking_dirs = $wp_filesystem->dirlist( $view_dir );
		if ( ! $tracking_dirs ) {
			return true;
		}

		foreach ( $tracking_dirs as $dir ) {
			if ( ! is_dir( $view_dir . $dir['name'] ) || $dir['name'] === 'all' ) {
				continue; // allディレクトリはスキップ
			}

			$tracking_id         = $dir['name'];
			$source_tracking_dir = $view_dir . $tracking_id . '/';

			// QA Analyticsでは単一のtracking_idのみなので、全内容をallにコピー
			$qahm_log->info( "Converting tracking_id directory: {$tracking_id}" );

			// 全ディレクトリ内容をall/にコピー
			$this->copy_directory_recursive( $source_tracking_dir, $all_dir );

			// コピー後、元のtracking_idディレクトリからversion_histとreadersを削除
			$source_version_hist = $source_tracking_dir . 'version_hist/';
			$source_readers      = $source_tracking_dir . 'readers/';

			if ( $wp_filesystem->exists( $source_version_hist ) ) {
				$wp_filesystem->delete( $source_version_hist, true );
				$qahm_log->info( "Deleted original version_hist from {$tracking_id}" );
			}

			if ( $wp_filesystem->exists( $source_readers ) ) {
				$wp_filesystem->delete( $source_readers, true );
				$qahm_log->info( "Deleted original readers from {$tracking_id}" );
			}

			// QA Analyticsでは通常1つのtracking_idのみなので、最初の1つで処理完了
			break;
		}

		return true;
	}


	/**
	 * QA AnalyticsからQA Platform仕様への全テーブル変換処理
	 */
	public function convert_qa_analytics_data_base( $source_tracking_id, $target_tracking_id ) {
		global $wpdb;
		global $qahm_log;

		$wpdb->query( 'SET SESSION wait_timeout = 28800' );
		$wpdb->query( 'SET SESSION interactive_timeout = 28800' );
		$qahm_log->info( 'Extended MySQL timeout settings for migration (28800 seconds)' );

		// goalsオプションにtracking_idを追加
		$goals_result = $this->convert_goals_to_tracking_id_format( $target_tracking_id );
		if ( $goals_result ) {
			$qahm_log->info( 'Goals conversion to tracking_id format completed successfully' );
		} else {
			$qahm_log->info( 'Goals conversion failed' );
		}

		// Google関連パラメータをリセット
		$reset_google_result = $this->reset_google_credentials();
		if ( $reset_google_result ) {
			$qahm_log->info( 'Google credentials reset completed successfully' );
		} else {
			$qahm_log->info( 'Google credentials reset failed' );
		}

		// qa_pagesテーブルをQA Platform仕様に変換
		$qa_pages_result = $this->convert_qa_pages_to_platform_spec( $source_tracking_id, $target_tracking_id );

		if ( $qa_pages_result ) {
			$qahm_log->info( 'qa_pages table conversion to QA Platform spec completed successfully' );
		} else {
			$qahm_log->info( 'qa_pages table conversion failed' );
		}

		// qa_readersテーブルをQA Platform仕様に変換
		$qa_readers_result = $this->convert_qa_readers_to_platform_spec();

		if ( $qa_readers_result ) {
			$qahm_log->info( 'qa_readers table conversion to QA Platform spec completed successfully' );
		} else {
			$qahm_log->info( 'qa_readers table conversion failed' );
		}

		// qa_pv_logテーブルをQA Platform仕様に変換
		$qa_pv_log_result = $this->convert_qa_pv_log_to_platform_spec();

		if ( $qa_pv_log_result ) {
			$qahm_log->info( 'qa_pv_log table conversion to QA Platform spec completed successfully' );
		} else {
			$qahm_log->info( 'qa_pv_log table conversion failed' );
		}

		// qa_utm_mediaテーブルをQA Platform仕様に変換
		$qa_utm_media_result = $this->convert_qa_utm_media_to_platform_spec();

		if ( $qa_utm_media_result ) {
			$qahm_log->info( 'qa_utm_media table conversion to QA Platform spec completed successfully' );
		} else {
			$qahm_log->info( 'qa_utm_media table conversion failed' );
		}

		// qa_utm_sourcesテーブルをQA Platform仕様に変換
		$qa_utm_sources_result = $this->convert_qa_utm_sources_to_platform_spec();

		if ( $qa_utm_sources_result ) {
			$qahm_log->info( 'qa_utm_sources table conversion to QA Platform spec completed successfully' );
		} else {
			$qahm_log->info( 'qa_utm_sources table conversion failed' );
		}

		// qa_utm_contentテーブルをQA Platform仕様に変換（新規作成）
		$qa_utm_content_result = $this->convert_qa_utm_content_to_platform_spec();

		if ( $qa_utm_content_result ) {
			$qahm_log->info( 'qa_utm_content table conversion to QA Platform spec completed successfully' );
		} else {
			$qahm_log->info( 'qa_utm_content table conversion failed' );
		}

		// qa_gsc_query_logテーブルをQA Platform仕様に変換
		$qa_gsc_result = $this->convert_qa_gsc_query_log_to_platform_spec();

		if ( $qa_gsc_result ) {
			$qahm_log->info( 'qa_gsc_query_log table conversion to QA Platform spec completed successfully' );
		} else {
			$qahm_log->info( 'qa_gsc_query_log table conversion failed' );
		}

		// qahm_rectermテーブルを削除
		$qahm_recterm_result = $this->drop_recterm_table();
		if ( $qahm_recterm_result ) {
			$qahm_log->info( 'qahm_recterm table dropped successfully' );
		} else {
			$qahm_log->info( 'Failed to drop qahm_recterm table or it does not exist' );
		}
	}

	/**
	 * qa_pagesテーブルをQA Platform仕様に変換
	 */
	public function convert_qa_pages_to_platform_spec( $source_tracking_id, $target_tracking_id ) {
		global $wpdb;
		global $qahm_log;

		// 1. 既存の全インデックスを動的に削除（PRIMARY以外）
		$existing_indexes = $wpdb->get_results( "SHOW INDEX FROM {$wpdb->prefix}qa_pages WHERE Key_name != 'PRIMARY'" );
		$qahm_log->info( 'Found ' . $this->wrap_count( $existing_indexes ) . ' existing indexes in qa_pages table for conversion' );

		foreach ( $existing_indexes as $index ) {
			$qahm_log->info( "Dropping index: {$index->Key_name}" );
			$drop_query = "DROP INDEX `{$index->Key_name}` ON {$wpdb->prefix}qa_pages";
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL with identifiers. Query is built from MySQL metadata (no user input). $wpdb->prepare() does not support identifiers. Safe to run as raw SQL.
			$result = $wpdb->query( $drop_query );
			if ( $result === false ) {
				$qahm_log->info( "Failed to drop index {$index->Key_name}: {$wpdb->last_error}" );
			} else {
				$qahm_log->info( "Successfully dropped index: {$index->Key_name}" );
			}
		}

		// 2. path_url_hashカラムを追加（存在しない場合のみ）
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}qa_pages LIKE 'path_url_hash'" );
		if ( empty( $column_exists ) ) {
			$alter_query = "ALTER TABLE {$wpdb->prefix}qa_pages ADD COLUMN path_url_hash char(16) NULL AFTER url_hash";
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL statement using only trusted table prefix and static SQL. No user input involved; identifiers are not supported by $wpdb->prepare().
			$result = $wpdb->query( $alter_query );
			if ( $result === false ) {
				$qahm_log->info( "SQL Error: {$wpdb->last_error} in query: {$wpdb->last_query}" );
				return false;
			}
		}

		$duplicate_hashes = $wpdb->get_results(
			"SELECT url_hash, COUNT(*) as cnt   
             FROM {$wpdb->prefix}qa_pages   
             GROUP BY url_hash   
             HAVING cnt > 1",
			ARRAY_A
		);

		if ( ! empty( $duplicate_hashes ) ) {
			$qahm_log->info( 'Found ' . $this->wrap_count( $duplicate_hashes ) . ' duplicate url_hash entries, cleaning up before index creation' );

			foreach ( $duplicate_hashes as $dup ) {
				$delete_result = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->prefix}qa_pages   
                         WHERE url_hash = %s   
                         AND page_id NOT IN (  
                             SELECT * FROM (  
                                 SELECT MAX(page_id)   
                                 FROM {$wpdb->prefix}qa_pages   
                                 WHERE url_hash = %s  
                             ) AS tmp  
                         )",
						$dup['url_hash'],
						$dup['url_hash']
					)
				);

				if ( $delete_result !== false ) {
					$qahm_log->info( "Cleaned up {$delete_result} duplicate records for url_hash: {$dup['url_hash']}" );
				} else {
					$qahm_log->error( "Failed to clean up duplicates for url_hash: {$dup['url_hash']} - {$wpdb->last_error}" );
				}
			}
		}

		// 4. QA Platform標準仕様のインデックスを作成
		$create_indexes = array(
			"CREATE UNIQUE INDEX qa_pages_url_hash_uindex ON {$wpdb->prefix}qa_pages (url_hash)",
			"CREATE INDEX {$wpdb->prefix}qa_pages_tracking_id_index ON {$wpdb->prefix}qa_pages (tracking_id)",
			"CREATE INDEX {$wpdb->prefix}qa_pages_path_url_hash_index ON {$wpdb->prefix}qa_pages (path_url_hash)",
		);

		foreach ( $create_indexes as $query ) {
			preg_match( '/(?:CREATE (?:UNIQUE )?INDEX|CREATE INDEX) (\S+)/', $query, $matches );
			$index_name = $matches[1] ?? '';

			if ( empty( $index_name ) ) {
				$qahm_log->warning( "Failed to extract index name from query: {$query}" );
				continue;
			}

			$index_exists = $wpdb->get_results(
				$wpdb->prepare(
					"SHOW INDEX FROM {$wpdb->prefix}qa_pages WHERE Key_name = %s",
					$index_name
				)
			);

			if ( ! empty( $index_exists ) ) {
				$qahm_log->info( "Index {$index_name} already exists, skipping creation" );
				continue;
			}

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- CREATE (UNIQUE) INDEX is DDL with identifiers. The query is internally constructed (no user input). $wpdb->prepare() is not applicable to identifiers.
			$result = $wpdb->query( $query );
			if ( $result === false ) {
				$qahm_log->warning( "SQL Error creating index {$index_name}: {$wpdb->last_error}" );

				if ( $this->wrap_strpos( $query, 'UNIQUE' ) !== false ) {
					$fallback_query = str_replace( 'UNIQUE INDEX', 'INDEX', $query );
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL fallback generated internally (no user input). Uses identifiers, which $wpdb->prepare() cannot parameterize.
					$fallback_result = $wpdb->query( $fallback_query );

					if ( $fallback_result !== false ) {
						$qahm_log->info( "Fallback to non-unique index succeeded: {$index_name}" );
					} else {
						$qahm_log->error( "Fallback also failed for index {$index_name}: {$wpdb->last_error}" );
					}
				}
			} else {
				$qahm_log->info( "Successfully created index: {$index_name}" );
			}
		}

		// 4. 既存データのpath_url_hash値を生成・更新
		$this->update_qa_pages_path_url_hash();

		// 5. tracking_idを更新（冪等性を持たせるためON DUPLICATE KEY UPDATEを使用）
		$existing_pages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}qa_pages WHERE tracking_id = %s OR tracking_id = %s",
				$source_tracking_id,
				$target_tracking_id
			),
			ARRAY_A
		);

		if ( empty( $existing_pages ) ) {
			$qahm_log->info( 'No pages found for tracking_id conversion' );
			return true;
		}

		$qahm_log->info( 'Found ' . $this->wrap_count( $existing_pages ) . ' pages to convert' );

		$batch_size      = 1000;
		$total_converted = 0;
		$batches         = array_chunk( $existing_pages, $batch_size );

		foreach ( $batches as $batch_index => $batch ) {
			$values       = array();
			$placeholders = array();

			foreach ( $batch as $page ) {
				$values[]       = $target_tracking_id;
				$values[]       = $page['wp_qa_type'];
				$values[]       = $page['wp_qa_id'];
				$values[]       = $page['url'];
				$values[]       = $page['url_hash'];
				$values[]       = $page['path_url_hash'];
				$values[]       = $page['title'];
				$values[]       = $page['update_date'];
				$placeholders[] = '(%s, %s, %d, %s, %s, %s, %s, %s)';
			}

			$sql = "INSERT INTO {$wpdb->prefix}qa_pages " .
				'(tracking_id, wp_qa_type, wp_qa_id, url, url_hash, path_url_hash, title, update_date) ' .
				'VALUES ' . join( ',', $placeholders ) . ' ' .
				'ON DUPLICATE KEY UPDATE ' .
				'tracking_id = VALUES(tracking_id), ' .
				'title = VALUES(title), ' .
				'update_date = VALUES(update_date)';

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This SQL query uses placeholders and $wpdb->prepare(), but it may trigger warnings due to the dynamic construction of the SQL string.  
			$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );

			if ( $result === false ) {
				$qahm_log->error( "Failed to update tracking_id for batch {$batch_index}: {$wpdb->last_error}" );
				return false;
			}

			$total_converted += $this->wrap_count( $batch );
			$qahm_log->info( "Batch {$batch_index}: Converted " . $this->wrap_count( $batch ) . " records (Total: {$total_converted})" );
		}

		$qahm_log->info( "Successfully converted {$total_converted} records to tracking_id: {$target_tracking_id}" );

		return true;
	}



	/**
	 * 既存データのpath_url_hash値を生成・更新
	 */
	public function update_qa_pages_path_url_hash() {
		global $wpdb;

		$pages = $wpdb->get_results(
			"SELECT page_id, url FROM {$wpdb->prefix}qa_pages WHERE path_url_hash IS NULL",
			ARRAY_A
		);

		foreach ( $pages as $page ) {
			if ( ! empty( $page['url'] ) ) {
				$path_url      = $this->to_path_url( $page['url'] );
				$path_url_hash = hash( 'fnv164', $path_url );

				$wpdb->update(
					$wpdb->prefix . 'qa_pages',
					array( 'path_url_hash' => $path_url_hash ),
					array( 'page_id' => $page['page_id'] ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}
	}

	/**
	 * qa_readersテーブルをQA Platform仕様に変換（country_code除く）
	 */
	public function convert_qa_readers_to_platform_spec() {
		global $wpdb;
		global $qahm_log;

		// 1. 既存の全インデックスを動的に削除（PRIMARY以外）
		$existing_indexes = $wpdb->get_results( "SHOW INDEX FROM {$wpdb->prefix}qa_readers WHERE Key_name != 'PRIMARY'" );
		$qahm_log->info( 'Found ' . $this->wrap_count( $existing_indexes ) . ' existing indexes in qa_readers table for conversion' );

		foreach ( $existing_indexes as $index ) {
			// Keep the original case from SHOW INDEX to avoid case-mismatch issues.
			// Escape backticks inside identifier just in case (`` → two backticks).
			$index_name = str_replace( '`', '``', $index->Key_name );

			$qahm_log->info( "Dropping index: {$index_name}" );
			$drop_query = "DROP INDEX `{$index_name}` ON {$wpdb->prefix}qa_readers";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL with identifiers; index name comes from SHOW INDEX (trusted), preserved case and backtick-escaped; table from $wpdb->prefix; no user input values.
			$result = $wpdb->query( $drop_query );
			if ( $result === false ) {
				$qahm_log->info( "Failed to drop index {$index_name}: {$wpdb->last_error}" );
			} else {
				$qahm_log->info( "Successfully dropped index: {$index_name}" );
			}
		}

		// 2. original_idカラムの型変更
		$alter_query = "ALTER TABLE {$wpdb->prefix}qa_readers MODIFY COLUMN original_id varchar(191) NULL";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL with identifiers only; table/column names are static; no user input values.
		$result = $wpdb->query( $alter_query );
		if ( $result === false ) {
			$qahm_log->info( "SQL Error: {$wpdb->last_error} in query: {$wpdb->last_query}" );
			return false;
		}

		// 3. 新しいカラムを追加（country_codeは除く）
		$column_exists_language = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}qa_readers LIKE 'language'" );
		if ( empty( $column_exists_language ) ) {
			$add_language = "ALTER TABLE {$wpdb->prefix}qa_readers ADD COLUMN language char(2) NULL AFTER UAbrowser";
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL with identifiers only; table/column names are static; no user input values.
			$result = $wpdb->query( $add_language );
			if ( $result === false ) {
				$qahm_log->info( "SQL Error: {$wpdb->last_error} in query: {$wpdb->last_query}" );
			}
		}

		$column_exists_reject = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}qa_readers LIKE 'is_reject'" );
		if ( empty( $column_exists_reject ) ) {
			$add_reject = "ALTER TABLE {$wpdb->prefix}qa_readers ADD COLUMN is_reject tinyint(1) NULL AFTER language";
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL with identifiers only; table/column names are static; no user input values.
			$result = $wpdb->query( $add_reject );
			if ( $result === false ) {
				$qahm_log->info( "SQL Error: {$wpdb->last_error} in query: {$wpdb->last_query}" );
			}
		}

		// 4. QA Platform標準仕様のインデックスを作成（ここは元のまま）
		$create_indexes = array(
			"CREATE INDEX {$wpdb->prefix}qa_readers_qa_id_index ON {$wpdb->prefix}qa_readers (qa_id)",
			"CREATE INDEX {$wpdb->prefix}qa_readers_original_id_index ON {$wpdb->prefix}qa_readers (original_id)",
			"CREATE INDEX {$wpdb->prefix}qa_readers_UAos_index ON {$wpdb->prefix}qa_readers (UAos)",
		);

		foreach ( $create_indexes as $query ) {
			preg_match( '/CREATE INDEX (\S+)/', $query, $matches );
			$index_name = $matches[1] ?? '';

			if ( empty( $index_name ) ) {
				$qahm_log->warning( "Failed to extract index name from query: {$query}" );
				continue;
			}

			$index_exists = $wpdb->get_results(
				$wpdb->prepare(
					"SHOW INDEX FROM {$wpdb->prefix}qa_readers WHERE Key_name = %s",
					$index_name
				)
			);

			if ( ! empty( $index_exists ) ) {
				$qahm_log->info( "Index {$index_name} already exists, skipping creation" );
				continue;
			}

			// DDLで識別子のみのため prepare 不可（元コードのまま）
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL with static identifiers only; CREATE INDEX cannot use prepare(); no user-supplied values are interpolated.
			$result = $wpdb->query( $query );
			if ( $result === false ) {
				$qahm_log->warning( "SQL Error creating index {$index_name}: {$wpdb->last_error}" );
			} else {
				$qahm_log->info( "Successfully created index: {$index_name}" );
			}
		}

		return true;
	}



	/**
	 * qa_pv_logテーブルをQA Platform仕様に変換（データ変換含む）
	 * Note: Minimal-diff patch to satisfy PHPCS (PreparedSQL) while preserving logic.
	 */
    // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- This file intentionally documents per-line ignores next to DDL execution lines where identifiers are used.
	public function convert_qa_pv_log_to_platform_spec() {
		global $wpdb;
		global $qahm_log;

		// 1. カラムの型変更
		$column_modifications = array(
			"ALTER TABLE {$wpdb->prefix}qa_pv_log MODIFY COLUMN access_time DATETIME DEFAULT CURRENT_TIMESTAMP",
			"ALTER TABLE {$wpdb->prefix}qa_pv_log MODIFY COLUMN medium_id INT",
			"ALTER TABLE {$wpdb->prefix}qa_pv_log MODIFY COLUMN campaign_id INT",
		);

		foreach ( $column_modifications as $query ) {
			$result = $wpdb->query( $query );
			if ( $result === false ) {
				$qahm_log->info( "SQL Error: {$wpdb->last_error} in query: {$wpdb->last_query}" );
			} else {
				$qahm_log->info( 'Successfully modified column type' );
			}
		}

		// 2. content_idカラムを追加（存在しない場合のみ）
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}qa_pv_log LIKE 'content_id'" );
		if ( empty( $column_exists ) ) {
			$add_content_id = "ALTER TABLE {$wpdb->prefix}qa_pv_log ADD COLUMN content_id int NULL AFTER campaign_id";
			$result         = $wpdb->query( $add_content_id );
			if ( $result === false ) {
				$qahm_log->info( "SQL Error: {$wpdb->last_error} in query: {$wpdb->last_query}" );
			} else {
				$qahm_log->info( 'Successfully added content_id column' );
			}
		}

		// 3. 新しいis_raw_*カラムを追加（存在しない場合のみ）
		$new_columns = array(
			array(
				'name'  => 'is_raw_p',
				'query' => "ALTER TABLE {$wpdb->prefix}qa_pv_log ADD COLUMN is_raw_p tinyint NULL",
			),
			array(
				'name'  => 'is_raw_c',
				'query' => "ALTER TABLE {$wpdb->prefix}qa_pv_log ADD COLUMN is_raw_c tinyint NULL",
			),
			array(
				'name'  => 'is_raw_e',
				'query' => "ALTER TABLE {$wpdb->prefix}qa_pv_log ADD COLUMN is_raw_e tinyint NULL",
			),
		);

		foreach ( $new_columns as $column ) {
			$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}qa_pv_log LIKE '{$column['name']}'" );
			if ( empty( $column_exists ) ) {
				$result = $wpdb->query( $column['query'] );
				if ( $result === false ) {
					$qahm_log->info( "SQL Error: {$wpdb->last_error} in query: {$wpdb->last_query}" );
				} else {
					$qahm_log->info( "Successfully added {$column['name']} column" );
				}
			}
		}

		// 4. 既存データを変換（raw_*の内容に基づいてis_raw_*を設定）
		$qahm_log->info( 'Starting is_raw_* conversion for qa_pv_log' );

		$batch_size      = 10000;
		$offset          = 0;
		$total_converted = 0;

		while ( true ) {
			$records = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pv_id, raw_p, raw_c, raw_e   
                     FROM {$wpdb->prefix}qa_pv_log   
                     WHERE is_raw_p IS NULL   
                     LIMIT %d OFFSET %d",
					$batch_size,
					$offset
				),
				ARRAY_A
			);

			if ( empty( $records ) ) {
				break;
			}

			foreach ( $records as $record ) {
				$is_raw_p = ( ! empty( $record['raw_p'] ) && $record['raw_p'] !== '' ) ? 1 : 0;
				$is_raw_c = ( ! empty( $record['raw_c'] ) && $record['raw_c'] !== '' ) ? 1 : 0;
				$is_raw_e = ( ! empty( $record['raw_e'] ) && $record['raw_e'] !== '' ) ? 1 : 0;

				$wpdb->update(
					$wpdb->prefix . 'qa_pv_log',
					array(
						'is_raw_p' => $is_raw_p,
						'is_raw_c' => $is_raw_c,
						'is_raw_e' => $is_raw_e,
					),
					array( 'pv_id' => $record['pv_id'] ),
					array( '%d', '%d', '%d' ),
					array( '%d' )
				);
			}

			$total_converted += $this->wrap_count( $records );
			$offset          += $batch_size;
			$qahm_log->info( "Converted {$total_converted} records in qa_pv_log" );
		}

		$qahm_log->info( "Successfully converted {$total_converted} total records in qa_pv_log" );

		// 5. 古いraw_*カラムを削除（存在する場合のみ）
		$drop_columns = array(
			array(
				'name'  => 'raw_p',
				'query' => "ALTER TABLE {$wpdb->prefix}qa_pv_log DROP COLUMN raw_p",
			),
			array(
				'name'  => 'raw_c',
				'query' => "ALTER TABLE {$wpdb->prefix}qa_pv_log DROP COLUMN raw_c",
			),
			array(
				'name'  => 'raw_e',
				'query' => "ALTER TABLE {$wpdb->prefix}qa_pv_log DROP COLUMN raw_e",
			),
		);

		foreach ( $drop_columns as $column ) {
			$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}qa_pv_log LIKE '{$column['name']}'" );
			if ( ! empty( $column_exists ) ) {
				$result = $wpdb->query( $column['query'] );
				if ( $result === false ) {
					$qahm_log->info( "SQL Error: {$wpdb->last_error} in query: {$wpdb->last_query}" );
				} else {
					$qahm_log->info( "Successfully dropped {$column['name']} column" );
				}
			}
		}

		return true;
	}
    // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

	/**
	 * qa_utm_mediaテーブルをQA Platform仕様に変換
	 */
	public function convert_qa_utm_media_to_platform_spec() {
		global $wpdb;
		global $qahm_log;

		$qahm_log->info( 'Starting qa_utm_media table conversion' );

		$alter_query = "ALTER TABLE {$wpdb->prefix}qa_utm_media MODIFY COLUMN medium_id INT AUTO_INCREMENT";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL with static identifiers only; prepare() cannot bind identifiers; no user-supplied values.
		$result = $wpdb->query( $alter_query );
		if ( $result === false ) {
			$qahm_log->error( "SQL Error: {$wpdb->last_error} in query: {$wpdb->last_query}" );
			return false;
		}

		$qahm_log->info( 'Successfully converted qa_utm_media table' );
		return true;
	}

	/**
	 * qa_utm_sourcesテーブルをQA Platform仕様に変換
	 */
	public function convert_qa_utm_sources_to_platform_spec() {
		global $wpdb;
		global $qahm_log;

		$qahm_log->info( 'Starting qa_utm_sources table conversion' );

		$alter_query = "ALTER TABLE {$wpdb->prefix}qa_utm_sources MODIFY COLUMN source_id INT AUTO_INCREMENT";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL with static identifiers only; no user-supplied values; prepare() cannot bind identifiers.  
		$result = $wpdb->query( $alter_query );
		if ( $result === false ) {
			$qahm_log->error( "SQL Error: {$wpdb->last_error} in query: {$wpdb->last_query}" );
			return false;
		}

		$qahm_log->info( 'Successfully converted qa_utm_sources table' );
		return true;
	}

	/**
	 * qa_utm_contentテーブルをQA Platform仕様に変換（新規作成）
	 */
	public function convert_qa_utm_content_to_platform_spec() {
		global $wpdb;
		global $qahm_log;

		// テーブルの存在確認
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}qa_utm_content'" );
		if ( ! $table_exists ) {
			$qahm_log->info( 'Creating qa_utm_content table for QA Platform spec' );

			// テーブル作成
			$create_query = "  
                CREATE TABLE {$wpdb->prefix}qa_utm_content (  
                    content_id int auto_increment primary key,  
                    utm_content varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin null,  
                    constraint qa_utm_content_content_id_uindex unique (content_id),  
                    constraint qa_utm_content_utm_content_uindex unique (utm_content)  
                ) DEFAULT CHARACTER SET {$wpdb->charset} COLLATE {$wpdb->collate}  
            ";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL with static identifiers only; CREATE TABLE cannot use prepare(); no user-supplied values interpolated.
			$result = $wpdb->query( $create_query );
			if ( $result === false ) {
				$qahm_log->info( "SQL Error: {$wpdb->last_error} in query: {$wpdb->last_query}" );
				return false;
			} else {
				$qahm_log->info( 'Successfully created qa_utm_content table' );
			}
		} else {
			$qahm_log->info( 'qa_utm_content table already exists, skipping creation' );
		}

		return true;
	}

	/**
	 * qa_gsc_query_logテーブルをQA Platform仕様に変換
	 */
	public function convert_qa_gsc_query_log_to_platform_spec() {
		global $wpdb;
		global $qahm_log;

		// 既存の単一GSCテーブルの存在確認
		$old_table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}qa_gsc_query_log'" );

		// sitemanage設定を確保
		$sitemanage = $this->ensure_sitemanage();
		if ( ! $sitemanage ) {
			$qahm_log->error( 'Failed to ensure sitemanage' );
			return false;
		}

		// 最初のサイトのみを取得
		$tracking_id    = $sitemanage[0]['tracking_id'];
		$new_table_name = $wpdb->prefix . 'qa_gsc_' . $tracking_id . '_query_log';

		if ( $old_table_exists ) {
			$qahm_log->info( 'Found existing qa_gsc_query_log table, starting migration' );

			// 対象テーブルが既に存在しないかチェック
			$target_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$new_table_name}'" );
			if ( ! $target_exists ) {
				// テーブル構造をコピー
				$create_query = "CREATE TABLE {$new_table_name} LIKE {$wpdb->prefix}qa_gsc_query_log";
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL with identifiers only; CREATE TABLE ... LIKE ... cannot use prepare(); no user-supplied values. 
				$create_result = $wpdb->query( $create_query );

				if ( $create_result === false ) {
					$qahm_log->error( "Failed to create GSC table {$new_table_name}: {$wpdb->last_error}" );
					return false;
				}
			}

			// 対象テーブルの存在に関係なく、必ずデータ移行を実行
			$qahm_log->info( "Starting data migration from qa_gsc_query_log to {$new_table_name}" );

			$batch_size     = 5000;
			$offset         = 0;
			$total_migrated = 0;

			while ( true ) {
				$records = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$wpdb->prefix}qa_gsc_query_log   
                         LIMIT %d OFFSET %d",
						$batch_size,
						$offset
					),
					ARRAY_A
				);

				if ( empty( $records ) ) {
					break;
				}

				$values       = array();
				$placeholders = array();

				foreach ( $records as $record ) {
					$record_values  = array_values( $record );
					$values         = $this->wrap_array_merge( $values, $record_values );
					$placeholders[] = '(' . $this->wrap_implode( ',', array_fill( 0, $this->wrap_count( $record ), '%s' ) ) . ')';
				}

				$columns = $this->wrap_implode( ',', array_keys( $records[0] ) );
				$sql     = "INSERT IGNORE INTO {$new_table_name} ({$columns}) VALUES " . $this->wrap_implode( ',', $placeholders );

                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This SQL query uses placeholders and $wpdb->prepare(), but it may trigger warnings due to the dynamic construction of the SQL string.  
				$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );

				if ( $result === false ) {
					$qahm_log->error( "Failed to migrate batch at offset {$offset}: {$wpdb->last_error}" );
					return false;
				}

				$total_migrated += $this->wrap_count( $records );
				$offset         += $batch_size;
				$qahm_log->info( "Migrated {$total_migrated} records to {$new_table_name}" );
			}

			$qahm_log->info( "Successfully migrated {$total_migrated} total records to GSC table: {$new_table_name}" );

			$drop_result = $wpdb->query( "DROP TABLE {$wpdb->prefix}qa_gsc_query_log" );
			if ( $drop_result !== false ) {
				$qahm_log->info( 'Successfully dropped original qa_gsc_query_log table' );
			} else {
				$qahm_log->warning( "Failed to drop original qa_gsc_query_log table: {$wpdb->last_error}" );
			}
		} else {
			// 既存テーブルが存在しない場合：新規作成
			$qahm_log->info( 'No existing qa_gsc_query_log table found, creating new table' );

			$qahm_database_manager = new QAHM_Database_Creator();
			$gsc_table_created     = $qahm_database_manager->create_gsc_query_log_table( $tracking_id );

			if ( $gsc_table_created ) {
				$qahm_log->info( "Successfully created GSC table for tracking_id: {$tracking_id}" );
			} else {
				$qahm_log->error( "Failed to create GSC table for tracking_id: {$tracking_id}" );
			}
		}

		return true;
	}

	/**
	 * sitemanageオプションを確保（存在しない場合は作成）
	 */
	private function ensure_sitemanage() {
		global $qahm_log;

		$sitemanage = $this->wrap_get_option( 'sitemanage' );
		if ( ! $sitemanage ) {
			global $qahm_admin_page_entire;

			if ( ! $qahm_admin_page_entire ) {
				$qahm_log->error( 'QAHM_Admin_Page_Entire not available for sitemanage creation' );
				return false;
			}

			$result = $qahm_admin_page_entire->set_sitemanage_domainurl( get_site_url() );
			if ( $result['result'] !== 'success' ) {
				$qahm_log->error( 'Failed to create sitemanage: ' . $this->wrap_json_encode( $result ) );
				return false;
			}

			$sitemanage = $this->wrap_get_option( 'sitemanage' );
		}

		return $sitemanage;
	}

	/**
	 * 使用されていないqahm_rectermテーブルを削除
	 */
	public function drop_recterm_table() {
		global $wpdb;
		global $qahm_log;

		$table_name   = $wpdb->prefix . 'qahm_recterm';
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" );

		if ( $table_exists ) {
			$result = $wpdb->query( "DROP TABLE {$table_name}" );
			if ( $result !== false ) {
				$qahm_log->info( "Successfully dropped unused table: {$table_name}" );
				return true;
			} else {
				$qahm_log->warning( "Failed to drop table {$table_name}: {$wpdb->last_error}" );
				return false;
			}
		}

		return true; // テーブルが存在しない場合も成功とみなす
	}

	/**
	 * view_pv ディレクトリ内の全ファイルの access_time を unix timestamp に変換
	 * QA Analytics から QA Platform への移行処理
	 */
	public function convert_qa_analytics_view_pv_access_time() {
		global $wp_filesystem;
		global $qahm_log;

		// tracking_idを取得
		$sitemanage = $this->ensure_sitemanage();
		if ( ! $sitemanage ) {
			$qahm_log->error( 'Failed to ensure sitemanage' );
			return false;
		}
		$tracking_id = $sitemanage[0]['tracking_id'];

		$total_converted = 0;
		$total_errors    = 0;

		$data_dir    = $this->get_data_dir_path();
		$view_pv_dir = $data_dir . "view/{$tracking_id}/view_pv/";

		if ( ! $wp_filesystem->is_dir( $view_pv_dir ) ) {
			return false;
		}

		$files = $this->wrap_dirlist( $view_pv_dir );
		if ( ! $files ) {
			return false;
		}

		foreach ( $files as $file ) {
			$filename  = $file['name'];
			$file_path = $view_pv_dir . $filename;

			// view_pv ファイルのみ処理（既存のファイル命名パターンに従う）
			if ( ! preg_match( '/\\d{4}-\\d{2}-\\d{2}_\\d+-\\d+_viewpv\\.php$/', $filename ) ) {
				continue;
			}

			try {
				// ファイル読み込み
				$file_contents = $this->wrap_get_contents( $file_path );
				if ( ! $file_contents ) {
					++$total_errors;
					continue;
				}

				// ファイルをunserialize
				$file_data = $this->wrap_unserialize( $file_contents );
				if ( ! $file_data || ! is_array( $file_data ) ) {
					++$total_errors;
					continue;
				}

				$modified = false;

				// 各レコードの access_time を変換
				foreach ( $file_data as &$pv_data ) {
					if ( ! isset( $pv_data['access_time'] ) ) {
						continue;
					}

					// 文字列形式の場合のみ変換（既存の処理パターンに従う）
					if ( is_string( $pv_data['access_time'] ) ) {
						$unix_time = strtotime( $pv_data['access_time'] );
						if ( $unix_time !== false ) {
							$pv_data['access_time'] = $unix_time;
							$modified               = true;
						}
					}
				}

				// 変更があった場合のみファイル更新
				if ( $modified ) {
					// バックアップ作成
					$backup_path = $file_path . '.backup_' . gmdate( 'YmdHis' );
					$wp_filesystem->copy( $file_path, $backup_path );

					// 変換後データを保存（既存のwrap_serializeメソッドを使用）
					$serialized_data = $this->wrap_serialize( $file_data );
					if ( $this->wrap_put_contents( $file_path, $serialized_data ) ) {
						$wp_filesystem->delete( $backup_path );
						++$total_converted;
					} else {
						// バックアップから復元
						$wp_filesystem->copy( $backup_path, $file_path );
						++$total_errors;
					}
				}
			} catch ( Exception $e ) {
				++$total_errors;
			}
		}

		if ( $qahm_log ) {
			$qahm_log->info( "Access time conversion completed. Files converted: {$total_converted}, Errors: {$total_errors}" );
		}

		return true;
	}

	/**
	 * QA Analyticsの設定値をqa-config.phpに移行
	 * デフォルト値より高い値が設定されていた場合のみ反映
	 */
	private function migrate_config_values() {
		global $qahm_log;

		$data_save_month      = $this->wrap_get_option( 'data_retention_dur' );
		$data_save_lic_option = $this->wrap_get_option( 'license_option' );
		$data_save_pv         = $data_save_lic_option['measure'];

		$qahm_log->info( "Config migration: data_save_month={$data_save_month}, data_save_pv={$data_save_pv}" );

		$default_retention_days = 120;
		$default_pv_limit       = 10000;

		$retention_days = null;
		$pv_limit       = null;

		if ( $data_save_month && is_numeric( $data_save_month ) ) {
			$calculated_days = (int) $data_save_month;
			if ( $calculated_days > $default_retention_days ) {
				$retention_days = $calculated_days;
				$qahm_log->info( "Config migration: Will migrate retention_days={$retention_days} (from {$data_save_month} months)" );
			}
		}

		if ( $data_save_pv && is_numeric( $data_save_pv ) ) {
			$pv_value = (int) $data_save_pv;
			if ( $pv_value > $default_pv_limit ) {
				$pv_limit = $pv_value;
				$qahm_log->info( "Config migration: Will migrate pv_limit={$pv_limit}" );
			}
		}

		if ( $retention_days !== null || $pv_limit !== null ) {
			$result = $this->write_config_values( $retention_days, $pv_limit );
			if ( $result ) {
				$qahm_log->info( 'Config migration: Successfully wrote config values to qa-config.php' );
			} else {
				$qahm_log->warning( 'Config migration: Failed to write config values to qa-config.php' );
			}
		} else {
			$qahm_log->info( 'Config migration: No values to migrate (all below defaults)' );
		}
	}

	/**
	 * qa-config.phpに設定値を書き込む
	 *
	 * @param int|null $retention_days データ保持期間（日数）
	 * @param int|null $pv_limit       月間PV上限
	 * @return bool 成功時true、失敗時false
	 */
	private function write_config_values( $retention_days, $pv_limit ) {
		global $wp_filesystem;
		global $qahm_log;

		if ( empty( $wp_filesystem ) ) {
			$this->init_wp_filesystem();
		}

		$config_file_path = WP_CONTENT_DIR . '/qa-zero-data/qa-config.php';

		if ( ! $wp_filesystem->exists( $config_file_path ) ) {
			$qahm_log->warning( "Config migration: qa-config.php not found at {$config_file_path}" );
			return false;
		}

		$config_content = $wp_filesystem->get_contents( $config_file_path );

		if ( $config_content === false ) {
			$qahm_log->warning( 'Config migration: Failed to read qa-config.php' );
			return false;
		}

		if ( $retention_days !== null ) {
			if ( $this->wrap_strpos( $config_content, 'QAHM_CONFIG_DATA_RETENTION_DAYS' ) !== false ) {
				$config_content = preg_replace(
					"/define\s*\(\s*['\"]QAHM_CONFIG_DATA_RETENTION_DAYS['\"]\s*,\s*[^)]+\)\s*;/",
					"define('QAHM_CONFIG_DATA_RETENTION_DAYS', {$retention_days});",
					$config_content
				);
			} else {
				$config_content .= "\ndefine('QAHM_CONFIG_DATA_RETENTION_DAYS', {$retention_days});\n";
			}
		}

		if ( $pv_limit !== null ) {
			if ( $this->wrap_strpos( $config_content, 'QAHM_CONFIG_LIMIT_PV_MONTH' ) !== false ) {
				$config_content = preg_replace(
					"/define\s*\(\s*['\"]QAHM_CONFIG_LIMIT_PV_MONTH['\"]\s*,\s*[^)]+\)\s*;/",
					"define('QAHM_CONFIG_LIMIT_PV_MONTH', {$pv_limit});",
					$config_content
				);
			} else {
				$config_content .= "\ndefine('QAHM_CONFIG_LIMIT_PV_MONTH', {$pv_limit});\n";
			}
		}

		return $wp_filesystem->put_contents( $config_file_path, $config_content, 0644 );
	}
}
