<?php
defined( 'ABSPATH' ) || exit;
/**
 * はじめに画面（初期ゲート）
 *
 * @package qa_heatmap
 */

$GLOBALS['qahm_admin_page_intro'] = new QAHM_Admin_Page_Intro();

class QAHM_Admin_Page_Intro extends QAHM_Admin_Page_Base {

	const SLUG = QAHM_NAME . '-intro';

	const NONCE_ACTION = self::SLUG . '-nonce-action';
	const NONCE_NAME   = self::SLUG . '-nonce-name';

	/**
	 * コンストラクタ
	 */
	public function __construct() {
		parent::__construct();

		add_action( 'admin_init', array( $this, 'handle_form_submission' ) );
		add_action( 'admin_init', array( $this, 'handle_intro_skip' ) );
	}

	/**
	 * フォーム送信処理
	 */
	public function handle_form_submission() {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->wrap_update_option( 'intro_completed', true );

		// メンテナンスモードファイルを削除
		$maintenance_path = $this->get_temp_dir_path() . 'maintenance.php';
		if ( $this->wrap_exists( $maintenance_path ) ) {
			$this->wrap_delete( $maintenance_path );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . QAHM_Admin_Page_Realtime::SLUG ) );
		exit;
	}

	public function handle_intro_skip() {

		// qahm-config に行く時だけでOK
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( $page !== QAHM_Admin_Page_Config::SLUG ) {
			return;
		}

		// パラメータチェック
		$skip = isset( $_GET['qahm_intro_skip'] ) ? sanitize_text_field( wp_unslash( $_GET['qahm_intro_skip'] ) ) : '';
		if ( $skip !== '1' ) {
			return;
		}

		// nonce 検証
		$nonce = isset( $_GET['qahm_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['qahm_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'qahm_intro_skip' ) ) {
			return;
		}

		// 権限（ここは今の仕様通り）
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// すでに完了なら何もしない
		if ( $this->wrap_get_option( 'intro_completed' ) ) {
			return;
		}

		// intro 完了扱い
		$this->wrap_update_option( 'intro_completed', true );

		// メンテナンスモードファイルを削除（既存と同じ挙動にする）
		$maintenance_path = $this->get_temp_dir_path() . 'maintenance.php';
		if ( $this->wrap_exists( $maintenance_path ) ) {
			$this->wrap_delete( $maintenance_path );
		}

		// URLを綺麗に（パラメータを消して Settings に留める）
		wp_safe_redirect( admin_url( 'admin.php?page=' . QAHM_Admin_Page_Config::SLUG ) );
		exit;
	}

	/**
	 * 初期化
	 *
	 * @param string $hook_suffix フックサフィックス.
	 */
	public function enqueue_scripts( $hook_suffix ) {
		if ( $this->hook_suffix !== $hook_suffix ) {
			return;
		}

		$css_dir_url = $this->get_css_dir_url();

		$this->common_enqueue_style();

		$this->common_enqueue_script();

		$scripts = $this->get_common_inline_script();
		wp_add_inline_script( QAHM_NAME . '-common', 'var ' . QAHM_NAME . ' = ' . QAHM_NAME . ' || {}; let ' . QAHM_NAME . 'Obj = ' . $this->wrap_json_encode( $scripts ) . '; ' . QAHM_NAME . ' = Object.assign( ' . QAHM_NAME . ', ' . QAHM_NAME . 'Obj );', 'before' );

		$localize = $this->get_common_localize_script();
		wp_localize_script( QAHM_NAME . '-common', QAHM_NAME . 'l10n', $localize );
	}

	/**
	 * ページの表示
	 */
	public function create_html() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'qa-heatmap-analytics' ) );
		}
		?>

