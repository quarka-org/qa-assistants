/*
 * qahm-echarts.js — QA Assistants 共有 ECharts ラッパ（Issue #1280）
 *
 * 全管理画面チャート（realtime / dashboard / goals / acquisition / user）と
 * アシスタント chart step が、このラッパ経由で ECharts を使う。
 * 各画面で echarts.init / setOption を直書きしないこと。
 *
 * 依存: lib/echarts/echarts.custom.min.js（tree-shaken bundle: bar / line / pie）
 *
 * API:
 *   var inst = qahm.EChart.create( elOrId, spec );  // 冪等（同一要素への二重 init 防止）
 *   qahm.EChart.update( inst, spec );               // データ・軸の差し替え
 *   qahm.EChart.destroy( inst );                    // dispose + レジストリ解除
 *
 * spec（正規化形）:
 *   {
 *     kind:   'bar' | 'line' | 'pie' | 'hbar' | 'combo',
 *     labels: [ 'a', 'b', ... ],                  // category 軸（pie ではスライス名）
 *     series: [ {
 *       name:  '系列名',
 *       type:  'bar' | 'line',                    // combo のみ必須（他は kind から決定）
 *       data:  [ 1, 2, ... ],                     // pie は labels と対で値のみ
 *       color: '#69A4E2',                         // 省略時はテーマパレット
 *       yAxisIndex: 0,                            // combo の右軸系列は 1
 *       area:   true,                             // line: グラデーション塗り
 *       dashed: true,                             // line: 破線
 *       smooth: true,                             // line: 曲線補間
 *       symbolSize: 6,                            // line: データ点マーカーの大きさ（省略時はテーマ既定）
 *       showSymbol: false,                        // line: データ点マーカー非表示（点が多い時の密集対策）
 *       stack:  'total',                          // bar 積み上げ
 *       hidden: true,                             // 初期非表示（legend.selected で凡例トグル可能なまま）
 *       colors: [ '#a', '#b' ],                   // bar: データ点ごとの個別色
 *     } ],
 *     yAxes:  [ { show, min, max, interval, name, splitLine } ],  // combo/dual 用（省略時は単一 Y 自動）。splitLine:false で目盛り線非表示
 *     syncYAxes: true,                            // combo: 全系列の最大値から単一スケールを計算し全 yAxis に適用
 *                                                 //（= user 画面の beforeBuildTicks「左右同一スケール」相当。
 *                                                 //   軸ごとに別スケールが必要なら yAxes[].max/interval を明示指定）
 *                                                 // 注意: stack 系列の合計は見ない。stacked + syncYAxes 併用時は max を明示すること
 *     donut:  true,                               // pie をドーナツに
 *     legend: true | false | { ...echarts legend },
 *     tooltip: { ...echarts tooltip 上書き },
 *     maxXTicks: 10,                              // category 軸ラベルの最大表示数（Chart.js maxTicksLimit 相当）
 *     empty:  { text: 'No data' },                // データ全ゼロ・空配列時のオーバーレイ
 *   }
 *
 * 既定の書式（#1294 洗練）:
 *   - tooltip / Y 軸ラベルの数値は 3 桁カンマ区切り（spec.tooltip の上書きは尊重）
 *   - 多系列（series 2 本以上）はホバー系列を強調し他系列を減光（emphasis.focus: 'series'）
 *   - hbar は既定でフル名称 + 値の tooltip（軸ラベルの 18 字省略を補完）
 *   - 入場アニメーションは時間差: 棒 = 左から波のように / 折線 = 棒の後に左→右へ描き進む /
 *     hbar = 上の行から順に伸びる。更新遷移は 300ms で入場より短く（ANIM 定数参照）
 *   - データ取得中は qahm.EChart.loading( el ) で脈動ドットを表示（create / update が自動で消す）
 */

var qahm = qahm || {};

