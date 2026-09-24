var qahmz              = qahmz || {};

// #1346: インラインタグ（qahmz.initDate 等の設定）が欠落・遅延した環境でも
// trackingStart() が qahmz.initDate.getTime() で落ちて qtag 全体（公開 API 含む）が
// 死なないための既定値。正規タグではインラインが先に実行されるため上書きされない。
qahmz.initDate         = qahmz.initDate || new Date();

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

// T49: qa_id_zはHttpOnly Cookie — JSからは読めない。サーバーが$_COOKIEで直接読む
// getQaidfromCookie は廃止（互換性のため空実装を残す）
qahmz.getQaidfromCookie = function(){
	return { value: '', is_new_user: 0 };
}

// T49: qa_id_zはサーバーがSet-Cookieで発行する。JS側のCookie書き込みは不要
qahmz.setQaid = function(){
	return true;
}

// T49: Cookie管理はサーバーサイドに統一。JSはサーバー確定の同意状態(isConsented)に従う
// #1062: 旧cookieConsentObjectガード（どこからも設定されない死に変数）を撤去。
//        ガードが先にreturnするため後続のisConsented判定が到達不能で、
//        同意済みユーザーのbehavioral送信が恒久にis_reject=trueになっていた
qahmz.updateQaidCookie = function() {

	if( !qahmz.cookieMode ){ //Cookie同意モード以外
		qahmz.isRejectCookie = false;
		return;
	}

	// 同意モード: qa_cookieConsentはHttpOnlyでJSから読めないため、
	// initレスポンスのis_consented（サーバーが$_COOKIEで判定）を正とする。
	// 未確定（initレスポンス前）は安全側=拒否で送る。同意済みリピーターは
	// サーバーがqa_cookieConsentを見てis_rejectをfalseに上書きするため取りこぼしなし
	// （initは常にwithCredentials送信のため、同意Cookieは確実にサーバーへ届く）
	if( qahmz.isConsented === true ){
		qahmz.isRejectCookie = false;
	}else{
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

		// T49: qa_idはPOSTに含めない（サーバーが$_COOKIEから直接読む）
		let sendStr = 'action=init_session_data';
		sendStr += '&tracking_hash=' + encodeURIComponent( qahmz.tracking_hash );
		sendStr += '&url=' + encodeURIComponent( location.href );
		sendStr += '&title=' + encodeURIComponent( document.title );
		sendStr += '&referrer=' + encodeURIComponent( document.referrer );
		sendStr += '&country=' + encodeURIComponent( (navigator.userLanguage||navigator.browserLanguage||navigator.language).substr(0,2) );
		sendStr += '&tracking_id=' + encodeURIComponent( qahmz.tracking_id );
		sendStr += '&is_reject=' + encodeURIComponent( qahmz.isRejectCookie );

		// T50: original_id取得（Cookie or JS変数）
		if ( qahmz.originalIdSourceType && qahmz.originalIdSourceName ) {
			var oidVal = '';
			if ( qahmz.originalIdSourceType === 'cookie' ) {
				var cookies = qahmz.getCookieArray();
				if ( cookies[ qahmz.originalIdSourceName ] !== undefined ) {
					oidVal = cookies[ qahmz.originalIdSourceName ];
				}
			} else if ( qahmz.originalIdSourceType === 'js_var' ) {
				var jsVal = window[ qahmz.originalIdSourceName ];
				if ( jsVal !== undefined && jsVal !== null ) {
					oidVal = String( jsVal );
				}
			}
			if ( oidVal !== '' ) {
				sendStr += '&original_id=' + encodeURIComponent( oidVal );
			}
		}

		qahmz.xhr.open( 'POST', qahmz.ajaxurl, true );
		// T49: init_session_dataのみwithCredentials（HttpOnly Cookieの送受信に必要）
		qahmz.xhr.withCredentials = true;

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
				// T49: サーバーからの同意状態を反映
				qahmz.isConsented      = !!data.is_consented;
				if(!qahmz.cookieMode){
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

qahmz.trackingStarted = qahmz.trackingStarted || false; //trackingStart関数が既に呼び出されているか？（#1467: タグ二重読み込みの再パースで起動済み状態をリセットしない）
qahmz.trackingStart = function (){

	// #1467: 二重起動ガード（呼び出し元は全て trackingStarted を確認するが、レース・確認漏れへの最終防衛線）
	if ( qahmz.trackingStarted ) {
		return;
	}
	qahmz.trackingStarted = true;

	let docReadyDate = new Date();
	qahmz.speedMsec = docReadyDate.getTime() - qahmz.initDate.getTime();

	// QAの初期化が完了したらmoveBehavioralDataを起動
	// #1467: interval ID はローカル変数で所有する。共有プロパティ1本（qahmz.startMoveIntervalId）に依存すると、
	// 多重起動時に ID が上書きされ clearInterval が最後の1本しか殺せず、迷子の見張りが送信ループを起こす。
	let startMoveIntervalId = null;
	qahmz.startMoveBehavioralData = function() {
		if ( qahmz.isExcludedIp ) {
			clearInterval( startMoveIntervalId );
			return;
		}
		if ( qahmz.initBehData ) {
			qahmz.updateMsec();
			qahmz.moveBehavioralData();
			clearInterval( startMoveIntervalId );
		}
	}
	startMoveIntervalId = setInterval( qahmz.startMoveBehavioralData, 10 );
	qahmz.startMoveIntervalId = startMoveIntervalId; // 後方互換（本ブロック外の参照は現状なし）

} 

document.addEventListener("DOMContentLoaded", function() {
	if( !qahmz.trackingStarted ){
		qahmz.trackingStart();
	}
});

// #1346: qahmz.domloaded（インラインタグのリスナ）に加えて document.readyState でも判定する。
// GTM 経由の設置ではタグ全体（インライン含む）が DOMContentLoaded 後に注入されるため、
// インラインのリスナは発火済みで domloaded が立たない。従来この穴は dataLayer の
// gtm.dom/gtm.load 検出（下の dataLayer ブロック）が塞いでいたが、あちらは入口の栓
// （dli・既定 OFF）でゲートされるため、栓と無関係に readyState で開始を保証する。
if( qahmz.domloaded || document.readyState !== 'loading' ){
	if( !qahmz.trackingStarted ){
		qahmz.trackingStart();
	}
}

// サイト読み込みからdocument readyが走るまでの時間を更新
qahmz.updateMsec = function() {

	// #1467: レート制限（フェイルセーフ・sendBehavioralData と共通の枠）
	if ( ! qahmz.canSendUnderRateLimit() ) {
		return;
	}

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
// #1467: 行動系送信の共通レート制限（フェイルセーフ）。
// 正常運転＝send_interval（既定3000ms＝20回/分）の定期送信＋クリック等の強制送信。クリック多用ページでも
// 60回/分には届きにくく、事故（カミタケ実測1,200〜3,000回/分）とは20倍以上の差がある位置に天井を置く。
// 注意: 正常負荷は QAHM_CONFIG_BEHAVIORAL_SEND_INTERVAL に暗黙依存（大幅に短縮する場合はこの天井も見直すこと）。
// タグ二重読み込み等の異常で送信ループが生じても、サーバーを飽和させる前にクライアント側で頭打ちにする。
qahmz.sendRateLog = qahmz.sendRateLog || [];
qahmz.canSendUnderRateLimit = function() {
	let nowMs = Date.now();
	qahmz.sendRateLog = qahmz.sendRateLog.filter( function( t ) { return ( nowMs - t ) < 60000; } );
	if ( qahmz.sendRateLog.length >= 60 ) {
		qahmz.log( 'send rate limit exceeded. sending suppressed.' );
		return false;
	}
	qahmz.sendRateLog.push( nowMs );
	return true;
}

qahmz.sendBehavioralData = function( forceSend, isBeforeUnload ) {

	// 送信回数をカウント。既にデータ送信中の場合はforceSendがtrueじゃない限りreturn
	if ( ! forceSend && qahmz.sendBehavNum > 0 ) {
		return;
	}

	// #1467: レート制限（フェイルセーフ）。超過時は強制送信も含めて抑止する。
	if ( ! qahmz.canSendUnderRateLimit() ) {
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
	// T108: PVスコープの submit 観測フラグ。pos は毎ビーコン必ず送られる（is_pos 常時 true）
	// 経路なので、ここに載せれば raw_p ヘッダー経由で確実に PV 単位で cron に届く。
	data.append('is_submit', qahmz.isSubmit ? 1 : 0);
	
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
	
	// action_id: クリック対象の「事実」の分類（T108）。相互排他で、より特異な事実を
	// 優先する: tel/mailto（祖先 <a> の href スキーム）＝3/4 → form 領域内（closest('form')
	// 非null）＝2(form) → それ以外＝1(click)。submit の推測（button/input の
	// .type==='submit'）は廃止した——素の <button> は HTML 既定で type='submit' のため、
	// Cookie 同意バナー等の form でないボタンが action_id=2 に混入し is_submit を汚染
	// していた。tel/mailto は closest('a')、form は closest('form') とどちらもタグ非依存の
	// DOM 事実で判定する——<a href="tel:"><span>電話</span></a> の span 着弾や、内側の
	// span/icon・dev 自作ボタン（div/a/span）でも祖先要素を正しく拾う（子孫着弾で取りこぼさない）。
	let actionId = 1; // 1:click（汎用・リンク含む・フォールバック）
	const anchor = e.target.closest ? e.target.closest( 'a' ) : null;
	const href   = anchor ? ( anchor.href || '' ) : '';

	if ( href.startsWith( 'tel:' ) ) {
		actionId = 3; // tel（form 内にあっても tel/mailto を優先）
	} else if ( href.startsWith( 'mailto:' ) ) {
		actionId = 4; // mailto
	} else if ( e.target.closest && e.target.closest( 'form' ) ) {
		actionId = 2; // form（フォーム領域内クリック）
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

	// T108: ネイティブ submit イベントを document の capture-phase で観測し、PV単位フラグを
	// 立てる。実フォーム送信（クリック / Enter / React <form onSubmit> 等）のみ発火するため、
	// クリック推測で混入していた Cookie 同意バナーは自然に脱落する。preventDefault されても
	// 発火後の観測は成立する。clickAry には積まず（PVスコープ真偽値）、ビーコンの is_submit
	// フィールドで送る。委譲リスナーなので動的追加フォームも MutationObserver 不要で捕捉。
	document.addEventListener("submit", function(e){
		qahmz.isSubmit = true;
	}, true);

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

	// #1467: 二重初期化ガード。listener の重複登録・monitor interval の多重化・
	// sendBehavNum リセットによる送信ゲートの再開放（送信ループの燃料）を構造的に防ぐ。
	if ( qahmz.behavioralDataStarted ) {
		return true;
	}
	qahmz.behavioralDataStarted = true;

	qahmz.stayHeight    = [];

	qahmz.limitMilliSec = 1000 * 60 * 30;
	qahmz.focusDate     = new Date();
	qahmz.blurMilliSec  = 0;

	qahmz.isScrollMax     = false;
	qahmz.isClickWait     = false;

	// T108: このPVでネイティブ submit イベントが観測されたか（PVスコープ・action_id 非依存）。
	// document capture-phase の submit リスナー（addEventListener 内）で立て、ビーコンの
	// is_submit フィールドとして送る。クリック推測を廃し Enter 送信も捕捉する。
	qahmz.isSubmit        = false;

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
	// #1467: 万一の多重起動でも monitor を増殖させない（旧 interval を止めてから張る）
	if ( qahmz.monitorId ) {
		clearInterval( qahmz.monitorId );
	}
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

if (qahmz.supportsBeforeUnloadAndSendBeacon && ! qahmz.unloadSendHooked) { // #1467: 二重読み込みでリスナーを重複登録しない
	qahmz.unloadSendHooked = true;
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

// #1346: 入口の栓（フック装着ごとゲート）
// qahmz.dli はサーバー配信の動的プレフィックス（qtag.php）が、サイト設定
// datalayer_import が ON のときだけ定義する。未定義（既定）なら dataLayer には
// 読み書きとも一切触らない＝push のオーバーライドが「無条件」だった既知課題の解消。
// 従来この if 内の gtm.dom/gtm.load 検出が担っていた GTM 遅延ロード時の計測開始
// 補正は、上の document.readyState フォールバックが dataLayer 非依存で肩代わりする。
if ( typeof window.dataLayer !== 'undefined' && typeof qahmz.dli !== 'undefined' ) {

    qahmz.dLvariables = {};
	qahmz.gtmLoadEvents   = ['gtm.dom','gtm.load'];

	// #1346: event キーの無い push の記録（CTT 型＝ {page_location: 仮想URL}）。
	// 保存するのは page_location のみ（gtm.* 等のノイズは保存しない）・イベント名は
	// 予約名 qa_page_location・同一値の連続は二重記録しない。
	// datalayereventpushed() は本ブロックより後で定義されるため、ここでは直接
	// dLeventAry へ積む（記録形式は datalayereventpushed() と同一）。
	qahmz.dlLastPageLocation = qahmz.dlLastPageLocation || null;
	qahmz.recordDlPageLocation = qahmz.recordDlPageLocation || function ( obj ) {
		if ( ! obj || ! Object.prototype.hasOwnProperty.call( obj, 'page_location' ) ) {
			return;
		}
		if ( obj.page_location === qahmz.dlLastPageLocation ) {
			return;
		}
		qahmz.dlLastPageLocation = obj.page_location;
		qahmz.dLeventAry = qahmz.dLeventAry || [];
		qahmz.dLeventAry.push( [ 'qa_page_location', JSON.stringify( { page_location: obj.page_location } ) ] );
	};

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

		// #1346: 初期走査ぶんの event 無し push を記録する（CTT の本命経路）。
		// CTT の {page_location} は qtag より先に dataLayer に置かれるため push フックを
		// 通らない＝ここで記録しないと1件も残らない（2026-08-03 実機検証）。
		// dlInitScanned＝タグ二重読み込みで初期走査が再実行されても記録を繰り返さない
		// once ガード（#1467 の gtmPushHooked と同じ動機。セルフレビュー 🟡-1）。
		if ( ! qahmz.dlInitScanned && ! Object.prototype.hasOwnProperty.call( obj, 'event' ) ) {
			qahmz.recordDlPageLocation( obj );
		}
	}
	qahmz.dlInitScanned = true;

    
	// #1467: タグ二重読み込みで push を二重フックしない（同一イベントの二重処理・多重包み込み防止）
	if ( ! qahmz.gtmPushHooked ) {
	qahmz.gtmPushHooked = true;

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

						// #1346: フック装着後に届いた event 無し push も記録する
						// （SPA 的に後から push されるサイトへの備え。二重記録は
						//   recordDlPageLocation 内の同一値ガードが防ぐ）
						qahmz.recordDlPageLocation( data );
					}

				} catch (error) {
					console.error("An error occurred:", error.message);
				}
			
			}
		}

	);
	} // #1467: gtmPushHooked ガード終端
}

qahmz.datalayereventpushed = function(eventname, data) {
    // Convert data to JSON
    let json = JSON.stringify(data);
	
	qahmz.dLeventAry = qahmz.dLeventAry || [];
	qahmz.dLeventAry.push([eventname,json]);

}

//公開メソッド
var qahmz_pub = qahmz_pub || {};

// T49: HttpOnly Cookie対応 — サーバーサイドでCookie操作
qahmz_pub.cookieConsent = function(agree) {
	if( !qahmz.ajaxurl ){ return; }
	if(agree){
		// 同意: サーバーにCookie発行を依頼
		qahmz.set_cookieConsent && qahmz.set_cookieConsent();
	}else{
		// 拒否: サーバーにCookie削除を依頼
		var xhr = new XMLHttpRequest();
		var sendStr = 'action=cookie_consent_revoke';
		sendStr += '&tracking_hash=' + encodeURIComponent( qahmz.tracking_hash || '' );
		sendStr += '&url=' + encodeURIComponent( location.href );
		sendStr += '&tracking_id=' + encodeURIComponent( qahmz.tracking_id || '' );
		xhr.open( 'POST', qahmz.ajaxurl, true );
		xhr.withCredentials = true;
		xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
		xhr.onload = function(){
			qahmz.isConsented = false;
			qahmz.isRejectCookie = true;
		};
		xhr.send( sendStr );
	}
}

qahmz.liveView = qahmz.liveView || {};

qahmz.liveView.init = function() {
	var params = new URLSearchParams(location.search);
	var token = params.get('qa_lv');

	var storageKey = 'qa_live_view_' + location.pathname;
	var hasStoredData = sessionStorage.getItem(storageKey) !== null;

	if (!token && !hasStoredData) {
		return;
	}

	var script = document.createElement('script');
	script.src = qahmz.ajaxurl.replace(/qahm-ajax\.php.*$/, 'js/live-view.js') + '?ver={qtag_ver}';
	script.onload = function() {
		qahmz.liveView.start(token);
	};
	script.onerror = function() {
		console.error('QA Live View: Failed to load live-view.js');
	};
	document.head.appendChild(script);
};

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', qahmz.liveView.init);
} else {
	qahmz.liveView.init();
}

