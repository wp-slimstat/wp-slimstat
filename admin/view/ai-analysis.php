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
				<?php $ai_tab_url = add_query_arg( array( 'page' => 'slimai', 'tab' => $key ), admin_url( 'admin.php' ) ); ?>
				<li class="nav-tab nav-tab-<?php echo $ai_tab === $key ? 'active' : 'inactive'; ?>">
					<a href="<?php echo esc_url( $ai_tab_url ); ?>" <?php if ( $ai_tab === $key ) : ?>aria-current="page"<?php endif; ?>><?php echo esc_html( $label ); ?></a>
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
				<p><?php esc_html_e( 'Copy AI Prompt turns a report into a ready-to-paste question with its data, dates and metric definitions. Use it as often as you like.', 'wp-slimstat' ); ?></p>
				<ol class="ss-ai-steps">
					<li><?php esc_html_e( 'Open a report and choose your date range and filters.', 'wp-slimstat' ); ?></li>
					<li><?php esc_html_e( 'Click Copy AI Prompt below the report title.', 'wp-slimstat' ); ?></li>
					<li><?php esc_html_e( 'Paste it into ChatGPT, Claude, Gemini, Grok, or your preferred AI service.', 'wp-slimstat' ); ?></li>
				</ol>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=slimview2' ) ); ?>"><?php esc_html_e( 'Open reports', 'wp-slimstat' ); ?></a>
			</div>
			<aside class="ss-ai-pro-intro" aria-labelledby="ss-ai-pro-heading">
				<p class="ss-ai-eyebrow"><?php esc_html_e( 'SlimStat Pro', 'wp-slimstat' ); ?></p>
				<h2 id="ss-ai-pro-heading"><?php esc_html_e( 'Get insights right here in WordPress', 'wp-slimstat' ); ?></h2>
				<p><?php esc_html_e( 'Connect your AI provider once, then use Analyze with AI on a report. Read the response here and return to your latest saved analyses anytime.', 'wp-slimstat' ); ?></p>
				<?php if ( $native ) : ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=slimai&tab=' . ( current_user_can( 'manage_options' ) ? 'settings' : 'saved' ) ) ); ?>"><?php echo esc_html( current_user_can( 'manage_options' ) ? __( 'AI Analysis settings', 'wp-slimstat' ) : __( 'View saved analyses', 'wp-slimstat' ) ); ?></a>
				<?php elseif ( current_user_can( 'manage_options' ) ) : ?>
					<a class="button" href="<?php echo esc_url( \SlimStat\Utils\ProLinks::url( 'ai-analysis', 'introduction', true ) ); ?>"><?php esc_html_e( 'Explore Pro AI →', 'wp-slimstat' ); ?></a>
					<p><?php esc_html_e( 'Already have Pro? Activate the Pro plugin and your license to get started.', 'wp-slimstat' ); ?> <a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>"><?php esc_html_e( 'Manage plugins', 'wp-slimstat' ); ?></a></p>
				<?php else : ?>
					<p><?php esc_html_e( 'Ask your site administrator to activate SlimStat Pro and connect an AI provider.', 'wp-slimstat' ); ?></p>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Requires WordPress 7.0 or newer and an active Pro license. Provider API charges may apply.', 'wp-slimstat' ); ?></p>
			</aside>
		</section>
		<p class="ss-ai-explainer"><?php esc_html_e( 'Review a copied prompt before sharing it. AI can make mistakes: check its suggestions against your reports before acting.', 'wp-slimstat' ); ?></p>
	<?php else : ?>
		<?php do_action( 'slimstat_ai_page', $ai_tab ); ?>
	<?php endif; ?>
	</div>
</div></div>
