<?php
defined( 'ABSPATH' ) || exit;
/**
 * 世界のロケールにあわせた日付時刻を返すためのクラス。
 * 基本的な考え方としては、unixtimeを使ってUTC基準の絶対値で計算し、2038年問題に対応するdatetime classを使ってロケールなどを加工する（本classは2038年問題に対応できることをデバッグ済み）。
 * 多くのシステムではUTCがdefault_timezoneになっているので、WordPressのロケールを活用してあわせていく。
 * なおunixtimeは32bitサーバー上で動く場合にint 32bitで扱われ、phpもそれにあわせてmakeされるため2038年問題が発生する。64bit環境で動くPHPであればtimestampもint 64bitで扱うため問題は発生しない（つまりサーバー依存）。
 * @package qa_heatmap
 */

$GLOBALS['qahm_time'] = new QAHM_Time();
class QAHM_Time {
	/**
	 *
	 */
	const DEFAULT_DATE_FORMAT     = 'Y-m-d';
	const DEFAULT_DATETIME_FORMAT = 'Y-m-d H:i:s';
	const DEFAULT_TIME_FORMAT     = 'H:i:s';
	const DEFAULT_TIME_DELIMITER  = ':';

	public $timezone_string;
	public $utc_offset;
	public $timezone_obj;

	/**
	 * このインスタンスの TZ が「計測サイトに明示設定された確定値」かどうか。
	 * get_site_clock() がサイトTZを解決できたとき true、WP-TZ 代替時は false。
	 * （未確認可視化・候補4b 検知の将来フック用。グローバル singleton では false 固定）
	 */
	public $tz_confirmed = false;

	/**
	 * get_site_clock() の per-tracking_id インスタンスキャッシュ（リクエスト内メモ化）
	 */
	private static $clock_cache = array();

	/**
	 * @param string|null $timezone IANA タイムゾーン識別子。null/空ならインストールの WP タイムゾーン。
	 *                              計測サイトTZは get_site_clock() 経由で渡る。
	 */
	public function __construct( $timezone = null ) {
		// 公開プロパティ utc_offset は従来どおりインストールの WP オフセットを保持（後方互換のため維持）
		$this->utc_offset = get_option( 'gmt_offset' );

		if ( ! empty( $timezone ) ) {
			// サイトTZ経路（IANA 文字列）
			$this->timezone_obj    = new DateTimeZone( $timezone );
			$this->timezone_string = $timezone;
		} else {
			// WP-TZ経路（引数なし＝従来どおりのグローバル singleton）。
			// wp_timezone() に委譲し、旧実装の自前オフセット組み立て（gmt_offset が小数の
			// インド +5:30 / ネパール +5:45 等で不正な DateTimeZone を生む潜在クラッシュ）を根絶する。
			$this->timezone_obj    = wp_timezone();
			$this->timezone_string = wp_timezone_string();
		}
	}

