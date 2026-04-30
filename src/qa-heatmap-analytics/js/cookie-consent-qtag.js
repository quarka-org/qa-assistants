
var qahmz              = qahmz || {};

//qahmz.cookieConsentObject = true;
qahmz.cookieConsent = "{cookie_consent}";

if( !qahmz.set_cookieConsent ){

    // T49: Cookie発行をサーバーサイドに移行（Ajax方式）
    qahmz.set_cookieConsent = function(){

        // 同意していない場合は何もしない
        if( qahmz.cookieConsent !== true && qahmz.cookieConsent !== "true" ){
            return;
        }

        // 既にサーバーサイドCookieで同意済みなら再送信不要
        // （HttpOnly CookieはJSから読めないが、isConsentedフラグで確認）
        if( qahmz.isConsented ){
            return;
        }

        // ajaxurlが未設定の場合はスキップ（qtag.jsがまだロードされていない）
        if( !qahmz.ajaxurl ){
            return;
        }

        // サーバーにCookie発行を依頼
        var xhr = new XMLHttpRequest();
        var sendStr = 'action=cookie_consent';
        sendStr += '&tracking_hash=' + encodeURIComponent( qahmz.tracking_hash || '' );
        sendStr += '&url=' + encodeURIComponent( location.href );
        sendStr += '&tracking_id=' + encodeURIComponent( qahmz.tracking_id || '' );

        xhr.open( 'POST', qahmz.ajaxurl, true );
        xhr.withCredentials = true;
        xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );

        xhr.onload = function(){
            try {
                var data = JSON.parse( xhr.response );
                if( data && data.success ){
                    qahmz.isConsented = true;
                    if( data.qa_id ){
                        qahmz.qa_id = data.qa_id;
                    }
                    // 同意状態が変わったのでisRejectCookieを再評価
                    if( qahmz.updateQaidCookie ){
                        qahmz.updateQaidCookie();
                    }
                }
            } catch(e) {}
        };

        xhr.send( sendStr );
    }

}

qahmz.set_cookieConsent();

