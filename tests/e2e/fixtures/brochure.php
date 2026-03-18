<?php
/**
 * Fixture: brochure
 *
 * Seeds a corporate brochure site: Home, About, Services, Contact pages
 * and one testimonial post. No blog archive intended.
 * Idempotent — running twice produces no duplicates.
 *
 * Usage:
 *   wp eval-file tests/e2e/fixtures/brochure.php --path=/var/www/html --allow-root
 *
 * @requires PHP 7.4+
 */

declare(strict_types=1);

// ── Pages ─────────────────────────────────────────────────────────────────────

$pages = [
    [
        'post_title'   => 'Home',
        'post_name'    => 'fixture-brochure-home',
        'post_content' => 'Welcome to Acme Corp. We build reliable software solutions.',
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ],
    [
        'post_title'   => 'About',
        'post_name'    => 'fixture-brochure-about',
        'post_content' => 'Acme Corp was founded in 2010 with a mission to simplify enterprise software.',
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ],
    [
        'post_title'   => 'Services',
        'post_name'    => 'fixture-brochure-services',
        'post_content' => 'Our services include consulting, development, and 24/7 support.',
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ],
    [
        'post_title'   => 'Contact',
        'post_name'    => 'fixture-brochure-contact',
        'post_content' => 'Reach us at contact@acmecorp.test or call +1-800-FIXTURE.',
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ],
];

foreach ($pages as $page_data) {
    $existing = get_page_by_path($page_data['post_name'], OBJECT, 'page');
    if (!$existing) {
        $result = wp_insert_post($page_data, true);
        if (is_wp_error($result)) {
            WP_CLI::warning('Page insert failed: ' . $result->get_error_message());
        }
    }
}

// ── Testimonial post ──────────────────────────────────────────────────────────

$testimonial_slug = 'fixture-brochure-testimonial-acme';
$existing         = get_page_by_path($testimonial_slug, OBJECT, 'post');
if (!$existing) {
    $result = wp_insert_post([
        'post_title'   => 'Brochure Fixture: Client Testimonial — Acme Corp',
        'post_name'    => $testimonial_slug,
        'post_content' => '"Working with Acme Corp transformed our operations. Highly recommended." — Jane Doe, CTO of SampleCo.',
        'post_status'  => 'publish',
        'post_type'    => 'post',
    ], true);
    if (is_wp_error($result)) {
        WP_CLI::warning('Post insert failed: ' . $result->get_error_message());
    }
}

WP_CLI::success('brochure fixture seeded.');
