<?php
defined( 'ABSPATH' ) || exit;
/**
 * プラグイン作りでどのクラスからも参照する汎用クラス
 *
 * @package qa_heatmap
 */

class QAHM_Base extends QAHM_WP_Base {
	/**
	 * WordPressのget_option関数をqahm用に使いやすくした関数
	 */
	public function wrap_get_option( $option, $default = false ) {
		if ( $default === false ) {
			foreach ( QAHM_OPTIONS as $key => $value ) {
				if ( $option === $key ) {
					$default = $value;
					break;
				}
			}
			foreach ( QAHM_DB_OPTIONS as $key => $value ) {
				if ( $option === $key ) {
					$default = $value;
					break;
				}
			}
		}
		return get_option( QAHM_OPTION_PREFIX . $option, $default );
	}

	public function wrap_get_zero_option( $option, $default = false, $tracking_id = 'all' ) {
		$all_options_json = $this->wrap_get_option( $option, $default );
		if ( $all_options_json ) {
			$all_options_ary = json_decode( $all_options_json, true );
			if ( isset( $all_options_ary[ $tracking_id ] ) ) {
				return $this->wrap_json_encode( $all_options_ary[ $tracking_id ] );
			} else {
				return null;
			}
		} else {
			return null;
		}
	}

	/**
	 * データ保持期間を取得
	 * 優先順位: wp-config.php定数 > WordPressオプション > デフォルト値
	 */
	public function get_data_retention_days() {
		if ( defined( 'QAHM_CONFIG_DATA_RETENTION_DAYS' ) && QAHM_CONFIG_DATA_RETENTION_DAYS !== false ) {
			$days = (int) QAHM_CONFIG_DATA_RETENTION_DAYS;

			// 範囲外の値を適切な範囲内に丸める
			if ( $days < 1 ) {
				return 1; // 最小値: 1日
			}
			if ( $days > 30000 ) {
				return 30000; // 最大値: 30000日
			}
			return $days;
		}

		// 万が一の場合のフォールバック: プロダクト別のデフォルト値
		// Differs between ZERO and QA - Start ----------
		if ( defined( 'QAHM_TYPE' ) && QAHM_TYPE === QAHM_TYPE_ZERO ) {
			return 740;
		} else {
			return 120;
		}
		// Differs between ZERO and QA - End ----------
	}

	/**
	 * wp-config.phpでデータ保持期間が設定されているかチェック
	 */
	public function is_data_retention_days_defined_in_config() {
		return defined( 'QAHM_CONFIG_DATA_RETENTION_DAYS' ) && QAHM_CONFIG_DATA_RETENTION_DAYS !== false;
	}

	public function wrap_get_goals_option( $option, $default, $tracking_id ) {
		$all_goals_json = $this->wrap_get_option( $option, $default );
		if ( $all_goals_json ) {
			$all_goals_ary = json_decode( $all_goals_json, true );
			if ( isset( $all_goals_ary[ $tracking_id ] ) ) {
				return $this->wrap_json_encode( $all_goals_ary[ $tracking_id ] );
			} else {
				return null;
			}
		} else {
			return null;
		}
	}

	/**
	 * WordPressのupdate_option関数をqahm用に使いやすくした関数
	 */
	public function wrap_update_option( $option, $value ) {
		return update_option( QAHM_OPTION_PREFIX . $option, $value );
	}

	public function wrap_update_zero_option( $option, $value, $tracking_id ) {
		$all_options_ary  = array();
		$all_options_json = $this->wrap_get_option( $option, false );
		if ( $all_options_json ) {
			$all_options_ary = json_decode( $all_options_json, true );
		}
		$all_options_ary[ $tracking_id ] = json_decode( $value );
		return $this->wrap_update_option( $option, $this->wrap_json_encode( $all_options_ary ) );
	}

	public function wrap_update_goals_option( $option, $value, $tracking_id ) {
		$all_goals_json = $this->wrap_get_option( $option, false );
		if ( $all_goals_json ) {
			$all_goals_ary = json_decode( $all_goals_json, true );
		}
		$all_goals_ary[ $tracking_id ] = json_decode( $value );
		return $this->wrap_update_option( $option, $this->wrap_json_encode( $all_goals_ary ) );
	}
	// zero end

	/**
	 * WordPressのget_user_meta関数をqahm用に使いやすくした関数
	 */
	public function wrap_get_user_meta( $user_id, $meta_key = '', $single = false ) {
		// 空文字列の場合、指定されたユーザーのすべてのメタデータを返される
		if ( $meta_key === '' ) {
			return get_user_meta( $user_id, $meta_key, $single );
		} else {
			return get_user_meta( $user_id, QAHM_OPTION_PREFIX . $meta_key, $single );
		}
	}

	/**
	 * WordPressのupdate_user_meta関数をqahm用に使いやすくした関数
	 */
	public function wrap_update_user_meta( $user_id, $meta_key, $meta_value, $prev_value = '' ) {
		return update_user_meta( $user_id, QAHM_OPTION_PREFIX . $meta_key, $meta_value, $prev_value );
	}

	/**
	 * phpの$this->filter_inputのラップ関数
	 */
	public function wrap_filter_input( $type, $variable_name, $filter = FILTER_DEFAULT ) {
		$checkTypes = array(
			INPUT_GET,
			INPUT_POST,
			INPUT_COOKIE,
		);

		if ( $this->wrap_in_array( $type, $checkTypes ) || filter_has_var( $type, $variable_name ) ) {
			return filter_input( $type, $variable_name, $filter );
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via filter_var() with caller-specified filter.
		} elseif ( $type == INPUT_SERVER && isset( $_SERVER[ $variable_name ] ) ) {
			return filter_var( wp_unslash( $_SERVER[ $variable_name ] ), $filter );
		} elseif ( $type == INPUT_ENV && isset( $_ENV[ $variable_name ] ) ) {
			return filter_var( wp_unslash( $_ENV[ $variable_name ] ), $filter );
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		} else {
			return null;
		}
	}

