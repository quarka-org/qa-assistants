<?php
defined( 'ABSPATH' ) || exit;
/**
 *
 * @package qa_heatmap
 */

class QAHM_Update extends QAHM_File_Data {

	public function __construct() {
	}

	public function check_version() {
		global $qahm_license;
		global $qahm_db;
		global $qahm_data_api;
		global $qahm_log;
		global $wpdb;
		$ver = $this->wrap_get_option( 'plugin_version' );
		if ( $ver === QAHM_PLUGIN_VERSION ) {
			$this->delete_maintenance_file();
			return;
		}

		// Differs between ZERO and QA - Start ----------
		// プラグインタイプによってアップデート処理を分ける
		if ( QAHM_TYPE === QAHM_TYPE_ZERO ) {
			if ( version_compare( '2.5.1.0', $ver, '>' ) ) {
				$this->mod_table_add_ip_to_country();
				$this->wrap_update_option( 'plugin_version', '2.5.1.0' );
			}

			if ( version_compare( '2.5.2.0', $ver, '>' ) ) {
				$qahm_activate = new QAHM_Activate();
				$qahm_activate->setup_config_file();
				$this->wrap_update_option( 'plugin_version', '2.5.2.0' );
			}

			if ( version_compare( '3.0.0.0', $ver, '>' ) ) {
				// GSC API取得件数上限定数を追記
				$this->add_constant_to_config( 'QAHM_CONFIG_GSC_ROW_LIMIT_PER_REQUEST_KEYWORD', 5000 );
				$this->add_constant_to_config( 'QAHM_CONFIG_GSC_ROW_LIMIT_PER_REQUEST_PAGE', 1000 );
				$this->add_constant_to_config( 'QAHM_CONFIG_GSC_ROW_LIMIT_PER_REQUEST_QUERY', 5000 );
				$this->wrap_update_option( 'plugin_version', '3.0.0.0' );
			}

			if ( version_compare( '3.0.1.0', $ver, '>' ) ) {
				// 既存 sitemanage に subdomain_tracking フィールドを追加
				$sitemanage = $this->wrap_get_option( 'sitemanage' );
				if ( $sitemanage && is_array( $sitemanage ) ) {
					foreach ( $sitemanage as &$site ) {
						if ( ! isset( $site['subdomain_tracking'] ) ) {
							$site['subdomain_tracking'] = 0;
						}
						if ( ! isset( $site['user_type_method'] ) ) {
							$site['user_type_method'] = 1;
						}
					}
					unset( $site );
					$this->wrap_update_option( 'sitemanage', $sitemanage );
				}
				$this->wrap_update_option( 'plugin_version', '3.0.1.0' );
			}

			if ( version_compare( '3.0.2.0', $ver, '>' ) ) {
				$this->fix_utm_media_auto_increment();
				$this->wrap_update_option( 'plugin_version', '3.0.2.0' );
			}

			if ( version_compare( '3.0.9.0', $ver, '>' ) ) {
				// 既存 sitemanage に html_diff_detection_mode フィールドを追加
				$sitemanage = $this->wrap_get_option( 'sitemanage' );
				if ( $sitemanage && is_array( $sitemanage ) ) {
					foreach ( $sitemanage as &$site ) {
						if ( ! isset( $site['html_diff_detection_mode'] ) ) {
							$site['html_diff_detection_mode'] = 'major_only';
						}
					}
					unset( $site );
					$this->wrap_update_option( 'sitemanage', $sitemanage );
				}
				// qa-config.php から不要になった定数を削除
				$this->remove_constant_from_config( 'QAHM_CONFIG_HTML_DIFF_DETECTION_MODE' );

				// subcron → html_periodic リネーム（#988 Day Cron 統合）
				$data_dir   = $this->get_data_dir_path();
				global $wp_filesystem;
				if ( empty( $wp_filesystem ) ) {
					$this->init_wp_filesystem();
				}
				$rename_map = array(
					'subcron_list.php'               => 'html_periodic_list.php',
					'subcron_list_organize.php'      => 'html_periodic_list_organize.php',
					'subcron_list_progress.php'      => 'html_periodic_list_progress.php',
					'subcron_list_page_progress.php' => 'html_periodic_list_page_progress.php',
				);
				foreach ( $rename_map as $old_name => $new_name ) {
					if ( file_exists( $data_dir . $old_name ) && ! file_exists( $data_dir . $new_name ) ) {
						$wp_filesystem->move( $data_dir . $old_name, $data_dir . $new_name );
					}
				}
				// 不要になったファイルを削除
				$delete_files = array( 'subcron_status', 'subcron_lock' );
				foreach ( $delete_files as $file ) {
					if ( file_exists( $data_dir . $file ) ) {
						wp_delete_file( $data_dir . $file );
					}
				}

				// ページタイプ判定用カラム追加
				if ( ! $this->mod_table_add_page_type() ) {
					return; // page_type 追加失敗時は中断、次回リトライ
				}
				$this->wrap_update_option( 'plugin_version', '3.0.9.0' );
			}
		} elseif ( QAHM_TYPE === QAHM_TYPE_WP ) {
			if ( version_compare( '1.0.5.0', $ver, '>' ) ) {
				$this->wrap_update_option( 'is_first_heatmap_setting', '' );
				$this->wrap_update_option( 'plugin_version', '1.0.5.0' );
			}

			if ( version_compare( '1.0.8.0', $ver, '>' ) ) {
				$this->wrap_update_option( 'heatmap_measure_max', 1 );
				$this->wrap_update_option( 'campaign_oneyear_popup', false );
				$this->wrap_update_option( 'plugin_version', '1.0.8.0' );
			}

			if ( version_compare( '1.1.0.0', $ver, '>' ) ) {
				$this->wrap_update_option( 'data_save_month', 2 );
				$this->wrap_update_option( 'plugin_version', '1.1.0.0' );
			}

			if ( version_compare( '2.9.0.0', $ver, '>' ) ) {
				$plan = (int) $this->wrap_get_option( 'license_plan' );
				if ( 0 < $plan ) {
					// ライセンス情報を新しい形式に一新するので、この時点で強制的にライセンス認証を行う
					$key = $this->wrap_get_option( 'license_key' );
					$id  = $this->wrap_get_option( 'license_id' );
					$qahm_license->activate( $key, $id );
				}
				$this->wrap_update_option( 'plugin_version', '2.9.0.0' );
			}

			if ( version_compare( '3.3.0.0', $ver, '>' ) ) {
				$qahm_sql_table = new QAHM_Database_Creator();
				$check_exists   = -123454321;
				$ver            = $this->wrap_get_option( 'qa_gsc_query_log_version', $check_exists );
				if ( $ver === $check_exists ) {

					$url         = get_site_url();
					$parse_url   = wp_parse_url( $url );
					$domain_url  = $this->to_domain_url( $parse_url );
					$tracking_id = $this->get_tracking_id( $domain_url );

					$query = $qahm_sql_table->get_qa_gsc_query_log_create_table( $tracking_id );
					if ( $query ) {
						// queryのコメント、先頭末尾のスペースやTAB等を削除
						$query_ary = $this->wrap_explode( PHP_EOL, $query );
						for ( $query_idx = 0, $query_max = $this->wrap_count( $query_ary ); $query_idx < $query_max; $query_idx++ ) {
							$query_ary[ $query_idx ] = $this->wrap_trim( $query_ary[ $query_idx ], " \t" );
							if ( $this->wrap_substr( $query_ary[ $query_idx ], 0, 2 ) === '--' ) {
								unset( $query_ary[ $query_idx ] );
							}
						}
						$query = $this->wrap_implode( '', $query_ary );

						// クエリ実行
						$query_ary = $this->wrap_explode( ';', $query );
						for ( $query_idx = 0, $query_max = $this->wrap_count( $query_ary ); $query_idx < $query_max; $query_idx++ ) {
							if ( $query_ary[ $query_idx ] ) {
								$qahm_db->query( $query_ary[ $query_idx ] );
							}
						}
						$this->wrap_put_contents( 'qa_gsc_query_log_version', QAHM_DB_OPTIONS['qa_gsc_query_log_version'] );
					}
				}

				$this->wrap_update_option( 'google_credentials', '' );
				$this->wrap_update_option( 'google_is_redirect', false );
				$this->wrap_update_option( 'plugin_version', '3.3.0.0' );
			}

			if ( version_compare( '3.9.9.0', $ver, '>' ) ) {
				$this->wrap_update_option( 'cb_sup_mode', 'no' );
				$this->wrap_update_option( 'pv_limit_rate', 0 );
				$this->wrap_update_option( 'data_retention_dur', 90 );
				$this->wrap_update_option( 'license_option', null );
				$this->wrap_update_option( 'plugin_first_launch', false );
				$this->wrap_update_option( 'pv_limit_rate', 0 );
				$this->wrap_update_option( 'pv_warning_mail_month', null );
				$this->wrap_update_option( 'pv_over_mail_month', null );
				$this->wrap_update_option( 'plugin_version', '3.9.9.0' );
			}

			if ( version_compare( '3.9.9.1', $ver, '>' ) ) {
				$this->wrap_update_option( 'send_email_address', get_option( 'admin_email' ) );
				$this->wrap_update_option( 'plugin_version', '3.9.9.1' );
			}

			if ( version_compare( '3.9.9.3', $ver, '>' ) ) {
				$this->wrap_update_option( 'anontrack', 0 );
				$this->wrap_update_option( 'cb_init_consent', 'yes' );
				$this->wrap_update_option( 'plugin_version', '3.9.9.3' );
			}

			if ( version_compare( '4.0.1.0', $ver, '>' ) ) {
				$this->wrap_update_option( 'announce_friend_plan', true );
				$this->wrap_update_option( 'plugin_version', '4.0.1.0' );
			}

			if ( version_compare( '4.7.0.0', $ver, '>' ) ) {
				// QAアナリティクスからQAアシスタントへ＝データが見られない通知を表示するフラッグ
				$timestamp               = time();
				$unavailable_state_array = array(
					'pending'   => true,
					'timestamp' => (string) $timestamp,
				);
				$this->wrap_update_option( 'v5_data_unavailable_state', $unavailable_state_array );
				// 旧QA専用ユーザーロールを削除（該当ユーザーは "No role for this site" になります）
				remove_role( 'qahm-manager' );
				remove_role( 'qahm-viewer' );

				require_once __DIR__ . '/class-qahm-analytics-migration.php';
				$migration = new QAHM_Analytics_Migration();
				$migration->convert_qa_analytics();
				$this->wrap_update_option( 'plugin_version', '4.7.0.0' );
			}
			if ( version_compare( '4.8.0.0', $ver, '>' ) ) {
				$this->wrap_update_option( 'data_retention_days', 30 );
				$this->wrap_update_option( 'cb_sup_mode', 'yes' );
				$this->wrap_update_option( 'send_email_address', get_option( 'admin_email' ) );
				$this->wrap_update_option( 'pv_limit_rate', 0 );
				$this->wrap_update_option( 'pv_warning_mail_month', null );
				$this->wrap_update_option( 'pv_over_mail_month', null );
				$this->wrap_update_option( 'advanced_mode', false );
				$this->wrap_update_option( 'plugin_version', '4.8.0.0' );
			}
			if ( version_compare( '4.8.4.0', $ver, '>' ) ) {
				$this->mod_table_add_ip_to_country();
				$this->wrap_update_option( 'plugin_version', '4.8.4.0' );
			}

			if ( version_compare( '4.9.5.0', $ver, '>' ) ) {
				$sitemanage = $this->wrap_get_option( 'sitemanage' );
				if ( $sitemanage && is_array( $sitemanage ) ) {
					foreach ( $sitemanage as &$site ) {
						$site['anontrack'] = 1;
					}
					unset( $site );
					$this->wrap_update_option( 'sitemanage', $sitemanage );
				}

				$qahm_activate = new QAHM_Activate();
				$qahm_activate->setup_config_file();

				$this->wrap_update_option( 'plugin_version', '4.9.5.0' );
			}

			if ( version_compare( '5.1.0.0', $ver, '>' ) ) {
				$data_dir = $this->get_data_dir_path();

				// 進捗完了ファイルの存在確認
				$version_hist_completed = $this->wrap_exists( $data_dir . 'cleanup_version_hist_completed.php' );

				// version_histクリーンアップ
				if ( ! $version_hist_completed ) {
					$completed = $this->cleanup_version_hist_duplicates();
					if ( ! $completed ) {
						return;  // 未完了なら即座に終了
					}
					// 完了ファイルを作成
					$this->wrap_put_contents( $data_dir . 'cleanup_version_hist_completed.php', 'completed' );
				}

				// クリーンアップ完了ファイルを削除
				$this->wrap_delete( $data_dir . 'cleanup_version_hist_completed.php' );
				$this->wrap_update_option( 'plugin_version', '5.1.0.0' );
			}

			if ( version_compare( '5.1.2.0', $ver, '>' ) ) {
				// GSC API取得件数上限定数を追記
				$this->add_constant_to_config( 'QAHM_CONFIG_GSC_ROW_LIMIT_PER_REQUEST_KEYWORD', 5000 );
				$this->add_constant_to_config( 'QAHM_CONFIG_GSC_ROW_LIMIT_PER_REQUEST_PAGE', 1000 );
				$this->add_constant_to_config( 'QAHM_CONFIG_GSC_ROW_LIMIT_PER_REQUEST_QUERY', 5000 );
				$this->wrap_update_option( 'plugin_version', '5.1.2.0' );
			}

			if ( version_compare( '5.1.4.0', $ver, '>' ) ) {
				// 既存ユーザーは「はじめに」画面をスキップ
				$this->wrap_update_option( 'intro_completed', true );
				$this->wrap_update_option( 'plugin_version', '5.1.4.0' );
			}

			if ( version_compare( '5.1.5.0', $ver, '>' ) ) {
				$this->fix_utm_media_auto_increment();
				$this->wrap_update_option( 'plugin_version', '5.1.5.0' );
			}

			if ( version_compare( '5.1.9.0', $ver, '>' ) ) {
				// 既存 sitemanage に html_diff_detection_mode フィールドを追加
				$sitemanage = $this->wrap_get_option( 'sitemanage' );
				if ( $sitemanage && is_array( $sitemanage ) ) {
					foreach ( $sitemanage as &$site ) {
						if ( ! isset( $site['html_diff_detection_mode'] ) ) {
							$site['html_diff_detection_mode'] = 'major_only';
						}
					}
					unset( $site );
					$this->wrap_update_option( 'sitemanage', $sitemanage );
				}
				// qa-config.php から不要になった定数を削除
				$this->remove_constant_from_config( 'QAHM_CONFIG_HTML_DIFF_DETECTION_MODE' );
				// ページタイプ判定用カラム追加
				if ( ! $this->mod_table_add_page_type() ) {
					return; // page_type 追加失敗時は中断、次回リトライ
				}
				$this->wrap_update_option( 'plugin_version', '5.1.9.0' );
			}
		}
		// Differs between ZERO and QA - End ----------

		// 最終的にプラグインバージョンを現行のものに変更
		$this->wrap_update_option( 'plugin_version', QAHM_PLUGIN_VERSION );

		// プラグインバージョンに合わせてBrainsファイル更新のため、ライセンス認証
		$lic_authoirzed = $this->lic_authorized();
		if ( $lic_authoirzed ) {
			$key = $this->wrap_get_option( 'license_key' );
			$id  = $this->wrap_get_option( 'license_id' );
			$qahm_license->activate( $key, $id );
		}

		// メンテナンスファイルを削除
		$this->delete_maintenance_file();

		$qahm_log->info( 'Update process has completed.' );
	}


