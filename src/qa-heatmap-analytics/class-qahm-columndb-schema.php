<?php
/**
 * 列DB スキーマ定義クラス
 *
 * 各データセットのカラム定義と型情報を管理する。
 *
 * @package qa_heatmap
 */

class QAHM_ColumnDB_Schema {

    /**
     * データセットID定数
     */
    const DATASET_ALLPV = 1;
    const DATASET_CLICK_EVENT = 2;
    const DATASET_DATALAYER_EVENT = 3;
    const DATASET_GSC = 4;
    const DATASET_GA4_AGE_GENDER = 8;
    const DATASET_GA4_COUNTRY = 9;
    const DATASET_GA4_REGION = 10;

    /**
     * データ型定数
     */
    const TYPE_UINT32 = 'uint32';  // 4バイト
    const TYPE_UINT16 = 'uint16';  // 2バイト
    const TYPE_UINT8  = 'uint8';   // 1バイト

    /**
     * allpv スキーマ定義
     *
     * カラム名 => [
     *     'type'     => データ型,
     *     'bytes'    => バイト数,
     *     'nullable' => NULL許容フラグ,
     *     'default'  => デフォルト値（nullableの場合）
     * ]
     */
    const SCHEMA_ALLPV = [
        'pv_id' => [
            'type'     => self::TYPE_UINT32,
            'bytes'    => 4,
            'nullable' => false,
        ],
        'session_id' => [
            'type'     => self::TYPE_UINT32,
            'bytes'    => 4,
            'nullable' => false,
        ],
        'reader_id' => [
            'type'     => self::TYPE_UINT32,
            'bytes'    => 4,
            'nullable' => false,
        ],
        'page_id' => [
            'type'     => self::TYPE_UINT32,
            'bytes'    => 4,
            'nullable' => false,
        ],
        'device_id' => [
            'type'     => self::TYPE_UINT8,
            'bytes'    => 1,
            'nullable' => false,
        ],
        'source_id' => [
            'type'     => self::TYPE_UINT16,
            'bytes'    => 2,
            'nullable' => true,
            'default'  => 0,
        ],
        'medium_id' => [
            'type'     => self::TYPE_UINT8,
            'bytes'    => 1,
            'nullable' => true,
            'default'  => 0,
        ],
        'campaign_id' => [
            'type'     => self::TYPE_UINT16,
            'bytes'    => 2,
            'nullable' => true,
            'default'  => 0,
        ],
        'content_id' => [
            'type'     => self::TYPE_UINT16,
            'bytes'    => 2,
            'nullable' => true,
            'default'  => 0,
        ],
        'access_time' => [
            'type'     => self::TYPE_UINT32,
            'bytes'    => 4,
            'nullable' => false,
        ],
        'pv' => [
            'type'     => self::TYPE_UINT16,
            'bytes'    => 2,
            'nullable' => false,
        ],
        'speed_msec' => [
            'type'     => self::TYPE_UINT16,
            'bytes'    => 2,
            'nullable' => true,
            'default'  => 0,
        ],
        'browse_sec' => [
            'type'     => self::TYPE_UINT16,
            'bytes'    => 2,
            'nullable' => true,
            'default'  => 0,
        ],
        'is_last' => [
            'type'     => self::TYPE_UINT8,
            'bytes'    => 1,
            'nullable' => false,
        ],
        'is_newuser' => [
            'type'     => self::TYPE_UINT8,
            'bytes'    => 1,
            'nullable' => false,
        ],
        'version_id' => [
            'type'     => self::TYPE_UINT16,
            'bytes'    => 2,
            'nullable' => true,
            'default'  => 0,
        ],
        // --- 行動カラム C-1: raw_p由来 (5カラム) ---
        'depth_position' => [
            'type'     => self::TYPE_UINT8,
            'bytes'    => 1,
            'nullable' => true,
            'default'  => 0,
        ],
        'deep_read' => [
            'type'     => self::TYPE_UINT8,
            'bytes'    => 1,
            'nullable' => true,
            'default'  => 0,
        ],
        'stop_max_sec' => [
            'type'     => self::TYPE_UINT16,
            'bytes'    => 2,
            'nullable' => true,
            'default'  => 0,
        ],
        'stop_max_pos' => [
            'type'     => self::TYPE_UINT8,
            'bytes'    => 1,
            'nullable' => true,
            'default'  => 0,
        ],
        'exit_pos' => [
            'type'     => self::TYPE_UINT8,
            'bytes'    => 1,
            'nullable' => true,
            'default'  => 0,
        ],
        // --- 行動カラム C-2: raw_c由来 (3カラム) ---
        'is_submit' => [
            'type'     => self::TYPE_UINT8,
            'bytes'    => 1,
            'nullable' => true,
            'default'  => 0,
        ],
        'dead_click_image_count' => [
            'type'     => self::TYPE_UINT8,
            'bytes'    => 1,
            'nullable' => true,
            'default'  => 0,
        ],
        'irritation_click_count' => [
            'type'     => self::TYPE_UINT8,
            'bytes'    => 1,
            'nullable' => true,
            'default'  => 0,
        ],
        // --- 行動カラム C-3: raw_e由来 (3カラム) ---
        'scroll_back_count' => [
            'type'     => self::TYPE_UINT8,
            'bytes'    => 1,
            'nullable' => true,
            'default'  => 0,
        ],
        'content_skip_count' => [
            'type'     => self::TYPE_UINT8,
            'bytes'    => 1,
            'nullable' => true,
            'default'  => 0,
        ],
        'exploration_count' => [
            'type'     => self::TYPE_UINT8,
            'bytes'    => 1,
            'nullable' => true,
            'default'  => 0,
        ],
        // --- 遷移カラム: prev/next ページID ---
        'prev_page_id' => [
            'type'     => self::TYPE_UINT32,
            'bytes'    => 4,
            'nullable' => true,
            'default'  => 0,
        ],
        'next_page_id' => [
            'type'     => self::TYPE_UINT32,
            'bytes'    => 4,
            'nullable' => true,
            'default'  => 0,
        ],
    ];

