<?php
defined( 'ABSPATH' ) || exit;
/**
 * データを見る画面で共通する処理を書くクラス
 * なのでこのクラスは単一でページを生成できるものではない
 * 継承用として作っているのでクラス化してる
 */


class QAHM_Admin_Page_Dataviewer extends QAHM_Admin_Page_Base {

	// アクセス制限情報を取得。値は参照渡しで返す
	protected function regist_inline_script() {
		global $qahm_data_api;
		//tracking_id
		// tracking_id is used only for display switching (no state changes). wp_unslash() and sanitize_text_field() are applied inside sanitize_tracking_id().
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended
		$tracking_id_raw = isset( $_GET['tracking_id'] ) ? $this->sanitize_tracking_id( wp_unslash( $_GET['tracking_id'] ) ) : 'all';
		$tracking_id     = $this->get_safe_tracking_id( $tracking_id_raw );
		$pvterm_dates    = $qahm_data_api->get_pvterm_both_end_date( $tracking_id );
		if ( ! empty( $pvterm_dates ) ) {
			$pvterm_start  = $pvterm_dates['start'];
			$pvterm_latest = $pvterm_dates['latest'];
		} else {
			$pvterm_start  = null;
			$pvterm_latest = null;
		}

		$scripts                       = $this->get_common_inline_script();
		$scripts['tracking_id']        = $tracking_id;
		$scripts['pvterm_start_date']  = $pvterm_start;
		$scripts['pvterm_latest_date'] = $pvterm_latest;
		$scripts['wp_time_adj']        = get_option( 'gmt_offset' );
		$scripts['wp_lang_set']        = get_bloginfo( 'language' );
		//mkadd 202206 for goal
		$scripts['goalsJson'] = $qahm_data_api->get_goals_json( $tracking_id );
		if ( $scripts['goalsJson'] === 'null' ) {
			$scripts['goalsJson'] = null;
		}
		$scripts['siteinfoJson'] = $qahm_data_api->get_siteinfo_json( $tracking_id );
		wp_add_inline_script( QAHM_NAME . '-common', 'var ' . QAHM_NAME . ' = ' . QAHM_NAME . ' || {}; let ' . QAHM_NAME . 'Obj = ' . $this->wrap_json_encode( $scripts ) . '; ' . QAHM_NAME . ' = Object.assign( ' . QAHM_NAME . ', ' . QAHM_NAME . 'Obj );', 'before' );
	}

