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

/**
 * QA Platform REST API バージョン / アップデート
 *
 * 2層バージョニング（docs/qal/10-rest-api-specification.md §5 参照）:
 *   - QAHM_API_VERSION : URL に現れる API バージョン（破壊的変更でのみ bump、24ヶ月サポート）
 *   - QAHM_API_UPDATE  : 同一 version 内の後方互換な機能追加日。/guide レスポンスに
 *                        `api_update` として返却され、クライアントが新機能の有無を判定する。
 *
 * 新しい機能（filter の新演算子 / 新マテリアル / 新 calc 関数 / view 拡張 等）を
 * 追加したタイミングで QAHM_API_UPDATE を **その日の日付** に bump すること。
 * 単なるリファクタや typo 修正では bump しない。
 *
 * 参考: docs/handover/T55-developers-doc-api-update.md, /qal-update スキル
 */
const QAHM_API_VERSION = '2026-05-11';
const QAHM_API_UPDATE  = '2026-08-05';

/**
 * アシスタント manifest の spec 版（互換宣言 min_core_version の判定基準・Issue #1433）
 *
 * このコアが実装している「アシスタント機能セットの世代」を表す semver。
 * manifest が min_core_version でこれより上を要求したら、配信時に E_CORE_TOO_OLD
 * （「QA Assistants を更新してください」）を返す（class-qahm-assistant-runtime-handler.php）。
 *
 * 新しい step 型 / 列 type / 表現ブロック等を追加したら MINOR を上げること。
 * 正本 docs/specs/assistant/overview.md の Version と一致させる（定数＝正本＝maker ミラーの三点一致）。
 */
const QAHM_ASSISTANT_SPEC_VERSION = '2.15.0';

const QAHM_DEBUG_LEVEL = array(
	'release' => 0,
	'staging' => 1,
	'debug'   => 2,
);

// GA4 backfill: 夜間cronで遡及取得する月数（現在月の前月から数えてNヶ月）
// GA4 Data API 無料枠の遡及実績上限は14ヶ月。安全マージンで13。
// T60: 毎晩 check_file ベースで未取得月のみ補完する（状態分岐なし）。
const QAHM_GA4_BACKFILL_MONTHS = 13;

const QAHM_CONFIG_GOALMAX = 10;

// #1345: dataLayer 取り込み（#1346）が記録する予約イベント名。
// qtag.js の recordDlPageLocation() が push する名前と一致していること（js/qtag.js 参照）。
const QAHM_DLEVENT_RESERVED_EVENT_NAME = 'qa_page_location';

// #1345: gtype_dlevent の照合対象キーの既定値。#1346 の捕捉仕様が page_location のみを保存するため、
// 現状はこの1つだけが実データとして存在する（項目として持つのは将来の他キー用）。
// ※ QAHM_GOAL_DEFAULTS より前に定義すること（const 配列から参照するため）。
const QAHM_DLEVENT_DEFAULT_KEY = 'page_location';

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
	// #1345: gtype_dlevent（dataLayer の値でゴール判定）用。読むのは gtype_dlevent のときだけ。
	'g_dlkey'         => QAHM_DLEVENT_DEFAULT_KEY,
	'g_dlvalues'      => '',
);

// Config API: category whitelists (shared by RuntimeHandler and Legacy)
const QAHM_CONFIG_READABLE_CATEGORIES = array( 'goals', 'siteinfo', 'custom_data' );
const QAHM_CONFIG_WRITABLE_CATEGORIES = array( 'goals', 'custom_data' );

// custom_data size limits (Issue #1200) — guards against accidental bloat that
// would degrade write performance (O(N) read-merge-write per call) and consume
// disk shared with measurement data under qa-zero-data/.
// Tune via separate PR after observing real-world usage if needed.
const QAHM_CUSTOM_DATA_FILE_MAX_BYTES  = 1048576; // 1 MiB per store file.
const QAHM_CUSTOM_DATA_VALUE_MAX_BYTES = 65536;   // 64 KiB per single value.

