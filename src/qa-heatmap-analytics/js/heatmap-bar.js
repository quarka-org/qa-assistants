var qahm = qahm || {};

// エレメントの表示 / 非表示切り替え.
qahm.toggleShowElement = function( clickElem, tarElem ) {
	let type = jQuery( clickElem ).attr( 'type' );

	let iframe = jQuery( '#heatmap-iframe' );

	// iframe内のコンテンツのdocumentオブジェクト
	let ifrmDoc = iframe[0].contentWindow.document;

	if ( type === 'checkbox' ) {
		jQuery( clickElem ).on(
			'click',
			function() {
				if ( jQuery( this ).prop( 'checked' ) ) {
					jQuery( clickElem ).prop( 'checked', true );
					jQuery( tarElem ).removeClass( 'qahm-hide' );
				} else {
					jQuery( clickElem ).prop( 'checked', false );
					jQuery( tarElem ).addClass( 'qahm-hide' );
				}
			}
		);
	}
};

// 絞り込み（フィルター）機能のhtml作成、イベント設定
qahm.createDateRangePicker = function() {
	moment.locale( qahm.wp_lang_set );

	// date range picker 初期設定
	jQuery( '#heatmap-bar-date-range-text' ).daterangepicker({
		startDate: moment(qahm.start_date),
		endDate: moment(qahm.end_date),
		minDate: moment(qahm.pvterm_start_date),
		maxDate: moment(qahm.pvterm_latest_date),
		showDropdowns: true,
		showCustomRangeLabel: true,
        linkedCalendars: false,
		ranges: {
			[qahml10n['calender_kako7days']]: [moment().subtract(7, 'days'), moment().subtract(1, 'days')],
			[qahml10n['calender_kako30days']]: [moment().subtract(30, 'days'), moment().subtract(1, 'days')],
			[qahml10n['calender_kongetsu']]: [moment().startOf('month'), moment().endOf('month')],
			[qahml10n['calender_sengetsu']]: [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
		},
		opens: 'center',
		locale: {
            //format: 'll',
			format: 'YYYY/MM/DD',
            //separator: ' ' + qahml10n['calender_kara'] + ' ',
			separator: ' - ',
            customRangeLabel: qahml10n['calender_erabu'],
            cancelLabel: qahml10n['calender_cancel'],
            applyLabel: qahml10n['calender_ok'],
		}
		}, function(startDate, endDate) {
			// URLオブジェクトを作成
			let url = new URL(window.location.href);

			// URLパラメータを設定（ここでは 'version' というパラメータを変更する例）
			url.searchParams.set('start_date', startDate.format('YYYY-MM-DD 00:00:00'));
			url.searchParams.set('end_date', endDate.format('YYYY-MM-DD 23:59:59'));

			// 新しいURLにリダイレクト
			window.location.href = url.toString();
		}
	);
};

// バージョンIDを変更するセレクトボックスのイベント処理
qahm.changeVersionSelectBox = function( selectboxId ) {
	jQuery( selectboxId ).on( 'change', function() {
		// 選択されたオプションのvalueを取得
		let versionId = jQuery(this).val();

		// URLオブジェクトを作成
		let url = new URL(window.location.href);

		// URLパラメータを設定（ここでは 'version' というパラメータを変更する例）
		url.searchParams.set('version_id', versionId);

		// 新しいURLにリダイレクト
		window.location.href = url.toString();
	});
};

// // デバイス選択ボックスの変更処理
// qahm.changeDeviceSelectBox = function() {
// 	jQuery( '#qahm-bar-device > select' ).change(
// 		function() {
// 			// window.location.href = jQuery(this).val();
// 			qahm.createCap( qahm.type, qahm.id, qahm.ver, jQuery( this ).val(), null, null, true );
// 		}
// 	);
// };

// // バージョン選択ボックスのソース取得
// qahm.getVersionSelectBoxSource = function() {
// 	bar_ver_select = '<select>';
// 	const verMax   = Number( qahm.verMax );
// 	const verView  = 30;
// 	let urlSplit   = location.href.split( '/' );
// 	/*
// 	・リストに表示するのは３０アイテムまで。
// 	・現在のリビジョン＋最新リビジョンを表示

// 	[例]
// 	バージョン50（一番上）
// 	バージョン100
// 	バージョン99
// 	バージョン98
// 	…
// 	バージョン70
// 	 */
// 	bar_ver_select += qahm.getVersionSelectBoxOptionSource( qahm.ver, '' );
// 	for ( let i = verMax; i > Math.max( 0,verMax - verView ); i-- ) {
// 		if ( Number( qahm.ver ) === i ) {
// 			continue;
// 		}
// 		urlSplit[urlSplit.length - 3] = i;
// 		bar_ver_select               += qahm.getVersionSelectBoxOptionSource( i, urlSplit.join( '/' ) );
// 	}
// 	bar_ver_select += '</select>';
// 	return bar_ver_select;
// }

// // バージョン選択ボックスのoptionタグを構築
// qahm.getVersionSelectBoxOptionSource = function( ver, url ) {
// 	if ( url ) {
// 		url = ' value="' + url + '"';
// 	}
// 	for ( let i = 0, len = qahm.recRefresh.length; i < len; i++ ) {
// 		if ( qahm.recRefresh[i]['version'] == ver ) {
// 			if ( qahm.recRefresh[i]['end_date'] ) {
// 				return null;
// 			}
// 			//let date_obj = new Date( qahm.recRefresh[i]['refresh_date'] );
// 			let date     = qahm.recRefresh[i]['refresh_date'];//date_obj.getFullYear() + '/' + ('0' + (date_obj.getMonth() + 1)).slice( -2 ) + '/' + ('0' + date_obj.getDate()).slice( -2 );
// 			return '<option' + url + '>Ver.' + ver + ' ' + date + ' - </option>';
// 		}
// 	}
// 	return null;
// };

// // バージョン選択ボックスの変更処理
// qahm.changeVersionSelectbox = function() {
// 	jQuery( '#qahm-bar-version-select > select' ).on(
// 		'change',
// 		function() {
// 			// 遷移先バージョン取得
// 			let ver = jQuery( this ).val();
// 			if (ver != '') {
// 				qahm.createCap( qahm.type, qahm.id, ver, qahm.dev, null, null, false );
// 			}
// 		}
// 	);
// };


// Differs between ZERO and QA - Start ----------
// 絞り込み（フィルター）機能のhtml作成、イベント設定
// QA ZEROでのみフィルター機能を有効にする
qahm.createFilterBlock = function() {
	// QA Assistantsではフィルター機能を無効化
	if (qahm.type !== qahm.type_zero) {
		return;
	}
	// コンテンツの構築
	createSelectBox( qahml10n['source'], 'source', qahml10n['select_source'], qahm.filterSourceAry, qahm.urlSourceAry );
	createSelectBox( qahml10n['medium'], 'media', qahml10n['select_medium'], qahm.filterMediaAry, qahm.urlMediaAry );
	createSelectBox( qahml10n['campaign'], 'campaign', qahml10n['select_campaign'], qahm.filterCampaignAry, qahm.urlCampaignAry );
	createSelectBox( qahml10n['goal'], 'goal', qahml10n['select_goal'], qahm.filterGoalAry, qahm.urlGoalAry );
	createOkCancelButton();

	// 絞り込みアイコンを押した時フィルターウインドウを表示
	const filterButton = document.querySelector('[data-id="heatmap-bar-filter"]');
	if (filterButton) {
		filterButton.addEventListener('click', function(event) {
			document.getElementById('filter-overlay').classList.add('show');
		});
	}

	// モーダルの黒背景をクリックした時フィルターウインドウを非表示
	document.getElementById('filter-overlay').addEventListener('click', function(event) {
		// modal要素をクリックした場合は、イベントをバブルアップさせない
		if (event.target === document.getElementById('filter-overlay')) {
			document.getElementById('filter-overlay').classList.remove('show');
		}
	});

	function createSelectBox( title, filterName, defaultValue, dataAry, defCheckedAry ) {
		let html =
			'<div id="filter-' + filterName + '" class="filter-custom-select filter-custom-select-' + filterName + '">' +
				'<div class="filter-title">' + title + '</div>' +
				'<div class="filter-selectbox filter-selectbox-' + filterName + '">' +
					'<span id="filter-selected-' + filterName + '"">' + defaultValue + '</span>' +
				'</div>' +
				'<div class="filter-selectbox-items-container filter-selectbox-items-container-' + filterName + '">' +
					'<div class="filter-selectbox filter-selectbox-' + filterName + '">';

				for ( let dataIdx = 0; dataIdx < dataAry.length; dataIdx++ ) {
					let checked = '';
					if ( defCheckedAry.includes( dataAry[dataIdx] ) ) {
						checked = ' checked';
					}
					let escHtml = escapeHtml( dataAry[dataIdx] );
					let escAttr = escapeAttribute( dataAry[dataIdx] );
					html +=
						'<div class="filter-selectbox-item filter-selectbox-item-' + filterName + '">' +
							'<input type="checkbox" id="' + filterName + '-' + escAttr + '" name="' + filterName + '-' + escAttr + '" value="' + escHtml + '"' + checked + '>' + 
							'<label for="' + filterName + '-' + escAttr + '">' + escHtml + '</label>' +
						'</div>';
				}

				html +=
					'</div>' +
				'</div>' +
			'</div>';

		jQuery( '.filter-item-container' ).append( html );
		const selectBox = document.querySelector('.filter-selectbox-' + filterName);
		const optionsContainer = document.querySelector('.filter-selectbox-items-container-' + filterName);
		const options = document.querySelectorAll('.filter-selectbox-item-' + filterName);

		selectBox.addEventListener('click', () => {
			optionsContainer.classList.toggle('show');
			const rect = optionsContainer.getBoundingClientRect();
			const windowHeight = window.innerHeight;
			const windowWidth = window.innerWidth;

			if (rect.bottom > windowHeight) {
				optionsContainer.style.top = `-${rect.height}px`;
			} else {
				optionsContainer.style.top = '100%';
			}

			if (rect.right > windowWidth) {
				optionsContainer.style.left = `${windowWidth - rect.right}px`;
			} else {
				optionsContainer.style.left = '0';
			}
		});

		document.addEventListener('click', (e) => {
			if (!e.target.closest('.filter-custom-select-' + filterName)) {
				optionsContainer.classList.remove('show');
			}
		});

		options.forEach(option => {
			const checkbox = option.querySelector('.filter-selectbox-item-' + filterName + ' ' + 'input[type="checkbox"]');

			option.addEventListener('click', (e) => {
				checkbox.checked = !checkbox.checked;
				updateSelectBoxAppearance(filterName, defaultValue);

				if (checkbox.checked) {
					option.classList.add('checked');
				} else {
					option.classList.remove('checked');
				}

				e.stopPropagation(); // クリックイベントが親要素に伝播するのを防ぎます
			});

			// 初期状態でチェックが入っている場合の処理
			if (checkbox.checked) {
				option.classList.add('checked');
			}
		});

		// 初期状態でチェックがない場合にグレーにする
		updateSelectBoxAppearance(filterName, defaultValue);

		function updateSelectBoxAppearance(filterName, defaultValue) {
			const checkedOptions = document.querySelectorAll('.filter-selectbox-item-' + filterName + ' ' + 'input:checked');
			const values = Array.from(checkedOptions).map(option => option.value);
			const selectedItems = document.getElementById('filter-selected-' + filterName);
			const selectBox = document.querySelector('.filter-selectbox-' + filterName);
			selectedItems.textContent = values.length > 0 ? values.join(', ') : defaultValue;
		
			if (values.length === 0) {
				selectBox.classList.add('gray');
			} else {
				selectBox.classList.remove('gray');
			}
		}
	}

	// 適用, キャンセルボタンのhtml作成とイベント設定
	function createOkCancelButton() {
		let html =
			'<div class="filter-button-container">' +
				'<div id="filter-button-ok" class="filter-button filter-button-ok">' + qahml10n['apply_filter'] + '</div>' +
				'<div id="filter-button-cancel" class="filter-button filter-button-cancel">' + qahml10n['cancel'] + '</div>' +
			'</div>';
		
		jQuery( '.filter-item-container' ).append( html );
		
		document.getElementById('filter-button-ok').addEventListener('click', function(event) {
			applyFilter();
		});
		
		document.getElementById('filter-button-cancel').addEventListener('click', function(event) {
			document.getElementById('filter-overlay').classList.remove('show');
		});
	}

	// HTMLエスケープ
	function escapeHtml(str) {
		return str.replace(/[&<>"']/g, function (match) {
			const escape = {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#39;'
			};
			return escape[match];
		});
	}

	// 属性値エスケープ
	function escapeAttribute(str) {
		return str.replace(/[\x00-\x1F\x7F"'><]/g, function (match) {
			return `&#${match.charCodeAt(0)};`;
		});
	}

	// フィルタを適用する処理
	function applyFilter() {
		const sourceCheckedAry = document.querySelectorAll('.filter-selectbox-item-source input:checked');
		const sourceAry = Array.from(sourceCheckedAry).map(option => option.value);
		const mediaCheckedAry = document.querySelectorAll('.filter-selectbox-item-media input:checked');
		const mediaAry = Array.from(mediaCheckedAry).map(option => option.value);
		const campaignCheckedAry = document.querySelectorAll('.filter-selectbox-item-campaign input:checked');
		const campaignAry = Array.from(campaignCheckedAry).map(option => option.value);
		const goalCheckedAry = document.querySelectorAll('.filter-selectbox-item-goal input:checked');
		const goalAry = Array.from(goalCheckedAry).map(option => option.value);

		// 現在のURLを取得
		const currentUrl = new URL(window.location.href);

		// URLSearchParamsオブジェクトを作成
		const params = new URLSearchParams(currentUrl.search);

		// 配列をURLパラメータ用の文字列に変換してセット（既存のパラメーターは保持される）
		if ( sourceAry.length > 0 ) {
			params.set('source', sourceAry.join(','));
		} else {
			params.delete('source');
		}
		if ( mediaAry.length > 0 ) {
			params.set('media', mediaAry.join(','));
		} else {
			params.delete('media');
		}
		if ( campaignAry.length > 0 ) {
			params.set('campaign', campaignAry.join(','));
		} else {
			params.delete('campaign');
		}
		if ( goalAry.length > 0 ) {
			for ( let goalIdx = 0; goalIdx < goalAry.length; goalIdx++ ) {
				if ( goalAry[goalIdx] === qahml10n['goal_achieved'] ) {
					//goalAry[goalIdx] = 'true';
					goalAry[goalIdx] = 1;
				} else if ( goalAry[goalIdx] === qahml10n['goal_not_achieved'] ) {
					//goalAry[goalIdx] = 'false';
					goalAry[goalIdx] = 0;
				}
			}
			params.set('goal', goalAry.join(','));
		} else {
			params.delete('goal');
		}

		// 更新されたパラメーターを現在のURLに反映
		currentUrl.search = params.toString();

		// 新しいURLにリダイレクトしてページをリロード
		window.location.href = currentUrl.toString();
	}
}
// Differs between ZERO and QA - End ----------


qahm.processSeparateData = function( separateData ) {
	//let outputArray = [];
	//let totalExitNumSum = 0; // 合計を保存する変数を初期化

	// 合計行を追加
	//let totalCheckbox = '<input type="checkbox" data-col-id="0" data-row-id="0">';
	//outputArray.push([totalCheckbox, '', '合計', '---', '---', totalExitNumSum]);
	// 各参照元メディアごとにループ
	for (let key in separateData.merge_as) {
		let [media, source, campaign, is_goal] = key.split('_');
		//let totalExitNum = 0;

		if (!key.includes('_')) {
			continue;
		}

		//for (let subKey in separateData.merge_as[key]) {
		//	totalExitNum += separateData.merge_as[key][subKey][3]; // 3 is the index of EXIT_NUM in the array
		//}
		//totalExitNumSum += totalExitNum; // 合計を更新

		// チェックボックスを追加
		//let checkbox = '<input type="checkbox" data-col-id="0" data-row-id="' + outputArray.length + '">';
		//outputArray.push([checkbox, media + '_' + source + '_' + is_goal, media, source, is_goal, totalExitNum]);

		qahm.filterKeyAry.push( media + '_' + source + '_' + campaign + '_' + is_goal );
		if ( ! qahm.filterSourceAry.includes( source ) ) {
			qahm.filterSourceAry.push( source );
		}
		if ( ! qahm.filterMediaAry.includes( media ) ) {
			qahm.filterMediaAry.push( media );
		}
		if ( ! qahm.filterCampaignAry.includes( campaign ) ) {
			qahm.filterCampaignAry.push( campaign );
		}
	}
	qahm.filterGoalAry.push( qahml10n['goal_achieved'] );
	qahm.filterGoalAry.push( qahml10n['goal_not_achieved'] );

	//outputArray[0][5] = totalExitNumSum; // 合計を更新
	//return outputArray;
}

qahm.convertUrlParamToAry = function( sourceParam, mediaParam, campaignParam, goalParam ) {
	// カンマ区切りの文字列を配列に変換
	if (sourceParam) {
		qahm.urlSourceAry = sourceParam.split(',');
	}
	if (mediaParam) {
		qahm.urlMediaAry = mediaParam.split(',');
	}
	if (campaignParam) {
		qahm.urlCampaignAry = campaignParam.split(',');
	}
	if (goalParam) {
		qahm.urlGoalAry = goalParam.split(',');
		for (let goalIdx = 0; goalIdx < qahm.urlGoalAry.length; goalIdx++) {
			// if (qahm.urlGoalAry[goalIdx] === 'true') {
			// 	qahm.urlGoalAry[goalIdx] = '○';
			// } else if (qahm.urlGoalAry[goalIdx] === 'false') {
			// 	qahm.urlGoalAry[goalIdx] = '×';
			// }
			if (qahm.urlGoalAry[goalIdx] == 1) {
			 	qahm.urlGoalAry[goalIdx] = '○';
			} else if (qahm.urlGoalAry[goalIdx] == 0) {
			 	qahm.urlGoalAry[goalIdx] = '×';
			}
		}
	}
};

qahm.convertFilterKeyAry = function() {

	let resAry = [];
	for ( let keyIdx = 0; keyIdx < qahm.filterKeyAry.length; keyIdx++ ) {
		const key = qahm.filterKeyAry[keyIdx];
		const keySplit = key.split('_');
		const source   = keySplit[1];
		const media    = keySplit[0];
		const campaign = keySplit[2];
		const goal     = keySplit[3];

		// 参照元のチェック
		if ( qahm.urlSourceAry.length > 0 && ! qahm.urlSourceAry.includes( source ) ) {
			continue;
		}
		// メディアのチェック
		if ( qahm.urlMediaAry.length > 0 && ! qahm.urlMediaAry.includes( media ) ) {
			continue;
		}
		// メディアのチェック
		if ( qahm.urlCampaignAry.length > 0 && ! qahm.urlCampaignAry.includes( campaign ) ) {
			continue;
		}
		// 目標のチェック
		if ( qahm.urlGoalAry.length > 0 && ! qahm.urlGoalAry.includes( goal ) ) {
			continue;
		}
		resAry.push( key );
	}

	qahm.filterKeyAry = resAry;

	for (let goalIdx = 0; goalIdx < qahm.urlGoalAry.length; goalIdx++) {
		if (qahm.urlGoalAry[goalIdx] === '○') {
			qahm.urlGoalAry[goalIdx] = qahml10n['goal_achieved'];
		} else if (qahm.urlGoalAry[goalIdx] === '×') {
			qahm.urlGoalAry[goalIdx] = qahml10n['goal_not_achieved'];
		}
	}
};


qahm.updateDataNum = function() {
	for ( let filterIdx = 0; filterIdx < qahm.filterKeyAry.length; filterIdx++ ) {
		const key = qahm.filterKeyAry[filterIdx];

		// データ数
		if ( qahm.sepDataNumAry && qahm.sepDataNumAry.hasOwnProperty(key) ) {
			qahm.dataNum += qahm.sepDataNumAry[key];
		}

		// 合計滞在時間
		if ( qahm.sepTotalStayTimeAry && qahm.sepTotalStayTimeAry.hasOwnProperty(key) ) {
			qahm.totalStayTime += qahm.sepTotalStayTimeAry[key];
		}
	}

	// 合計滞在時間をデータ数で割って平均滞在時間を算出
	if (qahm.dataNum > 0) {
		qahm.avgStayTime = Math.round(qahm.totalStayTime / qahm.dataNum);
	}

	let elem = document.getElementById("heatmap-bar-data-num-value");
	elem.textContent = qahm.dataNum;
	elem = document.getElementById("heatmap-bar-avg-time-on-page-value");
	elem.textContent = formatSeconds( qahm.avgStayTime );

	// データ数が0の場合、フラグをtrueにしてロード画面を解除
	if (qahm.dataNum === 0) {
		qahm.hasShownNoDataAlert = true;
		
		// ロード画面を解除
		if (typeof qahm.hideLoadIcon === 'function') {
			qahm.hideLoadIcon();
		}
	}

	function formatSeconds(seconds) {
		// 分を計算
		const minutes = Math.floor(seconds / 60);
		// 残りの秒を計算
		const remainingSeconds = seconds % 60;

		// 分を2桁の文字列に変換
		const formattedMinutes = minutes.toString().padStart(2, '0');
		// 秒を2桁の文字列に変換
		const formattedSeconds = remainingSeconds.toString().padStart(2, '0');

		// フォーマットされた文字列を返す
		return `${formattedMinutes}:${formattedSeconds}`;
	}
};


/**
 * Merges data from qahm.separateData into qahm.mergeX based on the provided keys.
 *
 * @param {Array} keys - The keys to be used for merging data.
 */
qahm.filterMergeData = function() {
	let keys = qahm.filterKeyAry;

	// If the keys array includes the string '合計', return without doing anything
	/*
	if (keys.includes('合計')) {
		qahm.dataNum = qahm.totalValues.dataNum;
		qahm.mergeC = qahm.totalValues.mergeC;
		qahm.mergeASV2 = qahm.totalValues.mergeASV2;
		return;
	}
	*/

	// Initialize work variables
	let workMergeC = [];
	let workMergeASV2 = [];
	let workDataNum = 0;

	// Iterate over each key
	keys.forEach(function(key) {
		// Check if the key exists in qahm.separateData.merge_c
		if (qahm.separateData.merge_c && qahm.separateData.merge_c.hasOwnProperty(key)) {
			// Merge the data from qahm.separateData.merge_c into workMergeC
			workMergeC.push( ...qahm.separateData.merge_c[key] );
		}

		// Check if the key exists in qahm.separateData.merge_as
		if (qahm.separateData.merge_as && qahm.separateData.merge_as.hasOwnProperty(key)) {
			// Iterate over each STAY_HEIGHT in the data for the key
			for (let stayHeight in qahm.separateData.merge_as[key]) {
				// Check if the STAY_HEIGHT exists in workMergeASV2
				if (!workMergeASV2.hasOwnProperty(stayHeight)) {
					// If not, initialize it with the same structure as in qahm.separateData.merge_as
					workMergeASV2[stayHeight] = [stayHeight, 0, 0, 0];
				}

				// Add the STAY_TIME, STAY_NUM, and EXIT_NUM from qahm.separateData.merge_as to workMergeASV2
				workMergeASV2[stayHeight][1] += qahm.separateData.merge_as[key][stayHeight][1];
				workMergeASV2[stayHeight][2] += qahm.separateData.merge_as[key][stayHeight][2];
				workMergeASV2[stayHeight][3] += qahm.separateData.merge_as[key][stayHeight][3];

				// Add the EXIT_NUM to workDataNum
				//workDataNum += qahm.separateData.merge_as[key][stayHeight][3];
			}
		}
	});
	//
	for (let stayHeight in workMergeASV2) {
		if (workMergeASV2[stayHeight][2] === 0) {
			continue;
		}
		workMergeASV2[stayHeight][1] = Math.round(workMergeASV2[stayHeight][1] / workMergeASV2[stayHeight][2]);
	}
	// Set the work variables to the qahm properties at the end
	qahm.mergeC = workMergeC ? Object.values(workMergeC) : null;
	qahm.mergeASV2 = workMergeASV2 ? Object.values(workMergeASV2) : null;
	//qahm.dataNum = workDataNum;
};

// QA ZERO END

qahm.updatePageVersion = function() {
	if (!confirm(qahml10n.confirm_version_update)) {
		return;
	}
	
	const $button = jQuery('#heatmap-bar-version-update-button');
	const originalText = $button.text();
	$button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> ' + qahml10n.updating);
	
	jQuery.ajax({
		type: 'POST',
		url: qahm.ajax_url,
		dataType: 'json',
		data: {
			action: 'qahm_ajax_update_page_version',
			page_id: qahm.page_id,
			nonce: qahm.nonce_api
		}
	})
	.done(function(response) {
		if (response.success) {
			let message = qahml10n.version_update_success + '\n\n';
			for (const [device, version] of Object.entries(response.results)) {
				if (version) {
					message += device + ': Ver.' + version + '\n';
				} else {
					message += device + ': ' + qahml10n.failed + '\n';
				}
			}
			alert(message);
			
			location.reload();
		} else {
			alert(qahml10n.version_update_failed + '\n' + (response.message || ''));
		}
	})
	.fail(function(jqXHR, textStatus, errorThrown) {
		alert(qahml10n.version_update_error + '\n' + textStatus);
		console.error('AJAX Error:', textStatus, errorThrown);
	})
	.always(function() {
		$button.prop('disabled', false).text(originalText);
	});
};
