<?php
defined( 'ABSPATH' ) || exit;
/**
 *
 *
 * @package qa_heatmap
 */

$GLOBALS['qahm_admin_page_config'] = new QAHM_Admin_Page_Config();

class QAHM_Admin_Page_Config extends QAHM_Admin_Page_Base {

	// スラッグ
	const SLUG = QAHM_NAME . '-config';

	// nonce
	const NONCE_ACTION = self::SLUG . '-nonce-action';
	const NONCE_NAME   = self::SLUG . '-nonce-name';

	private static $error_msg = array();
	private $localize_ary;

	// admin_init から create_html へ受け渡す GA4 設定保存通知（{message:string,status:'success'|'error'} or null）
	private $ga4_notice = null;
	// admin_init から create_html へ受け渡す OAuth 復元タブ（'tab_google' or null）
	private $pending_tab = null;

	/**
	 * コンストラクタ
	 */
	public function __construct() {
		parent::__construct();

		// コールバック
		add_action( 'init', array( $this, 'init_wp_filesystem' ) );
		add_action( 'load-toplevel_page_qahm-config', array( $this, 'admin_init' ) );
		// QA Assistants(WP) では設定画面が「QA Assistants」配下のサブメニュー（add_submenu_page）として
		// 登録されるため、load フックが toplevel_page_… にならず（実体は qa-assistants_page_qahm-config 等）、
		// 上の load-toplevel フックでは admin_init が発火しない＝Google 認証フォームの送信が無反応になる。
		// メニュー位置・タイトル由来のフック名に依存しないよう、WP では generic admin_init 経由でも呼ぶ
		// （admin_init() 先頭のページガードで qahm-config 以外は即 return するため他画面には無影響）。
		// 二重発火しない前提: WP は設定画面が常にサブメニュー（class-qahm-admin-init.php の sub_menu_mode=true）
		// ゆえ load-toplevel_page_qahm-config は発火せず、admin_init は generic 経由の1回のみ。
		// ※将来 WP を toplevel メニュー化する場合は、load-toplevel と generic の二重登録になり
		// 　admin_init() が2回走る点に注意（現状は保存系が nonce 必須＋冪等、redirect は exit するため実害は出ないが、
		// 　その際はこの generic 追加を見直すこと）。
		if ( QAHM_TYPE === QAHM_TYPE_WP ) {
			add_action( 'admin_init', array( $this, 'admin_init' ) );
		}

		// AJAX関数の登録
		add_action( 'wp_ajax_qahm_ajax_save_plugin_config', array( $this, 'ajax_save_plugin_config' ) );
		add_action( 'wp_ajax_qahm_ajax_save_measurement_config', array( $this, 'ajax_save_measurement_config' ) );
		add_action( 'wp_ajax_qahm_get_ga4_properties', array( $this, 'ajax_get_ga4_properties' ) );
	}

	// 管理画面の初期化
	public function admin_init() {
		// generic 'admin_init' 経由（QA Assistants のサブメニュー対策）でも呼ばれるため、対象ページ＋権限で絞る。
		// 旧 load-toplevel_page_… フックは WP がページの capability を確認した後にのみ発火していたので、
		// その暗黙の cap ゲートをここで明示復元する（cap はメニュー登録 admin-init.php と同一ロジック）。
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || self::SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		$required_cap = ( QAHM_TYPE === QAHM_TYPE_ZERO ) ? 'qahm_analytics' : 'manage_options';
		if ( ! current_user_can( $required_cap ) ) {
			return;
		}
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}
		if ( $this->is_redirect() ) {
			return;
		}

		global $qahm_google_api;
		global $qahm_data_api;

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended
		$tracking_id_raw = isset( $_GET['tracking_id'] ) ? $this->sanitize_tracking_id( wp_unslash( $_GET['tracking_id'] ) ) : 'all';
		$tracking_id     = $this->get_safe_tracking_id( $tracking_id_raw );

