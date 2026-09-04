<?php
if (!defined('ABSPATH')) {
	exit;
}

?>

<div class="backdrop-container">
    <div class="wrap-slimstat slimstat-migration">
        <?php wp_slimstat_admin::get_template('header', ['is_pro' => wp_slimstat::pro_is_installed()]); ?>
        <h2><?php echo esc_html__('SlimStat Database Migration', 'wp-slimstat'); ?></h2>

        <div class="meta-box-sortables">
            <div id="poststuff" style="width: 100%;">
                <div class="postbox full-width" id="slimstat_migration_status">
                    <h3><?php echo esc_html__('Migration Status', 'wp-slimstat'); ?></h3>
                    <div class="inside">
                    <?php
                    // Nothing is owed, but steps are offered: the two states the page used to
                    // render identically. Everything below reads this rather than re-testing.
                    //
                    // Not `&& !empty($offered_migrations)`. renderPage() redirects and exits when
                    // nothing is required AND nothing is offered, so reaching this include with
                    // no required migrations already guarantees the offered set is non-empty. The
                    // extra conjunct read as a second condition while being dead, which is two
                    // names for one boolean and the slower way to a third, unhandled state.
                    $offered_only = !$has_required_migrations;
                    ?>
                    <div class="slimstat-status-header" aria-live="polite">
                        <span class="slimstat-status-text" data-label-idle="<?php echo $offered_only
                            ? esc_attr__('No migration required', 'wp-slimstat')
                            : esc_attr__('Ready to start', 'wp-slimstat'); ?>" data-label-running="<?php echo esc_attr__('Migrating database…', 'wp-slimstat'); ?>" data-label-done="<?php echo esc_attr__('Migration complete', 'wp-slimstat'); ?>" data-label-failed="<?php echo esc_attr__('Migration failed', 'wp-slimstat'); ?>"><?php echo $offered_only
                            ? esc_html__('No migration required', 'wp-slimstat')
                            : esc_html__('Ready to start', 'wp-slimstat'); ?></span>
                        <span class="slimstat-status-badge slimstat-badge-idle"><?php echo esc_html__('Idle', 'wp-slimstat'); ?></span>
                    </div>

                    <?php // Present continuous under an "Idle" badge, on a page where nothing had started. ?>
                    <p class="slimstat-status-intro"><?php echo $offered_only
                        ? esc_html__('Your database is up to date. The optional steps below are available but not needed — each one says what it does and what it costs before you start it.', 'wp-slimstat')
                        : esc_html__('This will migrate your database to improve SlimStat performance and stability. Keep this page open until the process finishes. You can review each step below.', 'wp-slimstat'); ?></p>

                    <ul class="slimstat-status-metrics">
                        <li><span class="label"><?php echo esc_html__('Total steps', 'wp-slimstat'); ?></span><span class="value" id="slimstat-metrics-total">0</span></li>
                        <li><span class="label"><?php echo esc_html__('Completed', 'wp-slimstat'); ?></span><span class="value" id="slimstat-metrics-completed">0</span></li>
                        <li><span class="label"><?php echo esc_html__('Remaining', 'wp-slimstat'); ?></span><span class="value" id="slimstat-metrics-remaining">0</span></li>
                        <li><span class="label"><?php echo esc_html__('Elapsed', 'wp-slimstat'); ?></span><span class="value" id="slimstat-metrics-elapsed">00:00</span></li>
                    </ul>

                    <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                        <div class="bar"></div>
                        <div class="progress-label"><span id="slimstat-progress-percent">0%</span></div>
                    </div>

                    <?php
                    // `slimstat-migration-notice` is NOT decoration. migration.css hides every
                    // .notice on this screen to keep other plugins' clutter off it, exempting
                    // only that class — and this element never carried it, so the page hid its
                    // own status note. Worse, migration.js writes FAILURE text into this same
                    // element, so a failed step reported into something with display:none.
                    // Pinned by tests/migration-notice-visibility-test.php.
                    ?>
                    <div class="status-note notice inline notice-info slimstat-migration-notice" id="slimstat-status-note">
                        <?php if ($has_required_migrations): ?>
                            <?php echo esc_html__('Click "Start Migration" to begin. Progress and details will appear here.', 'wp-slimstat'); ?>
                        <?php else: ?>
                            <?php
                            printf(
                                esc_html(
                                    /* translators: %d: number of optional migration steps. */
                                    _n(
                                        'No migration is required. %d optional step is listed below — start it yourself when you are ready.',
                                        'No migration is required. %d optional steps are listed below — start each one yourself when you are ready.',
                                        count($offered_migrations),
                                        'wp-slimstat'
                                    )
                                ),
                                (int) count($offered_migrations)
                            );
                            ?>
                        <?php endif; ?>
                    </div>

                    <?php // Open when the steps inside it are the only way to act on this page. ?>
                    <details class="slimstat-details" style="margin:10px 0;"<?php echo $offered_only ? ' open' : ''; ?>>
                        <summary style="cursor:pointer;"><?php echo esc_html__('Migration Steps & Diagnostics', 'wp-slimstat'); ?></summary>
                        <ul id="slimstat-migration-list"></ul>
                    </details>

                    <div class="slimstat-actions" style="margin-top:12px;">
                        <?php if ($has_required_migrations): ?>
                            <button id="slimstat-start-migration" class="button button-primary">
                                <span class="label"><?php echo esc_html__('Start Migration', 'wp-slimstat'); ?></span>
                                <span class="spinner"></span>
                            </button>
                        <?php else: ?>
                            <a href="<?php echo esc_url(admin_url('index.php')); ?>" class="button button-primary">
                                <?php echo esc_html__('Back to Dashboard', 'wp-slimstat'); ?>
                            </a>
                        <?php endif; ?>
                        <a href="<?php echo esc_url(admin_url('index.php')); ?>" id="slimstat-back-dashboard" class="button" style="display:none;">
                            <?php echo esc_html__('Back to Dashboard', 'wp-slimstat'); ?>
                        </a>
                    </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
