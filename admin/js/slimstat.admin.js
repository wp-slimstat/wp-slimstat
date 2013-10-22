if (typeof SlimStatAdminParams == 'undefined') SlimStatAdminParams = {'filters': '', 'current_tab': 1, 'async_load': 'no', 'refresh_interval': 0, 'expand_details':'yes', 'datepicker_image':''};
var SlimStatAdmin = {
	data: [],
	ticks: [],
	options: [],
	_placeholder: null,
	_chart_options: [],
	_refresh_timer: [0, 0],

	chart_init: function() {
		SlimStatAdmin._placeholder = jQuery("#chart-placeholder");
		if (SlimStatAdmin._placeholder.is(':hidden')) return true;
		SlimStatAdmin._chart_options = {
			zoom: { interactive: true },
			pan: { interactive: true },
			series: {
				lines: { show: true },
				colors: [ { opacity: 0.85 } ],
				shadowSize: 5
			},
			colors: ['#bbcc44', '#ccc', '#999', '#21759B', '#02c907'],
			shadowSize: 0,
			xaxis: {
				tickSize: 1,
				tickDecimals: 0,
				ticks: (SlimStatAdmin.options.interval > 0 && SlimStatAdmin.ticks.length > 20) ? [] : SlimStatAdmin.ticks,
				zoomRange: [5, SlimStatAdmin.ticks.length],
				panRange: [0, SlimStatAdmin.ticks.length]
			},
			yaxis: {
				tickDecimals: 0,
				max: SlimStatAdmin.options.max_yaxis+1,
				zoomRange: [5, SlimStatAdmin.options.max_yaxis+1],
				panRange:[0, SlimStatAdmin.options.max_yaxis+1]
			},
			grid: {
				backgroundColor: '#ffffff',
				borderWidth: 0,
				hoverable: true,
				clickable: true
			},
			legend: {
				container: '#chart-legend',
				noColumns: 4
			}
		};
		var previous_point = null;

		jQuery.plot(SlimStatAdmin._placeholder, SlimStatAdmin.data, SlimStatAdmin._chart_options);
		SlimStatAdmin.chart_color_weekends();

		SlimStatAdmin._placeholder.bind('plothover', function(event, pos, item){
			if (item){
				if (typeof item.series.label != 'undefined'){
					if (previous_point != item.dataIndex){
						previous_point = item.dataIndex;
						jQuery("#jquery-tooltip").remove();
						SlimStatAdmin.show_tooltip(item.pageX, item.pageY, SlimStatAdmin.chart_tick_format(item.datapoint[1]));
					}
				}
				else{
					SlimStatAdmin.show_tooltip(item.pageX, item.pageY, SlimStatAdmin.data[0].data[item.dataIndex][2]);
				}
			}
			else{
				if (jQuery('#jquery-tooltip').length) jQuery('#jquery-tooltip').remove();
				previous_point = null;            
			}
		});

		SlimStatAdmin._placeholder.bind('plotclick', function(event, pos, item){
			if (item && typeof item.series.label != 'undefined'){
				if (item.seriesIndex == 1 && typeof SlimStatAdmin.data[item.seriesIndex].data[item.datapoint[0]-SlimStatAdmin.options.rtl_filler_previous][2] != 'undefined'){
					document.location.href = SlimStatAdmin.data[item.seriesIndex].data[item.datapoint[0]-SlimStatAdmin.options.rtl_filler_previous][2].replace(/&amp;/gi,'&');
				}
				if (item.seriesIndex != 1 && typeof SlimStatAdmin.data[item.seriesIndex].data[item.datapoint[0]-SlimStatAdmin.options.rtl_filler_current][2] != 'undefined'){
					document.location.href = SlimStatAdmin.data[item.seriesIndex].data[item.datapoint[0]-SlimStatAdmin.options.rtl_filler_current][2].replace(/&amp;/gi,'&');
				}
			}
		});

		SlimStatAdmin._placeholder.bind('dblclick', function(event){
			jQuery.plot(SlimStatAdmin._placeholder, SlimStatAdmin.data, SlimStatAdmin._chart_options);
			SlimStatAdmin.chart_color_weekends();
		});

		SlimStatAdmin._placeholder.bind('plotzoom', SlimStatAdmin.chart_color_weekends);

		SlimStatAdmin._placeholder.bind('plotpan', SlimStatAdmin.chart_color_weekends);
	},
	
	load_ajax_data : function(report_id, data){
		data['current_tab'] = SlimStatAdminParams.current_tab;
		jQuery.post(ajaxurl+'?slimstat=1'+SlimStatAdminParams.filters, data, function(response){
			jQuery(report_id + ' .inside').html(response);
			if (report_id.indexOf('_01') > 0) SlimStatAdmin.chart_init();
			SlimStatAdmin.expand_row_details(report_id);
			SlimStatAdmin.enable_inline_help(report_id);
			jQuery(report_id + ' .whois,' + report_id + ' .whois16').bind("click", function(event) {
				SlimStatAdmin.attach_whois_modal(jQuery(this), event);
			});
		});
	},
	
	expand_row_details : function(report_id){
		if (SlimStatAdminParams.expand_details == 'yes'){
			jQuery(report_id+' .inside p:not(.header)').each(function(){
				if (this.title.length){
					this.savetitle = this.title;
					jQuery(this).append('<b id="wp-element-details">'+this.title+'</b>');
					this.title = '';
				}
			});
		}
		else{
			jQuery(report_id+' .inside p:not(.header)').hover(
				function(){
					if (this.title.length){
						this.savetitle = this.title;
						jQuery(this).append('<b id="wp-element-details">'+this.title+'</b>');
						this.title = '';
						jQuery('#wp-element-details .whois').bind("click", function(event) {
							SlimStatAdmin.attach_whois_modal(jQuery(this), event);
						});
					}
				},
				function(){
					if (jQuery('#wp-element-details').is('*')){
						this.title = this.savetitle;
						jQuery('#wp-element-details').remove();
					}
				}
			);
		}
	},

	show_tooltip : function(x, y, content, class_label, minus){
		var class_attribute = class_label ? ' class="'+class_label+'"':'';
		if (jQuery('#jquery-tooltip').length) jQuery('#jquery-tooltip').remove();
		jQuery('<div id="jquery-tooltip"' + class_attribute + '>' + content + '</div>').appendTo("body");
		jQuery("#jquery-tooltip").css({
			top:y+10,
			left:minus?x-jQuery("#jquery-tooltip").width()-10:x+10,
		}).fadeIn(200);
	},
	
	enable_inline_help : function(report_id){
		if (report_id.length > 0) report_id += ' ';
		jQuery(report_id + '.inline-help, ' + report_id + '.img-inline-help').hover(
				function(event){
					this.savetitle = this.title;
					SlimStatAdmin.show_tooltip(event.pageX, event.pageY, this.title, 'tooltip-fixed-width');
					this.title = '';
				},
				function(){
					this.title = this.savetitle;
					jQuery('#jquery-tooltip').remove();
				}
		);
	},

	chart_tick_format : function(n){
		n += '';
		x = n.split('.');
		x1 = x[0];
		x2 = x.length > 1 ? decimalPoint + x[1] : '';
		var rgx = /(\d+)(\d{3})/;
		while (rgx.test(x1)) {
			x1 = x1.replace(rgx, '$1' + thousandsSeparator + '$2');
		}
		return x1 + x2;
	},
	
	chart_color_weekends: function(){
		if (SlimStatAdmin.options.daily_chart) jQuery(".xAxis .tickLabel").each(function(i){
			myDate = new Date(SlimStatAdmin.options.current_year, SlimStatAdmin.options.current_month-1, parseInt(jQuery(this).html()), 3, 30, 0);
			if(myDate.getDay()%6 == 0) jQuery(this).css('color','#ccc');
		});
	},
	
	attach_whois_modal: function(element, event){
		event.preventDefault();

		jQuery('#modal-dialog').html('<h3>Geolocation Information</h3><a class="close-dialog" href="#"></a><iframe id="ip2location" src="'+element.attr('href')+'" width="100%" height="92%"></iframe>');
		//jQuery('#modal-dialog').html('<h3>Visitor Information</h3><a class="close-dialog" href="#"></a><iframe id="ip2location" src="http://multisite.dev/ip2location.html" width="49%" height="90%"></iframe>');
		//jQuery('<div id="visitor-details"><p class="loading"></p></div>').insertAfter('#ip2location');
		//var data = {action: 'slimstat_visitor_information', ip_url: element.attr('href'), security: jQuery('#meta-box-order-nonce').val()}
		//jQuery.post(ajaxurl+'?slimstat=1'+SlimStatAdminParams.filters, data, function(response){
		//	jQuery('#visitor-details').html(response);
		//});
		jQuery('#modal-dialog').dialog('open');
		SlimStatAdmin.set_overlay_dimensions();
	},

	set_overlay_dimensions: function(){
		jQuery('.ui-widget-overlay').width(jQuery(window).width());
		jQuery('.ui-widget-overlay').height(jQuery(document).height());
		jQuery('.ui-widget-content').width(jQuery(window).width() * 0.9);
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
			report_id = '#slim_p7_02';
			data = {action: 'slimstat_load_report', report_id: report_id, security: jQuery('#meta-box-order-nonce').val()};
			jQuery(report_id+' .inside').html('<p class="loading"></p>');
			SlimStatAdmin.load_ajax_data(report_id, data);

			window.clearTimeout(refresh_handle);
			SlimStatAdmin._refresh_timer[0] = parseInt(SlimStatAdminParams.refresh_interval/60);
			SlimStatAdmin._refresh_timer[1] = SlimStatAdminParams.refresh_interval%60;
			refresh_handle = window.setTimeout("SlimStatAdmin.refresh_countdown();", 1000);
		}
	}
}