		// Google OAuth コールバック時の復元
		// Google は redirect_uri に query を勝手に付与できないため、
		// コールバック URL には tracking_id が載らず、admin_init は tracking_id='all' で呼ばれる。
		// POST で認証開始した直前の tracking_id を transient に積んでおき、
		// ?code=XXX が付いた戻り時だけ復元する（後段で init_for_admin が fetchAccessTokenWithAuthCode を実行してtokenを保存する）。
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $tracking_id === 'all' && isset( $_GET['code'] ) ) {
			$pending_key = 'qahm_google_oauth_pending_' . get_current_user_id();
			$pending     = get_transient( $pending_key );
			if ( is_array( $pending ) && ! empty( $pending['tracking_id'] ) ) {
				$tracking_id = $this->get_safe_tracking_id( $pending['tracking_id'] );
				if ( ! empty( $pending['tab'] ) ) {
					$this->pending_tab = sanitize_key( $pending['tab'] );
				}
				delete_transient( $pending_key );
			}
		}

		// ALL選択時は初期化をスキップ（create_htmlでメッセージ表示）
		if ( $tracking_id === 'all' ) {
			return;
		}

		$scope   = array( 'https://www.googleapis.com/auth/webmasters.readonly', 'https://www.googleapis.com/auth/analytics.readonly' );

		$sitemanage = $qahm_data_api->get_sitemanage();
		if ( $sitemanage ) {
			$url = null;
			foreach ( $sitemanage as $site ) {
				if ( $tracking_id === $site['tracking_id'] ) {
					$url = $site['url'];
					break;
				}
			}

			if ( isset( $_POST[ self::NONCE_NAME ] ) ) {
				// フォーム送信時
				// どのフォームが送信されたかを確認
				$form_type = isset( $_POST['form_type'] )
					? sanitize_key( wp_unslash( $_POST['form_type'] ) )
					: '';

				// Google API 設定フォームの場合のみ処理
				if ( 'save_google_credentials' === $form_type ) {

					// nonceチェック
					check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME ); // 失敗時は内部で停止するので分岐不要

					// wrap_filter_inputではなく、WordPressの unslash → sanitize を使う
					$client_id     = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
					$client_secret = isset( $_POST['client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['client_secret'] ) ) : '';

					// Client Secret はセキュリティ上フォームに再表示しない（マスク）。
					// 空のまま送信された場合（保存済みで未変更）は、既存の値を維持する。
					if ( '' === $client_secret ) {
						$existing_cred = $qahm_google_api->get_credentials( $tracking_id );
						if ( is_array( $existing_cred ) && ! empty( $existing_cred['client_secret'] ) ) {
							$client_secret = $existing_cred['client_secret'];
						}
					}

					$qahm_google_api->set_credentials( $client_id, $client_secret, null, $tracking_id );
					$qahm_google_api->set_tracking_id( $tracking_id, $url );

					// Google redirect_uri には tracking_id を含められないため、
					// コールバック時に復元できるよう直前の tracking_id を transient に保存する。
					// 将来的にタブ復元等にも拡張できるよう配列で持つ。
					set_transient(
						'qahm_google_oauth_pending_' . get_current_user_id(),
						array(
							'tracking_id' => $tracking_id,
							'tab'         => 'tab_google',
						),
						10 * MINUTE_IN_SECONDS
					);

					$qahm_google_api->init_for_admin(
						'Google API Integration',
						$scope,
						admin_url( 'admin.php?page=qahm-config' ),
						true
					);
				}

				// GA4プロパティ設定の保存
				if ( 'save_ga4_settings' === $form_type ) {
					check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );
					$ga4_property_id   = isset( $_POST['ga4_property_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ga4_property_id'] ) ) : '';
					$ga4_property_name = isset( $_POST['ga4_property_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ga4_property_name'] ) ) : '';
					if ( empty( $ga4_property_id ) ) {
						$ga4_property_id = isset( $_POST['ga4_property_id_manual'] ) ? sanitize_text_field( wp_unslash( $_POST['ga4_property_id_manual'] ) ) : '';
						// 手入力経由は displayName が無いので name はクリア
						$ga4_property_name = '';
					}
					// プロパティIDは数字のみに制限（"properties/XXXXXXXX" → "XXXXXXXX"）
					if ( strpos( $ga4_property_id, 'properties/' ) === 0 ) {
						$ga4_property_id = substr( $ga4_property_id, 11 );
					}
					if ( ctype_digit( $ga4_property_id ) && strlen( $ga4_property_id ) > 0 ) {
						$ga4_settings = get_option( 'qahm_ga4_settings', array() );
						$ga4_settings[ $tracking_id ] = array(
							'ga4_property_id'   => $ga4_property_id,
							'ga4_property_name' => $ga4_property_name,
						);
						update_option( 'qahm_ga4_settings', $ga4_settings );
						$this->ga4_notice  = array(
							'message' => __( 'GA4 property saved.', 'qa-heatmap-analytics' ),
							'status'  => 'success',
						);
						$this->pending_tab = 'tab_google';
					} else {
						$this->ga4_notice  = array(
							'message' => __( 'Invalid GA4 property ID. Please enter a numeric value.', 'qa-heatmap-analytics' ),
							'status'  => 'error',
						);
						$this->pending_tab = 'tab_google';
					}
				}
			} else {
				// 通常表示
				$qahm_google_api->set_tracking_id( $tracking_id, $url );
				$qahm_google_api->init_for_admin(
					'Google API Integration',
					$scope,
					admin_url( 'admin.php?page=qahm-config' )
				);
			}
		}
	}

	/**
	 * 初期化
	 */
	public function enqueue_scripts( $hook_suffix ) {
		if ( $this->hook_suffix !== $hook_suffix ||
			! $this->is_enqueue_jquery()
		) {
			return;
		}

		if ( $this->is_redirect() ) {
			return;
		}

		global $qahm_time;
		$js_dir      = $this->get_js_dir_url();
		$data_dir    = $this->get_data_dir_url();
		$css_dir_url = $this->get_css_dir_url();

		$GOALMAX = QAHM_CONFIG_GOALMAX;

		// enqueue_style
		$this->common_enqueue_style();
		wp_enqueue_script( QAHM_NAME . '-admin-page-config', $js_dir . 'admin-page-config.js', array( QAHM_NAME . '-effect' ), QAHM_PLUGIN_VERSION ); //QA ZERO add

		if ( QAHM_TYPE === QAHM_TYPE_ZERO ) {
			wp_enqueue_style( QAHM_NAME . '-admin-page-config', $css_dir_url . 'admin-page-config-zero.css', array( QAHM_NAME . '-reset' ), QAHM_PLUGIN_VERSION );
		} elseif ( QAHM_TYPE === QAHM_TYPE_WP ) {
			wp_enqueue_style( QAHM_NAME . '-admin-page-config', $css_dir_url . 'admin-page-config-wp.css', array( QAHM_NAME . '-reset' ), QAHM_PLUGIN_VERSION );
		}

		// enqueue script
		$this->common_enqueue_script();

		// g_clickpage の変数作成
		global $qahm_data_api;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended
		$tracking_id_raw = isset( $_GET['tracking_id'] ) ? $this->sanitize_tracking_id( wp_unslash( $_GET['tracking_id'] ) ) : 'all';
		$tracking_id     = $this->get_safe_tracking_id( $tracking_id_raw );

		// ALL選択時はスクリプト登録をスキップ
		if ( $tracking_id === 'all' ) {
			return;
		}

		$goals_ary          = $qahm_data_api->get_goals_preferences( $tracking_id );
		$click_iframe_url   = esc_url( get_home_url() );
		$measuring_page_url = array();
		$g_clickpage_ary    = array();
		for ( $iii = 1; $iii <= $GOALMAX; $iii++ ) {
			$g_clickpage = isset( $goals_ary[ $iii ]['g_clickpage'] ) ? urldecode( $goals_ary[ $iii ]['g_clickpage'] ) : '';
			//set default
			if ( ! $g_clickpage ) {
				$g_clickpage = $click_iframe_url;
			}
			$g_clickpage_ary[ $iii ] = $g_clickpage;
		}
		// inline script
		$scripts                = $this->get_common_inline_script();
		$scripts['access_role'] = $this->wrap_get_option( 'access_role' );
		$scripts['wp_time_adj'] = get_option( 'gmt_offset' );
		$scripts['wp_lang_set'] = get_bloginfo( 'language' );
		$scripts['goalmax']     = $GOALMAX;
		$scripts['g_clickpage'] = $g_clickpage_ary;
		wp_add_inline_script( QAHM_NAME . '-common', 'var ' . QAHM_NAME . ' = ' . QAHM_NAME . ' || {}; let ' . QAHM_NAME . 'Obj = ' . $this->wrap_json_encode( $scripts ) . '; ' . QAHM_NAME . ' = Object.assign( ' . QAHM_NAME . ', ' . QAHM_NAME . 'Obj );', 'before' );

		// localize
		$localize                             = $this->get_common_localize_script();
		$localize['data_save_month_title']    = esc_html__( 'Data Storage Period', 'qa-heatmap-analytics' );
		$localize['settings_saved']           = esc_attr__( 'Settings saved.', 'qa-heatmap-analytics' );
		$localize['cnv_couldnt_saved']        = esc_html__( 'Could not be saved. The value is same as before or is incorrect.', 'qa-heatmap-analytics' );
		// #1060: 保存処理が長引いて応答が返らなかった場合の文言。値が不正な場合とは原因が異なるため区別する。
		$localize['cnv_save_timeout']         = esc_html__( 'The response is taking longer than expected. The goal may have been saved. Please reload the page after a while to check.', 'qa-heatmap-analytics' );
		$localize['cnv_delete_confirm']       = esc_html__( 'Are you sure to delete this goal?', 'qa-heatmap-analytics' );
		$localize['cnv_couldnt_delete']       = esc_html__( 'Could not delete. The value is incorrect.', 'qa-heatmap-analytics' );
		$localize['cnv_page_set_alert']       = esc_html__( 'You are trying to set all the pages.', 'qa-heatmap-analytics' );
		$localize['cnv_goal_numbering_alert'] = esc_html__( 'There is a skip in goal numbers. Please set goals sequentially.', 'qa-heatmap-analytics' );
		/* translators: placeholders are for a goal ID */
		$cnv_saved_message = esc_html__( 'Goal %d saved successfully.', 'qa-heatmap-analytics' );
		if ( QAHM_TYPE === QAHM_TYPE_ZERO ) {
			// QA ZERO は AI 指示文を夜間 Cron で再生成するため、即時反映ではない旨を補足する。
			$cnv_saved_message .= ' ' . esc_html__( 'AI指示文への反映は翌日以降になります。', 'qa-heatmap-analytics' );
		}
		$localize['cnv_saved_1'] = $cnv_saved_message;
		/* translators: placeholders are for a goal ID */
		$localize['cnv_deleted']              = esc_html__( 'Goal %d deleted.', 'qa-heatmap-analytics' );
		$localize['cnv_deleted2']             = esc_html__( 'Press OK to reload the page.', 'qa-heatmap-analytics' );
		$localize['cnv_reaching_goal_notice'] = esc_attr__( 'There are goals that have been achieved in the past 30 days.', 'qa-heatmap-analytics' );
		$localize['cnv_saving']               = esc_attr__( 'Saving...', 'qa-heatmap-analytics' );
		$localize['cnv_load_page']            = esc_html__( 'Load the Page', 'qa-heatmap-analytics' );
		$localize['cnv_loading']              = esc_html__( 'Loading...', 'qa-heatmap-analytics' );
		$localize['cnv_in_progress']          = esc_html__( 'Generating goal data for the past 30 days.', 'qa-heatmap-analytics' );
		$localize['cnv_estimated_time']       = esc_html__( '(Estimated time) About', 'qa-heatmap-analytics' );
		$localize['x_minutes']                = esc_html__( 'minutes', 'qa-heatmap-analytics' );
		$localize['x_seconds']                = esc_html__( 'seconds', 'qa-heatmap-analytics' );
		$localize['cnv_estimated_time2']      = esc_html__( 'The report may take a few minutes to update after processing is complete.', 'qa-heatmap-analytics' );
		$localize['cnv_save_failed']          = esc_html__( 'Failed to save the goal.', 'qa-heatmap-analytics' );
		$localize['nothing_page_id']          = esc_html__( 'Sorry, a post or page that is either newly created or never visited cannot be set as a goal. Please allow at least one day.', 'qa-heatmap-analytics' );
		$localize['nothing_page_id2']         = esc_html__( 'Or, please ensure the URL belongs to this WordPress site.', 'qa-heatmap-analytics' );
		$localize['wrong_regex_delimiter']    = esc_html__( 'The pattern does not have a valid starting or ending delimiter.', 'qa-heatmap-analytics' );
		$localize['no_pvterm']                = esc_html__( 'Analytics data may not yet be available. Please wait a few days or review your settings.', 'qa-heatmap-analytics' );
		$localize['failed_iframe_load']       = esc_html__( 'Failed to load the page. Please check the URL.', 'qa-heatmap-analytics' );
		$localize['mail_btn_updating']        = esc_html__( 'Updating...', 'qa-heatmap-analytics' );
		$localize['mail_alert_update_failed'] = esc_html__( 'Failed updating. Please retry again.', 'qa-heatmap-analytics' );
		$localize['please_try_again']         = esc_html__( 'Please try again.', 'qa-heatmap-analytics' );

		// プラグイン設定用のローカライズテキスト
		$localize['data_save_month_title']   = esc_html__( 'Data Storage Period', 'qa-heatmap-analytics' );
		$localize['setting_option_saved']    = esc_html__( 'Plugin options saved successfully.', 'qa-heatmap-analytics' );
		$localize['setting_option_failed']   = esc_html__( 'Failed saving plugin options.', 'qa-heatmap-analytics' );
		$localize['alert_message_success']   = esc_html__( 'Success', 'qa-heatmap-analytics' );
		$localize['alert_message_failed']    = esc_html__( 'Failed to update settings', 'qa-heatmap-analytics' );
		$localize['nonce_qahm_options']      = wp_create_nonce( 'qahm-config-nonce-action-qahm-options' );
		$localize['measurement_saved']       = esc_html__( 'Measurement settings saved.', 'qa-heatmap-analytics' );
		$localize['measurement_save_failed'] = esc_html__( 'Failed to save measurement settings.', 'qa-heatmap-analytics' );
		$localize['measurement_invalid_ip']  = esc_html__( 'Invalid IP address found. Please check the input.', 'qa-heatmap-analytics' );

		wp_localize_script( QAHM_NAME . '-common', QAHM_NAME . 'l10n', $localize );

		$this->localize_ary = $localize;
	}

	/**
	 * ページの表示
	 */
	public function create_html() {
		if ( $this->is_redirect() ) {
			return;
		}

		if ( ! $this->is_enqueue_jquery() ) {
			$this->print_not_enqueue_jquery_html();
			return;
		}

		if ( $this->is_maintenance() ) {
			$this->print_maintenance_html();
			return;
		}

		// データを取得
		global $qahm_data_api;
		global $qahm_google_api;

		$GOALMAX = QAHM_CONFIG_GOALMAX;

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended
		$tracking_id_raw = isset( $_GET['tracking_id'] ) ? $this->sanitize_tracking_id( wp_unslash( $_GET['tracking_id'] ) ) : 'all';
		$tracking_id     = $this->get_safe_tracking_id( $tracking_id_raw );

		// ALL選択時は個別のtracking_id選択を促す
		if ( $tracking_id === 'all' ) {
			?>
			<div id="<?php echo esc_attr( basename( __FILE__, '.php' ) ); ?>" class="qahm-admin-page">
				<div class="qa-zero-content">
					<?php $this->create_header( __( 'Settings', 'qa-heatmap-analytics' ) ); ?>
					<?php $this->print_select_tracking_id_message( __( 'Settings', 'qa-heatmap-analytics' ) ); ?>
				</div>
			</div>
			<?php
			return;
		}

		$siteinfo_ary = $qahm_data_api->get_siteinfo_preferences( $tracking_id );

		$goals_ary = $qahm_data_api->get_goals_preferences( $tracking_id );

		// 計測設定タブ用: sitemanage データを取得（QA ZERO のみ）
		$current_site_data = null;
		if ( QAHM_TYPE === QAHM_TYPE_ZERO ) {
			$sitemanage_datas = $qahm_data_api->get_sitemanage();
			if ( $sitemanage_datas ) {
				foreach ( $sitemanage_datas as $site ) {
					if ( $site['tracking_id'] === $tracking_id ) {
						$current_site_data = $site;
						break;
					}
				}
			}
		}

		$sitetype_ary = array(
			'general_company',
			'media_affiliate',
			'service_matching',
			'ec_ec',
			'general_shop',
			'media_owned',
			'service_ugc',
			'ec_contents',
			'general_ir',
			'media_other',
			'service_membershi',
			'ec_license',
			'general_recruit',
			'service_other',
			'ec_other',
		);

		$lang_ja['target_user_question'] = esc_html__( 'Which type of users do you want meet the goal?', 'qa-heatmap-analytics' );
		$lang_ja['target_individual']    = esc_html__( 'Personal', 'qa-heatmap-analytics' );
		$lang_ja['target_corporation']   = esc_html__( 'Corporations/Organizations', 'qa-heatmap-analytics' );

		$lang_ja['select_sitetype_question'] = esc_html__( 'Choose a category that describes your site best.', 'qa-heatmap-analytics' );
		$lang_ja['general']                  = esc_html__( 'General', 'qa-heatmap-analytics' );
		$lang_ja['media']                    = esc_html__( 'Media', 'qa-heatmap-analytics' );
		$lang_ja['service']                  = esc_html__( 'Providing services', 'qa-heatmap-analytics' );
		$lang_ja['ec_mall']                  = esc_html__( 'EC/Mall', 'qa-heatmap-analytics' );

		$lang_ja['general_company']   = esc_html__( 'About a company/services', 'qa-heatmap-analytics' );
		$lang_ja['media_affiliate']   = esc_html__( 'Affiliate blogs/Media', 'qa-heatmap-analytics' );
		$lang_ja['service_matching']  = esc_html__( 'Matching', 'qa-heatmap-analytics' );
		$lang_ja['ec_ec']             = esc_html__( 'Product sales', 'qa-heatmap-analytics' );
		$lang_ja['general_shop']      = esc_html__( 'About stores/facilities', 'qa-heatmap-analytics' );
		$lang_ja['media_owned']       = esc_html__( 'Owned media', 'qa-heatmap-analytics' );
		$lang_ja['service_ugc']       = esc_html__( 'Posting', 'qa-heatmap-analytics' );
		$lang_ja['ec_contents']       = esc_html__( 'Online content sales', 'qa-heatmap-analytics' );
		$lang_ja['general_ir']        = esc_html__( 'IR', 'qa-heatmap-analytics' );
		$lang_ja['media_other']       = esc_html__( 'Other information dissemination', 'qa-heatmap-analytics' );
		$lang_ja['service_membershi'] = esc_html__( 'SNS/Member services', 'qa-heatmap-analytics' );
		$lang_ja['ec_license']        = esc_html__( 'License sales', 'qa-heatmap-analytics' );
		$lang_ja['general_recruit']   = esc_html__( 'Recruitment', 'qa-heatmap-analytics' );
		$lang_ja['service_other']     = esc_html__( 'Other services', 'qa-heatmap-analytics' );
		$lang_ja['ec_other']          = esc_html__( 'Other sales', 'qa-heatmap-analytics' );

		$lang_ja['membership_question']          = esc_html__( 'Does the site have "member registration"?', 'qa-heatmap-analytics' );
		$lang_ja['payment_question']             = esc_html__( 'Does the site have "payment function"?', 'qa-heatmap-analytics' );
		$lang_ja['goal_monthly_access_question'] = esc_html__( 'Enter the target number for monthly sessions.', 'qa-heatmap-analytics' );

		$lang_ja['membership_yes'] = esc_html__( 'Yes.', 'qa-heatmap-analytics' );
		$lang_ja['membership_no']  = esc_html__( 'No.', 'qa-heatmap-analytics' );
		$lang_ja['next']           = esc_html__( 'Next', 'qa-heatmap-analytics' );
		$lang_ja['save']           = esc_html__( 'Save', 'qa-heatmap-analytics' );

		$lang_ja['payment_no']   = esc_html__( 'No.', 'qa-heatmap-analytics' );
		$lang_ja['payment_yes']  = esc_html__( 'Yes, using original system.', 'qa-heatmap-analytics' );
		$lang_ja['payment_cart'] = esc_html__( 'Using external cart system.', 'qa-heatmap-analytics' );

		$lang_ja['month_later']  = esc_html__( 'month(s) later, reaching', 'qa-heatmap-analytics' );
		$lang_ja['session_goal'] = esc_html__( 'sessions/month is the goal.', 'qa-heatmap-analytics' );

		$goal_noun          = esc_html__( 'Goal', 'qa-heatmap-analytics' );
		$goal_title         = esc_html__( 'Goal Name', 'qa-heatmap-analytics' );
		$required           = esc_html_x( '*', 'A mark that indicates it is required item.', 'qa-heatmap-analytics' );
		$goal_number        = esc_html__( 'Completions Target in a Month', 'qa-heatmap-analytics' );
		$num_scale          = esc_html__( 'completion(s)', 'qa-heatmap-analytics' );
		$goal_value         = esc_html__( 'Goal Value (avg. monetary amount per goal)', 'qa-heatmap-analytics' );
		$val_scale          = esc_html_x( 'dollar(s)', 'Please put your currency. (This is only for a goal criterion.)', 'qa-heatmap-analytics' );
		$goal_sales         = esc_html__( 'Estimated Total Goal Value', 'qa-heatmap-analytics' );
		$goal_type          = esc_html__( 'Goal Type', 'qa-heatmap-analytics' );
		$goal_type_page     = esc_html__( 'Destination', 'qa-heatmap-analytics' );
		$goal_type_click    = esc_html__( 'Click', 'qa-heatmap-analytics' );
		$goal_type_event    = esc_html__( 'Event (Advanced)', 'qa-heatmap-analytics' );
		// #1345: dataLayer の値でゴール判定する新タイプ
		$goal_type_dlevent  = esc_html__( 'dataLayer value', 'qa-heatmap-analytics' );
		$dlevent_key        = esc_html__( 'dataLayer key to match', 'qa-heatmap-analytics' );
		$dlevent_values     = esc_html__( 'Values to match (one per line, exact match)', 'qa-heatmap-analytics' );
		$dlevent_note       = esc_html__( 'Requires "dataLayer import" to be ON for this site on the Issue Tag page. Only values recorded after it was turned ON can be counted.', 'qa-heatmap-analytics' );
		$goal_page          = esc_html__( 'Web page URL', 'qa-heatmap-analytics' );
		$click_page         = esc_html__( 'On which page?', 'qa-heatmap-analytics' );
		$eventtype          = esc_html__( 'Event Type', 'qa-heatmap-analytics' );
		$savegoal           = esc_attr__( 'Save', 'qa-heatmap-analytics' );
		$savesetting        = esc_attr__( 'Settings saved.', 'qa-heatmap-analytics' );
		$clickselector      = esc_html__( 'Click the object. (auto-fill)', 'qa-heatmap-analytics' );
		$eventselector      = esc_html__( 'Hyperlink Reference (Regular Expression with delimiter)', 'qa-heatmap-analytics' );
		$example            = esc_html__( 'Example:', 'qa-heatmap-analytics' );
		$pagematch_complete = esc_html__( 'Equals to', 'qa-heatmap-analytics' );
		$pagematch_prefix   = esc_html__( 'Begins with', 'qa-heatmap-analytics' );
		$click_sel_load     = esc_html__( 'Load the Page', 'qa-heatmap-analytics' );
		$click_sel_set      = esc_html__( 'Selector input completed.', 'qa-heatmap-analytics' );
		$unset_goal         = esc_html_x( 'Unset', 'unset a goal', 'qa-heatmap-analytics' );

		//each event
		$event_click       = esc_html__( 'on click', 'qa-heatmap-analytics' );
		$event_value_click = 'onclick';

		// iframe
		$click_iframe_url = '';
		$sitemanage       = $qahm_data_api->get_sitemanage();
		if ( $sitemanage ) {
			foreach ( $sitemanage as $site ) {
				if ( $site['tracking_id'] === $tracking_id ) {
					$click_iframe_url = 'https://' . $site['url'];
					break;
				}
			}
		}

		//1st which panel will be oepn?
		$oepndetail = array_fill( 1, 2, '' );
		if ( isset( $siteinfo_ary['session_goal'] ) || isset( $siteinfo_ary['sitetype'] ) ) {
			$oepndetail[2] = 'open';
		} else {
			$oepndetail[1] = 'open';
		}

		//event measuring page
		$measuring_page_url = array();

		// Google API 認証情報
		$access_token = null;
		$credentials  = $qahm_google_api->get_credentials( $tracking_id );
		if ( $credentials && isset( $credentials['token'] ) && isset( $credentials['token']['access_token'] ) ) {
			$access_token = $credentials['token']['access_token'];
		}

		// GA4 プロパティ設定
		$ga4_settings_all       = get_option( 'qahm_ga4_settings', array() );
		$saved_ga4_property_id   = isset( $ga4_settings_all[ $tracking_id ]['ga4_property_id'] ) ? $ga4_settings_all[ $tracking_id ]['ga4_property_id'] : '';
		$saved_ga4_property_name = isset( $ga4_settings_all[ $tracking_id ]['ga4_property_name'] ) ? $ga4_settings_all[ $tracking_id ]['ga4_property_name'] : '';

		// デフォルトタブ決定（OAuth戻り・URLパラメータ・通知ありのときだけ tab_google を強制＝いずれも1回きり）
		if ( QAHM_TYPE === QAHM_TYPE_WP ) {
			$default_tab = 'tab_plugin';
		} else {
			$default_tab = 'tab_measurement';
		}
		// google_is_redirect の値はあとで announce 用に再読込されるので、ここでは消費しない
		$was_oauth_redirect = (bool) $this->wrap_get_option( 'google_is_redirect' );
		$tab_param          = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// OAuth 戻り（コールバックで復元した pending_tab／認証直後フラグ／?tab=tab_google／保存通知）は
		// WP・ZERO 共通で Google連携タブを開く。
		// ※ #1363 で QA Assistants(WP) の Google 連携を開放したが、このタブ復帰ロジックは従来
		//   全体が QAHM_TYPE_ZERO ゲートだったため WP では効かず、認証後に一般タブへ戻ってしまっていた。
		//   un-gate の残党としてここも WP に開く。
		$google_tab_return = (
			$this->pending_tab === 'tab_google' ||
			$was_oauth_redirect ||
			'tab_google' === $tab_param ||
			$this->ga4_notice !== null
		);
		// ※旧「連携済みだが GA4 未設定なら毎回 Google タブを開く」恒常ナッジは #1525 で撤去。
		//   連携直後に1回だけ戻す（上の $google_tab_return）意図に対し、GA4 を設定するまで
		//   設定画面が常に Google連携タブで開いてしまい、先頭タブ（計測）に到達しない体験になっていた。
		if ( $google_tab_return ) {
			$default_tab = 'tab_google';
		}

		?>

		<div id="<?php echo esc_attr( basename( __FILE__, '.php' ) ); ?>" class="qahm-admin-page">
			<div class="qa-zero-content">
				<?php $this->create_header( __( 'Settings', 'qa-heatmap-analytics' ) ); ?>

				<?php
				if ( $this->wrap_get_option( 'google_is_redirect' ) ) {
					// 認証直後に失敗したときだけ知らせる。成功は Google連携タブ見出しの「接続済み」バッジで示すため、
					// 「成功しました」帯は出さない（接続後に出っぱなしになる冗長表示を避ける）。
					if ( ! $qahm_google_api->is_auth() ) {
						$this->print_qa_announce_html( esc_html( __( 'Failed to connect with Google API.', 'qa-heatmap-analytics' ) ), 'error' );
					}
					$this->wrap_update_option( 'google_is_redirect', false );
				}

				$form_google_disabled = '';
				if ( $qahm_google_api->is_auth() ) {
					//  $form_google_disabled = ' disabled';
				}

				$err_ary = $qahm_google_api->test_search_console_connect();
				if ( $err_ary ) {
					// 認証（トークン取得）が成功していても、その Google アカウントが当該サイトの
					// Search Console プロパティに権限を持たない場合は test クエリが 403 等で失敗する。
					// 「認証失敗」と「認証成功・データアクセス権なし」は原因も対処も別なので、
					// is_auth() で文言と通知レベルを出し分けて、ユーザーが切り分けられるようにする。
					if ( $qahm_google_api->is_auth() ) {
						$err_text  = esc_html( __( 'Authentication succeeded, but this Google account does not have access to this site\'s Search Console data. Please confirm the site is registered as a property in Search Console and that this account has permission.', 'qa-heatmap-analytics' ) ) . '<br>';
						// 原因（このサイトの Search Console 権限なし）に直結する導線。汎用マニュアルより踏みやすい。
						$err_text .= '<a href="' . esc_url( 'https://search.google.com/search-console' ) . '" target="_blank" rel="noopener">' . esc_html( __( 'Open Search Console to add this site or check permissions', 'qa-heatmap-analytics' ) ) . '</a><br>';
						$err_status = 'warning';
					} else {
						$err_text = esc_html( __( 'Failed to connect with Google API.', 'qa-heatmap-analytics' ) ) . '<br>';
						$err_status = 'error';
					}
					$err_text .= '<br>';
					$err_text .= 'error code: ' . esc_html( $err_ary['code'] ) . '<br>';
					$err_text .= 'error message: ' . esc_html( $err_ary['message'] );
					$this->print_qa_announce_html( $err_text, $err_status );
				}
				?>
				<div class="qa-zero-data-container">
					<div class="qa-zero-data qa-zero-data--config">
						<div class="tabs">

					<div class="qa-zero-tab">
					<?php if ( QAHM_TYPE === QAHM_TYPE_WP ) : ?>
						<span class="qa-zero-tab__item<?php echo ( 'tab_plugin' === $default_tab ) ? ' qa-zero-tab__item--active' : ''; ?>" data-tab="tab_plugin_content"><span class="qa-zero-tab__icon"><i class="fas fa-cog"></i> </span><?php esc_html_e( 'General Settings', 'qa-heatmap-analytics' ); ?></span>
					<?php endif; ?>
					<?php if ( QAHM_TYPE === QAHM_TYPE_ZERO ) : ?>
					<span class="qa-zero-tab__item<?php echo ( 'tab_measurement' === $default_tab ) ? ' qa-zero-tab__item--active' : ''; ?>" data-tab="tab_measurement_content"><span class="qa-zero-tab__icon"><i class="fas fa-tachometer-alt"></i> </span><?php esc_html_e( 'Measurement', 'qa-heatmap-analytics' ); ?></span>
					<?php endif; ?>
					<span class="qa-zero-tab__item<?php echo ( 'tab_goal' === $default_tab ) ? ' qa-zero-tab__item--active' : ''; ?>" data-tab="tab_goal_content"><span class="qa-zero-tab__icon"><i class="fas fa-crosshairs"></i> </span><?php esc_html_e( 'Goals', 'qa-heatmap-analytics' ); ?></span>
					<?php if ( false ) : // サイトの属性を非表示 ?>
					<span class="qa-zero-tab__item" data-tab="tab_site_attr_content"><span class="qa-zero-tab__icon"><i class="far fa-address-card"></i> </span><?php esc_html_e( 'Site Profile', 'qa-heatmap-analytics' ); ?></span>
					<?php endif; ?>
					<span class="qa-zero-tab__item<?php echo ( 'tab_google' === $default_tab ) ? ' qa-zero-tab__item--active' : ''; ?>" data-tab="tab_google_content"><span class="qa-zero-tab__icon"><i class="fab fa-google"></i> </span><?php esc_html_e( 'Google Integration', 'qa-heatmap-analytics' ); ?></span>
					</div>

					<?php

					$advanced_mode = $this->wrap_get_option( 'advanced_mode' );
					if ( $advanced_mode == true ) {
						$advanced_mode = ' checked';
					} else {
						$advanced_mode = '';
					}

					$cb_sup_mode = $this->wrap_get_option( 'cb_sup_mode' );
					if ( $cb_sup_mode === 'yes' ) {
						$cb_sup_mode_checked = ' checked';
					} else {
						$cb_sup_mode_checked = '';
					}

					/** ----------------------------
					 * プラグイン設定
					 */
					if ( QAHM_TYPE === QAHM_TYPE_WP || QAHM_TYPE === QAHM_TYPE_ZERO ) {
						?>
						<div class="qahm-config__tab-content<?php echo esc_attr( ( 'tab_plugin' === $default_tab ) ? ' qahm-config__tab-content--active' : '' ); ?>" id="tab_plugin_content">
							<div class="qahm-config__content-width">
								<table class="form-table">
									<tbody>
										<tr>
											<th scope="row">
												<label for="advanced_mode"><?php esc_html_e( 'Advanced Mode', 'qa-heatmap-analytics' ); ?></label>
											</th>
											<td>
												<input type="checkbox" name="advanced_mode" id="advanced_mode"<?php echo esc_attr( $advanced_mode ); ?>>
												<p class="description">
													<?php
													echo esc_html__(
														'Advanced Mode enables access to detailed reports, including Audience, Acquisition, Behavior, and Goals. If you prefer a simpler interface, disable it to only see the essential metrics.',
														'qa-heatmap-analytics'
													);
													?>
												</p>
											</td>
										</tr>
										<tr>
											<th scope="row">
												<label for="cb_sup_mode"><?php esc_html_e( 'Enable Cookieless Tracking', 'qa-heatmap-analytics' ); ?></label>
											</th>
											<td>
												<input type="checkbox" name="cb_sup_mode" id="cb_sup_mode"<?php echo esc_attr( $cb_sup_mode_checked ); ?>>
												<p class="description">
													<?php echo esc_html__( 'This plugin uses cookieless tracking by default. If a cookie banner is present, it will respect the visitor\'s consent and adjust tracking behavior accordingly. Uncheck this if you want to always use cookies for tracking (not recommended).', 'qa-heatmap-analytics' ); ?>
												</p>
												<p class="description">
													<?php echo esc_html__( 'If you are using a cookie banner tool, you may need to configure it to work properly with cookieless tracking. For more details, visit our documentation site.', 'qa-heatmap-analytics' ); ?><br>
													<a href="<?php echo esc_url( QAHM_DOCUMENTATION_URL ); ?>" target="_blank" rel="noopener"><?php echo esc_html( QAHM_PLUGIN_NAME ); ?> Documentation</a>
												</p>
											</td>
										</tr>
									</tbody>
								</table>

								<p class="submit">
									<button name="plugin-submit" id="plugin-submit" class="qahm-btn qahm-btn--primary"><?php esc_html_e( 'Save', 'qa-heatmap-analytics' ); ?></button>
								</p>

								<?php if ( QAHM_TYPE === QAHM_TYPE_WP ) { ?>
								<hr>
								<?php
								$retention_days   = $this->get_data_retention_days();
								$monthly_pv_limit = QAHM_CONFIG_LIMIT_PV_MONTH;
								?>
								<div class="qahm-config-note">
									<h3><?php esc_html_e( 'Data retention & limits', 'qa-heatmap-analytics' ); ?></h3>

									<ul class="qahm-config-note__list">
										<li class="qahm-config-note__list-item">
										<?php esc_html_e( 'Data retention', 'qa-heatmap-analytics' ); ?>:
										<strong><?php echo esc_html( number_format_i18n( $retention_days ) ); ?></strong>
										<?php esc_html_e( 'days', 'qa-heatmap-analytics' ); ?>
										</li>
										<li class="qahm-config-note__list-item">
										<?php esc_html_e( 'Monthly PV limit', 'qa-heatmap-analytics' ); ?>:
										<strong><?php echo esc_html( number_format_i18n( $monthly_pv_limit ) ); ?></strong>
										</li>
									</ul>

									<p class="qahm-config-note__footer">
										<?php
										$text = sprintf(
											/* translators: 1: opening <code> tag, 2: closing </code> tag. Example output: Defined in <code>qa-config.php</code>. You can change them by editing this file. */
											__( 'Defined in %1$sqa-config.php%2$s. You can change them by editing this file.', 'qa-heatmap-analytics' ),
											'<code>',
											'</code>'
										);
										echo wp_kses( $text, array( 'code' => array() ) );
										?>
									<br>
									<a href="<?php echo esc_url( 'https://docs.quarka.org/docs/user-manual/getting-started/configure-qa-config/' ); ?>" target="_blank" rel="noopener" class="button button-link">
										<?php esc_html_e( 'How to configure qa-config.php (Documentation)', 'qa-heatmap-analytics' ); ?>
									</a>
									</p>
								</div>

								<?php } ?>

							</div>
						</div>
					<?php } ?>

		<?php
		/** ----------------------------
		 * "Goal"
		 */
		?>
					<div class="qahm-config__tab-content<?php echo esc_attr( ( 'tab_goal' === $default_tab ) ? ' qahm-config__tab-content--active' : '' ); ?>" id="tab_goal_content">
						<div class="qahm-config__tab-content-description">
							<p class="qahm-config__tab-description"><?php esc_html_e( 'Set goals to understand key actions on your site.', 'qa-heatmap-analytics' ); ?><br>
							<?php if ( QAHM_TYPE === QAHM_TYPE_WP ) { ?>
							<strong><?php esc_html_e( 'Basic goal metrics are shown in the Audience Report. To view detailed goal reports, please enable Advanced Mode.', 'qa-heatmap-analytics' ); ?></strong><br>
							<?php } ?>
							<?php esc_html_e( 'You can update your goals at any time. Goal completions are calculated using all available data, including data collected before the goal was added or updated.', 'qa-heatmap-analytics' ); ?></p>
							  
							<div id="step2">

							<?php
							$gtype_iframe_display = array_fill( 1, $GOALMAX, 'display: none' );
							for ( $iii = 1; $iii <= $GOALMAX; $iii++ ) {
								$gtitle          = isset( $goals_ary[ $iii ]['gtitle'] ) ? esc_html( urldecode( $goals_ary[ $iii ]['gtitle'] ) ) : '';
								$gnum_scale      = isset( $goals_ary[ $iii ]['gnum_scale'] ) ? esc_attr( urldecode( $goals_ary[ $iii ]['gnum_scale'] ) ) : 0;
								$gnum_value      = isset( $goals_ary[ $iii ]['gnum_value'] ) ? esc_attr( urldecode( $goals_ary[ $iii ]['gnum_value'] ) ) : 0;
								$gtype           = isset( $goals_ary[ $iii ]['gtype'] ) ? esc_attr( urldecode( $goals_ary[ $iii ]['gtype'] ) ) : 'gtype_page';
								$g_goalpage      = isset( $goals_ary[ $iii ]['g_goalpage'] ) ? esc_url( urldecode( $goals_ary[ $iii ]['g_goalpage'] ) ) : '';
								$g_pagematch     = isset( $goals_ary[ $iii ]['g_pagematch'] ) ? esc_attr( urldecode( $goals_ary[ $iii ]['g_pagematch'] ) ) : '';
								$g_clickpage     = isset( $goals_ary[ $iii ]['g_clickpage'] ) ? esc_url( urldecode( $goals_ary[ $iii ]['g_clickpage'] ) ) : '';
								$g_eventtype     = isset( $goals_ary[ $iii ]['g_eventtype'] ) ? esc_attr( urldecode( $goals_ary[ $iii ]['g_eventtype'] ) ) : '';
								$g_clickselector = isset( $goals_ary[ $iii ]['g_clickselector'] ) ? esc_attr( urldecode( $goals_ary[ $iii ]['g_clickselector'] ) ) : '';
								$g_eventselector = isset( $goals_ary[ $iii ]['g_eventselector'] ) ? esc_attr( urldecode( $goals_ary[ $iii ]['g_eventselector'] ) ) : '';
								// #1345: dataLayer ゴール。値は複数行のため textarea に出す（esc_attr ではなく esc_textarea）
								// ★ urldecode しない＝保存側（JS も validate_goal も）エンコードしていないため。
								//    ここでだけ復号すると、%XX を含む値が再保存で書き換わり照合が静かに外れる。
								$g_dlkey    = isset( $goals_ary[ $iii ]['g_dlkey'] ) && '' !== $goals_ary[ $iii ]['g_dlkey'] ? esc_attr( $goals_ary[ $iii ]['g_dlkey'] ) : QAHM_DLEVENT_DEFAULT_KEY;
								$g_dlvalues = isset( $goals_ary[ $iii ]['g_dlvalues'] ) ? $goals_ary[ $iii ]['g_dlvalues'] : '';

								// index 3 = gtype_dlevent（#1345 で追加）
								$gtype_checked     = array_fill( 0, 4, '' );
								$gtype_required    = array_fill( 0, 4, '' );
								$pagematch_checked = array_fill( 0, 2, '' );
								//$gtype_display = array_fill(0, 3, 'style="display: none"');
								$gtype_display = array_fill( 0, 4, 'display: none' );

								if ( ! $g_clickpage ) {
									$g_clickpage = esc_url( $click_iframe_url );
								}

								switch ( $gtype ) {
									case 'gtype_click':
										$gtype_checked[1]             = 'checked';
										$gtype_required[1]            = 'required';
										$gtype_iframe_display[ $iii ] = '';
										$gtype_display[1]             = '';
										break;
									case 'gtype_event':
										$gtype_checked[2]  = 'checked';
										$gtype_required[2] = 'required';
										$gtype_display[2]  = '';
										break;
									case 'gtype_dlevent':
										$gtype_checked[3]  = 'checked';
										$gtype_required[3] = 'required';
										$gtype_display[3]  = '';
										break;
									default:
									case 'gtype_page':
										$gtype_checked[0]  = 'checked';
										$gtype_required[0] = 'required';
										$gtype_display[0]  = '';
										break;
								}

								switch ( $g_pagematch ) {
									case 'pagematch_prefix':
										$pagematch_checked[1] = 'checked';
										break;
									default:
									case 'pagematch_complete':
										$pagematch_checked[0] = 'checked';
										break;
								}
								?>
							<div class="qahm-config__goal-box" id="<?php echo esc_attr( 'g' . $iii . '_goalbox' ); ?>">
								<h3><?php echo esc_html( $goal_noun . $iii ); ?></h3>
								<form id="<?php echo esc_attr( 'g' . $iii . '_form' ); ?>" onsubmit="saveChanges(this);return false">
								<table>
									<colgroup>
										<col class="qahm-config__goal-col-label">
										<col class="qahm-config__goal-col-input">
									</colgroup>
									<tbody>
									<tr>
										<td><?php echo esc_html( $goal_title ); ?><span class="el_attention">*</span></td>
										<td><input type="text" name="<?php echo esc_attr( 'g' . $iii . '_title' ); ?>" id="<?php echo esc_attr( 'g' . $iii . '_title' ); ?>" required value="<?php echo esc_attr( $gtitle ); ?>" size="30"></td>
									</tr>
									<tr>
										<td><?php echo esc_html( $goal_number ); ?></td>
										<td><input type="number" name="<?php echo esc_attr( 'g' . $iii . '_num' ); ?>" id="<?php echo esc_attr( 'g' . $iii . '_num' ); ?>" value="<?php echo esc_attr( $gnum_scale ); ?>" onchange="calcSales(this)"><?php echo esc_html( $num_scale ); ?></td>
									</tr>
									<tr>
										<td><?php echo esc_html( $goal_value ); ?></td>
										<td><input type="number" name="<?php echo esc_attr( 'g' . $iii . '_val' ); ?>" id="<?php echo esc_attr( 'g' . $iii . '_val' ); ?>" value="<?php echo esc_attr( $gnum_value ); ?>" onchange="calcSales(this)"><?php echo esc_html( $val_scale ); ?>&nbsp;<p class="right"><?php echo esc_html( $goal_sales ); ?> = <span id="<?php echo esc_attr( 'g' . $iii . '_calcsales' ); ?>">0</span> <?php echo esc_html( $val_scale ); ?></p></td>
									</tr>
									<tr>
										<td><?php echo esc_html( $goal_type ); ?><span class="el_attention">*</span>&nbsp;<span class="el_loading">Loading<span></span></span></td>
										<td class="td_gtype_save">
											<input type="radio" name="<?php echo esc_attr( 'g' . $iii . '_type' ); ?>" id="<?php echo esc_attr( 'g' . $iii . '_type_page' ); ?>" value="gtype_page" <?php echo esc_attr( $gtype_checked[0] ); ?>><label for="<?php echo esc_attr( 'g' . $iii . '_type_page' ); ?>"><?php echo esc_html( $goal_type_page ); ?></label>
											<input type="radio" name="<?php echo esc_attr( 'g' . $iii . '_type' ); ?>" id="<?php echo esc_attr( 'g' . $iii . '_type_click' ); ?>" value="gtype_click" <?php echo esc_attr( $gtype_checked[1] ); ?>><label for="<?php echo esc_attr( 'g' . $iii . '_type_click' ); ?>"><?php echo esc_html( $goal_type_click ); ?></label>&nbsp;
											<span class="qahm-config__event-type-hidden"><input type="radio" name="<?php echo esc_attr( 'g' . $iii . '_type' ); ?>" id="<?php echo esc_attr( 'g' . $iii . '_type_event' ); ?>" value="gtype_event" <?php echo esc_attr( $gtype_checked[2] ); ?>><label for="<?php echo esc_attr( 'g' . $iii . '_type_event' ); ?>"><?php echo esc_html( $goal_type_event ); ?></label></span>&nbsp;
											<?php
											// Issue #1617: 「dataLayer の値」は QA ZERO 専用のゴールタイプ。
											// QA Assistants には dataLayer を取り込む経路が無い（qtag.php:56 の QAHM_TYPE_WP 分岐は
											// 静的 qtag.js を返して終わり、取り込みの栓 qahmz.dli を定義するのは同 :101 ＝ ZERO 側だけ）。
											// このため QA Assistants でこのタイプを選ぶと達成が永遠に 0 になる ＝ 選ばせない。
											// ★ ラジオ要素そのものを出力しないこと。保存済みゴールの gtype が gtype_dlevent の環境では
											//    ラジオグループが未選択になり、js/admin-page-config.js の RadioNodeList .value が空文字になる。
											//    空文字は class-qahm-data-api.php の validate_goal の switch で default（required_null）へ落ち、
											//    そのゴールが保存できなくなる。gtype_event と同じ「display:none の span で包む」形なら
											//    checked が保たれるため、この経路を踏まない。
											// ★ hidden 属性とクラスを両方付けるのは二重の保険。QA Assistants は Version ヘッダに
											//    β を書かない運用（products.md の明文の例外）のため、β1→β2→β3→GA のあいだ
											//    QAHM_PLUGIN_VERSION は 5.3.0.0 のまま＝CSS の ?ver= が変わらず、β2 でこの画面を
											//    開いた人のブラウザは古い admin-page-config-wp.css を使い続ける（gotcha #32）。
											//    その場合クラスだけでは効かないので、UA 既定の [hidden]{display:none} で隠す。
											//    逆に author 規則が [hidden] を打ち消した場合はクラス側が効く。
											$is_dlevent_hidden = ( QAHM_TYPE === QAHM_TYPE_WP );
											?>
											<?php if ( $is_dlevent_hidden ) : ?><span class="qahm-config__goal-type-hidden" hidden><?php endif; ?><input type="radio" name="<?php echo esc_attr( 'g' . $iii . '_type' ); ?>" id="<?php echo esc_attr( 'g' . $iii . '_type_dlevent' ); ?>" value="gtype_dlevent" <?php echo esc_attr( $gtype_checked[3] ); ?>><label for="<?php echo esc_attr( 'g' . $iii . '_type_dlevent' ); ?>"><?php echo esc_html( $goal_type_dlevent ); ?></label><?php if ( $is_dlevent_hidden ) : ?></span><?php endif; ?>&nbsp;
											<br>
											<div id="<?php echo esc_attr( 'g' . $iii . '_page_goal' ); ?>" style="<?php echo esc_attr( $gtype_display[0] ); ?>" class="qahm-config__goal-type-box">
												<label><?php echo esc_html( $goal_page ); ?></label><br>
												<input type="radio" name="<?php echo esc_attr( 'g' . $iii . '_pagematch' ); ?>" id="<?php echo esc_attr( 'g' . $iii . '_pagematch_prefix' ); ?>" value="pagematch_prefix" <?php echo esc_attr( $pagematch_checked[1] ); ?>><label for="<?php echo esc_attr( 'g' . $iii . '_pagematch_prefix' ); ?>"><?php echo esc_html( $pagematch_prefix ); ?></label>
												<input type="radio" name="<?php echo esc_attr( 'g' . $iii . '_pagematch' ); ?>" id="<?php echo esc_attr( 'g' . $iii . '_pagematch_complete' ); ?>" value="pagematch_complete" <?php echo esc_attr( $pagematch_checked[0] ); ?>><label for="<?php echo esc_attr( 'g' . $iii . '_pagematch_complete' ); ?>"><?php echo esc_html( $pagematch_complete ); ?></label><br>
												<input type="text" name="<?php echo esc_attr( 'g' . $iii . '_goalpage' ); ?>" id="<?php echo esc_attr( 'g' . $iii . '_goalpage' ); ?>" value="<?php echo esc_attr( $g_goalpage ); ?>" <?php echo esc_attr( $gtype_required[0] ); ?> size="60">
												&nbsp;
											</div>
											<div id="<?php echo esc_attr( 'g' . $iii . '_click_goal' ); ?>" style="<?php echo esc_attr( $gtype_display[1] ); ?>" class="qahm-config__goal-type-box">
												<label><?php echo esc_html( $click_page ); ?></label>
												<div class="qahm-config__goal-url-row">
													<input type="text" name="<?php echo esc_attr( 'g' . $iii . '_clickpage' ); ?>" id="<?php echo esc_attr( 'g' . $iii . '_clickpage' ); ?>" value="<?php echo esc_url( $g_clickpage ); ?>" <?php echo esc_attr( $gtype_required[1] ); ?> placeholder="<?php echo esc_url( $click_iframe_url ); ?>">
													<button id="<?php echo esc_attr( 'g' . $iii . '_click_pageload' ); ?>" class="qahm-btn qahm-btn--secondary" type="button"><?php echo esc_html( $click_sel_load ); ?></button>
												</div>
												<label><?php echo esc_html( $clickselector ); ?></label>
												<input type="text" name="<?php echo esc_attr( 'g' . $iii . '_clickselector' ); ?>" id="<?php echo esc_attr( 'g' . $iii . '_clickselector' ); ?>" disabled value="<?php echo esc_attr( $g_clickselector ); ?>" <?php echo esc_attr( $gtype_required[1] ); ?>>
												<div id="<?php echo esc_attr( 'g' . $iii . '_event-iframe-tooltip-right' ); ?>" class="qahm-config__event-tooltip--right"><?php echo esc_html( $click_sel_set ); ?></div>
											</div>
											<div id="<?php echo esc_attr( 'g' . $iii . '_event_goal' ); ?>" style="<?php echo esc_attr( $gtype_display[2] ); ?>;  display:none;" class="qahm-config__goal-type-box">
												<label><?php echo esc_html( $eventtype ); ?></label><select name="<?php echo esc_attr( 'g' . $iii . '_eventtype' ); ?>" id="<?php echo esc_attr( 'g' . $iii . '_eventtype' ); ?>"><option value="onclick"><?php echo esc_html( $event_click ); ?></option></select> <br><br>
												<label><?php echo esc_html( $eventselector ); ?></label><br><input type="text" name="<?php echo esc_attr( 'g' . $iii . '_eventselector' ); ?>" id="<?php echo esc_attr( 'g' . $iii . '_eventselector' ); ?>" value="<?php echo esc_attr( $g_eventselector ); ?>" <?php echo esc_attr( $gtype_required[2] ); ?> size="80">
												<div class="qahm-config__event-example"><p><?php echo esc_html( $example ); ?><br>/.*ad-link.*/<br>/\/my-goal-link\//</p></div>
											</div>
											<?php
											// Issue #1617: QA Assistants では入力ボックスも隠す（ラジオと同じ扱い）。
											// 理由＝(a) 同ファイルの gtype_event が既に箱も display:none で隠している（上の event_goal）
											//         ＝「ラジオも箱も隠す」がこのファイルの既存の作法。
											//       (b) 隠さないと、保存済みゴールがある環境で案内文
											//         「Issue Tag ページで dataLayer 取り込みを ON に」が残るが、
											//         QA Assistants のタグ発行ページに datalayer_import は存在しない
											//         ＝利用者に実行不可能な操作を指示することになる。
											//       (c) 注意文で代替する案は、.mo/.po がビルド除外
											//         （build/exclude-qa-heatmap-analytics.txt）のため QA では英文のまま出る。
											// ★ inline style なので CSS の読み込みに依存しない（ラジオ側の hidden と同じ理由＝
											//    QA Assistants は Version ヘッダに β を書かず ?ver= が据え置かれるため）。
											// ★ 表示だけの変更＝保存経路には触れない。gtype は radio が checked のまま送られる。
											$dlevent_box_style = $gtype_display[3] . ( $is_dlevent_hidden ? ';  display:none;' : '' );
											?>
											<div id="<?php echo esc_attr( 'g' . $iii . '_dlevent_goal' ); ?>" style="<?php echo esc_attr( $dlevent_box_style ); ?>" class="qahm-config__goal-type-box">
												<label for="<?php echo esc_attr( 'g' . $iii . '_dlkey' ); ?>"><?php echo esc_html( $dlevent_key ); ?></label><br>
												<input type="text" name="<?php echo esc_attr( 'g' . $iii . '_dlkey' ); ?>" id="<?php echo esc_attr( 'g' . $iii . '_dlkey' ); ?>" value="<?php echo esc_attr( $g_dlkey ); ?>" size="30">
												<br><br>
												<label for="<?php echo esc_attr( 'g' . $iii . '_dlvalues' ); ?>"><?php echo esc_html( $dlevent_values ); ?><span class="el_attention">*</span></label><br>
												<textarea name="<?php echo esc_attr( 'g' . $iii . '_dlvalues' ); ?>" id="<?php echo esc_attr( 'g' . $iii . '_dlvalues' ); ?>" rows="5" cols="80" <?php echo esc_attr( $gtype_required[3] ); ?>><?php echo esc_textarea( $g_dlvalues ); ?></textarea>
												<div class="qahm-config__event-example"><p><?php echo esc_html( $dlevent_note ); ?></p></div>
											</div>
										</td>
									</tr>
									</tbody>
								</table>
								<div class="qahm-config__goal-actions td_gtype_save">
									<input type="submit" name="submit" id="<?php echo esc_attr( 'g' . $iii . '_submit' ); ?>" value="<?php echo esc_html( $savegoal ); ?>" class="qahm-btn qahm-btn--primary">
									<a href="#<?php echo esc_attr( 'g' . $iii . '_goalbox' ); ?>" onclick="deleteGoalX(<?php echo esc_attr( $iii ); ?>)"><?php echo esc_html( $unset_goal ); ?></a>
								</div>
								<div id="<?php echo esc_attr( 'g' . $iii . '_event-iframe-containar' ); ?>" class="qahm-config__event-iframe-container" style="<?php echo esc_attr( $gtype_iframe_display[ $iii ] ); ?>">
									<div class="qahm-config__iframe-width-control">
										<label><?php esc_html_e( 'Preview width', 'qa-heatmap-analytics' ); ?>:
											<input type="range" class="qahm-config__iframe-width-slider" min="375" max="1400" value="1200" data-gid="<?php echo esc_attr( $iii ); ?>">
											<span class="qahm-config__iframe-width-value">1200px</span>
										</label>
									</div>
									<div class="qahm-config__iframe-scale-wrapper">
										<iframe id="<?php echo esc_attr( 'g' . $iii . '_event-iframe' ); ?>" class="event-iframe" src="" frameborder="0" width="1200" height="400" scrolling="yes"></iframe>
									</div>
								</div>
								</form>
							</div>

								<?php
							}  //end for
							?>


							</div>
						</div>
					</div><!-- endof #tab_goal_content -->



		<?php
		/** --------------------------------
		 * "Site Profile"
		 */
		?>
						<?php if ( false ) : // 非表示 ?>
						<div class="qahm-config__tab-content" id="tab_site_attr_content">
							<form id="siteinfo_form" onsubmit="siteinfoChanges(this);return false">

								<h3><?php echo esc_html( __( 'Which type of users do you want meet the goal?', 'qa-heatmap-analytics' ) ); ?></h3>
								<?php
								$target_options = array(
									'target_individual'  => __( 'Personal', 'qa-heatmap-analytics' ),
									'target_corporation' => __( 'Corporations/Organizations', 'qa-heatmap-analytics' ),
								);

								foreach ( $target_options as $key => $label ) {
									?>
									<input type="radio"
										name="target_customer"
										id="<?php echo esc_attr( $key ); ?>"
										value="<?php echo esc_attr( $key ); ?>"
										<?php checked( $siteinfo_ary['target_customer'] ?? '', $key ); ?> />

									<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
								<?php } ?>

								<h3><?php echo esc_html( __( 'Choose a category that describes your site best.', 'qa-heatmap-analytics' ) ); ?></h3>
								<table>
									<thead>
										<tr>
											<th><?php echo esc_html( __( 'General', 'qa-heatmap-analytics' ) ); ?></th>
											<th><?php echo esc_html( __( 'Media', 'qa-heatmap-analytics' ) ); ?></th>
											<th><?php echo esc_html( __( 'Providing services', 'qa-heatmap-analytics' ) ); ?></th>
											<th><?php echo esc_html( __( 'EC/Mall', 'qa-heatmap-analytics' ) ); ?></th>
										</tr>
									</thead>
									<tbody>
										<tr>
										<?php
										$sitetype_options = array(
											'general_company' => __( 'About a company/services', 'qa-heatmap-analytics' ),
											'media_affiliate' => __( 'Affiliate blogs/Media', 'qa-heatmap-analytics' ),
											'service_matching' => __( 'Matching', 'qa-heatmap-analytics' ),
											'ec_ec'        => __( 'Product sales', 'qa-heatmap-analytics' ),
											'general_shop' => __( 'About stores/facilities', 'qa-heatmap-analytics' ),
											'media_owned'  => __( 'Owned media', 'qa-heatmap-analytics' ),
											'service_ugc'  => __( 'Posting', 'qa-heatmap-analytics' ),
											'ec_contents'  => __( 'Online content sales', 'qa-heatmap-analytics' ),
											'general_ir'   => __( 'IR', 'qa-heatmap-analytics' ),
											'media_other'  => __( 'Other information dissemination', 'qa-heatmap-analytics' ),
											'service_membershi' => __( 'SNS/Member services', 'qa-heatmap-analytics' ),
											'ec_license'   => __( 'License sales', 'qa-heatmap-analytics' ),
											'general_recruit' => __( 'Recruitment', 'qa-heatmap-analytics' ),
											'service_other' => __( 'Other services', 'qa-heatmap-analytics' ),
											'ec_other'     => __( 'Other sales', 'qa-heatmap-analytics' ),
										);

										foreach ( $sitetype_options as $key => $label ) :
											?>
											<td>
												<input type="radio" 
													name="sitetype" 
													id="<?php echo esc_attr( $key ); ?>"
													value="<?php echo esc_attr( $key ); ?>"
													<?php checked( $siteinfo_ary['sitetype'] ?? '', $key ); ?> />
												<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
											</td>
											<?php
											$nowtd = array_search( $key, array_keys( $sitetype_options ), true ) + 1;
											if ( $nowtd === 14 ) {
												echo '<td>&nbsp;</td>' . PHP_EOL;
											}
											if ( $nowtd >= 14 ) {
												++$nowtd;
											}
											if ( $nowtd % 4 === 0 ) {
												echo '</tr>' . PHP_EOL;
												if ( $nowtd !== 16 ) {
													echo '<tr>' . PHP_EOL;
												}
											}
										endforeach;
										?>
									</tbody>
								</table>

								<h3><?php echo esc_html( __( 'Does the site have "member registration"?', 'qa-heatmap-analytics' ) ); ?></h3>
								<?php
								$membership_options = array(
									'membership_no'  => __( 'No.', 'qa-heatmap-analytics' ),
									'membership_yes' => __( 'Yes.', 'qa-heatmap-analytics' ),
								);
								foreach ( $membership_options as $key => $label ) :
									?>
									<input type="radio" 
										name="membership" 
										id="<?php echo esc_attr( $key ); ?>"
										value="<?php echo esc_attr( $key ); ?>"
										<?php checked( $siteinfo_ary['membership'] ?? '', $key ); ?> />
									<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
								<?php endforeach; ?>

								<h3><?php echo esc_html( __( 'Does the site have "payment function"?', 'qa-heatmap-analytics' ) ); ?></h3>
								<?php
								$payment_options = array(
									'payment_no'   => __( 'No.', 'qa-heatmap-analytics' ),
									'payment_yes'  => __( 'Yes, using original system.', 'qa-heatmap-analytics' ),
									'payment_cart' => __( 'Using external cart system.', 'qa-heatmap-analytics' ),
								);
								foreach ( $payment_options as $key => $label ) :
									?>
									<input type="radio" 
										name="payment" 
										id="<?php echo esc_attr( $key ); ?>"
										value="<?php echo esc_attr( $key ); ?>"
										<?php checked( $siteinfo_ary['payment'] ?? '', $key ); ?> />
									<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
								<?php endforeach; ?>

								<?php
								$month_later  = isset( $siteinfo_ary['month_later'] ) ? $siteinfo_ary['month_later'] : '';
								$session_goal = isset( $siteinfo_ary['session_goal'] ) ? $siteinfo_ary['session_goal'] : '';
								?>
								<h3><?php echo esc_html( __( 'Enter the target number for monthly sessions.', 'qa-heatmap-analytics' ) ); ?></h3>
								<input type="number" name="month_later" id="month_later" value="<?php echo esc_attr( $month_later ); ?>" />
								<label for="month_later"><?php echo esc_html( __( 'month(s) later, reaching', 'qa-heatmap-analytics' ) ); ?></label>&nbsp;
								<input type="number" name="session_goal" id="session_goal" value="<?php echo esc_attr( $session_goal ); ?>" />
								<label for="session_goal"><?php echo esc_html( __( 'sessions/month is the goal.', 'qa-heatmap-analytics' ) ); ?></label>

								<p>
									<input type="submit" value="<?php echo esc_attr( __( 'Save', 'qa-heatmap-analytics' ) ); ?>" class="qahm-btn qahm-btn--primary" />
								</p>
							</form>
						</div><!-- endof #tab_site_attr_content -->
						<?php endif; // end if false ?>



		<?php
		/** --------------------------------
		 * "Google API"
		 */
		?>
						<div class="qahm-config__tab-content<?php echo esc_attr( ( 'tab_google' === $default_tab ) ? ' qahm-config__tab-content--active' : '' ); ?>" id="tab_google_content">
							<p class="qahm-config__tab-description">
								<?php echo esc_html( __( 'API integration with Google allows you to retrieve data from Google Search Console and Google Analytics.', 'qa-heatmap-analytics' ) ); ?>
								<span class="qahm_hatena-mark"><i class="far fa-question-circle"></i></span>
								<a href="https://mem.quarka.org/manual/connect-to-gsc/" target="_blank" rel="noopener"><?php echo esc_html( __( 'How to connect with API', 'qa-heatmap-analytics' ) ); ?><span class="qahm_link-mark"><i class="fas fa-external-link-alt"></i></span></a>
							</p>
							<?php // 接続状態を一目で示すバッジ（成功帯を出しっぱなしにせず、接続有無だけ明示）。 ?>
							<p>
							<?php if ( $access_token ) : ?>
								<span class="qahm-config__status-pill qahm-config__status-pill--on">&#10003; <?php echo esc_html( __( 'Connected', 'qa-heatmap-analytics' ) ); ?></span>
							<?php else : ?>
								<span class="qahm-config__status-pill qahm-config__status-pill--off"><?php echo esc_html( __( 'Not connected', 'qa-heatmap-analytics' ) ); ?></span>
							<?php endif; ?>
							</p>
							<form method="post" action="">
								<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME, false ); ?>
								<input type="hidden" name="form_type" value="save_google_credentials">

								<table class="form-table">
									<tbody>
										<tr>
											<th scope="row">
												<label for="client_id">
													<?php echo esc_html( __( 'Client ID', 'qa-heatmap-analytics' ) ); ?>
												</label>
											</th>
											<td>
												<input name="client_id" type="text" id="client_id" value="<?php echo esc_attr( isset( $credentials['client_id'] ) ? $credentials['client_id'] : '' ); ?>" class="regular-text"<?php echo esc_attr( $form_google_disabled ); ?>>
											</td>
										</tr>

										<tr>
											<th scope="row">
												<label for="client_secret">
													<?php echo esc_html( __( 'Client Secret', 'qa-heatmap-analytics' ) ); ?>
												</label>
											</th>
											<td>
												<?php
												// Client Secret は機密ゆえ実値を画面に出さない（DOM/ソースにも残さない）。
												// 保存済みなら「設定済み（マスク）＋変更ボタン」、未設定なら入力欄を直接表示する2状態。
												// 入力欄は常に DOM に置き（name=client_secret・value=""）、未変更なら空送信 → save 側で既存値を維持。
												if ( ! empty( $credentials['client_secret'] ) ) :
												?>
												<span id="client_secret_saved">
													<span style="font-family:monospace;letter-spacing:2px;color:#555">&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</span>
													<span style="color:#6b6862;font-size:12px">（<?php echo esc_html( __( 'Saved', 'qa-heatmap-analytics' ) ); ?>）</span>
													<button type="button" id="client_secret_change_btn" class="qahm-btn"><?php echo esc_html( __( 'Change', 'qa-heatmap-analytics' ) ); ?></button>
												</span>
												<span id="client_secret_input_wrap" style="display:none">
													<input name="client_secret" type="password" id="client_secret" value="" autocomplete="new-password" class="regular-text"<?php echo esc_attr( $form_google_disabled ); ?>>
													<span style="color:#6b6862;font-size:12px">（<?php echo esc_html( __( 'Enter a new value to change', 'qa-heatmap-analytics' ) ); ?>）</span>
												</span>
												<script>
												( function () {
													var btn   = document.getElementById( 'client_secret_change_btn' );
													var saved = document.getElementById( 'client_secret_saved' );
													var wrap  = document.getElementById( 'client_secret_input_wrap' );
													if ( ! btn || ! saved || ! wrap ) { return; }
													btn.addEventListener( 'click', function () {
														saved.style.display = 'none';
														wrap.style.display  = 'inline';
														var inp = document.getElementById( 'client_secret' );
														if ( inp ) { inp.focus(); }
													} );
												} )();
												</script>
												<?php else : ?>
												<input name="client_secret" type="password" id="client_secret" value="" autocomplete="new-password" class="regular-text"<?php echo esc_attr( $form_google_disabled ); ?>>
												<?php endif; ?>
												<?php
												if ( $form_google_disabled !== '' ) {
													echo '<span id="client_info_disabled_text" class="qahm-config__unlock-link">&nbsp;' . esc_html( __( 'Unlock the button\'s disabled', 'qa-heatmap-analytics' ) ) . '</span>';
												}
												?>
												</td>
										</tr>

										<tr>
											<th scope="row">
												<label for="redirect_uri">
													<?php echo esc_html( __( 'Redirect URI', 'qa-heatmap-analytics' ) ); ?>
												</label>
											</th>
											<td>
												<?php // 完全一致登録が必要なため、手選択ミスを避けて1クリックでコピーできるようにする。 ?>
												<input type="text" id="qahm_redirect_uri" class="regular-text" readonly value="<?php echo esc_url( admin_url( 'admin.php?page=qahm-config' ) ); ?>">
												<button type="button" id="qahm_copy_redirect_uri" class="qahm-btn"><?php esc_html_e( 'Copy', 'qa-heatmap-analytics' ); ?></button>
												<span id="qahm_redirect_uri_copied" class="qahm-config__copied-msg" style="display:none">&nbsp;<?php esc_html_e( 'Copied', 'qa-heatmap-analytics' ); ?></span>
											</td>
										</tr>

										<tr>
											<td colspan="2">
												<p class="submit">
													<input type="submit" name="submit" id="submit" class="qahm-btn qahm-btn--primary" value="<?php esc_attr_e( 'Authenticate', 'qa-heatmap-analytics' ); ?>">
												</p>
											</td>
										</tr>
										
									</tbody>
								</table>
							</form>
							<?php
							if ( $access_token ) {
								// 接続状態は見出し横の「接続済み」バッジで示すため、「認証完了…」の成功帯は出さない
								// （接続後に毎回出る冗長表示を撤去）。データ反映ラグの案内（info）だけ残す。
								// Search Console は接続直後にはデータが出ない（Google 側の集計反映に1〜2日）ため、
								// これが無いと「接続したのにデータが出ない＝壊れている」と誤解されやすい。
								$this->print_qa_announce_html(
									esc_html__( 'Search Console data appears about 1 to 2 days after connecting, due to Google\'s data processing. If the data is empty at first, please check again later.', 'qa-heatmap-analytics' ),
									'info'
								);
							}
							?>
							<script>
							( function () {
								var btn  = document.getElementById( 'qahm_copy_redirect_uri' );
								var src  = document.getElementById( 'qahm_redirect_uri' );
								var done = document.getElementById( 'qahm_redirect_uri_copied' );
								if ( ! btn || ! src ) { return; }
								btn.addEventListener( 'click', function () {
									src.focus();
									src.select();
									src.setSelectionRange( 0, 99999 );
									var ok = false;
									if ( navigator.clipboard && navigator.clipboard.writeText ) {
										navigator.clipboard.writeText( src.value );
										ok = true;
									} else {
										try { ok = document.execCommand( 'copy' ); } catch ( e ) { ok = false; }
									}
									if ( ok && done ) {
										done.style.display = 'inline';
										setTimeout( function () { done.style.display = 'none'; }, 2000 );
									}
								} );
							} )();
							</script>

