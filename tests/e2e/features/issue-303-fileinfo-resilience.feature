Feature: Tracker resilience when the PHP fileinfo extension is missing
  As a SlimStat administrator on a host whose PHP build lacks ext-fileinfo,
  I want the tracker to keep working (using the built-in UADetector fallback)
  so that visitor analytics are not silently broken by a hosting limitation,
  and I want to be told what to do about it.

  # Issue: https://github.com/wp-slimstat/wp-slimstat/issues/303
  # Implemented as Playwright spec: issue-303-fileinfo-missing-tracker.spec.ts

  Background:
    Given WP Slimstat 5.4.13 or later is active
    And the SlimStat option "enable_browscap" is set to "on"
    And the SlimStat option "is_tracking" is set to "on"
    And tracking_request_method is "rest"
    And the host's PHP build does NOT have the "fileinfo" extension loaded

  Scenario: bdd-tracker-degrades-gracefully-no-fileinfo
    When a frontend visitor loads any public page
    Then the tracker REST endpoint POST /wp-json/slimstat/v1/hit returns HTTP 200
    And a row is recorded in wp_slim_stats for the request
    And no "PHP Fatal error" appears in wp-content/debug.log for the wp-slimstat path
    And the recorded row's "browser" column is populated by UADetector
    And the recorded row's "browser" column is not the literal "Default Browser"

  Scenario: bdd-admin-notice-surfaces-when-extension-missing
    When an administrator opens /wp-admin/admin.php?page=slimview1
    Then a warning admin notice mentioning the "fileinfo" extension is visible
    And the notice links to the Settings → Third-Party Libraries tab
    And the notice is rendered inside SlimStat admin pages only (not network admin, not unrelated wp-admin screens)

  Scenario: bdd-dismiss-notice-persists-across-loads
    Given the fileinfo notice is visible
    When the administrator clicks the dismiss button on the notice
    And the dismiss request to admin-ajax.php?action=slimstat_notice_browscap_fileinfo returns success
    And the administrator reloads /wp-admin/admin.php?page=slimview1
    Then the fileinfo notice is no longer visible
    And the SlimStat option "notice_browscap_fileinfo" is now "off"
    And dismissing this notice does not affect the separate "notice_browscap" install banner

  Scenario: bdd-no-notice-when-browscap-disabled
    Given the SlimStat option "enable_browscap" is set to "off"
    When an administrator opens /wp-admin/admin.php?page=slimview1
    Then no warning notice mentioning "fileinfo" is visible
    # Sites that intentionally opted out of Browscap should not be nagged.

  Scenario: bdd-backward-compat-fileinfo-present
    Given the host's PHP build DOES have the "fileinfo" extension loaded
    And the SlimStat option "enable_browscap" is set to "on"
    When a frontend visitor loads any public page
    Then the tracker REST endpoint returns HTTP 200
    And the recorded row's "browser" column is populated by Browscap (when the cache is installed)
    And no admin notice about fileinfo is rendered

  Scenario: bdd-throwable-catch-protects-against-other-errors
    Given the host's PHP build DOES have the "fileinfo" extension loaded
    And the bundled Browscap cache is corrupted or unreachable
    When a frontend visitor loads any public page
    Then the tracker REST endpoint returns HTTP 200
    And the wider \Throwable catch in get_browser_from_browscap() degrades to UADetector instead of fataling
    And a single error_log line is written for diagnosability
