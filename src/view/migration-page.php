<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included by a report/admin rendering method; these are local template variables, not plugin globals.
if (!defined('ABSPATH')) {
	exit;
}

// All three are set by MigrationAdmin::renderPage(), this file's only includer — but a template that
// READS variables it does not declare is one PHPStan cannot reason about, and
// `$has_required_migrations might not be defined` had been parked in phpstan-baseline.neon for
// exactly that reason.
//
// Adding $offered_migrations did not add a baseline entry. It added TWO UNBAISELINED errors and
// turned the PHPStan lane red — measured at the previous commit: three variable.undefined errors
// in this file, one of them baselined and two not. So this is a build fix, not a tidy-up, and
// the baseline's own header is why it is not fixed by baselining: never baseline a newly
// introduced error.
//
// Declaring the contract removes all three, so the pre-existing entry is retired and the ceiling
// descends with it — which is the ratchet's stated purpose rather than a side effect.
//
// is_array() rather than ?? : it narrows the type to array, so this survives a level bump; ??
// alone leaves it `mixed`, which passes level 5 only because checkExplicitMixed is off.
$has_required_migrations = isset($has_required_migrations) ? $has_required_migrations : false;
$offered_migrations      = (isset($offered_migrations) && is_array($offered_migrations)) ? $offered_migrations : [];
$analytics_myisam        = !empty($analytics_myisam);
$column_drift = isset($column_drift) && is_array($column_drift) ? $column_drift : null;

?>

<div class="backdrop-container">
    <div class="wrap-slimstat slimstat-migration">
        <?php wp_slimstat_admin::get_template('header', ['is_pro' => wp_slimstat::pro_is_installed(), 'title' => __('Database Migration', 'wp-slimstat')]); ?>

        <div class="meta-box-sortables">
            <div id="poststuff" style="width: 100%;">
                <div class="postbox full-width" id="slimstat_migration_status">
                    <h3><?php echo esc_html__('Migration Status', 'wp-slimstat'); ?></h3>
                    <div class="inside">
                    <?php
                    $offered_only = !$has_required_migrations;
                    // Offered-only: the status note below is the one sentence. The status line,
                    // intro, counters and progress bar describe a run that cannot start here
                    // (optional steps report into their own row), so they are not drawn (audit B3).
                    if (!$offered_only) :
                    ?>
                    <div class="slimstat-status-header" aria-live="polite">
                        <span class="slimstat-status-text" data-label-idle="<?php echo esc_attr__('Ready to start', 'wp-slimstat'); ?>" data-label-running="<?php echo esc_attr__('Migrating database…', 'wp-slimstat'); ?>" data-label-done="<?php echo esc_attr__('Required migrations are complete. Reload this page to verify column repairs.', 'wp-slimstat'); ?>" data-label-failed="<?php echo esc_attr__('Migration failed', 'wp-slimstat'); ?>"><?php echo esc_html__('Ready to start', 'wp-slimstat'); ?></span>
                        <span class="slimstat-status-badge slimstat-badge-idle"><?php echo esc_html__('Idle', 'wp-slimstat'); ?></span>
                    </div>

                    <?php // Present continuous under an "Idle" badge, on a page where nothing had started. ?>
                    <p class="slimstat-status-intro"><?php echo esc_html__('SlimStat requires a database update. Back up your database first, then select Start Migration. Keep this page open until it finishes. A time estimate is unavailable: duration depends on affected rows and database performance.', 'wp-slimstat'); ?><?php if ($analytics_myisam) { echo ' ' . esc_html__('A SlimStat analytics table uses MyISAM. Each step on it locks it for writes while it runs, so tracking pauses during the migration.', 'wp-slimstat'); } ?></p>

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
                    <?php endif; ?>

                    <?php
                    // `slimstat-migration-notice` is NOT decoration. migration.css hides every
                    // .notice on this screen to keep other plugins' clutter off it, exempting
                    // only that class — and this element never carried it, so the page hid its
                    // own status note. Worse, migration.js writes FAILURE text into this same
                    // element, so a failed step reported into something with display:none.
                    // Pinned by tests/migration-notice-visibility-test.php.
                    ?>
                    <div id="slimstat-status-note" class="status-note notice inline slimstat-migration-notice <?php echo $has_required_migrations ? 'notice-info' : ([] === $column_drift ? 'notice-success' : 'notice-warning'); ?>">
                        <?php if ($has_required_migrations): ?>
                            <?php echo esc_html__('Click "Start Migration" to begin. Progress and details will appear here.', 'wp-slimstat'); ?>
                        <?php elseif (null === $column_drift): ?>
                            <?php echo esc_html__('SlimStat could not verify the database columns. Check the database connection and permissions, then reload this page. Existing warnings are retained.', 'wp-slimstat'); ?>
                        <?php elseif ([] !== $column_drift): ?>
                            <?php echo esc_html__('Database columns still differ from this version. Review the details and the optional repairs below. If no matching repair is offered, ask your database administrator or SlimStat support to review the definitions before changing them.', 'wp-slimstat'); ?>
                        <?php else: ?>
                            <?php echo esc_html__('✓ Database is up to date', 'wp-slimstat'); ?>
                        <?php endif; ?>
                    </div>

                    <?php if (is_array($column_drift) && [] !== $column_drift): ?>
                        <ul class="slimstat-column-drift"><?php foreach ($column_drift as $column): ?><li><?php echo esc_html($column); ?></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                    <?php // Open when the steps inside it are the only way to act on this page. ?>
                    <details class="slimstat-details" style="margin:10px 0;">
                        <summary style="cursor:pointer;"><?php echo esc_html__('Technical details', 'wp-slimstat'); ?></summary>
                        <ul id="slimstat-migration-list"></ul>
                    </details>

                    <?php if ($offered_migrations) : ?><details class="slimstat-details"<?php if (!empty($column_drift)) { echo ' open'; } ?>><summary><?php esc_html_e('Advanced (optional)', 'wp-slimstat'); ?></summary><p><?php esc_html_e('These steps are optional and do not block the required update. Review each benefit and any tracking interruption before running it.', 'wp-slimstat'); ?></p><ul id="slimstat-optional-migration-list"></ul></details><?php endif; ?>

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
