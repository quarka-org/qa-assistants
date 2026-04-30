var qahm = qahm || {};
qahm.filterKeyAry      = [];
qahm.filterMediaAry    = [];
qahm.filterSourceAry   = [];
qahm.filterCampaignAry = [];
qahm.filterGoalAry     = [];
qahm.urlSourceAry      = [];
qahm.urlMediaAry       = [];
qahm.urlCampaignAry    = [];
qahm.urlGoalAry        = [];

qahm.mouseY = 0;

// ログイン判定
qahm.loadScreen.promise().then(

	function () {

		// インラインフレームと外のフレームのスクロールを連動させる
		const outer = document.getElementById('heatmap-iframe-container');
		const innerIframe = document.getElementById('heatmap-iframe');
  
		function adjustOuterHeight() {
			const innerDoc = innerIframe.contentDocument || innerIframe.contentWindow.document;
			const innerHeight = innerDoc.documentElement.scrollHeight;
			outer.style.height = `${innerHeight}px`;
			innerIframe.style.height = `${innerHeight}px`;
		  }

		adjustOuterHeight();
		const innerDoc = innerIframe.contentDocument || innerIframe.contentWindow.document;

		 // iframe内のスクロールバーを非表示にする
		innerDoc.body.style.overflow = 'hidden';

		outer.onscroll = function() {
			innerDoc.documentElement.scrollTop = outer.scrollTop;
		};

		innerDoc.onscroll = function() {
			outer.scrollTop = innerDoc.documentElement.scrollTop;
		};

		// Adjust height if the content inside the iframe changes dynamically
		const observer = new MutationObserver(adjustOuterHeight);
		observer.observe(innerDoc.body, { childList: true, subtree: true, attributes: true, characterData: true });

		let width = null;
		if ( qahm.dev ) {
			switch ( qahm.dev ) {
				case 'smp':
					width = '375px';
					break;
				case 'tab':
					width = '768px';
					break;
				default:
					if ( outer ) {
						width = outer.clientWidth + 'px';
					} else {
						width = '1903px';
					}
					break;
			}
		}
	
		let elem = document.getElementById('heatmap-iframe');
		if ( elem ) {
			elem.style.width = width;
			elem.style.visibility = 'visible';
		}
	
		elem = document.getElementById('heatmap-container');
		if ( elem ) {
			//1elem.style.width = width;
			elem.style.visibility = 'visible';
		}
	
		elem = document.getElementById('heatmap-scroll');
		if ( elem ) {
			elem.style.width = width;
		}
		
		elem = document.getElementById('heatmap-attention');
		if ( elem ) {
			elem.style.width = width;
		}
	
		elem = document.getElementById('heatmap-click-count');
		if ( elem ) {
			elem.style.width = width;
		}

		elem = document.getElementById('heatmap-click-heat');
		if ( elem ) {
			elem.style.width = width;
		}

		qahm.createDateRangePicker();

		let d = new jQuery.Deferred();
		qahm.showLoadIcon();
		jQuery.ajax( {
			type: 'POST',
			url: qahm.ajax_url,
			data: {
				'action': 'qahm_ajax_init_heatmap_view',
				'type': qahm.type,
				'id': qahm.id,
				'ver': qahm.ver,
				'dev': qahm.dev,
				'file_base_name': qahm.file_base_name,
			},
			dataType: 'json',
		}
		).done(
			function (data) {
				setData(data); // Call the new function here

				qahm.iframeWin  = document.getElementById('heatmap-iframe').contentWindow;
				qahm.iframeDoc  = document.getElementById('heatmap-iframe').contentWindow.document;
				qahm.iframeHtml = qahm.iframeDoc.documentElement;
				qahm.iframeBody = qahm.iframeDoc.body;

				let cookieAry = qahm.getCookieArray();
				if ( cookieAry['qa_heatmap_bar_scroll'] === 'true' ) {
					jQuery( '#heatmap-scroll' ).removeClass( 'qahm-hide' );
					jQuery( '#heatmap-scroll-tooltip' ).removeClass( 'qahm-hide' );
				}
				if ( cookieAry['qa_heatmap_bar_attention'] === 'true' ) {
					jQuery( '#heatmap-attention' ).removeClass( 'qahm-hide' );
				}
				if ( cookieAry['qa_heatmap_bar_click_heat'] === 'true' ) {
					jQuery( '#heatmap-click-heat' ).removeClass( 'qahm-hide' );
				}
				if ( cookieAry['qa_heatmap_bar_click_count'] === 'true' ) {
					jQuery( '#heatmap-click-count' ).removeClass( 'qahm-hide' );
				}

				d.resolve();
			}
		).fail(
			function (jqXHR, textStatus, errorThrown) {
				qahm.log_ajax_error(jqXHR, textStatus, errorThrown);
				d.reject();
			}
		);
		return d.promise();
	}
)
.then(
	function () {
		//QA ZERO
		let d = new jQuery.Deferred();
		jQuery.ajax({
			url: qahm.ajax_url, // This should be the URL to the WordPress Ajax handler (usually admin-ajax.php)
			type: 'POST',
			data: {
				action: 'qahm_ajax_get_separate_data',
				file_base_name: qahm.file_base_name, // Replace this with the actual version ID
			}
		}
		).done(
			function (data) {
				// Save the response data to the qahm object
				qahm.separateData = data;
				qahm.processSeparateData(qahm.separateData);

				// 現在のURLからパラメータを解析
				const sourceParam   = qahm.source;
				const mediaParam    = qahm.media;
				const campaignParam = qahm.campaign;
				const goalParam     = qahm.goal;

				if ( sourceParam || mediaParam || campaignParam || goalParam ) {
					qahm.dataNum = 0;
					qahm.totalStayTime = 0;
					qahm.avgStayTime = 0;
					qahm.sepDataNumAry = JSON.parse(qahm.separate_data_num);
					qahm.sepTotalStayTimeAry = JSON.parse(qahm.separate_total_stay_time);

					qahm.convertUrlParamToAry( sourceParam, mediaParam, campaignParam, goalParam );
					qahm.convertFilterKeyAry();
					qahm.updateDataNum();
					qahm.filterMergeData();
					jQuery( '#heatmap-bar-data-num span' ).html( '<i class="fas fa-users"></i>Valid Data: ' + qahm.dataNum );
				}

				qahm.createFilterBlock();
				d.resolve();
			}
		).fail(
			function (jqXHR, textStatus, errorThrown) {
				qahm.log_ajax_error(jqXHR, textStatus, errorThrown);
				d.reject();
			}
		);
		return d.promise();
	}
)
.then(
	function () {
        // データ数に応じた処理  
        if (qahm.dataNum === 0 && !qahm.hasShownNoDataAlert) {  
            qahm.hasShownNoDataAlert = true;  
            AlertMessage.alert(  
                qahml10n['no_valid_data_title'],  
                qahml10n['no_valid_data_message'],  
                'warning',  
                function(){}  
            );  
        } else if (qahm.dataNum > 0) {  
            // ヒートマップ初期化処理  
			qahm.initMapParam();
			qahm.createBlockArray();
			qahm.createClickHeatMap();
			qahm.createScrollMap();
			qahm.addScrollMapEvent();
			qahm.updateScrollMapTooltipDataNum( 0 );
			qahm.createAttentionMap();
			qahm.createClickCountMap();

            // 表示切り替え  
			qahm.toggleShowElement('.heatmap-bar-scroll', '#heatmap-scroll' );
			qahm.toggleShowElement('.heatmap-bar-scroll', '#heatmap-scroll-tooltip' );
			qahm.toggleShowElement('.heatmap-bar-attention', '#heatmap-attention' );
			qahm.toggleShowElement('.heatmap-bar-click-heat', '#heatmap-click-heat' );
			qahm.toggleShowElement('.heatmap-bar-click-count', '#heatmap-click-count' );

			//qahm.resizeWindow();
			qahm.changeBarConfig();
			qahm.addIframeEvent();
			qahm.correctScroll();
			//qahm.addFilterPopupEvent();

			(function scheduleCheck() {
				setTimeout( function() {
					qahm.checkUpdateMap();
					scheduleCheck();
				}, 1000 );
			})();
		}

        // 共通処理 
		jQuery( 'a', qahm.iframeDoc ).on( 'click', function() {
			return false;
		});

		qahm.changeVersionSelectBox( '#heatmap-bar-device-version-selectbox' );
		qahm.changeVersionSelectBox( '#heatmap-bar-page-version-selectbox' );
		
		jQuery('#heatmap-bar-version-update-button').on('click', function() {
			qahm.updatePageVersion();
		});
		
		qahm.disabledConfig( false );
		qahm.hideLoadIcon();
	}

);

