<?php
defined( 'ABSPATH' ) || exit;
/**
 * ヘルプ画面（QA ZERO / QA Assistants 共通）
 * Issue #1042: プロダクト別ファイルを core に統合
 *
 * @package qa_heatmap
 */

$GLOBALS['qahm_admin_page_help'] = new QAHM_Admin_Page_Help();

class QAHM_Admin_Page_Help extends QAHM_Admin_Page_Base {

	// スラッグ
	const SLUG = QAHM_NAME . '-help';

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
	 * Calculate average page speed from recent data
	 * Issue #144: Storage data status widget information migration
	 */
	private function calculate_average_page_speed() {
		global $qahm_db;

		$table_name      = $qahm_db->prefix . 'qa_pv_log';
		$thirty_days_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );

		$query = $qahm_db->prepare(
			"SELECT AVG(speed_msec) as avg_speed FROM {$table_name} WHERE access_time >= %s AND speed_msec > 0",
			$thirty_days_ago
		);

		$result = $qahm_db->get_var( $query );

		if ( $result && $result > 0 ) {
			return round( $result, 0 ); // Return as integer milliseconds
		}

		return '--'; // Return placeholder if no data available
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

		// enqueue_style
		$this->common_enqueue_style();
		if ( QAHM_TYPE === QAHM_TYPE_ZERO ) {
			wp_enqueue_style( QAHM_NAME . '-admin-page-help-css', $css_dir_url . 'admin-page-help-zero.css', array( QAHM_NAME . '-reset' ), QAHM_PLUGIN_VERSION );
		} else {
			wp_enqueue_style( QAHM_NAME . '-admin-page-help-css', $css_dir_url . 'admin-page-help-wp.css', array( QAHM_NAME . '-reset' ), QAHM_PLUGIN_VERSION );
		}

		// enqueue script
		$this->common_enqueue_script();

		// inline script
		$scripts = $this->get_common_inline_script();
		wp_add_inline_script( QAHM_NAME . '-common', 'var ' . QAHM_NAME . ' = ' . QAHM_NAME . ' || {}; let ' . QAHM_NAME . 'Obj = ' . $this->wrap_json_encode( $scripts ) . '; ' . QAHM_NAME . ' = Object.assign( ' . QAHM_NAME . ', ' . QAHM_NAME . 'Obj );', 'before' );