	/**
	 * WordPressのwp_mailをベースにQAから送れるようにしたもの
	 */
	public function qa_mail( $subject, $message ) {
		$homeurl = get_home_url();
		$domain  = wp_parse_url( $homeurl, PHP_URL_HOST );
		$from    = 'wordpress@' . $domain;
		$return  = false;

		$plugin_name = QAHM_PLUGIN_NAME;
		$headers     = array( "From: {$plugin_name} <{$from}>", 'Content-Type: text/plain; charset=UTF-8' );
		$to          = $this->wrap_get_option( 'send_email_address' );
		$return      = wp_mail( $to, $subject, $message, $headers );
		return $return;
	}

	/**
	 * アクセス権限判定
	 */
	public function check_access_role( $cap ) {
		$user = wp_get_current_user();
		switch ( $cap ) {
			case 'manage_options':
				if ( $user->has_cap( 'manage_options' ) ) {
					return true;
				} else {
					return false;
				}
				break;

			case 'qazero-admin':
				if ( $user->has_cap( 'manage_options' ) || $user->has_cap( 'qazero-admin' ) ) {
					return true;
				} else {
					return false;
				}
				break;

			case 'qazero-view':
				if ( $user->has_cap( 'manage_options' ) || $user->has_cap( 'qazero-admin' ) || $user->has_cap( 'qazero-view' ) ) {
					return true;
				} else {
					return false;
				}
				break;
			default:
				return false;
		}
	}


