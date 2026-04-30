var qahmz              = qahmz || {};

qahmz.initBehData      = false;
qahmz.readersName      = null;
qahmz.readersBodyIndex = 0;
qahmz.rawName          = null;
qahmz.speedMsec        = 0;
qahmz.qa_id            = null;

qahmz.isFailAjax    = false;
qahmz.isExcludedIp  = false;
qahmz.initWinW   = window.innerWidth;
qahmz.initWinH   = window.innerHeight;

// QA Assistants の場合は HTML で事前定義済み、QA ZERO の場合はここで設定
qahmz.ajaxurl       = qahmz.ajaxurl || "{ajax_url}";
qahmz.tracking_hash = qahmz.tracking_hash || "{tracking_hash}";

//initリトライの設定
qahmz.maxinitRetries    = 10;
qahmz.initRetryInterval = 3000; //ms

//send失敗フラグ
qahmz.updateMsecFailed  = false;

//QA_ID保存域
qahmz.qa_id = null;

 //cookieを拒否しているかどうか
qahmz.isRejectCookie = qahmz.cookieMode;

qahmz.supportsBeforeUnloadAndSendBeacon = false;
qahmz.supportsBeforeUnload = false;
qahmz.supportsSendBeacon = false;

// qahmのデバッグフラグに応じてログを表示
qahmz.log = function ( msg ) {
	if ( !qahmz.debug ) {
		return;
	}

	// traceログが長いためグループ化
	console.groupCollapsed( msg );
	console.trace();
	console.groupEnd();
	//console.log(msg);
};

// ajax error
qahmz.log_ajax_error = function ( jqXHR, textStatus, errorThrown ) {

	console.groupCollapsed( 'ajax error' );
	console.log( 'jqXHR       : ' + jqXHR.status );
	console.log( 'textStatus  : ' + textStatus );
	console.log( 'errorThrown : ' + errorThrown.message );
	console.trace();
	console.groupEnd();

	/*
		存在しないページにajax通信を行うと、コンソールログに次のように表示されます。
		このケースでは通信先のURLを確認する必要があります。
		jqXHR	404
		textStatus	error
		errorThrown	undefine

		通信先のページで内部エラー
		このケースでは通信先のファイル（PHP）を見直す必要があります。
		jqXHR	500
		textStatus	error
		errorThrown	undefine

		リクエストに入る値が全くの予想外
		ajaxでの設定に不備がある場合は以下のエラー内容となります。
		jqXHR	200
		textStatus	parseerror
		errorThrown	Unexpected token / in JSON at position 0
	*/

};


// cookieが有効か判定（navigator.cookieEnabled を使用）
qahmz.isEnableCookie = function(){
	return navigator.cookieEnabled;
};

// cookie値を連想配列として取得する
qahmz.getCookieArray = function(){
	var arr = new Array();
	if ( document.cookie !== '' ) {
		var tmp = document.cookie.split( '; ' );
		for (var i = 0;i < tmp.length;i++) {
			var data     = tmp[i].split( '=' );
			arr[data[0]] = decodeURIComponent( data[1] );
		}
	}
	return arr;
};

// cookieをセットする
qahmz.setCookie = function(cookie_name, value){

	let name = cookie_name + "=";
	let expires = new Date();
	expires.setTime(expires.getTime() + 60 * 60 * 24 * 365 * 2 * 1000); //有効期限は2年
	let cookie_value = name + value.toString() + ";expires=" + expires.toUTCString() + ";path=/";

	//クロスドメインQA ID共通化対応
	if (qahmz.xdm && qahmz.xdm !== "") {
		cookie_value += ";domain=."+qahmz.xdm; //ドメイン属性の付与
	}

	document.cookie = cookie_value;

}

// cookieを取得する
qahmz.getCookie = function(cookie_name){
	let cookie_ary = qahmz.getCookieArray();

	if(cookie_ary[cookie_name]){
		return cookie_ary[cookie_name];
	}

	return false;
}

// cookieを削除する
qahmz.deleteCookie = function(cookie_name){

    let name = cookie_name+"=";
    document.cookie = name + ";expires=Thu, 01 Jan 1970 00:00:00 UTC;path=/";

    // クロスドメインQA ID共通化対応がある場合、そのドメイン属性も含めて削除
    if (qahmz.xdm && qahmz.xdm !== "") {
        document.cookie = name + ";expires=Thu, 01 Jan 1970 00:00:00 UTC;path=/;domain=." + qahmz.xdm;
    }

}

qahmz.getQaidfromCookie = function(){

	let qa_id_obj = { value: '', is_new_user: 0 };

	let cookie_ary = qahmz.getCookieArray();

	if ( cookie_ary["qa_id_z"] ){
		qa_id_obj.value = cookie_ary["qa_id_z"];
		qa_id_obj.is_new_user = 0; //新規ユーザでない
	} else {
		qa_id_obj.is_new_user = 1; //新規ユーザ
	}

	return qa_id_obj;

}

qahmz.setQaid = function(){

	if( !qahmz.qa_id ){
		return false;
	}
	qahmz.setCookie("qa_id_z",qahmz.qa_id);

	return true;

}