	/**
	 * 計測サイト（tracking_id）の TZ に束ねた時計インスタンスを返すファクトリ。
	 * 暦日・表示系メソッドはこのインスタンス経由で呼ぶことで自動的にサイトTZ動作になる。
	 * 瞬間/UTC 系（now_unixtime 等）はサイト非依存なのでグローバル $qahm_time のままでよい。
	 *
	 * @param string $tracking_id 計測サイト識別子
	 * @return QAHM_Time サイトTZ（未解決時は WP-TZ 代替）に束ねたインスタンス
	 */
	public static function get_site_clock( $tracking_id ) {
		// 'all'（全サイト横断集計）は、東京の月曜と NY の月曜が別瞬間になるため
		// 原理的に単一の暦日を持てない。WP-TZ を「ダッシュボードの暦」として意図的に
		// 採用し、グローバル singleton をそのまま返す。未解決サイトと同じ沈黙フォール
		// バック経路に乗せず、ここで明示的に短絡することで「設計判断としての WP-TZ」と
		// 「サイトTZ未解決の代替」を取り違えない（4b 検知の誤爆も防ぐ）。#1153
		if ( 'all' === $tracking_id ) {
			global $qahm_time;
			if ( $qahm_time instanceof self ) {
				return $qahm_time;
			}
			// 通常は file ロード時に初期化済み。未初期化の保険として以降の WP-TZ 解決へ落とす。
		}

		if ( isset( self::$clock_cache[ $tracking_id ] ) ) {
			return self::$clock_cache[ $tracking_id ];
		}

		list( $tz, $confirmed ) = self::resolve_site_tz( $tracking_id );
		try {
			$clock = new self( $tz );
		} catch ( Exception $e ) {
			// 不正な TZ 文字列でも止めない（1サイトのミスで cron 全滅を防ぐ）。WP-TZ 退避＋未確認扱い。
			global $qahm_log;
			if ( isset( $qahm_log ) ) {
				$qahm_log->warning( 'QAHM_Time::get_site_clock invalid timezone "' . $tz . '" for tracking_id ' . $tracking_id . ', fell back to WP timezone.' );
			}
			$clock     = new self( null );
			$confirmed = false;
		}
		$clock->tz_confirmed = $confirmed;

		self::$clock_cache[ $tracking_id ] = $clock;
		return $clock;
	}

	/**
	 * tracking_id から計測サイトの TZ を解決する。
	 * 沈黙フォールバックを避けるため「明示確定か WP-TZ 代替か」を確定フラグで区別して返す。
	 *
	 * @param string $tracking_id
	 * @return array [ string|null $timezone, bool $confirmed ]
	 *               明示設定あり → [ IANA文字列, true ] ／ 未設定・サイト不在 → [ null, false ]
	 */
	private static function resolve_site_tz( $tracking_id ) {
		global $qahm_data_api;
		if ( isset( $qahm_data_api ) && is_object( $qahm_data_api ) && method_exists( $qahm_data_api, 'get_sitemanage' ) ) {
			foreach ( (array) $qahm_data_api->get_sitemanage() as $site ) {
				// tracking_id の比較は本コードベースの慣習にあわせ loose（==）。型差での取りこぼしが
				// 黙って WP-TZ 代替になるのを避ける。
				if ( isset( $site['tracking_id'] ) && $site['tracking_id'] == $tracking_id ) {
					if ( ! empty( $site['timezone'] ) ) {
						return array( $site['timezone'], true ); // 明示確定
					}
					break; // サイトは在るが timezone 未設定 → 代替へ
				}
			}
		}
		return array( null, false ); // 未設定 or サイト不在 → WP-TZ 代替（確定フラグ false）
	}

	/**
	 * sitemanage の timezone フィールドに「保存してよい値」を解決する（登録・バックフィル共通の単一窓口）。
	 *
	 * データ契約: timezone は常に「有効な IANA 文字列」か「キー未設定」のどちらか。
	 * '' やオフセット文字列（+09:00 等）は決して保存しない。
	 * 本メソッドは保存可能な IANA 文字列を返すか、保存すべき値が無いとき '' を返す。
	 * 呼び出し側は '' を受け取ったら timezone キー自体を書かない（未設定のまま＝実行時 WP-TZ フォールバック）。
	 *
	 * 解決順:
	 *   1. 明示候補 $candidate が IANA 一覧に含まれれば、それを採用（ZERO タグ発行 UI 等で別市場サイトの TZ を選択する経路）。
	 *   2. それ以外は get_option('timezone_string') を見る。WP で「都市」設定なら IANA が入っているので採用。
	 *      WP が「手動オフセット」設定（都市未選択）なら空 → '' を返す（＝未設定にする）。
	 *
	 * timezone_string は WP コアの仕様上、非空なら必ず IANA だが、念のため一覧で検証してから返す（堅牢化）。
	 * 共有(core)・型非依存: timezone_identifiers_list() / get_option() は WP・PHP 標準で QAHM_TYPE に依存しない。
	 *
	 * @param string|null $candidate 明示指定の TZ 候補（UI 選択値など）。null/空なら WP-TZ から解決。
	 * @return string 保存してよい IANA 文字列、または保存すべき値が無いとき ''（呼び出し側はキーを書かない）。
	 */
	public static function resolve_store_timezone( $candidate = null ) {
		$iana_list = timezone_identifiers_list();

		// 1. 明示候補（IANA のみ採用。手動オフセット文字列等は弾く）
		if ( ! empty( $candidate ) && in_array( $candidate, $iana_list, true ) ) {
			return $candidate;
		}

		// 2. WP インストールの timezone_string（都市設定なら IANA、手動オフセットなら空）
		$tz_string = get_option( 'timezone_string' );
		if ( ! empty( $tz_string ) && in_array( $tz_string, $iana_list, true ) ) {
			return $tz_string;
		}

		// 保存すべき確定 IANA 値が無い → キーを書かない（実行時に WP-TZ フォールバック）
		return '';
	}