    /**
     * click_event スキーマ定義（Phase 1: 14カラム・34バイト/行）
     *
     * raw_cフィールド → 列DBカラムの対応は design-document.md 付録B 参照
     */
    const SCHEMA_CLICK_EVENT = [
        'pv_id' => [
            'type'     => self::TYPE_UINT32,
            'bytes'    => 4,
            'nullable' => false,
        ],
        'session_id' => [
            'type'     => self::TYPE_UINT32,
            'bytes'    => 4,
            'nullable' => false,
        ],
        'page_id' => [
            'type'     => self::TYPE_UINT32,
            'bytes'    => 4,
            'nullable' => false,
        ],
        'event_sec' => [
            'type'     => self::TYPE_UINT16,
            'bytes'    => 2,
            'nullable' => false,
        ],
        'selector_id' => [
            'type'     => self::TYPE_UINT32,
            'bytes'    => 4,
            'nullable' => false,
        ],
        'element_text_id' => [
            'type'     => self::TYPE_UINT16,
            'bytes'    => 2,
            'nullable' => true,
            'default'  => 0,
        ],
        'element_id_id' => [
            'type'     => self::TYPE_UINT16,
            'bytes'    => 2,
            'nullable' => true,
            'default'  => 0,
        ],
        'element_class_id' => [
            'type'     => self::TYPE_UINT16,
            'bytes'    => 2,
            'nullable' => true,
            'default'  => 0,
        ],
        'element_data_id' => [
            'type'     => self::TYPE_UINT16,
            'bytes'    => 2,
            'nullable' => true,
            'default'  => 0,
        ],
        'to_url_id' => [
            'type'     => self::TYPE_UINT16,
            'bytes'    => 2,
            'nullable' => true,
            'default'  => 0,
        ],
        'is_external' => [
            'type'     => self::TYPE_UINT8,
            'bytes'    => 1,
            'nullable' => false,
            'default'  => 0,
        ],
        'action_id' => [
            'type'     => self::TYPE_UINT8,
            'bytes'    => 1,
            'nullable' => false,
        ],
        'page_x_pct' => [
            'type'     => self::TYPE_UINT16,
            'bytes'    => 2,
            'nullable' => false,
        ],
        'page_y_pct' => [
            'type'     => self::TYPE_UINT16,
            'bytes'    => 2,
            'nullable' => false,
        ],
    ];

