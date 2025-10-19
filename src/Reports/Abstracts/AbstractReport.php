<?php
/**
 * Abstract Report Base Class
 *
 * @package SlimStat
 * @since 5.4.0
 */

declare(strict_types=1);

namespace SlimStat\Reports\Abstracts;

use SlimStat\Reports\Contracts\ReportInterface;
use SlimStat\Reports\Contracts\RenderableInterface;

/**
 * Class AbstractReport
 *
 * Base abstract class for all reports.
 * Implements common functionality and enforces structure.
 */
abstract class AbstractReport implements ReportInterface, RenderableInterface {
	/**
	 * Report unique identifier
	 *
	 * @var string
	 */
	protected string $id;

	/**
	 * Report title (translatable)
	 *
	 * @var string
	 */
	protected string $title;

	/**
	 * Locations where this report appears
	 *
	 * @var array<string>
	 */
	protected array $locations = [];

	/**
	 * CSS classes for the report container
	 *
	 * @var array<string>
	 */
	protected array $classes = [];

	/**
	 * Tooltip/help text
	 *
	 * @var string|null
	 */
	protected ?string $tooltip = null;

	/**
	 * Report color (for styling)
	 *
	 * @var string
	 */
	protected string $color = '#EFF6FF';

	/**
	 * Minimum capability required to view
	 *
	 * @var string
	 */
	protected string $capability = 'read';

	/**
	 * Constructor - initializes the report
	 */
	public function __construct() {
		$this->init();
		$this->setup_capability();
	}

	/**
	 * Initialize report properties.
	 * Must be implemented by child classes.
	 *
	 * @return void
	 */
	abstract protected function init(): void;

