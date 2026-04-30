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

	// 数値でない場合（初回の "-" → 数値）はそのまま表示
	if ( isNaN( oldNum ) || isNaN( newNum ) ) {
		el.text( newText );
		return;
	}

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

				// カウントアップアニメーション付きで更新
				qahm.animateCount( jQuery('#session_num'), data['session_num'] );
				qahm.animateCount( jQuery('#session_num_1min'), data['session_num_1min'] );

				// 数値に変化があった場合のみパルスライン発火
				if ( changed ) {
					qahm.fireHeartbeat();
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
// 横棒グラフ共通ファクトリ（TOP5）
// ============================================
qahm.createTop5Chart = function( canvasId, color ) {
	var canvas = document.getElementById( canvasId );
	if ( ! canvas ) {
		return null;
	}
	var chart = new Chart( canvas.getContext('2d'), {
		type: 'horizontalBar',
		data: {
			labels: [],
			datasets: [{
				data: [],
				backgroundColor: [],
				borderWidth: 0,
				barPercentage: 0.7
			}]
		},
		options: {
			responsive: true,
			maintainAspectRatio: false,
			scales: {
				xAxes: [{
					display: false,
					ticks: { beginAtZero: true }
				}],
				yAxes: [{
					display: true,
					ticks: {
						fontSize: 11,
						callback: function( value ) {
							if ( typeof value === 'string' && value.length > 18 ) {
								return value.substring( 0, 18 ) + '...';
							}
							return value;
						}
					},
					gridLines: { display: false }
				}]
			},
			legend: { display: false },
			tooltips: {
				enabled: false
			},
			animation: { duration: 500 }
		}
	});
	chart._baseColor = color;
	return chart;
};

qahm.updateTop5Chart = function( chart, realtimeList, titleIndex ) {
	if ( ! chart ) { return; }
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

	chart.data.labels = sorted.map( function( item ) { return item.title; } );
	chart.data.datasets[0].data = sorted.map( function( item ) { return item.count; } );

	// データ数に応じて背景色を生成
	var opacities = [0.8, 0.65, 0.5, 0.4, 0.3];
	var baseColor = chart._baseColor || 'rgb(0, 186, 141)';
	chart.data.datasets[0].backgroundColor = sorted.map( function( item, idx ) {
		var op = opacities[ idx ] || 0.3;
		return baseColor.replace( ')', ', ' + op + ')' ).replace( 'rgb', 'rgba' );
	});

	chart.update();

	// 空状態メッセージの表示/非表示
	var wrapper = chart.canvas.parentNode;
	var emptyMsg = wrapper.querySelector('.qa-zero-realtime-empty');
	if ( sorted.length === 0 ) {
		if ( ! emptyMsg ) {
			emptyMsg = document.createElement('div');
			emptyMsg.className = 'qa-zero-realtime-empty';
			emptyMsg.textContent = 'No data';
			wrapper.appendChild( emptyMsg );
		}
		emptyMsg.style.display = '';
	} else {
		if ( emptyMsg ) { emptyMsg.style.display = 'none'; }
	}
};

// ============================================
// 地域 TOP5
// ============================================
qahm.initRegionsChart = function() {
	regionsChart = qahm.createTop5Chart( 'regions_chart', 'rgb(0, 186, 141)' );
};

// ============================================
// 参照元 TOP5
// ============================================
qahm.initReferrersChart = function() {
	referrersChart = qahm.createTop5Chart( 'referrers_chart', 'rgb(0, 166, 214)' );
};

// ============================================
// デバイス内訳ドーナツ
// ============================================
qahm.initDeviceChart = function() {
	var canvas = document.getElementById('device_chart');
	if ( ! canvas ) { return; }

	deviceChart = new Chart( canvas.getContext('2d'), {
		type: 'doughnut',
		data: {
			labels: ['desktop', 'tablet', 'mobile'],
			datasets: [{
				data: [1, 0, 0],
				backgroundColor: ['#e0e0e0', '#e0e0e0', '#e0e0e0'],
				borderWidth: 2,
				borderColor: '#fff'
			}]
		},
		options: {
			responsive: true,
			maintainAspectRatio: false,
			legend: {
				position: 'bottom',
				labels: { fontSize: 12, padding: 15 }
			},
			tooltips: {
				callbacks: {
					label: function( item, data ) {
						return data.labels[ item.index ] || '';
					}
				}
			},
			animation: { animateRotate: true, duration: 800 },
			cutoutPercentage: 55
		}
	});
};

qahm.updateDeviceChart = function( realtimeList ) {
	if ( ! deviceChart ) { return; }
	var counts = { desktop: 0, tablet: 0, mobile: 0 };
	for ( var i = 0; i < realtimeList.length; i++ ) {
		var dev = realtimeList[i][0];
		if ( counts[ dev ] !== undefined ) {
			counts[ dev ]++;
		}
	}
	var total = counts.desktop + counts.tablet + counts.mobile;
	if ( total === 0 ) {
		// データなし: グレーのプレースホルダーリング
		deviceChart.data.datasets[0].data = [1, 0, 0];
		deviceChart.data.datasets[0].backgroundColor = ['#e0e0e0', '#e0e0e0', '#e0e0e0'];
	} else {
		deviceChart.data.datasets[0].data = [ counts.desktop, counts.tablet, counts.mobile ];
		deviceChart.data.datasets[0].backgroundColor = ['#00ba8d', '#00cd0a', '#f59e0b'];
	}
	deviceChart.update();
};

// ============================================
// 全グラフ一括更新（PHP側24hフィルタ済みデータをそのまま使用）
// ============================================
qahm.updateRealtimeCharts = function( realtimeList ) {
	qahm.updateTop5Chart( regionsChart, realtimeList, 12 );     // country
	qahm.updateTop5Chart( referrersChart, realtimeList, 7 );    // referrer domain
	qahm.updateDeviceChart( realtimeList );
};


/**-------------------------------
 * to clear the chart
 */
 qahm.clearPreChart = function(chartVar) {
	if ( typeof chartVar !== 'undefined' ) {
		chartVar.destroy();
	}
}
qahm.resetCanvas = function(canvasId) {
  let container = document.getElementById(canvasId).parentNode;
	container.innerHTML = '&nbsp;';
	container.innerHTML = '<canvas id="' + canvasId + '"></canvas>';
}

// ============================================
// パルスライン波形ランダム生成
// ============================================
qahm.generateHeartbeatPoints = function() {
	var mid = 15;
	// 波形の中心位置を中央付近でランダム（85〜115）
	var center = 85 + Math.floor( Math.random() * 30 );
	// 振幅にランダム（0.8〜1.2倍）
	var amp = 0.8 + Math.random() * 0.4;
	// 横幅スケール（0.5〜1.0）
	var ws = 0.6 + Math.random() * 0.4;

	// 上下ランダム反転
	var flip = Math.random() > 0.5 ? 1 : -1;

	// 心電図パターン: フラット → P波 → QRS群 → T波 → フラット
	var pts = [
		[ 10, mid ],
		[ center - 20 * ws, mid ],
		[ center - 15 * ws, mid - 9 * amp * flip ],
		[ center - 10 * ws, mid ],
		[ center - 5 * ws, mid ],
		[ center - 2 * ws, mid + 6 * amp * flip ],
		[ center, mid - 36 * amp * flip ],
		[ center + 3 * ws, mid + 14 * amp * flip ],
		[ center + 6 * ws, mid ],
		[ center + 12 * ws, mid ],
		[ center + 18 * ws, mid - 12 * amp * flip ],
		[ center + 24 * ws, mid ],
		[ 190, mid ]
	];

	return pts.map( function( p ) {
		return Math.round( p[0] ) + ',' + Math.max( 2, Math.min( 28, Math.round( p[1] * 10 ) / 10 ) );
	} ).join( ' ' );
};

// 数値変化時にパルスを発火する（演出シーケンス付き）
qahm.heartbeatBusy = false;
qahm.heartbeatPending = false;

qahm.fireHeartbeat = function() {
	var line = document.getElementById( 'heartbeat-line' );
	var lineBg = document.getElementById( 'heartbeat-line-bg' );
	var container = document.querySelector( '.qa-zero-realtime-heartbeat' );
	if ( ! line || ! lineBg || ! container ) { return; }

	// 連続発火を防止（完了後に再発火する）
	if ( qahm.heartbeatBusy ) {
		qahm.heartbeatPending = true;
		return;
	}
	qahm.heartbeatBusy = true;

	// シーケンス完了処理
	function onSequenceEnd() {
		qahm.heartbeatBusy = false;
		if ( qahm.heartbeatPending ) {
			qahm.heartbeatPending = false;
			qahm.fireHeartbeat();
		}
	}

	// opacity フェード用ユーティリティ
	function fadeOpacity( el, from, to, duration, callback ) {
		var start = null;
		function step( timestamp ) {
			if ( ! start ) { start = timestamp; }
			var progress = Math.min( ( timestamp - start ) / duration, 1 );
			el.style.opacity = from + ( to - from ) * progress;
			if ( progress < 1 ) {
				requestAnimationFrame( step );
			} else if ( callback ) {
				callback();
			}
		}
		requestAnimationFrame( step );
	}

	// Step 1: 横棒をゆっくりフェードアウト
	fadeOpacity( lineBg, 0.3, 0, 1500, function() {

		// Step 2: 波形をセットしてスイープ開始（opacity をゆっくり上げつつ）
		var points = qahm.generateHeartbeatPoints();
		line.setAttribute( 'points', points );
		lineBg.setAttribute( 'points', points );

		container.classList.remove( 'qa-zero-realtime-heartbeat--active' );
		void container.offsetWidth;
		container.classList.add( 'qa-zero-realtime-heartbeat--active' );

		fadeOpacity( lineBg, 0, 0.3, 1200, null );

		// Step 3: スイープ完了後、フェードアウト → 横棒に戻す
		setTimeout( function() {
			fadeOpacity( lineBg, 0.3, 0, 800, function() {
				line.setAttribute( 'points', '10,15 190,15' );
				lineBg.setAttribute( 'points', '10,15 190,15' );
				fadeOpacity( lineBg, 0, 0.3, 500, function() {
					onSequenceEnd();
				} );
			} );
		}, 2500 );
	} );
};