//状況に応じてCookieを更新する
//戻り値：Cookie拒否かどうか
qahmz.updateQaidCookie = function() {

	if( !qahmz.cookieMode ){ //Cookie同意モード以外
		//何もしない
		qahmz.isRejectCookie = false;
		return;
	}

	//同意モード
	if( !qahmz.cookieConsentObject ){ //同意タグがなければすべてのcookie消去
		qahmz.deleteCookie("qa_id_z");
		qahmz.deleteCookie("qa_cookieConsent");
		qahmz.isRejectCookie = true;
		return;
	}

	if( qahmz.getCookie("qa_cookieConsent") == "true" ){
		qahmz.setQaid();
		qahmz.isRejectCookie = false;
	}else{
		qahmz.deleteCookie("qa_id_z");
		qahmz.deleteCookie("qa_cookieConsent");
		qahmz.isRejectCookie = true;
	}

}

qahmz.updateQaidCookie(); 

//init処理
qahmz.init = function() {

	// ビューモードなら計測をスキップ
	if (window.location.search.indexOf('qahm_view_mode=1') !== -1) {
		return;
	}

	try {
		
		if ( ! qahmz.cookieMode && ! qahmz.isEnableCookie() ) {
			throw new Error( 'qa: Measurement failed because cookie is invalid.' );
		}

		qahmz.xhr = new XMLHttpRequest();

		//qa_idの取得
		let qa_id_obj = qahmz.getQaidfromCookie();

		let sendStr = 'action=init_session_data';
		sendStr += '&tracking_hash=' + encodeURIComponent( qahmz.tracking_hash );
		sendStr += '&url=' + encodeURIComponent( location.href );
		sendStr += '&title=' + encodeURIComponent( document.title );
		sendStr += '&referrer=' + encodeURIComponent( document.referrer );
		sendStr += '&country=' + encodeURIComponent( (navigator.userLanguage||navigator.browserLanguage||navigator.language).substr(0,2) );
		if( qa_id_obj.value != '' ){
			sendStr += '&qa_id=' + encodeURIComponent( qa_id_obj.value );
		}
		sendStr += '&is_new_user=' + encodeURIComponent( qa_id_obj.is_new_user );
		sendStr += '&tracking_id=' + encodeURIComponent( qahmz.tracking_id );
		sendStr += '&is_reject=' + encodeURIComponent( qahmz.isRejectCookie );

		qahmz.xhr.open( 'POST', qahmz.ajaxurl, true );

		qahmz.xhr.onload = function () {
			let data;
			try {
				data = JSON.parse( qahmz.xhr.response );
			} catch ( e ) {
				return;
			}
			if ( data && data.excluded ) {
				qahmz.isExcludedIp = true;
				console.log( 'qa: Measurement is disabled because your IP address is in the exclusion list.' );
				return;
			}
			if ( data && data.readers_name ) {
				qahmz.readersName      = data.readers_name;
				qahmz.readersBodyIndex = data.readers_body_index;
				qahmz.rawName          = data.raw_name;
				qahmz.qa_id            = data.qa_id;
				if(!qahmz.cookieMode){ //同意モード以外なら有無を言わさずqa_idをセット
					qahmz.setQaid();
				}else{
					qahmz.updateQaidCookie();
				}
				qahmz.initBehData      = true;
			} else {
				throw new Error( 'qa: init failed. HttpStatus: ' + qahmz.xhr.statusText );
			}
		}

		qahmz.xhr.onerror = function() {

			if(qahmz.initBehData == false){
				qahmz.initRetry();
			}

        }
		
		qahmz.xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
		qahmz.xhr.send( sendStr );

	} catch (e) {
		console.error( e.message );
	}

}

qahmz.initRetryCount    = 0;

qahmz.initRetry = function(){

	if (qahmz.initRetryCount < qahmz.maxinitRetries) {
        qahmz.initRetryCount++;
        console.log(`qa: Retrying init request after ${qahmz.initRetryInterval}ms... Attempt ${qahmz.initRetryCount}`);
        setTimeout(qahmz.init, qahmz.initRetryInterval);
    } else {
        console.error('qa: Maximum init retry attempts reached. Aborting.');
    }

}

qahmz.init();

//record処理

// マウスの絶対座標取得 ブラウザ間で取得する数値をnormalizeできるらしい
qahmz.getMousePos = function(e) {
	let posx     = 0;
	let posy     = 0;
	if ( ! e ) {
		e = window.event;
	}
	if (e.pageX || e.pageY) {
		posx = e.pageX;
		posy = e.pageY;
	} else if (e.clientX || e.clientY) {
		posx = e.clientX + document.body.scrollLeft + document.documentElement.scrollLeft;
		posy = e.clientY + document.body.scrollTop + document.documentElement.scrollTop;
	}
	return { x : posx, y : posy };
};

/**
 * オブジェクトがELEMENT_NODEか判定
 */
qahmz.isElementNode = function( obj ) {
	return obj && obj.nodeType && obj.nodeType === 1;
}

/**
 * 同じ階層に同名要素が複数ある場合は識別のためインデックスを付与する
 * 複数要素の先頭 ( index = 1 ) の場合、インデックスは省略可能
 */
qahmz.getSiblingElemetsIndex = function( el, name ) {
	var index = 1;
	var sib   = el;

	while ( ( sib = sib.previousElementSibling ) ) {
		if ( sib.nodeName.toLowerCase() === name ) {
			++index;
		}
	}

	return index;
};

/**
 * エレメントからセレクタを取得
 * @returns {string} セレクタ名
 */
