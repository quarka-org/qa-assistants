<?php
defined( 'ABSPATH' ) || exit;
/**
 * QA ZERO のオプション関数クラス
 *
 * WordPressオプションに保存されたQA関連の値を取得するための関数を提供します。
 * このクラスは読み取り専用で、更新や削除操作は含みません。
 *
 * @package qa_heatmap
 */

$GLOBALS['qahm_options_functions'] = new QAHM_Options_Functions();
class QAHM_Options_Functions extends QAHM_Base {

	/**
	 * コンストラクタ
	 */
	public function __construct() {
	}

	/**
	 * achievements オプションを取得
	 * @return string
	 */
	public function get_achievements() {
		return $this->wrap_get_option( 'achievements' );
	}

	/**
	 * advanced_mode オプションを取得
	 * @return bool
	 */
	public function get_advanced_mode() {
		return $this->wrap_get_option( 'advanced_mode' );
	}

	/**
	 * cb_sup_mode オプションを取得
	 * @return string
	 */
	public function get_cb_sup_mode() {
		return $this->wrap_get_option( 'cb_sup_mode' );
	}

	/**
	 * data_retention_days オプションを取得
	 * 優先順位: wp-config.php定数 > WordPressオプション > デフォルト値
	 * @return int
	 */
	public function get_data_retention_days() {
		return parent::get_data_retention_days();
	}

	/**
	 * license_authorized オプションを取得
	 * @return bool
	 */
	public function get_license_authorized() {
		return $this->wrap_get_option( 'license_authorized' );
	}

	/**
	 * license_options オプションを取得
	 * @return string
	 */
	public function get_license_options() {
		return $this->wrap_get_option( 'license_options' );
	}

	/**
	 * siteinfo オプションを取得
	 * @return mixed
	 */
	public function get_siteinfo() {
		return $this->wrap_get_option( 'siteinfo' );
	}

	/**
	 * sitemanage オプションを取得
	 * @return array|null
	 */
	public function get_sitemanage() {
		return $this->wrap_get_option( 'sitemanage' );
	}

	/**
	 * google_credentials オプションを取得
	 * @return string
	 */
	public function get_google_credentials() {
		return $this->wrap_get_option( 'google_credentials' );
	}

	/**
	 * google_is_redirect オプションを取得
	 * @return bool
	 */
	public function get_google_is_redirect() {
		return $this->wrap_get_option( 'google_is_redirect' );
	}

	/**
	 * 全てのZEROオプションを一括取得
	 * @param string $option オプション名（通常は'siteinfo'）
	 * @param mixed $default デフォルト値
	 * @return array|null 全tracking_idのZEROオプション配列
	 *
	 * 返される配列の構造:
	 * array(
	 *     'tracking_id_1' => array(
	 *         // tracking_id固有のオプションデータ
	 *     ),
	 *     'tracking_id_2' => array(
	 *         // tracking_id固有のオプションデータ
	 *     ),
	 *     ...
	 * )
	 */
	public function get_all_zero_options( $option, $default = false ) {
		$all_options_json = $this->wrap_get_option( $option, $default );
		if ( $all_options_json ) {
			return json_decode( $all_options_json, true );
		}
		return null;
	}

	/**
	 * 全てのゴール設定を一括取得
	 * @param string $option オプション名（通常は'goals'）
	 * @param mixed $default デフォルト値
	 * @return array|null 全tracking_idのゴール設定配列
	 *
	 * 返される配列の構造:
	 * array(
	 *     'tracking_id_1' => array(
	 *         'goal_id_1' => array(
	 *             'gtitle' => 'ゴールタイトル',
	 *             'pageid_ary' => array(page_id1, page_id2, ...),
	 *             'gnum_scale' => 'スケール設定',
	 *             'gtype' => 'ゴールタイプ',
	 *             'gurl' => 'ゴールURL',
	 *             'gurl_match' => 'URL一致条件',
	 *             // その他のゴール設定項目
	 *         ),
	 *         'goal_id_2' => array(...),
	 *         ...
	 *     ),
	 *     'tracking_id_2' => array(...),
	 *     ...
	 * )
	 */
	public function get_all_goals_options( $option = 'goals', $default = '' ) {
		$all_goals_json = $this->wrap_get_option( $option, $default );
		if ( $all_goals_json ) {
			return json_decode( $all_goals_json, true );
		}
		return null;
	}
}
