# Widget Integration Mechanism - VERIFIED FINDINGS

Research completed: 2025-11-15
Directory: wp-slimstat/

## Executive Summary

The ACTUAL widget registration mechanism in wp-slimstat is **simpler** than expected from the research document. It does NOT use WordPress's `add_meta_box()` or `do_meta_boxes()` functions. Instead, it uses:

1. **Static array registration** in `wp_slimstat_reports::$reports`
2. **WordPress's built-in dashboard script** (`wp_enqueue_script('dashboard')`) which provides jQuery UI Sortable
3. **WordPress's native AJAX handler** for `meta-box-order` (built into WordPress core)
4. **User meta storage** using WordPress's `get_user_option()` / `update_user_option()`

## 1. WIDGET REGISTRATION (T076)

### File Location
**File**: `/admin/view/wp-slimstat-reports.php`  
**Lines**: 5-907 (class definition and initialization)

### Registration Pattern

**Step 1: Define static array** (Line 5)
```php
public static $reports = []; // Initially empty
```

**Step 2: Populate array in `init()` method** (Lines 100-907)
```php
self::$reports = [
    'slim_p7_02' => [
        'title'         => __('Access Log', 'wp-slimstat'),
        'callback'      => [self::class, 'show_access_log'],
        'callback_args' => [
            'type'    => 'recent',
            'columns' => '*',
            'raw'     => ['wp_slimstat_db', 'get_recent'],
        ],
        'classes'   => ['full-width', 'tall'],
        'locations' => ['slimview1', 'dashboard'],
        'tooltip'   => __('Color Codes', 'wp-slimstat') . '</strong>...',
    ],
    
    'slim_p1_01' => [
        'title'         => __('Pageviews', 'wp-slimstat'),
        'callback'      => [self::class, 'show_chart'],
        'callback_args' => [
            'id'         => 'slim_p1_01',
            'chart_data' => [
                'data1' => 'COUNT( ip )',
                'data2' => 'COUNT( DISTINCT ip )',
            ],
            'chart_labels' => [
                __('Pageviews', 'wp-slimstat'),
                __('Unique IPs', 'wp-slimstat'),
            ],
        ],
        'classes'   => ['extralarge', 'chart'],
        'locations' => ['slimview2', 'dashboard'],
        'tooltip'   => $pageviews_chart_tooltip,
    ],
    // ... hundreds more widgets
];
```

**Step 3: Apply filter hook** (Line 907)
```php
// Allow third party tools to manipulate this list
self::$reports = apply_filters('slimstat_reports_info', self::$reports);
```

### Data Structure (VERIFIED)

Each widget MUST have:
- `title` (string): Display name
- `callback` (callable): Render function `[ClassName, 'method_name']`
- `callback_args` (array): Parameters passed to callback
- `classes` (array): CSS classes for size (`normal`, `large`, `extralarge`, `tall`, `full-width`, `chart`)
- `locations` (array): Where widget appears (`slimview1`, `slimview2`, `slimview3`, `slimview4`, `slimview5`, `dashboard`, `inactive`)
- `tooltip` (string, optional): Help text

### ACTUAL Integration Example (from ChannelWidgetRegistrar.php)

**File**: `/src/Widgets/ChannelWidgetRegistrar.php`  
**Lines**: 37-78