//QA ZERO
function setData(data) {
	// debugレベルがちゃんと動作しているか確かめる
	qahm.debug = data['debug'];
	qahm.debug_level = data['debug_level'];
	qahm.type = data['type'];
	qahm.type_zero = data['type_zero'];
	qahm.type_wp = data['type_wp'];
	qahm.locale = data['locale'];
	qahm.dataNum = data['data_num'];
	qahm.verMax = data['ver_max'];
	qahm.recFlag = data['rec_flag'];
	qahm.freeRecFlag = data['free_rec_flag'];
	qahm.recRefresh = data['rec_refresh'];
	qahm.mergeC = data['merge_c'];
	qahm.mergeASV1 = data['merge_as_v1'];
	qahm.mergeASV2 = data['merge_as_v2'];

	// 合計値は保存しておく
	qahm.totalValues = {};
	qahm.totalValues.dataNum = data['data_num'];
	qahm.totalValues.mergeC = data['merge_c'];
	qahm.totalValues.mergeASV2 = data['merge_as_v2'];

	// 定数 わかりやすいように大文字
	qahm.DATA_HEATMAP_SELECTOR_NAME = data['DATA_HEATMAP_SELECTOR_NAME'];
	qahm.DATA_HEATMAP_SELECTOR_X    = data['DATA_HEATMAP_SELECTOR_X'];
	qahm.DATA_HEATMAP_SELECTOR_Y    = data['DATA_HEATMAP_SELECTOR_Y'];

	qahm.DATA_ATTENTION_SCROLL_PERCENT_V1   = data['DATA_ATTENTION_SCROLL_PERCENT_V1'];
	qahm.DATA_ATTENTION_SCROLL_STAY_TIME_V1 = data['DATA_ATTENTION_SCROLL_STAY_TIME_V1'];
	qahm.DATA_ATTENTION_SCROLL_STAY_NUM_V1  = data['DATA_ATTENTION_SCROLL_STAY_NUM_V1'];
	qahm.DATA_ATTENTION_SCROLL_EXIT_NUM_V1  = data['DATA_ATTENTION_SCROLL_EXIT_NUM_V1'];

	qahm.DATA_ATTENTION_SCROLL_STAY_HEIGHT_V2 = data['DATA_ATTENTION_SCROLL_STAY_HEIGHT_V2'];
	qahm.DATA_ATTENTION_SCROLL_STAY_TIME_V2   = data['DATA_ATTENTION_SCROLL_STAY_TIME_V2'];
	qahm.DATA_ATTENTION_SCROLL_STAY_NUM_V2    = data['DATA_ATTENTION_SCROLL_STAY_NUM_V2'];
	qahm.DATA_ATTENTION_SCROLL_EXIT_NUM_V2    = data['DATA_ATTENTION_SCROLL_EXIT_NUM_V2'];
}

//QA ZERO END

// cookie値を連想配列として取得する
qahm.getCookieArray = function(){
	var arr = new Array();
	if ( document.cookie !== '' ) {
		var tmp = document.cookie.split( '; ' );
		for (var i = 0;i < tmp.length;i++) {
			var data     = tmp[i].split( '=' );
			arr[data[0]] = decodeURIComponent( data[1] );
		}
	}
	return arr;;
};
