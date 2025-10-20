<?php
/**
 * Report Registry
 *
 * @package SlimStat
 * @since 5.4.0
 */

declare(strict_types=1);

namespace SlimStat\Reports\Registry;

use SlimStat\Reports\Contracts\ReportInterface;

/**
 * Class ReportRegistry
 *
 * Central registry for all reports in the system.
 * Implements Singleton pattern to ensure single instance.
 */
class ReportRegistry {
	/**
	 * Singleton instance
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Registered reports
	 *
	 * @var array<string, ReportInterface>
	 */
	private array $reports = [];

	/**
	 * Reports grouped by location
	 *
	 * @var array<string, array<string>>
	 */
	private array $locations_map = [
		'slimview1' => [],
		'slimview2' => [],
		'slimview3' => [],
		'slimview4' => [],
		'slimview5' => [],
		'dashboard' => [],
		'inactive'  => [],
	];

	/**
	 * User-specific report configuration
	 *
	 * @var array<string, array<string>>
	 */
	private array $user_reports = [];

	/**
	 * Private constructor to prevent direct instantiation
	 */
	private function __construct() {
		$this->load_user_reports();
	}

	/**
	 * Prevent cloning
	 *
	 * @return void
	 */
	private function __clone() {
	}

	/**
	 * Prevent unserialization
	 *
	 * @return void
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton' );
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
	 * Register a report
	 *
	 * @param ReportInterface $report Report instance
	 * @return void
	 */
	public function register( ReportInterface $report ): void {
		$id = $report->get_id();

		// Store the report
		$this->reports[ $id ] = $report;

		// Map to locations
		foreach ( $report->get_locations() as $location ) {
			if ( ! isset( $this->locations_map[ $location ] ) ) {
				$this->locations_map[ $location ] = [];
			}

			if ( ! in_array( $id, $this->locations_map[ $location ], true ) ) {
				$this->locations_map[ $location ][] = $id;
			}
		}

		do_action( 'slimstat_report_registered', $report );
	}

	/**
	 * Unregister a report
	 *
	 * @param string $id Report ID
	 * @return void
	 */
	public function unregister( string $id ): void {
		if ( ! isset( $this->reports[ $id ] ) ) {
			return;
		}

		$report = $this->reports[ $id ];

		// Remove from locations map
		foreach ( $report->get_locations() as $location ) {
			if ( isset( $this->locations_map[ $location ] ) ) {
				$this->locations_map[ $location ] = array_diff(
					$this->locations_map[ $location ],
					[ $id ]
				);
			}
		}

		// Remove the report
		unset( $this->reports[ $id ] );

		do_action( 'slimstat_report_unregistered', $id );
	}

	/**
	 * Get a report by ID
	 *
	 * @param string $id Report ID
	 * @return ReportInterface|null
	 */
	public function get( string $id ): ?ReportInterface {
		return $this->reports[ $id ] ?? null;
	}

	/**
	 * Get all registered reports
	 *
	 * @return array<string, ReportInterface>
	 */
	public function get_all(): array {
		return $this->reports;
	}

	/**
	 * Get reports by location
	 *
	 * @param string $location Location identifier
	 * @return array<ReportInterface>
	 */
	public function get_by_location( string $location ): array {
		if ( ! isset( $this->locations_map[ $location ] ) ) {
			return [];
		}

		$reports = [];
		foreach ( $this->locations_map[ $location ] as $id ) {
			if ( isset( $this->reports[ $id ] ) ) {
				$reports[ $id ] = $this->reports[ $id ];
			}
		}

		// Sort reports by priority (lower number = higher priority)
		uasort( $reports, function( $a, $b ) {
			$priority_a = method_exists( $a, 'get_priority' ) ? $a->get_priority() : 10;
			$priority_b = method_exists( $b, 'get_priority' ) ? $b->get_priority() : 10;
			return $priority_a <=> $priority_b;
		} );

		return $reports;
	}

	/**
	 * Check if a report is registered
	 *
	 * @param string $id Report ID
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->reports[ $id ] );
	}

	/**
	 * Get all available locations
	 *
	 * @return array<string>
	 */
	public function get_locations(): array {
		return array_keys( $this->locations_map );
	}

	/**
	 * Get reports as legacy array format
	 * For backward compatibility with wp_slimstat_reports
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function to_legacy_array(): array {
		$legacy = [];

		foreach ( $this->reports as $id => $report ) {
			$legacy[ $id ] = $report->to_array();
		}

		return $legacy;
	}

	/**
	 * Get user reports configuration
	 *
	 * @return array<string, array<string>>
	 */
	public function get_user_reports(): array {
		return $this->user_reports;
	}

	/**
	 * Set user reports configuration
	 *
	 * @param array<string, array<string>> $user_reports User reports config
	 * @return void
	 */
	public function set_user_reports( array $user_reports ): void {
		$this->user_reports = $user_reports;
	}

	/**
	 * Load user-specific report configuration
	 *
	 * @return void
	 */
	private function load_user_reports(): void {
		if ( ! class_exists( '\wp_slimstat_admin' ) ) {
			return;
		}

		if ( ! empty( \wp_slimstat_admin::$meta_user_reports ) && is_array( \wp_slimstat_admin::$meta_user_reports ) ) {
			foreach ( \wp_slimstat_admin::$meta_user_reports as $location => $report_list ) {
				if ( ! array_key_exists( $location, $this->locations_map ) ) {
					continue;
				}

				$this->user_reports[ $location ] = explode( ',', $report_list );
			}
		}
	}

	/**
	 * Merge new reports with user configuration
	 *
	 * @return void
	 */
	public function merge_with_user_config(): void {
		if ( empty( $this->user_reports ) ) {
			return;
		}

		// Flatten the multi-dimensional user_reports array
		$flat_user_reports = [];
		foreach ( $this->user_reports as $location_reports ) {
			if ( is_array( $location_reports ) ) {
				$flat_user_reports = array_merge( $flat_user_reports, $location_reports );
			}
		}
		$flat_user_reports = array_unique( array_filter( $flat_user_reports ) );

		$all_report_ids    = array_keys( $this->reports );
		$new_reports       = array_diff( $all_report_ids, $flat_user_reports );

		// Add new reports to appropriate locations
		foreach ( $new_reports as $report_id ) {
			$report = $this->get( $report_id );
			if ( ! $report ) {
				continue;
			}

			foreach ( $report->get_locations() as $location ) {
				if ( ! isset( $this->user_reports[ $location ] ) ) {
					$this->user_reports[ $location ] = [];
				}

				if ( ! in_array( $report_id, $this->user_reports[ $location ], true ) ) {
					$this->user_reports[ $location ][] = $report_id;
				}
			}
		}
	}

	/**
	 * Clear all registered reports (for testing)
	 *
	 * @return void
	 */
	public function clear(): void {
		$this->reports       = [];
		$this->locations_map = [
			'slimview1' => [],
			'slimview2' => [],
			'slimview3' => [],
			'slimview4' => [],
			'slimview5' => [],
			'dashboard' => [],
			'inactive'  => [],
		];
	}
}