	/**
	 * このインスタンスの TZ 文字列を返す。JS ペイロード同梱・表示整形用。
	 * 返却値は通常 IANA 識別子（例: Asia/Tokyo）。ただし WP-TZ 代替経路で WP インストールが
	 * timezone_string 未設定（gmt_offset のみ）の場合、wp_timezone_string() は UTC オフセット文字列
	 * （例: +09:00）を返すため、本メソッドもそれを返しうる（IANA ではない）。
	 * → IANA 前提の消費側（JS dayjs.tz / Intl.DateTimeFormat 等）はこのケースに注意（Phase 3 申し送り）。
	 * @return string IANA 識別子（例: Asia/Tokyo）または UTC オフセット（例: +09:00）
	 */
	public function get_site_timezone() {
		return $this->timezone_string;
	}

	/**
	 * このインスタンスの TZ が計測サイトの明示確定値か（true）、WP-TZ 代替か（false）。
	 */
	public function is_timezone_confirmed() {
		return $this->tz_confirmed;
	}

	/**
	 * 現在年の数値
	 */
	public function year( $datetime_str = 'now' ) {
		$d = new DateTime( $datetime_str, $this->timezone_obj );
		return (int) $d->format( 'Y' );
	}

	/**
	 * 現在月の数値（先頭にゼロなし）
	 */
	public function month( $datetime_str = 'now' ) {
		$d = new DateTime( $datetime_str, $this->timezone_obj );
		return (int) $d->format( 'n' );
	}

	/**
	 * 現在月の数値（先頭にゼロあり）
	 */
	public function monthstr( $datetime_str = 'now' ) {
		$d = new DateTime( $datetime_str, $this->timezone_obj );
		return $d->format( 'm' );
	}

	/**
	 * 現在日の数値
	 */
	public function day( $datetime_str = 'now' ) {
		$d = new DateTime( $datetime_str, $this->timezone_obj );
		return (int) $d->format( 'j' );
	}

	/**
	 * 現在時刻の数値
	 */
	public function hour( $datetime_str = 'now' ) {
		$d = new DateTime( $datetime_str, $this->timezone_obj );
		return (int) $d->format( 'G' );
	}

	/**
	 * 現在分の数値
	 */
	public function minute( $datetime_str = 'now' ) {
		$d = new DateTime( $datetime_str, $this->timezone_obj );
		return (int) $d->format( 'i' );
	}

	/**
	 * 本日の日付文字列
	 */
	public function today_str( $format = self::DEFAULT_DATE_FORMAT ) {
		$d = new DateTime( '', $this->timezone_obj );
		return $d->format( $format );
	}

	/**
	 * 現在の日付時刻文字列
	 */
	public function now_str( $format = self::DEFAULT_DATETIME_FORMAT ) {
		$d = new DateTime( '', $this->timezone_obj );
		return $d->format( $format );
	}

