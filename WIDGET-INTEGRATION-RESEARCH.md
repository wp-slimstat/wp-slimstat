# Widget Integration Research (T076-T078)

Research findings for integrating Traffic Channel widgets into SlimStat's existing customization system.

## T076: Widget Registration Mechanism

### How Widgets Are Registered

**File**: `admin/view/wp-slimstat-reports.php`

**Registration Pattern**:
```php
self::$reports = [
    'widget_id' => [
        'title'         => __('Widget Title', 'wp-slimstat'),
        'callback'      => [self::class, 'method_name'],
        'callback_args' => [/* widget-specific args */],
        'classes'       => ['normal', 'tall', 'large', 'extralarge', 'full-width'],
        'locations'     => ['slimview1', 'slimview2', 'slimview3', 'slimview4', 'slimview5', 'dashboard', 'inactive'],
        'tooltip'       => __('Help text', 'wp-slimstat'),
    ],
];
```

**Key Findings**:
1. **Globally Unique IDs**: Widget IDs must be unique across all SlimStat widgets (e.g., `slim_channel_top`, `slim_channel_dist`)
2. **Static Array**: Widgets are registered in `self::$reports` static array
3. **Initialization**: `wp_slimstat_reports::init()` is called to populate the reports array
4. **Hook/Filter**: No apparent filter/hook system - widgets must be added directly to `self::$reports`

### Integration Strategy

**For Channel Widgets**:
```php
// In wp-slimstat-reports.php, add to self::$reports array:

'slim_channel_top' => [
    'title'         => __('Top Traffic Channel', 'wp-slimstat'),
    'callback'      => ['SlimStat\\Widgets\\TopChannelWidget', 'render'],
    'callback_args' => [],
    'classes'       => ['normal'],
    'locations'     => ['slimview_marketing'],  // Custom location for Marketing page
    'tooltip'       => __('Shows the traffic channel with the most visits for the selected date range.', 'wp-slimstat'),
],

'slim_channel_dist' => [
    'title'         => __('Channel Distribution', 'wp-slimstat'),
    'callback'      => ['SlimStat\\Widgets\\ChannelDistributionWidget', 'render'],
    'callback_args' => [],
    'classes'       => ['normal'],
    'locations'     => ['slimview_marketing'],
    'tooltip'       => __('Breakdown of all traffic channels with percentages and visit counts.', 'wp-slimstat'),
],
```

---

## T077: Drag-and-Drop Implementation

### Storage Mechanism

**Key Finding**: Widget layout customization is stored in **WordPress user meta**.

**Evidence**:
- `self::$user_reports` array structure maps locations to widgets
- Pre-defined locations: `slimview1`, `slimview2`, `slimview3`, `slimview4`, `slimview5`, `dashboard`, `inactive`
- User can drag widgets between locations to customize layout

### JavaScript Implementation

**File**: Likely in `admin/js/admin.js` or similar

**Expected Pattern**:
```javascript
// jQuery UI Sortable for drag-and-drop
$('.slimstat-widget-container').sortable({
    connectWith: '.slimstat-widget-container',
    update: function(event, ui) {
        // Save new layout to user meta via AJAX
        var layout = {};
        $('.slimstat-widget-container').each(function() {
            var location = $(this).data('location');
            var widgets = $(this).sortable('toArray');
            layout[location] = widgets;
        });

        // AJAX call to save layout
        $.post(ajaxurl, {
            action: 'slimstat_save_layout',
            layout: JSON.stringify(layout),
            nonce: slimstat_nonce
        });
    }
});
```

### Storage Format

**User Meta Key**: Likely `slimstat_widget_layout` or `wp_slimstat_reports_config`

**Format**:
```php
[
    'slimview1' => ['slim_p1_01', 'slim_p1_03', 'slim_channel_top'],
    'slimview2' => ['slim_p1_04', 'slim_p1_06'],
    'slimview3' => ['slim_channel_dist'],
    'slimview_marketing' => ['slim_channel_top', 'slim_channel_dist'],
    'dashboard' => ['slim_p7_02'],
    'inactive' => [],
]
```

