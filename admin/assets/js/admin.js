if ( typeof SlimStatAdminParams == 'undefined' ) {
	SlimStatAdminParams = {
		async_load : 'no',
		refresh_interval: 0,
		datepicker_image: ''
	};
}

// ----- TABLE OF CONTENTS -----------------------------------------------------------
// 
// 1. Data Refresh
// 2. Filters
// 3. Access Log
// 4. Customizer
// 5. Miscellaneous
// 6. Init Third-party Libraries
//
// -----------------------------------------------------------------------------------

jQuery( function() {

	// ----- BEGIN: DATA REFRESH -----------------------------------------------------
	//

	// Reload a report's data if it is (re)activated via the checkbox under Screen Options
	jQuery( 'input.hide-postbox-tog[id^=slim_p]' ).on( 'click.postboxes', function () {
		if ( jQuery( this ).prop( "checked" ) && jQuery( '#' + jQuery( this ).val() ).length ) {
			refresh = SlimStatAdmin.refresh_report( jQuery( this ).val() );
			refresh();
		}
	});

	// Reload a report's data if the corresponding refresh/pagination button is clicked
	jQuery( document ).on( 'click', '[id^=slim_] .refresh', function( e ) {
		e.preventDefault();

		var id = jQuery( this ).parents( '.postbox' ).attr( 'id' );

		// Is this a pagination link?
		if ( typeof jQuery( this ).attr( 'href' ).split( '?' )[ 1 ] == 'string' ) {
			clean_filters = SlimStatAdmin.get_query_string_filters( jQuery( this ).attr( 'href' ).split( '?' )[ 1 ].substring( 1 ) );
			if ( typeof clean_filters[ 'fs[start_from]' ] == 'string' ) {
				jQuery( '<input type="hidden" name="fs[start_from]" class="slimstat-post-filter slimstat-temp-filter" value="' + clean_filters[ 'fs[start_from]' ] + '">' ).appendTo( '#slimstat-filters-form' );
			}
		}

		refresh = SlimStatAdmin.refresh_report( id );
		refresh();
		
		// Remove any temporary filters set here above
		jQuery( '.slimstat-temp-filter' ).remove();

		// Re-initialize SlimScroll on the new content
		jQuery( '#' + id + ' .inside' ).slimScroll( {
			scrollTo : '0px'
		} );
	} );

	// Asynchronous reports are loaded dynamically after the page loads
	if ( SlimStatAdminParams.async_load == 'on' ) { 
		var base = jQuery.when({});
		jQuery( 'div[id^=slim_p]' ).each( function() {
			// Skip Charts
			if ( jQuery( this ).find( '.chart-placeholder, .refresh-timer' ).length == 0 ) {
				jQuery( '#' + jQuery( this ).attr( 'id' ) + ' .inside' ).html( '<p class="loading"><i class="slimstat-font-spin4 animate-spin"></i></p>' );
				base = base.then( SlimStatAdmin.refresh_report( jQuery( this ).attr( 'id' ) ) );
			}
		} );
	}

	//
	// ----- END: DATA REFRESH -------------------------------------------------------

	// ----- BEGIN: FILTERS ----------------------------------------------------------
	//

	// Make input field read-only if certain operators are selected
	jQuery( '#slimstat-filter-operator' ).on( 'change', function() {
		if ( this.value == 'is_empty' || this.value == 'is_not_empty' ) {
			jQuery( '#slimstat-filter-value' ).attr( 'readonly', 'readonly' );
		}
		else {
			jQuery( '#slimstat-filter-value' ).removeAttr( 'readonly' );
		}
	} );

	// Toggle the Date Filters dropdown menu
	jQuery( '#slimstat-date-filters > a' ).on( 'click', function( e ) {
		e.preventDefault();
		jQuery( '#slimstat-date-filters > .dropdown' ).slideToggle( 250 );
	} );

	// Initialize the datepicker library and button (built-in)
	if ( typeof jQuery( '.slimstat-filter-date' ).datepicker == 'function' ) {
		jQuery( '.slimstat-filter-date' ).datepicker( {
			buttonImage: SlimStatAdminParams.datepicker_image,
			buttonImageOnly: true,
			changeMonth: true,
			changeYear: true,
			dateFormat: 'yy-m-d',
			maxDate: new Date,
			nextText: '&raquo;',
			prevText: '&laquo;',
			showOn: 'both',

			onClose: function( dateText, inst ) {
				if ( dateText.length ) {
					jQuery( '#slimstat-filter-hour' ).val( 0 );
					jQuery( '#slimstat-filter-day' ).val( dateText.split( '-' )[ 2 ] );
					jQuery( '#slimstat-filter-month' ).val( dateText.split( '-' )[ 1 ] );
					jQuery( '#slimstat-filter-year' ).val( dateText.split( '-' )[ 0 ] );
					jQuery( '#slimstat-filter-interval' ).val( -1 );
				}
			}
		} );
	}

	// Save silters
	jQuery( document ).on( 'click', '#slimstat-save-filter', function( e ) {
		e.preventDefault();
		
		data = {
			action: 'slimstat_manage_filters',
			security: jQuery( '#meta-box-order-nonce' ).val(),
			type: 'save',
			filter_array: jQuery( this ).attr( 'data-filter-array' )
		};

		var element = jQuery( this );
		jQuery.post( ajaxurl, data, function( response ) {
			element.text( response ).fadeOut( 1500 );
		} );
	} );

	// Load saved filters: open a dialog with a list of filters
	jQuery( document ).on( 'click', '#slimstat-load-saved-filters', function( e ) {
		e.preventDefault();
		var inner_html = '';
		
		data = {
			action: 'slimstat_manage_filters',
			security: jQuery( '#meta-box-order-nonce' ).val(),
			type: 'load',
			page: SlimStatAdmin.get_current_tab()
		};

		var dialog_title = jQuery( this ).attr( 'title' ); // passed as an attribute so that it can be localized
		jQuery.post( ajaxurl, data, function( response ) {
			jQuery( '#slimstat-modal-dialog' ).dialog( {
				dialogClass: 'slimstat',
				title: dialog_title
			} ).html( response ).dialog( 'open' );
		} );
	} );

	// Delete saved filters
	jQuery( document ).on( 'click', '.slimstat-delete-filter', function( e ) {
		e.preventDefault();

		data = {
			action: 'slimstat_manage_filters',
			security: jQuery( '#meta-box-order-nonce' ).val(),
			page: SlimStatAdmin.get_current_tab(),
			type: 'delete',
			filter_id: jQuery( this ).attr( 'data-filter-id' )
		};

		jQuery.post( ajaxurl, data, function( response ) {
			jQuery( '#slim_filters_overlay' ).parent().html( response );
		} );
	} );

	// Since we handle all "filter links" as POST requests (see code here above), we need to add dummy form tag to the dashboard,
	// for when our reports are displayed on that page
	if ( !jQuery( '#slimstat-filters-form' ).length ) {
		jQuery( '<form id="slimstat-filters-form" method="post"/>' ).appendTo('body');
	}

	jQuery( document ).on( 'click', '.slimstat-filter-link, #toplevel_page_slimview1 a, #wp-admin-bar-slimstat-header li a', function( e ) {
		url = jQuery( this ).attr( 'href' );

		// If this link doesn't have a valid HREF attribute, bail
		if ( typeof url != 'string' ) {
			return true;
		}

		e.preventDefault();

		jQuery( 'form#slimstat-filters-form' ).attr( 'action', url.split( '?' )[ 0 ] + '?page=' + SlimStatAdmin.get_current_tab( url.split( '?' )[ 1 ] ) );

		SlimStatAdmin.add_url_filters_to_form( url, typeof jQuery( this ).attr( 'data-reset-filters' ) != 'undefined', jQuery( this ).hasClass( 'slimstat-filter-temp' ) );

		jQuery( '#slimstat-filters-form' ).submit();

		// Remove any temporary filters set here above
		jQuery( '.slimstat-temp-filter' ).remove();

		return false;
	});

	//
	// ----- END: FILTERS ------------------------------------------------------------

	// ----- BEGIN: ACCESS LOG -----------------------------------------------------
	//

	// Delete a pageview when the corresponding button is clicked.
	// Since this content can be reloaded dynamically, we use the .on call with the classname
	jQuery( document ).on( 'click', '.slimstat-delete-entry', function( e ) {
		e.preventDefault();

		data = {
			action: 'slimstat_delete_pageview',
			security: jQuery( '#meta-box-order-nonce' ).val(),
			pageview_id : jQuery( this ).attr( 'data-pageview-id' )
		};

		var parent = jQuery( this ).parents( 'p' );
		jQuery.post( ajaxurl, data, function( response ) {
			parent.fadeOut( 500 );
		} );
	} );

	// // Modal Window / Whois
	jQuery( document ).on( 'click', '.whois', function( e ) {
		e.preventDefault();

		// If admin is using HTTPS and IP lookup service is not, open a new window/tab, instead of an overlay dialog
		if ( document.location.href.substr( 0, document.location.href.indexOf( "://" ) ).toLowerCase() == 'https' &&  jQuery( this ).attr( 'href' ).substr( 0, jQuery( this ).attr( 'href' ).indexOf( "://" ) ).toLowerCase() == 'http' ) {
			window.open( jQuery( this ).attr( 'href' ), '_blank' );
			return -1;
		}

		jQuery('#slimstat-modal-dialog').dialog({
			dialogClass: 'slimstat',
			title: jQuery(this).attr('title')
		}).html('<iframe id="ip2location" src="'+jQuery(this).attr('href')+'" width="100%" height="630"></iframe>');
		jQuery('#slimstat-modal-dialog').dialog('open');
	});

	//
	// ----- END: ACCESS LOG -------------------------------------------------------

	// ----- BEGIN: CUSTOMIZER -------------------------------------------------------
	//

	// Clone and delete report placeholders
	jQuery( '.slimstat-layout .slimstat-header-buttons a' ).on( 'click', function( e ) {
		e.preventDefault();
		if ( jQuery( this ).hasClass( 'slimstat-font-docs' ) ) {
			jQuery( this ).removeClass( 'slimstat-font-docs' ).addClass( 'slimstat-font-trash' ).parents( '.postbox' ).clone( true ).appendTo( jQuery( this ).parents( '.meta-box-sortables' ) );
			jQuery( this ).removeClass( 'slimstat-font-trash' ).addClass( 'slimstat-font-docs' );
		}
		else if ( jQuery( this ).hasClass( 'slimstat-font-minus-circled' ) ) {
			jQuery( this ).removeClass( 'slimstat-font-minus-circled' ).parents( '.postbox' ).appendTo( jQuery( '#postbox-container-inactive .meta-box-sortables' ) );
		}
		else {
			jQuery( this ).parents( '.postbox' ).remove();
		}

		// Save the new groups
		var data = { 
			action: 'meta-box-order',
			_ajax_nonce: jQuery( '#meta-box-order-nonce' ).val(),
			page: SlimStatAdminParams.page_location + '_page_slimlayout',
			page_columns: 0
		};
		
		jQuery( '.meta-box-sortables' ).each( function() {
			data[ 'order[' + this.id.split("-")[0] + ']' ] = jQuery( this ).sortable( 'toArray' ).join( ',' );
		});

		jQuery.post( ajaxurl, data );
	} );

	//
	// ----- END: CUSTOMIZER ---------------------------------------------------------
	
	// ----- BEGIN: MISCELLANEOUS ----------------------------------------------------
	//

	// Hide a notice and send the corresponding ajax request to the server
	jQuery( document ).on( 'click', '[id^=slimstat-notice-] button', function( e ) {
		data = {
			action: jQuery( this ).parent().attr( 'id' ).replace(/-/g, '_'),
			security: jQuery( '#meta-box-order-nonce' ).val()
		};

		jQuery.ajax({
			url: ajaxurl,
			type: 'post',
			async: true,
			data: data
		});
	});

	//
	// ----- END: MISCELLANEOUS ------------------------------------------------------

	// ----- BEGIN: INIT THIRD-PARTY LIBRARIES ---------------------------------------
	//

	// SlimScroll 
	jQuery( '[id^=slim_] .inside' ).slimScroll( {
		distance: '2px',
		opacity: '0.15',
		size: '5px',
		wheelStep: 10
	} );

	// QTip
	jQuery( document ).on( 'mouseover', '.slimstat-tooltip-trigger', function( e ) {
		if ( typeof jQuery( this ).attr( 'data-hasqtip' ) != 'undefined' ) {
			return true;
		}

		tooltip_content = jQuery( this ).find( '.slimstat-tooltip-content:not(.expanded)' );

		if ( typeof tooltip_content == 'undefined' || tooltip_content == null || tooltip_content.length  == 0 ) {
			tooltip_content = jQuery( this ).attr( 'title' );
		}
		else {
			tooltip_content = tooltip_content[ 0 ].innerHTML;
		}

		if ( typeof tooltip_content != 'undefined' && tooltip_content.length ) {
			jQuery(this).qtip( {
				overwrite: false,
				content: {
					text: tooltip_content
				},
				show: {
					event: e.type,
					ready: true
				},
				hide: {
					delay: 250,
					fixed: true
				},
				position: {
					my: 'bottom right',
					at: 'top left',
					adjust: {
						x: 5
					},
					viewport: jQuery( window )
				},
				style: {
					classes: 'qtip-dark qtip-rounded'
				}
			}, e );
		}
	} );

	// Modal Window
	if ( typeof jQuery( '#slimstat-modal-dialog' ).dialog == 'function' ) {
		jQuery( '#slimstat-modal-dialog' ).dialog( {
			autoOpen: false,
			closeOnEscape: true,
			closeText: '',
			draggable: true,
			modal: true,
			open: function(){
				jQuery( '.ui-widget-overlay, .close-dialog' ).on( 'click', function() {
					jQuery( '#slimstat-modal-dialog' ).dialog( 'close' );
				} );
			},
			position: { my: 'top center' },
			resizable: false
		} );
	}

	// BootstrapSwitch (pretty checkboxes in settings)
	jQuery( 'input.slimstat-checkbox-toggle' ).not( '[data-switch-no-init]' ).bootstrapSwitch();
	
	// TagEditor
	jQuery( 'textarea.slimstat-taglist' ).tagEditor( {
		forceLowercase: false,
		maxLength: 1000
	} );

	// Code Editor
	if( typeof wp.codeEditor == 'object' ) {
		jQuery( '[data-code-editor]' ).each( function() {
			var editorSettings = wp.codeEditor.defaultSettings ? _.clone( wp.codeEditor.defaultSettings ) : {};
			editorSettings.codemirror = _.extend(
				{},
				editorSettings.codemirror,
				{
					indentUnit: 2,
					lineNumbers: true,
					mode: jQuery( this ).attr( 'data-code-editor' ),
					tabSize: 2
				}
			);

			var editor = wp.codeEditor.initialize( jQuery( this ), editorSettings );
		});
	}

	//
	// ----- END: INIT THIRD-PARTY LIBRARIES -----------------------------------------
} );

