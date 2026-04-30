var qahm = qahm || {};

qahm.loadEffect.promise()
.then( function () {
	if( qahm.license_confetti ) {
		qahm.startConfetti();
		AlertMessage.alert(
			qahml10n.powerup_title,
			qahml10n.powerup_text,
			'success',
			function(){
				qahm.stopConfetti();
			}
		);
	}
});