if (typeof SlimStatParams == 'undefined') SlimStatParams = {'filters_string': '', 'async_load': 'no', 'refresh_interval': 0};
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
			colors: ['#bbcc44', '#ccc', '#21759B', '#02c907'],
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
		previous_point = null;
		
		jQuery.plot(SlimStatAdmin._placeholder, SlimStatAdmin.data, SlimStatAdmin._chart_options);

		SlimStatAdmin.chart_color_weekends();
		
		SlimStatAdmin._placeholder.bind('plothover', function(event, pos, item){
			if (item){
				if (typeof item.series.label != 'undefined'){
					if (previous_point != item.dataIndex){
						previous_point = item.dataIndex;
						jQuery("#jquery-tooltip").remove();
						SlimStatAdmin.show_tooltip(item.pageX, item.pageY, item.series.label+': <b>'+SlimStatAdmin.ticks[item.datapoint[0]][1]+'</b> = '+SlimStatAdmin.chart_tick_format(item.datapoint[1]));
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
	
	load_ajax_data : function(box_id, data){
		jQuery.post(ajaxurl, data, function(response){
			jQuery(box_id + ' .inside').html(response);
			if (box_id.indexOf('_01') > 0) SlimStatAdmin.chart_init();
			SlimStatAdmin.expand_row_details(box_id);
			SlimStatAdmin.enable_inline_help(box_id);
		});
	},
	
	expand_row_details : function(box_id){
		jQuery(box_id+' .inside p:not(.header)').hover(
			function(){
				if (this.title.length){
					this.savetitle = this.title;
					jQuery(this).append('<b id="wp-element-details">'+this.title+'</b>');
					this.title = '';
				}
			},
			function(){
				if (jQuery('#wp-element-details').is('*')){
					this.title = this.savetitle;
					jQuery('#wp-element-details').remove();
				}
			}
		);
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
	
	enable_inline_help : function(box_id){
		if (box_id.length > 0) box_id += ' ';
		jQuery(box_id + '.inline-help').hover(
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
	
	attach_whois_modal: function(){
		jQuery('.whois,.whois16').click(function(event) {
			if(event.preventDefault){event.preventDefault();} else {event.returnValue = false;}
			jQuery('#modal-dialog').html('<h3>Geolocation Information</h3><iframe src="'+jQuery(this).attr('href')+'" width="100%" height="92%"></iframe>');
			jQuery('#modal-dialog').dialog('open');
		});
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
			window.clearTimeout(refresh_handle);
			document.location.href = '?page=wp-slimstat&fs='+SlimStatParams.filters_string;
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

	jQuery('div[id^=slim_]').each(function(){
		var box_id = '#'+jQuery(this).attr('id');
		if (typeof SlimStatParams.async_load != 'undefined' && SlimStatParams.async_load == 'yes'){
			var data = {action: 'slimstat_load_report', fs: SlimStatParams.filters_string, box_id: box_id, security: jQuery('#meta-box-order-nonce').val()}
			SlimStatAdmin.load_ajax_data(box_id, data);
		}
		else{
			SlimStatAdmin.expand_row_details(box_id);
		}
	});
	
	if ((typeof SlimStatParams.async_load == 'undefined' || SlimStatParams.async_load != 'yes') && jQuery('#chart-placeholder').length > 0){
		SlimStatAdmin.chart_init();
		SlimStatAdmin.enable_inline_help('');
	}
	
	// Refresh page every X seconds
	if (SlimStatParams.refresh_interval > 0){
		SlimStatAdmin._refresh_timer[0] = parseInt(SlimStatParams.refresh_interval/60);
		SlimStatAdmin._refresh_timer[1] = SlimStatParams.refresh_interval%60;
		refresh_handle = window.setTimeout("SlimStatAdmin.refresh_countdown();", 1000);
	}

	jQuery('input.hide-postbox-tog[id^=slim_]').bind('click.postboxes', function (){
		var box_id = '#'+jQuery(this).val();
		var data = {action: 'slimstat_load_report', fs: SlimStatParams.filters_string, box_id: box_id, security: jQuery('#meta-box-order-nonce').val()}
		jQuery(box_id+' .inside').html('<p class="loading"></p>');

		if (jQuery(this).prop("checked") && jQuery('#'+jQuery(this).val()).length){
			SlimStatAdmin.load_ajax_data(box_id, data);
		}
	});

	// Slimstat Dashboard CSS Tweaks
	jQuery('#dashboard-widgets-wrap div[id^=slim_]').addClass('slimstat');

	// Modal Window
	if (typeof jQuery('#modal-dialog').dialog == 'function'){
		/* WHOIS and the 'more' [+] button open a modal window */
		jQuery('#modal-dialog').dialog({
			modal : true,
			autoOpen : false,
			closeOnEscape : true,
			draggable : false,
			open: function(){
				jQuery('.ui-widget-overlay').bind('click',function(){
					jQuery('#modal-dialog').dialog('close');
				})
			},
			resizable : false,
			width : jQuery(window).width() * 0.9,
			height : jQuery(window).height() * 0.7
		});
		jQuery('.more-modal-window').click(function(event) {
			if(event.preventDefault){event.preventDefault();} else {event.returnValue = false;}
			jQuery('#modal-dialog').html('<h3>'+jQuery(this).attr('title')+'</h3>blah');
			jQuery('#modal-dialog').dialog('open');
		});
		SlimStatAdmin.attach_whois_modal();
	}
});
