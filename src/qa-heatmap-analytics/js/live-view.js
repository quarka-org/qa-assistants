var qahmz = qahmz || {};
qahmz.liveView = qahmz.liveView || {};

qahmz.liveView.BLOCK_HEIGHT = 100;
qahmz.liveView.BLOCK_AVG_STAY_TIME = 0;
qahmz.liveView.BLOCK_TOTAL_STAY_TIME = 1;
qahmz.liveView.BLOCK_TOTAL_STAY_NUM = 2;
qahmz.liveView.BLOCK_TOTAL_EXIT_NUM = 3;
qahmz.liveView.CANVAS_MARGIN = 2000;

qahmz.liveView.data = null;
qahmz.liveView.originalData = null;
qahmz.liveView.blockAry = null;
qahmz.liveView.blockNum = 0;
qahmz.liveView.isRendering = false;
qahmz.liveView.isRenderingClick = false;
qahmz.liveView.scrollTimer = null;
qahmz.liveView.clickRedrawTimer = null;
qahmz.liveView.CLICK_REDRAW_DELAY = 1000;
qahmz.liveView.prevDocHeight = -1;
qahmz.liveView.originalBodyMarginTop = null;
qahmz.liveView.scrollResizeHandler = null;
qahmz.liveView.resizeHandler = null;

qahmz.liveView.filterKeyAry = [];
qahmz.liveView.filterSourceAry = [];
qahmz.liveView.filterMediaAry = [];
qahmz.liveView.filterCampaignAry = [];
qahmz.liveView.filterGoalAry = [];

qahmz.liveView.fetchData = function(token) {
	return new Promise(function(resolve, reject) {
		var url = qahmz.ajaxurl + '?action=get_live_view_data&token=' + encodeURIComponent(token);
		var xhr = new XMLHttpRequest();
		xhr.open('GET', url, true);
		xhr.onload = function() {
			if (xhr.status === 200) {
				try {
					var resp = JSON.parse(xhr.responseText);
					if (resp.success && resp.data) {
						resolve(resp.data);
					} else {
						reject(resp.data || 'Unknown error');
					}
				} catch (e) {
					reject('Parse error');
				}
			} else {
				reject('HTTP ' + xhr.status);
			}
		};
		xhr.onerror = function() {
			reject('Network error');
		};
		xhr.send();
	});
};

qahmz.liveView.saveToStorage = function(data) {
	var storageKey = 'qa_live_view_' + location.pathname;
	try {
		sessionStorage.setItem(storageKey, JSON.stringify(data));
	} catch (e) {
		// storage full
	}
};

qahmz.liveView.loadFromStorage = function() {
	var storageKey = 'qa_live_view_' + location.pathname;
	var stored = sessionStorage.getItem(storageKey);
	if (stored) {
		try {
			return JSON.parse(stored);
		} catch (e) {
			return null;
		}
	}
	return null;
};

qahmz.liveView.removeTokenFromUrl = function() {
	if (typeof history !== 'undefined' && history.replaceState) {
		var url = new URL(location.href);
		url.searchParams.delete('qa_lv');
		history.replaceState(null, '', url.toString());
	}
};

qahmz.liveView.escapeSelectorString = function(str) {
	var strSplitAry = str.split('>');
	var find = false;
	for (var strIdx = 0; strIdx < strSplitAry.length; strIdx++) {
		var deliStr = '.';
		var deliIdx = strSplitAry[strIdx].indexOf(deliStr);
		if (deliIdx === -1) {
			deliStr = '#';
			deliIdx = strSplitAry[strIdx].indexOf(deliStr);
		}
		if (deliIdx !== -1) {
			var fwdName = strSplitAry[strIdx].substr(0, deliIdx);
			var backName = strSplitAry[strIdx].substr(deliIdx + 1);
			strSplitAry[strIdx] = fwdName + deliStr + CSS.escape(backName);
			find = true;
		}
	}
	if (find) {
		return strSplitAry.join('>');
	} else {
		return str;
	}
};

qahmz.liveView.getAttentionColor = function(avgStayTime) {
	var COLOR_CODE = ["#5e4fa2","#4478b2","#4ba0b1","#72c3a7","#a0d9a3","#ccea9f","#ebf7a6","#fbf8b0","#fee89a","#fdca79","#fba35e","#f3784c","#e1524a","#c42c4a","#9e0142"];
	var colorIdx = Math.round(avgStayTime);
	if (colorIdx >= COLOR_CODE.length) {
		colorIdx = COLOR_CODE.length - 1;
	}
	return COLOR_CODE[colorIdx];
};

qahmz.liveView.getArrayRateValue = function(rateAry, rateVal) {
	if (rateVal <= 0) {
		return Math.min.apply(null, rateAry);
	} else if (rateVal >= 100) {
		return Math.max.apply(null, rateAry);
	}
	var idx = Math.floor((rateAry.length * rateVal / 100) - 0.01);
	return rateAry[idx];
};

