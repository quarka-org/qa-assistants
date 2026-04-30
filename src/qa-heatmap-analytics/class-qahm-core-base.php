<?php
defined( 'ABSPATH' ) || exit;
/**
 * QAHM Core Base Class
 *
 * プロジェクト全体で共通して利用される「安全ラッパー関数（wrap_*）」を提供する基底クラス。
 *
 * 【役割】
 * - PHP 標準関数を安全に利用するためのラッパーを定義（例：wrap_count(), wrap_in_array() など）
 * - ラッパーの実装本体は static メソッド（wrap_*_static）として提供
 * - インスタンスメソッド（wrap_*）は static メソッドへの薄いラッパーとして機能
 *
 * 【設計思想】
 * - QAHM 内のあらゆるクラスから、統一された「安全な関数呼び出し」を行えるようにする
 * - wrap_*_static() に本体ロジックを集約することで、static／instance 双方から同一処理を利用可能
 * - 互換性対応・入力の安全化・ログ追加などの共通処理を一本化し、メンテナンス性を高める
 *
 * 【使い方】
 *
 * ▼ インスタンスメソッド内から：
 *     $count = $this->wrap_count( $items );
 *
 * ▼ static メソッド内から：
 *     $count = static::wrap_count_static( $items );
 *
 * ▼ ラッパーを追加したいとき：
 *     1. protected static function wrap_xxx_static( ... ) を作成
 *     2. protected function wrap_xxx( ... ) から static メソッドを呼び出す
 *
 * このクラスはフレームワーク層の最下部に位置し、全ての QAHM クラスの共通基盤となります。
 *
 * @package qa_heatmap
 */
abstract class QAHM_Core_Base {

	/*======================================================
	 * count / 配列系
	 *======================================================*/

	/**
	 * 安全な count() ラッパー（本体／static）
	 *
	 * @param mixed $value カウント対象の値.
	 * @return int 要素数（null や非 countable の場合は 0）.
	 */
	protected static function wrap_count_static( $value ) {
		if ( $value === null ) {
			return 0;
		}

		if ( is_array( $value ) || $value instanceof Countable ) {
			return count( $value );
		}

		// 必要であればここでログ出力なども可能.
		return 0;
	}

	/**
	 * 安全な count() ラッパー（インスタンス用）
	 *
	 * @param mixed $value カウント対象の値.
	 * @return int
	 */
	protected function wrap_count( $value ) {
		return static::wrap_count_static( $value );
	}


	/**
	 * 安全な array_filter() ラッパー（本体／static）
	 *
	 * - 配列でない値が来ても fatal / warning を起こさず、空配列を返す。
	 * - callback が未指定（null）なら、PHP標準の「falsy を除外」挙動に従う。
	 * - callback が指定されていても callable でない場合は、callback 無しとして扱う。
	 *
	 * @param mixed         $array    フィルタ対象の配列.
	 * @param callable|null $callback フィルタ関数（省略可）.
	 * @param int           $mode     フラグ（ARRAY_FILTER_USE_KEY など）.
	 * @return array フィルタ後の配列.
	 */
	protected static function wrap_array_filter_static( $array, $callback = null, $mode = 0 ) {
		if ( ! is_array( $array ) ) {
			return array();
		}

		if ( $callback !== null && ! is_callable( $callback ) ) {
			$callback = null;
		}

		// $mode は PHP 標準 array_filter の第3引数に渡す（未指定時は 0）.
		return array_filter( $array, $callback, (int) $mode );
	}

	/**
	 * 安全な array_filter() ラッパー（インスタンス用）
	 *
	 * @param mixed         $array    フィルタ対象の配列.
	 * @param callable|null $callback フィルタ関数（省略可）.
	 * @param int           $mode     フラグ（ARRAY_FILTER_USE_KEY など）.
	 * @return array
	 */
	protected function wrap_array_filter( $array, $callback = null, $mode = 0 ) {
		return static::wrap_array_filter_static( $array, $callback, $mode );
	}

