<?php
defined( 'ABSPATH' ) || exit;
/**
 * Class QAHM_Data_Processor
 *
 * データ処理クラス
 * 低レベルデータの取得（ファイル/DB）と高度な処理を行います
 * - IDをわかりやすい名前や値に変換
 * - データの集計・分析処理
 *
 * @package qa_heatmap
 */
class QAHM_Data_Processor extends QAHM_Base {

	/**
	 * IDの変換情報をキャッシュする配列
	 * @var array
	 */
	private static $cache = array(
		'utm_media'     => array(),
		'utm_content'   => array(),
		'utm_sources'   => array(),
		'utm_campaigns' => array(),
		'pages'         => array(),
	);

	/**
	 * キャッシュをクリアする
	 *
	 * @param string|array $cache_type キャッシュタイプ（'utm_media', 'utm_content', 'utm_sources', 'utm_campaigns', 'pages'）
	 *                                  指定しない場合は全てクリア
	 * @return void
	 */
	public static function clear_cache( $cache_type = null ) {
		if ( $cache_type === null ) {
			self::$cache = array(
				'utm_media'     => array(),
				'utm_content'   => array(),
				'utm_sources'   => array(),
				'utm_campaigns' => array(),
				'pages'         => array(),
			);
		} elseif ( is_array( $cache_type ) ) {
			foreach ( $cache_type as $type ) {
				if ( isset( self::$cache[ $type ] ) ) {
					self::$cache[ $type ] = array();
				}
			}
		} elseif ( isset( self::$cache[ $cache_type ] ) ) {
			self::$cache[ $cache_type ] = array();
		}
	}

	/**
	 * medium_idを名前に変換
	 *
	 * @param int|array $medium_id 変換対象のmedium_id、または複数のmedium_idを含む配列
	 * @param bool $include_description 説明も含めるか
	 * @return string|array 変換後のUTMメディア名
	 */
	public static function resolve_medium_id( $medium_id, $include_description = false ) {
		// 値が空の場合は処理しない
		if ( empty( $medium_id ) ) {
			return $medium_id;
		}

		// キャッシュをチェック
		if ( empty( self::$cache['utm_media'] ) ) {
			$media_data = QAHM_DB_Functions::get_utm_media();
			foreach ( $media_data as $media ) {
				self::$cache['utm_media'][ $media['medium_id'] ] = $media;
			}
		}

		// 配列の場合は再帰的に処理
		if ( is_array( $medium_id ) ) {
			$result = array();
			foreach ( $medium_id as $id ) {
				$result[ $id ] = self::resolve_medium_id( $id, $include_description );
			}
			return $result;
		}

		// 単一IDの変換
		if ( isset( self::$cache['utm_media'][ $medium_id ] ) ) {
			$media = self::$cache['utm_media'][ $medium_id ];
			if ( $include_description && ! empty( $media['description'] ) ) {
				return $media['utm_medium'] . ' (' . $media['description'] . ')';
			} else {
				return $media['utm_medium'];
			}
		}

		// 見つからない場合はIDをそのまま返す
		return 'medium_' . $medium_id;
	}

	/**
	 * source_idを名前に変換
	 *
	 * @param int|array $source_id 変換対象のsource_id、または複数のsource_idを含む配列
	 * @param bool $include_domain ドメイン情報も含めるか
	 * @return string|array 変換後のUTMソース名
	 */
	public static function resolve_source_id( $source_id, $include_domain = false ) {
		// 値が空の場合は処理しない
		if ( empty( $source_id ) ) {
			return $source_id;
		}

		// キャッシュをチェック
		if ( empty( self::$cache['utm_sources'] ) ) {
			$sources_data = QAHM_DB_Functions::get_utm_sources();
			foreach ( $sources_data as $source ) {
				self::$cache['utm_sources'][ $source['source_id'] ] = $source;
			}
		}

		// 配列の場合は再帰的に処理
		if ( is_array( $source_id ) ) {
			$result = array();
			foreach ( $source_id as $id ) {
				$result[ $id ] = self::resolve_source_id( $id, $include_domain );
			}
			return $result;
		}

		// 単一IDの変換
		if ( isset( self::$cache['utm_sources'][ $source_id ] ) ) {
			$source = self::$cache['utm_sources'][ $source_id ];
			if ( $include_domain && ! empty( $source['source_domain'] ) ) {
				return $source['utm_source'] . ' (' . $source['source_domain'] . ')';
			} else {
				return $source['utm_source'];
			}
		}

		// 見つからない場合はIDをそのまま返す
		return 'source_' . $source_id;
	}

