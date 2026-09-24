<?php
defined( 'ABSPATH' ) || exit;
/**
 * QAデータ（DBやファイルに入っている）にアクセスするためのラッパークラス
 * wpdbと似たコマンドを揃え、SQLに対応するが、セキュリティ対策も含め、決まったいくつかのコマンド(限定されたSELECTなど）しか受け付けないようにする。
 * またwpdbのprepareは単にSQLインジェクション対策を施したSQL文字列を返すだけなので（つまり純粋なDBのprepareとは違う）、そのまま活用することができる。
 * QAの関数群は、このqahm_dbを利用することでデータがどこに保存されているのかを意識せずに任意のデータをひくことができる。
 * @package qa_heatmap
 */

$GLOBALS['qahm_db'] = new QAHM_Db();
class QAHM_Db extends QAHM_File_Data {

	public $prefix;
	public $insert_id;
	public $last_error;

	/**
	 * view_pv 廃止 RM4-R1 PR-a #1408: R1 の index-free 経路（get_results_view_pv の date-range／pv_id、
	 * get_vr_view_session の pv_id 経路）を view_pv 行ファイル読みから allpv 列DB（共有ビルダー
	 * build_allpv_rows_for_date #1402/#1406）読みへ切り替えるフラグ。
	 *
	 * true で ON。ON の行は rm_r1_project_viewpv_row で view_pv 行（cron-proc 生成の29キー・型）へ忠実に
	 * 射影する（TSV ダウンロード等、行キー＝列で消費する消費者があるため）。セッション復元は physical
	 * adjacency 遡りでなく allpv ネイティブの session_id グループで行う（日跨ぎ境界の一致は #1406
	 * daycross-verification.md で実証済み）。
	 * 未 done／保持窓外／不整合を検出したら null で従来 view_pv 経路へ all-or-nothing フォールバック
	 * （移行期の view_pv 併存・安全側）。OFF（既定）は従来経路を逐語温存＝本番ゼロ変化。
	 * page_id／version_id where（view_pv index ファイル駆動）は PR-b（別 Issue）。
	 */
	const RM_R1_INDEXFREE_ALLPV_ENABLED = false;

	/**
	 * view_pv 廃止 RM4-R1 PR-b #1412: R1 の index 駆動経路（get_vr_view_session の page_id 経路・
	 * get_results_view_pv の page_id 分岐〔ヒートマップ生成 view-heatmap が直接消費〕）を view_pv index
	 * ファイル駆動から allpv 列DB（find_allpv_pv_ids_for_date #1406＝軽量列スキャン＋build_allpv_rows_for_date）
	 * 読みへ切り替えるフラグ。
	 *
	 * version_id 分岐（get_results_view_pv）は実消費者なし（ヒートマップは 2023/12 に page_id+date クエリへ
	 * 移行済み・コメントアウトで現存）＝移行対象外・P7-X で view_pv ごと退役。
	 * page_id 分岐の date 無し呼び出しも実消費者なし＝ON 対象外（従来経路のまま）。
	 * 忠実性の規約（view_pv 忠実 row アダプタ・all-or-nothing フォールバック・pv_id 昇順）は PR-a #1408 と共通。
	 * OFF（既定）は従来経路を逐語温存＝本番ゼロ変化。
	 */
	const RM_R1_INDEX_PATHS_ALLPV_ENABLED = false;

	/**
	 * const
	 */
	const QAHM_VIEW_PV_COL = array(
		'pv_id',
		'reader_id',
		'UAos',
		'UAbrowser',
		'language',
		'country_code',
		'page_id',
		'url',
		'title',
		'device_id',
		'source_id',
		'utm_source',
		'source_domain',
		'medium_id',
		'utm_medium',
		'campaign_id',
		'utm_campaign',
		'session_no',
		'access_time',
		'pv',
		'speed_msec',
		'browse_sec',
		'is_last',
		'is_newuser',
		'version_id',
		'is_raw_p',
		'is_raw_c',
		'is_raw_e',
	);

	const QAHM_VR_VIEW_PV_COL = array(
		'pv_id',
		'reader_id',
		'UAos',
		'UAbrowser',
		'page_id',
		'url',
		'title',
		'device_id',
		'source_id',
		'utm_source',
		'source_domain',
		'medium_id',
		'utm_medium',
		'campaign_id',
		'utm_campaign',
		'session_no',
		'access_time',
		'pv',
		'speed_msec',
		'browse_sec',
		'is_last',
		'is_newuser',
		'version_id',
		'is_raw_p',
		'is_raw_c',
		'is_raw_e',
		'version_no',
	);

	const QAHM_SUMMARY_DAYS_ACCESS_DETAIL = array(
		'date',
		'device_id',
		'utm_source',
		'utm_medium',
		'utm_campaign',
		'is_newuser',
		'is_QA',
		'pv_count',
		'session_count',
		'user_count',
		'bounce_count',
		'time_on_page',
	);

	const QAHM_VR_SUMMARY_ALLPAGE_COL = array(
		'date',
		'page_id',
		'device_id',
		'utm_source',
		'utm_medium',
		'utm_campaign',
		'is_newuser',
		'is_QA',
		'pv_count',
		'user_count',
		'bounce_count',
		'exit_count',
		'time_on_page',
		'lp_count',
		'title',
		'url',
		'wp_qa_id',
	);

	const QAHM_VR_SUMMARY_LANDINGPAGE_COL = array(
		'date',
		'page_id',
		'device_id',
		'utm_source',
		'utm_medium',
		'utm_campaign',
		'is_newuser',
		'second_page',
		'is_QA',
		'pv_count',
		'session_count',
		'user_count',
		'bounce_count',
		'session_time',
		'title',
		'url',
		'wp_qa_id',
	);

	const QAHM_VR_SUMMARY_GROWTHPAGE_COL = array(
		'page_id',
		'device_id',
		'utm_source',
		'utm_medium',
		'start_session_count',
		'end_session_count',
		'title',
		'url',
		'wp_qa_id',
	);

	private $version_hist_dirlist_mem; //QA ZERO add
	private $view_pv_cache; //QA ZERO add

	public function __construct() {
		// WordPressはコアの初期化→dbの初期化→その他の初期化（プラグイン含む）といった流れになるので
		// コンストラクタの時点でおそらくwpdbが読み込まれているはず
		// ダメならフックを用いて以下の処理の実行タイミングを変えるべき imai
		global $wpdb;
		$this->prefix     = $wpdb->prefix;
		$this->insert_id  = 0;
		$this->last_error = '';

		$this->version_hist_dirlist_mem_reset();
		$this->view_pv_cache_reset();
	}

	/**
	 * useful
	 */
	public function alltable_name() {
		$tbname_ary = array();
		foreach ( QAHM_DB_OPTIONS as $key => $val ) {
			$tbname       = $this->wrap_substr( $key, 0, -8 );
			$tbname_ary[] = $this->prefix . $tbname;
		}
		return $tbname_ary;
	}
	/**
	 * prepare
	 * Wrapper for $wpdb->prepare().
	 *
	 */
	public function prepare( $query, ...$args ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared below; no post-prepare mutation; identifiers fixed/whitelisted.
		return $wpdb->prepare( $query, ...$args );
	}

	/**
	 * print_error
	 */
	public function print_error() {
		global $wpdb;
		return $wpdb->print_error();
	}

	/**
	 * result
	 * Proxy for wpdb->get_results() with routing by table.
	 *
	 * @phpcsSuppress WordPress.DB.PreparedSQL.NotPrepared
	 */
	public function get_results( $query = null, $output = OBJECT, $connect_tid = 'all' ) {
		global $wpdb;

		//switch function from table name
		$tb_view_pv                = $this->prefix . 'view_pv';
		$tb_vr_view_pv             = $this->prefix . 'vr_view_pv';
		$tb_view_ver               = $this->prefix . 'view_page_version_hist';
		$tb_days_access            = $this->prefix . 'summary_days_access';
		$tb_days_access_detail     = $this->prefix . 'summary_days_access_detail';
		$tb_vr_summary_allpage     = $this->prefix . 'vr_summary_allpage';
		$tb_vr_summary_landingpage = $this->prefix . 'vr_summary_landingpage';

		if ( preg_match( '/from ' . $tb_vr_view_pv . '/i', $query ) ) {
			return $this->get_results_view_pv( $query, true, $connect_tid );
		} elseif ( preg_match( '/from ' . $tb_view_pv . '/i', $query ) ) {
			return $this->get_results_view_pv( $query, false, $connect_tid );
		} elseif ( preg_match( '/from ' . $tb_view_ver . '/i', $query ) ) {
			return $this->get_results_view_page_version_hist( $query, $connect_tid );
		} elseif ( preg_match( '/from ' . $tb_days_access_detail . ' /i', $query ) ) {
			return $this->get_results_days_access_detail( $query, $connect_tid );
		} elseif ( preg_match( '/from ' . $tb_days_access . ' /i', $query ) ) {
			return $this->get_results_days_access( $query, $connect_tid );
		} elseif ( preg_match( '/from ' . $tb_vr_summary_allpage . ' /i', $query ) ) {
			return $this->get_results_vr_summary_allpage( $query, $connect_tid );
		} elseif ( preg_match( '/from ' . $tb_vr_summary_landingpage . ' /i', $query ) ) {
			return $this->get_results_vr_summary_landingpage( $query, $connect_tid );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query may be pre-processed in this proxy; identifiers fixed/whitelisted.
			return $wpdb->get_results( $query, $output );
		}
	}


	/**
	 * result
	 */
	/**
	 * 該当セッションを取得して返す
	 * QA ZERO $connect_tid を引数に追加
	 */
	public function get_vr_view_session( $column, $date, $where, $count = false, $connect_tid = 'all' ) {
		global $wp_filesystem;
		global $qahm_time;

		$tracking_id = $connect_tid;

		/*
		メモ

		idの優先順位（数が少ない方から優先 / いまはpage_id,pv_idのみ対応）
		reader_id
		page_id
		version_id
		campaign_id
		source_id
		medium_id

		*/

		// date 必須のため、適切な形になっていないならnullを返す
		// もしも単一の日付で検索することが今後あるなら「if ( strptime( $date_or_id, '%Y-%m-%d' ) ) {」で判定すれば良さそう
		if ( ! preg_match( '/^date\s*=\s*between\s*([0-9]{4}.[0-9]{2}.[0-9]{2})\s*and\s*([0-9]{4}.[0-9]{2}.[0-9]{2})$/', $date, $date_strings ) ) {
			return null;
		}

		$s_daystr = $date_strings[1];
		$e_daystr = $date_strings[2];
		if ( ! $s_daystr || ! $e_daystr ) {
			return null;
		}
		$s_unixtime = $qahm_time->str_to_unixtime( $s_daystr . ' 00:00:00' );
		$e_unixtime = $qahm_time->str_to_unixtime( $e_daystr . ' 23:59:59' );

		$date_period_ary = new DatePeriod(
			new DateTime( $s_daystr . ' 00:00:00' ),
			new DateInterval( 'P1D' ),
			new DateTime( $e_daystr . ' 23:59:59' )
		);

		// column配列を作成
		$column     = str_replace( ' ', '', $column );
		$column_ary = $this->wrap_explode( ',', $column );

		$view_dir       = $this->get_data_dir_path( 'view' );
		$viewpv_dir     = $view_dir . $tracking_id . '/view_pv/';
		$viewpv_idx_dir = $viewpv_dir . 'index/';
		// $verhist_dir = $view_dir . $tracking_id . '/version_hist/';
		$verhist_dir = $view_dir . 'all' . '/version_hist/';

		// AND に対応するときは事前にexplode関数で配列に分割するとよさそう

		// whereの構文が対応不可ならnullを返す
		//mkmod 20220617
		$whereok = false;
		$ids_ary = array();
		//単一
		preg_match_all( '/[^ =]+/', $where, $matches );
		if ( isset( $matches[0][0] ) && isset( $matches[0][1] ) ) {
			$id_type = $matches[0][0];
			$id_num  = $matches[0][1];
			if ( ! is_numeric( $id_num ) ) {
				$whereok = false;
			} else {
				$ids_ary[0] = (int) $id_num;
				$whereok    = 'single';
			}
		}

		//複数
		$wheretrim = str_replace( ' ', '', $where );
		preg_match_all( '/(.*)in\(([0-9]*,.*)\)+/i', $wheretrim, $matches );
		if ( isset( $matches[1][0] ) && isset( $matches[2][0] ) ) {
			$id_type = $matches[1][0];
			$ids_num = $matches[2][0];
			$ids_ary = $this->wrap_explode( ',', $ids_num );

			if ( ! is_array( $ids_ary ) ) {
				$whereok = false;
			} else {
				foreach ( $ids_ary as $iii => $id ) {
					$ids_ary[ $iii ] = (int) $id;
				}
				$whereok = 'multiple';
			}
		}
		if ( ! $whereok ) {
			return null;
		}

		//初期化
		$idx_base        = '';
		$before_idx_file = '';
		switch ( $id_type ) {
			case 'page_id':
				$idx_base = '_pageid.php';
				break;
			case 'pv_id':
				//indexなし（viewpv.phpをそのまま使う）
				break;
			default:
				return null;
		}

		$ret_ary = array();
		$ret_cnt = 0;

		switch ( $id_type ) {

			case 'page_id':
				// view_pv 廃止 RM4-R1 PR-b #1412: ON は find（allpv 列スキャン）でアンカーを引き、
				// session_id グループでセッションを復元する。null（未 done／不整合）なら従来 index 経路へ
				// all-or-nothing フォールバック。
				if ( self::RM_R1_INDEX_PATHS_ALLPV_ENABLED ) {
					$rm_r1_ret = $this->rm_r1_vr_sessions_pageid_from_allpv( $tracking_id, $ids_ary, $column, $column_ary, $count, $verhist_dir, $s_daystr, $e_daystr );
					if ( null !== $rm_r1_ret ) {
						return $rm_r1_ret;
					}
				}
				foreach ( $ids_ary as $id_num ) {

					//indexファイルを探す
					$search_range = 100000;
					$search_max   = 10000000;
					if ( $id_num > $search_max ) {
						return null;
					}
					$idx_file = '';
					for ( $i = 1; $i < $search_max; $i += $search_range ) {
						if ( $i <= $id_num && $i + $search_range > $id_num ) {
							$idx_file = $i . '-' . ( $i + $search_range - 1 ) . $idx_base;
							break;
						}
					}

					if ( ! $wp_filesystem->exists( $viewpv_idx_dir . $idx_file ) ) {
						continue;
					}

					//mkdummy
					if ( $idx_file !== $before_idx_file ) {
						$pageid_idx_file = $this->wrap_get_contents( $viewpv_idx_dir . $idx_file );
						$pageid_idx_ary  = $this->wrap_unserialize( $pageid_idx_file );
						$before_idx_file = $idx_file;
					}
					$viewpv_idx_ary = array();

					foreach ( $date_period_ary as $value ) {
						$date_period = $value->format( 'Y-m-d' );
						if ( $pageid_idx_ary[ $id_num ] == false ) {
							continue;
						}
						if ( $this->wrap_array_key_exists( $date_period, $pageid_idx_ary[ $id_num ] ) ) {
							$viewpv_idx_ary[ $date_period ] = $pageid_idx_ary[ $id_num ][ $date_period ];
						}
					}

					$viewpv_ary     = null;
					$viewpv_dirlist = $this->wrap_dirlist( $viewpv_dir );
					if ( ! $viewpv_dirlist ) {
						return null;
					}

					$searched_pv_id       = array();
					$view_pv_idx_ary_keys = array_keys( $viewpv_idx_ary );
					$view_pv_idx_cnt      = 0;
					$view_pv_idx_max      = $this->wrap_count( $viewpv_idx_ary );

					foreach ( $viewpv_dirlist as $viewpv_fileobj ) {
						$view_pv_file_name = $viewpv_fileobj['name'];

						// この時点でbetweenの最小値、最大値と日付比較を行うことで、更に高速化できそう

						for ( $i = $view_pv_idx_cnt; $i < $view_pv_idx_max; $i++ ) {
							$key = $view_pv_idx_ary_keys[ $i ];
							if ( $this->wrap_substr( $view_pv_file_name, 0, 10 ) !== $key ) {
								continue;
							}

							$viewpv_ary = $this->wrap_unserialize( $this->wrap_get_contents( $viewpv_dir . $viewpv_fileobj['name'] ) );
							foreach ( $viewpv_ary as $idx => $viewpv ) {
								if ( ! is_array( $viewpv_idx_ary[ $key ] ) ) {
									continue;
								}
								foreach ( $viewpv_idx_ary[ $key ] as $viewpv_idx ) {
									if ( (int) $viewpv['pv_id'] === $viewpv_idx ) {
										// 前回調べたか確認し、既に調べていたら（セッションに組み込まれていたら）スルー
										if ( ! empty( $searched_pv_id ) ) {
											$find = false;
											foreach ( $searched_pv_id as $pv_id ) {
												if ( $pv_id === $viewpv['pv_id'] ) {
													$find = true;
													break;
												}
											}
											if ( $find ) {
												break;
											}
										}
										$searched_pv_id = array();    // 毎回調べる度にクリアする

										// セッションの構築
										$session_ary = array();
										$pv_no       = (int) $viewpv['pv'];

										// １PV目でなかったとき、前のPVを遡って取りに行く。
										if ( $pv_no > 1 ) {
											$pv_idx     = $idx - $pv_no + 1;
											$now_reader = $viewpv['reader_id'];
											while ( $pv_idx < $idx ) {
												//20220622 pv_noが飛んでいる時があるのでその対策。reader_idが違うなら違うセッションなので処理しない。
												if ( $viewpv_ary[ $pv_idx ]['reader_id'] !== $now_reader ) {
													++$pv_idx;
													continue;
												}
												if ( $count ) {
													++$ret_cnt;
												} else {
													if ( $column === '*' ) {
														if ( $viewpv_ary[ $pv_idx ]['version_id'] ) {
															$verhist_filename = $viewpv_ary[ $pv_idx ]['version_id'] . '_version.php';
															$verhist_file     = $this->wrap_get_contents( $verhist_dir . $verhist_filename );
															if ( $verhist_file ) {
																$verhist_ary                         = $this->wrap_unserialize( $verhist_file );
																$viewpv_ary[ $pv_idx ]['version_no'] = $verhist_ary[0]->version_no;
															}
														}

														$session_ary[] = $viewpv_ary[ $pv_idx ];
													} else {
														$temp_ary = array();
														foreach ( $column_ary as $column_val ) {
															switch ( $column_val ) {
																case 'version_no':
																	if ( $viewpv_ary[ $pv_idx ]['version_id'] ) {
																		$verhist_filename = $viewpv_ary[ $pv_idx ]['version_id'] . '_version.php';
																		$verhist_file     = $this->wrap_get_contents( $verhist_dir . $verhist_filename );
																		if ( $verhist_file ) {
																			$verhist_ary             = $this->wrap_unserialize( $verhist_file );
																			$temp_ary[ $column_val ] = $verhist_ary[0]->version_no;
																		}
																	}
																	break;

																default:
																	$temp_ary[ $column_val ] = $viewpv_ary[ $pv_idx ][ $column_val ];
																	break;
															}
														}
														$session_ary[] = $temp_ary;
													}
												}
												++$pv_idx;
											}
										}

										// （$viewpv_idx配列に含まれている）ループ現時点PVと、それ以降のPV（同一セッション）を取得。念のため以下ループ上限（一セッション最大PV数）は10000に設定。※datasearch.jsに関連箇所あり。
										$first_search = true;
										$now_reader   = $viewpv['reader_id'];
										for ( $pv_cnt = 0; $pv_cnt < 10000; $pv_cnt++ ) {
											$pv_idx = $idx + $pv_cnt;

											//is_lastが無く、別セッションになった場合。reader_idで判断。20230410ym
											if ( $viewpv_ary[ $pv_idx ]['reader_id'] !== $now_reader ) {
												$session_ary_last_keynum                            = $this->wrap_count( $session_ary ) - 1;
												$session_ary[ $session_ary_last_keynum ]['is_last'] = '1';
												break;
											}

											if ( $first_search ) {
												$first_search = false;
											} else {
												$searched_pv_id[] = $viewpv_ary[ $pv_idx ]['pv_id'];
											}

											if ( $count ) {
												++$ret_cnt;
											} else {
												if ( $column === '*' ) {
													if ( $viewpv_ary[ $pv_idx ]['version_id'] ) {
														$verhist_filename = $viewpv_ary[ $pv_idx ]['version_id'] . '_version.php';
														$verhist_file     = $this->wrap_get_contents( $verhist_dir . $verhist_filename );
														if ( $verhist_file ) {
															$verhist_ary                         = $this->wrap_unserialize( $verhist_file );
															$viewpv_ary[ $pv_idx ]['version_no'] = $verhist_ary[0]->version_no;
														}
													}

													$session_ary[] = $viewpv_ary[ $pv_idx ];
												} else {
													$temp_ary = array();
													foreach ( $column_ary as $column_val ) {
														switch ( $column_val ) {
															case 'version_no':
																if ( $viewpv_ary[ $pv_idx ]['version_id'] ) {
																	$verhist_filename = $viewpv_ary[ $pv_idx ]['version_id'] . '_version.php';
																	$verhist_file     = $this->wrap_get_contents( $verhist_dir . $verhist_filename );
																	if ( $verhist_file ) {
																		$verhist_ary             = $this->wrap_unserialize( $verhist_file );
																		$temp_ary[ $column_val ] = $verhist_ary[0]->version_no;
																	}
																}
																break;

															default:
																$temp_ary[ $column_val ] = $viewpv_ary[ $pv_idx ][ $column_val ];
																break;
														}
													}
													$session_ary[] = $temp_ary;
												}
											}

											if ( $viewpv_ary[ $pv_idx ]['is_last'] ) {
												break;
											}
										}
										//sessionの重複を防ぐ
										$session_find = false;
										$ret_ary_cnt  = $this->wrap_count( $ret_ary );
										for ( $iii = 0; $iii < $ret_ary_cnt; $iii++ ) {
											if ( (int) $ret_ary[ $iii ][0]['pv_id'] === (int) $session_ary[0]['pv_id'] ) {
												$session_find = true;
												break;
											}
										}
										if ( ! $session_find ) {
											$ret_ary[] = $session_ary;
										}
									}
								}
							}

							++$view_pv_idx_cnt;
						}
						if ( $view_pv_idx_cnt >= $view_pv_idx_max ) {
							break;
						}
					}
				}
				break;

			case 'pv_id':
				// view_pv 廃止 RM4-R1 PR-a #1408: ON は allpv の session_id グループでセッションを復元する。
				// null（未 done／保持窓外／不整合）なら従来 view_pv 経路へ all-or-nothing フォールバック。
				if ( self::RM_R1_INDEXFREE_ALLPV_ENABLED ) {
					$rm_r1_ret = $this->rm_r1_vr_sessions_from_allpv( $tracking_id, $ids_ary, $column, $column_ary, $count, $verhist_dir );
					if ( null !== $rm_r1_ret ) {
						return $rm_r1_ret;
					}
				}
				$searched_pv_id = array();
				$viewpv_dirlist = $this->wrap_dirlist( $viewpv_dir );
				if ( ! $viewpv_dirlist ) {
					return null;
				}

				foreach ( $ids_ary as $id_num ) {
					$viewpv_ary = null;

					foreach ( $viewpv_dirlist as $viewpv_fileobj ) {
						$view_pv_file_name = $viewpv_fileobj['name'];
						//pv_idの場合の処理
						if ( preg_match( '/_(\d+)-(\d+)_/', $view_pv_file_name, $matches ) ) {
							$pvid_min = $matches[1];
							$pvid_max = $matches[2];
						}
						if ( $id_num >= $pvid_min && $id_num <= $pvid_max ) {
							$viewpv_ary = $this->wrap_unserialize( $this->wrap_get_contents( $viewpv_dir . $viewpv_fileobj['name'] ) );
							$key        = $this->wrap_substr( $view_pv_file_name, 0, 10 );

							//fileが存在しない場合はFalseが返る
							if ( ! is_array( $viewpv_ary ) ) {
								continue;
							}
							foreach ( $viewpv_ary as $idx => $viewpv ) {
								if ( (int) $viewpv['pv_id'] === $id_num ) {
									// 既にセッションに組み込まれていたらスルー（セッションの重複を防ぐ）
									if ( $this->wrap_in_array( $viewpv['pv_id'], $searched_pv_id ) ) {
										break;
									}
									//$searched_pv_id[] = $viewpv[ 'pv_id' ];

									// セッションの構築
									$session_ary = array();
									$pv_no       = (int) $viewpv['pv'];
									$now_reader  = $viewpv['reader_id'];

									// １PV目でなかったとき、前のPVを遡って取りに行く。
									if ( $pv_no > 1 ) {
										$pv_idx = $idx - $pv_no + 1;
										while ( $pv_idx < $idx ) {
											//20220622 pv_noが飛んでいる時があるのでその対策。reader_idが違うなら違うセッションなので処理しない。
											if ( $viewpv_ary[ $pv_idx ]['reader_id'] !== $now_reader ) {
												++$pv_idx;
												continue;
											}
											$searched_pv_id[] = $viewpv_ary[ $pv_idx ]['pv_id'];
											if ( $count ) {
												++$ret_cnt;
											} else {
												if ( $column === '*' ) {
													if ( $viewpv_ary[ $pv_idx ]['version_id'] ) {
														$verhist_filename = $viewpv_ary[ $pv_idx ]['version_id'] . '_version.php';
														$verhist_file     = $this->wrap_get_contents( $verhist_dir . $verhist_filename );
														if ( $verhist_file ) {
															$verhist_ary                         = $this->wrap_unserialize( $verhist_file );
															$viewpv_ary[ $pv_idx ]['version_no'] = $verhist_ary[0]->version_no;
														}
													}

													$session_ary[] = $viewpv_ary[ $pv_idx ];

												} else {
													$temp_ary = array();
													foreach ( $column_ary as $column_val ) {
														switch ( $column_val ) {
															case 'version_no':
																if ( $viewpv_ary[ $pv_idx ]['version_id'] ) {
																	$verhist_filename = $viewpv_ary[ $pv_idx ]['version_id'] . '_version.php';
																	$verhist_file     = $this->wrap_get_contents( $verhist_dir . $verhist_filename );
																	if ( $verhist_file ) {
																		$verhist_ary             = $this->wrap_unserialize( $verhist_file );
																		$temp_ary[ $column_val ] = $verhist_ary[0]->version_no;
																	}
																}
																break;

															default:
																$temp_ary[ $column_val ] = $viewpv_ary[ $pv_idx ][ $column_val ];
																break;
														}
													}
													$session_ary[] = $temp_ary;
												}
											}
											++$pv_idx;
										}
									}

									// $viewpv_aryループ現時点PV(=$id_num)と、それ以降のPV（同一セッション）を取得。念のためPVのループ上限は10000に設定
									//$first_search = true;
									for ( $pv_cnt = 0; $pv_cnt < 10000; $pv_cnt++ ) {
										$pv_idx = $idx + $pv_cnt;

										//is_lastが無く、別セッションになった場合。reader_idで判断。
										if ( $viewpv_ary[ $pv_idx ]['reader_id'] !== $now_reader ) {
											$session_ary_last_keynum                            = $this->wrap_count( $session_ary ) - 1;
											$session_ary[ $session_ary_last_keynum ]['is_last'] = '1';
											break;
										}

										$searched_pv_id[] = $viewpv_ary[ $pv_idx ]['pv_id'];

										if ( $count ) {
											++$ret_cnt;
										} else {
											if ( $column === '*' ) {
												if ( $viewpv_ary[ $pv_idx ]['version_id'] ) {
													$verhist_filename = $viewpv_ary[ $pv_idx ]['version_id'] . '_version.php';
													$verhist_file     = $this->wrap_get_contents( $verhist_dir . $verhist_filename );
													if ( $verhist_file ) {
														$verhist_ary                         = $this->wrap_unserialize( $verhist_file );
														$viewpv_ary[ $pv_idx ]['version_no'] = $verhist_ary[0]->version_no;
													}
												}

												$session_ary[] = $viewpv_ary[ $pv_idx ];
											} else {
												$temp_ary = array();
												foreach ( $column_ary as $column_val ) {
													switch ( $column_val ) {
														case 'version_no':
															if ( $viewpv_ary[ $pv_idx ]['version_id'] ) {
																$verhist_filename = $viewpv_ary[ $pv_idx ]['version_id'] . '_version.php';
																$verhist_file     = $this->wrap_get_contents( $verhist_dir . $verhist_filename );
																if ( $verhist_file ) {
																	$verhist_ary             = $this->wrap_unserialize( $verhist_file );
																	$temp_ary[ $column_val ] = $verhist_ary[0]->version_no;
																}
															}
															break;

														default:
															$temp_ary[ $column_val ] = $viewpv_ary[ $pv_idx ][ $column_val ];
															break;
													}
												}
												$session_ary[] = $temp_ary;
											}
										}

										if ( $viewpv_ary[ $pv_idx ]['is_last'] ) {
											break;
										}
									}

									$ret_ary[] = $session_ary;

								}
							}
						}
					}
				}
				break;
			default:
				return null;
		}
		//return
		if ( $count ) {
			return $ret_cnt;
		} else {
			if ( ! is_array( $ret_ary ) ) {
				return null;
			} else {
				return $ret_ary;
			}
		}
	}


	/**
	 * query
	 * Proxy for wpdb->query().
	 *
	 * @phpcsSuppress WordPress.DB.PreparedSQL.NotPrepared
	 */
	public function query( $query ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query may be pre-processed in this proxy; identifiers fixed/whitelisted.
		$result           = $wpdb->query( $query );
		$this->insert_id  = $wpdb->insert_id;
		$this->last_error = $wpdb->last_error;
		return $result;
	}

	// get_varに問題がありそうなので使わなくなった
	/**
	 * result
	 * Proxy for wpdb->get_var() with routing by table.
	 *
	 * @phpcsSuppress WordPress.DB.PreparedSQL.NotPrepared
	 */
	public function get_var( $query = null, $x = 0, $y = 0, $tracking_id = 'all' ) {
		global $wpdb;

		//switch function from table name
		$tb_view_pv    = $this->prefix . 'view_pv';
		$tb_vr_view_pv = $this->prefix . 'vr_view_pv';
		$tb_view_ver   = $this->prefix . 'view_page_version_hist';
		if ( preg_match( '/from ' . $tb_vr_view_pv . '/i', $query ) ) {
			return $this->get_results_view_pv( $query, true, $tracking_id );
		} elseif ( preg_match( '/from ' . $tb_view_pv . '/i', $query ) ) {
			return $this->get_results_view_pv( $query, false, $tracking_id );
		} elseif ( preg_match( '/from ' . $tb_view_ver . '/i', $query ) ) {
			return $this->get_results_view_page_version_hist( $query, $tracking_id );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query may be pre-processed in this proxy; identifiers fixed/whitelisted.
			return $wpdb->get_var( $query, $x, $y );
		}
	}

