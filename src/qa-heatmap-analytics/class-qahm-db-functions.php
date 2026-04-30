<?php
defined( 'ABSPATH' ) || exit;
/**
 * Class QAHM_DB_Functions
 *
 * データベーステーブルからデータを取得するためのクラス
 * マスターテーブルおよびトランザクションテーブルへの直接的なアクセス関数を提供します
 *
 * @package qa_heatmap
 */
class QAHM_DB_Functions extends QAHM_Base {

	/**
	 * UTMメディアマスターからデータを取得
	 *
	 * @param int|array $medium_id 取得対象のメディアID。省略時は全件取得
	 * @return array medium_id, utm_medium, description の配列
	 */
	public static function get_utm_media( $medium_id = null ) {
		global $wpdb;

		if ( $medium_id === null ) {
			// 全件取得
			return $wpdb->get_results(
				"SELECT medium_id, utm_medium, description 
                FROM `{$wpdb->prefix}qa_utm_media` 
                ORDER BY medium_id",
				ARRAY_A
			);
		} elseif ( is_array( $medium_id ) ) {
			// IN句で複数指定
			if ( empty( $medium_id ) ) {
				return array();
			}

			$placeholders = static::wrap_implode_static( ',', array_fill( 0, static::wrap_count_static( $medium_id ), '%d' ) );
			$sql          = $wpdb->prepare(
				"SELECT medium_id, utm_medium, description 
                 FROM {$wpdb->prefix}qa_utm_media 
                 WHERE medium_id IN ($placeholders)
                 ORDER BY medium_id",
				$medium_id
			);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
			return $wpdb->get_results( $sql, ARRAY_A );
		} else {
			// 1件取得
			$sql = $wpdb->prepare(
				"SELECT medium_id, utm_medium, description 
                 FROM {$wpdb->prefix}qa_utm_media 
                 WHERE medium_id = %d",
				$medium_id
			);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
			return $wpdb->get_row( $sql, ARRAY_A );
		}
	}

	/**
	 * UTMコンテンツマスターからデータを取得
	 *
	 * @param int|array $content_id 取得対象のコンテンツID。省略時は全件取得
	 * @return array content_id, utm_content の配列
	 */
	public static function get_utm_content( $content_id = null ) {
		global $wpdb;

		if ( $content_id === null ) {
			// 全件取得
			return $wpdb->get_results(
				"SELECT content_id, utm_content 
                FROM `{$wpdb->prefix}qa_utm_content` 
                ORDER BY content_id",
				ARRAY_A
			);
		} elseif ( is_array( $content_id ) ) {
			// IN句で複数指定
			if ( empty( $content_id ) ) {
				return array();
			}

			$placeholders = static::wrap_implode_static( ',', array_fill( 0, static::wrap_count_static( $content_id ), '%d' ) );
			$sql          = $wpdb->prepare(
				"SELECT content_id, utm_content 
                 FROM {$wpdb->prefix}qa_utm_content 
                 WHERE content_id IN ($placeholders)
                 ORDER BY content_id",
				$content_id
			);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
			return $wpdb->get_results( $sql, ARRAY_A );
		} else {
			// 1件取得
			$sql = $wpdb->prepare(
				"SELECT content_id, utm_content 
                 FROM {$wpdb->prefix}qa_utm_content 
                 WHERE content_id = %d",
				$content_id
			);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
			return $wpdb->get_row( $sql, ARRAY_A );
		}
	}