<?php
/** --------------------------------
 * GA4 Property Selection
 *
 * 連携状態を「PHP宣言的な2状態描画」で出し分ける:
 *   - $saved_ga4_property_id があれば「連携済み」表示（disabled入力 + 再連携リンク）
 *   - 無ければ「未連携」表示（プロパティ取得ボタン + ドロップダウン + 手入力フォールバック）
 *
 * 通知は admin_init で $this->ga4_notice にセットされたものを print_qa_announce_html() で1経路に統一。
 */
?>
<?php if ( QAHM_TYPE !== QAHM_TYPE_WP ) : // GA4は丸山さん作成処理。QA Assistants(WP)では非表示（GSC連携のみ開放） ?>
<hr>
<h3><?php esc_html_e( 'GA4 Property Settings', 'qa-heatmap-analytics' ); ?></h3>
<p class="qahm-config__tab-description">
	<?php esc_html_e( 'Select the GA4 property to retrieve attribute data (age, gender, region).', 'qa-heatmap-analytics' ); ?>
	<br>
	<small><?php esc_html_e( 'Requires: GA4 Admin API and GA4 Data API must be enabled in Google Cloud Console.', 'qa-heatmap-analytics' ); ?></small>
</p>
<?php // 接続状態バッジ。GSC と同じピル表現で「プロパティ設定の有無」を一目で示す（見た目だけの統一・挙動は不変）。 ?>
<p>
<?php if ( $saved_ga4_property_id ) : ?>
	<span class="qahm-config__status-pill qahm-config__status-pill--on">&#10003; <?php echo esc_html( __( 'GA4 property connected', 'qa-heatmap-analytics' ) ); ?></span>