	// 翻訳文はどの画面でどのテキストを使っているか把握不可なのでこの関数で一括で登録
	protected function regist_localize_script() {
		$localize                           = $this->get_common_localize_script();
		$localize['table_tanmatsu']         = esc_html__( 'Device', 'qa-heatmap-analytics' );
		$localize['table_ridatsujikoku']    = esc_html__( 'Time Session End', 'qa-heatmap-analytics' );
		$localize['table_id']               = esc_html__( 'ID', 'qa-heatmap-analytics' );
		$localize['table_id_tooltip']       = esc_html__( 'An ID which was uniquely assigned by QA to a user.', 'qa-heatmap-analytics' );
		$localize['table_1page_me']         = esc_html__( 'Landing Page', 'qa-heatmap-analytics' );
		$localize['table_ridatsu_page']     = esc_html__( 'Exit Page', 'qa-heatmap-analytics' );
		$localize['table_sanshoumoto']      = esc_html__( 'Source', 'qa-heatmap-analytics' );
		$localize['table_pv']               = esc_html__( 'Pageviews', 'qa-heatmap-analytics' );
		$localize['table_site_taizaijikan'] = esc_html__( 'Time on Site', 'qa-heatmap-analytics' );
		$localize['table_saisei']           = esc_html__( 'Replay', 'qa-heatmap-analytics' );
		$localize['table_page_title']       = esc_html__( 'Page Title', 'qa-heatmap-analytics' );
		$localize['table_data_total']       = esc_html__( 'Data Total', 'qa-heatmap-analytics' );
		$localize['table_page_version']     = esc_html__( 'Page Version : Span', 'qa-heatmap-analytics' );

		$localize['table_user_type']            = esc_html__( 'User Type', 'qa-heatmap-analytics' );
		$localize['table_device_cat']           = esc_html__( 'Device Category', 'qa-heatmap-analytics' );
		$localize['table_user']                 = esc_html__( 'Users', 'qa-heatmap-analytics' );
		$localize['table_new_user']             = esc_html__( 'New Users', 'qa-heatmap-analytics' );
		$localize['table_session']              = esc_html__( 'Sessions', 'qa-heatmap-analytics' );
		$localize['table_bounce_rate']          = esc_html__( 'Bounce Rate', 'qa-heatmap-analytics' );
		$localize['table_page_session']         = esc_html__( 'Pages / Session', 'qa-heatmap-analytics' );
		$localize['table_avg_session_time']     = esc_html__( 'Avg. Time on Site', 'qa-heatmap-analytics' );
		$localize['table_channel']              = esc_html__( 'Channel', 'qa-heatmap-analytics' );
		$localize['table_referrer']             = esc_html__( 'Source', 'qa-heatmap-analytics' );
		$localize['table_media']                = esc_html__( 'Medium', 'qa-heatmap-analytics' );
		$localize['table_title']                = esc_html__( 'Title', 'qa-heatmap-analytics' );
		$localize['table_url']                  = esc_html__( 'URL', 'qa-heatmap-analytics' );
		$localize['table_new_session_rate']     = esc_html__( '% New Sessions', 'qa-heatmap-analytics' );
		$localize['table_edit']                 = esc_html__( 'Edit', 'qa-heatmap-analytics' );
		$localize['table_heatmap']              = esc_html__( 'Heatmap', 'qa-heatmap-analytics' );
		$localize['table_past_session']         = esc_html__( 'Sessions (Earliest 7days)', 'qa-heatmap-analytics' );
		$localize['table_recent_session']       = esc_html__( 'Sessions (Latest 7days)', 'qa-heatmap-analytics' );
		$localize['table_growth_rate']          = esc_html__( 'Growth Rate', 'qa-heatmap-analytics' );
		$localize['table_page_view_num']        = esc_html__( 'Pageviews', 'qa-heatmap-analytics' );
		$localize['table_page_visit_num']       = esc_html__( 'Unique Pageviews', 'qa-heatmap-analytics' );
		$localize['table_page_avg_stay_time']   = esc_html__( 'Avg. Time on Page', 'qa-heatmap-analytics' );
		$localize['table_entrance_num']         = esc_html__( 'Entrance', 'qa-heatmap-analytics' );
		$localize['table_exit_rate']            = esc_html__( '% Exit', 'qa-heatmap-analytics' );
		$localize['table_goal_conversion_rate'] = esc_html__( 'Goal Conversion Rate', 'qa-heatmap-analytics' );
		$localize['table_goal_completions']     = esc_html__( 'Goal Completions', 'qa-heatmap-analytics' );
		$localize['table_goal_value']           = esc_html__( 'Goal Value', 'qa-heatmap-analytics' );
		$localize['table_page_value']           = esc_html__( 'Page Value', 'qa-heatmap-analytics' );
		$localize['table_graph']                = esc_html__( 'Graph', 'qa-heatmap-analytics' );
		$localize['table_total']                = esc_html__( 'Total', 'qa-heatmap-analytics' );

		$localize['graph_users']           = esc_html_x( 'Users', 'a label in a graph', 'qa-heatmap-analytics' );
		$localize['graph_sessions']        = esc_html_x( 'Sessions', 'a lebel in a graph', 'qa-heatmap-analytics' );
		$localize['graph_pvs']             = esc_html_x( 'Pageviews', 'a label in a graph', 'qa-heatmap-analytics' );
		$localize['graph_posts']           = esc_html_x( 'Posts', 'a label in a graph', 'qa-heatmap-analytics' );
		$localize['graph_hourly_sessions'] = esc_html_x( 'Hourly Sessions', 'a label in a graph', 'qa-heatmap-analytics' );
		$localize['graph_hours']           = esc_html_x( 'Hours', 'a label in a graph', 'qa-heatmap-analytics' );
		$localize['sessions_today']        = esc_html_x( 'Hourly Sessions Today', 'a title of a graph', 'qa-heatmap-analytics' );

		$localize['cnv_all_goals']         = esc_html__( 'All Goals', 'qa-heatmap-analytics' );
		$localize['cnv_graph_present']     = esc_html__( 'Present', 'qa-heatmap-analytics' );
		$localize['cnv_graph_goal']        = esc_html__( 'Completions Target', 'qa-heatmap-analytics' );
		$localize['cnv_graph_completions'] = esc_html__( 'Completions', 'qa-heatmap-analytics' );

		$localize['calender_kinou']      = esc_html_x( 'Yesterday', 'a word in a date range picker', 'qa-heatmap-analytics' );
		$localize['calender_kako7days']  = esc_html_x( 'Last 7 Days', 'words in a date range picker', 'qa-heatmap-analytics' );
		$localize['calender_kako30days'] = esc_html_x( 'Last 30 Days', 'words in a date range picker', 'qa-heatmap-analytics' );
		$localize['calender_kongetsu']   = esc_html_x( 'This Month', 'words in a date range picker', 'qa-heatmap-analytics' );
		$localize['calender_sengetsu']   = esc_html_x( 'Last Month', 'words in a date range picker', 'qa-heatmap-analytics' );
		$localize['calender_erabu']      = esc_html_x( 'Custom Range', 'words in a date range picker', 'qa-heatmap-analytics' );
		$localize['calender_cancel']     = esc_html_x( 'Cancel', 'a word in a date range picker', 'qa-heatmap-analytics' );
		$localize['calender_ok']         = esc_html_x( 'Apply', 'a word in a date range picker', 'qa-heatmap-analytics' );
		$localize['calender_kara']       = esc_html_x( '-', 'a connector between dates in a date range picker', 'qa-heatmap-analytics' );

		/* translators: placeholders represent the start and end dates for the download */
		$localize['download_msg1'] = esc_html__( 'Download the data from %1$s to %2$s.', 'qa-heatmap-analytics' );
		$localize['download_msg2'] = esc_html__( '*If the data size is too large, depending on the server, it may not be possible to download. In that case, try shortening the date range.', 'qa-heatmap-analytics' );
		/* translators: placeholders represent the start and end dates for the download */
		$localize['download_done_nodata'] = esc_html__( 'No data between %1$s and %2$s.', 'qa-heatmap-analytics' );
		$localize['download_error1']      = esc_html__( 'A communication error occurred when acquiring data.', 'qa-heatmap-analytics' );
		$localize['download_error2']      = esc_html__( 'It may be acquired too much data. Please shorten the date range and try again. (It depends on the server, but in general, it would be better to make the total number of PVs for the period less than 10,000.)', 'qa-heatmap-analytics' );

		$localize['ds_cyusyutsu_kensu']  = esc_html_x( 'session results', 'displaying the number of filtered sessions', 'qa-heatmap-analytics' );
		$localize['ds_cyusyutsu_button'] = esc_html_x( 'Apply', 'value of the button in narrow-down-data-section', 'qa-heatmap-analytics' );
		$localize['ds_cyusyutsu_cyu']    = esc_html_x( 'Filtering...', 'value of the button in narrow-down-data-section', 'qa-heatmap-analytics' );
		$localize['ds_cyusyutsu_error1'] = esc_html_x( ': NO data found.', 'error message1 in narrow-down-data-section', 'qa-heatmap-analytics' );
		$localize['ds_cyusyutsu_error2'] = esc_html_x( 'A communication error occurred when acquiring data. It may be acquired too much data. Please narrow down the condition and try again.', 'error message2 in narrow-down-data-section', 'qa-heatmap-analytics' );
		/* translators: placeholders represent the number of days for displaying report data */
		$localize['ds_cyusyutsu_error3'] = esc_html_x( 'Showing for the last %d day(s) data because of too many data.', 'error message1 in narrow-down-data-section', 'qa-heatmap-analytics' );

		//$localize['good'] = esc_html__( 'Good', 'qa-heatmap-analytics' );
		//$localize['caution'] = esc_html__( 'Caution', 'qa-heatmap-analytics' );
		//$localize['kanren_data'] = esc_html__('関連データ', 'qa-heatmap-analytics');

		$localize['realtime_replay_alert1'] = esc_html__( 'Unable to replay the data', 'qa-heatmap-analytics' );
		$localize['realtime_replay_alert2'] = sprintf(
			/* translators: %1$s and %2$s are anchor tags for the Audience page link */
			esc_html__( 'To view older session data, please refer to the %1$sAudience%2$s page\'s session recording table.', 'qa-heatmap-analytics' ),
			'<a href="admin.php?page=qahm-user" target="_blank">',
			'</a>'
		);
		$localize['switch_txt_all_session1'] = esc_html__( 'All Sessions', 'qa-heatmap-analytics' );
		$localize['switch_txt_all_session2'] = esc_html__( 'Latest 10,000 sessions', 'qa-heatmap-analytics' );

		// Brains
		$localize['select_agent']             = esc_html__( 'Choose an assistant', 'qa-heatmap-analytics' );
		$localize['switch_agent']             = esc_html__( 'Switch assistant', 'qa-heatmap-analytics' );
		$localize['end_command_label']        = esc_html_x( 'End', 'Button label for ending the Assistants interaction', 'qa-heatmap-analytics' );
		$localize['download_more_assistants'] = esc_html__( 'Browse More Assistants', 'qa-heatmap-analytics' );
		$locale                               = get_locale();
		if ( $this->wrap_strpos( $locale, 'ja' ) === 0 ) {
			$localize['download_assistant_url'] = 'https://quarka.org/assistants';
		} else {
			$localize['download_assistant_url'] = 'https://quarka.org/en-assistants';
		}
		$localize['drag_to_reorder']                 = esc_html__( 'You can rearrange assistants by dragging them.', 'qa-heatmap-analytics' );
		$localize['no_assistant_installed_title']    = esc_html__( 'No Assistants Ready', 'qa-heatmap-analytics' );
		$localize['assistant_installation_required'] = esc_html__( 'Get insights from your website data.', 'qa-heatmap-analytics' ) . '<br>' . esc_html__( ' Install or activate an assistant to get started.', 'qa-heatmap-analytics' );
		$localize['download_first_assistant']        = esc_html__( 'Browse Assistants', 'qa-heatmap-analytics' );

		wp_localize_script( QAHM_NAME . '-common', QAHM_NAME . 'l10n', $localize );
		$this->regist_qa_table_localize_script();
	}

