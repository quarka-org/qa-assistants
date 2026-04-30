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
		'ACTION_ID'         => 9,       // アクション分類（1:click, 2:submit, 3:tel, 4:mailto）
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
}
