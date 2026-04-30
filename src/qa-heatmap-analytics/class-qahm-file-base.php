<?php
defined( 'ABSPATH' ) || exit;
/**
 *
 *
 * @package qa_heatmap
 */

class QAHM_File_Base extends QAHM_Base {

	/**
	 * lsコマンドの結果をwrap_dirlistの戻り値と同じ形式で返します。
	 *
	 * 引数：
	 * $dir       string : 必須。対象となるディレクトリのパスを指定
	 * $wildcard  string : 省略化。lsに渡すファイル抽出条件を指定。
	 *
	 * 備考：
	 * ・ファイルパスにスペースが入る場合は無視されます。
	 * ・wrap_dirlistはアルファベット順の”自然順”で返しますが、
	 * 　当関数はls結果をそのまま（アルファベット順）で返します。
	 * 　自然順で返すことを期待して使用しないでください。
	 * ・「ls -l --time-style=full-iso」
	 * 　が標準的な列名
	 * 　パーミッション, ハードリンクの数, ファイルの所有者名,
	 * 　ファイルの所有グループ名, ファイルサイズ（バイト単位）,
	 * 　ファイルの最終更新日時（ISO 8601形式）, ファイル名、
	 * 　で返ることを想定していますので、OSや設定によっては使用不可です。
	 * 　(使用する場合は、オプションで切り替え可能とすること)
	 * ・osコマンドインジェクションの可能性がある場合は使用しないでください
	 * 　（POSTされた値をそのままチェックせず入力することは不可）
	 */
	public function listfiles_ls( $dir, $wildcard = '*' ) {

		$output = array();
		exec( 'ls -l --time-style=full-iso ' . $dir . $wildcard, $files );

		foreach ( $files as $file ) {
			#$fileInfo = preg_split('/\s+/', $file, null, PREG_SPLIT_NO_EMPTY);
			$fileInfo = $this->wrap_explode( ' ', $file );
			if ( $this->wrap_count( $fileInfo ) != 9 || $this->wrap_substr( $fileInfo[0], 0, 1 ) == 'd' ) {
				continue;
			}

			$fileName    = basename( $fileInfo[8] );
			$lastModUnix = filemtime( $fileInfo[8] );
			#$lastModUnix = strtotime($fileInfo[5] . " " . $fileInfo[6]. " " .$fileInfo[7]);
			$fileSize = intval( $fileInfo[4] );
			$output[] = array(
				'name'        => $fileName,
				'lastmodunix' => $lastModUnix,
				'size'        => $fileSize,
			);
		}
		return $output;
	}


	/**
	 * rawデータのディレクトリのパスを取得
	 */

	public function get_raw_dir_path( $tracking_id, $url_hash ) {

		// tracking_id が空なら処理しない（不正なディレクトリ作成を防止）
		if ( empty( $tracking_id ) ) {
			return false;
		}

		$dir = $this->get_data_dir_path();

		$dir .= $tracking_id . '/';
		if ( ! $this->wrap_mkdir( $dir ) ) {
			return false;
		}

		if ( $url_hash ) {
			$dir .= $url_hash . '/';
			if ( ! $this->wrap_mkdir( $dir ) ) {
				return false;
			}
		}

		return $dir;
	}

	/**
	 * セキュリティを強化するためのトラッキングハッシュ配列を取得する。なければ作成
	 */
	public function get_tracking_hash_array( $tracking_id = null, $hash_update = true ) {

		if ( ! $tracking_id ) {
			$tracking_id = $this->get_tracking_id();
		}
		//$tracking_id = $this->get_tracking_id( $url );
		$data_dir   = $this->get_data_dir_path();
		$thash_file = $data_dir . $tracking_id . '_tracking_hash.php';

		$new_thash_ary = array();
		//get now hash
		global $wp_filesystem;
		global $qahm_time;
		$now_utime = $qahm_time->now_unixtime();
		// 旧: $newhash = hash('fnv164', (string) mt_rand());
		$newhash = hash( 'fnv164', (string) random_int( 0, mt_getrandmax() ) );
		if ( $wp_filesystem->exists( $thash_file ) ) {
			$th_serial = $this->wrap_get_contents( $thash_file );
			$thash_ary = $this->wrap_unserialize( $th_serial );

			$recent_utime = $thash_ary[0]['create_utime'];
			$th_interval  = $now_utime - $recent_utime;
			if ( 3600 * 24 < $th_interval && $hash_update ) {
				$new_thash_ary[0] = array(
					'create_utime'  => $now_utime,
					'tracking_hash' => $newhash,
				);
				$new_thash_ary[1] = $thash_ary[0];
				$new_th_serial    = $this->wrap_serialize( $new_thash_ary );
				$this->wrap_put_contents( $thash_file, $new_th_serial );
			} else {
				$new_thash_ary = $thash_ary;
			}
		} else {
				$new_thash_ary[0] = array(
					'create_utime'  => $now_utime,
					'tracking_hash' => $newhash,
				);
				$new_th_serial    = $this->wrap_serialize( $new_thash_ary );
				$this->wrap_put_contents( $thash_file, $new_th_serial );
		}
		return $new_thash_ary;
	}