	/**
	 * useful
	 * Show column names of a given table.
	 *
	 * 列名を返せるのは view_pv / vr_view_pv（ファイル実体のテーブル）だけで、
	 * それ以外のテーブルには空配列を返す。呼び出し元の select_data() は
	 * $column !== '*' のときだけこの戻り値でカラム名を検証するため、
	 * DB テーブルに対してカラム名を指定した問い合わせは行えない（'*' を使うこと）。
	 *
	 * #1305: ここには DB テーブル用の分岐があったが三重に壊れており
	 * （定数の値側を回していて一致しない／SQL から SHOW が欠落／
	 * オブジェクトを配列として読む）、必ず空配列を返していた。
	 * 分岐自体は毎回実行されていたが、その結果（列名）を使う呼び出しが
	 * 1つも無いことを確認したうえで削除した
	 * ＝削除の前後で戻り値は全ての入力に対して同一。
	 *
	 * ⚠️ 将来ここに SHOW COLUMNS を正しく実装する場合は、テーブル名の
	 * ホワイトリスト検証をこの関数側に持たせること。本関数は呼び出し元
	 * select_data() のテーブル検証（401 die）より「前」に、未検証の
	 * $table をそのまま受け取る位置で呼ばれている。
	 */
	public function show_column( $table ) {
		$retary = array();
		switch ( $table ) {
			case 'view_pv':
				$retary = self::QAHM_VIEW_PV_COL;
				break;

			case 'vr_view_pv':
				$retary = self::QAHM_VR_VIEW_PV_COL;
				break;

			default:
				// 上記2つ以外には列名を返さない（意図的に空配列）。
				// ここに DB テーブル用の分岐があったが #1305 で撤去した。復活させないこと。
				break;
		}
		return $retary;
	}
	/**
	 * protected
	 */
	// QA ZERO START
	// add $connect_tid
	protected function get_results_view_pv( $query = null, $is_vr = false, $tracking_id = 'all' ) {
		// QA ZERO END
		$tracking_id = $this->get_safe_tracking_id( $tracking_id );

		global $qahm_time;

		//view_pv table is in file
		$data_dir = $this->get_data_dir_path();
		$view_dir = $data_dir . 'view/';
		//view_pv dirlist
		$viewpv_dir     = $view_dir . $tracking_id . '/view_pv/';
		$viewpv_idx_dir = $viewpv_dir . '/index/';
		$allfiles       = $this->wrap_dirlist( $viewpv_dir );
		// $verhist_dir = $view_dir . $tracking_id . '/version_hist/';
		$verhist_dir = $view_dir . 'all' . '/version_hist/';

		// columns
		preg_match( '/select (.*) from/i', $query, $sel_columns );
		$sel_column  = str_replace( ' ', '', $sel_columns[1] );
		$columns_ary = array();
		if ( $sel_column === '*' ) {
			$columns_ary[0] = '*';
		} elseif ( preg_match( '/count\((.*)\)/', $sel_column, $counts ) ) {
			$columns_ary[0] = 'count';
			$columns_ary[1] = $counts[1];
		} else {
			$columns_ary = $this->wrap_explode( ',', $sel_column );
		}
		$is_error    = false;
		$results_ary = array();
		$countall    = 0;
		// where date
		if ( preg_match( '/where access_time between.*([0-9]{4}.[0-9]{2}.[0-9]{2}.[0-9]{2}.[0-9]{2}.[0-9]{2}).*and.*([0-9]{4}.[0-9]{2}.[0-9]{2}.[0-9]{2}.[0-9]{2}.[0-9]{2})/i', $query, $date_strings ) ) {
			$s_unixtime = $qahm_time->str_to_unixtime( $date_strings[1] );
			$e_unixtime = $qahm_time->str_to_unixtime( $date_strings[2] );

			// view_pv 廃止 RM4-R1 PR-a #1408: ON は allpv から OLD と同じ日包含規則で組み立てる。
			// null（範囲内に未 done 日あり等）なら従来 view_pv 経路へ all-or-nothing フォールバック。
			if ( self::RM_R1_INDEXFREE_ALLPV_ENABLED ) {
				$rm_r1_ret = $this->rm_r1_results_daterange_from_allpv( $tracking_id, $date_strings[1], $date_strings[2], $s_unixtime, $e_unixtime, $columns_ary, $is_vr, $verhist_dir );
				if ( null !== $rm_r1_ret ) {
					return $rm_r1_ret;
				}
			}

			foreach ( $allfiles as $file ) {
				$filename = $file['name'];
				if ( is_file( $viewpv_dir . $filename ) ) {
					$f_date = $this->wrap_substr( $filename, 0, 10 ) . ' 00:00:00';

					$f_unixtime = $qahm_time->str_to_unixtime( $f_date );
					if ( $s_unixtime <= $f_unixtime && $f_unixtime <= $e_unixtime ) {
						$tmpary = $this->wrap_unserialize( $this->wrap_get_contents( $viewpv_dir . $filename ) );
						//データは連想配列に入っている
						//取得するデータによって処理をわける
						switch ( $columns_ary[0] ) {
							case 'count':
								if ( $columns_ary[1] === '*' ) {
									$countall = $countall + $this->wrap_count( $tmpary );
								} else {
									foreach ( $tmpary as $bodyary ) {
										if ( $bodyary[ $columns_ary[1] ] ) {
											++$countall;
										}
									}
								}
								break;

							case '*':
								foreach ( $tmpary as &$bodyary ) {
									if ( $is_vr ) {
										$verhist_filename = $bodyary['version_id'] . '_version.php';
										$verhist_file     = $this->wrap_get_contents( $verhist_dir . $verhist_filename );
										if ( $verhist_file ) {
											$verhist_ary           = $this->wrap_unserialize( $verhist_file );
											$bodyary['version_no'] = $verhist_ary[0]->version_no;
										}
									}
								}
								$results_ary[] = $tmpary;
								break;

							default:
								foreach ( $tmpary as $bodyary ) {
									$lineary = array();
									foreach ( $columns_ary as $column ) {
										if ( $is_vr ) {
											switch ( $column ) {
												case 'version_no':
													$verhist_filename = $bodyary['version_id'] . '_version.php';
													$verhist_file     = $this->wrap_get_contents( $verhist_dir . $verhist_filename );
													if ( $verhist_file ) {
														$verhist_ary        = $this->wrap_unserialize( $verhist_file );
														$lineary[ $column ] = $verhist_ary[0]->version_no;
													}
													break;

												default:
													$lineary[ $column ] = $bodyary[ $column ];
													break;
											}
										} else {
											$lineary[ $column ] = $bodyary[ $column ];
										}
									}
									$results_ary[] = $lineary;
								}
								break;
						}
					}
				}
			}

			// where pv_id
		} elseif ( preg_match( '/where pv_id = ([0-9]*)/i', $query, $pvidary ) ) {
			$pv_id = (int) $pvidary[1];

			// view_pv 廃止 RM4-R1 PR-a #1408: ON は qa_pv_log で pv_id→日付を解決し allpv から単一行を引く。
			// null（保持窓外／未 done／行不在）なら従来 view_pv 経路へ all-or-nothing フォールバック。
			if ( self::RM_R1_INDEXFREE_ALLPV_ENABLED ) {
				$rm_r1_ret = $this->rm_r1_results_pvid_from_allpv( $tracking_id, $pv_id, $columns_ary );
				if ( null !== $rm_r1_ret ) {
					return $rm_r1_ret;
				}
			}
			for ( $iii = 0; $iii < $this->wrap_count( $allfiles ); $iii++ ) {
				$filename = $allfiles[ $iii ]['name'];
				$fnameexp = $this->wrap_explode( '_', $filename );
				$pvno_exp = $this->wrap_explode( '-', $fnameexp[1] );
				$s_pvidno = (int) $pvno_exp[0];
				$e_pvidno = (int) $pvno_exp[1];
				if ( $s_pvidno <= $pv_id && $pv_id <= $e_pvidno ) {
					$tmpary = $this->wrap_unserialize( $this->wrap_get_contents( $viewpv_dir . $filename ) );
					//データは連想配列に入っている
					//取得するデータによって処理をわける
					for ( $jjj = 0; $jjj < $this->wrap_count( $tmpary ); $jjj++ ) {
						if ( (int) $tmpary[ $jjj ]['pv_id'] === $pv_id ) {
							switch ( $columns_ary[0] ) {
								case 'count':
									if ( $columns_ary[1] === '*' ) {
										$countall = 1;
									} else {
										if ( $tmpary[ $jjj ][ $columns_ary[1] ] ) {
											$countall = 1;
										}
									}
									break;

								case '*':
									$results_ary[] = $tmpary[ $jjj ];
									break;

								default:
									$lineary = array();
									foreach ( $columns_ary as $column ) {
										$lineary[ $column ] = $tmpary[ $jjj ][ $column ];
									}
									$results_ary[] = $lineary;
									break;
							}
							$jjj = $this->wrap_count( $tmpary ) + 888;
							$iii = $this->wrap_count( $allfiles ) + 888;
						}
					}
				}
			}

			// where version_id
		} elseif ( preg_match( '/where version_id = ([0-9]*)/i', $query, $idary ) ) {
			$id = (int) $idary[1];

			$idx_file = $this->get_index_file_contents( $viewpv_idx_dir, 'versionid', $id );
			if ( ! $idx_file ) {
				return false;
			}

			$allfiles_cnt = $this->wrap_count( $allfiles );
			foreach ( $idx_file as $date => $id_ary ) {
				for ( $iii = 0; $iii < $allfiles_cnt; $iii++ ) {
					$filename = $allfiles[ $iii ]['name'];
					if ( ! is_file( $viewpv_dir . $filename ) ) {
						continue;
					}

					if ( $this->wrap_substr( $filename, 0, 10 ) !== $date ) {
						continue;
					}

					if ( $this->wrap_array_key_exists( $tracking_id, $this->view_pv_cache ) && $this->wrap_array_key_exists( $filename, $this->view_pv_cache[ $tracking_id ] ) && $this->view_pv_cache[ $tracking_id ][ $filename ] ) {
						$tmpary = $this->view_pv_cache[ $tracking_id ][ $filename ];
					} else {
						$tmpary = $this->wrap_unserialize( $this->wrap_get_contents( $viewpv_dir . $filename ) );
					}

					$jjj     = 0;
					$tmp_cnt = $this->wrap_count( $tmpary );
					foreach ( $id_ary as $id ) {
						//データは連想配列に入っている
						//取得するデータによって処理をわける
						for ( ; $jjj < $tmp_cnt; $jjj++ ) {
							if ( (int) $tmpary[ $jjj ]['pv_id'] === (int) $id ) {
								switch ( $columns_ary[0] ) {
									case 'count':
										if ( $columns_ary[1] === '*' ) {
											++$countall;
										} else {
											if ( $tmpary[ $jjj ][ $columns_ary[1] ] ) {
												++$countall;
											}
										}
										break;

									case '*':
										$results_ary[] = $tmpary[ $jjj ];
										break;

									default:
										$lineary = array();
										foreach ( $columns_ary as $column ) {
											$lineary[ $column ] = $tmpary[ $jjj ][ $column ];
										}
										$results_ary[] = $lineary;
								}

								++$jjj;
								break;
							}
						}
					}
					break;
				}
			}

			// where page_id
			// version_idとほぼ同じ手法なので、余裕があればソースをマージした方が良さそう
			// speed up kaizen 2023/12/03 by maruyama
		} elseif ( preg_match( "/where page_id = ([0-9]+)(?: and access_time between.*([0-9]{4}.[0-9]{2}.[0-9]{2}.[0-9]{2}.[0-9]{2}.[0-9]{2}).*and.*([0-9]{4}.[0-9]{2}.[0-9]{2}.[0-9]{2}.[0-9]{2}.[0-9]{2})')?/i", $query, $matches ) ) {
			$id      = (int) $matches[1];
			$is_date = false;
			// オプショナルで日付範囲が指定されている場合、その値を取得
			if ( ! empty( $matches[2] ) && ! empty( $matches[3] ) ) {
				$start_datetime = $matches[2];
				$end_datetime   = $matches[3];
				$start_utime    = $qahm_time->str_to_unixtime( $start_datetime );
				$end_utime      = $qahm_time->str_to_unixtime( $end_datetime );
				$is_date        = true;
			}

			// view_pv 廃止 RM4-R1 PR-b #1412: ON は find（allpv 列スキャン）→ builder subset で行を引く
			// （ヒートマップ生成 view-heatmap の消費経路）。date 無し呼び出しは実消費者ゼロ＝従来経路のまま。
			// null（未 done／不整合）なら従来 index 経路へ all-or-nothing フォールバック。
			if ( self::RM_R1_INDEX_PATHS_ALLPV_ENABLED ) {
				$rm_r1_ret = $this->rm_r1_results_pageid_from_allpv( $tracking_id, $id, $is_date, $is_date ? $start_datetime : null, $is_date ? $end_datetime : null, $is_date ? $start_utime : 0, $is_date ? $end_utime : 0, $columns_ary );
				if ( null !== $rm_r1_ret ) {
					return $rm_r1_ret;
				}
			}
			$idx_file = $this->get_index_file_contents( $viewpv_idx_dir, 'pageid', $id );
			if ( ! $idx_file ) {
				return false;
			}
			global $qahm_log;
			$allfiles_cnt = $this->wrap_count( $allfiles );
			foreach ( $idx_file as $date => $id_ary ) {
				if ( $is_date ) {
					$now_utime = $qahm_time->str_to_unixtime( $date . ' 23:59:59' );
					if ( $now_utime < $start_utime || $end_utime < $now_utime ) {
						continue;
					}
				}
				for ( $iii = 0; $iii < $allfiles_cnt; $iii++ ) {
					$filename = $allfiles[ $iii ]['name'];
					if ( ! is_file( $viewpv_dir . $filename ) ) {
						continue;
					}

					if ( $this->wrap_substr( $filename, 0, 10 ) !== $date ) {
						continue;
					}

					$tmpary = $this->wrap_unserialize( $this->wrap_get_contents( $viewpv_dir . $filename ) );

					$jjj     = 0;
					$tmp_cnt = $this->wrap_count( $tmpary );
					// $tmparyを'pv_id'をキーとした新しい連想配列に変換
					$cached_array = array();
					foreach ( $tmpary as $item ) {
						$cached_array[ $item['pv_id'] ] = $item;
					}

					// $id_aryを使用して$cached_arrayからデータを検索
					foreach ( $id_ary as $id ) {
						if ( isset( $cached_array[ $id ] ) ) {
							// 見つかったデータに対する処理
							switch ( $columns_ary[0] ) {
								case 'count':
									if ( $columns_ary[1] === '*' ) {
										++$countall;
									} else {
										if ( isset( $cached_array[ $id ][ $columns_ary[1] ] ) ) {
											++$countall;
										}
									}
									break;

								case '*':
									$results_ary[] = $cached_array[ $id ];
									break;

								default:
									$lineary = array();
									foreach ( $columns_ary as $column ) {
										$lineary[ $column ] = $cached_array[ $id ][ $column ];
									}
									$results_ary[] = $lineary;
							}
						}
					}
					break;
				}
			}

			// 必須項目の指定がないのでエラー
		} else {
			$is_error = true;
		}

		//返値をセット
		if ( $is_error ) {
			return false;
		} else {
			if ( $columns_ary[0] === 'count' ) {
				return $countall;
			} else {
				return $results_ary;
			}
		}
	}

	/**
	 * builder 行を view_pv 行（cron-proc 生成の29キー・順序・型）へ忠実に射影する（view_pv 廃止 RM4-R1 PR-a #1408）。
	 *
	 * R1 の返り行は TSV ダウンロード（admin-page-user.js＝行キーがそのまま列）や ajax_select_data（JSON 直結）で
	 * 消費されるため、キー集合・順序・型を view_pv 行ファイルの行（cron-proc の $newary 構築）と一致させる。
	 * - builder の superset キー（session_id / content_id / utm_content / os / browser）は落とす
	 * - is_reject は view_pv 行に存在する（生成時に qa_readers から付与・reader 不明は null）ため、
	 *   qa_readers 現在値マップから補完する（値で分岐する消費者は無し＝キー存在パリティ目的。
	 *   現在値 vs 生成時スナップショットのドリフトは RM1/R2 で受容済みの flip 周知事項）
	 * - 数値系は view_pv 同様 string 化（wpdb 由来型）。access_time / version_id のみ int（生成時に int 格納）
	 *
	 * @param array $row           build_allpv_rows_for_date の行.
	 * @param array $is_reject_map reader_id(int) => is_reject（qa_readers 現在値）のマップ.
	 * @return array view_pv 忠実行.
	 */
	private function rm_r1_project_viewpv_row( $row, $is_reject_map ) {
		$reader_id = isset( $row['reader_id'] ) ? (int) $row['reader_id'] : 0;
		return array(
			'pv_id'         => isset( $row['pv_id'] ) ? (string) $row['pv_id'] : '',
			'reader_id'     => (string) $reader_id,
			'UAos'          => isset( $row['UAos'] ) ? (string) $row['UAos'] : '',
			'UAbrowser'     => isset( $row['UAbrowser'] ) ? (string) $row['UAbrowser'] : '',
			'language'      => isset( $row['language'] ) ? (string) $row['language'] : '',
			'country_code'  => isset( $row['country_code'] ) ? (string) $row['country_code'] : '',
			'is_reject'     => isset( $is_reject_map[ $reader_id ] ) ? $is_reject_map[ $reader_id ] : null,
			'page_id'       => isset( $row['page_id'] ) ? (string) $row['page_id'] : '',
			'url'           => isset( $row['url'] ) ? (string) $row['url'] : '',
			'title'         => isset( $row['title'] ) ? (string) $row['title'] : '',
			'access_time'   => isset( $row['access_time'] ) ? (int) $row['access_time'] : 0,
			'device_id'     => isset( $row['device_id'] ) ? (string) $row['device_id'] : '',
			'version_id'    => isset( $row['version_id'] ) ? (int) $row['version_id'] : 0,
			'source_id'     => isset( $row['source_id'] ) ? (string) $row['source_id'] : '',
			'utm_source'    => isset( $row['utm_source'] ) ? (string) $row['utm_source'] : '',
			'source_domain' => isset( $row['source_domain'] ) ? (string) $row['source_domain'] : '',
			'medium_id'     => isset( $row['medium_id'] ) ? (string) $row['medium_id'] : '',
			'utm_medium'    => isset( $row['utm_medium'] ) ? (string) $row['utm_medium'] : '',
			'campaign_id'   => isset( $row['campaign_id'] ) ? (string) $row['campaign_id'] : '',
			'utm_campaign'  => isset( $row['utm_campaign'] ) ? (string) $row['utm_campaign'] : '',
			'session_no'    => isset( $row['session_no'] ) ? $row['session_no'] : '',
			'pv'            => isset( $row['pv'] ) ? (string) $row['pv'] : '',
			'speed_msec'    => isset( $row['speed_msec'] ) ? (string) $row['speed_msec'] : '',
			'browse_sec'    => isset( $row['browse_sec'] ) ? (string) $row['browse_sec'] : '',
			'is_last'       => isset( $row['is_last'] ) ? (string) $row['is_last'] : '',
			'is_newuser'    => isset( $row['is_newuser'] ) ? (string) $row['is_newuser'] : '',
			'is_raw_p'      => isset( $row['is_raw_p'] ) ? $row['is_raw_p'] : '',
			'is_raw_c'      => isset( $row['is_raw_c'] ) ? $row['is_raw_c'] : '',
			'is_raw_e'      => isset( $row['is_raw_e'] ) ? $row['is_raw_e'] : '',
		);
	}

	/**
	 * qa_pv_log の access_time（datetime 文字列）から 'Ymd' 8桁を得る（RM4-R1 PR-a #1408）。
	 *
	 * qa_pv_log access_time は view_pv 日ファイル・allpv 変換と同一の暦日バケット源なので、
	 * この導出日は OLD がその pv を収めていた日ファイルの日付と一致する。
	 *
	 * @param mixed $access_time qa_pv_log 格納の datetime 文字列.
	 * @return string|null 'Ymd' 8桁。形式不正は null.
	 */
	private function rm_r1_ymd_from_access_time( $access_time ) {
		if ( ! is_string( $access_time ) || $this->wrap_strlen( $access_time ) < 10 ) {
			return null;
		}
		$ymd = str_replace( '-', '', $this->wrap_substr( $access_time, 0, 10 ) );
		return ( 8 === $this->wrap_strlen( $ymd ) ) ? $ymd : null;
	}

	/**
	 * allpv データセットディレクトリ（Issue #1420 nodata 判定用・load_allpv_columns と同一規則）。
	 *
	 * @param string $tracking_id トラッキングID.
	 * @return string
	 */
	private function get_allpv_dataset_dir( $tracking_id ) {
		return $this->get_data_dir_path() . 'report/' . $this->get_safe_tracking_id( $tracking_id ) . '/columns-db/allpv/';
	}

	/**
	 * その日が「データ無し日（manifest done rows=0 マーカー）」か（Issue #1420）。
	 *
	 * フラグ（QAHM_ColumnDB_Manifest::NODATA_DAY_MANIFEST_ENABLED）OFF では常に false。
	 * ColumnDB ON リーダー（builder/find/RM3/RM1）が「null＝未 done」と「データ無し日＝行0件」を
	 * 区別するための共通入口。
	 *
	 * @param string $tracking_id トラッキングID.
	 * @param string $date_ymd    YYYYMMDD.
	 * @return bool
	 */
	public function is_allpv_nodata_day( $tracking_id, $date_ymd ) {
		if ( ! class_exists( 'QAHM_ColumnDB_Manifest' ) ) {
			return false;
		}
		return QAHM_ColumnDB_Manifest::is_nodata_day( $this->get_allpv_dataset_dir( $tracking_id ), (string) $date_ymd );
	}

	/**
	 * nodata スキャンが永続化した「最古データ証跡日」境界を返す（Issue #1420）。
	 *
	 * 境界より前の日は「その tid にデータが存在し始める前」＝OLD もファイル不在で読まない＝寄与ゼロ扱いにできる。
	 * フラグ OFF・スキャン未実行（メタ不在）は null＝呼び出し側はクランプしない（従来どおりフォールバック）。
	 *
	 * @param string $tracking_id トラッキングID.
	 * @return string|null YYYYMMDD or null.
	 */
	public function get_allpv_nodata_boundary( $tracking_id ) {
		if ( ! class_exists( 'QAHM_ColumnDB_Manifest' ) || ! QAHM_ColumnDB_Manifest::NODATA_DAY_MANIFEST_ENABLED ) {
			return null;
		}
		$meta = QAHM_ColumnDB_Manifest::load_scan_meta( $this->get_allpv_dataset_dir( $tracking_id ) );
		return ( false !== $meta ) ? (string) $meta['boundary'] : null;
	}

	/**
	 * date-range 分岐の allpv 版（view_pv 廃止 RM4-R1 PR-a #1408）。
	 *
	 * 日の包含規則は OLD（L: where access_time between 分岐）を bug-for-bug 再現する（着手前レビュー 🟡-1）:
	 * 「日 D の 00:00:00 を $qahm_time->str_to_unixtime した値が [s,e] 内なら日 D を丸ごと採用」。
	 * S 日の 00:00 が範囲開始前なら S 日は除外・行レベルの時刻フィルタは無い（OLD のファイル単位粒度そのまま）。
	 * ※ R2（get_recent_sessions）の DateTime サイト暦日列挙とは別流儀＝流用しない。
	 * `'*'` のネストは OLD の「ファイル単位」に対し ON は「日単位」（多ファイル日のみ粒度差・全消費者 flat 後
	 * 使用で吸収・RM4-R2 ⚪-1 同 class）。
	 *
	 * @return int|array|null count は int・行は array。範囲内に未 done 日があれば null（呼び出し側フォールバック）.
	 */
	private function rm_r1_results_daterange_from_allpv( $tracking_id, $s_str, $e_str, $s_unixtime, $e_unixtime, $columns_ary, $is_vr, $verhist_dir ) {
		global $qahm_time, $qahm_file_functions;
		if ( ! is_object( $qahm_file_functions ) || ! method_exists( $qahm_file_functions, 'build_allpv_rows_for_date' ) ) {
			return null;
		}
		if ( ! preg_match( '/^([0-9]{4}).([0-9]{2}).([0-9]{2})/', $s_str, $s_parts ) || ! preg_match( '/^([0-9]{4}).([0-9]{2}).([0-9]{2})/', $e_str, $e_parts ) ) {
			return null;
		}

		// 候補日ラベルを純カレンダー演算で列挙し、OLD と同じ判定（D 00:00:00 の unixtime ∈ [s,e]）で採否を決める。
		$included_days = array();
		$y = (int) $s_parts[1];
		$m = (int) $s_parts[2];
		$d = (int) $s_parts[3];
		$end_label = sprintf( '%04d-%02d-%02d', (int) $e_parts[1], (int) $e_parts[2], (int) $e_parts[3] );
		$guard = 0;
		while ( true ) {
			$label = sprintf( '%04d-%02d-%02d', $y, $m, $d );
			$f_unixtime = $qahm_time->str_to_unixtime( $label . ' 00:00:00' );
			if ( $s_unixtime <= $f_unixtime && $f_unixtime <= $e_unixtime ) {
				$included_days[] = $label;
			}
			if ( $label >= $end_label ) {
				break;
			}
			if ( ++$guard > 36600 ) { // 100年ガード（暴走防止）
				return null;
			}
			$next = gmmktime( 0, 0, 0, $m, $d + 1, $y );
			$y = (int) gmdate( 'Y', $next );
			$m = (int) gmdate( 'n', $next );
			$d = (int) gmdate( 'j', $next );
		}

		// 各日を builder で組み立て（1日でも null＝真の未 done なら全体フォールバック）。
		// Issue #1420: データ無し日（done rows=0＝builder が空配列）と境界（最古データ証跡日）前の日は
		// 「寄与ゼロ」で続行＝OLD はファイル不在の日を読まない（寄与ゼロ）ので等価。
		// '*' のネストも足さない（OLD＝ファイル無し＝ネスト無し）。
		$nodata_boundary = $this->get_allpv_nodata_boundary( $tracking_id );
		$day_rows_ary    = array();
		$reader_ids      = array();
		foreach ( $included_days as $label ) {
			$ymd  = str_replace( '-', '', $label );
			$rows = $qahm_file_functions->build_allpv_rows_for_date( $tracking_id, $ymd );
			if ( ! is_array( $rows ) ) {
				if ( null !== $nodata_boundary && $ymd < $nodata_boundary ) {
					continue;
				}
				return null;
			}
			if ( ! $rows ) {
				continue;
			}
			// allpv の物理行順はセッション連続順（列DB のセッション隣接格納）。view_pv ファイルは
			// 生成 SELECT の PK 順＝pv_id 昇順なので、OLD の行順（TSV ダウンロード等の見た目順）に合わせて sort する。
			usort(
				$rows,
				function ( $a, $b ) {
					return $a['pv_id'] <=> $b['pv_id'];
				}
			);
			$day_rows_ary[ $label ] = $rows;
			foreach ( $rows as $r ) {
				$reader_ids[ (int) $r['reader_id'] ] = true;
			}
		}
		$is_reject_map = QAHM_DB_Functions::get_readers_is_reject( array_keys( $reader_ids ) );

		$countall    = 0;
		$results_ary = array();
		foreach ( $day_rows_ary as $label => $rows ) {
			if ( empty( $rows ) ) {
				continue; // OLD はデータの無い日はファイル自体が無い＝何も加えない
			}
			switch ( $columns_ary[0] ) {
				case 'count':
					if ( $columns_ary[1] === '*' ) {
						$countall = $countall + $this->wrap_count( $rows );
					} else {
						foreach ( $rows as $r ) {
							$faithful = $this->rm_r1_project_viewpv_row( $r, $is_reject_map );
							if ( isset( $faithful[ $columns_ary[1] ] ) && $faithful[ $columns_ary[1] ] ) {
								++$countall;
							}
						}
					}
					break;

				case '*':
					$day_ary = array();
					foreach ( $rows as $r ) {
						$faithful = $this->rm_r1_project_viewpv_row( $r, $is_reject_map );
						if ( $is_vr ) {
							// OLD（date-range '*'）は version_id の truthy を見ずに verhist を読む＝忠実再現
							$verhist_file = $this->wrap_get_contents( $verhist_dir . $faithful['version_id'] . '_version.php' );
							if ( $verhist_file ) {
								$verhist_ary            = $this->wrap_unserialize( $verhist_file );
								$faithful['version_no'] = $verhist_ary[0]->version_no;
							}
						}
						$day_ary[] = $faithful;
					}
					$results_ary[] = $day_ary;
					break;

				default:
					foreach ( $rows as $r ) {
						$faithful = $this->rm_r1_project_viewpv_row( $r, $is_reject_map );
						$lineary  = array();
						foreach ( $columns_ary as $column ) {
							if ( $is_vr && 'version_no' === $column ) {
								$verhist_file = $this->wrap_get_contents( $verhist_dir . $faithful['version_id'] . '_version.php' );
								if ( $verhist_file ) {
									$verhist_ary        = $this->wrap_unserialize( $verhist_file );
									$lineary[ $column ] = $verhist_ary[0]->version_no;
								}
							} else {
								$lineary[ $column ] = isset( $faithful[ $column ] ) ? $faithful[ $column ] : null;
							}
						}
						$results_ary[] = $lineary;
					}
					break;
			}
		}

		if ( $columns_ary[0] === 'count' ) {
			return $countall;
		}
		return $results_ary;
	}

	/**
	 * pv_id 分岐の allpv 版（view_pv 廃止 RM4-R1 PR-a #1408）。
	 *
	 * OLD はファイル名の pv_id レンジで日ファイルを引く。ON は qa_pv_log で pv_id→日付を解決し、
	 * allpv の subset build（PR-0 の $pv_id_filter）で単一行を得る。
	 * ※ OLD の pv_id 分岐は is_vr でも version_no を補完しない＝ON も補完しない（忠実）。
	 *
	 * @return int|array|null count は int・行は array。保持窓外／未 done／行不在は null（フォールバック）.
	 */
	private function rm_r1_results_pvid_from_allpv( $tracking_id, $pv_id, $columns_ary ) {
		global $qahm_file_functions;
		if ( ! is_object( $qahm_file_functions ) || ! method_exists( $qahm_file_functions, 'build_allpv_rows_for_date' ) ) {
			return null;
		}
		$at_map = QAHM_DB_Functions::get_pv_log_access_time( array( $pv_id ) );
		if ( ! isset( $at_map[ $pv_id ] ) ) {
			return null;
		}
		$ymd = $this->rm_r1_ymd_from_access_time( $at_map[ $pv_id ] );
		if ( null === $ymd ) {
			return null;
		}
		$rows = $qahm_file_functions->build_allpv_rows_for_date( $tracking_id, $ymd, array( $pv_id ) );
		if ( ! is_array( $rows ) || 1 !== $this->wrap_count( $rows ) ) {
			// 日は done なのに行が無い＝不整合（tid 違い含む）。保守的に従来経路へ
			return null;
		}
		$is_reject_map = QAHM_DB_Functions::get_readers_is_reject( array( (int) $rows[0]['reader_id'] ) );
		$faithful      = $this->rm_r1_project_viewpv_row( $rows[0], $is_reject_map );

		switch ( $columns_ary[0] ) {
			case 'count':
				if ( $columns_ary[1] === '*' ) {
					return 1;
				}
				return ( isset( $faithful[ $columns_ary[1] ] ) && $faithful[ $columns_ary[1] ] ) ? 1 : 0;

			case '*':
				return array( $faithful );

			default:
				$lineary = array();
				foreach ( $columns_ary as $column ) {
					$lineary[ $column ] = isset( $faithful[ $column ] ) ? $faithful[ $column ] : null;
				}
				return array( $lineary );
		}
	}

