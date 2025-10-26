<?php
/**
 * Report Factory
 *
 * @package SlimStat
 * @since 5.4.0
 */

declare(strict_types=1);

namespace SlimStat\Reports\Registry;

use SlimStat\Reports\Contracts\ReportInterface;

/**
 * Class ReportFactory
 *
 * Factory for creating report instances.
 * Supports dependency injection and lazy loading.
 */
class ReportFactory {
	/**
	 * Dependency injection container
	 *
	 * @var array<string, mixed>
	 */
	private array $container = [];

	/**
	 * Cached report instances
	 *
	 * @var array<string, ReportInterface>
	 */
	private array $instances = [];

	/**
	 * Report class map
	 *
	 * @var array<string, string>
	 */
	private array $class_map = [];

	/**
	 * Constructor
	 *
	 * @param array<string, mixed> $container DI container (optional)
	 */
	public function __construct( array $container = [] ) {
		$this->container = $container;
	}

	/**
	 * Register a report class
	 *
	 * @param string $id    Report ID
	 * @param string $class Fully qualified class name
	 * @return void
	 */
	public function register_class( string $id, string $class ): void {
		if ( ! class_exists( $class ) ) {
			throw new \InvalidArgumentException( "Class {$class} does not exist" );
		}

		if ( ! is_subclass_of( $class, ReportInterface::class ) ) {
			throw new \InvalidArgumentException( "Class {$class} must implement ReportInterface" );
		}

		$this->class_map[ $id ] = $class;
	}

	/**
	 * Create a report instance
	 *
	 * @param string               $class_or_id Class name or registered ID
	 * @param array<string, mixed> $args        Constructor arguments
	 * @param bool                 $cache       Whether to cache the instance
	 * @return ReportInterface
	 */
	public function create( string $class_or_id, array $args = [], bool $cache = false ): ReportInterface {
		// Check if it's a registered ID
		$class = $this->class_map[ $class_or_id ] ?? $class_or_id;

		// Return cached instance if available
		if ( $cache && isset( $this->instances[ $class ] ) ) {
			return $this->instances[ $class ];
		}

		// Validate class exists and implements interface
		if ( ! class_exists( $class ) ) {
			throw new \InvalidArgumentException( "Class {$class} does not exist" );
		}

		if ( ! is_subclass_of( $class, ReportInterface::class ) ) {
			throw new \InvalidArgumentException( "Class {$class} must implement ReportInterface" );
		}

		// Create instance
		$instance = $this->instantiate( $class, $args );

		// Cache if requested
		if ( $cache ) {
			$this->instances[ $class ] = $instance;
		}

		return $instance;
	}

	/**
	 * Create multiple report instances
	 *
	 * @param array<string> $classes Array of class names or IDs
	 * @param bool          $cache   Whether to cache instances
	 * @return array<ReportInterface>
	 */
	public function create_many( array $classes, bool $cache = false ): array {
		$reports = [];

		foreach ( $classes as $class ) {
			try {
				$reports[] = $this->create( $class, [], $cache );
			} catch ( \Exception $e ) {
				// Log error but continue
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'Failed to create report: ' . $e->getMessage() );
				}
			}
		}

		return $reports;
	}

	/**
	 * Instantiate a report class with dependency injection
	 *
	 * @param string               $class Fully qualified class name
	 * @param array<string, mixed> $args  Constructor arguments
	 * @return ReportInterface
	 */
	private function instantiate( string $class, array $args = [] ): ReportInterface {
		// Check if we have a factory method in the container
		if ( isset( $this->container[ $class ] ) && is_callable( $this->container[ $class ] ) ) {
			return call_user_func( $this->container[ $class ], $args );
		}

		// Try reflection for constructor injection
		try {
			$reflection = new \ReflectionClass( $class );

			// No constructor or no parameters - simple instantiation
			$constructor = $reflection->getConstructor();
			if ( ! $constructor || 0 === $constructor->getNumberOfParameters() ) {
				return new $class();
			}

			// Resolve constructor dependencies
			$dependencies = $this->resolve_dependencies( $constructor, $args );

			return $reflection->newInstanceArgs( $dependencies );
		} catch ( \ReflectionException $e ) {
			throw new \RuntimeException( "Failed to instantiate {$class}: " . $e->getMessage() );
		}
	}

	/**
	 * Resolve constructor dependencies
	 *
	 * @param \ReflectionMethod    $constructor Constructor method
	 * @param array<string, mixed> $args        User-provided arguments
	 * @return array<mixed>
	 */
	private function resolve_dependencies( \ReflectionMethod $constructor, array $args = [] ): array {
		$dependencies = [];

		foreach ( $constructor->getParameters() as $param ) {
			$name = $param->getName();

			// Use user-provided argument if available
			if ( isset( $args[ $name ] ) ) {
				$dependencies[] = $args[ $name ];
				continue;
			}

			// Try to resolve from container
			$type = $param->getType();
			if ( $type && ! $type->isBuiltin() ) {
				$type_name = $type->getName();

				if ( isset( $this->container[ $type_name ] ) ) {
					$dependencies[] = is_callable( $this->container[ $type_name ] )
						? call_user_func( $this->container[ $type_name ] )
						: $this->container[ $type_name ];
					continue;
				}

				// Try to auto-wire
				if ( class_exists( $type_name ) ) {
					$dependencies[] = $this->create( $type_name, [], true );
					continue;
				}
			}

			// Use default value if available
			if ( $param->isDefaultValueAvailable() ) {
				$dependencies[] = $param->getDefaultValue();
				continue;
			}

			// Cannot resolve
			throw new \RuntimeException(
				"Cannot resolve dependency {$name} for class " . $constructor->getDeclaringClass()->getName()
			);
		}

		return $dependencies;
	}

	/**
	 * Bind a service to the container
	 *
	 * @param string         $id      Service identifier
	 * @param mixed|callable $service Service instance or factory
	 * @return void
	 */
	public function bind( string $id, $service ): void {
		$this->container[ $id ] = $service;
	}

	/**
	 * Bind a singleton service
	 *
	 * @param string   $id      Service identifier
	 * @param callable $factory Factory function
	 * @return void
	 */
	public function singleton( string $id, callable $factory ): void {
		$this->container[ $id ] = function () use ( $factory, $id ) {
			static $instance = null;

			if ( null === $instance ) {
				$instance = $factory();
			}

			return $instance;
		};
	}

	/**
	 * Clear cached instances
	 *
	 * @return void
	 */
	public function clear_cache(): void {
		$this->instances = [];
	}

	/**
	 * Get all registered class mappings
	 *
	 * @return array<string, string>
	 */
	public function get_class_map(): array {
		return $this->class_map;
	}
}
