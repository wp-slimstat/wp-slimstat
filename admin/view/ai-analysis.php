<?php
/**
 * Report analysis introduction and private Pro library shell.
 *
 * @package SlimStat
 * @license GPL-2.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Local variables in wp_slimstat_include_ai().
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
if ( ! wp_slimstat_admin::can_view_stats() ) {
	return; }

$native           = (bool) has_action( 'slimstat_ai_page' );
$ai_tabs          = $native ? array( 'saved' => __( 'Saved analyses', 'wp-slimstat' ) ) : array();
$ai_tabs['guide'] = __( 'How it works', 'wp-slimstat' );
if ( $native && current_user_can( 'manage_options' ) ) {
	$ai_tabs['settings'] = __( 'Settings', 'wp-slimstat' );
}
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only allowlisted navigation.
$ai_tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ( $native ? 'saved' : 'guide' );
if ( ! isset( $ai_tabs[ $ai_tab ] ) ) {
	$ai_tab = $native ? 'saved' : 'guide'; }
?>
<div class="backdrop-container"><div class="wrap-slimstat slimstat-config ss-ai-page">
	<?php
	wp_slimstat_admin::get_template(
		'header',
		array(
			'is_pro' => wp_slimstat::pro_is_installed(),
			'title'  => __( 'AI Analysis', 'wp-slimstat' ),
			'lead'   => __( 'Understand your reports, choose what to do next.', 'wp-slimstat' ),
		)
	);
	?>
	<?php if ( $native ) : ?>
	<nav aria-label="<?php esc_attr_e( 'AI Analysis sections', 'wp-slimstat' ); ?>">
		<ul class="nav-tabs">
			<?php foreach ( $ai_tabs as $key => $label ) : ?>
				<li class="nav-tab nav-tab-<?php echo $ai_tab === $key ? 'active' : 'inactive'; ?>">
					<a href="<?php echo esc_url( add_query_arg( 'tab', $key, admin_url( 'admin.php?page=slimai' ) ) ); ?>" aria-current="<?php echo $ai_tab === $key ? 'page' : 'false'; ?>"><?php echo esc_html( $label ); ?></a>
				</li>
			<?php endforeach; ?>
		</ul>
	</nav>
	<?php endif; ?>
	<div class="ss-ai-page-content">
	<?php if ( 'guide' === $ai_tab ) : ?>
		<section class="ss-ai-intro" aria-labelledby="ss-ai-copy-heading">
			<div>
				<p class="ss-ai-eyebrow"><?php esc_html_e( 'Included in Free', 'wp-slimstat' ); ?></p>
				<h2 id="ss-ai-copy-heading"><?php esc_html_e( 'Your report, ready for a second opinion', 'wp-slimstat' ); ?></h2>
				<p><?php esc_html_e( 'Copy Prompt turns a report into a ready-to-paste question with its data, dates and metric definitions. Use it as often as you like.', 'wp-slimstat' ); ?></p>
				<ol class="ss-ai-steps">
					<li><?php esc_html_e( 'Open a report and choose your date range and filters.', 'wp-slimstat' ); ?></li>
					<li><?php esc_html_e( 'Click Copy Prompt below the report title.', 'wp-slimstat' ); ?></li>
					<li><?php esc_html_e( 'Paste it into ChatGPT, Claude, Gemini, Grok, or your preferred AI service.', 'wp-slimstat' ); ?></li>
				</ol>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=slimview2' ) ); ?>"><?php esc_html_e( 'Open reports', 'wp-slimstat' ); ?></a>
			</div>
			<aside class="ss-ai-pro-intro" aria-labelledby="ss-ai-pro-heading">
				<p class="ss-ai-eyebrow ss-ai-pro-eyebrow"><span class="slimstat-ai-spark" aria-hidden="true">✨</span><?php esc_html_e( 'SlimStat Pro', 'wp-slimstat' ); ?></p>
				<h2 id="ss-ai-pro-heading"><?php esc_html_e( 'Get insights right here in WordPress', 'wp-slimstat' ); ?></h2>
				<p class="ss-ai-pro-lead"><?php esc_html_e( 'Skip the copy and paste: analyze a report on the screen where you read it.', 'wp-slimstat' ); ?></p>
				<ul class="ss-ai-benefits">
					<li><strong><?php esc_html_e( 'Analyze in place', 'wp-slimstat' ); ?></strong> <?php esc_html_e( 'Click AI Analysis on a report and read the response beside your data.', 'wp-slimstat' ); ?></li>
					<li><strong><?php esc_html_e( 'Come back anytime', 'wp-slimstat' ); ?></strong> <?php esc_html_e( 'Your latest analysis of each report is kept under Saved analyses.', 'wp-slimstat' ); ?></li>
					<li><strong><?php esc_html_e( 'Use your own AI provider', 'wp-slimstat' ); ?></strong> <?php esc_html_e( 'Connect the service you choose once, in WordPress.', 'wp-slimstat' ); ?></li>
				</ul>
				<?php if ( $native ) : ?>
					<p class="ss-ai-pro-actions"><a class="button button-primary ss-ai-pro-cta" href="<?php echo esc_url( admin_url( 'admin.php?page=slimai&tab=' . ( current_user_can( 'manage_options' ) ? 'settings' : 'saved' ) ) ); ?>"><?php echo esc_html( current_user_can( 'manage_options' ) ? __( 'AI Analysis settings', 'wp-slimstat' ) : __( 'View saved analyses', 'wp-slimstat' ) ); ?></a></p>
				<?php elseif ( current_user_can( 'manage_options' ) ) : ?>
					<p class="ss-ai-pro-actions">
						<a class="button button-primary ss-ai-pro-cta" href="<?php echo esc_url( \SlimStat\Utils\ProLinks::tag( 'https://wp-slimstat.com/pro-features/', 'ai-analysis', 'slimai-pro-panel' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Explore Pro features', 'wp-slimstat' ); ?><span class="ss-ai-pro-arrow" aria-hidden="true">→</span><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'wp-slimstat' ); ?></span></a>
					</p>
					<?php
					// Existing customers: the license banner's own first step (activate or fix the license), else the Plugins screen.
					$ai_owned = \SlimStat\Services\ProLicense::action();
					?>
					<p class="ss-ai-pro-owned"><?php esc_html_e( 'Already have Pro?', 'wp-slimstat' ); ?>
						<?php if ( $ai_owned ) : ?>
							<a href="<?php echo esc_url( $ai_owned[1] ); ?>"><?php echo esc_html( $ai_owned[0] ); ?></a>
						<?php else : ?>
							<?php esc_html_e( 'Activate the Pro plugin and your license to get started.', 'wp-slimstat' ); ?> <a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>"><?php esc_html_e( 'Manage plugins', 'wp-slimstat' ); ?></a>
						<?php endif; ?>
					</p>
				<?php else : ?>
					<p><?php esc_html_e( 'Ask your site administrator to activate SlimStat Pro and connect an AI provider.', 'wp-slimstat' ); ?></p>
				<?php endif; ?>
				<h3 class="ss-ai-pro-needs-title"><?php esc_html_e( 'Requirements', 'wp-slimstat' ); ?></h3>
				<ul class="ss-ai-pro-needs">
					<li><?php esc_html_e( 'An active SlimStat Pro license', 'wp-slimstat' ); ?></li>
					<li><?php esc_html_e( 'WordPress 7.0 or newer, with its AI features enabled', 'wp-slimstat' ); ?></li>
					<li><?php esc_html_e( 'An AI provider connected in WordPress (provider API charges may apply)', 'wp-slimstat' ); ?></li>
				</ul>
			</aside>
		</section>
		<p class="ss-ai-explainer"><?php esc_html_e( 'Review a copied prompt before sharing it. AI can make mistakes: check its suggestions against your reports before acting.', 'wp-slimstat' ); ?></p>
	<?php else : ?>
		<?php do_action( 'slimstat_ai_page', $ai_tab ); ?>
	<?php endif; ?>
	</div>
</div></div>
