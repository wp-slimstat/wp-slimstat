<?php
/**
 * Every WP-Cron hook this plugin schedules.
 *
 * This is the single source of truth for cleanup. Deactivation
 * (wp_slimstat_admin::deactivate) and uninstall (slimstat_uninstall_cron) both
 * iterate it, so a hook can never be scheduled without also being cleared —
 * `slimstat_daily_cron_hook` was scheduled from day one and cleared nowhere,
 * because the two lists were maintained by hand in different files.
 *
 * Returns a plain array and declares no class, so uninstall.php — which runs with
 * WordPress loaded but the plugin NOT bootstrapped, and therefore without the
 * Composer autoloader — can `require` it safely.
 *
 * Add a hook here the moment you schedule it anywhere; tests/cron-hook-cleanup-test.php
 * scans the source for scheduling calls and fails the build on any hook that is missing.
 *
 * @return string[]
 */

return [
    // Recurring (wp_schedule_event).
    'wp_slimstat_purge',
    'wp_slimstat_update_geoip_database',
    // No longer scheduled (W6) — retained so deactivate/uninstall still sweep it off
    // installs that scheduled it before it was retired.
    'wp_slimstat_generate_daily_salt',
    'slimstat_daily_cron_hook',

    // One-shot (wp_schedule_single_event). Clearing these is idempotent and stops a
    // pending event firing against a plugin that is no longer there.
    'slimstat_initial_notification_fetch',
];
