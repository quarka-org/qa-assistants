var qahm = qahm || {};

if ( typeof qahm.tracking_id === 'undefined' ) {
	let url         = new URL(window.location.href);
	let params      = url.searchParams;
	qahm.tracking_id = params.get('tracking_id');
}

window.addEventListener('DOMContentLoaded', function() {
	qahm.initDateSetting();

	// QA Tableの初期化
	allpageHeader = [
		{ key: 'page_id', label: '', hidden: true, type: 'integer' },
		{ key: 'title', label: qahml10n['table_title'], width: 18 },
		{ key: 'url', label: qahml10n['table_url'], width: 18, type: 'link' },
		{ key: 'page_view_num', label: qahml10n['table_page_view_num'], width: 8, type: 'integer' },
		{ key: 'page_visit_num', label: qahml10n['table_page_visit_num'], width: 8, type: 'integer' },
		{ key: 'page_avg_stay_time', label: qahml10n['table_page_avg_stay_time'], width: 8, type: 'duration' },
		{ key: 'entrance_num', label: qahml10n['table_entrance_num'], width: 8, type: 'integer' },
		{ key: 'bounce_rate', label: qahml10n['table_bounce_rate'], width: 8, type: 'percentage' },
		{ key: 'exit_rate', label: qahml10n['table_exit_rate'], width: 8, type: 'percentage' },
		{ key: 'page_value', label: qahml10n['table_page_value'], width: 8, type: 'integer' },
		{ key: 'heatmap', label: qahml10n['table_heatmap'], width: 8, exportable: false, sortable: false, filtering: false, formatter: function(value, row) {
			return `<div class="qa-table-heatmap-container">
					<span class="dashicons dashicons-desktop" data-device_name="dsk" data-page_id="${row.page_id}" data-is_landing_page="0"></span>
					<span class="dashicons dashicons-tablet" data-device_name="tab" data-page_id="${row.page_id}" data-is_landing_page="0"></span>
					<span class="dashicons dashicons-smartphone" data-device_name="smp" data-page_id="${row.page_id}" data-is_landing_page="0"></span>
				</div>`;
    	} }
	];
	allpageOptions = {
		perPage: 100,
		pagination: true,
		exportable: true,
		sortable: true,
        filtering: true,
		maxHeight: 600,
		stickyHeader: true,
		initialSort: {
			column: 'page_view_num',
			direction: 'desc'
		}
	};
	allpageTable = qaTable.createTable('#tb_allpage', allpageHeader, allpageOptions);

    let apradios = document.getElementsByName( `js_apGoals` );
    for ( let jjj = 0; jjj < apradios.length; jjj++ ) {
        apradios[jjj].addEventListener( 'click', qahm.changeApGoal );
    }

});

qahm.changeApGoal = function(e) {
    let checkedId = e.target.id;
    let idsplit   = checkedId.split('_');
    let gid       = Number( idsplit[2] );
	allpageTable.showLoading();
    qahm.makeAllPageTable( qahm.apArray[gid] );
};

qahm.makeAllPageTable = function (ary) {
    let newAry = new Array();

    //generate allpage table
    for ( let nnn = 0; nnn < ary.length; nnn++ ) {
		newAry[nnn] = new Array();
		newAry[nnn][0] = ary[nnn][0];
		newAry[nnn][1] = ary[nnn][1];
		newAry[nnn][2] = ary[nnn][2];
		newAry[nnn][3] = ary[nnn][3];
		newAry[nnn][4] = ary[nnn][4];
		newAry[nnn][5] = 0;
		newAry[nnn][6] = ary[nnn][6];
		newAry[nnn][7] = 0;
		newAry[nnn][8] = 0;
		newAry[nnn][9] = ary[nnn][11];

        let pvcounts = ary[nnn][3];
        let times    = ary[nnn][5];
        let lpcounts = ary[nnn][6];
        let bounces  = ary[nnn][7];
        let exits    = ary[nnn][8];

		//ヒートマップリンク
		newAry[nnn][10] = '';

        if ( 0 < pvcounts ) {
            //平均ページ滞在時間（秒）
            let sessiontim = (times / pvcounts);
            newAry[nnn][5] = sessiontim.toFixed(0);
            //離脱率はPV数のうちの離脱数
            let exitrate   = (exits / pvcounts) * 100;
            newAry[nnn][8] = exitrate.toFixed(2);
        }
        if ( 0 < lpcounts ) {
            //直帰率はLPのうちの直帰数
            let bouncerate = (bounces / lpcounts) * 100;
            newAry[nnn][7] = bouncerate.toFixed(2);
        }
    }

	if ( allpageTable ) {
		allpageTable.updateData( newAry );
	}
};


