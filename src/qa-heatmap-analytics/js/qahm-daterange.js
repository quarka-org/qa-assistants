/**
 * qahm-daterange.js
 *
 * 日付範囲選択の共通アダプタ層。
 * UI ライブラリ（Cally / <calendar-range>）を1箇所に閉じ込め、
 * 各画面（集客 dataviewer / AIDE / Atelier / ヒートマップ）は本アダプタ経由で
 * 日付範囲ピッカーを設置する。将来ライブラリを差し替える場合も、変更は本ファイルに閉じる。
 *
 * 提供 API:
 *   qahm.DateRange.init( targetEl, options ) -> { getRange(), destroy() }
 *     options = {
 *       start, end,        // 'YYYY-MM-DD'（初期範囲。cookie 指定があれば cookie 優先）
 *       min, max,          // 'YYYY-MM-DD'（選択可能範囲。任意）
 *       months,            // 表示月数（既定 2）
 *       presets,           // [{ label, start, end }] 配列なら完全置換 / 省略で既定4種
 *       presetLabels,      // { kako7days, kako30days, kongetsu, sengetsu } 省略で qahml10n / 英語
 *       onChange,          // function(payload) コールバック（イベント発火より先に呼ぶ）
 *       locale,            // 'ja'（既定）
 *       firstDayOfWeek,    // 0=日(既定。moment 'ja' と同じ) .. 6=土
 *       jqEvent,           // boolean（既定 true）= qahm:dateRangeChanged を発火するか
 *       cookie,            // { startName, endName, maxAge, writeFormat:'iso'|'ymd', read:true } 任意
 *       portal             // boolean（既定 false）= trigger ポップオーバーを body 直下に fixed 配置（#1353）。
 *                          //   overflow:hidden のスクロール枠（アシスタント会話 UI 等）に埋め込む場合に opt-in。
 *                          //   既定は従来どおり mount 直下に absolute（ダッシュボード6画面/AIDE は無改修）。
 *     }
 *
 * payload = {
 *   startStr, endStr,      // 'YYYY-MM-DD'
 *   startDate, endDate,    // Date オブジェクト（startOf/endOf day, wp_timezone）
 *   dateBetween,           // 'date = between {start} and {end}'
 *   ymdArray               // ['YYYY-MM-DD', ...]（両端含む）
 * }
 *
 * Devin 着手前レビュー反映（#1318）:
 *   - Cally は ESM のみ配布のため IIFE 化して enqueue（type=module 不使用）。
 *   - イベント引数は Date 維持（現行 admin-page-dataviewer.js と同契約）。コア6画面は引数を使わず
 *     モジュールスコープ変数を直読みするため、onChange をイベント発火より先に呼ぶ。
 *   - cookie 読み取りは ISO8601 / YYYY-MM-DD の両対応。書き込み形式は writeFormat で選択（既定 iso）。
 */
