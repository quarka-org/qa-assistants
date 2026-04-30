<?php
defined( 'ABSPATH' ) || exit;
/**
 * QA ZERO のファイル関数クラス
 *
 * 各データファイルへのアクセスと処理のための関数を提供します。
 * このクラスはアクセス解析データを管理する各種ファイルを扱います。
 *
 * ## 主要ID・パラメータの説明
 *
 * - tracking_id: サイトを識別するためのID
 * - page_id: 各ページを一意に識別するID
 * - device_id: デバイスタイプ (1:desktop, 2:tablet, 3:mobile)
 * - source_id: トラフィックのソース（参照元）を識別するID
 * - medium_id: メディアタイプを識別するID (organic, cpc, referral など)
 * - campaign_id: マーケティングキャンペーンを識別するID
 * - version_id: ヒートマップのバージョンを識別するID
 * - is_newuser: 新規ユーザーかどうかのフラグ (1:新規, 0:リピート)
 * - is_QA: QAデータかどうかのフラグ
 *
 * ## ファイル構造
 *
 * データファイルは以下の構造で保存されています：
 * {data_dir}/{tracking_id}/[view|summary|gsc|goal]/[ファイル種別]/[ファイル名]
 *
 * - view_pv: 各PVの詳細データ
 * - summary_days_access: 日別アクセス概要
 * - summary_days_access_detail_i: 詳細アクセスデータの累計（1月1日から対象日まで）
 * - summary_allpage_i: 全ページデータの累計
 * - summary_landingpage_i: ランディングページデータの累計
 * - gsc_lp_query: Search Consoleのランディングページ×クエリデータ
 * - summary_event: イベントデータ（クリック、フォーム入力など）
 * - goal: コンバージョンセッションデータ
 * - summary_ec_item: ECサイトの商品データ
 */

$GLOBALS['qahm_file_functions'] = new QAHM_File_Functions();
class QAHM_File_Functions extends QAHM_File_Base {

	/**
	 * データディレクトリのパス
	 *
	 * @var string
	 */
	private $data_dir;

	/**
	 * コンストラクタ
	 *
	 * @param string $data_dir データディレクトリのパス（デフォルト: WordPress content directory + 'qa-zero-data'）
	 */
	public function __construct( $data_dir = null ) {
		if ( $data_dir === null ) {
			$this->data_dir = WP_CONTENT_DIR . '/qa-zero-data';
		} else {
			$this->data_dir = $data_dir;
		}
	}

	/**
	 * view_pv データを取得
	 *
	 * @param string $tracking_id トラッキングID
	 * @param string $start_datetime 開始日時 (Y-m-d H:i:s)
	 * @param string $end_datetime 終了日時 (Y-m-d H:i:s)
	 * @param array $options 追加オプション
	 * @return array 結果データの配列。以下の構造を持つ：
	 *     [
	 *         [
	 *             'pv_id' => (int) PV ID,
	 *             'reader_id' => (int) リーダーID,
	 *             'UAos' => (string) OSユーザーエージェント,
	 *             'UAbrowser' => (string) ブラウザユーザーエージェント,
	 *             'language' => (string) 言語コード,
	 *             'country_code' => (string) 国コード,
	 *             'page_id' => (int) ページID,
	 *             'url' => (string) URL,
	 *             'title' => (string) ページタイトル,
	 *             'device_id' => (int) デバイスID (1:desktop, 2:tablet, 3:mobile),
	 *             'source_id' => (int) ソースID,
	 *             'utm_source' => (string) UTMソース,
	 *             'source_domain' => (string) ソースドメイン,
	 *             'medium_id' => (int) メディアID,
	 *             'utm_medium' => (string) UTMメディア,
	 *             'campaign_id' => (int) キャンペーンID,
	 *             'session_no' => (int) セッション番号,
	 *             'access_time' => (string) アクセス時間,
	 *             'pv' => (int) PVカウント,
	 *             'speed_msec' => (int) ページ読み込み速度(ミリ秒),
	 *             'browse_sec' => (int) 閲覧時間(秒),
	 *             'is_last' => (int) 最終PVフラグ,
	 *             'is_newuser' => (int) 新規ユーザーフラグ,
	 *             'version_id' => (int) バージョンID,
	 *             'is_raw_p' => (int) ポジションRAWデータフラグ,
	 *             'is_raw_c' => (int) クリックRAWデータフラグ,
	 *             'is_raw_e' => (int) イベントRAWデータフラグ
	 *         ],
	 *         ...
	 *     ]
	 */
	public function get_view_pv( $tracking_id, $start_datetime, $end_datetime, $options = array() ) {
		// PV範囲を特定
		$start_time = strtotime( $start_datetime );
		$end_time   = strtotime( $end_datetime );

		if ( ! $start_time || ! $end_time ) {
			return array();
		}

		// PV ID の範囲を特定
		$min_pv_id = $this->get_min_pv_id_for_date( $tracking_id, $start_time );
		$max_pv_id = $this->get_max_pv_id_for_date( $tracking_id, $end_time );

		if ( ! $min_pv_id || ! $max_pv_id ) {
			return array();
		}

		// view_pv ファイルの取得
		$view_pv_files = $this->get_view_pv_files( $tracking_id, $min_pv_id, $max_pv_id );

		// データを取得して結合
		$results = array();
		foreach ( $view_pv_files as $file ) {
			$file_data = $this->load_file( $file );
			if ( ! $file_data ) {
				continue;
			}

			// 日時フィルタリング
			foreach ( $file_data as $pv_data ) {
				if ( ! isset( $pv_data['access_time'] ) ) {
					continue;
				}

				// access_timeが文字列の場合はstrtimeで変換
				if ( is_string( $pv_data['access_time'] ) ) {
					$pv_time = strtotime( $pv_data['access_time'] );
				} else {
					$pv_time = (int) $pv_data['access_time'];
				}

				if ( $pv_time >= $start_time && $pv_time <= $end_time ) {
					$results[] = $pv_data;
				}
			}
		}

		return $results;
	}

	/**
	 * summary_days_access データを取得
	 * 日々のアクセス数を表示するための中間データ
	 *
	 * @param string $tracking_id トラッキングID
	 * @param string $start_date 開始日 (Y-m-d)
	 * @param string $end_date 終了日 (Y-m-d)
	 * @return array 日付をキーとする連想配列。以下の構造を持つ：
	 *     [
	 *         'YYYY-MM-DD' => [
	 *             'date' => (string) 日付,
	 *             'pv_count' => (int) PV数,
	 *             'session_count' => (int) セッション数,
	 *             'user_count' => (int) ユーザー数
	 *         ],
	 *         ...
	 *     ]
	 */
	public function get_summary_days_access( $tracking_id, $start_date, $end_date ) {
		$file_path = $this->data_dir . "/view/{$tracking_id}/summary/days_access.php";
		$data      = $this->load_file( $file_path );

		if ( ! $data ) {
			return array();
		}

		// 日付範囲でフィルタリング
		$start_time = strtotime( $start_date );
		$end_time   = strtotime( $end_date );

		$filtered_data = array();
		foreach ( $data as $index => $day_data ) {
			if ( is_array( $day_data ) && isset( $day_data['date'] ) ) {
				$day_time = strtotime( $day_data['date'] );
				if ( $day_time >= $start_time && $day_time <= $end_time ) {
					$filtered_data[] = $day_data;
				}
			}
		}

		return $filtered_data;
	}

	/**
	 * 指定日付の最小PV IDを取得
	 *
	 * @param string $tracking_id トラッキングID
	 * @param int $timestamp UNIXタイムスタンプ
	 * @return int|null 最小PV ID
	 */
	private function get_min_pv_id_for_date( $tracking_id, $timestamp ) {
		$data_dir   = $this->get_data_dir_path();
		$view_dir   = $data_dir . 'view/';
		$viewpv_dir = $view_dir . $tracking_id . '/view_pv/';

		$target_date = gmdate( 'Y-m-d', $timestamp );
		$files       = $this->wrap_dirlist( $viewpv_dir );

		$min_pv_id = null;
		foreach ( $files as $file ) {
			$filename  = $file['name'];
			$file_date = $this->wrap_substr( $filename, 0, 10 );

			// 対象日付以降のファイルを処理
			if ( $file_date >= $target_date && preg_match( '/\\d{4}-\\d{2}-\\d{2}_(\\d+)-(\\d+)_viewpv\\.php/', $filename, $matches ) ) {
				$file_min_pv_id = (int) $matches[1];
				if ( $min_pv_id === null || $file_min_pv_id < $min_pv_id ) {
					$min_pv_id = $file_min_pv_id;
				}
			}
		}

		return $min_pv_id;
	}

	/**
	 * 指定日付の最大PV IDを取得
	 *
	 * @param string $tracking_id トラッキングID
	 * @param int $timestamp UNIXタイムスタンプ
	 * @return int|null 最大PV ID
	 */
	private function get_max_pv_id_for_date( $tracking_id, $timestamp ) {
		$data_dir   = $this->get_data_dir_path();
		$view_dir   = $data_dir . 'view/';
		$viewpv_dir = $view_dir . $tracking_id . '/view_pv/';

		$target_date = gmdate( 'Y-m-d', $timestamp );
		$files       = $this->wrap_dirlist( $viewpv_dir );

		$max_pv_id = null;
		foreach ( $files as $file ) {
			$filename  = $file['name'];
			$file_date = $this->wrap_substr( $filename, 0, 10 );

			// 対象日付以前のファイルを処理
			if ( $file_date <= $target_date && preg_match( '/\\d{4}-\\d{2}-\\d{2}_(\\d+)-(\\d+)_viewpv\\.php/', $filename, $matches ) ) {
				$file_max_pv_id = (int) $matches[2];
				if ( $max_pv_id === null || $file_max_pv_id > $max_pv_id ) {
					$max_pv_id = $file_max_pv_id;
				}
			}
		}

		return $max_pv_id;
	}

	/**
	 * 指定PV ID範囲のview_pvファイルパスを取得
	 *
	 * @param string $tracking_id トラッキングID
	 * @param int $min_pv_id 最小PV ID
	 * @param int $max_pv_id 最大PV ID
	 * @return array ファイルパスの配列
	 */
	private function get_view_pv_files( $tracking_id, $min_pv_id, $max_pv_id ) {
		$view_pv_dir = $this->data_dir . "/view/{$tracking_id}/view_pv/";

		if ( ! is_dir( $view_pv_dir ) ) {
			return array();
		}

		$files    = array();
		$dir_list = $this->wrap_dirlist( $view_pv_dir );

		if ( ! $dir_list ) {
			return array();
		}

		foreach ( $dir_list as $file_obj ) {
			$filename = $file_obj['name'];
			if ( ! is_file( $view_pv_dir . $filename ) ) {
				continue;
			}

			if ( preg_match( '/^\d{4}-\d{2}-\d{2}_(\d+)-(\d+)_viewpv\.php$/', $filename, $matches ) ) {
				$file_min_id = (int) $matches[1];
				$file_max_id = (int) $matches[2];

				if ( $file_max_id >= $min_pv_id && $file_min_id <= $max_pv_id ) {
					$files[] = $view_pv_dir . $filename;
				}
			}
		}
		sort( $files );

		return $files;
	}

