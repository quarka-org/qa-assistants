<?php
/**
 * QAHM ColumnDB Selectors
 *
 * グローバルセレクタ管理クラス。tracking_id単位の統一セレクタ辞書。
 * セレクタ文字列→IDの変換のみを担当する（クリック集計等は列DB側の責務）。
 *
 * 内部的にはQAHM_ColumnDB_Dictionaryに委譲し、
 * ファイルパスの解決（view/{tracking_id}/global-selectors-dict.php）と
 * tracking_idベースのコンストラクタを提供する。
 *
 * @package qa_heatmap
 */
class QAHM_ColumnDB_Selectors {

	/**
	 * トラッキングID
	 *
	 * @var string
	 */
	private $tracking_id;

	/**
	 * 内部辞書
	 *
	 * @var QAHM_ColumnDB_Dictionary
	 */
	private $dict;

	/**
	 * コンストラクタ
	 *
	 * @param string      $tracking_id トラッキングID
	 * @param string|null $base_dir    ベースディレクトリ。nullの場合はデフォルトのデータパスを使用。
	 */
	public function __construct( $tracking_id, $base_dir = null ) {
		$this->tracking_id = $tracking_id;

		if ( null === $base_dir ) {
			$base_dir = WP_CONTENT_DIR . '/qa-zero-data/view/' . $tracking_id . '/';
		}

		$base_dir = rtrim( $base_dir, '/' ) . '/';
		$this->dict = new QAHM_ColumnDB_Dictionary( $base_dir . 'global-selectors-dict.php' );
	}

	/**
	 * セレクタのIDを取得または新規作成
	 *
	 * @param string $selector セレクタ文字列
	 * @return int セレクタID（1以上）
	 */
	public function get_or_create( $selector ) {
		return $this->dict->get_or_create( $selector );
	}

	/**
	 * セレクタのIDを検索（作成しない）
	 *
	 * @param string $selector セレクタ文字列
	 * @return int|null セレクタID。未登録の場合はnull。
	 */
	public function lookup( $selector ) {
		return $this->dict->lookup( $selector );
	}

	/**
	 * セレクタIDから文字列を逆引き
	 *
	 * @param int $selector_id セレクタID
	 * @return string|null セレクタ文字列。見つからなければnull。
	 */
	public function get_selector_string( $selector_id ) {
		return $this->dict->get_string( $selector_id );
	}

	/**
	 * IDから文字列を逆引き（QAHM_ColumnDB_Dictionary互換エイリアス）
	 *
	 * decode_dict_columns() が辞書クラスに共通して get_string() を呼ぶため、
	 * get_selector_string() への委譲で互換性を提供する。
	 *
	 * @param int $id セレクタID
	 * @return string|null セレクタ文字列
	 */
	public function get_string( $id ) {
		return $this->get_selector_string( $id );
	}

	/**
	 * 全エントリを取得（部分検索用）
	 *
	 * @return array [id => string, ...]
	 */
	public function get_all_entries() {
		return $this->dict->get_all_entries();
	}

	/**
	 * ファイルに保存して閉じる
	 */
	public function close() {
		$this->dict->close();
	}
}
