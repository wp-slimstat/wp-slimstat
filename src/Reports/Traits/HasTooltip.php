<?php
/**
 * Has Tooltip Trait
 *
 * @package SlimStat
 * @since 5.4.0
 */

declare(strict_types=1);

namespace SlimStat\Reports\Traits;

/**
 * Trait HasTooltip
 *
 * Provides tooltip/help text functionality to reports.
 */
trait HasTooltip {
	/**
	 * Tooltip/help text
	 *
	 * @var string|null
	 */
	protected ?string $tooltip = null;

	/**
	 * Set the tooltip text
	 *
	 * @param string|null $tooltip Tooltip HTML content
	 * @return self
	 */
	public function set_tooltip( ?string $tooltip ): self {
		$this->tooltip = $tooltip;
		return $this;
	}

	/**
	 * Get the tooltip text
	 *
	 * @return string|null
	 */
	public function get_tooltip(): ?string {
		return $this->tooltip;
	}

	/**
	 * Build chart tooltip text
	 *
	 * @param string $title   Chart title
	 * @param string $description Chart description
	 * @param array<string>  $bullets List of bullet points
	 * @return string Formatted tooltip HTML
	 */
	protected function build_chart_tooltip( string $title, string $description, array $bullets ): string {
		$html = '<strong>' . esc_html( $title ) . '</strong><br>';
		$html .= esc_html( $description );

		if ( ! empty( $bullets ) ) {
			$html .= '<ul style="margin-top: 8px; margin-bottom: 8px;">';
			foreach ( $bullets as $bullet ) {
				$html .= '<li>' . esc_html( $bullet ) . '</li>';
			}
			$html .= '</ul>';
		}

		return $html;
	}

	/**
	 * Build standard tooltip text
	 *
	 * @param string $text Tooltip text
	 * @return string Formatted tooltip HTML
	 */
	protected function build_tooltip( string $text ): string {
		return esc_html( $text );
	}
}
