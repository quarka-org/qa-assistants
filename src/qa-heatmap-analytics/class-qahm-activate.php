<?php
defined( 'ABSPATH' ) || exit;
/**
 * プラグインを有効化
 * DBのテーブルに関しては自前のクラス内でアクティベート処理を実行している
 * これはregister_activation_hook関数内ではグローバル変数のアクセス権がないためである
 * 参考：https://wpdocs.osdn.jp/%E9%96%A2%E6%95%B0%E3%83%AA%E3%83%95%E3%82%A1%E3%83%AC%E3%83%B3%E3%82%B9/register_activation_hook
 *
 * アンインストール処理についてはuninstall.phpを参照
 * 上記URLの理由にてqahm-uninstall.phpという名称には出来なかった
 *
 * @package qa_heatmap
 */

// データの初期化
new QAHM_Activate();

class QAHM_Activate extends QAHM_File_Base {

	const HOOK_CRON_DATA_MANAGE = QAHM_OPTION_PREFIX . 'cron_data_manage';

	/**
	 * コンストラクタ
	 */
	public function __construct() {
		// プラグイン有効化 / 無効化時の処理
		add_action( 'activated_plugin', array( $this, 'activation' ) );
		add_action( 'deactivated_plugin', array( $this, 'deactivation' ) );
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );

		// スケジュールイベントを設定（消失用にこのタイミングで。念のため）
		add_action( 'wp_loaded', array( $this, 'set_schedule_event_list' ) );

