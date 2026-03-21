<?php
/**
 * Fixture: publisher
 *
 * Seeds a news/blog site with 3 posts, 2 pages, 1 editor user, and tags.
 * Idempotent — running twice produces no duplicates.
 *
 * Usage:
 *   wp eval-file tests/e2e/fixtures/publisher.php --path=/var/www/html --allow-root
 *
 * @requires PHP 7.4+
 */

declare(strict_types=1);

// ── Categories ────────────────────────────────────────────────────────────────

$categories = [
    'Technology' => 'technology',
    'Business'   => 'business',
];

$category_ids = [];
foreach ($categories as $name => $slug) {
    $existing = get_term_by('slug', $slug, 'category');
    if ($existing instanceof WP_Term) {
        $category_ids[$slug] = $existing->term_id;
    } else {
        $result = wp_insert_term($name, 'category', ['slug' => $slug]);
        $category_ids[$slug] = is_wp_error($result) ? 0 : (int) $result['term_id'];
    }
}

// ── Tags ──────────────────────────────────────────────────────────────────────

$tags = ['news', 'tech', 'business'];
foreach ($tags as $tag) {
    if (!get_term_by('slug', $tag, 'post_tag')) {
        wp_insert_term($tag, 'post_tag', ['slug' => $tag]);
    }
}

$tag_ids = [];
foreach ($tags as $tag) {
    $term = get_term_by('slug', $tag, 'post_tag');
    if ($term instanceof WP_Term) {
        $tag_ids[$tag] = $term->term_id;
    }
}

// ── Posts ─────────────────────────────────────────────────────────────────────

$posts = [
    [
        'post_title'    => 'Publisher Fixture: Tech Roundup 2024',
        'post_name'     => 'fixture-tech-roundup-2024',
        'post_content'  => 'Weekly technology news roundup covering AI, cloud computing, and developer tools.',
        'post_status'   => 'publish',
        'post_type'     => 'post',
        'post_category' => [$category_ids['technology']],
        'tags_input'    => ['news', 'tech'],
    ],
    [
        'post_title'    => 'Publisher Fixture: Business Trends Q1',
        'post_name'     => 'fixture-business-trends-q1',
        'post_content'  => 'Analysis of key business trends for Q1 including market shifts and startup activity.',
        'post_status'   => 'publish',
        'post_type'     => 'post',
        'post_category' => [$category_ids['business']],
        'tags_input'    => ['news', 'business'],
    ],
    [
        'post_title'    => 'Publisher Fixture: Cross-Sector Innovation',
        'post_name'     => 'fixture-cross-sector-innovation',
        'post_content'  => 'How technology and business intersect to drive cross-sector innovation.',
        'post_status'   => 'publish',
        'post_type'     => 'post',
        'post_category' => [$category_ids['technology'], $category_ids['business']],
        'tags_input'    => ['tech', 'business'],
    ],
];

foreach ($posts as $post_data) {
    $existing = get_page_by_path($post_data['post_name'], OBJECT, 'post');
    if (!$existing) {
        $result = wp_insert_post($post_data, true);
        if (is_wp_error($result)) {
            WP_CLI::warning('Post insert failed: ' . $result->get_error_message());
        }
    }
}

// ── Pages ─────────────────────────────────────────────────────────────────────

$pages = [
    [
        'post_title'   => 'About',
        'post_name'    => 'fixture-publisher-about',
        'post_content' => 'About this news publication.',
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ],
    [
        'post_title'   => 'Contact',
        'post_name'    => 'fixture-publisher-contact',
        'post_content' => 'Contact the editorial team.',
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

// ── Editor user ───────────────────────────────────────────────────────────────

$editor_login = 'fixture_editor_publisher';
if (!get_user_by('login', $editor_login)) {
    $user_id = wp_create_user($editor_login, 'fixture_pass_2024!', 'fixture.editor@publisher.test');
    if (!is_wp_error($user_id)) {
        $user = new WP_User($user_id);
        $user->set_role('editor');
    }
}

WP_CLI::success('publisher fixture seeded.');
