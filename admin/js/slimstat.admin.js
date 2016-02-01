if (typeof SlimStatAdminParams == 'undefined') SlimStatAdminParams = {refresh_interval: 0, expand_details: 'no', datepicker_image: '', text_direction: '', use_slimscroll: 'yes', chart_colors: ['#ccc', '#999', '#bbcc44', '#21759b']};
var SlimStatAdmin = {
	// Public variables
	chart_data: [],
	chart_info: [],
	chart_id: 0,
	ticks: [],

	// Private variables
	_chart_options: {
		grid: {
			backgroundColor: '#ffffff',
			borderWidth: 0,
			hoverable: true,
			clickable: true
		},
		legend: {
			container: '',
			noColumns: 4
		},
		pan: { interactive: true },
		series: {
			lines: { show: true },
			colors: [ { opacity: 0.85 } ],
			shadowSize: 5
		},
		shadowSize: 0,
		zoom: { interactive: true }
	},
	_placeholder: null,
	_chart_id: 0,
	_qtip_previous_point: null,
	_refresh_timer: [0, 0],
	_tooltip: {},

	chart_color_weekends: function(){
		if (!SlimStatAdmin.chart_info.daily_chart){
			return true;
		}

		jQuery(".xAxis .tickLabel").each(function(i){
			myDate = new Date(SlimStatAdmin.chart_info.current_year, SlimStatAdmin.chart_info.current_month-1, parseInt(jQuery(this).html()), 3, 30, 0);
			if(myDate.getDay()%6 == 0){
				jQuery(this).css('color','#ccc');
			}
		});
	},

	chart_init: function() {
		jQuery(".chart-placeholder").each( function() {
			SlimStatAdmin._placeholder = jQuery(this);
			SlimStatAdmin._chart_id = jQuery(this).parents('.postbox').attr('id');
			SlimStatAdmin._chart_options.legend.container = '#' + SlimStatAdmin._chart_id + ' .chart-legend';

			// Don't do anything if no placeholder or if hidden
			if (!SlimStatAdmin._placeholder.length || SlimStatAdmin._placeholder.is(':hidden')){
				return true;
			}

			max_y_axis = 0;
			for (i in SlimStatAdmin.chart_data[ SlimStatAdmin._chart_id ]){
				max = SlimStatAdmin.chart_data[ SlimStatAdmin._chart_id ][ i ].data.reduce( function( max, arr ) { 
					return Math.max(max, arr[1]); 
				}, -Infinity)+1;
				if (max > max_y_axis) max_y_axis = max;
			}

			// Calculate the remaining options
			SlimStatAdmin._chart_options.colors = SlimStatAdminParams.chart_colors,

			SlimStatAdmin._chart_options.xaxis = {
				ticks: (SlimStatAdmin.ticks[0][1].indexOf('/') > 0 && SlimStatAdmin.ticks.length > 16) ? [] : SlimStatAdmin.ticks,
				tickDecimals: 0,
				tickLength: 0,
				tickSize: 1,
				panRange: [ 0, SlimStatAdmin.chart_data[ SlimStatAdmin._chart_id ][ 0 ].data.length - 1 ],
				zoomRange: [ 5, SlimStatAdmin.chart_data[ SlimStatAdmin._chart_id ][ 0 ].data.length - 1],
			};
			if (SlimStatAdminParams.text_direction == 'rtl'){
				SlimStatAdmin._chart_options.xaxis.transform = function(v) {
					return -v;
				};
				SlimStatAdmin._chart_options.xaxis.inverseTransform = function(v) {
					return -v;
				};
			}

			SlimStatAdmin._chart_options.yaxis = {
				tickDecimals: 0,
				zoomRange: [5, max_y_axis],
				panRange:[0, max_y_axis]
			};

			// Draw the chart
			jQuery.plot(SlimStatAdmin._placeholder, SlimStatAdmin.chart_data[ SlimStatAdmin._chart_id ], SlimStatAdmin._chart_options);
			SlimStatAdmin.chart_color_weekends();
			
			// Enable tooltips
			SlimStatAdmin._tooltip = SlimStatAdmin._placeholder.qtip({
				content: ' ',
				hide: {
					event: false,
					fixed: true
				},
				//id: 'chart-placeholder',
				position: {
					target: 'mouse',
					viewport: SlimStatAdmin._placeholder,
					adjust: {
						x: 15
					}
				},
				prerender: true,
				show: false,
				style: {
					classes: 'qtip-dark'
				}
			});
			SlimStatAdmin._placeholder.bind("plothover", function (event, coords, item) {
				// Grab the API reference
				var api = jQuery(this).qtip();

				// If we weren't passed the item object, hide the tooltip and remove cached point data
				if (!item) {
					api.cache.point = false;
					return api.hide(item);
				}

				SlimStatAdmin._previous_point = api.cache.point;
				if (SlimStatAdmin._previous_point !== item.dataIndex) {
					api.cache.point = item.dataIndex;

					label = item.series.label.replace(/[0-9\/\:]+(.*)(am|pm)?/gi, '');
					if (SlimStatAdmin.ticks[item.dataIndex][1].indexOf('/') > 0){
						label += ' ' + SlimStatAdmin.ticks[item.dataIndex][1];
					}
					api.set('content.text', label + ': ' + item.datapoint[1]);

					api.elements.tooltip.stop(1, 1);
					api.show(item);
				}
			});

			SlimStatAdmin._placeholder.bind('plotzoom', SlimStatAdmin.chart_color_weekends);
			SlimStatAdmin._placeholder.bind('plotpan', SlimStatAdmin.chart_color_weekends);
		});
	},

	parse_url_filters: function(report_id, href){
		filters_parsed = [];
		filters_to_add = href.split('&');
		jQuery('#slimstat-filters-form').attr('action', filters_to_add[0]);

		for (i in filters_to_add){
			if (filters_to_add[i].indexOf('fs\%5B') != 0) continue;
			
			filter_components = filters_to_add[i].split('=');
			filter_components[0] = decodeURIComponent(filter_components[0]);
			jQuery('input[name="'+filter_components[0]+'"]').remove();

			filters_parsed[filter_components[0]] = [report_id, filter_components[1].replace('+', ' ')];
		}
		return filters_parsed;
	},

	add_filters_to_form: function(filters_parsed, reset_existing_filters){
		if (reset_existing_filters){
			jQuery('.slimstat-post-filter').remove();
		}
		
		for (i in filters_parsed){
			if (jQuery.inArray(i, ['fs[minute]','fs[hour]','fs[day]','fs[month]','fs[year]','fs[interval_direction]','fs[interval]','fs[interval_hours]','fs[interval_minutes]']) == -1){
				jQuery('<input>').attr('type', 'hidden').attr('name', i).attr('class', 'slimstat-post-filter slimstat-temp-filter '+filters_parsed[i][0]).val(filters_parsed[i][1]).appendTo('#slimstat-filters-form');
			}
			else{
				field_id = i.replace('fs[', '#slimstat-filter-').replace(']', '');
				field_val = filters_parsed[i][1].split(' ');
				jQuery(field_id).val(field_val[1]);
			}
		}
	},
	
	remove_filters_from_form: function(filters_parsed){
		for (i in filters_parsed){
			jQuery('input[name="'+i.replace('[', '\\[').replace(']', '\\]')+'"]').remove();
		}
	},

	refresh_countdown: function(){
		SlimStatAdmin._refresh_timer[1]--;
		if (SlimStatAdmin._refresh_timer[1] == -1){
			SlimStatAdmin._refresh_timer[1] = 59;
			SlimStatAdmin._refresh_timer[0] = SlimStatAdmin._refresh_timer[0]-1;
		}
		jQuery('.refresh-timer').html(SlimStatAdmin._refresh_timer[0]+':'+((SlimStatAdmin._refresh_timer[1]<10)?'0':'')+SlimStatAdmin._refresh_timer[1]);
		if (SlimStatAdmin._refresh_timer[0] > 0 || SlimStatAdmin._refresh_timer[1] > 0){
			refresh_handle = window.setTimeout("SlimStatAdmin.refresh_countdown();", 1000);
		}
		else{
			report_id = 'slim_p7_02';
			data = {action: 'slimstat_load_report', report_id: report_id, security: jQuery('#meta-box-order-nonce').val(), page: SlimStatAdmin.get_query_string_value( 'page' ) };
			jQuery('#'+report_id+' .inside').html('<p class="loading"><i class="slimstat-font-spin4 animate-spin"></i></p>');
			SlimStatAdmin.refresh_report(report_id, data);

			window.clearTimeout(refresh_handle);
			SlimStatAdmin._refresh_timer[0] = parseInt(SlimStatAdminParams.refresh_interval/60);
			SlimStatAdmin._refresh_timer[1] = SlimStatAdminParams.refresh_interval%60;
			refresh_handle = window.setTimeout("SlimStatAdmin.refresh_countdown();", 1000);
		}
	},
	
	refresh_report: function(report_id, data){
		// Get the data from the hidden form
		filters_input = jQuery('#slimstat-filters-form .slimstat-post-filter').toArray();
		for (i in filters_input){
			data[filters_input[i]['name']] = filters_input[i]['value'];
		}

		jQuery('#'+report_id+' .inside').html('<p class="loading"><i class="slimstat-font-spin4 animate-spin"></i></p>');

		jQuery.post(ajaxurl, data, function(response){
			if (report_id.indexOf('_01') > 0){
				jQuery('#'+report_id + ' .inside').html(response);
				SlimStatAdmin.chart_init();
			}
			else{
				jQuery('#'+report_id + ' .inside').fadeOut(700, function(){
					jQuery(this).html(response).fadeIn(700);
				});
			}
		});

		// Remove filters set by other Ajax buttons
		jQuery('.slimstat-temp-filter').remove();
	},
	
	get_query_string_value: function( key ) {
		query_string = window.location.search.substring( 1 );
	 
		// Split into key/value pairs
		pairs = query_string.split("&");
	 
		// Convert the array of strings into an object
		for ( i in pairs ) {
			pair_array = pairs[i].split('=');
			if ( pair_array[ 0 ] == key ) {
				return pair_array[ 1 ];
			}
		}
	 
		return '';
	}
}

jQuery(function(){
	// Refresh page every X seconds
	if (SlimStatAdminParams.refresh_interval > 0 && !jQuery('[name^="fs\[is_past\]"]').length){
		SlimStatAdmin._refresh_timer[0] = parseInt(SlimStatAdminParams.refresh_interval/60);
		SlimStatAdmin._refresh_timer[1] = SlimStatAdminParams.refresh_interval%60;
		refresh_handle = window.setTimeout("SlimStatAdmin.refresh_countdown();", 1000);
	}

	// Refresh report if (re)activated via the checkbox under Screen Options
	jQuery( 'input.hide-postbox-tog[id^=slim_]' ).bind( 'click.postboxes', function () {
		if ( jQuery( this ).prop( "checked" ) && jQuery( '#' + jQuery( this ).val() ).length ) {
			var report_id = jQuery( this ).val();
			var data = { action: 'slimstat_load_report', report_id: report_id, security: jQuery( '#meta-box-order-nonce' ).val() }
			jQuery( '#' + report_id ).removeClass( 'hidden' );
			jQuery( '#' + report_id + ' .inside' ).html( '<p class="loading"></p>' );
			SlimStatAdmin.refresh_report( report_id, data );
		}
	});
	
	// Filters: add form if not available (Dashboard)
	if (!jQuery('#slimstat-filters-form').length){
		jQuery('<form id="slimstat-filters-form" method="post"/>').appendTo('body');
	}

	// Filters: Lock value input field based on operator drop down selection
	jQuery('#slimstat-filter-operator').change(function(){
		if (this.value=='is_empty'||this.value=='is_not_empty'){
			jQuery('#slimstat-filter-value').attr('readonly', 'readonly');
		}
		else{
			jQuery('#slimstat-filter-value').removeAttr('readonly');
		}
	});

	// Filters: empty on focus
	jQuery('.empty-on-focus').focus(function(){
		if (this.value == this.defaultValue) this.value = '';
	});
	jQuery('.empty-on-focus').blur(function(){
		if (this.value == '') this.value = this.defaultValue;
	});

	// Show/Hide Date Dropdown Filters
	jQuery('#slimstat-date-filters a').click(function(e){
		e.preventDefault();
		jQuery('#slimstat-date-filters span').slideToggle(300);
		jQuery(this).toggleClass('open');
	}).children().click(function(){
	  return false;
	});
	
	// Date Filters: Datepicker
	if (typeof jQuery('.slimstat-filter-date').datepicker == 'function'){
		jQuery('.slimstat-filter-date').datepicker({
			buttonImage: SlimStatAdminParams.datepicker_image,
			buttonImageOnly: true,
			changeMonth: true,
			changeYear: true,
			dateFormat: 'yy-m-d',
			maxDate: new Date,
			nextText: '&raquo;',
			prevText: '&laquo;',
			showOn: 'both',
			
			onClose: function(dateText, inst) {
				if (!dateText.length) return true;
				jQuery('#slimstat-filter-day').val( dateText.split('-')[2] );
				jQuery('#slimstat-filter-month').val( dateText.split('-')[1] );
				jQuery('#slimstat-filter-year').val( dateText.split('-')[0] );
			}
		});
	}

	// Filters: Remove selected when X is clicked
	jQuery('a.slimstat-remove-filter').click(function(e){
		e.preventDefault();

		filters_to_remove = SlimStatAdmin.parse_url_filters('p0', jQuery(this).attr('href'));
		SlimStatAdmin.remove_filters_from_form(filters_to_remove);

		jQuery('#slimstat-filters-form').submit();
		jQuery('.slimstat-temp-filter').remove();
		return false;
	});

	// Send filters as post requests
	jQuery(document).on('click', '.slimstat-filter-link, #toplevel_page_slimview1 a, #wp-admin-bar-slimstat-header li a', function(e){
		e.preventDefault();

		if (!jQuery('#slimstat-filters-form').length){
			return true;
		}

		filters_parsed = SlimStatAdmin.parse_url_filters('p0', jQuery(this).attr('href'));
		SlimStatAdmin.add_filters_to_form(filters_parsed, (typeof jQuery(this).attr('data-reset-filters') != 'undefined'));

		jQuery('#slimstat-filters-form').submit();
		jQuery('.slimstat-temp-filter').remove();
		return false;
	});

	// Behavior associated to all the 'ajax-based' buttons
	jQuery(document).on('click', '[id^=slim_] .button-ajax', function(e){
		e.preventDefault();
		report_id = jQuery(this).parents('.postbox').attr('id');

		if (typeof jQuery(this).attr('href') != 'undefined'){
			filters_parsed = SlimStatAdmin.parse_url_filters(report_id, jQuery(this).attr('href'));
			SlimStatAdmin.add_filters_to_form(filters_parsed);

			// Remember the new filter for when the report is refreshed
			if (typeof filters_parsed['fs[start_from]'] != 'undefined' && jQuery('#'+report_id+' .refresh').length){
				href = jQuery('#'+report_id+' .refresh').attr('href');
				href_clean = href.substring(0, href.indexOf('&fs%5Bstart_from'));
				if (href_clean != '') href = href_clean;
				jQuery('#'+report_id+' .refresh').attr('href', href+'&fs%5Bstart_from%5D='+filters_parsed['fs[start_from]']);
			}
		}

		data = {action: 'slimstat_load_report', report_id: report_id, security: jQuery('#meta-box-order-nonce').val(), page: SlimStatAdmin.get_query_string_value( 'page' ) };
		SlimStatAdmin.refresh_report(report_id, data);
		
		if (SlimStatAdminParams.use_slimscroll == 'yes'){
			jQuery('#'+report_id+' .inside').slimScroll({scrollTo : '0px'});
		}
		
		if (typeof refresh_handle != 'undefined' && !jQuery('[name^="fs\[is_past\]"]').length){
			window.clearTimeout(refresh_handle);
			SlimStatAdmin._refresh_timer[0] = parseInt(SlimStatAdminParams.refresh_interval/60);
			SlimStatAdmin._refresh_timer[1] = SlimStatAdminParams.refresh_interval%60;
			refresh_handle = window.setTimeout("SlimStatAdmin.refresh_countdown();", 1000);
		}
	});

	// Asynchronous Reports
	if (SlimStatAdminParams.async_load == 'yes'){
		jQuery('div[id^=slim_]').each(function(){
			report_id = jQuery(this).attr('id');
			data = {action: 'slimstat_load_report', report_id: report_id, security: jQuery('#meta-box-order-nonce').val(), current_tab: SlimStatAdminParams.current_tab}
			SlimStatAdmin.refresh_report(report_id, data);
		});
	}

	// Hide Admin Notice
	jQuery(document).on('click', '#slimstat-hide-admin-notice', function(e){
		e.preventDefault();
		jQuery(this).parents('.slimstat-notice').slideUp(1000);
		data = {action: 'slimstat_hide_admin_notice', security: jQuery('#meta-box-order-nonce').val()};
		jQuery.ajax({
			url: ajaxurl,
			type: 'post',
			async: true,
			data: data
		});
	});

	// Hide GeoLite Notice
	jQuery(document).on('click', '#slimstat-hide-geolite-notice', function(e){
		e.preventDefault();
		jQuery(this).parents('.wp-ui-notification').slideUp(1000);
		data = {action: 'slimstat_hide_geolite_notice', security: jQuery('#meta-box-order-nonce').val()};
		jQuery.ajax({
			url: ajaxurl,
			type: 'post',
			async: true,
			data: data
		});
	});

	// Accept terms and conditions
	jQuery(document).on('click', '#slimstat-accept-terms', function(e){
		e.preventDefault();
		jQuery('.slimstat-notice').slideUp(1000);
		data = {action: 'slimstat_enable_ads_feature', security: jQuery('#meta-box-order-nonce').val()};
		jQuery.ajax({
			url: ajaxurl,
			type: 'post',
			async: true,
			data: data
		});
	});

	// Delete Pageview
	jQuery(document).on('click', '.slimstat-delete-entry', function(e){
		var target = jQuery(this);

		e.preventDefault();
		data = {action: 'slimstat_delete_pageview', security: jQuery('#meta-box-order-nonce').val(), pageview_id : target.attr('data-pageview-id')};
		jQuery.ajax({
			url: ajaxurl,
			type: 'post',
			async: true,
			data: data
		}).done(function(){
			target.parents('p').fadeOut(1000);
		});
	});

	// SlimScroll init
	if (SlimStatAdminParams.use_slimscroll == 'yes'){
		jQuery('[id^=slim_]:not(.tall) .inside').slimScroll({
			distance: '2px',
			opacity: '0.15',
			size: '5px',
			wheelStep: 10
		});
		jQuery('[id^=slim_].tall .inside').slimScroll({
			distance: '2px',
			height: '630px',
			opacity: '0.15',
			size: '5px',
			wheelStep: 10
		});
	}

	// ToolTips
	jQuery(document).on('mouseover', '.slimstat-tooltip-trigger', function(e){
		var tooltip_content = jQuery( this ).next( '.slimstat-tooltip-content:not(.expanded)' );
		var tooltip_relationship = 'sibling';

		if ( tooltip_content.length == 0 ) {
			tooltip_content = jQuery( this ).find( '.slimstat-tooltip-content:not(.expanded)' );
			tooltip_relationship = 'child';
		}

		if ( tooltip_content.length ) {
			jQuery(this).qtip({
				overwrite: false,
				content: {
					text: tooltip_content
				},
				show: {
					event: e.type,
					ready: true
				},
				hide: {
	                delay: ( tooltip_relationship == 'child' ) ? 250 : 100,
	                fixed: true
	            },
				position: {
					my: 'top right',  // Position my top left...
	        		at: 'top left',
					adjust: {
						x: ( tooltip_relationship == 'child' ) ? 0 : -15
					},
					viewport: jQuery(window)
				},
				style: {
					classes: 'qtip-light'
				}
			}, e);
		}
	});

	// Modal Window / Overlay: Setup
	if (typeof jQuery('#slimstat-modal-dialog').dialog == 'function'){
		jQuery('#slimstat-modal-dialog').dialog({
			autoOpen: false,
			closeOnEscape: true,
			closeText: '',
			draggable: true,
			modal: true,
			open: function(){
				jQuery('.ui-widget-overlay,.close-dialog').bind('click',function(){
					jQuery('#slimstat-modal-dialog').dialog('close');
				});
			},
			position: { my: "top center" },
			resizable: false
		});
	}

	// Modal Window / Whois
	jQuery(document).on('click', '.whois', function(e){
		e.preventDefault();
		
		// If admin is using HTTPS and IP lookup service is not, open a new window/tab, instead of an overlay dialog
        if (document.location.href.substr(0, document.location.href.indexOf("://")).toLowerCase() == 'https' &&  jQuery(this).attr('href').substr(0, jQuery(this).attr('href').indexOf("://")).toLowerCase() == 'http'){
			window.open(jQuery(this).attr('href'), '_blank');
			return -1;
		}
		
		jQuery('#slimstat-modal-dialog').dialog({
			dialogClass: 'slimstat',
			title: jQuery(this).attr('title')
		}).html('<iframe id="ip2location" src="'+jQuery(this).attr('href')+'" width="100%" height="600"></iframe>');
		jQuery('#slimstat-modal-dialog').dialog('open');
	});

	// Modal Window / Load & Save Filters
	jQuery(document).on('click', '#slimstat-load-saved-filters', function(e){
		e.preventDefault();
		var inner_html = '';
		
		data = {action: 'slimstat_manage_filters', security: jQuery('#meta-box-order-nonce').val(), type: 'load', page: SlimStatAdmin.get_query_string_value( 'page' ) };
		jQuery.ajax({
			url: ajaxurl,
			type: 'post',
			async: false,
			data: data,
			success: function(response){
				inner_html = response;
			}
		});
		
		jQuery('#slimstat-modal-dialog').dialog({
			dialogClass: 'slimstat',
			title: jQuery(this).attr('title')
		}).html(inner_html);
		jQuery('#slimstat-modal-dialog').dialog('open');
	});

	// Save Filters
	jQuery(document).on('click', '#slimstat-save-filter', function(e){
		e.preventDefault();
		
		data = {action: 'slimstat_manage_filters', security: jQuery('#meta-box-order-nonce').val(), type: 'save', filter_array: jQuery(this).attr('data-filter-array')};
		jQuery.ajax({
			url: ajaxurl,
			type: 'post',
			async: false,
			data: data,
			success: function(response){
				jQuery('#slimstat-save-filter').text(response).fadeOut(1500);
			}
		});
	});

	// Delete saved filters
	jQuery(document).on('click', '.slimstat-delete-filter', function(e){
		e.preventDefault();

		data = {action: 'slimstat_manage_filters', security: jQuery('#meta-box-order-nonce').val(), type: 'delete', filter_id: jQuery(this).attr('data-filter-id'), page: SlimStatAdmin.get_query_string_value( 'page' ) };
		jQuery.ajax({
			url: ajaxurl,
			type: 'post',
			async: false,
			data: data,
			success: function(response){
				jQuery('#slim_filters_overlay').parent().html(response);
			}
		});
	});

	// Redraw charts and adjust modal window width on resize
	SlimStatAdmin.chart_init();
	jQuery(window).resize(function(){
		SlimStatAdmin.chart_init();
	});

	// Customizer: clone and delete report placeholders
	jQuery( '.slimstat-layout .slimstat-header-buttons a' ).on( 'click', function() {
		if ( jQuery( this ).hasClass( 'slimstat-font-docs') ) {
			jQuery( this ).removeClass( 'slimstat-font-docs' ).addClass( 'slimstat-font-trash' ).parents( '.postbox' ).clone(true).appendTo( jQuery( this ).parents( '.meta-box-sortables' ) );
			jQuery( this ).removeClass( 'slimstat-font-trash' ).addClass( 'slimstat-font-docs' );
		}
		else {
			jQuery( this ).parents( '.postbox' ).remove();
		}

		// Save the new groups
		data = { action: 'meta-box-order', _ajax_nonce: jQuery('#meta-box-order-nonce').val(), page_columns: 0, page: 'slimstat_page_slimlayout' };
		jQuery( '.meta-box-sortables' ).each( function() {
			data[ 'order[' + this.id.split("-")[0] + ']' ] = jQuery( this ).sortable( 'toArray' ).join( ',' );
		});
		
		jQuery.ajax({
			url: ajaxurl,
			type: 'post',
			async: true,
			data: data
		});

		return false;
	});
});