		// sitemanageに登録
		add_action( 'init', array( $this, 'regist_sitemanage' ) );
	}

	/**
	 * プラグイン有効化時の処理
	 */
	public function activation( $plugin ) {
		// 自分のプラグインが有効化された場合のみ処理
		$our_plugins = array(
			'qa-zero/qahm.php',
			'qa-heatmap-analytics/qahm.php',
		);

		if ( ! $this->wrap_in_array( $plugin, $our_plugins ) ) {
			return; // 自分のプラグインでない場合は何もしない
		}

		// 念のため
		$this->deactivation( $plugin );

		$this->wrap_mkdir( $this->get_data_dir_path( 'readers' ) );
		$this->wrap_mkdir( $this->get_data_dir_path( 'heatmap-view-work' ) );

		// wp_optionsの初期値設定
		foreach ( QAHM_OPTIONS as $key => $value ) {
			$this->check_exist_update( $key, $value );
		}

		// Specific to ZERO - Start ---------------
		// 権限の追加
		if ( QAHM_TYPE === QAHM_TYPE_ZERO ) {
			$capabilities = array(
				'read'                     => true, // WordPress のデフォルト権限、ダッシュボードへのアクセスを許可
				'qazero_admin_page_access' => true, // カスタム権限
			);
			add_role( 'qazero-admin', 'QA Zero Admin', $capabilities );
			add_role( 'qazero-view', 'QA Zero View', $capabilities );
		}
		// Specific to ZERO - End -----------------

		$this->setup_config_file();
	}

	/**
	 * プラグイン無効化時の処理
	 */
	public function deactivation( $plugin ) {
		// 自分のプラグインが無効化された場合のみ処理
		$our_plugins = array(
			'qa-zero/qahm.php',
			'qa-heatmap-analytics/qahm.php',
		);

		if ( ! $this->wrap_in_array( $plugin, $our_plugins ) ) {
			return; // 自分のプラグインでない場合は何もしない
		}

		// Specific to ZERO - Start ---------------
		// 権限の削除
		if ( QAHM_TYPE === QAHM_TYPE_ZERO ) {
			remove_role( 'qazero-admin' );
			remove_role( 'qazero-view' );
		}
		// Specific to ZERO - End -----------------
	}

	/**
	 * オプションが存在しなければアップデート
	 */
	private function check_exist_update( $option, $value ) {
		if ( $this->wrap_get_option( $option, -123454321 ) === -123454321 ) {
			$this->wrap_update_option( $option, $value );
		}
	}

	/**
	 * add_filter 2分毎に実行するcronのスケジュール
	 */
	public function add_cron_schedules( $schedules ) {
		// Specific to QA - Start ---------------
		// QAのみ2分間隔のスケジュールを登録
		if ( QAHM_TYPE === QAHM_TYPE_WP ) {
			if ( ! isset( $schedules['2min'] ) ) {
				$schedules['2min'] = array(
					'interval' => 2 * 60,
					'display'  => 'Once every 2 minutes',
				);
			}
		}
		// Specific to QA - End -----------------
		return $schedules;
	}

	/**
	 * スケジュールイベントを設定。全てのcronスケジュールをここに登録
	 */
	public function set_schedule_event_list() {
		// Specific to QA - Start ---------------
		// QAのみスケジュールイベントを設定
		if ( QAHM_TYPE === QAHM_TYPE_WP ) {
			$this->set_schedule_event( '2min', self::HOOK_CRON_DATA_MANAGE );
		}
		// Specific to QA - End -----------------
	}

	/**
	 * スケジュールイベントを設定
	 */
	private function set_schedule_event( $recurrence, $hook ) {
		if ( ! wp_next_scheduled( $hook ) ) {
			// WordPressのタイムゾーンを考慮してスケジュールイベントを登録
			$gmt_timestamp = time();

			// スケジュールイベントを登録
			wp_schedule_event( $gmt_timestamp, $recurrence, $hook );
		}
	}

	/**
	 * sitemanageに登録
	 * 有効化時に登録したかったが、フックのタイミングの関係でinitフックに登録
	 */
	public function regist_sitemanage() {
		global $qahm_admin_page_entire;

		// Specific to QA - Start ---------------
		// sitemanageに登録
		if ( QAHM_TYPE === QAHM_TYPE_WP ) {
			// 既に登録されているなら登録しない
			if ( ! $this->wrap_get_option( 'sitemanage' ) ) {
				// 通常パス（qtag 生成含む）
				if ( $qahm_admin_page_entire ) {
					try {
						$qahm_admin_page_entire->set_sitemanage_domainurl( get_site_url() );
					} catch ( Exception $e ) {
						// WP_Filesystem 失敗等で例外が発生した場合はフォールバック
					}
				}

				// フォールバック: 上記が失敗しても DB 登録だけは確実に行う
				if ( ! $this->wrap_get_option( 'sitemanage' ) ) {
					$parse_url = wp_parse_url( get_site_url() );
					if ( $parse_url && isset( $parse_url['host'] ) ) {
						$domain_url  = $parse_url['host'] . ( isset( $parse_url['path'] ) ? $parse_url['path'] : '/' );
						$tracking_id = substr( md5( uniqid( wp_rand(), true ) ), 0, 16 );
						$sitemanage  = array(
							array(
								'site_id'                => 0,
								'url'                    => $domain_url,
								'domain'                 => $parse_url['host'],
								'tracking_id'            => $tracking_id,
								'memo'                   => '',
								'status'                 => 0,
								'ignore_params'          => '',
								'search_params'          => '',
								'ignore_ips'             => '',
								'url_case_sensitivity'   => 0,
								'get_base_html_periodic' => 0,
								'anontrack'              => 1,
								'insert_datetime'        => current_time( 'mysql' ),
							),
						);
						$this->wrap_update_option( 'sitemanage', $sitemanage );
					}
				}
			}
		}
		// Specific to QA - End -----------------
	}

	/**
	 * 統合設定ファイル機能の実行
	 * プロダクト固有設定をデータディレクトリにコピーし、wp-load.phpパスを追記
	 */
	public function setup_config_file() {
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			$this->init_wp_filesystem();
		}

		// WP_Filesystem の初期化に失敗した場合はスキップ（次回管理画面アクセス時にリトライ）
		if ( empty( $wp_filesystem ) ) {
			return false;
		}

		$wp_load_path = ABSPATH;
		if ( ! $wp_load_path ) {
			return false;
		}

		if ( file_exists( __DIR__ . '/qa-config.php' ) ) {
			$source_config = __DIR__ . '/qa-config.php';
		} else {
			$source_config = dirname( __DIR__, 1 ) . '/' . QAHM_TEXT_DOMAIN . '/qa-config.php';
		}

		$config_file_path  = WP_CONTENT_DIR;
		$config_file_path .= '/qa-zero-data/qa-config.php';

		// ディレクトリが存在しない場合は作成
		$config_dir = dirname( $config_file_path );
		if ( ! $wp_filesystem->exists( $config_dir ) ) {
			$wp_filesystem->mkdir( $config_dir, 0755, true );
		}

		$config_content = '';
		if ( file_exists( $source_config ) ) {
			$source_content = $wp_filesystem->get_contents( $source_config );
			if ( $source_content ) {
				$config_content = $source_content;
			}
		}

		if ( $wp_filesystem->exists( $config_file_path ) ) {
			$existing_content = $wp_filesystem->get_contents( $config_file_path );
			if ( $existing_content && $this->wrap_strpos( $existing_content, 'QAHM_CONFIG_WP_ROOT_PATH' ) !== false ) {
				return true;
			}
			if ( $existing_content && empty( $config_content ) ) {
				$config_content = $existing_content;
			}
		}

		if ( empty( $config_content ) ) {
			$config_content  = "<?php\n";
			$config_content .= "// QA Platform Configuration File\n";
			$config_content .= "// Auto-generated during plugin activation\n\n";
		}

		if ( $this->wrap_strpos( $config_content, 'QAHM_CONFIG_WP_ROOT_PATH' ) === false ) {
			$config_content .= "\n";
			$config_content .= "define('QAHM_CONFIG_WP_ROOT_PATH', '" . addslashes( $wp_load_path ) . "');\n";
		}

		return $wp_filesystem->put_contents( $config_file_path, $config_content, 0644 );
	}
}
