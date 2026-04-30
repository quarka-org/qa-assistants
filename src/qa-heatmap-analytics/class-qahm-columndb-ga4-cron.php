<?php
/**
 * GA4列DB 夜間バッチ変換クラス
 *
 * GA4中間ファイル（ga4/YYYY-MM_ga4_*.php）を列DB（ga4_age_gender / ga4_country / ga4_region）に変換。
 * 月次単位で処理。
 *
 * cronフロー:
 *   Night>Make column-db ga4>Start -> Night>Make column-db ga4>Loop -> Night>Make column-db ga4>End
 *
 * @package qa_heatmap
 */

class QAHM_ColumnDB_Ga4Cron extends QAHM_Base {

	/**
	 * 制限時間（秒）
	 */
	const TIME_LIMIT_SEC = 300;

	/**
	 * メモファイル名
	 */
	const MEMO_FILE = 'cron_columndb_ga4_target_memo.php';

	/**
	 * 年齢帯文字列→ID変換マップ
	 */
	const AGE_BRACKET_MAP = array(
		'unknown' => 0,
		'18-24'   => 1,
		'25-34'   => 2,
		'35-44'   => 3,
		'45-54'   => 4,
		'55-64'   => 5,
		'65+'     => 6,
	);

	/**
	 * 性別文字列→ID変換マップ
	 */
	const GENDER_MAP = array(
		'unknown' => 0,
		'male'    => 1,
		'female'  => 2,
	);

	/**
	 * 都道府県名→ID変換マップ
	 */
	const REGION_MAP = array(
		'Hokkaido'  => 1,  'Aomori'    => 2,  'Iwate'     => 3,  'Miyagi'    => 4,
		'Akita'     => 5,  'Yamagata'  => 6,  'Fukushima' => 7,
		'Ibaraki'   => 8,  'Tochigi'   => 9,  'Gunma'     => 10, 'Saitama'   => 11,
		'Chiba'     => 12, 'Tokyo'     => 13, 'Kanagawa'  => 14,
		'Niigata'   => 15, 'Toyama'    => 16, 'Ishikawa'  => 17, 'Fukui'     => 18,
		'Yamanashi' => 19, 'Nagano'    => 20,
		'Gifu'      => 21, 'Shizuoka'  => 22, 'Aichi'     => 23, 'Mie'       => 24,
		'Shiga'     => 25, 'Kyoto'     => 26, 'Osaka'     => 27, 'Hyogo'     => 28,
		'Nara'      => 29, 'Wakayama'  => 30,
		'Tottori'   => 31, 'Shimane'   => 32, 'Okayama'   => 33, 'Hiroshima' => 34, 'Yamaguchi' => 35,
		'Tokushima' => 36, 'Kagawa'    => 37, 'Ehime'     => 38, 'Kochi'     => 39,
		'Fukuoka'   => 40, 'Saga'      => 41, 'Nagasaki'  => 42, 'Kumamoto'  => 43,
		'Oita'      => 44, 'Miyazaki'  => 45, 'Kagoshima' => 46, 'Okinawa'   => 47,
	);

	/**
	 * cronステップ: Start
	 *
	 * tracking_id一覧と未処理月を取得し、処理対象リストを作成する。
	 *
	 * @return string 次のcronステータス
	 */
	public function start() {
		global $qahm_data_api, $qahm_log;

		$temp_dir = $this->get_data_dir_path( 'temp' );

		// tracking_id一覧を取得
		$siteary = $qahm_data_api->get_sitemanage();
		$targets = array();

		if ( ! empty( $siteary ) ) {
			foreach ( $siteary as $site ) {
				$tid = $site['tracking_id'];
				$months = $this->get_unprocessed_months( $tid );
				foreach ( $months as $ym ) {
					$targets[] = array(
						'tracking_id' => $tid,
						'year_month'  => $ym,
					);
				}
			}
		}

		if ( empty( $targets ) ) {
			if ( $qahm_log ) {
				$qahm_log->info( 'ColumnDB GA4 cron: No unprocessed months found' );
			}
			return 'Night>Make column-db ga4>End';
		}

		// 処理対象リストを保存
		$target_memo = array(
			'index'   => 0,
			'targets' => $targets,
		);
		$this->wrap_put_contents(
			$temp_dir . self::MEMO_FILE,
			$this->wrap_serialize( $target_memo )
		);

		if ( $qahm_log ) {
			$qahm_log->info( 'ColumnDB GA4 cron: Start (' . count( $targets ) . ' month(s) to process)' );
		}

		return 'Night>Make column-db ga4>Loop';
	}

