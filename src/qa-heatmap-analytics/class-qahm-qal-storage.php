<?php
/**
 * QAL Storage Class
 * 
 * Provides data retrieval from storage layer (files or database) for QAL execution.
 * This class is responsible for fetching raw physical data without any decoding or transformation.
 *
 * @package qa_heatmap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QAHM_Qal_Storage extends QAHM_File_Base {

	/**
	 * T133: 行が属する日（YYYYMMDD）を載せる列の名前。
	 *
	 * 行そのものに日付を持たないデータセット（gsc）で、Material 層が month 仮想列を
	 * 算出するための素材。列DBは日別ファイルなので、日付は読んだファイル名が持っている。
	 * 注入する側がこの名前の持ち主で、読む側（Material の TIME_BUCKET_SOURCE_MAP）はこの定数を参照する。
	 */
	const DATE_YMD_COLUMN = 'date_ymd';

	/**
	 * Constructor
	 */
	public function __construct() {
	}

	// =======================================================================
	// 列DBプリミティブ（T25a: scan_and_extract + 復号）
	// =======================================================================

	/**
	 * 日付範囲からYYYYMMDD配列を生成（閉区間 [start, end]）
	 *
	 * @param array $time_range ['start' => 'YYYY-MM-DDT...', 'end' => 'YYYY-MM-DDT...', 'tz' => '...']
	 * @return array YYYYMMDD文字列の配列
	 */
	private function get_date_range_list( $time_range ) {
		$tz_string = isset( $time_range['tz'] ) ? $time_range['tz'] : 'Asia/Tokyo';
		try {
			$tz = new DateTimeZone( $tz_string );
		} catch ( Exception $e ) {
			$tz = new DateTimeZone( 'Asia/Tokyo' );
		}

		$start = new DateTime( $time_range['start'], $tz );
		$end   = new DateTime( $time_range['end'], $tz );

		$dates = array();
		$current = clone $start;
		while ( $current <= $end ) {
			$dates[] = $current->format( 'Ymd' );
			$current->modify( '+1 day' );
		}
		return $dates;
	}

	/**
	 * 列DBベースディレクトリを取得
	 *
	 * @param string $tracking_id トラッキングID
	 * @param string $dataset_name データセット名（allpv, click_event, datalayer_event, events）
	 * @return string ディレクトリパス（末尾スラッシュあり）
	 */
	private function get_columndb_base_dir( $tracking_id, $dataset_name ) {
		return WP_CONTENT_DIR . '/qa-zero-data/report/' . $tracking_id . '/columns-db/' . $dataset_name . '/';
	}

	/**
	 * 列ファイルパスを構築
	 *
	 * @param string $base_dir 列DBベースディレクトリ
	 * @param string $dataset_prefix データセット名（ファイル名プレフィックス）
	 * @param string $column_name カラム名
	 * @param string $date_ymd YYYYMMDD
	 * @return string ファイルパス
	 */
	private function get_column_file_path( $base_dir, $dataset_prefix, $column_name, $date_ymd ) {
		$year_month = substr( $date_ymd, 0, 6 );
		return $base_dir . $year_month . '/' . $dataset_prefix . '_' . $date_ymd . '_' . $column_name . '.php';
	}

	/**
	 * 列の「実際に読むべき型」を解決する（T113 #1477）
	 *
	 * 拡幅12列（QAHM_ColumnDB_Schema::WIDENED_UINT32_COLUMNS）だけは、拡幅前に書かれた
	 * 旧幅（uint16/uint8）の列ファイルが実在しうるため、同日の pv_id 列から実幅を自己判定する。
	 * それ以外の列はスキーマ型をそのまま返す（誤判定リスクの面積を広げない）。
	 * 自己判定を信頼してよいのは manifest が done の日だけ（呼び出し元 scan_and_extract の
	 * done ゲートが前提）。詳細は QAHM_ColumnDB_BinaryIO::detect_column_type() の docblock 参照。
	 *
	 * @param string $base_dir 列DBベースディレクトリ
	 * @param string $dataset_prefix データセット名
	 * @param string $column_name カラム名
	 * @param string $date_ymd YYYYMMDD
	 * @param string $schema_type スキーマ上の型（あるべき型）
	 * @return string 実際に読むべき型名
	 */
	private function resolve_actual_type( $base_dir, $dataset_prefix, $column_name, $date_ymd, $schema_type ) {
		if ( ! QAHM_ColumnDB_Schema::is_widened_column( $dataset_prefix, $column_name ) ) {
			return $schema_type;
		}
		$col_path  = $this->get_column_file_path( $base_dir, $dataset_prefix, $column_name, $date_ymd );
		$pvid_path = $this->get_column_file_path( $base_dir, $dataset_prefix, 'pv_id', $date_ymd );
		$actual    = QAHM_ColumnDB_BinaryIO::detect_column_type( $col_path, $pvid_path );
		if ( false === $actual ) {
			// 判定不能（pv_id 不在・行数がどの幅でも合わない破損）＝warn してスキーマ型へフォールバック
			// （done な日では起きない想定。起きたら破損の検出そのもの）
			global $qahm_log;
			if ( is_object( $qahm_log ) ) {
				$qahm_log->warning( 'ColumnDB width detect failed (T113 #1477): ' . $dataset_prefix . ' ' . $date_ymd . ' ' . $column_name . ' — fallback to schema type ' . $schema_type );
			}
			return $schema_type;
		}
		return $actual;
	}

	/**
	 * 型別配列読み込みラッパー
	 *
	 * @param string $filepath ファイルパス
	 * @param string $type 型名（uint64, uint32, uint16, uint8）
	 * @return array|false 値の配列
	 */
	private function read_typed_array( $filepath, $type ) {
		switch ( $type ) {
			case 'uint64':
				return QAHM_ColumnDB_BinaryIO::read_uint64_array( $filepath );
			case 'uint32':
				return QAHM_ColumnDB_BinaryIO::read_uint32_array( $filepath );
			case 'uint16':
				return QAHM_ColumnDB_BinaryIO::read_uint16_array( $filepath );
			case 'uint8':
				return QAHM_ColumnDB_BinaryIO::read_uint8_array( $filepath );
			default:
				return false;
		}
	}

	/**
	 * バイナリ列ファイルから match_ids に一致する行オフセットを収集する
	 *
	 * スキャン列を生バイナリのまま strpos() で検索するため、
	 * PHPのforeachループを排除し、Cレベルのバイト検索で高速にマッチ行を特定する。
	 *
	 * @param string     $filepath   列ファイルパス
	 * @param string     $type       型名（'uint64', 'uint32', 'uint16', 'uint8'）
	 * @param array|null $match_ids  マッチするID配列。nullなら全行マッチ
	 * @return array|null  マッチした行オフセットの配列。nullは「全行マッチ」を意味する。
	 *                     ファイルが存在しない場合は空配列 []
	 */
	private function binary_scan( $filepath, $type, $match_ids ) {
		$type_info = array(
			'uint64' => array( 'bytes' => 8, 'pack' => 'P' ),
			'uint32' => array( 'bytes' => 4, 'pack' => 'V' ),
			'uint16' => array( 'bytes' => 2, 'pack' => 'v' ),
			'uint8'  => array( 'bytes' => 1, 'pack' => 'C' ),
		);
		$bytes = $type_info[ $type ]['bytes'];
		$pack  = $type_info[ $type ]['pack'];

		if ( $match_ids === null ) {
			return null;
		}

		// 大量ID時はforeachフォールバック
		if ( count( $match_ids ) > 100 ) {
			return $this->binary_scan_foreach_fallback( $filepath, $type, $match_ids );
		}

		$binary = QAHM_ColumnDB_BinaryIO::read_file( $filepath );
		if ( $binary === false || $binary === '' ) {
			return array();
		}

		$offsets = array();
		foreach ( $match_ids as $id ) {
			$needle = pack( $pack, $id );
			$pos = 0;
			while ( ( $pos = strpos( $binary, $needle, $pos ) ) !== false ) {
				if ( $pos % $bytes === 0 ) {
					$offsets[ $pos / $bytes ] = true;
				}
				$pos++;
			}
		}

		$result = array_keys( $offsets );
		sort( $result, SORT_NUMERIC );
		return $result;
	}

	/**
	 * 大量ID時のforeachフォールバック
	 *
	 * @param string $filepath 列ファイルパス
	 * @param string $type 型名
	 * @param array $match_ids マッチするID配列
	 * @return array マッチした行オフセットの配列
	 */
	private function binary_scan_foreach_fallback( $filepath, $type, $match_ids ) {
		$data = $this->read_typed_array( $filepath, $type );
		if ( $data === false ) {
			return array();
		}

		$id_set = array_flip( $match_ids );
		$offsets = array();
		foreach ( $data as $offset => $value ) {
			if ( isset( $id_set[ $value ] ) ) {
				$offsets[] = $offset;
			}
		}
		return $offsets;
	}

	/**
	 * 列DBからスキャン+抽出を行うコア関数
	 *
	 * scan_column でマッチした行の keep_columns データを返す。
	 * Phase 1（バイナリ検索）→ Phase 2（keep列展開）→ Phase 3（列抽出）を実行。
	 * Phase 4（復号）は呼び出し元の各 fetch_*_data() が担当する。
	 *
	 * @param string     $base_dir        列DBベースディレクトリ
	 * @param string     $dataset_prefix  データセット名（ファイル名プレフィックス）
	 * @param array      $dates           対象日付配列（YYYYMMDD）
	 * @param string     $scan_column     スキャン対象の物理カラム名
	 * @param array|null $match_ids       マッチするID配列。nullなら全行マッチ
	 * @param array      $keep_columns    返却する物理カラム名の配列
	 * @param array      $schema          カラムごとの型情報 ['pv_id' => 'uint32', ...]
	 * @param string|null $emit_date_column 指定時、各行にその行を読んだ日別ファイルの日付(YYYYMMDD)を
	 *                                     この名前で付与する。行に日付を持たないデータセット
	 *                                     （gsc 等）で月バケット等の時間次元を作るための素材（T133）
	 * @return array ['record_count' => int, 'data' => [行配列]]
	 */
	private function scan_and_extract( $base_dir, $dataset_prefix, $dates, $scan_column, $match_ids, $keep_columns, $schema, $emit_date_column = null ) {
		global $qahm_log;
		$all_records = array();

		foreach ( $dates as $date_ymd ) {
			// Issue #1279: manifest（変換完了台帳）にエントリがあり state=done でない日は、
			// 変換中/中断の不完全データのためスキップする（エントリの無い日は従来どおり）。
			// load はプロセス内キャッシュされるため、ファイル読みは月 1 回
			if ( class_exists( 'QAHM_ColumnDB_Manifest' ) ) {
				$manifest_entry = QAHM_ColumnDB_Manifest::get_day(
					QAHM_ColumnDB_Manifest::load( $base_dir, substr( $date_ymd, 0, 6 ) ),
					$date_ymd
				);
				if ( null !== $manifest_entry && ! QAHM_ColumnDB_Manifest::is_done_entry( $manifest_entry ) ) {
					if ( is_object( $qahm_log ) ) {
						$qahm_log->debug( 'QAL columndb: skip incomplete day (manifest state != done) ' . $dataset_prefix . ' ' . $date_ymd );
					}
					continue;
				}
			}

			$scan_path = $this->get_column_file_path( $base_dir, $dataset_prefix, $scan_column, $date_ymd );
			if ( ! file_exists( $scan_path ) ) {
				continue;
			}

			// Phase 1: メイン列検索（バイナリ検索 or 全件）
			// T113 (#1477): 拡幅12列は旧幅（uint16/uint8）ファイルが実在しうるため、
			// スキーマ型でなく実ファイルの幅（resolve_actual_type）で検索する
			$scan_type = $this->resolve_actual_type( $base_dir, $dataset_prefix, $scan_column, $date_ymd, $schema[ $scan_column ] );
			$matched_offsets = $this->binary_scan( $scan_path, $scan_type, $match_ids );
			if ( is_array( $matched_offsets ) && empty( $matched_offsets ) ) {
				continue;
			}

			// Phase 2: keep列を全行展開
			$keep_data = array();
			$row_count = null;
			foreach ( $keep_columns as $col ) {
				$col_path = $this->get_column_file_path( $base_dir, $dataset_prefix, $col, $date_ymd );
				$col_type = $this->resolve_actual_type( $base_dir, $dataset_prefix, $col, $date_ymd, $schema[ $col ] );
				$col_data = $this->read_typed_array( $col_path, $col_type );
				if ( $col_data === false ) {
					$col_data = array();
				}
				$keep_data[ $col ] = $col_data;
				if ( $row_count === null ) {
					$row_count = count( $col_data );
				}
			}

			// T133: 日付だけを要求されたケース（keep 列が空）でも行数を決められるようにする。
			// スキャン列の長さがその日の行数。
			if ( $row_count === null && $emit_date_column !== null ) {
				$scan_data = $this->read_typed_array( $scan_path, $scan_type );
				$row_count = ( $scan_data === false ) ? 0 : count( $scan_data );
			}

			if ( $row_count === null || $row_count === 0 ) {
				continue;
			}

			// Phase 3: 列抽出
			if ( $matched_offsets === null ) {
				// 全件
				for ( $i = 0; $i < $row_count; $i++ ) {
					$record = array();
					foreach ( $keep_columns as $col ) {
						$record[ $col ] = isset( $keep_data[ $col ][ $i ] ) ? $keep_data[ $col ][ $i ] : null;
					}
					// T133: 行が属する日（日別ファイル名の日付）。月バケットの素材
					if ( $emit_date_column !== null ) {
						$record[ $emit_date_column ] = $date_ymd;
					}
					$all_records[] = $record;
				}
			} else {
				// マッチ行のみ
				foreach ( $matched_offsets as $offset ) {
					if ( $offset >= $row_count ) {
						continue;
					}
					$record = array();
					foreach ( $keep_columns as $col ) {
						$record[ $col ] = isset( $keep_data[ $col ][ $offset ] ) ? $keep_data[ $col ][ $offset ] : null;
					}
					if ( $emit_date_column !== null ) {
						$record[ $emit_date_column ] = $date_ymd;
					}
					$all_records[] = $record;
				}
			}
		}

		return array(
			'record_count' => count( $all_records ),
			'data'         => $all_records,
		);
	}

	/**
	 * 辞書カラムのID→文字列変換を行う（物理カラム名→マテリアルカラム名への変換含む）
	 *
	 * @param array  $records      scan_and_extract() が返したレコード配列
	 * @param array  $dict_map     辞書マッピング定義
	 *     [
	 *       'physical_col' => [
	 *           'material_col' => 'event_name',
	 *           'dict'         => QAHM_ColumnDB_Dictionary instance,
	 *       ],
	 *     ]
	 * @return array 復号済みレコード配列
	 */
	private function decode_dict_columns( $records, $dict_map ) {
		foreach ( $records as &$record ) {
			foreach ( $dict_map as $physical_col => $info ) {
				if ( ! isset( $record[ $physical_col ] ) ) {
					continue;
				}
				$id = (int) $record[ $physical_col ];
				if ( $id === 0 ) {
					$record[ $info['material_col'] ] = null;
				} else {
					$record[ $info['material_col'] ] = $info['dict']->get_string( $id );
				}
				unset( $record[ $physical_col ] );
			}
		}
		unset( $record );
		return $records;
	}

	/**
	 * 要求された物理カラムから辞書カラムの物理名に解決する
	 *
	 * keep_columns にマテリアルカラム名が含まれる場合、対応する物理カラム名に置換する。
	 *
	 * @param array $physical_columns 元の物理カラム配列
	 * @param array $alias_map マテリアルカラム名 → 物理カラム名 のマッピング
	 * @return array 解決済みの物理カラム配列
	 */
	private function resolve_physical_keep( $physical_columns, $alias_map ) {
		$resolved = array();
		foreach ( $physical_columns as $col ) {
			if ( isset( $alias_map[ $col ] ) ) {
				$resolved[] = $alias_map[ $col ];
			} else {
				$resolved[] = $col;
			}
		}
		return array_unique( $resolved );
	}

	// =======================================================================
	// 列DB フィルタ評価（T25b: フィルタ条件 → scan_column + match_ids）
	// =======================================================================

	/**
	 * フィルタ条件を列DB用の scan_column + match_ids に変換する
	 *
	 * フィルタ条件の中から列DBでスキャン可能な条件を1つ選び、
	 * (scan_column, match_ids) のペアに変換する。
	 * 残りのフィルタ条件は remaining_filters として返す。
	 *
	 * 戦略の自動推論:
	 *   - physical_column ≠ material_column（辞書カラム）→ 辞書lookup
	 *   - physical_column = material_column（数値カラム）→ 直接ID比較
	 *   - スキーマに存在しないカラム → スキップ（master参照等）
	 *
	 * @param array       $filter_conditions  フィルタ条件（マテリアルカラム名ベース）
	 * @param array       $alias_map          マテリアルカラム→物理カラムのマッピング
	 * @param array       $schema             物理カラム→型のマッピング
	 * @param string      $base_dir           列DBベースディレクトリ
	 * @param array       $dict_files         物理カラム→辞書ファイル名のマッピング
	 * @param string|null $tracking_id        トラッキングID（Selectors用）
	 * @param array       $selector_columns   Selectorsを使う物理カラム名の配列
	 * @return array|null ['scan_column', 'match_ids', 'remaining_filters'] or null
	 */
	private function resolve_columndb_filter( $filter_conditions, $alias_map, $schema, $base_dir, $dict_files = array(), $tracking_id = null, $selector_columns = array() ) {
		if ( empty( $filter_conditions ) || ! is_array( $filter_conditions ) ) {
			return null;
		}

		$remaining = $filter_conditions;

		foreach ( $filter_conditions as $material_col => $condition ) {
			// マテリアルカラム→物理カラム解決
			$physical_col = isset( $alias_map[ $material_col ] ) ? $alias_map[ $material_col ] : $material_col;

			// 列DBスキーマに存在しないカラムはスキップ（master参照等）
			if ( ! isset( $schema[ $physical_col ] ) ) {
				continue;
			}

			// 辞書カラムかどうか判定
			$is_dict     = isset( $dict_files[ $physical_col ] );
			$is_selector = in_array( $physical_col, $selector_columns, true );

			// フィルタ値からmatch_idsを解決
			$match_ids = null;
			if ( is_array( $condition ) && $this->is_operator_filter( $condition ) ) {
				$match_ids = $this->resolve_operator_to_ids( $condition, $physical_col, $is_dict, $is_selector, $base_dir, $dict_files, $tracking_id );
			} else {
				$match_ids = $this->resolve_in_clause_to_ids( $condition, $physical_col, $is_dict, $is_selector, $base_dir, $dict_files, $tracking_id );
			}

			if ( $match_ids !== null ) {
				unset( $remaining[ $material_col ] );
				return array(
					'scan_column'       => $physical_col,
					'match_ids'         => $match_ids,
					'remaining_filters' => $remaining,
				);
			}
		}

		return null;
	}

	/**
	 * IN clause形式のフィルタ値をmatch_idsに変換する
	 *
	 * @param mixed       $values          フィルタ値の配列
	 * @param string      $physical_col    物理カラム名
	 * @param bool        $is_dict         辞書カラムか
	 * @param bool        $is_selector     Selectorsカラムか
	 * @param string      $base_dir        列DBベースディレクトリ
	 * @param array       $dict_files      辞書ファイルマッピング
	 * @param string|null $tracking_id     トラッキングID
	 * @return array|null match_ids配列 or null
	 */
	private function resolve_in_clause_to_ids( $values, $physical_col, $is_dict, $is_selector, $base_dir, $dict_files, $tracking_id ) {
		if ( ! is_array( $values ) || empty( $values ) ) {
			return null;
		}

		if ( $is_dict || $is_selector ) {
			$dict = $this->open_filter_dict( $physical_col, $is_selector, $base_dir, $dict_files, $tracking_id );
			if ( $dict === null ) {
				return null;
			}
			$ids = array();
			foreach ( $values as $val ) {
				$id = $dict->lookup( (string) $val );
				if ( $id !== null ) {
					$ids[] = $id;
				}
			}
			$dict->close();
			return empty( $ids ) ? array() : $ids;
		}

		// 直接ID比較（値がそのまま数値ID）
		$ids = array();
		foreach ( $values as $val ) {
			$ids[] = (int) $val;
		}
		return $ids;
	}

	/**
	 * オペレータ形式のフィルタ条件をmatch_idsに変換する
	 *
	 * eq/in → match_idsに変換可能
	 * contains/prefix → 辞書全エントリをスキャンしてマッチするIDを収集
	 * 数値範囲（gte/lte/between等） → null（post_filterで処理）
	 *
	 * @param array       $condition       オペレータ条件
	 * @param string      $physical_col    物理カラム名
	 * @param bool        $is_dict         辞書カラムか
	 * @param bool        $is_selector     Selectorsカラムか
	 * @param string      $base_dir        列DBベースディレクトリ
	 * @param array       $dict_files      辞書ファイルマッピング
	 * @param string|null $tracking_id     トラッキングID
	 * @return array|null match_ids配列 or null
	 */
	private function resolve_operator_to_ids( $condition, $physical_col, $is_dict, $is_selector, $base_dir, $dict_files, $tracking_id ) {
		// eq → 単一値lookup
		if ( isset( $condition['eq'] ) && count( $condition ) === 1 ) {
			return $this->resolve_in_clause_to_ids(
				array( $condition['eq'] ), $physical_col, $is_dict, $is_selector, $base_dir, $dict_files, $tracking_id
			);
		}

		// in → 複数値lookup
		if ( isset( $condition['in'] ) && is_array( $condition['in'] ) && count( $condition ) === 1 ) {
			return $this->resolve_in_clause_to_ids(
				$condition['in'], $physical_col, $is_dict, $is_selector, $base_dir, $dict_files, $tracking_id
			);
		}

		// contains/prefix → 辞書全エントリスキャン
		if ( ( $is_dict || $is_selector ) && ( isset( $condition['contains'] ) || isset( $condition['prefix'] ) ) ) {
			$dict = $this->open_filter_dict( $physical_col, $is_selector, $base_dir, $dict_files, $tracking_id );
			if ( $dict === null ) {
				return null;
			}
			$all = $dict->get_all_entries();
			$ids = array();
			foreach ( $all as $id => $str ) {
				if ( $this->match_operator_filter( $str, $condition ) ) {
					$ids[] = $id;
				}
			}
			$dict->close();
			return $ids;
		}

		// 数値範囲（gte/lte/between/gt/lt）→ match_idsに変換不可、post_filterで処理
		return null;
	}

	/**
	 * フィルタ用の辞書インスタンスを開く
	 *
	 * @param string      $physical_col    物理カラム名
	 * @param bool        $is_selector     Selectorsカラムか
	 * @param string      $base_dir        列DBベースディレクトリ
	 * @param array       $dict_files      辞書ファイルマッピング
	 * @param string|null $tracking_id     トラッキングID
	 * @return object|null 辞書インスタンス or null
	 */
	private function open_filter_dict( $physical_col, $is_selector, $base_dir, $dict_files, $tracking_id ) {
		if ( $is_selector && $tracking_id !== null ) {
			// T68: tracking_id='all' の selectors 辞書は report/all/columns-db/click_event/ 配下に統一配置
			if ( $tracking_id === 'all' ) {
				return new QAHM_ColumnDB_Selectors( 'all', $this->get_columndb_base_dir( 'all', 'click_event' ) );
			}
			return new QAHM_ColumnDB_Selectors( $tracking_id );
		}
		if ( isset( $dict_files[ $physical_col ] ) ) {
			$dict_path = $base_dir . $dict_files[ $physical_col ];
			if ( ! file_exists( $dict_path ) ) {
				return null;
			}
			return new QAHM_ColumnDB_Dictionary( $dict_path );
		}
		return null;
	}

	/**
	 * 残余フィルタが参照するカラムをkeep列に追加する
	 *
	 * フィルタ条件のカラムがkeep列に含まれていない場合、scan_and_extractで
	 * 取得されずpost_filterが正しく動作しない。このメソッドでフィルタ用の
	 * カラムを追加し、フィルタ適用後に除去するためのリストを返す。
	 *
	 * @param array $physical_keep      現在のkeep列（物理カラム名）
	 * @param array $remaining_filters  残余フィルタ条件（マテリアルカラム名ベース）
	 * @param array $alias_map          マテリアルカラム→物理カラムのマッピング
	 * @param array $schema             物理カラム→型のマッピング
	 * @return array ['keep' => 拡張済みkeep列, 'extra_cols' => 後で除去するカラム名配列（物理名+マテリアル名）]
	 */
	private function augment_keep_for_filters( $physical_keep, $remaining_filters, $alias_map, $schema ) {
		$extra_cols = array();
		if ( empty( $remaining_filters ) ) {
			return array( 'keep' => $physical_keep, 'extra_cols' => $extra_cols );
		}

		foreach ( $remaining_filters as $material_col => $condition ) {
			$physical_col = isset( $alias_map[ $material_col ] ) ? $alias_map[ $material_col ] : $material_col;
			if ( isset( $schema[ $physical_col ] ) && ! in_array( $physical_col, $physical_keep, true ) ) {
				$physical_keep[] = $physical_col;
				$extra_cols[] = $physical_col;
				// 辞書デコード後はマテリアル名に変わるため、両方を除去対象にする
				if ( $physical_col !== $material_col ) {
					$extra_cols[] = $material_col;
				}
			}
		}

		return array( 'keep' => $physical_keep, 'extra_cols' => $extra_cols );
	}

	/**
	 * フィルタ用に追加したカラムをレコードから除去する
	 *
	 * @param array $records    レコード配列
	 * @param array $extra_cols 除去するカラム名配列
	 * @return array カラム除去済みレコード配列
	 */
	private function strip_extra_columns( $records, $extra_cols ) {
		if ( empty( $extra_cols ) ) {
			return $records;
		}
		foreach ( $records as &$record ) {
			foreach ( $extra_cols as $col ) {
				unset( $record[ $col ] );
			}
		}
		unset( $record );
		return $records;
	}

	/**
	 * scan_and_extract後の結果にフィルタ条件を適用する（残余フィルタ用）
	 *
	 * 列DBスキャンで処理できなかったフィルタ条件（数値範囲等）を
	 * デコード済みレコードに対してPHPループで適用する。
	 *
	 * @param array $records          デコード済みレコード配列
	 * @param array $remaining_filters 残余フィルタ条件（マテリアルカラム名ベース）
	 * @return array フィルタ適用済みレコード配列
	 */
	private function apply_post_filters( $records, $remaining_filters ) {
		if ( empty( $remaining_filters ) ) {
			return $records;
		}

		$filtered = array();
		foreach ( $records as $record ) {
			$matches = true;
			foreach ( $remaining_filters as $field => $condition ) {
				if ( ! $matches ) {
					break;
				}
				$value = array_key_exists( $field, $record ) ? $record[ $field ] : null;
				if ( is_array( $condition ) && $this->is_operator_filter( $condition ) ) {
					if ( ! $this->match_operator_filter( $value, $condition ) ) {
						$matches = false;
					}
				} elseif ( is_array( $condition ) ) {
					// IN clause形式
					if ( ! $this->wrap_in_array( $value, $condition ) ) {
						$matches = false;
					}
				}
			}
			if ( $matches ) {
				$filtered[] = $record;
			}
		}
		return $filtered;
	}

	// =======================================================================
	// 列DB マテリアル別 fetch_*_data()（T25a）
	// =======================================================================

	/**
	 * datalayer_event マテリアルのデータ取得（Layer 1）
	 *
	 * 固定5カラム、辞書2個のシンプルなパターン。
	 *
	 * @param string     $tracking_id      トラッキングID
	 * @param string     $material_name    マテリアル名
	 * @param array      $time_range       時間範囲
	 * @param array|null $filter_conditions フィルタ条件
	 * @param array      $physical_columns 物理カラム名の配列
	 * @param bool       $count_only       カウントのみ
	 * @return array 結果
	 */
	private function fetch_datalayer_event_data( $tracking_id, $material_name, $time_range, $filter_conditions, $physical_columns, $count_only ) {
		$base_dir = $this->get_columndb_base_dir( $tracking_id, 'datalayer_event' );
		if ( ! is_dir( $base_dir ) ) {
			return array(
				'error_code' => 'E_DATA_SOURCE_NOT_FOUND',
				'message'    => sprintf( "ColumnDB datalayer_event directory not found for tracking_id '%s'", $tracking_id ),
				'location'   => 'storage',
			);
		}

		$dates = $this->get_date_range_list( $time_range );

		// マテリアルカラム→物理カラム解決
		$alias_map = array(
			'event_name' => 'event_name_id',
			'params_json' => 'params_id',
		);
		$physical_keep = $this->resolve_physical_keep( $physical_columns, $alias_map );

		// 型は SCHEMA_DATALAYER_EVENT から引く（T113 #1477・型リテラルの複製はミスアラインの温床）
		$schema = $this->build_join_schema( QAHM_ColumnDB_Schema::DATASET_DATALAYER_EVENT, array(
			'pv_id', 'session_id', 'page_id', 'event_name_id', 'params_id',
		) );

		// T25b: フィルタ条件を scan_column + match_ids に変換
		$scan_column       = 'pv_id';
		$match_ids         = null;
		$remaining_filters = array();

		$dict_files = array(
			'event_name_id' => 'dict-event-names.php',
			'params_id'     => 'dict-params-json.php',
		);

		if ( ! empty( $filter_conditions ) && is_array( $filter_conditions ) ) {
			$resolved = $this->resolve_columndb_filter( $filter_conditions, $alias_map, $schema, $base_dir, $dict_files );
			if ( $resolved !== null ) {
				$scan_column       = $resolved['scan_column'];
				$match_ids         = $resolved['match_ids'];
				$remaining_filters = $resolved['remaining_filters'];
			} else {
				// 列DBで解決できるフィルタがなかった場合、全件取得して後フィルタ
				$remaining_filters = $filter_conditions;
			}
		}

		// 残余フィルタのカラムをkeep列に追加（フィルタ評価に必要）
		$extra_cols = array();
		if ( ! empty( $remaining_filters ) ) {
			$augmented = $this->augment_keep_for_filters( $physical_keep, $remaining_filters, $alias_map, $schema );
			$physical_keep = $augmented['keep'];
			$extra_cols    = $augmented['extra_cols'];
		}

		$result = $this->scan_and_extract( $base_dir, 'datalayer_event', $dates, $scan_column, $match_ids, $physical_keep, $schema );

		if ( $count_only && empty( $remaining_filters ) ) {
			return array(
				'record_count' => 1,
				'data'         => array( array( 'count' => $result['record_count'] ) ),
			);
		}

		// Phase 4: 辞書引き
		$dict_map = array();
		if ( in_array( 'event_name_id', $physical_keep, true ) ) {
			$dict_map['event_name_id'] = array(
				'material_col' => 'event_name',
				'dict'         => new QAHM_ColumnDB_Dictionary( $base_dir . 'dict-event-names.php' ),
			);
		}
		if ( in_array( 'params_id', $physical_keep, true ) ) {
			$dict_map['params_id'] = array(
				'material_col' => 'params_json',
				'dict'         => new QAHM_ColumnDB_Dictionary( $base_dir . 'dict-params-json.php' ),
			);
		}

		if ( ! empty( $dict_map ) ) {
			$result['data'] = $this->decode_dict_columns( $result['data'], $dict_map );
			foreach ( $dict_map as $info ) {
				$info['dict']->close();
			}
		}

		// T25b: 残余フィルタの適用 → 追加カラムの除去
		if ( ! empty( $remaining_filters ) ) {
			$result['data'] = $this->apply_post_filters( $result['data'], $remaining_filters );
			$result['data'] = $this->strip_extra_columns( $result['data'], $extra_cols );
			$result['record_count'] = count( $result['data'] );
		}

		if ( $count_only ) {
			return array(
				'record_count' => 1,
				'data'         => array( array( 'count' => $result['record_count'] ) ),
			);
		}

		return $result;
	}

	/**
	 * events.{name} マテリアルのデータ取得（Layer 2: serialize表配列）
	 *
	 * バイナリ列DBではなくserialize表配列を読み込む。辞書引き不要。
	 *
	 * @param string     $tracking_id      トラッキングID
	 * @param string     $material_name    マテリアル名（"events.purchase" 等）
	 * @param array      $time_range       時間範囲
	 * @param array|null $filter_conditions フィルタ条件
	 * @param array      $physical_columns 物理カラム名の配列
	 * @param bool       $count_only       カウントのみ
	 * @return array 結果
	 */
	private function fetch_event_detail_data( $tracking_id, $material_name, $time_range, $filter_conditions, $physical_columns, $count_only ) {
		$event_name = substr( $material_name, 7 ); // "events." の後
		$ev_dir_name = $this->sanitize_event_dir( $event_name );

		$events_base = $this->get_columndb_base_dir( $tracking_id, 'events' );
		$event_dir = $events_base . $ev_dir_name . '/';

		if ( ! is_dir( $event_dir ) ) {
			return array(
				'error_code' => 'E_DATA_SOURCE_NOT_FOUND',
				'message'    => sprintf( "Event detail directory not found for '%s'", $material_name ),
				'location'   => 'storage',
			);
		}

		$dates = $this->get_date_range_list( $time_range );

		// フィルタ用カラムもレコードに含める（keep対象外でもフィルタ評価に必要）
		$extract_columns = $physical_columns;
		$extra_filter_cols = array();
		if ( ! empty( $filter_conditions ) && is_array( $filter_conditions ) ) {
			foreach ( $filter_conditions as $filter_col => $cond ) {
				if ( ! in_array( $filter_col, $extract_columns, true ) ) {
					$extract_columns[] = $filter_col;
					$extra_filter_cols[] = $filter_col;
				}
			}
		}

		$all_records = array();
		foreach ( $dates as $date_ymd ) {
			$ym = substr( $date_ymd, 0, 6 );
			$filepath = $event_dir . $ym . '/' . $ev_dir_name . '_' . $date_ymd . '.php';
			if ( ! file_exists( $filepath ) ) {
				continue;
			}

			$table = $this->wrap_unserialize( $this->wrap_get_contents( $filepath ) );
			if ( ! is_array( $table ) || empty( $table['rows'] ) || empty( $table['columns'] ) ) {
				continue;
			}

			$columns = $table['columns'];
			foreach ( $table['rows'] as $row ) {
				$record = array_combine( $columns, array_pad( $row, count( $columns ), null ) );
				$filtered_record = array();
				foreach ( $extract_columns as $col ) {
					if ( array_key_exists( $col, $record ) ) {
						$filtered_record[ $col ] = $record[ $col ];
					}
				}
				$all_records[] = $filtered_record;
			}
		}

		// T25b: Layer 2フィルタ → 追加カラムの除去
		if ( ! empty( $filter_conditions ) && is_array( $filter_conditions ) ) {
			$all_records = $this->apply_post_filters( $all_records, $filter_conditions );
			$all_records = $this->strip_extra_columns( $all_records, $extra_filter_cols );
		}

		if ( $count_only ) {
			return array(
				'record_count' => 1,
				'data'         => array( array( 'count' => count( $all_records ) ) ),
			);
		}

		return array(
			'record_count' => count( $all_records ),
			'data'         => $all_records,
		);
	}

	/**
	 * click_event マテリアルのデータ取得
	 *
	 * datalayer_eventと同パターンだが辞書が7個。
	 *
	 * @param string     $tracking_id      トラッキングID
	 * @param string     $material_name    マテリアル名
	 * @param array      $time_range       時間範囲
	 * @param array|null $filter_conditions フィルタ条件
	 * @param array      $physical_columns 物理カラム名の配列
	 * @param bool       $count_only       カウントのみ
	 * @return array 結果
	 */
	private function fetch_click_event_data( $tracking_id, $material_name, $time_range, $filter_conditions, $physical_columns, $count_only ) {
		$base_dir = $this->get_columndb_base_dir( $tracking_id, 'click_event' );
		if ( ! is_dir( $base_dir ) ) {
			return array(
				'error_code' => 'E_DATA_SOURCE_NOT_FOUND',
				'message'    => sprintf( "ColumnDB click_event directory not found for tracking_id '%s'", $tracking_id ),
				'location'   => 'storage',
			);
		}

		$dates = $this->get_date_range_list( $time_range );

		// マテリアルカラム→物理カラム解決（7辞書カラム）
		$alias_map = array(
			'selector'     => 'selector_id',
			'element_text' => 'element_text_id',
			'element_id'   => 'element_id_id',
			'element_class' => 'element_class_id',
			'element_data' => 'element_data_id',
			'to_url'       => 'to_url_id',
		);
		$physical_keep = $this->resolve_physical_keep( $physical_columns, $alias_map );

		// 型は SCHEMA_CLICK_EVENT から引く（T113 #1477・型リテラルの複製はミスアラインの温床）
		$schema = $this->build_join_schema( QAHM_ColumnDB_Schema::DATASET_CLICK_EVENT, array(
			'pv_id', 'session_id', 'page_id', 'event_sec', 'selector_id',
			'element_text_id', 'element_id_id', 'element_class_id',
			'element_data_id', 'to_url_id',
			'is_external', 'action_id', 'page_x_pct', 'page_y_pct',
		) );

		// T25b: フィルタ条件を scan_column + match_ids に変換
		$scan_column       = 'pv_id';
		$match_ids         = null;
		$remaining_filters = array();

		$dict_files = array(
			'element_text_id'  => 'dict-element-texts.php',
			'element_id_id'    => 'dict-element-ids.php',
			'element_class_id' => 'dict-element-classes.php',
			'element_data_id'  => 'dict-element-data-attrs.php',
			'to_url_id'        => 'dict-urls.php',
		);
		$selector_columns = array( 'selector_id' );

		if ( ! empty( $filter_conditions ) && is_array( $filter_conditions ) ) {
			$resolved = $this->resolve_columndb_filter( $filter_conditions, $alias_map, $schema, $base_dir, $dict_files, $tracking_id, $selector_columns );
			if ( $resolved !== null ) {
				$scan_column       = $resolved['scan_column'];
				$match_ids         = $resolved['match_ids'];
				$remaining_filters = $resolved['remaining_filters'];
			} else {
				$remaining_filters = $filter_conditions;
			}
		}

		// 残余フィルタのカラムをkeep列に追加（フィルタ評価に必要）
		$extra_cols = array();
		if ( ! empty( $remaining_filters ) ) {
			$augmented = $this->augment_keep_for_filters( $physical_keep, $remaining_filters, $alias_map, $schema );
			$physical_keep = $augmented['keep'];
			$extra_cols    = $augmented['extra_cols'];
		}

		$result = $this->scan_and_extract( $base_dir, 'click_event', $dates, $scan_column, $match_ids, $physical_keep, $schema );

		if ( $count_only && empty( $remaining_filters ) ) {
			return array(
				'record_count' => 1,
				'data'         => array( array( 'count' => $result['record_count'] ) ),
			);
		}

		// Phase 4: 辞書引き（7辞書）
		$file_dict_map = array(
			'element_text_id'  => array( 'material_col' => 'element_text', 'file' => 'dict-element-texts.php' ),
			'element_id_id'    => array( 'material_col' => 'element_id',   'file' => 'dict-element-ids.php' ),
			'element_class_id' => array( 'material_col' => 'element_class', 'file' => 'dict-element-classes.php' ),
			'element_data_id'  => array( 'material_col' => 'element_data', 'file' => 'dict-element-data-attrs.php' ),
			'to_url_id'        => array( 'material_col' => 'to_url',      'file' => 'dict-urls.php' ),
		);

		$dict_map = array();

		// selector は QAHM_ColumnDB_Selectors（グローバル辞書）
		// T68: tracking_id='all' の selectors 辞書は report/all/columns-db/click_event/ 配下に統一配置
		if ( in_array( 'selector_id', $physical_keep, true ) ) {
			if ( $tracking_id === 'all' ) {
				$selectors = new QAHM_ColumnDB_Selectors( 'all', $base_dir );
			} else {
				$selectors = new QAHM_ColumnDB_Selectors( $tracking_id );
			}
			$dict_map['selector_id'] = array(
				'material_col' => 'selector',
				'dict'         => $selectors,
			);
		}

		// ファイル辞書
		foreach ( $file_dict_map as $physical_col => $info ) {
			if ( in_array( $physical_col, $physical_keep, true ) ) {
				$dict_map[ $physical_col ] = array(
					'material_col' => $info['material_col'],
					'dict'         => new QAHM_ColumnDB_Dictionary( $base_dir . $info['file'] ),
				);
			}
		}

		if ( ! empty( $dict_map ) ) {
			$result['data'] = $this->decode_dict_columns( $result['data'], $dict_map );
			foreach ( $dict_map as $info ) {
				$info['dict']->close();
			}
		}

		// T25b: 残余フィルタの適用 → 追加カラムの除去
		if ( ! empty( $remaining_filters ) ) {
			$result['data'] = $this->apply_post_filters( $result['data'], $remaining_filters );
			$result['data'] = $this->strip_extra_columns( $result['data'], $extra_cols );
			$result['record_count'] = count( $result['data'] );
		}

		if ( $count_only ) {
			return array(
				'record_count' => 1,
				'data'         => array( array( 'count' => $result['record_count'] ) ),
			);
		}

		return $result;
	}

	/**
	 * allpv マテリアルの列DBからのデータ取得
	 *
	 * 列DB固有の16カラムのみ対応。マスター参照カラムが含まれる場合はfile_functionsにフォールバック。
	 * T25b: フィルタ条件が列DBカラムのみの場合は列DBスキャンを使用。
	 *       マスター参照カラム（url, utm_source等）のフィルタがある場合はフォールバック。
	 *
	 * @param string     $tracking_id      トラッキングID
	 * @param array      $time_range       時間範囲
	 * @param array      $physical_columns 物理カラム名の配列
	 * @param array|null $filter_conditions フィルタ条件
	 * @param bool       $count_only       カウントのみ
	 * @return array|null 結果。nullの場合はfile_functionsフォールバックが必要
	 */
	private function fetch_allpv_data_from_columndb( $tracking_id, $time_range, $physical_columns, $filter_conditions, $count_only ) {
		$base_dir = $this->get_columndb_base_dir( $tracking_id, 'allpv' );
		if ( ! is_dir( $base_dir ) ) {
			return null; // フォールバック
		}

		// 列DB固有カラム（SCHEMA_ALLPV の正規定義から自動生成、二重定義回避）
		$columndb_schema = array();
		foreach ( QAHM_ColumnDB_Schema::SCHEMA_ALLPV as $col_name => $def ) {
			$columndb_schema[ $col_name ] = $def['type'];
		}

		// マスター参照カラムが含まれている場合はフォールバック
		foreach ( $physical_columns as $col ) {
			if ( ! isset( $columndb_schema[ $col ] ) ) {
				return null; // file_functionsフォールバック
			}
		}

		// T25b: フィルタ条件にマスター参照カラムが含まれるかチェック
		if ( ! empty( $filter_conditions ) && is_array( $filter_conditions ) ) {
			foreach ( $filter_conditions as $filter_col => $condition ) {
				if ( ! isset( $columndb_schema[ $filter_col ] ) ) {
					// マスター参照カラム（url, utm_source等）のフィルタ → file_functionsフォールバック
					return null;
				}
			}
		}

		$dates = $this->get_date_range_list( $time_range );

		// T25b: フィルタ条件を scan_column + match_ids に変換
		$scan_column       = 'pv_id';
		$match_ids         = null;
		$remaining_filters = array();

		// allpvは辞書カラムを持たない（直接ID比較 or 数値範囲のみ）
		if ( ! empty( $filter_conditions ) && is_array( $filter_conditions ) ) {
			$resolved = $this->resolve_columndb_filter( $filter_conditions, array(), $columndb_schema, $base_dir );
			if ( $resolved !== null ) {
				$scan_column       = $resolved['scan_column'];
				$match_ids         = $resolved['match_ids'];
				$remaining_filters = $resolved['remaining_filters'];
			} else {
				$remaining_filters = $filter_conditions;
			}
		}

		// 残余フィルタのカラムをkeep列に追加（フィルタ評価に必要）
		$extra_cols = array();
		if ( ! empty( $remaining_filters ) ) {
			$augmented = $this->augment_keep_for_filters( $physical_columns, $remaining_filters, array(), $columndb_schema );
			$physical_columns = $augmented['keep'];
			$extra_cols       = $augmented['extra_cols'];
		}

		$result = $this->scan_and_extract( $base_dir, 'allpv', $dates, $scan_column, $match_ids, $physical_columns, $columndb_schema );

		// T25b: 残余フィルタの適用 → 追加カラムの除去
		if ( ! empty( $remaining_filters ) ) {
			$result['data'] = $this->apply_post_filters( $result['data'], $remaining_filters );
			$result['data'] = $this->strip_extra_columns( $result['data'], $extra_cols );
			$result['record_count'] = count( $result['data'] );
		}

		if ( $count_only ) {
			return array(
				'record_count' => 1,
				'data'         => array( array( 'count' => $result['record_count'] ) ),
			);
		}

		return $result;
	}

	/**
	 * イベント名をディレクトリ名にサニタイズ
	 *
	 * @param string $event_name イベント名
	 * @return string ディレクトリ名
	 */
	private function sanitize_event_dir( $event_name ) {
		if ( $event_name === '' ) {
			return '_empty_';
		}
		$name = str_replace(
			array( '/', "\0", '\\', ':', '*', '?', '"', '<', '>', '|' ),
			'_',
			$event_name
		);
		if ( $name === '.' || $name === '..' ) {
			return '_' . $name . '_';
		}
		return $name;
	}

	// =======================================================================
	// 既存メソッド
	// =======================================================================

	/**
	 * Fetch filtered data from storage layer
	 * 
	 * Retrieves data from the appropriate storage backend (files or database)
	 * based on material definition. Returns physical column data without any decoding.
	 * 
	 * For 2025-10-20 version:
	 * - filter_conditions supports per-material field filtering (allpv: utm_source, utm_medium, utm_campaign, device_type, country_code, url)
	 * - Supports indexed array format (IN clause) and operator object format (eq, prefix, contains, etc.)
	 * - Only time range filtering is applied for time (start and end dates)
	 * - The 'tz' parameter in time_range is currently ignored
	 * - Supported materials: allpv, gsc, goal_x (where x is the goal number)
	 *
	 * @param string $tracking_id Tracking ID
	 * @param string $material_name Material name (e.g., 'allpv', 'gsc')
	 * @param array $time_range Time range configuration with 'start', 'end', 'tz' (Note: 'tz' is currently ignored in 2025-10-20 version)
	 * @param array|null $filter_conditions Filter conditions (currently unused/reserved for future use; not supported in 2025-10-20)
	 * @param array $physical_columns Physical column names to retrieve
	 * @param bool $count_only Whether to return only the count (enables optimization for allpv material)
	 * @return array Result with record_count and data, or error array
	 */
	public function fetch_filtered_data( $tracking_id, $material_name, $time_range, $filter_conditions, $physical_columns, $count_only = false ) {
		// filter_conditions is passed to material-specific fetch methods for field-level filtering.
		global $qahm_file_functions;

		if ( ! is_object( $qahm_file_functions ) ) {
			return array(
				'error_code' => 'E_DEPENDENCY_NOT_AVAILABLE',
				'message' => __( 'Required file functions dependency is not available.', 'qa-heatmap-analytics' ),
				'location' => 'storage'
			);
		}

		if ( empty( $tracking_id ) || ! is_string( $tracking_id ) ) {
			return array(
				'error_code' => 'E_INVALID_TRACKING_ID',
				'message' => __( 'Invalid tracking_id provided.', 'qa-heatmap-analytics' ),
				'location' => 'storage'
			);
		}

		if ( ! is_array( $time_range ) || ! isset( $time_range['start'] ) || ! isset( $time_range['end'] ) ) {
			return array(
				'error_code' => 'E_INVALID_TIME_RANGE',
				'message' => __( 'Invalid time_range provided. Must contain start and end.', 'qa-heatmap-analytics' ),
				'location' => 'storage'
			);
		}

		switch ( $material_name ) {
			case 'allpv':
				return $this->fetch_allpv_data( $tracking_id, $time_range, $physical_columns, $filter_conditions, $count_only );

			case 'gsc':
				return $this->fetch_gsc_data( $tracking_id, $time_range, $physical_columns, $filter_conditions );

			case 'ga4_age_gender':
				return $this->fetch_ga4_data( $tracking_id, $time_range, $physical_columns, 'ga4_age_gender', $filter_conditions );

			case 'ga4_country':
				return $this->fetch_ga4_data( $tracking_id, $time_range, $physical_columns, 'ga4_country', $filter_conditions );

			case 'ga4_region':
				return $this->fetch_ga4_data( $tracking_id, $time_range, $physical_columns, 'ga4_region', $filter_conditions );

			case 'click_event':
				return $this->fetch_click_event_data( $tracking_id, $material_name, $time_range, $filter_conditions, $physical_columns, $count_only );

			case 'datalayer_event':
				return $this->fetch_datalayer_event_data( $tracking_id, $material_name, $time_range, $filter_conditions, $physical_columns, $count_only );

			case 'page_version':
				return $this->fetch_page_version_data( $tracking_id, $time_range, $physical_columns, $filter_conditions );

			case 'summary_landingpage':
				return $this->fetch_summary_landingpage_data( $tracking_id, $time_range, $physical_columns, $filter_conditions );

			case 'summary_allpage':
				return $this->fetch_summary_allpage_data( $tracking_id, $time_range, $physical_columns, $filter_conditions );

			case 'summary_days_access_detail':
				return $this->fetch_summary_days_access_detail_data( $tracking_id, $time_range, $physical_columns, $filter_conditions );

			default:
				// Check for events.{name} pattern (Layer 2 event detail tables)
				if ( strpos( $material_name, 'events.' ) === 0 ) {
					return $this->fetch_event_detail_data( $tracking_id, $material_name, $time_range, $filter_conditions, $physical_columns, $count_only );
				}

				// Check for goal_x pattern (e.g., goal_1, goal_2, etc.) - goal numbers start from 1
				if ( strpos( $material_name, 'goal_' ) === 0 && strlen( $material_name ) > 5 && ctype_digit( substr( $material_name, 5 ) ) && substr( $material_name, 5, 1 ) !== '0' ) {
					$goal_number = (int) substr( $material_name, 5 );
					return $this->fetch_goal_data( $tracking_id, $time_range, $physical_columns, $goal_number, $filter_conditions );
				}

				return array(
					'error_code' => 'E_MATERIAL_NOT_SUPPORTED',
					/* translators: %s: material name */
					'message' => sprintf( __( "Material '%s' is not supported in version 2025-10-20", 'qa-heatmap-analytics' ), $material_name ),
					'location' => 'storage'
				);
		}
	}

	/**
	 * Fetch joined data from storage layer
	 *
	 * Retrieves data for join operations using scan_and_extract with match_ids.
	 * Only integer key columns (pv_id, session_id, page_id) are supported as join keys.
	 * Returns physical column data (IDs, not decoded values). Decoding is Material layer's responsibility.
	 *
	 * @param string $tracking_id   Tracking ID
	 * @param string $material_name Right material name
	 * @param array  $time_range    Time range with 'start', 'end', 'tz'
	 * @param string $scan_column   Physical column name to scan (join key)
	 * @param array  $match_ids     Array of integer IDs to match
	 * @param array  $physical_columns Physical column names to retrieve (keep columns for right material)
	 * @return array ['record_count' => int, 'data' => array] or error array
	 */
	public function fetch_joined_data( $tracking_id, $material_name, $time_range, $scan_column, $match_ids, $physical_columns ) {
		if ( empty( $tracking_id ) || ! is_string( $tracking_id ) ) {
			return array(
				'error_code' => 'E_INVALID_TRACKING_ID',
				'message' => __( 'Invalid tracking_id provided.', 'qa-heatmap-analytics' ),
				'location' => 'storage'
			);
		}

		if ( ! is_array( $match_ids ) || empty( $match_ids ) ) {
			return array(
				'record_count' => 0,
				'data' => array(),
			);
		}

		// page_version: DB直接クエリで早期分岐（列DBではないため）
		if ( 'page_version' === $material_name ) {
			$int_ids = $this->wrap_array_map( 'intval', $match_ids );
			$limit   = $this->wrap_count( $int_ids ) * 10; // 1 page_id/device_id に複数version行ありうる

			// $scan_column に応じて正しいパラメータにルーティング
			$version_id_arg = null;
			$page_id_arg    = null;
			$device_id_arg  = null;

			switch ( $scan_column ) {
				case 'version_id':
					$version_id_arg = $int_ids;
					$limit = $this->wrap_count( $int_ids );
					break;
				case 'page_id':
					$page_id_arg = $int_ids;
					break;
				case 'device_id':
					$device_id_arg = $int_ids;
					break;
				default:
					return array(
						'error_code' => 'E_JOIN_COLUMN_NOT_FOUND',
						'message'    => sprintf( "Join scan column '%s' not found in page_version material", $scan_column ),
						'location'   => 'storage',
					);
			}

			$rows = QAHM_DB_Functions::get_qa_page_version_hist( $version_id_arg, $page_id_arg, $device_id_arg, null, null, false, $limit );
			if ( ! is_array( $rows ) ) {
				$rows = array();
			}
			// DB余剰カラム（insert_datetime等）を除去し、物理カラムのみ返す
			$allowed_join = array( 'version_id', 'page_id', 'device_id', 'version_no', 'update_date' );
			$cleaned = array();
			foreach ( $rows as $row ) {
				$clean_row = array();
				foreach ( $allowed_join as $col ) {
					if ( isset( $row[ $col ] ) ) {
						$clean_row[ $col ] = $row[ $col ];
					}
				}
				$cleaned[] = $clean_row;
			}
			return array(
				'record_count' => $this->wrap_count( $cleaned ),
				'data'         => $cleaned,
			);
		}

		$material_config = $this->get_join_material_config( $material_name, $tracking_id );
		if ( isset( $material_config['error_code'] ) ) {
			return $material_config;
		}

		$schema = $material_config['schema'];
		$base_dir = $material_config['base_dir'];
		$dataset_prefix = $material_config['dataset_prefix'];
		$alias_map = $material_config['alias_map'];

		if ( ! is_dir( $base_dir ) ) {
			return array(
				'error_code' => 'E_DATA_SOURCE_NOT_FOUND',
				'message'    => sprintf( "ColumnDB %s directory not found for tracking_id '%s'", $material_name, $tracking_id ),
				'location'   => 'storage',
			);
		}

		// Validate scan_column exists in schema
		if ( ! isset( $schema[ $scan_column ] ) ) {
			return array(
				'error_code' => 'E_JOIN_COLUMN_NOT_FOUND',
				'message' => sprintf( "Join scan column '%s' not found in schema for material '%s'", $scan_column, $material_name ),
				'location' => 'storage'
			);
		}

		// Resolve physical keep columns (material column names to physical column names)
		$physical_keep = $this->resolve_physical_keep( $physical_columns, $alias_map );

		// Ensure scan_column is in keep so it appears in output for hash-map merge
		if ( ! in_array( $scan_column, $physical_keep, true ) ) {
			$physical_keep[] = $scan_column;
		}

		$dates = $this->get_date_range_list( $time_range );

		$result = $this->scan_and_extract( $base_dir, $dataset_prefix, $dates, $scan_column, $match_ids, $physical_keep, $schema );

		// Dictionary decoding for right material
		if ( isset( $material_config['dict_config'] ) ) {
			$dict_map = array();
			foreach ( $material_config['dict_config'] as $physical_col => $info ) {
				if ( ! in_array( $physical_col, $physical_keep, true ) ) {
					continue;
				}
				if ( isset( $info['selector'] ) && $info['selector'] ) {
					$dict_map[ $physical_col ] = array(
						'material_col' => $info['material_col'],
						'dict'         => new QAHM_ColumnDB_Selectors( $tracking_id ),
					);
				} elseif ( isset( $info['file'] ) ) {
					$dict_path = $base_dir . $info['file'];
					if ( file_exists( $dict_path ) ) {
						$dict_map[ $physical_col ] = array(
							'material_col' => $info['material_col'],
							'dict'         => new QAHM_ColumnDB_Dictionary( $dict_path ),
						);
					}
				} elseif ( isset( $info['source'] ) && 'db' === $info['source'] ) {
					// DB dict: query_id → keyword inline resolution
					$unique_ids = array();
					foreach ( $result['data'] as $row ) {
						if ( isset( $row[ $physical_col ] ) ) {
							$unique_ids[ (int) $row[ $physical_col ] ] = true;
						}
					}
					$unique_ids = array_keys( $unique_ids );

					if ( ! empty( $unique_ids ) ) {
						$db_rows = QAHM_DB_Functions::get_gsc_query_logs( $tracking_id, $unique_ids, null, null, PHP_INT_MAX );
						$id_to_keyword = array();
						if ( is_array( $db_rows ) ) {
							foreach ( $db_rows as $db_row ) {
								$id_to_keyword[ (int) $db_row['query_id'] ] = $db_row['keyword'];
							}
						}
						$material_col = $info['material_col'];
						foreach ( $result['data'] as &$rrow ) {
							if ( isset( $rrow[ $physical_col ] ) ) {
								$id = (int) $rrow[ $physical_col ];
								$rrow[ $material_col ] = isset( $id_to_keyword[ $id ] ) ? $id_to_keyword[ $id ] : '';
								unset( $rrow[ $physical_col ] );
							}
						}
						unset( $rrow );
					}
				}
			}

			if ( ! empty( $dict_map ) ) {
				$result['data'] = $this->decode_dict_columns( $result['data'], $dict_map );
				foreach ( $dict_map as $info ) {
					$info['dict']->close();
				}
			}
		}

		return $result;
	}

	/**
	 * SCHEMA_* 定数から join 用の型マップを構築する（T113 #1477・型定義の一元管理）
	 *
	 * 型の正本は QAHM_ColumnDB_Schema の SCHEMA_* 定数。ここに型リテラルを複製すると
	 * スキーマ変更時に旧幅で読むミスアラインが起きる（T113 で実際に踏んだ轍）ため、
	 * 型は必ず SCHEMA_* から引く。**列の選抜リスト（whitelist）自体は手動維持が仕様**
	 * （join に載せる列の選定は意図的な設計判断＝全列を機械的に載せない）。
	 *
	 * @param int   $dataset_id データセットID（QAHM_ColumnDB_Schema::DATASET_*）
	 * @param array $columns 選抜カラム名の配列
	 * @return array ['col' => 'type', ...]
	 */
	private function build_join_schema( $dataset_id, $columns ) {
		$schema = QAHM_ColumnDB_Schema::get_schema( $dataset_id );
		$result = array();
		foreach ( $columns as $col ) {
			if ( isset( $schema[ $col ]['type'] ) ) {
				$result[ $col ] = $schema[ $col ]['type'];
			}
		}
		return $result;
	}

	/**
	 * Get material configuration for join operations
	 *
	 * Returns schema, base_dir, dataset_prefix, alias_map, and dict_config for each supported material.
	 *
	 * @param string $material_name Material name
	 * @param string $tracking_id Tracking ID
	 * @return array Configuration array or error array
	 */
	private function get_join_material_config( $material_name, $tracking_id ) {
		switch ( $material_name ) {
			case 'allpv':
				return array(
					'base_dir'       => $this->get_columndb_base_dir( $tracking_id, 'allpv' ),
					'dataset_prefix' => 'allpv',
					'alias_map'      => array(),
					// 型は SCHEMA_ALLPV から引く（T113 #1477・列の選抜は手動維持）
					'schema'         => $this->build_join_schema( QAHM_ColumnDB_Schema::DATASET_ALLPV, array(
						'pv_id', 'session_id', 'reader_id', 'page_id', 'device_id',
						'source_id', 'medium_id', 'campaign_id', 'content_id',
						'access_time', 'pv', 'speed_msec', 'browse_sec',
						'is_last', 'is_newuser', 'version_id',
					) ),
				);

			case 'click_event':
				return array(
					'base_dir'       => $this->get_columndb_base_dir( $tracking_id, 'click_event' ),
					'dataset_prefix' => 'click_event',
					'alias_map'      => array(
						'selector'      => 'selector_id',
						'element_text'  => 'element_text_id',
						'element_id'    => 'element_id_id',
						'element_class' => 'element_class_id',
						'element_data'  => 'element_data_id',
						'to_url'        => 'to_url_id',
					),
					// 型は SCHEMA_CLICK_EVENT から引く（T113 #1477・列の選抜は手動維持）
					'schema'         => $this->build_join_schema( QAHM_ColumnDB_Schema::DATASET_CLICK_EVENT, array(
						'pv_id', 'session_id', 'page_id', 'event_sec', 'selector_id',
						'element_text_id', 'element_id_id', 'element_class_id',
						'element_data_id', 'to_url_id',
						'is_external', 'action_id', 'page_x_pct', 'page_y_pct',
					) ),
					'dict_config'    => array(
						'selector_id'      => array( 'material_col' => 'selector',      'selector' => true ),
						'element_text_id'  => array( 'material_col' => 'element_text',  'file' => 'dict-element-texts.php' ),
						'element_id_id'    => array( 'material_col' => 'element_id',    'file' => 'dict-element-ids.php' ),
						'element_class_id' => array( 'material_col' => 'element_class', 'file' => 'dict-element-classes.php' ),
						'element_data_id'  => array( 'material_col' => 'element_data',  'file' => 'dict-element-data-attrs.php' ),
						'to_url_id'        => array( 'material_col' => 'to_url',        'file' => 'dict-urls.php' ),
					),
				);

			case 'datalayer_event':
				return array(
					'base_dir'       => $this->get_columndb_base_dir( $tracking_id, 'datalayer_event' ),
					'dataset_prefix' => 'datalayer_event',
					'alias_map'      => array(
						'event_name'  => 'event_name_id',
						'params_json' => 'params_id',
					),
					// 型は SCHEMA_DATALAYER_EVENT から引く（T113 #1477・列の選抜は手動維持）
					'schema'         => $this->build_join_schema( QAHM_ColumnDB_Schema::DATASET_DATALAYER_EVENT, array(
						'pv_id', 'session_id', 'page_id', 'event_name_id', 'params_id',
					) ),
					'dict_config'    => array(
						'event_name_id' => array( 'material_col' => 'event_name',  'file' => 'dict-event-names.php' ),
						'params_id'     => array( 'material_col' => 'params_json', 'file' => 'dict-params-json.php' ),
					),
				);

			case 'gsc':
				return array(
					'base_dir'       => $this->get_columndb_base_dir( $tracking_id, 'gsc' ),
					'dataset_prefix' => 'gsc',
					'alias_map'      => array(
						'keyword' => 'query_id',
					),
					'schema'         => $this->get_gsc_schema(),
					'dict_config'    => array(
						'query_id' => array( 'material_col' => 'keyword', 'source' => 'db' ),
					),
				);

			default:
				return array(
					'error_code' => 'E_MATERIAL_NOT_SUPPORTED',
					'message' => sprintf( "Material '%s' is not supported for join operations", $material_name ),
					'location' => 'storage'
				);
		}
	}

	/**
	 * Get GSC column DB schema definition
	 *
	 * Shared between fetch_gsc_data() and get_join_material_config().
	 *
	 * @return array Column name => type mapping
	 */
	private function get_gsc_schema() {
		return array(
			'page_id'       => 'uint32',
			'query_id'      => 'uint32',
			'search_type'   => 'uint8',
			'clicks'        => 'uint32',
			'impressions'   => 'uint32',
			'position_x100' => 'uint16',
		);
	}

	/**
	 * Filter columns from raw data records
	 *
	 * Extracts only the specified physical columns from raw data records.
	 * If no columns are specified, returns all records unchanged.
	 * Missing columns are set to null.
	 *
	 * @param array $raw_data Raw data records from storage
	 * @param array $physical_columns Physical column names to retrieve
	 * @return array Filtered data records
	 */
	private function filter_columns_from_records( $raw_data, $physical_columns ) {
		$filtered_data = array();
		foreach ( $raw_data as $record ) {
			if ( empty( $physical_columns ) ) {
				$filtered_data[] = $record;
			} else {
				$filtered_record = array();
				foreach ( $physical_columns as $column ) {
					if ( isset( $record[ $column ] ) ) {
						$filtered_record[ $column ] = $record[ $column ];
					} else {
						$filtered_record[ $column ] = null;
					}
				}
				$filtered_data[] = $filtered_record;
			}
		}
		return $filtered_data;
	}

	/**
	 * Fetch data for 'allpv' material
	 * 
	 * Retrieves pageview data from view_pv files using QAHM_File_Functions::get_view_pv().
	 * Supports filtering by: utm_source, utm_medium, utm_campaign, device_type, country_code
	 *
	 * @param string $tracking_id Tracking ID
	 * @param array $time_range Time range configuration
	 * @param array $physical_columns Physical column names to retrieve
	 * @param array|null $filter_conditions Filter conditions (e.g., ['utm_medium' => ['facebook', 'twitter']])
	 * @param bool $count_only Whether to return only the count (enables optimization using summary_days_access)
	 * @return array Result with record_count and data, or error array
	 */
	private function fetch_allpv_data( $tracking_id, $time_range, $physical_columns, $filter_conditions = null, $count_only = false ) {
		global $qahm_file_functions;

		// 列DBを試行（T37: count_onlyでも列DB経路を使用）
		$columndb_result = $this->fetch_allpv_data_from_columndb( $tracking_id, $time_range, $physical_columns, $filter_conditions, $count_only );
		if ( $columndb_result !== null ) {
			return $columndb_result;
		}

		// #1489: fail-loud — ここから先は view_pv フォールバック経路。この経路のフィルタ適用
		// （apply_allpv_filters）が解するのは「オペレータ形式（任意フィールド）」と「indexed-array 形式の
		// 下記5フィールド」だけで、それ以外（例: page_id => array(5)）は従来【黙って無視】され、呼び出し側は
		// 絞ったつもりの全行を受け取っていた（PR #1488 専属レビュー 🟡-1）。間違った結果を静かに返す
		// くらいならエラーで止まる（呼び出し側は既存の error_code 分岐で安全に落ちる／ヒートマップ #1466 は
		// null 経由で旧経路フォールバック）。
		if ( ! empty( $filter_conditions ) && is_array( $filter_conditions ) ) {
			// この whitelist は apply_allpv_filters の indexed-array 対応フィールドと 1:1（増減時は両方を揃える）。
			$fallback_indexed_fields = array( 'utm_medium', 'utm_source', 'utm_campaign', 'device_type', 'country_code' );
			foreach ( $filter_conditions as $filter_col => $condition ) {
				if ( is_array( $condition ) && $this->is_operator_filter( $condition ) ) {
					// オペレータ形式は match_operator_filter が任意フィールドで評価できるため通す。
					// 既知の限界: view_pv 行に存在しないフィールドへのオペレータは null 評価になる
					// （eq/gt/prefix/in は 0 行・neq は全行通過）＝ここでは検出しない。現行の実呼び出し元に
					// この形は存在しない（reverse_lookup は indexed を生成・ヒートマップは indexed page_id）。
					continue;
				}
				if ( ! in_array( $filter_col, $fallback_indexed_fields, true ) ) {
					return array(
						'error_code' => 'E_FILTER_NOT_SUPPORTED',
						/* translators: %s: filter field name */
						'message'    => sprintf( __( "Filter on field '%s' is not supported by the view_pv fallback path.", 'qa-heatmap-analytics' ), $filter_col ),
						'location'   => 'storage',
					);
				}
			}
		}

		// COUNT query optimization: use summary_days_access instead of view_pv
		// This optimization is only applied when:
		// 1. count_only is true
		// 2. No filter conditions are specified (filter_conditions is null or empty)
		if ( $count_only && empty( $filter_conditions ) ) {
			return $this->fetch_allpv_count_from_summary( $tracking_id, $time_range );
		}

		if ( ! method_exists( $qahm_file_functions, 'get_view_pv' ) ) {
			return array(
				'error_code' => 'E_METHOD_NOT_AVAILABLE',
				'message' => __( 'Required method get_view_pv is not available.', 'qa-heatmap-analytics' ),
				'location' => 'storage'
			);
		}

		$start_datetime = $time_range['start'];
		$end_datetime = $time_range['end'];

		$raw_data = $qahm_file_functions->get_view_pv( $tracking_id, $start_datetime, $end_datetime );

		if ( ! is_array( $raw_data ) ) {
			return array(
				'error_code' => 'E_DATA_SOURCE_NOT_FOUND',
				/* translators: %s: tracking ID */
				'message' => sprintf( __( "Data source not found for tracking_id '%s'", 'qa-heatmap-analytics' ), $tracking_id ),
				'location' => 'storage'
			);
		}

		// Apply filter conditions if provided
		if ( ! empty( $filter_conditions ) && is_array( $filter_conditions ) ) {
			$raw_data = $this->apply_allpv_filters( $raw_data, $filter_conditions );
		}

		$filtered_data = $this->filter_columns_from_records( $raw_data, $physical_columns );

		return array(
			'record_count' => $this->wrap_count( $filtered_data ),
			'data' => $filtered_data
		);
	}

	/**
	 * Fetch allpv count from summary_days_access
	 * 
	 * Optimized method for COUNT queries on allpv material.
	 * Instead of loading all view_pv data and counting records,
	 * this method uses the pre-aggregated summary_days_access data
	 * which contains daily PV counts.
	 * 
	 * This optimization significantly reduces memory usage and improves
	 * performance for simple count queries without filters.
	 *
	 * @param string $tracking_id Tracking ID
	 * @param array $time_range Time range configuration with 'start' and 'end'
	 * @return array Result with record_count=1 and data containing total count, or error array
	 */
	private function fetch_allpv_count_from_summary( $tracking_id, $time_range ) {
		global $qahm_file_functions;

		if ( ! method_exists( $qahm_file_functions, 'get_summary_days_access' ) ) {
			return array(
				'error_code' => 'E_METHOD_NOT_AVAILABLE',
				'message' => __( 'Required method get_summary_days_access is not available.', 'qa-heatmap-analytics' ),
				'location' => 'storage'
			);
		}

		$start_date = isset( $time_range['start'] ) && is_string( $time_range['start'] ) 
			? $this->wrap_substr( $time_range['start'], 0, 10 ) 
			: '';
		$end_date = isset( $time_range['end'] ) && is_string( $time_range['end'] ) 
			? $this->wrap_substr( $time_range['end'], 0, 10 ) 
			: '';

		if ( $start_date === '' || $end_date === '' ) {
			return array(
				'error_code' => 'E_INVALID_TIME_RANGE',
				'message' => __( 'Invalid time_range provided. Start and end must be valid date strings.', 'qa-heatmap-analytics' ),
				'location' => 'storage'
			);
		}

		$summary_data = $qahm_file_functions->get_summary_days_access( $tracking_id, $start_date, $end_date );

		if ( ! is_array( $summary_data ) || empty( $summary_data ) ) {
			return array(
				'error_code' => 'E_DATA_SOURCE_NOT_FOUND',
				/* translators: %s: tracking ID */
				'message' => sprintf( __( 'Summary data not found or empty for tracking_id: %s', 'qa-heatmap-analytics' ), $tracking_id ),
				'location' => 'storage'
			);
		}

		$total_count = 0;
		foreach ( $summary_data as $day_data ) {
			$total_count += isset( $day_data['pv_count'] ) ? (int) $day_data['pv_count'] : 0;
		}

		return array(
			'record_count' => 1,
			'data' => array( array( 'count' => $total_count ) )
		);
	}

	/**
	 * Fetch data for 'gsc' material
	 * 
	 * Retrieves Google Search Console data using QAHM_File_Functions::get_gsc_lp_query().
	 * Supports filtering by: search_type, keyword
	 *
	 * @param string $tracking_id Tracking ID
	 * @param array $time_range Time range configuration
	 * @param array $physical_columns Physical column names to retrieve
	 * @param array|null $filter_conditions Filter conditions (e.g., ['search_type' => ['web']])
	 * @return array Result with record_count and data, or error array
	 */
	private function fetch_gsc_data( $tracking_id, $time_range, $physical_columns, $filter_conditions = null ) {
		$base_dir = $this->get_columndb_base_dir( $tracking_id, 'gsc' );

		// 列DBスキーマ
		$columndb_schema = $this->get_gsc_schema();

		$dates = $this->get_date_range_list( $time_range );

		// フィルタ条件をscan_column + match_idsに変換
		$scan_column       = 'page_id';
		$match_ids         = null;
		$remaining_filters = array();

		if ( ! empty( $filter_conditions ) && is_array( $filter_conditions ) ) {
			// keyword フィルタ: DB検索 → query_id集合に変換
			if ( isset( $filter_conditions['keyword'] ) ) {
				$keyword_ids = $this->resolve_keyword_to_query_ids( $tracking_id, $filter_conditions['keyword'] );
				if ( $keyword_ids !== null ) {
					// keyword → query_id に変換成功
					// 空配列（ゼロマッチ）の場合、sentinel array(-1) で確実にゼロ結果を保証
					if ( empty( $keyword_ids ) ) {
						$keyword_ids = array( -1 );
					}
					$filter_conditions['query_id'] = $keyword_ids;
					unset( $filter_conditions['keyword'] );
				} else {
					// 未対応オペレータ（neq, gt, lt, between等）: サイレントに全行除外されるのを防止
					return array(
						'error_code' => 'E_UNSUPPORTED_KEYWORD_OPERATOR',
						'message'    => 'The keyword filter operator is not supported for GSC. Supported: eq, in, contains, prefix.',
						'location'   => 'storage.gsc.filter.keyword',
					);
				}
			}

			$resolved = $this->resolve_columndb_filter( $filter_conditions, array(), $columndb_schema, $base_dir );
			if ( $resolved !== null ) {
				$scan_column       = $resolved['scan_column'];
				$match_ids         = $resolved['match_ids'];
				$remaining_filters = $resolved['remaining_filters'];
			} else {
				$remaining_filters = $filter_conditions;
			}
		}

		// 残余フィルタのカラムをkeep列に追加
		$extra_cols = array();
		if ( ! empty( $remaining_filters ) ) {
			$augmented = $this->augment_keep_for_filters( $physical_columns, $remaining_filters, array(), $columndb_schema );
			$physical_columns = $augmented['keep'];
			$extra_cols       = $augmented['extra_cols'];
		}

		// 列DBからデータ取得（物理カラムのみ）
		$db_columns = array();
		foreach ( $physical_columns as $col ) {
			if ( isset( $columndb_schema[ $col ] ) ) {
				$db_columns[] = $col;
			}
		}

		// T133: gsc の行は日付を持たないため、Material 層が month を算出できるよう
		// 日別ファイルの日付を要求された時だけ注入する（要求が無ければ従来どおり）
		$emit_date_column = in_array( self::DATE_YMD_COLUMN, $physical_columns, true )
			? self::DATE_YMD_COLUMN
			: null;

		$result = $this->scan_and_extract( $base_dir, 'gsc', $dates, $scan_column, $match_ids, $db_columns, $columndb_schema, $emit_date_column );

		// 残余フィルタの適用 → 追加カラムの除去
		if ( ! empty( $remaining_filters ) ) {
			$result['data'] = $this->apply_post_filters( $result['data'], $remaining_filters );
			$result['data'] = $this->strip_extra_columns( $result['data'], $extra_cols );
			$result['record_count'] = count( $result['data'] );
		}

		return $result;
	}

	/**
	 * Fetch data for GA4 materials (ga4_age_gender / ga4_country / ga4_region)
	 *
	 * GA4 data is stored monthly. Converts time_range to month list and
	 * reads from column DB using scan_and_extract.
	 *
	 * @param string $tracking_id Tracking ID
	 * @param array $time_range Time range configuration
	 * @param array $physical_columns Physical column names to retrieve
	 * @param string $dataset_name Dataset name (ga4_age_gender, ga4_country, ga4_region)
	 * @param array|null $filter_conditions Filter conditions
	 * @return array Result with record_count and data, or error array
	 */
	private function fetch_ga4_data( $tracking_id, $time_range, $physical_columns, $dataset_name, $filter_conditions = null ) {
		$base_dir = $this->get_columndb_base_dir( $tracking_id, $dataset_name );
		$schema   = $this->get_ga4_schema( $dataset_name );

		// 月次リスト生成（日次ではなく月次）
		$months = $this->get_month_range_list( $time_range );

		// フィルタ解決
		$scan_column       = null;
		$match_ids         = null;
		$remaining_filters = array();

		if ( ! empty( $filter_conditions ) && is_array( $filter_conditions ) ) {
			$resolved = $this->resolve_columndb_filter( $filter_conditions, array(), $schema, $base_dir );
			if ( $resolved !== null ) {
				$scan_column       = $resolved['scan_column'];
				$match_ids         = $resolved['match_ids'];
				$remaining_filters = $resolved['remaining_filters'];
			} else {
				$remaining_filters = $filter_conditions;
			}
		}

		// scan_column未指定時はスキーマの最初のカラム
		if ( $scan_column === null ) {
			$schema_keys = array_keys( $schema );
			$scan_column = $schema_keys[0];
		}

		// 残余フィルタのカラムをkeep列に追加
		$extra_cols = array();
		if ( ! empty( $remaining_filters ) ) {
			$augmented = $this->augment_keep_for_filters( $physical_columns, $remaining_filters, array(), $schema );
			$physical_columns = $augmented['keep'];
			$extra_cols       = $augmented['extra_cols'];
		}

		// 列DBからデータ取得
		$db_columns = array();
		foreach ( $physical_columns as $col ) {
			if ( isset( $schema[ $col ] ) ) {
				$db_columns[] = $col;
			}
		}

		$result = $this->scan_and_extract( $base_dir, $dataset_name, $months, $scan_column, $match_ids, $db_columns, $schema );

		// 残余フィルタの適用
		if ( ! empty( $remaining_filters ) ) {
			$result['data'] = $this->apply_post_filters( $result['data'], $remaining_filters );
			$result['data'] = $this->strip_extra_columns( $result['data'], $extra_cols );
			$result['record_count'] = count( $result['data'] );
		}

		return $result;
	}

	/**
	 * Get GA4 column DB schema definition
	 *
	 * @param string $dataset_name Dataset name
	 * @return array Column name => type mapping
	 */
	private function get_ga4_schema( $dataset_name ) {
		switch ( $dataset_name ) {
			case 'ga4_age_gender':
				return array(
					'age_bracket'  => 'uint8',
					'gender'       => 'uint8',
					'sessions'     => 'uint32',
					'active_users' => 'uint32',
				);
			case 'ga4_country':
				return array(
					'country_id'   => 'uint16',
					'sessions'     => 'uint32',
					'active_users' => 'uint32',
				);
			case 'ga4_region':
				return array(
					'region_id'    => 'uint16',
					'sessions'     => 'uint32',
					'active_users' => 'uint32',
				);
			default:
				return array();
		}
	}

	/**
	 * Get month range list from time_range
	 *
	 * Converts a time range to a list of YYYYMM strings (monthly granularity).
	 * Used for GA4 data which is stored monthly instead of daily.
	 *
	 * @param array $time_range Time range configuration with 'start', 'end', 'tz'
	 * @return array List of YYYYMM strings
	 */
	private function get_month_range_list( $time_range ) {
		$tz_string = isset( $time_range['tz'] ) ? $time_range['tz'] : 'Asia/Tokyo';
		try {
			$tz = new DateTimeZone( $tz_string );
		} catch ( Exception $e ) {
			$tz = new DateTimeZone( 'Asia/Tokyo' );
		}

		$start = new DateTime( $time_range['start'], $tz );
		$end   = new DateTime( $time_range['end'], $tz );

		$months = array();
		$current = clone $start;
		$current->modify( 'first day of this month' );

		while ( $current <= $end ) {
			$months[] = $current->format( 'Ym' );
			$current->modify( '+1 month' );
		}

		return array_unique( $months );
	}

	/**
	 * Fetch data for 'goal_x' material
	 *
	 * Retrieves goal achievement session data using QAHM_File_Functions::get_goal_data_by_number().
	 * The goal data is structured as sessions containing multiple PV records.
	 * This method flattens the session structure to return individual PV records.
	 * Supports filtering by: utm_source, utm_medium, utm_campaign, device_id, is_reject
	 *
	 * @param string $tracking_id Tracking ID
	 * @param array $time_range Time range configuration
	 * @param array $physical_columns Physical column names to retrieve
	 * @param int $goal_number Goal number to retrieve data for
	 * @param array|null $filter_conditions Filter conditions (e.g., ['is_reject' => [false]])
	 * @return array Result with record_count and data, or error array
	 */
	private function fetch_goal_data( $tracking_id, $time_range, $physical_columns, $goal_number, $filter_conditions = null ) {
		global $qahm_file_functions;

		if ( ! method_exists( $qahm_file_functions, 'get_goal_data_by_number' ) ) {
			return array(
				'error_code' => 'E_METHOD_NOT_AVAILABLE',
				'message' => __( 'Required method get_goal_data_by_number is not available.', 'qa-heatmap-analytics' ),
				'location' => 'storage'
			);
		}

		$start_date = isset( $time_range['start'] ) && is_string( $time_range['start'] ) 
			? $this->wrap_substr( $time_range['start'], 0, 10 ) 
			: '';
		$end_date = isset( $time_range['end'] ) && is_string( $time_range['end'] ) 
			? $this->wrap_substr( $time_range['end'], 0, 10 ) 
			: '';

		if ( $start_date === '' || $end_date === '' ) {
			return array(
				'error_code' => 'E_INVALID_TIME_RANGE',
				'message' => __( 'Invalid time_range provided. Start and end must be valid date strings.', 'qa-heatmap-analytics' ),
				'location' => 'storage'
			);
		}

		// get_goal_data_by_number() always returns an array (empty array when no data found)
		$raw_data = $qahm_file_functions->get_goal_data_by_number( $tracking_id, $goal_number, $start_date, $end_date );

		// Flatten session data structure to individual PV records
		$flattened_data = array();
		foreach ( $raw_data as $session_index => $session_data ) {
			if ( is_array( $session_data ) ) {
				foreach ( $session_data as $pv_index => $pv_data ) {
					if ( is_array( $pv_data ) ) {
						// Add session and pv index for reference
						$pv_data['session_index'] = $session_index;
						$pv_data['pv_index'] = $pv_index;
						$flattened_data[] = $pv_data;
					}
				}
			}
		}

		// Apply filter conditions if provided
		if ( ! empty( $filter_conditions ) && is_array( $filter_conditions ) ) {
			$flattened_data = $this->apply_goal_filters( $flattened_data, $filter_conditions );
		}

		$filtered_data = $this->filter_columns_from_records( $flattened_data, $physical_columns );

		return array(
			'record_count' => $this->wrap_count( $filtered_data ),
			'data' => $filtered_data
		);
	}

	/**
	 * Build a 'date = between' dateterm string for summary_days_* DB functions
	 *
	 * Validates that both ends of $time_range are 'Y-m-d' dates (truncating any
	 * trailing time component first) and assembles the exact string expected by
	 * QAHM_DB::summary_days_landingpages / summary_days_allpages /
	 * summary_days_access_detail (see class-qahm-db.php — 'date = between
	 * YYYY-MM-DD and YYYY-MM-DD'). Date validation uses DateTime instead of a
	 * regular expression, per project policy.
	 *
	 * @param array $time_range Time range with 'start' and 'end'
	 * @return array ['dateterm' => string] on success, or an error array
	 */
	private function build_summary_dateterm( $time_range ) {
		$start_date = isset( $time_range['start'] ) && is_string( $time_range['start'] )
			? $this->wrap_substr( $time_range['start'], 0, 10 )
			: '';
		$end_date = isset( $time_range['end'] ) && is_string( $time_range['end'] )
			? $this->wrap_substr( $time_range['end'], 0, 10 )
			: '';

		$start_obj = DateTime::createFromFormat( 'Y-m-d', $start_date );
		$end_obj   = DateTime::createFromFormat( 'Y-m-d', $end_date );

		if ( false === $start_obj || false === $end_obj
			|| $start_obj->format( 'Y-m-d' ) !== $start_date
			|| $end_obj->format( 'Y-m-d' ) !== $end_date ) {
			return array(
				'error_code' => 'E_INVALID_TIME_RANGE',
				'message' => __( 'Invalid time_range provided. Start and end must be valid Y-m-d date strings.', 'qa-heatmap-analytics' ),
				'location' => 'storage'
			);
		}

		return array(
			'dateterm' => 'date = between ' . $start_date . ' and ' . $end_date,
		);
	}

	/**
	 * Apply filter conditions to summary material records
	 *
	 * Shared by the three summary fetch methods (summary_landingpage /
	 * summary_allpage / summary_days_access_detail). QAL filter evaluation is the
	 * Storage layer's responsibility for every material — the Executor only
	 * validates filter syntax and never filters data rows (same architecture as
	 * apply_allpv_filters / apply_goal_filters). Without this step a filter on a
	 * summary material would be silently ignored.
	 *
	 * Filtering runs on the records returned by the summary_days_* DB functions,
	 * before column trimming, so every dimension column is still present.
	 *
	 * Supported formats (per column, AND-combined across columns):
	 * - Indexed array (IN clause):  ['utm_source' => ['google', 'yahoo']]
	 * - Operator object:            ['pv_count' => ['gte' => 100]]
	 *
	 * Only columns in $filterable_columns are evaluated; any other filter key is
	 * ignored here (it does not belong to this material's dimensions). Note that
	 * utm_source / utm_medium / utm_campaign hold label strings (the summary_days_*
	 * functions restore ID->label internally and the manifest declares them
	 * without resolve_via), so filter values — passed through unchanged by the
	 * Material layer — are compared as label strings directly.
	 *
	 * @param array $data Summary records (label-restored, full dimensions)
	 * @param array|null $filter_conditions Filter conditions, or null/empty for no filtering
	 * @param array $filterable_columns Column names this material allows filtering on
	 * @return array Filtered records
	 */
	private function apply_summary_filters( $data, $filter_conditions, $filterable_columns ) {
		if ( empty( $filter_conditions ) || ! is_array( $filter_conditions ) ) {
			return $data;
		}

		$filtered_data = array();
		foreach ( $data as $record ) {
			$matches = true;

			foreach ( $filter_conditions as $column => $condition ) {
				if ( ! $matches ) {
					break;
				}
				// Only evaluate columns that belong to this material's dimensions.
				if ( ! in_array( $column, $filterable_columns, true ) ) {
					continue;
				}
				if ( null === $condition || ( is_array( $condition ) && empty( $condition ) ) ) {
					continue;
				}

				$value = isset( $record[ $column ] ) ? $record[ $column ] : null;

				if ( is_array( $condition ) && $this->is_operator_filter( $condition ) ) {
					// Operator format: ['gte' => 100], ['eq' => 'google'], etc.
					if ( ! $this->match_operator_filter( $value, $condition ) ) {
						$matches = false;
					}
				} else {
					// Indexed array (IN clause) format: ['google', 'yahoo']
					$in_list = is_array( $condition ) ? $condition : array( $condition );
					if ( ! $this->wrap_in_array( $value, $in_list ) ) {
						$matches = false;
					}
				}
			}

			if ( $matches ) {
				$filtered_data[] = $record;
			}
		}

		return $filtered_data;
	}

	/**
	 * Fetch data for 'summary_landingpage' material
	 *
	 * Retrieves the year-spanning cumulative landing-page summary via
	 * QAHM_DB::summary_days_landingpages(). That function reads the integral
	 * summary files and restores ID->label values (utm_source / utm_medium /
	 * utm_campaign / source_domain / title / url / wp_qa_id) internally, so the
	 * Storage layer evaluates filter conditions and trims the result to the
	 * requested physical columns.
	 *
	 * Filter evaluation happens here (Storage layer), consistent with every
	 * other material — see apply_summary_filters().
	 *
	 * @param string $tracking_id Tracking ID
	 * @param array $time_range Time range configuration
	 * @param array $physical_columns Physical column names to retrieve
	 * @param array|null $filter_conditions Filter conditions (e.g. ['utm_source' => ['google']])
	 * @return array Result with record_count and data, or error array
	 */
	private function fetch_summary_landingpage_data( $tracking_id, $time_range, $physical_columns, $filter_conditions = null ) {
		global $qahm_db;

		$dateterm_result = $this->build_summary_dateterm( $time_range );
		if ( isset( $dateterm_result['error_code'] ) ) {
			return $dateterm_result;
		}

		if ( ! is_object( $qahm_db ) || ! method_exists( $qahm_db, 'summary_days_landingpages' ) ) {
			return array(
				'error_code' => 'E_METHOD_NOT_AVAILABLE',
				'message' => __( 'Required method summary_days_landingpages is not available.', 'qa-heatmap-analytics' ),
				'location' => 'storage'
			);
		}

		// summary_days_landingpages() calls get_safe_tracking_id() internally.
		$raw_data = $qahm_db->summary_days_landingpages( $dateterm_result['dateterm'], $tracking_id );
		if ( ! is_array( $raw_data ) ) {
			$raw_data = array();
		}

		// page_id / second_page are page-dimension columns (present in landingpage).
		$filterable_columns = array(
			'utm_source', 'utm_medium', 'utm_campaign', 'device_id',
			'is_newuser', 'is_QA', 'page_id', 'second_page',
		);
		$raw_data = $this->apply_summary_filters( $raw_data, $filter_conditions, $filterable_columns );

		$filtered_data = $this->filter_columns_from_records( $raw_data, $physical_columns );

		return array(
			'record_count' => $this->wrap_count( $filtered_data ),
			'data' => $filtered_data
		);
	}

	/**
	 * Fetch data for 'summary_allpage' material
	 *
	 * Retrieves the year-spanning cumulative all-page summary via
	 * QAHM_DB::summary_days_allpages(). ID->label restoration happens inside
	 * that function; the Storage layer evaluates filters and trims columns.
	 *
	 * Filter evaluation happens here (Storage layer) — see apply_summary_filters().
	 *
	 * @param string $tracking_id Tracking ID
	 * @param array $time_range Time range configuration
	 * @param array $physical_columns Physical column names to retrieve
	 * @param array|null $filter_conditions Filter conditions (e.g. ['device_id' => [1]])
	 * @return array Result with record_count and data, or error array
	 */
	private function fetch_summary_allpage_data( $tracking_id, $time_range, $physical_columns, $filter_conditions = null ) {
		global $qahm_db;

		$dateterm_result = $this->build_summary_dateterm( $time_range );
		if ( isset( $dateterm_result['error_code'] ) ) {
			return $dateterm_result;
		}

		if ( ! is_object( $qahm_db ) || ! method_exists( $qahm_db, 'summary_days_allpages' ) ) {
			return array(
				'error_code' => 'E_METHOD_NOT_AVAILABLE',
				'message' => __( 'Required method summary_days_allpages is not available.', 'qa-heatmap-analytics' ),
				'location' => 'storage'
			);
		}

		// summary_days_allpages() calls get_safe_tracking_id() internally.
		$raw_data = $qahm_db->summary_days_allpages( $dateterm_result['dateterm'], $tracking_id );
		if ( ! is_array( $raw_data ) ) {
			$raw_data = array();
		}

		// page_id is a page-dimension column (present in allpage). No second_page.
		$filterable_columns = array(
			'utm_source', 'utm_medium', 'utm_campaign', 'device_id',
			'is_newuser', 'is_QA', 'page_id',
		);
		$raw_data = $this->apply_summary_filters( $raw_data, $filter_conditions, $filterable_columns );

		$filtered_data = $this->filter_columns_from_records( $raw_data, $physical_columns );

		return array(
			'record_count' => $this->wrap_count( $filtered_data ),
			'data' => $filtered_data
		);
	}

	/**
	 * Fetch data for 'summary_days_access_detail' material
	 *
	 * Retrieves the year-spanning cumulative source/medium/campaign x device
	 * access summary via QAHM_DB::summary_days_access_detail(). ID->label
	 * restoration happens inside that function; the Storage layer evaluates
	 * filters and trims columns.
	 *
	 * Unlike the other two summary functions, summary_days_access_detail() does
	 * NOT call get_safe_tracking_id() internally, so it is applied here before
	 * the call (T102 §9 — tracking_id sanitization).
	 *
	 * Filter evaluation happens here (Storage layer) — see apply_summary_filters().
	 *
	 * @param string $tracking_id Tracking ID
	 * @param array $time_range Time range configuration
	 * @param array $physical_columns Physical column names to retrieve
	 * @param array|null $filter_conditions Filter conditions (e.g. ['utm_medium' => ['organic']])
	 * @return array Result with record_count and data, or error array
	 */
	private function fetch_summary_days_access_detail_data( $tracking_id, $time_range, $physical_columns, $filter_conditions = null ) {
		global $qahm_db;

		$dateterm_result = $this->build_summary_dateterm( $time_range );
		if ( isset( $dateterm_result['error_code'] ) ) {
			return $dateterm_result;
		}

		if ( ! is_object( $qahm_db ) || ! method_exists( $qahm_db, 'summary_days_access_detail' ) ) {
			return array(
				'error_code' => 'E_METHOD_NOT_AVAILABLE',
				'message' => __( 'Required method summary_days_access_detail is not available.', 'qa-heatmap-analytics' ),
				'location' => 'storage'
			);
		}

		// summary_days_access_detail() does NOT sanitize tracking_id internally.
		$safe_tracking_id = $qahm_db->get_safe_tracking_id( $tracking_id );

		$raw_data = $qahm_db->summary_days_access_detail( $dateterm_result['dateterm'], $safe_tracking_id );
		if ( ! is_array( $raw_data ) ) {
			$raw_data = array();
		}

		// access_detail has no page dimension — no page_id / second_page.
		$filterable_columns = array(
			'utm_source', 'utm_medium', 'utm_campaign', 'device_id',
			'is_newuser', 'is_QA',
		);
		$raw_data = $this->apply_summary_filters( $raw_data, $filter_conditions, $filterable_columns );

		$filtered_data = $this->filter_columns_from_records( $raw_data, $physical_columns );

		return array(
			'record_count' => $this->wrap_count( $filtered_data ),
			'data' => $filtered_data
		);
	}

	/**
	 * Apply filter conditions to allpv data
	 *
	 * Filters records based on specified conditions.
	 * Supported filter fields: utm_source, utm_medium, utm_campaign, device_type, country_code, url
	 *
	 * Supports two filter formats:
	 * - Indexed array (existing): ['utm_medium' => ['facebook', 'twitter']] — IN clause
	 * - Operator object (new):    ['url' => ['prefix' => 'https://...']]   — operator-based
	 *
	 * #1489: indexed-array 対応フィールドを増減したら fetch_allpv_data の fail-loud whitelist
	 * （$fallback_indexed_fields）も同時に揃えること（1:1 の対）。ここに増やして whitelist を
	 * 忘れると、対応済みのはずのフィルタがフォールバックで E_FILTER_NOT_SUPPORTED になる。
	 *
	 * @param array $data Raw data records
	 * @param array $filter_conditions Filter conditions
	 * @return array Filtered data records
	 */
	private function apply_allpv_filters( $data, $filter_conditions ) {
		$filtered_data = array();

		foreach ( $data as $record ) {
			$matches = true;

			// utm_medium filter (indexed array format)
			if ( isset( $filter_conditions['utm_medium'] ) && ! empty( $filter_conditions['utm_medium'] ) && ! $this->is_operator_filter( $filter_conditions['utm_medium'] ) ) {
				$utm_medium = isset( $record['utm_medium'] ) ? $record['utm_medium'] : '(not set)';
				if ( ! $this->wrap_in_array( $utm_medium, $filter_conditions['utm_medium'] ) ) {
					$matches = false;
				}
			}

			// utm_source filter (indexed array format)
			if ( $matches && isset( $filter_conditions['utm_source'] ) && ! empty( $filter_conditions['utm_source'] ) && ! $this->is_operator_filter( $filter_conditions['utm_source'] ) ) {
				$utm_source = isset( $record['utm_source'] ) ? $record['utm_source'] : null;
				$source_domain = isset( $record['source_domain'] ) ? $record['source_domain'] : '(not set)';
				// Use utm_source if available, otherwise use source_domain
				$source = ! empty( $utm_source ) ? $utm_source : $source_domain;
				if ( ! $this->wrap_in_array( $source, $filter_conditions['utm_source'] ) ) {
					$matches = false;
				}
			}

			// utm_campaign filter (indexed array format)
			if ( $matches && isset( $filter_conditions['utm_campaign'] ) && ! empty( $filter_conditions['utm_campaign'] ) && ! $this->is_operator_filter( $filter_conditions['utm_campaign'] ) ) {
				$utm_campaign = isset( $record['utm_campaign'] ) ? $record['utm_campaign'] : '(not set)';
				if ( ! $this->wrap_in_array( $utm_campaign, $filter_conditions['utm_campaign'] ) ) {
					$matches = false;
				}
			}

			// device_type filter (indexed array format)
			if ( $matches && isset( $filter_conditions['device_type'] ) && ! empty( $filter_conditions['device_type'] ) && ! $this->is_operator_filter( $filter_conditions['device_type'] ) ) {
				$device_type = isset( $record['device_type'] ) ? $record['device_type'] : null;
				if ( ! $this->wrap_in_array( $device_type, $filter_conditions['device_type'] ) ) {
					$matches = false;
				}
			}

			// country_code filter (indexed array format)
			if ( $matches && isset( $filter_conditions['country_code'] ) && ! empty( $filter_conditions['country_code'] ) && ! $this->is_operator_filter( $filter_conditions['country_code'] ) ) {
				$country_code = isset( $record['country_code'] ) ? $record['country_code'] : '(not set)';
				if ( ! $this->wrap_in_array( $country_code, $filter_conditions['country_code'] ) ) {
					$matches = false;
				}
			}

			// Generic operator filter handler (supports url, and operator format on any field).
			// Also handles operator format on existing fields (e.g., utm_medium with {"eq": "social"})
			// since the indexed array checks above skip operator format via is_operator_filter() guard.
			if ( $matches ) {
				foreach ( $filter_conditions as $field => $condition ) {
					if ( ! $matches ) {
						break;
					}
					if ( ! is_array( $condition ) || ! $this->is_operator_filter( $condition ) ) {
						continue;
					}
					$value = isset( $record[ $field ] ) ? $record[ $field ] : null;
					if ( ! $this->match_operator_filter( $value, $condition ) ) {
						$matches = false;
					}
				}
			}

			if ( $matches ) {
				$filtered_data[] = $record;
			}
		}

		return $filtered_data;
	}

	/**
	 * Check if a filter condition is in operator object format
	 *
	 * Operator format: associative array with operator keys (e.g., ['eq' => 'value', 'prefix' => 'value'])
	 * Indexed array format: sequential array (e.g., ['organic', 'facebook'])
	 *
	 * @param array $condition Filter condition to check
	 * @return bool True if operator format
	 */
	private function is_operator_filter( $condition ) {
		if ( ! is_array( $condition ) ) {
			return false;
		}
		$operators = array( 'eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'contains', 'prefix', 'between' );
		foreach ( array_keys( $condition ) as $key ) {
			if ( in_array( $key, $operators, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Match a value against operator filter conditions
	 *
	 * @param mixed $value Record field value
	 * @param array $condition Operator conditions (e.g., ['prefix' => 'https://...'])
	 * @return bool True if value matches all conditions
	 */
	private function match_operator_filter( $value, $condition ) {
		foreach ( $condition as $operator => $target ) {
			switch ( $operator ) {
				case 'eq':
					if ( $value !== $target ) {
						return false;
					}
					break;
				case 'neq':
					if ( $value === $target ) {
						return false;
					}
					break;
				case 'prefix':
					if ( null === $value || 0 !== strpos( $value, $target ) ) {
						return false;
					}
					break;
				case 'contains':
					if ( null === $value || false === strpos( $value, $target ) ) {
						return false;
					}
					break;
				case 'gt':
					if ( null === $value || $value <= $target ) {
						return false;
					}
					break;
				case 'gte':
					if ( null === $value || $value < $target ) {
						return false;
					}
					break;
				case 'lt':
					if ( null === $value || $value >= $target ) {
						return false;
					}
					break;
				case 'lte':
					if ( null === $value || $value > $target ) {
						return false;
					}
					break;
				case 'in':
					if ( ! is_array( $target ) || ! $this->wrap_in_array( $value, $target ) ) {
						return false;
					}
					break;
				case 'between':
					if ( ! is_array( $target ) || count( $target ) < 2 ) {
						return false;
					}
					if ( null === $value || $value < $target[0] || $value > $target[1] ) {
						return false;
					}
					break;
			}
		}
		return true;
	}

	/**
	 * keyword フィルタ条件を query_id 集合に変換する
	 *
	 * qa_gsc_{tid}_query_log テーブルに対してキーワード検索を行い、
	 * マッチする query_id の配列を返す。
	 *
	 * @param string $tracking_id トラッキングID
	 * @param mixed  $condition   フィルタ条件（配列 or オペレータ形式）
	 * @return array|null query_id配列。変換不可の場合null
	 */
	private function resolve_keyword_to_query_ids( $tracking_id, $condition ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'qa_gsc_' . $tracking_id . '_query_log';

		// テーブル存在チェック
		$table_exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
			$table_name
		) );
		if ( ! $table_exists ) {
			return array();
		}

		// オペレータ形式
		if ( is_array( $condition ) && ! empty( $condition ) ) {
			// eq
			if ( isset( $condition['eq'] ) && count( $condition ) === 1 ) {
				$sql = $wpdb->prepare(
					"SELECT DISTINCT query_id FROM {$table_name} WHERE keyword = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$condition['eq']
				);
				$rows = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return array_map( 'intval', $rows );
			}

			// in
			if ( isset( $condition['in'] ) && is_array( $condition['in'] ) && count( $condition ) === 1 ) {
				if ( empty( $condition['in'] ) ) {
					return array();
				}
				$placeholders = implode( ',', array_fill( 0, count( $condition['in'] ), '%s' ) );
				$sql = $wpdb->prepare(
					"SELECT DISTINCT query_id FROM {$table_name} WHERE keyword IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$condition['in']
				);
				$rows = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return array_map( 'intval', $rows );
			}

			// contains
			if ( isset( $condition['contains'] ) && count( $condition ) === 1 ) {
				$like = '%' . $wpdb->esc_like( $condition['contains'] ) . '%';
				$sql = $wpdb->prepare(
					"SELECT DISTINCT query_id FROM {$table_name} WHERE keyword LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$like
				);
				$rows = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return array_map( 'intval', $rows );
			}

			// prefix
			if ( isset( $condition['prefix'] ) && count( $condition ) === 1 ) {
				$like = $wpdb->esc_like( $condition['prefix'] ) . '%';
				$sql = $wpdb->prepare(
					"SELECT DISTINCT query_id FROM {$table_name} WHERE keyword LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$like
				);
				$rows = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return array_map( 'intval', $rows );
			}

			// フラット配列（IN句）
			if ( ! isset( $condition[0] ) ) {
				return null;
			}
			$placeholders = implode( ',', array_fill( 0, count( $condition ), '%s' ) );
			$sql = $wpdb->prepare(
				"SELECT DISTINCT query_id FROM {$table_name} WHERE keyword IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$condition
			);
			$rows = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return array_map( 'intval', $rows );
		}

		return null;
	}

	/**
	 * Normalize boolean values for consistent comparison
	 *
	 * Converts boolean, integer, and string representations to a consistent integer format:
	 * - true, 1, "1", "true" -> 1
	 * - false, 0, "0", "false" -> 0
	 * - null -> null
	 *
	 * @param mixed $value Value to normalize
	 * @return int|null Normalized value (1, 0, or null)
	 */
	private function normalize_boolean_value( $value ) {
		if ( $value === null ) {
			return null;
		}
		if ( is_bool( $value ) ) {
			return $value ? 1 : 0;
		}
		if ( is_int( $value ) ) {
			return $value ? 1 : 0;
		}
		if ( is_string( $value ) ) {
			$lower = strtolower( $value );
			if ( $lower === 'true' || $lower === '1' ) {
				return 1;
			}
			if ( $lower === 'false' || $lower === '0' ) {
				return 0;
			}
		}
		return $value ? 1 : 0;
	}

	/**
	 * Apply filter conditions to goal data
	 * 
	 * Filters records based on specified conditions.
	 * Supported filter fields: utm_source, utm_medium, utm_campaign, device_id, is_reject
	 *
	 * @param array $data Raw data records
	 * @param array $filter_conditions Filter conditions (e.g., ['is_reject' => [false]])
	 * @return array Filtered data records
	 */
	private function apply_goal_filters( $data, $filter_conditions ) {
		$filtered_data = array();

		foreach ( $data as $record ) {
			$matches = true;

			// utm_medium filter
			if ( isset( $filter_conditions['utm_medium'] ) && ! empty( $filter_conditions['utm_medium'] ) ) {
				$utm_medium = isset( $record['utm_medium'] ) ? $record['utm_medium'] : '(not set)';
				if ( ! $this->wrap_in_array( $utm_medium, $filter_conditions['utm_medium'] ) ) {
					$matches = false;
				}
			}

			// utm_source filter
			if ( $matches && isset( $filter_conditions['utm_source'] ) && ! empty( $filter_conditions['utm_source'] ) ) {
				$utm_source = isset( $record['utm_source'] ) ? $record['utm_source'] : null;
				$source_domain = isset( $record['source_domain'] ) ? $record['source_domain'] : '(not set)';
				// Use utm_source if available, otherwise use source_domain
				$source = ! empty( $utm_source ) ? $utm_source : $source_domain;
				if ( ! $this->wrap_in_array( $source, $filter_conditions['utm_source'] ) ) {
					$matches = false;
				}
			}

			// utm_campaign filter
			if ( $matches && isset( $filter_conditions['utm_campaign'] ) && ! empty( $filter_conditions['utm_campaign'] ) ) {
				$utm_campaign = isset( $record['utm_campaign'] ) ? $record['utm_campaign'] : '(not set)';
				if ( ! $this->wrap_in_array( $utm_campaign, $filter_conditions['utm_campaign'] ) ) {
					$matches = false;
				}
			}

			// device_id filter
			if ( $matches && isset( $filter_conditions['device_id'] ) && ! empty( $filter_conditions['device_id'] ) ) {
				$device_id = isset( $record['device_id'] ) ? $record['device_id'] : null;
				if ( ! $this->wrap_in_array( $device_id, $filter_conditions['device_id'] ) ) {
					$matches = false;
				}
			}

			// is_reject filter (with type normalization for boolean/string compatibility)
			if ( $matches && isset( $filter_conditions['is_reject'] ) ) {
				$is_reject = isset( $record['is_reject'] ) ? $record['is_reject'] : null;
				$is_reject_normalized = $this->normalize_boolean_value( $is_reject );
				$filter_values_normalized = array();
				if ( is_array( $filter_conditions['is_reject'] ) ) {
					foreach ( $filter_conditions['is_reject'] as $filter_value ) {
						$filter_values_normalized[] = $this->normalize_boolean_value( $filter_value );
					}
				}
				if ( ! $this->wrap_in_array( $is_reject_normalized, $filter_values_normalized, true ) ) {
					$matches = false;
				}
			}

			if ( $matches ) {
				$filtered_data[] = $record;
			}
		}

		return $filtered_data;
	}

	/**
	 * Fetch data for 'page_version' material
	 *
	 * Retrieves page version history from qa_page_version_hist table via DB direct query.
	 * Excludes base_html/base_selector (heavy content columns).
	 * Supports filter conditions: page_id, device_id, version_no, update_date.
	 * time_range is mapped to update_date BETWEEN condition.
	 *
	 * @param string     $tracking_id      Tracking ID
	 * @param array      $time_range       Time range with 'start', 'end', 'tz'
	 * @param array      $physical_columns Physical column names to retrieve
	 * @param array|null $filter_conditions Filter conditions
	 * @return array ['record_count' => int, 'data' => array] or error array
	 */
	private function fetch_page_version_data( $tracking_id, $time_range, $physical_columns, $filter_conditions = null ) {
		// time_range → update_date の日付範囲に変換
		$start_date = isset( $time_range['start'] ) && is_string( $time_range['start'] )
			? substr( $time_range['start'], 0, 10 )
			: null;
		$end_date = isset( $time_range['end'] ) && is_string( $time_range['end'] )
			? substr( $time_range['end'], 0, 10 )
			: null;

		// フィルタ条件の抽出
		$page_id   = null;
		$device_id = null;
		$limit     = 50000;

		if ( ! empty( $filter_conditions ) && is_array( $filter_conditions ) ) {
			// page_id フィルタ
			if ( isset( $filter_conditions['page_id'] ) ) {
				$page_id = $this->extract_filter_value( $filter_conditions['page_id'] );
			}
			// device_id フィルタ
			if ( isset( $filter_conditions['device_id'] ) ) {
				$device_id = $this->extract_filter_value( $filter_conditions['device_id'] );
			}
		}

		$rows = QAHM_DB_Functions::get_qa_page_version_hist(
			null,
			$page_id,
			$device_id,
			$start_date,
			$end_date,
			false,
			$limit
		);

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		// version_no / update_date のポストフィルタ
		if ( ! empty( $filter_conditions ) && is_array( $filter_conditions ) ) {
			$rows = $this->apply_page_version_post_filters( $rows, $filter_conditions );
		}

		// physical_columns で必要なカラムのみに絞る（insert_datetime等のDB余剰カラムも除去）
		$allowed = array( 'version_id', 'page_id', 'device_id', 'version_no', 'update_date' );
		$keep_cols = array_intersect( $physical_columns, $allowed );

		$filtered_rows = array();
		foreach ( $rows as $row ) {
			$filtered_row = array();
			foreach ( $keep_cols as $col ) {
				if ( isset( $row[ $col ] ) ) {
					$filtered_row[ $col ] = $row[ $col ];
				}
			}
			$filtered_rows[] = $filtered_row;
		}
		$rows = $filtered_rows;

		return array(
			'record_count' => $this->wrap_count( $rows ),
			'data'         => $rows,
		);
	}

	/**
	 * Extract a single value or array of values from a filter condition
	 *
	 * @param mixed $condition Filter condition (array for IN, object with operators, or scalar)
	 * @return mixed Extracted value(s)
	 */
	private function extract_filter_value( $condition ) {
		if ( is_array( $condition ) && array_values( $condition ) === $condition ) {
			// Sequential numeric keys = plain IN clause
			return $condition;
		}
		if ( is_array( $condition ) ) {
			if ( isset( $condition['eq'] ) ) {
				return $condition['eq'];
			}
			if ( isset( $condition['in'] ) ) {
				return $condition['in'];
			}
		}
		return $condition;
	}

	/**
	 * Apply post-filters for page_version (version_no, update_date)
	 *
	 * @param array $rows    Data rows
	 * @param array $filters Filter conditions
	 * @return array Filtered rows
	 */
	private function apply_page_version_post_filters( $rows, $filters ) {
		$filtered = array();
		foreach ( $rows as $row ) {
			$match = true;

			// version_no filter
			if ( isset( $filters['version_no'] ) && $match ) {
				$match = $this->match_filter_condition( $row, 'version_no', $filters['version_no'] );
			}

			// update_date filter (beyond time_range)
			if ( isset( $filters['update_date'] ) && $match ) {
				$match = $this->match_filter_condition( $row, 'update_date', $filters['update_date'] );
			}

			if ( $match ) {
				$filtered[] = $row;
			}
		}
		return $filtered;
	}

	/**
	 * Match a single filter condition against a row value
	 *
	 * @param array  $row       Data row
	 * @param string $column    Column name
	 * @param mixed  $condition Filter condition
	 * @return bool Whether the row matches
	 */
	private function match_filter_condition( $row, $column, $condition ) {
		if ( ! isset( $row[ $column ] ) ) {
			return false;
		}
		$value = $row[ $column ];

		// Sequential numeric keys = plain IN clause
		if ( is_array( $condition ) && array_values( $condition ) === $condition ) {
			return $this->wrap_in_array( $value, $condition );
		}

		if ( ! is_array( $condition ) ) {
			// Scalar = eq
			return $value == $condition;
		}

		// Operator-based conditions — AND all matching operators together
		$match = true;
		$has_operator = false;

		if ( isset( $condition['eq'] ) ) {
			$has_operator = true;
			$match = $match && ( $value == $condition['eq'] );
		}
		if ( isset( $condition['neq'] ) ) {
			$has_operator = true;
			$match = $match && ( $value != $condition['neq'] );
		}
		if ( isset( $condition['gt'] ) ) {
			$has_operator = true;
			$match = $match && ( $value > $condition['gt'] );
		}
		if ( isset( $condition['gte'] ) ) {
			$has_operator = true;
			$match = $match && ( $value >= $condition['gte'] );
		}
		if ( isset( $condition['lt'] ) ) {
			$has_operator = true;
			$match = $match && ( $value < $condition['lt'] );
		}
		if ( isset( $condition['lte'] ) ) {
			$has_operator = true;
			$match = $match && ( $value <= $condition['lte'] );
		}
		if ( isset( $condition['between'] ) && is_array( $condition['between'] ) && $this->wrap_count( $condition['between'] ) === 2 ) {
			$has_operator = true;
			$match = $match && ( $value >= $condition['between'][0] && $value <= $condition['between'][1] );
		}
		if ( isset( $condition['in'] ) && is_array( $condition['in'] ) ) {
			$has_operator = true;
			$match = $match && $this->wrap_in_array( $value, $condition['in'] );
		}
		if ( isset( $condition['prefix'] ) ) {
			$has_operator = true;
			$match = $match && ( is_string( $value ) && is_string( $condition['prefix'] ) && strpos( $value, $condition['prefix'] ) === 0 );
		}
		if ( isset( $condition['contains'] ) ) {
			$has_operator = true;
			$match = $match && ( is_string( $value ) && is_string( $condition['contains'] ) && strpos( $value, $condition['contains'] ) !== false );
		}

		return $has_operator ? $match : true;
	}
}

$GLOBALS['qahm_qal_storage'] = new QAHM_Qal_Storage();
