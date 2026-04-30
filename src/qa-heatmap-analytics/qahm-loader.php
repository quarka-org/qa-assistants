<?php
defined( 'ABSPATH' ) || exit;

/*
 * このファイルは “require の羅列” になっていて一見ごちゃごちゃしているが、
 * プラグイン構造上これは意図的な設計（重要）。
 *
 * 本当は Loader を関数化して整理したいところだが（可読性・保守性の面では理想）、
 * 本プラグインは多くのクラスが「読み込み時にグローバル初期化を行う構造」のため、
 * require_once を関数内で実行すると正常に初期化されず、致命的エラーを引き起こす。
 *
 * - require/include は「呼び出し元のスコープ」で実行される。
 *   関数内で実行すると、初期化処理が関数スコープに閉じ込められてしまう。
 *
 * - その結果、QAHM_DB / QAHM_File_Base などが初期化されず、
 *   wrap_get_contents() のようなメソッド呼び出しが null で Fatal Error になる。
 *
 * - したがって require_once は「必ずグローバルスコープで実行」する必要がある。
 *   共通化できるのはパス解決（ファイル位置の判定）まで。
 *
 * - 結論：ローダーは“グローバル直書き require”が最も安定し、唯一安全に動作する形。
 *
 * この理由から、このファイルはあえてまとまっていないように見える。
 * しかし、現状のプラグインアーキテクチャにおいてはこれが最適解である。
 */

require_once __DIR__ . '/copyrights.php';

$qahm_time_start = microtime( true );

// filesystem_methodが direct or ftpextじゃなければヘルプリンクを表示
require_once ABSPATH . 'wp-admin/includes/file.php';
$access_type = get_filesystem_method();
if ( ! ( $access_type === 'direct' || $access_type === 'ftpext' ) ) {
	// 直接書き込み権限がない場合は、ユーザーに通知を表示する
	add_action(
		'admin_notices',
		function () {
			echo '<div id="qahm-error-filesystem" class="error notice is-dismissible">';
			echo '<p>';
			printf(
				/* translators: %s is for the plugin name */
				esc_html__( '%s cannot be enabled due to missing write permissions for the files.', 'qa-heatmap-analytics' ),
				esc_html( QAHM_PLUGIN_NAME_SHORT )
			);
			echo ' <a href="' . esc_url( QAHM_DOCUMENTATION_URL ) . '" target="_blank" rel="noopener">';
			esc_html_e( 'See Documentation', 'qa-heatmap-analytics' );
			echo '</a></p>';
			echo '</div>';
		}
	);
	return;
}

// include
require_once __DIR__ . '/vendor/autoload.php';

// qa-configの読み込み。wp-loadパスの関係でdataディレクトリのファイルを読む想定で作っている。それ以外はエラー対策として一応書いているが想定した挙動ではない
if ( file_exists( WP_CONTENT_DIR . '/qa-zero-data/qa-config.php' ) ) {
	require_once WP_CONTENT_DIR . '/qa-zero-data/qa-config.php';
} elseif ( file_exists( __DIR__ . '/qa-config.php' ) ) {
	require_once __DIR__ . '/qa-config.php';
} else {
	require_once dirname( __DIR__, 1 ) . '/' . QAHM_TEXT_DOMAIN . '/qa-config.php';
}

// qa関連ファイルの読み込み
require_once __DIR__ . '/qahm-const.php';
require_once __DIR__ . '/class-qahm-core-base.php';
require_once __DIR__ . '/class-qahm-wp-base.php';
require_once __DIR__ . '/class-qahm-base.php';
require_once __DIR__ . '/class-qahm-time.php';
require_once __DIR__ . '/class-qahm-log.php';
require_once __DIR__ . '/class-qahm-data-encryption.php';
require_once __DIR__ . '/class-qahm-file-base.php';
require_once __DIR__ . '/class-qahm-file-data.php';
require_once __DIR__ . '/class-qahm-db.php';
require_once __DIR__ . '/class-qahm-file-functions.php';
require_once __DIR__ . '/class-qahm-options-functions.php';
require_once __DIR__ . '/class-qahm-database-creator.php';
require_once __DIR__ . '/class-qahm-db-functions.php';
require_once __DIR__ . '/class-qahm-data-processor.php';

