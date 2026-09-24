<?php
defined( 'ABSPATH' ) || exit;
/**
 * qa関連のdataをひいてくるAPI
 * qahm-dbクラスを通じてデータを取得する。
 * 初期APIとしては、自プラグインデータ閲覧画面からのajaxリクエストを裁くAPIを想定するが、今後は、他のプラグインやウェブサービス上からデータを取得できるようにする可能性がある
 * （しかし、その場合はバージョン管理が必須。かつ要セキュリティ対応？プラグインはDB直接参照できるのでセキュリティは不要な気もする。そもそもデータは匿名化している）
 * どちらにせよ、初期はバージョン管理が容易な自プラグインのためのAPIだけを作成する。
 * 他所からの参照にはajaxだけでなくURLによるget やdeleteなどのmethod対応も考えられ、WordPressのRESTを使う可能性もあり、別ファイルで管理すべきだと思われる
 * @package qa_heatmap
 */

$GLOBALS['qahm_data_api'] = new QAHM_Data_Api();
class QAHM_Data_Api extends QAHM_Db {
	/**
	 *
	 */
	const NONCE_API = 'api';

	/**
	 * view_pv 廃止 RM4-R2 #1402: get_recent_sessions の PV 取得を allpv 列DB 経路へ切り替えるフラグ。
	 *
	 * true で各日を共有ビルダー QAHM_File_Functions::build_allpv_rows_for_date から読む。期間内（範囲＋翌日）に
	 * 1日でも allpv 未 done（null）／get_pv_log カバレッジ不足があれば、全体を従来 view_pv 行ファイル glob 経路へ
	 * all-or-nothing フォールバック（移行期の view_pv 併存・安全側）。OFF（既定）は glob 経路を逐語温存＝本番ゼロ変化。
	 */
	const RM_R2_RECENT_SESSIONS_ALLPV_ENABLED = false;

	public function __construct() {
		$this->regist_ajax_func( 'ajax_select_data' );
		$this->regist_ajax_func( 'ajax_get_pvterm_start_date' );
		$this->regist_ajax_func( 'ajax_save_siteinfo' );
		$this->regist_ajax_func( 'ajax_save_goal_x' );
		$this->regist_ajax_func( 'ajax_delete_goal_x' );
		$this->regist_ajax_func( 'ajax_get_recent_sessions' );
		$this->regist_ajax_func( 'ajax_get_goals_sessions' );
		$this->regist_ajax_func( 'ajax_url_to_page_id' );
		$this->regist_ajax_func( 'ajax_get_heatmap_cachelist' );
		$this->regist_ajax_func( 'ajax_get_nrd_data' );
		$this->regist_ajax_func( 'ajax_get_ch_data' );
		$this->regist_ajax_func( 'ajax_get_ch_days_data' );
		$this->regist_ajax_func( 'ajax_get_sm_data' );
		$this->regist_ajax_func( 'ajax_get_sm_days_data' );
		$this->regist_ajax_func( 'ajax_get_lp_data' );
		$this->regist_ajax_func( 'ajax_get_gw_data' );
		$this->regist_ajax_func( 'ajax_get_ap_data' );
		$this->regist_ajax_func_public( 'ajax_get_nonce' );
		$this->regist_ajax_func( 'ajax_get_base_html_by_url' );
		$this->regist_ajax_func( 'ajax_generate_ai_report' );
		$this->regist_ajax_func( 'ajax_get_processing_queues' );
		$this->regist_ajax_func( 'ajax_get_completed_queues' );
		$this->regist_ajax_func( 'ajax_cancel_queue' );
		$this->regist_ajax_func( 'ajax_delete_reports' );

		// WordPressはコアの初期化→dbの初期化→その他の初期化（プラグイン含む）といった流れになるので
		// コンストラクタの時点でおそらくwpdbが読み込まれているはず
		// ダメならフックを用いて以下の処理の実行タイミングを変えるべき imai
		global $wpdb;
		$this->prefix = $wpdb->prefix;
	}

