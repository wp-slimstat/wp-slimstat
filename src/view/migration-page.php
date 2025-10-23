<?php
if (!defined('ABSPATH')) {
	exit;
}

// Load SlimStat header
wp_slimstat_admin::get_template('header', ['is_pro' => wp_slimstat::pro_is_installed()]);
?>

<div class="backdrop-container">
    <div class="wrap slimstat slimstat-migration">
        <h2><?php echo esc_html__('SlimStat Database Migration', 'wp-slimstat'); ?></h2>

        <div class="meta-box-sortables">
            <div id="poststuff" style="width: 100%;">
                <div class="postbox full-width" id="slimstat_migration_status">
                    <h3><?php echo esc_html__('Migration Status', 'wp-slimstat'); ?></h3>
                    <div class="inside">
                    <div class="slimstat-status-header" aria-live="polite">
                        <span class="slimstat-status-text" data-label-idle="<?php echo esc_attr__('Ready to start', 'wp-slimstat'); ?>" data-label-running="<?php echo esc_attr__('Migrating database…', 'wp-slimstat'); ?>" data-label-done="<?php echo esc_attr__('Migration complete', 'wp-slimstat'); ?>" data-label-failed="<?php echo esc_attr__('Migration failed', 'wp-slimstat'); ?>"><?php echo esc_html__('Ready to start', 'wp-slimstat'); ?></span>
                        <span class="slimstat-status-badge slimstat-badge-idle"><?php echo esc_html__('Idle', 'wp-slimstat'); ?></span>
                    </div>

                    <p class="slimstat-status-intro"><?php echo esc_html__('We are migrating your database to improve SlimStat performance and stability. Keep this page open until the process finishes. You can review each step below.', 'wp-slimstat'); ?></p>

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

                    <div class="status-note notice inline notice-info" id="slimstat-status-note">
                        <?php if ($has_required_migrations): ?>
                            <?php echo esc_html__('Click "Start Migration" to begin. Progress and details will appear here.', 'wp-slimstat'); ?>
                        <?php else: ?>
                            <?php echo esc_html__('No migrations are required. Your database is up to date.', 'wp-slimstat'); ?>
                        <?php endif; ?>
                    </div>

                    <details class="slimstat-details" style="margin:10px 0;">
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