	/**
	 * summary_days_access_detail_i データを取得
	 * 詳細なアクセス集計の累計データ（1月1日からの累計）
	 *
	 * @param string $tracking_id トラッキングID
	 * @param string $start_date 開始日 (Y-m-d)
	 * @param string $end_date 終了日 (Y-m-d)
	 * @return array 日付をキーとする連想配列。以下の構造を持つ多次元配列：
	 *     [
	 *         'YYYY-MM-DD' => [
	 *             {device_id} => [
	 *                 {source_id} => [
	 *                     {medium_id} => [
	 *                         {campaign_id} => [
	 *                             {is_newuser} => [
	 *                                 {is_QA} => [
	 *                                     'pv_count' => (int) PV数,
	 *                                     'session_count' => (int) セッション数,
	 *                                     'user_count' => (int) ユーザー数,
	 *                                     'bounce_count' => (int) 直帰数,
	 *                                     'time_on_page' => (int) 滞在時間(秒),
	 *                                     'is_newuser_count' => (int) 新規ユーザー数
	 *                                 ]
	 *                             ]
	 *                         ]
	 *                     ]
	 *                 ]
	 *             ]
	 *         ],
	 *         ...
	 *     ]
	 */
	public function get_summary_days_access_detail_i( $tracking_id, $start_date, $end_date ) {
		// 日付ごとにファイルを取得
		$current_date = new DateTime( $start_date );
		$end_date_obj = new DateTime( $end_date );

		$results = array();

		while ( $current_date <= $end_date_obj ) {
			$date_str  = $current_date->format( 'Y-m-d' );
			$file_path = $this->data_dir . "/view/{$tracking_id}/summary/{$date_str}_summary_days_access_detail_i.php";

			$file_data = $this->load_file( $file_path );
			if ( $file_data ) {
				$results[ $date_str ] = $file_data;
			}

			$current_date->modify( '+1 day' );
		}

		return $results;
	}

	/**
	 * summary_allpage_i データを取得
	 * 全ページの累計データ（1月1日からの累計）
	 *
	 * @param string $tracking_id トラッキングID
	 * @param string $start_date 開始日 (Y-m-d)
	 * @param string $end_date 終了日 (Y-m-d)
	 * @return array 多次元配列。以下の構造を持つ：
	 *     [
	 *         {page_id} => [
	 *             {device_id} => [
	 *                 {source_id} => [
	 *                     {medium_id} => [
	 *                         {campaign_id} => [
	 *                             {is_newuser} => [
	 *                                 {is_QA} => [
	 *                                     'pv_count' => (int) PV数,
	 *                                     'user_count' => (int) ユーザー数,
	 *                                     'bounce_count' => (int) 直帰数,
	 *                                     'exit_count' => (int) 離脱数,
	 *                                     'time_on_page' => (int) 滞在時間(秒),
	 *                                     'lp_count' => (int) ランディングページ数
	 *                                 ]
	 *                             ]
	 *                         ]
	 *                     ]
	 *                 ]
	 *             ]
	 *         ],
	 *         ...
	 *     ]
	 */
	public function get_summary_allpage_i( $tracking_id, $start_date, $end_date ) {
		// 累計データを取得
		$end_date_obj = new DateTime( $end_date );
		$end_date_str = $end_date_obj->format( 'Y-m-d' );

		$file_path = $this->data_dir . "/view/{$tracking_id}/summary/{$end_date_str}_summary_allpage_i.php";

		return $this->load_file( $file_path );
	}

	/**
	 * summary_landingpage_i データを取得
	 * ランディングページの累計データ（1月1日からの累計）
	 *
	 * @param string $tracking_id トラッキングID
	 * @param string $start_date 開始日 (Y-m-d)
	 * @param string $end_date 終了日 (Y-m-d)
	 * @return array 多次元配列。以下の構造を持つ：
	 *     [
	 *         {page_id} => [  // ランディングページID
	 *             {device_id} => [
	 *                 {source_id} => [
	 *                     {medium_id} => [
	 *                         {campaign_id} => [
	 *                             {is_newuser} => [
	 *                                 {second_page} => [  // 2ページ目のID、直帰の場合は0
	 *                                     {is_QA} => [
	 *                                         'pv_count' => (int) PV数,
	 *                                         'session_count' => (int) セッション数,
	 *                                         'user_count' => (int) ユーザー数,
	 *                                         'bounce_count' => (int) 直帰数,
	 *                                         'session_time' => (int) セッション時間(秒)
	 *                                     ]
	 *                                 ]
	 *                             ]
	 *                         ]
	 *                     ]
	 *                 ]
	 *             ]
	 *         ],
	 *         ...
	 *     ]
	 */
	public function get_summary_landingpage_i( $tracking_id, $start_date, $end_date ) {
		// 累計データを取得
		$end_date_obj = new DateTime( $end_date );
		$end_date_str = $end_date_obj->format( 'Y-m-d' );

		$file_path = $this->data_dir . "/view/{$tracking_id}/summary/{$end_date_str}_summary_landingpage_i.php";

		return $this->load_file( $file_path );
	}

	/**
	 * _lp_query データを取得
	 * サーチコンソールから取得したLPごとのクエリデータ
	 *
	 * @param string $tracking_id トラッキングID
	 * @param string $start_date 開始日 (Y-m-d)
	 * @param string $end_date 終了日 (Y-m-d)
	 * @return array 多次元配列。以下の構造を持つ：
	 *     [
	 *         [
	 *             'page_id' => (int) ページID,
	 *             'title' => (string) ページタイトル,
	 *             'url' => (string) ページURL,
	 *             'search_type' => (int) 検索タイプ,
	 *             'keyword' => (string) キーワード,
	 *             'clicks_sum' => (int) Σclicks,
	 *             'impressions_sum' => (int) Σimpressions,
	 *             'ctr' => (float) clicks_sum / impressions_sum,
	 *             'position_wavg' => (float) 加重平均position,
	 *             'first_position' => (float) 期間内最初のposition,
	 *             'latest_position' => (float) 期間内最新のposition,
	 *             'position_history' => (string) 日別position変遷（CSV形式）
	 *         ],
	 *         // ... 全組み合わせのレコード
	 *     ]
	 */
	public function get_gsc_lp_query( $tracking_id, $start_date, $end_date ) {
		$start_date_obj = new DateTime( $start_date );
		$end_date_obj   = new DateTime( $end_date );
		$current_date   = clone $start_date_obj;

		$flattened_data  = array();
		$aggregated_data = array();

		while ( $current_date <= $end_date_obj ) {
			$date_str  = $current_date->format( 'Y-m-d' );
			$file_path = $this->data_dir . "/view/{$tracking_id}/gsc/{$date_str}_gsc_lp_query.php";

			if ( ! $this->wrap_exists( $file_path ) ) {
				$current_date->modify( '+1 day' );
				continue;
			}

			$data = $this->load_file( $file_path );

			if ( $data === false || $data === null ) {
				$current_date->modify( '+1 day' );
				continue;
			}

			if ( $data && is_array( $data ) ) {
				foreach ( $data as $page_data ) {
					if ( ! isset( $page_data['page_id'] ) || ! isset( $page_data['query'] ) ) {
						continue;
					}

					$page_id = $page_data['page_id'];
					$title   = isset( $page_data['title'] ) ? $page_data['title'] : '';
					$url     = isset( $page_data['url'] ) ? $page_data['url'] : '';

					foreach ( $page_data['query'] as $query_data ) {
						if ( ! isset( $query_data['keyword'] ) || ! isset( $query_data['search_type'] ) ) {
							continue;
						}

						$keyword     = $query_data['keyword'];
						$search_type = (int) $query_data['search_type'];
						$clicks      = isset( $query_data['clicks'] ) ? (int) $query_data['clicks'] : 0;
						$impressions = isset( $query_data['impressions'] ) ? (int) $query_data['impressions'] : 0;
						$position    = isset( $query_data['position'] ) ? (float) $query_data['position'] : 0.0;

						$key = $page_id . '_' . $search_type . '_' . md5( $keyword );

						if ( ! isset( $aggregated_data[ $key ] ) ) {
							$aggregated_data[ $key ] = array(
								'page_id'         => $page_id,
								'title'           => $title,
								'url'             => $url,
								'search_type'     => $search_type,
								'keyword'         => $keyword,
								'clicks_sum'      => 0,
								'impressions_sum' => 0,
								'position_data'   => array(),
							);
						}

						$aggregated_data[ $key ]['clicks_sum']      += $clicks;
						$aggregated_data[ $key ]['impressions_sum'] += $impressions;

						if ( $impressions > 0 ) {
							$aggregated_data[ $key ]['position_data'][] = array(
								'position'    => $position,
								'impressions' => $impressions,
								'date'        => $date_str,
							);
						}
					}
				}
			}

			$current_date->modify( '+1 day' );
		}

		if ( empty( $aggregated_data ) ) {
			return false;
		}

		foreach ( $aggregated_data as $key => $data ) {
			$clicks_sum      = $data['clicks_sum'];
			$impressions_sum = $data['impressions_sum'];

			$ctr = ( $impressions_sum > 0 ) ? round( $clicks_sum / $impressions_sum, 4 ) : 0.0;

			$position_wavg    = null;
			$first_position   = null;
			$latest_position  = null;
			$position_history = '';

			if ( ! empty( $data['position_data'] ) ) {
				usort(
					$data['position_data'],
					function ( $a, $b ) {
						return strcmp( $a['date'], $b['date'] );
					}
				);

				$total_weighted_position    = 0;
				$total_impressions_for_wavg = 0;

				foreach ( $data['position_data'] as $pos_data ) {
					$total_weighted_position    += $pos_data['position'] * $pos_data['impressions'];
					$total_impressions_for_wavg += $pos_data['impressions'];
				}

				if ( $total_impressions_for_wavg > 0 ) {
					$position_wavg = round( $total_weighted_position / $total_impressions_for_wavg, 2 );
				}

				$first_position  = $data['position_data'][0]['position'];
				$latest_position = end( $data['position_data'] )['position'];

				$position_by_date = array();
				foreach ( $data['position_data'] as $pos_data ) {
					$position_by_date[ $pos_data['date'] ] = $pos_data['position'];
				}

				$history_array        = array();
				$current_history_date = clone $start_date_obj;
				while ( $current_history_date <= $end_date_obj ) {
					$date_str = $current_history_date->format( 'Y-m-d' );
					if ( isset( $position_by_date[ $date_str ] ) ) {
						$history_array[] = $position_by_date[ $date_str ];
					} else {
						$history_array[] = '';
					}
					$current_history_date->modify( '+1 day' );
				}

				$position_history = '"' . $this->wrap_implode( ',', $history_array ) . '"';
			}

			$flattened_data[] = array(
				'page_id'          => $data['page_id'],
				'title'            => $data['title'],
				'url'              => $data['url'],
				'search_type'      => $data['search_type'],
				'keyword'          => $data['keyword'],
				'clicks_sum'       => $clicks_sum,
				'impressions_sum'  => $impressions_sum,
				'ctr'              => $ctr,
				'position_wavg'    => $position_wavg,
				'first_position'   => $first_position,
				'latest_position'  => $latest_position,
				'position_history' => $position_history,
			);
		}

		return $flattened_data;
	}