qahmz.getSelectorFromElement = function( el ) {
	var names = [];
	if ( ! qahmz.isElementNode( el ) ) {
		return names;
	}

	while ( el.nodeType === Node.ELEMENT_NODE ) {
		var name = el.nodeName.toLowerCase();
		if ( el.id ) {
			// id はページ内で一意となるため、これ以上の検索は不要
			// ↑ かと思ったがクリックマップを正しく構成するためには必要
			name += '#' + el.id;
			//names.unshift( name );
			//break;
		}

		// 同じ階層に同名要素が複数ある場合は識別のためインデックスを付与する
		// 複数要素の先頭 ( index = 1 ) の場合、インデックスは省略可能
		//
		var index = qahmz.getSiblingElemetsIndex( el, name );
		if ( 1 < index ) {
			name += ':nth-of-type(' + index + ')';
		}

		names.unshift( name );
		el = el.parentNode;
	}

	return names;
};

/**
 * セレクタの文字をエスケープ
 * @param {string} str セレクタ文字列
 * @returns {string} エスケープされたセレクタ文字列
 */
qahmz.escapeSelectorString = function( str ){
	let strSplitAry = str.split('>');
	let find = false;
	for ( let strIdx = 0; strIdx < strSplitAry.length; strIdx++ ) {
		let deliStr = '.';
		let deliIdx = strSplitAry[strIdx].indexOf( deliStr );
		if ( deliIdx === -1 ) {
			deliStr = '#';
			deliIdx = strSplitAry[strIdx].indexOf( deliStr );
		}
		if ( deliIdx !== -1 ) {
			let fwdName  = strSplitAry[strIdx].substr( 0, deliIdx );
			let BackName = strSplitAry[strIdx].substr( deliIdx + 1 );
			strSplitAry[strIdx] = fwdName + deliStr + CSS.escape( BackName );
			find = true;
		}
	}

	if ( find ) {
		return strSplitAry.join('>');
	} else {
		return str;
	}
};

/**
 * エレメントから遷移先を取得
 * @returns {string} 遷移先URL
 */
qahmz.getTransitionFromSelector = function( el ) {
	while ( el.nodeType === Node.ELEMENT_NODE ) {
		if( el.href ){
			return el.href;
		}
		el = el.parentNode;
	}
	return null;
}

// rec_flag判定

/*
document.addEventListener("DOMContentLoaded", function() {

		let docReadyDate = new Date();
		qahmz.speedMsec = docReadyDate.getTime() - qahmz.initDate.getTime();

		// QAの初期化が完了したらmoveBehavioralDataを起動
		qahmz.startMoveBehavioralData = function() {
			if ( qahmz.initBehData ) {
				qahmz.updateMsec();
				qahmz.moveBehavioralData();
				clearInterval( qahmz.startMoveIntervalId );
			}
		}
		qahmz.startMoveIntervalId = setInterval( qahmz.startMoveBehavioralData, 10 );
	}
);
*/

qahmz.trackingStarted = false; //trackingStart関数が既に呼び出されているか？
qahmz.trackingStart = function (){

	qahmz.trackingStarted = true;

	let docReadyDate = new Date();
	qahmz.speedMsec = docReadyDate.getTime() - qahmz.initDate.getTime();

	// QAの初期化が完了したらmoveBehavioralDataを起動
	qahmz.startMoveBehavioralData = function() {
		if ( qahmz.isExcludedIp ) {
			clearInterval( qahmz.startMoveIntervalId );
			return;
		}
		if ( qahmz.initBehData ) {
			qahmz.updateMsec();
			qahmz.moveBehavioralData();
			clearInterval( qahmz.startMoveIntervalId );
		}
	}
	qahmz.startMoveIntervalId = setInterval( qahmz.startMoveBehavioralData, 10 );

} 

document.addEventListener("DOMContentLoaded", function() {
	if( !qahmz.trackingStarted ){
		qahmz.trackingStart();
	}
});

if( qahmz.domloaded ){
	if( !qahmz.trackingStarted ){
		qahmz.trackingStart();
	}
}

// サイト読み込みからdocument readyが走るまでの時間を更新
qahmz.updateMsec = function() {

	let sendStr = 'action=update_msec';
	sendStr += '&tracking_hash=' + encodeURIComponent( qahmz.tracking_hash );
	sendStr += '&readers_name=' + encodeURIComponent( qahmz.readersName );
	sendStr += '&readers_body_index=' + encodeURIComponent( qahmz.readersBodyIndex );
	sendStr += '&speed_msec=' + encodeURIComponent( qahmz.speedMsec );
	sendStr += '&url=' + encodeURIComponent( location.href ); //QA ZERO add
	sendStr += '&tracking_id=' + encodeURIComponent( qahmz.tracking_id ); //QA ZERO add

	let xhr = new XMLHttpRequest();
	xhr.open("POST", qahmz.ajaxurl, true);

	xhr.onreadystatechange = function() {
		if (xhr.readyState === 4 && xhr.status === 200) {
			qahmz.updateMsecFailed = false;
			qahmz.log(qahmz.speedMsec);
		} else if (xhr.readyState === 4 && xhr.status !== 200) {
			qahmz.updateMsecFailed = true;
			qahmz.log_ajax_error(xhr.responseText, xhr.status, xhr.statusText);
		} else {
			qahmz.updateMsecFailed = true;
		}
	};

	xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
	xhr.send(sendStr);

}