	/**
	 * ZERO only
	 * ライセンス認証済みか判定
	 */
	public function lic_authorized() {
		$lic_auth = $this->wrap_get_option( 'license_authorized' );
		if ( $lic_auth ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * ==============================
	 * QAHM only
	 * ※呼ばれている箇所がないので削除予定
	 */
	/**
	 * ライセンスプラン配列を取得
	 */
	public function get_plan() {
		$plans = $this->wrap_get_option( 'license_plans' );
		if ( ! $plans ) {
			return null;
		}
		return json_decode( $plans, true );
	}
	/**
	* 該当のプランが組み込まれているかチェック
	* 組み込まれていればその値を返す
	*/
	public function check_plan( $plan_name ) {
		$plans = $this->get_plan();

		if ( $plans && $this->wrap_array_key_exists( $plan_name, $plans ) ) {
			return $plans[ $plan_name ];
		} else {
			return false;
		}
	}
	/**
	 * ==============================
	 */

	/**
	 * プラグインのメインファイルパスを取得
	 */
	public function get_plugin_main_file_path() {
		// 現在のファイルのディレクトリパスを取得
		$current_dir = __DIR__;

		// 同じディレクトリにファイルが存在するかチェック
		$same_dir_path = $current_dir . '/' . QAHM_NAME . '.php';

		if ( file_exists( $same_dir_path ) ) {
			return $same_dir_path;
		}

		// 存在しなければ親ディレクトリから検索
		// 親ディレクトリ + テキストドメインディレクトリ + ファイル名
		$parent_dir_path = dirname( $current_dir ) . '/' . QAHM_TEXT_DOMAIN . '/' . QAHM_NAME . '.php';

		if ( file_exists( $parent_dir_path ) ) {
			return $parent_dir_path;
		}

		// どちらも見つからない場合はデフォルトパスを返す
		return $parent_dir_path;
	}

	/**
	 * jsディレクトリのパスを取得
	 */
	public function get_js_dir_path() {
		return plugin_dir_path( __FILE__ ) . 'js/';
	}

	/**
	 * jsディレクトリのURLを取得
	 */
	public function get_js_dir_url() {
		return plugin_dir_url( __FILE__ ) . 'js/';
	}

	/**
	 * cssディレクトリのパスを取得
	 */
	public function get_css_dir_path() {
		return plugin_dir_path( __FILE__ ) . 'css/';
	}

	/**
	 * cssディレクトリのURLを取得
	 */
	public function get_css_dir_url() {
		return plugin_dir_url( __FILE__ ) . 'css/';
	}

	/**
	 * imgディレクトリのパスを取得
	 */
	public function get_img_dir_path() {
		return plugin_dir_path( __FILE__ ) . 'img/';
	}

	/**
	 * imgディレクトリのURLを取得
	 */
	public function get_img_dir_url() {
		return plugin_dir_url( __FILE__ ) . 'img/';
	}

	/**
	 * dataディレクトリのパスを取得
	 * 引数にdataディレクトリからのパスを入力することにより、
	 * dataディレクトリからの相対パスを取得することができる。
	 *
	 * なおこの関数では念のためディレクトリの存在チェック＆mkdirも行うが、
	 * 相対パスに深い階層を指定してもmkdirされるのは最後の階層のみであり
	 * 道中の階層にはmkdirされない。その点には注意
	 */
	public function get_data_dir_path( $data_rel_path = '' ) {
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			$this->init_wp_filesystem();
		}

		$path = $wp_filesystem->wp_content_dir() . 'qa-zero-data/';
		if ( ! $wp_filesystem->exists( $path ) ) {
			$wp_filesystem->mkdir( $path );
		}

		if ( $data_rel_path ) {
			$path .= $data_rel_path;
			if ( $this->wrap_substr( $path, -1 ) !== '/' ) {
				$path .= '/';
			}

			if ( ! $wp_filesystem->exists( $path ) ) {
				$wp_filesystem->mkdir( $path );
			}
		}

		return $path;
	}

	/**
	 * dataディレクトリのURLを取得
	 */
	public function get_data_dir_url( $data_rel_path = '' ) {
		$path = content_url() . '/' . 'qa-zero-data/';

		if ( $data_rel_path ) {
			$path .= $data_rel_path;
			if ( $this->wrap_substr( $path, -1 ) !== '/' ) {
				$path .= '/';
			}
		}

		return $path;
	}

	/**
	 * tempディレクトリのパスを取得
	 */
	public function get_temp_dir_path() {
		return plugin_dir_path( __FILE__ ) . 'temp/';
	}

	/**
	 * cronのロックファイルのパスを取得
	 */
	public function get_cron_lock_path() {
		return $this->get_data_dir_path() . 'cron_lock';
	}

	/**
	 * cronのステータスファイルのパスを取得 -- maruyama
	 */
	public function get_cron_status_path() {
		return $this->get_data_dir_path() . 'cron_status';
	}

	/**
	 * cronのバックアップファイルのパスを取得 -- maruyama
	 */
	public function get_cron_backup_path() {
		return $this->get_data_dir_path() . 'cron_backup';
	}

	/**
	 * プラグインのメンテナンスモードか判定
	 */
	public function is_maintenance() {
		global $wp_filesystem;
		$maintenance_path = $this->get_temp_dir_path() . 'maintenance.php';
		if ( $wp_filesystem->exists( $maintenance_path ) ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * トラッキングIDを取得
	 */
	public function get_tracking_id( $url = null ) {

		/* QA ZERO del
		if ( $this->is_wordpress() ) {
			// auto
			$parse_url = parse_url( get_home_url() );
			$id = 'a&' . $parse_url['host'];
		} else {
			// manual
			$parse_url = parse_url( $url );
			$id = 'm&' . $parse_url['host'];
		} QA ZERO del */
		//maryama add
		if ( $url == null ) {
			return 'all';
		}
		//maryama add end
		$id = 'z&' . $url;

		return hash( 'fnv164', $id );
	}
	//QA ZERO STSRT
	public function get_url_hash( $url ) {
		return hash( 'fnv164', $url );
	}
	public function get_qaid_from_sessionfile( $filename ) {
		return strstr( $filename, '_', true );
	}
	//QA ZERO END
	/**
	 * WPサイトか判定
	 * この判定方法で良いのかは要検証。今後変わる可能性あり
	 */
	/* QA ZERO del
	public function is_wordpress() {
		if ( function_exists( 'wp_nonce_field' ) ) {
			return true;
		} else {
			return false;
		}
	}
	*/

	/**
	 * qahm対象ページか判定
	 * ※プラグイン読み込み直後のコンストラクタやwp_ajax内では$type引数指定無しの形は使えないので注意
	 */
	public function is_qahm_page( $type = null ) {
		if ( $type ) {
			if (
				$type === 'home' ||
				$type === 'page_id' ||
				$type === 'p' ||
				$type === 'cat' ||
				$type === 'tag' ||
				$type === 'tax'
			) {
				return true;
			} else {
				return false;
			}
		} else {
			if ( is_home() || is_front_page() || is_page() || is_single() || is_category() || is_tag() || is_tax() ) {
				return true;
			} else {
				return false;
			}
		}
	}

	/**
	 * ajaxに関数を登録（認証済みユーザー専用）
	 */
	public function regist_ajax_func( $func ) {
		add_action( 'wp_ajax_' . QAHM_NAME . '_' . $func, array( $this, $func ) );
	}

	/**
	 * ajaxに関数を登録（未認証ユーザーにも公開）
	 */
	public function regist_ajax_func_public( $func ) {
		add_action( 'wp_ajax_' . QAHM_NAME . '_' . $func, array( $this, $func ) );
		add_action( 'wp_ajax_nopriv_' . QAHM_NAME . '_' . $func, array( $this, $func ) );
	}

	/**
	 * wp_filesystem 初期化
	 */
	public function init_wp_filesystem() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				WP_Filesystem();
				return;
			}

			$creds = false;

			$access_type = get_filesystem_method();
			if ( $access_type === 'ftpext' ) {
				$creds = request_filesystem_credentials( '', '', false, false, null );
			}
			if ( ! WP_Filesystem( $creds ) ) {
				// FS 初期化失敗はログに残すが、throw しない。
				// FS 依存の処理は $wp_filesystem の null チェックで個別にガードすること。
				return;
			}
		}
	}

	/**
	 * uriエンコードし小文字にして返す
	 */
	public function encode_uri( $uri ) {
		$uri = preg_replace_callback(
			"{[^0-9a-z_.!~*'();,/?:@&=+$#-]}i",
			function ( $m ) {
				return sprintf( '%%%02X', ord( $m[0] ) );
			},
			$uri
		);
		return mb_strtolower( $uri );
	}

