<?php
defined( 'ABSPATH' ) || exit;
/**
 *
 *
 * @package qa_heatmap
 */

//
// ライセンスに関するクラス
//

$GLOBALS['qahm_license'] = new QAHM_License();

// ライセンスを管理するクラス
class QAHM_License extends QAHM_File_Base {

	const FREE_MEASURE_PAGE = 1;        // 無料ユーザーの最大計測ページ数

	// プラン
	/*
	const PLAN = array(
		'free'          => 0,
		'friend'        => 1,
		'personal'      => 2,
		'light'         => 3,
		'business'      => 4,
		'businessplus'  => 5,
		'agent'         => 6,
		'enterprise'    => 7
	);
	*/

	// メッセージのレベル
	const MESSAGE_LEVEL = array(
		'success' => 0,
		'info'    => 1,
		'warning' => 2,
		'error'   => 3,
	);

	// メッセージの通知領域
	const MESSAGE_VIEW = array(
		'admin'   => 0,               // 管理画面全体。こちらを選択した場合はqahmのプラグイン名も先頭に自動付与
		'license' => 1,               // 管理画面のライセンス認証画面のみ
		'hidden'  => 2,                // 非表示
	);

	// valuables
	public static $dom = '';

	public function __construct() {
		add_action( 'admin_notices', array( $this, 'view_message' ) );
		add_action( 'init', array( $this, 'init_wp_filesystem' ) );
	}

	/**
	 * ライセンス用にWPドメインを取得
	 */
	private function get_domain() {
		$wp_domain = $this->wrap_get_option( 'license_wp_domain' );
		if ( ! $wp_domain ) {
			$wp_url     = get_option( 'home' );
			$parsed_url = wp_parse_url( $wp_url, PHP_URL_HOST ) . wp_parse_url( $wp_url, PHP_URL_PATH );
			$wp_domain  = $this->wrap_rtrim( $parsed_url, '/' );
			$this->wrap_update_option( 'license_wp_domain', $wp_domain );
		}
		return $wp_domain;
	}

	/*
	 * ライセンスシステムのメッセージを管理画面に表示
	 */
	public function view_message() {
		$msg_ary = $this->wrap_get_option( 'license_message' );
		if ( ! $msg_ary ) {
			return;
		}

		$plugin_name = QAHM_PLUGIN_NAME . ' : ';
		foreach ( $msg_ary as &$msg ) {
			$is_view = true;
			switch ( $msg['view'] ) {
				case self::MESSAGE_VIEW['admin']:
					break;

				case self::MESSAGE_VIEW['license']:
					require_once __DIR__ . '/class-qahm-admin-page-license.php';
					$page = $this->wrap_filter_input( INPUT_GET, 'page' );
					if ( QAHM_Admin_Page_License::SLUG !== $page ) {
						$is_view = false;
					}
					$plugin_name = '';
					break;

				case self::MESSAGE_VIEW['hidden']:
				default:
					$is_view = false;
					break;
			}
			if ( ! $is_view ) {
				continue;
			}

			$class_level = '';
			switch ( $msg['level'] ) {
				case self::MESSAGE_LEVEL['success']:
					if ( self::MESSAGE_VIEW['license'] !== $msg['view'] ) {
						continue 2;
					}
					$class_level = 'notice-success ';
					break;
				case self::MESSAGE_LEVEL['info']:
					$class_level = 'notice-info ';
					break;
				case self::MESSAGE_LEVEL['warning']:
					$class_level = 'notice-warning ';
					break;
				case self::MESSAGE_LEVEL['error']:
					$class_level = 'notice-error ';
					break;
			}
		}
	}

	/**
	 * プラグイン側で設定したいメッセージの出力 & ログの出力
	 * この関数はwp_remote_postで返ってきたjsonの中身（配列）を入れるのではない
	 * 引数によってjsonのメッセージと同じ形式のデータを作りlicese_messageに格納する
	 * この関数により作られたメッセージのメッセージナンバーは空とする。
	 */
	private function set_plugin_message( $level, $msg, $view, $log = '' ) {
		$msg_ary = array(
			'no'      => '',
			'level'   => $level,
			'message' => $msg,
			'view'    => $view,
		);
		$this->wrap_update_option( 'license_message', array( $msg_ary ) );

		if ( $log ) {
			global $qahm_log;
			$qahm_log->error( $log );
		}
	}

