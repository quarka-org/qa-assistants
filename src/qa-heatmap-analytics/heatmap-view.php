<?php
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone SHORTINIT file: loaded directly, bootstraps WordPress internally. Do not exit.
}
try {
	// URLパラメータの格納
	$version_id      = (int) filter_input( INPUT_GET, 'version_id' );
	$start_date      = filter_input( INPUT_GET, 'start_date' );
	$end_date        = filter_input( INPUT_GET, 'end_date' );
	$tracking_id     = filter_input( INPUT_GET, 'tracking_id' );
	$is_landing_page = (int) filter_input( INPUT_GET, 'is_landing_page' );
	$source          = filter_input( INPUT_GET, 'source' );
	$media           = filter_input( INPUT_GET, 'media' );
	$campaign        = filter_input( INPUT_GET, 'campaign' );
	$goal            = filter_input( INPUT_GET, 'goal' );

	$config_path = dirname( __DIR__, 3 ) . '/wp-content/qa-zero-data/qa-config.php';

	if ( file_exists( $config_path ) ) {
		require_once $config_path;
	}

	if ( defined( 'QAHM_CONFIG_WP_ROOT_PATH' ) && file_exists( QAHM_CONFIG_WP_ROOT_PATH . 'wp-load.php' ) ) {
		require_once QAHM_CONFIG_WP_ROOT_PATH . 'wp-load.php';
		require_once QAHM_CONFIG_WP_ROOT_PATH . 'wp-settings.php';
	} else {
		require_once '../../../wp-load.php';
		require_once '../../../wp-settings.php';
	}

	$page_id = null;
	if ( $version_id ) {
		global $qahm_db;
		$table_name = 'view_page_version_hist';
		$query      = 'SELECT page_id FROM ' . $qahm_db->prefix . $table_name . ' WHERE version_id = %d';
		$result     = $qahm_db->get_results( $qahm_db->prepare( $query, $version_id ), ARRAY_A );
		if ( $result && ! empty( $result[0]['page_id'] ) ) {
			$page_id = (int) $result[0]['page_id'];
		}
	}

	// GETパラメーター判定
	if ( ! $version_id || ! $start_date || ! $end_date || ! $tracking_id ) {
		throw new Exception( 'The required URL parameters are missing.' );
	}
	$file_base_name = $version_id . '_' . preg_replace( '/[\s:-]+/', '', $start_date ) . '_' . preg_replace( '/[\s:-]+/', '', $end_date ) . '_' . $is_landing_page . '_' . $tracking_id;

	global $qahm_time;
	global $wp_filesystem;
	$heatmap_view_work_dir = $qahm_view_heatmap->get_data_dir_path( 'heatmap-view-work' );
	$heatmap_view_work_url = $qahm_view_heatmap->get_heatmap_view_work_dir_url();
	$file_info             = $heatmap_view_work_dir . $file_base_name . '-info.php';
	if ( ! $wp_filesystem->exists( $file_info ) ) {
		if ( $version_id && $start_date && $end_date && $tracking_id ) {
			echo '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>Generating data...</body></html>';
			$qahm_view_heatmap->create_heatmap_file( $start_date, $end_date, $version_id, $is_landing_page, $tracking_id );

			// 現在のURLを取得
			$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
			$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Comparison only, value not stored.
			$https = ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on' ) ? 'https' : 'http';

			$current_url = esc_url( "{$https}://{$host}{$uri}" );

			// 追加または上書きしたいパラメータを指定
			$new_params = array(
				'version_id'  => $version_id,
				'start_date'  => $start_date,
				'end_date'    => $end_date,
				'tracking_id' => $tracking_id,
			);

			// 現在のURLから既存のクエリパラメータを取得
			$url_parts    = wp_parse_url( $current_url );
			$query_params = array();
			if ( isset( $url_parts['query'] ) ) {
				parse_str( $url_parts['query'], $query_params );
			}

			// 新しいパラメータをマージ（既存のパラメータは上書きされる）
			$query_params = array_merge( $query_params, $new_params );

			// 新しいURLを構築
			$new_query_string = http_build_query( $query_params );
			$new_url          = $url_parts['scheme'] . '://' . $url_parts['host'] . $url_parts['path'] . '?' . $new_query_string;

			// リダイレクト
			header( "Location: $new_url" );
			exit;

		} else {
			throw new Exception( 'Query string has no value.' );
		}
	}
	$content_info_ary = $wp_filesystem->get_contents_array( $file_info );


	// info ファイル読み込み
	foreach ( $content_info_ary as $content_info ) {
		$exp_info = explode( '=', $content_info );
		switch ( $exp_info[0] ) {
			case 'data_num':
				$data_num = (int) trim( $exp_info[1] );
				break;
			case 'wp_qa_type':
				$wp_qa_type = trim( $exp_info[1] );
				break;
			case 'wp_qa_id':
				$wp_qa_id = (int) trim( $exp_info[1] );
				break;
			case 'version_no':
				$version_no = (int) trim( $exp_info[1] );
				break;
			case 'device_name':
				$device_name = trim( $exp_info[1] );
				break;
			case 'time_on_page':
				$time_on_page = (float) trim( $exp_info[1] );
				$time_on_page = $qahm_time->seconds_to_timestr( $time_on_page );
				$time_on_page = substr( $time_on_page, strlen( '00:' ) );
				break;
			case 'separate_data_num':
				$separate_data_num = trim( $exp_info[1] );
				break;
			case 'separate_total_stay_time':
				$separate_total_stay_time = trim( $exp_info[1] );
				break;
			case 'all_version_ary':
				$all_version_ary = trim( $exp_info[1] );
				$all_version_ary = json_decode( $all_version_ary, true );
				break;
			case 'device_version_ary':
				$device_version_ary = trim( $exp_info[1] );
				$device_version_ary = json_decode( $device_version_ary, true );
				break;
		}
	}

	if ( $source || $media || $campaign || $goal ) {
		$data_num     = '--';
		$time_on_page = '--:--';
	}


	// ログイン判定
	if ( ! $qahm_view_heatmap->check_access_role( 'qazero-view' ) ) {
		throw new Exception( 'You do not have access privileges.' );
	}

	// 翻訳ファイルの読み込みはここでしなくてもプラグイン全体で読み込まれている
	//load_plugin_textdomain( 'qa-heatmap-analytics', false, plugin_basename( dirname( __FILE__ ) ) . '/languages' );

	$ajax_url = admin_url( 'admin-ajax.php' );
	//mkdummy for brainsai
	$nonce_api      = wp_create_nonce( QAHM_Data_Api::NONCE_API );
	$plugin_dir_url = plugin_dir_url( __FILE__ );

	$pvterm_start_date  = $qahm_view_heatmap->get_pvterm_start_date( $tracking_id );
	$pvterm_latest_date = $qahm_view_heatmap->get_pvterm_latest_date( $tracking_id );

	/*
	$text_data_num = esc_html__( 'Number of data', 'qa-heatmap-analytics' );
	$text_data_num = '<i class="fas fa-users"></i> ' . $text_data_num . ': ' . $data_num;
	$text_data_num = '<span class="qahm-tooltip-bottom" data-qahm-tooltip="' . $qahm_view_heatmap->qa_langesc_attr__( 'このページでヒートマップデータを記録した数です。PV数に近い値になりますが、数秒で直帰した場合などは記録されません。', 'qa-heatmap-analytics' ) . '">' . $text_data_num . '</span>';
	*/
	// データ数
	$data_num_title   = esc_html__( 'Valid Data', 'qa-heatmap-analytics' );
	$data_num_tooltip = esc_attr__( 'The amount of valid data currently available for heatmap analysis. Older data may be deleted based on your retention settings.', 'qa-heatmap-analytics' );
	$data_num_icon    = '<i class="fas fa-users"></i>';

	// ヘルプ
	$help_title   = esc_html__( 'Help', 'qa-heatmap-analytics' );
	$help_tooltip = esc_attr__( 'Click to open Help page for heatmap view.', 'qa-heatmap-analytics' );
	$help_icon    = '<i class="far fa-question-circle"></i>';

	//QA ZERO
	//フィルター
	$filter_title   = '<button class="filter-data-button">' . esc_html__( 'Filter', 'qa-heatmap-analytics' ) . '</button>';
	$filter_tooltip = esc_attr__( 'Filter the data based on the selected conditions.', 'qa-heatmap-analytics' );
	$filter_icon    = '<i class="fas fa-filter"></i>';
	//QA ZERO END

	// スクロールマップ
	$scro_map_title   = esc_html__( 'Scroll Map', 'qa-heatmap-analytics' );
	$scro_map_tooltip = esc_attr__( 'Shows how far down users scrolled on the page. Areas viewed by more users appear warmer in color.', 'qa-heatmap-analytics' );
	$scro_map_icon    = '<img src="' . $qahm_view_heatmap->get_img_dir_url() . 'scroll-map.svg">';

	// アテンションマップ
	$atte_map_title   = esc_html__( 'Attention Map', 'qa-heatmap-analytics' );
	$atte_map_tooltip = esc_attr__( 'Highlights the areas where users spent more time. Frequently viewed content appears in red.', 'qa-heatmap-analytics' );
	$atte_map_icon    = '<img src="' . $qahm_view_heatmap->get_img_dir_url() . 'attention-map.svg">';

	// クリックヒートマップ
	$heat_map_title   = esc_html__( 'Click Heatmap', 'qa-heatmap-analytics' );
	$heat_map_tooltip = esc_attr__( 'Visualizes where users clicked. Areas with more clicks are shown in red, indicating points of interest.', 'qa-heatmap-analytics' );
	$heat_map_icon    = '<img src="' . $qahm_view_heatmap->get_img_dir_url() . 'click-heat-map.svg">';

	// クリックカウントマップ
	$count_map_title   = esc_html__( 'Click Count Map', 'qa-heatmap-analytics' );
	$count_map_tooltip = esc_attr__( 'Displays the number of clicks on each element, such as buttons or banners. Useful for tracking click trends.', 'qa-heatmap-analytics' );
	$count_map_icon    = '<img src="' . $qahm_view_heatmap->get_img_dir_url() . 'click-count-map.svg">';

	// 平均滞在
	$time_on_page_title   = esc_html__( 'Average Time on Page', 'qa-heatmap-analytics' );
	$time_on_page_tooltip = esc_attr__( 'Displays the average time users spent on the page, based on heatmap data.', 'qa-heatmap-analytics' );
	$time_on_page_icon    = '<i class="fas fa-user-clock"></i>';

	// カレンダー
	$date_range_title   = '';
	$date_range_textbox = '<input type="text" id="heatmap-bar-date-range-text">';
	$date_range_tooltip = esc_attr__( 'Select the date range to display heatmap data.', 'qa-heatmap-analytics' );
	$date_range_icon    = '<i class="far fa-calendar-alt"></i>';

	// デバイス
	$device_version_title     = '';
	$device_version_selectbox = '<select id="heatmap-bar-device-version-selectbox" class="heatmap-bar-selectbox">';
	foreach ( $device_version_ary as $key => $value ) {
		$selected = '';
		$name     = '';
		if ( $device_name === $key ) {
			$selected = ' selected';
		}
		switch ( $key ) {
			case 'dsk':
				$name = 'desktop';
				break;
			case 'tab':
				$name = 'tablet';
				break;
			case 'smp':
				$name = 'mobile';
				break;
		}
		$device_version_selectbox .= '<option value="' . $value . '" ' . $selected . '>' . esc_html( $name ) . '</option>';
	}
	$device_version_selectbox .= '</select>';
	$device_version_tooltip    = esc_attr__( 'Displays the heatmap data for the selected device.', 'qa-heatmap-analytics' );
	$device_version_icon       = '<i class="fas fa-network-wired"></i>';

	// ページバージョン
	$page_version_title     = '';
	$page_version_selectbox = '<select id="heatmap-bar-page-version-selectbox" class="heatmap-bar-selectbox">';
	for ( $i = 0; $i < count( $all_version_ary ); $i++ ) {
		$version  = $all_version_ary[ $i ];
		$selected = '';
		if ( $version['version_id'] == $version_id ) {
			$selected = ' selected';
		}
		$page_version_selectbox .= '<option value="' . $version['version_id'] . '" ' . $selected . '>Ver.' . $version['version_no'] . ': ' . $version['version_period'] . '</option>';
	}
	$page_version_selectbox .= '</select>';
	$page_version_tooltip    = esc_attr__(
		'Displays the heatmap data based on the HTML during the selected period.
',
		'qa-heatmap-analytics'
	);
	$page_version_icon       = '<i class="fas fa-layer-group"></i>';

	// BrainsAI- mkdummy
	$brains_title = esc_html__( 'Brains', 'qa-heatmap-analytics' );
	$brains_icon  = '<img src="' . $qahm_view_heatmap->get_img_dir_url() . 'menu_brains.svg" width="20px" id="brain_heatmap_top">';


	// html構築
	if ( ! isset( $_COOKIE['qa_heatmap_bar_scroll'] ) &&
		! isset( $_COOKIE['qa_heatmap_bar_scroll'] ) &&
		! isset( $_COOKIE['qa_heatmap_bar_scroll'] ) &&
		! isset( $_COOKIE['qa_heatmap_bar_scroll'] ) ) {
		$cfg_scroll      = false;
		$cfg_attention   = true;
		$cfg_click_heat  = true;
		$cfg_click_count = false;
		setcookie( 'qa_heatmap_bar_scroll', 'false', time() + 60 * 60 * 24 * 365 * 2, '/' );
		setcookie( 'qa_heatmap_bar_attention', 'true', time() + 60 * 60 * 24 * 365 * 2, '/' );
		setcookie( 'qa_heatmap_bar_click_heat', 'true', time() + 60 * 60 * 24 * 365 * 2, '/' );
		setcookie( 'qa_heatmap_bar_click_count', 'false', time() + 60 * 60 * 24 * 365 * 2, '/' );
	} else {
		$cfg_scroll      = filter_input( INPUT_COOKIE, 'qa_heatmap_bar_scroll' );
		$cfg_scroll      = $cfg_scroll === 'true' ? true : false;
		$cfg_attention   = filter_input( INPUT_COOKIE, 'qa_heatmap_bar_attention' );
		$cfg_attention   = $cfg_attention === 'true' ? true : false;
		$cfg_click_heat  = filter_input( INPUT_COOKIE, 'qa_heatmap_bar_click_heat' );
		$cfg_click_heat  = $cfg_click_heat === 'true' ? true : false;
		$cfg_click_count = filter_input( INPUT_COOKIE, 'qa_heatmap_bar_click_count' );
		$cfg_click_count = $cfg_click_count === 'true' ? true : false;
	}
	$html_bar  = '<div class="heatmap-bar__row heatmap-bar__row--1">';
	$html_bar .= '<div class="heatmap-bar__controls-left">';
	$html_bar .= '<ul class="heatmap-bar__items">';
	// Differs between ZERO and QA - Start ----------
	if ( QAHM_TYPE === QAHM_TYPE_ZERO ) {
		$html_bar .= $qahm_view_heatmap->get_html_bar_text( 'heatmap-bar-filter', $filter_icon . $filter_title, $filter_tooltip );
	}
	// Differs between ZERO and QA - End ----------
	$html_bar .= $qahm_view_heatmap->get_html_bar_text( 'heatmap-bar-date-range', $date_range_icon . $date_range_title . $date_range_textbox, $date_range_tooltip, false, '' );
	$html_bar .= $qahm_view_heatmap->get_html_bar_text( 'heatmap-bar-device-version', $device_version_icon . $device_version_title . $device_version_selectbox, $device_version_tooltip );
	$html_bar .= '</ul>';
	$html_bar .= '</div>';

	$html_bar              .= '<div class="heatmap-bar__controls-right">';
	$html_bar              .= '<ul class="heatmap-bar__items">';
	$version_update_title   = '<button id="heatmap-bar-version-update-button" class="heatmap-bar-button heatmap-bar-button--secondary">' . esc_html__( 'Create Heatmap Version', 'qa-heatmap-analytics' ) . '</button>';
	$version_update_tooltip = esc_attr__( 'Changed this page’s content or layout? Create a new heatmap version.', 'qa-heatmap-analytics' );
	$version_update_icon    = '<i class="fas fa-sync-alt"></i>';
	$html_bar              .= $qahm_view_heatmap->get_html_bar_text( 'heatmap-bar-version-update', $version_update_icon . $version_update_title, $version_update_tooltip );
	$html_bar              .= '</ul>';
	$html_bar              .= '</div>';

	$html_bar .= '</div>';

	$html_bar .= '<div class="heatmap-bar__row heatmap-bar__row--2">';
	$html_bar .= '<div class="heatmap-bar__display">';
	$html_bar .= '<ul class="heatmap-bar__items">';
	$html_bar .= $qahm_view_heatmap->get_html_bar_checkbox( 'heatmap-bar-scroll', $scro_map_icon . $scro_map_title, $scro_map_tooltip, $cfg_scroll );
	$html_bar .= $qahm_view_heatmap->get_html_bar_checkbox( 'heatmap-bar-attention', $atte_map_icon . $atte_map_title, $atte_map_tooltip, $cfg_attention );
	$html_bar .= $qahm_view_heatmap->get_html_bar_checkbox( 'heatmap-bar-click-heat', $heat_map_icon . $heat_map_title, $heat_map_tooltip, $cfg_click_heat );
	$html_bar .= $qahm_view_heatmap->get_html_bar_checkbox( 'heatmap-bar-click-count', $count_map_icon . $count_map_title, $count_map_tooltip, $cfg_click_count );
	$html_bar .= '</ul>';
	$html_bar .= '</div>';
	$html_bar .= '<div class="heatmap-bar__info">';
	$html_bar .= '<ul class="heatmap-bar__items">';
	$html_bar .= $qahm_view_heatmap->get_html_bar_text( 'heatmap-bar-data-num', $data_num_icon . $data_num_title . ': ' . '<span id="heatmap-bar-data-num-value">' . $data_num . '</span>', $data_num_tooltip );
	$html_bar .= $qahm_view_heatmap->get_html_bar_text( 'heatmap-bar-avg-time-on-page', $time_on_page_icon . $time_on_page_title . ': <span id="heatmap-bar-avg-time-on-page-value">' . $time_on_page . '</span>', $time_on_page_tooltip );
	$html_bar .= $qahm_view_heatmap->get_html_bar_text( 'heatmap-bar-help', $help_icon . $help_title, $help_tooltip, false, 'https://mem.quarka.org/manual/to-see-heatmap-view/' );
	$html_bar .= '</ul>';
	$html_bar .= '</div>';
	$html_bar .= '</div>';

	//$html_bar .= $qahm_view_heatmap->get_html_bar_text( 'heatmap-bar-page-version', $page_version_icon . $page_version_title . $page_version_selectbox, $page_version_tooltip );
	//mkdummy
	//$html_bar .= $qahm_view_heatmap->get_html_bar_text( 'heatmap-bar-brains', $brains_icon . $brains_title ,  "Boot Brains", false, '' );
	$plugin_version  = QAHM_PLUGIN_VERSION;
	$scroll_data_num = '0' . ' ' . esc_html_x( 'users', 'user count label', 'qa-heatmap-analytics' );

} catch ( Exception $e ) {
	echo '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>';
	echo '<p>Error : ' . esc_html( $e->getMessage() ) . '</p>';
	echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=qahm-help' ) ) . '" target="_blank">' . esc_html__( 'HELP', 'qa-heatmap-analytics' ) . '</a></p>';
	echo '</body></html>';
	exit();
}
?>