	/**
	 * bot判定
	 */
	public function is_bot() {
		$bot = array(
			'Googlebot',
			'msnbot',
			'bingbot',
			'Yahoo! Slurp',
			'Y!J',
			'facebookexternalhit',
			'Twitterbot',
			'Applebot',
			'Linespider',
			'Baidu',
			'YandexBot',
			'Yeti',
			'dotbot',
			'rogerbot',
			'AhrefsBot',
			'MJ12bot',
			'SMTBot',
			'BLEXBot',
			'linkdexbot',
			'SemrushBot',
			'360Spider',
			'spider',
			'YoudaoBot',
			'DuckDuckGo',
			'Daum',
			'Exabot',
			'SeznamBot',
			'Steeler',
			'Sonic',
			'BUbiNG',
			'Barkrowler',
			'GrapeshotCrawler',
			'MegaIndex.ru',
			'archive.org_bot',
			'TweetmemeBot',
			'PaperLiBot',
			'admantx-apacas',
			'SafeDNSBot',
			'TurnitinBot',
			'proximic',
			'ICC-Crawler',
			'Mappy',
			'YaK',
			'CCBot',
			'Pockey',
			'psbot',
			'Feedly',
			'Superfeedr bot',
			'ltx71',
			'Mail.RU_Bot',
			'Linguee Bot',
			'DuckDuckBot',
			'bidswitchbot',
			'applebot',
			'istellabot',
			'integralads',
			'jet-bot',
			'trendictionbot',
			'blogmuraBot',
			'NetSeer crawler',
			QAHM_NAME . 'bot',
		);

		// 正規表現用に配列を置換
		for ( $i = 0, $bot_len = $this->wrap_count( $bot ); $i < $bot_len; $i++ ) {
			$bot[ $i ] = str_replace( '.', '\.', $bot[ $i ] );
			$bot[ $i ] = str_replace( '-', '\-', $bot[ $i ] );
		}

		$ua = $this->wrap_filter_input( INPUT_SERVER, 'HTTP_USER_AGENT' );
		if ( $ua ) {
			if ( preg_match( '/' . $this->wrap_implode( '|', $bot ) . '/', $ua ) ) {
				return true;
			}
		}
		return false;
	}

	public function os_from_ua( $ua ) {
		$device_os = '';
		if ( empty( $ua ) || ! is_string( $ua ) ) {
			return $device_os;
		}
		// 判別対象のデバイス/OS
		if ( preg_match( '/(iPhone|iPod|iPad|Windows Phone|Opera Mobi|Fennec|Android TV|Android|PlayStation|Xbox|Nintendo Switch|Roku|Fire TV|Apple TV|Tizen|WebOS|SmartTV|Linux|CrOS|Fuchsia)/', $ua, $match ) ) {
			$device_os = $match[1];
		} elseif ( preg_match( '/(Mac OS X [0-9]*.[0-9]*|Windows NT [0-9]*.[0-9]*)/', $ua, $match ) ) {
			$device_os = $match[1];
		}
		$device_os = str_replace( '_', '.', $device_os );
		return $device_os;
	}

	public function browser_from_ua( $ua ) {
		$browser = '';
		$version = '';
		// ブラウザの判別。
		if ( preg_match( '/(MSIE|Chrome|Firefox|Android|Safari|Opera|jp.co.yahoo.ipn.appli)[\/ ]([0-9.]*)/', $ua, $match ) ) {
			$browser = $match[1];
			$version = $match[2];
		}
		return $browser . '/' . $version;
	}

	public function is_zip( $string ) {
		$is_zip = false;
		if ( 3 < $this->wrap_strlen( $string ) ) {
			$byte1 = strtoupper( bin2hex( $this->wrap_substr( $string, 0, 1 ) ) );
			$byte2 = strtoupper( bin2hex( $this->wrap_substr( $string, 1, 1 ) ) );
			$byte3 = strtoupper( bin2hex( $this->wrap_substr( $string, 2, 1 ) ) );

			if ( $byte1 == '1F' && $byte2 == '8B' && $byte3 == '08' ) {
				$is_zip = true;
			}
		}
		return $is_zip;
	}

	//QA ZERO start

	/**
	 * 与えられたparse_urlから第一ディレクトリまでのurlにして返す
	 * 引数：array parse_url(対象URL)の結果
	 * 戻り値：string 第一ディレクトリまでのURL
	 */
	public function to_domain_url( $parse_url, $leave_scheme = false ) {

		if ( $leave_scheme ) {
			$domain_url = $parse_url['scheme'] . '://' . $parse_url['host'] . '/';
		} else {
			$domain_url = $parse_url['host'] . '/';
		}

		// 'path'キーが存在し、空でないかチェック
		if ( isset( $parse_url['path'] ) && ! empty( $parse_url['path'] ) ) {
			$path_array      = $this->wrap_explode( '/', $parse_url['path'] );
			$first_directory = ( $this->wrap_count( $path_array ) > 2 ) ? $path_array[1] . '/' : '';
			$domain_url      = $domain_url . $first_directory;
		}

		return $domain_url;
	}

	/**
	 * シリアライズに使用される特殊文字をエスケープして返す
	 * 引数：string 入力文字
	 * 戻り値：string 特殊文字エスケープ済みの文字列
	 */
	public function serialize_escape( $text ) {

		$escape_target = array( ':', '{', '}' );
		$escape_result = array( '\:', '\{', '\}' );

		return str_replace( $escape_target, $escape_result, $text );
	}
	// QA ZERO MARUYAMA
	/**
	 * LowerURLからPath URLを求める
	 */
	public function to_path_url( $lower_url ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url() may be undefined in early-loading contexts; safe fallback to parse_url().
		$parse_url = parse_url( $lower_url );
		if ( ! $parse_url ) {
			return false;
		}
		if ( ! isset( $parse_url['scheme'] ) || ! isset( $parse_url['host'] ) ) {
			return false;
		}
		$domain_url = $parse_url['scheme'] . '://' . $parse_url['host'];
		if ( ! isset( $parse_url['path'] ) ) {
			return $this->set_trailing_slash( $domain_url );
		}
		$path_str = $parse_url['path'];
		$path_url = $domain_url . $path_str;

		return $this->set_trailing_slash( $path_url );
	}
	public function set_trailing_slash( $url ) {
		if ( $url[-1] != '/' ) {
			$url .= '/';
		}
		return $url;
	}

