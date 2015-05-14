var SlimStat = {
	// Private Properties
	_id : (typeof SlimStatParams.id != 'undefined') ? SlimStatParams.id : "-1.0",
	_base64_key_str : "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=",
	_plugins : {
		'acrobat': { substrings: [ "Adobe", "Acrobat" ], active_x_strings: [ "AcroPDF.PDF", "PDF.PDFCtrl.5" ] },
		'director': { substrings: [ "Shockwave", "Director" ], active_x_strings: [ "SWCtl.SWCtl" ] },
		'flash': { substrings: [ "Shockwave", "Flash" ], active_x_strings: [ "ShockwaveFlash.ShockwaveFlash" ] },
		'mediaplayer': { substrings: [ "Windows Media" ], active_x_strings: [ "WMPlayer.OCX" ] },
		'quicktime': { substrings: [ "QuickTime" ], active_x_strings: [ "QuickTime.QuickTime" ] },
		'real': { substrings: [ "RealPlayer" ], active_x_strings: [ "rmocx.RealPlayer G2 Control", "RealPlayer.RealPlayer(tm) ActiveX Control (32-bit)", "RealVideo.RealVideo(tm) ActiveX Control (32-bit)" ] },
		'silverlight': { substrings: [ "Silverlight" ], active_x_strings: [ "AgControl.AgControl" ] }
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
	
	// Base64 Encode - http://www.webtoolkit.info/
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

	_detect_single_plugin_not_ie : function (plugin_name) {
		var plugin, haystack, found, i, j;

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
		return false;
	},
	
	_detect_single_plugin_ie : function (plugin_name) {
		var i;

		for (i in SlimStat._plugins[plugin_name].active_x_strings) {
			try {
				new ActiveXObject( SlimStat._plugins[plugin_name].active_x_strings[i] );
				return true;
			}
			catch(e) {
				return false;
			}
		}
	},
	
	_detect_single_plugin : function (plugin_name) {
		if( navigator.plugins.length ) {
			this.detect = this._detect_single_plugin_not_ie;
		}
		else {
			this.detect = this._detect_single_plugin_ie;
		}
		return this.detect( plugin_name );
	},

	detect_plugins : function () {
		var a_plugin, plugins = "";

		for (a_plugin in SlimStat._plugins) {
			if (SlimStat._detect_single_plugin(a_plugin)) {
				plugins += a_plugin + "|";
			}
		}
		return plugins;
	},
	
	get_page_performance : function () {
		slim_performance = window.performance || window.mozPerformance || window.msPerformance || window.webkitPerformance || {};
		if (typeof slim_performance.timing == 'undefined'){
			return 0;
		}
		
		return slim_performance.timing.loadEventEnd - slim_performance.timing.responseEnd;
	},
	
	get_server_latency : function () {
		slim_performance = window.performance || window.mozPerformance || window.msPerformance || window.webkitPerformance || {};
		if (typeof slim_performance.timing == 'undefined'){
			return 0;
		}
		
		return slim_performance.timing.responseEnd - slim_performance.timing.connectEnd;
	},

	send_to_server : function (data, callback) {
		if (typeof SlimStatParams.ajaxurl == 'undefined' || typeof data == 'undefined'){
			if (typeof callback == 'function'){
				callback();
			}
			return false;
		}

		try {
			if (window.XMLHttpRequest) {
				request = new XMLHttpRequest();
			}
			else if (window.ActiveXObject) { // code for IE6, IE5
				request = new ActiveXObject("Microsoft.XMLHTTP");
			}
		} catch (failed) {
			if (typeof callback == 'function'){
				callback();
			}
			return false;
		}

		if (request) {
			request.open("POST", SlimStatParams.ajaxurl, true);
            request.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
			request.send(data);

			request.onreadystatechange = function() {
                if (request.readyState == 4) {
                    if (typeof SlimStatParams.id == "undefined") {
                        parsed_id = parseInt(request.responseText);
                        if (!isNaN(parsed_id) && parsed_id > 0) {
                            SlimStat._id = request.responseText
                        }
                    } else {
                        SlimStat._id = SlimStatParams.id
                    }
                    if (typeof callback == "function") {
                        callback()
                    }
                }
            };
			return true;
		}
		return false;
	},

	ss_track : function (e, c, note, callback) {
		// Check function params
		if (!e){
			e = window.event;
		}
		type = (typeof c == 'undefined')?0:parseInt(c);
		note_array = [];

		// Do nothing if we don't have a valid SlimStat._id
		parsed_id = parseInt(SlimStat._id);
		if (isNaN(parsed_id) || parsed_id <= 0) {
			if (typeof callback == 'function'){
				callback();
			}
			return false;
		}

		node = (typeof e.target != "undefined")?e.target:(typeof e.srcElement != "undefined")?e.srcElement:false;
		if (!node) {
			if (typeof callback == 'function'){
				callback();
			}
			return false;
		}

		// Old Safari bug
		if (node.nodeType == 3) node = node.parentNode;

		parent_node = node.parentNode;
		outbound_resource = '';

		// This handler can be attached to any element, but only A carries the extra info we need
		switch (node.nodeName) {
			case 'FORM':
				if (node.action.length > 0){
					outbound_resource = escape(node.action);
				}
				break;

			case 'INPUT':
				// Let's look for a FORM element
				while (typeof parent_node != 'undefined' && parent_node.nodeName != 'FORM' && parent_node.nodeName != 'BODY') parent_node = parent_node.parentNode;
				if (typeof parent_node.action != 'undefined' && parent_node.action.length > 0) {
					outbound_resource = escape(parent_node.action);
					break;
				}

			default:
				// Any other element
				if (node.nodeName != 'A') {
					if (typeof node.getAttribute == 'function' && node.getAttribute('id') != 'undefined' && node.getAttribute('id').length){
						outbound_resource = node.getAttribute('id');
						break;
					}
					while (typeof node.parentNode != 'undefined' && node.parentNode != null && node.nodeName != 'A' && node.nodeName != 'BODY') node = node.parentNode;
				}

				// Anchor in the same page
				if (typeof node.hash != 'undefined' && node.hash.length > 0 && node.hostname == location.hostname) {
					outbound_resource = escape(node.hash);
				}
				else if (typeof node.href != 'undefined') {
					outbound_resource = escape(node.href);
				}

				// If this element has a title, we can record that as well
				if (typeof node.getAttribute == 'function'){
					if (typeof node.getAttribute('title') != 'undefined' && node.getAttribute('title') != null && node.getAttribute('title').length > 0){
						note_array.push('Title:'+node.getAttribute('title'));
					}
					if (typeof node.getAttribute('id') != 'undefined' && node.getAttribute('id') != null && node.getAttribute('id').length > 0){
						note_array.push('ID:'+node.getAttribute('id'));
					}
				}
		}

		// Track mouse coordinates
		pos_x = -1; pos_y = -1;
		position = '';
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
		if (pos_x > 0 && pos_y > 0){
			position = 'po='+pos_x+','+pos_y;
		}

		// Event description and button pressed
		event_description = e.type;
		if (e.type != 'click' && typeof(e.which) != 'undefined'){
			if (e.type == 'keypress'){
				event_description += '; keypress:'+String.fromCharCode(parseInt(e.which));
			}
			else{
				event_description += '; which:'+e.which;
			}
		}

		// Custom description for this event
		if (typeof note != 'undefined' && note.length > 0){
			note_array.push(note);
		}

		// Internal downloads are tracked as pageviews
		if (type == 1){
			SlimStat.send_to_server("action=slimtrack_pageview&id="+SlimStat._id+"&ref="+SlimStat._base64_encode(document.referrer)+"&res="+SlimStat._base64_encode(outbound_resource.substring(outbound_resource.indexOf(location.hostname) + location.hostname.length))+"&sw="+screen.width+"&sh="+screen.height+"&bw="+window.innerWidth+"&bh="+window.innerHeight+"&sl="+SlimStat.get_server_latency()+"&pp="+SlimStat.get_page_performance()+"&pl="+SlimStat.detect_plugins());
		}

		SlimStat.send_to_server("action=slimtrack_event&id="+SlimStat._id+"&type="+type+"&outbound="+SlimStat._base64_encode(outbound_resource)+"&position="+position+"&description="+SlimStat._base64_encode(event_description)+"&notes="+SlimStat._base64_encode(escape(note_array.join(', '))), callback);
		
		return true;
	},
	
	// Tracks Google+1 clicks
	slimstat_plusone : function (obj) {
		SlimStat.send_to_server('type=4&outbound='+escape('#google-plus-'+obj.state)); 
	},
	
	add_event : function ( obj, type, fn ) {
		if (obj && obj.addEventListener) {
			obj.addEventListener( type, fn, false );
		}
		else if (obj && obj.attachEvent) {
			obj["e"+type+fn] = fn;
			obj[type+fn] = function() { obj["e"+type+fn]( window.event ); }
			obj.attachEvent( "on"+type, obj[type+fn] );
		}
		else {
			obj["on"+type] = obj["e"+type+fn];
		}
	},
	event_fire : function ( obj, evt ) {
		var fire_on_this = obj;
	    if ( document.createEvent ) {
	      var ev_obj = document.createEvent('MouseEvents');
	      ev_obj.initEvent( evt, true, false );
	      fire_on_this.dispatchEvent( ev_obj );
	    }
	    else if ( document.createEventObject ) {
	      var ev_obj = document.createEventObject();
	      fire_on_this.fireEvent( 'on' + evt, ev_obj );
	    }
	},
	in_array : function (needle, haystack) {
		for(var i = 0; i < haystack.length; i++) {
			if (haystack[i].trim() == needle) return true;
		}
		return false;
	},
	in_array_substring : function (needle, haystack_of_substrings) {
		for(var i = 0; i < haystack_of_substrings.length; i++) {
			if (needle.indexOf(haystack_of_substrings[i].trim()) != -1) return true;
		}
		return false;
	}
}

// Attaches an event listener to all external links
SlimStat.add_event(window, 'load', function() {
	if (typeof SlimStatParams.disable_outbound_tracking == 'undefined'){
		all_links = document.getElementsByTagName("a");
		var extensions_to_track = (typeof SlimStatParams.extensions_to_track != 'undefined' && SlimStatParams.extensions_to_track.length > 0) ? SlimStatParams.extensions_to_track.split(','):[];
		var to_ignore = (typeof SlimStatParams.outbound_classes_rel_href_to_ignore != 'undefined' && SlimStatParams.outbound_classes_rel_href_to_ignore.length > 0) ? SlimStatParams.outbound_classes_rel_href_to_ignore.split(','):[];
		var to_not_track = (typeof SlimStatParams.outbound_classes_rel_href_to_not_track != 'undefined' && SlimStatParams.outbound_classes_rel_href_to_not_track.length > 0) ? SlimStatParams.outbound_classes_rel_href_to_not_track.split(','):[];
		
		for (var i=0; i<all_links.length; i++) {
			// We need a closure to keep track of some variables and carry them thorughout the process
			(function() {
				var cur_link = all_links[i];

				// Types
				// 0: external
				// 1: download
				// 2: internal

				cur_link.slimstat_actual_click = false;
				cur_link.slimstat_type = (cur_link.href && (cur_link.hostname == location.hostname || cur_link.href.indexOf('://') == -1))?2:0;
				cur_link.slimstat_track_me = true;
				cur_link.slimstat_callback = true;

				// Track downloads (internal)
				if (extensions_to_track.length > 0 && cur_link.pathname.indexOf('.') > 0 && cur_link.hostname == location.hostname){
					extension_current_link = cur_link.pathname.split('.').pop().replace(/[\/\-]/g, '');
					cur_link.slimstat_track_me = SlimStat.in_array(extension_current_link, extensions_to_track);
					cur_link.slimstat_type = 1;
				}

				// Track ALL other internal links?
				if (cur_link.slimstat_track_me && cur_link.slimstat_type == 2 && (typeof SlimStatParams.track_internal_links == 'undefined' || SlimStatParams.track_internal_links == 'false')) {
					cur_link.slimstat_track_me = false;
				}

				// Do not invoke the callback or don't track links with given classes
				if (cur_link.slimstat_track_me && (to_ignore.length > 0 || to_not_track.length > 0)){
					classes_current_link = (typeof cur_link.className != 'undefined' && cur_link.className.length > 0) ? cur_link.className.split(' '):[];

					for (var cl = 0; cl < classes_current_link.length; cl++){
						if (SlimStat.in_array_substring(classes_current_link[cl], to_ignore)) {
							cur_link.slimstat_callback = false;
						}
						if (SlimStat.in_array_substring(classes_current_link[cl], to_not_track)) {
							cur_link.slimstat_track_me = false;
							break;
						}
					}
				}

				// Do not invoke the callback on links with given rel
				if (cur_link.slimstat_track_me && typeof cur_link.attributes.rel != 'undefined' && cur_link.attributes.rel.value.length > 0){
					if (SlimStat.in_array_substring(cur_link.attributes.rel.value, to_ignore)){
						cur_link.slimstat_callback = false;
					}
					if (SlimStat.in_array_substring(cur_link.attributes.rel.value, to_not_track)){
						cur_link.slimstat_track_me = false;
					}
				}

				// Do not invoke the callback on links with given href
				if (cur_link.slimstat_track_me && typeof cur_link.href != 'undefined' && cur_link.href.length > 0){
					if (SlimStat.in_array_substring(cur_link.href, to_ignore)){
						cur_link.slimstat_callback = false;
					}
					if (SlimStat.in_array_substring(cur_link.href, to_not_track)){
						cur_link.slimstat_track_me = false;
					}
				}
				
				// Do not invoke the callback on links that open a new window
				if (cur_link.slimstat_track_me && cur_link.target && !cur_link.target.match(/^_(self|parent|top)$/i)){
					cur_link.slimstat_callback = false;
				}

				// Internal link or extension to ignore
				SlimStat.add_event(cur_link, "click", function(e) {
					if (this.slimstat_track_me){
						if (!this.slimstat_actual_click){
							if (this.slimstat_callback){
								if (typeof e.preventDefault == "function") {
									e.preventDefault();
								}
								this.slimstat_actual_click = !this.slimstat_actual_click;
								SlimStat.ss_track(e, this.slimstat_type, "", function() {
									SlimStat.event_fire(cur_link, 'click');
								});
							}
							else{
								SlimStat.ss_track(e, this.slimstat_type, "", function() {});
							}
						}
					}
				});
      })();
		}
	}
});

// Is Javascript Mode active?
var current_data = 'action=slimtrack_pageview';
if (typeof SlimStatParams.id != 'undefined' && parseInt(SlimStatParams.id)>0){
	current_data += "&id="+SlimStatParams.id;
}
else if (typeof SlimStatParams.ci != 'undefined'){
	current_data += "&ci="+SlimStatParams.ci+"&ref="+SlimStat._base64_encode(document.referrer)+"&res="+SlimStat._base64_encode(window.location.href);
}

// Gathers all the information and sends it to the server
if (current_data.length){
	SlimStat.add_event(window, 'load', function(){
		setTimeout(function(){
			SlimStat.send_to_server(current_data+"&sw="+screen.width+"&sh="+screen.height+"&bw="+window.innerWidth+"&bh="+window.innerHeight+"&sl="+SlimStat.get_server_latency()+"&pp="+SlimStat.get_page_performance()+"&pl="+SlimStat.detect_plugins());
		}, 0)
	});
}