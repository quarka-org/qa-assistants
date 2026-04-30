<?php
defined( 'ABSPATH' ) || exit;
/**
 * WP管理画面の初期化関連処理
 *
 * @package qa_heatmap
 */
// phpcs:disable PluginCheck.UpdateProcedures.update_modification_detected
// Plugin Check exclusion: QA ZERO disables WordPress auto updates intentionally (not distributed on WordPress.org).
new QAHM_Admin_Init();

// ファイルのロードや管理画面のメニューを管理するクラス
class QAHM_Admin_Init extends QAHM_File_Base {

	public function __construct() {
		// 初期化時に設定を行うためのフックを追加
		add_action( 'init', array( $this, 'init_settings' ) );
	}

	/**
	 * 初期化処理
	 */
	public function init_settings() {
		// 共通の初期化処理
		add_action( 'admin_menu', array( $this, 'setcookie' ), 1 );
		add_action( 'admin_enqueue_scripts', array( $this, 'add_announce_style' ) );
		add_action( 'admin_menu', array( $this, 'create_plugin_menu' ) );
		add_filter( 'wp_feed_cache_transient_lifetime', array( $this, 'set_feed_cache_time' ) );

		// Differs between ZERO and QA - Start ---------
		// 初期設定
		if ( QAHM_TYPE === QAHM_TYPE_ZERO ) {
			// 翻訳ファイルの読み込み
			// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Required for QA ZERO standalone version (translations not loaded via WordPress.org)
			load_plugin_textdomain( 'qa-heatmap-analytics', false, plugin_basename( __DIR__ ) . '/languages' );

			// その他のZERO専用の設定を追加
			add_action( 'user_register', array( $this, 'set_zero_user' ) );
			add_action( 'admin_init', array( $this, 'zero_profile_color' ) );
			add_action( 'admin_init', array( $this, 'zero_user_redirect' ) );
			add_action( 'admin_menu', array( $this, 'zero_user_remove_menus' ) );
			add_action( 'wp_before_admin_bar_render', array( $this, 'zero_user_custom_admin_bar' ) );

			// 自動アップデートの停止
			add_filter( 'auto_update_core', '__return_false' );
			add_filter( 'auto_update_plugin', '__return_false' );
			add_filter( 'auto_update_theme', '__return_false' );
			add_filter( 'auto_update_translation', '__return_false' );

			// ログイン画面のカスタマイズ
			add_action( 'login_enqueue_scripts', array( $this, 'zero_login_logo' ) );
			add_filter( 'login_headerurl', array( $this, 'zero_login_logo_url' ) );
			add_filter( 'login_headertext', array( $this, 'zero_login_logo_text' ) );
		} elseif ( QAHM_TYPE === QAHM_TYPE_WP ) {
			// PV数制限アラート
			add_action( 'admin_notices', array( $this, 'show_pv_limit_notice' ) );
			// QA Analytics から QA Assistants(v5) にアップデートした際のデータ更新中通知
			add_action( 'admin_footer', array( $this, 'v5_data_unavailable_notice_footer_js' ) );
			// QAHM_TYPE_WP ブロック内にフック追加（init_settings 内）
			add_action( 'admin_footer', array( $this, 'advanced_mode_notice_footer_js' ) );
			add_action( 'wp_ajax_qahm_dismiss_advanced_notice', array( $this, 'ajax_dismiss_advanced_notice' ) );

		}
		// Differs between ZERO and QA - End ----------
	}

	function show_pv_limit_notice() {
		$pv_limit_rate = $this->wrap_get_option( 'pv_limit_rate' );
		if ( ! $pv_limit_rate ) {
			return;
		}
		$lang_set = get_bloginfo( 'language' );
		// create_qa_announce_html用
		// 許可するHTMLタグと属性のリスト
		$qa_announce_allowed_tags = array(
			'div'        => array(
				'class' => array(),
				'style' => array(),
			),
			'span'       => array(
				'class' => array(),
				'style' => array(),
			),
			'p'          => array(
				'class' => array(),
				'style' => array(),
			),
			'br'         => array(),
			'blockquote' => array(),
			'ul'         => array(),
			'ol'         => array(),
			'li'         => array(),
			'strong'     => array(),
			'em'         => array(),
			'b'          => array(),
			'i'          => array(),
			// リンクタグ
			'a'          => array(
				'href'   => array(),
				'title'  => array(),
				'target' => array(),
				'rel'    => array(),
			),
			// 画像タグ
			'img'        => array(
				'src'    => array(),
				'alt'    => array(),
				'width'  => array(),
				'height' => array(),
				'class'  => array(),
			),
		);
		if ( $pv_limit_rate >= 100 ) {
			/* translators: placeholders are for the link */
			$qa_announce_html = $this->create_qa_announce_html( esc_html__( 'Data collection has stopped because your site reached its monthly pageview limit.', 'qa-heatmap-analytics' ), 'error' );
			echo wp_kses( $qa_announce_html, $qa_announce_allowed_tags );
		} elseif ( $pv_limit_rate >= 80 ) {
			/* translators: placeholders are for the link */
			$qa_announce_html = $this->create_qa_announce_html( esc_html__( 'Your site has used 80% of its monthly pageview limit.', 'qa-heatmap-analytics' ), 'warning' );
			echo wp_kses( $qa_announce_html, $qa_announce_allowed_tags );
		}
	}


	/**
	 * 管理画面に表示するアラートCSSの読み込み
	 */
	public function add_announce_style() {
		$css_dir_url = $this->get_css_dir_url();
		wp_enqueue_style( QAHM_NAME . '-admin-page-announce', $css_dir_url . 'admin-page-announce.css', null, QAHM_PLUGIN_VERSION );
	}

