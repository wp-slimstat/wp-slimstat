<?php
/**
 * Usage Examples for SlimStat Reports System
 *
 * @package SlimStat
 * @since 5.4.0
 */

// ═══════════════════════════════════════════════════════════════
// Example 1: Initialize the Report System
// ═══════════════════════════════════════════════════════════════

// در فایل wp-slimstat.php یا هر جای دیگه که می‌خواید:
add_action( 'plugins_loaded', function() {
	\SlimStat\Reports\Bootstrap::get_instance()->init();
}, 5 );

// ═══════════════════════════════════════════════════════════════
// Example 2: Create a Custom Chart Report
// ═══════════════════════════════════════════════════════════════

namespace MyPlugin\Reports;

use SlimStat\Reports\Abstracts\ChartReport;

class CustomChartReport extends ChartReport {
	protected function init(): void {
		parent::init();

		$this->id        = 'custom_chart_01';
		$this->title     = __( 'Custom Chart', 'wp-slimstat' );
		$this->locations = [ 'slimview2', 'dashboard' ];
		$this->classes   = [ 'extralarge', 'chart' ];
		$this->tooltip   = $this->build_chart_tooltip(
			__( 'Custom Chart', 'wp-slimstat' ),
			__( 'This is a custom chart report.', 'wp-slimstat' ),
			[
				__( 'First bullet point', 'wp-slimstat' ),
				__( 'Second bullet point', 'wp-slimstat' ),
			]
		);
	}

	public function get_chart_data(): array {
		return [
			'data1' => 'COUNT( resource )',
			'data2' => 'COUNT( DISTINCT resource )',
		];
	}

	public function get_chart_labels(): array {
		return [
			__( 'Total Pages', 'wp-slimstat' ),
			__( 'Unique Pages', 'wp-slimstat' ),
		];
	}
}

// ═══════════════════════════════════════════════════════════════
// Example 3: Create a Custom Table Report
// ═══════════════════════════════════════════════════════════════

namespace MyPlugin\Reports;

use SlimStat\Reports\Abstracts\TableReport;

class CustomTableReport extends TableReport {
	protected function init(): void {
		$this->id        = 'custom_table_01';
		$this->title     = __( 'Top Custom Posts', 'wp-slimstat' );
		$this->locations = [ 'slimview4' ];
		$this->classes   = [ 'normal' ];

		// Query configuration
		$this->type    = 'top';
		$this->columns = 'resource';
		$this->where   = 'content_type = "my_custom_type"';
		$this->raw     = [ 'wp_slimstat_db', 'get_top' ];
	}
}

// ═══════════════════════════════════════════════════════════════
// Example 4: Register Custom Reports
// ═══════════════════════════════════════════════════════════════

// Method 1: Via WordPress Filter
add_action( 'slimstat_register_custom_reports', function( $reports ) {
	$reports[] = \MyPlugin\Reports\CustomChartReport::class;
	$reports[] = \MyPlugin\Reports\CustomTableReport::class;
	return $reports;
} );

// Method 2: Manual Registration
add_action( 'init', function() {
	$bootstrap = \SlimStat\Reports\Bootstrap::get_instance();
	$factory   = $bootstrap->get_factory();
	$registry  = $bootstrap->get_registry();

	// Create and register
	$report = $factory->create( \MyPlugin\Reports\CustomChartReport::class );
	$registry->register( $report );
} );

// ═══════════════════════════════════════════════════════════════
// Example 5: Access Reports from Registry
// ═══════════════════════════════════════════════════════════════

add_action( 'admin_init', function() {
	$bootstrap = \SlimStat\Reports\Bootstrap::get_instance();
	$registry  = $bootstrap->get_registry();

	// Get a specific report
	$pageviews_report = $registry->get( 'slim_p1_01' );
	if ( $pageviews_report ) {
		echo $pageviews_report->get_title();
	}

	// Get all reports
	$all_reports = $registry->get_all();
	foreach ( $all_reports as $id => $report ) {
		echo $report->get_id() . ': ' . $report->get_title() . '<br>';
	}

	// Get reports by location
	$dashboard_reports = $registry->get_by_location( 'dashboard' );
	foreach ( $dashboard_reports as $report ) {
		if ( $report->can_view() ) {
			$report->render();
		}
	}
} );

