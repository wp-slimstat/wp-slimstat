# Testing Instructions for WP-SlimStat Feature 004

## Environment Limitations

The current Local by Flywheel environment doesn't have PHP/Composer in the system PATH. You have two options to run the integration tests:

## Option 1: Install PHP/Composer and Run Tests

### Step 1: Install Homebrew PHP (if not already installed)

```bash
# Install Homebrew PHP
brew install php

# Verify installation
php --version
composer --version
```

### Step 2: Install PHPUnit Dependencies

```bash
cd "/Users/parhumm/Local Sites/test/app/public/wp-content/plugins/wp-slimstat"

# Install PHPUnit and WordPress polyfills
composer require --dev phpunit/phpunit:^9.5 yoast/phpunit-polyfills
```

### Step 3: Run Integration Tests

```bash
# Run all integration tests (T061-T064)
vendor/bin/phpunit --testsuite Integration

# Run specific test
vendor/bin/phpunit --filter test_widget_renders_html_with_test_visits

# Run with verbose output
vendor/bin/phpunit --testsuite Integration --verbose

# Run performance tests only
vendor/bin/phpunit --group performance
```

### Expected Test Output

```
PHPUnit 9.5.x

Integration (6 tests, 14 assertions)
 ✓ Widget renders html with test visits (T061)
 ✓ Widget render performance under 300ms (T062)
 ✓ Widget handles zero state scenario (T063)
 ✓ Ajax refresh flow clears cache and rerenders (T064)
 ✓ Widgets respect date range filters
 ✓ Additional widget integration test

Time: 00:02.500, Memory: 20.00 MB

OK (6 tests, 14 assertions)
```

## Option 2: Manual WordPress Testing

If you prefer to verify functionality without running PHPUnit:

### Step 1: Access WordPress Admin

1. Go to: http://test.local/wp-admin
2. Navigate to: **SlimStat → Settings → Traffic Channels** (should see channel widgets)

### Step 2: Verify Widget Rendering (T061)

**Expected Results:**
- ✓ "Top Traffic Channel" widget displays
- ✓ "Channel Distribution" widget displays with table
- ✓ Both widgets show HTML structure with proper CSS classes
- ✓ If no data: "No channel data available" message appears
- ✓ If data exists: Channel names (Direct, Organic Search, Social, etc.) display

### Step 3: Verify Performance (T062)

**Browser DevTools Method:**
1. Open browser DevTools (F12)
2. Go to **Network** tab
3. Filter by **XHR**
4. Reload the SlimStat admin page
5. Check the AJAX request timing for widget loads

**Expected Results:**
- ✓ Widget AJAX responses < 300ms (even with 10k visits)
- ✓ No PHP errors in browser console
- ✓ No JavaScript errors

### Step 4: Verify Zero-State (T063)

1. In database, run:
   ```sql
   DELETE FROM wp_slim_channels;
   ```
2. Reload SlimStat admin page

**Expected Results:**
- ✓ Widgets render without fatal errors
- ✓ "No channel data available" or similar message displays
- ✓ Page loads normally

### Step 5: Verify AJAX Refresh (T064)

1. Open browser DevTools → **Application → Storage → Transients**
2. Find transients starting with `slimstat_widget_slim_channel`
3. Note the cached values
4. Click any "Refresh" button on the widgets
5. Verify transients are cleared and repopulated

**Expected Results:**
- ✓ Initial page load creates transient cache
- ✓ Refresh button clears cache
- ✓ Widget re-renders with fresh data
- ✓ New transient is set

## Option 3: Use Local's Built-in PHP

If Local by Flywheel has a built-in PHP, you can use it:

```bash
# Find Local's PHP
find ~/Library/Application\ Support/Local -name "php" -type f

# Use it directly (replace path with actual path found)
/path/to/local/php/bin/php vendor/bin/phpunit --testsuite Integration
```

## Test Coverage Summary

### T061: Widget HTML Output Test ✓
- Tests widget rendering with 10k visits
- Validates HTML structure
- Confirms all 8 channel categories supported

### T062: Performance Test ✓
- Ensures <300ms render time (FR-033)
- Tests with 10k visits dataset
- Validates both Top Channel and Distribution widgets

### T063: Zero-State Scenario ✓
- Tests with no classified data
- Validates error-free rendering
- Confirms "no data" messages display

### T064: AJAX Refresh Flow ✓
- Tests cache invalidation
- Validates re-rendering
- Confirms new cache creation

## Troubleshooting

### "Class not found" errors

```bash
# Regenerate autoloader
composer dump-autoload
```

### Database connection errors

Ensure WordPress is properly loaded in `tests/bootstrap.php`. The current configuration uses the Local site's WordPress installation at:
```
/Users/parhumm/Local Sites/test/app/public/
```

### Test data not cleaning up

```sql
-- Manual cleanup
DELETE FROM wp_slim_stats WHERE ip = '127.0.0.1';
DELETE FROM wp_slim_channels WHERE visit_id NOT IN (SELECT id FROM wp_slim_stats);
```

## Next Steps

After tests pass:
1. ✓ T061-T064 complete (Integration tests implemented)
2. Optional: T025-T028 (Unit tests for ClassificationEngine)
3. Optional: T072-T075 (Filter integration tests)
4. Ready for production deployment

## Support

- Test documentation: [tests/README.md](tests/README.md)
- PHPUnit docs: https://phpunit.de/documentation.html
- WordPress testing: https://make.wordpress.org/core/handbook/testing/automated-testing/phpunit/