	/**
	 * 安全な array_map() ラッパー（本体／static）
	 *
	 * - 配列でない値を渡すと PHP8 では TypeError になり得るため、配列以外は空配列扱いにする。
	 * - callback が null の場合は PHP 標準 array_map の仕様に従う（複数配列の結合用途など）。
	 * - callback が指定されていても callable でない場合は null として扱う。
	 *
	 * @param callable|null $callback 適用する関数（省略可）.
	 * @param mixed         $array    対象配列.
	 * @param mixed         ...$arrays 追加の配列（省略可）.
	 * @return array マップ後の配列.
	 */
	protected static function wrap_array_map_static( $callback, $array, ...$arrays ) {
		if ( ! is_array( $array ) ) {
			return array();
		}

		$target_arrays = array( $array );

		foreach ( $arrays as $arr ) {
			if ( is_array( $arr ) ) {
				$target_arrays[] = $arr;
			}
		}

		if ( $callback !== null && ! is_callable( $callback ) ) {
			$callback = null;
		}

		return array_map( $callback, ...$target_arrays );
	}

	/**
	 * 安全な array_map() ラッパー（インスタンス用）
	 *
	 * @param callable|null $callback 適用する関数（省略可）.
	 * @param mixed         $array    対象配列.
	 * @param mixed         ...$arrays 追加の配列（省略可）.
	 * @return array
	 */
	protected function wrap_array_map( $callback, $array, ...$arrays ) {
		return static::wrap_array_map_static( $callback, $array, ...$arrays );
	}

	/**
	 * 安全な array_merge() ラッパー（本体／static）
	 *
	 * @param array ...$arrays マージする配列.
	 * @return array マージされた配列.
	 */
	protected static function wrap_array_merge_static( ...$arrays ) {
		$filtered_arrays = array_filter(
			$arrays,
			static function ( $arr ) {
				return is_array( $arr );
			}
		);

		if ( empty( $filtered_arrays ) ) {
			return array();
		}

		return array_merge( ...$filtered_arrays );
	}

	/**
	 * 安全な array_merge() ラッパー（インスタンス用）
	 *
	 * @param array ...$arrays マージする配列.
	 * @return array
	 */
	protected function wrap_array_merge( ...$arrays ) {
		return static::wrap_array_merge_static( ...$arrays );
	}

	/**
	 * 安全な explode() ラッパー（本体／static）
	 *
	 * @param string      $delimiter 区切り文字.
	 * @param string|null $string    分割対象文字列.
	 * @param int         $limit     分割数の制限.
	 * @return array 分割された配列.
	 */
	protected static function wrap_explode_static( $delimiter, $string, $limit = PHP_INT_MAX ) {
		$string = (string) ( $string ?? '' );

		// PHP8 では区切り文字が空文字だと ValueError になるためガードする.
		if ( $delimiter === null || $delimiter === '' ) {
			return array( $string );
		}

		return explode( $delimiter, $string, $limit );
	}

	/**
	 * 安全な explode() ラッパー（インスタンス用）
	 *
	 * @param string      $delimiter 区切り文字.
	 * @param string|null $string    分割対象文字列.
	 * @param int         $limit     分割数の制限.
	 * @return array
	 */
	protected function wrap_explode( $delimiter, $string, $limit = PHP_INT_MAX ) {
		return static::wrap_explode_static( $delimiter, $string, $limit );
	}

	/**
	 * 安全な implode() ラッパー（本体／static）
	 *
	 * @param string $separator 区切り文字.
	 * @param mixed  $array     結合する配列.
	 * @return string 結合された文字列.
	 */
	protected static function wrap_implode_static( $separator, $array ) {
		if ( ! is_array( $array ) ) {
			return '';
		}

		$filtered = array_filter(
			$array,
			static function ( $item ) {
				return $item !== null;
			}
		);

		return implode( $separator ?? '', $filtered );
	}

