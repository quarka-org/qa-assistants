var qahm = qahm || {};

if ( typeof qahm.tracking_id === 'undefined' ) {
	let url         = new URL(window.location.href);
	let params      = url.searchParams;
	qahm.tracking_id = params.get('tracking_id');
}

window.addEventListener('DOMContentLoaded', function() {
	qahm.initDateSetting();

	// QA Tableの初期化
	landingpageHeader = [
		{ key: 'page_id', hidden: true },
		{ key: 'title', label: qahml10n['table_title'], width: 15 },
		{ key: 'url', label: qahml10n['table_url'], width: 15, type: 'link' },
		{ key: 'session', label: qahml10n['table_session'], width: 7, type: 'integer' },
		{ key: 'new_session_rate', label: qahml10n['table_new_session_rate'], width: 7, type: 'percentage' },
		{ key: 'new_user', label: qahml10n['table_new_user'], width: 7, type: 'integer' },
		{ key: 'bounce_rate', label: qahml10n['table_bounce_rate'], width: 7, type: 'percentage' },
		{ key: 'page_session', label: qahml10n['table_page_session'], width: 7, type: 'float' },
		{ key: 'avg_session_time', label: qahml10n['table_avg_session_time'], width: 7, type: 'duration' },
		{ key: 'goal_conversion_rate', label: qahml10n['table_goal_conversion_rate'], width: 7, type: 'percentage' },
		{ key: 'goal_completions', label: qahml10n['table_goal_completions'], width: 7, type: 'integer' },
		{ key: 'goal_value', label: qahml10n['table_goal_value'], width: 7, type: 'integer' },
		{ key: 'heatmap', label: qahml10n['table_heatmap'], width: 7, sortable: false, exportable: false, filtering: false, formatter: function(value, row) {
			return `<div class="qa-table-heatmap-container">
					<span class="dashicons dashicons-desktop" data-device_name="dsk" data-page_id="${row.page_id}" data-is_landing_page="1"></span>
					<span class="dashicons dashicons-tablet" data-device_name="tab" data-page_id="${row.page_id}" data-is_landing_page="1"></span>
					<span class="dashicons dashicons-smartphone" data-device_name="smp" data-page_id="${row.page_id}" data-is_landing_page="1"></span>
				</div>`;
    	} }
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
	landingpageTable = qaTable.createTable('#tb_landingpage', landingpageHeader, landingpageOptions);

    let lpradios = document.getElementsByName( `js_lpGoals` );
    for ( let jjj = 0; jjj < lpradios.length; jjj++ ) {
        lpradios[jjj].addEventListener( 'click', qahm.changeLpGoal );
    }

});

qahm.changeLpGoal = function(e) {
    let checkedId = e.target.id;
    let idsplit   = checkedId.split('_');
    let gid       = Number( idsplit[2] );
    qahm.makeLandingPageTable( qahm.lpArray[gid] );
};

qahm.makeLandingPageTable = function (ary) {
    let newAry = new Array();

    //generate lp table
    for ( let nnn = 0; nnn < ary.length; nnn++ ) {
		newAry[nnn] = new Array();
		newAry[nnn][0]  = ary[nnn][0];
		newAry[nnn][1]  = ary[nnn][1];
		newAry[nnn][2]  = ary[nnn][2];
		newAry[nnn][3]  = ary[nnn][3];
		newAry[nnn][5]  = ary[nnn][5];
		newAry[nnn][9]  = ary[nnn][11];
		newAry[nnn][10] = ary[nnn][12];
		newAry[nnn][11] = ary[nnn][13];

        let session     = ary[nnn][3];
        let newsession  = ary[nnn][4];
        let bounce      = ary[nnn][6];
        let pvcount     = ary[nnn][7];
        let timeon      = ary[nnn][8];

        //ヒートマップリンク
		newAry[nnn][12] = '';

        if ( 0 < session ) {
            //新規セッション率
            let newsessionrate   = ( newsession / session ) * 100;
            newAry[nnn][4] = newsessionrate.toFixed(2);
            //ページ／セッション
            let pagesession = (pvcount / session);
            newAry[nnn][7] = pagesession.toFixed(2);
            //平均セッション時間
            let avgsessiontime   = (timeon / session);
            newAry[nnn][8] = avgsessiontime.toFixed(0);
            //直帰率はLPのうちの直帰数
            let bouncerate = (bounce / session) * 100;
            newAry[nnn][6] = bouncerate.toFixed(2);
        }
    }

	if ( landingpageTable ) {
		landingpageTable.updateData( newAry );
	}
};


/** -------------------------------------
 * データの取得と描画・表示
 */
jQuery(
	function() {
		qahm.nowAjaxStep = 0;
		qahm.renderBehaviorLpData(reportDateBetween);
		qahm.setDateRangePicker();

    }
);

// カレンダー期間変更時の処理
jQuery(document).on('qahm:dateRangeChanged', function( RangeStart, RangeEnd ) {
	qahm.nowAjaxStep = 0;
	qahm.renderBehaviorLpData(reportDateBetween);

});

qahm.renderBehaviorLpData = function(dateBetweenStr) {
    switch (qahm.nowAjaxStep) {
        case 0:
			jQuery('#datepicker-base-textbox').prop('disabled', true);
			qahm.disabledGoalRadioButton();
			landingpageTable.showLoading();

            qahm.nowAjaxStep = 'getGoals';
            qahm.renderBehaviorLpData(dateBetweenStr);
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
					/*
                    let ary = new Array();
                    for ( let gid = 1; gid <= Object.keys(data).length; gid++ ) {
                        ary = ary.concat( data[gid] );
                    }
                    for ( let gid = 0; gid <= Object.keys(data).length; gid++ ) {
                        if ( gid === 0 ) {
                            qahm.goalsSessionData[0] = ary;
                        } else {
                            qahm.goalsSessionData[gid] = data[gid];
                        }
                    }
					*/
					if (data !== null ) {
						let ary = new Array();
						for (let gid = 1; gid <= Object.keys(data).length; gid++) {
							ary = ary.concat(data[gid]);
						}
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
                    qahm.renderBehaviorLpData(dateBetweenStr);
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
                qahm.goalsLpArray  = new Array();
                qahm.goalsApArray  = new Array();

                let uri        = new URL(window.location.href);

                for ( let gid = 0; gid < Object.keys(qahm.goalsArray).length; gid++ ) {

                    //make nrd array
                    let lp_ary = new Array();
                    let ap_ary = new Array();
                    for ( let sno = 0; sno < qahm.goalsSessionData[gid].length; sno++ ) {
                        let lp = qahm.goalsSessionData[gid][sno][0];

                        //lp
                        if ( lp_ary[lp['page_id']] !== undefined  ) {
                            lp_ary[lp['page_id']] ++;
                        } else {
                            lp_ary[lp['page_id']] = 1;
                        }
                        //ap
                        for ( let pno = 0; pno < qahm.goalsSessionData[gid][sno].length; pno++ ) {
                            //pageid=null or pno=null の場合はスキップ
                            if (qahm.goalsSessionData[gid][sno][pno] == null || qahm.goalsSessionData[gid][sno][pno]['page_id'] == null) {
                                continue;
                            }
                            let pageid = Number(qahm.goalsSessionData[gid][sno][pno]['page_id']);
                            //mkdummy
                                if (Number(pageid)===4726) {
                                    console.log(pageid+'-'+gid.toString()+'-'+sno.toString()+'-'+pno.toString());
                                }
                                //mkdummy end
                            let is_conversion = false;
                            for ( let lll = 0; lll < qahm.goalsArray[gid]['pageid_ary'].length; lll++ ) {
                                if ( pageid === Number( qahm.goalsArray[gid]['pageid_ary'][lll]) ) {
                                    is_conversion = true;
                                    break;
                                }
                            }
                            if ( ap_ary[pageid]  !== undefined ) {
                                ap_ary[pageid] ++;
                            } else {
                                ap_ary[pageid] = 1;
                            }
                            if ( is_conversion ) {
                                break;
                            }
                        }
                    }
                    qahm.goalsLpArray[gid]  = lp_ary;
                    qahm.goalsApArray[gid]  = ap_ary;
                }
            }
            qahm.nowAjaxStep = 'getLp';
            qahm.renderBehaviorLpData(dateBetweenStr);
            break;


        case 'getLp':
            //landingpage table
            jQuery.ajax(
                {
                    type: 'POST',
                    url: qahm.ajax_url,
                    dataType : 'json',
                    data: {
                        'action' : 'qahm_ajax_get_lp_data',
                        'date' : dateBetweenStr,
                        'nonce':qahm.nonce_api,
                        'tracking_id':qahm.tracking_id
                    }
                }
            ).done(
                function( data ){
                    let ary = data;
                    if ( ary && Array.isArray( ary ) && ary.length > 0 ) {
                        qahm.lpArray = new Array;
                        if ( qahm.goalsJson ) {

                            //0:all のcv率と目標達成数はgid0に入っている全目標達成数を使える。但し目標値の合計は個別に計算が必要
                            for (let iii = 0; iii < ary.length; iii++) {
                                let pageid = ary[iii][0];
                                let valueall = 0;
                                let sessions = Number(ary[iii][3]);
                                for ( let gid = 1; gid < qahm.goalsLpArray.length; gid++ ) {
                                    if (qahm.lpArray[gid] === undefined) {
                                        qahm.lpArray[gid] = new Array();
                                    }
                                    if ( qahm.goalsLpArray[gid][pageid] !== undefined) {
                                        let gcount = Number(qahm.goalsLpArray[gid][pageid]);
                                        let cvrate = 0;
                                        if (0 < sessions) {
                                            cvrate = qahm.roundToX(gcount / sessions * 100, 2);
                                        }
                                        let valuex = Number(qahm.goalsArray[gid]['gnum_value']) * gcount;
                                        valueall += valuex;
                                        qahm.lpArray[gid][iii] = ary[iii].concat([cvrate, gcount, valuex]);
                                    } else {
                                        qahm.lpArray[gid][iii] = ary[iii].concat([0, 0, 0]);
                                    }
                                }
                                let gcountall = 0;
                                if ( qahm.goalsLpArray[0][pageid] !== undefined ) {
                                    if ( qahm.goalsLpArray[0][pageid] !== undefined ) {
                                        gcountall = qahm.goalsLpArray[0][pageid] ;
                                    }
                                }
                                let cvrateall = 0;
                                if ( 0 < sessions ) {
                                    cvrateall = qahm.roundToX( gcountall / sessions * 100, 2);
                                }
                                if ( qahm.lpArray[0] === undefined ) {
                                    qahm.lpArray[0] = new Array();
                                }
                                qahm.lpArray[0][iii] = ary[iii].concat([ cvrateall, gcountall, valueall ]);
                            }
                        }
                        else {
                            //全部0
                            qahm.lpArray[0] = new Array();
                            for (let iii = 0; iii < ary.length; iii++) {
                                qahm.lpArray[0][iii] = ary[iii].concat([ 0, 0, 0 ]);
                            }
                        }
                        qahm.makeLandingPageTable( qahm.lpArray[0] );
					} else {
						landingpageTable.updateData( [] );
					}
                }
            ).fail(
                function( jqXHR, textStatus, errorThrown ){
                    qahm.log_ajax_error( jqXHR, textStatus, errorThrown );
                }
            ).always(
                function(){
                    // date picker enable
                    jQuery('#datepicker-base-textbox').prop('disabled', false);
					qahm.enabledGoalRadioButton();
                    qahm.nowAjaxStep = 0;
                }
            );
            break;

        default:
            break;

    }
};