	/**
	 * summary_event データを取得
	 * イベントデータ（クリックやフォームフォーカスなど）
	 *
	 * @param string $tracking_id トラッキングID
	 * @param string $start_date 開始日 (Y-m-d)
	 * @param string $end_date 終了日 (Y-m-d)
	 * @return array 日付をキーとする連想配列。以下の構造を持つ：
	 *     [
	 *         'YYYY-MM-DD' => [
	 *             {page_id} => [
	 *                 {version_id} => [
	 *                     'event' => [
	 *                         [
	 *                             'cv_type' => (string) イベントタイプ ('c':クリック, 'p':動画再生, 'i':フォーカスイン, 'o':フォーカスアウト),
	 *                             'selector' => (string) セレクタ文字列,
	 *                             'pv_id' => (array) 該当のPV ID配列,
	 *                             // cv_type が 'c' の場合
	 *                             'url' => (string) 遷移先URL
	 *                         ],
	 *                         ...
	 *                     ]
	 *                 ]
	 *             ]
	 *         ],
	 *         ...
	 *     ]
	 */
	public function get_summary_event( $tracking_id, $start_date, $end_date ) {
		// 日付ごとにファイルを取得
		$current_date = new DateTime( $start_date );
		$end_date_obj = new DateTime( $end_date );

		$results = array();

		while ( $current_date <= $end_date_obj ) {
			$date_str  = $current_date->format( 'Y-m-d' );
			$file_path = $this->data_dir . "/view/{$tracking_id}/summary/{$date_str}_summary_event.php";

			$file_data = $this->load_file( $file_path );
			if ( $file_data ) {
				$results[ $date_str ] = $file_data;
			}

			$current_date->modify( '+1 day' );
		}

		return $results;
	}

	/**
	 * goal データを取得
	 * 目標達成した (コンバージョンした) セッションデータ
	 *
	 * @param string $tracking_id トラッキングID
	 * @param string $start_date 開始日 (Y-m-d)
	 * @param string $end_date 終了日 (Y-m-d)
	 * @param int $goal_id ゴールID
	 * @return array セッションの配列。以下の構造を持つ：
	 *     [
	 *         [  // セッション1
	 *             [  // PV1
	 *                 'pv_id' => (int) PV ID,
	 *                 'reader_id' => (int) リーダーID,
	 *                 'UAos' => (string) OSユーザーエージェント,
	 *                 'UAbrowser' => (string) ブラウザユーザーエージェント,
	 *                 'language' => (string) 言語,
	 *                 'is_reject' => (int) リジェクトフラグ,
	 *                 'page_id' => (int) ページID,
	 *                 'url' => (string) URL,
	 *                 'title' => (string) ページタイトル,
	 *                 'access_time' => (int) アクセスタイムスタンプ,
	 *                 'device_id' => (int) デバイスID,
	 *                 'version_id' => (int) バージョンID,
	 *                 'source_id' => (int) ソースID,
	 *                 'utm_source' => (string) UTMソース,
	 *                 'source_domain' => (string) 参照元ドメイン,
	 *                 'medium_id' => (int) メディアID,
	 *                 'utm_medium' => (string) UTMメディア,
	 *                 'campaign_id' => (int) キャンペーンID,
	 *                 'utm_campaign' => (string) UTMキャンペーン,
	 *                 'session_no' => (int) セッション番号,
	 *                 'pv' => (int) PVカウント,
	 *                 'speed_msec' => (int) ページ読み込み速度(ミリ秒),
	 *                 'browse_sec' => (int) 閲覧時間(秒),
	 *                 'is_last' => (int) 最終PVフラグ,
	 *                 'is_newuser' => (int) 新規ユーザーフラグ,
	 *                 'is_raw_p' => (int) ポジションRAWデータフラグ,
	 *                 'is_raw_c' => (int) クリックRAWデータフラグ,
	 *                 'is_raw_e' => (int) イベントRAWデータフラグ,
	 *                 'version_no' => (int) バージョン番号
	 *             ],
	 *             ...  // PV2, PV3, ...
	 *         ],
	 *         ...  // セッション2, セッション3, ...
	 *     ]
	 */
	public function get_goal( $tracking_id, $start_date, $end_date, $goal_id ) {
		// 月ごとにファイルを取得
		$current_date = new DateTime( $start_date );
		$end_date_obj = new DateTime( $end_date );

		$results = array();

		while ( $current_date <= $end_date_obj ) {
			$month_start = $current_date->format( 'Y-m-01' );
			$file_path   = $this->data_dir . "/view/{$tracking_id}/summary/{$month_start}_goal_{$goal_id}_1mon.php";

			$file_data = $this->load_file( $file_path );
			if ( $file_data ) {
				// 日付範囲でフィルタリング
				foreach ( $file_data as $session_data ) {
					foreach ( $session_data as $pv_data ) {
						$pv_time = $pv_data['access_time'];
						$pv_date = gmdate( 'Y-m-d', $pv_time );

						if ( $pv_date >= $start_date && $pv_date <= $end_date ) {
							$results[] = $session_data;
							break; // このセッションは既に追加したので次へ
						}
					}
				}
			}

			$current_date->modify( '+1 month' );
		}

		return $results;
	}

	/**
	 * summary_ec_item データを取得
	 * ECサイトの商品データ
	 *
	 * @param string $tracking_id トラッキングID
	 * @param string $start_date 開始日 (Y-m-d)
	 * @param string $end_date 終了日 (Y-m-d)
	 * @return array 日付をキーとする連想配列。以下の構造を持つ：
	 *     [
	 *         'YYYY-MM-DD' => [
	 *             [
	 *                 'product_name' => (string) 商品名,
	 *                 'product_category' => (string) 商品カテゴリ,
	 *                 'device_id' => (int) デバイスID,
	 *                 'medium_id' => (int) メディアID,
	 *                 'source_id' => (int) ソースID,
	 *                 'campaign_id' => (int) キャンペーンID,
	 *                 'is_newuser' => (int) 新規ユーザーフラグ,
	 *                 'lp_page_id' => (int) ランディングページID,
	 *                 'quantity' => (int) 数量
	 *             ],
	 *             ...
	 *         ],
	 *         ...
	 *     ]
	 */
	public function get_summary_ec_item( $tracking_id, $start_date, $end_date ) {
		// 日付ごとにファイルを取得
		$current_date = new DateTime( $start_date );
		$end_date_obj = new DateTime( $end_date );

		$results = array();

		while ( $current_date <= $end_date_obj ) {
			$date_str  = $current_date->format( 'Y-m-d' );
			$file_path = $this->data_dir . "/view/{$tracking_id}/summary/{$date_str}_summary_ec_item.php";

			$file_data = $this->load_file( $file_path );
			if ( $file_data ) {
				$results[ $date_str ] = $file_data;
			}

			$current_date->modify( '+1 day' );
		}

		return $results;
	}

	/**
	 * 積分形式のサマリーファイルの差分、増分を計算する
	 * 多次元配列の各レベルで再帰的に処理を行う
	 *
	 * @param array $data1 基準となるデータ（開始日のデータなど）
	 * @param array $data2 比較するデータ（終了日のデータなど）
	 * @param string $operation 操作タイプ ('add'=加算, 'subtract'=減算)
	 * @return array 計算結果
	 */
	private function calculate_integral_summary( $data1, $data2, $operation = 'add' ) {
		$result = array();

		// まず $data1 をループして、$data1 にのみ存在するキーを追加
		foreach ( $data1 as $key => $value ) {
			if ( ! $this->wrap_array_key_exists( $key, $data2 ) ) {
				$result[ $key ] = $value;
			}
		}

		// 次に $data2 をループして、$data1 に存在するキーの処理と $data2 にのみ存在するキーを追加
		foreach ( $data2 as $key => $value ) {
			if ( $this->wrap_array_key_exists( $key, $data1 ) ) {
				if ( is_array( $value ) ) {
					$result[ $key ] = $this->calculate_integral_summary( $data1[ $key ], $value, $operation );
				} else {
					if ( $operation === 'subtract' ) {
						$result[ $key ] = $value - $data1[ $key ];
					} else {
						$result[ $key ] = $data1[ $key ] + $value;
					}
				}
			} else {
				$result[ $key ] = $value;
			}
		}

		return $result;
	}

	/**
	 * 期間指定でのサマリーデータの集計を行う
	 * 年をまたぐ場合にも対応し、_iファイルの差分を計算する
	 *
	 * @param string $start_date 開始日 (Y-m-d)
	 * @param string $end_date 終了日 (Y-m-d)
	 * @param string $summary_type サマリータイプ ('allpage', 'landingpage', 'days_access_detail' など)
	 * @param string $tracking_id トラッキングID ('all'の場合は全トラッキング対象)
	 * @return array 集計結果
	 */
	private function calc_summary_dateterm_total( $start_date, $end_date, $summary_type, $tracking_id = 'all' ) {
		$tracking_id = $this->get_safe_tracking_id( $tracking_id );

		$date_format    = 'Y-m-d';
		$view_dir       = $this->data_dir . '/view/';
		$vw_summary_dir = $view_dir . $tracking_id . '/summary/';

		// 日付フォーマットに基づいてDateTimeオブジェクトを作成
		$start_date_obj = DateTime::createFromFormat( 'Y-m-d', $start_date );
		$end_date_obj   = DateTime::createFromFormat( 'Y-m-d', $end_date );

		// 日付が正しく解析されたか確認
		if ( ! $start_date_obj || ! $end_date_obj ) {
			throw new Exception( 'Invalid date format' );
		}

		$start_year = $start_date_obj->format( 'Y' );
		$end_year   = $end_date_obj->format( 'Y' );

		$total_difference = array();

		for ( $year = $start_year; $year <= $end_year; $year++ ) {
			$start_data = array();

			if ( $year == $start_year ) {
				$year_start_date = $start_date;
				$start_datetime  = DateTime::createFromFormat( 'Y-m-d', $year_start_date )->modify( '-1 day' );
				if ( ! $start_datetime ) {
					throw new Exception( 'Invalid date format in loop' );
				}

				// $year_start_dateが1/1の場合は$start_dataを空に設定
				if ( $start_datetime->format( 'm-d' ) == '12-31' ) {
					$start_data = array();
				} else {
					$start_file_name = $start_datetime->format( $date_format ) . '_summary_' . $summary_type . '_i.php';
					$start_file_path = $vw_summary_dir . $start_file_name;

					if ( is_readable( $start_file_path ) ) {
						$start_data = $this->load_file( $start_file_path );
					} else {
						// start_dateのファイルがない場合は、遡って直前日のファイルを使う（年初日 or 計測開始日、どちらか近い方まで遡る）
						$measurement_start_date = $this->get_pvterm_start_date( $tracking_id );
						$year_jan1              = DateTime::createFromFormat( 'Y-m-d', "$year-01-01" );
						$measurement_start_obj  = DateTime::createFromFormat( 'Y-m-d', $measurement_start_date );
						$min_datetime           = ( $measurement_start_obj > $year_jan1 ) ? $measurement_start_obj : $year_jan1;

						while ( $start_datetime >= $min_datetime ) {
							$start_date_str          = $start_datetime->format( $date_format );
							$start_date_summary_file = $vw_summary_dir . $start_date_str . '_summary_' . $summary_type . '_i.php';
							if ( is_readable( $start_date_summary_file ) ) {
								$start_data = $this->load_file( $start_date_summary_file );
								break;
							}
							$start_datetime->modify( '-1 day' );
						}
					}
				}
			}

			$year_end_date = ( $year < $end_year ) ? "$year-12-31" : $end_date;

			$end_date_str  = $year_end_date;
			$end_file_name = $end_date_str . '_summary_' . $summary_type . '_i.php';
			$end_file_path = $vw_summary_dir . $end_file_name;

			if ( is_readable( $end_file_path ) ) {
				$end_data = $this->load_file( $end_file_path );
			} else {
				$end_data = array();
				// end_dateのファイルがない場合は、遡って直前日のファイルを使う（ $start_date or 年初日 or 計測開始日の前まで遡る）
				$end_datetime          = DateTime::createFromFormat( 'Y-m-d', $end_date_str );
				$year_jan1             = DateTime::createFromFormat( 'Y-m-d', "$year-01-01" );
				$measurement_start_obj = DateTime::createFromFormat( 'Y-m-d', $this->get_pvterm_start_date( $tracking_id ) );
				$start_date_obj        = DateTime::createFromFormat( 'Y-m-d', $start_date );
				$min_datetime          = max( $year_jan1, $measurement_start_obj, $start_date_obj );

				while ( $end_datetime > $min_datetime ) {
					$try_date_str          = $end_datetime->format( $date_format );
					$try_date_summary_file = $vw_summary_dir . $try_date_str . '_summary_' . $summary_type . '_i.php';
					if ( is_readable( $try_date_summary_file ) ) {
						$end_data = $this->load_file( $try_date_summary_file );
						break;
					}
					$end_datetime->modify( '-1 day' );
				}
			}

			// 差分を計算
			$year_difference = $this->calculate_integral_summary( $start_data, $end_data, 'subtract' );
			if ( empty( $total_difference ) ) {
				$total_difference = $year_difference;
			} else {
				$total_difference = $this->calculate_integral_summary( $total_difference, $year_difference, 'add' );
			}
		}

		return $total_difference;
	}