qahmz.liveView.createBlockArray = function() {
	var data = qahmz.liveView.data;
	var mergeASV2 = data.merge_as_v2;
	var mergeASV1 = data.merge_as_v1;

	if (!mergeASV2 && !mergeASV1) {
		qahmz.liveView.blockNum = 0;
		qahmz.liveView.blockAry = null;
		return;
	}

	var docHeight = document.documentElement.scrollHeight;
	var blockHeight = qahmz.liveView.BLOCK_HEIGHT;
	qahmz.liveView.blockNum = Math.ceil(docHeight / blockHeight);
	qahmz.liveView.blockAry = [];

	for (var blockIdx = 0; blockIdx < qahmz.liveView.blockNum; blockIdx++) {
		qahmz.liveView.blockAry[blockIdx] = [0, 0, 0, 0];
	}

	if (mergeASV2) {
		for (var mergeIdx = 0; mergeIdx < mergeASV2.length; mergeIdx++) {
			var bIdx = mergeASV2[mergeIdx][data.DATA_ATTENTION_SCROLL_STAY_HEIGHT_V2];
			if (qahmz.liveView.blockNum <= bIdx) {
				break;
			}
			var avgStayTime = mergeASV2[mergeIdx][data.DATA_ATTENTION_SCROLL_STAY_TIME_V2];
			var totalStayNum = mergeASV2[mergeIdx][data.DATA_ATTENTION_SCROLL_STAY_NUM_V2];
			var totalExitNum = mergeASV2[mergeIdx][data.DATA_ATTENTION_SCROLL_EXIT_NUM_V2];
			qahmz.liveView.blockAry[bIdx][qahmz.liveView.BLOCK_TOTAL_STAY_TIME] += avgStayTime * totalStayNum;
			qahmz.liveView.blockAry[bIdx][qahmz.liveView.BLOCK_TOTAL_STAY_NUM] += totalStayNum;
			qahmz.liveView.blockAry[bIdx][qahmz.liveView.BLOCK_TOTAL_EXIT_NUM] += totalExitNum;
		}
	}

	if (mergeASV1) {
		for (var m1Idx = 0; m1Idx < mergeASV1.length; m1Idx++) {
			var percent = mergeASV1[m1Idx][data.DATA_ATTENTION_SCROLL_PERCENT_V1];
			var heightBlock = Math.floor(docHeight * percent / 100 / blockHeight);
			if (qahmz.liveView.blockNum <= heightBlock) {
				heightBlock = qahmz.liveView.blockNum - 1;
			}
			var v1StayTime = mergeASV1[m1Idx][data.DATA_ATTENTION_SCROLL_STAY_TIME_V1];
			var v1StayNum = mergeASV1[m1Idx][data.DATA_ATTENTION_SCROLL_STAY_NUM_V1];
			var v1ExitNum = mergeASV1[m1Idx][data.DATA_ATTENTION_SCROLL_EXIT_NUM_V1];
			qahmz.liveView.blockAry[heightBlock][qahmz.liveView.BLOCK_TOTAL_STAY_TIME] += v1StayTime * v1StayNum;
			qahmz.liveView.blockAry[heightBlock][qahmz.liveView.BLOCK_TOTAL_STAY_NUM] += v1StayNum;
			qahmz.liveView.blockAry[heightBlock][qahmz.liveView.BLOCK_TOTAL_EXIT_NUM] += v1ExitNum;
		}
	}

	var attentionLimitTime = 15;
	for (var aIdx = 0; aIdx < qahmz.liveView.blockNum; aIdx++) {
		if (qahmz.liveView.blockAry[aIdx][qahmz.liveView.BLOCK_TOTAL_STAY_NUM] > 0) {
			var avg = qahmz.liveView.blockAry[aIdx][qahmz.liveView.BLOCK_TOTAL_STAY_TIME] / qahmz.liveView.blockAry[aIdx][qahmz.liveView.BLOCK_TOTAL_STAY_NUM];
			if (avg > attentionLimitTime) {
				avg = attentionLimitTime;
			}
			qahmz.liveView.blockAry[aIdx][qahmz.liveView.BLOCK_AVG_STAY_TIME] = avg;
		}
	}
};

qahmz.liveView.processSeparateData = function(separateData) {
	if (!separateData) {
		return;
	}
	var seen = {};
	var collect = function(key) {
		if (!key.includes('_') || seen[key]) {
			return;
		}
		seen[key] = true;
		var parts = key.split('_');
		var media = parts[0];
		var source = parts[1];
		var campaign = parts[2];

		qahmz.liveView.filterKeyAry.push(key);
		if (qahmz.liveView.filterSourceAry.indexOf(source) === -1) {
			qahmz.liveView.filterSourceAry.push(source);
		}
		if (qahmz.liveView.filterMediaAry.indexOf(media) === -1) {
			qahmz.liveView.filterMediaAry.push(media);
		}
		if (qahmz.liveView.filterCampaignAry.indexOf(campaign) === -1) {
			qahmz.liveView.filterCampaignAry.push(campaign);
		}
	};
	if (separateData.merge_as) {
		for (var keyAs in separateData.merge_as) {
			collect(keyAs);
		}
	}
	if (separateData.merge_c) {
		for (var keyC in separateData.merge_c) {
			collect(keyC);
		}
	}
	qahmz.liveView.filterGoalAry = ['\u25CB', '\u00D7'];
};

