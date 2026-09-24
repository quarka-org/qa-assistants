<?php
defined( 'ABSPATH' ) || exit;
/**
 *
 *
 * @package qa_heatmap_analytics
 */

$qahm_admin_page_assistant = new QAHM_Admin_Page_Assistant();

class QAHM_Admin_Page_Assistant extends QAHM_Admin_Page_Dataviewer {

	// スラッグ
	const SLUG = QAHM_NAME . '-assistant';

	// nonce
	const NONCE_ACTION = self::SLUG . '-nonce-action';
	const NONCE_NAME   = self::SLUG . '-nonce-name';

	/**
	 * コンストラクタ
	 */
	public function __construct() {
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

		$css_dir_url = $this->get_css_dir_url();
		$js_dir_url  = $this->get_js_dir_url();

		// enqueue style
		$this->common_enqueue_style();
		wp_enqueue_style( QAHM_NAME . '-daterangepicker-css', $css_dir_url . 'lib/date-range-picker/daterangepicker.css', null, QAHM_PLUGIN_VERSION );
		wp_enqueue_style( QAHM_NAME . '-echarts-wrapper', $css_dir_url . 'qahm-echarts.css', null, QAHM_PLUGIN_VERSION );

		// Differs between ZERO and QA - Start ----------
		// ZEROで読み込むファイル
		if ( QAHM_TYPE === QAHM_TYPE_ZERO ) {
			wp_enqueue_style( QAHM_NAME . '-admin-page-assistant', $css_dir_url . 'admin-page-assistant-zero.css', array( QAHM_NAME . '-common' ), QAHM_PLUGIN_VERSION );
			// QAで読み込むファイル
		} elseif ( QAHM_TYPE === QAHM_TYPE_WP ) {
			wp_enqueue_style( QAHM_NAME . '-admin-page-assistant', $css_dir_url . 'admin-page-assistant-wp.css', array( QAHM_NAME . '-common' ), QAHM_PLUGIN_VERSION );
		}
		// Differs between ZERO and QA - End ----------

		// enqueue script
		$this->common_enqueue_script();
		// DOMPurify（#1424・表現力フェーズ3）: table 列 type:"html" / 会話 html step のサニタイズ用。
		// アシスタント画面のみで読み込む（qa-table 本体の依存に足すと全管理画面ロードになるため足さない。
		// 未ロード画面で type:"html" が使われた場合は qa-table / ヘルパ側の escape フォールバックが安全側に倒す）。
		wp_enqueue_script( QAHM_NAME . '-dompurify', $js_dir_url . 'lib/dompurify/purify.min.js', null, QAHM_PLUGIN_VERSION, false );
		// 共有 HTML サニタイザ（#1426）: 会話 html step と table 列 type:"html" が同じ許可基準を通る共有ヘルパ。
		wp_enqueue_script( QAHM_NAME . '-html-sanitizer', $js_dir_url . 'qahm-html-sanitizer.js', array( QAHM_NAME . '-dompurify' ), QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-echarts', $js_dir_url . 'lib/echarts/echarts.custom.min.js', null, QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-echarts-wrapper', $js_dir_url . 'lib/echarts/qahm-echarts.js', array( QAHM_NAME . '-echarts' ), QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-sortable', $js_dir_url . 'lib/sortable/Sortable.min.js', null, QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-dayjs', $js_dir_url . 'lib/dayjs/dayjs.min.js', null, QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-dayjs-utc', $js_dir_url . 'lib/dayjs/plugin/utc.js', array( QAHM_NAME . '-dayjs' ), QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-dayjs-timezone', $js_dir_url . 'lib/dayjs/plugin/timezone.js', array( QAHM_NAME . '-dayjs' ), QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-moment-with-locales', $js_dir_url . 'lib/moment/moment-with-locales.min.js', null, QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-daterangepicker', $js_dir_url . 'lib/date-range-picker/daterangepicker.js', array( QAHM_NAME . '-moment-with-locales' ), QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-admin-page-dataviewer', $js_dir_url . 'admin-page-dataviewer.js', array( QAHM_NAME . '-daterangepicker' ), QAHM_PLUGIN_VERSION );
		// 期間カレンダー（Cally アダプタ）— #1341。register はコア common_enqueue_script()。dayjs/utc/tz は上で enqueue 済み。
		wp_enqueue_style( QAHM_NAME . '-daterange' );
		wp_enqueue_script( QAHM_NAME . '-daterange' );
		// 2024/09/20現在未使用なのでコメントアウト
		//wp_enqueue_script( QAHM_NAME . '-admin-page-assistant', $js_dir_url . 'admin-page-assistant.js', array( QAHM_NAME . '-admin-page-dataviewer' ), QAHM_PLUGIN_VERSION );
		wp_enqueue_script( QAHM_NAME . '-conversation-ui', $js_dir_url . 'conversation-ui.js', array( QAHM_NAME . '-admin-page-dataviewer' ), QAHM_PLUGIN_VERSION );
		// step レジストリ（#1449）: step dispatch の単一テーブル。アダプタ群より先にロードする
		// （登録の受け皿。resolve 順はレジストリ内の正準順序表が固定するため、ロード順は優先度に影響しない）。
		wp_enqueue_script( QAHM_NAME . '-assistant-step-registry', $js_dir_url . 'qahm-assistant-step-registry.js', array(), QAHM_PLUGIN_VERSION );
		wp_enqueue_script( QAHM_NAME . '-assistant-table', $js_dir_url . 'qahm-assistant-table.js', array( QAHM_NAME . '-conversation-ui', QAHM_NAME . '-html-sanitizer' ), QAHM_PLUGIN_VERSION );
		wp_enqueue_script( QAHM_NAME . '-assistant-chart', $js_dir_url . 'qahm-assistant-chart.js', array( QAHM_NAME . '-echarts-wrapper' ), QAHM_PLUGIN_VERSION );
		wp_enqueue_script( QAHM_NAME . '-assistant-scorecard', $js_dir_url . 'qahm-assistant-scorecard.js', array( QAHM_NAME . '-conversation-ui' ), QAHM_PLUGIN_VERSION );
		// blocks は callout/divider/html の step を registry へ自己登録し（#1449 PR-D2）、
		// html step が共有サニタイザを使うため、両方を依存に明示する。
		wp_enqueue_script( QAHM_NAME . '-assistant-blocks', $js_dir_url . 'qahm-assistant-blocks.js', array( QAHM_NAME . '-conversation-ui', QAHM_NAME . '-assistant-step-registry', QAHM_NAME . '-html-sanitizer' ), QAHM_PLUGIN_VERSION );
		wp_enqueue_script( QAHM_NAME . '-assistant-ui', $js_dir_url . 'qahm-assistant-ui.js', array( QAHM_NAME . '-assistant-table', QAHM_NAME . '-daterange' ), QAHM_PLUGIN_VERSION );
		wp_enqueue_script( QAHM_NAME . '-assistant-runtime', $js_dir_url . 'qahm-assistant-runtime.js', array( QAHM_NAME . '-assistant-step-registry', QAHM_NAME . '-assistant-ui', QAHM_NAME . '-assistant-chart', QAHM_NAME . '-assistant-scorecard', QAHM_NAME . '-assistant-blocks', QAHM_NAME . '-html-sanitizer' ), QAHM_PLUGIN_VERSION );
		wp_enqueue_script( QAHM_NAME . '-zero-qa-assistant-ai', $js_dir_url . 'assistant-ai.js', array( QAHM_NAME . '-assistant-runtime' ), QAHM_PLUGIN_VERSION );
		wp_enqueue_script( QAHM_NAME . '-assistant-ai-legacy', $js_dir_url . 'assistant-ai-legacy.js', array( QAHM_NAME . '-zero-qa-assistant-ai' ), QAHM_PLUGIN_VERSION );
		// 会話エクスポータ（#1535）: launcher が「この会話を保存」ボタンを取り付けるために参照する。
		// runtime（runLog）に読みで依存するため runtime の後・launcher の前にロードする。
		// Issue #1593: 保存 UI は描画ゲート（既定 OFF）＝QAHM_ASSISTANT_EXPORT_UI が真のときだけ enqueue する。
		// OFF のとき launcher は「exporter 未ロード環境では静かにスキップ」（#1535 設計）に乗る＝ボタンは DOM に出ない。
		// ⚠️ launcher の依存配列も同じゲートで条件化すること＝exporter を未登録のまま依存に残すと、
		// WP が launcher ごと黙って読み込まなくなる（アシスタントが起動しない）。
		$export_ui_on  = defined( 'QAHM_ASSISTANT_EXPORT_UI' ) && QAHM_ASSISTANT_EXPORT_UI;
		$launcher_deps = array( QAHM_NAME . '-zero-qa-assistant-ai' );
		if ( $export_ui_on ) {
			wp_enqueue_script( QAHM_NAME . '-assistant-exporter', $js_dir_url . 'qahm-assistant-exporter.js', array( QAHM_NAME . '-assistant-runtime' ), QAHM_PLUGIN_VERSION );
			$launcher_deps[] = QAHM_NAME . '-assistant-exporter';
		}
		wp_enqueue_script( QAHM_NAME . '-assistant-ai-manifest', $js_dir_url . 'assistant-ai-manifest.js', $launcher_deps, QAHM_PLUGIN_VERSION );
		// inline script
		$this->regist_inline_script();

		// localize
		$this->regist_localize_script();
	}

	/**
	 * ページの表示
	 */
	public function create_html() {
		if ( ! $this->is_enqueue_jquery() ) {
			$this->print_not_enqueue_jquery_html();
			return;
		}

		if ( $this->is_maintenance() ) {
			$this->print_maintenance_html();
			return;
		}
		?>

		<div id="<?php echo esc_attr( basename( __FILE__, '.php' ) ); ?>">
			<div class="qa-zero-content">
				<!-- ヘッダー -->
				<?php $this->create_header( _x( 'Explore', 'Admin screen label', 'qa-heatmap-analytics' ) ); ?>

				<!-- Assistants 選択画面 -->
				<div id="qahm-assistant-selector"></div>

				<!-- Assistants 会話画面 -->
				<div id="this_page_is_assistantpage">
					<div id="assistant_top" data-assistant-open="true"></div>
				</div>

			</div>
		</div>
		<?php
	}
}// end of class