	/**
	 * jsonで返されたメッセージ配列をプラグイン用に最適化してreturn
	 */
	private function opt_json_message( $json_msg_ary, $view ) {
		foreach ( $json_msg_ary as &$json_msg ) {
			$json_msg['level'] = self::MESSAGE_LEVEL[ $json_msg['level'] ];
			$json_msg['view']  = $view;
		}
		return $json_msg_ary;
	}

	/**
	 * ライセンス認証の通信処理。戻り値はjsonデータ。通信に失敗した場合はfalse
	 */
	private function remote_post( $url, $args, $view ) {
		$level = self::MESSAGE_LEVEL['error'];
		$msg   = esc_html__( 'An error occurred during authentication. Please try activating the license again, and if you still encounter the same message, kindly contact our support team.', 'qa-heatmap-analytics' );

		//since WP6.4, 'timeout' extended.
		$allmix = wp_remote_post(
			$url,
			array(
				'body'    => $args,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $allmix ) ) {
			$wp_error_code    = $allmix->get_error_code();
			$wp_error_message = $allmix->get_error_message();
			if ( ! $wp_error_code ) {
				$wp_error_code = '';
			}
			if ( ! $wp_error_message ) {
				$wp_error_message = '';
			}
			$msg .= '  [WP_Error Code: ' . $wp_error_code . '; Message: ' . $wp_error_message . ']';
			$this->set_plugin_message( $level, $msg, $view, esc_html__( 'License authentication error', 'qa-heatmap-analytics' ) . ' : wp_remote_post > is_wp_error' . ' Code: ' . $wp_error_code . ' Message: ' . $wp_error_message );
			return false;
		}

		$ret_body = wp_remote_retrieve_body( $allmix );
		if ( ! $ret_body ) {
			$this->set_plugin_message( $level, $msg, $view, esc_html__( 'License authentication error', 'qa-heatmap-analytics' ) . ' : wp_remote_post > wp_remote_retrieve_body' );
			return false;
		}

		$res_code = wp_remote_retrieve_response_code( $allmix );
		if ( ! $res_code ) {
			$this->set_plugin_message( $level, $msg, $view, esc_html__( 'License authentication error', 'qa-heatmap-analytics' ) . ' : wp_remote_post > wp_remote_retrieve_response_code' );
			return false;

		} elseif ( $res_code !== 200 ) {
			$this->set_plugin_message( $level, $msg, $view, esc_html__( 'License authentication error', 'qa-heatmap-analytics' ) . ' : http status code ' . $res_code );
			return false;
		}

		$json_array = json_decode( $ret_body, true );
		if ( $json_array === null ) {
			$this->set_plugin_message( $level, $msg, $view, esc_html__( 'License authentication error', 'qa-heatmap-analytics' ) . ' : wp_remote_post > json_decode' );
			return false;
		}

		return $json_array;
	}

