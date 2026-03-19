<?php

declare(strict_types=1);

$assertions = 0;

function assert_same($expected, $actual, string $message): void
{
    global $assertions;
    $assertions++;

    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ")\n");
        exit(1);
    }
}

function assert_true($actual, string $message): void
{
    global $assertions;
    $assertions++;

    if ($actual !== true) {
        fwrite(STDERR, "FAIL: {$message} (expected true, got " . var_export($actual, true) . ")\n");
        exit(1);
    }
}

function assert_false($actual, string $message): void
{
    global $assertions;
    $assertions++;

    if ($actual !== false) {
        fwrite(STDERR, "FAIL: {$message} (expected false, got " . var_export($actual, true) . ")\n");
        exit(1);
    }
}

if (!class_exists('wp_slimstat')) {
    class wp_slimstat
    {
        public static function string_to_array($_option = '')
        {
            if (empty($_option) || !is_string($_option)) {
                return [];
            }

            return array_filter(array_map('trim', explode(',', $_option)));
        }
    }
}

$GLOBALS['slimstat_attachment_test_state'] = [];
$GLOBALS['post'] = (object) ['ID' => 123, 'post_author' => 7];
$GLOBALS['pagenow'] = '';

function slimstat_attachment_test_reset(array $overrides = []): void
{
    $GLOBALS['slimstat_attachment_test_state'] = array_merge([
        'is_404' => false,
        'is_single' => false,
        'is_page' => false,
        'is_attachment' => false,
        'is_singular' => false,
        'is_post_type_archive' => false,
        'is_tag' => false,
        'is_tax' => false,
        'is_category' => false,
        'is_date' => false,
        'is_author' => false,
        'is_archive' => false,
        'is_search' => false,
        'is_feed' => false,
        'is_home' => false,
        'is_front_page' => false,
        'is_admin' => false,
        'is_paged' => false,
        'post_type' => 'attachment',
    ], $overrides);

    $GLOBALS['post'] = (object) ['ID' => 123, 'post_author' => 7];
    $GLOBALS['pagenow'] = '';
}

function slimstat_attachment_test_state(string $key)
{
    return $GLOBALS['slimstat_attachment_test_state'][$key] ?? false;
}

if (!function_exists('get_post_type')) {
    function get_post_type()
    {
        return slimstat_attachment_test_state('post_type');
    }
}

if (!function_exists('get_object_taxonomies')) {
    function get_object_taxonomies($post)
    {
        return [];
    }
}

if (!function_exists('get_the_terms')) {
    function get_the_terms($postId, $taxonomy)
    {
        return [];
    }
}

if (!function_exists('get_the_tags')) {
    function get_the_tags()
    {
        return [];
    }
}

if (!function_exists('get_the_category')) {
    function get_the_category()
    {
        return [];
    }
}

if (!function_exists('get_the_author_meta')) {
    function get_the_author_meta($field, $userId)
    {
        return 'attachment-author';
    }
}

if (!function_exists('is_404')) {
    function is_404()
    {
        return slimstat_attachment_test_state(__FUNCTION__);
    }
}

if (!function_exists('is_single')) {
    function is_single()
    {
        return slimstat_attachment_test_state(__FUNCTION__);
    }
}

if (!function_exists('is_page')) {
    function is_page()
    {
        return slimstat_attachment_test_state(__FUNCTION__);
    }
}

if (!function_exists('is_attachment')) {
    function is_attachment()
    {
        return slimstat_attachment_test_state(__FUNCTION__);
    }
}

if (!function_exists('is_singular')) {
    function is_singular()
    {
        return slimstat_attachment_test_state(__FUNCTION__);
    }
}

if (!function_exists('is_post_type_archive')) {
    function is_post_type_archive()
    {
        return slimstat_attachment_test_state(__FUNCTION__);
    }
}

if (!function_exists('is_tag')) {
    function is_tag()
    {
        return slimstat_attachment_test_state(__FUNCTION__);
    }
}

if (!function_exists('is_tax')) {
    function is_tax()
    {
        return slimstat_attachment_test_state(__FUNCTION__);
    }
}

if (!function_exists('is_category')) {
    function is_category()
    {
        return slimstat_attachment_test_state(__FUNCTION__);
    }
}

if (!function_exists('is_date')) {
    function is_date()
    {
        return slimstat_attachment_test_state(__FUNCTION__);
    }
}

if (!function_exists('is_author')) {
    function is_author()
    {
        return slimstat_attachment_test_state(__FUNCTION__);
    }
}

if (!function_exists('is_archive')) {
    function is_archive()
    {
        return slimstat_attachment_test_state(__FUNCTION__);
    }
}

if (!function_exists('is_search')) {
    function is_search()
    {
        return slimstat_attachment_test_state(__FUNCTION__);
    }
}

if (!function_exists('is_feed')) {
    function is_feed()
    {
        return slimstat_attachment_test_state(__FUNCTION__);
    }
}

if (!function_exists('is_home')) {
    function is_home()
    {
        return slimstat_attachment_test_state(__FUNCTION__);
    }
}

if (!function_exists('is_front_page')) {
    function is_front_page()
    {
        return slimstat_attachment_test_state(__FUNCTION__);
    }
}

if (!function_exists('is_admin')) {
    function is_admin()
    {
        return slimstat_attachment_test_state(__FUNCTION__);
    }
}

if (!function_exists('is_paged')) {
    function is_paged()
    {
        return slimstat_attachment_test_state(__FUNCTION__);
    }
}

require_once __DIR__ . '/../src/Tracker/Utils.php';
require_once __DIR__ . '/../src/Tracker/Tracker.php';

use SlimStat\Tracker\Tracker;
use SlimStat\Tracker\Utils;

slimstat_attachment_test_reset([
    'is_attachment' => true,
    'is_singular' => true,
]);

$contentInfo = Utils::getContentInfo();
assert_same('cpt:attachment', $contentInfo['content_type'], 'Utils::getContentInfo should prefix attachment content types');

$legacyContentInfo = Tracker::_get_content_info();
assert_same('cpt:attachment', $legacyContentInfo['content_type'], 'Tracker::_get_content_info should prefix attachment content types');

assert_true(Utils::isBlacklisted('cpt:attachment', 'cpt:attachment'), 'Exact attachment CPT exclusions should match');
assert_false(Utils::isBlacklisted('attachment', 'cpt:attachment'), 'Legacy attachment values should not match prefixed exclusions');
assert_true(Utils::isBlacklisted('cpt:attachment', 'cpt:*'), 'Wildcard CPT exclusions should match attachments');

echo "All {$assertions} assertions passed in content-type-attachment-test.php\n";