	/**
	 * public function
	 */
	public function ajax_select_data() {
		$nonce = $this->wrap_filter_input( INPUT_POST, 'nonce' );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_API ) || $this->is_maintenance() ) {
			http_response_code( 400 );
			die( 'nonce error' );
		}
		// 全パラメーターを取得する
		$table       = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'table' ) );
		$column      = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'select' ) );
		$date_or_id  = mb_strtolower( $this->wrap_trim( $this->wrap_filter_input( INPUT_POST, 'date_or_id' ) ) );
		$count       = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'count' ) );
		$where       = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'where' ) );
		$tracking_id = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'tracking_id' ) ); // zero add

		if ( $count === 'true' || $count == 1 ) {
			$count = true;
		} else {
			$count = false;
		}

		$resary = $this->select_data( $table, $column, $date_or_id, $count, $where, $tracking_id ); //zero add
		header( 'Content-type: application/json; charset=UTF-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
		echo $this->wrap_json_encode( $resary );
		die();
	}
	public function ajax_get_pvterm_start_date() {
		$nonce       = $this->wrap_filter_input( INPUT_POST, 'nonce' );
		$tracking_id = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'tracking_id' ) ); // zero add
		if ( ! wp_verify_nonce( $nonce, self::NONCE_API ) || $this->is_maintenance() ) {
			http_response_code( 400 );
			die( 'nonce error' );
		}
		$res = $this->get_pvterm_start_date( $tracking_id );
		header( 'Content-type: application/json; charset=UTF-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
		echo $this->wrap_json_encode( $res );
		die();
	}

	/**
	 * public function
	 */

	//「サイトの属性」設定
	public function ajax_save_siteinfo() {
		$nonce = $this->wrap_filter_input( INPUT_POST, 'nonce' );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_API ) || $this->is_maintenance() ) {
			http_response_code( 400 );
			die( 'nonce error' );
		}
		$target_customer = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'target_customer' ) );
		$sitetype        = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'sitetype' ) );
		$membership      = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'membership' ) );
		$payment         = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'payment' ) );
		$month_later     = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'month_later' ) );
		$session_goal    = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'session_goal' ) );
		$tracking_id     = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'tracking_id' ) );

		// #1153: 目標日（Nか月後）は暦日なので計測サイトTZで算出。
		$clock          = QAHM_Time::get_site_clock( $tracking_id );
		$goalday        = $clock->xmonth_str( $month_later );
		$goaldaysession = floor( $session_goal / 30 );

		$siteinfo_ary = array(
			'target_customer' => $target_customer,
			'sitetype'        => $sitetype,
			'membership'      => $membership,
			'payment'         => $payment,
			'month_later'     => $month_later,
			'session_goal'    => $session_goal,
			'goalday'         => $goalday,
			'goaldaysession'  => $goaldaysession,
		);
		$savestatus   = $this->update_siteinfo_preferences( $tracking_id, $siteinfo_ary );
		header( 'Content-type: application/json; charset=UTF-8' );
		echo( '{"success":true}' );
		die();
	}

	/**
	 * 指定されたトラッキングIDに基づいてサイト情報の設定を取得します。
	 *
	 * @param string $tracking_id トラッキングID。
	 * @return array トラッキングIDに対応するサイト情報の設定。存在しない場合は空の配列を返します。
	 */
	public function get_siteinfo_preferences( $tracking_id ) {
		$siteinfo_by_tracking_id = array();
		$all_siteinfo            = $this->wrap_get_option( 'siteinfo' );
		if ( $all_siteinfo ) {
			// 旧コードの名残でjsonの時がある。その場合はdecodeして配列で保存し直す
			if ( ! is_array( $all_siteinfo ) ) {
				$all_siteinfo = json_decode( $all_siteinfo, true );
				$this->wrap_update_option( 'siteinfo', $all_siteinfo );
			}
			if ( isset( $all_siteinfo[ $tracking_id ] ) ) {
				$siteinfo_by_tracking_id = $all_siteinfo[ $tracking_id ];
			}
		}
		return $siteinfo_by_tracking_id;
	}

	public function update_siteinfo_preferences( $tracking_id, $siteinfo_by_tracking_id ) {
		$all_siteinfo = $this->wrap_get_option( 'siteinfo' );
		if ( $all_siteinfo ) {
			// 旧コードの名残でjsonの時がある。decodeしたらそのまま配列で保存する
			if ( ! is_array( $all_siteinfo ) ) {
				$all_siteinfo = json_decode( $all_siteinfo, true );
			}
			$all_siteinfo[ $tracking_id ] = $siteinfo_by_tracking_id;
		} else {
			$all_siteinfo = array( $tracking_id => $siteinfo_by_tracking_id );
		}
		return $this->wrap_update_option( 'siteinfo', $all_siteinfo );
	}

	public function ajax_get_base_html_by_url() {
		$base_html = null;
		$nonce     = $this->wrap_filter_input( INPUT_POST, 'nonce' );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_API ) || $this->is_maintenance() ) {
			http_response_code( 400 );
			die( 'nonce error' );
		}
		$pageurl   = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'pageurl' ) );
		$device_id = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'device_id' ) );
		if ( $this->wrap_filter_input( INPUT_POST, 'add_basehref' ) !== null ) {
			$add_basehref = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'add_basehref' ) );
		} else {
			$add_basehref = true;
		}

		$base_html = $this->get_base_html_by_url( $pageurl, $device_id, $add_basehref );
		header( 'Content-type: application/json; charset=UTF-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
		echo $this->wrap_json_encode( $base_html );
		die();
	}

	public function get_base_html_by_url( $pageurl, $device_id, $add_basehref = true ) {

		global $qahm_db;

		$pageid_ary_a = $this->url_to_page_id( $pageurl );
		if ( ! $pageid_ary_a ) {
			return null;
		}
		foreach ( $pageid_ary_a as $page_id_ary ) {
			$page_id = $page_id_ary['page_id'];
			// page_idかつdevice_idから最新のversion_idを求める
			$table_name = 'view_page_version_hist';
			$query      = 'SELECT version_no,base_html FROM ' . $qahm_db->prefix . $table_name . ' WHERE page_id = %d AND device_id = %d';
			//$query      = 'SELECT version_no,base_html FROM ' . $qahm_db->prefix . $table_name . ' WHERE page_id = %d';
			$qa_page_version_hist = $qahm_db->get_results( $qahm_db->prepare( $query, $page_id, $device_id ), ARRAY_A );
			if ( ! $qa_page_version_hist ) {
				return null;
			}
		}
		// 最新のversion_histを取得
		$version_no = null;
		foreach ( $qa_page_version_hist as $hist ) {
			if ( $version_no ) {
				if ( $version_no < (int) $hist['version_no'] ) {
					$version_no = (int) $hist['version_no'];
					$base_html  = $hist['base_html'];
				}
			} else {
					$version_no = (int) $hist['version_no'];
					$base_html  = $hist['base_html'];
			}
		}

		// dbにbase_htmlが存在しない場合は作る
		if ( ! $base_html ) {
			$device_name = $this->device_id_to_device_name( $device_id );
			if ( ! $device_name ) {
				$device_name = 'dsk'; // デフォルト値
			}
			$response = $this->wrap_remote_get( $pageurl, $device_name );

			if ( is_wp_error( $response ) ) {
				return null;
			}
			$base_html = wp_remote_retrieve_body( $response );

			if ( $this->is_zip( $base_html ) ) {
				$temphtml = gzdecode( $base_html );
				if ( $temphtml !== false ) {
					$base_html = $temphtml;
				}
			}
		}

		if ( $add_basehref ) {
			$base_url      = $pageurl;
			$new_base_html = str_replace( '<head>', '<head>' . '<base href="' . $base_url . '">', $base_html );
			return $new_base_html;
		} else {
			return $base_html;
		}
	}


	/**
	 * Validate goal input parameters and resolve pageid_ary.
	 *
	 * Pure input validation — does NOT call get_goals_preferences().
	 * Returns validation result without die() or HTTP response.
	 *
	 * @param array $params Goal parameters (gtitle, gtype, g_goalpage, etc.)
	 * @return array {
	 *     valid: bool,
	 *     error: string (empty if valid),
	 *     goal_data: array (validated goal data),
	 *     pageid_ary: array (resolved page IDs)
	 * }
	 */
	public function validate_goal( $params ) {
		$gtitle          = isset( $params['gtitle'] ) ? $this->alltrim( $params['gtitle'] ) : '';
		$gnum_scale      = isset( $params['gnum_scale'] ) ? $this->alltrim( $params['gnum_scale'] ) : '';
		$gnum_value      = isset( $params['gnum_value'] ) ? $this->alltrim( $params['gnum_value'] ) : '';
		$gtype           = isset( $params['gtype'] ) ? $this->alltrim( $params['gtype'] ) : '';
		$g_goalpage      = isset( $params['g_goalpage'] ) ? $this->alltrim( $params['g_goalpage'] ) : '';
		$g_pagematch     = isset( $params['g_pagematch'] ) ? $this->alltrim( $params['g_pagematch'] ) : '';
		$g_clickpage     = isset( $params['g_clickpage'] ) ? $this->alltrim( $params['g_clickpage'] ) : '';
		$g_eventtype     = isset( $params['g_eventtype'] ) ? $this->alltrim( $params['g_eventtype'] ) : '';
		$g_clickselector = isset( $params['g_clickselector'] ) ? $this->alltrim( $params['g_clickselector'] ) : '';
		$g_eventselector = isset( $params['g_eventselector'] ) ? $this->alltrim( $params['g_eventselector'] ) : '';
		// #1345: gtype_dlevent。key は照合対象の dataLayer キー（CTT は page_location 固定・将来の他キー用に項目として持つ）。
		// values は改行区切りの複数値（いずれか一致で達成）。
		// ★ values に alltrim を使わないこと＝この class の alltrim() は str_replace( ' ', '' ) で
		//   「文字列中のすべての半角スペースを削除する」実装。照合値は記録された値と完全一致させる
		//   必要があるため、値の中身を書き換えてはいけない（正規化は normalize_dlevent_values で行単位に行う）。
		$g_dlkey    = isset( $params['g_dlkey'] ) ? $this->alltrim( $params['g_dlkey'] ) : '';
		$g_dlvalues = isset( $params['g_dlvalues'] ) ? (string) $params['g_dlvalues'] : '';

		$result = array(
			'valid'      => false,
			'error'      => '',
			'goal_data'  => array(),
			'pageid_ary' => array(),
		);

		// Title required
		if ( ! $gtitle ) {
			$result['error'] = 'title_error';
			return $result;
		}

		// Type-specific required fields
		switch ( $gtype ) {
			case 'gtype_page':
				if ( ! $g_goalpage ) {
					$result['error'] = 'required_null';
					return $result;
				}
				break;
			case 'gtype_click':
				if ( ! $g_clickselector ) {
					$result['error'] = 'required_null';
					return $result;
				}
				break;
			case 'gtype_event':
				if ( ! $g_eventselector ) {
					$result['error'] = 'required_null';
					return $result;
				}
				break;
			case 'gtype_dlevent':
				// #1345: 照合する値（改行区切りの絶対 URL）は必須。key は未指定なら page_location を既定にする。
				if ( '' === $g_dlvalues ) {
					$result['error'] = 'required_null';
					return $result;
				}
				if ( '' === $g_dlkey ) {
					$g_dlkey = QAHM_DLEVENT_DEFAULT_KEY;
				}
				// 正規化＝改行で分割し、空行と重複を落とす。1件も残らなければ未入力と同じ扱い。
				$dlvalue_list = $this->normalize_dlevent_values( $g_dlvalues );
				if ( empty( $dlvalue_list ) ) {
					$result['error'] = 'required_null';
					return $result;
				}
				$g_dlvalues = implode( "\n", $dlvalue_list );
				break;
			default:
				$result['error'] = 'required_null';
				return $result;
		}

		$goal_data = array(
			'gtitle'          => $gtitle,
			'gnum_scale'      => $gnum_scale,
			'gnum_value'      => $gnum_value,
			'gtype'           => $gtype,
			'g_goalpage'      => $g_goalpage,
			'g_pagematch'     => $g_pagematch,
			'g_clickpage'     => $g_clickpage,
			'g_eventtype'     => $g_eventtype,
			'g_clickselector' => $g_clickselector,
			'g_eventselector' => $g_eventselector,
			'g_dlkey'         => $g_dlkey,
			'g_dlvalues'      => $g_dlvalues,
			'pageid_ary'      => array(),
		);

		// Resolve pageid_ary
		$pageid_ary = array();
		switch ( $gtype ) {
			case 'gtype_page':
			default:
				$match_prefix = ( 'pagematch_prefix' === $g_pagematch );
				$pageid_ary   = $this->url_to_page_id( $g_goalpage, $match_prefix );
				if ( ! $pageid_ary ) {
					$result['error'] = 'no_page_id';
					return $result;
				}
				break;

			case 'gtype_click':
				$pageid_ary = $this->url_to_page_id( $g_clickpage, false );
				if ( ! $pageid_ary ) {
					$result['error'] = 'no_page_id';
					return $result;
				}
				break;

			case 'gtype_dlevent':
				// #1345: dataLayer の値で判定するため、ゴール定義にページの結び付けを持たない。
				// ★ gtype_event と同じく page_id を持たない形にする。ここで case を持たずに
				//   default（＝gtype_page）へ落ちると、g_goalpage 未入力のまま url_to_page_id() に
				//   渡って no_page_id で保存できない／別ゴールとして解決される事故になる。
				$pageid_ary = array( 'page_id' => null );
				break;

			case 'gtype_event':
				$pageid_ary = array( 'page_id' => null );
				// Delimiter check
				$correct_regex = true;
				if ( $this->wrap_strlen( $g_eventselector ) < 3 ) {
					$correct_regex = false;
				}
				$validModifiers          = 'imsxeADSUXJu';
				$startDelimiter          = $g_eventselector[0];
				$patternWithoutModifiers = $this->wrap_rtrim( $g_eventselector, $validModifiers );
				$endDelimiter            = $patternWithoutModifiers[ $this->wrap_strlen( $patternWithoutModifiers ) - 1 ];
				if ( $startDelimiter !== $endDelimiter ) {
					$correct_regex = false;
				}
				$validDelimiters = '/#~';
				if ( false === $this->wrap_strpos( $validDelimiters, $startDelimiter ) ) {
					$correct_regex = false;
				}
				if ( $this->wrap_strlen( $g_eventselector ) > 3 && '\\' === $g_eventselector[ $this->wrap_strlen( $g_eventselector ) - 2 ] ) {
					$correct_regex = false;
				}
				$invalidAfterDelimiter = '/^[\/#~][?+*{}()[\]]/';
				if ( preg_match( $invalidAfterDelimiter, $g_eventselector ) ) {
					$correct_regex = false;
				}
				if ( false === $correct_regex ) {
					$result['error'] = 'wrong_delimiter';
					return $result;
				}
				break;
		}

		$goal_data['pageid_ary'] = $pageid_ary;
		$result['valid']         = true;
		$result['goal_data']     = $goal_data;
		$result['pageid_ary']    = $pageid_ary;
		return $result;
	}

	/**
	 * Save goal data to DB (options table).
	 *
	 * @param string $tracking_id Tracking ID
	 * @param int    $gid         Goal ID
	 * @param array  $goal_data   Validated goal data
	 * @return bool True on success
	 */
	public function save_goal_to_db( $tracking_id, $gid, $goal_data ) {
		$my_goals_ary         = $this->get_goals_preferences( $tracking_id );
		$my_goals_ary[ $gid ] = $goal_data;
		return $this->update_goals_preferences( $tracking_id, $my_goals_ary );
	}

	/**
	 * Generate goal files (summary files for the goal).
	 *
	 * Does NOT control HTTP response. Caller is responsible for response handling.
	 *
	 * @param string $tracking_id Tracking ID
	 * @param int    $gid         Goal ID
	 * @param array  $goal_data   Validated goal data
	 * @return array { status: string, goal_comp_flg: int }
	 */
	public function generate_goal_files( $tracking_id, $gid, $goal_data ) {
		global $wp_filesystem;
		global $qahm_time;

		$pvterm_both_end = $this->get_pvterm_both_end_date( $tracking_id );
		if ( empty( $pvterm_both_end ) ) {
			return array(
				'status'        => 'no_pvterm',
				'goal_comp_flg' => 0,
			);
		}

		$pvterm_start  = $pvterm_both_end['start'];
		$pvterm_latest = $pvterm_both_end['latest'];

		$data_dir           = $this->get_data_dir_path();
		$log_dir            = $data_dir . 'log/';
		$mylog_dir          = $log_dir . $tracking_id . '/';
		$view_dir           = $data_dir . 'view/';
		$myview_dir         = $view_dir . $tracking_id . '/';
		$myview_summary_dir = $myview_dir . 'summary/';

		// heatmap view用ファイル削除
		$heatmap_view_work_dir = $data_dir . 'heatmap-view-work/';
		$heatmap_view_files    = $wp_filesystem->dirlist( $heatmap_view_work_dir );
		if ( is_array( $heatmap_view_files ) ) {
			foreach ( $heatmap_view_files as $file_name => $file_info ) {
				$file_path = $heatmap_view_work_dir . $file_name;
				if ( ( false !== $this->wrap_strpos( $file_name, $tracking_id ) ) && $wp_filesystem->is_file( $file_path ) ) {
					$wp_filesystem->delete( $file_path );
				}
			}
		}

		$goal_completion_flg = 0;
		$log_ary             = array();

		// log file
		$log_file = $mylog_dir . 'goal_' . $gid . '_file_making.log';
		if ( ! $wp_filesystem->exists( $mylog_dir ) ) {
			$wp_filesystem->mkdir( $mylog_dir );
		}
		if ( ! $wp_filesystem->exists( $log_file ) ) {
			$log_ary['info'] = array(
				'pvterm_start'     => $pvterm_start,
				'going_back_done'  => false,
				'prevent_cron'     => true,
				'set_after_ver2.2' => true,
			);
			$start           = strtotime( $pvterm_start );
			$end             = strtotime( $pvterm_latest );
			while ( $start < $end || gmdate( 'Y-m', $start ) === gmdate( 'Y-m', $end ) ) {
				$yyyymm_key             = gmdate( 'Y-m', $start );
				$log_ary[ $yyyymm_key ] = array(
					'done'  => false,
					'doing' => false,
				);
				$start                  = strtotime( '+1 month', $start );
			}
			$pvterm_start_ym                             = $this->wrap_substr( $pvterm_start, 0, 7 );
			$log_ary[ $pvterm_start_ym ]['starting_end'] = true;
			$this->wrap_put_contents( $log_file, $this->wrap_serialize( $log_ary ) );
		}

		$latest_day   = $pvterm_latest;
		$only_one_day = false;
		$daterange    = 30 - 1;
		$ym_dates     = array();

		if ( $pvterm_start !== $pvterm_latest ) {
			$range_start = $qahm_time->xday_str( -$daterange, $pvterm_latest );
			if ( $qahm_time->xday_num( $range_start, $pvterm_start ) < 0 ) {
				$range_start = $pvterm_start;
				$daterange   = $qahm_time->xday_num( $pvterm_latest, $range_start );
			}
			$range_end = $qahm_time->xday_str( -1, $pvterm_latest );

			$start_dt = new \DateTime( $range_start );
			$end_dt   = new \DateTime( $range_end );
			$end_dt->modify( '+1 day' );
			$period = new \DatePeriod( $start_dt, new \DateInterval( 'P1D' ), $end_dt );
			foreach ( $period as $date ) {
				$monthKey = $date->format( 'Y-m' );
				if ( ! isset( $ym_dates[ $monthKey ] ) ) {
					$ym_dates[ $monthKey ] = array();
				}
				$ym_dates[ $monthKey ][] = $date->format( 'Y-m-d' );
			}
		} else {
			$only_one_day = true;
		}

		if ( $only_one_day ) {
			$latest_day_gdata = $this->fetch_goal_comp_sessions_in_month( $tracking_id, $gid, $goal_data, $latest_day, $latest_day );
			// #1345: null は「まだ判定できない」の意味（列DB 変換待ち等）。serialize(null) を書いて
			// done を立てると、その月は二度と再計算されない。書かずに次の機会へ委ねる。
			if ( is_null( $latest_day_gdata ) ) {
				return $goal_completion_flg;
			}
			if ( ! empty( $latest_day_gdata ) ) {
				$goal_completion_flg = 1;
			}
			$ym                 = $this->wrap_substr( $latest_day, 0, 7 );
			$goal_file          = $myview_summary_dir . $ym . '-01_goal_' . $gid . '_1mon.php';
			$gfile_saved_status = $this->wrap_put_contents( $goal_file, $this->wrap_serialize( $latest_day_gdata ) );

			if ( $gfile_saved_status ) {
				$log_ary[ $ym ]['latest'] = $latest_day;
				$month_last               = $ym . '-' . strval( $qahm_time->month_daynum( $latest_day ) );
				if ( $latest_day === $month_last ) {
					$log_ary[ $ym ]['done'] = true;
				}
			}
		} else {
			$latest_day_gdata = $this->fetch_goal_comp_sessions_in_month( $tracking_id, $gid, $goal_data, $latest_day, $latest_day );
			// #1345: null（判定不能）なら、最新日を含む月の集計が組めない＝ここで打ち切る
			// （下の wrap_array_merge に null が流れると、その月だけ静かに欠けた内容で done になる）
			if ( is_null( $latest_day_gdata ) ) {
				return $goal_completion_flg;
			}
			$latest_day_ym    = $this->wrap_substr( $latest_day, 0, 7 );

			foreach ( $ym_dates as $ym => $dates ) {
				$fromdate = $dates[0];
				$todate   = $dates[ $this->wrap_count( $dates ) - 1 ];
				$gdata    = $this->fetch_goal_comp_sessions_in_month( $tracking_id, $gid, $goal_data, $fromdate, $todate );
				// #1345: null（判定不能）の月はファイルを書かずに飛ばす（上記と同じ理由）
				if ( is_null( $gdata ) ) {
					continue;
				}
				if ( $ym === $latest_day_ym ) {
					$gdata  = $this->wrap_array_merge( $gdata, $latest_day_gdata );
					$todate = $latest_day;
				}
				if ( ! empty( $gdata ) ) {
					$goal_completion_flg = 1;
				}
				$goal_file          = $myview_summary_dir . $ym . '-01_goal_' . $gid . '_1mon.php';
				$gfile_saved_status = $this->wrap_put_contents( $goal_file, $this->wrap_serialize( $gdata ) );

				if ( $gfile_saved_status ) {
					$log_ary[ $ym ]['latest'] = $todate;
					$month_first              = $ym . '-01';
					if ( $fromdate !== $month_first && $fromdate !== $pvterm_start ) {
						$log_ary[ $ym ]['earliest']          = $fromdate;
						$log_ary[ $ym ]['earlier_part_done'] = false;
					}
					$month_last = $ym . '-' . strval( $qahm_time->month_daynum( $month_first ) );
					if ( $fromdate === $month_first && $todate === $month_last ) {
						$log_ary[ $ym ]['done'] = true;
					} elseif ( $fromdate === $pvterm_start && $todate === $month_last ) {
						$log_ary[ $ym ]['done'] = true;
					}
				}
			}
		}

		$log_ary['info']['prevent_cron'] = false;
		$this->wrap_put_contents( $log_file, $this->wrap_serialize( $log_ary ) );

		return array(
			'status'        => 'done',
			'goal_comp_flg' => $goal_completion_flg,
		);
	}

	public function ajax_save_goal_x() {
		$nonce = $this->wrap_filter_input( INPUT_POST, 'nonce' );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_API ) || $this->is_maintenance() ) {
			http_response_code( 400 );
			die( 'nonce error' );
		}

		$gid         = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'gid' ) );
		$tracking_id = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'tracking_id' ) );

		$params = array(
			'gtitle'          => $this->wrap_filter_input( INPUT_POST, 'gtitle' ),
			'gnum_scale'      => $this->wrap_filter_input( INPUT_POST, 'gnum_scale' ),
			'gnum_value'      => $this->wrap_filter_input( INPUT_POST, 'gnum_value' ),
			'gtype'           => $this->wrap_filter_input( INPUT_POST, 'gtype' ),
			'g_goalpage'      => $this->wrap_filter_input( INPUT_POST, 'g_goalpage' ),
			'g_pagematch'     => $this->wrap_filter_input( INPUT_POST, 'g_pagematch' ),
			'g_clickpage'     => $this->wrap_filter_input( INPUT_POST, 'g_clickpage' ),
			'g_eventtype'     => $this->wrap_filter_input( INPUT_POST, 'g_eventtype' ),
			'g_clickselector' => $this->wrap_filter_input( INPUT_POST, 'g_clickselector' ),
			'g_eventselector' => $this->wrap_filter_input( INPUT_POST, 'g_eventselector' ),
			// #1345: gtype_dlevent（dataLayer の値でゴール判定）用。設定画面は gtype に関係なく
			// この2つを送るため、他 gtype のゴールにも g_dlkey（既定値）が入る。読むのは gtype_dlevent のときだけ。
			'g_dlkey'         => $this->wrap_filter_input( INPUT_POST, 'g_dlkey' ),
			'g_dlvalues'      => $this->wrap_filter_input( INPUT_POST, 'g_dlvalues' ),
		);

		// Validate
		$validation = $this->validate_goal( $params );
		if ( ! $validation['valid'] ) {
			// Maintain original error response format for backward compatibility
			$error = $validation['error'];
			if ( 'title_error' === $error ) {
				http_response_code( 401 );
				die( 'title error' );
			}
			if ( 'no_page_id' === $error || 'wrong_delimiter' === $error ) {
				header( 'Content-type: application/json; charset=UTF-8' );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
				echo $this->wrap_json_encode(
					array(
						'status' => 'error',
						'reason' => esc_html( $error ),
					)
				);
				die();
			}
			// required_null
			http_response_code( 402 );
			die( 'required null error' );
		}

		$new_goal = $validation['goal_data'];

		// Save to DB with timing
		$start_dbsave_time = microtime( true );
		$savestatus        = $this->save_goal_to_db( $tracking_id, $gid, $new_goal );

		if ( ! $savestatus ) {
			http_response_code( 500 );
			die( 'options save error' );
		}

		// Goal file generation with in_progress response handling
		ignore_user_abort( true );
		$sent_json_before_finish = false;
		$db_save_time            = microtime( true ) - $start_dbsave_time;

		if ( $db_save_time > 3 ) {
			$sent_json_before_finish = true;
			$json                    = $this->wrap_json_encode( array( 'status' => 'in_progress' ) );
			header( 'Content-type: application/json; charset=UTF-8' );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
			echo $json;
			$this->flush_and_close_connection( strlen( $json ) );
		}

		// Estimate file generation time using first day
		if ( ! $sent_json_before_finish ) {
			global $qahm_time;
			$pvterm_both_end = $this->get_pvterm_both_end_date( $tracking_id );
			if ( ! empty( $pvterm_both_end ) && $pvterm_both_end['start'] !== $pvterm_both_end['latest'] ) {
				$start_filemake_time = microtime( true );
				$this->fetch_goal_comp_sessions_in_month( $tracking_id, $gid, $new_goal, $pvterm_both_end['latest'], $pvterm_both_end['latest'] );
				$time_taken  = microtime( true ) - $start_filemake_time;
				$daterange   = 29;
				$range_start = $qahm_time->xday_str( -$daterange, $pvterm_both_end['latest'] );
				if ( $qahm_time->xday_num( $range_start, $pvterm_both_end['start'] ) < 0 ) {
					$daterange = $qahm_time->xday_num( $pvterm_both_end['latest'], $pvterm_both_end['start'] );
				}
				$estimated_time = round( $time_taken * $daterange, 0 );
				if ( $estimated_time + $db_save_time > 3 ) {
					$sent_json_before_finish = true;
					$json                    = $this->wrap_json_encode(
						array(
							'status'        => 'in_progress',
							'estimated_sec' => $estimated_time,
						)
					);
					header( 'Content-type: application/json; charset=UTF-8' );
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
					echo $json;
					$this->flush_and_close_connection( strlen( $json ) );
				}
			}
		}

		$file_result = $this->generate_goal_files( $tracking_id, $gid, $new_goal );

		if ( $sent_json_before_finish ) {
			die();
		}
		wp_send_json( $file_result );
	}

	public function ajax_delete_goal_x() {
		$nonce = $this->wrap_filter_input( INPUT_POST, 'nonce' );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_API ) || $this->is_maintenance() ) {
			http_response_code( 400 );
			die( 'nonce error' );
		}
		$gid         = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'gid' ) );
		$tracking_id = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'tracking_id' ) );
		if ( is_numeric( $gid ) ) {
			$stat = $this->delete_goal_x( $tracking_id, (int) $gid );
		} else {
			$stat = false;
		}
		if ( $stat ) {
			header( 'Content-type: application/json; charset=UTF-8' );
			echo( '{"save":"success"}' );
			die();
		} else {
			http_response_code( 500 );
			die( 'options save error' );
		}
	}

	public function delete_goal_x( $tracking_id, $gid ) {
		$gary = $this->get_goals_preferences( $tracking_id );
		$nary = array();
		if ( $this->wrap_count( $gary ) < $gid ) {
			return false;
		}
		for ( $iii = 1; $iii <= $this->wrap_count( $gary ); $iii++ ) {
			if ( $iii < $gid ) {
				$nary[ $iii ] = $gary[ $iii ];
			} elseif ( $iii === $gid ) {
				$donothing = true;
			} else {
				$nary[ $iii - 1 ] = $gary[ $iii ];
			}
		}
		$savestatus = $this->update_goals_preferences( $tracking_id, $nary );
		if ( $savestatus ) {
			$this->delete_goal_X_file( $tracking_id, $gid );
			for ( $iii = 1; $iii <= $this->wrap_count( $gary ); $iii++ ) {
				if ( $gid < $iii ) {
					$this->rename_goal_X_file( $tracking_id, $iii, $iii - 1 );
				}
			}
		}
		return $savestatus;
	}

	/**
	 * 指定されたトラッキングIDにおいて設定されたゴールを取得します。
	 *
	 * @param string $tracking_id
	 * @return array ゴール配列。ゴールが設定されていない場合は空の配列。
	 * sample: [ 1 => [ 'gtitle' => 'タイトル', 'gnum_scale' => '1', 'gnum_value' => '1', 'gtype' => 'gtype_page', 'g_goalpage' => 'http://example.com/', 'g_pagematch' => 'pagematch_prefix', 'g_clickpage' => '', 'g_eventtype' => '', 'g_clickselector' => '', 'g_eventselector' => '', 'pageid_ary' => [ 'page_id' => 1 ] ] ]
	 */
	public function get_goals_preferences( $tracking_id ) {
		$goals_by_tracking_id = array();
		$all_goals            = $this->wrap_get_option( 'goals' );
		if ( $all_goals ) {
			// 旧コードの名残でjsonの時がある。その場合は配列に変換して保存し直す
			if ( ! is_array( $all_goals ) ) {
				$all_goals = json_decode( $all_goals, true );
				$this->wrap_update_option( 'goals', $all_goals );
			}
			if ( isset( $all_goals[ $tracking_id ] ) ) {
				$goals_by_tracking_id = $all_goals[ $tracking_id ];
			}
		}
		return $goals_by_tracking_id;
	}

	public function get_goals_json( $tracking_id ) {
		$goals_by_tracking_id = $this->get_goals_preferences( $tracking_id );
		if ( $goals_by_tracking_id ) {
			$goals_json = $this->wrap_json_encode( $goals_by_tracking_id );
		} else {
			$goals_json = null;
		}
		return $goals_json;
	}

	public function update_goals_preferences( $tracking_id, $goals_by_tracking_id ) {
		$all_goals = $this->wrap_get_option( 'goals' );
		if ( $all_goals ) {
			// 旧コードの名残でjsonの時がある。decodeしたらそのまま配列で保存する
			if ( ! is_array( $all_goals ) ) {
				$all_goals = json_decode( $all_goals, true );
			}
			$all_goals[ $tracking_id ] = $goals_by_tracking_id;
		} else {
			$all_goals = array( $tracking_id => $goals_by_tracking_id );
		}
		return $this->wrap_update_option( 'goals', $all_goals );
	}

	public function ajax_get_recent_sessions() {
		$nonce = $this->wrap_filter_input( INPUT_POST, 'nonce' );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_API ) || $this->is_maintenance() ) {
			http_response_code( 400 );
			die( 'nonce error' );
		}
		// 全パラメーターを取得する
		$dateterm    = mb_strtolower( $this->wrap_trim( $this->wrap_filter_input( INPUT_POST, 'date' ) ) );
		$tracking_id = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'tracking_id' ) );
		$resary      = $this->get_recent_sessions( $dateterm, $tracking_id );
		header( 'Content-type: application/json; charset=UTF-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
		echo $this->wrap_json_encode( $resary );
		die();
	}

	public function ajax_get_goals_sessions() {
		$nonce = $this->wrap_filter_input( INPUT_POST, 'nonce' );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_API ) || $this->is_maintenance() ) {
			http_response_code( 400 );
			die( 'nonce error' );
		}
		// 全パラメーターを取得する
		$dateterm    = mb_strtolower( $this->wrap_trim( $this->wrap_filter_input( INPUT_POST, 'date' ) ) );
		$tracking_id = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'tracking_id' ) );
		$resary      = $this->get_goals_sessions( $dateterm, $tracking_id );
		header( 'Content-type: application/json; charset=UTF-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
		echo $this->wrap_json_encode( $resary );
		die();
	}

	public function delete_goal_X_file( $tracking_id, $gid ) {
		global $wp_filesystem;

		// dir
		$data_dir    = $this->get_data_dir_path();
		$view_dir    = $data_dir . 'view/';
		$myview_dir  = $view_dir . $tracking_id . '/';
		$summary_dir = $myview_dir . 'summary/';
		//log dir
		$log_dir   = $data_dir . 'log/';
		$mylog_dir = $log_dir . $tracking_id . '/';

		$searchstr     = '_goal_' . (string) $gid;
		$summary_files = $session_files = $this->wrap_dirlist( $summary_dir );
		if ( $summary_files ) {
			foreach ( $summary_files as $fileobj ) {
				$filename = $fileobj['name'];
				$find     = $this->wrap_strpos( $fileobj['name'], $searchstr );
				if ( $find !== false ) {
					if ( $wp_filesystem->exists( $summary_dir . $filename ) ) {
						$wp_filesystem->delete( $summary_dir . $filename );
					}
				}
			}
		}
		$deleting_log_str = 'goal_' . $gid . '_file_making';
		$log_files        = $this->wrap_dirlist( $mylog_dir );
		if ( $log_files ) {
			foreach ( $log_files as $fileobj ) {
				$filename = $fileobj['name'];
				$find     = $this->wrap_strpos( $fileobj['name'], $deleting_log_str );
				if ( $find !== false ) {
					if ( $wp_filesystem->exists( $mylog_dir . $filename ) ) {
						$wp_filesystem->delete( $mylog_dir . $filename );
					}
				}
			}
		}
	}

	public function rename_goal_X_file( $tracking_id, $old_gid, $new_gid ) {
		global $wp_filesystem;

		// dir
		$data_dir    = $this->get_data_dir_path();
		$view_dir    = $data_dir . 'view/';
		$myview_dir  = $view_dir . $tracking_id . '/';
		$summary_dir = $myview_dir . 'summary/';
		//log dir
		$log_dir   = $data_dir . 'log/';
		$mylog_dir = $log_dir . $tracking_id . '/';

		$old_searchstr = '_goal_' . (string) $old_gid;
		$new_searchstr = '_goal_' . (string) $new_gid;
		$summary_files = $this->wrap_dirlist( $summary_dir );
		if ( $summary_files ) {
			foreach ( $summary_files as $fileobj ) {
				$filename = $fileobj['name'];
				$find     = $this->wrap_strpos( $fileobj['name'], $old_searchstr );
				if ( $find !== false ) {
					$new_filename = str_replace( $old_searchstr, $new_searchstr, $filename );
					if ( $wp_filesystem->exists( $summary_dir . $filename ) ) {
						$wp_filesystem->move( $summary_dir . $filename, $summary_dir . $new_filename );
					}
				}
			}
		}
		$old_log_str = 'goal_' . $old_gid . '_file_making';
		$new_log_str = 'goal_' . $new_gid . '_file_making';
		$log_files   = $this->wrap_dirlist( $mylog_dir );
		if ( $log_files ) {
			foreach ( $log_files as $fileobj ) {
				$filename = $fileobj['name'];
				$find     = $this->wrap_strpos( $fileobj['name'], $old_log_str );
				if ( $find !== false ) {
					$new_filename = str_replace( $old_log_str, $new_log_str, $filename );
					if ( $wp_filesystem->exists( $mylog_dir . $filename ) ) {
						$wp_filesystem->move( $mylog_dir . $filename, $mylog_dir . $new_filename );
					}
				}
			}
		}
	}

	/*
		考え方：

		セッションはview_pvの中に格納されている
		セッションはaccess_time（30分以内のアクセス）のみの情報で求めている

		セッションの最大数は10000、かつ新しい日付順にセッションを取得する必要がある
		ただしセッションに連なるPVをひとまとまりの固めようとすると、access_timeは古い日付順に見ていく必要がある

		よって、セッションを求める際は新しい日付順のループ
		セッションに連なるPVを求める際は古い日付順のループを行う

		速度を考慮し、一度開いたファイルの内容は$file_ary[日付]に格納しておく
		$file_ary[日付]に格納された内容は、セッションを求める際やセッションに連なるPVを求める際に利用する


		returnする配列：
		配列はjsのqahm.createSessionArray関数で加工される（2024/02/27時点でそこでしか使われない）
		よって、そこで必要なパラメータしかreturnしていない


		補足：
		この関数の中身はget_vr_view_session関数に移してもよかったのだが、現時点ではしていない
		これは、取得するセッション数に1万という上限数が決められているため

		get_vr_view_session関数は2024/02/27時点で取得するデータ数の設定ができない
		その部分の仕様決め＆作り込みをしていくよりは、先に進めていった方が良いと判断したため
	*/
	public function get_recent_sessions( $dateterm, $tracking_id = 'all' ) {
		global $wp_filesystem;
		global $qahm_time;

		// date 必須のため、適切な形になっていないならnullを返す
		if ( ! preg_match( '/^date\s*=\s*between\s*([0-9]{4}.[0-9]{2}.[0-9]{2})\s*and\s*([0-9]{4}.[0-9]{2}.[0-9]{2})$/', $dateterm, $date_strings ) ) {
			return null;
		}

		$s_daystr = $date_strings[1];
		$e_daystr = $date_strings[2];
		if ( ! $s_daystr || ! $e_daystr ) {
			return null;
		}
		$s_datetime    = new DateTime( $s_daystr );
		$e_datetime    = new DateTime( $e_daystr );
		$date_iterator = new Qahm_Flexible_Date_Iterator( $s_datetime, $e_datetime, true );

		$view_dir   = $this->get_data_dir_path( 'view' );
		$viewpv_dir = $view_dir . $tracking_id . '/view_pv/';

		// セッションごとのPVデータを格納する配列を初期化
		// session_num はセッションIDの数をカウントする変数。countを使わないのは処理速度を考慮して。
		$sessions    = array();
		$session_num = 0;
		$session_max = 10000;

		// view_pv 廃止 RM4-R2 #1402: ON=期間内（範囲＋翌日）を allpv 共有ビルダーで prefetch。
		// 1日でも null（未 done/カバレッジ不足）なら全体を従来 view_pv glob 経路へ all-or-nothing フォールバック。
		$use_allpv  = false;
		$prefetched = array();
		if ( self::RM_R2_RECENT_SESSIONS_ALLPV_ENABLED ) {
			$prefetched = $this->build_recent_sessions_days_from_allpv( $tracking_id, $s_datetime, $e_datetime );
			if ( null !== $prefetched ) {
				$use_allpv = true;
			} else {
				$prefetched = array();
			}
		}

		// 日付の終了日+1のデータを事前に求めておく
		$file_ary      = array();
		$next_datetime = clone $e_datetime;
		$next_datetime = $next_datetime->modify( '+1 day' );
		$next_day_str  = $next_datetime->format( 'Y-m-d' );
		if ( $use_allpv ) {
			// ON: 翌日分は prefetch 済み（無ければ未設定＝OFF の「ファイル無し」と同義）。
			if ( isset( $prefetched[ $next_day_str ] ) ) {
				$file_ary[ $next_day_str ] = $prefetched[ $next_day_str ];
			}
		} else {
			$file_name_pattern = $next_day_str . '_*';
			$next_day_files    = glob( $viewpv_dir . $file_name_pattern );
			if ( $next_day_files ) {
				foreach ( $next_day_files as $file_path ) {
					$serialized_data           = $this->wrap_get_contents( $file_path );
					$file_ary[ $next_day_str ] = $this->wrap_unserialize( $serialized_data );
				}
			}
		}

		// 日付範囲内でループ処理
		foreach ( $date_iterator as $date_time ) {
			$date_str = $date_time->format( 'Y-m-d' );

			if ( $use_allpv ) {
				// ON: 1日分の全行（共有ビルダー）を一括処理。prefetch に無い日（done で0件）は OFF の「ファイル無し」と同義でスキップ。
				if ( isset( $prefetched[ $date_str ] ) ) {
					$file_ary[ $date_str ] = $prefetched[ $date_str ];
					if ( $this->collect_session_starts( $file_ary[ $date_str ], $sessions, $session_num, $session_max ) ) {
						break;
					}
				}
			} else {
				// OFF: 従来どおり view_pv 行ファイルを glob→unserialize（byte 不変）。

				// 対象の日付で始まるファイル名パターンを生成
				$file_name_pattern = $date_str . '_*';

				// glob関数を使用して、指定されたパターンに一致するファイルのリストを取得
				$matched_files = glob( $viewpv_dir . $file_name_pattern );

				// ファイルからデータを読み込む部分を含むループ内での処理
				foreach ( $matched_files as $file_path ) {
					// ファイルの内容を読み込む
					$serialized_data = $this->wrap_get_contents( $file_path );

					// ファイルの内容を配列に変換
					$file_ary[ $date_str ] = $this->wrap_unserialize( $serialized_data );

					// セッションの起点作成ループ処理。pv=1のデータを配列に追加していく
					if ( $this->collect_session_starts( $file_ary[ $date_str ], $sessions, $session_num, $session_max ) ) {
						break 2;
					}
				}
			}
		}

		// $file_aryのキーだけを逆順で取得
		$file_date_keys = array_keys( $file_ary );
		$file_date_keys = array_reverse( $file_date_keys );

		// 逆順のキーを使って元の配列からデータにアクセス
		foreach ( $file_date_keys as $date ) {
			foreach ( $file_ary[ $date ] as $data ) {
				$reader_id = $data['reader_id'];

				// 同一セッションIDのPVデータをセッション配列に追加
				if ( isset( $sessions[ $reader_id ] ) && (int) $data['pv'] > 1 ) {
					// 配列内を検索してアクセスタイムが30分以内ならセッション追加
					foreach ( $sessions[ $reader_id ] as $session_index => $session ) {
						foreach ( $session as $session_data ) {
							// 30分以内のアクセスならセッション追加
							$access_time      = $session_data['access_time'];
							$next_access_time = $data['access_time'];
							$interval         = $next_access_time - $access_time;
							if ( $interval > 0 && $interval <= 30 * 60 ) { // 30分は1800秒
								$sessions[ $reader_id ][ $session_index ][] = array(
									'reader_id'     => $data['reader_id'],
									'url'           => $data['url'],
									'title'         => $data['title'],
									'device_id'     => $data['device_id'],
									'source_domain' => $data['source_domain'],
									'utm_medium'    => $data['utm_medium'],
									'access_time'   => $data['access_time'],
									'pv'            => $data['pv'],
									'browse_sec'    => $data['browse_sec'],
									'is_last'       => $data['is_last'],
									'is_raw_e'      => $data['is_raw_e'],
								);
								break 2; // 親ループも抜ける
							}
						}
					}
				}
			}
		}

		// ここで$sessions配列を一次元配列に変換
		$ret_ary = array();
		foreach ( $sessions as $session ) {
			foreach ( $session as $session_data ) {
				$ret_ary[] = $session_data;
			}
		}

		return $ret_ary;
	}

	/**
	 * pv=1 の行からセッション起点を収集する（get_recent_sessions の共通ロジック・view_pv 廃止 RM4-R2 #1402）。
	 *
	 * OFF（view_pv glob）/ON（allpv 共有ビルダー）双方から同一ロジックで呼ぶために抽出。従来インライン処理と
	 * 逐語同一（session_max 到達で true を返し呼び出し側がループを抜ける＝旧 break 3 と等価）。
	 *
	 * @param array $rows          1日分の行配列.
	 * @param array $sessions      セッション蓄積（参照渡し・reader_id 別）.
	 * @param int   $session_num   セッション数カウンタ（参照渡し）.
	 * @param int   $session_max   上限.
	 * @return bool session_max に到達したら true（呼び出し側で打ち切り）.
	 */
	private function collect_session_starts( $rows, &$sessions, &$session_num, $session_max ) {
		if ( ! is_array( $rows ) ) {
			return false;
		}
		foreach ( $rows as $data ) {
			$reader_id = $data['reader_id'];

			// 同一セッションIDのPVデータをセッション配列に追加
			if ( (int) $data['pv'] === 1 ) {
				if ( ! isset( $sessions[ $reader_id ] ) ) {
					$sessions[ $reader_id ] = array();
				}
				$sessions[ $reader_id ][] = array(
					array(
						'reader_id'     => $data['reader_id'],
						'url'           => $data['url'],
						'title'         => $data['title'],
						'device_id'     => $data['device_id'],
						'source_domain' => $data['source_domain'],
						'utm_medium'    => $data['utm_medium'],
						'access_time'   => $data['access_time'],
						'pv'            => $data['pv'],
						'browse_sec'    => $data['browse_sec'],
						'is_last'       => $data['is_last'],
						'is_raw_e'      => $data['is_raw_e'],
					),
				);
				++$session_num;
				if ( $session_num >= $session_max ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * get_recent_sessions の ON 経路: 期間内（範囲 s..e ＋翌日 e+1）の各日を allpv 共有ビルダーで prefetch する
	 * （view_pv 廃止 RM4-R2 #1402）。
	 *
	 * 各日 QAHM_File_Functions::build_allpv_rows_for_date を呼ぶ。**1日でも null（allpv 未 done／get_pv_log
	 * カバレッジ不足）なら null を返し、呼び出し側が全体を従来 view_pv glob 経路へ all-or-nothing フォールバック**。
	 * 日の進行は UTC 一貫（gmdate/gmmktime）でなく、OFF と同じ DateTime（サイト暦日）で列挙する＝OFF の
	 * ファイル名日付（サイト暦日）と暦日集合を一致させるため。
	 *
	 * @param string $tracking_id トラッキングID.
	 * @param DateTime $s_datetime 期間開始.
	 * @param DateTime $e_datetime 期間終了.
	 * @return array|null date_str('Y-m-d') => 行配列 のマップ。未 done 等があれば null.
	 */
	private function build_recent_sessions_days_from_allpv( $tracking_id, $s_datetime, $e_datetime ) {
		global $qahm_file_functions;
		if ( ! is_object( $qahm_file_functions ) || ! method_exists( $qahm_file_functions, 'build_allpv_rows_for_date' ) ) {
			return null;
		}

		// 範囲 s..e ＋翌日 e+1（翌日は日跨ぎセッション継続のため OFF も事前読みする）。
		$end_plus1 = clone $e_datetime;
		$end_plus1 = $end_plus1->modify( '+1 day' );

		$prefetched = array();
		$cur        = clone $s_datetime;
		$guard      = 0;
		while ( $cur <= $end_plus1 && $guard < 10000 ) {
			$date_str = $cur->format( 'Y-m-d' );
			$ymd      = $cur->format( 'Ymd' );
			$rows     = $qahm_file_functions->build_allpv_rows_for_date( $tracking_id, $ymd );
			if ( null === $rows ) {
				return null; // all-or-nothing
			}
			// 空配列（done で0件）はその日のキーを作らない＝OFF の「ファイル無し」と同義に揃える。
			if ( ! empty( $rows ) ) {
				$prefetched[ $date_str ] = $rows;
			}
			$cur = $cur->modify( '+1 day' );
			$guard++;
		}

		// guard で打ち切られた（期間を読み切れていない）場合は部分 prefetch を返さず null＝OFF フォールバックへ
		// （RM3-B create_viewpv_csv_rows_from_allpv と対称・サイレントなデータ欠落を防ぐ all-or-nothing の担保）。
		if ( $cur <= $end_plus1 ) {
			return null;
		}

		return $prefetched;
	}

	/**
	 * return array
	 *  $resary = [$gid => [[session1], [session2], ...], ...]
	 */
	public function get_goals_sessions( $dateterm, $tracking_id = 'all' ) {
		global $qahm_time;
		global $wp_filesystem;

		$goals_ary = $this->get_goals_preferences( $tracking_id );

		// 自サイトドメインを取得（チャネル分類で Direct 判定に使用）
		// tracking_id="all" の場合は $domain=null のまま（自サイトドメイン判定はスキップされる）
		$domain = $this->get_own_domain( $tracking_id ); // #1508: 取得処理を一元化
		$resary = array();

		// dir
		$data_dir           = $this->get_data_dir_path();
		$view_dir           = $data_dir . 'view/';
		$allview_dir        = $view_dir . 'all/';
		$myview_dir         = $view_dir . $tracking_id . '/';
		$myview_summary_dir = $myview_dir . 'summary/';

		// dateterm __ s...start, e...end
		if ( ! preg_match( '/^date\s*=\s*between\s*([0-9]{4}.[0-9]{2}.[0-9]{2})\s*and\s*([0-9]{4}.[0-9]{2}.[0-9]{2})$/', $dateterm, $date_strings ) ) {
			return null;
		}
		$s_daystr = $date_strings[1];
		$e_daystr = $date_strings[2];
		if ( ! $s_daystr || ! $e_daystr ) {
			return null;
		}
		$s_unixtime = $qahm_time->str_to_unixtime( $s_daystr . ' 00:00:00' );
		$e_unixtime = $qahm_time->str_to_unixtime( $e_daystr . ' 23:59:59' );

		//Which month files (yyyy-mm-01_goal_X_1mon.php) needed for $dateterm
		$goal_file_name_prefix_ary = array();
		$makeary                   = true;
		$a_date                    = $this->wrap_substr( $s_daystr, 0, 7 ) . '-01';
		$iii                       = 0;
		while ( $makeary ) {
			$goal_file_name_prefix_ary[ $iii ] = $a_date . '_goal_';

			$n_year = (int) $this->wrap_substr( $a_date, 0, 4 );
			$n_monx = (int) $this->wrap_substr( $a_date, 5, 2 );
			if ( $n_monx === 12 ) {
				$next_year = $n_year + 1;
				$next_year = (string) $next_year;
				$next_monx = '01';
			} else {
				$next_year = (string) $n_year;
				$next_monx = $n_monx + 1;
				$next_monx = sprintf( '%02d', $next_monx );
			}
			$n_day_01 = $next_year . '-' . $next_monx . '-01';

			$b_date = $qahm_time->xday_str( -1, $n_day_01 . ' 00:00:00', 'Y-m-d' );
			// period end
			if ( $e_unixtime <= $qahm_time->str_to_unixtime( $b_date . ' 23:59:59' ) ) {
				$makeary = false;
			}

			++$iii;
			$a_date = $n_day_01;
		}

		// $gidごとにゴール達成セッションをまとめていく
		foreach ( $goals_ary as $gid => $goal_ary ) {

			//1. get data from goal files
			$goal_sessions_from_files = array();
			foreach ( $goal_file_name_prefix_ary as $each_mon_goal_file ) {
				$goal_file = $each_mon_goal_file . $gid . '_1mon.php';

				$goal_data_from_each_mon_file = '';
				if ( $wp_filesystem->exists( $myview_summary_dir . $goal_file ) ) {
					$goal_data_from_each_mon_file = $this->wrap_unserialize( $this->wrap_get_contents( $myview_summary_dir . $goal_file ) );
					// ゴール達成が０の時は、空配列が入っている
				}
				if ( ! empty( $goal_data_from_each_mon_file ) ) {
					$goal_sessions_from_files = $this->wrap_array_merge( $goal_sessions_from_files, $goal_data_from_each_mon_file );
				}
			}

			//2. access_timeで絞り込み($goal_sessions_from_filesは月まとめてなので、指定期間内のみを抽出)
			$gs_ary = array();
			foreach ( $goal_sessions_from_files as $each_session ) {
				$lp_utime = $each_session[0]['access_time'];
				if ( $s_unixtime <= $lp_utime && $lp_utime <= $e_unixtime ) {
					$sessions = array();
					foreach ( $each_session as $pv ) {
						if ( isset( $pv['access_time'] ) ) {
							// #498/#1076/#1508: メディア補完＝共通規則（QAHM_Base::derive_medium_for_empty_utm）に一元化。
							// 表・グラフ側と同じ関数を使うことで、ゴール完了数の行キー不一致（#1508）を構造的に防ぐ。
							if ( empty( $pv['utm_medium'] ) ) {
								$source           = isset( $pv['source_domain'] ) ? $pv['source_domain'] : 'direct';
								$pv['utm_medium'] = QAHM_Base::derive_medium_for_empty_utm( $source, $domain );
							}
							$sessions[] = $pv;
						}
					}
					if ( ! empty( $sessions ) ) {
						$gs_ary[] = $sessions;
					}
				}
			}

			//3. $gid別にまとめる
			$resary[ $gid ] = $gs_ary;

		} //end foreach( $goals_ary as $gid => $goal_ary )

		return $resary;
	}

	/**
	 * 基本使わない。
	 * 旧get_goals_sessions関数から、goalファイルを作成する部分を切り取ったもの
	 */
	/*
	public function make_goal_files( $dateterm, $tracking_id="all", $single_goal_id = null ) {
		global $qahm_time;
		global $wp_filesystem;

		$goals_ary = [];
		if ( $single_goal_id ) {
			$all_goals_ary = $this->get_goals_preferences( $tracking_id );
			$goals_ary[$single_goal_id] = $all_goals_ary[$single_goal_id];
		} else {
			$goals_ary = $this->get_goals_preferences( $tracking_id );
		}
		$resary = [];

		// dir
		$data_dir = $this->get_data_dir_path();
		$view_dir = $data_dir . 'view/';
		$allview_dir = $view_dir . 'all/';
		$myview_dir = $view_dir . $tracking_id . '/';
		$myview_summary_dir = $myview_dir . 'summary/';

		// dateterm __ s...start, e...end
		if ( ! preg_match( '/^date\s*=\s*between\s*([0-9]{4}.[0-9]{2}.[0-9]{2})\s*and\s*([0-9]{4}.[0-9]{2}.[0-9]{2})$/', $dateterm, $date_strings ) ) {
			return null;
		}
		$s_daystr   = $date_strings[1];
		$e_daystr   = $date_strings[2];
		if ( ! $s_daystr || ! $e_daystr ) {
			return null;
		}
		$s_unixtime = $qahm_time->str_to_unixtime( $s_daystr . ' 00:00:00' );
		$e_unixtime = $qahm_time->str_to_unixtime( $e_daystr . ' 23:59:59' );

		// goalファイルの保存
		// 　基本ひと月まるごとのデータで作成するが、$current_monthの場合は $days_before日前までのデータで作成する
		$days_before = 1;

		//Which month files (yyyy-mm-01_goal_X_1mon.php) will be for $dateterm
		// __ a...start of month, b...end of month
		$dateterm_goal_files = [];

		$nowmonth = $qahm_time->month();
		$makeary = true;
		$a_date  = $this->wrap_substr( $s_daystr, 0, 7 ) . '-01';
		$iii = 0;
		while( $makeary ) {
			$goal_file_name_prefix  = $a_date . '_goal_';

			$n_year = (int)$this->wrap_substr( $a_date, 0, 4 );
			$n_monx = (int)$this->wrap_substr( $a_date, 5, 2 );
			$current_month = false;
			if ( $nowmonth === $n_monx ) {
				$current_month = true;
			}
			if ( $n_monx === 12 ) {
				$next_year = $n_year + 1;
				$next_year = (string)$next_year;
				$next_monx = '01';
			} else {
				$next_year = (string)$n_year;
				$next_monx = $n_monx + 1;
				$next_monx = sprintf('%02d', $next_monx);
			}
			$n_day_01 = $next_year. '-' . $next_monx . '-01';

			$b_date   = $qahm_time->xday_str( -1, $n_day_01 . ' 00:00:00', 'Y-m-d' );
			if ( $e_unixtime <= $qahm_time->str_to_unixtime( $b_date. ' 23:59:59' ) ) {
				$makeary = false;
			}

			if ( $current_month ) {
				$today_str = $qahm_time->today_str('Y-m-d');
				$b_date = $qahm_time->xday_str( $days_before, $today_str . ' 00:00:00', 'Y-m-d' );
				$makeary = false;
			}

			$between = 'date = between ' . $a_date . ' and ' . $b_date;
			$goal_files_dateranges[$iii] = ['file_name_prefix' => $goal_file_name_prefix, 'between' => $between, 'current' => $current_month, 'a_date'=> $a_date, 'b_date' => $b_date ];
			$iii++;
			$a_date = $n_day_01;
		}

		// make goal file __ {tracking_id}/summary/ goal file
		foreach ( $goals_ary as $gid => $goal_ary ) {
			$goal_sessions_ary = [];
			$pageid_ary = [];

			foreach ( $goal_files_dateranges as $each_mon_goal_file ) {
				$make_goal_file = true;

				$between   = $each_mon_goal_file['between'];
				$goal_file = $each_mon_goal_file['file_name_prefix'] . $gid . '_1mon.php';

				// goal file exist check
				if ( $wp_filesystem->exists( $myview_summary_dir . $goal_file ) ) {
					$file_mtime = $wp_filesystem->mtime( $myview_summary_dir . $goal_file );
					if ( $file_mtime ) {
						if ( $each_mon_goal_file['current'] ) {
							$today  = $qahm_time->today_str();
							$tutime = $qahm_time->str_to_unixtime( $today . ' 00:00:00' );
							if ( $tutime <= $file_mtime ) {
								$make_goal_file = false;
								//$goal_comp_sessions = $this->wrap_unserialize( $this->wrap_get_contents( $myview_summary_dir . $goal_file ) );
							}
						} else {
							$okutime = $qahm_time->str_to_unixtime( $each_mon_goal_file['b_date'] . ' 23:59:59' );
							//1day after is ok
							$okutime = $okutime + ( 3600 * 24 );
							if ( $okutime < $file_mtime ) {
								$make_goal_file = false;
								//$goal_comp_sessions = $this->wrap_unserialize( $this->wrap_get_contents( $myview_summary_dir . $goal_file ) );
							}
						}
					}
				}

				//if no file or is old, make goal file
				// ゴール達成セッションがあってもなくても、goalファイルを作成する。＝無駄にファイル作成工程を踏まないように、「達成０」でもファイルを作成する。
				if ( $make_goal_file ) {
					$goal_comp_sessions = [];

					switch ( $goal_ary['gtype'] ) {

						//page_idから
						case 'gtype_page' :
						case 'gtype_click' :
						default :
							$pageid_ary = $goal_ary['pageid_ary'];
							$pageid_cnt = $this->wrap_count( $pageid_ary );

							// page_idがない場合はnullを入れて、次のgoal($gid)へ　※一応ゴール設定保存の段階でエラーにして保存させないようにしているが、念のため
							if ( $pageid_cnt === 0 ) {
								$resary[$gid] = null;
								continue 2;
							}

							$res = [];
							$where = '';
							if ( $pageid_cnt === 1 ) {
								$page_id  = $pageid_ary[0]['page_id'];
								$where =  'page_id=' . strval($page_id);
							} elseif ( $pageid_cnt > 1 ) {
								$instr = 'in (';
								foreach ( $pageid_ary as $idx => $pageid ) {
									$page_id = $pageid[ 'page_id' ];
									if ( (int)$page_id > 0 ) {
										$instr .= strval($page_id);
									}
									if ( $idx === $pageid_cnt -1 ) {
										$instr .= ')';
									} else {
										$instr .= ',';
									}
								}
								$where = 'page_id ' . $instr;
							}
							//execute
							if ( $where !== '' ) {
								$res = $this->select_data('vr_view_session', '*',  $between, false, $where, $tracking_id);
								// ↑該当データが無い場合は　空配列が返ってくる
							}

							if ( is_array( $res ) ) {
								switch ( $goal_ary['gtype'] ) {
									case 'gtype_page' :
										$goal_comp_sessions = $res;
										//$this->wrap_put_contents( $myview_summary_dir . $goal_file, $this->wrap_serialize( $goal_comp_sessions ) );
									break;

									case 'gtype_click' :
										// click達成のみを抽出
										if ( ! empty( $res ) ) {
											$g_clickselector = $goal_ary['g_clickselector'];
											//$pageid_ary = $goal_ary['pageid_ary']; //既に代入済み

											// ZERO: files only in /view/all/
											$verhist_dir = $allview_dir . 'version_hist/';
											$verhist_idx_dir = $verhist_dir. 'index/';
											$raw_c_dir   = $allview_dir . 'view_pv/' . 'raw_c/';

											$event_session_ary = [];

											//1st page_idから全てのversion_histをオープンし、version_id、セレクタindexを配列に保存
											$vid_sidx_ary = [];
											$idx_base  = '_pageid.php';
											$idx_file = '';
											$mem_index = [];

											//if ( isset( $pageid_ary ) ) { //既に0判定＆回避済み
												foreach ( $pageid_ary as $id_ary ) {
													//indexファイルを探す
													$id_num = (int)$id_ary['page_id'];
													$search_range = 100000;
													$search_max = 10000000;
													if ( $id_num > $search_max ) {
														return null;
													}
													for ( $i = 1; $i < $search_max; $i += $search_range ) {
														if ( $i <= $id_num && $i + $search_range > $id_num ) {
															$idx_file = $i . '-' . ( $i + $search_range - 1 ) . $idx_base;
															break;
														}
													}
													if ( ! isset($mem_index[$idx_file] ) ) {
														$mem_index[$idx_file] = $this->wrap_unserialize( $this->wrap_get_contents( $verhist_idx_dir . $idx_file ) );
													}
													if ( is_array($mem_index[$idx_file][$id_num]) ) {
														foreach ($mem_index[$idx_file][$id_num] as $version_id ) {
															$verhist_filename = $version_id . '_version.php';
															$verhist_file = $this->wrap_get_contents( $verhist_dir . $verhist_filename );
															if ( $verhist_file ) {
																$verhist_ary   = $this->wrap_unserialize( $verhist_file );
																$base_selector = $verhist_ary[ 0 ]->base_selector;
																$base_selector_ary = $this->wrap_explode("\t", $base_selector);
																for ( $sidx = 0; $sidx < $this->wrap_count( $base_selector_ary ); $sidx++ ) {
																	//$shape_fact   = preg_replace('/:nth-of-type\([0-9]*\)/i','', $base_selector_ary[$sidx] );
																	//$shape_search = preg_replace('/:nth-of-type\([0-9]*\)/i','', $g_clickselector );
																	//厳密にする
																	$shape_fact   = $base_selector_ary[$sidx];
																	$shape_search = $g_clickselector;
																	if ( $shape_fact === $shape_search ) {
																		$vid_sidx_ary[] = [$version_id, $sidx];
																		break;
																	}
																}
															}
														}
													}
												}
											//}

											//2nd sessionからversion_idを探し、見つかったら該当のpvidのraw_cをオープン。セレクタindexがあるかチェック
											//page_ids search
											//save versionid and session no
											$raw_c_list = $this->wrap_dirlist( $raw_c_dir );
											if ( is_array( $raw_c_list ) ) {
												$raw_c_ary = [];
												foreach ( $res as $session_ary ) {
													// $access_date = $this->wrap_substr( $session_ary[ 0 ][ 'access_time' ], 0, 10 );
													// raw_c のファイル名は cron-proc.php で WP タイムゾーン (JST) を使い命名されているため、
													// access_time (UNIX秒) も $qahm_time で同じ tz に揃えて strstr マッチさせる。
													$unix_timestamp = $session_ary[0]['access_time'];
													$access_date = $qahm_time->unixtime_to_str( $unix_timestamp, 'Y-m-d' );
													for ( $pid = 0; $pid < $this->wrap_count( $session_ary ); $pid++ ) {
														$pv = $session_ary[ $pid ];
														$vid = $pv[ 'version_id' ];
														for ( $iii = 0; $iii < $this->wrap_count( $vid_sidx_ary ); $iii++ ) {
															if ( (int)$vid === (int)$vid_sidx_ary[ $iii ][ 0 ] ) {
																$pv_id = (int)$pv[ 'pv_id' ];
																for ( $fno = 0; $fno < $this->wrap_count( $raw_c_list ); $fno++ ) {
																	if ( strstr( $raw_c_list[ $fno ][ 'name' ], $access_date ) ) {
																		if ( ! isset($raw_c_ary[ $access_date ] ) ) {
																			$raw_c_ary[$access_date] = $this->wrap_unserialize( $this->wrap_get_contents( $raw_c_dir . $raw_c_list[ $fno ][ 'name' ] ) );
																		}
																		for ( $pvx = 0; $pvx < $this->wrap_count( $raw_c_ary[ $access_date ] ); $pvx++ ) {
																			if ( (int)$raw_c_ary[$access_date][ $pvx ][ 'pv_id' ] === $pv_id ) {
																				$pv_raw_c_str = $raw_c_ary[$access_date][ $pvx ][ 'raw_c' ];
																				$pv_raw_c_ary = $this->wrap_explode( '\n', $pv_raw_c_str );
																				for ( $sidx = 0; $sidx < $this->wrap_count( $pv_raw_c_ary ); $sidx++ ) {
																					$raw_c_events = $this->convert_tsv_to_array( $pv_raw_c_ary[ $sidx ] );
																					if ( (int)$raw_c_events[ 1 ][ 0 ] === (int)$vid_sidx_ary[ $iii ][ 1 ] ) {
																						$event_session_ary[] = $session_ary;
																						break 5;
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
											$goal_comp_sessions = $event_session_ary;
										}
										//$this->wrap_put_contents( $myview_summary_dir . $goal_file, $this->wrap_serialize( $goal_comp_sessions ) );
									break;
								}
							}
						break;

						//pv_idから
						case 'gtype_event' :
								$each_mon_pvid_ary = [];

								// s_daystrからe_daystrのイベントサマリーファイル YYYY-MM-DD_summary_event.php を開ける
								$s_daystr = $each_mon_goal_file['a_date'];
								$e_daystr = $each_mon_goal_file['b_date'];
								$start = new DateTime($s_daystr);
								$end = new DateTime($e_daystr);
								// DatePeriodオブジェクトを作成
								$interval = new DateInterval('P1D'); // 1日のインターバル
								$period = new DatePeriod($start, $interval, $end->modify('+1 day')); // 終了日も含めるために+1日する

								// event type に合わせて、cv_typeとparameter_nameをswitch
								$g_cv_type = '';
								$parameter_name = '';
								switch( $goal_ary['g_eventtype'] ) {
									case 'onclick' :
										$g_cv_type = 'c';
										$parameter_name = 'url';
										break;
									default:
										//$g_cv_type = '';
										//$parameter_name = '';
										break;
								}

								foreach ( $period as $date ) {
									$filename = $myview_summary_dir . $date->format('Y-m-d') . '_summary_event.php';
									if ( $wp_filesystem->exists( $filename ) ) {
										$file_contents = $this->wrap_unserialize($this->wrap_get_contents($filename));
										if ( $file_contents ) {
											foreach ( $file_contents as $content ) {
												foreach ( $content['event'] as $event ) {
													if ( $event['cv_type'] == $g_cv_type && preg_match( $goal_ary['g_eventselector'], $event[$parameter_name] ) ) {
														// pv_idを追加
														foreach ( $event['pv_id'] as $pv_id ) {
															$each_mon_pvid_ary[] = $pv_id;
														}
													}
												}
											}
										}
									}
								}

								$pvid_cnt = $this->wrap_count( $each_mon_pvid_ary );
								$res = [];
								$where = '';
								if ( $pvid_cnt == 1 ) {
									$pv_id  = $each_mon_pvid_ary[0];
									$where =  'pv_id=' . strval($pv_id);
								} elseif ( $pvid_cnt > 1 ) {
									$instr = 'in (';
									foreach ( $each_mon_pvid_ary as $idx => $pvid ) {
										$pv_id = $pvid;
										if ( (int)$pv_id > 0 ) {
											$instr .= strval($pv_id);
										}
										if ( $idx === $pvid_cnt -1 ) {
											$instr .= ')';
										} else {
											$instr .= ',';
										}
									}
									$where = 'pv_id ' . $instr;
								}
								// execute
								if ( $where !== '' ) {
									$res = $this->select_data( 'vr_view_session', '*',  $between, false, $where, $tracking_id );
								}
								if ( is_array( $res )) {
									$goal_comp_sessions = $res;
									//$this->wrap_put_contents( $myview_summary_dir . $goal_file, $this->wrap_serialize( $goal_comp_sessions ) );
								}
						break;

					}

					// 保存
					$this->wrap_put_contents( $myview_summary_dir . $goal_file, $this->wrap_serialize( $goal_comp_sessions ) );

				} // end of if ( $make_goal_file )

			} // end of foreach ( $goal_files_dateranges as $each_mon_goal_file )

		} // end foreach( $goals_ary as $gid => $goal_ary )

	}
	*/


	/**
	 * 同月内の期間で、
	 * goalファイル用のゴール達成セッションを作成する （一つのゴールのみ対象。$tracking_idはall不可。）
	 * 　ゴール達成０でも、空配列を返す。処理不可の場合は、nullを返す。
	 */
	public function fetch_goal_comp_sessions_in_month( $tracking_id, $gid, $goal_ary, $from_date, $to_date ) {
		if ( $this->wrap_substr( $from_date, 0, 7 ) !== $this->wrap_substr( $to_date, 0, 7 ) ) {
			return null;
		}
		global $wp_filesystem;
		global $qahm_time;

		// dir
		$data_dir           = $this->get_data_dir_path();
		$view_dir           = $data_dir . 'view/';
		$allview_dir        = $view_dir . 'all/';
		$verhist_dir        = $allview_dir . 'version_hist/'; // "version hist" files are only in "/view/all/"
		$verhist_idx_dir    = $verhist_dir . 'index/';
		$raw_c_dir          = $allview_dir . 'view_pv/' . 'raw_c/';
		$myview_dir         = $view_dir . $tracking_id . '/';
		$myview_summary_dir = $myview_dir . 'summary/';

		$goal_comp_sessions = array();
		$between            = 'date = between ' . $from_date . ' and ' . $to_date;

		switch ( $goal_ary['gtype'] ) {

			//page_idから
			case 'gtype_page':
			case 'gtype_click':
				$pageid_ary = $goal_ary['pageid_ary'];
				// page_idがない場合、処理しない（できない）※一応ゴール設定保存の段階でエラーにして保存させないようにしているが、念のため
				if ( ! is_array( $pageid_ary ) ) {
					return null;
				}
				$pageid_cnt = $this->wrap_count( $pageid_ary );
				if ( $pageid_cnt === 0 ) {
					return null;
				}

				// #1256 goal QAL化 1a: gtype_page は ColumnDB（allpv）からセッション特定を試みる。
				// ColumnDB 未整備（過去月・変換ラグ）の場合は null が返り、従来経路で処理する。
				if ( 'gtype_page' === $goal_ary['gtype'] ) {
					$columndb_sessions = $this->fetch_goal_page_sessions_via_columndb( $tracking_id, $pageid_ary, $from_date, $to_date, $between );
					if ( null !== $columndb_sessions ) {
						$goal_comp_sessions = $columndb_sessions;
						break;
					}
				}

				// #1256 goal QAL化 1b: gtype_click は ColumnDB（click_event）からセッション特定を試みる。
				// 旧経路の selector 照合は raw_c の gXX 移行（T68, 2026-04-21 sync）後のデータでは機能しない
				//（sidx 前提の照合のため）。本経路は修復を兼ねた移行。
				// ColumnDB 未整備（過去月・変換ラグ）の場合は null が返り、従来経路で処理する
				//（click_event の整備開始 2026-04 ≈ T68 混入時期のため、従来経路は正常だった期間にのみ使われる）。
				if ( 'gtype_click' === $goal_ary['gtype'] ) {
					$columndb_sessions = $this->fetch_goal_click_sessions_via_columndb( $tracking_id, $pageid_ary, $goal_ary['g_clickselector'], $from_date, $to_date, $between );
					if ( null !== $columndb_sessions ) {
						$goal_comp_sessions = $columndb_sessions;
						break;
					}
				}

				$res   = array();
				$where = '';

				if ( $pageid_cnt === 1 ) {
					$page_id = $pageid_ary[0]['page_id'];
					$where   = 'page_id=' . strval( $page_id );
				} elseif ( $pageid_cnt > 1 ) {
					$instr = 'in (';
					foreach ( $pageid_ary as $idx => $pageid ) {
						$page_id = $pageid['page_id'];
						if ( (int) $page_id > 0 ) {
							$instr .= strval( $page_id );
						}
						if ( $idx === $pageid_cnt - 1 ) {
							$instr .= ')';
						} else {
							$instr .= ',';
						}
					}
					$where = 'page_id ' . $instr;
				}
				//execute
				if ( $where !== '' ) {
					$res = $this->select_data( 'vr_view_session', '*', $between, false, $where, $tracking_id );
					// ↑該当データが無い場合は　空配列が返ってくる
				}

				if ( is_array( $res ) ) {
					switch ( $goal_ary['gtype'] ) {
						case 'gtype_page':
							$goal_comp_sessions = $res;
							break;

						case 'gtype_click':
							// click達成のみを抽出
							if ( ! empty( $res ) ) {
								$g_clickselector = $goal_ary['g_clickselector'];
								//$pageid_ary = $goal_ary['pageid_ary']; //既に代入済み

								$event_session_ary = array();

								//1st page_idから全てのversion_histをオープンし、version_id、セレクタindexを配列に保存
								$vid_sidx_ary = array();
								$idx_base     = '_pageid.php';
								$idx_file     = '';
								$mem_index    = array();

								//if ( isset( $pageid_ary ) ) { //既に0判定＆回避済み
								foreach ( $pageid_ary as $id_ary ) {
									//indexファイルを探す
									$id_num       = (int) $id_ary['page_id'];
									$search_range = 100000;
									$search_max   = 10000000;
									if ( $id_num > $search_max ) {
										return null;
									}
									//for ( $i = 1; $i < $search_max; $i += $search_range ) {
									//  if ( $i <= $id_num && $i + $search_range > $id_num ) {
									//      $idx_file = $i . '-' . ( $i + $search_range - 1 ) . $idx_base;
									//      break;
									//  }
									//}
									// $id_numに基づいて$idx_fileを直接決定
									$start_range = floor( $id_num / $search_range ) * $search_range + 1;
									$idx_file    = $start_range . '-' . ( $start_range + $search_range - 1 ) . $idx_base;
									if ( ! isset( $mem_index[ $idx_file ] ) ) {
										$mem_index[ $idx_file ] = $this->wrap_unserialize( $this->wrap_get_contents( $verhist_idx_dir . $idx_file ) );
									}
									if ( isset( $mem_index[ $idx_file ][ $id_num ] ) && is_array( $mem_index[ $idx_file ][ $id_num ] ) ) {
										foreach ( $mem_index[ $idx_file ][ $id_num ] as $version_id ) {
											$verhist_filename = $version_id . '_version.php';
											$verhist_file     = $this->wrap_get_contents( $verhist_dir . $verhist_filename );
											if ( $verhist_file ) {
												$verhist_ary         = $this->wrap_unserialize( $verhist_file );
												$base_selector       = $verhist_ary[0]->base_selector;
												$base_selector_ary   = $this->wrap_explode( "\t", $base_selector );
												$base_selector_count = $this->wrap_count( $base_selector_ary );
												for ( $sidx = 0; $sidx < $base_selector_count; $sidx++ ) {
													// セレクタを正規化して比較
													$shape_fact   = $base_selector_ary[ $sidx ];
													$shape_search = $g_clickselector;

													// 改良版正規化を適用
													$normalized_fact   = $this->normalize_selector( $shape_fact, true );
													$normalized_search = $this->normalize_selector( $shape_search, true );

													if ( $normalized_fact === $normalized_search ) {
														$vid_sidx_ary[] = array( $version_id, $sidx );
														break;
													}
												}
											}
										}
									}
								}
								//}

								//2nd sessionからversion_idを探し、見つかったら該当のpvidのraw_cをオープン。セレクタindexがあるかチェック
								//page_ids search
								//save versionid and session no
								$raw_c_list = $this->wrap_dirlist( $raw_c_dir );
								if ( is_array( $raw_c_list ) ) {
									$raw_c_ary          = array();
									$raw_c_list_count   = $this->wrap_count( $raw_c_list );
									$vid_sidx_ary_count = $this->wrap_count( $vid_sidx_ary );
									foreach ( $res as $session_ary ) {
										// raw_c のファイル名は cron-proc.php で WP タイムゾーン (JST) を使い命名されているため、
										// access_time (UNIX秒) も $qahm_time で同じ tz に揃えて strstr マッチさせる。
										$unix_timestamp    = $session_ary[0]['access_time'];
										$access_date       = $qahm_time->unixtime_to_str( $unix_timestamp, 'Y-m-d' );
										$session_ary_count = $this->wrap_count( $session_ary );
										for ( $pid = 0; $pid < $session_ary_count; $pid++ ) {
											$pv  = $session_ary[ $pid ];
											$vid = $pv['version_id'];
											for ( $iii = 0; $iii < $vid_sidx_ary_count; $iii++ ) {
												if ( (int) $vid === (int) $vid_sidx_ary[ $iii ][0] ) {
													$pv_id = (int) $pv['pv_id'];
													for ( $fno = 0; $fno < $raw_c_list_count; $fno++ ) {
														if ( strstr( $raw_c_list[ $fno ]['name'], $access_date ) ) {
															if ( ! isset( $raw_c_ary[ $access_date ] ) ) {
																$raw_c_ary[ $access_date ] = $this->wrap_unserialize( $this->wrap_get_contents( $raw_c_dir . $raw_c_list[ $fno ]['name'] ) );
															}
															$raw_c_ary_date_count = $this->wrap_count( $raw_c_ary[ $access_date ] );
															for ( $pvx = 0; $pvx < $raw_c_ary_date_count; $pvx++ ) {
																if ( (int) $raw_c_ary[ $access_date ][ $pvx ]['pv_id'] === $pv_id ) {
																	$pv_raw_c_str       = $raw_c_ary[ $access_date ][ $pvx ]['raw_c'];
																	$pv_raw_c_ary       = $this->wrap_explode( "\n", $pv_raw_c_str ); // ['raw_c']の値は実際の改行文字（PHP_EOL）で区切られているため、ダブルクォートを使う
																	$pv_raw_c_ary_count = $this->wrap_count( $pv_raw_c_ary );
																	for ( $sidx = 0; $sidx < $pv_raw_c_ary_count; $sidx++ ) {
																		$raw_c_events = $this->convert_tsv_to_array( $pv_raw_c_ary[ $sidx ] );
																		if ( (int) $raw_c_events[0][0] === (int) $vid_sidx_ary[ $iii ][1] ) {
																			$event_session_ary[] = $session_ary;
																			break 5;
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
								$goal_comp_sessions = $event_session_ary;
							}
							break;
					}
				}
				break;

			// #1345: dataLayer の値から（QAL 経由でセッション特定 → 行の実体化は pv_id 経路を共用）
			case 'gtype_dlevent':
				$dlevent_sessions = $this->fetch_goal_dlevent_sessions_via_qal( $tracking_id, $goal_ary, $from_date, $to_date, $between );
				if ( null === $dlevent_sessions ) {
					// 列DB 未整備・QAL エラー等。従来経路のようなフォールバック先が無い gtype のため、
					// ここは「まだ判定できない」＝ null を返して呼び出し元にゴールファイルを作らせない。
					return null;
				}
				$goal_comp_sessions = $dlevent_sessions;
				break;

			//pv_idから
			case 'gtype_event':
				$each_mon_pvid_ary = array();
				$dates             = array();
				$current_date      = strtotime( $from_date );
				$end_date          = strtotime( $to_date );

				while ( $current_date <= $end_date ) {
					$dates[]      = gmdate( 'Y-m-d', $current_date );
					$current_date = strtotime( '+1 day', $current_date );
				}

				// event type に合わせて、cv_typeとparameter_nameをswitch
				$g_cv_type      = '';
				$parameter_name = '';
				switch ( $goal_ary['g_eventtype'] ) {
					case 'onclick':
						$g_cv_type      = 'c';
						$parameter_name = 'url';
						break;
					default:
						//$g_cv_type = '';
						//$parameter_name = '';
						break;
				}

				foreach ( $dates as $date ) {
					$filename = $myview_summary_dir . $date . '_summary_event.php';
					if ( $wp_filesystem->exists( $filename ) ) {
						$file_contents = $this->wrap_unserialize( $this->wrap_get_contents( $filename ) );
						if ( $file_contents ) {
							foreach ( $file_contents as $content ) {
								foreach ( $content['event'] as $event ) {
									if ( $event['cv_type'] == $g_cv_type && preg_match( $goal_ary['g_eventselector'], $event[ $parameter_name ] ) ) {
										// pv_idを追加
										foreach ( $event['pv_id'] as $pv_id ) {
											$each_mon_pvid_ary[] = $pv_id;
										}
									}
								}
							}
						}
					}
				}

				$pvid_cnt = $this->wrap_count( $each_mon_pvid_ary );
				$res      = array();
				$where    = '';
				if ( $pvid_cnt == 1 ) {
					$pv_id = $each_mon_pvid_ary[0];
					$where = 'pv_id=' . strval( $pv_id );
				} elseif ( $pvid_cnt > 1 ) {
					$instr = 'in (';
					foreach ( $each_mon_pvid_ary as $idx => $pvid ) {
						$pv_id = $pvid;
						if ( (int) $pv_id > 0 ) {
							$instr .= strval( $pv_id );
						}
						if ( $idx === $pvid_cnt - 1 ) {
							$instr .= ')';
						} else {
							$instr .= ',';
						}
					}
					$where = 'pv_id ' . $instr;
				}
				// execute
				if ( $where !== '' ) {
					$res = $this->select_data( 'vr_view_session', '*', $between, false, $where, $tracking_id );
				}
				if ( is_array( $res ) ) {
					$goal_comp_sessions = $res;
				}
				break;

		}

		return $goal_comp_sessions;
	}

	/**
	 * gtype_dlevent の「照合する値」を正規化する（#1345）
	 *
	 * 入力は改行区切りの複数値。改行コードの揺れ（CRLF / CR / LF）を吸収し、
	 * 空行と重複を落として配列で返す。順序は入力順を保つ。
	 *
	 * @param string $raw 改行区切りの値
	 * @return array 正規化済みの値の配列（0件の場合は空配列）
	 */
	private function normalize_dlevent_values( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		// 正規表現を使わずに改行コードを LF へ寄せる（区切りは改行のみ＝値に空白を含む URL も壊さない）
		$normalized = str_replace( array( "\r\n", "\r" ), "\n", $raw );
		$lines      = explode( "\n", $normalized );

		$unique = array();
		foreach ( $lines as $line ) {
			// ★ alltrim() は使わない（この class の alltrim は文字列中の半角スペースを全削除する実装で、
			//   照合値そのものを書き換えてしまう）。行頭・行末の空白だけを落とす。
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			// キーに値を使って重複排除（順序は最初の出現位置を保つ）
			if ( ! isset( $unique[ $line ] ) ) {
				$unique[ $line ] = true;
			}
		}
		return array_keys( $unique );
	}

	/**
	 * QAL が返した datalayer_event 行から、照合に一致した pv_id 集合を作る（#1345）
	 *
	 * `params_json`（記録時の JSON そのもの）を復号し、照合キーの値が対象集合に含まれる行の pv_id を集める。
	 * ★ JSON 文字列同士の完全一致で照合しないのは、キー順・スラッシュのエスケープ有無が
	 *   記録側の実装で揺れうるためで、揺れたときにエラーを出さず静かに 0 件になるのを避ける。
	 *
	 * fetch_goal_dlevent_sessions_via_qal から切り出してあるのは、ハーネスから直接叩いて
	 * 照合の仕様を固定できるようにするため（tasks の issue-1345 配下 harness.php の T2）。
	 *
	 * @param array  $rows    QAL の戻り行（pv_id / params_json を含む）
	 * @param string $dlkey   照合対象のキー名
	 * @param array  $targets 照合する値（正規化済み）
	 * @return array 一致した pv_id をキーにしたマップ（`array( pv_id => true )`）。0件は空配列。
	 *               ★ 1a（fetch_goal_page_sessions_via_columndb）／1b と同じ形にしてある＝
	 *               呼び出し側は `array_keys()` で pv_id 列を取る。3経路で流儀を揃えることで、
	 *               「値の配列」と取り違えて `array_keys()` を二重に掛ける事故を構造的に無くす。
	 */
	private function collect_dlevent_matched_pvids( $rows, $dlkey, $targets ) {
		if ( ! is_array( $rows ) || empty( $targets ) ) {
			return array();
		}
		$target_map    = array_flip( $targets );
		$matched_pvids = array();
		foreach ( $rows as $row ) {
			$pv_id = isset( $row['pv_id'] ) ? (int) $row['pv_id'] : 0;
			if ( $pv_id <= 0 ) {
				continue;
			}
			$params_json = isset( $row['params_json'] ) ? (string) $row['params_json'] : '';
			if ( '' === $params_json ) {
				continue;
			}
			$params = json_decode( $params_json, true );
			if ( ! is_array( $params ) || ! isset( $params[ $dlkey ] ) ) {
				continue;
			}
			// 配列/オブジェクトが記録されていた場合に (string) キャストで warning を出さない
			if ( ! is_scalar( $params[ $dlkey ] ) ) {
				continue;
			}
			$value = (string) $params[ $dlkey ];
			if ( isset( $target_map[ $value ] ) ) {
				$matched_pvids[ $pv_id ] = true;
			}
		}
		return $matched_pvids;
	}

	/**
	 * そのサイトに datalayer_event の列DB データセットが存在するか（#1345）
	 *
	 * ★ 「現在 dataLayer 取り込みが ON か」ではなく「記録が存在し得るか」で判定する。
	 *   ON → OFF に切り替えた環境でも、ON だった期間の列DB は残り続けるため、
	 *   現在値で判定すると過去月の達成まで 0 で確定してしまう（cron は done を立てると
	 *   再計算しない）。QAL 側も dir 不在のとき E_DATA_SOURCE_NOT_FOUND を返すため、
	 *   ここの判定は「QAL がデータ源を見つけられるか」と同じ条件になっている。
	 *
	 * @param string $tracking_id トラッキングID
	 * @return bool データセットがあれば true
	 */
	private function has_dlevent_columndb_dataset( $tracking_id ) {
		global $wp_filesystem;

		$dl_dir = $this->get_data_dir_path() . 'report/' . $tracking_id . '/columns-db/datalayer_event/';
		if ( is_object( $wp_filesystem ) ) {
			return (bool) $wp_filesystem->exists( $dl_dir );
		}
		return is_dir( $dl_dir );
	}

	/**
	 * 期間内の datalayer_event 列DB が「読み切れる状態」かを確かめる（#1345）
	 *
	 * QAL は変換未了の日をエラーにせず「行が無かった」のと同じ形で返すため、そのままだと
	 * 変換ラグ中の月を 0 件で確定させてしまう（cron は done を立てて二度と再計算しない）。
	 * 1a（fetch_goal_page_sessions_via_columndb）と同じ防御をここでも行う。
	 *
	 * @param string $tracking_id トラッキングID
	 * @param string $from_date   開始日（Y-m-d）
	 * @param string $to_date     終了日（Y-m-d）
	 * @return bool 期間内のすべての日が「変換済み」または「元データ自体が無い」なら true
	 */
	private function is_dlevent_columndb_ready( $tracking_id, $from_date, $to_date ) {
		global $wp_filesystem, $qahm_log;

		if ( ! class_exists( 'QAHM_ColumnDB_Manifest' ) || ! is_object( $wp_filesystem ) ) {
			// 判定材料が無い場合は従来どおり進む（QAL 側の結果に委ねる）
			return true;
		}

		$data_dir = $this->get_data_dir_path();
		$dl_dir   = $data_dir . 'report/' . $tracking_id . '/columns-db/datalayer_event/';
		$rawg_dir = $data_dir . 'view/' . $tracking_id . '/view_pv/raw_g/';

		// raw_g が存在する日付の一覧（＝本来なら変換されているはずの日）
		$rawg_dates = array();
		$rawg_list  = $this->wrap_dirlist( $rawg_dir );
		if ( is_array( $rawg_list ) ) {
			foreach ( $rawg_list as $fileobj ) {
				$rawg_dates[ $this->wrap_substr( $fileobj['name'], 0, 10 ) ] = true;
			}
		}

		$cur_unixtime = strtotime( $from_date );
		$end_unixtime = strtotime( $to_date );
		while ( $cur_unixtime <= $end_unixtime ) {
			$date         = gmdate( 'Y-m-d', $cur_unixtime );
			$cur_unixtime = strtotime( '+1 day', $cur_unixtime );

			$date_ymd = str_replace( '-', '', $date );
			$ym       = $this->wrap_substr( $date_ymd, 0, 6 );

			$entry = QAHM_ColumnDB_Manifest::get_day(
				QAHM_ColumnDB_Manifest::load( $dl_dir, $ym ),
				$date_ymd
			);

			if ( null !== $entry ) {
				// 台帳にあるのに done でない＝変換中/中断 → 判定を先送り
				if ( ! QAHM_ColumnDB_Manifest::is_done_entry( $entry ) ) {
					if ( is_object( $qahm_log ) ) {
						$qahm_log->debug( 'goal dlevent: columndb not done. tid:' . $tracking_id . ' date:' . $date_ymd );
					}
					return false;
				}
				continue;
			}

			// 台帳に無い日でも、raw_g があるなら「これから変換される」＝まだ確定させない
			if ( isset( $rawg_dates[ $date ] ) ) {
				if ( is_object( $qahm_log ) ) {
					$qahm_log->debug( 'goal dlevent: raw_g exists but columndb not converted. tid:' . $tracking_id . ' date:' . $date_ymd );
				}
				return false;
			}
		}

		return true;
	}

	/**
	 * gtype_dlevent ゴールの達成セッションを QAL（datalayer_event マテリアル）から特定する（#1345）
	 *
	 * 判断B＝B-2（2026-08-05 今井）に基づき、列DB を直読みせず QAL 経由でセッションを抜く。
	 * セッションの「特定」のみ QAL で行い、行の「実体化」は従来の vr_view_session（pv_id 経路）を
	 * 共用する＝出力行は他 gtype と同一形式になる（1a / 1b / gtype_event と同じ流儀）。
	 *
	 * 照合は「値の完全一致」。列DB は値を辞書 ID で持つため、完全一致なら辞書の逆引きで済む
	 * （前方一致・正規表現は辞書の全走査が必要になるため将来拡張・spec 参照）。
	 *
	 * ★ QAL に渡す time は `Y-m-d` の日付のみ（時刻を持たない）。get_date_range_list() は
	 *   tz でその日付を解釈して YYYYMMDD の一覧を作るため、日付のみを渡す限り tz によって
	 *   対象日がずれることはない（2026-08-06 実読で確認）。
	 *
	 * @param string $tracking_id トラッキングID
	 * @param array  $goal_ary    ゴール定義（g_dlkey / g_dlvalues を含む）
	 * @param string $from_date   取得開始日（Y-m-d、to_date と同月）
	 * @param string $to_date     取得終了日（Y-m-d）
	 * @param string $between     select_data 用の date 条件文字列
	 * @return array|null 達成セッション配列（0件は空配列）。QAL エラー等で特定できない場合は null
	 */
	private function fetch_goal_dlevent_sessions_via_qal( $tracking_id, $goal_ary, $from_date, $to_date, $between ) {
		global $qahm_qal_executor, $qahm_log;

		if ( ! is_object( $qahm_qal_executor ) ) {
			return null;
		}

		$dlkey = ( isset( $goal_ary['g_dlkey'] ) && '' !== $goal_ary['g_dlkey'] ) ? $goal_ary['g_dlkey'] : QAHM_DLEVENT_DEFAULT_KEY;
		$raw_values = isset( $goal_ary['g_dlvalues'] ) ? $goal_ary['g_dlvalues'] : '';
		$targets    = $this->normalize_dlevent_values( $raw_values );
		if ( empty( $targets ) ) {
			// 保存時に弾いているため通常は到達しない。達成0として扱う（null＝判定不能とは区別する）
			return array();
		}

		// ★ 順序に意味がある。ready を先に見ること。
		//   変換が終わっていない日が期間内にあれば、その月を「0件」で確定させない（1a と同じ防御）。
		if ( ! $this->is_dlevent_columndb_ready( $tracking_id, $from_date, $to_date ) ) {
			return null;
		}

		// 記録がそもそも1件も無いサイト（列DB のデータセットが未生成）は、達成0で確定させる。
		// ここを null にすると QAL が E_DATA_SOURCE_NOT_FOUND を返し続け、cron が毎晩この月を
		// やり直すため（一度も取り込みを ON にしていないサイトで無限リトライになる）。
		//
		// ★ 判定材料に「現在の datalayer_import（sitemanage の現在値）」を使ってはいけない。
		//   列DB は過去の記録を保持し続けるので、ON → OFF に切り替えた環境で
		//   「ON だった月の達成」まで 0 で確定してしまう（cron は done を立てると再計算しない）。
		//   見るべきは「その期間に記録データが存在し得るか」＝列DB データセットの有無。
		//
		// ★ この判定を ready より前に置いてもいけない。ON にした初日は
		//   「raw_g はあるが列DB はまだ無い」状態で、0 で確定すると cron は翌日から再開するため
		//   初日の達成が永久に 0 になる。ready が先なら false → null → 翌晩やり直しへ正しく倒れる。
		if ( ! $this->has_dlevent_columndb_dataset( $tracking_id ) ) {
			if ( is_object( $qahm_log ) ) {
				$qahm_log->debug( 'goal dlevent: no datalayer_event dataset. tid:' . $tracking_id . ' term:' . $from_date . '..' . $to_date );
			}
			return array();
		}

		// ★ QAL は offset ページングを持たない（offset は result の禁止キー・executor は常に
		//   先頭から array_slice する）。月まとめで1回取ると、件数が limit を超えた瞬間に
		//   「エラーを出さず静かに欠ける」。そこで **日ごとに QAL を呼び、pv_id を union** する。
		//   QAL 側もどのみち日付ファイル単位で走る（get_date_range_list）ので分割は自然な粒度。
		$fetch_limit = 50000;
		$matched_pvids = array();

		$cur_unixtime = strtotime( $from_date );
		$end_unixtime = strtotime( $to_date );
		while ( $cur_unixtime <= $end_unixtime ) {
			$date         = gmdate( 'Y-m-d', $cur_unixtime );
			$cur_unixtime = strtotime( '+1 day', $cur_unixtime );

			// #1346 が記録する予約イベント名だけを対象にする（GTM 由来の gtm.* は目標判定に使わない）
			$qal = array(
				'tracking_id' => $tracking_id,
				'materials'   => array( array( 'name' => 'datalayer_event' ) ),
				'time'        => array(
					// 日付のみ（時刻なし）＝tz による対象日のずれが起きない形（spec 2-2 参照）
					'start' => $date,
					'end'   => $date,
					'tz'    => 'Asia/Tokyo',
				),
				'make'        => array(
					'goal_dlevent' => array(
						'from'   => array( 'datalayer_event' ),
						'filter' => array(
							// list 形式＝いずれかに一致（IN 相当）。QAL の正式構文
							'event_name' => array( QAHM_DLEVENT_RESERVED_EVENT_NAME ),
						),
						'keep'   => array( 'datalayer_event.pv_id', 'datalayer_event.params_json' ),
					),
				),
				'result'      => array(
					'use'   => 'goal_dlevent',
					'limit' => $fetch_limit,
				),
			);

			$plan = $qahm_qal_executor->qal_build_execute_plan( array( 'qal' => $qal ) );
			if ( ! is_array( $plan ) || isset( $plan['error_code'] ) ) {
				if ( is_object( $qahm_log ) ) {
					$qahm_log->debug( 'goal dlevent: qal_build_execute_plan failed. tid:' . $tracking_id . ' date:' . $date . ' err:' . ( isset( $plan['error_code'] ) ? $plan['error_code'] : 'unknown' ) );
				}
				return null;
			}

			$exec = $qahm_qal_executor->qal_executor( $plan );
			if ( ! is_array( $exec ) || isset( $exec['error_code'] ) ) {
				if ( is_object( $qahm_log ) ) {
					$qahm_log->debug( 'goal dlevent: qal_executor failed. tid:' . $tracking_id . ' date:' . $date . ' err:' . ( isset( $exec['error_code'] ) ? $exec['error_code'] : 'unknown' ) );
				}
				return null;
			}

			// 日単位でも上限に当たったら「静かに欠けた」ことになるので確定させない
			if ( isset( $exec['meta']['total_count'] ) && (int) $exec['meta']['total_count'] > $fetch_limit ) {
				if ( is_object( $qahm_log ) ) {
					$qahm_log->warning( 'goal dlevent: QAL result truncated. tid:' . $tracking_id . ' date:' . $date . ' total:' . (int) $exec['meta']['total_count'] . ' limit:' . $fetch_limit );
				}
				return null;
			}

			$rows = ( isset( $exec['data'] ) && is_array( $exec['data'] ) ) ? $exec['data'] : array();
			// 戻りは 1a/1b と同じ「pv_id をキーにしたマップ」＝ここで素直に union できる
			$matched_pvids += $this->collect_dlevent_matched_pvids( $rows, $dlkey, $targets );
		}

		if ( empty( $matched_pvids ) ) {
			// 達成0は正常系。取りこぼしとの区別を後追いできるよう痕跡を残す（1a と同じ流儀）
			if ( is_object( $qahm_log ) ) {
				$qahm_log->debug( 'goal dlevent: no matching value. tid:' . $tracking_id . ' key:' . $dlkey . ' targets:' . $this->wrap_count( $targets ) . ' term:' . $from_date . '..' . $to_date );
			}
			return array();
		}

		// 行の実体化は従来の pv_id 経路（1a / gtype_event と同じ流儀）に委ねる
		$pvids = array_keys( $matched_pvids );
		if ( 1 === $this->wrap_count( $pvids ) ) {
			$where = 'pv_id=' . strval( $pvids[0] );
		} else {
			$where = 'pv_id in (' . implode( ',', $pvids ) . ')';
		}
		$res = $this->select_data( 'vr_view_session', '*', $between, false, $where, $tracking_id );

		return is_array( $res ) ? $res : null;
	}

	/**
	 * gtype_page ゴールの達成セッションを ColumnDB（allpv）から特定する（Epic #1256 goal QAL化 1a）
	 *
	 * セッションの「特定」のみ ColumnDB の page_id 列スキャンで行い、
	 * 行の「実体化」は従来の vr_view_session（pv_id 経路。index 不使用）を共用する。
	 * これにより出力行は従来経路と同一になり、pageid index への依存だけが外れる。
	 *
	 * @param string $tracking_id トラッキングID
	 * @param array  $pageid_ary  ゴール定義の page_id 配列（[ ['page_id' => N], ... ]）
	 * @param string $from_date   取得開始日（Y-m-d、to_date と同月）
	 * @param string $to_date     取得終了日（Y-m-d）
	 * @param string $between     select_data 用の date 条件文字列
	 * @return array|null 達成セッション配列（0件は空配列）。ColumnDB 未整備等で
	 *                    特定できない場合は null（呼び出し元が従来経路へフォールバック）
	 */
	private function fetch_goal_page_sessions_via_columndb( $tracking_id, $pageid_ary, $from_date, $to_date, $between ) {
		global $wp_filesystem;

		if ( ! class_exists( 'QAHM_ColumnDB_BinaryIO' ) ) {
			return null;
		}

		// columndb-cron と同じパス構成（class-qahm-columndb-cron.php convert_one_date 参照）
		$data_dir  = $this->get_data_dir_path();
		$allpv_dir = $data_dir . 'report/' . $tracking_id . '/columns-db/allpv/';
		if ( ! $wp_filesystem->exists( $allpv_dir ) ) {
			return null;
		}

		// 対象 page_id 集合（従来経路と同じく 0 以下は除外）
		$target_pages = array();
		foreach ( $pageid_ary as $id_ary ) {
			$page_id = isset( $id_ary['page_id'] ) ? (int) $id_ary['page_id'] : 0;
			if ( $page_id > 0 ) {
				$target_pages[ $page_id ] = true;
			}
		}
		if ( empty( $target_pages ) ) {
			return null;
		}

		// view_pv が存在する日付の一覧（ColumnDB 変換ラグ・過去月の検出用）
		// ※ wrap_dirlist は index/ raw_c/ 等のサブディレクトリも返すが、先頭10文字が日付形式に
		//   ならないためキーとして無害（日付キーの照合にのみ使用する）
		$viewpv_dir     = $data_dir . 'view/' . $tracking_id . '/view_pv/';
		$viewpv_dates   = array();
		$viewpv_dirlist = $this->wrap_dirlist( $viewpv_dir );
		if ( is_array( $viewpv_dirlist ) ) {
			foreach ( $viewpv_dirlist as $viewpv_fileobj ) {
				$viewpv_dates[ $this->wrap_substr( $viewpv_fileobj['name'], 0, 10 ) ] = true;
			}
		}

		// 日付ごとに page_id 列をスキャンし、ゴールページに該当する pv_id を収集
		$matched_pvids = array();
		$cur_unixtime  = strtotime( $from_date );
		$end_unixtime  = strtotime( $to_date );
		while ( $cur_unixtime <= $end_unixtime ) {
			$date         = gmdate( 'Y-m-d', $cur_unixtime );
			$cur_unixtime = strtotime( '+1 day', $cur_unixtime );

			$date_ymd = str_replace( '-', '', $date );
			$col_base = $allpv_dir . $this->wrap_substr( $date_ymd, 0, 6 ) . '/allpv_' . $date_ymd . '_';

			// Issue #1279: manifest（変換完了台帳）にエントリがある日は state=done を要求する。
			// converting のまま残った日（変換中/中断）は不完全データの可能性があるため従来経路へ委ねる。
			// load はプロセス内キャッシュされるため、ファイル読みは月 1 回
			$manifest_entry = null;
			if ( class_exists( 'QAHM_ColumnDB_Manifest' ) ) {
				$manifest_entry = QAHM_ColumnDB_Manifest::get_day(
					QAHM_ColumnDB_Manifest::load( $allpv_dir, $this->wrap_substr( $date_ymd, 0, 6 ) ),
					$date_ymd
				);
				if ( null !== $manifest_entry && ! QAHM_ColumnDB_Manifest::is_done_entry( $manifest_entry ) ) {
					return null;
				}
			}

			if ( ! $wp_filesystem->exists( $col_base . 'page_id.php' ) ) {
				if ( isset( $viewpv_dates[ $date ] ) ) {
					// view_pv はあるのに ColumnDB 未生成 → 従来経路に委ねる（取りこぼし防止）
					return null;
				}
				continue; // その日はデータ自体が無い
			}

			$page_col = QAHM_ColumnDB_BinaryIO::read_uint32_array( $col_base . 'page_id.php' );
			if ( false === $page_col ) {
				return null;
			}
			$page_row_cnt = $this->wrap_count( $page_col );
			if ( null !== $manifest_entry && isset( $manifest_entry['rows'] ) && (int) $manifest_entry['rows'] !== $page_row_cnt ) {
				// manifest 記録の行数と実列長の不一致 = 部分欠損や書き込み後のドリフト → 従来経路へ
				return null;
			}
			$hit_offsets  = array();
			foreach ( $page_col as $offset => $page_id ) {
				if ( isset( $target_pages[ (int) $page_id ] ) ) {
					$hit_offsets[] = $offset;
				}
			}
			unset( $page_col );
			if ( empty( $hit_offsets ) ) {
				continue;
			}

			$pvid_col = QAHM_ColumnDB_BinaryIO::read_uint32_array( $col_base . 'pv_id.php' );
			if ( false === $pvid_col || $this->wrap_count( $pvid_col ) !== $page_row_cnt ) {
				// 列ファイル間の行数不一致 = 変換途中（flush 途中）等の不完全データ → 従来経路へ
				return null;
			}
			foreach ( $hit_offsets as $offset ) {
				if ( isset( $pvid_col[ $offset ] ) && (int) $pvid_col[ $offset ] > 0 ) {
					$matched_pvids[ (int) $pvid_col[ $offset ] ] = true;
				}
			}
			unset( $pvid_col );
		}

		if ( empty( $matched_pvids ) ) {
			// ゴール達成0は正常系だが、列データ完全性の問題（TIME_LIMIT 中断等）を後追いできるよう痕跡を残す
			global $qahm_log;
			if ( is_object( $qahm_log ) ) {
				$qahm_log->debug( 'goal columndb path: no matching pv. tid:' . $tracking_id . ' pages:' . implode( ',', array_keys( $target_pages ) ) . ' term:' . $from_date . '..' . $to_date );
			}
			return array();
		}

		// 行の実体化は従来の pv_id 経路（gtype_event と同じ流儀）に委ねる
		$pvids = array_keys( $matched_pvids );
		if ( 1 === $this->wrap_count( $pvids ) ) {
			$where = 'pv_id=' . strval( $pvids[0] );
		} else {
			$where = 'pv_id in (' . implode( ',', $pvids ) . ')';
		}
		$res = $this->select_data( 'vr_view_session', '*', $between, false, $where, $tracking_id );

		return is_array( $res ) ? $res : null;
	}

	/**
	 * gtype_click ゴールの達成セッションを ColumnDB（click_event）から特定する（Epic #1256 goal QAL化 1b）
	 *
	 * ゴールのセレクタ文字列をグローバルセレクタ辞書（QAHM_ColumnDB_Selectors）の全エントリと
	 * normalize_selector で照合して候補 selector_id 群を解決し、click_event の
	 * selector_id / page_id / pv_id 列スキャンで「ゴールページ上で当該セレクタをクリックした pv」を特定する。
	 * 行の実体化は従来の vr_view_session（pv_id 経路）を共用する（1a と同型）。
	 *
	 * 背景: 旧経路（version_hist sidx + raw_c 照合）は raw_c の gXX 移行（T68）後のデータでは
	 * selector 照合が機能しないため、本経路は修復を兼ねる。
	 *
	 * @param string $tracking_id     トラッキングID
	 * @param array  $pageid_ary      ゴール定義の page_id 配列（[ ['page_id' => N], ... ]）
	 * @param string $g_clickselector ゴールのクリックセレクタ文字列
	 * @param string $from_date       取得開始日（Y-m-d、to_date と同月）
	 * @param string $to_date         取得終了日（Y-m-d）
	 * @param string $between         select_data 用の date 条件文字列
	 * @return array|null 達成セッション配列（0件は空配列）。ColumnDB 未整備等で
	 *                    特定できない場合は null（呼び出し元が従来経路へフォールバック）
	 */
	private function fetch_goal_click_sessions_via_columndb( $tracking_id, $pageid_ary, $g_clickselector, $from_date, $to_date, $between ) {
		global $wp_filesystem;

		if ( ! class_exists( 'QAHM_ColumnDB_BinaryIO' ) || ! class_exists( 'QAHM_ColumnDB_Selectors' ) ) {
			return null;
		}
		// tracking_id='all' の click_event はサイト統合辞書（report/all/ 配下）を使うため本経路の対象外
		//（呼び出し元の契約上 all は来ないが、誤用時に誤辞書照合となるのを防ぐ）
		if ( 'all' === $tracking_id || ! is_string( $g_clickselector ) || '' === trim( $g_clickselector ) ) {
			return null;
		}

		// columndb-cron と同じパス構成
		$data_dir = $this->get_data_dir_path();
		$ce_dir   = $data_dir . 'report/' . $tracking_id . '/columns-db/click_event/';
		if ( ! $wp_filesystem->exists( $ce_dir ) ) {
			return null;
		}

		// 対象 page_id 集合（従来経路と同じく 0 以下は除外）
		$target_pages = array();
		foreach ( $pageid_ary as $id_ary ) {
			$page_id = isset( $id_ary['page_id'] ) ? (int) $id_ary['page_id'] : 0;
			if ( $page_id > 0 ) {
				$target_pages[ $page_id ] = true;
			}
		}
		if ( empty( $target_pages ) ) {
			return null;
		}

		// ゴールセレクタ → 候補 selector_id 群（グローバル辞書の全エントリと正規化照合。
		// 旧経路の version_hist base_selector 照合と同じ normalize_selector( x, true ) を両辺に適用）
		$selectors        = new QAHM_ColumnDB_Selectors( $tracking_id );
		$normalized_goal  = $this->normalize_selector( $g_clickselector, true );
		$candidate_ids    = array();
		$dict_entries     = (array) $selectors->get_all_entries();
		$dict_has_entries = ! empty( $dict_entries );
		foreach ( $dict_entries as $selector_id => $selector_str ) {
			if ( $this->normalize_selector( (string) $selector_str, true ) === $normalized_goal ) {
				$candidate_ids[ (int) $selector_id ] = true;
			}
		}
		unset( $dict_entries );
		// 候補ゼロ＝このセレクタは一度もクリックされていない（辞書はクリック発生時に登録されるため）。
		// click_event の存在チェックを通った日についてはゴール達成0が正なので、処理を継続する
		//（辞書破損との区別は後段の selector_id 観測で行う）。

		// raw_c 日次ファイルが存在する日付の一覧（click_event 未変換の検出用）。
		// columndb-cron は「rawc ファイルが無い日」には click_event マーカーを書かないため、
		// 未変換判定は view_pv ではなく rawc の有無（= 変換器と同じ入力、columndb-cron convert_one_date 参照）で行う。
		$rawc_dir   = $data_dir . 'view/' . $tracking_id . '/view_pv/raw_c/';
		$rawc_dates = array();
		$rawc_dirlist = $this->wrap_dirlist( $rawc_dir );
		if ( is_array( $rawc_dirlist ) ) {
			foreach ( $rawc_dirlist as $rawc_fileobj ) {
				$rawc_dates[ $this->wrap_substr( $rawc_fileobj['name'], 0, 10 ) ] = true;
			}
		}

		// 日付ごとに click_event をスキャン
		// ※ click_event_{ymd}_pv_id.php は「rawc があり変換された日」に必ず作成される（クリック0でも空ファイル）。
		//   rawc の無い日にはマーカー自体が作られないため、未変換判定は上の rawc 有無と組で行う。
		$matched_pvids = array();
		$saw_click_row = false;
		$cur_unixtime  = strtotime( $from_date );
		$end_unixtime  = strtotime( $to_date );
		while ( $cur_unixtime <= $end_unixtime ) {
			$date         = gmdate( 'Y-m-d', $cur_unixtime );
			$cur_unixtime = strtotime( '+1 day', $cur_unixtime );

			$date_ymd = str_replace( '-', '', $date );
			$col_base = $ce_dir . $this->wrap_substr( $date_ymd, 0, 6 ) . '/click_event_' . $date_ymd . '_';

			// Issue #1279: manifest（変換完了台帳）にエントリがある日は state=done を要求する。
			// converting のまま残った日（変換中/中断）は不完全データの可能性があるため従来経路へ委ねる
			$manifest_entry = null;
			if ( class_exists( 'QAHM_ColumnDB_Manifest' ) ) {
				$manifest_entry = QAHM_ColumnDB_Manifest::get_day(
					QAHM_ColumnDB_Manifest::load( $ce_dir, $this->wrap_substr( $date_ymd, 0, 6 ) ),
					$date_ymd
				);
				if ( null !== $manifest_entry && ! QAHM_ColumnDB_Manifest::is_done_entry( $manifest_entry ) ) {
					return null;
				}
			}

			if ( ! $wp_filesystem->exists( $col_base . 'pv_id.php' ) ) {
				if ( isset( $rawc_dates[ $date ] ) ) {
					// rawc はあるのに click_event 未変換 → 従来経路に委ねる（取りこぼし防止）
					return null;
				}
				continue; // その日はクリックデータ自体が無い（変換器も対象外とする日）
			}

			$pvid_col = QAHM_ColumnDB_BinaryIO::read_uint32_array( $col_base . 'pv_id.php' );
			if ( false === $pvid_col ) {
				return null;
			}
			$row_cnt = $this->wrap_count( $pvid_col );
			if ( null !== $manifest_entry && isset( $manifest_entry['rows'] ) && (int) $manifest_entry['rows'] !== $row_cnt ) {
				// manifest 記録の行数と実列長の不一致 = 部分欠損や書き込み後のドリフト → 従来経路へ
				return null;
			}
			if ( 0 === $row_cnt ) {
				if ( isset( $rawc_dates[ $date ] ) ) {
					// rawc が存在するのに変換行が0 = 旧形式（T68以前）rawc のバックログ変換で
					// 行がスキップされた日（または allpv 不可用時の空マーカー）。
					// この期間は旧経路（sidx 照合）が正しく扱えるため、月ごと従来経路へ委ねる。
					// ※ post-T68 のクリック0の日は rawc 日次ファイル自体が作られない（→マーカーも無く
					//   上の continue 側に入る）ため、正常なクリック0日と確実に区別できる。
					return null;
				}
				continue; // クリックデータの無い日（rawc 無し・マーカーは allpv 経由等で作成）
			}

			$page_col = QAHM_ColumnDB_BinaryIO::read_uint32_array( $col_base . 'page_id.php' );
			$sel_col  = QAHM_ColumnDB_BinaryIO::read_uint32_array( $col_base . 'selector_id.php' );
			if ( false === $page_col || false === $sel_col
				|| $this->wrap_count( $page_col ) !== $row_cnt || $this->wrap_count( $sel_col ) !== $row_cnt ) {
				// 列ファイル間の行数不一致 = 変換途中等の不完全データ → 従来経路へ
				return null;
			}
			for ( $iii = 0; $iii < $row_cnt; $iii++ ) {
				$selector_id = (int) $sel_col[ $iii ];
				if ( 0 === $selector_id ) {
					// selector_id=0 は gXX 形式でない raw_c（T68 以前のバックログ変換等）の痕跡。
					// この期間は旧経路（sidx 照合）が正しく扱えるため、月ごと従来経路へ委ねる。
					return null;
				}
				$saw_click_row = true;
				if ( isset( $target_pages[ (int) $page_col[ $iii ] ] )
					&& isset( $candidate_ids[ $selector_id ] )
					&& (int) $pvid_col[ $iii ] > 0 ) {
					$matched_pvids[ (int) $pvid_col[ $iii ] ] = true;
				}
			}
			unset( $pvid_col, $page_col, $sel_col );
		}

		// 辞書不整合ガード: click 行（selector_id>=1）が存在するのに辞書が空 = 辞書ファイルの欠損/破損。
		// 候補解決が成立しないまま達成0を焼き込まないよう、従来経路へ委ねる。
		if ( $saw_click_row && ! $dict_has_entries ) {
			return null;
		}

		if ( empty( $matched_pvids ) ) {
			// ゴール達成0は正常系だが、後追いできるよう痕跡を残す
			global $qahm_log;
			if ( is_object( $qahm_log ) ) {
				$qahm_log->debug( 'goal columndb click path: no matching pv. tid:' . $tracking_id . ' selector_candidates:' . implode( ',', array_keys( $candidate_ids ) ) . ' term:' . $from_date . '..' . $to_date );
			}
			return array();
		}

		// 行の実体化は従来の pv_id 経路（1a / gtype_event と同じ流儀）に委ねる
		$pvids = array_keys( $matched_pvids );
		if ( 1 === $this->wrap_count( $pvids ) ) {
			$where = 'pv_id=' . strval( $pvids[0] );
		} else {
			$where = 'pv_id in (' . implode( ',', $pvids ) . ')';
		}
		$res = $this->select_data( 'vr_view_session', '*', $between, false, $where, $tracking_id );

		return is_array( $res ) ? $res : null;
	}

	/**
	 * 旧goalファイルから、「クリック」ゴール達成セッションを抽出する
	 */
	public function extract_click_goal_comp_sessions( $tracking_id, $gid, $goal_ary, $from_date, $to_date, $is_whole_month = true, $goal_file_path = null ) {
		if ( $this->wrap_substr( $from_date, 0, 7 ) !== $this->wrap_substr( $to_date, 0, 7 ) ) {
			return null;
		}
		if ( $goal_ary['gtype'] !== 'gtype_click' ) {
			return null;
		}
		if ( is_null( $goal_file_path ) ) {
			return null;
		}
		global $wp_filesystem;
		global $qahm_time;

		$goal_comp_sessions = array();
		$between            = 'date = between ' . $from_date . ' and ' . $to_date;

		$pageid_ary = $goal_ary['pageid_ary'];
		// page_idがない場合、処理しない（できない）※一応ゴール設定保存の段階でエラーにして保存させないようにしているが、念のため
		if ( ! is_array( $pageid_ary ) ) {
			return null;
		}
		$pageid_cnt = $this->wrap_count( $pageid_ary );
		if ( $pageid_cnt === 0 ) {
			return null;
		}

		$res   = array();
		$where = '';

		$res = $this->wrap_unserialize( $this->wrap_get_contents( $goal_file_path ) );
		if ( is_array( $res ) ) {
			// click達成のみを抽出
			if ( ! empty( $res ) ) {
				$g_clickselector = $goal_ary['g_clickselector'];
				//$pageid_ary = $goal_ary['pageid_ary']; //既に代入済み

				// dir __"version hist" files for Zero are only in "/view/all/"
				$data_dir        = $this->get_data_dir_path();
				$allview_dir     = $data_dir . 'view/all/';
				$verhist_dir     = $allview_dir . 'version_hist/';
				$verhist_idx_dir = $verhist_dir . 'index/';
				$raw_c_dir       = $allview_dir . 'view_pv/' . 'raw_c/';

				$event_session_ary = array();

				//1st page_idから全てのversion_histをオープンし、version_id、セレクタindexを配列に保存
				$vid_sidx_ary = array();
				$idx_base     = '_pageid.php';
				$idx_file     = '';
				$mem_index    = array();

				//if ( isset( $pageid_ary ) ) { //既に0判定＆回避済み
				foreach ( $pageid_ary as $id_ary ) {
					//indexファイルを探す
					$id_num       = (int) $id_ary['page_id'];
					$search_range = 100000;
					$search_max   = 10000000;
					if ( $id_num > $search_max ) {
						return null;
					}
					for ( $i = 1; $i < $search_max; $i += $search_range ) {
						if ( $i <= $id_num && $i + $search_range > $id_num ) {
							$idx_file = $i . '-' . ( $i + $search_range - 1 ) . $idx_base;
							break;
						}
					}
					if ( ! isset( $mem_index[ $idx_file ] ) ) {
						$mem_index[ $idx_file ] = $this->wrap_unserialize( $this->wrap_get_contents( $verhist_idx_dir . $idx_file ) );
					}
					if ( is_array( $mem_index[ $idx_file ][ $id_num ] ) ) {
						foreach ( $mem_index[ $idx_file ][ $id_num ] as $version_id ) {
							$verhist_filename = $version_id . '_version.php';
							$verhist_file     = $this->wrap_get_contents( $verhist_dir . $verhist_filename );
							if ( $verhist_file ) {
								$verhist_ary       = $this->wrap_unserialize( $verhist_file );
								$base_selector     = $verhist_ary[0]->base_selector;
								$base_selector_ary = $this->wrap_explode( "\t", $base_selector );
								for ( $sidx = 0; $sidx < $this->wrap_count( $base_selector_ary ); $sidx++ ) {
									//$shape_fact   = preg_replace('/:nth-of-type\([0-9]*\)/i','', $base_selector_ary[$sidx] );
									//$shape_search = preg_replace('/:nth-of-type\([0-9]*\)/i','', $g_clickselector );
									//厳密にする
									$shape_fact   = $base_selector_ary[ $sidx ];
									$shape_search = $g_clickselector;
									if ( $shape_fact === $shape_search ) {
										$vid_sidx_ary[] = array( $version_id, $sidx );
										break;
									}
								}
							}
						}
					}
				}
				//}

				//2nd sessionからversion_idを探し、見つかったら該当のpvidのraw_cをオープン。セレクタindexがあるかチェック
				//page_ids search
				//save versionid and session no
				$raw_c_list = $this->wrap_dirlist( $raw_c_dir );
				if ( is_array( $raw_c_list ) ) {
					$raw_c_ary = array();
					foreach ( $res as $session_ary ) {
						// $access_date = $this->wrap_substr( $session_ary[ 0 ][ 'access_time' ], 0, 10 );
						$unix_timestamp = $session_ary[0]['access_time'];
						$access_date    = gmdate( 'Y-m-d', $unix_timestamp );
						for ( $pid = 0; $pid < $this->wrap_count( $session_ary ); $pid++ ) {
							$pv  = $session_ary[ $pid ];
							$vid = $pv['version_id'];
							for ( $iii = 0; $iii < $this->wrap_count( $vid_sidx_ary ); $iii++ ) {
								if ( (int) $vid === (int) $vid_sidx_ary[ $iii ][0] ) {
									$pv_id = (int) $pv['pv_id'];
									for ( $fno = 0; $fno < $this->wrap_count( $raw_c_list ); $fno++ ) {
										if ( strstr( $raw_c_list[ $fno ]['name'], $access_date ) ) {
											if ( ! isset( $raw_c_ary[ $access_date ] ) ) {
												$raw_c_ary[ $access_date ] = $this->wrap_unserialize( $this->wrap_get_contents( $raw_c_dir . $raw_c_list[ $fno ]['name'] ) );
											}
											for ( $pvx = 0; $pvx < $this->wrap_count( $raw_c_ary[ $access_date ] ); $pvx++ ) {
												if ( (int) $raw_c_ary[ $access_date ][ $pvx ]['pv_id'] === $pv_id ) {
													$pv_raw_c_str = $raw_c_ary[ $access_date ][ $pvx ]['raw_c'];
													$pv_raw_c_ary = $this->wrap_explode( "\n", $pv_raw_c_str );
													for ( $sidx = 0; $sidx < $this->wrap_count( $pv_raw_c_ary ); $sidx++ ) {
														$raw_c_events = $this->convert_tsv_to_array( $pv_raw_c_ary[ $sidx ] );
														if ( (int) $raw_c_events[1][0] === (int) $vid_sidx_ary[ $iii ][1] ) {
															$event_session_ary[] = $session_ary;
															break 5;
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
				$goal_comp_sessions = $event_session_ary;
			}
		}

		return $goal_comp_sessions;
	}


	public function ajax_url_to_page_id() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_API ) ) {
			http_response_code( 400 );
			die( 'nonce error' );
		}

		$url          = $this->wrap_filter_input( INPUT_POST, 'url' );
		$prefix_match = $this->wrap_filter_input( INPUT_POST, 'prefix' );
		if ( ! $url ) {
			die();
		}
		if ( $prefix_match ) {
			$match_prefix = false;
			if ( $prefix_match === 'pagematch_prefix' ) {
				$match_prefix = true;
			}
			$res = $this->url_to_page_id( $url, $match_prefix );
		} else {
			$res = $this->url_to_page_id( $url );
		}
		if ( $res ) {
			if ( $prefix_match ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
				echo $this->wrap_json_encode( $res );
			} else {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
				echo $this->wrap_json_encode( $res[0]['page_id'] . 'id' );
			}
		} else {
			echo esc_html( (string) $res ) . 'error';
		}
		die();
	}

	public function url_to_page_id( $url, $prefix_match = false ) {
		global $qahm_db;
		global $wpdb;

		$url1 = mb_strtolower( $url );
		if ( $prefix_match ) {
			// 前方一致
			$query = 'SELECT page_id FROM ' . $qahm_db->prefix . 'qa_pages WHERE url LIKE %s';
			$res   = $qahm_db->get_results( $qahm_db->prepare( $query, $wpdb->esc_like( $url1 ) . '%' ), ARRAY_A );
		} else {
			// スラッシュあり、なしの両方を検索
			if ( $this->wrap_substr( $url1, -1 ) === '/' ) {
				$url2 = $this->wrap_rtrim( $url1, '/' );
			} else {
				$url2 = $url1 . '/';
			}
			$query = 'SELECT page_id FROM ' . $qahm_db->prefix . 'qa_pages WHERE url = BINARY %s OR url = BINARY %s';
			$res   = $qahm_db->get_results( $qahm_db->prepare( $query, $url1, $url2 ), ARRAY_A );
		}

		return $res;
	}

	//いつかAPIのために作りたい
	public function summary_data( $table, $dimensions, $metrics, $date_or_id, $count = false, $where = '' ) {

		//table名の補完
		if ( $this->wrap_strpos( $table, $this->prefix ) === false ) {
			$table = $this->prefix . $table;
		}
	}

	//暫定的に専用配列を返す。いつかAPIで吸収したい。
	// new repeat / device table用
	public function ajax_get_nrd_data() {
		$nonce = $this->wrap_filter_input( INPUT_POST, 'nonce' );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_API ) || $this->is_maintenance() ) {
			http_response_code( 406 );
			die( 'nonce error' );
		}
		// 全パラメーターを取得する
		$dateterm    = mb_strtolower( $this->wrap_trim( $this->wrap_filter_input( INPUT_POST, 'date' ) ) );
		$tracking_id = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'tracking_id' ) );
		//$resary = $this->get_nrd_data( $dateterm, $tracking_id );
		$resary = $this->get_nrd_data_by_sub_summary( $dateterm, $tracking_id );
		header( 'Content-type: application/json; charset=UTF-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
		echo $this->wrap_json_encode( $resary );
		die();
	}

	public function get_nrd_data( $dateterm, $tracking_id = 'all' ) {

		//make new/repeat device array
		$sad_ary = $this->select_data( 'summary_days_access_detail', '*', $dateterm, false, '', $tracking_id );
		$nrd_ary = array();

		$nrd_ary[] = ( array( 'New Visitor', 'desktop', 0, 0, 0, 0, 0, 0 ) );
		$nrd_ary[] = ( array( 'New Visitor', 'tablet', 0, 0, 0, 0, 0, 0 ) );
		$nrd_ary[] = ( array( 'New Visitor', 'mobile', 0, 0, 0, 0, 0, 0 ) );
		$nrd_ary[] = ( array( 'Returning Visitor', 'desktop', 0, 0, 0, 0, 0, 0 ) );
		$nrd_ary[] = ( array( 'Returning Visitor', 'tablet', 0, 0, 0, 0, 0, 0 ) );
		$nrd_ary[] = ( array( 'Returning Visitor', 'mobile', 0, 0, 0, 0, 0, 0 ) );

			// new/repeat device report
		$maxcnt = $this->wrap_count( $sad_ary );
		for ( $iii = 0; $iii < $maxcnt; $iii++ ) {

			if ( $sad_ary[ $iii ]['is_newuser'] ) {
				switch ( $sad_ary[ $iii ]['device_id'] ) {
					case 1:
						$nrd_ary[0][2] += $sad_ary[ $iii ]['user_count'];
						$nrd_ary[0][3] += $sad_ary[ $iii ]['is_newuser'];
						$nrd_ary[0][4] += $sad_ary[ $iii ]['session_count'];
						$nrd_ary[0][5] += $sad_ary[ $iii ]['bounce_count'];
						$nrd_ary[0][6] += $sad_ary[ $iii ]['pv_count'];
						$nrd_ary[0][7] += $sad_ary[ $iii ]['time_on_page'];
						break;

					case 2:
						$nrd_ary[1][2] += $sad_ary[ $iii ]['user_count'];
						$nrd_ary[1][3] += $sad_ary[ $iii ]['is_newuser'];
						$nrd_ary[1][4] += $sad_ary[ $iii ]['session_count'];
						$nrd_ary[1][5] += $sad_ary[ $iii ]['bounce_count'];
						$nrd_ary[1][6] += $sad_ary[ $iii ]['pv_count'];
						$nrd_ary[1][7] += $sad_ary[ $iii ]['time_on_page'];
						break;

					case 3:
						$nrd_ary[2][2] += $sad_ary[ $iii ]['user_count'];
						$nrd_ary[2][3] += $sad_ary[ $iii ]['is_newuser'];
						$nrd_ary[2][4] += $sad_ary[ $iii ]['session_count'];
						$nrd_ary[2][5] += $sad_ary[ $iii ]['bounce_count'];
						$nrd_ary[2][6] += $sad_ary[ $iii ]['pv_count'];
						$nrd_ary[2][7] += $sad_ary[ $iii ]['time_on_page'];
						break;

					default:
						break;
				}
			} else {
				switch ( $sad_ary[ $iii ]['device_id'] ) {
					case 1:
						$nrd_ary[3][2] += $sad_ary[ $iii ]['user_count'];
						$nrd_ary[3][4] += $sad_ary[ $iii ]['session_count'];
						$nrd_ary[3][5] += $sad_ary[ $iii ]['bounce_count'];
						$nrd_ary[3][6] += $sad_ary[ $iii ]['pv_count'];
						$nrd_ary[3][7] += $sad_ary[ $iii ]['time_on_page'];
						break;

					case 2:
						$nrd_ary[4][2] += $sad_ary[ $iii ]['user_count'];
						$nrd_ary[4][4] += $sad_ary[ $iii ]['session_count'];
						$nrd_ary[4][5] += $sad_ary[ $iii ]['bounce_count'];
						$nrd_ary[4][6] += $sad_ary[ $iii ]['pv_count'];
						$nrd_ary[4][7] += $sad_ary[ $iii ]['time_on_page'];
						break;

					case 3:
						$nrd_ary[5][2] += $sad_ary[ $iii ]['user_count'];
						$nrd_ary[5][4] += $sad_ary[ $iii ]['session_count'];
						$nrd_ary[5][5] += $sad_ary[ $iii ]['bounce_count'];
						$nrd_ary[5][6] += $sad_ary[ $iii ]['pv_count'];
						$nrd_ary[5][7] += $sad_ary[ $iii ]['time_on_page'];
						break;

					default:
						break;
				}
			}
		}
		$nnncnt = $this->wrap_count( $nrd_ary );
		for ( $nnn = 0; $nnn < $nnncnt; $nnn++ ) {
			$sessions = $nrd_ary[ $nnn ][4];
			$bounces  = $nrd_ary[ $nnn ][5];
			$pages    = $nrd_ary[ $nnn ][6];
			$times    = $nrd_ary[ $nnn ][7];
			if ( 0 < $sessions ) {
				//直帰はセッションのうちの直帰数
				$bouncerate         = ( $bounces / $sessions ) * 100;
				$nrd_ary[ $nnn ][5] = round( $bouncerate, 1 );
				//ページ/セッション
				$sessionavg         = ( $pages / $sessions );
				$nrd_ary[ $nnn ][6] = round( $sessionavg, 2 );
				//平均セッション時間（秒）
				$sessiontim         = ( $times / $sessions );
				$nrd_ary[ $nnn ][7] = round( $sessiontim, 0 );
			}
		}
		return $nrd_ary;
	}

	//サブサマリー利用版get_nrd_data
	public function get_nrd_data_by_sub_summary( $dateterm, $tracking_id = 'all' ) {

		global $qahm_db;
		$sad_ary = $qahm_db->summary_days_access_detail( $dateterm, $tracking_id ); //次元ごとに集計済みのデータ

		$nrd_ary = array();

		$nrd_ary[] = ( array( 'New Visitor', 'desktop', 0, 0, 0, 0, 0, 0 ) );
		$nrd_ary[] = ( array( 'New Visitor', 'tablet', 0, 0, 0, 0, 0, 0 ) );
		$nrd_ary[] = ( array( 'New Visitor', 'mobile', 0, 0, 0, 0, 0, 0 ) );
		$nrd_ary[] = ( array( 'Returning Visitor', 'desktop', 0, 0, 0, 0, 0, 0 ) );
		$nrd_ary[] = ( array( 'Returning Visitor', 'tablet', 0, 0, 0, 0, 0, 0 ) );
		$nrd_ary[] = ( array( 'Returning Visitor', 'mobile', 0, 0, 0, 0, 0, 0 ) );

			// new/repeat device report
		$maxcnt = $this->wrap_count( $sad_ary );
		for ( $iii = 0; $iii < $maxcnt; $iii++ ) {

			if ( $sad_ary[ $iii ]['is_newuser'] ) {
				switch ( $sad_ary[ $iii ]['device_id'] ) {
					case 1:
						$nrd_ary[0][2] += $sad_ary[ $iii ]['user_count'];
						//$nrd_ary[0][3] += $sad_ary[$iii]['is_newuser'];
						$nrd_ary[0][3] += $sad_ary[ $iii ]['is_newuser_count'];
						$nrd_ary[0][4] += $sad_ary[ $iii ]['session_count'];
						$nrd_ary[0][5] += $sad_ary[ $iii ]['bounce_count'];
						$nrd_ary[0][6] += $sad_ary[ $iii ]['pv_count'];
						$nrd_ary[0][7] += $sad_ary[ $iii ]['time_on_page'];
						break;

					case 2:
						$nrd_ary[1][2] += $sad_ary[ $iii ]['user_count'];
						//$nrd_ary[1][3] += $sad_ary[$iii]['is_newuser'];
						$nrd_ary[1][3] += $sad_ary[ $iii ]['is_newuser_count'];
						$nrd_ary[1][4] += $sad_ary[ $iii ]['session_count'];
						$nrd_ary[1][5] += $sad_ary[ $iii ]['bounce_count'];
						$nrd_ary[1][6] += $sad_ary[ $iii ]['pv_count'];
						$nrd_ary[1][7] += $sad_ary[ $iii ]['time_on_page'];
						break;

					case 3:
						$nrd_ary[2][2] += $sad_ary[ $iii ]['user_count'];
						//$nrd_ary[2][3] += $sad_ary[$iii]['is_newuser'];
						$nrd_ary[2][3] += $sad_ary[ $iii ]['is_newuser_count'];
						$nrd_ary[2][4] += $sad_ary[ $iii ]['session_count'];
						$nrd_ary[2][5] += $sad_ary[ $iii ]['bounce_count'];
						$nrd_ary[2][6] += $sad_ary[ $iii ]['pv_count'];
						$nrd_ary[2][7] += $sad_ary[ $iii ]['time_on_page'];
						break;

					default:
						break;
				}
			} else {
				switch ( $sad_ary[ $iii ]['device_id'] ) {
					case 1:
						$nrd_ary[3][2] += $sad_ary[ $iii ]['user_count'];
						$nrd_ary[3][4] += $sad_ary[ $iii ]['session_count'];
						$nrd_ary[3][5] += $sad_ary[ $iii ]['bounce_count'];
						$nrd_ary[3][6] += $sad_ary[ $iii ]['pv_count'];
						$nrd_ary[3][7] += $sad_ary[ $iii ]['time_on_page'];
						break;

					case 2:
						$nrd_ary[4][2] += $sad_ary[ $iii ]['user_count'];
						$nrd_ary[4][4] += $sad_ary[ $iii ]['session_count'];
						$nrd_ary[4][5] += $sad_ary[ $iii ]['bounce_count'];
						$nrd_ary[4][6] += $sad_ary[ $iii ]['pv_count'];
						$nrd_ary[4][7] += $sad_ary[ $iii ]['time_on_page'];
						break;

					case 3:
						$nrd_ary[5][2] += $sad_ary[ $iii ]['user_count'];
						$nrd_ary[5][4] += $sad_ary[ $iii ]['session_count'];
						$nrd_ary[5][5] += $sad_ary[ $iii ]['bounce_count'];
						$nrd_ary[5][6] += $sad_ary[ $iii ]['pv_count'];
						$nrd_ary[5][7] += $sad_ary[ $iii ]['time_on_page'];
						break;

					default:
						break;
				}
			}
		}
		$nnncnt = $this->wrap_count( $nrd_ary );
		for ( $nnn = 0; $nnn < $nnncnt; $nnn++ ) {
			$sessions = $nrd_ary[ $nnn ][4];
			$bounces  = $nrd_ary[ $nnn ][5];
			$pages    = $nrd_ary[ $nnn ][6];
			$times    = $nrd_ary[ $nnn ][7];
			if ( 0 < $sessions ) {
				//直帰はセッションのうちの直帰数
				$bouncerate         = ( $bounces / $sessions ) * 100;
				$nrd_ary[ $nnn ][5] = round( $bouncerate, 1 );
				//ページ/セッション
				$sessionavg         = ( $pages / $sessions );
				$nrd_ary[ $nnn ][6] = round( $sessionavg, 2 );
				//平均セッション時間（秒）
				$sessiontim         = ( $times / $sessions );
				$nrd_ary[ $nnn ][7] = round( $sessiontim, 0 );
			}
		}
		return $nrd_ary;
	}


	// new repeat / device table用
	public function ajax_get_ch_data() {
		$nonce = $this->wrap_filter_input( INPUT_POST, 'nonce' );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_API ) || $this->is_maintenance() ) {
			http_response_code( 406 );
			die( 'nonce error' );
		}
		// 全パラメーターを取得する
		$dateterm    = mb_strtolower( $this->wrap_trim( $this->wrap_filter_input( INPUT_POST, 'date' ) ) );
		$tracking_id = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'tracking_id' ) );
		//$resary = $this->get_ch_data( $dateterm, $tracking_id );
		$resary = $this->get_ch_data_by_sub_summary( $dateterm, $tracking_id );
		header( 'Content-type: application/json; charset=UTF-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
		echo $this->wrap_json_encode( $resary );
		die();
	}

	public function get_ch_data( $dateterm, $tracking_id = 'all' ) {

		global $qahm_data_api;

		//make channel array
		$sad_ary = $this->select_data( 'summary_days_access_detail', '*', $dateterm, false, '', $tracking_id );

		//make channel array
		$ch_ary   = array();
		$ch_ary[] = ( array( __( 'Total', 'qa-heatmap-analytics' ), 0, 0, 0, 0, 0, 0 ) );
		$ch_ary[] = ( array( 'Direct', 0, 0, 0, 0, 0, 0 ) );
		$ch_ary[] = ( array( 'Organic Search', 0, 0, 0, 0, 0, 0 ) );
		$ch_ary[] = ( array( 'Social', 0, 0, 0, 0, 0, 0 ) );
		$ch_ary[] = ( array( 'Email', 0, 0, 0, 0, 0, 0 ) );
		$ch_ary[] = ( array( 'Affiliates', 0, 0, 0, 0, 0, 0 ) );
		$ch_ary[] = ( array( 'Referral', 0, 0, 0, 0, 0, 0 ) );
		$ch_ary[] = ( array( 'Paid Search', 0, 0, 0, 0, 0, 0 ) );
		$ch_ary[] = ( array( 'Other Advertising', 0, 0, 0, 0, 0, 0 ) );
		$ch_ary[] = ( array( 'Display', 0, 0, 0, 0, 0, 0 ) );
		$ch_ary[] = ( array( 'Other', 0, 0, 0, 0, 0, 0 ) );

		//channel index
		$total_idx      = 0;
		$direct_idx     = 1;
		$organic_idx    = 2;
		$social_idx     = 3;
		$email_idx      = 4;
		$affiliates_idx = 5;
		$referral_idx   = 6;
		$paidsearch_idx = 7;
		$otheradv_idx   = 8;
		$display_idx    = 9;
		$other_idx      = 10;

		//default channel group regular expressions
		$paidsearch = '/^(cpc|ppc|paidsearch)$/';
		$display    = '/(display|cpm|banner)$/';
		$otheradv   = '/^(cpv|cpa|cpp|content-text)$/';
		$social     = '/^(social|social-network|social-media|sm|social network|social media)$/';

		//mapping for utm_medium
		$channel_map = array(
			''          => $direct_idx,
			'organic'   => $organic_idx,
			'email'     => $email_idx,
			'affiliate' => $affiliates_idx,
			'referral'  => $referral_idx,
		);

		//caching for medium and domain
		$medium_cache = array();
		$domain_cache = array();

		$maxcnt     = $this->wrap_count( $sad_ary );
		$domain     = null;
		$sitemanage = $qahm_data_api->get_sitemanage();

		if ( $sitemanage ) {
			foreach ( $sitemanage as $site ) {
				if ( $site['tracking_id'] === $tracking_id ) {
					$domain = $site['domain'];
					break;
				}
			}
		}

		for ( $iii = 0; $iii < $maxcnt; $iii++ ) {
			$utm_medium    = $sad_ary[ $iii ]['utm_medium'];
			$source_domain = $sad_ary[ $iii ]['source_domain'];

			// Use cached medium if available
			if ( ! isset( $medium_cache[ $utm_medium ] ) ) {
				if ( isset( $channel_map[ $utm_medium ] ) ) {
					$medium_cache[ $utm_medium ] = $channel_map[ $utm_medium ];
				} elseif ( preg_match( $paidsearch, $utm_medium ) ) {
					$medium_cache[ $utm_medium ] = $paidsearch_idx;
				} elseif ( preg_match( $display, $utm_medium ) ) {
					$medium_cache[ $utm_medium ] = $display_idx;
				} elseif ( preg_match( $social, $utm_medium ) ) {
					$medium_cache[ $utm_medium ] = $social_idx;
				} elseif ( preg_match( $otheradv, $utm_medium ) ) {
					$medium_cache[ $utm_medium ] = $otheradv_idx;
				} else {
					$medium_cache[ $utm_medium ] = $other_idx;
				}
			}
			$medium_idx = $medium_cache[ $utm_medium ];

			// Use cached domain if available
			if ( ! isset( $domain_cache[ $source_domain ] ) ) {
				if ( $source_domain === 'direct' || $source_domain === $domain ) {
					$domain_cache[ $source_domain ] = $direct_idx;
				} else {
					$domain_cache[ $source_domain ] = $referral_idx;
				}
			}
			$domain_idx = $domain_cache[ $source_domain ];

			// Channel report processing
			if ( $utm_medium === '' || $utm_medium === null ) {
				$ch_ary[ $domain_idx ][1] += $sad_ary[ $iii ]['user_count'];
				if ( $sad_ary[ $iii ]['is_newuser'] ) {
					$ch_ary[ $domain_idx ][2] += $sad_ary[ $iii ]['user_count'];
				}
				$ch_ary[ $domain_idx ][3] += $sad_ary[ $iii ]['session_count'];
				$ch_ary[ $domain_idx ][4] += $sad_ary[ $iii ]['bounce_count'];
				$ch_ary[ $domain_idx ][5] += $sad_ary[ $iii ]['pv_count'];
				$ch_ary[ $domain_idx ][6] += $sad_ary[ $iii ]['time_on_page'];
			} else {
				$ch_ary[ $medium_idx ][1] += $sad_ary[ $iii ]['user_count'];
				if ( $sad_ary[ $iii ]['is_newuser'] ) {
					$ch_ary[ $medium_idx ][2] += $sad_ary[ $iii ]['user_count'];
				}
				$ch_ary[ $medium_idx ][3] += $sad_ary[ $iii ]['session_count'];
				$ch_ary[ $medium_idx ][4] += $sad_ary[ $iii ]['bounce_count'];
				$ch_ary[ $medium_idx ][5] += $sad_ary[ $iii ]['pv_count'];
				$ch_ary[ $medium_idx ][6] += $sad_ary[ $iii ]['time_on_page'];
			}
		}

		// Calculate totals after the main loop
		for ( $i = $total_idx + 1; $i < $this->wrap_count( $ch_ary ); $i++ ) {
			for ( $j = 1; $j < $this->wrap_count( $ch_ary[ $i ] ); $j++ ) {
				$ch_ary[ $total_idx ][ $j ] += $ch_ary[ $i ][ $j ];
			}
		}

		// Generate channel table with calculated bounce rate, pages per session, and session time
		$nnncnt = $this->wrap_count( $ch_ary );
		for ( $nnn = 0; $nnn < $nnncnt; $nnn++ ) {
			$sessions = $ch_ary[ $nnn ][3];
			$bounces  = $ch_ary[ $nnn ][4];
			$pages    = $ch_ary[ $nnn ][5];
			$times    = $ch_ary[ $nnn ][6];
			if ( 0 < $sessions ) {
				// Calculate bounce rate
				$bouncerate        = ( $bounces / $sessions ) * 100;
				$ch_ary[ $nnn ][4] = round( $bouncerate, 1 );
				// Pages per session
				$sessionavg        = ( $pages / $sessions );
				$ch_ary[ $nnn ][5] = round( $sessionavg, 2 );
				// Average session time (seconds)
				$sessiontim        = ( $times / $sessions );
				$ch_ary[ $nnn ][6] = round( $sessiontim, 0 );
			}
		}

		return $ch_ary;
	}


	public function get_ch_data_by_sub_summary( $dateterm, $tracking_id = 'all' ) {

		global $qahm_db;

		$sad_ary = $qahm_db->summary_days_access_detail( $dateterm, $tracking_id ); //次元ごとに集計済みのデータ
				//make channel array
		//https://support.google.com/analytics/answer/3297892?hl=en
		//user newuser session bouncerate page/session avgsessiontime
		$ch_ary   = array();
		$ch_ary[] = ( array( __( 'Total', 'qa-heatmap-analytics' ), 0, 0, 0, 0, 0, 0 ) );
		$ch_ary[] = ( array( 'Direct', 0, 0, 0, 0, 0, 0 ) );
		$ch_ary[] = ( array( 'Organic Search', 0, 0, 0, 0, 0, 0 ) );
		$ch_ary[] = ( array( 'Social', 0, 0, 0, 0, 0, 0 ) );
		$ch_ary[] = ( array( 'Email', 0, 0, 0, 0, 0, 0 ) );
		$ch_ary[] = ( array( 'Affiliates', 0, 0, 0, 0, 0, 0 ) );
		$ch_ary[] = ( array( 'Referral', 0, 0, 0, 0, 0, 0 ) );
		$ch_ary[] = ( array( 'Paid Search', 0, 0, 0, 0, 0, 0 ) );
		$ch_ary[] = ( array( 'Other Advertising', 0, 0, 0, 0, 0, 0 ) );
		$ch_ary[] = ( array( 'Display', 0, 0, 0, 0, 0, 0 ) );
		$ch_ary[] = ( array( 'Other', 0, 0, 0, 0, 0, 0 ) );

		// $ch_aryのインデックス
		$total_idx      = 0;
		$direct_idx     = 1;
		$organic_idx    = 2;
		$social_idx     = 3;
		$email_idx      = 4;
		$affiliates_idx = 5;
		$referral_idx   = 6;
		$paidsearch_idx = 7;
		$otheradv_idx   = 8;
		$display_idx    = 9;
		$other_idx      = 10;

		//default channel group
		$paidsearch = '/^(cpc|ppc|paidsearch)$/';
		$display    = '/(display|cpm|banner)$/';
		$otheradv   = '/^(cpv|cpa|cpp|content-text)$/';
		$social     = '/^(social|social-network|social-media|sm|social network|social media)$/';

		// new/repeat device report
		$maxcnt = $this->wrap_count( $sad_ary );
		$domain = $this->get_own_domain( $tracking_id ); // #1508: 取得処理を一元化
		for ( $iii = 0; $iii < $maxcnt; $iii++ ) {

			//channel report

			switch ( $sad_ary[ $iii ]['utm_medium'] ) {
				case '':
				case null:
					// #1508: 空 medium の分類を共通規則（QAHM_Base::derive_medium_for_empty_utm＝ゴール側 #498/#1076 と同一）に統一。
					// 検索エンジン経由は Referral でなく Organic Search へ（従来の一律 Referral は誤分類）。
					$derived_medium = QAHM_Base::derive_medium_for_empty_utm( $sad_ary[ $iii ]['source_domain'], $domain );
					if ( '(none)' === $derived_medium ) {
						$empty_medium_idx = $direct_idx;
					} elseif ( 'organic' === $derived_medium ) {
						$empty_medium_idx = $organic_idx;
					} else {
						$empty_medium_idx = $referral_idx;
					}
					$ch_ary[ $empty_medium_idx ][1] += $sad_ary[ $iii ]['user_count'];
					if ( $sad_ary[ $iii ]['is_newuser'] ) {
						$ch_ary[ $empty_medium_idx ][2] += $sad_ary[ $iii ]['user_count'];
					}
					$ch_ary[ $empty_medium_idx ][3] += $sad_ary[ $iii ]['session_count'];
					$ch_ary[ $empty_medium_idx ][4] += $sad_ary[ $iii ]['bounce_count'];
					$ch_ary[ $empty_medium_idx ][5] += $sad_ary[ $iii ]['pv_count'];
					$ch_ary[ $empty_medium_idx ][6] += $sad_ary[ $iii ]['time_on_page'];
					break;

				case 'organic':
					$ch_ary[ $organic_idx ][1] += $sad_ary[ $iii ]['user_count'];
					if ( $sad_ary[ $iii ]['is_newuser'] ) {
						$ch_ary[ $organic_idx ][2] += $sad_ary[ $iii ]['user_count'];
					}
					$ch_ary[ $organic_idx ][3] += $sad_ary[ $iii ]['session_count'];
					$ch_ary[ $organic_idx ][4] += $sad_ary[ $iii ]['bounce_count'];
					$ch_ary[ $organic_idx ][5] += $sad_ary[ $iii ]['pv_count'];
					$ch_ary[ $organic_idx ][6] += $sad_ary[ $iii ]['time_on_page'];
					break;

				case 'email':
					$ch_ary[ $email_idx ][1] += $sad_ary[ $iii ]['user_count'];
					if ( $sad_ary[ $iii ]['is_newuser'] ) {
						$ch_ary[ $email_idx ][2] += $sad_ary[ $iii ]['user_count'];
					}
					$ch_ary[ $email_idx ][3] += $sad_ary[ $iii ]['session_count'];
					$ch_ary[ $email_idx ][4] += $sad_ary[ $iii ]['bounce_count'];
					$ch_ary[ $email_idx ][5] += $sad_ary[ $iii ]['pv_count'];
					$ch_ary[ $email_idx ][6] += $sad_ary[ $iii ]['time_on_page'];
					break;

				case 'affiliate':
					$ch_ary[ $affiliates_idx ][1] += $sad_ary[ $iii ]['user_count'];
					if ( $sad_ary[ $iii ]['is_newuser'] ) {
						$ch_ary[ $affiliates_idx ][2] += $sad_ary[ $iii ]['user_count'];
					}
					$ch_ary[ $affiliates_idx ][3] += $sad_ary[ $iii ]['session_count'];
					$ch_ary[ $affiliates_idx ][4] += $sad_ary[ $iii ]['bounce_count'];
					$ch_ary[ $affiliates_idx ][5] += $sad_ary[ $iii ]['pv_count'];
					$ch_ary[ $affiliates_idx ][6] += $sad_ary[ $iii ]['time_on_page'];
					break;

				case 'referral':
					$ch_ary[ $referral_idx ][1] += $sad_ary[ $iii ]['user_count'];
					if ( $sad_ary[ $iii ]['is_newuser'] ) {
						$ch_ary[ $referral_idx ][2] += $sad_ary[ $iii ]['user_count'];
					}
					$ch_ary[ $referral_idx ][3] += $sad_ary[ $iii ]['session_count'];
					$ch_ary[ $referral_idx ][4] += $sad_ary[ $iii ]['bounce_count'];
					$ch_ary[ $referral_idx ][5] += $sad_ary[ $iii ]['pv_count'];
					$ch_ary[ $referral_idx ][6] += $sad_ary[ $iii ]['time_on_page'];
					break;

				default:
					if ( preg_match( $paidsearch, $sad_ary[ $iii ]['utm_medium'] ) ) {
						$ch_ary[ $paidsearch_idx ][1] += $sad_ary[ $iii ]['user_count'];
						if ( $sad_ary[ $iii ]['is_newuser'] ) {
							$ch_ary[ $paidsearch_idx ][2] += $sad_ary[ $iii ]['user_count'];
						}
						$ch_ary[ $paidsearch_idx ][3] += $sad_ary[ $iii ]['session_count'];
						$ch_ary[ $paidsearch_idx ][4] += $sad_ary[ $iii ]['bounce_count'];
						$ch_ary[ $paidsearch_idx ][5] += $sad_ary[ $iii ]['pv_count'];
						$ch_ary[ $paidsearch_idx ][6] += $sad_ary[ $iii ]['time_on_page'];
					} elseif ( preg_match( $display, $sad_ary[ $iii ]['utm_medium'] ) ) {
						$ch_ary[ $display_idx ][1] += $sad_ary[ $iii ]['user_count'];
						if ( $sad_ary[ $iii ]['is_newuser'] ) {
							$ch_ary[ $display_idx ][2] += $sad_ary[ $iii ]['user_count'];
						}
						$ch_ary[ $display_idx ][3] += $sad_ary[ $iii ]['session_count'];
						$ch_ary[ $display_idx ][4] += $sad_ary[ $iii ]['bounce_count'];
						$ch_ary[ $display_idx ][5] += $sad_ary[ $iii ]['pv_count'];
						$ch_ary[ $display_idx ][6] += $sad_ary[ $iii ]['time_on_page'];
					} elseif ( preg_match( $social, $sad_ary[ $iii ]['utm_medium'] ) ) {
						$ch_ary[ $social_idx ][1] += $sad_ary[ $iii ]['user_count'];
						if ( $sad_ary[ $iii ]['is_newuser'] ) {
							$ch_ary[ $social_idx ][2] += $sad_ary[ $iii ]['user_count'];
						}
						$ch_ary[ $social_idx ][3] += $sad_ary[ $iii ]['session_count'];
						$ch_ary[ $social_idx ][4] += $sad_ary[ $iii ]['bounce_count'];
						$ch_ary[ $social_idx ][5] += $sad_ary[ $iii ]['pv_count'];
						$ch_ary[ $social_idx ][6] += $sad_ary[ $iii ]['time_on_page'];
					} elseif ( preg_match( $otheradv, $sad_ary[ $iii ]['utm_medium'] ) ) {
						$ch_ary[ $otheradv_idx ][1] += $sad_ary[ $iii ]['user_count'];
						if ( $sad_ary[ $iii ]['is_newuser'] ) {
							$ch_ary[ $otheradv_idx ][2] += $sad_ary[ $iii ]['user_count'];
						}
						$ch_ary[ $otheradv_idx ][3] += $sad_ary[ $iii ]['session_count'];
						$ch_ary[ $otheradv_idx ][4] += $sad_ary[ $iii ]['bounce_count'];
						$ch_ary[ $otheradv_idx ][5] += $sad_ary[ $iii ]['pv_count'];
						$ch_ary[ $otheradv_idx ][6] += $sad_ary[ $iii ]['time_on_page'];
					} else {
						$ch_ary[ $other_idx ][1] += $sad_ary[ $iii ]['user_count'];
						if ( $sad_ary[ $iii ]['is_newuser'] ) {
							$ch_ary[ $other_idx ][2] += $sad_ary[ $iii ]['user_count'];
						}
						$ch_ary[ $other_idx ][3] += $sad_ary[ $iii ]['session_count'];
						$ch_ary[ $other_idx ][4] += $sad_ary[ $iii ]['bounce_count'];
						$ch_ary[ $other_idx ][5] += $sad_ary[ $iii ]['pv_count'];
						$ch_ary[ $other_idx ][6] += $sad_ary[ $iii ]['time_on_page'];
					}
					break;

			}
		}

		// 合計値の計算。上記のループ内で計算するとソースが長くなるためここで計算
		for ( $i = $total_idx + 1; $i < $this->wrap_count( $ch_ary ); $i++ ) {
			for ( $j = 1; $j < $this->wrap_count( $ch_ary[ $i ] ); $j++ ) {
				$ch_ary[ $total_idx ][ $j ] += $ch_ary[ $i ][ $j ];
			}
		}

		//generate channel table
		$nnncnt = $this->wrap_count( $ch_ary );
		for ( $nnn = 0; $nnn < $nnncnt; $nnn++ ) {
			$sessions = $ch_ary[ $nnn ][3];
			$bounces  = $ch_ary[ $nnn ][4];
			$pages    = $ch_ary[ $nnn ][5];
			$times    = $ch_ary[ $nnn ][6];
			if ( 0 < $sessions ) {
				//直帰はセッションのうちの直帰数
				$bouncerate        = ( $bounces / $sessions ) * 100;
				$ch_ary[ $nnn ][4] = round( $bouncerate, 1 );
				//ページ/セッション
				$sessionavg        = ( $pages / $sessions );
				$ch_ary[ $nnn ][5] = round( $sessionavg, 2 );
				//平均セッション時間（秒）
				$sessiontim        = ( $times / $sessions );
				$ch_ary[ $nnn ][6] = round( $sessiontim, 0 );
			}
		}

		return $ch_ary;
	}


	public function ajax_get_ch_days_data() {
		$nonce = $this->wrap_filter_input( INPUT_POST, 'nonce' );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_API ) || $this->is_maintenance() ) {
			http_response_code( 406 );
			die( 'nonce error' );
		}
		// 全パラメーターを取得する
		$dateterm    = strtolower( $this->wrap_trim( $this->wrap_filter_input( INPUT_POST, 'date' ) ) );
		$name_ary    = json_decode( $this->wrap_filter_input( INPUT_POST, 'name_ary' ) );
		$tracking_id = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'tracking_id' ) );

		if ( ! $dateterm || ! $name_ary || ! $tracking_id ) {
			http_response_code( 408 );
			die( 'parameter error' );
		}

		$ret_ary = $this->get_ch_days_data( $dateterm, $name_ary, $tracking_id );
		header( 'Content-type: application/json; charset=UTF-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
		echo $this->wrap_json_encode( $ret_ary );
		die();
	}
	public function get_ch_days_data( $dateterm, $name_ary, $tracking_id = 'all' ) {

		$sad_ary = $this->select_data( 'summary_days_access_detail', '*', $dateterm, false, '', $tracking_id );
		//$sad_ary = $qahm_db->summary_days_access_detail( $dateterm, $tracking_id ); //次元ごとに集計済みのデータ

		// #1508: 空 medium 導出（自ドメイン判定）用の自サイトドメイン
		$domain = $this->get_own_domain( $tracking_id );

		// 正規表現を使用して日付を抽出
		preg_match( '/between (\d{4}-\d{2}-\d{2}) and (\d{4}-\d{2}-\d{2})/', $dateterm, $matches );
		$min_date = $matches[1];
		$max_date = $matches[2];

		// 最小日付と最大日付をDateTimeオブジェクトに変換
		$min_date_dt = new DateTime( $min_date );
		$max_date_dt = new DateTime( $max_date );

		// 全ての日付のリストを生成
		$period = new DatePeriod(
			$min_date_dt,
			new DateInterval( 'P1D' ),
			$max_date_dt->modify( '+1 day' )
		);

		// 日付リストの初期化
		$all_dates = array();
		foreach ( $period as $date ) {
			$all_dates[] = $date->format( 'Y-m-d' );
		}

		// 日付とutm_mediumごとのセッション数を格納する配列（初期値0）
		$ret_ary = array();

		// 全ての日付について、各utm_mediumのセッション数の初期値を0に設定
		foreach ( $all_dates as $date ) {
			foreach ( $name_ary as $name ) {
				$ret_ary[ $date ][ $name ] = 0;
			}
		}

		// default channel group
		$paidsearch = '/^(cpc|ppc|paidsearch)$/';
		$display    = '/(display|cpm|banner)$/';
		$otheradv   = '/^(cpv|cpa|cpp|content-text)$/';
		$social     = '/^(social|social-network|social-media|sm|social network|social media)$/';

		// 元の配列をループして処理
		foreach ( $sad_ary as $sad ) {
			$date          = $sad['date'];
			$session_count = $sad['session_count'];
			$source_domain = $this->wrap_array_key_exists( 'source_domain', $sad ) ? $sad['source_domain'] : '';
			$utm_medium    = $this->wrap_array_key_exists( 'utm_medium', $sad ) ? $sad['utm_medium'] : '';
			$ch_name       = '';

			switch ( $utm_medium ) {
				case '':
				case null:
					// #1508: 空 medium の分類を共通規則に統一（チャネル表本体と同じ＝検索エンジン→Organic Search・自ドメイン→Direct）。
					// 従来この関数は自ドメイン判定も無く、表本体とグラフで分類がズレていた。
					$derived_medium = QAHM_Base::derive_medium_for_empty_utm( $source_domain, $domain );
					if ( '(none)' === $derived_medium ) {
						$ch_name = 'Direct';
					} elseif ( 'organic' === $derived_medium ) {
						$ch_name = 'Organic Search';
					} else {
						$ch_name = 'Referral';
					}
					break;

				case 'organic':
					$ch_name = 'Organic Search';
					break;

				case 'email':
					$ch_name = 'Email';
					break;

				case 'affiliate':
					$ch_name = 'Affiliates';
					break;

				case 'referral':
					$ch_name = 'Referral';
					break;

				default:
					if ( preg_match( $paidsearch, $utm_medium ) ) {
						$ch_name = 'Paid Search';
					} elseif ( preg_match( $display, $utm_medium ) ) {
						$ch_name = 'Display';
					} elseif ( preg_match( $social, $utm_medium ) ) {
						$ch_name = 'Social';
					} elseif ( preg_match( $otheradv, $utm_medium ) ) {
						$ch_name = 'Other Advertising';
					} else {
						$ch_name = 'Other';
					}
					break;
			}

			// ch_nameが名前の配列に含まれているか確認
			if ( $this->wrap_in_array( $ch_name, $name_ary ) ) {
				// 日付とutm_mediumの組み合わせをキーとしてセッション数を加算
				$ret_ary[ $date ][ $ch_name ] += $session_count;
			}

			// 合計配列が存在するかチェック。存在すれば加算
			if ( $this->wrap_in_array( __( 'Total', 'qa-heatmap-analytics' ), $name_ary ) ) {
				// 日付とutm_mediumの組み合わせをキーとしてセッション数を加算
				$ret_ary[ $date ][ __( 'Total', 'qa-heatmap-analytics' ) ] += $session_count;
			}
		}

		// 結果の出力
		return $ret_ary;
	}


	// new repeat / device table用
	public function ajax_get_sm_data() {
		$nonce = $this->wrap_filter_input( INPUT_POST, 'nonce' );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_API ) || $this->is_maintenance() ) {
			http_response_code( 406 );
			die( 'nonce error' );
		}
		// 全パラメーターを取得する
		$dateterm    = mb_strtolower( $this->wrap_trim( $this->wrap_filter_input( INPUT_POST, 'date' ) ) );
		$tracking_id = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'tracking_id' ) );
		//$resary = $this->get_sm_data( $dateterm, $tracking_id );
		$resary = $this->get_sm_data_by_sub_summary( $dateterm, $tracking_id );
		header( 'Content-type: application/json; charset=UTF-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
		echo $this->wrap_json_encode( $resary );
		die();
	}

	public function get_sm_data( $dateterm, $tracking_id = 'all' ) {

		//make chanell array
		$sad_ary = $this->select_data( 'summary_days_access_detail', '*', $dateterm, false, '', $tracking_id );

		//make channel array
		//https://support.google.com/analytics/answer/3297892?hl=en
		//user newuser session bouncerate page/session avgsessiontime
		$sm_ary = array();

		// $sm_aryのインデックス
		$sm_ary[]  = ( array( __( 'Total', 'qa-heatmap-analytics' ), __( 'Total', 'qa-heatmap-analytics' ), 0, 0, 0, 0, 0, 0 ) );
		$total_idx = 0;

		//source/media report
		$maxcnt = $this->wrap_count( $sad_ary );
		for ( $iii = 0; $iii < $maxcnt; $iii++ ) {
			$source = $this->wrap_array_key_exists( 'source_domain', $sad_ary[ $iii ] ) ? $sad_ary[ $iii ]['source_domain'] : 'direct';
			$medium = $this->wrap_array_key_exists( 'utm_medium', $sad_ary[ $iii ] ) ? $sad_ary[ $iii ]['utm_medium'] : '';
			if ( ! $medium ) {
				if ( $source === 'direct' ) {
					$medium = '(none)';
				} else {
					$medium = 'referral';
				}
			}
			$usercnt = $sad_ary[ $iii ]['user_count'];
			$newuser = $sad_ary[ $iii ]['is_newuser'];
			$session = $sad_ary[ $iii ]['session_count'];
			$bounce  = $sad_ary[ $iii ]['bounce_count'];
			$pvcnt   = $sad_ary[ $iii ]['pv_count'];
			$timeon  = $sad_ary[ $iii ]['time_on_page'];
			if ( ! $sad_ary[ $iii ]['is_newuser'] ) {
				$newuser = 0;
			}
			$is_find = false;
			$ssscnt  = $this->wrap_count( $sm_ary );
			for ( $sss = 0; $sss < $ssscnt; $sss++ ) {
				if ( $sm_ary[ $sss ][0] === $source && $sm_ary[ $sss ][1] === $medium ) {
					$sm_ary[ $sss ][2] += $usercnt;
					$sm_ary[ $sss ][3] += $newuser;
					$sm_ary[ $sss ][4] += $session;
					$sm_ary[ $sss ][5] += $bounce;
					$sm_ary[ $sss ][6] += $pvcnt;
					$sm_ary[ $sss ][7] += $timeon;
					$is_find            = true;
					break;
				}
			}
			if ( ! $is_find ) {
				$sm_ary[] = array( $source, $medium, $usercnt, $newuser, $session, $bounce, $pvcnt, $timeon );
			}
		}

		// 合計値の計算。上記のループ内で計算するとソースが長くなるためここで計算
		for ( $i = $total_idx + 1; $i < $this->wrap_count( $sm_ary ); $i++ ) {
			for ( $j = 2; $j < $this->wrap_count( $sm_ary[ $i ] ); $j++ ) {
				$sm_ary[ $total_idx ][ $j ] += $sm_ary[ $i ][ $j ];
			}
		}

		$ssscnt = $this->wrap_count( $sm_ary );
		//generate source/media table
		for ( $nnn = 0; $nnn < $ssscnt; $nnn++ ) {
			$sessions = $sm_ary[ $nnn ][4];
			$bounces  = $sm_ary[ $nnn ][5];
			$pages    = $sm_ary[ $nnn ][6];
			$times    = $sm_ary[ $nnn ][7];
			if ( 0 < $sessions ) {
				//直帰はセッションのうちの直帰数
				$bouncerate        = ( $bounces / $sessions ) * 100;
				$sm_ary[ $nnn ][5] = round( $bouncerate, 1 );
				//ページ/セッション
				$sessionavg        = ( $pages / $sessions );
				$sm_ary[ $nnn ][6] = round( $sessionavg, 2 );
				//平均セッション時間（秒）
				$sessiontim        = ( $times / $sessions );
				$sm_ary[ $nnn ][7] = round( $sessiontim, 0 );
			}
		}
		return $sm_ary;
	}

	public function get_sm_data_by_sub_summary( $dateterm, $tracking_id = 'all' ) {

		global $qahm_db;
		$sad_ary = $qahm_db->summary_days_access_detail( $dateterm, $tracking_id ); //次元ごとに集計済みのデータ

		// #1508: 空 medium 導出（自ドメイン判定）用の自サイトドメイン
		$domain = $this->get_own_domain( $tracking_id );

		//make channel array
		//https://support.google.com/analytics/answer/3297892?hl=en
		//user newuser session bouncerate page/session avgsessiontime
		$sm_ary = array();

		// $sm_aryのインデックス
		$sm_ary[]  = ( array( __( 'Total', 'qa-heatmap-analytics' ), __( 'Total', 'qa-heatmap-analytics' ), 0, 0, 0, 0, 0, 0 ) );
		$total_idx = 0;

			//source/media report
		$maxcnt = $this->wrap_count( $sad_ary );
		for ( $iii = 0; $iii < $maxcnt; $iii++ ) {
			$source = $this->wrap_array_key_exists( 'source_domain', $sad_ary[ $iii ] ) ? $sad_ary[ $iii ]['source_domain'] : 'direct';
			if ( ! $source ) {
				$source = 'direct'; // #1508: 空 source はゴール側キー導出（JS）と同じ direct 扱いに揃える
			}
			$medium = $this->wrap_array_key_exists( 'utm_medium', $sad_ary[ $iii ] ) ? $sad_ary[ $iii ]['utm_medium'] : '';
			if ( ! $medium ) {
				// #1508: 空 medium の導出を共通規則（ゴール側 #498/#1076 と同一）に統一。
				// 従来の一律 referral は検索エンジン経由を誤分類し、ゴール完了数の行紐付けを取りこぼしていた。
				$medium = QAHM_Base::derive_medium_for_empty_utm( $source, $domain );
			}
			$usercnt = $sad_ary[ $iii ]['user_count'];
			//$newuser = $sad_ary[$iii]['is_newuser'];
			$newuser = $sad_ary[ $iii ]['is_newuser_count'];
			$session = $sad_ary[ $iii ]['session_count'];
			$bounce  = $sad_ary[ $iii ]['bounce_count'];
			$pvcnt   = $sad_ary[ $iii ]['pv_count'];
			$timeon  = $sad_ary[ $iii ]['time_on_page'];
			//if ( ! $sad_ary[$iii]['is_newuser'] ) {
				//$newuser = 0;
			//}
			$is_find = false;
			$ssscnt  = $this->wrap_count( $sm_ary );
			for ( $sss = 0; $sss < $ssscnt; $sss++ ) {
				if ( $sm_ary[ $sss ][0] === $source && $sm_ary[ $sss ][1] === $medium ) {
					$sm_ary[ $sss ][2] += $usercnt;
					$sm_ary[ $sss ][3] += $newuser;
					$sm_ary[ $sss ][4] += $session;
					$sm_ary[ $sss ][5] += $bounce;
					$sm_ary[ $sss ][6] += $pvcnt;
					$sm_ary[ $sss ][7] += $timeon;
					$is_find            = true;
					break;
				}
			}
			if ( ! $is_find ) {
				$sm_ary[] = array( $source, $medium, $usercnt, $newuser, $session, $bounce, $pvcnt, $timeon );
			}
		}

		// 合計値の計算。上記のループ内で計算するとソースが長くなるためここで計算
		for ( $i = $total_idx + 1; $i < $this->wrap_count( $sm_ary ); $i++ ) {
			for ( $j = 2; $j < $this->wrap_count( $sm_ary[ $i ] ); $j++ ) {
				$sm_ary[ $total_idx ][ $j ] += $sm_ary[ $i ][ $j ];
			}
		}

		$ssscnt = $this->wrap_count( $sm_ary );
		//generate source/media table
		for ( $nnn = 0; $nnn < $ssscnt; $nnn++ ) {
			$sessions = $sm_ary[ $nnn ][4];
			$bounces  = $sm_ary[ $nnn ][5];
			$pages    = $sm_ary[ $nnn ][6];
			$times    = $sm_ary[ $nnn ][7];
			if ( 0 < $sessions ) {
				//直帰はセッションのうちの直帰数
				$bouncerate        = ( $bounces / $sessions ) * 100;
				$sm_ary[ $nnn ][5] = round( $bouncerate, 1 );
				//ページ/セッション
				$sessionavg        = ( $pages / $sessions );
				$sm_ary[ $nnn ][6] = round( $sessionavg, 2 );
				//平均セッション時間（秒）
				$sessiontim        = ( $times / $sessions );
				$sm_ary[ $nnn ][7] = round( $sessiontim, 0 );
			}
		}
		return $sm_ary;
	}

	public function ajax_get_sm_days_data() {
		$nonce = $this->wrap_filter_input( INPUT_POST, 'nonce' );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_API ) || $this->is_maintenance() ) {
			http_response_code( 406 );
			die( 'nonce error' );
		}
		// 全パラメーターを取得する
		$dateterm    = strtolower( $this->wrap_trim( $this->wrap_filter_input( INPUT_POST, 'date' ) ) );
		$name_ary    = json_decode( $this->wrap_filter_input( INPUT_POST, 'name_ary' ) );
		$tracking_id = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'tracking_id' ) );

		if ( ! $dateterm || ! $name_ary || ! $tracking_id ) {
			http_response_code( 408 );
			die( 'parameter error' );
		}

		$ret_ary = $this->get_sm_days_data( $dateterm, $name_ary, $tracking_id );
		header( 'Content-type: application/json; charset=UTF-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
		echo $this->wrap_json_encode( $ret_ary );
		die();
	}
	public function get_sm_days_data( $dateterm, $name_ary, $tracking_id = 'all' ) {

		$sad_ary = $this->select_data( 'summary_days_access_detail', '*', $dateterm, false, '', $tracking_id );
		//$sad_ary = $qahm_db->summary_days_access_detail( $dateterm, $tracking_id ); //次元ごとに集計済みのデータ

		// #1508: 空 medium 導出（自ドメイン判定）用の自サイトドメイン
		$domain = $this->get_own_domain( $tracking_id );

		// 正規表現を使用して日付を抽出
		preg_match( '/between (\d{4}-\d{2}-\d{2}) and (\d{4}-\d{2}-\d{2})/', $dateterm, $matches );
		$min_date = $matches[1];
		$max_date = $matches[2];

		// 最小日付と最大日付をDateTimeオブジェクトに変換
		$min_date_dt = new DateTime( $min_date );
		$max_date_dt = new DateTime( $max_date );

		// 全ての日付のリストを生成
		$period = new DatePeriod(
			$min_date_dt,
			new DateInterval( 'P1D' ),
			$max_date_dt->modify( '+1 day' )
		);

		// 日付リストの初期化
		$all_dates = array();
		foreach ( $period as $date ) {
			$all_dates[] = $date->format( 'Y-m-d' );
		}

		// 日付と参照元メディアごとのセッション数を格納する配列（初期値0）
		$ret_ary = array();

		// 全ての日付について、各参照元メディアのセッション数の初期値を0に設定
		foreach ( $all_dates as $date ) {
			foreach ( $name_ary as $name ) {
				$ret_ary[ $date ][ $name ] = 0;
			}
		}

		//source/media report
		foreach ( $sad_ary as $sad ) {
			$date          = $sad['date'];
			$session_count = $sad['session_count'];

			$source = $this->wrap_array_key_exists( 'source_domain', $sad ) ? $sad['source_domain'] : 'direct';
			if ( ! $source ) {
				$source = 'direct'; // #1508: 表側（get_sm_data_by_sub_summary）と同じ正規化
			}
			$medium = $this->wrap_array_key_exists( 'utm_medium', $sad ) ? $sad['utm_medium'] : '';
			if ( ! $medium ) {
				// #1508: 表側と同一規則に統一（ズレると該当行のグラフ名照合が外れて 0 になる）
				$medium = QAHM_Base::derive_medium_for_empty_utm( $source, $domain );
			}
			$sm_name = $source . ' | ' . $medium;

			// sm_nameが名前の配列に含まれているか確認
			if ( $this->wrap_in_array( $sm_name, $name_ary ) ) {
				// 日付とutm_mediumの組み合わせをキーとしてセッション数を加算
				$ret_ary[ $date ][ $sm_name ] += $session_count;
			}

			// 合計配列が存在するかチェック。存在すれば加算
			$total_name = __( 'Total', 'qa-heatmap-analytics' ) . ' | ' . __( 'Total', 'qa-heatmap-analytics' );
			if ( $this->wrap_in_array( $total_name, $name_ary ) ) {
				// 日付とutm_mediumの組み合わせをキーとしてセッション数を加算
				$ret_ary[ $date ][ $total_name ] += $session_count;
			}
		}

		return $ret_ary;
	}

	// landing page table用
	public function ajax_get_lp_data() {
		$nonce = $this->wrap_filter_input( INPUT_POST, 'nonce' );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_API ) || $this->is_maintenance() ) {
			http_response_code( 406 );
			die( 'nonce error' );
		}
		// 全パラメーターを取得する
		$dateterm    = mb_strtolower( $this->wrap_trim( $this->wrap_filter_input( INPUT_POST, 'date' ) ) );
		$tracking_id = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'tracking_id' ) );
		$resary      = $this->get_lp_data( $dateterm, $tracking_id );
		header( 'Content-type: application/json; charset=UTF-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
		echo $this->wrap_json_encode( $resary );
		die();
	}


	/**
	 * ランディングページデータを取得する
	 * @param string $dateterm 'date = between {Y-m-d} and {Y-m-d}'
	 * @param string $tracking_id トラッキングID。デフォルトは "all"。
	 * @return array
	 * return データ構造サンプル
	 * [
	 *   [
	 *      [0] => [123, 456], // （URLクレンジング前の？）ページIDの配列
	 *      [1] => 'Sample Page Title', // ページのタイトル
	 *      [2] => 'https://example.com/sample-page', // ページのURL（小文字に変換＝クレンジング済み）
	 *      [3] => 150, // セッション数
	 *      [4] => 50, // 新規セッション数
	 *      [5] => 45, // 新規ユーザー数
	 *      [6] => 30, // バウンス数
	 *      [7] => 200, // ページビュー数
	 *      [8] => 600, // セッション時間（秒）
	 *      [9] => 0 // wp_qa_id
	 *      [10] => [123, 456, ...] // クレンジング後、同一URLになったページをマージした、ページIDの配列
	 *  ],
	 * ...
	 * ]
	 *
	 */
	public function get_lp_data( $dateterm, $tracking_id = 'all' ) {

		global $qahm_data_api;

		//make chanell array
		$lps_ary    = array();
		$domain     = null;
		$sitemanage = $qahm_data_api->get_sitemanage();
		if ( $sitemanage ) {
			foreach ( $sitemanage as $site ) {
				if ( $site['tracking_id'] === $tracking_id ) {
					$domain = $site['domain'];
					break;
				}
			}
		}

		global $qahm_db;
		$lp_ary = $qahm_db->summary_days_landingpages( $dateterm, $tracking_id );
		//遅いので上記に変更
		//$lp_ary = $this->select_data( 'vr_summary_landingpage', '*', $dateterm );

		$lp_data_table_ary = $this->aggregate_lp_data_by_pageurl( $lp_ary, $domain );

		return $lp_data_table_ary;
	}

	/**
	 * 条件に合致するランディングページデータを取得する
	 *
	 * @param string $tracking_id
	 * @param string $dateterm 'date = between {Y-m-d} and {Y-m-d}'
	 * @param array $filters フィルタ条件(AND)の連想配列 [フィールド名 => 値]
	 * @param int $max_results 最大取得件数（指定した場合のみ）　※ajax通信やブラウザ描画の負荷軽減のため
	 * @param int $sort_by ソート対象のインデックス番号。※returnデータ構造のインデックス番号と同じ
	 * @param SORT_DESC|SORT_ASC $sort_order ソート順
	 * @return array
	 * // returnデータ構造サンプル
	 * [
	 *   [
	 *      [0] => [123, 456], // （URLクレンジング前の？）ページIDの配列
	 *      [1] => 'Sample Page Title', // ページのタイトル
	 *      [2] => 'https://example.com/sample-page', // ページのURL（小文字に変換＝クレンジング済み）
	 *      [3] => 150, // セッション数
	 *      [4] => 50, // 新規セッション数
	 *      [5] => 45, // 新規ユーザー数
	 *      [6] => 30, // バウンス数
	 *      [7] => 200, // ページビュー数
	 *      [8] => 600, // セッション時間（秒）
	 *      [9] => 0 // wp_qa_id
	 *      [10] => [123, 456, ...] // クレンジング後、同一URLになったページをマージした、ページIDの配列
	 *  ],
	 * ...
	 * ]
	 *
	 */
	public function get_filtered_lp_data( $tracking_id, $dateterm, $filters = array(), $max_results = null, $sort_by = null, $sort_order = SORT_DESC ) {

		$lp_ary = $this->summary_days_landingpages( $dateterm, $tracking_id );

		// フィルタリング処理（複数条件の AND 絞り込み）
		$filtered_lps = array();
		$lp_ary_cnt   = $this->wrap_count( $lp_ary );
		for ( $iii = 0; $iii < $lp_ary_cnt; $iii++ ) {
			$match = true; // 条件に合致するかを判定するフラグ
			foreach ( $filters as $field => $value ) {
				if ( ( ! isset( $lp_ary[ $iii ][ $field ] ) ) || $lp_ary[ $iii ][ $field ] !== $value ) {
					$match = false;
					break; // 一つでも条件に合わなければこの行は対象外
				}
			}
			if ( $match ) {
				$filtered_lps[] = $lp_ary[ $iii ];
			}
		}

		// トラッキングIDに対応するドメインを取得
		//global $qahm_data_api;
		$domain     = null;
		$sitemanage = $this->get_sitemanage();
		if ( $sitemanage ) {
			foreach ( $sitemanage as $site ) {
				if ( $site['tracking_id'] === $tracking_id ) {
					$domain = $site['domain'];
					break;
				}
			}
		}

		// フィルタリング済みデータを集約
		$filtered_lp_data_table_ary = $this->aggregate_lp_data_by_pageurl( $filtered_lps, $domain );

		//sort ※[0]でソートはしないからこのifでOKとする
		if ( $sort_by && isset( $filtered_lp_data_table_ary[0][ $sort_by ] ) ) {
			$sort_by = array_column( $filtered_lp_data_table_ary, $sort_by );
			array_multisort( $sort_by, $sort_order, $filtered_lp_data_table_ary );
		}

		// 最大取得件数を超える場合は、指定された件数まで切り詰める
		if ( $max_results && $this->wrap_count( $filtered_lp_data_table_ary ) > $max_results ) {
			$filtered_lp_data_table_ary = array_slice( $filtered_lp_data_table_ary, 0, $max_results );
		}

		return $filtered_lp_data_table_ary;
	}

	/*
	get_ap_data関数やget_lp_data関数は、ユニークなURLをキーとしてデータを集計しているが、
	その際にURLの大文字小文字を区別しているため、同じURLでも大文字小文字が異なる場合には別のデータとして集計されてしまう。
	そのため、URLの大文字小文字を区別せずにデータを集計するための処理を追加する。

	ただし、ZEROではタグ発行のページでURLの大文字小文字を区別しているため、
	この設定の結果によっては、URLの大文字小文字を区別する場合、しない場合とでデータの集計方法を変更しなければならない。

	ver 2.2.0.0 時点で以上の課題を抱えている。
	*/
	/**
	 * ページURLごとにデータを集約する，Directの処理なども行う　→「ランディングページ」表にそのまま使える形にする　※インデックス配列
	 * [
	 *    [
	 *         0  => [123],                          // ページID配列 (page_id)
	 *         1  => 'Landing Page Title',           // タイトル (title)
	 *         2  => 'https://example.com/page1',    // URL (url)
	 *         3  => 50,                             // 合計セッション数 (session_count)
	 *         4  => 20,                             // 合計新規セッション数 (new_session)
	 *         5  => 15,                             // 合計新規ユーザー数 (new_user)
	 *         6  => 5,                              // 合計バウンス数 (bounce_count)
	 *         7  => 120,                            // 合計PV数 (pv_count)
	 *         8  => 3600,                           // 合計セッション時間 (session_time)
	 *         9  => 456,                            // WordPress QA ID (wpqaid)
	 *         10 => [123]                           // ページID配列 (再掲示)
	 *   ],
	 *   ...
	 * ]
	 */
	public function aggregate_lp_data_by_pageurl( $lp_ary, $domain ) {
		$lp_cnt = $this->wrap_count( $lp_ary );

		// ハッシュマップを初期化
		$lps_map = array();

		// 配列の要素数を減らすための変数を定義
		$new_lps_ary = array();

		for ( $iii = 0; $iii < $lp_cnt; $iii++ ) {

			// 必要な変数を抽出
			$pageid  = $lp_ary[ $iii ]['page_id'];
			$wpqaid  = $lp_ary[ $iii ]['wp_qa_id'];
			$title   = $lp_ary[ $iii ]['title'];
			$url     = $lp_ary[ $iii ]['url'];
			$source  = $lp_ary[ $iii ]['source_domain'] ?? '';
			$medium  = $lp_ary[ $iii ]['utm_medium'] ?? '';
			$pvcnt   = (int) $lp_ary[ $iii ]['pv_count'];
			$session = (int) $lp_ary[ $iii ]['session_count'];
			$usercnt = (int) $lp_ary[ $iii ]['user_count'];
			$bounce  = (int) $lp_ary[ $iii ]['bounce_count'];
			$timeon  = (int) $lp_ary[ $iii ]['session_time'];

			// `Direct` の処理
			if ( $source === 'direct' || $source === $domain ) {
				$medium = 'Direct';
			}

			// 新規セッションと新規ユーザーを処理
			$newsession = 0;
			$newuser    = 0;
			if ( $lp_ary[ $iii ]['is_newuser'] ) {
				$newsession = $session;
				$newuser    = $usercnt;
			}

			// ハッシュマップにデータを追加
			if ( ! isset( $lps_map[ $pageid ] ) ) {
				// ページIDが存在しない場合は新しいエントリを作成
				$lps_map[ $pageid ] = array(
					$pageid,
					$title,
					$url,
					$session,
					$newsession,
					$newuser,
					$bounce,
					$pvcnt,
					$timeon,
					$wpqaid,
					$pageid,
				);
			} else {
				// ページIDが存在する場合は既存のエントリを更新
				$lps_map[ $pageid ][3] += $session;
				$lps_map[ $pageid ][4] += $newsession;
				$lps_map[ $pageid ][5] += $newuser;
				$lps_map[ $pageid ][6] += $bounce;
				$lps_map[ $pageid ][7] += $pvcnt;
				$lps_map[ $pageid ][8] += $timeon;
			}
		}

		// ハッシュマップから元の配列に変換
		$lps_ary = array_values( $lps_map );

		$lpscnt = $this->wrap_count( $lps_ary );

		for ( $ccc = 0; $ccc < $lpscnt; $ccc++ ) {
			if ( ! is_array( $lps_ary[ $ccc ][0] ) ) {
				$current_url = $lps_ary[ $ccc ][2];
				$urllow      = strtolower( $current_url ); // URLを小文字に変換
				$pageid      = $lps_ary[ $ccc ][0];
				$title       = $lps_ary[ $ccc ][1];
				$session     = (int) $lps_ary[ $ccc ][3];
				$newsession  = (int) $lps_ary[ $ccc ][4];
				$newuser     = (int) $lps_ary[ $ccc ][5];
				$bounce      = (int) $lps_ary[ $ccc ][6];
				$pvcount     = (int) $lps_ary[ $ccc ][7];
				$timeon      = (int) $lps_ary[ $ccc ][8];
				$wpqaid      = $lps_ary[ $ccc ][9];

				// URL がすでにマップに存在する場合はデータをマージ
				if ( isset( $cleansing_url_map[ $urllow ] ) ) {
					$existing_data                = &$cleansing_url_map[ $urllow ];
					$existing_data['pageids'][]   = $pageid;
					$existing_data['session']    += $session;
					$existing_data['newsession'] += $newsession;
					$existing_data['newuser']    += $newuser;
					$existing_data['bounce']     += $bounce;
					$existing_data['pvcount']    += $pvcount;
					$existing_data['timeon']     += $timeon;
					$except_set[ $pageid ]        = true; // 重複したページIDは除外する
				} else {
					// 新規URLの場合はマップに追加
					$cleansing_url_map[ $urllow ] = array(
						'pageids'    => array( $pageid ),
						'title'      => $title,
						'url'        => $urllow,
						'session'    => $session,
						'newsession' => $newsession,
						'newuser'    => $newuser,
						'bounce'     => $bounce,
						'pvcount'    => $pvcount,
						'timeon'     => $timeon,
						'wpqaid'     => $wpqaid,
					);
				}
			}
		}

		// マップから最終的な結果配列を作成
		$new_cleansing_url_ary = array();
		if ( is_array( $cleansing_url_map ) ) {
			foreach ( $cleansing_url_map as $data ) {
				// except_set に含まれないものだけを配列に追加（基本的にはクレンジングURLごとにマージされているが、念のため重複ページIDを確認・除外）
				if ( ! isset( $except_set[ $data['pageids'][0] ] ) ) {
					$new_cleansing_url_ary[] = array(
						$data['pageids'],
						$data['title'],
						$data['url'],
						$data['session'],
						$data['newsession'],
						$data['newuser'],
						$data['bounce'],
						$data['pvcount'],
						$data['timeon'],
						$data['wpqaid'],
						$data['pageids'],
					);
				}
			}
		}

		// 最終的な結果を返す
		$lps_ary = $new_cleansing_url_ary;
		return $lps_ary;

		// 変更前の旧コード　ここから ---------------------------------------------------------------------------------------------
		//      for ( $iii = 0; $iii < $lp_cnt; $iii++ ) {
		//
		//            //usually start
		//            $pageid  = $lp_ary[$iii]['page_id'];
		//            $wpqaid  = $lp_ary[$iii]['wp_qa_id'];
		//            $title   = $lp_ary[$iii]['title'];
		//            $url     = $lp_ary[$iii]['url'];
		//            $source  = $lp_ary[$iii]['source_domain'];
		//            $utm_sce = $lp_ary[$iii]['utm_source'];
		//            if ( $source === null ) { $source = ''; }
		//            $medium  = $lp_ary[$iii]['utm_medium'];
		//            if ( $medium === null ) { $medium = ''; }
		//            if ( $source === 'direct' || $source === $domain ) { $medium = 'Direct'; }
		//            $pvcnt   = (int)($lp_ary[$iii]['pv_count']);
		//            $session = (int)($lp_ary[$iii]['session_count']);
		//            $usercnt = (int)($lp_ary[$iii]['user_count']);
		//            $bounce  = (int)($lp_ary[$iii]['bounce_count']);
		//            $timeon  = (int)($lp_ary[$iii]['session_time']);
		//
		//            $newsession = 0;
		//            $newuser    = 0;
		//            if ( $lp_ary[$iii]['is_newuser'] ) {
		//                $newsession = (int)( $session );
		//                $newuser    = (int)( $usercnt );
		//            }
		//
		//            // landingpage report
		//            $is_find = false;
		//            $lpscnt  = $this->wrap_count( $lps_ary );
		//            for ( $ppp = 0; $ppp < $lpscnt; $ppp++ ) {
		//                if ( $lps_ary[$ppp][0] === $pageid ) {
		//                    $lps_ary[$ppp][3] += $session;
		//                    $lps_ary[$ppp][4] += $newsession;
		//                    $lps_ary[$ppp][5] += $newuser;
		//                    $lps_ary[$ppp][6] += $bounce;
		//                    $lps_ary[$ppp][7] += $pvcnt;
		//                    $lps_ary[$ppp][8] += $timeon;
		//                    $is_find = true;
		//                    break;
		//                }
		//            }
		//            if ( ! $is_find ) {
		//                $lps_ary[] = [ $pageid, $title, $url, $session, $newsession, $newuser, $bounce, $pvcnt, $timeon, $wpqaid, $pageid ];
		//            }
		//        }
		//
		//
		//
		//      //urlクレンジング。大文字だった場合に小文字と同一視してマージする。page_idは配列にする。
		//      $cleansing_url_ary = [];
		//      $except_ary      = [];
		//      $cidx            = 0;
		//      $lpscnt = $this->wrap_count( $lps_ary );
		//      for ( $ccc = 0; $ccc < $lpscnt; $ccc++ ) {
		//          //この処理で対応したpage_idはArrayにしておくことで飛ばす
		//          if ( ! is_array( $lps_ary[$ccc][0] ) ) {
		//              if ( preg_match('/[A-Z]/',  $lps_ary[$ccc][2] ) ) {
		//                  //search and re calc
		//                  $pageids = [];
		//                  $pageids[] = $lps_ary[$ccc][0];
		//                  $title    = $lps_ary[$ccc][1];
		//                  $urllow   = strtolower($lps_ary[$ccc][2]);
		//                  $session = $lps_ary[$ccc][3];
		//                  $newsession = $lps_ary[$ccc][4];
		//                  $newuser    = $lps_ary[$ccc][5];
		//                  $bounce = $lps_ary[$ccc][6];
		//                  $pvcount  = $lps_ary[$ccc][7];
		//                  $timeon    = $lps_ary[$ccc][8];
		//                  $wpqaid   = $lps_ary[$ccc][9];
		//
		//                  for ( $sss = 0; $sss < $lpscnt; $sss++ ) {
		//                      $nowlow = strtolower($lps_ary[$sss][2]);
		//                      if ( $urllow === $nowlow && $ccc !== $sss ) {
		//                          //先に$sssを入れてしまったのでクレンジングから抜く必要がある。
		//                          if ($sss < $ccc) {
		//                              $except_ary[] = $lps_ary[$sss][0];
		//                          }
		//                          $pageids[] = $lps_ary[$sss][0];
		//                          $session += $lps_ary[$sss][3];
		//                          $newsession += $lps_ary[$sss][4];
		//                          $newuser    += $lps_ary[$sss][5];
		//                          $bounce += $lps_ary[$sss][6];
		//                          $pvcount  += $lps_ary[$sss][7];
		//                          $timeon    += $lps_ary[$sss][8];
		//                          $lps_ary[$sss][0] = $pageids;
		//                      }
		//                  }
		//                  $cleansing_url_ary[$cidx] = [$pageids,$title, $urllow, $session, $newsession, $newuser, $bounce, $pvcount, $timeon, $wpqaid, $pageids];
		//                  $cidx++;
		//              } else {
		//                  $cleansing_url_ary[$cidx] = $lps_ary[$ccc];
		//                  $cidx++;
		//              }
		//          }
		//      }
		//      $new_cleansing_url_ary = [];
		//      $cuacnt = $this->wrap_count($cleansing_url_ary);
		//      $exacnt = $this->wrap_count($except_ary);
		//
		//      for ( $iii = 0; $iii < $cuacnt; $iii++ ) {
		//          $is_find = false;
		//          $page_id = 0;
		//          if ( ! is_array( $cleansing_url_ary[$iii][0] ) ) {
		//              $page_id = $cleansing_url_ary[$iii][0];
		//          }
		//          for ( $kkk = 0; $kkk < $exacnt; $kkk++ ) {
		//              if ( $page_id === $except_ary[$kkk]) {
		//                  $is_find = true;
		//              }
		//          }
		//          if (! $is_find ) {
		//              $new_cleansing_url_ary[] =  $cleansing_url_ary[$iii];
		//          }
		//      }
		//
		//      $lps_ary = $new_cleansing_url_ary;
		//      return $lps_ary;
		// 変更前の旧コード　ここまで ---------------------------------------------------------------------------------------------
	}


	// growth page table用
	public function ajax_get_gw_data() {
		$nonce = $this->wrap_filter_input( INPUT_POST, 'nonce' );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_API ) || $this->is_maintenance() ) {
			http_response_code( 406 );
			die( 'nonce error' );
		}
		// 全パラメーターを取得する
		$dateterm    = mb_strtolower( $this->wrap_trim( $this->wrap_filter_input( INPUT_POST, 'date' ) ) );
		$tracking_id = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'tracking_id' ) );
		$resary      = $this->get_gw_data( $dateterm, $tracking_id );
		header( 'Content-type: application/json; charset=UTF-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
		echo $this->wrap_json_encode( $resary );
		die();
	}

	public function get_gw_data( $dateterm, $tracking_id = 'all' ) {
		global $qahm_db;
		global $qahm_data_api;

		/*
		// 元のソースコードに$domain取得コードがあったが、現状は使用していない。そのためコメントアウト
		$domain = null;
		$sitemanage = $qahm_data_api->get_sitemanage();
		if ( $sitemanage ) {
			foreach( $sitemanage as $site ) {
				if ( $site['tracking_id'] === $tracking_id ) {
					$domain = $site['domain'];
					break;
				}
			}
		}
		*/

		$gw_ary = $qahm_db->summary_days_growthpages( $dateterm, $tracking_id );
		$retary = array();
		foreach ( $gw_ary as $line_ary ) {
			$pageid         = $line_ary['page_id'];
			$medium         = $line_ary['utm_medium'];
			$start_pv_count = $line_ary['start_session_count'];
			$end_pv_count   = $line_ary['end_session_count'];
			$title          = $line_ary['title'];
			$url            = $line_ary['url'];
			$wpqaid         = $line_ary['wp_qa_id'];
			$editurl        = admin_url( 'post.php' );
			$editurl        = $editurl . '?post=' . (string) $wpqaid . '\&action=edit';
			//urlの置換
			$growth_rate = 0;
			if ( $start_pv_count <= $end_pv_count && $start_pv_count !== 0 ) {
				$growth_rate = round( $end_pv_count / $start_pv_count * 100 - 100, 2 );
			} else {
				if ( $end_pv_count !== 0 ) {
					$growth_rate = -1 * round( $start_pv_count / $end_pv_count * 100 - 100, 2 );
				}
			}
			$retary[] = array( $pageid, $title, $url, $medium, $start_pv_count, $end_pv_count, $growth_rate, $editurl );
		}
		return $retary;
	}

	// all page table用
	public function ajax_get_ap_data() {
		$nonce = $this->wrap_filter_input( INPUT_POST, 'nonce' );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_API ) || $this->is_maintenance() ) {
			http_response_code( 406 );
			die( 'nonce error' );
		}
		// 全パラメーターを取得する
		$dateterm    = mb_strtolower( $this->wrap_trim( $this->wrap_filter_input( INPUT_POST, 'date' ) ) );
		$tracking_id = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'tracking_id' ) );
		$resary      = $this->get_ap_data( $dateterm, $tracking_id );
		header( 'Content-type: application/json; charset=UTF-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
		echo $this->wrap_json_encode( $resary );
		die();
	}
	// 20240816 koji maruyama speedup
	/*
	get_ap_data関数やget_lp_data関数は、ユニークなURLをキーとしてデータを集計しているが、
	その際にURLの大文字小文字を区別しているため、同じURLでも大文字小文字が異なる場合には別のデータとして集計されてしまう。
	そのため、URLの大文字小文字を区別せずにデータを集計するための処理を追加する。

	ただし、ZEROではタグ発行のページでURLの大文字小文字を区別しているため、
	この設定の結果によっては、URLの大文字小文字を区別する場合、しない場合とでデータの集計方法を変更しなければならない。

	ver 2.2.0.0 時点で以上の課題を抱えている。
	*/
	public function get_ap_data( $dateterm, $tracking_id = 'all' ) {
		global $qahm_db;

		$aps_ary = array();
		$ap_ary  = $qahm_db->summary_days_allpages( $dateterm, $tracking_id );
		$ap_cnt  = $this->wrap_count( $ap_ary );

		// ハッシュマップを初期化
		$aps_map = array();

		// データを一度に処理し、マージを行う
		for ( $iii = 0; $iii < $ap_cnt; $iii++ ) {
			$pageid  = $ap_ary[ $iii ]['page_id'];
			$wpqaid  = $ap_ary[ $iii ]['wp_qa_id'];
			$title   = $ap_ary[ $iii ]['title'];
			$url     = $ap_ary[ $iii ]['url'];
			$source  = $ap_ary[ $iii ]['source_domain'] ?? '';
			$medium  = $ap_ary[ $iii ]['utm_medium'] ?? '';
			$usercnt = (int) $ap_ary[ $iii ]['user_count'];
			$bounce  = (int) $ap_ary[ $iii ]['bounce_count'];
			$pvcnt   = (int) $ap_ary[ $iii ]['pv_count'];
			$exitcnt = (int) $ap_ary[ $iii ]['exit_count'];
			$timeon  = (int) $ap_ary[ $iii ]['time_on_page'];
			$lpcount = (int) $ap_ary[ $iii ]['lp_count'];

			// ソースが direct の場合は medium を Direct に設定
			if ( $source === 'direct' ) {
				$medium = 'Direct';
			}

			// ハッシュマップで検索と更新
			if ( isset( $aps_map[ $pageid ] ) ) {
				$aps_map[ $pageid ][3] += $pvcnt;
				$aps_map[ $pageid ][4] += $usercnt;
				$aps_map[ $pageid ][5] += $timeon;
				$aps_map[ $pageid ][6] += $lpcount;
				$aps_map[ $pageid ][7] += $bounce;
				$aps_map[ $pageid ][8] += $exitcnt;
			} else {
				$aps_map[ $pageid ] = array( $pageid, $title, $url, $pvcnt, $usercnt, $timeon, $lpcount, $bounce, $exitcnt, $wpqaid, $pageid );
			}
		}

		// ハッシュマップから元の配列に変換
		$aps_ary = array_values( $aps_map );
		$apscnt  = $this->wrap_count( $aps_ary );

		// URLクレンジング用のマップとセットを初期化
		$cleansing_url_map = array();
		$except_set        = array();

		// URLのクレンジングとマージ処理
		for ( $ccc = 0; $ccc < $apscnt; $ccc++ ) {
			if ( ! is_array( $aps_ary[ $ccc ][0] ) ) {
				$current_url = $aps_ary[ $ccc ][2];
				$urllow      = strtolower( $current_url );
				$pageid      = $aps_ary[ $ccc ][0];
				$title       = $aps_ary[ $ccc ][1];
				$pvcnt       = (int) $aps_ary[ $ccc ][3];
				$usercnt     = (int) $aps_ary[ $ccc ][4];
				$timeon      = (int) $aps_ary[ $ccc ][5];
				$lpcount     = (int) $aps_ary[ $ccc ][6];
				$bounce      = (int) $aps_ary[ $ccc ][7];
				$exitcnt     = (int) $aps_ary[ $ccc ][8];
				$wpqaid      = $aps_ary[ $ccc ][9];

				// URL がすでにマップに存在する場合はデータをマージ
				if ( isset( $cleansing_url_map[ $urllow ] ) ) {
					$existing_data              = &$cleansing_url_map[ $urllow ];
					$existing_data['pageids'][] = $pageid;
					$existing_data['pvcnt']    += $pvcnt;
					$existing_data['usercnt']  += $usercnt;
					$existing_data['timeon']   += $timeon;
					$existing_data['lpcount']  += $lpcount;
					$existing_data['bounce']   += $bounce;
					$existing_data['exitcnt']  += $exitcnt;
					$except_set[ $pageid ]      = true;
				} else {
					// 新規URLの場合はマップに追加
					$cleansing_url_map[ $urllow ] = array(
						'pageids' => array( $pageid ),
						'title'   => $title,
						'url'     => $urllow,
						'pvcnt'   => $pvcnt,
						'usercnt' => $usercnt,
						'timeon'  => $timeon,
						'lpcount' => $lpcount,
						'bounce'  => $bounce,
						'exitcnt' => $exitcnt,
						'wpqaid'  => $wpqaid,
					);
				}
			}
		}

		// マップから最終的な結果配列を作成
		$new_cleansing_url_ary = array();
		foreach ( $cleansing_url_map as $data ) {
			// except_set に含まれないものだけを新しい配列に追加
			if ( ! isset( $except_set[ $data['pageids'][0] ] ) ) {
				$new_cleansing_url_ary[] = array(
					$data['pageids'],
					$data['title'],
					$data['url'],
					$data['pvcnt'],
					$data['usercnt'],
					$data['timeon'],
					$data['lpcount'],
					$data['bounce'],
					$data['exitcnt'],
					$data['wpqaid'],
					$data['pageids'],
				);
			}
		}

		// 最終的な結果を返す
		return $new_cleansing_url_ary;
	}

	//  public function get_ap_data( $dateterm, $tracking_id="all" ) {
	//      $aps_ary = [];
	//
	//      global $qahm_db;
	//      $ap_ary = $qahm_db->summary_days_allpages( $dateterm, $tracking_id );
	//      //遅いので上記に変更
	////        $ap_ary = $this->select_data( 'vr_summary_allpage', '*', $dateterm );
	//
	//      $ap_cnt = $this->wrap_count( $ap_ary );
	//      for ( $iii = 0; $iii < $ap_cnt; $iii++ ) {
	//            // allpage report
	//            $pageid  = $ap_ary[$iii]['page_id'];
	//            $wpqaid  = $ap_ary[$iii]['wp_qa_id'];
	//            $title   = $ap_ary[$iii]['title'];
	//            $url     = $ap_ary[$iii]['url'];
	//            $source  = $ap_ary[$iii]['source_domain'];
	//            if ( $source === null ) { $source = ''; }
	//            $medium  = $ap_ary[$iii]['utm_medium'];
	//            if ( $medium === null ) { $medium = ''; }
	//            if ( $source === 'direct' ) { $medium = 'Direct'; }
	//            $usercnt = $ap_ary[$iii]['user_count'];
	//            $bounce  = $ap_ary[$iii]['bounce_count'];
	//            $pvcnt   = $ap_ary[$iii]['pv_count'];
	//            $exitcnt = $ap_ary[$iii]['exit_count'];
	//            $timeon  = $ap_ary[$iii]['time_on_page'];
	//            $lpcount = $ap_ary[$iii]['lp_count'];
	//            if ( ! $ap_ary[$iii]['is_newuser'] ) {
	//                $newuser = 0;
	//            }
	//            $is_find = false;
	//            $apscnt = $this->wrap_count($aps_ary);
	//            for ( $ppp = 0; $ppp < $apscnt; $ppp++ ) {
	//                if ( $aps_ary[$ppp][0] === $pageid ) {
	//                    $aps_ary[$ppp][3] += $pvcnt;
	//                    $aps_ary[$ppp][4] += $usercnt;
	//                    $aps_ary[$ppp][5] += $timeon;
	//                    $aps_ary[$ppp][6] += $lpcount;
	//                    $aps_ary[$ppp][7] += $bounce;
	//                    $aps_ary[$ppp][8] += $exitcnt;
	//                    $is_find = true;
	//                    break;
	//                }
	//            }
	//            if ( ! $is_find ) {
	//                $aps_ary[] = ( [ $pageid, $title, $url, $pvcnt, $usercnt, $timeon, $lpcount, $bounce, $exitcnt, $wpqaid, $pageid ]);
	//            }
	//        }
	//      //urlクレンジング。大文字だった場合に小文字と同一視してマージする。page_idは配列にする。
	//      $cleansing_url_ary = [];
	//      $except_ary      = [];
	//      $cidx            = 0;
	//      $apscnt          = $this->wrap_count($aps_ary);
	//      for ( $ccc = 0; $ccc < $apscnt; $ccc++ ) {
	//          //この処理で対応したpage_idはArrayにしておくことで飛ばす
	//          if ( ! is_array($aps_ary[$ccc][0]) ) {
	//              if ( preg_match('/[A-Z]/', $aps_ary[$ccc][2] ) ) {
	//                  //search and re calc
	//                  $pageids = [];
	//                  $pageids[] = $aps_ary[$ccc][0];
	//                  $title    = $aps_ary[$ccc][1];
	//                  $urllow   = strtolower($aps_ary[$ccc][2]);
	//                  $pvcounts = $aps_ary[$ccc][3];
	//                  $usercnts = $aps_ary[$ccc][4];
	//                  $times    = $aps_ary[$ccc][5];
	//                  $lpcounts = $aps_ary[$ccc][6];
	//                  $bounces  = $aps_ary[$ccc][7];
	//                  $exits    = $aps_ary[$ccc][8];
	//                  $wpqaid   = $aps_ary[$ccc][9];
	//
	//                  for ( $sss = 0; $sss < $apscnt; $sss++ ) {
	//                      $nowlow = strtolower($aps_ary[$sss][2]);
	//                      if ( $urllow === $nowlow && $ccc !== $sss ) {
	//                          //先に$sssを入れてしまったのでクレンジングから抜く必要がある。
	//                          if ($sss < $ccc) {
	//                              $except_ary[] = $aps_ary[$sss][0];
	//                          }
	//                          $pageids[] = $aps_ary[$sss][0];
	//                          $pvcounts += $aps_ary[$sss][3];
	//                          $usercnts += $aps_ary[$sss][4];
	//                          $times    += $aps_ary[$sss][5];
	//                          $lpcounts += $aps_ary[$sss][6];
	//                          $bounces  += $aps_ary[$sss][7];
	//                          $exits    += $aps_ary[$sss][8];
	//                          $aps_ary[$sss][0] = $pageids;
	//                      }
	//                  }
	//                  $cleansing_url_ary[$cidx] = [$pageids, $title, $urllow, $pvcounts, $usercnts, $times, $lpcounts, $bounces, $exits, $wpqaid, $pageids];
	//                  $cidx++;
	//              } else {
	//                  $cleansing_url_ary[$cidx] = $aps_ary[$ccc];
	//                  $cidx++;
	//              }
	//          }
	//      }
	//      $new_cleansing_url_ary = [];
	//      $clucnt = $this->wrap_count($cleansing_url_ary);
	//      for ( $iii = 0; $iii < $clucnt; $iii++ ) {
	//          $is_find = false;
	//          $page_id = 0;
	//          if ( ! is_array( $cleansing_url_ary[$iii][0] ) ) {
	//              $page_id = $cleansing_url_ary[$iii][0];
	//          }
	//          $ecacnt = $this->wrap_count($except_ary);
	//          for ( $kkk = 0; $kkk < $ecacnt; $kkk++ ) {
	//              if ( $page_id === $except_ary[$kkk]) {
	//                  $is_find = true;
	//              }
	//          }
	//          if (! $is_find ) {
	//              $new_cleansing_url_ary[] = $cleansing_url_ary[$iii];
	//          }
	//      }
	//      $aps_ary = $new_cleansing_url_ary;
	//      return $aps_ary;
	//  }


	// QA ZERO ___ SEO data
	/**
	 * ページごとのKeywordの詳細情報を配列で取得
	 *
	 * @param string 'Y-m-d' $start_date, $end_date
	 * @param bool $single_lp
	 * @param int $page_id ※使うときは必ずint型で渡すこと
	 *
	 * // returnデータ構造（サンプル）
	 * $param_ary = [
	 *     'page_id_1' => [ //実際の「page_id（番号）」がキー
	 *         'wp_qa_id' => 'qa_id_1',
	 *         'title' => 'Sample Page Title 1',
	 *         'url' => 'https://example.com/page1',
	 *         'keyword' => [
	 *             'keyword_1' => [ //実際の「キーワード」がキー
	 *                 'date' => [ //期間分の日ごとデータ。日付dateではなく、index番号＝日付順。0が$start_date。データがない$date_idxは初期値
	 *                     0 => [ 'impressions' => 100, 'clicks' => 10, 'position' => 1, ],
	 *                     1 => [ 'impressions' => 150, 'clicks' => 15, 'position' => 2, ],
	 *                     // ...
	 *                 ],
	 *             ],
	 *             // 他のキーワードが続く...
	 *         ],
	 *     ],
	 *    // 他のページが続く...($single_lp=true の時を除く)
	 * ];
	 *
	 */
	public function get_gsc_lp_keywords_detail( $tracking_id, $start_date, $end_date, $single_lp = false, $page_id = '' ) {
		// #1153: gsc ファイル名（google-api が計測サイトTZで命名）に揃える。
		$clock   = QAHM_Time::get_site_clock( $tracking_id );
		$gsc_dir = $this->get_data_dir_path( 'view/' . $tracking_id . '/gsc' );

		$param_ary    = array();
		$max_date_idx = $clock->xday_num( $end_date, $start_date ) + 1;
		for ( $date_idx = 0; $date_idx < $max_date_idx; $date_idx++ ) {
			$tar_date          = $clock->xday_str( (int) $date_idx, $start_date );
			$gsc_lp_query_path = $gsc_dir . $tar_date . '_gsc_lp_query.php';

			if ( ! $this->wrap_exists( $gsc_lp_query_path ) ) {
				continue;
			}

			$lp_query_ary = $this->wrap_get_contents( $gsc_lp_query_path );
			$lp_query_ary = $this->wrap_unserialize( $lp_query_ary );
			if ( ! is_array( $lp_query_ary ) ) {
				continue;
			}

			foreach ( $lp_query_ary as $nowpage ) {

				if ( $single_lp && $nowpage['page_id'] !== $page_id ) {
					continue;
				}

				$pid = $nowpage['page_id'];
				$wpi = $nowpage['wp_qa_id'];
				$ttl = $nowpage['title'];
				$url = $nowpage['url'];

				if ( isset( $nowpage['query'] ) ) {
					$param_ary[ $pid ]['wp_qa_id'] = $wpi;
					$param_ary[ $pid ]['title']    = $ttl;
					$param_ary[ $pid ]['url']      = $url;

					foreach ( $nowpage['query'] as $nowquery ) {
						$key = $nowquery['keyword'];
						$pos = $nowquery['position'];
						$imp = $nowquery['impressions'];
						$clk = $nowquery['clicks'];

						if ( ! isset( $param_ary[ $pid ]['keyword'][ $key ] ) ) {
							// キーワード'date'配列の初期化
							$param_ary[ $pid ]['keyword'][ $key ]['date'] = array_fill(
								0,
								$max_date_idx,
								array(
									'impressions' => 0,
									'clicks'      => 0,
									'position'    => null,
								)
							);
						}
						// 現在の日付$date_idxにデータを上書き
						$param_ary[ $pid ]['keyword'][ $key ]['date'][ $date_idx ]['impressions'] = $imp;
						$param_ary[ $pid ]['keyword'][ $key ]['date'][ $date_idx ]['clicks']      = $clk;
						$param_ary[ $pid ]['keyword'][ $key ]['date'][ $date_idx ]['position']    = $pos;

					}
				}
			}
		}

		return $param_ary;
	}

	/**
	 * ページごとに「キーワード」データを計算してまとめる
	 *
	 * // returnデータ構造（サンプル）
	 * [
	 *    'page_id_1' => [ //実際の「page_id（番号）」がキー
	 *         'wp_qa_id' => 'qa_id_1',
	 *         'title' => 'Sample Page Title 1',
	 *         'url' => 'https://example.com/page1',
	 *         'keyword' => [
	 *             'keyword_1' => [ //実際の「キーワード」がキー
	 *                 'imp' => 250,
	 *                 'clk' => 25,
	 *                 'ctr' => 10.0,
	 *                 'prevpos' => 1,
	 *                 'rankdiff' => -1,
	 *                 'lastpos' => 2,
	 *                 'rankmax' => 1,
	 *                 'rankmin' => 2,
	 *                 'trend' => -0.5,
	 *                 'soukankeisu' => 0.95,
	 *             ],
	 *             // 他のキーワードが続く...
	 *         ],
	 *     ],
	 *     // 他のページが続く...
	 * ];
	 *
	 */
	public function get_gsc_lp_keywords_calc_data( $tracking_id, $start_date, $end_date ) {
		// #1153: 日数カウントは計測サイトTZで（gsc は per-tid）。
		$clock = QAHM_Time::get_site_clock( $tracking_id );

		$detail_ary = $this->get_gsc_lp_keywords_detail( $tracking_id, $start_date, $end_date );

		$all_keyword_calc_ary = array();
		$date_max             = $clock->xday_num( $end_date, $start_date ) + 1;
		foreach ( $detail_ary as $pid => $key_ary ) {
			$all_keyword_calc_ary[ $pid ] = array(
				'wp_qa_id' => $key_ary['wp_qa_id'],
				'title'    => $key_ary['title'],
				'url'      => $key_ary['url'],
				'keyword'  => array(),
			);

			// ここからキーワードごとの計算
			foreach ( $key_ary['keyword'] as $key => $ary ) {
				$imp     = 0;
				$clk     = 0;
				$posary  = array();
				$prevpos = 99;
				$temppos = 0;
				$rankmax = 10000;
				$rankmin = 1;
				$poscnt  = 0;

				$dateXYAry = array(); // データセットを初期化

				for ( $ddd = 0; $ddd < $date_max; $ddd++ ) {

					$nowpos = $ary['date'][ $ddd ]['position'];
					$nowclk = $ary['date'][ $ddd ]['clicks'];
					$imp   += $ary['date'][ $ddd ]['impressions'];
					$clk   += $ary['date'][ $ddd ]['clicks'];

					if ( $nowpos !== null ) {
						$temppos = $nowpos;
						if ( $prevpos !== $nowpos && $nowpos !== $ary['date'][ $date_max - 1 ]['position'] ) {
							$prevpos = $nowpos;
						}
					}

					$posary[] = $nowpos;

					if ( $nowpos !== null ) {
						if ( $rankmin < $nowpos ) {
							$rankmin = $nowpos;
						}
						if ( $nowpos < $rankmax ) {
							$rankmax = $nowpos;
						}
						++$poscnt;
					}

					// $dateXYAry にデータを追加
					$dateXYAry[] = array(
						'dateIndex' => $ddd,
						'position'  => $nowpos,
					);
				}

				$ctr     = ( $clk / $imp ) * 100;
				$lastpos = $ary['date'][ $date_max - 1 ]['position'] ?? $temppos;
				// トレンドと相関係数の計算
				list( $trend, $soukankeisu ) = $this->calculate_trend_and_correlation( $dateXYAry );

				$all_keyword_calc_ary[ $pid ]['keyword'][ $key ] = array(
					'imp'         => $imp,
					'clk'         => $clk,
					'ctr'         => $ctr,
					'prevpos'     => $prevpos,
					'rankdiff'    => $prevpos - $lastpos,
					'lastpos'     => $lastpos,
					'rankmax'     => $rankmax,
					'rankmin'     => $rankmin,
					'trend'       => $trend,
					'soukankeisu' => $soukankeisu,
				);
			}
		}

		return $all_keyword_calc_ary;
	}

	/**
	 * 回帰直線の傾きと切片、および回帰直線データを計算
	 *
	 * @param array $posary ランク(position)の配列
	 * @return array [$m, $b, $regary]
	 */
	public function calculate_regression( $posary ) {
		$n = $this->wrap_count( $posary );
		if ( $n === 0 ) {
			return array( 0, 0, array_fill( 0, $n, null ) );
		}

		$dateXYAry = array();
		foreach ( $posary as $index => $value ) {
			if ( $value !== null ) {
				$dateXYAry[] = array(
					'dateIndex' => $index,
					'position'  => $value,
				);
			}
		}

		$valid_count = $this->wrap_count( $dateXYAry );
		if ( $valid_count <= 1 ) {
			return array( 0, 0, array_fill( 0, $n, null ) );
		}

		$sum_x = $sum_y = $sum_x2 = $sum_xy = 0;
		foreach ( $dateXYAry as $data ) {
			$x = $data['dateIndex'];
			$y = $data['position'];

			$sum_x  += $x;
			$sum_y  += $y;
			$sum_x2 += $x * $x;
			$sum_xy += $x * $y;
		}

		$denominator = $valid_count * $sum_x2 - $sum_x * $sum_x;
		$m           = ( $denominator != 0 ) ? ( ( $valid_count * $sum_xy - $sum_x * $sum_y ) / $denominator ) : 0;
		$b           = ( $sum_y - $m * $sum_x ) / $valid_count;

		// 回帰直線データを生成
		$regary = array();
		foreach ( $posary as $index => $value ) {
			if ( $value !== null ) {
				$regary[] = $m * $index + $b;
			} else {
				$regary[] = null;
			}
		}

		return array( $m, $b, $regary );
	}

	/**
	 * トレンドと相関係数を計算
	 *
	 * @param array $dateXYAry x, yのペア配列
	 * @return array [$trend, $correlation]
	 */
	public function calculate_trend_and_correlation( $dateXYAry ) {
		if ( empty( $dateXYAry ) ) {
			return array( null, null );
		}

		// $posary を抽出
		$posary = array_column( $dateXYAry, 'position', 'dateIndex' );

		// 回帰直線を計算
		list($m, $b, $regary) = $this->calculate_regression( $posary );

		// 相関係数を計算
		$n     = $this->wrap_count( $dateXYAry );
		$sum_x = $sum_y = $sum_x2 = $sum_y2 = $sum_xy = 0; // 初期化

		foreach ( $dateXYAry as $data ) {
			$x = $data['dateIndex'];
			$y = $data['position'];
			if ( $y === null ) {
				continue;
			}

			$sum_x  += $x;
			$sum_y  += $y;
			$sum_x2 += $x * $x;
			$sum_y2 += $y * $y;
			$sum_xy += $x * $y;
		}

		$numerator   = $n * $sum_xy - $sum_x * $sum_y;
		$denominator = sqrt( ( $n * $sum_x2 - $sum_x * $sum_x ) * ( $n * $sum_y2 - $sum_y * $sum_y ) );
		$correlation = ( $denominator != 0 ) ? ( $numerator / $denominator ) : 0;

		// トレンドを計算
		$trend       = round( $m * -100, 0 );
		$correlation = round( $correlation, 2 );

		return array( $trend, $correlation );
	}




	// QA ZERO START
	// add $connect_tid
	public function select_data( $table, $column, $date_or_id, $count = false, $where = '', $connect_tid = 'all' ) {
		// QA ZERO END
		global $qahm_db;

		//table名の補完
		$table_allcol = $this->show_column( $table );
		if ( $this->wrap_strpos( $table, $this->prefix ) === false ) {
			$table = $this->prefix . $table;
		}

		//必須フィールドのチェック
		$colname     = array();
		$is_err      = false;
		$is_datetime = false;
		switch ( $table ) {
			case $this->prefix . 'view_pv':
			case $this->prefix . 'vr_view_pv':
				$colname['date'] = 'access_time';
				$colname['id']   = 'pv_id';
				$is_datetime     = true;
				break;

			case $this->prefix . 'summary_days_access':
				$colname['date'] = 'date';
				$colname['id']   = '';
				$is_datetime     = false;
				break;

			case $this->prefix . 'summary_days_access_detail':
				$colname['date'] = 'date';
				$colname['id']   = '';
				$is_datetime     = false;
				break;

			case $this->prefix . 'vr_summary_allpage':
				$colname['date'] = 'date';
				$colname['id']   = '';
				$is_datetime     = false;
				break;

			case $this->prefix . 'vr_summary_landingpage':
				$colname['date'] = 'date';
				$colname['id']   = '';
				$is_datetime     = false;
				break;

			case $this->prefix . 'qa_page_version_hist':
				$colname['date'] = 'update_date';
				$colname['id']   = 'version_id';
				break;

			case $this->prefix . 'qa_readers':
				$colname['date'] = 'update_date';
				$colname['id']   = 'reader_id';
				break;

			case $this->prefix . 'qa_utm_sources':
				$colname['id'] = 'source_id';
				break;

			case $this->prefix . 'qa_pages':
				$colname['date'] = 'update_date';
				$colname['id']   = 'page_id';
				break;

			case $this->prefix . 'qa_pv_log':
				$colname['date'] = 'access_time';
				$colname['id']   = 'pv_id';
				$is_datetime     = true;
				break;

			case $this->prefix . 'vr_view_session':
				// QA ZERO START
				return $this->get_vr_view_session( $column, $date_or_id, $where, $count, $connect_tid );
			// QA ZERO END

			default:
				$is_err = true;
				break;
		}
		if ( $is_err ) {
			http_response_code( 401 );
			die( 'table error' );
		}
		//カラムのチェック
		if ( $column !== '*' ) {
			$columns = $this->wrap_explode( ',', $column );
			if ( 1 < $this->wrap_count( $columns ) && $count === true ) {
				http_response_code( 402 );
				die( 'count too many colmuns error' );
			}
			foreach ( $columns as $col ) {
				if ( ! $this->wrap_in_array( $col, $table_allcol ) ) {
					http_response_code( 402 );
					die( 'colmuns error' );
				}
			}
		}

		//カラムの完成
		if ( $count ) {
			$column = '$this->wrap_count(' . $column . ')';
		}

		// 最初のクエリを作成
		if ( preg_match( '/^date\s*=\s*between\s*([0-9]{4}.[0-9]{2}.[0-9]{2})\s*and\s*([0-9]{4}.[0-9]{2}.[0-9]{2})$/', $date_or_id, $datestrs ) ) {
			//$basequery = 'select ' . $column . ' from ' . $table . ' where ' . $colname[ 'date' ] . ' = %s between %s';
			$basequery = 'select ' . $column . ' from ' . $table . ' where ' . $colname['date'] . ' between %s and %s';
			$aday      = $datestrs[1];
			$bday      = $datestrs[2];
			if ( $is_datetime ) {
				if ( $this->wrap_strpos( $aday, ':' ) === false ) {
					$aday = $aday . ' 00:00:00';
				}
				if ( $this->wrap_strpos( $bday, ':' ) === false ) {
					$bday = $bday . ' 23:59:59';
				}
			}
			$query = $this->prepare( $basequery, $aday, $bday );
		} elseif ( preg_match( '/^id\s*=\s*([0-9]*)$/', $date_or_id, $idnum ) ) {
			$basequery = 'select ' . $column . ' from ' . $table . ' where ' . $colname['id'] . ' = %d';
			$query     = $this->prepare( $basequery, $idnum[1] );
		} else {
			$is_err = true;
		}
		if ( $is_err ) {
			http_response_code( 408 );
			die( esc_html( $aday ) . esc_html( $bday ) . 'date_or_id error' );
		}

		if ( $count ) {
			return $this->get_var( $query, 0, 0, $connect_tid );
		} else {
			// QA ZERO START
			return $this->get_results( $query, OBJECT, $connect_tid );
			// QA ZERO END
		}
	}

	public function ajax_get_heatmap_cachelist() {
		$nonce = $this->wrap_filter_input( INPUT_POST, 'nonce' );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_API ) || $this->is_maintenance() ) {
			http_response_code( 400 );
			die( 'nonce error' );
		}

		$data_dir = $this->get_data_dir_path();
		$cachedir = $data_dir . 'cache/';

		$cachelist_ary = $this->wrap_unserialize( $this->wrap_get_contents( $cachedir . 'heatmap_list.php' ) );

		if ( is_array( $cachelist_ary ) ) {
			header( 'Content-type: application/json; charset=UTF-8' );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
			echo $this->wrap_json_encode( $cachelist_ary );
		} else {
			http_response_code( 409 );
			die();
		}
		die();
	}

	//QA ZERO start

	/**
	 * #1508: tracking_id から自サイトのドメインを引く。
	 * 空 medium 導出（QAHM_Base::derive_medium_for_empty_utm）の自ドメイン判定に使う。
	 * tracking_id='all' や未登録は null（＝自ドメイン判定はスキップされる）。
	 *
	 * @param string $tracking_id
	 * @return string|null
	 */
	private function get_own_domain( $tracking_id ) {
		$sitemanage = $this->get_sitemanage();
		if ( $sitemanage ) {
			foreach ( $sitemanage as $site ) {
				if ( $site['tracking_id'] === $tracking_id ) {
					return $site['domain'];
				}
			}
		}
		return null;
	}

	public function get_sitemanage() {
		$sitemanage = $this->wrap_get_option( 'sitemanage' );
		if ( ! $sitemanage ) {
			return null;
		}

		$sitemanage = $this->wrap_array_filter(
			$sitemanage,
			function ( $item ) {
				return isset( $item['status'] ) && $item['status'] !== 255;
			}
		);
		// インデックス番号を0から順に再構築
		$sitemanage = array_values( $sitemanage );
		return $sitemanage;
	}

	//QA ZERO end

	/**
	 * private function
	 */
	private function alltrim( $string ) {
		return str_replace( ' ', '', $string );
	}

	/**
	 * セレクタを正規化して比較可能にする
	 * - 末尾のnth-of-typeは保持（具体的な要素指定）
	 * - 中間のnth-of-typeは削除（構造の揺らぎを吸収）
	 * - ID属性は削除
	 *
	 * @param string $selector セレクタ文字列
	 * @return string 正規化されたセレクタ
	 */
	private function normalize_selector( $selector, $remove_last_element = false ) {
		// まず空白を正規化
		$selector = preg_replace( '/\s+/', '', $selector );

		// セレクタを > で分割
		$parts            = explode( '>', $selector );
		$normalized_parts = array();
		$parts_count      = count( $parts );

		// 末尾から見て最初のnth-of-typeを見つける
		$last_nth_index = -1;
		for ( $i = $parts_count - 1; $i >= 0; $i-- ) {
			if ( ! empty( $parts[ $i ] ) && preg_match( '/:nth-of-type\([0-9]*\)/i', $parts[ $i ] ) ) {
				$last_nth_index = $i;
				break;
			}
		}

		// 末尾要素を削除するか判定
		$should_remove_last = false;
		if ( $remove_last_element && $parts_count > 0 ) {
			$last_part = end( $parts );
			// テキスト装飾系のインライン要素かチェック（spanとlabelは除外）
			$inline_elements = array(
				'strong',
				'em',
				'b',
				'i',
				'u',
				's',
				'del',
				'ins',
				'small',
				'sub',
				'sup',
				'code',
				'kbd',
				'samp',
				'mark',
				'time',
			);

			foreach ( $inline_elements as $element ) {
				if ( preg_match( '/^' . $element . '($|[:#])/', $last_part ) ) {
					$should_remove_last = true;
					break;
				}
			}
		}

		$end_index = $should_remove_last ? $parts_count - 1 : $parts_count;

		foreach ( $parts as $index => $part ) {
			if ( $index >= $end_index ) {
				continue;
			}

			$part = trim( $part );

			// 空要素をスキップ
			if ( empty( $part ) ) {
				continue;
			}

			// ID属性を削除
			$part = preg_replace( '/#[a-zA-Z0-9_-]+/', '', $part );

			// 末尾から見て最初のnth-of-typeを持つ要素の場合は保持
			if ( $index === $last_nth_index ) {
				$normalized_parts[] = $part;
			} else {
				// それ以外はnth-of-typeを削除
				$part = preg_replace( '/:nth-of-type\([0-9]*\)/i', '', $part );
				if ( ! empty( $part ) ) {
					$normalized_parts[] = $part;
				}
			}
		}

		// 再結合
		$normalized = implode( '>', $normalized_parts );

		return strtolower( $normalized );
	}

	/**
	 * ユーザー名とパスワードの認証が通ればnonceを返す
	 */
	public function ajax_get_nonce() {
		$name = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'user_name' ) );
		$pass = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'password' ) );
		$type = $this->alltrim( $this->wrap_filter_input( INPUT_POST, 'client_type' ) );

		$user = wp_authenticate( $name, $pass );
		if ( is_wp_error( $user ) ) {
			echo esc_html__( 'Error: Login authentication failed.', 'qa-heatmap-analytics' );
			die();
		}

		// 管理者権限判定
		// $user->rolesでループしてadminを調べる方法でよさそう
		$is_admin = false;
		foreach ( $user->roles as $role ) {
			if ( 'administrator' === $role ) {
				$is_admin = true;
				break;
			}
		}
		if ( ! $is_admin ) {
			echo esc_html__( 'Error: You do not have administrator privileges.', 'qa-heatmap-analytics' );
			die();
		}

		// 現在はクライアント側で nonce のみを直接受け取る仕様。
		// @todo 将来的に他のAPIレスポンスと統一し、wp_send_json(['nonce' => ...]) に変更する。※受け取り側の修正も必要
		echo esc_html( wp_create_nonce( self::NONCE_API ) );
		die();
	}

	/**
	 * Generate AI report via Ajax
	 */
	public function ajax_generate_ai_report() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_API ) ) {
			http_response_code( 400 );
			die( 'nonce error' );
		}

		$button_type = isset( $_POST['button_type'] ) ? sanitize_text_field( wp_unslash( $_POST['button_type'] ) ) : '';
		$start_date  = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
		$end_date    = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
		$tracking_id = isset( $_POST['tracking_id'] ) ? $this->get_safe_tracking_id( sanitize_text_field( wp_unslash( $_POST['tracking_id'] ) ) ) : 'all';

		$response = $this->generate_ai_report( $button_type, $start_date, $end_date, $tracking_id );
		wp_send_json( $response );
		die();
	}

	/**
	 * Generate AI report processing logic
	 */
	public function generate_ai_report( $button_type, $start_date, $end_date, $tracking_id = 'all' ) {
		if ( empty( $button_type ) || empty( $start_date ) || empty( $end_date ) ) {
			return array(
				'success' => false,
				'message' => esc_html__( 'Required parameters are missing.', 'qa-heatmap-analytics' ),
			);
		}

		if ( ! $this->validate_date_format( $start_date ) || ! $this->validate_date_format( $end_date ) ) {
			return array(
				'success' => false,
				'message' => esc_html__( 'Invalid date format.', 'qa-heatmap-analytics' ),
			);
		}

		// Generate queue data based on button type
		$queue_data = array(
			'tracking_id' => $tracking_id,
			'start_date'  => $start_date,
			'end_date'    => $end_date,
			'button_type' => $button_type,
			'created_at'  => current_time( 'mysql' ),
		);

		$result = false;

		switch ( $button_type ) {
			case 'seo':
				$result = $this->enqueue_seo_report( $queue_data );
				break;
			case 'ads':
				$result = $this->enqueue_ads_report( $queue_data );
				break;
			case 'popular':
				$result = $this->enqueue_popular_report( $queue_data );
				break;
			case 'cv':
				$result = $this->enqueue_cv_report( $queue_data );
				break;
			case 'repeat':
				$result = $this->enqueue_repeat_report( $queue_data );
				break;
			case 'free_extraction':
				$result = $this->enqueue_free_extraction_report( $queue_data );
				break;
			default:
				return array(
					'success' => false,
					'message' => esc_html__( 'Invalid report type.', 'qa-heatmap-analytics' ),
				);
		}

		if ( $result ) {
			return array(
				'success'  => true,
				'message'  => esc_html__( 'Report generation has been queued successfully.', 'qa-heatmap-analytics' ),
				'queue_id' => $result,
			);
		} else {
			return array(
				'success' => false,
				'message' => esc_html__( 'Failed to queue report generation.', 'qa-heatmap-analytics' ),
			);
		}
	}

	/**
	 * Enqueue SEO report generation
	 */
	private function enqueue_seo_report( $queue_data ) {
		global $qahm_report_queue;
		if ( ! $qahm_report_queue ) {
			return false;
		}

		$queue_id  = 'seo_report_' . time() . '_' . wp_rand( 1000, 9999 );
		$csv_files = array( 'seo.php', 'seo2.php' );

		$queue_data['id']        = $queue_id;
		$queue_data['csv_files'] = $csv_files;
		$queue_data['type']      = 'seo_report';

		return $qahm_report_queue->enqueue_report( $queue_data );
	}

	/**
	 * Enqueue ads report generation
	 */
	private function enqueue_ads_report( $queue_data ) {
		global $qahm_report_queue;
		if ( ! $qahm_report_queue ) {
			return false;
		}

		$queue_id  = 'ads_report_' . time() . '_' . wp_rand( 1000, 9999 );
		$csv_files = array( 'ads.php' );

		$queue_data['id']        = $queue_id;
		$queue_data['csv_files'] = $csv_files;
		$queue_data['type']      = 'ads_report';

		return $qahm_report_queue->enqueue_report( $queue_data );
	}

	/**
	 * Enqueue popular pages report generation
	 */
	private function enqueue_popular_report( $queue_data ) {
		global $qahm_report_queue;
		if ( ! $qahm_report_queue ) {
			return false;
		}

		$queue_id  = 'popular_report_' . time() . '_' . wp_rand( 1000, 9999 );
		$csv_files = array( 'popular.php' );

		$queue_data['id']        = $queue_id;
		$queue_data['csv_files'] = $csv_files;
		$queue_data['type']      = 'popular_report';

		return $qahm_report_queue->enqueue_report( $queue_data );
	}

	/**
	 * Enqueue CV pages report generation
	 */
	private function enqueue_cv_report( $queue_data ) {
		global $qahm_report_queue;
		if ( ! $qahm_report_queue ) {
			return false;
		}

		$queue_id  = 'cv_report_' . time() . '_' . wp_rand( 1000, 9999 );
		$csv_files = array( 'cv.php' );

		$queue_data['id']        = $queue_id;
		$queue_data['csv_files'] = $csv_files;
		$queue_data['type']      = 'cv_report';

		return $qahm_report_queue->enqueue_report( $queue_data );
	}

	/**
	 * Enqueue repeat visitors report generation
	 */
	private function enqueue_repeat_report( $queue_data ) {
		global $qahm_report_queue;
		if ( ! $qahm_report_queue ) {
			return false;
		}

		$queue_id  = 'repeat_report_' . time() . '_' . wp_rand( 1000, 9999 );
		$csv_files = array( 'repeat.php' );

		$queue_data['id']        = $queue_id;
		$queue_data['csv_files'] = $csv_files;
		$queue_data['type']      = 'repeat_report';

		return $qahm_report_queue->enqueue_report( $queue_data );
	}

	/**
	 * Enqueue free extraction report generation
	 */
	private function enqueue_free_extraction_report( $queue_data ) {
		global $qahm_report_queue;
		if ( ! $qahm_report_queue ) {
			return false;
		}

		$queue_id  = 'free_extraction_report_' . time() . '_' . wp_rand( 1000, 9999 );
		$csv_files = array( 'free_extraction.php' );

		$queue_data['id']        = $queue_id;
		$queue_data['csv_files'] = $csv_files;
		$queue_data['type']      = 'free_extraction_report';

		return $qahm_report_queue->enqueue_report( $queue_data );
	}

	/**
	 * Get processing queues via Ajax
	 */
	public function ajax_get_processing_queues() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_API ) ) {
			http_response_code( 400 );
			die( 'nonce error' );
		}

		$tracking_id = isset( $_POST['tracking_id'] ) ? $this->get_safe_tracking_id( sanitize_text_field( wp_unslash( $_POST['tracking_id'] ) ) ) : 'all';

		$response = $this->get_processing_queues( $tracking_id );
		wp_send_json( $response );
		die();
	}

	/**
	 * Get processing queues processing logic
	 */
	public function get_processing_queues( $tracking_id = 'all' ) {
		$processing_queues = array();

		return array(
			'success' => true,
			'data'    => $processing_queues,
		);
	}

	/**
	 * Get completed queues via Ajax
	 */
	public function ajax_get_completed_queues() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_API ) ) {
			http_response_code( 400 );
			die( 'nonce error' );
		}

		$tracking_id = isset( $_POST['tracking_id'] ) ? $this->get_safe_tracking_id( sanitize_text_field( wp_unslash( $_POST['tracking_id'] ) ) ) : 'all';

		$response = $this->get_completed_queues( $tracking_id );
		wp_send_json( $response );
		die();
	}

	/**
	 * Get completed queues processing logic
	 */
	public function get_completed_queues( $tracking_id = 'all' ) {
		$completed_queues = array();

		$report_queue  = new QAHM_Report_Queue();
		$data_dir      = $this->get_data_dir_path();
		$completed_dir = $data_dir . 'report/' . sanitize_text_field( $tracking_id ) . '/queue/completed/';

		if ( $this->wrap_exists( $completed_dir ) ) {
			$files = $this->wrap_dirlist( $completed_dir );
			if ( $files ) {
				foreach ( $files as $file ) {
					$queue_id   = str_replace( '.php', '', $file['name'] );
					$queue_data = $report_queue->get_queue_data( $queue_id, $tracking_id );

					if ( $queue_data ) {
						$completed_queues[] = array(
							'id'          => $queue_data['id'],
							'title'       => isset( $queue_data['display_text'] ) ? $queue_data['display_text'] : $queue_data['id'],
							'type'        => isset( $queue_data['type'] ) ? $queue_data['type'] : 'report',
							'created_at'  => isset( $queue_data['created_at'] ) ? $queue_data['created_at'] : '',
							'period'      => isset( $queue_data['period'] ) ? $queue_data['period'] : '',
							'tracking_id' => isset( $queue_data['tracking_id'] ) ? $queue_data['tracking_id'] : $tracking_id,
						);
					}
				}
			}
		}

		return array(
			'success' => true,
			'data'    => $completed_queues,
		);
	}

	/**
	 * Cancel queue via Ajax
	 */
	public function ajax_cancel_queue() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_API ) ) {
			http_response_code( 400 );
			die( 'nonce error' );
		}

		$queue_id = isset( $_POST['queue_id'] ) ? sanitize_text_field( wp_unslash( $_POST['queue_id'] ) ) : '';

		$response = $this->cancel_queue( $queue_id );
		wp_send_json( $response );
		die();
	}

	/**
	 * Cancel queue processing logic
	 */
	public function cancel_queue( $queue_id ) {
		if ( empty( $queue_id ) ) {
			return array(
				'success' => false,
				'message' => esc_html__( 'Queue ID is required.', 'qa-heatmap-analytics' ),
			);
		}

		return array(
			'success' => true,
			'message' => esc_html__( 'Queue cancelled successfully.', 'qa-heatmap-analytics' ),
		);
	}

	/**
	 * Delete reports via Ajax
	 */
	public function ajax_delete_reports() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_API ) ) {
			http_response_code( 400 );
			die( 'nonce error' );
		}

		$queue_ids = isset( $_POST['queue_ids'] ) && is_array( $_POST['queue_ids'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['queue_ids'] ) )
			: array();

		$response = $this->delete_reports( $queue_ids );
		wp_send_json( $response );
		die();
	}

	/**
	 * Delete reports processing logic
	 */
	public function delete_reports( $queue_ids ) {
		if ( empty( $queue_ids ) || ! is_array( $queue_ids ) ) {
			return array(
				'success' => false,
				'message' => esc_html__( 'Queue IDs are required.', 'qa-heatmap-analytics' ),
			);
		}

		$queue_ids = $this->wrap_array_map( 'sanitize_text_field', $queue_ids );

		return array(
			'success' => true,
			'message' => esc_html__( 'Reports deleted successfully.', 'qa-heatmap-analytics' ),
		);
	}

	/**
	 * Validate date format (YYYY-MM-DD)
	 */
	private function validate_date_format( $date ) {
		$d = DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}
}