	/**
	 * cronステップ: Loop
	 *
	 * プロセス内ループで複数月のGA4データを列DBに変換する。
	 * TIME_LIMIT_SEC超過時は次のcronプロセスに委譲。
	 *
	 * @return string 次のcronステータス
	 */
	public function process_loop() {
		global $qahm_log;

		$temp_dir   = $this->get_data_dir_path( 'temp' );
		$loop_start = time();

		// target_memoを読み込み
		$memo_slz = $this->wrap_get_contents( $temp_dir . self::MEMO_FILE );
		$memo     = $this->wrap_unserialize( $memo_slz );

		if ( ! $memo || ! isset( $memo['targets'] ) || ! isset( $memo['index'] ) ) {
			return 'Night>Make column-db ga4>End';
		}

		$index   = $memo['index'];
		$targets = $memo['targets'];
		$count   = count( $targets );

		// プロセス内ループ: 時間制限内で複数月を処理
		while ( $index < $count ) {
			$target      = $targets[ $index ];
			$tracking_id = $target['tracking_id'];
			$year_month  = $target['year_month'];

			$result = $this->convert_ga4_one_month( $tracking_id, $year_month );

			if ( ! $result && $qahm_log ) {
				$qahm_log->warning( 'ColumnDB GA4 conversion failed: ' . $tracking_id . ' / ' . $year_month );
			}

			$index++;

			// インデックスを進めて保存
			$memo['index'] = $index;
			$this->wrap_put_contents(
				$temp_dir . self::MEMO_FILE,
				$this->wrap_serialize( $memo )
			);

			// 時間制限チェック
			if ( ( time() - $loop_start ) > self::TIME_LIMIT_SEC ) {
				if ( $qahm_log ) {
					$qahm_log->info( 'ColumnDB GA4 cron: Time limit reached (' . $index . '/' . $count . ')' );
				}
				return 'Night>Make column-db ga4>Loop';
			}
		}

		// 全処理完了
		if ( $qahm_log ) {
			$qahm_log->info( 'ColumnDB GA4 cron: All months processed (' . $count . ')' );
		}
		return 'Night>Make column-db ga4>End';
	}

	/**
	 * cronステップ: End
	 *
	 * 一時ファイルを削除し、次のcronフローに遷移する。
	 *
	 * @return string 次のcronステータス
	 */
	public function end() {
		$temp_dir = $this->get_data_dir_path( 'temp' );
		$this->wrap_delete( $temp_dir . self::MEMO_FILE );

		return 'Night>Make Goal file>Start';
	}

