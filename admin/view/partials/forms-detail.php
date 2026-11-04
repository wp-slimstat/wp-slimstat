<?php
/** A form's observed funnel and practical next checks. @license GPL-2.0-or-later */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Local variables in Report::render().
use SlimStat\Forms\Report;
use SlimStat\Forms\Insights;
use SlimStat\Tracker\Acquisition;
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
if ( ! Report::canView() || empty( $report['forms'] ) ) {
	return; }
$row     = $report['forms'][0];
$devices = array();
foreach ( $report['devices'] as $device ) {
	$devices[ (int) $device['device'] ] = $device; }
$mobile_gap = Insights::mobileGap( $devices[1] ?? array(), $devices[0] ?? array() );
$maximum    = max( 1, (int) $row['seen'], (int) $row['started'], (int) $row['submitted'] );
$unfinished = max( 0, (int) $row['started'] - (int) $row['completed_starts'] );
?>
<h2><?php esc_html_e( 'From seeing your form to completing it', 'wp-slimstat' ); ?></h2>
<dl class="ss-forms-funnel">
	<?php
	foreach ( array(
		'seen'      => __( 'Seen', 'wp-slimstat' ),
		'started'   => __( 'Started', 'wp-slimstat' ),
		'submitted' => __( 'Completed', 'wp-slimstat' ),
	) as $key => $label ) :
		?>
	<div><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( number_format_i18n( $row[ $key ] ) ); ?></dd><span class="ss-forms-bar ss-forms-stage-<?php echo esc_attr( $key ); ?>" aria-hidden="true"><span style="width:<?php echo esc_attr( (string) round( 100 * (int) $row[ $key ] / $maximum, 2 ) ); ?>%"></span></span></div><?php endforeach; ?>
	<div class="ss-forms-result"><dt><?php esc_html_e( 'Completion rate', 'wp-slimstat' ); ?></dt><dd><?php echo esc_html( Report::rate( $row ) ); ?></dd></div>
</dl>
<p><?php esc_html_e( 'Counts represent tracked visits, not individual entries or people. A visit can complete the form even when its earlier steps could not be observed.', 'wp-slimstat' ); ?></p>
<?php
if ( (int) $row['submitted'] > (int) $row['converted'] ) :
	?>
	<p><?php /* translators: %s: Number of confirmed completions with no recorded view. */ echo esc_html( sprintf( __( '%s completions had no recorded view. They appear in Completed, but are excluded from the completion rate.', 'wp-slimstat' ), number_format_i18n( $row['submitted'] - $row['converted'] ) ) ); ?></p><?php endif; ?>
<?php if ( $mobile_gap ) : ?>
	<section class="ss-forms-state" aria-labelledby="ss-forms-opportunity"><h2 id="ss-forms-opportunity"><?php esc_html_e( 'Check the mobile form experience', 'wp-slimstat' ); ?></h2><p><?php /* translators: 1: Mobile completion rate, 2: desktop completion rate. */ echo esc_html( sprintf( __( 'Mobile visitors completed this form at %1$s, compared with %2$s on desktop. The difference is large enough to investigate.', 'wp-slimstat' ), Report::rate( $devices[1] ), Report::rate( $devices[0] ) ) ); ?></p><p><?php esc_html_e( 'Try the form on a phone. Check whether instructions, error messages, and the submit button are easy to use. Consider simplifying optional fields, then compare results after the change.', 'wp-slimstat' ); ?></p><p class="description"><?php esc_html_e( 'This comparison does not identify the cause. Mobile visitors may have different needs or traffic sources.', 'wp-slimstat' ); ?></p></section>
<?php elseif ( (int) $row['seen'] < 100 ) : ?>
	<section class="ss-forms-next" aria-labelledby="ss-forms-next-heading"><span class="dashicons dashicons-lightbulb" aria-hidden="true"></span><div><h2 id="ss-forms-next-heading"><?php esc_html_e( 'Your next check: try the whole journey', 'wp-slimstat' ); ?></h2><p><?php esc_html_e( 'Open the form on your phone, fill it in, and check the confirmation. Make sure visitors can tell what happens next and when you will respond.', 'wp-slimstat' ); ?></p><p class="description"><?php esc_html_e( 'This is a setup check, not a finding about your visitors. Data-based recommendations appear when enough activity has been recorded.', 'wp-slimstat' ); ?></p></div></section>
