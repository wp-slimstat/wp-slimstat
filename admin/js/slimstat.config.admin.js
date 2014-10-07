jQuery(function(){
	jQuery('#use_separate_menu_yes').click(function(){
		jQuery('#form-slimstat-options-tab-1').attr('action', 'admin.php?page=wp-slim-config&tab=1');
	});
	jQuery('#use_separate_menu_no').click(function(){
		jQuery('#form-slimstat-options-tab-1').attr('action', 'admin.php?page=wp-slim-config&tab=1');
	});
});