if ( file_exists( __DIR__ . '/class-qahm-license.php' ) ) {
	require_once __DIR__ . '/class-qahm-license.php';
} else {
	require_once dirname( __DIR__, 1 ) . '/' . QAHM_TEXT_DOMAIN . '/class-qahm-license.php';
}

require_once __DIR__ . '/class-qahm-update.php';
require_once __DIR__ . '/class-qahm-behavioral-data.php';
require_once __DIR__ . '/class-qahm-view-base.php';
require_once __DIR__ . '/class-qahm-view-heatmap.php';
require_once __DIR__ . '/class-qahm-view-replay.php';
require_once __DIR__ . '/class-qahm-google-api.php';
require_once __DIR__ . '/class-qahm-cron-proc.php';
if ( QAHM_TYPE === QAHM_TYPE_ZERO ) {
	require_once __DIR__ . '/class-qahm-ai-report-generator.php';
}

require_once __DIR__ . '/class-qahm-data-api.php';

// QAL: Both ZERO and QA Assistants (required for manifest-based assistants)
require_once __DIR__ . '/class-qahm-qal-guide.php';
require_once __DIR__ . '/class-qahm-qal-executor.php';
require_once __DIR__ . '/class-qahm-qal-storage.php';
require_once __DIR__ . '/class-qahm-qal-material.php';

// REST API: Specific to ZERO - Start ---------------
if ( QAHM_TYPE === QAHM_TYPE_ZERO ) {
	require_once __DIR__ . '/class-qahm-rest-url-helper.php';
}
if ( QAHM_TYPE === QAHM_TYPE_ZERO ) {
	require_once __DIR__ . '/class-qahm-rest-controller.php';
}
// REST API: Specific to ZERO - End ---------------

// Specific to QA - Start ---------------
if ( QAHM_TYPE === QAHM_TYPE_WP ) {
	if ( file_exists( __DIR__ . '/class-qahm-tracking-tag.php' ) ) {
		require_once __DIR__ . '/class-qahm-tracking-tag.php';
	} else {
		require_once dirname( __DIR__, 1 ) . '/' . QAHM_TEXT_DOMAIN . '/class-qahm-tracking-tag.php';
	}

	require_once __DIR__ . '/class-qahm-page-analysis-assistant.php';
}
// Specific to QA - End ---------------

require_once __DIR__ . '/class-qahm-assistant.php';
require_once __DIR__ . '/class-qahm-assistant-legacy-handler.php';
require_once __DIR__ . '/class-qahm-assistant-manager.php';
require_once __DIR__ . '/class-qahm-assistant-runtime-handler.php';
require_once __DIR__ . '/class-qahm-admin-page-base.php';
require_once __DIR__ . '/class-qahm-admin-page-dataviewer.php';

// Specific to ZERO - Start ---------------
if ( QAHM_TYPE === QAHM_TYPE_ZERO ) {
	require_once __DIR__ . '/class-qahm-admin-page-dashboard.php';
}
// Specific to ZERO - End ---------------

// Specific to QA Assistants - Start ---------------
if ( QAHM_TYPE === QAHM_TYPE_WP ) {
	require_once __DIR__ . '/class-qahm-admin-page-intro.php';
}
// Specific to QA Assistants - End ---------------

require_once __DIR__ . '/class-qahm-admin-page-assistant.php';
require_once __DIR__ . '/class-qahm-admin-page-user.php';
require_once __DIR__ . '/class-qahm-admin-page-acquisition.php';
require_once __DIR__ . '/class-qahm-admin-page-behavior.php';
require_once __DIR__ . '/class-qahm-admin-page-behavior-lp.php';
require_once __DIR__ . '/class-qahm-admin-page-behavior-gw.php';
require_once __DIR__ . '/class-qahm-admin-page-behavior-ap.php';
require_once __DIR__ . '/class-qahm-admin-page-goals.php';
require_once __DIR__ . '/class-qahm-admin-page-realtime.php';
require_once __DIR__ . '/class-qahm-admin-page-config.php';

if ( file_exists( __DIR__ . '/class-qahm-admin-page-entire.php' ) ) {
	require_once __DIR__ . '/class-qahm-admin-page-entire.php';
} else {
	require_once dirname( __DIR__, 1 ) . '/' . QAHM_TEXT_DOMAIN . '/class-qahm-admin-page-entire.php';
}

