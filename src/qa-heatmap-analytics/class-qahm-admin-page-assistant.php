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
		wp_enqueue_style( QAHM_NAME . '-admin-page-chart', $css_dir_url . 'admin-page-chart.css', array( QAHM_NAME . '-reset' ), QAHM_PLUGIN_VERSION );

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
		wp_enqueue_script( QAHM_NAME . '-chart', $js_dir_url . 'lib/chart/chart.min.js', null, QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-sortable', $js_dir_url . 'lib/sortable/Sortable.min.js', null, QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-dayjs', $js_dir_url . 'lib/dayjs/dayjs.min.js', null, QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-dayjs-utc', $js_dir_url . 'lib/dayjs/plugin/utc.js', array( QAHM_NAME . '-dayjs' ), QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-dayjs-timezone', $js_dir_url . 'lib/dayjs/plugin/timezone.js', array( QAHM_NAME . '-dayjs' ), QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-moment-with-locales', $js_dir_url . 'lib/moment/moment-with-locales.min.js', null, QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-daterangepicker', $js_dir_url . 'lib/date-range-picker/daterangepicker.js', array( QAHM_NAME . '-moment-with-locales' ), QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-admin-page-dataviewer', $js_dir_url . 'admin-page-dataviewer.js', array( QAHM_NAME . '-daterangepicker' ), QAHM_PLUGIN_VERSION );
		// 2024/09/20現在未使用なのでコメントアウト
		//wp_enqueue_script( QAHM_NAME . '-admin-page-assistant', $js_dir_url . 'admin-page-assistant.js', array( QAHM_NAME . '-admin-page-dataviewer' ), QAHM_PLUGIN_VERSION );
		wp_enqueue_script( QAHM_NAME . '-conversation-ui', $js_dir_url . 'conversation-ui.js', array( QAHM_NAME . '-admin-page-dataviewer' ), QAHM_PLUGIN_VERSION );
		wp_enqueue_script( QAHM_NAME . '-assistant-table', $js_dir_url . 'qahm-assistant-table.js', array( QAHM_NAME . '-conversation-ui' ), QAHM_PLUGIN_VERSION );
		wp_enqueue_script( QAHM_NAME . '-assistant-ui', $js_dir_url . 'qahm-assistant-ui.js', array( QAHM_NAME . '-assistant-table' ), QAHM_PLUGIN_VERSION );
		wp_enqueue_script( QAHM_NAME . '-assistant-runtime', $js_dir_url . 'qahm-assistant-runtime.js', array( QAHM_NAME . '-assistant-ui' ), QAHM_PLUGIN_VERSION );
		wp_enqueue_script( QAHM_NAME . '-zero-qa-assistant-ai', $js_dir_url . 'assistant-ai.js', array( QAHM_NAME . '-assistant-runtime' ), QAHM_PLUGIN_VERSION );
		wp_enqueue_script( QAHM_NAME . '-assistant-ai-legacy', $js_dir_url . 'assistant-ai-legacy.js', array( QAHM_NAME . '-zero-qa-assistant-ai' ), QAHM_PLUGIN_VERSION );
		wp_enqueue_script( QAHM_NAME . '-assistant-ai-manifest', $js_dir_url . 'assistant-ai-manifest.js', array( QAHM_NAME . '-zero-qa-assistant-ai' ), QAHM_PLUGIN_VERSION );
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

				<?php $this->create_footer_follow(); ?>
			</div>
		</div>
		<?php
	}
}// end of class