	/**
	 * メンテナンスファイルの削除
	 */
	private function delete_maintenance_file() {
		$maintenance_path = $this->get_temp_dir_path() . 'maintenance.php';
		if ( $this->wrap_exists( $maintenance_path ) ) {
			$this->wrap_delete( $maintenance_path );
		}
	}

	/**
	 * qa_utm_media テーブルの AUTO_INCREMENT 復旧と不正データ削除
	 *
	 * QA Analytics → QA Assistants マイグレーション時に AUTO_INCREMENT が欠落し、
	 * 未知の utm_medium が medium_id = 0 で登録される問題を修正する。
	 * ALTER TABLE MODIFY COLUMN は冪等（既に AUTO_INCREMENT がある環境でも安全）。
	 */
	private function fix_utm_media_auto_increment() {
		global $wpdb;
		global $qahm_db;
		global $qahm_log;

		$table_name = $wpdb->prefix . 'qa_utm_media';

		// テーブルが存在するか確認
		$table_exists = $qahm_db->get_var( "SHOW TABLES LIKE '{$table_name}'" );
		if ( ! $table_exists ) {
			return;
		}

		// medium_id = 0 の不正レコードを先に削除（AUTO_INCREMENT 付与前に除去）
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DML with table name from $wpdb->prefix; no user input.
		$deleted = $qahm_db->query( "DELETE FROM {$table_name} WHERE medium_id = 0" );
		if ( false === $deleted ) {
			$qahm_log->warning( "Failed to delete medium_id=0 from {$table_name}: {$wpdb->last_error}" );
		} elseif ( 0 < $deleted ) {
			$qahm_log->info( "Deleted {$deleted} invalid record(s) with medium_id=0 from {$table_name}" );
		}

		// AUTO_INCREMENT を付与（冪等）
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL with table name from $wpdb->prefix; no user input.
		$result = $qahm_db->query( "ALTER TABLE {$table_name} MODIFY COLUMN medium_id INT AUTO_INCREMENT" );
		if ( false === $result ) {
			$qahm_log->error( "Failed to add AUTO_INCREMENT to {$table_name}: {$wpdb->last_error}" );
			return;
		}
		$qahm_log->info( "Successfully added AUTO_INCREMENT to {$table_name}.medium_id" );
	}

