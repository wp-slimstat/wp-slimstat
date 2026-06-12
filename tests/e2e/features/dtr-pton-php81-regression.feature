Feature: Tracker IP binarization is correct on PHP 8.1+
  The `_dtr_pton()` helper converts an IP string to its binary representation
  for prefix-based IP-filter comparisons (`ignore_ip`, GDPR exclusions, etc.).
  On PHP 8.1+ a missing `$unpacked` initialization let invalid inputs leak
  8 phantom zero bits into filter comparisons — silently broken IP filters.

  # Source enforcement: tests/dtr-pton-init-test.php
  # PHPUnit:            tests/Unit/Tracker/TrackerTest.php (5 test_dtr_pton_* cases)
  # Issue:              v5.4.15 CI Tier 1 8.1 lane test failure (resolved in 5.4.16)

  Background:
    Given WP Slimstat 5.4.16 or later is active
    And the host is running PHP 8.1 or higher

  Scenario: bdd-dtr-pton-invalid-ip-returns-empty-string
    When Tracker::_dtr_pton is called with the string "not-an-ip"
    Then the return value is the empty string ""
    And the return value is NOT the phantom "00000000" (8 fake zero bits)

  Scenario: bdd-dtr-pton-valid-ipv4-returns-32-bit-binary
    When Tracker::_dtr_pton is called with "192.168.1.1"
    Then the return value has length 32
    And the return value contains only "0" and "1" characters
    And the return value equals "11000000101010000000000100000001"

  Scenario: bdd-dtr-pton-valid-ipv6-returns-128-bit-binary
    Given the AF_INET6 constant is defined
    When Tracker::_dtr_pton is called with "::1"
    Then the return value has length 128
    And the return value is 127 zero bits followed by "1"

  Scenario: bdd-dtr-pton-empty-input-returns-empty-string
    When Tracker::_dtr_pton is called with the empty string ""
    Then the return value is the empty string ""

  Scenario: bdd-ci-8-1-lane-re-promoted-to-fast
    # Reverted in v5.4.15, re-promoted in v5.4.16 once the underlying bug was
    # fixed. 8.1 in Tier 1 catches implicit-nullable E_DEPRECATED at PR time.
    Given .github/workflows/ci.yml is at v5.4.16 or later
    Then the Tier 1 fast matrix includes "8.1"
