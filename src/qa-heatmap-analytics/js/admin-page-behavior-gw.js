var qahm = qahm || {};

if ( typeof qahm.tracking_id === 'undefined' ) {
	let url         = new URL(window.location.href);
	let params      = url.searchParams;
	qahm.tracking_id = params.get('tracking_id');
}

window.addEventListener('DOMContentLoaded', function() {
	qahm.initDateSetting();

	// QA Tableの初期化
	growthpageHeader = [
		{ key: 'page_id', label: '', hidden: true, type: 'integer' },
		{ key: 'title', label: qahml10n['table_title'], width: 18 },
		{ key: 'url', label: qahml10n['table_url'], width: 18, type: 'link' },
		{ key: 'media', label: qahml10n['table_media'], width: 8 },
		{ key: 'past_session', label: qahml10n['table_past_session'], width: 8, type: 'integer' },
		{ key: 'recent_session', label: qahml10n['table_recent_session'], width: 8, type: 'integer' },
		{ key: 'growth_rate', label: qahml10n['table_growth_rate'], width: 8, type: 'percentage' },
		{ key: 'goal_conversion_rate', label: qahml10n['table_goal_conversion_rate'], width: 8, type: 'percentage', typeOptions: { precision: 1 } },
		{ key: 'goal_completions', label: qahml10n['table_goal_completions'], width: 8, type: 'integer' },
		{ key: 'goal_value', label: qahml10n['table_goal_value'], width: 8, type: 'integer' },
		{ key: 'heatmap', label: qahml10n['table_heatmap'], width: 8, sortable: false, exportable: false, filtering: false, formatter: function(value, row) {
			return `<div class="qa-table-heatmap-container">
					<span class="dashicons dashicons-desktop" data-device_name="dsk" data-page_id="${row.page_id}" data-is_landing_page="1" data-media="${row.media}"></span>
					<span class="dashicons dashicons-tablet" data-device_name="tab" data-page_id="${row.page_id}" data-is_landing_page="1" data-media="${row.media}"></span>
					<span class="dashicons dashicons-smartphone" data-device_name="smp" data-page_id="${row.page_id}" data-is_landing_page="1" data-media="${row.media}"></span>
				</div>`;
    	} }
	];
	growthpageOptions = {
		perPage: 100,
		pagination: true,
		exportable: true,
		sortable: true,
        filtering: true,
		maxHeight: 600,
		stickyHeader: true,
		initialSort: {
			column: 'growth_rate',
			direction: 'desc'
		}
	};
	growthpageTable = qaTable.createTable('#tb_growthpage', growthpageHeader, growthpageOptions);

    let gwradios = document.getElementsByName( `js_gwGoals` );
    for ( let jjj = 0; jjj < gwradios.length; jjj++ ) {
        gwradios[jjj].addEventListener( 'click', qahm.changeGwGoal );
    }

});

qahm.changeGwGoal = function(e) {
    let checkedId = e.target.id;
    let idsplit   = checkedId.split('_');
    let gid       = Number( idsplit[2] );
    qahm.makeGrowthPageTable( qahm.gwArray[gid] );
};

qahm.makeGrowthPageTable = function (ary) {
    let newAry = new Array();

    for ( let iii = 0; iii < ary.length; iii++ ) {
        newAry[iii] = new Array();
		newAry[iii][0]  = ary[iii][0];
		newAry[iii][1]  = ary[iii][1];
		newAry[iii][2]  = ary[iii][2];
		newAry[iii][3]  = ary[iii][3];
		newAry[iii][4]  = ary[iii][4];
		newAry[iii][5]  = ary[iii][5];
		newAry[iii][6]  = ary[iii][6];
		newAry[iii][7]  = ary[iii][8];
		newAry[iii][8]  = ary[iii][9];
		newAry[iii][9]  = ary[iii][10];

        //ヒートマップリンク
        newAry[iii][10] = '';
    }

	if ( growthpageTable ) {
		growthpageTable.updateData( newAry );
	}
};