	/**
	 * hash値があればtrue。なければfalse
	 */
	public function check_tracking_hash( $checkhash, $tracking_id ) {

		$hash_ary = $this->get_tracking_hash_array( $tracking_id, false );
		$is_in    = false;
		foreach ( $hash_ary as $hash ) {
			if ( $checkhash === $hash['tracking_hash'] ) {
				$is_in = true;
			}
		}
		return $is_in;
	}

	/**
	 * tracking_id毎のqtag.jsを作成する
	 */
	public function create_qtag( $tracking_id, $exists_ok = true ) {

		if ( empty( $tracking_id ) ) {
			return false;
		}

		$qtag_file_name = 'qtag.js';
		$js_dir_path    = $this->get_js_dir_path();

		$qtag_subdir_path = $this->get_qtag_dir_path( $tracking_id );
		$qtag_file_path   = $qtag_subdir_path . $qtag_file_name;

		//すでに存在していれば作り直さない
		if ( ! $exists_ok && file_exists( $qtag_file_path ) ) {
			return $qtag_file_path;
		}

		$qtag_tmp_file_path = $js_dir_path . $qtag_file_name;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- WP_Filesystem may use FTP mode.
		$qtag_content = file_get_contents( $qtag_tmp_file_path );

		if ( ! $qtag_content ) {
			return false;
		}

		$tracking_hash = $this->get_tracking_hash_array( $tracking_id )[0]['tracking_hash'];
		$ajax_url      = plugin_dir_url( __FILE__ ) . 'qahm-ajax.php';

		$qtag_content = str_replace( '{tracking_hash}', $tracking_hash, $qtag_content );
		$qtag_content = str_replace( '{ajax_url}', $ajax_url, $qtag_content );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem may use FTP mode.
		if ( ! file_put_contents( $qtag_file_path, $qtag_content ) ) {
			return false;
		}

		return $qtag_file_path;
	}

	/**
	 * tracking_id毎のqtag.jsの保存先ディレクトリを取得。なければ作成。
	 */
	public function get_qtag_dir_path( $tracking_id, $mkdir = true ) {

		$data_dir_path = $this->get_data_dir_path();

		$qtag_dir_name = 'qtag_js';
		$qtag_dir_path = $data_dir_path . $qtag_dir_name;

		if ( $mkdir ) {
			$this->wrap_mkdir( $qtag_dir_path );
		}

		$qtag_subdir_path = $qtag_dir_path . '/' . $tracking_id;

		if ( $mkdir ) {
			$this->wrap_mkdir( $qtag_subdir_path );
		}

		return $qtag_subdir_path . '/';
	}

	/**
	 * tracking_id毎のqtag.jsのURL取得
	 *
	 * 指定されたtracking_idに対応するqtag.jsファイルが格納される
	 * ディレクトリのWebアクセス可能なURLを取得します。
	 *
	 * @param string $tracking_id トラッキングID
	 * @return string qtagディレクトリのURL（末尾にスラッシュ付き）
	 */
	public function get_qtag_dir_url( $tracking_id ) {
		$data_dir_url    = $this->get_data_dir_url();
		$qtag_dir_name   = 'qtag_js';
		$qtag_dir_url    = $data_dir_url . $qtag_dir_name;
		$qtag_subdir_url = $qtag_dir_url . '/' . $tracking_id;
		return $qtag_subdir_url . '/';
	}