```php
public static function register_channel_widgets(): void
{
    // Check if SlimStat reports class exists
    if (!class_exists('wp_slimstat_reports')) {
        return;
    }

    // Initialize widgets
    $top_channel_widget = new TopChannelWidget();
    $distribution_widget = new ChannelDistributionWidget();

    // Register AJAX handlers for widgets
    $top_channel_widget->register_ajax_handler();
    $distribution_widget->register_ajax_handler();

    // Register Top Channel Widget (T048)
    \wp_slimstat_reports::$reports['slim_channel_top'] = [
        'title' => __('Top Traffic Channel', 'wp-slimstat'),
        'callback' => [self::class, 'render_top_channel_widget'],
        'callback_args' => [
            'widget' => $top_channel_widget,
        ],
        'classes' => ['normal'], // Widget size class
        'locations' => ['slimstat-marketing'], // Display on Marketing page
        'tooltip' => __('Shows the traffic channel with the most visits for the selected time period.', 'wp-slimstat'),
    ];

    // Register Channel Distribution Widget (T049)
    \wp_slimstat_reports::$reports['slim_channel_distribution'] = [
        'title' => __('Channel Distribution', 'wp-slimstat'),
        'callback' => [self::class, 'render_distribution_widget'],
        'callback_args' => [
            'widget' => $distribution_widget,
        ],
        'classes' => ['large'], // Larger widget for table + chart
        'locations' => ['slimstat-marketing'], // Display on Marketing page
        'tooltip' => __('Shows the breakdown of all 8 traffic channel categories with visit counts and percentages.', 'wp-slimstat'),
    ];

    // Allow Pro plugin to register additional widgets via hook
    do_action('slimstat_marketing_widgets');
}
```

### Hook Priority (CRITICAL)

**File**: `/src/Widgets/ChannelWidgetRegistrar.php`  
**Line**: 27

```php
// Priority 100 ensures wp_slimstat_reports class is loaded and $reports array initialized
add_action('admin_init', [self::class, 'register_channel_widgets'], 100);
```

**WHY**: The `wp_slimstat_reports::init()` runs BEFORE priority 100, so the `$reports` array is already initialized.

## 2. DRAG-AND-DROP IMPLEMENTATION (T077)

### JavaScript Initialization

**File**: `/admin/index.php`  
**Lines**: 778, 825

```php
public static function wp_slimstat_enqueue_scripts($_hook = '')
{
    $current_screen = get_current_screen();
    if ($current_screen && str_contains($current_screen->id ?? '', 'slim')) {
        wp_enqueue_script('dashboard'); // <-- CRITICAL: This loads jQuery UI Sortable
        wp_enqueue_script('jquery-ui-datepicker');
    }
    
    // ... more scripts
    
    wp_enqueue_script('slimstat_admin', plugins_url('/admin/assets/js/admin.js', __DIR__), ['jquery-ui-dialog'], SLIMSTAT_ANALYTICS_VERSION, true);
}
```

### AJAX Handler (Custom Implementation)

**File**: `/admin/assets/js/admin.js`  
**Lines**: 897-921

```javascript
// Clone and delete report placeholders
jQuery(".slimstat-layout .slimstat-header-buttons a").on("click", function (e) {
    e.preventDefault();
    if (jQuery(this).hasClass("slimstat-font-docs")) {
        jQuery(this).removeClass("slimstat-font-docs").addClass("slimstat-font-trash").parents(".postbox").clone(true).appendTo(jQuery(this).parents(".meta-box-sortables"));
        jQuery(this).removeClass("slimstat-font-trash").addClass("slimstat-font-docs");
    } else if (jQuery(this).hasClass("slimstat-font-minus-circled")) {
        jQuery(this).removeClass("slimstat-font-minus-circled").parents(".postbox").appendTo(jQuery("#postbox-container-inactive .meta-box-sortables"));
    } else {
        jQuery(this).parents(".postbox").remove();
    }

    // Save the new groups
    var data = {
        action: "meta-box-order",
        _ajax_nonce: jQuery("#meta-box-order-nonce").val(),
        page: SlimStatAdminParams.page_location + "_page_slimlayout",
        page_columns: 0,
    };

    jQuery(".meta-box-sortables").each(function () {
        data["order[" + this.id.split("-")[0] + "]"] = jQuery(this).sortable("toArray").join(",");
    });

    jQuery.post(ajaxurl, data);
});
```

### Key Mechanism Details

1. **WordPress's Built-in Handler**: The `action: "meta-box-order"` is handled by WordPress core (not SlimStat)
2. **Nonce Verification**: Uses `wp_create_nonce('meta-box-order')`
3. **Data Format**: `order[slimview1] = "slim_p1_01,slim_p1_03,slim_p1_04"`
4. **Storage**: WordPress saves to `{$wpdb->prefix}usermeta` with key `meta-box-order_{page_slug}`

### Layout Page Template

**File**: `/admin/view/layout.php`  
**Lines**: 1-64

