var qahm = qahm || {};

if ( typeof qahm.tracking_id === 'undefined' ) {
	let url         = new URL(window.location.href);
	let params      = url.searchParams;
	qahm.tracking_id = params.get('tracking_id');
}

window.addEventListener('DOMContentLoaded', function() {
	qahm.initDateSetting();

	//create channel table
	channelHeader = [
		{ key: 'graph', label: qahml10n['table_graph'], type: 'check', width: 7, typeOptions: { maxSelections: 5 }, exportable: false, filtering: false },
		{ key: 'channel', label: qahml10n['table_channel'], width: 12 },
		{ key: 'user', label: qahml10n['table_user'], width: 9, type: 'integer' },
		{ key: 'new_user', label: qahml10n['table_new_user'], width: 9, type: 'integer' },
		{ key: 'session', label: qahml10n['table_session'], width: 9, type: 'integer' },
		{ key: 'bounce_rate', label: qahml10n['table_bounce_rate'], width: 9, type: 'percentage' },
		{ key: 'page_session', label: qahml10n['table_page_session'], width: 9, type: 'float', agg: { type: 'wavg', weightKey: 'session' } }, // Issue #1175: ページ/セッションは session 加重平均
		{ key: 'avg_session_time', label: qahml10n['table_avg_session_time'], width: 9, type: 'duration' },
		{ key: 'goal_conversion_rate', label: qahml10n['table_goal_conversion_rate'], width: 9, type: 'percentage' },
		{ key: 'goal_completions', label: qahml10n['table_goal_completions'], width: 9, type: 'integer' },
		{ key: 'goal_value', label: qahml10n['table_goal_value'], width: 9, type: 'integer' },
	];
	channelOptions = {
		perPage: 100,
		pagination: true,
		exportable: true,
		sortable: true,
        filtering: true,
		columnToggle: true,
		maxHeight: 600,
		stickyHeader: true,
		initialSort: {
			column: 'user',
			direction: 'desc'
		},
	};
	channelTable = qaTable.createTable('#tb_channels', channelHeader, { ...channelOptions, totalRow: { weightKey: 'session', label: qahml10n['table_total'], selectable: true, checkedByDefault: true } }); // Issue #1175: 合計行（ソート対象の1行・率は session 加重平均・graph チェックで全体推移）

    let chradios = document.getElementsByName( `js_chGoals` );
    for ( let jjj = 0; jjj < chradios.length; jjj++ ) {
        chradios[jjj].addEventListener( 'click', qahm.changeChGoal );
    }


	//create source/media table
	sourceMediumHeader = [
		{ key: 'graph', label: qahml10n['table_graph'], type: 'check', width: 7, typeOptions: { maxSelections: 5 }, exportable: false, filtering: false },
		{ key: 'referrer', label: qahml10n['table_referrer'], width: 11, formatter: function(value, row) {
			if ( value !== 'direct' && value !== qahml10n['table_total'] ) {
				ret = `<a href="//${value}" target="_blank" rel="noopener">${value}</a>`;
			} else {
				ret = value;
			}
			return ret;
    	} },
		{ key: 'media', label: qahml10n['table_media'], width: 10, },
		{ key: 'user', label: qahml10n['table_user'], width: 8, type: 'integer' },
		{ key: 'new_user', label: qahml10n['table_new_user'], width: 8, type: 'integer' },
		{ key: 'session', label: qahml10n['table_session'], width: 8, type: 'integer' },
		{ key: 'bounce_rate', label: qahml10n['table_bounce_rate'], width: 8, type: 'percentage' },
		{ key: 'page_session', label: qahml10n['table_page_session'], width: 8, type: 'float', agg: { type: 'wavg', weightKey: 'session' } }, // Issue #1175: ページ/セッションは session 加重平均
		{ key: 'avg_session_time', label: qahml10n['table_avg_session_time'], width: 8, type: 'duration' },
		{ key: 'goal_conversion_rate', label: qahml10n['table_goal_conversion_rate'], width: 8, type: 'percentage' },
		{ key: 'goal_completions', label: qahml10n['table_goal_completions'], width: 8, type: 'integer' },
		{ key: 'goal_value', label: qahml10n['table_goal_value'], width: 8, type: 'integer' },
	];
	sourceMediumOptions = {
		perPage: 100,
		pagination: true,
		exportable: true,
		sortable: true,
        filtering: true,
		columnToggle: true,
		maxHeight: 600,
		stickyHeader: true,
		initialSort: {
			column: 'user',
			direction: 'desc'
		},
	};
	sourceMediumTable = qaTable.createTable('#tb_sourceMedium', sourceMediumHeader, { ...sourceMediumOptions, totalRow: { weightKey: 'session', label: qahml10n['table_total'], selectable: true, checkedByDefault: true, values: { media: qahml10n['table_total'] } } }); // Issue #1175: 合計行（率は session 加重平均・media にも「合計」を入れグラフ複合名「合計｜合計」を成立）

    let smradios = document.getElementsByName( `js_smGoals` );
    for ( let jjj = 0; jjj < smradios.length; jjj++ ) {
        smradios[jjj].addEventListener( 'click', qahm.changeSmGoal );
    }

});
qahm.changeChGoal = function(e) {
    let checkedId = e.target.id;
    let idsplit   = checkedId.split('_');
    let gid       = Number( idsplit[2] );
	channelTable.showLoading();

	// チェック状況を他のテーブルの値に更新する
	let checkedArray = channelTable.getCheckedData('graph');

	// 「合計」行のチェックボックスだけtrueにする
	for (let goalIdx = 0; goalIdx < qahm.chArray.length; goalIdx++) {
		for (let chIdx = 0; chIdx < qahm.chArray[goalIdx].length; chIdx++) {
			qahm.chArray[goalIdx][chIdx][0] = false;
			for (let checkedIdx = 0; checkedIdx < checkedArray.length; checkedIdx++) {
				if (qahm.chArray[goalIdx][chIdx][1] === checkedArray[checkedIdx][1]) {
					qahm.chArray[goalIdx][chIdx][0] = true;
				}
			}
		}
	}

	channelTable.updateData(qahm.chArray[gid].slice(1)); // Issue #1175: 先頭の合計行を除外
};
qahm.changeSmGoal = function(e) {
    let checkedId = e.target.id;
    let idsplit   = checkedId.split('_');
    let gid       = Number( idsplit[2] );
	sourceMediumTable.showLoading();

	// チェック状況を他のテーブルの値に更新する
	let checkedArray = sourceMediumTable.getCheckedData('graph');

	// 「合計」行のチェックボックスだけtrueにする
	for (let goalIdx = 0; goalIdx < qahm.smArray.length; goalIdx++) {
		for (let chIdx = 0; chIdx < qahm.smArray[goalIdx].length; chIdx++) {
			qahm.smArray[goalIdx][chIdx][0] = false;
			for (let checkedIdx = 0; checkedIdx < checkedArray.length; checkedIdx++) {
				if (qahm.smArray[goalIdx][chIdx][1] === checkedArray[checkedIdx][1]) {
					qahm.smArray[goalIdx][chIdx][0] = true;
				}
			}
		}
	}

	sourceMediumTable.updateData(qahm.smArray[gid].slice(1)); // Issue #1175: 先頭の合計行を除外
};