	/**
	 * 現在のunixtime
	 */
	public function now_unixtime() {
		$d = new DateTime( '', $this->timezone_obj );
		return $d->getTimestamp();
	}

	/**
	 * 現在月の日数の数値（月末日）
	 */
	public function month_daynum( $datetime_str = 'now' ) {
		$d = new DateTime( $datetime_str, $this->timezone_obj );
		return (int) $d->format( 't' );
	}

	/**
	 * 引数$datetime_strに対し、差分の日付を求める
	 * 引数$modifierには'+1 day'や'-1 month'などを入力
	 */
	public function diff_str( $datetime_str, $modifier, $format = self::DEFAULT_DATETIME_FORMAT ) {
		$date = new DateTime( $datetime_str, $this->timezone_obj );
		$date->modify( $modifier );
		return $date->format( $format );
	}


	/**
	 * 月の加減算をして日付文字列に
	 */
	public function xmonth_str( $months, $from_datetime_str = 'now', $format = self::DEFAULT_DATE_FORMAT ) {
		$d = new DateTime( $from_datetime_str, $this->timezone_obj );

		//次月からb_monを求める
		$d_year   = (int) $d->format( 'Y' );
		$d_monx   = (int) $d->format( 'n' );
		$d_dayx   = (int) $d->format( 'j' );
		$ret_hour = (int) $d->format( 'H' );
		$ret_minx = (int) $d->format( 'i' );
		$ret_secx = (int) $d->format( 's' );

		$n_monx = $d_monx + (int) $months;
		if ( $n_monx <= 0 ) {
			$plusminusyear = floor( ( $n_monx - 1 ) / 12 );
			$ret_monx      = $n_monx % 12;
			$ret_monx      = 12 + $ret_monx;
		} else {
			$plusminusyear = floor( $n_monx / 12 );
			$ret_monx      = $n_monx % 12;
		}
		$ret_year = $d_year + $plusminusyear;
		$ret_monx = sprintf( '%02d', $ret_monx );
		//最終日判定
		$lastday = ( new DateTimeImmutable() )->modify( 'last day of' . $ret_year . '-' . $ret_monx )->format( 'j' );
		if ( $d_dayx > $lastday ) {
			$ret_dayx = $lastday;
		} else {
			$ret_dayx = $d_dayx;
		}
		$ret_dayx = sprintf( '%02d', $ret_dayx );

		$dn = new DateTime( $ret_year . '-' . $ret_monx . '-' . $ret_dayx . ' ' . $ret_hour . ':' . $ret_minx . ':' . $ret_secx, $this->timezone_obj );
		return $dn->format( $format );
	}


	/**
	 * 日の加減算をして日付文字列に
	 */
	public function xday_str( $days, $from_datetime_str = 'now', $format = self::DEFAULT_DATE_FORMAT ) {
		$d             = new DateTime( $from_datetime_str, $this->timezone_obj );
		$interval_spec = 'P' . abs( $days ) . 'D';
		if ( $days >= 0 ) {
			$d->add( new DateInterval( $interval_spec ) );
		} else {
			$d->sub( new DateInterval( $interval_spec ) );
		}
		return $d->format( $format );
	}

	/**
	 * 引数に渡された２つの日付文字列の差分を求めて日数を返す
	 * $start_datetime_strを基軸に、$end_datetime_strが未来の日付であれば正の数、過去の日付であれば負の数が返る
	 */
	public function xday_num( $end_datetime_str, $start_datetime_str = 'now' ) {
		$start = new DateTime( $start_datetime_str, $this->timezone_obj );
		$end   = new DateTime( $end_datetime_str, $this->timezone_obj );
		$start->setTime( 0, 0 );
		$end->setTime( 0, 0 );
		$diff = $start->diff( $end );
		$ret  = (int) $diff->days;
		if ( $diff->invert === 1 ) {
			$ret = -$ret;
		}
		return $ret;
	}

