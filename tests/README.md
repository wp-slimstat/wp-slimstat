# WP-SlimStat Testing Guide

## Overview

This directory contains PHPUnit tests for the Traffic Channel Report feature (Feature 004).

## Test Structure

```
tests/
├── bootstrap.php          # Test environment setup
├── Integration/          # Integration tests
│   └── WidgetRenderTest.php  # Widget rendering tests (T061-T064)
└── Unit/                 # Unit tests (future)
    ├── ClassificationEngineTest.php  # T025
    └── ClassificationRulesTest.php   # T026-T028
```

## Requirements

- PHP 7.4 or higher
- WordPress 5.8 or higher
- PHPUnit 9.x
- WordPress Test Library (optional, but recommended)

## Installation

### 1. Install PHPUnit

```bash
cd wp-slimstat
composer require --dev phpunit/phpunit:^9.5
composer require --dev yoast/phpunit-polyfills
```

### 2. Set Up WordPress Test Library (Optional)

```bash
# Install WordPress test library
bash bin/install-wp-tests.sh wordpress_test root '' localhost latest

# Or set WP_TESTS_DIR environment variable
export WP_TESTS_DIR=/path/to/wordpress-tests-lib
```

## Running Tests

### Run All Tests

```bash
cd wp-slimstat
vendor/bin/phpunit
```

### Run Specific Test Suite

```bash
# Integration tests only
vendor/bin/phpunit --testsuite Integration

# Unit tests only
vendor/bin/phpunit --testsuite Unit
```

### Run Specific Test File

```bash
vendor/bin/phpunit tests/Integration/WidgetRenderTest.php
```

### Run Specific Test Method

```bash
vendor/bin/phpunit --filter test_widget_renders_html_with_test_visits
```

### Run with Coverage Report

```bash
vendor/bin/phpunit --coverage-html coverage/
```

## Test Groups

Tests are organized by groups for selective execution:

```bash
# Run integration tests
vendor/bin/phpunit --group integration

# Run performance tests
vendor/bin/phpunit --group performance

# Run edge case tests
vendor/bin/phpunit --group edge-cases

# Run AJAX tests
vendor/bin/phpunit --group ajax

# Exclude slow tests
vendor/bin/phpunit --exclude-group slow
```

## Integration Tests (T061-T064)

### T061: Widget HTML Output Test

Tests that widgets render valid HTML with 10k test visits.

```bash
vendor/bin/phpunit --filter test_widget_renders_html_with_test_visits
```

**Assertions**:
- Widget renders non-empty HTML
- HTML contains proper structure (divs, classes)
- Widget title appears in output
- Distribution widget includes table

### T062: Performance Test (FR-033)

Tests that widgets render under 300ms with 10k visits.

```bash
vendor/bin/phpunit --filter test_widget_render_performance_under_300ms
```

**Assertions**:
- Top Channel Widget < 300ms
- Channel Distribution Widget < 300ms

### T063: Zero-State Scenario

Tests widget behavior with no classified data.

```bash
vendor/bin/phpunit --filter test_widget_handles_zero_state_scenario
```

**Assertions**:
- Widgets render without errors
- "No data" message displayed
- Proper fallback HTML structure

### T064: AJAX Refresh Flow

Tests complete AJAX refresh workflow.

```bash
vendor/bin/phpunit --filter test_ajax_refresh_flow_clears_cache_and_rerenders
```

**Assertions**:
- Initial render caches output
- Cache can be cleared
- Second render fetches fresh data
- New cache is set after re-render

## Writing New Tests

### Integration Test Template

```php
<?php
namespace SlimStat\Tests\Integration;

use PHPUnit\Framework\TestCase;

class MyIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Setup code
    }

    /**
     * @test
     * @group integration
     */
    public function test_my_feature(): void
    {
        // Arrange
        $expected = 'expected value';

        // Act
        $actual = my_function();

        // Assert
        $this->assertEquals($expected, $actual);
    }
}
```

### Unit Test Template

```php
<?php
namespace SlimStat\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SlimStat\Channel\ClassificationEngine;

class ClassificationEngineTest extends TestCase
{
    /**
     * @test
     * @group unit
     */
    public function test_classifies_direct_traffic(): void
    {
        $engine = new ClassificationEngine();
        $result = $engine->classify([
            'referer' => '',
            'resource' => 'https://example.com/',
        ]);

        $this->assertEquals('direct', $result['channel']);
    }
}
```

## Test Data

### Creating Test Visits

The `WidgetRenderTest` class includes helper methods for creating test data:

```php
// Create 1000 test visits with channel classifications
$this->createTestVisits(1000);

// Create a single visit with specific channel
$this->createVisitWithChannel(time(), 'social');
```

### Cleanup

All test data is automatically cleaned up in `tearDown()`:
- Test visits deleted from `wp_slim_stats`
- Test channel records deleted from `wp_slim_channels`

## Continuous Integration

### GitHub Actions Example

```.yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v2

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '7.4'

      - name: Install dependencies
        run: composer install

      - name: Run tests
        run: vendor/bin/phpunit
```

## Troubleshooting

### Database Connection Errors

Ensure WordPress is properly loaded:

```php
// In bootstrap.php
if (!defined('DB_NAME')) {
    define('DB_NAME', 'your_test_db');
    define('DB_USER', 'your_test_user');
    define('DB_PASSWORD', 'your_test_pass');
    define('DB_HOST', 'localhost');
}
```

### Class Not Found Errors

Check autoloading:

```bash
composer dump-autoload
```

### Test Data Not Cleaning Up

Manually clean test tables:

```sql
DELETE FROM wp_slim_stats WHERE ip = '127.0.0.1';
DELETE FROM wp_slim_channels WHERE visit_id NOT IN (SELECT id FROM wp_slim_stats);
```

## Best Practices

1. **Isolation**: Each test should be independent
2. **Cleanup**: Always clean up test data in `tearDown()`
3. **Assertions**: Use specific assertions (e.g., `assertStringContainsString` instead of `assertTrue`)
4. **Performance**: Mark slow tests with `@group slow`
5. **Documentation**: Add PHPDoc blocks explaining what each test validates

## References

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [WordPress Testing Handbook](https://make.wordpress.org/core/handbook/testing/automated-testing/phpunit/)
- [Feature 004 Specification](../specs/004-traffic-channel/spec.md)
- [Task List](../specs/004-traffic-channel/tasks.free.md)

## Support

For issues or questions:
- Create an issue in the repository
- Check existing tests for examples
- Review PHPUnit and WordPress testing documentation
