<?php
defined( 'ABSPATH' ) || exit;
/**
 *
 *
 * @package analytics_backup_by_qa
 */

$GLOBALS['qahm_admin_page_user'] = new QAHM_Admin_Page_User();

class QAHM_Admin_Page_User extends QAHM_Admin_Page_Dataviewer {

	// スラッグ
	const SLUG = QAHM_NAME . '-user';

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
		wp_enqueue_style( QAHM_NAME . '-admin-page-chart', $css_dir_url . 'admin-page-chart.css', array( QAHM_NAME . '-reset' ), QAHM_PLUGIN_VERSION );

		// enqueue script
		$this->common_enqueue_script();
		wp_enqueue_script( QAHM_NAME . '-chart', $js_dir_url . 'lib/chart/chart.min.js', null, QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-dayjs', $js_dir_url . 'lib/dayjs/dayjs.min.js', null, QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-dayjs-utc', $js_dir_url . 'lib/dayjs/plugin/utc.js', array( QAHM_NAME . '-dayjs' ), QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-dayjs-timezone', $js_dir_url . 'lib/dayjs/plugin/timezone.js', array( QAHM_NAME . '-dayjs' ), QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-moment-with-locales', $js_dir_url . 'lib/moment/moment-with-locales.min.js', null, QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-daterangepicker', $js_dir_url . 'lib/date-range-picker/daterangepicker.js', array( QAHM_NAME . '-dayjs', QAHM_NAME . '-moment-with-locales' ), QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-admin-page-dataviewer', $js_dir_url . 'admin-page-dataviewer.js', array( QAHM_NAME . '-dayjs', QAHM_NAME . '-daterangepicker' ), QAHM_PLUGIN_VERSION );
		wp_enqueue_script( QAHM_NAME . '-admin-page-user', $js_dir_url . 'admin-page-user.js', array( QAHM_NAME . '-admin-page-dataviewer' ), QAHM_PLUGIN_VERSION );
		wp_enqueue_script( QAHM_NAME . '-cap-create', $js_dir_url . 'cap-create.js', array( QAHM_NAME . '-effect' ), QAHM_PLUGIN_VERSION );

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
		// tracking_id is used only for display switching (no state changes). wp_unslash() and sanitize_text_field() are applied inside sanitize_tracking_id().
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended
		$tracking_id_raw = isset( $_GET['tracking_id'] ) ? $this->sanitize_tracking_id( wp_unslash( $_GET['tracking_id'] ) ) : 'all';
		$tracking_id     = $this->get_safe_tracking_id( $tracking_id_raw );
		$goals_ary       = $qahm_data_api->get_goals_preferences( $tracking_id );
		?>

		<div id="<?php echo esc_attr( basename( __FILE__, '.php' ) ); ?>">
			<div class="qa-zero-content">
				<!-- ヘッダー -->
				<?php $this->create_header( __( 'Audience', 'qa-heatmap-analytics' ) ); ?>

				<?php
				if ( QAHM_TYPE === QAHM_TYPE_WP ) {
					$is_advanced_mode = $this->wrap_get_option( 'advanced_mode', false );
					if ( ! $is_advanced_mode ) {
						?>
						<p class="qahm-mode-hint" style="margin:2px 10px 10px;color:#666;font-size:12.5px;line-height:1.4;display:block">
						<?php esc_html_e( 'Viewing in Simple Mode. Additional reports are available in Advanced Mode.', 'qa-heatmap-analytics' ); ?>
						</p>
						<?php
					}
				}

				?>

				<!-- 期間 -->
				<?php $this->create_date_range(); ?>

				<!-- データ -->
				<div class="qa-zero-data-container">
					<div class="qa-zero-data">
						<div class="qa-zero-data__title">
							<svg xmlns="http://www.w3.org/2000/svg" width="21" height="14" viewBox="0 0 21 14" fill="none">
								<path d="M14.1364 6C15.6455 6 16.8545 4.66 16.8545 3C16.8545 1.34 15.6455 0 14.1364 0C12.6273 0 11.4091 1.34 11.4091 3C11.4091 4.66 12.6273 6 14.1364 6ZM6.86364 6C8.37273 6 9.58182 4.66 9.58182 3C9.58182 1.34 8.37273 0 6.86364 0C5.35455 0 4.13636 1.34 4.13636 3C4.13636 4.66 5.35455 6 6.86364 6ZM6.86364 8C4.74545 8 0.5 9.17 0.5 11.5V14H13.2273V11.5C13.2273 9.17 8.98182 8 6.86364 8ZM14.1364 8C13.8727 8 13.5727 8.02 13.2545 8.05C14.3091 8.89 15.0455 10.02 15.0455 11.5V14H20.5V11.5C20.5 9.17 16.2545 8 14.1364 8Z"/>
							</svg>
							<?php esc_html_e( 'Overview', 'qa-heatmap-analytics' ); ?>
						</div>
						<div class="qa-zero-graph">
							<div id="chart1-legend" class="graph-legend"></div>
							<div class="qa-zero-graph--large">
								<canvas id="statsChart1"></canvas>
							</div>
						</div>

