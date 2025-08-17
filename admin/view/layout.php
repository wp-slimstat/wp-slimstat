<?php
// Keep track of multiple occurrences of the same report, to allow users to delete duplicates
$already_seen = [];
?>

<div class="backdrop-container ">
    <div class="wrap slimstat slimstat-layout">
        <h2><?php _e('Customize and organize your reports', 'wp-slimstat') ?></h2>
        <p><?php
            _e('You can drag and drop the placeholders here below from one widget area to another, to customize the layout of each report screen. You can place multiple charts on the same view, clone reports or move them to the Inactive Reports if you are not interested in that specific metric.', 'wp-slimstat');
if (is_network_admin()) {
    echo ' ';
    _e('By using the network-wide customizer, all your users will see the same layout you define, and they will not be able to customize it further.', 'wp-slimstat');
    echo ' ';
}
?></p>

        <form method="get" action=""><input type="hidden" id="meta-box-order-nonce" name="meta-box-order-nonce" value="<?php echo wp_create_nonce('meta-box-order') ?>"/></form>

        <form action="admin-post.php" method="post">
            <?php wp_nonce_field('reset_layout'); ?>
            <input type="hidden" name="action" value="slimstat_reset_layout">
            <input type="submit" value="<?php _e('Reset Layout', 'wp-slimstat') ?>" class="button"/>
        </form>

        <?php foreach (wp_slimstat_reports::$user_reports as $a_location_id => $a_location_list): ?>

            <div id="postbox-container-<?php echo esc_attr($a_location_id); ?>" class="postbox-container">
                <h2 class="slimstat-options-section-header"><?php echo wp_slimstat_admin::$screens_info[$a_location_id]['title'] ?></h2>
                <div id="<?php echo esc_attr($a_location_id); ?>-sortables" class="meta-box-sortables"><?php
        foreach ($a_location_list as $a_report_id) {
            if (empty(wp_slimstat_reports::$reports[$a_report_id])) {
                continue;
            }

            if (!in_array($a_report_id, $already_seen)) {
                $already_seen[] = $a_report_id;
                $icon           = 'docs';
                $title          = __('Clone', 'wp-slimstat');
            } else {
                $icon  = 'trash';
                $title = __('Delete', 'wp-slimstat');
            }

            $placeholder_classes = '';
            $h                   = wp_slimstat_reports::$reports[$a_report_id];
            if (is_array(wp_slimstat_reports::$reports[$a_report_id]['classes'])) {
                $placeholder_classes = ' ' . implode(' ', wp_slimstat_reports::$reports[$a_report_id]['classes']);
            }

            echo "
			<div class='postbox{$placeholder_classes}' id='" . esc_attr($a_report_id) . "'>
				<div class='slimstat-header-buttons'>
					<a class='slimstat-font-{$icon}' href='#' title='" . esc_attr($title) . "'></a>
					" . (('inactive' != $a_location_id) ? ' <a class="slimstat-font-minus-circled" href="#" title="' . __('Move to Inactive', 'wp-slimstat') . '"></a>' : '') . "
				</div>
				<h3 class='hndle'>" . wp_slimstat_reports::$reports[$a_report_id]['title'] . '</h3>
			</div>';
        } ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