	/**
	 * Get report data from database or other source.
	 * Must be implemented by child classes.
	 *
	 * @return array<string, mixed>
	 */
	abstract public function get_data(): array;

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_title(): string {
		return $this->title;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_locations(): array {
		return $this->locations;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_classes(): array {
		return $this->classes;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_tooltip(): ?string {
		return $this->tooltip;
	}

	/**
	 * Get the report color
	 *
	 * @return string
	 */
	public function get_color(): string {
		return $this->color;
	}

	/**
	 * {@inheritDoc}
	 */
	public function can_view(): bool {
		// Check if user is whitelisted
		if ( false !== strpos( ( wp_slimstat::$settings['can_view'] ?? '' ), (string) $GLOBALS['current_user']->user_login ) ) {
			return current_user_can( 'read' );
		}

		// Check capability
		$minimum_capability = wp_slimstat::$settings['capability_can_view'] ?? $this->capability;
		return current_user_can( $minimum_capability );
	}

	/**
	 * Setup the required capability based on settings
	 *
	 * @return void
	 */
	protected function setup_capability(): void {
		if ( ! empty( wp_slimstat::$settings['capability_can_view'] ) ) {
			$this->capability = wp_slimstat::$settings['capability_can_view'];
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function render(): void {
		if ( ! $this->can_view() ) {
			return;
		}

		$this->render_header();
		$this->render_content();
		$this->render_footer();
	}

	/**
	 * Render the report header
	 *
	 * @return void
	 */
	protected function render_header(): void {
		if ( ! is_admin() ) {
			return;
		}

		$header_classes = implode( ' ', $this->get_classes() );
		$fixed_title    = str_replace( [ '-', '_', '"', "'", ')', '(' ], '', strtolower( $this->get_title() ) );
		$header_classes .= ' report-' . implode( '-', explode( ' ', esc_attr( $fixed_title ) ) );

		// Refresh button
		$header_buttons = $this->get_header_buttons();

		// Tooltip
		$header_tooltip = $this->get_header_tooltip();

		// Widget title
		$widget_title = '<h3>' . esc_html( $this->get_title() ) . $header_tooltip . '</h3>';

		$bar_color = $this->get_color();

		echo "<div class='postbox " . esc_attr( $header_classes ) . "' style='--box-bar-color: " . esc_attr( $bar_color ) . ";' id='" . esc_attr( $this->get_id() ) . "'>";
		echo $header_buttons;
		echo $widget_title;
		echo "<div class='inside'>";
	}

	/**
	 * Render the report footer
	 *
	 * @return void
	 */
	protected function render_footer(): void {
		echo '</div></div>';
	}

	/**
	 * Get header buttons HTML
	 *
	 * @return string
	 */
	protected function get_header_buttons(): string {
		if ( ! is_admin() ) {
			return '';
		}

		$buttons = '';

		// Refresh button (only if time range is current)
		if ( isset( wp_slimstat_db::$filters_normalized['utime']['end'] ) && wp_slimstat_db::$filters_normalized['utime']['end'] >= date_i18n( 'U' ) - 300 ) {
			$buttons = '<a class="noslimstat refresh" title="' . esc_attr__( 'Refresh', 'wp-slimstat' ) . '" href="' . $this->get_refresh_url() . '"><svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M2.44215 9.33359C2.50187 5.19973 5.89666 1.875 10.0656 1.875C12.8226 1.875 15.239 3.32856 16.5777 5.50601C16.7584 5.80006 16.6666 6.18499 16.3726 6.36576C16.0785 6.54654 15.6936 6.45471 15.5128 6.16066C14.3937 4.34037 12.3735 3.125 10.0656 3.125C6.57859 3.125 3.75293 5.89808 3.69234 9.33181L4.02599 9.00077C4.27102 8.75765 4.66675 8.75921 4.90986 9.00424C5.15298 9.24928 5.15143 9.645 4.90639 9.88812L3.50655 11.277C3.26288 11.5188 2.86982 11.5188 2.62614 11.277L1.2263 9.88812C0.981267 9.645 0.979713 9.24928 1.22283 9.00424C1.46595 8.75921 1.86167 8.75765 2.10671 9.00077L2.44215 9.33359ZM16.4885 8.72215C16.732 8.4815 17.1238 8.4815 17.3672 8.72215L18.7724 10.111C19.0179 10.3537 19.0202 10.7494 18.7776 10.9949C18.5349 11.2404 18.1392 11.2427 17.8937 11.0001L17.5521 10.6624C17.4943 14.8003 14.0846 18.125 9.90191 18.125C7.13633 18.125 4.71134 16.6725 3.3675 14.4949C3.18622 14.2012 3.2774 13.8161 3.57114 13.6348C3.86489 13.4535 4.24997 13.5447 4.43125 13.8384C5.5545 15.6586 7.58316 16.875 9.90191 16.875C13.4071 16.875 16.2433 14.0976 16.302 10.6641L15.962 11.0001C15.7165 11.2427 15.3208 11.2404 15.0782 10.9949C14.8355 10.7494 14.8378 10.3537 15.0833 10.111L16.4885 8.72215Z" fill="#676E74"/></svg></a>';
		}

		// Allow third-party code to add more buttons
		$buttons = apply_filters( 'slimstat_report_header_buttons', $buttons, $this->get_id() );

		if ( ! empty( $buttons ) ) {
			$buttons = '<div class="slimstat-header-buttons">' . $buttons . '</div>';
		}

		return $buttons;
	}

	/**
	 * Get header tooltip HTML
	 *
	 * @return string
	 */
	protected function get_header_tooltip(): string {
		$tooltip_content = $this->get_tooltip();

		if ( empty( $tooltip_content ) ) {
			$tooltip_content = esc_html( $this->get_id() );
		} else {
			$tooltip_content .= '<br /><br />' . esc_html( $this->get_id() );
		}

		return '<span class="header-tooltip slimstat-tooltip-trigger corner"><svg width="17" height="18" viewBox="0 0 17 18" fill="none" xmlns="http://www.w3.org/2000/svg"> <path d="M8.6665 13.3125C8.97716 13.3125 9.229 13.0607 9.229 12.75V8.25C9.229 7.93934 8.97716 7.6875 8.6665 7.6875C8.35584 7.6875 8.104 7.93934 8.104 8.25V12.75C8.104 13.0607 8.35584 13.3125 8.6665 13.3125Z" fill="#9BA1A6"/> <path d="M8.6665 5.25C9.08072 5.25 9.4165 5.58579 9.4165 6C9.4165 6.41421 9.08072 6.75 8.6665 6.75C8.25229 6.75 7.9165 6.41421 7.9165 6C7.9165 5.58579 8.25229 5.25 8.6665 5.25Z" fill="#9BA1A6"/> <path fill-rule="evenodd" clip-rule="evenodd" d="M0.604004 9C0.604004 4.5472 4.21371 0.9375 8.6665 0.9375C13.1193 0.9375 16.729 4.5472 16.729 9C16.729 13.4528 13.1193 17.0625 8.6665 17.0625C4.21371 17.0625 0.604004 13.4528 0.604004 9ZM8.6665 2.0625C4.83503 2.0625 1.729 5.16852 1.729 9C1.729 12.8315 4.83503 15.9375 8.6665 15.9375C12.498 15.9375 15.604 12.8315 15.604 9C15.604 5.16852 12.498 2.0625 8.6665 2.0625Z" fill="#9BA1A6"/></svg><span class="slimstat-tooltip-content">' . $tooltip_content . '</span></span>';
	}

	/**
	 * Get the refresh URL for this report
	 *
	 * @return string
	 */
	protected function get_refresh_url(): string {
		if ( ! class_exists( 'wp_slimstat_reports' ) ) {
			return '#';
		}

		return wp_slimstat_reports::fs_url();
	}

	/**
	 * {@inheritDoc}
	 */
	public function to_array(): array {
		return [
			'title'         => $this->get_title(),
			'callback'      => [ $this, 'render_content' ],
			'callback_args' => $this->get_callback_args(),
			'classes'       => $this->get_classes(),
			'locations'     => $this->get_locations(),
			'tooltip'       => $this->get_tooltip(),
			'color'         => $this->get_color(),
		];
	}

	/**
	 * Get callback arguments for backward compatibility
	 *
	 * @return array<string, mixed>
	 */
	protected function get_callback_args(): array {
		return [];
	}
}
