# Manual Testing Guide for Traffic Channel Report (Feature 004)

This guide provides step-by-step instructions for manually testing all integration test scenarios (T061-T064) without PHPUnit.

## Prerequisites

- WordPress site running at: http://test.local
- WP-SlimStat plugin activated
- Admin access to WordPress dashboard

## Test Scenario T061: Widget HTML Rendering

**Objective:** Verify widgets render valid HTML structure with proper data display

### Steps:

1. **Access SlimStat Admin**
   - Go to: http://test.local/wp-admin
   - Navigate to: **SlimStat → Reports → Traffic Channels**

2. **Verify Top Channel Widget**
   - ✓ Widget title: "Top Traffic Channel" is visible
   - ✓ Widget has CSS class: `slimstat-widget channel-widget`
   - ✓ Widget displays channel data OR "No channel data" message
   - ✓ No PHP errors or warnings appear

3. **Verify Channel Distribution Widget**
   - ✓ Widget title: "Channel Distribution" is visible
   - ✓ Widget contains an HTML `<table>` element
   - ✓ Table shows all 8 channel categories (or subset if data limited):
     - Direct
     - Organic Search
     - Paid Search
     - Social
     - Email
     - AI
     - Referral
     - Other
   - ✓ Each row shows: Channel name, Count, Percentage
   - ✓ No JavaScript errors in browser console (F12 → Console)

4. **Inspect HTML Structure (Optional)**
   - Right-click widget → Inspect Element
   - Verify structure matches:
     ```html
     <div class="slimstat-widget channel-widget" id="slim_channel_top">
         <div class="slimstat-widget-header">
             <h3>Top Traffic Channel</h3>
         </div>
         <div class="slimstat-widget-content">
             <!-- Chart or data display -->
         </div>
     </div>
     ```

### Expected Results:
- ✅ Both widgets render without errors
- ✅ HTML structure is valid and properly formatted
- ✅ Data displays correctly (or "no data" message if no visits)
- ✅ CSS styles apply correctly (no broken layout)

---

## Test Scenario T062: Performance <300ms

**Objective:** Verify widget rendering completes under 300ms (FR-033 requirement)

### Steps:

1. **Open Browser DevTools**
   - Press F12 (or Cmd+Option+I on Mac)
   - Go to **Network** tab
   - Check "Disable cache" option

2. **Reload SlimStat Page**
   - Navigate to: **SlimStat → Reports → Traffic Channels**
   - Watch Network tab for AJAX requests

3. **Measure Widget Load Time**
   - Filter Network tab by: **XHR** or **Fetch**
   - Find requests containing "channel" or "widget"
   - Check the **Time** column for each request

4. **Alternative: Use Performance Tab**
   - Go to **Performance** tab in DevTools
   - Click **Record** button
   - Reload the page
   - Stop recording
   - Find widget render events in timeline

### Expected Results:
- ✅ Widget AJAX responses complete in <300ms
- ✅ Total page load (including all widgets) <2 seconds
- ✅ No slow database queries (check Query Monitor plugin if available)

### Performance Benchmarks:
| Visits Count | Expected Time | Maximum Time |
|-------------|---------------|--------------|
| 0-1,000     | <50ms         | 100ms        |
| 1,000-5,000 | <150ms        | 200ms        |
| 5,000-10,000| <250ms        | 300ms        |
| 10,000+     | <300ms        | 300ms        |

---

## Test Scenario T063: Zero-State Scenario

**Objective:** Verify widgets handle cases with no classified channel data

### Steps:

1. **Clear Channel Data (Optional - Skip if you want to keep existing data)**
   ```sql
   -- Run in phpMyAdmin or database client
   DELETE FROM wp_slim_channels;
   ```

2. **Access SlimStat Admin**
   - Go to: **SlimStat → Reports → Traffic Channels**

3. **Verify Zero-State Messages**
   - ✓ Widgets render without fatal errors
   - ✓ "No channel data available" or similar message appears
   - ✓ Message suggests:
     - Waiting for visits to be classified
     - Running classification cron job
     - Checking that tracking is enabled

4. **Verify HTML Structure Remains Valid**
   - Right-click → Inspect Element
   - Confirm widget container divs still render
   - Confirm no empty or broken HTML tags

### Expected Results:
- ✅ No PHP errors or warnings
- ✅ No JavaScript errors in console
- ✅ Widgets display helpful "no data" message
- ✅ Page layout remains intact (no broken CSS)
- ✅ Other SlimStat widgets still function normally

### Restore Data (if you cleared it):
```sql
-- Re-run classification on existing visits
-- The cron job will automatically re-classify visits
-- Or manually trigger: WP-CLI command or admin action
```

---

## Test Scenario T064: AJAX Refresh Flow

**Objective:** Verify cache invalidation and widget re-rendering via AJAX

### Steps:

1. **Check Initial Cache State**
   - Open DevTools → **Application** tab (Chrome) or **Storage** tab (Firefox)
   - Navigate to: **Storage → Transients** (or use plugin like Query Monitor)
   - Look for transients starting with: `slimstat_widget_slim_channel_`
   - Note the cached values and timestamps

2. **Trigger Widget Refresh**
   - On the Traffic Channels page, look for "Refresh" button or icon
   - Click the refresh button
   - Alternatively, use the main "Refresh All" button if available

