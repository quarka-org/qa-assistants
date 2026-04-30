var qahm = qahm || {};

// 引数で渡した配列のオンロード判定
qahm.setLoadAllCallback = function( elems, callback ) {
    var count = 0;
    for (var i = 0; i < elems.length; ++i) {
        elems[i].onload = function() {
            ++count;
            if (count == elems.length) {
                // All elements have been loaded.
                callback();
            }
        };
    }
}


qahm.initPlayList = function() {
	let scrTop = ( 50 + 6 + 6 ) * ( qahm.replay_id - 1 ) + 'px';
	jQuery( '#playlist' ).mCustomScrollbar({
		setTop: scrTop,
		scrollInertia: 600
	});
	
	// 画像の遅延読み込み
	let images = document.querySelectorAll( 'img[data-src]' );
	for( let i = 0; i < images.length; i++ ) {
		setTimeout(
			function(){
				let dataSrc = images[i].getAttribute( 'data-src' );
				images[i].removeAttribute( 'data-src' );
				images[i].setAttribute( 'src', dataSrc );
			}, 10 * i
		);
	}

	// ページの遷移
	jQuery( document ).on(
		'click',
		'.playlist-item',
		function(){
			qahm.openReplayView(
				jQuery( this ).data( 'replay_id' )
			);
		}
	);
}