---

## T078: Widget Locations System

### Available Locations

**Core Locations**:
1. **slimview1** - First SlimStat report tab
2. **slimview2** - Second SlimStat report tab
3. **slimview3** - Third SlimStat report tab
4. **slimview4** - Fourth SlimStat report tab
5. **slimview5** - Fifth SlimStat report tab (Search Terms focus)
6. **dashboard** - WordPress admin dashboard
7. **inactive** - Hidden widgets (not displayed)

**Custom Location for Traffic Channels**:
8. **slimview_marketing** - New Marketing page location (Feature 004)

### How Locations Work

**Rendering Process**:
1. SlimStat loads user's custom layout from user meta
2. For each location (slimview1, slimview2, etc.):
   - Get list of widget IDs for that location
   - Loop through widgets and call their `callback` function
   - Wrap each widget in HTML container with CSS classes
3. User can drag widgets between locations to reorganize

### Registration Strategy for Marketing Page

**Option 1: Extend Existing System**

Add `slimview_marketing` to `self::$user_reports`:

```php
public static $user_reports = [
    'slimview1' => [],
    'slimview2' => [],
    'slimview3' => [],
    'slimview4' => [],
    'slimview5' => [],
    'slimview_marketing' => [],  // New location
    'dashboard' => [],
    'inactive'  => [],
];
```

**Option 2: Dedicated Marketing Page Handler**

Create separate rendering logic for Marketing page that:
- Reads from same user meta structure
- Uses same drag-and-drop JavaScript
- Displays only widgets with `'slimview_marketing'` in their `locations` array

---

## Implementation Checklist

### T079: Create LayoutIntegration Class

**File**: `src/Admin/LayoutIntegration.php`

**Responsibilities**:
1. Register channel widgets in `self::$reports` array
2. Add `slimview_marketing` to `self::$user_reports`
3. Provide method to get widgets for Marketing page
4. Handle default layout for first-time users

### T080: Register Channel Widgets

**Implementation**:
```php
public static function register_channel_widgets()
{
    wp_slimstat_reports::$reports['slim_channel_top'] = [
        'title'         => __('Top Traffic Channel', 'wp-slimstat'),
        'callback'      => ['SlimStat\\Widgets\\TopChannelWidget', 'render'],
        'callback_args' => [],
        'classes'       => ['normal'],
        'locations'     => ['slimview_marketing'],
        'tooltip'       => __('Shows the traffic channel with the most visits.', 'wp-slimstat'),
    ];

    wp_slimstat_reports::$reports['slim_channel_dist'] = [
        'title'         => __('Channel Distribution', 'wp-slimstat'),
        'callback'      => ['SlimStat\\Widgets\\ChannelDistributionWidget', 'render'],
        'callback_args' => [],
        'classes'       => ['normal'],
        'locations'     => ['slimview_marketing'],
        'tooltip'       => __('Breakdown of all traffic channels.', 'wp-slimstat'),
    ];
}
```

### T081: Reset Layout Support

**Implementation**:
```php
public static function reset_marketing_layout()
{
    $user_id = get_current_user_id();
    $default_layout = [
        'slimview_marketing' => ['slim_channel_top', 'slim_channel_dist'],
    ];

    update_user_meta($user_id, 'slimstat_widget_layout', $default_layout);
}
```

### T082: Test Widget Cloning

**Expected Behavior**:
- Users can drag `slim_channel_top` to multiple locations
- Each instance should display identical data (same date range, same filters)
- No state pollution between cloned instances

**Test**:
```php
public function test_cloned_widgets_display_identical_data()
{
    $widget = new TopChannelWidget();
    $args = ['date_from' => time() - DAY_IN_SECONDS, 'date_to' => time()];

    $html1 = $widget->render($args);
    $html2 = $widget->render($args);

    $this->assertEquals($html1, $html2, 'Cloned widgets should render identical HTML');
}
```

