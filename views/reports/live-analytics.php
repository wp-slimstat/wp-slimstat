<?php
/**
 * Live Analytics Report View
 *
 * @package SlimStat
 * @since 5.4.0
 */

// don't load directly.
if ( ! defined( 'ABSPATH' ) ) {
	header( 'Status: 403 Forbidden' );
	header( 'HTTP/1.1 403 Forbidden' );
	exit;
}

$data = $args['data'] ?? [];
$auto_refresh = $args['auto_refresh'] ?? false;
$refresh_interval = $args['refresh_interval'] ?? 60000;
$selected_metric = $data['selected_metric'] ?? 'users';

// Extract data
$users_live = $data['users_live'] ?? 0;
$pages_live = $data['pages_live'] ?? 0;
$countries_live = $data['countries_live'] ?? 0;
$active_users_data = $data['active_users_per_minute'] ?? [];
$last_updated = $data['last_updated'] ?? time();

// Chart data
$chart_labels = $active_users_data['labels'] ?? [];
$chart_data = $active_users_data['data'] ?? [];
$peak_index = $active_users_data['peak_index'] ?? null;
$max_value = $active_users_data['max_value'] ?? 0;

// Format numbers
$users_formatted = number_format( $users_live );
$pages_formatted = number_format( $pages_live );
$countries_formatted = number_format( $countries_live );

// Generate unique IDs
$report_id = 'live_analytics_' . uniqid();
$chart_id = 'live_chart_' . uniqid();
?>

