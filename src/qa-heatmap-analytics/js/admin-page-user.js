var qahm = qahm || {};

if ( typeof qahm.tracking_id === 'undefined' ) {
	let url         = new URL(window.location.href);
	let params      = url.searchParams;
	qahm.tracking_id = params.get('tracking_id');
}
qahm.reportNumPvs = 0;

window.addEventListener('DOMContentLoaded', function() {
	qahm.initDateSetting();

	//create audience table
	audienceHeader = [
		{ key: 'user_type', label: qahml10n['table_user_type'], width: 10 },
		{ key: 'device_cat', label: qahml10n['table_device_cat'], width: 10 },
		{ key: 'user', label: qahml10n['table_user'], width: 10, type: 'integer' },
		{ key: '', hidden: true },	// 現在未使用
		{ key: 'session', label: qahml10n['table_session'], type: 'integer', width: 10 },
		{ key: 'bounce_rate', label: qahml10n['table_bounce_rate'], width: 10, type: 'percentage' },
		{ key: 'page_session', label: qahml10n['table_page_session'], width: 10, type: 'float' },
		{ key: 'avg_session_time', label: qahml10n['table_avg_session_time'], width: 10, type: 'duration' },
		{ key: 'goal_conversion_rate', label: qahml10n['table_goal_conversion_rate'], width: 10, type: 'percentage', typeOptions: { precision: 1 } },
		{ key: 'goal_completions', label: qahml10n['table_goal_completions'], width: 10, type: 'integer', typeOptions: { precision: 1 } },
		{ key: 'goal_value', label: qahml10n['table_goal_value'], width: 10, type: 'integer' },
	];
	audienceOptions = {
		perPage: 100,
		pagination: true,
		exportable: true,
		sortable: true,
        filtering: true,
		maxHeight: 300,
		stickyHeader: true,
		initialSort: {
			column: 'user_type',
			direction: 'asc'
		}
	};
	audienceTable = qaTable.createTable('#tb_audienceDevice', audienceHeader, audienceOptions);

    let nrdradios = document.getElementsByName( `js_nrdGoals` );
    for ( let jjj = 0; jjj < nrdradios.length; jjj++ ) {
        nrdradios[jjj].addEventListener( 'click', qahm.changeNrdGoal );
    }

	// create session recoding table
	sesRecColumns = [
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
		{ key: 'referrer', label: qahml10n['table_referrer'], width: 11,
			formatter: function(value, row) {
				if ( value !== 'direct' && value !== qahml10n['table_total'] ) {
					ret = `<a href="//${value}" target="_blank" rel="noopener">${value}</a>`;
				} else {
					ret = value;
				}
				return ret;
			}
		},
		{ key: 'pv', label: qahml10n['table_pv'], width: 7, type: 'integer' },
		{ key: 'site_taizaijikan', label: qahml10n['table_site_taizaijikan'], width: 10, type: 'duration' },
		{ key: 'access_time', hidden: true },
		{ key: 'saisei', label: qahml10n['table_saisei'], width: 7, sortable: false, exportable: false, filtering: false,
			formatter: function(value, row) {
				return `<div class="qa-table-replay-container">
						<span class="icon-replay" data-reader_id="${row.reader_id}" data-replay_id="1" data-access_time="${row.access_time}"><span class="dashicons dashicons-format-video"></span></span>
					</div>`;
			}
		},
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
	sesRecTable = qaTable.createTable('#sday_table', sesRecColumns, sesRecOptions);

    let sesRecRadios = document.getElementsByName( `js_sesRec` );
    for ( let jjj = 0; jjj < sesRecRadios.length; jjj++ ) {
        sesRecRadios[jjj].addEventListener( 'click', qahm.changeSesRec );
    }


	// イベントの登録
	qahm.replayClickEvent();

	//csv download
	let statsDownloadBtn = document.getElementById('csv-download-btn');
	if ( statsDownloadBtn ) {
		statsDownloadBtn.addEventListener( 'click', function() {
			let gosign = window.confirm( qahm.sprintfAry( qahml10n['download_msg1'], moment(reportRangeStart).format('ll'), moment(reportRangeEnd).format('ll') ) + '\n' + qahml10n['download_msg2'] );
			if( gosign ) {
				qahm.downloadAudienceCsv( reportRangeStart, reportRangeEnd, qahm.reportNumPvs );
			}
		});
	}
	
});
qahm.changeNrdGoal = function(e) {
    let checkedId = e.target.id;
    let idsplit   = checkedId.split('_');
    let gid       = Number( idsplit[2] );
	audienceTable.showLoading();
	audienceTable.updateData(qahm.nrdArray[gid]);
    //qahm.makeTable( audienceTable, qahm.nrdArray[gid] );
};
qahm.changeSesRec = function(e) {
    let checkedId = e.target.id;
    let idsplit   = checkedId.split('_');
    let gid       = Number( idsplit[2] );
    qahm.drawSessionsView( qahm.sessionAry[gid] );
};


/** -------------------------------------
 * データの取得と描画・表示
 */
jQuery(
	function() {
		qahm.nowAjaxStep = 0;
		qahm.renderAudienceData(reportDateBetween, dateRangeYmdAry);
		qahm.setDateRangePicker();

    }
);
// カレンダー期間変更時の処理
jQuery(document).on('qahm:dateRangeChanged', function( RangeStart, RangeEnd ) {
	qahm.nowAjaxStep = 0;
	qahm.renderAudienceData(reportDateBetween, dateRangeYmdAry);
});

qahm.renderAudienceData = function(dateBetweenStr, dateYmdAry) {
    switch (qahm.nowAjaxStep) {
        case 0:
			jQuery('#datepicker-base-textbox').prop('disabled', true);
			qahm.disabledGoalRadioButton();
			audienceTable.showLoading();
			sesRecTable.showLoading();

            qahm.nowAjaxStep = 'getRecentSessions';
            qahm.renderAudienceData(dateBetweenStr, dateYmdAry);
            break;

		case 'getRecentSessions':
            qahm.sessionAry = new Array();

            jQuery.ajax(
                {
                    type: 'POST',
                    url: qahm.ajax_url,
                    dataType : 'json',
                    data: {
                        'action' : 'qahm_ajax_get_recent_sessions',
                        'date' : dateBetweenStr,
                        'nonce':qahm.nonce_api,
                        'tracking_id':qahm.tracking_id
                    }
                }
            ).done(
                function( data ){
					if ( data ) {
						qahm.sessionAry[0] = data;
					}
                    qahm.nowAjaxStep = 'getGoalsSessions';
                    qahm.renderAudienceData(dateBetweenStr, dateYmdAry);
                }
            ).fail(
                function( jqXHR, textStatus, errorThrown ){
                    qahm.log_ajax_error( jqXHR, textStatus, errorThrown );
                }
            );
            break;

        case 'getGoalsSessions':
            if ( qahm.goalsJson ) {
                qahm.goalsArray = JSON.parse( qahm.goalsJson );
            }

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
						// 重複するセッションを除外する関数定義
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
						allSessionAry = removeDuplicates(allSessionAry);

						for (let gid = 0; gid <= Object.keys(data).length; gid++) {
							if (gid === 0) {
								qahm.sessionAry[1] = allSessionAry;
							} else {
								qahm.sessionAry[gid+1] = data[gid];
							}
						}
					} else {
						if (qahm.goalsJson) {
							for (let gid = 0; gid <= Object.keys(qahm.goalsArray).length; gid++) {
								qahm.sessionAry[gid+1] = new Array();
							}
						}
					}
                }
            ).fail(
                function( jqXHR, textStatus, errorThrown ){
                    qahm.log_ajax_error( jqXHR, textStatus, errorThrown );
                    if ( qahm.goalsJson ) {
                        for ( let gid = 0; gid <= Object.keys(qahm.goalsArray).length; gid++ ) {
                            qahm.sessionAry[gid+1] = new Array();
                        }
                    }
                }
            ).always(
                function(){
                    qahm.nowAjaxStep = 'makeGoalsArray';
                    qahm.renderAudienceData(dateBetweenStr, dateYmdAry);
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
                qahm.goalsNrdArray = new Array();
				
                let uri        = new URL(window.location.href);
                let domain     = uri.host;

                for ( let gid = 0; gid < Object.keys(qahm.goalsArray).length; gid++ ) {

                    //make nrd array
                    let nrdary = [ 0,0,0,0,0,0 ];
                    for ( let sno = 0; sno < qahm.sessionAry[gid+1].length; sno++ ) {
                        let lp = qahm.sessionAry[gid+1][sno][0];
                        //nrd
                        if ( Number( lp['is_newuser'] ) ) {
                            switch ( Number( lp['device_id'] ) )  {
                                case 1:
                                    nrdary[0]++;
                                    break;

                                case 2:
                                    nrdary[1]++;
                                    break;

                                case 3:
                                    nrdary[2]++;
                                    break;
                            }
                        } else {
                            switch ( Number( lp['device_id'] ) )  {
                                case 1:
                                    nrdary[3]++;
                                    break;

                                case 2:
                                    nrdary[4]++;
                                    break;

                                case 3:
                                    nrdary[5]++;
                                    break;
                            }
                        }
					}
                    qahm.goalsNrdArray[gid] = nrdary;
                }
            }
            qahm.nowAjaxStep = 'getNrd';
            qahm.renderAudienceData(dateBetweenStr, dateYmdAry);
            break;


        case 'getNrd':
		    //new /repeat device table
            jQuery.ajax(
                {
                    type: 'POST',
                    url: qahm.ajax_url,
                    dataType : 'json',
                    data: {
                        'action' : 'qahm_ajax_get_nrd_data',
                        'date' : dateBetweenStr,
                        'nonce':qahm.nonce_api,
                        'tracking_id':qahm.tracking_id
                    }
                }
            ).done(
                function( data ){
                    let ary = data;
	                //if ( ary && Array.isArray( ary ) && ary.length > 0 ) {
					if ( ary && Array.isArray( ary ) && ary.length > 0 &&
					   ( ary[0][2] > 0 || ary[1][2] > 0 || ary[2][2] > 0 || ary[3][2] > 0 || ary[4][2] > 0 || ary[5][2] > 0 ) ) {
                        qahm.nrdArray = new Array;

                        if ( qahm.goalsJson ) {

                            //0:all のcv率と目標達成数はgid0に入っている全目標達成数を使える。但し目標値の合計は個別に計算が必要
                            for (let iii = 0; iii < ary.length; iii++) {
                                let valueall = 0;
                                let sessions = Number(ary[iii][4]);
                                for ( let gid = 1; gid < qahm.goalsNrdArray.length; gid++ ) {
                                    let gcount = Number(qahm.goalsNrdArray[gid][iii]);
                                    let cvrate = 0;
                                    if ( 0 < sessions ) {
                                        cvrate = qahm.roundToX( gcount / sessions  * 100, 2);
                                    }
                                    let valuex   = Number(qahm.goalsArray[gid]['gnum_value']) * gcount;
                                    valueall += valuex;
                                    if ( qahm.nrdArray[gid] === undefined ) {
                                        qahm.nrdArray[gid] = new Array();
                                    }
                                    qahm.nrdArray[gid][iii] = ary[iii].concat([ cvrate, gcount, valuex ]);
                                }
                                let gcountall = Number(qahm.goalsNrdArray[0][iii]);
                                let cvrateall = 0;
                                if ( 0 < sessions ) {
                                    cvrateall = qahm.roundToX( gcountall / sessions * 100, 2);
                                }
                                if ( qahm.nrdArray[0] === undefined ) {
                                    qahm.nrdArray[0] = new Array();
                                }
                                qahm.nrdArray[0][iii] = ary[iii].concat([ cvrateall, gcountall, valueall ]);
                            }

                        } else {
                            //全部0
                            qahm.nrdArray[0] = new Array();
                            for (let iii = 0; iii < ary.length; iii++) {
                                qahm.nrdArray[0][iii] = ary[iii].concat([ 0, 0, 0 ]);
                            }
                        }
                        //qahm.makeTable( audienceTable, qahm.nrdArray[0] );
						audienceTable.updateData(qahm.nrdArray[0]);
                    } else {
						audienceTable.updateData([]);
					}

					qahm.drawSessionsView( qahm.sessionAry[0] );
                }
            ).fail(
                function( jqXHR, textStatus, errorThrown ){
                    qahm.log_ajax_error( jqXHR, textStatus, errorThrown );
                }
            ).always(
                function(){
                    qahm.nowAjaxStep = 'getOverview';
					qahm.renderAudienceData(dateBetweenStr, dateYmdAry);
					//jQuery('#datepicker-base-textbox').prop('disabled', false);
					//qahm.enabledGoalRadioButton();
                }
            );
            break;

		case 'getOverview':
			// graph and total
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
                    let ary = data;

					// graph
					let overviewDataAry = {
						numPvs:[],
						numSessions:[],
						numUsers:[],
					};				
					let indexMarker = 0;				
					for ( let ddd = 0; ddd < dateYmdAry.length; ddd++ ) {
						let dataExists = false;
						for ( let iii = indexMarker; iii < ary.length; iii++ ) {
							if( ary[iii]['date'] == dateYmdAry[ddd] ) {
								overviewDataAry.numPvs.push( Number(ary[iii]['pv_count']) );
								overviewDataAry.numSessions.push( Number(ary[iii]['session_count']) );
								overviewDataAry.numUsers.push( Number(ary[iii]['user_count']) );
								indexMarker = iii;
								dataExists = true;
								break;
							}
						}
						if ( ! dataExists ) {
							overviewDataAry.numPvs.push( 0 );
							overviewDataAry.numSessions.push( 0 );
							overviewDataAry.numUsers.push( 0 );
						}
						
					}
					qahm.drawAudienceGraph(dateYmdAry, overviewDataAry, true);

					// total
					let totalOfArray = (accumulator, currentValue) => accumulator + currentValue;
					let overviewTotalNums = {
						numPvs : 0,
						numSessions : 0,
						numUsers : 0,
					};
					if ( dateYmdAry.length > 1 ) {
						overviewTotalNums = {
							numPvs :		overviewDataAry.numPvs.reduce(totalOfArray),
							numSessions : 	overviewDataAry.numSessions.reduce(totalOfArray),
							numUsers :		overviewDataAry.numUsers.reduce(totalOfArray),
						};
					} else {
						overviewTotalNums = {
							numPvs :		overviewDataAry.numPvs[0],
							numSessions : 	overviewDataAry.numSessions[0],
							numUsers :  	overviewDataAry.numUsers[0],
						};
					}
					let statsBlankAndValues = {
						'qa-zero-num-readers': overviewTotalNums.numUsers,
						'qa-zero-num-sessions': overviewTotalNums.numSessions,
						'qa-zero-num-pvs': overviewTotalNums.numPvs,
					};
					for ( let blankId in statsBlankAndValues ) {
						if ( isNaN(statsBlankAndValues[blankId]) ) {
							statsBlankAndValues[blankId] = '--';
						}
						let blankToFill = document.getElementById(blankId);
						if ( blankToFill ) {
							blankToFill.innerText = qahm.comma( statsBlankAndValues[blankId] );
						}
					}
					// csvダウンロードのために、numPvsをグローバル変数に保存
					qahm.reportNumPvs = overviewTotalNums.numPvs;
                }
            ).fail(
                function( jqXHR, textStatus, errorThrown ){
                    qahm.log_ajax_error( jqXHR, textStatus, errorThrown );
                }
            ).always(
                function(){
					qahm.nowAjaxStep = 0;
					jQuery('#datepicker-base-textbox').prop('disabled', false);
					qahm.enabledGoalRadioButton();
                }
            );
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



