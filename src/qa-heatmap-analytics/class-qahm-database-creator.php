<?php
defined( 'ABSPATH' ) || exit;
/**
 * QAデータベースのテーブル作成クラス
 * @package qa_heatmap
 */

class QAHM_Database_Creator extends QAHM_Base {
	const CHECK_NOT_EXISTS = -123454321;

	/**
	 * 全テーブルのデータベース初期化処理
	 * 外部から呼び出される主要メソッド
	 */
	public function initialize_database() {
		// 各テーブルの作成と成功時のバージョン設定
		$this->create_table_if_needed( 'qa_readers' );
		$this->create_table_if_needed( 'qa_pages' );
		$this->create_table_if_needed( 'qa_utm_media' );
		$this->create_table_if_needed( 'qa_utm_content' );
		$this->create_table_if_needed( 'qa_utm_sources' );
		$this->create_table_if_needed( 'qa_utm_campaigns' );
		$this->create_table_if_needed( 'qa_pv_log' );
		$this->create_table_if_needed( 'qa_search_log' );
		$this->create_table_if_needed( 'qa_page_version_hist' );
	}

	/**
	 * テーブルが必要な場合のみ作成し、成功時にバージョンを設定
	 *
	 * @param string $table_name テーブル名（プレフィックスなし）
	 */
	private function create_table_if_needed( $table_name ) {
		global $qahm_log, $qahm_db;

		$full_table_name = $qahm_db->prefix . $table_name;

		// 1. まずテーブルの存在をチェック
		if ( $this->table_exists( $full_table_name ) ) {
			$qahm_log->info( "QAHM: Table {$table_name} already exists, skipping creation" );

			// テーブルが存在するが、バージョン情報がない場合は設定
			$this->ensure_table_version( $table_name );
			return;
		}

		// 2. テーブルが存在しない場合のみ、バージョンチェックして作成
		$version_key = $table_name . '_version';
		$ver         = $this->wrap_get_option( $version_key, self::CHECK_NOT_EXISTS );

		if ( $ver === self::CHECK_NOT_EXISTS ) {
			// テーブル作成SQLを取得
			$method_name = 'get_' . $table_name . '_create_table';

			if ( method_exists( $this, $method_name ) ) {
				$query = $this->$method_name();

				// テーブル作成を実行
				if ( $this->execute_sql_query( $query ) ) {
					// 成功時のみバージョンを設定
					$target_version = QAHM_DB_OPTIONS[ $version_key ];
					$this->wrap_update_option( $version_key, $target_version );
					$qahm_log->info( "QAHM: Successfully created table {$table_name} and set version to {$target_version}" );
				} else {
					$qahm_log->error( "QAHM: Failed to create table {$table_name}" );
				}
			} else {
				$qahm_log->error( "QAHM: Method {$method_name} not found for table {$table_name}" );
			}
		}
	}

	/**
	 * テーブルが存在するかチェック
	 *
	 * @param string $table_name フルテーブル名（プレフィックス付き）
	 * @return bool テーブルが存在する場合true
	 */
	private function table_exists( $table_name ) {
		global $qahm_db;

		$result = $qahm_db->get_var(
			$qahm_db->prepare(
				'SHOW TABLES LIKE %s',
				$table_name
			)
		);

		return ! is_null( $result );
	}

	/**
	 * テーブルが存在するがバージョン情報がない場合、バージョンを設定
	 *
	 * @param string $table_name テーブル名（プレフィックスなし）
	 */
	private function ensure_table_version( $table_name ) {
		$version_key = $table_name . '_version';
		$ver         = $this->wrap_get_option( $version_key, self::CHECK_NOT_EXISTS );

		// バージョン情報がない場合は設定
		if ( $ver === self::CHECK_NOT_EXISTS ) {
			if ( isset( QAHM_DB_OPTIONS[ $version_key ] ) ) {
				$target_version = QAHM_DB_OPTIONS[ $version_key ];
				$this->wrap_update_option( $version_key, $target_version );

				global $qahm_log;
				$qahm_log->info( "QAHM: Set version {$target_version} for existing table {$table_name}" );
			}
		}
	}

	/**
	 * SQLクエリを実行する
	 *
	 * @param string $query 実行するSQLクエリ
	 * @return bool 実行成功の場合true、失敗の場合false
	 */
	private function execute_sql_query( $query ) {
		global $qahm_db;
		global $qahm_log;

		if ( empty( $query ) ) {
			return false;
		}

		// クエリの前処理（コメント削除、整形）
		$cleaned_query = $this->clean_sql_query( $query );

		// セミコロンで分割してクエリを実行
		$query_array = $this->wrap_explode( ';', $cleaned_query );
		$success     = true;

		foreach ( $query_array as $single_query ) {
			$single_query = $this->wrap_trim( $single_query );
			if ( ! empty( $single_query ) ) {
				$result = $qahm_db->query( $single_query );

				// エラーハンドリング
				if ( $result === false ) {
					$success = false;
					$qahm_log->error( 'QAHM SQL Error: ' . $qahm_db->last_error );
					$qahm_log->error( 'Failed Query: ' . $single_query );
					// 一つでも失敗したら全体を失敗として扱う
					break;
				}
			}
		}

		return $success;
	}

	/**
	 * SQLクエリをクリーンアップする
	 *
	 * @param string $query クリーンアップするクエリ
	 * @return string クリーンアップされたクエリ
	 */
	private function clean_sql_query( $query ) {
		// 行ごとに分割
		$query_lines   = $this->wrap_explode( PHP_EOL, $query );
		$cleaned_lines = array();

		foreach ( $query_lines as $line ) {
			// タブを削除
			$line = $this->wrap_trim( $line, "\t" );

			// コメント行を除外
			if ( $this->wrap_substr( $this->wrap_trim( $line ), 0, 2 ) !== '--' && ! empty( $this->wrap_trim( $line ) ) ) {
				$cleaned_lines[] = $line;
			}
		}

		return $this->wrap_implode( ' ', $cleaned_lines );
	}

	/**
	 * 特定のテーブルのバージョンを更新
	 *
	 * @param string $table_name テーブル名
	 * @param int $new_version 新しいバージョン
	 */
	public function update_table_version( $table_name, $new_version ) {
		$option_name = $table_name . '_version';
		$this->wrap_update_option( $option_name, $new_version );
	}

	/**
	 * GSCクエリログテーブル作成（tracking_id対応版）
	 *
	 * @param string $tracking_id トラッキングID
	 * @return bool 作成成功の場合true
	 */
	public function create_gsc_query_log_table( $tracking_id ) {
		global $qahm_log, $qahm_db;

		if ( empty( $tracking_id ) ) {
			$qahm_log->warning( 'QAHM: tracking_id is required for GSC query log table' );
			return false;
		}

		// テーブル名にtracking_idを含める
		$table_name = $qahm_db->prefix . 'qa_gsc_' . $tracking_id . '_query_log';

		// テーブルの存在チェック
		if ( $this->table_exists( $table_name ) ) {
			$qahm_log->info( "QAHM: GSC query log table for tracking_id {$tracking_id} already exists" );
			$this->ensure_table_version( 'qa_gsc_query_log' );
			return true;
		}

		// テーブル作成SQL取得・実行
		$query = $this->get_qa_gsc_query_log_create_table( $tracking_id );

		if ( $this->execute_sql_query( $query ) ) {
			$this->ensure_table_version( 'qa_gsc_query_log' );
			$qahm_log->info( "QAHM: Successfully created GSC query log table for tracking_id: {$tracking_id}" );
			return true;
		} else {
			$qahm_log->error( "QAHM: Failed to create GSC query log table for tracking_id: {$tracking_id}" );
			return false;
		}
	}