		// localize
		$localize = $this->get_common_localize_script();
		wp_localize_script( QAHM_NAME . '-common', QAHM_NAME . 'l10n', $localize );
	}

	/**
	 * ページの表示
	 */
	public function create_html() {
		$lang_set               = get_bloginfo( 'language' );
		$php_version            = phpversion();
		$php_memory_limit       = ini_get( 'memory_limit' );
		$php_max_execution_time = ini_get( 'max_execution_time' );
		global $wp_version;

		$qahm_file           = new QAHM_File_Base();
		$this_month_pv       = $qahm_file->count_this_month_pv();
		$pv_limit            = QAHM_CONFIG_LIMIT_PV_MONTH;
		$data_retention_days = $this->get_data_retention_days();

		// キャッシュからストレージ情報を読み取り（Issue #1037）
		// キャッシュは夜間バッチで生成。なければ「--」表示（直接走査はしない）
		$data_dir     = $this->get_data_dir_path();
		$cache_file   = $data_dir . 'cache/storage_stats_cache.php';
		$storage_info = null;
		if ( $this->wrap_exists( $cache_file ) ) {
			$storage_info = $this->wrap_unserialize( $this->wrap_get_contents( $cache_file ) );
		}
		if ( ! empty( $storage_info ) && isset( $storage_info['filecount'] ) ) {
			$storage_size_mb = round( $storage_info['size'] / ( 1024 * 1024 ), 2 );
			$file_count      = number_format( $storage_info['filecount'] );
		} else {
			$storage_size_mb = '--';
			$file_count      = '--';
		}
		$avg_page_speed = $this->calculate_average_page_speed();
		?>

		<div id="<?php echo esc_attr( basename( __FILE__, '.php' ) ); ?>" class="qahm-admin-page">
			<div class="qa-zero-content">
				<?php $this->create_header( esc_html__( 'Help', 'qa-heatmap-analytics' ), false ); ?>
				<div class="qa-zero-data-container">
					<div class="qa-zero-data">

						<?php // --- ヘルプ・リンク セクション --- ?>
						<?php if ( QAHM_TYPE === QAHM_TYPE_ZERO ) { ?>
							<?php if ( 'ja' === $lang_set ) { ?>
							<div class="qahm-help__section">
								<h3 class="section-label"><?php esc_html_e( 'ご利用ガイド', 'qa-heatmap-analytics' ); ?></h3>
								<a href="https://docs.google.com/document/d/1HeL84w2_HUGMh90rRh46ri2wLymKXx416XjmVVEyn3c/edit?usp=sharing" target="_blank" rel="noopener" class="qahm-help__link"><?php esc_html_e( 'ユーザーマニュアル', 'qa-heatmap-analytics' ); ?></a>
							</div>
							<div class="qahm-help__section">
								<h3 class="section-label"><?php esc_html_e( 'ヘルプ', 'qa-heatmap-analytics' ); ?></h3>
								<a href="https://qazero.com/customer-support/" target="_blank" rel="noopener" class="qahm-help__link"><?php esc_html_e( 'お問い合わせ', 'qa-heatmap-analytics' ); ?></a>
							</div>
							<?php } else { ?>
							<div class="qahm-help__section">
								<h3 class="section-label"><?php esc_html_e( 'ご利用ガイド', 'qa-heatmap-analytics' ); ?></h3>
								<a href="" target="_blank" rel="noopener" class="qahm-help__link"><?php esc_html_e( 'ドキュメント', 'qa-heatmap-analytics' ); ?></a>
							</div>
							<div class="qahm-help__section">
								<h3 class="section-label"><?php esc_html_e( 'カスタマーサポート', 'qa-heatmap-analytics' ); ?></h3>
								<a href="" target="_blank" rel="noopener" class="qahm-help__link"><?php esc_html_e( 'お問い合わせ', 'qa-heatmap-analytics' ); ?></a>
							</div>
							<?php } ?>
						<?php } else { ?>
							<div class="qahm-help__section">
								<h3 class="section-label"><?php esc_html_e( 'User Guide', 'qa-heatmap-analytics' ); ?></h3>
								<a href="<?php echo esc_url( QAHM_DOCUMENTATION_URL ); ?>" target="_blank" rel="noopener" class="qahm-help__link"><?php esc_html_e( 'Documentation', 'qa-heatmap-analytics' ); ?></a>
							</div>
							<div class="qahm-help__section">
								<h3 class="section-label"><?php esc_html_e( 'Community Support', 'qa-heatmap-analytics' ); ?></h3>
								<a href="https://wordpress.org/support/plugin/qa-heatmap-analytics/" target="_blank" rel="noopener" class="qahm-help__link"><?php esc_html_e( 'WordPress Support Forum', 'qa-heatmap-analytics' ); ?></a>
								<p class="qahm-help__link-description">
								<?php
								esc_html_e(
									'You\'ll often find helpful answers from our community members. Our support team is also around if you need further assistance.
We also welcome your feedback or any insights you\'d like to share.',
									'qa-heatmap-analytics'
								);
								?>
								</p>
							</div>
						<?php } ?>

						<?php // --- プラグインと環境情報 --- ?>
						<div class="qahm-help__section">
							<h3 class="section-label"><?php esc_html_e( 'System Information', 'qa-heatmap-analytics' ); ?></h3>
							<p class="qahm-help__version"><?php echo esc_html( QAHM_PLUGIN_NAME ); ?> <?php esc_html_e( 'Version', 'qa-heatmap-analytics' ); ?>: <?php echo esc_html( QAHM_PLUGIN_VERSION ); ?></p>
							<table class="qahm-help__info-table">
								<tr>
									<th><?php esc_html_e( 'WordPress version', 'qa-heatmap-analytics' ); ?></th>
									<td><?php echo esc_html( $wp_version ); ?></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'PHP version', 'qa-heatmap-analytics' ); ?></th>
									<td><?php echo esc_html( $php_version ); ?></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'PHP memory limit', 'qa-heatmap-analytics' ); ?></th>
									<td><?php echo esc_html( $php_memory_limit ); ?></td>
								</tr>
								<?php if ( QAHM_TYPE === QAHM_TYPE_WP ) { ?>
								<tr>
									<th><?php esc_html_e( 'Pageviews This Month / Limit:', 'qa-heatmap-analytics' ); ?></th>
									<td><?php echo esc_html( number_format( $this_month_pv ) . ' / ' . number_format( $pv_limit ) ); ?></td>
								</tr>
								<?php } ?>
								<tr>
									<th><?php esc_html_e( 'Data Retention Period', 'qa-heatmap-analytics' ); ?></th>
									<td><?php echo esc_html( $data_retention_days ) . ' ' . esc_html__( '日', 'qa-heatmap-analytics' ); ?></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Stored Data Size', 'qa-heatmap-analytics' ); ?></th>
									<td>
									<?php
									if ( '--' !== $storage_size_mb ) {
										echo esc_html( $storage_size_mb . ' MB' );
									} else {
										echo '--';
									}
									?>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Number of Files', 'qa-heatmap-analytics' ); ?></th>
									<td><?php echo esc_html( $file_count ); ?></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'QA Page Load Time', 'qa-heatmap-analytics' ); ?></th>
									<td><?php echo esc_html( $avg_page_speed ); ?>
									<?php
									if ( '--' !== $avg_page_speed ) {
										echo ' ms'; }
									?>
									</td>
								</tr>
							</table>
						</div>

						<?php // --- RSS セクション（プラグインと環境情報と Debug の間） --- ?>
						<?php if ( QAHM_TYPE === QAHM_TYPE_WP ) { ?>
							<div class="qahm-help__section qahm-help__rss">
								<?php $this->view_rss_feed( false ); ?>
							</div>
						<?php } ?>

						<?php // --- Debug セクション --- ?>
						<?php
						global $wpdb;

						$my_theme     = wp_get_theme();
						$site_plugins = get_plugins();
						$plugin_names = array();

						foreach ( $site_plugins as $main_file => $plugin_meta ) {
							if ( ! is_plugin_active( $main_file ) ) {
								continue;
							}
							$plugin_names[] = sanitize_text_field( $plugin_meta['Name'] . ' ' . $plugin_meta['Version'] );
						}

						$cron_status = $this->wrap_get_contents( $this->get_data_dir_path() . 'cron_status' );

						// max_allowed_packet
						$max_allowed_packet = '--';
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$result = $wpdb->get_results( "SHOW VARIABLES LIKE 'max_allowed_packet'", ARRAY_A );
						if ( ! empty( $result ) ) {
							$max_allowed_packet = $result[0]['Value'];
							$max_allowed_packet = $max_allowed_packet / ( 1024 ** 3 );
							$max_allowed_packet = number_format( $max_allowed_packet, 2 ) . ' GB';
						}
						?>
						<details class="qahm-help__section qahm-help__debug">
							<summary class="qahm-help__debug-toggle">Debug</summary>
							<div class="qahm-help__debug-content">
								<p><strong>Plugin version:</strong><br><?php echo esc_html( QAHM_PLUGIN_VERSION ); ?></p>
								<p><strong>WordPress Server IP address:</strong><br><?php echo esc_html( $this->wrap_filter_input( INPUT_SERVER, 'SERVER_ADDR' ) ); ?></p>
								<p><strong>PHP version:</strong><br><?php echo esc_html( $php_version ); ?></p>
								<p><strong>PHP memory limit:</strong><br><?php echo esc_html( $php_memory_limit ); ?></p>
								<p><strong>max_execution_time:</strong><br><?php echo esc_html( $php_max_execution_time ); ?></p>
								<p><strong>max_allowed_packet:</strong><br><?php echo esc_html( $max_allowed_packet ); ?></p>
								<p><strong>PHP extensions:</strong><br><?php echo esc_html( $this->wrap_implode( ', ', get_loaded_extensions() ) ); ?></p>
								<p><strong>Database version:</strong><br>
								<?php
								// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
								echo esc_html( $wpdb->get_var( 'SELECT VERSION();' ) );
								?>
								</p>
								<p><strong>InnoDB availability:</strong><br>
								<?php
								// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
								echo esc_html( $wpdb->get_var( "SELECT SUPPORT FROM INFORMATION_SCHEMA.ENGINES WHERE ENGINE = 'InnoDB';" ) );
								?>
								</p>
								<p><strong>WordPress version:</strong><br><?php echo esc_html( $wp_version ); ?></p>
								<p><strong>Multisite:</strong><br><?php echo esc_html( ( function_exists( 'is_multisite' ) && is_multisite() ) ? 'Yes' : 'No' ); ?></p>
								<p><strong>Active plugins:</strong><br><?php echo esc_html( $this->wrap_implode( ', ', $plugin_names ) ); ?></p>
								<p><strong>Theme:</strong><br><?php echo esc_html( $my_theme->get( 'Name' ) . ' (' . $my_theme->get( 'Version' ) . ') by ' . $my_theme->get( 'Author' ) ); ?></p>
								<p><strong>qalog.txt:</strong><br><?php echo esc_url( $this->get_data_dir_url( 'log' ) . 'qalog.txt' ); ?></p>
								<p><strong>cron_status:</strong><br><?php echo esc_html( $cron_status ); ?></p>
							</div>
						</details>

					</div>
				</div>

				<?php if ( QAHM_TYPE === QAHM_TYPE_WP ) { ?>
					<?php $this->create_footer_follow(); ?>
				<?php } ?>

			</div>
		</div>

		<?php
	}
} // end of class