	/**
	 * tracking_id毎のqtag.jsを削除する
	 */
	function delete_qtag( $tracking_id ) {

		if ( empty( $tracking_id ) ) {
			return false;
		}

		$qtag_file_name = 'qtag.js';
		$qtag_dir_path  = $this->get_qtag_dir_path( $tracking_id, false );
		$qtag_file_path = $qtag_dir_path . $qtag_file_name;

		if ( $this->wrap_exists( $qtag_file_path ) ) {
			$this->wrap_delete( $qtag_file_path );
		}

		global $wp_filesystem;
		return $wp_filesystem->rmdir( $qtag_dir_path );
	}


	/**
	 * ディレクトリのURL or パスから要素を求める
	 */
	protected function get_raw_dir_elem( $url ) {
		$url_exp = $this->wrap_explode( '/', $url );

		$data_num = null;
		for ( $i = 0; $i < $this->wrap_count( $url_exp ); $i++ ) {
			// dataフォルダの位置を求める
			if ( $url_exp[ $i ] === 'data' ) {
				$data_num = $i;
				break;
			}
		}
		if ( $data_num === null || ! isset( $url_exp[ $i + 4 ] ) ) {
			return null;
		}
		if ( ! $url_exp[ $i + 5 ] ) {
			return null;
		}

		$data         = array();
		$data['type'] = $url_exp[ $i + 2 ];
		$data['id']   = $url_exp[ $i + 3 ];
		$data['ver']  = $url_exp[ $i + 4 ];
		$data['dev']  = $url_exp[ $i + 5 ];
		return $data;
	}

	/**
	 * タイプとIDから元URLを取得
	 */
	protected function get_base_url( $type, $id ) {
		switch ( $type ) {
			case 'home':
				return home_url( '/' );
			case 'page_id':
			case 'p':
				return get_permalink( $id );
			case 'cat':
				return get_category_link( $id );
			case 'tag':
				return get_tag_link( $id );
			case 'tax':
				return get_term_link( $id );
			default:
				return null;
		}
	}

	/** ------------------------------
	 * 容量計算ルーチン一式
	 */

	//DB
	public function count_db() {
		//calc db
		global $qahm_db;
		global $wpdb;
		$alldbsize_ary = array();
		$alltb_ary     = $qahm_db->alltable_name();
		foreach ( $alltb_ary as $tablename ) {
			//1行だけとる
			$rowsize = 0;
			$query   = 'SELECT * from ' . $tablename . ' LIMIT 1';
			$res     = $qahm_db->get_results( $query, 'ARRAY_A' );
			$line    = $res[0];
			if ( $line !== null ) {
				foreach ( $line as $val ) {
					if ( is_string( $val ) ) {
						if ( is_numeric( $val ) ) {
							$num = (int) $val;
							if ( $num <= 255 ) {
								$rowsize += 1;
							} elseif ( $num <= 65535 ) {
								$rowsize += 2;
							} else {
								$rowsize += 4;
							}
						} else {
							$rowsize += $this->wrap_strlen( $val );
						}
					}
				}
			}
			if ( $rowsize === 0 ) {
				$rowsize = 100;
			}

			$query           = 'SELECT COUNT(*) from ' . $tablename;
			$res             = $qahm_db->get_results( $query );
			$count           = (int) $res[0]->{'COUNT(*)'};
			$byte            = $rowsize * $count;
			$alldbsize_ary[] = array(
				'tablename' => $tablename,
				'count'     => $count,
				'byte'      => $byte,
			);
		}
		$allcount = 0;
		$allbyte  = 0;
		foreach ( $alldbsize_ary as $table ) {
			$allcount += $table['count'];
			$allbyte  += $table['byte'];
		}
		$alldbsize_ary[] = array(
			'tablename' => 'all',
			'count'     => $allcount,
			'byte'      => $allbyte,
		);
		return $alldbsize_ary;
	}

	//file
	public function count_files() {
		global $qahm_time;
		global $wp_filesystem;
		$data_dir = $this->get_data_dir_path();

		// データディレクトリの再帰検索を行い、ファイル数と総容量を求める
		$search_dirs = array( $data_dir );
		$allfile_cnt = 0;
		$allfilesize = 0;
		for ( $iii = 0; $iii < $this->wrap_count( $search_dirs ); $iii++ ) {   // 再帰のためループ毎にcount関数を実行しなければならない
			$dir = $search_dirs[ $iii ];
			if ( $wp_filesystem->is_dir( $dir ) && $wp_filesystem->exists( $dir ) ) {

				// ディレクトリ内に存在するファイルのリストを取得
				$file_list = $this->wrap_dirlist( $dir );
				if ( $file_list ) {
					// ディレクトリ内のファイルを全てチェック
					foreach ( $file_list as $file ) {
						// ディレクトリなら再帰検索用の配列にディレクトリを登録
						if ( is_dir( $dir . $file['name'] ) ) {
							$search_dirs[] = $dir . $file['name'] . '/';
						} else {
							// ファイルをカウントしサイズを取得
							++$allfile_cnt;
							$allfilesize += $file['size'];
						}
					}
				}
			}
		}
		return array(
			'filecount' => $allfile_cnt,
			'size'      => $allfilesize,
		);
	}