	/**
	 * get_vr_view_session pv_id 経路の allpv 版（view_pv 廃止 RM4-R1 PR-a #1408）。
	 *
	 * セッション復元は OLD の physical adjacency 遡り／前進でなく、allpv ネイティブの session_id グループで行う
	 * （境界一致は #1406 daycross-verification.md で実証）。OLD の観測可能な挙動を再現する:
	 * - 出力順＝ids_ary 順に「未出力のセッション」を追加（OLD の searched_pv_id skip と同効果の session 単位 dedup）
	 * - セッション内の行順＝pv_id 昇順（OLD のファイル物理順と同一）
	 * - is_last 強制＝OLD はセッション打ち切り点（reader 変化／ファイル末尾）で最終行の is_last を '1' に強制する
	 *   （column 射影行にもキーを追加する）。末尾行の生 is_last が truthy（真のセッション終端）なら強制しない
	 * - version_no は version_id truthy のときだけ verhist を読む（OLD の vr_view_session 側の規則）
	 *
	 * @return int|array|null count は int・セッション配列は array。解決不能（保持窓外／未 done／不整合）は null.
	 */
	private function rm_r1_vr_sessions_from_allpv( $tracking_id, $ids_ary, $column, $column_ary, $count, $verhist_dir ) {
		global $qahm_file_functions;
		if ( ! is_object( $qahm_file_functions ) || ! method_exists( $qahm_file_functions, 'build_allpv_rows_for_date' ) ) {
			return null;
		}

		// pv_id → 日付解決。1つでも解決できなければ全体フォールバック（all-or-nothing）
		$int_ids = array();
		foreach ( $ids_ary as $id ) {
			$int_ids[] = (int) $id;
		}
		$at_map = QAHM_DB_Functions::get_pv_log_access_time( $int_ids );
		$ymd_by_id = array();
		foreach ( $int_ids as $id ) {
			if ( ! isset( $at_map[ $id ] ) ) {
				return null;
			}
			$ymd = $this->rm_r1_ymd_from_access_time( $at_map[ $id ] );
			if ( null === $ymd ) {
				return null;
			}
			$ymd_by_id[ $id ] = $ymd;
		}

		// 日ごとに全行を組み立て（セッション復元には日の全行が要る）
		$day_rows   = array();
		$reader_ids = array();
		foreach ( array_unique( $ymd_by_id ) as $ymd ) {
			$rows = $qahm_file_functions->build_allpv_rows_for_date( $tracking_id, $ymd );
			if ( ! is_array( $rows ) ) {
				return null;
			}
			// allpv の物理行順はセッション連続順。OLD のファイル物理順（pv_id 昇順）に合わせて sort し、
			// セッション内の行順を OLD と一致させる（date-range 側と同じ規約）。
			usort(
				$rows,
				function ( $a, $b ) {
					return $a['pv_id'] <=> $b['pv_id'];
				}
			);
			$day_rows[ $ymd ] = $rows;
			foreach ( $rows as $r ) {
				$reader_ids[ (int) $r['reader_id'] ] = true;
			}
		}
		$is_reject_map = QAHM_DB_Functions::get_readers_is_reject( array_keys( $reader_ids ) );

		// pv_id → セッションキー／セッションキー → 行リスト（allpv 行順＝pv_id 昇順）
		$pv_to_session = array();
		$session_rows  = array();
		foreach ( $day_rows as $ymd => $rows ) {
			foreach ( $rows as $r ) {
				$skey = $ymd . '|' . ( isset( $r['session_id'] ) ? (int) $r['session_id'] : 0 );
				$pv_to_session[ (int) $r['pv_id'] ] = $skey;
				$session_rows[ $skey ][] = $r;
			}
		}

		$ret_cnt = 0;
		$ret_ary = array();
		$emitted = array();
		foreach ( $int_ids as $id ) {
			if ( ! isset( $pv_to_session[ $id ] ) ) {
				// 日は done なのに行が無い＝不整合（tid 違い含む）。保守的に従来経路へ
				return null;
			}
			$skey = $pv_to_session[ $id ];
			if ( isset( $emitted[ $skey ] ) ) {
				continue; // 同一セッションの重複 id（OLD の searched_pv_id skip と同効果）
			}
			$emitted[ $skey ] = true;
			$rows = $session_rows[ $skey ];

			if ( $count ) {
				$ret_cnt += $this->wrap_count( $rows );
				continue;
			}

			$last_i      = $this->wrap_count( $rows ) - 1;
			$genuine_end = ! empty( $rows[ $last_i ]['is_last'] );
			$session_ary = array();
			foreach ( $rows as $r ) {
				$faithful = $this->rm_r1_project_viewpv_row( $r, $is_reject_map );
				if ( $column === '*' ) {
					if ( $faithful['version_id'] ) {
						$verhist_file = $this->wrap_get_contents( $verhist_dir . $faithful['version_id'] . '_version.php' );
						if ( $verhist_file ) {
							$verhist_ary            = $this->wrap_unserialize( $verhist_file );
							$faithful['version_no'] = $verhist_ary[0]->version_no;
						}
					}
					$session_ary[] = $faithful;
				} else {
					$temp_ary = array();
					foreach ( $column_ary as $column_val ) {
						if ( 'version_no' === $column_val ) {
							if ( $faithful['version_id'] ) {
								$verhist_file = $this->wrap_get_contents( $verhist_dir . $faithful['version_id'] . '_version.php' );
								if ( $verhist_file ) {
									$verhist_ary             = $this->wrap_unserialize( $verhist_file );
									$temp_ary[ $column_val ] = $verhist_ary[0]->version_no;
								}
							}
						} else {
							$temp_ary[ $column_val ] = isset( $faithful[ $column_val ] ) ? $faithful[ $column_val ] : null;
						}
					}
					$session_ary[] = $temp_ary;
				}
			}
			if ( ! $genuine_end && ! empty( $session_ary ) ) {
				$session_ary_last_keynum                            = $this->wrap_count( $session_ary ) - 1;
				$session_ary[ $session_ary_last_keynum ]['is_last'] = '1';
			}
			$ret_ary[] = $session_ary;
		}

		if ( $count ) {
			return $ret_cnt;
		}
		return $ret_ary;
	}

	/**
	 * 日付ラベル（'Y-m-d'）を s..e（両端含む）で列挙する（view_pv 廃止 RM4-R1 PR-b #1412）。
	 *
	 * 純カレンダー演算（gmmktime＝UTC 一貫の日送り）でラベルのみ生成する。範囲への包含判定
	 * （00:00 判定／23:59:59 判定など経路ごとの癖）は呼び出し側が行う。
	 *
	 * @param string $s_str 開始日を先頭に含む文字列（'YYYY?MM?DD...'・区切り任意）.
	 * @param string $e_str 終了日を先頭に含む文字列.
	 * @return array|null ラベル配列。形式不正・範囲異常（100年超）は null.
	 */
	private function rm_r1_enumerate_day_labels( $s_str, $e_str ) {
		if ( ! preg_match( '/^([0-9]{4}).([0-9]{2}).([0-9]{2})/', (string) $s_str, $s_parts ) || ! preg_match( '/^([0-9]{4}).([0-9]{2}).([0-9]{2})/', (string) $e_str, $e_parts ) ) {
			return null;
		}
		$labels    = array();
		$y         = (int) $s_parts[1];
		$m         = (int) $s_parts[2];
		$d         = (int) $s_parts[3];
		$end_label = sprintf( '%04d-%02d-%02d', (int) $e_parts[1], (int) $e_parts[2], (int) $e_parts[3] );
		if ( $end_label < sprintf( '%04d-%02d-%02d', $y, $m, $d ) ) {
			// 逆転範囲（end < start）は null＝従来経路へフォールバック（OLD の DatePeriod は0回転で空を返す＝
			// フォールバック先の OLD がその空を返すことで出力一致。セルフレビュー 🟡-1）
			return null;
		}
		$guard     = 0;
		while ( true ) {
			$label    = sprintf( '%04d-%02d-%02d', $y, $m, $d );
			$labels[] = $label;
			if ( $label >= $end_label ) {
				break;
			}
			if ( ++$guard > 36600 ) { // 100年ガード（暴走防止）
				return null;
			}
			$next = gmmktime( 0, 0, 0, $m, $d + 1, $y );
			$y    = (int) gmdate( 'Y', $next );
			$m    = (int) gmdate( 'n', $next );
			$d    = (int) gmdate( 'j', $next );
		}
		return $labels;
	}

	/**
	 * セッション（builder 行のリスト）を get_vr_view_session の出力形へ射影する（view_pv 廃止 RM4-R1 PR-b #1412）。
	 *
	 * PR-a の rm_r1_vr_sessions_from_allpv 内の射影ループと同一規約（意図的な同型・PR-a 検証済みコードを
	 * 触らないため新設）:
	 * - 忠実 row アダプタ（rm_r1_project_viewpv_row）で view_pv 行へ射影
	 * - version_no は version_id truthy のときだけ verhist を読む（vr_view_session 側の規則）
	 * - is_last 強制＝末尾行の生 is_last が falsy（打ち切りセッション）のときだけ '1' を設定
	 *   （column 射影行にもキーを追加＝OLD の打ち切り点 mutation と同じ）
	 *
	 * @param array  $rows          セッションの builder 行（pv_id 昇順）.
	 * @param string $column        '*' または列リスト（空白除去済み）.
	 * @param array  $column_ary    列配列.
	 * @param string $verhist_dir   version_hist ディレクトリ.
	 * @param array  $is_reject_map reader_id => is_reject マップ.
	 * @return array 射影済みセッション（行配列）.
	 */
	private function rm_r1_project_session( $rows, $column, $column_ary, $verhist_dir, $is_reject_map ) {
		$last_i      = $this->wrap_count( $rows ) - 1;
		$genuine_end = ! empty( $rows[ $last_i ]['is_last'] );
		$session_ary = array();
		foreach ( $rows as $r ) {
			$faithful = $this->rm_r1_project_viewpv_row( $r, $is_reject_map );
			if ( $column === '*' ) {
				if ( $faithful['version_id'] ) {
					$verhist_file = $this->wrap_get_contents( $verhist_dir . $faithful['version_id'] . '_version.php' );
					if ( $verhist_file ) {
						$verhist_ary            = $this->wrap_unserialize( $verhist_file );
						$faithful['version_no'] = $verhist_ary[0]->version_no;
					}
				}
				$session_ary[] = $faithful;
			} else {
				$temp_ary = array();
				foreach ( $column_ary as $column_val ) {
					if ( 'version_no' === $column_val ) {
						if ( $faithful['version_id'] ) {
							$verhist_file = $this->wrap_get_contents( $verhist_dir . $faithful['version_id'] . '_version.php' );
							if ( $verhist_file ) {
								$verhist_ary             = $this->wrap_unserialize( $verhist_file );
								$temp_ary[ $column_val ] = $verhist_ary[0]->version_no;
							}
						}
					} else {
						$temp_ary[ $column_val ] = isset( $faithful[ $column_val ] ) ? $faithful[ $column_val ] : null;
					}
				}
				$session_ary[] = $temp_ary;
			}
		}
		if ( ! $genuine_end && ! empty( $session_ary ) ) {
			$session_ary_last_keynum                            = $this->wrap_count( $session_ary ) - 1;
			$session_ary[ $session_ary_last_keynum ]['is_last'] = '1';
		}
		return $session_ary;
	}

	/**
	 * get_vr_view_session page_id 経路の allpv 版（view_pv 廃止 RM4-R1 PR-b #1412）。
	 *
	 * OLD＝pageid index ファイル駆動（date_period × index 日付の交差→ファイル走査→physical adjacency 復元）。
	 * ON＝日ラベル列挙（s..e 全日）→ find_allpv_pv_ids_for_date（軽量列スキャン・#1406 の初消費）で
	 * 「対象 page のアンカーがある日」を判定 → ある日だけ builder 全行 → session_id グループ →
	 * アンカー（matched pv）を含むセッションを出力。
	 *
	 * OLD の観測可能挙動の再現:
	 * - 出力順＝page_id（ids_ary）順 → 日付昇順 → アンカー pv_id 昇順
	 * - セッション単位 dedup（OLD の searched_pv_id skip＋ret_ary 先頭 pv_id 比較の実効と同じ。
	 *   OLD が interleave 時に非連続再アンカーで count を二重加算しうる緩さは再現しない＝ON がより正確・flip 周知）
	 * - page_id > 10,000,000 は OLD が全体 null（search_max）＝ON も null（従来経路に落ち OLD が null を返す＝一致）
	 * - 期間内にアンカーなし＝空配列／count 0（OLD の index 範囲外と同じ）
	 *
	 * @return int|array|null count は int・セッション配列は array。未 done／不整合は null（フォールバック）.
	 */
	private function rm_r1_vr_sessions_pageid_from_allpv( $tracking_id, $ids_ary, $column, $column_ary, $count, $verhist_dir, $s_daystr, $e_daystr ) {
		global $qahm_file_functions;
		if ( ! is_object( $qahm_file_functions ) || ! method_exists( $qahm_file_functions, 'find_allpv_pv_ids_for_date' ) ) {
			return null;
		}
		$search_max = 10000000;
		$page_ids   = array();
		foreach ( $ids_ary as $id_num ) {
			if ( (int) $id_num > $search_max ) {
				return null;
			}
			$page_ids[] = (int) $id_num;
		}
		$labels = $this->rm_r1_enumerate_day_labels( $s_daystr, $e_daystr );
		if ( null === $labels ) {
			return null;
		}

		// 日ごと: find でアンカー有無を判定 → ある日だけ全行 builder（セッション復元には日の全行が要る）
		$anchor_by_day = array();
		$day_rows      = array();
		$reader_ids    = array();
		foreach ( $labels as $label ) {
			$ymd     = str_replace( '-', '', $label );
			$matched = $qahm_file_functions->find_allpv_pv_ids_for_date( $tracking_id, $ymd, 'page_id', $page_ids );
			if ( null === $matched ) {
				return null; // 未 done 日（データ無し日含む）＝all-or-nothing で従来経路へ
			}
			if ( empty( $matched ) ) {
				continue;
			}
			sort( $matched );
			$rows = $qahm_file_functions->build_allpv_rows_for_date( $tracking_id, $ymd );
			if ( ! is_array( $rows ) ) {
				return null;
			}
			usort(
				$rows,
				function ( $a, $b ) {
					return $a['pv_id'] <=> $b['pv_id'];
				}
			);
			$anchor_by_day[ $label ] = $matched;
			$day_rows[ $label ]      = $rows;
			foreach ( $rows as $r ) {
				$reader_ids[ (int) $r['reader_id'] ] = true;
			}
		}
		if ( empty( $anchor_by_day ) ) {
			return $count ? 0 : array();
		}
		$is_reject_map = QAHM_DB_Functions::get_readers_is_reject( array_keys( $reader_ids ) );

		// pv_id → セッションキー／セッション → 行リスト／pv_id → page_id
		$pv_to_session = array();
		$session_rows  = array();
		$page_of_pv    = array();
		foreach ( $day_rows as $label => $rows ) {
			$ymd = str_replace( '-', '', $label );
			foreach ( $rows as $r ) {
				$skey = $ymd . '|' . ( isset( $r['session_id'] ) ? (int) $r['session_id'] : 0 );
				$pv_to_session[ (int) $r['pv_id'] ] = $skey;
				$session_rows[ $skey ][]            = $r;
				$page_of_pv[ (int) $r['pv_id'] ]    = (int) $r['page_id'];
			}
		}

		$ret_cnt = 0;
		$ret_ary = array();
		$emitted = array();
		foreach ( $page_ids as $pid ) {
			foreach ( $anchor_by_day as $label => $matched ) {
				foreach ( $matched as $anchor_pv ) {
					if ( ! isset( $page_of_pv[ $anchor_pv ] ) || $page_of_pv[ $anchor_pv ] !== $pid ) {
						continue; // matched は複数 page 混在＝この page のアンカーだけを処理（ids_ary 順の再現）
					}
					if ( ! isset( $pv_to_session[ $anchor_pv ] ) ) {
						return null; // 不整合（find と builder の食い違い）＝従来経路へ
					}
					$skey = $pv_to_session[ $anchor_pv ];
					if ( isset( $emitted[ $skey ] ) ) {
						continue; // セッション単位 dedup
					}
					$emitted[ $skey ] = true;
					$rows             = $session_rows[ $skey ];
					if ( $count ) {
						$ret_cnt += $this->wrap_count( $rows );
						continue;
					}
					$ret_ary[] = $this->rm_r1_project_session( $rows, $column, $column_ary, $verhist_dir, $is_reject_map );
				}
			}
		}
		if ( $count ) {
			return $ret_cnt;
		}
		return $ret_ary;
	}

	/**
	 * get_results_view_pv page_id 分岐の allpv 版（view_pv 廃止 RM4-R1 PR-b #1412）。
	 *
	 * 実消費者＝ヒートマップ生成（class-qahm-view-heatmap.php create_heatmap_file＝
	 * `WHERE page_id = %d and access_time between %s and %s`・columns 射影・date 付き）。
	 *
	 * OLD の観測可能挙動の再現:
	 * - 日の包含判定＝**`str_to_unixtime( D . ' 23:59:59' ) ∈ [start, end]`**（OLD の pageid index 日付フィルタを
	 *   bug-for-bug。date-range 分岐の 00:00 判定とは別の癖＝start 日は常に含み・end 日は end が 23:59:59 の時だけ含む）
	 * - count(col) は **isset 判定**（date-range 分岐の truthy 判定と異なる・OLD 忠実）
	 * - version_no 補完なし（OLD の page_id 分岐に version_no ケースは無い）
	 * - 行順＝日付昇順 → pv_id 昇順（OLD の index 順と同じ）・`'*'`／columns とも flat
	 * - date 無し呼び出し＝実消費者ゼロ＝null（従来経路のまま・移行しない）
	 * - 範囲内にアンカーなし＝空配列／count 0（OLD の「index あり・範囲外」と同じ。OLD は page が全履歴で
	 *   未出現だと false を返すが、消費者（heatmap）は truthy チェックのみ＝実害なし・意図的差分として記録）
	 *
	 * @return int|array|null count は int・行は array。date 無し／未 done／不整合は null（フォールバック）.
	 */
	private function rm_r1_results_pageid_from_allpv( $tracking_id, $page_id, $is_date, $start_datetime, $end_datetime, $start_utime, $end_utime, $columns_ary ) {
		global $qahm_time, $qahm_file_functions;
		if ( ! $is_date ) {
			return null;
		}
		if ( ! is_object( $qahm_file_functions ) || ! method_exists( $qahm_file_functions, 'find_allpv_pv_ids_for_date' ) ) {
			return null;
		}
		$labels = $this->rm_r1_enumerate_day_labels( $start_datetime, $end_datetime );
		if ( null === $labels ) {
			return null;
		}

		// 各日: OLD の包含判定（D 23:59:59 ∈ [start, end]）→ find → builder subset
		$day_rows_ary = array();
		$reader_ids   = array();
		foreach ( $labels as $label ) {
			$now_utime = $qahm_time->str_to_unixtime( $label . ' 23:59:59' );
			if ( $now_utime < $start_utime || $end_utime < $now_utime ) {
				continue;
			}
			$ymd     = str_replace( '-', '', $label );
			$matched = $qahm_file_functions->find_allpv_pv_ids_for_date( $tracking_id, $ymd, 'page_id', array( (int) $page_id ) );
			if ( null === $matched ) {
				return null; // 未 done 日＝all-or-nothing で従来経路へ
			}
			if ( empty( $matched ) ) {
				continue;
			}
			sort( $matched );
			$rows = $qahm_file_functions->build_allpv_rows_for_date( $tracking_id, $ymd, $matched );
			if ( ! is_array( $rows ) || $this->wrap_count( $rows ) !== $this->wrap_count( $matched ) ) {
				return null; // 不整合＝従来経路へ
			}
			usort(
				$rows,
				function ( $a, $b ) {
					return $a['pv_id'] <=> $b['pv_id'];
				}
			);
			$day_rows_ary[ $label ] = $rows;
			foreach ( $rows as $r ) {
				$reader_ids[ (int) $r['reader_id'] ] = true;
			}
		}
		$is_reject_map = QAHM_DB_Functions::get_readers_is_reject( array_keys( $reader_ids ) );

		$countall    = 0;
		$results_ary = array();
		foreach ( $day_rows_ary as $label => $rows ) {
			switch ( $columns_ary[0] ) {
				case 'count':
					if ( $columns_ary[1] === '*' ) {
						$countall = $countall + $this->wrap_count( $rows );
					} else {
						foreach ( $rows as $r ) {
							$faithful = $this->rm_r1_project_viewpv_row( $r, $is_reject_map );
							// OLD（page_id 分岐 L1097-1100 相当）は isset 判定（truthy でない）＝忠実再現
							if ( isset( $faithful[ $columns_ary[1] ] ) ) {
								++$countall;
							}
						}
					}
					break;

				case '*':
					foreach ( $rows as $r ) {
						$results_ary[] = $this->rm_r1_project_viewpv_row( $r, $is_reject_map );
					}
					break;

				default:
					foreach ( $rows as $r ) {
						$faithful = $this->rm_r1_project_viewpv_row( $r, $is_reject_map );
						$lineary  = array();
						foreach ( $columns_ary as $column ) {
							$lineary[ $column ] = isset( $faithful[ $column ] ) ? $faithful[ $column ] : null;
						}
						$results_ary[] = $lineary;
					}
					break;
			}
		}

		if ( $columns_ary[0] === 'count' ) {
			return $countall;
		}
		return $results_ary;
	}


	/**
	 * protected
	 */
	protected function get_results_view_page_version_hist( $query = null, $tracking_id = 'all' ) {
		// #1153: version_hist は 'all' ディレクトリ（全 tracking_id 横断）に格納されるため、
		// ファイル名日付の解釈は 'all' 暦（＝WP-TZ）で揃える。get_site_clock('all') は WP-TZ を返す。
		$clock = QAHM_Time::get_site_clock( 'all' );

		//view_pv table is in file
		$data_dir = $this->get_data_dir_path();
		$view_dir = $data_dir . 'view/';
		//view_pv dirlist
		// $verhist_dir = $view_dir . $tracking_id . '/version_hist/';
		// $verhist_idx_dir = $view_dir . $tracking_id . '/version_hist/index/';
		$verhist_dir     = $view_dir . 'all' . '/version_hist/';
		$verhist_idx_dir = $view_dir . 'all' . '/version_hist/index/';

		if ( $this->wrap_array_key_exists( $tracking_id, $this->version_hist_dirlist_mem ) && $this->version_hist_dirlist_mem[ $tracking_id ] ) {
			$allfiles = $this->version_hist_dirlist_mem[ $tracking_id ];
		} else {
			$allfiles                                       = $this->wrap_dirlist( $verhist_dir );
			$this->version_hist_dirlist_mem[ $tracking_id ] = $allfiles; //メモリーに記録
		}

		// columns
		preg_match( '/select (.*?) /i', $query, $sel_columns );
		$sel_column  = str_replace( ' ', '', $sel_columns[1] );
		$columns_ary = array();
		if ( $sel_column === '*' ) {
			$columns_ary[0] = '*';
		} elseif ( preg_match( '/count\((.*)\)/', $sel_column, $counts ) ) {
			$columns_ary[0] = 'count';
			$columns_ary[1] = $counts[1];
		} else {
			$columns_ary = $this->wrap_explode( ',', $sel_column );
		}
		$is_error    = false;
		$results_ary = array();
		$countall    = 0;
		// where date
		if ( preg_match( '/where update_date between.*([0-9]{4}.[0-9]{2}.[0-9]{2}.[0-9]{2}.[0-9]{2}.[0-9]{2}).*and.*([0-9]{4}.[0-9]{2}.[0-9]{2}.[0-9]{2}.[0-9]{2}.[0-9]{2})/i', $query, $date_strings ) ) {
			$s_unixtime = $clock->str_to_unixtime( $date_strings[1] );
			$e_unixtime = $clock->str_to_unixtime( $date_strings[2] );

			foreach ( $allfiles as $file ) {
				$filename = $file['name'];
				if ( is_file( $verhist_dir . $filename ) ) {
					$f_date = $this->wrap_substr( $filename, 0, 10 ) . ' 00:00:00';

					$f_unixtime = $clock->str_to_unixtime( $f_date );
					if ( $s_unixtime <= $f_unixtime && $f_unixtime <= $e_unixtime ) {
						$tmpary = $this->wrap_unserialize( $this->wrap_get_contents( $verhist_dir . $filename ) );
						//データは連想配列に入っている
						//取得するデータによって処理をわける
						switch ( $columns_ary[0] ) {
							case 'count':
								if ( $columns_ary[1] === '*' ) {
									$countall = $countall + $this->wrap_count( $tmpary );
								} else {
									foreach ( $tmpary as $bodyary ) {
										if ( $bodyary[ $columns_ary[1] ] ) {
											++$countall;
										}
									}
								}
								break;

							case '*':
								$results_ary[] = $tmpary;
								break;

							default:
								foreach ( $tmpary as $bodyary ) {
									$lineary = array();
									foreach ( $columns_ary as $column ) {
										$lineary[ $column ] = $bodyary[ $column ];
									}
									$results_ary[] = $lineary;
								}
								break;
						}
					}
				}
			}

			// where version_id
		} elseif ( preg_match( '/where version_id = ([0-9]*)/i', $query, $veridary ) ) {
			$version_id = (int) $veridary[1];
			$filename   = $version_id . '_version.php';
			$tmpary     = $this->wrap_unserialize( $this->wrap_get_contents( $verhist_dir . $filename ) );

			// stdClassから連想配列にキャスト変換
			$tmpary = json_decode( $this->wrap_json_encode( $tmpary ), true );

			//データは連想配列に入っている
			//取得するデータによって処理をわける
			for ( $jjj = 0; $jjj < $this->wrap_count( $tmpary ); $jjj++ ) {
				if ( (int) $tmpary[ $jjj ]['version_id'] === $version_id ) {
					switch ( $columns_ary[0] ) {
						case 'count':
							if ( $columns_ary[1] === '*' ) {
								$countall = 1;
							} else {
								if ( $tmpary[ $jjj ][ $columns_ary[1] ] ) {
									$countall = 1;
								}
							}
							break;

						case '*':
							$results_ary[] = $tmpary[ $jjj ];
							break;

						default:
							$lineary = array();
							foreach ( $columns_ary as $column ) {
								$lineary[ $column ] = $tmpary[ $jjj ][ $column ];
							}
							$results_ary[] = $lineary;
							break;
					}
				}
			}
		} elseif ( preg_match( '/where page_id = ([0-9]*)/i', $query, $pageidary ) ) {
			$page_id = (int) $pageidary[1];

			$id_ary = $this->get_index_file_contents( $verhist_idx_dir, 'pageid', $page_id );
			if ( ! $id_ary ) {
				return false;
			}

			foreach ( $id_ary as $id ) {
				$filename = $id . '_version.php';
				if ( is_file( $verhist_dir . $filename ) ) {
					$tmpary = $this->wrap_unserialize( $this->wrap_get_contents( $verhist_dir . $filename ) );

					// stdClassから連想配列にキャスト変換
					$tmpary = json_decode( $this->wrap_json_encode( $tmpary ), true );

					//データは連想配列に入っている
					//取得するデータによって処理をわける
					for ( $jjj = 0; $jjj < $this->wrap_count( $tmpary ); $jjj++ ) {
						if ( (int) $tmpary[ $jjj ]['page_id'] === (int) $page_id ) {
							switch ( $columns_ary[0] ) {
								case 'count':
									if ( $columns_ary[1] === '*' ) {
										$countall = 1;
									} else {
										if ( $tmpary[ $jjj ][ $columns_ary[1] ] ) {
											$countall = 1;
										}
									}
									break;

								case '*':
									$results_ary[] = $tmpary[ $jjj ];
									break;

								default:
									$lineary = array();
									foreach ( $columns_ary as $column ) {
										$lineary[ $column ] = $tmpary[ $jjj ][ $column ];
									}
									$results_ary[] = $lineary;
									break;
							}
						}
					}
				}
			}
			// 必須項目の指定がないのでエラー
		} else {
			$is_error = true;
		}

		//返値をセット
		if ( $is_error ) {
			return false;
		} else {
			if ( $columns_ary[0] === 'count' ) {
				return $countall;
			} else {
				return $results_ary;
			}
		}
	}

	protected function get_results_days_access( $query = null, $tracking_id = 'all' ) {
		// #1153: per-tid summary を読むので時計も同じ tracking_id で（'all' は WP-TZ 短絡）。
		$clock = QAHM_Time::get_site_clock( $tracking_id );
		//view_pv table is in file
		$data_dir                 = $this->get_data_dir_path();
		$view_dir                 = $data_dir . 'view/';
		$myview_dir               = $view_dir . $tracking_id . '/';
		$summary_dir              = $myview_dir . 'summary/';
		$summary_days_access_file = $summary_dir . 'days_access.php';
		global $wp_filesystem;
		// columns
		preg_match( '/select (.*) from/i', $query, $sel_columns );
		$sel_column  = str_replace( ' ', '', $sel_columns[1] );
		$columns_ary = array();
		if ( $sel_column === '*' ) {
			$columns_ary[0] = '*';
		} elseif ( preg_match( '/count\((.*)\)/', $sel_column, $counts ) ) {
			$columns_ary[0] = 'count';
			$columns_ary[1] = $counts[1];
		} else {
			$columns_ary = $this->wrap_explode( ',', $sel_column );
		}
		$is_error    = false;
		$results_ary = array();
		$countall    = 0;

		// アクセス一覧を読み込む。存在しなければfalseを返す
		if ( $wp_filesystem->exists( $summary_days_access_file ) ) {
			$days_access_ary = $this->wrap_unserialize( $this->wrap_get_contents( $summary_days_access_file ) );
		} else {
			return false;
		}
		// where date
		if ( preg_match( '/where date between.*([0-9]{4}.[0-9]{2}.[0-9]{2}).*and.*([0-9]{4}.[0-9]{2}.[0-9]{2})/i', $query, $date_strings ) ) {
			$s_daystr   = $date_strings[1];
			$e_daystr   = $date_strings[2];
			$s_unixtime = $clock->str_to_unixtime( $s_daystr . ' 00:00:00' );
			$e_unixtime = $clock->str_to_unixtime( $e_daystr . ' 23:59:59' );

			$is_loop = true;
			$loopcnt = 0;
			while ( $is_loop ) {
				$rowdate      = $days_access_ary[ $loopcnt ]['date'];
				$row_unixtime = $clock->str_to_unixtime( $rowdate . ' 00:00:00' );

				if ( $s_unixtime <= $row_unixtime && $row_unixtime <= $e_unixtime ) {
					//データは連想配列に入っている
					//取得するデータによって処理をわける
					switch ( $columns_ary[0] ) {
						case 'count':
							if ( $columns_ary[1] === '*' ) {
								++$countall;
							} else {
								if ( $days_access_ary[ $loopcnt ][ $columns_ary[1] ] ) {
									++$countall;
								}
							}
							break;

						case '*':
							$results_ary[] = $days_access_ary[ $loopcnt ];
							break;

						default:
							$lineary = array();
							foreach ( $columns_ary as $column ) {
								$lineary[ $column ] = $days_access_ary[ $loopcnt ][ $column ];
							}
							$results_ary[] = $lineary;
							break;
					}
				}
				if ( $e_unixtime < $row_unixtime ) {
					$is_loop = false;
				}
				if ( $rowdate === $e_daystr ) {
					$is_loop = false;
				}
				++$loopcnt;
				if ( $loopcnt >= $this->wrap_count( $days_access_ary ) ) {
					$is_loop = false;
				}
			}

			// where pv_id
			// 必須項目の指定がないのでエラー
		} else {
			$is_error = true;
		}

		//返値をセット
		if ( $is_error ) {
			return false;
		} else {
			if ( $columns_ary[0] === 'count' ) {
				return $countall;
			} else {
				return $results_ary;
			}
		}
	}

