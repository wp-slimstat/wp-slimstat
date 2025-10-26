<?php
/**
 * Renderable Interface
 *
 * @package SlimStat
 * @since 5.4.0
 */

declare(strict_types=1);

namespace SlimStat\Reports\Contracts;

/**
 * Interface RenderableInterface
 *
 * For reports that can be rendered using a renderer.
 */
interface RenderableInterface {
	/**
	 * Get the data to be rendered.
	 *
	 * @return array<string, mixed> Report data
	 */
	public function get_data(): array;

	/**
	 * Get the renderer class name for this report.
	 *
	 * @return string Fully qualified renderer class name
	 */
	public function get_renderer(): string;

	/**
	 * Render the report content (without header/footer).
	 *
	 * @return void
	 */
	public function render_content(): void;
}
