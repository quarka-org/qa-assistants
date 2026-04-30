var qahm = qahm || {};


  
/**
 * 日付処理
 * 
 * 日付時刻操作・計算は、Days.js を使う　※ブラウザローカルに左右されないため
 * 言語文字列への変換は、JSネイティブの Intl.DateTimeFormat を使う （ Dayjsインスタンスを Date オブジェクトに戻した後で変換する）
 * 
 * Days.js の使い方（参考）：
 * // インスタンス生成
 * let dayjsTzDate = dayjs.tz(); // 現在日時（タイムゾーン適用）
 * // Get
 * dayjsDate.year(); // 年；　month(), date(), day(), hour(), minute(), second() なども同様
 * // Set
 * dayjsDate = dayjsDate.year(2023).month(6).date(1).hour(0).minute(0).second(0); // 2023年7月1日 00:00:00
 * dayjsDate = dayjsDate.endOf('day'); // 23:59:59.999
 * dayjsDate = dayjsDate.startOf('day'); // 00:00:00.000
* // 演算
 * dayjsDate = dayjsDate.subtract(1, 'day'); // 1日前；　単位は 'day', 'hour', 'minute', 'second' など
 * dayjsDate = dayjsDate.add(1, 'day'); // 1日後
 * // フォーマット
 * dayjsDate.format('YYYY-MM-DD HH:mm:ss'); // '2023-07-01 00:00:00'  (local tz)
 * dayjsDate.utc().format('YYYY-MM-DD HH:mm:ss'); // '2023-07-01 00:00:00' （UTC）
 * dayjsDate.toISOString(); // '2023-07-01T00:00:00.000Z' （UTC）
 * 
 * // Day.js インスタンスを Date オブジェクトに戻す
 * let jsDate = dayjsDate.toDate();
 * 
 */


/**
 * Day.js のプラグイン（UTC, Timezone）を初期化して、WordPressのタイムゾーンを設定
 * 必要に応じて関数の先頭で呼び出してください
 */
qahm.initDayjsPlugins = function() {
	if ( ! dayjs.__qahmInitialized ) {
		dayjs.extend(window.dayjs_plugin_utc);
		dayjs.extend(window.dayjs_plugin_timezone);
		dayjs.tz.setDefault(qahm.wp_timezone); // タイムゾーンを設定
		dayjs.__qahmInitialized = true; // 二重初期化を防ぐ
	}
};


/**
 * 共通日付ユーティリティ（Day.js + タイムゾーン対応）
 * 
 * - すべて JSTなど指定されたタイムゾーン（qahm.wp_timezone）で処理されます
 * - デフォルト出力フォーマットは 'YYYY-MM-DD' です（任意で変更可）
 * - 深夜対応やPHPとの連携を考慮した関数も含まれています
 */
// Day.js のプラグインを初期化
qahm.initDayjsPlugins();