/** -------------------------------------
 * データの取得と描画・表示
 */
jQuery(
	function() {
		qahm.nowAjaxStep = 0;
		qahm.renderAcquisitionData(reportDateBetween);
		qahm.setDateRangePicker();

    }
);

// カレンダー期間変更時の処理
jQuery(document).on('qahm:dateRangeChanged', function( RangeStart, RangeEnd ) {
	qahm.nowAjaxStep = 0;
	qahm.renderAcquisitionData(reportDateBetween);
	// [Issue #1231] チャネル別 / SNS 別テーブルとも All Goals (gid=0) で再描画されるため、UI のラジオも同期させる
	let chAllGoalsRadio = document.getElementById( 'js_chGoals_0' );
	if ( chAllGoalsRadio ) {
		chAllGoalsRadio.checked = true;
	}
	let smAllGoalsRadio = document.getElementById( 'js_smGoals_0' );
	if ( smAllGoalsRadio ) {
		smAllGoalsRadio.checked = true;
	}
});


// 重複するセッションを除外する関数定義
qahm.removeDuplicateSessions = function(arr) {
	const seen = new Map();

	return arr.filter(subArray => {
	  const key = subArray.map(obj => JSON.stringify(obj)).join('|');
	  if (seen.has(key)) {
	    return false;
	  }
	  seen.set(key, true);
	  return true;
	});
};