	/**
	 * 引数に渡された２つの日付文字列の差分を求めて秒数を返す
	 * 対象の時間を過ぎていた場合はマイナスの値が返る
	 */
	public function xsec_num( $end_datetime_str, $start_datetime_str = 'now' ) {
		$start = new DateTime( $start_datetime_str, $this->timezone_obj );
		$end   = new DateTime( $end_datetime_str, $this->timezone_obj );
		return $end->getTimestamp() - $start->getTimestamp();
	}

	/**
	 * unixtimeから日付時刻
	 */
	public function unixtime_to_str( $unixtime, $format = self::DEFAULT_DATETIME_FORMAT ) {
		$d = new DateTime( '', $this->timezone_obj );
		$d->setTimestamp( $unixtime );
		return $d->format( $format );
	}

	/**
	 * WPのdate_i18n()でunixtimeをUTC基準ではなくロケール変換して保存した場合の関数。この場合はUTCに戻さないといけない
	 */
	public function wpunixtime_to_str( $unixtime, $format = self::DEFAULT_DATETIME_FORMAT ) {
		$t = new DateTimeZone( 'UTC' );
		$d = new DateTime( '', $t );
		$d->setTimestamp( $unixtime );
		return $d->format( $format );
	}

	/**
	 * 日付時刻からunixtime
	 *
	 * createFromFormat は時刻フィールドが未指定だと「実行時の現在時刻」で補完する（PHP仕様）。
	 * これだと date-only 文字列を渡したとき同一入力でも実行時刻で結果が揺れ、期間境界の比較で
	 * 最終日を取りこぼす等の非決定的バグになりうる（#1153）。本クラスの他メソッドは new DateTime
	 * 経由で「未指定＝深夜(00:00:00)」に揃っているため、ここも '!' を前置してエポック起点に固定し
	 * 挙動を統一する。全フィールドを明示した入力には影響しない no-op（現行の全呼び出し元が該当）。
	 */
	public function str_to_unixtime( $datetime_str, $format = self::DEFAULT_DATETIME_FORMAT ) {
		// 空・非文字列フォーマットは不正入力として弾く。'!' を付与すると createFromFormat が
		// epoch（unixtime 0）を返しうるため、本メソッドの「不正入力は false」契約に倒す。
		if ( ! is_string( $format ) || '' === $format ) {
			return false;
		}
		// 先頭が '!' でなければ前置（深夜起点に固定）。既に '!' 付きなら二重付与しない。
		if ( '!' !== $format[0] ) {
			$format = '!' . $format;
		}
		$d = DateTime::createFromFormat( $format, $datetime_str, $this->timezone_obj );
		// 不正フォーマットでは createFromFormat が false を返す。getTimestamp() で fatal を
		// 起こさないよう false を返し、呼び出し側で判定できるようにする。
		if ( false === $d ) {
			return false;
		}
		return $d->getTimestamp();
	}

	/**
	 * 計測サイトTZの1暦日 $ymd に対応する UTC 半開区間 [start, end) を返す。
	 * @param string $ymd 'Y-m-d'
	 * @return array|false [ int $start_utc, int $end_utc ]（UTC unixtime 秒・半開 start<=t<end）。不正入力は false。
	 */
	public function site_day_range_utc( $ymd ) {
		return $this->site_period_range_utc( $ymd, 'P1D' );
	}

