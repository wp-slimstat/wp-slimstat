<?php
/** Form Analytics: automatic setup and an evidence-first report. @license GPL-2.0-or-later */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Local variables in Report::render().
use SlimStat\Forms\Report;
use SlimStat\Forms\Store;
use SlimStat\Forms\Registry;
use SlimStat\Forms\Insights;
use SlimStat\Tracker\Acquisition;
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
if ( ! Report::canView() ) {
	return; }
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only allowlisted report navigation.
$saved = isset( $_GET['saved'] ) && '1' === $_GET['saved'];
// phpcs:enable WordPress.Security.NonceVerification.Recommended
$base            = admin_url( 'admin.php?page=slimforms' );
$active          = Registry::active();
$ready           = Store::ready();
$enabled         = Store::enabled();
$tracking        = 'on' === ( wp_slimstat::$settings['is_tracking'] ?? 'on' );
$report          = null;
$failed          = false;
$selection_error = '';
$form            = '';
$days            = 0;
$tab             = isset( $tab ) && 'setup' === $tab ? 'setup' : 'overview';
if ( 'overview' === $tab && $ready ) {
	try {
		$report = Report::load();
		$form   = $report['form'];
		$days   = $report['days'];
		Report::primeTitles( array_column( $report['forms'], 'form' ) );
	} catch ( InvalidArgumentException $error ) {
		$selection_error = $error->getMessage();
	} catch ( Throwable $error ) {
		$failed = true; }
}
?>
<?php if ( 'setup' === $tab ) : ?>
<div class="backdrop-container"><div class="wrap-slimstat slimstat-config ss-forms">
	<?php
	wp_slimstat_admin::get_template(
		'header',
		array(
			'is_pro' => wp_slimstat::pro_is_installed(),
			'title'  => __( 'Form Analytics', 'wp-slimstat' ),
			'lead'   => __( 'See where visitors stop and help more of them complete your forms.', 'wp-slimstat' ),
		)
	);
	?>
	<?php
