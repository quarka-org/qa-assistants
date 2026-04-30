var qahm = qahm || {};

qahm.docHeight             = -1;
qahm.docWidth              = -1;
qahm.prevDocWidth          = -1;
qahm.lastAppliedHeight     = -1;
qahm.heatMapCreateY        = -1;
qahm.isCreateScrollMap     = false;
qahm.isCreateAttentionMap  = false;
qahm.isCreateClickHeatMap  = false;
qahm.isCreateClickCountMap = false;
qahm.useIdScrollMap        = 0;
qahm.useIdAttentionMap     = 0;
qahm.useIdClickHeatMap     = 0;
qahm.useIdClickCountMap    = 0;
qahm.isScrollMapEvent      = false;
qahm.canvasMargin          = 2000;

// qahm.blockAryの配列には平均滞在時間、合計滞在時間、その地点に滞在した人数、離脱した人数を格納
qahm.BLOCK_AVG_STAY_TIME   = 0;
qahm.BLOCK_TOTAL_STAY_TIME = 1;
qahm.BLOCK_TOTAL_STAY_NUM  = 2;
qahm.BLOCK_TOTAL_EXIT_NUM  = 3;
qahm.blockHeight           = 100;


/*
・1秒毎に状態をチェック。サイトの高さ、画面幅など
 　どれかが変わった瞬間にマップを再構築する
・ヒートマップの場合は上記にくわえて自分の位置（スクロールトップ）もみる
・データ数軽減のためヒートマップのみ自分の画面±2000pxを上限とする
・ロード画面はユーザーが横幅を変更したときのみだす
・フラッシュ用のレイヤーを作って交互に表示
・高さが変わったときのみスクロール、アテンションマップは再構築
・クリックデータの数が3万以上なら弾く、など。これはマージデータを作る際にすべき。じゃないと位置によってデータがでたりでなかったりする
*/

/*
課題：横幅
更新タイミング重複
翻訳
*/

qahm.updateHeatmapBarHeight = function() {
	const heatmapBar = document.getElementById('heatmap-bar');
	if (heatmapBar) {
		const barHeight = heatmapBar.offsetHeight;
		if (barHeight > 0) {
			document.documentElement.style.setProperty('--heatmap-bar-height', barHeight + 'px');
		}
	}
};

// 初期化
qahm.initMapParam = function(){
    let bodyHeight = qahm.iframeDoc.body.scrollHeight;
    //let elementHeight = qahm.iframeDoc.documentElement.scrollHeight;
    //let clientHeight = qahm.iframeDoc.documentElement.clientHeight;
    qahm.docHeight = bodyHeight;

	qahm.docWidth = Math.max.apply(
		null,
		[qahm.iframeDoc.body.clientWidth,
		qahm.iframeDoc.body.scrollWidth,
		qahm.iframeDoc.documentElement.clientWidth,
		qahm.iframeDoc.documentElement.scrollWidth]
	);
	qahm.prevDocWidth = qahm.docWidth;

	jQuery( '#heatmap-content' ).css( 'height', qahm.docHeight );
	qahm.heatMapCreateY = window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
	
	qahm.updateHeatmapBarHeight();
};


qahm.correctScroll = function() {
	let scrollTop;
	let scrollLeft;
	
	if (typeof qahm.iframeWin.jQuery !== 'undefined') {
		scrollTop = jQuery( qahm.iframeWin ).scrollTop();
		scrollLeft = jQuery( qahm.iframeWin ).scrollLeft();
	} else {
		scrollTop = qahm.iframeWin.pageYOffset || qahm.iframeDoc.documentElement.scrollTop || qahm.iframeDoc.body.scrollTop || 0;
		scrollLeft = qahm.iframeWin.pageXOffset || qahm.iframeDoc.documentElement.scrollLeft || qahm.iframeDoc.body.scrollLeft || 0;
	}
	
	jQuery( '#heatmap-container' ).scrollTop( scrollTop );
	jQuery( '#heatmap-container' ).scrollLeft( scrollLeft );
};

qahm.addIframeEvent = function() {
	if (typeof qahm.iframeWin.jQuery !== 'undefined') {
		jQuery( qahm.iframeWin ).scroll(function () {
			qahm.correctScroll();
		});
	} else {
		qahm.iframeWin.addEventListener('scroll', function() {
			qahm.correctScroll();
		});
	}
};

