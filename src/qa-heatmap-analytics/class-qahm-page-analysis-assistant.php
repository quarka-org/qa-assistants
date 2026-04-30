<?php
defined( 'ABSPATH' ) || exit;
/**
 * QAHM Page Analysis Assistant
 *
 * Provides page analysis assistant functionality for QA Assistants.
 * Displays a floating button on frontend pages when admin is logged in.
 *
 * @package qa-heatmap-analytics
 */

$GLOBALS['qahm_page_analysis_assistant'] = new QAHM_Page_Analysis_Assistant();

class QAHM_Page_Analysis_Assistant extends QAHM_File_Base {

	/**
	 * データ取得期間(日数)
	 * @var int
	 */
	private $data_period_days = 7;

	/**
	 * Constructor
	 */
	public function __construct() {
		if ( QAHM_TYPE !== QAHM_TYPE_WP ) {
			return;
		}

		if ( ! is_admin() ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
			add_action( 'wp_footer', array( $this, 'render_chatbot_html' ) );
		}

		add_action( 'wp_ajax_qahm_page_analysis_assistant', array( $this, 'ajax_page_analysis_assistant' ) );
	}

	/**
	 * データ期間を設定
	 *
	 * @param int $days 取得する日数(1-90日)
	 */
	public function set_data_period( $days ) {
		$days = (int) $days;
		if ( $days >= 1 && $days <= 90 ) {
			$this->data_period_days = $days;
		}
	}

	/**
	 * データ期間の表示名を取得
	 *
	 * @return string
	 */
	private function get_period_label() {
		return sprintf(
			/* translators: %d: number of days. */
			__( 'Last %d days', 'qa-heatmap-analytics' ),
			$this->data_period_days
		);
	}

	/**
	 * スクリプトとスタイルのエンキュー
	 */
	public function enqueue_scripts() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$css_dir_url = $this->get_css_dir_url();
		$js_dir_url  = $this->get_js_dir_url();

		wp_enqueue_script(
			QAHM_NAME . '-conversation-ui',
			$js_dir_url . 'conversation-ui.js',
			array( 'jquery' ),
			QAHM_PLUGIN_VERSION,
			true
		);

		wp_enqueue_script(
			QAHM_NAME . '-page-analysis-assistant',
			$js_dir_url . 'page-analysis-assistant.js',
			array( 'jquery', QAHM_NAME . '-conversation-ui' ),
			QAHM_PLUGIN_VERSION,
			true
		);

		wp_enqueue_style(
			QAHM_NAME . '-page-analysis-assistant',
			$css_dir_url . 'page-analysis-assistant-wp.css',
			array(),
			QAHM_PLUGIN_VERSION
		);

