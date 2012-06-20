var placeholder = jQuery("#chart-placeholder");
var options = {
	zoom:{interactive:true},
	pan:{interactive:true},
	series:{
		lines:{show:true},
		points:{
			show:true,
			symbol:function(ctx, x, y, radius, shadow){ctx.arc(x, y, 2, 0, Math.PI * 2, false)} 
		},
		colors:[{opacity:0.85}],
		shadowSize:5
	},
	colors:['#ccc','#21759B','#02c907'],
	shadowSize:0,
	xaxis:{
		tickSize:1,
		tickDecimals:0,
		ticks:(slimstat_interval>0 && window.ticks.length>20)?[]:window.ticks,
		zoomRange:[5,window.ticks.length],
		panRange:[0,window.ticks.length]
	},
	yaxis:{
		tickDecimals:0,
		zoomRange:[5, slimstat_max_yaxis+parseInt(slimstat_max_yaxis/5)],
		panRange:[0, slimstat_max_yaxis+parseInt(slimstat_max_yaxis/5)]
	},
	grid:{
		backgroundColor:"#ffffff",
		borderWidth:0,
		hoverable:true,
		clickable:true
	},
	legend:{
		container:"#chart-legend",
		noColumns:3
	}
};
jQuery.plot(placeholder, [slimstat_a, slimstat_b, slimstat_c], options);
color_weekends();
placeholder.bind('plothover', function(event, pos, item){
	if (item){
		if (previousPoint != item.dataIndex){
			previousPoint = item.dataIndex;
			jQuery("#jquery-tooltip").remove();
			showTooltip(item.pageX, item.pageY, item.series.label+': <b>'+window.ticks[item.datapoint[0]][1]+'</b> = '+tickFormatter(item.datapoint[1]));
		}
	}
	else{
		jQuery('#jquery-tooltip').remove();
		previousPoint = null;            
	}
});
placeholder.bind('plotclick', function(event, pos, item){
	if (item){
		if (item.seriesIndex == 1 && typeof(window.chart_data[item.seriesIndex][item.datapoint[0]-slimstat_rtl_filler_previous][2]) != 'undefined'){
			document.location.href = window.location.pathname+'?page=wp-slimstat&fs='+window.chart_data[item.seriesIndex][item.datapoint[0]-slimstat_rtl_filler_previous][2];
		}
		if (item.seriesIndex != 1 && typeof(window.chart_data[item.seriesIndex][item.datapoint[0]-slimstat_rtl_filler_current][2]) != 'undefined'){
			document.location.href = window.location.pathname+'?page=wp-slimstat&fs='+window.chart_data[item.seriesIndex][item.datapoint[0]-slimstat_rtl_filler_current][2];
			
		}
	}
});
placeholder.bind('dblclick', function(event){
	jQuery.plot(placeholder, [slimstat_a, slimstat_b, slimstat_c], options);
	color_weekends();
});
placeholder.bind('plotzoom', color_weekends);
placeholder.bind('plotpan', color_weekends);