// マップの更新チェック
qahm.checkUpdateMap = function(){
	// ページ内リンククリック対策
	/*
	let iframeWin = document.getElementById('heatmap-iframe').contentWindow;
	if ( qahm.iframeDoc.URL !== iframeWin.document.URL ) {
		jQuery( qahm.iframeWin ).unbind();
		qahm.iframeWin = iframeWin;
		qahm.iframeDoc = iframeWin.document;
		qahm.addIframeEvent();
	}
	*/

	// マップ構築処理が処理中の場合はスルー
	if( qahm.isCreateScrollMap || qahm.isCreateAttentionMap || qahm.isCreateClickHeatMap || qahm.isCreateClickCountMap ) {
		return;
	}

    let bodyHeight = qahm.iframeDoc.body.scrollHeight;
    //let elementHeight = qahm.iframeDoc.documentElement.scrollHeight;
    //let clientHeight = qahm.iframeDoc.documentElement.clientHeight;
    qahm.docHeight = bodyHeight;

	qahm.docWidth = Math.max.apply(
		null,
		[qahm.iframeDoc.body.clientWidth,
		qahm.iframeDoc.body.scrollWidth,
		qahm.iframeDoc.documentElement.clientWidth,
		qahm.iframeDoc.documentElement.scrollWidth]
	);

	let scrollTop = window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
	
	if( qahm.prevDocWidth !== qahm.docWidth ) {
		qahm.updateHeatmapBarHeight();
	}
	
	// ページ高さまたは幅が変更された場合のみ再構築
	if( qahm.docHeight !== qahm.lastAppliedHeight ||
		qahm.prevDocWidth !== qahm.docWidth ) {
		
		// すべてのコンテナ要素に正しい高さを設定 
		jQuery( '#heatmap-iframe-container' ).css( 'height', qahm.docHeight );
		jQuery( '#heatmap-iframe' ).css( 'height', qahm.docHeight );
		jQuery( '#heatmap-content' ).css( 'height', qahm.docHeight );
		qahm.lastAppliedHeight = qahm.docHeight;

		// ヒートマップの再構築
		qahm.createBlockArray();
		qahm.createClickCountMap();
		qahm.createClickHeatMap();
		qahm.createScrollMap();
		qahm.createAttentionMap();
		qahm.correctScroll();
		qahm.heatMapCreateY = scrollTop;
	} else if (
		scrollTop > qahm.heatMapCreateY + ( qahm.canvasMargin / 2 ) ||
		scrollTop < qahm.heatMapCreateY - ( qahm.canvasMargin / 2 ) )
	{
		// スクロール位置が大きく変わった場合のみクリック系マップを更新  
		qahm.createClickCountMap();
		qahm.createClickHeatMap();
		qahm.heatMapCreateY = scrollTop;
	}
	qahm.prevDocWidth = qahm.docWidth;
};


// 上部バーの設定を変更した際の処理
qahm.changeBarConfig = function(){
	jQuery( '.heatmap-bar__checkbox-input' ).change( function() {
		let classVal = jQuery(this).attr('class');
		let classVals = classVal.split(' ');
		let name = null;
		let val = jQuery(this).prop('checked');
		for ( let i = 0; i < classVals.length; i++ ) {
			switch ( classVals[i] ) {
				case 'heatmap-bar-scroll':
					name = 'qa_heatmap_bar_scroll';
					break;
				case 'heatmap-bar-attention':
					name = 'qa_heatmap_bar_attention';
					break;
				case 'heatmap-bar-click-heat':
					name = 'qa_heatmap_bar_click_heat';
					if ( val ) {
						qahm.createClickHeatMap();
					}
					break;
				case 'heatmap-bar-click-count':
					name = 'qa_heatmap_bar_click_count';
					if ( val ) {
						qahm.createClickCountMap();
					}
					break;
			}
			if ( name ) {
				break;
			}
		}
		if ( ! name ) {
			return;
		}

		let age = 60 * 60 * 24 * 365 * 2;
		document.cookie = name + '=' + val + '; path=/; max-age=' + age;
	});
}


// 上部バーの操作状態変更
qahm.disabledConfig = function( disabledFlag ){
	jQuery( '.heatmap-bar__checkbox-input' ).prop( 'disabled', disabledFlag );
}


