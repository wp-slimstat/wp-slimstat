Feature: Own code is implicit-nullable-deprecation-free
  As a SlimStat maintainer planning PHP 9.0 readiness,
  I want zero implicit-nullable parameter signatures in own code
  so that wp-content/debug.log on PHP 8.1+ hosts is free of
  E_DEPRECATED noise originating from wp-slimstat, and the codebase is
  forward-compatible with PHP 9.0 (where the deprecation becomes a fatal).

  # Audit follow-up: jaan-to/outputs/wp/audit/php-7-4-compat/
  # Source-level enforcement: tests/php-implicit-nullable-test.php
  # PHPUnit Reflection pins:  tests/Unit/{Exception,Utils,Tracker,Components}/*CompatTest.php

  Background:
    Given WP Slimstat 5.4.15 or later is active
    And the source-level test `composer test:implicit-nullable` runs on every push

  Scenario Outline: <method> declares an explicit nullable type on the null-default param
    When Reflection inspects <class>::<method>
    Then parameter $<param> declares an explicit nullable type (?<type>)

    Examples:
      | class                                                       | method            | param    | type      |
      | SlimStat\Exception\LogException                             | __construct       | previous | Exception |
      | SlimStat\Utils\Query                                        | hasWhereClause    | operator | string    |
      | SlimStat\Components\DateRangeHelper                         | format_date_range | preset   | string    |
      | SlimStat\Services\Admin\ConditionTagEvaluator               | checkConditions   | version  | string    |
      | SlimStat\Tracker\Session                                    | setTrackingCookie | expires  | int       |

  Scenario: bdd-session-cookie-value-stays-untyped-for-strict_types-callers
    # Negative pin: Session::setTrackingCookie's $value param must NOT be
    # tightened to `string`. ConsentChangeRestController has strict_types=1
    # and passes int from Session::getVisitId(): int — a `string $value`
    # signature would TypeError that caller.
    When Reflection inspects SlimStat\Tracker\Session::setTrackingCookie
    Then the first parameter $value has no explicit type
