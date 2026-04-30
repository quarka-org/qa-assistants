var qahm = qahm || {};

if ( typeof qahm.tracking_id === 'undefined' ) {
	let url         = new URL(window.location.href);
	let params      = url.searchParams;
	qahm.tracking_id = params.get('tracking_id');
}
qahm.sDayTable    = null;
qahm.heatmapTable = null;

qahm.dsPvData        = null;
qahm.dsSessionData   = null;
qahm.dsTodayUnixTime = null;

qahm.colorAlfaChange = function ( rgba, alfa ) {
    let rgbaary = rgba.split(',');
    let orgalfa = rgbaary[3];
    return rgba.replace( orgalfa, alfa.toString() + ')' );
};


window.addEventListener('DOMContentLoaded', function() {
	qahm.initDateSetting();

    //goal table
	sourceMediumHeader = [
		{ key: 'referrer', label: qahml10n['table_referrer'], width: 20, formatter: function(value, row) {
			if ( value !== 'direct' && value !== qahml10n['table_total'] ) {
				ret = `<a href="//${value}" target="_blank" rel="noopener">${value}</a>`;
			} else {
				ret = value;
			}
			return ret;
    	} },
		{ key: 'media', label: qahml10n['table_media'], width: 20, },
		{ key: 'user', label: qahml10n['table_user'], width: 10, type: 'integer' },
		{ key: 'new_user', label: qahml10n['table_new_user'], width: 10, type: 'integer' },
		{ key: 'session', label: qahml10n['table_session'], width: 10, type: 'integer' },
		{ key: 'bounce_rate', label: qahml10n['table_bounce_rate'], width: 10, type: 'percentage' },
		{ key: 'page_session', label: qahml10n['table_page_session'], width: 10, type: 'float' },
		{ key: 'avg_session_time', label: qahml10n['table_avg_session_time'], width: 10, type: 'duration' },
	];
	sourceMediumOptions = {
		perPage: 100,
		pagination: true,
		exportable: true,
		sortable: true,
        filtering: true,
		maxHeight: 600,
		stickyHeader: true,
		initialSort: {
			column: 'user',
			direction: 'desc'
		},
	};
	sourceMediumTable = qaTable.createTable('#tb_goalsm', sourceMediumHeader, sourceMediumOptions);

	//create lp table
	landingpageHeader = [
		{ key: 'page_id', hidden: true },
		{ key: 'title', label: qahml10n['table_title'], width: 23 },
		{ key: 'url', label: qahml10n['table_url'], width: 23, type: 'link' },
		{ key: 'session', label: qahml10n['table_session'], width: 9, type: 'integer' },
		{ key: 'new_session_rate', label: qahml10n['table_new_session_rate'], width: 9, type: 'percentage' },
		{ key: 'new_user', label: qahml10n['table_new_user'], width: 9, type: 'integer' },
		{ key: 'bounce_rate', label: qahml10n['table_bounce_rate'], width: 9, type: 'percentage' },
		{ key: 'page_session', label: qahml10n['table_page_session'], width: 9, type: 'float' },
		{ key: 'avg_session_time', label: qahml10n['table_avg_session_time'], width: 9, type: 'duration' },
	];
	landingpageOptions = {
		perPage: 100,
		pagination: true,
		exportable: true,
		sortable: true,
        filtering: true,
		maxHeight: 600,
		stickyHeader: true,
		initialSort: {
			column: 'session',
			direction: 'desc'
		}
	};
	landingpageTable = qaTable.createTable('#tb_goallp', landingpageHeader, landingpageOptions);

	// create heatmap table
	heatmapHeader = [
		{ key: 'page_id', hidden: true },
		{ key: 'url', hidden: true },
		{ key: 'title', label: qahml10n['table_title'], width: 80, formatter: function(value, row) {
			return `<a href="${row.url}" target="_blank" rel="noopener">${value}</a>`;
    	} },
		{ key: 'session', label: qahml10n['table_session'], width: 10, type: 'integer' },
		{ key: 'heatmap', label: qahml10n['table_heatmap'], width: 10, sortable: false, exportable: false, filtering: false, formatter: function(value, row) {
			return `<div class="qa-table-heatmap-container">
					<span class="dashicons dashicons-desktop" data-device_name="dsk" data-page_id="${row.page_id}" data-is_landing_page="1" data-is_goal="1"></span>
					<span class="dashicons dashicons-tablet" data-device_name="tab" data-page_id="${row.page_id}" data-is_landing_page="1" data-is_goal="1"></span>
					<span class="dashicons dashicons-smartphone" data-device_name="smp" data-page_id="${row.page_id}" data-is_landing_page="1" data-is_goal="1"></span>
				</div>`;
    	} }
	];
	heatmapOptions = {
		perPage: 100,
		pagination: true,
		exportable: true,
		sortable: true,
        filtering: true,
		maxHeight: 600,
		stickyHeader: true,
		initialSort: {
			column: 'session',
			direction: 'desc'
		}
	};
	heatmapTable = qaTable.createTable('#heatmap-table', heatmapHeader, heatmapOptions);

	// create session recoding table
	sesRecHeader = [
		{ key: 'tanmatsu', label: qahml10n['table_tanmatsu'], width: 7 },
		{ key: 'ridatsujikoku', label: qahml10n['table_ridatsujikoku'], width: 15, textAlign: 'center',
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
		{ key: 'reader_id', label: qahml10n['table_id'], width: 7, type: 'integer' },
		{ key: 'landing_page_url', hidden: true },
		{ key: 'landing_page', label: qahml10n['table_1page_me'], width: 18,
			formatter: function(value, row) {
				return `<a href="${row.landing_page_url}" target="_blank" rel="noopener">${value}</a>`;
			}
		},
		{ key: 'ridatsu_page_url', hidden: true },
		{ key: 'ridatsu_page', label: qahml10n['table_ridatsu_page'], width: 18,
			formatter: function(value, row) {
				return `<a href="${row.ridatsu_page_url}" target="_blank" rel="noopener">${value}</a>`;
			}
		},
		{ key: 'referrer', label: qahml10n['table_referrer'], width: 11, formatter: function(value, row) {
			if ( value !== 'direct' && value !== qahml10n['table_total'] ) {
				ret = `<a href="//${value}" target="_blank" rel="noopener">${value}</a>`;
			} else {
				ret = value;
			}
			return ret;
    	} },
		// #964: メディア列（utm_medium — 参照元の右）
		{ key: 'media', label: qahml10n['table_media'] || 'Medium', width: 8 },
		{ key: 'pv', label: qahml10n['table_pv'], width: 7, type: 'integer' },
		{ key: 'site_taizaijikan', label: qahml10n['table_site_taizaijikan'], width: 10, type: 'duration' },
		{ key: 'access_time', hidden: true },
		{ key: 'saisei', label: qahml10n['table_saisei'], width: 7, sortable: false, exportable: false, filtering: false, formatter: function(value, row) {
			return `<div class="qa-table-replay-container">
					<span class="icon-replay" data-reader_id="${row.reader_id}" data-replay_id="1" data-access_time="${row.access_time}"><span class="dashicons dashicons-format-video"></span></span>
				</div>`;
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
	sesRecTable = qaTable.createTable('#sday_table', sesRecHeader, sesRecOptions);

    let gsession_selector = document.getElementsByName(`js_gsession_selector`);
    for ( let gid = 0; gid < gsession_selector.length; gid++ ) {
        gsession_selector[gid].addEventListener('click', qahm.changeSelectorGoal);
    }


	// イベントの登録
	qahm.replayClickEvent();

});

qahm.changeSelectorGoal= function(e) {
    let checkedId = e.target.id;

    let gsession_selector = document.getElementsByName(`js_gsession_selector`);

    if ( checkedId === 'js_gsession_selectuser' ) {
        for ( let gid = 0; gid < gsession_selector.length; gid++ ) {
            let divbox = gsession_selector[gid].closest('.bl_goalBox');
            divbox.classList.remove('bl_goalBoxChecked');
        }
        let divbox = e.target.closest('.bl_goalBox');
        divbox.classList.add('bl_goalBoxChecked');
        qahm.drawSessionsView([]);
    } else {
        let idsplit   = checkedId.split('_');
        let nowgid    = Number( idsplit[3] );
        for ( let sid = 0; sid < gsession_selector.length; sid++ ) {
            let divbox = gsession_selector[sid].closest('.bl_goalBox');
            if ( sid === nowgid ) {
                divbox.classList.add('bl_goalBoxChecked');
            } else {
                divbox.classList.remove('bl_goalBoxChecked');
            }
        }
        qahm.drawSessionsView(qahm.goalsSessionData[nowgid]);
    }
};


/** -------------------------------------
 * データの取得と描画・表示
 */
jQuery(
	function() {
		qahm.nowAjaxStep = 0;
		qahm.renderGoalsData(reportDateBetween, dateRangeYmdAry);
		qahm.setDateRangePicker();

    }
);
// カレンダー期間変更時の処理
jQuery(document).on('qahm:dateRangeChanged', function( RangeStart, RangeEnd ) {
	qahm.nowAjaxStep = 0;
	qahm.renderGoalsData(reportDateBetween, dateRangeYmdAry);
});

qahm.renderGoalsData = function(dateBetweenStr, dateYmdAry) {
	// reportRangeStart, reportRangeEndを使っている（が、引数には入れていない）

	switch (qahm.nowAjaxStep) {
        case 0:
			jQuery('#datepicker-base-textbox').prop('disabled', true);
			qahm.disabledGoalRadioButton();
			sourceMediumTable.showLoading();
			landingpageTable.showLoading();
			heatmapTable.showLoading();
			sesRecTable.showLoading();

            qahm.nowAjaxStep = 'getSummaryDaysAccess';
            qahm.renderGoalsData(dateBetweenStr, dateYmdAry);
            break;

		case 'getSummaryDaysAccess':
			jQuery.ajax(
				{
					type: 'POST',
					url: qahm.ajax_url,
					dataType : 'json',
					data: {
						'action' : 'qahm_ajax_select_data',
						'table' : 'summary_days_access',
						'select': '*',
						'date_or_id': dateBetweenStr,
						'count' : false,
						'nonce':qahm.nonce_api,
						'tracking_id' : qahm.tracking_id
					}
				}
            ).done(
                function( data ){
                    let ary = data || [];

					// graph用にsessions配列を作成
					let graphDataAry = {
						//numPvs:[],
						numSessions:[],
						//numUsers:[],
					};				
					let indexMarker = 0;				
					for ( let ddd = 0; ddd < dateYmdAry.length; ddd++ ) {
						let dataExists = false;
						for ( let iii = indexMarker; iii < ary.length; iii++ ) {
							if( ary[iii]['date'] == dateYmdAry[ddd] ) {
								//graphDataAry.numPvs.push( Number(ary[iii]['pv_count']) );
								graphDataAry.numSessions.push( Number(ary[iii]['session_count']) );
								//graphDataAry.numUsers.push( Number(ary[iii]['user_count']) );
								indexMarker = iii;
								dataExists = true;
								break;
							}
						}
						if ( ! dataExists ) {
							//graphDataAry.numPvs.push( 0 );
							graphDataAry.numSessions.push( 0 );
							//graphDataAry.numUsers.push( 0 );
						}
						
					}
					// セッション数（グラフ用配列、合計数）を共通変数に保存
					qahm.graphDataArySessions = graphDataAry.numSessions;
					let totalOfArray = (accumulator, currentValue) => accumulator + currentValue;
					if ( dateYmdAry.length > 1 ) {
						qahm.totalSessions = graphDataAry.numSessions.reduce(totalOfArray);
					} else {
						qahm.totalSessions = graphDataAry.numSessions[0];
					}
					
                }
            ).fail(
                function( jqXHR, textStatus, errorThrown ){
                    qahm.log_ajax_error( jqXHR, textStatus, errorThrown );
                }
            ).always(
                function(){
					qahm.nowAjaxStep = 'getGoals';
					qahm.renderGoalsData(dateBetweenStr, dateYmdAry);
                }
            );
			break;


        case 'getGoals':
            if ( qahm.goalsJson ) {
                qahm.goalsArray = JSON.parse( qahm.goalsJson );
            }
		    //new /repeat device table
            qahm.goalsSessionData = new Array();
            jQuery.ajax(
                {
                    type: 'POST',
                    url: qahm.ajax_url,
                    dataType : 'json',
                    data: {
                        'action' : 'qahm_ajax_get_goals_sessions',
                        'date' : dateBetweenStr,
                        'nonce':qahm.nonce_api,
                        'tracking_id':qahm.tracking_id
                    }
                }
            ).done(
                function( data ){
					if (data !== null ) {
						let allSessionAry = new Array();

						// 全てのゴール配列を作る。各ゴール配列をマージするが、その際に同一セッションは除外
						for (let gid = 1; gid <= Object.keys(data).length; gid++) {
							if ( data[gid].length === 0 ) {
								continue;
							}
							if ( allSessionAry.length === 0 ) {
								allSessionAry = data[gid];
								continue;
							}
							allSessionAry = allSessionAry.concat(data[gid]);
						}

						// 重複するセッションを除外
						function removeDuplicates(arr) {
							const seen = new Map();

							return arr.filter(subArray => {
							  const key = subArray.map(obj => JSON.stringify(obj)).join('|');
							  if (seen.has(key)) {
								return false;
							  }
							  seen.set(key, true);
							  return true;
							});
						}
						allSessionAry = removeDuplicates(allSessionAry);

						for (let gid = 0; gid <= Object.keys(data).length; gid++) {
							if (gid === 0) {
								qahm.goalsSessionData[0] = allSessionAry;
							} else {
								qahm.goalsSessionData[gid] = data[gid];
							}
						}
					} else {
						if (qahm.goalsJson) {
							for (let gid = 0; gid <= Object.keys(qahm.goalsArray).length; gid++) {
								qahm.goalsSessionData[gid] = new Array();
							}
						}
					}
                }
            ).fail(
                function( jqXHR, textStatus, errorThrown ){
                    qahm.log_ajax_error( jqXHR, textStatus, errorThrown );
                    if ( qahm.goalsJson ) {
                        for ( let gid = 0; gid <= Object.keys(qahm.goalsArray).length; gid++ ) {
                            qahm.goalsSessionData[gid] = new Array();
                        }
                    }
                }
            ).always(
                function(){
                    qahm.nowAjaxStep = 'makeGoalsArray';
                    qahm.renderGoalsData(dateBetweenStr, dateYmdAry);
                }
            );
            break;

        case 'makeGoalsArray':
            if ( qahm.goalsJson ) {
                let pidary   = new Array();
                let allgoals = 0;
                for ( let gid = 1; gid <= Object.keys(qahm.goalsArray).length; gid++ ) {
                    pidary = pidary.concat(qahm.goalsArray[gid]['pageid_ary']);
                    allgoals += Number(qahm.goalsArray[gid]['gnum_scale']);
                }
                qahm.goalsArray[0] = {'pageid_ary': pidary, 'gtitle':qahml10n['cnv_all_goals'], 'gnum_scale':allgoals };
            }
            qahm.nowAjaxStep = 'makeCVGraph';
            qahm.renderGoalsData(dateBetweenStr, dateYmdAry);
            break;


        case 'makeCVGraph':

            if ( qahm.goalsJson ) {
                //make conversion array
                qahm.goalsSummary = new Array();
                let sttdayobj  = new Date( reportRangeStart );
                let nextdayobj = new Date( sttdayobj );
                nextdayobj.setDate( nextdayobj.getDate() + 1 );

                let termdate  = (reportRangeEnd - reportRangeStart) / 86400000;
                if ( qahm.goalsSessionData !== undefined ) {
                    for (let dno = 0; dno < termdate; dno++) {
                        for (let gid = 0; gid < qahm.goalsSessionData.length; gid++) {
                            let sno = 0;
                            if (qahm.goalsSummary[gid] === undefined) {
                                qahm.goalsSummary[gid] = {'cvPerDay': [], 'nCount': 0, 'nValue': 0, 'nCvrate': 0.0};
                            }
                            qahm.goalsSummary[gid].cvPerDay[dno] = 0;

                            while (sno < qahm.goalsSessionData[gid].length) {
                                // let accessdobj = new Date(qahm.goalsSessionData[gid][sno][0]['access_time']);
                                let unixtime = new Date(qahm.goalsSessionData[gid][sno][0]['access_time']);
                                let accessdobj = new Date(unixtime * 1000);
                                if (sttdayobj <= accessdobj && accessdobj < nextdayobj) {
                                    qahm.goalsSummary[gid].cvPerDay[dno]++;
                                    qahm.goalsSummary[gid].nCount++;
                                }
                                sno++;
                            }
                        }
                        sttdayobj = new Date(nextdayobj);
                        nextdayobj.setDate(nextdayobj.getDate() + 1);
                    }
                }
                let allvalue   = 0;
                for (let gid = 0; gid < qahm.goalsSessionData.length; gid++) {
                    if ( 1 <= gid ) {
                        qahm.goalsSummary[gid].nValue = Number(qahm.goalsSummary[gid].nCount * qahm.goalsArray[gid]['gnum_value']);
                        allvalue += Number(qahm.goalsSummary[gid].nValue);
                    }

					if (Number(qahm.totalSessions) === 0) {
						qahm.goalsSummary[gid].nCvrate = 0;
					} else {
						qahm.goalsSummary[gid].nCvrate = Number(qahm.goalsSummary[gid].nCount) / Number(qahm.totalSessions);
					}
                    qahm.goalsSummary[gid].nCvrate = qahm.roundToX(qahm.goalsSummary[gid].nCvrate * 100, 2);
                }
                qahm.goalsSummary[0].nValue = allvalue;

                //make datasets
                let cvDatesets = new Array();
                for (let gid = 0; gid < qahm.goalsSessionData.length; gid++) {
                    let rgba = qahm.graphColorGoals[gid];
                    let hide = false;
                    if (gid === 0 ) { hide = true;}
                    cvDatesets[gid] = {
                                type: 'bar',
                                hidden: hide,
                                label: decodeURI(qahm.goalsArray[gid].gtitle),
                                data: qahm.goalsSummary[gid].cvPerDay,
                                backgroundColor: rgba,
                                borderColor: rgba,
                                borderJoinStyle: 'bevel',
                                borderWidth: 2,
                                borderDash: [10, 1, 2, 1],
                                pointStyle: 'rectRot',
                                lineTension: 0,
                                fill: false,
                                yAxisID: 'goals'
                    };
                }
                cvDatesets[qahm.goalsSessionData.length] = {
					type: 'line',  // 明示的に型を指定
					label: qahml10n['graph_sessions'],
					data: qahm.graphDataArySessions,
					backgroundColor: 'rgba(105,164,226, 0.1)',
					borderColor: 'rgba(105,164,226, 0.8)',  // 線の色を追加
					borderWidth: 2,  // 線の太さを指定
					pointRadius: 2,  // ポイントのサイズを小さく
					pointHoverRadius: 5,  // ホバー時のサイズ
					fill: false,  // 塗りつぶし
					lineTension: 0.4,  // 曲線の滑らかさ
					yAxisID: 'session',
					// 以下の設定を追加
					spanGaps: true,  // データの欠損部分を線でつなぐ
					// 表示範囲を調整
					hidden: false  // 明示的に表示するよう設定
				}
                qahm.resetCanvas("cvConversionGraph");
				let dateLabels = qahm.makeFormattedDatesArray( reportRangeStart, reportRangeEnd, 'YYYY/MM/DD' );
                let cvConversionGraph = document.getElementById("cvConversionGraph").getContext('2d');
                if (cvConversionGraph) {
                    let conversionGraphChart = new Chart(cvConversionGraph, {
						type: 'line',
						data: {
							labels: dateLabels,
							datasets: cvDatesets,
						},
						options: {
							responsive: true,
							maintainAspectRatio: false,
							// 全体のスペースを確保
							layout: {
								padding: {
									left: 10,
									right: 30, // 右側に余裕を持たせる
									top: 20,
									bottom: 10
								}
							},
							scales: {
								xAxes: [{
									// カテゴリのオフセットを調整
									offset: true,
									// グリッド線の設定
									gridLines: {
										offsetGridLines: true
									},
									// 日付ラベルの設定
									ticks: {
										autoSkip: true,
										maxRotation: 0,
										minRotation: 0,
										maxTicksLimit: 10
									}
								}],
								yAxes: [{
									id: 'goals',
									position: 'left',
									ticks: {
										beginAtZero: true,
										// Y軸の最大値を調整して縦方向にズームアウト
										callback: function(value) {
											return value;
										}
									}
								},{
									id: 'session',
									position: 'right',
									ticks: {
										beginAtZero: true
									},
									gridLines: {
										drawOnChartArea: false // 2つ目のY軸のグリッド線を非表示に
									}
								}]
							},
							// データセットのバー幅調整
							elements: {
								rectangle: {
									borderWidth: 1
								}
							},
							tooltips: {
								mode: 'index',
								intersect: false
							}
						}
					});
                }
                //draw gsession_selector and graph
                for (let gid = 0; gid < qahm.goalsSessionData.length; gid++) {
                    let gcomplete = 'js_gcomplete_' + gid.toString();
                    let gvalue    = 'js_gvalue_' + gid.toString();
                    let gcvrate   = 'js_gcvrate_' + gid.toString();

                    let cmp = document.getElementById(gcomplete);
                    if (cmp) { cmp.innerText = qahm.comma( qahm.goalsSummary[gid].nCount.toString() ); }
                    let val = document.getElementById(gvalue);
                    if (val) { val.innerText = qahm.comma( qahm.goalsSummary[gid].nValue.toString() ); }
                    let cvr = document.getElementById(gcvrate);
                    if (cvr) { cvr.innerText = qahm.goalsSummary[gid].nCvrate.toString() + '%'; }


                    let canvasid = 'js_gssCanvas_' + gid.toString();
                    qahm.resetCanvas(canvasid);
                    let canvas   = document.getElementById(canvasid);
                    if ( canvas !== null ) {
                        let goalhope     = qahm.goalsArray[gid].gnum_scale / 30 * termdate;
                        let cvGoalData   = [ qahm.goalsSummary[gid].nCount, Math.floor( goalhope ) ];
                        let cvGoalGraphChart = new Chart(canvas, {
                            type: 'bar',
                            data: {
								labels: [ qahml10n['cnv_graph_present'], qahml10n['cnv_graph_goal'] ],
								datasets: [{
									label:qahml10n['cnv_graph_completions'],
									fill: false,
									lineTension: 0,
									data: cvGoalData,
									backgroundColor: [qahm.graphColorGoals[gid], qahm.colorAlfaChange(qahm.graphColorGoals[gid], 0.3) ],
								}],
                            },
                            options: {
                                legend: {
                                    labels: {
                                        fontSize: 9
                                    },
                                },
                                barPercentage : 1,
                                scales: {
                                    xAxes: [{
                                        stacked: true, //積み上げ棒グラフにする設定
                                    }],
                                    yAxes: [{
                                        stacked: true, //積み上げ棒グラフにする設定
                                        ticks: {
                                            beginAtZero: true
                                        },
                                        beforeBuildTicks: function( axis ) {
                                            if ( axis.max < 10 ) {
                                                axis.options.ticks.stepSize = 1;
                                            }
                                        }
                                    }]
                                }
                            },
                        });
                    }
                }
                qahm.drawSessionsView(qahm.goalsSessionData[0]);

            } else {
				qahm.drawSessionsView([]);
                qahm.resetCanvas("cvConversionGraph", 'style="height: 0"');
            }

            qahm.nowAjaxStep = 0;
			jQuery('#datepicker-base-textbox').prop('disabled', false);
			qahm.enabledGoalRadioButton();
            break;



        default:
            break;

    }
};

qahm.drawTableAjax = function(action, table, dateBetweenStr) {
    //new /repeat device table
    jQuery.ajax(
        {
            type: 'POST',
            url: qahm.ajax_url,
            dataType: 'json',
            data: {
                'action': action,
                'date': dateBetweenStr,
                'nonce': qahm.nonce_api,
                'tracking_id':qahm.tracking_id
            }
        }
    ).done(
        function (data) {
            let ary = data;
            if (ary) {
                qahm.makeTable(table, ary);
                table.clearReload();
            }
        }
    ).fail(
        function (jqXHR, textStatus, errorThrown) {
            qahm.log_ajax_error(jqXHR, textStatus, errorThrown);
            const cb = qahm.drawTableAjax.bind(null, action, table, dateBetweenStr);
            table.errorReload(cb);
        }
    ).always(
        function () {
        }
    );
};



// 以下元datasearch.js
qahm.drawSessionsView = function ( sessionary ) {
	//抽出中表示
	jQuery( '#extraction-proc-button' ).text( qahml10n['ds_cyusyutsu_cyu'] ).prop( 'disabled', true );
	jQuery( '#cyusyutsu_notice' ).html('<span id="cyusyutsu_session_num">...</span>' + qahml10n['ds_cyusyutsu_cyu']);

	//sessionary の中身チェック
	// is_last無しのNULL配列があるセッションは、10000個で止まるようになっている（＠qahm-data-db.php L457）
	for ( let iii = 0; iii < sessionary.length; iii++ ) {
		let tempSessionPvs = [];
		if ( sessionary[iii].length  === 10000 ) {
			for ( let jjj = 0; jjj < sessionary[iii].length; jjj++ ) {
				if ( sessionary[iii][jjj] === null ) {
					tempSessionPvs[jjj-1]['is_last'] = '1';
					break;
				}
				tempSessionPvs.push(sessionary[iii][jjj]);
			}
			sessionary[iii] = tempSessionPvs;
		}		
	}

	qahm.dsPvData = null;
	qahm.dsSessionData = sessionary;
	//session recording table.js

	//make table
	let allSmAry = qahm.createSmArray( sessionary );
	if ( allSmAry && Array.isArray( allSmAry ) && allSmAry.length > 0 ) {
		sourceMediumTable.updateData(allSmAry);
	} else {
		sourceMediumTable.updateData([]);
	}

	let alllpAry = qahm.createLpArray( sessionary );
	if ( alllpAry && Array.isArray( alllpAry ) && alllpAry.length > 0 ) {
		landingpageTable.updateData(alllpAry);
	} else {
		landingpageTable.updateData([]);
	}

	// heatmap
	qahm.createHeatmapList( reportRangeStart, reportRangeEnd );

	let allSessionAry = qahm.createSessionArray( sessionary );
	if ( allSessionAry && Array.isArray( allSessionAry ) && allSessionAry.length > 0 ) {
		sesRecTable.updateData(allSessionAry);
	} else {
		sesRecTable.updateData([]);
	}


	//ボタンを元に戻す
	jQuery( '#extraction-proc-button' ).text(qahml10n['ds_cyusyutsu_button']).prop( 'disabled', false );
	//抽出件数を表示する
	if ( allSessionAry.length == 0 ) {
		jQuery( '#cyusyutsu_notice' ).html('<span id="cyusyutsu_session_num">0</span>' + qahml10n['ds_cyusyutsu_kensu']);
	} else {
		jQuery( '#cyusyutsu_notice' ).html('<span id="cyusyutsu_session_num">' + allSessionAry.length + '</span>' + qahml10n['ds_cyusyutsu_kensu']);
	}


};


// for データを探す
jQuery( function(){

	// 変数の初期化
	let today = new Date();
	let hrgap = (today.getTimezoneOffset()) / 60;
	today.setHours( today.getHours() + hrgap + qahm.wp_time_adj );
	qahm.dsTodayUnixTime = today.setHours( 0, 0, 0, 0 );

	let periodDays = 7;

	// createDataTable();
	clickExtractButton();
	clickExtractUrl();

	function createDataTable(searchUrl, prefix) {

		let periodDays  = Math.ceil( (reportRangeEnd - reportRangeStart) / ( 24 * 60 * 60 * 1000 ) );
		let startMoment = moment(reportRangeStart);
		let endMoment   = moment(reportRangeEnd);
		let startStr    = startMoment.format('YYYY-MM-DD');
		let endStr      = endMoment.format('YYYY-MM-DD');
		let getdayStr   = endStr;

		//1st count data
		let pvcount    = 0;
		let separatepv = 10000;
		let maxpv      = 300000;
		let loopcount  = 1;
		let loopmax    = 1;
		let loopdayadd = periodDays -1;

		let ary = [];
		let isFirst = true;
		let where    = '';
		let pidcount = 0;
		let pidmax   = 100;
		let pidovermsg = qahm.japan('対象ページ数が' + pidmax.toString() + 'を超えています。集計ができない可能性があり、期間や条件を変更した方がよいかも知れません。このまま続行しますか？');

		if ( searchUrl ) {

			// page_idを調べる
			let page_id  = null;

			jQuery.Deferred(
				function(d) {
					jQuery.ajax(
						{
							type: 'POST',
							url: qahm.ajax_url,
							dataType : 'json',
							data: {
								'action': 'qahm_ajax_url_to_page_id',
								'nonce' : qahm.nonce_api,
								'url'   : searchUrl,
								'prefix': prefix,
							}
					}
					).done(
						function( data ){
							if ( ! data ) {
								alert( searchUrl + qahml10n['ds_cyusyutsu_error1'] );
								jQuery( '#extraction-proc-button' ).text(qahml10n['ds_cyusyutsu_button']).prop( 'disabled', false );
								d.reject();
							}
							pidcount = Object.keys(data).length;
							if ( pidmax < pidcount ) {
								let res = confirm( pidovermsg );
								if ( res === false ) {
									jQuery( '#extraction-proc-button' ).text(qahml10n['ds_cyusyutsu_button']).prop( 'disabled', false );
									d.reject();
								}
							}
							if ( Object.keys(data).length == 1 ) {
								page_id = data[0][ 'page_id' ];
								where =  'page_id=' + page_id.toString();
							} else {
								let instr = 'in (';
								for ( let iii = 0; iii < Object.keys(data).length; iii++ ) {
									page_id = data[iii][ 'page_id' ];
									if ( Number(page_id) > 0 ) {
										instr = instr + page_id.toString();
									}
									if ( iii === Object.keys(data).length -1 ) {
										instr = instr + ')';
									} else {
										instr = instr + ',';
									}
								}
								where = 'page_id ' + instr;
							}
							d.resolve();
						}
					).fail(
						function( jqXHR, textStatus, errorThrown ){
							qahm.log_ajax_error( jqXHR, textStatus, errorThrown );
							alert( searchUrl +  qahml10n['ds_cyusyutsu_error1'] );
							jQuery( '#extraction-proc-button' ).text(qahml10n['ds_cyusyutsu_button']).prop( 'disabled', false );
							d.reject();
						}
					);
				}
			).then(
				function(){
					//　再帰関数の定義
					let deferred = new jQuery.Deferred;
					const getSessionData = function() {
						let table = 'vr_view_session';
						if ( isFirst ) {
							jQuery.ajax(
								{
									type: 'POST',
									url: qahm.ajax_url,
									dataType : 'json',
									data: {
										'action' : 'qahm_ajax_select_data',
										'table' : table,
										'select': '*',
										'date_or_id':`date = between ${startStr} and ${endStr}`,
										'count' : true,
										'where' : where,
										'nonce':qahm.nonce_api,
										'tracking_id' :qahm.tracking_id
									}
							}
							).done(
								function( data ){
									pvcount = Number( data );
									//2nd get data loop
									if ( maxpv < pvcount ) {
										daysince = new Date(qahm.dsTodayUnixTime);
										if ((pvcount / 2) < maxpv) {
											daysince.setTime(daysince.getTime() - 30 * 1000 * 60 * 60 * 24);
											alert( qahm.sprintf( qahml10n['ds_cyusyutsu_error3'], 30 ) );
										} else if ((pvcount / 8) < maxpv) {
											daysince.setTime(daysince.getTime() - 7 * 1000 * 60 * 60 * 24);
											alert( qahm.sprintf( qahml10n['ds_cyusyutsu_error3'], 7 ) );
										} else {
											daysince.setTime(daysince.getTime() - 1 * 1000 * 60 * 60 * 24);
											alert( qahm.sprintf( qahml10n['ds_cyusyutsu_error3'], 1 ) );
										}
										startStr = moment(daysince).format('YYYY-MM-DD');
									}else if( separatepv < pvcount ) {
										let separate = Math.floor( pvcount / separatepv );
										loopmax    = separate + 1;
										loopdayadd = Math.floor( ( periodDays -1 ) / separate );
										getday     = new Date(reportRangeEnd);
										getday.setTime( getday.getTime() + ( loopdayadd - periodDays ) *1000*60*60*24 );
										getdayStr = moment(getday).format('YYYY-MM-DD');
									}
									isFirst = false;
									getSessionData();
								}
							).fail(
								function( jqXHR, textStatus, errorThrown ){
									qahm.log_ajax_error( jqXHR, textStatus, errorThrown );
									//エラー通信失敗のお知らせ文を出す
									jQuery( '#cyusyutsu_notice' ).html('<span style="color: red;">' + qahml10n['ds_cyusyutsu_error2'] + '</span>');
									//ボタンを元に戻す
									jQuery( '#extraction-proc-button' ).text(qahml10n['ds_cyusyutsu_button']).prop( 'disabled', false );
								}
							).always(
								function(){
								}
							);
						} else {
							jQuery.ajax(
								{
									type: 'POST',
									url: qahm.ajax_url,
									dataType : 'json',
									data: {
										'action' : 'qahm_ajax_select_data',
										'table' : table,
										'select': '*',
										'date_or_id':`date = between ${startStr} and ${getdayStr}`,
										'count' : false,
										'where' : where,
										'nonce':qahm.nonce_api,
										'tracking_id' :qahm.tracking_id
									}
								}
							).done(
								function( data ){
									ary = ary.concat(data);
									loopcount++;
									if ( loopcount < loopmax ) {
										//Fromを1日進める
										getday.setTime( getday.getTime() + 1 *1000*60*60*24 );
										startStr =  moment(getday).format('YYYY-MM-DD');
										//Toをさらに loopdayadd - 1日分進める
										getday.setTime( getday.getTime() +  ( loopdayadd - 1 )*1000*60*60*24 );
										getdayStr = moment(getday).format('YYYY-MM-DD');
										getSessionData();
									} else if ( loopcount === loopmax ) {
										//Fromを1日進める
										getday.setTime( getday.getTime() + 1 *1000*60*60*24 );
										startStr =  moment(getday).format('YYYY-MM-DD');
										//Toは昨日まで
										getdayStr = endStr;
										getSessionData();
									} else {
										deferred.resolve();
									}
								}
							).fail(
								function( jqXHR, textStatus, errorThrown ){
									qahm.log_ajax_error( jqXHR, textStatus, errorThrown );
									//エラー通信失敗のお知らせ文を出す
									jQuery( '#cyusyutsu_notice' ).html('<span style="color: red;">' + qahml10n['ds_cyusyutsu_error2'] + '</span>');
									//ボタンを元に戻す
									jQuery( '#extraction-proc-button' ).text(qahml10n['ds_cyusyutsu_button']).prop( 'disabled', false );
								}
							).always(
								function(){
								}
							);
						}
						return deferred.promise();
					}; //end of 'getSessionData' difinition

					// 実際の呼び出し
					getSessionData().then( function() {
						//メモリの解放後データ配列をセット
						qahm.dsPvData = null;
						qahm.drawSessionsView( ary );
						// qahm.dsSessionData = ary;
						// console.log( qahm.dsSessionData );

						/*
						let allSessionAry = qahm.createSessionArray(ary);

						if (typeof qahm.sDayTable !== 'undefined' && qahm.sDayTable !== '') {
							qahm.sDayTable.rawDataArray = allSessionAry;
							if (qahm.sDayTable.visibleArray.length === 0) {
								qahm.sDayTable.generateTable();
							} else {
								if ( qahm.sDayTable.isNoCheck() && qahm.sDayTable.countActiveFilterBoxes() === 0 && qahm.sDayTable.isScrolled() === false ) {
									qahm.sDayTable.updateTable();
								}
							}
						}
						*/

						// //session recording table.js
						// let allSessionAry = qahm.createSessionArray(ary);
						// if (typeof qahm.sDayTable !== 'undefined' && qahm.sDayTable !== '') {
						// 	qahm.sDayTable.rawDataArray = allSessionAry;
						// 	if (! qahm.sDayTable.headerTableByID) {
						// 		qahm.sDayTable.generateTable();
						// 	} else {
						// 		qahm.sDayTable.updateTable();
						// 	}
						// }
                        
						// // heatmap
						// qahm.createHeatmapList( reportRangeStart, reportRangeEnd );

						//ボタンを元に戻す
						jQuery( '#extraction-proc-button' ).text(qahml10n['ds_cyusyutsu_button']).prop( 'disabled', false );
						//抽出件数を表示する
						if ( allSessionAry.length == 0 ) {
							jQuery( '#cyusyutsu_notice' ).html('<span id="cyusyutsu_session_num">0</span>' + qahml10n['ds_cyusyutsu_kensu']);
						} else {
							jQuery( '#cyusyutsu_notice' ).html('<span id="cyusyutsu_session_num">' + allSessionAry.length + '</span>' + qahml10n['ds_cyusyutsu_kensu']);
						}
					});
				}
			);

		} else {

			//1st count data
			let pvcount    = 0;
			let separatepv = 10000;
			let maxpv      = 300000;
			let loopcount  = 1;
			let loopmax    = 1;
			let loopdayadd = periodDays -1;

			//　再帰関数の定義
			let deferred = new jQuery.Deferred;
			const getPvData = function() {
				let table = 'vr_view_pv';
				if ( isFirst ) {
					jQuery.ajax(
						{
							type: 'POST',
							url: qahm.ajax_url,
							dataType : 'json',
							data: {
								'action' : 'qahm_ajax_select_data',
								'table' : table,
								'select': '*',
								'date_or_id':`date = between ${startStr} and ${endStr}`,
								'count' : true,
								'nonce':qahm.nonce_api,
								'tracking_id' : qahm.tracking_id
							}
					}
					).done(
						function( data ){
							pvcount = Number( data );
							//2nd get data loop
							if ( maxpv < pvcount ) {
								daysince = new Date(qahm.dsTodayUnixTime);
								if ((pvcount / 2) < maxpv) {
									daysince.setTime(daysince.getTime() - 30 * 1000 * 60 * 60 * 24);
									alert( qahm.sprintf( qahml10n['ds_cyusyutsu_error3'], 30 ) );
								} else if ((pvcount / 8) < maxpv) {
									daysince.setTime(daysince.getTime() - 7 * 1000 * 60 * 60 * 24);
									alert( qahm.sprintf( qahml10n['ds_cyusyutsu_error3'], 7 ) );
								} else {
									daysince.setTime(daysince.getTime() - 1 * 1000 * 60 * 60 * 24);
									alert( qahm.sprintf( qahml10n['ds_cyusyutsu_error3'], 1 ) );
								}
								startStr = moment(daysince).format('YYYY-MM-DD');
							}else if( separatepv < pvcount ) {
								let separate = Math.floor( pvcount / separatepv );
								loopmax    = separate + 1;
								loopdayadd = Math.floor( ( periodDays -1 ) / separate );
								//getday     = new Date(qahm.dsTodayUnixTime);
								getday     = new Date(reportRangeEnd);
								getday.setTime( getday.getTime() + ( loopdayadd - periodDays ) *1000*60*60*24 );
								getdayStr = moment(getday).format('YYYY-MM-DD');
							}
							isFirst = false;
							getPvData();
						}
					).fail(
						function( jqXHR, textStatus, errorThrown ){
							qahm.log_ajax_error( jqXHR, textStatus, errorThrown );
						}
					).always(
						function(){
						}
					);
				} else {
					jQuery.ajax(
						{
							type: 'POST',
							url: qahm.ajax_url,
							dataType : 'json',
							data: {
								'action' : 'qahm_ajax_select_data',
								'table' : table,
								'select': '*',
								'date_or_id':`date = between ${startStr} and ${getdayStr}`,
								'count' : false,
								'nonce':qahm.nonce_api,
								'tracking_id' : qahm.tracking_id
							}
						}
					).done(
						function( data ){
							ary = ary.concat(data);
							loopcount++;
							if ( loopcount < loopmax ) {
								//Fromを1日進める
								getday.setTime( getday.getTime() + 1 *1000*60*60*24 );
								startStr =  moment(getday).format('YYYY-MM-DD');
								//Toをさらに loopdayadd - 1日分進める
								getday.setTime( getday.getTime() +  ( loopdayadd - 1 )*1000*60*60*24 );
								getdayStr = moment(getday).format('YYYY-MM-DD');
								getPvData();
							} else if ( loopcount === loopmax ) {
								//Fromを1日進める
								getday.setTime( getday.getTime() + 1 *1000*60*60*24 );
								startStr =  moment(getday).format('YYYY-MM-DD');
								//Toは昨日まで
								getdayStr = endStr;
								getPvData();
							} else {
								deferred.resolve();
							}
						}
					).fail(
						function( jqXHR, textStatus, errorThrown ){
							qahm.log_ajax_error( jqXHR, textStatus, errorThrown );
							//エラー通信失敗のお知らせ文を出す
							jQuery( '#cyusyutsu_notice' ).html('<span style="color: red;">' + qahml10n['ds_cyusyutsu_error2'] + '</span>');
							//ボタンを元に戻す
							jQuery( '#extraction-proc-button' ).text(qahml10n['ds_cyusyutsu_button']).prop( 'disabled', false );
						}
					).always(
						function(){
						}
					);
				}
				return deferred.promise();
			}; //end of 'getPvData' difinition

			// 実際の呼び出し
			getPvData().then( function() {
				//メモリの解放後データ配列をセット
				qahm.dsSessionData = null;
				qahm.dsPvData = ary;
				//console.log( qahm.dsPvData );

				let allSessionAry = qahm.createSessionArray(ary);
				sesRecTable.updateData(allSessionAry);

				/*
				if (typeof qahm.sDayTable !== 'undefined' && qahm.sDayTable !== '') {
					qahm.sDayTable.rawDataArray = allSessionAry;
					if ( ! qahm.sDayTable.headerTableByID ) {
						qahm.sDayTable.generateTable();
					} else {
						qahm.sDayTable.updateTable(true);
					}
				}
					*/

				// heatmap
				qahm.createHeatmapList( reportRangeStart, reportRangeEnd );

				//ボタンを元に戻す
				jQuery( '#extraction-proc-button' ).text(qahml10n['ds_cyusyutsu_button']).prop( 'disabled', false );
				//抽出件数を表示する
				jQuery( '#cyusyutsu_notice' ).html('<span id="cyusyutsu_session_num">' + allSessionAry.length + '</span>' + qahml10n['ds_cyusyutsu_kensu']);
			});
		}
	}

	// 抽出ボタンクリック
	function clickExtractButton() {
		jQuery( '#extraction-proc-button' ).on( 'click', function() {
			let uri         = new URL(window.location.href);
			let httpdomaina = uri.origin;
			let httpdomainb = httpdomaina + '/';
			const prefix = jQuery( 'input:radio[name="selectuser_pagematch"]:checked' ).val();
			const searchUrl = jQuery( '#jsSearchPageUrl' ).val();
			if ( prefix === 'pagematch_prefix' ) {
				if ( searchUrl === httpdomaina || searchUrl === httpdomainb ) {
					alert(qahm.japan('全てのページが条件になっています。'))
					return;
				}
			}
			jQuery( '#extraction-proc-button' ).text( qahml10n['ds_cyusyutsu_cyu'] ).prop( 'disabled', true );
			createDataTable(searchUrl, prefix);
		});
	}
	// 抽出URLクリック
	function clickExtractUrl() {
		jQuery( '#jsSearchPageUrl' ).on( 'click', function() {
			jQuery( '#js_gsession_selectuser' ).trigger('click');
		});
	}


});


/**
 *  「見たいデータを探す」のSource/Media一覧Table
 */
qahm.createSmArray = function( vr_sessions_ary ) {
	let allSmAry = [];
	let allSmTempAry = [];
	let is_session = false;
	let firstTitle = '';
	let firstTitleEl = '';
	let firstUrl   = '';
	let lastTitle = '';
	let lastTitleEl = '';
	let lastUrl   = '';
	let device = 'desktop';
	let reader_id = 0;
	let pvcnt  = 0;
	let is_bounce = 0;
	let is_newuser = true;
	let last_exit_time = 0;
	let source_domain = '';
	let utm_medium = '';
	let sec_on_site = 0;

	if ( vr_sessions_ary[0] ) {
		for ( let iii = 0; iii < vr_sessions_ary.length; iii++ ) {
			//sessin start?
			for ( let jjj = 0; jjj < vr_sessions_ary[iii].length; jjj++ ) {
				if (Number(vr_sessions_ary[iii][jjj].pv) === 1) {
					is_session = true;
					reader_id = vr_sessions_ary[iii][jjj].reader_id;
					pvcnt = 1;
					sec_on_site = Number(vr_sessions_ary[iii][jjj].browse_sec);
					if (Number(vr_sessions_ary[iii][jjj].is_last) === 1) {
						is_bounce = 1;
					} else {
						is_bounce = 0;
					}
				} else {
					pvcnt++;
					sec_on_site = sec_on_site + Number(vr_sessions_ary[iii][jjj].browse_sec);
				}
				if (is_session) {
					//last ?
					if (Number(vr_sessions_ary[iii][jjj].is_last) === 1) {
						is_session = false;
						is_newuser = Number(vr_sessions_ary[iii][jjj].is_newuser);
						last_exit_time = (Date.parse(vr_sessions_ary[iii][jjj].access_time)) / 1000;
						source_domain = vr_sessions_ary[iii][jjj].source_domain;
						utm_medium = vr_sessions_ary[iii][jjj].utm_medium;
						if ( utm_medium === undefined ) {
							utm_medium = '';
						}

						//make array
						let smAry = [source_domain, utm_medium, [reader_id], is_newuser, 1, is_bounce, pvcnt, sec_on_site];
						let is_find = false;
						if ( allSmTempAry.length !== 0 ) {
							//search
							for ( let sss = 0; sss < allSmTempAry.length; sss++ ) {
								if ( allSmTempAry[sss][0] === source_domain && allSmTempAry[sss][1] === utm_medium ) {
									is_find = true;
									allSmTempAry[sss][2].push(reader_id);
									allSmTempAry[sss][3] += is_newuser;
									allSmTempAry[sss][4] += 1;
									allSmTempAry[sss][5] += is_bounce;
									allSmTempAry[sss][6] += pvcnt;
									allSmTempAry[sss][7] += sec_on_site;
									break;
								}
							}
						}
						if ( !is_find ) {
							allSmTempAry.push(smAry);
						}
					}
				}
			}
		}
	}
	for ( let sss = 0; sss < allSmTempAry.length; sss++ ) {
		let sessions   = allSmTempAry[sss][4];
		let uniquser   = 0;
		let bouncerate = 0;
		let pageperssn = 0;
		let avgsecsite = 0;

		uniquser   = Array.from(new Set(allSmTempAry[sss][2])).length;
		bouncerate = qahm.roundToX(allSmTempAry[sss][5] / sessions * 100 , 1);
		pageperssn = qahm.roundToX(allSmTempAry[sss][6] / sessions , 2);
		avgsecsite = qahm.roundToX(allSmTempAry[sss][7] / sessions , 0);

		allSmTempAry[sss][2] = uniquser;
		allSmTempAry[sss][5] = bouncerate;
		allSmTempAry[sss][6] = pageperssn;
		allSmTempAry[sss][7] = avgsecsite;
    }
	return allSmTempAry;
};

/**
 *  「見たいデータを探す」のlp一覧Table
 */
qahm.createLpArray = function( vr_sessions_ary ) {
	let allLpAry = [];
	let is_session = false;
	let firstTitle = '';
	let firstTitleEl = '';
	let firstUrl   = '';
	let lastTitle = '';
	let lastTitleEl = '';
	let lastUrl   = '';
	let wp_page_id = 0;
	let device = 'desktop';
	let reader_id = 0;
	let pvcnt  = 0;
	let last_exit_time = 0;
	let source_domain = '';
	let utm_source = '';
	let sec_on_site = 0;
    let replayTdHtml = '';
	let is_bounce = 0;
    //url host
    let uri        = new URL(window.location.href);
    let httplen    = uri.origin.length;
	let is_newuser = 1;
	let page_id = 0;

	if ( vr_sessions_ary[0] ) { //ym wrote
		for ( let iii = 0; iii < vr_sessions_ary.length; iii++ ) {
			//sessin start?
			for ( let jjj = 0; jjj < vr_sessions_ary[iii].length; jjj++ ) {
				if (Number(vr_sessions_ary[iii][jjj].pv) === 1) {
					is_session = true;
					page_id = vr_sessions_ary[iii][jjj].page_id;
					firstTitle = vr_sessions_ary[iii][jjj].title;
					firstTitleEl = qahm.truncateStr( firstTitle, 80 );
					firstUrl = vr_sessions_ary[iii][jjj].url;
					reader_id = vr_sessions_ary[iii][jjj].reader_id;
					pvcnt = 1;
					sec_on_site = Number(vr_sessions_ary[iii][jjj].browse_sec);
					replayTdHtml = '';
					if (Number(vr_sessions_ary[iii][jjj].is_last) === 1) {
						is_bounce = 1;
					} else {
						is_bounce = 0;
					}


				} else {
					pvcnt++;
					sec_on_site = sec_on_site + Number(vr_sessions_ary[iii][jjj].browse_sec);
				}
				if (is_session) {

					//last ?
					if (Number(vr_sessions_ary[iii][jjj].is_last) === 1) {
						is_session = false;
						is_newuser = Number(vr_sessions_ary[iii][jjj].is_newuser);
						source_domain = vr_sessions_ary[iii][jjj].source_domain;
						utm_source = vr_sessions_ary[iii][jjj].utm_medium;

						//make array
						let lpAry = [page_id, firstTitleEl, firstUrl, 1, is_newuser, is_newuser, is_bounce, pvcnt, sec_on_site];
						let is_find = false;
						if ( allLpAry.length !== 0 ) {
							//search
							for ( let sss = 0; sss < allLpAry.length; sss++ ) {
								if ( allLpAry[sss][0] === page_id ) {
									is_find = true;
									allLpAry[sss][3] += 1;
									allLpAry[sss][4] += is_newuser;
									allLpAry[sss][5] += is_newuser;
									allLpAry[sss][6] += is_bounce;
									allLpAry[sss][7] += pvcnt;
									allLpAry[sss][8] += sec_on_site;
									break;
								}
							}
						}
						if ( !is_find ) {
							allLpAry.push(lpAry);
						}
					}
				}
			}
		}
	}
	for ( let sss = 0; sss < allLpAry.length; sss++ ) {
		let sessions   = allLpAry[sss][3];
		let newwerrate = 0;
		let bouncerate = 0;
		let pageperssn = 0;
		let avgsecsite = 0;

		newwerrate = qahm.roundToX(allLpAry[sss][4] / sessions * 100, 1);
		bouncerate = qahm.roundToX(allLpAry[sss][6] / sessions * 100, 1);
		pageperssn = qahm.roundToX(allLpAry[sss][7] / sessions , 2);
		avgsecsite = qahm.roundToX(allLpAry[sss][8] / sessions , 0);

		allLpAry[sss][4] = newwerrate;
		allLpAry[sss][6] = bouncerate;
		allLpAry[sss][7] = pageperssn;
		allLpAry[sss][8] = avgsecsite;
    }
	return allLpAry;
}

/**
 *  「見たいデータを探す」のsession一覧Table
 */
qahm.createSessionArray = function( vr_view_ary ) {
	let allSessionAry = [];
	let is_session = false;
	let firstTitle = '';
	let firstTitleEl = '';
	let firstUrl   = '';
	let lastTitle = '';
	let lastTitleEl = '';
	let lastUrl   = '';
	let device = 'desktop';
	let reader_id = 0;
	let pvcnt  = 0;
	let last_exit_time = 0;
	let source_domain = '';
	let utm_source = '';
	let sec_on_site = 0;
	let firstAccessTime = null;

	if ( vr_view_ary[0] ) { //ym wrote
		for ( let iii = 0; iii < vr_view_ary.length; iii++ ) {
			//sessin start?
			for ( let jjj = 0; jjj < vr_view_ary[iii].length; jjj++ ) {
				if (Number(vr_view_ary[iii][jjj].pv) === 1) {
					is_session = true;
					firstTitle = vr_view_ary[iii][jjj].title;
					firstTitleEl = qahm.truncateStr( firstTitle, 80 );
					firstUrl = vr_view_ary[iii][jjj].url;
					reader_id = vr_view_ary[iii][jjj].reader_id;
					switch (Number(vr_view_ary[iii][jjj].device_id)) {
						case 2:
							device = 'tablet';
							break;
						case 3:
							device = 'mobile';
							break;
						case 1:
						default:
							device = 'desktop';
							break;
					}
					pvcnt = 1;
					sec_on_site = Number(vr_view_ary[iii][jjj].browse_sec);
					firstAccessTime = null;
				} else {
					pvcnt++;
					sec_on_site = sec_on_site + Number(vr_view_ary[iii][jjj].browse_sec);
				}
				if (is_session) {
					//再生ボタンを一番若いpvで作成する。但し日付が古い時は暫定的なボタンを表示
					if (Number(vr_view_ary[iii][jjj].is_raw_e) === 1) {
						if ( ! firstAccessTime ) {
							firstAccessTime = vr_view_ary[iii][jjj].access_time;
						}
					}

					//2024/02/27時点で最後のPVのデータにis_last=trueが入らない不具合があるため、is_lastでチェックすることをやめた
					if (vr_view_ary[iii].length === (jjj+1)) {
					//if (Number(vr_view_ary[iii][jjj].is_last) === 1) {
						is_session = false;

						//この時点で再生ボタンが空ならこの行は追加しない
						if ( ! firstAccessTime ) {
							continue;
						}

						lastTitle = vr_view_ary[iii][jjj].title;
						lastTitleEl = qahm.truncateStr( lastTitle, 80 );
						lastUrl = vr_view_ary[iii][jjj].url;
						/*const accessDate = new Date( vr_view_ary[iii][jjj].access_time * 1000 );
						const year       = accessDate.getFullYear().toString().padStart(4, '0');
						const month      = (accessDate.getMonth() + 1).toString().padStart(2, '0');
						const day        = accessDate.getDate().toString().padStart(2, '0');
						last_exit_time   = year + '-' + month + '-' + day;
						*/
						last_exit_time   = vr_view_ary[iii][jjj].access_time;
						source_domain = vr_view_ary[iii][jjj].source_domain;
						// #964: メディア列（PHP側で補完済み）
						let utm_medium = vr_view_ary[iii][jjj].utm_medium || '';

						//make array
						let sessionAry = [device, last_exit_time, reader_id, firstUrl, firstTitleEl, lastUrl, lastTitleEl, source_domain, utm_medium, pvcnt, sec_on_site, firstAccessTime, ''];
						allSessionAry.push(sessionAry);
					}
				}
			}
		}
	} //endif(ym wrote)
	return allSessionAry;
}



/**
 *  ヒートマップ一覧の構築
 */
qahm.createHeatmapList = function( startDate, endDate ) {
	qahm.hmList = {};
	let dsData  = null;

	// ここでいい感じに整形できるならしたい
	if ( qahm.dsPvData ) {
		dsData = qahm.dsPvData;
	} else {
		dsData = qahm.dsSessionData;
	}

	const startTime = performance.now();
	for ( let i = 0, paramLen = dsData.length; i < paramLen; i++ ) {
		if ( dsData[i] ) {
			for ( let j = 0, testLen = dsData[i].length; j < testLen; j++ ) {
				let param = dsData[i][j];
				if ( param.is_raw_p == 0 && param.is_raw_c == 0 ) {
					continue;
				}

				// ハッシュがつくURLは今のところ除外
				if( param.url.indexOf('#') !== -1 ) {
					continue;
				}

				let accessTime = new Date( param.access_time * 1000 );
				if( startDate > accessTime || endDate < accessTime ) {
					continue;
				}

				// pvData構築
				for ( const devType in qahm.devices ) {
					if( qahm.devices[devType]['id'] === parseInt( param.device_id ) ) {
						let devName = qahm.devices[devType]['name'];
						break;
					}
				}

				/* 連想配列に直接代入（オブジェクトパターン） */
				// 存在チェック
				let existHMVer = false;
				if( qahm.hmList[param.page_id] && qahm.hmList[param.page_id].verIdx && param.version_no ) {
					if ( qahm.hmList[param.page_id].verInfo[param.version_no] ) {
						qahm.hmList[param.page_id].verInfo[param.version_no][param.device_id].dataNum++;
						qahm.hmList[param.page_id].verInfo[param.version_no][param.device_id].verId = param.version_id;

						let accessTime = new Date( param.access_time * 1000 );
						if ( qahm.hmList[param.page_id].verInfo[param.version_no].startDate > accessTime ) {
							qahm.hmList[param.page_id].verInfo[param.version_no].startDate = accessTime;
						} else if ( qahm.hmList[param.page_id].verInfo[param.version_no].endDate < accessTime ) {
							qahm.hmList[param.page_id].verInfo[param.version_no].endDate = accessTime;
						}
						existHMVer = true;
					}

					if ( qahm.hmList[param.page_id].verIdx < param.version_no ) {
						qahm.hmList[param.page_id].verIdx = param.version_no;
					}
				}

				if ( ! existHMVer ) {
					// アクセス速度のことを考慮して連想配列にはpage_idを入れている
					if ( ! qahm.hmList[param.page_id] ) {
						qahm.hmList[param.page_id] = {};
						qahm.hmList[param.page_id].url     = param.url;
						qahm.hmList[param.page_id].title   = param.title;
						qahm.hmList[param.page_id].verIdx  = param.version_no;
						qahm.hmList[param.page_id].verInfo = {};
					}

					if ( param.version_no ) {
						qahm.hmList[param.page_id].verInfo[param.version_no] = {};
						for ( const devType in qahm.devices ) {
							qahm.hmList[param.page_id].verInfo[param.version_no][qahm.devices[devType]['id']] = {};
							qahm.hmList[param.page_id].verInfo[param.version_no][qahm.devices[devType]['id']].dataNum = 0;
							qahm.hmList[param.page_id].verInfo[param.version_no][qahm.devices[devType]['id']].verId = null;
						}
						qahm.hmList[param.page_id].verInfo[param.version_no][param.device_id].dataNum++;
						qahm.hmList[param.page_id].verInfo[param.version_no][param.device_id].verId     = param.version_id;
						let accessTime = new Date( param.access_time * 1000 );
						qahm.hmList[param.page_id].verInfo[param.version_no].startDate = accessTime;
						qahm.hmList[param.page_id].verInfo[param.version_no].endDate   = accessTime;
					} else {
						if ( ! qahm.hmList[param.page_id].verIdx ) {
							qahm.hmList[param.page_id].verIdx = null;
						}
					}
				}
			}
		} else {
			continue;
		}
	}

	const endTime = performance.now(); // 終了時間
	// console.log( endTime - startTime ); // 何ミリ秒かかったかを表示する

	qahm.hmList = Object.entries( qahm.hmList );

	let allHeatmapAry = [];
	for ( let hmIdx = 0, hmLen = qahm.hmList.length; hmIdx < hmLen; hmIdx++ ) {
		let pageId  = qahm.hmList[hmIdx][0];
		let hm      = qahm.hmList[hmIdx][1];
		
		// データ数
		let verInfo    = null;
		let dataDsk    = 0;
		let dataTab    = 0;
		let dataSmp    = 0;

		if ( hm.verIdx ) {
			verInfo  = hm.verInfo[hm.verIdx];
			dataDsk  = parseInt( verInfo[1]['dataNum'] );
			dataTab  = parseInt( verInfo[2]['dataNum'] );
			dataSmp  = parseInt( verInfo[3]['dataNum'] );
		}
		hm.title = qahm.truncateStr( hm.title, 80 );

		allHeatmapAry.push( [
			pageId,
			hm.url,
			hm.title,
			dataDsk + dataTab + dataSmp,
			'',
		] );
	}

	if ( allHeatmapAry && Array.isArray( allHeatmapAry ) && allHeatmapAry.length > 0 ) {
		heatmapTable.updateData( allHeatmapAry );
	} else {
		heatmapTable.updateData( [] );
	}

	// デバイスリンククリック
	jQuery( document ).off( 'click', '.qa-table-heatmap-container span' );
	jQuery( document ).on(
		'click',
		'.qa-table-heatmap-container span',
		function(){
			let url           = new URL(window.location.href);
			let params        = url.searchParams;
			let trackingId    = params.get('tracking_id');
			let startMoment   = moment(reportRangeStart);
			let endMoment     = moment(reportRangeEnd);
			let startDate     = startMoment.format('YYYY-MM-DD HH:mm:ss');
			let endDate       = endMoment.format('YYYY-MM-DD HH:mm:ss');
			let pageId        = jQuery( this ).data( 'page_id' );
			let deviceName    = jQuery( this ).data( 'device_name' );
			let isLandingPage = jQuery( this ).data( 'is_landing_page' );
			let isGoal        = jQuery( this ).data( 'is_goal' );

			qahm.createCap( startDate, endDate, pageId, deviceName, isLandingPage, null, trackingId, isGoal );
		}
	);
	//});

	function getDeviceNumHtml( verInfo ) {
		let devIcon    = [];
		let devList    = [];
		let devDataNum = [];
		let devVerId   = [];
		let devMax     = Object.keys( qahm.devices ).length;
		let devIdx     = 0;

		for ( let devKey in qahm.devices ) {
			devIcon[devIdx]    = 'dashicons-' + devKey;
			devList[devIdx]    = qahm.devices[devKey]['name'];
			devDataNum[devIdx] = 0;
			devVerId[devIdx]   = 0;
			devIdx++;
		};

		if ( verInfo ) {
			for ( devIdx = 0; devIdx < devMax; devIdx++ ) {
				devDataNum[devIdx] = verInfo[devIdx+1]['dataNum'];
				devVerId[devIdx]   = verInfo[devIdx+1]['verId'];
			}
		}

		let html = '';

		for ( let devIdx = 0; devIdx < devMax; devIdx++ ) {
			let devNotExistsStyle = 'style="opacity: 0.3; margin-right: 8px;"';
			if ( devDataNum[devIdx] > 0 ){
				html += '<a target="_blank" class="qahm-heatmap-link" data-device_name="' + devList[devIdx] + '" data-version_id="' + devVerId[devIdx] + '" data-is_landing_page="0">';
				html += '<span class="dashicons ' + devIcon[devIdx] + '"></span>';
				html += '<span>(' + devDataNum[devIdx] + ')</span>';
				html += '</a>';
			} else {
				html += '<span ' + devNotExistsStyle + '>';
				html += '<span class="dashicons ' + devIcon[devIdx] + '"></span>';
				html += '<span>(0)</span>';
				html += '</span>';
			}
		}

		return html;
	}

	// 文字列省略。全角半角対応
	function omitStr( text, len, truncation ) {
		if (truncation === undefined) {
			truncation = '';
		}
		let text_array = text.split( '' );
		let count      = 0;
		let str        = '';
		for (i = 0; i < text_array.length; i++) {
			let n = escape( text_array[i] );
			if (n.length < 4) {
				count++;
			} else {
				count += 2;
			}
			if (count > len) {
				return str + truncation;
			}
			str += text.charAt( i );
		}
		return text;
	};

	//日付から文字列に変換する関数
	function getDataPeriod( date ) {
		let yearStr   = date.getFullYear();
		let monthStr  = date.getMonth() + 1;
		let dayStr    = date.getDate();

		// YYYY-MM-DDの形にする
		let formatStr = yearStr;
		formatStr += '-' + ('0' + monthStr).slice(-2);
		formatStr += '-' + ('0' + dayStr).slice(-2);

		return formatStr;
	};
};



/**
 *  目標ラジオボタンのリセット
 */
qahm.resetGoalRagioButton = function() {
	// name="js_gsession_selector" のラジオボタンを全て取得
	const radioButtons = document.querySelectorAll('input[name="js_gsession_selector"]');

	// 各ラジオボタンのチェックを外す
	radioButtons.forEach((radioButton) => {
		radioButton.checked = false;
	});
	// 特定のラジオボタンにチェックを入れる
	const specificRadioButton = document.getElementById('js_gsession_selector_0');
	if (specificRadioButton) {
		specificRadioButton.checked = true;
	}
}

