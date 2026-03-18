<?php
/**
 * Fixture: store
 *
 * Seeds an e-commerce site structure: Shop and Cart pages, one product
 * announcement post. WooCommerce is NOT required — this only creates the
 * page scaffolding used by E2E tests.
 * Idempotent — running twice produces no duplicates.
 *
 * Usage:
 *   wp eval-file tests/e2e/fixtures/store.php --path=/var/www/html --allow-root
 *
 * @requires PHP 7.4+
 */

declare(strict_types=1);

// ── Pages ─────────────────────────────────────────────────────────────────────

$pages = [
    [
        'post_title'   => 'Shop',
        'post_name'    => 'fixture-store-shop',
        'post_content' => 'Browse our product catalogue.',
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ],
    [
        'post_title'   => 'Cart',
        'post_name'    => 'fixture-store-cart',
        'post_content' => 'Your shopping cart.',
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

// ── Product announcement post ─────────────────────────────────────────────────

$post_slug = 'fixture-store-product-launch';
$existing  = get_page_by_path($post_slug, OBJECT, 'post');
if (!$existing) {
    $result = wp_insert_post([
        'post_title'   => 'Store Fixture: New Product Launch',
        'post_name'    => $post_slug,
        'post_content' => 'Announcing our latest product. Available now in the shop.',
        'post_status'  => 'publish',
        'post_type'    => 'post',
    ], true);
    if (is_wp_error($result)) {
        WP_CLI::warning('Post insert failed: ' . $result->get_error_message());
    }
}

WP_CLI::success('store fixture seeded.');