<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_locale() ); ?>">
	<head>
		<meta charset="utf-8">
		<meta http-equiv="X-UA-Compatible" content="IE=edge">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">

		<title>QA Heatmap View</title>

		<?php // phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- This stylesheet is safely loaded internally for admin use and does not impact the frontend or the original WordPress site. ?>
		<link rel="stylesheet" type="text/css" href="./css/reset.css?ver=<?php echo esc_attr( $plugin_version ); ?>">
		<link rel="stylesheet" type="text/css" href="./css/lib/sweet-alert-2/sweetalert2.min.css?ver=<?php echo esc_attr( $plugin_version ); ?>">
		<link rel="stylesheet" type="text/css" href="./css/lib/date-range-picker/daterangepicker.css?ver=<?php echo esc_attr( $plugin_version ); ?>">
		<link rel="stylesheet" type="text/css" href="./css/common.css?ver=<?php echo esc_attr( $plugin_version ); ?>">
		<?php
		// プロダクト別のヒートマップビューCSSを読み込み
		$heatmap_css_file = ( QAHM_TYPE === QAHM_TYPE_ZERO ) ? 'heatmap-view-zero.css' : 'heatmap-view-wp.css';
		?>
		<link rel="stylesheet" type="text/css" href="./css/<?php echo esc_attr( $heatmap_css_file ); ?>?ver=<?php echo esc_attr( $plugin_version ); ?>">
		<?php // phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet ?>

		<?php // phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- This script is safely loaded internally for admin use and does not impact the frontend or the original WordPress site. ?>
		<script src="<?php echo esc_url( includes_url( 'js/jquery/jquery.min.js' ) ); ?>"></script>
		<script src="./js/lib/sweet-alert-2/sweetalert2.min.js?ver=<?php echo esc_attr( $plugin_version ); ?>"></script>
		<script src="./js/alert-message.js?ver=<?php echo esc_attr( $plugin_version ); ?>"></script>
		<script src="./js/lib/font-awesome/all.min.js?ver=<?php echo esc_attr( $plugin_version ); ?>"></script>
		<script src="./js/lib/moment/moment-with-locales.min.js?ver=<?php echo esc_attr( $plugin_version ); ?>"></script>
		<script src="./js/lib/date-range-picker/daterangepicker.js?ver=<?php echo esc_attr( $plugin_version ); ?>"></script>		
		<script>
			var qahm = qahm || {};
			let qahmObj = {
				'nonce_api':'<?php echo wp_json_encode( $nonce_api ); ?>',
				'ajax_url':'<?php echo esc_js( esc_url( $ajax_url ) ); ?>',
				'wp_lang_set':'<?php echo esc_js( get_bloginfo( 'language' ) ); ?>',
				'type':'<?php echo esc_js( $wp_qa_type ); ?>',
				'id':<?php echo intval( $wp_qa_id ); ?>,
				'page_id':<?php echo intval( $page_id ); ?>,
				'ver':<?php echo intval( $version_no ); ?>,
				'dev':'<?php echo esc_js( $device_name ); ?>',
				'file_base_name':'<?php echo esc_js( $file_base_name ); ?>',
				'version_id':<?php echo intval( $version_id ); ?>,
				'start_date':'<?php echo esc_js( $start_date ); ?>',
				'end_date':'<?php echo esc_js( $end_date ); ?>',
				'source':'<?php echo esc_js( $source ); ?>',
				'media':'<?php echo esc_js( $media ); ?>',
				'campaign':'<?php echo esc_js( $campaign ); ?>',
				'goal':'<?php echo esc_js( $goal ); ?>',
				'attention_limit_time':<?php echo intval( QAHM_View_Heatmap::ATTENTION_LIMIT_TIME ); ?>,
				'plugin_dir_url':'<?php echo esc_js( esc_url( plugin_dir_url( __FILE__ ) ) ); ?>',
				'separate_data_num':<?php echo wp_json_encode( $separate_data_num ); ?>,
				'separate_total_stay_time':<?php echo wp_json_encode( $separate_total_stay_time ); ?>,
				'dataNum':<?php echo intval( $data_num ); ?>,
				'hasShownNoDataAlert': false,
				'pvterm_start_date':'<?php echo esc_js( $pvterm_start_date ); ?>',
				'pvterm_latest_date':'<?php echo esc_js( $pvterm_latest_date ); ?>',
			};
			qahm = Object.assign( qahm, qahmObj );

			var qahml10n = {
				'people':'<?php echo esc_html_x( 'people', 'counting number (unit) of people', 'qa-heatmap-analytics' ); ?>',
				'calender_kako7days':'<?php echo esc_html_x( 'Last 7 Days', 'words in a date range picker', 'qa-heatmap-analytics' ); ?>',
				'calender_kako30days':'<?php echo esc_html_x( 'Last 30 Days', 'words in a date range picker', 'qa-heatmap-analytics' ); ?>',
				'calender_kongetsu':'<?php echo esc_html_x( 'This Month', 'words in a date range picker', 'qa-heatmap-analytics' ); ?>',
				'calender_sengetsu':'<?php echo esc_html_x( 'Last Month', 'words in a date range picker', 'qa-heatmap-analytics' ); ?>',
				'calender_erabu':'<?php echo esc_html_x( 'Custom Range', 'words in a date range picker', 'qa-heatmap-analytics' ); ?>',
				'calender_cancel':'<?php echo esc_html_x( 'Cancel', 'a word in a date range picker', 'qa-heatmap-analytics' ); ?>',
				'calender_ok':'<?php echo esc_html_x( 'Apply', 'a word in a date range picker', 'qa-heatmap-analytics' ); ?>',
				'calender_kara':'<?php echo esc_html_x( '-', 'a connector between dates in a date range picker', 'qa-heatmap-analytics' ); ?>',

				// 絞り込みウィンドウ
				'source': '<?php echo esc_html__( 'Source', 'qa-heatmap-analytics' ); ?>',
				'medium': '<?php echo esc_html__( 'Medium', 'qa-heatmap-analytics' ); ?>',
				'campaign': '<?php echo esc_html__( 'Campaign', 'qa-heatmap-analytics' ); ?>',
				'goal': '<?php echo esc_html__( 'Goal', 'qa-heatmap-analytics' ); ?>',
				'select_source': '<?php echo esc_html__( 'Select Source', 'qa-heatmap-analytics' ); ?>',
				'select_medium': '<?php echo esc_html__( 'Select Medium', 'qa-heatmap-analytics' ); ?>',
				'select_campaign': '<?php echo esc_html__( 'Select Campaign', 'qa-heatmap-analytics' ); ?>',
				'select_goal': '<?php echo esc_html__( 'Select Goal', 'qa-heatmap-analytics' ); ?>',
				'apply_filter': '<?php echo esc_html__( 'Apply Filter', 'qa-heatmap-analytics' ); ?>',
				'cancel': '<?php echo esc_html__( 'Cancel', 'qa-heatmap-analytics' ); ?>',
				'goal_achieved': '<?php echo esc_html__( 'Achieved', 'qa-heatmap-analytics' ); ?>',
				'goal_not_achieved': '<?php echo esc_html__( 'Unachieved', 'qa-heatmap-analytics' ); ?>',
				
				'confirm_version_update':'<?php echo esc_js( __( 'This will capture the page’s current HTML and create a new heatmap version for all devices. Continue?', 'qa-heatmap-analytics' ) ); ?>',
				'updating':'<?php echo esc_js( __( 'Updating...', 'qa-heatmap-analytics' ) ); ?>',
				'version_update_success':'<?php echo esc_js( __( 'Heatmap version updated successfully!', 'qa-heatmap-analytics' ) ); ?>',
				'failed':'<?php echo esc_js( __( 'Failed', 'qa-heatmap-analytics' ) ); ?>',
				'version_update_failed':'<?php echo esc_js( __( 'Failed to update heatmap version.', 'qa-heatmap-analytics' ) ); ?>',
				'version_update_error':'<?php echo esc_js( __( 'An error occurred while updating the heatmap version.', 'qa-heatmap-analytics' ) ); ?>',

				'no_valid_data_title':'<?php echo esc_js( __( 'No data to display', 'qa-heatmap-analytics' ) ); ?>',
				'no_valid_data_message':'<?php echo esc_js( __( 'There is no data available to display this heatmap.', 'qa-heatmap-analytics' ) ); ?>'
			};
		</script>
		<script src="./js/common.js?ver=<?php echo esc_attr( $plugin_version ); ?>"></script>
		<script src="./js/load-screen.js?ver=<?php echo esc_attr( $plugin_version ); ?>"></script>
		<script src="./js/cap-create.js?ver=<?php echo esc_attr( $plugin_version ); ?>"></script>
		<script src="./js/lib/heatmap/heatmap.min.js?ver=<?php echo esc_attr( $plugin_version ); ?>"></script>
		<script src="./js/heatmap-view.js?ver=<?php echo esc_attr( $plugin_version ); ?>"></script>
		<script src="./js/heatmap-bar.js?ver=<?php echo esc_attr( $plugin_version ); ?>"></script>
		<script src="./js/heatmap-main.js?ver=<?php echo esc_attr( $plugin_version ); ?>"></script>
		<?php // phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript ?>
		
	</head>
	<body>
		<?php
			$html = '<div class="heatmap-bar">' .
					$html_bar .
					'</div>' .
					'<div id="heatmap-iframe-container" class="frame">' .
					'<div class="frame-inner">' .
					'<iframe id="heatmap-iframe" src="' . $heatmap_view_work_url . $file_base_name . '-cap.php?qahm_view_mode=1" width="100%" height="100%"></iframe>' .
					'</div>' .
					'</div>' .
					'<div id="heatmap-container" class="frame">' .
					'<div class="frame-inner">' .
					'<div id="heatmap-content">' .
					'<div id="heatmap-click-heat-wrapper">' .
					'<div id="heatmap-click-heat" class="qahm-hide">' .
					'<div id="heatmap-click-heat-0"></div>' .
					'<div id="heatmap-click-heat-1"></div>' .
					'</div>' .
					'</div>' .
					'<div id="heatmap-click-count-wrapper">' .
					'<div id="heatmap-click-count" class="qahm-hide">' .
					'<div id="heatmap-click-count-0"></div>' .
					'<div id="heatmap-click-count-1"></div>' .
					'</div>' .
					'</div>' .
					'<div id="heatmap-attention-scroll-wrapper">' .
					'<div id="heatmap-scroll-tooltip" class="qahm-hide"><span id="heatmap-scroll-data-num">' . $scroll_data_num . '</span></div>' .
					'<div id="heatmap-scroll" class="qahm-hide">' .
					'<div id="heatmap-scroll-0"></div>' .
					'<div id="heatmap-scroll-1"></div>' .
					'</div>' .
					'<div id="heatmap-attention" class="qahm-hide">' .
					'<div id="heatmap-attention-0"></div>' .
					'<div id="heatmap-attention-1"></div>' .
					'</div>' .
				'</div>' .
					'</div>' .
					'</div>' .
					'</div>';
			// Differs between ZERO and QA - Start ----------
		if ( QAHM_TYPE === QAHM_TYPE_ZERO ) {
			$html .= '<div id="filter-overlay" class="filter-overlay">' .
				'<div id="filter-container">' .
				'<div id="filter-container-title">' . $filter_icon . ' ' . esc_html__( 'Filter', 'qa-heatmap-analytics' ) . '</div>' .
				'<div class="filter-item-container"></div>' .
				'</div>' .
				'</div>';
		}
			// Differs between ZERO and QA - End ----------
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe internal HTML generation for heatmap rendering.
			echo $html;
		?>
	</body>
</html>