	// qa_readersテーブルの作成SQLを返す
	public function get_qa_readers_create_table() {
		global $wpdb;
		$charset_collate = '';

		// charsetを指定する
		if ( $wpdb->charset ) {
			$charset_collate = 'DEFAULT CHARACTER SET ' . $wpdb->charset;
		}

		// 照合順序を指定する（ある場合。通常デフォルトのutf8_general_ci）
		if ( $wpdb->collate ) {
			$charset_collate .= ' COLLATE ' . $wpdb->collate;
		}

		$lines = array(
			'		-- パーティションはunixtimeで問題のあるとされる2038/1/19まで=2037年12月まで作成する。reader,pv_log,version_histの3つで使用する。',
			'		-- qa_readers',
			"		drop table if exists {$wpdb->prefix}qa_readers;",
			"		create table {$wpdb->prefix}qa_readers",
			'		(',
			'			reader_id int auto_increment,',
			'			qa_id char(28) not null,',
			'			original_id varchar(191) null,',
			'			UAos varchar(32) null,',
			'			UAbrowser varchar(32) null,',
			'			language char(2) null,',
			'			country_code char(2) null,',
			'			is_reject tinyint(1) null,',
			'			update_date date not null,',
			'			primary key (reader_id, update_date)',
			"		) {$charset_collate}",
			'		partition by range COLUMNS(update_date) (',
			"			partition  p202001 values less than ('2020-02-01'),",
			"			partition  p202002 values less than ('2020-03-01'),",
			"			partition  p202003 values less than ('2020-04-01'),",
			"			partition  p202004 values less than ('2020-05-01'),",
			"			partition  p202005 values less than ('2020-06-01'),",
			"			partition  p202006 values less than ('2020-07-01'),",
			"			partition  p202007 values less than ('2020-08-01'),",
			"			partition  p202008 values less than ('2020-09-01'),",
			"			partition  p202009 values less than ('2020-10-01'),",
			"			partition  p202010 values less than ('2020-11-01'),",
			"			partition  p202011 values less than ('2020-12-01'),",
			"			partition  p202012 values less than ('2021-01-01'),",
			"			partition  p202101 values less than ('2021-02-01'),",
			"			partition  p202102 values less than ('2021-03-01'),",
			"			partition  p202103 values less than ('2021-04-01'),",
			"			partition  p202104 values less than ('2021-05-01'),",
			"			partition  p202105 values less than ('2021-06-01'),",
			"			partition  p202106 values less than ('2021-07-01'),",
			"			partition  p202107 values less than ('2021-08-01'),",
			"			partition  p202108 values less than ('2021-09-01'),",
			"			partition  p202109 values less than ('2021-10-01'),",
			"			partition  p202110 values less than ('2021-11-01'),",
			"			partition  p202111 values less than ('2021-12-01'),",
			"			partition  p202112 values less than ('2022-01-01'),",
			"			partition  p202201 values less than ('2022-02-01'),",
			"			partition  p202202 values less than ('2022-03-01'),",
			"			partition  p202203 values less than ('2022-04-01'),",
			"			partition  p202204 values less than ('2022-05-01'),",
			"			partition  p202205 values less than ('2022-06-01'),",
			"			partition  p202206 values less than ('2022-07-01'),",
			"			partition  p202207 values less than ('2022-08-01'),",
			"			partition  p202208 values less than ('2022-09-01'),",
			"			partition  p202209 values less than ('2022-10-01'),",
			"			partition  p202210 values less than ('2022-11-01'),",
			"			partition  p202211 values less than ('2022-12-01'),",
			"			partition  p202212 values less than ('2023-01-01'),",
			"			partition  p202301 values less than ('2023-02-01'),",
			"			partition  p202302 values less than ('2023-03-01'),",
			"			partition  p202303 values less than ('2023-04-01'),",
			"			partition  p202304 values less than ('2023-05-01'),",
			"			partition  p202305 values less than ('2023-06-01'),",
			"			partition  p202306 values less than ('2023-07-01'),",
			"			partition  p202307 values less than ('2023-08-01'),",
			"			partition  p202308 values less than ('2023-09-01'),",
			"			partition  p202309 values less than ('2023-10-01'),",
			"			partition  p202310 values less than ('2023-11-01'),",
			"			partition  p202311 values less than ('2023-12-01'),",
			"			partition  p202312 values less than ('2024-01-01'),",
			"			partition  p202401 values less than ('2024-02-01'),",
			"			partition  p202402 values less than ('2024-03-01'),",
			"			partition  p202403 values less than ('2024-04-01'),",
			"			partition  p202404 values less than ('2024-05-01'),",
			"			partition  p202405 values less than ('2024-06-01'),",
			"			partition  p202406 values less than ('2024-07-01'),",
			"			partition  p202407 values less than ('2024-08-01'),",
			"			partition  p202408 values less than ('2024-09-01'),",
			"			partition  p202409 values less than ('2024-10-01'),",
			"			partition  p202410 values less than ('2024-11-01'),",
			"			partition  p202411 values less than ('2024-12-01'),",
			"			partition  p202412 values less than ('2025-01-01'),",
			"			partition  p202501 values less than ('2025-02-01'),",
			"			partition  p202502 values less than ('2025-03-01'),",
			"			partition  p202503 values less than ('2025-04-01'),",
			"			partition  p202504 values less than ('2025-05-01'),",
			"			partition  p202505 values less than ('2025-06-01'),",
			"			partition  p202506 values less than ('2025-07-01'),",
			"			partition  p202507 values less than ('2025-08-01'),",
			"			partition  p202508 values less than ('2025-09-01'),",
			"			partition  p202509 values less than ('2025-10-01'),",
			"			partition  p202510 values less than ('2025-11-01'),",
			"			partition  p202511 values less than ('2025-12-01'),",
			"			partition  p202512 values less than ('2026-01-01'),",
			"			partition  p202601 values less than ('2026-02-01'),",
			"			partition  p202602 values less than ('2026-03-01'),",
			"			partition  p202603 values less than ('2026-04-01'),",
			"			partition  p202604 values less than ('2026-05-01'),",
			"			partition  p202605 values less than ('2026-06-01'),",
			"			partition  p202606 values less than ('2026-07-01'),",
			"			partition  p202607 values less than ('2026-08-01'),",
			"			partition  p202608 values less than ('2026-09-01'),",
			"			partition  p202609 values less than ('2026-10-01'),",
			"			partition  p202610 values less than ('2026-11-01'),",
			"			partition  p202611 values less than ('2026-12-01'),",
			"			partition  p202612 values less than ('2027-01-01'),",
			"			partition  p202701 values less than ('2027-02-01'),",
			"			partition  p202702 values less than ('2027-03-01'),",
			"			partition  p202703 values less than ('2027-04-01'),",
			"			partition  p202704 values less than ('2027-05-01'),",
			"			partition  p202705 values less than ('2027-06-01'),",
			"			partition  p202706 values less than ('2027-07-01'),",
			"			partition  p202707 values less than ('2027-08-01'),",
			"			partition  p202708 values less than ('2027-09-01'),",
			"			partition  p202709 values less than ('2027-10-01'),",
			"			partition  p202710 values less than ('2027-11-01'),",
			"			partition  p202711 values less than ('2027-12-01'),",
			"			partition  p202712 values less than ('2028-01-01'),",
			"			partition  p202801 values less than ('2028-02-01'),",
			"			partition  p202802 values less than ('2028-03-01'),",
			"			partition  p202803 values less than ('2028-04-01'),",
			"			partition  p202804 values less than ('2028-05-01'),",
			"			partition  p202805 values less than ('2028-06-01'),",
			"			partition  p202806 values less than ('2028-07-01'),",
			"			partition  p202807 values less than ('2028-08-01'),",
			"			partition  p202808 values less than ('2028-09-01'),",
			"			partition  p202809 values less than ('2028-10-01'),",
			"			partition  p202810 values less than ('2028-11-01'),",
			"			partition  p202811 values less than ('2028-12-01'),",
			"			partition  p202812 values less than ('2029-01-01'),",
			"			partition  p202901 values less than ('2029-02-01'),",
			"			partition  p202902 values less than ('2029-03-01'),",
			"			partition  p202903 values less than ('2029-04-01'),",
			"			partition  p202904 values less than ('2029-05-01'),",
			"			partition  p202905 values less than ('2029-06-01'),",
			"			partition  p202906 values less than ('2029-07-01'),",
			"			partition  p202907 values less than ('2029-08-01'),",
			"			partition  p202908 values less than ('2029-09-01'),",
			"			partition  p202909 values less than ('2029-10-01'),",
			"			partition  p202910 values less than ('2029-11-01'),",
			"			partition  p202911 values less than ('2029-12-01'),",
			"			partition  p202912 values less than ('2030-01-01'),",
			"			partition  p203001 values less than ('2030-02-01'),",
			"			partition  p203002 values less than ('2030-03-01'),",
			"			partition  p203003 values less than ('2030-04-01'),",
			"			partition  p203004 values less than ('2030-05-01'),",
			"			partition  p203005 values less than ('2030-06-01'),",
			"			partition  p203006 values less than ('2030-07-01'),",
			"			partition  p203007 values less than ('2030-08-01'),",
			"			partition  p203008 values less than ('2030-09-01'),",
			"			partition  p203009 values less than ('2030-10-01'),",
			"			partition  p203010 values less than ('2030-11-01'),",
			"			partition  p203011 values less than ('2030-12-01'),",
			"			partition  p203012 values less than ('2031-01-01'),",
			"			partition  p203101 values less than ('2031-02-01'),",
			"			partition  p203102 values less than ('2031-03-01'),",
			"			partition  p203103 values less than ('2031-04-01'),",
			"			partition  p203104 values less than ('2031-05-01'),",
			"			partition  p203105 values less than ('2031-06-01'),",
			"			partition  p203106 values less than ('2031-07-01'),",
			"			partition  p203107 values less than ('2031-08-01'),",
			"			partition  p203108 values less than ('2031-09-01'),",
			"			partition  p203109 values less than ('2031-10-01'),",
			"			partition  p203110 values less than ('2031-11-01'),",
			"			partition  p203111 values less than ('2031-12-01'),",
			"			partition  p203112 values less than ('2032-01-01'),",
			"			partition  p203201 values less than ('2032-02-01'),",
			"			partition  p203202 values less than ('2032-03-01'),",
			"			partition  p203203 values less than ('2032-04-01'),",
			"			partition  p203204 values less than ('2032-05-01'),",
			"			partition  p203205 values less than ('2032-06-01'),",
			"			partition  p203206 values less than ('2032-07-01'),",
			"			partition  p203207 values less than ('2032-08-01'),",
			"			partition  p203208 values less than ('2032-09-01'),",
			"			partition  p203209 values less than ('2032-10-01'),",
			"			partition  p203210 values less than ('2032-11-01'),",
			"			partition  p203211 values less than ('2032-12-01'),",
			"			partition  p203212 values less than ('2033-01-01'),",
			"			partition  p203301 values less than ('2033-02-01'),",
			"			partition  p203302 values less than ('2033-03-01'),",
			"			partition  p203303 values less than ('2033-04-01'),",
			"			partition  p203304 values less than ('2033-05-01'),",
			"			partition  p203305 values less than ('2033-06-01'),",
			"			partition  p203306 values less than ('2033-07-01'),",
			"			partition  p203307 values less than ('2033-08-01'),",
			"			partition  p203308 values less than ('2033-09-01'),",
			"			partition  p203309 values less than ('2033-10-01'),",
			"			partition  p203310 values less than ('2033-11-01'),",
			"			partition  p203311 values less than ('2033-12-01'),",
			"			partition  p203312 values less than ('2034-01-01'),",
			"			partition  p203401 values less than ('2034-02-01'),",
			"			partition  p203402 values less than ('2034-03-01'),",
			"			partition  p203403 values less than ('2034-04-01'),",
			"			partition  p203404 values less than ('2034-05-01'),",
			"			partition  p203405 values less than ('2034-06-01'),",
			"			partition  p203406 values less than ('2034-07-01'),",
			"			partition  p203407 values less than ('2034-08-01'),",
			"			partition  p203408 values less than ('2034-09-01'),",
			"			partition  p203409 values less than ('2034-10-01'),",
			"			partition  p203410 values less than ('2034-11-01'),",
			"			partition  p203411 values less than ('2034-12-01'),",
			"			partition  p203412 values less than ('2035-01-01'),",
			"			partition  p203501 values less than ('2035-02-01'),",
			"			partition  p203502 values less than ('2035-03-01'),",
			"			partition  p203503 values less than ('2035-04-01'),",
			"			partition  p203504 values less than ('2035-05-01'),",
			"			partition  p203505 values less than ('2035-06-01'),",
			"			partition  p203506 values less than ('2035-07-01'),",
			"			partition  p203507 values less than ('2035-08-01'),",
			"			partition  p203508 values less than ('2035-09-01'),",
			"			partition  p203509 values less than ('2035-10-01'),",
			"			partition  p203510 values less than ('2035-11-01'),",
			"			partition  p203511 values less than ('2035-12-01'),",
			"			partition  p203512 values less than ('2036-01-01'),",
			"			partition  p203601 values less than ('2036-02-01'),",
			"			partition  p203602 values less than ('2036-03-01'),",
			"			partition  p203603 values less than ('2036-04-01'),",
			"			partition  p203604 values less than ('2036-05-01'),",
			"			partition  p203605 values less than ('2036-06-01'),",
			"			partition  p203606 values less than ('2036-07-01'),",
			"			partition  p203607 values less than ('2036-08-01'),",
			"			partition  p203608 values less than ('2036-09-01'),",
			"			partition  p203609 values less than ('2036-10-01'),",
			"			partition  p203610 values less than ('2036-11-01'),",
			"			partition  p203611 values less than ('2036-12-01'),",
			"			partition  p203612 values less than ('2037-01-01'),",
			"			partition  p203701 values less than ('2037-02-01'),",
			"			partition  p203702 values less than ('2037-03-01'),",
			"			partition  p203703 values less than ('2037-04-01'),",
			"			partition  p203704 values less than ('2037-05-01'),",
			"			partition  p203705 values less than ('2037-06-01'),",
			"			partition  p203706 values less than ('2037-07-01'),",
			"			partition  p203707 values less than ('2037-08-01'),",
			"			partition  p203708 values less than ('2037-09-01'),",
			"			partition  p203709 values less than ('2037-10-01'),",
			"			partition  p203710 values less than ('2037-11-01'),",
			"			partition  p203711 values less than ('2037-12-01'),",
			"			partition  p203712 values less than ('2038-01-01'),",
			'		PARTITION pmax VALUES LESS THAN (MAXVALUE)',
			'		);',
			'		',
			"		create index {$wpdb->prefix}qa_readers_qa_id_index",
			"			on {$wpdb->prefix}qa_readers (qa_id)",
			'		;',
			"		create index {$wpdb->prefix}qa_readers_original_id_index",
			"			on {$wpdb->prefix}qa_readers (original_id)",
			'		;',
			"		create index {$wpdb->prefix}qa_readers_UAos_index",
			"			on {$wpdb->prefix}qa_readers (UAos)",
			'		;',
			'		create index idx_readers_country',
			"			on {$wpdb->prefix}qa_readers (country_code)",
			'		;',
			'		',

		);
		return $this->wrap_implode( "\n", $lines );
	}


	// qa_readersテーブルの作成SQLを返す
	public function get_qa_pages_create_table() {
		global $wpdb;
		$charset_collate = '';

		// charsetを指定する
		if ( $wpdb->charset ) {
			$charset_collate = 'DEFAULT CHARACTER SET ' . $wpdb->charset;
		}

		// 照合順序を指定する（ある場合。通常デフォルトのutf8_general_ci）
		if ( $wpdb->collate ) {
			$charset_collate .= ' COLLATE ' . $wpdb->collate;
		}

		$lines = array(
			'		-- qa_pages',
			"		drop table if exists {$wpdb->prefix}qa_pages;",
			"		create table {$wpdb->prefix}qa_pages",
			'		(',
			'			page_id int auto_increment',
			'				primary key,',
			'			tracking_id char(16) null,',
			'			wp_qa_type varchar(20) null,',
			'			wp_qa_id bigint null,',
			'			url text null,',
			'			url_hash char(16) null,',
			'			path_url_hash char(16) null,',
			'			title varchar(128) null,',
			'			update_date date not null,',
			'			page_type bigint unsigned default null,',
			'			page_fetch_status tinyint default null,',
			'			is_article tinyint generated always as (page_type & 1) stored,',
			'			is_product tinyint generated always as ((page_type >> 1) & 1) stored,',
			'			is_list tinyint generated always as ((page_type >> 2) & 1) stored,',
			'			is_form tinyint generated always as ((page_type >> 3) & 1) stored,',
			'			is_trust_info tinyint generated always as ((page_type >> 4) & 1) stored,',
			'			is_faq tinyint generated always as ((page_type >> 5) & 1) stored,',
			'			is_landing tinyint generated always as ((page_type >> 6) & 1) stored,',
			'			is_search tinyint generated always as ((page_type >> 7) & 1) stored,',
			'			is_account tinyint generated always as ((page_type >> 8) & 1) stored,',
			'			is_cart tinyint generated always as ((page_type >> 9) & 1) stored,',
			'			is_checkout tinyint generated always as ((page_type >> 10) & 1) stored,',
			'			is_confirm tinyint generated always as ((page_type >> 11) & 1) stored,',
			'			is_thanks tinyint generated always as ((page_type >> 12) & 1) stored,',
			'			is_top_page tinyint generated always as ((page_type >> 13) & 1) stored,',
			'			is_event tinyint generated always as ((page_type >> 14) & 1) stored,',
			'			is_recipe tinyint generated always as ((page_type >> 15) & 1) stored,',
			'			is_job tinyint generated always as ((page_type >> 16) & 1) stored,',
			'			is_video tinyint generated always as ((page_type >> 17) & 1) stored,',
			'			is_howto tinyint generated always as ((page_type >> 18) & 1) stored,',
			'			is_qa_forum tinyint generated always as ((page_type >> 19) & 1) stored,',
			'			constraint qa_pages_url_hash_uindex',
			'				unique (url_hash)',
			"		) {$charset_collate}",
			'		;',
			'		',
			"		create index {$wpdb->prefix}qa_pages_tracking_id_index",
			"			on {$wpdb->prefix}qa_pages (tracking_id)",
			'		;',
			"		create index {$wpdb->prefix}qa_pages_url_hash_index",
			"			on {$wpdb->prefix}qa_pages (url_hash)",
			'		;',
			"		create index {$wpdb->prefix}qa_pages_path_url_hash_index",
			"			on {$wpdb->prefix}qa_pages (path_url_hash)",
			'		;',

		);
		return $this->wrap_implode( "\n", $lines );
	}



