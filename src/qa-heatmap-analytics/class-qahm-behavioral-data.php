<?php
defined( 'ABSPATH' ) || exit;
/**
 * rawデータ関連のajax通信処理をまとめたクラス
 *
 * @package qa_heatmap
 */

new QAHM_Behavioral_Data();

class QAHM_Behavioral_Data extends QAHM_File_Data {

	const NONCE_INIT       = 'init';
	const NONCE_BEHAVIORAL = 'behavioral';

	/**
	 * コンストラクタ
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'init_wp_filesystem' ) );
	}

	/**
	 * セッション内容からクローラーかどうか判定するとともに、ファイルに記憶する
	 */

	public function crawler_checker( $qa_id, $ip_address, $ua, $session_body, $tracking_id ) {

		$crawler_check_result = array(
			'crawler' => false,
			'point'   => 0,
		);
		$pv_num               = $this->wrap_count( $session_body );

		// 20PV以下の場合、即座に false を返す
		if ( $pv_num <= 20 ) {
			$crawler_check_result['crawler'] = false;
			return $crawler_check_result;
		}

		$crawler_dir        = $this->get_data_dir_path( 'crawler/' );
		$crawler_record_dir = $this->get_data_dir_path( 'crawler/' . $tracking_id );

		global $wp_filesystem;
		if ( ! $wp_filesystem->exists( $crawler_record_dir ) ) {
			$wp_filesystem->mkdir( $crawler_record_dir );
		}
		$crawler_qaid_path = $crawler_record_dir . $qa_id . '.php';

		// $qa_idのファイルが存在するか確認
		if ( file_exists( $crawler_qaid_path ) ) {
			$crawler_check_result['crawler'] = true;
			return $crawler_check_result;
		}

		// ページビュー数に基づいてポイントを付加
		if ( $pv_num >= 120 ) {
			$crawler_check_result['point'] += 3;
		} elseif ( $pv_num >= 50 ) {
			$crawler_check_result['point'] += 2;
		} elseif ( $pv_num >= 30 ) {
			$crawler_check_result['point'] += 1;
		}

		// アクセス秒数平均が3秒以下ならポイントを追加
		$first_body = $session_body[0];
		$last_body  = end( $session_body );

		if ( ( $last_body['access_time'] - $first_body['access_time'] ) / ( $pv_num - 1 ) < 3 ) {
			$crawler_check_result['point'] += 2;
		}

		// ユーザーエージェントのOSが入っていないならポイント追加
		// このルーチンはスピードを重視したため主要OSのみ判定している
		// それ以外はクローラーとしてのポイントになってしまうが、それ以外はやむなしとする

		$isOSDetected = false;
		if ( $this->wrap_strpos( $ua, 'Windows' ) !== false ) {
			$isOSDetected = true;
		} elseif ( $this->wrap_strpos( $ua, 'iPhone' ) !== false || $this->wrap_strpos( $ua, 'iPad' ) !== false ) {
			$isOSDetected = true;
		} elseif ( $this->wrap_strpos( $ua, 'Android' ) !== false ) {
			$isOSDetected = true;
		} elseif ( $this->wrap_strpos( $ua, 'Mac OS X' ) !== false ) {
			$isOSDetected = true;
		}

		if ( ! $isOSDetected ) {
			$crawler_check_result['point'] += 1;
		}

		if ( $crawler_check_result['point'] >= 4 ) {
			//ファイルに記録
			$data = $ip_address . "\t" . $ua;

			$crawler_check_result['crawler'] = true;
			// ファイル書き込み
			$this->wrap_put_contents( $crawler_qaid_path, $data );
		}

		return $crawler_check_result; //クローラー判定
	}

	/**
	 * 初期化
	 */