	/**
	 * UTMソースマスターからデータを取得
	 *
	 * @param int|array $source_id 取得対象のソースID。省略時は全件取得
	 * @return array source_id, utm_source, referer, source_domain, medium_id の配列
	 */
	public static function get_utm_sources( $source_id = null ) {
		global $wpdb;

		if ( $source_id === null ) {
			// 全件取得
			return $wpdb->get_results(
				"SELECT source_id, utm_source, referer, source_domain, medium_id, utm_term, keyword
                FROM `{$wpdb->prefix}qa_utm_sources`
                ORDER BY source_id",
				ARRAY_A
			);
		} elseif ( is_array( $source_id ) ) {
			// IN句で複数指定
			if ( empty( $source_id ) ) {
				return array();
			}

			$placeholders = static::wrap_implode_static( ',', array_fill( 0, static::wrap_count_static( $source_id ), '%d' ) );
			$sql          = $wpdb->prepare(
				"SELECT source_id, utm_source, referer, source_domain, medium_id, utm_term, keyword 
                 FROM {$wpdb->prefix}qa_utm_sources 
                 WHERE source_id IN ($placeholders)
                 ORDER BY source_id",
				$source_id
			);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
			return $wpdb->get_results( $sql, ARRAY_A );
		} else {
			// 1件取得
			$sql = $wpdb->prepare(
				"SELECT source_id, utm_source, referer, source_domain, medium_id, utm_term, keyword 
                 FROM {$wpdb->prefix}qa_utm_sources 
                 WHERE source_id = %d",
				$source_id
			);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
			return $wpdb->get_row( $sql, ARRAY_A );
		}
	}

	/**
	 * UTMキャンペーンマスターからデータを取得
	 *
	 * @param int|array $campaign_id 取得対象のキャンペーンID。省略時は全件取得
	 * @return array campaign_id, utm_campaign の配列
	 */
	public static function get_utm_campaigns( $campaign_id = null ) {
		global $wpdb;

		if ( $campaign_id === null ) {
			// 全件取得
			return $wpdb->get_results(
				"SELECT campaign_id, utm_campaign
                FROM `{$wpdb->prefix}qa_utm_campaigns`
                ORDER BY campaign_id",
				ARRAY_A
			);
		} elseif ( is_array( $campaign_id ) ) {
			// IN句で複数指定
			if ( empty( $campaign_id ) ) {
				return array();
			}

			$placeholders = static::wrap_implode_static( ',', array_fill( 0, static::wrap_count_static( $campaign_id ), '%d' ) );
			$sql          = $wpdb->prepare(
				"SELECT campaign_id, utm_campaign 
                 FROM {$wpdb->prefix}qa_utm_campaigns 
                 WHERE campaign_id IN ($placeholders)
                 ORDER BY campaign_id",
				$campaign_id
			);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
			return $wpdb->get_results( $sql, ARRAY_A );
		} else {
			// 1件取得
			$sql = $wpdb->prepare(
				"SELECT campaign_id, utm_campaign 
                 FROM {$wpdb->prefix}qa_utm_campaigns 
                 WHERE campaign_id = %d",
				$campaign_id
			);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
			return $wpdb->get_row( $sql, ARRAY_A );
		}
	}

	/**
	 * ページマスターからデータを取得
	 *
	 * @param int|array $page_id 取得対象のページID。省略時は検索条件による取得
	 * @param string $url_filter URL検索フィルタ（$page_idが省略された場合のみ有効）
	 * @param int $limit 取得件数上限（省略可）
	 * @return array page_id, tracking_id, wp_qa_type, wp_qa_id, url, title の配列
	 */
	public static function get_qa_pages( $page_id = null, $url_filter = '', $limit = 1000 ) {
		global $wpdb;

		if ( is_numeric( $page_id ) ) {
			// 単一ページID指定の場合
			$sql = $wpdb->prepare(
				"SELECT page_id, tracking_id, wp_qa_type, wp_qa_id, url, url_hash, path_url_hash, title, update_date
                 FROM {$wpdb->prefix}qa_pages
                 WHERE page_id = %d",
				$page_id
			);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
			return $wpdb->get_row( $sql, ARRAY_A );
		} elseif ( is_array( $page_id ) && ! empty( $page_id ) ) {
			// 複数ページID指定の場合
			$placeholders = static::wrap_implode_static( ',', array_fill( 0, static::wrap_count_static( $page_id ), '%d' ) );
			$sql          = $wpdb->prepare(
				"SELECT page_id, tracking_id, wp_qa_type, wp_qa_id, url, url_hash, path_url_hash, title, update_date
                 FROM {$wpdb->prefix}qa_pages
                 WHERE page_id IN ($placeholders)
                 ORDER BY page_id DESC",
				$page_id
			);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
			return $wpdb->get_results( $sql, ARRAY_A );
		} else {
			// ページID指定なしの場合（検索条件による取得）
			$where = '';
			if ( ! empty( $url_filter ) ) {
				$where = $wpdb->prepare( 'WHERE url LIKE %s', '%' . $wpdb->esc_like( $url_filter ) . '%' );
			}

			$limit_clause = '';
			if ( $limit > 0 ) {
				$limit_clause = $wpdb->prepare( 'LIMIT %d', $limit );
			}

			return $wpdb->get_results(
				"
                SELECT page_id, tracking_id, wp_qa_type, wp_qa_id, url, url_hash, path_url_hash, title, update_date
                FROM `{$wpdb->prefix}qa_pages`
                {$where}
                ORDER BY page_id DESC
                {$limit_clause}
                ",
				ARRAY_A
			);
		}
	}

