var qahm = qahm || {};

qahm.dashParamDeferred = new jQuery.Deferred();

window.addEventListener('DOMContentLoaded', function() {
	qahm.initDateSetting();

	qahm.initDashboardCollapsible();
	qahm.initDashboardTemplateCards();

    // 表示するグラフの期間を取得
    // --- Using Dayjs Start ------
	// ダッシュボード　180日固定
	let dashbdDaysRange = 180 - 1;
    let dashbdToDate = dayjs.tz(reportRangeEnd).endOf('day');
	let dashbdFromDate = dashbdToDate.subtract( dashbdDaysRange, 'day' ).startOf('day');	
	let dashbdFromDateStr = dashbdFromDate.format('YYYY-MM-DD');
	dashbdDateBetween = 'date = between ' + dashbdFromDateStr + ' and ' + reportRangeEndStr;
    // --- Using Dayjs End ------

});


/** -------------------------------------
 * データの取得と描画・表示
 */
jQuery(
	function() {
        //days access
        jQuery.ajax(
            {
                type: 'POST',
                url: qahm.ajax_url,
                dataType : 'json',
                data: {
                    'action' : 'qahm_ajax_select_data',
                    'table' : 'summary_days_access',
                    'select': '*',
                    'date_or_id': dashbdDateBetween,
                    'count' : false,
                    'nonce':qahm.nonce_api,
                    'tracking_id' : qahm.tracking_id
                }
            }
        ).done(
            function( data ){
                qahm.dashParam = data;
                qahm.dashParamDeferred.resolve();
            }
        ).fail(
            function( jqXHR, textStatus, errorThrown ){
                qahm.log_ajax_error( jqXHR, textStatus, errorThrown );
                qahm.dashParamDeferred.reject();
            }
        );

		qahm.dashParamDeferred.promise().then(function() {
			if ( ! qahm.dashParam ) {
				return;
			}

			//dashboard用に180日が戻ってくる。30日分を抜き出して渡す。
			let dashary = qahm.dashParam;

			// koji
			// write dashboard
			let statlen     = dashary.length;
			let ary_lastday = dashary[statlen -1]['date'];

			let matsubi = moment(ary_lastday, "YYYY-MM-DD");
			let tsuitachi = matsubi.date(1);
			let last_mn = moment(tsuitachi).subtract(1, 'months');
			let this_mn = moment(tsuitachi).format('YYYY-MM-DD');
			last_mn = moment(last_mn).format('YYYY-MM-DD');

			let dayslen  = 180;
			let startidx = statlen - dayslen;
			let offset   = 0;
			if ( startidx < 0 ) {
				startidx = 0;
			}

			let goalDaySession = 0;
			let goalday = '';
			if ( qahm.siteinfoJson ) {
				let siteinfoObj = JSON.parse( qahm.siteinfoJson );
				goalDaySession = siteinfoObj['goaldaysession'];
				goalday = siteinfoObj['goalday'];
			}

			let dashcharts_data  = new Array();
			let goalcharts_data  = new Array();
			let dashcharts_label = new Array();
			let this_mn_sessions = 0;
			let last_mn_sessions = 0;
			for ( let iii = statlen - 1 ; startidx <= iii ; --iii ) {
				if ( moment( dashary[iii]['date'] ).isSameOrAfter( this_mn ) ) {
					this_mn_sessions += dashary[iii]['session_count'];
				}else if ( moment( dashary[iii]['date'] ).isSameOrAfter( last_mn ) ) {
					last_mn_sessions += dashary[iii]['session_count'];
				}
				dashcharts_label[iii - startidx] = dashary[iii]['date'].slice(5).replace('-', '/');
				dashcharts_data[iii - startidx]  = dashary[iii]['session_count'];
				if ( 0 < goalDaySession ) {
					goalcharts_data[iii - startidx] = goalDaySession;
				}
			}
			let ary_lastd_o      = dateStringSlicer( ary_lastday );
			let ary_lastday_len  = ary_lastd_o['D'];
			let thismn_lastday   = new Date( ary_lastd_o['Y'], ary_lastd_o['M'], 0 )
			let thismn_lastday_n = thismn_lastday.getDate();
			let this_mn_estimate = 0;
			if ( ary_lastday_len !== 0 ) {
				this_mn_estimate = Math.round( this_mn_sessions / ary_lastday_len * thismn_lastday_n );
			}

			//write
			document.getElementById('last-month-sessions').innerText = qahm.comma( last_mn_sessions );
			document.getElementById('this-month-sessions').innerText = qahm.comma( this_mn_sessions );
			document.getElementById('this-month-estimate').innerText = qahm.comma( this_mn_estimate );

			let cvAccessGraph = document.getElementById('access_graph');
			let datasets = {
					type: 'line',
					label: qahml10n['graph_sessions'],
					fill: false,
					lineTension: 0,
					data: dashcharts_data,
					borderColor: '#69A4E2',
					borderJoinStyle: 'bevel',
					pointStyle: 'rect',
					pointRadius: 1.5,
					borderWidth: 2.5,
					pointBackgroundColor: '#69A4E2',
				};
			datasets = [datasets];

			let cvAccessGraphChart = new Chart(cvAccessGraph, {
				type: 'line',
				data: {
				labels: dashcharts_label,
				datasets: datasets,
				},
				options: {
					legend: {
						labels: {
							fontSize: 9
						},
					},
					scales: {
						yAxes: [{
							ticks: {
								min: 0,
							},
							beforeBuildTicks: function(axis) {
								if( axis.max < 6 ) {
									axis.max = 6;
									axis.options.ticks.stepSize = 1;
								}
							},
						}],
						xAxes: [{
							// 日付ラベルの設定
							ticks: {
								autoSkip: true,
								maxRotation: 0,
								minRotation: 0,
								maxTicksLimit: 10
							}
						}],
					},
				},
			});
		});


        // goals
        if ( qahm.goalsJson ) {
            qahm.goalsArray = JSON.parse( qahm.goalsJson );

            jQuery.ajax(
                {
                    type: 'POST',
                    url: qahm.ajax_url,
                    dataType : 'json',
                    data: {
                        'action' : 'qahm_ajax_get_two_mon_sessions',
                        'tracking_id' : qahm.tracking_id
                    }
                }
            ).done(
                function( data ){
                    if ( data ) {
                        qahm.g2monSessionsJson = data['g_session_ary'];
                        document.getElementById('this-month-goal-cv').textContent = data['g_nmon_cv'];
                        document.getElementById('this-month-goal-estimate').textContent = data['g_estimate'];
                        document.getElementById('last-month-goal-cv').textContent = data['g_lmon_cv'];

                        let lastdayobj   = new Date();
                        lastdayobj.setDate( lastdayobj.getDate() -1 );
            
                        let lmon01obj   = new Date();
                        lmon01obj.setDate(1);
                        lmon01obj.setMonth( lmon01obj.getMonth() -1 );
                        lmon01obj.setHours(0,0,0);
            
                        let sttdayobj  = new Date( lmon01obj );
                        let nextdayobj = new Date( sttdayobj );
                        nextdayobj.setDate( nextdayobj.getDate() + 1 );
            
                        let termdate  = (lastdayobj - lmon01obj) / 86400000;
                        let datelabel = [];
                        let datedata  = []
                        if ( qahm.g2monSessionsJson !== undefined ) {
                            let sessionary = new Array();
                            sessionary = JSON.parse(JSON.stringify(qahm.g2monSessionsJson));
                            for (let dno = 0; dno < termdate; dno++) {
                                let sno = 0;
                                let dateformat = ('00' + (sttdayobj.getMonth()+1)).slice(-2) + '/' + ('00' + sttdayobj.getDate()).slice(-2);
                                datelabel[dno] = dateformat;
                                datedata[dno] = 0;
                                while ( sno < sessionary[0].length ) {
                                    let accessdobj = new Date(sessionary[0][sno][0]['access_time'] * 1000);
                                    if (sttdayobj <= accessdobj && accessdobj < nextdayobj) {
                                        datedata[dno]++;
                                    }
                                    sno++;
                                }
                                sttdayobj = new Date(nextdayobj);
                                nextdayobj.setDate( nextdayobj.getDate() + 1 );
                            }
            
                            //goals graph
                            let cvConversionGraph = document.getElementById('conversion_graph');
                            let conv_charts_data = datedata;
                            let cvConversionGraphChart = new Chart(cvConversionGraph, {
                                type: 'bar',
                                data: {
                                    labels: datelabel,
                                    datasets: [{
                                        label: 'Conversions',
                                        fill: false,
                                        lineTension: 0,
                                        data: conv_charts_data,
                                        borderColor: qahm.graphColorGoals[0],
                                        borderJoinStyle: 'bevel',
                                        pointStyle: 'rect',
                                        pointRadius: 1.5,
                                        borderWidth: 2.5,
                                        pointBackgroundColor: qahm.graphColorGoals[0],
                                    }],
                                },
                                options: {
                                    legend: {
                                        labels: {
                                            fontSize: 9
                                        },
                                    },
									scales: {
										xAxes: [{
											ticks: {
												autoSkip: true,
												maxRotation: 0,
												minRotation: 0,
												maxTicksLimit: 10
											}
										}]
									},
                                },
                            });
                        }

                    }
                }
            ).fail(
                function( jqXHR, textStatus, errorThrown ){
                    qahm.log_ajax_error( jqXHR, textStatus, errorThrown );
                }
            );

        }


		function dateStringSlicer ( yyyy_mm_dd ) {
			let obj = new Object();
			obj['Y'] = Number( yyyy_mm_dd.substr( 0,4 ) );
			obj['M'] = Number( yyyy_mm_dd.substr( 5,2 ) );
			obj['D']  = Number( yyyy_mm_dd.substr( 8,2 ) );
			return obj
		}
    }
);

qahm.initDashboardCollapsible = function() {
	var collapsibles = document.querySelectorAll('.qahm-dashboard-collapsible');
	
	collapsibles.forEach(function(collapsible) {
		var header = collapsible.querySelector('.qahm-dashboard-collapsible__header');
		
		if (header) {
			header.addEventListener('click', function() {
				var isCollapsed = collapsible.getAttribute('data-collapsed') === 'true';
				var isExpanded = !isCollapsed;
				
				collapsible.setAttribute('data-collapsed', isExpanded ? 'true' : 'false');
				header.setAttribute('aria-expanded', !isExpanded ? 'true' : 'false');
			});
		}
	});
};

qahm.initDashboardTemplateCards = function() {
	var templateButtons = document.querySelectorAll('.qahm-dashboard-template-card__button');
	
	templateButtons.forEach(function(button) {
		button.addEventListener('click', function() {
			var template = button.getAttribute('data-template');
			
			if (template) {
				qahm.handleTemplateCardClick(template);
			}
		});
	});
};

qahm.handleTemplateCardClick = function(template) {
	if (typeof console !== 'undefined') {
		console.log('AI Report template selected:', template);
	}
};