	/**
	 * 安全な implode() ラッパー（インスタンス用）
	 *
	 * @param string $separator 区切り文字.
	 * @param mixed  $array     結合する配列.
	 * @return string
	 */
	protected function wrap_implode( $separator, $array ) {
		return static::wrap_implode_static( $separator, $array );
	}

	/**
	 * 安全な in_array() ラッパー（本体／static）
	 *
	 * @param mixed $needle   検索値.
	 * @param mixed $haystack 配列.
	 * @param bool  $strict   厳密比較.
	 * @return bool 存在するかどうか.
	 */
	protected static function wrap_in_array_static( $needle, $haystack, $strict = false ) {
		if ( ! is_array( $haystack ) ) {
			return false;
		}

		return in_array( $needle, $haystack, $strict );
	}

	/**
	 * 安全な in_array() ラッパー（インスタンス用）
	 *
	 * @param mixed $needle   検索値.
	 * @param mixed $haystack 配列.
	 * @param bool  $strict   厳密比較.
	 * @return bool
	 */
	protected function wrap_in_array( $needle, $haystack, $strict = false ) {
		return static::wrap_in_array_static( $needle, $haystack, $strict );
	}

	/**
	 * 安全な array_key_exists() ラッパー（本体／static）
	 *
	 * @param mixed $key   キー.
	 * @param mixed $array 配列.
	 * @return bool キーが存在するかどうか.
	 */
	protected static function wrap_array_key_exists_static( $key, $array ) {
		if ( ! is_array( $array ) ) {
			return false;
		}

		return array_key_exists( $key, $array );
	}

	/**
	 * 安全な array_key_exists() ラッパー（インスタンス用）
	 *
	 * @param mixed $key   キー.
	 * @param mixed $array 配列.
	 * @return bool
	 */
	protected function wrap_array_key_exists( $key, $array ) {
		return static::wrap_array_key_exists_static( $key, $array );
	}

	/**
	 * 安全な is_array() ラッパー（本体／static）
	 *
	 * @param mixed $value チェック対象.
	 * @return bool 配列かどうか.
	 */
	protected static function wrap_is_array_static( $value ) {
		return is_array( $value );
	}

	/**
	 * 安全な is_array() ラッパー（インスタンス用）
	 *
	 * @param mixed $value チェック対象.
	 * @return bool
	 */
	protected function wrap_is_array( $value ) {
		return static::wrap_is_array_static( $value );
	}

	/**
	 * 安全な array_push() ラッパー（本体／static）
	 *
	 * @param mixed $array  配列.
	 * @param mixed ...$values 追加する値.
	 * @return array 新しい配列.
	 */
	protected static function wrap_array_push_static( $array, ...$values ) {
		if ( ! is_array( $array ) ) {
			$array = array();
		}

		foreach ( $values as $value ) {
			$array[] = $value;
		}

		return $array;
	}

	/**
	 * 安全な array_push() ラッパー（インスタンス用）
	 *
	 * @param mixed $array  配列.
	 * @param mixed ...$values 追加する値.
	 * @return array
	 */
	protected function wrap_array_push( $array, ...$values ) {
		return static::wrap_array_push_static( $array, ...$values );
	}

	/**
	 * 安全な array_pop() ラッパー（本体／static）
	 *
	 * @param mixed $array 配列.
	 * @return array [取り出した要素, 残りの配列]（配列でない場合は [null, array()]）.
	 */
	protected static function wrap_array_pop_static( $array ) {
		if ( ! is_array( $array ) || empty( $array ) ) {
			return array( null, array() );
		}

		$popped = array_pop( $array );

		return array( $popped, $array );
	}

	/**
	 * 安全な array_pop() ラッパー（インスタンス用）
	 *
	 * @param mixed $array 配列.
	 * @return array
	 */
	protected function wrap_array_pop( $array ) {
		return static::wrap_array_pop_static( $array );
	}

