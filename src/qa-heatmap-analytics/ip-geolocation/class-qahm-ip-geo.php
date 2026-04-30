<?php
defined( 'ABSPATH' ) || exit;
/**
 * IP地理的位置特定クラス
 * IPアドレスから国コード（ISO 3166-1 alpha-2）を取得する
 *
 * @package qa_heatmap
 */

class QAHM_IP_Geolocation extends QAHM_Base {

    /**
     * CSVファイルのパス
     */
    private static $csv_file_path = null;

    /**
     * APCuキャッシュのキープレフィックス
     */
    private const CACHE_PREFIX = 'qahm_ip_geo_';
    
    /**
     * CSV全体キャッシュのキー
     */
    private const CSV_CACHE_KEY = 'qahm_ip_geo_csv_data';

    /**
     * キャッシュの有効期限（秒）
     */
    private const CACHE_TTL = 3600; // 1時間

    /**
     * 初期化処理
     */
    private static function init() {
        if (self::$csv_file_path === null) {
            self::$csv_file_path = dirname(__FILE__) . '/asn-country-ipv4-num.csv';
        }
    }

    /**
     * IPアドレスから国情報を取得
     * 
     * @param string $ip_address IPアドレス
     * @return array|null ['country_code' => 'JP', 'continent_code' => 'AS'] または null
     */
    public static function get_country_from_ip($ip_address) {
        if (empty($ip_address)) {
            return null;
        }

        $ip_long = ip2long($ip_address);
        if ($ip_long === false) {
            return null;
        }

        if (filter_var($ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        self::init();

        $csv_data = self::get_cached_csv_data();
        if ($csv_data === null) {
            return null;
        }

        $result = self::binary_search_ip_range($ip_long, $csv_data);

        return $result;
    }

    /**
     * CSV全体をAPCuキャッシュから取得または読み込み
     * 
     * @return array|null CSV データの配列または null
     */
    private static function get_cached_csv_data() {
        if (function_exists('apcu_fetch')) {
            $cached_data = apcu_fetch(self::CSV_CACHE_KEY);
            if ($cached_data !== false) {
                return $cached_data;
            }
        }

        $csv_data = self::load_csv_data();
        
        if (function_exists('apcu_store') && $csv_data !== null) {
            apcu_store(self::CSV_CACHE_KEY, $csv_data, self::CACHE_TTL);
        }

        return $csv_data;
    }

    /**
     * CSVファイルからデータを読み込み
     * 
     * @return array|null CSV データの配列または null
     */
    private static function load_csv_data() {
        if (!file_exists(self::$csv_file_path)) {
            return null;
        }

        // This AJAX endpoint runs in SHORTINIT mode where WP_Filesystem cannot be initialized.
        // Direct fopen/fclose calls are allowed here because this operation is read-only and poses no security risk.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        $handle = fopen(self::$csv_file_path, 'r');
        if ($handle === false) {
            return null;
        }

        fgetcsv($handle);

        $csv_data = [];
        while (($data = fgetcsv($handle)) !== false) {
            if (static::wrap_count_static($data) >= 3) {
                $csv_data[] = [
                    'start' => (int)$data[0],
                    'end' => (int)$data[1],
                    'country_code' => static::wrap_trim_static($data[2])
                ];
            }
        }

        // Closing the handle safely; WP_Filesystem is unavailable in SHORTINIT mode.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($handle);
        
        usort($csv_data, function($a, $b) {
            return $a['start'] <=> $b['start'];
        });

        return $csv_data;
    }

    /**
     * バイナリサーチでIP範囲を高速検索
     * 
     * @param int $ip_long IPアドレスのlong値
     * @param array $csv_data CSV データの配列
     * @return array|null 国情報または null
     */
    private static function binary_search_ip_range($ip_long, $csv_data) {
        $left = 0;
        $right = static::wrap_count_static($csv_data) - 1;

        while ($left <= $right) {
            $mid = intval(($left + $right) / 2);
            $range = $csv_data[$mid];

            if ($ip_long >= $range['start'] && $ip_long <= $range['end']) {
                return [
                    'country_code' => $range['country_code'],
                    'continent_code' => self::get_continent_from_country($range['country_code'])
                ];
            } elseif ($ip_long < $range['start']) {
                $right = $mid - 1;
            } else {
                $left = $mid + 1;
            }
        }

        return null;
    }

    /**
     * 国コードから大陸コードを取得
     * 
     * @param string $country_code 国コード
     * @return string 大陸コード
     */
    private static function get_continent_from_country($country_code) {
        $continent_map = [
            'JP' => 'AS', 'CN' => 'AS', 'KR' => 'AS', 'IN' => 'AS', 'TH' => 'AS',
            'VN' => 'AS', 'PH' => 'AS', 'ID' => 'AS', 'MY' => 'AS', 'SG' => 'AS',
            'US' => 'NA', 'CA' => 'NA', 'MX' => 'NA',
            'GB' => 'EU', 'DE' => 'EU', 'FR' => 'EU', 'IT' => 'EU', 'ES' => 'EU',
            'NL' => 'EU', 'SE' => 'EU', 'NO' => 'EU', 'DK' => 'EU', 'FI' => 'EU',
            'AU' => 'OC', 'NZ' => 'OC',
            'BR' => 'SA', 'AR' => 'SA', 'CL' => 'SA', 'PE' => 'SA',
            'ZA' => 'AF', 'EG' => 'AF', 'NG' => 'AF', 'KE' => 'AF'
        ];

        return isset($continent_map[$country_code]) ? $continent_map[$country_code] : 'UN';
    }

    /**
     * CIDR記法でのIP範囲チェック
     * 
     * @param string $ip IPアドレス
     * @param string $cidr CIDR記法の範囲
     * @return bool 範囲内かどうか
     */
    private static function ip_in_cidr($ip, $cidr) {
        list($subnet, $mask) = static::wrap_explode_static('/', $cidr);
        
        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);
        $mask_long = -1 << (32 - (int)$mask);
        
        return ($ip_long & $mask_long) === ($subnet_long & $mask_long);
    }

    /**
     * キャッシュをクリア
     * 
     * @return bool 成功したかどうか
     */
    public static function clear_cache() {
        if (!function_exists('apcu_delete')) {
            return false;
        }

        return apcu_delete(self::CSV_CACHE_KEY);
    }

    /**
     * キャッシュ統計情報を取得
     * 
     * @return array|null キャッシュ情報または null
     */
    public static function get_cache_info() {
        if (!function_exists('apcu_fetch')) {
            return null;
        }

        $cached_data = apcu_fetch(self::CSV_CACHE_KEY);
        if ($cached_data === false) {
            return ['cached' => false, 'entries' => 0];
        }

        return [
            'cached' => true,
            'entries' => static::wrap_count_static($cached_data),
            'memory_usage' => static::wrap_strlen_static(serialize($cached_data))
        ];
    }
}