	/**
	 * 管理画面のQAHMメニュー
	 * ここから各管理ページのファイル読み込みフックを呼び出している
	 */
	public function create_plugin_menu() {
		if ( ! is_user_logged_in() || ! is_admin() ) {
			return false;
		}

		// Specific to QA Assistants - Start ---------------
		// QA Assistantsの場合、intro_completedフラグがfalseなら「はじめに」画面のみ表示
		if ( QAHM_TYPE === QAHM_TYPE_WP ) {
			$intro_completed = $this->wrap_get_option( 'intro_completed' );
			if ( ! $intro_completed ) {
				$this->create_intro_menu_only();
				return;
			}
		}
		// Specific to QA Assistants - End ---------------

		// Specific to ZERO - Start ---------------
		if ( QAHM_TYPE === QAHM_TYPE_ZERO ) {
			global $qahm_admin_page_dashboard;
		}
		// Specific to ZERO - End ---------------

		global $qahm_admin_page_assistant;
		global $qahm_admin_page_user;
		global $qahm_admin_page_acquisition;
		global $qahm_admin_page_behavior;
		global $qahm_admin_page_behavior_lp;
		global $qahm_admin_page_behavior_gw;
		global $qahm_admin_page_behavior_ap;
		global $qahm_admin_page_goals;
		global $qahm_admin_page_ai_report;
		global $qahm_admin_page_realtime;
		global $qahm_admin_page_config;
		global $qahm_admin_page_license;
		global $qahm_admin_page_help;
		global $qahm_admin_page_entire;

		$cap   = 'manage_options';
		$user  = wp_get_current_user();
		$roles = $user->roles;
		$role  = array_shift( $roles );
		if ( $role === 'qazero-admin' || $role === 'qazero-view' ) {
			$cap = $role;
		}

		// 事前にモードを決定
		if ( QAHM_TYPE === QAHM_TYPE_ZERO ) {
			$advanced_mode = true;
			$sub_menu_mode = false;
		} else {
			$advanced_mode = $this->wrap_get_option( 'advanced_mode' );
			$sub_menu_mode = true;
		}

		// ランディングページ系を表示するかどうか
		$show_behavior_sub = ! $sub_menu_mode || ( $sub_menu_mode && $advanced_mode );

		// ダッシュボードを最初に条件分岐で定義
		$pages = array();

		// Specific to ZERO - Start ---------------
		if ( QAHM_TYPE === QAHM_TYPE_ZERO ) {
			$pages[ QAHM_Admin_Page_Dashboard::SLUG ] = array(
				'obj'   => $qahm_admin_page_dashboard,
				'title' => __( 'Dashboard', 'qa-heatmap-analytics' ),
				'icon'  => 'menu_dashboard.svg',
				'when'  => QAHM_TYPE === QAHM_TYPE_ZERO,
				'type'  => 'normal',
				'slug'  => QAHM_Admin_Page_Dashboard::SLUG,
			);
		}
		// Specific to ZERO - End ---------------

		// 各ページ情報を slug キーでまとめる
		$pages += array(
			QAHM_Admin_Page_Assistant::SLUG   => array(
				'obj'   => $qahm_admin_page_assistant,
				'title' => _x( 'Explore', 'Admin screen label', 'qa-heatmap-analytics' ),
				'icon'  => 'menu_brains.svg',
				'when'  => true,
				'type'  => 'normal',
				'slug'  => QAHM_Admin_Page_Assistant::SLUG,
			),
			QAHM_Admin_Page_Realtime::SLUG    => array(
				'obj'   => $qahm_admin_page_realtime,
				'title' => __( 'Realtime', 'qa-heatmap-analytics' ),
				'icon'  => 'menu_realtime.svg',
				'when'  => true,
				'type'  => 'normal',
				'slug'  => QAHM_Admin_Page_Realtime::SLUG,
			),
			QAHM_Admin_Page_User::SLUG        => array(
				'obj'   => $qahm_admin_page_user,
				'title' => __( 'Audience', 'qa-heatmap-analytics' ),
				'icon'  => 'menu_user.svg',
				'when'  => true,
				'type'  => 'normal',
				'slug'  => QAHM_Admin_Page_User::SLUG,
			),
			QAHM_Admin_Page_Acquisition::SLUG => array(
				'obj'   => $qahm_admin_page_acquisition,
				'title' => __( 'Acquisition', 'qa-heatmap-analytics' ),
				'icon'  => 'menu_acquisition.svg',
				'when'  => $advanced_mode,
				'type'  => 'normal',
				'slug'  => QAHM_Admin_Page_Acquisition::SLUG,
			),
			QAHM_Admin_Page_Behavior_Lp::SLUG => array(
				'obj'   => $qahm_admin_page_behavior,
				'title' => __( 'Behavior', 'qa-heatmap-analytics' ),
				'icon'  => 'menu_behavior.svg',
				'when'  => ! $sub_menu_mode,
				'type'  => 'behavior_parent',
				'slug'  => QAHM_Admin_Page_Behavior_Lp::SLUG,
			),
			'behavior_lp'                     => array(
				'obj'   => $qahm_admin_page_behavior_lp,
				'title' => __( 'Landing Pages', 'qa-heatmap-analytics' ),
				'when'  => $show_behavior_sub,
				'type'  => 'behavior_sub',
				'slug'  => QAHM_Admin_Page_Behavior_Lp::SLUG,
			),
			'behavior_gw'                     => array(
				'obj'   => $qahm_admin_page_behavior_gw,
				'title' => __( 'Top Growing', 'qa-heatmap-analytics' ),
				'when'  => $show_behavior_sub,
				'type'  => 'behavior_sub',
				'slug'  => QAHM_Admin_Page_Behavior_Gw::SLUG,
			),
			'behavior_ap'                     => array(
				'obj'   => $qahm_admin_page_behavior_ap,
				'title' => __( 'All Pages', 'qa-heatmap-analytics' ),
				'when'  => $show_behavior_sub,
				'type'  => 'behavior_sub',
				'slug'  => QAHM_Admin_Page_Behavior_Ap::SLUG,
			),
			QAHM_Admin_Page_Goals::SLUG       => array(
				'obj'   => $qahm_admin_page_goals,
				'title' => __( 'Goals', 'qa-heatmap-analytics' ),
				'icon'  => 'menu_goals.svg',
				'when'  => $advanced_mode,
				'type'  => 'normal',
				'slug'  => QAHM_Admin_Page_Goals::SLUG,
			),
			/*
			QAHM_Admin_Page_Ai_Report::SLUG => [
				'obj'   => $qahm_admin_page_ai_report,
				'title' => 'AIレポート',
				'icon'  => 'menu_ai_report.svg',
				'when'  => false,
				'type'  => 'normal',
				'slug'  => QAHM_Admin_Page_Ai_Report::SLUG,
			],
			*/
			// Specific to ZERO - Start ---------------
			( class_exists( 'QAHM_Admin_Page_License' ) ? QAHM_Admin_Page_License::SLUG : '__license_placeholder__' ) => class_exists( 'QAHM_Admin_Page_License' ) ? array(
				'obj'   => isset( $qahm_admin_page_license ) ? $qahm_admin_page_license : null,
				'title' => __( 'License Activation', 'qa-heatmap-analytics' ),
				'icon'  => 'menu_license.svg',
				'when'  => QAHM_TYPE === QAHM_TYPE_ZERO && $advanced_mode && $this->check_access_role( 'manage_options' ),
				'type'  => 'normal',
				'slug'  => QAHM_Admin_Page_License::SLUG,
			) : null,
			// Specific to ZERO - End ---------------
			QAHM_Admin_Page_Config::SLUG      => array(
				'obj'   => $qahm_admin_page_config,
				'title' => __( 'Settings', 'qa-heatmap-analytics' ),
				'icon'  => 'menu_config.svg',
				'when'  => $this->check_access_role( 'qazero-admin' ),
				'type'  => 'normal',
				'slug'  => QAHM_Admin_Page_Config::SLUG,
			),
			QAHM_Admin_Page_Entire::SLUG      => array(
				'obj'   => $qahm_admin_page_entire,
				'title' => 'タグ発行',
				'icon'  => 'menu_tag.svg',
				'when'  => QAHM_TYPE === QAHM_TYPE_ZERO && $this->check_access_role( 'manage_options' ),
				'type'  => 'normal',
				'slug'  => QAHM_Admin_Page_Entire::SLUG,
			),
			QAHM_Admin_Page_Help::SLUG        => array(
				'obj'   => $qahm_admin_page_help,
				'title' => __( 'Help', 'qa-heatmap-analytics' ),
				'icon'  => 'menu_help.svg',
				'when'  => true,
				'type'  => 'normal',
				'slug'  => QAHM_Admin_Page_Help::SLUG,
			),
		);

		// 固定のメニュー順
		$order = array(
			// Specific to ZERO - Start ---------------
			QAHM_TYPE === QAHM_TYPE_ZERO ? QAHM_Admin_Page_Dashboard::SLUG : null,
			// Specific to ZERO - End ---------------
			QAHM_Admin_Page_Assistant::SLUG,
			QAHM_Admin_Page_Realtime::SLUG,
			QAHM_Admin_Page_User::SLUG,
			QAHM_Admin_Page_Acquisition::SLUG,
			QAHM_Admin_Page_Behavior_Lp::SLUG,
			'behavior_lp',
			'behavior_gw',
			'behavior_ap',
			QAHM_Admin_Page_Goals::SLUG,
			//QAHM_Admin_Page_Ai_Report::SLUG,
			class_exists( 'QAHM_Admin_Page_License' ) ? QAHM_Admin_Page_License::SLUG : null,
			QAHM_Admin_Page_Config::SLUG,
			QAHM_Admin_Page_Entire::SLUG,
			QAHM_Admin_Page_Help::SLUG,
		);

		// $order配列定義後にnullを除去
		$order = array_filter( $order );

		if ( ! $sub_menu_mode ) {
			// サブメニューOFF：order順でトップ/サブを分岐
			foreach ( $order as $key ) {
				if ( empty( $pages[ $key ] ) || ! $pages[ $key ]['when'] ) {
					continue;
				}
				$p = $pages[ $key ];

				if ( $p['type'] === 'behavior_parent' ) {
					$hook = add_menu_page(
						esc_html( $p['title'] ),
						esc_html( $p['title'] ),
						$cap,
						$p['slug'],
						array( $qahm_admin_page_behavior_lp, 'create_html' ),
						$this->get_img_dir_url() . $p['icon']
					);
					add_action( 'admin_enqueue_scripts', array( $qahm_admin_page_behavior_lp, 'enqueue_scripts' ) );
					$qahm_admin_page_behavior->hook_suffix = $hook;

				} elseif ( $p['type'] === 'behavior_sub' ) {
					$hook = add_submenu_page(
						QAHM_Admin_Page_Behavior_Lp::SLUG,
						esc_html( $p['title'] ),
						esc_html( $p['title'] ),
						$cap,
						$p['slug'],
						array( $p['obj'], 'create_html' )
					);
					add_action( 'admin_enqueue_scripts', array( $p['obj'], 'enqueue_scripts' ) );
					$p['obj']->hook_suffix = $hook;

				} else {
					$hook = add_menu_page(
						esc_html( $p['title'] ),
						esc_html( $p['title'] ),
						$cap,
						$p['slug'],
						array( $p['obj'], 'create_html' ),
						$this->get_img_dir_url() . $p['icon']
					);
					add_action( 'admin_enqueue_scripts', array( $p['obj'], 'enqueue_scripts' ) );
					$p['obj']->hook_suffix = $hook;
				}
			}
		} else {
			// サブメニューON：親を定数QAHM_PLUGIN_NAME で登録
			$parent_icon = 'data:image/svg+xml;base64,' . base64_encode(
				'<?xml version="1.0" encoding="utf-8"?>
				<!-- Generator: Adobe Illustrator 25.4.1, SVG Export Plug-In . SVG Version: 6.00 Build 0)  -->
				<svg version="1.1" id="レイヤー_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px"
					y="0px" viewBox="0 0 256 256" style="enable-background:new 0 0 256 256;" xml:space="preserve">
				<g>
					<g>
						<path class="st0" d="M232.7,237.5c4.6,0,9.1-2.1,12-6c4.9-6.6,3.6-16-3.1-20.9l-39.4-29.3l-15.2,26l36.7,27.3
							C226.4,236.6,229.6,237.5,232.7,237.5z"/>
						<path class="st1" d="M186.5,189.8c-1.3,0-2.7-0.4-3.8-1.3l-78.5-59c-2.1-1.6-3-4.3-2.3-6.8c0.7-2.5,2.9-4.4,5.5-4.7l44.9-4.6
							c3.6-0.4,6.7,2.2,7,5.7c0.4,3.5-2.2,6.7-5.7,7l-28.6,2.9l65.4,49.1c2.8,2.1,3.4,6.1,1.3,9C190.4,188.9,188.4,189.8,186.5,189.8z"
							/>
						<path class="st2" d="M117.4,237c-19.1,0-38-5.1-54.9-14.9c-25.2-14.7-43.2-38.4-50.6-66.6C-3.3,97.2,31.6,37.4,89.9,22.1
							C148.1,6.8,208,41.7,223.3,100l0,0c15.3,58.2-19.6,118.1-77.9,133.4C136.1,235.8,126.7,237,117.4,237z M117.6,44.1
							c-7,0-14.1,0.9-21.2,2.8C51.8,58.6,25,104.4,36.8,149c5.7,21.6,19.4,39.7,38.7,51c19.3,11.3,41.8,14.3,63.4,8.7
							c44.6-11.7,71.3-57.5,59.6-102.1C188.6,69,154.7,44.1,117.6,44.1z"/>
						<path class="st1" d="M169.4,124.8c-0.1,0-0.2,0-0.3,0c-4-0.2-7.1-3.5-6.9-7.5c0,0,0,0,0,0c0.2-3.9,3.5-7,7.5-6.9
							c4,0.2,7.1,3.5,6.9,7.5C176.4,121.8,173.2,124.8,169.4,124.8z"/>
						<path class="st1" d="M186.5,123.2c-0.1,0-0.2,0-0.3,0c-4-0.2-7.1-3.5-6.9-7.5c0,0,0,0,0,0c0.2-3.9,3.5-7,7.5-6.9
							c1.9,0.1,3.7,0.9,5,2.3c1.3,1.4,2,3.3,1.9,5.2C193.5,120.2,190.3,123.2,186.5,123.2z"/>
					</g>
				</g>
				</svg>'
			);
			if ( ! $advanced_mode ) {
				$parent_slug = QAHM_Admin_Page_Assistant::SLUG;
				$parent_obj  = $qahm_admin_page_assistant;
			} else {
				// Differs between ZERO and QA - Start ----------
				if ( QAHM_TYPE === QAHM_TYPE_ZERO ) {
					$parent_slug = QAHM_Admin_Page_Dashboard::SLUG;
					$parent_obj  = $qahm_admin_page_dashboard;
				} else {
					$parent_slug = QAHM_Admin_Page_Assistant::SLUG;
					$parent_obj  = $qahm_admin_page_assistant;
				}
				// Differs between ZERO and QA - End ----------
			}
			$hook = add_menu_page(
				QAHM_PLUGIN_NAME,
				QAHM_PLUGIN_NAME,
				$cap,
				$parent_slug,
				array( $parent_obj, 'create_html' ),
				$parent_icon
			);
			add_action( 'admin_enqueue_scripts', array( $parent_obj, 'enqueue_scripts' ) );
			$parent_obj->hook_suffix = $hook;

			// order順で全項目をサブメニュー登録（behavior系は when=false になるのでスキップ）
			foreach ( $order as $key ) {
				if ( empty( $pages[ $key ] ) || ! $pages[ $key ]['when'] ) {
					continue;
				}
				$p    = $pages[ $key ];
				$hook = add_submenu_page(
					$parent_slug,
					esc_html( $p['title'] ),
					esc_html( $p['title'] ),
					$cap,
					$p['slug'],
					array( $p['obj'], 'create_html' )
				);
				add_action( 'admin_enqueue_scripts', array( $p['obj'], 'enqueue_scripts' ) );
				$p['obj']->hook_suffix = $hook;
			}
		}
	}

	/**
	 * 「はじめに」画面のみのメニューを作成（QA Assistants専用）
	 */
	private function create_intro_menu_only() {
		global $qahm_admin_page_intro;

		$cap = 'manage_options';

		$parent_icon = 'data:image/svg+xml;base64,' . base64_encode(
			'<?xml version="1.0" encoding="utf-8"?>
			<!-- Generator: Adobe Illustrator 25.4.1, SVG Export Plug-In . SVG Version: 6.00 Build 0)  -->
			<svg version="1.1" id="レイヤー_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px"
				y="0px" viewBox="0 0 256 256" style="enable-background:new 0 0 256 256;" xml:space="preserve">
			<g>
				<g>
					<path class="st0" d="M232.7,237.5c4.6,0,9.1-2.1,12-6c4.9-6.6,3.6-16-3.1-20.9l-39.4-29.3l-15.2,26l36.7,27.3
						C226.4,236.6,229.6,237.5,232.7,237.5z"/>
					<path class="st1" d="M186.5,189.8c-1.3,0-2.7-0.4-3.8-1.3l-78.5-59c-2.1-1.6-3-4.3-2.3-6.8c0.7-2.5,2.9-4.4,5.5-4.7l44.9-4.6
						c3.6-0.4,6.7,2.2,7,5.7c0.4,3.5-2.2,6.7-5.7,7l-28.6,2.9l65.4,49.1c2.8,2.1,3.4,6.1,1.3,9C190.4,188.9,188.4,189.8,186.5,189.8z"
						/>
					<path class="st2" d="M117.4,237c-19.1,0-38-5.1-54.9-14.9c-25.2-14.7-43.2-38.4-50.6-66.6C-3.3,97.2,31.6,37.4,89.9,22.1
						C148.1,6.8,208,41.7,223.3,100l0,0c15.3,58.2-19.6,118.1-77.9,133.4C136.1,235.8,126.7,237,117.4,237z M117.6,44.1
						c-7,0-14.1,0.9-21.2,2.8C51.8,58.6,25,104.4,36.8,149c5.7,21.6,19.4,39.7,38.7,51c19.3,11.3,41.8,14.3,63.4,8.7
						c44.6-11.7,71.3-57.5,59.6-102.1C188.6,69,154.7,44.1,117.6,44.1z"/>
					<path class="st1" d="M169.4,124.8c-0.1,0-0.2,0-0.3,0c-4-0.2-7.1-3.5-6.9-7.5c0,0,0,0,0,0c0.2-3.9,3.5-7,7.5-6.9
						c4,0.2,7.1,3.5,6.9,7.5C176.4,121.8,173.2,124.8,169.4,124.8z"/>
					<path class="st1" d="M186.5,123.2c-0.1,0-0.2,0-0.3,0c-4-0.2-7.1-3.5-6.9-7.5c0,0,0,0,0,0c0.2-3.9,3.5-7,7.5-6.9
						c1.9,0.1,3.7,0.9,5,2.3c1.3,1.4,2,3.3,1.9,5.2C193.5,120.2,190.3,123.2,186.5,123.2z"/>
				</g>
			</g>
			</svg>'
		);

		$hook = add_menu_page(
			QAHM_PLUGIN_NAME,
			QAHM_PLUGIN_NAME,
			$cap,
			QAHM_Admin_Page_Intro::SLUG,
			array( $qahm_admin_page_intro, 'create_html' ),
			$parent_icon
		);
		add_action( 'admin_enqueue_scripts', array( $qahm_admin_page_intro, 'enqueue_scripts' ) );
		$qahm_admin_page_intro->hook_suffix = $hook;

		// 設定画面も登録（サブメニューとして非表示）
		global $qahm_admin_page_config;
		$hook = add_submenu_page(
			null, // 親スラッグを null にすることでメニューには表示されない
			__( 'Settings', 'qa-heatmap-analytics' ),
			__( 'Settings', 'qa-heatmap-analytics' ),
			$cap,
			QAHM_Admin_Page_Config::SLUG,
			array( $qahm_admin_page_config, 'create_html' )
		);
		add_action( 'admin_enqueue_scripts', array( $qahm_admin_page_config, 'enqueue_scripts' ) );
		$qahm_admin_page_config->hook_suffix = $hook;
	}

	// ユーザーを新規追加した際、管理画面にZEROカラーを適用
	public function set_zero_user( $user_id ) {
		update_user_meta( $user_id, 'admin_color', 'qa-zero-green' );
	}

	// QA ZERO用の色を管理画面に適用
	public function zero_profile_color() {
		wp_admin_css_color(
			'qa-zero-green',
			'qa-zero-green',
			$this->get_css_dir_url() . 'profile/qa-zero-green.css',
			array( '#1f8362', '#0f4232', '#1e1e1e', '#3855e1' ),
			array(
				'base'    => '#f1f3f3',
				'focus'   => '#fff',
				'current' => '#fff',
			)
		);
	}

	// ZERO専用ユーザーの場合不要なメニューの削除
	public function zero_user_remove_menus() {
		$user = wp_get_current_user();
		if ( $user->has_cap( 'qazero-admin' ) || $user->has_cap( 'qazero-view' ) ) {
			remove_menu_page( 'index.php' );
			remove_menu_page( 'edit.php' );
			remove_menu_page( 'upload.php' );
			remove_menu_page( 'edit.php?post_type=page' );
			remove_menu_page( 'edit-comments.php' );
			remove_menu_page( 'themes.php' );
			remove_menu_page( 'plugins.php' );
			remove_menu_page( 'users.php' );
			remove_menu_page( 'tools.php' );
			remove_menu_page( 'options-general.php' );
			remove_menu_page( 'profile.php' );

			// メニューセパレーター（区切り線）の除去
			global $menu;
			foreach ( $menu as $key => $item ) {
				if ( false !== strpos( $item[2], 'separator' ) ) {
					unset( $menu[ $key ] );
				}
			}
		}
	}

	// ZERO専用ユーザーのリダイレクト処理
	public function zero_user_redirect() {
		$user = wp_get_current_user();
		if ( $user->has_cap( 'qazero-admin' ) || $user->has_cap( 'qazero-view' ) ) {
			// pagenowを使わないとajaxでコケるっぽい
			global $pagenow;
			if ( $pagenow === 'index.php' ||
				$pagenow === 'edit.php' ||
				$pagenow === 'upload.php' ||
				$pagenow === 'comment.php' ||
				$pagenow === 'edit.php?post_type=page' ||
				$pagenow === 'edit-comments.php' ||
				$pagenow === 'edit-tags.php' ||
				$pagenow === 'themes.php' ||
				$pagenow === 'plugins.php' ||
				$pagenow === 'users.php' ||
				$pagenow === 'tools.php' ||
				$pagenow === 'options-general.php' ||
				$pagenow === 'post.php' ||
				$pagenow === 'post-new.php' ||
				$pagenow === 'profile.php' ||
				$pagenow === 'update-core.php'
			) {
				wp_safe_redirect( admin_url( 'admin.php?page=qahm-dashboard' ) );
				exit;
			}
			// WordPress本体の更新通知を非表示
			remove_action( 'admin_notices', 'update_nag', 3 );
		}
	}

	// ZERO専用ユーザーのアドミンバーのカスタマイズ
	public function zero_user_custom_admin_bar() {
		global $wp_admin_bar;

		// ZEROのロゴ＆タイトル設定
		$logo_svg = '<svg width="20" height="20" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="10" r="10" fill="#5AC93D" /><text x="50%" y="50%" dy=".3em" font-size="12" text-anchor="middle" fill="white">Z</text></svg>';
		$title    = sprintf(
			'<div style="display: flex;align-items: center;justify-content: center">%s<span style="color: white;margin-left: 10px">%s</span></div>',
			$logo_svg, //アイコン
			'QA ZERO'//親メニューラベル
		);
		$wp_admin_bar->add_menu(
			array(
				'id'    => 'my-custom-logo',
				'title' => $title,
				'href'  => admin_url( 'admin.php?page=qahm-dashboard' ),
			)
		);

		// サイト一覧は削除 — サイトセレクターバー（各ページのメインコンテンツ上部）に移行（#901 Step 2）

		// この関数自体が QAHM_TYPE_ZERO でのみフック登録されるため、全ユーザーに適用
		$wp_admin_bar->remove_menu( 'wp-logo' );
		$wp_admin_bar->remove_menu( 'site-name' );

		// プロフィール編集リンクを削除
		$wp_admin_bar->remove_node( 'edit-profile' );

		// 英語または日本語で「こんにちは」、「Howdy」および「さん」を削除
		$my_account = $wp_admin_bar->get_node( 'my-account' );
		if ( $my_account ) {
			$new_title = preg_replace( '/こんにちは、|Howdy,\s?|さん/', '', $my_account->title );
			$wp_admin_bar->add_node(
				array(
					'id'    => 'my-account',
					'title' => $new_title,
				)
			);
		}
	}

	/**
	 * ログイン画面のロゴをカスタマイズ
	 */
	public function zero_login_logo() {
		?>
		<style type="text/css">
			body.login div#login h1 a {
				background-image: url('<?php echo esc_url( $this->get_img_dir_url() . 'logo-zero.png' ); ?>');
				background-size: contain;
				width: 100%;
				height: 80px; /* ロゴの高さに合わせて調整 */
				text-indent: -9999px; /* 視覚的にテキストを隠す */
				overflow: hidden;
				display: block;
			}
		</style>
		<?php
	}

	/**
	 * ロゴのリンク先をホームURLに変更
	 */
	public function zero_login_logo_url() {
		return esc_url( home_url() );
	}

	/**
	 * ロゴのテキストを変更
	 */
	public function zero_login_logo_text() {
		return esc_html( get_bloginfo( 'name', 'display' ) );
	}

	/**
	 * RSSフィードのキャッシュを3時間に変更
	 */
	public function set_feed_cache_time() {
		return 60 * 60 * 3;
	}

	// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended
	// Plugin Check exclusion: setcookie() reads $_GET/$_COOKIE/$_SERVER for tracking_id persistence. Values are sanitized via sanitize_tracking_id(). No nonce because this runs on every admin page load.
	public function setcookie() {
		$currentScriptName = basename( $_SERVER['PHP_SELF'] );

		// 現在のスクリプト名が 'admin.php'じゃなければ終了
		if ( $currentScriptName !== 'admin.php' ) {
			return;
		}

		if ( ! $this->is_qahm_admin_page() ) {
			return;
		}

		global $qahm_data_api, $qahm_log;

		if ( isset( $_GET['tracking_id'] ) ) {
			$tracking_id = $this->sanitize_tracking_id( wp_unslash( $_GET['tracking_id'] ) );

			// 無効な tracking_id（空文字含む）を cookie に保存しないように検証する。
			// 検証なしで保存すると、汚染された cookie が以降毎リクエスト送られて
			// 設定画面が永久に開けなくなる（Issue #1072）。
			// 注: validate_tracking_id() は空文字を「有効」扱いするため、
			//     サニタイズで全潰れになったケースを別途 empty() で弾く。
			if ( empty( $tracking_id ) || ! $this->validate_tracking_id( $tracking_id ) ) {
				if ( $qahm_log ) {
					$ip_address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
					$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'unknown';
					$qahm_log->warning(
						"Invalid tracking_id attempt: '{$tracking_id}' from IP: {$ip_address}, UA: {$user_agent}",
						'tracking_validation.log'
					);
				}
				$this->clear_tracking_id_cookie();
				wp_safe_redirect( remove_query_arg( 'tracking_id' ) );
				exit;
			}

			setcookie(
				'tracking_id',
				$tracking_id,
				array(
					'expires'  => time() + 60 * 60 * 24 * 365,
					'path'     => '/',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		} elseif ( isset( $_COOKIE['tracking_id'] ) ) {
			$tracking_id = $this->sanitize_tracking_id( wp_unslash( $_COOKIE['tracking_id'] ) );

			// cookie 由来でも検証する。汚染された cookie からの自己回復のため。
			// 空文字ガードの理由は URL 分岐側のコメント参照。
			if ( empty( $tracking_id ) || ! $this->validate_tracking_id( $tracking_id ) ) {
				if ( $qahm_log ) {
					$ip_address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
					$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'unknown';
					$qahm_log->warning(
						"Invalid tracking_id in cookie: '{$tracking_id}' from IP: {$ip_address}, UA: {$user_agent}",
						'tracking_validation.log'
					);
				}
				$this->clear_tracking_id_cookie();
				wp_safe_redirect( remove_query_arg( 'tracking_id' ) );
				exit;
			}

			$current_url = $_SERVER['REQUEST_URI'];
			$current_url = add_query_arg( 'tracking_id', $tracking_id, $current_url );
			wp_safe_redirect( $current_url );  // 新しいURLにリダイレクト
			exit;
		} else {
			//$tracking_id = "all"; // default
			$sitemanage = $qahm_data_api->get_sitemanage();
			if ( ! empty( $sitemanage ) && isset( $sitemanage[0]['tracking_id'] ) ) {
				$tracking_id = $sitemanage[0]['tracking_id'];
				$current_url = $_SERVER['REQUEST_URI'];
				$current_url = add_query_arg( 'tracking_id', $tracking_id, $current_url );
				wp_safe_redirect( $current_url );  // 新しいURLにリダイレクト
				exit;
			}
		}
	}

	/**
	 * tracking_id cookie をクリアする
	 *
	 * 設定時の属性（path / secure / httponly / samesite）と完全に一致させる必要がある。
	 * ヘルパー化することで、将来属性が追加されたときに設定/削除でズレが起きないようにする。
	 */
	private function clear_tracking_id_cookie() {
		setcookie(
			'tracking_id',
			'',
			array(
				'expires'  => time() - 3600,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended

	/**
	 * 現在のページがQA Assistants関連ページかどうかを判定
	 *
	 * @return bool QA Assistants関連ページの場合true
	 */
	private function is_qahm_admin_page() {
		// Plugin Check exclusion: $_GET['page'] is a safe internal admin query var; used only for page detection in admin area.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! isset( $_GET['page'] ) ) {
			return false;
		}

		$page = $_GET['page'];

		$qahm_pages = array(
			'qahm-dashboard',
			'qahm-brains',
			'qahm-realtime',
			'qahm-user',
			'qahm-acquisition',
			'qahm-behavior',
			'qahm-behavior-lp',
			'qahm-behavior-gw',
			'qahm-behavior-ap',
			'qahm-goals',
			'qahm-license',
			'qahm-config',
			'qahm-entire',
			'qahm-help',
			'qahm-dataportal',
		);

		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return ( $this->wrap_strpos( $page, 'qahm' ) === 0 || $this->wrap_in_array( $page, $qahm_pages ) );
	}


	// QA Analytics から QA Assistants(v5) にアップデートした際のデータ更新中通知
	public function show_v5_data_unavailable_notice() {
		if ( ! $this->is_qahm_admin_page() ) {
			return;
		}

		// 保存状態を取得（配列形式）
		$state     = $this->wrap_get_option( 'v5_data_unavailable_state' );
		$pending   = isset( $state['pending'] ) ? (bool) $state['pending'] : false;
		$timestamp = isset( $state['timestamp'] ) ? (int) $state['timestamp'] : 0;

		// 24時間を超えていたら自動解除
		if ( $pending && ( time() - $timestamp > DAY_IN_SECONDS ) ) {
			$state['pending'] = false;
			$this->wrap_update_option( 'v5_data_unavailable_state', $state );
			return;
		}

		// 通知表示は pending が true のときのみ
		if ( ! $pending ) {
			return;
		}

		$locale = get_locale();
		if ( $this->wrap_strpos( $locale, 'ja' ) === 0 ) {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			echo '<span class="dashicons dashicons-warning" style="color:#d63638;"></span>';
			echo esc_html__( 'QAアナリティクスからQA Assistantsへのデータ移行処理を実行中です。', 'qa-heatmap-analytics' ) . '<br>';
			echo esc_html__( 'レポート画面などでは「データがありません」と表示されますが、データは夜間処理後に反映されます。', 'qa-heatmap-analytics' ) . '<br>';
			echo esc_html__( '計測は通常どおり行われていますので、明日以降のデータ反映を楽しみにお待ちください。', 'qa-heatmap-analytics' ) . '<br>';
			echo '<a href="' . esc_url( 'https://mem.quarka.org/wpuserinfo-alert20251006/' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( '詳しくはこちら', 'qa-heatmap-analytics' ) . '</a>';
			echo '</p></div>';
		} else {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			echo '<span class="dashicons dashicons-warning" style="color:#d63638;"></span>';
			echo esc_html__( 'Your past analytics data is being carried over and prepared for use in QA Assistants.', 'qa-heatmap-analytics' ) . '<br>';
			echo esc_html__( 'Reports may show "No data available", but data collection is proceeding as normal and data will be reflected after the nightly process.', 'qa-heatmap-analytics' ) . '<br>';
			echo esc_html__( 'Please look forward to seeing your data reflected in the coming days.', 'qa-heatmap-analytics' ) . '<br>';
			echo '<a href="' . esc_url( 'https://mem.quarka.org/wpuserinfo-alert20251006/' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Click here for more details.', 'qa-heatmap-analytics' ) . '</a>';
			echo '</p></div>';
		}
	}

	/**
	 * QA Analytics から QA Assistants(v5) にアップデートした際のデータ更新中通知(JavaScript版)
	 */
	public function v5_data_unavailable_notice_footer_js() {
		// 同じ条件で出す時だけJSを出力
		if ( ! $this->is_qahm_admin_page() ) {
			return;
		}

		// 保存状態を取得
		$state     = $this->wrap_get_option( 'v5_data_unavailable_state' );
		$pending   = isset( $state['pending'] ) ? (bool) $state['pending'] : false;
		$timestamp = isset( $state['timestamp'] ) ? (int) $state['timestamp'] : 0;

		// 24時間を超えていたら自動解除
		if ( $pending && ( time() - $timestamp > DAY_IN_SECONDS ) ) {
			$state['pending'] = false;
			$this->wrap_update_option( 'v5_data_unavailable_state', $state );
			return;
		}

		// 通知表示は pending が true のときのみ
		if ( ! $pending ) {
			return;
		}

		$locale = get_locale();
		?>
		<script>
		jQuery(document).ready(function($) {
			<?php if ( $this->wrap_strpos( $locale, 'ja' ) === 0 ) : ?>
			AlertMessage.alert(
				'<?php echo esc_js( __( 'データ反映に関するお知らせ', 'qa-heatmap-analytics' ) ); ?>',
				'<?php echo esc_js( __( 'QAアナリティクスからQA Assistantsへのデータ移行処理を実行中です。', 'qa-heatmap-analytics' ) ); ?><br>' +
				'<?php echo esc_js( __( 'レポート画面などでは「データがありません」と表示されますが、データは夜間処理後に反映されます。', 'qa-heatmap-analytics' ) ); ?><br>' +
				'<?php echo esc_js( __( '計測は通常どおり行われていますので、明日以降のデータ反映を楽しみにお待ちください。', 'qa-heatmap-analytics' ) ); ?><br><br>' +
				'<?php echo esc_js( __( '※このお知らせは更新から最大24時間表示されます。処理完了済みの場合は、閉じてそのままご利用ください。', 'qa-heatmap-analytics' ) ); ?><br><br>' +
				'<a href="https://mem.quarka.org/wpuserinfo-alert20251006/" target="_blank" rel="noopener noreferrer" style="color: #0073aa; text-decoration: underline;"><?php echo esc_js( __( '詳しくはこちら', 'qa-heatmap-analytics' ) ); ?></a>',
				'info'
			);
			<?php else : ?>
			AlertMessage.alert(
				'<?php echo esc_js( __( 'Notice about Data Availability', 'qa-heatmap-analytics' ) ); ?>',
				'<?php echo esc_js( __( 'Your past analytics data is being carried over and prepared for use in QA Assistants.', 'qa-heatmap-analytics' ) ); ?><br>' +
				'<?php echo esc_js( __( 'Reports may show "No data available", but data collection is proceeding as normal and data will be reflected after the nightly process.', 'qa-heatmap-analytics' ) ); ?><br>' +
				'<?php echo esc_js( __( 'Please look forward to seeing your data reflected in the coming days.', 'qa-heatmap-analytics' ) ); ?><br><br>' +
				'<?php echo esc_js( __( 'This notice may appear for up to 24 hours after the update. If processing has already completed, you can close this and continue using the reports as usual.', 'qa-heatmap-analytics' ) ); ?><br><br>' +
				'<a href="https://mem.quarka.org/wpuserinfo-alert20251006/" target="_blank" rel="noopener noreferrer" style="color: #0073aa; text-decoration: underline;"><?php echo esc_js( __( 'Click here for more details.', 'qa-heatmap-analytics' ) ); ?></a>',
				'info'
			);
			<?php endif; ?>
		});
		</script>
		<?php
	}


	/**
	 * QA Assistants のみ
	 * Advanced OFFかつ未dismissのとき、プラグイン配下ページで案内を出す
	 * since March 2026「有効化しました」ページで案内する形になったので、この関数呼び出しはしない
	 */
	public function show_advanced_mode_notice() {
		// 権限・ページ判定
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! method_exists( $this, 'is_qahm_admin_page' ) || ! $this->is_qahm_admin_page() ) {
			return;
		}

		// 「はじめに」画面では表示しない
		if ( isset( $_GET['page'] ) && sanitize_key( wp_unslash( $_GET['page'] ) ) === QAHM_NAME . '-intro' ) {
			return;
		}

		// Advanced OFF を wp_options 'advanced_mode' で判定（true/false想定）
		$advanced_mode = $this->wrap_get_option( 'advanced_mode', false );
		if ( $advanced_mode ) {
			return;
		}

		// 既にユーザーがdismissしていれば出さない
		$dismissed = (bool) get_user_meta( get_current_user_id(), 'qahm_advanced_notice_dismissed', true );
		if ( $dismissed ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=qahm-config' );
		$nonce        = wp_create_nonce( 'qahm_dismiss_advanced_notice' );

		echo '<div class="notice notice-info is-dismissible qahm-advanced-notice"'
		. ' data-qahm-action="qahm_dismiss_advanced_notice"'
		. ' data-qahm-nonce="' . esc_attr( $nonce ) . '">'
		. '<p>'
		. '<span class="dashicons dashicons-info" aria-hidden="true"> </span>'
		. '<span class="screen-reader-text">Info: </span>'
		. '<strong>' . esc_html__( 'Advanced Mode is available', 'qa-heatmap-analytics' ) . '</strong> '
		. sprintf(
				/* translators: %s: link to Advanced tab in Settings */
			esc_html__( 'Enable Advanced Mode to access detailed reports such as Acquisition, Landing Pages, and Goals. You can switch it anytime in %s.', 'qa-heatmap-analytics' ),
			'<a href="' . esc_url( $settings_url ) . '" class="qahm-advanced-link">'
				. esc_html__( 'Settings → Advanced Mode', 'qa-heatmap-analytics' ) . '</a>'
		)
		. '</p></div>';
	}
	// × クリック時にAJAXでdismissを保存
	public function advanced_mode_notice_footer_js() {
		// 同じ条件で出す時だけJSを出力（無駄ロード防止）
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! method_exists( $this, 'is_qahm_admin_page' ) || ! $this->is_qahm_admin_page() ) {
			return;
		}
		// 「はじめに」画面では表示しない
		if ( isset( $_GET['page'] ) && sanitize_key( wp_unslash( $_GET['page'] ) ) === QAHM_NAME . '-intro' ) {
			return;
		}
		if ( $this->wrap_get_option( 'advanced_mode', false ) ) {
			return;
		}
		if ( (bool) get_user_meta( get_current_user_id(), 'qahm_advanced_notice_dismissed', true ) ) {
			return;
		}
		?>
		<script>
			jQuery(document).on('click', '.qahm-advanced-notice .notice-dismiss', function () {
				var $wrap  = jQuery(this).closest('.qahm-advanced-notice');
				var action = $wrap.data('qahm-action');
				var nonce  = $wrap.data('qahm-nonce');
				if (!action || !nonce) return;
				jQuery.post(ajaxurl, { action: action, qahm_dismiss_nonce: nonce });
			});
		</script>
		<?php
	}
	// dismiss保存（ユーザー単位）
	public function ajax_dismiss_advanced_notice() {
		check_ajax_referer( 'qahm_dismiss_advanced_notice', 'qahm_dismiss_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Insufficient permissions.', 'qa-heatmap-analytics' ) ), 403 );
		}
		update_user_meta( get_current_user_id(), 'qahm_advanced_notice_dismissed', 1 );
		wp_send_json_success();
	}
}