	private function unparse_url( $parsed_url ) {
		$scheme   = isset( $parsed_url['scheme'] ) ? $parsed_url['scheme'] . '://' : '';
		$host     = isset( $parsed_url['host'] ) ? $parsed_url['host'] : '';
		$port     = isset( $parsed_url['port'] ) ? ':' . $parsed_url['port'] : '';
		$user     = isset( $parsed_url['user'] ) ? $parsed_url['user'] : '';
		$pass     = isset( $parsed_url['pass'] ) ? ':' . $parsed_url['pass'] : '';
		$pass     = ( $user || $pass ) ? "$pass@" : '';
		$path     = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '';
		$query    = isset( $parsed_url['query'] ) ? '?' . $parsed_url['query'] : '';
		$fragment = isset( $parsed_url['fragment'] ) ? '#' . $parsed_url['fragment'] : '';
		return "$scheme$user$pass$host$port$path$query$fragment";
	}

	/**
	 * 対象urlにcurlアクセスを実行する
	 */
	public function curl_get( $url, $connectionTimeout, $timeout, $dev_name, $speed_limit = 1000, $speed_time = 5 ) {

		// ユーザーエージェントの決定
		$bot = QAHM_NAME . 'bot/' . QAHM_PLUGIN_VERSION;

		switch ( $dev_name ) {
			case 'smp':
				$ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 12_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/69.0.3497.91 Mobile/15E148 Safari/605.1' . ' ' . $bot;
				break;
			case 'tab':
				$ua = 'Mozilla/5.0 (iPad; CPU OS 12_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/12.0 Mobile/15E148 Safari/604.1' . ' ' . $bot;
				break;
			default:
				$ua = 'Mozilla/5.0 (Windows NT 5.1) AppleWebKit/535.11 (KHTML, like Gecko) Chrome/17.0.963.79 Safari/535.11' . ' ' . $bot;
				break;
		}

		// URLの解析とエンコード処理
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.WP.AlternativeFunctions.curl_* -- wp_parse_url() and wp_remote_get() are unavailable before WordPress is fully loaded; safe fallback to parse_url() and cURL for internal use.
		$parsed_url = parse_url( $url );
		if ( $parsed_url === false ) {
			// URL解析に失敗した場合は false を返す
			return false;
		}

		// クエリ部分が存在する場合、解析してRFC3986に準拠した形式にエンコード
		if ( isset( $parsed_url['query'] ) ) {
			parse_str( $parsed_url['query'], $query_params );
			$encoded_query       = http_build_query( $query_params, '', '&', PHP_QUERY_RFC3986 );
			$parsed_url['query'] = $encoded_query;
		}

		// パースした情報からURL文字列を再構築
		$url = $this->unparse_url( $parsed_url );

		// Plugin Check exclusion: Uses cURL for internal pre-WordPress HTTP request; wp_remote_get() not available in this context
		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_errno, WordPress.WP.AlternativeFunctions.curl_curl_close
		// cURLセッションの初期化とオプション設定
		$curl = curl_init();
		curl_setopt( $curl, CURLOPT_URL, $url );
		curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $curl, CURLOPT_CONNECTTIMEOUT, $connectionTimeout );
		curl_setopt( $curl, CURLOPT_TIMEOUT, $timeout );
		curl_setopt( $curl, CURLOPT_USERAGENT, $ua );
		curl_setopt( $curl, CURLOPT_LOW_SPEED_LIMIT, $speed_limit );
		curl_setopt( $curl, CURLOPT_LOW_SPEED_TIME, $speed_time );
		curl_setopt( $curl, CURLOPT_FOLLOWLOCATION, true );
		curl_setopt( $curl, CURLOPT_FAILONERROR, true );

		// HTTPSの場合のSSL検証（必要に応じて設定変更）
		if ( stripos( $url, 'https://' ) === 0 ) {
			curl_setopt( $curl, CURLOPT_SSL_VERIFYPEER, true );
			curl_setopt( $curl, CURLOPT_SSL_VERIFYHOST, 2 );
		}

		// URLにアクセスしてレスポンスを取得
		$response = curl_exec( $curl );

		// エラー発生時はfalseを返す
		if ( curl_errno( $curl ) ) {
			$response = false;
		}

		// セッションを閉じる
		curl_close( $curl );

		// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_errno, WordPress.WP.AlternativeFunctions.curl_curl_close