	// qa_utm_mediaテーブルの作成SQLを返す
	public function get_qa_utm_media_create_table() {
		global $wpdb;
		$charset_collate = '';

		// charsetを指定する
		if ( $wpdb->charset ) {
			$charset_collate = 'DEFAULT CHARACTER SET ' . $wpdb->charset;
		}

		// 照合順序を指定する（ある場合。通常デフォルトのutf8_general_ci）
		if ( $wpdb->collate ) {
			$charset_collate .= ' COLLATE ' . $wpdb->collate;
		}

		$lines = array(
			'		-- qa_utm_media',
			"		drop table if exists {$wpdb->prefix}qa_utm_media;",
			"		create table {$wpdb->prefix}qa_utm_media",
			'		(',
			'			medium_id int auto_increment',
			'				primary key,',
			'			utm_medium varchar(64) null,',
			'			description varchar(256) null,',
			'			constraint qa_utm_medium_medium_id_uindex',
			'				unique (medium_id),',
			'			constraint qa_utm_medium_utm_medium_uindex',
			'				unique (utm_medium)',
			"		) {$charset_collate}",
			'		;',
			'		',
			'		-- inital set',
			"		delete from {$wpdb->prefix}qa_utm_media;",
			"		alter table {$wpdb->prefix}qa_utm_media auto_increment = 1;",
			"		INSERT INTO {$wpdb->prefix}qa_utm_media (utm_medium, description) VALUES",
			"		('organic','Organic Search'),",
			"		('cpc','Paid Search'),",
			"		('ppc','Paid Search'),",
			"		('paidsearch','Paid Search'),",
			"		('display','Display'),",
			"		('cpm','Display'),",
			"		('banner','Display'),",
			"		('cpv','Other Advertising'),",
			"		('cpa','Other Advertising'),",
			"		('cpp','Other Advertising'),",
			"		('content-text','Other Advertising'),",
			"		('affiliate','Affiliate'),",
			"		('social','Social'),",
			"		('social-network','Social'),",
			"		('social-media','Social'),",
			"		('sm','Social'),",
			"		('social network','Social'),",
			"		('social media','Social'),",
			"		('email','Email')",
			'		;',

		);
		return $this->wrap_implode( "\n", $lines );
	}

	// qa_utm_contentテーブルの作成SQLを返す
	public function get_qa_utm_content_create_table() {

		global $wpdb;
		$charset_collate = '';

		// charsetを指定する
		if ( $wpdb->charset ) {
			$charset_collate = 'DEFAULT CHARACTER SET ' . $wpdb->charset;
		}

		// 照合順序を指定する（ある場合。通常デフォルトのutf8_general_ci）
		if ( $wpdb->collate ) {
			$charset_collate .= ' COLLATE ' . $wpdb->collate;
		}

		$lines = array(
			'		-- qa_utm_content',
			"		drop table if exists {$wpdb->prefix}qa_utm_content;",
			"		create table {$wpdb->prefix}qa_utm_content",
			'		(',
			'			content_id int auto_increment',
			'				primary key,',
			'			utm_content varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin null,',
			'			constraint qa_utm_content_content_id_uindex',
			'				unique (content_id),',
			'			constraint qa_utm_content_utm_content_uindex',
			'				unique (utm_content)',
			"		) {$charset_collate}",
			'		;',

		);
		return $this->wrap_implode( "\n", $lines );
	}

	// qa_readersテーブルの作成SQLを返す
	public function get_qa_utm_sources_create_table() {
		global $wpdb;
		$charset_collate = '';

		// charsetを指定する
		if ( $wpdb->charset ) {
			$charset_collate = 'DEFAULT CHARACTER SET ' . $wpdb->charset;
		}

		// 照合順序を指定する（ある場合。通常デフォルトのutf8_general_ci）
		if ( $wpdb->collate ) {
			$charset_collate .= ' COLLATE ' . $wpdb->collate;
		}

		$lines = array(
			'		-- qa_utm_sources',
			"		drop table if exists {$wpdb->prefix}qa_utm_sources;",
			"		create table {$wpdb->prefix}qa_utm_sources",
			'		(',
			'			source_id int auto_increment',
			'				primary key,',
			'			utm_source varchar(128) null,',
			'			referer text null,',
			'			source_domain varchar(128) null,',
			'			medium_id int null,',
			'			utm_term varchar(255) null,',
			'			keyword varchar(255) null,',
			'			constraint qa_sources_source_id_uindex',
			'				unique (source_id)',
			"		) {$charset_collate}",
			'		;',
			'		',
			'		create index qa_sources_souce_domain_index',
			"			on {$wpdb->prefix}qa_utm_sources (source_domain)",
			'		;',
			'		',
			"		delete from {$wpdb->prefix}qa_utm_sources;",
			"		alter table {$wpdb->prefix}qa_utm_sources auto_increment = 1;",
			"		INSERT INTO {$wpdb->prefix}qa_utm_sources (utm_source, source_domain,referer,medium_id) VALUES",
			'		(\'google\',\'www.google.com\',\'https://www.google.com/\',1),',
			'		(\'yahoo.co.jp\',\'search.yahoo.co.jp\',\'https://search.yahoo.co.jp/\',1),',
			'		(\'yahoo\',\'search.yahoo.com\',\'https://search.yahoo.com/\',1),',
			'		(\'bing\',\'www.bing.com\',\'https://www.bing.com/\',1),',
			'		(\'goo.ne.jp\',\'search.goo.ne.jp\',\'https://search.goo.ne.jp/\',1),',
			'		(\'google\',\'www.google.com\',\'https://www.google.com/\',2),',
			'		(\'twitter\',\'twitter.com\',\'https://twitter.com/\',13),',
			'		(\'twitter\',\'t.co\',\'https://t.co/\',13),',
			'		(\'facebook\',\'facebook.com\',\'https://facebook.com/\',13),',
			'		(\'instagram\',\'instagram.com\',\'https://instagram.com/\',13),',
			'		(\'google\',\'www.google.ac\',\'https://www.google.ac\',1),',
			'		(\'google\',\'www.google.ad\',\'https://www.google.ad\',1),',
			'		(\'google\',\'www.google.ae\',\'https://www.google.ae\',1),',
			'		(\'google\',\'www.google.com.af\',\'https://www.google.com.af\',1),',
			'		(\'google\',\'www.google.com.ag\',\'https://www.google.com.ag\',1),',
			'		(\'google\',\'www.google.off.ai\',\'https://www.google.off.ai\',1),',
			'		(\'google\',\'www.google.am\',\'https://www.google.am\',1),',
			'		(\'google\',\'www.google.co.ao\',\'https://www.google.co.ao\',1),',
			'		(\'google\',\'www.google.com.ar\',\'https://www.google.com.ar\',1),',
			'		(\'google\',\'www.google.as\',\'https://www.google.as\',1),',
			'		(\'google\',\'www.google.at\',\'https://www.google.at\',1),',
			'		(\'google\',\'www.google.com.au\',\'https://www.google.com.au\',1),',
			'		(\'google\',\'www.google.az\',\'https://www.google.az\',1),',
			'		(\'google\',\'www.google.ba\',\'https://www.google.ba\',1),',
			'		(\'google\',\'www.google.com.bd\',\'https://www.google.com.bd\',1),',
			'		(\'google\',\'www.google.be\',\'https://www.google.be\',1),',
			'		(\'google\',\'www.google.bg\',\'https://www.google.bg\',1),',
			'		(\'google\',\'www.google.com.bh\',\'https://www.google.com.bh\',1),',
			'		(\'google\',\'www.google.bi\',\'https://www.google.bi\',1),',
			'		(\'google\',\'www.google.bj\',\'https://www.google.bj\',1),',
			'		(\'google\',\'www.google.com.bn\',\'https://www.google.com.bn\',1),',
			'		(\'google\',\'www.google.com.bo\',\'https://www.google.com.bo\',1),',
			'		(\'google\',\'www.google.com.br\',\'https://www.google.com.br\',1),',
			'		(\'google\',\'www.google.bs\',\'https://www.google.bs\',1),',
			'		(\'google\',\'www.google.co.bw\',\'https://www.google.co.bw\',1),',
			'		(\'google\',\'www.google.com.by\',\'https://www.google.com.by\',1),',
			'		(\'google\',\'www.google.com.bz\',\'https://www.google.com.bz\',1),',
			'		(\'google\',\'www.google.ca\',\'https://www.google.ca\',1),',
			'		(\'google\',\'www.google.cd\',\'https://www.google.cd\',1),',
			'		(\'google\',\'www.google.cf/\',\'https://www.google.cf/\',1),',
			'		(\'google\',\'www.google.cg\',\'https://www.google.cg\',1),',
			'		(\'google\',\'www.google.ch\',\'https://www.google.ch\',1),',
			'		(\'google\',\'www.google.ci\',\'https://www.google.ci\',1),',
			'		(\'google\',\'www.google.co.ck\',\'https://www.google.co.ck\',1),',
			'		(\'google\',\'www.google.cl\',\'https://www.google.cl\',1),',
			'		(\'google\',\'www.google.cn\',\'https://www.google.cn\',1),',
			'		(\'google\',\'www.google.com.co\',\'https://www.google.com.co\',1),',
			'		(\'google\',\'www.google.co.cr\',\'https://www.google.co.cr\',1),',
			'		(\'google\',\'www.google.com.cu\',\'https://www.google.com.cu\',1),',
			'		(\'google\',\'www.google.com.cy\',\'https://www.google.com.cy\',1),',
			'		(\'google\',\'www.google.cz\',\'https://www.google.cz\',1),',
			'		(\'google\',\'www.google.de\',\'https://www.google.de\',1),',
			'		(\'google\',\'www.google.dj\',\'https://www.google.dj\',1),',
			'		(\'google\',\'www.google.dk\',\'https://www.google.dk\',1),',
			'		(\'google\',\'www.google.dm\',\'https://www.google.dm\',1),',
			'		(\'google\',\'www.google.com.do\',\'https://www.google.com.do\',1),',
			'		(\'google\',\'www.google.dz\',\'https://www.google.dz\',1),',
			'		(\'google\',\'www.google.com.ec\',\'https://www.google.com.ec\',1),',
			'		(\'google\',\'www.google.ee\',\'https://www.google.ee\',1),',
			'		(\'google\',\'www.google.com.eg\',\'https://www.google.com.eg\',1),',
			'		(\'google\',\'www.google.es\',\'https://www.google.es\',1),',
			'		(\'google\',\'www.google.com.et\',\'https://www.google.com.et\',1),',
			'		(\'google\',\'www.google.fi\',\'https://www.google.fi\',1),',
			'		(\'google\',\'www.google.com.fj\',\'https://www.google.com.fj\',1),',
			'		(\'google\',\'www.google.fm\',\'https://www.google.fm\',1),',
			'		(\'google\',\'www.google.fr\',\'https://www.google.fr\',1),',
			'		(\'google\',\'www.google.gd\',\'https://www.google.gd\',1),',
			'		(\'google\',\'www.google.ge\',\'https://www.google.ge\',1),',
			'		(\'google\',\'www.google.gf\',\'https://www.google.gf\',1),',
			'		(\'google\',\'www.google.gg\',\'https://www.google.gg\',1),',
			'		(\'google\',\'www.google.com.gh\',\'https://www.google.com.gh\',1),',
			'		(\'google\',\'www.google.com.gi\',\'https://www.google.com.gi\',1),',
			'		(\'google\',\'www.google.gl\',\'https://www.google.gl\',1),',
			'		(\'google\',\'www.google.gm\',\'https://www.google.gm\',1),',
			'		(\'google\',\'www.google.gp\',\'https://www.google.gp\',1),',
			'		(\'google\',\'www.google.gr\',\'https://www.google.gr\',1),',
			'		(\'google\',\'www.google.com.gt\',\'https://www.google.com.gt\',1),',
			'		(\'google\',\'www.google.gy\',\'https://www.google.gy\',1),',
			'		(\'google\',\'www.google.com.hk\',\'https://www.google.com.hk\',1),',
			'		(\'google\',\'www.google.hn\',\'https://www.google.hn\',1),',
			'		(\'google\',\'www.google.hr\',\'https://www.google.hr\',1),',
			'		(\'google\',\'www.google.ht\',\'https://www.google.ht\',1),',
			'		(\'google\',\'www.google.co.hu\',\'https://www.google.co.hu\',1),',
			'		(\'google\',\'www.google.co.id\',\'https://www.google.co.id\',1),',
			'		(\'google\',\'www.google.ie\',\'https://www.google.ie\',1),',
			'		(\'google\',\'www.google.co.il\',\'https://www.google.co.il\',1),',
			'		(\'google\',\'www.google.co.im\',\'https://www.google.co.im\',1),',
			'		(\'google\',\'www.google.co.in\',\'https://www.google.co.in\',1),',
			'		(\'google\',\'www.google.is\',\'https://www.google.is\',1),',
			'		(\'google\',\'www.google.it\',\'https://www.google.it\',1),',
			'		(\'google\',\'www.google.co.je\',\'https://www.google.co.je\',1),',
			'		(\'google\',\'www.google.com.jm\',\'https://www.google.com.jm\',1),',
			'		(\'google\',\'www.google.jo\',\'https://www.google.jo\',1),',
			'		(\'google\',\'www.google.co.jp\',\'https://www.google.co.jp\',1),',
			'		(\'google\',\'www.google.co.ke\',\'https://www.google.co.ke\',1),',
			'		(\'google\',\'www.google.kg\',\'https://www.google.kg\',1),',
			'		(\'google\',\'www.google.com.kh/\',\'https://www.google.com.kh/\',1),',
			'		(\'google\',\'www.google.ki\',\'https://www.google.ki\',1),',
			'		(\'google\',\'www.google.co.kr\',\'https://www.google.co.kr\',1),',
			'		(\'google\',\'www.google.com.kw\',\'https://www.google.com.kw\',1),',
			'		(\'google\',\'www.google.kz\',\'https://www.google.kz\',1),',
			'		(\'google\',\'www.google.la\',\'https://www.google.la\',1),',
			'		(\'google\',\'www.google.com.lb\',\'https://www.google.com.lb\',1),',
			'		(\'google\',\'www.google.com.lc\',\'https://www.google.com.lc\',1),',
			'		(\'google\',\'www.google.li\',\'https://www.google.li\',1),',
			'		(\'google\',\'www.google.lk\',\'https://www.google.lk\',1),',
			'		(\'google\',\'www.google.co.ls\',\'https://www.google.co.ls\',1),',
			'		(\'google\',\'www.google.lt\',\'https://www.google.lt\',1),',
			'		(\'google\',\'www.google.lu\',\'https://www.google.lu\',1),',
			'		(\'google\',\'www.google.lv\',\'https://www.google.lv\',1),',
			'		(\'google\',\'www.google.com.ly\',\'https://www.google.com.ly\',1),',
			'		(\'google\',\'www.google.co.ma\',\'https://www.google.co.ma\',1),',
			'		(\'google\',\'www.google.md\',\'https://www.google.md\',1),',
			'		(\'google\',\'www.google.me\',\'https://www.google.me\',1),',
			'		(\'google\',\'www.google.mg\',\'https://www.google.mg\',1),',
			'		(\'google\',\'www.google.com.mk\',\'https://www.google.com.mk\',1),',
			'		(\'google\',\'www.google.mn\',\'https://www.google.mn\',1),',
			'		(\'google\',\'www.google.ms\',\'https://www.google.ms\',1),',
			'		(\'google\',\'www.google.com.mt\',\'https://www.google.com.mt\',1),',
			'		(\'google\',\'www.google.mu\',\'https://www.google.mu\',1),',
			'		(\'google\',\'www.google.mv\',\'https://www.google.mv\',1),',
			'		(\'google\',\'www.google.mw\',\'https://www.google.mw\',1),',
			'		(\'google\',\'www.google.com.mx\',\'https://www.google.com.mx\',1),',
			'		(\'google\',\'www.google.com.my\',\'https://www.google.com.my\',1),',
			'		(\'google\',\'www.google.co.mz\',\'https://www.google.co.mz\',1),',
			'		(\'google\',\'www.google.com.na\',\'https://www.google.com.na\',1),',
			'		(\'google\',\'www.google.com.nf\',\'https://www.google.com.nf\',1),',
			'		(\'google\',\'www.google.com.ng\',\'https://www.google.com.ng\',1),',
			'		(\'google\',\'www.google.com.ni\',\'https://www.google.com.ni\',1),',
			'		(\'google\',\'www.google.nl\',\'https://www.google.nl\',1),',
			'		(\'google\',\'www.google.no\',\'https://www.google.no\',1),',
			'		(\'google\',\'www.google.com.np\',\'https://www.google.com.np\',1),',
			'		(\'google\',\'www.google.nr\',\'https://www.google.nr\',1),',
			'		(\'google\',\'www.google.nu\',\'https://www.google.nu\',1),',
			'		(\'google\',\'www.google.co.nz\',\'https://www.google.co.nz\',1),',
			'		(\'google\',\'www.google.com.om\',\'https://www.google.com.om\',1),',
			'		(\'google\',\'www.google.com.pa\',\'https://www.google.com.pa\',1),',
			'		(\'google\',\'www.google.com.pe\',\'https://www.google.com.pe\',1),',
			'		(\'google\',\'www.google.com.ph\',\'https://www.google.com.ph\',1),',
			'		(\'google\',\'www.google.com.pk\',\'https://www.google.com.pk\',1),',
			'		(\'google\',\'www.google.pl\',\'https://www.google.pl\',1),',
			'		(\'google\',\'www.google.pn\',\'https://www.google.pn\',1),',
			'		(\'google\',\'www.google.com.pr\',\'https://www.google.com.pr\',1),',
			'		(\'google\',\'www.google.ps/\',\'https://www.google.ps/\',1),',
			'		(\'google\',\'www.google.pt\',\'https://www.google.pt\',1),',
			'		(\'google\',\'www.google.com.py\',\'https://www.google.com.py\',1),',
			'		(\'google\',\'www.google.com.qa\',\'https://www.google.com.qa\',1),',
			'		(\'google\',\'www.google.ro\',\'https://www.google.ro\',1),',
			'		(\'google\',\'www.google.rs\',\'https://www.google.rs\',1),',
			'		(\'google\',\'www.google.ru\',\'https://www.google.ru\',1),',
			'		(\'google\',\'www.google.rw\',\'https://www.google.rw\',1),',
			'		(\'google\',\'www.google.com.sa\',\'https://www.google.com.sa\',1),',
			'		(\'google\',\'www.google.com.sb\',\'https://www.google.com.sb\',1),',
			'		(\'google\',\'www.google.sc\',\'https://www.google.sc\',1),',
			'		(\'google\',\'www.google.se\',\'https://www.google.se\',1),',
			'		(\'google\',\'www.google.com.sg\',\'https://www.google.com.sg\',1),',
			'		(\'google\',\'www.google.sh\',\'https://www.google.sh\',1),',
			'		(\'google\',\'www.google.si\',\'https://www.google.si\',1),',
			'		(\'google\',\'www.google.sk\',\'https://www.google.sk\',1),',
			'		(\'google\',\'www.google.com.sl\',\'https://www.google.com.sl\',1),',
			'		(\'google\',\'www.google.sm\',\'https://www.google.sm\',1),',
			'		(\'google\',\'www.google.sn\',\'https://www.google.sn\',1),',
			'		(\'google\',\'www.google.st\',\'https://www.google.st\',1),',
			'		(\'google\',\'www.google.com.sv\',\'https://www.google.com.sv\',1),',
			'		(\'google\',\'www.google.co.th\',\'https://www.google.co.th\',1),',
			'		(\'google\',\'www.google.com.tj\',\'https://www.google.com.tj\',1),',
			'		(\'google\',\'www.google.tk\',\'https://www.google.tk\',1),',
			'		(\'google\',\'www.google.tm\',\'https://www.google.tm\',1),',
			'		(\'google\',\'www.google.to\',\'https://www.google.to\',1),',
			'		(\'google\',\'www.google.tp\',\'https://www.google.tp\',1),',
			'		(\'google\',\'www.google.com.tr\',\'https://www.google.com.tr\',1),',
			'		(\'google\',\'www.google.tt\',\'https://www.google.tt\',1),',
			'		(\'google\',\'www.google.com.tw\',\'https://www.google.com.tw\',1),',
			'		(\'google\',\'www.google.co.tz\',\'https://www.google.co.tz\',1),',
			'		(\'google\',\'www.google.com.ua\',\'https://www.google.com.ua\',1),',
			'		(\'google\',\'www.google.co.ug\',\'https://www.google.co.ug\',1),',
			'		(\'google\',\'www.google.co.uk\',\'https://www.google.co.uk\',1),',
			'		(\'google\',\'www.google.com.uy\',\'https://www.google.com.uy\',1),',
			'		(\'google\',\'www.google.co.uz\',\'https://www.google.co.uz\',1),',
			'		(\'google\',\'www.google.com.vc\',\'https://www.google.com.vc\',1),',
			'		(\'google\',\'www.google.co.ve\',\'https://www.google.co.ve\',1),',
			'		(\'google\',\'www.google.vg\',\'https://www.google.vg\',1),',
			'		(\'google\',\'www.google.co.vi\',\'https://www.google.co.vi\',1),',
			'		(\'google\',\'www.google.com.vn\',\'https://www.google.com.vn\',1),',
			'		(\'google\',\'www.google.vu\',\'https://www.google.vu\',1),',
			'		(\'google\',\'www.google.ws\',\'https://www.google.ws\',1),',
			'		(\'google\',\'www.google.co.za\',\'https://www.google.co.za\',1),',
			'		(\'google\',\'www.google.co.zm\',\'https://www.google.co.zm\',1),',
			'		(\'google\',\'www.google.co.zw\',\'https://www.google.co.zw\',1)',
			'		;',

		);

		return $this->wrap_implode( "\n", $lines );
	}