<?php endif; ?>
<?php require __DIR__ . '/forms-trend.php'; ?>
<div class="ss-forms-split">
	<section aria-labelledby="ss-forms-progress"><h2 id="ss-forms-progress"><?php esc_html_e( 'What may be getting in the way?', 'wp-slimstat' ); ?></h2><dl>
		<dt><?php esc_html_e( 'Started without completion', 'wp-slimstat' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $unfinished ) ); ?></dd>
		<dt><?php esc_html_e( 'Visits with validation errors', 'wp-slimstat' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $row['invalid'] ) ); ?></dd>
		<dt><?php esc_html_e( 'Visits marked as spam', 'wp-slimstat' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $row['spam'] ) ); ?></dd>
		<dt><?php esc_html_e( 'Visits with processing failures', 'wp-slimstat' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $row['failed'] ) ); ?></dd>
	</dl><p class="description"><?php esc_html_e( 'Unfinished starts may include visits still in progress. Errors and spam appear only when the integration can verify them. One visit may encounter an error and later complete the form.', 'wp-slimstat' ); ?></p></section>
	<section aria-labelledby="ss-forms-time"><h2 id="ss-forms-time"><?php esc_html_e( 'Time to complete', 'wp-slimstat' ); ?></h2>
		<?php
		if ( (int) $row['timed'] >= 10 ) :
			?>
			<p><?php /* translators: %s: Average elapsed time in seconds. */ echo esc_html( sprintf( __( '%s seconds on average', 'wp-slimstat' ), number_format_i18n( $row['duration'] / $row['timed'] ) ) ); ?></p><p><?php /* translators: %s: Number of completions with both timestamps observed. */ echo esc_html( sprintf( __( 'Based on %s completions with both a recorded start and confirmation. This is elapsed time and may include time spent away from the form.', 'wp-slimstat' ), number_format_i18n( $row['timed'] ) ) ); ?></p>
			<?php
		else :
			?>
			<p><?php esc_html_e( 'An average appears after 10 completions with a recorded start and confirmation.', 'wp-slimstat' ); ?></p><?php endif; ?>
	</section>
</div>
<div class="ss-forms-split">
	<section aria-labelledby="ss-forms-devices"><h2 id="ss-forms-devices"><?php esc_html_e( 'Is the form working on every device?', 'wp-slimstat' ); ?></h2><div class="ss-forms-table"><table class="widefat striped"><thead><tr><th scope="col"><?php esc_html_e( 'Device', 'wp-slimstat' ); ?></th><th scope="col"><?php esc_html_e( 'Seen', 'wp-slimstat' ); ?></th><th scope="col"><?php esc_html_e( 'Completion rate', 'wp-slimstat' ); ?></th></tr></thead><tbody>
	<?php
	foreach ( $report['devices'] as $device ) :
		?>
		<tr><th scope="row"><?php echo esc_html( 1 === (int) $device['device'] ? __( 'Mobile', 'wp-slimstat' ) : __( 'Desktop', 'wp-slimstat' ) ); ?></th><td><?php echo esc_html( number_format_i18n( $device['seen'] ) ); ?></td><td><strong><?php echo esc_html( Report::rate( $device ) ); ?></strong><span class="ss-forms-bar" aria-hidden="true"><span style="width:<?php echo esc_attr( (string) ( \SlimStat\Forms\Metrics::rate( (int) $device['converted'], (int) $device['seen'] ) ?? 0 ) ); ?>%"></span></span></td></tr><?php endforeach; ?></tbody></table></div></section>
	<section aria-labelledby="ss-forms-sources"><h2 id="ss-forms-sources"><?php esc_html_e( 'Which visitors are completing the form?', 'wp-slimstat' ); ?></h2><p><?php esc_html_e( 'Where the tracked visit came from when this form was first recorded.', 'wp-slimstat' ); ?></p><div class="ss-forms-table"><table class="widefat striped"><thead><tr><th scope="col"><?php esc_html_e( 'Channel', 'wp-slimstat' ); ?></th><th scope="col"><?php esc_html_e( 'Seen', 'wp-slimstat' ); ?></th><th scope="col"><?php esc_html_e( 'Completion rate', 'wp-slimstat' ); ?></th></tr></thead><tbody>
	<?php
	foreach ( $report['sources'] as $source ) :
		?>
		<tr><th scope="row"><?php echo esc_html( Acquisition::labels()[ $source['channel'] ] ?? __( 'Other', 'wp-slimstat' ) ); ?></th><td><?php echo esc_html( number_format_i18n( $source['seen'] ) ); ?></td><td><strong><?php echo esc_html( Report::rate( $source ) ); ?></strong><span class="ss-forms-bar" aria-hidden="true"><span style="width:<?php echo esc_attr( (string) ( \SlimStat\Forms\Metrics::rate( (int) $source['converted'], (int) $source['seen'] ) ?? 0 ) ); ?>%"></span></span></td></tr><?php endforeach; ?></tbody></table></div></section>
</div>
<section class="ss-forms-pro" aria-labelledby="ss-forms-pro-heading"><h2 id="ss-forms-pro-heading"><?php esc_html_e( 'Find the next improvement', 'wp-slimstat' ); ?></h2>
<?php if ( Report::advanced() ) : ?>
	<?php do_action( 'slimstat_forms_pro_report', $report ); ?>
<?php else : ?>
	<p><?php esc_html_e( 'Explore field friction, visitor journeys, and the purchases that follow a form completion with SlimStat Pro.', 'wp-slimstat' ); ?></p>
	<?php
	wp_slimstat_admin::get_template(
		'locked-preview',
		array(
			'text'     => __( 'Discover what helps visitors complete your forms', 'wp-slimstat' ),
			'campaign' => 'form-analytics',
			'content'  => 'form-detail',
		)
	);
	?>
<?php endif; ?>
</section>