	/**
	 * campaign_idを名前に変換
	 *
	 * @param int|array $campaign_id 変換対象のcampaign_id、または複数のcampaign_idを含む配列
	 * @return string|array 変換後のUTMキャンペーン名
	 */
	public static function resolve_campaign_id( $campaign_id ) {
		// 値が空の場合は処理しない
		if ( empty( $campaign_id ) ) {
			return $campaign_id;
		}

		// キャッシュをチェック
		if ( empty( self::$cache['utm_campaigns'] ) ) {
			$campaigns_data = QAHM_DB_Functions::get_utm_campaigns();
			foreach ( $campaigns_data as $campaign ) {
				self::$cache['utm_campaigns'][ $campaign['campaign_id'] ] = $campaign;
			}
		}

		// 配列の場合は再帰的に処理
		if ( is_array( $campaign_id ) ) {
			$result = array();
			foreach ( $campaign_id as $id ) {
				$result[ $id ] = self::resolve_campaign_id( $id );
			}
			return $result;
		}

		// 単一IDの変換
		if ( isset( self::$cache['utm_campaigns'][ $campaign_id ] ) ) {
			return self::$cache['utm_campaigns'][ $campaign_id ]['utm_campaign'];
		}

		// 見つからない場合はIDをそのまま返す
		return 'campaign_' . $campaign_id;
	}

	/**
	 * content_idを名前に変換
	 *
	 * @param int|array $content_id 変換対象のcontent_id、または複数のcontent_idを含む配列
	 * @return string|array 変換後のUTMコンテンツ名
	 */
	public static function resolve_content_id( $content_id ) {
		// 値が空の場合は処理しない
		if ( empty( $content_id ) ) {
			return $content_id;
		}

		// キャッシュをチェック
		if ( empty( self::$cache['utm_content'] ) ) {
			$contents_data = QAHM_DB_Functions::get_utm_content();
			foreach ( $contents_data as $content ) {
				self::$cache['utm_content'][ $content['content_id'] ] = $content;
			}
		}

		// 配列の場合は再帰的に処理
		if ( is_array( $content_id ) ) {
			$result = array();
			foreach ( $content_id as $id ) {
				$result[ $id ] = self::resolve_content_id( $id );
			}
			return $result;
		}

		// 単一IDの変換
		if ( isset( self::$cache['utm_content'][ $content_id ] ) ) {
			return self::$cache['utm_content'][ $content_id ]['utm_content'];
		}

		// 見つからない場合はIDをそのまま返す
		return 'content_' . $content_id;
	}

	/**
	 * page_idをURL/タイトルに変換
	 *
	 * @param int|array $page_id 変換対象のpage_id、または複数のpage_idを含む配列
	 * @param string $return_type 'url'（デフォルト）, 'title', 'both'のいずれか
	 * @return string|array 変換後のURL/タイトル
	 */
	public static function resolve_page_id( $page_id, $return_type = 'url' ) {
		// 値が空の場合は処理しない
		if ( empty( $page_id ) ) {
			return $page_id;
		}

		// 配列の場合は再帰的に処理
		if ( is_array( $page_id ) ) {
			$result = array();
			foreach ( $page_id as $id ) {
				$result[ $id ] = self::resolve_page_id( $id, $return_type );
			}
			return $result;
		}

		// キャッシュをチェック
		if ( ! isset( self::$cache['pages'][ $page_id ] ) ) {
			$page_data = QAHM_DB_Functions::get_qa_pages( $page_id );
			if ( $page_data ) {
				self::$cache['pages'][ $page_id ] = $page_data;
			} else {
				return 'page_' . $page_id;
			}
		}

		$page = self::$cache['pages'][ $page_id ];

		// 戻り値のタイプに応じて返す
		switch ( $return_type ) {
			case 'title':
				return $page['title'] ?: 'page_' . $page_id;
			case 'both':
				return array(
					'url'   => $page['url'] ?: 'page_' . $page_id,
					'title' => $page['title'] ?: 'page_' . $page_id,
				);
			case 'url':
			default:
				return $page['url'] ?: 'page_' . $page_id;
		}
	}

