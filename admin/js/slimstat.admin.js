if (typeof SlimStatAdminParams == 'undefined') SlimStatAdminParams = {current_tab: 1, async_load: 'no', refresh_interval: 0, expand_details: 'no', datepicker_image: '', text_direction: ''};
var SlimStatAdmin = {
	// Public variables
	chart_data: [],
	chart_info: [],
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
			container: '#chart-legend',
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
	_qtip_previous_point: null,
	_refresh_timer: [0, 0],
	_tooltip: {},

	add_post_filters: function(report_id, href){
		filters_parsed = [];
		filters_to_add = href.split('&');
		jQuery('#slimstat-filters-form-hidden').attr('action', filters_to_add[0]);

		for (i in filters_to_add){
			if (filters_to_add[i].indexOf('fs\%5B') != 0) continue;
			
			filter_components = filters_to_add[i].split('=');

			filter_components[0] = decodeURIComponent(filter_components[0]);
			jQuery('input[name="'+filter_components[0]+'"]').remove();
			
			if (filter_components[0].indexOf('[day]') > 0) jQuery('#slimstat-filter-day').val(0);
			if (filter_components[0].indexOf('[month]') > 0) jQuery('#slimstat-filter-month').val(0);
			if (filter_components[0].indexOf('[year]') > 0) jQuery('#slimstat-filter-year').val('');
			if (filter_components[0].indexOf('[interval]') > 0) jQuery('#slimstat-filter-interval').val('');
				
			jQuery('<input>').attr('type', 'hidden').attr('name', filter_components[0]).attr('class', 'slimstat-post-filter slimstat-new-filter '+report_id).val(filter_components[1].replace('+', ' ')).appendTo('#slimstat-filters-form-hidden');
			filters_parsed[filter_components[0]] = filter_components[1];
		}
		return filters_parsed;
	},

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
		SlimStatAdmin._placeholder = jQuery("#chart-placeholder");

		// Don't do anything if no placeholder or if hidden
		if (!SlimStatAdmin._placeholder.length || SlimStatAdmin._placeholder.is(':hidden')){
			return true;
		}

		max_y_axis = 0;
		for (i in SlimStatAdmin.chart_data){
			max = SlimStatAdmin.chart_data[i].data.reduce(function(max, arr){ 
				return Math.max(max, arr[1]); 
			}, -Infinity)+1;
			if (max > max_y_axis) max_y_axis = max;
		}

		// Calculate the remaining options
		SlimStatAdmin._chart_options.colors = (SlimStatAdmin.chart_data.length == 4)?['#ccc', '#999', '#bbcc44', '#21759b', '#02c907']:['#bbcc44', '#21759b', '#02c907'],
		SlimStatAdmin._chart_options.xaxis = {
			ticks: (SlimStatAdmin.ticks[0][1].indexOf('/') > 0 && SlimStatAdmin.ticks.length > 16) ? [] : SlimStatAdmin.ticks,
			tickDecimals: 0,
			tickLength: 0,
			tickSize: 1,
			panRange: [0, SlimStatAdmin.chart_data[0].data.length-1],
			zoomRange: [5, SlimStatAdmin.chart_data[0].data.length-1],
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
		jQuery.plot(SlimStatAdmin._placeholder, SlimStatAdmin.chart_data, SlimStatAdmin._chart_options);
		SlimStatAdmin.chart_color_weekends();
		
		// Enable tooltips
		SlimStatAdmin._tooltip = SlimStatAdmin._placeholder.qtip({
			content: ' ',
			hide: {
				event: false,
				fixed: true
			},
			id: 'chart-placeholder',
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
/*
		SlimStatAdmin._placeholder.bind('plotclick', function(event, pos, item){
			if (item && typeof item.series.label != 'undefined'){
				if (item.seriesIndex == 1 && typeof SlimStatAdmin.chart_data[item.seriesIndex].data[item.datapoint[0]-SlimStatAdmin.chart_info.rtl_filler_previous][2] != 'undefined'){
					document.location.href = SlimStatAdmin.chart_data[item.seriesIndex].data[item.datapoint[0]-SlimStatAdmin.chart_info.rtl_filler_previous][2].replace(/&amp;/gi,'&');
				}
				if (item.seriesIndex != 1 && typeof SlimStatAdmin.chart_data[item.seriesIndex].data[item.datapoint[0]-SlimStatAdmin.chart_info.rtl_filler_current][2] != 'undefined'){
					document.location.href = SlimStatAdmin.chart_data[item.seriesIndex].data[item.datapoint[0]-SlimStatAdmin.chart_info.rtl_filler_current][2].replace(/&amp;/gi,'&');
				}
			}
		});
*/
		SlimStatAdmin._placeholder.bind('dblclick', function(event){
			jQuery.plot(SlimStatAdmin._placeholder, SlimStatAdmin.chart_data, SlimStatAdmin._chart_options);
			SlimStatAdmin.chart_color_weekends();
		});

		SlimStatAdmin._placeholder.bind('plotzoom', SlimStatAdmin.chart_color_weekends);
		SlimStatAdmin._placeholder.bind('plotpan', SlimStatAdmin.chart_color_weekends);
	},

	load_ajax_data : function(report_id, data){
		data['current_tab'] = SlimStatAdminParams.current_tab;
		jQuery('.slimstat-post-filter').each(function(){
			data[jQuery(this).attr('name')] = jQuery(this).attr('value');
		});
		jQuery('#'+report_id+' .inside').html('<p class="loading"><i class="slimstat-font-spin1 animate-spin"></i></p>');

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
		jQuery('.slimstat-new-filter').remove();
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
			data = {action: 'slimstat_load_report', report_id: report_id, security: jQuery('#meta-box-order-nonce').val()};
			jQuery('#'+report_id+' .inside').html('<p class="loading"></p>');
			SlimStatAdmin.load_ajax_data(report_id, data);

			window.clearTimeout(refresh_handle);
			SlimStatAdmin._refresh_timer[0] = parseInt(SlimStatAdminParams.refresh_interval/60);
			SlimStatAdmin._refresh_timer[1] = SlimStatAdminParams.refresh_interval%60;
			refresh_handle = window.setTimeout("SlimStatAdmin.refresh_countdown();", 1000);
		}
	}
}

jQuery(function(){
	// Refresh page every X seconds
	if (SlimStatAdminParams.refresh_interval > 0){
		SlimStatAdmin._refresh_timer[0] = parseInt(SlimStatAdminParams.refresh_interval/60);
		SlimStatAdmin._refresh_timer[1] = SlimStatAdminParams.refresh_interval%60;
		refresh_handle = window.setTimeout("SlimStatAdmin.refresh_countdown();", 1000);
	}

	jQuery('input.hide-postbox-tog[id^=slim_]').bind('click.postboxes', function (){
		var report_id = jQuery(this).val();
		var data = {action: 'slimstat_load_report', report_id: report_id, security: jQuery('#meta-box-order-nonce').val()}
		jQuery('#'+report_id+' .inside').html('<p class="loading"></p>');

		if (jQuery(this).prop("checked") && jQuery('#'+jQuery(this).val()).length){
			SlimStatAdmin.load_ajax_data(report_id, data);
		}
	});
	
	
	jQuery('a.slimstat-remove-filter').click(function(e){
		e.preventDefault();

		filters_to_remove = decodeURIComponent(jQuery(this).attr('href')).split('&');
		jQuery('#slimstat-filters-form-hidden').attr('action', filters_to_remove[0]);
		jQuery('.slimstat-new-filter').remove();

		for (i in filters_to_remove){
			filter_components = filters_to_remove[i].split('=');
			jQuery('input[name="'+filter_components[0].replace('[', '\\[').replace(']', '\\]')+'"]').remove();
			
			// Reset dropdowns, if needed
			if (filter_components[0].indexOf('[day]') > 0) jQuery('#slimstat-filter-day').val(0);
			if (filter_components[0].indexOf('[month]') > 0) jQuery('#slimstat-filter-month').val(0);
			if (filter_components[0].indexOf('[year]') > 0) jQuery('#slimstat-filter-year').val('');
			if (filter_components[0].indexOf('[interval]') > 0) jQuery('#slimstat-filter-interval').val('');
		}
	
		jQuery('#slimstat-filters-form-hidden').submit();
		return false;
	});

	
});











// New stuff
jQuery(function(){
	// Filters: add hidden form
	if (!jQuery('#slimstat-filters-form-hidden').length){
		jQuery('<form id="slimstat-filters-form-hidden" method="post"/>').appendTo('body');
		jQuery('.slimstat-post-filter').each(function(){
			console.log(jQuery(this).clone());
			jQuery(this).clone().appendTo('#slimstat-filters-form-hidden');
		});
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

	// Send filters as post requests
	jQuery(document).on('click', '.slimstat-filter-link, #toplevel_page_wp-slim-view-1 li a, #wp-admin-bar-slimstat-header li a', function(e){
		e.preventDefault();
		if (!jQuery('#slimstat-filters-form-hidden').length){
			return true;
		}

		jQuery('.slimstat-new-filter').remove();
		jQuery('.empty-on-submit').val(0);

		SlimStatAdmin.add_post_filters('p0', jQuery(this).attr('href'));
		jQuery('#slimstat-filters-form-hidden').submit();
		return false;
	});

	// Behavior associated to all the 'ajax-based' buttons
	jQuery(document).on('click', '[id^=slim_] .button-ajax', function(e){
		e.preventDefault();
		report_id = jQuery(this).parents('.postbox').attr('id');

		if (typeof jQuery(this).attr('href') != 'undefined'){
			filters_parsed = SlimStatAdmin.add_post_filters(report_id, jQuery(this).attr('href'));

			// Remember the new filter for when the report is refreshed
			if (typeof filters_parsed['fs[start_from]'] != 'undefined' && jQuery('#'+report_id+' .refresh').length){
				href = jQuery('#'+report_id+' .refresh').attr('href');
				href_clean = href.substring(0, href.indexOf('&fs%5Bstart_from'));
				if (href_clean != '') href = href_clean;
				jQuery('#'+report_id+' .refresh').attr('href', href+'&fs%5Bstart_from%5D='+filters_parsed['fs[start_from]']);
			}
		}

		data = {action: 'slimstat_load_report', report_id: report_id, security: jQuery('#meta-box-order-nonce').val()};
		SlimStatAdmin.load_ajax_data(report_id, data);
		
		jQuery('#'+report_id+' .inside').slimScroll({scrollTo : '0px'});
		
		if (typeof refresh_handle != 'undefined'){
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
			data = {action: 'slimstat_load_report', report_id: report_id, security: jQuery('#meta-box-order-nonce').val()}
			SlimStatAdmin.load_ajax_data(report_id, data);
		});
	}

	// Hide Admin Notice
	jQuery(document).on('click', '#slimstat-hide-admin-notice', function(e){
		e.preventDefault();
		jQuery('.updated').slideUp(1000);
		data = {action: 'slimstat_hide_admin_notice', security: jQuery('#meta-box-order-nonce').val()};
		jQuery.ajax({
			url: ajaxurl,
			type: 'post',
			async: true,
			data: data
		});
	});

	// SlimScroll init
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

	// ToolTips
	jQuery(document).on('mouseover', '.slimstat-tooltip-trigger', function(e){
		jQuery(this).qtip({
			overwrite: false,
			content: {
				text: jQuery(this).next('.slimstat-tooltip-content')
			},
			show: {
				event: e.type,
				ready: true
			},
			position: {
				adjust: {
					x: 15
				},
				viewport: jQuery(window)
			},
			style: {
				classes: 'qtip-dark'
			}
		}, e);
	});
	
	// Row Details
	if (SlimStatAdminParams.expand_details != 'yes'){
		jQuery(document).on('mouseenter mouseleave', '.wrap.slimstat .postbox p:not(.header)', function(){
			jQuery(this).find('.slimstat-row-details').toggleClass('expanded');
		});
	}

	// Modal Window / Overlay: Setup
	if (typeof jQuery('#slimstat-modal-dialog').dialog == 'function'){
		jQuery('#slimstat-modal-dialog').dialog({
			autoOpen: false,
			closeOnEscape: true,
			closeText: '',
			draggable: true,
			height: 415,
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

	// Modal Window / Overlay: Whois
	jQuery(document).on('click', '.whois', function(e){
		e.preventDefault();
		jQuery('#slimstat-modal-dialog').dialog({
			dialogClass: 'slimstat',
			title: jQuery(this).attr('title')
		}).html('<iframe id="ip2location" src="'+jQuery(this).attr('href')+'" width="100%" height="92%"></iframe>');
		jQuery('#slimstat-modal-dialog').dialog('open');
	});

	// Redraw charts and adjust modal window width on resize
	SlimStatAdmin.chart_init();
	jQuery(window).resize(function(){
		SlimStatAdmin.chart_init();
	});

	// Remove click on report title
	jQuery('h3.hndle').on('click', function(){ jQuery(this).parent().toggleClass('closed') });
});

/* SlimScroll v1.3.2 | http://rocha.la | Copyright (c) 2011 Piotr Rochala. Licensed MIT, GPL. */
(function(f){jQuery.fn.extend({slimScroll:function(g){var a=f.extend({width:"auto",height:"250px",size:"7px",color:"#000",position:"right",distance:"1px",start:"top",opacity:0.4,alwaysVisible:!1,disableFadeOut:!1,railVisible:!1,railColor:"#333",railOpacity:0.2,railDraggable:!0,railClass:"slimScrollRail",barClass:"slimScrollBar",wrapperClass:"slimScrollDiv",allowPageScroll:!1,wheelStep:20,touchScrollStep:200,borderRadius:"7px",railBorderRadius:"7px"},g);this.each(function(){function u(d){if(r){d=d||window.event;var c=0;d.wheelDelta&&(c=-d.wheelDelta/120);d.detail&&(c=d.detail/3);f(d.target||d.srcTarget||d.srcElement).closest("."+a.wrapperClass).is(b.parent())&&m(c,!0);d.preventDefault&&!k&&d.preventDefault();k||(d.returnValue=!1)}}function m(d,f,g){k=!1;var e=d,h=b.outerHeight()-c.outerHeight();f&&(e=parseInt(c.css("top"))+d*parseInt(a.wheelStep)/100*c.outerHeight(),e=Math.min(Math.max(e,0),h),e=0<d?Math.ceil(e):Math.floor(e),c.css({top:e+"px"}));l=parseInt(c.css("top"))/(b.outerHeight()-c.outerHeight());e=l*(b[0].scrollHeight-b.outerHeight());g&&(e=d,d=e/b[0].scrollHeight*b.outerHeight(),d=Math.min(Math.max(d,0),h),c.css({top:d+"px"}));b.scrollTop(e);b.trigger("slimscrolling",~~e);v();p()}function C(){window.addEventListener?(this.addEventListener("DOMMouseScroll",u,!1),this.addEventListener("mousewheel",u,!1)):document.attachEvent("onmousewheel",u)}function w(){s=Math.max(b.outerHeight()/b[0].scrollHeight*b.outerHeight(),D);c.css({height:s+"px"});var a=s==b.outerHeight()?"none":"block";c.css({display:a})}function v(){w();clearTimeout(A);l==~~l?(k=a.allowPageScroll,B!=l&&b.trigger("slimscroll",0==~~l?"top":"bottom")):k=!1;B=l;s>=b.outerHeight()?k=!0:(c.stop(!0,!0).fadeIn("fast"),a.railVisible&&h.stop(!0,!0).fadeIn("fast"))}function p(){a.alwaysVisible||(A=setTimeout(function(){a.disableFadeOut&&r||x||y||(c.fadeOut("slow"),h.fadeOut("slow"))},1E3))}var r,x,y,A,z,s,l,B,D=30,k=!1,b=f(this);if(b.parent().hasClass(a.wrapperClass)){var n=b.scrollTop(),c=b.parent().find("."+a.barClass),h=b.parent().find("."+a.railClass);w();if(f.isPlainObject(g)){if("height"in g&&"auto"==g.height){b.parent().css("height","auto");b.css("height","auto");var q=b.parent().parent().height();b.parent().css("height",q);b.css("height",q)}if("scrollTo"in g)n=parseInt(a.scrollTo);else if("scrollBy"in g)n+=parseInt(a.scrollBy);else if("destroy"in g){c.remove();h.remove();b.unwrap();return}m(n,!1,!0)}}else{a.height="auto"==g.height?b.parent().height():g.height;n=f("<div></div>").addClass(a.wrapperClass).css({position:"relative",overflow:"hidden",width:a.width,height:a.height});b.css({overflow:"hidden",width:a.width,height:a.height});var h=f("<div></div>").addClass(a.railClass).css({width:a.size,height:"100%",position:"absolute",top:0,display:a.alwaysVisible&&a.railVisible?"block":"none","border-radius":a.railBorderRadius,background:a.railColor,opacity:a.railOpacity,zIndex:90}),c=f("<div></div>").addClass(a.barClass).css({background:a.color,width:a.size,position:"absolute",top:0,opacity:a.opacity,display:a.alwaysVisible?"block":"none","border-radius":a.borderRadius,BorderRadius:a.borderRadius,MozBorderRadius:a.borderRadius,WebkitBorderRadius:a.borderRadius,zIndex:99}),q="right"==a.position?{right:a.distance}:{left:a.distance};h.css(q);c.css(q);b.wrap(n);b.parent().append(c);b.parent().append(h);a.railDraggable&&c.bind("mousedown",function(a){var b=f(document);y=!0;t=parseFloat(c.css("top"));pageY=a.pageY;b.bind("mousemove.slimscroll",function(a){currTop=t+a.pageY-pageY;c.css("top",currTop);m(0,c.position().top,!1)});b.bind("mouseup.slimscroll",function(a){y=!1;p();b.unbind(".slimscroll")});return!1}).bind("selectstart.slimscroll",function(a){a.stopPropagation();a.preventDefault();return!1});h.hover(function(){v()},function(){p()});c.hover(function(){x=!0},function(){x=!1});b.hover(function(){r=!0;v();p()},function(){r=!1;p()});b.bind("touchstart",function(a,b){a.originalEvent.touches.length&&(z=a.originalEvent.touches[0].pageY)});b.bind("touchmove",function(b){k||b.originalEvent.preventDefault();b.originalEvent.touches.length&&(m((z-b.originalEvent.touches[0].pageY)/a.touchScrollStep,!0),z=b.originalEvent.touches[0].pageY)});w();"bottom"===a.start?(c.css({top:b.outerHeight()-c.outerHeight()}),m(0,!0)):"top"!==a.start&&(m(f(a.start).position().top,null,!0),a.alwaysVisible||c.hide());C()}});return this}});jQuery.fn.extend({slimscroll:jQuery.fn.slimScroll})})(jQuery);

/* qTip2 v2.2.0 | http://qtip2.com | Licensed MIT, GPL. */
!function(a,b,c){!function(a){"use strict";"function"==typeof define&&define.amd?define(["jquery"],a):jQuery&&!jQuery.fn.qtip&&a(jQuery)}(function(d){"use strict";function e(a,b,c,e){this.id=c,this.target=a,this.tooltip=A,this.elements={target:a},this._id=N+"-"+c,this.timers={img:{}},this.options=b,this.plugins={},this.cache={event:{},target:d(),disabled:z,attr:e,onTooltip:z,lastClass:""},this.rendered=this.destroyed=this.disabled=this.waiting=this.hiddenDuringWait=this.positioning=this.triggering=z}function f(a){return a===A||"object"!==d.type(a)}function g(a){return!(d.isFunction(a)||a&&a.attr||a.length||"object"===d.type(a)&&(a.jquery||a.then))}function h(a){var b,c,e,h;return f(a)?z:(f(a.metadata)&&(a.metadata={type:a.metadata}),"content"in a&&(b=a.content,f(b)||b.jquery||b.done?b=a.content={text:c=g(b)?z:b}:c=b.text,"ajax"in b&&(e=b.ajax,h=e&&e.once!==z,delete b.ajax,b.text=function(a,b){var f=c||d(this).attr(b.options.content.attr)||"Loading...",g=d.ajax(d.extend({},e,{context:b})).then(e.success,A,e.error).then(function(a){return a&&h&&b.set("content.text",a),a},function(a,c,d){b.destroyed||0===a.status||b.set("content.text",c+": "+d)});return h?f:(b.set("content.text",f),g)}),"title"in b&&(f(b.title)||(b.button=b.title.button,b.title=b.title.text),g(b.title||z)&&(b.title=z))),"position"in a&&f(a.position)&&(a.position={my:a.position,at:a.position}),"show"in a&&f(a.show)&&(a.show=a.show.jquery?{target:a.show}:a.show===y?{ready:y}:{event:a.show}),"hide"in a&&f(a.hide)&&(a.hide=a.hide.jquery?{target:a.hide}:{event:a.hide}),"style"in a&&f(a.style)&&(a.style={classes:a.style}),d.each(M,function(){this.sanitize&&this.sanitize(a)}),a)}function i(a,b){for(var c,d=0,e=a,f=b.split(".");e=e[f[d++]];)d<f.length&&(c=e);return[c||a,f.pop()]}function j(a,b){var c,d,e;for(c in this.checks)for(d in this.checks[c])(e=new RegExp(d,"i").exec(a))&&(b.push(e),("builtin"===c||this.plugins[c])&&this.checks[c][d].apply(this.plugins[c]||this,b))}function k(a){return Q.concat("").join(a?"-"+a+" ":" ")}function l(c){return c&&{type:c.type,pageX:c.pageX,pageY:c.pageY,target:c.target,relatedTarget:c.relatedTarget,scrollX:c.scrollX||a.pageXOffset||b.body.scrollLeft||b.documentElement.scrollLeft,scrollY:c.scrollY||a.pageYOffset||b.body.scrollTop||b.documentElement.scrollTop}||{}}function m(a,b){return b>0?setTimeout(d.proxy(a,this),b):(a.call(this),void 0)}function n(a){return this.tooltip.hasClass(X)?z:(clearTimeout(this.timers.show),clearTimeout(this.timers.hide),this.timers.show=m.call(this,function(){this.toggle(y,a)},this.options.show.delay),void 0)}function o(a){if(this.tooltip.hasClass(X))return z;var b=d(a.relatedTarget),c=b.closest(R)[0]===this.tooltip[0],e=b[0]===this.options.show.target[0];if(clearTimeout(this.timers.show),clearTimeout(this.timers.hide),this!==b[0]&&"mouse"===this.options.position.target&&c||this.options.hide.fixed&&/mouse(out|leave|move)/.test(a.type)&&(c||e))try{a.preventDefault(),a.stopImmediatePropagation()}catch(f){}else this.timers.hide=m.call(this,function(){this.toggle(z,a)},this.options.hide.delay,this)}function p(a){return this.tooltip.hasClass(X)||!this.options.hide.inactive?z:(clearTimeout(this.timers.inactive),this.timers.inactive=m.call(this,function(){this.hide(a)},this.options.hide.inactive),void 0)}function q(a){this.rendered&&this.tooltip[0].offsetWidth>0&&this.reposition(a)}function r(a,c,e){d(b.body).delegate(a,(c.split?c:c.join(cb+" "))+cb,function(){var a=t.api[d.attr(this,P)];a&&!a.disabled&&e.apply(a,arguments)})}function s(a,c,f){var g,i,j,k,l,m=d(b.body),n=a[0]===b?m:a,o=a.metadata?a.metadata(f.metadata):A,p="html5"===f.metadata.type&&o?o[f.metadata.name]:A,q=a.data(f.metadata.name||"qtipopts");try{q="string"==typeof q?d.parseJSON(q):q}catch(r){}if(k=d.extend(y,{},t.defaults,f,"object"==typeof q?h(q):A,h(p||o)),i=k.position,k.id=c,"boolean"==typeof k.content.text){if(j=a.attr(k.content.attr),k.content.attr===z||!j)return z;k.content.text=j}if(i.container.length||(i.container=m),i.target===z&&(i.target=n),k.show.target===z&&(k.show.target=n),k.show.solo===y&&(k.show.solo=i.container.closest("body")),k.hide.target===z&&(k.hide.target=n),k.position.viewport===y&&(k.position.viewport=i.container),i.container=i.container.eq(0),i.at=new v(i.at,y),i.my=new v(i.my),a.data(N))if(k.overwrite)a.qtip("destroy",!0);else if(k.overwrite===z)return z;return a.attr(O,c),k.suppress&&(l=a.attr("title"))&&a.removeAttr("title").attr(Z,l).attr("title",""),g=new e(a,k,c,!!j),a.data(N,g),a.one("remove.qtip-"+c+" removeqtip.qtip-"+c,function(){var a;(a=d(this).data(N))&&a.destroy(!0)}),g}var t,u,v,w,x,y=!0,z=!1,A=null,B="x",C="y",D="width",E="height",F="top",G="left",H="bottom",I="right",J="center",K="flipinvert",L="shift",M={},N="qtip",O="data-hasqtip",P="data-qtip-id",Q=["ui-widget","ui-tooltip"],R="."+N,S="click dblclick mousedown mouseup mousemove mouseleave mouseenter".split(" "),T=N+"-fixed",U=N+"-default",V=N+"-focus",W=N+"-hover",X=N+"-disabled",Y="_replacedByqTip",Z="oldtitle",$={ie:function(){for(var a=3,c=b.createElement("div");(c.innerHTML="<!--[if gt IE "+ ++a+"]><i></i><![endif]-->")&&c.getElementsByTagName("i")[0];);return a>4?a:0/0}(),iOS:parseFloat((""+(/CPU.*OS ([0-9_]{1,5})|(CPU like).*AppleWebKit.*Mobile/i.exec(navigator.userAgent)||[0,""])[1]).replace("undefined","3_2").replace("_",".").replace("_",""))||z};u=e.prototype,u._when=function(a){return d.when.apply(d,a)},u.render=function(a){if(this.rendered||this.destroyed)return this;var b,c=this,e=this.options,f=this.cache,g=this.elements,h=e.content.text,i=e.content.title,j=e.content.button,k=e.position,l=("."+this._id+" ",[]);return d.attr(this.target[0],"aria-describedby",this._id),this.tooltip=g.tooltip=b=d("<div/>",{id:this._id,"class":[N,U,e.style.classes,N+"-pos-"+e.position.my.abbrev()].join(" "),width:e.style.width||"",height:e.style.height||"",tracking:"mouse"===k.target&&k.adjust.mouse,role:"alert","aria-live":"polite","aria-atomic":z,"aria-describedby":this._id+"-content","aria-hidden":y}).toggleClass(X,this.disabled).attr(P,this.id).data(N,this).appendTo(k.container).append(g.content=d("<div />",{"class":N+"-content",id:this._id+"-content","aria-atomic":y})),this.rendered=-1,this.positioning=y,i&&(this._createTitle(),d.isFunction(i)||l.push(this._updateTitle(i,z))),j&&this._createButton(),d.isFunction(h)||l.push(this._updateContent(h,z)),this.rendered=y,this._setWidget(),d.each(M,function(a){var b;"render"===this.initialize&&(b=this(c))&&(c.plugins[a]=b)}),this._unassignEvents(),this._assignEvents(),this._when(l).then(function(){c._trigger("render"),c.positioning=z,c.hiddenDuringWait||!e.show.ready&&!a||c.toggle(y,f.event,z),c.hiddenDuringWait=z}),t.api[this.id]=this,this},u.destroy=function(a){function b(){if(!this.destroyed){this.destroyed=y;var a=this.target,b=a.attr(Z);this.rendered&&this.tooltip.stop(1,0).find("*").remove().end().remove(),d.each(this.plugins,function(){this.destroy&&this.destroy()}),clearTimeout(this.timers.show),clearTimeout(this.timers.hide),this._unassignEvents(),a.removeData(N).removeAttr(P).removeAttr(O).removeAttr("aria-describedby"),this.options.suppress&&b&&a.attr("title",b).removeAttr(Z),this._unbind(a),this.options=this.elements=this.cache=this.timers=this.plugins=this.mouse=A,delete t.api[this.id]}}return this.destroyed?this.target:(a===y&&"hide"!==this.triggering||!this.rendered?b.call(this):(this.tooltip.one("tooltiphidden",d.proxy(b,this)),!this.triggering&&this.hide()),this.target)},w=u.checks={builtin:{"^id$":function(a,b,c,e){var f=c===y?t.nextid:c,g=N+"-"+f;f!==z&&f.length>0&&!d("#"+g).length?(this._id=g,this.rendered&&(this.tooltip[0].id=this._id,this.elements.content[0].id=this._id+"-content",this.elements.title[0].id=this._id+"-title")):a[b]=e},"^prerender":function(a,b,c){c&&!this.rendered&&this.render(this.options.show.ready)},"^content.text$":function(a,b,c){this._updateContent(c)},"^content.attr$":function(a,b,c,d){this.options.content.text===this.target.attr(d)&&this._updateContent(this.target.attr(c))},"^content.title$":function(a,b,c){return c?(c&&!this.elements.title&&this._createTitle(),this._updateTitle(c),void 0):this._removeTitle()},"^content.button$":function(a,b,c){this._updateButton(c)},"^content.title.(text|button)$":function(a,b,c){this.set("content."+b,c)},"^position.(my|at)$":function(a,b,c){"string"==typeof c&&(a[b]=new v(c,"at"===b))},"^position.container$":function(a,b,c){this.rendered&&this.tooltip.appendTo(c)},"^show.ready$":function(a,b,c){c&&(!this.rendered&&this.render(y)||this.toggle(y))},"^style.classes$":function(a,b,c,d){this.rendered&&this.tooltip.removeClass(d).addClass(c)},"^style.(width|height)":function(a,b,c){this.rendered&&this.tooltip.css(b,c)},"^style.widget|content.title":function(){this.rendered&&this._setWidget()},"^style.def":function(a,b,c){this.rendered&&this.tooltip.toggleClass(U,!!c)},"^events.(render|show|move|hide|focus|blur)$":function(a,b,c){this.rendered&&this.tooltip[(d.isFunction(c)?"":"un")+"bind"]("tooltip"+b,c)},"^(show|hide|position).(event|target|fixed|inactive|leave|distance|viewport|adjust)":function(){if(this.rendered){var a=this.options.position;this.tooltip.attr("tracking","mouse"===a.target&&a.adjust.mouse),this._unassignEvents(),this._assignEvents()}}}},u.get=function(a){if(this.destroyed)return this;var b=i(this.options,a.toLowerCase()),c=b[0][b[1]];return c.precedance?c.string():c};var _=/^position\.(my|at|adjust|target|container|viewport)|style|content|show\.ready/i,ab=/^prerender|show\.ready/i;u.set=function(a,b){if(this.destroyed)return this;{var c,e=this.rendered,f=z,g=this.options;this.checks}return"string"==typeof a?(c=a,a={},a[c]=b):a=d.extend({},a),d.each(a,function(b,c){if(e&&ab.test(b))return delete a[b],void 0;var h,j=i(g,b.toLowerCase());h=j[0][j[1]],j[0][j[1]]=c&&c.nodeType?d(c):c,f=_.test(b)||f,a[b]=[j[0],j[1],c,h]}),h(g),this.positioning=y,d.each(a,d.proxy(j,this)),this.positioning=z,this.rendered&&this.tooltip[0].offsetWidth>0&&f&&this.reposition("mouse"===g.position.target?A:this.cache.event),this},u._update=function(a,b){var c=this,e=this.cache;return this.rendered&&a?(d.isFunction(a)&&(a=a.call(this.elements.target,e.event,this)||""),d.isFunction(a.then)?(e.waiting=y,a.then(function(a){return e.waiting=z,c._update(a,b)},A,function(a){return c._update(a,b)})):a===z||!a&&""!==a?z:(a.jquery&&a.length>0?b.empty().append(a.css({display:"block",visibility:"visible"})):b.html(a),this._waitForContent(b).then(function(a){a.images&&a.images.length&&c.rendered&&c.tooltip[0].offsetWidth>0&&c.reposition(e.event,!a.length)}))):z},u._waitForContent=function(a){var b=this.cache;return b.waiting=y,(d.fn.imagesLoaded?a.imagesLoaded():d.Deferred().resolve([])).done(function(){b.waiting=z}).promise()},u._updateContent=function(a,b){this._update(a,this.elements.content,b)},u._updateTitle=function(a,b){this._update(a,this.elements.title,b)===z&&this._removeTitle(z)},u._createTitle=function(){var a=this.elements,b=this._id+"-title";a.titlebar&&this._removeTitle(),a.titlebar=d("<div />",{"class":N+"-titlebar "+(this.options.style.widget?k("header"):"")}).append(a.title=d("<div />",{id:b,"class":N+"-title","aria-atomic":y})).insertBefore(a.content).delegate(".qtip-close","mousedown keydown mouseup keyup mouseout",function(a){d(this).toggleClass("ui-state-active ui-state-focus","down"===a.type.substr(-4))}).delegate(".qtip-close","mouseover mouseout",function(a){d(this).toggleClass("ui-state-hover","mouseover"===a.type)}),this.options.content.button&&this._createButton()},u._removeTitle=function(a){var b=this.elements;b.title&&(b.titlebar.remove(),b.titlebar=b.title=b.button=A,a!==z&&this.reposition())},u.reposition=function(c,e){if(!this.rendered||this.positioning||this.destroyed)return this;this.positioning=y;var f,g,h=this.cache,i=this.tooltip,j=this.options.position,k=j.target,l=j.my,m=j.at,n=j.viewport,o=j.container,p=j.adjust,q=p.method.split(" "),r=i.outerWidth(z),s=i.outerHeight(z),t=0,u=0,v=i.css("position"),w={left:0,top:0},x=i[0].offsetWidth>0,A=c&&"scroll"===c.type,B=d(a),C=o[0].ownerDocument,D=this.mouse;if(d.isArray(k)&&2===k.length)m={x:G,y:F},w={left:k[0],top:k[1]};else if("mouse"===k)m={x:G,y:F},!D||!D.pageX||!p.mouse&&c&&c.pageX?c&&c.pageX||((!p.mouse||this.options.show.distance)&&h.origin&&h.origin.pageX?c=h.origin:(!c||c&&("resize"===c.type||"scroll"===c.type))&&(c=h.event)):c=D,"static"!==v&&(w=o.offset()),C.body.offsetWidth!==(a.innerWidth||C.documentElement.clientWidth)&&(g=d(b.body).offset()),w={left:c.pageX-w.left+(g&&g.left||0),top:c.pageY-w.top+(g&&g.top||0)},p.mouse&&A&&D&&(w.left-=(D.scrollX||0)-B.scrollLeft(),w.top-=(D.scrollY||0)-B.scrollTop());else{if("event"===k?c&&c.target&&"scroll"!==c.type&&"resize"!==c.type?h.target=d(c.target):c.target||(h.target=this.elements.target):"event"!==k&&(h.target=d(k.jquery?k:this.elements.target)),k=h.target,k=d(k).eq(0),0===k.length)return this;k[0]===b||k[0]===a?(t=$.iOS?a.innerWidth:k.width(),u=$.iOS?a.innerHeight:k.height(),k[0]===a&&(w={top:(n||k).scrollTop(),left:(n||k).scrollLeft()})):M.imagemap&&k.is("area")?f=M.imagemap(this,k,m,M.viewport?q:z):M.svg&&k&&k[0].ownerSVGElement?f=M.svg(this,k,m,M.viewport?q:z):(t=k.outerWidth(z),u=k.outerHeight(z),w=k.offset()),f&&(t=f.width,u=f.height,g=f.offset,w=f.position),w=this.reposition.offset(k,w,o),($.iOS>3.1&&$.iOS<4.1||$.iOS>=4.3&&$.iOS<4.33||!$.iOS&&"fixed"===v)&&(w.left-=B.scrollLeft(),w.top-=B.scrollTop()),(!f||f&&f.adjustable!==z)&&(w.left+=m.x===I?t:m.x===J?t/2:0,w.top+=m.y===H?u:m.y===J?u/2:0)}return w.left+=p.x+(l.x===I?-r:l.x===J?-r/2:0),w.top+=p.y+(l.y===H?-s:l.y===J?-s/2:0),M.viewport?(w.adjusted=M.viewport(this,w,j,t,u,r,s),g&&w.adjusted.left&&(w.left+=g.left),g&&w.adjusted.top&&(w.top+=g.top)):w.adjusted={left:0,top:0},this._trigger("move",[w,n.elem||n],c)?(delete w.adjusted,e===z||!x||isNaN(w.left)||isNaN(w.top)||"mouse"===k||!d.isFunction(j.effect)?i.css(w):d.isFunction(j.effect)&&(j.effect.call(i,this,d.extend({},w)),i.queue(function(a){d(this).css({opacity:"",height:""}),$.ie&&this.style.removeAttribute("filter"),a()})),this.positioning=z,this):this},u.reposition.offset=function(a,c,e){function f(a,b){c.left+=b*a.scrollLeft(),c.top+=b*a.scrollTop()}if(!e[0])return c;var g,h,i,j,k=d(a[0].ownerDocument),l=!!$.ie&&"CSS1Compat"!==b.compatMode,m=e[0];do"static"!==(h=d.css(m,"position"))&&("fixed"===h?(i=m.getBoundingClientRect(),f(k,-1)):(i=d(m).position(),i.left+=parseFloat(d.css(m,"borderLeftWidth"))||0,i.top+=parseFloat(d.css(m,"borderTopWidth"))||0),c.left-=i.left+(parseFloat(d.css(m,"marginLeft"))||0),c.top-=i.top+(parseFloat(d.css(m,"marginTop"))||0),g||"hidden"===(j=d.css(m,"overflow"))||"visible"===j||(g=d(m)));while(m=m.offsetParent);return g&&(g[0]!==k[0]||l)&&f(g,1),c};var bb=(v=u.reposition.Corner=function(a,b){a=(""+a).replace(/([A-Z])/," $1").replace(/middle/gi,J).toLowerCase(),this.x=(a.match(/left|right/i)||a.match(/center/)||["inherit"])[0].toLowerCase(),this.y=(a.match(/top|bottom|center/i)||["inherit"])[0].toLowerCase(),this.forceY=!!b;var c=a.charAt(0);this.precedance="t"===c||"b"===c?C:B}).prototype;bb.invert=function(a,b){this[a]=this[a]===G?I:this[a]===I?G:b||this[a]},bb.string=function(){var a=this.x,b=this.y;return a===b?a:this.precedance===C||this.forceY&&"center"!==b?b+" "+a:a+" "+b},bb.abbrev=function(){var a=this.string().split(" ");return a[0].charAt(0)+(a[1]&&a[1].charAt(0)||"")},bb.clone=function(){return new v(this.string(),this.forceY)},u.toggle=function(a,c){var e=this.cache,f=this.options,g=this.tooltip;if(c){if(/over|enter/.test(c.type)&&/out|leave/.test(e.event.type)&&f.show.target.add(c.target).length===f.show.target.length&&g.has(c.relatedTarget).length)return this;e.event=l(c)}if(this.waiting&&!a&&(this.hiddenDuringWait=y),!this.rendered)return a?this.render(1):this;if(this.destroyed||this.disabled)return this;var h,i,j,k=a?"show":"hide",m=this.options[k],n=(this.options[a?"hide":"show"],this.options.position),o=this.options.content,p=this.tooltip.css("width"),q=this.tooltip.is(":visible"),r=a||1===m.target.length,s=!c||m.target.length<2||e.target[0]===c.target;return(typeof a).search("boolean|number")&&(a=!q),h=!g.is(":animated")&&q===a&&s,i=h?A:!!this._trigger(k,[90]),this.destroyed?this:(i!==z&&a&&this.focus(c),!i||h?this:(d.attr(g[0],"aria-hidden",!a),a?(e.origin=l(this.mouse),d.isFunction(o.text)&&this._updateContent(o.text,z),d.isFunction(o.title)&&this._updateTitle(o.title,z),!x&&"mouse"===n.target&&n.adjust.mouse&&(d(b).bind("mousemove."+N,this._storeMouse),x=y),p||g.css("width",g.outerWidth(z)),this.reposition(c,arguments[2]),p||g.css("width",""),m.solo&&("string"==typeof m.solo?d(m.solo):d(R,m.solo)).not(g).not(m.target).qtip("hide",d.Event("tooltipsolo"))):(clearTimeout(this.timers.show),delete e.origin,x&&!d(R+'[tracking="true"]:visible',m.solo).not(g).length&&(d(b).unbind("mousemove."+N),x=z),this.blur(c)),j=d.proxy(function(){a?($.ie&&g[0].style.removeAttribute("filter"),g.css("overflow",""),"string"==typeof m.autofocus&&d(this.options.show.autofocus,g).focus(),this.options.show.target.trigger("qtip-"+this.id+"-inactive")):g.css({display:"",visibility:"",opacity:"",left:"",top:""}),this._trigger(a?"visible":"hidden")},this),m.effect===z||r===z?(g[k](),j()):d.isFunction(m.effect)?(g.stop(1,1),m.effect.call(g,this),g.queue("fx",function(a){j(),a()})):g.fadeTo(90,a?1:0,j),a&&m.target.trigger("qtip-"+this.id+"-inactive"),this))},u.show=function(a){return this.toggle(y,a)},u.hide=function(a){return this.toggle(z,a)},u.focus=function(a){if(!this.rendered||this.destroyed)return this;var b=d(R),c=this.tooltip,e=parseInt(c[0].style.zIndex,10),f=t.zindex+b.length;return c.hasClass(V)||this._trigger("focus",[f],a)&&(e!==f&&(b.each(function(){this.style.zIndex>e&&(this.style.zIndex=this.style.zIndex-1)}),b.filter("."+V).qtip("blur",a)),c.addClass(V)[0].style.zIndex=f),this},u.blur=function(a){return!this.rendered||this.destroyed?this:(this.tooltip.removeClass(V),this._trigger("blur",[this.tooltip.css("zIndex")],a),this)},u.disable=function(a){return this.destroyed?this:("toggle"===a?a=!(this.rendered?this.tooltip.hasClass(X):this.disabled):"boolean"!=typeof a&&(a=y),this.rendered&&this.tooltip.toggleClass(X,a).attr("aria-disabled",a),this.disabled=!!a,this)},u.enable=function(){return this.disable(z)},u._createButton=function(){var a=this,b=this.elements,c=b.tooltip,e=this.options.content.button,f="string"==typeof e,g=f?e:"Close tooltip";b.button&&b.button.remove(),b.button=e.jquery?e:d("<a />",{"class":"qtip-close "+(this.options.style.widget?"":N+"-icon"),title:g,"aria-label":g}).prepend(d("<span />",{"class":"ui-icon ui-icon-close",html:"&times;"})),b.button.appendTo(b.titlebar||c).attr("role","button").click(function(b){return c.hasClass(X)||a.hide(b),z})},u._updateButton=function(a){if(!this.rendered)return z;var b=this.elements.button;a?this._createButton():b.remove()},u._setWidget=function(){var a=this.options.style.widget,b=this.elements,c=b.tooltip,d=c.hasClass(X);c.removeClass(X),X=a?"ui-state-disabled":"qtip-disabled",c.toggleClass(X,d),c.toggleClass("ui-helper-reset "+k(),a).toggleClass(U,this.options.style.def&&!a),b.content&&b.content.toggleClass(k("content"),a),b.titlebar&&b.titlebar.toggleClass(k("header"),a),b.button&&b.button.toggleClass(N+"-icon",!a)},u._storeMouse=function(a){(this.mouse=l(a)).type="mousemove"},u._bind=function(a,b,c,e,f){var g="."+this._id+(e?"-"+e:"");b.length&&d(a).bind((b.split?b:b.join(g+" "))+g,d.proxy(c,f||this))},u._unbind=function(a,b){d(a).unbind("."+this._id+(b?"-"+b:""))};var cb="."+N;d(function(){r(R,["mouseenter","mouseleave"],function(a){var b="mouseenter"===a.type,c=d(a.currentTarget),e=d(a.relatedTarget||a.target),f=this.options;b?(this.focus(a),c.hasClass(T)&&!c.hasClass(X)&&clearTimeout(this.timers.hide)):"mouse"===f.position.target&&f.hide.event&&f.show.target&&!e.closest(f.show.target[0]).length&&this.hide(a),c.toggleClass(W,b)}),r("["+P+"]",S,p)}),u._trigger=function(a,b,c){var e=d.Event("tooltip"+a);return e.originalEvent=c&&d.extend({},c)||this.cache.event||A,this.triggering=a,this.tooltip.trigger(e,[this].concat(b||[])),this.triggering=z,!e.isDefaultPrevented()},u._bindEvents=function(a,b,c,e,f,g){if(e.add(c).length===e.length){var h=[];b=d.map(b,function(b){var c=d.inArray(b,a);return c>-1?(h.push(a.splice(c,1)[0]),void 0):b}),h.length&&this._bind(c,h,function(a){var b=this.rendered?this.tooltip[0].offsetWidth>0:!1;(b?g:f).call(this,a)})}this._bind(c,a,f),this._bind(e,b,g)},u._assignInitialEvents=function(a){function b(a){return this.disabled||this.destroyed?z:(this.cache.event=l(a),this.cache.target=a?d(a.target):[c],clearTimeout(this.timers.show),this.timers.show=m.call(this,function(){this.render("object"==typeof a||e.show.ready)},e.show.delay),void 0)}var e=this.options,f=e.show.target,g=e.hide.target,h=e.show.event?d.trim(""+e.show.event).split(" "):[],i=e.hide.event?d.trim(""+e.hide.event).split(" "):[];/mouse(over|enter)/i.test(e.show.event)&&!/mouse(out|leave)/i.test(e.hide.event)&&i.push("mouseleave"),this._bind(f,"mousemove",function(a){this._storeMouse(a),this.cache.onTarget=y}),this._bindEvents(h,i,f,g,b,function(){clearTimeout(this.timers.show)}),(e.show.ready||e.prerender)&&b.call(this,a)},u._assignEvents=function(){var c=this,e=this.options,f=e.position,g=this.tooltip,h=e.show.target,i=e.hide.target,j=f.container,k=f.viewport,l=d(b),m=(d(b.body),d(a)),r=e.show.event?d.trim(""+e.show.event).split(" "):[],s=e.hide.event?d.trim(""+e.hide.event).split(" "):[];d.each(e.events,function(a,b){c._bind(g,"toggle"===a?["tooltipshow","tooltiphide"]:["tooltip"+a],b,null,g)}),/mouse(out|leave)/i.test(e.hide.event)&&"window"===e.hide.leave&&this._bind(l,["mouseout","blur"],function(a){/select|option/.test(a.target.nodeName)||a.relatedTarget||this.hide(a)}),e.hide.fixed?i=i.add(g.addClass(T)):/mouse(over|enter)/i.test(e.show.event)&&this._bind(i,"mouseleave",function(){clearTimeout(this.timers.show)}),(""+e.hide.event).indexOf("unfocus")>-1&&this._bind(j.closest("html"),["mousedown","touchstart"],function(a){var b=d(a.target),c=this.rendered&&!this.tooltip.hasClass(X)&&this.tooltip[0].offsetWidth>0,e=b.parents(R).filter(this.tooltip[0]).length>0;b[0]===this.target[0]||b[0]===this.tooltip[0]||e||this.target.has(b[0]).length||!c||this.hide(a)}),"number"==typeof e.hide.inactive&&(this._bind(h,"qtip-"+this.id+"-inactive",p),this._bind(i.add(g),t.inactiveEvents,p,"-inactive")),this._bindEvents(r,s,h,i,n,o),this._bind(h.add(g),"mousemove",function(a){if("number"==typeof e.hide.distance){var b=this.cache.origin||{},c=this.options.hide.distance,d=Math.abs;(d(a.pageX-b.pageX)>=c||d(a.pageY-b.pageY)>=c)&&this.hide(a)}this._storeMouse(a)}),"mouse"===f.target&&f.adjust.mouse&&(e.hide.event&&this._bind(h,["mouseenter","mouseleave"],function(a){this.cache.onTarget="mouseenter"===a.type}),this._bind(l,"mousemove",function(a){this.rendered&&this.cache.onTarget&&!this.tooltip.hasClass(X)&&this.tooltip[0].offsetWidth>0&&this.reposition(a)})),(f.adjust.resize||k.length)&&this._bind(d.event.special.resize?k:m,"resize",q),f.adjust.scroll&&this._bind(m.add(f.container),"scroll",q)},u._unassignEvents=function(){var c=[this.options.show.target[0],this.options.hide.target[0],this.rendered&&this.tooltip[0],this.options.position.container[0],this.options.position.viewport[0],this.options.position.container.closest("html")[0],a,b];this._unbind(d([]).pushStack(d.grep(c,function(a){return"object"==typeof a})))},t=d.fn.qtip=function(a,b,e){var f=(""+a).toLowerCase(),g=A,i=d.makeArray(arguments).slice(1),j=i[i.length-1],k=this[0]?d.data(this[0],N):A;return!arguments.length&&k||"api"===f?k:"string"==typeof a?(this.each(function(){var a=d.data(this,N);if(!a)return y;if(j&&j.timeStamp&&(a.cache.event=j),!b||"option"!==f&&"options"!==f)a[f]&&a[f].apply(a,i);else{if(e===c&&!d.isPlainObject(b))return g=a.get(b),z;a.set(b,e)}}),g!==A?g:this):"object"!=typeof a&&arguments.length?void 0:(k=h(d.extend(y,{},a)),this.each(function(a){var b,c;return c=d.isArray(k.id)?k.id[a]:k.id,c=!c||c===z||c.length<1||t.api[c]?t.nextid++:c,b=s(d(this),c,k),b===z?y:(t.api[c]=b,d.each(M,function(){"initialize"===this.initialize&&this(b)}),b._assignInitialEvents(j),void 0)}))},d.qtip=e,t.api={},d.each({attr:function(a,b){if(this.length){var c=this[0],e="title",f=d.data(c,"qtip");if(a===e&&f&&"object"==typeof f&&f.options.suppress)return arguments.length<2?d.attr(c,Z):(f&&f.options.content.attr===e&&f.cache.attr&&f.set("content.text",b),this.attr(Z,b))}return d.fn["attr"+Y].apply(this,arguments)},clone:function(a){var b=(d([]),d.fn["clone"+Y].apply(this,arguments));return a||b.filter("["+Z+"]").attr("title",function(){return d.attr(this,Z)}).removeAttr(Z),b}},function(a,b){if(!b||d.fn[a+Y])return y;var c=d.fn[a+Y]=d.fn[a];d.fn[a]=function(){return b.apply(this,arguments)||c.apply(this,arguments)}}),d.ui||(d["cleanData"+Y]=d.cleanData,d.cleanData=function(a){for(var b,c=0;(b=d(a[c])).length;c++)if(b.attr(O))try{b.triggerHandler("removeqtip")}catch(e){}d["cleanData"+Y].apply(this,arguments)}),t.version="2.2.0",t.nextid=0,t.inactiveEvents=S,t.zindex=15e3,t.defaults={prerender:z,id:z,overwrite:y,suppress:y,content:{text:y,attr:"title",title:z,button:z},position:{my:"top left",at:"bottom right",target:z,container:z,viewport:z,adjust:{x:0,y:0,mouse:y,scroll:y,resize:y,method:"flipinvert flipinvert"},effect:function(a,b){d(this).animate(b,{duration:200,queue:z})}},show:{target:z,event:"mouseenter",effect:y,delay:90,solo:z,ready:z,autofocus:z},hide:{target:z,event:"mouseleave",effect:y,delay:0,fixed:z,inactive:z,leave:"window",distance:z},style:{classes:"",widget:z,width:z,height:z,def:y},events:{render:A,move:A,show:A,hide:A,toggle:A,visible:A,hidden:A,focus:A,blur:A}},M.viewport=function(c,d,e,f,g,h,i){function j(a,b,c,e,f,g,h,i,j){var k=d[f],m=v[a],t=w[a],u=c===L,x=m===f?j:m===g?-j:-j/2,y=t===f?i:t===g?-i:-i/2,z=r[f]+s[f]-(o?0:n[f]),A=z-k,B=k+j-(h===D?p:q)-z,C=x-(v.precedance===a||m===v[b]?y:0)-(t===J?i/2:0);return u?(C=(m===f?1:-1)*x,d[f]+=A>0?A:B>0?-B:0,d[f]=Math.max(-n[f]+s[f],k-C,Math.min(Math.max(-n[f]+s[f]+(h===D?p:q),k+C),d[f],"center"===m?k-x:1e9))):(e*=c===K?2:0,A>0&&(m!==f||B>0)?(d[f]-=C+e,l.invert(a,f)):B>0&&(m!==g||A>0)&&(d[f]-=(m===J?-C:C)+e,l.invert(a,g)),d[f]<r&&-d[f]>B&&(d[f]=k,l=v.clone())),d[f]-k}var k,l,m,n,o,p,q,r,s,t=e.target,u=c.elements.tooltip,v=e.my,w=e.at,x=e.adjust,y=x.method.split(" "),A=y[0],M=y[1]||y[0],O=e.viewport,P=e.container,Q=c.cache,R={left:0,top:0};return O.jquery&&t[0]!==a&&t[0]!==b.body&&"none"!==x.method?(n=P.offset()||R,o="static"===P.css("position"),k="fixed"===u.css("position"),p=O[0]===a?O.width():O.outerWidth(z),q=O[0]===a?O.height():O.outerHeight(z),r={left:k?0:O.scrollLeft(),top:k?0:O.scrollTop()},s=O.offset()||R,("shift"!==A||"shift"!==M)&&(l=v.clone()),R={left:"none"!==A?j(B,C,A,x.x,G,I,D,f,h):0,top:"none"!==M?j(C,B,M,x.y,F,H,E,g,i):0},l&&Q.lastClass!==(m=N+"-pos-"+l.abbrev())&&u.removeClass(c.cache.lastClass).addClass(c.cache.lastClass=m),R):R}})}(window,document);

/* flot v0.8.2 | https://github.com/flot/flot | Copyright (c) 2007-2013 IOLA and Ole Laursen. Licensed under the MIT license. */
(function(e){e.color={};e.color.make=function(t,n,r,i){var s={};s.r=t||0;s.g=n||0;s.b=r||0;s.a=i!=null?i:1;s.add=function(e,t){for(var n=0;n<e.length;++n)s[e.charAt(n)]+=t;return s.normalize()};s.scale=function(e,t){for(var n=0;n<e.length;++n)s[e.charAt(n)]*=t;return s.normalize()};s.toString=function(){if(s.a>=1){return"rgb("+[s.r,s.g,s.b].join(",")+")"}else{return"rgba("+[s.r,s.g,s.b,s.a].join(",")+")"}};s.normalize=function(){function e(e,t,n){return t<e?e:t>n?n:t}s.r=e(0,parseInt(s.r),255);s.g=e(0,parseInt(s.g),255);s.b=e(0,parseInt(s.b),255);s.a=e(0,s.a,1);return s};s.clone=function(){return e.color.make(s.r,s.b,s.g,s.a)};return s.normalize()};e.color.extract=function(t,n){var r;do{r=t.css(n).toLowerCase();if(r!=""&&r!="transparent")break;t=t.parent()}while(t.length&&!e.nodeName(t.get(0),"body"));if(r=="rgba(0, 0, 0, 0)")r="transparent";return e.color.parse(r)};e.color.parse=function(n){var r,i=e.color.make;if(r=/rgb\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*\)/.exec(n))return i(parseInt(r[1],10),parseInt(r[2],10),parseInt(r[3],10));if(r=/rgba\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]+(?:\.[0-9]+)?)\s*\)/.exec(n))return i(parseInt(r[1],10),parseInt(r[2],10),parseInt(r[3],10),parseFloat(r[4]));if(r=/rgb\(\s*([0-9]+(?:\.[0-9]+)?)\%\s*,\s*([0-9]+(?:\.[0-9]+)?)\%\s*,\s*([0-9]+(?:\.[0-9]+)?)\%\s*\)/.exec(n))return i(parseFloat(r[1])*2.55,parseFloat(r[2])*2.55,parseFloat(r[3])*2.55);if(r=/rgba\(\s*([0-9]+(?:\.[0-9]+)?)\%\s*,\s*([0-9]+(?:\.[0-9]+)?)\%\s*,\s*([0-9]+(?:\.[0-9]+)?)\%\s*,\s*([0-9]+(?:\.[0-9]+)?)\s*\)/.exec(n))return i(parseFloat(r[1])*2.55,parseFloat(r[2])*2.55,parseFloat(r[3])*2.55,parseFloat(r[4]));if(r=/#([a-fA-F0-9]{2})([a-fA-F0-9]{2})([a-fA-F0-9]{2})/.exec(n))return i(parseInt(r[1],16),parseInt(r[2],16),parseInt(r[3],16));if(r=/#([a-fA-F0-9])([a-fA-F0-9])([a-fA-F0-9])/.exec(n))return i(parseInt(r[1]+r[1],16),parseInt(r[2]+r[2],16),parseInt(r[3]+r[3],16));var s=e.trim(n).toLowerCase();if(s=="transparent")return i(255,255,255,0);else{r=t[s]||[0,0,0];return i(r[0],r[1],r[2])}};var t={aqua:[0,255,255],azure:[240,255,255],beige:[245,245,220],black:[0,0,0],blue:[0,0,255],brown:[165,42,42],cyan:[0,255,255],darkblue:[0,0,139],darkcyan:[0,139,139],darkgrey:[169,169,169],darkgreen:[0,100,0],darkkhaki:[189,183,107],darkmagenta:[139,0,139],darkolivegreen:[85,107,47],darkorange:[255,140,0],darkorchid:[153,50,204],darkred:[139,0,0],darksalmon:[233,150,122],darkviolet:[148,0,211],fuchsia:[255,0,255],gold:[255,215,0],green:[0,128,0],indigo:[75,0,130],khaki:[240,230,140],lightblue:[173,216,230],lightcyan:[224,255,255],lightgreen:[144,238,144],lightgrey:[211,211,211],lightpink:[255,182,193],lightyellow:[255,255,224],lime:[0,255,0],magenta:[255,0,255],maroon:[128,0,0],navy:[0,0,128],olive:[128,128,0],orange:[255,165,0],pink:[255,192,203],purple:[128,0,128],violet:[128,0,128],red:[255,0,0],silver:[192,192,192],white:[255,255,255],yellow:[255,255,0]}})(jQuery);(function(e){function n(t,n){var r=n.children("."+t)[0];if(r==null){r=document.createElement("canvas");r.className=t;e(r).css({direction:"ltr",position:"absolute",left:0,top:0}).appendTo(n);if(!r.getContext){if(window.G_vmlCanvasManager){r=window.G_vmlCanvasManager.initElement(r)}else{throw new Error("Canvas is not available. If you're using IE with a fall-back such as Excanvas, then there's either a mistake in your conditional include, or the page has no DOCTYPE and is rendering in Quirks Mode.")}}}this.element=r;var i=this.context=r.getContext("2d");var s=window.devicePixelRatio||1,o=i.webkitBackingStorePixelRatio||i.mozBackingStorePixelRatio||i.msBackingStorePixelRatio||i.oBackingStorePixelRatio||i.backingStorePixelRatio||1;this.pixelRatio=s/o;this.resize(n.width(),n.height());this.textContainer=null;this.text={};this._textCache={}}function r(t,r,s,o){function E(e,t){t=[w].concat(t);for(var n=0;n<e.length;++n)e[n].apply(this,t)}function S(){var t={Canvas:n};for(var r=0;r<o.length;++r){var i=o[r];i.init(w,t);if(i.options)e.extend(true,a,i.options)}}function x(n){e.extend(true,a,n);if(n&&n.colors){a.colors=n.colors}if(a.xaxis.color==null)a.xaxis.color=e.color.parse(a.grid.color).scale("a",.22).toString();if(a.yaxis.color==null)a.yaxis.color=e.color.parse(a.grid.color).scale("a",.22).toString();if(a.xaxis.tickColor==null)a.xaxis.tickColor=a.grid.tickColor||a.xaxis.color;if(a.yaxis.tickColor==null)a.yaxis.tickColor=a.grid.tickColor||a.yaxis.color;if(a.grid.borderColor==null)a.grid.borderColor=a.grid.color;if(a.grid.tickColor==null)a.grid.tickColor=e.color.parse(a.grid.color).scale("a",.22).toString();var r,i,s,o=t.css("font-size"),u=o?+o.replace("px",""):13,f={style:t.css("font-style"),size:Math.round(.8*u),variant:t.css("font-variant"),weight:t.css("font-weight"),family:t.css("font-family")};s=a.xaxes.length||1;for(r=0;r<s;++r){i=a.xaxes[r];if(i&&!i.tickColor){i.tickColor=i.color}i=e.extend(true,{},a.xaxis,i);a.xaxes[r]=i;if(i.font){i.font=e.extend({},f,i.font);if(!i.font.color){i.font.color=i.color}if(!i.font.lineHeight){i.font.lineHeight=Math.round(i.font.size*1.15)}}}s=a.yaxes.length||1;for(r=0;r<s;++r){i=a.yaxes[r];if(i&&!i.tickColor){i.tickColor=i.color}i=e.extend(true,{},a.yaxis,i);a.yaxes[r]=i;if(i.font){i.font=e.extend({},f,i.font);if(!i.font.color){i.font.color=i.color}if(!i.font.lineHeight){i.font.lineHeight=Math.round(i.font.size*1.15)}}}if(a.xaxis.noTicks&&a.xaxis.ticks==null)a.xaxis.ticks=a.xaxis.noTicks;if(a.yaxis.noTicks&&a.yaxis.ticks==null)a.yaxis.ticks=a.yaxis.noTicks;if(a.x2axis){a.xaxes[1]=e.extend(true,{},a.xaxis,a.x2axis);a.xaxes[1].position="top"}if(a.y2axis){a.yaxes[1]=e.extend(true,{},a.yaxis,a.y2axis);a.yaxes[1].position="right"}if(a.grid.coloredAreas)a.grid.markings=a.grid.coloredAreas;if(a.grid.coloredAreasColor)a.grid.markingsColor=a.grid.coloredAreasColor;if(a.lines)e.extend(true,a.series.lines,a.lines);if(a.points)e.extend(true,a.series.points,a.points);if(a.bars)e.extend(true,a.series.bars,a.bars);if(a.shadowSize!=null)a.series.shadowSize=a.shadowSize;if(a.highlightColor!=null)a.series.highlightColor=a.highlightColor;for(r=0;r<a.xaxes.length;++r)O(d,r+1).options=a.xaxes[r];for(r=0;r<a.yaxes.length;++r)O(v,r+1).options=a.yaxes[r];for(var l in b)if(a.hooks[l]&&a.hooks[l].length)b[l]=b[l].concat(a.hooks[l]);E(b.processOptions,[a])}function T(e){u=N(e);M();_()}function N(t){var n=[];for(var r=0;r<t.length;++r){var i=e.extend(true,{},a.series);if(t[r].data!=null){i.data=t[r].data;delete t[r].data;e.extend(true,i,t[r]);t[r].data=i.data}else i.data=t[r];n.push(i)}return n}function C(e,t){var n=e[t+"axis"];if(typeof n=="object")n=n.n;if(typeof n!="number")n=1;return n}function k(){return e.grep(d.concat(v),function(e){return e})}function L(e){var t={},n,r;for(n=0;n<d.length;++n){r=d[n];if(r&&r.used)t["x"+r.n]=r.c2p(e.left)}for(n=0;n<v.length;++n){r=v[n];if(r&&r.used)t["y"+r.n]=r.c2p(e.top)}if(t.x1!==undefined)t.x=t.x1;if(t.y1!==undefined)t.y=t.y1;return t}function A(e){var t={},n,r,i;for(n=0;n<d.length;++n){r=d[n];if(r&&r.used){i="x"+r.n;if(e[i]==null&&r.n==1)i="x";if(e[i]!=null){t.left=r.p2c(e[i]);break}}}for(n=0;n<v.length;++n){r=v[n];if(r&&r.used){i="y"+r.n;if(e[i]==null&&r.n==1)i="y";if(e[i]!=null){t.top=r.p2c(e[i]);break}}}return t}function O(t,n){if(!t[n-1])t[n-1]={n:n,direction:t==d?"x":"y",options:e.extend(true,{},t==d?a.xaxis:a.yaxis)};return t[n-1]}function M(){var t=u.length,n=-1,r;for(r=0;r<u.length;++r){var i=u[r].color;if(i!=null){t--;if(typeof i=="number"&&i>n){n=i}}}if(t<=n){t=n+1}var s,o=[],f=a.colors,l=f.length,c=0;for(r=0;r<t;r++){s=e.color.parse(f[r%l]||"#666");if(r%l==0&&r){if(c>=0){if(c<.5){c=-c-.2}else c=0}else c=-c}o[r]=s.scale("rgb",1+c)}var h=0,p;for(r=0;r<u.length;++r){p=u[r];if(p.color==null){p.color=o[h].toString();++h}else if(typeof p.color=="number")p.color=o[p.color].toString();if(p.lines.show==null){var m,g=true;for(m in p)if(p[m]&&p[m].show){g=false;break}if(g)p.lines.show=true}if(p.lines.zero==null){p.lines.zero=!!p.lines.fill}p.xaxis=O(d,C(p,"x"));p.yaxis=O(v,C(p,"y"))}}function _(){function x(e,t,n){if(t<e.datamin&&t!=-r)e.datamin=t;if(n>e.datamax&&n!=r)e.datamax=n}var t=Number.POSITIVE_INFINITY,n=Number.NEGATIVE_INFINITY,r=Number.MAX_VALUE,i,s,o,a,f,l,c,h,p,d,v,m,g,y,w,S;e.each(k(),function(e,r){r.datamin=t;r.datamax=n;r.used=false});for(i=0;i<u.length;++i){l=u[i];l.datapoints={points:[]};E(b.processRawData,[l,l.data,l.datapoints])}for(i=0;i<u.length;++i){l=u[i];w=l.data;S=l.datapoints.format;if(!S){S=[];S.push({x:true,number:true,required:true});S.push({y:true,number:true,required:true});if(l.bars.show||l.lines.show&&l.lines.fill){var T=!!(l.bars.show&&l.bars.zero||l.lines.show&&l.lines.zero);S.push({y:true,number:true,required:false,defaultValue:0,autoscale:T});if(l.bars.horizontal){delete S[S.length-1].y;S[S.length-1].x=true}}l.datapoints.format=S}if(l.datapoints.pointsize!=null)continue;l.datapoints.pointsize=S.length;h=l.datapoints.pointsize;c=l.datapoints.points;var N=l.lines.show&&l.lines.steps;l.xaxis.used=l.yaxis.used=true;for(s=o=0;s<w.length;++s,o+=h){y=w[s];var C=y==null;if(!C){for(a=0;a<h;++a){m=y[a];g=S[a];if(g){if(g.number&&m!=null){m=+m;if(isNaN(m))m=null;else if(m==Infinity)m=r;else if(m==-Infinity)m=-r}if(m==null){if(g.required)C=true;if(g.defaultValue!=null)m=g.defaultValue}}c[o+a]=m}}if(C){for(a=0;a<h;++a){m=c[o+a];if(m!=null){g=S[a];if(g.autoscale!==false){if(g.x){x(l.xaxis,m,m)}if(g.y){x(l.yaxis,m,m)}}}c[o+a]=null}}else{if(N&&o>0&&c[o-h]!=null&&c[o-h]!=c[o]&&c[o-h+1]!=c[o+1]){for(a=0;a<h;++a)c[o+h+a]=c[o+a];c[o+1]=c[o-h+1];o+=h}}}}for(i=0;i<u.length;++i){l=u[i];E(b.processDatapoints,[l,l.datapoints])}for(i=0;i<u.length;++i){l=u[i];c=l.datapoints.points;h=l.datapoints.pointsize;S=l.datapoints.format;var L=t,A=t,O=n,M=n;for(s=0;s<c.length;s+=h){if(c[s]==null)continue;for(a=0;a<h;++a){m=c[s+a];g=S[a];if(!g||g.autoscale===false||m==r||m==-r)continue;if(g.x){if(m<L)L=m;if(m>O)O=m}if(g.y){if(m<A)A=m;if(m>M)M=m}}}if(l.bars.show){var _;switch(l.bars.align){case"left":_=0;break;case"right":_=-l.bars.barWidth;break;default:_=-l.bars.barWidth/2}if(l.bars.horizontal){A+=_;M+=_+l.bars.barWidth}else{L+=_;O+=_+l.bars.barWidth}}x(l.xaxis,L,O);x(l.yaxis,A,M)}e.each(k(),function(e,r){if(r.datamin==t)r.datamin=null;if(r.datamax==n)r.datamax=null})}function D(){t.css("padding",0).children().filter(function(){return!e(this).hasClass("flot-overlay")&&!e(this).hasClass("flot-base")}).remove();if(t.css("position")=="static")t.css("position","relative");f=new n("flot-base",t);l=new n("flot-overlay",t);h=f.context;p=l.context;c=e(l.element).unbind();var r=t.data("plot");if(r){r.shutdown();l.clear()}t.data("plot",w)}function P(){if(a.grid.hoverable){c.mousemove(at);c.bind("mouseleave",ft)}if(a.grid.clickable)c.click(lt);E(b.bindEvents,[c])}function H(){if(ot)clearTimeout(ot);c.unbind("mousemove",at);c.unbind("mouseleave",ft);c.unbind("click",lt);E(b.shutdown,[c])}function B(e){function t(e){return e}var n,r,i=e.options.transform||t,s=e.options.inverseTransform;if(e.direction=="x"){n=e.scale=g/Math.abs(i(e.max)-i(e.min));r=Math.min(i(e.max),i(e.min))}else{n=e.scale=y/Math.abs(i(e.max)-i(e.min));n=-n;r=Math.max(i(e.max),i(e.min))}if(i==t)e.p2c=function(e){return(e-r)*n};else e.p2c=function(e){return(i(e)-r)*n};if(!s)e.c2p=function(e){return r+e/n};else e.c2p=function(e){return s(r+e/n)}}function j(e){var t=e.options,n=e.ticks||[],r=t.labelWidth||0,i=t.labelHeight||0,s=r||(e.direction=="x"?Math.floor(f.width/(n.length||1)):null),o=e.direction+"Axis "+e.direction+e.n+"Axis",u="flot-"+e.direction+"-axis flot-"+e.direction+e.n+"-axis "+o,a=t.font||"flot-tick-label tickLabel";for(var l=0;l<n.length;++l){var c=n[l];if(!c.label)continue;var h=f.getTextInfo(u,c.label,a,null,s);r=Math.max(r,h.width);i=Math.max(i,h.height)}e.labelWidth=t.labelWidth||r;e.labelHeight=t.labelHeight||i}function F(t){var n=t.labelWidth,r=t.labelHeight,i=t.options.position,s=t.direction==="x",o=t.options.tickLength,u=a.grid.axisMargin,l=a.grid.labelMargin,c=true,h=true,p=true,g=false;e.each(s?d:v,function(e,n){if(n&&n.reserveSpace){if(n===t){g=true}else if(n.options.position===i){if(g){h=false}else{c=false}}if(!g){p=false}}});if(h){u=0}if(o==null){o=p?"full":5}if(!isNaN(+o))l+=+o;if(s){r+=l;if(i=="bottom"){m.bottom+=r+u;t.box={top:f.height-m.bottom,height:r}}else{t.box={top:m.top+u,height:r};m.top+=r+u}}else{n+=l;if(i=="left"){t.box={left:m.left+u,width:n};m.left+=n+u}else{m.right+=n+u;t.box={left:f.width-m.right,width:n}}}t.position=i;t.tickLength=o;t.box.padding=l;t.innermost=c}function I(e){if(e.direction=="x"){e.box.left=m.left-e.labelWidth/2;e.box.width=f.width-m.left-m.right+e.labelWidth}else{e.box.top=m.top-e.labelHeight/2;e.box.height=f.height-m.bottom-m.top+e.labelHeight}}function q(){var t=a.grid.minBorderMargin,n,r;if(t==null){t=0;for(r=0;r<u.length;++r)t=Math.max(t,2*(u[r].points.radius+u[r].points.lineWidth/2))}var i={left:t,right:t,top:t,bottom:t};e.each(k(),function(e,t){if(t.reserveSpace&&t.ticks&&t.ticks.length){var n=t.ticks[t.ticks.length-1];if(t.direction==="x"){i.left=Math.max(i.left,t.labelWidth/2);if(n.v<=t.max){i.right=Math.max(i.right,t.labelWidth/2)}}else{i.bottom=Math.max(i.bottom,t.labelHeight/2);if(n.v<=t.max){i.top=Math.max(i.top,t.labelHeight/2)}}}});m.left=Math.ceil(Math.max(i.left,m.left));m.right=Math.ceil(Math.max(i.right,m.right));m.top=Math.ceil(Math.max(i.top,m.top));m.bottom=Math.ceil(Math.max(i.bottom,m.bottom))}function R(){var t,n=k(),r=a.grid.show;for(var i in m){var s=a.grid.margin||0;m[i]=typeof s=="number"?s:s[i]||0}E(b.processOffset,[m]);for(var i in m){if(typeof a.grid.borderWidth=="object"){m[i]+=r?a.grid.borderWidth[i]:0}else{m[i]+=r?a.grid.borderWidth:0}}e.each(n,function(e,t){t.show=t.options.show;if(t.show==null)t.show=t.used;t.reserveSpace=t.show||t.options.reserveSpace;U(t)});if(r){var o=e.grep(n,function(e){return e.reserveSpace});e.each(o,function(e,t){z(t);W(t);X(t,t.ticks);j(t)});for(t=o.length-1;t>=0;--t)F(o[t]);q();e.each(o,function(e,t){I(t)})}g=f.width-m.left-m.right;y=f.height-m.bottom-m.top;e.each(n,function(e,t){B(t)});if(r){G()}it()}function U(e){var t=e.options,n=+(t.min!=null?t.min:e.datamin),r=+(t.max!=null?t.max:e.datamax),i=r-n;if(i==0){var s=r==0?1:.01;if(t.min==null)n-=s;if(t.max==null||t.min!=null)r+=s}else{var o=t.autoscaleMargin;if(o!=null){if(t.min==null){n-=i*o;if(n<0&&e.datamin!=null&&e.datamin>=0)n=0}if(t.max==null){r+=i*o;if(r>0&&e.datamax!=null&&e.datamax<=0)r=0}}}e.min=n;e.max=r}function z(t){var n=t.options;var r;if(typeof n.ticks=="number"&&n.ticks>0)r=n.ticks;else r=.3*Math.sqrt(t.direction=="x"?f.width:f.height);var s=(t.max-t.min)/r,o=-Math.floor(Math.log(s)/Math.LN10),u=n.tickDecimals;if(u!=null&&o>u){o=u}var a=Math.pow(10,-o),l=s/a,c;if(l<1.5){c=1}else if(l<3){c=2;if(l>2.25&&(u==null||o+1<=u)){c=2.5;++o}}else if(l<7.5){c=5}else{c=10}c*=a;if(n.minTickSize!=null&&c<n.minTickSize){c=n.minTickSize}t.delta=s;t.tickDecimals=Math.max(0,u!=null?u:o);t.tickSize=n.tickSize||c;if(n.mode=="time"&&!t.tickGenerator){throw new Error("Time mode requires the flot.time plugin.")}if(!t.tickGenerator){t.tickGenerator=function(e){var t=[],n=i(e.min,e.tickSize),r=0,s=Number.NaN,o;do{o=s;s=n+r*e.tickSize;t.push(s);++r}while(s<e.max&&s!=o);return t};t.tickFormatter=function(e,t){var n=t.tickDecimals?Math.pow(10,t.tickDecimals):1;var r=""+Math.round(e*n)/n;if(t.tickDecimals!=null){var i=r.indexOf(".");var s=i==-1?0:r.length-i-1;if(s<t.tickDecimals){return(s?r:r+".")+(""+n).substr(1,t.tickDecimals-s)}}return r}}if(e.isFunction(n.tickFormatter))t.tickFormatter=function(e,t){return""+n.tickFormatter(e,t)};if(n.alignTicksWithAxis!=null){var h=(t.direction=="x"?d:v)[n.alignTicksWithAxis-1];if(h&&h.used&&h!=t){var p=t.tickGenerator(t);if(p.length>0){if(n.min==null)t.min=Math.min(t.min,p[0]);if(n.max==null&&p.length>1)t.max=Math.max(t.max,p[p.length-1])}t.tickGenerator=function(e){var t=[],n,r;for(r=0;r<h.ticks.length;++r){n=(h.ticks[r].v-h.min)/(h.max-h.min);n=e.min+n*(e.max-e.min);t.push(n)}return t};if(!t.mode&&n.tickDecimals==null){var m=Math.max(0,-Math.floor(Math.log(t.delta)/Math.LN10)+1),g=t.tickGenerator(t);if(!(g.length>1&&/\..*0$/.test((g[1]-g[0]).toFixed(m))))t.tickDecimals=m}}}}function W(t){var n=t.options.ticks,r=[];if(n==null||typeof n=="number"&&n>0)r=t.tickGenerator(t);else if(n){if(e.isFunction(n))r=n(t);else r=n}var i,s;t.ticks=[];for(i=0;i<r.length;++i){var o=null;var u=r[i];if(typeof u=="object"){s=+u[0];if(u.length>1)o=u[1]}else s=+u;if(o==null)o=t.tickFormatter(s,t);if(!isNaN(s))t.ticks.push({v:s,label:o})}}function X(e,t){if(e.options.autoscaleMargin&&t.length>0){if(e.options.min==null)e.min=Math.min(e.min,t[0].v);if(e.options.max==null&&t.length>1)e.max=Math.max(e.max,t[t.length-1].v)}}function V(){f.clear();E(b.drawBackground,[h]);var e=a.grid;if(e.show&&e.backgroundColor)K();if(e.show&&!e.aboveData){Q()}for(var t=0;t<u.length;++t){E(b.drawSeries,[h,u[t]]);Y(u[t])}E(b.draw,[h]);if(e.show&&e.aboveData){Q()}f.render();ht()}function J(e,t){var n,r,i,s,o=k();for(var u=0;u<o.length;++u){n=o[u];if(n.direction==t){s=t+n.n+"axis";if(!e[s]&&n.n==1)s=t+"axis";if(e[s]){r=e[s].from;i=e[s].to;break}}}if(!e[s]){n=t=="x"?d[0]:v[0];r=e[t+"1"];i=e[t+"2"]}if(r!=null&&i!=null&&r>i){var a=r;r=i;i=a}return{from:r,to:i,axis:n}}function K(){h.save();h.translate(m.left,m.top);h.fillStyle=bt(a.grid.backgroundColor,y,0,"rgba(255, 255, 255, 0)");h.fillRect(0,0,g,y);h.restore()}function Q(){var t,n,r,i;h.save();h.translate(m.left,m.top);var s=a.grid.markings;if(s){if(e.isFunction(s)){n=w.getAxes();n.xmin=n.xaxis.min;n.xmax=n.xaxis.max;n.ymin=n.yaxis.min;n.ymax=n.yaxis.max;s=s(n)}for(t=0;t<s.length;++t){var o=s[t],u=J(o,"x"),f=J(o,"y");if(u.from==null)u.from=u.axis.min;if(u.to==null)u.to=u.axis.max;if(f.from==null)f.from=f.axis.min;if(f.to==null)f.to=f.axis.max;if(u.to<u.axis.min||u.from>u.axis.max||f.to<f.axis.min||f.from>f.axis.max)continue;u.from=Math.max(u.from,u.axis.min);u.to=Math.min(u.to,u.axis.max);f.from=Math.max(f.from,f.axis.min);f.to=Math.min(f.to,f.axis.max);if(u.from==u.to&&f.from==f.to)continue;u.from=u.axis.p2c(u.from);u.to=u.axis.p2c(u.to);f.from=f.axis.p2c(f.from);f.to=f.axis.p2c(f.to);if(u.from==u.to||f.from==f.to){h.beginPath();h.strokeStyle=o.color||a.grid.markingsColor;h.lineWidth=o.lineWidth||a.grid.markingsLineWidth;h.moveTo(u.from,f.from);h.lineTo(u.to,f.to);h.stroke()}else{h.fillStyle=o.color||a.grid.markingsColor;h.fillRect(u.from,f.to,u.to-u.from,f.from-f.to)}}}n=k();r=a.grid.borderWidth;for(var l=0;l<n.length;++l){var c=n[l],p=c.box,d=c.tickLength,v,b,E,S;if(!c.show||c.ticks.length==0)continue;h.lineWidth=1;if(c.direction=="x"){v=0;if(d=="full")b=c.position=="top"?0:y;else b=p.top-m.top+(c.position=="top"?p.height:0)}else{b=0;if(d=="full")v=c.position=="left"?0:g;else v=p.left-m.left+(c.position=="left"?p.width:0)}if(!c.innermost){h.strokeStyle=c.options.color;h.beginPath();E=S=0;if(c.direction=="x")E=g+1;else S=y+1;if(h.lineWidth==1){if(c.direction=="x"){b=Math.floor(b)+.5}else{v=Math.floor(v)+.5}}h.moveTo(v,b);h.lineTo(v+E,b+S);h.stroke()}h.strokeStyle=c.options.tickColor;h.beginPath();for(t=0;t<c.ticks.length;++t){var x=c.ticks[t].v;E=S=0;if(isNaN(x)||x<c.min||x>c.max||d=="full"&&(typeof r=="object"&&r[c.position]>0||r>0)&&(x==c.min||x==c.max))continue;if(c.direction=="x"){v=c.p2c(x);S=d=="full"?-y:d;if(c.position=="top")S=-S}else{b=c.p2c(x);E=d=="full"?-g:d;if(c.position=="left")E=-E}if(h.lineWidth==1){if(c.direction=="x")v=Math.floor(v)+.5;else b=Math.floor(b)+.5}h.moveTo(v,b);h.lineTo(v+E,b+S)}h.stroke()}if(r){i=a.grid.borderColor;if(typeof r=="object"||typeof i=="object"){if(typeof r!=="object"){r={top:r,right:r,bottom:r,left:r}}if(typeof i!=="object"){i={top:i,right:i,bottom:i,left:i}}if(r.top>0){h.strokeStyle=i.top;h.lineWidth=r.top;h.beginPath();h.moveTo(0-r.left,0-r.top/2);h.lineTo(g,0-r.top/2);h.stroke()}if(r.right>0){h.strokeStyle=i.right;h.lineWidth=r.right;h.beginPath();h.moveTo(g+r.right/2,0-r.top);h.lineTo(g+r.right/2,y);h.stroke()}if(r.bottom>0){h.strokeStyle=i.bottom;h.lineWidth=r.bottom;h.beginPath();h.moveTo(g+r.right,y+r.bottom/2);h.lineTo(0,y+r.bottom/2);h.stroke()}if(r.left>0){h.strokeStyle=i.left;h.lineWidth=r.left;h.beginPath();h.moveTo(0-r.left/2,y+r.bottom);h.lineTo(0-r.left/2,0);h.stroke()}}else{h.lineWidth=r;h.strokeStyle=a.grid.borderColor;h.strokeRect(-r/2,-r/2,g+r,y+r)}}h.restore()}function G(){e.each(k(),function(e,t){var n=t.box,r=t.direction+"Axis "+t.direction+t.n+"Axis",i="flot-"+t.direction+"-axis flot-"+t.direction+t.n+"-axis "+r,s=t.options.font||"flot-tick-label tickLabel",o,u,a,l,c;f.removeText(i);if(!t.show||t.ticks.length==0)return;for(var h=0;h<t.ticks.length;++h){o=t.ticks[h];if(!o.label||o.v<t.min||o.v>t.max)continue;if(t.direction=="x"){l="center";u=m.left+t.p2c(o.v);if(t.position=="bottom"){a=n.top+n.padding}else{a=n.top+n.height-n.padding;c="bottom"}}else{c="middle";a=m.top+t.p2c(o.v);if(t.position=="left"){u=n.left+n.width-n.padding;l="right"}else{u=n.left+n.padding}}f.addText(i,u,a,o.label,s,null,null,l,c)}})}function Y(e){if(e.lines.show)Z(e);if(e.bars.show)nt(e);if(e.points.show)et(e)}function Z(e){function t(e,t,n,r,i){var s=e.points,o=e.pointsize,u=null,a=null;h.beginPath();for(var f=o;f<s.length;f+=o){var l=s[f-o],c=s[f-o+1],p=s[f],d=s[f+1];if(l==null||p==null)continue;if(c<=d&&c<i.min){if(d<i.min)continue;l=(i.min-c)/(d-c)*(p-l)+l;c=i.min}else if(d<=c&&d<i.min){if(c<i.min)continue;p=(i.min-c)/(d-c)*(p-l)+l;d=i.min}if(c>=d&&c>i.max){if(d>i.max)continue;l=(i.max-c)/(d-c)*(p-l)+l;c=i.max}else if(d>=c&&d>i.max){if(c>i.max)continue;p=(i.max-c)/(d-c)*(p-l)+l;d=i.max}if(l<=p&&l<r.min){if(p<r.min)continue;c=(r.min-l)/(p-l)*(d-c)+c;l=r.min}else if(p<=l&&p<r.min){if(l<r.min)continue;d=(r.min-l)/(p-l)*(d-c)+c;p=r.min}if(l>=p&&l>r.max){if(p>r.max)continue;c=(r.max-l)/(p-l)*(d-c)+c;l=r.max}else if(p>=l&&p>r.max){if(l>r.max)continue;d=(r.max-l)/(p-l)*(d-c)+c;p=r.max}if(l!=u||c!=a)h.moveTo(r.p2c(l)+t,i.p2c(c)+n);u=p;a=d;h.lineTo(r.p2c(p)+t,i.p2c(d)+n)}h.stroke()}function n(e,t,n){var r=e.points,i=e.pointsize,s=Math.min(Math.max(0,n.min),n.max),o=0,u,a=false,f=1,l=0,c=0;while(true){if(i>0&&o>r.length+i)break;o+=i;var p=r[o-i],d=r[o-i+f],v=r[o],m=r[o+f];if(a){if(i>0&&p!=null&&v==null){c=o;i=-i;f=2;continue}if(i<0&&o==l+i){h.fill();a=false;i=-i;f=1;o=l=c+i;continue}}if(p==null||v==null)continue;if(p<=v&&p<t.min){if(v<t.min)continue;d=(t.min-p)/(v-p)*(m-d)+d;p=t.min}else if(v<=p&&v<t.min){if(p<t.min)continue;m=(t.min-p)/(v-p)*(m-d)+d;v=t.min}if(p>=v&&p>t.max){if(v>t.max)continue;d=(t.max-p)/(v-p)*(m-d)+d;p=t.max}else if(v>=p&&v>t.max){if(p>t.max)continue;m=(t.max-p)/(v-p)*(m-d)+d;v=t.max}if(!a){h.beginPath();h.moveTo(t.p2c(p),n.p2c(s));a=true}if(d>=n.max&&m>=n.max){h.lineTo(t.p2c(p),n.p2c(n.max));h.lineTo(t.p2c(v),n.p2c(n.max));continue}else if(d<=n.min&&m<=n.min){h.lineTo(t.p2c(p),n.p2c(n.min));h.lineTo(t.p2c(v),n.p2c(n.min));continue}var g=p,y=v;if(d<=m&&d<n.min&&m>=n.min){p=(n.min-d)/(m-d)*(v-p)+p;d=n.min}else if(m<=d&&m<n.min&&d>=n.min){v=(n.min-d)/(m-d)*(v-p)+p;m=n.min}if(d>=m&&d>n.max&&m<=n.max){p=(n.max-d)/(m-d)*(v-p)+p;d=n.max}else if(m>=d&&m>n.max&&d<=n.max){v=(n.max-d)/(m-d)*(v-p)+p;m=n.max}if(p!=g){h.lineTo(t.p2c(g),n.p2c(d))}h.lineTo(t.p2c(p),n.p2c(d));h.lineTo(t.p2c(v),n.p2c(m));if(v!=y){h.lineTo(t.p2c(v),n.p2c(m));h.lineTo(t.p2c(y),n.p2c(m))}}}h.save();h.translate(m.left,m.top);h.lineJoin="round";var r=e.lines.lineWidth,i=e.shadowSize;if(r>0&&i>0){h.lineWidth=i;h.strokeStyle="rgba(0,0,0,0.1)";var s=Math.PI/18;t(e.datapoints,Math.sin(s)*(r/2+i/2),Math.cos(s)*(r/2+i/2),e.xaxis,e.yaxis);h.lineWidth=i/2;t(e.datapoints,Math.sin(s)*(r/2+i/4),Math.cos(s)*(r/2+i/4),e.xaxis,e.yaxis)}h.lineWidth=r;h.strokeStyle=e.color;var o=rt(e.lines,e.color,0,y);if(o){h.fillStyle=o;n(e.datapoints,e.xaxis,e.yaxis)}if(r>0)t(e.datapoints,0,0,e.xaxis,e.yaxis);h.restore()}function et(e){function t(e,t,n,r,i,s,o,u){var a=e.points,f=e.pointsize;for(var l=0;l<a.length;l+=f){var c=a[l],p=a[l+1];if(c==null||c<s.min||c>s.max||p<o.min||p>o.max)continue;h.beginPath();c=s.p2c(c);p=o.p2c(p)+r;if(u=="circle")h.arc(c,p,t,0,i?Math.PI:Math.PI*2,false);else u(h,c,p,t,i);h.closePath();if(n){h.fillStyle=n;h.fill()}h.stroke()}}h.save();h.translate(m.left,m.top);var n=e.points.lineWidth,r=e.shadowSize,i=e.points.radius,s=e.points.symbol;if(n==0)n=1e-4;if(n>0&&r>0){var o=r/2;h.lineWidth=o;h.strokeStyle="rgba(0,0,0,0.1)";t(e.datapoints,i,null,o+o/2,true,e.xaxis,e.yaxis,s);h.strokeStyle="rgba(0,0,0,0.2)";t(e.datapoints,i,null,o/2,true,e.xaxis,e.yaxis,s)}h.lineWidth=n;h.strokeStyle=e.color;t(e.datapoints,i,rt(e.points,e.color),0,false,e.xaxis,e.yaxis,s);h.restore()}function tt(e,t,n,r,i,s,o,u,a,f,l){var c,h,p,d,v,m,g,y,b;if(f){y=m=g=true;v=false;c=n;h=e;d=t+r;p=t+i;if(h<c){b=h;h=c;c=b;v=true;m=false}}else{v=m=g=true;y=false;c=e+r;h=e+i;p=n;d=t;if(d<p){b=d;d=p;p=b;y=true;g=false}}if(h<o.min||c>o.max||d<u.min||p>u.max)return;if(c<o.min){c=o.min;v=false}if(h>o.max){h=o.max;m=false}if(p<u.min){p=u.min;y=false}if(d>u.max){d=u.max;g=false}c=o.p2c(c);p=u.p2c(p);h=o.p2c(h);d=u.p2c(d);if(s){a.fillStyle=s(p,d);a.fillRect(c,d,h-c,p-d)}if(l>0&&(v||m||g||y)){a.beginPath();a.moveTo(c,p);if(v)a.lineTo(c,d);else a.moveTo(c,d);if(g)a.lineTo(h,d);else a.moveTo(h,d);if(m)a.lineTo(h,p);else a.moveTo(h,p);if(y)a.lineTo(c,p);else a.moveTo(c,p);a.stroke()}}function nt(e){function t(t,n,r,i,s,o){var u=t.points,a=t.pointsize;for(var f=0;f<u.length;f+=a){if(u[f]==null)continue;tt(u[f],u[f+1],u[f+2],n,r,i,s,o,h,e.bars.horizontal,e.bars.lineWidth)}}h.save();h.translate(m.left,m.top);h.lineWidth=e.bars.lineWidth;h.strokeStyle=e.color;var n;switch(e.bars.align){case"left":n=0;break;case"right":n=-e.bars.barWidth;break;default:n=-e.bars.barWidth/2}var r=e.bars.fill?function(t,n){return rt(e.bars,e.color,t,n)}:null;t(e.datapoints,n,n+e.bars.barWidth,r,e.xaxis,e.yaxis);h.restore()}function rt(t,n,r,i){var s=t.fill;if(!s)return null;if(t.fillColor)return bt(t.fillColor,r,i,n);var o=e.color.parse(n);o.a=typeof s=="number"?s:.4;o.normalize();return o.toString()}function it(){if(a.legend.container!=null){e(a.legend.container).html("")}else{t.find(".legend").remove()}if(!a.legend.show){return}var n=[],r=[],i=false,s=a.legend.labelFormatter,o,f;for(var l=0;l<u.length;++l){o=u[l];if(o.label){f=s?s(o.label,o):o.label;if(f){r.push({label:f,color:o.color})}}}if(a.legend.sorted){if(e.isFunction(a.legend.sorted)){r.sort(a.legend.sorted)}else if(a.legend.sorted=="reverse"){r.reverse()}else{var c=a.legend.sorted!="descending";r.sort(function(e,t){return e.label==t.label?0:e.label<t.label!=c?1:-1})}}for(var l=0;l<r.length;++l){var h=r[l];if(l%a.legend.noColumns==0){if(i)n.push("</tr>");n.push("<tr>");i=true}n.push('<td class="legendColorBox"><div style="border:1px solid '+a.legend.labelBoxBorderColor+';padding:1px"><div style="width:4px;height:0;border:5px solid '+h.color+';overflow:hidden"></div></div></td>'+'<td class="legendLabel">'+h.label+"</td>")}if(i)n.push("</tr>");if(n.length==0)return;var p='<table style="font-size:smaller;color:'+a.grid.color+'">'+n.join("")+"</table>";if(a.legend.container!=null)e(a.legend.container).html(p);else{var d="",v=a.legend.position,g=a.legend.margin;if(g[0]==null)g=[g,g];if(v.charAt(0)=="n")d+="top:"+(g[1]+m.top)+"px;";else if(v.charAt(0)=="s")d+="bottom:"+(g[1]+m.bottom)+"px;";if(v.charAt(1)=="e")d+="right:"+(g[0]+m.right)+"px;";else if(v.charAt(1)=="w")d+="left:"+(g[0]+m.left)+"px;";var y=e('<div class="legend">'+p.replace('style="','style="position:absolute;'+d+";")+"</div>").appendTo(t);if(a.legend.backgroundOpacity!=0){var b=a.legend.backgroundColor;if(b==null){b=a.grid.backgroundColor;if(b&&typeof b=="string")b=e.color.parse(b);else b=e.color.extract(y,"background-color");b.a=1;b=b.toString()}var w=y.children();e('<div style="position:absolute;width:'+w.width()+"px;height:"+w.height()+"px;"+d+"background-color:"+b+';"> </div>').prependTo(y).css("opacity",a.legend.backgroundOpacity)}}}function ut(e,t,n){var r=a.grid.mouseActiveRadius,i=r*r+1,s=null,o=false,f,l,c;for(f=u.length-1;f>=0;--f){if(!n(u[f]))continue;var h=u[f],p=h.xaxis,d=h.yaxis,v=h.datapoints.points,m=p.c2p(e),g=d.c2p(t),y=r/p.scale,b=r/d.scale;c=h.datapoints.pointsize;if(p.options.inverseTransform)y=Number.MAX_VALUE;if(d.options.inverseTransform)b=Number.MAX_VALUE;if(h.lines.show||h.points.show){for(l=0;l<v.length;l+=c){var w=v[l],E=v[l+1];if(w==null)continue;if(w-m>y||w-m<-y||E-g>b||E-g<-b)continue;var S=Math.abs(p.p2c(w)-e),x=Math.abs(d.p2c(E)-t),T=S*S+x*x;if(T<i){i=T;s=[f,l/c]}}}if(h.bars.show&&!s){var N,C;switch(h.bars.align){case"left":N=0;break;case"right":N=-h.bars.barWidth;break;default:N=-h.bars.barWidth/2}C=N+h.bars.barWidth;for(l=0;l<v.length;l+=c){var w=v[l],E=v[l+1],k=v[l+2];if(w==null)continue;if(u[f].bars.horizontal?m<=Math.max(k,w)&&m>=Math.min(k,w)&&g>=E+N&&g<=E+C:m>=w+N&&m<=w+C&&g>=Math.min(k,E)&&g<=Math.max(k,E))s=[f,l/c]}}}if(s){f=s[0];l=s[1];c=u[f].datapoints.pointsize;return{datapoint:u[f].datapoints.points.slice(l*c,(l+1)*c),dataIndex:l,series:u[f],seriesIndex:f}}return null}function at(e){if(a.grid.hoverable)ct("plothover",e,function(e){return e["hoverable"]!=false})}function ft(e){if(a.grid.hoverable)ct("plothover",e,function(e){return false})}function lt(e){ct("plotclick",e,function(e){return e["clickable"]!=false})}function ct(e,n,r){var i=c.offset(),s=n.pageX-i.left-m.left,o=n.pageY-i.top-m.top,u=L({left:s,top:o});u.pageX=n.pageX;u.pageY=n.pageY;var f=ut(s,o,r);if(f){f.pageX=parseInt(f.series.xaxis.p2c(f.datapoint[0])+i.left+m.left,10);f.pageY=parseInt(f.series.yaxis.p2c(f.datapoint[1])+i.top+m.top,10)}if(a.grid.autoHighlight){for(var l=0;l<st.length;++l){var h=st[l];if(h.auto==e&&!(f&&h.series==f.series&&h.point[0]==f.datapoint[0]&&h.point[1]==f.datapoint[1]))vt(h.series,h.point)}if(f)dt(f.series,f.datapoint,e)}t.trigger(e,[u,f])}function ht(){var e=a.interaction.redrawOverlayInterval;if(e==-1){pt();return}if(!ot)ot=setTimeout(pt,e)}function pt(){ot=null;p.save();l.clear();p.translate(m.left,m.top);var e,t;for(e=0;e<st.length;++e){t=st[e];if(t.series.bars.show)yt(t.series,t.point);else gt(t.series,t.point)}p.restore();E(b.drawOverlay,[p])}function dt(e,t,n){if(typeof e=="number")e=u[e];if(typeof t=="number"){var r=e.datapoints.pointsize;t=e.datapoints.points.slice(r*t,r*(t+1))}var i=mt(e,t);if(i==-1){st.push({series:e,point:t,auto:n});ht()}else if(!n)st[i].auto=false}function vt(e,t){if(e==null&&t==null){st=[];ht();return}if(typeof e=="number")e=u[e];if(typeof t=="number"){var n=e.datapoints.pointsize;t=e.datapoints.points.slice(n*t,n*(t+1))}var r=mt(e,t);if(r!=-1){st.splice(r,1);ht()}}function mt(e,t){for(var n=0;n<st.length;++n){var r=st[n];if(r.series==e&&r.point[0]==t[0]&&r.point[1]==t[1])return n}return-1}function gt(t,n){var r=n[0],i=n[1],s=t.xaxis,o=t.yaxis,u=typeof t.highlightColor==="string"?t.highlightColor:e.color.parse(t.color).scale("a",.5).toString();if(r<s.min||r>s.max||i<o.min||i>o.max)return;var a=t.points.radius+t.points.lineWidth/2;p.lineWidth=a;p.strokeStyle=u;var f=1.5*a;r=s.p2c(r);i=o.p2c(i);p.beginPath();if(t.points.symbol=="circle")p.arc(r,i,f,0,2*Math.PI,false);else t.points.symbol(p,r,i,f,false);p.closePath();p.stroke()}function yt(t,n){var r=typeof t.highlightColor==="string"?t.highlightColor:e.color.parse(t.color).scale("a",.5).toString(),i=r,s;switch(t.bars.align){case"left":s=0;break;case"right":s=-t.bars.barWidth;break;default:s=-t.bars.barWidth/2}p.lineWidth=t.bars.lineWidth;p.strokeStyle=r;tt(n[0],n[1],n[2]||0,s,s+t.bars.barWidth,function(){return i},t.xaxis,t.yaxis,p,t.bars.horizontal,t.bars.lineWidth)}function bt(t,n,r,i){if(typeof t=="string")return t;else{var s=h.createLinearGradient(0,r,0,n);for(var o=0,u=t.colors.length;o<u;++o){var a=t.colors[o];if(typeof a!="string"){var f=e.color.parse(i);if(a.brightness!=null)f=f.scale("rgb",a.brightness);if(a.opacity!=null)f.a*=a.opacity;a=f.toString()}s.addColorStop(o/(u-1),a)}return s}}var u=[],a={colors:["#edc240","#afd8f8","#cb4b4b","#4da74d","#9440ed"],legend:{show:true,noColumns:1,labelFormatter:null,labelBoxBorderColor:"#ccc",container:null,position:"ne",margin:5,backgroundColor:null,backgroundOpacity:.85,sorted:null},xaxis:{show:null,position:"bottom",mode:null,font:null,color:null,tickColor:null,transform:null,inverseTransform:null,min:null,max:null,autoscaleMargin:null,ticks:null,tickFormatter:null,labelWidth:null,labelHeight:null,reserveSpace:null,tickLength:null,alignTicksWithAxis:null,tickDecimals:null,tickSize:null,minTickSize:null},yaxis:{autoscaleMargin:.02,position:"left"},xaxes:[],yaxes:[],series:{points:{show:false,radius:3,lineWidth:2,fill:true,fillColor:"#ffffff",symbol:"circle"},lines:{lineWidth:2,fill:false,fillColor:null,steps:false},bars:{show:false,lineWidth:2,barWidth:1,fill:true,fillColor:null,align:"left",horizontal:false,zero:true},shadowSize:3,highlightColor:null},grid:{show:true,aboveData:false,color:"#545454",backgroundColor:null,borderColor:null,tickColor:null,margin:0,labelMargin:5,axisMargin:8,borderWidth:2,minBorderMargin:null,markings:null,markingsColor:"#f4f4f4",markingsLineWidth:2,clickable:false,hoverable:false,autoHighlight:true,mouseActiveRadius:10},interaction:{redrawOverlayInterval:1e3/60},hooks:{}},f=null,l=null,c=null,h=null,p=null,d=[],v=[],m={left:0,right:0,top:0,bottom:0},g=0,y=0,b={processOptions:[],processRawData:[],processDatapoints:[],processOffset:[],drawBackground:[],drawSeries:[],draw:[],bindEvents:[],drawOverlay:[],shutdown:[]},w=this;w.setData=T;w.setupGrid=R;w.draw=V;w.getPlaceholder=function(){return t};w.getCanvas=function(){return f.element};w.getPlotOffset=function(){return m};w.width=function(){return g};w.height=function(){return y};w.offset=function(){var e=c.offset();e.left+=m.left;e.top+=m.top;return e};w.getData=function(){return u};w.getAxes=function(){var t={},n;e.each(d.concat(v),function(e,n){if(n)t[n.direction+(n.n!=1?n.n:"")+"axis"]=n});return t};w.getXAxes=function(){return d};w.getYAxes=function(){return v};w.c2p=L;w.p2c=A;w.getOptions=function(){return a};w.highlight=dt;w.unhighlight=vt;w.triggerRedrawOverlay=ht;w.pointOffset=function(e){return{left:parseInt(d[C(e,"x")-1].p2c(+e.x)+m.left,10),top:parseInt(v[C(e,"y")-1].p2c(+e.y)+m.top,10)}};w.shutdown=H;w.destroy=function(){H();t.removeData("plot").empty();u=[];a=null;f=null;l=null;c=null;h=null;p=null;d=[];v=[];b=null;st=[];w=null};w.resize=function(){var e=t.width(),n=t.height();f.resize(e,n);l.resize(e,n)};w.hooks=b;S(w);x(s);D();T(r);R();V();P();var st=[],ot=null}function i(e,t){return t*Math.floor(e/t)}var t=Object.prototype.hasOwnProperty;n.prototype.resize=function(e,t){if(e<=0||t<=0){throw new Error("Invalid dimensions for plot, width = "+e+", height = "+t)}var n=this.element,r=this.context,i=this.pixelRatio;if(this.width!=e){n.width=e*i;n.style.width=e+"px";this.width=e}if(this.height!=t){n.height=t*i;n.style.height=t+"px";this.height=t}r.restore();r.save();r.scale(i,i)};n.prototype.clear=function(){this.context.clearRect(0,0,this.width,this.height)};n.prototype.render=function(){var e=this._textCache;for(var n in e){if(t.call(e,n)){var r=this.getTextLayer(n),i=e[n];r.hide();for(var s in i){if(t.call(i,s)){var o=i[s];for(var u in o){if(t.call(o,u)){var a=o[u].positions;for(var f=0,l;l=a[f];f++){if(l.active){if(!l.rendered){r.append(l.element);l.rendered=true}}else{a.splice(f--,1);if(l.rendered){l.element.detach()}}}if(a.length==0){delete o[u]}}}}}r.show()}}};n.prototype.getTextLayer=function(t){var n=this.text[t];if(n==null){if(this.textContainer==null){this.textContainer=e("<div class='flot-text'></div>").css({position:"absolute",top:0,left:0,bottom:0,right:0,"font-size":"smaller",color:"#545454"}).insertAfter(this.element)}n=this.text[t]=e("<div></div>").addClass(t).css({position:"absolute",top:0,left:0,bottom:0,right:0}).appendTo(this.textContainer)}return n};n.prototype.getTextInfo=function(t,n,r,i,s){var o,u,a,f;n=""+n;if(typeof r==="object"){o=r.style+" "+r.variant+" "+r.weight+" "+r.size+"px/"+r.lineHeight+"px "+r.family}else{o=r}u=this._textCache[t];if(u==null){u=this._textCache[t]={}}a=u[o];if(a==null){a=u[o]={}}f=a[n];if(f==null){var l=e("<div></div>").html(n).css({position:"absolute","max-width":s,top:-9999}).appendTo(this.getTextLayer(t));if(typeof r==="object"){l.css({font:o,color:r.color})}else if(typeof r==="string"){l.addClass(r)}f=a[n]={width:l.outerWidth(true),height:l.outerHeight(true),element:l,positions:[]};l.detach()}return f};n.prototype.addText=function(e,t,n,r,i,s,o,u,a){var f=this.getTextInfo(e,r,i,s,o),l=f.positions;if(u=="center"){t-=f.width/2}else if(u=="right"){t-=f.width}if(a=="middle"){n-=f.height/2}else if(a=="bottom"){n-=f.height}for(var c=0,h;h=l[c];c++){if(h.x==t&&h.y==n){h.active=true;return}}h={active:true,rendered:false,element:l.length?f.element.clone():f.element,x:t,y:n};l.push(h);h.element.css({top:Math.round(n),left:Math.round(t),"text-align":u})};n.prototype.removeText=function(e,n,r,i,s,o){if(i==null){var u=this._textCache[e];if(u!=null){for(var a in u){if(t.call(u,a)){var f=u[a];for(var l in f){if(t.call(f,l)){var c=f[l].positions;for(var h=0,p;p=c[h];h++){p.active=false}}}}}}}else{var c=this.getTextInfo(e,i,s,o).positions;for(var h=0,p;p=c[h];h++){if(p.x==n&&p.y==r){p.active=false}}}};e.plot=function(t,n,i){var s=new r(e(t),n,i,e.plot.plugins);return s};e.plot.version="0.8.2";e.plot.plugins=[];e.fn.plot=function(t,n){return this.each(function(){e.plot(this,t,n)})}})(jQuery);

/* flot navigate v0.8.2 |  https://github.com/flot/flot | Copyright (c) 2007-2013 IOLA and Ole Laursen. Licensed under the MIT license. */
(function(e){function t(i){var l,h=this,p=i.data||{};if(p.elem)h=i.dragTarget=p.elem,i.dragProxy=a.proxy||h,i.cursorOffsetX=p.pageX-p.left,i.cursorOffsetY=p.pageY-p.top,i.offsetX=i.pageX-i.cursorOffsetX,i.offsetY=i.pageY-i.cursorOffsetY;else if(a.dragging||p.which>0&&i.which!=p.which||e(i.target).is(p.not))return;switch(i.type){case"mousedown":return e.extend(p,e(h).offset(),{elem:h,target:i.target,pageX:i.pageX,pageY:i.pageY}),o.add(document,"mousemove mouseup",t,p),s(h,!1),a.dragging=null,!1;case!a.dragging&&"mousemove":if(r(i.pageX-p.pageX)+r(i.pageY-p.pageY)<p.distance)break;i.target=p.target,l=n(i,"dragstart",h),l!==!1&&(a.dragging=h,a.proxy=i.dragProxy=e(l||h)[0]);case"mousemove":if(a.dragging){if(l=n(i,"drag",h),u.drop&&(u.drop.allowed=l!==!1,u.drop.handler(i)),l!==!1)break;i.type="mouseup"};case"mouseup":o.remove(document,"mousemove mouseup",t),a.dragging&&(u.drop&&u.drop.handler(i),n(i,"dragend",h)),s(h,!0),a.dragging=a.proxy=p.elem=!1}return!0}function n(t,n,r){t.type=n;var i=e.event.dispatch.call(r,t);return i===!1?!1:i||t.result}function r(e){return Math.pow(e,2)}function i(){return a.dragging===!1}function s(e,t){e&&(e.unselectable=t?"off":"on",e.onselectstart=function(){return t},e.style&&(e.style.MozUserSelect=t?"":"none"))}e.fn.drag=function(e,t,n){return t&&this.bind("dragstart",e),n&&this.bind("dragend",n),e?this.bind("drag",t?t:e):this.trigger("drag")};var o=e.event,u=o.special,a=u.drag={not:":input",distance:0,which:1,dragging:!1,setup:function(n){n=e.extend({distance:a.distance,which:a.which,not:a.not},n||{}),n.distance=r(n.distance),o.add(this,"mousedown",t,n),this.attachEvent&&this.attachEvent("ondragstart",i)},teardown:function(){o.remove(this,"mousedown",t),this===a.dragging&&(a.dragging=a.proxy=!1),s(this,!0),this.detachEvent&&this.detachEvent("ondragstart",i)}};u.dragstart=u.dragend={setup:function(){},teardown:function(){}}})(jQuery);(function(e){function t(t){var n=t||window.event,r=[].slice.call(arguments,1),i=0,s=0,o=0,t=e.event.fix(n);t.type="mousewheel";n.wheelDelta&&(i=n.wheelDelta/120);n.detail&&(i=-n.detail/3);o=i;void 0!==n.axis&&n.axis===n.HORIZONTAL_AXIS&&(o=0,s=-1*i);void 0!==n.wheelDeltaY&&(o=n.wheelDeltaY/120);void 0!==n.wheelDeltaX&&(s=-1*n.wheelDeltaX/120);r.unshift(t,i,s,o);return(e.event.dispatch||e.event.handle).apply(this,r)}var n=["DOMMouseScroll","mousewheel"];if(e.event.fixHooks)for(var r=n.length;r;)e.event.fixHooks[n[--r]]=e.event.mouseHooks;e.event.special.mousewheel={setup:function(){if(this.addEventListener)for(var e=n.length;e;)this.addEventListener(n[--e],t,!1);else this.onmousewheel=t},teardown:function(){if(this.removeEventListener)for(var e=n.length;e;)this.removeEventListener(n[--e],t,!1);else this.onmousewheel=null}};e.fn.extend({mousewheel:function(e){return e?this.bind("mousewheel",e):this.trigger("mousewheel")},unmousewheel:function(e){return this.unbind("mousewheel",e)}})})(jQuery);(function(e){function n(t){function n(e,n){var r=t.offset();r.left=e.pageX-r.left;r.top=e.pageY-r.top;if(n)t.zoomOut({center:r});else t.zoom({center:r})}function r(e,t){e.preventDefault();n(e,t<0);return false}function a(e){if(e.which!=1)return false;var n=t.getPlaceholder().css("cursor");if(n)i=n;t.getPlaceholder().css("cursor",t.getOptions().pan.cursor);s=e.pageX;o=e.pageY}function f(e){var n=t.getOptions().pan.frameRate;if(u||!n)return;u=setTimeout(function(){t.pan({left:s-e.pageX,top:o-e.pageY});s=e.pageX;o=e.pageY;u=null},1/n*1e3)}function l(e){if(u){clearTimeout(u);u=null}t.getPlaceholder().css("cursor",i);t.pan({left:s-e.pageX,top:o-e.pageY})}function c(e,t){var i=e.getOptions();if(i.zoom.interactive){t[i.zoom.trigger](n);t.mousewheel(r)}if(i.pan.interactive){t.bind("dragstart",{distance:10},a);t.bind("drag",f);t.bind("dragend",l)}}function h(e,t){t.unbind(e.getOptions().zoom.trigger,n);t.unbind("mousewheel",r);t.unbind("dragstart",a);t.unbind("drag",f);t.unbind("dragend",l);if(u)clearTimeout(u)}var i="default",s=0,o=0,u=null;t.zoomOut=function(e){if(!e)e={};if(!e.amount)e.amount=t.getOptions().zoom.amount;e.amount=1/e.amount;t.zoom(e)};t.zoom=function(n){if(!n)n={};var r=n.center,i=n.amount||t.getOptions().zoom.amount,s=t.width(),o=t.height();if(!r)r={left:s/2,top:o/2};var u=r.left/s,a=r.top/o,f={x:{min:r.left-u*s/i,max:r.left+(1-u)*s/i},y:{min:r.top-a*o/i,max:r.top+(1-a)*o/i}};e.each(t.getAxes(),function(e,t){var n=t.options,r=f[t.direction].min,i=f[t.direction].max,s=n.zoomRange,o=n.panRange;if(s===false)return;r=t.c2p(r);i=t.c2p(i);if(r>i){var u=r;r=i;i=u}if(o){if(o[0]!=null&&r<o[0]){r=o[0]}if(o[1]!=null&&i>o[1]){i=o[1]}}var a=i-r;if(s&&(s[0]!=null&&a<s[0]||s[1]!=null&&a>s[1]))return;n.min=r;n.max=i});t.setupGrid();t.draw();if(!n.preventEvent)t.getPlaceholder().trigger("plotzoom",[t,n])};t.pan=function(n){var r={x:+n.left,y:+n.top};if(isNaN(r.x))r.x=0;if(isNaN(r.y))r.y=0;e.each(t.getAxes(),function(e,t){var n=t.options,i,s,o=r[t.direction];i=t.c2p(t.p2c(t.min)+o),s=t.c2p(t.p2c(t.max)+o);var u=n.panRange;if(u===false)return;if(u){if(u[0]!=null&&u[0]>i){o=u[0]-i;i+=o;s+=o}if(u[1]!=null&&u[1]<s){o=u[1]-s;i+=o;s+=o}}n.min=i;n.max=s});t.setupGrid();t.draw();if(!n.preventEvent)t.getPlaceholder().trigger("plotpan",[t,n])};t.hooks.bindEvents.push(c);t.hooks.shutdown.push(h)}var t={xaxis:{zoomRange:null,panRange:null},zoom:{interactive:false,trigger:"dblclick",amount:1.5},pan:{interactive:false,cursor:"move",frameRate:20}};e.plot.plugins.push({init:n,options:t,name:"navigate",version:"1.3"})})(jQuery);