require_once __DIR__ . '/ip-geolocation/class-qahm-ip-geo.php';
require_once __DIR__ . '/ip-geolocation/class-qahm-country-converter.php';

if ( file_exists( __DIR__ . '/class-qahm-admin-page-license.php' ) ) {
	require_once __DIR__ . '/class-qahm-admin-page-license.php';
} elseif ( file_exists( dirname( __DIR__, 1 ) . '/' . QAHM_TEXT_DOMAIN . '/class-qahm-admin-page-license.php' ) ) {
	require_once dirname( __DIR__, 1 ) . '/' . QAHM_TEXT_DOMAIN . '/class-qahm-admin-page-license.php';
}

if ( file_exists( __DIR__ . '/class-qahm-admin-page-help.php' ) ) {
	require_once __DIR__ . '/class-qahm-admin-page-help.php';
} else {
	require_once dirname( __DIR__, 1 ) . '/' . QAHM_TEXT_DOMAIN . '/class-qahm-admin-page-help.php';
}

require_once __DIR__ . '/class-qahm-activate.php';
require_once __DIR__ . '/class-qahm-admin-init.php';

// QAHM_Version_Managerを常に読み込む（両製品共通）
require_once __DIR__ . '/class-qahm-version-manager.php';

// Specific to ZERO - Start ---------------
// QAHM_Html_Periodic_ProcはQA ZEROのみで読み込む
if ( QAHM_TYPE === QAHM_TYPE_ZERO ) {
	require_once __DIR__ . '/class-qahm-html-periodic-proc.php';
}
// Specific to ZERO - End ---------------

if ( QAHM_TYPE === QAHM_TYPE_ZERO ) {
	add_action(
		'rest_api_init',
		function () {
			$qahm_rest_controller = new QAHM_Rest_Controller();
			$qahm_rest_controller->register_routes();
		}
	);
}

$qahm_loadtime = ( microtime( true ) - $qahm_time_start );
$qahm_loadtime = round( $qahm_loadtime, 5 );

// === QA-LABO-LOADER START ===
// WARNING: このセクションは自動生成されます。直接編集しないでください！
// labo固有の機能を追加する場合は src/core/qa-labo-loader.php を編集してください。
// 詳細は qa-labo-loader.php のコメントを参照してください。
/**
 * QA Labo専用ローダー
 *
 * ============================================================================
 * 重要: qa-laboでlabo固有の機能を追加する場合は、このファイルを編集してください。
 * qahm-loader.phpを直接編集しないでください！
 * ============================================================================
 *
 * このファイルはqa-labo環境専用の開発中ツールの読み込み処理を記述します。
 * qa-platformとqa-labo間の自動マージ時に、このファイルの内容が
 * qahm-loader.phpに追記されることで、labo固有の機能が有効化されます。
 *
 * 【なぜqahm-loader.phpを直接編集してはいけないのか】
 * qa-platformからqa-laboへの自動マージ時に、qahm-loader.phpはqa-platform側の
 * バージョンで上書きされます。その後、このファイル(qa-labo-loader.php)の内容が
 * 自動的に追記されます。直接qahm-loader.phpを編集すると、次回のマージで
 * その変更が失われてしまいます。
 *
 * 【正しいワークフロー】
 * 1. このファイル(qa-labo-loader.php)にlabo固有のrequire文を追加
 * 2. scripts/apply-labo-loader.sh を実行してqahm-loader.phpを更新
 *    （GitHub Actionsでは自動実行されます）
 * 3. 動作確認
 *
 * 【コーディングルール】
 * - qahm-loader.phpと同様、グローバルスコープでのrequireが必要です
 * - 関数化せず、直接requireを記述してください
 * - このファイルは双方向マージの対象外です（labo専用）
 *
 * @package QA_Labo
 */
// ============================================================================
// qa-labo専用の開発中ツールをここに追加
// ============================================================================
//
// 例:
// if ( file_exists( dirname( __FILE__ ) . '/class-qahm-labo-tool.php' ) ) {
//     require_once dirname( __FILE__ ) . '/class-qahm-labo-tool.php';
// }
// === QA-LABO-LOADER END ===