	/**
	 * URLハッシュからページ情報を取得
	 *
	 * @param string|array $url_hash URLハッシュ（単一または配列）
	 * @return array|null ページ情報
	 */
	public static function get_page_by_url_hash( $url_hash ) {
		global $wpdb;

		if ( is_array( $url_hash ) ) {
			if ( empty( $url_hash ) ) {
				return array();
			}

			$placeholders = static::wrap_implode_static( ',', array_fill( 0, static::wrap_count_static( $url_hash ), '%s' ) );
			$sql          = $wpdb->prepare(
				"SELECT page_id, tracking_id, wp_qa_type, wp_qa_id, url, url_hash, path_url_hash, title, update_date
                 FROM {$wpdb->prefix}qa_pages
                 WHERE url_hash IN ($placeholders)
                 ORDER BY page_id",
				$url_hash
			);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
			return $wpdb->get_results( $sql, ARRAY_A );
		} else {
			$sql = $wpdb->prepare(
				"SELECT page_id, tracking_id, wp_qa_type, wp_qa_id, url, url_hash, path_url_hash, title, update_date
                 FROM {$wpdb->prefix}qa_pages
                 WHERE url_hash = %s",
				$url_hash
			);

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
			return $wpdb->get_row( $sql, ARRAY_A );
		}
	}

	/**
	 * 検索ログからデータを取得
	 *
	 * @param int|array $pv_id PV_ID（指定時は日付条件は無視）
	 * @param string $start_date 開始日（Y-m-d形式）
	 * @param string $end_date 終了日（Y-m-d形式）
	 * @param int $limit 取得件数上限（省略可）
	 * @return array 検索ログデータ
	 */
	public static function get_search_logs( $pv_id = null, $start_date = null, $end_date = null, $limit = 1000 ) {
		global $wpdb;

		if ( is_numeric( $pv_id ) ) {
			// 単一PV_ID指定
			$sql = $wpdb->prepare(
				"SELECT s.pv_id, s.query, p.reader_id, p.page_id, p.access_time
                 FROM {$wpdb->prefix}qa_search_log AS s
                 INNER JOIN {$wpdb->prefix}qa_pv_log AS p ON s.pv_id = p.pv_id
                 WHERE s.pv_id = %d",
				$pv_id
			);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
			return $wpdb->get_row( $sql, ARRAY_A );
		} elseif ( is_array( $pv_id ) && ! empty( $pv_id ) ) {
			// 複数PV_ID指定
			$placeholders = static::wrap_implode_static( ',', array_fill( 0, static::wrap_count_static( $pv_id ), '%d' ) );
			$sql          = $wpdb->prepare(
				"SELECT s.pv_id, s.query, p.reader_id, p.page_id, p.access_time
                 FROM {$wpdb->prefix}qa_search_log AS s
                 INNER JOIN {$wpdb->prefix}qa_pv_log AS p ON s.pv_id = p.pv_id
                 WHERE s.pv_id IN ($placeholders)
                 ORDER BY p.access_time DESC",
				$pv_id
			);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
			return $wpdb->get_results( $sql, ARRAY_A );
		} elseif ( $start_date && $end_date ) {
			// 日付範囲指定
			$sql = $wpdb->prepare(
				"SELECT s.pv_id, s.query, p.reader_id, p.page_id, p.access_time
                 FROM {$wpdb->prefix}qa_search_log AS s
                 INNER JOIN {$wpdb->prefix}qa_pv_log AS p ON s.pv_id = p.pv_id
                 WHERE DATE(p.access_time) BETWEEN %s AND %s
                 ORDER BY p.access_time DESC
                 LIMIT %d",
				$start_date,
				$end_date,
				$limit
			);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
			return $wpdb->get_results( $sql, ARRAY_A );
		} else {
			// 条件なし（最新のものを上限数だけ取得）
			$sql = $wpdb->prepare(
				"SELECT s.pv_id, s.query, p.reader_id, p.page_id, p.access_time
                 FROM {$wpdb->prefix}qa_search_log AS s
                 INNER JOIN {$wpdb->prefix}qa_pv_log AS p ON s.pv_id = p.pv_id
                 ORDER BY p.access_time DESC
                 LIMIT %d",
				$limit
			);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
			return $wpdb->get_results( $sql, ARRAY_A );
		}
	}

