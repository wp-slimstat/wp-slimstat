var SlimStat = {
	// private properties
	_base64_key_str : "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=",
	_plugins : {
		acrobat: { substrings: [ "Adobe", "Acrobat" ], active_x_strings: [ "AcroPDF.PDF", "PDF.PDFCtrl.5" ] },
		director: { substrings: [ "Shockwave", "Director" ], active_x_strings: [ "SWCtl.SWCtl" ] },
		flash: { substrings: [ "Shockwave", "Flash" ], active_x_strings: [ "ShockwaveFlash.ShockwaveFlash" ] },	
		java: { substrings: [ "Java" ], active_x_strings: [ "JavaWebStart.isInstalled" ] },
		mediaplayer: { substrings: [ "Windows Media" ], active_x_strings: [ "WMPlayer.OCX" ] },
		quicktime: { substrings: [ "QuickTime" ], active_x_strings: [ "QuickTime.QuickTime" ] },	
		real: { substrings: [ "RealPlayer" ], active_x_strings: [ "rmocx.RealPlayer G2 Control", "RealPlayer.RealPlayer(tm) ActiveX Control (32-bit)", "RealVideo.RealVideo(tm) ActiveX Control (32-bit)" ] },	
		silverlight: { substrings: [ "Silverlight" ], active_x_strings: [ "AgControl.AgControl" ] }
	},

	// Base64 encode / decode - http://www.webtoolkit.info/
	_base64_encode : function (input) {
		var chr1, chr2, chr3, enc1, enc2, enc3, enc4, output = "", i = 0;

		input = SlimStat._utf8_encode(input);

		while (i < input.length) {
			chr1 = input.charCodeAt(i++);
			chr2 = input.charCodeAt(i++);
			chr3 = input.charCodeAt(i++);

			enc1 = chr1 >> 2;
			enc2 = ((chr1 & 3) << 4) | (chr2 >> 4);
			enc3 = ((chr2 & 15) << 2) | (chr3 >> 6);
			enc4 = chr3 & 63;

			if (isNaN(chr2)) {
				enc3 = enc4 = 64;
			} else if (isNaN(chr3)) {
				enc4 = 64;
			}

			output = output + SlimStat._base64_key_str.charAt(enc1) + SlimStat._base64_key_str.charAt(enc2) + SlimStat._base64_key_str.charAt(enc3) + this._base64_key_str.charAt(enc4);
		}
		return output;
	},

	_utf8_encode : function (string) {
		var n, c, utftext = "";
		
		string = string.replace(/\r\n/g,"\n");

		for (n = 0; n < string.length; n++) {
			c = string.charCodeAt(n);

			if (c < 128) {
				utftext += String.fromCharCode(c);
			}
			else if((c > 127) && (c < 2048)) {
				utftext += String.fromCharCode((c >> 6) | 192);
				utftext += String.fromCharCode((c & 63) | 128);
			}
			else {
				utftext += String.fromCharCode((c >> 12) | 224);
				utftext += String.fromCharCode(((c >> 6) & 63) | 128);
				utftext += String.fromCharCode((c & 63) | 128);
			}
		}
		return utftext;
	},

	_detect_single_plugin : function (plugin_name) {
		var plugin, haystack, found, i, j;
		
		try {
			if (navigator.plugins) {
				for (i in navigator.plugins) {
					haystack = '' + navigator.plugins[i].name + navigator.plugins[i].description;
					found = 0;

					for (j in SlimStat._plugins[plugin_name].substrings) {
						if (haystack.indexOf(SlimStat._plugins[plugin_name].substrings[j]) != -1) {
							found++;
						}
					}

					if (found == SlimStat._plugins[plugin_name].substrings.length) {
						return true;
					}
				}
			}
		} catch (e) {}
		return false;
	},
	
	_detect_single_plugin_ie : function (plugin_name) {
		var i;
	
		for (i in SlimStat._plugins[plugin_name].active_x_strings) {
			if (detect_active_x_control(SlimStat._plugins[plugin_name].active_x_strings[i])) return true;
		}
		return false;
	},
	
	detect_plugins : function () {
		var a_plugin, plugins = "";

		for (a_plugin in SlimStat._plugins) {
			if (SlimStat._detect_single_plugin(a_plugin) || (plugins_detectable_with_vb && SlimStat._detect_single_plugin_ie(a_plugin))) {
				plugins += a_plugin + "|";
			}
		}

		return plugins;
	},
	
	// From http://www.useragentman.com/blog/2009/11/29/how-to-detect-font-smoothing-using-javascript/
	has_smoothing : function () {
		// IE has screen.fontSmoothingEnabled - sweet!
		if (typeof screen.fontSmoothingEnabled != 'undefined'){
			return Number(screen.fontSmoothingEnabled);
		}
		else{
			try{
				// Create a 35x35 Canvas block.
				var canvasNode = document.createElement('canvas');
				canvasNode.width = "35";
				canvasNode.height = "35";

				// We must put this node into the body, otherwise Safari for Windows does not report correctly.
				canvasNode.style.display = 'none';
				document.body.appendChild(canvasNode);
				var ctx = canvasNode.getContext('2d');

				// draw a black letter 'O', 32px Arial.
				ctx.textBaseline = "top";
				ctx.font = "32px Arial";
				ctx.fillStyle = "black";
				ctx.strokeStyle = "black";

				ctx.fillText("O", 0, 0);

				// start at (8,1) and search the canvas from left to right, top to bottom to see if we can find a non-black pixel.  If so we return 1.
				for (var j = 8; j <= 32; j++){
					for (var i = 1; i <= 32; i++){
						var imageData = ctx.getImageData(i, j, 1, 1).data;
						var alpha = imageData[3];

						if (alpha != 255 && alpha != 0) return 1; // font-smoothing must be on.
					}
				}

				// didn't find any non-black pixels - return 0.
				return 0;
			}
			catch (ex){
				// Something went wrong (for example, Opera cannot use the canvas fillText() method.
				return 0;
			}
		}
	},
	
	send_to_server : function (url, async) {
		if (typeof slimstat_path == 'undefined' || typeof slimstat_tid == 'undefined' || typeof slimstat_session_id == 'undefined' || typeof slimstat_blog_id == 'undefined' || typeof url == 'undefined'){
			return false;
		}

		if (typeof async == 'undefined') var async = true;

		try {
			if (window.XMLHttpRequest) {
				request = new XMLHttpRequest();
			}
			else if (window.ActiveXObject) { // code for IE6, IE5
				request = new ActiveXObject("Microsoft.XMLHTTP");
			}
		} catch (failed) {
			return false;
		}
		if (request) {
			var data = "data="+SlimStat._base64_encode("id="+slimstat_tid+"&sid="+slimstat_session_id+"&bid="+slimstat_blog_id+"&"+url);
			request.open('POST', slimstat_path+'/wp-slimstat-js.php', async);
			request.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
			request.send(data);
			return true;
		}	
		return false;
	},
	
	ss_track : function (e, c, note) {
		// Check function params
		if (typeof e == 'undefined') var e = window.event;
		code = (typeof c == 'undefined')?0:parseInt(c);
		if (typeof note == 'undefined') var note = '';

		var node = (typeof e.target != 'undefined')?e.target:((typeof e.srcElement != 'undefined')?e.srcElement:false);
		if (!node) return false;

		// Old Safari bug
		if (node.nodeType == 3) node = node.parentNode;

		var async = false;
		var parent_node = node.parentNode;
		var node_hostname = '';
		var node_pathname = location.pathname;
		var slimstat_info = '';

		// This handler can be attached to any element, but only A carry the extra info we need
		switch (node.nodeName) {
			case 'FORM':
				if (node.action.length > 0) node_pathname = escape(node.action);
				break;

			case 'INPUT':
				// Let's look for a FORM element
				while (typeof parent_node != 'undefined' && parent_node.nodeName != 'FORM' && parent_node.nodeName != 'BODY') parent_node = parent_node.parentNode;
				if (typeof parent_node.action != 'undefined' && parent_node.action.length > 0) {
					node_pathname = escape(parent_node.action);
					break;
				}

			default:
				if (node.nodeName != 'A') {
					if (typeof node.getAttribute == 'function' && node.getAttribute('id') != 'undefined' && node.getAttribute('id') != null && node.getAttribute('id').length > 0){
						node_pathname = node.getAttribute('id');
						break;
					}
					while (typeof node != 'undefined' && node.nodeName != 'A' && node.nodeName != 'BODY') node = node.parentNode;
				}

				// Anchor in the same page
				if (typeof node.hash != 'undefined' && node.hash.length > 0 && node.hostname == location.hostname) {
					async = true;
					node_pathname = escape(node.hash);
				}
				// Any other element
				else {
					// Wait for the server to respond
					node_hostname = (typeof node.hostname != 'undefined')?node.hostname:'';
					if (typeof node.href != 'undefined') {
						node_pathname = escape(node.href);
					}				
				}

				// If this element has an ID, we can use that for the note
				if (typeof node.getAttribute == 'function' &&
					(note.substring(0, 2) == 'A:' || note.length == 0) &&
					node.getAttribute('id') != 'undefined' &&
					node.getAttribute('id') != null &&
					node.getAttribute('id').length > 0) note = 'ID:'+node.getAttribute('id');
		}
		slimstat_info = "&obd="+node_hostname+"&obr="+node_pathname;

		// Track mouse coordinates
		var pos_x = -1; var pos_y = -1;
		if (typeof e.pageX != 'undefined' && typeof e.pageY != 'undefined') {
			pos_x = e.pageX;
			pos_y = e.pageY;
		}
		else if (typeof e.clientX != 'undefined' && typeof e.clientY != 'undefined' &&
				typeof document.body.scrollLeft != 'undefined' && typeof document.documentElement.scrollLeft != 'undefined' &&
				typeof document.body.scrollTop != 'undefined' && typeof document.documentElement.scrollTop != 'undefined') {
			pos_x = e.clientX+document.body.scrollLeft+document.documentElement.scrollLeft;
			pos_y = e.clientY+document.body.scrollTop+document.documentElement.scrollTop;
		}
		if (pos_x > 0 && pos_y > 0) slimstat_info += ((slimstat_info.length > 0)?'&':'?')+'po='+pos_x+','+pos_y;

		// Event type and button pressed
		note += '|ET:'+e.type;

		if (e.type != 'click' && typeof(e.which) != 'undefined') {
			if (e.type == 'keypress' )
				note += '|BT:'+String.fromCharCode(parseInt(e.which));
			else
				note += '|BT:'+e.which;
		}

		return SlimStat.send_to_server("ty="+code+slimstat_info+"&no="+escape(note), async);
	}
}

