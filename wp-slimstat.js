function slimstat_detectPlugin(substrs) {
	if (navigator.plugins) {
		for (var i = 0; i < navigator.plugins.length; i++) {
			var plugin = navigator.plugins[i];
			var haystack = plugin.name + plugin.description;
			var found = 0;

			for (var j = 0; j < substrs.length; j++) {
				if (haystack.indexOf(substrs[j]) != -1) {
					found++;
				}
			}
			
			if (found == substrs.length) {
				return true;
			}
		}
	}

	return false;
}

function slimstat_detectObject(progIds, fns) {
	for (var i = 0; i < progIds.length; i++) {
		try {
			var obj = new ActiveXObject(progIds[i]);

			if (obj) {			
				return fns && fns[i]
					? fns[i].call(obj)
					: true;
			}
		} catch (e) {
			// Ignore
		}
	}

 	return false;
}

function slimstat_setCookie(cookie_name, value){
	var expiration_date = new Date();
	expiration_date.setMinutes(expiration_date.getMinutes()+30);
	document.cookie=cookie_name+"="+escape(value)+";expires="+expiration_date.toUTCString()+";path=/";
}

function slimstat_getCookie(cookie_name){
	var results = document.cookie.match ( '(^|;) ?' + cookie_name + '=([^;]*)(;|$)' );

	if ( results ) return ( unescape ( results[2] ) );
	
	return null;
}

var slimstat_plugins = {
	java: {
		substrs: [ "Java" ],
		progIds: [ "JavaWebStart.isInstalled" ]
	},
	acrobat: {
		substrs: [ "Adobe", "Acrobat" ],
		progIds: [ "AcroPDF.PDF", "PDF.PDFCtrl.5" ]
	},
	flash: {
		substrs: [ "Shockwave", "Flash" ],
		progIds: [ "ShockwaveFlash.ShockwaveFlash" ]
	},
	director: {
		substrs: [ "Shockwave", "Director" ],
		progIds: [ "SWCtl.SWCtl" ]
	},
	quicktime: {
		substrs: [ "QuickTime" ],
		progIds: [ "QuickTimeCheckObject.QuickTimeCheck" ],
		fns: [ function () { return this.IsQuickTimeAvailable(0); } ]
	},
	real: {
		substrs: [ "RealPlayer" ],
		progIds: [
			"rmocx.RealPlayer G2 Control",
			"RealPlayer.RealPlayer(tm) ActiveX Control (32-bit)",
			"RealVideo.RealVideo(tm) ActiveX Control (32-bit)"
		]
	},
	mediaplayer: {
		substrs: [ "Windows Media" ],
		progIds: [ "MediaPlayer.MediaPlayer" ]
	},
	silverlight: {
		substrs: [ "Silverlight" ],
		progIds: [ "AgControl.AgControl" ]
	}
};

var slimstat_uniwin = {
	width: window.innerWidth || document.documentElement.clientWidth
		|| document.body.offsetWidth,
	height: window.innerHeight || document.documentElement.clientHeight
		|| document.body.offsetHeight
};

// Set a cookie to track this visit
var slimstat_tracking_code = slimstat_getCookie('slimstat_tracking_code');
if (slimstat_tracking_code == null || slimstat_tracking_code.length != 32) {
	slimstat_setCookie('slimstat_tracking_code', slimstat_session_id);
}

slimstat_info = "?sw="+screen.width;
slimstat_info += "&sh="+screen.height;
slimstat_info += "&cd="+screen.colorDepth;
slimstat_info += "&aa="+(screen.fontSmoothingEnabled?'1':'0');
slimstat_info += "&id="+slimstat_tid;
slimstat_info += "&sid="+slimstat_session_id;
slimstat_info += "&pl=";

for (var slimstat_alias in slimstat_plugins) {
	var slimstat_plugin = slimstat_plugins[slimstat_alias];
		if (slimstat_detectPlugin(slimstat_plugin.substrs) || slimstat_detectObject(slimstat_plugin.progIds, slimstat_plugin.fns)) {
        slimstat_info += slimstat_alias +"|";
	}
}
if (typeof XMLHttpRequest == "undefined") {
	XMLHttpRequest = function () {
		try { return new ActiveXObject("Msxml2.XMLHTTP.6.0"); }
		catch (e1) {}
		try { return new ActiveXObject("Msxml2.XMLHTTP.3.0"); }
		catch (e2) {}
		try { return new ActiveXObject("Msxml2.XMLHTTP"); }
		catch (e3) {}
		//Microsoft.XMLHTTP points to Msxml2.XMLHTTP.3.0 and is redundant
		throw new Error("This browser does not support XMLHttpRequest.");
	};
}
var slimstat_request = false;
try {
	slimstat_request = new XMLHttpRequest();
} catch (failed) {
	slimstat_request = false;
}
if (slimstat_request) {
	slimstat_url = slimstat_path+'/wp-slimstat/wp-slimstat-js.php'+slimstat_info;
	slimstat_request.open('GET', slimstat_url, true);
	slimstat_request.send(null);
}