	/**
	 * GSCクエリログからデータを取得
	 *
	 * @param string $tracking_id トラッキングID
	 * @param int|array $query_id クエリID（指定時は日付条件は無視）
	 * @param string $start_date 開始日（Y-m-d形式）
	 * @param string $end_date 終了日（Y-m-d形式）
	 * @param int $limit 取得件数上限（省略可）
	 * @return array GSCクエリログデータ
	 */
	public static function get_gsc_query_logs( $tracking_id, $query_id = null, $start_date = null, $end_date = null, $limit = 1000 ) {
		global $wpdb;

		if ( is_numeric( $query_id ) ) {
			// 単一クエリID指定
			$sql = $wpdb->prepare(
				"SELECT query_id, keyword, update_date
                 FROM {$wpdb->prefix}qa_gsc_{$tracking_id}_query_log
                 WHERE query_id = %d",
				$query_id
			);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
			return $wpdb->get_row( $sql, ARRAY_A );
		} elseif ( is_array( $query_id ) && ! empty( $query_id ) ) {
			// 複数クエリID指定
			$placeholders = static::wrap_implode_static( ',', array_fill( 0, static::wrap_count_static( $query_id ), '%d' ) );
			$sql          = $wpdb->prepare(
				"SELECT query_id, keyword, update_date
                 FROM {$wpdb->prefix}qa_gsc_{$tracking_id}_query_log
                 WHERE query_id IN ($placeholders)
                 ORDER BY update_date DESC",
				$query_id
			);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
			return $wpdb->get_results( $sql, ARRAY_A );
		} elseif ( $start_date && $end_date ) {
			// 日付範囲指定
			$sql = $wpdb->prepare(
				"SELECT query_id, keyword, update_date
                 FROM {$wpdb->prefix}qa_gsc_{$tracking_id}_query_log
                 WHERE update_date BETWEEN %s AND %s
                 ORDER BY update_date DESC
                 LIMIT %d",
				$start_date,
				$end_date,
				$limit
			);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
			return $wpdb->get_results( $sql, ARRAY_A );
		} else {
			// 条件なし（最新のものを上限数だけ取得）
			$sql = $wpdb->prepare(
				"SELECT query_id, keyword, update_date
                 FROM {$wpdb->prefix}qa_gsc_{$tracking_id}_query_log
                 ORDER BY update_date DESC
                 LIMIT %d",
				$limit
			);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
			return $wpdb->get_results( $sql, ARRAY_A );
		}
	}

