Feature: CI matrix enforces the "Requires PHP" contract
  The plugin header declares "Requires PHP: 7.4" — every PHP version in
  [floor, current-stable] must be exercised in at least one CI tier whose
  job runs real tests (PHPUnit or composer test:*), not just `php -l`.
  The PR #307 audit proved a lint-only 7.4 lane misses runtime fatals
  like `Call to undefined function str_contains`.

  # Audit follow-up: jaan-to/outputs/wp/audit/php-7-4-compat/
  # Source enforcement: tests/ci-matrix-coverage-test.php (vanilla PHP)

  Background:
    Given .github/workflows/ci.yml defines Tier 1 fast and Tier 3 nightly jobs

  Scenario: bdd-tier1-fast-covers-7-4-8-1-8-2
    # 8.1 promoted from nightly so implicit-nullable regressions surface on PR review.
    When CI runs on every push or pull request
    Then Tier 1 fast executes PHPUnit (8.1, 8.2) and source-level tests (all three)
    On PHP 7.4, 8.1, and 8.2

  Scenario Outline: bdd-tier3-nightly-runs-real-tests-on-<php>
    When the 02:00 UTC nightly schedule fires
    Then Tier 3 nightly executes the full PHPUnit suite on PHP <php>

    Examples:
      | php |
      | 7.4 |
      | 8.0 |
      | 8.1 |
      | 8.2 |
      | 8.3 |
      | 8.4 |
      | 8.5 |