// 全バージョンのデータを吸収したブロック配列を構築
qahm.createBlockArray = function() {
	if ( qahm.mergeASV2 === undefined && qahm.mergeASV1 === undefined ) {
		qahm.blockNum = 0;
		qahm.blockAry = null;
		return;
	}

	/*
		平均値計算メモ
		平均7秒 2人
		平均1秒 80人

		・合計秒
		7 * 2 = 14
		1 * 80 = 80
		14 + 80 = 94

		・合計人
		2 + 80 = 82

		・平均秒
		94 / 82 = 1.15
	*/

	// 全てのバージョンのデータをqahm.blockAry配列に格納
	qahm.blockNum     = Math.ceil( qahm.docHeight / qahm.blockHeight );
	qahm.blockAry    = [];
	for ( let blockIdx = 0; blockIdx < qahm.blockNum; blockIdx++ ) {
		qahm.blockAry[blockIdx] = [ 0, 0, 0, 0 ];
	}

	// 全バージョンのデータから合計滞在時間、人数を求める
	if ( qahm.mergeASV2 ) {
		for ( let mergeIdx = 0; mergeIdx < qahm.mergeASV2.length; mergeIdx++ ) {
			let blockIdx     = qahm.mergeASV2[mergeIdx][qahm.DATA_ATTENTION_SCROLL_STAY_HEIGHT_V2];
			if ( qahm.blockNum <= blockIdx ) {
				break;
			}
			let avgStayTime  = qahm.mergeASV2[mergeIdx][qahm.DATA_ATTENTION_SCROLL_STAY_TIME_V2];
			let totalStayNum = qahm.mergeASV2[mergeIdx][qahm.DATA_ATTENTION_SCROLL_STAY_NUM_V2];
			let totalExitNum = qahm.mergeASV2[mergeIdx][qahm.DATA_ATTENTION_SCROLL_EXIT_NUM_V2];
			qahm.blockAry[blockIdx][qahm.BLOCK_TOTAL_STAY_TIME] += avgStayTime * totalStayNum;
			qahm.blockAry[blockIdx][qahm.BLOCK_TOTAL_STAY_TIME]  = Math.round( qahm.blockAry[blockIdx][qahm.BLOCK_TOTAL_STAY_TIME] * 1000 ) / 1000;
			qahm.blockAry[blockIdx][qahm.BLOCK_TOTAL_STAY_NUM]  += totalStayNum;
			qahm.blockAry[blockIdx][qahm.BLOCK_TOTAL_EXIT_NUM]  += totalExitNum;
		}
	}

	if ( qahm.mergeASV1 ) {
		for ( let mergeIdx = 0; mergeIdx < qahm.mergeASV1.length; mergeIdx++ ) {
			let blockIdx    = Math.floor( qahm.docHeight * qahm.mergeASV1[mergeIdx][qahm.DATA_ATTENTION_SCROLL_PERCENT_V1] / 100 / 100 );
			if ( qahm.blockNum <= blockIdx ) {
				break;
			}
			let avgStayTime  = qahm.mergeASV1[mergeIdx][qahm.DATA_ATTENTION_SCROLL_STAY_TIME_V1];
			let totalStayNum = qahm.mergeASV1[mergeIdx][qahm.DATA_ATTENTION_SCROLL_STAY_NUM_V1];
			let totalExitNum = qahm.mergeASV1[mergeIdx][qahm.DATA_ATTENTION_SCROLL_EXIT_NUM_V1];
			qahm.blockAry[blockIdx][qahm.BLOCK_TOTAL_STAY_TIME] += avgStayTime * totalStayNum;
			qahm.blockAry[blockIdx][qahm.BLOCK_TOTAL_STAY_TIME]  = Math.round( qahm.blockAry[blockIdx][qahm.BLOCK_TOTAL_STAY_TIME] * 1000 ) / 1000;
			qahm.blockAry[blockIdx][qahm.BLOCK_TOTAL_STAY_NUM]  += totalStayNum;
			qahm.blockAry[blockIdx][qahm.BLOCK_TOTAL_EXIT_NUM]  += totalExitNum;
		}
	}

	// 上記データから平均滞在時間を求める
	for ( let blockIdx = 0; blockIdx < qahm.blockNum; blockIdx++ ) {
		if ( qahm.blockAry[blockIdx][qahm.BLOCK_TOTAL_STAY_TIME] > 0 && qahm.blockAry[blockIdx][qahm.BLOCK_TOTAL_STAY_NUM] > 0 ) {
			let avgTime = qahm.blockAry[blockIdx][qahm.BLOCK_TOTAL_STAY_TIME] / qahm.blockAry[blockIdx][qahm.BLOCK_TOTAL_STAY_NUM];
			avgTime = Math.round( avgTime * 1000 ) / 1000;
			qahm.blockAry[blockIdx][qahm.BLOCK_AVG_STAY_TIME] = avgTime;
		}
	}
};


// 配列の指定パーセントの位置（インデックス）に対応した値を返す関数
qahm.getArrayRateValue = function( rateAry, rateVal ) {
	if ( rateVal <= 0 ) {
		return Math.min.apply( null, rateAry );
	} else if ( rateVal >= 100 ) {
		return Math.max.apply( null, rateAry );
	}

	// 降順に並んだ配列に位置する要素のインデックス
	let idx = Math.floor( ( rateAry.length * rateVal / 100 ) - 0.01 );
	return rateAry[idx];
}

qahm.getSiblingElemetsIndex = function( el, name ) {
	let index = 1;
	let sib   = el;

	while ( ( sib = sib.previousElementSibling ) ) {
		if ( sib.nodeName.toLowerCase() === name ) {
			++index;
		}
	}

	return index;
}

qahm.getSelectorFromElement = function( el ) {
	let names = [];
	if ( ! ( el instanceof Element ) ) {
		return names; }

	while ( el.nodeType === Node.ELEMENT_NODE ) {
		let name = el.nodeName.toLowerCase();
		if ( el.id ) {
			// id はページ内で一意となるため、これ以上の検索は不要
			name += '#' + el.id;
			names.unshift( name );
			break;
		}

		// 同じ階層に同名要素が複数ある場合は識別のためインデックスを付与する
		// 複数要素の先頭 ( index = 1 ) の場合、インデックスは省略可能
		//
		let index = qahm.getSiblingElemetsIndex( el, name );
		if ( 1 < index ) {
			name += ':nth-of-type(' + index + ')';
		}

		names.unshift( name );
		el = el.parentNode;
	}

	return names;
}

