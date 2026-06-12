Feature: Bundled Symfony/Polyfill/Php80 is loaded on every request
  Without this load, own code that calls `str_contains()` or any other PHP
  8.0+ stdlib function fatals on real PHP 7.4 hosts — the exact bug that
  produced v5.4.14's wp-admin crash.

  # Source enforcement:  tests/php74-no-php80-functions-test.php (asserts the
  #                      require_once line exists in wp-slimstat.php)
  # PHPUnit integration: tests/Unit/Polyfill/Php80PolyfillLoadedTest.php
  # Production load:     wp-slimstat.php (after vendor/autoload.php)

  Background:
    Given WP Slimstat 5.4.17 or later is active
    And the bundled Symfony/Polyfill/Php80 bootstrap exists at src/Dependencies/Symfony/Polyfill/Php80/bootstrap.php

  Scenario Outline: bdd-polyfilled-function-is-callable-after-plugin-boot
    When the plugin's wp-slimstat.php is loaded
    Then the function <fn>() is defined globally
    And it short-circuits to the native PHP implementation on PHP 8.0+

    Examples:
      | fn                   |
      | fdiv                 |
      | preg_last_error_msg  |
      | str_contains         |
      | str_starts_with      |
      | str_ends_with        |
      | get_debug_type       |
      | get_resource_id      |

  Scenario: bdd-source-level-test-asserts-bootstrap-is-required
    Given a developer removes the polyfill `require_once` from wp-slimstat.php
    When `composer test:php74-compat` runs in CI
    Then the test fails with a clear "must require_once Symfony/Polyfill/Php80/bootstrap.php" message
    And the developer must restore the require line before merge

  Scenario: bdd-php-81-plus-stdlib-still-flagged
    Given a developer adds a call to `array_is_list($arr)` somewhere in admin/ or src/
    When `composer test:php74-compat` runs
    Then the test fails — array_is_list is PHP 8.1 and the bundled polyfill does NOT cover it
    But the test does NOT flag str_contains (which IS polyfilled)
