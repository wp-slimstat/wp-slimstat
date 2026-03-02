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

		// Hook into wp_slimstat_reports init to sort new reports by priority
		add_action( 'wp_slimstat_reports_init', [ $this, 'sort_new_reports_by_priority' ], 999 );

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
		$merged = array_merge( $legacy_reports, $new_reports );

		// Ensure Live Analytics appears in slimview1 as first report
		if ( ! empty( $new_reports['slim_live_analytics'] ) ) {
			// Make sure Live Analytics is available in slimview1
			$merged['slim_live_analytics']['locations'] = [ 'slimview1' ];
		}

		return $merged;
	}

	/**
	 * Sync user reports configuration
	 *
	 * @return array<string, array<string>>
	 */
	public function sync_user_reports(): array {
		$user_reports = $this->registry->get_user_reports();

		// If no user reports yet, initialize from legacy system
		if ( empty( $user_reports ) && class_exists( '\wp_slimstat_reports' ) ) {
			$legacy_user_reports = \wp_slimstat_reports::$user_reports ?? [];

			if ( ! empty( $legacy_user_reports ) ) {
				$this->registry->set_user_reports( $legacy_user_reports );
			}
		}

		return $this->registry->get_user_reports();
	}

	/**
	 * Sort new reports by priority when they're added for the first time
	 *
	 * This inserts new reports at positions based on their priority,
	 * while preserving the user's custom arrangement of existing reports.
	 *
	 * @return void
	 */
	public function sort_new_reports_by_priority(): void {
		if ( ! class_exists( '\wp_slimstat_reports' ) ) {
			return;
		}

		// Get all registered reports
		$all_reports = \wp_slimstat_reports::$reports ?? [];
		if ( empty( $all_reports ) ) {
			return;
		}

		// Get the reports that were just added (not in saved user config)
		$saved_user_reports = [];
		if ( class_exists( '\wp_slimstat_admin' ) && ! empty( \wp_slimstat_admin::$meta_user_reports ) ) {
			$saved_user_reports = \wp_slimstat_admin::$meta_user_reports;
		}

		if ( ! is_array( $saved_user_reports ) ) {
			$saved_user_reports = [];
		}

		// Process each location
		foreach ( \wp_slimstat_reports::$user_reports as $location => &$report_ids ) {
			if ( ! is_array( $report_ids ) || empty( $report_ids ) ) {
				continue;
			}

			// Find newly added reports (not in saved config)
			$saved_reports_for_location = isset( $saved_user_reports[ $location ] )
				? explode( ',', $saved_user_reports[ $location ] )
				: [];
			$new_reports = array_diff( $report_ids, $saved_reports_for_location );

			if ( empty( $new_reports ) ) {
				continue;
			}

			// Get priorities for new reports
			$new_report_priorities = [];
			foreach ( $new_reports as $report_id ) {
				if ( isset( $all_reports[ $report_id ] ) ) {
					$new_report_priorities[ $report_id ] = $all_reports[ $report_id ]['priority'] ?? 10;
				}
			}

			// Sort new reports by priority (lower = higher priority)
			asort( $new_report_priorities );

			// Keep existing reports in their current order
			$existing_reports = array_diff( $report_ids, $new_reports );
			$existing_reports = array_values( $existing_reports );

			// Build result array starting with existing reports
			$result = [];
			$new_reports_inserted = [];

			foreach ( $existing_reports as $existing_report_id ) {
				$existing_priority = isset( $all_reports[ $existing_report_id ] )
					? ( $all_reports[ $existing_report_id ]['priority'] ?? 10 )
					: 10;

				// Before adding this existing report, add any new reports with higher priority (lower number)
				foreach ( $new_report_priorities as $new_report_id => $new_priority ) {
					if ( in_array( $new_report_id, $new_reports_inserted, true ) ) {
						continue;
					}

					if ( $new_priority < $existing_priority ) {
						$result[] = $new_report_id;
						$new_reports_inserted[] = $new_report_id;
					}
				}

				// Add the existing report
				$result[] = $existing_report_id;
			}

			// Add any remaining new reports that weren't inserted yet
			foreach ( $new_report_priorities as $new_report_id => $new_priority ) {
				if ( ! in_array( $new_report_id, $new_reports_inserted, true ) ) {
					$result[] = $new_report_id;
				}
			}

			$report_ids = $result;
		}
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