qahm.dateUtils = {
	/**
	 * 今日の日付を指定フォーマットで返す（タイムゾーン適用）
	 * @param {string} [format='YYYY-MM-DD'] - 出力フォーマット
	 * @returns {string} 整形済みの日付文字列
	 */
	getToday(format = 'YYYY-MM-DD') {
		// 第一引数のundefinedは、現在時刻を指定するためのもの
	  	return dayjs.tz(undefined, qahm.wp_timezone).format(format);
	},
  
	/**
	 * 昨日（1日前）の日付を返す（タイムゾーン適用）
	 * @param {string} [format='YYYY-MM-DD'] - 出力フォーマット
	 * @returns {string} 整形済みの日付文字列
	 */
	getYesterday(format = 'YYYY-MM-DD') {
	  	return dayjs.tz(undefined, qahm.wp_timezone).subtract(1, 'day').format(format);
	},
  
	/**
	 * 深夜（4時未満）は2日前、それ以降は昨日の日付を返す
	 * @param {string} [format='YYYY-MM-DD'] - 出力フォーマット
	 * @returns {string} 整形済みの日付文字列
	 */
	getSmartYesterday(format = 'YYYY-MM-DD') {
	  	const now = dayjs.tz(undefined, qahm.wp_timezone);
	  	const minus = now.hour() < 4 ? 2 : 1;
	  	return now.subtract(minus, 'day').format(format);
	},
  
	/**
	 * 指定したn日前の日付を返す（基準日時指定可能）	 * 
	 * @param {string | number | Date | undefined} base - 基準日時（undefinedなら現在）
	 * @param {number} n - 何日前か（1 = 昨日）
	 * @param {string} [format='YYYY-MM-DD'] - 出力フォーマット
	 * @returns {string} 整形済み日付
	 */
	getDaysAgo( base = undefined, n = 1, format = 'YYYY-MM-DD' ) {
		// phpのUnixタイム（秒）を指定した場合、ミリ秒に変換（西暦33658年まで安全圏）
		if (typeof base === 'number' && base < 1e12) {
			base = base * 1000; // 秒 → ミリ秒
		}
		return dayjs.tz(base, qahm.wp_timezone).subtract(n, 'day').format(format);
	},
  
	/**
	 * PHPのUnixタイム（秒）を指定タイムゾーン＋フォーマットで整形
	 * @param {number} phpUnixSec - PHPの `time()` などで得た秒単位のUnixタイム
	 * @param {string} [format='YYYY-MM-DD'] - 出力フォーマット
	 * @returns {string} 整形済み日付文字列
	 */
	formatPhpUnixTime(phpUnixSec, format = 'YYYY-MM-DD') {
		return dayjs.tz(phpUnixSec * 1000, qahm.wp_timezone).format(format);
	},
  
	/**
	 * JSのUnixタイム（ミリ秒）を、タイムゾーン付きで整形
	 * @param {number} unixTime - ミリ秒単位（JS）の Unixタイム
	 * @param {string} [format='YYYY-MM-DD'] - 出力フォーマット
	 * @returns {string} 整形済みの日付文字列
	 */
	formatJsUnixTime(unixTime, format = 'YYYY-MM-DD') {
	  	return dayjs.tz(unixTime, qahm.wp_timezone).format(format);
	},

	// Date Range Picker 用 ------
	/**
	 * Day.jsインスタンスから Momentインスタンス（日付のみ）を生成
	 * タイムゾーン付きのDay.jsを "YYYY-MM-DD" で明示的にMomentへ渡す
	 *
	 * @param {dayjs.Dayjs} dayjsInst - タイムゾーン適用済みの Day.js インスタンス
	 * @returns {moment.Moment} Momentインスタンス（時刻なしの日付）
	 */
	dayjsToMomentDate(dayjsInst) {
		return moment(dayjsInst.format('YYYY-MM-DD'), 'YYYY-MM-DD');
	},
	
	/**
	 * 任意の n 日前の日付を wp_timezone で計算し、Momentインスタンスで返す
	 *
	 * @param {number} daysAgo - 何日前か（例：7）
	 * @param {string | number | Date | undefined} [base=undefined] - 基準日（未指定なら現在）
	 * @returns {moment.Moment} wp_timezoneで処理された日付（00:00）
	 */
	getMomentDaysAgo(daysAgo, base = undefined) {
	qahm.initDayjsPlugins();
	const dj = dayjs.tz(base, qahm.wp_timezone).subtract(daysAgo, 'day');
	return qahm.dateUtils.dayjsToMomentDate(dj);
	},

	/**
	 * 任意の月の「月初」と「月末」を wp_timezone で取得し、Momentインスタンス（日付のみ）で返す
	 *
	 * @param {number} offset - 何か月前か（0 = 今月, 1 = 先月）
	 * @param {string | number | Date | undefined} [base=undefined] - 基準日（未指定なら現在）
	 * @returns {[moment.Moment, moment.Moment]} 月初～月末のMoment日付範囲
	 */
	getMomentMonthRange(offset = 0, base = undefined) {
	qahm.initDayjsPlugins();
	const dj = dayjs.tz(base, qahm.wp_timezone).subtract(offset, 'month');
	const start = qahm.dateUtils.dayjsToMomentDate(dj.startOf('month'));
	const end = qahm.dateUtils.dayjsToMomentDate(dj.endOf('month'));
	return [start, end];
	}
};


/**
 * 日付配列を作成 ※ Day.js を使う
 * @function qahm.makeFormattedDatesArray
 * @param {string|Date|Object} startDate - 範囲の開始日（YYYY-MM-DDなどの文字列、Dateオブジェクト、またはDay.jsが受け取れる形式）
 * @param {string|Date|Object} endDate - 範囲の終了日（同上）
 * @param {string} [dayjsFormat='YYYY-MM-DD'] - 出力する日付フォーマット（Day.jsのformatに準拠）
 * @returns {string[]} 日付フォーマットに従った日付ラベルの配列
 */
