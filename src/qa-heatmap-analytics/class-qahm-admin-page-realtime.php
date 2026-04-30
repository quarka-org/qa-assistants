<?php
defined( 'ABSPATH' ) || exit;
/**
 *
 *
 * @package qa_heatmap
 */

$GLOBALS['qahm_admin_page_realtime'] = new QAHM_Admin_Page_Realtime();

class QAHM_Admin_Page_Realtime extends QAHM_Admin_Page_Dataviewer {

	// スラッグ
	const SLUG = QAHM_NAME . '-realtime';

	// nonce
	const NONCE_ACTION = self::SLUG . '-nonce-action';
	const NONCE_NAME   = self::SLUG . '-nonce-name';

	function __construct() {
		parent::__construct();
		$this->regist_ajax_func( 'ajax_get_session_num' );
		$this->regist_ajax_func( 'ajax_get_realtime_list' );
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

		// enqueue script
		$this->common_enqueue_script();
		if ( QAHM_TYPE === QAHM_TYPE_ZERO ) {
			wp_enqueue_style( QAHM_NAME . '-admin-page-realtime', $css_dir_url . 'admin-page-realtime-zero.css', null, QAHM_PLUGIN_VERSION );
		} elseif ( QAHM_TYPE === QAHM_TYPE_WP ) {
			wp_enqueue_style( QAHM_NAME . '-admin-page-realtime', $css_dir_url . 'admin-page-realtime-wp.css', null, QAHM_PLUGIN_VERSION );
		}

		wp_enqueue_script( QAHM_NAME . '-admin-page-realtime', $js_dir_url . 'admin-page-realtime.js', array( QAHM_NAME . '-effect' ), QAHM_PLUGIN_VERSION );
		wp_enqueue_script( QAHM_NAME . '-chart', $js_dir_url . 'lib/chart/chart.min.js', null, QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-dayjs', $js_dir_url . 'lib/dayjs/dayjs.min.js', null, QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-dayjs-utc', $js_dir_url . 'lib/dayjs/plugin/utc.js', array( QAHM_NAME . '-dayjs' ), QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-dayjs-timezone', $js_dir_url . 'lib/dayjs/plugin/timezone.js', array( QAHM_NAME . '-dayjs' ), QAHM_PLUGIN_VERSION, false );
		wp_enqueue_script( QAHM_NAME . '-admin-page-dataviewer', $js_dir_url . 'admin-page-dataviewer.js', array( QAHM_NAME . '-chart' ), QAHM_PLUGIN_VERSION );

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

					<?php $this->create_header( __( 'Realtime', 'qa-heatmap-analytics' ) ); ?>

					<!-- リアルタイムサマリー -->
					<div class="qa-zero-data-container">
						<div class="qa-zero-data qa-zero-realtime-summary<?php echo QAHM_CONFIG_TWO_SYSTEM_MODE ? ' qa-zero-realtime-summary--half' : ''; ?>">
							<?php if ( ! QAHM_CONFIG_TWO_SYSTEM_MODE ) { ?>
							<div class="qa-zero-realtime-summary__block">
								<div class="qa-zero-data__title">
									<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none">
										<path fill="currentColor" d="M10 10C12.21 10 14 8.21 14 6C14 3.79 12.21 2 10 2C7.79 2 6 3.79 6 6C6 8.21 7.79 10 10 10ZM10 12C7.33 12 2 13.34 2 16V18H18V16C18 13.34 12.67 12 10 12Z"/>
									</svg>
									<?php esc_html_e( 'Active Users', 'qa-heatmap-analytics' ); ?>
								</div>
								<div class="qa-zero-data-box-wrapper">
									<div class="qa-zero-data-box">
										<div class="qa-zero-data-box__title"><?php esc_html_e( 'Last 1 Min', 'qa-heatmap-analytics' ); ?></div>
										<div class="qa-zero-data-box__value qa-zero-data-box--highlight"><span id="session_num_1min">-</span></div>
									</div>
									<div class="qa-zero-data-box">
										<div class="qa-zero-data-box__title"><?php esc_html_e( 'Last 30 Min', 'qa-heatmap-analytics' ); ?></div>
										<div class="qa-zero-data-box__value qa-zero-data-box--highlight"><span id="session_num">-</span></div>
									</div>
								</div>
								<div class="qa-zero-realtime-heartbeat">
									<svg viewBox="0 0 200 30" preserveAspectRatio="none">
										<polyline id="heartbeat-line-bg" class="qa-zero-realtime-heartbeat__line-bg" points="10,15 190,15" />
										<polyline id="heartbeat-line" class="qa-zero-realtime-heartbeat__line" points="10,15 190,15" />
									</svg>
								</div>
							</div>
							<div class="qa-zero-realtime-summary__block">
								<div class="qa-zero-data__title">
									<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none">
										<path d="M17 1H3C1.9 1 1 1.9 1 3V17C1 18.1 1.9 19 3 19H17C18.1 19 19 18.1 19 17V3C19 1.9 18.1 1 17 1ZM17 17H3V3H17V17ZM7.5 13H5V15H7.5V13ZM12.5 7H10V15H12.5V7ZM17.5 10H15V15H17.5V10Z"/>
									</svg>
									<?php esc_html_e( 'Device Breakdown', 'qa-heatmap-analytics' ); ?>
								</div>
								<div class="qa-zero-graph qa-zero-graph--default qa-zero-realtime-doughnut">
									<canvas id="device_chart"></canvas>
								</div>
							</div>
							<?php } ?>
							<div class="qa-zero-realtime-summary__block">
								<div class="qa-zero-data__title">
									<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none">
										<path d="M10 0C4.48 0 0 4.48 0 10C0 15.52 4.48 20 10 20C15.52 20 20 15.52 20 10C20 4.48 15.52 0 10 0ZM9 17.93C5.05 17.44 2 14.08 2 10C2 9.38 2.08 8.79 2.21 8.21L7 13V14C7 15.1 7.9 16 9 16V17.93ZM15.9 15.39C15.64 14.58 14.9 14 14 14H13V11C13 10.45 12.55 10 12 10H6V8H8C8.55 8 9 7.55 9 7V5H11C12.1 5 13 4.1 13 3V2.59C16.93 4.05 18 7.72 18 10C18 12.08 17.21 13.97 15.9 15.39Z"/>
									</svg>
									<?php esc_html_e( 'Regions TOP 5', 'qa-heatmap-analytics' ); ?>
								</div>
								<div class="qa-zero-graph qa-zero-graph--default">
									<canvas id="regions_chart"></canvas>
								</div>
							</div>
							<div class="qa-zero-realtime-summary__block">
								<div class="qa-zero-data__title">
									<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none">
										<path d="M3.27 13.73C4.44 14.9 6.11 15.12 7.46 14.43L8.62 15.59C6.72 16.88 4.15 16.64 2.51 14.99C0.66 13.14 0.66 10.11 2.51 8.27L5.69 5.09C7.54 3.24 10.57 3.24 12.41 5.09C12.88 5.56 13.22 6.11 13.43 6.7L12.17 7.96C12.13 7.12 11.79 6.3 11.13 5.64C9.86 4.37 7.78 4.37 6.51 5.64L3.27 8.88C2 10.15 2 12.46 3.27 13.73ZM16.73 6.27C15.56 5.1 13.89 4.88 12.54 5.57L11.38 4.41C13.28 3.12 15.85 3.36 17.49 5.01C19.34 6.86 19.34 9.89 17.49 11.73L14.31 14.91C12.46 16.76 9.43 16.76 7.59 14.91C7.12 14.44 6.78 13.89 6.57 13.3L7.83 12.04C7.87 12.88 8.21 13.7 8.87 14.36C10.14 15.63 12.22 15.63 13.49 14.36L16.73 11.12C18 9.85 18 7.54 16.73 6.27Z"/>
									</svg>
									<?php esc_html_e( 'Referrers TOP 5', 'qa-heatmap-analytics' ); ?>
								</div>
								<div class="qa-zero-graph qa-zero-graph--default">
									<canvas id="referrers_chart"></canvas>
								</div>
							</div>
						</div>
					</div>

					<!-- Session Recordings -->
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
								<?php esc_html_e( 'Session Recordings', 'qa-heatmap-analytics' ); ?>
							</div>
							<div class="qa-zero-realtime-update">
								<?php esc_html_e( 'Last update', 'qa-heatmap-analytics' ); ?>:
								<span id="update_time"></span>
							</div>
							<div id="tday_table"></div>
						</div>
					</div>

				<?php $this->create_footer_follow(); ?>
			</div><!-- qa-zero-content_end -->
		</div>
		<?php
	}

	/**
	 * セッション数の取得
	 */
	public function ajax_get_session_num() {
		if ( $this->is_maintenance() ) {
			return;
		}

		$data             = array();
		$session_num      = 0;
		$session_num_1min = 0;

		// tracking_id を取得（'all' の場合は全てカウント）
		$tracking_id_raw = $this->wrap_filter_input( INPUT_POST, 'tracking_id' );
		$tracking_id     = ! empty( $tracking_id_raw ) ? trim( $tracking_id_raw ) : 'all';

		global $wp_filesystem;
		global $qahm_time;
		$before1min            = $qahm_time->now_unixtime() - 60;
		$session_temp_dir_path = $this->get_data_dir_path( 'readers/temp' );
		if ( $wp_filesystem->exists( $session_temp_dir_path ) ) {

			$session_temp_dirlist = $this->wrap_dirlist( $session_temp_dir_path );
			if ( $session_temp_dirlist ) {
				foreach ( $session_temp_dirlist as $session_temp_fileobj ) {
					// tracking_id でフィルタリング
					if ( $tracking_id !== 'all' ) {
						$temp_file_path = $session_temp_dir_path . '/' . $session_temp_fileobj['name'];
						if ( $wp_filesystem->exists( $temp_file_path ) ) {
							$temp_data = $this->wrap_unserialize( $this->wrap_get_contents( $temp_file_path ) );
							if ( ! $temp_data || ! isset( $temp_data['head']['tracking_id'] ) ) {
								continue;
							}
							if ( $temp_data['head']['tracking_id'] !== $tracking_id ) {
								continue;
							}
						}
					}

					++$session_num;
					if ( $session_temp_fileobj['lastmodunix'] > $before1min ) {
						++$session_num_1min;
					}
				}
			}
		}

		$data['session_num']      = $session_num;
		$data['session_num_1min'] = $session_num_1min;

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
		echo $this->wrap_json_encode( $data );
		die();
	}


	/**
	 * リアルタイムリストの取得
	 */
	public function ajax_get_realtime_list() {
		if ( $this->is_maintenance() ) {
			return;
		}

		$data          = array();
		$alldataary    = array();
		$realtime_list = '';

		// tracking_id を取得（'all' の場合は全て表示）
		$tracking_id_raw = $this->wrap_filter_input( INPUT_POST, 'tracking_id' );
		$tracking_id     = ! empty( $tracking_id_raw ) ? trim( $tracking_id_raw ) : 'all';

		global $wp_filesystem;
		global $qahm_time;

		$ellipsis     = '...';
		$title_width  = 80 + mb_strlen( $ellipsis );
		$domain_width = 30 + mb_strlen( $ellipsis );

		$realtime_view_path = $this->get_data_dir_path( 'readers' ) . 'realtime_view.php';
		if ( ! $wp_filesystem->exists( $realtime_view_path ) ) {
			echo 'null';
			die();
		}

		$realtime_view_ary = $this->wrap_unserialize( $this->wrap_get_contents( $realtime_view_path ) );
		if ( ! $realtime_view_ary ) {
			echo 'null';
			die();
		}

		$realtime_cnt = $this->wrap_count( $realtime_view_ary['body'] );
		if ( $realtime_cnt === 0 ) {
			echo 'null';
			die();
		}

		// #903: 24時間以内のセッションのみ表示
		$threshold_time = $qahm_time->now_unixtime() - ( 24 * 60 * 60 );

		for ( $i = 0; $i < $realtime_cnt; $i++ ) {
			$body = $realtime_view_ary['body'][ $i ];

			// 24時間フィルタ
			if ( isset( $body['last_exit_time'] ) && (int) $body['last_exit_time'] < $threshold_time ) {
				continue;
			}

			// tracking_id でフィルタリング
			if ( $tracking_id !== 'all' ) {
				if ( ! isset( $body['tracking_id'] ) || $body['tracking_id'] !== $tracking_id ) {
					continue;
				}
			}
			$first_title        = $body['first_title'];
			$first_title_el     = mb_strimwidth( $first_title, 0, $title_width, $ellipsis );
			$first_url          = $body['first_url'];
			$last_title         = $body['last_title'];
			$last_title_el      = mb_strimwidth( $last_title, 0, $title_width, $ellipsis );
			$last_url           = $body['last_url'];
			$last_exit_time     = $body['last_exit_time'];
			$sec_on_site        = $qahm_time->seconds_to_timestr( (int) $body['sec_on_site'] );
			$referrer           = $body['first_referrer'];
			$source_domain      = 'direct';
			$source_domain_html = 'direct';
			$work_base_name     = pathinfo( $body['file_name'], PATHINFO_FILENAME );

			if ( ! empty( $referrer ) ) {
				if ( 0 === strncmp( $referrer, 'http', 4 ) ) {
					$parse_url          = wp_parse_url( $referrer );
					$ref_host           = $parse_url['host'];
					$source_domain      = mb_strimwidth( $ref_host, 0, $domain_width, $ellipsis );
					$source_domain_html = '<a href="' . esc_url( $referrer ) . '" target="_blank" class="qahm-tooltip" data-qahm-tooltip="' . esc_url( $referrer ) . '">' . esc_html( $source_domain ) . '</a>';
				} else {
					$source_domain      = mb_strimwidth( $referrer, 0, $domain_width, $ellipsis );
					$source_domain_html = esc_html( $source_domain );
				}
			}

			$device     = $body['device_name'];
			$device_map = array(
				'dsk' => 'desktop',
				'tab' => 'tablet',
				'smp' => 'mobile',
			);
			if ( isset( $device_map[ $device ] ) ) {
				$device = $device_map[ $device ];
			}

			// #903: メディア列（utm_medium — 集客画面と同じ補完ロジック）
			// #1076: SEARCH_ENGINES 判定を追加（検索エンジン経由を organic として扱う）
			$utm_medium = isset( $body['utm_medium'] ) ? $body['utm_medium'] : '';
			if ( empty( $utm_medium ) ) {
				if ( 'direct' === $source_domain ) {
					$utm_medium = '(none)';
				} elseif ( QAHM_Base::is_search_engine_domain( $source_domain ) ) {
					$utm_medium = 'organic';
				} else {
					$utm_medium = 'referral';
				}
			}

			$dataary      = array();
			$dataary[]    = esc_html( $device );            // 0
			$dataary[]    = esc_html( $last_exit_time );     // 1
			$dataary[]    = esc_url( $first_url );           // 2
			$dataary[]    = esc_html( $first_title_el );     // 3
			$dataary[]    = esc_url( $last_url );            // 4
			$dataary[]    = esc_html( $last_title_el );      // 5
			$dataary[]    = esc_url( $referrer );            // 6
			$dataary[]    = esc_html( $source_domain );      // 7
			$dataary[]    = esc_html( $utm_medium );         // 8 (#903: 参照元の右)
			$dataary[]    = esc_html( $body['page_view'] );  // 9
			$dataary[]    = esc_html( $body['sec_on_site'] );// 10
			$dataary[]    = esc_attr( $work_base_name );     // 11
			$country_code = isset( $body['country_code'] ) ? $body['country_code'] : '';
			$dataary[]    = esc_html( QAHM_Country_Converter::get_country_name( $country_code ) ); // 12
			$alldataary[] = $dataary;
		}

		$data['update_time'] = $qahm_time->now_str();

		//$data['realtime_list'] = $realtime_list;
		$data['realtime_list'] = $alldataary;

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body for AJAX (non-HTML context).
		echo $this->wrap_json_encode( $data );
		die();
	}
} // end of class