	// qa_readersテーブルの作成SQLを返す
	public function get_qa_utm_campaigns_create_table() {
		global $wpdb;
		$charset_collate = '';

		// charsetを指定する
		if ( $wpdb->charset ) {
			$charset_collate = 'DEFAULT CHARACTER SET ' . $wpdb->charset;
		}

		// 照合順序を指定する（ある場合。通常デフォルトのutf8_general_ci）
		if ( $wpdb->collate ) {
			$charset_collate .= ' COLLATE ' . $wpdb->collate;
		}

		$lines = array(
			'		-- qa_utm_campaigns',
			"		drop table if exists {$wpdb->prefix}qa_utm_campaigns;",
			"		create table {$wpdb->prefix}qa_utm_campaigns",
			'		(',
			'			campaign_id int auto_increment',
			'				primary key,',
			'			utm_campaign varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin null,',
			'			constraint qa_utm_campaigns_campaign_id_uindex',
			'				unique (campaign_id),',
			'			constraint qa_utm_campaigns_utm_campaign_uindex',
			'				unique (utm_campaign)',
			"		) {$charset_collate}",
			'		;',

		);

		return $this->wrap_implode( "\n", $lines );
	}



	// qa_readersテーブルの作成SQLを返す
	public function get_qa_pv_log_create_table() {
		global $wpdb;
		$charset_collate = '';

		// charsetを指定する
		if ( $wpdb->charset ) {
			$charset_collate = 'DEFAULT CHARACTER SET ' . $wpdb->charset;
		}

		// 照合順序を指定する（ある場合。通常デフォルトのutf8_general_ci）
		if ( $wpdb->collate ) {
			$charset_collate .= ' COLLATE ' . $wpdb->collate;
		}

		$lines = array(
			'		-- qa_pv_log',
			"		drop table if exists {$wpdb->prefix}qa_pv_log;",
			"		create table {$wpdb->prefix}qa_pv_log",
			'		(',
			'			pv_id int auto_increment,',
			'			reader_id int null,',
			'			page_id int null,',
			'			device_id tinyint null,',
			'			source_id int null,',
			'			medium_id int null,',
			'			campaign_id int null,',
			'			content_id int null,',
			'			session_no tinyint null,',
			'			access_time datetime default CURRENT_TIMESTAMP not null,',
			'			pv smallint(6) null,',
			'			speed_msec smallint(5) unsigned null,',
			'			browse_sec smallint(5) unsigned null,',
			'			is_last tinyint(1) null,',
			'			is_newuser tinyint(1) null,',
			'			is_cv_session tinyint(1) null,',
			'			flag_bit int null,',
			'			version_id mediumint null,',
			'			is_raw_p tinyint null,',
			'			is_raw_c tinyint null,',
			'			is_raw_e tinyint null,',
			'			primary key (pv_id, access_time),',
			'			constraint uniq_access',
			'			unique (reader_id, access_time)',
			"		) {$charset_collate} ",
			'		partition by range COLUMNS(access_time) (',
			'			partition  p202001 values less than (\'2020-02-01 00:00:00\'),',
			'			partition  p202002 values less than (\'2020-03-01 00:00:00\'),',
			'			partition  p202003 values less than (\'2020-04-01 00:00:00\'),',
			'			partition  p202004 values less than (\'2020-05-01 00:00:00\'),',
			'			partition  p202005 values less than (\'2020-06-01 00:00:00\'),',
			'			partition  p202006 values less than (\'2020-07-01 00:00:00\'),',
			'			partition  p202007 values less than (\'2020-08-01 00:00:00\'),',
			'			partition  p202008 values less than (\'2020-09-01 00:00:00\'),',
			'			partition  p202009 values less than (\'2020-10-01 00:00:00\'),',
			'			partition  p202010 values less than (\'2020-11-01 00:00:00\'),',
			'			partition  p202011 values less than (\'2020-12-01 00:00:00\'),',
			'			partition  p202012 values less than (\'2021-01-01 00:00:00\'),',
			'			partition  p202101 values less than (\'2021-02-01 00:00:00\'),',
			'			partition  p202102 values less than (\'2021-03-01 00:00:00\'),',
			'			partition  p202103 values less than (\'2021-04-01 00:00:00\'),',
			'			partition  p202104 values less than (\'2021-05-01 00:00:00\'),',
			'			partition  p202105 values less than (\'2021-06-01 00:00:00\'),',
			'			partition  p202106 values less than (\'2021-07-01 00:00:00\'),',
			'			partition  p202107 values less than (\'2021-08-01 00:00:00\'),',
			'			partition  p202108 values less than (\'2021-09-01 00:00:00\'),',
			'			partition  p202109 values less than (\'2021-10-01 00:00:00\'),',
			'			partition  p202110 values less than (\'2021-11-01 00:00:00\'),',
			'			partition  p202111 values less than (\'2021-12-01 00:00:00\'),',
			'			partition  p202112 values less than (\'2022-01-01 00:00:00\'),',
			'			partition  p202201 values less than (\'2022-02-01 00:00:00\'),',
			'			partition  p202202 values less than (\'2022-03-01 00:00:00\'),',
			'			partition  p202203 values less than (\'2022-04-01 00:00:00\'),',
			'			partition  p202204 values less than (\'2022-05-01 00:00:00\'),',
			'			partition  p202205 values less than (\'2022-06-01 00:00:00\'),',
			'			partition  p202206 values less than (\'2022-07-01 00:00:00\'),',
			'			partition  p202207 values less than (\'2022-08-01 00:00:00\'),',
			'			partition  p202208 values less than (\'2022-09-01 00:00:00\'),',
			'			partition  p202209 values less than (\'2022-10-01 00:00:00\'),',
			'			partition  p202210 values less than (\'2022-11-01 00:00:00\'),',
			'			partition  p202211 values less than (\'2022-12-01 00:00:00\'),',
			'			partition  p202212 values less than (\'2023-01-01 00:00:00\'),',
			'			partition  p202301 values less than (\'2023-02-01 00:00:00\'),',
			'			partition  p202302 values less than (\'2023-03-01 00:00:00\'),',
			'			partition  p202303 values less than (\'2023-04-01 00:00:00\'),',
			'			partition  p202304 values less than (\'2023-05-01 00:00:00\'),',
			'			partition  p202305 values less than (\'2023-06-01 00:00:00\'),',
			'			partition  p202306 values less than (\'2023-07-01 00:00:00\'),',
			'			partition  p202307 values less than (\'2023-08-01 00:00:00\'),',
			'			partition  p202308 values less than (\'2023-09-01 00:00:00\'),',
			'			partition  p202309 values less than (\'2023-10-01 00:00:00\'),',
			'			partition  p202310 values less than (\'2023-11-01 00:00:00\'),',
			'			partition  p202311 values less than (\'2023-12-01 00:00:00\'),',
			'			partition  p202312 values less than (\'2024-01-01 00:00:00\'),',
			'			partition  p202401 values less than (\'2024-02-01 00:00:00\'),',
			'			partition  p202402 values less than (\'2024-03-01 00:00:00\'),',
			'			partition  p202403 values less than (\'2024-04-01 00:00:00\'),',
			'			partition  p202404 values less than (\'2024-05-01 00:00:00\'),',
			'			partition  p202405 values less than (\'2024-06-01 00:00:00\'),',
			'			partition  p202406 values less than (\'2024-07-01 00:00:00\'),',
			'			partition  p202407 values less than (\'2024-08-01 00:00:00\'),',
			'			partition  p202408 values less than (\'2024-09-01 00:00:00\'),',
			'			partition  p202409 values less than (\'2024-10-01 00:00:00\'),',
			'			partition  p202410 values less than (\'2024-11-01 00:00:00\'),',
			'			partition  p202411 values less than (\'2024-12-01 00:00:00\'),',
			'			partition  p202412 values less than (\'2025-01-01 00:00:00\'),',
			'			partition  p202501 values less than (\'2025-02-01 00:00:00\'),',
			'			partition  p202502 values less than (\'2025-03-01 00:00:00\'),',
			'			partition  p202503 values less than (\'2025-04-01 00:00:00\'),',
			'			partition  p202504 values less than (\'2025-05-01 00:00:00\'),',
			'			partition  p202505 values less than (\'2025-06-01 00:00:00\'),',
			'			partition  p202506 values less than (\'2025-07-01 00:00:00\'),',
			'			partition  p202507 values less than (\'2025-08-01 00:00:00\'),',
			'			partition  p202508 values less than (\'2025-09-01 00:00:00\'),',
			'			partition  p202509 values less than (\'2025-10-01 00:00:00\'),',
			'			partition  p202510 values less than (\'2025-11-01 00:00:00\'),',
			'			partition  p202511 values less than (\'2025-12-01 00:00:00\'),',
			'			partition  p202512 values less than (\'2026-01-01 00:00:00\'),',
			'			partition  p202601 values less than (\'2026-02-01 00:00:00\'),',
			'			partition  p202602 values less than (\'2026-03-01 00:00:00\'),',
			'			partition  p202603 values less than (\'2026-04-01 00:00:00\'),',
			'			partition  p202604 values less than (\'2026-05-01 00:00:00\'),',
			'			partition  p202605 values less than (\'2026-06-01 00:00:00\'),',
			'			partition  p202606 values less than (\'2026-07-01 00:00:00\'),',
			'			partition  p202607 values less than (\'2026-08-01 00:00:00\'),',
			'			partition  p202608 values less than (\'2026-09-01 00:00:00\'),',
			'			partition  p202609 values less than (\'2026-10-01 00:00:00\'),',
			'			partition  p202610 values less than (\'2026-11-01 00:00:00\'),',
			'			partition  p202611 values less than (\'2026-12-01 00:00:00\'),',
			'			partition  p202612 values less than (\'2027-01-01 00:00:00\'),',
			'			partition  p202701 values less than (\'2027-02-01 00:00:00\'),',
			'			partition  p202702 values less than (\'2027-03-01 00:00:00\'),',
			'			partition  p202703 values less than (\'2027-04-01 00:00:00\'),',
			'			partition  p202704 values less than (\'2027-05-01 00:00:00\'),',
			'			partition  p202705 values less than (\'2027-06-01 00:00:00\'),',
			'			partition  p202706 values less than (\'2027-07-01 00:00:00\'),',
			'			partition  p202707 values less than (\'2027-08-01 00:00:00\'),',
			'			partition  p202708 values less than (\'2027-09-01 00:00:00\'),',
			'			partition  p202709 values less than (\'2027-10-01 00:00:00\'),',
			'			partition  p202710 values less than (\'2027-11-01 00:00:00\'),',
			'			partition  p202711 values less than (\'2027-12-01 00:00:00\'),',
			'			partition  p202712 values less than (\'2028-01-01 00:00:00\'),',
			'			partition  p202801 values less than (\'2028-02-01 00:00:00\'),',
			'			partition  p202802 values less than (\'2028-03-01 00:00:00\'),',
			'			partition  p202803 values less than (\'2028-04-01 00:00:00\'),',
			'			partition  p202804 values less than (\'2028-05-01 00:00:00\'),',
			'			partition  p202805 values less than (\'2028-06-01 00:00:00\'),',
			'			partition  p202806 values less than (\'2028-07-01 00:00:00\'),',
			'			partition  p202807 values less than (\'2028-08-01 00:00:00\'),',
			'			partition  p202808 values less than (\'2028-09-01 00:00:00\'),',
			'			partition  p202809 values less than (\'2028-10-01 00:00:00\'),',
			'			partition  p202810 values less than (\'2028-11-01 00:00:00\'),',
			'			partition  p202811 values less than (\'2028-12-01 00:00:00\'),',
			'			partition  p202812 values less than (\'2029-01-01 00:00:00\'),',
			'			partition  p202901 values less than (\'2029-02-01 00:00:00\'),',
			'			partition  p202902 values less than (\'2029-03-01 00:00:00\'),',
			'			partition  p202903 values less than (\'2029-04-01 00:00:00\'),',
			'			partition  p202904 values less than (\'2029-05-01 00:00:00\'),',
			'			partition  p202905 values less than (\'2029-06-01 00:00:00\'),',
			'			partition  p202906 values less than (\'2029-07-01 00:00:00\'),',
			'			partition  p202907 values less than (\'2029-08-01 00:00:00\'),',
			'			partition  p202908 values less than (\'2029-09-01 00:00:00\'),',
			'			partition  p202909 values less than (\'2029-10-01 00:00:00\'),',
			'			partition  p202910 values less than (\'2029-11-01 00:00:00\'),',
			'			partition  p202911 values less than (\'2029-12-01 00:00:00\'),',
			'			partition  p202912 values less than (\'2030-01-01 00:00:00\'),',
			'			partition  p203001 values less than (\'2030-02-01 00:00:00\'),',
			'			partition  p203002 values less than (\'2030-03-01 00:00:00\'),',
			'			partition  p203003 values less than (\'2030-04-01 00:00:00\'),',
			'			partition  p203004 values less than (\'2030-05-01 00:00:00\'),',
			'			partition  p203005 values less than (\'2030-06-01 00:00:00\'),',
			'			partition  p203006 values less than (\'2030-07-01 00:00:00\'),',
			'			partition  p203007 values less than (\'2030-08-01 00:00:00\'),',
			'			partition  p203008 values less than (\'2030-09-01 00:00:00\'),',
			'			partition  p203009 values less than (\'2030-10-01 00:00:00\'),',
			'			partition  p203010 values less than (\'2030-11-01 00:00:00\'),',
			'			partition  p203011 values less than (\'2030-12-01 00:00:00\'),',
			'			partition  p203012 values less than (\'2031-01-01 00:00:00\'),',
			'			partition  p203101 values less than (\'2031-02-01 00:00:00\'),',
			'			partition  p203102 values less than (\'2031-03-01 00:00:00\'),',
			'			partition  p203103 values less than (\'2031-04-01 00:00:00\'),',
			'			partition  p203104 values less than (\'2031-05-01 00:00:00\'),',
			'			partition  p203105 values less than (\'2031-06-01 00:00:00\'),',
			'			partition  p203106 values less than (\'2031-07-01 00:00:00\'),',
			'			partition  p203107 values less than (\'2031-08-01 00:00:00\'),',
			'			partition  p203108 values less than (\'2031-09-01 00:00:00\'),',
			'			partition  p203109 values less than (\'2031-10-01 00:00:00\'),',
			'			partition  p203110 values less than (\'2031-11-01 00:00:00\'),',
			'			partition  p203111 values less than (\'2031-12-01 00:00:00\'),',
			'			partition  p203112 values less than (\'2032-01-01 00:00:00\'),',
			'			partition  p203201 values less than (\'2032-02-01 00:00:00\'),',
			'			partition  p203202 values less than (\'2032-03-01 00:00:00\'),',
			'			partition  p203203 values less than (\'2032-04-01 00:00:00\'),',
			'			partition  p203204 values less than (\'2032-05-01 00:00:00\'),',
			'			partition  p203205 values less than (\'2032-06-01 00:00:00\'),',
			'			partition  p203206 values less than (\'2032-07-01 00:00:00\'),',
			'			partition  p203207 values less than (\'2032-08-01 00:00:00\'),',
			'			partition  p203208 values less than (\'2032-09-01 00:00:00\'),',
			'			partition  p203209 values less than (\'2032-10-01 00:00:00\'),',
			'			partition  p203210 values less than (\'2032-11-01 00:00:00\'),',
			'			partition  p203211 values less than (\'2032-12-01 00:00:00\'),',
			'			partition  p203212 values less than (\'2033-01-01 00:00:00\'),',
			'			partition  p203301 values less than (\'2033-02-01 00:00:00\'),',
			'			partition  p203302 values less than (\'2033-03-01 00:00:00\'),',
			'			partition  p203303 values less than (\'2033-04-01 00:00:00\'),',
			'			partition  p203304 values less than (\'2033-05-01 00:00:00\'),',
			'			partition  p203305 values less than (\'2033-06-01 00:00:00\'),',
			'			partition  p203306 values less than (\'2033-07-01 00:00:00\'),',
			'			partition  p203307 values less than (\'2033-08-01 00:00:00\'),',
			'			partition  p203308 values less than (\'2033-09-01 00:00:00\'),',
			'			partition  p203309 values less than (\'2033-10-01 00:00:00\'),',
			'			partition  p203310 values less than (\'2033-11-01 00:00:00\'),',
			'			partition  p203311 values less than (\'2033-12-01 00:00:00\'),',
			'			partition  p203312 values less than (\'2034-01-01 00:00:00\'),',
			'			partition  p203401 values less than (\'2034-02-01 00:00:00\'),',
			'			partition  p203402 values less than (\'2034-03-01 00:00:00\'),',
			'			partition  p203403 values less than (\'2034-04-01 00:00:00\'),',
			'			partition  p203404 values less than (\'2034-05-01 00:00:00\'),',
			'			partition  p203405 values less than (\'2034-06-01 00:00:00\'),',
			'			partition  p203406 values less than (\'2034-07-01 00:00:00\'),',
			'			partition  p203407 values less than (\'2034-08-01 00:00:00\'),',
			'			partition  p203408 values less than (\'2034-09-01 00:00:00\'),',
			'			partition  p203409 values less than (\'2034-10-01 00:00:00\'),',
			'			partition  p203410 values less than (\'2034-11-01 00:00:00\'),',
			'			partition  p203411 values less than (\'2034-12-01 00:00:00\'),',
			'			partition  p203412 values less than (\'2035-01-01 00:00:00\'),',
			'			partition  p203501 values less than (\'2035-02-01 00:00:00\'),',
			'			partition  p203502 values less than (\'2035-03-01 00:00:00\'),',
			'			partition  p203503 values less than (\'2035-04-01 00:00:00\'),',
			'			partition  p203504 values less than (\'2035-05-01 00:00:00\'),',
			'			partition  p203505 values less than (\'2035-06-01 00:00:00\'),',
			'			partition  p203506 values less than (\'2035-07-01 00:00:00\'),',
			'			partition  p203507 values less than (\'2035-08-01 00:00:00\'),',
			'			partition  p203508 values less than (\'2035-09-01 00:00:00\'),',
			'			partition  p203509 values less than (\'2035-10-01 00:00:00\'),',
			'			partition  p203510 values less than (\'2035-11-01 00:00:00\'),',
			'			partition  p203511 values less than (\'2035-12-01 00:00:00\'),',
			'			partition  p203512 values less than (\'2036-01-01 00:00:00\'),',
			'			partition  p203601 values less than (\'2036-02-01 00:00:00\'),',
			'			partition  p203602 values less than (\'2036-03-01 00:00:00\'),',
			'			partition  p203603 values less than (\'2036-04-01 00:00:00\'),',
			'			partition  p203604 values less than (\'2036-05-01 00:00:00\'),',
			'			partition  p203605 values less than (\'2036-06-01 00:00:00\'),',
			'			partition  p203606 values less than (\'2036-07-01 00:00:00\'),',
			'			partition  p203607 values less than (\'2036-08-01 00:00:00\'),',
			'			partition  p203608 values less than (\'2036-09-01 00:00:00\'),',
			'			partition  p203609 values less than (\'2036-10-01 00:00:00\'),',
			'			partition  p203610 values less than (\'2036-11-01 00:00:00\'),',
			'			partition  p203611 values less than (\'2036-12-01 00:00:00\'),',
			'			partition  p203612 values less than (\'2037-01-01 00:00:00\'),',
			'			partition  p203701 values less than (\'2037-02-01 00:00:00\'),',
			'			partition  p203702 values less than (\'2037-03-01 00:00:00\'),',
			'			partition  p203703 values less than (\'2037-04-01 00:00:00\'),',
			'			partition  p203704 values less than (\'2037-05-01 00:00:00\'),',
			'			partition  p203705 values less than (\'2037-06-01 00:00:00\'),',
			'			partition  p203706 values less than (\'2037-07-01 00:00:00\'),',
			'			partition  p203707 values less than (\'2037-08-01 00:00:00\'),',
			'			partition  p203708 values less than (\'2037-09-01 00:00:00\'),',
			'			partition  p203709 values less than (\'2037-10-01 00:00:00\'),',
			'			partition  p203710 values less than (\'2037-11-01 00:00:00\'),',
			'			partition  p203711 values less than (\'2037-12-01 00:00:00\'),',
			'			partition  p203712 values less than (\'2038-01-01 00:00:00\'),',
			'		PARTITION pmax VALUES LESS THAN (MAXVALUE)',
			'		);',
			'		',
			'		create index qa_pv_log_is_cv_session_index',
			"			on {$wpdb->prefix}qa_pv_log (is_cv_session)",
			'		;',
			'		',
			'		create index qa_pv_log_reader_id_index',
			"			on {$wpdb->prefix}qa_pv_log (reader_id)",
			'		;',
			'		',
			'		create index qa_pv_log_source_id_index',
			"			on {$wpdb->prefix}qa_pv_log (source_id)",
			'		;',
			'		CREATE index qa_pv_log_version_id_index',
			"			on {$wpdb->prefix}qa_pv_log (version_id)",
			'		;',

		);

		return $this->wrap_implode( "\n", $lines );
	}