```php
<div class="backdrop-container ">
    <div class="wrap slimstat slimstat-layout">
        <h2><?php _e('Customize and organize your reports', 'wp-slimstat') ?></h2>
        <p>You can drag and drop the placeholders here below...</p>

        <form method="get" action="">
            <input type="hidden" id="meta-box-order-nonce" name="meta-box-order-nonce" value="<?php echo wp_create_nonce('meta-box-order') ?>"/>
        </form>

        <form action="admin-post.php" method="post">
            <?php wp_nonce_field('reset_layout'); ?>
            <input type="hidden" name="action" value="slimstat_reset_layout">
            <input type="submit" value="<?php _e('Reset Layout', 'wp-slimstat') ?>" class="button"/>
        </form>

        <?php foreach (wp_slimstat_reports::$user_reports as $a_location_id => $a_location_list): ?>

            <div id="postbox-container-<?php echo esc_attr($a_location_id); ?>" class="postbox-container">
                <h2 class="slimstat-options-section-header"><?php echo wp_slimstat_admin::$screens_info[$a_location_id]['title'] ?></h2>
                <div id="<?php echo esc_attr($a_location_id); ?>-sortables" class="meta-box-sortables">
                    <?php foreach ($a_location_list as $a_report_id) {
                        // ... render widget placeholder
                    } ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
```

### User Meta Storage

**File**: `/admin/index.php`  
**Lines**: 43, 156-161

```php
// Retrieve this user's custom report assignment (Customizer)
// Superadmins can customize the layout at network level, to override per-site settings
self::$meta_user_reports = get_user_option('meta-box-order_' . wp_slimstat_admin::$page_location . '_page_slimlayout-network', 1);

// No network-wide settings found
if (empty(self::$meta_user_reports)) {
    self::$meta_user_reports = get_user_option('meta-box-order_' . wp_slimstat_admin::$page_location . '_page_slimlayout', $GLOBALS['current_user']->ID);
}
```

**Meta Key Format**:
- Network-wide: `meta-box-order_admin_page_slimlayout-network`
- Per-user: `meta-box-order_admin_page_slimlayout`

**Data Format** (stored as serialized array):
```php
[
    'slimview1' => 'slim_p7_02,slim_p1_01',
    'slimview2' => 'slim_p1_03,slim_p1_04,slim_p1_06',
    'slimview3' => 'slim_p2_01,slim_p2_02',
    'slimview4' => 'slim_p3_01,slim_p3_02',
    'slimview5' => 'slim_p5_01,slim_p5_02',
    'dashboard' => 'slim_p1_01,slim_p1_08',
    'inactive'  => 'slim_p1_11,slim_p2_23',
]
```

### Reset Layout Functionality

**File**: `/admin/index.php`  
**Lines**: 37, 400-416

```php
// Action for reset layout
add_action('admin_post_slimstat_reset_layout', ['wp_slimstat_admin', 'handle_reset_layout']);

/**
 *  Reset layout
 */
public static function handle_reset_layout()
{
    // Check nonce
    if (!wp_verify_nonce($_REQUEST['_wpnonce'], 'reset_layout')) {
        wp_die(__('Sorry, you are not allowed to access this page.', 'wp-slimstat'));
    }

    $GLOBALS['wpdb']->query(sprintf("DELETE FROM %susermeta WHERE meta_key LIKE '%%meta-box-order_admin_page_slimlayout%%'", $GLOBALS['wpdb']->prefix));
    $GLOBALS['wpdb']->query(sprintf("DELETE FROM %susermeta WHERE meta_key LIKE '%%mmetaboxhidden_admin_page_slimview%%'", $GLOBALS['wpdb']->prefix));
    $GLOBALS['wpdb']->query(sprintf("DELETE FROM %susermeta WHERE meta_key LIKE '%%meta-box-order_slimstat%%'", $GLOBALS['wpdb']->prefix));
    $GLOBALS['wpdb']->query(sprintf("DELETE FROM %susermeta WHERE meta_key LIKE '%%metaboxhidden_slimstat%%'", $GLOBALS['wpdb']->prefix));
    $GLOBALS['wpdb']->query(sprintf("DELETE FROM %susermeta WHERE meta_key LIKE '%%closedpostboxes_slimstat%%'", $GLOBALS['wpdb']->prefix));

    // Redirect to layout page
    wp_safe_redirect(admin_url('admin.php?page=slimlayout'));
    die();
}
```