	/**
	 * 計測サイトTZの $anchor_ymd 00:00 を起点に $interval_spec 進めた UTC 半開区間 [start, end) を返す。
	 * P1D=暦日・P7D=1週間・P1M=1ヶ月（いずれもカレンダー算術で DST 安全。PT24H 等の固定秒は使わない）。
	 * @param string $anchor_ymd 'Y-m-d'
	 * @param string $interval_spec DateInterval 仕様文字列
	 * @return array|false [ int $start_utc, int $end_utc ]（UTC unixtime 秒・半開 start<=t<end）。不正入力は false。
	 */
	public function site_period_range_utc( $anchor_ymd, $interval_spec = 'P1D' ) {
		global $qahm_log;
		// $anchor_ymd / $interval_spec が不正だと DateTimeImmutable / DateInterval が例外を投げる。
		// 本クラスの方針（fatal にせず log＋センチネル返し）に揃え、false を返して呼び出し側で判定可能にする。
		try {
			$start = new DateTimeImmutable( $anchor_ymd . ' 00:00:00', $this->timezone_obj );
			$end   = $start->add( new DateInterval( $interval_spec ) );
		} catch ( Exception $e ) {
			if ( isset( $qahm_log ) ) {
				$qahm_log->warning( 'QAHM_Time::site_period_range_utc invalid input: anchor=' . $anchor_ymd . ' interval=' . $interval_spec );
			}
			return false;
		}
		return array( $start->getTimestamp(), $end->getTimestamp() );
	}

	/**
	 * 計測サイトTZで $from_ymd〜$to_ymd（両端含む）の連続・ゼロ埋め日系列を返す。
	 * 各要素は [ 'ymd' => 'Y-m-d', 'start_utc' => int, 'end_utc' => int ]（半開・UTC秒）。
	 * 二役: (a) バケット済みデータの正準描画順 ／ (b) JS が非バケットデータを振り分けるカット表。
	 * 不正入力は fatal にせず空配列＋ログ。日送りもカレンダー算術（DST 安全・±86400 禁止）。
	 * @param string $from_ymd 'Y-m-d'
	 * @param string $to_ymd   'Y-m-d'
	 * @return array
	 */
	public function site_day_series( $from_ymd, $to_ymd ) {
		global $qahm_log;
		// 暦日のみ（'Y-m-d'）を受け付ける。is_date() は時刻付きも許可してしまい、
		// その場合 ' 00:00:00' 連結で DateTimeImmutable が例外を投げるため is_ymd() で厳密判定する。
		if ( ! $this->is_ymd( $from_ymd ) || ! $this->is_ymd( $to_ymd ) ) {
			if ( isset( $qahm_log ) ) {
				$qahm_log->warning( 'QAHM_Time::site_day_series invalid date range: ' . $from_ymd . ' .. ' . $to_ymd );
			}
			return array();
		}

		$cur  = new DateTimeImmutable( $from_ymd . ' 00:00:00', $this->timezone_obj );
		$stop = new DateTimeImmutable( $to_ymd . ' 00:00:00', $this->timezone_obj );
		$one  = new DateInterval( 'P1D' );
		$out  = array();
		while ( $cur <= $stop ) {
			$next  = $cur->add( $one );
			$out[] = array(
				'ymd'       => $cur->format( 'Y-m-d' ),
				'start_utc' => $cur->getTimestamp(),
				'end_utc'   => $next->getTimestamp(),
			);
			$cur = $next;
		}
		return $out;
	}

	/**
	 * 秒から時刻文字列
	 */
	public function seconds_to_timestr( $seconds, $delimiter = self::DEFAULT_TIME_DELIMITER ) {
		$hhh = floor( $seconds / 3600 );
		$mmm = floor( ( $seconds / 60 ) % 60 );
		$sss = floor( $seconds % 60 );

		/*
		if ( $seconds < 60 ) {
			$str = sprintf( '%d', $sss );
		} elseif ( $seconds < 3600 ) {
			$str = sprintf ( '%d' . $delimiter . '%02d', $mmm, $sss );
		} else {
			$str = sprintf ( '%d' . $delimiter . '%02d'. $delimiter . '%02d', $hhh, $mmm, $sss );
		}*/

		// ↑ 00:00:00のフォーマットに変更 imai
		$str = sprintf( '%02d', $hhh ) . $delimiter . sprintf( '%02d', $mmm ) . $delimiter . sprintf( '%02d', $sss );

		return $str;
	}

