var qahm = qahm || {};

qahm.loadScreen.promise()
.then(
	function() {
		/*
		// 配列から対応したパーセント値を渡す
		qahm.createCap = function( versionId, devName, startDate, endDate, isTarBlank ) {
			qahm.showLoadIcon();

			let start_time = new Date().getTime();
			
			// ポップアップブロック対策として先にウインドウを開く
			let windowOpen = null;
			let height     = window.outerHeight * 0.8;
			switch ( devName ) {
				case 'smp':
					opt = 'width=375,height=' + height;
					windowOpen = window.open( '', '', opt );
					break;
				case 'tab':
					opt = 'width=768,height=' + height;
					windowOpen = window.open( '', '', opt );
					break;
			}

			jQuery.ajax(
				{
					type: 'POST',
					url: qahm.ajax_url,
					dataType : 'json',
					data: {
						'action' : 'qahm_ajax_create_heatmap_file',
						'version_id': versionId,
						'start_date': startDate,
						'end_date': endDate,
					},
				}
			).done(
				function( url ){
					if( ! url ) {
						console.log( 'failed : create cap.php');
						qahm.hideLoadIcon();
						return;
					}

					// 最低読み込み時間経過後に処理実行
					let now_time  = new Date().getTime();
					let load_time = now_time - start_time;
					let min_time  = 300;

					if ( load_time < min_time ) {
						// ロードアイコンを削除して新しいウインドウを開く
						setTimeout(
							function(){
								qahm.hideLoadIcon();
								if ( isTarBlank ) {
									windowOpen ? windowOpen.location.href = url : window.open( url, '_blank' );
								} else {
									location.href = url;
								}
							},
							(min_time - load_time)
						);
					} else {
						qahm.hideLoadIcon();
						if ( isTarBlank ) {
							windowOpen ? windowOpen.location.href = url : window.open( url, '_blank' );
						} else {
							location.href = url;
						}
					}
				}
			);
		}


		// 配列から対応したパーセント値を渡す
		qahm.createCapToViewPv = function( versionId, devName, viewPvAry, isTarBlank ) {
			qahm.showLoadIcon();

			let start_time = new Date().getTime();
			
			// ポップアップブロック対策として先にウインドウを開く
			let windowOpen = null;
			let height     = window.outerHeight * 0.8;
			switch ( devName ) {
				case 'smp':
					opt = 'width=375,height=' + height;
					windowOpen = window.open( '', '', opt );
					break;
				case 'tab':
					opt = 'width=768,height=' + height;
					windowOpen = window.open( '', '', opt );
					break;
			}
			
			jQuery.ajax(
				{
					type: 'POST',
					url: qahm.ajax_url,
					dataType : 'json',
					data: {
						'action' : 'qahm_ajax_create_heatmap_file',
						'version_id': versionId,
						'view_pv_ary': JSON.stringify( viewPvAry ),
					},
				}
			).done(
				function( url ){
					if( ! url ) {
						console.log( 'failed : create cap.php');
						qahm.hideLoadIcon();
						return;
					}

					// 最低読み込み時間経過後に処理実行
					let now_time  = new Date().getTime();
					let load_time = now_time - start_time;
					let min_time  = 300;

					if ( load_time < min_time ) {
						// ロードアイコンを削除して新しいウインドウを開く
						setTimeout(
							function(){
								qahm.hideLoadIcon();
								if ( isTarBlank ) {
									windowOpen ? windowOpen.location.href = url : window.open( url, '_blank' );
								} else {
									location.href = url;
								}
							},
							(min_time - load_time)
						);
					} else {
						qahm.hideLoadIcon();
						if ( isTarBlank ) {
							windowOpen ? windowOpen.location.href = url : window.open( url, '_blank' );
						} else {
							location.href = url;
						}
					}
				}
			);
		}

		*/

		// 配列から対応したパーセント値を渡す
		qahm.createCap = function( startDate, endDate, pageId, deviceName, isLandingPage, media, tracking_id, goal ) {
			qahm.showLoadIcon();

			let start_time = new Date().getTime();
			
			jQuery.ajax(
				{
					type: 'POST',
					url: qahm.ajax_url,
					dataType : 'json',
					data: {
						'action' : 'qahm_ajax_create_heatmap_file',
						'tracking_id': tracking_id,
						'start_date': startDate,
						'end_date': endDate,
						'page_id': pageId,
						'device_name': deviceName,
						'is_landing_page': isLandingPage,
						'media': media,
						'goal': goal,
					},
				}
			).done(
				function( url ){
					// 変数 url の内容を確認
					if (typeof url !== 'string' || ! url.startsWith('http')) {
						alert( url );
						qahm.hideLoadIcon();
						return;
					}

					// 最低読み込み時間経過後に処理実行
					let now_time  = new Date().getTime();
					let load_time = now_time - start_time;
					let min_time  = 300;

					if ( load_time < min_time ) {
						// ロードアイコンを削除して新しいウインドウを開く
						setTimeout(
							function(){
								qahm.hideLoadIcon();
								window.open( url, '_blank' );
							},
							(min_time - load_time)
						);
					} else {
						qahm.hideLoadIcon();
						window.open( url, '_blank' );
					}
				}
			);
		}


		// アドミンバーのリンククリック
		// ここを復活させるにはqahm-loadのqahm.version_idの設定が必要あり（その時にデータがあるページの最大バージョン値がよさそう）
		//jQuery( '#wp-admin-bar-qahm span.ab-label' ).on(
		//	'click',
		//	function(){
		//		qahm.createCap( qahm.version_id, 'dsk', true );
		//	}
		//);
	}
);