	/**
	 * ページIDに基づいてPVデータを取得する
	 *
	 * @param string $tracking_id トラッキングID
	 * @param int|array $page_id 検索するページID（単一整数または整数配列）
	 * @param string|null $start_date 開始日（YYYY-MM-DD形式、オプション）
	 * @param string|null $end_date 終了日（YYYY-MM-DD形式、オプション）
	 * @return array PVデータの配列
	 */
	public function get_pv_data_by_page_id( $tracking_id, $page_id, $start_date = null, $end_date = null ) {
		global $qahm_db;

		$data_dir       = $this->get_data_dir_path();
		$view_dir       = $data_dir . 'view/';
		$viewpv_dir     = $view_dir . $tracking_id . '/view_pv/';
		$viewpv_idx_dir = $viewpv_dir . 'index/';

		$results  = array();
		$page_ids = is_array( $page_id ) ? $page_id : array( (int) $page_id );

		// 各ページIDについて処理
		foreach ( $page_ids as $single_page_id ) {
			// インデックスファイルからデータを取得
			$index_data = $qahm_db->get_index_file_contents( $viewpv_idx_dir, 'pageid', $single_page_id );
			if ( ! $index_data ) {
				continue;
			}

			// 日付ごとにファイルを読み込み、該当するPVデータを抽出
			foreach ( $index_data as $date => $pv_ids ) {
				// 日付フィルタリング
				if ( ( $start_date && $date < $start_date ) || ( $end_date && $date > $end_date ) ) {
					continue;
				}

				// 該当日のファイルを探す
				$pv_file = $this->find_pv_file_by_date( $viewpv_dir, $date );
				if ( ! $pv_file ) {
					continue;
				}

				// ファイルからデータを読み込む
				$pv_data = $this->wrap_unserialize( $this->wrap_get_contents( $viewpv_dir . $pv_file ) );
				if ( ! is_array( $pv_data ) ) {
					continue;
				}

				// PV IDでルックアップするためのマップを作成
				$pv_map = array();
				foreach ( $pv_data as $item ) {
					$pv_map[ (int) $item['pv_id'] ] = $item;
				}

				// 該当するPVデータを取得
				foreach ( $pv_ids as $pv_id ) {
					if ( isset( $pv_map[ $pv_id ] ) ) {
						$results[] = $pv_map[ $pv_id ];
					}
				}
			}
		}

		return $results;
	}

	/**
	 * バージョンIDに基づいてPVデータを取得する
	 *
	 * @param string $tracking_id トラッキングID
	 * @param int|array $version_id 検索するバージョンID（単一整数または整数配列）
	 * @param string|null $start_date 開始日（YYYY-MM-DD形式、オプション）
	 * @param string|null $end_date 終了日（YYYY-MM-DD形式、オプション）
	 * @return array PVデータの配列
	 */
	public function get_pv_data_by_version_id( $tracking_id, $version_id, $start_date = null, $end_date = null ) {
		global $qahm_db;

		$data_dir       = $this->get_data_dir_path();
		$view_dir       = $data_dir . 'view/';
		$viewpv_dir     = $view_dir . $tracking_id . '/view_pv/';
		$viewpv_idx_dir = $viewpv_dir . 'index/';

		$results     = array();
		$version_ids = is_array( $version_id ) ? $version_id : array( (int) $version_id );

		// 各バージョンIDについて処理
		foreach ( $version_ids as $single_version_id ) {
			// インデックスファイルからデータを取得
			$index_data = $qahm_db->get_index_file_contents( $viewpv_idx_dir, 'versionid', $single_version_id );
			if ( ! $index_data ) {
				continue;
			}

			// 日付ごとにファイルを読み込み、該当するPVデータを抽出
			foreach ( $index_data as $date => $pv_ids ) {
				// 日付フィルタリング
				if ( ( $start_date && $date < $start_date ) || ( $end_date && $date > $end_date ) ) {
					continue;
				}

				// 該当日のファイルを探す
				$pv_file = $this->find_pv_file_by_date( $viewpv_dir, $date );
				if ( ! $pv_file ) {
					continue;
				}

				// ファイルからデータを読み込む
				$pv_data = $this->wrap_unserialize( $this->wrap_get_contents( $viewpv_dir . $pv_file ) );
				if ( ! is_array( $pv_data ) ) {
					continue;
				}

				// PV IDでルックアップするためのマップを作成
				$pv_map = array();
				foreach ( $pv_data as $item ) {
					$pv_map[ (int) $item['pv_id'] ] = $item;
				}

				// 該当するPVデータを取得
				foreach ( $pv_ids as $pv_id ) {
					if ( isset( $pv_map[ $pv_id ] ) ) {
						$results[] = $pv_map[ $pv_id ];
					}
				}
			}
		}

		return $results;
	}

	/**
	 * PV IDに基づいてセッションデータを取得する
	 *
	 * @param string $tracking_id トラッキングID
	 * @param int|array $pv_id 検索するPV ID（単一整数または整数配列）
	 * @param string|null $start_date 開始日（YYYY-MM-DD形式、オプション）
	 * @param string|null $end_date 終了日（YYYY-MM-DD形式、オプション）
	 * @return array セッションデータの配列（各セッションは複数のPVデータを含む配列）
	 */
	public function get_session_data_by_pv_id( $tracking_id, $pv_id, $start_date = null, $end_date = null ) {
		$data_dir    = $this->get_data_dir_path();
		$view_dir    = $data_dir . 'view/';
		$viewpv_dir  = $view_dir . $tracking_id . '/view_pv/';
		$verhist_dir = $view_dir . 'all' . '/version_hist/';

		$pv_ids             = is_array( $pv_id ) ? $pv_id : array( (int) $pv_id );
		$results            = array();
		$processed_sessions = array(); // 処理済みセッションを追跡

		// 各PV IDごとにファイルを特定して処理
		foreach ( $pv_ids as $single_pv_id ) {
			// PV IDからファイルを探す
			$file_info = $this->find_file_containing_pv_id( $viewpv_dir, $single_pv_id );
			if ( ! $file_info ) {
				continue;
			}

			$filename = $file_info['filename'];
			$date     = $this->wrap_substr( $filename, 0, 10 );

			// 日付フィルタリング
			if ( ( $start_date && $date < $start_date ) || ( $end_date && $date > $end_date ) ) {
				continue;
			}

			// ファイルからデータを読み込む
			$pv_data = $this->wrap_unserialize( $this->wrap_get_contents( $viewpv_dir . $filename ) );
			if ( ! is_array( $pv_data ) ) {
				continue;
			}

			// セッションを構築
			$session = $this->build_session_from_pv_data( $pv_data, $single_pv_id, $verhist_dir );
			if ( ! empty( $session ) ) {
				// セッションの重複を避ける
				$session_key = $session[0]['reader_id'] . '_' . $session[0]['session_no'];
				if ( ! isset( $processed_sessions[ $session_key ] ) ) {
					$results[]                          = $session;
					$processed_sessions[ $session_key ] = true;
				}
			}
		}

		return $results;
	}

	/**
	 * ページIDに基づいてセッションデータを取得する
	 *
	 * @param string $tracking_id トラッキングID
	 * @param int|array $page_id 検索するページID（単一整数または整数配列）
	 * @param string|null $start_date 開始日（YYYY-MM-DD形式、オプション）
	 * @param string|null $end_date 終了日（YYYY-MM-DD形式、オプション）
	 * @return array セッションデータの配列（各セッションは複数のPVデータを含む配列）
	 */
	public function get_session_data_by_page_id( $tracking_id, $page_id, $start_date = null, $end_date = null ) {
		// まずページIDに基づくPV IDを取得
		$pv_data = $this->get_pv_data_by_page_id( $tracking_id, $page_id, $start_date, $end_date );
		if ( empty( $pv_data ) ) {
			return array();
		}

		// PV IDを抽出
		$pv_ids = array();
		foreach ( $pv_data as $item ) {
			$pv_ids[] = (int) $item['pv_id'];
		}

		// PV IDからセッションデータを取得
		return $this->get_session_data_by_pv_id( $tracking_id, $pv_ids, $start_date, $end_date );
	}

	/**
	 * バージョンIDに基づいてセッションデータを取得する
	 *
	 * @param string $tracking_id トラッキングID
	 * @param int|array $version_id 検索するバージョンID（単一整数または整数配列）
	 * @param string|null $start_date 開始日（YYYY-MM-DD形式、オプション）
	 * @param string|null $end_date 終了日（YYYY-MM-DD形式、オプション）
	 * @return array セッションデータの配列（各セッションは複数のPVデータを含む配列）
	 */
	public function get_session_data_by_version_id( $tracking_id, $version_id, $start_date = null, $end_date = null ) {
		// まずバージョンIDに基づくPV IDを取得
		$pv_data = $this->get_pv_data_by_version_id( $tracking_id, $version_id, $start_date, $end_date );
		if ( empty( $pv_data ) ) {
			return array();
		}

		// PV IDを抽出
		$pv_ids = array();
		foreach ( $pv_data as $item ) {
			$pv_ids[] = (int) $item['pv_id'];
		}

		// PV IDからセッションデータを取得
		return $this->get_session_data_by_pv_id( $tracking_id, $pv_ids, $start_date, $end_date );
	}