	// qa_readersテーブルの作成SQLを返す
	public function get_qa_search_log_create_table() {
		global $wpdb;
		$charset_collate = '';

		// charsetを指定する
		if ( $wpdb->charset ) {
			$charset_collate = 'DEFAULT CHARACTER SET ' . $wpdb->charset;
		}

		// 照合順序を指定する（ある場合。通常デフォルトのutf8_general_ci）
		if ( $wpdb->collate ) {
			$charset_collate .= ' COLLATE ' . $wpdb->collate;
		}

		$lines = array(
			'		-- qa_search_log',
			"		drop table if exists {$wpdb->prefix}qa_search_log;",
			"		create table {$wpdb->prefix}qa_search_log",
			'		(',
			'			pv_id int null,',
			'			query varchar(128) null',
			"		) {$charset_collate}",
			'		;',

		);

		return $this->wrap_implode( "\n", $lines );
	}



	// qa_readersテーブルの作成SQLを返す
	public function get_qa_page_version_hist_create_table() {
		global $wpdb;
		$charset_collate = '';

		// charsetを指定する
		if ( $wpdb->charset ) {
			$charset_collate = 'DEFAULT CHARACTER SET ' . $wpdb->charset;
		}

		// 照合順序を指定する（ある場合。通常デフォルトのutf8_general_ci）
		if ( $wpdb->collate ) {
			$charset_collate .= ' COLLATE ' . $wpdb->collate;
		}

		$lines = array(
			'		-- qa_page_version_hist',
			"		drop table if exists {$wpdb->prefix}qa_page_version_hist;",
			"		create table {$wpdb->prefix}qa_page_version_hist",
			'		(',
			'			version_id mediumint auto_increment,',
			'			page_id int null,',
			'			device_id tinyint null,',
			'			version_no smallint(6) null,',
			'			base_html longtext null,',
			'			base_selector mediumtext null,',
			'			update_date date not null,',
			'			insert_datetime datetime null,',
			'			primary key (version_id, update_date)',
			"		) {$charset_collate} ",
			'		partition by range COLUMNS(update_date) (',
			'			partition  p202001 values less than (\'2020-02-01\'),',
			'			partition  p202002 values less than (\'2020-03-01\'),',
			'			partition  p202003 values less than (\'2020-04-01\'),',
			'			partition  p202004 values less than (\'2020-05-01\'),',
			'			partition  p202005 values less than (\'2020-06-01\'),',
			'			partition  p202006 values less than (\'2020-07-01\'),',
			'			partition  p202007 values less than (\'2020-08-01\'),',
			'			partition  p202008 values less than (\'2020-09-01\'),',
			'			partition  p202009 values less than (\'2020-10-01\'),',
			'			partition  p202010 values less than (\'2020-11-01\'),',
			'			partition  p202011 values less than (\'2020-12-01\'),',
			'			partition  p202012 values less than (\'2021-01-01\'),',
			'			partition  p202101 values less than (\'2021-02-01\'),',
			'			partition  p202102 values less than (\'2021-03-01\'),',
			'			partition  p202103 values less than (\'2021-04-01\'),',
			'			partition  p202104 values less than (\'2021-05-01\'),',
			'			partition  p202105 values less than (\'2021-06-01\'),',
			'			partition  p202106 values less than (\'2021-07-01\'),',
			'			partition  p202107 values less than (\'2021-08-01\'),',
			'			partition  p202108 values less than (\'2021-09-01\'),',
			'			partition  p202109 values less than (\'2021-10-01\'),',
			'			partition  p202110 values less than (\'2021-11-01\'),',
			'			partition  p202111 values less than (\'2021-12-01\'),',
			'			partition  p202112 values less than (\'2022-01-01\'),',
			'			partition  p202201 values less than (\'2022-02-01\'),',
			'			partition  p202202 values less than (\'2022-03-01\'),',
			'			partition  p202203 values less than (\'2022-04-01\'),',
			'			partition  p202204 values less than (\'2022-05-01\'),',
			'			partition  p202205 values less than (\'2022-06-01\'),',
			'			partition  p202206 values less than (\'2022-07-01\'),',
			'			partition  p202207 values less than (\'2022-08-01\'),',
			'			partition  p202208 values less than (\'2022-09-01\'),',
			'			partition  p202209 values less than (\'2022-10-01\'),',
			'			partition  p202210 values less than (\'2022-11-01\'),',
			'			partition  p202211 values less than (\'2022-12-01\'),',
			'			partition  p202212 values less than (\'2023-01-01\'),',
			'			partition  p202301 values less than (\'2023-02-01\'),',
			'			partition  p202302 values less than (\'2023-03-01\'),',
			'			partition  p202303 values less than (\'2023-04-01\'),',
			'			partition  p202304 values less than (\'2023-05-01\'),',
			'			partition  p202305 values less than (\'2023-06-01\'),',
			'			partition  p202306 values less than (\'2023-07-01\'),',
			'			partition  p202307 values less than (\'2023-08-01\'),',
			'			partition  p202308 values less than (\'2023-09-01\'),',
			'			partition  p202309 values less than (\'2023-10-01\'),',
			'			partition  p202310 values less than (\'2023-11-01\'),',
			'			partition  p202311 values less than (\'2023-12-01\'),',
			'			partition  p202312 values less than (\'2024-01-01\'),',
			'			partition  p202401 values less than (\'2024-02-01\'),',
			'			partition  p202402 values less than (\'2024-03-01\'),',
			'			partition  p202403 values less than (\'2024-04-01\'),',
			'			partition  p202404 values less than (\'2024-05-01\'),',
			'			partition  p202405 values less than (\'2024-06-01\'),',
			'			partition  p202406 values less than (\'2024-07-01\'),',
			'			partition  p202407 values less than (\'2024-08-01\'),',
			'			partition  p202408 values less than (\'2024-09-01\'),',
			'			partition  p202409 values less than (\'2024-10-01\'),',
			'			partition  p202410 values less than (\'2024-11-01\'),',
			'			partition  p202411 values less than (\'2024-12-01\'),',
			'			partition  p202412 values less than (\'2025-01-01\'),',
			'			partition  p202501 values less than (\'2025-02-01\'),',
			'			partition  p202502 values less than (\'2025-03-01\'),',
			'			partition  p202503 values less than (\'2025-04-01\'),',
			'			partition  p202504 values less than (\'2025-05-01\'),',
			'			partition  p202505 values less than (\'2025-06-01\'),',
			'			partition  p202506 values less than (\'2025-07-01\'),',
			'			partition  p202507 values less than (\'2025-08-01\'),',
			'			partition  p202508 values less than (\'2025-09-01\'),',
			'			partition  p202509 values less than (\'2025-10-01\'),',
			'			partition  p202510 values less than (\'2025-11-01\'),',
			'			partition  p202511 values less than (\'2025-12-01\'),',
			'			partition  p202512 values less than (\'2026-01-01\'),',
			'			partition  p202601 values less than (\'2026-02-01\'),',
			'			partition  p202602 values less than (\'2026-03-01\'),',
			'			partition  p202603 values less than (\'2026-04-01\'),',
			'			partition  p202604 values less than (\'2026-05-01\'),',
			'			partition  p202605 values less than (\'2026-06-01\'),',
			'			partition  p202606 values less than (\'2026-07-01\'),',
			'			partition  p202607 values less than (\'2026-08-01\'),',
			'			partition  p202608 values less than (\'2026-09-01\'),',
			'			partition  p202609 values less than (\'2026-10-01\'),',
			'			partition  p202610 values less than (\'2026-11-01\'),',
			'			partition  p202611 values less than (\'2026-12-01\'),',
			'			partition  p202612 values less than (\'2027-01-01\'),',
			'			partition  p202701 values less than (\'2027-02-01\'),',
			'			partition  p202702 values less than (\'2027-03-01\'),',
			'			partition  p202703 values less than (\'2027-04-01\'),',
			'			partition  p202704 values less than (\'2027-05-01\'),',
			'			partition  p202705 values less than (\'2027-06-01\'),',
			'			partition  p202706 values less than (\'2027-07-01\'),',
			'			partition  p202707 values less than (\'2027-08-01\'),',
			'			partition  p202708 values less than (\'2027-09-01\'),',
			'			partition  p202709 values less than (\'2027-10-01\'),',
			'			partition  p202710 values less than (\'2027-11-01\'),',
			'			partition  p202711 values less than (\'2027-12-01\'),',
			'			partition  p202712 values less than (\'2028-01-01\'),',
			'			partition  p202801 values less than (\'2028-02-01\'),',
			'			partition  p202802 values less than (\'2028-03-01\'),',
			'			partition  p202803 values less than (\'2028-04-01\'),',
			'			partition  p202804 values less than (\'2028-05-01\'),',
			'			partition  p202805 values less than (\'2028-06-01\'),',
			'			partition  p202806 values less than (\'2028-07-01\'),',
			'			partition  p202807 values less than (\'2028-08-01\'),',
			'			partition  p202808 values less than (\'2028-09-01\'),',
			'			partition  p202809 values less than (\'2028-10-01\'),',
			'			partition  p202810 values less than (\'2028-11-01\'),',
			'			partition  p202811 values less than (\'2028-12-01\'),',
			'			partition  p202812 values less than (\'2029-01-01\'),',
			'			partition  p202901 values less than (\'2029-02-01\'),',
			'			partition  p202902 values less than (\'2029-03-01\'),',
			'			partition  p202903 values less than (\'2029-04-01\'),',
			'			partition  p202904 values less than (\'2029-05-01\'),',
			'			partition  p202905 values less than (\'2029-06-01\'),',
			'			partition  p202906 values less than (\'2029-07-01\'),',
			'			partition  p202907 values less than (\'2029-08-01\'),',
			'			partition  p202908 values less than (\'2029-09-01\'),',
			'			partition  p202909 values less than (\'2029-10-01\'),',
			'			partition  p202910 values less than (\'2029-11-01\'),',
			'			partition  p202911 values less than (\'2029-12-01\'),',
			'			partition  p202912 values less than (\'2030-01-01\'),',
			'			partition  p203001 values less than (\'2030-02-01\'),',
			'			partition  p203002 values less than (\'2030-03-01\'),',
			'			partition  p203003 values less than (\'2030-04-01\'),',
			'			partition  p203004 values less than (\'2030-05-01\'),',
			'			partition  p203005 values less than (\'2030-06-01\'),',
			'			partition  p203006 values less than (\'2030-07-01\'),',
			'			partition  p203007 values less than (\'2030-08-01\'),',
			'			partition  p203008 values less than (\'2030-09-01\'),',
			'			partition  p203009 values less than (\'2030-10-01\'),',
			'			partition  p203010 values less than (\'2030-11-01\'),',
			'			partition  p203011 values less than (\'2030-12-01\'),',
			'			partition  p203012 values less than (\'2031-01-01\'),',
			'			partition  p203101 values less than (\'2031-02-01\'),',
			'			partition  p203102 values less than (\'2031-03-01\'),',
			'			partition  p203103 values less than (\'2031-04-01\'),',
			'			partition  p203104 values less than (\'2031-05-01\'),',
			'			partition  p203105 values less than (\'2031-06-01\'),',
			'			partition  p203106 values less than (\'2031-07-01\'),',
			'			partition  p203107 values less than (\'2031-08-01\'),',
			'			partition  p203108 values less than (\'2031-09-01\'),',
			'			partition  p203109 values less than (\'2031-10-01\'),',
			'			partition  p203110 values less than (\'2031-11-01\'),',
			'			partition  p203111 values less than (\'2031-12-01\'),',
			'			partition  p203112 values less than (\'2032-01-01\'),',
			'			partition  p203201 values less than (\'2032-02-01\'),',
			'			partition  p203202 values less than (\'2032-03-01\'),',
			'			partition  p203203 values less than (\'2032-04-01\'),',
			'			partition  p203204 values less than (\'2032-05-01\'),',
			'			partition  p203205 values less than (\'2032-06-01\'),',
			'			partition  p203206 values less than (\'2032-07-01\'),',
			'			partition  p203207 values less than (\'2032-08-01\'),',
			'			partition  p203208 values less than (\'2032-09-01\'),',
			'			partition  p203209 values less than (\'2032-10-01\'),',
			'			partition  p203210 values less than (\'2032-11-01\'),',
			'			partition  p203211 values less than (\'2032-12-01\'),',
			'			partition  p203212 values less than (\'2033-01-01\'),',
			'			partition  p203301 values less than (\'2033-02-01\'),',
			'			partition  p203302 values less than (\'2033-03-01\'),',
			'			partition  p203303 values less than (\'2033-04-01\'),',
			'			partition  p203304 values less than (\'2033-05-01\'),',
			'			partition  p203305 values less than (\'2033-06-01\'),',
			'			partition  p203306 values less than (\'2033-07-01\'),',
			'			partition  p203307 values less than (\'2033-08-01\'),',
			'			partition  p203308 values less than (\'2033-09-01\'),',
			'			partition  p203309 values less than (\'2033-10-01\'),',
			'			partition  p203310 values less than (\'2033-11-01\'),',
			'			partition  p203311 values less than (\'2033-12-01\'),',
			'			partition  p203312 values less than (\'2034-01-01\'),',
			'			partition  p203401 values less than (\'2034-02-01\'),',
			'			partition  p203402 values less than (\'2034-03-01\'),',
			'			partition  p203403 values less than (\'2034-04-01\'),',
			'			partition  p203404 values less than (\'2034-05-01\'),',
			'			partition  p203405 values less than (\'2034-06-01\'),',
			'			partition  p203406 values less than (\'2034-07-01\'),',
			'			partition  p203407 values less than (\'2034-08-01\'),',
			'			partition  p203408 values less than (\'2034-09-01\'),',
			'			partition  p203409 values less than (\'2034-10-01\'),',
			'			partition  p203410 values less than (\'2034-11-01\'),',
			'			partition  p203411 values less than (\'2034-12-01\'),',
			'			partition  p203412 values less than (\'2035-01-01\'),',
			'			partition  p203501 values less than (\'2035-02-01\'),',
			'			partition  p203502 values less than (\'2035-03-01\'),',
			'			partition  p203503 values less than (\'2035-04-01\'),',
			'			partition  p203504 values less than (\'2035-05-01\'),',
			'			partition  p203505 values less than (\'2035-06-01\'),',
			'			partition  p203506 values less than (\'2035-07-01\'),',
			'			partition  p203507 values less than (\'2035-08-01\'),',
			'			partition  p203508 values less than (\'2035-09-01\'),',
			'			partition  p203509 values less than (\'2035-10-01\'),',
			'			partition  p203510 values less than (\'2035-11-01\'),',
			'			partition  p203511 values less than (\'2035-12-01\'),',
			'			partition  p203512 values less than (\'2036-01-01\'),',
			'			partition  p203601 values less than (\'2036-02-01\'),',
			'			partition  p203602 values less than (\'2036-03-01\'),',
			'			partition  p203603 values less than (\'2036-04-01\'),',
			'			partition  p203604 values less than (\'2036-05-01\'),',
			'			partition  p203605 values less than (\'2036-06-01\'),',
			'			partition  p203606 values less than (\'2036-07-01\'),',
			'			partition  p203607 values less than (\'2036-08-01\'),',
			'			partition  p203608 values less than (\'2036-09-01\'),',
			'			partition  p203609 values less than (\'2036-10-01\'),',
			'			partition  p203610 values less than (\'2036-11-01\'),',
			'			partition  p203611 values less than (\'2036-12-01\'),',
			'			partition  p203612 values less than (\'2037-01-01\'),',
			'			partition  p203701 values less than (\'2037-02-01\'),',
			'			partition  p203702 values less than (\'2037-03-01\'),',
			'			partition  p203703 values less than (\'2037-04-01\'),',
			'			partition  p203704 values less than (\'2037-05-01\'),',
			'			partition  p203705 values less than (\'2037-06-01\'),',
			'			partition  p203706 values less than (\'2037-07-01\'),',
			'			partition  p203707 values less than (\'2037-08-01\'),',
			'			partition  p203708 values less than (\'2037-09-01\'),',
			'			partition  p203709 values less than (\'2037-10-01\'),',
			'			partition  p203710 values less than (\'2037-11-01\'),',
			'			partition  p203711 values less than (\'2037-12-01\'),',
			'			partition  p203712 values less than (\'2038-01-01\'),',
			'			PARTITION pmax VALUES LESS THAN (MAXVALUE)',
			'		);',
			"		create index {$wpdb->prefix}qa_page_version_hist_page_id_index",
			"			on {$wpdb->prefix}qa_page_version_hist (page_id)",
			'		;',

		);

		return $this->wrap_implode( "\n", $lines );
	}