	public function init_session_data( $qa_id, $title, $url, $c_url, $url_hash, $ref, $country, $ua, $tracking_id, $is_new_user, $is_cookie_reject, $ip_address ) {
		//QA ZERO add
		//public function init_session_data( $qa_id, $wp_qa_type, $wp_qa_id, $title, $url, $ref, $country, $ua ) { QA ZERO del

			global $qahm_time;

			$geo_data = null;
		if ( $ip_address && $ip_address !== '127.0.0.1' && $ip_address !== '::1' ) {
			$geo_data = QAHM_IP_Geolocation::get_country_from_ip( $ip_address );
		}

			$dev_name         = $this->user_agent_to_device_name( $ua );
			$utm_source       = '';
			$utm_medium       = '';
			$utm_campaign     = '';
			$utm_content      = '';
			$utm_term         = '';
			$user_original_id = '';

			// utm_***の設定＆urlの一部パラメーターを削除して保存できるよう対応
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url() is unavailable before WordPress is fully loaded; safe fallback to parse_url().
			$parse_url = parse_url( $url, PHP_URL_QUERY );
		if ( $parse_url ) {
			parse_str( $parse_url, $query_ary );

			if ( $this->wrap_array_key_exists( 'utm_source', $query_ary ) ) {
				$utm_source = $this->encode_utm_parameter( $query_ary['utm_source'] );
			}
			if ( $this->wrap_array_key_exists( 'utm_medium', $query_ary ) ) {
				$utm_medium = $this->encode_utm_parameter( $query_ary['utm_medium'] );
			}
			if ( $this->wrap_array_key_exists( 'utm_campaign', $query_ary ) ) {
				$utm_campaign = $this->encode_utm_parameter( $query_ary['utm_campaign'] );
			}
			if ( $this->wrap_array_key_exists( 'utm_term', $query_ary ) ) {
				$utm_term = $this->encode_utm_parameter( $query_ary['utm_term'] );
			}
			if ( $this->wrap_array_key_exists( 'utm_content', $query_ary ) ) {
				$utm_content = $this->encode_utm_parameter( $query_ary['utm_content'] );
			}

			//QA ZERO add start
			if ( $this->wrap_array_key_exists( 'gad', $query_ary ) ) {
				if ( ! $utm_source ) {
					$utm_source = 'google';
				}
				if ( ! $utm_medium ) {
					$utm_medium = 'cpc';
				}
			}
			//QA ZERO add end

			if ( $this->wrap_array_key_exists( 'gclid', $query_ary ) ) {
				if ( ! $utm_source ) {
					$utm_source = 'google';
				}
				if ( ! $utm_medium ) {
					$utm_medium = 'cpc';
				}
			}

			if ( $this->wrap_array_key_exists( 'fbclid', $query_ary ) ) {
				if ( ! $utm_medium ) {
					$utm_medium = 'social';
				}
				if ( ! $utm_source ) {
					$utm_source = 'facebook';
				}
			}

			if ( $this->wrap_array_key_exists( 'twclid', $query_ary ) ) {
				if ( ! $utm_medium ) {
					$utm_medium = 'social';
				}
				if ( ! $utm_source ) {
					$utm_source = 'twitter';
				}
			}

			if ( $this->wrap_array_key_exists( 'yclid', $query_ary ) ) {
				if ( ! $utm_medium ) {
					$utm_medium = 'cpc';
				}
				if ( ! $utm_source ) {
					$utm_source = 'yahoo';
				}
			}

			if ( $this->wrap_array_key_exists( 'ldtag_cl', $query_ary ) ) {
				if ( ! $utm_medium ) {
					$utm_medium = 'cpc';
				}
				if ( ! $utm_source ) {
					$utm_source = 'line';
				}
			}

			if ( $this->wrap_array_key_exists( 'msclkid', $query_ary ) ) {
				if ( ! $utm_medium ) {
					$utm_medium = 'cpc';
				}
				if ( ! $utm_source ) {
					$utm_source = 'microsoft';
				}
			}

			if ( $this->wrap_array_key_exists( 'gad_source', $query_ary ) ) {
				if ( ! $utm_source ) {
					$utm_source = 'google';
				}
				if ( ! $utm_medium ) {
					$utm_medium = 'cpc';
				}
			}

			if ( $this->wrap_array_key_exists( 'sa_p', $query_ary ) ) {
				if ( ! $utm_medium ) {
					$utm_medium = 'cpc';
				}
				if ( ! $utm_source ) {
					$utm_source = 'yahoo';
				}
			}

			if ( $this->wrap_array_key_exists( 'sa_cc', $query_ary ) ) {
				if ( ! $utm_medium ) {
					$utm_medium = 'cpc';
				}
				if ( ! $utm_source ) {
					$utm_source = 'yahoo';
				}
			}

			if ( $this->wrap_array_key_exists( 'sa_t', $query_ary ) ) {
				if ( ! $utm_medium ) {
					$utm_medium = 'cpc';
				}
				if ( ! $utm_source ) {
					$utm_source = 'yahoo';
				}
			}

			if ( $this->wrap_array_key_exists( 'sa_ra', $query_ary ) ) {
				if ( ! $utm_medium ) {
					$utm_medium = 'cpc';
				}
				if ( ! $utm_source ) {
					$utm_source = 'yahoo';
				}
			}
		}

			//$url                = $this->opt_url_param( $url ); QA ZERO del
			$readers_temp_dir   = $this->get_data_dir_path( 'readers/temp/' );
			$readers_finish_dir = $this->get_data_dir_path( 'readers/finish/' );

			// sessionデータ作成
			$today_str        = $qahm_time->today_str();
			$session_temp_ary = null;
			//$is_new_user        = 0; QA ZERO del

		if ( $qa_id ) {

			//QA ZERO ADD START
			$session_num  = 1;
			$readers_name = $qa_id . '_' . $today_str . '_' . $session_num;
			//QA ZERO ADD END

			// 保存対象のセッションファイルを調べる。まずはtempディレクトリ
			$file_info = $this->get_latest_readers_file_info( $readers_temp_dir, $qa_id );

			if ( $file_info ) {
				$before_30min = $qahm_time->now_unixtime() - ( 60 * 30 );
				if ( $file_info['lastmodunix'] < $before_30min ) {
					// 作られてから30分以上経過している場合はcronが止まっている可能性があるので、session_no+1で新規ファイル作成
					if ( $file_info['day_str'] === $today_str ) {
						$session_num = $file_info['session_num'] + 1;
					} else {
						$session_num = 1;
					}
					$readers_name = $qa_id . '_' . $today_str . '_' . $session_num;

				} else {
					// 作られてから30分経過していない場合は前のファイルに追記書き込み
					$session_num      = $file_info['session_num'];
					$readers_name     = $qa_id . '_' . $file_info['day_str'] . '_' . $session_num;
					$session_temp_ary = $this->wrap_unserialize( $this->wrap_get_contents( $readers_temp_dir . $readers_name . '.php' ) );
				}
			}

			/*-- QA ZERO DEL START
			} else {
				// tempディレクトリにファイルがない場合はfinishディレクトリを確認
				$file_info = $this->get_latest_readers_file_info( $readers_finish_dir, $qa_id );

				if ( $file_info ) {
					if ( $file_info['day_str'] === $today_str ) {
						$session_num  = $file_info['session_num'] + 1;
					} else {
						$session_num = 1;
					}

				} else {
					$session_num  = 1;
				}

				$readers_name = $qa_id . '_' . $today_str . '_' . $session_num;
			}
			QA ZERO DEL END --*/

		} else {

			/*--
			$qa_id        = $qahm_time->now_str( 'ymdHis' ) . hash( 'fnv164', mt_rand() );
			$session_num  = 1;
			$readers_name = $qa_id . '_' . $today_str . '_' . $session_num;
			setcookie( 'qa_id', $qa_id, time() + 60 * 60 * 24 * 365 * 2, '/' ); QA ZERO del
			--*/

			// qa_idも_gaもcookieに存在していなければ新規ユーザー
			if ( ! $this->wrap_filter_input( INPUT_COOKIE, '_ga' ) ) {
				$is_new_user = 1;
			}
		}

			// session temp data
		if ( ! $session_temp_ary ) {
			$session_temp_ary                    = array();
			$session_temp_ary['head']['version'] = 1;
			// $session_temp_ary['head']['tracking_id']    = $this->get_tracking_id(); QA ZERO del
			$session_temp_ary['head']['tracking_id']    = $tracking_id; //QA ZERO add
			$session_temp_ary['head']['device_name']    = $dev_name;
			$session_temp_ary['head']['is_new_user']    = $is_new_user;
			$session_temp_ary['head']['user_agent']     = $ua;
			$session_temp_ary['head']['first_referrer'] = $ref;
			$session_temp_ary['head']['utm_source']     = $utm_source;
			$session_temp_ary['head']['utm_medium']     = $utm_medium;
			$session_temp_ary['head']['utm_campaign']   = $utm_campaign;
			$session_temp_ary['head']['utm_term']       = $utm_term;
			$session_temp_ary['head']['utm_content']    = $utm_content; //QA ZERO add
			$session_temp_ary['head']['original_id']    = $user_original_id;
			$session_temp_ary['head']['country']        = $country;
			$session_temp_ary['head']['country_code']   = $geo_data ? $geo_data['country_code'] : null;
			$session_temp_ary['head']['is_reject']      = $is_cookie_reject;
		}

			$access_time = $qahm_time->now_unixtime();//QA ZERO add

			$body = array(
				//'page_url'    => $url, QA ZERO del
				'page_url'    => $c_url, //QA ZERO add
				'page_title'  => $title,
				//'page_type'   => $wp_qa_type, QA ZERO del
				//'page_id'     => $wp_qa_id, QA ZERO del
				'page_type'   => '', //QA ZERO add
				'page_id'     => 0,
				//access_time' => $qahm_time->now_unixtime(), QA ZERO del
				'access_time' => $access_time, //QA ZERO add
				'page_speed'  => 0,
			);

			if ( ! isset( $session_temp_ary['body'] ) ) {
				$session_temp_ary['body'] = array();
			}

			$data['readers_name']       = $readers_name;
			$data['readers_body_index'] = array_push( $session_temp_ary['body'], $body ) - 1;

			//クローラー判定
			$crawler_chk_result = self::crawler_checker( $qa_id, $ip_address, $ua, $session_temp_ary['body'], $tracking_id );

			if ( $crawler_chk_result['crawler'] ) {
				return false;
			}

			$this->wrap_put_contents( $readers_temp_dir . $readers_name . '.php', $this->wrap_serialize( $session_temp_ary ) );

			// qahm測定対象外のページの場合は生データを作らない
			/*
			if ( $this->is_qahm_page( $wp_qa_type ) ) {

				// 読者のPV数を取得。検索上限PV数は10000（仮）
				$raw_dir = $this->get_raw_dir_path( $wp_qa_type, $wp_qa_id, $dev_name );
				$limit  = 10000;
				$pv_num = 1;
				for( $i = 1; $i < $limit; $i++ ) {
					if ( ! $this->wrap_exists( $raw_dir . $readers_name . '_' . $i . '-p.php' ) ) {
						$pv_num  = $i;
						break;
					}
				}

				$data['raw_name'] = $readers_name . '_' . $pv_num;
			} QA ZERO del */

			/*
			// 読者のPV数を取得。検索上限PV数は10000（仮）
			$raw_dir = $this->get_raw_dir_path( $tracking_id, $url_hash );
			$limit  = 10000;
			$pv_num = 1;
			for( $i = 1; $i < $limit; $i++ ) {
				if ( ! $this->wrap_exists( $raw_dir . $readers_name . '_' . $i . '-p.php' ) ) {
					$pv_num  = $i;
					break;
				}
			}
			QA ZERO del */

			//$data['raw_name'] = $readers_name . '_' . $pv_num; QA ZERO del
			$data['raw_name'] = $qa_id . '_' . $access_time;

			$data['qa_id'] = $qa_id;

			return $data;
	}