/* SlimScroll v1.3.6 | http://rocha.la | Copyright (c) 2011 Piotr Rochala. Licensed MIT, GPL. */
(function(e){e.fn.extend({slimScroll:function(g){var a=e.extend({width:"auto",height:"250px",size:"7px",color:"#000",position:"right",distance:"1px",start:"top",opacity:.4,alwaysVisible:!1,disableFadeOut:!1,railVisible:!1,railColor:"#333",railOpacity:.2,railDraggable:!0,railClass:"slimScrollRail",barClass:"slimScrollBar",wrapperClass:"slimScrollDiv",allowPageScroll:!1,wheelStep:20,touchScrollStep:200,borderRadius:"7px",railBorderRadius:"7px"},g);this.each(function(){function v(d){if(r){d=d||window.event;var c=0;d.wheelDelta&&(c=-d.wheelDelta/120);d.detail&&(c=d.detail/3);e(d.target||d.srcTarget||d.srcElement).closest("."+a.wrapperClass).is(b.parent())&&m(c,!0);d.preventDefault&&!k&&d.preventDefault();k||(d.returnValue=!1)}}function m(d,e,g){k=!1;var f=d,h=b.outerHeight()-c.outerHeight();e&&(f=parseInt(c.css("top"))+d*parseInt(a.wheelStep)/100*c.outerHeight(),f=Math.min(Math.max(f,0),h),f=0<d?Math.ceil(f):Math.floor(f),c.css({top:f+"px"}));l=parseInt(c.css("top"))/(b.outerHeight()-c.outerHeight());f=l*(b[0].scrollHeight-b.outerHeight());g&&(f=d,d=f/b[0].scrollHeight*b.outerHeight(),d=Math.min(Math.max(d,0),h),c.css({top:d+"px"}));b.scrollTop(f);b.trigger("slimscrolling",~~f);w();p()}function x(){u=Math.max(b.outerHeight()/b[0].scrollHeight*b.outerHeight(),30);c.css({height:u+"px"});var a=u==b.outerHeight()?"none":"block";c.css({display:a})}function w(){x();clearTimeout(B);l==~~l?(k=a.allowPageScroll,C!=l&&b.trigger("slimscroll",0==~~l?"top":"bottom")):k=!1;C=l;u>=b.outerHeight()?k=!0:(c.stop(!0,!0).fadeIn("fast"),a.railVisible&&h.stop(!0,!0).fadeIn("fast"))}function p(){a.alwaysVisible||(B=setTimeout(function(){a.disableFadeOut&&r||y||z||(c.fadeOut("slow"),h.fadeOut("slow"))},1E3))}var r,y,z,B,A,u,l,C,k=!1,b=e(this);if(b.parent().hasClass(a.wrapperClass)){var n=b.scrollTop(),c=b.closest("."+a.barClass),h=b.closest("."+a.railClass);x();if(e.isPlainObject(g)){if("height"in g&&"auto"==g.height){b.parent().css("height","auto");b.css("height","auto");var q=b.parent().parent().height();b.parent().css("height",q);b.css("height",q)}if("scrollTo"in g)n=parseInt(a.scrollTo);else if("scrollBy"in g)n+=parseInt(a.scrollBy);else if("destroy"in g){c.remove();h.remove();b.unwrap();return}m(n,!1,!0)}}else if(!(e.isPlainObject(g)&&"destroy"in g)){a.height="auto"==a.height?b.parent().height():a.height;n=e("<div></div>").addClass(a.wrapperClass).css({position:"relative",overflow:"hidden",width:a.width,height:a.height});b.css({overflow:"hidden",width:a.width,height:a.height});var h=e("<div></div>").addClass(a.railClass).css({width:a.size,height:"100%",position:"absolute",top:0,display:a.alwaysVisible&&a.railVisible?"block":"none","border-radius":a.railBorderRadius,background:a.railColor,opacity:a.railOpacity,zIndex:90}),c=e("<div></div>").addClass(a.barClass).css({background:a.color,width:a.size,position:"absolute",top:0,opacity:a.opacity,display:a.alwaysVisible?"block":"none","border-radius":a.borderRadius,BorderRadius:a.borderRadius,MozBorderRadius:a.borderRadius,WebkitBorderRadius:a.borderRadius,zIndex:99}),q="right"==a.position?{right:a.distance}:{left:a.distance};h.css(q);c.css(q);b.wrap(n);b.parent().append(c);b.parent().append(h);a.railDraggable&&c.bind("mousedown",function(a){var b=e(document);z=!0;t=parseFloat(c.css("top"));pageY=a.pageY;b.bind("mousemove.slimscroll",function(a){currTop=t+a.pageY-pageY;c.css("top",currTop);m(0,c.position().top,!1)});b.bind("mouseup.slimscroll",function(a){z=!1;p();b.unbind(".slimscroll")});return!1}).bind("selectstart.slimscroll",function(a){a.stopPropagation();a.preventDefault();return!1});h.hover(function(){w()},function(){p()});c.hover(function(){y=!0},function(){y=!1});b.hover(function(){r=!0;w();p()},function(){r=!1;p()});b.bind("touchstart",function(a,b){a.originalEvent.touches.length&&(A=a.originalEvent.touches[0].pageY)});b.bind("touchmove",function(b){k||b.originalEvent.preventDefault();b.originalEvent.touches.length&&(m((A-b.originalEvent.touches[0].pageY)/a.touchScrollStep,!0),A=b.originalEvent.touches[0].pageY)});x();"bottom"===a.start?(c.css({top:b.outerHeight()-c.outerHeight()}),m(0,!0)):"top"!==a.start&&(m(e(a.start).position().top,null,!0),a.alwaysVisible||c.hide());window.addEventListener?(this.addEventListener("DOMMouseScroll",v,!1),this.addEventListener("mousewheel",v,!1)):document.attachEvent("onmousewheel",v)}});return this}});e.fn.extend({slimscroll:e.fn.slimScroll})})(jQuery);