	/**
	 * リーダー情報の取得
	 *
	 * @param int|array $reader_id リーダーID（指定時はqa_idは無視）
	 * @param string|array $qa_id クライアントID（単一または配列）
	 * @param string $start_date 開始日（Y-m-d形式）
	 * @param string $end_date 終了日（Y-m-d形式）
	 * @param int $limit 取得件数上限（reader_idが配列か、qa_idが配列の場合のみ有効）
	 * @return array|null リーダー情報
	 */
	public static function get_qa_readers( $reader_id = null, $qa_id = null, $start_date = null, $end_date = null, $limit = 100 ) {
		global $wpdb;

		if ( is_numeric( $reader_id ) ) {
			// 単一リーダーID指定
			$sql = $wpdb->prepare(
				"SELECT reader_id, qa_id, original_id, UAos, UAbrowser, language, country_code, is_reject, update_date
                 FROM {$wpdb->prefix}qa_readers
                 WHERE reader_id = %d",
				$reader_id
			);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
			return $wpdb->get_row( $sql, ARRAY_A );
		} elseif ( is_array( $reader_id ) && ! empty( $reader_id ) ) {
			// 複数リーダーID指定
			$placeholders = static::wrap_implode_static( ',', array_fill( 0, static::wrap_count_static( $reader_id ), '%d' ) );
			$sql          = $wpdb->prepare(
				"SELECT reader_id, qa_id, original_id, UAos, UAbrowser, language, country_code, is_reject, update_date
                 FROM {$wpdb->prefix}qa_readers
                 WHERE reader_id IN ($placeholders)
                 ORDER BY reader_id DESC
                 LIMIT %d",
				static::wrap_array_merge_static( $reader_id, array( $limit ) )
			);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
			return $wpdb->get_results( $sql, ARRAY_A );
		} elseif ( is_array( $qa_id ) && ! empty( $qa_id ) ) {
			// 複数クライアントID指定
			$placeholders  = static::wrap_implode_static( ',', array_fill( 0, static::wrap_count_static( $qa_id ), '%s' ) );
			$dateCondition = '';
			if ( $start_date && $end_date ) {
				$dateCondition = $wpdb->prepare( ' AND update_date BETWEEN %s AND %s', $start_date, $end_date );
			}

			$sql = $wpdb->prepare(
				"SELECT reader_id, qa_id, original_id, UAos, UAbrowser, language, country_code, is_reject, update_date
                 FROM {$wpdb->prefix}qa_readers
                 WHERE qa_id IN ($placeholders)
                 $dateCondition
                 ORDER BY reader_id DESC
                 LIMIT %d",
				static::wrap_array_merge_static( $qa_id, array( $limit ) )
			);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
			return $wpdb->get_results( $sql, ARRAY_A );
		} elseif ( $qa_id ) {
			// 単一クライアントID指定
			$where = $wpdb->prepare( 'WHERE qa_id = %s', $qa_id );

			if ( $start_date && $end_date ) {
				$where .= $wpdb->prepare( ' AND update_date BETWEEN %s AND %s', $start_date, $end_date );
			}

			return $wpdb->get_row(
				"
                SELECT reader_id, qa_id, original_id, UAos, UAbrowser, language, country_code, is_reject, update_date
                FROM `{$wpdb->prefix}qa_readers`
                {$where}
                ORDER BY reader_id DESC
                LIMIT 1
                ",
				ARRAY_A
			);

		} else {
			// 条件なし（最新のものを上限数だけ取得）
			$dateCondition = '';
			if ( $start_date && $end_date ) {
				$dateCondition = $wpdb->prepare( 'WHERE update_date BETWEEN %s AND %s', $start_date, $end_date );
			}

			$sql = $wpdb->prepare(
				"SELECT reader_id, qa_id, original_id, UAos, UAbrowser, language, country_code, is_reject, update_date
                 FROM {$wpdb->prefix}qa_readers
                 $dateCondition
                 ORDER BY reader_id DESC
                 LIMIT %d",
				$limit
			);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
			return $wpdb->get_results( $sql, ARRAY_A );
		}
	}