	/**
	 * ファイル内のPVデータからセッションを構築する
	 *
	 * @param array $pv_data PVデータの配列
	 * @param int $pv_id 検索するPV ID
	 * @param string $verhist_dir バージョン履歴ディレクトリ
	 * @return array セッションデータの配列
	 */
	private function build_session_from_pv_data( $pv_data, $pv_id, $verhist_dir ) {
		// PV IDが含まれる位置を特定
		$pv_idx    = null;
		$target_pv = null;

		foreach ( $pv_data as $idx => $item ) {
			if ( (int) $item['pv_id'] === (int) $pv_id ) {
				$pv_idx    = $idx;
				$target_pv = $item;
				break;
			}
		}

		if ( $pv_idx === null ) {
			return array();
		}

		$session   = array();
		$reader_id = $target_pv['reader_id'];
		$pv_no     = (int) $target_pv['pv'];

		// 前のPVを遡って取得（1PV目でない場合）
		if ( $pv_no > 1 ) {
			$prev_idx = $pv_idx - $pv_no + 1;
			if ( $prev_idx >= 0 ) {
				for ( $i = $prev_idx; $i < $pv_idx; $i++ ) {
					if ( isset( $pv_data[ $i ] ) && $pv_data[ $i ]['reader_id'] === $reader_id ) {
						// version_noを追加
						$pv_item = $pv_data[ $i ];
						if ( isset( $pv_item['version_id'] ) && (int) $pv_item['version_id'] > 0 ) {
							$verhist_filename = $pv_item['version_id'] . '_version.php';
							if ( $this->wrap_exists( $verhist_dir . $verhist_filename ) ) {
								$verhist_file = $this->wrap_get_contents( $verhist_dir . $verhist_filename );
								if ( $verhist_file ) {
									$verhist_ary           = $this->wrap_unserialize( $verhist_file );
									$pv_item['version_no'] = $verhist_ary[0]->version_no;
								}
							}
						}
						$session[] = $pv_item;
					}
				}
			}
		}

		// 現在のPVを追加
		if ( isset( $target_pv['version_id'] ) && (int) $target_pv['version_id'] > 0 ) {
			$verhist_filename = $target_pv['version_id'] . '_version.php';
			if ( $this->wrap_exists( $verhist_dir . $verhist_filename ) ) {
				$verhist_file = $this->wrap_get_contents( $verhist_dir . $verhist_filename );
				if ( $verhist_file ) {
					$verhist_ary             = $this->wrap_unserialize( $verhist_file );
					$target_pv['version_no'] = $verhist_ary[0]->version_no;
				}
			}
		}
		$session[] = $target_pv;

		// 以降のセッションデータを追加
		$next_idx = $pv_idx + 1;
		while ( isset( $pv_data[ $next_idx ] ) ) {
			if ( $pv_data[ $next_idx ]['reader_id'] !== $reader_id ) {
				break;
			}

			$next_pv = $pv_data[ $next_idx ];

			// version_noを追加
			if ( isset( $next_pv['version_id'] ) && (int) $next_pv['version_id'] > 0 ) {
				$verhist_filename = $next_pv['version_id'] . '_version.php';
				if ( $this->wrap_exists( $verhist_dir . $verhist_filename ) ) {
					$verhist_file = $this->wrap_get_contents( $verhist_dir . $verhist_filename );
					if ( $verhist_file ) {
						$verhist_ary           = $this->wrap_unserialize( $verhist_file );
						$next_pv['version_no'] = $verhist_ary[0]->version_no;
					}
				}
			}

			$session[] = $next_pv;

			// セッション終了チェック
			if ( (int) $next_pv['is_last'] === 1 ) {
				break;
			}

			++$next_idx;
		}

		return $session;
	}

	/**
	 * 日付からPVファイルを探す
	 *
	 * @param string $dir 検索するディレクトリ
	 * @param string $date 日付（YYYY-MM-DD形式）
	 * @return string|null ファイル名（見つからない場合はnull）
	 */
	private function find_pv_file_by_date( $dir, $date ) {
		$files = $this->wrap_dirlist( $dir );

		foreach ( $files as $file ) {
			if ( $this->wrap_substr( $file['name'], 0, 10 ) === $date ) {
				return $file['name'];
			}
		}

		return null;
	}

	/**
	 * PV IDを含むファイルを探す
	 *
	 * @param string $dir 検索するディレクトリ
	 * @param int $pv_id 検索するPV ID
	 * @return array|null ファイル情報（見つからない場合はnull）
	 */
	private function find_file_containing_pv_id( $dir, $pv_id ) {
		$files = $this->wrap_dirlist( $dir );

		foreach ( $files as $file ) {
			$filename = $file['name'];
			if ( preg_match( '/_(\d+)-(\d+)_/', $filename, $matches ) ) {
				$min_id = (int) $matches[1];
				$max_id = (int) $matches[2];

				if ( $pv_id >= $min_id && $pv_id <= $max_id ) {
					return array(
						'filename' => $filename,
						'min_id'   => $min_id,
						'max_id'   => $max_id,
					);
				}
			}
		}

		return null;
	}


	/**
	 * ファイルを読み込む
	 *
	 * @param string $file_path ファイルパス
	 * @return array|false ファイルの内容、失敗した場合はfalse
	 */
	public function load_file( $file_path ) {
		if ( ! $this->wrap_exists( $file_path ) ) {
			return false;
		}

		$data = $this->wrap_get_contents( $file_path );
		if ( $data === false ) {
			return false;
		}

		return $this->wrap_unserialize( $data );
	}

	/**
	 * ディレクトリ内のファイル一覧を取得
	 *
	 * @param string $dir_path ディレクトリパス
	 * @return array ファイル名の配列
	 */
	public function list_directory( $dir_path ) {
		if ( ! is_dir( $dir_path ) ) {
			return array();
		}

		$files    = array();
		$dir_list = $this->wrap_dirlist( $dir_path );

		if ( $dir_list ) {
			foreach ( $dir_list as $file_obj ) {
				$files[] = $file_obj['name'];
			}
		}

		return $files;
	}

	/**
	 * ファイル名から日付マッピングを作成
	 *
	 * @param array $file_list ファイル名の配列
	 * @return array 日付=>ファイル名のマッピング
	 */
	public function create_date_file_mapping( $file_list ) {
		$mapping = array();

		foreach ( $file_list as $file ) {
			// ファイル名から日付部分を抽出（例: data_2023-01-01.json から 2023-01-01 を取得）
			if ( preg_match( '/(\d{4}-\d{2}-\d{2})/', $file, $matches ) ) {
				$date             = $matches[1];
				$mapping[ $date ] = $file;
			}
		}

		return $mapping;
	}

	/**
	 * TSV形式の文字列を配列に変換
	 *
	 * @param string $tsv_string TSV形式の文字列
	 * @return array 変換された二次元配列
	 */
	public function convert_tsv_to_array( $tsv_string ) {
		$result = array();
		$lines  = $this->wrap_explode( "\n", $this->wrap_trim( $tsv_string ) );

		foreach ( $lines as $line ) {
			$result[] = $this->wrap_explode( "\t", $line );
		}

		return $result;
	}

	/**
	 * 日付範囲を配列として取得
	 *
	 * @param string $start_date 開始日 (Y-m-d)
	 * @param string $end_date 終了日 (Y-m-d)
	 * @return array 日付の配列
	 */
	private function get_date_range( $start_date, $end_date ) {
		$dates = array();

		$current = strtotime( $start_date );
		$end     = strtotime( $end_date );

		while ( $current <= $end ) {
			$dates[] = gmdate( 'Y-m-d', $current );
			$current = strtotime( '+1 day', $current );
		}

		return $dates;
	}

	/**
	 * ページのクリックデータを取得する
	 *
	 * @param string $tracking_id トラッキングID
	 * @param int $page_id ページID
	 * @param int $version_id バージョンID
	 * @param string $start_date 開始日 (Y-m-d)
	 * @param string $end_date 終了日 (Y-m-d)
	 * @param array $filters フィルタリング条件
	 *   - device_id: デバイスID (1:desktop, 2:tablet, 3:mobile)
	 *   - is_landing_page: ランディングページのみ対象とする場合はtrue
	 *   - utm_media: mediaによるフィルタリング条件の配列
	 *   - utm_source: sourceによるフィルタリング条件の配列
	 *   - utm_campaign: campaignによるフィルタリング条件の配列
	 *   - is_goal: ゴールセッションのみ対象とする場合はtrue
	 * @return array クリックデータ
	 *   [
	 *     'click_data' => [
	 *       [
	 *         'selector_name' => (string),
	 *         'selector_x' => (int),
	 *         'selector_y' => (int)
	 *       ],
	 *       ...
	 *     ],
	 *     'data_num' => (int) データ数
	 *   ]
	 */
	public function get_page_click_data( $tracking_id, $page_id, $version_id, $start_date, $end_date, $filters = array() ) {
		global $qahm_data_api;

		// まずbase_selectorを取得
		$base_selector = $this->get_base_selector( $version_id );
		if ( empty( $base_selector ) ) {
			return array(
				'click_data' => array(),
				'data_num'   => 0,
			);
		} else {
			// base_selectorを配列に変換
			if ( is_array( $base_selector ) ) {
				$base_selector_ary = $base_selector;
			} else {
				$base_selector_ary = $this->wrap_explode( "\\t", $base_selector );
			}
		}

		// PVデータの取得
		$pv_data = $this->get_pv_data_by_page_id( $tracking_id, $page_id, $start_date, $end_date );

		// ゴールセッション情報の取得（必要な場合）
		$goal_sessions = array();
		if ( isset( $filters['is_goal'] ) && $filters['is_goal'] ) {
			$dateterm           = 'date = between ' . $start_date . ' and ' . $end_date;
			$all_goals_sessions = $qahm_data_api->get_goals_sessions( $dateterm, $tracking_id );

			foreach ( $all_goals_sessions as $goals_sessions ) {
				foreach ( $goals_sessions as $goal_session ) {
					foreach ( $goal_session as $session ) {
						$goal_sessions[ $session['pv_id'] ] = true;
					}
				}
			}
		}

		// フィルタリングされたPVデータ
		$filtered_pv_data = array();

		foreach ( $pv_data as $pv ) {
			// バージョンIDフィルタリング
			if ( (int) $pv['version_id'] !== (int) $version_id ) {
				continue;
			}

			// デバイスフィルタリング
			if ( isset( $filters['device_id'] ) && (int) $pv['device_id'] !== (int) $filters['device_id'] ) {
				continue;
			}

			// ランディングページフィルタリング
			if ( isset( $filters['is_landing_page'] ) && $filters['is_landing_page'] && (int) $pv['pv'] !== 1 ) {
				continue;
			}

			// utm_mediaフィルタリング
			if ( isset( $filters['utm_media'] ) && ! empty( $filters['utm_media'] ) ) {
				$utm_medium = isset( $pv['utm_medium'] ) ? $pv['utm_medium'] : '(not set)';
				if ( ! $this->wrap_in_array( $utm_medium, $filters['utm_media'] ) ) {
					continue;
				}
			}

			// utm_sourceフィルタリング
			if ( isset( $filters['utm_source'] ) && ! empty( $filters['utm_source'] ) ) {
				$utm_source    = isset( $pv['utm_source'] ) ? $pv['utm_source'] : null;
				$source_domain = isset( $pv['source_domain'] ) ? $pv['source_domain'] : '(not set)';

				// utm_sourceか、そうでなければsource_domainで判定
				$source = ! empty( $utm_source ) ? $utm_source : $source_domain;

				if ( ! $this->wrap_in_array( $source, $filters['utm_source'] ) ) {
					continue;
				}
			}

			// utm_campaignフィルタリング
			if ( isset( $filters['utm_campaign'] ) && ! empty( $filters['utm_campaign'] ) ) {
				$utm_campaign = isset( $pv['utm_campaign'] ) ? $pv['utm_campaign'] : '(not set)';
				if ( ! $this->wrap_in_array( $utm_campaign, $filters['utm_campaign'] ) ) {
					continue;
				}
			}

			// ゴールフィルタリング
			if ( isset( $filters['is_goal'] ) && $filters['is_goal'] ) {
				if ( ! isset( $goal_sessions[ $pv['pv_id'] ] ) ) {
					continue;
				}
			}

			$filtered_pv_data[] = $pv;
		}

		// raw_cディレクトリのパス
		$view_pv_dir = $this->data_dir . '/view/' . $tracking_id . '/view_pv/';
		$raw_c_dir   = $view_pv_dir . 'raw_c/';

		// ファイル一覧を取得し、日付でマッピング
		$raw_c_dirlist = $this->list_directory( $raw_c_dir );
		$raw_c_filemap = $this->create_date_file_mapping( $raw_c_dirlist );

		// クリックデータ計算用の変数
		$merge_click_ary = array();
		$data_num        = 0;

		// 前回処理した日付を追跡
		$last_date        = null;
		$raw_c_cached_ary = array();

		foreach ( $filtered_pv_data as $pv_log ) {
			$raw_c_tsv        = null;
			$access_timestamp = isset( $pv_log['access_time'] ) ? $pv_log['access_time'] : 0;

			// 日付を取得
			$current_date = gmdate( 'Y-m-d', $access_timestamp );

			// 日付が変わったらファイルを再度読み込む
			if ( $last_date !== $current_date ) {
				$last_date = $current_date;
				if ( isset( $raw_c_filemap[ $current_date ] ) ) {
					$raw_c_file       = $raw_c_filemap[ $current_date ];
					$raw_c_data_ary   = $this->load_file( $raw_c_dir . $raw_c_file );
					$raw_c_cached_ary = array();
					if ( is_array( $raw_c_data_ary ) ) {
						foreach ( $raw_c_data_ary as $raw_c_data ) {
							if ( isset( $raw_c_data['pv_id'] ) && isset( $raw_c_data['raw_c'] ) ) {
								$raw_c_cached_ary[ $raw_c_data['pv_id'] ] = $raw_c_data['raw_c'];
							}
						}
					}
				} else {
					continue;
				}
			}

			// raw_cのデータを取得
			$pv_id = $pv_log['pv_id'];
			if ( isset( $raw_c_cached_ary[ $pv_id ] ) ) {
				$raw_c_tsv = $raw_c_cached_ary[ $pv_id ];
			} else {
				continue;
			}

			// データがあれば処理
			if ( $raw_c_tsv ) {
				++$data_num;
				$raw_c_ary = $this->convert_tsv_to_array( $raw_c_tsv );

				foreach ( $raw_c_ary as $index => $c ) {
					if ( $index === 0 ) {
						// ヘッダー部はスキップ
						continue;
					}

					// セレクタ情報がなければスキップ
					if ( ! isset( $c[0] ) || ! isset( $base_selector_ary[ $c[0] ] ) ) {
						continue;
					}

					$merge_click_ary[] = array(
						'selector_name' => $base_selector_ary[ $c[0] ], // SELECTOR_NAME
						'selector_x'    => (int) $c[1],                  // SELECTOR_X
						'selector_y'    => (int) $c[2],                   // SELECTOR_Y
					);
				}
			}
		}

		// 結果を構造化
		return array(
			'click_data' => $merge_click_ary,
			'data_num'   => $data_num,
		);
	}