// 測定開始時からの経過時間をミリ秒で取得
qahmz.getTotalProcMilliSec   = function() {
	let nowDate       = new Date();
	let diffMilliSec  = nowDate.getTime() - qahmz.focusDate.getTime();
	let totalMilliSec = qahmz.blurMilliSec  + diffMilliSec;
	return totalMilliSec;
}

// 測定の制限時間を超えたか？
qahmz.isOverTimeLimit = function() {
	return qahmz.getTotalProcMilliSec() > qahmz.limitMilliSec ? true : false;
}

qahmz.addPosData = function() {
	// 画面全体のY座標
	const siteBottomY = Math.max.apply(
		null,
		[
			document.body.clientHeight,
			document.body.scrollHeight,
			document.documentElement.scrollHeight,
			document.documentElement.clientHeight
		]
	);

	// 画面中央のY座標
	const dispCenterY = qahmz.scrollY() + ( window.innerHeight / 2 );

	// 画面下のY座標
	const dispBottomY = qahmz.scrollY() + window.innerHeight;

	let stayHeightIdx = 0;
	if( dispCenterY > 0 ) {
		stayHeightIdx = Math.floor( dispCenterY / 100 );
	}
	if ( ! qahmz.stayHeight[stayHeightIdx] ) {
		qahmz.stayHeight[stayHeightIdx] = 0;
	}
	qahmz.stayHeight[stayHeightIdx]++;


	if( ! qahmz.isScrollMax && ( dispBottomY / siteBottomY ) > 0.99  ) {
		qahmz.isScrollMax = true;
	}
}

//ブラウザ互換性のあるスクロール量取得 QA ZERO
qahmz.scrollY = function(){
	let scroll_Y = document.documentElement.scrollTop || document.body.scrollTop;
	return scroll_Y;
}

// 行動データを送信
qahmz.sendBehavioralData = function( forceSend, isBeforeUnload ) {

	// 送信回数をカウント。既にデータ送信中の場合はforceSendがtrueじゃない限りreturn
	if ( ! forceSend && qahmz.sendBehavNum > 0 ) {
		return;
	}
	qahmz.sendBehavNum++;

	let isPos   = false;
	let isClick = false;
	let isEvent = false;
	let isDLevent = false; //dataLayer

	isPos = true;

	if ( qahmz.clickAry.length > 0 ){
		isClick = true;
	}

	if ( qahmz.eventAry.length > 0 ){
		isEvent = true;
	}

	if ( qahmz.dLeventAry.length > 0 ){
		isDLevent = true;
	}

	let data = new FormData();
	data.append('action', 'record_behavioral_data');
	data.append('tracking_hash', qahmz.tracking_hash);
	data.append('pos_ver', 2);
	data.append('click_ver', 2);
	data.append('event_ver', 1);
	data.append('dlevent_ver', 1);
	data.append('is_pos', isPos);
	data.append('is_click', isClick);
	data.append('is_event', isEvent);
	data.append('is_dLevent', isDLevent); //dataLayer
	data.append('raw_name', qahmz.rawName);
	data.append('readers_name', qahmz.readersName);
	data.append('ua', navigator.userAgent.toLowerCase());
	data.append('url', location.href); //QA ZERO add
	data.append('tracking_id', qahmz.tracking_id); //QA ZERO add
	
	// init
	data.append('init_window_w', qahmz.initWinW);
	data.append('init_window_h', qahmz.initWinH);
	
	// pos
	data.append('stay_height', JSON.stringify(qahmz.stayHeight));
	data.append('is_scroll_max', qahmz.isScrollMax);
	
	// click
	data.append('click_ary', JSON.stringify(qahmz.clickAry));
	
	// event
	data.append('event_ary', JSON.stringify(qahmz.eventAry));

	//dLevent
	data.append('dlevent_ary', JSON.stringify(qahmz.dLeventAry));

	//cookie拒否
	data.append('is_reject', qahmz.isRejectCookie);

	if ( isBeforeUnload ) {

		// sendBeacon を使用したデータ送信
		const byteSize = calculateFormDataSize(data);

		if (byteSize > 64000) {
			qahmz.log("行動データのサイズが64KBを超えています。sendBeaconで送信できません。");
		} else {
			const success = navigator.sendBeacon(qahmz.ajaxurl, data);
			if (success) {
				qahmz.log("行動データを送信しました (sendBeacon)");
				qahmz.postBehavNum--;
			}
		}

		function calculateFormDataSize(formData) {
			let totalSize = 0;

			formData.forEach((value, key) => {
				// ファイルデータの場合、サイズをファイルのサイズとして計算
				if (value instanceof File) {
					totalSize += value.size;
				} else {
					// 文字列の場合、UTF-8エンコーディングでバイト数を計算
					totalSize += new TextEncoder().encode(value).length;
				}
				// URLSearchParams形式でのエンコード（keyとvalueのペア）
				totalSize += new TextEncoder().encode(key).length + 1; // keyのサイズ（key=value 形式のため「=」1バイト追加）
			});

			return totalSize;
		}

	} else {
		
		let xhr = new XMLHttpRequest();
		xhr.open("POST", qahmz.ajaxurl);
		xhr.onreadystatechange = function () {
			if (xhr.readyState === 4) {
				if (xhr.status === 200) {
					qahmz.log("行動データを送信しました (XMLHttpRequest)");
				} else {
					qahmz.log_ajax_error(xhr, xhr.statusText, xhr.response);
					qahmz.isFailAjax = true;
				}
				qahmz.sendBehavNum--;
			}
		}
		xhr.send(data);

	}
}