	/**
	 * 日付文字列が有効かチェックする
	 */
	public function is_date( $date_str ) {
		// nullや空文字列チェック
		if ( empty( $date_str ) ) {
			return false;
		}

		// 特殊な文字列は許可
		// 注: 本クラスは QAHM_Core_Base を継承していないため wrap_in_array() / wrap_strpos() は使えない。
		//     これらは native の薄いラッパーなので native 関数を直接使う（挙動等価・既存の潜在バグも解消）。
		$special_strings = array( 'now', 'today', 'yesterday', 'tomorrow' );
		if ( in_array( $date_str, $special_strings, true ) ) {
			return true;
		}

		// 無効なプレースホルダーパターンをチェック
		$invalid_patterns = array( 'dd', 'mm', 'yyyy', 'hh', 'ii', 'ss' );
		foreach ( $invalid_patterns as $pattern ) {
			if ( strpos( (string) $date_str, $pattern ) !== false ) {
				return false;
			}
		}

		// 基本的なフォーマットチェック（正規表現は最後に）
		$formats = array(
			'/^\d{4}-\d{2}-\d{2}$/',                    // YYYY-MM-DD
			'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',  // YYYY-MM-DD HH:MM:SS
			'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/',        // YYYY-MM-DD HH:MM
		);

		foreach ( $formats as $format ) {
			if ( preg_match( $format, $date_str ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 厳密に 'Y-m-d'（暦日のみ・時刻なし）かを判定する。
	 * is_date() は 'Y-m-d H:i:s' 等も許可するため、暦日入力前提のメソッド（site_day_series 等）では
	 * 本メソッドで時刻付き文字列を弾く。時刻付きを許すと ' 00:00:00' 連結でパース文字列が壊れ例外になる。
	 * @param string $date_str
	 * @return bool
	 */
	public function is_ymd( $date_str ) {
		return is_string( $date_str ) && (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_str );
	}
}


/*
	2つの日付の間の日付をループで処理できるクラス
	日付の逆順ループも制御可能

	パフォーマンスのポイント
	メモリ使用量の削減: カスタムイテレータを使用することで、大量の日付を配列に変換する必要がなくなり、メモリ使用量を削減できます。

	// 使い方
	$start = new DateTime('2023-01-01');
	$end = new DateTime('2023-01-10');

	// 順方向のイテレーション例
	$iterator = new Qahm_Flexible_Date_Iterator($start, $end);
	foreach ($iterator as $date) {
		echo $date->format('Y-m-d') . PHP_EOL;
	}

	// 逆順のイテレーション例
	$reverse_iterator = new Qahm_Flexible_Date_Iterator($start, $end, true);
	foreach ($reverse_iterator as $date) {
		echo $date->format('Y-m-d') . PHP_EOL;
	}
*/
class Qahm_Flexible_Date_Iterator implements Iterator {
	private $current;
	private $start;
	private $end;
	private $interval;
	private $reverse; // イテレーションの方向を制御

	public function __construct( DateTime $start, DateTime $end, bool $reverse = false ) {
		$this->start    = $start;
		$this->end      = $end;
		$this->interval = new DateInterval( 'P1D' );
		$this->reverse  = $reverse;
		$this->rewind();
	}

	public function rewind(): void {
		$this->current = $this->reverse ? $this->end : $this->start;
	}

	public function current(): DateTime {
		return $this->current;
	}

	public function key(): string {
		return $this->current->format( 'Y-m-d' );
	}

	public function next(): void {
		if ( $this->reverse ) {
			$this->current = ( clone $this->current )->sub( $this->interval );
		} else {
			$this->current = ( clone $this->current )->add( $this->interval );
		}
	}

	public function valid(): bool {
		if ( $this->reverse ) {
			return $this->current->getTimestamp() >= $this->start->getTimestamp();
		} else {
			return $this->current->getTimestamp() <= $this->end->getTimestamp();
		}
	}
}
