<?php
/**
 * Legacy Report Adapter
 *
 * @package SlimStat
 * @since 5.4.0
 */

declare(strict_types=1);

namespace SlimStat\Reports\Registry;

/**
 * Class LegacyReportAdapter
 *
 * Bridges the new report system with the legacy wp_slimstat_reports class.
 * Ensures backward compatibility while allowing gradual migration.
 */
class LegacyReportAdapter {
	/**
	 * Report registry
	 *
	 * @var ReportRegistry
	 */
	private ReportRegistry $registry;

	/**
	 * Report loader
	 *
	 * @var ReportLoader
	 */
	private ReportLoader $loader;

	/**
	 * Whether the adapter is initialized
	 *
	 * @var bool
	 */
	private static bool $initialized = false;

	/**
	 * Constructor
	 *
	 * @param ReportRegistry $registry Report registry
	 * @param ReportLoader   $loader   Report loader
	 */
	public function __construct( ReportRegistry $registry, ReportLoader $loader ) {
		$this->registry = $registry;
		$this->loader   = $loader;
	}

	/**
	 * Initialize the adapter and sync with legacy system
	 *
	 * @return void
	 */
	public function init(): void {
		if ( self::$initialized ) {
			return;
		}

		// Hook into wp_slimstat_reports initialization
		add_filter( 'slimstat_reports_info', [ $this, 'merge_reports' ], 999 );

		// Load new reports
		$this->loader->load_all( true );

		self::$initialized = true;
	}

	/**
	 * Merge new reports into legacy reports array
	 *
	 * @param array<string, array<string, mixed>> $legacy_reports Legacy reports array
	 * @return array<string, array<string, mixed>>
	 */
	public function merge_reports( array $legacy_reports ): array {
		// Get new reports as legacy array format
		$new_reports = $this->registry->to_legacy_array();

		// Merge new reports with legacy ones
		// New reports take precedence if there's a conflict
		return array_merge( $legacy_reports, $new_reports );
	}

	/**
	 * Sync user reports configuration
	 *
	 * @return array<string, array<string>>
	 */
	public function sync_user_reports(): array {
		$user_reports = $this->registry->get_user_reports();

		// If no user reports yet, initialize from legacy system
		if ( empty( $user_reports ) && class_exists( 'wp_slimstat_reports' ) ) {
			$legacy_user_reports = wp_slimstat_reports::$user_reports ?? [];

			if ( ! empty( $legacy_user_reports ) ) {
				$this->registry->set_user_reports( $legacy_user_reports );
			}
		}

		return $this->registry->get_user_reports();
	}

	/**
	 * Get all reports in legacy format
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_legacy_reports(): array {
		return $this->registry->to_legacy_array();
	}

	/**
	 * Get user reports in legacy format
	 *
	 * @return array<string, array<string>>
	 */
	public function get_legacy_user_reports(): array {
		return $this->registry->get_user_reports();
	}

	/**
	 * Check if a report exists in the registry
	 *
	 * @param string $report_id Report ID
	 * @return bool
	 */
	public function has_report( string $report_id ): bool {
		return $this->registry->has( $report_id );
	}

	/**
	 * Render a report by ID
	 *
	 * @param string $report_id Report ID
	 * @return void
	 */
	public function render_report( string $report_id ): void {
		$report = $this->registry->get( $report_id );

		if ( ! $report ) {
			return;
		}

		$report->render();
	}

	/**
	 * Get the registry instance
	 *
	 * @return ReportRegistry
	 */
	public function get_registry(): ReportRegistry {
		return $this->registry;
	}

	/**
	 * Get the loader instance
	 *
	 * @return ReportLoader
	 */
	public function get_loader(): ReportLoader {
		return $this->loader;
	}

	/**
	 * Bootstrap the new report system
	 * This should be called early in the WordPress lifecycle
	 *
	 * @return self
	 */
	public static function bootstrap(): self {
		// Create instances
		$registry = ReportRegistry::get_instance();
		$factory  = new ReportFactory();
		$loader   = new ReportLoader( $registry, $factory );

		// Create adapter
		$adapter = new self( $registry, $loader );

		// Initialize
		$adapter->init();

		return $adapter;
	}

	/**
	 * Check if the adapter is initialized
	 *
	 * @return bool
	 */
	public static function is_initialized(): bool {
		return self::$initialized;
	}
}