else :
	?>
	<div class="ss-forms"><?php endif; ?>
	<nav aria-label="<?php esc_attr_e( 'Form Analytics sections', 'wp-slimstat' ); ?>">
		<ul class="nav-tabs">
			<?php
			foreach ( array(
				'overview' => __( 'Reports', 'wp-slimstat' ),
				'setup'    => __( 'Setup & privacy', 'wp-slimstat' ),
			) as $key => $label ) :
				?>
			<li class="nav-tab nav-tab-<?php echo $tab === $key ? 'active' : 'inactive'; ?>"><a href="<?php echo esc_url( add_query_arg( 'tab', $key, $base ) ); ?>" aria-current="<?php echo $tab === $key ? 'page' : 'false'; ?>"><?php echo esc_html( $label ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	</nav>
	<div class="ss-forms-content">
	<?php
	if ( $saved ) :
		?>
		<p role="status"><?php echo esc_html( $enabled ? __( 'Setting saved. New form activity allowed by your privacy settings will be collected when SlimStat tracking is on.', 'wp-slimstat' ) : __( 'Setting saved. Form activity collection is paused.', 'wp-slimstat' ) ); ?></p><?php endif; ?>
	<?php if ( ! empty( $report['retention_limited'] ) ) : ?>
		<p class="description"><?php esc_html_e( 'Part of this period is outside your analytics retention limit and is excluded from these figures.', 'wp-slimstat' ); ?></p>
	<?php endif; ?>
	<?php if ( ! empty( $report['retained_only'] ) ) : ?>
		<p class="description"><?php esc_html_e( 'This segment uses retained form visits. Privacy erasures and retention limits can make it smaller than the unfiltered daily totals.', 'wp-slimstat' ); ?></p>
	<?php endif; ?>
	<?php if ( ! $enabled || ! $tracking ) : ?>
		<div class="ss-forms-state" role="status"><strong><?php esc_html_e( 'New form activity is paused', 'wp-slimstat' ); ?></strong><p><?php esc_html_e( 'Your saved reports are still available. Resume tracking when you are ready to collect new activity.', 'wp-slimstat' ); ?> <a href="<?php echo esc_url( $tracking ? add_query_arg( 'tab', 'setup', $base ) : admin_url( 'admin.php?page=slimconfig' ) ); ?>"><?php esc_html_e( 'Review tracking settings', 'wp-slimstat' ); ?></a></p></div>
	<?php endif; ?>
	<?php if ( 'setup' === $tab ) : ?>
		<section aria-labelledby="ss-forms-setup">
			<h2 id="ss-forms-setup"><?php echo esc_html( $ready && $enabled && $tracking ? __( 'Ready to collect form activity', 'wp-slimstat' ) : __( 'Set up form tracking', 'wp-slimstat' ) ); ?></h2>
			<p><?php esc_html_e( 'Supported forms connect automatically. You do not need API keys, tracking code, or field mapping.', 'wp-slimstat' ); ?></p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="ss-forms-setting">
				<input type="hidden" name="action" value="slimstat_forms_toggle" /><?php wp_nonce_field( 'slimstat_forms_toggle' ); ?>
				<label><input type="checkbox" name="enabled" value="on" <?php checked( $enabled ); ?> /> <?php esc_html_e( 'Collect form activity', 'wp-slimstat' ); ?></label>
				<p class="description"><?php esc_html_e( 'Turning this off pauses new activity. It does not delete saved reports or change how your forms work.', 'wp-slimstat' ); ?></p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save setting', 'wp-slimstat' ); ?></button>
			</form>
		</section>
		<section aria-labelledby="ss-forms-integrations"><h2 id="ss-forms-integrations"><?php esc_html_e( 'Available integrations', 'wp-slimstat' ); ?></h2>
			<p><?php esc_html_e( 'All supported integrations are included in Free. A form appears in Reports after SlimStat records its first tracked visit.', 'wp-slimstat' ); ?></p>
			<?php
			if ( $active ) :
				?>
				<ul class="ss-forms-integrations">
				<?php
				foreach ( $active as $key => $unused ) :
					?>
				<li><strong><?php echo esc_html( Registry::labels()[ $key ] ); ?></strong> <span><?php esc_html_e( 'Plugin detected', 'wp-slimstat' ); ?></span></li><?php endforeach; ?></ul>
				<?php
			else :
				?>
				<p><?php esc_html_e( 'No supported form plugin is active. Activate a supported form plugin, publish a form, then return here.', 'wp-slimstat' ); ?></p><a class="button" href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>"><?php esc_html_e( 'Manage form plugins', 'wp-slimstat' ); ?></a><?php endif; ?>
			<details><summary><?php esc_html_e( 'Which plugins can connect?', 'wp-slimstat' ); ?></summary><p><?php echo esc_html( implode( ', ', Registry::labels() ) ); ?></p><p><?php esc_html_e( 'An available integration is not proof of a successful submission. SlimStat records completion only when the form plugin confirms it.', 'wp-slimstat' ); ?></p></details>
		</section>
		<section aria-labelledby="ss-forms-privacy"><h2 id="ss-forms-privacy"><?php esc_html_e( 'Your existing privacy settings apply', 'wp-slimstat' ); ?></h2>
			<p><?php esc_html_e( 'SlimStat records form activity, never the answers people enter. It follows your consent requirements, tracking exclusions, and data retention settings. Activity without the required consent is not included.', 'wp-slimstat' ); ?></p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=slimconfig' ) ); ?>"><?php esc_html_e( 'Review SlimStat privacy and retention settings', 'wp-slimstat' ); ?></a>
		</section>
		<details><summary><?php esc_html_e( 'Why is my form missing?', 'wp-slimstat' ); ?></summary><ol><li><?php esc_html_e( 'Confirm that your form plugin is listed above and the form is published.', 'wp-slimstat' ); ?></li><li><?php esc_html_e( 'Open the form page in a private browser window. Give analytics consent if your site asks for it.', 'wp-slimstat' ); ?></li><li><?php esc_html_e( 'Bring the form into view, then refresh this report after about 30 seconds. Visits excluded by your SlimStat settings will not appear.', 'wp-slimstat' ); ?></li><li><?php esc_html_e( 'If you recently enabled tracking, clear your page cache so visitors receive the updated form.', 'wp-slimstat' ); ?></li></ol></details>
	<?php endif; ?>
	<?php if ( $active && ! $ready && $enabled ) : ?>
		<section class="ss-forms-state" aria-labelledby="ss-forms-retry"><h2 id="ss-forms-retry"><?php esc_html_e( 'Form tracking is not ready yet', 'wp-slimstat' ); ?></h2><p><?php esc_html_e( 'SlimStat could not prepare storage for form activity. Your forms still work. Retry setup; if this continues, ask your host to check database permissions and SlimStat migration settings.', 'wp-slimstat' ); ?></p><form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><input type="hidden" name="action" value="slimstat_forms_retry" /><?php wp_nonce_field( 'slimstat_forms_retry' ); ?><button class="button" type="submit"><?php esc_html_e( 'Retry form tracking setup', 'wp-slimstat' ); ?></button></form></section>
	<?php elseif ( 'overview' === $tab ) : ?>
		<?php
		if ( '' !== $form ) :
			?>
			<a href="<?php echo esc_url( Report::url() ); ?>"><?php esc_html_e( '← All forms', 'wp-slimstat' ); ?></a><h2><?php echo esc_html( Report::name( $form ) ); ?></h2><?php endif; ?>
		<?php if ( $selection_error ) : ?>
			<div class="ss-forms-state" role="alert"><h2><?php esc_html_e( 'Adjust your report selection', 'wp-slimstat' ); ?></h2><p><?php echo esc_html( $selection_error ); ?></p></div>
		<?php elseif ( $failed ) : ?>
			<div class="ss-forms-state" role="alert"><h2><?php esc_html_e( 'This report could not load', 'wp-slimstat' ); ?></h2><p><?php esc_html_e( 'The database did not return form activity. Refresh this page. If the problem continues, ask your host to check the analytics database connection.', 'wp-slimstat' ); ?></p></div>
		<?php elseif ( empty( $report['forms'] ) ) : ?>
			<section class="ss-forms-empty"><h2><?php esc_html_e( 'Your next form visit starts the story', 'wp-slimstat' ); ?></h2><p><?php esc_html_e( 'No form activity is available for this period. Once visitors see a supported form, you can follow their progress here.', 'wp-slimstat' ); ?></p><a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'tab', 'setup', $base ) ); ?>"><?php esc_html_e( 'Check setup and privacy', 'wp-slimstat' ); ?></a></section>
		<?php elseif ( '' === $form ) : ?>
			<section class="ss-forms-overview" aria-labelledby="ss-forms-overview-heading">
				<div class="ss-forms-section-heading"><div><h2 id="ss-forms-overview-heading"><?php esc_html_e( 'Which forms get completed?', 'wp-slimstat' ); ?></h2><p><?php esc_html_e( 'Choose a form to see where visitors stop and what to check next.', 'wp-slimstat' ); ?></p></div><span class="ss-forms-tag"><?php esc_html_e( 'Confirmed by your form plugins', 'wp-slimstat' ); ?></span></div>
				<ol class="ss-forms-ranking">
				<?php $rank_maximum = max( 1, (int) $report['forms'][0]['submitted'] ); ?>
				<?php foreach ( array_slice( $report['forms'], 0, 5 ) as $rank ) : ?>
					<li><a href="<?php echo esc_url( Report::url( $rank['form'] ) ); ?>"><span><?php echo esc_html( Report::name( $rank['form'] ) ); ?></span><strong><?php /* translators: %s: Number of visits that completed a form. */ echo esc_html( sprintf( __( '%s completed', 'wp-slimstat' ), number_format_i18n( $rank['submitted'] ) ) ); ?></strong><span class="ss-forms-bar" aria-hidden="true"><span style="width:<?php echo esc_attr( (string) round( 100 * (int) $rank['submitted'] / $rank_maximum, 2 ) ); ?>%"></span></span></a></li>
				<?php endforeach; ?>
				</ol>
			</section>
			<h2><?php esc_html_e( 'All recorded forms', 'wp-slimstat' ); ?></h2>
			<p id="ss-forms-counts"><?php esc_html_e( 'Each form is counted once per tracked visit. Completed means the form plugin confirmed success; it does not mean a qualified lead or a sale.', 'wp-slimstat' ); ?></p>
			<div class="ss-forms-table"><table class="widefat striped" aria-describedby="ss-forms-counts"><thead><tr><th scope="col"><?php esc_html_e( 'Form', 'wp-slimstat' ); ?></th><th scope="col"><?php esc_html_e( 'Seen', 'wp-slimstat' ); ?></th><th scope="col"><?php esc_html_e( 'Started', 'wp-slimstat' ); ?></th><th scope="col"><?php esc_html_e( 'Completed', 'wp-slimstat' ); ?></th><th scope="col"><?php esc_html_e( 'Completion rate', 'wp-slimstat' ); ?></th></tr></thead><tbody>
			<?php
			foreach ( array_slice( $report['forms'], 0, 100 ) as $row ) :
				?>
				<tr><th scope="row"><a href="
				<?php
				echo esc_url(
					Report::url( $row['form'] )
				);
				?>
				"><?php echo esc_html( Report::name( $row['form'] ) ); ?></a></th><td><?php echo esc_html( number_format_i18n( $row['seen'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $row['started'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $row['submitted'] ) ); ?></td><td><?php echo esc_html( Report::rate( $row ) ); ?></td></tr><?php endforeach; ?>
			</tbody></table></div>
			<?php
			if ( count( $report['forms'] ) > 100 ) :
				?>
				<p><?php esc_html_e( 'Showing the 100 most active forms in this period.', 'wp-slimstat' ); ?></p><?php endif; ?>
			<?php
		else :
			require __DIR__ . '/partials/forms-detail.php';