jQuery(function(){
	jQuery('.box-help').hover(
		function(event){
			element_class = (this.id.length)?'tooltip-'+this.id:'tooltip-fixed-width';
			this.savetitle = this.title;
			SlimStatAdmin.show_tooltip(event.pageX, event.pageY, this.title, element_class, 'yes');
			this.title = '';
		},
		function(){
			this.title = this.savetitle;
			jQuery('#jquery-tooltip').remove();
		}
	);

	jQuery('.box-refresh').click(
		function(){
			report_id = '#'+jQuery(this).parent().attr('id');
			data = {action: 'slimstat_load_report', report_id: report_id, security: jQuery('#meta-box-order-nonce').val()}
			jQuery(report_id+' .inside').html('<p class="loading"></p>');
			SlimStatAdmin.load_ajax_data(report_id, data);
			
			if (typeof refresh_handle != 'undefined'){
				window.clearTimeout(refresh_handle);
				SlimStatAdmin._refresh_timer[0] = parseInt(SlimStatAdminParams.refresh_interval/60);
				SlimStatAdmin._refresh_timer[1] = SlimStatAdminParams.refresh_interval%60;
				refresh_handle = window.setTimeout("SlimStatAdmin.refresh_countdown();", 1000);
			}
		}
	);

	jQuery('div[id^=slim_]').each(function(){
		report_id = '#'+jQuery(this).attr('id');
		if (typeof SlimStatAdminParams.async_load != 'undefined' && SlimStatAdminParams.async_load == 'yes'){
			data = {action: 'slimstat_load_report', report_id: report_id, security: jQuery('#meta-box-order-nonce').val()}
			SlimStatAdmin.load_ajax_data(report_id, data);
		}
		else{
			SlimStatAdmin.expand_row_details(report_id);
		}
	});
	
	if (jQuery('#chart-placeholder').length > 0){
		SlimStatAdmin.chart_init();
	}
	if (typeof SlimStatAdminParams.async_load == 'undefined' || SlimStatAdminParams.async_load != 'yes'){
		SlimStatAdmin.enable_inline_help('');
	}
	
	// Remove click on report title
	jQuery('h3.hndle').on('click', function(){ jQuery(this).parent().toggleClass('closed') });
	
	// Refresh page every X seconds
	if (SlimStatAdminParams.refresh_interval > 0){
		SlimStatAdmin._refresh_timer[0] = parseInt(SlimStatAdminParams.refresh_interval/60);
		SlimStatAdmin._refresh_timer[1] = SlimStatAdminParams.refresh_interval%60;
		refresh_handle = window.setTimeout("SlimStatAdmin.refresh_countdown();", 1000);
	}

	jQuery('input.hide-postbox-tog[id^=slim_]').bind('click.postboxes', function (){
		var report_id = '#'+jQuery(this).val();
		var data = {action: 'slimstat_load_report', report_id: report_id, security: jQuery('#meta-box-order-nonce').val()}
		jQuery(report_id+' .inside').html('<p class="loading"></p>');

		if (jQuery(this).prop("checked") && jQuery('#'+jQuery(this).val()).length){
			SlimStatAdmin.load_ajax_data(report_id, data);
		}
	});
	
	// Send new filters as post requests
	jQuery(document).on('click', '.slimstat-filter-link', function(e){
		e.preventDefault();
		filters_to_add = jQuery(this).attr('href').split('&');

		jQuery('#slimstat-filters').attr('action', filters_to_add[0]);
		for (i in filters_to_add){
			if (filters_to_add[i].indexOf('fs\%5B') != 0) continue;
			
			filter_components = filters_to_add[i].split('=');

			filter_components[0] = decodeURIComponent(filter_components[0]);
			jQuery('input[name="'+filter_components[0]+'"]').remove();
			
			if (filter_components[0].indexOf('[day]') > 0) jQuery('#slimstat_filter_day').val(0);
			if (filter_components[0].indexOf('[month]') > 0) jQuery('#slimstat_filter_month').val(0);
			if (filter_components[0].indexOf('[year]') > 0) jQuery('#slimstat_filter_year').val('');
				
			jQuery('<input>').attr('type', 'hidden').attr('name', filter_components[0]).val(filter_components[1].replace('+', ' ')).appendTo('#slimstat-filters');
			
		}
		jQuery('#slimstat-filters').submit();
		return false;
	});
	jQuery('a.remove-filter').click(function(e){
		filter_to_remove = decodeURIComponent(jQuery(this).attr('href')).split('&');
		jQuery('#slimstat-filters').attr('action', filter_to_remove[0]);
		if (filter_to_remove[1].length == 0) return true;
		
		e.preventDefault();
		filter_components = filter_to_remove[1].split('=');
		jQuery('input[name="'+filter_components[0].replace('[', '\\[').replace(']', '\\]')+'"]').remove();
		
		// Reset dropdowns, if needed
		if (filter_components[0].indexOf('[day]') > 0) jQuery('#slimstat_filter_day').val(0);
		if (filter_components[0].indexOf('[month]') > 0) jQuery('#slimstat_filter_month').val(0);
		if (filter_components[0].indexOf('[year]') > 0) jQuery('#slimstat_filter_year').val('');
		jQuery('#slimstat-filters').submit();
		return false;
	});
	
	// Datepicker
	if (typeof jQuery('.slimstat-filter-date').datepicker == 'function'){
		jQuery('.slimstat-filter-date').datepicker({
			buttonImage: SlimStatAdminParams.datepicker_image,
			buttonImageOnly: true,
			changeMonth: true,
			changeYear: true,
			dateFormat: 'yy-m-d',
			nextText: '&raquo;',
			prevText: '&laquo;',
			showOn: 'both',
			
			onClose: function(dateText, inst) {
				if (!dateText.length) return true;
				jQuery('#slimstat_filter_day').val( dateText.split('-')[2] );
				jQuery('#slimstat_filter_month').val( dateText.split('-')[1] );
				jQuery('#slimstat_filter_year').val( dateText.split('-')[0] );
				if (!jQuery('#slimstat_interval_block').is(':visible')) {
					jQuery('#slimstat_interval_block').fadeIn();
				}
			}
		});
	}

	// Slimstat Dashboard CSS Tweaks
	jQuery('#dashboard-widgets-wrap div[id^=slim_]').addClass('slimstat');

	// Modal Window
	if (typeof jQuery('#modal-dialog').dialog == 'function'){
		/* WHOIS and the 'more' [+] button open a modal window */
		jQuery('#modal-dialog').dialog({
			autoOpen : false,
			closeOnEscape : true,
			draggable : true,
			height : 415,
			modal : true,
			open: function(){
				jQuery('.ui-widget-overlay').bind('click',function(){
					jQuery('#modal-dialog').dialog('close');
				});
				jQuery('.close-dialog').bind('click',function(){
					jQuery('#modal-dialog').dialog('close');
				})
			},
			position : { my: "top center" },
			resizable : false,
			width : jQuery(window).width() * 0.9
		});
		jQuery('.more-modal-window').click(function(event) {
			if(event.preventDefault){event.preventDefault();} else {event.returnValue = false;}
			jQuery('#modal-dialog').html('<h3>'+jQuery(this).attr('title')+'</h3>blah');
			jQuery('#modal-dialog').dialog('open');
		});
		jQuery('.whois,.whois16').bind("click", function(event) {
			SlimStatAdmin.attach_whois_modal(jQuery(this), event);
		});
	}
	
	jQuery(window).resize(function(){
        SlimStatAdmin.set_overlay_dimensions();
    });
});