	/**
	 * ページの精読率データを取得する
	 *
	 * @param string $tracking_id トラッキングID
	 * @param int $page_id ページID
	 * @param string $start_date 開始日 (Y-m-d)
	 * @param string $end_date 終了日 (Y-m-d)
	 * @param array $filters フィルタリング条件
	 *   - device_id: デバイスID (1:desktop, 2:tablet, 3:mobile)
	 *   - is_landing_page: ランディングページのみ対象とする場合はtrue
	 *   - utm_media: mediaによるフィルタリング条件の配列
	 *   - utm_source: sourceによるフィルタリング条件の配列
	 *   - utm_campaign: campaignによるフィルタリング条件の配列
	 *   - is_goal: ゴールセッションのみ対象とする場合はtrue
	 * @return array 精読率データ
	 *   [
	 *     'reading_data' => [
	 *       [
	 *         'stay_height' => (int),
	 *         'stay_time' => (float),
	 *         'stay_num' => (int),
	 *         'exit_num' => (int)
	 *       ],
	 *       ...
	 *     ],
	 *     'data_num' => (int),
	 *     'total_stay_time' => (float)
	 *   ]
	 */
	public function get_page_reading_data( $tracking_id, $page_id, $start_date, $end_date, $filters = array() ) {
		global $qahm_data_api;

		// PVデータの取得
		$pv_data = $this->get_pv_data_by_page_id( $tracking_id, $page_id, $start_date, $end_date );

		// ゴールセッション情報の取得（必要な場合）
		$goal_sessions = array();
		if ( isset( $filters['is_goal'] ) && $filters['is_goal'] ) {
			$dateterm           = 'date = between ' . $start_date . ' and ' . $end_date;
			$all_goals_sessions = $qahm_data_api->get_goals_sessions( $dateterm, $tracking_id );

			foreach ( $all_goals_sessions as $goals_sessions ) {
				foreach ( $goals_sessions as $goal_session ) {
					foreach ( $goal_session as $session ) {
						$goal_sessions[ $session['pv_id'] ] = true;
					}
				}
			}
		}

		// フィルタリングされたPVデータ
		$filtered_pv_data = array();

		foreach ( $pv_data as $pv ) {
			// デバイスフィルタリング
			if ( isset( $filters['device_id'] ) && (int) $pv['device_id'] !== (int) $filters['device_id'] ) {
				continue;
			}

			// ランディングページフィルタリング
			if ( isset( $filters['is_landing_page'] ) && $filters['is_landing_page'] && (int) $pv['pv'] !== 1 ) {
				continue;
			}

			// utm_mediaフィルタリング
			if ( isset( $filters['utm_media'] ) && ! empty( $filters['utm_media'] ) ) {
				$utm_medium = isset( $pv['utm_medium'] ) ? $pv['utm_medium'] : '(not set)';
				if ( ! $this->wrap_in_array( $utm_medium, $filters['utm_media'] ) ) {
					continue;
				}
			}

			// utm_sourceフィルタリング
			if ( isset( $filters['utm_source'] ) && ! empty( $filters['utm_source'] ) ) {
				$utm_source    = isset( $pv['utm_source'] ) ? $pv['utm_source'] : null;
				$source_domain = isset( $pv['source_domain'] ) ? $pv['source_domain'] : '(not set)';

				// utm_sourceか、そうでなければsource_domainで判定
				$source = ! empty( $utm_source ) ? $utm_source : $source_domain;

				if ( ! $this->wrap_in_array( $source, $filters['utm_source'] ) ) {
					continue;
				}
			}

			// utm_campaignフィルタリング
			if ( isset( $filters['utm_campaign'] ) && ! empty( $filters['utm_campaign'] ) ) {
				$utm_campaign = isset( $pv['utm_campaign'] ) ? $pv['utm_campaign'] : '(not set)';
				if ( ! $this->wrap_in_array( $utm_campaign, $filters['utm_campaign'] ) ) {
					continue;
				}
			}

			// ゴールフィルタリング
			if ( isset( $filters['is_goal'] ) && $filters['is_goal'] ) {
				if ( ! isset( $goal_sessions[ $pv['pv_id'] ] ) ) {
					continue;
				}
			}

			$filtered_pv_data[] = $pv;
		}

		// raw_pディレクトリのパス
		$view_pv_dir = $this->data_dir . '/view/' . $tracking_id . '/view_pv/';
		$raw_p_dir   = $view_pv_dir . 'raw_p/';

		// ファイル一覧を取得し、日付でマッピング
		$raw_p_dirlist = $this->list_directory( $raw_p_dir );
		$raw_p_filemap = $this->create_date_file_mapping( $raw_p_dirlist );

		// 精読率データ計算用の変数
		$merge_att_scr_ary = array();
		$data_num          = 0;
		$total_stay_time   = 0;

		// 定数定義
		$attention_limit_time = 30; // 最大の注目時間（秒）
		$max_reading_level    = 37.5;  // 最大の精読レベル
		$four_per_six         = 4 / 6;      // 中心位置に割り当てる割合
		$one_per_six          = 1 / 6;       // 前後位置に割り当てる割合

		// 前回処理した日付を追跡
		$last_date        = null;
		$raw_p_cached_ary = array();

		foreach ( $filtered_pv_data as $pv_log ) {
			$raw_p_tsv        = null;
			$access_timestamp = isset( $pv_log['access_time'] ) ? $pv_log['access_time'] : 0;

			// 日付を取得
			$current_date = gmdate( 'Y-m-d', $access_timestamp );

			// 日付が変わったらファイルを再度読み込む
			if ( $last_date !== $current_date ) {
				$last_date = $current_date;
				if ( isset( $raw_p_filemap[ $current_date ] ) ) {
					$raw_p_file       = $raw_p_filemap[ $current_date ];
					$raw_p_data_ary   = $this->load_file( $raw_p_dir . $raw_p_file );
					$raw_p_cached_ary = array();
					if ( is_array( $raw_p_data_ary ) ) {
						foreach ( $raw_p_data_ary as $raw_p_data ) {
							if ( isset( $raw_p_data['pv_id'] ) && isset( $raw_p_data['raw_p'] ) ) {
								$raw_p_cached_ary[ $raw_p_data['pv_id'] ] = $raw_p_data['raw_p'];
							}
						}
					}
				} else {
					continue;
				}
			}

			// raw_pのデータを取得
			$pv_id = $pv_log['pv_id'];
			if ( isset( $raw_p_cached_ary[ $pv_id ] ) ) {
				$raw_p_tsv = $raw_p_cached_ary[ $pv_id ];
			} else {
				continue;
			}

			// データがあれば処理
			if ( $raw_p_tsv ) {
				++$data_num;
				$raw_p_ary = $this->convert_tsv_to_array( $raw_p_tsv );
				$exit_idx  = -1;

				// バージョン確認
				$ver = (int) $raw_p_ary[0][0]; // ヘッダー部のバージョン情報

				if ( $ver === 2 ) {
					// 最大の滞在時間を取得
					$max_stay_time = 0;
					foreach ( $raw_p_ary as $idx => $p ) {
						if ( $idx === 0 ) {
							continue; // ヘッダースキップ
						}

						if ( isset( $p[1] ) ) { // STAY_TIME
							if ( $p[1] > $max_stay_time ) {
								$max_stay_time = $p[1];
							}
						}
					}

					if ( $max_stay_time <= 0 ) {
						$max_stay_time = 1;
					}

					if ( $attention_limit_time < $max_stay_time ) {
						$max_stay_time = $attention_limit_time;
					}

					// 各ポジションデータを処理
					for ( $raw_p_idx = 1; $raw_p_idx < $this->wrap_count( $raw_p_ary ); $raw_p_idx++ ) {
						$p = $raw_p_ary[ $raw_p_idx ];

						// 必要なデータが無ければスキップ
						if ( ! isset( $p[0] ) ) { // STAY_HEIGHT
							break;
						}

						if ( $p[0] === 'a' ) {
							break; // 'a'は特殊コードでスキップ
						}

						$stay_time = min( (int) $p[1], $attention_limit_time ); // STAY_TIME

						// 滞在時間を熟読度に変換
						if ( $max_stay_time <= 2 ) {
							$reading_level = $stay_time == 2 ? 4 : 2;
						} else {
							$reading_level = ( $stay_time / $max_stay_time ) * $max_reading_level;
						}

						// 現在処理中のSTAY_HEIGHTを取得
						$merge_att_scr_idx = (int) $p[0]; // STAY_HEIGHT

						// 中心のインデックスに4/6を割り振る
						if ( isset( $merge_att_scr_ary[ $merge_att_scr_idx ] ) ) {
							$merge_att_scr_ary[ $merge_att_scr_idx ][1] += $reading_level * $four_per_six; // STAY_TIME
							++$merge_att_scr_ary[ $merge_att_scr_idx ][2]; // STAY_NUM
						} else {
							$merge_att_scr_ary[ $merge_att_scr_idx ] = array(
								(int) $p[0], // STAY_HEIGHT
								$reading_level * $four_per_six, // STAY_TIME
								1, // STAY_NUM
								0, // EXIT_NUM
							);
						}

						// 前後のインデックスに1/6ずつ割り振る
						if ( $merge_att_scr_idx - 1 >= 0 ) {
							if ( isset( $merge_att_scr_ary[ $merge_att_scr_idx - 1 ] ) ) {
								$merge_att_scr_ary[ $merge_att_scr_idx - 1 ][1] += $reading_level * $one_per_six; // STAY_TIME
								++$merge_att_scr_ary[ $merge_att_scr_idx - 1 ][2]; // STAY_NUM
							} else {
								$merge_att_scr_ary[ $merge_att_scr_idx - 1 ] = array(
									(int) $p[0] - 1, // STAY_HEIGHT
									$reading_level * $one_per_six, // STAY_TIME
									1, // STAY_NUM
									0, // EXIT_NUM
								);
							}
						}

						if ( isset( $merge_att_scr_ary[ $merge_att_scr_idx + 1 ] ) ) {
							$merge_att_scr_ary[ $merge_att_scr_idx + 1 ][1] += $reading_level * $one_per_six; // STAY_TIME
							++$merge_att_scr_ary[ $merge_att_scr_idx + 1 ][2]; // STAY_NUM
						} else {
							$merge_att_scr_ary[ $merge_att_scr_idx + 1 ] = array(
								(int) $p[0] + 1, // STAY_HEIGHT
								$reading_level * $one_per_six, // STAY_TIME
								1, // STAY_NUM
								0, // EXIT_NUM
							);
						}

						// 離脱位置を更新
						if ( $exit_idx < $merge_att_scr_idx ) {
							$exit_idx = $merge_att_scr_idx;
						}

						// 合計滞在時間に加算
						$total_stay_time += $stay_time;
					}

					// body部が存在しなかったユーザーの対策
					if ( $exit_idx === -1 ) {
						$exit_idx = 0;
					}

					if ( isset( $merge_att_scr_ary[ $exit_idx ] ) ) {
						// 離脱ユーザーの位置を増やす
						++$merge_att_scr_ary[ $exit_idx ][3]; // EXIT_NUM
					} else {
						// 離脱ユーザーの位置を新たに作成
						$merge_att_scr_ary[ $exit_idx ] = array(
							0, // STAY_HEIGHT
							0, // STAY_TIME
							1, // STAY_NUM
							1,  // EXIT_NUM
						);
					}
				}
			}
		}

		// 合算したデータの平均値を求める
		foreach ( $merge_att_scr_ary as $idx => $values ) {
			if ( $values[2] > 1 ) { // STAY_NUM > 1
				$merge_att_scr_ary[ $idx ][1] /= $values[2]; // STAY_TIME / STAY_NUM
				$merge_att_scr_ary[ $idx ][1]  = round( $merge_att_scr_ary[ $idx ][1], 3 ); // 小数点以下3桁に丸める
			}
		}

		// 結果を構造化
		$reading_result = array(
			'reading_data'    => array(),
			'data_num'        => $data_num,
			'total_stay_time' => $total_stay_time,
		);

		// ソート用の配列
		$sort_keys = array();
		foreach ( $merge_att_scr_ary as $idx => $values ) {
			$sort_keys[ $idx ] = $values[0]; // STAY_HEIGHT
		}

		// STAY_HEIGHTでソート
		array_multisort( $sort_keys, SORT_ASC, $merge_att_scr_ary );

		// 構造化したデータを作成
		foreach ( $merge_att_scr_ary as $values ) {
			$reading_result['reading_data'][] = array(
				'stay_height' => $values[0], // STAY_HEIGHT
				'stay_time'   => $values[1],   // STAY_TIME
				'stay_num'    => $values[2],    // STAY_NUM
				'exit_num'    => $values[3],     // EXIT_NUM
			);
		}

		return $reading_result;
	}

