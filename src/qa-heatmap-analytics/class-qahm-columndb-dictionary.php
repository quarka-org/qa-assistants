<?php
/**
 * QAHM ColumnDB Dictionary
 *
 * 汎用文字列辞書クラス。文字列→IDのマッピングをserialize形式で管理する。
 * element_text, element_id, element_class, element_data_attr, in_url, out_url の
 * 6つの辞書インスタンスで共用する。
 *
 * ID=0 は「値なし/null」を意味する予約値。辞書のIDは1から開始。
 *
 * @package qa_heatmap
 */
class QAHM_ColumnDB_Dictionary extends QAHM_Base {

	/**
	 * 辞書ファイルパス
	 *
	 * @var string
	 */
	private $filepath;

	/**
	 * 正引き: string → int
	 *
	 * @var array
	 */
	private $str_to_id = array();

	/**
	 * 逆引き: int → string
	 *
	 * @var array
	 */
	private $id_to_str = array();

	/**
	 * 次の採番ID（1始まり、0は予約）
	 *
	 * @var int
	 */
	private $next_id = 1;

	/**
	 * 変更フラグ
	 *
	 * @var bool
	 */
	private $dirty = false;

	/**
	 * コンストラクタ
	 *
	 * 既存ファイルがあれば読み込んで内部状態を復元する。
	 *
	 * @param string $filepath 辞書ファイルパス
	 */
	public function __construct( $filepath ) {
		$this->filepath = $filepath;
		$this->load();
	}

	/**
	 * ファイルから辞書データを読み込む
	 */
	private function load() {
		if ( ! file_exists( $this->filepath ) ) {
			return;
		}

		$body = $this->wrap_get_contents( $this->filepath );
		if ( false === $body || '' === $body ) {
			return;
		}

		$data = $this->wrap_unserialize( $body );
		if ( ! is_array( $data ) || ! isset( $data['entries'] ) ) {
			return;
		}

		$this->next_id = isset( $data['next_id'] ) ? (int) $data['next_id'] : 1;

		foreach ( $data['entries'] as $id => $str ) {
			$id = (int) $id;
			$this->id_to_str[ $id ] = $str;
			$this->str_to_id[ $str ] = $id;
		}
	}

	/**
	 * 文字列のIDを取得または新規作成
	 *
	 * @param string $str 文字列
	 * @return int ID（空文字→0、それ以外は1以上）
	 */
	public function get_or_create( $str ) {
		if ( '' === $str ) {
			return 0;
		}

		if ( isset( $this->str_to_id[ $str ] ) ) {
			return $this->str_to_id[ $str ];
		}

		$id = $this->next_id;
		$this->next_id++;
		$this->str_to_id[ $str ] = $id;
		$this->id_to_str[ $id ]  = $str;
		$this->dirty = true;

		return $id;
	}

	/**
	 * 文字列のIDを検索（作成しない）
	 *
	 * @param string $str 文字列
	 * @return int|null ID。未登録の場合はnull。空文字は0。
	 */
	public function lookup( $str ) {
		if ( '' === $str ) {
			return 0;
		}

		return isset( $this->str_to_id[ $str ] ) ? $this->str_to_id[ $str ] : null;
	}

	/**
	 * IDから文字列を逆引き
	 *
	 * @param int $id ID
	 * @return string|null 文字列。見つからなければnull。
	 */
	public function get_string( $id ) {
		$id = (int) $id;
		return isset( $this->id_to_str[ $id ] ) ? $this->id_to_str[ $id ] : null;
	}

	/**
	 * 全エントリを取得（部分検索用）
	 *
	 * @return array [id => string, ...]
	 */
	public function get_all_entries() {
		return $this->id_to_str;
	}

	/**
	 * ファイルに保存して閉じる
	 *
	 * dirty時のみ書き込みを実行する。
	 */
	public function close() {
		if ( ! $this->dirty ) {
			return;
		}

		$dir = dirname( $this->filepath );
		if ( ! is_dir( $dir ) ) {
			$this->wrap_mkdir( $dir );
		}

		$data = array(
			'version' => 1,
			'next_id' => $this->next_id,
			'entries' => $this->id_to_str,
		);

		$this->wrap_put_contents( $this->filepath, $this->wrap_serialize( $data ) );
		$this->dirty = false;
	}
}