// クリックヒートマップ
qahm.createClickHeatMap = function(){
	if ( ! qahm.mergeC || qahm.isCreateClickHeatMap || ! jQuery( '.heatmap-bar-click-heat' ).prop( 'checked' ) ) {
		return;
	}

	qahm.isCreateClickHeatMap = true;

	let useId = 0;
	if ( qahm.useIdClickHeatMap === 0 ) {
		useId = 1;
	}

	// canvasの高さを確定させる
	let canvasTop = window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
	let canvasBottom = jQuery(window).innerHeight() + canvasTop;
	
	canvasTop -= qahm.canvasMargin;
	if ( canvasTop < 0 ) {
		canvasTop = 0;
	}

	canvasBottom += qahm.canvasMargin;
	if ( canvasBottom > qahm.docHeight ) {
		canvasBottom = qahm.docHeight;
	}

	//const startTime = Date.now(); // 開始時間

	// jQuery( ～ ).offset()の取得処理は非常に時間がかかるので、一度調べた情報はここに格納して高速化
	let selectorOffsAry = [];

	// ヒートマップを展開する座標配列の設定
	// オフセットを用いているため↓の高さ設定前に設定
	let points = [];
	for ( let i = 0; i < qahm.mergeC.length; i++ ) {
		let offs;
		let selName = qahm.mergeC[i][qahm.DATA_HEATMAP_SELECTOR_NAME];
		if ( selectorOffsAry[selName] ) {
			offs = selectorOffsAry[selName];
		} else {
			let escName = qahm.escapeSelectorString( selName );
			let sel = qahm.iframeDoc.querySelector( escName );
			if ( sel === null ) {
				continue;
			}

			let bounds = sel.getBoundingClientRect();
			offs = {
				top:  bounds.top + (qahm.iframeBody.scrollTop || qahm.iframeHtml.scrollTop) - qahm.iframeHtml.clientTop,
				left: bounds.left + (qahm.iframeBody.scrollLeft || qahm.iframeHtml.scrollLeft) - qahm.iframeHtml.clientLeft
			};
			selectorOffsAry[selName] = offs;
			/*
			selectorOffsAry[selName] = jQuery( selName, qahm.iframeDoc ).offset();
			offs = selectorOffsAry[selName];
			*/
		}

		// タグからの相対座標を求める
		let x = qahm.mergeC[i][qahm.DATA_HEATMAP_SELECTOR_X];
		let y = qahm.mergeC[i][qahm.DATA_HEATMAP_SELECTOR_Y];
		x += offs.left;
		y += offs.top;

		// 描画範囲に入っているか確認
		if ( canvasTop > y || canvasBottom < y ) {
			continue;
		}

		//x = Math.floor( x );
		//y = Math.floor( y );
		y -= canvasTop;

		let point = {
			x: x,
			y: y,
			value: 1
		};
		// qahm.log( 'heatmap_pos:' + point.x + ' ,' + point.y, true );
		points.push( point );
	}

	//const endTime = Date.now(); // 終了時間
	//console.log(endTime - startTime); // 何ミリ秒かかったかを表示する

	const pointsData = {
		max: 2,
		data: points
	};

	// ヒートマップの高さを設定（使用する前提条件）
	const height = canvasBottom - canvasTop;
	jQuery( '#heatmap-click-heat-' + useId ).css( 'top', canvasTop );
	jQuery( '#heatmap-click-heat-' + useId ).css( 'height', height );
	jQuery( '#heatmap-click-heat-' + useId ).css( 'width', '100%' );

	let heatmapInstance = h337.create(
		{
			container: document.querySelector( '#heatmap-click-heat-' + useId ),
			radius: 40
		}
	);
	heatmapInstance.setData( pointsData );

	// setDataが終わったあとにpositionを変更
	// こうしないと駄目
	jQuery( '#heatmap-click-heat-' + useId ).css( 'position', 'absolute' );
	jQuery( '#heatmap-click-heat-' + qahm.useIdClickHeatMap ).empty().removeAttr('style');
	qahm.useIdClickHeatMap    = useId;

	qahm.isCreateClickHeatMap = false;
};

