<?php
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone file: loaded directly for replay rendering. Do not exit.
}
try {
	$work_base_name = filter_input( INPUT_GET, 'work_base_name' );
	$replay_id      = (int) filter_input( INPUT_GET, 'replay_id' );

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

	// GETパラメーター判定
	if ( ! $work_base_name || ! $replay_id ) {
		throw new Exception( 'Query string has no value.' );
	}

	global $qahm_time;
	global $wp_filesystem;
	global $qahm_view_replay;
	$replay_view_work_dir = $qahm_view_replay->get_data_dir_path( 'replay-view-work' );
	$replay_view_work_url = $qahm_view_replay->get_work_dir_url();

	// ファイル読み込み
	$event_ary = $qahm_view_replay->get_event_array( $work_base_name, $replay_id );

	// info 読み込み
	$info_path = $replay_view_work_dir . $work_base_name . '_' . $replay_id . '-info.php';
	$info_ary  = $qahm_view_replay->get_contents_info( $info_path );

	// ログイン判定
	if ( ! $qahm_view_replay->check_access_role( 'qazero-view' ) ) {
		throw new Exception( 'You do not have access privileges.' );
	}

	// 翻訳ファイルの読み込みはここでしなくてもプラグイン全体で読み込まれている
	//load_plugin_textdomain( 'qa-heatmap-analytics', false, plugin_basename( dirname( __FILE__ ) ) . '/languages' );

	// パラメータ設定
	$ajax_url       = admin_url( 'admin-ajax.php' );
	$plugin_version = QAHM_PLUGIN_VERSION;
	$debug_level    = wp_json_encode( QAHM_DEBUG_LEVEL );
	$debug          = QAHM_DEBUG;
	$cap_url        = $replay_view_work_url . $work_base_name . '_' . $replay_id . '-cap.php';
	$img_dir_url    = $qahm_view_replay->get_img_dir_url();

	$data_col_head     = $qahm_view_replay::DATA_COLUMN_HEADER;
	$data_col_body     = $qahm_view_replay::DATA_COLUMN_BODY;
	$data_row_win_w    = $qahm_view_replay::DATA_EVENT_1['WINDOW_INNER_W'];
	$data_row_win_h    = $qahm_view_replay::DATA_EVENT_1['WINDOW_INNER_H'];
	$data_row_type     = $qahm_view_replay::DATA_EVENT_1['TYPE'];
	$data_row_time     = $qahm_view_replay::DATA_EVENT_1['TIME'];
	$data_row_click_x  = $qahm_view_replay::DATA_EVENT_1['CLICK_X'];
	$data_row_click_y  = $qahm_view_replay::DATA_EVENT_1['CLICK_Y'];
	$data_row_mouse_x  = $qahm_view_replay::DATA_EVENT_1['MOUSE_X'];
	$data_row_mouse_y  = $qahm_view_replay::DATA_EVENT_1['MOUSE_Y'];
	$data_row_scroll_y = $qahm_view_replay::DATA_EVENT_1['SCROLL_Y'];
	$data_row_resize_x = $qahm_view_replay::DATA_EVENT_1['RESIZE_X'];
	$data_row_resize_y = $qahm_view_replay::DATA_EVENT_1['RESIZE_Y'];
	$event_last_time   = '00:00';
	if ( $event_ary ) {
		$event_last_sec  = floor( (int) $event_ary[ count( $event_ary ) - 1 ][ $qahm_view_replay::DATA_EVENT_1['TIME'] ] / 1000 );
		$event_last_time = $qahm_time->seconds_to_timestr( $event_last_sec );
		$event_last_time = substr( $event_last_time, strlen( '00:' ) );
	}

	$page_ary         = $info_ary['page_array'];
	$user_pv          = 0;
	$user_access_time = $info_ary['access_time'];
	$replay_id_idx    = $replay_id - 1;
	$replay_id_max    = count( $page_ary );

	$html_playlist  = '<div id="playlist">';
	$next_thumb     = '';
	$next_title     = '';
	$next_replay_id = 1;
	for ( $i = 0; $i < $replay_id_max; $i++ ) {
		$page = $page_ary[ $i ];
		++$user_pv;

		// プレイリストの項目
		$active = '';
		if ( $i === $replay_id_idx ) {
			$active .= ' playlist-item-active';
		}
		$html_playlist .= '<div class="playlist-item' . $active . '" data-replay_id="' . ( $i + 1 ) . '" data-access_time="' . $page['access_time'] . '" data-page-url="' . esc_attr( $page['url'] ) . '">';

		// プレイリストの順番
		$html_playlist .= '<div class="playlist-item-number"><span>';
		$css_view_link  = '';
		if ( $i === $replay_id_idx ) {
			$html_playlist .= '&#x25B6;';
		} else {
			$html_playlist .= $i + 1;
		}
		$html_playlist .= '</span></div>';

		// プレイリストのサムネイル
		$html_playlist .= '<div class="playlist-item-thumb">';
		$postid         = url_to_postid( $page['url'] );
		$thumb_src      = $img_dir_url . 'noeyecatch.png';
		if ( $postid !== 0 && has_post_thumbnail( $postid ) ) {
			$thumb          = get_the_post_thumbnail( $postid, 'thumbnail' );
			$thumb_id       = get_post_thumbnail_id( $postid );
			$thumb_ary      = wp_get_attachment_image_src( $thumb_id, 'thumbnail' );
			$thumb_src      = $thumb_ary[0];
			$html_playlist .= '<img src="' . $img_dir_url . 'noeyecatch.png" data-src="' . $thumb_src . '">';

		} else {
			// no thumb
			$html_playlist .= '<img src="' . $img_dir_url . 'noeyecatch.png">';
		}
		$html_playlist .= '</div>';

		// プレイリストのタイトル
		$html_playlist .= '<div class="playlist-item-title"><span>';
		$html_playlist .= htmlentities( $page['title'] );
		$html_playlist .= '<span></div>';

		$html_playlist .= '</div>';

		// 現在再生している次の動画の情報を格納
		// 次の動画の情報がなければ最初の動画の情報が格納される
		if ( $i === 0 || $i === $replay_id_idx + 1 ) {
			$next_thumb     = $thumb_src;
			$next_title     = $page['title'];
			$next_replay_id = $i + 1;
		}
	}
	$html_playlist .= '</div>';

	// 次の動画の再生ボックス
	$html_next_replay  = '<div id="next-replay-time">' . __( 'Play the next video after <span id="next-replay-time-count">5</span> seconds.', 'qa-heatmap-analytics' ) . '</div>';
	$html_next_replay .= '<div id="next-replay-thumb"><img src="' . $next_thumb . '"></div>';
	$html_next_replay .= '<div id="next-replay-title">' . htmlentities( $next_title ) . '</div>';
	$html_next_replay .= '<div id="next-replay-cancel">' . __( 'Cancel', 'qa-heatmap-analytics' ) . '</div>';
	$html_next_replay .= '<div id="next-replay-play" data-replay_id="' . $next_replay_id . '">' . __( 'Play Now', 'qa-heatmap-analytics' ) . '</div>';

	// ユーザーの情報
	$user_id      = $info_ary['qa_id'];
	$user_country = $info_ary['country'];
	$user_ref     = $info_ary['first_referrer'];
	$user_is_new  = $info_ary['is_new_user'];
	$user_browser = $info_ary['browser'];
	$user_os      = $info_ary['os'];
	$user_device  = $info_ary['device'];

	// ユーザーの情報を加工
	if ( ! $user_id ) {
		$user_id = __( 'Unkown', 'qa-heatmap-analytics' );
	}

	switch ( $user_country ) {
		case 'ja':
			$user_country = __( 'Japan', 'qa-heatmap-analytics' );
			break;

		// 国情報が不明の場合除去。
		case 'Unkown':
		case '':
			$user_country = __( 'Unkown', 'qa-heatmap-analytics' );
			break;
	}

	if ( $user_ref ) {
		if ( 0 === strncmp( $user_ref, 'http', 4 ) ) {
			$parse_url = wp_parse_url( $user_ref );
			$user_ref  = $parse_url['host'];
		}
	} else {
		$user_ref = __( 'Unkown', 'qa-heatmap-analytics' );
	}

	if ( $user_is_new === 1 ) {
		$user_is_new = __( 'New User', 'qa-heatmap-analytics' );
	} else {
		$user_is_new = __( 'Returning User', 'qa-heatmap-analytics' );
	}

	if ( ! $user_browser ) {
		$user_browser = __( 'Unkown', 'qa-heatmap-analytics' );
	}

	if ( $user_os === 'Unknown' || ! $user_os ) {
		$user_os = __( 'Unkown', 'qa-heatmap-analytics' );
	}

	if ( ! $user_device ) {
		$user_device = __( 'Unkown', 'qa-heatmap-analytics' );
	}

	if ( $user_device === 'tab' ) {
		$user_device      = 'tablet';
		$user_device_icon = '<i class="fas fa-tablet-alt fa-fw session-icon"></i>';
	} elseif ( $user_device === 'smp' ) {
		$user_device      = 'mobile';
		$user_device_icon = '<i class="fas fa-mobile-alt fa-fw session-icon"></i>';
	} else {
		$user_device      = 'desktop';
		$user_device_icon = '<i class="fas fa-desktop fa-fw session-icon"></i>';
	}

	if ( 0 === strncmp( $user_os, 'Windows', strlen( 'Windows' ) ) ) {
		$user_os_icon = '<i class="fab fa-windows fa-fw session-icon"></i>';
	} elseif ( 0 === strncmp( $user_os, 'Mac', strlen( 'Mac' ) ) || 0 === strncmp( $user_os, 'iOS', 3 ) ) {
		$user_os_icon = '<i class="fab fa-apple fa-fw session-icon"></i>';
	} elseif ( 0 === strncmp( $user_os, 'Linux', strlen( 'Linux' ) ) ) {
		$user_os_icon = '<i class="fab fa-linux fa-fw session-icon"></i>';
	} elseif ( 0 === strncmp( $user_os, 'Android', strlen( 'Android' ) ) ) {
		$user_os_icon = '<i class="fab fa-android fa-fw session-icon"></i>';
	} else {
		$user_os_icon = '<i class="fas fa-window-maximize fa-fw session-icon"></i>';
	}

	if ( 0 === strncmp( $user_browser, 'Edge', strlen( 'Edge' ) ) ) {
		$user_browser_icon = '<i class="fab fa-edge fa-fw session-icon"></i>';
	} elseif ( 0 === strncmp( $user_browser, 'Opera', strlen( 'Opera' ) ) || 0 === strncmp( $user_os, 'iOS', 3 ) ) {
		$user_browser_icon = '<i class="fab fa-opera fa-fw session-icon"></i>';
	} elseif ( 0 === strncmp( $user_browser, 'Chrome', strlen( 'Chrome' ) ) ) {
		$user_browser_icon = '<i class="fab fa-chrome fa-fw session-icon"></i>';
	} elseif ( 0 === strncmp( $user_browser, 'Firefox', strlen( 'Firefox' ) ) ) {
		$user_browser_icon = '<i class="fab fa-firefox fa-fw session-icon"></i>';
	} elseif ( 0 === strncmp( $user_browser, 'Internet Explorer', strlen( 'Internet Explorer' ) ) ) {
		$user_browser_icon = '<i class="fab fa-internet-explorer fa-fw session-icon"></i>';
	} elseif ( 0 === strncmp( $user_browser, 'Safari', strlen( 'Safari' ) ) ) {
		$user_browser_icon = '<i class="fab fa-safari fa-fw session-icon"></i>';
	} else {
		$user_browser_icon = '<i class="fas fa-window-maximize fa-fw session-icon"></i>';
	}

	// htmlタグ
	$html_user_info  = '<ul id="session-info">';
	$html_user_info .= '<li class="qahm-tooltip-bottom" data-qahm-tooltip="' . esc_attr(
		sprintf(
		/* translators: %s is for the plugin name */
			__( 'An anonymized ID which is assigned uniquely by %1$s.', 'qa-heatmap-analytics' ),
			QAHM_PLUGIN_NAME_SHORT
		)
	) . '"><i class="fas fa-id-card fa-fw session-icon"></i>' . $user_id . '</li>';
	$html_user_info .= '<li class="qahm-tooltip-bottom" data-qahm-tooltip="' . __( 'Whether this user visited the site for the first time (New User) or more than once (Returning User).', 'qa-heatmap-analytics' ) . '"><i class="fas fa-user fa-fw session-icon"></i>' . $user_is_new . '</li>';

	if ( $user_country !== __( 'Unkown', 'qa-heatmap-analytics' ) ) {
		$html_user_info .= '<li class="qahm-tooltip-bottom" data-qahm-tooltip="' . __( 'The country this user visited from.', 'qa-heatmap-analytics' ) . '"><i class="fas fa-globe-americas fa-fw session-icon"></i>' . $user_country . '</li>';
	}
	$html_user_info .= '</ul>';

	$html_access_info  = '<ul id="session-info">';
	$html_access_info .= '<li class="qahm-tooltip-bottom" data-qahm-tooltip="' . __( 'The time of landing on the site.', 'qa-heatmap-analytics' ) . '"><i class="fas fa-calendar-alt fa-fw session-icon"></i>' . $user_access_time . '</li>';
	$html_access_info .= '<li class="qahm-tooltip-bottom" data-qahm-tooltip="' . __( 'The referrer that this user came from.', 'qa-heatmap-analytics' ) . '"><i class="fas fa-chalkboard-teacher fa-fw session-icon"></i>' . $user_ref . '</li>';
	$html_access_info .= '<li class="qahm-tooltip-bottom" data-qahm-tooltip="' . __( 'The number of page views this user has seen.', 'qa-heatmap-analytics' ) . '"><i class="fas fa-file-alt fa-fw session-icon"></i>' . $user_pv . 'PV</li>';
	$html_access_info .= '</ul>';

	$html_device_info  = '<ul id="session-info">';
	$html_device_info .= '<li class="qahm-tooltip-bottom" data-qahm-tooltip="' . esc_attr( 'Either of three types: desktop, mobile, tablet.' ) . '">' . $user_device_icon . $user_device . '</li>';
	$html_device_info .= '<li class="qahm-tooltip-bottom" data-qahm-tooltip="' . __( 'OS', 'qa-heatmap-analytics' ) . '">' . $user_os_icon . $user_os . '</li>';
	$html_device_info .= '<li class="qahm-tooltip-bottom" data-qahm-tooltip="' . __( 'Browser', 'qa-heatmap-analytics' ) . '">' . $user_browser_icon . $user_browser . '</li>';
	$html_device_info .= '</ul>';

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

		<title>QA Replay View</title>

		<?php // phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- This stylesheet is safely loaded internally for admin use and does not impact the frontend or the original WordPress site. ?>
		<link rel="stylesheet" type="text/css" href="./css/reset.css?ver=<?php echo esc_attr( $plugin_version ); ?>">
		<link rel="stylesheet" type="text/css" href="./css/lib/sweet-alert-2/sweetalert2.min.css?ver=<?php echo esc_attr( $plugin_version ); ?>">
		<link rel="stylesheet" type="text/css" href="./css/common.css?ver=<?php echo esc_attr( $plugin_version ); ?>">
		<link rel="stylesheet" type="text/css" href="./css/replay-view.css?ver=<?php echo esc_attr( $plugin_version ); ?>">
		<link rel="stylesheet" type="text/css" href="./css/lib/jquery-custom-content-scroller/jquery.mCustomScrollbar.min.css?ver=<?php echo esc_attr( $plugin_version ); ?>">
		<?php // phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet ?>

		<?php // phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- This script is safely loaded internally for admin use and does not impact the frontend or the original WordPress site. ?>
		<script src="<?php echo esc_url( includes_url( 'js/jquery/jquery.min.js' ) ); ?>"></script>
		<script src="./js/lib/sweet-alert-2/sweetalert2.min.js?ver=<?php echo esc_attr( $plugin_version ); ?>"></script>
		<script src="./js/alert-message.js?ver=<?php echo esc_attr( $plugin_version ); ?>"></script>
		<script src="./js/lib/font-awesome/all.min.js?ver=<?php echo esc_attr( $plugin_version ); ?>"></script>
		<script src="./js/lib/jquery-custom-content-scroller/jquery.mCustomScrollbar.min.js?ver=<?php echo esc_attr( $plugin_version ); ?>"></script>
		<?php // phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript ?>
	</head>
	<body class="qa_is_sideOpen">
				
		<div id="qa-player-container">
			
			<div id="url-container">
				<p id="url-replay">
					URL: <a href="<?php echo esc_url( $info_ary['base_url'] ); ?>" target="_blank"><?php echo esc_html( urldecode( $info_ary['base_url'] ) ); ?></a>
				</p>
			</div>

			<div id="screen-container">
				<div id="screen-control"></div>
				<div id="screen-overlay"></div>
				<div id="screen-container-inner">
					<canvas id="screen-canvas"></canvas>
					<iframe id="screen-iframe" src="<?php echo esc_url( $cap_url ); ?>?qahm_view_mode=1" scrolling="no" frameborder="0"></iframe>
				</div>
				<div id="next-replay-container" style="display:none;"><?php echo wp_kses_post( $html_next_replay ); ?></div>
			</div>

			<div id="seekbar-container">
				<div id="seekbar-back-top"></div>
				<div id="seekbar-back-bottom"></div>
				<div id="seekbar-back"></div>
				<div id="seekbar-play"></div>
			</div>

			<div id="control-container">
				<!--<button id="play" class="play"></button>-->
				<button id="control-play" class="control-icon" style="display: none;"><i class="fas fa-play fa-fw"></i></button>
				<button id="control-pause" class="control-icon"><i class="fas fa-pause fa-fw"></i></button>
				<button id="control-replay" class="control-icon" style="display: none;"><i class="fa fa-reply fa-fw"></i></button>
				<button id="control-prev" class="control-icon"><i class="fas fa-fast-backward fa-fw"></i></button>
				<button id="control-next" class="control-icon"><i class="fas fa-fast-forward fa-fw"></i></button>
				<!--<button class="control-icon"><i class="fas fa-redo"></i></button>-->
				<button id="control-speed" class="control-text" data-speed="1">1x</button>
				<div class="video-timer">
					<span class="video-timer-now">00:00</span>
						/ 
					<span class="video-timer-last"><?php echo esc_html( $event_last_time ); ?></span>
				</div>
			</div>
		</div>
		
		<div id="qa-player-description">
			<div class="qa-player-description-inner">

				<div>
					<p class="title"><?php esc_html_e( 'User information', 'qa-heatmap-analytics' ); ?></p>
					<?php echo wp_kses_post( $html_user_info ); ?>
				</div>
				<hr>
				<div>
					<p class="title"><?php esc_html_e( 'Access information', 'qa-heatmap-analytics' ); ?></p>
					<?php echo wp_kses_post( $html_access_info ); ?>
				</div>
				<hr>
				<div>
					<p class="title"><?php esc_html_e( 'Device information', 'qa-heatmap-analytics' ); ?></p>
					<?php echo wp_kses_post( $html_device_info ); ?>
				</div>
				<hr>
				<div>
					<p class="title"><?php esc_html_e( 'the Page(s) viewed by this user', 'qa-heatmap-analytics' ); ?></p>
					<?php echo wp_kses_post( $html_playlist ); ?>
				</div>
			</div>
		</div>

		<script>
			var qahm = {
				'ajax_url':'<?php echo esc_js( esc_url( $ajax_url ) ); ?>',
				'data_type':'<?php echo esc_js( $info_ary['data_type'] ); ?>',
				'debug_level':<?php echo intval( $debug_level ); ?>,
				'debug':<?php echo intval( $debug ); ?>,
				'type':<?php echo intval( QAHM_TYPE ); ?>,
				'type_zero':<?php echo intval( QAHM_TYPE_ZERO ); ?>,
				'type_wp':<?php echo intval( QAHM_TYPE_WP ); ?>,
				'event_ary':'<?php echo wp_json_encode( $event_ary ); ?>',
				'work_base_name':'<?php echo esc_js( $work_base_name ); ?>',
				'access_time':'<?php echo esc_js( $user_access_time ); ?>',
				'reader_id':'<?php echo esc_js( $info_ary['reader_id'] ); ?>',
				'replay_id':<?php echo intval( $replay_id ); ?>,
				'replay_id_max':<?php echo intval( $replay_id_max ); ?>,
				'data_col_head':'<?php echo esc_js( $data_col_head ); ?>',
				'data_col_body':'<?php echo esc_js( $data_col_body ); ?>',
				'data_row_win_w':'<?php echo esc_js( $data_row_win_w ); ?>',
				'data_row_win_h':'<?php echo esc_js( $data_row_win_h ); ?>',
				'data_row_type':'<?php echo esc_js( $data_row_type ); ?>',
				'data_row_time':'<?php echo esc_js( $data_row_time ); ?>',
				'data_row_click_x':'<?php echo esc_js( $data_row_click_x ); ?>',
				'data_row_click_y':'<?php echo esc_js( $data_row_click_y ); ?>',
				'data_row_mouse_x':'<?php echo esc_js( $data_row_mouse_x ); ?>',
				'data_row_mouse_y':'<?php echo esc_js( $data_row_mouse_y ); ?>',
				'data_row_scroll_y':'<?php echo esc_js( $data_row_scroll_y ); ?>',
				'data_row_resize_x':'<?php echo esc_js( $data_row_resize_x ); ?>',
				'data_row_resize_y':'<?php echo esc_js( $data_row_resize_y ); ?>',
			};

			var qahml10n = {
				'event_data_not_found':'<?php echo esc_attr__( 'There is no data to replay. The (thinkable) major reason would be: \n - A visitor quickly moved on to the next page.\n - No event happened and time passed.', 'qa-heatmap-analytics' ); ?>',
				'page_change_failed':'<?php echo esc_attr__( 'Failed to switch pages.', 'qa-heatmap-analytics' ); ?>',

			};
		</script>
		
		<?php // phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- This script is safely loaded internally for admin use and does not impact the frontend or the original WordPress site. ?>
		<script type="text/javascript" src="./js/common.js?ver=<?php echo esc_attr( QAHM_PLUGIN_VERSION ); ?>"></script>
		<script type="text/javascript" src="./js/load-screen.js?ver=<?php echo esc_attr( QAHM_PLUGIN_VERSION ); ?>"></script>
		<script type="text/javascript" src="./js/replay-class.js?ver=<?php echo esc_attr( QAHM_PLUGIN_VERSION ); ?>"></script>
		<script type="text/javascript" src="./js/replay-view.js?ver=<?php echo esc_attr( QAHM_PLUGIN_VERSION ); ?>"></script>
		<?php // phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript ?>
	</body>
</html>
