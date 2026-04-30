<?php

//
// 注意
// WordPress 3.1以降、パラメータ$functionに指定できる関数にはクラスメソッドを使用できない。
// 具体的には、次のような指定はNGとなり、関数の登録に失敗する。
// register_uninstall_hook( __FILE__, array( &$this, 'myplugin_uninstall' ) );
// https://elearn.jp/wpman/function/register_uninstall_hook.html
//
// uninstall.phpはプラグイン削除時に必ず実行される
// このためアンインストール処理はuninstall.phpにて行う
//

// 直接実行を禁止
if ( ! defined( 'ABSPATH' ) ) {
	die;
}

// WP_UNINSTALL_PLUGINが定義されているかチェック
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

set_time_limit( 60 * 30 );

require_once __DIR__ . '/qahm-const.php';

// テーブル存在チェック用の関数
function qahm_table_exists( $table_name ) {
	global $wpdb;
	$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
	return $result === $table_name;
}

// qahm wp_optionsの削除
foreach ( QAHM_OPTIONS as $key => $value ) {
	delete_option( QAHM_OPTION_PREFIX . $key );
}
// qahm wp_db_optionsの削除
foreach ( QAHM_DB_OPTIONS as $key => $value ) {
	delete_option( QAHM_OPTION_PREFIX . $key );
}
// qahm uninstall_optionsの削除
foreach ( QAHM_UNINSTALL_OPTIONS as $key ) {
	delete_option( QAHM_OPTION_PREFIX . $key );
}

// qa関連のuser_meta削除
$users = get_users( array( 'fields' => array( 'ID' ) ) );
foreach ( $users as $user ) {
	$user_meta_ary = get_user_meta( $user->ID );
	foreach ( $user_meta_ary as $meta_key => $meta_value ) {
		if ( strncmp( $meta_key, QAHM_OPTION_PREFIX, strlen( QAHM_OPTION_PREFIX ) ) === 0 ) {
			delete_user_meta( $user->ID, $meta_key );
		}
		if ( $meta_key === 'admin_color' && strncmp( $meta_value[0], 'qa-zero-', strlen( 'qa-zero-' ) ) === 0 ) {
			delete_user_meta( $user->ID, $meta_key );
		}
	}
}

// qahm tableの削除
global $wpdb;
$tables_to_delete = array(
	$wpdb->prefix . QAHM_NAME . '_recterm',
	$wpdb->prefix . QAHM_NAME . '_recrefresh',
	$wpdb->prefix . 'qa_pages',
	$wpdb->prefix . 'qa_page_version_hist',
	$wpdb->prefix . 'qa_pv_log',
	$wpdb->prefix . 'qa_readers',
	$wpdb->prefix . 'qa_search_log',
	$wpdb->prefix . 'qa_utm_campaigns',
	$wpdb->prefix . 'qa_utm_media',
	$wpdb->prefix . 'qa_utm_sources',
	$wpdb->prefix . 'qa_utm_content',
	$wpdb->prefix . 'qa_sitemanage',
);

foreach ( $tables_to_delete as $table_name ) {
	if ( qahm_table_exists( $table_name ) ) {
		$wpdb->query( "DROP TABLE {$table_name}" );
	}
}

// GSCテーブルの削除（存在チェック付き）
$gsc_tables = $wpdb->get_results( "SHOW TABLES LIKE '" . $wpdb->prefix . "qa_gsc%'", ARRAY_N );
foreach ( $gsc_tables as $table ) {
	$table_name = $table[0];
	if ( qahm_table_exists( $table_name ) ) {
		$wpdb->query( "DROP TABLE {$table_name}" );
	}
}

// dataディレクトリの削除
global $wp_filesystem;
$data_path = $wp_filesystem->wp_content_dir() . 'qa-zero-data/';
if ( $wp_filesystem->exists( $data_path ) ) {
	qahm_remove_dir( $data_path );
}
$data_path = $wp_filesystem->wp_content_dir() . 'qa-heatmap-analytics-data/';
if ( $wp_filesystem->exists( $data_path ) ) {
	qahm_remove_dir( $data_path );
}

function qahm_remove_dir( $dir ) {
	global $wp_filesystem;

	$list = $wp_filesystem->dirlist( $dir );
	foreach ( $list as $item ) {
		$path = $dir . DIRECTORY_SEPARATOR . $item['name'];

		if ( $wp_filesystem->is_dir( $path ) ) {
			// 再帰
			qahm_remove_dir( $path );
		} else {
			// ファイルを削除
			$wp_filesystem->delete( $path );
		}
	}

	// ディレクトリを削除
	$wp_filesystem->rmdir( $dir );
}