<div class="live-analytics-container live-analytics-light-theme" id="<?php echo esc_attr( $report_id ); ?>" data-refresh-interval="<?php echo esc_attr( $refresh_interval ); ?>">

	<!-- Main Content Grid -->
	<div class="live-analytics-grid">

		<!-- Left Panel: Metrics -->
		<div class="live-analytics-metrics">

			<!-- Users Live -->
			<div class="live-metric-item clickable-metric <?php echo $selected_metric === 'users' ? 'active' : ''; ?>" data-metric="users">
				<div class="metric-content">
					<div class="metric-label users-label">
						<div class="metric-icon users-icon">
                        <svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="9" cy="4.5" r="3" fill="#9BA1A6"/>
                            <ellipse cx="9" cy="12.75" rx="5.25" ry="3" fill="#9BA1A6"/>
                        </svg>

						</div>
						<?php esc_html_e( 'Users live', 'wp-slimstat' ); ?>
					</div>
					<div class="metric-value users-value"><?php echo esc_html( $users_formatted ); ?></div>
				</div>
			</div>

			<!-- Pages Live -->
			<div class="live-metric-item clickable-metric <?php echo $selected_metric === 'pages' ? 'active' : ''; ?>" data-metric="pages">
				<div class="metric-content">
					<div class="metric-label pages-label">
						<div class="metric-icon pages-icon">
                        <svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M1.3125 7.50026V10.5003C1.3125 12.6216 1.3125 13.6822 1.97151 14.3413C2.13451 14.5043 2.32209 14.6269 2.54421 14.7193C2.53917 14.6859 2.53441 14.6525 2.52991 14.619C2.43739 13.9309 2.43744 13.0716 2.4375 12.0743L2.4375 6.00022L2.4375 5.92616V5.92616C2.43744 4.92886 2.43739 4.06957 2.52991 3.38146C2.53441 3.34799 2.53916 3.31458 2.5442 3.28125C2.32208 3.37359 2.13451 3.49627 1.97151 3.65927C1.3125 4.31828 1.3125 5.37894 1.3125 7.50026Z" fill="#9BA1A6"/>
                            <path d="M16.3125 7.50026V10.5003C16.3125 12.6216 16.3125 13.6822 15.6535 14.3413C15.4905 14.5043 15.3029 14.6269 15.0808 14.7193C15.0858 14.6859 15.0906 14.6525 15.0951 14.619C15.1876 13.9309 15.1876 13.0716 15.1875 12.0743V5.92617C15.1876 4.92887 15.1876 4.06958 15.0951 3.38146C15.0906 3.34799 15.0858 3.31458 15.0808 3.28125C15.3029 3.37359 15.4905 3.49627 15.6535 3.65927C16.3125 4.31828 16.3125 5.37894 16.3125 7.50026Z" fill="#9BA1A6"/>
                            <path fill-rule="evenodd" clip-rule="evenodd" d="M4.22151 2.15901C3.5625 2.81802 3.5625 3.87868 3.5625 6V12C3.5625 14.1213 3.5625 15.182 4.22151 15.841C4.88052 16.5 5.94118 16.5 8.0625 16.5H9.5625C11.6838 16.5 12.7445 16.5 13.4035 15.841C14.0625 15.182 14.0625 14.1213 14.0625 12V6C14.0625 3.87868 14.0625 2.81802 13.4035 2.15901C12.7445 1.5 11.6838 1.5 9.5625 1.5H8.0625C5.94118 1.5 4.88052 1.5 4.22151 2.15901ZM6 12.75C6 12.4393 6.25184 12.1875 6.5625 12.1875H8.8125C9.12316 12.1875 9.375 12.4393 9.375 12.75C9.375 13.0607 9.12316 13.3125 8.8125 13.3125H6.5625C6.25184 13.3125 6 13.0607 6 12.75ZM6.5625 9.1875C6.25184 9.1875 6 9.43934 6 9.75C6 10.0607 6.25184 10.3125 6.5625 10.3125H11.0625C11.3732 10.3125 11.625 10.0607 11.625 9.75C11.625 9.43934 11.3732 9.1875 11.0625 9.1875H6.5625ZM6 6.75C6 6.43934 6.25184 6.1875 6.5625 6.1875H11.0625C11.3732 6.1875 11.625 6.43934 11.625 6.75C11.625 7.06066 11.3732 7.3125 11.0625 7.3125H6.5625C6.25184 7.3125 6 7.06066 6 6.75Z" fill="#9BA1A6"/>
                        </svg>

						</div>
						<?php esc_html_e( 'Pages live', 'wp-slimstat' ); ?>
					</div>
					<div class="metric-value pages-value"><?php echo esc_html( $pages_formatted ); ?></div>
				</div>
			</div>

			<!-- Countries Live -->
			<div class="live-metric-item clickable-metric <?php echo $selected_metric === 'countries' ? 'active' : ''; ?>" data-metric="countries">
				<div class="metric-content">
					<div class="metric-label countries-label">
						<div class="metric-icon countries-icon">
                        <svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M10.0773 13.707C10.8184 12.3105 13.2887 12.3105 13.2887 12.3105C15.8627 12.2836 16.2104 10.7208 16.4428 9.93091C16.0268 13.2919 13.3871 15.9637 10.0414 16.4284C9.79943 15.9192 9.51281 14.7709 10.0773 13.707Z" fill="#9BA1A6"/>
                            <path d="M3.75452 4.37542L3.44579 4.11138C3.42008 4.08939 3.3955 4.06637 3.37208 4.04239C2.20689 5.3641 1.5 7.0995 1.5 9C1.5 13.0956 4.7828 16.4244 8.86063 16.4987C8.59559 15.7076 8.42269 14.4255 9.08367 13.1799C9.69126 12.0349 10.9137 11.5842 11.6924 11.3927C12.1175 11.2883 12.5084 11.2373 12.7906 11.212C12.9331 11.1992 13.0514 11.1926 13.1367 11.1893C13.1795 11.1876 13.2142 11.1867 13.2398 11.1862L13.2714 11.1858L13.2798 11.1857C14.3154 11.1743 14.7173 10.8687 14.9028 10.6599C15.1322 10.4016 15.2221 10.0953 15.3474 9.66878L15.3636 9.61349C15.5122 9.10852 15.9867 8.78195 16.4976 8.80729C16.4505 6.94014 15.721 5.24253 14.5492 3.9545C14.5255 4.08777 14.4972 4.21168 14.4691 4.32137C14.3419 4.81755 14.1283 5.35287 13.8663 5.75054C13.6099 6.13972 13.1544 6.48746 12.8516 6.70502C12.6231 6.8692 12.3896 7.00559 12.1971 7.1165L12.128 7.15626C11.9543 7.25623 11.8161 7.33585 11.6846 7.42274C11.4176 7.59914 11.2574 7.75581 11.1489 7.96809C11.2149 8.2098 11.2618 8.48757 11.2626 8.77808C11.2643 9.46919 10.9106 10.0153 10.4877 10.3561C10.0717 10.6913 9.53339 10.8809 8.98798 10.8749C6.77562 10.8508 5.47798 9.046 5.31088 7.1862C5.2623 6.64545 5.01903 6.0628 4.6791 5.51991C4.3485 4.99193 3.97481 4.57855 3.75452 4.37542Z" fill="#9BA1A6"/>
                            <path d="M6.43137 7.08553C6.29093 5.52237 5.08545 4.06579 4.50027 3.53289L4.177 3.25642C5.48094 2.16027 7.16349 1.5 9.00027 1.5C10.6603 1.5 12.1944 2.03933 13.4368 2.95232C13.6124 3.48521 13.278 4.59868 12.9269 5.13158C12.7997 5.3246 12.5113 5.56424 12.1952 5.79139C11.4823 6.30357 10.5827 6.55691 10.1253 7.5C9.99451 7.76959 10.0001 8.03312 10.063 8.26219C10.1082 8.42688 10.1371 8.6059 10.1376 8.78098C10.139 9.34717 9.56643 9.75618 9.00027 9.75C7.52705 9.73392 6.56265 8.54665 6.43137 7.08553Z" fill="#9BA1A6"/>
                        </svg>

						</div>
						<?php esc_html_e( 'Countries live', 'wp-slimstat' ); ?>
					</div>
					<div class="metric-value countries-value"><?php echo esc_html( $countries_formatted ); ?></div>
				</div>
			</div>

		</div>

		<!-- Right Panel: Chart -->
		<div class="live-analytics-chart">
			<div class="chart-header">
				<div class="chart-title-wrapper">
					<span class="live-indicator">
						<span class="live-dot"></span>
						<span class="live-text"><?php esc_html_e( 'LIVE', 'wp-slimstat' ); ?></span>
					</span>
					<h4><?php esc_html_e( 'Online users per minute', 'wp-slimstat' ); ?></h4>
				</div>
			</div>
			<div class="chart-container">
				<canvas id="<?php echo esc_attr( $chart_id ); ?>"></canvas>
				<div class="empty-state">
					<p><?php esc_html_e( "We're not seeing activity in the last 30 minutes yet.", 'wp-slimstat' ); ?></p>
				</div>
			</div>
		</div>

	</div>


</div>

<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function() {
	// Initialize Live Analytics
	var config = {
		report_id: '<?php echo esc_js( $report_id ); ?>',
		chart_id: '<?php echo esc_js( $chart_id ); ?>',
		chart_labels: <?php echo json_encode( $chart_labels ); ?>,
		chart_data: <?php echo json_encode( $chart_data ); ?>,
		peak_index: <?php echo json_encode( $peak_index ); ?>,
		max_value: <?php echo json_encode( $max_value ); ?>,
		auto_refresh: <?php echo $auto_refresh ? 'true' : 'false'; ?>,
		refresh_interval: <?php echo intval( $refresh_interval ); ?>,
		ajax_url: '<?php echo admin_url( 'admin-ajax.php' ); ?>',
		nonce: '<?php echo wp_create_nonce( 'slimstat_ajax_nonce' ); ?>',
		current_metric: '<?php echo esc_js( $selected_metric ); ?>'
	};

	var liveAnalytics = new LiveAnalytics(config);
	liveAnalytics.init();
});
</script>
