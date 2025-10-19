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

		$this->initialized = true;

		do_action( 'slimstat_reports_system_initialized' );
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
	}

	/**
	 * Load legacy adapter if wp_slimstat_reports is being used
	 *
	 * @return void
	 */
	public function maybe_load_legacy_adapter(): void {
		if ( class_exists( 'wp_slimstat_reports' ) ) {
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