	/*======================================================
	 * 文字列系
	 *======================================================*/

	/**
	 * 安全な strlen() ラッパー（本体／static）
	 *
	 * @param mixed $str 文字列.
	 * @return int 文字列長（null の場合は 0）.
	 */
	protected static function wrap_strlen_static( $str ) {
		return strlen( (string) ( $str ?? '' ) );
	}

	/**
	 * 安全な strlen() ラッパー（インスタンス用）
	 *
	 * @param mixed $str 文字列.
	 * @return int
	 */
	protected function wrap_strlen( $str ) {
		return static::wrap_strlen_static( $str );
	}

	/**
	 * 安全な substr() ラッパー（本体／static）
	 *
	 * @param mixed      $str   文字列.
	 * @param int        $start 開始位置.
	 * @param int|nil    $length 長さ.
	 * @return string 部分文字列（null の場合は空文字）.
	 */
	protected static function wrap_substr_static( $str, $start, $length = null ) {
		$str = (string) ( $str ?? '' );

		if ( $length === null ) {
			return substr( $str, $start );
		}

		return substr( $str, $start, $length );
	}

	/**
	 * 安全な substr() ラッパー（インスタンス用）
	 *
	 * @param mixed      $str   文字列.
	 * @param int        $start 開始位置.
	 * @param int|nil    $length 長さ.
	 * @return string
	 */
	protected function wrap_substr( $str, $start, $length = null ) {
		return static::wrap_substr_static( $str, $start, $length );
	}


	/**
	 * 安全な strpos() ラッパー（本体／static）
	 *
	 * PHP8 では needle（検索文字列）が空文字の場合に ValueError になるためガードする。
	 *
	 * @param mixed $haystack 対象文字列.
	 * @param mixed $needle   検索文字列（空文字は許可しない）.
	 * @param int   $offset   開始位置.
	 * @return int|false 見つかった位置（0以上）または false.
	 */
	protected static function wrap_strpos_static( $haystack, $needle, $offset = 0 ) {
		$haystack = (string) ( $haystack ?? '' );
		$needle   = (string) ( $needle ?? '' );

		if ( $needle === '' ) {
			return false;
		}

		return strpos( $haystack, $needle, (int) $offset );
	}

	/**
	 * 安全な strpos() ラッパー（インスタンス用）
	 *
	 * @param mixed $haystack 対象文字列.
	 * @param mixed $needle   検索文字列（空文字は許可しない）.
	 * @param int   $offset   開始位置.
	 * @return int|false
	 */
	protected function wrap_strpos( $haystack, $needle, $offset = 0 ) {
		return static::wrap_strpos_static( $haystack, $needle, $offset );
	}

	/**
	 * 安全な trim() ラッパー（本体／static）
	 *
	 * @param mixed      $str        対象文字列.
	 * @param string|nil $characters 削除する文字群（省略可）.
	 * @return string トリム後文字列.
	 */
	protected static function wrap_trim_static( $str, $characters = null ) {
		$str = (string) ( $str ?? '' );

		if ( $characters === null ) {
			return trim( $str );
		}

		return trim( $str, (string) $characters );
	}

	/**
	 * 安全な trim() ラッパー（インスタンス用）
	 *
	 * @param mixed      $str        対象文字列.
	 * @param string|nil $characters 削除する文字群（省略可）.
	 * @return string
	 */
	protected function wrap_trim( $str, $characters = null ) {
		return static::wrap_trim_static( $str, $characters );
	}

	/**
	 * 安全な ltrim() ラッパー（本体／static）
	 *
	 * @param mixed      $str
	 * @param string|nil $characters
	 * @return string
	 */
	protected static function wrap_ltrim_static( $str, $characters = null ) {
		$str = (string) ( $str ?? '' );

		if ( $characters === null ) {
			return ltrim( $str );
		}

		return ltrim( $str, (string) $characters );
	}