// ----- BEGIN: SLIMSTATADMIN HELPER FUNCTIONS ---------------------------------------
var SlimStatAdmin = {
	refresh_handle: null,

	refresh_report: function( id ) {
		return function() {
			var inner_content = '#' + id + ' .inside';
			var defer = jQuery.Deferred();

			jQuery( '#' + id + ' .inside' ).html( '<p class="loading"><i class="slimstat-font-spin4 animate-spin"></i></p>' );

			// Clear the autorefresh timer, if set
			if ( SlimStatAdmin.refresh_handle != null ) {
				clearTimeout( SlimStatAdmin.refresh_handle );
			}

			data = {
				action: 'slimstat_load_report',
				security: jQuery( '#meta-box-order-nonce' ).val(),
				page: SlimStatAdmin.get_current_tab(),
				report_id: id
			};

			// Append the data from the hidden form
			filters_input = jQuery( '#slimstat-filters-form .slimstat-post-filter' ).toArray();
			for ( i in filters_input ) {
				data[ filters_input[ i ][ 'name' ] ] = filters_input[ i ][ 'value' ];
			}

			jQuery.ajax( { method: 'POST', url: ajaxurl, data: data } )
				.done( function( response ) {
					// Charts don't play nice with the "fade" animation we have for the other reports
					if ( jQuery( '#' + id ).hasClass( 'chart' ) ) {
						jQuery( inner_content ).html( response );
					}
					else{
						jQuery( inner_content ).fadeOut( 500, function() { jQuery( this ).html( response ).fadeIn( 500 ); } );

						// If we are refreshing the Access Log, let's reset the countdown timer
						if ( id == 'slim_p7_02' ) {
							SlimStatAdmin._refresh_timer = SlimStatAdminParams.refresh_interval;
						}
					}
				} )
				.complete( function() {
					defer.resolve();
				} );

			return defer.promise();
		}
	},

	get_query_string_filters: function( url ) {

		// Parse the URL and update the form_filters object accordingly
		query_string_pairs = url.substring( url.indexOf( '?' ) + 1 ).split( '&' );
		clean_filters = {};

		for ( i in query_string_pairs ) {
			decoded_pair = decodeURIComponent( query_string_pairs[ i ].replace( /\+/g, '%20' ) );

			if ( decoded_pair.indexOf( 'fs[' ) == -1 ) {
				continue;
			}

			a_pair = decoded_pair.split( '=' );
			if ( a_pair[ 0 ].length ) {
				clean_filters[ a_pair[ 0 ] ] = a_pair[ 1 ];
			}
		}

		return clean_filters;
	},

	add_url_filters_to_form: function( url, delete_existing_filters, is_temporary ) {
		clean_filters = SlimStatAdmin.get_query_string_filters( url );

		is_temporary_class = '';
		if ( typeof is_temporary != 'undefined' ) {
			is_temporary_class = ' slimstat-temp-filter';
		}

		// Manipulate the existing list of filters (hidden input fields), if we don't want to delete them
		if ( typeof delete_existing_filters == 'undefined' || !delete_existing_filters ) {

			for( i in clean_filters ) {
				// If value is empty (length is 1, meaning that it just has the operator but no value), delete corresponding input field
				if ( clean_filters[ i ].trim().split( ' ' ).length == 1 ) {
					jQuery( 'input[name="' + i + '"]' ).remove();
				}
				else if ( jQuery( 'input[name="' + i + '"]' ).length > 0 ) {
					jQuery( 'input[name="' + i + '"]' ).attr( 'value', clean_filters[ i ] );
				}
				else {
					jQuery( '<input type="hidden" name="' + i + '" class="slimstat-post-filter' + is_temporary_class + '" value="' + clean_filters[ i ] + '">' ).appendTo( '#slimstat-filters-form' );
				}
			}
		}
		// Start from a clean slate
		else {
			jQuery( '.slimstat-post-filter' ).remove();

			for( i in clean_filters ) {
				jQuery( '<input type="hidden" name="' + i + '" class="slimstat-post-filter' + is_temporary_class + '" value="' + clean_filters[ i ] + '">' ).appendTo( '#slimstat-filters-form' );
			}
		}
	},

	// Get the value of a given key in the query string passed via the URL
	get_current_tab: function( query_string ) {
		query_string = typeof query_string == 'undefined' ? window.location.search.substring( 1 ) : query_string;
		var regex = new RegExp( "page(=([^&#]*)|&|#|$)" );
		
		values = regex.exec( query_string );

		if ( typeof values != 'undefined' && values != null && typeof values[ 2 ] == 'string' ) {
			return decodeURIComponent( values[ 2 ].replace( /\+/g, " " ) );
		}

		return '';
	}
}
// ----- END: SLIMSTATADMIN HELPER FUNCTIONS -----------------------------------------

/* SlimScroll v1.3.8 | https://rocha.la | Copyright (c) 2011 Piotr Rochala. Released under the MIT and GPL licenses. */
!function(e){e.fn.extend({slimScroll:function(i){var s={width:"auto",size:"7px",color:"#000",position:"right",distance:"1px",start:"top",opacity:.4,alwaysVisible:!1,disableFadeOut:!1,railVisible:!1,railColor:"#333",railOpacity:.2,railDraggable:!0,railClass:"slimScrollRail",barClass:"slimScrollBar",wrapperClass:"slimScrollDiv",allowPageScroll:!1,wheelStep:20,touchScrollStep:200,borderRadius:"7px",railBorderRadius:"7px"},o=e.extend(s,i);return this.each(function(){function s(t){if(h){var t=t||window.event,i=0;t.wheelDelta&&(i=-t.wheelDelta/120),t.detail&&(i=t.detail/3);var s=t.target||t.srcTarget||t.srcElement;e(s).closest("."+o.wrapperClass).is(x.parent())&&r(i,!0),t.preventDefault&&!y&&t.preventDefault(),y||(t.returnValue=!1)}}function r(e,t,i){y=!1;var s=e,r=x.outerHeight()-D.outerHeight();if(t&&(s=parseInt(D.css("top"))+e*parseInt(o.wheelStep)/100*D.outerHeight(),s=Math.min(Math.max(s,0),r),s=e>0?Math.ceil(s):Math.floor(s),D.css({top:s+"px"})),v=parseInt(D.css("top"))/(x.outerHeight()-D.outerHeight()),s=v*(x[0].scrollHeight-x.outerHeight()),i){s=e;var a=s/x[0].scrollHeight*x.outerHeight();a=Math.min(Math.max(a,0),r),D.css({top:a+"px"})}x.scrollTop(s),x.trigger("slimscrolling",~~s),n(),c()}function a(e){window.addEventListener?(e.addEventListener("DOMMouseScroll",s,!1),e.addEventListener("mousewheel",s,!1)):document.attachEvent("onmousewheel",s)}function l(){f=Math.max(x.outerHeight()/x[0].scrollHeight*x.outerHeight(),m),D.css({height:f+"px"});var e=f==x.outerHeight()?"none":"block";D.css({display:e})}function n(){if(l(),clearTimeout(p),v==~~v){if(y=o.allowPageScroll,b!=v){var e=0==~~v?"top":"bottom";x.trigger("slimscroll",e)}}else y=!1;return b=v,f>=x.outerHeight()?void(y=!0):(D.stop(!0,!0).fadeIn("fast"),void(o.railVisible&&R.stop(!0,!0).fadeIn("fast")))}function c(){o.alwaysVisible||(p=setTimeout(function(){o.disableFadeOut&&h||u||d||(D.fadeOut("slow"),R.fadeOut("slow"))},1e3))}var h,u,d,p,g,f,v,b,w="<div></div>",m=30,y=!1,x=e(this);if(x.parent().hasClass(o.wrapperClass)){var C=x.scrollTop();if(D=x.siblings("."+o.barClass),R=x.siblings("."+o.railClass),l(),e.isPlainObject(i)){if("height"in i&&"auto"==i.height){x.parent().css("height","auto"),x.css("height","auto");var H=x.parent().parent().height();x.parent().css("height",H),x.css("height",H)}else if("height"in i){var S=i.height;x.parent().css("height",S),x.css("height",S)}if("scrollTo"in i)C=parseInt(o.scrollTo);else if("scrollBy"in i)C+=parseInt(o.scrollBy);else if("destroy"in i)return D.remove(),R.remove(),void x.unwrap();r(C,!1,!0)}}else if(!(e.isPlainObject(i)&&"destroy"in i)){o.height="auto"==o.height?x.parent().height():o.height;var E=e(w).addClass(o.wrapperClass).css({position:"relative",overflow:"hidden",width:o.width,height:o.height});x.css({overflow:"hidden",width:o.width,height:o.height});var R=e(w).addClass(o.railClass).css({width:o.size,height:"100%",position:"absolute",top:0,display:o.alwaysVisible&&o.railVisible?"block":"none","border-radius":o.railBorderRadius,background:o.railColor,opacity:o.railOpacity,zIndex:90}),D=e(w).addClass(o.barClass).css({background:o.color,width:o.size,position:"absolute",top:0,opacity:o.opacity,display:o.alwaysVisible?"block":"none","border-radius":o.borderRadius,BorderRadius:o.borderRadius,MozBorderRadius:o.borderRadius,WebkitBorderRadius:o.borderRadius,zIndex:99}),M="right"==o.position?{right:o.distance}:{left:o.distance};R.css(M),D.css(M),x.wrap(E),x.parent().append(D),x.parent().append(R),o.railDraggable&&D.bind("mousedown",function(i){var s=e(document);return d=!0,t=parseFloat(D.css("top")),pageY=i.pageY,s.bind("mousemove.slimscroll",function(e){currTop=t+e.pageY-pageY,D.css("top",currTop),r(0,D.position().top,!1)}),s.bind("mouseup.slimscroll",function(e){d=!1,c(),s.unbind(".slimscroll")}),!1}).bind("selectstart.slimscroll",function(e){return e.stopPropagation(),e.preventDefault(),!1}),R.hover(function(){n()},function(){c()}),D.hover(function(){u=!0},function(){u=!1}),x.hover(function(){h=!0,n(),c()},function(){h=!1,c()}),x.bind("touchstart",function(e,t){e.originalEvent.touches.length&&(g=e.originalEvent.touches[0].pageY)}),x.bind("touchmove",function(e){if(y||e.originalEvent.preventDefault(),e.originalEvent.touches.length){var t=(g-e.originalEvent.touches[0].pageY)/o.touchScrollStep;r(t,!0),g=e.originalEvent.touches[0].pageY}}),l(),"bottom"===o.start?(D.css({top:x.outerHeight()-D.outerHeight()}),r(0,!0)):"top"!==o.start&&(r(e(o.start).position().top,null,!0),o.alwaysVisible||D.hide()),a(this)}}),this}}),e.fn.extend({slimscroll:e.fn.slimScroll})}(jQuery);