qahmz.liveView.filterMergeData = function() {
	var separateData = qahmz.liveView.data;
	if (!separateData.separate_merge_c && !separateData.separate_merge_as) {
		return;
	}

	var keys = qahmz.liveView.filterKeyAry;
	var workMergeC = [];
	var workMergeASV2 = [];

	keys.forEach(function(key) {
		if (separateData.separate_merge_c && separateData.separate_merge_c.hasOwnProperty(key)) {
			workMergeC = workMergeC.concat(separateData.separate_merge_c[key]);
		}
		if (separateData.separate_merge_as && separateData.separate_merge_as.hasOwnProperty(key)) {
			for (var stayHeight in separateData.separate_merge_as[key]) {
				if (!workMergeASV2.hasOwnProperty(stayHeight)) {
					workMergeASV2[stayHeight] = [stayHeight, 0, 0, 0];
				}
				workMergeASV2[stayHeight][1] += separateData.separate_merge_as[key][stayHeight][1];
				workMergeASV2[stayHeight][2] += separateData.separate_merge_as[key][stayHeight][2];
				workMergeASV2[stayHeight][3] += separateData.separate_merge_as[key][stayHeight][3];
			}
		}
	});

	for (var sh in workMergeASV2) {
		if (workMergeASV2[sh][2] === 0) {
			continue;
		}
		workMergeASV2[sh][1] = Math.round(workMergeASV2[sh][1] / workMergeASV2[sh][2]);
	}

	qahmz.liveView.data.merge_c = workMergeC.length > 0 ? workMergeC : null;
	qahmz.liveView.data.merge_as_v2 = Object.values(workMergeASV2).length > 0 ? Object.values(workMergeASV2) : null;

	var dataNum = 0;
	for (var ki = 0; ki < keys.length; ki++) {
		if (separateData.separate_data_num && separateData.separate_data_num.hasOwnProperty(keys[ki])) {
			dataNum += separateData.separate_data_num[keys[ki]];
		}
	}
	qahmz.liveView.data.data_num = dataNum;
};

qahmz.liveView.applyFilter = function() {
	var bar = document.getElementById('qa-live-view-bar');
	if (!bar) {
		return;
	}

	var sourceSelect = bar.querySelector('[data-filter="source"]');
	var mediaSelect = bar.querySelector('[data-filter="media"]');
	var campaignSelect = bar.querySelector('[data-filter="campaign"]');
	var goalSelect = bar.querySelector('[data-filter="goal"]');

	var selSource = sourceSelect ? sourceSelect.value : '';
	var selMedia = mediaSelect ? mediaSelect.value : '';
	var selCampaign = campaignSelect ? campaignSelect.value : '';
	var selGoal = goalSelect ? goalSelect.value : '';

	var allKeys = [];
	var origData = qahmz.liveView.originalData;
	var seenKey = {};
	var collectKey = function(key) {
		if (!key.includes('_') || seenKey[key]) {
			return;
		}
		seenKey[key] = true;
		allKeys.push(key);
	};
	if (origData.separate_merge_as) {
		for (var keyAs in origData.separate_merge_as) {
			collectKey(keyAs);
		}
	}
	if (origData.separate_merge_c) {
		for (var keyC in origData.separate_merge_c) {
			collectKey(keyC);
		}
	}

	var filtered = [];
	for (var i = 0; i < allKeys.length; i++) {
		var parts = allKeys[i].split('_');
		var media = parts[0];
		var source = parts[1];
		var campaign = parts[2];
		var goal = parts[3];

		if (selSource && source !== selSource) { continue; }
		if (selMedia && media !== selMedia) { continue; }
		if (selCampaign && campaign !== selCampaign) { continue; }
		if (selGoal && goal !== selGoal) { continue; }
		filtered.push(allKeys[i]);
	}

	qahmz.liveView.filterKeyAry = filtered;
	qahmz.liveView.data.merge_c = qahmz.liveView.originalData.merge_c ? JSON.parse(JSON.stringify(qahmz.liveView.originalData.merge_c)) : null;
	qahmz.liveView.data.merge_as_v2 = qahmz.liveView.originalData.merge_as_v2 ? JSON.parse(JSON.stringify(qahmz.liveView.originalData.merge_as_v2)) : null;
	qahmz.liveView.data.data_num = qahmz.liveView.originalData.data_num;

	if (selSource || selMedia || selCampaign || selGoal) {
		qahmz.liveView.filterMergeData();
	}

	qahmz.liveView.prevDocHeight = -1;
	qahmz.liveView.renderer.updateAllMaps();

	var numEl = document.getElementById('qa-lv-data-num');
	if (numEl) {
		numEl.textContent = qahmz.liveView.data.data_num;
	}
};

qahmz.liveView.renderer = {};

qahmz.liveView.renderer.init = function(data) {
	qahmz.liveView.data = data;
	qahmz.liveView.originalData = JSON.parse(JSON.stringify(data));
	qahmz.liveView.processSeparateData({merge_as: data.separate_merge_as, merge_c: data.separate_merge_c});
	qahmz.liveView.createBlockArray();
	qahmz.liveView.renderer.createOverlayContainer();
	qahmz.liveView.renderer.createControlBar();
	qahmz.liveView.renderer.updateAllMaps();
	qahmz.liveView.renderer.bindScrollUpdate();
};