// VBScript block for Internet Explorer
var plugins_detectable_with_vb = false;
if ((navigator.userAgent.indexOf('MSIE') != -1) && (navigator.userAgent.indexOf('Win') != -1)) {
    document.writeln('<scr' + 'ipt language="VBscript">');
    document.writeln('\'do a one-time test for a version of VBScript that can handle this code');
    document.writeln('If ScriptEngineMajorVersion >= 2 then');
    document.writeln('  plugins_detectable_with_vb = True');
    document.writeln('End If');
    document.writeln('\'this next function will detect most plugins');
    document.writeln('Function detect_active_x_control(active_x_name)');
    document.writeln('  on error resume next');
    document.writeln('  detect_active_x_control = False');
    document.writeln('  If plugins_detectable_with_vb Then');
    document.writeln('     detect_active_x_control = IsObject(CreateObject(active_x_name))');
    document.writeln('  End If');
    document.writeln('End Function');
    document.writeln('</scr' + 'ipt>');
}

// For backward compatibility
function ss_te (e, c, deprecated, note) { SlimStat.ss_track(e, c, note); }
function ss_track (e, c, note){ SlimStat.ss_track(e, c, note); }

// Tracks Google+1 clicks
function slimstat_plusone (obj) { SlimStat.send_to_server('ty=4&obr='+escape('#google-plus-'+obj.state), true); }

