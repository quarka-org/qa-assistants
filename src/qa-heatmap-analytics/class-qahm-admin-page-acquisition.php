<?php
defined( 'ABSPATH' ) || exit;
/**
 *
 *
 * @package analytics_backup_by_qa
 */

$GLOBALS['qahm_admin_page_acquisition'] = new QAHM_Admin_Page_Acquisition();

class QAHM_Admin_Page_Acquisition extends QAHM_Admin_Page_Dataviewer {

	// スラッグ
	const SLUG = QAHM_NAME . '-acquisition';

	// nonce
	const NONCE_ACTION = self::SLUG . '-nonce-action';
	const NONCE_NAME   = self::SLUG . '-nonce-name';

	/**
	 * コンストラクタ
	 */
	public function __construct() {
		parent::__construct();
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
		wp_enqueue_style( QAHM_NAME . '-daterangepicker-css', $css_dir_url . 'lib/date-range-picker/daterangepicker.css', array( QAHM_NAME . '-reset' ), QAHM_PLUGIN_VERSION );

		// enqueue script
		$this->common_enqueue_script();
		wp_enqueue_script( QAHM_NAME . '-chart', $js_dir_url . 'lib/chart/chart.min.js', null, QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-dayjs', $js_dir_url . 'lib/dayjs/dayjs.min.js', null, QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-dayjs-utc', $js_dir_url . 'lib/dayjs/plugin/utc.js', array( QAHM_NAME . '-dayjs' ), QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-dayjs-timezone', $js_dir_url . 'lib/dayjs/plugin/timezone.js', array( QAHM_NAME . '-dayjs' ), QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-moment-with-locales', $js_dir_url . 'lib/moment/moment-with-locales.min.js', null, QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-daterangepicker', $js_dir_url . 'lib/date-range-picker/daterangepicker.js', array( QAHM_NAME . '-dayjs', QAHM_NAME . '-moment-with-locales' ), QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-admin-page-dataviewer', $js_dir_url . 'admin-page-dataviewer.js', array( QAHM_NAME . '-dayjs', QAHM_NAME . '-daterangepicker' ), QAHM_PLUGIN_VERSION );
		wp_enqueue_script( QAHM_NAME . '-admin-page-acquisition', $js_dir_url . 'admin-page-acquisition.js', array( QAHM_NAME . '-admin-page-dataviewer' ), QAHM_PLUGIN_VERSION );

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

		global $qahm_data_api;
		$lang_set = get_bloginfo( 'language' );
		// tracking_id is used only for display switching (no state changes). wp_unslash() and sanitize_text_field() are applied inside sanitize_tracking_id().
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended
		$tracking_id_raw = isset( $_GET['tracking_id'] ) ? $this->sanitize_tracking_id( wp_unslash( $_GET['tracking_id'] ) ) : 'all';
		$tracking_id     = $this->get_safe_tracking_id( $tracking_id_raw );
		$goals_ary       = $qahm_data_api->get_goals_preferences( $tracking_id );
		?>

		<div id="<?php echo esc_attr( basename( __FILE__, '.php' ) ); ?>">
			<div class="qa-zero-content">
				<!-- ヘッダー -->
				<?php $this->create_header( __( 'Acquisition', 'qa-heatmap-analytics' ) ); ?>

				<!-- 期間 -->
				<?php $this->create_date_range(); ?>

				<!-- チャネル -->
				<div class="qa-zero-data-container">
					<div class="qa-zero-data">
						<div class="qa-zero-data__title">
							<svg xmlns="http://www.w3.org/2000/svg" width="25" height="24" viewBox="0 0 25 24" fill="none">
								<g clip-path="url(#clip0_36_30432)">
									<path d="M23.5 11.01L18.5 11C17.95 11 17.5 11.45 17.5 12V21C17.5 21.55 17.95 22 18.5 22H23.5C24.05 22 24.5 21.55 24.5 21V12C24.5 11.45 24.05 11.01 23.5 11.01ZM23.5 20H18.5V13H23.5V20ZM20.5 2H2.5C1.39 2 0.5 2.89 0.5 4V16C0.5 16.5304 0.710714 17.0391 1.08579 17.4142C1.46086 17.7893 1.96957 18 2.5 18H9.5V20H7.5V22H15.5V20H13.5V18H15.5V16H2.5V4H20.5V9H22.5V4C22.5 3.46957 22.2893 2.96086 21.9142 2.58579C21.5391 2.21071 21.0304 2 20.5 2ZM12.47 9L11.5 6L10.53 9H7.5L9.97 10.76L9.03 13.67L11.5 11.87L13.97 13.67L13.03 10.76L15.5 9H12.47Z"/>
								</g>
								<defs>
									<clipPath id="clip0_36_30432">
									<rect width="24" height="24" fill="white" transform="translate(0.5)"/>
									</clipPath>
								</defs>
							</svg>
							<?php esc_html_e( 'Channel', 'qa-heatmap-analytics' ); ?>
						</div>
						<div class="qa-zero-graph">
							<div class="qa-zero-graph__title"><?php echo esc_html__( 'Sessions', 'qa-heatmap-analytics' ); ?></div>
							<div class="graph-legend"></div>
							<div class="qa-zero-graph--large">
								<canvas id="ch-chart-canvas"></canvas>
							</div>
						</div>
						<button type="button" id="ch-chart-button" class="qa-zero-graph-show-button">
							<?php echo esc_html__( 'Plot Rows', 'qa-heatmap-analytics' ); ?>
						</button>
						<div class="qa-zero-radio-button">
						<?php
							echo '<label for="js_chGoals_0"><input type="radio" id="js_chGoals_0" class="qa-zero-radio-button--item" name="js_chGoals" checked>' . esc_html__( 'All Goals', 'qa-heatmap-analytics' ) . '</label>';
						foreach ( $goals_ary as $gid => $goal ) {
							echo '<label for="js_chGoals_' . esc_attr( $gid ) . '"><input type="radio" id="js_chGoals_' . esc_attr( $gid ) . '" class="qa-zero-radio-button--item" name="js_chGoals">' . esc_html( urldecode( $goal['gtitle'] ) ) . '</label>';
						}
						?>
						</div>
						<div id="tb_channels"></div>
					</div>
				</div>

				<!-- 参照元/メディア -->
				<div class="qa-zero-data-container">
					<div class="qa-zero-data">
						<div class="qa-zero-data__title">
							<svg xmlns="http://www.w3.org/2000/svg" width="25" height="24" viewBox="0 0 25 24" fill="none">
								<g clip-path="url(#clip0_36_30432)">
									<path d="M23.5 11.01L18.5 11C17.95 11 17.5 11.45 17.5 12V21C17.5 21.55 17.95 22 18.5 22H23.5C24.05 22 24.5 21.55 24.5 21V12C24.5 11.45 24.05 11.01 23.5 11.01ZM23.5 20H18.5V13H23.5V20ZM20.5 2H2.5C1.39 2 0.5 2.89 0.5 4V16C0.5 16.5304 0.710714 17.0391 1.08579 17.4142C1.46086 17.7893 1.96957 18 2.5 18H9.5V20H7.5V22H15.5V20H13.5V18H15.5V16H2.5V4H20.5V9H22.5V4C22.5 3.46957 22.2893 2.96086 21.9142 2.58579C21.5391 2.21071 21.0304 2 20.5 2ZM12.47 9L11.5 6L10.53 9H7.5L9.97 10.76L9.03 13.67L11.5 11.87L13.97 13.67L13.03 10.76L15.5 9H12.47Z"/>
								</g>
								<defs>
									<clipPath id="clip0_36_30432">
									<rect width="24" height="24" fill="white" transform="translate(0.5)"/>
									</clipPath>
								</defs>
							</svg>
							<?php esc_html_e( 'Source / Medium', 'qa-heatmap-analytics' ); ?>
						</div>
						<div class="qa-zero-graph">
							<div class="qa-zero-graph__title"><?php echo esc_html__( 'Sessions', 'qa-heatmap-analytics' ); ?></div>
							<div class="graph-legend"></div>
							<div class="qa-zero-graph--large">
								<canvas id="sm-chart-canvas"></canvas>
							</div>
						</div>
						<button type="button" id="sm-chart-button" class="qa-zero-graph-show-button">
							<?php echo esc_html__( 'Plot Rows', 'qa-heatmap-analytics' ); ?>
						</button>
						<div class="qa-zero-radio-button">
						<?php
							echo '<label for="js_smGoals_0"><input type="radio" id="js_smGoals_0" class="qa-zero-radio-button--item" name="js_smGoals" checked>' . esc_html__( 'All Goals', 'qa-heatmap-analytics' ) . '</label>';
						foreach ( $goals_ary as $gid => $goal ) {
							echo '<label for="js_smGoals_' . esc_attr( $gid ) . '"><input type="radio" id="js_smGoals_' . esc_attr( $gid ) . '" class="qa-zero-radio-button--item" name="js_smGoals">' . esc_html( urldecode( $goal['gtitle'] ) ) . '</label>';
						}
						?>
						</div>
						<div id="tb_sourceMedium"></div>
					</div>
				</div>

				<?php $this->create_footer_follow(); ?>
			</div>
		</div>

		<?php
	}
} // end of class
