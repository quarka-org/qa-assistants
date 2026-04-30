<?php
defined( 'ABSPATH' ) || exit;
/**
 * 定数の宣言
 *
 * constを優先して使用。defineでのみ対応可能な定数はdefine
 *
 * @package qa_heatmap
 */

// プラグイン情報設定
// ここを通るのは開発環境でqtag.phpやqahm-ajax.phpなど直接実行したとき

if ( ! defined( 'QAHM_TYPE_ZERO' ) ) {
	define( 'QAHM_TYPE_ZERO', 1 );
}
if ( ! defined( 'QAHM_TYPE_WP' ) ) {
	define( 'QAHM_TYPE_WP', 2 );
}
$qahm_plugin_type = get_option( 'qahm_plugin_type' );

switch ( $qahm_plugin_type ) {
	case 'qa-zero':
		$main_file = WP_PLUGIN_DIR . '/qa-zero/qahm.php';
		if ( ! defined( 'QAHM_TYPE' ) ) {
			define( 'QAHM_TYPE', QAHM_TYPE_ZERO );
		}
		break;
	case 'qa-heatmap-analytics':
		$main_file = WP_PLUGIN_DIR . '/qa-heatmap-analytics/qahm.php';
		if ( ! defined( 'QAHM_TYPE' ) ) {
			define( 'QAHM_TYPE', QAHM_TYPE_WP );
		}
		break;
	default:
		if ( ! defined( 'QAHM_TYPE' ) ) {
			define( 'QAHM_TYPE', null );
		}
		$main_file = null;
		break;
}

if ( $main_file && file_exists( $main_file ) ) {
	$qahm_plugin_data = get_file_data(
		$main_file,
		array(
			'name'        => 'Plugin Name',
			'version'     => 'Version',
			'text_domain' => 'Text Domain',
		)
	);
	if ( ! defined( 'QAHM_PLUGIN_NAME' ) ) {
		define( 'QAHM_PLUGIN_NAME', $qahm_plugin_data['name'] );
	}
	if ( ! defined( 'QAHM_PLUGIN_VERSION' ) ) {
		define( 'QAHM_PLUGIN_VERSION', $qahm_plugin_data['version'] );
	}
	if ( ! defined( 'QAHM_TEXT_DOMAIN' ) ) {
		define( 'QAHM_TEXT_DOMAIN', $qahm_plugin_data['text_domain'] );
	}
} else {
	// 無効 or ファイルが見つからない場合
	if ( ! defined( 'QAHM_PLUGIN_NAME' ) ) {
		define( 'QAHM_PLUGIN_NAME', null );
	}
	if ( ! defined( 'QAHM_PLUGIN_VERSION' ) ) {
		define( 'QAHM_PLUGIN_VERSION', null );
	}
	if ( ! defined( 'QAHM_TEXT_DOMAIN' ) ) {
		define( 'QAHM_TEXT_DOMAIN', null );
	}
}

// プラグイン用
const QAHM_NAME          = 'qahm';
const QAHM_OPTION_PREFIX = QAHM_NAME . '_';

const QAHM_DEBUG_LEVEL = array(
	'release' => 0,
	'staging' => 1,
	'debug'   => 2,
);

const QAHM_CONFIG_GOALMAX = 10;

// Config API: goal field defaults (shared by RuntimeHandler, Legacy, and Data API)
const QAHM_GOAL_DEFAULTS = array(
	'gtitle'          => '',
	'gnum_scale'      => '',
	'gnum_value'      => '',
	'gtype'           => 'gtype_page',
	'g_goalpage'      => '',
	'g_pagematch'     => 'pagematch_complete',
	'g_clickpage'     => '',
	'g_eventtype'     => '',
	'g_clickselector' => '',
	'g_eventselector' => '',
);

// Config API: category whitelists (shared by RuntimeHandler and Legacy)
const QAHM_CONFIG_READABLE_CATEGORIES = array( 'goals', 'siteinfo' );
const QAHM_CONFIG_WRITABLE_CATEGORIES = array( 'goals' );

const QAHM_DEVICES = array(
	'desktop'    => array(
		'name'         => 'dsk',
		'id'           => 1,
		'display_name' => 'desktop',
	),
	'tablet'     => array(
		'name'         => 'tab',
		'id'           => 2,
		'display_name' => 'tablet',
	),
	'smartphone' => array(
		'name'         => 'smp',
		'id'           => 3,
		'display_name' => 'mobile',
	),
);


/**
 * サイトやドキュメントのURLを定義
 */
