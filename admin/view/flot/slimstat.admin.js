function showTooltip(x, y, content, class_label, minus){
	var class_attribute = class_label ? ' class="'+class_label+'"':'';
	jQuery('<div id="jquery-tooltip"' + class_attribute + '>' + content + '</div>').appendTo("body");
	jQuery("#jquery-tooltip").css({
		top:y+10,
		left:minus?x-jQuery("#jquery-tooltip").width()-10:x+10,
	}).fadeIn(200);
}
function tickFormatter(n){
	n += '';
	x = n.split('.');
	x1 = x[0];
	x2 = x.length > 1 ? decimalPoint + x[1] : '';
	var rgx = /(\d+)(\d{3})/;
	while (rgx.test(x1)) {
		x1 = x1.replace(rgx, '$1' + thousandsSeparator + '$2');
	}
	return x1 + x2;
}
function color_weekends(){
	if (!slimstat_day_filter_active) jQuery(".xAxis .tickLabel").each(function(i){
		myDate = new Date(slimstat_current_year, slimstat_current_month-1, parseInt(jQuery(this).html()), 3, 30, 0);
		if(myDate.getDay()%6 == 0) jQuery(this).css('color','#ccc');
	});
}
var previousPoint = null;

jQuery(document).ready(function(){
	jQuery('.box-help').hover(
			function(event){
				element_class = (this.id.length > 0)?'tooltip-'+this.id:'tooltip-fixed-width';
				this.savetitle = this.title;
				showTooltip(event.pageX, event.pageY, this.title, element_class, 'yes');
				this.title = '';
			},
			function(){
				this.title = this.savetitle;
				jQuery('#jquery-tooltip').remove();
			}
	);

	jQuery('.inline-help').hover(
			function(event){
				this.savetitle = this.title;
				showTooltip(event.pageX, event.pageY, this.title, 'tooltip-fixed-width');
				this.title = '';
			},
			function(){
				this.title = this.savetitle;
				jQuery('#jquery-tooltip').remove();
			}
	);

	// Slimstat Dashboard CSS Tweaks
	jQuery('[id^=slim_]').addClass('slimstat');
	jQuery('#slim_p1_01').removeClass('slimstat');

	jQuery('.slimstat .inside p:not(.header)').hover(
		function(){
			if (this.title.length > 0){
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

	jQuery('input.hide-postbox-tog[id^=slim_]').bind('click.postboxes', function (){
		var box_id = '#'+jQuery(this).val();
		if (typeof slimstat_filters_string == 'undefined') slimstat_filters_string = '';
		jQuery(box_id+' .inside').html('<p class="nodata loading"></p>');
		var data = {action: 'slimstat_load_report', fs: slimstat_filters_string, box_id: box_id, security: jQuery('#meta-box-order-nonce').val()}
		if (jQuery(this).prop("checked") && jQuery('#'+jQuery(this).val()).length !== 0){
			jQuery.post(ajaxurl, data, function(response){
				jQuery(box_id+' .inside').html(response);
				jQuery(box_id+' .inside p:not(.header)').hover(
					function(){
						if (this.title.length > 0){
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
			});
		}
	});
	

	if (typeof jQuery('#more-dialog').dialog == 'function'){
		jQuery('#more-dialog').dialog({
			modal : true,
			autoOpen : false,
			closeOnEscape : true,
			draggable : false,
			open: function(){
				jQuery('.ui-widget-overlay').bind('click',function(){
					jQuery('#more-dialog').dialog('close');
				})
			},
			resizable : false,
			width : '760'
		});
		jQuery('.more').click(function(event) {
			event.preventDefault();
			jQuery('#more-dialog').dialog('open');
		});
	}	
});