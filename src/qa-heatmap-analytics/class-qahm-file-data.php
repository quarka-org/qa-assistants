<?php
defined( 'ABSPATH' ) || exit;
/**
 * QAHMで使用するデータのクラス
 *
 * 全てのデータファイルは下記のデータを入れる
 *
 * 1行目に404コード
 * - セキュリティ対策。名称は404
 *
 * 2行目にヘッダー情報
 * - ひとつしかない情報を入れる。名称はheader
 *
 * 3行目以降にデータ本体
 * - 複数存在する情報を入れる。名称はbody
 *
 * を書き込む。
 *
 * ※データのルール
 * - ヘッダーの先頭は必ずデータのバージョンとする。
 * - 区切り文字はtabにしてtsvの形式で扱う。
 * - セキュリティ対策のため拡張子は.phpにする。
 *
 * バージョンの差異を吸収する関数も作る予定だが、規模が大きくなる可能性がある。
 * その時はより細分化する予定
 *
 * @package qa_heatmap
 */

class QAHM_File_Data extends QAHM_File_Base {

	// 全てのデータに共通する行番号の指定（column）

	// security部は存在しないものと想定
	const DATA_COLUMN_HEADER = 0;
	const DATA_COLUMN_BODY   = 1;

	// 以下定数はデータに格納する順序を定義（row）

	/*
	 * ヘッダーの共通データ
	 *
	 * データの中身を見る際、必ずバージョンを判定する必要がある。
	 * 定数名にはバージョン情報が含まれているため、
	 * まずはこの値でバージョンチェックし使用する定数を選ぶ必要がある。
	 * そのため各定数内にはバージョン情報を含めていない。
	 */
	const DATA_HEADER_VERSION = 0;

	// 位置データ（raw_p）ヘッダー行の追加フィールド。
	// index0 は DATA_HEADER_VERSION、index1 に is_submit を載せる（T108）。
	// このPVでネイティブ submit イベントが観測されたか（0/1）。
	// raw_e ヘッダーが window 解像度を PV 単位で運ぶのと同型。
	const DATA_POS_HEADER_IS_SUBMIT = 1;

	// 位置データ バージョン1
	const DATA_POS_1 = array(
		// body
		'PERCENT_HEIGHT' => 0,       // 高さを百分率で求めた値
		'TIME_ON_HEIGHT' => 1,       // 高さあたりの滞在時間（秒）
	);

	// 位置データ バージョン2
	const DATA_POS_2 = array(
		// body
		'STAY_HEIGHT' => 0,       // 高さを百で割った位置
		'STAY_TIME'   => 1,       // 高さを百で割った位置あたりの滞在時間（秒）
	);

	// クリックデータ バージョン1
	const DATA_CLICK_1 = array(
		// body
		'SELECTOR_NAME' => 0,       // セレクタ名
		'SELECTOR_X'    => 1,       // セレクタ左上からの相対座標X
		'SELECTOR_Y'    => 2,       // セレクタ左上からの相対座標Y
		'TRANSITION'    => 3,       // 遷移先のURL
	);

	// クリックデータ バージョン2
	const DATA_CLICK_2 = array(
		// body
		'SELECTOR_NAME'     => 0,       // セレクタ名
		'SELECTOR_X'        => 1,       // セレクタ左上からの相対座標X
		'SELECTOR_Y'        => 2,       // セレクタ左上からの相対座標Y
		'TRANSITION'        => 3,       // 遷移先のURL
		'EVENT_SEC'         => 4,       // イベント発生秒（ページ閲覧開始から何秒後か）
		'ELEMENT_TEXT'      => 5,       // ボタン・リンクのテキスト
		'ELEMENT_ID'        => 6,       // DOM id属性
		'ELEMENT_CLASS'     => 7,       // class属性
		'ELEMENT_DATA_ATTR' => 8,       // data-*属性
		'ACTION_ID'         => 9,       // アクション分類（1:click, 2:form, 3:tel, 4:mailto）T108: 2 は submit 推測→フォーム領域内クリックの DOM 事実に再定義
		'PAGE_X_PCT'        => 10,      // ページ内クリック位置X（％）整数値
		'PAGE_Y_PCT'        => 11,      // ページ内クリック位置Y（％）整数値
	);

	// イベントデータ バージョン1
	const DATA_EVENT_1 = array(
		// header
		'WINDOW_INNER_W' => 1,       // 解像度W
		'WINDOW_INNER_H' => 2,       // 解像度H
		'DEVICE_NAME'    => 3,       // デバイス名 削除
		'COUNTRY'        => 4,       // 国 readersに移動するので削除

		// body
		'TYPE'           => 0,       // イベントタイプ
		'TIME'           => 1,       // イベントの発生時刻（読み込み完了からのms）
		'CLICK_X'        => 2,       // クリックイベントのX座標
		'CLICK_Y'        => 3,       // クリックイベントのY座標
		'SCROLL_Y'       => 2,       // スクロールイベントのY座標
		'MOUSE_X'        => 2,       // マウスの移動イベントのX座標
		'MOUSE_Y'        => 3,       // マウスの移動イベントのY座標
		'RESIZE_X'       => 2,       // リサイズイベントのX座標
		'RESIZE_Y'       => 3,       // リサイズイベントのY座標
	);