// スクロールマップ
qahm.createScrollMap = function(){
	if ( ! qahm.blockAry || qahm.isCreateScrollMap ) {
		return;
	}
	qahm.isCreateScrollMap = true;
	
	let useId = 0;
	if ( qahm.useIdScrollMap === 0 ) {
		useId = 1;
	}

	// カラーコード。スクロールマップ用の20色
	let colorCode = ["rgba(158, 1, 66, 1)","rgba(186, 33, 72, 1)","rgba(211, 62, 75, 1)","rgba(230, 90, 73, 1)","rgba(242, 118, 75, 1)","rgba(249, 150, 87, 1)","rgba(252, 180, 105, 1)","rgba(254, 207, 126, 1)","rgba(254, 229, 151, 1)","rgba(253, 244, 171, 1)","rgba(247, 250, 175, 1)","rgba(232, 246, 164, 1)","rgba(210, 237, 158, 1)","rgba(179, 224, 161, 1)","rgba(145, 211, 164, 1)","rgba(111, 193, 168, 1)","rgba(82, 169, 175, 1)","rgba(66, 139, 181, 1)","rgba(73, 109, 175, 1)","rgba(94, 79, 162, 0)"];
	let opacity   = 0.7;
	let scrollRateAry = [];

	// 離脱ユーザーのパーセント位置から実際の高さに変換
	/*
	for ( let i = 0; i < qahm.mergeAS.length; i++ ) {
		let exitUserNum = qahm.mergeAS[i][qahm.DATA_ATTENTION_SCROLL_EXIT_USER];
		if( 0 === exitUserNum ){
			continue;
		}
		for ( let j = 0; j < exitUserNum; j++ ) {
			scrollRateAry.push( qahm.docHeight * ( qahm.mergeAS[i][qahm.DATA_ATTENTION_SCROLL_PERCENT] / 100 ) );
		}
	}
	*/
	if ( qahm.blockAry ) {
		for ( let i = 0; i < qahm.blockAry.length; i++ ) {
			let exitNum = qahm.blockAry[i][qahm.BLOCK_TOTAL_EXIT_NUM];
			if( 0 === exitNum ){
				continue;
			}

			for ( let j = 0; j < exitNum; j++ ) {
				scrollRateAry.push( i * qahm.blockHeight );
			}
		}
	}


	let html         = '';
	let scrollMapAry = [];

	// 精読率100%ライン
	scrollMapAry.push( {
		'heightTop'   : 0,
		'heightBottom': qahm.getArrayRateValue( scrollRateAry, 5 ),
		'colorTop'    : colorCode[0],
		'colorBottom' : colorCode[1],
		'line'        : 100
	} );
	
	for ( let i = 1;  i <= 19;  i++ ) {
		let prevScrollMap = scrollMapAry[scrollMapAry.length - 1];

		let heightTop     = prevScrollMap['heightBottom'];
		let heightBottom  = qahm.getArrayRateValue( scrollRateAry, (i+1)*5 );
		if ( heightTop === heightBottom ) {
			continue;
		}

		let colorTop      = prevScrollMap['colorBottom'];
		let colorBottom   = colorCode[i];
		
		scrollMapAry.push( {
			'heightTop'   : heightTop,
			'heightBottom': heightBottom,
			'colorTop'    : colorTop,
			'colorBottom' : colorBottom,
			'line'        : 100 - i*5
		} );
	}

	// 精読率0%ライン
	/*
	if ( scrollMapAry[scrollMapAry.length - 1]['heightBottom'] !== qahm.docHeight ) {
		scrollMapAry[scrollMapAry.length - 1]['colorBottom'] = colorCode[colorCode.length - 1];
		scrollMapAry.push( {
			'heightTop'   : scrollMapAry[scrollMapAry.length - 1]['heightBottom'],
			'heightBottom': qahm.docHeight,
			'colorTop'    : scrollMapAry[scrollMapAry.length - 1]['colorBottom'],
			'colorBottom' : colorCode[colorCode.length - 1],
			'line'        : 0
		} );
	}
	*/

	for ( let i = 0;  i < scrollMapAry.length;  i++ ) {
		let nowVal  = scrollMapAry[i]['line'];
		let nextVal = 0;
		if ( i + 1 < scrollMapAry.length ) {
			nextVal = scrollMapAry[i + 1]['line'];
		}

		// この計算式、おそらく微妙に間違っているが大きな問題がないのでこれで
		const clipPadding   = 5;	// 内部に左右5%（計10%）の幅
		const clipMargin    = clipPadding + 10;	// 左右に10%ずつの余白
		const clipPath1     = ( 0 - clipPadding + ( 100 - nowVal ) / 2 ) * ( 100 - clipMargin * 2 ) / 100 + clipMargin;
		const clipPath2     = ( 100 + clipPadding - ( 100 - nowVal ) / 2 ) * ( 100 - clipMargin * 2 ) / 100 + clipMargin;
		const clipPath3     = ( 100 + clipPadding - ( 100 - nextVal ) / 2 ) * ( 100 - clipMargin * 2 ) / 100 + clipMargin;
		const clipPath4     = ( 0 - clipPadding + ( 100 - nextVal ) / 2 ) * ( 100- clipMargin * 2 ) / 100 + clipMargin;

		let styleHeight     = 'height:' + ( scrollMapAry[i]['heightBottom'] - scrollMapAry[i]['heightTop'] ) + 'px;';
		let colorTop        = scrollMapAry[i]['colorTop'];
		let colorBottom     = scrollMapAry[i]['colorBottom'];
		// RGBAの値をアルファ値0に変更する
		if ( i === scrollMapAry.length - 1 ) {
			colorBottom = colorCode[colorCode.length - 1];
		}
		let styleBackground = 'background: linear-gradient( ' + colorTop + ', ' + colorBottom + ' );';
		let styleOpacity    = 'opacity: ' + opacity + ';';
		let scrollVal       = nowVal + '%';
		let styleWidth      = 'width: 100%;';
		let styleMargin     = 'margin: 0 auto;';
		let styleTextAlign  = 'text-align: center;';
		let styleClipPath   = 'clip-path: polygon(' + clipPath1 + '% 0%, ' + clipPath2 + '% 0%, ' + clipPath3 + '% 100%, ' + clipPath4 + '% 100%)';
		html += '<div class="heatmap-scroll-font heatmap-scroll-font-' + qahm.dev + '" style="' + styleHeight + ' ' + styleBackground + ' ' + styleOpacity + ' ' + styleWidth + ' ' + styleMargin + ' ' + styleTextAlign + ' ' + styleClipPath + '">' + scrollVal + '</div>';
	}

	jQuery( '#heatmap-scroll-' + useId ).html( html );
	jQuery( '#heatmap-scroll-' + qahm.useIdScrollMap ).empty().removeAttr('style');
	qahm.useIdScrollMap = useId;

	qahm.isCreateScrollMap = false;

};