<?php else : ?>
	<span class="qahm-config__status-pill qahm-config__status-pill--off"><?php echo esc_html( __( 'No GA4 property set', 'qa-heatmap-analytics' ) ); ?></span>
<?php endif; ?>
</p>

<?php
if ( is_array( $this->ga4_notice ) && ! empty( $this->ga4_notice['message'] ) ) {
	$this->print_qa_announce_html(
		esc_html( $this->ga4_notice['message'] ),
		isset( $this->ga4_notice['status'] ) ? $this->ga4_notice['status'] : 'success'
	);
}
?>

<?php if ( $saved_ga4_property_id ) : ?>
	<?php
	$display_name = $saved_ga4_property_name !== ''
		? $saved_ga4_property_name
		: __( '(name unavailable — reconnect to fetch)', 'qa-heatmap-analytics' );
	?>
	<form method="post" id="ga4_settings_form">
		<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME, false ); ?>
		<input type="hidden" name="form_type" value="save_ga4_settings">
		<input type="hidden" id="ga4_property_name" name="ga4_property_name" value="<?php echo esc_attr( $saved_ga4_property_name ); ?>">
		<table class="form-table">
			<tbody>
				<tr>
					<th scope="row">
						<label><?php esc_html_e( 'Connected GA4 Property', 'qa-heatmap-analytics' ); ?></label>
					</th>
					<td>
						<p class="qahm-config__ga4-prop">
							<strong><?php echo esc_html( $saved_ga4_property_id ); ?></strong>
							&nbsp;—&nbsp;
							<span><?php echo esc_html( $display_name ); ?></span>
						</p>
						<input type="text" id="ga4_property_id_manual" name="ga4_property_id_manual" value="<?php echo esc_attr( $saved_ga4_property_id ); ?>" class="regular-text" disabled>
						<button type="button" id="ga4_reconnect_link" class="qahm-btn qahm-btn--secondary qahm-config__ga4-reconnect">
							<?php esc_html_e( 'Connect a different GA4 property', 'qa-heatmap-analytics' ); ?>
						</button>
					</td>
				</tr>
				<tr id="ga4_reconnect_picker" style="display: none;">
					<th scope="row"></th>
					<td>
						<button type="button" id="ga4_fetch_properties" class="qahm-btn qahm-btn--secondary">
							<?php esc_html_e( 'Select GA4 Property', 'qa-heatmap-analytics' ); ?>
						</button>
						<span id="ga4_fetch_status" class="qahm-config__ga4-status"></span>
						<br><br>
						<select id="ga4_property_select" name="ga4_property_id" class="qahm-config__ga4-select" style="display: none;">
							<option value=""><?php esc_html_e( '-- Select a property --', 'qa-heatmap-analytics' ); ?></option>
						</select>
					</td>
				</tr>
			</tbody>
		</table>
		<p class="submit">
			<input type="submit" class="qahm-btn qahm-btn--primary" value="<?php esc_attr_e( 'Save GA4 Settings', 'qa-heatmap-analytics' ); ?>" disabled id="ga4_save_btn">
		</p>
	</form>