<style>
	.qahm-intro-wrap{
		max-width: 720px;
		margin: 40px 0 0 40px;
	}
	.qahm-intro-wrap h1{
		font-size: 23px;
		font-weight: 400;
		margin: 0;
		padding: 0;
		color: #1d2327;
	}
	.qahm-intro-wrap h1::before{
		content: "";
		display: inline-block;
		width: 6px;
		height: 24px;
		background: #FF8786;
		margin-right: 12px;
		vertical-align: middle;
	}

	.qahm-intro-content{
		margin-top: 20px;
		background: #fff;
		border: 1px solid #dcdcde;
		border-radius: 8px;
		padding: 24px;
	}

	.qahm-intro-content p{
		font-size: 14px;
		line-height: 1.9;
		margin: 0 0 16px 0;
		color: #1d2327;
	}
	.qahm-intro-section{
		margin-top: 18px;
		padding-top: 18px;
		border-top: 1px solid #f0f0f1;
	}
	.qahm-intro-section:first-of-type{
		margin-top: 0;
		padding-top: 0;
		border-top: 0;
	}

	.qahm-intro-label{
		font-size: 14px;
		font-weight: 600;
		color: #1d2327;
		margin: 0 0 10px 0;
	}

	.qahm-intro-list{
		margin: 0;
		padding-left: 20px;
	}
	.qahm-intro-list li{
		margin: 0 0 8px 0;
		font-size: 14px;
		line-height: 1.9;
		color: #1d2327;
	}
	.qahm-intro-list li:last-child{
		margin-bottom: 0;
	}

	.qahm-intro-link{
		margin-top: 12px;
		margin-bottom: 8px;
		font-size: 14px;
	}
	.qahm-intro-link a{
		color: #2271b1;
		text-decoration: none;
	}
	.qahm-intro-link a:hover{
		text-decoration: underline;
	}

	.qahm-intro-actions{
		margin-top: 20px;
		padding-top: 18px;
		display: flex;
		justify-content: center;
		border-top: 1px solid #f0f0f1;
	}
	.qahm-intro-actions .submit{
		margin: 0;
		padding: 0;
	}
</style>

<div id="<?php echo esc_attr( basename( __FILE__, '.php' ) ); ?>" class="qahm-admin-page">
	<div class="qahm-intro-wrap">
		<h1><?php esc_html_e( 'QA Assistants is now active', 'qa-heatmap-analytics' ); ?></h1>

		<div class="qahm-intro-content">
			<p>
				<?php esc_html_e( 'QA Assistants has started tracking activity on your site.', 'qa-heatmap-analytics' ); ?>
			</p>

			<div class="qahm-intro-section">
				<p class="qahm-intro-label"><?php esc_html_e( 'Available now', 'qa-heatmap-analytics' ); ?></p>
				<ul class="qahm-intro-list">
					<li><?php esc_html_e( 'Live activity', 'qa-heatmap-analytics' ); ?></li>
				</ul>
			</div>

			<div class="qahm-intro-section">
				<p class="qahm-intro-label"><?php esc_html_e( 'Available once data is collected', 'qa-heatmap-analytics' ); ?></p>
				<ul class="qahm-intro-list">
					<li><?php esc_html_e( 'Insights from assistants', 'qa-heatmap-analytics' ); ?></li>
					<li><?php esc_html_e( 'Suggestions based on your site activity', 'qa-heatmap-analytics' ); ?></li>
				</ul>
			</div>

			<div class="qahm-intro-section">
				<p class="qahm-intro-label">
					<?php esc_html_e( 'Advanced Settings', 'qa-heatmap-analytics' ); ?>
				</p>
				<ul class="qahm-intro-list">
					<li><?php esc_html_e( 'Advanced Mode (detailed reports and insights)', 'qa-heatmap-analytics' ); ?></li>
					<li><?php esc_html_e( 'Data retention', 'qa-heatmap-analytics' ); ?></li>
					<li><?php esc_html_e( 'Traffic limit', 'qa-heatmap-analytics' ); ?></li>
				</ul>

				<div class="qahm-intro-link">
					<?php
					$settings_url = admin_url( 'admin.php?page=qahm-config' );
					$settings_url = add_query_arg( 'qahm_intro_skip', '1', $settings_url );
					$settings_url = wp_nonce_url( $settings_url, 'qahm_intro_skip', 'qahm_nonce' );
					?>
					<a href="<?php echo esc_url( $settings_url ); ?>">
						<?php esc_html_e( 'Review settings', 'qa-heatmap-analytics' ); ?> →
					</a>
				</div>
			</div>

			<div class="qahm-intro-actions">
				<form method="post" action="">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
					<p class="submit">
						<input type="submit" name="submit" id="submit" class="button button-primary button-hero" value="<?php echo esc_attr_x( 'View Live Activity', 'button label', 'qa-heatmap-analytics' ); ?>"
						>
					</p>
				</form>
			</div>

		</div>
	</div>
</div>


		<?php
	}
}