// Attaches an event listener to all external links
var links_in_this_page = document.getElementsByTagName("a");
for (var i=0; i<links_in_this_page.length; i++) {
	if ((typeof slimstat_heatmap == 'undefined') && ((links_in_this_page[i].hostname == location.hostname) ||  (links_in_this_page[i].href.indexOf('://') == -1) || (links_in_this_page[i].className.indexOf('noslimstat') != -1))){
		continue;
	}

	if (links_in_this_page[i].addEventListener) {
		links_in_this_page[i].addEventListener("click", function (i) { return function (e) { SlimStat.ss_track(e, 0, "A:"+(i+1)) } }(i), false);
	}
	else if (links_in_this_page[i].attachEvent){
		links_in_this_page[i].attachEvent("onclick", function (i) { return function (e) { SlimStat.ss_track(e, 0, "A:"+(i+1)) } }(i));
	}
}

// Gathers all the information and sends it to the server
SlimStat.send_to_server("sw="+(window.innerWidth||document.documentElement.clientWidth||document.body.offsetWidth)+"&sh="+(window.innerHeight||document.documentElement.clientHeight||document.body.offsetHeight)+"&cd="+screen.colorDepth+"&aa="+SlimStat.has_smoothing()+"&pl="+SlimStat.detect_plugins());