// 初期化
qahm.loadScreen.promise()
.then(
	function() {
		if ( qahm.event_ary === 'null' || qahm.event_ary === null ) {
			qahm.initPlayList();
			alert( qahml10n.event_data_not_found );
			return;
		}

		qahm.showLoadIcon();

		qahm.imgMouseIcon        = new Image();
		qahm.imgMouseIcon.src    = 'img/cursor.png';
		
		qahm.imgPlayIcon         = new Image();
		qahm.imgPlayIcon.src     = "data:image/svg+xml;base64," + btoa( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><!-- Font Awesome Free 5.15.1 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license/free (Icons: CC BY 4.0, Fonts: SIL OFL 1.1, Code: MIT License) --><circle cx="256" cy="256" r="240" fill="white"/><path style="fill:#000;" d="M256 8C119 8 8 119 8 256s111 248 248 248 248-111 248-248S393 8 256 8zm115.7 272l-176 101c-15.8 8.8-35.7-2.5-35.7-21V152c0-18.4 19.8-29.8 35.7-21l176 107c16.4 9.2 16.4 32.9 0 42z"/></svg>' );
		
		qahm.imgPauseIcon        = new Image();
		qahm.imgPauseIcon.src    = "data:image/svg+xml;base64," + btoa( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><!-- Font Awesome Free 5.15.1 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license/free (Icons: CC BY 4.0, Fonts: SIL OFL 1.1, Code: MIT License) --><circle cx="256" cy="256" r="240" fill="white"/><path style="fill:#000;" d="M256 8C119 8 8 119 8 256s111 248 248 248 248-111 248-248S393 8 256 8zm-16 328c0 8.8-7.2 16-16 16h-48c-8.8 0-16-7.2-16-16V176c0-8.8 7.2-16 16-16h48c8.8 0 16 7.2 16 16v160zm112 0c0 8.8-7.2 16-16 16h-48c-8.8 0-16-7.2-16-16V176c0-8.8 7.2-16 16-16h48c8.8 0 16 7.2 16 16v160z"/></svg>' );
		
		qahm.imgBackwardIcon     = new Image();
		qahm.imgBackwardIcon.src = "data:image/svg+xml;base64," + btoa( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><!-- Font Awesome Free 5.15.1 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license/free (Icons: CC BY 4.0, Fonts: SIL OFL 1.1, Code: MIT License) --><path style="fill:#000;" d="M256 8C119 8 8 119 8 256s111 248 248 248 248-111 248-248S393 8 256 8z"/><path style="fill:#fff;" transform="translate(84,104) scale(0.60)" d="M11.5 280.6l192 160c20.6 17.2 52.5 2.8 52.5-24.6V96c0-27.4-31.9-41.8-52.5-24.6l-192 160c-15.3 12.8-15.3 36.4 0 49.2zm256 0l192 160c20.6 17.2 52.5 2.8 52.5-24.6V96c0-27.4-31.9-41.8-52.5-24.6l-192 160c-15.3 12.8-15.3 36.4 0 49.2z"/></svg>' );
		
		qahm.imgForwardIcon      = new Image();
		qahm.imgForwardIcon.src  = "data:image/svg+xml;base64," + btoa( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><!-- Font Awesome Free 5.15.1 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license/free (Icons: CC BY 4.0, Fonts: SIL OFL 1.1, Code: MIT License) --><path style="fill:#000;" d="M256 8C119 8 8 119 8 256s111 248 248 248 248-111 248-248S393 8 256 8z"/><path style="fill:#fff;" transform="translate(124,104) scale(0.60)" d="M500.5 231.4l-192-160C287.9 54.3 256 68.6 256 96v320c0 27.4 31.9 41.8 52.5 24.6l192-160c15.3-12.8 15.3-36.4 0-49.2zm-256 0l-192-160C31.9 54.3 0 68.6 0 96v320c0 27.4 31.9 41.8 52.5 24.6l192-160c15.3-12.8 15.3-36.4 0-49.2z"/></svg>' );
		
		let imgAry = [
			qahm.imgMouseIcon,
			qahm.imgPlayIcon,
			qahm.imgPauseIcon,
			qahm.imgBackwardIcon,
			qahm.imgForwardIcon,
		];

		qahm.setLoadAllCallback( imgAry, function() {
			qahm.EVENTSTATE = {
				PAUSE   : 1,
				PLAY    : 2,
				END_SET : 3,
				END     : 4,
			};

			qahm.eventPlayMilliSec        = 0;
			qahm.eventState               = qahm.EVENTSTATE.PAUSE;
			qahm.eventPrevVisibilityState = 'visible';
			qahm.event_ary                 = JSON.parse( qahm.event_ary );
			qahm.eventIdx                 = qahm.data_col_body;
			qahm.eventPlayMilliSec        = 0;
			qahm.eventEndMilliSec         = parseInt( qahm.event_ary[qahm.event_ary.length-1][qahm.data_row_time] );
			qahm.nowScrollTop             = 0;
			qahm.eventScrollTop           = 0;
			qahm.mouseRipple              = new Array();
			qahm.rippleMax                = 30;
			qahm.isOpenProc               = false;
			qahm.resizeW                  = qahm.event_ary[qahm.data_col_head][qahm.data_row_win_w];
			qahm.resizeH                  = qahm.event_ary[qahm.data_col_head][qahm.data_row_win_h];
			qahm.speedRate                = 1;
			qahm.effectIcon               = new QahmEffectIcon();
			qahm.nextReplayFuncId         = 0;
/*
			jQuery( window ).on(
				'load',
				function() {
*/
					qahm.initPlayList();

					qahm.loadOgpImages();

					if ( qahm.event_ary ) {
						// 暫定再生
						qahm.eventPrevDate = new Date();
						qahm.eventState    = qahm.EVENTSTATE.PLAY;

						qahm.eventIntervalId = setInterval( qahm.eventMove, 100 );
						qahm.setUpCanvas();
						qahm.animationCanvas();
					} else {
						// イベントデータが無い場合は次の動画へ
						// ここは仕様を決める必要あり
						qahm.openReplayView(
							qahm.replay_id + 1
						);
					}

					// ショートカットキー
					jQuery( window ).keydown( function(e){
						switch ( e.key ) {
							case ' ':
							case 'k':
								qahm.actionPlayPause();
								break;
							case 'ArrowRight':
								qahm.actionForwardMilliSec( 5000 );
								break;
							case 'ArrowLeft':
								qahm.actionBackwardMilliSec( 5000 );
								break;
							case 'l':
								qahm.actionForwardMilliSec( 10000 );
								break;
							case 'j':
								qahm.actionBackwardMilliSec( 10000 );
								break;
							case '<':
								qahm.actionSwitchSpeed( false );
								break;
							case '>':
								qahm.actionSwitchSpeed( true );
								break;
							case '0':
							case '1':
							case '2':
							case '3':
							case '4':
							case '5':
							case '6':
							case '7':
							case '8':
							case '9':
								qahm.setEventProgress( parseInt( e.key ) / 10 );
								break;
						}
					});

					// クリック処理
					jQuery( document ).on(
						'click',
						'#screen-control,#control-play,#control-pause,#control-replay',
						function(){
							qahm.actionPlayPause();
						}
					);

					jQuery( document ).on(
						'click',
						'#control-speed',
						function(){
							qahm.actionSwitchSpeed( true );
						}
					);

					jQuery( document ).on(
						'click',
						'#control-prev',
						function(){
							qahm.openReplayView(
								qahm.replay_id - 1
							);
						}
					);

					jQuery( document ).on(
						'click',
						'#control-next,#next-replay-play',
						function(){
							let id = qahm.replay_id + 1;
							if ( id > qahm.replay_id_max ) {
								id = 1;
							}

							qahm.openReplayView( id );
						}
					);

					jQuery( document ).on(
						'click',
						'#next-replay-cancel',
						function(){
							jQuery( '#next-replay-container' ).fadeOut( 300 );
							qahm.nextReplayFuncId++;
						}
					);

					jQuery( document ).on(
						'click',
						'#seekbar-container',
						function( e ){
							const selWidth  = jQuery( '#seekbar-container' ).width();
							const selPos    = jQuery( '#seekbar-container' ).offset();
							const selLeft   = Math.round( selPos.left );
							
							const mousePos  = qahm.getMousePos( e );
							const mouseX    = Math.round( mousePos.x );
							
							const selOfsX   = mouseX - selLeft;
							const clickPerX = selOfsX / selWidth;
							
							qahm.setEventProgress( clickPerX );
						}
					);

					// スクリーンの補正
					qahm.correctScreen();
					jQuery( window ).resize(function() {
						qahm.correctScreen();
					});

					qahm.hideLoadIcon();
				/*}
			);*/
		});
	}
);


qahm.calcEventProgress = function( isChangeAuto ) {
	let mouseMove	= null;
	let mouseClick  = [];
	let winScroll	= null;
	let winResize	= null;

	// クリックのみすべてのイベントを実行
	// それ以外は最後のイベントのみ実行
	while( parseInt( qahm.event_ary[qahm.eventIdx][qahm.data_row_time] ) <= qahm.eventPlayMilliSec ) {
		switch ( qahm.event_ary[qahm.eventIdx][qahm.data_row_type] ) {
			case 'm':
				mouseMove = new Array(
					parseInt( qahm.event_ary[qahm.eventIdx][qahm.data_row_mouse_x] ),
					parseInt( qahm.event_ary[qahm.eventIdx][qahm.data_row_mouse_y] )
					);
				break;
			case 'c':
				if ( isChangeAuto ) {
					mouseClick.push( Array(
						parseInt( qahm.event_ary[qahm.eventIdx][qahm.data_row_click_x] ),
						parseInt( qahm.event_ary[qahm.eventIdx][qahm.data_row_click_y] )
						) );
				}
				break;
			case 's':
				winScroll = parseInt( qahm.event_ary[qahm.eventIdx][qahm.data_row_scroll_y] );
				break;
			case 'r':
				winResize = new Array(
					parseInt( qahm.event_ary[qahm.eventIdx][2] ),
					parseInt( qahm.event_ary[qahm.eventIdx][3] )
					);
				break;
		}
		qahm.eventIdx++;

		//if ( isChangeAuto ) {
			if ( qahm.event_ary.length <= qahm.eventIdx ) {
				qahm.eventState = qahm.EVENTSTATE.END_SET;
				qahm.eventPlayMilliSec = qahm.event_ary[qahm.eventIdx-1][qahm.data_row_time];
				break;
			}
		//}
	}
	//qahm.log( 'ms:' + qahm.eventPlayMilliSec );
	if( winScroll !== null ) {
		qahm.nowScrollTop = winScroll;
		qahm.log( 's:' + winScroll );
	}
	
	let frameContent = jQuery( 'body,html', jQuery( '#screen-iframe' ).contents() );
	if ( ! frameContent.is( ':animated' ) ) {
		if ( qahm.nowScrollTop !== qahm.eventScrollTop ) {
			qahm.eventScrollTop = qahm.nowScrollTop;

			if ( isChangeAuto ) {
				let scrSpeed = parseInt( 200 / qahm.speedRate );
				if ( scrSpeed < 0 ) {
					scrSpeed = 0;
				}
				frameContent.animate( { scrollTop: qahm.eventScrollTop }, scrSpeed, 'swing' );
			} else {
				frameContent.scrollTop( qahm.eventScrollTop );
			}
		}
	}
	if( mouseMove !== null ) {
		let pos = { x: mouseMove[0], y: mouseMove[1] };
		if ( qahm.destination === undefined ) {
			qahm.destination = new QahmDestination( pos.x, pos.y );
			qahm.mouseLine = new QahmMouseLine( pos.x, pos.y );
		} else {
			qahm.destination.setPos( pos.x, pos.y );
		}
		qahm.log( 'm:' + pos.x + ', ' + pos.y );
	}
	
	if ( isChangeAuto ) {
		if( mouseClick.length ) {
			for ( let i = 0; i < mouseClick.length; i++ ) {
				let pos = { x: mouseClick[i][0], y: mouseClick[i][1] };
				for ( let j = 0; j < qahm.rippleMax; j++ ) {
					if ( qahm.mouseRipple[j] === undefined ) {
						qahm.mouseRipple[j] = new QahmMouseRipple( pos.x, pos.y );
						break;
					}
				}
				qahm.log( 'c:' + pos.x + ', ' + pos.y );
			}
		}
	}
	if( winResize !== null ) {
		qahm.resizeW = winResize[0];
		qahm.resizeH = winResize[1];
		qahm.correctScreen();
	}

	// timer
	let timer = Math.floor( qahm.eventPlayMilliSec / 1000 );
	let min = Math.floor(timer % (24 * 60 * 60) % (60 * 60) / 60);
	min = ( '00' + min ).slice( -2 );
	let sec = timer % (24 * 60 * 60) % (60 * 60) % 60;
	sec = ( '00' + sec ).slice( -2 );
	jQuery( '.video-timer-now' ).text( min + ':' + sec );

	// seekbar
	let seek = qahm.eventPlayMilliSec / qahm.eventEndMilliSec * 100;
	if ( seek > 100 ) {
		seek = 100;
	}
	jQuery( '#seekbar-play' ).css( 'width', seek + '%' );
}


qahm.setEventProgress = function( perMilliSec ) {
	// パラメーターの初期化
	qahm.eventPlayMilliSec = qahm.eventEndMilliSec * perMilliSec;
	qahm.eventIdx          = qahm.data_col_body;
	qahm.eventPrevDate     = new Date();
	qahm.nowScrollTop      = 0;
	qahm.resizeW           = qahm.event_ary[qahm.data_col_head][qahm.data_row_win_w];
	qahm.resizeH           = qahm.event_ary[qahm.data_col_head][qahm.data_row_win_h];

	// 画面の初期化
	qahm.clearCanvas();
	if ( qahm.destination !== undefined ) {
		delete qahm.destination;
		delete qahm.mouseLine;
	}
	if ( qahm.destination !== undefined ) {
		delete qahm.mouseLine;
	}
	for ( let i = 0; i < qahm.rippleMax; i++ ) {
		if ( qahm.mouseRipple[i] !== undefined ) {
			delete qahm.mouseRipple[i];
		}
	}

	// state
	switch( qahm.eventState ) {
		case qahm.EVENTSTATE.END_SET:
		case qahm.EVENTSTATE.END:
			jQuery( '#screen-overlay' ).hide();
			jQuery( '#next-replay-container' ).hide();
			jQuery( '#control-play' ).css( 'display', 'none' );
			jQuery( '#control-pause' ).removeAttr( 'style' );
			jQuery( '#control-replay' ).css( 'display', 'none' );
			qahm.eventState = qahm.EVENTSTATE.PLAY;
			break;
	}

	// 再計算
	qahm.calcEventProgress( false );
}

qahm.eventMove = function() {
	if ( document.visibilityState === 'visible' ) {
		if ( qahm.eventPrevVisibilityState === 'hidden' ) {
			qahm.eventPrevDate = new Date();
		}
		
		switch( qahm.eventState ) {
			case qahm.EVENTSTATE.PAUSE:
				break;

			case qahm.EVENTSTATE.PLAY:
				let nowDate             = new Date();
				let diffMilliSec        = nowDate.getTime() - qahm.eventPrevDate.getTime();
				qahm.eventPrevDate      = nowDate;
				qahm.eventPlayMilliSec += Math.round( diffMilliSec * qahm.speedRate );
			
				qahm.calcEventProgress( true );
				break;
			
			case qahm.EVENTSTATE.END_SET:
				jQuery( '#screen-overlay' ).hide().fadeIn( 500 );
				jQuery( '#control-play' ).css( 'display', 'none' );
				jQuery( '#control-pause' ).css( 'display', 'none' );
				jQuery( '#control-replay' ).removeAttr( 'style' );
				
				if ( 1 < qahm.replay_id_max ) {
					jQuery( '#next-replay-container' ).hide().fadeIn( 500 );
					jQuery( '#next-replay-time-count' ).text( '5' );
					qahm.nextReplayFuncId++;
					setTimeout( qahm.nextReplayTimeCount, 1000, qahm.nextReplayFuncId, 5 );
				}

				qahm.eventState = qahm.EVENTSTATE.END;
				break;

			case qahm.EVENTSTATE.END:
				break;
			
			default:
				break;
		}
	}
	qahm.eventPrevVisibilityState = document.visibilityState;
}

qahm.nextReplayTimeCount = function( id, count ) {
	if ( qahm.eventState !== qahm.EVENTSTATE.END ||
		 qahm.nextReplayFuncId !== id ) {
		return;
	}

	count--;
	if ( 0 >= count ) {
		let id = qahm.replay_id + 1;
		if ( id > qahm.replay_id_max ) {
			id = 1;
		}

		qahm.openReplayView( id );
	} else {
		jQuery( '#next-replay-time-count' ).text( count );
		setTimeout( qahm.nextReplayTimeCount, 1000, id, count );
	}
}

qahm.setUpCanvas = function() {
	qahm.canvas = document.getElementById( 'screen-canvas' );
	qahm.ctx = qahm.canvas.getContext( '2d' );

	qahm.ctx.beginPath();
	qahm.ctx.moveTo( 0, 0 );
	qahm.ctx.lineTo( qahm.canvas.width, 0 );
	qahm.ctx.lineTo( qahm.canvas.width, qahm.canvas.height );
	qahm.ctx.lineTo( 0, qahm.canvas.height );
	qahm.ctx.closePath();
	qahm.ctx.fillStyle = 'rgba(255,255,255,1)';
	qahm.ctx.fill();
}

qahm.clearCanvas = function() {
	qahm.ctx.clearRect( 0, 0, qahm.canvas.width, qahm.canvas.height );
}


qahm.animationCanvas = function() {
	loop();
	function loop(){
		/** 動作 **/
		//if ( qahm.eventState === qahm.EVENTSTATE.PLAY ) {
		// マウスライン
		if ( qahm.mouseLine !== undefined ) {
			qahm.mouseLine.uptateState();
		}

		// 波紋
		for ( let i = 0; i < qahm.rippleMax; i++ ) {
			if ( qahm.mouseRipple[i] !== undefined ) {
				qahm.mouseRipple[i].update();
				if ( qahm.mouseRipple[i].isDead ) {
					delete qahm.mouseRipple[i];
				}
			}
		}
		
		// アイコンのエフェクト
		if ( qahm.effectIcon !== undefined ) {
			qahm.effectIcon.update();
		}
		//}


		/** 描画 **/
		qahm.clearCanvas();

		// マウスライン
		if ( qahm.mouseLine !== undefined ) {
			qahm.mouseLine.draw();
		}

		// 目的地
		if ( qahm.destination !== undefined ) {
			qahm.destination.draw();
		}

		// 波紋
		for ( let i = 0; i < qahm.rippleMax; i++ ) {
			if ( qahm.mouseRipple[i] !== undefined ) {
				qahm.mouseRipple[i].draw();
			}
		}
		
		// アイコンのエフェクト
		if ( qahm.effectIcon !== undefined ) {
			qahm.effectIcon.draw();
		}

		window.requestAnimationFrame( loop );
	}
}


qahm.openReplayView = function( replayId ) {
	if ( replayId < 1 || replayId > qahm.replay_id_max || replayId === qahm.replay_id || qahm.isOpenProc ) {
		return;
	}

	qahm.showLoadIcon();
	qahm.isOpenProc = true;

	let startDate = new Date().getTime();

	// このスコープでしか使わないajax成功処理
	function qahmOpenAjaxDone( url ) {
		// 最低読み込み時間経過後に処理実行
		let nowDate      = new Date().getTime();
		let loadMilliSec = nowDate - startDate;
		let minMilliSec  = 100;

		if ( loadMilliSec < minMilliSec ) {
			setTimeout(
				function(){
					window.location.href = url;
				},
				( minMilliSec - loadMilliSec )
			);
		} else {
			window.location.href = url;
		}
	}

	switch ( qahm.data_type ) {
		case 'readers':
			jQuery.ajax(
				{
					type: 'POST',
					url: qahm.ajax_url,
					dataType : 'text',
					data: {
						'action'        : 'qahm_ajax_create_replay_file_to_raw_data',
						'work_base_name': qahm.work_base_name,
						'replay_id'     : replayId,
					},
				}
			).done(
				function( url ){
					qahmOpenAjaxDone( url );
				}
			).fail(
				function( jqXHR, textStatus, errorThrown ){
					qahm.log_ajax_error( jqXHR, textStatus, errorThrown );
					qahm.isOpenProc = false;
				}
			).always(
				function(){
					qahm.hideLoadIcon();
				}
			);
			break;
		
		case 'database':
			let accessTime = jQuery('#playlist [data-replay_id="' + replayId + '"]').data( 'access_time' );
			jQuery.ajax(
				{
					type: 'POST',
					url: qahm.ajax_url,
					dataType : 'json',
					data: {
						'action'        : 'qahm_ajax_create_replay_file_to_data_base',
						'work_base_name': qahm.work_base_name,
						'replay_id'     : replayId,
						'reader_id'     : qahm.reader_id,
						'access_time'   : accessTime
					},
				}
			).done(
				function( url ){
					qahmOpenAjaxDone( url );
				}
			).fail(
				function( jqXHR, textStatus, errorThrown ){
					qahm.log_ajax_error( jqXHR, textStatus, errorThrown );
					qahm.isOpenProc = false;
				}
			).always(
				function(){
					qahm.hideLoadIcon();
				}
			);
			break;
		
		default:
			alert( qahml10n.page_change_failed );
			break;
	}
}


qahm.correctScreen = function() {
	let w = qahm.resizeW;
	let h = qahm.resizeH;

	let scr = jQuery( '#screen-container' );
	let scale = scr.width() / w;
	if ( scale > 1 ) {
		scale = 1;
	}
	let height = scale * h;

	jQuery( '#screen-container-inner' ).css({
		'display':'block',
		'width':'1px',
		'height':height + 'px',
		'transform':'scale(' + scale + ')',
		'transform-origin':'50% 0 0',
	});

	if ( qahm.canvas.width !== w || qahm.canvas.height !== h ) {
		let ml = - ( w / 2 );
		qahm.canvas.width = w;
		qahm.canvas.height = h;
		jQuery( '#screen-canvas' ).css({
			'margin-left' : ml + 'px'
		});
		jQuery( '#screen-iframe' ).css({
			'width': w,
			'height': h,
			'margin-left': ml + 'px' ,
			'display': 'initial'
		});
	}
}


// マウスの絶対座標取得 ブラウザ間で取得する数値をnormalizeできるらしい
qahm.getMousePos = (e) => {
	let posx     = 0;
	let posy     = 0;
	if ( ! e) {
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


// 以下プレイヤーの行動を宣言
// 再生 / 一時停止 / 最初に戻る機能
qahm.actionPlayPause = function() {
	switch( qahm.eventState ) {
		case qahm.EVENTSTATE.PAUSE:
			jQuery( '#control-play' ).css( 'display', 'none' );
			jQuery( '#control-pause' ).removeAttr( 'style' );
			jQuery( '#control-replay' ).css( 'display', 'none' );
			qahm.effectIcon.init( qahm.imgPlayIcon );
			
			qahm.eventPrevDate = new Date();
			qahm.eventState    = qahm.EVENTSTATE.PLAY;
			break;
	
		case qahm.EVENTSTATE.PLAY:
			jQuery( '#control-play' ).removeAttr( 'style' );
			jQuery( '#control-pause' ).css( 'display', 'none' );
			jQuery( '#control-replay' ).css( 'display', 'none' );
			qahm.effectIcon.init( qahm.imgPauseIcon );
			
			qahm.eventState = qahm.EVENTSTATE.PAUSE;
			break;
		
		case qahm.EVENTSTATE.END_SET:
		case qahm.EVENTSTATE.END:
			jQuery( '#control-play' ).css( 'display', 'none' );
			jQuery( '#control-pause' ).removeAttr( 'style' );
			jQuery( '#control-replay' ).css( 'display', 'none' );
			qahm.setEventProgress( 0 );
	
			qahm.eventState = qahm.EVENTSTATE.PLAY;
			break;
	}
	jQuery( '#next-replay-container' ).hide();
};


// 指定ms分早送り
qahm.actionForwardMilliSec = function( milliSec ) {
	if( qahm.eventState === qahm.EVENTSTATE.END_SET ||
		qahm.eventState === qahm.EVENTSTATE.END ) {
		return;
	}

	qahm.eventPlayMilliSec += milliSec;
	if ( qahm.eventPlayMilliSec > qahm.eventEndMilliSec ) {
		qahm.eventPlayMilliSec = qahm.eventEndMilliSec;
	}
	qahm.setEventProgress( qahm.eventPlayMilliSec / qahm.eventEndMilliSec );
	qahm.effectIcon.init( qahm.imgForwardIcon );
};


// 指定ms分巻き戻し
qahm.actionBackwardMilliSec = function( milliSec ) {
	qahm.eventPlayMilliSec -= milliSec;
	if ( qahm.eventPlayMilliSec < 0 ) {
		qahm.eventPlayMilliSec = 0;
	}
	qahm.setEventProgress( qahm.eventPlayMilliSec / qahm.eventEndMilliSec );
	qahm.effectIcon.init( qahm.imgBackwardIcon );
};


// スピード変更
qahm.actionSwitchSpeed = function( isNext ) {
	let speed = jQuery( '#control-speed' ).data( 'speed' );
	let speedAry = [ 0.5, 1, 2, 4 ];
	for ( let i = 0; i < speedAry.length; i++ ) {
		if( speed === speedAry[i] ) {
			let speedIndex = i;
			if ( isNext ) {
				speedIndex++;
				if ( speedIndex >= speedAry.length ) {
					speedIndex = 0;
				}
			} else {
				speedIndex--;
				if ( speedIndex < 0 ) {
					speedIndex = speedAry.length - 1;
				}
			}
			qahm.speedRate = speedAry[speedIndex];
			break;
		}
	}
	
	jQuery( '#control-speed' ).data( 'speed', qahm.speedRate );
	jQuery( '#control-speed' ).text( qahm.speedRate + 'x' );
}

qahm.loadOgpImages = function() {
	jQuery('#playlist .playlist-item').each(function() {
		const $item = jQuery(this);
		const $img = $item.find('.playlist-item-thumb img');
		
		const pageUrl = $item.data('page-url');
		
		if ( pageUrl ) {
			jQuery.ajax({
				type: 'POST',
				url: qahm.ajax_url,
				dataType: 'json',
				data: {
					'action': 'qahm_ajax_get_ogp_image',
					'url': pageUrl
				}
			}).done(function(response) {
				if ( response.success && response.image_url ) {
					$img.attr('src', response.image_url);
				}
			}).fail(function() {
			});
		}
	});
};
