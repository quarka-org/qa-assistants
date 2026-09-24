/*
■ 動作仕様
・人数の更新は10秒に1回
・テーブルの更新は1分に1回

1. ページを開いたタイミングで更新
2. タブがアクティブのとき上記時間経過したら更新
3. タブが非アクティブのとき上記時間経過後タブを戻したら更新
4. タブが非アクティブ中のときは更新関数を何度も実行しないよう対応
5. タブがアクティブのとき、かつウインドウのフォーカスが非アクティブの場合でも更新

■ グラフ仕様（#935）
・アクティブページTOP5: テーブルデータの last_title を集計 → 横棒グラフ
・カウントアップアニメ: 数値変化時にCSSアニメーション
*/

var qahm = qahm || {};

qahm.updateRealtimeListCnt = 0;
qahm.updateSessionNumCnt = 0;
qahm.nextRealtimeUpdate = 0;  // 次のテーブル更新時刻を保持
qahm.nextSessionUpdate = 0;   // 次の人数更新時刻を保持

// URLパラメータから tracking_id を取得
qahm.tracking_id = new URLSearchParams(window.location.search).get('tracking_id') || 'all';

let intervalSessionNum;

// --- グラフ ---
var regionsChart = null;
var referrersChart = null;
var deviceChart = null;

// 人数とテーブルの更新をチェックして実行
function checkAndUpdate() {
    const now = new Date().getTime();

    // 人数の更新 (10秒ごと)
    if (now >= qahm.nextSessionUpdate) {
        qahm.updateSessionNum();
        qahm.nextSessionUpdate = now + 10000; // 次回更新は10秒後
    }

    // テーブルの更新 (1分ごと)
    if (now >= qahm.nextRealtimeUpdate) {
        qahm.updateRealtimeList();
        qahm.nextRealtimeUpdate = now + 60000; // 次回更新は1分後
    }
}

function startIntervals() {
    if (!intervalSessionNum) {
        intervalSessionNum = setInterval(function() {
            if (document.visibilityState === 'visible') {
                checkAndUpdate();
            }
        }, 1000 * 10); // 10秒ごとにチェックして更新
    }
}

function stopIntervals() {
    if (intervalSessionNum) {
        clearInterval(intervalSessionNum);
        intervalSessionNum = null;
    }
}

// タブの可視状態変更時のイベント
function handleVisibilityChange() {
    const now = new Date().getTime();

    if (document.visibilityState === 'visible') {
        // 1分以上経過している場合は即時更新を実行
        if (now >= qahm.nextSessionUpdate || now >= qahm.nextRealtimeUpdate) {
            checkAndUpdate();
        }
        // 定期更新を再開
        startIntervals();
    } else {
        // タブが非アクティブのとき、定期更新を停止
        stopIntervals();
    }
}

// ページがロードされたときの初期処理
window.addEventListener('DOMContentLoaded', function() {
    qahm.openReplayView();

    // グラフ初期化
    qahm.initRegionsChart();
    qahm.initReferrersChart();
    qahm.initDeviceChart();

    // パルスライン初期化（イベント駆動トレース）
    qahm.heartbeat.init();

    // 人数のカウントアップをパルスの“ビクン”（R波を描く瞬間）に同期
    qahm.heartbeat.onPeak( qahm.flushSessionNums );

    // ページを開いた直後の即時更新（AJAX完了時にパルスが発火する）
    checkAndUpdate();

    // 次回の更新時刻を設定し、定期更新を開始
    const now = new Date().getTime();
    qahm.nextSessionUpdate = now + 10000; // 次回の人数更新時刻は10秒後
    qahm.nextRealtimeUpdate = now + 60000; // 次回のテーブル更新時刻は1分後

    startIntervals();

    // 可視状態の変更を監視
    document.addEventListener('visibilitychange', handleVisibilityChange);
    window.addEventListener('focus', handleVisibilityChange);  // フォーカス時もチェック
});