	/**
	 * 安全な ltrim() ラッパー（インスタンス用）
	 *
	 * @param mixed      $str
	 * @param string|nil $characters
	 * @return string
	 */
	protected function wrap_ltrim( $str, $characters = null ) {
		return static::wrap_ltrim_static( $str, $characters );
	}

	/**
	 * 安全な rtrim() ラッパー（本体／static）
	 *
	 * @param mixed      $str
	 * @param string|nil $characters
	 * @return string
	 */
	protected static function wrap_rtrim_static( $str, $characters = null ) {
		$str = (string) ( $str ?? '' );

		if ( $characters === null ) {
			return rtrim( $str );
		}

		return rtrim( $str, (string) $characters );
	}

	/**
	 * 安全な rtrim() ラッパー（インスタンス用）
	 *
	 * @param mixed      $str
	 * @param string|nil $characters
	 * @return string
	 */
	protected function wrap_rtrim( $str, $characters = null ) {
		return static::wrap_rtrim_static( $str, $characters );
	}

	/**
	 * 安全な str_replace() ラッパー（本体／static）
	 *
	 * @param mixed $search  検索文字列.
	 * @param mixed $replace 置換文字列.
	 * @param mixed $subject 対象文字列.
	 * @return string 置換後の文字列.
	 */
	protected static function wrap_str_replace_static( $search, $replace, $subject ) {
		$search  = $search ?? '';
		$replace = $replace ?? '';
		$subject = $subject ?? '';

		return str_replace( $search, $replace, $subject );
	}

	/**
	 * 安全な str_replace() ラッパー（インスタンス用）
	 *
	 * @param mixed $search  検索文字列.
	 * @param mixed $replace 置換文字列.
	 * @param mixed $subject 対象文字列.
	 * @return string
	 */
	protected function wrap_str_replace( $search, $replace, $subject ) {
		return static::wrap_str_replace_static( $search, $replace, $subject );
	}

	/*======================================================
	 * 型変換系
	 *======================================================*/

	/**
	 * 安全な intval() ラッパー（本体／static）
	 *
	 * @param mixed $value 変換対象.
	 * @return int 整数値（null の場合は 0）.
	 */
	protected static function wrap_intval_static( $value ) {
		return intval( $value ?? 0 );
	}

	/**
	 * 安全な intval() ラッパー（インスタンス用）
	 *
	 * @param mixed $value 変換対象.
	 * @return int
	 */
	protected function wrap_intval( $value ) {
		return static::wrap_intval_static( $value );
	}

	/**
	 * 安全な strval() ラッパー（本体／static）
	 *
	 * @param mixed $value 変換対象.
	 * @return string 文字列（null の場合は空文字）.
	 */
	protected static function wrap_strval_static( $value ) {
		return (string) ( $value ?? '' );
	}

	/**
	 * 安全な strval() ラッパー（インスタンス用）
	 *
	 * @param mixed $value 変換対象.
	 * @return string
	 */
	protected function wrap_strval( $value ) {
		return static::wrap_strval_static( $value );
	}

	/**
	 * 安全な boolval() ラッパー（本体／static）
	 *
	 * @param mixed $value 変換対象.
	 * @return bool 真偽値（null の場合は false）.
	 */
	protected static function wrap_boolval_static( $value ) {
		if ( $value === null ) {
			return false;
		}

		return (bool) $value;
	}

	/**
	 * 安全な boolval() ラッパー（インスタンス用）
	 *
	 * @param mixed $value 変換対象.
	 * @return bool
	 */
	protected function wrap_boolval( $value ) {
		return static::wrap_boolval_static( $value );
	}

	/*======================================================
	 * JSON / serialize 系
	 *======================================================*/