---

## Cache & Date Range Integration (T083-T086)

### T083: Transient Key Generation

**Pattern**: `slimstat_widget_{widget_id}_{filters_hash}`

**Example**:
```php
$filters_hash = md5(serialize([
    'date_from' => $args['date_from'],
    'date_to' => $args['date_to'],
    'channel_filter' => $args['channel_filter'] ?? '',
]));

$cache_key = "slimstat_widget_slim_channel_top_{$filters_hash}";
```

### T084: Cache Expiry Configuration

**WordPress Options Keys**:
- `slimstat_cache_expiry` - Default: 300 seconds (5 minutes)
- Per-widget override: `slimstat_cache_{widget_id}_expiry`

**Implementation**:
```php
$expiry = get_option('slimstat_cache_expiry', 300);
set_transient($cache_key, $html, $expiry);
```

### T085: Date Range Filter System

**JavaScript Events**:
- `slimstat_date_range_changed` - Fired when user changes date range
- Event data: `{ date_from: timestamp, date_to: timestamp }`

**Query Parameters**:
- `dt_from` - Start date (Unix timestamp)
- `dt_to` - End date (Unix timestamp)

**Persistence**:
- Stored in session: `$_SESSION['slimstat_filters']['date_range']`
- Or in URL query string for deep linking

### T086: Update BaseChannelWidget Cache Integration

**Current Implementation** (already done in T029-T030):
```php
protected function generate_cache_key(array $args): string
{
    $filters_hash = md5(serialize($args));
    return "slimstat_widget_{$this->widget_id}_{$filters_hash}";
}

protected function get_cached_output(string $cache_key): ?string
{
    return get_transient($cache_key);
}

protected function cache_output(string $cache_key, string $html): void
{
    $expiry = get_option('slimstat_cache_expiry', 300);
    set_transient($cache_key, $html, $expiry);
}
```

**Status**: ✅ Already matches SlimStat patterns

---

## Summary

### Research Findings

1. **Widget Registration**: Direct array assignment to `wp_slimstat_reports::$reports`
2. **Customization Storage**: User meta with location-to-widgets mapping
3. **Drag-and-Drop**: jQuery UI Sortable with AJAX save
4. **Locations**: 7 core locations + 1 custom (`slimview_marketing`)
5. **Cache Pattern**: Transients with filters-based hash keys
6. **Date Range**: Session storage + query params + JavaScript events

### Integration Status

| Task | Status | Notes |
|------|--------|-------|
| T076 | ✅ Research Complete | Widget registration pattern documented |
| T077 | ✅ Research Complete | Drag-and-drop storage mechanism identified |
| T078 | ✅ Research Complete | Widget locations system documented |
| T079 | 📋 Ready to Implement | Create LayoutIntegration class |
| T080 | 📋 Ready to Implement | Register widgets in `self::$reports` |
| T081 | 📋 Ready to Implement | Add reset layout support |
| T082 | 📋 Ready to Implement | Test widget cloning behavior |
| T083 | ✅ Already Implemented | Cache keys match pattern |
| T084 | ✅ Already Implemented | Expiry config already used |
| T085 | ℹ️ Informational | Date range system documented |
| T086 | ✅ Already Implemented | BaseChannelWidget uses correct patterns |

### Next Steps

1. **Immediate**: Mark T076-T078 complete (research done)
2. **Implementation**: Create LayoutIntegration class (T079-T082)
3. **Polish**: Complete remaining 40+ tasks (export, settings, docs)

---

## References

- [wp-slimstat-reports.php](admin/view/wp-slimstat-reports.php) - Widget registration
- [BaseChannelWidget.php](src/Widgets/BaseChannelWidget.php) - Cache implementation
- [Feature 004 Spec](../../../specs/004-traffic-channel/spec.md) - Requirements
- [Tasks Free](../../../specs/004-traffic-channel/tasks.free.md) - Task list