	// マージしたヒートマップデータ バージョン1
	const DATA_MERGE_CLICK_1 = array(
		// body
		'SELECTOR_NAME' => 0,       // セレクタ名
		'SELECTOR_X'    => 1,       // セレクタ相対座標X
		'SELECTOR_Y'    => 2,       // セレクタ相対座標Y
	);

	// マージしたアテンションデータ バージョン1
	const DATA_MERGE_ATTENTION_SCROLL_1 = array(
		// body
		'PERCENT'   => 0,       // 100分率した番号位置
		'STAY_TIME' => 1,       // 100分率した番号位置の平均滞在時間（秒）
		'STAY_NUM'  => 2,       // 100分率した番号位置に滞在した読者の数
		'EXIT_NUM'  => 3,       // 離脱した読者の数
	);

	// マージしたアテンションデータ バージョン2
	const DATA_MERGE_ATTENTION_SCROLL_2 = array(
		// body
		'STAY_HEIGHT' => 0,       // 高さを百で割った位置
		'STAY_TIME'   => 1,       // 高さを百で割った位置の平均滞在時間（秒）
		'STAY_NUM'    => 2,       // 高さを百で割った位置に滞在した読者の数
		'EXIT_NUM'    => 3,       // この地点で離脱した読者の数
	);

	/**
	 * 読者セッションファイル名（readers_name）の形式を検証する。#1642
	 *
	 * 形式は {qa_id}_{Y-m-d}_{セッション番号}（init_session_data() が生成して計測タグへ返す名前）。
	 * 計測タグから送り返された値をそのままファイルパスに使うため、形式に合わない名前は通さない。
	 * 既存の規約に合わせて preg_match は使わず、explode / ctype / strlen で判定する。
	 *
	 * @param mixed $name 検証する名前（拡張子なし）。
	 * @return bool 形式に合えば true。
	 */
	public function is_valid_readers_name( $name ) {
		if ( ! is_string( $name ) ) {
			return false;
		}

		$parts = explode( '_', $name );
		if ( count( $parts ) !== 3 ) {
			return false;
		}

		return $this->is_valid_qa_id( $parts[0] )
			&& $this->is_valid_ymd_str( $parts[1] )
			&& $this->is_valid_digits( $parts[2], 9 );
	}

	/**
	 * 生データファイル名（raw_name）の形式を検証する。#1642
	 *
	 * 形式は {qa_id}_{unixtime}（init_session_data() が生成して計測タグへ返す名前）。
	 * ここに -p / -c / -e / -g と拡張子が付いて保存される。
	 *
	 * @param mixed $name 検証する名前（接尾辞・拡張子なし）。
	 * @return bool 形式に合えば true。
	 */
	public function is_valid_raw_name( $name ) {
		if ( ! is_string( $name ) ) {
			return false;
		}

		$parts = explode( '_', $name );
		if ( count( $parts ) !== 2 ) {
			return false;
		}

		return $this->is_valid_qa_id( $parts[0] )
			&& $this->is_valid_digits( $parts[1], 10 );
	}

	/**
	 * readers/temp 内のファイル名が、計測エンドポイントの作る形式（{readers_name}.php）かを検証する。#1642
	 *
	 * 同じディレクトリにはヘルスチェックの一時ファイルなど別用途のファイルも置かれるため、
	 * 形式に合わないファイルは読み込み対象から外す目的で使う。
	 *
	 * @param mixed $file_name ファイル名。
	 * @return bool 形式に合えば true。
	 */
	public function is_valid_readers_file_name( $file_name ) {
		if ( ! is_string( $file_name ) || substr( $file_name, -4 ) !== '.php' ) {
			return false;
		}

		return $this->is_valid_readers_name( substr( $file_name, 0, -4 ) );
	}

	/**
	 * qa_id の形式（28桁の英数字）を検証する。
	 *
	 * qahm-ajax.php の Cookie（qa_id_z）検証と同じ条件。
	 *
	 * @param string $qa_id 検証する qa_id。
	 * @return bool 形式に合えば true。
	 */
	private function is_valid_qa_id( $qa_id ) {
		return strlen( $qa_id ) === 28 && ctype_alnum( $qa_id );
	}

	/**
	 * Y-m-d 形式の実在する日付かを検証する。
	 *
	 * @param string $ymd 検証する日付文字列。
	 * @return bool 形式に合い、実在する日付なら true。
	 */
	private function is_valid_ymd_str( $ymd ) {
		if ( strlen( $ymd ) !== 10 || $ymd[4] !== '-' || $ymd[7] !== '-' ) {
			return false;
		}

		$year  = substr( $ymd, 0, 4 );
		$month = substr( $ymd, 5, 2 );
		$day   = substr( $ymd, 8, 2 );
		if ( ! ctype_digit( $year ) || ! ctype_digit( $month ) || ! ctype_digit( $day ) ) {
			return false;
		}

		return checkdate( (int) $month, (int) $day, (int) $year );
	}

	/**
	 * 1 文字以上 $max_len 文字以下の数字だけの文字列かを検証する。
	 *
	 * @param string $str     検証する文字列。
	 * @param int    $max_len 許容する最大桁数。
	 * @return bool 条件に合えば true。
	 */
	private function is_valid_digits( $str, $max_len ) {
		$len = strlen( $str );
		return $len >= 1 && $len <= $max_len && ctype_digit( $str );
	}
}