	/**
	 * ページバージョン履歴の取得
	 *
	 * @param int|array $version_id バージョンID（指定時は他の条件は無視）
	 * @param int|array $page_id ページID（単一または配列）
	 * @param int|array $device_id デバイスID (1=desktop, 2=tablet, 3=mobile)（単一または配列）
	 * @param string $start_date 開始日（Y-m-d形式）
	 * @param string $end_date 終了日（Y-m-d形式）
	 * @param bool $include_content コンテンツ(base_html, base_selector)を含めるか
	 * @param int $limit 取得件数上限
	 * @return array ページバージョン履歴データ
	 */
	public static function get_qa_page_version_hist( $version_id = null, $page_id = null, $device_id = null, $start_date = null, $end_date = null, $include_content = false, $limit = 100 ) {
		global $wpdb;

		// 取得するフィールド
		$fields = 'version_id, page_id, device_id, version_no, update_date, insert_datetime';
		if ( $include_content ) {
			$fields .= ', base_html, base_selector';
		}

		// バージョンID指定がある場合は他の条件は無視
		if ( is_numeric( $version_id ) ) {
			// 単一バージョンID指定
			$sql = $wpdb->prepare(
				"SELECT $fields
                FROM {$wpdb->prefix}qa_page_version_hist
                WHERE version_id = %d",
				$version_id
			);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
			return $wpdb->get_row( $sql, ARRAY_A );
		} elseif ( is_array( $version_id ) && ! empty( $version_id ) ) {
			// 複数バージョンID指定
			$placeholders = static::wrap_implode_static( ',', array_fill( 0, static::wrap_count_static( $version_id ), '%d' ) );
			$sql          = $wpdb->prepare(
				"SELECT $fields
                FROM {$wpdb->prefix}qa_page_version_hist
                WHERE version_id IN ($placeholders)
                ORDER BY update_date DESC, version_id DESC",
				$version_id
			);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
			return $wpdb->get_results( $sql, ARRAY_A );
		}

		// WHERE句の条件を構築
		$conditions     = array();
		$prepare_values = array();

		// page_id条件
		if ( is_numeric( $page_id ) ) {
			$conditions[]     = 'page_id = %d';
			$prepare_values[] = $page_id;
		} elseif ( is_array( $page_id ) && ! empty( $page_id ) ) {
			$placeholders   = static::wrap_implode_static( ',', array_fill( 0, static::wrap_count_static( $page_id ), '%d' ) );
			$conditions[]   = "page_id IN ($placeholders)";
			$prepare_values = static::wrap_array_merge_static( $prepare_values, $page_id );
		}

		// device_id条件
		if ( is_numeric( $device_id ) ) {
			$conditions[]     = 'device_id = %d';
			$prepare_values[] = $device_id;
		} elseif ( is_array( $device_id ) && ! empty( $device_id ) ) {
			$placeholders   = static::wrap_implode_static( ',', array_fill( 0, static::wrap_count_static( $device_id ), '%d' ) );
			$conditions[]   = "device_id IN ($placeholders)";
			$prepare_values = static::wrap_array_merge_static( $prepare_values, $device_id );
		}

		// 日付範囲条件
		if ( $start_date && $end_date ) {
			$conditions[]     = 'update_date BETWEEN %s AND %s';
			$prepare_values[] = $start_date;
			$prepare_values[] = $end_date;
		}

		// WHERE句の構築
		$where = '';
		if ( ! empty( $conditions ) ) {
			$where = 'WHERE ' . static::wrap_implode_static( ' AND ', $conditions );
		}

		// 並び順とリミット
		$prepare_values[] = $limit;

		// SQLの実行
		$sql = $wpdb->prepare(
			"SELECT $fields
            FROM {$wpdb->prefix}qa_page_version_hist
            $where
            ORDER BY update_date DESC, version_id DESC
            LIMIT %d",
			$prepare_values
		);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Verified prepared via $wpdb->prepare; placeholders match; no post-prepare mutation; identifiers fixed/whitelisted. (important-comment)
		return $wpdb->get_results( $sql, ARRAY_A );
	}
}
