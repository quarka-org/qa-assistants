//ベクトル
class QahmVector {
	constructor( x, y ) {
		this.x = x;
		this.y = y;
	}
	//初期値を保存
	saveInitProp() {
		this.startX = this.x;
		this.startY = this.y;
	}
	//ベクトル加算
	addProp( vectorObj ) {
		this.x += vectorObj.x;
		this.y += vectorObj.y;
	}
	//ベクトル減算
	subProp( vectorObj ) {
		this.x -= vectorObj.x;
		this.y -= vectorObj.y;
	}
	//ベクトル乗算
	mult( num ) {
		this.x = this.x * num;
		this.y = this.y * num;
	}
	//ベクトル除算
	div( num ) {
		this.x = this.x / num;
		this.y = this.y / num;
	}
	//ベクトルの大きさを返す
	mag(){
		return Math.sqrt(this.x * this.x + this.y * this.y);
	}
	//正規化する
	normalize() {
		var size = Math.sqrt(this.x * this.x + this.y * this.y);
		if(size === 0){
			return;
		}
		this.x = this.x * (1 / size);
		this.y = this.y * (1 / size);
	}
	//最大値
	limit( max ) {
		if(this.x > max){
			this.x = max;
		}
		if(this.x * -1 > max){
			this.x = max * -1;
		}
		if(this.y > max){
			this.y = max;
		}
		if(this.y * -1 > max){
			this.y = max * -1;
		}
	}
	//長さ１のランダムな値を返す
	random2D(){
		this.x = (Math.random()*2)-1;
		this.y = (Math.random()*2)-1;
		return this.normalize();
	}
	//同じ値をもったVectorを返す
	copy(){
		return new QahmVector( this.x, this.y );
	}
}


// マウスの動き
class QahmMouseLine {
	constructor( startX, startY ){
		this.velocity     = new QahmVector( 0, 0 );
		this.acceleration = new QahmVector( 0, 0 );
		this.vLocation    = new QahmVector( startX, startY );
		this.maxSpeed     = 20 + ( ( qahm.speedRate - 1 ) * 20 );
		this.maxSeek      = 1.2 + ( ( qahm.speedRate - 1 ) * 14 );
		this.angle        = 0;
		this.fillColor    = 'rgba(255,99,71,1.0)';
		this.line         = [];
		this.lineLength   = 80;
		this.setUpLine();
	}

	// 線を初期化
	setUpLine(){
		for ( let i = 0; i < this.lineLength; i++ ) {
			this.line[i] = this.vLocation.copy();
		};
	}
	// 操舵力を計算
	calculateSeek(){
		//this.maxSpeed = 20 + ( ( qahm.speedRate - 1 ) * 20 );
		//this.maxSeek  = 1.2 + ( ( qahm.speedRate - 1 ) * 14 );
		this.maxSpeed = 20 * qahm.speedRate;
		this.maxSeek  = 6 * qahm.speedRate;

		//目的地へのベクトル
		var desire = qahm.destination.vLocation.copy();
		desire.subProp( this.vLocation );
		var distance = desire.mag();
		if( distance > 150 ){
			desire.normalize();
			desire.mult( this.maxSpeed );
		}
		else{
			desire.normalize();
			var speed = ( this.maxSpeed * distance ) / 150;
			desire.mult( speed );
		}
		//目的地へ向いたベクトルから現在の速度を引く
		var seekVelocity = desire.copy();
		seekVelocity.subProp( this.velocity );
		seekVelocity.limit( this.maxSeek );
		this.applyForce( seekVelocity );
	}
	//力を適用
	applyForce( Vector ){
		this.acceleration.addProp( Vector );
	}
	//位置を更新
	uptateState(){
		this.calculateSeek();
		this.velocity.addProp( this.acceleration);
		this.vLocation.addProp( this.velocity );
		this.angle = Math.atan2( this.velocity.y, this.velocity.x);
		this.acceleration.mult(0);
		this.updateLine();
	}
	// 線の位置を更新
	updateLine(){
		this.line.push( this.vLocation.copy() );
		this.line = this.line.slice( 1,this.lineLength + 1 );
	}

