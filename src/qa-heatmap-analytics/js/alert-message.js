(function (ns) {

	// Sweet Alert 2が読み込まれてなければ抜ける
	if ( typeof Swal === 'undefined' ) {
		console.error( 'SweetAlert2 is not loaded.' );
		return false;
	}

	var AlertMessage = function () {

		// インスタンスがあるかどうかチェック  
		if ( typeof AlertMessage.instance === 'object' ) {
			return AlertMessage.instance;
		}

		// 無ければキャッシュする  
		AlertMessage.instance = this;
		return this;
	};

	/** 
	* 確認用メッセージウインドウ（OK、Cancelボタンを表示）
	* @param _text String 表示したい内容テキスト 
	* @param _title String 表示したいタイトルテキスト 
	* @param _icon string アラートの種類 bootstrapと同じ or 'question' ( ?マーク ) 
	* @param _callback function OKボタンが押されたときのコールバック
	* @constructor 
	*/
	AlertMessage.prototype.confirm = function ( _title, _text, _icon, _callback ) {

		var o = {
			allowOutsideClick: false,
			showCancelButton: true,
			confirmButtonText: 'OK',
			cancelButtonText: 'Cancel',
			title: _title,
			html: _text
		};

		if (_icon === 'success' ||
			_icon === 'error' ||
			_icon === 'warning' ||
			_icon === 'info' ||
			_icon === 'question' ) {
			o['icon'] = _icon;
		}

		Swal.fire(o).then(function (result) {
			var retBool = false;
			if (typeof result.value !== 'undefined' && result.value === true) {
				retBool = true;
			}
			if ( retBool && typeof _callback === 'function' ) {
				_callback.call(this, retBool);
			}
		});

	};

	/** 
	* アラート用メッセージウインドウ（OKボタンのみ表示）
	* @param _title String 表示したいタイトルテキスト 
	* @param _text String 表示したい内容テキスト 
	* @param _icon string アラートの種類 bootstrapと同じ or 'question' ( ?マーク ) 
	* @param _callback function OKボタンが押されたときのコールバック
	* @constructor 
	*/
	AlertMessage.prototype.alert = function ( _title, _text, _icon, _callback ) {

		var o = {
			allowOutsideClick: false,
			title: _title,
			html: _text
		};

		if (_icon === 'success' ||
			_icon === 'error' ||
			_icon === 'warning' ||
			_icon === 'info' ||
			_icon === 'question' ) {
			o['icon'] = _icon;
		}

		Swal.fire(o).then(function (result) {
			var retBool = false;
			if ( typeof result.value !== 'undefined' && result.value === true ) {
				retBool = true;
			}
			if ( typeof _callback === 'function' ) {
				_callback.call( this, retBool );
			}
		});
	};

	ns.AlertMessage = new AlertMessage();

})(window);