qahmz.checkClickEvent = function(e) {

	if ( qahmz.isOverTimeLimit() || ! document.hasFocus() ) {
		return;
	}

	const selAry   = qahmz.getSelectorFromElement( e.target );

	let findTagIdx = -1;
	for (let i = 0, sLen = selAry.length; i < sLen; i++ ) {
		for (let j = 0, tLen = qahmz.clickTagAry.length; j < tLen; j++ ) {
			if( selAry[i].indexOf( qahmz.clickTagAry[j] ) !== 0 ){
				continue;
			}

			if( selAry[i].length === qahmz.clickTagAry[j].length ||
				selAry[i].indexOf( qahmz.clickTagAry[j] + '#' ) === 0 ||
				selAry[i].indexOf( qahmz.clickTagAry[j] + ':' ) === 0 ) {
				findTagIdx = j;
				break;
			}
		}
		if( findTagIdx !== -1 ){
			break;
		}
	}
	
	// クリックウェイト
	if ( findTagIdx === -1 ) {
		if ( qahmz.isClickWait ) {
			return;
		}
		qahmz.isClickWait = true;
		setTimeout( function(){ qahmz.isClickWait = false; }, 300 );
	
	// タグのクリックウェイト
	} else {
		if ( qahmz.clickWaitAry[findTagIdx] ) {
			return;
		}
		qahmz.clickWaitAry[findTagIdx] = true;
		setTimeout( function(){ qahmz.clickWaitAry[findTagIdx] = false; }, 300 );
	}

	// クリックデータ
	const names   = qahmz.getSelectorFromElement( e.target );
	const selName = names.join( '>' );
	//qahmz.log( 'selector:' + selName );

	// セレクタ左上
	const escapedSelName = qahmz.escapeSelectorString(selName);
	const element = document.querySelector(escapedSelName);
	const rect = element.getBoundingClientRect();

	const selPos = {
	  top: rect.top + window.scrollY,
	  left: rect.left + window.scrollX
	};

	const selTop  = Math.round( selPos.top );
	const selLeft = Math.round( selPos.left );
	//qahmz.log( 'selTop: ' + selTop );
	//qahmz.log( 'selLeft: ' + selLeft );

	// マウス座標
	const mousePos = qahmz.getMousePos( e );
	const mouseX   = Math.round( mousePos.x );
	const mouseY   = Math.round( mousePos.y );
	//qahmz.log( 'mouseX: ' + mouseX );
	//qahmz.log( 'mouseY: ' + mouseY );

	// セレクタ左上からのマウス相対座標
	const relX = mouseX - selLeft;
	const relY = mouseY - selTop;

	const eventSec = Math.round(qahmz.getTotalProcMilliSec() / 1000);
	const elementText = e.target.textContent ? e.target.textContent.trim().substring(0, 100) : '';
	const elementId = e.target.id || '';
	const elementClass = e.target.className || '';
	
	let elementDataAttr = '';
	if (e.target.dataset) {
		const dataAttrs = [];
		for (const key in e.target.dataset) {
			if (e.target.dataset.hasOwnProperty(key)) {
				dataAttrs.push(key + '=' + e.target.dataset[key]);
			}
		}
		elementDataAttr = dataAttrs.join(',').substring(0, 200);
	}
	
	let actionId = 1; // デフォルトはclick
	const tagName = e.target.tagName.toLowerCase();
	const type = e.target.type ? e.target.type.toLowerCase() : '';
	
	if (tagName === 'input' && type === 'submit') {
		actionId = 2; // submit
	} else if (tagName === 'a') {
		const href = e.target.href || '';
		if (href.startsWith('tel:')) {
			actionId = 3; // tel
		} else if (href.startsWith('mailto:')) {
			actionId = 4; // mailto
		}
	} else if (tagName === 'button' && type === 'submit') {
		actionId = 2; // submit
	}
	
	const pageXPct = document.documentElement.scrollWidth > 0
		? Math.round((mouseX / document.documentElement.scrollWidth) * 100)
		: 0;
	const pageYPct = document.documentElement.scrollHeight > 0
		? Math.round((mouseY / document.documentElement.scrollHeight) * 100)
		: 0;
	
	// aタグをクリックした場合は遷移先のURLをデータに入れる
	let transition = '';
	if ( 'a' === qahmz.clickTagAry[findTagIdx] ) {
		transition = qahmz.getTransitionFromSelector( e.target );
		qahmz.clickAry.push( [ selName, relX, relY, transition, eventSec, elementText, elementId, elementClass, elementDataAttr, actionId, pageXPct, pageYPct ] );
	} else {
		qahmz.clickAry.push( [ selName, relX, relY, '', eventSec, elementText, elementId, elementClass, elementDataAttr, actionId, pageXPct, pageYPct ] );
	}
	qahmz.log( 'click: ' + qahmz.clickAry[qahmz.clickAry.length - 1] );

	// イベントデータ
	const clientX = Math.round( e.clientX );
	const clientY = Math.round( e.clientY );
	qahmz.eventAry.push( [ 'c', qahmz.getTotalProcMilliSec(), clientX, clientY ] );
	qahmz.log( 'event: ' + qahmz.eventAry[qahmz.eventAry.length - 1] );
	//qahmz.log( 'event mouse click client pos: ' + clientX + ', ' + clientY );

	// 指定タグへのクリック処理が行われた際はデータを即送信
	if( -1 !== findTagIdx ) {
		qahmz.sendBehavioralData( true, false );
	} else {
		qahmz.sendBehavioralData( false, false );
	}

}