qahm.makeFormattedDatesArray = function( startDate, endDate, dayjsFormat = 'YYYY-MM-DD' ) {
	let formattedDatesArray = [];

	// Day.js のプラグインを拡張（UTC, タイムゾーン）
	qahm.initDayjsPlugins();

	let startDateInst = dayjs.tz( startDate );
	let endDateInst = dayjs.tz( endDate );
	let rangeDays = endDateInst.diff( startDateInst, 'day' ) + 1;
	for ( let iii = 0; iii < rangeDays; iii++ ) {
		formattedDatesArray.push( startDateInst.add( iii, 'day' ).format( dayjsFormat ) );
	}
	return formattedDatesArray;
}

/**
 * locale日付文字列への変換（Intl.DateTimeFormat…JSネイティブを使う）
 * 
 * dateStyle:
 * 'long'
 * 	  Chrome表示例）
 * 	  en-GB: '20 December 2020'; en-US: 'December 20, 2020'; ja-JP: '2020年12月20日'
 * 	  formatRangeの時： en-GB: '20 December 2020 - 25 December 2020'; en-US: 'December 20, 2020 - December 25, 2020'; ja-JP: '2020/12/20 ～ 2020/12/25'
 * 'medium' 
 * 	  Chrome表示例）
 * 	  en-GB: '20 Dec 2020'; en-US: 'Dec 20, 2020'; ja-JP: '2020/12/20'
 * 	  formatRangeの時： en-GB: '20 Dec 2020 - 25 Dec 2020'; en-US: 'Dec 20, 2020 - Dec 25, 2020'; ja-JP: '2020/12/20 ～ 2020/12/25'
 * 
 * timeStyle:
 * 'short'	14:32	時:分のみ
 * 'medium'	14:32:12	時:分:秒
 * 'long'	14:32:12 JST	時:分:秒 + タイムゾーン略称
 * 
 */ 
/**
 * 日付のみ
 *
 * @function qahm.formatDateText
 * @param {Date | string | number} dateInput - 整形したい日付。Date オブジェクト、ISO 文字列、UNIX タイムスタンプなどを受け付けます。
 * @param {('short' | 'medium' | 'long' | 'full')} [dateStyle='long']
 * @returns {string} 整形された日付文字列。不正な入力の場合は空文字を返します。
 */
qahm.formatDateText = function(dateInput, dateStyle = 'long') {
	const dateObj = new Date(dateInput);
	if (!(dateObj instanceof Date) || isNaN(dateObj)) return '';
  
	const formatter = new Intl.DateTimeFormat(qahm.locale_for_js, {
	  dateStyle: dateStyle,
	  timeZone: qahm.wp_timezone,
	});
  
	return formatter.format(dateObj);
};

/**
 * 日付＋時刻
 *
 * @function qahm.formatDateTimeText
 * @param {Date | string | number} dateInput
 * @param {('short' | 'medium' | 'long' | 'full')} [dateStyle='long']
 * @param {('short' | 'medium' | 'long' | 'full')} [timeStyle='medium']
 * @returns {string}
 */
qahm.formatDateTimeText = function(dateInput, dateStyle = 'long', timeStyle = 'medium') {
	const dateObj = new Date(dateInput);
	if (!(dateObj instanceof Date) || isNaN(dateObj)) return '';
  
	const formatter = new Intl.DateTimeFormat(qahm.locale_for_js, {
	  dateStyle: dateStyle,
	  timeStyle: timeStyle,
	  timeZone: qahm.wp_timezone,
	});
  
	return formatter.format(dateObj);
};

/**
 *  期間　（例）開始日～終了日
 *
 * @function qahm.formatDateRangeText
 * @param {Date | string | number} startInput - 範囲の開始日。
 * @param {Date | string | number} endInput - 範囲の終了日。
 * @param {('short' | 'medium' | 'long' | 'full')} [dateStyle='long'] - 日付の表示スタイル。
 * @returns {string} 整形された日付範囲文字列。不正な入力がある場合は空文字を返します。
 */
qahm.formatDateRangeText = function(startInput, endInput, dateStyle = 'long') {
	const start = new Date(startInput);
	const end = new Date(endInput);
	if (isNaN(start) || isNaN(end)) return '';
  
	const formatter = new Intl.DateTimeFormat(qahm.locale_for_js, {
	  dateStyle: dateStyle,
	  timeZone: qahm.wp_timezone,
	});
  
	return formatter.formatRange(start, end);
};
  
