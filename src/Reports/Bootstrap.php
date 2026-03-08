<?php
/**
 * Reports System Bootstrap
 *
 * @package SlimStat
 * @since 5.4.0
 */

declare(strict_types=1);

namespace SlimStat\Reports;

use SlimStat\Reports\Registry\ReportRegistry;
use SlimStat\Reports\Registry\ReportFactory;
use SlimStat\Reports\Registry\ReportLoader;
use SlimStat\Reports\Registry\LegacyReportAdapter;
use SlimStat\Reports\Types\Analytics\LiveAnalyticsReport;

/**
 * Class Bootstrap
 *
 * Bootstraps the new report system and integrates with legacy code.
 */
class Bootstrap {
	/**
	 * Singleton instance
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Report registry
	 *
	 * @var ReportRegistry|null
	 */
	private ?ReportRegistry $registry = null;

	/**
	 * Report factory
	 *
	 * @var ReportFactory|null
	 */
	private ?ReportFactory $factory = null;

	/**
	 * Report loader
	 *
	 * @var ReportLoader|null
	 */
	private ?ReportLoader $loader = null;

	/**
	 * Legacy adapter
	 *
	 * @var LegacyReportAdapter|null
	 */
	private ?LegacyReportAdapter $adapter = null;

	/**
	 * Whether the system is initialized
	 *
	 * @var bool
	 */
	private bool $initialized = false;

	/**
	 * Private constructor to prevent direct instantiation
	 */
	private function __construct() {
	}

	/**
	 * Get the singleton instance
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize the report system
	 *
	 * @return void
	 */
	public function init(): void {
		if ( $this->initialized ) {
			return;
		}

		// Create core components
		$this->registry = ReportRegistry::get_instance();
		$this->factory  = new ReportFactory( $this->get_di_container() );
		$this->loader   = new ReportLoader( $this->registry, $this->factory );

		// Create and initialize adapter
		$this->adapter = new LegacyReportAdapter( $this->registry, $this->loader );
		$this->adapter->init();

		// Register hooks
		$this->register_hooks();

		// Register built-in reports
		$this->register_builtin_reports();

		$this->initialized = true;

		do_action( 'slimstat_reports_system_initialized' );
	}

	/**
	 * Register built-in reports
	 *
	 * Each report is self-contained and handles its own:
	 * - AJAX endpoints
	 * - Asset enqueuing (CSS/JS)
	 * - WordPress hooks
	 *
	 * To add a new report:
	 * 1. Create a new class extending AbstractReport
	 * 2. Add a static init_hooks() method to handle report-specific setup
	 * 3. Add the class to the $builtin_reports array below
	 *
	 * Example:
	 * ```php
	 * class MyNewReport extends AbstractReport {
	 *     public static function init_hooks(): void {
	 *         add_action( 'wp_ajax_my_report_data', [ __CLASS__, 'ajax_handler' ] );
	 *         add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
	 *     }
	 * }
	 * ```
	 *
	 * @return void
	 */
	private function register_builtin_reports(): void {
		// List of built-in report classes
		$builtin_reports = [
			LiveAnalyticsReport::class,
			// Add new reports here - each report is fully self-contained
		];

		foreach ( $builtin_reports as $report_class ) {
			// Create and register the report
			$report = $this->factory->create( $report_class );
			$this->registry->register( $report );

			// Initialize report-specific hooks and assets
			if ( method_exists( $report_class, 'init_hooks' ) ) {
				call_user_func( [ $report_class, 'init_hooks' ] );
			}
		}
	}

	/**
	 * Get dependency injection container
	 *
	 * @return array<string, mixed>
	 */
	private function get_di_container(): array {
		$container = [];

		// Register wp_slimstat_db as a service
		if ( class_exists( 'wp_slimstat_db' ) ) {
			$container['wp_slimstat_db'] = function () {
				return new \wp_slimstat_db();
			};
		}

		// Allow third-party code to add services
		return apply_filters( 'slimstat_reports_di_container', $container );
	}

	/**
	 * Register WordPress hooks
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		// Hook into wp_slimstat_reports init
		add_action( 'init', [ $this, 'maybe_load_legacy_adapter' ], 5 );

		// Allow third-party code to register custom reports
		add_action( 'slimstat_register_custom_reports', [ $this, 'register_custom_reports' ] );

		// Register AJAX handlers
		$this->register_ajax_handlers();
	}

	/**
	 * Register AJAX handlers for reports
	 *
	 * @return void
	 */
	private function register_ajax_handlers(): void {
		// AJAX handlers are registered by each report via init_hooks()
		// This action is for third-party extensions
		do_action( 'slimstat_register_ajax_handlers' );
	}

	/**
	 * Load legacy adapter if wp_slimstat_reports is being used
	 *
	 * @return void
	 */
	public function maybe_load_legacy_adapter(): void {
		if ( class_exists( '\wp_slimstat_reports' ) ) {
			// Sync user reports
			$this->adapter->sync_user_reports();
		}
	}

	/**
	 * Allow third-party code to register custom reports
	 *
	 * @param array<string> $report_classes Array of report class names
	 * @return void
	 */
	public function register_custom_reports( array $report_classes ): void {
		if ( empty( $report_classes ) ) {
			return;
		}

		$this->loader->register_classes( $report_classes, true );
	}

	/**
	 * Get the registry
	 *
	 * @return ReportRegistry|null
	 */
	public function get_registry(): ?ReportRegistry {
		return $this->registry;
	}

	/**
	 * Get the factory
	 *
	 * @return ReportFactory|null
	 */
	public function get_factory(): ?ReportFactory {
		return $this->factory;
	}

	/**
	 * Get the loader
	 *
	 * @return ReportLoader|null
	 */
	public function get_loader(): ?ReportLoader {
		return $this->loader;
	}

	/**
	 * Get the adapter
	 *
	 * @return LegacyReportAdapter|null
	 */
	public function get_adapter(): ?LegacyReportAdapter {
		return $this->adapter;
	}

	/**
	 * Check if the system is initialized
	 *
	 * @return bool
	 */
	public function is_initialized(): bool {
		return $this->initialized;
	}
}
