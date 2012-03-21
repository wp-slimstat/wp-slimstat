// If XMLHttpRequest is not defined, we need to create it
if (typeof XMLHttpRequest == 'undefined') {
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

// Plugin Detection Functionality
var slimstat_plugins = {
	java: { substrs: [ "Java" ], progIds: [ "JavaWebStart.isInstalled" ] },
	acrobat: { substrs: [ "Adobe", "Acrobat" ], progIds: [ "AcroPDF.PDF", "PDF.PDFCtrl.5" ] },
	flash: { substrs: [ "Shockwave", "Flash" ], progIds: [ "ShockwaveFlash.ShockwaveFlash" ] },
	director: { substrs: [ "Shockwave", "Director" ], progIds: [ "SWCtl.SWCtl" ] },
	real: { substrs: [ "RealPlayer" ], progIds: [ "rmocx.RealPlayer G2 Control", "RealPlayer.RealPlayer(tm) ActiveX Control (32-bit)", "RealVideo.RealVideo(tm) ActiveX Control (32-bit)" ] },
	mediaplayer: { substrs: [ "Windows Media" ], progIds: [ "WMPlayer.OCX" ] },
	silverlight: { substrs: [ "Silverlight" ], progIds: [ "AgControl.AgControl" ] }
};
function slimstat_detect_plugin(substrs) {
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

// VBScript block for Internet Explorer
var detectableWithVB = false;
if ((navigator.userAgent.indexOf('MSIE') != -1) && (navigator.userAgent.indexOf('Win') != -1)) {
    document.writeln('<scr' + 'ipt language="VBscript">');
    document.writeln('\'do a one-time test for a version of VBScript that can handle this code');
    document.writeln('detectableWithVB = False');
    document.writeln('If ScriptEngineMajorVersion >= 2 then');
    document.writeln('  detectableWithVB = True');
    document.writeln('End If');
    document.writeln('\'this next function will detect most plugins');
    document.writeln('Function detectActiveXControl(activeXControlName)');
    document.writeln('  on error resume next');
    document.writeln('  detectActiveXControl = False');
    document.writeln('  If detectableWithVB Then');
    document.writeln('     detectActiveXControl = IsObject(CreateObject(activeXControlName))');
    document.writeln('  End If');
    document.writeln('End Function');
    document.writeln('</scr' + 'ipt>');

	function slimstat_detectActiveXControl(progIds){
		for (var i = 0; i < progIds.length; i++) {
			if (detectActiveXControl(progIds[i])) return true;
		}
		return false;
	}
}

// Thanks to http://www.useragentman.com/blog/2009/11/29/how-to-detect-font-smoothing-using-javascript/
function slimstat_has_smoothing(){
	// IE has screen.fontSmoothingEnabled - sweet!
	if (typeof screen.fontSmoothingEnabled != 'undefined'){
		return screen.fontSmoothingEnabled;
	}
	else{
		try{
			// Create a 35x35 Canvas block.
			var canvasNode = document.createElement('canvas');
			canvasNode.width = "35";
			canvasNode.height = "35";

			// We must put this node into the body, otherwise
			// Safari Windows does not report correctly.
			canvasNode.style.display = 'none';
			document.body.appendChild(canvasNode);
			var ctx = canvasNode.getContext('2d');

			// draw a black letter 'O', 32px Arial.
			ctx.textBaseline = "top";
			ctx.font = "32px Arial";
			ctx.fillStyle = "black";
			ctx.strokeStyle = "black";

			ctx.fillText("O", 0, 0);

			// start at (8,1) and search the canvas from left to right,
			// top to bottom to see if we can find a non-black pixel.  If
			// so we return true.
			for (var j = 8; j <= 32; j++){
				for (var i = 1; i <= 32; i++){
					var imageData = ctx.getImageData(i, j, 1, 1).data;
					var alpha = imageData[3];

					if (alpha != 255 && alpha != 0) return true; // font-smoothing must be on.
				}
			}

			// didn't find any non-black pixels - return false.
			return false;
		}
		catch (ex){
			// Something went wrong (for example, Opera cannot use the
			// canvas fillText() method.  Return null (unknown).
			return null;
		}
	}
}

// Sends asynchronous requests to the server 
function slimstat_send_to_server(url, async){
	if (typeof slimstat_path == 'undefined' ||
		typeof slimstat_tid == 'undefined' ||
		typeof slimstat_session_id == 'undefined' ||
		typeof slimstat_blog_id == 'undefined' ||
		typeof url == 'undefined') return 0;

	if (typeof async == 'undefined') var async = true;
	try {
		request = new XMLHttpRequest();
	} catch (failed) {
		request = false;
	}
	if (request) {
		request.open('GET', slimstat_path+'/wp-slimstat-js.php'+"?id="+slimstat_tid+"&sid="+slimstat_session_id+"&bid="+slimstat_blog_id+"&"+url, async);
		request.send(null);
	}
	
	return true;
}

function ss_te(e, c, load_target, note){
	// Check function params
	if (typeof e == 'undefined') var e = window.event;
	code = parseInt(c);
	if (typeof load_target == 'undefined') var load_target = true;
	if (typeof note == 'undefined') var note = '';

	var node = (typeof e.target != 'undefined')?e.target:((typeof e.srcElement != 'undefined')?e.srcElement:false);

	// Maybe the user passed the node instead of the event?
	if (!node && typeof e.nodeType != 'undefined'){
		node = e;
		e = window.event;
	}

	// Old Safari bug
	if (node && node.nodeType == 3) node = node.parentNode; 

	// This function can be attached to click and mousedown events
	var target_location = '';
	var node_hostname = '';
	var node_pathname = '';
	if (node && typeof node.getAttribute == 'function'){
		if (typeof node.hash != 'undefined' && node.hash.length > 0 && node.hostname == location.hostname){
			node_pathname = escape(node.hash);
		}
		else{
			target_location = (typeof node.href != 'undefined')?node.href:'';
			node_hostname = (typeof node.hostname != 'undefined')?node.hostname:'';
			node_pathname = escape(target_location);
		}
		// If this element has an ID, we can use that for the note
		if ((note.substring(0, 2) == 'A:' || note.length == 0) &&
			node.getAttribute('id') != 'undefined' &&
			node.getAttribute('id') != null &&
			node.getAttribute('id').length > 0) note = 'ID:'+node.getAttribute('id');
	}
	var slimstat_info = "&obd="+node_hostname+"&obr="+node_pathname;

	// Track mouse coordinates
	var pos_x = -1; var pos_y = -1;
	if (typeof e.pageX != 'undefined' && typeof e.pageY != 'undefined'){
		pos_x = e.pageX;
		pos_y = e.pageY;
	}
	else if (typeof e.clientX != 'undefined' && typeof e.clientY != 'undefined' &&
			typeof document.body.scrollLeft != 'undefined' && typeof document.documentElement.scrollLeft != 'undefined' &&
			typeof document.body.scrollTop != 'undefined' && typeof document.documentElement.scrollTop != 'undefined'){
		pos_x = e.clientX+document.body.scrollLeft+document.documentElement.scrollLeft;
		pos_y = e.clientY+document.body.scrollTop+document.documentElement.scrollTop;
	}
	if (pos_x > 0 && pos_y > 0) slimstat_info += '&po='+pos_x+','+pos_y;

	// Event type and button pressed
	note += '|ET:'+e.type;

	if (e.type != 'click' && typeof(e.which) != 'undefined'){
		if (e.type == 'keypress' )
			note += '|BT:'+String.fromCharCode(parseInt(e.which));
		else
			note += '|BT:'+e.which;
	}

	slimstat_send_to_server("ty="+code+slimstat_info+"&no="+escape(note), !target_location);

	// Does the target need to be loaded? Note: if jQuery has handlers associated to this node, don't bother loading the URL
	if (!load_target || !target_location || e.type != 'click' || (typeof jQuery != 'undefined' && typeof(jQuery(node).data('events')) != 'undefined')) return true;

	switch(node.getAttribute('target')){
		case '_blank':
		case '_new':
			window.open(target_location, node.getAttribute('target'));
			break;
		case null:
		case 'undefined':
		case '':
		case '_self':
			// This is necessary to give the browser some time to elaborate the request
			self.location.href = target_location;
			break;
		case '_parent':
			parent.location.href = target_location;
			break;
		default:
			if (top.frames[e.target])
				top.frames[node].location.href = target_location;
			else
				window.open(target_location, node.getAttribute('target'));
	}

	if (typeof e.preventDefault == 'function')
		e.preventDefault();
	else
		e.returnValue = false;
	return true;
}

// Attach an event listener to all external links
var links_in_this_page = document.getElementsByTagName("a");
for (var i=0;i<links_in_this_page.length;i++){
	if ((typeof slimstat_heatmap == 'undefined') &&
		((links_in_this_page[i].hostname == location.hostname) ||
		 (links_in_this_page[i].href.indexOf('://') == -1) ||
		 (links_in_this_page[i].className.indexOf('noslimstat') != -1))) continue;

	if (links_in_this_page[i].addEventListener)
		links_in_this_page[i].addEventListener("click", function(i){ return function(e){ ss_te(e,0,true,"A:"+(i+1)) } }(i), false);
	else if (links_in_this_page[i].attachEvent)
		links_in_this_page[i].attachEvent("onclick", function(i){ return function(e){ ss_te(e,0,true,"A:"+(i+1)) } }(i));
}

// Track Google+1 clicks
function slimstat_plusone(obj){
	if (obj.state == 'off')
		ss_te('', 4, false);
	else
		ss_te('', 3, false);
}

// Gather all the information and send it to the server
slimstat_info = "sw="+(window.innerWidth||document.documentElement.clientWidth||document.body.offsetWidth)+"&sh="+(window.innerHeight||document.documentElement.clientHeight||document.body.offsetHeight)+"&cd="+screen.colorDepth+"&aa="+(slimstat_has_smoothing()?'1':'0')+"&pl=";
for (var slimstat_alias in slimstat_plugins){
	if (slimstat_detect_plugin(slimstat_plugins[slimstat_alias].substrs) || (detectableWithVB && slimstat_detectActiveXControl(slimstat_plugins[slimstat_alias].progIds))){
		slimstat_info += slimstat_alias +"|";
	}
}
slimstat_send_to_server(slimstat_info);