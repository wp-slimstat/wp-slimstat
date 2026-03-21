<?php
/**
 * Fixture: membership
 *
 * Seeds a membership site structure: Login and Members pages, two users
 * (subscriber + member). No membership plugin required.
 * Idempotent — running twice produces no duplicates.
 *
 * Usage:
 *   wp eval-file tests/e2e/fixtures/membership.php --path=/var/www/html --allow-root
 *
 * @requires PHP 7.4+
 */

declare(strict_types=1);

// ── Pages ─────────────────────────────────────────────────────────────────────

$pages = [
    [
        'post_title'   => 'Login',
        'post_name'    => 'fixture-membership-login',
        'post_content' => 'Please log in to access member content.',
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ],
    [
        'post_title'   => 'Members',
        'post_name'    => 'fixture-membership-members',
        'post_content' => 'Welcome, members. This area is restricted to registered members only.',
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ],
    [
        'post_title'   => 'Restricted Content',
        'post_name'    => 'fixture-membership-restricted',
        'post_content' => 'This content is only visible to active members.',
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

// ── Users ─────────────────────────────────────────────────────────────────────

$users = [
    [
        'login' => 'fixture_subscriber_01',
        'email' => 'fixture.subscriber@membership.test',
        'role'  => 'subscriber',
    ],
    [
        'login' => 'fixture_member_01',
        'email' => 'fixture.member@membership.test',
        'role'  => 'subscriber', // No built-in "member" role; subscriber is the closest equivalent.
    ],
];

foreach ($users as $user_data) {
    if (!get_user_by('login', $user_data['login'])) {
        $user_id = wp_create_user(
            $user_data['login'],
            'fixture_pass_2024!',
            $user_data['email']
        );
        if (!is_wp_error($user_id)) {
            $user = new WP_User($user_id);
            $user->set_role($user_data['role']);
        } else {
            WP_CLI::warning('User create failed: ' . $user_id->get_error_message());
        }
    }
}

WP_CLI::success('membership fixture seeded.');