window.addEventListener('DOMContentLoaded', function() {
	// create session recoding table
	sesRecHeader = [
		{ key: 'tanmatsu', label: qahml10n['table_tanmatsu'], width: 5 },
		{ key: 'ridatsujikoku', label: qahml10n['table_ridatsujikoku'], width: 13, textAlign: 'center',
			formatter: function(value, row) {
				if (!value) return '';
				// Unixタイムスタンプは秒単位なので、ミリ秒に変換
				var date = new Date(value * 1000);

				// 各要素を2桁に揃える
				var y = date.getFullYear();
				var m = String(date.getMonth() + 1).padStart(2, '0');
				var d = String(date.getDate()).padStart(2, '0');
				var h = String(date.getHours()).padStart(2, '0');
				var min = String(date.getMinutes()).padStart(2, '0');
				var s = String(date.getSeconds()).padStart(2, '0');

				return y + '/' + m + '/' + d + ' ' + h + ':' + min + ':' + s;
			}
		},
		{ key: 'landing_page_url', hidden: true },
		{ key: 'landing_page', label: qahml10n['table_1page_me'], width: 18,
			formatter: function(value, row) {
				return '<a href="' + row.landing_page_url + '" target="_blank" rel="noopener">' + value + '</a>';
			}
		},
		{ key: 'ridatsu_page_url', hidden: true },
		{ key: 'ridatsu_page', label: qahml10n['table_ridatsu_page'], width: 18,
			formatter: function(value, row) {
				return '<a href="' + row.ridatsu_page_url + '" target="_blank" rel="noopener">' + value + '</a>';
			}
		},
		{ key: 'referrer_url', hidden: true },
		{ key: 'referrer', label: qahml10n['table_referrer'], width: 12, formatter: function(value, row) {
			if ( value !== 'direct' && value !== qahml10n['table_total'] ) {
				ret = '<a href="' + row.referrer_url + '" target="_blank" rel="noopener">' + value + '</a>';
			} else {
				ret = value;
			}
			return ret;
    	} },
		// #903: メディア列（utm_medium — 参照元の右）
		{ key: 'media', label: qahml10n['table_media'] || 'Medium', width: 8 },
		{ key: 'pv', label: qahml10n['table_pv'], width: 5, type: 'integer' },
		{ key: 'site_taizaijikan', label: qahml10n['table_site_taizaijikan'], width: 8, type: 'duration' },
		{ key: 'saisei', label: qahml10n['table_saisei'], width: 5, sortable: false, exportable: false, filtering: false, formatter: function(value, row) {
			return '<div class="qa-table-replay-container">' +
					'<span class="icon-replay" data-work_base_name="' + value + '"><span class="dashicons dashicons-format-video"></span></span>' +
				'</div>';
    	} },
	];
	sesRecOptions = {
		perPage: 100,
		pagination: true,
		exportable: true,
		sortable: true,
        filtering: true,
		columnToggle: true,
		maxHeight: 600,
		stickyHeader: true,
		initialSort: {
			column: 'ridatsujikoku',
			direction: 'desc'
		}
	};
	sesRecTable = qaTable.createTable('#tday_table', sesRecHeader, sesRecOptions);
	sesRecTable.showLoading();
});