// custom_data key count limit (Issue #1228, Stage A) — guards against unbounded key
// accumulation (history/log patterns with URL/timestamp keys). The file size limit
// (QAHM_CUSTOM_DATA_FILE_MAX_BYTES) alone allows ~5,000+ small-value keys before
// triggering, which makes the failure point unpredictable from a manifest author's
// perspective. This explicit count limit lets manifest authors declare intent
// ("keep at most N entries") and pairs with the `rotate` option (config_write) for
// automatic oldest-key eviction.
const QAHM_CUSTOM_DATA_KEY_MAX_COUNT = 1000;

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

if ( ! function_exists( 'qahm_get_utm_label' ) ) {
	/**
	 * UTM パラメータの表示ラベルを取得する。
	 *
	 * QA ZERO 環境では `utm_source` / `utm_medium` / `utm_campaign` を返し、
	 * QA Assistants 環境では `_x()` 経由でテキストドメイン翻訳を返す（msgctxt: 'UTM parameter label'）。
	 *
	 * 返り値は **未エスケープの生文字列**。HTML 出力する場合は呼び出し側で `esc_html()` を適用すること。
	 * CSV / Excel ヘッダー / AI プロンプト文ではそのまま使用可。
	 *
	 * @param string $key 'source' / 'medium' / 'campaign' のいずれか。
	 * @return string ラベル文字列。未知のキーが渡された場合はキー文字列をそのまま返す。
	 */
	function qahm_get_utm_label( $key ) {
		if ( defined( 'QAHM_TYPE' ) && QAHM_TYPE === QAHM_TYPE_ZERO ) {
			$labels = array(
				'source'   => 'utm_source',
				'medium'   => 'utm_medium',
				'campaign' => 'utm_campaign',
			);
			return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
		}
		switch ( $key ) {
			case 'source':
				return _x( 'Source', 'UTM parameter label', 'qa-heatmap-analytics' );
			case 'medium':
				return _x( 'Medium', 'UTM parameter label', 'qa-heatmap-analytics' );
			case 'campaign':
				return _x( 'Campaign', 'UTM parameter label', 'qa-heatmap-analytics' );
			default:
				return $key;
		}
	}
}

if ( ! function_exists( 'qahm_get_utm_select_label' ) ) {
	/**
	 * UTM パラメータ選択 placeholder のラベルを取得する。
	 *
	 * ヒートマップ絞り込みフィルター UI の「Select Source」等で使用。
	 * QA ZERO 環境では `Select utm_source` 等、QA Assistants 環境では `_x()` 経由で翻訳。
	 *
	 * 返り値は **未エスケープの生文字列**。
	 *
	 * @param string $key 'source' / 'medium' / 'campaign' のいずれか。
	 * @return string ラベル文字列。
	 */
	function qahm_get_utm_select_label( $key ) {
		if ( defined( 'QAHM_TYPE' ) && QAHM_TYPE === QAHM_TYPE_ZERO ) {
			$labels = array(
				'source'   => 'Select utm_source',
				'medium'   => 'Select utm_medium',
				'campaign' => 'Select utm_campaign',
			);
			return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
		}
		switch ( $key ) {
			case 'source':
				return _x( 'Select Source', 'UTM parameter select label', 'qa-heatmap-analytics' );
			case 'medium':
				return _x( 'Select Medium', 'UTM parameter select label', 'qa-heatmap-analytics' );
			case 'campaign':
				return _x( 'Select Campaign', 'UTM parameter select label', 'qa-heatmap-analytics' );
			default:
				return $key;
		}
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

// フロントエンド（計測対象サイトの通常表示）で参照される qahm_ オプション。
// wrap_update_option は新規登録時、この配列のキーを autoload='yes'、それ以外を 'no' にする（Issue #1174）。
const QAHM_AUTOLOAD_YES_OPTIONS = ( QAHM_TYPE === QAHM_TYPE_WP )
	? array( 'cb_sup_mode', 'sitemanage' )
	: array( 'cb_sup_mode' );

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