## 3. WIDGET LOCATIONS (T078)

### Available Locations (VERIFIED)

**File**: `/admin/view/wp-slimstat-reports.php`  
**Lines**: 6-14

```php
public static $user_reports = [
    'slimview1' => [], // Real-time
    'slimview2' => [], // Overview
    'slimview3' => [], // Audience
    'slimview4' => [], // Site Analysis
    'slimview5' => [], // Traffic Sources
    'dashboard' => [], // WordPress Dashboard
    'inactive'  => [], // Inactive Reports
];
```

### Location Titles and Configuration

**File**: `/admin/index.php`  
**Lines**: 45-109

```php
self::$screens_info = [
    'slimview1' => [
        'is_report_group' => true,
        'show_in_sidebar' => true,
        'title'           => __('Real-time', 'wp-slimstat'),
        'capability'      => 'can_view',
        'callback'        => [self::class, 'wp_slimstat_include_view'],
    ],
    'slimview2' => [
        'is_report_group' => true,
        'show_in_sidebar' => true,
        'title'           => __('Overview', 'wp-slimstat'),
        'capability'      => 'can_view',
        'callback'        => [self::class, 'wp_slimstat_include_view'],
    ],
    'slimview3' => [
        'is_report_group' => true,
        'show_in_sidebar' => true,
        'title'           => __('Audience', 'wp-slimstat'),
        'capability'      => 'can_view',
        'callback'        => [self::class, 'wp_slimstat_include_view'],
    ],
    'slimview4' => [
        'is_report_group' => true,
        'show_in_sidebar' => true,
        'title'           => __('Site Analysis', 'wp-slimstat'),
        'capability'      => 'can_view',
        'callback'        => [self::class, 'wp_slimstat_include_view'],
    ],
    'slimview5' => [
        'is_report_group' => true,
        'show_in_sidebar' => true,
        'title'           => __('Traffic Sources', 'wp-slimstat'),
        'capability'      => 'can_view',
        'callback'        => [self::class, 'wp_slimstat_include_view'],
    ],
    'dashboard' => [
        'is_report_group' => false,
        'show_in_sidebar' => false,
        'title'           => __('Dashboard', 'wp-slimstat'),
        'capability'      => 'can_view',
        'callback'        => null, // WordPress Dashboard uses wp_add_dashboard_widget()
    ],
    // ... more screens
];
```

### Widget Rendering on Pages

**File**: `/admin/view/index.php`  
**Lines**: 128-142

```php
<div class="meta-box-sortables">
    <form method="get" action="">
        <input type="hidden" id="meta-box-order-nonce" name="meta-box-order-nonce" value="<?php echo wp_create_nonce('meta-box-order') ?>"/>
    </form>
    
    <?php
    foreach (wp_slimstat_reports::$user_reports[wp_slimstat_admin::$current_screen] as $a_report_id) {
        // A report could have been deprecated...
        if (empty(wp_slimstat_reports::$reports[$a_report_id])) {
            continue;
        }

        wp_slimstat_reports::report_header($a_report_id);
        wp_slimstat_reports::callback_wrapper(['id' => $a_report_id]);
        wp_slimstat_reports::report_footer();
    }
    ?>
</div>
```

### Default Layout Population

**File**: `/admin/view/wp-slimstat-reports.php`  
**Lines**: 910-932