if ( ! defined( 'QAHM_PRODUCT_SITE_URL' ) ) {
	switch ( defined( 'QAHM_TYPE' ) ? QAHM_TYPE : null ) {
		case QAHM_TYPE_ZERO:
			define( 'QAHM_PRODUCT_SITE_URL', 'https://qazero.com/' );
			break;

		case QAHM_TYPE_WP:
		default:
			define( 'QAHM_PRODUCT_SITE_URL', 'https://quarka.org/' );
			break;
	}
}
if ( ! defined( 'QAHM_DOCUMENTATION_URL' ) ) {
	switch ( defined( 'QAHM_TYPE' ) ? QAHM_TYPE : null ) {
		case QAHM_TYPE_ZERO:
			define( 'QAHM_DOCUMENTATION_URL', 'https://docs.qazero.com/' );
			break;

		case QAHM_TYPE_WP:
		default:
			define( 'QAHM_DOCUMENTATION_URL', 'https://docs.quarka.org/docs/user-manual' );
			break;
	}
}
/**
 * 表記用のプラグイン名
 */
if ( ! defined( 'QAHM_PLUGIN_NAME_SHORT' ) ) {
	switch ( defined( 'QAHM_TYPE' ) ? QAHM_TYPE : null ) {
		case QAHM_TYPE_ZERO:
			define( 'QAHM_PLUGIN_NAME_SHORT', 'QA ZERO' );
			break;

		case QAHM_TYPE_WP:
		default:
			define( 'QAHM_PLUGIN_NAME_SHORT', 'QA Assistants' );
			break;
	}
}
if ( ! defined( 'QAHM_PLUGIN_NAME_FOR_MAIL' ) ) {
	switch ( defined( 'QAHM_TYPE' ) ? QAHM_TYPE : null ) {
		case QAHM_TYPE_ZERO:
			define( 'QAHM_PLUGIN_NAME_FOR_MAIL', 'QA ZERO' );
			break;

		case QAHM_TYPE_WP:
		default:
			define( 'QAHM_PLUGIN_NAME_FOR_MAIL', 'QA Assistants' );
			break;
	}
}



// qahm用のwp_option 右は初期値
// ここに登録したパラメーターはアンインストール時にwp_optionsから自動で削除される
const QAHM_OPTIONS = array(
	'achievements'              => '',
	'advanced_mode'             => false,
	'cb_sup_mode'               => 'yes',
	'license_authorized'        => false,
	'license_options'           => '',
	'license_wp_domain'         => '',
	'license_key'               => '',
	'license_id'                => '',
	'license_message'           => '',
	'license_activate_time'     => 0,
	'plugin_version'            => QAHM_PLUGIN_VERSION,
	'is_first_heatmap_setting'  => true,
	'send_email_address'        => '',
	'siteinfo'                  => '',
	'sitemanage'                => null,
	'goals'                     => '',
	'over_mail_time'            => 0,
	'pv_limit_rate'             => 0,
	'pv_over_mail_month'        => null,
	'pv_warning_mail_month'     => null,
	'qaz_pid'                   => 0,
	'google_credentials'        => '',
	'google_is_redirect'        => false,
	'v5_data_unavailable_state' => array(
		'pending'   => false,
		'timestamp' => 0,
	),
	'intro_completed'           => false,
);

// アンインストール時に削除する専用のオプションを羅列していく。
// こちらの配列には、今は使用していないが旧バージョンで使用していたQAHM_OPTIONSのパラメータを追加するイメージ
const QAHM_UNINSTALL_OPTIONS = array(
	'access_role',
	'announce_friend_plan',
	'campaign_oneyear_popup',
	'cap_article',
	'cron_exec_date',
	'email_notice',
	'data_save_month',
	'data_save_pv',
	'data_retention_days',
	'data_retention_dur',
	'heatmap_measure_max',
	'heatmap_sort_rec',
	'heatmap_sort_view',
	'is_raw_save_all',
	'license_password',
	'license_plan', // since ZERO license changed
	'license_plans', // since ZERO license changed
	'plugin_type',
	'qa_sitemanage_version',
	'search_params',
	'recterm_version',
);

const QAHM_DB_OPTIONS = array(
	'qa_readers_version'           => 3,
	'qa_pages_version'             => 1,
	'qa_utm_media_version'         => 1,
	'qa_utm_sources_version'       => 1,
	'qa_utm_campaigns_version'     => 1,
	'qa_pv_log_version'            => 1,
	'qa_search_log_version'        => 1,
	'qa_page_version_hist_version' => 1,
	'qa_gsc_query_log_version'     => 1,
	'qa_utm_content_version'       => 1,
);

require_once __DIR__ . '/qahm-const-ignore.php';
require_once __DIR__ . '/qahm-const-domain.php';

// メモリーの初期値など各ファイル共通
const QAHM_MEMORY_LIMIT_MIN = 512;