	public function update_msec( $readers_name, $readers_body_index, $speed_msec ) {
			$readers_temp_dir = $this->get_data_dir_path( 'readers/temp/' );
			$readers_data_ary = $this->wrap_unserialize( $this->wrap_get_contents( $readers_temp_dir . $readers_name . '.php' ) );
		if ( isset( $readers_data_ary['body'][ $readers_body_index ]['page_speed'] ) ) {
			$readers_data_ary['body'][ $readers_body_index ]['page_speed'] = $speed_msec;
			$this->wrap_put_contents( $readers_temp_dir . $readers_name . '.php', $this->wrap_serialize( $readers_data_ary ) );
		}
	}

	// 指定したQA IDを持つひとにとって、最新のreadersファイルを取得
	private function get_latest_readers_file_info( $tar_dir, $qa_id ) {
		global $qahm_time;

		// 一番新しいreadersファイルの情報を格納する配列
		$file_info = array();

		if ( QAHM_CONFIG_USE_LSCMD_LISTFILE ) {
			$file_list = $this->listfiles_ls( $tar_dir, $qa_id . '_*.php' );
		} else {
			$file_list = $this->wrap_dirlist( $tar_dir );
		}

		if ( $file_list ) {
			foreach ( $file_list as $file ) {
				if ( $this->wrap_strpos( $file['name'], $qa_id . '_' ) === false ) {
					continue;
				}

				if ( ! preg_match( '/' . $qa_id . '_(.*)_(.*)\.php/', $file['name'], $match ) ) {
					continue;
				}

				$day_str     = $match[1];
				$session_num = (int) $match[2];

				if ( ! empty( $file_info ) ) {
					$xday_num = $qahm_time->xday_num( $file_info['day_str'], $day_str );

					if ( $xday_num === 0 ) {
						if ( $file_info['session_num'] >= $session_num ) {
							continue;
						}
					}
				}

				$file_info['name']        = $file['name'];
				$file_info['lastmodunix'] = $file['lastmodunix'];
				$file_info['session_num'] = $session_num;
				$file_info['day_str']     = $day_str;
			}
		}

		return empty( $file_info ) ? null : $file_info;
	}