	/**
	 * DBのカラム変更
	 */
	private function mod_table_add_ip_to_country() {
		global $wpdb;
		global $qahm_db;
		global $qahm_log;

		// qa_readersテーブルにcountry_codeカラムを追加
		$table_exists = $qahm_db->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}qa_readers'" );
		if ( $table_exists ) {
			$column_exists = $qahm_db->get_var( "SHOW COLUMNS FROM {$wpdb->prefix}qa_readers LIKE 'country_code'" );
			if ( ! $column_exists ) {
				$alt_query = "ALTER TABLE {$wpdb->prefix}qa_readers ADD COLUMN country_code CHAR(2) DEFAULT NULL AFTER is_reject";
				$result    = $qahm_db->query( $alt_query );
				if ( $result === false ) {
					$qahm_log->info( "(During plugin update) SQL Error: {$wpdb->last_error} in query: {$wpdb->last_query}" );
				} else {
					$qahm_log->info( '(During plugin update) Successfully added country_code column to qa_readers table' );
				}

				$index_exists = $qahm_db->get_var( "SHOW INDEX FROM {$wpdb->prefix}qa_readers WHERE Key_name = 'idx_readers_country'" );
				if ( ! $index_exists ) {
					$index_query = "CREATE INDEX idx_readers_country ON {$wpdb->prefix}qa_readers(country_code)";
					$result      = $qahm_db->query( $index_query );
					if ( $result === false ) {
						$qahm_log->info( "(During plugin update) SQL Error: {$wpdb->last_error} in query: {$wpdb->last_query}" );
					} else {
						$qahm_log->info( '(During plugin update) Successfully created idx_readers_country index' );
					}
				}
			}
		}
	}

	/**
	 * qa_pages テーブルにページタイプ判定用カラムを追加
	 *
	 * page_type (BIGINT UNSIGNED) — ビットフラグでページタイプを格納
	 * page_fetch_status (TINYINT) — NULL=未取得, 1=成功, -1=失敗
	 * is_* (TINYINT GENERATED STORED) — page_type から自動計算
	 *
	 * @return bool 全カラム追加成功時 true、1つでも失敗時 false（呼び出し元でリトライ制御）
	 */
	private function mod_table_add_page_type() {
		global $wpdb;
		global $qahm_db;
		global $qahm_log;

		$table = $wpdb->prefix . 'qa_pages';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL with table name from $wpdb->prefix; no user input.
		$table_exists = $qahm_db->get_var( "SHOW TABLES LIKE '{$table}'" );
		if ( ! $table_exists ) {
			return true; // テーブル未作成は正常（新規インストール時は CREATE TABLE で対応）
		}

		// 1. page_type カラム（BIGINT UNSIGNED, NULL=未チェック）
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL with table name from $wpdb->prefix; no user input.
		$col = $qahm_db->get_var( "SHOW COLUMNS FROM {$table} LIKE 'page_type'" );
		if ( ! $col ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL with table name from $wpdb->prefix; no user input.
			$result = $qahm_db->query( "ALTER TABLE {$table} ADD COLUMN page_type BIGINT UNSIGNED DEFAULT NULL" );
			if ( false === $result ) {
				$qahm_log->error( "(During plugin update) Failed to add page_type to {$table}: {$wpdb->last_error}" );
				return false; // Generated Columns は page_type に依存するため、失敗時は中断
			}
			$qahm_log->info( "(During plugin update) Successfully added page_type column to {$table}" );
		}

		// 2. page_fetch_status カラム（TINYINT, NULL=未取得, 1=成功, -1=失敗）
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL with table name from $wpdb->prefix; no user input.
		$col = $qahm_db->get_var( "SHOW COLUMNS FROM {$table} LIKE 'page_fetch_status'" );
		if ( ! $col ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL with table name from $wpdb->prefix; no user input.
			$result = $qahm_db->query( "ALTER TABLE {$table} ADD COLUMN page_fetch_status TINYINT DEFAULT NULL" );
			if ( false === $result ) {
				$qahm_log->error( "(During plugin update) Failed to add page_fetch_status to {$table}: {$wpdb->last_error}" );
			} else {
				$qahm_log->info( "(During plugin update) Successfully added page_fetch_status column to {$table}" );
			}
		}

		// 3. Generated Columns（STORED） — page_type から自動計算
		$has_failure       = false;
		$generated_columns = array(
			'is_article'    => 'page_type & 1',
			'is_product'    => '(page_type >> 1) & 1',
			'is_list'       => '(page_type >> 2) & 1',
			'is_form'       => '(page_type >> 3) & 1',
			'is_trust_info' => '(page_type >> 4) & 1',
			'is_faq'        => '(page_type >> 5) & 1',
			'is_landing'    => '(page_type >> 6) & 1',
			'is_search'     => '(page_type >> 7) & 1',
			'is_account'    => '(page_type >> 8) & 1',
			'is_cart'       => '(page_type >> 9) & 1',
			'is_checkout'   => '(page_type >> 10) & 1',
			'is_confirm'    => '(page_type >> 11) & 1',
			'is_thanks'     => '(page_type >> 12) & 1',
			'is_top_page'   => '(page_type >> 13) & 1',
			'is_event'      => '(page_type >> 14) & 1',
			'is_recipe'     => '(page_type >> 15) & 1',
			'is_job'        => '(page_type >> 16) & 1',
			'is_video'      => '(page_type >> 17) & 1',
			'is_howto'      => '(page_type >> 18) & 1',
			'is_qa_forum'   => '(page_type >> 19) & 1',
		);
		foreach ( $generated_columns as $name => $expr ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL with table name from $wpdb->prefix; no user input.
			$col = $qahm_db->get_var( "SHOW COLUMNS FROM {$table} LIKE '{$name}'" );
			if ( ! $col ) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL with table name from $wpdb->prefix; column name from hardcoded array.
				$result = $qahm_db->query(
					"ALTER TABLE {$table} ADD COLUMN {$name} TINYINT GENERATED ALWAYS AS ({$expr}) STORED"
				);
				if ( false === $result ) {
					$qahm_log->error( "(During plugin update) Failed to add {$name} to {$table}: {$wpdb->last_error}" );
					$has_failure = true;
				} else {
					$qahm_log->info( "(During plugin update) Successfully added {$name} column to {$table}" );
				}
			}
		}

		return ! $has_failure;
	}

	/**
	 * バージョン5.1.0.0: qa_page_version_hist重複レコード削除
	 * 各page_id+device_idの組み合わせにつき、最古のversion_id（値が最小）のレコードのみを残し、他を削除する
	 * base_htmlとbase_selectorが存在する古いレコードを保持するため、version_id ASCでソートし最小を残す
	 */
	private function cleanup_version_hist_duplicates() {
		global $wpdb;
		global $qahm_log;

		$start_time         = time();
		$max_execution_time = 25;
		$batch_size         = 100;

		$data_dir      = $this->get_data_dir_path();
		$progress_file = $data_dir . 'cleanup_version_hist_progress.php';

		if ( $this->wrap_exists( $progress_file ) ) {
			$progress = $this->wrap_unserialize( $this->wrap_get_contents( $progress_file ) );
		} else {
			$table_name = $wpdb->prefix . 'qa_page_version_hist';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is safely constructed using $wpdb->prefix. Direct database call is necessary for counting distinct page_ids. Caching would not provide benefits in this context. (important-comment)
			$total_pages = $wpdb->get_var(
				"SELECT COUNT(DISTINCT page_id) FROM {$table_name}"
			);

			$progress = array(
				'last_processed_page_id' => 0,
				'total_pages'            => (int) $total_pages,
				'processed_pages'        => 0,
				'deleted_records'        => 0,
				'start_time'             => $start_time,
				'error_count'            => array(),
			);

			$qahm_log->info( "qa_page_version_hist cleanup started. Total pages: {$total_pages}" );
		}

		$table_name = $wpdb->prefix . 'qa_page_version_hist';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is safely constructed using $wpdb->prefix. Direct database call is necessary for retrieving distinct page_ids. Caching would not provide benefits in this context. (important-comment)
		$page_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT page_id 
                 FROM {$table_name} 
                 WHERE page_id > %d
                 ORDER BY page_id ASC 
                 LIMIT %d",
				$progress['last_processed_page_id'],
				$batch_size
			)
		);

		if ( empty( $page_ids ) ) {
			$this->wrap_delete( $progress_file );
			$qahm_log->info(
				'qa_page_version_hist cleanup completed. ' .
				"Processed: {$progress['processed_pages']} pages, " .
				"Deleted: {$progress['deleted_records']} records"
			);
			return true;  // 完了
		}

		$device_ids = array(
			QAHM_DEVICES['desktop']['id'],
			QAHM_DEVICES['tablet']['id'],
			QAHM_DEVICES['smartphone']['id'],
		);

		foreach ( $page_ids as $page_id ) {
			if ( time() - $start_time > $max_execution_time ) {
				$this->wrap_put_contents( $progress_file, $this->wrap_serialize( $progress ) );
				$qahm_log->info(
					'qa_page_version_hist cleanup paused (timeout). ' .
					"Progress: {$progress['processed_pages']}/{$progress['total_pages']}"
				);
				return false;  // 未完了
			}

			if ( isset( $progress['error_count'][ $page_id ] ) && $progress['error_count'][ $page_id ] >= 3 ) {
				$qahm_log->warning( "Skipping page_id {$page_id} due to repeated errors" );
				$progress['last_processed_page_id'] = $page_id;
				++$progress['processed_pages'];
				continue;
			}

			foreach ( $device_ids as $device_id ) {
				// version_noごとに処理するため、まず存在するversion_noを取得
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is safely constructed using $wpdb->prefix. Direct database call is necessary for retrieving distinct version numbers. Caching would not provide benefits in this context. (important-comment)
				$version_nos = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT version_no 
                         FROM {$table_name} 
                         WHERE page_id = %d AND device_id = %d
                         ORDER BY version_no ASC",
						$page_id,
						$device_id
					)
				);

				if ( empty( $version_nos ) ) {
					continue;
				}

				// 各version_noごとに重複チェック
				foreach ( $version_nos as $version_no ) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is safely constructed using $wpdb->prefix. Direct database call is necessary for retrieving version records. Caching would not provide benefits in this context. (important-comment)
					$versions = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT version_id, version_no, insert_datetime 
                             FROM {$table_name} 
                             WHERE page_id = %d AND device_id = %d AND version_no = %d
                             ORDER BY version_id ASC",
							$page_id,
							$device_id,
							$version_no
						),
						ARRAY_A
					);

					if ( empty( $versions ) || $this->wrap_count( $versions ) <= 1 ) {
						continue;
					}

					$min_version_id = (int) $versions[0]['version_id'];

					$delete_version_ids = array();
					foreach ( $versions as $version ) {
						if ( (int) $version['version_id'] !== $min_version_id ) {
							$delete_version_ids[] = (int) $version['version_id'];
						}
					}

					if ( ! empty( $delete_version_ids ) ) {
						$placeholders = $this->wrap_implode( ',', array_fill( 0, $this->wrap_count( $delete_version_ids ), '%d' ) );
						$query        = $wpdb->prepare(
							"DELETE FROM {$table_name} 
                             WHERE page_id = %d AND device_id = %d AND version_id IN ({$placeholders})",
							$this->wrap_array_merge( array( $page_id, $device_id ), $delete_version_ids )
						);

                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name and placeholders are safely constructed. This SQL query uses placeholders and $wpdb->prepare(), but it may trigger warnings due to the dynamic construction of the SQL string. Direct database call is necessary in this case due to the complexity of the SQL query. Caching would not provide significant performance benefits in this context. (important-comment)
						$result = $wpdb->query( $query );

						if ( $result === false ) {
							$qahm_log->error(
								"Failed to delete version_hist records for page_id={$page_id}, device_id={$device_id}, version_no={$version_no}. " .
								"Error: {$wpdb->last_error}"
							);

							if ( ! isset( $progress['error_count'][ $page_id ] ) ) {
								$progress['error_count'][ $page_id ] = 0;
							}
							++$progress['error_count'][ $page_id ];

							$this->wrap_put_contents( $progress_file, $this->wrap_serialize( $progress ) );
							return false;  // 未完了
						} else {
							$progress['deleted_records'] += $result;

							// ファイルシステムからも削除
							$version_hist_dir = $data_dir . 'view/all/version_hist/';
							foreach ( $delete_version_ids as $vid ) {
								$version_file = $version_hist_dir . $vid . '_version.php';
								if ( $this->wrap_exists( $version_file ) ) {
									$this->wrap_delete( $version_file );
								}
							}
						}
					}
				}
			}

			$progress['last_processed_page_id'] = $page_id;
			++$progress['processed_pages'];

			if ( isset( $progress['error_count'][ $page_id ] ) ) {
				unset( $progress['error_count'][ $page_id ] );
			}
		}

		// バッチ処理完了後、次のバッチがあるか確認
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is safely constructed using $wpdb->prefix. Direct database call is necessary for checking if more pages exist. Caching would not provide benefits in this context. (important-comment)
		$next_page_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT page_id 
                 FROM {$table_name} 
                 WHERE page_id > %d
                 ORDER BY page_id ASC 
                 LIMIT 1",
				$progress['last_processed_page_id']
			)
		);

		if ( empty( $next_page_ids ) ) {
			// 本当に完了
			$this->wrap_delete( $progress_file );
			$qahm_log->info(
				'qa_page_version_hist cleanup completed. ' .
				"Processed: {$progress['processed_pages']} pages, " .
				"Deleted: {$progress['deleted_records']} records"
			);
			return true;
		}

		// まだ続きがある
		$this->wrap_put_contents( $progress_file, $this->wrap_serialize( $progress ) );
		$qahm_log->info(
			'qa_page_version_hist cleanup in progress. ' .
			"Progress: {$progress['processed_pages']}/{$progress['total_pages']}"
		);
		return false;
	}

	/**
	 * qa-config.phpに新しい定数を追記する汎用メソッド
	 *
	 * @param string $constant_name 定数名
	 * @param mixed  $value         定数の値
	 * @return bool 成功時true、失敗時false、既に存在する場合はnull
	 */
	private function add_constant_to_config( $constant_name, $value ) {
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			$this->init_wp_filesystem();
		}

		$config_file_path = WP_CONTENT_DIR . '/qa-zero-data/qa-config.php';

		if ( ! $wp_filesystem->exists( $config_file_path ) ) {
			return false;
		}

		$config_content = $wp_filesystem->get_contents( $config_file_path );

		if ( $config_content === false ) {
			return false;
		}

		// 厳密な存在チェック
		$pattern = "/define\s*\(\s*['\"]" . preg_quote( $constant_name, '/' ) . "['\"]\s*,/";
		if ( preg_match( $pattern, $config_content ) ) {
			return null; // 既に存在
		}

		// 存在しない場合はファイル末尾に追加
		$config_content .= "\ndefine('{$constant_name}', {$value});";
		return $wp_filesystem->put_contents( $config_file_path, $config_content, 0644 );
	}

	/**
	 * qa-config.php から指定した定数の define() 行を削除する
	 *
	 * @param string $constant_name 定数名
	 * @return bool 成功時true、失敗時またはファイル/定数が存在しない場合false
	 */
	private function remove_constant_from_config( $constant_name ) {
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			$this->init_wp_filesystem();
		}

		$config_file_path = WP_CONTENT_DIR . '/qa-zero-data/qa-config.php';

		if ( ! $wp_filesystem->exists( $config_file_path ) ) {
			return false;
		}

		$config_content = $wp_filesystem->get_contents( $config_file_path );

		if ( false === $config_content ) {
			return false;
		}

		// define('CONSTANT_NAME', ...); の行を削除（前後の空行も1つ除去）
		$pattern     = "/\n?define\s*\(\s*['\"]" . preg_quote( $constant_name, '/' ) . "['\"]\s*,\s*[^)]*\)\s*;\s*\n?/";
		$new_content = preg_replace( $pattern, "\n", $config_content );

		if ( $new_content === $config_content ) {
			return false; // 変更なし（定数が存在しなかった）
		}

		return $wp_filesystem->put_contents( $config_file_path, $new_content, 0644 );
	}
}