	/**
	 * デバイスIDを名前に変換
	 *
	 * @param int|array $device_id 変換対象のdevice_id
	 * @return string|array 変換後のデバイス名
	 */
	public static function resolve_device_id( $device_id ) {
		// 値が空の場合は処理しない
		if ( empty( $device_id ) ) {
			return $device_id;
		}

		// デバイス定義
		$devices = array(
			1 => 'desktop',
			2 => 'mobile',
			3 => 'tablet',
		);

		// 配列の場合は再帰的に処理
		if ( is_array( $device_id ) ) {
			$result = array();
			foreach ( $device_id as $id ) {
				$result[ $id ] = self::resolve_device_id( $id );
			}
			return $result;
		}

		// 単一IDの変換
		if ( isset( $devices[ $device_id ] ) ) {
			return $devices[ $device_id ];
		}

		// 見つからない場合はIDをそのまま返す
		return 'device_' . $device_id;
	}



	/**
	 * データをフラット化する
	 *
	 * @param array $data フラット化するデータ
	 * @param bool $resolve_ids IDを解決するか
	 * @return array フラット化されたデータ
	 */
	public static function flatten_data( $data, $resolve_ids = true ) {
		if ( empty( $data ) ) {
			return $data;
		}

		// 単一レコードか複数レコードかを判別
		$is_single_record = ! isset( $data[0] );
		$records          = $is_single_record ? array( $data ) : $data;
		$result           = array();

		foreach ( $records as $record ) {
			$flat_record = array();

			// 通常のIDフィールドを処理
			$id_fields = array(
				'page_id'     => array(
					'method' => 'resolve_page_id',
					'type'   => 'url',
				),
				'device_id'   => array( 'method' => 'resolve_device_id' ),
				'source_id'   => array( 'method' => 'resolve_source_id' ),
				'medium_id'   => array( 'method' => 'resolve_medium_id' ),
				'campaign_id' => array( 'method' => 'resolve_campaign_id' ),
				'content_id'  => array( 'method' => 'resolve_content_id' ),
			);

			foreach ( $record as $key => $value ) {
				if ( $resolve_ids && isset( $id_fields[ $key ] ) && ! empty( $value ) ) {
					$method = $id_fields[ $key ]['method'];
					$type   = isset( $id_fields[ $key ]['type'] ) ? $id_fields[ $key ]['type'] : null;

					if ( $type ) {
						$flat_record[ $key ]                           = $value;
						$flat_record[ str_replace( '_id', '', $key ) ] = self::$method( $value, $type );
					} else {
						$flat_record[ $key ]                           = $value;
						$flat_record[ str_replace( '_id', '', $key ) ] = self::$method( $value );
					}
				} else {
					$flat_record[ $key ] = $value;
				}
			}

			// 既に解決済みのフィールドがある場合（resolve_pv_log_idsの結果など）
			if ( isset( $record['resolved'] ) ) {
				foreach ( $record['resolved'] as $key => $value ) {
					if ( is_array( $value ) && isset( $value['url'] ) && isset( $value['title'] ) ) {
						// pageのように複合情報の場合
						$flat_record[ $key . '_url' ]   = $value['url'];
						$flat_record[ $key . '_title' ] = $value['title'];
					} else {
						// 単一値の場合
						$flat_record[ $key ] = $value;
					}
				}

				// resolvedキー自体は削除
				unset( $flat_record['resolved'] );
			}

			$result[] = $flat_record;
		}

		return $is_single_record ? $result[0] : $result;
	}