3. **Monitor Network Activity**
   - Watch **Network → XHR** tab
   - Verify AJAX request is sent to admin-ajax.php
   - Check response contains fresh widget HTML

4. **Verify Cache Was Cleared**
   - Return to **Application → Transients**
   - Confirm old transient was deleted
   - Confirm new transient was created with updated timestamp

5. **Verify Widget Re-rendered**
   - ✓ Widget content updates (even if data looks the same)
   - ✓ No errors in console
   - ✓ Page doesn't fully reload (AJAX only)

### Expected Results:
- ✅ Initial page load creates transient cache
- ✅ Refresh button triggers AJAX request
- ✅ Old transient is deleted (`delete_transient` called)
- ✅ Widget queries fresh data from database
- ✅ New HTML is returned via AJAX
- ✅ New transient is set with updated data
- ✅ Widget updates without full page reload

### Cache Key Format:
```
slimstat_widget_slim_channel_top_{filters_hash}
slimstat_widget_slim_channel_distribution_{filters_hash}
```

---

## Additional Manual Tests

### Test: Date Range Filters

1. **Apply Date Range Filter**
   - Use SlimStat's date range selector
   - Select "Last 7 days"
   - Apply filter

2. **Verify Widget Updates**
   - ✓ Widget data reflects selected date range
   - ✓ Only visits within range are counted
   - ✓ Cache key changes based on filters

3. **Change Date Range**
   - Select "Last 30 days"
   - ✓ Widget data updates accordingly
   - ✓ Different cache transient is used

### Test: Pro Upgrade Modal (Free Plugin Only)

1. **Access Free Plugin Features**
   - Navigate to Traffic Channels page
   - Look for "locked" or "Pro" features

2. **Click Upgrade Trigger**
   - ✓ Modal overlay appears with blur effect
   - ✓ Modal content displays upgrade messaging
   - ✓ "View Pricing & Upgrade" button links to pricing page
   - ✓ Close button (X) dismisses modal
   - ✓ ESC key dismisses modal
   - ✓ Focus is trapped within modal (Tab cycles through elements)

3. **Verify Accessibility**
   - ✓ Modal can be closed with ESC key
   - ✓ Focus returns to trigger element after close
   - ✓ Screen reader announces modal content

---

## Troubleshooting Common Issues

### Issue: "No channel data available" always shows

**Possible Causes:**
1. No visits have been tracked yet
2. Classification cron job hasn't run
3. `wp_slim_channels` table doesn't exist

**Solutions:**
```sql
-- Check if table exists
SHOW TABLES LIKE 'wp_slim_channels';

-- Check if visits exist
SELECT COUNT(*) FROM wp_slim_stats;

-- Check if classifications exist
SELECT COUNT(*) FROM wp_slim_channels;

-- Manually trigger classification (if WP-CLI available)
wp slimstat classify-visits
```

### Issue: Widgets not appearing on admin page

**Possible Causes:**
1. Plugin not activated
2. User doesn't have permission
3. JavaScript errors blocking widget load

**Solutions:**
1. Check: **Plugins → Installed Plugins** → Activate WP-SlimStat
2. Ensure logged in as Administrator
3. Check browser console (F12) for JavaScript errors
4. Disable other plugins to check for conflicts

### Issue: Performance degradation with large datasets

**Possible Causes:**
1. Missing database indexes
2. Cache not working
3. Query inefficiencies

**Solutions:**
```sql
-- Verify indexes exist
SHOW INDEX FROM wp_slim_channels;

-- Should see indexes on:
-- - visit_id (UNIQUE)
-- - channel
-- - classified_at
```

---

## Test Summary Checklist

After completing all manual tests, verify:

- [ ] **T061: Widget HTML Rendering**
  - [ ] Top Channel Widget displays correctly
  - [ ] Channel Distribution Widget displays with table
  - [ ] All 8 channel categories supported
  - [ ] No HTML/CSS errors

- [ ] **T062: Performance**
  - [ ] Widgets load in <300ms (with 10k visits)
  - [ ] No slow queries
  - [ ] Cache is utilized

- [ ] **T063: Zero-State**
  - [ ] "No data" message displays when appropriate
  - [ ] No errors with empty dataset
  - [ ] Layout remains intact

- [ ] **T064: AJAX Refresh**
  - [ ] Refresh button triggers AJAX
  - [ ] Cache is cleared on refresh
  - [ ] Widget re-renders with fresh data
  - [ ] New cache is set

- [ ] **Additional Checks**
  - [ ] Date range filters work
  - [ ] Pro upgrade modal functions (free plugin)
  - [ ] All browsers tested (Chrome, Firefox, Safari)
  - [ ] Mobile responsive design

---

## Next Steps After Testing

1. ✅ Mark T061-T064 as complete in tasks.free.md
2. ✅ Document any bugs found during testing
3. ✅ Create GitHub issues for any failures
4. ✅ Optional: Run automated PHPUnit tests for regression coverage
5. ✅ Deploy to production when all tests pass

## Support

For issues or questions:
- Check: [tests/README.md](tests/README.md)
- Review: [TESTING.md](TESTING.md) for automated test setup
- Reference: [specs/004-traffic-channel/spec.md](../../../specs/004-traffic-channel/spec.md)
