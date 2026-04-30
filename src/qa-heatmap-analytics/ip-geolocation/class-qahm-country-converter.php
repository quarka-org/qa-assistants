<?php
defined( 'ABSPATH' ) || exit;
/**
 * 国コード変換クラス
 * ISO 3166-1 alpha-2国コードから国名に変換する
 *
 * @package qa_heatmap
 */

class QAHM_Country_Converter extends QAHM_Base {

    /**
     * 国名マッピングデータ
     */
    private static $country_names = null;

    /**
     * 初期化処理
     */
    private static function init() {
        if (self::$country_names === null) {
            $countries_file = dirname(__FILE__) . '/countries.php';
            if (file_exists($countries_file)) {
                self::$country_names = include $countries_file;
            } else {
                self::$country_names = [];
            }
        }
    }

    /**
     * 国コードから国名に変換
     * 
     * @param string $country_code ISO 3166-1 alpha-2コード
     * @return string 国名（見つからない場合は国コードをそのまま返す）
     */
    public static function get_country_name($country_code) {
        if (empty($country_code)) {
            return '';
        }

        self::init();

        $country_code = strtoupper($country_code);
        
        if (isset(self::$country_names[$country_code])) {
            $country_key = self::$country_names[$country_code];
            return $country_key;
        }

        return $country_code;
    }

    /**
     * 利用可能な全ての国コードを取得
     * 
     * @return array 国コードの配列
     */
    public static function get_available_country_codes() {
        self::init();
        return array_keys(self::$country_names);
    }

    /**
     * 利用可能な全ての国名を取得
     * 
     * @return array ['国コード' => '国名'] の配列
     */
    public static function get_all_countries() {
        self::init();
        
        $result = [];
        foreach (self::$country_names as $code => $country_key) {
            $result[$code] = self::get_country_name($code);
        }
        
        return $result;
    }

    /**
     * 国名から国コードを逆引き検索
     * 
     * @param string $country_name 国名
     * @return string|null 国コード（見つからない場合はnull）
     */
    public static function get_country_code_by_name($country_name) {
        if (empty($country_name)) {
            return null;
        }

        self::init();

        foreach (self::$country_names as $code => $country_key) {
            $translated_name = $country_key;
            if ($translated_name === $country_name) {
                return $code;
            }
        }

        return null;
    }

    /**
     * 大陸コードから大陸名を取得
     * 
     * @param string $continent_code 大陸コード
     * @return string 大陸名
     */
    public static function get_continent_name($continent_code) {
        $continent_names = [
            'AS' => 'Asia',
            'EU' => 'Europe', 
            'NA' => 'North America',
            'SA' => 'South America',
            'AF' => 'Africa',
            'OC' => 'Oceania',
            'AN' => 'Antarctica',
            'UN' => 'Unknown'
        ];

        if (isset($continent_names[$continent_code])) {
            return $continent_names[$continent_code];
        }

        return $continent_code;
    }

    /**
     * 国コードの妥当性をチェック
     * 
     * @param string $country_code 国コード
     * @return bool 妥当かどうか
     */
    public static function is_valid_country_code($country_code) {
        if (empty($country_code) || static::wrap_strlen_static($country_code) !== 2) {
            return false;
        }

        self::init();
        return isset(self::$country_names[strtoupper($country_code)]);
    }
}