	/**
	 * 未処理月を取得
	 *
	 * ga4中間ファイルが3種類揃っているが列DBが未生成の月を取得する。
	 *
	 * @param string $tracking_id 追跡ID
	 * @return array YYYY-MM形式の月の配列
	 */
	private function get_unprocessed_months( $tracking_id ) {
		$data_dir = $this->get_data_dir_path();
		$ga4_dir  = $data_dir . 'view/' . $tracking_id . '/ga4/';

		if ( ! is_dir( $ga4_dir ) ) {
			return array();
		}

		// 中間ファイルからYYYY-MM形式の月を収集
		// ファイル名形式: YYYY-MM_ga4_age_gender.php, YYYY-MM_ga4_country.php, YYYY-MM_ga4_region.php
		$files = glob( $ga4_dir . '*_ga4_*.php' );
		$month_reports = array(); // YYYY-MM => array of report types

		if ( $files ) {
			foreach ( $files as $file ) {
				$basename = basename( $file );
				// 先頭7文字が YYYY-MM 形式かチェック（preg_match不使用）
				if ( strlen( $basename ) < 8 ) {
					continue;
				}
				$ym_part = substr( $basename, 0, 7 );
				if ( strlen( $ym_part ) !== 7 || $ym_part[4] !== '-'
					|| ! ctype_digit( substr( $ym_part, 0, 4 ) )
					|| ! ctype_digit( substr( $ym_part, 5, 2 ) ) ) {
					continue;
				}

				// レポートタイプを判定
				$suffix = substr( $basename, 8 ); // "_ga4_" 以降
				if ( strpos( $basename, $ym_part . '_ga4_age_gender.php' ) === 0 ) {
					$month_reports[ $ym_part ]['age_gender'] = true;
				} elseif ( strpos( $basename, $ym_part . '_ga4_country.php' ) === 0 ) {
					$month_reports[ $ym_part ]['country'] = true;
				} elseif ( strpos( $basename, $ym_part . '_ga4_region.php' ) === 0 ) {
					$month_reports[ $ym_part ]['region'] = true;
				}
			}
		}

		// 3レポート揃っている月のみ対象
		$report_dir = $data_dir . 'report/' . $tracking_id . '/columns-db/';
		$unprocessed = array();

		foreach ( $month_reports as $ym => $types ) {
			if ( ! isset( $types['age_gender'] ) || ! isset( $types['country'] ) || ! isset( $types['region'] ) ) {
				continue;
			}

			// 列DB未生成チェック（age_bracketカラムの存在で判定）
			// 当月は毎晩再生成（中間ファイルが日々更新されるため）（T66）
			$ym_compact = str_replace( '-', '', $ym );
			$current_ym = gmdate( 'Y-m' );
			$check_file = $report_dir . 'ga4_age_gender/' . $ym_compact . '/ga4_age_gender_' . $ym_compact . '_age_bracket.php';
			if ( $ym === $current_ym ) {
				// Writerがappendモードのため、当月は既存列DBを削除してから再生成（T66）
				$ga4_types = array( 'ga4_age_gender', 'ga4_country', 'ga4_region' );
				$cleanup_ok = true;
				foreach ( $ga4_types as $ga4_type ) {
					$month_dir = $report_dir . $ga4_type . '/' . $ym_compact . '/';
					if ( is_dir( $month_dir ) ) {
						$files = glob( $month_dir . '*.php' );
						if ( $files ) {
							foreach ( $files as $f ) {
								if ( ! unlink( $f ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
									$cleanup_ok = false;
								}
							}
						}
						if ( $cleanup_ok && ! rmdir( $month_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
							$cleanup_ok = false;
						}
					}
				}
				if ( $cleanup_ok ) {
					$unprocessed[] = $ym;
				}
			} elseif ( ! $this->wrap_exists( $check_file ) ) {
				$unprocessed[] = $ym;
			}
		}

		// 降順ソート（新しい月→古い月）
		rsort( $unprocessed );

		return $unprocessed;
	}

	/**
	 * 1ヶ月分のGA4データを列DBに変換
	 *
	 * 3種類の中間ファイル（age_gender, country, region）を読み込み、
	 * それぞれ対応する列DBに変換する。
	 *
	 * @param string $tracking_id 追跡ID
	 * @param string $year_month YYYY-MM形式
	 * @return bool 成功/失敗
	 */
	private function convert_ga4_one_month( $tracking_id, $year_month ) {
		global $qahm_log;

		$data_dir   = $this->get_data_dir_path();
		$report_dir = $data_dir . 'report/' . $tracking_id . '/columns-db/';
		$ga4_source = $data_dir . 'view/' . $tracking_id . '/ga4/';

		// YYYY-MM → YYYYMM（列DBの日付キーとして使用）
		$ym_compact = str_replace( '-', '', $year_month );

		$total_processed = 0;

		// (i) age_gender
		$age_gender_file = $ga4_source . $year_month . '_ga4_age_gender.php';
		if ( $this->wrap_exists( $age_gender_file ) ) {
			$slz  = $this->wrap_get_contents( $age_gender_file );
			$data = $this->wrap_unserialize( $slz );

			if ( is_array( $data ) && isset( $data['rows'] ) && is_array( $data['rows'] ) ) {
				$ag_dir  = $report_dir . 'ga4_age_gender/';
				$writer  = new QAHM_ColumnDB_Writer( 'ga4_age_gender', $tracking_id, $ag_dir );

				foreach ( $data['rows'] as $row ) {
					$age_str    = isset( $row['dimensionValues'][0]['value'] ) ? $row['dimensionValues'][0]['value'] : 'unknown';
					$gender_str = isset( $row['dimensionValues'][1]['value'] ) ? $row['dimensionValues'][1]['value'] : 'unknown';
					$sessions   = isset( $row['metricValues'][0]['value'] ) ? (int) $row['metricValues'][0]['value'] : 0;
					$active     = isset( $row['metricValues'][1]['value'] ) ? (int) $row['metricValues'][1]['value'] : 0;

					$age_id    = isset( self::AGE_BRACKET_MAP[ $age_str ] ) ? self::AGE_BRACKET_MAP[ $age_str ] : 0;
					$gender_id = isset( self::GENDER_MAP[ $gender_str ] ) ? self::GENDER_MAP[ $gender_str ] : 0;

					$writer->write_row( array(
						'age_bracket'  => $age_id,
						'gender'       => $gender_id,
						'sessions'     => $sessions,
						'active_users' => $active,
					), $ym_compact );

					$total_processed++;
				}

				$writer->finalize();
			}
		}

		// (ii) country
		$country_file = $ga4_source . $year_month . '_ga4_country.php';
		if ( $this->wrap_exists( $country_file ) ) {
			$slz  = $this->wrap_get_contents( $country_file );
			$data = $this->wrap_unserialize( $slz );

			if ( is_array( $data ) && isset( $data['rows'] ) && is_array( $data['rows'] ) ) {
				$co_dir = $report_dir . 'ga4_country/';
				$writer = new QAHM_ColumnDB_Writer( 'ga4_country', $tracking_id, $co_dir );

				foreach ( $data['rows'] as $row ) {
					$country_str = isset( $row['dimensionValues'][0]['value'] ) ? $row['dimensionValues'][0]['value'] : '';
					$sessions    = isset( $row['metricValues'][0]['value'] ) ? (int) $row['metricValues'][0]['value'] : 0;
					$active      = isset( $row['metricValues'][1]['value'] ) ? (int) $row['metricValues'][1]['value'] : 0;

					// ISO 3166-1 alpha-2国コード→uint16（2文字のASCII値変換）
					$country_id = 0;
					if ( strlen( $country_str ) === 2 ) {
						$country_id = ord( $country_str[0] ) * 256 + ord( $country_str[1] );
					}

					$writer->write_row( array(
						'country_id'   => $country_id,
						'sessions'     => $sessions,
						'active_users' => $active,
					), $ym_compact );

					$total_processed++;
				}

				$writer->finalize();
			}
		}

		// (iii) region
		$region_file = $ga4_source . $year_month . '_ga4_region.php';
		if ( $this->wrap_exists( $region_file ) ) {
			$slz  = $this->wrap_get_contents( $region_file );
			$data = $this->wrap_unserialize( $slz );

			if ( is_array( $data ) && isset( $data['rows'] ) && is_array( $data['rows'] ) ) {
				$rg_dir = $report_dir . 'ga4_region/';
				$writer = new QAHM_ColumnDB_Writer( 'ga4_region', $tracking_id, $rg_dir );

				foreach ( $data['rows'] as $row ) {
					$region_str = isset( $row['dimensionValues'][0]['value'] ) ? $row['dimensionValues'][0]['value'] : '';
					$sessions   = isset( $row['metricValues'][0]['value'] ) ? (int) $row['metricValues'][0]['value'] : 0;
					$active     = isset( $row['metricValues'][1]['value'] ) ? (int) $row['metricValues'][1]['value'] : 0;

					// 都道府県名→ID（マッチしなければ0=その他）
					$region_id = 0;
					if ( isset( self::REGION_MAP[ $region_str ] ) ) {
						$region_id = self::REGION_MAP[ $region_str ];
					} else {
						// "Osaka Prefecture" のような名称から "Osaka" を抽出して再照合
						$space_pos = strpos( $region_str, ' ' );
						if ( $space_pos !== false ) {
							$short_name = substr( $region_str, 0, $space_pos );
							if ( isset( self::REGION_MAP[ $short_name ] ) ) {
								$region_id = self::REGION_MAP[ $short_name ];
							}
						}
					}

					$writer->write_row( array(
						'region_id'    => $region_id,
						'sessions'     => $sessions,
						'active_users' => $active,
					), $ym_compact );

					$total_processed++;
				}

				$writer->finalize();
			}
		}

		if ( $qahm_log ) {
			$qahm_log->info( 'ColumnDB GA4 converted: ' . $tracking_id . ' / ' . $year_month . ' (' . $total_processed . ' records)' );
		}

		return true;
	}
}