/* qTip2 v2.2.1 | http://qtip2.com | Licensed MIT, GPL. */
!function(a,b,c){!function(a){"use strict";"function"==typeof define&&define.amd?define(["jquery"],a):jQuery&&!jQuery.fn.qtip&&a(jQuery)}(function(d){"use strict";function e(a,b,c,e){this.id=c,this.target=a,this.tooltip=z,this.elements={target:a},this._id=M+"-"+c,this.timers={img:{}},this.options=b,this.plugins={},this.cache={event:{},target:d(),disabled:y,attr:e,onTooltip:y,lastClass:""},this.rendered=this.destroyed=this.disabled=this.waiting=this.hiddenDuringWait=this.positioning=this.triggering=y}function f(a){return a===z||"object"!==d.type(a)}function g(a){return!(d.isFunction(a)||a&&a.attr||a.length||"object"===d.type(a)&&(a.jquery||a.then))}function h(a){var b,c,e,h;return f(a)?y:(f(a.metadata)&&(a.metadata={type:a.metadata}),"content"in a&&(b=a.content,f(b)||b.jquery||b.done?b=a.content={text:c=g(b)?y:b}:c=b.text,"ajax"in b&&(e=b.ajax,h=e&&e.once!==y,delete b.ajax,b.text=function(a,b){var f=c||d(this).attr(b.options.content.attr)||"Loading...",g=d.ajax(d.extend({},e,{context:b})).then(e.success,z,e.error).then(function(a){return a&&h&&b.set("content.text",a),a},function(a,c,d){b.destroyed||0===a.status||b.set("content.text",c+": "+d)});return h?f:(b.set("content.text",f),g)}),"title"in b&&(d.isPlainObject(b.title)&&(b.button=b.title.button,b.title=b.title.text),g(b.title||y)&&(b.title=y))),"position"in a&&f(a.position)&&(a.position={my:a.position,at:a.position}),"show"in a&&f(a.show)&&(a.show=a.show.jquery?{target:a.show}:a.show===x?{ready:x}:{event:a.show}),"hide"in a&&f(a.hide)&&(a.hide=a.hide.jquery?{target:a.hide}:{event:a.hide}),"style"in a&&f(a.style)&&(a.style={classes:a.style}),d.each(L,function(){this.sanitize&&this.sanitize(a)}),a)}function i(a,b){for(var c,d=0,e=a,f=b.split(".");e=e[f[d++]];)d<f.length&&(c=e);return[c||a,f.pop()]}function j(a,b){var c,d,e;for(c in this.checks)for(d in this.checks[c])(e=new RegExp(d,"i").exec(a))&&(b.push(e),("builtin"===c||this.plugins[c])&&this.checks[c][d].apply(this.plugins[c]||this,b))}function k(a){return P.concat("").join(a?"-"+a+" ":" ")}function l(a,b){return b>0?setTimeout(d.proxy(a,this),b):void a.call(this)}function m(a){this.tooltip.hasClass(W)||(clearTimeout(this.timers.show),clearTimeout(this.timers.hide),this.timers.show=l.call(this,function(){this.toggle(x,a)},this.options.show.delay))}function n(a){if(!this.tooltip.hasClass(W)&&!this.destroyed){var b=d(a.relatedTarget),c=b.closest(Q)[0]===this.tooltip[0],e=b[0]===this.options.show.target[0];if(clearTimeout(this.timers.show),clearTimeout(this.timers.hide),this!==b[0]&&"mouse"===this.options.position.target&&c||this.options.hide.fixed&&/mouse(out|leave|move)/.test(a.type)&&(c||e))try{a.preventDefault(),a.stopImmediatePropagation()}catch(f){}else this.timers.hide=l.call(this,function(){this.toggle(y,a)},this.options.hide.delay,this)}}function o(a){!this.tooltip.hasClass(W)&&this.options.hide.inactive&&(clearTimeout(this.timers.inactive),this.timers.inactive=l.call(this,function(){this.hide(a)},this.options.hide.inactive))}function p(a){this.rendered&&this.tooltip[0].offsetWidth>0&&this.reposition(a)}function q(a,c,e){d(b.body).delegate(a,(c.split?c:c.join("."+M+" "))+"."+M,function(){var a=s.api[d.attr(this,O)];a&&!a.disabled&&e.apply(a,arguments)})}function r(a,c,f){var g,i,j,k,l,m=d(b.body),n=a[0]===b?m:a,o=a.metadata?a.metadata(f.metadata):z,p="html5"===f.metadata.type&&o?o[f.metadata.name]:z,q=a.data(f.metadata.name||"qtipopts");try{q="string"==typeof q?d.parseJSON(q):q}catch(r){}if(k=d.extend(x,{},s.defaults,f,"object"==typeof q?h(q):z,h(p||o)),i=k.position,k.id=c,"boolean"==typeof k.content.text){if(j=a.attr(k.content.attr),k.content.attr===y||!j)return y;k.content.text=j}if(i.container.length||(i.container=m),i.target===y&&(i.target=n),k.show.target===y&&(k.show.target=n),k.show.solo===x&&(k.show.solo=i.container.closest("body")),k.hide.target===y&&(k.hide.target=n),k.position.viewport===x&&(k.position.viewport=i.container),i.container=i.container.eq(0),i.at=new u(i.at,x),i.my=new u(i.my),a.data(M))if(k.overwrite)a.qtip("destroy",!0);else if(k.overwrite===y)return y;return a.attr(N,c),k.suppress&&(l=a.attr("title"))&&a.removeAttr("title").attr(Y,l).attr("title",""),g=new e(a,k,c,!!j),a.data(M,g),g}var s,t,u,v,w,x=!0,y=!1,z=null,A="x",B="y",C="width",D="height",E="top",F="left",G="bottom",H="right",I="center",J="flipinvert",K="shift",L={},M="qtip",N="data-hasqtip",O="data-qtip-id",P=["ui-widget","ui-tooltip"],Q="."+M,R="click dblclick mousedown mouseup mousemove mouseleave mouseenter".split(" "),S=M+"-fixed",T=M+"-default",U=M+"-focus",V=M+"-hover",W=M+"-disabled",X="_replacedByqTip",Y="oldtitle",Z={ie:function(){for(var a=4,c=b.createElement("div");(c.innerHTML="<!--[if gt IE "+a+"]><i></i><![endif]-->")&&c.getElementsByTagName("i")[0];a+=1);return a>4?a:0/0}(),iOS:parseFloat((""+(/CPU.*OS ([0-9_]{1,5})|(CPU like).*AppleWebKit.*Mobile/i.exec(navigator.userAgent)||[0,""])[1]).replace("undefined","3_2").replace("_",".").replace("_",""))||y};t=e.prototype,t._when=function(a){return d.when.apply(d,a)},t.render=function(a){if(this.rendered||this.destroyed)return this;var b,c=this,e=this.options,f=this.cache,g=this.elements,h=e.content.text,i=e.content.title,j=e.content.button,k=e.position,l=("."+this._id+" ",[]);return d.attr(this.target[0],"aria-describedby",this._id),f.posClass=this._createPosClass((this.position={my:k.my,at:k.at}).my),this.tooltip=g.tooltip=b=d("<div/>",{id:this._id,"class":[M,T,e.style.classes,f.posClass].join(" "),width:e.style.width||"",height:e.style.height||"",tracking:"mouse"===k.target&&k.adjust.mouse,role:"alert","aria-live":"polite","aria-atomic":y,"aria-describedby":this._id+"-content","aria-hidden":x}).toggleClass(W,this.disabled).attr(O,this.id).data(M,this).appendTo(k.container).append(g.content=d("<div />",{"class":M+"-content",id:this._id+"-content","aria-atomic":x})),this.rendered=-1,this.positioning=x,i&&(this._createTitle(),d.isFunction(i)||l.push(this._updateTitle(i,y))),j&&this._createButton(),d.isFunction(h)||l.push(this._updateContent(h,y)),this.rendered=x,this._setWidget(),d.each(L,function(a){var b;"render"===this.initialize&&(b=this(c))&&(c.plugins[a]=b)}),this._unassignEvents(),this._assignEvents(),this._when(l).then(function(){c._trigger("render"),c.positioning=y,c.hiddenDuringWait||!e.show.ready&&!a||c.toggle(x,f.event,y),c.hiddenDuringWait=y}),s.api[this.id]=this,this},t.destroy=function(a){function b(){if(!this.destroyed){this.destroyed=x;var a,b=this.target,c=b.attr(Y);this.rendered&&this.tooltip.stop(1,0).find("*").remove().end().remove(),d.each(this.plugins,function(){this.destroy&&this.destroy()});for(a in this.timers)clearTimeout(this.timers[a]);b.removeData(M).removeAttr(O).removeAttr(N).removeAttr("aria-describedby"),this.options.suppress&&c&&b.attr("title",c).removeAttr(Y),this._unassignEvents(),this.options=this.elements=this.cache=this.timers=this.plugins=this.mouse=z,delete s.api[this.id]}}return this.destroyed?this.target:(a===x&&"hide"!==this.triggering||!this.rendered?b.call(this):(this.tooltip.one("tooltiphidden",d.proxy(b,this)),!this.triggering&&this.hide()),this.target)},v=t.checks={builtin:{"^id$":function(a,b,c,e){var f=c===x?s.nextid:c,g=M+"-"+f;f!==y&&f.length>0&&!d("#"+g).length?(this._id=g,this.rendered&&(this.tooltip[0].id=this._id,this.elements.content[0].id=this._id+"-content",this.elements.title[0].id=this._id+"-title")):a[b]=e},"^prerender":function(a,b,c){c&&!this.rendered&&this.render(this.options.show.ready)},"^content.text$":function(a,b,c){this._updateContent(c)},"^content.attr$":function(a,b,c,d){this.options.content.text===this.target.attr(d)&&this._updateContent(this.target.attr(c))},"^content.title$":function(a,b,c){return c?(c&&!this.elements.title&&this._createTitle(),void this._updateTitle(c)):this._removeTitle()},"^content.button$":function(a,b,c){this._updateButton(c)},"^content.title.(text|button)$":function(a,b,c){this.set("content."+b,c)},"^position.(my|at)$":function(a,b,c){"string"==typeof c&&(this.position[b]=a[b]=new u(c,"at"===b))},"^position.container$":function(a,b,c){this.rendered&&this.tooltip.appendTo(c)},"^show.ready$":function(a,b,c){c&&(!this.rendered&&this.render(x)||this.toggle(x))},"^style.classes$":function(a,b,c,d){this.rendered&&this.tooltip.removeClass(d).addClass(c)},"^style.(width|height)":function(a,b,c){this.rendered&&this.tooltip.css(b,c)},"^style.widget|content.title":function(){this.rendered&&this._setWidget()},"^style.def":function(a,b,c){this.rendered&&this.tooltip.toggleClass(T,!!c)},"^events.(render|show|move|hide|focus|blur)$":function(a,b,c){this.rendered&&this.tooltip[(d.isFunction(c)?"":"un")+"bind"]("tooltip"+b,c)},"^(show|hide|position).(event|target|fixed|inactive|leave|distance|viewport|adjust)":function(){if(this.rendered){var a=this.options.position;this.tooltip.attr("tracking","mouse"===a.target&&a.adjust.mouse),this._unassignEvents(),this._assignEvents()}}}},t.get=function(a){if(this.destroyed)return this;var b=i(this.options,a.toLowerCase()),c=b[0][b[1]];return c.precedance?c.string():c};var $=/^position\.(my|at|adjust|target|container|viewport)|style|content|show\.ready/i,_=/^prerender|show\.ready/i;t.set=function(a,b){if(this.destroyed)return this;{var c,e=this.rendered,f=y,g=this.options;this.checks}return"string"==typeof a?(c=a,a={},a[c]=b):a=d.extend({},a),d.each(a,function(b,c){if(e&&_.test(b))return void delete a[b];var h,j=i(g,b.toLowerCase());h=j[0][j[1]],j[0][j[1]]=c&&c.nodeType?d(c):c,f=$.test(b)||f,a[b]=[j[0],j[1],c,h]}),h(g),this.positioning=x,d.each(a,d.proxy(j,this)),this.positioning=y,this.rendered&&this.tooltip[0].offsetWidth>0&&f&&this.reposition("mouse"===g.position.target?z:this.cache.event),this},t._update=function(a,b){var c=this,e=this.cache;return this.rendered&&a?(d.isFunction(a)&&(a=a.call(this.elements.target,e.event,this)||""),d.isFunction(a.then)?(e.waiting=x,a.then(function(a){return e.waiting=y,c._update(a,b)},z,function(a){return c._update(a,b)})):a===y||!a&&""!==a?y:(a.jquery&&a.length>0?b.empty().append(a.css({display:"block",visibility:"visible"})):b.html(a),this._waitForContent(b).then(function(a){c.rendered&&c.tooltip[0].offsetWidth>0&&c.reposition(e.event,!a.length)}))):y},t._waitForContent=function(a){var b=this.cache;return b.waiting=x,(d.fn.imagesLoaded?a.imagesLoaded():d.Deferred().resolve([])).done(function(){b.waiting=y}).promise()},t._updateContent=function(a,b){this._update(a,this.elements.content,b)},t._updateTitle=function(a,b){this._update(a,this.elements.title,b)===y&&this._removeTitle(y)},t._createTitle=function(){var a=this.elements,b=this._id+"-title";a.titlebar&&this._removeTitle(),a.titlebar=d("<div />",{"class":M+"-titlebar "+(this.options.style.widget?k("header"):"")}).append(a.title=d("<div />",{id:b,"class":M+"-title","aria-atomic":x})).insertBefore(a.content).delegate(".qtip-close","mousedown keydown mouseup keyup mouseout",function(a){d(this).toggleClass("ui-state-active ui-state-focus","down"===a.type.substr(-4))}).delegate(".qtip-close","mouseover mouseout",function(a){d(this).toggleClass("ui-state-hover","mouseover"===a.type)}),this.options.content.button&&this._createButton()},t._removeTitle=function(a){var b=this.elements;b.title&&(b.titlebar.remove(),b.titlebar=b.title=b.button=z,a!==y&&this.reposition())},t._createPosClass=function(a){return M+"-pos-"+(a||this.options.position.my).abbrev()},t.reposition=function(c,e){if(!this.rendered||this.positioning||this.destroyed)return this;this.positioning=x;var f,g,h,i,j=this.cache,k=this.tooltip,l=this.options.position,m=l.target,n=l.my,o=l.at,p=l.viewport,q=l.container,r=l.adjust,s=r.method.split(" "),t=k.outerWidth(y),u=k.outerHeight(y),v=0,w=0,z=k.css("position"),A={left:0,top:0},B=k[0].offsetWidth>0,C=c&&"scroll"===c.type,D=d(a),J=q[0].ownerDocument,K=this.mouse;if(d.isArray(m)&&2===m.length)o={x:F,y:E},A={left:m[0],top:m[1]};else if("mouse"===m)o={x:F,y:E},(!r.mouse||this.options.hide.distance)&&j.origin&&j.origin.pageX?c=j.origin:!c||c&&("resize"===c.type||"scroll"===c.type)?c=j.event:K&&K.pageX&&(c=K),"static"!==z&&(A=q.offset()),J.body.offsetWidth!==(a.innerWidth||J.documentElement.clientWidth)&&(g=d(b.body).offset()),A={left:c.pageX-A.left+(g&&g.left||0),top:c.pageY-A.top+(g&&g.top||0)},r.mouse&&C&&K&&(A.left-=(K.scrollX||0)-D.scrollLeft(),A.top-=(K.scrollY||0)-D.scrollTop());else{if("event"===m?c&&c.target&&"scroll"!==c.type&&"resize"!==c.type?j.target=d(c.target):c.target||(j.target=this.elements.target):"event"!==m&&(j.target=d(m.jquery?m:this.elements.target)),m=j.target,m=d(m).eq(0),0===m.length)return this;m[0]===b||m[0]===a?(v=Z.iOS?a.innerWidth:m.width(),w=Z.iOS?a.innerHeight:m.height(),m[0]===a&&(A={top:(p||m).scrollTop(),left:(p||m).scrollLeft()})):L.imagemap&&m.is("area")?f=L.imagemap(this,m,o,L.viewport?s:y):L.svg&&m&&m[0].ownerSVGElement?f=L.svg(this,m,o,L.viewport?s:y):(v=m.outerWidth(y),w=m.outerHeight(y),A=m.offset()),f&&(v=f.width,w=f.height,g=f.offset,A=f.position),A=this.reposition.offset(m,A,q),(Z.iOS>3.1&&Z.iOS<4.1||Z.iOS>=4.3&&Z.iOS<4.33||!Z.iOS&&"fixed"===z)&&(A.left-=D.scrollLeft(),A.top-=D.scrollTop()),(!f||f&&f.adjustable!==y)&&(A.left+=o.x===H?v:o.x===I?v/2:0,A.top+=o.y===G?w:o.y===I?w/2:0)}return A.left+=r.x+(n.x===H?-t:n.x===I?-t/2:0),A.top+=r.y+(n.y===G?-u:n.y===I?-u/2:0),L.viewport?(h=A.adjusted=L.viewport(this,A,l,v,w,t,u),g&&h.left&&(A.left+=g.left),g&&h.top&&(A.top+=g.top),h.my&&(this.position.my=h.my)):A.adjusted={left:0,top:0},j.posClass!==(i=this._createPosClass(this.position.my))&&k.removeClass(j.posClass).addClass(j.posClass=i),this._trigger("move",[A,p.elem||p],c)?(delete A.adjusted,e===y||!B||isNaN(A.left)||isNaN(A.top)||"mouse"===m||!d.isFunction(l.effect)?k.css(A):d.isFunction(l.effect)&&(l.effect.call(k,this,d.extend({},A)),k.queue(function(a){d(this).css({opacity:"",height:""}),Z.ie&&this.style.removeAttribute("filter"),a()})),this.positioning=y,this):this},t.reposition.offset=function(a,c,e){function f(a,b){c.left+=b*a.scrollLeft(),c.top+=b*a.scrollTop()}if(!e[0])return c;var g,h,i,j,k=d(a[0].ownerDocument),l=!!Z.ie&&"CSS1Compat"!==b.compatMode,m=e[0];do"static"!==(h=d.css(m,"position"))&&("fixed"===h?(i=m.getBoundingClientRect(),f(k,-1)):(i=d(m).position(),i.left+=parseFloat(d.css(m,"borderLeftWidth"))||0,i.top+=parseFloat(d.css(m,"borderTopWidth"))||0),c.left-=i.left+(parseFloat(d.css(m,"marginLeft"))||0),c.top-=i.top+(parseFloat(d.css(m,"marginTop"))||0),g||"hidden"===(j=d.css(m,"overflow"))||"visible"===j||(g=d(m)));while(m=m.offsetParent);return g&&(g[0]!==k[0]||l)&&f(g,1),c};var ab=(u=t.reposition.Corner=function(a,b){a=(""+a).replace(/([A-Z])/," $1").replace(/middle/gi,I).toLowerCase(),this.x=(a.match(/left|right/i)||a.match(/center/)||["inherit"])[0].toLowerCase(),this.y=(a.match(/top|bottom|center/i)||["inherit"])[0].toLowerCase(),this.forceY=!!b;var c=a.charAt(0);this.precedance="t"===c||"b"===c?B:A}).prototype;ab.invert=function(a,b){this[a]=this[a]===F?H:this[a]===H?F:b||this[a]},ab.string=function(a){var b=this.x,c=this.y,d=b!==c?"center"===b||"center"!==c&&(this.precedance===B||this.forceY)?[c,b]:[b,c]:[b];return a!==!1?d.join(" "):d},ab.abbrev=function(){var a=this.string(!1);return a[0].charAt(0)+(a[1]&&a[1].charAt(0)||"")},ab.clone=function(){return new u(this.string(),this.forceY)},t.toggle=function(a,c){var e=this.cache,f=this.options,g=this.tooltip;if(c){if(/over|enter/.test(c.type)&&e.event&&/out|leave/.test(e.event.type)&&f.show.target.add(c.target).length===f.show.target.length&&g.has(c.relatedTarget).length)return this;e.event=d.event.fix(c)}if(this.waiting&&!a&&(this.hiddenDuringWait=x),!this.rendered)return a?this.render(1):this;if(this.destroyed||this.disabled)return this;var h,i,j,k=a?"show":"hide",l=this.options[k],m=(this.options[a?"hide":"show"],this.options.position),n=this.options.content,o=this.tooltip.css("width"),p=this.tooltip.is(":visible"),q=a||1===l.target.length,r=!c||l.target.length<2||e.target[0]===c.target;return(typeof a).search("boolean|number")&&(a=!p),h=!g.is(":animated")&&p===a&&r,i=h?z:!!this._trigger(k,[90]),this.destroyed?this:(i!==y&&a&&this.focus(c),!i||h?this:(d.attr(g[0],"aria-hidden",!a),a?(this.mouse&&(e.origin=d.event.fix(this.mouse)),d.isFunction(n.text)&&this._updateContent(n.text,y),d.isFunction(n.title)&&this._updateTitle(n.title,y),!w&&"mouse"===m.target&&m.adjust.mouse&&(d(b).bind("mousemove."+M,this._storeMouse),w=x),o||g.css("width",g.outerWidth(y)),this.reposition(c,arguments[2]),o||g.css("width",""),l.solo&&("string"==typeof l.solo?d(l.solo):d(Q,l.solo)).not(g).not(l.target).qtip("hide",d.Event("tooltipsolo"))):(clearTimeout(this.timers.show),delete e.origin,w&&!d(Q+'[tracking="true"]:visible',l.solo).not(g).length&&(d(b).unbind("mousemove."+M),w=y),this.blur(c)),j=d.proxy(function(){a?(Z.ie&&g[0].style.removeAttribute("filter"),g.css("overflow",""),"string"==typeof l.autofocus&&d(this.options.show.autofocus,g).focus(),this.options.show.target.trigger("qtip-"+this.id+"-inactive")):g.css({display:"",visibility:"",opacity:"",left:"",top:""}),this._trigger(a?"visible":"hidden")},this),l.effect===y||q===y?(g[k](),j()):d.isFunction(l.effect)?(g.stop(1,1),l.effect.call(g,this),g.queue("fx",function(a){j(),a()})):g.fadeTo(90,a?1:0,j),a&&l.target.trigger("qtip-"+this.id+"-inactive"),this))},t.show=function(a){return this.toggle(x,a)},t.hide=function(a){return this.toggle(y,a)},t.focus=function(a){if(!this.rendered||this.destroyed)return this;var b=d(Q),c=this.tooltip,e=parseInt(c[0].style.zIndex,10),f=s.zindex+b.length;return c.hasClass(U)||this._trigger("focus",[f],a)&&(e!==f&&(b.each(function(){this.style.zIndex>e&&(this.style.zIndex=this.style.zIndex-1)}),b.filter("."+U).qtip("blur",a)),c.addClass(U)[0].style.zIndex=f),this},t.blur=function(a){return!this.rendered||this.destroyed?this:(this.tooltip.removeClass(U),this._trigger("blur",[this.tooltip.css("zIndex")],a),this)},t.disable=function(a){return this.destroyed?this:("toggle"===a?a=!(this.rendered?this.tooltip.hasClass(W):this.disabled):"boolean"!=typeof a&&(a=x),this.rendered&&this.tooltip.toggleClass(W,a).attr("aria-disabled",a),this.disabled=!!a,this)},t.enable=function(){return this.disable(y)},t._createButton=function(){var a=this,b=this.elements,c=b.tooltip,e=this.options.content.button,f="string"==typeof e,g=f?e:"Close tooltip";b.button&&b.button.remove(),b.button=e.jquery?e:d("<a />",{"class":"qtip-close "+(this.options.style.widget?"":M+"-icon"),title:g,"aria-label":g}).prepend(d("<span />",{"class":"ui-icon ui-icon-close",html:"&times;"})),b.button.appendTo(b.titlebar||c).attr("role","button").click(function(b){return c.hasClass(W)||a.hide(b),y})},t._updateButton=function(a){if(!this.rendered)return y;var b=this.elements.button;a?this._createButton():b.remove()},t._setWidget=function(){var a=this.options.style.widget,b=this.elements,c=b.tooltip,d=c.hasClass(W);c.removeClass(W),W=a?"ui-state-disabled":"qtip-disabled",c.toggleClass(W,d),c.toggleClass("ui-helper-reset "+k(),a).toggleClass(T,this.options.style.def&&!a),b.content&&b.content.toggleClass(k("content"),a),b.titlebar&&b.titlebar.toggleClass(k("header"),a),b.button&&b.button.toggleClass(M+"-icon",!a)},t._storeMouse=function(a){return(this.mouse=d.event.fix(a)).type="mousemove",this},t._bind=function(a,b,c,e,f){if(a&&c&&b.length){var g="."+this._id+(e?"-"+e:"");return d(a).bind((b.split?b:b.join(g+" "))+g,d.proxy(c,f||this)),this}},t._unbind=function(a,b){return a&&d(a).unbind("."+this._id+(b?"-"+b:"")),this},t._trigger=function(a,b,c){var e=d.Event("tooltip"+a);return e.originalEvent=c&&d.extend({},c)||this.cache.event||z,this.triggering=a,this.tooltip.trigger(e,[this].concat(b||[])),this.triggering=y,!e.isDefaultPrevented()},t._bindEvents=function(a,b,c,e,f,g){var h=c.filter(e).add(e.filter(c)),i=[];h.length&&(d.each(b,function(b,c){var e=d.inArray(c,a);e>-1&&i.push(a.splice(e,1)[0])}),i.length&&(this._bind(h,i,function(a){var b=this.rendered?this.tooltip[0].offsetWidth>0:!1;(b?g:f).call(this,a)}),c=c.not(h),e=e.not(h))),this._bind(c,a,f),this._bind(e,b,g)},t._assignInitialEvents=function(a){function b(a){return this.disabled||this.destroyed?y:(this.cache.event=a&&d.event.fix(a),this.cache.target=a&&d(a.target),clearTimeout(this.timers.show),void(this.timers.show=l.call(this,function(){this.render("object"==typeof a||c.show.ready)},c.prerender?0:c.show.delay)))}var c=this.options,e=c.show.target,f=c.hide.target,g=c.show.event?d.trim(""+c.show.event).split(" "):[],h=c.hide.event?d.trim(""+c.hide.event).split(" "):[];this._bind(this.elements.target,["remove","removeqtip"],function(){this.destroy(!0)},"destroy"),/mouse(over|enter)/i.test(c.show.event)&&!/mouse(out|leave)/i.test(c.hide.event)&&h.push("mouseleave"),this._bind(e,"mousemove",function(a){this._storeMouse(a),this.cache.onTarget=x}),this._bindEvents(g,h,e,f,b,function(){return this.timers?void clearTimeout(this.timers.show):y}),(c.show.ready||c.prerender)&&b.call(this,a)},t._assignEvents=function(){var c=this,e=this.options,f=e.position,g=this.tooltip,h=e.show.target,i=e.hide.target,j=f.container,k=f.viewport,l=d(b),q=(d(b.body),d(a)),r=e.show.event?d.trim(""+e.show.event).split(" "):[],t=e.hide.event?d.trim(""+e.hide.event).split(" "):[];d.each(e.events,function(a,b){c._bind(g,"toggle"===a?["tooltipshow","tooltiphide"]:["tooltip"+a],b,null,g)}),/mouse(out|leave)/i.test(e.hide.event)&&"window"===e.hide.leave&&this._bind(l,["mouseout","blur"],function(a){/select|option/.test(a.target.nodeName)||a.relatedTarget||this.hide(a)}),e.hide.fixed?i=i.add(g.addClass(S)):/mouse(over|enter)/i.test(e.show.event)&&this._bind(i,"mouseleave",function(){clearTimeout(this.timers.show)}),(""+e.hide.event).indexOf("unfocus")>-1&&this._bind(j.closest("html"),["mousedown","touchstart"],function(a){var b=d(a.target),c=this.rendered&&!this.tooltip.hasClass(W)&&this.tooltip[0].offsetWidth>0,e=b.parents(Q).filter(this.tooltip[0]).length>0;b[0]===this.target[0]||b[0]===this.tooltip[0]||e||this.target.has(b[0]).length||!c||this.hide(a)}),"number"==typeof e.hide.inactive&&(this._bind(h,"qtip-"+this.id+"-inactive",o,"inactive"),this._bind(i.add(g),s.inactiveEvents,o)),this._bindEvents(r,t,h,i,m,n),this._bind(h.add(g),"mousemove",function(a){if("number"==typeof e.hide.distance){var b=this.cache.origin||{},c=this.options.hide.distance,d=Math.abs;(d(a.pageX-b.pageX)>=c||d(a.pageY-b.pageY)>=c)&&this.hide(a)}this._storeMouse(a)}),"mouse"===f.target&&f.adjust.mouse&&(e.hide.event&&this._bind(h,["mouseenter","mouseleave"],function(a){return this.cache?void(this.cache.onTarget="mouseenter"===a.type):y}),this._bind(l,"mousemove",function(a){this.rendered&&this.cache.onTarget&&!this.tooltip.hasClass(W)&&this.tooltip[0].offsetWidth>0&&this.reposition(a)})),(f.adjust.resize||k.length)&&this._bind(d.event.special.resize?k:q,"resize",p),f.adjust.scroll&&this._bind(q.add(f.container),"scroll",p)},t._unassignEvents=function(){var c=this.options,e=c.show.target,f=c.hide.target,g=d.grep([this.elements.target[0],this.rendered&&this.tooltip[0],c.position.container[0],c.position.viewport[0],c.position.container.closest("html")[0],a,b],function(a){return"object"==typeof a});e&&e.toArray&&(g=g.concat(e.toArray())),f&&f.toArray&&(g=g.concat(f.toArray())),this._unbind(g)._unbind(g,"destroy")._unbind(g,"inactive")},d(function(){q(Q,["mouseenter","mouseleave"],function(a){var b="mouseenter"===a.type,c=d(a.currentTarget),e=d(a.relatedTarget||a.target),f=this.options;b?(this.focus(a),c.hasClass(S)&&!c.hasClass(W)&&clearTimeout(this.timers.hide)):"mouse"===f.position.target&&f.position.adjust.mouse&&f.hide.event&&f.show.target&&!e.closest(f.show.target[0]).length&&this.hide(a),c.toggleClass(V,b)}),q("["+O+"]",R,o)}),s=d.fn.qtip=function(a,b,e){var f=(""+a).toLowerCase(),g=z,i=d.makeArray(arguments).slice(1),j=i[i.length-1],k=this[0]?d.data(this[0],M):z;return!arguments.length&&k||"api"===f?k:"string"==typeof a?(this.each(function(){var a=d.data(this,M);if(!a)return x;if(j&&j.timeStamp&&(a.cache.event=j),!b||"option"!==f&&"options"!==f)a[f]&&a[f].apply(a,i);else{if(e===c&&!d.isPlainObject(b))return g=a.get(b),y;a.set(b,e)}}),g!==z?g:this):"object"!=typeof a&&arguments.length?void 0:(k=h(d.extend(x,{},a)),this.each(function(a){var b,c;return c=d.isArray(k.id)?k.id[a]:k.id,c=!c||c===y||c.length<1||s.api[c]?s.nextid++:c,b=r(d(this),c,k),b===y?x:(s.api[c]=b,d.each(L,function(){"initialize"===this.initialize&&this(b)}),void b._assignInitialEvents(j))}))},d.qtip=e,s.api={},d.each({attr:function(a,b){if(this.length){var c=this[0],e="title",f=d.data(c,"qtip");if(a===e&&f&&"object"==typeof f&&f.options.suppress)return arguments.length<2?d.attr(c,Y):(f&&f.options.content.attr===e&&f.cache.attr&&f.set("content.text",b),this.attr(Y,b))}return d.fn["attr"+X].apply(this,arguments)},clone:function(a){var b=(d([]),d.fn["clone"+X].apply(this,arguments));return a||b.filter("["+Y+"]").attr("title",function(){return d.attr(this,Y)}).removeAttr(Y),b}},function(a,b){if(!b||d.fn[a+X])return x;var c=d.fn[a+X]=d.fn[a];d.fn[a]=function(){return b.apply(this,arguments)||c.apply(this,arguments)}}),d.ui||(d["cleanData"+X]=d.cleanData,d.cleanData=function(a){for(var b,c=0;(b=d(a[c])).length;c++)if(b.attr(N))try{b.triggerHandler("removeqtip")}catch(e){}d["cleanData"+X].apply(this,arguments)}),s.version="2.2.1",s.nextid=0,s.inactiveEvents=R,s.zindex=15e3,s.defaults={prerender:y,id:y,overwrite:x,suppress:x,content:{text:x,attr:"title",title:y,button:y},position:{my:"top left",at:"bottom right",target:y,container:y,viewport:y,adjust:{x:0,y:0,mouse:x,scroll:x,resize:x,method:"flipinvert flipinvert"},effect:function(a,b){d(this).animate(b,{duration:200,queue:y})}},show:{target:y,event:"mouseenter",effect:x,delay:90,solo:y,ready:y,autofocus:y},hide:{target:y,event:"mouseleave",effect:x,delay:0,fixed:y,inactive:y,leave:"window",distance:y},style:{classes:"",widget:y,width:y,height:y,def:x},events:{render:z,move:z,show:z,hide:z,toggle:z,visible:z,hidden:z,focus:z,blur:z}},L.viewport=function(c,d,e,f,g,h,i){function j(a,b,c,e,f,g,h,i,j){var k=d[f],s=u[a],t=v[a],w=c===K,x=s===f?j:s===g?-j:-j/2,y=t===f?i:t===g?-i:-i/2,z=q[f]+r[f]-(n?0:m[f]),A=z-k,B=k+j-(h===C?o:p)-z,D=x-(u.precedance===a||s===u[b]?y:0)-(t===I?i/2:0);return w?(D=(s===f?1:-1)*x,d[f]+=A>0?A:B>0?-B:0,d[f]=Math.max(-m[f]+r[f],k-D,Math.min(Math.max(-m[f]+r[f]+(h===C?o:p),k+D),d[f],"center"===s?k-x:1e9))):(e*=c===J?2:0,A>0&&(s!==f||B>0)?(d[f]-=D+e,l.invert(a,f)):B>0&&(s!==g||A>0)&&(d[f]-=(s===I?-D:D)+e,l.invert(a,g)),d[f]<q&&-d[f]>B&&(d[f]=k,l=u.clone())),d[f]-k}var k,l,m,n,o,p,q,r,s=e.target,t=c.elements.tooltip,u=e.my,v=e.at,w=e.adjust,x=w.method.split(" "),z=x[0],L=x[1]||x[0],M=e.viewport,N=e.container,O=(c.cache,{left:0,top:0});return M.jquery&&s[0]!==a&&s[0]!==b.body&&"none"!==w.method?(m=N.offset()||O,n="static"===N.css("position"),k="fixed"===t.css("position"),o=M[0]===a?M.width():M.outerWidth(y),p=M[0]===a?M.height():M.outerHeight(y),q={left:k?0:M.scrollLeft(),top:k?0:M.scrollTop()},r=M.offset()||O,("shift"!==z||"shift"!==L)&&(l=u.clone()),O={left:"none"!==z?j(A,B,z,w.x,F,H,C,f,h):0,top:"none"!==L?j(B,A,L,w.y,E,G,D,g,i):0,my:l}):O}})}(window,document);

