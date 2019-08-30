var SlimStat = {
	_id : ( "undefined" != typeof SlimStatParams.id && !isNaN( parseInt( SlimStatParams.id ) ) ) ? SlimStatParams.id : "-1.0",
	_base64_key_str : "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=",

	// Encodes a string using the UTF-8 encoding. This function is needed by the Base64 Encoder here below
	_utf8_encode : function( string ) {
		var n, c, utftext = "";

		string = string.replace(/\r\n/g, "\n");

		for ( n = 0; n < string.length; n++ ) {
			c = string.charCodeAt( n );

			if ( c < 128 ) {
				utftext += String.fromCharCode( c );
			}
			else if ( ( c > 127 ) && ( c < 2048 ) ) {
				utftext += String.fromCharCode( ( c >> 6 ) | 192 );
				utftext += String.fromCharCode( ( c & 63 ) | 128 );
			}
			else {
				utftext += String.fromCharCode( ( c >> 12 ) | 224 );
				utftext += String.fromCharCode( ( ( c >> 6 ) & 63 ) | 128 );
				utftext += String.fromCharCode( ( c & 63 ) | 128 );
			}
		}
		return utftext;
	},

	// Base64 Encode - http://www.webtoolkit.info/
	_base64_encode : function( input ) {
		var chr1, chr2, chr3, enc1, enc2, enc3, enc4, output = "", i = 0;

		input = SlimStat._utf8_encode( input );

		while ( i < input.length ) {
			chr1 = input.charCodeAt( i++ );
			chr2 = input.charCodeAt( i++ );
			chr3 = input.charCodeAt( i++ );

			enc1 = chr1 >> 2;
			enc2 = ( ( chr1 & 3 ) << 4 ) | ( chr2 >> 4 );
			enc3 = ( ( chr2 & 15 ) << 2 ) | ( chr3 >> 6 );
			enc4 = chr3 & 63;

			if ( isNaN( chr2 ) ) {
				enc3 = enc4 = 64;
			}
			else if ( isNaN( chr3 ) ) {
				enc4 = 64;
			}

			output = output + SlimStat._base64_key_str.charAt( enc1 ) + SlimStat._base64_key_str.charAt( enc2 ) + SlimStat._base64_key_str.charAt( enc3 ) + SlimStat._base64_key_str.charAt( enc4 );
		}
		return output;
	},

	// Calculates the current page's performance
	get_page_performance : function() {
		slim_performance = window.performance || window.mozPerformance || window.msPerformance || window.webkitPerformance || {};
		if ( "undefined" == typeof slim_performance.timing ){
			return 0;
		}

		return slim_performance.timing.loadEventEnd - slim_performance.timing.responseEnd;
	},

	// Calculates the current page's latency
	get_server_latency : function() {
		slim_performance = window.performance || window.mozPerformance || window.msPerformance || window.webkitPerformance || {};
		if ( "undefined" == typeof slim_performance.timing ){
			return 0;
		}

		return slim_performance.timing.responseEnd - slim_performance.timing.connectEnd;
	},

	// Records the choice made by the visitor and hides the dialog message
	optout : function( event, cookie_value ) {
		event.preventDefault();

		expiration = new Date();
		expiration.setTime( expiration.getTime() + 31536000000 );
		document.cookie = "slimstat_optout_tracking=" + cookie_value + ";path=" + SlimStatParams.baseurl + ";expires=" + expiration.toGMTString();

		event.target.parentNode.parentNode.removeChild( event.target.parentNode );
	},

	// Retrieves and displays the opt-out message dynamically, to avoid issues with cached pages
	show_optout_message : function() {
		var opt_out_cookies = ( "undefined" != typeof SlimStatParams.opt_out_cookies && SlimStatParams.opt_out_cookies ) ? SlimStatParams.opt_out_cookies.split( ',' ) : [];
		var show_optout = ( opt_out_cookies.length > 0 );

		for ( var i = 0; i < opt_out_cookies.length; i++ ) {
			if ( SlimStat.get_cookie( opt_out_cookies[ i ] ) != "" ) {
				show_optout = false;
			}
		}

		if ( show_optout ) {
			// Retrieve the message from the server
			try {
				xhr = new XMLHttpRequest();
			} catch ( failed ) {
				return false;
			}

			if ( "object" == typeof xhr ) {
				xhr.open( "POST", SlimStatParams.ajaxurl, true );
				xhr.setRequestHeader( "Content-type", "application/x-www-form-urlencoded" );
				xhr.setRequestHeader( "X-Requested-With", "XMLHttpRequest" );
				xhr.withCredentials = true;
				xhr.send( "action=slimstat_optout_html" );

				xhr.onreadystatechange = function() {
					if ( 4 == xhr.readyState ) {
						document.body.insertAdjacentHTML( 'beforeend', xhr.responseText );
					}
				}

				return true;
			}
		}
	},

	// Attaches an event handler to a node
	add_event : function( obj, type, fn ) {
		if ( obj && obj.addEventListener ) {
			obj.addEventListener( type, fn, false );
		}
		else if ( obj && obj.attachEvent ) {
			obj[ "e" + type + fn ] = fn;
			obj[ type + fn ] = function() { obj[ "e" + type + fn ] ( window.event ); }
			obj.attachEvent( "on"+type, obj[type+fn] );
		}
		else {
			obj[ "on" + type ] = obj[ "e" + type + fn ];
		}
	},

	// Implements a function to find a substring in an array
	in_array : function( needle, haystack ) {
		for ( var i = 0; i < haystack.length; i++ ) {
			if ( needle.indexOf( haystack[ i ].trim() ) != -1 ) {
				return true;
			}
		}
		return false;
	},

	// Retrieves the value associated to a given cookie
	get_cookie : function( name ) {
		var value = "; " + document.cookie;
		var parts = value.split( "; " + name + "=" );
		if ( parts.length == 2 ) {
			return parts.pop().split( ";" ).shift();
		}
		return "";
	},

	// Sends data back to the server (wrapper for XMLHttpRequest object)
	send_to_server : function( data, use_beacon ) {
		if ( "undefined" == typeof SlimStatParams.ajaxurl || "undefined" == typeof data ) {
			return false;
		}

		if ( "undefined" == typeof use_beacon ) {
			use_beacon = true;
		}

		slimstat_data_with_client_info = data + "&sw=" + screen.width + "&sh=" + screen.height + "&bw=" + window.innerWidth + "&bh=" + window.innerHeight + "&sl=" + SlimStat.get_server_latency() + "&pp=" + SlimStat.get_page_performance();

		if ( use_beacon && navigator.sendBeacon ) {
			navigator.sendBeacon( SlimStatParams.ajaxurl, slimstat_data_with_client_info );
		}
		else {
			try {
				xhr = new XMLHttpRequest();
			} catch ( failed ) {
				return false;
			}

			if ( "object" == typeof xhr ) {
				xhr.open( "POST", SlimStatParams.ajaxurl, true );
				xhr.setRequestHeader( "Content-type", "application/x-www-form-urlencoded" );
				xhr.setRequestHeader( "X-Requested-With", "XMLHttpRequest" );
				xhr.withCredentials = true;
				xhr.send( slimstat_data_with_client_info );

				xhr.onreadystatechange = function() {
					if ( 4 == xhr.readyState ) {
						parsed_id = parseInt( xhr.responseText );
						if ( !isNaN( parsed_id ) && parsed_id > 0 ) {
							SlimStat._id = xhr.responseText;
						}
					}
				}

				return true;
			}
		}
		
		return false;
	},

	// Tracks events (clicks to download files, mouse coordinates on anchors, etc)
	ss_track : function( note, use_beacon ) {
		// Read and initialize input parameters
		note_array = [];
		if ( "undefined" != typeof note && note.length > 0 ){
			note_array.push( note );
		}
		if ( "undefined" == typeof use_beacon ) {
			use_beacon = true;
		}

		// No event was triggered (weird)
		if ( "undefined" == typeof window.event ) {
			return false;
		}

		if ( "undefined" != typeof window.event.target ) {
			target_node = window.event.target;
		}
		else if ( "undefined" != typeof window.event.srcElement ) {
			target_node = window.event.srcElement;
		}
		else {
			return false;
		}

		action = "event";
		resource_url = "";

		// Do not track events on elements with given class names or rel attributes
		to_not_track = ( "undefined" != typeof SlimStatParams.outbound_classes_rel_href_to_not_track && SlimStatParams.outbound_classes_rel_href_to_not_track ) ? SlimStatParams.outbound_classes_rel_href_to_not_track.split( ',' ) : [];

		if ( to_not_track.length > 0 ) {
			target_classes = ( "undefined" != typeof target_node.className ) ? target_node.className.split( " " ) : [];
			if ( target_classes.filter( value => -1 !== to_not_track.indexOf( value ) ).length != 0 || ( "undefined" != typeof target_node.attributes && "undefined" != typeof target_node.attributes.rel && "undefined" != typeof target_node.attributes.rel.value && SlimStat.in_array( target_node.attributes.rel.value, to_not_track ) ) ) {
				return false;
			}
		}

		// Different elements have different properties to record...
		if ( "undefined" != typeof target_node.nodeName ) {
			switch ( target_node.nodeName ) {
				case "FORM":
					if ( "undefined" != typeof target_node.action ) {
						resource_url = target_node.action;
					}
					break;

				case "INPUT":
					// Let's look for a FORM element
					parent_node = target_node.parentNode;
					while ( "undefined" != typeof parent_node && parent_node.nodeName != "FORM" && parent_node.nodeName != "BODY" ) {
						parent_node = parent_node.parentNode;
					}
					if ( "undefined" != typeof parent_node.action ) {
						resource_url = parent_node.action;
					}
					break;

				default:
					// Is this a link?
					parent_node = target_node;
					while ( "undefined" != typeof parent_node && parent_node.nodeName != "A" && parent_node.nodeName != "BODY" ) {
						parent_node = parent_node.parentNode;
					}

					if ( parent_node.nodeName == "A" ) {
						target_node = parent_node;

						// Anchor in the same page
						if ( "undefined" != typeof target_node.hash && target_node.hostname == location.hostname ) {
							resource_url = target_node.hash;
						}
						// Regular link to another page
						else if ( "undefined" != typeof target_node.href && target_node.href.indexOf( 'javascript:' ) == -1 ) {
							// Do not track links containing one of the strings defined in the settings as HREF
							if ( "undefined" != target_node.href && SlimStat.in_array( target_node.href, to_not_track ) ) {
								return false;
							}

							resource_url = target_node.href;
						}

						// If the current link target's extension is among the one defined in the settings, we should label this event as a download
						extensions_to_track = ( "undefined" != typeof SlimStatParams.extensions_to_track && SlimStatParams.extensions_to_track ) ? SlimStatParams.extensions_to_track.split( ',' ) : [];
						extension_current_link = target_node.pathname.split( /[?#]/ )[ 0 ].split( '.' ).pop().replace( /[\/\-]/g, '' );

						if ( SlimStat.in_array( extension_current_link, extensions_to_track ) ) {
							action = "add";
							resource_url = resource_url.substring( resource_url.indexOf( location.hostname ) + location.hostname.length );
						}
					}

					// If this element has a title, we can record that as well
					if ( "function" == typeof target_node.getAttribute ) {
						if ( "undefined" != typeof target_node.getAttribute( "title" ) && target_node.getAttribute( "title" ) ) {
							note_array.push( "Title:" + target_node.getAttribute( "title" ) );
						}
						if ( "undefined" != typeof target_node.getAttribute( "id" ) && target_node.getAttribute( "id" ) ) {
							note_array.push( "ID:" + target_node.getAttribute( "id" ) );
						}
					}
			}
		}

		// Event coordinates
		position = "";

		if ( "undefined" != typeof window.event.pageX && "undefined" != typeof window.event.pageY ) {
			position = window.event.pageX + "," + window.event.pageY;
		}
		else if ( "undefined" != typeof window.event.clientX && "undefined" != typeof window.event.clientY &&
				"undefined" != typeof document.body.scrollLeft && "undefined" != typeof document.documentElement.scrollLeft &&
				"undefined" != typeof document.body.scrollTop && "undefined" != typeof document.documentElement.scrollTop ) {
			position = window.event.clientX + document.body.scrollLeft + document.documentElement.scrollLeft + "," + window.event.clientY + document.body.scrollTop + document.documentElement.scrollTop;
		}

		// Event description and button pressed
		if ( "undefined" !=  typeof window.event.type ) {
			event_description = window.event.type;
			if ( "keypress" == window.event.type ) {
				event_description += '; keypress:' + String.fromCharCode( parseInt( window.event.which ) );
			}
			else if ( "click" == window.event.type ) {
				event_description += '; which:' + window.event.which;
			}
		}

// TODO: CONSOLIDATE NOTE AND DESCRIPTION
		// note_string = SlimStat._base64_encode( note_array.join( ", " ) );


		// SlimStat.send_to_server( "action=slimtrack&op=" + requested_op + "&id=" + SlimStat._id + "&ty=" + type + "&ref=" + SlimStat._base64_encode( document.referrer ) + "&res=" + SlimStat._base64_encode( resource_url ) + "&pos=" + position + "&des=" + SlimStat._base64_encode( event_description ) + "&no=" + note_string, use_beacon );

		return true;
	}
}

// Helper function
if ( typeof String.prototype.trim !== 'function' ) {
	String.prototype.trim = function() {
		return this.replace( /^\s+|\s+$/g, '' ); 
	}
}

// Ok, let's go, Sparky!
SlimStat.add_event( window, 'load', function() {
	slimstat_data = "";
	use_beacon = true;

	if ( "undefined" != typeof SlimStatParams.id && parseInt( SlimStatParams.id ) > 0 ) {
		slimstat_data = "action=slimtrack&op=update&id=" + SlimStatParams.id;
	}
	else {
// COMPLETE THE HANDLING OF EXTERNAL PAGES BY IMPLEMENTING THE CORRESPONDING PHP CODE
	
// CLEANUP USE OF PARAMS: ID is always ID, &ci= should be used for CI
		slimstat_data = "action=slimtrack&op=add&ref=" + SlimStat._base64_encode( document.referrer ) + "&res=" + SlimStat._base64_encode( window.location.href );
	
		if ( "undefined" != typeof SlimStatParams.ci ) {
			slimstat_data += "&ci=" + SlimStatParams.ci;
		}

		// If the tracker is working in "client mode", it needs to wait for the server to assign it a page view ID
		use_beacon = false;
	}
// TEST IF use_beacon is being passed as expected
	if ( slimstat_data.length > 0 ) {
		setTimeout( function(){
			SlimStat.send_to_server( slimstat_data, use_beacon );
		}, 50 );
	}

	// Attach an event handler to all the links on the page that satisfy the criteria set by the admin
	all_links = document.getElementsByTagName( "a" );
	for ( var i = 0; i < all_links.length; i++ ) {
		SlimStat.add_event( all_links[ i ], "click", function( e ) {
			SlimStat.ss_track();
		} );
	}

	// GDPR: display the Opt-Out box, if needed
	SlimStat.show_optout_message();
} );