/**
 *   期間　（例）開始日 + 時刻～終了日 + 時刻
 *  *
 * @function qahm.formatDateTimeRangeText
 * @param {Date | string | number} startInput
 * @param {Date | string | number} endInput
 * @param {('short' | 'medium' | 'long' | 'full')} [dateStyle='long']
 * @param {('short' | 'medium' | 'long' | 'full')} [timeStyle='medium']
 * @returns {string}
 */
qahm.formatDateTimeRangeText = function(startInput, endInput, dateStyle = 'long', timeStyle = 'medium') {
	const start = new Date(startInput);
	const end = new Date(endInput);
	if (isNaN(start) || isNaN(end)) return '';
  
	const formatter = new Intl.DateTimeFormat(qahm.locale_for_js, {
	  dateStyle: dateStyle,
	  timeStyle: timeStyle,
	  timeZone: qahm.wp_timezone,
	});
  
	return formatter.formatRange(start, end);
};

// --------------------------------------



qahm.nowAjaxStep = 0;
qahm.calendarCookieMaxAge = 10 * 60 * 60; // 10 hour

/**
 * 日付関連のdataviewer以下共通変数
 */
let reportRangeStart, reportRangeEnd; // Date Object
let reportRangeStartStr, reportRangeEndStr; // YYYY-MM-DD 形式の文字列
let calMinDateStr, calMaxDateStr;
let dateRangeYmdAry = []; // YYYY-MM-DD 形式の日付文字列の配列
let reportDateBetween = ''; // "date = beween {YYYY-MM-DD} and {YYYY-MM-DD}" の文字列


// カレンダー期間＆ajax用期間の決定　※ Day.js を使う
qahm.initDateSetting = function() {
	//reportRangeStart, reportRangeEnd, reportRangeStartStr, reportRangeEndStr;
	let cookieSavedRangeStart, cookieSavedRangeEnd;
	
	// Day.js のプラグインを拡張（UTC, タイムゾーン）
	qahm.initDayjsPlugins();

	// --- Using Dayjs Start ------
	let todayInst = dayjs.tz();
	let minusday = 1;
	if (  todayInst.hour() < 4 ) { //cron前の深夜は2日前にしておく
		minusday = 2; 
	}
	let calMaxDate = todayInst.subtract( minusday, 'day' ).endOf('day'); // minusday前の 23:59:59:999
	
	// カレンダー選択可能範囲の決定
	calMinDateStr = qahm.pvterm_start_date;
	if ( calMinDateStr  === null || calMinDateStr === undefined ) {
		let calMinDate = calMaxDate.clone();
		calMinDateStr = calMinDate.format('YYYY-MM-DD');
	}
	calMaxDateStr = calMaxDate.format('YYYY-MM-DD');
	if ( (qahm.debug >= qahm.debug_level) || qahm.dev001 || qahm.dev002 || qahm.dev003 ) {
		if ( qahm.pvterm_latest_date !== null ) {
			calMaxDateStr = qahm.pvterm_latest_date;
		}
	}
	// 表示期間：CookieがあればCookieの値、なければデフォルト7日間
	let calDaysRange = 7 - 1;
	cookieSavedRangeStart = qahm.getSafeCookie( 'qahm_zero_calendar_base_start_date' );
	cookieSavedRangeEnd   = qahm.getSafeCookie( 'qahm_zero_calendar_base_end_date' );
	if ( cookieSavedRangeStart && cookieSavedRangeEnd ) {
		// ISOString (UTC) を正しく扱うために、まず dayjs.utc() でUTCパースしてからタイムゾーン適用
		reportRangeEnd = dayjs.utc(cookieSavedRangeEnd).tz(qahm.wp_timezone);
		reportRangeStart = dayjs.utc(cookieSavedRangeStart).tz(qahm.wp_timezone);
		calDaysRange = reportRangeEnd.diff( reportRangeStart, 'day' );
	} else {
		reportRangeEnd = calMaxDate.clone();
		reportRangeStart = reportRangeEnd.subtract( calDaysRange, 'day' ).startOf('day');

		// Cookieに保存
		qahm.setSafeCookie( 'qahm_zero_calendar_base_start_date', reportRangeStart.toISOString(), qahm.calendarCookieMaxAge );
		qahm.setSafeCookie( 'qahm_zero_calendar_base_end_date', reportRangeEnd.toISOString(), qahm.calendarCookieMaxAge );
	}
	reportRangeStartStr = reportRangeStart.format('YYYY-MM-DD');
	reportRangeEndStr = reportRangeEnd.format('YYYY-MM-DD');
	reportDateBetween = 'date = between ' + reportRangeStartStr + ' and ' + reportRangeEndStr;
	// --- Using Dayjs End ------
	// Date Object に変換
	reportRangeStart = reportRangeStart.toDate();
	reportRangeEnd = reportRangeEnd.toDate();

	// 日付配列（データのキーに合わせて YYYY-MM-DD 形式）を作成
	dateRangeYmdAry = qahm.makeFormattedDatesArray( reportRangeStart, reportRangeEnd, 'YYYY-MM-DD' );

};


