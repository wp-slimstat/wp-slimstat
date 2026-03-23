<?php
/**
 * Fixture: multisite
 *
 * Readiness check and optional sub-site seeder for WordPress Multisite.
 *
 * Behaviour:
 *   - If NOT multisite: outputs "FIXTURE_SKIP:multisite" and exits cleanly.
 *     Test runners can detect this signal and skip multisite-specific tests.
 *   - If multisite: creates a sub-site "fixture-sub" (idempotent).
 *
 * Usage:
 *   wp eval-file tests/e2e/fixtures/multisite.php --path=/var/www/html --allow-root
 *
 * @requires PHP 7.4+
 */

declare(strict_types=1);

// ── Readiness check ───────────────────────────────────────────────────────────

if (!is_multisite()) {
    // Emit a machine-readable skip signal on stdout, then exit cleanly.
    WP_CLI::log('FIXTURE_SKIP:multisite');
    WP_CLI::success('multisite fixture skipped (not a multisite installation).');
    return;
}

// ── Sub-site seeding ──────────────────────────────────────────────────────────

$sub_slug   = 'fixture-sub';
$sub_domain = defined('DOMAIN_CURRENT_SITE') ? DOMAIN_CURRENT_SITE : parse_url(home_url(), PHP_URL_HOST);

// For subdirectory installs (most common in local dev), check by path.
// For subdomain installs, check by domain.
$existing_blog_id = get_blog_id_from_url($sub_domain, '/' . $sub_slug . '/');

if (!$existing_blog_id) {
    $site_id = get_current_site()->id ?? 1;

    $blog_id = wpmu_create_blog(
        $sub_domain,
        '/' . $sub_slug . '/',
        'Fixture Sub Site',
        get_current_user_id(),
        ['public' => 1],
        $site_id
    );

    if (is_wp_error($blog_id)) {
        WP_CLI::warning('Sub-site creation failed: ' . $blog_id->get_error_message());
    } else {
        WP_CLI::log('Created sub-site ID: ' . $blog_id);

        // Seed a single page on the sub-site.
        switch_to_blog($blog_id);

        $page_slug = 'fixture-multisite-welcome';
        if (!get_page_by_path($page_slug, OBJECT, 'page')) {
            wp_insert_post([
                'post_title'   => 'Welcome to Fixture Sub Site',
                'post_name'    => $page_slug,
                'post_content' => 'This sub-site was created by the multisite fixture.',
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ]);
        }

        restore_current_blog();
    }
} else {
    WP_CLI::log('Sub-site already exists (blog ID: ' . $existing_blog_id . ').');
}

WP_CLI::success('multisite fixture seeded.');