	// 描画
	draw(){
		let alpha = 0;
		for ( let i = 1; i < this.lineLength; i++ ) {
			alpha += ( 1 / this.lineLength );
			if( alpha > 1.0 ) {
				alpha = 1.0;
			}
			let lineWidth = 1 + ( i * 4 / this.lineLength );
			let color     = 'rgba(255, 99, 71,' + alpha + ')';

			qahm.ctx.globalCompositeOperation = 'destination-out';
			qahm.ctx.beginPath();
			qahm.ctx.lineCap   = 'round';
			qahm.ctx.lineWidth = lineWidth;
			qahm.ctx.moveTo( this.line[i-1].x,this.line[i-1].y );
			qahm.ctx.lineTo( this.line[i-1].x, this.line[i-1].y );
			qahm.ctx.strokeStyle = 'rgba(255,255,255,1)';
			qahm.ctx.stroke();
			qahm.ctx.globalCompositeOperation = 'source-over';

			qahm.ctx.beginPath();
			qahm.ctx.lineCap   = 'round';
			qahm.ctx.lineWidth = lineWidth;
			qahm.ctx.moveTo( this.line[i-1].x,this.line[i-1].y );
			qahm.ctx.lineTo( this.line[i].x, this.line[i].y );
			qahm.ctx.strokeStyle = color;
			qahm.ctx.stroke();
		};
	}
}

//目的地

//目的地
class QahmDestination {
	constructor( startX, startY ){
		this.radius    = 30;
		this.fillColor = 'rgba(255,240,0,0.5)';
		this.vLocation = new QahmVector( startX, startY );
	}

	setPos( x, y ){
		this.vLocation.x = x;
		this.vLocation.y = y;
	}

	draw(){
		qahm.ctx.beginPath();
		qahm.ctx.arc( this.vLocation.x, this.vLocation.y, this.radius, 0, Math.PI * 2, true );
		qahm.ctx.closePath();
		qahm.ctx.fillStyle = this.fillColor;
		qahm.ctx.fill();
		
		qahm.ctx.drawImage( qahm.imgMouseIcon, this.vLocation.x, this.vLocation.y );
	}
}


// 波紋
class QahmMouseRipple {
	constructor( x, y ){
		this.radius = 4;
		this.alpha  = 0.5;
		this.x      = x;
		this.y      = y;
		this.isDead = false;
	}

	update() {
		if ( this.isDead ) {
			return;
		}

		this.alpha -= 0.015;
		if ( this.alpha <= 0 ) {
			this.isDead = true;
			return;
		}
		
		this.radius += 1.5;
	}

	draw() {
		if ( this.isDead ) {
			return;
		}

		let fillColor = 'rgba(255,99,71,' + this.alpha + ')';
		
		qahm.ctx.beginPath();
		qahm.ctx.arc( this.x, this.y, this.radius, 0, Math.PI * 2, true );
		qahm.ctx.closePath();
		qahm.ctx.fillStyle = fillColor;
		qahm.ctx.fill();
	}
}


// 画面真ん中に表示するアイコンのエフェクト
class QahmEffectIcon {
	constructor(){
		this.img   = null;
	}

	init( img ) {
		this.img   = img;
		this.alpha = 0.7;
		this.size  = 100;
	}

	update() {
		if ( this.img === null ) {
			return;
		}
		
		this.alpha -= 0.025;
		this.size  += 3;
		if ( this.alpha <= 0 ) {
			this.img = null;
			return;
		}
	}

	draw() {
		if ( this.img === null ) {
			return;
		}

		let centerX = qahm.canvas.width / 2;
		let centerY = qahm.canvas.height / 2;
		let tempAlpha = qahm.ctx.globalAlpha;
		
		qahm.ctx.globalAlpha = this.alpha;
		qahm.ctx.drawImage( this.img, centerX - this.size / 2, centerY - this.size / 2, this.size, this.size );
		qahm.ctx.globalAlpha = tempAlpha;
	}
}