	/**
	 * count_files のチャンク処理版（Issue #1037）
	 *
	 * 時間バジェット内で走査し、未完了なら途中結果を temp ファイルに保存して中断。
	 * 次回呼び出しで続きから再開する。
	 *
	 * @param int $time_limit_sec 1回あたりの制限時間（秒）
	 * @return array ['done' => bool, 'filecount' => int, 'size' => int]
	 */
	public function count_files_chunked( $time_limit_sec = 60 ) {
		global $wp_filesystem;
		$data_dir  = $this->get_data_dir_path();
		$memo_file = $data_dir . 'temp/storage_stats_memo.php';

		// 進捗メモの読み込み（前回の中断地点から再開）
		if ( $this->wrap_exists( $memo_file ) ) {
			$memo = $this->wrap_unserialize( $this->wrap_get_contents( $memo_file ) );
		}
		if ( empty( $memo ) || ! isset( $memo['search_dirs'] ) ) {
			$memo = array(
				'search_dirs' => array( $data_dir ),
				'dir_index'   => 0,
				'filecount'   => 0,
				'size'        => 0,
			);
		}

		$search_dirs = $memo['search_dirs'];
		$iii         = $memo['dir_index'];
		$allfile_cnt = $memo['filecount'];
		$allfilesize = $memo['size'];
		$start_time  = microtime( true );

		for ( ; $iii < $this->wrap_count( $search_dirs ); $iii++ ) {
			// 時間バジェットチェック
			if ( microtime( true ) - $start_time > $time_limit_sec ) {
				// 途中結果を保存して中断
				$memo['search_dirs'] = $search_dirs;
				$memo['dir_index']   = $iii;
				$memo['filecount']   = $allfile_cnt;
				$memo['size']        = $allfilesize;
				$this->wrap_put_contents( $memo_file, $this->wrap_serialize( $memo ) );
				return array(
					'done'      => false,
					'filecount' => $allfile_cnt,
					'size'      => $allfilesize,
				);
			}

			$dir = $search_dirs[ $iii ];
			if ( $wp_filesystem->is_dir( $dir ) && $wp_filesystem->exists( $dir ) ) {
				$file_list = $this->wrap_dirlist( $dir );
				if ( $file_list ) {
					foreach ( $file_list as $file ) {
						if ( is_dir( $dir . $file['name'] ) ) {
							$search_dirs[] = $dir . $file['name'] . '/';
						} else {
							++$allfile_cnt;
							$allfilesize += $file['size'];
						}
					}
				}
			}
		}

		// 完了 — メモファイルを削除
		if ( $this->wrap_exists( $memo_file ) ) {
			$this->wrap_delete( $memo_file );
		}
		return array(
			'done'      => true,
			'filecount' => $allfile_cnt,
			'size'      => $allfilesize,
		);
	}

	//days pv
	public function count_this_month_pv( $tracking_id = 'all' ) {
		$ret_count = 0;

		global $qahm_db;
		global $qahm_time;

		$data_dir       = $this->get_data_dir_path();
		$view_dir       = $data_dir . 'view/';
		$myview_dir     = $view_dir . $tracking_id . '/';
		$vw_summary_dir = $myview_dir . 'summary/';

		if ( $this->wrap_exists( $vw_summary_dir . 'days_access.php' ) ) {
			$daysum_ary = $this->wrap_unserialize( $qahm_db->wrap_get_contents( $vw_summary_dir . 'days_access.php' ) );
			if ( ! is_array( $daysum_ary ) ) {
				return $ret_count; // 0を返す
			}

			$month = $qahm_time->month();
			if ( (int) $month < 10 ) {
				$month = '0' . (string) $month;
			} else {
				$month = (string) $month;
			}

			$this_month_1st      = $qahm_time->year() . '-' . $month . '-01 00:00:00';
			$this_month_1st_unix = $qahm_time->str_to_unixtime( $this_month_1st );

			foreach ( $daysum_ary as $val ) {
				$nowunixtime = $qahm_time->str_to_unixtime( $val['date'] . ' 00:00:00' );
				if ( $this_month_1st_unix <= $nowunixtime ) {
					$ret_count += $val['pv_count'];
				}
			}
		}
		return $ret_count;
	}

