<?php
if ( ! defined( 'ABSPATH' ) ) {
	// Standalone SHORTINIT file: loaded directly, bootstraps WordPress internally. Do not exit.
}
/**
 * Created by PhpStorm.
 * User: maruyama
 * Date: 2022/08/27
 * Time: 16:01
 */

define( 'SHORTINIT', true );

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
require_once ABSPATH . WPINC . '/l10n.php';
//require( ABSPATH . WPINC . '/capabilities.php' );
//require( ABSPATH . WPINC . '/class-wp-user.php' );
//require( ABSPATH . WPINC . '/user.php' );
//require( ABSPATH . WPINC . '/kses.php' );
//require( ABSPATH . WPINC . '/rest-api.php' );


wp_plugin_directory_constants();

$GLOBALS['wp_plugin_paths'] = array();

//wp_cookie_constants();
//require_once ABSPATH . WPINC . '/pluggable.php';
//require_once ABSPATH . WPINC . '/pluggable-deprecated.php';

require_once ABSPATH . WPINC . '/link-template.php';


/**
 * [ ! ] DO NOT include the plugin main file since WP6.1
 */
//require_once 'qahm.php';//=ERROR

//call qahm files
require_once 'qahm-const.php';
require_once 'class-qahm-core-base.php';
require_once 'class-qahm-wp-base.php';
require_once 'class-qahm-base.php';
$qahm_base = new QAHM_Base();
$qahm_base->init_wp_filesystem(); //<-Needed!
require_once 'class-qahm-time.php';
require_once 'class-qahm-log.php';
require_once 'class-qahm-file-base.php';
require_once 'class-qahm-file-data.php';
require_once 'class-qahm-behavioral-data.php';
require_once 'ip-geolocation/class-qahm-ip-geo.php';
require_once 'ip-geolocation/class-qahm-country-converter.php';

// work around for apache_request_headers
if ( ! function_exists( 'apache_request_headers' ) ) {
	function apache_request_headers() {
		$arh     = array();
		$rx_http = '/\AHTTP_/';
		foreach ( $_SERVER as $key => $val ) {
			if ( preg_match( $rx_http, $key ) ) {
				$arh_key    = preg_replace( $rx_http, '', $key );
				$rx_matches = array();
				// do some nasty string manipulations to restore the original letter case
				// this should work in most cases
				$rx_matches = explode( '_', $arh_key );
				if ( count( $rx_matches ) > 0 and strlen( $arh_key ) > 2 ) {
					foreach ( $rx_matches as $ak_key => $ak_val ) {
						$rx_matches[ $ak_key ] = ucfirst( $ak_val );
					}
					$arh_key = implode( '-', $rx_matches );
				}
				$arh[ $arh_key ] = $val;
			}
		}
		return( $arh );
	}
}