qahm.renderAcquisitionData = function(dateBetweenStr) {
    switch (qahm.nowAjaxStep) {
        case 0:
			jQuery('#datepicker-base-textbox').prop('disabled', true);
			qahm.disabledGoalRadioButton();
			channelTable.showLoading();
			sourceMediumTable.showLoading();
			// グラフのローディングも開始時に出す（#1294）。ch/sm チャートの create は後段の
			// getCh/getSm ステップなので、ここで出さないと先行 ajax 中はグラフ領域が空白になる。
			// 各 create が自動で消す
			qahm.EChart.loading( 'ch-chart' );
			qahm.EChart.loading( 'sm-chart' );

            qahm.nowAjaxStep = 'getGoals';
            qahm.renderAcquisitionData(dateBetweenStr);
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
						let ary = new Array();
						for (let gid = 1; gid <= Object.keys(data).length; gid++) {
							ary = ary.concat(data[gid]);
						}

						// 重複するセッションを除外
						ary = qahm.removeDuplicateSessions(ary);

						for (let gid = 0; gid <= Object.keys(data).length; gid++) {
							if (gid === 0) {
								qahm.goalsSessionData[0] = ary;
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
                    qahm.renderAcquisitionData(dateBetweenStr);
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
                qahm.goalsChArray  = new Array();
                qahm.goalsSmArray  = new Array();
                qahm.goalsLpArray  = new Array();
                qahm.goalsApArray  = new Array();

                //default channel group
                let paidsearch = new RegExp('^(cpc|ppc|paidsearch)$');
                let display    = new RegExp('(display|cpm|banner)$');
                let otheradv   = new RegExp('^(cpv|cpa|cpp|content-text)$');
                let social     = new RegExp('^(social|social-network|social-media|sm|social network|social media)$');

                let uri        = new URL(window.location.href);
                let domain     = uri.host;

                for ( let gid = 0; gid < Object.keys(qahm.goalsArray).length; gid++ ) {

                    //make nrd array (nrd = new/repeat device)
                    let ch_ary = {[qahml10n['table_total']]:0,'Direct':0,'Organic Search':0,'Referral':0,'Social':0,'Display':0,'Email':0,'Affiliates':0,'Paid Search':0,'Other Advertising':0,'Other':0 };
                    let sm_ary = new Array();
					sm_ary[qahml10n['table_total']] = new Array();
					sm_ary[qahml10n['table_total']][qahml10n['table_total']] = 0;
                    for ( let sno = 0; sno < qahm.goalsSessionData[gid].length; sno++ ) {
                        let lp = qahm.goalsSessionData[gid][sno][0];

						let source  = lp['source_domain'];
						if ( ! source ) {
							source = 'direct';
						}
						// PHP get_goals_sessions で utm_medium は補完済み（JS側フォールバック不要）
						let medium  = lp['utm_medium'];

                        //channel
						ch_ary[qahml10n['table_total']]++;
						switch ( medium ) {
							case '(none)':
							case 'direct':
								ch_ary['Direct']++;
								break;

							case 'organic':
								ch_ary["Organic Search"]++;
								break;

							case 'referral':
								ch_ary["Referral"]++;
								break;

							case 'social':
								ch_ary["Social"]++;
								break;

							case 'display':
								ch_ary["Display"]++;
								break;

							case 'email':
								ch_ary["Email"]++;
								break;

							case 'affiliate':
								ch_ary["Affiliates"]++;
								break;

							case 'cpc':
								ch_ary["Paid Search"]++;
								break;

							case 'cpv':
								ch_ary["Other Advertising"]++;
								break;

							default:
								if ( lp['utm_medium'].match( paidsearch ) ){
									ch_ary['Paid Search']++;
								} else if ( lp['utm_medium'].match( display ) ){
									ch_ary['Display']++;
								} else if ( lp['utm_medium'].match( social ) ){
									ch_ary['Social']++;
								} else if ( lp['utm_medium'].match( otheradv ) ){
									ch_ary["Other Advertising"]++;
								} else {
									ch_ary['Other']++;
								}
								break;
						}
						//sm
						sm_ary[qahml10n['table_total']][qahml10n['table_total']]++;
						if ( sm_ary[source] !== undefined ) {
							if (sm_ary[source][medium] !== undefined) {
								sm_ary[source][medium]++;
							} else {
								sm_ary[source][medium] = 1;
							}
						} else {
							sm_ary[source] = new Array();
							sm_ary[source][medium] = 1;
						}
                    }
                    qahm.goalsChArray[gid]  = ch_ary;
                    qahm.goalsSmArray[gid]  = sm_ary;
                }
            }
            qahm.nowAjaxStep = 'getCh';
            qahm.renderAcquisitionData(dateBetweenStr);
            break;

        case 'getCh':
			//channel table
			jQuery.ajax(
				{
					type: 'POST',
					url: qahm.ajax_url,
					dataType : 'json',
					data: {
						'action' : 'qahm_ajax_get_ch_data',
						'date' : dateBetweenStr,
						'nonce':qahm.nonce_api,
                        'tracking_id':qahm.tracking_id
					}
				}
			).done(
				function( data ){
					let ary = data;
					if ( ary && Array.isArray( ary ) && ary.length > 0 && ary[0][1] > 0 ) {
					//if ( ary && Array.isArray( ary ) && ary.length > 0 ) {
                        qahm.chArray = new Array;
						for (let iii = 0; iii < ary.length; iii++) {
							ary[iii][7] = ary[iii][6];
							ary[iii][6] = ary[iii][5];
							ary[iii][5] = ary[iii][4];
							ary[iii][4] = ary[iii][3];
							ary[iii][3] = ary[iii][2];
							ary[iii][2] = ary[iii][1];
							ary[iii][1] = ary[iii][0];
							ary[iii][0] = false;
						}

                        if ( qahm.goalsJson ) {

                            //0:all のcv率と目標達成数はgid0に入っている全目標達成数を使える。但し目標値の合計は個別に計算が必要
                            for (let iii = 0; iii < ary.length; iii++) {
                                let valueall = 0;
                                let chkey    = ary[iii][0+1];
                                let sessions = Number(ary[iii][3+1]);
                                for ( let gid = 1; gid < qahm.goalsChArray.length; gid++ ) {
                                    let gcount = qahm.goalsChArray[gid][chkey];
                                    let cvrate = 0;
                                    if ( 0 < sessions ) {
                                        cvrate = qahm.roundToX( gcount / sessions  * 100, 2);
                                    }
                                    let valuex   = qahm.goalsArray[gid]['gnum_value'] * gcount;
                                    valueall += valuex;
                                    if ( qahm.chArray[gid] === undefined ) {
                                        qahm.chArray[gid] = new Array();
                                    }
                                    qahm.chArray[gid][iii] = ary[iii].concat([ cvrate, gcount, valuex ]);
                                }
                                let gcountall = qahm.goalsChArray[0][chkey];
                                let cvrateall = 0;
                                if ( 0 < sessions ) {
                                    cvrateall = qahm.roundToX( gcountall / sessions * 100, 2);
                                }
                                if ( qahm.chArray[0] === undefined ) {
                                    qahm.chArray[0] = new Array();
                                }
                                qahm.chArray[0][iii] = ary[iii].concat([ cvrateall, gcountall, valueall ]);
                            }

                        } else {
                            //全部0
                            qahm.chArray[0] = new Array();
                            for (let iii = 0; iii < ary.length; iii++) {
                                qahm.chArray[0][iii] = ary[iii].concat([ 0, 0, 0 ]);
                            }
                        }

						// Issue #1175: サーバー先頭の合計行(index 0)を除外して渡す（合計は qaTable が計算で生成）
						channelTable.updateData(qahm.chArray[0].slice(1));
					} else {
						channelTable.updateData([]);
					}
					qahm.checkedDataToDrawGraph( 'ch' );
				}
			).fail(
				function( jqXHR, textStatus, errorThrown ){
					qahm.log_ajax_error( jqXHR, textStatus, errorThrown );
				}
			).always(
				function(){
                    qahm.nowAjaxStep = 'getSm';
                    qahm.renderAcquisitionData(dateBetweenStr);
				}
			);
			break;

        case 'getSm':
			//source media table
			jQuery.ajax(
				{
					type: 'POST',
					url: qahm.ajax_url,
					dataType : 'json',
					data: {
						'action' : 'qahm_ajax_get_sm_data',
						'date' : dateBetweenStr,
						'nonce':qahm.nonce_api,
                        'tracking_id':qahm.tracking_id
					}
				}
			).done(
				function( data ){
					let ary = data;
					if ( ary && Array.isArray( ary ) && ary.length > 0 && ary[0][2] > 0 ) {
					//if ( ary && Array.isArray( ary ) && ary.length > 0 ) {
                        qahm.smArray = new Array;
						for (let iii = 0; iii < ary.length; iii++) {
							ary[iii][8] = ary[iii][7];
							ary[iii][7] = ary[iii][6];
							ary[iii][6] = ary[iii][5];
							ary[iii][5] = ary[iii][4];
							ary[iii][4] = ary[iii][3];
							ary[iii][3] = ary[iii][2];
							ary[iii][2] = ary[iii][1];
							ary[iii][1] = ary[iii][0];
							ary[iii][0] = false;
						}

                        if ( qahm.goalsJson ) {

                            //0:all のcv率と目標達成数はgid0に入っている全目標達成数を使える。但し目標値の合計は個別に計算が必要
                            for (let iii = 0; iii < ary.length; iii++) {
                                let source = ary[iii][1];
                                let media  = ary[iii][2];
                                let valueall = 0;
                                let sessions = Number(ary[iii][5]);
                                for ( let gid = 1; gid < qahm.goalsSmArray.length; gid++ ) {
                                    if (qahm.smArray[gid] === undefined) {
                                        qahm.smArray[gid] = new Array();
                                    }
                                    if ( qahm.goalsSmArray[gid][source] !== undefined) {
                                        if (qahm.goalsSmArray[gid][source][media] !== undefined) {
                                            let gcount = Number(qahm.goalsSmArray[gid][source][media]);
                                            let cvrate = 0;
                                            if (0 < sessions) {
                                                cvrate = qahm.roundToX(gcount / sessions * 100, 2);
                                            }
                                            let valuex = Number(qahm.goalsArray[gid]['gnum_value']) * gcount;
                                            valueall += valuex;
                                            qahm.smArray[gid][iii] = ary[iii].concat([cvrate, gcount, valuex]);
                                        } else {
                                            qahm.smArray[gid][iii] = ary[iii].concat([0, 0, 0]);
                                        }
                                    } else {
                                        qahm.smArray[gid][iii] = ary[iii].concat([0, 0, 0]);
                                    }
                                }
                                let gcountall = 0;
                                if ( qahm.goalsSmArray[0][source] !== undefined ) {
                                    if ( qahm.goalsSmArray[0][source][media] !== undefined ) {
                                        gcountall = qahm.goalsSmArray[0][source][media] ;
                                    }
                                }
                                let cvrateall = 0;
                                if ( 0 < sessions ) {
                                    cvrateall = qahm.roundToX( gcountall / sessions * 100, 2);
                                }
                                if ( qahm.smArray[0] === undefined ) {
                                    qahm.smArray[0] = new Array();
                                }
                                qahm.smArray[0][iii] = ary[iii].concat([ cvrateall, gcountall, valueall ]);
                            }
                        }
                        else {
                            //全部0
                            qahm.smArray[0] = new Array();
                            for (let iii = 0; iii < ary.length; iii++) {
                                qahm.smArray[0][iii] = ary[iii].concat([ 0, 0, 0 ]);
                            }
                        }
						
						// Issue #1175: サーバー先頭の合計行(index 0)を除外して渡す（合計は qaTable が計算で生成）
						sourceMediumTable.updateData(qahm.smArray[0].slice(1));
					} else {
						sourceMediumTable.updateData([]);
					}
					qahm.checkedDataToDrawGraph( 'sm' );
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

// グラフに表示
jQuery( document ).on( 'click',	'#ch-chart-button', function(){
	qahm.checkedDataToDrawGraph('ch');
});
jQuery( document ).on( 'click',	'#sm-chart-button', function(){
	qahm.checkedDataToDrawGraph('sm');
});

// reportDateBetween, dateRangeYmdAry, reportRangeStart, reportRangeEnd を使う↓（が、引数には入れていない）
qahm.checkedDataToDrawGraph = function( type ) {
	let action   = 'qahm_ajax_get_' + type + '_days_data';
	let chartId  = ( 'ch' === type ) ? 'ch-chart' : 'sm-chart';
	let nameAry  = [];
	let checkAry = null;
	qahm.EChart.loading( chartId ); // データ取得中の表情（#1294。create が自動で消す）
	switch ( type ) {
		case 'ch':
			checkAry = channelTable.getCheckedData('graph');
			break;
		case 'sm':
			checkAry = sourceMediumTable.getCheckedData('graph');
			break;
	}
	if ( checkAry.length === 0 ) {
		nameAry.push( '' );
	} else {
		for( let checkIdx = 0; checkIdx < checkAry.length; checkIdx++ ){
			switch ( type ) {
				case 'ch':
					nameAry.push( checkAry[checkIdx][1] );
					break;
				case 'sm':
					nameAry.push( checkAry[checkIdx][1] + ' | ' + checkAry[checkIdx][2] );
					break;
			}
		}
	}

	jQuery.ajax(
		{
			type: 'POST',
			url: qahm.ajax_url,
			dataType : 'json',
			data: {
				'action' : action,
				'date': reportDateBetween,
				'name_ary': JSON.stringify( nameAry ),
				'tracking_id': qahm.tracking_id,
				'nonce': qahm.nonce_api,
			}
		}
	).done(
		function( dataAry ){
			// 各チャネルの日付ごとのデータを作成（#1280: qahm.EChart ラッパ経由・色はテーマパレット）
			let graphSeries = [];
			for( let nameIdx = 0; nameIdx < nameAry.length; nameIdx++ ){
				let sessionAry = [];
				const name = nameAry[nameIdx];
				for ( let dateIdx = 0; dateIdx < dateRangeYmdAry.length; dateIdx++ ) {
					const date = dateRangeYmdAry[dateIdx];
					if ( date in dataAry && name in dataAry[date] ) {
						sessionAry.push( dataAry[date][name] );
					} else {
						sessionAry.push( 0 );
					}

				}
				graphSeries.push( {
					name: name,
					type: 'line',
					data: sessionAry,
				} );
			}

			let labelsDates = qahm.makeFormattedDatesArray( reportRangeStart, reportRangeEnd, 'MM/DD' );
			// 冪等 create: 2回目以降は同インスタンスに notMerge 全置換（系列数の増減も残骸なく反映される）
			qahm.EChart.create( chartId, {
				kind: 'line',
				labels: labelsDates,
				series: graphSeries,
				legend: true,
				maxXTicks: 10,
			} );
		}
	).fail(
		function( jqXHR, textStatus, errorThrown ){
			qahm.log_ajax_error( jqXHR, textStatus, errorThrown );
			qahm.EChart.loading( chartId, false );
			qahm.nowAjaxStep = 'error';
			alert( 'error' );
		}
	);
};