qahmz.extractPointerData = function( target ){

	// クリックされた要素からセレクタ名を取得
	const names = qahmz.getSelectorFromElement( target );
	const selName = names.join('>');

	// セレクタの位置を取得
	// const element = document.querySelector(selName);
	// const selTop = Math.round(element.offsetTop);
	// const selLeft = Math.round(element.offsetLeft);

	// マウス座標を取得
	// const mousePos = qahmz.getMousePos(e);
	// const mouseX = Math.round(mousePos.x);
	// const mouseY = Math.round(mousePos.y);

	//let mouseX = 0;
	//let mouseY = 0;

	// if( qahmz.mouseXCur > 0 && qahmz.mouseYCur > 0 ){
	// 	mouseX = qahmz.mouseXCur + document.body.scrollLeft + document.documentElement.scrollLeft;
	// 	mouseY = qahmz.mouseYCur + document.body.scrollTop + document.documentElement.scrollTop;	
	// }
	
	// セレクタ左上からのマウス/タッチイベントの相対座標を計算
	// const relX = mouseX - selLeft;
	// const relY = mouseY - selTop;

	const relX = 0;
	const relY = 0;

	// セレクタ名、相対座標を含むオブジェクトを返す
	return {
		selName: selName,
		relX: relX,
		relY: relY
	};
}

qahmz.checkVideoEvent = function( e, param ){

	let ecData = qahmz.extractPointerData( e.target );
	qahmz.clickAry.push( [ ecData.selName, ecData.relX, ecData.relY, param ] );

}

qahmz.checkFocusEvent = function( e, param ){

	let ecData = qahmz.extractPointerData( e.target );
	qahmz.clickAry.push( [ ecData.selName, ecData.relX, ecData.relY, param ] );

}

qahmz.addEventListener = function() {

	document.querySelector("body").addEventListener("click", function(e){
		qahmz.checkClickEvent(e);
	});

	qahmz.setVideoListener = function( targetElem ) {

		//ビデオ再生の監視
		targetElem.addEventListener('play', function(e) {
			if (e.target.tagName === 'VIDEO') {
				qahmz.checkVideoEvent( e , 'p' );
			}
		}, true);
		targetElem.addEventListener('pause', function(e) {
			if (e.target.tagName === 'VIDEO') {
				qahmz.checkVideoEvent( e , 't' );
			}
		}, true);
		targetElem.addEventListener('ended', function(e) {
			if (e.target.tagName === 'VIDEO') {
				qahmz.checkVideoEvent( e , 't' );
			}
		}, true);
	}

	qahmz.setFormFocusListener = function( targetElem ) {

		targetElem.addEventListener('focusin', function(e) {
			if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
				qahmz.checkFocusEvent( e , 'i' );
			}
		}, true);

		targetElem.addEventListener('focusout', function(e) {
			if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
				qahmz.checkFocusEvent( e , 'o' );
			}
		}, true);

	}

	//動的に追加された要素に対する対応
	let observer = new MutationObserver(function(mutations) {
		mutations.forEach(function(mutation) {
			// 追加されたノードそれぞれに対して処理を行う
			for (let i = 0; i < mutation.addedNodes.length; i++) {
				let newNode = mutation.addedNodes[i];
				// 新たに追加されたノードが要素ノードである場合
				if (newNode.nodeType === Node.ELEMENT_NODE) {
					// クリックイベントのハンドラを設定
					newNode.addEventListener("click", function(e) {
						qahmz.checkClickEvent(e);
					});

					qahmz.setVideoListener( newNode );
					qahmz.setFormFocusListener( newNode );
					
				}
			}
		});
	});

	// DOMの変更を監視する対象の要素を指定
	let bodyNode = document.querySelector("body");
	// 監視の設定
	let observerConfig = {
		childList: true, // 直下の子ノードの追加・削除を監視
		subtree: true // 子孫ノードも監視対象に含める
	};
	// 監視を開始
	observer.observe(bodyNode, observerConfig);

	//動画要素の監視
	qahmz.setVideoListener( document.querySelector("body") );
	qahmz.setFormFocusListener( document.querySelector("body") );

    window.addEventListener("scroll", function() {
		if ( qahmz.isOverTimeLimit() || ! document.hasFocus() ) {
			return;
		}

		qahmz.scrollTopCur = Math.round( qahmz.scrollY() );
		qahmz.checkScrollEvent();
	});

	window.addEventListener("mousemove", function(e) {

		if ( qahmz.isOverTimeLimit() || ! document.hasFocus() ) {
			return;
		}

		qahmz.mouseXCur = Math.round( e.clientX );
		qahmz.mouseYCur = Math.round( e.clientY );
		qahmz.checkMouseMoveEvent();
	});

	window.addEventListener("resize", function() {

		if ( qahmz.isOverTimeLimit() ) {
			return;
		}

		if ( qahmz.resizeId !== false ) {
			clearTimeout( qahmz.resizeId );
		}
		qahmz.resizeId = setTimeout(function() {
			qahmz.eventAry.push( [ 'r', qahmz.getTotalProcMilliSec(), window.innerWidth, window.innerHeight ] );
			qahmz.log( 'event: ' + qahmz.eventAry[qahmz.eventAry.length - 1] );
		}, 300 );
	});
}