						<div class="qa-zero-data-box-wrapper">
							<div class="qa-zero-data-box">
								<div class="qa-zero-data-box__title">
								<?php esc_html_e( 'Users', 'qa-heatmap-analytics' ); ?>
								</div>
								<div class="qa-zero-data-box__value qa-zero-data-box--highlight">
								<span id="qa-zero-num-readers">--</span>
								</div>
							</div>
							<div class="qa-zero-data-box">
								<div class="qa-zero-data-box__title">
								<?php esc_html_e( 'Sessions', 'qa-heatmap-analytics' ); ?>
								</div>
								<div class="qa-zero-data-box__value qa-zero-data-box--highlight">
								<span id="qa-zero-num-sessions">--</span>
								</div>
							</div>
							<div class="qa-zero-data-box">
								<div class="qa-zero-data-box__title">
								<?php esc_html_e( 'Pageviews', 'qa-heatmap-analytics' ); ?>
								</div>
								<div class="qa-zero-data-box__value qa-zero-data-box--highlight">
								<span id="qa-zero-num-pvs">--</span>
								</div>
							</div>
						</div>
					</div>
				</div>

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
							<?php esc_html_e( 'Users / Device', 'qa-heatmap-analytics' ); ?>
						</div>
						<div class="qa-zero-radio-button">
						<?php
							echo '<label for="js_nrdGoals_0"><input type="radio" id="js_nrdGoals_0" class="qa-zero-radio-button--item" name="js_nrdGoals" checked>' . esc_html__( 'All Goals', 'qa-heatmap-analytics' ) . '</label>';
						foreach ( $goals_ary as $gid => $goal ) {
							echo '<label for="js_nrdGoals_' . esc_attr( $gid ) . '"><input type="radio" id="js_nrdGoals_' . esc_attr( $gid ) . '" class="qa-zero-radio-button--item" name="js_nrdGoals">' . esc_html( urldecode( $goal['gtitle'] ) ) . '</label>';
						}
						?>
						</div>
						<div id="tb_audienceDevice"></div>
					</div>
				</div>

				<!-- セッションレコーディング -->
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
							<?php echo esc_html( __( 'Session Recordings', 'qa-heatmap-analytics' ) ); ?>
						</div>
						<div class="qa-zero-radio-button">
						<?php
							echo '<label for="js_sesRec_0"><input type="radio" id="js_sesRec_0" class="qa-zero-radio-button--item" name="js_sesRec" checked><span id="all_session">' . esc_html__( 'All Sessions', 'qa-heatmap-analytics' ) . '</span></label>';
						if ( $goals_ary ) {
							echo '<label for="js_sesRec_1"><input type="radio" id="js_sesRec_1" class="qa-zero-radio-button--item" name="js_sesRec">' . esc_html__( 'All Goals', 'qa-heatmap-analytics' ) . '</label>';
						}

						foreach ( $goals_ary as $gid => $goal ) {
							echo '<label for="js_sesRec_' . esc_attr( $gid + 1 ) . '"><input type="radio" id="js_sesRec_' . esc_attr( $gid + 1 ) . '" class="qa-zero-radio-button--item" name="js_sesRec">' . esc_html( urldecode( $goal['gtitle'] ) ) . '</label>';
						}
						?>
						</div>
						<div id="sday_table"></div>
					</div>
				</div>
				
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
							<?php echo esc_html( __( 'Advanced Export', 'qa-heatmap-analytics' ) ); ?>
						</div>
						<div class="qa-zero-download">
							<input type="button" id="csv-download-btn" class="qa-zero-download__button" value="<?php esc_attr_e( 'Download Pageview-Level Data (TSV)', 'qa-heatmap-analytics' ); ?>">
							reader_id, UAos, UAbrowser, url, title, device_id, utm_source, source_domain, utm_medium, utm_campaign, session_no, access_time, pv, speed_msec, browse_sec, is_last, is_newuser
						</div>
					</div>
				</div>

				<?php $this->create_footer_follow(); ?>
			</div>
		</div>
		<?php
	}
} // end of class