endif;
		?>
		<details class="ss-forms-definitions"><summary><?php esc_html_e( 'How to read these numbers', 'wp-slimstat' ); ?></summary><dl><dt><?php esc_html_e( 'Seen', 'wp-slimstat' ); ?></dt><dd><?php esc_html_e( 'Tracked visits in which the form came into view. Loading the page alone does not count.', 'wp-slimstat' ); ?></dd><dt><?php esc_html_e( 'Started', 'wp-slimstat' ); ?></dt><dd><?php esc_html_e( 'Tracked visits in which someone focused or changed a form field. Password and payment fields are excluded.', 'wp-slimstat' ); ?></dd><dt><?php esc_html_e( 'Completed', 'wp-slimstat' ); ?></dt><dd><?php esc_html_e( 'Tracked visits with a successful submission confirmed by the form plugin. Repeated submissions during the same visit count once.', 'wp-slimstat' ); ?></dd><dt><?php esc_html_e( 'Completion rate', 'wp-slimstat' ); ?></dt><dd><?php esc_html_e( 'Of the visits that saw the form, the percentage that also completed it. Confirmations with no observed view are excluded from this percentage.', 'wp-slimstat' ); ?></dd></dl><p><?php esc_html_e( 'Consent choices, blockers, missing visit context, and unsupported submission paths can reduce coverage. Counts can differ from entries saved by your form plugin. Recent activity can take about 30 seconds to appear.', 'wp-slimstat' ); ?></p></details>
	<?php endif; ?>
	</div>
</div>
<?php
if ( 'setup' === $tab ) :
	?>
	</div><?php endif; ?>