qahmz.liveView.renderer.createOverlayContainer = function() {
	var container = document.createElement('div');
	container.id = 'qa-lv-overlay-container';
	container.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:' + document.documentElement.scrollHeight + 'px;z-index:2147483646;pointer-events:none;';

	var scrollMap = document.createElement('div');
	scrollMap.id = 'qa-lv-scroll-map';
	scrollMap.style.cssText = 'position:absolute;top:0;left:0;width:100%;display:none;pointer-events:none;';

	var attentionMap = document.createElement('div');
	attentionMap.id = 'qa-lv-attention-map';
	attentionMap.style.cssText = 'position:absolute;top:0;left:0;width:100%;display:none;pointer-events:none;';

	var clickHeat = document.createElement('div');
	clickHeat.id = 'qa-lv-click-heat';
	clickHeat.style.cssText = 'position:absolute;top:0;left:0;width:100%;display:none;pointer-events:none;';

	var clickCount = document.createElement('div');
	clickCount.id = 'qa-lv-click-count';
	clickCount.style.cssText = 'position:absolute;top:0;left:0;width:100%;display:none;pointer-events:none;';

	container.appendChild(scrollMap);
	container.appendChild(attentionMap);
	container.appendChild(clickHeat);
	container.appendChild(clickCount);
	document.body.appendChild(container);
};

qahmz.liveView.renderer.createControlBar = function() {
	var bar = document.createElement('div');
	bar.id = 'qa-live-view-bar';
	bar.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:2147483647;background:rgba(0,0,0,0.9);color:#fff;padding:8px 16px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;font-size:14px;display:flex;align-items:center;gap:16px;box-sizing:border-box;';

	var title = document.createElement('span');
	title.textContent = 'QA Live View';
	title.style.cssText = 'font-weight:bold;white-space:nowrap;';

	var closeBtn = document.createElement('button');
	closeBtn.innerHTML = '&times;';
	closeBtn.title = 'Close';
	closeBtn.style.cssText = 'background:none;border:none;color:#fff;font-size:20px;cursor:pointer;padding:0 8px;';
	closeBtn.addEventListener('click', function() {
		qahmz.liveView.renderer.destroy();
	});

	var controls = document.createElement('div');
	controls.style.cssText = 'display:flex;gap:16px;flex-wrap:wrap;';

	var maps = [
		{key: 'scroll', label: 'Scroll Map', checked: false},
		{key: 'attention', label: 'Attention Map', checked: false},
		{key: 'click-heat', label: 'Click Heat', checked: false},
		{key: 'click-count', label: 'Click Count', checked: false}
	];

	for (var i = 0; i < maps.length; i++) {
		var label = document.createElement('label');
		label.style.cssText = 'cursor:pointer;display:flex;align-items:center;gap:4px;white-space:nowrap;';
		var checkbox = document.createElement('input');
		checkbox.type = 'checkbox';
		checkbox.setAttribute('data-map', maps[i].key);
		checkbox.checked = maps[i].checked;
		checkbox.addEventListener('change', qahmz.liveView.renderer.onMapToggle);
		var span = document.createElement('span');
		span.textContent = maps[i].label;
		label.appendChild(checkbox);
		label.appendChild(span);
		controls.appendChild(label);
	}

	var filterContainer = document.createElement('div');
	filterContainer.id = 'qa-lv-filters';
	filterContainer.style.cssText = 'display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-left:auto;';

	var hasFilters = qahmz.liveView.filterSourceAry.length > 0 ||
		qahmz.liveView.filterMediaAry.length > 0 ||
		qahmz.liveView.filterCampaignAry.length > 0;

	if (hasFilters) {
		var filters = [
			{key: 'source', label: 'Source', options: qahmz.liveView.filterSourceAry},
			{key: 'media', label: 'Media', options: qahmz.liveView.filterMediaAry},
			{key: 'campaign', label: 'Campaign', options: qahmz.liveView.filterCampaignAry},
			{key: 'goal', label: 'Goal', options: qahmz.liveView.filterGoalAry}
		];

		for (var fi = 0; fi < filters.length; fi++) {
			if (filters[fi].options.length === 0) {
				continue;
			}
			var select = document.createElement('select');
			select.setAttribute('data-filter', filters[fi].key);
			select.style.cssText = 'padding:2px 4px;font-size:12px;border-radius:3px;border:1px solid #555;background:#333;color:#fff;max-width:120px;';
			var allOpt = document.createElement('option');
			allOpt.value = '';
			allOpt.textContent = filters[fi].label + ': All';
			select.appendChild(allOpt);
			for (var oi = 0; oi < filters[fi].options.length; oi++) {
				var opt = document.createElement('option');
				opt.value = filters[fi].options[oi];
				opt.textContent = filters[fi].options[oi];
				select.appendChild(opt);
			}
			select.addEventListener('change', qahmz.liveView.applyFilter);
			filterContainer.appendChild(select);
		}
	}

	var dataNumSpan = document.createElement('span');
	dataNumSpan.style.cssText = 'font-size:12px;white-space:nowrap;';
	dataNumSpan.innerHTML = 'PV: <span id="qa-lv-data-num">' + (qahmz.liveView.data ? qahmz.liveView.data.data_num : 0) + '</span>';
	filterContainer.appendChild(dataNumSpan);

	bar.appendChild(title);
	bar.appendChild(closeBtn);
	bar.appendChild(controls);
	bar.appendChild(filterContainer);
	document.body.appendChild(bar);

	qahmz.liveView.originalBodyMarginTop = document.body.style.marginTop;
	document.body.style.marginTop = (bar.offsetHeight) + 'px';
};

qahmz.liveView.renderer.onMapToggle = function() {
	var mapKey = this.getAttribute('data-map');
	var checked = this.checked;
	var mapIds = {
		'scroll': 'qa-lv-scroll-map',
		'attention': 'qa-lv-attention-map',
		'click-heat': 'qa-lv-click-heat',
		'click-count': 'qa-lv-click-count'
	};
	var el = document.getElementById(mapIds[mapKey]);
	if (el) {
		el.style.display = checked ? 'block' : 'none';
		if (checked) {
			qahmz.liveView.renderer.updateAllMaps();
		}
	}
};