( function () {
	'use strict';

	// ============================================
	// QA Assistants テーマ（独自・現行配色の系統から再構成）
	// ============================================

	// ブランド青ファミリー（qahm.graphColorBase）+ 既存アクセント色から構成。
	// 多系列（acquisition 最大8系列）でも識別できる順序に並べ替え。
	var PALETTE = [
		'#69A4E2', // ブランド青（基調）
		'#ED7E17', // オレンジ
		'#00BA8D', // エメラルド
		'#AF49C5', // パープル
		'#2F5F98', // ミッドブルー
		'#F0C419', // イエロー（旧 rgb(237,239,0) を視認性調整）
		'#31356E', // ネイビー
		'#A0A424', // オリーブ
	];

	var THEME_NAME = 'qahm';
	var themeRegistered = false;

	function ensureTheme() {
		if ( themeRegistered || typeof echarts === 'undefined' ) {
			return;
		}
		echarts.registerTheme( THEME_NAME, {
			color: PALETTE,
			backgroundColor: 'transparent',
			textStyle: {
				fontFamily: 'inherit',
				color: '#555555',
			},
			categoryAxis: {
				axisLine: { lineStyle: { color: '#E0E0E0' } },
				axisTick: { show: false },
				axisLabel: { color: '#767676', fontSize: 11 },
				splitLine: { show: false },
			},
			valueAxis: {
				axisLine: { show: false },
				axisTick: { show: false },
				axisLabel: { color: '#767676', fontSize: 11 },
				splitLine: { lineStyle: { color: '#F0F2F5' } },
			},
			legend: {
				textStyle: { color: '#555555', fontSize: 12 },
				itemGap: 15,
			},
			tooltip: {
				backgroundColor: '#FFFFFF',
				borderColor: '#E3E6EA',
				borderWidth: 1,
				textStyle: { color: '#333333', fontSize: 12 },
				extraCssText: 'box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12); border-radius: 4px;',
				axisPointer: {
					lineStyle: { color: 'rgba(105, 164, 226, 0.4)' },
					crossStyle: { color: 'rgba(105, 164, 226, 0.4)' },
				},
			},
			bar: {
				itemStyle: { borderRadius: [ 3, 3, 0, 0 ] },
			},
			line: {
				lineStyle: { width: 2 },
				symbolSize: 5,
				symbol: 'circle',
			},
		} );
		themeRegistered = true;
	}

	// ============================================
	// 内部ユーティリティ
	// ============================================

	var registry = []; // 生存インスタンス（resize 一元管理用）
	var resizeBound = false;
	var resizeTimer = null;
	var resizeObserver = null;

	// resize() は進行中の入場アニメーションを殺して最終状態にスナップさせる。
	// 入場ウィンドウ内（create からこの時間以内）に resize が来たら、新サイズで入場を再生し直す
	//（初回ロード時のスクロールバー出現・レイアウト変動・アコーディオン展開で入場が消える対策。#1294 R4）
	var ENTRANCE_REPLAY_MS = 4000;

	// 全生存インスタンスを resize。dispose 済み・DOM が切り離されたものは registry から掃除する
	//（destroy() を経ず DOM ごと消されたケースの自衛。detached DOM への resize 連打を防ぐ）
	function resizeAll() {
		for ( var i = registry.length - 1; i >= 0; i-- ) {
			var inst = registry[ i ];
			if ( ! inst || inst.isDisposed() || ! inst.getDom() || ! inst.getDom().isConnected ) {
				registry.splice( i, 1 );
				if ( inst && ! inst.isDisposed() ) {
					inst.dispose();
				}
				continue;
			}
			inst.resize();
			if ( inst.__qahmCreatedAt && inst.__qahmSpec && Date.now() - inst.__qahmCreatedAt < ENTRANCE_REPLAY_MS ) {
				inst.clear();
				inst.setOption( buildOption( inst.__qahmSpec ), true );
			}
		}
	}

	function scheduleResize() {
		// debounce: 連続 resize で N 回再描画しない
		clearTimeout( resizeTimer );
		resizeTimer = setTimeout( resizeAll, 120 );
	}

	function bindResizeOnce() {
		if ( resizeBound ) {
			return;
		}
		// window resize に加え、WP 管理メニュー折りたたみ等「window resize を発火させない幅変化」へ
		// ResizeObserver で追従する（Chart.js v2 は親要素監視で追従できていたため、退行防止）
		window.addEventListener( 'resize', scheduleResize );
		if ( typeof ResizeObserver !== 'undefined' ) {
			resizeObserver = new ResizeObserver( function ( entries ) {
				// observe() 直後はサイズ変化がなくても必ず初回通知が来る（仕様）。
				// これを resize 扱いすると create 直後の入場アニメーションが殺されるため読み捨てる
				var changed = false;
				for ( var i = 0; i < entries.length; i++ ) {
					var t = entries[ i ].target;
					if ( ! t.__qahmRoPrimed ) {
						t.__qahmRoPrimed = true;
						continue;
					}
					changed = true;
				}
				if ( changed ) {
					scheduleResize();
				}
			} );
		}
		resizeBound = true;
	}

	function resolveEl( elOrId ) {
		if ( typeof elOrId === 'string' ) {
			return document.getElementById( elOrId );
		}
		return elOrId;
	}

	// hex 形式のみ対応（#abc / #aabbcc）。それ以外（rgb() 等）は null を返し、呼び出し側でフォールバックする
	function hexToRgba( hex, alpha ) {
		if ( typeof hex !== 'string' || '#' !== hex.charAt( 0 ) ) {
			return null;
		}
		var h = hex.replace( '#', '' );
		if ( 3 === h.length ) {
			h = h.charAt( 0 ) + h.charAt( 0 ) + h.charAt( 1 ) + h.charAt( 1 ) + h.charAt( 2 ) + h.charAt( 2 );
		}
		var n = parseInt( h, 16 );
		return 'rgba(' + ( ( n >> 16 ) & 255 ) + ', ' + ( ( n >> 8 ) & 255 ) + ', ' + ( n & 255 ) + ', ' + alpha + ')';
	}

	function seriesColor( s, idx ) {
		return s.color || PALETTE[ idx % PALETTE.length ];
	}

	// 数値の表示書式（ツールチップ・軸ラベル共通）。3桁カンマ区切り。
	// 欠損値（null / '' / NaN）は ECharts 既定フォーマッタと同じ '-' に倒す
	//（カスタム valueFormatter は既定の '-' フォールバックをバイパスするため、ここで模倣する）。
	// ロケールは en-US 固定: 画面側の qahm.comma（カンマ固定）と同一表示にし、
	// ロケールによって同一画面内で千区切りが混在しないようにする。
	function fmtNum( value ) {
		var n = Number( value );
		if ( null == value || '' === value || isNaN( n ) ) {
			return '-';
		}
		return n.toLocaleString( 'en-US' );
	}

	// カスタム formatter 用 HTML エスケープ（ECharts は関数 formatter の出力をエスケープしない）
	function escHtml( str ) {
		return String( str ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	// 「きりのいい」軸最大値と間隔を計算する（1/2/5 × 10^n 系列）。
	// user 画面の Chart.js beforeBuildTicks（左右軸の目盛り同期）相当を事前計算で再現する。
	function niceScale( dataMax, splitNumber ) {
		if ( ! dataMax || dataMax <= 0 ) {
			return { max: splitNumber, interval: 1 };
		}
		var rawStep = dataMax / splitNumber;
		var mag = Math.pow( 10, Math.floor( Math.log( rawStep ) / Math.LN10 ) );
		var norm = rawStep / mag;
		var step;
		if ( norm <= 1 ) {
			step = 1;
		} else if ( norm <= 2 ) {
			step = 2;
		} else if ( norm <= 5 ) {
			step = 5;
		} else {
			step = 10;
		}
		var interval = step * mag;
		return { max: interval * splitNumber, interval: interval };
	}

	// 全系列のデータ最大値（syncYAxes の共有スケール計算用）
	function maxOfAllSeries( series ) {
		var max = 0;
		for ( var i = 0; i < series.length; i++ ) {
			var data = series[ i ].data || [];
			for ( var j = 0; j < data.length; j++ ) {
				var v = Number( data[ j ] ) || 0;
				if ( v > max ) {
					max = v;
				}
			}
		}
		return max;
	}

	function isAllEmpty( spec ) {
		if ( ! spec.series || 0 === spec.series.length ) {
			return true;
		}
		for ( var i = 0; i < spec.series.length; i++ ) {
			var data = spec.series[ i ].data || [];
			for ( var j = 0; j < data.length; j++ ) {
				// 負値もデータとして扱う（差分・増減系データ対応）
				if ( 0 !== ( Number( data[ j ] ) || 0 ) ) {
					return false;
				}
			}
		}
		return true;
	}

	// ローディングオーバーレイ（#1294 第二弾）。データ取得中の「真っ白な待ち時間」に表情を付ける。
	// 画面側はリクエスト開始時に qahm.EChart.loading( el ) を呼ぶだけでよい
	//（create / update が描画時に自動で消す。明示的に消したい場合は loading( el, false )）
	function toggleLoadingOverlay( el, show ) {
		var overlay = null;
		for ( var i = 0; i < el.children.length; i++ ) {
			if ( el.children[ i ].classList && el.children[ i ].classList.contains( 'qahm-ec-loading' ) ) {
				overlay = el.children[ i ];
				break;
			}
		}
		if ( show ) {
			if ( ! overlay ) {
				overlay = document.createElement( 'div' );
				overlay.className = 'qahm-ec-loading';
				overlay.appendChild( document.createElement( 'span' ) );
				overlay.appendChild( document.createElement( 'span' ) );
				overlay.appendChild( document.createElement( 'span' ) );
				el.appendChild( overlay );
			}
			overlay.style.display = '';
		} else if ( overlay ) {
			overlay.style.display = 'none';
		}
	}

	function toggleEmptyOverlay( el, spec ) {
		var show = spec.empty && isAllEmpty( spec );
		var overlay = null;
		// el 直下のみ検索（ネストした別チャートのオーバーレイを拾わない）
		for ( var i = 0; i < el.children.length; i++ ) {
			if ( el.children[ i ].classList && el.children[ i ].classList.contains( 'qahm-ec-empty' ) ) {
				overlay = el.children[ i ];
				break;
			}
		}
		if ( show ) {
			if ( ! overlay ) {
				overlay = document.createElement( 'div' );
				overlay.className = 'qahm-ec-empty';
				el.appendChild( overlay );
			}
			overlay.textContent = spec.empty.text || 'No data';
			overlay.style.display = '';
		} else if ( overlay ) {
			overlay.style.display = 'none';
		}
	}

	// ============================================
	// spec → ECharts option ビルダー
	// ============================================

	function buildLegend( spec ) {
		var legend;
		if ( false === spec.legend || undefined === spec.legend ) {
			legend = { show: false };
		} else if ( true === spec.legend ) {
			// type:scroll = 多系列でも1行に収まり矢印で送る（折返しでチャートに被るのを防ぐ）
			legend = { show: true, bottom: 0, type: 'scroll' };
		} else {
			legend = Object.assign( {}, spec.legend );
		}
		// series[].hidden → legend.selected（初期非表示。凡例クリックでトグル可能なまま）
		if ( spec.series ) {
			var selected = null;
			for ( var i = 0; i < spec.series.length; i++ ) {
				if ( spec.series[ i ].hidden && spec.series[ i ].name ) {
					selected = selected || {};
					selected[ spec.series[ i ].name ] = false;
				}
			}
			if ( selected ) {
				legend.selected = selected;
			}
		}
		return legend;
	}

	function buildPie( spec ) {
		var data = [];
		var values = ( spec.series[ 0 ] && spec.series[ 0 ].data ) || [];
		for ( var i = 0; i < spec.labels.length; i++ ) {
			var item = { name: spec.labels[ i ], value: Number( values[ i ] ) || 0 };
			var colors = spec.series[ 0 ] && spec.series[ 0 ].colors;
			if ( colors && colors[ i ] ) {
				item.itemStyle = { color: colors[ i ] };
			}
			data.push( item );
		}
		return {
			legend: buildLegend( spec ),
			tooltip: Object.assign( { trigger: 'item', valueFormatter: fmtNum }, spec.tooltip || {} ),
			series: [ {
				id: 'qahm-s0',
				type: 'pie',
				radius: spec.donut ? [ '50%', '78%' ] : '70%',
				center: [ '50%', '46%' ],
				label: { show: false },
				labelLine: { show: false },
				itemStyle: { borderColor: '#FFFFFF', borderWidth: 2 },
				animationType: 'expansion',
				animationDuration: 1200,
				animationEasing: 'cubicOut',
				data: data,
			} ],
		};
	}

	function buildHBar( spec ) {
		// ECharts の category 軸は下→上に積むため、上から大→小に見せるには逆順で渡す。
		// 呼び出し側は「大→小」の自然な並びで labels/series を渡せばよい（ここで reverse する）。
		var labels = spec.labels.slice().reverse();
		var s = spec.series[ 0 ] || { data: [] };
		var data = s.data.slice().reverse();
		// 個別色指定（realtime TOP5 の濃淡など）。colors も大→小の並びで受けて reverse
		var colors = s.colors ? s.colors.slice().reverse() : null;
		var items = [];
		for ( var i = 0; i < data.length; i++ ) {
			var item = { value: data[ i ] };
			if ( colors && colors[ i ] ) {
				item.itemStyle = { color: colors[ i ] };
			}
			items.push( item );
		}
		return {
			grid: { left: 0, right: 20, top: 8, bottom: 8, containLabel: true },
			xAxis: { type: 'value', show: false, min: 0 },
			yAxis: {
				type: 'category',
				data: labels,
				axisLine: { show: false },
				axisLabel: {
					fontSize: 11,
					formatter: function ( value ) {
						if ( typeof value === 'string' && value.length > 18 ) {
							return value.substring( 0, 18 ) + '...';
						}
						return value;
					},
				},
			},
			legend: { show: false },
			// 軸ラベルは 18 字で省略されるため、既定でフル名称 + 値をホバー表示する
			//（realtime TOP5 は件数非表示の自前 tooltip を spec で上書き = 影響なし）。
			// hbar は単一系列前提のため系列名は出さない（名称: 値 のみの意図的な簡素形）
			tooltip: Object.assign( {
				show: true,
				trigger: 'item',
				confine: true,
				formatter: function ( p ) {
					return escHtml( p.name ) + ': <b>' + escHtml( fmtNum( p.value ) ) + '</b>';
				},
			}, spec.tooltip || {} ),
			series: [ {
				id: 'qahm-s0',
				type: 'bar',
				data: items,
				barWidth: '70%',
				itemStyle: { color: seriesColor( s, 0 ), borderRadius: [ 0, 3, 3, 0 ] },
				animationDuration: ANIM.hbarDuration,
				animationEasing: 'cubicOut',
				// 上の行（= 値が大きい行）から順に伸びる。データは reverse 済みなので逆順ディレイ
				animationDelay: function ( idx ) {
					return ( items.length - 1 - idx ) * ANIM.hbarRowDelay;
				},
			} ],
		};
	}

	// 入場アニメーションの時間差（#1294 第二弾）:
	// 棒 = 左から順に波のように立ち上がる（点ごとのディレイ。多点でも体感が間延びしないよう上限あり）
	// 折線 = 棒のあとに左→右へ描き進む（combo では棒の立ち上がりを待ってから描き始める）
	var ANIM = {
		barPointDelay: 34,    // 棒: 1 点あたりのディレイ ms
		barDelayCap: 800,     // 棒: ディレイ上限（多点の dashboard でも 0.8s 内に全点が動き出す）
		barDuration: 1200,
		lineDuration: 2100,   // 折線: 左→右の描画時間
		lineAfterBar: 750,    // combo: 折線が描き始めるまでの待ち
		lineCascade: 300,     // 折線のみ複数本（acquisition）: 1 本ごとの開始ずれ
		hbarRowDelay: 135,    // hbar: 1 行あたりのディレイ
		hbarDuration: 1050,
	};

	function buildCartesian( spec ) {
		var series = [];
		var i;
		var hasBar = false;
		for ( i = 0; i < spec.series.length; i++ ) {
			if ( 'bar' === ( spec.series[ i ].type || ( 'line' === spec.kind ? 'line' : 'bar' ) ) ) {
				hasBar = true;
			}
		}
		var lineSeq = 0; // 折線の登場順（cascade 用。series 配列内の折線だけを数える）
		for ( i = 0; i < spec.series.length; i++ ) {
			var s = spec.series[ i ];
			var type = s.type || ( 'line' === spec.kind ? 'line' : 'bar' );
			var color = seriesColor( s, i );
			var one = {
				// 安定 id: replaceMerge 更新時に系列をマッチさせ、遷移アニメーションを維持する
				id: 'qahm-s' + i,
				name: s.name,
				type: type,
				data: s.data,
				yAxisIndex: s.yAxisIndex || 0,
				itemStyle: { color: color },
			};
			if ( spec.series.length > 1 ) {
				// 多系列: ホバー（凡例ホバー含む）した系列を強調し、他系列を減光する
				one.emphasis = { focus: 'series' };
			}
			if ( s.colors ) {
				// データ点ごとの個別色（goals ミニチャートの「現在 vs 目標」など）
				one.data = s.data.map( function ( v, di ) {
					return s.colors[ di ] ? { value: v, itemStyle: { color: s.colors[ di ] } } : v;
				} );
			}
			if ( 'line' === type ) {
				// 左→右へ描き進む入場。combo では棒の立ち上がり後に開始、折線のみ複数本なら 1 本ずつずらす
				one.animationDuration = ANIM.lineDuration;
				one.animationEasing = 'cubicOut';
				one.animationDelay = hasBar ? ANIM.lineAfterBar : lineSeq * ANIM.lineCascade;
				lineSeq++;
				one.lineStyle = { width: 2, color: color };
				if ( s.smooth ) {
					one.smooth = true;
				}
				if ( undefined !== s.symbolSize ) {
					one.symbolSize = s.symbolSize;
				}
				if ( false === s.showSymbol ) {
					// 点が多い時の密集対策。axis トリガーの tooltip は showSymbol:false でも機能する
					one.showSymbol = false;
				}
				if ( s.dashed ) {
					one.lineStyle.type = 'dashed';
				}
				if ( s.area ) {
					var topColor = hexToRgba( color, 0.22 );
					if ( topColor ) {
						one.areaStyle = {
							color: new echarts.graphic.LinearGradient( 0, 0, 0, 1, [
								{ offset: 0, color: topColor },
								{ offset: 1, color: hexToRgba( color, 0.02 ) },
							] ),
						};
					} else {
						// hex 以外（rgb() 等）はグラデーションせず半透明の単色塗り
						one.areaStyle = { color: color, opacity: 0.15 };
					}
				}
			} else {
				// 左から順に波のように立ち上がる入場
				one.animationDuration = ANIM.barDuration;
				one.animationEasing = 'cubicOut';
				one.animationDelay = function ( idx ) {
					return Math.min( idx * ANIM.barPointDelay, ANIM.barDelayCap );
				};
				one.itemStyle.borderRadius = [ 3, 3, 0, 0 ];
				if ( s.stack ) {
					one.stack = s.stack;
				}
				if ( s.barMaxWidth ) {
					one.barMaxWidth = s.barMaxWidth;
				}
			}
			series.push( one );
		}

		// yAxis 構築（単一 or 複数）。
		// syncYAxes: 全系列の最大値から単一の niceScale を計算し、全 yAxis に同じ max/interval を適用する
		//（= user 画面の Chart.js beforeBuildTicks「左右同一スケール」の再現）。
		// 注意: stack 系列の合計は見ない。stacked + syncYAxes 併用時は yAxes[].max を明示すること。
		var yAxes;
		var SPLIT = 5;
		var shared = null;
		if ( spec.syncYAxes ) {
			shared = niceScale( maxOfAllSeries( spec.series ), SPLIT );
		}
		if ( spec.yAxes && spec.yAxes.length > 0 ) {
			yAxes = [];
			for ( i = 0; i < spec.yAxes.length; i++ ) {
				var def = spec.yAxes[ i ] || {};
				var axis = {
					type: 'value',
					show: false !== def.show,
					min: undefined !== def.min ? def.min : 0,
					name: def.name,
					axisLabel: { formatter: fmtNum },
				};
				if ( false === def.splitLine ) {
					axis.splitLine = { show: false };
				}
				if ( shared ) {
					axis.max = undefined !== def.max ? def.max : shared.max;
					axis.interval = undefined !== def.interval ? def.interval : shared.interval;
				} else {
					if ( undefined !== def.max ) {
						axis.max = def.max;
					}
					if ( undefined !== def.interval ) {
						axis.interval = def.interval;
					}
				}
				yAxes.push( axis );
			}
		} else {
			yAxes = [ { type: 'value', min: 0, axisLabel: { formatter: fmtNum } } ];
		}

		var hasLegend = false !== spec.legend && undefined !== spec.legend;
		return {
			// データ更新時の遷移は入場より短く・きびきびと（realtime の 60 秒更新等）
			animationDurationUpdate: 300,
			animationEasingUpdate: 'cubicOut',
			grid: {
				left: 8,
				right: spec.yAxes && spec.yAxes.length > 1 ? 8 : 16,
				top: 16,
				bottom: hasLegend ? 32 : 8,
				containLabel: true,
			},
			xAxis: {
				type: 'category',
				data: spec.labels,
				boundaryGap: 'line' !== spec.kind,
				axisLabel: {
					// maxXTicks: ラベル最大表示数（Chart.js maxTicksLimit 相当）。省略時は自動間引き
					interval: spec.maxXTicks && spec.labels.length > spec.maxXTicks
						? Math.ceil( spec.labels.length / spec.maxXTicks ) - 1
						: 'auto',
					hideOverlap: true,
					rotate: 0,
				},
			},
			yAxis: yAxes,
			legend: buildLegend( spec ),
			tooltip: Object.assign( {
				trigger: 'axis',
				axisPointer: { type: 'line' === spec.kind ? 'line' : 'shadow' },
				valueFormatter: fmtNum,
			}, spec.tooltip || {} ),
			series: series,
		};
	}

	function buildOption( spec ) {
		if ( 'pie' === spec.kind ) {
			return buildPie( spec );
		}
		if ( 'hbar' === spec.kind ) {
			return buildHBar( spec );
		}
		// bar / line / combo は直交座標系として共通処理
		return buildCartesian( spec );
	}

	// ============================================
	// 公開 API
	// ============================================

	qahm.EChart = {

		THEME: THEME_NAME,
		palette: PALETTE,

		/**
		 * チャート生成（冪等）。既に同要素に init 済みならそのインスタンスを再利用して更新する。
		 * @param {HTMLElement|string} elOrId - 描画先 div（canvas 不可。幅・高さ必須）
		 * @param {Object} spec - 正規化 spec（ファイル冒頭コメント参照）
		 * @return {Object|null} ECharts インスタンス
		 */
		create: function ( elOrId, spec ) {
			var el = resolveEl( elOrId );
			if ( ! el || typeof echarts === 'undefined' ) {
				return null;
			}
			ensureTheme();
			bindResizeOnce();

			var inst = echarts.getInstanceByDom( el );
			if ( ! inst ) {
				inst = echarts.init( el, THEME_NAME );
				registry.push( inst );
				if ( resizeObserver ) {
					// WP 管理メニュー折りたたみ等、window resize を伴わない幅変化に追従
					resizeObserver.observe( el );
				}
			} else {
				// create = 「新しいデータセットの提示」の契約: 既存インスタンスでも入場演出を再生する。
				// clear しないと安定 id の系列が更新遷移（300ms）扱いになり、期間変更・再 Plot で
				// ゆったり入場が一度も見えない（#1294 R3）。差分遷移で済ませたい場合は update() を使うこと
				inst.clear();
			}
			inst.setOption( buildOption( spec ), true ); // notMerge 全置換 + 入場アニメーション
			// 入場ウィンドウ内の resize で入場を再生し直すための記録（resizeAll 参照）
			inst.__qahmSpec = spec;
			inst.__qahmCreatedAt = Date.now();
			toggleLoadingOverlay( el, false );
			toggleEmptyOverlay( el, spec );
			return inst;
		},

		/**
		 * データ更新。spec 全体を渡す。
		 * replaceMerge で系列の増減（残骸）を防ぎつつ、既存系列はマージして
		 * 前値→新値の遷移アニメーションを維持する（notMerge だと毎回入場アニメが再生される）。
		 */
		update: function ( inst, spec ) {
			if ( ! inst || inst.isDisposed() ) {
				return;
			}
			inst.setOption( buildOption( spec ), { replaceMerge: [ 'series' ] } );
			// update 後は入場リプレイの対象外にし、リプレイ用 spec も最新化する
			//（古い spec（例: realtime の初期空データ）で巻き戻さないため）
			inst.__qahmSpec = spec;
			inst.__qahmCreatedAt = 0;
			toggleLoadingOverlay( inst.getDom(), false );
			toggleEmptyOverlay( inst.getDom(), spec );
		},

		/**
		 * 破棄（dispose + レジストリ解除）。
		 */
		destroy: function ( inst ) {
			if ( ! inst ) {
				return;
			}
			var idx = registry.indexOf( inst );
			if ( -1 !== idx ) {
				registry.splice( idx, 1 );
			}
			if ( ! inst.isDisposed() ) {
				if ( resizeObserver && inst.getDom() ) {
					resizeObserver.unobserve( inst.getDom() );
					// 再 observe 時に初回通知の読み捨てが再び効くようにリセット
					inst.getDom().__qahmRoPrimed = false;
				}
				inst.dispose();
			}
		},

		/**
		 * 要素（または id）に紐づくインスタンスを破棄。画面側で echarts を直接参照させないためのヘルパ。
		 */
		destroyByEl: function ( elOrId ) {
			var el = resolveEl( elOrId );
			if ( ! el || typeof echarts === 'undefined' ) {
				return;
			}
			this.destroy( echarts.getInstanceByDom( el ) );
		},

		/**
		 * ローディング表示の切替。データ取得開始時に loading( el ) を呼ぶ。
		 * create / update が成功描画時に自動で消すため、明示 hide はエラー経路でのみ必要。
		 * @param {HTMLElement|string} elOrId - チャートコンテナ（インスタンス生成前でも可）
		 * @param {boolean} [show=true]
		 */
		loading: function ( elOrId, show ) {
			var el = resolveEl( elOrId );
			if ( ! el ) {
				return;
			}
			toggleLoadingOverlay( el, false !== show );
		},

		/**
		 * 全生存インスタンスの明示 resize（タブ切替・表示トグル後など、画面側から任意に叩ける）。
		 */
		resizeAll: resizeAll,

		/**
		 * 軸スケール計算（公開ユーティリティ）。combo 以外で個別に使いたい画面向け。
		 */
		niceScale: niceScale,
	};

} )();