    /**
     * datalayer_event スキーマ定義（Layer 1: 5カラム・16バイト/行）
     *
     * raw_gイベントの統合インデックス。設計書 04-4-column-db-datalayer.md 参照
     */
    const SCHEMA_DATALAYER_EVENT = [
        'pv_id' => [
            'type'     => self::TYPE_UINT32,
            'bytes'    => 4,
            'nullable' => false,
        ],
        'session_id' => [
            'type'     => self::TYPE_UINT32,
            'bytes'    => 4,
            'nullable' => false,
        ],
        'page_id' => [
            'type'     => self::TYPE_UINT32,
            'bytes'    => 4,
            'nullable' => false,
        ],
        'event_name_id' => [
            'type'     => self::TYPE_UINT16,
            'bytes'    => 2,
            'nullable' => false,
        ],
        'params_id' => [
            'type'     => self::TYPE_UINT16,
            'bytes'    => 2,
            'nullable' => false,
        ],
    ];

    /**
     * GSC スキーマ定義
     */
    const SCHEMA_GSC = [
        'page_id' => [
            'type'     => self::TYPE_UINT32,
            'bytes'    => 4,
            'nullable' => false,
        ],
        'query_id' => [
            'type'     => self::TYPE_UINT32,
            'bytes'    => 4,
            'nullable' => false,
        ],
        'search_type' => [
            'type'     => self::TYPE_UINT8,
            'bytes'    => 1,
            'nullable' => false,
        ],
        'clicks' => [
            'type'     => self::TYPE_UINT32,
            'bytes'    => 4,
            'nullable' => false,
        ],
        'impressions' => [
            'type'     => self::TYPE_UINT32,
            'bytes'    => 4,
            'nullable' => false,
        ],
        'position_x100' => [
            'type'     => self::TYPE_UINT16,
            'bytes'    => 2,
            'nullable' => false,
        ],
    ];

    /**
     * GA4 age_gender スキーマ定義
     */
    const SCHEMA_GA4_AGE_GENDER = [
        'age_bracket' => [
            'type'     => self::TYPE_UINT8,
            'bytes'    => 1,
            'nullable' => false,
        ],
        'gender' => [
            'type'     => self::TYPE_UINT8,
            'bytes'    => 1,
            'nullable' => false,
        ],
        'sessions' => [
            'type'     => self::TYPE_UINT32,
            'bytes'    => 4,
            'nullable' => false,
        ],
        'active_users' => [
            'type'     => self::TYPE_UINT32,
            'bytes'    => 4,
            'nullable' => false,
        ],
    ];

    /**
     * GA4 country スキーマ定義
     */
    const SCHEMA_GA4_COUNTRY = [
        'country_id' => [
            'type'     => self::TYPE_UINT16,
            'bytes'    => 2,
            'nullable' => false,
        ],
        'sessions' => [
            'type'     => self::TYPE_UINT32,
            'bytes'    => 4,
            'nullable' => false,
        ],
        'active_users' => [
            'type'     => self::TYPE_UINT32,
            'bytes'    => 4,
            'nullable' => false,
        ],
    ];