// カレンダーを設置
// Date Range Picker を使う（ moment.js を内包している。扱う値も momentインスタンスなので、文字列で渡して変換かける　※ブラウザ依存を避けるため）
qahm.setDateRangePicker = function() {

	// momentのロケール（言語）を設定
	moment.locale(qahm.locale_for_js);

	// 日付範囲テキストボックスを更新する共通関数
    function updateDateRangeTextbox(calStartDateObj, calEndDateObj) {
        let datePickerText = qahm.formatDateRangeText(calStartDateObj, calEndDateObj);
        jQuery('#datepicker-base-textbox').val(datePickerText);
    }

	// momentインスタンス化
	let rangeStartDate  = moment( reportRangeStartStr, 'YYYY-MM-DD' );
    let rangeEndDate    = moment( reportRangeEndStr, 'YYYY-MM-DD' );

	// range用のmomentインスタンス
	let kyouDate = qahm.dateUtils.getToday( 'YYYY-MM-DD' );
	let zenjitsuDate = qahm.dateUtils.getSmartYesterday( 'YYYY-MM-DD' );
	let kyouMoment = moment(kyouDate, 'YYYY-MM-DD');
	let zenjitsuMoment = moment(zenjitsuDate, 'YYYY-MM-DD');

    let daterangeOpt = {
        startDate: rangeStartDate,
        endDate: rangeEndDate,
		minDate: moment( calMinDateStr, 'YYYY-MM-DD' ), //(Date or string) The earliest date a user may select.
		maxDate: moment( calMaxDateStr, 'YYYY-MM-DD' ), //(Date or string) The latest date a user may select.
        showCustomRangeLabel: true, //選択肢にカレンダーありか、なしか。
        showDropdowns: true, //年月選択肢をドロップダウンにするか
        linkedCalendars: false, //２つのカレンダーを連動させるか（常に連続する2か月の表示か）
        ranges: {
			[qahml10n['calender_kako7days']]: [
				zenjitsuMoment.clone().subtract(6, 'days'),
				zenjitsuMoment
			],
			[qahml10n['calender_kako30days']]: [
				zenjitsuMoment.clone().subtract(29, 'days'),
				zenjitsuMoment
			],
			[qahml10n['calender_kongetsu']]: [
				kyouMoment.clone().startOf('month'),
				kyouMoment.clone().endOf('month')
			],
			[qahml10n['calender_sengetsu']]: [
				kyouMoment.clone().subtract(1, 'month').startOf('month'),
				kyouMoment.clone().subtract(1, 'month').endOf('month')
			]
        },
        locale: {
            separator: qahml10n['calender_kara'],
            customRangeLabel: qahml10n['calender_erabu'],
            cancelLabel: qahml10n['calender_cancel'],
            applyLabel: qahml10n['calender_ok'],
        },
    };

	// datepickerを初期化（初期化時の表示も updateDateRangeTextbox が呼ばれる）
    jQuery('#datepicker-base-textbox').daterangepicker(daterangeOpt, updateDateRangeTextbox);


    //期間変更された時
    jQuery('#datepicker-base-textbox').on('apply.daterangepicker', function(ev, picker) {
		moment.locale(qahm.locale_for_js); // momentのロケール（言語）を設定

		// 選択された日付をdayjsインスタンスに変換
		let pickedStartInst = dayjs(picker.startDate).tz(qahm.wp_timezone).startOf('day');
		let pickedEndInst = dayjs(picker.endDate).tz(qahm.wp_timezone).endOf('day');

		// cookieに保存
		let pickedStartStr = pickedStartInst.toISOString();
		let pickedEndStr = pickedEndInst.toISOString();
		qahm.setSafeCookie('qahm_zero_calendar_base_start_date', pickedStartStr, qahm.calendarCookieMaxAge);
		qahm.setSafeCookie('qahm_zero_calendar_base_end_date', pickedEndStr, qahm.calendarCookieMaxAge);

		// 共通日付変数の更新
		reportRangeStart = pickedStartInst.toDate();
		reportRangeEnd = pickedEndInst.toDate();
		reportRangeStartStr = pickedStartInst.format('YYYY-MM-DD');
		reportRangeEndStr = pickedEndInst.format('YYYY-MM-DD');
		reportDateBetween = 'date = between ' + reportRangeStartStr + ' and ' + reportRangeEndStr;
		dateRangeYmdAry = qahm.makeFormattedDatesArray(reportRangeStart, reportRangeEnd, 'YYYY-MM-DD');
		
		// カレンダー変更のイベントを発火
		jQuery(document).trigger('qahm:dateRangeChanged', [reportRangeStart, reportRangeEnd]);

		// UI表示を更新
        updateDateRangeTextbox(picker.startDate, picker.endDate);

    });


	// 初期表示（初期化時に1回呼ぶ）
    updateDateRangeTextbox(rangeStartDate, rangeEndDate);
    
}