	/**
	 * データを集計する
	 *
	 * @param array $data 集計対象のデータ
	 * @param string|array $dimension 集計軸（例: 'medium', 'source', 'page_id'、または配列で複数指定）
	 * @param string|array $metrics 集計指標（例: 'pv', 'speed_msec', 'browse_sec'、または配列で複数指定）
	 * @param array $options 追加オプション
	 * @return array 集計結果
	 */
	public static function aggregate_data( $data, $dimension, $metrics, $options = array() ) {
		if ( empty( $data ) ) {
			return array();
		}

		// 集計軸を配列に統一
		$dimensions = is_array( $dimension ) ? $dimension : array( $dimension );

		// 集計指標を配列に統一
		$all_metrics = is_array( $metrics ) ? $metrics : array( $metrics );

		// デフォルトオプション
		$default_options = array(
			'resolve_ids' => true,  // ID解決するか
			'sort_by'     => null,      // ソート対象のメトリクス
			'sort_order'  => 'desc', // ソート順序（descは降順、ascは昇順）
			'limit'       => null,        // 結果の上限数
		);

		$options = static::wrap_array_merge_static( $default_options, $options );

		// 集計用の配列を初期化
		$aggregated = array();

		// データをループして集計
		foreach ( $data as $record ) {
			// 集計キーを生成
			$key_parts = array();
			foreach ( $dimensions as $dim ) {
				$key_parts[] = isset( $record[ $dim ] ) ? $record[ $dim ] : 'undefined';
			}
			$key = static::wrap_implode_static( '||', $key_parts );

			// キーが存在しない場合は初期化
			if ( ! isset( $aggregated[ $key ] ) ) {
				$aggregated[ $key ] = array(
					'count'      => 0,
					'dimensions' => array(),
					'metrics'    => array(),
				);

				// 各次元の値を保存
				foreach ( $dimensions as $dim ) {
					$aggregated[ $key ]['dimensions'][ $dim ] = isset( $record[ $dim ] ) ? $record[ $dim ] : null;
				}

				// メトリクスを初期化
				foreach ( $all_metrics as $metric ) {
					$aggregated[ $key ]['metrics'][ $metric ] = array(
						'sum' => 0,
						'min' => null,
						'max' => null,
						'avg' => 0,
					);
				}
			}

			// レコードをカウント
			++$aggregated[ $key ]['count'];

			// 各メトリクスを集計
			foreach ( $all_metrics as $metric ) {
				if ( isset( $record[ $metric ] ) && is_numeric( $record[ $metric ] ) ) {
					$value = (float) $record[ $metric ];

					// 合計を計算
					$aggregated[ $key ]['metrics'][ $metric ]['sum'] += $value;

					// 最小値を計算
					if ( $aggregated[ $key ]['metrics'][ $metric ]['min'] === null || $value < $aggregated[ $key ]['metrics'][ $metric ]['min'] ) {
						$aggregated[ $key ]['metrics'][ $metric ]['min'] = $value;
					}

					// 最大値を計算
					if ( $aggregated[ $key ]['metrics'][ $metric ]['max'] === null || $value > $aggregated[ $key ]['metrics'][ $metric ]['max'] ) {
						$aggregated[ $key ]['metrics'][ $metric ]['max'] = $value;
					}
				}
			}
		}

		// 平均値を計算
		foreach ( $aggregated as &$group ) {
			foreach ( $all_metrics as $metric ) {
				if ( $group['count'] > 0 ) {
					$group['metrics'][ $metric ]['avg'] = $group['metrics'][ $metric ]['sum'] / $group['count'];
				}
			}
		}

		// 結果を配列に変換
		$result = array();
		foreach ( $aggregated as $key => $group ) {
			$row = array();

			// 次元を展開
			foreach ( $group['dimensions'] as $dim => $value ) {
				$row[ $dim ] = $value;

				// ID解決オプションが有効な場合
				if ( $options['resolve_ids'] ) {
					if ( $dim === 'page_id' ) {
						$resolved          = self::resolve_page_id( $value, 'both' );
						$row['page_url']   = is_array( $resolved ) ? $resolved['url'] : $resolved;
						$row['page_title'] = is_array( $resolved ) ? $resolved['title'] : '';
					} elseif ( $dim === 'device_id' ) {
						$row['device'] = self::resolve_device_id( $value );
					} elseif ( $dim === 'source_id' ) {
						$row['source'] = self::resolve_source_id( $value );
					} elseif ( $dim === 'medium_id' ) {
						$row['medium'] = self::resolve_medium_id( $value );
					} elseif ( $dim === 'campaign_id' ) {
						$row['campaign'] = self::resolve_campaign_id( $value );
					} elseif ( $dim === 'content_id' ) {
						$row['content'] = self::resolve_content_id( $value );
					}
				}
			}

			// レコード数
			$row['count'] = $group['count'];

			// メトリクスを展開
			foreach ( $group['metrics'] as $metric => $values ) {
				$row[ $metric . '_sum' ] = $values['sum'];
				$row[ $metric . '_min' ] = $values['min'];
				$row[ $metric . '_max' ] = $values['max'];
				$row[ $metric . '_avg' ] = $values['avg'];
			}

			$result[] = $row;
		}

		// ソート
		if ( $options['sort_by'] ) {
			usort(
				$result,
				function ( $a, $b ) use ( $options ) {
					$field = $options['sort_by'];
					$a_val = isset( $a[ $field ] ) ? $a[ $field ] : 0;
					$b_val = isset( $b[ $field ] ) ? $b[ $field ] : 0;

					if ( $a_val == $b_val ) {
						return 0;
					}

					if ( $options['sort_order'] === 'asc' ) {
						return $a_val < $b_val ? -1 : 1;
					} else {
						return $a_val > $b_val ? -1 : 1;
					}
				}
			);
		}

		// 制限
		if ( $options['limit'] && static::wrap_count_static( $result ) > $options['limit'] ) {
			$result = array_slice( $result, 0, $options['limit'] );
		}

		return $result;
	}
	/**
	 * ページの精読率データをセグメント別に取得する
	 *
	 * @param string $tracking_id トラッキングID
	 * @param int $page_id ページID
	 * @param string $start_date 開始日 (Y-m-d)
	 * @param string $end_date 終了日 (Y-m-d)
	 * @param array $segments セグメント条件
	 *   - utm_media: mediaによるフィルタリング条件の配列
	 *   - utm_source: sourceによるフィルタリング条件の配列
	 *   - utm_campaign: campaignによるフィルタリング条件の配列
	 *   - is_goal: ゴールセッションのみ対象とする場合はtrue
	 * @param array $options 追加オプション
	 *   - device_id: デバイスID (1:desktop, 2:tablet, 3:mobile)
	 *   - is_landing_page: ランディングページのみを対象とする場合はtrue
	 * @return array セグメント別の精読率データ
	 */
	public function get_segmented_reading_data( $tracking_id, $page_id, $start_date, $end_date, $segments = array(), $options = array() ) {
		global $qahm_file_functions, $qahm_data_api;

		// PVデータの取得
		$pv_data = $qahm_file_functions->get_pv_data_by_page_id( $tracking_id, $page_id, $start_date, $end_date );

		// 条件に一致するセッションを取得（ゴールデータ）
		$goal_sessions = array();
		if ( isset( $segments['is_goal'] ) && $segments['is_goal'] ) {
			$dateterm           = 'date = between ' . $start_date . ' and ' . $end_date;
			$all_goals_sessions = $qahm_data_api->get_goals_sessions( $dateterm, $tracking_id );

			// ゴールセッションのPV IDをマッピング
			foreach ( $all_goals_sessions as $goals_sessions ) {
				foreach ( $goals_sessions as $goal_session ) {
					foreach ( $goal_session as $session ) {
						$goal_sessions[ $session['pv_id'] ] = true;
					}
				}
			}
		}

		// セグメントごとにPVをグループ化
		$segmented_pv_data = array();

		foreach ( $pv_data as $pv ) {
			// セグメントキーの作成
			$utm_medium = isset( $pv['utm_medium'] ) ? $pv['utm_medium'] : '(not set)';

			$utm_source    = isset( $pv['utm_source'] ) ? $pv['utm_source'] : null;
			$source_domain = isset( $pv['source_domain'] ) ? $pv['source_domain'] : '(not set)';
			$source        = ! empty( $utm_source ) ? $utm_source : $source_domain;

			$utm_campaign = isset( $pv['utm_campaign'] ) ? $pv['utm_campaign'] : '(not set)';

			$is_goal = isset( $goal_sessions[ $pv['pv_id'] ] ) ? '○' : '×';

			// セグメントキー
			$segment_key = $utm_medium . '_' . $source . '_' . $utm_campaign . '_' . $is_goal;

			// セグメントごとにPVをグループ化
			if ( ! isset( $segmented_pv_data[ $segment_key ] ) ) {
				$segmented_pv_data[ $segment_key ] = array();
			}

			$segmented_pv_data[ $segment_key ][] = $pv;
		}

		// セグメントごとに精読率データを集計
		$result = array();

		foreach ( $segmented_pv_data as $segment_key => $pvs ) {
			// セグメントキーを分解
			list($utm_medium, $source, $utm_campaign, $is_goal) = $this->wrap_explode( '_', $segment_key );

			// フィルター条件を構築
			$filters = static::wrap_array_merge_static(
				$options,
				array(
					'utm_media'    => array( $utm_medium ),
					'utm_source'   => array( $source ),
					'utm_campaign' => array( $utm_campaign ),
					'is_goal'      => ( $is_goal === '○' ),
				)
			);

			// file_functionsのget_page_reading_dataを使用
			$reading_data = $qahm_file_functions->get_page_reading_data( $tracking_id, $page_id, $start_date, $end_date, $filters );

			// セグメント情報を追加
			$reading_data['segment_info'] = array(
				'utm_medium'   => $utm_medium,
				'source'       => $source,
				'utm_campaign' => $utm_campaign,
				'is_goal'      => $is_goal,
			);

			$result[ $segment_key ] = $reading_data;
		}

		return $result;
	}