/** ---------------------------------------------------
 * drawing graph
 */
let statsChart1;
qahm.drawAudienceGraph = function( dateLabelsAry, dataAry, isClear = false ) {
	let ctx = document.getElementById("statsChart1");
	if ( ! ctx ) {
		return;
	}

	// ここでラベルの表示形式を yyyy/MM/dd に変更
	dateLabelsAry = dateLabelsAry.map(label => label.replace(/-/g, '/'));

	if ( isClear ) {
		qahm.clearPreChart(statsChart1);
		qahm.resetCanvas('statsChart1');
	}

	let chartOneColor = {
		users: { backgroundColor: qahm.graphColorBaseA[2], borderColor: qahm.graphColorBaseA[2] },
		sessions: { backgroundColor: qahm.graphColorBaseA[0], borderColor: qahm.graphColorBaseA[0]},
		pvs: { backgroundColor: qahm.graphColorBaseA[1], borderColor: qahm.graphColorBaseA[1]  },
	};

	let axisMax;
	let axisStepSize;
	let keepAxis = false;
	let linePointStyle = {};
	if ( dateLabelsAry.length <= 3 ) {
		linePointStyle = {
			borderWidth: 3,
			radius: 10,
			pointHoverRadius: 15,
		}
	} else if ( dateLabelsAry.length > 150 ) {
		linePointStyle = {
			borderWidth: 2,
			radius: 0,
			pointHoverRadius: 3,
		}
	} else if ( dateLabelsAry.length >= 85 ) {
		linePointStyle = {
			borderWidth: 2.5,
			radius: 2,
			pointHoverRadius: 3,
		}
	}else {
		linePointStyle = {
			borderWidth: 3,
			radius: 4,
			pointHoverRadius: 6,
		}
	}

	ctx = document.getElementById('statsChart1').getContext('2d');
	statsChart1 = new Chart(ctx, {
		type: 'bar',
		data: {
			labels: dateLabelsAry,
			datasets: [
				{
					type: 'line',
					label: qahml10n['graph_users'],
					data: dataAry.numUsers,
					backgroundColor: chartOneColor.users.backgroundColor,
					borderColor: chartOneColor.users.borderColor,
					borderJoinStyle: 'bevel',
					borderWidth: linePointStyle.borderWidth,
					borderDash: [10, 1, 2, 1],
					pointStyle: 'rectRot',
					radius: linePointStyle.radius,
					pointHoverRadius: linePointStyle.pointHoverRadius,
					lineTension: 0,
					fill: false,
					yAxisID: 'hidden-y-axis',
					order: 3
				},
				{
					type: 'line',
					label: qahml10n['graph_sessions'],
					data: dataAry.numSessions,
					backgroundColor: chartOneColor.sessions.backgroundColor,
					borderColor: chartOneColor.sessions.borderColor,
					borderJoinStyle: 'bevel',
					borderWidth: linePointStyle.borderWidth,
					pointStyle: 'rect',
					radius: linePointStyle.radius,
					pointHoverRadius: linePointStyle.pointHoverRadius,
					lineTension: 0,
					fill: false,
					yAxisID: 'hidden-y-axis',
					order: 4
				},
				{
					//type: 'bar',
					label: qahml10n['graph_pvs'],
					data: dataAry.numPvs,
					backgroundColor: chartOneColor.pvs.backgroundColor,
					borderColor: chartOneColor.pvs.borderColor,
					borderWidth: 2,
					maxBarThickness: 100,
					yAxisID: 'main-y-axis',
					order: 6
				}
			]
		},
		options: {
			spanGaps: false,
			responsive: true,
			maintainAspectRatio: false,
			title: {
				display: false,
				text: 'graph title',
				padding:3
			},
			scales: {
				yAxes: [{
					id: 'main-y-axis',
					position: 'left',
					type: 'linear',
					ticks: {
						min: 0,
					},
					beforeBuildTicks: function(axis) {
						if ( keepAxis ) {
							axis.max = axisMax;
							axis.stepSize = axisStepSize;
						} else {
							if( axis.max < 10 ) {
								axis.max = 10;
								axis.stepSize = 1;
							}
							axisMax = axis.max;
							axisStepSize = axis.stepSize;
							keepAxis = true;
						}
					},
				}, {
					id: 'hidden-y-axis',
					position: 'right',
   					type: 'linear',
					gridLines: {
						display: false
					},
					ticks: {
						min: 0,
						display: false
					},
					beforeBuildTicks: function(axis) {
						axis.max = axisMax;
						axis.stepSize = axisStepSize;
					}
				}],
				xAxes: [{
					stacked: true,
					ticks: {
						autoSkip: true,        // ラベルが多い場合は間引き
						maxRotation: 0,        // 最大でも回転させない
						minRotation: 0,        // 最小も0度（水平表示）
						maxTicksLimit: 10      // 最大でも10個まで表示
					}
				}]
			},
			legend: {
				display: false,
				position: 'top',
				labels: {
					usePointStyle: true
				}
			},
			legendCallback: function(chart) {
				let legendHtml = [];
				let labelDeco;
				legendHtml.push('<ul>');
				let dataSet = chart.data.datasets;
				for ( let lll = 0; lll < dataSet.length; lll++ ) {
					let meta = statsChart1.getDatasetMeta(lll);
					if( meta.hidden === true ){
						labelDeco = 'style="text-decoration:line-through;"'
					} else {
						labelDeco = '';
					}
					/*
					if( lll == 3 ) {
						legendHtml.push('</ul><ul>');
					}
					*/
					legendHtml.push('<li>');
					legendHtml.push('<div class="pt-'+dataSet[lll].pointStyle+'" style="background-color:' + dataSet[lll].backgroundColor +'; border-color:'+dataSet[lll].borderColor +'"></div>');
					legendHtml.push('<span class="legend-label"'+labelDeco +'>'+dataSet[lll].label+'</span>');
					legendHtml.push('</li>');
				}
				legendHtml.push('</ul>');
				return legendHtml.join("");
			},
			tooltips: {
				mode: 'index',
				itemSort: function(a, b, data) {
					return (a.datasetIndex - b.datasetIndex);
				},
				callbacks: {
					label: function(tooltipItem, data) {
						let label;
						//if ( tooltipItem.datasetIndex < 3 ) {
							label = data.datasets[tooltipItem.datasetIndex].label + ': ' + tooltipItem.yLabel;
						//} else {
						//	label = 'filter';
						//	label += data.datasets[tooltipItem.datasetIndex].label + ': ' + tooltipItem.yLabel;;
						//}
						return label;
					}
			}

			},
			/*
			onClick: function(e) {
				let element = statsChart1.getElementAtEvent(e);
				//console.log(element);
				if (! element || element.length === 0) return;
				let meIndex = element[0]._index;
				let meLabelDate = dateLabelsAry[meIndex];
				console.log('clicked date:', meLabelDate);
			},
			*/
		}
	});

	// legendHere
	document.getElementById('chart1-legend').innerHTML = statsChart1.generateLegend();

	let legendItems;
	legendItems = document.getElementById('chart1-legend').getElementsByTagName('li');
	for (let i = 0; i < legendItems.length; i++) {
		legendItems[i].addEventListener("click", (e) =>
			updateDataset(e.target.parentNode, i)
		);
	}
	//to switch dataset when the legend clicked
	let updateDataset = (legendLi, index) => {
		let meta = statsChart1.getDatasetMeta(index);
		let labelSpan = legendLi.querySelector('span.legend-label');
		let hiddenData = meta.hidden === true ? true : false;
		if (hiddenData) {
			labelSpan.style.textDecoration = "none";
			meta.hidden = null;
		} else {
			labelSpan.style.textDecoration = "line-through";
			meta.hidden = true;
		}
		statsChart1.update();
	};

}; //end of "drawAudienceGraph" function