	/**
	 * アクティベート（認証）
	 * $viewではエラーの通知領域をMESSAGE_VIEWの中から設定可能
	 */
	public function activate( $key, $uid, $view = self::MESSAGE_VIEW['admin'], $url = 'https://mem.quarka.org/tsushin/' ) {
		global $qahm_time;
		$this->wrap_update_option( 'license_activate_time', $qahm_time->now_unixtime() );

		global $qahm_data_api;
		$sitemanage   = $qahm_data_api->get_sitemanage();
		$tagged_sites = array_column( $sitemanage, 'domain' );

		$parm                = array();
		$parm['sec']         = 'license'; // Specific to QA
		$parm['cmd']         = 'check';
		$parm['ver']         = QAHM_PLUGIN_VERSION;
		$parm['dom']         = $this->get_domain();
		$parm['uid']         = $uid;
		$parm['key']         = $key;
		$parm['taggedsites'] = $tagged_sites;

		$json_array = $this->remote_post( $url, $parm, $view );
		if ( ! $json_array ) {
			return false;
		}
		if ( QAHM_DEBUG >= QAHM_DEBUG_LEVEL['debug'] ) {
			//print_r( $json_array );
		}

		$is_success = false;
		if ( $json_array['is_success'] ) {
			$is_success = true;
			$this->change_zero_authorized( $json_array['val'], $json_array['bin'] );
			$json_array['msg'][0]['message'] = esc_html__( 'License authentication was successful.', 'qa-heatmap-analytics' );
			$json_array['msg'][0]['level']   = 'success';
			$json_array['msg'][0]['no']      = 0;

			if ( $view === self::MESSAGE_VIEW['admin'] ) {
				$view = self::MESSAGE_VIEW['hidden'];
			} elseif ( $view === self::MESSAGE_VIEW['license'] ) {
				// ライセンス認証画面の紙吹雪エフェクト用
				$json_array['msg'][0]['confetti'] = true;
			}
		} else {
			$this->change_zero_unauthorized();
		}

		if ( $json_array['msg'] ) {
			$msg = $this->opt_json_message( $json_array['msg'], $view );
			$this->wrap_update_option( 'license_message', $msg );
		} else {
			$this->wrap_update_option( 'license_message', '' );
		}
		return $is_success;
	}

	// このドメインのライセンスを削除する
	public function deactivate( $key, $uid, $view = self::MESSAGE_VIEW['admin'], $url = 'https://mem.quarka.org/tsushin/' ) {
		$parm        = array();
		$parm['sec'] = 'license'; //Specific to QA
		$parm['cmd'] = 'deactivate';
		$parm['dom'] = $this->get_domain();
		$parm['uid'] = $uid;
		$parm['key'] = $key;

		$json_array = $this->remote_post( $url, $parm, $view );
		if ( ! $json_array ) {
			return false;
		}
		if ( QAHM_DEBUG >= QAHM_DEBUG_LEVEL['debug'] ) {
			//print_r( $json_array );
		}

		$is_success = false;
		if ( $json_array['is_success'] ) {
			$is_success = true;
			$this->change_zero_unauthorized();
			$json_array['msg'][0]['message'] = esc_html__( 'ライセンス認証を解除しました。', 'qa-heatmap-analytics' );
			$json_array['msg'][0]['level']   = 'success';
			$json_array['msg'][0]['no']      = 0;

			if ( $view === self::MESSAGE_VIEW['admin'] ) {
				$view = self::MESSAGE_VIEW['hidden'];
			}
		}

		if ( $json_array['msg'] ) {
			$msg = $this->opt_json_message( $json_array['msg'], $view );
			$this->wrap_update_option( 'license_message', $msg );
		} else {
			$this->wrap_update_option( 'license_message', '' );
		}
		return $is_success;
	}