	public function record_behavioral_data( $is_pos, $is_click, $is_event, $is_dLevent, $raw_name, $readers_name, $ua, $tracking_id, $url_hash, $is_cookie_reject ) {

		try {
			global $qahm_time;

			$dev_name = $this->user_agent_to_device_name( $ua );
			$raw_dir  = $this->get_raw_dir_path( $tracking_id, $url_hash ); //QA ZERO add

			$readers_temp_path = $this->get_data_dir_path( 'readers/temp/' ) . $readers_name . '.php';

			if ( $this->wrap_exists( $readers_temp_path ) ) {

				$lastmodunix  = filemtime( $readers_temp_path );
				$readers_data = $this->wrap_get_contents( $readers_temp_path );

				$readers_data_ary                      = $this->wrap_unserialize( $readers_data );
				$is_reject_temp                        = $readers_data_ary['head']['is_reject'];
				$readers_data_ary['head']['is_reject'] = $is_cookie_reject;

				if ( $qahm_time->now_unixtime() - $lastmodunix > 30 || $is_reject_temp != $is_cookie_reject ) {
					$this->wrap_put_contents( $readers_temp_path, $this->wrap_serialize( $readers_data_ary ) );
				}
			}

			// validate
			if ( ! $raw_dir ) {
				throw new Exception( 'Failed to specify the directory for raw data.' );
			}

			$output = 'output data /';
			if ( $is_pos ) {
				$pos_ver       = $this->wrap_filter_input( INPUT_POST, 'pos_ver' );
				$is_scroll_max = $this->wrap_filter_input( INPUT_POST, 'is_scroll_max' );
				if ( ! $pos_ver ) {
					$pos_ver = 1;
				} else {
					$pos_ver = (int) $pos_ver;
				}
				$pos_ary  = array(
					array(
						self::DATA_HEADER_VERSION => $pos_ver,
					),
				);
				$pos_path = $raw_dir . $raw_name . '-p' . '.php';

				// validate & optimize
				if ( $pos_ver === 2 ) {
					$stay_height_ary = json_decode( $this->wrap_filter_input( INPUT_POST, 'stay_height' ), true );

					foreach ( $stay_height_ary as $stay_height => $stay_time ) {
						if ( ! $stay_time ) {
							continue;
						}

						if ( ! $this->validate_number( $stay_time ) ) {
							throw new Exception( 'The value of $stay_height is invalid.' );
						}

						array_push(
							$pos_ary,
							array(
								self::DATA_POS_2['STAY_HEIGHT'] => $stay_height,
								self::DATA_POS_2['STAY_TIME']   => $stay_time,
							)
						);
					}

					if ( $is_scroll_max === 'true' ) {
						array_push(
							$pos_ary,
							array(
								self::DATA_POS_2['STAY_HEIGHT'] => 'a',
							)
						);
					}
				} elseif ( $pos_ver === 1 ) {
					$percent_height = json_decode( $this->wrap_filter_input( INPUT_POST, 'percent_height' ), true );
					for ( $i = 0; $i < 100; $i++ ) {
						if ( ! $this->validate_number( $percent_height[ $i ] ) ) {
							throw new Exception( 'The value of $percent_height[$i] is invalid.' );
						}

						if ( $percent_height[ $i ] > 0 ) {
							array_push(
								$pos_ary,
								array(
									self::DATA_POS_1['PERCENT_HEIGHT'] => $i,
									self::DATA_POS_1['TIME_ON_HEIGHT'] => $percent_height[ $i ],
								)
							);
						}
					}

					if ( $is_scroll_max === 'true' ) {
						array_push(
							$pos_ary,
							array(
								self::DATA_POS_1['PERCENT_HEIGHT'] => 'a',
							)
						);
					}
				}

				$pos_tsv = $this->convert_array_to_tsv( $pos_ary );
				$this->wrap_put_contents( $pos_path, $pos_tsv );
				$output .= ' p /';
			}

			if ( $is_click ) {
				$click_ary = json_decode( $this->wrap_filter_input( INPUT_POST, 'click_ary' ), true );
				$click_ver = $this->wrap_filter_input( INPUT_POST, 'click_ver' );
				if ( ! $click_ver ) {
					$click_ver = 1;
				} else {
					$click_ver = (int) $click_ver;
				}
				$click_path = $raw_dir . $raw_name . '-c' . '.php';

				// validate & optimize
				$validated_click_ary = array();

				for ( $i = 0, $click_ary_cnt = $this->wrap_count( $click_ary ); $i < $click_ary_cnt; $i++ ) {
					if ( ! $this->validate_number( $click_ary[ $i ][ self::DATA_CLICK_1['SELECTOR_X'] ] ) ) {
						continue;
						//throw new Exception( 'The value of $click_ary is invalid.' );
					}

					if ( ! $this->validate_number( $click_ary[ $i ][ self::DATA_CLICK_1['SELECTOR_Y'] ] ) ) {
						continue;
						//throw new Exception( 'The value of $click_ary is invalid.' );
					}

					if ( $this->wrap_array_key_exists( self::DATA_CLICK_1['TRANSITION'], $click_ary[ $i ] ) ) {
						$click_ary[ $i ][ self::DATA_CLICK_1['TRANSITION'] ] = mb_strtolower( $click_ary[ $i ][ self::DATA_CLICK_1['TRANSITION'] ] );
					}

					if ( $click_ver >= 2 && $this->wrap_count( $click_ary[ $i ] ) > $this->wrap_count( self::DATA_CLICK_1 ) ) {
						if ( $this->wrap_array_key_exists( self::DATA_CLICK_2['EVENT_SEC'], $click_ary[ $i ] ) ) {
							if ( ! $this->validate_number( $click_ary[ $i ][ self::DATA_CLICK_2['EVENT_SEC'] ] ) ) {
								$click_ary[ $i ][ self::DATA_CLICK_2['EVENT_SEC'] ] = 0;
							}
						}

						if ( $this->wrap_array_key_exists( self::DATA_CLICK_2['ACTION_ID'], $click_ary[ $i ] ) ) {
							$action_id = (int) $click_ary[ $i ][ self::DATA_CLICK_2['ACTION_ID'] ];
							if ( $action_id < 1 || $action_id > 4 ) {
								$click_ary[ $i ][ self::DATA_CLICK_2['ACTION_ID'] ] = 1; // デフォルトはclick
							}
						}

						if ( $this->wrap_array_key_exists( self::DATA_CLICK_2['PAGE_X_PCT'], $click_ary[ $i ] ) ) {
							$page_x_pct = (int) $click_ary[ $i ][ self::DATA_CLICK_2['PAGE_X_PCT'] ];
							if ( $page_x_pct < 0 || $page_x_pct > 100 ) {
								$click_ary[ $i ][ self::DATA_CLICK_2['PAGE_X_PCT'] ] = 0;
							}
						}

						if ( $this->wrap_array_key_exists( self::DATA_CLICK_2['PAGE_Y_PCT'], $click_ary[ $i ] ) ) {
							$page_y_pct = (int) $click_ary[ $i ][ self::DATA_CLICK_2['PAGE_Y_PCT'] ];
							if ( $page_y_pct < 0 || $page_y_pct > 100 ) {
								$click_ary[ $i ][ self::DATA_CLICK_2['PAGE_Y_PCT'] ] = 0;
							}
						}

						if ( $this->wrap_array_key_exists( self::DATA_CLICK_2['ELEMENT_TEXT'], $click_ary[ $i ] ) ) {
							$click_ary[ $i ][ self::DATA_CLICK_2['ELEMENT_TEXT'] ] = sanitize_text_field( $click_ary[ $i ][ self::DATA_CLICK_2['ELEMENT_TEXT'] ] );
						}

						if ( $this->wrap_array_key_exists( self::DATA_CLICK_2['ELEMENT_ID'], $click_ary[ $i ] ) ) {
							$click_ary[ $i ][ self::DATA_CLICK_2['ELEMENT_ID'] ] = sanitize_text_field( $click_ary[ $i ][ self::DATA_CLICK_2['ELEMENT_ID'] ] );
						}

						if ( $this->wrap_array_key_exists( self::DATA_CLICK_2['ELEMENT_CLASS'], $click_ary[ $i ] ) ) {
							$click_ary[ $i ][ self::DATA_CLICK_2['ELEMENT_CLASS'] ] = sanitize_text_field( $click_ary[ $i ][ self::DATA_CLICK_2['ELEMENT_CLASS'] ] );
						}

						if ( $this->wrap_array_key_exists( self::DATA_CLICK_2['ELEMENT_DATA_ATTR'], $click_ary[ $i ] ) ) {
							$click_ary[ $i ][ self::DATA_CLICK_2['ELEMENT_DATA_ATTR'] ] = sanitize_text_field( $click_ary[ $i ][ self::DATA_CLICK_2['ELEMENT_DATA_ATTR'] ] );
						}
					}

					array_push( $validated_click_ary, $click_ary[ $i ] );
				}

				$click_head          = array(
					array(
						self::DATA_HEADER_VERSION => $click_ver,
					),
				);
				$validated_click_ary = $this->wrap_array_merge( $click_head, $validated_click_ary );
				$click_tsv           = $this->convert_array_to_tsv( $validated_click_ary );
				$this->wrap_put_contents( $click_path, $click_tsv );
				$output .= ' c /';
			}

			if ( $is_event ) {
				$event_ary     = json_decode( $this->wrap_filter_input( INPUT_POST, 'event_ary' ), true );
				$init_window_w = $this->wrap_filter_input( INPUT_POST, 'init_window_w' );
				$init_window_h = $this->wrap_filter_input( INPUT_POST, 'init_window_h' );
				$event_ver     = $this->wrap_filter_input( INPUT_POST, 'event_ver' );
				if ( ! $event_ver ) {
					$event_ver = 1;
				} else {
					$event_ver = (int) $event_ver;
				}
				$event_path = $raw_dir . $raw_name . '-e' . '.php';

				// validate
				if ( ! $this->validate_number( $init_window_w ) ) {
					throw new Exception( 'The value of $init_window_w is invalid.' );
				}

				if ( ! $this->validate_number( $init_window_h ) ) {
					throw new Exception( 'The value of $init_window_h is invalid.' );
				}

				for ( $i = 0, $event_ary_cnt = $this->wrap_count( $event_ary ); $i < $event_ary_cnt; $i++ ) {
					$event_type = $event_ary[ $i ][ self::DATA_EVENT_1['TYPE'] ];
					if ( $this->wrap_strlen( $event_type ) !== 1 ) {
						throw new Exception( 'The value of $event_ary is invalid.' );
					}

					if ( ! $this->validate_number( $event_ary[ $i ][ self::DATA_EVENT_1['TIME'] ] ) ) {
						throw new Exception( 'The value of $event_ary is invalid.' );
					}

					switch ( $event_type ) {
						case 'c':
							if ( ! $this->validate_number( $event_ary[ $i ][ self::DATA_EVENT_1['CLICK_X'] ] ) ) {
								throw new Exception( 'The value of $event_ary is invalid.' );
							}

							if ( ! $this->validate_number( $event_ary[ $i ][ self::DATA_EVENT_1['CLICK_Y'] ] ) ) {
								throw new Exception( 'The value of $event_ary is invalid.' );
							}
							break;

						case 's':
							if ( ! $this->validate_number( $event_ary[ $i ][ self::DATA_EVENT_1['SCROLL_Y'] ] ) ) {
								throw new Exception( 'The value of $event_ary is invalid.' );
							}
							break;

						case 'm':
							if ( ! $this->validate_number( $event_ary[ $i ][ self::DATA_EVENT_1['MOUSE_X'] ] ) ) {
								throw new Exception( 'The value of $event_ary is invalid.' );
							}

							if ( ! $this->validate_number( $event_ary[ $i ][ self::DATA_EVENT_1['MOUSE_Y'] ] ) ) {
								throw new Exception( 'The value of $event_ary is invalid.' );
							}
							break;

						case 'r':
							if ( ! $this->validate_number( $event_ary[ $i ][ self::DATA_EVENT_1['RESIZE_X'] ] ) ) {
								throw new Exception( 'The value of $event_ary is invalid.' );
							}

							if ( ! $this->validate_number( $event_ary[ $i ][ self::DATA_EVENT_1['RESIZE_Y'] ] ) ) {
								throw new Exception( 'The value of $event_ary is invalid.' );
							}
							break;

						// videoタグのセレクタはこの時点では文字列で格納
						case 'p':
							break;

						case 'a':
							break;

						default:
							throw new Exception( 'The value of $event_ary is invalid.' );
					}
				}

				$event_head = array(
					array(
						self::DATA_HEADER_VERSION => $event_ver,
						self::DATA_EVENT_1['WINDOW_INNER_W'] => (int) $init_window_w,
						self::DATA_EVENT_1['WINDOW_INNER_H'] => (int) $init_window_h,
					),
				);
				$event_ary  = $this->wrap_array_merge( $event_head, $event_ary );
				$event_tsv  = $this->convert_array_to_tsv( $event_ary );
				$this->wrap_put_contents( $event_path, $event_tsv );
				$output .= ' e /';
			}

			//dataLayer連携
			if ( $is_dLevent ) {

				$dLevent_path = $raw_dir . $raw_name . '-g' . '.php';
				$dLevent_ary  = json_decode( $this->wrap_filter_input( INPUT_POST, 'dlevent_ary' ), true );
				$dLevent_ver  = $this->wrap_filter_input( INPUT_POST, 'dlevent_ver' );
				$dLevent_head = array(
					array(
						self::DATA_HEADER_VERSION => $dLevent_ver,
					),
				);

				$dLevent_ary = $this->wrap_array_merge( $dLevent_head, $dLevent_ary );
				$dLevent_tsv = $this->convert_array_to_tsv( $dLevent_ary );
				$this->wrap_put_contents( $dLevent_path, $dLevent_tsv );

				$output .= ' g /';

			}

			// ファイルの整合性を合わせるためにファイルの保存はここで一気にする
			return $output;

		} catch ( Exception $e ) {
			http_response_code( 500 );
			echo esc_html( $e->getMessage() );

		} finally {
			die();
		}
	}

	// 数値のチェック
	private function validate_number( $val, $type = 'numeric' ) {
		switch ( $type ) {
			case 'numeric':
				return is_numeric( $val );
			case 'int':
				return is_int( $val );
			case 'float':
				return is_float( $val );
			default:
				return false;
		}
	}

	/**
	 * UTMパラメータの文字列をUTF-8に変換
	 *
	 * @param string $utm_string UTMパラメータの文字列
	 * @return string エンコード済みのUTMパラメータ
	 */
	private function encode_utm_parameter( $utm_string ) {
		// UTF-8にエンコードされていない場合はUTF-8に変換
		if ( mb_detect_encoding( $utm_string, 'UTF-8', true ) === false ) {
			$utm_string = mb_convert_encoding( $utm_string, 'UTF-8', 'auto' );
		}

		return $utm_string;
	}
} // end of class