qahmz.liveView.renderer.updateAllMaps = function(opts) {
	if (qahmz.liveView.isRendering) {
		return;
	}
	qahmz.liveView.isRendering = true;
	var skipClick = !!(opts && opts.skipClick);

	var docHeight = document.documentElement.scrollHeight;
	if (docHeight !== qahmz.liveView.prevDocHeight) {
		qahmz.liveView.prevDocHeight = docHeight;
		qahmz.liveView.createBlockArray();
		var container = document.getElementById('qa-lv-overlay-container');
		if (container) {
			container.style.height = docHeight + 'px';
		}
	}

	var bar = document.getElementById('qa-live-view-bar');
	var checkboxes = bar ? bar.querySelectorAll('input[type="checkbox"]') : [];

	for (var i = 0; i < checkboxes.length; i++) {
		if (!checkboxes[i].checked) {
			continue;
		}
		var mapKey = checkboxes[i].getAttribute('data-map');
		switch (mapKey) {
			case 'scroll':
				qahmz.liveView.renderer.renderScrollMap();
				break;
			case 'attention':
				qahmz.liveView.renderer.renderAttentionMap();
				break;
			case 'click-heat':
				if (!skipClick) {
					qahmz.liveView.renderer.renderClickHeatMap();
				}
				break;
			case 'click-count':
				if (!skipClick) {
					qahmz.liveView.renderer.renderClickCountMap();
				}
				break;
		}
	}

	qahmz.liveView.isRendering = false;
};

qahmz.liveView.renderer.renderClickOnly = function() {
	if (qahmz.liveView.isRenderingClick) {
		return;
	}
	qahmz.liveView.isRenderingClick = true;
	var bar = document.getElementById('qa-live-view-bar');
	var checkboxes = bar ? bar.querySelectorAll('input[type="checkbox"]') : [];
	for (var i = 0; i < checkboxes.length; i++) {
		if (!checkboxes[i].checked) {
			continue;
		}
		var mapKey = checkboxes[i].getAttribute('data-map');
		if (mapKey === 'click-heat') {
			qahmz.liveView.renderer.renderClickHeatMap();
		} else if (mapKey === 'click-count') {
			qahmz.liveView.renderer.renderClickCountMap();
		}
	}
	qahmz.liveView.isRenderingClick = false;
};

qahmz.liveView.renderer.bindScrollUpdate = function() {
	qahmz.liveView.scrollResizeHandler = function() {
		if (qahmz.liveView.scrollTimer) {
			clearTimeout(qahmz.liveView.scrollTimer);
		}
		if (qahmz.liveView.clickRedrawTimer) {
			clearTimeout(qahmz.liveView.clickRedrawTimer);
			qahmz.liveView.clickRedrawTimer = null;
		}
		qahmz.liveView.scrollTimer = setTimeout(function() {
			qahmz.liveView.renderer.updateAllMaps({skipClick: true});
		}, 200);
		qahmz.liveView.clickRedrawTimer = setTimeout(function() {
			qahmz.liveView.clickRedrawTimer = null;
			qahmz.liveView.renderer.renderClickOnly();
		}, qahmz.liveView.CLICK_REDRAW_DELAY);
	};
	qahmz.liveView.resizeHandler = function() {
		if (qahmz.liveView.scrollTimer) {
			clearTimeout(qahmz.liveView.scrollTimer);
		}
		if (qahmz.liveView.clickRedrawTimer) {
			clearTimeout(qahmz.liveView.clickRedrawTimer);
			qahmz.liveView.clickRedrawTimer = null;
		}
		qahmz.liveView.scrollTimer = setTimeout(function() {
			qahmz.liveView.renderer.updateAllMaps();
		}, 200);
	};
	window.addEventListener('scroll', qahmz.liveView.scrollResizeHandler, {passive: true});
	window.addEventListener('resize', qahmz.liveView.resizeHandler, {passive: true});
};