qahm.addScrollMapEvent = function() {
	if ( ! qahm.blockAry ) {
		return;
	}
	

	/*
	こちらの処理はスクロールマップをパーセント表示にしていないパターン

	let nowDataNum = qahm.dataNum;
	qahm.stayNumAry = [];
	for ( let blockIdx = 0; blockIdx < qahm.blockAry.length; blockIdx++ ) {
		qahm.stayNumAry[blockIdx] = nowDataNum;
		nowDataNum -= qahm.blockAry[blockIdx][qahm.BLOCK_TOTAL_EXIT_NUM];
	}

	jQuery( qahm.iframeWin ).on( 'mousemove scroll', function(e) {
		qahm.updateScrollMapTooltipDataNum( e.clientY );
	});
	*/
	
	// 滞在人数を100分割した地点で求める
	qahm.stayPerAry = [];
	for ( let stayIdx = 0; stayIdx < 100; stayIdx++ ) {
		qahm.stayPerAry[stayIdx] = 0;
	}
	let nowDataNum = qahm.dataNum;
	qahm.stayPerAry[0] = nowDataNum;
	
	let oldStayIdx     = 0;
	let oldStayDataNum = 0;
	for ( let blockIdx = 0; blockIdx < qahm.blockAry.length; blockIdx++ ) {
		let stayIdx = Math.floor( blockIdx / qahm.blockAry.length * 100 );
		let exitUserNum = qahm.blockAry[blockIdx][qahm.BLOCK_TOTAL_EXIT_NUM];
		nowDataNum -= exitUserNum;

		// +1することでパーセントラインが1個下になる
		stayIdx++;
		if ( stayIdx < 100 ) {
			qahm.stayPerAry[ stayIdx ] = nowDataNum;
		}
		
		if ( oldStayIdx + 1 < stayIdx ) {
			for ( let loopIdx = oldStayIdx + 1; loopIdx < stayIdx; loopIdx++ ) {
				qahm.stayPerAry[loopIdx] = oldStayDataNum;
			}
		}
		oldStayIdx     = stayIdx;
		oldStayDataNum = nowDataNum;
	}

	// マウスのY座標を取得して、その座標に対応する滞在人数を更新
	if (typeof qahm.iframeWin.jQuery !== 'undefined') {
		jQuery( qahm.iframeWin ).mousemove(function(event) {
			qahm.mouseY = event.pageY;
			qahm.updateScrollMapTooltipDataNum();
		});
	} else {
		qahm.iframeWin.addEventListener('mousemove', function(event) {
			qahm.mouseY = event.pageY;
			qahm.updateScrollMapTooltipDataNum();
		});
	}

	// スクロールしたときに、その座標に対応する滞在人数を更新
	if (typeof qahm.iframeWin.jQuery !== 'undefined') {
		jQuery( qahm.iframeWin ).scroll( function() {
			qahm.updateScrollMapTooltipDataNum();
		});
	} else {
		qahm.iframeWin.addEventListener('scroll', function() {
			qahm.updateScrollMapTooltipDataNum();
		});
	}
};


qahm.updateScrollMapTooltipDataNum = function() {
	if ( ! qahm.blockAry ) {
		return;
	}
	
	/*
	こちらの処理はスクロールマップをパーセント表示にしていないパターン

	let mouseY  = jQuery( qahm.iframeWin ).scrollTop() + offsY;
	let stayIdx = Math.floor( mouseY / 100 );
	let scrollStayNum = qahm.stayNumAry[stayIdx];
	if ( scrollStayNum !== undefined ) {
		jQuery( '#heatmap-scroll-data-num' ).text( scrollStayNum + qahml10n.people );
	}
	*/

	let stayIdx = Math.floor( ( qahm.mouseY ) / qahm.docHeight * 100 );
	let scrollStayNum = qahm.stayPerAry[stayIdx];
	if ( scrollStayNum !== undefined ) {
		jQuery( '#heatmap-scroll-data-num' ).text( scrollStayNum + qahml10n.people );
	}
};