qahmz.checkScrollEvent = function() {
	if ( qahmz.scrollTop !== qahmz.scrollTopCur && ! qahmz.isScrollWait ) {
		qahmz.addScrollEvent();
	}
}

qahmz.addScrollEvent = function() {
	qahmz.isScrollWait = true;
	qahmz.scrollTop = qahmz.scrollTopCur;
	qahmz.eventAry.push( [ 's', qahmz.getTotalProcMilliSec(), qahmz.scrollTop ] );
	qahmz.log( 'event: ' + qahmz.eventAry[qahmz.eventAry.length - 1] );
	setTimeout( function(){ qahmz.isScrollWait = false; }, 300 );
}

qahmz.checkMouseMoveEvent = function() {
	if ( qahmz.mouseX !== qahmz.mouseXCur || qahmz.mouseY !== qahmz.mouseYCur ) {
		if( ! qahmz.isMouseMoveWait ) {
			qahmz.addMouseMoveEvent();
		}
	}
}

qahmz.addMouseMoveEvent = function() {
	qahmz.isMouseMoveWait = true;
	qahmz.mouseX = qahmz.mouseXCur;
	qahmz.mouseY = qahmz.mouseYCur;
	qahmz.eventAry.push( [ 'm', qahmz.getTotalProcMilliSec(), qahmz.mouseX, qahmz.mouseY ] );
	qahmz.log( 'event: ' + qahmz.eventAry[qahmz.eventAry.length - 1] );
	setTimeout( function(){ qahmz.isMouseMoveWait = false; }, 300 );
}


// データの保存など実行タイミングを監視
// setIntervalはフォーカスが外されていても実行し続けるが、qahmは実行させない仕様となる
// そのため内部的なタイマーを参照し実行タイミングを制御するこのシステムが必要
qahmz.monitorBehavioralData = function() {
	if ( qahmz.isOverTimeLimit() ) {
		clearInterval( qahmz.monitorId );
		return;
	}

	if ( ! document.hasFocus() ){
		return;
	}

	let totalMS = qahmz.getTotalProcMilliSec();
	//qahmz.log( 'totalMS:' + totalMS );

	// 常時監視
	qahmz.checkScrollEvent();
	qahmz.checkMouseMoveEvent();

	// 1000ms毎のイベント
	if ( ( totalMS - qahmz.monitorPrevRun1000MS ) >= 1000 ) {
		qahmz.monitorPrevRun1000MS = Math.floor( totalMS / 1000 ) * 1000;
		qahmz.addPosData();
		//qahmz.log( '|||||' + qahmz.monitorPrevRun1000MS );
		qahmz.updateQaidCookie();
		
	}

	// 3000ms毎のイベント
	// 実行間隔はQAHM_CONFIG_BEHAVIORAL_SEND_INTERVALで変更可能にした
	// そのため、qahmz.monitorPrevRun3000MSという変数名はいまいち合っていないが、とりあえずこのまま
	if ( ( totalMS - qahmz.monitorPrevRun3000MS ) >= qahmz.send_interval ) {
		qahmz.monitorPrevRun3000MS = Math.floor( totalMS / qahmz.send_interval ) * qahmz.send_interval;
		if ( ! qahmz.isFailAjax ) {
			qahmz.sendBehavioralData( false, false );
			//qahmz.log( '*****' + qahmz.monitorPrevRun3000MS );
		}
	}
}

qahmz.moveBehavioralData = function() {
	
	qahmz.stayHeight    = [];

	qahmz.limitMilliSec = 1000 * 60 * 30;
	qahmz.focusDate     = new Date();
	qahmz.blurMilliSec  = 0;

	qahmz.isScrollMax     = false;
	qahmz.isClickWait     = false;

	qahmz.clickAry        = [];
	qahmz.eventAry        = [];
	qahmz.dLeventAry      = qahmz.dLeventAry || []; //dataLayer連携

	qahmz.isScrollWait    = false;
	qahmz.isMouseMoveWait = false;
	qahmz.resizeId        = false;

	qahmz.scrollTop       = 0;
	qahmz.scrollTopCur    = Math.round( qahmz.scrollY() );
	qahmz.mouseX          = 0;
	qahmz.mouseY          = 0;
	qahmz.mouseXCur       = 0;
	qahmz.mouseYCur       = 0;

	qahmz.monitorPrevRun1000MS = 0;
	qahmz.monitorPrevRun3000MS = 0;		
	
	qahmz.sendBehavNum = 0;

	qahmz.clickTagAry  = [ 'a','input','button','textarea' ];
	qahmz.clickWaitAry = [ false, false, false, false ];

	// ウィンドウがアクティブになっているときだけ記録
	window.addEventListener("focus", function(){
		qahmz.focusDate = new Date();
	});
		
	window.addEventListener("blur", function(){
		let nowDate = new Date();
		qahmz.blurMilliSec += ( nowDate.getTime() - qahmz.focusDate.getTime() );
	});

	// 一定間隔で動作する処理はこちらにまとめる
	qahmz.monitorId = setInterval( qahmz.monitorBehavioralData, 100 );

	// イベントリスナーを利用した処理はこちらにまとめる
	qahmz.addEventListener();

	// 初期スクロール位置がトップではない場合の対策
	qahmz.checkScrollEvent();
	
	return true;
}
//;);