	/**
	 * バージョンIDからbase_selectorを取得
	 *
	 * @param int $version_id バージョンID
	 * @return string|null base_selector文字列
	 */
	private function get_base_selector( $version_id ) {
		global $qahm_db;

		$table_name = 'view_page_version_hist';
		$query      = 'SELECT base_selector FROM ' . $qahm_db->prefix . $table_name . ' WHERE version_id = %d';
		$result     = $qahm_db->get_var( $qahm_db->prepare( $query, $version_id ) );

		return $result;
	}

	/**
	 * サーチコンソールのLPクエリデータを取得（page_id指定）
	 * @param string $tracking_id トラッキングID
	 * @param int $page_id ページID
	 * @param string $start_date 開始日 (Y-m-d)
	 * @param string $end_date 終了日 (Y-m-d)
	 * @return array 該当ページのGSCデータ。以下の構造を持つ：
	 *     [
	 *         'page_id' => (int) ランディングページID,
	 *         'wp_qa_type' => (string) WP用識別タイプ,
	 *         'wp_qa_id' => (int) WP用ID,
	 *         'title' => (string) LPのタイトル,
	 *         'url' => (string) LPのURL,
	 *         'query' => [  // クエリ情報の配列
	 *             [
	 *                 'query_id' => (int) クエリID,
	 *                 'keyword' => (string) 検索キーワード,
	 *                 'search_type' => (int) 検索タイプ (1:web, 2:image, 3:video),
	 *                 'impressions' => (int) 表示回数,
	 *                 'clicks' => (int) クリック数,
	 *                 'position' => (float) 検索順位
	 *             ],
	 *             ...
	 *         ]
	 *     ]
	 */
	public function get_gsc_data_by_page_id( $tracking_id, $page_id, $start_date, $end_date ) {
		$start_date_obj = new DateTime( $start_date );
		$end_date_obj   = new DateTime( $end_date );
		$current_date   = clone $start_date_obj;

		$result = null;

		while ( $current_date <= $end_date_obj ) {
			$month_start = $current_date->format( 'Y-m-01' );
			$file_path   = $this->data_dir . "/view/{$tracking_id}/gsc/{$month_start}_gsc_lp_query_1mon.php";

			if ( ! $this->wrap_exists( $file_path ) ) {
				$current_date->modify( '+1 month' );
				continue;
			}

			$data = $this->load_file( $file_path );

			if ( $data === null ) {
				$file_content = $this->wrap_get_contents( $file_path );
				if ( $file_content !== false ) {

					$data_start = $this->wrap_strpos( $file_content, 'a:' );
					if ( $data_start !== false ) {
						$serialized_data = $this->wrap_substr( $file_content, $data_start );

						$data = $this->wrap_unserialize( $serialized_data );
					} else {
					}
				} else {
				}
			}

			if ( $data !== null && ! is_array( $data ) ) {
				$data = $this->wrap_unserialize( $data );
			}

			if ( $data && is_array( $data ) ) {

				$found_page_ids = array();
				foreach ( $data as $page_data ) {
					if ( isset( $page_data['page_id'] ) ) {
						$found_page_ids[] = $page_data['page_id'];
						if ( $page_data['page_id'] == $page_id ) {
							$result = $page_data;
							break 2;
						}
					}
				}

				$unique_page_ids = array_unique( $found_page_ids );
				sort( $unique_page_ids );
			} else {
			}

			$current_date->modify( '+1 month' );
		}

		return $result;
	}

	/**
	 * 複数ページIDのGSCデータを一括取得（日次ファイル対応版）
	 * @param string $tracking_id トラッキングID
	 * @param array $page_ids ページID配列
	 * @param string $start_date 開始日 (Y-m-d)
	 * @param string $end_date 終了日 (Y-m-d)
	 * @return array ページIDをキーとする連想配列。各要素は以下の構造を持つ：
	 *     [
	 *         {page_id} => [
	 *             'wp_qa_type' => (int),
	 *             'wp_qa_id' => (int),
	 *             'page_id' => (int),
	 *             'title' => (string),
	 *             'url' => (string),
	 *             'query' => [
	 *                 {search_type} => [
	 *                     'query' => [
	 *                         [
	 *                             'query_id' => (int),
	 *                             'keyword' => (string),
	 *                             'search_type' => (int),
	 *                             'clicks' => (int),
	 *                             'impressions' => (int),
	 *                             'position' => (float)
	 *                         ],
	 *                         ...
	 *                     ]
	 *                 ],
	 *                 ...
	 *             ]
	 *         ],
	 *         ...
	 *     ]
	 */
	public function get_gsc_data_by_page_ids( $tracking_id, $page_ids, $start_date, $end_date ) {
		$start_date_obj = new DateTime( $start_date );
		$end_date_obj   = new DateTime( $end_date );
		$current_date   = clone $start_date_obj;

		$results    = array();
		$daily_data = array();

		while ( $current_date <= $end_date_obj ) {
			$date_str  = $current_date->format( 'Y-m-d' );
			$file_path = $this->data_dir . "/view/{$tracking_id}/gsc/{$date_str}_gsc_lp_query.php";

			$data = $this->load_file( $file_path );
			if ( $data && is_array( $data ) ) {
				foreach ( $data as $page_data ) {
					if ( isset( $page_data['page_id'] ) ) {
						$page_id = $page_data['page_id'];
						if ( $this->wrap_in_array( $page_id, $page_ids ) ) {
							if ( ! isset( $daily_data[ $page_id ] ) ) {
								$daily_data[ $page_id ] = array();
							}
							$daily_data[ $page_id ][] = $page_data;
						}
					}
				}
			}

			$current_date->modify( '+1 day' );
		}

		foreach ( $daily_data as $page_id => $page_data_array ) {
			$results[ $page_id ] = $this->aggregate_gsc_page_data( $page_data_array );
		}

		return $results;
	}

