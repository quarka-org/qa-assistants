var qahm = qahm || {};

// qahmのデバッグフラグに応じてログを表示
qahm.log = function ( msg ) {
	if ( qahm.debug !== qahm.debug_level['debug'] ) {
		return;
	}

	// traceログが長いためグループ化
	console.groupCollapsed( msg );
	console.trace();
	console.groupEnd();
	//console.log(msg);
};

// qahmのデバッグフラグに応じてアラートを表示
qahm.alert = function ( msg ) {
	if ( qahm.debug !== qahm.debug_level['debug'] ) {
		return;
	}
	console.trace();
	alert( msg );
};

// ajax error
qahm.log_ajax_error = function ( jqXHR, textStatus, errorThrown ) {
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

/*
// 最低限の機能しかない簡易的なsprintf。翻訳に使用。必要になれば拡張する
qahm.sprintf = function( format, ...args ) {
	// 現在は%dのフォーマットのみ対応、argsもひとつのみ対応
	let replace = format.replace( '%d', args[0] );
	return replace;
}
*/

// ↑の関数をIEでも動くような形にとりあえず変更したもの。引数の対応はひとつのみ
qahm.sprintf = function( format, arg ) {
	let replace = format.replace( '%d', arg );
	return replace;
}
// 複数の引数に対応させるため、配列を使う。
qahm.sprintfAry = function( format, ...args ) {
	let replaced = format;
	for ( let iii = 0; iii < args.length; iii++ ) {
		let placeholder = '%' + (iii+1).toString() + '$s';
		replaced = replaced.replace( placeholder, args[iii] );
	}
	return replaced;
}

// 引数で指定したライセンスプランを契約しているかチェック
//ZEROでは使わない
//qahm.checkLicensePlan = function ( plan ) {
//	if ( ! qahm.license_plans ) {
//		return false;
//	}

//	if ( qahm.license_plans[ plan ] ) {
//		return true;
//	} else {
//		return false;
//	}
//}


// 引数に指定された文字列が全て大文字ならtrueを返す
qahm.isUpper = function ( str ) {
	return !/[a-z]/.test( str ) && /[A-Z]/.test( str );
}

// 引数に指定された文字列が全て小文字ならtrueを返す
qahm.isLower = function ( str ) {
	return /[a-z]/.test( str ) && ! /[A-Z]/.test( str );
}


// 翻訳
qahm.japan = function ( text, domain = '' ) {
	return text;
}

//小数点の丸めに対応する
qahm.roundToX = function (num, keta ) {
	let eplus  = "e+" + keta.toString();
	let eminus = "e-"+ keta.toString();
    return +(Math.round(num + eplus )  + eminus );
}

//日付から文字列に変換する関数
qahm.getDataPeriod = function( date ) {
	let yearStr   = date.getFullYear();
	let monthStr  = date.getMonth() + 1;
	let dayStr    = date.getDate();

	// YYYY-MM-DDの形にする
	let formatStr = yearStr;
	formatStr += '-' + ('0' + monthStr).slice(-2);
	formatStr += '-' + ('0' + dayStr).slice(-2);

	return formatStr;
};

// 数字をnum桁カンマ区切りにする（小数点も考慮）.
qahm.comma = function( num ) {
	var s = String(num).split('.');
	var ret = String(s[0]).replace( /(\d)(?=(\d\d\d)+(?!\d))/g, '$1,');
	if (s.length > 1) {
		ret += '.' + s[1];
	}
	return ret;
};


// 文字列をnum文字でカットし、終端に'...'を追加
qahm.truncateStr = function( str, num ) {
	// 文字列がnum文字以下の場合はそのまま返す
	if (str.length <= num) {
	  return str;
	}
	return str.slice(0, num) + '...';
};