// ═══════════════════════════════════════════════════════════════
// Example 6: Use Dependency Injection
// ═══════════════════════════════════════════════════════════════

namespace MyPlugin\Services;

class MyCustomService {
	public function getData() {
		return [ 'custom' => 'data' ];
	}
}

namespace MyPlugin\Reports;

use SlimStat\Reports\Abstracts\AbstractReport;
use MyPlugin\Services\MyCustomService;

class ReportWithDI extends AbstractReport {
	private MyCustomService $service;

	public function __construct( MyCustomService $service ) {
		$this->service = $service;
		parent::__construct();
	}

	protected function init(): void {
		$this->id        = 'di_report_01';
		$this->title     = __( 'Report with DI', 'wp-slimstat' );
		$this->locations = [ 'slimview1' ];
	}

	public function get_data(): array {
		return $this->service->getData();
	}

	public function get_renderer(): string {
		return 'custom';
	}

	public function render_content(): void {
		$data = $this->get_data();
		echo '<pre>' . print_r( $data, true ) . '</pre>';
	}
}

// Register the service in DI container
add_filter( 'slimstat_reports_di_container', function( $container ) {
	$container[ MyPlugin\Services\MyCustomService::class ] = function() {
		return new MyPlugin\Services\MyCustomService();
	};
	return $container;
} );

// ═══════════════════════════════════════════════════════════════
// Example 7: Extend Existing Report
// ═══════════════════════════════════════════════════════════════

namespace MyPlugin\Reports;

use SlimStat\Reports\Types\Analytics\PageviewsChartReport;

class ExtendedPageviewsReport extends PageviewsChartReport {
	protected function init(): void {
		parent::init();

		// Override properties
		$this->id        = 'extended_pageviews';
		$this->title     = __( 'Extended Pageviews', 'wp-slimstat' );
		$this->locations = [ 'slimview2' ]; // Different location
	}

	public function get_chart_data(): array {
		// Add custom data source
		return [
			'data1' => 'COUNT( ip )',
			'data2' => 'COUNT( DISTINCT ip )',
			'data3' => 'COUNT( DISTINCT visit_id )', // Extra line
		];
	}
}

// ═══════════════════════════════════════════════════════════════
// Example 8: Get Legacy Array Format
// ═══════════════════════════════════════════════════════════════

add_action( 'admin_init', function() {
	$bootstrap = \SlimStat\Reports\Bootstrap::get_instance();
	$registry  = $bootstrap->get_registry();

	// Get in old format for backward compatibility
	$legacy_reports = $registry->to_legacy_array();

	// This is compatible with old code:
	// wp_slimstat_reports::$reports
	foreach ( $legacy_reports as $id => $config ) {
		echo $config['title'];
	}
} );

// ═══════════════════════════════════════════════════════════════
// Example 9: Factory with Caching
// ═══════════════════════════════════════════════════════════════

add_action( 'init', function() {
	$bootstrap = \SlimStat\Reports\Bootstrap::get_instance();
	$factory   = $bootstrap->get_factory();

	// Create with caching (singleton-like behavior)
	$report1 = $factory->create( \SlimStat\Reports\Types\Analytics\PageviewsChartReport::class, [], true );
	$report2 = $factory->create( \SlimStat\Reports\Types\Analytics\PageviewsChartReport::class, [], true );

	// $report1 === $report2 (same instance)

	// Create multiple reports at once
	$reports = $factory->create_many( [
		\SlimStat\Reports\Types\Analytics\PageviewsChartReport::class,
		\SlimStat\Reports\Types\Analytics\TopWebPagesReport::class,
	], true );
} );

// ═══════════════════════════════════════════════════════════════
// Example 10: Programmatically Render a Report
// ═══════════════════════════════════════════════════════════════

add_shortcode( 'slimstat_report', function( $atts ) {
	$atts = shortcode_atts( [
		'id' => 'slim_p1_01',
	], $atts );

	$bootstrap = \SlimStat\Reports\Bootstrap::get_instance();
	$registry  = $bootstrap->get_registry();

	$report = $registry->get( $atts['id'] );

	if ( ! $report || ! $report->can_view() ) {
		return '<p>Report not found or no permission.</p>';
	}

	ob_start();
	$report->render();
	return ob_get_clean();
} );

// Usage: [slimstat_report id="slim_p1_01"]