    /**
     * GA4 region スキーマ定義
     */
    const SCHEMA_GA4_REGION = [
        'region_id' => [
            'type'     => self::TYPE_UINT16,
            'bytes'    => 2,
            'nullable' => false,
        ],
        'sessions' => [
            'type'     => self::TYPE_UINT32,
            'bytes'    => 4,
            'nullable' => false,
        ],
        'active_users' => [
            'type'     => self::TYPE_UINT32,
            'bytes'    => 4,
            'nullable' => false,
        ],
    ];

    /**
     * スキーマ定義を取得
     *
     * @param int $dataset_id データセットID
     * @return array|null スキーマ定義
     */
    public static function get_schema( int $dataset_id ): ?array {
        switch ( $dataset_id ) {
            case self::DATASET_ALLPV:
                return self::SCHEMA_ALLPV;
            case self::DATASET_CLICK_EVENT:
                return self::SCHEMA_CLICK_EVENT;
            case self::DATASET_DATALAYER_EVENT:
                return self::SCHEMA_DATALAYER_EVENT;
            case self::DATASET_GSC:
                return self::SCHEMA_GSC;
            case self::DATASET_GA4_AGE_GENDER:
                return self::SCHEMA_GA4_AGE_GENDER;
            case self::DATASET_GA4_COUNTRY:
                return self::SCHEMA_GA4_COUNTRY;
            case self::DATASET_GA4_REGION:
                return self::SCHEMA_GA4_REGION;
            default:
                return null;
        }
    }

    /**
     * データセット名からIDを取得
     *
     * @param string $name データセット名
     * @return int|null データセットID
     */
    public static function get_dataset_id( string $name ): ?int {
        $map = [
            'allpv'            => self::DATASET_ALLPV,
            'click_event'      => self::DATASET_CLICK_EVENT,
            'datalayer_event'  => self::DATASET_DATALAYER_EVENT,
            'gsc'              => self::DATASET_GSC,
            'ga4_age_gender'   => self::DATASET_GA4_AGE_GENDER,
            'ga4_country'      => self::DATASET_GA4_COUNTRY,
            'ga4_region'       => self::DATASET_GA4_REGION,
        ];
        return $map[ $name ] ?? null;
    }

    /**
     * データセットIDから名前を取得
     *
     * @param int $id データセットID
     * @return string|null データセット名
     */
    public static function get_dataset_name( int $id ): ?string {
        $map = [
            self::DATASET_ALLPV            => 'allpv',
            self::DATASET_CLICK_EVENT      => 'click_event',
            self::DATASET_DATALAYER_EVENT  => 'datalayer_event',
            self::DATASET_GSC              => 'gsc',
            self::DATASET_GA4_AGE_GENDER   => 'ga4_age_gender',
            self::DATASET_GA4_COUNTRY      => 'ga4_country',
            self::DATASET_GA4_REGION       => 'ga4_region',
        ];
        return $map[ $id ] ?? null;
    }

    /**
     * カラムのバイト数を取得
     *
     * @param int $dataset_id データセットID
     * @param string $column_name カラム名
     * @return int|null バイト数
     */
    public static function get_column_bytes( int $dataset_id, string $column_name ): ?int {
        $schema = self::get_schema( $dataset_id );
        if ( $schema === null ) {
            return null;
        }
        return $schema[ $column_name ]['bytes'] ?? null;
    }

    /**
     * 1行あたりの合計バイト数を計算
     *
     * @param int $dataset_id データセットID
     * @return int|null バイト数
     */
    public static function get_row_bytes( int $dataset_id ): ?int {
        $schema = self::get_schema( $dataset_id );
        if ( $schema === null ) {
            return null;
        }

        $total = 0;
        foreach ( $schema as $column ) {
            $total += $column['bytes'];
        }
        return $total;
    }

    /**
     * カラム一覧を取得
     *
     * @param int $dataset_id データセットID
     * @return array|null カラム名の配列
     */
    public static function get_column_names( int $dataset_id ): ?array {
        $schema = self::get_schema( $dataset_id );
        if ( $schema === null ) {
            return null;
        }
        return array_keys( $schema );
    }
}