qahmz.liveView.renderer.renderScrollMap = function() {
	var target = document.getElementById('qa-lv-scroll-map');
	if (!target || !qahmz.liveView.blockAry) {
		return;
	}

	var docHeight = document.documentElement.scrollHeight;
	var blockHeight = qahmz.liveView.BLOCK_HEIGHT;
	var colorCode = ["rgba(158,1,66,1)","rgba(186,33,72,1)","rgba(211,62,75,1)","rgba(230,90,73,1)","rgba(242,118,75,1)","rgba(249,150,87,1)","rgba(252,180,105,1)","rgba(254,207,126,1)","rgba(254,229,151,1)","rgba(253,244,171,1)","rgba(247,250,175,1)","rgba(232,246,164,1)","rgba(210,237,158,1)","rgba(179,224,161,1)","rgba(145,211,164,1)","rgba(111,193,168,1)","rgba(82,169,175,1)","rgba(66,139,181,1)","rgba(73,109,175,1)","rgba(94,79,162,0)"];
	var opacity = 0.7;

	var scrollRateAry = [];
	for (var i = 0; i < qahmz.liveView.blockAry.length; i++) {
		var exitNum = qahmz.liveView.blockAry[i][qahmz.liveView.BLOCK_TOTAL_EXIT_NUM];
		if (exitNum === 0) {
			continue;
		}
		for (var j = 0; j < exitNum; j++) {
			scrollRateAry.push(i * blockHeight);
		}
	}

	if (scrollRateAry.length === 0) {
		target.innerHTML = '';
		return;
	}

	var scrollMapAry = [];
	scrollMapAry.push({
		heightTop: 0,
		heightBottom: qahmz.liveView.getArrayRateValue(scrollRateAry, 5),
		colorTop: colorCode[0],
		colorBottom: colorCode[1],
		line: 100
	});

	for (var si = 1; si <= 19; si++) {
		var prevMap = scrollMapAry[scrollMapAry.length - 1];
		var hTop = prevMap.heightBottom;
		var hBottom = qahmz.liveView.getArrayRateValue(scrollRateAry, (si + 1) * 5);
		if (hTop === hBottom) {
			continue;
		}
		scrollMapAry.push({
			heightTop: hTop,
			heightBottom: hBottom,
			colorTop: prevMap.colorBottom,
			colorBottom: colorCode[si],
			line: 100 - si * 5
		});
	}

	var html = '';
	for (var mi = 0; mi < scrollMapAry.length; mi++) {
		var nowVal = scrollMapAry[mi].line;
		var nextVal = 0;
		if (mi + 1 < scrollMapAry.length) {
			nextVal = scrollMapAry[mi + 1].line;
		}

		var clipPadding = 5;
		var clipMargin = clipPadding + 10;
		var clipPath1 = (0 - clipPadding + (100 - nowVal) / 2) * (100 - clipMargin * 2) / 100 + clipMargin;
		var clipPath2 = (100 + clipPadding - (100 - nowVal) / 2) * (100 - clipMargin * 2) / 100 + clipMargin;
		var clipPath3 = (100 + clipPadding - (100 - nextVal) / 2) * (100 - clipMargin * 2) / 100 + clipMargin;
		var clipPath4 = (0 - clipPadding + (100 - nextVal) / 2) * (100 - clipMargin * 2) / 100 + clipMargin;

		var sHeight = scrollMapAry[mi].heightBottom - scrollMapAry[mi].heightTop;
		var cTop = scrollMapAry[mi].colorTop;
		var cBottom = scrollMapAry[mi].colorBottom;
		if (mi === scrollMapAry.length - 1) {
			cBottom = colorCode[colorCode.length - 1];
		}

		html += '<div style="height:' + sHeight + 'px;background:linear-gradient(' + cTop + ',' + cBottom + ');opacity:' + opacity + ';width:100%;text-align:center;clip-path:polygon(' + clipPath1 + '% 0%,' + clipPath2 + '% 0%,' + clipPath3 + '% 100%,' + clipPath4 + '% 100%);color:#fff;font-size:14px;font-weight:bold;line-height:' + sHeight + 'px;">' + nowVal + '%</div>';
	}

	target.innerHTML = html;
	target.style.height = docHeight + 'px';
};

qahmz.liveView.renderer.renderAttentionMap = function() {
	var target = document.getElementById('qa-lv-attention-map');
	if (!target || !qahmz.liveView.blockAry) {
		return;
	}

	var docHeight = document.documentElement.scrollHeight;
	var blockHeight = qahmz.liveView.BLOCK_HEIGHT;
	var html = '';
	var colorTop = qahmz.liveView.getAttentionColor(0);
	var colorBottom = '';
	var height = blockHeight;
	var opacity = 0.7;

	for (var blockIdx = 0; blockIdx < qahmz.liveView.blockNum; blockIdx++) {
		colorBottom = qahmz.liveView.getAttentionColor(qahmz.liveView.blockAry[blockIdx][qahmz.liveView.BLOCK_AVG_STAY_TIME]);

		if (blockIdx === qahmz.liveView.blockNum - 1) {
			height = docHeight % blockHeight;
			if (height === 0) {
				height = blockHeight;
			}
		}
		html += '<div style="height:' + height + 'px;background:linear-gradient(' + colorTop + ',' + colorBottom + ');opacity:' + opacity + ';color:#fff;"></div>';
		colorTop = colorBottom;
	}

	target.innerHTML = html;
	target.style.height = docHeight + 'px';
};