// アテンションマップのカラーコードを求める
qahm.getAttentionColor = function( avgStayTime ) {
	const COLOR_CODE = ["#5e4fa2","#4478b2","#4ba0b1","#72c3a7","#a0d9a3","#ccea9f","#ebf7a6","#fbf8b0","#fee89a","#fdca79","#fba35e","#f3784c","#e1524a","#c42c4a","#9e0142"];

	//avgStayTimeという名前だが、これは15を最大値に調整された値（ver1.1.0.0で対応され、1.1.1.0より反映する）
	let colorIdx = Math.round( avgStayTime );
	if( colorIdx >= COLOR_CODE.length ){
		colorIdx = COLOR_CODE.length - 1;
	}
	return COLOR_CODE[colorIdx];
};


// アテンションマップ
qahm.createAttentionMap = function(){
	if ( ! qahm.blockAry || qahm.isCreateAttentionMap ) {
		return;
	}
	qahm.isCreateAttentionMap = true;
	
	let useId = 0;
	if ( qahm.useIdAttentionMap === 0 ) {
		useId = 1;
	}

	let html        = '';
	let colorTop    = qahm.getAttentionColor( 0, 0, qahm.blockNum );
	let colorBottom = '';
	let height      = qahm.blockHeight;
	let opacity     = 0.7;

	for ( let blockIdx = 0; blockIdx < qahm.blockNum; blockIdx++ ) {
		colorBottom = qahm.getAttentionColor( qahm.blockAry[blockIdx][qahm.BLOCK_AVG_STAY_TIME] );
		
		if ( blockIdx === qahm.blockNum - 1 ) {
			height = qahm.docHeight % qahm.blockHeight;
		}
		html += '<div style="height:' + height + 'px; background: linear-gradient( ' + colorTop + ', ' + colorBottom + ' ); opacity: ' + opacity + '; color: #fff;"></div>';

		colorTop = colorBottom;
	}

	jQuery( '#heatmap-attention-' + useId ).html( html );
	jQuery( '#heatmap-attention-' + qahm.useIdAttentionMap ).empty().removeAttr('style');
	qahm.useIdAttentionMap = useId;

	qahm.isCreateAttentionMap = false;
};