	/**
	 * 複数日のGSCページデータを統合
	 * @param array $page_data_array 同一page_idの複数日データ配列
	 * @return array 統合されたページデータ
	 */
	public function aggregate_gsc_page_data( $page_data_array ) {
		if ( empty( $page_data_array ) ) {
			return array();
		}

		$first_data = $page_data_array[0];
		$aggregated = array(
			'wp_qa_type' => $first_data['wp_qa_type'] ?? null,
			'wp_qa_id'   => $first_data['wp_qa_id'] ?? null,
			'page_id'    => $first_data['page_id'] ?? null,
			'title'      => $first_data['title'] ?? '',
			'url'        => $first_data['url'] ?? '',
			'query'      => array(),
		);

		$query_aggregation = array();

		foreach ( $page_data_array as $page_data ) {
			if ( ! isset( $page_data['query'] ) || ! is_array( $page_data['query'] ) ) {
				continue;
			}

			foreach ( $page_data['query'] as $query_item ) {
				if ( ! is_array( $query_item ) ) {
					continue;
				}

				$query_id    = $query_item['query_id'] ?? null;
				$search_type = $query_item['search_type'] ?? 1;

				if ( $query_id === null ) {
					continue;
				}

				if ( ! isset( $query_aggregation[ $search_type ] ) ) {
					$query_aggregation[ $search_type ] = array();
				}

				if ( ! isset( $query_aggregation[ $search_type ][ $query_id ] ) ) {
					$query_aggregation[ $search_type ][ $query_id ] = array(
						'query_id'                => $query_id,
						'keyword'                 => $query_item['keyword'] ?? '',
						'search_type'             => $search_type,
						'clicks'                  => (int) ( $query_item['clicks'] ?? 0 ),
						'impressions'             => (int) ( $query_item['impressions'] ?? 0 ),
						'position'                => (float) ( $query_item['position'] ?? 0 ),
						'total_weighted_position' => (float) ( $query_item['position'] ?? 0 ) * (int) ( $query_item['impressions'] ?? 0 ),
					);
				} else {
					$existing        = &$query_aggregation[ $search_type ][ $query_id ];
					$new_clicks      = (int) ( $query_item['clicks'] ?? 0 );
					$new_impressions = (int) ( $query_item['impressions'] ?? 0 );
					$new_position    = (float) ( $query_item['position'] ?? 0 );

					$existing['clicks']                  += $new_clicks;
					$existing['impressions']             += $new_impressions;
					$existing['total_weighted_position'] += $new_position * $new_impressions;
				}
			}
		}

		foreach ( $query_aggregation as $search_type => $queries ) {
			$aggregated['query'][ $search_type ] = array( 'query' => array() );

			foreach ( $queries as $query_data ) {
				if ( $query_data['impressions'] > 0 ) {
					$query_data['position'] = $query_data['total_weighted_position'] / $query_data['impressions'];
				} else {
					$query_data['position'] = 0;
				}

				unset( $query_data['total_weighted_position'] );

				$aggregated['query'][ $search_type ]['query'][] = $query_data;
			}
		}

		return $aggregated;
	}

	/**
	 * 目標データを目標番号と期間で取得（改良版）
	 * @param string $tracking_id トラッキングID
	 * @param int $goal_number 目標番号
	 * @param string $start_date 開始日 (Y-m-d)
	 * @param string $end_date 終了日 (Y-m-d)
	 * @return array ゴール達成セッションデータ。既存のget_goal()と同じ構造：
	 *     [
	 *         [  // セッション1
	 *             [  // PV1
	 *                 'pv_id' => (int) PV ID,
	 *                 'reader_id' => (int) リーダーID,
	 *                 'UAos' => (string) OSユーザーエージェント,
	 *                 'UAbrowser' => (string) ブラウザユーザーエージェント,
	 *                 'language' => (string) 言語,
	 *                 'is_reject' => (int) リジェクトフラグ,
	 *                 'page_id' => (int) ページID,
	 *                 'url' => (string) URL,
	 *                 'title' => (string) ページタイトル,
	 *                 'access_time' => (int) アクセスタイムスタンプ,
	 *                 'device_id' => (int) デバイスID,
	 *                 'version_id' => (int) バージョンID,
	 *                 // ... その他のPVデータフィールド
	 *             ],
	 *             ...  // PV2, PV3, ...
	 *         ],
	 *         ...  // セッション2, セッション3, ...
	 *     ]
	 */
	public function get_goal_data_by_number( $tracking_id, $goal_number, $start_date, $end_date ) {
		$start_date_obj = new DateTime( $start_date );
		$end_date_obj   = new DateTime( $end_date );
		$current_date   = clone $start_date_obj;

		$results = array();

		while ( $current_date <= $end_date_obj ) {
			$month_start = $current_date->format( 'Y-m-01' );
			$file_path   = $this->data_dir . "/view/{$tracking_id}/summary/{$month_start}_goal_{$goal_number}_1mon.php";

			$file_data = $this->load_file( $file_path );
			if ( $file_data ) {
				foreach ( $file_data as $session_data ) {
					$session_in_range = false;
					foreach ( $session_data as $pv_data ) {
						$pv_time = $pv_data['access_time'];
						$pv_date = gmdate( 'Y-m-d', $pv_time );

						if ( $pv_date >= $start_date && $pv_date <= $end_date ) {
							$session_in_range = true;
							break;
						}
					}

					if ( $session_in_range ) {
						$results[] = $session_data;
					}
				}
			}

			$current_date->modify( '+1 month' );
		}

		return $results;
	}

	/**
	 * 複数目標の達成データを一括取得
	 * @param string $tracking_id トラッキングID
	 * @param array $goal_numbers 目標番号配列
	 * @param string $start_date 開始日 (Y-m-d)
	 * @param string $end_date 終了日 (Y-m-d)
	 * @return array 目標番号をキーとする連想配列。各要素は上記get_goal_data_by_numberと同じ構造
	 *     [
	 *         {goal_number} => [
	 *             [  // セッション1
	 *                 [...] // PVデータ配列
	 *             ],
	 *             ...
	 *         ],
	 *         ...
	 *     ]
	 */
	public function get_multiple_goals_data( $tracking_id, $goal_numbers, $start_date, $end_date ) {
		$results = array();

		foreach ( $goal_numbers as $goal_number ) {
			$goal_data = $this->get_goal_data_by_number( $tracking_id, $goal_number, $start_date, $end_date );
			if ( ! empty( $goal_data ) ) {
				$results[ $goal_number ] = $goal_data;
			}
		}

		return $results;
	}

	/**
	 * バージョンIDからHTMLソースを取得
	 * @param int $version_id バージョンID
	 * @return array|null HTMLソース情報。以下の構造を持つ：
	 *     [
	 *         'version_id' => (int) バージョンID,
	 *         'version_no' => (int) バージョン番号,
	 *         'html_source' => (string) HTMLソース文字列,
	 *         'page_id' => (int) ページID,
	 *         'url' => (string) ページURL,
	 *         'title' => (string) ページタイトル,
	 *         'created_at' => (string) 作成日時,
	 *         'base_selector' => (string) ベースセレクタ（ヒートマップ用）
	 *     ]
	 *     ファイルが存在しない場合はnull
	 */
	public function get_html_source_by_version_id( $version_id ) {
		$data_dir         = $this->get_data_dir_path();
		$verhist_dir      = $data_dir . 'view/all/version_hist/';
		$verhist_filename = $version_id . '_version.php';
		$file_path        = $verhist_dir . $verhist_filename;

		if ( ! $this->wrap_exists( $file_path ) ) {
			return null;
		}

		$verhist_file = $this->wrap_get_contents( $file_path );
		if ( ! $verhist_file ) {
			return null;
		}

		$verhist_data = $this->wrap_unserialize( $verhist_file );
		if ( ! $verhist_data || ! is_array( $verhist_data ) || empty( $verhist_data ) ) {
			return null;
		}

		$version_info = $verhist_data[0];

		$html_source = '';
		$page_url    = '';
		if ( isset( $version_info->base_html ) && ! empty( $version_info->base_html ) ) {
			$html_source = $version_info->base_html;
		} elseif ( isset( $version_info->page_id ) && ! empty( $version_info->page_id ) ) {
			$page_data = QAHM_DB_Functions::get_qa_pages( $version_info->page_id );
			if ( $page_data && ! empty( $page_data['url'] ) ) {
				$page_url    = $page_data['url'];
				$device_name = 'dsk'; // Default to desktop
				if ( isset( $version_info->device_id ) ) {
					$device_name = $this->device_id_to_device_name( $version_info->device_id );
				}

				$response = $this->wrap_remote_get( $page_data['url'], $device_name );
				if ( ! is_wp_error( $response ) &&
					( $response['response']['code'] === 200 || $response['response']['code'] === 404 ) ) {
					$html_source = $response['body'];

					if ( $this->is_zip( $html_source ) ) {
						$temphtml = gzdecode( $html_source );
						if ( $temphtml !== false ) {
							$html_source = $temphtml;
						}
					}
				}
			}
		}

		$result = array(
			'version_id'    => (int) $version_id,
			'version_no'    => isset( $version_info->version_no ) ? (int) $version_info->version_no : null,
			'html_source'   => $html_source,
			'page_id'       => isset( $version_info->page_id ) ? (int) $version_info->page_id : null,
			'url'           => ! empty( $page_url ) ? $page_url : ( isset( $version_info->url ) ? $version_info->url : '' ),
			'title'         => isset( $version_info->title ) ? $version_info->title : '',
			'created_at'    => isset( $version_info->update_date ) ? $version_info->update_date : '',
			'base_selector' => isset( $version_info->base_selector ) ? $version_info->base_selector : '',
		);

		return $result;
	}

	/**
	 * ページIDと日付からバージョンを特定してHTMLソースを取得
	 * @param string $tracking_id トラッキングID
	 * @param int $page_id ページID
	 * @param string $date 日付 (Y-m-d)
	 * @return array バージョン情報とHTMLソース。以下の構造を持つ：
	 *     [
	 *         'matched_versions' => [
	 *             [
	 *                 'version_id' => (int),
	 *                 'version_no' => (int),
	 *                 'html_source' => (string),
	 *                 // ... 上記get_html_source_by_version_idと同じ構造
	 *             ],
	 *             ...
	 *         ],
	 *         'page_info' => [
	 *             'page_id' => (int),
	 *             'url' => (string),
	 *             'title' => (string)
	 *         ]
	 *     ]
	 */
	public function get_html_source_by_page_and_date( $tracking_id, $page_id, $date ) {
		global $qahm_db;

		$table_name = $qahm_db->prefix . 'qa_page_version_hist';
		$query      = $qahm_db->prepare(
			"SELECT version_id, page_id, url, title FROM {$table_name} 
             WHERE page_id = %d AND DATE(update_date) = %s 
             ORDER BY update_date DESC",
			$page_id,
			$date
		);

		$version_records = $qahm_db->get_results( $query );

		$result = array(
			'matched_versions' => array(),
			'page_info'        => array(
				'page_id' => $page_id,
				'url'     => '',
				'title'   => '',
			),
		);

		if ( ! empty( $version_records ) ) {
			$result['page_info']['url']   = $version_records[0]->url;
			$result['page_info']['title'] = $version_records[0]->title;

			foreach ( $version_records as $record ) {
				$html_source_data = $this->get_html_source_by_version_id( $record->version_id );
				if ( $html_source_data ) {
					$result['matched_versions'][] = $html_source_data;
				}
			}
		}

		return $result;
	}

	/**
	 * 複数バージョンのHTMLソースを一括取得
	 * @param array $version_ids バージョンID配列
	 * @return array バージョンIDをキーとする連想配列。各要素は上記get_html_source_by_version_idと同じ構造
	 *     [
	 *         {version_id} => [
	 *             'version_id' => (int),
	 *             'version_no' => (int),
	 *             'html_source' => (string),
	 *             // ... 上記と同じ構造
	 *         ],
	 *         ...
	 *     ]
	 *     存在しないバージョンIDはキーごと除外される
	 */
	public function get_multiple_html_sources( $version_ids ) {
		$results = array();

		foreach ( $version_ids as $version_id ) {
			$html_source_data = $this->get_html_source_by_version_id( $version_id );
			if ( $html_source_data ) {
				$results[ $version_id ] = $html_source_data;
			}
		}

		return $results;
	}
}
