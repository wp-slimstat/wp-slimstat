<?php
/**
 * Report Interface
 *
 * @package SlimStat
 * @since 5.4.0
 */

declare(strict_types=1);

namespace SlimStat\Reports\Contracts;

/**
 * Interface ReportInterface
 *
 * Core interface that all reports must implement.
 * Defines the contract for report objects in the system.
 */
interface ReportInterface {
	/**
	 * Get the unique identifier for this report.
	 *
	 * @return string Report ID (e.g., 'slim_p1_01')
	 */
	public function get_id(): string;

	/**
	 * Get the human-readable title for this report.
	 *
	 * @return string Translated report title
	 */
	public function get_title(): string;

	/**
	 * Get the locations where this report should appear.
	 *
	 * @return array<string> Array of location identifiers (e.g., ['slimview1', 'dashboard'])
	 */
	public function get_locations(): array;

	/**
	 * Get the CSS classes for this report container.
	 *
	 * @return array<string> Array of CSS class names
	 */
	public function get_classes(): array;

	/**
	 * Get the tooltip/help text for this report.
	 *
	 * @return string|null Tooltip HTML or null if no tooltip
	 */
	public function get_tooltip(): ?string;

	/**
	 * Check if the current user can view this report.
	 *
	 * @return bool True if user has permission to view
	 */
	public function can_view(): bool;

	/**
	 * Render the complete report (header + content + footer).
	 *
	 * @return void
	 */
	public function render(): void;

	/**
	 * Get the report configuration as an array.
	 * Used for backward compatibility with legacy system.
	 *
	 * @return array<string, mixed> Report configuration
	 */
	public function to_array(): array;
}