/**
 *  ---------------------------------------------------
 * 過去データCSVダウンロード
 * 今後ダウンロードファイルの種類が増えるようなら引数を増やして対応
 */
qahm.downloadAudienceCsv = function( fromDate, toDate, reportNumPvs ) {

	//jsonをtsv/csv文字列に編集する関数
	// let qaHeaderTerms = [ 'pv_id', 'reader_id', 'UAos', 'UAbrowser', 'country', 'page_id', 'url', 'title', 'device_id', 'source_id', 'utm_source', 'source_domain', 'medium_id', 'utm_medium', 'campaign_id', 'utm_campaign', 'session_no', 'access_time', 'pv', 'speed_msec', 'browse_sec', 'is_last', 'is_newuser', 'version_id', 'is_raw_p', 'is_raw_c', 'is_raw_e' ];
	let qaHeaderTerms = [ 'reader_id', 'UAos', 'UAbrowser', 'url', 'title', 'device_id', 'utm_source', 'source_domain', 'utm_medium', 'utm_campaign', 'session_no', 'access_time', 'pv', 'speed_msec', 'browse_sec', 'is_last', 'is_newuser'];
	let jsonToTCsv = function(json, delimiter) {
		let header = qaHeaderTerms.join(delimiter) + "\n";
		let body = json.map(function(d){
			 return qaHeaderTerms.map(function(key) {
				switch (key) {
					case 'device_id':
						switch (d[key]) {
							case '1':
								return 'desktop';
							case '2':
								return 'tablet';
							case '3':
								return 'mobile';
							default:
								return '';
						}
					case 'access_time':
						if (d[key]) {
							// UNIXタイムをDateオブジェクトに変換し、フォーマットする
							const date = new Date(d[key] * 1000);
							const formattedDate = date.getFullYear() + '-' +
								('0' + (date.getMonth() + 1)).slice(-2) + '-' +
								('0' + date.getDate()).slice(-2) + ' ' +
								('0' + date.getHours()).slice(-2) + ':' +
								('0' + date.getMinutes()).slice(-2) + ':' +
								('0' + date.getSeconds()).slice(-2);
							return formattedDate;
						} else {
							return '';
						}
					default:
						return d[key] ? d[key] : '';
				}
			 }).join(delimiter);
		}).join("\n");
		return header + body;
	}
	//UTF8
	let bom = new Uint8Array([0xEF, 0xBB, 0xBF]);


	// データ数によって、ファイル期間を決定
	let dataBetween = [];
	let fromDateStr = moment(fromDate).format('YYYY-MM-DD');
	let toDateStr = moment(toDate).format('YYYY-MM-DD');

	if ( reportNumPvs < 300000 ) {
		dataBetween.push( [fromDateStr, toDateStr] );
	} else {
		let gessuu = toDate.getMonth() - fromDate.getMonth();
		if ( gessuu > 0 ) {
			//月ごとに区切る
			for ( let mmm = 0; mmm <= gessuu; mmm++ ) {
				if( mmm === 0 ) {
					dataBetween.push( [fromDateStr, moment(fromDate).endOf('month').format('YYYY-MM-DD')] );
				} else if ( mmm === gessuu ) {
					dataBetween.push( [moment(toDate).startOf('month').format('YYYY-MM-DD'), toDateStr] );
				} else {
					dataBetween.push( [moment(fromDate).add( mmm, 'months').startOf('month').format('YYYY-MM-DD'), moment(fromDate).add( mmm, 'months').endOf('month').format('YYYY-MM-DD')] );
				}
			}
		} else {
			//（一応　エンタープライズになっているはず。）
			dataBetween.push( [fromDateStr, toDateStr] );
			/*
			//半分に区切ってみる？
			let nissuu = toDate.getDate() - fromeDate.getDate();
			let hanbun = Math.floor( nissuu / 2 );
			dataBetween.push( [fromDateStr, moment(fromDate).add(hanbun, 'days').format('YYYY-MM-DD')] );
			dataBetween.push( [moment(fromDate).add(hanbun+1, 'days').format('YYYY-MM-DD'), toDateStr] );
			*/
		}
	}


	//データ取得とファイルダウンロード
	jQuery(
		function(){
			let noDataMsg = '';
			let nnn = 0;

			let getAndDownloadCsvData = function() {
				let deferredDl     = new jQuery.Deferred;
				qahm.statsCsvParam = null;
				let url            = new URL(window.location.href);
    			let params         = url.searchParams;
			    let tracking_id    = params.get('tracking_id');

				if ( nnn < dataBetween.length ) {
					jQuery.ajax(
						{
							type: 'POST',
							url: qahm.ajax_url,
							dataType : 'json',
							data: {
								'action' : 'qahm_ajax_select_data',
								'table' : 'view_pv',
								'select': '*',
								'date_or_id':`date = between ${dataBetween[nnn][0]} and ${dataBetween[nnn][1]}`,
								'count' : false,
								'nonce':qahm.nonce_api,
                                'tracking_id' : tracking_id
							}
						}
					).done(
						function( data ){
							qahm.statsCsvParam = data;

							let csvParam = qahm.statsCsvParam.flat(1);
							if ( csvParam.length > 0 ) {
								let fileName = 'QA_Data_' + moment(dataBetween[nnn][0], 'YYYY-MM-DD').format('YYYYMMDD') + '-' + moment(dataBetween[nnn][1], 'YYYY-MM-DD').format('YYYYMMDD');
								let csvData = jsonToTCsv( csvParam, '\t');
//								let csvData = jsonToTCsv( csvParam, ',');

								//出力ファイル名
								let exportedFilename = (fileName || 'export') + '.tsv';
//								let exportedFilename = (fileName || 'export') + '.csv';
								//BLOBに変換
								let blob = new Blob([ bom, csvData ], { 'type' : 'text/tsv' });
//								let blob = new Blob([ bom, csvData ], { 'type' : 'text/csv' });

								let downloadLink = document.createElement('a');
								downloadLink.download = exportedFilename;
								downloadLink.href = URL.createObjectURL(blob);
								downloadLink.dataset.downloadurl = ['text/plain', downloadLink.download, downloadLink.href].join(':'); //いる？いらない？　HTML要素に追加されたカスタム属性のデータを表す。これらの属性はカスタムデータ属性と呼ばれており、 HTML とその DOM 表現との間で、固有の情報を交換できるようにします。すべてのカスタムデータは、その属性を設定した要素の HTMLElement インターフェイスを通して使用することができます。 HTMLElement.dataset プロパティでカスタムデータにアクセスできます。
								downloadLink.click();

								URL.revokeObjectURL(downloadLink.href);

							} else {
								noDataMsg += qahm.sprintfAry( qahml10n['download_done_nodata'], dataBetween[nnn][0], dataBetween[nnn][1] );
							}

							nnn++;
							getAndDownloadCsvData();
						}
					).fail(
						function( jqXHR, textStatus, errorThrown ){
							qahm.log_ajax_error( jqXHR, textStatus, errorThrown );
							deferredDl.reject();
						}
					).always(
						function(){
						}
					);

				} else {
					deferredDl.resolve();
				}
				return deferredDl.promise();
			}; //end of 'getAndDownloadCsvData' definition

			//実行
			getAndDownloadCsvData().then(
			function(){
				if ( noDataMsg ) {
					alert(noDataMsg);
				}
			}, function(e){
				alert( qahml10n['download_error1'] + '\n' + qahml10n['download_error2'] );
			}
			);
		}
	)
};