	/**
	 * #1105: 広告トラフィックの source_domain 補完（表示時のみ。DB は変更しない）
	 *
	 * @param array $results_raw_ary 参照渡し
	 */
	private function compensate_ad_source_domains( &$results_raw_ary ) {
		foreach ( $results_raw_ary as $idx => $line_ary ) {
			if ( isset( $line_ary['source_domain'] ) && isset( $line_ary['utm_source'] ) ) {
				$results_raw_ary[ $idx ]['source_domain'] = QAHM_Base::resolve_ad_source_domain(
					$line_ary['source_domain'],
					$line_ary['utm_source'],
					isset( $line_ary['utm_medium'] ) ? $line_ary['utm_medium'] : ''
				);
			}
		}
	}

	/**
	 * @param null $query
	 * @return array|bool|int
	 */
	protected function get_results_days_access_detail( $query = null, $tracking_id = 'all' ) {
		// #1153: per-tid summary を読むので時計も同じ tracking_id で（'all' は WP-TZ 短絡）。
		$clock = QAHM_Time::get_site_clock( $tracking_id );
		//view_pv table is in file
		$data_dir                        = $this->get_data_dir_path();
		$view_dir                        = $data_dir . 'view/';
		$myview_dir                      = $view_dir . $tracking_id . '/';
		$summary_dir                     = $myview_dir . 'summary/';
		$summary_days_access_detail_file = $summary_dir . 'days_access_detail.php';
		global $wp_filesystem;
		// columns
		preg_match( '/select (.*) from/i', $query, $sel_columns );
		$sel_column  = str_replace( ' ', '', $sel_columns[1] );
		$columns_ary = array();
		if ( $sel_column === '*' ) {
			$columns_ary[0] = '*';
		} elseif ( preg_match( '/count\((.*)\)/', $sel_column, $counts ) ) {
			$columns_ary[0] = 'count';
			$columns_ary[1] = $counts[1];
		} else {
			$columns_ary = $this->wrap_explode( ',', $sel_column );
		}
		$is_error        = false;
		$results_ary     = array();
		$results_raw_ary = array();
		$countall        = 0;

		// アクセス一覧を読み込む。存在しなければfalseを返す
		if ( $wp_filesystem->exists( $summary_days_access_detail_file ) ) {
			$days_access_detail_ary = $this->wrap_unserialize( $this->wrap_get_contents( $summary_days_access_detail_file ) );
		} else {
			return false;
		}
		// where date
		if ( preg_match( '/where date between.*([0-9]{4}.[0-9]{2}.[0-9]{2}).*and.*([0-9]{4}.[0-9]{2}.[0-9]{2})/i', $query, $date_strings ) ) {
			$s_daystr   = $date_strings[1];
			$e_daystr   = $date_strings[2];
			$s_unixtime = $clock->str_to_unixtime( $s_daystr . ' 00:00:00' );
			$e_unixtime = $clock->str_to_unixtime( $e_daystr . ' 23:59:59' );

			$is_loop = true;
			$loopcnt = 0;
			while ( $is_loop ) {
				$rowdate      = $days_access_detail_ary[ $loopcnt ]['date'];
				$row_unixtime = $clock->str_to_unixtime( $rowdate . ' 00:00:00' );
				$tmp_retary   = array();
				if ( $s_unixtime <= $row_unixtime && $row_unixtime <= $e_unixtime ) {
					//データは連想配列に入っている
					//取得するデータによって処理をわける
					switch ( $columns_ary[0] ) {
						case 'count':
							if ( $columns_ary[1] === '*' ) {
								++$countall;
							} else {
								if ( $days_access_detail_ary[ $loopcnt ][ $columns_ary[1] ] ) {
									++$countall;
								}
							}
							break;

						case '*':
							$tmp_retary = $days_access_detail_ary[ $loopcnt ]['data'];
							foreach ( $tmp_retary as $idxd => $aryd ) {
								foreach ( $aryd as $idxs => $arys ) {
									foreach ( $arys as $idxm => $arym ) {
										foreach ( $arym as $idxc => $aryc ) {
											foreach ( $aryc as $idxn => $aryn ) {
												foreach ( $aryn as $idxq => $aryq ) {
													$results_raw_ary[] = array(
														self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[0] => $rowdate,
														self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[1] => $idxd,
														self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[2] => $idxs,
														self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[3] => $idxm,
														self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[4] => $idxc,
														self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[5] => $idxn,
														self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[6] => $idxq,
														self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[7] => $tmp_retary[ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['pv_count'],
														self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[8] => $tmp_retary[ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['session_count'],
														self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[9] => $tmp_retary[ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['user_count'],
														self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[10] => $tmp_retary[ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['bounce_count'],
														self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[11] => $tmp_retary[ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['time_on_page'],
													);
												}
											}
										}
									}
								}
							}
							break;

						default:
							break;
					}
				}
				if ( $e_unixtime < $row_unixtime ) {
					$is_loop = false;
				}
				if ( $rowdate === $e_daystr ) {
					$is_loop = false;
				}
				++$loopcnt;
				if ( $loopcnt >= $this->wrap_count( $days_access_detail_ary ) ) {
					$is_loop = false;
				}
			}

			// where pv_id
			// 必須項目の指定がないのでエラー
		} else {
			$is_error = true;
		}
		//配列の要素を文字列変換
		$sid_ary = array();
		$mid_ary = array();
		$cid_ary = array();
		foreach ( $results_raw_ary as $idx => $line_ary ) {
			$sid = (int) $line_ary['utm_source'];
			if ( ! isset( $sid_ary[ $sid ] ) ) {
				$query      = 'select utm_source, source_domain from ' . $this->prefix . 'qa_utm_sources where source_id=%d';
				$query      = $this->prepare( $query, $sid );
				$utm_source = $this->get_results( $query );
				if ( ! empty( $utm_source ) ) {
					$results_raw_ary[ $idx ]['utm_source']    = $utm_source[0]->utm_source;
					$results_raw_ary[ $idx ]['source_domain'] = $utm_source[0]->source_domain;
					$sid_ary[ $sid ]                          = array( $utm_source[0]->utm_source, $utm_source[0]->source_domain );
				}
			} else {
				$results_raw_ary[ $idx ]['utm_source']    = $sid_ary[ $sid ][0];
				$results_raw_ary[ $idx ]['source_domain'] = $sid_ary[ $sid ][1];
			}
			$mid = (int) $line_ary['utm_medium'];
			if ( ! isset( $mid_ary[ $mid ] ) ) {
				$query      = 'select utm_medium from ' . $this->prefix . 'qa_utm_media where medium_id=%d';
				$query      = $this->prepare( $query, $mid );
				$utm_medium = $this->get_results( $query );
				if ( ! empty( $utm_medium ) ) {
					$results_raw_ary[ $idx ]['utm_medium'] = $utm_medium[0]->utm_medium;
					$mid_ary[ $mid ]                       = $utm_medium[0]->utm_medium;
				} else {
					$results_raw_ary[ $idx ]['utm_medium'] = '';
					$mid_ary[ $mid ]                       = '';
				}
			} else {
				$results_raw_ary[ $idx ]['utm_medium'] = $mid_ary[ $mid ];
			}
			$cid = (int) $line_ary['utm_campaign'];
			if ( ! isset( $cid_ary[ $cid ] ) ) {
				$query        = 'select utm_campaign from ' . $this->prefix . 'qa_utm_campaigns where campaign_id=%d';
				$query        = $this->prepare( $query, $cid );
				$utm_campaign = $this->get_results( $query );
				if ( ! empty( $utm_campaign ) ) {
					$results_raw_ary[ $idx ]['utm_campaign'] = $utm_campaign[0]->utm_campaign;
					$cid_ary[ $cid ]                         = $utm_campaign[0]->utm_campaign;
				}
			} else {
				$results_raw_ary[ $idx ]['utm_campaign'] = $cid_ary[ $cid ];
			}
		}
		// #1105: 広告トラフィックの source_domain 補完
		$this->compensate_ad_source_domains( $results_raw_ary );

		//返値をセット
		if ( $is_error ) {
			return false;
		} else {
			if ( $columns_ary[0] === 'count' ) {
				return $countall;
			} else {
				return $results_raw_ary;
			}
		}
	}

	protected function get_results_vr_summary_landingpage( $query = null, $tracking_id = 'all' ) {
		// #1153: per-tid summary を読むので時計も同じ tracking_id で（'all' は WP-TZ 短絡）。
		$clock = QAHM_Time::get_site_clock( $tracking_id );
		//view_pv table is in file
		$data_dir    = $this->get_data_dir_path();
		$view_dir    = $data_dir . 'view/';
		$myview_dir  = $view_dir . $tracking_id . '/';
		$summary_dir = $myview_dir . 'summary/';

		global $wp_filesystem;

		// columns
		preg_match( '/select (.*) from/i', $query, $sel_columns );
		$sel_column  = str_replace( ' ', '', $sel_columns[1] );
		$columns_ary = array();
		if ( $sel_column === '*' ) {
			$columns_ary[0] = '*';
		} elseif ( preg_match( '/count\((.*)\)/', $sel_column, $counts ) ) {
			$columns_ary[0] = 'count';
			$columns_ary[1] = $counts[1];
		} else {
			$columns_ary = $this->wrap_explode( ',', $sel_column );
		}

		$is_error        = false;
		$results_raw_ary = array();
		$countall        = 0;
		$allfiles        = $this->wrap_dirlist( $summary_dir );

		// where date
		if ( preg_match( '/where date between.*([0-9]{4}.[0-9]{2}.[0-9]{2}).*and.*([0-9]{4}.[0-9]{2}.[0-9]{2})/i', $query, $date_strings ) ) {
			$s_daystr   = $date_strings[1];
			$e_daystr   = $date_strings[2];
			$s_unixtime = $clock->str_to_unixtime( $s_daystr . ' 00:00:00' );
			$e_unixtime = $clock->str_to_unixtime( $e_daystr . ' 23:59:59' );

			foreach ( $allfiles as $file ) {
				$filename = $file['name'];
				if ( strrpos( $filename, 'landingpage.php' ) === false ) {
					continue;
				} else {
					$f_date = $this->wrap_substr( $filename, 0, 10 ) . ' 00:00:00';

					$f_unixtime = $clock->str_to_unixtime( $f_date );
					if ( $s_unixtime <= $f_unixtime && $f_unixtime <= $e_unixtime ) {
						$tmpary = $this->wrap_unserialize( $this->wrap_get_contents( $summary_dir . $filename ) );
						//データは連想配列に入っている
						//取得するデータによって処理をわける
						switch ( $columns_ary[0] ) {
							case 'count':
								if ( $columns_ary[1] === '*' ) {
									$countall = $countall + $this->wrap_count( $tmpary );
								} else {
									foreach ( $tmpary as $bodyary ) {
										if ( $bodyary[ $columns_ary[1] ] ) {
											++$countall;
										}
									}
								}
								break;

							case '*':
								foreach ( $tmpary as $idxp => $aryp ) {
									foreach ( $aryp as $idxd => $aryd ) {
										foreach ( $aryd as $idxs => $arys ) {
											foreach ( $arys as $idxm => $arym ) {
												foreach ( $arym as $idxc => $aryc ) {
													foreach ( $aryc as $idxn => $aryn ) {
														foreach ( $aryn as $idx2 => $ary2 ) {
															foreach ( $ary2 as $idxq => $aryq ) {
																$results_raw_ary[] = array(
																	self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[0] => $f_date,
																	self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[1] => $idxp,
																	self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[2] => $idxd,
																	self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[3] => $idxs,
																	self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[4] => $idxm,
																	self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[5] => $idxc,
																	self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[6] => $idxn,
																	self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[7] => $idx2,
																	self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[8] => $idxq,
																	self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[9] => $tmpary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idx2 ][ $idxq ]['pv_count'],
																	self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[10] => $tmpary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idx2 ][ $idxq ]['session_count'],
																	self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[11] => $tmpary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idx2 ][ $idxq ]['user_count'],
																	self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[12] => $tmpary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idx2 ][ $idxq ]['bounce_count'],
																	self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[13] => $tmpary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idx2 ][ $idxq ]['session_time'],
																);
															}
														}
													}
												}
											}
										}
									}
								}
								break;

							default:
								break;
						}
					}
				}
			}
		}
		//配列の要素を文字列変換
		$pid_ary = array();
		$sid_ary = array();
		$mid_ary = array();
		$cid_ary = array();
		foreach ( $results_raw_ary as $idx => $line_ary ) {
			$pid = (int) $line_ary['page_id'];
			if ( ! isset( $pid_ary[ $pid ] ) ) {
				$query                               = 'select title, url, wp_qa_id from ' . $this->prefix . 'qa_pages where page_id=%d';
				$query                               = $this->prepare( $query, $pid );
				$page                                = $this->get_results( $query );
				$results_raw_ary[ $idx ]['title']    = $page[0]->title;
				$results_raw_ary[ $idx ]['url']      = $page[0]->url;
				$results_raw_ary[ $idx ]['wp_qa_id'] = $page[0]->wp_qa_id;
				$pid_ary[ $pid ]                     = array( $page[0]->title, $page[0]->url, $page[0]->wp_qa_id );
			} else {
				$results_raw_ary[ $idx ]['title']    = $pid_ary[ $pid ][0];
				$results_raw_ary[ $idx ]['url']      = $pid_ary[ $pid ][1];
				$results_raw_ary[ $idx ]['wp_qa_id'] = $pid_ary[ $pid ][2];
			}
			$sid = (int) $line_ary['utm_source'];
			if ( ! isset( $sid_ary[ $sid ] ) ) {
				$query      = 'select utm_source, source_domain from ' . $this->prefix . 'qa_utm_sources where source_id=%d';
				$query      = $this->prepare( $query, $sid );
				$utm_source = $this->get_results( $query );
				if ( ! empty( $utm_source ) ) {
					$results_raw_ary[ $idx ]['utm_source']    = $utm_source[0]->utm_source;
					$results_raw_ary[ $idx ]['source_domain'] = $utm_source[0]->source_domain;
					$sid_ary[ $sid ]                          = array( $utm_source[0]->utm_source, $utm_source[0]->source_domain );
				}
			} else {
				$results_raw_ary[ $idx ]['utm_source']    = $sid_ary[ $sid ][0];
				$results_raw_ary[ $idx ]['source_domain'] = $sid_ary[ $sid ][1];
			}
			$mid = (int) $line_ary['utm_medium'];
			if ( ! isset( $mid_ary[ $mid ] ) ) {
				$query      = 'select utm_medium from ' . $this->prefix . 'qa_utm_media where medium_id=%d';
				$query      = $this->prepare( $query, $mid );
				$utm_medium = $this->get_results( $query );
				if ( ! empty( $utm_medium ) ) {
					$results_raw_ary[ $idx ]['utm_medium'] = $utm_medium[0]->utm_medium;
					$mid_ary[ $mid ]                       = $utm_medium[0]->utm_medium;
				} else {
					$results_raw_ary[ $idx ]['utm_medium'] = '';
					$mid_ary[ $mid ]                       = '';
				}
			} else {
				$results_raw_ary[ $idx ]['utm_medium'] = $mid_ary[ $mid ];
			}
			$cid = (int) $line_ary['utm_campaign'];
			if ( ! isset( $cid_ary[ $cid ] ) ) {
				$query        = 'select utm_campaign from ' . $this->prefix . 'qa_utm_campaigns where campaign_id=%d';
				$query        = $this->prepare( $query, $cid );
				$utm_campaign = $this->get_results( $query );
				if ( ! empty( $utm_campaign ) ) {
					$results_raw_ary[ $idx ]['utm_campaign'] = $utm_campaign[0]->utm_campaign;
					$cid_ary[ $cid ]                         = $utm_campaign[0]->utm_campaign;
				}
			} else {
				$results_raw_ary[ $idx ]['utm_campaign'] = $cid_ary[ $cid ];
			}
		}
		// #1105: 広告トラフィックの source_domain 補完
		$this->compensate_ad_source_domains( $results_raw_ary );

		//返値をセット
		if ( $is_error ) {
			return false;
		} else {
			if ( $columns_ary[0] === 'count' ) {
				return $countall;
			} else {
				return $results_raw_ary;
			}
		}
	}

	protected function get_results_vr_summary_allpage( $query = null, $tracking_id = 'all' ) {
		// #1153: per-tid summary を読むので時計も同じ tracking_id で（'all' は WP-TZ 短絡）。
		$clock = QAHM_Time::get_site_clock( $tracking_id );
		//view_pv table is in file
		$data_dir    = $this->get_data_dir_path();
		$view_dir    = $data_dir . 'view/';
		$myview_dir  = $view_dir . $tracking_id . '/';
		$summary_dir = $myview_dir . 'summary/';

		// columns
		preg_match( '/select (.*) from/i', $query, $sel_columns );
		$sel_column  = str_replace( ' ', '', $sel_columns[1] );
		$columns_ary = array();
		if ( $sel_column === '*' ) {
			$columns_ary[0] = '*';
		} elseif ( preg_match( '/count\((.*)\)/', $sel_column, $counts ) ) {
			$columns_ary[0] = 'count';
			$columns_ary[1] = $counts[1];
		} else {
			$columns_ary = $this->wrap_explode( ',', $sel_column );
		}

		$is_error        = false;
		$results_raw_ary = array();
		$countall        = 0;
		$allfiles        = $this->wrap_dirlist( $summary_dir );

		// where date
		if ( preg_match( '/where date between.*([0-9]{4}.[0-9]{2}.[0-9]{2}).*and.*([0-9]{4}.[0-9]{2}.[0-9]{2})/i', $query, $date_strings ) ) {
			$s_daystr   = $date_strings[1];
			$e_daystr   = $date_strings[2];
			$s_unixtime = $clock->str_to_unixtime( $s_daystr . ' 00:00:00' );
			$e_unixtime = $clock->str_to_unixtime( $e_daystr . ' 23:59:59' );

			foreach ( $allfiles as $file ) {
				$filename = $file['name'];
				if ( strrpos( $filename, 'allpage.php' ) === false ) {
					continue;
				} else {
					$f_date = $this->wrap_substr( $filename, 0, 10 ) . ' 00:00:00';

					$f_unixtime = $clock->str_to_unixtime( $f_date );
					if ( $s_unixtime <= $f_unixtime && $f_unixtime <= $e_unixtime ) {
						$tmpary = $this->wrap_unserialize( $this->wrap_get_contents( $summary_dir . $filename ) );
						//データは連想配列に入っている
						//取得するデータによって処理をわける
						switch ( $columns_ary[0] ) {
							case 'count':
								if ( $columns_ary[1] === '*' ) {
									$countall = $countall + $this->wrap_count( $tmpary );
								} else {
									foreach ( $tmpary as $bodyary ) {
										if ( $bodyary[ $columns_ary[1] ] ) {
											++$countall;
										}
									}
								}
								break;

							case '*':
								foreach ( $tmpary as $idxp => $aryp ) {
									foreach ( $aryp as $idxd => $aryd ) {
										foreach ( $aryd as $idxs => $arys ) {
											foreach ( $arys as $idxm => $arym ) {
												foreach ( $arym as $idxc => $aryc ) {
													foreach ( $aryc as $idxn => $aryn ) {
														foreach ( $aryn as $idxq => $aryq ) {
															$results_raw_ary[] = array(
																self::QAHM_VR_SUMMARY_ALLPAGE_COL[0] => $f_date,
																self::QAHM_VR_SUMMARY_ALLPAGE_COL[1] => $idxp,
																self::QAHM_VR_SUMMARY_ALLPAGE_COL[2] => $idxd,
																self::QAHM_VR_SUMMARY_ALLPAGE_COL[3] => $idxs,
																self::QAHM_VR_SUMMARY_ALLPAGE_COL[4] => $idxm,
																self::QAHM_VR_SUMMARY_ALLPAGE_COL[5] => $idxc,
																self::QAHM_VR_SUMMARY_ALLPAGE_COL[6] => $idxn,
																self::QAHM_VR_SUMMARY_ALLPAGE_COL[7] => $idxq,
																self::QAHM_VR_SUMMARY_ALLPAGE_COL[8] => $tmpary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['pv_count'],
																self::QAHM_VR_SUMMARY_ALLPAGE_COL[9] => $tmpary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['user_count'],
																self::QAHM_VR_SUMMARY_ALLPAGE_COL[10] => $tmpary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['bounce_count'],
																self::QAHM_VR_SUMMARY_ALLPAGE_COL[11] => $tmpary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['exit_count'],
																self::QAHM_VR_SUMMARY_ALLPAGE_COL[12] => $tmpary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['time_on_page'],
																self::QAHM_VR_SUMMARY_ALLPAGE_COL[13] => $tmpary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['lp_count'],
															);
														}
													}
												}
											}
										}
									}
								}
								break;

							default:
								break;
						}
					}
				}
			}
		}
		//配列の要素を文字列変換
		$pid_ary = array();
		$sid_ary = array();
		$mid_ary = array();
		$cid_ary = array();
		foreach ( $results_raw_ary as $idx => $line_ary ) {
			$pid = (int) $line_ary['page_id'];
			if ( ! isset( $pid_ary[ $pid ] ) ) {
				$query                               = 'select title, url, wp_qa_id from ' . $this->prefix . 'qa_pages where page_id=%d';
				$query                               = $this->prepare( $query, $pid );
				$page                                = $this->get_results( $query );
				$results_raw_ary[ $idx ]['title']    = $page[0]->title;
				$results_raw_ary[ $idx ]['url']      = $page[0]->url;
				$results_raw_ary[ $idx ]['wp_qa_id'] = $page[0]->wp_qa_id;
				$pid_ary[ $pid ]                     = array( $page[0]->title, $page[0]->url, $page[0]->wp_qa_id );
			} else {
				$results_raw_ary[ $idx ]['title']    = $pid_ary[ $pid ][0];
				$results_raw_ary[ $idx ]['url']      = $pid_ary[ $pid ][1];
				$results_raw_ary[ $idx ]['wp_qa_id'] = $pid_ary[ $pid ][2];
			}
			$sid = (int) $line_ary['utm_source'];
			if ( ! isset( $sid_ary[ $sid ] ) ) {
				$query      = 'select utm_source, source_domain from ' . $this->prefix . 'qa_utm_sources where source_id=%d';
				$query      = $this->prepare( $query, $sid );
				$utm_source = $this->get_results( $query );
				if ( ! empty( $utm_source ) ) {
					$results_raw_ary[ $idx ]['utm_source']    = $utm_source[0]->utm_source;
					$results_raw_ary[ $idx ]['source_domain'] = $utm_source[0]->source_domain;
					$sid_ary[ $sid ]                          = array( $utm_source[0]->utm_source, $utm_source[0]->source_domain );
				}
			} else {
				$results_raw_ary[ $idx ]['utm_source']    = $sid_ary[ $sid ][0];
				$results_raw_ary[ $idx ]['source_domain'] = $sid_ary[ $sid ][1];
			}
			$mid = (int) $line_ary['utm_medium'];
			if ( ! isset( $mid_ary[ $mid ] ) ) {
				$query      = 'select utm_medium from ' . $this->prefix . 'qa_utm_media where medium_id=%d';
				$query      = $this->prepare( $query, $mid );
				$utm_medium = $this->get_results( $query );
				if ( ! empty( $utm_medium ) ) {
					$results_raw_ary[ $idx ]['utm_medium'] = $utm_medium[0]->utm_medium;
					$mid_ary[ $mid ]                       = $utm_medium[0]->utm_medium;
				} else {
					$results_raw_ary[ $idx ]['utm_medium'] = '';
					$mid_ary[ $mid ]                       = '';
				}
			} else {
				$results_raw_ary[ $idx ]['utm_medium'] = $mid_ary[ $mid ];
			}
			$cid = (int) $line_ary['utm_campaign'];
			if ( ! isset( $cid_ary[ $cid ] ) ) {
				$query        = 'select utm_campaign from ' . $this->prefix . 'qa_utm_campaigns where campaign_id=%d';
				$query        = $this->prepare( $query, $cid );
				$utm_campaign = $this->get_results( $query );
				if ( ! empty( $utm_campaign ) ) {
					$results_raw_ary[ $idx ]['utm_campaign'] = $utm_campaign[0]->utm_campaign;
					$cid_ary[ $cid ]                         = $utm_campaign[0]->utm_campaign;
				}
			} else {
				$results_raw_ary[ $idx ]['utm_campaign'] = $cid_ary[ $cid ];
			}
		}
		// #1105: 広告トラフィックの source_domain 補完
		$this->compensate_ad_source_domains( $results_raw_ary );

		//返値をセット
		if ( $is_error ) {
			return false;
		} else {
			if ( $columns_ary[0] === 'count' ) {
				return $countall;
			} else {
				return $results_raw_ary;
			}
		}
	}



	public function get_index_file_contents( $dir_path, $id_type, $id_num ) {
		$file_name    = null;
		$search_range = 100000;
		$search_max   = 10000000;
		if ( $id_num > $search_max ) {
			return null;
		}
		for ( $i = 1; $i < $search_max; $i += $search_range ) {
			if ( $i <= $id_num && $i + $search_range > $id_num ) {
				$file_name = $i . '-' . ( $i + $search_range - 1 ) . '_' . $id_type . '.php';
				break;
			}
		}

		if ( ! $file_name || ! $this->wrap_exists( $dir_path . $file_name ) ) {
			return null;
		}

		$file = $this->wrap_unserialize( $this->wrap_get_contents( $dir_path . $file_name ) );
		return $file[ $id_num ];
	}

