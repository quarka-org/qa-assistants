
var qahmz              = qahmz || {};

//qahmz.cookieConsentObject = true;
qahmz.cookieConsent = "{cookie_consent}";

if( !qahmz.set_cookieConsent ){

    qahmz.set_cookieConsent = function(){

        // まずは既存のクッキーをチェック
        if(document.cookie.indexOf("qa_cookieConsent=true") !== -1) {
            // qa_cookieConsentがtrueで存在する場合は何もしない
            return;
        }
    
        // クッキーを設定する
        let name = "qa_cookieConsent=";
        let expires = new Date();
        expires.setTime(expires.getTime() + 60 * 60 * 24 * 365 * 2 * 1000); //有効期限は2年
        let cookie_value = name + qahmz.cookieConsent + ";expires=" + expires.toUTCString() + ";path=/";
        
        // クロスドメインQA ID共通化対応
        if (qahmz.xdm && qahmz.xdm !== "") {
            cookie_value += ";domain=." + qahmz.xdm; // ドメイン属性の付与
        }
    
        document.cookie = cookie_value;
    }

}

qahmz.set_cookieConsent();