qahmz.liveView.renderer.renderClickHeatMap = function() {
	var target = document.getElementById('qa-lv-click-heat');
	if (!target || !qahmz.liveView.data || !qahmz.liveView.data.merge_c) {
		return;
	}

	var data = qahmz.liveView.data;
	var mergeC = data.merge_c;
	var docHeight = document.documentElement.scrollHeight;

	var canvasTop = window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
	var canvasBottom = window.innerHeight + canvasTop;
	canvasTop -= qahmz.liveView.CANVAS_MARGIN;
	if (canvasTop < 0) {
		canvasTop = 0;
	}
	canvasBottom += qahmz.liveView.CANVAS_MARGIN;
	if (canvasBottom > docHeight) {
		canvasBottom = docHeight;
	}

	var selectorOffsAry = [];
	var points = [];

	for (var i = 0; i < mergeC.length; i++) {
		var selName = mergeC[i][data.DATA_HEATMAP_SELECTOR_NAME];
		var offs;

		if (selectorOffsAry[selName]) {
			offs = selectorOffsAry[selName];
		} else {
			var escName = qahmz.liveView.escapeSelectorString(selName);
			var sel = document.querySelector(escName);
				if (sel === null) {
					if (typeof console !== 'undefined' && console.warn) {
						console.warn('QA Live View: selector not found: ' + selName);
					}
					continue;
				}
				var bounds = sel.getBoundingClientRect();
				offs = {
					top: bounds.top + (window.pageYOffset || document.documentElement.scrollTop || 0),
					left: bounds.left + (window.pageXOffset || document.documentElement.scrollLeft || 0)
				};
				selectorOffsAry[selName] = offs;
		}

		var x = mergeC[i][data.DATA_HEATMAP_SELECTOR_X];
		var y = mergeC[i][data.DATA_HEATMAP_SELECTOR_Y];
		x += offs.left;
		y += offs.top;

		if (canvasTop > y || canvasBottom < y) {
			continue;
		}

		y -= canvasTop;
		points.push({x: x, y: y, value: 1});
	}

	var height = canvasBottom - canvasTop;

	target.innerHTML = '';
	var wrapper = document.createElement('div');
	wrapper.id = 'qa-lv-click-heat-inner';
	wrapper.style.cssText = 'position:absolute;top:' + canvasTop + 'px;height:' + height + 'px;width:100%;';
	target.appendChild(wrapper);

	if (typeof h337 !== 'undefined') {
		var heatmapInstance = h337.create({
			container: wrapper,
			radius: 40
		});
		heatmapInstance.setData({max: 2, data: points});
		wrapper.style.position = 'absolute';
	} else {
		var script = document.createElement('script');
		script.src = qahmz.ajaxurl.replace(/qahm-ajax\.php.*$/, 'js/lib/heatmap/heatmap.min.js');
		script.onload = function() {
			var inst = h337.create({
				container: wrapper,
				radius: 40
			});
			inst.setData({max: 2, data: points});
			wrapper.style.position = 'absolute';
		};
		document.head.appendChild(script);
	}
};

qahmz.liveView.renderer.renderClickCountMap = function() {
	var target = document.getElementById('qa-lv-click-count');
	if (!target || !qahmz.liveView.data || !qahmz.liveView.data.merge_c) {
		return;
	}

	var data = qahmz.liveView.data;
	var mergeC = data.merge_c;
	var docHeight = document.documentElement.scrollHeight;

	var canvasTop = window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
	var canvasBottom = window.innerHeight + canvasTop;
	canvasTop -= qahmz.liveView.CANVAS_MARGIN;
	if (canvasTop < 0) {
		canvasTop = 0;
	}
	canvasBottom += qahmz.liveView.CANVAS_MARGIN;
	if (canvasBottom > docHeight) {
		canvasBottom = docHeight;
	}

	var CLICK_SEL_NUM = 0;
	var CLICK_SEL_NAME = 1;
	var CLICK_SEL_X = 2;
	var CLICK_SEL_Y = 3;
	var CLICK_SEL_W = 4;
	var CLICK_SEL_H = 5;

	var points = [];
	var targetTagAry = ['a', 'input', 'button', 'textarea'];

	for (var i = 0; i < mergeC.length; i++) {
		var find = false;
		var selName = mergeC[i][data.DATA_HEATMAP_SELECTOR_NAME];
		var selTagAry = selName.split('>');

		for (var j = 0; j < selTagAry.length; j++) {
			for (var k = 0; k < targetTagAry.length; k++) {
				if (selTagAry[j].indexOf(targetTagAry[k]) !== 0) {
					continue;
				}
				if (selTagAry[j].length === targetTagAry[k].length ||
					selTagAry[j].indexOf(targetTagAry[k] + '#') === 0 ||
					selTagAry[j].indexOf(targetTagAry[k] + ':') === 0) {
					find = true;
					break;
				}
			}
			if (find) {
				break;
			}
		}
		if (!find) {
			continue;
		}

		var pIdx = -1;
		for (var pj = 0; pj < points.length; pj++) {
			if (mergeC[i][data.DATA_HEATMAP_SELECTOR_NAME] === points[pj][CLICK_SEL_NAME]) {
				pIdx = pj;
				break;
			}
		}

		if (pIdx === -1) {
			var escName = qahmz.liveView.escapeSelectorString(mergeC[i][data.DATA_HEATMAP_SELECTOR_NAME]);
			var sel = document.querySelector(escName);
			if (sel === null) {
				continue;
			}
			if (sel.offsetWidth === 0 || sel.offsetHeight === 0) {
				continue;
			}

			var bounds = sel.getBoundingClientRect();
			var offsLeft = bounds.left + (window.pageXOffset || document.documentElement.scrollLeft || 0);
			var offsTop = bounds.top + (window.pageYOffset || document.documentElement.scrollTop || 0);
			if (canvasTop > offsTop || canvasBottom < offsTop + sel.offsetHeight) {
				continue;
			}

			points.push([
				1,
				mergeC[i][data.DATA_HEATMAP_SELECTOR_NAME],
				offsLeft,
				offsTop - canvasTop,
				sel.offsetWidth,
				sel.offsetHeight
			]);
		} else {
			points[pIdx][CLICK_SEL_NUM]++;
		}
	}

	var width = document.body.clientWidth;
	var height = canvasBottom - canvasTop;

	target.innerHTML = '';
	var canvasWrapper = document.createElement('div');
	canvasWrapper.style.cssText = 'position:absolute;top:' + canvasTop + 'px;height:' + height + 'px;width:100%;line-height:0px;';
	var canvas = document.createElement('canvas');
	canvas.width = width;
	canvas.height = height;
	canvas.style.cssText = 'position:absolute;left:0;top:0;';
	canvasWrapper.appendChild(canvas);
	target.appendChild(canvasWrapper);

	var ctx = canvas.getContext('2d');
	for (var pi = 0; pi < points.length; pi++) {
		var fillColor = [0, 0, 0, 0.8];

		var elSel = document.querySelector(qahmz.liveView.escapeSelectorString(points[pi][CLICK_SEL_NAME]));
		if (elSel) {
			var stylePosition = getComputedStyle(elSel).position;
			if (stylePosition === 'fixed' || stylePosition === 'absolute') {
				fillColor[2] = 255;
			} else {
				var parent = elSel.parentElement;
				while (parent) {
					var ps = getComputedStyle(parent).position;
					if (ps === 'fixed' || ps === 'absolute') {
						fillColor[2] = 255;
						break;
					}
					parent = parent.parentElement;
				}
			}
		}

		ctx.beginPath();
		ctx.lineWidth = 5;
		ctx.strokeStyle = 'rgba(255,200,200,1.0)';
		ctx.strokeRect(
			points[pi][CLICK_SEL_X],
			points[pi][CLICK_SEL_Y],
			points[pi][CLICK_SEL_W],
			points[pi][CLICK_SEL_H]
		);

		ctx.beginPath();
		ctx.fillStyle = 'rgba(' + fillColor.join(', ') + ')';
		ctx.fillRect(
			points[pi][CLICK_SEL_X],
			points[pi][CLICK_SEL_Y],
			points[pi][CLICK_SEL_W],
			points[pi][CLICK_SEL_H]
		);

		ctx.beginPath();
		ctx.font = 'bold 22px serif';
		ctx.fillStyle = 'white';
		ctx.textAlign = 'center';
		ctx.textBaseline = 'middle';
		ctx.fillText(
			points[pi][CLICK_SEL_NUM],
			points[pi][CLICK_SEL_X] + points[pi][CLICK_SEL_W] / 2,
			points[pi][CLICK_SEL_Y] + points[pi][CLICK_SEL_H] / 2
		);
	}
};

