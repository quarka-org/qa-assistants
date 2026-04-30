<?php
defined( 'ABSPATH' ) || exit;
/**
 *
 *
 * @package analytics_backup_by_qa
 */

$GLOBALS['qahm_admin_page_behavior_gw'] = new QAHM_Admin_Page_Behavior_Gw();

class QAHM_Admin_Page_Behavior_Gw extends QAHM_Admin_Page_Behavior {

	// スラッグ
	const SLUG = QAHM_NAME . '-behavior-gw';

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
		// Retain the base condition check from the parent class
		if ( $this->hook_suffix !== $hook_suffix || ! $this->is_enqueue_jquery() ) {
			return;
		}

		// Use parent method to enqueue shared styles and scripts
		parent::enqueue_scripts( $hook_suffix );
		// Enqueue additional LP-specific styles or scripts here if needed
		$js_dir_url = $this->get_js_dir_url();
		wp_enqueue_script( QAHM_NAME . '-admin-page-behavior-gw', $js_dir_url . 'admin-page-behavior-gw.js', array( QAHM_NAME . '-admin-page-behavior' ), QAHM_PLUGIN_VERSION );
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
				<?php $this->create_header( esc_html__( 'Top Growing', 'qa-heatmap-analytics' ) ); ?>

				<!-- 期間 -->
				<?php $this->create_date_range(); ?>

				<!-- 伸びているランディングページ -->
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
							<?php esc_html_e( 'Top Growing Landing Pages', 'qa-heatmap-analytics' ); ?>
						</div>
						<div class="qa-zero-radio-button">
							<?php
							echo '<label for="js_gwGoals_0"><input type="radio" id="js_gwGoals_0" class="qa-zero-radio-button--item" name="js_gwGoals" checked>' . esc_html__( 'All Goals', 'qa-heatmap-analytics' ) . '</label>';
							foreach ( $goals_ary as $gid => $goal ) {
								echo '<label for="js_gwGoals_' . esc_attr( $gid ) . '"><input type="radio" id="js_gwGoals_' . esc_attr( $gid ) . '" class="qa-zero-radio-button--item" name="js_gwGoals">' . esc_html( urldecode( $goal['gtitle'] ) ) . '</label>';
							}
							?>
						</div>
						<div id="tb_growthpage"></div>
					</div>
				</div>

				<!-- ランディングページ -->
				<div class="qa-zero-data-container" style="visibility: hidden">
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
							<?php esc_html_e( 'Landing Pages', 'qa-heatmap-analytics' ); ?>
						</div>
						<div class="qa-zero-radio-button">
						<?php
							echo '<label for="js_lpGoals_0"><input type="radio" id="js_lpGoals_0" class="qa-zero-radio-button--item" name="js_lpGoals" checked>' . esc_html__( 'All Goals', 'qa-heatmap-analytics' ) . '</label>';
						foreach ( $goals_ary as $gid => $goal ) {
							echo '<label for="js_lpGoals_' . esc_attr( $gid ) . '"><input type="radio" id="js_lpGoals_' . esc_attr( $gid ) . '" class="qa-zero-radio-button--item" name="js_lpGoals">' . esc_html( urldecode( $goal['gtitle'] ) ) . '</label>';
						}
						?>
						</div>
						<div id="tb_landingpage"></div>
					</div>
				</div>

				<!-- 全てのページ -->
				<div class="qa-zero-data-container" style="visibility: hidden">
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
							<?php esc_html_e( 'All Pages', 'qa-heatmap-analytics' ); ?>
						</div>
						<div class="qa-zero-radio-button">
						<?php
							echo '<label for="js_apGoals_0"><input type="radio" id="js_apGoals_0" class="qa-zero-radio-button--item" name="js_apGoals" checked>' . esc_html__( 'All Goals', 'qa-heatmap-analytics' ) . '</label>';
						foreach ( $goals_ary as $gid => $goal ) {
							echo '<label for="js_apGoals_' . esc_attr( $gid ) . '"><input type="radio" id="js_apGoals_' . esc_attr( $gid ) . '" class="qa-zero-radio-button--item" name="js_apGoals">' . esc_html( urldecode( $goal['gtitle'] ) ) . '</label>';
						}
						?>
						</div>
						<div id="tb_allpage"></div>
					</div>
				</div>

				<?php $this->create_footer_follow(); ?>
			</div>
		</div>
		<?php
	}
} // end of class