	/**
	 * ページのクリックデータをセグメント別に取得する
	 *
	 * @param string $tracking_id トラッキングID
	 * @param int $page_id ページID
	 * @param int $version_id バージョンID
	 * @param string $start_date 開始日 (Y-m-d)
	 * @param string $end_date 終了日 (Y-m-d)
	 * @param array $segments セグメント条件
	 *   - utm_media: mediaによるフィルタリング条件の配列
	 *   - utm_source: sourceによるフィルタリング条件の配列
	 *   - utm_campaign: campaignによるフィルタリング条件の配列
	 *   - is_goal: ゴールセッションのみ対象とする場合はtrue
	 * @param array $options 追加オプション
	 *   - device_id: デバイスID (1:desktop, 2:tablet, 3:mobile)
	 *   - is_landing_page: ランディングページのみを対象とする場合はtrue
	 * @return array セグメント別のクリックデータ
	 */
	public function get_segmented_click_data( $tracking_id, $page_id, $version_id, $start_date, $end_date, $segments = array(), $options = array() ) {
		global $qahm_file_functions, $qahm_data_api;

		// PVデータの取得
		$pv_data = $qahm_file_functions->get_pv_data_by_page_id( $tracking_id, $page_id, $start_date, $end_date );

		// 条件に一致するセッションを取得（ゴールデータ）
		$goal_sessions = array();
		if ( isset( $segments['is_goal'] ) && $segments['is_goal'] ) {
			$dateterm           = 'date = between ' . $start_date . ' and ' . $end_date;
			$all_goals_sessions = $qahm_data_api->get_goals_sessions( $dateterm, $tracking_id );

			// ゴールセッションのPV IDをマッピング
			foreach ( $all_goals_sessions as $goals_sessions ) {
				foreach ( $goals_sessions as $goal_session ) {
					foreach ( $goal_session as $session ) {
						$goal_sessions[ $session['pv_id'] ] = true;
					}
				}
			}
		}

		// セグメントごとにPVをグループ化
		$segmented_pv_data = array();

		foreach ( $pv_data as $pv ) {
			// セグメントキーの作成
			$utm_medium = isset( $pv['utm_medium'] ) ? $pv['utm_medium'] : '(not set)';

			$utm_source    = isset( $pv['utm_source'] ) ? $pv['utm_source'] : null;
			$source_domain = isset( $pv['source_domain'] ) ? $pv['source_domain'] : '(not set)';
			$source        = ! empty( $utm_source ) ? $utm_source : $source_domain;

			$utm_campaign = isset( $pv['utm_campaign'] ) ? $pv['utm_campaign'] : '(not set)';

			$is_goal = isset( $goal_sessions[ $pv['pv_id'] ] ) ? '○' : '×';

			// セグメントキー
			$segment_key = $utm_medium . '_' . $source . '_' . $utm_campaign . '_' . $is_goal;

			// セグメントごとにPVをグループ化
			if ( ! isset( $segmented_pv_data[ $segment_key ] ) ) {
				$segmented_pv_data[ $segment_key ] = array();
			}

			$segmented_pv_data[ $segment_key ][] = $pv;
		}

		// セグメントごとにクリックデータを集計
		$result = array();

		foreach ( $segmented_pv_data as $segment_key => $pvs ) {
			// セグメントキーを分解
			list($utm_medium, $source, $utm_campaign, $is_goal) = $this->wrap_explode( '_', $segment_key );

			// フィルター条件を構築
			$filters = static::wrap_array_merge_static(
				$options,
				array(
					'utm_media'    => array( $utm_medium ),
					'utm_source'   => array( $source ),
					'utm_campaign' => array( $utm_campaign ),
					'is_goal'      => ( $is_goal === '○' ),
				)
			);

			// file_functionsのget_page_click_dataを使用
			$click_data = $qahm_file_functions->get_page_click_data( $tracking_id, $page_id, $version_id, $start_date, $end_date, $filters );

			// セグメント情報を追加
			$click_data['segment_info'] = array(
				'utm_medium'   => $utm_medium,
				'source'       => $source,
				'utm_campaign' => $utm_campaign,
				'is_goal'      => $is_goal,
			);

			$result[ $segment_key ] = $click_data;
		}

		return $result;
	}
}