<?php else : ?>
	<form method="post" id="ga4_settings_form">
		<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME, false ); ?>
		<input type="hidden" name="form_type" value="save_ga4_settings">
		<input type="hidden" id="ga4_property_name" name="ga4_property_name" value="">
		<table class="form-table">
			<tbody>
				<tr>
					<th scope="row">
						<label><?php esc_html_e( 'GA4 Property', 'qa-heatmap-analytics' ); ?></label>
					</th>
					<td>
						<button type="button" id="ga4_fetch_properties" class="qahm-btn qahm-btn--secondary">
							<?php esc_html_e( 'Select GA4 Property', 'qa-heatmap-analytics' ); ?>
						</button>
						<span id="ga4_fetch_status" class="qahm-config__ga4-status"></span>
						<br><br>
						<select id="ga4_property_select" name="ga4_property_id" class="qahm-config__ga4-select" style="display: none;">
							<option value=""><?php esc_html_e( '-- Select a property --', 'qa-heatmap-analytics' ); ?></option>
						</select>
						<br><br>
						<label for="ga4_property_id_manual"><?php esc_html_e( 'Or enter Property ID manually (fallback):', 'qa-heatmap-analytics' ); ?></label>
						<input type="text" id="ga4_property_id_manual" name="ga4_property_id_manual" value="" placeholder="e.g. 123456789" class="qahm-config__ga4-manual">
					</td>
				</tr>
			</tbody>
		</table>
		<p class="submit">
			<input type="submit" class="qahm-btn qahm-btn--primary" value="<?php esc_attr_e( 'Save GA4 Settings', 'qa-heatmap-analytics' ); ?>">
		</p>
	</form>