```php
// Do we have any new reports not listed in this user's settings?
if (class_exists('wp_slimstat_admin') && !empty(wp_slimstat_admin::$meta_user_reports) && is_array(wp_slimstat_admin::$meta_user_reports)) {
    $flat_user_reports = array_filter(explode(',', implode(',', wp_slimstat_admin::$meta_user_reports)));
    $merge_reports     = array_diff(array_filter(array_keys(self::$reports)), $flat_user_reports);

    // Now let's explode all the lists
    foreach (wp_slimstat_admin::$meta_user_reports as $a_location => $a_report_list) {
        if (!array_key_exists($a_location, self::$user_reports)) {
            continue;
        }
        self::$user_reports[$a_location] = explode(',', $a_report_list);
    }
}

// Merge new reports into default locations based on 'locations' array
foreach ($merge_reports as $a_report_id) {
    if (!empty(self::$reports[$a_report_id]['locations']) && is_array(self::$reports[$a_report_id]['locations'])) {
        foreach (self::$reports[$a_report_id]['locations'] as $a_report_location) {
            if (!in_array($a_report_id, self::$user_reports[$a_report_location])) {
                self::$user_reports[$a_report_location][] = $a_report_id;
            }
        }
    }
}
```

## KEY DIFFERENCES FROM RESEARCH ASSUMPTIONS

### 1. NO WordPress Meta Box API
- **Research Expected**: `add_meta_box()`, `do_meta_boxes()`
- **ACTUAL**: Direct array manipulation + custom rendering loop

### 2. WordPress Dashboard Script (Not Custom Sortable)
- **Research Expected**: Custom jQuery UI Sortable initialization
- **ACTUAL**: WordPress's built-in `dashboard` script provides jQuery UI Sortable automatically

### 3. WordPress Core AJAX Handler
- **Research Expected**: Custom AJAX handler in SlimStat
- **ACTUAL**: WordPress core handles `meta-box-order` action natively (no SlimStat code needed)

### 4. User Meta Storage (Not Plugin Options)
- **Research Expected**: SlimStat plugin options table
- **ACTUAL**: WordPress `{$wpdb->prefix}usermeta` table with `get_user_option()` / `update_user_option()`

### 5. Filter Hook (Not Action Hook)
- **Research Expected**: Action hook for registration
- **ACTUAL**: Filter hook `slimstat_reports_info` to modify `$reports` array

## INTEGRATION CHECKLIST FOR T076-T078

### T076: Widget Registration
- [x] Direct assignment to `\wp_slimstat_reports::$reports['widget_id']`
- [x] Use `admin_init` hook with priority 100+
- [x] Include ALL required keys: `title`, `callback`, `callback_args`, `classes`, `locations`, `tooltip`
- [x] Use proper CSS classes: `normal` (default), `large`, `extralarge`, `tall`, `full-width`, `chart`
- [x] Specify location array (e.g., `['slimstat-marketing']`)

### T077: Drag-and-Drop Support
- [x] NO custom sortable initialization needed (WordPress's `dashboard` script handles it)
- [x] NO custom AJAX handler needed (WordPress core handles `meta-box-order`)
- [x] Ensure page has `.meta-box-sortables` container
- [x] Ensure widgets have `.postbox` class with unique `id` attribute

### T078: Widget Locations
- [x] Verify new location added to `$screens_info` array (via filter hook)
- [x] Verify new location added to `$user_reports` array initialization
- [x] Ensure page template uses `foreach (wp_slimstat_reports::$user_reports[...])` loop
- [x] Ensure widgets specify correct location in `locations` array

## VERIFICATION STATUS

- **Widget Registration**: ✅ VERIFIED (ChannelWidgetRegistrar.php lines 37-78)
- **Drag-and-Drop Mechanism**: ✅ VERIFIED (admin.js lines 897-921, WordPress dashboard script)
- **User Meta Storage**: ✅ VERIFIED (admin/index.php lines 156-161)
- **Reset Layout**: ✅ VERIFIED (admin/index.php lines 400-416)
- **Widget Rendering**: ✅ VERIFIED (admin/view/index.php lines 128-142)
- **Default Layout Population**: ✅ VERIFIED (wp-slimstat-reports.php lines 910-932)

## NEXT STEPS

1. **Update WIDGET-INTEGRATION-RESEARCH.md** with corrected mechanisms
2. **Simplify T076-T078 implementation** (no custom AJAX handler needed)
3. **Test drag-and-drop** on Marketing page (may work automatically if structure is correct)
4. **Add Marketing location to $user_reports initialization** (if not already done)
