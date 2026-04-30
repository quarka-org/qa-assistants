try {
	var qahm        = qahm || {};
	qahm.loadEffect = new jQuery.Deferred();

	qahm.loadScreen.promise()
	.then( function () {
		window.requestAnimationFrame = window.requestAnimationFrame || window.mozRequestAnimationFrame || window.webkitRequestAnimationFrame;

		qahm.effectResizeId;
		qahm.effectCanvas = document.querySelector( '#qahm-effect-canvas' );
		qahm.effectCanvas.width = window.innerWidth;
		qahm.effectCanvas.height = window.innerHeight;
		qahm.effectCtx = qahm.effectCanvas.getContext( '2d' );
		qahm.effectCtx.globalCompositeOperation = 'source-over';
		qahm.confetti = [];
		qahm.confettiIndex = 0;
		qahm.confettiFrameId = 0;
		qahm.isConfettiStop = false;

		qahm.getRandom = function( min, max ) {
			return Math.random() * ( max - min ) + min;
		}

		qahm.createConfetti = function ( x, y, vx, vy, color ){
			this.x = x;
			this.y = y;
			this.vx = vx;
			this.vy = vy;
			this.color = color;
			qahm.confetti[qahm.confettiIndex] = this;
			this.id = qahm.confettiIndex;
			qahm.confettiIndex++;
			this.life = 0;
			this.maxlife = 60 * 8;
			this.degree = qahm.getRandom( 0,360 );			// 開始角度をずらす
			this.size = Math.floor(qahm.getRandom( 8,10 ));	// 紙吹雪のサイズに変化をつける
		};

		qahm.createConfetti.prototype.draw = function(x, y){

			this.degree += 1;
			this.vx *= 0.9;	    // 重力
			this.vy *= 0.999;	// 重力
			this.x += this.vx+Math.cos(this.degree*Math.PI/180);	// 蛇行
			this.y += this.vy;
			this.width = this.size;
			this.height = Math.cos(this.degree*Math.PI/45)*this.size;	// 高さを変化させて、回転させてるっぽくみせる
			
			// 紙吹雪の描画
			qahm.effectCtx.fillStyle = this.color;
			qahm.effectCtx.beginPath();
			qahm.effectCtx.moveTo( this.x+this.x/2, this.y+this.y/2 );
			qahm.effectCtx.lineTo( this.x+this.x/2+this.width/2, this.y+this.y/2+this.height );
			qahm.effectCtx.lineTo( this.x+this.x/2+this.width+this.width/2, this.y+this.y/2+this.height );
			qahm.effectCtx.lineTo( this.x+this.x/2+this.width, this.y+this.y/2 );
			qahm.effectCtx.closePath();
			qahm.effectCtx.fill();
			this.life++;
			
			// lifeがなくなったら紙吹雪を削除
			if( this.life >= this.maxlife ){
				delete qahm.confetti[this.id];
			}
		}

		function confettiMove(){
			// 全画面に色をしく。透過率をあげると残像が強くなる
			qahm.effectCtx.clearRect( 0, 0, qahm.effectCanvas.width, qahm.effectCanvas.height );

			let colorAry = [
				'#eda6a7',
				'#b5e671',
				'#f8f666',
				'#9eddf9',
				'#eac746',
				'#eecff9',
			];

			// 紙吹雪の量の調節
			if ( qahm.isConfettiStart || ( qahm.confettiFrameId % 3 == 0 && ! qahm.isConfettiStop ) ) {
				for ( let i=0; i < 2; i++ ) {
					new qahm.createConfetti(
						qahm.effectCanvas.width*Math.random()-qahm.effectCanvas.width+qahm.effectCanvas.width/2*Math.random(),
						0,
						qahm.getRandom(1, 3),
						qahm.getRandom(2, 4),
						colorAry[ Math.floor( qahm.getRandom(0, colorAry.length) ) ]
						);

					new qahm.createConfetti(
						qahm.effectCanvas.width*Math.random()+qahm.effectCanvas.width-qahm.effectCanvas.width*Math.random(),
						0,
						-1 * qahm.getRandom(1, 3),
						qahm.getRandom(2, 4),
						colorAry[ Math.floor( qahm.getRandom(0, colorAry.length) ) ]
						);
				}
					
				qahm.isConfettiStart = false;
			}

			let isDraw = false;
			for( let i in qahm.confetti ){
				qahm.confetti[i].draw();
				isDraw = true;
			}
			if ( isDraw ) {
				qahm.confettiFrameId = requestAnimationFrame( confettiMove );
			} else {
				qahm.confettiIndex = 0;
				qahm.confetti.splice(0);
				cancelAnimationFrame( qahm.confettiFrameId ) ;
			}
		}

		qahm.startConfetti = function() {
			qahm.isConfettiStop = false;
			qahm.isConfettiStart = true;
			cancelAnimationFrame( qahm.confettiFrameId ) ;
			confettiMove() ;
		}
		
		qahm.stopConfetti = function() {
			qahm.isConfettiStop = true;
			//cancelAnimationFrame( qahm.confettiFrameId ) ;
		}

		// リサイズ処理
		window.addEventListener('resize', function(){
			if ( qahm.effectResizeId !== null ) {
				clearTimeout( qahm.effectResizeId );
			}
				
			qahm.effectResizeId = setTimeout( function () {
				qahm.effectCanvas.width = window.innerWidth;
				qahm.effectCanvas.height = window.innerHeight;
			}, 300 );
		});

		qahm.loadEffect.resolve();
	});
} catch (e) {
	console.log( e.message );
}