// 以下元datasearch.js
qahm.drawSessionsView = function ( sessionary ) {
	// 全てのセッションの文字列を最新1万セッションに書き換え
	if ( qahm.sessionAry[0].length >= 10000 ) {
		jQuery('#all_session').text( qahml10n['switch_txt_all_session2'] );
	} else {
		jQuery('#all_session').text( qahml10n['switch_txt_all_session1'] );
	}

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

	let allSessionAry = qahm.createSessionArray( sessionary );
	sesRecTable.updateData(allSessionAry);
};



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
	let sec_on_site = 0;
	let firstAccessTime = null;

	if ( vr_view_ary[0] ) { //ym wrote
		for ( let iii = 0; iii < vr_view_ary.length; iii++ ) {
			//sessin start?
			for ( let jjj = 0; jjj < vr_view_ary[iii].length; jjj++ ) {
				if (Number(vr_view_ary[iii][jjj].pv) === 1) {
					is_session = true;
					firstTitle = vr_view_ary[iii][jjj].title;
					firstTitleEl = firstTitle.slice(0, 80) + '...';
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

						//この時点でaccess_timeがnullなら行動データは存在しない。その場合、この行は追加しない
						if ( ! firstAccessTime ) {
							continue;
						}

						lastTitle = vr_view_ary[iii][jjj].title;
						lastTitleEl = lastTitle.slice(0, 80) + '...';
						lastUrl = vr_view_ary[iii][jjj].url;
						/*const accessDate = new Date( vr_view_ary[iii][jjj].access_time * 1000 );
						const year       = accessDate.getFullYear().toString().padStart(4, '0');
						const month      = (accessDate.getMonth() + 1).toString().padStart(2, '0');
						const day        = accessDate.getDate().toString().padStart(2, '0');
						last_exit_time   = year + '-' + month + '-' + day;
						*/
						last_exit_time   = vr_view_ary[iii][jjj].access_time;
						source_domain = vr_view_ary[iii][jjj].source_domain;

						//make array
						let sessionAry = [device, last_exit_time, reader_id, firstUrl, firstTitleEl, lastUrl, lastTitleEl, source_domain, pvcnt, sec_on_site, firstAccessTime, ''];
						allSessionAry.push(sessionAry);
					}
				}
			}
		}
	} //endif(ym wrote)
	return allSessionAry;
}