/** -------------------------------------
 * データの取得と描画・表示
 */
jQuery(
	function() {
		qahm.nowAjaxStep = 0;
		qahm.renderBehaviorApData(reportDateBetween);
		qahm.setDateRangePicker();

    }
);

// カレンダー期間変更時の処理
jQuery(document).on('qahm:dateRangeChanged', function( RangeStart, RangeEnd ) {
	qahm.nowAjaxStep = 0;
	qahm.renderBehaviorApData(reportDateBetween);

});

qahm.renderBehaviorApData = function(dateBetweenStr) {
    switch (qahm.nowAjaxStep) {
        case 0:
			jQuery('#datepicker-base-textbox').prop('disabled', true);
			qahm.disabledGoalRadioButton();
			allpageTable.showLoading();

            qahm.nowAjaxStep = 'getGoals';
            qahm.renderBehaviorApData(dateBetweenStr);
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
                    qahm.renderBehaviorApData(dateBetweenStr);
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
            qahm.nowAjaxStep = 'getAp';
            qahm.renderBehaviorApData(dateBetweenStr);
            break;

        case 'getAp':
            //allpage table
            jQuery.ajax(
                {
                    type: 'POST',
                    url: qahm.ajax_url,
                    dataType : 'json',
                    data: {
                        'action' : 'qahm_ajax_get_ap_data',
                        'date' : dateBetweenStr,
                        'nonce':qahm.nonce_api,
                        'tracking_id':qahm.tracking_id
                    }
                }
            ).done(
                function( data ){
                    let ary = data;
                    if ( ary && Array.isArray( ary ) && ary.length > 0 ) {
                        qahm.apArray = new Array;
                        if ( qahm.goalsJson ) {

                            //0:all のcv率と目標達成数はgid0に入っている全目標達成数を使える。但し目標値の合計は個別に計算が必要
                            for (let iii = 0; iii < ary.length; iii++) {
                                let pageid = ary[iii][0];

                                //mkdummy
                                let url = ary[iii][2];
                                let serach = '/lp-qa-analytics/?maf=3115_2623747.40932.0..1768949474.1653962871';
                                let islog = false;
                                if (url.indexOf(serach) > 0 ) {
                                    islog = true;
                                }
                                //mkdumy end
                                let pagevalueall = 0;
                                let sessions = Number(ary[iii][4]);
                                for ( let gid = 1; gid < qahm.goalsApArray.length; gid++ ) {
                                    if (qahm.apArray[gid] === undefined) {
                                        qahm.apArray[gid] = new Array();
                                    }
                                    if ( qahm.goalsApArray[gid][pageid] !== undefined) {
                                        let gcount = qahm.goalsApArray[gid][pageid];
                                        let valuex = qahm.goalsArray[gid]['gnum_value'] * gcount;
                                        //mkdummy

                                        if (islog) {
                                            console.log(pageid+'-'+gid.toString()+'-'+gcount.toString()+'-'+valuex.toString());
                                        }
                                        //mkdummy end

                                        let pagevalue = 0;
                                        if (0 < sessions) {
                                            pagevalue = Math.round( valuex / sessions );
                                        }
                                        pagevalueall += pagevalue;
                                        qahm.apArray[gid][iii] = ary[iii].concat([ pagevalue ]);
                                    } else {
                                        qahm.apArray[gid][iii] = ary[iii].concat([0]);
                                    }
                                }
                                if ( qahm.apArray[0] === undefined ) {
                                    qahm.apArray[0] = new Array();
                                }
                                qahm.apArray[0][iii] = ary[iii].concat([ pagevalueall ]);
                            }
                        }
                        else {
                            //全部0
                            qahm.apArray[0] = new Array();
                            for (let iii = 0; iii < ary.length; iii++) {
                                qahm.apArray[0][iii] = ary[iii].concat([ 0 ]);
                            }
                        }
                        qahm.makeAllPageTable(qahm.apArray[0]);
                    } else {
						allpageTable.updateData( [] );
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