<?php endif; ?>

<script>
(function() {
	var fetchBtn      = document.getElementById('ga4_fetch_properties');
	var selectEl      = document.getElementById('ga4_property_select');
	var statusEl      = document.getElementById('ga4_fetch_status');
	var manualInput   = document.getElementById('ga4_property_id_manual');
	var nameInput     = document.getElementById('ga4_property_name');
	var reconnectBtn  = document.getElementById('ga4_reconnect_link');
	var reconnectRow  = document.getElementById('ga4_reconnect_picker');
	var saveBtn       = document.getElementById('ga4_save_btn');

	// 「別のGA4プロパティと連携する」: 入力欄の disabled を外して選択UIを開く
	if (reconnectBtn && reconnectRow) {
		reconnectBtn.addEventListener('click', function() {
			reconnectRow.style.display = '';
			if (manualInput) { manualInput.disabled = false; }
			if (saveBtn)     { saveBtn.disabled     = false; }
			// 再取得ボタンを連動押下するのは過剰なのでしない
		});
	}

	// ドロップダウン選択時: 手入力欄と hidden displayName を同期更新
	// 注: textContent は ID を括弧書きで含むため、表示用に保存するのは data-display-name
	if (selectEl) {
		selectEl.addEventListener('change', function() {
			var opt = selectEl.options[selectEl.selectedIndex];
			if (manualInput) { manualInput.value = selectEl.value || ''; }
			if (nameInput)   { nameInput.value   = opt ? (opt.dataset.displayName || '') : ''; }
		});
	}

	if (fetchBtn) {
		fetchBtn.addEventListener('click', function() {
			statusEl.textContent = '<?php echo esc_js( __( 'Loading...', 'qa-heatmap-analytics' ) ); ?>';
			var formData = new FormData();
			formData.append('action', 'qahm_get_ga4_properties');
			formData.append('nonce', '<?php echo esc_js( wp_create_nonce( self::NONCE_ACTION ) ); ?>');
			formData.append('tracking_id', '<?php echo esc_js( $tracking_id ); ?>');

			fetch(ajaxurl, { method: 'POST', body: formData })
				.then(function(r) { return r.json(); })
				.then(function(data) {
					if (data.success && data.data && data.data.accountSummaries) {
						selectEl.innerHTML = '<option value=""><?php echo esc_js( __( '-- Select a property --', 'qa-heatmap-analytics' ) ); ?></option>';
						data.data.accountSummaries.forEach(function(account) {
							if (account.propertySummaries) {
								account.propertySummaries.forEach(function(prop) {
									var propId = prop.property ? prop.property.replace('properties/', '') : '';
									var displayName = (account.displayName || '') + ' / ' + (prop.displayName || '');
									var opt = document.createElement('option');
									opt.value = propId;
									opt.dataset.displayName = displayName;
									opt.textContent = displayName + ' (' + propId + ')';
									selectEl.appendChild(opt);
								});
							}
						});
						selectEl.style.display = 'block';
						statusEl.textContent = '';
					} else {
						statusEl.textContent = data.data && data.data.message ? data.data.message : 'Error';
					}
				})
				.catch(function(e) {
					statusEl.textContent = 'Request failed: ' + e.message;
				});
		});
	}
})();
</script>
<?php endif; // GA4 非表示ゲート終端（QAHM_TYPE !== QAHM_TYPE_WP） ?>
						</div><!-- endof #tab_google_content -->

		<?php
		/** --------------------------------
		 * "計測設定" (Measurement) — QA ZERO only
		 */
		if ( QAHM_TYPE === QAHM_TYPE_ZERO && $current_site_data ) :
			?>
						<div class="qahm-config__tab-content<?php echo esc_attr( ( 'tab_measurement' === $default_tab ) ? ' qahm-config__tab-content--active' : '' ); ?>" id="tab_measurement_content">
							<div class="qahm-config__content-width">
								<p class="qahm-config__tab-description"><?php esc_html_e( 'このサイトの計測動作を設定します。URLパラメーター、IP除外、データ収集オプションを変更できます。', 'qa-heatmap-analytics' ); ?></p>
								<table class="form-table">
									<tbody>
										<tr>
											<th scope="row">
												<label for="measurement_ignore_params"><?php esc_html_e( '除外URLパラメーター', 'qa-heatmap-analytics' ); ?></label>
											</th>
											<td>
												<?php
												$ignore_params_display = '';
												if ( ! empty( $current_site_data['ignore_params'] ) ) {
													$ignore_params_display = str_replace( ',', "\n", $current_site_data['ignore_params'] );
												}
												?>
												<textarea id="measurement_ignore_params" rows="4" class="large-text"><?php echo esc_textarea( $ignore_params_display ); ?></textarea>
												<p class="description"><?php esc_html_e( '除外するURLパラメーター名を1行に1つずつ入力してください。', 'qa-heatmap-analytics' ); ?></p>
											</td>
										</tr>
										<tr>
											<th scope="row">
												<label for="measurement_search_params"><?php esc_html_e( '検索パラメーター', 'qa-heatmap-analytics' ); ?></label>
											</th>
											<td>
												<?php
												$search_params_display = '';
												if ( ! empty( $current_site_data['search_params'] ) ) {
													$search_params_display = str_replace( ',', "\n", $current_site_data['search_params'] );
												}
												?>
												<textarea id="measurement_search_params" rows="4" class="large-text"><?php echo esc_textarea( $search_params_display ); ?></textarea>
												<p class="description"><?php esc_html_e( 'サイト内検索に使用するパラメーター名を1行に1つずつ入力してください。', 'qa-heatmap-analytics' ); ?></p>
											</td>
										</tr>
										<tr>
											<th scope="row">
												<label for="measurement_ignore_ips"><?php esc_html_e( '計測しないIP', 'qa-heatmap-analytics' ); ?></label>
											</th>
											<td>
												<?php
												$ignore_ips_display = '';
												if ( ! empty( $current_site_data['ignore_ips'] ) ) {
													$ignore_ips_display = str_replace( ',', "\n", $current_site_data['ignore_ips'] );
												}
												?>
												<textarea id="measurement_ignore_ips" rows="4" class="large-text"><?php echo esc_textarea( $ignore_ips_display ); ?></textarea>
												<p class="description"><?php esc_html_e( '計測から除外するIPアドレスを1行に1つずつ入力してください。', 'qa-heatmap-analytics' ); ?></p>
											</td>
										</tr>
										<tr>
											<th scope="row">
												<?php esc_html_e( 'URL大文字小文字の区別', 'qa-heatmap-analytics' ); ?>
											</th>
											<td>
												<label>
													<input type="checkbox" id="measurement_url_case" value="1" <?php checked( (int) $current_site_data['url_case_sensitivity'], 1 ); ?>>
													<?php esc_html_e( '区別する', 'qa-heatmap-analytics' ); ?>
												</label>
												<p class="description"><?php esc_html_e( 'チェックを外すと、URLは小文字に統一して集計されます。', 'qa-heatmap-analytics' ); ?></p>
											</td>
										</tr>
										<tr>
											<th scope="row">
												<?php esc_html_e( 'HTML定期取得', 'qa-heatmap-analytics' ); ?>
											</th>
											<td>
												<label>
													<input type="checkbox" id="measurement_get_base_html_periodic" value="1" <?php checked( (int) ( $current_site_data['get_base_html_periodic'] ?? 0 ), 1 ); ?>>
													<?php esc_html_e( '定期的にHTMLを取得してページの差分を検知する', 'qa-heatmap-analytics' ); ?>
												</label>
												<p class="description"><?php esc_html_e( '有効にすると、ページに変更があった際にバージョンが自動更新されます。', 'qa-heatmap-analytics' ); ?></p>
											</td>
										</tr>
										<tr>
											<th scope="row">
												<label for="measurement_html_diff_detection_mode"><?php esc_html_e( '差分検知モード', 'qa-heatmap-analytics' ); ?></label>
											</th>
											<td>
												<?php
												$current_detection_mode = isset( $current_site_data['html_diff_detection_mode'] ) ? $current_site_data['html_diff_detection_mode'] : 'major_only';
												?>
												<select id="measurement_html_diff_detection_mode" <?php disabled( (int) ( $current_site_data['get_base_html_periodic'] ?? 0 ), 0 ); ?>>
													<option value="all" <?php selected( $current_detection_mode, 'all' ); ?>><?php esc_html_e( '全差分検知（すべての変更を検知）', 'qa-heatmap-analytics' ); ?></option>
													<option value="minor" <?php selected( $current_detection_mode, 'minor' ); ?>><?php esc_html_e( '軽微な変更も検知（見出し5文字、テキスト10文字、構造3要素）', 'qa-heatmap-analytics' ); ?></option>
													<option value="major_only" <?php selected( $current_detection_mode, 'major_only' ); ?>><?php esc_html_e( '大きな変更のみ検知（見出し20文字、テキスト50文字、構造5要素）', 'qa-heatmap-analytics' ); ?></option>
												</select>
												<p class="description"><?php esc_html_e( 'HTMLの差分をどの程度の変更量で検知するかを設定します。この設定はHTML定期取得が有効な場合のみ機能します。', 'qa-heatmap-analytics' ); ?></p>
											</td>
										</tr>
									</tbody>
								</table>
								<input type="hidden" id="measurement_site_id" value="<?php echo esc_attr( $current_site_data['site_id'] ); ?>">
								<p class="submit">
									<button type="button" id="measurement-submit" class="qahm-btn qahm-btn--primary"><?php esc_html_e( 'Save', 'qa-heatmap-analytics' ); ?></button>
								</p>
							</div>
						</div><!-- endof #tab_measurement_content -->
		<?php endif; ?>

			</div><!-- endof .tabs -->
					</div><!-- endof .qa-zero-data -->
				</div><!-- endof .qa-zero-data-container -->
		</div><!-- endof .qa-zero-content -->
	</div>


		<?php
	}

	private function is_redirect() {
		// tracking_id is used only for display switching (no state changes). wp_unslash() and sanitize_text_field() are applied inside sanitize_tracking_id().
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended
		$tracking_id_raw = isset( $_GET['tracking_id'] ) ? $this->sanitize_tracking_id( wp_unslash( $_GET['tracking_id'] ) ) : 'all';
		$tracking_id     = $this->get_safe_tracking_id( $tracking_id_raw );
		if ( $this->wrap_get_option( 'google_is_redirect' ) && $tracking_id === '' ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * GA4プロパティ一覧をAJAXで取得
	 */
	public function ajax_get_ga4_properties() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		global $qahm_google_api, $qahm_data_api;

		$tracking_id = isset( $_POST['tracking_id'] ) ? sanitize_text_field( wp_unslash( $_POST['tracking_id'] ) ) : '';
		if ( empty( $tracking_id ) ) {
			wp_send_json_error( array( 'message' => 'tracking_id is required' ) );
		}

		$sitemanage = $qahm_data_api->get_sitemanage();
		$url = null;
		if ( $sitemanage ) {
			foreach ( $sitemanage as $site ) {
				if ( $tracking_id === $site['tracking_id'] ) {
					$url = $site['url'];
					break;
				}
			}
		}

		if ( ! $url ) {
			wp_send_json_error( array( 'message' => 'tracking_id not found' ) );
		}

		$qahm_google_api->set_tracking_id( $tracking_id, $url );
		$scope = array( 'https://www.googleapis.com/auth/webmasters.readonly', 'https://www.googleapis.com/auth/analytics.readonly' );
		$is_init = $qahm_google_api->init_for_admin(
			'Google API Integration',
			$scope,
			admin_url( 'admin.php?page=qahm-config' )
		);

		if ( ! $is_init ) {
			wp_send_json_error( array( 'message' => 'Google API authentication failed. Please re-authenticate.' ) );
		}

		$result = $qahm_google_api->get_ga4_account_summaries();
		if ( $result === null || isset( $result['error'] ) ) {
			$err_msg = isset( $result['error'] ) ? $result['error'] : 'Unknown error';
			wp_send_json_error( array( 'message' => 'GA4 Admin API error: ' . ( is_array( $err_msg ) ? json_encode( $err_msg ) : $err_msg ) ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * 設定画面の項目をデータベースに保存する
	 */
	public function ajax_save_plugin_config() {
		if ( ! check_ajax_referer( 'qahm-config-nonce-action-qahm-options', 'security', false ) ) {
			wp_send_json_error( 'Invalid nonce' );
			wp_die();
		}
		/*
		if (!$this->check_qahm_access_cap('qahm_manage_settings')) {
			wp_send_json_error('Permission denied');
			wp_die();
		}
		*/

		$advanced_mode = $this->wrap_filter_input( INPUT_POST, 'advanced_mode' );
		if ( $advanced_mode === 'true' ) {
			$advanced_mode = true;
		} else {
			$advanced_mode = false;
		}
		$cb_sup_mode = $this->wrap_filter_input( INPUT_POST, 'cb_sup_mode' );
		if ( $cb_sup_mode === 'true' ) {
			$cb_sup_mode = 'yes';
		} else {
			$cb_sup_mode = 'no';
		}

		$this->wrap_update_option( 'advanced_mode', $advanced_mode );
		$this->wrap_update_option( 'cb_sup_mode', $cb_sup_mode );

		wp_send_json_success();
	}

	/**
	 * 計測設定を保存する（QA ZERO 専用）
	 */
	public function ajax_save_measurement_config() {
		if ( ! check_ajax_referer( 'qahm-config-nonce-action-qahm-options', 'security', false ) ) {
			wp_send_json_error( 'Invalid nonce' );
			wp_die();
		}

		if ( QAHM_TYPE !== QAHM_TYPE_ZERO ) {
			wp_send_json_error( 'Not available' );
			wp_die();
		}

		$site_id                  = (int) $this->wrap_filter_input( INPUT_POST, 'site_id' );
		$ignore_params            = sanitize_textarea_field( $this->wrap_filter_input( INPUT_POST, 'ignore_params' ) );
		$search_params            = sanitize_textarea_field( $this->wrap_filter_input( INPUT_POST, 'search_params' ) );
		$ignore_ips               = sanitize_textarea_field( $this->wrap_filter_input( INPUT_POST, 'ignore_ips' ) );
		$url_case                 = (int) $this->wrap_filter_input( INPUT_POST, 'url_case' );
		$get_base_html_periodic   = (int) $this->wrap_filter_input( INPUT_POST, 'get_base_html_periodic' );
		$html_diff_detection_mode = sanitize_text_field( $this->wrap_filter_input( INPUT_POST, 'html_diff_detection_mode' ) );

		// バリデーション: url_case は 0 or 1 のみ
		$url_case = ( 1 === $url_case ) ? 1 : 0;

		// バリデーション: get_base_html_periodic は 0 or 1 のみ
		$get_base_html_periodic = ( 1 === $get_base_html_periodic ) ? 1 : 0;

		// バリデーション: html_diff_detection_mode は許可値リストでチェック
		$allowed_modes = array( 'all', 'minor', 'major_only' );
		if ( ! in_array( $html_diff_detection_mode, $allowed_modes, true ) ) {
			$html_diff_detection_mode = 'major_only';
		}

		// バリデーション: 除外URLパラメーター（カンマまたは改行区切りに対応）
		$ignore_params_clean = '';
		if ( ! empty( $ignore_params ) ) {
			$params       = preg_split( '/[,\r\n]+/', $ignore_params );
			$valid_params = array();
			foreach ( $params as $param ) {
				$param = trim( $param );
				if ( '' === $param ) {
					continue;
				}
				if ( 1 !== preg_match( '/^[a-zA-Z0-9\-\_]+$/', $param ) ) {
					wp_send_json_error( 'invalid_ignore_params' );
					wp_die();
				}
				$valid_params[] = $param;
			}
			$ignore_params_clean = $this->wrap_implode( ',', $valid_params );
		}

		// バリデーション: 検索パラメーター（カンマまたは改行区切りに対応）
		$search_params_clean = '';
		if ( ! empty( $search_params ) ) {
			$params       = preg_split( '/[,\r\n]+/', $search_params );
			$valid_params = array();
			foreach ( $params as $param ) {
				$param = trim( $param );
				if ( '' === $param ) {
					continue;
				}
				if ( 1 !== preg_match( '/^[a-zA-Z0-9\-\_]+$/', $param ) ) {
					wp_send_json_error( 'invalid_search_params' );
					wp_die();
				}
				$valid_params[] = $param;
			}
			$search_params_clean = $this->wrap_implode( ',', $valid_params );
		}

		// バリデーション: IP アドレス（改行区切り → カンマ区切りに変換）
		$ignore_ips_clean = '';
		if ( ! empty( $ignore_ips ) ) {
			$ip_lines  = preg_split( '/[\r\n]+/', $ignore_ips );
			$valid_ips = array();
			foreach ( $ip_lines as $ip ) {
				$ip = trim( $ip );
				if ( '' === $ip ) {
					continue;
				}
				if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					wp_send_json_error( 'invalid_ip' );
					wp_die();
				}
				$valid_ips[] = $ip;
			}
			$ignore_ips_clean = $this->wrap_implode( ',', array_unique( $valid_ips ) );
		}

		// sitemanage を更新
		$sitemanage = $this->wrap_get_option( 'sitemanage' );
		$found      = false;
		foreach ( $sitemanage as &$site ) {
			if ( (int) $site['site_id'] === $site_id ) {
				$site['ignore_params']            = $ignore_params_clean;
				$site['search_params']            = $search_params_clean;
				$site['ignore_ips']               = $ignore_ips_clean;
				$site['url_case_sensitivity']     = $url_case;
				$site['get_base_html_periodic']   = $get_base_html_periodic;
				$site['html_diff_detection_mode'] = $html_diff_detection_mode;
				$found                            = true;
				break;
			}
		}
		unset( $site );

		if ( ! $found ) {
			wp_send_json_error( 'site_not_found' );
			wp_die();
		}

		$res = $this->wrap_update_option( 'sitemanage', $sitemanage );
		if ( ! $res ) {
			wp_send_json_error( 'save_failed' );
			wp_die();
		}

		wp_send_json_success();
	}
} // end of class