	/**
	 * QA Table用 localization(l10n) テキスト（admin-page共通）
	 */
	protected function regist_qa_table_localize_script() {
		$locale = get_locale();
		$locale = $this->normalize_locale( $locale );
		$path   = plugin_dir_path( __FILE__ ) . 'languages/qa-table-' . $locale . '.json';

		if ( file_exists( $path ) ) {
			$json_data    = $this->wrap_get_contents( $path );
			$translations = json_decode( $json_data, true );

			if ( is_array( $translations ) ) {
				wp_localize_script( QAHM_NAME . '-qa-table', 'qaTableL10n', $translations );
			}
		}
	}


	// 期間の帯を展開
	protected function create_date_range() {
		?>
		<div class="qa-zero-date-range">
			<div class="qa-zero-date-range__title">
			<?php esc_html_e( 'Date Range', 'qa-heatmap-analytics' ); ?>
			</div>
			<div class="qa-zero-date-range__text-area">
				<svg xmlns="http://www.w3.org/2000/svg" width="18" height="20" viewBox="0 0 18 20" fill="none">
					<path d="M8 12V10H10V12H8ZM4 12V10H6V12H4ZM12 12V10H14V12H12ZM8 16V14H10V16H8ZM4 16V14H6V16H4ZM12 16V14H14V16H12ZM0 20V2H3V0H5V2H13V0H15V2H18V20H0ZM2 18H16V8H2V18ZM2 6H16V4H2V6Z" fill="#757575"/>
				</svg>
				<input type="text" id="datepicker-base-textbox" class="qa-zero-date-range__input-area">
			</div>
		</div>
		<?php
	}
}
