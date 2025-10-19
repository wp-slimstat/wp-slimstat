<?php
/**
 * Report Loader
 *
 * @package SlimStat
 * @since 5.4.0
 */

declare(strict_types=1);

namespace SlimStat\Reports\Registry;

use SlimStat\Reports\Contracts\ReportInterface;

/**
 * Class ReportLoader
 *
 * Automatically discovers and registers report classes.
 */
class ReportLoader {
	/**
	 * Report registry
	 *
	 * @var ReportRegistry
	 */
	private ReportRegistry $registry;

	/**
	 * Report factory
	 *
	 * @var ReportFactory
	 */
	private ReportFactory $factory;

	/**
	 * Directories to scan for reports
	 *
	 * @var array<string>
	 */
	private array $scan_directories = [];

	/**
	 * Namespace prefix for discovered reports
	 *
	 * @var string
	 */
	private string $namespace_prefix = 'SlimStat\\Reports\\Types\\';

	/**
	 * Constructor
	 *
	 * @param ReportRegistry $registry Report registry
	 * @param ReportFactory  $factory  Report factory
	 */
	public function __construct( ReportRegistry $registry, ReportFactory $factory ) {
		$this->registry = $registry;
		$this->factory  = $factory;

		// Default scan directory
		$this->scan_directories = [
			dirname( __DIR__ ) . '/Types',
		];
	}

	/**
	 * Add a directory to scan for reports
	 *
	 * @param string $directory Directory path
	 * @param string $namespace Namespace prefix for classes in this directory
	 * @return void
	 */
	public function add_scan_directory( string $directory, string $namespace = '' ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		$this->scan_directories[] = $directory;

		if ( ! empty( $namespace ) ) {
			$this->namespace_prefix = rtrim( $namespace, '\\' ) . '\\';
		}
	}

	/**
	 * Load and register all reports
	 *
	 * @param bool $cache Whether to cache report instances
	 * @return int Number of reports loaded
	 */
	public function load_all( bool $cache = false ): int {
		$loaded = 0;

		foreach ( $this->scan_directories as $directory ) {
			$loaded += $this->load_from_directory( $directory, $cache );
		}

		// Merge with user configuration
		$this->registry->merge_with_user_config();

		do_action( 'slimstat_reports_loaded', $loaded );

		return $loaded;
	}

	/**
	 * Load reports from a specific directory
	 *
	 * @param string $directory Directory path
	 * @param bool   $cache     Whether to cache instances
	 * @return int Number of reports loaded
	 */
	private function load_from_directory( string $directory, bool $cache = false ): int {
		if ( ! is_dir( $directory ) ) {
			return 0;
		}

		$loaded  = 0;
		$classes = $this->discover_report_classes( $directory );

		foreach ( $classes as $class ) {
			if ( $this->load_report_class( $class, $cache ) ) {
				$loaded++;
			}
		}

		return $loaded;
	}

	/**
	 * Discover report classes in a directory
	 *
	 * @param string $directory Directory path
	 * @return array<string> Array of fully qualified class names
	 */
	private function discover_report_classes( string $directory ): array {
		$classes = [];
		$files   = $this->scan_directory_recursive( $directory );

		foreach ( $files as $file ) {
			$class = $this->file_to_class_name( $file, $directory );

			if ( $class && class_exists( $class ) && $this->is_report_class( $class ) ) {
				$classes[] = $class;
			}
		}

		return $classes;
	}

	/**
	 * Recursively scan directory for PHP files
	 *
	 * @param string $directory Directory path
	 * @return array<string> Array of file paths
	 */
	private function scan_directory_recursive( string $directory ): array {
		$files = [];

		if ( ! is_dir( $directory ) ) {
			return $files;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $directory, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		return $files;
	}

	/**
	 * Convert file path to class name
	 *
	 * @param string $file      File path
	 * @param string $base_dir  Base directory
	 * @return string|null Class name or null if invalid
	 */
	private function file_to_class_name( string $file, string $base_dir ): ?string {
		$relative = str_replace( $base_dir, '', $file );
		$relative = str_replace( [ '/', '\\' ], '\\', $relative );
		$relative = ltrim( $relative, '\\' );
		$relative = str_replace( '.php', '', $relative );

		// Get the subdirectory structure (e.g., "Overview\AccessLogReport")
		$parts = explode( '\\', $relative );

		if ( empty( $parts ) ) {
			return null;
		}

		// Build class name: SlimStat\Reports\Types\Overview\AccessLogReport
		return $this->namespace_prefix . implode( '\\', $parts );
	}

	/**
	 * Check if a class is a valid report class
	 *
	 * @param string $class Fully qualified class name
	 * @return bool
	 */
	private function is_report_class( string $class ): bool {
		if ( ! class_exists( $class ) ) {
			return false;
		}

		try {
			$reflection = new \ReflectionClass( $class );

			// Must not be abstract or interface
			if ( $reflection->isAbstract() || $reflection->isInterface() ) {
				return false;
			}

			// Must implement ReportInterface
			return $reflection->implementsInterface( ReportInterface::class );
		} catch ( \ReflectionException $e ) {
			return false;
		}
	}

	/**
	 * Load and register a single report class
	 *
	 * @param string $class Fully qualified class name
	 * @param bool   $cache Whether to cache the instance
	 * @return bool Success status
	 */
	private function load_report_class( string $class, bool $cache = false ): bool {
		try {
			$report = $this->factory->create( $class, [], $cache );
			$this->registry->register( $report );

			return true;
		} catch ( \Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( "Failed to load report class {$class}: " . $e->getMessage() );
			}

			return false;
		}
	}

	/**
	 * Register reports from an array of class names
	 *
	 * @param array<string> $classes Array of class names
	 * @param bool          $cache   Whether to cache instances
	 * @return int Number of reports registered
	 */
	public function register_classes( array $classes, bool $cache = false ): int {
		$registered = 0;

		foreach ( $classes as $class ) {
			if ( $this->load_report_class( $class, $cache ) ) {
				$registered++;
			}
		}

		return $registered;
	}

	/**
	 * Register a single report instance
	 *
	 * @param ReportInterface $report Report instance
	 * @return void
	 */
	public function register_instance( ReportInterface $report ): void {
		$this->registry->register( $report );
	}

	/**
	 * Get the registry
	 *
	 * @return ReportRegistry
	 */
	public function get_registry(): ReportRegistry {
		return $this->registry;
	}

	/**
	 * Get the factory
	 *
	 * @return ReportFactory
	 */
	public function get_factory(): ReportFactory {
		return $this->factory;
	}
}