/** -------------------------------------
 * データの取得と描画・表示
 */
jQuery(
	function() {
		qahm.nowAjaxStep = 0;
		qahm.renderBehaviorGwData(reportDateBetween);
		qahm.setDateRangePicker();

    }
);

// カレンダー期間変更時の処理
jQuery(document).on('qahm:dateRangeChanged', function( RangeStart, RangeEnd ) {
	qahm.nowAjaxStep = 0;
	qahm.renderBehaviorGwData(reportDateBetween);

});

qahm.renderBehaviorGwData = function(dateBetweenStr) {
    switch (qahm.nowAjaxStep) {
        case 0:
            jQuery('#datepicker-base-textbox').prop('disabled', true);
			qahm.disabledGoalRadioButton();
			growthpageTable.showLoading();

            qahm.nowAjaxStep = 'getGoals';
            qahm.renderBehaviorGwData(dateBetweenStr);
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
                    qahm.renderBehaviorGwData(dateBetweenStr);
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
            qahm.nowAjaxStep = 'getGw';
            qahm.renderBehaviorGwData(dateBetweenStr);
            break;

        case 'getGw':
            // growth landing page table
            jQuery.ajax({
                type: 'POST',
                url: qahm.ajax_url,
                dataType: 'json',
                data: {
                    'action': 'qahm_ajax_get_gw_data',
                    'date': dateBetweenStr,
                    'nonce': qahm.nonce_api,
                    'tracking_id': qahm.tracking_id
                }
            }).done(function (data) {
                let ary = data;
                if (ary && Array.isArray(ary) && ary.length > 0) {
                    let pelm = 0;
                    let relm = 11;
                    let celm = 12;
                    let velm = 13;
                    qahm.gwArray = new Array();

                    if (qahm.goalsJson) {
                        // goalsLpArray から直接値を取得
                        for (let iii = 0; iii < ary.length; iii++) {
                            let pageid = ary[iii][0];
                            for (let gid = 0; gid < qahm.goalsLpArray.length; gid++) {
                                if (qahm.gwArray[gid] === undefined) {
                                    qahm.gwArray[gid] = new Array();
                                }
                                let goalData = qahm.goalsLpArray[gid][pageid];
                                if (goalData) {
                                    let sessions = Number(ary[iii][3]);
                                    let cvrate = sessions > 0 ? qahm.roundToX((goalData / sessions) * 100, 2) : 0;
                                    // gnum_valueが存在するかをチェックし、nullの場合は0を使用
                                    let gnumValue = qahm.goalsArray[gid] && qahm.goalsArray[gid]['gnum_value'] != null
                                        ? Number(qahm.goalsArray[gid]['gnum_value'])
                                        : 0;
                                    let valuex = gnumValue * goalData;
                                    qahm.gwArray[gid][iii] = ary[iii].concat([cvrate, goalData, valuex]);
                                } else {
                                    qahm.gwArray[gid][iii] = ary[iii].concat([0, 0, 0]);
                                }
                            }
                        }
                    } else {
                        // データがない場合は0を設定
                        qahm.gwArray[0] = new Array();
                        for (let iii = 0; iii < ary.length; iii++) {
                            qahm.gwArray[0][iii] = ary[iii].concat([0, 0, 0]);
                        }
                    }
                    qahm.makeGrowthPageTable( qahm.gwArray[0] );
                } else {
					growthpageTable.updateData( [] );
				}
            }).fail(function (jqXHR, textStatus, errorThrown) {
                qahm.log_ajax_error(jqXHR, textStatus, errorThrown);
            }).always(function () {
                // date picker enable
                jQuery('#datepicker-base-textbox').prop('disabled', false);
				qahm.enabledGoalRadioButton();
                qahm.nowAjaxStep = 0;
            });
            break;

        default:
            break;
    }

};