qahm.openReplayView = function() {
	jQuery( document ).on( 'click', '.icon-replay', function(){
		qahm.showLoadIcon();

		let start_time = new Date().getTime();
		jQuery.ajax(
			{
				type: 'POST',
				url: qahm.ajax_url,
				dataType : 'text',
				data: {
					'action'        : 'qahm_ajax_create_replay_file_to_raw_data',
					'work_base_name': jQuery( this ).data( 'work_base_name' ),
					'replay_id'     : 1,
				},
			}
		).done(
			function( url ){
				if ( url.startsWith("http")) {
					// 最低読み込み時間経過後に処理実行
					let now_time  = new Date().getTime();
					let load_time = now_time - start_time;
					let min_time  = 400;

					if ( load_time < min_time ) {
						// ロードアイコンを削除して新しいウインドウを開く
						setTimeout(
							function(){
								window.open( url, '_blank' );
							},
							(min_time - load_time)
						);
					} else {
						window.open( url, '_blank' );
					}
				} else {
					AlertMessage.alert(
						qahml10n['realtime_replay_alert1'],
						qahml10n['realtime_replay_alert2'],
						'error',
						function(){}
					);
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

// パルスのピークに同期して反映する人数（保留値）
qahm.pendingSessionNums = null;
qahm.pendingSessionTimer = null;
qahm.flushSessionNums = function() {
	if ( ! qahm.pendingSessionNums ) { return; }
	clearTimeout( qahm.pendingSessionTimer );
	qahm.animateCount( jQuery( '#session_num' ), qahm.pendingSessionNums[ 0 ] );
	qahm.animateCount( jQuery( '#session_num_1min' ), qahm.pendingSessionNums[ 1 ] );
	qahm.pendingSessionNums = null;
};

// --- カウントアニメーション（ティッキング式） ---
qahm.animateCount = function( el, newValue ) {
	var currentText = el.text();
	var newText = String( newValue );
	if ( currentText === newText ) {
		return; // 変化なし
	}

	// 実行中のアニメーションをキャンセル
	var rafId = el.data( 'animateRafId' );
	if ( rafId ) {
		cancelAnimationFrame( rafId );
		el.removeData( 'animateRafId' );
	}

	var oldNum = parseInt( currentText, 10 );
	var newNum = parseInt( newText, 10 );

	if ( isNaN( newNum ) ) {
		el.text( newText );
		return;
	}
	// 初回（"-" → 数値）は 0 からカウントアップして登場させる
	if ( isNaN( oldNum ) ) {
		oldNum = 0;
	}

	// 変化の瞬間に軽くポップ。グラデーション文字（background-clip:text）を持つ親に適用する:
	// span 側に transform を掛けると塗りが透明落ちしてカウントが見えなくなるため
	var popEl = el.parent( '.qa-zero-data-box__value' );
	if ( ! popEl.length ) {
		popEl = el;
	}
	popEl.removeClass( 'qa-zero-realtime-count-animate' );
	void popEl[ 0 ].offsetWidth;
	popEl.addClass( 'qa-zero-realtime-count-animate' );

	// 数値を高速カウントで遷移させる
	var diff = newNum - oldNum;
	var duration = 400;
	var startTime = null;

	function tick( timestamp ) {
		if ( ! startTime ) {
			startTime = timestamp;
		}
		var progress = Math.min( ( timestamp - startTime ) / duration, 1 );
		// easeOut (cubic)
		var eased = 1 - Math.pow( 1 - progress, 3 );
		var current = Math.round( oldNum + diff * eased );
		el.text( current );
		if ( progress < 1 ) {
			el.data( 'animateRafId', requestAnimationFrame( tick ) );
		} else {
			el.removeData( 'animateRafId' );
		}
	}

	el.data( 'animateRafId', requestAnimationFrame( tick ) );
};

qahm.updateSessionNum = function() {
	if ( jQuery('#session_num').length === 0 || qahm.updateSessionNumCnt > 0 ) {
		return;
	}
	qahm.updateSessionNumCnt++;

	jQuery.ajax(
		{
			type: 'POST',
			url: qahm.ajax_url,
			dataType : 'json',
			data: {
				'action' : 'qahm_ajax_get_session_num',
				'tracking_id' : qahm.tracking_id,
			},
		}
	).done(
		function( data ){
			if ( data ) {
				// 数値変化を検出
				var prevNum = jQuery('#session_num').text();
				var prevNum1min = jQuery('#session_num_1min').text();
				var changed = ( prevNum !== String( data['session_num'] ) ) ||
				              ( prevNum1min !== String( data['session_num_1min'] ) );

				if ( changed ) {
					// 数値の反映はパルスの“ビクン”（R波を描く瞬間）に同期させる
					qahm.pendingSessionNums = [ data['session_num'], data['session_num_1min'] ];
					qahm.fireHeartbeat();
					// 万一ピークが来ない場合の保険（3秒で反映）
					clearTimeout( qahm.pendingSessionTimer );
					qahm.pendingSessionTimer = setTimeout( qahm.flushSessionNums, 3000 );
				} else {
					qahm.animateCount( jQuery('#session_num'), data['session_num'] );
					qahm.animateCount( jQuery('#session_num_1min'), data['session_num_1min'] );
				}
			}
		}
	).fail(
		function( jqXHR, textStatus, errorThrown ){
			jQuery( '#session_num' ).text( 'please reload' );
			jQuery( '#session_num_1min' ).text( 'please reload' );
			qahm.log_ajax_error( jqXHR, textStatus, errorThrown );
		}
	).always(
		function(){
			qahm.updateSessionNumCnt--;
		}
	);
}


qahm.updateRealtimeList = function() {
	if ( qahm.updateRealtimeListCnt > 0 ) {
		return;
	}
	qahm.updateRealtimeListCnt++;

	jQuery.ajax(
		{
			type: 'POST',
			url: qahm.ajax_url,
			dataType : 'json',
			data: {
				'action' : 'qahm_ajax_get_realtime_list',
				'tracking_id' : qahm.tracking_id,
			},
		}
	).done(
		function( data ){
			if ( ! data ) {
				sesRecTable.updateData([]);
				qahm.updateRealtimeCharts( [] );
				return;
			}
			if (typeof sesRecTable !== 'undefined' && sesRecTable !== '') {
				if ( data['realtime_list'].length > 0 ) {
					jQuery( '#update_time' ).hide().text(data['update_time']).fadeIn(4000,'swing');
					sesRecTable.updateData(data['realtime_list']);

					// 全グラフを更新（30分フィルタ付き）
					qahm.updateRealtimeCharts( data['realtime_list'] );
				} else {
					sesRecTable.updateData([]);
					qahm.updateRealtimeCharts( [] );
				}
			}
		}
	).fail(
		function( jqXHR, textStatus, errorThrown ){
			jQuery( '#update_time' ).text( 'please reload' );
			qahm.log_ajax_error( jqXHR, textStatus, errorThrown );
		}
	).always(
		function(){
			qahm.updateRealtimeListCnt--;
		}
	);
}


// ============================================
// 横棒グラフ共通（TOP5）— qahm.EChart ラッパ経由（#1280）
// ============================================

// TOP5 の濃淡ベース色
var qahmRtTop5Colors = {
	regions:   'rgb(0, 186, 141)',
	referrers: 'rgb(0, 166, 214)'
};

// ツールチップ用エスケープ（タイトル・参照元は外部由来文字列のため）
function qahmRtEscHtml( str ) {
	return String( str ).replace( /[&<>"']/g, function( ch ) {
		return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ ch ];
	});
}

// realtimeList を集計して hbar 用 spec を作る（大きい順。並び順の反転はラッパが吸収）
qahm.buildTop5Spec = function( realtimeList, titleIndex, baseColor ) {
	var counts = {};
	for ( var i = 0; i < realtimeList.length; i++ ) {
		var title = realtimeList[i][ titleIndex ];
		if ( ! title ) { continue; }
		counts[ title ] = ( counts[ title ] || 0 ) + 1;
	}
	var sorted = Object.keys( counts ).map( function( k ) {
		return { title: k, count: counts[k] };
	}).sort( function( a, b ) {
		return b.count - a.count;
	}).slice( 0, 5 );

	// データ数に応じて濃淡を生成（大きいほど濃く）
	var opacities = [0.8, 0.65, 0.5, 0.4, 0.3];
	var colors = sorted.map( function( item, idx ) {
		var op = opacities[ idx ] || 0.3;
		return baseColor.replace( ')', ', ' + op + ')' ).replace( 'rgb', 'rgba' );
	});

	return {
		kind: 'hbar',
		labels: sorted.map( function( item ) { return item.title; } ),
		series: [{
			data: sorted.map( function( item ) { return item.count; } ),
			colors: colors
		}],
		// ホバーで省略前のフル名称を表示。
		// 件数は出さない: 集計元が「直近最大5000セッション」窓のため正確な実数ではない（管理人判断 2026-06-12）
		tooltip: {
			show: true,
			trigger: 'item',
			confine: true,
			formatter: function( p ) {
				return qahmRtEscHtml( p.name );
			}
		},
		empty: { text: 'No data' }
	};
};

// ============================================
// 地域 TOP5
// ============================================
qahm.initRegionsChart = function() {
	regionsChart = qahm.EChart.create( 'regions_chart', qahm.buildTop5Spec( [], 0, qahmRtTop5Colors.regions ) );
};

// ============================================
// 参照元 TOP5
// ============================================
qahm.initReferrersChart = function() {
	referrersChart = qahm.EChart.create( 'referrers_chart', qahm.buildTop5Spec( [], 0, qahmRtTop5Colors.referrers ) );
};

// ============================================
// デバイス内訳ドーナツ
// ============================================

// realtimeList を集計してドーナツ用 spec を作る
qahm.buildDeviceSpec = function( realtimeList ) {
	var counts = { desktop: 0, tablet: 0, mobile: 0 };
	for ( var i = 0; i < realtimeList.length; i++ ) {
		var dev = realtimeList[i][0];
		if ( counts[ dev ] !== undefined ) {
			counts[ dev ]++;
		}
	}
	var total = counts.desktop + counts.tablet + counts.mobile;
	var data, colors;
	if ( total === 0 ) {
		// データなし: グレーのプレースホルダーリング
		data   = [1, 0, 0];
		colors = ['#e0e0e0', '#e0e0e0', '#e0e0e0'];
	} else {
		data   = [ counts.desktop, counts.tablet, counts.mobile ];
		colors = ['#00ba8d', '#00cd0a', '#f59e0b'];
	}
	return {
		kind: 'pie',
		donut: true,
		labels: ['desktop', 'tablet', 'mobile'],
		series: [{ data: data, colors: colors }],
		legend: true,
		tooltip: { trigger: 'item', formatter: '{b}' }
	};
};

qahm.initDeviceChart = function() {
	deviceChart = qahm.EChart.create( 'device_chart', qahm.buildDeviceSpec( [] ) );
};

// ============================================
// 全グラフ一括更新（PHP側24hフィルタ済みデータをそのまま使用）
// ============================================
qahm.updateRealtimeCharts = function( realtimeList ) {
	qahm.EChart.update( regionsChart,   qahm.buildTop5Spec( realtimeList, 12, qahmRtTop5Colors.regions ) );   // country
	qahm.EChart.update( referrersChart, qahm.buildTop5Spec( realtimeList, 7,  qahmRtTop5Colors.referrers ) ); // referrer domain
	qahm.EChart.update( deviceChart,    qahm.buildDeviceSpec( realtimeList ) );
};

// ============================================
// パルスライン（常時駆動モニター型・#1280）
// 「平坦⇔波形」の切替をやめ、常に微小な“呼吸”が右→左へ流れ続けるトレースに、
// 人数変化時の心拍が右端から流れ込んで自然にスクロールアウトする。
// タブ非表示中は停止。prefers-reduced-motion 環境では静的波形 + グロー強調のみ。
// ============================================
qahm.heartbeat = ( function () {
	var X_MIN = 10, X_MAX = 190, Y_MID = 15, Y_MIN = 0.4, Y_MAX = 29.6; // viewBox 座標系
	var STEP = 2;                                  // 1サンプルの横幅
	var COUNT = ( X_MAX - X_MIN ) / STEP + 1;      // サンプル数
	var SPEED = 150;                               // 掃引速度 px/s（1パス ≒ 1.2 秒）
	var BLEND = 16;                                // ヘッド前方で新旧トレースを橋渡しする幅 px
	var SETTLE_DUR = 1800;                         // 掃引後、残光が形ごと平坦へ溶けて戻る時間 ms
	var IDLE_OPACITY = 0.3;                        // アイドル時の線の明るさ（半透明・均一）
	var COMPLEX_X = 95;                            // 心拍複合波の開始位置（毎回固定で構図を安定させる）
	var trace = null, head = null, container = null;
	var gradStops = null;                          // 残光グラデーションの stop 要素
	var ys = [], idleYs = [];                      // 表示値 / 静止時の平坦ライン形状
	var headX = X_MIN, writeIdx = -1;
	var writeQueue = [];                           // ヘッドが書き込むオフセット列
	var headGlow = 0;                              // ヘッド輝度（心拍書き込みで 1 → 減衰）
	var peakCb = null;                             // R波（ビクンの頂点）を描いた瞬間の通知先
	var state = 'idle';                            // idle（静止）/ sweep（掃引中）/ settle（残光が溶けて戻る）
	var sweepT0 = 0, settleT0 = 0, settleFrom = null;
	var sweepPending = false;                      // 掃引中に来た通信の持ち越し
	var lastTs = 0, rafId = null;
	var reduced = !! ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );

	// 心拍1拍分のオフセット列（前ぶれ小スパイク → QRS → 戻り。1サンプル = STEP px）。
	// 各要素の振幅と間隔を独立に揺らし、毎回少し違う“個性のある拍”にする
	function ecgShape() {
		var pre = 5 + Math.random() * 4.5;    // 前ぶれ 5〜9.5（R検出閾値 -10 を超えない）
		var q   = 1.5 + Math.random() * 2;    // Q の沈み
		var r   = 11.5 + Math.random() * 2.9; // R 11.5〜14.4（レーン上端を超えない）
		var s   = 7.5 + Math.random() * 4.5;  // S 7.5〜12
		var re  = 5 + Math.random() * 4.5;    // 戻りの小山 5〜9.5
		var gap = 1 + Math.floor( Math.random() * 5 ); // 前ぶれ〜QRS の間隔 1〜5 サンプル
		var tail = 0.1 + Math.random() * 0.25; // 余韻の高さ係数
		var shape = [ 0, -pre * 0.35, -pre, -pre * 0.35, 0 ];
		for ( var g = 0; g < gap; g++ ) {
			shape.push( 0 );
		}
		shape.push( q * 0.55, q, -r, s, -re, s * tail, 0.6, 0 );
		return shape;
	}

	function clampY( y ) {
		return Math.round( Math.max( Y_MIN, Math.min( Y_MAX, y ) ) * 10 ) / 10;
	}

	// 静止時のライン形状（真っ平ら。掃引で心拍が乗り、settle で再び完全な水平へ溶ける）
	function makeIdle() {
		var arr = [];
		for ( var i = 0; i < COUNT; i++ ) {
			arr.push( Y_MID );
		}
		return arr;
	}

	function setPath( arr ) {
		var d = 'M';
		for ( var i = 0; i < COUNT; i++ ) {
			d += ( X_MIN + i * STEP ) + ',' + clampY( arr[ i ] ) + ' ';
		}
		trace.setAttribute( 'd', d );
	}

	// グラデーション: アイドル＝均一な控えめ輝度
	function setGradientIdle() {
		if ( ! gradStops ) { return; }
		for ( var s = 0; s < 4; s++ ) {
			gradStops[ s ].setAttribute( 'stop-opacity', IDLE_OPACITY );
		}
	}

	// グラデーション: 掃引中＝ヘッド直後が最も明るく後方ほど減衰する“彗星の尾”
	function setGradientComet() {
		if ( ! gradStops ) { return; }
		var th = ( headX - X_MIN ) / ( X_MAX - X_MIN );
		var edge = ( 1 - 0.72 * th ).toFixed( 2 );
		gradStops[ 0 ].setAttribute( 'stop-opacity', edge );
		gradStops[ 1 ].setAttribute( 'offset', th.toFixed( 4 ) );
		gradStops[ 1 ].setAttribute( 'stop-opacity', 1 );
		gradStops[ 2 ].setAttribute( 'offset', Math.min( 1, th + 0.002 ).toFixed( 4 ) );
		gradStops[ 2 ].setAttribute( 'stop-opacity', 0.22 );
		gradStops[ 3 ].setAttribute( 'stop-opacity', edge );
	}

	// グラデーション: 残光が均一なアイドル輝度へ溶けて戻る（k: 0→1）
	function setGradientSettle( k ) {
		if ( ! gradStops ) { return; }
		// 掃引終了時点（ヘッド右端）の各 stop 輝度 → IDLE_OPACITY へ補間
		var from = [ 0.28, 1, 0.22, 0.28 ];
		for ( var s = 0; s < 4; s++ ) {
			gradStops[ s ].setAttribute( 'stop-opacity', ( from[ s ] + ( IDLE_OPACITY - from[ s ] ) * k ).toFixed( 2 ) );
		}
	}

	// 掃引中の描画: ヘッド前方は BLEND 幅で先端値→前周値へ smoothstep 橋渡し（線は途切れない）
	function renderSweep( ts ) {
		var hi = Math.min( writeIdx, COUNT - 1 );
		var tipY = hi >= 0 ? ys[ hi ] : idleYs[ 0 ];
		var d = 'M';
		for ( var i = 0; i < COUNT; i++ ) {
			var x = X_MIN + i * STEP;
			var y;
			if ( i <= hi ) {
				y = ys[ i ];
			} else {
				var ahead = x - headX;
				if ( ahead < BLEND ) {
					var bt = Math.max( 0, ahead / BLEND );
					bt = bt * bt * ( 3 - 2 * bt ); // smoothstep
					y = tipY * ( 1 - bt ) + ys[ i ] * bt;
				} else {
					y = ys[ i ];
				}
			}
			d += x + ',' + clampY( y ) + ' ';
		}
		trace.setAttribute( 'd', d );
		head.setAttribute( 'cx', Math.round( headX * 10 ) / 10 );
		head.setAttribute( 'cy', clampY( tipY ) );
		// 開始 150ms はフェードイン。心拍の書き込み中は明るく
		var ramp = Math.min( 1, ( ts - sweepT0 ) / 150 );
		head.style.opacity = ( ramp * ( 0.5 + 0.5 * headGlow ) ).toFixed( 2 );
		setGradientComet();
	}

	function beginSweep( ts ) {
		state = 'sweep';
		sweepT0 = ts || performance.now();
		headX = X_MIN;
		writeIdx = -1;
		headGlow = 0;
		// 複合波が毎回 COMPLEX_X から始まるよう、先頭に平坦区間を詰める
		writeQueue = [];
		var lead = Math.floor( ( COMPLEX_X - X_MIN ) / STEP );
		for ( var i = 0; i < lead; i++ ) {
			writeQueue.push( 0 );
		}
		var shape = ecgShape();
		for ( i = 0; i < shape.length; i++ ) {
			writeQueue.push( shape[ i ] );
		}
	}

	function tick( ts ) {
		rafId = requestAnimationFrame( tick );
		if ( ! lastTs ) { lastTs = ts; }
		var dt = Math.min( ts - lastTs, 100 ); // タブ復帰直後の時間ジャンプを抑制
		lastTs = ts;

		if ( state === 'sweep' ) {
			headX += ( dt / 1000 ) * SPEED;
			var hi = Math.floor( ( headX - X_MIN ) / STEP );
			while ( writeIdx < hi && writeIdx < COUNT - 1 ) {
				writeIdx++;
				if ( writeQueue.length ) {
					var off = writeQueue.shift();
					// 心拍以外は完全な直線（うねりを乗せない）
					ys[ writeIdx ] = Y_MID + off;
					if ( 0 !== off ) {
						headGlow = 1;
					}
					if ( off <= -10 && peakCb ) {
						peakCb(); // R波を描いた瞬間（人数更新の同期点）
					}
				} else {
					ys[ writeIdx ] = Y_MID;
				}
			}
			headGlow = Math.max( 0, headGlow - dt / 600 );
			if ( headX >= X_MAX ) {
				// パス完了。持ち越しがあれば連続掃引、なければ残光を平坦へ溶かす
				if ( sweepPending ) {
					sweepPending = false;
					beginSweep( ts );
				} else {
					state = 'settle';
					settleT0 = ts;
					settleFrom = ys.slice();
					head.style.opacity = 0;
				}
			} else {
				renderSweep( ts );
			}
		} else if ( state === 'settle' ) {
			var k = Math.min( 1, ( ts - settleT0 ) / SETTLE_DUR );
			var e = k * k * ( 3 - 2 * k ); // smoothstep
			// 形そのものを平坦ラインへモーフィング（線は一瞬も消えない）
			var disp = [];
			for ( var j = 0; j < COUNT; j++ ) {
				disp.push( settleFrom[ j ] + ( idleYs[ j ] - settleFrom[ j ] ) * e );
			}
			setPath( disp );
			setGradientSettle( e );
			if ( k >= 1 ) {
				ys = idleYs.slice();
				state = 'idle';
				stop(); // アイドル中は描画ループ完全停止（CPU 負荷ゼロ）
			}
		}
	}

	function start() {
		if ( rafId || reduced ) { return; }
		lastTs = 0;
		rafId = requestAnimationFrame( tick );
	}

	function stop() {
		if ( rafId ) {
			cancelAnimationFrame( rafId );
			rafId = null;
		}
	}

	return {
		init: function () {
			var lineOld = document.getElementById( 'heartbeat-line' );
			var lineBgOld = document.getElementById( 'heartbeat-line-bg' );
			container = document.querySelector( '.qa-zero-realtime-heartbeat' );
			if ( ! lineOld || ! container ) { return; }
			var svg = lineOld.ownerSVGElement;
			if ( ! svg ) { return; }
			// 既存 polyline は no-JS 時のフォールバック表示。JS が引き継ぐので空にする
			lineOld.setAttribute( 'points', '' );
			if ( lineBgOld ) { lineBgOld.setAttribute( 'points', '' ); }
			var ns = 'http://www.w3.org/2000/svg';
			trace = document.createElementNS( ns, 'path' );
			trace.setAttribute( 'class', 'qa-zero-realtime-heartbeat__trace' );
			head = document.createElementNS( ns, 'circle' );
			head.setAttribute( 'class', 'qa-zero-realtime-heartbeat__head' );
			head.setAttribute( 'r', '2' );
			head.style.opacity = 0;
			svg.appendChild( trace );
			svg.appendChild( head );
			if ( ! reduced ) {
				// 残光グラデーション: CSS のブランド色を読み取り、stroke に opacity 勾配を適用
				var strokeColor = window.getComputedStyle( trace ).stroke;
				var defs = document.createElementNS( ns, 'defs' );
				var grad = document.createElementNS( ns, 'linearGradient' );
				grad.setAttribute( 'id', 'qahm-hb-grad' );
				grad.setAttribute( 'gradientUnits', 'userSpaceOnUse' );
				grad.setAttribute( 'x1', X_MIN );
				grad.setAttribute( 'y1', 0 );
				grad.setAttribute( 'x2', X_MAX );
				grad.setAttribute( 'y2', 0 );
				gradStops = [];
				var initOffsets = [ 0, 0, 0, 1 ];
				for ( var s = 0; s < 4; s++ ) {
					var stopEl = document.createElementNS( ns, 'stop' );
					stopEl.setAttribute( 'offset', initOffsets[ s ] );
					stopEl.setAttribute( 'stop-color', strokeColor );
					stopEl.setAttribute( 'stop-opacity', IDLE_OPACITY );
					grad.appendChild( stopEl );
					gradStops.push( stopEl );
				}
				defs.appendChild( grad );
				svg.appendChild( defs );
				trace.style.stroke = 'url(#qahm-hb-grad)';
			}
			// 静止時の平坦ライン（ごく緩やかなカーブ・毎回ランダムな位相）を生成して描画
			idleYs = makeIdle();
			ys = idleYs.slice();
			setPath( idleYs );
			setGradientIdle();
			if ( reduced ) { return; }
			// アイドルは静止 = ループは起動しない（通信時のみ動く）
			document.addEventListener( 'visibilitychange', function () {
				if ( document.visibilityState === 'visible' ) {
					if ( state !== 'idle' ) {
						start();
					}
				} else {
					stop();
				}
			} );
		},
		beat: function () {
			if ( ! trace ) { return; }
			if ( reduced ) {
				container.classList.remove( 'qa-zero-realtime-heartbeat--pulse' );
				void container.offsetWidth;
				container.classList.add( 'qa-zero-realtime-heartbeat--pulse' );
				if ( peakCb ) {
					peakCb(); // 演出なし環境では即時通知
				}
				return;
			}
			if ( state === 'sweep' ) {
				sweepPending = true; // 掃引中の通信は1回ぶん持ち越し
				return;
			}
			// idle / settle から新しい掃引を開始（settle 中なら現在の形を上書きしていく）
			beginSweep( performance.now() );
			start();
		},
		// R波（ビクンの頂点）を描いた瞬間のコールバック登録
		onPeak: function ( cb ) {
			peakCb = cb;
		},
	};
} )();

// 数値変化時に呼ばれる（呼び出し元 updateSessionNum は無変更）
qahm.fireHeartbeat = function() {
	qahm.heartbeat.beat();
};