( function () {
	'use strict';

	window.qahm = window.qahm || {};
	if ( window.qahm.DateRange ) {
		return; // 二重読み込みガード
	}

	var ISO_DATETIME_RE = /T/; // ISO8601 は 'YYYY-MM-DDTHH:mm...' を含む
	var YMD_RE = /^\d{4}-\d{2}-\d{2}$/;
	var MAX_RANGE_DAYS = 5000; // buildYmdArray の暴走防止（約13.7年。start>end など異常時の安全弁）
	var MAX_MONTHS = 240; // 縦描画する月数の上限（20年。データ期間が極端でも DOM 暴走を防ぐ安全弁）

	/**
	 * UI 文言の i18n。コア画面が wp_localize_script で渡す qahml10n を参照し、
	 * 無ければ英語フォールバック（プリセットラベルと同じ供給方式 = 翻訳の正本は PHP の __()）。
	 */
	function l10n( key, fallback ) {
		return ( window.qahml10n && qahml10n[ key ] ) ? qahml10n[ key ] : fallback;
	}

	/**
	 * 2つの 'YYYY-MM-DD' の月差（両端含む月数）。minYmd <= maxYmd 前提。
	 */
	function monthSpan( minYmd, maxYmd ) {
		var a = new Date( +minYmd.slice( 0, 4 ), +minYmd.slice( 5, 7 ) - 1, 1 );
		var b = new Date( +maxYmd.slice( 0, 4 ), +maxYmd.slice( 5, 7 ) - 1, 1 );
		return ( b.getFullYear() - a.getFullYear() ) * 12 + ( b.getMonth() - a.getMonth() ) + 1;
	}

	/**
	 * Cally の value（'YYYY-MM-DD/YYYY-MM-DD'）を { start, end } に分解する。
	 * 不正なら null。
	 */
	function parseValue( value ) {
		if ( typeof value !== 'string' || value.indexOf( '/' ) === -1 ) {
			return null;
		}
		var parts = value.split( '/' );
		var start = ( parts[0] || '' ).slice( 0, 10 );
		var end = ( parts[1] || '' ).slice( 0, 10 );
		if ( ! YMD_RE.test( start ) || ! YMD_RE.test( end ) ) {
			return null;
		}
		return { start: start, end: end };
	}

	/**
	 * { start, end } を Cally の value 文字列へ。
	 */
	function toValue( start, end ) {
		return start + '/' + end;
	}

	/**
	 * 文字列が ISO8601 日時（'T' を含む）かどうか。
	 */
	function isIsoDateTime( str ) {
		return typeof str === 'string' && ISO_DATETIME_RE.test( str );
	}

	/**
	 * cookie の生値を 'YYYY-MM-DD' に正規化する（D2: 旧 ISO / 新 YMD の両対応）。
	 * - ISO8601（'T' 含む）: dayjs.utc().tz() で wp_timezone の暦日に変換
	 * - 'YYYY-MM-DD': 先頭10文字をそのまま採用
	 * 解釈できなければ null。
	 */
	function normalizeCookieDate( raw ) {
		if ( ! raw || typeof raw !== 'string' ) {
			return null;
		}
		if ( isIsoDateTime( raw ) ) {
			var tz = ( window.qahm && qahm.wp_timezone ) ? qahm.wp_timezone : undefined;
			if ( window.dayjs && dayjs.utc ) {
				var d = tz ? dayjs.utc( raw ).tz( tz ) : dayjs.utc( raw );
				return d.isValid() ? d.format( 'YYYY-MM-DD' ) : null;
			}
			return raw.slice( 0, 10 ); // dayjs 不在時のフォールバック
		}
		var ymd = raw.slice( 0, 10 );
		return YMD_RE.test( ymd ) ? ymd : null;
	}

	/**
	 * 既定プリセット6種（過去7日 / 過去30日 / 今週 / 先週 / 今月 / 先月）を生成する。
	 * dataviewer の ranges 定義と同一の計算（基準=今日 / smart-yesterday）。
	 *
	 * @param {object} labels { kako7days, kako30days, konshu, senshu, kongetsu, sengetsu }
	 * @param {object} refs   { today:'YYYY-MM-DD', smartYesterday:'YYYY-MM-DD', min?, max? }
	 * @returns {Array<{token,label,start,end}>}
	 */
	// プリセット/相対トークンの計算を一元化する（#1358）。refs から token 付き
	// {token,label,start,end} を算出し、defaultPresets と resolveRelative の両方が共用する。
	// 「相対デフォルト＝そのプリセットをクリックした状態」の不変条件はここに集約される。
	function buildPresetEntries( labels, refs ) {
		labels = labels || {};
		// ラベル解決: 呼び出しの presetLabels → qahml10n → 英語フォールバック の3段。
		function L( key, fallback ) {
			return labels[ key ] || ( window.qahml10n && qahml10n[ 'calender_' + key ] ) || fallback;
		}
		var today = refs && refs.today ? refs.today : null;
		var zen = refs && refs.smartYesterday ? refs.smartYesterday : null;
		if ( ! window.dayjs || ! today || ! zen ) {
			return [];
		}
		var t = dayjs( today );
		var z = dayjs( zen );
		var fmt = 'YYYY-MM-DD';
		// 週境界（日曜始まり）。dayjs の day() は 0=日。今週は本日まで、先週は日〜土。
		var sunThis = t.subtract( t.day(), 'day' );  // 今週の日曜
		var sunLast = sunThis.subtract( 7, 'day' );  // 先週の日曜
		var satLast = sunLast.add( 6, 'day' );        // 先週の土曜
		// token = manifest の相対デフォルトで使う安定識別子（カレンダーのプリセットに対応）。
		var list = [
			{
				token: 'last_7_days',
				label: L( 'kako7days', 'Last 7 days' ),
				start: z.subtract( 6, 'day' ).format( fmt ),
				end: z.format( fmt ),
			},
			{
				token: 'last_30_days',
				label: L( 'kako30days', 'Last 30 days' ),
				start: z.subtract( 29, 'day' ).format( fmt ),
				end: z.format( fmt ),
			},
			{
				token: 'this_week',
				label: L( 'konshu', 'This week' ),
				start: sunThis.format( fmt ),
				end: t.format( fmt ),
			},
			{
				token: 'last_week',
				label: L( 'senshu', 'Last week' ),
				start: sunLast.format( fmt ),
				end: satLast.format( fmt ),
			},
			{
				token: 'this_month',
				label: L( 'kongetsu', 'This month' ),
				start: t.startOf( 'month' ).format( fmt ),
				end: t.endOf( 'month' ).format( fmt ),
			},
			{
				token: 'last_month',
				label: L( 'sengetsu', 'Last month' ),
				start: t.subtract( 1, 'month' ).startOf( 'month' ).format( fmt ),
				end: t.subtract( 1, 'month' ).endOf( 'month' ).format( fmt ),
			},
		];
		// 選択可能範囲 [min, max] にクランプ（例: 今月は月末でなく最新日=max まで）。
		// 範囲外になったプリセットは除外。YYYY-MM-DD の辞書順比較で OK。
		var min = refs && refs.min ? refs.min : null;
		var max = refs && refs.max ? refs.max : null;
		return list.map( function ( p ) {
			var s = p.start, e = p.end;
			if ( min && s < min ) { s = min; }
			if ( max && e > max ) { e = max; }
			if ( min && e < min ) { e = min; }
			if ( max && s > max ) { s = max; }
			return { token: p.token, label: p.label, start: s, end: e };
		} ).filter( function ( p ) {
			return p.start <= p.end;
		} );
	}

	function defaultPresets( labels, refs ) {
		// M3: 返り値 shape {label,start,end} を完全に維持する（token は外へ出さない）。
		// ダッシュボード6画面・AIDE のプリセット描画はこの出力に依存するため不変であること。
		return buildPresetEntries( labels, refs ).map( function ( p ) {
			return { label: p.label, start: p.start, end: p.end };
		} );
	}

	/**
	 * 相対トークン（カレンダーのプリセットに対応）を {start,end} へ解決する（#1358）。
	 * defaultPresets と同一の計算（buildPresetEntries）を通すため、解決結果は
	 * 「そのプリセットをクリックした状態」と厳密一致する。
	 *
	 * refs は部分上書き（M1）: today/smartYesterday は省略時 qahm.dateUtils から内部生成し、
	 * min/max のみ呼び出し側を尊重する（all-or-nothing にしない）。
	 *
	 * @param {string} token  'last_7_days'|'last_30_days'|'this_week'|'last_week'|'this_month'|'last_month'
	 * @param {object} [refs] { today, smartYesterday, min, max }（いずれも任意）
	 * @returns {{start:string,end:string}|null} 未知トークン or クランプで無効化した場合は null
	 */
	function resolveRelativeRange( token, refs ) {
		if ( typeof token !== 'string' || ! token ) {
			return null;
		}
		var du = window.qahm && qahm.dateUtils;
		var merged = {
			today: ( refs && refs.today )
				|| ( du && typeof du.getToday === 'function' ? du.getToday( 'YYYY-MM-DD' ) : null ),
			smartYesterday: ( refs && refs.smartYesterday )
				|| ( du && typeof du.getSmartYesterday === 'function' ? du.getSmartYesterday( 'YYYY-MM-DD' ) : null ),
			min: refs && refs.min ? refs.min : null,
			max: refs && refs.max ? refs.max : null,
		};
		// labels は範囲計算に不要。dayjs/dateUtils 不在時は buildPresetEntries が [] を返す。
		var entries = buildPresetEntries( null, merged );
		for ( var i = 0; i < entries.length; i++ ) {
			if ( entries[ i ].token === token ) {
				return { start: entries[ i ].start, end: entries[ i ].end };
			}
		}
		return null;
	}

	/**
	 * 開始/終了の 'YYYY-MM-DD' から、下流画面が必要とする payload を組み立てる。
	 * 現行 admin-page-dataviewer.js の apply ハンドラと同一の値を生成する（Phase 1 接合仕様）。
	 */
	function buildChangePayload( startStr, endStr ) {
		var tz = ( window.qahm && qahm.wp_timezone ) ? qahm.wp_timezone : undefined;
		var startInst, endInst;
		if ( window.dayjs && dayjs.tz && tz ) {
			startInst = dayjs.tz( startStr, tz ).startOf( 'day' );
			endInst = dayjs.tz( endStr, tz ).endOf( 'day' );
		} else if ( window.dayjs ) {
			startInst = dayjs( startStr ).startOf( 'day' );
			endInst = dayjs( endStr ).endOf( 'day' );
		} else {
			startInst = null;
			endInst = null;
		}

		var sStr = startInst ? startInst.format( 'YYYY-MM-DD' ) : startStr;
		var eStr = endInst ? endInst.format( 'YYYY-MM-DD' ) : endStr;

		return {
			startStr: sStr,
			endStr: eStr,
			// dayjs 不在時のフォールバックは時刻付き文字列でローカル解釈させる（'YYYY-MM-DD' 単独だと UTC midnight 扱いで日ズレし得る）
			startDate: startInst ? startInst.toDate() : new Date( sStr + 'T00:00:00' ),
			endDate: endInst ? endInst.toDate() : new Date( eStr + 'T23:59:59.999' ),
			dateBetween: 'date = between ' + sStr + ' and ' + eStr,
			ymdArray: buildYmdArray( sStr, eStr ),
		};
	}

	/**
	 * 開始〜終了（両端含む）の 'YYYY-MM-DD' 配列。
	 */
	function buildYmdArray( startStr, endStr ) {
		var out = [];
		if ( ! window.dayjs ) {
			return out;
		}
		var cur = dayjs( startStr );
		var end = dayjs( endStr );
		// 異常（start > end）は空配列を返す
		var guard = 0;
		while ( ( cur.isBefore( end ) || cur.isSame( end, 'day' ) ) && guard < MAX_RANGE_DAYS ) {
			out.push( cur.format( 'YYYY-MM-DD' ) );
			cur = cur.add( 1, 'day' );
			guard++;
		}
		return out;
	}

	// ---- cookie ヘルパ（qahm.getSafeCookie / setSafeCookie があれば利用） ----

	function readCookie( name ) {
		if ( window.qahm && typeof qahm.getSafeCookie === 'function' ) {
			return qahm.getSafeCookie( name );
		}
		return null;
	}

	function writeCookie( name, value, maxAge ) {
		if ( window.qahm && typeof qahm.setSafeCookie === 'function' ) {
			qahm.setSafeCookie( name, value, maxAge );
		}
	}

	// ---- DOM 構築 ----

	/**
	 * <calendar-range> を生成する。months 個の <calendar-month> を内包。
	 */
	function createCalendarElement( options, monthsCount, baseYmd ) {
		var range = document.createElement( 'calendar-range' );
		var months = monthsCount || options.months || 2;
		range.setAttribute( 'months', String( months ) );
		// 既定ロケールは 'ja'（JSDoc の既定値を実適用）
		range.setAttribute( 'locale', options.locale || 'ja' );
		// 既定は 0=日曜始まり（現行 daterangepicker の moment 'ja'/'en' = firstDayOfWeek 0 と一致）
		var firstDow = ( typeof options.firstDayOfWeek === 'number' ) ? options.firstDayOfWeek : 0;
		range.setAttribute( 'first-day-of-week', String( firstDow ) );
		if ( options.min ) {
			range.setAttribute( 'min', options.min );
		}
		if ( options.max ) {
			range.setAttribute( 'max', options.max );
		}
		// 縦スクロール版: 全月を縦積み（‹ › 送りは使わずスロット差し替え不要・Cally 既定ナビは CSS 非表示）。
		// Cally の月見出しは「月のみ」で年を出せない（formatMonth は month の種別文字列）。そこで各月の前に
		// 自前の「年 月」ラベル div を挿入する。calendar-range は子の calendar-month のみを機能対象に取るため、
		// 間に div を挟んでも offset 計算には影響しない（div は ::part(months) の flex に並んで表示されるだけ）。
		var locale = options.locale || 'ja';
		var ymFmt = null;
		try { ymFmt = new Intl.DateTimeFormat( locale, { year: 'numeric', month: 'long' } ); } catch ( e ) { ymFmt = null; }
		var base = ( baseYmd && YMD_RE.test( baseYmd ) )
			? new Date( +baseYmd.slice( 0, 4 ), +baseYmd.slice( 5, 7 ) - 1, 1 )
			: new Date();
		for ( var i = 0; i < months; i++ ) {
			var d = new Date( base.getFullYear(), base.getMonth() + i, 1 );
			var label = document.createElement( 'div' );
			label.className = 'qahm-daterange-monthlabel';
			label.textContent = ymFmt ? ymFmt.format( d ) : ( d.getFullYear() + '/' + ( d.getMonth() + 1 ) );
			range.appendChild( label );
			var month = document.createElement( 'calendar-month' );
			if ( i > 0 ) {
				month.setAttribute( 'offset', String( i ) );
			}
			range.appendChild( month );
		}
		return range;
	}

	/**
	 * プリセットボタン群を生成する。
	 */
	function createPresetBar( presets, onPick ) {
		var bar = document.createElement( 'div' );
		bar.className = 'qahm-daterange-presets';
		presets.forEach( function ( preset ) {
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'qahm-daterange-preset';
			btn.textContent = preset.label;
			btn.addEventListener( 'click', function () {
				onPick( preset.start, preset.end );
			} );
			bar.appendChild( btn );
		} );
		return bar;
	}

	/**
	 * 年/月クイック移動セレクト（旧 daterangepicker の showDropdowns 相当 / #1330）。
	 * Cally の focusedDate を操作して表示月を一気に移動する。
	 *  - 日本語ロケールは「年▼ 月▼」順（その他は「月▼ 年▼」）。
	 *  - 月の選択肢は min/max でクランプ（データ外の未来/過去月は出さない）。
	 *  - セレクト変更時は onPick(ymd)（クランプ済み月初）を呼ぶ。focusedDate 設定・表示同期・
	 *    アニメ判定は init 側（Cally の実 focusedDate を真実にする）。
	 * 返り値の setSelects は ‹ ›・ホイール操作後に init から呼ぶ（実表示月へ同期）。
	 */
	function createMonthNav( calendarEl, options, initialYmd, onPick ) {
		var locale = options.locale || 'ja';
		var isJa = /^ja/i.test( locale );
		var bar = document.createElement( 'div' );
		bar.className = 'qahm-daterange-monthnav';

		var minKey = options.min ? options.min.slice( 0, 7 ) : null; // 'YYYY-MM'
		var maxKey = options.max ? options.max.slice( 0, 7 ) : null;
		var minY = options.min ? +options.min.slice( 0, 4 ) : null;
		var maxY = options.max ? +options.max.slice( 0, 4 ) : null;
		var minM = options.min ? ( +options.min.slice( 5, 7 ) - 1 ) : null;
		var maxM = options.max ? ( +options.max.slice( 5, 7 ) - 1 ) : null;

		var baseY = ( initialYmd && YMD_RE.test( initialYmd ) ) ? +initialYmd.slice( 0, 4 ) : new Date().getFullYear();
		var loY = ( minY !== null ) ? minY : baseY - 10;
		var hiY = ( maxY !== null ) ? maxY : baseY + 1;
		if ( loY > baseY ) { loY = baseY; }
		if ( hiY < baseY ) { hiY = baseY; }

		var monthFmt = null;
		try { monthFmt = new Intl.DateTimeFormat( locale, { month: 'long' } ); } catch ( e ) { monthFmt = null; }
		function monthLabel( m ) { return monthFmt ? monthFmt.format( new Date( 2021, m, 1 ) ) : ( m + 1 ) + '月'; }

		// 年ラベルの書式。月ラベルと同様にロケールから解決する（日本語なら「2026年」）。
		var yearFmt = null;
		try { yearFmt = new Intl.DateTimeFormat( locale, { year: 'numeric' } ); } catch ( e ) { yearFmt = null; }
		function yearLabel( y ) {
			return yearFmt ? yearFmt.format( new Date( y, 0, 1 ) ) : ( isJa ? y + '年' : String( y ) );
		}

		var yearSel = document.createElement( 'select' );
		yearSel.className = 'qahm-daterange-yearsel';
		yearSel.setAttribute( 'aria-label', l10n( 'calender_select_year', 'Select year' ) );
		for ( var y = loY; y <= hiY; y++ ) {
			var yo = document.createElement( 'option' );
			yo.value = String( y );
			yo.textContent = yearLabel( y );
			yearSel.appendChild( yo );
		}

		var monthSel = document.createElement( 'select' );
		monthSel.className = 'qahm-daterange-monthsel';
		monthSel.setAttribute( 'aria-label', l10n( 'calender_select_month', 'Select month' ) );

		// 選択中の年に応じて月 options を [min,max] でクランプ再構築。
		function rebuildMonths( keepMonth ) {
			var yv = +yearSel.value;
			var lo = ( minY !== null && yv === minY ) ? minM : 0;
			var hi = ( maxY !== null && yv === maxY ) ? maxM : 11;
			var cur = ( typeof keepMonth === 'number' ) ? keepMonth : +monthSel.value;
			if ( cur < lo ) { cur = lo; }
			if ( cur > hi ) { cur = hi; }
			monthSel.innerHTML = '';
			for ( var m = lo; m <= hi; m++ ) {
				var mo = document.createElement( 'option' );
				mo.value = String( m );
				mo.textContent = monthLabel( m );
				monthSel.appendChild( mo );
			}
			monthSel.value = String( cur );
		}

		// 並び順: 日本語は「年▼ 月▼」、その他は「月▼ 年▼」
		if ( isJa ) {
			bar.appendChild( yearSel );
			bar.appendChild( monthSel );
		} else {
			bar.appendChild( monthSel );
			bar.appendChild( yearSel );
		}

		// セレクト表示を 'YYYY-MM-DD' に同期する（年・月セレクトを実表示月へ合わせる）。
		function setSelects( ymd ) {
			if ( ! ymd || ! YMD_RE.test( ymd ) ) {
				return;
			}
			var yy = ymd.slice( 0, 4 );
			if ( yearSel.querySelector( 'option[value="' + yy + '"]' ) ) {
				yearSel.value = yy;
			}
			rebuildMonths( +ymd.slice( 5, 7 ) - 1 );
		}

		// 'YYYY-MM' を [min,max] にクランプして 'YYYY-MM-01' を返す。
		function clampMonthYmd( key ) {
			if ( minKey && key < minKey ) { key = minKey; }
			if ( maxKey && key > maxKey ) { key = maxKey; }
			return key + '-01';
		}

		// セレクト変更時は、クランプした月初を onPick へ渡す（focusedDate 設定＋同期は init 側）。
		function onSelectChange() {
			var key = yearSel.value + '-' + ( '0' + ( +monthSel.value + 1 ) ).slice( -2 );
			if ( typeof onPick === 'function' ) {
				onPick( clampMonthYmd( key ) );
			}
		}
		function onYearChange() {
			rebuildMonths(); // 年が変わると月範囲が変わり得る
			onSelectChange();
		}

		yearSel.addEventListener( 'change', onYearChange );
		monthSel.addEventListener( 'change', onSelectChange );
		setSelects( initialYmd );

		return {
			el: bar,
			setSelects: setSelects,
			destroy: function () {
				yearSel.removeEventListener( 'change', onYearChange );
				monthSel.removeEventListener( 'change', onSelectChange );
			},
		};
	}

	// ---- メイン ----

	function init( targetEl, options ) {
		options = options || {};
		var jqEvent = options.jqEvent !== false;
		var cookie = options.cookie || null;
		// cookie 設定が不完全（キー名欠落）なら無視する
		if ( cookie && ( ! cookie.startName || ! cookie.endName ) ) {
			cookie = null;
		}

		// 初期範囲: cookie（あれば）→ options.start/end
		var startStr = options.start;
		var endStr = options.end;
		if ( cookie && cookie.read !== false ) {
			var cs = normalizeCookieDate( readCookie( cookie.startName ) );
			var ce = normalizeCookieDate( readCookie( cookie.endName ) );
			if ( cs && ce ) {
				startStr = cs;
				endStr = ce;
			}
		}

		// プリセット
		var presets = options.presets;
		if ( ! presets && window.qahm && qahm.dateUtils ) {
			presets = defaultPresets( options.presetLabels, {
				today: qahm.dateUtils.getToday( 'YYYY-MM-DD' ),
				smartYesterday: qahm.dateUtils.getSmartYesterday( 'YYYY-MM-DD' ),
				min: options.min,
				max: options.max,
			} );
		}
		presets = presets || [];

		// ポップオーバー（案A）: trigger 指定時はクリックで開閉するドロップダウン
		var trigger = options.trigger || null;
		// portal（#1353）: overflow:hidden のスクロール枠に埋め込む画面向けの opt-in。
		// true のとき popover を body 直下に fixed 配置し、祖先のクリップ・offsetParent ずれを回避する。
		var portal = !! ( trigger && options.portal );
		var formatLabel = ( typeof options.formatLabel === 'function' )
			? options.formatLabel
			: function ( s, e ) { return s + ' 〜 ' + e; };
		var closeOnApply = options.closeOnApply !== false;

		// 縦スクロールで描画する全月数（min〜max を全部並べる）。min/max 無ければ start/end、無ければ 12 か月。
		var spanMin = options.min || startStr || endStr || null;
		var spanMax = options.max || startStr || endStr || null;
		var monthsCount = ( spanMin && spanMax && spanMin <= spanMax ) ? monthSpan( spanMin, spanMax ) : 12;
		if ( monthsCount < 1 ) { monthsCount = 1; }
		if ( monthsCount > MAX_MONTHS ) { monthsCount = MAX_MONTHS; }

		// DOM
		var container = document.createElement( 'div' );
		container.className = 'qahm-daterange qahm-daterange--vscroll'
			+ ( trigger ? ' qahm-daterange--popover' : '' )
			+ ( portal ? ' qahm-daterange--portal' : '' );

		// プリセット（左ブロックに格納＝GA4 型の縦並び）。
		if ( presets.length ) {
			container.appendChild( createPresetBar( presets, function ( s, e ) {
				calendarEl.value = toValue( s, e );
				applyRange( s, e ); // プログラム変更では change が出ないため明示適用
				scrollToMonth( s, true ); // プリセット指定もスムーズスクロール
			} ) );
		}

		// スクロール領域 ＋ カレンダー（全月を縦積み）
		var scrollEl = document.createElement( 'div' );
		scrollEl.className = 'qahm-daterange-scroll';
		var calendarEl = createCalendarElement( options, monthsCount, spanMin );
		// 全月を spanMin 起点で並べる（focusedDate=spanMin の月）。以後 focusedDate は触らない。
		if ( spanMin && YMD_RE.test( spanMin ) ) {
			calendarEl.focusedDate = spanMin;
		}
		if ( startStr && endStr ) {
			calendarEl.value = toValue( startStr, endStr );
		}
		scrollEl.appendChild( calendarEl );

		// 年/月クイックジャンプ（右上ブロックの期間ジャンプセレクト。既定 ON。monthSelect:false で無効化）。
		// 選択月へスクロールするだけ＝全月描画済みゆえ focusedDate 操作は不要（横2か月版の予測不能問題と無縁）。
		var monthNav = null;
		if ( options.monthSelect !== false ) {
			monthNav = createMonthNav( calendarEl, options, ( startStr || endStr ), function ( ymd ) {
				scrollToMonth( ymd, true ); // セレクトでの月指定はスムーズスクロール
				if ( monthNav ) { monthNav.setSelects( ymd ); }
			} );
			container.appendChild( monthNav.el );
		}

		container.appendChild( scrollEl );
		// portal は body 直下に置き、overflow:hidden の祖先（会話枠等）のクリップを回避する（位置は open 時に
		// trigger の getBoundingClientRect から fixed で算出）。非 portal は従来どおり mount 直下（absolute）。
		if ( portal ) {
			document.body.appendChild( container );
		} else {
			targetEl.appendChild( container );
		}

		var rafFn = window.requestAnimationFrame ? window.requestAnimationFrame.bind( window )
			: function ( cb ) { return setTimeout( cb, 16 ); };

		// spanMin から ymd までの月オフセットで calendar-month を特定し、scrollEl 内でその月を上端に寄せる。
		// scrollIntoView はページ全体も動かすため、scrollEl 相対で scrollTop を加算する方式にする。
		function scrollToMonth( ymd, smooth ) {
			if ( ! ymd || ! YMD_RE.test( ymd ) || ! spanMin ) { return; }
			var off = monthSpan( spanMin, ymd ) - 1;
			if ( off < 0 ) { off = 0; }
			var months = calendarEl.querySelectorAll( 'calendar-month' );
			var target = months[ off ];
			if ( ! target ) { return; }
			// 月の上に年月ラベルがあるので、ラベルを上端アンカーにする（無ければ月自身）。
			var anchor = target.previousElementSibling;
			if ( ! anchor || ( anchor.className || '' ).indexOf( 'qahm-daterange-monthlabel' ) === -1 ) {
				anchor = target;
			}
			var mRect = anchor.getBoundingClientRect();
			var sRect = scrollEl.getBoundingClientRect();
			var top = scrollEl.scrollTop + ( mRect.top - sRect.top );
			// セレクト/プリセット指定時はスムーズスクロール（reduced-motion は尊重）。初期表示・再オープンは即時。
			var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
			if ( smooth && ! reduce && typeof scrollEl.scrollTo === 'function' ) {
				scrollEl.scrollTo( { top: top, behavior: 'smooth' } );
			} else {
				scrollEl.scrollTop = top;
			}
		}

		// トリガー表示の更新
		function updateTriggerLabel( s, e ) {
			if ( ! trigger ) { return; }
			var label = formatLabel( s, e );
			if ( 'value' in trigger ) { trigger.value = label; } else { trigger.textContent = label; }
		}

		// 変更処理（プリセット / ユーザー操作 共通）
		function applyRange( s, e ) {
			var payload = buildChangePayload( s, e );
			if ( cookie ) {
				var useIso = ( cookie.writeFormat || 'iso' ) === 'iso';
				writeCookie( cookie.startName, useIso ? payload.startDate.toISOString() : payload.startStr, cookie.maxAge );
				writeCookie( cookie.endName, useIso ? payload.endDate.toISOString() : payload.endStr, cookie.maxAge );
			}
			updateTriggerLabel( payload.startStr, payload.endStr );
			// 次回オープン時の初期スクロール基準を更新（範囲変更を反映＝再オープンで新しい開始月へスクロール）。
			startStr = payload.startStr;
			endStr = payload.endStr;
			// onChange はイベント発火より先（下流のモジュールスコープ変数更新を保証）
			if ( typeof options.onChange === 'function' ) { options.onChange( payload ); }
			if ( jqEvent && window.jQuery ) {
				jQuery( document ).trigger( 'qahm:dateRangeChanged', [ payload.startDate, payload.endDate ] );
			}
			// セレクト表示を選択範囲の終了月へ同期
			if ( monthNav ) { monthNav.setSelects( payload.endStr ); }
			if ( trigger && closeOnApply ) { closePopover(); }
		}

		// Cally の change（ユーザー操作時のみ発火）
		function onCalendarChange() {
			var parsed = parseValue( calendarEl.value );
			if ( parsed ) { applyRange( parsed.start, parsed.end ); }
		}
		calendarEl.addEventListener( 'change', onCalendarChange );

		// ---- portal 位置決め（#1353・fixed・trigger の getBoundingClientRect 基準） ----
		// overflow:hidden の祖先を抜けて body 直下に固定配置するため、open 中はスクロール/リサイズで
		// 追従（再配置）し、トリガーが可視域から外れたら閉じる。listener は close/destroy で必ず外す。
		var portalListening = false;
		var repoRaf = 0;
		function onPortalReposition() {
			if ( repoRaf ) { return; } // スクロール連打を rAF で間引く
			repoRaf = rafFn( function () { repoRaf = 0; positionPortal(); } );
		}
		function positionPortal() {
			if ( ! portal || ! isOpen || ! trigger || ! trigger.getBoundingClientRect ) { return; }
			var r = trigger.getBoundingClientRect();
			var vw = window.innerWidth || document.documentElement.clientWidth;
			var vh = window.innerHeight || document.documentElement.clientHeight;
			// トリガーが可視域から完全に外れたら閉じる（会話枠スクロールで追い出された等）
			if ( r.bottom <= 0 || r.top >= vh || r.right <= 0 || r.left >= vw ) {
				closePopover();
				return;
			}
			var edge = 8, gap = 4;
			// 下に入らず上に余地がある場合は上開き（flip）。可視域に収まるよう scrollEl を縮める。
			var spaceBelow = vh - r.bottom - gap - edge;
			var spaceAbove = r.top - gap - edge;
			var openUp = ( container.offsetHeight > spaceBelow && spaceAbove > spaceBelow );
			var avail = openUp ? spaceAbove : spaceBelow;
			// カレンダースクロール領域を可視域に収める（presets/monthnav ヘッダは固定のまま）。
			scrollEl.style.maxHeight = '';
			var headerH = container.offsetHeight - scrollEl.offsetHeight; // presets/monthnav/padding 等
			var scrollMax = avail - headerH;
			if ( scrollMax < 120 ) { scrollMax = 120; } // 最低限の高さは確保（極端に低いビューポートの保険）
			if ( scrollMax < 442 ) { scrollEl.style.maxHeight = Math.floor( scrollMax ) + 'px'; }
			// 縦位置（再測定後に確定）
			var ph = container.offsetHeight;
			var top = openUp ? ( r.top - gap - ph ) : ( r.bottom + gap );
			if ( top + ph > vh - edge ) { top = vh - edge - ph; }
			if ( top < edge ) { top = edge; }
			// 横位置: トリガー左端に合わせ、ビューポート内にクランプ
			var pw = container.offsetWidth;
			var left = r.left;
			if ( left + pw > vw - edge ) { left = vw - edge - pw; }
			if ( left < edge ) { left = edge; }
			container.style.left = Math.round( left ) + 'px';
			container.style.top = Math.round( top ) + 'px';
		}
		function addPortalListeners() {
			if ( portalListening ) { return; }
			portalListening = true;
			// capture=true ＝ scroll はバブルしないため、会話枠など任意のスクロールコンテナの scroll も拾う
			window.addEventListener( 'scroll', onPortalReposition, true );
			window.addEventListener( 'resize', onPortalReposition );
		}
		function removePortalListeners() {
			if ( ! portalListening ) { return; }
			portalListening = false;
			window.removeEventListener( 'scroll', onPortalReposition, true );
			window.removeEventListener( 'resize', onPortalReposition );
			if ( repoRaf && window.cancelAnimationFrame ) { window.cancelAnimationFrame( repoRaf ); }
			repoRaf = 0;
		}

		// ---- ポップオーバー開閉 ----
		var isOpen = false;
		function openPopover() {
			if ( isOpen ) { return; }
			container.classList.add( 'is-open' );
			isOpen = true;
			document.addEventListener( 'mousedown', onDocMouseDown, true );
			document.addEventListener( 'keydown', onKeydown, true );
			// portal: body 直下に fixed 配置（is-open で display 確定後に測位）＋スクロール/リサイズ追従を購読。
			if ( portal ) { positionPortal(); addPortalListeners(); }
			// セレクト表示を開始月へ同期（前回の範囲変更後でも再オープン時に開始月へそろえる）。
			if ( monthNav ) { monthNav.setSelects( startStr || endStr ); }
			// 開いた直後に選択範囲の開始月へスクロール（非表示中はレイアウト不可のため open 後に）。
			// Cally のレンダリング（calendar-month の高さ確定）後に走らせるため rAF を2段に。
			rafFn( function () { rafFn( function () {
				scrollToMonth( startStr || endStr );
				// Cally の月高さ確定後に再測位（portal の高さ依存を正す）。
				if ( portal ) { positionPortal(); }
			} ); } );
		}
		function closePopover() {
			if ( ! isOpen ) { return; }
			container.classList.remove( 'is-open' );
			isOpen = false;
			document.removeEventListener( 'mousedown', onDocMouseDown, true );
			document.removeEventListener( 'keydown', onKeydown, true );
			if ( portal ) { removePortalListeners(); }
		}
		function togglePopover() { isOpen ? closePopover() : openPopover(); }
		function onDocMouseDown( ev ) {
			if ( container.contains( ev.target ) || ( trigger && trigger.contains && trigger.contains( ev.target ) ) || ev.target === trigger ) { return; }
			closePopover();
		}
		function onKeydown( ev ) {
			if ( ev.key === 'Escape' || ev.keyCode === 27 ) {
				closePopover();
				if ( trigger && trigger.focus ) { trigger.focus(); }
			}
		}
		function onTriggerClick() { togglePopover(); }

		if ( trigger ) {
			if ( 'readOnly' in trigger ) { trigger.readOnly = true; } // 手入力を防ぎ表示専用に
			if ( startStr && endStr ) { updateTriggerLabel( startStr, endStr ); }
			trigger.addEventListener( 'click', onTriggerClick );
		} else {
			// インライン表示時は初期スクロールを直接（次フレーム）
			// Cally のレンダリング（calendar-month の高さ確定）後に走らせるため rAF を2段に。
			rafFn( function () { rafFn( function () { scrollToMonth( startStr || endStr ); } ); } );
		}
		// 初期セレクト表示を選択範囲の終了月へ
		if ( monthNav ) { monthNav.setSelects( startStr || endStr ); }

		return {
			getRange: function () { return parseValue( calendarEl.value ); },
			open: openPopover,
			close: closePopover,
			destroy: function () {
				calendarEl.removeEventListener( 'change', onCalendarChange );
				if ( monthNav ) { monthNav.destroy(); }
				if ( trigger ) {
					trigger.removeEventListener( 'click', onTriggerClick );
					document.removeEventListener( 'mousedown', onDocMouseDown, true );
					document.removeEventListener( 'keydown', onKeydown, true );
				}
				removePortalListeners(); // portal の scroll/resize リスナーを確実に外す（#1353・非 portal は no-op）
				if ( container.parentNode ) { container.parentNode.removeChild( container ); }
			},
		};
	}

	qahm.DateRange = {
		init: init,
		// 以下はテスト用に公開（純粋関数）
		parseValue: parseValue,
		toValue: toValue,
		isIsoDateTime: isIsoDateTime,
		normalizeCookieDate: normalizeCookieDate,
		defaultPresets: defaultPresets,
		resolveRelative: resolveRelativeRange,
		buildChangePayload: buildChangePayload,
		buildYmdArray: buildYmdArray,
	};
} )();