	/**
	 * 安全な json_encode() ラッパー（本体／static）
	 *
	 * ※ Core 層では PHP 標準 json_encode を利用します。
	 *    WordPress 環境では必要に応じて QAHM_WP_Base 側でオーバーライドしてください。
	 *
	 * @param mixed $value   エンコード対象.
	 * @param int   $options JSON オプション.
	 * @param int   $depth   最大深度.
	 * @return string|false JSON 文字列（エラー時は false）.
	 */
	protected static function wrap_json_encode_static( $value, $options = 0, $depth = 512 ) {
		if ( $value === null ) {
			return 'null';
		}

		$result = json_encode( $value, $options, $depth );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return false;
		}

		return $result;
	}

	/**
	 * 安全な json_encode() ラッパー（インスタンス用）
	 *
	 * @param mixed $value   エンコード対象.
	 * @param int   $options JSON オプション.
	 * @param int   $depth   最大深度.
	 * @return string|false
	 */
	protected function wrap_json_encode( $value, $options = 0, $depth = 512 ) {
		return static::wrap_json_encode_static( $value, $options, $depth );
	}

	/**
	 * 安全な json_decode() ラッパー（本体／static）
	 *
	 * ファイル読み出し用。簡易的な serialize データのチェックも行う。
	 * シリアライズデータを検出した場合は wrap_unserialize_static() を呼び出す。
	 *
	 * @param string|null $data JSON 文字列またはシリアライズデータ.
	 * @param bool        $assoc true の場合、連想配列として返す。false の場合、stdClass オブジェクトとして返す。
	 * @return mixed デコード結果（エラー時は null）.
	 */
	protected static function wrap_json_decode_static( $data, $assoc = false ) {
		if ( $data === null || $data === '' ) {
			return null;
		}

		// シリアライズデータの検出（先頭 2 文字でざっくり判定）.
		$str = static::wrap_substr_static( $data, 0, 2 );
		$ary = array( 'a:', 'b:', 'd:', 'i:', 'O:', 's:' );
		if ( static::wrap_in_array_static( $str, $ary, true ) ) {
			return static::wrap_unserialize_static( $data );
		}

		$result = json_decode( $data, $assoc );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return null;
		}

		return $result;
	}

	/**
	 * 安全な json_decode() ラッパー（インスタンス用）
	 *
	 * @param string|null $data JSON 文字列またはシリアライズデータ.
	 * @param bool        $assoc true の場合、連想配列として返す。false の場合、stdClass オブジェクトとして返す。
	 * @return mixed
	 */
	protected function wrap_json_decode( $data, $assoc = false ) {
		return static::wrap_json_decode_static( $data, $assoc );
	}

	/**
	 * serialize のラップ関数（本体／static）
	 *
	 * igbinary 拡張が利用可能な場合は igbinary_serialize を使用。
	 *
	 * @param mixed $value シリアライズ対象.
	 * @return string シリアライズされたデータ.
	 */
	protected static function wrap_serialize_static( $value ) {
		if ( extension_loaded( 'igbinary' ) && function_exists( 'igbinary_serialize' ) ) {
			return igbinary_serialize( $value );
		}

		return serialize( $value );
	}

	/**
	 * serialize のラップ関数（インスタンス用）
	 *
	 * @param mixed $value シリアライズ対象.
	 * @return string
	 */
	protected function wrap_serialize( $value ) {
		return static::wrap_serialize_static( $value );
	}

	/**
	 * unserialize のラップ関数（本体／static）
	 *
	 * igbinary 拡張が利用可能な場合は igbinary_unserialize を試行し、
	 * 失敗した場合は従来の unserialize を使用。
	 * 破損したシリアライズデータの修復も試みる。
	 *
	 * @param string $data シリアライズされたデータ.
	 * @return mixed デシリアライズされたデータ（失敗時は false）.
	 */
	protected static function wrap_unserialize_static( $data ) {
		if ( ! $data ) {
			return false;
		}

		// igbinary_unserialize を試行.
		if ( extension_loaded( 'igbinary' ) && function_exists( 'igbinary_unserialize' ) ) {
			$igbinary_result = @igbinary_unserialize( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $igbinary_result ) {
				return $igbinary_result;
			}
		}

		// 失敗したら従来の unserialize。
		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- バックトラック制限の一時的な緩和のため
		ini_set( 'pcre.backtrack_limit', 5000000 );

		$arr = @unserialize( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false !== $arr ) {
			return $arr;
		}

		// ここから先は、破損シリアライズデータの修復処理（既存ロジックを維持）.
		$pattern = '/(;s:[0-9]+:")([\s\S]+?)("[N];s:[0-9]+:)/';

		$fixed_data = preg_replace_callback(
			$pattern,
			static function ( $matches ) {
				if ( ! preg_match( '/^N/', $matches[3] ) ) {
					$matchfix = str_replace( '"', "'", $matches[2] );
				} else {
					$matchfix = $matches[2];
				}
				return $matches[1] . $matchfix . $matches[3];
			},
			$data
		);

		$str_fixed = preg_replace_callback(
			'/s:([0-9]+):"(.*?)";/',
			static function ( $matches ) {
				return 's:' . strlen( $matches[2] ) . ':"' . $matches[2] . '";';
			},
			$fixed_data
		);
		$arr       = @unserialize( $str_fixed ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false !== $arr ) {
			return $arr;
		}

		$str_fixed = preg_replace_callback(
			'/s:([0-9]+):"(.*?)";/',
			static function ( $match ) {
				return 's:' . strlen( $match[2] ) . ':"' . $match[2] . '";';
			},
			$fixed_data
		);
		$arr       = @unserialize( $str_fixed ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false !== $arr ) {
			return $arr;
		}

		$str_fixed = preg_replace( '%\n%', '', $fixed_data );
		$data      = preg_replace( '%";%', "\xC2\xB5\xC2\xB5\xC2\xB5", $str_fixed );
		$tab       = explode( "\xC2\xB5\xC2\xB5\xC2\xB5", $data );
		$new_data  = '';
		foreach ( $tab as $line ) {
			$new_data .= preg_replace_callback(
				'%\bs:(\d+):"(.*)%',
				static function ( $matches ) {
					$string       = $matches[2];
					$right_length = strlen( $string );
					return 's:' . $right_length . ':"' . $string . '";';
				},
				$line
			);
		}
		$str_fixed = $new_data;
		$arr       = @unserialize( $str_fixed ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false !== $arr ) {
			return $arr;
		}

		$str_fixed = preg_replace_callback(
			'/s:([0-9]+):"(.*?)";/',
			static function ( $match ) {
				return 's:' . strlen( $match[2] ) . ':"' . $match[2] . '";';
			},
			$fixed_data
		);
		$arr       = @unserialize( $str_fixed ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false !== $arr ) {
			return $arr;
		}

		$str_fixed = preg_replace_callback(
			'/s\:(\d+)\:"(.*?)\";/s',
			static function ( $matches ) {
				return 's:' . strlen( $matches[2] ) . ':"' . $matches[2] . '";';
			},
			$fixed_data
		);
		$arr       = @unserialize( $str_fixed ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false !== $arr ) {
			return $arr;
		}

		$str_fixed = preg_replace_callback(
			'/s\:(\d+)\:"(.*?)\";/s',
			static function ( $matches ) {
				return 's:' . strlen( $matches[2] ) . ':"' . $matches[2] . '";';
			},
			$fixed_data
		);
		$arr       = @unserialize( $str_fixed ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false !== $arr ) {
			return $arr;
		}

		return false;
	}

	/**
	 * unserialize のラップ関数（インスタンス用）
	 *
	 * @param string $data シリアライズされたデータ.
	 * @return mixed
	 */
	protected function wrap_unserialize( $data ) {
		return static::wrap_unserialize_static( $data );
	}
}