	//積分形式のサマリーファイルの差分、増分を取得する
	public function calculate_integral_summary( $data1, $data2, $operation = 'add' ) {
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
	 * #1590: end 側の積分ファイルが無いとき、存在する直近の日まで遡って「中身のある」積分データを返す。
	 * - 下限＝max( 年初, start_date )＝期間より手前へは戻らない（file-functions.php の同名関数は
	 *   max( 年初, 計測開始日, start_date )。計測開始日より前のファイルは存在しないので結果は同値）。
	 *   遡る日数に上限は置かない＝実在するデータを 0 行にしない（全欠損時のコストは別 Issue で glob 化）。
	 * - 下限日自身も試す（`>=`）。比較の時刻は `!Y-m-d` で 00:00:00 に固定する
	 *   （`Y-m-d` だけだと生成時刻が入り、秒境界で下限日を取りこぼす）。
	 * - 空ファイル・壊れたファイルは「無い」扱いで遡りを続ける（空のまま subtract へ進むと start 側の累積が漏れて膨張する）。
	 * 1つも無ければ null（呼び出し側はその年の差分を「無し」として扱う＝累積を返さない）。
	 */
	private function find_latest_integral_summary_data( $vw_summary_dir, $summary_type, $end_datetime, $start_date ) {
		$year_jan1      = DateTime::createFromFormat( '!Y-m-d', $end_datetime->format( 'Y' ) . '-01-01' );
		$start_date_obj = DateTime::createFromFormat( '!Y-m-d', $start_date );
		$min_datetime   = max( $year_jan1, $start_date_obj );
		$try_datetime   = DateTime::createFromFormat( '!Y-m-d', $end_datetime->format( 'Y-m-d' ) );

		while ( $try_datetime >= $min_datetime ) {
			$try_file = $vw_summary_dir . $try_datetime->format( 'Y-m-d' ) . '_summary_' . $summary_type . '_i.php';
			if ( is_readable( $try_file ) ) {
				$data = $this->wrap_unserialize( $this->wrap_get_contents( $try_file ) );
				if ( ! empty( $data ) && is_array( $data ) ) {
					return $data;
				}
			}
			$try_datetime->modify( '-1 day' );
		}
		return null;
	}

	public function calc_summary_dateterm_total( $start_date, $end_date, $summary_type, $tracking_id = 'all' ) {

		$date_format    = 'Y-m-d';
		$data_dir       = $this->get_data_dir_path();
		$view_dir       = $data_dir . 'view/';
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
						$start_file_path = $vw_summary_dir . $start_file_name;
						$start_data      = $this->wrap_unserialize( $this->wrap_get_contents( $start_file_path ) );
					}
				}
			}

			$year_end_date = ( $year < $end_year ) ? "$year-12-31" : $end_date;

			$end_datetime = DateTime::createFromFormat( 'Y-m-d', $year_end_date );

			if ( ! $end_datetime ) {
				throw new Exception( 'Invalid date format in loop' );
			}

			$end_file_name = $end_datetime->format( $date_format ) . '_summary_' . $summary_type . '_i.php';
			$end_file_path = $vw_summary_dir . $end_file_name;

			if ( is_readable( $end_file_path ) ) {
				$end_data = $this->wrap_unserialize( $this->wrap_get_contents( $end_file_path ) );
			} else {
				// #1590: end 日のファイルが無い（当日分は夜間 cron 前で未生成が既定）ときは、
				// 旧来の break（＝その年を丸ごと落とし 0 行になる）をやめ、中身のある直近の日へ丸める。
				$end_data = $this->find_latest_integral_summary_data( $vw_summary_dir, $summary_type, $end_datetime, $start_date );
				if ( null === $end_data ) {
					continue; // 期間内に1つも無い＝この年の差分は無し（累積を返さない）
				}
			}

			// end_dataが空でstart_dataが存在する場合の特別処理
			if ( empty( $end_data ) && ! empty( $start_data ) ) {
				$start_date_obj = DateTime::createFromFormat( 'Y-m-d', $start_date );
				$end_date_obj   = DateTime::createFromFormat( 'Y-m-d', $end_date );
				$date_diff      = $end_date_obj->diff( $start_date_obj )->days;

				if ( $date_diff == 0 ) {
					// 単一日の場合、その日のファイルが存在するかチェック
					$single_day_file = $vw_summary_dir . $start_date . '_summary_days_access_detail_i.php';
					if ( ! is_readable( $single_day_file ) ) {
						return array(); // その日のデータが存在しない場合は空配列
					}
				} else {
					// 複数日期間の場合、期間内で最後にデータが存在する日を探す
					$last_data_date = null;
					$check_date     = clone $end_date_obj;

					while ( $check_date >= $start_date_obj ) {
						$check_file = $vw_summary_dir . $check_date->format( 'Y-m-d' ) . '_summary_' . $summary_type . '_i.php';
						if ( is_readable( $check_file ) ) {
							$last_data_date = $check_date->format( 'Y-m-d' );
							break;
						}
						$check_date->modify( '-1 day' );
					}

					if ( ! $last_data_date ) {
						return array(); // 期間内にデータが存在しない場合は空配列
					} else {
						// 最後のデータ日までの差分を計算
						$end_data = $this->wrap_unserialize( $this->wrap_get_contents( $vw_summary_dir . $last_data_date . '_summary_' . $summary_type . '_i.php' ) );
					}
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

	public function calc_days_access_detail_dateterm_total( $start_date, $end_date, $tracking_id = 'all' ) {
		$date_format    = 'Y-m-d';
		$data_dir       = $this->get_data_dir_path();
		$view_dir       = $data_dir . 'view/';
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
					// 前日の積分ファイルを直接読み込み（遡り処理削除）
					$start_date_str          = $start_datetime->format( $date_format );
					$start_date_summary_file = $vw_summary_dir . $start_date_str . '_summary_days_access_detail_i.php';
					if ( is_readable( $start_date_summary_file ) ) {
						$start_data = $this->wrap_unserialize( $this->wrap_get_contents( $start_date_summary_file ) );
					}
				}
			}

			$year_end_date = ( $year < $end_year ) ? "$year-12-31" : $end_date;

			// 終了日の積分ファイルを読む。無ければ #1590: 中身のある直近の日へ丸める
			//（旧来は空配列のまま subtract へ進み、start 側の累積がそのまま返って膨張していた）。
			$end_date_str          = $year_end_date;
			$end_date_summary_file = $vw_summary_dir . $end_date_str . '_summary_days_access_detail_i.php';
			if ( is_readable( $end_date_summary_file ) ) {
				$end_data = $this->wrap_unserialize( $this->wrap_get_contents( $end_date_summary_file ) );
			} else {
				$end_datetime = DateTime::createFromFormat( 'Y-m-d', $end_date_str );
				if ( ! $end_datetime ) {
					throw new Exception( 'Invalid date format in loop' );
				}
				$end_data = $this->find_latest_integral_summary_data( $vw_summary_dir, 'days_access_detail', $end_datetime, $start_date );
				if ( null === $end_data ) {
					continue; // 期間内に1つも無い＝この年の差分は無し（累積を返さない）
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

	//-----------------------------
	//summary summary pages file
	//
	//  public function summary_days_landingpages ( $dateterm, $tracking_id="all" ) {
	//      global $qahm_time;
	//      // dir
	//      $data_dir = $this->get_data_dir_path();
	//      $view_dir = $data_dir . 'view/';
	//      $myview_dir = $view_dir . $tracking_id . '/';
	//      $summary_dir = $myview_dir . 'summary/';
	//      $sumallday_ary = [];
	//      $results_raw_ary = [];
	//
	//      if ( preg_match('/^date\s*=\s*between\s*([0-9]{4}.[0-9]{2}.[0-9]{2})\s*and\s*([0-9]{4}.[0-9]{2}.[0-9]{2})$/', $dateterm, $datestrs ) ) {
	//
	//          $s_daystr = $datestrs[1];
	//          $e_daystr = $datestrs[2];
	//
	//          $sumallday_ary = $this->calc_summary_dateterm_total( $s_daystr, $e_daystr , "landingpage" );
	//
	//          foreach ( $sumallday_ary as $idxp => $aryp ) {
	//              foreach ( $aryp as $idxd => $aryd ) {
	//                  foreach ( $aryd as $idxs => $arys ) {
	//                      foreach ( $arys as $idxm => $arym ) {
	//                          foreach ( $arym as $idxc => $aryc ) {
	//                              foreach ( $aryc as $idxn => $aryn ) {
	//                                  foreach ( $aryn as $idx2 => $ary2 ) {
	//                                      foreach ( $ary2 as $idxq => $aryq ) {
	//                                          if ( isset($sumallday_ary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idx2 ][ $idxq ][ 'pv_count' ]) ) {
	//                                              if( $sumallday_ary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idx2 ][ $idxq ][ 'pv_count' ] != 0 ){
	//                                                  $results_raw_ary[] = array
	//                                                  (
	//                                                      self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[ 1 ] => $idxp,
	//                                                      self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[ 2 ] => $idxd,
	//                                                      self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[ 3 ] => $idxs,
	//                                                      self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[ 4 ] => $idxm,
	//                                                      self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[ 5 ] => $idxc,
	//                                                      self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[ 6 ] => $idxn,
	//                                                      self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[ 7 ] => $idx2,
	//                                                      self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[ 8 ] => $idxq,
	//                                                      self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[ 9 ]  => $sumallday_ary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idx2 ][ $idxq ][ 'pv_count' ],
	//                                                      self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[ 10 ] => $sumallday_ary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idx2 ][ $idxq ][ 'session_count' ],
	//                                                      self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[ 11 ] => $sumallday_ary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idx2 ][ $idxq ][ 'user_count' ],
	//                                                      self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[ 12 ] => $sumallday_ary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idx2 ][ $idxq ][ 'bounce_count' ],
	//                                                      self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[ 13 ] => $sumallday_ary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idx2 ][ $idxq ][ 'session_time' ],
	//                                                  );
	//                                              }
	//                                          }
	//                                      }
	//                                  }
	//                              }
	//                          }
	//                      }
	//                  }
	//              }
	//          }
	//      }
	//
	//        $pid_list = [];
	//        $sid_list = [];
	//        $mid_list = [];
	//        $cid_list = [];
	//
	//        foreach ($results_raw_ary as $line_ary) {
	//            $pid = (int)$line_ary['page_id'];
	//            $sid = (int)$line_ary['utm_source'];
	//            $mid = (int)$line_ary['utm_medium'];
	//            $cid = (int)$line_ary['utm_campaign'];
	//
	//            if (!isset($pid_ary[$pid])) {
	//                $pid_list[] = $pid;
	//            }
	//            if (!isset($sid_ary[$sid])) {
	//                $sid_list[] = $sid;
	//            }
	//            if (!isset($mid_ary[$mid])) {
	//                $mid_list[] = $mid;
	//            }
	//            if (!isset($cid_ary[$cid])) {
	//                $cid_list[] = $cid;
	//            }
	//        }
	//
	//
	//// ページ情報を一括で取得
	//        if (!empty($pid_list)) {
	//            $pid_list = array_unique($pid_list);
	//            $page_ids_placeholders = $this->create_in_placeholders( $pid_list );
	//            $query = 'SELECT page_id, title, url, wp_qa_id FROM ' . $this->prefix . 'qa_pages WHERE page_id IN (' . $page_ids_placeholders . ')';
	//            $pages = $this->get_results($this->prepare($query, ...$pid_list));
	//            foreach ($pages as $page) {
	//                $pid_ary[$page->page_id] = [$page->title, $page->url, $page->wp_qa_id];
	//            }
	//        }
	//
	//// UTMソース情報を一括で取得
	//        if (!empty($sid_list)) {
	//            $sid_list = array_unique($sid_list);
	//            $source_ids_placeholders = $this->create_in_placeholders( $sid_list );
	//
	//            $query = 'SELECT source_id, utm_source, source_domain FROM ' . $this->prefix . 'qa_utm_sources WHERE source_id IN (' . $source_ids_placeholders . ')';
	//            $sources = $this->get_results($this->prepare($query, ...$sid_list));
	//            foreach ($sources as $source) {
	//                $sid_ary[$source->source_id] = [$source->utm_source, $source->source_domain];
	//            }
	//        }
	//
	//// UTMメディア情報を一括で取得
	//        if (!empty($mid_list)) {
	//            $mid_list = array_unique($mid_list);
	//            $medium_ids_placeholders = $this->create_in_placeholders( $mid_list );
	//            $query = 'SELECT medium_id, utm_medium FROM ' . $this->prefix . 'qa_utm_media WHERE medium_id IN (' . $medium_ids_placeholders . ')';
	//            $media = $this->get_results($this->prepare($query, ...$mid_list));
	//            foreach ($media as $medium) {
	//                $mid_ary[$medium->medium_id] = $medium->utm_medium;
	//            }
	//        }
	//
	//// UTMキャンペーン情報を一括で取得
	//        if (!empty($cid_list)) {
	//            $cid_list = array_unique($cid_list);
	//            $campaign_ids_placeholders = $this->create_in_placeholders( $cid_list );
	//            $query = 'SELECT campaign_id, utm_campaign FROM ' . $this->prefix . 'qa_utm_campaigns WHERE campaign_id IN (' . $campaign_ids_placeholders . ')';
	//            $campaigns = $this->get_results($this->prepare($query, ...$cid_list));
	//            foreach ($campaigns as $campaign) {
	//                $cid_ary[$campaign->campaign_id] = $campaign->utm_campaign;
	//            }
	//        }
	//// 取得したデータを使用してresults_raw_aryを更新
	//        foreach ($results_raw_ary as $idx => $line_ary) {
	//            $pid = (int)$line_ary['page_id'];
	//            if (isset($pid_ary[$pid])) {
	//                $results_raw_ary[$idx]['title'] = $pid_ary[$pid][0];
	//                $results_raw_ary[$idx]['url'] = $pid_ary[$pid][1];
	//                $results_raw_ary[$idx]['wp_qa_id'] = $pid_ary[$pid][2];
	//            }
	//
	//            $sid = (int)$line_ary['utm_source'];
	//            if (isset($sid_ary[$sid])) {
	//                $results_raw_ary[$idx]['utm_source'] = $sid_ary[$sid][0];
	//                $results_raw_ary[$idx]['source_domain'] = $sid_ary[$sid][1];
	//            }
	//
	//            $mid = (int)$line_ary['utm_medium'];
	//            $results_raw_ary[$idx]['utm_medium'] = $mid_ary[$mid] ?? '';
	//
	//            $cid = (int)$line_ary['utm_campaign'];
	//            $results_raw_ary[$idx]['utm_campaign'] = $cid_ary[$cid] ?? '';
	//        }
	////        //配列の要素を文字列変換
	////        $pid_ary = [];
	////        $sid_ary = [];
	////        $mid_ary = [];
	////        $cid_ary = [];
	////        foreach ( $results_raw_ary as $idx => $line_ary ) {
	////            $pid = (int)$line_ary['page_id'];
	////            if ( !isset( $pid_ary[$pid]) ) {
	////                $query = 'select title, url, wp_qa_id from ' . $this->prefix . 'qa_pages where page_id=%d';
	////                $query = $this->prepare($query, $pid);
	////                $page  = $this->get_results( $query );
	////                $results_raw_ary[$idx]['title'] = $page[0]->title;
	////                $results_raw_ary[$idx]['url'] = $page[0]->url;
	////                $results_raw_ary[$idx]['wp_qa_id'] = $page[0]->wp_qa_id;
	////                $pid_ary[$pid] = array( $page[0]->title, $page[0]->url, $page[0]->wp_qa_id );
	////            } else {
	////                $results_raw_ary[$idx]['title']    = $pid_ary[$pid][0];
	////                $results_raw_ary[$idx]['url']      = $pid_ary[$pid][1];
	////                $results_raw_ary[$idx]['wp_qa_id'] = $pid_ary[$pid][2];
	////            }
	////            $sid = (int)$line_ary['utm_source'];
	////            if ( !isset( $sid_ary[$sid]) ) {
	////                $query = 'select utm_source, source_domain from ' . $this->prefix . 'qa_utm_sources where source_id=%d';
	////                $query = $this->prepare($query, $sid);
	////                $utm_source = $this->get_results( $query );
	////                if ( ! empty( $utm_source ) ) {
	////                    $results_raw_ary[$idx]['utm_source'] = $utm_source[0]->utm_source;
	////                    $results_raw_ary[$idx]['source_domain'] = $utm_source[0]->source_domain;
	////                    $sid_ary[$sid] = array( $utm_source[0]->utm_source, $utm_source[0]->source_domain );
	////                }
	////            } else {
	////                $results_raw_ary[$idx]['utm_source']    = $sid_ary[$sid][0];
	////                $results_raw_ary[$idx]['source_domain'] = $sid_ary[$sid][1];
	////            }
	////            $mid = (int)$line_ary['utm_medium'];
	////            if ( !isset( $mid_ary[$mid]) ) {
	////                $query = 'select utm_medium from ' . $this->prefix . 'qa_utm_media where medium_id=%d';
	////                $query = $this->prepare( $query, $mid );
	////                $utm_medium = $this->get_results( $query );
	////                if ( ! empty( $utm_medium ) ) {
	////                    $results_raw_ary[$idx]['utm_medium'] = $utm_medium[0]->utm_medium;
	////                    $mid_ary[$mid] = $utm_medium[0]->utm_medium;
	////                }else{
	////                    $results_raw_ary[$idx]['utm_medium'] = '';
	////                    $mid_ary[$mid] = '';
	////                }
	////            } else {
	////                $results_raw_ary[$idx]['utm_medium'] = $mid_ary[$mid];
	////            }
	////            $cid = (int)$line_ary['utm_campaign'];
	////            if ( !isset( $cid_ary[$cid]) ) {
	////                $query = 'select utm_campaign from ' . $this->prefix . 'qa_utm_campaigns where campaign_id=%d';
	////                $query = $this->prepare( $query, $cid );
	////                $utm_campaign = $this->get_results( $query );
	////                if ( ! empty( $utm_campaign ) ) {
	////                    $results_raw_ary[$idx]['utm_campaign'] = $utm_campaign[0]->utm_campaign;
	////                    $cid_ary[$cid] = $utm_campaign[0]->utm_campaign;
	////                }
	////            } else {
	////                $results_raw_ary[$idx]['utm_campaign'] = $cid_ary[$cid];
	////            }
	////        }
	//
	//      return $results_raw_ary;
	//  }

	/*
		ページ別の集計データを取得するメソッドです。

		$group_by_keys は、結果をどのキーでグループ化して集計するかを指定するための配列です。
		この配列に指定されたキー（例えば 'utm_source' や 'utm_campaign' など）に基づいて、
		同じキーのデータを集約して、ページビュー数やセッション数などの数値データを合計します。

		例:
		$group_by_keys = ['utm_source'] と指定した場合、utm_source ごとにデータをグループ化して集計します。
		$group_by_keys = ['utm_source', 'utm_campaign'] の場合、utm_source と utm_campaign の組み合わせごとに集計が行われます。
		このように、$group_by_keys はどのディメンション（キー）でデータを集約するかを指定するために使用され、結果を整理する重要な役割を果たしています。

		returnデータ構造サンプル
		(A) $group_by_keys が空の場合
		[
			[
				// 集計対象の条件部分↓（ページ別かつ訪問者の情報種別　※組み合わせで１つの[]）
				'page_id' => 123,
				'device_id' => 1,
				'utm_source' => 'google',
				'utm_medium' => 'cpc',
				'utm_campaign' => 'summer_sale',
				'is_newuser' => 1,
				'second_page' => 1,
				'title' => 'Landing Page Title',
				'url' => 'https://example.com/landing-page',
				'source_domain' => 'google.com',
				'is_QA' => 1, //固定（例えばGoogle計測データとの区別が必要な未来に備えているもの）
				'wp_qa_id' => 0,
				// 集計部分↓
				'pv_count' => 100,
				'session_count' => 50,
				'user_count' => 40,
				'bounce_count' => 10,
				'session_time' => 5000,
			],
			...,
		]
		(2) $group_by_keys = ['utm_source', 'utm_campaign'] の場合
		[
			[
				// 集計対象の条件部分↓（
				'utm_source' => 'google',
				'utm_campaign' => 'summer_sale',
				// 集計部分↓
				'pv_count' => 100,
				'session_count' => 50,
				'user_count' => 40,
				'bounce_count' => 10,
				'session_time' => 5000
			],
			...,
		]
	*/
	public function summary_days_landingpages( $dateterm, $tracking_id = 'all', $group_by_keys = array() ) {
		$tracking_id = $this->get_safe_tracking_id( $tracking_id );

		// ディレクトリ関連の変数
		$data_dir        = $this->get_data_dir_path();
		$view_dir        = $data_dir . 'view/';
		$myview_dir      = $view_dir . $tracking_id . '/';
		$summary_dir     = $myview_dir . 'summary/';
		$sumallday_ary   = array();
		$results_raw_ary = array();

		// データ範囲の正規表現マッチング
		if ( preg_match( '/^date\s*=\s*between\s*([0-9]{4}.[0-9]{2}.[0-9]{2})\s*and\s*([0-9]{4}.[0-9]{2}.[0-9]{2})$/', $dateterm, $datestrs ) ) {
			$s_daystr = $datestrs[1];
			$e_daystr = $datestrs[2];
			//日付が同一であれば、サマリーファイル1つだけ読めばいい
			if ( $s_daystr == $e_daystr ) {
				if ( file_exists( $summary_dir . $s_daystr . '_summary_landingpage.php' ) ) {
					$sumallday_ary = $this->wrap_unserialize( $this->wrap_get_contents( $summary_dir . $s_daystr . '_summary_landingpage.php' ) );
				}
			}
			if ( empty( $sumallday_ary ) ) {
				$sumallday_ary = $this->calc_summary_dateterm_total( $s_daystr, $e_daystr, 'landingpage', $tracking_id );
			}

			foreach ( $sumallday_ary as $idxp => $aryp ) {
				foreach ( $aryp as $idxd => $aryd ) {
					foreach ( $aryd as $idxs => $arys ) {
						foreach ( $arys as $idxm => $arym ) {
							foreach ( $arym as $idxc => $aryc ) {
								foreach ( $aryc as $idxn => $aryn ) {
									foreach ( $aryn as $idx2 => $ary2 ) {
										foreach ( $ary2 as $idxq => $aryq ) {
											if ( isset( $sumallday_ary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idx2 ][ $idxq ]['pv_count'] ) ) {
												if ( $sumallday_ary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idx2 ][ $idxq ]['pv_count'] != 0 ) {
													$results_raw_ary[] = array(
														self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[1] => $idxp,
														self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[2] => $idxd,
														self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[3] => $idxs,
														self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[4] => $idxm,
														self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[5] => $idxc,
														self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[6] => $idxn,
														self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[7] => $idx2,
														self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[8] => $idxq,
														self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[9]  => $sumallday_ary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idx2 ][ $idxq ]['pv_count'],
														self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[10] => $sumallday_ary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idx2 ][ $idxq ]['session_count'],
														self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[11] => $sumallday_ary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idx2 ][ $idxq ]['user_count'],
														self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[12] => $sumallday_ary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idx2 ][ $idxq ]['bounce_count'],
														self::QAHM_VR_SUMMARY_LANDINGPAGE_COL[13] => $sumallday_ary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idx2 ][ $idxq ]['session_time'],
													);
												}
											}
										}
									}
								}
							}
						}
					}
				}
			}
		}

		// 取得するためのIDリストを準備
		$pid_list = array();
		$sid_list = array();
		$mid_list = array();
		$cid_list = array();

		foreach ( $results_raw_ary as $line_ary ) {
			$pid = (int) $line_ary['page_id'];
			$sid = (int) $line_ary['utm_source'];
			$mid = (int) $line_ary['utm_medium'];
			$cid = (int) $line_ary['utm_campaign'];

			if ( ! isset( $pid_ary[ $pid ] ) ) {
				$pid_list[] = $pid;
			}
			if ( ! isset( $sid_ary[ $sid ] ) ) {
				$sid_list[] = $sid;
			}
			if ( ! isset( $mid_ary[ $mid ] ) ) {
				$mid_list[] = $mid;
			}
			if ( ! isset( $cid_ary[ $cid ] ) ) {
				$cid_list[] = $cid;
			}
		}

		// ページ情報を取得
		if ( ! empty( $pid_list ) ) {
			$pid_list              = array_unique( $pid_list );
			$page_ids_placeholders = $this->create_in_placeholders( $pid_list );
			$query                 = 'SELECT page_id, title, url, wp_qa_id FROM ' . $this->prefix . 'qa_pages WHERE page_id IN (' . $page_ids_placeholders . ')';
			$pages                 = $this->get_results( $this->prepare( $query, ...$pid_list ) );
			foreach ( $pages as $page ) {
				$pid_ary[ $page->page_id ] = array( $page->title, $page->url, $page->wp_qa_id );
			}
		}

		// UTMソース情報を取得
		if ( ! empty( $sid_list ) ) {
			$sid_list                = array_unique( $sid_list );
			$source_ids_placeholders = $this->create_in_placeholders( $sid_list );
			$query                   = 'SELECT source_id, utm_source, source_domain FROM ' . $this->prefix . 'qa_utm_sources WHERE source_id IN (' . $source_ids_placeholders . ')';
			$sources                 = $this->get_results( $this->prepare( $query, ...$sid_list ) );
			foreach ( $sources as $source ) {
				$sid_ary[ $source->source_id ] = array( $source->utm_source, $source->source_domain );
			}
		}

		// UTMメディア情報を取得
		if ( ! empty( $mid_list ) ) {
			$mid_list                = array_unique( $mid_list );
			$medium_ids_placeholders = $this->create_in_placeholders( $mid_list );
			$query                   = 'SELECT medium_id, utm_medium FROM ' . $this->prefix . 'qa_utm_media WHERE medium_id IN (' . $medium_ids_placeholders . ')';
			$media                   = $this->get_results( $this->prepare( $query, ...$mid_list ) );
			foreach ( $media as $medium ) {
				$mid_ary[ $medium->medium_id ] = $medium->utm_medium;
			}
		}

		// UTMキャンペーン情報を取得
		if ( ! empty( $cid_list ) ) {
			$cid_list                  = array_unique( $cid_list );
			$campaign_ids_placeholders = $this->create_in_placeholders( $cid_list );
			$query                     = 'SELECT campaign_id, utm_campaign FROM ' . $this->prefix . 'qa_utm_campaigns WHERE campaign_id IN (' . $campaign_ids_placeholders . ')';
			$campaigns                 = $this->get_results( $this->prepare( $query, ...$cid_list ) );
			foreach ( $campaigns as $campaign ) {
				$cid_ary[ $campaign->campaign_id ] = $campaign->utm_campaign;
			}
		}

		// 取得したデータを使用して results_raw_ary を更新
		foreach ( $results_raw_ary as $idx => $line_ary ) {
			$pid = (int) $line_ary['page_id'];
			if ( isset( $pid_ary[ $pid ] ) ) {
				$results_raw_ary[ $idx ]['title']    = $pid_ary[ $pid ][0];
				$results_raw_ary[ $idx ]['url']      = $pid_ary[ $pid ][1];
				$results_raw_ary[ $idx ]['wp_qa_id'] = $pid_ary[ $pid ][2];
			}

			$sid = (int) $line_ary['utm_source'];
			if ( isset( $sid_ary[ $sid ] ) ) {
				$results_raw_ary[ $idx ]['utm_source']    = $sid_ary[ $sid ][0];
				$results_raw_ary[ $idx ]['source_domain'] = $sid_ary[ $sid ][1];
			}

			$mid                                   = (int) $line_ary['utm_medium'];
			$results_raw_ary[ $idx ]['utm_medium'] = $mid_ary[ $mid ] ?? '';

			$cid                                     = (int) $line_ary['utm_campaign'];
			$results_raw_ary[ $idx ]['utm_campaign'] = $cid_ary[ $cid ] ?? '';
		}
		// #1105: 広告トラフィックの source_domain 補完
		$this->compensate_ad_source_domains( $results_raw_ary );

		// 集計結果を results_raw_ary と同じ形式で返す
		if ( ! empty( $group_by_keys ) ) {
			$aggregated_results = array();

			foreach ( $results_raw_ary as $line ) {
				// 集計キーの作成
				$group_key = array();
				foreach ( $group_by_keys as $key ) {
					if ( isset( $line[ $key ] ) ) {
						$group_key[ $key ] = $line[ $key ];
					}
				}

				// キーがない場合はそのまま処理をスキップ
				if ( empty( $group_key ) ) {
					continue;
				}

				$group_key_string = $this->wrap_implode( '|', $group_key );

				// グループ化して集計
				if ( ! isset( $aggregated_results[ $group_key_string ] ) ) {
					$aggregated_results[ $group_key_string ] = $line;
				} else {
					// 数値の集計
					$aggregated_results[ $group_key_string ]['pv_count']      += $line['pv_count'];
					$aggregated_results[ $group_key_string ]['session_count'] += $line['session_count'];
					$aggregated_results[ $group_key_string ]['user_count']    += $line['user_count'];
					$aggregated_results[ $group_key_string ]['bounce_count']  += $line['bounce_count'];
					$aggregated_results[ $group_key_string ]['session_time']  += $line['session_time'];
				}
			}

			return array_values( $aggregated_results );  // 配列のインデックスをリセットして返す
		}

		// ディメンションが指定されていない場合はそのまま返す
		return $results_raw_ary;
	}

	//20240816 koji maruyama for speedup
	function summary_days_growthpages( $dateterm, $tracking_id ) {
		global $qahm_db;
		// #1153: per-tid 集計なので時計も同じ tracking_id で（'all' は WP-TZ 短絡）。
		$clock = QAHM_Time::get_site_clock( $tracking_id );
		$SUM_TERM        = 7;
		$results_raw_ary = array();
		$page_id_keyary  = array();  // ワーク配列：処理済みのpage_idを格納

		// まず最初の7日間と最後の7日間の日付範囲を計算する
		if ( preg_match( '/^date\s*=\s*between\s*([0-9]{4}.[0-9]{2}.[0-9]{2})\s*and\s*([0-9]{4}.[0-9]{2}.[0-9]{2})$/', $dateterm, $datestrs ) ) {
			$s_daystr   = $datestrs[1];
			$e_daystr   = $datestrs[2];
			$s_unixtime = $clock->str_to_unixtime( $s_daystr . ' 00:00:00' );
			$e_unixtime = $clock->str_to_unixtime( $e_daystr . ' 23:59:59' );

			// 期間が14日未満かどうかを確認
			$days_difference = ( $e_unixtime - $s_unixtime ) / ( 60 * 60 * 24 );

			if ( $days_difference < 14 ) {
				// 14日未満の場合は、最初の1日と最後の1日を使用する
				$first_day_dateterm = "date = between $s_daystr and $s_daystr";
				$last_day_dateterm  = "date = between $e_daystr and $e_daystr";

				$lp_sum_ary_stt = $qahm_db->summary_days_landingpages( $first_day_dateterm, $tracking_id );
				$lp_sum_ary_end = $qahm_db->summary_days_landingpages( $last_day_dateterm, $tracking_id );
			} else {
				// 最初の7日間と最後の7日間を使用する
				$mid_unixtime = $s_unixtime + ( $SUM_TERM * 24 * 60 * 60 );
				$mid_daystr   = gmdate( 'Y-m-d', $mid_unixtime );

				$first_week_dateterm = "date = between $s_daystr and $mid_daystr";
				$last_week_dateterm  = 'date = between ' . gmdate( 'Y-m-d', $e_unixtime - ( $SUM_TERM * 24 * 60 * 60 ) ) . " and $e_daystr";

				$lp_sum_ary_stt = $qahm_db->summary_days_landingpages( $first_week_dateterm, $tracking_id );
				$lp_sum_ary_end = $qahm_db->summary_days_landingpages( $last_week_dateterm, $tracking_id );
			}

			// $lp_sum_ary_stt を先に処理し、ページIDとutm_mediumをキーにしてセッション数を格納
			foreach ( $lp_sum_ary_stt as $lp_data_stt ) {
				$page_id    = $lp_data_stt['page_id'];
				$utm_medium = $lp_data_stt['utm_medium'];
				// #964/#1076/#1508: メディア補完＝共通規則（QAHM_Base::derive_medium_for_empty_utm）に一元化。
				// own_domain は従来この経路に判定が無かったため null 渡し＝空 source_domain（''→(none) に統一）を除き挙動不変。
				if ( empty( $utm_medium ) ) {
					$source_domain = isset( $lp_data_stt['source_domain'] ) ? $lp_data_stt['source_domain'] : 'direct';
					$utm_medium    = QAHM_Base::derive_medium_for_empty_utm( $source_domain, null );
				}

				// 既に同じpage_idとutm_mediumが処理済みの場合は、セッション数を合計
				if ( isset( $page_id_keyary[ $page_id ][ $utm_medium ] ) ) {
					$page_id_keyary[ $page_id ][ $utm_medium ]['start_session_count'] += $lp_data_stt['session_count'] ?? 0;
				} else {
					// 初回の処理の場合
					$page_id_keyary[ $page_id ][ $utm_medium ] = array(
						'page_id'             => $page_id,
						'title'               => $lp_data_stt['title'],
						'url'                 => $lp_data_stt['url'],
						'wp_qa_id'            => $lp_data_stt['wp_qa_id'],
						'utm_medium'          => $utm_medium,
						'start_session_count' => $lp_data_stt['session_count'] ?? 0,
						'end_session_count'   => 0, // 後で合計
					);
				}
			}

			// $lp_sum_ary_end を処理し、page_idとutm_mediumが一致する場合はセッション数を合計
			foreach ( $lp_sum_ary_end as $lp_data_end ) {
				$page_id    = $lp_data_end['page_id'];
				$utm_medium = $lp_data_end['utm_medium'];
				// #964/#1076/#1508: メディア補完＝共通規則（QAHM_Base::derive_medium_for_empty_utm）に一元化。
				// own_domain は従来この経路に判定が無かったため null 渡し＝空 source_domain（''→(none) に統一）を除き挙動不変。
				if ( empty( $utm_medium ) ) {
					$source_domain = isset( $lp_data_end['source_domain'] ) ? $lp_data_end['source_domain'] : 'direct';
					$utm_medium    = QAHM_Base::derive_medium_for_empty_utm( $source_domain, null );
				}

				// 既に同じpage_idとutm_mediumが存在する場合は、セッション数を合計
				if ( isset( $page_id_keyary[ $page_id ][ $utm_medium ] ) ) {
					$page_id_keyary[ $page_id ][ $utm_medium ]['end_session_count'] += $lp_data_end['session_count'] ?? 0;
				} else {
					// 新規のデータの場合は追加
					$page_id_keyary[ $page_id ][ $utm_medium ] = array(
						'page_id'             => $page_id,
						'title'               => $lp_data_end['title'],
						'url'                 => $lp_data_end['url'],
						'wp_qa_id'            => $lp_data_end['wp_qa_id'],
						'utm_medium'          => $utm_medium,
						'start_session_count' => 0, // $lp_sum_ary_stt に存在しないため 0 を設定
						'end_session_count'   => $lp_data_end['session_count'] ?? 0,
					);
				}
			}

			// 最終的な結果をresults_raw_aryに追加
			foreach ( $page_id_keyary as $utm_data ) {
				foreach ( $utm_data as $data ) {
					$results_raw_ary[] = $data;
				}
			}
			// end_session_countが大きい順に並び替え
			usort(
				$results_raw_ary,
				function ( $a, $b ) {
					return $b['end_session_count'] <=> $a['end_session_count'];
				}
			);
		}

		return $results_raw_ary;
	}

	public function summary_days_allpages( $dateterm, $tracking_id, $group_by_keys = array() ) {
		$tracking_id = $this->get_safe_tracking_id( $tracking_id );

		// ディレクトリ関連の変数
		$data_dir        = $this->get_data_dir_path();
		$view_dir        = $data_dir . 'view/';
		$myview_dir      = $view_dir . $tracking_id . '/';
		$summary_dir     = $myview_dir . 'summary/';
		$sumallday_ary   = array();
		$results_raw_ary = array();

		// データ範囲の正規表現マッチング
		if ( preg_match( '/^date\s*=\s*between\s*([0-9]{4}.[0-9]{2}.[0-9]{2})\s*and\s*([0-9]{4}.[0-9]{2}.[0-9]{2})$/', $dateterm, $datestrs ) ) {
			$s_daystr = $datestrs[1];
			$e_daystr = $datestrs[2];
			//日付が同一であれば、サマリーファイル1つだけ読めばいい
			if ( $s_daystr == $e_daystr ) {
				if ( file_exists( $summary_dir . $s_daystr . '_summary_allpage.php' ) ) {
					$sumallday_ary = $this->wrap_unserialize( $this->wrap_get_contents( $summary_dir . $s_daystr . '_summary_allpage.php' ) );
				}
			}
			if ( empty( $sumallday_ary ) ) {
				$sumallday_ary = $this->calc_summary_dateterm_total( $s_daystr, $e_daystr, 'allpage', $tracking_id );
			}

			foreach ( $sumallday_ary as $idxp => $aryp ) {
				foreach ( $aryp as $idxd => $aryd ) {
					foreach ( $aryd as $idxs => $arys ) {
						foreach ( $arys as $idxm => $arym ) {
							foreach ( $arym as $idxc => $aryc ) {
								foreach ( $aryc as $idxn => $aryn ) {
									foreach ( $aryn as $idxq => $aryq ) {
										if ( isset( $sumallday_ary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['pv_count'] ) ) {
											if ( $sumallday_ary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['pv_count'] != 0 ) {
												$results_raw_ary[] = array(
													self::QAHM_VR_SUMMARY_ALLPAGE_COL[1] => $idxp,
													self::QAHM_VR_SUMMARY_ALLPAGE_COL[2] => $idxd,
													self::QAHM_VR_SUMMARY_ALLPAGE_COL[3] => $idxs,
													self::QAHM_VR_SUMMARY_ALLPAGE_COL[4] => $idxm,
													self::QAHM_VR_SUMMARY_ALLPAGE_COL[5] => $idxc,
													self::QAHM_VR_SUMMARY_ALLPAGE_COL[6] => $idxn,
													self::QAHM_VR_SUMMARY_ALLPAGE_COL[7] => $idxq,
													self::QAHM_VR_SUMMARY_ALLPAGE_COL[8] => $sumallday_ary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['pv_count'],
													self::QAHM_VR_SUMMARY_ALLPAGE_COL[9] => $sumallday_ary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['user_count'],
													self::QAHM_VR_SUMMARY_ALLPAGE_COL[10] => $sumallday_ary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['bounce_count'],
													self::QAHM_VR_SUMMARY_ALLPAGE_COL[11] => $sumallday_ary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['exit_count'],
													self::QAHM_VR_SUMMARY_ALLPAGE_COL[12] => $sumallday_ary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['time_on_page'],
													self::QAHM_VR_SUMMARY_ALLPAGE_COL[13] => $sumallday_ary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['lp_count'],
												);
											}
										}
									}
								}
							}
						}
					}
				}
			}
		}

		// 取得するためのIDリストを準備
		$pid_list = array();
		$sid_list = array();
		$mid_list = array();
		$cid_list = array();

		foreach ( $results_raw_ary as $line_ary ) {
			$pid = (int) $line_ary['page_id'];
			$sid = (int) $line_ary['utm_source'];
			$mid = (int) $line_ary['utm_medium'];
			$cid = (int) $line_ary['utm_campaign'];

			if ( ! isset( $pid_ary[ $pid ] ) ) {
				$pid_list[] = $pid;
			}
			if ( ! isset( $sid_ary[ $sid ] ) ) {
				$sid_list[] = $sid;
			}
			if ( ! isset( $mid_ary[ $mid ] ) ) {
				$mid_list[] = $mid;
			}
			if ( ! isset( $cid_ary[ $cid ] ) ) {
				$cid_list[] = $cid;
			}
		}

		// ページ情報を取得
		if ( ! empty( $pid_list ) ) {
			$pid_list              = array_unique( $pid_list );
			$page_ids_placeholders = $this->create_in_placeholders( $pid_list );
			$query                 = 'SELECT page_id, title, url, wp_qa_id FROM ' . $this->prefix . 'qa_pages WHERE page_id IN (' . $page_ids_placeholders . ')';
			$pages                 = $this->get_results( $this->prepare( $query, ...$pid_list ) );
			foreach ( $pages as $page ) {
				$pid_ary[ $page->page_id ] = array( $page->title, $page->url, $page->wp_qa_id );
			}
		}

		// UTMソース情報を取得
		if ( ! empty( $sid_list ) ) {
			$sid_list                = array_unique( $sid_list );
			$source_ids_placeholders = $this->create_in_placeholders( $sid_list );
			$query                   = 'SELECT source_id, utm_source, source_domain FROM ' . $this->prefix . 'qa_utm_sources WHERE source_id IN (' . $source_ids_placeholders . ')';
			$sources                 = $this->get_results( $this->prepare( $query, ...$sid_list ) );
			foreach ( $sources as $source ) {
				$sid_ary[ $source->source_id ] = array( $source->utm_source, $source->source_domain );
			}
		}

		// UTMメディア情報を取得
		if ( ! empty( $mid_list ) ) {
			$mid_list                = array_unique( $mid_list );
			$medium_ids_placeholders = $this->create_in_placeholders( $mid_list );
			$query                   = 'SELECT medium_id, utm_medium FROM ' . $this->prefix . 'qa_utm_media WHERE medium_id IN (' . $medium_ids_placeholders . ')';
			$media                   = $this->get_results( $this->prepare( $query, ...$mid_list ) );
			foreach ( $media as $medium ) {
				$mid_ary[ $medium->medium_id ] = $medium->utm_medium;
			}
		}

		// UTMキャンペーン情報を取得
		if ( ! empty( $cid_list ) ) {
			$cid_list                  = array_unique( $cid_list );
			$campaign_ids_placeholders = $this->create_in_placeholders( $cid_list );
			$query                     = 'SELECT campaign_id, utm_campaign FROM ' . $this->prefix . 'qa_utm_campaigns WHERE campaign_id IN (' . $campaign_ids_placeholders . ')';
			$campaigns                 = $this->get_results( $this->prepare( $query, ...$cid_list ) );
			foreach ( $campaigns as $campaign ) {
				$cid_ary[ $campaign->campaign_id ] = $campaign->utm_campaign;
			}
		}

		// 取得したデータを使用して results_raw_ary を更新
		foreach ( $results_raw_ary as $idx => $line_ary ) {
			$pid = (int) $line_ary['page_id'];
			if ( isset( $pid_ary[ $pid ] ) ) {
				$results_raw_ary[ $idx ]['title']    = $pid_ary[ $pid ][0];
				$results_raw_ary[ $idx ]['url']      = $pid_ary[ $pid ][1];
				$results_raw_ary[ $idx ]['wp_qa_id'] = $pid_ary[ $pid ][2];
			}

			$sid = (int) $line_ary['utm_source'];
			if ( isset( $sid_ary[ $sid ] ) ) {
				$results_raw_ary[ $idx ]['utm_source']    = $sid_ary[ $sid ][0];
				$results_raw_ary[ $idx ]['source_domain'] = $sid_ary[ $sid ][1];
			}

			$mid                                   = (int) $line_ary['utm_medium'];
			$results_raw_ary[ $idx ]['utm_medium'] = $mid_ary[ $mid ] ?? '';

			$cid                                     = (int) $line_ary['utm_campaign'];
			$results_raw_ary[ $idx ]['utm_campaign'] = $cid_ary[ $cid ] ?? '';
		}
		// #1105: 広告トラフィックの source_domain 補完
		$this->compensate_ad_source_domains( $results_raw_ary );

		// 集計結果を results_raw_ary と同じ形式で返す
		if ( ! empty( $group_by_keys ) ) {
			$aggregated_results = array();

			foreach ( $results_raw_ary as $line ) {
				// 集計キーの作成
				$group_key = array();
				foreach ( $group_by_keys as $key ) {
					if ( isset( $line[ $key ] ) ) {
						$group_key[ $key ] = $line[ $key ];
					}
				}

				// キーがない場合はそのまま処理をスキップ
				if ( empty( $group_key ) ) {
					continue;
				}

				$group_key_string = $this->wrap_implode( '|', $group_key );

				// グループ化して集計
				if ( ! isset( $aggregated_results[ $group_key_string ] ) ) {
					$aggregated_results[ $group_key_string ] = $line;
				} else {
					// 数値の集計
					$aggregated_results[ $group_key_string ]['pv_count']     += $line['pv_count'];
					$aggregated_results[ $group_key_string ]['user_count']   += $line['user_count'];
					$aggregated_results[ $group_key_string ]['bounce_count'] += $line['bounce_count'];
					$aggregated_results[ $group_key_string ]['exit_count']   += $line['exit_count'];
					$aggregated_results[ $group_key_string ]['time_on_page'] += $line['time_on_page'];
					$aggregated_results[ $group_key_string ]['lp_count']     += $line['lp_count'];
				}
			}

			return array_values( $aggregated_results );  // 配列のインデックスをリセットして返す
		}

		// ディメンションが指定されていない場合はそのまま返す
		return $results_raw_ary;
	}



	// 20240815 koji maruyama for speedup
	public function summary_days_access_detail( $dateterm, $tracking_id ) {
		// dir
		$data_dir        = $this->get_data_dir_path();
		$view_dir        = $data_dir . 'view/';
		$myview_dir      = $view_dir . $tracking_id . '/';
		$summary_dir     = $myview_dir . 'summary/';
		$sumallday_ary   = array();
		$results_raw_ary = array();

		if ( preg_match( '/^date\s*=\s*between\s*([0-9]{4}.[0-9]{2}.[0-9]{2})\s*and\s*([0-9]{4}.[0-9]{2}.[0-9]{2})$/', $dateterm, $datestrs ) ) {

			$s_daystr = $datestrs[1];
			$e_daystr = $datestrs[2];

			$sumallday_ary = $this->calc_days_access_detail_dateterm_total( $s_daystr, $e_daystr, $tracking_id );

			foreach ( $sumallday_ary as $idxd => $aryd ) {
				foreach ( $aryd as $idxs => $arys ) {
					foreach ( $arys as $idxm => $arym ) {
						foreach ( $arym as $idxc => $aryc ) {
							foreach ( $aryc as $idxn => $aryn ) {
								foreach ( $aryn as $idxq => $aryq ) {

									if ( $sumallday_ary[ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['pv_count'] > 0 ) {

										$results_raw_ary[] = array(
											self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[1]  => $idxd,
											self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[2]  => $idxs,
											self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[3]  => $idxm,
											self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[4]  => $idxc,
											self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[5]  => $idxn,
											self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[6]  => $idxq,
											self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[7]  => $sumallday_ary[ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['pv_count'],
											self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[8]  => $sumallday_ary[ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['session_count'],
											self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[9]  => $sumallday_ary[ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['user_count'],
											self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[10] => $sumallday_ary[ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['bounce_count'],
											self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[11] => $sumallday_ary[ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['time_on_page'],
											'is_newuser_count'                        => $sumallday_ary[ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['is_newuser_count'],
										);

									}
								}
							}
						}
					}
				}
			}
		}

		// リストを一括で取得するための配列
		$sid_list = array();
		$mid_list = array();
		$cid_list = array();

		foreach ( $results_raw_ary as $line_ary ) {
			$sid = (int) $line_ary['utm_source'];
			$mid = (int) $line_ary['utm_medium'];
			$cid = (int) $line_ary['utm_campaign'];

			if ( ! isset( $sid_ary[ $sid ] ) ) {
				$sid_list[] = $sid;
			}
			if ( ! isset( $mid_ary[ $mid ] ) ) {
				$mid_list[] = $mid;
			}
			if ( ! isset( $cid_ary[ $cid ] ) ) {
				$cid_list[] = $cid;
			}
		}

		// UTMソース情報を一括で取得
		if ( ! empty( $sid_list ) ) {
			$sid_list                = array_unique( $sid_list );
			$source_ids_placeholders = $this->create_in_placeholders( $sid_list );

			$query   = 'SELECT source_id, utm_source, source_domain FROM ' . $this->prefix . 'qa_utm_sources WHERE source_id IN (' . $source_ids_placeholders . ')';
			$sources = $this->get_results( $this->prepare( $query, ...$sid_list ) );
			foreach ( $sources as $source ) {
				$sid_ary[ $source->source_id ] = array( $source->utm_source, $source->source_domain );
			}
		}

		// UTMメディア情報を一括で取得
		if ( ! empty( $mid_list ) ) {
			$mid_list                = array_unique( $mid_list );
			$medium_ids_placeholders = $this->create_in_placeholders( $mid_list );
			$query                   = 'SELECT medium_id, utm_medium FROM ' . $this->prefix . 'qa_utm_media WHERE medium_id IN (' . $medium_ids_placeholders . ')';
			$media                   = $this->get_results( $this->prepare( $query, ...$mid_list ) );
			foreach ( $media as $medium ) {
				$mid_ary[ $medium->medium_id ] = $medium->utm_medium;
			}
		}

		// UTMキャンペーン情報を一括で取得
		if ( ! empty( $cid_list ) ) {
			$cid_list                  = array_unique( $cid_list );
			$campaign_ids_placeholders = $this->create_in_placeholders( $cid_list );
			$query                     = 'SELECT campaign_id, utm_campaign FROM ' . $this->prefix . 'qa_utm_campaigns WHERE campaign_id IN (' . $campaign_ids_placeholders . ')';
			$campaigns                 = $this->get_results( $this->prepare( $query, ...$cid_list ) );
			foreach ( $campaigns as $campaign ) {
				$cid_ary[ $campaign->campaign_id ] = $campaign->utm_campaign;
			}
		}

		// 取得したデータを使用してresults_raw_aryを更新
		foreach ( $results_raw_ary as $idx => $line_ary ) {
			$sid = (int) $line_ary['utm_source'];
			if ( isset( $sid_ary[ $sid ] ) ) {
				$results_raw_ary[ $idx ]['utm_source']    = $sid_ary[ $sid ][0];
				$results_raw_ary[ $idx ]['source_domain'] = $sid_ary[ $sid ][1];
			}

			$mid                                   = (int) $line_ary['utm_medium'];
			$results_raw_ary[ $idx ]['utm_medium'] = $mid_ary[ $mid ] ?? '';

			$cid                                     = (int) $line_ary['utm_campaign'];
			$results_raw_ary[ $idx ]['utm_campaign'] = $cid_ary[ $cid ] ?? '';
		}
		// #1105: 広告トラフィックの source_domain 補完
		$this->compensate_ad_source_domains( $results_raw_ary );

		return $results_raw_ary;
	}
	// below is old source
	//  public function summary_days_access_detail ( $dateterm, $tracking_id ) {
	//
	//      // dir
	//      $data_dir = $this->get_data_dir_path();
	//      $view_dir = $data_dir . 'view/';
	//      $myview_dir = $view_dir . $tracking_id . '/';
	//      $summary_dir = $myview_dir . 'summary/';
	//      $sumallday_ary = [];
	//      $results_raw_ary = [];
	//
	//      if ( preg_match('/^date\s*=\s*between\s*([0-9]{4}.[0-9]{2}.[0-9]{2})\s*and\s*([0-9]{4}.[0-9]{2}.[0-9]{2})$/', $dateterm, $datestrs ) ) {
	//
	//          $s_daystr = $datestrs[1];
	//          $e_daystr = $datestrs[2];
	//
	//          $sumallday_ary = $this->calc_days_access_detail_dateterm_total( $s_daystr, $e_daystr, "days_access_detail" );
	//
	//          foreach ( $sumallday_ary as $idxd => $aryd ) {
	//              foreach ( $aryd as $idxs => $arys) {
	//                  foreach ( $arys as $idxm => $arym) {
	//                      foreach ( $arym as $idxc => $aryc) {
	//                          foreach ( $aryc as $idxn => $aryn) {
	//                              foreach ( $aryn as $idxq => $aryq) {
	//
	//                                  if( $sumallday_ary[$idxd][$idxs][$idxm][$idxc][$idxn][$idxq]['pv_count'] > 0){
	//
	//                                      $results_raw_ary[] = array
	//                                          (self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[1]  => $idxd,
	//                                          self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[2]  => $idxs,
	//                                          self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[3]  => $idxm,
	//                                          self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[4]  => $idxc,
	//                                          self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[5]  => $idxn,
	//                                          self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[6]  => $idxq,
	//                                          self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[7]  => $sumallday_ary[$idxd][$idxs][$idxm][$idxc][$idxn][$idxq]['pv_count'],
	//                                          self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[8]  => $sumallday_ary[$idxd][$idxs][$idxm][$idxc][$idxn][$idxq]['session_count'],
	//                                          self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[9]  => $sumallday_ary[$idxd][$idxs][$idxm][$idxc][$idxn][$idxq]['user_count'],
	//                                          self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[10] => $sumallday_ary[$idxd][$idxs][$idxm][$idxc][$idxn][$idxq]['bounce_count'],
	//                                          self::QAHM_SUMMARY_DAYS_ACCESS_DETAIL[11] => $sumallday_ary[$idxd][$idxs][$idxm][$idxc][$idxn][$idxq]['time_on_page'],
	//                                          'is_newuser_count'                        => $sumallday_ary[$idxd][$idxs][$idxm][$idxc][$idxn][$idxq]['is_newuser_count']
	//                                      );
	//
	//                                  }
	//                              }
	//                          }
	//                      }
	//                  }
	//              }
	//          }
	//
	//
	//      }
	//
	//      //配列の要素を文字列変換
	//      $sid_ary = [];
	//      $mid_ary = [];
	//      $cid_ary = [];
	//      foreach ( $results_raw_ary as $idx => $line_ary ) {
	//          $sid = (int)$line_ary['utm_source'];
	//          if ( !isset( $sid_ary[$sid]) ) {
	//              $query = 'select utm_source, source_domain from ' . $this->prefix . 'qa_utm_sources where source_id=%d';
	//              $query = $this->prepare($query, $sid);
	//              $utm_source = $this->get_results( $query );
	//              if ( ! empty( $utm_source ) ) {
	//                  $results_raw_ary[$idx]['utm_source'] = $utm_source[0]->utm_source;
	//                  $results_raw_ary[$idx]['source_domain'] = $utm_source[0]->source_domain;
	//                  $sid_ary[$sid] = array( $utm_source[0]->utm_source, $utm_source[0]->source_domain );
	//              }
	//          } else {
	//              $results_raw_ary[$idx]['utm_source']    = $sid_ary[$sid][0];
	//              $results_raw_ary[$idx]['source_domain'] = $sid_ary[$sid][1];
	//          }
	//          $mid = (int)$line_ary['utm_medium'];
	//          if ( !isset( $mid_ary[$mid]) ) {
	//              $query = 'select utm_medium from ' . $this->prefix . 'qa_utm_media where medium_id=%d';
	//              $query = $this->prepare( $query, $mid );
	//              $utm_medium = $this->get_results( $query );
	//              if ( ! empty( $utm_medium ) ) {
	//                  $results_raw_ary[$idx]['utm_medium'] = $utm_medium[0]->utm_medium;
	//                  $mid_ary[$mid] = $utm_medium[0]->utm_medium;
	//              }else{
	//                  $results_raw_ary[$idx]['utm_medium'] = '';
	//                  $mid_ary[$mid] = '';
	//              }
	//          } else {
	//              $results_raw_ary[$idx]['utm_medium'] = $mid_ary[$mid];
	//          }
	//          $cid = (int)$line_ary['utm_campaign'];
	//          if ( !isset( $cid_ary[$cid]) ) {
	//              $query = 'select utm_campaign from ' . $this->prefix . 'qa_utm_campaigns where campaign_id=%d';
	//              $query = $this->prepare( $query, $cid );
	//              $utm_campaign = $this->get_results( $query );
	//              if ( ! empty( $utm_campaign ) ) {
	//                  $results_raw_ary[$idx]['utm_campaign'] = $utm_campaign[0]->utm_campaign;
	//                  $cid_ary[$cid] = $utm_campaign[0]->utm_campaign;
	//              }
	//          } else {
	//              $results_raw_ary[$idx]['utm_campaign'] = $cid_ary[$cid];
	//          }
	//      }
	//
	//      return $results_raw_ary;
	//
	//  }

	//-----------------------------
	//make summary file
	//
	// QA ZERO START
	/**
	 * ColumnDB（allpv）の列ファイル群を日単位で読み込む（Epic #1256 P2-α #1287）
	 *
	 * 完了マーカー（QAHM_ColumnDB_Manifest）の state=done 確認と、manifest 記録行数・
	 * 列間の行数整合を検査し、1つでも保証が崩れた場合は null を返す
	 * （呼び出し元は従来の view_pv 経路へフォールバックする）。
	 * manifest にエントリが無い日（#1281 導入前の過去月）は、列ファイルの存在と
	 * 列間の行数一致のみで受け入れる（goal 1a と同じ後方互換姿勢）。
	 *
	 * @param string $tracking_id  トラッキングID（'all' 可）
	 * @param string $date_ymd     対象日（YYYYMMDD）
	 * @param array  $column_names 読み込む allpv 列名の配列
	 * @return array|null [ 列名 => 値配列（行順） ]。保証不成立時は null
	 */
	public function load_allpv_columns( $tracking_id, $date_ymd, $column_names ) {
		global $wp_filesystem;

		if ( ! class_exists( 'QAHM_ColumnDB_BinaryIO' ) || ! class_exists( 'QAHM_ColumnDB_Schema' ) || ! class_exists( 'QAHM_ColumnDB_Manifest' ) ) {
			return null;
		}
		if ( empty( $column_names ) ) {
			return null;
		}

		$data_dir  = $this->get_data_dir_path();
		$allpv_dir = $data_dir . 'report/' . $this->get_safe_tracking_id( $tracking_id ) . '/columns-db/allpv/';
		if ( ! $wp_filesystem->exists( $allpv_dir ) ) {
			return null;
		}

		$year_month = $this->wrap_substr( $date_ymd, 0, 6 );

		// Issue #1279 完了マーカー: エントリがある日は state=done を要求する。
		// converting 残置（変換中/中断）の日を summary に焼き込まない。
		$manifest_entry = QAHM_ColumnDB_Manifest::get_day(
			QAHM_ColumnDB_Manifest::load( $allpv_dir, $year_month ),
			$date_ymd
		);
		$expected_rows  = null;
		if ( null !== $manifest_entry ) {
			if ( ! QAHM_ColumnDB_Manifest::is_done_entry( $manifest_entry ) ) {
				return null;
			}
			if ( isset( $manifest_entry['rows'] ) ) {
				$expected_rows = (int) $manifest_entry['rows'];
				if ( $expected_rows <= 0 ) {
					// 行0の日は列ファイル自体が無い（空マーカー）。view_pv 側の実態と突合できないため従来経路へ
					return null;
				}
			}
		}

		$schema   = QAHM_ColumnDB_Schema::SCHEMA_ALLPV;
		$col_base = $allpv_dir . $year_month . '/allpv_' . $date_ymd . '_';
		$result   = array();
		$row_cnt  = null;
		foreach ( $column_names as $col_name ) {
			if ( ! isset( $schema[ $col_name ] ) ) {
				return null;
			}
			$col_path = $col_base . $col_name . '.php';
			if ( ! $wp_filesystem->exists( $col_path ) ) {
				return null;
			}
			if ( QAHM_ColumnDB_Schema::is_widened_column( 'allpv', $col_name ) ) {
				// T113 (#1477): 拡幅12列は旧幅（uint16/uint8）ファイルが実在しうる
				// ＝実ファイルの幅を pv_id 行数から自己判定して読む（done ゲート通過後のみ到達）
				$values = QAHM_ColumnDB_BinaryIO::read_column_auto( $col_path, $col_base . 'pv_id.php' );
			} else {
				switch ( $schema[ $col_name ]['type'] ) {
					case QAHM_ColumnDB_Schema::TYPE_UINT64:
						$values = QAHM_ColumnDB_BinaryIO::read_uint64_array( $col_path );
						break;
					case QAHM_ColumnDB_Schema::TYPE_UINT32:
						$values = QAHM_ColumnDB_BinaryIO::read_uint32_array( $col_path );
						break;
					case QAHM_ColumnDB_Schema::TYPE_UINT16:
						$values = QAHM_ColumnDB_BinaryIO::read_uint16_array( $col_path );
						break;
					case QAHM_ColumnDB_Schema::TYPE_UINT8:
						$values = QAHM_ColumnDB_BinaryIO::read_uint8_array( $col_path );
						break;
					default:
						return null;
				}
			}
			if ( false === $values || ! is_array( $values ) ) {
				return null;
			}
			$cnt = $this->wrap_count( $values );
			if ( null === $row_cnt ) {
				$row_cnt = $cnt;
				if ( null !== $expected_rows && $expected_rows !== $cnt ) {
					// manifest 記録行数と実列長の不一致 = 部分欠損や書き込み後のドリフト
					return null;
				}
			} elseif ( $cnt !== $row_cnt ) {
				// 列間の行数不一致 = 変換途中（flush 途中）等の不完全データ
				return null;
			}
			$result[ $col_name ] = $values;
		}

		if ( 0 === $row_cnt ) {
			// 全列 0 行（0 バイトファイル等の異常系）。正常な行0日は列ファイル自体が無い
			return null;
		}

		return $result;
	}

	/**
	 * days_access_detail の1日分集計（sdad）を ColumnDB（allpv）から構築する（Epic #1256 P2-α #1287）
	 *
	 * make_summary_days_access_detail のループ内集計（view_pv 行ベース）と同一の意味論。
	 * user_count は重複除去前の reader_id 配列のまま返し、呼び出し元の既存 unique 処理を共用する
	 * （serialize 出力の byte 等価を担保するため、キー順・型も既存集計に合わせている）。
	 *
	 * @param string $tracking_id   トラッキングID（'all' 可）
	 * @param string $f_date        対象日（Y-m-d）
	 * @param int    $expected_rows 期待行数（処理中の view_pv 日次ファイルの行数）
	 * @return array|null sdad 配列。ColumnDB から構築できない場合は null
	 */
	private function make_sdad_from_allpv( $tracking_id, $f_date, $expected_rows ) {
		$cols = $this->load_allpv_columns(
			$tracking_id,
			str_replace( '-', '', $f_date ),
			array( 'pv_id', 'device_id', 'source_id', 'medium_id', 'campaign_id', 'is_newuser', 'reader_id', 'browse_sec', 'is_last', 'pv' )
		);
		if ( null === $cols ) {
			return null;
		}

		// view_pv は夜間チェーン内で qa_pv_log と行数突合・再構築される（自己修復）。
		// done 済み allpv は再変換されないため、view_pv 側と行数が食い違う日（ドリフト日）に
		// 凍結スナップショットで上書きしないよう、行数一致を allpv 採用の条件にする。
		if ( $this->wrap_count( $cols['pv'] ) !== (int) $expected_rows ) {
			return null;
		}

		// allpv の行順は変換器内部のセッション採番順（reader グループ順）で、view_pv 日次ファイルの
		// pv_id 昇順（pk 順）と一致しない。sdad はネストキーの挿入順が serialize に出るため、
		// pv_id 昇順に並べ替えて従来集計と同一の行順を再現する。
		$pvid_order = array_map( 'intval', $cols['pv_id'] );
		asort( $pvid_order, SORT_NUMERIC );

		$sdad_ary = array();
		$is_QA    = 1;
		foreach ( array_keys( $pvid_order ) as $row ) {
			$device_id   = (int) $cols['device_id'][ $row ];
			$source_id   = (int) $cols['source_id'][ $row ];
			$medium_id   = (int) $cols['medium_id'][ $row ];
			$campaign_id = (int) $cols['campaign_id'][ $row ];
			$is_newuser  = (int) $cols['is_newuser'][ $row ];
			$reader_id   = (int) $cols['reader_id'][ $row ];
			$browse_sec  = (int) $cols['browse_sec'][ $row ];

			// 当日の1ページ目（LP着地）= セッション。LP かつ最終 PV = 直帰
			$is_landingpage = ( 1 === (int) $cols['pv'][ $row ] ) ? 1 : 0;
			$bounce_count   = ( $is_landingpage && (int) $cols['is_last'][ $row ] ) ? 1 : 0;

			if ( isset( $sdad_ary[ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ] ) ) {
				$sdad_ary[ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ]['pv_count']      += 1;
				$sdad_ary[ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ]['session_count'] += $is_landingpage;
				$sdad_ary[ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ]['user_count'][]   = $reader_id;
				$sdad_ary[ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ]['bounce_count']  += $bounce_count;
				$sdad_ary[ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ]['time_on_page']  += $browse_sec;
			} else {
				$sdad_ary[ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ] = array(
					'pv_count'      => 1,
					'session_count' => $is_landingpage,
					'user_count'    => array( $reader_id ),
					'bounce_count'  => $bounce_count,
					'time_on_page'  => $browse_sec,
				);
			}
		}

		return $sdad_ary;
	}

	/**
	 * summary_allpage（sap）/ summary_landingpage（sl）の1日分集計を ColumnDB（allpv）から構築する（Epic #1256 P2-β1 #1313）
	 *
	 * make_summary_days_access_detail のループ内集計（view_pv 行ベース）と同一の意味論。
	 * user_count は重複除去前の reader_id 配列のまま返し、呼び出し元の既存 unique 処理を共用する
	 * （serialize 出力の byte 等価を担保するため、キー順・型も既存集計に合わせている）。
	 *
	 * sl の second_page・session_time は「物理的に次の行（pv_id 昇順の次行）」を辿る現行挙動を再現する。
	 * allpv の next_page_id 列はセッション境界を尊重した値で現行と食い違うため使用しない。
	 *
	 * @param string $tracking_id   トラッキングID（'all' 可）
	 * @param string $f_date        対象日（Y-m-d）
	 * @param int    $expected_rows 期待行数（処理中の view_pv 日次ファイルの行数）
	 * @return array|null array( 'sap' => sap_ary, 'sl' => sl_ary )。構築できない場合は null
	 */
	private function make_sap_sl_from_allpv( $tracking_id, $f_date, $expected_rows ) {
		$cols = $this->load_allpv_columns(
			$tracking_id,
			str_replace( '-', '', $f_date ),
			array( 'pv_id', 'page_id', 'device_id', 'source_id', 'medium_id', 'campaign_id', 'is_newuser', 'reader_id', 'browse_sec', 'is_last', 'pv' )
		);
		if ( null === $cols ) {
			return null;
		}

		// sdad と同じドリフトガード: view_pv と行数が食い違う日（ドリフト日）は従来経路へ
		if ( $this->wrap_count( $cols['pv'] ) !== (int) $expected_rows ) {
			return null;
		}

		// allpv の行順は変換器内部のセッション採番順。view_pv 日次ファイルの pv_id 昇順（pk 順）に
		// 並べ替えて、従来集計と同一の行順・前方走査（物理次行）を再現する。
		$pvid_order = array_map( 'intval', $cols['pv_id'] );
		asort( $pvid_order, SORT_NUMERIC );
		$sorted_rows = array_keys( $pvid_order );
		$row_max     = $this->wrap_count( $sorted_rows );

		$sap_ary = array();
		$sl_ary  = array();
		$is_QA   = 1;

		for ( $pos = 0; $pos < $row_max; $pos++ ) {
			$row = $sorted_rows[ $pos ];

			$page_id     = (int) $cols['page_id'][ $row ];
			$device_id   = (int) $cols['device_id'][ $row ];
			$source_id   = (int) $cols['source_id'][ $row ];
			$medium_id   = (int) $cols['medium_id'][ $row ];
			$campaign_id = (int) $cols['campaign_id'][ $row ];
			$is_newuser  = (int) $cols['is_newuser'][ $row ];
			$reader_id   = (int) $cols['reader_id'][ $row ];
			$browse_sec  = (int) $cols['browse_sec'][ $row ];
			$is_last     = (int) $cols['is_last'][ $row ];

			$is_landingpage = ( 1 === (int) $cols['pv'][ $row ] ) ? 1 : 0;
			$is_bounce      = ( $is_landingpage && $is_last ) ? 1 : 0;

			// ---- sap（全 PV を page_id 単位で集計） ----
			$tmp_sap_ary = array(
				'pv_count'     => 1,
				'user_count'   => array( $reader_id ),
				'bounce_count' => $is_bounce,
				'exit_count'   => $is_last ? 1 : 0,
				'time_on_page' => $browse_sec,
				'lp_count'     => $is_landingpage,
			);
			if ( isset( $sap_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ] ) ) {
				$sap_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ]['pv_count']     += $tmp_sap_ary['pv_count'];
				$sap_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ]['user_count'][]  = $tmp_sap_ary['user_count'][0];
				$sap_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ]['bounce_count'] += $tmp_sap_ary['bounce_count'];
				$sap_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ]['exit_count']   += $tmp_sap_ary['exit_count'];
				$sap_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ]['time_on_page'] += $tmp_sap_ary['time_on_page'];
				$sap_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ]['lp_count']     += $tmp_sap_ary['lp_count'];
			} else {
				$sap_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ] = $tmp_sap_ary;
			}

			// ---- sl（LP 着地のみ。second_page・session_time は物理次行を辿る現行挙動） ----
			if ( $is_landingpage ) {
				$second_page = 0;
				$tmp_sl_ary  = array(
					'pv_count'      => 1,
					'session_count' => 1,
					'user_count'    => array( $reader_id ),
					'bounce_count'  => $is_bounce,
					'session_time'  => $browse_sec,
				);
				// 直帰でない LP は、物理次行から is_last までを同一セッションとして session_time/pv_count を集計
				if ( ! $is_last && $pos < $row_max - 1 ) {
					$second_page = (int) $cols['page_id'][ $sorted_rows[ $pos + 1 ] ];
					for ( $kkk = $pos + 1; $kkk < $row_max; $kkk++ ) {
						$krow                        = $sorted_rows[ $kkk ];
						$tmp_sl_ary['session_time'] += (int) $cols['browse_sec'][ $krow ];
						++$tmp_sl_ary['pv_count'];
						if ( (int) $cols['is_last'][ $krow ] ) {
							break;
						}
					}
				}
				if ( isset( $sl_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $second_page ][ $is_QA ] ) ) {
					$sl_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $second_page ][ $is_QA ]['pv_count']      += $tmp_sl_ary['pv_count'];
					$sl_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $second_page ][ $is_QA ]['session_count'] += $tmp_sl_ary['session_count'];
					$sl_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $second_page ][ $is_QA ]['user_count'][]   = $tmp_sl_ary['user_count'][0];
					$sl_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $second_page ][ $is_QA ]['bounce_count']  += $tmp_sl_ary['bounce_count'];
					$sl_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $second_page ][ $is_QA ]['session_time']  += $tmp_sl_ary['session_time'];
				} else {
					$sl_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $second_page ][ $is_QA ] = $tmp_sl_ary;
				}
			}
		}

		return array(
			'sap' => $sap_ary,
			'sl'  => $sl_ary,
		);
	}

	/**
	 * click_event の1日分の指定カラムを ColumnDB から読み出す（Epic #1256 P2-β2 #1316）
	 *
	 * load_allpv_columns と同型。click_event 列DB（report/{tid}/columns-db/click_event/）から
	 * 指定カラムを読み、完了マーカー（state=done）・manifest 行数と実列長の一致・列間の行数一致を
	 * すべて満たした場合のみ配列を返す。1つでも崩れたら null（呼び出し側は従来 raw_c 経路へ）。
	 *
	 * click_event の行数は「クリックイベント数」で view_pv 行数とは無関係なため、view_pv 行数との
	 * 突合は行わない（呼び出し側 make_se_from_clickevent も同様）。
	 *
	 * @param string $tracking_id  トラッキングID（'all' 可）
	 * @param string $date_ymd     対象日（YYYYMMDD）
	 * @param array  $column_names 取得するカラム名の配列
	 * @return array|null カラム名 => 値配列。読めない/保証が崩れる場合は null
	 */
	public function load_clickevent_columns( $tracking_id, $date_ymd, $column_names ) {
		global $wp_filesystem;

		if ( ! class_exists( 'QAHM_ColumnDB_BinaryIO' ) || ! class_exists( 'QAHM_ColumnDB_Schema' ) || ! class_exists( 'QAHM_ColumnDB_Manifest' ) ) {
			return null;
		}
		if ( empty( $column_names ) ) {
			return null;
		}

		$data_dir  = $this->get_data_dir_path();
		$click_dir = $data_dir . 'report/' . $this->get_safe_tracking_id( $tracking_id ) . '/columns-db/click_event/';
		if ( ! $wp_filesystem->exists( $click_dir ) ) {
			return null;
		}

		$year_month = $this->wrap_substr( $date_ymd, 0, 6 );

		// Issue #1279 完了マーカー: エントリがある日は state=done を要求する。
		// converting 残置（変換中/中断）の日を summary に焼き込まない。
		$manifest_entry = QAHM_ColumnDB_Manifest::get_day(
			QAHM_ColumnDB_Manifest::load( $click_dir, $year_month ),
			$date_ymd
		);
		$expected_rows  = null;
		if ( null !== $manifest_entry ) {
			if ( ! QAHM_ColumnDB_Manifest::is_done_entry( $manifest_entry ) ) {
				return null;
			}
			if ( isset( $manifest_entry['rows'] ) ) {
				$expected_rows = (int) $manifest_entry['rows'];
				if ( $expected_rows <= 0 ) {
					// クリック0件の日は列ファイルが無い（空マーカー）。従来 raw_c 経路でも空 se になるため null
					return null;
				}
			}
		}

		$schema   = QAHM_ColumnDB_Schema::SCHEMA_CLICK_EVENT;
		$col_base = $click_dir . $year_month . '/click_event_' . $date_ymd . '_';
		$result   = array();
		$row_cnt  = null;
		foreach ( $column_names as $col_name ) {
			if ( ! isset( $schema[ $col_name ] ) ) {
				return null;
			}
			$col_path = $col_base . $col_name . '.php';
			if ( ! $wp_filesystem->exists( $col_path ) ) {
				return null;
			}
			if ( QAHM_ColumnDB_Schema::is_widened_column( 'click_event', $col_name ) ) {
				// T113 (#1477): 拡幅12列は旧幅（uint16）ファイルが実在しうる
				// ＝実ファイルの幅を pv_id 行数から自己判定して読む（done ゲート通過後のみ到達）
				$values = QAHM_ColumnDB_BinaryIO::read_column_auto( $col_path, $col_base . 'pv_id.php' );
			} else {
				switch ( $schema[ $col_name ]['type'] ) {
					case QAHM_ColumnDB_Schema::TYPE_UINT64:
						$values = QAHM_ColumnDB_BinaryIO::read_uint64_array( $col_path );
						break;
					case QAHM_ColumnDB_Schema::TYPE_UINT32:
						$values = QAHM_ColumnDB_BinaryIO::read_uint32_array( $col_path );
						break;
					case QAHM_ColumnDB_Schema::TYPE_UINT16:
						$values = QAHM_ColumnDB_BinaryIO::read_uint16_array( $col_path );
						break;
					case QAHM_ColumnDB_Schema::TYPE_UINT8:
						$values = QAHM_ColumnDB_BinaryIO::read_uint8_array( $col_path );
						break;
					default:
						return null;
				}
			}
			if ( false === $values || ! is_array( $values ) ) {
				return null;
			}
			$cnt = $this->wrap_count( $values );
			if ( null === $row_cnt ) {
				$row_cnt = $cnt;
				if ( null !== $expected_rows && $expected_rows !== $cnt ) {
					// manifest 記録行数と実列長の不一致 = 部分欠損や書き込み後のドリフト
					return null;
				}
			} elseif ( $cnt !== $row_cnt ) {
				// 列間の行数不一致 = 変換途中（flush 途中）等の不完全データ
				return null;
			}
			$result[ $col_name ] = $values;
		}

		if ( 0 === $row_cnt ) {
			// 全列 0 行（0 バイトファイル等の異常系）。従来 raw_c 経路でも空 se になるため null
			return null;
		}

		return $result;
	}

	/**
	 * summary_event（se）の1日分を ColumnDB（click_event + allpv）から構築する（Epic #1256 P2-β2 #1316）
	 *
	 * 【背景＝T68 故障の修復】現行 se は raw_c の field[0]（T68 以降 'gXX' 形式）を (int) 変換しており、
	 * 'g210' → 0 のようにセレクタIDが潰れ、dedup の畳み込み・version 先頭セレクタ（base_selector[0]）への
	 * 化けで「実クリック要素でなく version 先頭セレクタ」を出し続けていた。本メソッドは click_event 列DB
	 * （selector_id / to_url_id を統合辞書で逆引き）から史実どおりのセレクタ・遷移を復元する。
	 *
	 * - selector: selector_id を QAHM_ColumnDB_Selectors（'all' は click_event 配下の統合辞書、個別 tid は
	 *   view/{tid}/global-selectors-dict）で逆引き。version_hist base_selector の index ではない（Devin B）。
	 * - cv_type / url: to_url_id を dict-urls で逆引きし、'p','t','i','o' は cv_type、それ以外は url、
	 *   to_url_id=0 は遷移なし＝cv_type='c'/url=null（現行 raw_c の TRANSITION 分岐と同一意味）。
	 * - version_id: click_event に無いため allpv（pv_id→version_id）で join。join 失敗は version_id=0 で含める。
	 * - dedup: 正しい selector + cv_type で区別（現行の sel_num=0 畳み込みを廃止＝修復）。同一 pv が同一
	 *   イベントを複数回クリックしても pv_id は1回だけ（現行のページ内 dedup と同一）。読者 data-api の
	 *   gtype_event フィルタは event 順・要素数に非依存のため、順序の正規化は行わない。
	 *
	 * フォールバック: click_event・allpv のいずれかが done でない/行数不整合の日は null を返し、呼び出し側は
	 * 従来 raw_c 経路を使う（誤爆方向は常に旧動作）。allpv は sap/sl と同じく view_pv 行数一致も条件にする
	 * （ドリフト日は当日の summary を一括して従来経路へ寄せる）。
	 *
	 * @param string $tracking_id   トラッキングID（'all' 可）
	 * @param string $f_date        対象日（Y-m-d）
	 * @param int    $expected_rows 期待行数（処理中の view_pv 日次ファイルの行数。allpv join 元の鮮度ガード）
	 * @return array|null se 配列。構築できない場合は null
	 */
	private function make_se_from_clickevent( $tracking_id, $f_date, $expected_rows ) {
		$date_ymd = str_replace( '-', '', $f_date );

		// click_event 列（pv_id / page_id / selector_id / to_url_id）
		$click = $this->load_clickevent_columns(
			$tracking_id,
			$date_ymd,
			array( 'pv_id', 'page_id', 'selector_id', 'to_url_id' )
		);
		if ( null === $click ) {
			return null;
		}

		// version_id は click_event に無いため allpv（pv_id→version_id）で join する。
		// allpv も done + view_pv 行数一致を採用条件にする（ドリフト日は従来経路へ）。
		$allpv = $this->load_allpv_columns( $tracking_id, $date_ymd, array( 'pv_id', 'version_id' ) );
		if ( null === $allpv ) {
			return null;
		}
		if ( $this->wrap_count( $allpv['pv_id'] ) !== (int) $expected_rows ) {
			return null;
		}
		$pv_to_version = array();
		for ( $i = 0, $allpv_max = $this->wrap_count( $allpv['pv_id'] ); $i < $allpv_max; $i++ ) {
			$pv_to_version[ (int) $allpv['pv_id'][ $i ] ] = (int) $allpv['version_id'][ $i ];
		}

		// セレクタ辞書・URL 辞書を用意（既存 fetch_click_event_data と同じ解決方法）。
		if ( ! class_exists( 'QAHM_ColumnDB_Selectors' ) || ! class_exists( 'QAHM_ColumnDB_Dictionary' ) ) {
			return null;
		}
		$data_dir  = $this->get_data_dir_path();
		$click_dir = $data_dir . 'report/' . $this->get_safe_tracking_id( $tracking_id ) . '/columns-db/click_event/';
		if ( 'all' === $tracking_id ) {
			$selectors = new QAHM_ColumnDB_Selectors( 'all', $click_dir );
		} else {
			$selectors = new QAHM_ColumnDB_Selectors( $tracking_id );
		}
		$urls = new QAHM_ColumnDB_Dictionary( $click_dir . 'dict-urls.php' );

		$se_ary     = array();
		$ver_to_idx = array(); // version_id => $se_ary の添字
		$evt_to_idx = array(); // version_id => "selector\0cv_type" => event の添字
		$pv_seen    = array(); // version_id => イベントキー => pv_id => true（pv 単位の重複排除）

		for ( $row = 0, $row_max = $this->wrap_count( $click['pv_id'] ); $row < $row_max; $row++ ) {
			$pv_id       = (int) $click['pv_id'][ $row ];
			$version_id  = isset( $pv_to_version[ $pv_id ] ) ? $pv_to_version[ $pv_id ] : 0;
			$selector_id = (int) $click['selector_id'][ $row ];
			$to_url_id   = (int) $click['to_url_id'][ $row ];

			$selector = $selectors->get_string( $selector_id );
			if ( null === $selector ) {
				$selector = '';
			}

			// to_url_id=0 は遷移なし（純クリック）。それ以外は遷移文字列を逆引きし、
			// 'p','t','i','o' は cv_type、それ以外は url（現行 raw_c の TRANSITION 分岐と同一）。
			$cv_type = 'c';
			$url     = null;
			if ( 0 !== $to_url_id ) {
				$transition = $urls->get_string( $to_url_id );
				if ( null !== $transition && '' !== $transition ) {
					if ( $this->wrap_in_array( $transition, array( 'p', 't', 'i', 'o' ) ) ) {
						$cv_type = $transition;
					} else {
						$url = $transition;
					}
				}
			}

			if ( ! isset( $ver_to_idx[ $version_id ] ) ) {
				$ver_to_idx[ $version_id ] = $this->wrap_count( $se_ary );
				$se_ary[]                  = array(
					'page_id'    => (int) $click['page_id'][ $row ],
					'version_id' => $version_id,
					'event'      => array(),
				);
			}
			$se_idx  = $ver_to_idx[ $version_id ];
			$evt_key = $selector . "\0" . $cv_type;

			if ( ! isset( $evt_to_idx[ $version_id ][ $evt_key ] ) ) {
				$evt_to_idx[ $version_id ][ $evt_key ] = $this->wrap_count( $se_ary[ $se_idx ]['event'] );
				$se_ary[ $se_idx ]['event'][]          = array(
					'cv_type'  => $cv_type,
					'selector' => $selector,
					'pv_id'    => array(),
					'url'      => $url,
				);
			}
			$evt_idx = $evt_to_idx[ $version_id ][ $evt_key ];

			// 同一 pv が同一 (selector, cv_type) を複数回クリックしても pv_id は1回だけ
			if ( ! isset( $pv_seen[ $version_id ][ $evt_key ][ $pv_id ] ) ) {
				$se_ary[ $se_idx ]['event'][ $evt_idx ]['pv_id'][] = $pv_id;
				$pv_seen[ $version_id ][ $evt_key ][ $pv_id ]      = true;
			}
		}

		$selectors->close();
		$urls->close();

		return $se_ary;
	}

	public function make_summary_days_access_detail( $connect_dir = 'all' ) {
		if ( $connect_dir == '' ) {
			$tracking_id = $this->get_tracking_id();
		} else {
			$tracking_id = $connect_dir;
		}
		global $wp_filesystem;
		global $qahm_time;
		// dir
		$data_dir = $this->get_data_dir_path();
		$view_dir = $data_dir . 'view/';
		// QA ZERO END
		$myview_dir = $view_dir . $tracking_id . '/';
		$viewpv_dir = $myview_dir . 'view_pv/';
		// $verhist_dir       = $myview_dir . 'version_hist/';
		$verhist_dir                     = $view_dir . 'all' . '/version_hist/';
		$raw_c_dir                       = $viewpv_dir . 'raw_c/';
		$vw_summary_dir                  = $myview_dir . 'summary/';
		$summary_days_access_detail_file = $vw_summary_dir . 'days_access_detail.php';

		//koji maruyama 2024/08/15 処理が長いとエラーになったので短くする。
		//基本的にview_pvも７日前からしか集計しないので、それ以前のデータは使わない
		$cronobj     = new QAHM_Cron_Proc();
		$s_date      = $qahm_time->xday_str( QAHM_Cron_Proc::REBUILD_VIEWPV_MAX_DAYS );
		$oldest_date = $cronobj->get_oldest_date_from_viewpv_create_hist();
		if ( $oldest_date ) {
			$s_date = $oldest_date;
		}
		$s_datetime             = $s_date . ' 00:00:00';
		$days_access_detail_ary = $this->wrap_unserialize( $this->wrap_get_contents( $summary_days_access_detail_file ) );
		if ( ! is_array( $days_access_detail_ary ) ) {
			$days_access_detail_ary = array();
		}
		//      $s_datetime = '1999-12-31 00:00:00';
		//      if ( $wp_filesystem->exists($summary_days_access_detail_file ) ) {
		//          $days_access_detail_ary = $this->wrap_unserialize( $this->wrap_get_contents( $summary_days_access_detail_file ) );
		//          if ( 0 < $this->wrap_count( $days_access_detail_ary ) ) {
		//              //　結局、毎月DBから上書きして値が増えるので、DBの期間分は上書きが必要
		//              // x month ago
		//              $yearx = $qahm_time->year();
		//              $month = $qahm_time->month();
		//              $data_save_month = QAHM_Cron_Proc::DATA_SAVE_MONTH;
		//              //  same_month
		//              $save_yearx = $yearx;
		//              $save_month = $month - $data_save_month;
		//              if ( $save_month <= 0 ) {
		//                  $save_month = 12 + $save_month;
		//                  $save_yearx = $yearx - 1;
		//              }
		//
		//              //below is ok
		//              $save_month = sprintf('%02d', $save_month);
		//              $s_datetime = $save_yearx . '-' . $save_month . '-01 00:00:00';
		//          }
		//      }

		//search どの日付から変更するべきか
		$start_idx = 0;
		foreach ( $days_access_detail_ary as $idx => $days_access ) {
			if ( isset( $days_access['sum_datetime'] ) ) {
				if ( ( $qahm_time->now_unixtime() - 3 * 60 * 60 ) < $qahm_time->str_to_unixtime( $days_access['sum_datetime'] ) ) {
					//本日集計済みなので、この日付は飛ばすべき
					$s_datetime = $days_access['date'] . ' 23:59:59';
					$start_idx  = $idx + 1;
				}
			} else {
				//dummyの古い値を入れる
				$tmpary                         = $this->wrap_array_merge( $days_access, array( 'sum_datetime' => '1999-12-31 00:00:00' ) );
				$days_access_detail_ary[ $idx ] = $tmpary;
			}
			if ( isset( $days_access['date'] ) ) {
				$ary_datetime = $days_access['date'] . ' 00:00:00';
				if ( $qahm_time->str_to_unixtime( $s_datetime ) <= $qahm_time->str_to_unixtime( $ary_datetime ) ) {
					if ( $start_idx === 0 ) {
						$start_idx = $idx;
					}
				}
			}
		}
		if ( $this->wrap_count( $days_access_detail_ary ) <= $start_idx && $start_idx !== 0 ) {
			$start_idx = -1;
		}

		//page関連は一ヶ月毎にサマリーファイルを作る
		$sap_1mon_ary  = array();
		$sl_1mon_ary   = array();
		$make_1mon_cnt = 0;
		$sap_name_1mon = '';
		$lp_name_1mon  = '';
		$base_sel_ary  = array();
		// search view_pv dir
		$allfiles = $this->wrap_dirlist( $viewpv_dir );
		if ( $allfiles ) {
			foreach ( $allfiles as $file ) {
				$filename = $file['name'];
				if ( is_file( $viewpv_dir . $filename ) ) {
					$f_date     = $this->wrap_substr( $filename, 0, 10 );
					$f_datetime = $f_date . ' 00:00:00';
					if ( $qahm_time->str_to_unixtime( $s_datetime ) <= $qahm_time->str_to_unixtime( $f_datetime ) ) {
						if ( $this->wrap_substr( $f_date, -2 ) === '01' ) {
							if ( 0 < $make_1mon_cnt ) {
								//exchange user_count
								foreach ( $sl_1mon_ary as $idxp => $aryp ) {
									foreach ( $aryp as $idxd => $aryd ) {
										foreach ( $aryd as $idxs => $arys ) {
											foreach ( $arys as $idxm => $arym ) {
												foreach ( $arym as $idxc => $aryc ) {
													foreach ( $aryc as $idxn => $aryn ) {
														foreach ( $aryn as $idx2 => $ary2 ) {
															foreach ( $ary2 as $idxq => $aryq ) {
																if ( isset( $aryq['user_count'] ) ) {
																	$unique   = array_unique( $aryq['user_count'], SORT_NUMERIC );
																	$uniqusr  = array_values( $unique );
																	$user_cnt = $this->wrap_count( $uniqusr );
																	$sl_1mon_ary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idx2 ][ $idxq ]['user_count'] = $user_cnt;
																}
															}
														}
													}
												}
											}
										}
									}
								}
								foreach ( $sap_1mon_ary as $idxp => $aryp ) {
									foreach ( $aryp as $idxd => $aryd ) {
										foreach ( $aryd as $idxs => $arys ) {
											foreach ( $arys as $idxm => $arym ) {
												foreach ( $arym as $idxc => $aryc ) {
													foreach ( $aryc as $idxn => $aryn ) {
														foreach ( $aryn as $idxq => $aryq ) {
															if ( isset( $aryq['user_count'] ) ) {
																$unique   = array_unique( $aryq['user_count'], SORT_NUMERIC );
																$uniqusr  = array_values( $unique );
																$user_cnt = $this->wrap_count( $uniqusr );
																$sap_1mon_ary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['user_count'] = $user_cnt;
															}
														}
													}
												}
											}
										}
									}
								}
								//write 1mon access
								$this->wrap_put_contents( $vw_summary_dir . $lp_name_1mon, $this->wrap_serialize( $sl_1mon_ary ) );
								$this->wrap_put_contents( $vw_summary_dir . $sap_name_1mon, $this->wrap_serialize( $sap_1mon_ary ) );
							}
							++$make_1mon_cnt;
							$lp_name_1mon  = $f_date . '_summary_landingpage_1mon.php';
							$sap_name_1mon = $f_date . '_summary_allpage_1mon.php';

							$sl_1mon_ary  = array();
							$sap_1mon_ary = array();
						}

						//集計対象
						$view_pv_ary = $this->wrap_unserialize( $this->wrap_get_contents( $viewpv_dir . $filename ) );

						$raw_c_file_ary = null;
						$raw_c_file_idx = 0;
						$raw_c_filename = str_replace( 'viewpv', 'rawc', $filename );
						if ( $this->wrap_exists( $raw_c_dir . $raw_c_filename ) ) {
							$raw_c_file_ary = $this->wrap_unserialize( $this->wrap_get_contents( $raw_c_dir . $raw_c_filename ) );
						}

						//作るファイルは4つ。
						//summary_days_access_detail(spad)
						//YYYY-MM-DD_summary_allpage(sap)
						//YYYY-MM-DD_summary_landingpage(sl)
						//YYYY-MM-DD_summary_event(se)
						$sdad_ary = array();
						$sap_ary  = array();
						$sl_ary   = array();
						$se_ary   = array();
						$total_pv = $this->wrap_count( $view_pv_ary );
						foreach ( $view_pv_ary as $idx => $pv_ary ) {
							//Dimensions
							//(int)=nullや空文字は0になる
							$pv_id       = (int) $pv_ary['pv_id'];
							$reader_id   = (int) $pv_ary['reader_id'];
							$page_id     = (int) $pv_ary['page_id'];
							$device_id   = (int) $pv_ary['device_id'];
							$source_id   = (int) $pv_ary['source_id'];
							$medium_id   = (int) $pv_ary['medium_id'];
							$campaign_id = (int) $pv_ary['campaign_id'];
							$version_id  = (int) $pv_ary['version_id'];
							$is_newuser  = (int) $pv_ary['is_newuser'];
							$is_QA       = 1;
							//Metrics
							$browse_sec = (int) $pv_ary['browse_sec'];
							//条件判定
							$is_last  = (int) $pv_ary['is_last'];
							$is_raw_c = (int) $pv_ary['is_raw_c'];

							//----make tmp_array
							$tmp_sdad_ary = array(
								'pv_count'      => 1,
								'session_count' => 0,
								'user_count'    => array( $reader_id ),
								'bounce_count'  => 0,
								'time_on_page'  => $browse_sec,
							);
							$tmp_sap_ary  = array(
								'pv_count'     => 1,
								'user_count'   => array( $reader_id ),
								'bounce_count' => 0,
								'exit_count'   => 0,
								'time_on_page' => $browse_sec,
								'lp_count'     => 0,
							);
							$tmp_sl_ary   = array(
								'pv_count'      => 1,
								'session_count' => 0,
								'user_count'    => array( $reader_id ),
								'bounce_count'  => 0,
								'session_time'  => $browse_sec,
							);

							$is_landingpage = 0;
							$second_page    = 0;
							//count session 当日の1ページ目（LP着地）をカウント
							if ( (int) $pv_ary['pv'] === 1 ) {
								$is_landingpage = 1;
							}
							//tmp_array start
							//直帰ページ
							if ( $is_landingpage && $is_last ) {
								$tmp_sdad_ary['bounce_count'] = 1;
								$tmp_sap_ary['bounce_count']  = 1;
								$tmp_sl_ary['bounce_count']   = 1;
								//2ページ以上見たセッション
							} elseif ( $is_landingpage ) {
								if ( $idx < $total_pv - 1 ) {
									$second_page = $view_pv_ary[ $idx + 1 ]['page_id'];
									//calc session_time
									for ( $iii = $idx + 1; $iii < $total_pv; $iii++ ) {
										$tmp_sl_ary['session_time'] += $view_pv_ary[ $iii ]['browse_sec'];
										++$tmp_sl_ary['pv_count'];
										if ( $view_pv_ary[ $iii ]['is_last'] ) {
											break;
										}
									}
								}
							}
							//離脱ページ
							if ( $is_last ) {
								$tmp_sap_ary['exit_count'] = 1;
							}
							//lpの時の処理。slはここで作る
							if ( $is_landingpage ) {
								$tmp_sdad_ary['session_count'] = 1;
								$tmp_sl_ary['session_count']   = 1;
								$tmp_sap_ary['lp_count']       = 1;
								if ( isset( $sl_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $second_page ][ $is_QA ] ) ) {
									$sl_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $second_page ][ $is_QA ]['pv_count']      += $tmp_sl_ary['pv_count'];
									$sl_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $second_page ][ $is_QA ]['session_count'] += $tmp_sl_ary['session_count'];
									$sl_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $second_page ][ $is_QA ]['user_count'][]   = $tmp_sl_ary['user_count'][0];
									$sl_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $second_page ][ $is_QA ]['bounce_count']  += $tmp_sl_ary['bounce_count'];
									$sl_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $second_page ][ $is_QA ]['session_time']  += $tmp_sl_ary['session_time'];
								} else {
									$sl_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $second_page ][ $is_QA ] = $tmp_sl_ary;
								}

								//1mon
								if ( isset( $sl_1mon_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $second_page ][ $is_QA ] ) ) {
									$sl_1mon_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $second_page ][ $is_QA ]['pv_count']      += $tmp_sl_ary['pv_count'];
									$sl_1mon_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $second_page ][ $is_QA ]['session_count'] += $tmp_sl_ary['session_count'];
									$sl_1mon_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $second_page ][ $is_QA ]['user_count'][]   = $tmp_sl_ary['user_count'][0];
									$sl_1mon_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $second_page ][ $is_QA ]['bounce_count']  += $tmp_sl_ary['bounce_count'];
									$sl_1mon_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $second_page ][ $is_QA ]['session_time']  += $tmp_sl_ary['session_time'];
								} else {
									$sl_1mon_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $second_page ][ $is_QA ] = $tmp_sl_ary;
								}
							}

							//sdadとsapを作る
							if ( isset( $sdad_ary[ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ] ) ) {
								$sdad_ary[ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ]['pv_count']      += $tmp_sdad_ary['pv_count'];
								$sdad_ary[ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ]['session_count'] += $tmp_sdad_ary['session_count'];
								$sdad_ary[ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ]['user_count'][]   = $tmp_sdad_ary['user_count'][0];
								$sdad_ary[ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ]['bounce_count']  += $tmp_sdad_ary['bounce_count'];
								$sdad_ary[ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ]['time_on_page']  += $tmp_sdad_ary['time_on_page'];
							} else {
								$sdad_ary[ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ] = $tmp_sdad_ary;
							}
							if ( isset( $sap_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ] ) ) {
								$sap_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ]['pv_count']     += $tmp_sap_ary['pv_count'];
								$sap_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ]['user_count'][]  = $tmp_sap_ary['user_count'][0];
								$sap_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ]['bounce_count'] += $tmp_sap_ary['bounce_count'];
								$sap_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ]['exit_count']   += $tmp_sap_ary['exit_count'];
								$sap_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ]['time_on_page'] += $tmp_sap_ary['time_on_page'];
								$sap_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ]['lp_count']     += $tmp_sap_ary['lp_count'];
							} else {
								$sap_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ] = $tmp_sap_ary;
							}
							//1mon
							if ( isset( $sap_1mon_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ] ) ) {
								$sap_1mon_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ]['pv_count']     += $tmp_sap_ary['pv_count'];
								$sap_1mon_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ]['user_count'][]  = $tmp_sap_ary['user_count'][0];
								$sap_1mon_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ]['bounce_count'] += $tmp_sap_ary['bounce_count'];
								$sap_1mon_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ]['exit_count']   += $tmp_sap_ary['exit_count'];
								$sap_1mon_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ]['time_on_page'] += $tmp_sap_ary['time_on_page'];
								$sap_1mon_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ]['lp_count']     += $tmp_sap_ary['lp_count'];
							} else {
								$sap_1mon_ary[ $page_id ][ $device_id ][ $source_id ][ $medium_id ][ $campaign_id ][ $is_newuser ][ $is_QA ] = $tmp_sap_ary;
							}

							// seを作る
							if ( $is_raw_c && $raw_c_file_ary ) {
								$raw_c_ary = null;
								for ( $raw_c_file_max = $this->wrap_count( $raw_c_file_ary ); $raw_c_file_idx < $raw_c_file_max; $raw_c_file_idx++ ) {
									if ( $pv_id === (int) $raw_c_file_ary[ $raw_c_file_idx ]['pv_id'] ) {
										$raw_c_ary = $this->convert_tsv_to_array( $raw_c_file_ary[ $raw_c_file_idx ]['raw_c'] );
										break;
									}
								}

								if ( $raw_c_ary ) {
									// raw_cの配列をイベントサマリー用に最適化
									// raw_cのデータバージョンによる処理は今は無し
									$event_ary = array();
									for ( $raw_c_idx = QAHM_File_Data::DATA_COLUMN_BODY, $raw_c_max = $this->wrap_count( $raw_c_ary ); $raw_c_idx < $raw_c_max; $raw_c_idx++ ) {
										$sel_num = (int) $raw_c_ary[ $raw_c_idx ][ QAHM_File_Data::DATA_CLICK_1['SELECTOR_NAME'] ];
										$url     = null;
										$cv_type = 'c';
										if ( $this->wrap_array_key_exists( QAHM_File_Data::DATA_CLICK_1['TRANSITION'], $raw_c_ary[ $raw_c_idx ] ) ) {
											$transition = $raw_c_ary[ $raw_c_idx ][ QAHM_File_Data::DATA_CLICK_1['TRANSITION'] ];
											if ( $this->wrap_in_array( $transition, array( 'p', 't', 'i', 'o' ) ) ) {
												// 'p', 't', 'i', 'o' のいずれかの場合の処理
												$cv_type = $transition;
											} else {
												// それ以外の場合の処理
												$url = $transition;
											}
											//$url = $raw_c_ary[$raw_c_idx][QAHM_File_Data::DATA_CLICK_1['TRANSITION']];
										}
										$is_add_event = false;
										$event_max    = $this->wrap_count( $event_ary );
										if ( $event_max > 0 ) {
											for ( $event_idx = 0; $event_idx < $event_max; $event_idx++ ) {
												//if ( $event_ary[$event_idx]['selector'] === $sel_num ) {
												if ( $event_ary[ $event_idx ]['selector'] === $sel_num && $event_ary[ $event_idx ]['cv_type'] === $cv_type ) {
													$is_add_event = true;
													break;
												}
											}
										}
										if ( ! $is_add_event ) {
											//$event_ary[] = [ 'cv_type' => 'c', 'selector' =>$sel_num , 'pv_id' => [ $pv_id ], 'url' => $url ];
											$event_ary[] = array(
												'cv_type'  => $cv_type,
												'selector' => $sel_num,
												'pv_id'    => array( $pv_id ),
												'url'      => $url,
											);
										}
									}

									// 挿入するイベント配列のインデックスを求める
									$se_idx = -1;
									$se_max = $this->wrap_count( $se_ary );
									if ( $se_max > 0 ) {
										for ( $tmp_se_idx = 0; $tmp_se_idx < $se_max; $tmp_se_idx++ ) {
											if ( $se_ary[ $tmp_se_idx ]['version_id'] === $version_id ) {
												$se_idx = $tmp_se_idx;
												break;
											}
										}
									}

									if ( $se_idx === -1 ) {
										$se_ary[] = array(
											'page_id'    => $page_id,
											'version_id' => $version_id,
											'event'      => $event_ary,
										);
										if ( ! isset( $base_sel_ary[ $version_id ] ) ) {
											if ( $this->wrap_exists( $verhist_dir . $version_id . '_version.php' ) ) {
												$verhist_ary                 = $this->wrap_unserialize( $this->wrap_get_contents( $verhist_dir . $version_id . '_version.php' ) );
												$base_sel_ary[ $version_id ] = $this->convert_tsv_to_array( $verhist_ary[0]->base_selector )[0];
											}
										}
									} else {
										for ( $event_idx = 0, $event_max = $this->wrap_count( $event_ary ); $event_idx < $event_max; $event_idx++ ) {
											$is_add_sel = false;
											for ( $se_event_idx = 0, $se_event_max = $this->wrap_count( $se_ary[ $se_idx ]['event'] ); $se_event_idx < $se_event_max; $se_event_idx++ ) {
												// 同名のイベントタイプ＆セレクタが追加されている場合、pv_idのみ追加
												if ( $se_ary[ $se_idx ]['event'][ $se_event_idx ]['selector'] === $event_ary[ $event_idx ]['selector'] &&
													$se_ary[ $se_idx ]['event'][ $se_event_idx ]['cv_type'] === $event_ary[ $event_idx ]['cv_type'] ) {
													$se_ary[ $se_idx ]['event'][ $se_event_idx ]['pv_id'][] = $event_ary[ $event_idx ]['pv_id'][0];
													$is_add_sel = true;
													break;
												}
											}

											if ( ! $is_add_sel ) {
												$se_ary[ $se_idx ]['event'][] = $event_ary[ $event_idx ];
											}
										}
									}
								}
							}
						}

						// イベントサマリーデータのセレクタIDをセレクタ名に変換
						for ( $se_idx = 0, $se_max = $this->wrap_count( $se_ary ); $se_idx < $se_max; $se_idx++ ) {
							for ( $event_idx = 0, $event_max = $this->wrap_count( $se_ary[ $se_idx ]['event'] ); $event_idx < $event_max; $event_idx++ ) {
								$se_ary[ $se_idx ]['event'][ $event_idx ]['selector'] = $base_sel_ary[ $se_ary[ $se_idx ]['version_id'] ][ $se_ary[ $se_idx ]['event'][ $event_idx ]['selector'] ];
							}
						}

						// #1287 P2-α / #1313 P2-β1: days_access_detail（sdad）・summary_allpage（sap）・summary_landingpage（sl）は
						// ColumnDB（allpv）読みを正とする。完了マーカー・行数一致等の保証が成立しない日は、
						// 従来どおり上のループ集計値（view_pv 由来）を使う（誤爆方向は常に旧動作 = エラーを発生させず移行）。
						// summary_event（se）は raw_c クリック明細が必要なため現行維持（P2-β2 で続編）。
						$sdad_allpv = $this->make_sdad_from_allpv( $tracking_id, $f_date, $total_pv );
						if ( null !== $sdad_allpv ) {
							$sdad_ary = $sdad_allpv;
						}
						$sapsl_allpv = $this->make_sap_sl_from_allpv( $tracking_id, $f_date, $total_pv );
						if ( null !== $sapsl_allpv ) {
							$sap_ary = $sapsl_allpv['sap'];
							$sl_ary  = $sapsl_allpv['sl'];
						}
						// #1316 P2-β2: summary_event（se）は click_event 列DB（selector_id/to_url_id を統合辞書で
						// 逆引き）から史実どおりに復元する。version_id は allpv（pv_id→version_id）で join。
						// click_event・allpv の保証が崩れる日は null＝従来 raw_c 由来の $se_ary（上のループ集計）を使う。
						$se_clickevent = $this->make_se_from_clickevent( $tracking_id, $f_date, $total_pv );
						if ( null !== $se_clickevent ) {
							$se_ary = $se_clickevent;
						}

						//容量を減らすためユーザーカウントを置き換えていく必要がある。
						foreach ( $sdad_ary as $idxd => $aryd ) {
							foreach ( $aryd as $idxs => $arys ) {
								foreach ( $arys as $idxm => $arym ) {
									foreach ( $arym as $idxc => $aryc ) {
										foreach ( $aryc as $idxn => $aryn ) {
											foreach ( $aryn as $idxq => $aryq ) {
												if ( isset( $aryq['user_count'] ) ) {
													$unique   = array_unique( $aryq['user_count'], SORT_NUMERIC );
													$uniqusr  = array_values( $unique );
													$user_cnt = $this->wrap_count( $uniqusr );
													$sdad_ary[ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['user_count'] = $user_cnt;
												}
											}
										}
									}
								}
							}
						}
						foreach ( $sl_ary as $idxp => $aryp ) {
							foreach ( $aryp as $idxd => $aryd ) {
								foreach ( $aryd as $idxs => $arys ) {
									foreach ( $arys as $idxm => $arym ) {
										foreach ( $arym as $idxc => $aryc ) {
											foreach ( $aryc as $idxn => $aryn ) {
												foreach ( $aryn as $idx2 => $ary2 ) {
													foreach ( $ary2 as $idxq => $aryq ) {
														if ( isset( $aryq['user_count'] ) ) {
															$unique   = array_unique( $aryq['user_count'], SORT_NUMERIC );
															$uniqusr  = array_values( $unique );
															$user_cnt = $this->wrap_count( $uniqusr );
															$sl_ary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idx2 ][ $idxq ]['user_count'] = $user_cnt;
														}
													}
												}
											}
										}
									}
								}
							}
						}
						foreach ( $sap_ary as $idxp => $aryp ) {
							foreach ( $aryp as $idxd => $aryd ) {
								foreach ( $aryd as $idxs => $arys ) {
									foreach ( $arys as $idxm => $arym ) {
										foreach ( $arym as $idxc => $aryc ) {
											foreach ( $aryc as $idxn => $aryn ) {
												foreach ( $aryn as $idxq => $aryq ) {
													if ( isset( $aryq['user_count'] ) ) {
														$unique   = array_unique( $aryq['user_count'], SORT_NUMERIC );
														$uniqusr  = array_values( $unique );
														$user_cnt = $this->wrap_count( $uniqusr );
														$sap_ary[ $idxp ][ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['user_count'] = $user_cnt;
													}
												}
											}
										}
									}
								}
							}
						}

						// 今回のファイルは既存aryの中にないので追加する
						if ( $start_idx < 0 ) {
							$days_access_detail_ary[] = array(
								'date' => $f_date,
								'data' => $sdad_ary,
							);
							// 今回の再計算した対象ファイルは既存aryの中に入る予定なので、どこに追加するかをチェック
						} else {
							$is_find  = false;
							$afterary = array();
							//既存aryの中で一致する日付を検索していれる
							for ( $ddd = $start_idx; $ddd < $this->wrap_count( $days_access_detail_ary ); $ddd++ ) {
								if ( isset( $days_access_detail_ary[ $ddd ]['date'] ) ) {
									$ary_datetime = $days_access_detail_ary[ $ddd ]['date'] . ' 00:00:00';
									if ( $qahm_time->str_to_unixtime( $ary_datetime ) <= $qahm_time->str_to_unixtime( $f_datetime ) ) {
										++$start_idx;
									} else {
										$afterary[] = $days_access_detail_ary[ $ddd ];
									}
									if ( $days_access_detail_ary[ $ddd ]['date'] === $f_date ) {
										$days_access_detail_ary[ $ddd ] = array(
											'date' => $f_date,
											'data' => $sdad_ary,
										);
										$is_find                        = true;
										break;
									}
								}
							}
							//まったく見つからなかった場合は、aryのおしりか間に追加
							if ( ! $is_find ) {
								//そもそも日付がオーバーした時は、おしりに追加
								if ( $this->wrap_count( $days_access_detail_ary ) <= $start_idx ) {
									$days_access_detail_ary[] = array(
										'date' => $f_date,
										'data' => $sdad_ary,
									);
									//以後の日付はお尻に追加
									$start_idx = -1;
									//日付がオーバーしていない場合は、間に追加
								} else {
									$new_days_access_detail_ary = array();
									for ( $ccc = 0; $ccc < $start_idx; $ccc++ ) {
										$new_days_access_detail_ary[] = $days_access_detail_ary[ $ccc ];
									}
									//start_idxのところに挿入
									$new_days_access_detail_ary[] = array(
										'date' => $f_date,
										'data' => $sdad_ary,
									);
									//お尻はいままで通り
									for ( $ccc = 0; $ccc < $this->wrap_count( $afterary ); $ccc++ ) {
										$new_days_access_detail_ary[] = $afterary[ $ccc ];
									}
									$days_access_detail_ary = $new_days_access_detail_ary;
									// 次の$fileの日付検索は次のstart_idxから
									++$start_idx;
									if ( $this->wrap_count( $days_access_detail_ary ) <= $start_idx ) {
										//以後の日付はお尻に追加
										$start_idx = -1;
									}
								}
							}
						}
						//write today access
						$this->wrap_put_contents( $vw_summary_dir . $f_date . '_summary_allpage.php', $this->wrap_serialize( $sap_ary ) );
						$this->wrap_put_contents( $vw_summary_dir . $f_date . '_summary_landingpage.php', $this->wrap_serialize( $sl_ary ) );
						$this->wrap_put_contents( $vw_summary_dir . $f_date . '_summary_event.php', $this->wrap_serialize( $se_ary ) );
						$this->wrap_put_contents( $summary_days_access_detail_file, $this->wrap_serialize( $days_access_detail_ary ) );

						// startするdatetimeは次の日付になる。
						$s_datetime = $qahm_time->xday_str( 1, $f_datetime, QAHM_Time::DEFAULT_DATETIME_FORMAT );

					} elseif ( ! $this->wrap_exists( $vw_summary_dir . $f_date . '_summary_event.php' ) ) {
						// summary_eventファイルが存在しない場合は作成する
						$view_pv_ary    = $this->wrap_unserialize( $this->wrap_get_contents( $viewpv_dir . $filename ) );
						$raw_c_file_ary = null;
						$raw_c_file_idx = 0;
						$raw_c_filename = str_replace( 'viewpv', 'rawc', $filename );
						if ( $this->wrap_exists( $raw_c_dir . $raw_c_filename ) ) {
							$raw_c_file_ary = $this->wrap_unserialize( $this->wrap_get_contents( $raw_c_dir . $raw_c_filename ) );
						}

						$se_ary   = array();
						$total_pv = $this->wrap_count( $view_pv_ary );
						if ( $raw_c_file_ary ) {
							foreach ( $view_pv_ary as $idx => $pv_ary ) {
								//Dimensions
								//(int)=nullや空文字は0になる
								$pv_id      = (int) $pv_ary['pv_id'];
								$page_id    = (int) $pv_ary['page_id'];
								$version_id = (int) $pv_ary['version_id'];
								$is_raw_c   = (int) $pv_ary['is_raw_c'];

								// seを作る
								if ( $is_raw_c ) {
									$raw_c_ary = null;
									for ( $raw_c_file_max = $this->wrap_count( $raw_c_file_ary ); $raw_c_file_idx < $raw_c_file_max; $raw_c_file_idx++ ) {
										if ( $pv_id === (int) $raw_c_file_ary[ $raw_c_file_idx ]['pv_id'] ) {
											$raw_c_ary = $this->convert_tsv_to_array( $raw_c_file_ary[ $raw_c_file_idx ]['raw_c'] );
											break;
										}
									}

									if ( $raw_c_ary ) {
										// raw_cの配列をイベントサマリー用に最適化
										// raw_cのデータバージョンによる処理は今は無し
										$event_ary = array();
										for ( $raw_c_idx = QAHM_File_Data::DATA_COLUMN_BODY, $raw_c_max = $this->wrap_count( $raw_c_ary ); $raw_c_idx < $raw_c_max; $raw_c_idx++ ) {
											$sel_num = (int) $raw_c_ary[ $raw_c_idx ][ QAHM_File_Data::DATA_CLICK_1['SELECTOR_NAME'] ];
											$url     = null;
											if ( $this->wrap_array_key_exists( QAHM_File_Data::DATA_CLICK_1['TRANSITION'], $raw_c_ary[ $raw_c_idx ] ) ) {
												$url = $raw_c_ary[ $raw_c_idx ][ QAHM_File_Data::DATA_CLICK_1['TRANSITION'] ];
											}
											$is_add_event = false;
											$event_max    = $this->wrap_count( $event_ary );
											if ( $event_max > 0 ) {
												for ( $event_idx = 0; $event_idx < $event_max; $event_idx++ ) {
													if ( $event_ary[ $event_idx ]['selector'] === $sel_num ) {
														$is_add_event = true;
														break;
													}
												}
											}
											if ( ! $is_add_event ) {
												$event_ary[] = array(
													'cv_type' => 'c',
													'selector' => $sel_num,
													'pv_id' => array( $pv_id ),
													'url' => $url,
												);
											}
										}

										// 挿入するイベント配列のインデックスを求める
										$se_idx = -1;
										$se_max = $this->wrap_count( $se_ary );
										if ( $se_max > 0 ) {
											for ( $tmp_se_idx = 0; $tmp_se_idx < $se_max; $tmp_se_idx++ ) {
												if ( $se_ary[ $tmp_se_idx ]['version_id'] === $version_id ) {
													$se_idx = $tmp_se_idx;
													break;
												}
											}
										}

										if ( $se_idx === -1 ) {
											$se_ary[] = array(
												'page_id' => $page_id,
												'version_id' => $version_id,
												'event'   => $event_ary,
											);
											if ( ! isset( $base_sel_ary[ $version_id ] ) ) {
												if ( $this->wrap_exists( $verhist_dir . $version_id . '_version.php' ) ) {
													$verhist_ary                 = $this->wrap_unserialize( $this->wrap_get_contents( $verhist_dir . $version_id . '_version.php' ) );
													$base_sel_ary[ $version_id ] = $this->convert_tsv_to_array( $verhist_ary[0]->base_selector )[0];
												}
											}
										} else {
											for ( $event_idx = 0, $event_max = $this->wrap_count( $event_ary ); $event_idx < $event_max; $event_idx++ ) {
												$is_add_sel = false;
												for ( $se_event_idx = 0, $se_event_max = $this->wrap_count( $se_ary[ $se_idx ]['event'] ); $se_event_idx < $se_event_max; $se_event_idx++ ) {
													// 同名のイベントタイプ＆セレクタが追加されている場合、pv_idのみ追加
													if ( $se_ary[ $se_idx ]['event'][ $se_event_idx ]['selector'] === $event_ary[ $event_idx ]['selector'] &&
														$se_ary[ $se_idx ]['event'][ $se_event_idx ]['cv_type'] === $event_ary[ $event_idx ]['cv_type'] ) {
														$se_ary[ $se_idx ]['event'][ $se_event_idx ]['pv_id'][] = $event_ary[ $event_idx ]['pv_id'][0];
														$is_add_sel = true;
														break;
													}
												}

												if ( ! $is_add_sel ) {
													$se_ary[ $se_idx ]['event'][] = $event_ary[ $event_idx ];
												}
											}
										}
									}
								}
							}

							// イベントサマリーデータのセレクタIDをセレクタ名に変換
							for ( $se_idx = 0, $se_max = $this->wrap_count( $se_ary ); $se_idx < $se_max; $se_idx++ ) {
								for ( $event_idx = 0, $event_max = $this->wrap_count( $se_ary[ $se_idx ]['event'] ); $event_idx < $event_max; $event_idx++ ) {
									$se_ary[ $se_idx ]['event'][ $event_idx ]['selector'] = $base_sel_ary[ $se_ary[ $se_idx ]['version_id'] ][ $se_ary[ $se_idx ]['event'][ $event_idx ]['selector'] ];
								}
							}
						}

						// #1316 P2-β2: click_event 経路で se を復元（raw_c 不在日でも click_event done なら生成可）。
						// click_event・allpv の保証が崩れる日は null＝上の raw_c 由来 $se_ary（無ければ空配列）を使う。
						$se_clickevent = $this->make_se_from_clickevent( $tracking_id, $f_date, $total_pv );
						if ( null !== $se_clickevent ) {
							$se_ary = $se_clickevent;
						}

						// write
						$this->wrap_put_contents( $vw_summary_dir . $f_date . '_summary_event.php', $this->wrap_serialize( $se_ary ) );
					}
				}
			}
		}
	}

	public function make_integral_summary_file( $summary_type, $years_back = 1, $connect_dir = 'all' ) {
		$data_dir       = $this->get_data_dir_path();
		$view_dir       = $data_dir . 'view/';
		$myview_dir     = $view_dir . ( $connect_dir ?: $this->get_tracking_id() ) . '/';
		$vw_summary_dir = $myview_dir . 'summary/';

		$date_format = 'Y-m-d'; // 日付形式

		// 現在の年と過去何年か分の年を取得
		$current_year = (int) current_time( 'Y' );
		$year_range   = range( $current_year - $years_back, $current_year );

		$previous_year_had_summary = false; // 前年にサマリーデータがあったかどうか

		foreach ( $year_range as $year ) {
			$regex_summary  = '/^(' . $year . '-\d{2}-\d{2})_summary_' . preg_quote( $summary_type ) . '\.php$/';
			$regex_integral = '/^(' . $year . '-\d{2}-\d{2})_summary_' . preg_quote( $summary_type ) . '_i\.php$/';

			$files = $this->wrap_dirlist( $vw_summary_dir );

			$summary_dates  = array();
			$integral_dates = array();
			foreach ( $files as $file ) {
				if ( preg_match( $regex_summary, $file['name'], $matches_summary ) ) {
					$summary_dates[] = $matches_summary[1];
				}
				if ( preg_match( $regex_integral, $file['name'], $matches_integral ) ) {
					$integral_dates[] = $matches_integral[1];
				}
			}

			if ( empty( $summary_dates ) ) {
				continue; // その年にsummaryファイルが一つもない場合は次の年へ
			}

			$latest_integral_date = ! empty( $integral_dates ) ? end( $integral_dates ) : reset( $summary_dates );
			$end_date             = ( $year == $current_year ) ? new DateTime() : DateTime::createFromFormat( 'Y-m-d', "$year-12-31" );
			$start_date           = DateTime::createFromFormat( $date_format, $latest_integral_date );
			if ( ! empty( $integral_dates ) && $this->wrap_in_array( $latest_integral_date, $integral_dates ) ) {
				$start_date->modify( '+1 day' );
			}

			// 前年にサマリーデータがあり、現在の年でない場合、開始日を1月1日に設定
			if ( $previous_year_had_summary && empty( $integral_dates ) ) {
				$start_date = DateTime::createFromFormat( $date_format, "$year-01-01" );
			}

			$integral_data = array(); // 累積データを初期化
			if ( ! empty( $integral_dates ) ) {
				$latest_integral_path = $vw_summary_dir . $latest_integral_date . '_summary_' . $summary_type . '_i.php';
				if ( is_readable( $latest_integral_path ) ) {
					$integral_data = $this->wrap_unserialize( $this->wrap_get_contents( $latest_integral_path ) );
				}
			}

			while ( $start_date <= $end_date ) {
				$formatted_date = $start_date->format( $date_format );

				$file_name = $formatted_date . '_summary_' . $summary_type . '.php';
				$file_path = $vw_summary_dir . $file_name;

				if ( is_readable( $file_path ) ) {
					$daily_data    = $this->wrap_unserialize( $this->wrap_get_contents( $file_path ) );
					$integral_data = $this->create_integral( $integral_data, $daily_data );
				}

				$integral_file_name = $formatted_date . '_summary_' . $summary_type . '_i.php';
				$integral_file_path = $vw_summary_dir . $integral_file_name;

				// 累積データをファイルに保存
				$this->wrap_put_contents( $integral_file_path, $this->wrap_serialize( $integral_data ) );

				$start_date->modify( '+1 day' ); // 日付を一日進める
			}

			$previous_year_had_summary = ! empty( $summary_dates );

		}
	}


	public function make_integral_days_access_detail_file( $years_back = 1, $connect_dir = 'all' ) {
		global $qahm_log;

		$data_dir       = $this->get_data_dir_path();
		$view_dir       = $data_dir . 'view/';
		$myview_dir     = $view_dir . ( $connect_dir ?: $this->get_tracking_id() ) . '/';
		$vw_summary_dir = $myview_dir . 'summary/';

		$date_format  = 'Y-m-d';
		$current_year = (int) current_time( 'Y' );
		$year_range   = range( $current_year - $years_back, $current_year );

		$file_path = $vw_summary_dir . 'days_access_detail.php';
		if ( ! is_readable( $file_path ) ) {
			return;
		}

		$daily_data_list = $this->wrap_unserialize( $this->wrap_get_contents( $file_path ) );
		if ( empty( $daily_data_list ) ) {
			return;
		}

		// 計測開始日
		$first_recorded_date     = $daily_data_list[0]['date'];
		$first_recorded_datetime = DateTime::createFromFormat( 'Y-m-d', $first_recorded_date );

		// 日付 → インデックス マップ
		$date_index_map = array_flip( array_column( $daily_data_list, 'date' ) );

		// 既存ファイル一覧を取得
		$files                = $this->wrap_dirlist( $vw_summary_dir );
		$existing_files       = array();
		$latest_integral_date = null;

		foreach ( $files as $file ) {
			if ( preg_match( '/^(\\d{4}-\\d{2}-\\d{2})_summary_days_access_detail_i\\.php$/', $file['name'], $matches ) ) {
				$existing_files[ $matches[1] ] = true;
				$latest_integral_date          = max( $latest_integral_date, $matches[1] );
			}
		}

		// 念のため直近5日分のファイルを削除（データ整合性確保のため）
		if ( $latest_integral_date ) {
			$latest_date_obj  = DateTime::createFromFormat( 'Y-m-d', $latest_integral_date );
			$delete_from_date = ( clone $latest_date_obj )->modify( '-5 days' );

			foreach ( $existing_files as $date => $exists ) {
				$file_date_obj = DateTime::createFromFormat( 'Y-m-d', $date );
				if ( $file_date_obj >= $delete_from_date ) {
					$file_to_delete = $vw_summary_dir . $date . '_summary_days_access_detail_i.php';
					if ( $this->wrap_exists( $file_to_delete ) ) {
						$delete_result = $this->wrap_delete( $file_to_delete );
						if ( $delete_result !== false ) {
							unset( $existing_files[ $date ] );
						} else {
							$qahm_log->warning( 'Failed to delete integral file: ' . $file_to_delete );
						}
					}
				}
			}

			// 削除後の最新日付を再計算
			$latest_integral_date = null;
			foreach ( $existing_files as $date => $exists ) {
				$latest_integral_date = max( $latest_integral_date, $date );
			}
		}

		foreach ( $year_range as $year ) {
			$end_date = ( $year == $current_year )
				? new DateTime()
				: DateTime::createFromFormat( 'Y-m-d', "$year-12-31" );

			// 年初 or 計測開始日 or 再開点
			$start_date = DateTime::createFromFormat( 'Y-m-d', "$year-01-01" );
			if ( $start_date < $first_recorded_datetime ) {
				$start_date = clone $first_recorded_datetime;
			}
			if ( $latest_integral_date && $this->wrap_substr( $latest_integral_date, 0, 4 ) == $year ) {
				$start_date = DateTime::createFromFormat( 'Y-m-d', $latest_integral_date )->modify( '+1 day' );
			}

			$need_recreate_from_date = null;
			$integral_data           = array(); // メモリ上で保持する積分データ

			while ( $start_date <= $end_date ) {
				$formatted_date           = $start_date->format( $date_format );
				$daily_integral_file_name = $formatted_date . '_summary_days_access_detail_i.php';
				$daily_integral_file_path = $vw_summary_dir . $daily_integral_file_name;

				// 欠損ファイルを検出した場合、その日付以降は全て再作成対象
				$file_exists    = isset( $existing_files[ $formatted_date ] );
				$should_process = ! $file_exists || ( $need_recreate_from_date && $formatted_date >= $need_recreate_from_date );

				if ( ! $should_process ) {
					$start_date->modify( '+1 day' );
					continue;
				}

				// 欠損ファイルを初めて検出した場合、再作成開始日を記録
				if ( ! $file_exists && ! $need_recreate_from_date ) {
					$need_recreate_from_date = $formatted_date;
				}

				// 年初または計測開始日の場合はリセット
				$is_reset_day = false;
				if ( ( $start_date->format( 'm-d' ) === '01-01' ) || ( $start_date == $first_recorded_datetime ) ) {
					$is_reset_day  = true;
					$integral_data = array();
				}

				// 年初リセット日以外では前回のデータを引き継ぎ
				if ( ! $is_reset_day && empty( $integral_data ) ) {
					// 初回処理時のみ前日ファイルを読み込み
					$previous_date               = ( clone $start_date )->modify( '-1 day' );
					$previous_integral_file_path = $vw_summary_dir . $previous_date->format( $date_format ) . '_summary_days_access_detail_i.php';

					if ( is_readable( $previous_integral_file_path ) ) {
						$integral_data = $this->wrap_unserialize( $this->wrap_get_contents( $previous_integral_file_path ) );
					}
				}

				// days_access_detail.phpに存在する日だけ加算処理、存在しない日は前日の累計データをそのまま保存
				if ( isset( $date_index_map[ $formatted_date ] ) ) {
					$daily_data = $daily_data_list[ $date_index_map[ $formatted_date ] ]['data'];

					// is_newuser_count を埋める
					foreach ( $daily_data as $idxd => $aryd ) {
						foreach ( $aryd as $idxs => $arys ) {
							foreach ( $arys as $idxm => $arym ) {
								foreach ( $arym as $idxc => $aryc ) {
									foreach ( $aryc as $idxn => $aryn ) {
										foreach ( $aryn as $idxq => $aryq ) {
											$daily_data[ $idxd ][ $idxs ][ $idxm ][ $idxc ][ $idxn ][ $idxq ]['is_newuser_count'] = $idxn;
										}
									}
								}
							}
						}
					}

					$integral_data = $this->create_integral( $integral_data, $daily_data );
				} else {
					// 0アクセス日の場合は、前日の累計データをそのまま使用
					$qahm_log->info( $formatted_date . 'のdays_access_detailデータが存在しません。前日の累計データをそのまま使用します。' );
					// $integral_dataは前回ループのデータがそのまま引き継がれる（何もしない）
				}

				// 条件に関係なく積分ファイルを保存（0アクセス日でも前日の累計データで保存）
				// 修正後
				$write_result = $this->wrap_put_contents( $daily_integral_file_path, $this->wrap_serialize( $integral_data ) );
				if ( $write_result === false ) {
					$qahm_log->warning( 'Failed to write integral file: ' . $daily_integral_file_path );
				}

				$start_date->modify( '+1 day' );
			}
		}
	}




	private function create_integral( $integral_data, $new_data ) {
		foreach ( $new_data as $key => $value ) {
			if ( is_array( $value ) ) {
				if ( ! isset( $integral_data[ $key ] ) ) {
					$integral_data[ $key ] = array();
				}
				$integral_data[ $key ] = $this->create_integral( $integral_data[ $key ], $value );
			} else {
				if ( ! isset( $integral_data[ $key ] ) ) {
					$integral_data[ $key ] = 0;
				}

				$integral_data[ $key ] += $value;
			}
		}
		return $integral_data;
	}
	public function delete_integral_summary_file( $summary_type, $from_date, $connect_dir = 'all' ) {
		$data_dir       = $this->get_data_dir_path();
		$view_dir       = $data_dir . 'view/';
		$myview_dir     = $view_dir . ( $connect_dir ?: $this->get_tracking_id() ) . '/';
		$vw_summary_dir = $myview_dir . 'summary/';
		global $wp_filesystem;
		// #1153: 削除対象の per-dir summary に合わせ、同じ識別子の時計で「昨日」を算出（'all' は WP-TZ 短絡）。
		$clock      = QAHM_Time::get_site_clock( $connect_dir ?: $this->get_tracking_id() );
		$yesterday  = $clock->xday_str( -1 );
		$start_date = new DateTime( $from_date );
		$end_date   = new DateTime( $yesterday );

		while ( $start_date <= $end_date ) {
			$deleteday = $start_date->format( 'Y-m-d' );
			$start_date->modify( '+1 day' );
			$integral_summary_file = $vw_summary_dir . $deleteday . '_summary_' . preg_quote( $summary_type ) . '_i.php';
			if ( $wp_filesystem->exists( $integral_summary_file ) ) {
				echo esc_html( $integral_summary_file ) . '<br>';
				$wp_filesystem->delete( $integral_summary_file );
			} else {
				echo esc_html( $integral_summary_file ) . ' not found<br>';
			}
		}
	}


	//QA ZERO start


	public function version_hist_dirlist_mem_reset() {

		$this->version_hist_dirlist_mem = array( 'all' => null );
		return;
	}

	public function view_pv_cache_reset() {

		$this->view_pv_cache = array( 'all' => array() );
		return;
	}

	public function view_pv_cache_load( $max_cache_size = 2147483648, $tracking_id = 'all' ) {

		$data_dir   = $this->get_data_dir_path();
		$view_dir   = $data_dir . 'view/';
		$viewpv_dir = $view_dir . $tracking_id . '/view_pv/';
		$allfiles   = scandir( $viewpv_dir );
		$allfiles   = array_diff( $allfiles, array( '.', '..' ) );

		$this->view_pv_cache[ $tracking_id ] = array();

		//先頭が日付のファイルだけ取り出し
		$allfiles = $this->wrap_array_filter(
			$allfiles,
			function ( $filename ) {
				$date = DateTime::createFromFormat( 'Y-m-d', $this->wrap_substr( $filename, 0, 10 ) );
				return $date !== false;
			}
		);

		//日付順(降順)に並べ替え
		usort(
			$allfiles,
			function ( $a, $b ) {
				$dateA = DateTime::createFromFormat( 'Y-m-d', $this->wrap_substr( $a, 0, 10 ) );
				$dateB = DateTime::createFromFormat( 'Y-m-d', $this->wrap_substr( $b, 0, 10 ) );
				return $dateA < $dateB;
			}
		);

		foreach ( $allfiles as $file ) {
			$this->view_pv_cache[ $tracking_id ][ $file ] = $this->wrap_unserialize( $this->wrap_get_contents( $viewpv_dir . $file ) );
			//メモリ使用量を確認し、max_cache_sizeを超えていたら打ち切り
			if ( memory_get_usage() > $max_cache_size ) {
				break;
			}
		}
	}

	public function get_view_pv_cache() {
		return $this->view_pv_cache;
	}

	// 20240813 koji maruyama for view_pv speed up
	// 重複を排除したIDリストを取得する関数
	public function get_unique_ids( $field, $result ) {
		$ids = array();
		foreach ( $result as $item ) {
			if ( ! $this->wrap_in_array( $item->$field, $ids ) ) {
				$ids[] = $item->$field; // IDをリストに追加
			}
		}
		return $ids; // 重複を排除したリストを返す
	}
	// プレースホルダ付きのIN句を作成する関数
	public function create_in_placeholders( $ids ) {
		return $this->wrap_implode( ',', array_fill( 0, $this->wrap_count( $ids ), '%d' ) ); // %dのリストを作成
	}
	// 20240813 koji maruyama for view_pv speed up end
	//QA ZERO end
}