	//days pv
	public function get_pvterm_start_date( $tracking_id = 'all' ) {

		global $qahm_db;
		global $qahm_time;
		$ret_day = $qahm_time->now_str( 'Y-m-d' );

		$data_dir       = $this->get_data_dir_path();
		$view_dir       = $data_dir . 'view/';
		$myview_dir     = $view_dir . $tracking_id . '/';
		$vw_summary_dir = $myview_dir . 'summary/';

		if ( $this->wrap_exists( $vw_summary_dir . 'days_access.php' ) ) {
			$daysum_ary = $this->wrap_unserialize( $qahm_db->wrap_get_contents( $vw_summary_dir . 'days_access.php' ) );
			if ( isset( $daysum_ary[0] ) ) {
				$ret_day = $daysum_ary[0]['date'];
			}
		}
		return $ret_day;
	}

	public function get_pvterm_latest_date( $tracking_id = 'all' ) {

		global $qahm_db;
		global $qahm_time;
		$ret_day = $qahm_time->now_str( 'Y-m-d' );

		$data_dir       = $this->get_data_dir_path();
		$view_dir       = $data_dir . 'view/';
		$myview_dir     = $view_dir . $tracking_id . '/';
		$vw_summary_dir = $myview_dir . 'summary/';

		if ( $this->wrap_exists( $vw_summary_dir . 'days_access.php' ) ) {
			$daysum_ary = $this->wrap_unserialize( $qahm_db->wrap_get_contents( $vw_summary_dir . 'days_access.php' ) );
			$last_index = $this->wrap_count( $daysum_ary ) - 1;
			if ( isset( $daysum_ary[ $last_index ] ) ) {
				$ret_day = $daysum_ary[ $last_index ]['date'];
			}
		}
		return $ret_day;
	}

	public function get_pvterm_both_end_date( $tracking_id = 'all' ) {

		global $qahm_db;
		$ret_days = array();

		$data_dir       = $this->get_data_dir_path();
		$view_dir       = $data_dir . 'view/';
		$myview_dir     = $view_dir . $tracking_id . '/';
		$vw_summary_dir = $myview_dir . 'summary/';

		if ( $this->wrap_exists( $vw_summary_dir . 'days_access.php' ) ) {
			$daysum_ary = $this->wrap_unserialize( $qahm_db->wrap_get_contents( $vw_summary_dir . 'days_access.php' ) );
			if ( is_array( $daysum_ary ) ) {
				$last_index = $this->wrap_count( $daysum_ary ) - 1;
				if ( isset( $daysum_ary[0] ) ) {
					$ret_days['start']  = $daysum_ary[0]['date'];
					$ret_days['latest'] = $daysum_ary[ $last_index ]['date'];
				}
			}
		}
		return $ret_days;
	}

	//days heatmap
	public function get_hmterm_start_date( $tracking_id = 'all' ) {
		global $qahm_time;

		$data_dir   = $this->get_data_dir_path();
		$view_dir   = $data_dir . 'view/';
		$myview_dir = $view_dir . $tracking_id . '/view_pv';
		$raw_p_dir  = $myview_dir . '/raw_p/';

		$allfiles = $this->wrap_dirlist( $raw_p_dir );
		$minunixt = $qahm_time->now_unixtime();
		if ( $allfiles ) {
			foreach ( $allfiles as $file ) {
				$filename = $file['name'];
				if ( is_file( $raw_p_dir . $filename ) ) {
					$f_date     = $this->wrap_substr( $filename, 0, 10 );
					$f_datetime = $f_date . ' 00:00:00';
				}
				$f_unixt = $qahm_time->str_to_unixtime( $f_datetime );
				if ( $f_unixt < $minunixt && $f_unixt !== 0 ) {
					$minunixt = $f_unixt;
				}
			}
		}
		$mindate = $qahm_time->unixtime_to_str( $minunixt );
		$ret_day = $this->wrap_substr( $mindate, 0, 10 );
		return $ret_day;
	}
}
