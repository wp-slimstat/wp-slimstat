Feature: WordPress admin loads cleanly on PHP 7.4 (str_contains regression)
  As a SlimStat administrator running WordPress on a PHP 7.4 host,
  I want every wp-admin page to load without fatals
  so that I can manage the plugin (and the rest of WordPress) without seeing
  a white-screen-of-death triggered by the asset enqueue function.

  # Issue: https://github.com/wp-slimstat/wp-slimstat/issues/303 (audit follow-up)
  # Implemented as Playwright spec: issue-php74-admin-load.spec.ts
  # Environment: wp-env phpVersion is pinned to 7.4 in .wp-env.json

  Background:
    Given WP Slimstat 5.4.14 or later is active
    And the WordPress site is running on PHP 7.4
    And WP_DEBUG and WP_DEBUG_LOG are enabled

  Scenario: bdd-php74-admin-pages-load-without-fatal
    # Covers /wp-admin/, /wp-admin/admin.php?page=slimview1, /wp-admin/edit.php.
    # The enqueue function fires on every admin_enqueue_scripts hook — must
    # not fatal on SlimStat or non-SlimStat screens.
    When an administrator opens any wp-admin page
    Then the response status is 200
    And the page renders the WordPress admin chrome
    And wp-content/debug.log contains no "Call to undefined function str_contains" fatal
    And wp-content/debug.log contains no PHP Fatal from the wp-slimstat path

  Scenario: bdd-php74-slim-pages-enqueue-datepicker
    When an administrator opens a SlimStat report page (page=slimview1)
    Then the jquery-ui-datepicker script is enqueued

  Scenario: bdd-php74-non-slim-pages-no-datepicker
    When an administrator opens /wp-admin/edit.php
    Then the jquery-ui-datepicker script is NOT enqueued