try {
    // beforeunload イベントの登録テスト
    const tempUnloadEvent = (e) => {
        qahmz.supportsBeforeUnload = true;
        if (typeof navigator.sendBeacon === "function") {
            qahmz.supportsSendBeacon = true;
        }
    };

    // 一時的にリスナーを登録
    window.addEventListener("beforeunload", tempUnloadEvent);

    // 擬似的な発火（`dispatchEvent`）で確認
    const event = new Event("beforeunload", { cancelable: true });
    window.dispatchEvent(event);

    // リスナーを削除して影響を防ぐ
    window.removeEventListener("beforeunload", tempUnloadEvent);

    // 両方サポートしている場合にフラグを設定
    qahmz.supportsBeforeUnloadAndSendBeacon = qahmz.supportsBeforeUnload && qahmz.supportsSendBeacon;
	
	if( qahmz.supportsBeforeUnloadAndSendBeacon ) {
		qahmz.log("beforeunload イベントの登録に成功しました。");
	} else {
		qahmz.log("beforeunload イベントの登録に失敗しました。");
	}
} catch (error) {
	qahmz.log("beforeunload イベントの登録に失敗しました。", error);
}

if (qahmz.supportsBeforeUnloadAndSendBeacon) {
    // beforeunload イベントでデータを送信
    window.addEventListener("beforeunload", function() {

		if ( qahmz.isExcludedIp ) { return; }

		if ( qahmz.updateMsecFailed ){

			let umdata = new URLSearchParams();

			umdata.append('action', 'update_msec');
			umdata.append('tracking_hash', qahmz.tracking_hash);
			umdata.append('readers_name', qahmz.readersName);
			umdata.append('readers_body_index', qahmz.readersBodyIndex);
			umdata.append('speed_msec', qahmz.speedMsec);
			umdata.append('url', location.href);
			umdata.append('tracking_id', qahmz.tracking_id);

			navigator.sendBeacon(qahmz.ajaxurl, umdata);

		}

		// この時点で行動データを送信中じゃないなら送信
		// リンククリック時などで送信中のケースも発生するため、強制送信はしないようにする
		// 具体的にはブラウザを閉じたりする処理など
        qahmz.sendBehavioralData( false, true );
    });
}

//dataLayer連携
//+GTMでタグのロードが遅延し、DOMContentLoadedが検出できずtrackingStart出来なかった場合の補正
//gtm.dom or gtm.loadイベント検出時にtrackingStartしているか確認し、していなかったら開始

if ( typeof window.dataLayer !== 'undefined' ) {

    qahmz.dLvariables = {};
	qahmz.gtmLoadEvents   = ['gtm.dom','gtm.load'];

	//初期で=を使って変数を格納するパターンもあるので、dataLayer内のものを取り出しておく
	for (let i = 0; i < window.dataLayer.length; i++) {
		let obj = window.dataLayer[i];
		
		for (let key in obj) {
			if (key !== 'event') {
				qahmz.dLvariables[key] = obj[key];
			}else{
				//すでにqahmz.gtmLoadEventsが起きているパターンの処理
				if (qahmz.gtmLoadEvents.includes(obj[key])){
					if( !qahmz.trackingStarted ){
						qahmz.trackingStart();
					}			
				}
			}
		}
	}

    
	const originalDataLayerPush = window.dataLayer.push.bind(window.dataLayer);

    Object.defineProperty(window.dataLayer,'push', {
			configurable: true,
			enumerable: true,
			value: function () {
				// Call the original push method
				//Array.prototype.push.apply(window.dataLayer, arguments);
				originalDataLayerPush(...arguments);

				try {
					// Get the last pushed object
					let data = arguments[0];
					if (data && data.hasOwnProperty('event')) {

						if( typeof qahmz.dli !== 'undefined' ){

							let datakeys = Object.keys(data); 
							let dataotherKeys = datakeys.filter(key => key !== 'event'); // 'event'以外のキーのみをフィルタリング
							if (dataotherKeys.length > 0) {
								let { event, ...rest } = data;
								Object.assign(qahmz.dLvariables, rest);
							}

							// Call custom event handler function
							qahmz.datalayereventpushed(data.event, qahmz.dLvariables);

							qahmz.dLvariables = {};

						}

						//gtmのDOMloadイベントorPageloadイベントを検出
						if(qahmz.gtmLoadEvents.includes(data.event)){
							if( !qahmz.trackingStarted ){
								qahmz.trackingStart();
							}
						}

					} else {
						// If it's not an event, assume it's variables and store them
						Object.assign(qahmz.dLvariables, data);
					}

				} catch (error) {
					console.error("An error occurred:", error.message);
				}
			
			}
		}

	);
}

qahmz.datalayereventpushed = function(eventname, data) {
    // Convert data to JSON
    let json = JSON.stringify(data);
	
	qahmz.dLeventAry = qahmz.dLeventAry || [];
	qahmz.dLeventAry.push([eventname,json]);

}

//公開メソッド
var qahmz_pub = qahmz_pub || {};

qahmz_pub.cookieConsent = function(agree) {
	if(agree){
		qahmz.setCookie("qa_cookieConsent",agree);
	}else{
		qahmz.deleteCookie("qa_id_z");
		qahmz.deleteCookie("qa_cookieConsent");
	}
}