	// qa_gscテーブルの作成SQLを返す
	public function get_qa_gsc_query_log_create_table( $tracking_id ) {
		global $wpdb;

		// tracking_idのバリデーション
		if ( empty( $tracking_id ) ) {
			return '';
		}

		$charset_collate = '';

		// charsetを指定する
		if ( $wpdb->charset ) {
			$charset_collate = 'DEFAULT CHARACTER SET ' . $wpdb->charset;
		}

		// 照合順序を指定する（ある場合。通常デフォルトのutf8_general_ci）
		if ( $wpdb->collate ) {
			$charset_collate .= ' COLLATE ' . $wpdb->collate;
		}

		$table_name = $wpdb->prefix . 'qa_gsc_' . $tracking_id . '_query_log';

		$lines = array(
			"		-- qa_gsc_query_log for tracking_id: {$tracking_id}",
			"		DROP TABLE IF EXISTS {$table_name};",
			"		CREATE TABLE {$table_name}",
			'		(',
			'			query_id int auto_increment,',
			'			keyword varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,',
			'			update_date date not null,',
			'			primary key (query_id, update_date),',
			'			unique key (keyword, update_date)',
			"		) {$charset_collate} ",
			'		PARTITION BY RANGE COLUMNS(update_date) (',
			'			PARTITION p202001 VALUES LESS THAN (\'2020-02-01\'),',
			'			PARTITION p202002 VALUES LESS THAN (\'2020-03-01\'),',
			'			PARTITION p202003 VALUES LESS THAN (\'2020-04-01\'),',
			'			PARTITION p202004 VALUES LESS THAN (\'2020-05-01\'),',
			'			PARTITION p202005 VALUES LESS THAN (\'2020-06-01\'),',
			'			PARTITION p202006 VALUES LESS THAN (\'2020-07-01\'),',
			'			PARTITION p202007 VALUES LESS THAN (\'2020-08-01\'),',
			'			PARTITION p202008 VALUES LESS THAN (\'2020-09-01\'),',
			'			PARTITION p202009 VALUES LESS THAN (\'2020-10-01\'),',
			'			PARTITION p202010 VALUES LESS THAN (\'2020-11-01\'),',
			'			PARTITION p202011 VALUES LESS THAN (\'2020-12-01\'),',
			'			PARTITION p202012 VALUES LESS THAN (\'2021-01-01\'),',
			'			PARTITION p202101 VALUES LESS THAN (\'2021-02-01\'),',
			'			PARTITION p202102 VALUES LESS THAN (\'2021-03-01\'),',
			'			PARTITION p202103 VALUES LESS THAN (\'2021-04-01\'),',
			'			PARTITION p202104 VALUES LESS THAN (\'2021-05-01\'),',
			'			PARTITION p202105 VALUES LESS THAN (\'2021-06-01\'),',
			'			PARTITION p202106 VALUES LESS THAN (\'2021-07-01\'),',
			'			PARTITION p202107 VALUES LESS THAN (\'2021-08-01\'),',
			'			PARTITION p202108 VALUES LESS THAN (\'2021-09-01\'),',
			'			PARTITION p202109 VALUES LESS THAN (\'2021-10-01\'),',
			'			PARTITION p202110 VALUES LESS THAN (\'2021-11-01\'),',
			'			PARTITION p202111 VALUES LESS THAN (\'2021-12-01\'),',
			'			PARTITION p202112 VALUES LESS THAN (\'2022-01-01\'),',
			'			PARTITION p202201 VALUES LESS THAN (\'2022-02-01\'),',
			'			PARTITION p202202 VALUES LESS THAN (\'2022-03-01\'),',
			'			PARTITION p202203 VALUES LESS THAN (\'2022-04-01\'),',
			'			PARTITION p202204 VALUES LESS THAN (\'2022-05-01\'),',
			'			PARTITION p202205 VALUES LESS THAN (\'2022-06-01\'),',
			'			PARTITION p202206 VALUES LESS THAN (\'2022-07-01\'),',
			'			PARTITION p202207 VALUES LESS THAN (\'2022-08-01\'),',
			'			PARTITION p202208 VALUES LESS THAN (\'2022-09-01\'),',
			'			PARTITION p202209 VALUES LESS THAN (\'2022-10-01\'),',
			'			PARTITION p202210 VALUES LESS THAN (\'2022-11-01\'),',
			'			PARTITION p202211 VALUES LESS THAN (\'2022-12-01\'),',
			'			PARTITION p202212 VALUES LESS THAN (\'2023-01-01\'),',
			'			PARTITION p202301 VALUES LESS THAN (\'2023-02-01\'),',
			'			PARTITION p202302 VALUES LESS THAN (\'2023-03-01\'),',
			'			PARTITION p202303 VALUES LESS THAN (\'2023-04-01\'),',
			'			PARTITION p202304 VALUES LESS THAN (\'2023-05-01\'),',
			'			PARTITION p202305 VALUES LESS THAN (\'2023-06-01\'),',
			'			PARTITION p202306 VALUES LESS THAN (\'2023-07-01\'),',
			'			PARTITION p202307 VALUES LESS THAN (\'2023-08-01\'),',
			'			PARTITION p202308 VALUES LESS THAN (\'2023-09-01\'),',
			'			PARTITION p202309 VALUES LESS THAN (\'2023-10-01\'),',
			'			PARTITION p202310 VALUES LESS THAN (\'2023-11-01\'),',
			'			PARTITION p202311 VALUES LESS THAN (\'2023-12-01\'),',
			'			PARTITION p202312 VALUES LESS THAN (\'2024-01-01\'),',
			'			PARTITION p202401 VALUES LESS THAN (\'2024-02-01\'),',
			'			PARTITION p202402 VALUES LESS THAN (\'2024-03-01\'),',
			'			PARTITION p202403 VALUES LESS THAN (\'2024-04-01\'),',
			'			PARTITION p202404 VALUES LESS THAN (\'2024-05-01\'),',
			'			PARTITION p202405 VALUES LESS THAN (\'2024-06-01\'),',
			'			PARTITION p202406 VALUES LESS THAN (\'2024-07-01\'),',
			'			PARTITION p202407 VALUES LESS THAN (\'2024-08-01\'),',
			'			PARTITION p202408 VALUES LESS THAN (\'2024-09-01\'),',
			'			PARTITION p202409 VALUES LESS THAN (\'2024-10-01\'),',
			'			PARTITION p202410 VALUES LESS THAN (\'2024-11-01\'),',
			'			PARTITION p202411 VALUES LESS THAN (\'2024-12-01\'),',
			'			PARTITION p202412 VALUES LESS THAN (\'2025-01-01\'),',
			'			PARTITION p202501 VALUES LESS THAN (\'2025-02-01\'),',
			'			PARTITION p202502 VALUES LESS THAN (\'2025-03-01\'),',
			'			PARTITION p202503 VALUES LESS THAN (\'2025-04-01\'),',
			'			PARTITION p202504 VALUES LESS THAN (\'2025-05-01\'),',
			'			PARTITION p202505 VALUES LESS THAN (\'2025-06-01\'),',
			'			PARTITION p202506 VALUES LESS THAN (\'2025-07-01\'),',
			'			PARTITION p202507 VALUES LESS THAN (\'2025-08-01\'),',
			'			PARTITION p202508 VALUES LESS THAN (\'2025-09-01\'),',
			'			PARTITION p202509 VALUES LESS THAN (\'2025-10-01\'),',
			'			PARTITION p202510 VALUES LESS THAN (\'2025-11-01\'),',
			'			PARTITION p202511 VALUES LESS THAN (\'2025-12-01\'),',
			'			PARTITION p202512 VALUES LESS THAN (\'2026-01-01\'),',
			'			PARTITION p202601 VALUES LESS THAN (\'2026-02-01\'),',
			'			PARTITION p202602 VALUES LESS THAN (\'2026-03-01\'),',
			'			PARTITION p202603 VALUES LESS THAN (\'2026-04-01\'),',
			'			PARTITION p202604 VALUES LESS THAN (\'2026-05-01\'),',
			'			PARTITION p202605 VALUES LESS THAN (\'2026-06-01\'),',
			'			PARTITION p202606 VALUES LESS THAN (\'2026-07-01\'),',
			'			PARTITION p202607 VALUES LESS THAN (\'2026-08-01\'),',
			'			PARTITION p202608 VALUES LESS THAN (\'2026-09-01\'),',
			'			PARTITION p202609 VALUES LESS THAN (\'2026-10-01\'),',
			'			PARTITION p202610 VALUES LESS THAN (\'2026-11-01\'),',
			'			PARTITION p202611 VALUES LESS THAN (\'2026-12-01\'),',
			'			PARTITION p202612 VALUES LESS THAN (\'2027-01-01\'),',
			'			PARTITION p202701 VALUES LESS THAN (\'2027-02-01\'),',
			'			PARTITION p202702 VALUES LESS THAN (\'2027-03-01\'),',
			'			PARTITION p202703 VALUES LESS THAN (\'2027-04-01\'),',
			'			PARTITION p202704 VALUES LESS THAN (\'2027-05-01\'),',
			'			PARTITION p202705 VALUES LESS THAN (\'2027-06-01\'),',
			'			PARTITION p202706 VALUES LESS THAN (\'2027-07-01\'),',
			'			PARTITION p202707 VALUES LESS THAN (\'2027-08-01\'),',
			'			PARTITION p202708 VALUES LESS THAN (\'2027-09-01\'),',
			'			PARTITION p202709 VALUES LESS THAN (\'2027-10-01\'),',
			'			PARTITION p202710 VALUES LESS THAN (\'2027-11-01\'),',
			'			PARTITION p202711 VALUES LESS THAN (\'2027-12-01\'),',
			'			PARTITION p202712 VALUES LESS THAN (\'2028-01-01\'),',
			'			PARTITION p202801 VALUES LESS THAN (\'2028-02-01\'),',
			'			PARTITION p202802 VALUES LESS THAN (\'2028-03-01\'),',
			'			PARTITION p202803 VALUES LESS THAN (\'2028-04-01\'),',
			'			PARTITION p202804 VALUES LESS THAN (\'2028-05-01\'),',
			'			PARTITION p202805 VALUES LESS THAN (\'2028-06-01\'),',
			'			PARTITION p202806 VALUES LESS THAN (\'2028-07-01\'),',
			'			PARTITION p202807 VALUES LESS THAN (\'2028-08-01\'),',
			'			PARTITION p202808 VALUES LESS THAN (\'2028-09-01\'),',
			'			PARTITION p202809 VALUES LESS THAN (\'2028-10-01\'),',
			'			PARTITION p202810 VALUES LESS THAN (\'2028-11-01\'),',
			'			PARTITION p202811 VALUES LESS THAN (\'2028-12-01\'),',
			'			PARTITION p202812 VALUES LESS THAN (\'2029-01-01\'),',
			'			PARTITION p202901 VALUES LESS THAN (\'2029-02-01\'),',
			'			PARTITION p202902 VALUES LESS THAN (\'2029-03-01\'),',
			'			PARTITION p202903 VALUES LESS THAN (\'2029-04-01\'),',
			'			PARTITION p202904 VALUES LESS THAN (\'2029-05-01\'),',
			'			PARTITION p202905 VALUES LESS THAN (\'2029-06-01\'),',
			'			PARTITION p202906 VALUES LESS THAN (\'2029-07-01\'),',
			'			PARTITION p202907 VALUES LESS THAN (\'2029-08-01\'),',
			'			PARTITION p202908 VALUES LESS THAN (\'2029-09-01\'),',
			'			PARTITION p202909 VALUES LESS THAN (\'2029-10-01\'),',
			'			PARTITION p202910 VALUES LESS THAN (\'2029-11-01\'),',
			'			PARTITION p202911 VALUES LESS THAN (\'2029-12-01\'),',
			'			PARTITION p202912 VALUES LESS THAN (\'2030-01-01\'),',
			'			PARTITION p203001 VALUES LESS THAN (\'2030-02-01\'),',
			'			PARTITION p203002 VALUES LESS THAN (\'2030-03-01\'),',
			'			PARTITION p203003 VALUES LESS THAN (\'2030-04-01\'),',
			'			PARTITION p203004 VALUES LESS THAN (\'2030-05-01\'),',
			'			PARTITION p203005 VALUES LESS THAN (\'2030-06-01\'),',
			'			PARTITION p203006 VALUES LESS THAN (\'2030-07-01\'),',
			'			PARTITION p203007 VALUES LESS THAN (\'2030-08-01\'),',
			'			PARTITION p203008 VALUES LESS THAN (\'2030-09-01\'),',
			'			PARTITION p203009 VALUES LESS THAN (\'2030-10-01\'),',
			'			PARTITION p203010 VALUES LESS THAN (\'2030-11-01\'),',
			'			PARTITION p203011 VALUES LESS THAN (\'2030-12-01\'),',
			'			PARTITION p203012 VALUES LESS THAN (\'2031-01-01\'),',
			'			PARTITION p203101 VALUES LESS THAN (\'2031-02-01\'),',
			'			PARTITION p203102 VALUES LESS THAN (\'2031-03-01\'),',
			'			PARTITION p203103 VALUES LESS THAN (\'2031-04-01\'),',
			'			PARTITION p203104 VALUES LESS THAN (\'2031-05-01\'),',
			'			PARTITION p203105 VALUES LESS THAN (\'2031-06-01\'),',
			'			PARTITION p203106 VALUES LESS THAN (\'2031-07-01\'),',
			'			PARTITION p203107 VALUES LESS THAN (\'2031-08-01\'),',
			'			PARTITION p203108 VALUES LESS THAN (\'2031-09-01\'),',
			'			PARTITION p203109 VALUES LESS THAN (\'2031-10-01\'),',
			'			PARTITION p203110 VALUES LESS THAN (\'2031-11-01\'),',
			'			PARTITION p203111 VALUES LESS THAN (\'2031-12-01\'),',
			'			PARTITION p203112 VALUES LESS THAN (\'2032-01-01\'),',
			'			PARTITION p203201 VALUES LESS THAN (\'2032-02-01\'),',
			'			PARTITION p203202 VALUES LESS THAN (\'2032-03-01\'),',
			'			PARTITION p203203 VALUES LESS THAN (\'2032-04-01\'),',
			'			PARTITION p203204 VALUES LESS THAN (\'2032-05-01\'),',
			'			PARTITION p203205 VALUES LESS THAN (\'2032-06-01\'),',
			'			PARTITION p203206 VALUES LESS THAN (\'2032-07-01\'),',
			'			PARTITION p203207 VALUES LESS THAN (\'2032-08-01\'),',
			'			PARTITION p203208 VALUES LESS THAN (\'2032-09-01\'),',
			'			PARTITION p203209 VALUES LESS THAN (\'2032-10-01\'),',
			'			PARTITION p203210 VALUES LESS THAN (\'2032-11-01\'),',
			'			PARTITION p203211 VALUES LESS THAN (\'2032-12-01\'),',
			'			PARTITION p203212 VALUES LESS THAN (\'2033-01-01\'),',
			'			PARTITION p203301 VALUES LESS THAN (\'2033-02-01\'),',
			'			PARTITION p203302 VALUES LESS THAN (\'2033-03-01\'),',
			'			PARTITION p203303 VALUES LESS THAN (\'2033-04-01\'),',
			'			PARTITION p203304 VALUES LESS THAN (\'2033-05-01\'),',
			'			PARTITION p203305 VALUES LESS THAN (\'2033-06-01\'),',
			'			PARTITION p203306 VALUES LESS THAN (\'2033-07-01\'),',
			'			PARTITION p203307 VALUES LESS THAN (\'2033-08-01\'),',
			'			PARTITION p203308 VALUES LESS THAN (\'2033-09-01\'),',
			'			PARTITION p203309 VALUES LESS THAN (\'2033-10-01\'),',
			'			PARTITION p203310 VALUES LESS THAN (\'2033-11-01\'),',
			'			PARTITION p203311 VALUES LESS THAN (\'2033-12-01\'),',
			'			PARTITION p203312 VALUES LESS THAN (\'2034-01-01\'),',
			'			PARTITION p203401 VALUES LESS THAN (\'2034-02-01\'),',
			'			PARTITION p203402 VALUES LESS THAN (\'2034-03-01\'),',
			'			PARTITION p203403 VALUES LESS THAN (\'2034-04-01\'),',
			'			PARTITION p203404 VALUES LESS THAN (\'2034-05-01\'),',
			'			PARTITION p203405 VALUES LESS THAN (\'2034-06-01\'),',
			'			PARTITION p203406 VALUES LESS THAN (\'2034-07-01\'),',
			'			PARTITION p203407 VALUES LESS THAN (\'2034-08-01\'),',
			'			PARTITION p203408 VALUES LESS THAN (\'2034-09-01\'),',
			'			PARTITION p203409 VALUES LESS THAN (\'2034-10-01\'),',
			'			PARTITION p203410 VALUES LESS THAN (\'2034-11-01\'),',
			'			PARTITION p203411 VALUES LESS THAN (\'2034-12-01\'),',
			'			PARTITION p203412 VALUES LESS THAN (\'2035-01-01\'),',
			'			PARTITION p203501 VALUES LESS THAN (\'2035-02-01\'),',
			'			PARTITION p203502 VALUES LESS THAN (\'2035-03-01\'),',
			'			PARTITION p203503 VALUES LESS THAN (\'2035-04-01\'),',
			'			PARTITION p203504 VALUES LESS THAN (\'2035-05-01\'),',
			'			PARTITION p203505 VALUES LESS THAN (\'2035-06-01\'),',
			'			PARTITION p203506 VALUES LESS THAN (\'2035-07-01\'),',
			'			PARTITION p203507 VALUES LESS THAN (\'2035-08-01\'),',
			'			PARTITION p203508 VALUES LESS THAN (\'2035-09-01\'),',
			'			PARTITION p203509 VALUES LESS THAN (\'2035-10-01\'),',
			'			PARTITION p203510 VALUES LESS THAN (\'2035-11-01\'),',
			'			PARTITION p203511 VALUES LESS THAN (\'2035-12-01\'),',
			'			PARTITION p203512 VALUES LESS THAN (\'2036-01-01\'),',
			'			PARTITION p203601 VALUES LESS THAN (\'2036-02-01\'),',
			'			PARTITION p203602 VALUES LESS THAN (\'2036-03-01\'),',
			'			PARTITION p203603 VALUES LESS THAN (\'2036-04-01\'),',
			'			PARTITION p203604 VALUES LESS THAN (\'2036-05-01\'),',
			'			PARTITION p203605 VALUES LESS THAN (\'2036-06-01\'),',
			'			PARTITION p203606 VALUES LESS THAN (\'2036-07-01\'),',
			'			PARTITION p203607 VALUES LESS THAN (\'2036-08-01\'),',
			'			PARTITION p203608 VALUES LESS THAN (\'2036-09-01\'),',
			'			PARTITION p203609 VALUES LESS THAN (\'2036-10-01\'),',
			'			PARTITION p203610 VALUES LESS THAN (\'2036-11-01\'),',
			'			PARTITION p203611 VALUES LESS THAN (\'2036-12-01\'),',
			'			PARTITION p203612 VALUES LESS THAN (\'2037-01-01\'),',
			'			PARTITION p203701 VALUES LESS THAN (\'2037-02-01\'),',
			'			PARTITION p203702 VALUES LESS THAN (\'2037-03-01\'),',
			'			PARTITION p203703 VALUES LESS THAN (\'2037-04-01\'),',
			'			PARTITION p203704 VALUES LESS THAN (\'2037-05-01\'),',
			'			PARTITION p203705 VALUES LESS THAN (\'2037-06-01\'),',
			'			PARTITION p203706 VALUES LESS THAN (\'2037-07-01\'),',
			'			PARTITION p203707 VALUES LESS THAN (\'2037-08-01\'),',
			'			PARTITION p203708 VALUES LESS THAN (\'2037-09-01\'),',
			'			PARTITION p203709 VALUES LESS THAN (\'2037-10-01\'),',
			'			PARTITION p203710 VALUES LESS THAN (\'2037-11-01\'),',
			'			PARTITION p203711 VALUES LESS THAN (\'2037-12-01\'),',
			'			PARTITION p203712 VALUES LESS THAN (\'2038-01-01\'),',
			'			PARTITION pmax VALUES LESS THAN (MAXVALUE)',
			'		);',

		);

		return $this->wrap_implode( "\n", $lines );
	}
}
