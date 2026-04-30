try {
	var qahm        = qahm || {};
	qahm.loadScreen = new jQuery.Deferred();

	qahm.progressID    = 0;
	qahm.progressNow   = 0;
	qahm.progressStart = 0;
	qahm.progressGoal  = 0;
	qahm.progressAccel = 0;		// 加速

	// ロードアイコンの表示
	qahm.showLoadIcon = function( func=null) {
		if ( func !== null ) {
			const dofunc = function(){
				let icon = document.getElementById('qahm-loading-container');
				let def  = new jQuery.Deferred();
				if (icon !== null) {
					icon.classList.add('qahm-fadein');
				}
				setTimeout(function () {
					def.resolve();
                },100);
				return def.promise();
			};
			dofunc().then( function(){func()}, function(){return false} );
		} else {
	        jQuery('#qahm-loading-container').addClass('qahm-fadein');
		}
	};

	// ロードアイコンを非表示
	qahm.hideLoadIcon = function() {
		jQuery( '#qahm-loading-container' ).removeClass( 'qahm-fadein' );
	};

	// プログレスバーの目標値設定
	qahm.setProgressBar = function( goal, text, func=null ) {
		qahm.progressStart = qahm.progressNow;
		qahm.progressGoal  = Math.floor( goal );
		if ( qahm.progressID === 0 ) {
			qahm.progressAccel = 1.0;
			qahm.progressID    = setInterval( qahm.moveProgressBar, 10 );
			jQuery( '#qahm-progress-container' ).addClass( 'qahm-fadein' );
		}
		let progPercent         = document.getElementById( 'qahm-progress-text' );
		progPercent.textContent = text;

		// goalに到達した瞬間の処理
		if ( func !== null ) {
			let id = setInterval(
				function(){
					if ( Math.floor( qahm.progressNow ) === goal ) {
						func();
						clearInterval( id );
					}
				},
				100
			);
		}
	};

	// プログレスバーの動作
	qahm.moveProgressBar = function() {
		let accel = ( qahm.progressGoal - qahm.progressStart ) * 0.002;
		let decel = ( qahm.progressGoal - qahm.progressStart ) * 0.0024;
		if ( ( qahm.progressGoal - qahm.progressStart ) / 2 > qahm.progressNow - qahm.progressStart ) {
			qahm.progressAccel += accel;
		} else {
			qahm.progressAccel -= decel;
		}

		let now = qahm.progressNow;
		qahm.progressNow += qahm.progressAccel;
		if ( qahm.progressNow < now ) {
			qahm.progressNow = now + 0.01;
		}

		if ( qahm.progressNow > qahm.progressGoal ) {
			qahm.progressNow = qahm.progressGoal;
		}
		// console.log( qahm.progressNow );

		// rgb color
		const startColor = [ 153, 207, 229 ];
		const goalColor  = [ 0, 127, 177 ];
		const percent    = Math.floor( qahm.progressNow );
		let nowColor     = goalColor;
		if ( percent < 100 ) {
			nowColor[0] = Math.floor( ( startColor[0] - goalColor[0] ) * ( ( 100 - percent ) / 100 ) ) + goalColor[0];
			nowColor[1] = Math.floor( ( startColor[1] - goalColor[1] ) * ( ( 100 - percent ) / 100 ) ) + goalColor[1];
			nowColor[2] = Math.floor( ( startColor[2] - goalColor[2] ) * ( ( 100 - percent ) / 100 ) ) + goalColor[2];
		}

		let progBar                   = document.getElementById( 'qahm-progress-bar' );
		progBar.style.width           = percent + '%';
		progBar.style.backgroundColor = 'rgb(' + nowColor[0] + ',' + nowColor[1] + ',' + nowColor[2] + ')';

		let progPercent         = document.getElementById( 'qahm-progress-percent' );
		progPercent.textContent = percent + '%';

		if ( qahm.progressNow === qahm.progressGoal ) {
			qahm.clearProgressBar();
		}
	};

	// プログレスバーのクリア
	qahm.clearProgressBar = function() {
		if ( qahm.progressID !== 0 ) {
			clearInterval( qahm.progressID );
			qahm.progressID = 0;
		}
		qahm.progressStart = 0;
		qahm.progressGoal  = 0;
		qahm.progressAccel = 0;
		if ( qahm.progressNow === 100 ) {
			qahm.progressNow = 0;
			jQuery( '#qahm-progress-container' ).removeClass( 'qahm-fadein' );
		}
	};

	window.addEventListener( 'load', function() {
		const docCont = document.getElementById( 'qahm-container' );
		if ( docCont ) {
			qahm.loadScreen.resolve();
		} else {
			let html = 
				'<div id="qahm-container">' +
					'<div id="qahm-loading-container" class="qahm-fade">' +
						'<div id="qahm-loading"></div>' +
					'</div>' +
					'<div id="qahm-progress-container" class="qahm-fade">' +
						'<div id="qahm-progress-back">' +
							'<div id="qahm-progress-bar"></div>' +
						'</div>' +
						'<div id="qahm-progress-info">' +
							'<p id="qahm-progress-text"></p>' +
							'<p id="qahm-progress-percent"></p>' +
						'</div>' +
					'</div>' +
					'<div id="qahm-effect-container">' +
						'<canvas id="qahm-effect-canvas"></canvas>' +
					'</div>' +
				'</div>';
			html = jQuery( html );
			jQuery( 'body' ).append( html );
			html.ready( function() { qahm.loadScreen.resolve(); } );
		}
	});
	
} catch (e) {
	console.log( e.message );
}