// クリックカウントマップ
qahm.createClickCountMap = function(){
	if ( ! qahm.mergeC || qahm.isCreateClickCountMap || ! jQuery( '.heatmap-bar-click-count' ).prop( 'checked' ) ) {
		return;
	}
	qahm.isCreateClickCountMap = true;

	let useId = 0;
	if ( qahm.useIdClickCountMap === 0 ) {
		useId = 1;
	}

	// canvasの高さを確定させる
	let canvasTop = window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
	let canvasBottom = jQuery( window ).innerHeight() + canvasTop;
	
	canvasTop -= qahm.canvasMargin;
	if ( canvasTop < 0 ) {
		canvasTop = 0;
	}

	canvasBottom += qahm.canvasMargin;
	if ( canvasBottom > qahm.docHeight ) {
		canvasBottom = qahm.docHeight;
	}
	
	// DOM追加後に処理を進める
	const width  = qahm.iframeDoc.body.clientWidth;
	const height = canvasBottom - canvasTop;
	let qahmDom = '';
	qahmDom   += '<div id="heatmap-click-count-parts-' + useId + '" style="top:' + canvasTop + 'px; height:' + height + 'px; position: absolute; line-height: 0px; width: 100%;">' +
			'<canvas width="' + width + '" height="' + height + '" style="position: absolute; left: 0px; top: 0px;">' +
			'</canvas></div>';

	qahmDom = jQuery( qahmDom );
	jQuery( '#heatmap-click-count-' + useId ).empty().append( qahmDom );

	// DOM追加後に処理を進める
	qahmDom.ready( function() {
		
		const CLICK_SEL_NUM   = 0;
		const CLICK_SEL_NAME  = 1;
		const CLICK_SEL_X     = 2;
		const CLICK_SEL_Y     = 3;
		const CLICK_SEL_W     = 4;
		const CLICK_SEL_H     = 5;

		// ヒートマップを展開する座標配列の設定
		// オフセットを用いているため↓の高さ設定前に設定
		let points = [];
		let target_tag_ary   = ['a','input','button','textarea'];
		for (let i = 0, d_len = qahm.mergeC.length; i < d_len; i++ ) {
			let find = false;
			let sel_name = qahm.mergeC[i][qahm.DATA_HEATMAP_SELECTOR_NAME];
			let sel_tag_ary = sel_name.split('>');

			for (let j = 0, s_len = sel_tag_ary.length; j < s_len; j++ ) {
				for (let k = 0, t_len = target_tag_ary.length; k < t_len; k++ ) {
					if( sel_tag_ary[j].indexOf( target_tag_ary[k] ) !== 0 ){
						continue;
					}

					if( sel_tag_ary[j].length === target_tag_ary[k].length ||
						sel_tag_ary[j].indexOf( target_tag_ary[k] + '#' ) === 0 ||
						sel_tag_ary[j].indexOf( target_tag_ary[k] + ':' ) === 0 ) {
						find = true;
						break;
					}
				}
				if( find ){
					break;
				}
			}
			if ( ! find ) {
				continue;
			}

			let p_idx = -1;
			let p_len = points.length;
			if ( p_len > 0 ) {
				for (let j = 0; j < p_len; j++ ) {
					if ( qahm.mergeC[i][qahm.DATA_HEATMAP_SELECTOR_NAME] === points[j][CLICK_SEL_NAME] ) {
						p_idx = j;
						break;
					}
				}
			}
			if ( p_idx === -1 ) {
				let escName = qahm.escapeSelectorString( qahm.mergeC[i][qahm.DATA_HEATMAP_SELECTOR_NAME] );
				let sel = qahm.iframeDoc.querySelector( escName );
				if ( sel === null ) {
					continue;
				}
				if ( sel.offsetWidth === 0 || sel.offsetHeight === 0 ) {
					continue;
				}
				
				let bounds = sel.getBoundingClientRect();
				let offsLeft = bounds.left + (qahm.iframeBody.scrollLeft || qahm.iframeHtml.scrollLeft) - qahm.iframeHtml.clientLeft;
				let offsTop  = bounds.top + (qahm.iframeBody.scrollTop || qahm.iframeHtml.scrollTop) - qahm.iframeHtml.clientTop;
				if ( canvasTop > offsTop || canvasBottom < offsTop + sel.offsetHeight ) {
					continue;
				}
				
				points.push(
					[
					1,
					qahm.mergeC[i][qahm.DATA_HEATMAP_SELECTOR_NAME],
					offsLeft,
					offsTop - canvasTop,
					sel.offsetWidth,
					sel.offsetHeight
					]
				);

				/*
				let sel = jQuery( qahm.mergeC[i][qahm.DATA_HEATMAP_SELECTOR_NAME], qahm.iframeDoc );
				let offs = sel.offset();
				if ( offs === undefined ) {
					continue;
				}
				
				if ( canvasTop > offs.top || canvasBottom < offs.top + sel.outerHeight() ) {
					continue;
				}

				//qahm.log( qahm.mergeC[i][qahm.DATA_HEATMAP_SELECTOR_NAME] )
				points.push(
					[
					1,
					qahm.mergeC[i][qahm.DATA_HEATMAP_SELECTOR_NAME],
					offs.left,
					offs.top - canvasTop,
					sel.outerWidth(),
					sel.outerHeight()
					]
				);
				*/
			} else {
				points[p_idx][CLICK_SEL_NUM]++;
			}
		}

		// qahm.log( points );

		// 描画
		let ctx = document.querySelector( '#heatmap-click-count-parts-' + useId + ' > canvas' ).getContext( '2d' );
		for (let i = 0, p_len = points.length; i < p_len; i++ ) {
			let fillColor = [0, 0, 0, 0.8];

			let node = jQuery( points[i][CLICK_SEL_NAME], qahm.iframeDoc );
			let stylePosition = node.css('position');
			if( stylePosition === 'fixed' || stylePosition === 'absolute' ) {
				fillColor[2] = 255;
			} else {
				let parents = node.parents();
				for ( let parentsIdx = 0; parentsIdx < parents.length; parentsIdx++ ) {
					stylePosition = getComputedStyle(parents[parentsIdx]).position;
					if( stylePosition === 'fixed' || stylePosition === 'absolute' ) {
						fillColor[2] = 255;
						break;
					}
				}
			}
			
			// 四角形（輪郭）
			ctx.beginPath();
			ctx.lineWidth   = 5;
			ctx.strokeStyle = 'rgba(' + [255, 200, 200, 1.0] + ')';
			ctx.strokeRect(
				points[i][CLICK_SEL_X],
				points[i][CLICK_SEL_Y],
				points[i][CLICK_SEL_W],
				points[i][CLICK_SEL_H]
			);

			ctx.beginPath();
			ctx.fillStyle = 'rgba(' + fillColor + ')';
			ctx.fillRect(
				points[i][CLICK_SEL_X],
				points[i][CLICK_SEL_Y],
				points[i][CLICK_SEL_W],
				points[i][CLICK_SEL_H]
			);

			// 数字
			ctx.beginPath();
			ctx.font         = '22px bold serif';
			ctx.fillStyle    = 'white';
			ctx.textAlign    = 'center';
			ctx.textBaseline = 'middle';
			ctx.fillText(
				points[i][CLICK_SEL_NUM],
				points[i][CLICK_SEL_X] + points[i][CLICK_SEL_W] / 2,
				points[i][CLICK_SEL_Y] + points[i][CLICK_SEL_H] / 2
			);
		}

		jQuery( '#heatmap-click-count-' + qahm.useIdClickCountMap ).empty().removeAttr('style');
		qahm.useIdClickCountMap = useId;

		qahm.isCreateClickCountMap = false;
	} );
};

/*
	セレクタの文字をエスケープ
*/
qahm.escapeSelectorString = function( str ){
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
	//str = str.replace(/[ !"#$%&'()*+,.\/:;<=>?@\[\\\]^`{|}~]/g, "\\$&");

	if ( find ) {
		return strSplitAry.join('>');
	} else {
		return str;
	}
}