/* flot v0.8.3 | https://github.com/flot/flot | Copyright (c) 2007-2013 IOLA and Ole Laursen. Licensed under the MIT license. */
(function($){$.color={};$.color.make=function(r,g,b,a){var o={};o.r=r||0;o.g=g||0;o.b=b||0;o.a=a!=null?a:1;o.add=function(c,d){for(var i=0;i<c.length;++i)o[c.charAt(i)]+=d;return o.normalize()};o.scale=function(c,f){for(var i=0;i<c.length;++i)o[c.charAt(i)]*=f;return o.normalize()};o.toString=function(){if(o.a>=1){return"rgb("+[o.r,o.g,o.b].join(",")+")"}else{return"rgba("+[o.r,o.g,o.b,o.a].join(",")+")"}};o.normalize=function(){function clamp(min,value,max){return value<min?min:value>max?max:value}o.r=clamp(0,parseInt(o.r),255);o.g=clamp(0,parseInt(o.g),255);o.b=clamp(0,parseInt(o.b),255);o.a=clamp(0,o.a,1);return o};o.clone=function(){return $.color.make(o.r,o.b,o.g,o.a)};return o.normalize()};$.color.extract=function(elem,css){var c;do{c=elem.css(css).toLowerCase();if(c!=""&&c!="transparent")break;elem=elem.parent()}while(elem.length&&!$.nodeName(elem.get(0),"body"));if(c=="rgba(0, 0, 0, 0)")c="transparent";return $.color.parse(c)};$.color.parse=function(str){var res,m=$.color.make;if(res=/rgb\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*\)/.exec(str))return m(parseInt(res[1],10),parseInt(res[2],10),parseInt(res[3],10));if(res=/rgba\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]+(?:\.[0-9]+)?)\s*\)/.exec(str))return m(parseInt(res[1],10),parseInt(res[2],10),parseInt(res[3],10),parseFloat(res[4]));if(res=/rgb\(\s*([0-9]+(?:\.[0-9]+)?)\%\s*,\s*([0-9]+(?:\.[0-9]+)?)\%\s*,\s*([0-9]+(?:\.[0-9]+)?)\%\s*\)/.exec(str))return m(parseFloat(res[1])*2.55,parseFloat(res[2])*2.55,parseFloat(res[3])*2.55);if(res=/rgba\(\s*([0-9]+(?:\.[0-9]+)?)\%\s*,\s*([0-9]+(?:\.[0-9]+)?)\%\s*,\s*([0-9]+(?:\.[0-9]+)?)\%\s*,\s*([0-9]+(?:\.[0-9]+)?)\s*\)/.exec(str))return m(parseFloat(res[1])*2.55,parseFloat(res[2])*2.55,parseFloat(res[3])*2.55,parseFloat(res[4]));if(res=/#([a-fA-F0-9]{2})([a-fA-F0-9]{2})([a-fA-F0-9]{2})/.exec(str))return m(parseInt(res[1],16),parseInt(res[2],16),parseInt(res[3],16));if(res=/#([a-fA-F0-9])([a-fA-F0-9])([a-fA-F0-9])/.exec(str))return m(parseInt(res[1]+res[1],16),parseInt(res[2]+res[2],16),parseInt(res[3]+res[3],16));var name=$.trim(str).toLowerCase();if(name=="transparent")return m(255,255,255,0);else{res=lookupColors[name]||[0,0,0];return m(res[0],res[1],res[2])}};var lookupColors={aqua:[0,255,255],azure:[240,255,255],beige:[245,245,220],black:[0,0,0],blue:[0,0,255],brown:[165,42,42],cyan:[0,255,255],darkblue:[0,0,139],darkcyan:[0,139,139],darkgrey:[169,169,169],darkgreen:[0,100,0],darkkhaki:[189,183,107],darkmagenta:[139,0,139],darkolivegreen:[85,107,47],darkorange:[255,140,0],darkorchid:[153,50,204],darkred:[139,0,0],darksalmon:[233,150,122],darkviolet:[148,0,211],fuchsia:[255,0,255],gold:[255,215,0],green:[0,128,0],indigo:[75,0,130],khaki:[240,230,140],lightblue:[173,216,230],lightcyan:[224,255,255],lightgreen:[144,238,144],lightgrey:[211,211,211],lightpink:[255,182,193],lightyellow:[255,255,224],lime:[0,255,0],magenta:[255,0,255],maroon:[128,0,0],navy:[0,0,128],olive:[128,128,0],orange:[255,165,0],pink:[255,192,203],purple:[128,0,128],violet:[128,0,128],red:[255,0,0],silver:[192,192,192],white:[255,255,255],yellow:[255,255,0]}})(jQuery);(function($){var hasOwnProperty=Object.prototype.hasOwnProperty;if(!$.fn.detach){$.fn.detach=function(){return this.each(function(){if(this.parentNode){this.parentNode.removeChild(this)}})}}function Canvas(cls,container){var element=container.children("."+cls)[0];if(element==null){element=document.createElement("canvas");element.className=cls;$(element).css({direction:"ltr",position:"absolute",left:0,top:0}).appendTo(container);if(!element.getContext){if(window.G_vmlCanvasManager){element=window.G_vmlCanvasManager.initElement(element)}else{throw new Error("Canvas is not available. If you're using IE with a fall-back such as Excanvas, then there's either a mistake in your conditional include, or the page has no DOCTYPE and is rendering in Quirks Mode.")}}}this.element=element;var context=this.context=element.getContext("2d");var devicePixelRatio=window.devicePixelRatio||1,backingStoreRatio=context.webkitBackingStorePixelRatio||context.mozBackingStorePixelRatio||context.msBackingStorePixelRatio||context.oBackingStorePixelRatio||context.backingStorePixelRatio||1;this.pixelRatio=devicePixelRatio/backingStoreRatio;this.resize(container.width(),container.height());this.textContainer=null;this.text={};this._textCache={}}Canvas.prototype.resize=function(width,height){if(width<=0||height<=0){throw new Error("Invalid dimensions for plot, width = "+width+", height = "+height)}var element=this.element,context=this.context,pixelRatio=this.pixelRatio;if(this.width!=width){element.width=width*pixelRatio;element.style.width=width+"px";this.width=width}if(this.height!=height){element.height=height*pixelRatio;element.style.height=height+"px";this.height=height}context.restore();context.save();context.scale(pixelRatio,pixelRatio)};Canvas.prototype.clear=function(){this.context.clearRect(0,0,this.width,this.height)};Canvas.prototype.render=function(){var cache=this._textCache;for(var layerKey in cache){if(hasOwnProperty.call(cache,layerKey)){var layer=this.getTextLayer(layerKey),layerCache=cache[layerKey];layer.hide();for(var styleKey in layerCache){if(hasOwnProperty.call(layerCache,styleKey)){var styleCache=layerCache[styleKey];for(var key in styleCache){if(hasOwnProperty.call(styleCache,key)){var positions=styleCache[key].positions;for(var i=0,position;position=positions[i];i++){if(position.active){if(!position.rendered){layer.append(position.element);position.rendered=true}}else{positions.splice(i--,1);if(position.rendered){position.element.detach()}}}if(positions.length==0){delete styleCache[key]}}}}}layer.show()}}};Canvas.prototype.getTextLayer=function(classes){var layer=this.text[classes];if(layer==null){if(this.textContainer==null){this.textContainer=$("<div class='flot-text'></div>").css({position:"absolute",top:0,left:0,bottom:0,right:0,"font-size":"smaller",color:"#545454"}).insertAfter(this.element)}layer=this.text[classes]=$("<div></div>").addClass(classes).css({position:"absolute",top:0,left:0,bottom:0,right:0}).appendTo(this.textContainer)}return layer};Canvas.prototype.getTextInfo=function(layer,text,font,angle,width){var textStyle,layerCache,styleCache,info;text=""+text;if(typeof font==="object"){textStyle=font.style+" "+font.variant+" "+font.weight+" "+font.size+"px/"+font.lineHeight+"px "+font.family}else{textStyle=font}layerCache=this._textCache[layer];if(layerCache==null){layerCache=this._textCache[layer]={}}styleCache=layerCache[textStyle];if(styleCache==null){styleCache=layerCache[textStyle]={}}info=styleCache[text];if(info==null){var element=$("<div></div>").html(text).css({position:"absolute","max-width":width,top:-9999}).appendTo(this.getTextLayer(layer));if(typeof font==="object"){element.css({font:textStyle,color:font.color})}else if(typeof font==="string"){element.addClass(font)}info=styleCache[text]={width:element.outerWidth(true),height:element.outerHeight(true),element:element,positions:[]};element.detach()}return info};Canvas.prototype.addText=function(layer,x,y,text,font,angle,width,halign,valign){var info=this.getTextInfo(layer,text,font,angle,width),positions=info.positions;if(halign=="center"){x-=info.width/2}else if(halign=="right"){x-=info.width}if(valign=="middle"){y-=info.height/2}else if(valign=="bottom"){y-=info.height}for(var i=0,position;position=positions[i];i++){if(position.x==x&&position.y==y){position.active=true;return}}position={active:true,rendered:false,element:positions.length?info.element.clone():info.element,x:x,y:y};positions.push(position);position.element.css({top:Math.round(y),left:Math.round(x),"text-align":halign})};Canvas.prototype.removeText=function(layer,x,y,text,font,angle){if(text==null){var layerCache=this._textCache[layer];if(layerCache!=null){for(var styleKey in layerCache){if(hasOwnProperty.call(layerCache,styleKey)){var styleCache=layerCache[styleKey];for(var key in styleCache){if(hasOwnProperty.call(styleCache,key)){var positions=styleCache[key].positions;for(var i=0,position;position=positions[i];i++){position.active=false}}}}}}}else{var positions=this.getTextInfo(layer,text,font,angle).positions;for(var i=0,position;position=positions[i];i++){if(position.x==x&&position.y==y){position.active=false}}}};function Plot(placeholder,data_,options_,plugins){var series=[],options={colors:["#edc240","#afd8f8","#cb4b4b","#4da74d","#9440ed"],legend:{show:true,noColumns:1,labelFormatter:null,labelBoxBorderColor:"#ccc",container:null,position:"ne",margin:5,backgroundColor:null,backgroundOpacity:.85,sorted:null},xaxis:{show:null,position:"bottom",mode:null,font:null,color:null,tickColor:null,transform:null,inverseTransform:null,min:null,max:null,autoscaleMargin:null,ticks:null,tickFormatter:null,labelWidth:null,labelHeight:null,reserveSpace:null,tickLength:null,alignTicksWithAxis:null,tickDecimals:null,tickSize:null,minTickSize:null},yaxis:{autoscaleMargin:.02,position:"left"},xaxes:[],yaxes:[],series:{points:{show:false,radius:3,lineWidth:2,fill:true,fillColor:"#ffffff",symbol:"circle"},lines:{lineWidth:2,fill:false,fillColor:null,steps:false},bars:{show:false,lineWidth:2,barWidth:1,fill:true,fillColor:null,align:"left",horizontal:false,zero:true},shadowSize:3,highlightColor:null},grid:{show:true,aboveData:false,color:"#545454",backgroundColor:null,borderColor:null,tickColor:null,margin:0,labelMargin:5,axisMargin:8,borderWidth:2,minBorderMargin:null,markings:null,markingsColor:"#f4f4f4",markingsLineWidth:2,clickable:false,hoverable:false,autoHighlight:true,mouseActiveRadius:10},interaction:{redrawOverlayInterval:1e3/60},hooks:{}},surface=null,overlay=null,eventHolder=null,ctx=null,octx=null,xaxes=[],yaxes=[],plotOffset={left:0,right:0,top:0,bottom:0},plotWidth=0,plotHeight=0,hooks={processOptions:[],processRawData:[],processDatapoints:[],processOffset:[],drawBackground:[],drawSeries:[],draw:[],bindEvents:[],drawOverlay:[],shutdown:[]},plot=this;plot.setData=setData;plot.setupGrid=setupGrid;plot.draw=draw;plot.getPlaceholder=function(){return placeholder};plot.getCanvas=function(){return surface.element};plot.getPlotOffset=function(){return plotOffset};plot.width=function(){return plotWidth};plot.height=function(){return plotHeight};plot.offset=function(){var o=eventHolder.offset();o.left+=plotOffset.left;o.top+=plotOffset.top;return o};plot.getData=function(){return series};plot.getAxes=function(){var res={},i;$.each(xaxes.concat(yaxes),function(_,axis){if(axis)res[axis.direction+(axis.n!=1?axis.n:"")+"axis"]=axis});return res};plot.getXAxes=function(){return xaxes};plot.getYAxes=function(){return yaxes};plot.c2p=canvasToAxisCoords;plot.p2c=axisToCanvasCoords;plot.getOptions=function(){return options};plot.highlight=highlight;plot.unhighlight=unhighlight;plot.triggerRedrawOverlay=triggerRedrawOverlay;plot.pointOffset=function(point){return{left:parseInt(xaxes[axisNumber(point,"x")-1].p2c(+point.x)+plotOffset.left,10),top:parseInt(yaxes[axisNumber(point,"y")-1].p2c(+point.y)+plotOffset.top,10)}};plot.shutdown=shutdown;plot.destroy=function(){shutdown();placeholder.removeData("plot").empty();series=[];options=null;surface=null;overlay=null;eventHolder=null;ctx=null;octx=null;xaxes=[];yaxes=[];hooks=null;highlights=[];plot=null};plot.resize=function(){var width=placeholder.width(),height=placeholder.height();surface.resize(width,height);overlay.resize(width,height)};plot.hooks=hooks;initPlugins(plot);parseOptions(options_);setupCanvases();setData(data_);setupGrid();draw();bindEvents();function executeHooks(hook,args){args=[plot].concat(args);for(var i=0;i<hook.length;++i)hook[i].apply(this,args)}function initPlugins(){var classes={Canvas:Canvas};for(var i=0;i<plugins.length;++i){var p=plugins[i];p.init(plot,classes);if(p.options)$.extend(true,options,p.options)}}function parseOptions(opts){$.extend(true,options,opts);if(opts&&opts.colors){options.colors=opts.colors}if(options.xaxis.color==null)options.xaxis.color=$.color.parse(options.grid.color).scale("a",.22).toString();if(options.yaxis.color==null)options.yaxis.color=$.color.parse(options.grid.color).scale("a",.22).toString();if(options.xaxis.tickColor==null)options.xaxis.tickColor=options.grid.tickColor||options.xaxis.color;if(options.yaxis.tickColor==null)options.yaxis.tickColor=options.grid.tickColor||options.yaxis.color;if(options.grid.borderColor==null)options.grid.borderColor=options.grid.color;if(options.grid.tickColor==null)options.grid.tickColor=$.color.parse(options.grid.color).scale("a",.22).toString();var i,axisOptions,axisCount,fontSize=placeholder.css("font-size"),fontSizeDefault=fontSize?+fontSize.replace("px",""):13,fontDefaults={style:placeholder.css("font-style"),size:Math.round(.8*fontSizeDefault),variant:placeholder.css("font-variant"),weight:placeholder.css("font-weight"),family:placeholder.css("font-family")};axisCount=options.xaxes.length||1;for(i=0;i<axisCount;++i){axisOptions=options.xaxes[i];if(axisOptions&&!axisOptions.tickColor){axisOptions.tickColor=axisOptions.color}axisOptions=$.extend(true,{},options.xaxis,axisOptions);options.xaxes[i]=axisOptions;if(axisOptions.font){axisOptions.font=$.extend({},fontDefaults,axisOptions.font);if(!axisOptions.font.color){axisOptions.font.color=axisOptions.color}if(!axisOptions.font.lineHeight){axisOptions.font.lineHeight=Math.round(axisOptions.font.size*1.15)}}}axisCount=options.yaxes.length||1;for(i=0;i<axisCount;++i){axisOptions=options.yaxes[i];if(axisOptions&&!axisOptions.tickColor){axisOptions.tickColor=axisOptions.color}axisOptions=$.extend(true,{},options.yaxis,axisOptions);options.yaxes[i]=axisOptions;if(axisOptions.font){axisOptions.font=$.extend({},fontDefaults,axisOptions.font);if(!axisOptions.font.color){axisOptions.font.color=axisOptions.color}if(!axisOptions.font.lineHeight){axisOptions.font.lineHeight=Math.round(axisOptions.font.size*1.15)}}}if(options.xaxis.noTicks&&options.xaxis.ticks==null)options.xaxis.ticks=options.xaxis.noTicks;if(options.yaxis.noTicks&&options.yaxis.ticks==null)options.yaxis.ticks=options.yaxis.noTicks;if(options.x2axis){options.xaxes[1]=$.extend(true,{},options.xaxis,options.x2axis);options.xaxes[1].position="top";if(options.x2axis.min==null){options.xaxes[1].min=null}if(options.x2axis.max==null){options.xaxes[1].max=null}}if(options.y2axis){options.yaxes[1]=$.extend(true,{},options.yaxis,options.y2axis);options.yaxes[1].position="right";if(options.y2axis.min==null){options.yaxes[1].min=null}if(options.y2axis.max==null){options.yaxes[1].max=null}}if(options.grid.coloredAreas)options.grid.markings=options.grid.coloredAreas;if(options.grid.coloredAreasColor)options.grid.markingsColor=options.grid.coloredAreasColor;if(options.lines)$.extend(true,options.series.lines,options.lines);if(options.points)$.extend(true,options.series.points,options.points);if(options.bars)$.extend(true,options.series.bars,options.bars);if(options.shadowSize!=null)options.series.shadowSize=options.shadowSize;if(options.highlightColor!=null)options.series.highlightColor=options.highlightColor;for(i=0;i<options.xaxes.length;++i)getOrCreateAxis(xaxes,i+1).options=options.xaxes[i];for(i=0;i<options.yaxes.length;++i)getOrCreateAxis(yaxes,i+1).options=options.yaxes[i];for(var n in hooks)if(options.hooks[n]&&options.hooks[n].length)hooks[n]=hooks[n].concat(options.hooks[n]);executeHooks(hooks.processOptions,[options])}function setData(d){series=parseData(d);fillInSeriesOptions();processData()}function parseData(d){var res=[];for(var i=0;i<d.length;++i){var s=$.extend(true,{},options.series);if(d[i].data!=null){s.data=d[i].data;delete d[i].data;$.extend(true,s,d[i]);d[i].data=s.data}else s.data=d[i];res.push(s)}return res}function axisNumber(obj,coord){var a=obj[coord+"axis"];if(typeof a=="object")a=a.n;if(typeof a!="number")a=1;return a}function allAxes(){return $.grep(xaxes.concat(yaxes),function(a){return a})}function canvasToAxisCoords(pos){var res={},i,axis;for(i=0;i<xaxes.length;++i){axis=xaxes[i];if(axis&&axis.used)res["x"+axis.n]=axis.c2p(pos.left)}for(i=0;i<yaxes.length;++i){axis=yaxes[i];if(axis&&axis.used)res["y"+axis.n]=axis.c2p(pos.top)}if(res.x1!==undefined)res.x=res.x1;if(res.y1!==undefined)res.y=res.y1;return res}function axisToCanvasCoords(pos){var res={},i,axis,key;for(i=0;i<xaxes.length;++i){axis=xaxes[i];if(axis&&axis.used){key="x"+axis.n;if(pos[key]==null&&axis.n==1)key="x";if(pos[key]!=null){res.left=axis.p2c(pos[key]);break}}}for(i=0;i<yaxes.length;++i){axis=yaxes[i];if(axis&&axis.used){key="y"+axis.n;if(pos[key]==null&&axis.n==1)key="y";if(pos[key]!=null){res.top=axis.p2c(pos[key]);break}}}return res}function getOrCreateAxis(axes,number){if(!axes[number-1])axes[number-1]={n:number,direction:axes==xaxes?"x":"y",options:$.extend(true,{},axes==xaxes?options.xaxis:options.yaxis)};return axes[number-1]}function fillInSeriesOptions(){var neededColors=series.length,maxIndex=-1,i;for(i=0;i<series.length;++i){var sc=series[i].color;if(sc!=null){neededColors--;if(typeof sc=="number"&&sc>maxIndex){maxIndex=sc}}}if(neededColors<=maxIndex){neededColors=maxIndex+1}var c,colors=[],colorPool=options.colors,colorPoolSize=colorPool.length,variation=0;for(i=0;i<neededColors;i++){c=$.color.parse(colorPool[i%colorPoolSize]||"#666");if(i%colorPoolSize==0&&i){if(variation>=0){if(variation<.5){variation=-variation-.2}else variation=0}else variation=-variation}colors[i]=c.scale("rgb",1+variation)}var colori=0,s;for(i=0;i<series.length;++i){s=series[i];if(s.color==null){s.color=colors[colori].toString();++colori}else if(typeof s.color=="number")s.color=colors[s.color].toString();if(s.lines.show==null){var v,show=true;for(v in s)if(s[v]&&s[v].show){show=false;break}if(show)s.lines.show=true}if(s.lines.zero==null){s.lines.zero=!!s.lines.fill}s.xaxis=getOrCreateAxis(xaxes,axisNumber(s,"x"));s.yaxis=getOrCreateAxis(yaxes,axisNumber(s,"y"))}}function processData(){var topSentry=Number.POSITIVE_INFINITY,bottomSentry=Number.NEGATIVE_INFINITY,fakeInfinity=Number.MAX_VALUE,i,j,k,m,length,s,points,ps,x,y,axis,val,f,p,data,format;function updateAxis(axis,min,max){if(min<axis.datamin&&min!=-fakeInfinity)axis.datamin=min;if(max>axis.datamax&&max!=fakeInfinity)axis.datamax=max}$.each(allAxes(),function(_,axis){axis.datamin=topSentry;axis.datamax=bottomSentry;axis.used=false});for(i=0;i<series.length;++i){s=series[i];s.datapoints={points:[]};executeHooks(hooks.processRawData,[s,s.data,s.datapoints])}for(i=0;i<series.length;++i){s=series[i];data=s.data;format=s.datapoints.format;if(!format){format=[];format.push({x:true,number:true,required:true});format.push({y:true,number:true,required:true});if(s.bars.show||s.lines.show&&s.lines.fill){var autoscale=!!(s.bars.show&&s.bars.zero||s.lines.show&&s.lines.zero);format.push({y:true,number:true,required:false,defaultValue:0,autoscale:autoscale});if(s.bars.horizontal){delete format[format.length-1].y;format[format.length-1].x=true}}s.datapoints.format=format}if(s.datapoints.pointsize!=null)continue;s.datapoints.pointsize=format.length;ps=s.datapoints.pointsize;points=s.datapoints.points;var insertSteps=s.lines.show&&s.lines.steps;s.xaxis.used=s.yaxis.used=true;for(j=k=0;j<data.length;++j,k+=ps){p=data[j];var nullify=p==null;if(!nullify){for(m=0;m<ps;++m){val=p[m];f=format[m];if(f){if(f.number&&val!=null){val=+val;if(isNaN(val))val=null;else if(val==Infinity)val=fakeInfinity;else if(val==-Infinity)val=-fakeInfinity}if(val==null){if(f.required)nullify=true;if(f.defaultValue!=null)val=f.defaultValue}}points[k+m]=val}}if(nullify){for(m=0;m<ps;++m){val=points[k+m];if(val!=null){f=format[m];if(f.autoscale!==false){if(f.x){updateAxis(s.xaxis,val,val)}if(f.y){updateAxis(s.yaxis,val,val)}}}points[k+m]=null}}else{if(insertSteps&&k>0&&points[k-ps]!=null&&points[k-ps]!=points[k]&&points[k-ps+1]!=points[k+1]){for(m=0;m<ps;++m)points[k+ps+m]=points[k+m];points[k+1]=points[k-ps+1];k+=ps}}}}for(i=0;i<series.length;++i){s=series[i];executeHooks(hooks.processDatapoints,[s,s.datapoints])}for(i=0;i<series.length;++i){s=series[i];points=s.datapoints.points;ps=s.datapoints.pointsize;format=s.datapoints.format;var xmin=topSentry,ymin=topSentry,xmax=bottomSentry,ymax=bottomSentry;for(j=0;j<points.length;j+=ps){if(points[j]==null)continue;for(m=0;m<ps;++m){val=points[j+m];f=format[m];if(!f||f.autoscale===false||val==fakeInfinity||val==-fakeInfinity)continue;if(f.x){if(val<xmin)xmin=val;if(val>xmax)xmax=val}if(f.y){if(val<ymin)ymin=val;if(val>ymax)ymax=val}}}if(s.bars.show){var delta;switch(s.bars.align){case"left":delta=0;break;case"right":delta=-s.bars.barWidth;break;default:delta=-s.bars.barWidth/2}if(s.bars.horizontal){ymin+=delta;ymax+=delta+s.bars.barWidth}else{xmin+=delta;xmax+=delta+s.bars.barWidth}}updateAxis(s.xaxis,xmin,xmax);updateAxis(s.yaxis,ymin,ymax)}$.each(allAxes(),function(_,axis){if(axis.datamin==topSentry)axis.datamin=null;if(axis.datamax==bottomSentry)axis.datamax=null})}function setupCanvases(){placeholder.css("padding",0).children().filter(function(){return!$(this).hasClass("flot-overlay")&&!$(this).hasClass("flot-base")}).remove();if(placeholder.css("position")=="static")placeholder.css("position","relative");surface=new Canvas("flot-base",placeholder);overlay=new Canvas("flot-overlay",placeholder);ctx=surface.context;octx=overlay.context;eventHolder=$(overlay.element).unbind();var existing=placeholder.data("plot");if(existing){existing.shutdown();overlay.clear()}placeholder.data("plot",plot)}function bindEvents(){if(options.grid.hoverable){eventHolder.mousemove(onMouseMove);eventHolder.bind("mouseleave",onMouseLeave)}if(options.grid.clickable)eventHolder.click(onClick);executeHooks(hooks.bindEvents,[eventHolder])}function shutdown(){if(redrawTimeout)clearTimeout(redrawTimeout);eventHolder.unbind("mousemove",onMouseMove);eventHolder.unbind("mouseleave",onMouseLeave);eventHolder.unbind("click",onClick);executeHooks(hooks.shutdown,[eventHolder])}function setTransformationHelpers(axis){function identity(x){return x}var s,m,t=axis.options.transform||identity,it=axis.options.inverseTransform;if(axis.direction=="x"){s=axis.scale=plotWidth/Math.abs(t(axis.max)-t(axis.min));m=Math.min(t(axis.max),t(axis.min))}else{s=axis.scale=plotHeight/Math.abs(t(axis.max)-t(axis.min));s=-s;m=Math.max(t(axis.max),t(axis.min))}if(t==identity)axis.p2c=function(p){return(p-m)*s};else axis.p2c=function(p){return(t(p)-m)*s};if(!it)axis.c2p=function(c){return m+c/s};else axis.c2p=function(c){return it(m+c/s)}}function measureTickLabels(axis){var opts=axis.options,ticks=axis.ticks||[],labelWidth=opts.labelWidth||0,labelHeight=opts.labelHeight||0,maxWidth=labelWidth||(axis.direction=="x"?Math.floor(surface.width/(ticks.length||1)):null),legacyStyles=axis.direction+"Axis "+axis.direction+axis.n+"Axis",layer="flot-"+axis.direction+"-axis flot-"+axis.direction+axis.n+"-axis "+legacyStyles,font=opts.font||"flot-tick-label tickLabel";for(var i=0;i<ticks.length;++i){var t=ticks[i];if(!t.label)continue;var info=surface.getTextInfo(layer,t.label,font,null,maxWidth);labelWidth=Math.max(labelWidth,info.width);labelHeight=Math.max(labelHeight,info.height)}axis.labelWidth=opts.labelWidth||labelWidth;axis.labelHeight=opts.labelHeight||labelHeight}function allocateAxisBoxFirstPhase(axis){var lw=axis.labelWidth,lh=axis.labelHeight,pos=axis.options.position,isXAxis=axis.direction==="x",tickLength=axis.options.tickLength,axisMargin=options.grid.axisMargin,padding=options.grid.labelMargin,innermost=true,outermost=true,first=true,found=false;$.each(isXAxis?xaxes:yaxes,function(i,a){if(a&&(a.show||a.reserveSpace)){if(a===axis){found=true}else if(a.options.position===pos){if(found){outermost=false}else{innermost=false}}if(!found){first=false}}});if(outermost){axisMargin=0}if(tickLength==null){tickLength=first?"full":5}if(!isNaN(+tickLength))padding+=+tickLength;if(isXAxis){lh+=padding;if(pos=="bottom"){plotOffset.bottom+=lh+axisMargin;axis.box={top:surface.height-plotOffset.bottom,height:lh}}else{axis.box={top:plotOffset.top+axisMargin,height:lh};plotOffset.top+=lh+axisMargin}}else{lw+=padding;if(pos=="left"){axis.box={left:plotOffset.left+axisMargin,width:lw};plotOffset.left+=lw+axisMargin}else{plotOffset.right+=lw+axisMargin;axis.box={left:surface.width-plotOffset.right,width:lw}}}axis.position=pos;axis.tickLength=tickLength;axis.box.padding=padding;axis.innermost=innermost}function allocateAxisBoxSecondPhase(axis){if(axis.direction=="x"){axis.box.left=plotOffset.left-axis.labelWidth/2;axis.box.width=surface.width-plotOffset.left-plotOffset.right+axis.labelWidth}else{axis.box.top=plotOffset.top-axis.labelHeight/2;axis.box.height=surface.height-plotOffset.bottom-plotOffset.top+axis.labelHeight}}function adjustLayoutForThingsStickingOut(){var minMargin=options.grid.minBorderMargin,axis,i;if(minMargin==null){minMargin=0;for(i=0;i<series.length;++i)minMargin=Math.max(minMargin,2*(series[i].points.radius+series[i].points.lineWidth/2))}var margins={left:minMargin,right:minMargin,top:minMargin,bottom:minMargin};$.each(allAxes(),function(_,axis){if(axis.reserveSpace&&axis.ticks&&axis.ticks.length){if(axis.direction==="x"){margins.left=Math.max(margins.left,axis.labelWidth/2);margins.right=Math.max(margins.right,axis.labelWidth/2)}else{margins.bottom=Math.max(margins.bottom,axis.labelHeight/2);margins.top=Math.max(margins.top,axis.labelHeight/2)}}});plotOffset.left=Math.ceil(Math.max(margins.left,plotOffset.left));plotOffset.right=Math.ceil(Math.max(margins.right,plotOffset.right));plotOffset.top=Math.ceil(Math.max(margins.top,plotOffset.top));plotOffset.bottom=Math.ceil(Math.max(margins.bottom,plotOffset.bottom))}function setupGrid(){var i,axes=allAxes(),showGrid=options.grid.show;for(var a in plotOffset){var margin=options.grid.margin||0;plotOffset[a]=typeof margin=="number"?margin:margin[a]||0}executeHooks(hooks.processOffset,[plotOffset]);for(var a in plotOffset){if(typeof options.grid.borderWidth=="object"){plotOffset[a]+=showGrid?options.grid.borderWidth[a]:0}else{plotOffset[a]+=showGrid?options.grid.borderWidth:0}}$.each(axes,function(_,axis){var axisOpts=axis.options;axis.show=axisOpts.show==null?axis.used:axisOpts.show;axis.reserveSpace=axisOpts.reserveSpace==null?axis.show:axisOpts.reserveSpace;setRange(axis)});if(showGrid){var allocatedAxes=$.grep(axes,function(axis){return axis.show||axis.reserveSpace});$.each(allocatedAxes,function(_,axis){setupTickGeneration(axis);setTicks(axis);snapRangeToTicks(axis,axis.ticks);measureTickLabels(axis)});for(i=allocatedAxes.length-1;i>=0;--i)allocateAxisBoxFirstPhase(allocatedAxes[i]);adjustLayoutForThingsStickingOut();$.each(allocatedAxes,function(_,axis){allocateAxisBoxSecondPhase(axis)})}plotWidth=surface.width-plotOffset.left-plotOffset.right;plotHeight=surface.height-plotOffset.bottom-plotOffset.top;$.each(axes,function(_,axis){setTransformationHelpers(axis)});if(showGrid){drawAxisLabels()}insertLegend()}function setRange(axis){var opts=axis.options,min=+(opts.min!=null?opts.min:axis.datamin),max=+(opts.max!=null?opts.max:axis.datamax),delta=max-min;if(delta==0){var widen=max==0?1:.01;if(opts.min==null)min-=widen;if(opts.max==null||opts.min!=null)max+=widen}else{var margin=opts.autoscaleMargin;if(margin!=null){if(opts.min==null){min-=delta*margin;if(min<0&&axis.datamin!=null&&axis.datamin>=0)min=0}if(opts.max==null){max+=delta*margin;if(max>0&&axis.datamax!=null&&axis.datamax<=0)max=0}}}axis.min=min;axis.max=max}function setupTickGeneration(axis){var opts=axis.options;var noTicks;if(typeof opts.ticks=="number"&&opts.ticks>0)noTicks=opts.ticks;else noTicks=.3*Math.sqrt(axis.direction=="x"?surface.width:surface.height);var delta=(axis.max-axis.min)/noTicks,dec=-Math.floor(Math.log(delta)/Math.LN10),maxDec=opts.tickDecimals;if(maxDec!=null&&dec>maxDec){dec=maxDec}var magn=Math.pow(10,-dec),norm=delta/magn,size;if(norm<1.5){size=1}else if(norm<3){size=2;if(norm>2.25&&(maxDec==null||dec+1<=maxDec)){size=2.5;++dec}}else if(norm<7.5){size=5}else{size=10}size*=magn;if(opts.minTickSize!=null&&size<opts.minTickSize){size=opts.minTickSize}axis.delta=delta;axis.tickDecimals=Math.max(0,maxDec!=null?maxDec:dec);axis.tickSize=opts.tickSize||size;if(opts.mode=="time"&&!axis.tickGenerator){throw new Error("Time mode requires the flot.time plugin.")}if(!axis.tickGenerator){axis.tickGenerator=function(axis){var ticks=[],start=floorInBase(axis.min,axis.tickSize),i=0,v=Number.NaN,prev;do{prev=v;v=start+i*axis.tickSize;ticks.push(v);++i}while(v<axis.max&&v!=prev);return ticks};axis.tickFormatter=function(value,axis){var factor=axis.tickDecimals?Math.pow(10,axis.tickDecimals):1;var formatted=""+Math.round(value*factor)/factor;if(axis.tickDecimals!=null){var decimal=formatted.indexOf(".");var precision=decimal==-1?0:formatted.length-decimal-1;if(precision<axis.tickDecimals){return(precision?formatted:formatted+".")+(""+factor).substr(1,axis.tickDecimals-precision)}}return formatted}}if($.isFunction(opts.tickFormatter))axis.tickFormatter=function(v,axis){return""+opts.tickFormatter(v,axis)};if(opts.alignTicksWithAxis!=null){var otherAxis=(axis.direction=="x"?xaxes:yaxes)[opts.alignTicksWithAxis-1];if(otherAxis&&otherAxis.used&&otherAxis!=axis){var niceTicks=axis.tickGenerator(axis);if(niceTicks.length>0){if(opts.min==null)axis.min=Math.min(axis.min,niceTicks[0]);if(opts.max==null&&niceTicks.length>1)axis.max=Math.max(axis.max,niceTicks[niceTicks.length-1])}axis.tickGenerator=function(axis){var ticks=[],v,i;for(i=0;i<otherAxis.ticks.length;++i){v=(otherAxis.ticks[i].v-otherAxis.min)/(otherAxis.max-otherAxis.min);v=axis.min+v*(axis.max-axis.min);ticks.push(v)}return ticks};if(!axis.mode&&opts.tickDecimals==null){var extraDec=Math.max(0,-Math.floor(Math.log(axis.delta)/Math.LN10)+1),ts=axis.tickGenerator(axis);if(!(ts.length>1&&/\..*0$/.test((ts[1]-ts[0]).toFixed(extraDec))))axis.tickDecimals=extraDec}}}}function setTicks(axis){var oticks=axis.options.ticks,ticks=[];if(oticks==null||typeof oticks=="number"&&oticks>0)ticks=axis.tickGenerator(axis);else if(oticks){if($.isFunction(oticks))ticks=oticks(axis);else ticks=oticks}var i,v;axis.ticks=[];for(i=0;i<ticks.length;++i){var label=null;var t=ticks[i];if(typeof t=="object"){v=+t[0];if(t.length>1)label=t[1]}else v=+t;if(label==null)label=axis.tickFormatter(v,axis);if(!isNaN(v))axis.ticks.push({v:v,label:label})}}function snapRangeToTicks(axis,ticks){if(axis.options.autoscaleMargin&&ticks.length>0){if(axis.options.min==null)axis.min=Math.min(axis.min,ticks[0].v);if(axis.options.max==null&&ticks.length>1)axis.max=Math.max(axis.max,ticks[ticks.length-1].v)}}function draw(){surface.clear();executeHooks(hooks.drawBackground,[ctx]);var grid=options.grid;if(grid.show&&grid.backgroundColor)drawBackground();if(grid.show&&!grid.aboveData){drawGrid()}for(var i=0;i<series.length;++i){executeHooks(hooks.drawSeries,[ctx,series[i]]);drawSeries(series[i])}executeHooks(hooks.draw,[ctx]);if(grid.show&&grid.aboveData){drawGrid()}surface.render();triggerRedrawOverlay()}function extractRange(ranges,coord){var axis,from,to,key,axes=allAxes();for(var i=0;i<axes.length;++i){axis=axes[i];if(axis.direction==coord){key=coord+axis.n+"axis";if(!ranges[key]&&axis.n==1)key=coord+"axis";if(ranges[key]){from=ranges[key].from;to=ranges[key].to;break}}}if(!ranges[key]){axis=coord=="x"?xaxes[0]:yaxes[0];from=ranges[coord+"1"];to=ranges[coord+"2"]}if(from!=null&&to!=null&&from>to){var tmp=from;from=to;to=tmp}return{from:from,to:to,axis:axis}}function drawBackground(){ctx.save();ctx.translate(plotOffset.left,plotOffset.top);ctx.fillStyle=getColorOrGradient(options.grid.backgroundColor,plotHeight,0,"rgba(255, 255, 255, 0)");ctx.fillRect(0,0,plotWidth,plotHeight);ctx.restore()}function drawGrid(){var i,axes,bw,bc;ctx.save();ctx.translate(plotOffset.left,plotOffset.top);var markings=options.grid.markings;if(markings){if($.isFunction(markings)){axes=plot.getAxes();axes.xmin=axes.xaxis.min;axes.xmax=axes.xaxis.max;axes.ymin=axes.yaxis.min;axes.ymax=axes.yaxis.max;markings=markings(axes)}for(i=0;i<markings.length;++i){var m=markings[i],xrange=extractRange(m,"x"),yrange=extractRange(m,"y");if(xrange.from==null)xrange.from=xrange.axis.min;if(xrange.to==null)xrange.to=xrange.axis.max;if(yrange.from==null)yrange.from=yrange.axis.min;if(yrange.to==null)yrange.to=yrange.axis.max;if(xrange.to<xrange.axis.min||xrange.from>xrange.axis.max||yrange.to<yrange.axis.min||yrange.from>yrange.axis.max)continue;xrange.from=Math.max(xrange.from,xrange.axis.min);xrange.to=Math.min(xrange.to,xrange.axis.max);yrange.from=Math.max(yrange.from,yrange.axis.min);yrange.to=Math.min(yrange.to,yrange.axis.max);var xequal=xrange.from===xrange.to,yequal=yrange.from===yrange.to;if(xequal&&yequal){continue}xrange.from=Math.floor(xrange.axis.p2c(xrange.from));xrange.to=Math.floor(xrange.axis.p2c(xrange.to));yrange.from=Math.floor(yrange.axis.p2c(yrange.from));yrange.to=Math.floor(yrange.axis.p2c(yrange.to));if(xequal||yequal){var lineWidth=m.lineWidth||options.grid.markingsLineWidth,subPixel=lineWidth%2?.5:0;ctx.beginPath();ctx.strokeStyle=m.color||options.grid.markingsColor;ctx.lineWidth=lineWidth;if(xequal){ctx.moveTo(xrange.to+subPixel,yrange.from);ctx.lineTo(xrange.to+subPixel,yrange.to)}else{ctx.moveTo(xrange.from,yrange.to+subPixel);ctx.lineTo(xrange.to,yrange.to+subPixel)}ctx.stroke()}else{ctx.fillStyle=m.color||options.grid.markingsColor;ctx.fillRect(xrange.from,yrange.to,xrange.to-xrange.from,yrange.from-yrange.to)}}}axes=allAxes();bw=options.grid.borderWidth;for(var j=0;j<axes.length;++j){var axis=axes[j],box=axis.box,t=axis.tickLength,x,y,xoff,yoff;if(!axis.show||axis.ticks.length==0)continue;ctx.lineWidth=1;if(axis.direction=="x"){x=0;if(t=="full")y=axis.position=="top"?0:plotHeight;else y=box.top-plotOffset.top+(axis.position=="top"?box.height:0)}else{y=0;if(t=="full")x=axis.position=="left"?0:plotWidth;else x=box.left-plotOffset.left+(axis.position=="left"?box.width:0)}if(!axis.innermost){ctx.strokeStyle=axis.options.color;ctx.beginPath();xoff=yoff=0;if(axis.direction=="x")xoff=plotWidth+1;else yoff=plotHeight+1;if(ctx.lineWidth==1){if(axis.direction=="x"){y=Math.floor(y)+.5}else{x=Math.floor(x)+.5}}ctx.moveTo(x,y);ctx.lineTo(x+xoff,y+yoff);ctx.stroke()}ctx.strokeStyle=axis.options.tickColor;ctx.beginPath();for(i=0;i<axis.ticks.length;++i){var v=axis.ticks[i].v;xoff=yoff=0;if(isNaN(v)||v<axis.min||v>axis.max||t=="full"&&(typeof bw=="object"&&bw[axis.position]>0||bw>0)&&(v==axis.min||v==axis.max))continue;if(axis.direction=="x"){x=axis.p2c(v);yoff=t=="full"?-plotHeight:t;if(axis.position=="top")yoff=-yoff}else{y=axis.p2c(v);xoff=t=="full"?-plotWidth:t;if(axis.position=="left")xoff=-xoff}if(ctx.lineWidth==1){if(axis.direction=="x")x=Math.floor(x)+.5;else y=Math.floor(y)+.5}ctx.moveTo(x,y);ctx.lineTo(x+xoff,y+yoff)}ctx.stroke()}if(bw){bc=options.grid.borderColor;if(typeof bw=="object"||typeof bc=="object"){if(typeof bw!=="object"){bw={top:bw,right:bw,bottom:bw,left:bw}}if(typeof bc!=="object"){bc={top:bc,right:bc,bottom:bc,left:bc}}if(bw.top>0){ctx.strokeStyle=bc.top;ctx.lineWidth=bw.top;ctx.beginPath();ctx.moveTo(0-bw.left,0-bw.top/2);ctx.lineTo(plotWidth,0-bw.top/2);ctx.stroke()}if(bw.right>0){ctx.strokeStyle=bc.right;ctx.lineWidth=bw.right;ctx.beginPath();ctx.moveTo(plotWidth+bw.right/2,0-bw.top);ctx.lineTo(plotWidth+bw.right/2,plotHeight);ctx.stroke()}if(bw.bottom>0){ctx.strokeStyle=bc.bottom;ctx.lineWidth=bw.bottom;ctx.beginPath();ctx.moveTo(plotWidth+bw.right,plotHeight+bw.bottom/2);ctx.lineTo(0,plotHeight+bw.bottom/2);ctx.stroke()}if(bw.left>0){ctx.strokeStyle=bc.left;ctx.lineWidth=bw.left;ctx.beginPath();ctx.moveTo(0-bw.left/2,plotHeight+bw.bottom);ctx.lineTo(0-bw.left/2,0);ctx.stroke()}}else{ctx.lineWidth=bw;ctx.strokeStyle=options.grid.borderColor;ctx.strokeRect(-bw/2,-bw/2,plotWidth+bw,plotHeight+bw)}}ctx.restore()}function drawAxisLabels(){$.each(allAxes(),function(_,axis){var box=axis.box,legacyStyles=axis.direction+"Axis "+axis.direction+axis.n+"Axis",layer="flot-"+axis.direction+"-axis flot-"+axis.direction+axis.n+"-axis "+legacyStyles,font=axis.options.font||"flot-tick-label tickLabel",tick,x,y,halign,valign;surface.removeText(layer);if(!axis.show||axis.ticks.length==0)return;for(var i=0;i<axis.ticks.length;++i){tick=axis.ticks[i];if(!tick.label||tick.v<axis.min||tick.v>axis.max)continue;if(axis.direction=="x"){halign="center";x=plotOffset.left+axis.p2c(tick.v);if(axis.position=="bottom"){y=box.top+box.padding}else{y=box.top+box.height-box.padding;valign="bottom"}}else{valign="middle";y=plotOffset.top+axis.p2c(tick.v);if(axis.position=="left"){x=box.left+box.width-box.padding;halign="right"}else{x=box.left+box.padding}}surface.addText(layer,x,y,tick.label,font,null,null,halign,valign)}})}function drawSeries(series){if(series.lines.show)drawSeriesLines(series);if(series.bars.show)drawSeriesBars(series);if(series.points.show)drawSeriesPoints(series)}function drawSeriesLines(series){function plotLine(datapoints,xoffset,yoffset,axisx,axisy){var points=datapoints.points,ps=datapoints.pointsize,prevx=null,prevy=null;ctx.beginPath();for(var i=ps;i<points.length;i+=ps){var x1=points[i-ps],y1=points[i-ps+1],x2=points[i],y2=points[i+1];if(x1==null||x2==null)continue;if(y1<=y2&&y1<axisy.min){if(y2<axisy.min)continue;x1=(axisy.min-y1)/(y2-y1)*(x2-x1)+x1;y1=axisy.min}else if(y2<=y1&&y2<axisy.min){if(y1<axisy.min)continue;x2=(axisy.min-y1)/(y2-y1)*(x2-x1)+x1;y2=axisy.min}if(y1>=y2&&y1>axisy.max){if(y2>axisy.max)continue;x1=(axisy.max-y1)/(y2-y1)*(x2-x1)+x1;y1=axisy.max}else if(y2>=y1&&y2>axisy.max){if(y1>axisy.max)continue;x2=(axisy.max-y1)/(y2-y1)*(x2-x1)+x1;y2=axisy.max}if(x1<=x2&&x1<axisx.min){if(x2<axisx.min)continue;y1=(axisx.min-x1)/(x2-x1)*(y2-y1)+y1;x1=axisx.min}else if(x2<=x1&&x2<axisx.min){if(x1<axisx.min)continue;y2=(axisx.min-x1)/(x2-x1)*(y2-y1)+y1;x2=axisx.min}if(x1>=x2&&x1>axisx.max){if(x2>axisx.max)continue;y1=(axisx.max-x1)/(x2-x1)*(y2-y1)+y1;x1=axisx.max}else if(x2>=x1&&x2>axisx.max){if(x1>axisx.max)continue;y2=(axisx.max-x1)/(x2-x1)*(y2-y1)+y1;x2=axisx.max}if(x1!=prevx||y1!=prevy)ctx.moveTo(axisx.p2c(x1)+xoffset,axisy.p2c(y1)+yoffset);prevx=x2;prevy=y2;ctx.lineTo(axisx.p2c(x2)+xoffset,axisy.p2c(y2)+yoffset)}ctx.stroke()}function plotLineArea(datapoints,axisx,axisy){var points=datapoints.points,ps=datapoints.pointsize,bottom=Math.min(Math.max(0,axisy.min),axisy.max),i=0,top,areaOpen=false,ypos=1,segmentStart=0,segmentEnd=0;while(true){if(ps>0&&i>points.length+ps)break;i+=ps;var x1=points[i-ps],y1=points[i-ps+ypos],x2=points[i],y2=points[i+ypos];if(areaOpen){if(ps>0&&x1!=null&&x2==null){segmentEnd=i;ps=-ps;ypos=2;continue}if(ps<0&&i==segmentStart+ps){ctx.fill();areaOpen=false;ps=-ps;ypos=1;i=segmentStart=segmentEnd+ps;continue}}if(x1==null||x2==null)continue;if(x1<=x2&&x1<axisx.min){if(x2<axisx.min)continue;y1=(axisx.min-x1)/(x2-x1)*(y2-y1)+y1;x1=axisx.min}else if(x2<=x1&&x2<axisx.min){if(x1<axisx.min)continue;y2=(axisx.min-x1)/(x2-x1)*(y2-y1)+y1;x2=axisx.min}if(x1>=x2&&x1>axisx.max){if(x2>axisx.max)continue;y1=(axisx.max-x1)/(x2-x1)*(y2-y1)+y1;x1=axisx.max}else if(x2>=x1&&x2>axisx.max){if(x1>axisx.max)continue;y2=(axisx.max-x1)/(x2-x1)*(y2-y1)+y1;x2=axisx.max}if(!areaOpen){ctx.beginPath();ctx.moveTo(axisx.p2c(x1),axisy.p2c(bottom));areaOpen=true}if(y1>=axisy.max&&y2>=axisy.max){ctx.lineTo(axisx.p2c(x1),axisy.p2c(axisy.max));ctx.lineTo(axisx.p2c(x2),axisy.p2c(axisy.max));continue}else if(y1<=axisy.min&&y2<=axisy.min){ctx.lineTo(axisx.p2c(x1),axisy.p2c(axisy.min));ctx.lineTo(axisx.p2c(x2),axisy.p2c(axisy.min));continue}var x1old=x1,x2old=x2;if(y1<=y2&&y1<axisy.min&&y2>=axisy.min){x1=(axisy.min-y1)/(y2-y1)*(x2-x1)+x1;y1=axisy.min}else if(y2<=y1&&y2<axisy.min&&y1>=axisy.min){x2=(axisy.min-y1)/(y2-y1)*(x2-x1)+x1;y2=axisy.min}if(y1>=y2&&y1>axisy.max&&y2<=axisy.max){x1=(axisy.max-y1)/(y2-y1)*(x2-x1)+x1;y1=axisy.max}else if(y2>=y1&&y2>axisy.max&&y1<=axisy.max){x2=(axisy.max-y1)/(y2-y1)*(x2-x1)+x1;y2=axisy.max}if(x1!=x1old){ctx.lineTo(axisx.p2c(x1old),axisy.p2c(y1))}ctx.lineTo(axisx.p2c(x1),axisy.p2c(y1));ctx.lineTo(axisx.p2c(x2),axisy.p2c(y2));if(x2!=x2old){ctx.lineTo(axisx.p2c(x2),axisy.p2c(y2));ctx.lineTo(axisx.p2c(x2old),axisy.p2c(y2))}}}ctx.save();ctx.translate(plotOffset.left,plotOffset.top);ctx.lineJoin="round";var lw=series.lines.lineWidth,sw=series.shadowSize;if(lw>0&&sw>0){ctx.lineWidth=sw;ctx.strokeStyle="rgba(0,0,0,0.1)";var angle=Math.PI/18;plotLine(series.datapoints,Math.sin(angle)*(lw/2+sw/2),Math.cos(angle)*(lw/2+sw/2),series.xaxis,series.yaxis);ctx.lineWidth=sw/2;plotLine(series.datapoints,Math.sin(angle)*(lw/2+sw/4),Math.cos(angle)*(lw/2+sw/4),series.xaxis,series.yaxis)}ctx.lineWidth=lw;ctx.strokeStyle=series.color;var fillStyle=getFillStyle(series.lines,series.color,0,plotHeight);if(fillStyle){ctx.fillStyle=fillStyle;plotLineArea(series.datapoints,series.xaxis,series.yaxis)}if(lw>0)plotLine(series.datapoints,0,0,series.xaxis,series.yaxis);ctx.restore()}function drawSeriesPoints(series){function plotPoints(datapoints,radius,fillStyle,offset,shadow,axisx,axisy,symbol){var points=datapoints.points,ps=datapoints.pointsize;for(var i=0;i<points.length;i+=ps){var x=points[i],y=points[i+1];if(x==null||x<axisx.min||x>axisx.max||y<axisy.min||y>axisy.max)continue;ctx.beginPath();x=axisx.p2c(x);y=axisy.p2c(y)+offset;if(symbol=="circle")ctx.arc(x,y,radius,0,shadow?Math.PI:Math.PI*2,false);else symbol(ctx,x,y,radius,shadow);ctx.closePath();if(fillStyle){ctx.fillStyle=fillStyle;ctx.fill()}ctx.stroke()}}ctx.save();ctx.translate(plotOffset.left,plotOffset.top);var lw=series.points.lineWidth,sw=series.shadowSize,radius=series.points.radius,symbol=series.points.symbol;if(lw==0)lw=1e-4;if(lw>0&&sw>0){var w=sw/2;ctx.lineWidth=w;ctx.strokeStyle="rgba(0,0,0,0.1)";plotPoints(series.datapoints,radius,null,w+w/2,true,series.xaxis,series.yaxis,symbol);ctx.strokeStyle="rgba(0,0,0,0.2)";plotPoints(series.datapoints,radius,null,w/2,true,series.xaxis,series.yaxis,symbol)}ctx.lineWidth=lw;ctx.strokeStyle=series.color;plotPoints(series.datapoints,radius,getFillStyle(series.points,series.color),0,false,series.xaxis,series.yaxis,symbol);ctx.restore()}function drawBar(x,y,b,barLeft,barRight,fillStyleCallback,axisx,axisy,c,horizontal,lineWidth){var left,right,bottom,top,drawLeft,drawRight,drawTop,drawBottom,tmp;if(horizontal){drawBottom=drawRight=drawTop=true;drawLeft=false;left=b;right=x;top=y+barLeft;bottom=y+barRight;if(right<left){tmp=right;right=left;left=tmp;drawLeft=true;drawRight=false}}else{drawLeft=drawRight=drawTop=true;drawBottom=false;left=x+barLeft;right=x+barRight;bottom=b;top=y;if(top<bottom){tmp=top;top=bottom;bottom=tmp;drawBottom=true;drawTop=false}}if(right<axisx.min||left>axisx.max||top<axisy.min||bottom>axisy.max)return;if(left<axisx.min){left=axisx.min;drawLeft=false}if(right>axisx.max){right=axisx.max;drawRight=false}if(bottom<axisy.min){bottom=axisy.min;drawBottom=false}if(top>axisy.max){top=axisy.max;drawTop=false}left=axisx.p2c(left);bottom=axisy.p2c(bottom);right=axisx.p2c(right);top=axisy.p2c(top);if(fillStyleCallback){c.fillStyle=fillStyleCallback(bottom,top);c.fillRect(left,top,right-left,bottom-top)}if(lineWidth>0&&(drawLeft||drawRight||drawTop||drawBottom)){c.beginPath();c.moveTo(left,bottom);if(drawLeft)c.lineTo(left,top);else c.moveTo(left,top);if(drawTop)c.lineTo(right,top);else c.moveTo(right,top);if(drawRight)c.lineTo(right,bottom);else c.moveTo(right,bottom);if(drawBottom)c.lineTo(left,bottom);else c.moveTo(left,bottom);c.stroke()}}function drawSeriesBars(series){function plotBars(datapoints,barLeft,barRight,fillStyleCallback,axisx,axisy){var points=datapoints.points,ps=datapoints.pointsize;for(var i=0;i<points.length;i+=ps){if(points[i]==null)continue;drawBar(points[i],points[i+1],points[i+2],barLeft,barRight,fillStyleCallback,axisx,axisy,ctx,series.bars.horizontal,series.bars.lineWidth)}}ctx.save();ctx.translate(plotOffset.left,plotOffset.top);ctx.lineWidth=series.bars.lineWidth;ctx.strokeStyle=series.color;var barLeft;switch(series.bars.align){case"left":barLeft=0;break;case"right":barLeft=-series.bars.barWidth;break;default:barLeft=-series.bars.barWidth/2}var fillStyleCallback=series.bars.fill?function(bottom,top){return getFillStyle(series.bars,series.color,bottom,top)}:null;plotBars(series.datapoints,barLeft,barLeft+series.bars.barWidth,fillStyleCallback,series.xaxis,series.yaxis);ctx.restore()}function getFillStyle(filloptions,seriesColor,bottom,top){var fill=filloptions.fill;if(!fill)return null;if(filloptions.fillColor)return getColorOrGradient(filloptions.fillColor,bottom,top,seriesColor);var c=$.color.parse(seriesColor);c.a=typeof fill=="number"?fill:.4;c.normalize();return c.toString()}function insertLegend(){if(options.legend.container!=null){$(options.legend.container).html("")}else{placeholder.find(".legend").remove()}if(!options.legend.show){return}var fragments=[],entries=[],rowStarted=false,lf=options.legend.labelFormatter,s,label;for(var i=0;i<series.length;++i){s=series[i];if(s.label){label=lf?lf(s.label,s):s.label;if(label){entries.push({label:label,color:s.color})}}}if(options.legend.sorted){if($.isFunction(options.legend.sorted)){entries.sort(options.legend.sorted)}else if(options.legend.sorted=="reverse"){entries.reverse()}else{var ascending=options.legend.sorted!="descending";entries.sort(function(a,b){return a.label==b.label?0:a.label<b.label!=ascending?1:-1})}}for(var i=0;i<entries.length;++i){var entry=entries[i];if(i%options.legend.noColumns==0){if(rowStarted)fragments.push("</tr>");fragments.push("<tr>");rowStarted=true}fragments.push('<td class="legendColorBox"><div style="border:1px solid '+options.legend.labelBoxBorderColor+';padding:1px"><div style="width:4px;height:0;border:5px solid '+entry.color+';overflow:hidden"></div></div></td>'+'<td class="legendLabel">'+entry.label+"</td>")}if(rowStarted)fragments.push("</tr>");if(fragments.length==0)return;var table='<table style="font-size:smaller;color:'+options.grid.color+'">'+fragments.join("")+"</table>";if(options.legend.container!=null)$(options.legend.container).html(table);else{var pos="",p=options.legend.position,m=options.legend.margin;if(m[0]==null)m=[m,m];if(p.charAt(0)=="n")pos+="top:"+(m[1]+plotOffset.top)+"px;";else if(p.charAt(0)=="s")pos+="bottom:"+(m[1]+plotOffset.bottom)+"px;";if(p.charAt(1)=="e")pos+="right:"+(m[0]+plotOffset.right)+"px;";else if(p.charAt(1)=="w")pos+="left:"+(m[0]+plotOffset.left)+"px;";var legend=$('<div class="legend">'+table.replace('style="','style="position:absolute;'+pos+";")+"</div>").appendTo(placeholder);if(options.legend.backgroundOpacity!=0){var c=options.legend.backgroundColor;if(c==null){c=options.grid.backgroundColor;if(c&&typeof c=="string")c=$.color.parse(c);else c=$.color.extract(legend,"background-color");c.a=1;c=c.toString()}var div=legend.children();$('<div style="position:absolute;width:'+div.width()+"px;height:"+div.height()+"px;"+pos+"background-color:"+c+';"> </div>').prependTo(legend).css("opacity",options.legend.backgroundOpacity)}}}var highlights=[],redrawTimeout=null;function findNearbyItem(mouseX,mouseY,seriesFilter){var maxDistance=options.grid.mouseActiveRadius,smallestDistance=maxDistance*maxDistance+1,item=null,foundPoint=false,i,j,ps;for(i=series.length-1;i>=0;--i){if(!seriesFilter(series[i]))continue;var s=series[i],axisx=s.xaxis,axisy=s.yaxis,points=s.datapoints.points,mx=axisx.c2p(mouseX),my=axisy.c2p(mouseY),maxx=maxDistance/axisx.scale,maxy=maxDistance/axisy.scale;ps=s.datapoints.pointsize;if(axisx.options.inverseTransform)maxx=Number.MAX_VALUE;if(axisy.options.inverseTransform)maxy=Number.MAX_VALUE;if(s.lines.show||s.points.show){for(j=0;j<points.length;j+=ps){var x=points[j],y=points[j+1];if(x==null)continue;if(x-mx>maxx||x-mx<-maxx||y-my>maxy||y-my<-maxy)continue;var dx=Math.abs(axisx.p2c(x)-mouseX),dy=Math.abs(axisy.p2c(y)-mouseY),dist=dx*dx+dy*dy;if(dist<smallestDistance){smallestDistance=dist;item=[i,j/ps]}}}if(s.bars.show&&!item){var barLeft,barRight;switch(s.bars.align){case"left":barLeft=0;break;case"right":barLeft=-s.bars.barWidth;break;default:barLeft=-s.bars.barWidth/2}barRight=barLeft+s.bars.barWidth;for(j=0;j<points.length;j+=ps){var x=points[j],y=points[j+1],b=points[j+2];if(x==null)continue;if(series[i].bars.horizontal?mx<=Math.max(b,x)&&mx>=Math.min(b,x)&&my>=y+barLeft&&my<=y+barRight:mx>=x+barLeft&&mx<=x+barRight&&my>=Math.min(b,y)&&my<=Math.max(b,y))item=[i,j/ps]}}}if(item){i=item[0];j=item[1];ps=series[i].datapoints.pointsize;return{datapoint:series[i].datapoints.points.slice(j*ps,(j+1)*ps),dataIndex:j,series:series[i],seriesIndex:i}}return null}function onMouseMove(e){if(options.grid.hoverable)triggerClickHoverEvent("plothover",e,function(s){return s["hoverable"]!=false})}function onMouseLeave(e){if(options.grid.hoverable)triggerClickHoverEvent("plothover",e,function(s){return false})}function onClick(e){triggerClickHoverEvent("plotclick",e,function(s){return s["clickable"]!=false})}function triggerClickHoverEvent(eventname,event,seriesFilter){var offset=eventHolder.offset(),canvasX=event.pageX-offset.left-plotOffset.left,canvasY=event.pageY-offset.top-plotOffset.top,pos=canvasToAxisCoords({left:canvasX,top:canvasY});pos.pageX=event.pageX;pos.pageY=event.pageY;var item=findNearbyItem(canvasX,canvasY,seriesFilter);if(item){item.pageX=parseInt(item.series.xaxis.p2c(item.datapoint[0])+offset.left+plotOffset.left,10);item.pageY=parseInt(item.series.yaxis.p2c(item.datapoint[1])+offset.top+plotOffset.top,10)}if(options.grid.autoHighlight){for(var i=0;i<highlights.length;++i){var h=highlights[i];if(h.auto==eventname&&!(item&&h.series==item.series&&h.point[0]==item.datapoint[0]&&h.point[1]==item.datapoint[1]))unhighlight(h.series,h.point)}if(item)highlight(item.series,item.datapoint,eventname)}placeholder.trigger(eventname,[pos,item])}function triggerRedrawOverlay(){var t=options.interaction.redrawOverlayInterval;if(t==-1){drawOverlay();return}if(!redrawTimeout)redrawTimeout=setTimeout(drawOverlay,t)}function drawOverlay(){redrawTimeout=null;octx.save();overlay.clear();octx.translate(plotOffset.left,plotOffset.top);var i,hi;for(i=0;i<highlights.length;++i){hi=highlights[i];if(hi.series.bars.show)drawBarHighlight(hi.series,hi.point);else drawPointHighlight(hi.series,hi.point)}octx.restore();executeHooks(hooks.drawOverlay,[octx])}function highlight(s,point,auto){if(typeof s=="number")s=series[s];if(typeof point=="number"){var ps=s.datapoints.pointsize;point=s.datapoints.points.slice(ps*point,ps*(point+1))}var i=indexOfHighlight(s,point);if(i==-1){highlights.push({series:s,point:point,auto:auto});triggerRedrawOverlay()}else if(!auto)highlights[i].auto=false}function unhighlight(s,point){if(s==null&&point==null){highlights=[];triggerRedrawOverlay();return}if(typeof s=="number")s=series[s];if(typeof point=="number"){var ps=s.datapoints.pointsize;point=s.datapoints.points.slice(ps*point,ps*(point+1))}var i=indexOfHighlight(s,point);if(i!=-1){highlights.splice(i,1);triggerRedrawOverlay()}}function indexOfHighlight(s,p){for(var i=0;i<highlights.length;++i){var h=highlights[i];if(h.series==s&&h.point[0]==p[0]&&h.point[1]==p[1])return i}return-1}function drawPointHighlight(series,point){var x=point[0],y=point[1],axisx=series.xaxis,axisy=series.yaxis,highlightColor=typeof series.highlightColor==="string"?series.highlightColor:$.color.parse(series.color).scale("a",.5).toString();if(x<axisx.min||x>axisx.max||y<axisy.min||y>axisy.max)return;var pointRadius=series.points.radius+series.points.lineWidth/2;octx.lineWidth=pointRadius;octx.strokeStyle=highlightColor;var radius=1.5*pointRadius;x=axisx.p2c(x);y=axisy.p2c(y);octx.beginPath();if(series.points.symbol=="circle")octx.arc(x,y,radius,0,2*Math.PI,false);else series.points.symbol(octx,x,y,radius,false);octx.closePath();octx.stroke()}function drawBarHighlight(series,point){var highlightColor=typeof series.highlightColor==="string"?series.highlightColor:$.color.parse(series.color).scale("a",.5).toString(),fillStyle=highlightColor,barLeft;switch(series.bars.align){case"left":barLeft=0;break;case"right":barLeft=-series.bars.barWidth;break;default:barLeft=-series.bars.barWidth/2}octx.lineWidth=series.bars.lineWidth;octx.strokeStyle=highlightColor;drawBar(point[0],point[1],point[2]||0,barLeft,barLeft+series.bars.barWidth,function(){return fillStyle},series.xaxis,series.yaxis,octx,series.bars.horizontal,series.bars.lineWidth)}function getColorOrGradient(spec,bottom,top,defaultColor){if(typeof spec=="string")return spec;else{var gradient=ctx.createLinearGradient(0,top,0,bottom);for(var i=0,l=spec.colors.length;i<l;++i){var c=spec.colors[i];if(typeof c!="string"){var co=$.color.parse(defaultColor);if(c.brightness!=null)co=co.scale("rgb",c.brightness);if(c.opacity!=null)co.a*=c.opacity;c=co.toString()}gradient.addColorStop(i/(l-1),c)}return gradient}}}$.plot=function(placeholder,data,options){var plot=new Plot($(placeholder),data,options,$.plot.plugins);return plot};$.plot.version="0.8.3";$.plot.plugins=[];$.fn.plot=function(data,options){return this.each(function(){$.plot(this,data,options)})};function floorInBase(n,base){return base*Math.floor(n/base)}})(jQuery);

/* flot navigate v0.8.3 |  https://github.com/flot/flot | Copyright (c) 2007-2014 IOLA and Ole Laursen. Licensed under the MIT license. */
(function(a){function e(h){var k,j=this,l=h.data||{};if(l.elem)j=h.dragTarget=l.elem,h.dragProxy=d.proxy||j,h.cursorOffsetX=l.pageX-l.left,h.cursorOffsetY=l.pageY-l.top,h.offsetX=h.pageX-h.cursorOffsetX,h.offsetY=h.pageY-h.cursorOffsetY;else if(d.dragging||l.which>0&&h.which!=l.which||a(h.target).is(l.not))return;switch(h.type){case"mousedown":return a.extend(l,a(j).offset(),{elem:j,target:h.target,pageX:h.pageX,pageY:h.pageY}),b.add(document,"mousemove mouseup",e,l),i(j,!1),d.dragging=null,!1;case!d.dragging&&"mousemove":if(g(h.pageX-l.pageX)+g(h.pageY-l.pageY)<l.distance)break;h.target=l.target,k=f(h,"dragstart",j),k!==!1&&(d.dragging=j,d.proxy=h.dragProxy=a(k||j)[0]);case"mousemove":if(d.dragging){if(k=f(h,"drag",j),c.drop&&(c.drop.allowed=k!==!1,c.drop.handler(h)),k!==!1)break;h.type="mouseup"}case"mouseup":b.remove(document,"mousemove mouseup",e),d.dragging&&(c.drop&&c.drop.handler(h),f(h,"dragend",j)),i(j,!0),d.dragging=d.proxy=l.elem=!1}return!0}function f(b,c,d){b.type=c;var e=a.event.dispatch.call(d,b);return e===!1?!1:e||b.result}function g(a){return Math.pow(a,2)}function h(){return d.dragging===!1}function i(a,b){a&&(a.unselectable=b?"off":"on",a.onselectstart=function(){return b},a.style&&(a.style.MozUserSelect=b?"":"none"))}a.fn.drag=function(a,b,c){return b&&this.bind("dragstart",a),c&&this.bind("dragend",c),a?this.bind("drag",b?b:a):this.trigger("drag")};var b=a.event,c=b.special,d=c.drag={not:":input",distance:0,which:1,dragging:!1,setup:function(c){c=a.extend({distance:d.distance,which:d.which,not:d.not},c||{}),c.distance=g(c.distance),b.add(this,"mousedown",e,c),this.attachEvent&&this.attachEvent("ondragstart",h)},teardown:function(){b.remove(this,"mousedown",e),this===d.dragging&&(d.dragging=d.proxy=!1),i(this,!0),this.detachEvent&&this.detachEvent("ondragstart",h)}};c.dragstart=c.dragend={setup:function(){},teardown:function(){}}})(jQuery);(function(d){function e(a){var b=a||window.event,c=[].slice.call(arguments,1),f=0,e=0,g=0,a=d.event.fix(b);a.type="mousewheel";b.wheelDelta&&(f=b.wheelDelta/120);b.detail&&(f=-b.detail/3);g=f;void 0!==b.axis&&b.axis===b.HORIZONTAL_AXIS&&(g=0,e=-1*f);void 0!==b.wheelDeltaY&&(g=b.wheelDeltaY/120);void 0!==b.wheelDeltaX&&(e=-1*b.wheelDeltaX/120);c.unshift(a,f,e,g);return(d.event.dispatch||d.event.handle).apply(this,c)}var c=["DOMMouseScroll","mousewheel"];if(d.event.fixHooks)for(var h=c.length;h;)d.event.fixHooks[c[--h]]=d.event.mouseHooks;d.event.special.mousewheel={setup:function(){if(this.addEventListener)for(var a=c.length;a;)this.addEventListener(c[--a],e,!1);else this.onmousewheel=e},teardown:function(){if(this.removeEventListener)for(var a=c.length;a;)this.removeEventListener(c[--a],e,!1);else this.onmousewheel=null}};d.fn.extend({mousewheel:function(a){return a?this.bind("mousewheel",a):this.trigger("mousewheel")},unmousewheel:function(a){return this.unbind("mousewheel",a)}})})(jQuery);(function($){var options={xaxis:{zoomRange:null,panRange:null},zoom:{interactive:false,trigger:"dblclick",amount:1.5},pan:{interactive:false,cursor:"move",frameRate:20}};function init(plot){function onZoomClick(e,zoomOut){var c=plot.offset();c.left=e.pageX-c.left;c.top=e.pageY-c.top;if(zoomOut)plot.zoomOut({center:c});else plot.zoom({center:c})}function onMouseWheel(e,delta){e.preventDefault();onZoomClick(e,delta<0);return false}var prevCursor="default",prevPageX=0,prevPageY=0,panTimeout=null;function onDragStart(e){if(e.which!=1)return false;var c=plot.getPlaceholder().css("cursor");if(c)prevCursor=c;plot.getPlaceholder().css("cursor",plot.getOptions().pan.cursor);prevPageX=e.pageX;prevPageY=e.pageY}function onDrag(e){var frameRate=plot.getOptions().pan.frameRate;if(panTimeout||!frameRate)return;panTimeout=setTimeout(function(){plot.pan({left:prevPageX-e.pageX,top:prevPageY-e.pageY});prevPageX=e.pageX;prevPageY=e.pageY;panTimeout=null},1/frameRate*1e3)}function onDragEnd(e){if(panTimeout){clearTimeout(panTimeout);panTimeout=null}plot.getPlaceholder().css("cursor",prevCursor);plot.pan({left:prevPageX-e.pageX,top:prevPageY-e.pageY})}function bindEvents(plot,eventHolder){var o=plot.getOptions();if(o.zoom.interactive){eventHolder[o.zoom.trigger](onZoomClick);eventHolder.mousewheel(onMouseWheel)}if(o.pan.interactive){eventHolder.bind("dragstart",{distance:10},onDragStart);eventHolder.bind("drag",onDrag);eventHolder.bind("dragend",onDragEnd)}}plot.zoomOut=function(args){if(!args)args={};if(!args.amount)args.amount=plot.getOptions().zoom.amount;args.amount=1/args.amount;plot.zoom(args)};plot.zoom=function(args){if(!args)args={};var c=args.center,amount=args.amount||plot.getOptions().zoom.amount,w=plot.width(),h=plot.height();if(!c)c={left:w/2,top:h/2};var xf=c.left/w,yf=c.top/h,minmax={x:{min:c.left-xf*w/amount,max:c.left+(1-xf)*w/amount},y:{min:c.top-yf*h/amount,max:c.top+(1-yf)*h/amount}};$.each(plot.getAxes(),function(_,axis){var opts=axis.options,min=minmax[axis.direction].min,max=minmax[axis.direction].max,zr=opts.zoomRange,pr=opts.panRange;if(zr===false)return;min=axis.c2p(min);max=axis.c2p(max);if(min>max){var tmp=min;min=max;max=tmp}if(pr){if(pr[0]!=null&&min<pr[0]){min=pr[0]}if(pr[1]!=null&&max>pr[1]){max=pr[1]}}var range=max-min;if(zr&&(zr[0]!=null&&range<zr[0]&&amount>1||zr[1]!=null&&range>zr[1]&&amount<1))return;opts.min=min;opts.max=max});plot.setupGrid();plot.draw();if(!args.preventEvent)plot.getPlaceholder().trigger("plotzoom",[plot,args])};plot.pan=function(args){var delta={x:+args.left,y:+args.top};if(isNaN(delta.x))delta.x=0;if(isNaN(delta.y))delta.y=0;$.each(plot.getAxes(),function(_,axis){var opts=axis.options,min,max,d=delta[axis.direction];min=axis.c2p(axis.p2c(axis.min)+d),max=axis.c2p(axis.p2c(axis.max)+d);var pr=opts.panRange;if(pr===false)return;if(pr){if(pr[0]!=null&&pr[0]>min){d=pr[0]-min;min+=d;max+=d}if(pr[1]!=null&&pr[1]<max){d=pr[1]-max;min+=d;max+=d}}opts.min=min;opts.max=max});plot.setupGrid();plot.draw();if(!args.preventEvent)plot.getPlaceholder().trigger("plotpan",[plot,args])};function shutdown(plot,eventHolder){eventHolder.unbind(plot.getOptions().zoom.trigger,onZoomClick);eventHolder.unbind("mousewheel",onMouseWheel);eventHolder.unbind("dragstart",onDragStart);eventHolder.unbind("drag",onDrag);eventHolder.unbind("dragend",onDragEnd);if(panTimeout)clearTimeout(panTimeout)}plot.hooks.bindEvents.push(bindEvents);plot.hooks.shutdown.push(shutdown)}$.plot.plugins.push({init:init,options:options,name:"navigate",version:"1.3"})})(jQuery);