	// 有効化処理 for ZERO 2024_09 edited
	public function change_zero_authorized( $val, $bin ) {
		global $wp_filesystem;
		global $qahm_log;

		// ライセンス認証情報を保存
		$this->wrap_update_option( 'license_authorized', true );

		// ライセンスオプションがあれば保存
		if ( $this->wrap_array_key_exists( 'options', $val ) && $val['options'] !== null ) {
			$this->wrap_update_option( 'license_options', $val['options'] );
		}

		if ( ! $bin ) {
			return;
		}

		// Brainsファイルの作成・更新
		if ( QAHM_DEBUG < QAHM_DEBUG_LEVEL['debug'] ) {
			$zero_brains_dir = $this->get_data_dir_path() . 'brains/';

			// ディレクトリごとに保存対象ファイルリストを管理
			$updated_files_per_directory = array();

			foreach ( $bin as $file ) {
				// 保存先ディレクトリ
				$save_directory = $zero_brains_dir . $file['directory'];

				// ディレクトリを作成
				if ( ! $this->create_directory_recursive( $save_directory ) ) {
					$qahm_log->warning( 'Failed to create directory: ' . $save_directory );
					continue;
				}

				// ファイル保存処理
				$file_path = $save_directory . '/' . $file['name'];
				$body      = base64_decode( $file['body'] );
				if ( $body === false ) {
					$qahm_log->warning( 'Failed to decode file body for: ' . $file['name'] );
					continue;
				}
				if ( ! $wp_filesystem->put_contents( $file_path, $body ) ) {
					$qahm_log->warning( 'Failed to write file: ' . $file_path );
					continue;
				}

				// 保存対象ファイルリストに追加
				if ( ! isset( $updated_files_per_directory[ $save_directory ] ) ) {
					$updated_files_per_directory[ $save_directory ] = array();
				}
				$updated_files_per_directory[ $save_directory ][] = $file['name'];
			}

			// 不要なディレクトリ、ファイルを削除
			foreach ( $updated_files_per_directory as $save_directory => $updated_files ) {
				$directory_files = $wp_filesystem->dirlist( $save_directory );
				if ( ! is_array( $directory_files ) ) {
					continue;
				}
				foreach ( $directory_files as $dir_file ) {
					if ( ! $this->wrap_in_array( $dir_file['name'], $updated_files, true ) ) {
						$delete_file_path = $save_directory . '/' . $dir_file['name'];
						if ( $wp_filesystem->exists( $delete_file_path ) ) {
							$wp_filesystem->delete( $delete_file_path, true );
							$qahm_log->info( 'Deleted file: ' . $delete_file_path );
						}
					}
				}
			}
		}
	}

	// 無効化処理
	public function change_zero_unauthorized() {
		global $wp_filesystem;

		// Brainsファイル削除
		if ( QAHM_DEBUG < QAHM_DEBUG_LEVEL['debug'] ) {
			$zero_brains_dir = $this->get_data_dir_path() . 'brains';
			$this->delete_directory_contents( $zero_brains_dir );
		}

		// wp_optionsのパラメーターを初期化
		$this->wrap_update_option( 'license_authorized', false );
		$this->wrap_update_option( 'license_options', null );
		$this->wrap_update_option( 'license_wp_domain', '' );
	}


	/**
	 * 親ディレクトリを再帰的に作成
	 * 　mkdir は指定したディレクトリを作成するだけで、その親ディレクトリが存在しない場合は作成できないため
	 */
	public function create_directory_recursive( $dir ) {
		global $wp_filesystem;

		// すでにディレクトリが存在する場合は何もしない
		if ( $wp_filesystem->exists( $dir ) ) {
			return true;
		}

		$parent_dir = dirname( $dir );
		if ( $parent_dir !== $dir ) {
			// 親ディレクトリを再帰的に作成
			$parent_created = $this->create_directory_recursive( $parent_dir );
			if ( ! $parent_created ) {
				return false;
			}
		}

		// 現在のディレクトリを作成
		$created = $wp_filesystem->mkdir( $dir );
		if ( ! $created ) {
			return false;
		}

		return true;
	}


	/**
	 * ディレクトリ内のアイテムを再帰的に削除
	 * 　渡す$dirは最後にスラッシュをつけない
	 * 　※空になったトップディレクトリだけ残す
	 */
	public function delete_directory_contents( $dir ) {
		global $wp_filesystem;

		// ディレクトリが存在しない場合は終了
		if ( ! $wp_filesystem->is_dir( $dir ) ) {
			return false;
		}

		// ディレクトリ内のアイテムを取得
		$items = $wp_filesystem->dirlist( $dir );
		foreach ( $items as $item ) {
			$path = $dir . '/' . $item['name'];
			if ( $item['type'] == 'd' ) {
				// サブディレクトリの中身を再帰的に削除
				$this->delete_directory_contents( $path );
				// サブディレクトリ自体を削除
				$wp_filesystem->rmdir( $path );
			} else {
				// ファイルを削除
				$wp_filesystem->delete( $path );
			}
		}
		return true;
	}
} // end of class