//qahm start
$behave    = new QAHM_Behavioral_Data();
$base      = new QAHM_Base();
$owndomain = isset( $_SERVER['SERVER_NAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) ) : '';
if ( $owndomain === '_' || empty( $owndomain ) ) {
	$owndomain = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
}
$allowed_origin = array( $owndomain );

//QA ZERO start
//sitemanageテーブルより登録されているすべてのドメインを取得し、$allowed_originに追加
$other_domains  = qahm_get_domains_with_cache();
$allowed_origin = array_merge( $allowed_origin, $other_domains );
//QA ZERO end

// external_healthcheck は Origin/tracking_id/hash 全てスキップ（Lambda A-01 用）
// TODO: Phase 2 で共有シークレットによる認証を追加予定
$get_action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : '';
if ( 'external_healthcheck' === $get_action ) {
	handle_external_healthcheck_request();
	exit;
}

$req_headers = apache_request_headers();
$req_origin  = '';
if ( array_key_exists( 'Origin', $req_headers ) ) {
	$req_origin = $req_headers['Origin'];
}
if ( array_key_exists( 'ORIGIN', $req_headers ) ) {
	$req_origin = $req_headers['ORIGIN'];
}
if ( array_key_exists( 'origin', $req_headers ) ) {
	$req_origin = $req_headers['origin'];
}
$is_own_domain = false;
$orgin         = '';
$action        = $base->wrap_filter_input( INPUT_POST, 'action' );

// allowed domain?
if ( $req_origin !== '' ) {
	$is_allowed = false;
	foreach ( $allowed_origin as $domain ) {
		// Plugin Check exclusion: wp_parse_url() not available in SHORTINIT context; safe fallback to parse_url()
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$host = parse_url( $req_origin, PHP_URL_HOST );
		if ( $host === $domain ) {
			$orgin = $req_origin;
			if ( $host === $owndomain ) {
				$is_own_domain = true;
			}
			$is_allowed = true;
			break;
		}
	}
	if ( ! $is_allowed ) {
		http_response_code( 404 );
		exit;
	}
}

//QA ZERO start
$url = $base->wrap_filter_input( INPUT_POST, 'url', FILTER_VALIDATE_URL );

if ( ! $url ) {
	http_response_code( 404 );
	exit;
}

// Plugin Check exclusion: wp_parse_url() not available before WordPress is fully loaded; safe fallback
// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
$parse_url   = parse_url( $url );
$tracking_id = $base->wrap_filter_input( INPUT_POST, 'tracking_id' );

//IPアドレスのチェック
$ip_address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
	$ip_addresses = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
	$ip_address   = trim( $ip_addresses[0] );
}


$sitemanage = get_option( 'qahm_sitemanage' );
$sitemanage = array_filter(
	$sitemanage,
	function ( $item ) {
		return isset( $item['status'] ) && $item['status'] !== 255;
	}
);
// インデックス番号を0から順に再構築
$sitemanage = array_values( $sitemanage );
if ( ! $sitemanage ) {
	http_response_code( 404 );
	exit;
}

$site = array_values(
	array_filter(
		$sitemanage,
		function ( $item ) use ( $tracking_id ) {
			return $item['tracking_id'] === $tracking_id;
		}
	)
);
if ( empty( $site ) ) {
	http_response_code( 404 );
	exit;
}

$ignore_ips_str = $site[0]['ignore_ips'];
$ignore_ips     = ( empty( $ignore_ips_str ) ) ? array() : explode( ',', $ignore_ips_str );

if ( in_array( $ip_address, $ignore_ips ) ) {
	if ( ! $is_own_domain ) {
		qahm_return_access_control_header( $orgin );
	}
	header( 'Content-Type: application/json' );
	echo '{"excluded":true}';
	exit;
}

$ignore_params_str = $site[0]['ignore_params'];
$ignore_params     = ( empty( $ignore_params_str ) ) ? array() : explode( ',', $ignore_params_str );
$c_url             = $behave->url_cleansing( $url, $ignore_params );

$url_case_sensitivity = $site[0]['url_case_sensitivity'];

//URLの大文字小文字を区別するか？
if ( $url_case_sensitivity != 1 ) {
	$c_url = mb_strtolower( $c_url );
}
$url_hash = hash( 'fnv164', $c_url );
//QA ZERO end

//check tracking_hash
$tracking_hash = $base->wrap_filter_input( INPUT_POST, 'tracking_hash' );
//if ( ! $behave->check_tracking_hash( $tracking_hash ) ) { QA ZERO del
if ( ! $behave->check_tracking_hash( $tracking_hash, $tracking_id ) ) { //QA ZERO add
	http_response_code( 404 );
	exit;
}

// ok, start behavioral data
$errmsg = '';

if ( ! $is_own_domain ) {
	qahm_return_access_control_header( $orgin );
}

switch ( $action ) {
	case 'healthcheck':
		handle_healthcheck_request( $base );
		exit;

	case 'init_session_data':
		$qa_id       = $base->serialize_escape( $base->wrap_filter_input( INPUT_POST, 'qa_id' ) );
		$title       = $base->serialize_escape( $base->wrap_filter_input( INPUT_POST, 'title' ) );
		$ref         = $base->wrap_filter_input( INPUT_POST, 'referrer' );
		$country     = $base->serialize_escape( $base->wrap_filter_input( INPUT_POST, 'country' ) );
		$ua          = $base->serialize_escape( $base->wrap_filter_input( INPUT_SERVER, 'HTTP_USER_AGENT' ) );
		$is_new_user = (int) $base->wrap_filter_input( INPUT_POST, 'is_new_user' );

		$posted_qa_id = $base->wrap_filter_input( INPUT_POST, 'qa_id' );
		if ( empty( $posted_qa_id ) ) {

			$anontrack = false;
			if ( isset( $site[0]['anontrack'] ) ) {
				$anontrack = $site[0]['anontrack'];
			}
			if ( $anontrack == 1 ) {
				$qa_id = $base->create_qa_id( $ip_address, $ua, $tracking_hash );
			} else {
				$qa_id = $base->create_qa_id( $ip_address, $ua, '' );
			}
		} else {
			$qa_id = $base->serialize_escape( $posted_qa_id );
		}

		if ( empty( $ref ) ) {
			$ref = 'direct';
		} else {
			if ( ! filter_var( $ref, FILTER_VALIDATE_URL ) ) {
				http_response_code( 404 );
				exit;
			}
		}
		// $url = mb_strtolower( $url ); QA ZERO del
		$ref = mb_strtolower( $ref );

		//is cookie reject?
		$is_reject = $base->wrap_filter_input( INPUT_POST, 'is_reject' );
		$is_reject = ( $is_reject === 'true' ) ? true : false;

		//init
		$data = $behave->init_session_data( $qa_id, $title, $url, $c_url, $url_hash, $ref, $country, $ua, $tracking_id, $is_new_user, $is_reject, $ip_address );

		if ( ! $data ) { //クローラー判定されたときfalseを返す
			http_response_code( 404 );
			exit;
		}

		qahm_return_json( $data );
		break;

	case 'update_msec':
		//readers_name       = $base->wrap_filter_input( INPUT_POST, 'readers_name' ); QA ZERO del
		$readers_name       = $base->serialize_escape( $base->wrap_filter_input( INPUT_POST, 'readers_name' ) ); //QA ZERO add
		$readers_body_index = (int) $base->wrap_filter_input( INPUT_POST, 'readers_body_index' );
		$speed_msec         = (int) $base->wrap_filter_input( INPUT_POST, 'speed_msec' );
		$behave->update_msec( $readers_name, $readers_body_index, $speed_msec );
		break;

	case 'record_behavioral_data':
		if ( $behave->is_maintenance() ) {
			http_response_code( 500 );
			exit;
		}
		$is_pos     = $base->wrap_filter_input( INPUT_POST, 'is_pos' );
		$is_pos     = ( $is_pos === 'true' ) ? true : false;
		$is_click   = $base->wrap_filter_input( INPUT_POST, 'is_click' );
		$is_click   = ( $is_click === 'true' ) ? true : false;
		$is_event   = $base->wrap_filter_input( INPUT_POST, 'is_event' );
		$is_event   = ( $is_event === 'true' ) ? true : false;
		$is_dLevent = $base->wrap_filter_input( INPUT_POST, 'is_dLevent' );
		$is_dLevent = ( $is_dLevent === 'true' ) ? true : false;

		//QA ZERO start
		$raw_name     = $base->serialize_escape( $base->wrap_filter_input( INPUT_POST, 'raw_name' ) );
		$readers_name = $base->serialize_escape( $base->wrap_filter_input( INPUT_POST, 'readers_name' ) );
		$ua           = $base->serialize_escape( $base->wrap_filter_input( INPUT_POST, 'ua' ) );
		$is_reject    = $base->wrap_filter_input( INPUT_POST, 'is_reject' );
		$is_reject    = ( $is_reject === 'true' ) ? true : false;
		//QA ZERO end

		if ( ! $ua ) {
			$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		}
		$output = $behave->record_behavioral_data( $is_pos, $is_click, $is_event, $is_dLevent, $raw_name, $readers_name, $ua, $tracking_id, $url_hash, $is_reject ); //QA ZERO add
		// Plugin Check exclusion: Outputs raw AJAX/JSON response (not HTML context)
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $output;
		break;

	default:
		http_response_code( 404 );
		exit;
}


exit;



function qahm_return_json( $data ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	echo json_encode( $data );
	exit;
}

function qahm_return_access_control_header( $origin = '' ) {
	header( "Access-Control-Allow-Origin: {$origin}" );
}

/**
 * ヘルスチェック専用処理関数
 */
function handle_healthcheck_request( $qahm_base ) {
	$data_dir = $qahm_base->get_data_dir_path();
	if ( ! $data_dir ) {
		http_response_code( 500 );
		echo json_encode(
			array(
				'status'    => 'error',
				'message'   => 'Data directory not accessible',
				'timestamp' => gmdate( 'Y-m-d\\TH:i:s\\Z' ),
			)
		);
		return;
	}

	$test_dir = $data_dir . 'temp/';
	if ( ! file_exists( $test_dir ) ) {
		if ( ! wp_mkdir_p( $test_dir ) ) {
			http_response_code( 500 );
			echo json_encode(
				array(
					'status'    => 'error',
					'message'   => 'Failed to create test directory',
					'timestamp' => gmdate( 'Y-m-d\\TH:i:s\\Z' ),
				)
			);
			return;
		}
	}

	$test_file_path = $test_dir . 'healthcheck_test.php';
	$test_data      = array(
		'timestamp' => gmdate( 'Y-m-d\\TH:i:s\\Z' ),
		'test_type' => 'healthcheck',
		'status'    => 'success',
	);

	$serialized_data = serialize( $test_data );
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- SHORTINIT context, WP_Filesystem unavailable.
	$result = file_put_contents( $test_file_path, "<?php\\n// Health Check Test File\\n// " . $serialized_data );

	if ( $result !== false ) {
		http_response_code( 200 );
		echo json_encode(
			array(
				'status'    => 'success',
				'message'   => 'Healthcheck file created successfully',
				'file_path' => $test_file_path,
				'timestamp' => gmdate( 'Y-m-d\\TH:i:s\\Z' ),
			)
		);
	} else {
		http_response_code( 500 );
		echo json_encode(
			array(
				'status'    => 'error',
				'message'   => 'Failed to create healthcheck file',
				'timestamp' => gmdate( 'Y-m-d\\TH:i:s\\Z' ),
			)
		);
	}
}

/**
 * 外部ヘルスチェック専用処理関数（Lambda A-01 用）
 *
 * 計測パスの疎通確認に特化した軽量エンドポイント。
 * 「ここまで到達できた」ことを返すだけで、計測データの処理は行わない。
 */
function handle_external_healthcheck_request() {
	header( 'Content-Type: application/json; charset=utf-8' );
	http_response_code( 200 );
	// Plugin Check exclusion: Outputs raw AJAX/JSON response (not HTML context)
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo json_encode(
		array(
			'status'    => 'ok',
			'message'   => 'External healthcheck endpoint is reachable',
			'timestamp' => gmdate( 'Y-m-d\\TH:i:s\\Z' ),
		)
	);
}

//APCuを利用してDBアクセス数を減らす
function qahm_get_domains_with_cache() {
	global $wpdb;
	$cache_key        = $wpdb->prefix . 'qa_domains_cache';
	$cache_expiration = 5 * MINUTE_IN_SECONDS; // キャッシュ有効期間を5分に設定。設定変更時も5分で反映される。

	// APCuが有効かをチェック
	$is_apcu_enabled = function_exists( 'apcu_fetch' );

	// キャッシュをAPCuから取得（APCuが有効な場合のみ）
	$domains = $is_apcu_enabled ? apcu_fetch( $cache_key ) : false;

	if ( $domains === false ) {
		// キャッシュがない場合のみクエリを実行
		$sitemanage = get_option( 'qahm_sitemanage' );
		$sitemanage = array_filter(
			$sitemanage,
			function ( $item ) {
				return isset( $item['status'] ) && $item['status'] !== 255;
			}
		);
		// インデックス番号を0から順に再構築
		$sitemanage = array_values( $sitemanage );
		if ( $sitemanage ) {
			$domains = array_column( $sitemanage, 'domain' );
		}

		// 結果をAPCuキャッシュに保存（APCuが有効な場合のみ）
		if ( $is_apcu_enabled ) {
			apcu_store( $cache_key, $domains, $cache_expiration );
		}
	}

	return $domains;
}