// クッキーの設定
qahm.setCookie = function( name, value, maxAge = '' ) {
	let cookie = name + '=' + value;
	if ( maxAge ){
		cookie += '; max-age=' + maxAge;
	}
	document.cookie = cookie;
};

// クッキーの取得
qahm.getCookie = function( name ) {
	let value = '; ' + document.cookie;
	let parts = value.split( '; ' + name + '=' );
	if ( parts.length == 2 ) return parts.pop().split( ';' ).shift();
};

// 安全なクッキー保存（URLエンコード付き）
qahm.setSafeCookie = function(name, value, maxAge = '') {
	let encoded = encodeURIComponent(value);
	let cookie = name + '=' + encoded;
	if (maxAge) {
		cookie += '; max-age=' + maxAge;
	}
	document.cookie = cookie;
};

// 安全なクッキー取得（URLデコード付き）
qahm.getSafeCookie = function(name) {
	let value = '; ' + document.cookie;
	let parts = value.split('; ' + name + '=');
	if (parts.length === 2) {
		return decodeURIComponent(parts.pop().split(';').shift());
	}
	return null;
};


// 文字列をエスケープする
qahm.htmlspecialchars = function( unsafeText ) {
	if(typeof unsafeText !== 'string'){
	  return unsafeText;
	}
	return unsafeText.replace(
		/[&'`"<>]/g,
		function(match) {
			return {
				'&': '&amp;',
				"'": '&#x27;',
				'`': '&#x60;',
				'"': '&quot;',
				'<': '&lt;',
				'>': '&gt;',
			}[match]
		}
	);
}


// 目標のラジオボタンが変更されたときのコールバック処理
qahm.changeGoal = function( e, table, array ) {
	if ( table === null ) {
		return;
	}
    let checkedId = e.target.id;
    let idsplit   = checkedId.split('_');
    let gid       = Number( idsplit[2] );
	if ( array[gid] === undefined ) {
		array[gid] = [];
	}
    qahm.makeTable( table, array[gid] );
};


//各Tableの作成
qahm.makeTable  = function(table, ary) {
    table.rawDataArray = ary;
    if ( ! table.headerTableByID ) {
        table.generateTable();
    } else {
        table.updateTable();
    }
};


// chart.js のグラフをクリアする
qahm.clearPreChart = function( chartVar ) {
	if ( typeof chartVar !== 'undefined' ) {
		chartVar.destroy();
	}
}
qahm.resetCanvas = function( canvasId, attr = '' ) {
	let container = document.getElementById(canvasId).parentNode;
	if (container) {
        container.innerHTML = '&nbsp;';
        container.innerHTML = `<canvas id="${canvasId}" ${attr}></canvas>`;
    }
}


// 再生ボタンクリック
qahm.replayClickEvent = function() {
	jQuery( document ).on( 'click', '.icon-replay', function(){
		qahm.showLoadIcon();

		let start_time  = new Date().getTime();
		let reader_id   = jQuery( this ).data( 'reader_id' );
		let replay_id   = jQuery( this ).data( 'replay_id' );
		let access_time = jQuery( this ).data( 'access_time' );

		jQuery.ajax(
			{
				type: 'POST',
				url: qahm.ajax_url,
				dataType : 'json',
				data: {
					'action':      'qahm_ajax_create_replay_file_to_data_base',
					'reader_id':   reader_id,
					'replay_id':   replay_id,
					'access_time': access_time,
				},
			}
		).done(
			function( data ){
				// 最低読み込み時間経過後に処理実行
				let now_time  = new Date().getTime();
				let load_time = now_time - start_time;
				let min_time  = 400;

				if ( load_time < min_time ) {
					// ロードアイコンを削除して新しいウインドウを開く
					setTimeout(
						function(){
							window.open( data, '_blank' );
						},
						(min_time - load_time)
					);
				} else {
					window.open( data, '_blank' );
				}
			}
		).fail(
			function( jqXHR, textStatus, errorThrown ){
				qahm.log_ajax_error( jqXHR, textStatus, errorThrown );
			}
		).always(
			function(){
				qahm.hideLoadIcon();
			}
		);
	});
}


// 目標ラジオボタンを触れる・触れないの切り替え
qahm.enabledGoalRadioButton = function() {
	// 目標ページ以外
	jQuery('.qa-zero-radio-button input').prop('disabled', false);
	jQuery('.qa-zero-radio-button input').css('cursor', 'pointer');
	jQuery('.qa-zero-radio-button label').css('cursor', 'pointer');
	jQuery('.qa-zero-radio-button').css('color', '#3c434a');

	// 目標ページ
	jQuery('.bl_goalBox input').prop('disabled', false);
	jQuery('.bl_goalBox').css('color', '#3c434a');
};

qahm.disabledGoalRadioButton = function() {
	// 目標ページ以外
	jQuery('.qa-zero-radio-button input').prop('disabled', true);
	jQuery('.qa-zero-radio-button input').css('cursor', 'default');
	jQuery('.qa-zero-radio-button label').css('cursor', 'default');
	jQuery('.qa-zero-radio-button').css('color', '#ddd');

	// 目標ページ
	jQuery('.bl_goalBox input').prop('disabled', true);
	jQuery('.bl_goalBox').css('color', '#ddd');
};



// graph color
qahm.graphColorBase  = ['#69A4E2', '#BAD6F4', '#31356E',  '#2F5F98'];
qahm.graphColorful   = ['#69A4E2', ];
qahm.graphColorBaseA = ['rgba(105, 164, 226, 1)', 'rgba(186, 214, 244, 1)', 'rgba(49, 53, 110, 1)',  'rgba(47, 95, 152, 1)'];
qahm.graphColorGoals = [
    'rgba(230, 215, 130, 1)', // 暗めの明るい黄色
    'rgba(215, 190, 100, 1)', // やや暗めの黄色
    'rgba(200, 170, 80, 1)',  // 濃い黄色
    'rgba(185, 150, 70, 1)',  // 暖かみのある濃い黄色
    'rgba(165, 130, 50, 1)',  // 暗い黄土色
    'rgba(200, 200, 220, 1)', // 暗めの青みグレー
    'rgba(160, 180, 210, 1)', // 落ち着いた水色
    'rgba(120, 140, 180, 1)', // 少し暗い青
    'rgba(90, 110, 150, 1)',  // 深い青
    'rgba(60, 70, 100, 1)',   // 暗いブルーグレー
    'rgba(30, 40, 70, 1)'     // 最も暗い青
];


/*
	元々admin-common.jsにあったものを移動
*/
jQuery(
	function () {
		// 管理画面上部にライセンス関連のメッセージを表示
		jQuery( '.qahm-license-message > button.notice-dismiss' ).on(
			'click',
			function(){
				let no = jQuery( this ).parent().data( 'no' );
				jQuery.ajax(
					{
						type: 'POST',
						url: qahm.ajax_url,
						data: {
							'action' : 'qahm_ajax_clear_license_message',
							'nonce' : qahm.nonce_api,
							'no': no
						}
					}
				).done(
					function( res ){
						qahm.log( 'done : qahm_ajax_clear_license_message' );
					}
				).fail(
					function( jqXHR, textStatus, errorThrown ){
						qahm.log_ajax_error( jqXHR, textStatus, errorThrown );
					}
				);
			}
		);
	}
);