/* qTip2 v3.0.3 | https://qtip2.com | Released under the MIT and GPL licenses. */
!function(a,b,c){!function(a){"use strict";"function"==typeof define&&define.amd?define(["jquery"],a):jQuery&&!jQuery.fn.qtip&&a(jQuery)}(function(d){"use strict";function e(a,b,c,e){this.id=c,this.target=a,this.tooltip=D,this.elements={target:a},this._id=Q+"-"+c,this.timers={img:{}},this.options=b,this.plugins={},this.cache={event:{},target:d(),disabled:C,attr:e,onTooltip:C,lastClass:""},this.rendered=this.destroyed=this.disabled=this.waiting=this.hiddenDuringWait=this.positioning=this.triggering=C}function f(a){return a===D||"object"!==d.type(a)}function g(a){return!(d.isFunction(a)||a&&a.attr||a.length||"object"===d.type(a)&&(a.jquery||a.then))}function h(a){var b,c,e,h;return f(a)?C:(f(a.metadata)&&(a.metadata={type:a.metadata}),"content"in a&&(b=a.content,f(b)||b.jquery||b.done?(c=g(b)?C:b,b=a.content={text:c}):c=b.text,"ajax"in b&&(e=b.ajax,h=e&&e.once!==C,delete b.ajax,b.text=function(a,b){var f=c||d(this).attr(b.options.content.attr)||"Loading...",g=d.ajax(d.extend({},e,{context:b})).then(e.success,D,e.error).then(function(a){return a&&h&&b.set("content.text",a),a},function(a,c,d){b.destroyed||0===a.status||b.set("content.text",c+": "+d)});return h?f:(b.set("content.text",f),g)}),"title"in b&&(d.isPlainObject(b.title)&&(b.button=b.title.button,b.title=b.title.text),g(b.title||C)&&(b.title=C))),"position"in a&&f(a.position)&&(a.position={my:a.position,at:a.position}),"show"in a&&f(a.show)&&(a.show=a.show.jquery?{target:a.show}:a.show===B?{ready:B}:{event:a.show}),"hide"in a&&f(a.hide)&&(a.hide=a.hide.jquery?{target:a.hide}:{event:a.hide}),"style"in a&&f(a.style)&&(a.style={classes:a.style}),d.each(P,function(){this.sanitize&&this.sanitize(a)}),a)}function i(a,b){for(var c,d=0,e=a,f=b.split(".");e=e[f[d++]];)d<f.length&&(c=e);return[c||a,f.pop()]}function j(a,b){var c,d,e;for(c in this.checks)if(this.checks.hasOwnProperty(c))for(d in this.checks[c])this.checks[c].hasOwnProperty(d)&&(e=new RegExp(d,"i").exec(a))&&(b.push(e),("builtin"===c||this.plugins[c])&&this.checks[c][d].apply(this.plugins[c]||this,b))}function k(a){return T.concat("").join(a?"-"+a+" ":" ")}function l(a,b){return b>0?setTimeout(d.proxy(a,this),b):void a.call(this)}function m(a){this.tooltip.hasClass($)||(clearTimeout(this.timers.show),clearTimeout(this.timers.hide),this.timers.show=l.call(this,function(){this.toggle(B,a)},this.options.show.delay))}function n(a){if(!this.tooltip.hasClass($)&&!this.destroyed){var b=d(a.relatedTarget),c=b.closest(U)[0]===this.tooltip[0],e=b[0]===this.options.show.target[0];if(clearTimeout(this.timers.show),clearTimeout(this.timers.hide),this!==b[0]&&"mouse"===this.options.position.target&&c||this.options.hide.fixed&&/mouse(out|leave|move)/.test(a.type)&&(c||e))try{a.preventDefault(),a.stopImmediatePropagation()}catch(f){}else this.timers.hide=l.call(this,function(){this.toggle(C,a)},this.options.hide.delay,this)}}function o(a){!this.tooltip.hasClass($)&&this.options.hide.inactive&&(clearTimeout(this.timers.inactive),this.timers.inactive=l.call(this,function(){this.hide(a)},this.options.hide.inactive))}function p(a){this.rendered&&this.tooltip[0].offsetWidth>0&&this.reposition(a)}function q(a,c,e){d(b.body).delegate(a,(c.split?c:c.join("."+Q+" "))+"."+Q,function(){var a=w.api[d.attr(this,S)];a&&!a.disabled&&e.apply(a,arguments)})}function r(a,c,f){var g,i,j,k,l,m=d(b.body),n=a[0]===b?m:a,o=a.metadata?a.metadata(f.metadata):D,p="html5"===f.metadata.type&&o?o[f.metadata.name]:D,q=a.data(f.metadata.name||"qtipopts");try{q="string"==typeof q?d.parseJSON(q):q}catch(r){}if(k=d.extend(B,{},w.defaults,f,"object"==typeof q?h(q):D,h(p||o)),i=k.position,k.id=c,"boolean"==typeof k.content.text){if(j=a.attr(k.content.attr),k.content.attr===C||!j)return C;k.content.text=j}if(i.container.length||(i.container=m),i.target===C&&(i.target=n),k.show.target===C&&(k.show.target=n),k.show.solo===B&&(k.show.solo=i.container.closest("body")),k.hide.target===C&&(k.hide.target=n),k.position.viewport===B&&(k.position.viewport=i.container),i.container=i.container.eq(0),i.at=new y(i.at,B),i.my=new y(i.my),a.data(Q))if(k.overwrite)a.qtip("destroy",!0);else if(k.overwrite===C)return C;return a.attr(R,c),k.suppress&&(l=a.attr("title"))&&a.removeAttr("title").attr(aa,l).attr("title",""),g=new e(a,k,c,!!j),a.data(Q,g),g}function s(a){return a.charAt(0).toUpperCase()+a.slice(1)}function t(a,b){var d,e,f=b.charAt(0).toUpperCase()+b.slice(1),g=(b+" "+ta.join(f+" ")+f).split(" "),h=0;if(sa[b])return a.css(sa[b]);for(;d=g[h++];)if((e=a.css(d))!==c)return sa[b]=d,e}function u(a,b){return Math.ceil(parseFloat(t(a,b)))}function v(a,b){this._ns="tip",this.options=b,this.offset=b.offset,this.size=[b.width,b.height],this.qtip=a,this.init(a)}var w,x,y,z,A,B=!0,C=!1,D=null,E="x",F="y",G="width",H="height",I="top",J="left",K="bottom",L="right",M="center",N="flipinvert",O="shift",P={},Q="qtip",R="data-hasqtip",S="data-qtip-id",T=["ui-widget","ui-tooltip"],U="."+Q,V="click dblclick mousedown mouseup mousemove mouseleave mouseenter".split(" "),W=Q+"-fixed",X=Q+"-default",Y=Q+"-focus",Z=Q+"-hover",$=Q+"-disabled",_="_replacedByqTip",aa="oldtitle",ba={ie:function(){var a,c;for(a=4,c=b.createElement("div");(c.innerHTML="<!--[if gt IE "+a+"]><i></i><![endif]-->")&&c.getElementsByTagName("i")[0];a+=1);return a>4?a:NaN}(),iOS:parseFloat((""+(/CPU.*OS ([0-9_]{1,5})|(CPU like).*AppleWebKit.*Mobile/i.exec(navigator.userAgent)||[0,""])[1]).replace("undefined","3_2").replace("_",".").replace("_",""))||C};x=e.prototype,x._when=function(a){return d.when.apply(d,a)},x.render=function(a){if(this.rendered||this.destroyed)return this;var b=this,c=this.options,e=this.cache,f=this.elements,g=c.content.text,h=c.content.title,i=c.content.button,j=c.position,k=[];return d.attr(this.target[0],"aria-describedby",this._id),e.posClass=this._createPosClass((this.position={my:j.my,at:j.at}).my),this.tooltip=f.tooltip=d("<div/>",{id:this._id,"class":[Q,X,c.style.classes,e.posClass].join(" "),width:c.style.width||"",height:c.style.height||"",tracking:"mouse"===j.target&&j.adjust.mouse,role:"alert","aria-live":"polite","aria-atomic":C,"aria-describedby":this._id+"-content","aria-hidden":B}).toggleClass($,this.disabled).attr(S,this.id).data(Q,this).appendTo(j.container).append(f.content=d("<div />",{"class":Q+"-content",id:this._id+"-content","aria-atomic":B})),this.rendered=-1,this.positioning=B,h&&(this._createTitle(),d.isFunction(h)||k.push(this._updateTitle(h,C))),i&&this._createButton(),d.isFunction(g)||k.push(this._updateContent(g,C)),this.rendered=B,this._setWidget(),d.each(P,function(a){var c;"render"===this.initialize&&(c=this(b))&&(b.plugins[a]=c)}),this._unassignEvents(),this._assignEvents(),this._when(k).then(function(){b._trigger("render"),b.positioning=C,b.hiddenDuringWait||!c.show.ready&&!a||b.toggle(B,e.event,C),b.hiddenDuringWait=C}),w.api[this.id]=this,this},x.destroy=function(a){function b(){if(!this.destroyed){this.destroyed=B;var a,b=this.target,c=b.attr(aa);this.rendered&&this.tooltip.stop(1,0).find("*").remove().end().remove(),d.each(this.plugins,function(){this.destroy&&this.destroy()});for(a in this.timers)this.timers.hasOwnProperty(a)&&clearTimeout(this.timers[a]);b.removeData(Q).removeAttr(S).removeAttr(R).removeAttr("aria-describedby"),this.options.suppress&&c&&b.attr("title",c).removeAttr(aa),this._unassignEvents(),this.options=this.elements=this.cache=this.timers=this.plugins=this.mouse=D,delete w.api[this.id]}}return this.destroyed?this.target:(a===B&&"hide"!==this.triggering||!this.rendered?b.call(this):(this.tooltip.one("tooltiphidden",d.proxy(b,this)),!this.triggering&&this.hide()),this.target)},z=x.checks={builtin:{"^id$":function(a,b,c,e){var f=c===B?w.nextid:c,g=Q+"-"+f;f!==C&&f.length>0&&!d("#"+g).length?(this._id=g,this.rendered&&(this.tooltip[0].id=this._id,this.elements.content[0].id=this._id+"-content",this.elements.title[0].id=this._id+"-title")):a[b]=e},"^prerender":function(a,b,c){c&&!this.rendered&&this.render(this.options.show.ready)},"^content.text$":function(a,b,c){this._updateContent(c)},"^content.attr$":function(a,b,c,d){this.options.content.text===this.target.attr(d)&&this._updateContent(this.target.attr(c))},"^content.title$":function(a,b,c){return c?(c&&!this.elements.title&&this._createTitle(),void this._updateTitle(c)):this._removeTitle()},"^content.button$":function(a,b,c){this._updateButton(c)},"^content.title.(text|button)$":function(a,b,c){this.set("content."+b,c)},"^position.(my|at)$":function(a,b,c){"string"==typeof c&&(this.position[b]=a[b]=new y(c,"at"===b))},"^position.container$":function(a,b,c){this.rendered&&this.tooltip.appendTo(c)},"^show.ready$":function(a,b,c){c&&(!this.rendered&&this.render(B)||this.toggle(B))},"^style.classes$":function(a,b,c,d){this.rendered&&this.tooltip.removeClass(d).addClass(c)},"^style.(width|height)":function(a,b,c){this.rendered&&this.tooltip.css(b,c)},"^style.widget|content.title":function(){this.rendered&&this._setWidget()},"^style.def":function(a,b,c){this.rendered&&this.tooltip.toggleClass(X,!!c)},"^events.(render|show|move|hide|focus|blur)$":function(a,b,c){this.rendered&&this.tooltip[(d.isFunction(c)?"":"un")+"bind"]("tooltip"+b,c)},"^(show|hide|position).(event|target|fixed|inactive|leave|distance|viewport|adjust)":function(){if(this.rendered){var a=this.options.position;this.tooltip.attr("tracking","mouse"===a.target&&a.adjust.mouse),this._unassignEvents(),this._assignEvents()}}}},x.get=function(a){if(this.destroyed)return this;var b=i(this.options,a.toLowerCase()),c=b[0][b[1]];return c.precedance?c.string():c};var ca=/^position\.(my|at|adjust|target|container|viewport)|style|content|show\.ready/i,da=/^prerender|show\.ready/i;x.set=function(a,b){if(this.destroyed)return this;var c,e=this.rendered,f=C,g=this.options;return"string"==typeof a?(c=a,a={},a[c]=b):a=d.extend({},a),d.each(a,function(b,c){if(e&&da.test(b))return void delete a[b];var h,j=i(g,b.toLowerCase());h=j[0][j[1]],j[0][j[1]]=c&&c.nodeType?d(c):c,f=ca.test(b)||f,a[b]=[j[0],j[1],c,h]}),h(g),this.positioning=B,d.each(a,d.proxy(j,this)),this.positioning=C,this.rendered&&this.tooltip[0].offsetWidth>0&&f&&this.reposition("mouse"===g.position.target?D:this.cache.event),this},x._update=function(a,b){var c=this,e=this.cache;return this.rendered&&a?(d.isFunction(a)&&(a=a.call(this.elements.target,e.event,this)||""),d.isFunction(a.then)?(e.waiting=B,a.then(function(a){return e.waiting=C,c._update(a,b)},D,function(a){return c._update(a,b)})):a===C||!a&&""!==a?C:(a.jquery&&a.length>0?b.empty().append(a.css({display:"block",visibility:"visible"})):b.html(a),this._waitForContent(b).then(function(a){c.rendered&&c.tooltip[0].offsetWidth>0&&c.reposition(e.event,!a.length)}))):C},x._waitForContent=function(a){var b=this.cache;return b.waiting=B,(d.fn.imagesLoaded?a.imagesLoaded():(new d.Deferred).resolve([])).done(function(){b.waiting=C}).promise()},x._updateContent=function(a,b){this._update(a,this.elements.content,b)},x._updateTitle=function(a,b){this._update(a,this.elements.title,b)===C&&this._removeTitle(C)},x._createTitle=function(){var a=this.elements,b=this._id+"-title";a.titlebar&&this._removeTitle(),a.titlebar=d("<div />",{"class":Q+"-titlebar "+(this.options.style.widget?k("header"):"")}).append(a.title=d("<div />",{id:b,"class":Q+"-title","aria-atomic":B})).insertBefore(a.content).delegate(".qtip-close","mousedown keydown mouseup keyup mouseout",function(a){d(this).toggleClass("ui-state-active ui-state-focus","down"===a.type.substr(-4))}).delegate(".qtip-close","mouseover mouseout",function(a){d(this).toggleClass("ui-state-hover","mouseover"===a.type)}),this.options.content.button&&this._createButton()},x._removeTitle=function(a){var b=this.elements;b.title&&(b.titlebar.remove(),b.titlebar=b.title=b.button=D,a!==C&&this.reposition())},x._createPosClass=function(a){return Q+"-pos-"+(a||this.options.position.my).abbrev()},x.reposition=function(c,e){if(!this.rendered||this.positioning||this.destroyed)return this;this.positioning=B;var f,g,h,i,j=this.cache,k=this.tooltip,l=this.options.position,m=l.target,n=l.my,o=l.at,p=l.viewport,q=l.container,r=l.adjust,s=r.method.split(" "),t=k.outerWidth(C),u=k.outerHeight(C),v=0,w=0,x=k.css("position"),y={left:0,top:0},z=k[0].offsetWidth>0,A=c&&"scroll"===c.type,D=d(a),E=q[0].ownerDocument,F=this.mouse;if(d.isArray(m)&&2===m.length)o={x:J,y:I},y={left:m[0],top:m[1]};else if("mouse"===m)o={x:J,y:I},(!r.mouse||this.options.hide.distance)&&j.origin&&j.origin.pageX?c=j.origin:!c||c&&("resize"===c.type||"scroll"===c.type)?c=j.event:F&&F.pageX&&(c=F),"static"!==x&&(y=q.offset()),E.body.offsetWidth!==(a.innerWidth||E.documentElement.clientWidth)&&(g=d(b.body).offset()),y={left:c.pageX-y.left+(g&&g.left||0),top:c.pageY-y.top+(g&&g.top||0)},r.mouse&&A&&F&&(y.left-=(F.scrollX||0)-D.scrollLeft(),y.top-=(F.scrollY||0)-D.scrollTop());else{if("event"===m?c&&c.target&&"scroll"!==c.type&&"resize"!==c.type?j.target=d(c.target):c.target||(j.target=this.elements.target):"event"!==m&&(j.target=d(m.jquery?m:this.elements.target)),m=j.target,m=d(m).eq(0),0===m.length)return this;m[0]===b||m[0]===a?(v=ba.iOS?a.innerWidth:m.width(),w=ba.iOS?a.innerHeight:m.height(),m[0]===a&&(y={top:(p||m).scrollTop(),left:(p||m).scrollLeft()})):P.imagemap&&m.is("area")?f=P.imagemap(this,m,o,P.viewport?s:C):P.svg&&m&&m[0].ownerSVGElement?f=P.svg(this,m,o,P.viewport?s:C):(v=m.outerWidth(C),w=m.outerHeight(C),y=m.offset()),f&&(v=f.width,w=f.height,g=f.offset,y=f.position),y=this.reposition.offset(m,y,q),(ba.iOS>3.1&&ba.iOS<4.1||ba.iOS>=4.3&&ba.iOS<4.33||!ba.iOS&&"fixed"===x)&&(y.left-=D.scrollLeft(),y.top-=D.scrollTop()),(!f||f&&f.adjustable!==C)&&(y.left+=o.x===L?v:o.x===M?v/2:0,y.top+=o.y===K?w:o.y===M?w/2:0)}return y.left+=r.x+(n.x===L?-t:n.x===M?-t/2:0),y.top+=r.y+(n.y===K?-u:n.y===M?-u/2:0),P.viewport?(h=y.adjusted=P.viewport(this,y,l,v,w,t,u),g&&h.left&&(y.left+=g.left),g&&h.top&&(y.top+=g.top),h.my&&(this.position.my=h.my)):y.adjusted={left:0,top:0},j.posClass!==(i=this._createPosClass(this.position.my))&&(j.posClass=i,k.removeClass(j.posClass).addClass(i)),this._trigger("move",[y,p.elem||p],c)?(delete y.adjusted,e===C||!z||isNaN(y.left)||isNaN(y.top)||"mouse"===m||!d.isFunction(l.effect)?k.css(y):d.isFunction(l.effect)&&(l.effect.call(k,this,d.extend({},y)),k.queue(function(a){d(this).css({opacity:"",height:""}),ba.ie&&this.style.removeAttribute("filter"),a()})),this.positioning=C,this):this},x.reposition.offset=function(a,c,e){function f(a,b){c.left+=b*a.scrollLeft(),c.top+=b*a.scrollTop()}if(!e[0])return c;var g,h,i,j,k=d(a[0].ownerDocument),l=!!ba.ie&&"CSS1Compat"!==b.compatMode,m=e[0];do"static"!==(h=d.css(m,"position"))&&("fixed"===h?(i=m.getBoundingClientRect(),f(k,-1)):(i=d(m).position(),i.left+=parseFloat(d.css(m,"borderLeftWidth"))||0,i.top+=parseFloat(d.css(m,"borderTopWidth"))||0),c.left-=i.left+(parseFloat(d.css(m,"marginLeft"))||0),c.top-=i.top+(parseFloat(d.css(m,"marginTop"))||0),g||"hidden"===(j=d.css(m,"overflow"))||"visible"===j||(g=d(m)));while(m=m.offsetParent);return g&&(g[0]!==k[0]||l)&&f(g,1),c};var ea=(y=x.reposition.Corner=function(a,b){a=(""+a).replace(/([A-Z])/," $1").replace(/middle/gi,M).toLowerCase(),this.x=(a.match(/left|right/i)||a.match(/center/)||["inherit"])[0].toLowerCase(),this.y=(a.match(/top|bottom|center/i)||["inherit"])[0].toLowerCase(),this.forceY=!!b;var c=a.charAt(0);this.precedance="t"===c||"b"===c?F:E}).prototype;ea.invert=function(a,b){this[a]=this[a]===J?L:this[a]===L?J:b||this[a]},ea.string=function(a){var b=this.x,c=this.y,d=b!==c?"center"===b||"center"!==c&&(this.precedance===F||this.forceY)?[c,b]:[b,c]:[b];return a!==!1?d.join(" "):d},ea.abbrev=function(){var a=this.string(!1);return a[0].charAt(0)+(a[1]&&a[1].charAt(0)||"")},ea.clone=function(){return new y(this.string(),this.forceY)},x.toggle=function(a,c){var e=this.cache,f=this.options,g=this.tooltip;if(c){if(/over|enter/.test(c.type)&&e.event&&/out|leave/.test(e.event.type)&&f.show.target.add(c.target).length===f.show.target.length&&g.has(c.relatedTarget).length)return this;e.event=d.event.fix(c)}if(this.waiting&&!a&&(this.hiddenDuringWait=B),!this.rendered)return a?this.render(1):this;if(this.destroyed||this.disabled)return this;var h,i,j,k=a?"show":"hide",l=this.options[k],m=this.options.position,n=this.options.content,o=this.tooltip.css("width"),p=this.tooltip.is(":visible"),q=a||1===l.target.length,r=!c||l.target.length<2||e.target[0]===c.target;return(typeof a).search("boolean|number")&&(a=!p),h=!g.is(":animated")&&p===a&&r,i=h?D:!!this._trigger(k,[90]),this.destroyed?this:(i!==C&&a&&this.focus(c),!i||h?this:(d.attr(g[0],"aria-hidden",!a),a?(this.mouse&&(e.origin=d.event.fix(this.mouse)),d.isFunction(n.text)&&this._updateContent(n.text,C),d.isFunction(n.title)&&this._updateTitle(n.title,C),!A&&"mouse"===m.target&&m.adjust.mouse&&(d(b).bind("mousemove."+Q,this._storeMouse),A=B),o||g.css("width",g.outerWidth(C)),this.reposition(c,arguments[2]),o||g.css("width",""),l.solo&&("string"==typeof l.solo?d(l.solo):d(U,l.solo)).not(g).not(l.target).qtip("hide",new d.Event("tooltipsolo"))):(clearTimeout(this.timers.show),delete e.origin,A&&!d(U+'[tracking="true"]:visible',l.solo).not(g).length&&(d(b).unbind("mousemove."+Q),A=C),this.blur(c)),j=d.proxy(function(){a?(ba.ie&&g[0].style.removeAttribute("filter"),g.css("overflow",""),"string"==typeof l.autofocus&&d(this.options.show.autofocus,g).focus(),this.options.show.target.trigger("qtip-"+this.id+"-inactive")):g.css({display:"",visibility:"",opacity:"",left:"",top:""}),this._trigger(a?"visible":"hidden")},this),l.effect===C||q===C?(g[k](),j()):d.isFunction(l.effect)?(g.stop(1,1),l.effect.call(g,this),g.queue("fx",function(a){j(),a()})):g.fadeTo(90,a?1:0,j),a&&l.target.trigger("qtip-"+this.id+"-inactive"),this))},x.show=function(a){return this.toggle(B,a)},x.hide=function(a){return this.toggle(C,a)},x.focus=function(a){if(!this.rendered||this.destroyed)return this;var b=d(U),c=this.tooltip,e=parseInt(c[0].style.zIndex,10),f=w.zindex+b.length;return c.hasClass(Y)||this._trigger("focus",[f],a)&&(e!==f&&(b.each(function(){this.style.zIndex>e&&(this.style.zIndex=this.style.zIndex-1)}),b.filter("."+Y).qtip("blur",a)),c.addClass(Y)[0].style.zIndex=f),this},x.blur=function(a){return!this.rendered||this.destroyed?this:(this.tooltip.removeClass(Y),this._trigger("blur",[this.tooltip.css("zIndex")],a),this)},x.disable=function(a){return this.destroyed?this:("toggle"===a?a=!(this.rendered?this.tooltip.hasClass($):this.disabled):"boolean"!=typeof a&&(a=B),this.rendered&&this.tooltip.toggleClass($,a).attr("aria-disabled",a),this.disabled=!!a,this)},x.enable=function(){return this.disable(C)},x._createButton=function(){var a=this,b=this.elements,c=b.tooltip,e=this.options.content.button,f="string"==typeof e,g=f?e:"Close tooltip";b.button&&b.button.remove(),e.jquery?b.button=e:b.button=d("<a />",{"class":"qtip-close "+(this.options.style.widget?"":Q+"-icon"),title:g,"aria-label":g}).prepend(d("<span />",{"class":"ui-icon ui-icon-close",html:"&times;"})),b.button.appendTo(b.titlebar||c).attr("role","button").click(function(b){return c.hasClass($)||a.hide(b),C})},x._updateButton=function(a){if(!this.rendered)return C;var b=this.elements.button;a?this._createButton():b.remove()},x._setWidget=function(){var a=this.options.style.widget,b=this.elements,c=b.tooltip,d=c.hasClass($);c.removeClass($),$=a?"ui-state-disabled":"qtip-disabled",c.toggleClass($,d),c.toggleClass("ui-helper-reset "+k(),a).toggleClass(X,this.options.style.def&&!a),b.content&&b.content.toggleClass(k("content"),a),b.titlebar&&b.titlebar.toggleClass(k("header"),a),b.button&&b.button.toggleClass(Q+"-icon",!a)},x._storeMouse=function(a){return(this.mouse=d.event.fix(a)).type="mousemove",this},x._bind=function(a,b,c,e,f){if(a&&c&&b.length){var g="."+this._id+(e?"-"+e:"");return d(a).bind((b.split?b:b.join(g+" "))+g,d.proxy(c,f||this)),this}},x._unbind=function(a,b){return a&&d(a).unbind("."+this._id+(b?"-"+b:"")),this},x._trigger=function(a,b,c){var e=new d.Event("tooltip"+a);return e.originalEvent=c&&d.extend({},c)||this.cache.event||D,this.triggering=a,this.tooltip.trigger(e,[this].concat(b||[])),this.triggering=C,!e.isDefaultPrevented()},x._bindEvents=function(a,b,c,e,f,g){var h=c.filter(e).add(e.filter(c)),i=[];h.length&&(d.each(b,function(b,c){var e=d.inArray(c,a);e>-1&&i.push(a.splice(e,1)[0])}),i.length&&(this._bind(h,i,function(a){var b=this.rendered?this.tooltip[0].offsetWidth>0:!1;(b?g:f).call(this,a)}),c=c.not(h),e=e.not(h))),this._bind(c,a,f),this._bind(e,b,g)},x._assignInitialEvents=function(a){function b(a){return this.disabled||this.destroyed?C:(this.cache.event=a&&d.event.fix(a),this.cache.target=a&&d(a.target),clearTimeout(this.timers.show),void(this.timers.show=l.call(this,function(){this.render("object"==typeof a||c.show.ready)},c.prerender?0:c.show.delay)))}var c=this.options,e=c.show.target,f=c.hide.target,g=c.show.event?d.trim(""+c.show.event).split(" "):[],h=c.hide.event?d.trim(""+c.hide.event).split(" "):[];this._bind(this.elements.target,["remove","removeqtip"],function(){this.destroy(!0)},"destroy"),/mouse(over|enter)/i.test(c.show.event)&&!/mouse(out|leave)/i.test(c.hide.event)&&h.push("mouseleave"),this._bind(e,"mousemove",function(a){this._storeMouse(a),this.cache.onTarget=B}),this._bindEvents(g,h,e,f,b,function(){return this.timers?void clearTimeout(this.timers.show):C}),(c.show.ready||c.prerender)&&b.call(this,a)},x._assignEvents=function(){var c=this,e=this.options,f=e.position,g=this.tooltip,h=e.show.target,i=e.hide.target,j=f.container,k=f.viewport,l=d(b),q=d(a),r=e.show.event?d.trim(""+e.show.event).split(" "):[],s=e.hide.event?d.trim(""+e.hide.event).split(" "):[];d.each(e.events,function(a,b){c._bind(g,"toggle"===a?["tooltipshow","tooltiphide"]:["tooltip"+a],b,null,g)}),/mouse(out|leave)/i.test(e.hide.event)&&"window"===e.hide.leave&&this._bind(l,["mouseout","blur"],function(a){/select|option/.test(a.target.nodeName)||a.relatedTarget||this.hide(a)}),e.hide.fixed?i=i.add(g.addClass(W)):/mouse(over|enter)/i.test(e.show.event)&&this._bind(i,"mouseleave",function(){clearTimeout(this.timers.show)}),(""+e.hide.event).indexOf("unfocus")>-1&&this._bind(j.closest("html"),["mousedown","touchstart"],function(a){var b=d(a.target),c=this.rendered&&!this.tooltip.hasClass($)&&this.tooltip[0].offsetWidth>0,e=b.parents(U).filter(this.tooltip[0]).length>0;b[0]===this.target[0]||b[0]===this.tooltip[0]||e||this.target.has(b[0]).length||!c||this.hide(a)}),"number"==typeof e.hide.inactive&&(this._bind(h,"qtip-"+this.id+"-inactive",o,"inactive"),this._bind(i.add(g),w.inactiveEvents,o)),this._bindEvents(r,s,h,i,m,n),this._bind(h.add(g),"mousemove",function(a){if("number"==typeof e.hide.distance){var b=this.cache.origin||{},c=this.options.hide.distance,d=Math.abs;(d(a.pageX-b.pageX)>=c||d(a.pageY-b.pageY)>=c)&&this.hide(a)}this._storeMouse(a)}),"mouse"===f.target&&f.adjust.mouse&&(e.hide.event&&this._bind(h,["mouseenter","mouseleave"],function(a){return this.cache?void(this.cache.onTarget="mouseenter"===a.type):C}),this._bind(l,"mousemove",function(a){this.rendered&&this.cache.onTarget&&!this.tooltip.hasClass($)&&this.tooltip[0].offsetWidth>0&&this.reposition(a)})),(f.adjust.resize||k.length)&&this._bind(d.event.special.resize?k:q,"resize",p),f.adjust.scroll&&this._bind(q.add(f.container),"scroll",p)},x._unassignEvents=function(){var c=this.options,e=c.show.target,f=c.hide.target,g=d.grep([this.elements.target[0],this.rendered&&this.tooltip[0],c.position.container[0],c.position.viewport[0],c.position.container.closest("html")[0],a,b],function(a){return"object"==typeof a});e&&e.toArray&&(g=g.concat(e.toArray())),f&&f.toArray&&(g=g.concat(f.toArray())),this._unbind(g)._unbind(g,"destroy")._unbind(g,"inactive")},d(function(){q(U,["mouseenter","mouseleave"],function(a){var b="mouseenter"===a.type,c=d(a.currentTarget),e=d(a.relatedTarget||a.target),f=this.options;b?(this.focus(a),c.hasClass(W)&&!c.hasClass($)&&clearTimeout(this.timers.hide)):"mouse"===f.position.target&&f.position.adjust.mouse&&f.hide.event&&f.show.target&&!e.closest(f.show.target[0]).length&&this.hide(a),c.toggleClass(Z,b)}),q("["+S+"]",V,o)}),w=d.fn.qtip=function(a,b,e){var f=(""+a).toLowerCase(),g=D,i=d.makeArray(arguments).slice(1),j=i[i.length-1],k=this[0]?d.data(this[0],Q):D;return!arguments.length&&k||"api"===f?k:"string"==typeof a?(this.each(function(){var a=d.data(this,Q);if(!a)return B;if(j&&j.timeStamp&&(a.cache.event=j),!b||"option"!==f&&"options"!==f)a[f]&&a[f].apply(a,i);else{if(e===c&&!d.isPlainObject(b))return g=a.get(b),C;a.set(b,e)}}),g!==D?g:this):"object"!=typeof a&&arguments.length?void 0:(k=h(d.extend(B,{},a)),this.each(function(a){var b,c;return c=d.isArray(k.id)?k.id[a]:k.id,c=!c||c===C||c.length<1||w.api[c]?w.nextid++:c,b=r(d(this),c,k),b===C?B:(w.api[c]=b,d.each(P,function(){"initialize"===this.initialize&&this(b)}),void b._assignInitialEvents(j))}))},d.qtip=e,w.api={},d.each({attr:function(a,b){if(this.length){var c=this[0],e="title",f=d.data(c,"qtip");if(a===e&&f&&f.options&&"object"==typeof f&&"object"==typeof f.options&&f.options.suppress)return arguments.length<2?d.attr(c,aa):(f&&f.options.content.attr===e&&f.cache.attr&&f.set("content.text",b),this.attr(aa,b))}return d.fn["attr"+_].apply(this,arguments)},clone:function(a){var b=d.fn["clone"+_].apply(this,arguments);return a||b.filter("["+aa+"]").attr("title",function(){return d.attr(this,aa)}).removeAttr(aa),b}},function(a,b){if(!b||d.fn[a+_])return B;var c=d.fn[a+_]=d.fn[a];d.fn[a]=function(){return b.apply(this,arguments)||c.apply(this,arguments)}}),d.ui||(d["cleanData"+_]=d.cleanData,d.cleanData=function(a){for(var b,c=0;(b=d(a[c])).length;c++)if(b.attr(R))try{b.triggerHandler("removeqtip")}catch(e){}d["cleanData"+_].apply(this,arguments)}),w.version="3.0.3",w.nextid=0,w.inactiveEvents=V,w.zindex=15e3,w.defaults={prerender:C,id:C,overwrite:B,suppress:B,content:{text:B,attr:"title",title:C,button:C},position:{my:"top left",at:"bottom right",target:C,container:C,viewport:C,adjust:{x:0,y:0,mouse:B,scroll:B,resize:B,method:"flipinvert flipinvert"},effect:function(a,b){d(this).animate(b,{duration:200,queue:C})}},show:{target:C,event:"mouseenter",effect:B,delay:90,solo:C,ready:C,autofocus:C},hide:{target:C,event:"mouseleave",effect:B,delay:0,fixed:C,inactive:C,leave:"window",distance:C},style:{classes:"",widget:C,width:C,height:C,def:B},events:{render:D,move:D,show:D,hide:D,toggle:D,visible:D,hidden:D,focus:D,blur:D}};var fa,ga,ha,ia,ja,ka="margin",la="border",ma="color",na="background-color",oa="transparent",pa=" !important",qa=!!b.createElement("canvas").getContext,ra=/rgba?\(0, 0, 0(, 0)?\)|transparent|#123456/i,sa={},ta=["Webkit","O","Moz","ms"];qa?(ia=a.devicePixelRatio||1,ja=function(){var a=b.createElement("canvas").getContext("2d");return a.backingStorePixelRatio||a.webkitBackingStorePixelRatio||a.mozBackingStorePixelRatio||a.msBackingStorePixelRatio||a.oBackingStorePixelRatio||1}(),ha=ia/ja):ga=function(a,b,c){return"<qtipvml:"+a+' xmlns="urn:schemas-microsoft.com:vml" class="qtip-vml" '+(b||"")+' style="behavior: url(#default#VML); '+(c||"")+'" />'},d.extend(v.prototype,{init:function(a){var b,c;c=this.element=a.elements.tip=d("<div />",{"class":Q+"-tip"}).prependTo(a.tooltip),qa?(b=d("<canvas />").appendTo(this.element)[0].getContext("2d"),b.lineJoin="miter",b.miterLimit=1e5,b.save()):(b=ga("shape",'coordorigin="0,0"',"position:absolute;"),this.element.html(b+b),a._bind(d("*",c).add(c),["click","mousedown"],function(a){a.stopPropagation()},this._ns)),a._bind(a.tooltip,"tooltipmove",this.reposition,this._ns,this),this.create()},_swapDimensions:function(){this.size[0]=this.options.height,this.size[1]=this.options.width},_resetDimensions:function(){this.size[0]=this.options.width,this.size[1]=this.options.height},_useTitle:function(a){var b=this.qtip.elements.titlebar;return b&&(a.y===I||a.y===M&&this.element.position().top+this.size[1]/2+this.options.offset<b.outerHeight(B))},_parseCorner:function(a){var b=this.qtip.options.position.my;return a===C||b===C?a=C:a===B?a=new y(b.string()):a.string||(a=new y(a),a.fixed=B),a},_parseWidth:function(a,b,c){var d=this.qtip.elements,e=la+s(b)+"Width";return(c?u(c,e):u(d.content,e)||u(this._useTitle(a)&&d.titlebar||d.content,e)||u(d.tooltip,e))||0},_parseRadius:function(a){var b=this.qtip.elements,c=la+s(a.y)+s(a.x)+"Radius";return ba.ie<9?0:u(this._useTitle(a)&&b.titlebar||b.content,c)||u(b.tooltip,c)||0},_invalidColour:function(a,b,c){var d=a.css(b);return!d||c&&d===a.css(c)||ra.test(d)?C:d},_parseColours:function(a){var b=this.qtip.elements,c=this.element.css("cssText",""),e=la+s(a[a.precedance])+s(ma),f=this._useTitle(a)&&b.titlebar||b.content,g=this._invalidColour,h=[];return h[0]=g(c,na)||g(f,na)||g(b.content,na)||g(b.tooltip,na)||c.css(na),h[1]=g(c,e,ma)||g(f,e,ma)||g(b.content,e,ma)||g(b.tooltip,e,ma)||b.tooltip.css(e),d("*",c).add(c).css("cssText",na+":"+oa+pa+";"+la+":0"+pa+";"),h},_calculateSize:function(a){var b,c,d,e=a.precedance===F,f=this.options.width,g=this.options.height,h="c"===a.abbrev(),i=(e?f:g)*(h?.5:1),j=Math.pow,k=Math.round,l=Math.sqrt(j(i,2)+j(g,2)),m=[this.border/i*l,this.border/g*l];return m[2]=Math.sqrt(j(m[0],2)-j(this.border,2)),m[3]=Math.sqrt(j(m[1],2)-j(this.border,2)),b=l+m[2]+m[3]+(h?0:m[0]),c=b/l,d=[k(c*f),k(c*g)],e?d:d.reverse()},_calculateTip:function(a,b,c){c=c||1,b=b||this.size;var d=b[0]*c,e=b[1]*c,f=Math.ceil(d/2),g=Math.ceil(e/2),h={br:[0,0,d,e,d,0],bl:[0,0,d,0,0,e],tr:[0,e,d,0,d,e],tl:[0,0,0,e,d,e],tc:[0,e,f,0,d,e],bc:[0,0,d,0,f,e],rc:[0,0,d,g,0,e],lc:[d,0,d,e,0,g]};return h.lt=h.br,h.rt=h.bl,h.lb=h.tr,h.rb=h.tl,h[a.abbrev()]},_drawCoords:function(a,b){a.beginPath(),a.moveTo(b[0],b[1]),a.lineTo(b[2],b[3]),a.lineTo(b[4],b[5]),a.closePath()},create:function(){var a=this.corner=(qa||ba.ie)&&this._parseCorner(this.options.corner);return this.enabled=!!this.corner&&"c"!==this.corner.abbrev(),this.enabled&&(this.qtip.cache.corner=a.clone(),this.update()),this.element.toggle(this.enabled),this.corner},update:function(b,c){if(!this.enabled)return this;var e,f,g,h,i,j,k,l,m=this.qtip.elements,n=this.element,o=n.children(),p=this.options,q=this.size,r=p.mimic,s=Math.round;b||(b=this.qtip.cache.corner||this.corner),r===C?r=b:(r=new y(r),r.precedance=b.precedance,"inherit"===r.x?r.x=b.x:"inherit"===r.y?r.y=b.y:r.x===r.y&&(r[b.precedance]=b[b.precedance])),f=r.precedance,b.precedance===E?this._swapDimensions():this._resetDimensions(),e=this.color=this._parseColours(b),e[1]!==oa?(l=this.border=this._parseWidth(b,b[b.precedance]),p.border&&1>l&&!ra.test(e[1])&&(e[0]=e[1]),this.border=l=p.border!==B?p.border:l):this.border=l=0,k=this.size=this._calculateSize(b),n.css({width:k[0],height:k[1],lineHeight:k[1]+"px"}),j=b.precedance===F?[s(r.x===J?l:r.x===L?k[0]-q[0]-l:(k[0]-q[0])/2),s(r.y===I?k[1]-q[1]:0)]:[s(r.x===J?k[0]-q[0]:0),s(r.y===I?l:r.y===K?k[1]-q[1]-l:(k[1]-q[1])/2)],qa?(g=o[0].getContext("2d"),g.restore(),g.save(),g.clearRect(0,0,6e3,6e3),h=this._calculateTip(r,q,ha),i=this._calculateTip(r,this.size,ha),o.attr(G,k[0]*ha).attr(H,k[1]*ha),o.css(G,k[0]).css(H,k[1]),this._drawCoords(g,i),g.fillStyle=e[1],g.fill(),g.translate(j[0]*ha,j[1]*ha),this._drawCoords(g,h),g.fillStyle=e[0],g.fill()):(h=this._calculateTip(r),h="m"+h[0]+","+h[1]+" l"+h[2]+","+h[3]+" "+h[4]+","+h[5]+" xe",j[2]=l&&/^(r|b)/i.test(b.string())?8===ba.ie?2:1:0,o.css({coordsize:k[0]+l+" "+k[1]+l,antialias:""+(r.string().indexOf(M)>-1),left:j[0]-j[2]*Number(f===E),top:j[1]-j[2]*Number(f===F),width:k[0]+l,height:k[1]+l}).each(function(a){var b=d(this);b[b.prop?"prop":"attr"]({coordsize:k[0]+l+" "+k[1]+l,path:h,fillcolor:e[0],filled:!!a,stroked:!a}).toggle(!(!l&&!a)),!a&&b.html(ga("stroke",'weight="'+2*l+'px" color="'+e[1]+'" miterlimit="1000" joinstyle="miter"'))})),a.opera&&setTimeout(function(){m.tip.css({display:"inline-block",visibility:"visible"})},1),c!==C&&this.calculate(b,k)},calculate:function(a,b){if(!this.enabled)return C;var c,e,f=this,g=this.qtip.elements,h=this.element,i=this.options.offset,j={};return a=a||this.corner,c=a.precedance,b=b||this._calculateSize(a),e=[a.x,a.y],c===E&&e.reverse(),d.each(e,function(d,e){var h,k,l;e===M?(h=c===F?J:I,j[h]="50%",j[ka+"-"+h]=-Math.round(b[c===F?0:1]/2)+i):(h=f._parseWidth(a,e,g.tooltip),k=f._parseWidth(a,e,g.content),l=f._parseRadius(a),j[e]=Math.max(-f.border,d?k:i+(l>h?l:-h)))}),j[a[c]]-=b[c===E?0:1],h.css({margin:"",top:"",bottom:"",left:"",right:""}).css(j),j},reposition:function(a,b,d){function e(a,b,c,d,e){a===O&&j.precedance===b&&k[d]&&j[c]!==M?j.precedance=j.precedance===E?F:E:a!==O&&k[d]&&(j[b]=j[b]===M?k[d]>0?d:e:j[b]===d?e:d)}function f(a,b,e){j[a]===M?p[ka+"-"+b]=o[a]=g[ka+"-"+b]-k[b]:(h=g[e]!==c?[k[b],-g[b]]:[-k[b],g[b]],(o[a]=Math.max(h[0],h[1]))>h[0]&&(d[b]-=k[b],o[b]=C),p[g[e]!==c?e:b]=o[a])}if(this.enabled){var g,h,i=b.cache,j=this.corner.clone(),k=d.adjusted,l=b.options.position.adjust.method.split(" "),m=l[0],n=l[1]||l[0],o={left:C,top:C,x:0,y:0},p={};this.corner.fixed!==B&&(e(m,E,F,J,L),e(n,F,E,I,K),j.string()===i.corner.string()&&i.cornerTop===k.top&&i.cornerLeft===k.left||this.update(j,C)),g=this.calculate(j),g.right!==c&&(g.left=-g.right),g.bottom!==c&&(g.top=-g.bottom),g.user=this.offset,o.left=m===O&&!!k.left,o.left&&f(E,J,L),o.top=n===O&&!!k.top,o.top&&f(F,I,K),this.element.css(p).toggle(!(o.x&&o.y||j.x===M&&o.y||j.y===M&&o.x)),d.left-=g.left.charAt?g.user:m!==O||o.top||!o.left&&!o.top?g.left+this.border:0,d.top-=g.top.charAt?g.user:n!==O||o.left||!o.left&&!o.top?g.top+this.border:0,i.cornerLeft=k.left,i.cornerTop=k.top,i.corner=j.clone()}},destroy:function(){this.qtip._unbind(this.qtip.tooltip,this._ns),this.qtip.elements.tip&&this.qtip.elements.tip.find("*").remove().end().remove()}}),fa=P.tip=function(a){return new v(a,a.options.style.tip)},fa.initialize="render",fa.sanitize=function(a){if(a.style&&"tip"in a.style){var b=a.style.tip;"object"!=typeof b&&(b=a.style.tip={corner:b}),/string|boolean/i.test(typeof b.corner)||(b.corner=B)}},z.tip={"^position.my|style.tip.(corner|mimic|border)$":function(){this.create(),this.qtip.reposition()},"^style.tip.(height|width)$":function(a){this.size=[a.width,a.height],this.update(),this.qtip.reposition()},"^content.title|style.(classes|widget)$":function(){this.update()}},d.extend(B,w.defaults,{style:{tip:{corner:B,mimic:C,width:6,height:6,border:B,offset:0}}}),P.viewport=function(c,d,e,f,g,h,i){function j(a,b,c,e,f,g,h,i,j){var k=d[f],s=u[a],t=v[a],w=c===O,x=s===f?j:s===g?-j:-j/2,y=t===f?i:t===g?-i:-i/2,z=q[f]+r[f]-(n?0:m[f]),A=z-k,B=k+j-(h===G?o:p)-z,C=x-(u.precedance===a||s===u[b]?y:0)-(t===M?i/2:0);return w?(C=(s===f?1:-1)*x,d[f]+=A>0?A:B>0?-B:0,d[f]=Math.max(-m[f]+r[f],k-C,Math.min(Math.max(-m[f]+r[f]+(h===G?o:p),k+C),d[f],"center"===s?k-x:1e9))):(e*=c===N?2:0,A>0&&(s!==f||B>0)?(d[f]-=C+e,l.invert(a,f)):B>0&&(s!==g||A>0)&&(d[f]-=(s===M?-C:C)+e,l.invert(a,g)),d[f]<q[f]&&-d[f]>B&&(d[f]=k,l=u.clone())),d[f]-k}var k,l,m,n,o,p,q,r,s=e.target,t=c.elements.tooltip,u=e.my,v=e.at,w=e.adjust,x=w.method.split(" "),y=x[0],z=x[1]||x[0],A=e.viewport,B=e.container,D={left:0,top:0};return A.jquery&&s[0]!==a&&s[0]!==b.body&&"none"!==w.method?(m=B.offset()||D,n="static"===B.css("position"),k="fixed"===t.css("position"),o=A[0]===a?A.width():A.outerWidth(C),p=A[0]===a?A.height():A.outerHeight(C),q={left:k?0:A.scrollLeft(),top:k?0:A.scrollTop()},r=A.offset()||D,"shift"===y&&"shift"===z||(l=u.clone()),D={left:"none"!==y?j(E,F,y,w.x,J,L,G,f,h):0,top:"none"!==z?j(F,E,z,w.y,I,K,H,g,i):0,my:l}):D}})}(window,document);

/* bootstrap-switch v3.3.4 | https://bootstrapswitch.com/ | Copyright (c) 2012-2017 Mattia Larentis & Emanuele Marchi. Released under the Apache 2.0 license. */
(function(a,b){if('function'==typeof define&&define.amd)define(['jquery'],b);else if('undefined'!=typeof exports)b(require('jquery'));else{b(a.jquery),a.bootstrapSwitch={exports:{}}.exports}})(this,function(a){'use strict';function c(j,k){if(!(j instanceof k))throw new TypeError('Cannot call a class as a function')}var d=function(j){return j&&j.__esModule?j:{default:j}}(a),e=Object.assign||function(j){for(var l,k=1;k<arguments.length;k++)for(var m in l=arguments[k],l)Object.prototype.hasOwnProperty.call(l,m)&&(j[m]=l[m]);return j},f=function(){function j(k,l){for(var n,m=0;m<l.length;m++)n=l[m],n.enumerable=n.enumerable||!1,n.configurable=!0,'value'in n&&(n.writable=!0),Object.defineProperty(k,n.key,n)}return function(k,l,m){return l&&j(k.prototype,l),m&&j(k,m),k}}(),g=d.default||window.jQuery||window.$,h=function(){function j(k){var l=this,m=1<arguments.length&&void 0!==arguments[1]?arguments[1]:{};c(this,j),this.$element=g(k),this.options=g.extend({},g.fn.bootstrapSwitch.defaults,this._getElementOptions(),m),this.prevOptions={},this.$wrapper=g('<div>',{class:function(){var o=[];return o.push(l.options.state?'on':'off'),l.options.size&&o.push(l.options.size),l.options.disabled&&o.push('disabled'),l.options.readonly&&o.push('readonly'),l.options.indeterminate&&o.push('indeterminate'),l.options.inverse&&o.push('inverse'),l.$element.attr('id')&&o.push('id-'+l.$element.attr('id')),o.map(l._getClass.bind(l)).concat([l.options.baseClass],l._getClasses(l.options.wrapperClass)).join(' ')}}),this.$container=g('<div>',{class:this._getClass('container')}),this.$on=g('<span>',{html:this.options.onText,class:this._getClass('handle-on')+' '+this._getClass(this.options.onColor)}),this.$off=g('<span>',{html:this.options.offText,class:this._getClass('handle-off')+' '+this._getClass(this.options.offColor)}),this.$label=g('<span>',{html:this.options.labelText,class:this._getClass('label')}),this.$element.on('init.bootstrapSwitch',this.options.onInit.bind(this,k)),this.$element.on('switchChange.bootstrapSwitch',function(){for(var n=arguments.length,o=Array(n),p=0;p<n;p++)o[p]=arguments[p];!1===l.options.onSwitchChange.apply(k,o)&&(l.$element.is(':radio')?g('[name="'+l.$element.attr('name')+'"]').trigger('previousState.bootstrapSwitch',!0):l.$element.trigger('previousState.bootstrapSwitch',!0))}),this.$container=this.$element.wrap(this.$container).parent(),this.$wrapper=this.$container.wrap(this.$wrapper).parent(),this.$element.before(this.options.inverse?this.$off:this.$on).before(this.$label).before(this.options.inverse?this.$on:this.$off),this.options.indeterminate&&this.$element.prop('indeterminate',!0),this._init(),this._elementHandlers(),this._handleHandlers(),this._labelHandlers(),this._formHandler(),this._externalLabelHandler(),this.$element.trigger('init.bootstrapSwitch',this.options.state)}return f(j,[{key:'setPrevOptions',value:function(){this.prevOptions=e({},this.options)}},{key:'state',value:function(l,m){return'undefined'==typeof l?this.options.state:this.options.disabled||this.options.readonly||this.options.state&&!this.options.radioAllOff&&this.$element.is(':radio')?this.$element:(this.$element.is(':radio')?g('[name="'+this.$element.attr('name')+'"]').trigger('setPreviousOptions.bootstrapSwitch'):this.$element.trigger('setPreviousOptions.bootstrapSwitch'),this.options.indeterminate&&this.indeterminate(!1),this.$element.prop('checked',!!l).trigger('change.bootstrapSwitch',m),this.$element)}},{key:'toggleState',value:function(l){return this.options.disabled||this.options.readonly?this.$element:this.options.indeterminate?(this.indeterminate(!1),this.state(!0)):this.$element.prop('checked',!this.options.state).trigger('change.bootstrapSwitch',l)}},{key:'size',value:function(l){return'undefined'==typeof l?this.options.size:(null!=this.options.size&&this.$wrapper.removeClass(this._getClass(this.options.size)),l&&this.$wrapper.addClass(this._getClass(l)),this._width(),this._containerPosition(),this.options.size=l,this.$element)}},{key:'animate',value:function(l){return'undefined'==typeof l?this.options.animate:this.options.animate===!!l?this.$element:this.toggleAnimate()}},{key:'toggleAnimate',value:function(){return this.options.animate=!this.options.animate,this.$wrapper.toggleClass(this._getClass('animate')),this.$element}},{key:'disabled',value:function(l){return'undefined'==typeof l?this.options.disabled:this.options.disabled===!!l?this.$element:this.toggleDisabled()}},{key:'toggleDisabled',value:function(){return this.options.disabled=!this.options.disabled,this.$element.prop('disabled',this.options.disabled),this.$wrapper.toggleClass(this._getClass('disabled')),this.$element}},{key:'readonly',value:function(l){return'undefined'==typeof l?this.options.readonly:this.options.readonly===!!l?this.$element:this.toggleReadonly()}},{key:'toggleReadonly',value:function(){return this.options.readonly=!this.options.readonly,this.$element.prop('readonly',this.options.readonly),this.$wrapper.toggleClass(this._getClass('readonly')),this.$element}},{key:'indeterminate',value:function(l){return'undefined'==typeof l?this.options.indeterminate:this.options.indeterminate===!!l?this.$element:this.toggleIndeterminate()}},{key:'toggleIndeterminate',value:function(){return this.options.indeterminate=!this.options.indeterminate,this.$element.prop('indeterminate',this.options.indeterminate),this.$wrapper.toggleClass(this._getClass('indeterminate')),this._containerPosition(),this.$element}},{key:'inverse',value:function(l){return'undefined'==typeof l?this.options.inverse:this.options.inverse===!!l?this.$element:this.toggleInverse()}},{key:'toggleInverse',value:function(){this.$wrapper.toggleClass(this._getClass('inverse'));var l=this.$on.clone(!0),m=this.$off.clone(!0);return this.$on.replaceWith(m),this.$off.replaceWith(l),this.$on=m,this.$off=l,this.options.inverse=!this.options.inverse,this.$element}},{key:'onColor',value:function(l){return'undefined'==typeof l?this.options.onColor:(this.options.onColor&&this.$on.removeClass(this._getClass(this.options.onColor)),this.$on.addClass(this._getClass(l)),this.options.onColor=l,this.$element)}},{key:'offColor',value:function(l){return'undefined'==typeof l?this.options.offColor:(this.options.offColor&&this.$off.removeClass(this._getClass(this.options.offColor)),this.$off.addClass(this._getClass(l)),this.options.offColor=l,this.$element)}},{key:'onText',value:function(l){return'undefined'==typeof l?this.options.onText:(this.$on.html(l),this._width(),this._containerPosition(),this.options.onText=l,this.$element)}},{key:'offText',value:function(l){return'undefined'==typeof l?this.options.offText:(this.$off.html(l),this._width(),this._containerPosition(),this.options.offText=l,this.$element)}},{key:'labelText',value:function(l){return'undefined'==typeof l?this.options.labelText:(this.$label.html(l),this._width(),this.options.labelText=l,this.$element)}},{key:'handleWidth',value:function(l){return'undefined'==typeof l?this.options.handleWidth:(this.options.handleWidth=l,this._width(),this._containerPosition(),this.$element)}},{key:'labelWidth',value:function(l){return'undefined'==typeof l?this.options.labelWidth:(this.options.labelWidth=l,this._width(),this._containerPosition(),this.$element)}},{key:'baseClass',value:function(){return this.options.baseClass}},{key:'wrapperClass',value:function(l){return'undefined'==typeof l?this.options.wrapperClass:(l||(l=g.fn.bootstrapSwitch.defaults.wrapperClass),this.$wrapper.removeClass(this._getClasses(this.options.wrapperClass).join(' ')),this.$wrapper.addClass(this._getClasses(l).join(' ')),this.options.wrapperClass=l,this.$element)}},{key:'radioAllOff',value:function(l){if('undefined'==typeof l)return this.options.radioAllOff;var m=!!l;return this.options.radioAllOff===m?this.$element:(this.options.radioAllOff=m,this.$element)}},{key:'onInit',value:function(l){return'undefined'==typeof l?this.options.onInit:(l||(l=g.fn.bootstrapSwitch.defaults.onInit),this.options.onInit=l,this.$element)}},{key:'onSwitchChange',value:function(l){return'undefined'==typeof l?this.options.onSwitchChange:(l||(l=g.fn.bootstrapSwitch.defaults.onSwitchChange),this.options.onSwitchChange=l,this.$element)}},{key:'destroy',value:function(){var l=this.$element.closest('form');return l.length&&l.off('reset.bootstrapSwitch').removeData('bootstrap-switch'),this.$container.children().not(this.$element).remove(),this.$element.unwrap().unwrap().off('.bootstrapSwitch').removeData('bootstrap-switch'),this.$element}},{key:'_getElementOptions',value:function(){return{state:this.$element.is(':checked'),size:this.$element.data('size'),animate:this.$element.data('animate'),disabled:this.$element.is(':disabled'),readonly:this.$element.is('[readonly]'),indeterminate:this.$element.data('indeterminate'),inverse:this.$element.data('inverse'),radioAllOff:this.$element.data('radio-all-off'),onColor:this.$element.data('on-color'),offColor:this.$element.data('off-color'),onText:this.$element.data('on-text'),offText:this.$element.data('off-text'),labelText:this.$element.data('label-text'),handleWidth:this.$element.data('handle-width'),labelWidth:this.$element.data('label-width'),baseClass:this.$element.data('base-class'),wrapperClass:this.$element.data('wrapper-class')}}},{key:'_width',value:function(){var l=this,m=this.$on.add(this.$off).add(this.$label).css('width',''),n='auto'===this.options.handleWidth?Math.round(Math.max(this.$on.width(),this.$off.width())):this.options.handleWidth;return m.width(n),this.$label.width(function(o,p){return'auto'===l.options.labelWidth?p<n?n:p:l.options.labelWidth}),this._handleWidth=this.$on.outerWidth(),this._labelWidth=this.$label.outerWidth(),this.$container.width(2*this._handleWidth+this._labelWidth),this.$wrapper.width(this._handleWidth+this._labelWidth)}},{key:'_containerPosition',value:function(){var l=this,m=0<arguments.length&&void 0!==arguments[0]?arguments[0]:this.options.state,n=arguments[1];this.$container.css('margin-left',function(){var o=[0,'-'+l._handleWidth+'px'];return l.options.indeterminate?'-'+l._handleWidth/2+'px':m?l.options.inverse?o[1]:o[0]:l.options.inverse?o[0]:o[1]})}},{key:'_init',value:function(){var l=this,m=function(){l.setPrevOptions(),l._width(),l._containerPosition(),setTimeout(function(){if(l.options.animate)return l.$wrapper.addClass(l._getClass('animate'))},50)};if(this.$wrapper.is(':visible'))return void m();var n=window.setInterval(function(){if(l.$wrapper.is(':visible'))return m(),window.clearInterval(n)},50)}},{key:'_elementHandlers',value:function(){var l=this;return this.$element.on({'setPreviousOptions.bootstrapSwitch':this.setPrevOptions.bind(this),'previousState.bootstrapSwitch':function(){l.options=l.prevOptions,l.options.indeterminate&&l.$wrapper.addClass(l._getClass('indeterminate')),l.$element.prop('checked',l.options.state).trigger('change.bootstrapSwitch',!0)},'change.bootstrapSwitch':function(n,o){n.preventDefault(),n.stopImmediatePropagation();var p=l.$element.is(':checked');l._containerPosition(p),p===l.options.state||(l.options.state=p,l.$wrapper.toggleClass(l._getClass('off')).toggleClass(l._getClass('on')),!o&&(l.$element.is(':radio')&&g('[name="'+l.$element.attr('name')+'"]').not(l.$element).prop('checked',!1).trigger('change.bootstrapSwitch',!0),l.$element.trigger('switchChange.bootstrapSwitch',[p])))},'focus.bootstrapSwitch':function(n){n.preventDefault(),l.$wrapper.addClass(l._getClass('focused'))},'blur.bootstrapSwitch':function(n){n.preventDefault(),l.$wrapper.removeClass(l._getClass('focused'))},'keydown.bootstrapSwitch':function(n){!n.which||l.options.disabled||l.options.readonly||(37===n.which||39===n.which)&&(n.preventDefault(),n.stopImmediatePropagation(),l.state(39===n.which))}})}},{key:'_handleHandlers',value:function(){var l=this;return this.$on.on('click.bootstrapSwitch',function(m){return m.preventDefault(),m.stopPropagation(),l.state(!1),l.$element.trigger('focus.bootstrapSwitch')}),this.$off.on('click.bootstrapSwitch',function(m){return m.preventDefault(),m.stopPropagation(),l.state(!0),l.$element.trigger('focus.bootstrapSwitch')})}},{key:'_labelHandlers',value:function(){var l=this;this.$label.on({click:function(o){o.stopPropagation()},'mousedown.bootstrapSwitch touchstart.bootstrapSwitch':function(o){l._dragStart||l.options.disabled||l.options.readonly||(o.preventDefault(),o.stopPropagation(),l._dragStart=(o.pageX||o.originalEvent.touches[0].pageX)-parseInt(l.$container.css('margin-left'),10),l.options.animate&&l.$wrapper.removeClass(l._getClass('animate')),l.$element.trigger('focus.bootstrapSwitch'))},'mousemove.bootstrapSwitch touchmove.bootstrapSwitch':function(o){if(null!=l._dragStart){var p=(o.pageX||o.originalEvent.touches[0].pageX)-l._dragStart;o.preventDefault(),p<-l._handleWidth||0<p||(l._dragEnd=p,l.$container.css('margin-left',l._dragEnd+'px'))}},'mouseup.bootstrapSwitch touchend.bootstrapSwitch':function(o){if(l._dragStart){if(o.preventDefault(),l.options.animate&&l.$wrapper.addClass(l._getClass('animate')),l._dragEnd){var p=l._dragEnd>-(l._handleWidth/2);l._dragEnd=!1,l.state(l.options.inverse?!p:p)}else l.state(!l.options.state);l._dragStart=!1}},'mouseleave.bootstrapSwitch':function(){l.$label.trigger('mouseup.bootstrapSwitch')}})}},{key:'_externalLabelHandler',value:function(){var l=this,m=this.$element.closest('label');m.on('click',function(n){n.preventDefault(),n.stopImmediatePropagation(),n.target===m[0]&&l.toggleState()})}},{key:'_formHandler',value:function(){var l=this.$element.closest('form');l.data('bootstrap-switch')||l.on('reset.bootstrapSwitch',function(){window.setTimeout(function(){l.find('input').filter(function(){return g(this).data('bootstrap-switch')}).each(function(){return g(this).bootstrapSwitch('state',this.checked)})},1)}).data('bootstrap-switch',!0)}},{key:'_getClass',value:function(l){return this.options.baseClass+'-'+l}},{key:'_getClasses',value:function(l){return g.isArray(l)?l.map(this._getClass.bind(this)):[this._getClass(l)]}}]),j}();g.fn.bootstrapSwitch=function(j){for(var l=arguments.length,m=Array(1<l?l-1:0),n=1;n<l;n++)m[n-1]=arguments[n];return Array.prototype.reduce.call(this,function(o,p){var q=g(p),r=q.data('bootstrap-switch'),s=r||new h(p,j);return r||q.data('bootstrap-switch',s),'string'==typeof j?s[j].apply(s,m):o},this)},g.fn.bootstrapSwitch.Constructor=h,g.fn.bootstrapSwitch.defaults={state:!0,size:null,animate:!0,disabled:!1,readonly:!1,indeterminate:!1,inverse:!1,radioAllOff:!1,onColor:'primary',offColor:'default',onText:'ON',offText:'OFF',labelText:'&nbsp',handleWidth:'auto',labelWidth:'auto',baseClass:'bootstrap-switch',wrapperClass:'wrapper',onInit:function(){},onSwitchChange:function(){}}});

/* caret v1.3.3 | https://github.com/accursoft/caret | Copyright (c) 2009 Gideon Sireling. Released under the BSD3 license. */
!function(e){e.fn.caret=function(e){var t=this[0],n="true"===t.contentEditable;if(0==arguments.length){if(window.getSelection){if(n){t.focus();var o=window.getSelection().getRangeAt(0),r=o.cloneRange();return r.selectNodeContents(t),r.setEnd(o.endContainer,o.endOffset),r.toString().length}return t.selectionStart}if(document.selection){if(t.focus(),n){var o=document.selection.createRange(),r=document.body.createTextRange();return r.moveToElementText(t),r.setEndPoint("EndToEnd",o),r.text.length}var e=0,c=t.createTextRange(),r=document.selection.createRange().duplicate(),a=r.getBookmark();for(c.moveToBookmark(a);0!==c.moveStart("character",-1);)e++;return e}return t.selectionStart?t.selectionStart:0}if(-1==e&&(e=this[n?"text":"val"]().length),window.getSelection)n?(t.focus(),window.getSelection().collapse(t.firstChild,e)):t.setSelectionRange(e,e);else if(document.body.createTextRange)if(n){var c=document.body.createTextRange();c.moveToElementText(t),c.moveStart("character",e),c.collapse(!0),c.select()}else{var c=t.createTextRange();c.move("character",e),c.select()}return n||t.focus(),e}}(jQuery);

/* jQuery tagEditor v1.0.21 | https://github.com/Pixabay/jQuery-tagEditor | Copyright (c) 2013 Pixabay. Licensed under the MIT license. */
!function(t){t.fn.tagEditorInput=function(){var e=" ",i=t(this),a=parseInt(i.css("fontSize")),r=t("<span/>").css({position:"absolute",top:-9999,left:-9999,width:"auto",fontSize:i.css("fontSize"),fontFamily:i.css("fontFamily"),fontWeight:i.css("fontWeight"),letterSpacing:i.css("letterSpacing"),whiteSpace:"nowrap"}),l=function(){if(e!==(e=i.val())){r.text(e);var t=r.width()+a;20>t&&(t=20),t!=i.width()&&i.width(t)}};return r.insertAfter(i),i.bind("keyup keydown focus",l)},t.fn.tagEditor=function(e,a,r){function l(t){return t.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#39;")}var n,o=t.extend({},t.fn.tagEditor.defaults,e),c=this;if(o.dregex=new RegExp("["+o.delimiter.replace("-","-")+"]","g"),"string"==typeof e){var s=[];return c.each(function(){var i=t(this),l=i.data("options"),n=i.next(".tag-editor");if("getTags"==e)s.push({field:i[0],editor:n,tags:n.data("tags")});else if("addTag"==e){if(l.maxTags&&n.data("tags").length>=l.maxTags)return!1;t('<li><div class="tag-editor-spacer">&nbsp;'+l.delimiter[0]+'</div><div class="tag-editor-tag"></div><div class="tag-editor-delete"><i></i></div></li>').appendTo(n).find(".tag-editor-tag").html('<input type="text" maxlength="'+l.maxLength+'">').addClass("active").find("input").val(a).blur(),r?t(".placeholder",n).remove():n.click()}else"removeTag"==e?(t(".tag-editor-tag",n).filter(function(){return t(this).text()==a}).closest("li").find(".tag-editor-delete").click(),r||n.click()):"destroy"==e&&i.removeClass("tag-editor-hidden-src").removeData("options").off("focus.tag-editor").next(".tag-editor").remove()}),"getTags"==e?s:this}return window.getSelection&&t(document).off("keydown.tag-editor").on("keydown.tag-editor",function(e){if(8==e.which||46==e.which||e.ctrlKey&&88==e.which){try{var a=getSelection(),r="INPUT"!=document.activeElement.tagName?t(a.getRangeAt(0).startContainer.parentNode).closest(".tag-editor"):0}catch(e){r=0}if(a.rangeCount>0&&r&&r.length){var l=[],n=a.toString().split(r.prev().data("options").dregex);for(i=0;i<n.length;i++){var o=t.trim(n[i]);o&&l.push(o)}return t(".tag-editor-tag",r).each(function(){~t.inArray(t(this).text(),l)&&t(this).closest("li").find(".tag-editor-delete").click()}),!1}}}),c.each(function(){function e(){!o.placeholder||c.length||t(".deleted, .placeholder, input",s).length||s.append('<li class="placeholder"><div>'+o.placeholder+"</div></li>")}function i(i){var a=c.toString();c=t(".tag-editor-tag:not(.deleted)",s).map(function(e,i){var a=t.trim(t(this).hasClass("active")?t(this).find("input").val():t(i).text());return a?a:void 0}).get(),s.data("tags",c),r.val(c.join(o.delimiter[0])),i||a!=c.toString()&&o.onChange(r,s,c),e()}function a(e){for(var a,n=e.closest("li"),d=e.val().replace(/ +/," ").split(o.dregex),g=e.data("old_tag"),f=c.slice(0),h=!1,u=0;u<d.length;u++)if(v=t.trim(d[u]).slice(0,o.maxLength),o.forceLowercase&&(v=v.toLowerCase()),a=o.beforeTagSave(r,s,f,g,v),v=a||v,a!==!1&&v&&(o.removeDuplicates&&~t.inArray(v,f)&&t(".tag-editor-tag",s).each(function(){t(this).text()==v&&t(this).closest("li").remove()}),f.push(v),n.before('<li><div class="tag-editor-spacer">&nbsp;'+o.delimiter[0]+'</div><div class="tag-editor-tag">'+l(v)+'</div><div class="tag-editor-delete"><i></i></div></li>'),o.maxTags&&f.length>=o.maxTags)){h=!0;break}e.attr("maxlength",o.maxLength).removeData("old_tag").val(""),h?e.blur():e.focus(),i()}var r=t(this),c=[],s=t("<ul "+(o.clickDelete?'oncontextmenu="return false;" ':"")+'class="tag-editor"></ul>').insertAfter(r);r.addClass("tag-editor-hidden-src").data("options",o).on("focus.tag-editor",function(){s.click()}),s.append('<li style="width:1px">&nbsp;</li>');var d='<li><div class="tag-editor-spacer">&nbsp;'+o.delimiter[0]+'</div><div class="tag-editor-tag"></div><div class="tag-editor-delete"><i></i></div></li>';s.click(function(e,i){var a,r,l=99999;if(!window.getSelection||""==getSelection())return o.maxTags&&s.data("tags").length>=o.maxTags?(s.find("input").blur(),!1):(n=!0,t("input:focus",s).blur(),n?(n=!0,t(".placeholder",s).remove(),i&&i.length?r="before":t(".tag-editor-tag",s).each(function(){var n=t(this),o=n.offset(),c=o.left,s=o.top;e.pageY>=s&&e.pageY<=s+n.height()&&(e.pageX<c?(r="before",a=c-e.pageX):(r="after",a=e.pageX-c-n.width()),l>a&&(l=a,i=n))}),"before"==r?t(d).insertBefore(i.closest("li")).find(".tag-editor-tag").click():"after"==r?t(d).insertAfter(i.closest("li")).find(".tag-editor-tag").click():t(d).appendTo(s).find(".tag-editor-tag").click(),!1):!1)}),s.on("click",".tag-editor-delete",function(){if(t(this).prev().hasClass("active"))return t(this).closest("li").find("input").caret(-1),!1;var a=t(this).closest("li"),l=a.find(".tag-editor-tag");return o.beforeTagDelete(r,s,c,l.text())===!1?!1:(l.addClass("deleted").animate({width:0},o.animateDelete,function(){a.remove(),e()}),i(),!1)}),o.clickDelete&&s.on("mousedown",".tag-editor-tag",function(a){if(a.ctrlKey||a.which>1){var l=t(this).closest("li"),n=l.find(".tag-editor-tag");return o.beforeTagDelete(r,s,c,n.text())===!1?!1:(n.addClass("deleted").animate({width:0},o.animateDelete,function(){l.remove(),e()}),i(),!1)}}),s.on("click",".tag-editor-tag",function(e){if(o.clickDelete&&(e.ctrlKey||e.which>1))return!1;if(!t(this).hasClass("active")){var i=t(this).text(),a=Math.abs((t(this).offset().left-e.pageX)/t(this).width()),r=parseInt(i.length*a),n=t(this).html('<input type="text" maxlength="'+o.maxLength+'" value="'+l(i)+'">').addClass("active").find("input");if(n.data("old_tag",i).tagEditorInput().focus().caret(r),o.autocomplete){var c=t.extend({},o.autocomplete),d="select"in c?o.autocomplete.select:"";c.select=function(e,i){d&&d(e,i),setTimeout(function(){s.trigger("click",[t(".active",s).find("input").closest("li").next("li").find(".tag-editor-tag")])},20)},n.autocomplete(c)}}return!1}),s.on("blur","input",function(d){d.stopPropagation();var g=t(this),f=g.data("old_tag"),h=t.trim(g.val().replace(/ +/," ").replace(o.dregex,o.delimiter[0]));if(h){if(h.indexOf(o.delimiter[0])>=0)return void a(g);if(h!=f)if(o.forceLowercase&&(h=h.toLowerCase()),cb_val=o.beforeTagSave(r,s,c,f,h),h=cb_val||h,cb_val===!1){if(f)return g.val(f).focus(),n=!1,void i();try{g.closest("li").remove()}catch(d){}f&&i()}else o.removeDuplicates&&t(".tag-editor-tag:not(.active)",s).each(function(){t(this).text()==h&&t(this).closest("li").remove()})}else{if(f&&o.beforeTagDelete(r,s,c,f)===!1)return g.val(f).focus(),n=!1,void i();try{g.closest("li").remove()}catch(d){}f&&i()}g.parent().html(l(h)).removeClass("active"),h!=f&&i(),e()});var g;s.on("paste","input",function(){t(this).removeAttr("maxlength"),g=t(this),setTimeout(function(){a(g)},30)});var f;s.on("keypress","input",function(e){o.delimiter.indexOf(String.fromCharCode(e.which))>=0&&(f=t(this),setTimeout(function(){a(f)},20))}),s.on("keydown","input",function(e){var i=t(this);if((37==e.which||!o.autocomplete&&38==e.which)&&!i.caret()||8==e.which&&!i.val()){var a=i.closest("li").prev("li").find(".tag-editor-tag");return a.length?a.click().find("input").caret(-1):!i.val()||o.maxTags&&s.data("tags").length>=o.maxTags||t(d).insertBefore(i.closest("li")).find(".tag-editor-tag").click(),!1}if((39==e.which||!o.autocomplete&&40==e.which)&&i.caret()==i.val().length){var l=i.closest("li").next("li").find(".tag-editor-tag");return l.length?l.click().find("input").caret(0):i.val()&&s.click(),!1}if(9==e.which){if(e.shiftKey){var a=i.closest("li").prev("li").find(".tag-editor-tag");if(a.length)a.click().find("input").caret(0);else{if(!i.val()||o.maxTags&&s.data("tags").length>=o.maxTags)return r.attr("disabled","disabled"),void setTimeout(function(){r.removeAttr("disabled")},30);t(d).insertBefore(i.closest("li")).find(".tag-editor-tag").click()}return!1}var l=i.closest("li").next("li").find(".tag-editor-tag");if(l.length)l.click().find("input").caret(0);else{if(!i.val())return;s.click()}return!1}if(!(46!=e.which||t.trim(i.val())&&i.caret()!=i.val().length)){var l=i.closest("li").next("li").find(".tag-editor-tag");return l.length?l.click().find("input").caret(0):i.val()&&s.click(),!1}if(13==e.which)return s.trigger("click",[i.closest("li").next("li").find(".tag-editor-tag")]),o.maxTags&&s.data("tags").length>=o.maxTags&&s.find("input").blur(),!1;if(36!=e.which||i.caret()){if(35==e.which&&i.caret()==i.val().length)s.find(".tag-editor-tag").last().click();else if(27==e.which)return i.val(i.data("old_tag")?i.data("old_tag"):"").blur(),!1}else s.find(".tag-editor-tag").first().click()});for(var h=o.initialTags.length?o.initialTags:r.val().split(o.dregex),u=0;u<h.length&&!(o.maxTags&&u>=o.maxTags);u++){var v=t.trim(h[u].replace(/ +/," "));v&&(o.forceLowercase&&(v=v.toLowerCase()),c.push(v),s.append('<li><div class="tag-editor-spacer">&nbsp;'+o.delimiter[0]+'</div><div class="tag-editor-tag">'+l(v)+'</div><div class="tag-editor-delete"><i></i></div></li>'))}i(!0),o.sortable&&t.fn.sortable&&s.sortable({distance:5,cancel:".tag-editor-spacer, input",helper:"clone",update:function(){i()}})})},t.fn.tagEditor.defaults={initialTags:[],maxTags:0,maxLength:50,delimiter:",;",placeholder:"",forceLowercase:!0,removeDuplicates:!0,clickDelete:!1,animateDelete:175,sortable:!0,autocomplete:null,onChange:function(){},beforeTagSave:function(){},beforeTagDelete:function(){}}}(jQuery);