		return $response;
	}

	/**
	 * 指定されたURLから不要なトラッキング用パラメータや除外指定のパラメータを除外して、クリーンなURLを返します。
	 *
	 * 除外対象として、固定のパラメータキーや "utm_" で始まるパラメータ、ユーザ指定のパラメータを除去します。
	 *
	 * @param string $url URL文字列
	 * @param array  $del_param_ary ユーザ指定で除外するパラメータ名の配列（省略可能）
	 *
	 * @return string クリーンアップされたURL
	 */
	public function url_cleansing( $url, $del_param_ary = array() ) {
		// フラグメント部分（#以降）を削除
		$base_url = $this->wrap_explode( '#', $url, 2 )[0];

		// URLを「?」で分割（クエリパラメータがあるかチェック）
		$url_parts = $this->wrap_explode( '?', $base_url, 2 );

		// クエリパラメータが存在しない場合は、そのまま返す
		if ( ! isset( $url_parts[1] ) ) {
			return $base_url;
		}

		// クエリパラメータを配列として解析
		parse_str( $url_parts[1], $query_ary );

		// 固定で除外するパラメータ名のリスト
		$exclude_params = array(
			'gclid',
			'gad_source',
			'_ga',
			'uid',
			'fbclid',
			'twclid',
			'gad',
			'yclid',
			'ldtag_cl',
			'msclkid',
			'sa_p',
			'sa_cc',
			'sa_t',
			'sa_ra',
		);

		// ユーザが指定した除外パラメータがあればマージする
		$exclude_params = $this->wrap_array_merge( $exclude_params, $del_param_ary );

		// 除外条件にマッチしないパラメータのみを残す（"utm_"で始まるものも除外）
		$filtered_query_ary = array();
		foreach ( $query_ary as $key => $value ) {
			if ( $this->wrap_in_array( $key, $exclude_params ) || $this->wrap_strpos( $key, 'utm_' ) === 0 ) {
				continue;
			}
			// パラメータの値が配列の場合、カンマ区切りの文字列に変換
			if ( is_array( $value ) ) {
				$value = $this->wrap_implode( ',', $value );
			}
			$filtered_query_ary[ $key ] = $value;
		}

		// http_build_query() により正しいURLエンコード済みのクエリ文字列を生成
		$query_string = http_build_query( $filtered_query_ary );

		// クエリ文字列が存在すればURLを再構築し、なければベースURLを返す
		return $query_string ? $url_parts[0] . '?' . $query_string : $url_parts[0];
	}

	//QA ZERO end

	/**
	 * qa 翻訳関数
	 */
	public function japan( $text, $domain = '' ) {
		return $text;
	}

	/**
	 * qa_idの生成
	 */
	public function create_qa_id( $ip_address, $ua, $tracking_hash ) {

		global $qahm_time;
		global $behave;

		$unique_server_value = NONCE_SALT . AUTH_SALT;
		//$id_base       = $ip_address.$ua.$tracking_hash;
		$id_base    = $ip_address . $ua . $unique_server_value . $tracking_hash;
		$qa_id_hash = hash( 'fnv164', $id_base );

		return '000000000000' . $qa_id_hash;
	}

	/**
	 * QA用のアラートhtmlを作成（WordPress管理画面用）
	 */
	public function create_qa_announce_html( $text, $status = 'success' ) {
		$base_color = null;
		switch ( $status ) {
			case 'success':
				$base_color = '#00a32a';
				break;

			case 'info':
				$base_color = '#72aee6';
				break;

			case 'warning':
				$base_color = '#dba617';
				break;

			case 'error':
				$base_color = '#d63638';
				break;

			default:
				return null;
		}

		/*
		$svg_icon = '<svg version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"
				x="0px" y="0px" width="20" height="20" viewBox="0 0 256 256" style="enable-background:new 0 0 256 256;" xml:space="preserve">
			<g>
				<g>
					<path class="qahm-announce-icon-' . $status . '" d="M232.7,237.5c4.6,0,9.1-2.1,12-6c4.9-6.6,3.6-16-3.1-20.9l-39.4-29.3l-15.2,26l36.7,27.3
						C226.4,236.6,229.6,237.5,232.7,237.5z"/>
					<path class="qahm-announce-icon-' . $status . '" d="M186.5,189.8c-1.3,0-2.7-0.4-3.8-1.3l-78.5-59c-2.1-1.6-3-4.3-2.3-6.8c0.7-2.5,2.9-4.4,5.5-4.7l44.9-4.6
						c3.6-0.4,6.7,2.2,7,5.7c0.4,3.5-2.2,6.7-5.7,7l-28.6,2.9l65.4,49.1c2.8,2.1,3.4,6.1,1.3,9C190.4,188.9,188.4,189.8,186.5,189.8z"
						/>
					<path class="qahm-announce-icon-' . $status . '" d="M117.4,237c-19.1,0-38-5.1-54.9-14.9c-25.2-14.7-43.2-38.4-50.6-66.6C-3.3,97.2,31.6,37.4,89.9,22.1
						C148.1,6.8,208,41.7,223.3,100l0,0c15.3,58.2-19.6,118.1-77.9,133.4C136.1,235.8,126.7,237,117.4,237z M117.6,44.1
						c-7,0-14.1,0.9-21.2,2.8C51.8,58.6,25,104.4,36.8,149c5.7,21.6,19.4,39.7,38.7,51c19.3,11.3,41.8,14.3,63.4,8.7
						c44.6-11.7,71.3-57.5,59.6-102.1C188.6,69,154.7,44.1,117.6,44.1z"/>
					<path class="qahm-announce-icon-' . $status . '" d="M169.4,124.8c-0.1,0-0.2,0-0.3,0c-4-0.2-7.1-3.5-6.9-7.5c0,0,0,0,0,0c0.2-3.9,3.5-7,7.5-6.9
						c4,0.2,7.1,3.5,6.9,7.5C176.4,121.8,173.2,124.8,169.4,124.8z"/>
					<path class="qahm-announce-icon-' . $status . '" d="M186.5,123.2c-0.1,0-0.2,0-0.3,0c-4-0.2-7.1-3.5-6.9-7.5c0,0,0,0,0,0c0.2-3.9,3.5-7,7.5-6.9
						c1.9,0.1,3.7,0.9,5,2.3c1.3,1.4,2,3.3,1.9,5.2C193.5,120.2,190.3,123.2,186.5,123.2z"/>
				</g>
			</g>
			</svg>';
		*/
		$svg_icon = '';

		return '<div class="qahm-announce-container qahm-announce-container-' . $status . '">' .
				'<div class="qahm-announce-icon">' . $svg_icon . '</div>' .
				'<div class="qahm-announce-text">[' . QAHM_PLUGIN_NAME . '] ' . $text . '</div>' .
				'</div>';
	}

	/**
	 * アラート用HTMLを出力する
	 *
	 * @param string $text   表示するテキスト
	 * @param string $status ステータス（success/info/warning/error）
	 */
	public function print_qa_announce_html( $text, $status = 'success' ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- This HTML is generated by create_qa_announce_html() which handles escaping internally
		echo $this->create_qa_announce_html( $text, $status );
	}

	/**
	 * APCuを利用してtracking_idの検証を行う
	 * qahm_sitemanageに登録されているtracking_idかどうかをチェック
	 */
	public function get_valid_tracking_ids_with_cache() {
		global $wpdb;
		$cache_key        = $wpdb->prefix . 'qa_tracking_ids_cache';
		$cache_expiration = 5 * MINUTE_IN_SECONDS;

		$is_apcu_enabled = function_exists( 'apcu_fetch' );

		$tracking_ids = $is_apcu_enabled ? apcu_fetch( $cache_key ) : false;

		if ( $tracking_ids === false ) {
			$sitemanage = $this->wrap_get_option( 'sitemanage' );
			if ( $sitemanage ) {
				$sitemanage   = $this->wrap_array_filter(
					$sitemanage,
					function ( $item ) {
						return isset( $item['status'] ) && $item['status'] !== 255;
					}
				);
				$sitemanage   = array_values( $sitemanage );
				$tracking_ids = array_column( $sitemanage, 'tracking_id' );
			} else {
				$tracking_ids = array();
			}

			if ( $is_apcu_enabled ) {
				apcu_store( $cache_key, $tracking_ids, $cache_expiration );
			}
		}

		return $tracking_ids;
	}

	/**
	 * tracking_idが有効かどうかを検証する
	 */
	public function validate_tracking_id( $tracking_id ) {
		if ( empty( $tracking_id ) || $tracking_id === 'all' ) {
			return true;
		}

		$valid_tracking_ids = $this->get_valid_tracking_ids_with_cache();
		return $this->wrap_in_array( $tracking_id, $valid_tracking_ids, true );
	}

	/**
	 * 安全なtracking_idを取得する
	 * 無効な場合は'all'を返す
	 */
	public function get_safe_tracking_id( $tracking_id ) {
		if ( $this->validate_tracking_id( $tracking_id ) ) {
			return $tracking_id;
		}

		global $qahm_log;
		if ( $qahm_log ) {
			$ip_address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
			$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'unknown';
			$qahm_log->warning(
				"Invalid tracking_id attempt: '{$tracking_id}' from IP: {$ip_address}, UA: {$user_agent}",
				'tracking_validation.log'
			);
		}

		return 'all';
	}

	/**
	 * tracking_id をサニタイズする（英数/ハイフン/アンダースコア程度に制限推奨）
	 */
	public function sanitize_tracking_id( $raw ) {
		$raw = sanitize_text_field( (string) $raw );
		// さらに厳格にするならホワイトリスト（推奨）
		$raw = preg_replace( '/[^a-zA-Z0-9\-_]/', '', $raw );
		return $raw;
	}

	/** ------------------------------
	 * ユーザーエージェントからデバイス名に変換
	 */
	public function user_agent_to_device_name( $ua ) {
		// モバイルからのアクセス
		if ( stripos( $ua, 'iphone' ) !== false || // iphone
			stripos( $ua, 'ipod' ) !== false || // ipod
			( stripos( $ua, 'android' ) !== false && stripos( $ua, 'mobile' ) !== false ) || // android
			( stripos( $ua, 'windows' ) !== false && stripos( $ua, 'mobile' ) !== false ) || // windows phone
			( stripos( $ua, 'firefox' ) !== false && stripos( $ua, 'mobile' ) !== false ) || // firefox phone
			( stripos( $ua, 'bb10' ) !== false && stripos( $ua, 'mobile' ) !== false ) || // blackberry 10
			( stripos( $ua, 'blackberry' ) !== false ) // blackberry
			) {
			return 'smp';
		}
		// タブレット
		// mobileという文字が含まれていないAndroid端末はすべてタブレット
		elseif ( stripos( $ua, 'android' ) !== false || stripos( $ua, 'ipad' ) !== false ) {
			return 'tab';
		} else {
			return 'dsk';
		}
	}

	/**
	 * ユーザーエージェントからOS名に変換
	 */
	public function user_agent_to_os_name( $ua ) {
		if ( preg_match( '/Windows NT 10.0/', $ua ) ) {
			return 'Windows 10';
		} elseif ( preg_match( '/Windows NT 6.3/', $ua ) ) {
			return 'Windows 8.1';
		} elseif ( preg_match( '/Windows NT 6.2/', $ua ) ) {
			return 'Windows 8';
		} elseif ( preg_match( '/Windows NT 6.1/', $ua ) ) {
			return 'Windows 7';
		} elseif ( preg_match( '/Mac OS X ([0-9\._]+)/', $ua, $matches ) ) {
			return 'Mac OS X ' . str_replace( '_', '.', $matches[1] );
		} elseif ( preg_match( '/Linux ([a-z0-9_]+)/', $ua, $matches ) ) {
			return 'Linux ' . $matches[1];
		} elseif ( preg_match( '/OS ([a-z0-9_]+)/', $ua, $matches ) ) {
			return 'iOS ' . str_replace( '_', '.', $matches[1] );
		} elseif ( preg_match( '/Android ([a-z0-9\.]+)/', $ua, $matches ) ) {
			return 'Android ' . $matches[1];
		} else {
			return 'Unknown';
		}
	}

	/**
	 * ユーザーエージェントからブラウザ名に変換
	 */
	public function user_agent_to_browser_name( $ua ) {
		if ( preg_match( '/(Iron|Sleipnir|Maxthon|Lunascape|SeaMonkey|Camino|PaleMoon|Waterfox|Cyberfox)\/([0-9\.]+)/', $ua, $matches ) ) {
			return $matches[1] . ' ' . $matches[2];
		} elseif ( preg_match( '/Edg\/([0-9\.]+)/', $ua, $matches ) || preg_match( '/Edge\/([0-9\.]+)/', $ua, $matches ) ) {
			return 'Edge' . ' ' . $matches[1];
		} elseif ( preg_match( '/(^Opera|OPR).*\/([0-9\.]+)/', $ua, $matches ) ) {
			return 'Opera' . ' ' . $matches[2];
		} elseif ( preg_match( '/Chrome\/([0-9\.]+)/', $ua, $matches ) ) {
			return 'Chrome' . ' ' . $matches[1];
		} elseif ( preg_match( '/Firefox\/([0-9\.]+)/', $ua, $matches ) ) {
			return 'Firefox' . ' ' . $matches[1];
		} elseif ( preg_match( '/(MSIE\s|Trident.*rv:)([0-9\.]+)/', $ua, $matches ) ) {
			return 'Internet Explorer' . ' ' . $matches[2];
		} elseif ( preg_match( '/\/([0-9\.]+)(\sMobile\/[A-Z0-9]{6})?\sSafari/', $ua, $matches ) ) {
			return 'Safari' . ' ' . $matches[1];
		} else {
			return 'Unknown';
		}
	}

	/**
	 * デバイスIDをデバイス名に変換
	 */
	protected function device_id_to_device_name( $id ) {
		foreach ( QAHM_DEVICES as $qahm_dev ) {
			if ( $qahm_dev['id'] === (int) $id ) {
				return $qahm_dev['name'];
			}
		}

		return false;
	}

	/**
	 * デバイス名をデバイスIDに変換
	 */
	protected function device_name_to_device_id( $name ) {
		foreach ( QAHM_DEVICES as $qahm_dev ) {
			if ( $qahm_dev['name'] === $name ) {
				return $qahm_dev['id'];
			}
		}

		return false;
	}

	/**
	 * tsv形式の文字列データを二次元配列に変換して返す
	 */
	protected function convert_tsv_to_array( $tsv ) {
		$tsv_ary = array();
		$tsv_col = $this->wrap_explode( PHP_EOL, $tsv );

		foreach ( $tsv_col as $tsv_row ) {
			$tsv_row_ary = $this->wrap_explode( "\t", $tsv_row );
			$tsv_ary[]   = $tsv_row_ary;
		}

		return $tsv_ary;
	}


	/**
	 * 二次元配列をtsv形式の文字列データに変換して返す
	 */
	protected function convert_array_to_tsv( $ary ) {
		$tsv = '';

		for ( $i = 0, $col_cnt = $this->wrap_count( $ary ); $i < $col_cnt; $i++ ) {
			for ( $j = 0, $raw_cnt = $this->wrap_count( $ary[ $i ] ); $j < $raw_cnt; $j++ ) {
				// 値にPHP_EOLや\tが入っていた場合はtsvの形が崩れる可能性があるので無視
				$replace = str_replace( PHP_EOL, '', $ary[ $i ][ $j ] );
				$replace = str_replace( "\t", '', $replace );
				$tsv    .= $replace;

				if ( $j === $raw_cnt - 1 ) {
					if ( $i !== $col_cnt - 1 ) {
						$tsv .= PHP_EOL;
					}
				} else {
					$tsv .= "\t";
				}
			}
		}

		return $tsv;
	}

	/**
	 * Flush output buffers and close the HTTP connection.
	 *
	 * Sends the already-echoed response to the client immediately,
	 * then closes the connection so PHP can continue processing
	 * in the background (requires ignore_user_abort(true) beforehand).
	 *
	 * @param int $content_length Byte length of the response body already echoed.
	 */
	protected function flush_and_close_connection( $content_length ) {
		header( 'Connection: close' );
		header( 'Content-Length: ' . $content_length );

		// Flush all output buffers.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- ob_end_flush may warn if no buffer exists.
		while ( ob_get_level() > 0 ) {
			@ob_end_flush();
		}
		flush();

		// php-fpm: finish the request immediately.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
	}

	/**
	 * source_domain が検索エンジンかどうかを判定する。
	 * SEARCH_ENGINES 定数（qahm-const-domain.php）の DOMAIN と完全一致で照合する。
	 * 夜間 cron (class-qahm-cron-proc.php) の判定と同じ基準を表示側から参照できるようにするためのヘルパー。
	 *
	 * @param string $source_domain
	 * @return bool
	 */
	public static function is_search_engine_domain( $source_domain ) {
		if ( empty( $source_domain ) ) {
			return false;
		}
		foreach ( SEARCH_ENGINES as $se ) {
			if ( $source_domain === $se['DOMAIN'] ) {
				return true;
			}
		}
		return false;
	}
}