		wp_localize_script(
			QAHM_NAME . '-page-analysis-assistant',
			'qahmPageAnalysisAssistantData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'qahm_page_analysis_assistant' ),
			)
		);
	}

	/**
	 * チャットボットのHTML構造を出力
	 */
	public function render_chatbot_html() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<!-- QA Assistants Page Analysis Assistant -->
		<div id="qahm-page-analysis-assistant-container">
			<button id="qahm-page-analysis-assistant-button" type="button">
				<span class="qahm-chatbot-icon">💬</span>
				<span class="qahm-chatbot-label">QA Assistants</span>
			</button>
		
			<div id="qahm-page-analysis-assistant-window" class="hidden">
				<div class="qahm-chatbot-header">
					<span class="qahm-chatbot-title">QA Assistants</span>
					<button class="qahm-chatbot-close" type="button">&times;</button>
				</div>
				<div id="qahm-chatbot-dialogue" class="qahm-chatbot-content">
					<!-- 会話内容がここに表示される(Phase 2で実装) -->
				</div>
				<!-- 新規追加: 入力エリア -->
				<div class="qahm-chatbot-input-area">
					<div class="qahm-chatbot-input-wrapper">
						<input 
							type="text" 
							class="qahm-chatbot-input" 
							placeholder="チャット機能は今後実装予定です" 
							disabled 
							readonly
						/>
						<button class="qahm-chatbot-send-button" disabled>
							<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
								<g>
									<path d="M12 3.59l7.457 7.45-1.414 1.42L13 7.41V21h-2V7.41l-5.043 5.05-1.414-1.42L12 3.59z" fill="currentColor"/>
								</g>
							</svg>
						</button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAXハンドラー: ページ分析アシスタント
	 */
	public function ajax_page_analysis_assistant() {
		check_ajax_referer( 'qahm_page_analysis_assistant', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		$action = isset( $_POST['action_type'] ) ? sanitize_text_field( wp_unslash( $_POST['action_type'] ) ) : 'init';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON input from assistant, sanitize_text_field would corrupt structure.
		$free_text = isset( $_POST['free'] ) ? wp_unslash( $_POST['free'] ) : '';

		$free_data = is_string( $free_text ) ? json_decode( $free_text, true ) : array();
		if ( is_array( $free_data ) && isset( $free_data['period_days'] ) ) {
			$this->set_data_period( (int) $free_data['period_days'] );
		}

		$response = $this->generate_conversation( $action, $free_text );

		wp_send_json_success( $response );
	}

	/**
	 * 会話ロジックの生成
	 *
	 * @param string $action アクション(init, view_data, view_heatmap)
	 * @param string $free_text 自由入力テキスト
	 * @return array 会話データ
	 */
	private function generate_conversation( $action, $free_text = '' ) {
		$execute = array();

		switch ( $action ) {
			case 'init':
				$this->data_period_days = 7;

				$execute[] = array(
					'msg' => esc_html__( 'Here are a few quick insights for this page.', 'qa-heatmap-analytics' ),
				);

				$execute[] = array(
					'msg' => esc_html__( 'What would you like to see?', 'qa-heatmap-analytics' ),
				);

				$execute[] = array(
					'cmd' => array(
						array(
							'text'   => esc_html__( 'View page overview', 'qa-heatmap-analytics' ),
							'action' => array(
								'next' => 'view_data',
							),
						),
						array(
							'text'   => esc_html__( 'View heatmap', 'qa-heatmap-analytics' ),
							'action' => array(
								'next' => 'view_heatmap',
							),
						),
					),
				);
				break;

			case 'view_data':
				$site_url    = get_site_url();
				$parse_url   = wp_parse_url( $site_url );
				$domain_url  = $this->to_domain_url( $parse_url );
				$tracking_id = $this->get_tracking_id( $domain_url );

				$current_page_url = isset( $_POST['current_url'] ) ? esc_url_raw( wp_unslash( $_POST['current_url'] ) ) : '';

				if ( empty( $current_page_url ) ) {
					$execute[] = array(
						'msg' => esc_html__( 'Couldn’t retrieve this page’s URL. Please reload the page.', 'qa-heatmap-analytics' ),
					);

					$execute[] = array(
						'cmd' => array(
							array(
								'text'   => esc_html__( 'Try again', 'qa-heatmap-analytics' ),
								'action' => array(
									'next' => 'view_data',
								),
							),
							array(
								'text'   => esc_html__( 'Back to start', 'qa-heatmap-analytics' ),
								'action' => array(
									'next' => 'init',
								),
							),
						),
					);
					break;
				}

				$url_hash = $this->get_url_hash( $current_page_url );

				global $wpdb;
				$table_name = $wpdb->prefix . 'qa_pages';
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This SQL query uses placeholders and $wpdb->prepare(), but it may trigger warnings due to the dynamic construction of the SQL string. Direct database call is necessary in this case due to the complexity of the SQL query. Caching would not provide significant performance benefits in this context.
				$page_data = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT page_id, url FROM {$table_name} WHERE url_hash = %s AND tracking_id = %s ORDER BY page_id DESC",
						$url_hash,
						$tracking_id
					)
				);

				if ( $wpdb->last_error ) {
					global $qahm_log;
					$qahm_log->error( 'Page Analysis Assistant - Database error: ' . $wpdb->last_error );

					$execute[] = array(
						'msg' => esc_html__( 'A database error occurred. Please wait a moment and try again.', 'qa-heatmap-analytics' ),
					);

					$execute[] = array(
						'cmd' => array(
							array(
								'text'   => esc_html__( 'Try again', 'qa-heatmap-analytics' ),
								'action' => array(
									'next' => 'view_data',
								),
							),
							array(
								'text'   => esc_html__( 'Back to start', 'qa-heatmap-analytics' ),
								'action' => array(
									'next' => 'init',
								),
							),
						),
					);
					break;
				}

				$page_id = null;
				if ( ! empty( $page_data ) ) {
					foreach ( $page_data as $row ) {
						if ( $row->url === $current_page_url ) {
							$page_id = $row->page_id;
							break;
						}
					}
				}

				if ( ! $page_id ) {
					$execute[] = array(
						'msg' => esc_html__( 'No visits to this page have been recorded yet.', 'qa-heatmap-analytics' ),
					);

					$execute[] = array(
						'msg' => esc_html__( 'Data collection will start as visitors come to this page.', 'qa-heatmap-analytics' ),
					);

					$execute[] = array(
						'cmd' => array(
							array(
								'text'   => esc_html__( 'View heatmap', 'qa-heatmap-analytics' ),
								'action' => array(
									'next' => 'view_heatmap',
								),
							),
							array(
								'text'   => esc_html__( 'Back to start', 'qa-heatmap-analytics' ),
								'action' => array(
									'next' => 'init',
								),
							),
						),
					);
					break;
				}

				global $qahm_file_functions;

				$current_end   = gmdate( 'Y-m-d 23:59:59', strtotime( 'yesterday' ) );
				$current_start = gmdate( 'Y-m-d 00:00:00', strtotime( '-' . $this->data_period_days . ' days', strtotime( 'yesterday' ) ) );

				$previous_end   = gmdate( 'Y-m-d 23:59:59', strtotime( '-' . ( $this->data_period_days + 1 ) . ' days', strtotime( 'yesterday' ) ) );
				$previous_start = gmdate( 'Y-m-d 00:00:00', strtotime( '-' . ( $this->data_period_days * 2 ) . ' days', strtotime( 'yesterday' ) ) );

				$current_pv_data      = $qahm_file_functions->get_view_pv( $tracking_id, $current_start, $current_end );
				$current_page_pv_data = $this->wrap_array_filter(
					$current_pv_data,
					function ( $pv ) use ( $page_id ) {
						return isset( $pv['page_id'] ) && (int) $pv['page_id'] === (int) $page_id;
					}
				);

				$previous_pv_data      = $qahm_file_functions->get_view_pv( $tracking_id, $previous_start, $previous_end );
				$previous_page_pv_data = $this->wrap_array_filter(
					$previous_pv_data,
					function ( $pv ) use ( $page_id ) {
						return isset( $pv['page_id'] ) && (int) $pv['page_id'] === (int) $page_id;
					}
				);

				if ( empty( $current_page_pv_data ) ) {
					$execute[] = array(
						/* translators: %s: period label (e.g., "the last 7 days") */
						'msg' => sprintf( esc_html__( 'There is no data for this page in %s.', 'qa-heatmap-analytics' ), $this->get_period_label() ),
					);

					$execute[] = array(
						'cmd' => array(
							array(
								'text'   => esc_html__( 'View heatmap', 'qa-heatmap-analytics' ),
								'action' => array(
									'next' => 'view_heatmap',
								),
							),
							array(
								'text'   => esc_html__( 'Back to start', 'qa-heatmap-analytics' ),
								'action' => array(
									'next' => 'init',
								),
							),
						),
					);
					break;
				}

				$current_pv_count         = $this->wrap_count( $current_page_pv_data );
				$current_session_count    = 0;
				$current_total_browse_sec = 0;
				$current_bounce_count     = 0;

				foreach ( $current_page_pv_data as $pv ) {
					if ( isset( $pv['pv'] ) && (int) $pv['pv'] === 1 ) {
						++$current_session_count;
						if ( isset( $pv['is_last'] ) && (int) $pv['is_last'] === 1 ) {
							++$current_bounce_count;
						}
					}
					if ( isset( $pv['browse_sec'] ) ) {
						$current_total_browse_sec += (int) $pv['browse_sec'];
					}
				}

				$current_avg_browse_sec = $current_pv_count > 0 ? $current_total_browse_sec / $current_pv_count : 0;
				$current_bounce_rate    = $current_session_count > 0 ? ( $current_bounce_count / $current_session_count ) * 100 : 0;

				$previous_pv_count         = $this->wrap_count( $previous_page_pv_data );
				$previous_session_count    = 0;
				$previous_total_browse_sec = 0;
				$previous_bounce_count     = 0;

				foreach ( $previous_page_pv_data as $pv ) {
					if ( isset( $pv['pv'] ) && (int) $pv['pv'] === 1 ) {
						++$previous_session_count;
						if ( isset( $pv['is_last'] ) && (int) $pv['is_last'] === 1 ) {
							++$previous_bounce_count;
						}
					}
					if ( isset( $pv['browse_sec'] ) ) {
						$previous_total_browse_sec += (int) $pv['browse_sec'];
					}
				}

				$previous_avg_browse_sec = $previous_pv_count > 0 ? $previous_total_browse_sec / $previous_pv_count : 0;
				$previous_bounce_rate    = $previous_session_count > 0 ? ( $previous_bounce_count / $previous_session_count ) * 100 : 0;

				$pv_change          = $previous_pv_count > 0 ? ( ( $current_pv_count - $previous_pv_count ) / $previous_pv_count ) * 100 : 0;
				$session_change     = $previous_session_count > 0 ? ( ( $current_session_count - $previous_session_count ) / $previous_session_count ) * 100 : 0;
				$browse_time_change = $previous_avg_browse_sec > 0 ? ( ( $current_avg_browse_sec - $previous_avg_browse_sec ) / $previous_avg_browse_sec ) * 100 : 0;
				$bounce_rate_change = $current_bounce_rate - $previous_bounce_rate;

				$execute[] = array(
					/* translators: %s: period label (e.g., "the last 7 days") */
					'msg' => sprintf( esc_html__( 'Summary for this page in %s.', 'qa-heatmap-analytics' ), $this->get_period_label() ),
				);

				$insights = array();

				if ( $previous_pv_count > 0 && abs( $pv_change ) > 10 ) {
					if ( $pv_change > 0 ) {
						/* translators: %f: percentage change */
						$insights[] = sprintf( esc_html__( 'Pageviews are up %.0f%% compared to the previous period.', 'qa-heatmap-analytics' ), $pv_change );
					} else {
						/* translators: %f: percentage change */
						$insights[] = sprintf( esc_html__( 'Pageviews are down %.0f%% compared to the previous period.', 'qa-heatmap-analytics' ), abs( $pv_change ) );
					}
				}

				if ( $previous_session_count > 0 && abs( $bounce_rate_change ) > 5 ) {
					if ( $bounce_rate_change > 0 ) {
						/* translators: %f: bounce rate change in points */
						$insights[] = sprintf( esc_html__( 'Bounce rate has increased by %.1f points.', 'qa-heatmap-analytics' ), $bounce_rate_change );
					} else {
						/* translators: %f: bounce rate change in points */
						$insights[] = sprintf( esc_html__( 'Bounce rate has improved by %.1f points.', 'qa-heatmap-analytics' ), abs( $bounce_rate_change ) );
					}
				}

				if ( $current_avg_browse_sec > 120 ) {
					$insights[] = esc_html__( 'Average time on page is high, indicating that visitors are engaging with the content.', 'qa-heatmap-analytics' );
				}

				if ( ! empty( $insights ) ) {
					$execute[] = array(
						'msg' => $this->wrap_implode( ' ', $insights ),
					);
				}

				if ( $previous_pv_count > 0 ) {
					if ( $pv_change > 0 ) {
						$pv_change_str = '<span class="qahm-change-increase">↑ +' . esc_html( number_format( $pv_change, 0 ) ) . '%</span>';
					} elseif ( $pv_change < 0 ) {
						$pv_change_str = '<span class="qahm-change-decrease">↓ ' . esc_html( number_format( $pv_change, 0 ) ) . '%</span>';
					} else {
						$pv_change_str = '<span class="qahm-change-neutral">→ 0%</span>';
					}
				} else {
					$pv_change_str = '-';
				}

				if ( $previous_session_count > 0 ) {
					if ( $session_change > 0 ) {
						$session_change_str = '<span class="qahm-change-increase">↑ +' . esc_html( number_format( $session_change, 0 ) ) . '%</span>';
					} elseif ( $session_change < 0 ) {
						$session_change_str = '<span class="qahm-change-decrease">↓ ' . esc_html( number_format( $session_change, 0 ) ) . '%</span>';
					} else {
						$session_change_str = '<span class="qahm-change-neutral">→ 0%</span>';
					}
				} else {
					$session_change_str = '-';
				}

				if ( $previous_avg_browse_sec > 0 ) {
					if ( $browse_time_change > 0 ) {
						$browse_time_change_str = '<span class="qahm-change-increase">↑ +' . esc_html( number_format( $browse_time_change, 0 ) ) . '%</span>';
					} elseif ( $browse_time_change < 0 ) {
						$browse_time_change_str = '<span class="qahm-change-decrease">↓ ' . esc_html( number_format( $browse_time_change, 0 ) ) . '%</span>';
					} else {
						$browse_time_change_str = '<span class="qahm-change-neutral">→ 0%</span>';
					}
				} else {
					$browse_time_change_str = '-';
				}

				if ( $previous_session_count > 0 ) {
					if ( $bounce_rate_change > 0 ) {
						$bounce_rate_change_str = '<span class="qahm-change-decrease">↑ +' . esc_html( number_format( $bounce_rate_change, 1 ) ) . 'pt</span>';
					} elseif ( $bounce_rate_change < 0 ) {
						$bounce_rate_change_str = '<span class="qahm-change-increase">↓ ' . esc_html( number_format( $bounce_rate_change, 1 ) ) . 'pt</span>';
					} else {
						$bounce_rate_change_str = '<span class="qahm-change-neutral">→ 0pt</span>';
					}
				} else {
					$bounce_rate_change_str = '-';
				}

				$execute[] = array(
					'data' => array(
						/* translators: %s: period label (e.g., "the last 7 days") */
						'title'  => sprintf( esc_html__( 'Page overview (%s)', 'qa-heatmap-analytics' ), $this->get_period_label() ),
						'header' => array(
							array(
								'label' => esc_html__( 'Metric', 'qa-heatmap-analytics' ),
								'data'  => 'metric',
							),
							array(
								'label' => esc_html__( 'Value', 'qa-heatmap-analytics' ),
								'data'  => 'value',
							),
							array(
								'label' => esc_html__( 'Change vs previous period', 'qa-heatmap-analytics' ),
								'data'  => 'change',
							),
						),
						'body'   => array(
							array(
								'metric' => esc_html_x(
									'Pageviews',
									'Metric label: number of pageviews in a report.',
									'qa-heatmap-analytics'
								),
								'value'  => number_format( $current_pv_count ),
								'change' => $pv_change_str,
							),
							array(
								'metric' => esc_html_x(
									'Sessions',
									'Metric label: number of sessions (visits) in a report.',
									'qa-heatmap-analytics'
								),
								'value'  => number_format( $current_session_count ),
								'change' => $session_change_str,
							),
							array(
								'metric' => esc_html_x(
									'Average time on page',
									'Metric label: average time users spend on the page in a report.',
									'qa-heatmap-analytics'
								),
								'value'  => gmdate( 'i:s', (int) $current_avg_browse_sec ),
								'change' => $browse_time_change_str,
							),
							array(
								'metric' => esc_html_x(
									'Bounce rate',
									'Metric label: bounce rate in a report.',
									'qa-heatmap-analytics'
								),
								'value'  => number_format( $current_bounce_rate, 1 ) . '%',
								'change' => $bounce_rate_change_str,
							),
						),
					),
				);

				if ( empty( $previous_page_pv_data ) ) {
					$previous_period_start_days = $this->data_period_days * 2;
					$previous_period_end_days   = $this->data_period_days + 1;

					$execute[] = array(
						'msg' => sprintf(
							'⚠️ %s',
							sprintf(
							/* translators: 1: start days ago, 2: end days ago */
								esc_html__( 'No data was recorded for the comparison period (%1$d to %2$d days ago), so changes vs the previous period are not shown.', 'qa-heatmap-analytics' ),
								$previous_period_end_days,
								$previous_period_start_days
							)
						),
					);
				}

				$execute[] = array(
					'msg' => esc_html__( 'Would you like to check a different period?', 'qa-heatmap-analytics' ),
				);

				$period_options = array();

				if ( $this->data_period_days !== 7 ) {
					$period_options[] = array(
						'text'   => esc_html__( 'View last 7 days', 'qa-heatmap-analytics' ),
						'action' => array(
							'next' => 'view_data',
							'free' => array( 'period_days' => 7 ),
						),
					);
				}

				if ( $this->data_period_days !== 30 ) {
					$period_options[] = array(
						'text'   => esc_html__( 'View last 30 days', 'qa-heatmap-analytics' ),
						'action' => array(
							'next' => 'view_data',
							'free' => array( 'period_days' => 30 ),
						),
					);
				}

				if ( $this->data_period_days !== 90 ) {
					$period_options[] = array(
						'text'   => esc_html__( 'View last 90 days', 'qa-heatmap-analytics' ),
						'action' => array(
							'next' => 'view_data',
							'free' => array( 'period_days' => 90 ),
						),
					);
				}

				$period_options[] = array(
					'text'   => esc_html__( 'View heatmap', 'qa-heatmap-analytics' ),
					'action' => array(
						'next' => 'view_heatmap',
						'free' => array( 'period_days' => $this->data_period_days ),
					),
				);

				$period_options[] = array(
					'text'   => esc_html__( 'Back to start', 'qa-heatmap-analytics' ),
					'action' => array( 'next' => 'init' ),
				);

				$execute[] = array( 'cmd' => $period_options );
				break;

			case 'view_heatmap':
				$site_url    = get_site_url();
				$parse_url   = wp_parse_url( $site_url );
				$domain_url  = $this->to_domain_url( $parse_url );
				$tracking_id = $this->get_tracking_id( $domain_url );

				$current_page_url = isset( $_POST['current_url'] ) ? esc_url_raw( wp_unslash( $_POST['current_url'] ) ) : '';

				if ( empty( $current_page_url ) ) {
					$execute[] = array(
						'msg' => esc_html__( 'Couldn’t retrieve this page’s URL. Please reload the page.', 'qa-heatmap-analytics' ),
					);

					$execute[] = array(
						'cmd' => array(
							array(
								'text'   => esc_html__( 'Try again', 'qa-heatmap-analytics' ),
								'action' => array(
									'next' => 'view_heatmap',
								),
							),
							array(
								'text'   => esc_html__( 'Back to start', 'qa-heatmap-analytics' ),
								'action' => array(
									'next' => 'init',
								),
							),
						),
					);
					break;
				}

				$url_hash = hash( 'fnv164', $current_page_url );

				global $wpdb;
				$table_name = $wpdb->prefix . 'qa_pages';
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This SQL query uses placeholders and $wpdb->prepare(), but it may trigger warnings due to the dynamic construction of the SQL string. Direct database call is necessary in this case due to the complexity of the SQL query. Caching would not provide significant performance benefits in this context.
				$page_data = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT page_id, url FROM {$table_name} WHERE url_hash = %s AND tracking_id = %s ORDER BY page_id DESC LIMIT 1",
						$url_hash,
						$tracking_id
					)
				);

				if ( $wpdb->last_error ) {
					global $qahm_log;
					$qahm_log->error( 'Page Analysis Assistant - Database error: ' . $wpdb->last_error );

					$execute[] = array(
						'msg' => esc_html__( 'A database error occurred. Please wait a moment and try again.', 'qa-heatmap-analytics' ),
					);

					$execute[] = array(
						'cmd' => array(
							array(
								'text'   => esc_html__( 'Try again', 'qa-heatmap-analytics' ),
								'action' => array(
									'next' => 'view_heatmap',
								),
							),
							array(
								'text'   => esc_html__( 'Back to start', 'qa-heatmap-analytics' ),
								'action' => array(
									'next' => 'init',
								),
							),
						),
					);
					break;
				}

				if ( $page_data && $page_data->url !== $current_page_url ) {
					$page_data = null;
				}

				if ( ! $page_data ) {
					$execute[] = array(
						'msg' => esc_html__( 'No visits to this page have been recorded yet.', 'qa-heatmap-analytics' ),
					);

					$execute[] = array(
						'msg' => esc_html__( 'Data collection will start as visitors come to this page.', 'qa-heatmap-analytics' ),
					);

					$execute[] = array(
						'cmd' => array(
							array(
								'text'   => esc_html__( 'View page overview', 'qa-heatmap-analytics' ),
								'action' => array(
									'next' => 'view_data',
								),
							),
							array(
								'text'   => esc_html__( 'Back to start', 'qa-heatmap-analytics' ),
								'action' => array(
									'next' => 'init',
								),
							),
						),
					);
					break;
				}

				$device_labels = array(
					'dsk' => '🖥️ desktop',
					'tab' => '🔲 tablet',
					'smp' => '📱 mobile',
				);

				$device_ids = array(
					'dsk' => 1,
					'tab' => 2,
					'smp' => 3,
				);

				$end_date   = gmdate( 'Y-m-d 23:59:59', strtotime( '-1 day' ) );
				$start_date = gmdate( 'Y-m-d 00:00:00', strtotime( '-' . $this->data_period_days . ' days' ) );

				global $qahm_view_heatmap;

				$execute[] = array(
					/* translators: %s: period label (e.g., "the last 7 days") */
					'msg' => sprintf( esc_html__( 'Heatmap for %s.', 'qa-heatmap-analytics' ), $this->get_period_label() ),
				);

				$execute[] = array(
					'msg' => esc_html__( 'Click a device link to open its heatmap.', 'qa-heatmap-analytics' ),
				);

				$links_html = '<ul style="list-style: none; padding: 0; margin: 10px 0;">';

				foreach ( $device_labels as $device_name => $device_label ) {
					$device_id  = $device_ids[ $device_name ];
					$version_id = $qahm_view_heatmap->get_version_id( $page_data->page_id, $device_id );

					if ( $version_id ) {
						$heatmap_base_url = plugin_dir_url( __FILE__ ) . 'heatmap-view.php';
						$heatmap_url      = add_query_arg(
							array(
								'version_id'      => $version_id,
								'start_date'      => urlencode( $start_date ),
								'end_date'        => urlencode( $end_date ),
								'is_landing_page' => 0,
								'tracking_id'     => $tracking_id,
							),
							$heatmap_base_url
						);

						$links_html .= '<li style="margin-bottom: 8px;">';
						$links_html .= '<a href="' . esc_url( $heatmap_url ) . '" target="_blank" rel="noopener noreferrer" class="qahm-heatmap-link">';
						$links_html .= $device_label;
						$links_html .= '</a>';
						$links_html .= '</li>';
					} else {
						$links_html .= '<li style="margin-bottom: 8px; color: #999;">';
						/* translators: %s: device label (e.g., "Desktop") */
						$links_html .= sprintf( esc_html__( '%s (No data)', 'qa-heatmap-analytics' ), esc_html( $device_label ) );
						$links_html .= '</li>';
					}
				}

				$links_html .= '</ul>';

				$execute[] = array(
					'msg' => $links_html,
				);

				$execute[] = array(
					'msg' => esc_html__( 'Would you like to check a different period?', 'qa-heatmap-analytics' ),
				);

				$period_options = array();

				if ( $this->data_period_days !== 7 ) {
					$period_options[] = array(
						'text'   => esc_html__( 'View last 7 days', 'qa-heatmap-analytics' ),
						'action' => array(
							'next' => 'view_heatmap',
							'free' => array( 'period_days' => 7 ),
						),
					);
				}

				if ( $this->data_period_days !== 30 ) {
					$period_options[] = array(
						'text'   => esc_html__( 'View last 30 days', 'qa-heatmap-analytics' ),
						'action' => array(
							'next' => 'view_heatmap',
							'free' => array( 'period_days' => 30 ),
						),
					);
				}

				if ( $this->data_period_days !== 90 ) {
					$period_options[] = array(
						'text'   => esc_html__( 'View last 90 days', 'qa-heatmap-analytics' ),
						'action' => array(
							'next' => 'view_heatmap',
							'free' => array( 'period_days' => 90 ),
						),
					);
				}

				$period_options[] = array(
					'text'   => esc_html__( 'View page overview', 'qa-heatmap-analytics' ),
					'action' => array(
						'next' => 'view_data',
						'free' => array( 'period_days' => $this->data_period_days ),
					),
				);

				$period_options[] = array(
					'text'   => esc_html__( 'Back to start', 'qa-heatmap-analytics' ),
					'action' => array( 'next' => 'init' ),
				);

				$execute[] = array( 'cmd' => $period_options );
				break;
			default:
				$execute[] = array(
					'msg' => esc_html__( 'Unknown action.', 'qa-heatmap-analytics' ),
				);
				break;
		}

		return array(
			'execute' => $execute,
		);
	}
}