qahmz.liveView.renderer.destroy = function() {
	if (qahmz.liveView.scrollResizeHandler) {
		window.removeEventListener('scroll', qahmz.liveView.scrollResizeHandler);
		qahmz.liveView.scrollResizeHandler = null;
	}
	if (qahmz.liveView.resizeHandler) {
		window.removeEventListener('resize', qahmz.liveView.resizeHandler);
		qahmz.liveView.resizeHandler = null;
	}
	if (qahmz.liveView.scrollTimer) {
		clearTimeout(qahmz.liveView.scrollTimer);
		qahmz.liveView.scrollTimer = null;
	}
	if (qahmz.liveView.clickRedrawTimer) {
		clearTimeout(qahmz.liveView.clickRedrawTimer);
		qahmz.liveView.clickRedrawTimer = null;
	}
	var bar = document.getElementById('qa-live-view-bar');
	if (bar) {
		bar.parentNode.removeChild(bar);
	}
	var container = document.getElementById('qa-lv-overlay-container');
	if (container) {
		container.parentNode.removeChild(container);
	}
	document.body.style.marginTop = qahmz.liveView.originalBodyMarginTop !== null ? qahmz.liveView.originalBodyMarginTop : '';
	var storageKey = 'qa_live_view_' + location.pathname;
	sessionStorage.removeItem(storageKey);
};

qahmz.liveView.showLoading = function() {
	var overlay = document.createElement('div');
	overlay.id = 'qa-lv-loading';
	overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;z-index:2147483647;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;';
	var spinner = document.createElement('div');
	spinner.style.cssText = 'color:#fff;font-size:18px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;';
	spinner.textContent = 'Loading heatmap data...';
	overlay.appendChild(spinner);
	document.body.appendChild(overlay);
};

qahmz.liveView.hideLoading = function() {
	var overlay = document.getElementById('qa-lv-loading');
	if (overlay) {
		overlay.parentNode.removeChild(overlay);
	}
};

qahmz.liveView.showError = function(msg) {
	qahmz.liveView.hideLoading();
	var overlay = document.createElement('div');
	overlay.id = 'qa-lv-error';
	overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:2147483647;background:rgba(200,0,0,0.9);color:#fff;padding:12px 16px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;font-size:14px;text-align:center;';
	overlay.textContent = 'QA Live View: ' + msg;
	document.body.appendChild(overlay);
	setTimeout(function() {
		var el = document.getElementById('qa-lv-error');
		if (el) {
			el.parentNode.removeChild(el);
		}
	}, 5000);
};

qahmz.liveView.start = function(token) {
	if (token) {
		qahmz.liveView.showLoading();
		qahmz.liveView.removeTokenFromUrl();
		qahmz.liveView.fetchData(token)
			.then(function(data) {
				qahmz.liveView.hideLoading();
				qahmz.liveView.saveToStorage(data);
				qahmz.liveView.renderer.init(data);
			})
			.catch(function(err) {
				qahmz.liveView.showError(typeof err === 'string' ? err : 'Failed to fetch data');
			});
	} else {
		var storedData = qahmz.liveView.loadFromStorage();
		if (storedData) {
			qahmz.liveView.renderer.init(storedData);
		}
	}
};
