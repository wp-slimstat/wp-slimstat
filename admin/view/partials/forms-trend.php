<?php
/** Daily observations, with gaps instead of invented historical zeroes. @license GPL-2.0-or-later */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Local template variables.
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
if ( ! \SlimStat\Forms\Report::canView() || ! isset( $report['trend'], $report['start'], $report['end'] ) ) {
	return; }
wp_enqueue_script( 'slimstat-forms-report' );
$points = array_column( $report['trend'], null, 'day' );
$chart  = array(
	'labels'         => array(),
	'seen'           => array(),
	'completed'      => array(),
	'seenLabel'      => __( 'Seen', 'wp-slimstat' ),
	'completedLabel' => __( 'Completed', 'wp-slimstat' ),
);
for ( $date = $report['start']; $date <= $report['end']; $date += DAY_IN_SECONDS ) {
	$chart['labels'][]    = date_i18n( get_option( 'date_format' ), $date );
	$chart['seen'][]      = isset( $points[ $date ] ) ? (int) $points[ $date ]['seen'] : null;
	$chart['completed'][] = isset( $points[ $date ] ) ? (int) $points[ $date ]['submitted'] : null;
}
?>
<section class="ss-forms-trend" aria-labelledby="ss-forms-trend-heading">
	<div class="ss-forms-section-heading"><div><h2 id="ss-forms-trend-heading"><?php esc_html_e( 'Are completions growing?', 'wp-slimstat' ); ?></h2><p><?php esc_html_e( 'Compare daily form views with confirmed completions.', 'wp-slimstat' ); ?></p></div>
	<?php
	if ( $report['end'] >= floor( wp_slimstat::now() / DAY_IN_SECONDS ) * DAY_IN_SECONDS ) :
		?>
		<span class="ss-forms-tag"><?php esc_html_e( 'Today is still in progress', 'wp-slimstat' ); ?></span><?php endif; ?></div>
	<figure data-forms-trend="<?php echo esc_attr( wp_json_encode( $chart ) ); ?>">
		<div data-forms-chart hidden>
			<div class="ss-forms-chart-legend"><label><input type="checkbox" data-forms-series="0" checked /><span class="ss-forms-swatch ss-forms-swatch-completed" aria-hidden="true"></span><?php esc_html_e( 'Completed', 'wp-slimstat' ); ?></label><label><input type="checkbox" data-forms-series="1" checked /><span class="ss-forms-swatch ss-forms-swatch-seen" aria-hidden="true"></span><?php esc_html_e( 'Seen', 'wp-slimstat' ); ?></label></div>
			<div class="ss-forms-canvas"><canvas role="img" aria-label="<?php esc_attr_e( 'Daily form views and completions. Exact values are available in the table below.', 'wp-slimstat' ); ?>"></canvas></div>
		</div>
		<figcaption><?php esc_html_e( 'Gaps mean no activity was recorded. They are not a measured zero. Completions are assigned to the day the form visit began.', 'wp-slimstat' ); ?></figcaption>
	</figure>
	<details><summary><?php esc_html_e( 'View daily numbers', 'wp-slimstat' ); ?></summary><div class="ss-forms-table"><table class="widefat striped"><thead><tr><th scope="col"><?php esc_html_e( 'Date', 'wp-slimstat' ); ?></th><th scope="col"><?php esc_html_e( 'Seen', 'wp-slimstat' ); ?></th><th scope="col"><?php esc_html_e( 'Started', 'wp-slimstat' ); ?></th><th scope="col"><?php esc_html_e( 'Completed', 'wp-slimstat' ); ?></th><th scope="col"><?php esc_html_e( 'Completion rate', 'wp-slimstat' ); ?></th></tr></thead><tbody>
	<?php foreach ( $report['trend'] as $day ) : ?>
		<tr><th scope="row"><?php echo esc_html( date_i18n( get_option( 'date_format' ), (int) $day['day'] ) ); ?></th><td><?php echo esc_html( number_format_i18n( $day['seen'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $day['started'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $day['submitted'] ) ); ?></td><td><?php echo esc_html( \SlimStat\Forms\Report::rate( $day ) ); ?></td></tr>
	<?php endforeach; ?>
	</tbody></table></div></details>
</section>
