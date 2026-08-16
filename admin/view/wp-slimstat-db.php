<?php

use SlimStat\Utils\Query;
use SlimStat\Utils\NetworkMerge;
use SlimStat\Components\DateRangeHelper;

// Let's define the main class with all the methods that we need
class wp_slimstat_db
{
    // Filters
    public static $columns_names = [];

    public static $operator_names = [];

    // Operators that take no value by design (is_empty/is_not_empty). Shared with
    // parse_filters(), fs_url(), and the JS layer (via SlimStatAdminParams) so the
    // list never drifts between callsites. See #305.
    public static $valueless_operators = ['is_empty', 'is_not_empty'];

    // parse_filters() switch keys that map to the date/misc buckets rather than a data
    // column. A value-less operator (is_empty/is_not_empty) is meaningless for these and
    // must not reach the switch without a value — those branches dereference $a_filter[3]
    // and would emit an "Undefined array key 3" warning on a crafted request. See #305.
    // Public + shared with the JS layer (via SlimStatAdminParams) so SlimStatGetFiltersForAjax()
    // strips the same date/misc keys when harvesting column filters for a sub-report. (#22)
    public const NON_COLUMN_FILTER_KEYS = [
        'strtotime', 'minute', 'hour', 'day', 'month', 'year',
        'interval', 'interval_hours', 'interval_minutes', 'limit_results', 'start_from',
    ];

    public static $filters_normalized = [];

    // Structure that maps filters to SQL information (table names, clauses, lookup tables, etc)
    public static $sql_where = ['columns' => '', 'time_range' => ''];

    // Filters that are not visible in the dropdown
    public static $all_columns_names = [];

    // Debug message
    public static $debug_message = '';

    // Useful data for the reports
    public static $pageviews = 0;

    /*
     * Sets the filters and other structures needed to store the data retrieved from the DB
     */
    public static function init($_filters = '')
    {
        // List of supported filters and their user-friendly names
        self::$columns_names = [
            'browser'              => [__('Browser', 'wp-slimstat'), 'varchar'],
            'country'              => [__('Country Code', 'wp-slimstat'), 'varchar'],
            'ip'                   => [__('IP Address', 'wp-slimstat'), 'varchar'],
            'searchterms'          => [__('Search Terms', 'wp-slimstat'), 'varchar'],
            'language'             => [__('Language', 'wp-slimstat'), 'varchar'],
            'platform'             => [__('Operating System', 'wp-slimstat'), 'varchar'],
            'resource'             => [__('Permalink', 'wp-slimstat'), 'varchar'],
            'referer'              => [__('Referer', 'wp-slimstat'), 'varchar'],
            'username'             => [__("Visitor's Username", 'wp-slimstat'), 'varchar'],
            'email'                => [__("Visitor's Email", 'wp-slimstat'), 'varchar'],
            'outbound_resource'    => [__('Outbound Link', 'wp-slimstat'), 'varchar'],
            'tz_offset'            => [__('Timezone Offset', 'wp-slimstat'), 'int'],
            'fingerprint'          => [__('Fingerprint', 'wp-slimstat'), 'varchar'],
            'page_performance'     => [__('Page Speed', 'wp-slimstat'), 'int'],
            'no_filter_selected_2' => ['', 'none'],
            'no_filter_selected_3' => [__('-- Advanced filters --', 'wp-slimstat'), 'none'],
            'browser_version'      => [__('Browser Version', 'wp-slimstat'), 'varchar'],
            'browser_type'         => [__('Browser Type', 'wp-slimstat'), 'int'],
            'user_agent'           => [__('User Agent', 'wp-slimstat'), 'varchar'],
            'city'                 => [__('City', 'wp-slimstat'), 'varchar'],
            'location'             => [__('Coordinates', 'wp-slimstat'), 'varchar'],
            'notes'                => [__('Annotations', 'wp-slimstat'), 'varchar'],
            'server_latency'       => [__('Server Latency', 'wp-slimstat'), 'int'],
            'author'               => [__('Post Author', 'wp-slimstat'), 'varchar'],
            'category'             => [__('Post Category ID', 'wp-slimstat'), 'varchar'],
            'other_ip'             => [__('Originating IP', 'wp-slimstat'), 'varchar'],
            'content_type'         => [__('Resource Content Type', 'wp-slimstat'), 'varchar'],
            'content_id'           => [__('Resource ID', 'wp-slimstat'), 'int'],
            'screen_width'         => [__('Screen Width', 'wp-slimstat'), 'int'],
            'screen_height'        => [__('Screen Height', 'wp-slimstat'), 'int'],
            'resolution'           => [__('Viewport Size', 'wp-slimstat'), 'varchar'],
            'visit_id'             => [__('Visit ID', 'wp-slimstat'), 'int'],
        ];

        if ('on' == wp_slimstat::$settings['geolocation_country']) {
            unset(self::$columns_names['city']);
            unset(self::$columns_names['location']);
        }

        // List of supported filters and their friendly names
        self::$operator_names = [
            'equals'           => __('equals', 'wp-slimstat'),
            'is_not_equal_to'  => __('is not equal to', 'wp-slimstat'),
            'contains'         => __('contains', 'wp-slimstat'),
            'includes_in_set'  => __('is included in', 'wp-slimstat'),
            'does_not_contain' => __('does not contain', 'wp-slimstat'),
            'starts_with'      => __('starts with', 'wp-slimstat'),
            'ends_with'        => __('ends with', 'wp-slimstat'),
            'sounds_like'      => __('sounds like', 'wp-slimstat'),
            'is_greater_than'  => __('is greater than', 'wp-slimstat'),
            'is_less_than'     => __('is less than', 'wp-slimstat'),
            'between'          => __('is between (x,y)', 'wp-slimstat'),
            'matches'          => __('matches', 'wp-slimstat'),
            'does_not_match'   => __('does not match', 'wp-slimstat'),
            'is_empty'         => __('is empty', 'wp-slimstat'),
            'is_not_empty'     => __('is not empty', 'wp-slimstat'),
        ];

        // The following filters will not be displayed in the dropdown
        self::$all_columns_names = array_merge([
            // Date and Time
            'minute'           => [__('Minute', 'wp-slimstat'), 'int'],
            'hour'             => [__('Hour', 'wp-slimstat'), 'int'],
            'day'              => [__('Day', 'wp-slimstat'), 'int'],
            'month'            => [__('Month', 'wp-slimstat'), 'int'],
            'year'             => [__('Year', 'wp-slimstat'), 'int'],
            'interval'         => [__('days', 'wp-slimstat'), 'int'],
            'interval_hours'   => [__('hours', 'wp-slimstat'), 'int'],
            'interval_minutes' => [__('minutes', 'wp-slimstat'), 'int'],
            'dt'               => [__('Timestamp', 'wp-slimstat'), 'int'],
            'dt_out'           => [__('Exit Timestamp', 'wp-slimstat'), 'int'],

            // Other columns
            'metric'       => [__('Metric', 'wp-slimstat'), 'varchar'],
            'value'        => [__('Value', 'wp-slimstat'), 'varchar'],
            'counthits'    => [__('Hits', 'wp-slimstat'), 'int'],
            'column_group' => [__('Grouped Value', 'wp-slimstat'), 'varchar'],
            'percentage'   => [__('Percentage', 'wp-slimstat'), 'int'],
            'tooltip'      => [__('Notes', 'wp-slimstat'), 'varchar'],
            'details'      => [__('Notes', 'wp-slimstat'), 'varchar'],

            // Events
            'event_id'          => [__('Event ID', 'wp-slimstat'), 'int'],
            'type'              => [__('Type', 'wp-slimstat'), 'int'],
            'event_description' => [__('Event Description', 'wp-slimstat'), 'varchar'],
            'position'          => [__('Event Coordinates', 'wp-slimstat'), 'int'],

            'limit_results' => [__('Max Results', 'wp-slimstat'), 'int'],
            'start_from'    => [__('Offset', 'wp-slimstat'), 'int'],

            // Misc Filters
            'strtotime' => [0, 'int'],
        ], self::$columns_names);

        // Allow third party plugins to add even more column names to the array
        self::$all_columns_names = apply_filters('slimstat_column_names', self::$all_columns_names);

        // Filters use the following format: browser equals Firefox&&&country contains gb
        $filters_array = [];

        // Handle type parameter for date presets and custom ranges
        if (isset($_GET['type'])) {
            // Sanitize the type parameter to prevent XSS
            $type = sanitize_key($_GET['type']);

            if ($type !== 'custom') {
                // Handle preset types
                // Validate that the type is a valid preset before using it
                $valid_presets = ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month',
                                  'last_7_days', 'last_28_days', 'last_30_days', 'last_90_days',
                                  'last_6_months', 'this_year'];

                if (in_array($type, $valid_presets, true)) {
                    $preset_range = DateRangeHelper::get_range_by_preset($type);
                    if ($preset_range) {
                        $filters_array['strtotime'] = 'strtotime equals ' . sanitize_text_field(wp_date('Y-m-d', $preset_range['end']));
                        // Calculate days by normalizing to midnight to avoid DST issues
                        $start_day = strtotime(wp_date('Y-m-d', $preset_range['start']));
                        $end_day = strtotime(wp_date('Y-m-d', $preset_range['end']));
                        $interval_days = (($end_day - $start_day) / 86400) + 1;
                        $filters_array['interval'] = 'interval equals -' . absint($interval_days);
                    }
                }
            } elseif (isset($_GET['from']) && isset($_GET['to'])) {
                // Sanitize date inputs to prevent XSS
                $from_date = sanitize_text_field($_GET['from']);
                $to_date = sanitize_text_field($_GET['to']);

                // Validate date format (YYYY-MM-DD)
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) {
                    // Calculate interval days directly from the date strings
                    $start_day = strtotime($from_date);
                    $end_day = strtotime($to_date);

                    // Basic validation
                    if ($start_day && $end_day && $start_day <= $end_day) {
                        $interval_days = (($end_day - $start_day) / 86400) + 1;

                        // Use the date strings directly without converting back and forth
                        $filters_array['strtotime'] = 'strtotime equals ' . $to_date;
                        $filters_array['interval'] = 'interval equals -' . absint($interval_days);
                    }
                }
            }
        }

        // Filters are set via javascript as hidden fields and submitted as a POST request. They override anything passed through the regular input fields
        if (!empty($_REQUEST['fs']) && is_array($_REQUEST['fs'])) {
            foreach ($_REQUEST['fs'] as $a_request_filter_name => $a_request_filter_value) {
                $safe_name  = sanitize_text_field(wp_unslash($a_request_filter_name));
                $safe_value = str_replace('&&&', '', sanitize_text_field(wp_unslash($a_request_filter_value)));
                $filters_array[$safe_name] = sprintf('%s %s', $safe_name, $safe_value);
            }
        }

        // Date filters (input fields) - Please note: interval_minutes is not exposed via the web interface, that's why it's not listed here below
        foreach (['hour', 'day', 'month', 'year', 'interval', 'interval_hours'] as $a_date_time_filter_name) {
            if (isset($_POST[$a_date_time_filter_name]) && strlen($_POST[$a_date_time_filter_name]) > 0) { // here we use isset instead of !empty to handle ZERO as a valid input value
                $filters_array[$a_date_time_filter_name] = $a_date_time_filter_name . ' equals ' . intval($_POST[$a_date_time_filter_name]);
            }
        }

        // Fields and drop downs
        if (!empty($_POST['f']) && !empty($_POST['o'])) {
            $filters_array[sanitize_text_field($_POST['f'])] = sprintf('%s %s ', sanitize_text_field($_POST[ 'f' ]), sanitize_text_field($_POST[ 'o' ])) . (isset($_POST['v']) ? sanitize_text_field($_POST['v']) : '');
        }

        // Filters set via the plugin options
        if ('on' == wp_slimstat::$settings['restrict_authors_view'] && !current_user_can('manage_options') && !empty($GLOBALS['current_user']->user_login)) {
            $filters_array['author'] = 'author equals ' . $GLOBALS['current_user']->user_login;
        }

        if ([] !== $filters_array) {
            $filters_raw = implode('&&&', $filters_array);
        }

        // Filters are defined as: browser equals Chrome&&&country starts_with en
        if (!isset($filters_raw) || !is_string($filters_raw)) {
            $filters_raw = '';
        }

        if (!empty($_filters) && is_string($_filters)) {
            if ('' !== $filters_raw && '0' !== $filters_raw) {
                $filters_raw = '' === $filters_raw || '0' === $filters_raw ? $_filters : $_filters . '&&&' . $filters_raw;
            } else {
                $filters_raw = $_filters;
            }
        }

        // Hook for the... filters
        $filters_raw = apply_filters('slimstat_db_pre_filters', $filters_raw);

        // Normalize the filters
        self::$filters_normalized = self::init_filters($filters_raw);

        // Retrieve data that will be used by multiple reports
        if (empty($_REQUEST['page']) || false !== strpos($_REQUEST['page'], 'slimview')) {
            self::$pageviews = wp_slimstat_db::count_records();
        }
    }

    // end init

    /**
     * Builds the array of WHERE clauses to be used later in our SQL queries
     */
    protected static function _get_sql_where($_filters_normalized = [], $_slim_stats_table_alias = '')
    {
        $sql_array = [];

        foreach ($_filters_normalized as $a_filter_column => $a_filter_data) {
            // Add-ons can set their own custom filters, which are ignored here
            if (false !== strpos($a_filter_column, 'addon_')) {
                continue;
            }

            $sql_array[] = self::get_single_where_clause($a_filter_column, $a_filter_data[0], $a_filter_data[1], $_slim_stats_table_alias);
        }

        // Flatten array
        if ([] !== $sql_array) {
            return implode(' AND ', $sql_array);
        }

        return '';
    }

    public static function get_combined_where($_where = '', $_column = '*', $_use_date_filters = true, $_slim_stats_table_alias = '', $where_params = null)
    {
        global $wpdb;

        $dt_with_alias = 'dt';
        if (!empty($_slim_stats_table_alias)) {
            $dt_with_alias = $_slim_stats_table_alias . '.' . $dt_with_alias;
        }

        $time_range_condition = '';
        if (empty($_where)) {
            if (!empty(self::$filters_normalized['columns'])) {
                $_where = self::_get_sql_where(self::$filters_normalized['columns'], $_slim_stats_table_alias);

                if ($_use_date_filters) {
                    // Use $wpdb->prepare() for all dynamic SQL values
                    $time_range_condition = $wpdb->prepare(
                        $dt_with_alias . ' BETWEEN %d AND %d',
                        intval(self::$filters_normalized['utime']['start']),
                        intval(self::$filters_normalized['utime']['end'])
                    );
                }
            } elseif ($_use_date_filters) {
                // Use $wpdb->prepare() for all dynamic SQL values
                $time_range_condition = $wpdb->prepare(
                    $dt_with_alias . ' BETWEEN %d AND %d',
                    intval(self::$filters_normalized['utime']['start']),
                    intval(self::$filters_normalized['utime']['end'])
                );
            }

            // This could happen if we have custom filters (add-ons, third party tools)
            if (empty($_where)) {
                $_where = '1=1';
            }
        } else {
            if ('1=1' != $_where && !empty(self::$filters_normalized['columns'])) {
                $new_clause = self::_get_sql_where(self::$filters_normalized['columns'], $_slim_stats_table_alias);

                // This condition could be empty if it's related to a custom column
                if (!empty($new_clause)) {
                    $_where = sprintf('(%s) AND %s', $_where, $new_clause);
                }
            }

            if ($_use_date_filters) {
                // Use $wpdb->prepare() for all dynamic SQL values
                $time_range_condition = $wpdb->prepare(
                    $dt_with_alias . ' BETWEEN %d AND %d',
                    intval(self::$filters_normalized['utime']['start']),
                    intval(self::$filters_normalized['utime']['end'])
                );
            }
        }

        if (!empty($_where) && ('' !== $time_range_condition && '0' !== $time_range_condition)) {
            // Parenthesise the caller's clause. SQL binds AND tighter than OR, so
            // `A OR B` + ` AND range` silently becomes `A OR (B AND range)` — a filter
            // that applies to half the condition. (D62)
            $_where = sprintf('(%s) AND %s', $_where, $time_range_condition);
        } else {
            $_where = trim(sprintf('%s %s', $_where, $time_range_condition));
        }

        if (!empty($_column) && !empty(self::$columns_names[$_column])) {
            $column_with_alias = $_column;
            if (!empty($_slim_stats_table_alias)) {
                $column_with_alias = $_slim_stats_table_alias . '.' . $column_with_alias;
            }

            $filter_empty     = $column_with_alias . ' ' . (('varchar' == self::$columns_names[$_column][1]) ? 'IS NULL' : '= 0');
            $filter_not_empty = $column_with_alias . ' ' . (('varchar' == self::$columns_names[$_column][1]) ? 'IS NOT NULL' : '<> 0');

            if (false === strpos($_where, $filter_empty) && false === strpos($_where, $filter_not_empty)) {
                // Same reason as above, and it matters more here: with the caller's
                // clause on the RIGHT of the AND, `col IS NOT NULL AND A OR B` groups as
                // `(col IS NOT NULL AND A) OR B`, so B escapes the filter entirely.
                $_where = sprintf('%s AND (%s)', $filter_not_empty, $_where);
            }
        }

        // If where_param is provided and where contains %s or %d, use prepare
        if (null !== $where_params && (false !== strpos($_where, '%s') || false !== strpos($_where, '%d'))) {
            global $wpdb;
            $_where = is_array($where_params) ? $wpdb->prepare($_where, ...$where_params) : $wpdb->prepare($_where, $where_params);
        }

        return $_where;
    }

    /**
     * Translates user-friendly operators into SQL conditions
     */
    public static function get_single_where_clause($_dimension = 'id', $_operator = 'equals', $_value = '', $_slim_stats_table_alias = '')
    {
        // Auto-upgrade operators for multi-value columns where exact match
        // never works (values stored as concatenated strings in a single field).
        $multi_value_like_columns = ['outbound_resource', 'notes'];
        if ($_operator === 'equals' && in_array($_dimension, $multi_value_like_columns, true)) {
            $_operator = 'contains';
        }
        if ($_operator === 'is_not_equal_to' && in_array($_dimension, $multi_value_like_columns, true)) {
            $_operator = 'does_not_contain';
        }
        // Category uses comma-separated IDs — use LIKE for substring matching.
        if ($_operator === 'equals' && $_dimension === 'category') {
            $_operator = 'contains';
        }
        if ($_operator === 'is_not_equal_to' && $_dimension === 'category') {
            $_operator = 'does_not_contain';
        }

        $filter_empty     = (!empty(self::$columns_names[$_dimension]) && 'varchar' == self::$columns_names[$_dimension][1]) ? 'IS NULL' : '= 0';
        $filter_not_empty = (!empty(self::$columns_names[$_dimension]) && 'varchar' == self::$columns_names[$_dimension][1]) ? 'IS NOT NULL' : '<> 0';

        $column_with_alias = $_dimension;
        if (!empty($_slim_stats_table_alias)) {
            $column_with_alias = $_slim_stats_table_alias . '.' . $_dimension;
        }

        switch ($_dimension) {
            case 'ip':
            case 'other_ip':
                $filter_empty = '= "0.0.0.0"';
                break;
            default:
                break;
        }

        if ('resource' == $_dimension) {
            $_value = implode('/', array_map('urlencode', explode('/', $_value)));
        }

        $where = ['', htmlentities($_value, ENT_QUOTES, 'UTF-8')];

        switch ($_operator) {
            case 'is_not_equal_to':
                $where[0] = sprintf('%s <> %%s', $column_with_alias);
                break;

            case 'contains':
                $where = [sprintf('%s LIKE %%s', $column_with_alias), '%' . $_value . '%'];
                break;

            case 'includes_in_set':
            case 'included_in_set':
                $where[0] = sprintf('FIND_IN_SET( %s, %%s ) > 0', $column_with_alias);
                break;

            case 'does_not_contain':
                $where = [sprintf('%s NOT LIKE %%s', $column_with_alias), '%' . $_value . '%'];
                break;

            case 'starts_with':
                $where = [sprintf('%s LIKE %%s', $column_with_alias), $_value . '%'];
                break;

            case 'ends_with':
                $where = [sprintf('%s LIKE %%s', $column_with_alias), '%' . $_value];
                break;

            case 'sounds_like':
                $where[0] = sprintf('SOUNDEX( %s ) = SOUNDEX( %%s )', $column_with_alias);
                break;

            case 'is_empty':
                $where = [sprintf('%s %s', $column_with_alias, $filter_empty), ''];
                break;

            case 'is_not_empty':
                $where = [sprintf('%s %s', $column_with_alias, $filter_not_empty), ''];
                break;

            case 'is_greater_than':
                $where[0] = sprintf('%s > %%s', $column_with_alias);
                break;

            case 'is_less_than':
                $where[0] = sprintf('%s < %%s', $column_with_alias);
                break;

            case 'between':
                $range = explode(',', $_value);
                $where[0] = sprintf('%s BETWEEN %%d AND %%d', $column_with_alias);
                $where[1] = [intval($range[0]), intval($range[1])];
                break;

            case 'matches':
                $where[0] = sprintf('%s REGEXP %%s', $column_with_alias);
                break;

            case 'does_not_match':
                $where[0] = sprintf('%s NOT REGEXP %%s', $column_with_alias);
                break;

            default:
                $where[0] = sprintf('%s = %%s', $column_with_alias);
                break;
        }

        if (isset($where[1]) && '' != $where[1]) {
            // Handle array of values for operators like 'between'
            if (is_array($where[1])) {
                return $GLOBALS['wpdb']->prepare($where[0], ...$where[1]);
            }
            return $GLOBALS['wpdb']->prepare($where[0], $where[1]);
        } else {
            return $where[0];
        }
    }

    /**
     * Helper to enable caching on a Query object if the date range does not include today.
     *
     * @param Query $query
     */
    protected static function maybe_enable_query_cache($query)
    {
        // Use the end date from normalized filters (if available)
        if (!empty(self::$filters_normalized['utime']['end'])) {
            // Convert to Y-m-d for comparison (Query expects string date)
            $to = wp_date('Y-m-d', self::$filters_normalized['utime']['end']);
            if (method_exists($query, 'canUseCacheForDateRange')) {
                $query->canUseCacheForDateRange($to);
            }
        }
    }

    public static function get_results($_sql = '', $_select_no_aggregate_values = '', $_order_by = '', $_group_by = '', $_aggregate_values_add = '')
    {
        $_sql = apply_filters('slimstat_get_results_sql', $_sql, $_select_no_aggregate_values, $_order_by, $_group_by, $_aggregate_values_add);

        if ('on' == wp_slimstat::$settings['show_sql_debug']) {
            self::$debug_message .= sprintf("<p class='debug'>%s</p>", $_sql);
        }

        $table    = $GLOBALS['wpdb']->prefix . 'slim_stats';
        $sql_trim = ltrim($_sql);
        $cache_key = '';

        if (0 === stripos($sql_trim, 'select') && false !== stripos($sql_trim, $table)) {
            // Cache SELECTs on the analytics table; key rationale and the write-back gate
            // are documented at the write below.
            $cache_key      = 'slimstat_query_' . md5($_sql);
            $cached_results = get_transient($cache_key);
            if (false !== $cached_results) {
                return $cached_results;
            }
        }

        // Nothing here rewrites the SQL into the Query builder, and nothing may without an
        // ANCHORED parse. The converter this method carried matched `WHERE (.+?)` with every
        // following group optional, so the lazy quantifier captured exactly ONE character:
        // `WHERE username IS NOT NULL GROUP BY username` executed as `WHERE (u)` with the
        // GROUP BY dropped and a LIMIT 100 the caller never wrote, and the wrong rows were
        // cached under the md5 of the ORIGINAL sql. No shipped caller ever reached it — the
        // regex demanded literal single spaces around FROM/WHERE and every caller ships
        // tab-indented SQL (see the D72 note below) — which is the only reason it never
        // corrupted a report. get_var()'s parsers survive because they anchor with `…$/`,
        // forcing full-clause capture. The contract is pinned by
        // tests/get-results-convert-contract-test.php: verbatim execution, both formattings.
        //
        // Execute on wp_slimstat::$wpdb so the External DB addon queries the correct database.
        $results = wp_slimstat::$wpdb->get_results($_sql, ARRAY_A);

        // Write back what we read.
        //
        // A transient is read above for EVERY select that reaches here, but it used to be
        // written only on the converted path — so any query the regex could not parse paid
        // a get_transient() on every render and never once benefited from it. That is every
        // Pro report: measured on slimview3, 0 of 3 get_results() calls converted, all three
        // read and none wrote, and `_transient_slimstat_query_*` held 0 rows.
        //
        // The regex cannot be widened into a fix. It demands `[^ ]+` for the table, and
        // Pro's queries read `FROM \`local\`.wp_users tu INNER JOIN …` — a join, not a bare
        // table name — so no amount of whitespace tolerance would let it convert them, and
        // converting a join through the builder would not reproduce the same SQL anyway.
        // The asymmetry between the read and the write is the defect, not the parsing.
        //
        // Gated on the window being entirely historical, the same rule the builder applies
        // via canUseCacheForDateRange(). Without that gate a live window — whose SQL carries
        // a timestamp that moves every second — would write a row per render that nothing
        // can ever read, which is precisely the accumulation that left thousands of orphaned
        // rows in wp_options.
        //
        // The key is an md5 of the FULL SQL, so anything that scopes a query — a filter, a
        // blog restriction, a user predicate — is already part of the key and cannot be
        // served to a request that asked something different.
        //
        // Worth stating plainly: the defect is real but the win is small. Measured on
        // slimview3 with a historical range, steady state, 600 -> 598 queries per render.
        // Three get_results() calls out of ~600 queries is all this path is; the claim that
        // it puts "the whole plugin outside the cache" does not survive measurement. (D72)
        if ('' !== $cache_key && self::window_is_cacheable()) {
            set_transient($cache_key, $results, 10 * MINUTE_IN_SECONDS);
        }

        return $results;
    }

    /**
     * Is the active window entirely in the past, and therefore safe to cache?
     *
     * The same test maybe_enable_query_cache() applies to a Query object, in a form a raw
     * SQL path can use. A window reaching today is refused: its SQL carries a timestamp that
     * moves, so every write would be unreadable by the next request.
     *
     * @since 5.6.0
     * @return bool
     */
    protected static function window_is_cacheable()
    {
        if (empty(self::$filters_normalized['utime']['end'])) {
            return false;
        }

        return (int) self::$filters_normalized['utime']['end'] < strtotime(date('Y-m-d 00:00:00'));
    }

    protected static function is_simple_count_query($sql)
    {
        $sql_trim = ltrim($sql);
        if (preg_match('/^select\s+count\s*\(.*\)\s+as\s+[a-z_][a-z0-9_]*\s+from\s+[`\w]+/i', $sql_trim) && (false === stripos($sql_trim, ' join ') && false === stripos($sql_trim, ' group by ') && false === stripos($sql_trim, ' having ') && false === stripos($sql_trim, ' union ') && false === stripos($sql_trim, ' as sub') && stripos($sql_trim, '(') === stripos($sql_trim, 'count('))) {
            // no subquery before count
            return true;
        }

        return preg_match('/^select\s+count\s*\(\s*distinct\s+.*\)\s+as\s+[a-z_][a-z0-9_]*\s+from\s+[`\w]+/i', $sql_trim) && (false === stripos($sql_trim, ' join ') && false === stripos($sql_trim, ' group by ') && false === stripos($sql_trim, ' having ') && false === stripos($sql_trim, ' union ') && false === stripos($sql_trim, ' as sub'));
    }

    public static function get_var($_sql = '', $_aggregate_value = '')
    {
        $_sql = apply_filters('slimstat_get_var_sql', $_sql, $_aggregate_value);

        if ('on' == wp_slimstat::$settings['show_sql_debug']) {
            self::$debug_message .= sprintf("<p class='debug'>%s</p>", $_sql);
        }

        $table = $GLOBALS['wpdb']->prefix . 'slim_stats';
        $sql_trim = ltrim($_sql);

        // Try to convert to Query class for better performance
        if (0 === stripos($sql_trim, 'select') && false !== stripos($sql_trim, $table)) {
            // Parse simple count queries
            if (preg_match('/^SELECT\s+COUNT\s*\(\s*(\*|DISTINCT\s+(\w+))\s*\)\s+AS\s+(\w+)\s+FROM\s+[`\w]+(?:\s+WHERE\s+(.+?))?$/i', $sql_trim, $matches)) {
                $count_field = $matches[1];
                $alias = $matches[3];
                $where_clause = isset($matches[4]) ? trim($matches[4]) : '';

                $query = Query::select($count_field)->from($table);

                if ($where_clause !== '' && $where_clause !== '0') {
                    $query->whereRaw($where_clause);
                }

                $query->allowCaching(true);
                return $query->getVar();
            }

            // Parse other aggregate queries
            if (preg_match('/^SELECT\s+(\w+\([^)]+\))\s+AS\s+(\w+)\s+FROM\s+[`\w]+(?:\s+WHERE\s+(.+?))?$/i', $sql_trim, $matches)) {
                $aggregate = $matches[1];
                $alias = $matches[2];
                $where_clause = isset($matches[3]) ? trim($matches[3]) : '';

                $query = Query::select($aggregate)->from($table);

                if ($where_clause !== '' && $where_clause !== '0') {
                    $query->whereRaw($where_clause);
                }

                $query->allowCaching(true);
                return $query->getVar();
            }
        }

        // Fallback to wpdb for complex queries
        if (0 === stripos(trim($_sql), 'select')) {
            $query = Query::select('*')->from('(' . $_sql . ') as sub');
            self::maybe_enable_query_cache($query);
            return $query->getVar();
        } else {
            return wp_slimstat::$wpdb->get_var($_sql);
        }
    }

    public static function parse_filters($_filters_raw)
    {
        $filters_parsed = [
            'columns' => [],
            'date'    => [],
        ];

        if (!empty($_filters_raw)) {
            $matches = explode('&&&', $_filters_raw);

            foreach ($matches as $a_match) {
                // Third group AND its leading separator are optional so value-less
                // operators survive a URL round-trip (sanitize_text_field() trims the
                // trailing space the form-builder appends). See #305.
                preg_match('/([^\s]+)\s([^\s]+)(?:\s(.+))?/', urldecode($a_match), $a_filter);

                if ([] === $a_filter || ((!array_key_exists($a_filter[1], self::$all_columns_names) || false !== strpos($a_filter[1], 'no_filter')) && false === strpos($a_filter[1], 'addon_'))) {
                    continue;
                }

                // Preserve "malformed (no value) → drop" semantics for value-bearing
                // operators now that the regex no longer requires a value. Value-less
                // operators (is_empty/is_not_empty) are explicitly allowed through — but
                // only for real data columns: a value-less op aimed at a date/misc switch
                // key (strtotime, minute, …) would dereference the absent $a_filter[3]
                // below, so drop it here too. See #305.
                if (!isset($a_filter[3])
                    && (!in_array($a_filter[2], self::$valueless_operators, true)
                        || in_array($a_filter[1], self::NON_COLUMN_FILTER_KEYS, true))) {
                    continue;
                }

                switch ($a_filter[1]) {
                    case 'strtotime':
                        $custom_date = strtotime($a_filter[3], wp_slimstat::now());

                        $filters_parsed['date']['minute'] = intval(date('i', $custom_date));
                        $filters_parsed['date']['hour']   = intval(date('H', $custom_date));
                        $filters_parsed['date']['day']    = intval(date('j', $custom_date));
                        $filters_parsed['date']['month']  = intval(date('n', $custom_date));
                        $filters_parsed['date']['year']   = intval(date('Y', $custom_date));
                        break;

                    case 'minute':
                    case 'hour':
                    case 'day':
                    case 'month':
                    case 'year':
                        if (is_numeric($a_filter[3])) {
                            $filters_parsed['date'][$a_filter[1]] = intval($a_filter[3]);
                        } else {
                            // Try to apply strtotime to value
                            self::toggle_date_i18n_filters(false);
                            switch ($a_filter[1]) {
                                case 'minute':
                                    $filters_parsed['date']['minute'] = intval(wp_date('i', strtotime($a_filter[3], date_i18n('U'))));
                                    break;

                                case 'hour':
                                    $filters_parsed['date']['hour'] = intval(wp_date('H', strtotime($a_filter[3], date_i18n('U'))));
                                    break;

                                case 'day':
                                    $filters_parsed['date']['day'] = intval(wp_date('j', strtotime($a_filter[3], date_i18n('U'))));
                                    break;

                                case 'month':
                                    $filters_parsed['date']['month'] = intval(wp_date('n', strtotime($a_filter[3], date_i18n('U'))));
                                    break;

                                case 'year':
                                    $filters_parsed['date']['year'] = intval(wp_date('Y', strtotime($a_filter[3], date_i18n('U'))));
                                    break;

                                default:
                                    break;
                            }

                            self::toggle_date_i18n_filters(true);

                            if (false === $filters_parsed['date'][$a_filter[1]]) {
                                unset($filters_parsed['date'][$a_filter[1]]);
                            }
                        }

                        break;

                    case 'interval':
                    case 'interval_hours':
                    case 'interval_minutes':
                        $filters_parsed['date'][$a_filter[1]] = intval($a_filter[3]);
                        break;

                    case 'limit_results':
                    case 'start_from':
                        $filters_parsed['misc'][$a_filter[1]] = str_replace('\\', '', htmlspecialchars_decode($a_filter[3]));
                        break;

                    case 'content_id':
                        if (isset($a_filter[3]) && ('' !== $a_filter[3] && '0' !== $a_filter[3])) {
                            $content_id                              = ('current' == $a_filter[3] && !empty($GLOBALS['post']->ID)) ? $GLOBALS['post']->ID : $a_filter[3];
                            $filters_parsed['columns'][$a_filter[1]] = [$a_filter[2], $content_id];
                            break;
                        }
                        // no break here: if value IS numeric, go to the default parser here below

                    default:
                        $filter_op    = $a_filter[2];
                        $filter_value = isset($a_filter[3]) ? str_replace('\\', '', htmlspecialchars_decode($a_filter[3])) : '';
                        if (in_array($filter_op, self::$valueless_operators, true)) {
                            // Value-less by design — store an empty value, scrubbing any stale
                            // UI value the SQL builder would ignore anyway. See #305.
                            $filters_parsed['columns'][$a_filter[1]] = [$filter_op, ''];
                        } elseif (trim($filter_value) !== '') {
                            // Ignore value-bearing filters submitted without a value.
                            $filters_parsed['columns'][$a_filter[1]] = [$filter_op, $filter_value];
                        }
                        break;
                }
            }
        }

        return $filters_parsed;
    }

    public static function init_filters($_filters_raw = '')
    {
        $fn = self::parse_filters($_filters_raw);

        // Initialize default values
        if (empty($fn['misc']['limit_results'])) {
            $fn['misc']['limit_results'] = wp_slimstat::$settings['limit_results'];
        }

        if (empty($fn['misc']['start_from'])) {
            $fn['misc']['start_from'] = 0;
        }

        $fn['utime'] = [
            'start' => 0,
            'end'   => 0,
        ];

        // Normalize the various date values
        wp_slimstat::toggle_date_i18n_filters(false);

        // Intervals
        // If neither an interval nor interval_hours were specified...
        if (!isset($fn['date']['interval_minutes']) && !isset($fn['date']['interval_hours']) && !isset($fn['date']['interval'])) {
            $fn['date']['interval_minutes'] = 0;
            $fn['date']['interval_hours']   = 0;

            // If a day has been specified, then interval = 1 (show only that day)
            if (!empty($fn['date']['day'])) {
                $fn['date']['interval'] = -1;
            } elseif (empty(wp_slimstat::$settings['use_current_month_timespan']) || 'on' != wp_slimstat::$settings['use_current_month_timespan']) {
                $fn['date']['interval'] = -abs(wp_slimstat::$settings['posts_column_day_interval']);
            } else {
                $fn['date']['interval'] = -intval(date_i18n('j'));
            }
        } else {
            if (empty($fn['date']['interval_minutes'])) {
                // interval was set, but not interval_hours
                $fn['date']['interval_minutes'] = 0;
            }

            if (empty($fn['date']['interval_hours'])) {
                // interval_hours was set, but not interval
                $fn['date']['interval_hours'] = 0;
            }

            if (empty($fn['date']['interval'])) {
                // interval_hours was set, but not interval
                $fn['date']['interval'] = 0;
            }
        }

        $fn['utime']['range'] = $fn['date']['interval'] * 86400 + $fn['date']['interval_hours'] * 3600 + $fn['date']['interval_minutes'] * 60;

        // Day
        if (empty($fn['date']['day'])) {
            $fn['date']['day'] = intval(date_i18n('j'));
        }

        // Month
        if (empty($fn['date']['month'])) {
            $fn['date']['month'] = intval(date_i18n('n'));
        }

        // Year
        if (empty($fn['date']['year'])) {
            $fn['date']['year'] = intval(date_i18n('Y'));
        }

        if ($fn['utime']['range'] < 0) {
            $fn['utime']['end'] = mktime(
                empty($fn['date']['hour']) ? 23 : $fn['date']['hour'],
                empty($fn['date']['minute']) ? 59 : $fn['date']['minute'],
                59,
                $fn['date']['month'],
                $fn['date']['day'],
                $fn['date']['year']
            );

            // If end is in the future and the level of granularity is hours, set it to now
            if (!empty($fn['date']['interval_hours']) && $fn['utime']['end'] > date_i18n('U')) {
                $fn['utime']['end'] = self::live_window_end();
            }

            // Add 1 second to account for the time difference between midnight and 23:59:59
            $fn['utime']['range'] += 1;
            $fn['utime']['start'] = $fn['utime']['end'] + $fn['utime']['range'];

            // Store the absolute value for later (chart)
            $fn['utime']['range'] = -$fn['utime']['range'];
        } else {
            $fn['utime']['start'] = mktime(
                empty($fn['date']['hour']) ? 0 : $fn['date']['hour'],
                empty($fn['date']['minute']) ? 0 : $fn['date']['minute'],
                0,
                $fn['date']['month'],
                $fn['date']['day'],
                $fn['date']['year']
            );

            $fn['utime']['range'] -= 1;
            $fn['utime']['end'] = $fn['utime']['start'] + $fn['utime']['range'];
        }

        // If end is in the future, set it to now
        if ($fn['utime']['end'] > date_i18n('U')) {
            $fn['utime']['end'] = self::live_window_end();
        }

        // Turn the date_i18n filters back on
        wp_slimstat::toggle_date_i18n_filters(true);

        // Apply third-party filters
        $fn = apply_filters('slimstat_db_filters_normalized', $fn, $_filters_raw);

        return $fn;
    }

    /**
     * The end of a window that reaches the present: "now", optionally rounded DOWN to a
     * bucket. Named for what it returns rather than "now", because with a bucket set it is
     * deliberately not now — it can be up to one bucket earlier.
     *
     * Every window reaching the present is clamped through here, and unbucketed the clamp
     * lands on the current SECOND. That timestamp travels into the SQL, and the query cache
     * keys on a hash of the SQL, so an identical report rendered twice a second apart
     * produces two different keys and neither is ever read again. Measured over real admin
     * renders of slimview2, steady state:
     *
     *     unbucketed (the default)   25 queries/render, 11 dead wp_options rows/render
     *     bucketed to 1 hour         20 queries/render,  6 dead wp_options rows/render
     *
     * 724 such `_transient_wp_slimstat_query_*` rows had accumulated on this install. The
     * residual 6 are Live Analytics statements carrying their own `time()`, out of reach
     * from here.
     *
     * It also makes the window unpinnable, which is why the parity oracle declares its
     * midnight-straddling cells "render-only" and never compares their values — and those
     * are precisely the cells that exercise Query::getAll()'s split-merge path. The oracle
     * exercises that path and then declines to look at the result. A bucket makes those
     * cells comparable.
     *
     * **Ships inert.** The filter defaults to 0 because a bucket changes a number the user
     * reads: a report ending "now" ends at the bucket boundary instead, excluding up to one
     * bucket of the most recent traffic. Turning it on by default is a product decision and
     * belongs with the other default changes, not here. Note the precedent cuts both ways —
     * `Chart.php` already ships a 60-second bucket on its own query args, enabled, and
     * `self::CACHE_RANGE_BUCKET_SECONDS` buckets this same value to an hour for the
     * goal/funnel/UV cache keys, also unconditionally.
     *
     * Those siblings are independent of this filter and stay that way: setting a bucket here
     * does not change `results_cache_key()`'s hard 3600, nor the literal 3600 in
     * `wp_slimstat_admin::build_filter_options_cache_key()`, nor Chart's 60. A caller that
     * sets a bucket not divisible by 60 will have Chart re-round the already-rounded value.
     *
     * **Call-site constraint:** this uses plain `date_i18n('U')` rather than
     * `wp_slimstat::now()`, which is correct only because both call sites sit inside
     * `init_filters()`'s `toggle_date_i18n_filters(false)` bracket, where the filters this
     * would otherwise be exposed to are already off. Do not call this from outside that
     * bracket without revisiting that.
     *
     * @since 5.6.0
     * @return int
     */
    public static function live_window_end()
    {
        $now = intval(date_i18n('U'));

        /**
         * Round "now" down to a multiple of this many seconds when clamping a live window.
         *
         * 0 or 1 disables bucketing entirely, which is the shipped default and a no-op.
         *
         * @since 5.6.0
         * @param int $seconds Bucket size in seconds. Default 0 (no bucketing).
         */
        $bucket = (int) apply_filters('slimstat_live_window_bucket_seconds', 0);

        if ($bucket < 2) {
            return $now;
        }

        return intdiv($now, $bucket) * $bucket;
    }

    // The following methods retrieve the information from the database

    /**
     * Resources seen in exactly one visit.
     *
     * `visit_id` IS NOT SELECTED, and its removal is the whole change. The inner query used to
     * read `SELECT resource, visit_id … GROUP BY resource`, which names a column that is neither
     * grouped nor aggregated — MySQL rejects that under `ONLY_FULL_GROUP_BY`, and the outer
     * `COUNT(*)` never looked at the value. `HAVING COUNT(visit_id) = 1` is an aggregate and is
     * unaffected.
     *
     * WHAT THIS DID NOT FIX, because it was never broken. EXPECTED-DIFFS recorded this as "it
     * errors under ONLY_FULL_GROUP_BY and Bounce Pages silently reads 0". Measured on the bench
     * corpus, it returns **56, matching an independently computed 56** — because
     * `wpdb::set_sql_mode()` strips `ONLY_FULL_GROUP_BY` from every WordPress connection, and the
     * server's own default has it on. The claim was written from reading the SQL, and the SQL is
     * genuinely non-conforming: forcing the mode back on in the same session DOES reject it. So
     * this is a latent hazard on any connection that keeps the mode — a `slimstat_custom_wpdb`
     * filter returning something other than `wpdb`, or a future core change — not a live defect.
     *
     * Recorded that way rather than quietly fixed, because "the bug report was wrong about why"
     * is exactly the kind of thing that gets rediscovered.
     */
    public static function count_bouncing_pages()
    {
        $where = self::get_combined_where('visit_id > 0 AND content_type <> "404"', 'resource');

        return intval(self::get_var(
            "
			SELECT COUNT(*) counthits
				FROM (
					SELECT resource
					FROM {$GLOBALS['wpdb']->prefix}slim_stats
					WHERE {$where}
					GROUP BY resource
					HAVING COUNT(visit_id) = 1
				) as ts1",
            'SUM(counthits) AS counthits'
        ));
    }

    public static function count_records($_column = 'id', $_where = '', $_use_date_filters = true, $where_params = [])
    {
        // Validating the column
        if (false === in_array($_column, ['id', 'ip', 'other_ip', 'username', 'email', 'country', 'location', 'city', 'referer', 'resource', 'searchterms', 'notes', 'visit_id', 'server_latency', 'page_performance', 'browser', 'browser_version', 'browser_type', 'platform', 'language', 'fingerprint', 'user_agent', 'resolution', 'screen_width', 'screen_height', 'content_type', 'category', 'author', 'content_id', 'outbound_resource', 'tz_offset', 'dt_out', 'dt'])) {
            return null;
        }

        $table = $GLOBALS['wpdb']->prefix . 'slim_stats';

        // M1 — the merge intent is declared from the COLUMN, and it decides the inner query's
        // shape. `id` counts rows and sums; everything else must union the VALUES, because the
        // golden fixture measures 6 distinct visitors network-wide against 7 summed per blog and
        // no aggregate over per-blog counts can recover the 6.
        //
        // Only when a merge is actually happening. On a single site — and on a network screen
        // that is not network-scoped — this stays the single `COUNT(DISTINCT …)` it has always
        // been, because the row-returning shape is only worth its cost if something unions it.
        $merging = NetworkMerge::isMerging();
        $intent  = NetworkMerge::intentForColumn($_column);

        $select = $merging
            ? NetworkMerge::innerSelect($intent, $_column)
            : sprintf('COUNT(%s) as counthits', ('id' != $_column) ? 'DISTINCT ' . $_column : $_column);

        $query = Query::select($select)->from($table);

        // Add date filters if needed
        if ($_use_date_filters && !empty(self::$filters_normalized['utime']['start']) && !empty(self::$filters_normalized['utime']['end'])) {
            $query->where('dt', 'BETWEEN', [intval(self::$filters_normalized['utime']['start']), intval(self::$filters_normalized['utime']['end'])]);
        }

		if (
			!empty($_where)
			&& !empty($where_params)
			&& (false !== strpos($_where, '%s') || false !== strpos($_where, '%d') || false !== strpos($_where, '%f'))
		) {
			$_where = is_array($where_params) ? $GLOBALS['wpdb']->prepare($_where, ...$where_params) : $GLOBALS['wpdb']->prepare($_where, $where_params);
		}

        // Add custom where clause
        if (!empty($_where)) {
            $query->whereRaw($_where);
        }

        // Add other filters
        if (!empty(self::$filters_normalized['columns'])) {
            $where_clause = self::_get_sql_where(self::$filters_normalized['columns']);
            if (!empty($where_clause)) {
                $query->whereRaw($where_clause);
            }
        }

        $query->allowCaching(true);

        // NETWORK-SCOPED, and it may only be so because get_top() is scoped in the same change.
        //
        // This is the DENOMINATOR in `100 * counthits / wp_slimstat_db::$pageviews`
        // (reports.php). An earlier attempt scoped it alone: the denominator moved 15 → 40 on
        // the golden fixture while the numerator stayed at 15, understating every row ~2.7x and
        // turning a report whose rows summed to 100% into one summing to ~37% — silently, since
        // reports.php clamps only the `> 99` direction. Reverted, and recorded as PITFALLS 23.
        //
        // The rule that replaced it: numerator and denominator move together, or neither moves.
        // The oracle in tests/docker/probe-network-view.php reports both sides and the state
        // they are in — consistent-main-site, MIXED, or consistent-network — so "they moved
        // together" is measured on every topology run rather than argued here.
        //
        // GATED ON THE SAME `$merging` FLAG THE NUMERATOR USES, and that is a compatibility
        // requirement rather than tidiness. Pro's `slimstat_get_var_sql` rewriter has existed
        // for years; `slimstat_network_merge_active` is new in this change. So on a site running
        // v6 free against an OLDER Pro, applying the filter unconditionally would let the old
        // rewriter scope this denominator while get_top() — which needs the new filter to know a
        // merge is happening — stayed main-site. That is MIXED, on real installs, from a version
        // combination nobody controls.
        //
        // Measured, not reasoned: the first version of this change did exactly that, and the
        // topology oracle came back `report_denominator: 40, report_numerator: 15, report_scope:
        // MIXED` against a Pro zip built before the new filter existed. Gating both sides on one
        // flag makes the old-Pro case fall back to consistent-main-site — the previous, correct
        // behaviour — instead of to a silently wrong ratio.
        return intval($merging
            ? $query->getVar(NetworkMerge::outerAggregate($intent, $_column))
            : $query->getVar());
    }

    public static function count_records_having($_column = 'id', $_where = '', $_having = '')
    {
        // Allowlist: only known schema columns are allowed as identifiers
        $allowed_columns = array_keys(self::$columns_names);
        if (!in_array($_column, $allowed_columns, true)) {
            return 0;
        }

        $merging         = NetworkMerge::isMerging();
        $table           = $GLOBALS['wpdb']->prefix . 'slim_stats';
        $distinct_column = ('id' !== $_column) ? 'DISTINCT ' . esc_sql($_column) : esc_sql($_column);

        $query = Query::select("COUNT(*) as counthits")
            ->from("(
                SELECT {$distinct_column}
                FROM {$table}
                WHERE " . self::get_combined_where($_where, $_column) . "
                GROUP BY " . esc_sql($_column) . "
                HAVING {$_having}
            ) AS ts1");

        $query->allowCaching(true);

        // M2 — SCOPED WITH THE SAME GATE, and this function is the reason the rule is worded as
        // "or neither moves" rather than "remember to do both".
        //
        // It is the NUMERATOR for the bounce rate, the new-visitor rate and the seven duration
        // buckets, every one of which is divided by count_records(). The first version of this
        // seam scoped count_records() and left this behind, recreating PITFALLS 23 on a
        // different pair — with the deleted comment three lines above having named it: "Scoping
        // either one alone breaks the pair." Caught by review, not by the suite: the symmetry
        // gate pinned only the get_top/count_records pair, so it stayed green.
        //
        // SUM is the correct intent because the HAVING is evaluated INSIDE each blog, which M2
        // ratifies as correct *today*: `visit_id` is per-blog, so a visit cannot span subsites
        // and a single-pageview visit is single-pageview on the site it happened on. Each blog
        // therefore contributes a whole number of bounces and those add. M2 is explicitly dated
        // — G4's cross-blog `vid_hash` reopens it, at which point per-blog HAVING starts
        // splitting one visit into two bounces.
        return intval($merging
            ? $query->getVar(NetworkMerge::outerAggregate(NetworkMerge::SUM, 'counthits'))
            : $query->getVar());
    }

    public static function get_data_size()
    {
        $suffix = 'KB';

        $sql           = 'SHOW TABLE STATUS LIKE "' . $GLOBALS['wpdb']->prefix . 'slim_stats"';
        $table_details = wp_slimstat::$wpdb->get_row($sql, 'ARRAY_A', 0);

        $table_size = ($table_details['Data_length'] / 1024) + ($table_details['Index_length'] / 1024);

        if ($table_size > 1024) {
            $table_size /= 1024;
            $suffix = 'MB';
        }

        return number_format_i18n($table_size, 2) . ' ' . $suffix;
    }

    public static function get_group_by($_args = [])
    {
        if (empty($_args['column_group'])) {
            $_args['column_group'] = 'id';
        }

        if (empty($_args['group_by'])) {
            $_args['group_by'] = 'id';
        }

        $table = $GLOBALS['wpdb']->prefix . 'slim_stats';
        $query = Query::select([
            $_args['group_by'],
            'COUNT(*) AS counthits',
            sprintf("GROUP_CONCAT( DISTINCT %s SEPARATOR ';;;' ) as column_group", $_args['column_group'])
        ])->from($table);

        // Add date filters if needed
        if (!empty(self::$filters_normalized['utime']['start']) && !empty(self::$filters_normalized['utime']['end'])) {
            $query->where('dt', 'BETWEEN', [intval(self::$filters_normalized['utime']['start']), intval(self::$filters_normalized['utime']['end'])]);
        }

        // Add other filters
        if (!empty(self::$filters_normalized['columns'])) {
            $where_clause = self::_get_sql_where(self::$filters_normalized['columns']);
            if (!empty($where_clause)) {
                $query->whereRaw($where_clause);
            }
        }

        // Add IS NOT NULL condition
        $query->where($_args['group_by'], 'IS NOT', null);

        // GROUP BY — P3's per-blog key under a merge, same as get_top. P3 also DISSOLVED
        // M3's hard half: the planned cross-blog recombination (split the concat,
        // re-dedupe, re-concat) assumed merged rows, but per-blog rows mean each arm's
        // GROUP_CONCAT is already its own blog's finished DISTINCT list. Every
        // (blog_id, group) exists in exactly ONE arm, so the outer aggregates are
        // pass-throughs: SUM(counthits) is that arm's count, MAX(column_group) is that
        // arm's list, and no cross-blog mixing exists to get wrong.
        $merging   = NetworkMerge::isMerging();
        $group_key = $merging ? NetworkMerge::groupKeyFor($_args['group_by']) : $_args['group_by'];
        $query->groupBy($group_key);

        // ORDER BY — tie-breaker on the group key (which is already the per-blog key
        // under a merge, so blog_id rides along without a second spelling of it here).
        $order = 'counthits DESC, ' . $group_key . ' ASC';
        $query->orderBy($order);

        // LIMIT — no SQL OFFSET; PHP-side pagination in show_group_by(). Not applied to
        // the inner query when merging, for get_top's reason: buildQuery() puts the LIMIT
        // inside every union arm, so each blog would contribute only its own top N and a
        // group ranked N+1 on one subsite loses that subsite's rows entirely. The bound
        // is re-applied OUTSIDE the union instead, by getAll's merge branch.
        $limit = max(1, intval(self::$filters_normalized['misc']['limit_results']));
        if (!$merging) {
            $query->limit($limit);
        }

        $query->allowCaching(true);

        $rows = $query->getAll(
            NetworkMerge::SUM,
            $_args['group_by'],
            $group_key,
            $order,
            'MAX(column_group) AS column_group',
            $limit
        );

        return $merging ? array_slice($rows, 0, $limit) : $rows;
    }

    /**
     * Max and mean pageviews per visit.
     *
     * `count(*)`, NOT `count(ip)`, and the change is a correctness fix that happens to be
     * cheaper rather than the other way round.
     *
     * `ip` is `VARCHAR(39) DEFAULT NULL`, and `count(ip)` skips NULLs — so a pageview recorded
     * without an ip was not counted as a page. This function answers "pages per visit"; a row
     * with no ip is still a page. On an install where any ip is NULL the old form understated
     * both the average and the maximum, silently.
     *
     * MEASURED (Run 13) over 152,014 rows, four forms on an A-B-C-D-D-C-B-A schedule, each form's
     * two replicates agreeing exactly, every answer checked against an independently computed
     * oracle (`COUNT(*) / COUNT(DISTINCT visit_id)`) rather than against one of the forms:
     *
     *   count(ip), with visit_id      Handler_read_rnd_next = 302,855
     *   count(ip), without            302,855      <- removing the unused column saves NOTHING
     *   count(*),  without            152,854
     *   count(*),  with visit_id      152,854      <- THIS form, the one that ships:  -49.5%
     *
     * The delta is 150,001 against a 152,014-row table — one full pass. `count(ip)` must read the
     * ip column of every row to learn whether it is NULL; `count(*)` needs no column value.
     *
     * AND THE OBVIOUS OPTIMISATION HERE BUYS NOTHING — measured, not assumed, which is why the
     * shipped form keeps `visit_id`. It is selected by the inner query and read by neither
     * aggregate, the exact shape that cost count_bouncing_pages() a disk-spilled temp table one
     * seam earlier. Here it changes `rnd_next` by **zero**, on both replicates, in both the
     * count(ip) and count(*) pairs. It is the GROUP BY key and it documents the grain, so it
     * stays. Do not "tidy" it on the strength of the other seam: the identical shape has
     * different consequences in the two queries, and that is only knowable by measuring.
     */
    public static function get_max_and_average_pages_per_visit()
    {
        $where = self::get_combined_where('visit_id > 0');
        $table = $GLOBALS['wpdb']->prefix . 'slim_stats';

        $subQuery = sprintf('SELECT count(*) counthits, visit_id FROM %s WHERE %s GROUP BY visit_id', $table, $where);
        $from     = sprintf('(%s) AS ts1', $subQuery);

        if (NetworkMerge::isMerging()) {
            // M4, forced: SUM/SUM at the TRUE outer level, divided once, here. An outer
            // AVG over unioned per-blog rows weights a two-visit blog the same as a
            // three-visit one — the mean of means, 5.8333 on the golden fixture where
            // the network answer is 40/7 = 5.7143. Pageviews and visits both compose
            // over blogs by SUM, the per-visit MAX by MAX, so three scalar aggregates
            // go through the same getVar network path every other ratio uses. (One
            // combined per-arm row would need a getRow network affordance that does not
            // exist — deferred with Run 22 rather than silently.)
            $sum_outer = NetworkMerge::outerAggregate(NetworkMerge::SUM, 'counthits');

            $pageviews = (int) Query::select('SUM(ts1.counthits) AS counthits')
                ->from($from)
                ->getVar($sum_outer);
            $visits = (int) Query::select('COUNT(*) AS counthits')
                ->from($from)
                ->getVar($sum_outer);
            // MAX composes by MAX. Written here, not as a NetworkMerge intent: one
            // caller does not mint vocabulary — a second MAX-composing caller moves it.
            $max = (int) Query::select('MAX(ts1.counthits) AS counthits')
                ->from($from)
                ->getVar('MAX(counthits) AS counthits');

            return [[
                'avghits' => ($visits > 0) ? ($pageviews / $visits) : 0,
                'maxhits' => $max,
            ]];
        }

        $query = Query::select('AVG(ts1.counthits) AS avghits, MAX(ts1.counthits) AS maxhits')
            ->from($from);

        self::maybe_enable_query_cache($query);
        return $query->getAll();
    }

    public static function get_oldest_visit()
    {
        $table = $GLOBALS['wpdb']->prefix . 'slim_stats';
        $query = Query::select('dt')->from($table)->orderBy('dt', 'ASC')->limit(1);
        $query->allowCaching(true, DAY_IN_SECONDS);
        return $query->getVar();
    }

    public static function get_overview_summary()
    {
        $days_in_range = ceil((wp_slimstat_db::$filters_normalized['utime']['end'] - wp_slimstat_db::$filters_normalized['utime']['start']) / 86400);
        $days_in_range = ($days_in_range < 1) ? 1 : $days_in_range;

        $results = [];

        // Turn date_i18n filters off
        wp_slimstat::toggle_date_i18n_filters(false);

        // Ensure pageviews is initialized for Dashboard widgets
        if (0 === self::$pageviews) {
            self::$pageviews = wp_slimstat_db::count_records();
        }

        $results[0]['metric']  = __('Pageviews', 'wp-slimstat');
        $results[0]['value']   = number_format_i18n(self::$pageviews, 0);
        $results[0]['tooltip'] = __('A pageview is a request to load a single HTML page on your website.', 'wp-slimstat');

        $results[1]['metric'] = __('Days in Range', 'wp-slimstat');
        $results[1]['value']  = $days_in_range;

        $results[2]['metric']  = __('Average Daily Pageviews', 'wp-slimstat');
        $results[2]['value']   = number_format_i18n(round(self::$pageviews / $days_in_range, 0));
        $results[2]['tooltip'] = __('How many daily pageviews have been generated on average.', 'wp-slimstat');

        $results[3]['metric']  = __('From Any SERP', 'wp-slimstat');
        $results[3]['value']   = number_format_i18n(wp_slimstat_db::count_records('id', 'searchterms IS NOT NULL'));
        $results[3]['tooltip'] = __('Visitors who landed on your site after searching for a keyword on a search engine and clicking on the corresponding search result link. This value includes both internal and external search result pages.', 'wp-slimstat');

        $results[4]['metric']  = __('Unique IPs', 'wp-slimstat');
        $results[4]['value']   = number_format_i18n(wp_slimstat_db::count_records('ip'));
        $results[4]['tooltip'] = __('Used to differentiate between multiple requests to download a file from one internet address (IP) and requests originating from many distinct addresses.', 'wp-slimstat');

        $results[5]['metric'] = __('Last 30 minutes', 'wp-slimstat');
        $results[5]['value']  = number_format_i18n(wp_slimstat_db::count_records('id', 'dt > ' . (wp_slimstat::now() - 1800), false));

        $results[6]['metric'] = __('Today', 'wp-slimstat');
        $results[6]['value']  = number_format_i18n(wp_slimstat_db::count_records('id', 'dt > ' . (wp_slimstat::date_i18n('U', mktime(0, 0, 0, (int) wp_slimstat::date_i18n('m'), (int) wp_slimstat::date_i18n('d'), (int) wp_slimstat::date_i18n('Y')))), false));

        $results[7]['metric'] = __('Yesterday', 'wp-slimstat');
        $results[7]['value']  = number_format_i18n(wp_slimstat_db::count_records('id', 'dt BETWEEN ' . (wp_slimstat::date_i18n('U', mktime(0, 0, 0, (int) wp_slimstat::date_i18n('m'), (int) wp_slimstat::date_i18n('d') - 1, (int) wp_slimstat::date_i18n('Y')))) . ' AND ' . (wp_slimstat::date_i18n('U', mktime(23, 59, 59, (int) wp_slimstat::date_i18n('m'), (int) wp_slimstat::date_i18n('d') - 1, (int) wp_slimstat::date_i18n('Y')))), false));

        // Turn date_i18n filters back on
        wp_slimstat::toggle_date_i18n_filters(true);

        return $results;
    }

    public static function get_recent($_column = 'id', $_where = '', $_having = '', $_use_date_filters = true, $_as_column = '', $_more_columns = '', $_order_by = 'dt DESC')
    {
        if (is_array($_column)) {
            $_where            = empty($_column['where']) ? '' : $_column['where'];
            $_having           = empty($_column['having']) ? '' : $_column['having'];
            $_use_date_filters = $_column['use_date_filters'] ?? true;
            $_as_column        = empty($_column['as_column']) ? '' : $_column['as_column'];
            $_more_columns     = empty($_column['more_columns']) ? '' : $_column['more_columns'];
            $_order_by         = empty($_column['order_by']) ? 'dt DESC' : $_column['order_by'];
            $_column           = $_column['columns'];
        }

        $columns = ('*' === $_column)
            ? ['id', 'ip', 'dt', 'username', 'referer', 'resource', 'browser', 'platform', 'country', 'city', 'content_type', 'notes', 'visit_id', 'server_latency', 'page_performance', 'browser_version', 'browser_type', 'language', 'fingerprint', 'user_agent', 'resolution', 'screen_width', 'screen_height', 'category', 'author', 'content_id', 'outbound_resource', 'tz_offset', 'dt_out']
            : array_map('trim', explode(',', $_column));
        if (!empty($_as_column)) {
            $columns[0] = $columns[0] . ' AS ' . $_as_column;
        }

        if (!empty($_more_columns)) {
            $more_cols = array_map('trim', explode(',', $_more_columns));
            $columns   = array_merge($columns, $more_cols);
        }

        if (!in_array('dt', $columns)) {
            $columns[] = 'dt';
        }

        if (!in_array('ip', $columns)) {
            $columns[] = 'ip';
        }

        $table = $GLOBALS['wpdb']->prefix . 'slim_stats';
        $query = Query::select(implode(', ', $columns))->from($table);

        // Always add date filter as a proper where() clause so placeholders are replaced
        if ($_use_date_filters && !empty(self::$filters_normalized['utime']['start']) && !empty(self::$filters_normalized['utime']['end']) && !$query->hasWhereClause('dt', 'BETWEEN')) {
            $query->where('dt', 'BETWEEN', [intval(self::$filters_normalized['utime']['start']), intval(self::$filters_normalized['utime']['end'])]);
        }

        // Apply active column filters (e.g., browser equals Chrome) using the existing normalization logic
        if (!empty(self::$filters_normalized['columns'])) {
            $normalized_where = self::_get_sql_where(self::$filters_normalized['columns']);
            if (!empty($normalized_where)) {
                $query->whereRaw($normalized_where);
            }
        }

        // Only add additional non-parameterized conditions passed via $_where
        if (!empty($_where)) {
            $query->whereRaw($_where);
        }

        // HAVING
        if (!empty($_having)) {
			$query->havingRaw($_having);
        }

        // ORDER BY
        if (!empty($_order_by)) {
            $query->orderBy($_order_by);
        }

        // LIMIT
        $start = max(0, intval(self::$filters_normalized['misc']['start_from']));
        $limit = max(1, intval(self::$filters_normalized['misc']['limit_results']));
        $query->limit($limit, $start);

        $query->allowCaching(false);



        return $query->getAll();
    }

    public static function get_recent_events()
    {
        return self::get_results(
            "
			SELECT te.*, t1.ip, t1.resource
			FROM {$GLOBALS[ 'wpdb' ]->prefix}slim_events te INNER JOIN {$GLOBALS[ 'wpdb' ]->prefix}slim_stats t1 ON te.id = t1.id
			WHERE " . wp_slimstat_db::get_combined_where('te.notes NOT LIKE "_ype:click%"', 'te.notes', true, 't1') . '
			ORDER BY te.dt DESC',
            'te.*, t1.resource',
            'dt DESC'
        );
    }

    public static function get_recent_outbound()
    {
        $mixed_outbound_resources = self::get_recent('outbound_resource', "outbound_resource IS NOT NULL AND outbound_resource != ''", '', true, '', 'dt, dt_out');
        $clean_outbound_resources = [];

        foreach ($mixed_outbound_resources as $a_mixed_resource) {
            // Prefer dt_out (actual outbound click time) over dt (pageview creation time)
            $row_dt = isset($a_mixed_resource['dt_out']) && intval($a_mixed_resource['dt_out']) > 0
                ? intval($a_mixed_resource['dt_out'])
                : (isset($a_mixed_resource['dt']) ? intval($a_mixed_resource['dt']) : 0);
            $exploded_resources = explode(';;;', $a_mixed_resource['outbound_resource'] ?? '');
            foreach ($exploded_resources as $a_exploded_resource) {
                if ($a_exploded_resource !== '') {
                    $clean_outbound_resources[] = ['url' => $a_exploded_resource, 'dt' => $row_dt];
                }
            }
        }

        return $clean_outbound_resources;
    }

    public static function get_top($_column = 'id', $_where = '', $_having = '', $_use_date_filters = true, $_as_column = '')
    {
        $_order_by    = 'counthits DESC';
        $_more_select = '';

        // This function can be passed individual arguments, or an array of arguments
        if (is_array($_column)) {
            $where_params = !empty($_column['where_params']) ? $_column['where_params'] : [];
            $_where       = !empty($_column['where']) ? $_column['where'] : '';

			if (
				!empty($_where)
				&& !empty($where_params)
				&& (false !== strpos($_where, '%s') || false !== strpos($_where, '%d') || false !== strpos($_where, '%f'))
			) {
				$_where = is_array($where_params) ? $GLOBALS['wpdb']->prepare($_where, ...$where_params) : $GLOBALS['wpdb']->prepare($_where, $where_params);
			}

            $_having           = empty($_column['having']) ? '' : $_column['having'];
            $_use_date_filters = isset($_column['use_date_filters']) ? (bool)$_column['use_date_filters'] : true;
            $_as_column        = empty($_column['as_column']) ? '' : $_column['as_column'];
            $_order_by         = empty($_column['order_by']) ? 'counthits DESC' : $_column['order_by'];
            $_more_select      = empty($_column['more_select']) ? '' : $_column['more_select'];
            $_column           = $_column['columns'];
        }

        // P3, ratified: a network report keeps PER-BLOG row identity — /about/ on two
        // subsites is two rows, each linking to its own site. Grouped by the column alone,
        // MySQL folds them into one row whose blog_id is whichever union arm it met first
        // (wpdb strips ONLY_FULL_GROUP_BY at connect, so this is a silent wrong answer,
        // not an error): the row links to an arbitrary site under a number no site earned,
        // and the SUM across rows cannot expose it — 6+5 in two rows and 11 in one sum
        // identically. Measured on the golden fixture: about_rows 1→2, about_max 11→6,
        // top_resource_max 16→7, network total unchanged at 40.
        //
        // Inside each union arm blog_id is the arm's injected constant, so the inner
        // grouping is unchanged per blog; single-site queries never take this branch.
        $merging         = NetworkMerge::isMerging();
        $group_by_column = $merging ? NetworkMerge::groupKeyFor($_column) : $_column;

        if (!empty($_as_column)) {
            $_column = sprintf('%s AS %s', $_column, $_as_column);
        } else {
            $_as_column = $_column;
        }

        $table = $GLOBALS['wpdb']->prefix . 'slim_stats';
        $select_cols = [$_column, 'COUNT(*) AS counthits'];
        if (!empty($_more_select)) {
            $select_cols[] = $_more_select;
        }
        $query = Query::select($select_cols)->from($table);

        // Add date filters if needed
        if ($_use_date_filters && !empty(self::$filters_normalized['utime']['start']) && !empty(self::$filters_normalized['utime']['end'])) {
            $query->where('dt', 'BETWEEN', [intval(self::$filters_normalized['utime']['start']), intval(self::$filters_normalized['utime']['end'])]);
        }

        // Add custom where clause
        if (!empty($_where)) {
            $query->whereRaw($_where);
        }

        // Add other filters
        if (!empty(self::$filters_normalized['columns'])) {
            $where_clause = self::_get_sql_where(self::$filters_normalized['columns']);
            if (!empty($where_clause)) {
                $query->whereRaw($where_clause);
            }
        }

        // GROUP BY
        $query->groupBy($group_by_column);

        // HAVING
		if (!empty($_having)) {
			$query->havingRaw($_having);
		}

        // ORDER BY — append tie-breakers for deterministic pagination when many rows
        // share the primary sort value. Per COMPONENT, not the composed key: searching
        // $_order_by for the two-column merge key can essentially never match, which
        // silently degenerated to "always append both" — right by accident, and emitting
        // a duplicate sort column whenever the caller already ordered by the base column.
        // blog_id is appended under merge REGARDLESS of the base column's presence: two
        // blogs tied on '/about/' under `order_by 'resource ASC'` still need it, or page
        // cuts go nondeterministic exactly in the P3 case.
        $order_with_tiebreak = $_order_by;
        $tiebreak_parts      = [];
        if ($merging && false === stripos($_order_by, 'blog_id')) {
            $tiebreak_parts[] = 'blog_id';
        }
        if (false === stripos($_order_by, $_column)) {
            $tiebreak_parts[] = $_column . ' ASC';
        }
        if ([] !== $tiebreak_parts) {
            $order_with_tiebreak .= ', ' . implode(', ', $tiebreak_parts);
        }
        $query->orderBy($order_with_tiebreak);

        // LIMIT — no SQL OFFSET for aggregated reports; PHP-side pagination
        // handles page slicing via array_slice in the rendering callbacks.
        // ($merging was resolved above, where the P3 group key needed it.)
        $limit = max(1, intval(self::$filters_normalized['misc']['limit_results']));

        // NOT APPLIED TO THE INNER QUERY WHEN MERGING, and this is a correctness fix rather than
        // a tuning choice. `buildQuery()` puts the LIMIT inside every union arm, and Pro's
        // rewriter adds none outside — so each blog would contribute only ITS OWN top 20, and a
        // resource ranked 21st on one subsite loses that subsite's hits entirely. The
        // denominator, meanwhile, stays complete. That is a silent per-row undercount with rows
        // summing to under 100%: PITFALLS 23's direction again, arrived at through pagination.
        //
        // The shape predates this change — the legacy string-SQL path had `LIMIT %d, %d` inside
        // too — but it was unreachable, because nothing built by this builder ever reached the
        // Network View. Routing these reports there for the first time is what makes it live.
        if (!$merging) {
            $query->limit($limit);
        }

        $query->allowCaching(true);

        // D22 — the NUMERATOR, scoped in the same change as its denominator and by the same
        // rule. `counthits` here is COUNT(*), which is additive over blogs, so SUM at the outer
        // level is correct and is the one intent for which that is true.
        //
        // The group key travels with it: the union has to re-group by the same columns or the
        // outer rows are per-blog fragments of one report row — the F10 Layer 2 grain mistake
        // in a different costume (Run 9 / M7).
        //
        // `$_more_select` travels too. It is an aggregate (`MAX(dt) AS dt` on the "Recent …"
        // reports), so it belongs in the OUTER select beside SUM(counthits) — computed over the
        // union rather than per arm. Omitted, it still resolved inside t_union_all and drove the
        // outer ORDER BY correctly while being absent from the returned columns, so the
        // last-seen tooltip silently vanished on every network-scoped "Recent" report.
        $rows = $query->getAll(
            NetworkMerge::SUM,
            $_as_column,
            $group_by_column,
            $order_with_tiebreak,
            $_more_select,
            $limit
        );

        // The LIMIT the inner query no longer carries is re-applied OUTSIDE the union by
        // getAll's merge branch; the slice stays as the belt for a filter that answers
        // with an unexpected shape.
        return $merging ? array_slice($rows, 0, $limit) : $rows;
    }

    public static function get_top_aggr($_column = 'id', $_where = '', $_outer_select_column = '', $_aggr_function = 'MAX')
    {
        // MAIN-SITE ONLY on a Network View, BY DECISION (M5, deferred) — stated here
        // because two documents claimed this statement existed and nothing in the file
        // said it, which made the deferral indistinguishable from an accident. The
        // representative-row shape (aggregate in an inner query, re-join to pick the row
        // that produced it) needs a composite (blog_id, id) key to survive a union merge;
        // Run 9 refuted F10 Layer 2, so that key does not arrive in v6.0.0. Until it
        // does, this function's reports read the current site only, everywhere.
        //
        // Declared BEFORE the branch, because only the array form sets them and both are read
        // below. On the scalar-argument path `$_as_column` was already an undefined-variable
        // read — harmless while PHP treated it as null, and a warning on 8.x — and
        // `$_use_date_filters` would have become one the moment it started being honoured,
        // silently turning the date filter OFF for every scalar caller. Defaults chosen to
        // preserve exactly what those callers got before.
        $_use_date_filters = true;
        $_as_column        = '';

        if (is_array($_column)) {
            $_where               = empty($_column['where']) ? '' : $_column['where'];
            $_having              = empty($_column['having']) ? '' : $_column['having'];
            // isset(), not empty(): `empty()` reads a declared `false` as "not set" and
            // flips it back to true, which is exactly the value a report would be
            // declaring on purpose. Matches get_top()'s reading of the same key. (D62)
            $_use_date_filters    = isset($_column['use_date_filters']) ? (bool) $_column['use_date_filters'] : true;
            $_as_column           = empty($_column['as_column']) ? '' : $_column['as_column'];
            $_outer_select_column = empty($_column['outer_select_column']) ? '' : $_column['outer_select_column'];
            $_aggr_function       = empty($_column['aggr_function']) ? '' : $_column['aggr_function'];
            $_column              = $_column['columns'];
        }

        if (!empty($_as_column)) {
            $_column = sprintf('%s AS %s', $_column, $_as_column);
        } else {
            $_as_column = $_column;
        }

        // `$_use_date_filters` is HONOURED, and until now it was parsed and thrown away: the
        // array form reads `use_date_filters` at the top of this function and nothing ever
        // consulted it, so `get_combined_where()` was called with two arguments and defaulted to
        // true. Every caller asking for an unfiltered aggregate got a date-filtered one.
        //
        // Found through the bench harness rather than a report: the two `uniques_*` answers
        // could not opt out, so they alone moved with the clock in a byte-comparison harness
        // whose entire contract is that answers do not. Crossing local midnight mid-run would
        // have produced DIFFERENCES with no code difference — and this harness escalates a
        // difference to "a defect or an EXPECTED-DIFFS entry, never a shrug".
        //
        // get_top()'s D62 note applies to the flag itself: `isset()` rather than `empty()`,
        // because `empty()` reads a declared `false` as "not set" and flips it back to true,
        // which is the value a caller would be declaring on purpose.
        $_where = self::get_combined_where($_where, $_column, $_use_date_filters);
        $table  = $GLOBALS['wpdb']->prefix . 'slim_stats';

		$subQuerySql = sprintf('SELECT %s, %s(id) as aggrid FROM %s WHERE %s GROUP BY %s', $_column, $_aggr_function, $table, $_where, $_column);

		$query = Query::select(sprintf('%s, ts1.aggrid as %s, COUNT(*) as counthits', $_outer_select_column, $_column))
			->from(sprintf('(%s) AS ts1', $subQuerySql))
            ->join($table . ' t1', 'ts1.aggrid', 't1.id')
            ->groupBy($_outer_select_column)
            // The tie-break is load-bearing: ORDER BY an aggregate alone leaves equal-count
            // rows in plan order, and this derived-table shape's plan order varies BETWEEN
            // EXECUTIONS on MySQL 5.7 — measured as a failed same-corpus null control (two
            // identical runs, rows 19/20 swapped). Tied rows also flap between page refreshes
            // for a human. Grouped column second makes the order a property of the DATA.
            ->orderBy(sprintf('counthits DESC, %s ASC', $_outer_select_column))
            ->limit(max(1, intval(self::$filters_normalized['misc']['limit_results'])));

        self::maybe_enable_query_cache($query);
        return $query->getAll();
    }

    public static function get_top_events()
    {
        $table_events = $GLOBALS['wpdb']->prefix . 'slim_events';
        $table_stats  = $GLOBALS['wpdb']->prefix . 'slim_stats';

        if (empty(self::$filters_normalized['columns'])) {
            $query = Query::select('te.notes, COUNT(*) as counthits')
                ->from($table_events . ' te')
                ->whereRaw(wp_slimstat_db::get_combined_where('notes NOT LIKE "type:click%"', 'notes'));
        } else {
            $query = Query::select('te.notes, COUNT(*) as counthits')
                ->from($table_events . ' te')
                ->join($table_stats . ' t1', 'te.id', 't1.id')
                ->whereRaw(wp_slimstat_db::get_combined_where('te.notes NOT LIKE "_ype:click%"', 'te.notes', true, 't1'));
        }

        $query->groupBy('te.notes')->orderBy('counthits DESC');

        $limit = max(1, intval(self::$filters_normalized['misc']['limit_results']));
        $query->limit($limit);

        self::maybe_enable_query_cache($query);
        return $query->getAll();
    }

    public static function get_top_outbound($_args = [])
    {
        $sort_by = 'counthits';
        if (is_array($_args) && !empty($_args['sort_outbound'])) {
            $sort_by = $_args['sort_outbound'];
        }

        // Zero out start_from before fetching raw data — get_recent_outbound()
        // calls get_recent() which applies SQL OFFSET. We need the full
        // (un-offset) result set for correct aggregation; PHP-side pagination
        // in the rendering callback handles page slicing.
        $saved_start = self::$filters_normalized['misc']['start_from'];
        self::$filters_normalized['misc']['start_from'] = 0;
        try {
            $raw_outbound = self::get_recent_outbound();
        } finally {
            self::$filters_normalized['misc']['start_from'] = $saved_start;
        }

        // Aggregate: count hits and track max dt per unique URL
        $aggregated = [];
        foreach ($raw_outbound as $item) {
            $url = $item['url'];
            if (!isset($aggregated[$url])) {
                $aggregated[$url] = ['counthits' => 0, 'dt' => 0];
            }
            $aggregated[$url]['counthits']++;
            if ($item['dt'] > $aggregated[$url]['dt']) {
                $aggregated[$url]['dt'] = $item['dt'];
            }
        }

        // Sort: 'dt' for Recent panel, 'counthits' (default) for Top panel
        if ($sort_by === 'dt') {
            uasort($aggregated, static function ($a, $b) {
                return $b['dt'] <=> $a['dt'];
            });
        } else {
            uasort($aggregated, static function ($a, $b) {
                return $b['counthits'] <=> $a['counthits'] ?: $b['dt'] <=> $a['dt'];
            });
        }

        $sorted_outbound_resources = [];
        foreach ($aggregated as $url => $data) {
            $sorted_outbound_resources[] = [
                'outbound_resource' => $url,
                'counthits'         => $data['counthits'],
                'dt'                => $data['dt'],
            ];
        }

        return $sorted_outbound_resources;
    }

    public static function get_traffic_sources_summary()
    {
        $results           = [];
        $total_human_hits  = wp_slimstat_db::count_records('id', 'visit_id > 0 AND browser_type <> 1');
        $new_visitors      = wp_slimstat_db::count_records_having('ip', 'visit_id > 0', 'COUNT(visit_id) = 1');
        $new_visitors_rate = ($total_human_hits > 0) ? sprintf('%01.2f', (100 * $new_visitors / $total_human_hits)) : 0;
        $server_name       = sanitize_text_field(wp_unslash($_SERVER['SERVER_NAME']));

        if (intval($new_visitors_rate) > 99) {
            $new_visitors_rate = '100';
        }

        $results[0]['metric']  = __('Pageviews', 'wp-slimstat');
        $results[0]['value']   = number_format_i18n(self::$pageviews);
        $results[0]['tooltip'] = __('A pageview is a request to load a single HTML page on your website.', 'wp-slimstat');

        $results[1]['metric']  = __('Unique Referrers', 'wp-slimstat');
        $results[1]['value']   = number_format_i18n(wp_slimstat_db::count_records('referer', 'referer NOT LIKE %s', true, ['%' . $GLOBALS['wpdb']->esc_like($server_name) . '%']));
        $results[1]['tooltip'] = __('A referrer (or referring site) is a site that a visitor previously visited before following a link to your site.', 'wp-slimstat');

        $results[2]['metric']  = __('Direct Pageviews', 'wp-slimstat');
        $results[2]['value']   = number_format_i18n(wp_slimstat_db::count_records('id', 'resource IS NULL'));
        $results[2]['tooltip'] = __("Visitors who typed your website URL directly into their browser address bar. It can also refer to visitors who clicked on one of their bookmarked links, untagged links within emails, or links in documents that don't include tracking variables.", 'wp-slimstat');

        $results[3]['metric']  = __('From External SERP', 'wp-slimstat');
        $results[3]['value']   = number_format_i18n(wp_slimstat_db::count_records('id', 'searchterms IS NOT NULL AND referer IS NOT NULL AND referer NOT LIKE %s', true, ['%' . $GLOBALS['wpdb']->esc_like(home_url()) . '%']));
        $results[3]['tooltip'] = __('Visitors who clicked on a link to your website listed on a search engine result page (SERP). This metric only counts visits coming from EXTERNAL search pages.', 'wp-slimstat');

        $results[4]['metric']  = __('Unique Landing Pages', 'wp-slimstat');
        $results[4]['value']   = number_format_i18n(wp_slimstat_db::count_records('resource'));
        $results[4]['tooltip'] = __("A landing page is the first page on your website that a visitors opens, also known as <em>entrance page</em>. For example, if they search for 'Brooklyn Office Space,' and they land on a page on your website, this page gets counted (for that visit) as a landing page.", 'wp-slimstat');

        $results[5]['metric']  = __('Bounce Pages', 'wp-slimstat');
        $results[5]['value']   = number_format_i18n(wp_slimstat_db::count_bouncing_pages());
        $results[5]['tooltip'] = __('Number of single-page visits tracked over the selected period of time.', 'wp-slimstat');

        $results[6]['metric']  = __('New Visitors Rate', 'wp-slimstat');
        $results[6]['value']   = number_format_i18n($new_visitors_rate, 2);
        $results[6]['tooltip'] = __('Percentage of single-page visits, i.e. visits in which the person left your site from the entrance page.', 'wp-slimstat');

        $results[7]['metric']  = __('Currently from search engines', 'wp-slimstat');
        $results[7]['value']   = number_format_i18n(wp_slimstat_db::count_records('id', 'searchterms IS NOT NULL  AND referer IS NOT NULL AND referer NOT LIKE %s AND dt > UNIX_TIMESTAMP() - 300', false, ['%' . $GLOBALS['wpdb']->esc_like(home_url()) . '%']));
        $results[7]['tooltip'] = __('Visitors who clicked on a link to your website listed on a search engine result page (SERP), tracked in the last 5 minutes.', 'wp-slimstat');

        return $results;
    }

    public static function get_visits_duration()
    {
        $total_human_visits = wp_slimstat_db::count_records('visit_id', 'visit_id > 0 AND browser_type <> 1');
        $results            = [];

        $count_results             = wp_slimstat_db::count_records_having('visit_id', 'visit_id > 0 AND browser_type <> 1', '	GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) >= 0 AND GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) <= 30');
        $average_time              = 30 * $count_results;
        $results[0]['metric']      = __('0 - 30 seconds', 'wp-slimstat');
        $results[0]['value']       = (($total_human_visits > 0) ? number_format_i18n((100 * $count_results / $total_human_visits), 2) : 0) . '%';
        $results[0]['details']     = __('Hits', 'wp-slimstat') . (': ' . $count_results);
        $results[0]['counthits']   = $count_results;

        $count_results             = wp_slimstat_db::count_records_having('visit_id', 'visit_id > 0 AND browser_type <> 1', 'GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) > 30 AND GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) <= 60');
        $average_time             += 60 * $count_results;
        $results[1]['metric']      = __('31 - 60 seconds', 'wp-slimstat');
        $results[1]['value']       = (($total_human_visits > 0) ? number_format_i18n((100 * $count_results / $total_human_visits), 2) : 0) . '%';
        $results[1]['details']     = __('Hits', 'wp-slimstat') . (': ' . $count_results);
        $results[1]['counthits']   = $count_results;

        $count_results             = wp_slimstat_db::count_records_having('visit_id', 'visit_id > 0 AND browser_type <> 1', 'GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) > 60 AND GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) <= 180');
        $average_time             += 180 * $count_results;
        $results[2]['metric']      = __('1 - 3 minutes', 'wp-slimstat');
        $results[2]['value']       = (($total_human_visits > 0) ? number_format_i18n((100 * $count_results / $total_human_visits), 2) : 0) . '%';
        $results[2]['details']     = __('Hits', 'wp-slimstat') . (': ' . $count_results);
        $results[2]['counthits']   = $count_results;

        $count_results             = wp_slimstat_db::count_records_having('visit_id', 'visit_id > 0 AND browser_type <> 1', 'GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) > 180 AND GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) <= 300');
        $average_time             += 300 * $count_results;
        $results[3]['metric']      = __('3 - 5 minutes', 'wp-slimstat');
        $results[3]['value']       = (($total_human_visits > 0) ? number_format_i18n((100 * $count_results / $total_human_visits), 2) : 0) . '%';
        $results[3]['details']     = __('Hits', 'wp-slimstat') . (': ' . $count_results);
        $results[3]['counthits']   = $count_results;

        $count_results             = wp_slimstat_db::count_records_having('visit_id', 'visit_id > 0 AND browser_type <> 1', 'GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) > 300 AND GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) <= 420');
        $average_time             += 420 * $count_results;
        $results[4]['metric']      = __('5 - 7 minutes', 'wp-slimstat');
        $results[4]['value']       = (($total_human_visits > 0) ? number_format_i18n((100 * $count_results / $total_human_visits), 2) : 0) . '%';
        $results[4]['details']     = __('Hits', 'wp-slimstat') . (': ' . $count_results);
        $results[4]['counthits']   = $count_results;

        $count_results             = wp_slimstat_db::count_records_having('visit_id', 'visit_id > 0 AND browser_type <> 1', 'GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) > 420 AND GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) <= 600');
        $average_time             += 600 * $count_results;
        $results[5]['metric']      = __('7 - 10 minutes', 'wp-slimstat');
        $results[5]['value']       = (($total_human_visits > 0) ? number_format_i18n((100 * $count_results / $total_human_visits), 2) : 0) . '%';
        $results[5]['details']     = __('Hits', 'wp-slimstat') . (': ' . $count_results);
        $results[5]['counthits']   = $count_results;

        $count_results             = wp_slimstat_db::count_records_having('visit_id', 'visit_id > 0 AND browser_type <> 1', 'GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) > 600');
        $average_time             += 900 * $count_results;
        $results[6]['metric']      = __('More than 10 minutes', 'wp-slimstat');
        $results[6]['value']       = (($total_human_visits > 0) ? number_format_i18n((100 * $count_results / $total_human_visits), 2) : 0) . '%';
        $results[6]['details']     = __('Hits', 'wp-slimstat') . (': ' . $count_results);
        $results[6]['counthits']   = $count_results;

        // Sort time buckets by most hits first
        usort($results, static function ($a, $b) {
            return ($b['counthits'] ?? 0) <=> ($a['counthits'] ?? 0);
        });

        if ($total_human_visits > 0) {
            $average_time = intval($average_time / $total_human_visits);
            // gmdate (not date) so the elapsed-seconds value isn't shifted by the
            // site timezone; 'i:s' = minutes:seconds ('m' was the month token).
            $average_time = gmdate($average_time >= 3600 ? 'H:i:s' : 'i:s', $average_time);
        } else {
            $average_time = '0:00';
        }

        // Average row always at the bottom
        $results[]  = [
            'metric'  => __('Average Visit Duration', 'wp-slimstat'),
            'value'   => $average_time,
            'details' => '',
        ];

        return $results;
    }

    public static function get_visitors_summary()
    {
        $results            = [];
        $total_visits       = wp_slimstat_db::count_records('visit_id', 'browser_type <> 1');
        $single_page_visits = wp_slimstat_db::count_records_having('visit_id', 'browser_type <> 1', 'COUNT(id) = 1');

        $bounce_rate       = ($total_visits > 0) ? (100 * $single_page_visits / $total_visits) : 0;
        $metrics_per_visit = wp_slimstat_db::get_max_and_average_pages_per_visit();
        if (empty($metrics_per_visit[0])) {
            $metrics_per_visit[0] = ['avghits' => 0, 'maxhits' => 0];
        }

        if (intval($bounce_rate) > 99) {
            $bounce_rate = '100';
        }

        $results[0]['metric']  = __('Visits', 'wp-slimstat');
        $results[0]['value']   = number_format_i18n(wp_slimstat_db::count_records('visit_id', 'visit_id > 0 AND browser_type <> 1'));
        $results[0]['tooltip'] = __('A visit is a group of pageviews within a 30-minute time span. Returning visitors are counted multiple times if they start a new visit.', 'wp-slimstat');

        $results[1]['metric']  = __('Unique IPs', 'wp-slimstat');
        $results[1]['value']   = number_format_i18n(wp_slimstat_db::count_records('ip', 'visit_id > 0 AND browser_type <> 1'));
        $results[1]['tooltip'] = __('It includes only traffic generated by human visitors.', 'wp-slimstat');

        $results[2]['metric']  = __('Bounce rate', 'wp-slimstat');
        $results[2]['value']   = number_format_i18n($bounce_rate, 2);
        $results[2]['tooltip'] = __('Total number of one-page visits divided by the total number of entries to a website. Please see the <a href="https://support.google.com/analytics/answer/1009409?hl=en" target="_blank">official Google docs</a> for more information.', 'wp-slimstat');

        $results[3]['metric']  = __('Known visitors', 'wp-slimstat');
        $results[3]['value']   = number_format_i18n(wp_slimstat_db::count_records('username'));
        $results[3]['tooltip'] = __('Visitors who have previously left a comment on your blog.', 'wp-slimstat');

        $results[4]['metric']  = __('Single-page Visits', 'wp-slimstat');
        $results[4]['value']   = number_format_i18n($single_page_visits);
        $results[4]['tooltip'] = __('Human users that generated one single page view on your website.', 'wp-slimstat');

        $results[5]['metric'] = __('Bots', 'wp-slimstat');
        $results[5]['value']  = number_format_i18n(wp_slimstat_db::count_records('id', 'browser_type = 1'));

        $results[6]['metric'] = __('Pageviews per visit', 'wp-slimstat');
        $results[6]['value']  = number_format_i18n($metrics_per_visit[0]['avghits'], 2);

        $results[7]['metric'] = __('Longest visit', 'wp-slimstat');
        $results[7]['value']  = number_format_i18n($metrics_per_visit[0]['maxhits']) . ' ' . __('hits', 'wp-slimstat');

        return $results;
    }

    public static function get_your_blog()
    {
        if (false === ($results = get_transient('slimstat_your_content'))) {
            $results      = [];
            $posts_table  = $GLOBALS['wpdb']->posts;
            $comments_table = $GLOBALS['wpdb']->comments;
            $slim_stats_table = $GLOBALS['wpdb']->prefix . 'slim_stats';

            // The six post/comment metrics query CORE tables (wp_posts, wp_comments), so
            // they run on the LOCAL connection — not the analytics one the constructor
            // binds. Under the custom-DB add-on the analytics handle is a different
            // database with no wp_posts, and every one of these read zero or errored
            // silently (F6/C44). Only the latency metric below queries slim_stats.
            $results[0]['metric']  = __('Content Items', 'wp-slimstat');
            $results[0]['value']   = number_format_i18n(Query::select('COUNT(*)')->local()->from($posts_table)->where('post_type', '!=', 'revision')->where('post_status', '!=', 'auto-draft')->getVar());
            $results[0]['tooltip'] = __('This value includes not only posts and pages, but any custom post type, regardless of their status.', 'wp-slimstat');

            $results[1]['metric'] = __('Posts', 'wp-slimstat');
            $results[1]['value']  = Query::select('COUNT(*)')->local()->from($posts_table)->where('post_type', '=', 'post')->getVar();

            $results[2]['metric'] = __('Pages', 'wp-slimstat');
            $results[2]['value']  = number_format_i18n(Query::select('COUNT(*)')->local()->from($posts_table)->where('post_type', '=', 'page')->getVar());

            $results[3]['metric'] = __('Attachments', 'wp-slimstat');
            $results[3]['value']  = number_format_i18n(Query::select('COUNT(*)')->local()->from($posts_table)->where('post_type', '=', 'attachment')->getVar());

            $results[4]['metric'] = __('Revisions', 'wp-slimstat');
            $results[4]['value']  = number_format_i18n(Query::select('COUNT(*)')->local()->from($posts_table)->where('post_type', '=', 'revision')->getVar());

            $results[5]['metric'] = __('Comments', 'wp-slimstat');
            $results[5]['value']  = Query::select('COUNT(*)')->local()->from($comments_table)->getVar();

            $results[6]['metric'] = __('Avg Comments per Post', 'wp-slimstat');
            $results[6]['value']  = empty($results[1]['value']) ? 0 : number_format_i18n($results[5]['value'] / $results[1]['value']);

            $results[7]['metric']  = __('Avg Server Latency', 'wp-slimstat');
            $results[7]['value']   = number_format_i18n(Query::select('AVG(server_latency)')->from($slim_stats_table)->where('server_latency', '!=', 0)->getVar());
            $results[7]['tooltip'] = __('Latency is the amount of time it takes for the host server to receive and process a request for a page object. The amount of latency depends largely on how far away the user is from the server.', 'wp-slimstat');

            $results[1]['value'] = number_format_i18n($results[1]['value']);
            $results[5]['value'] = number_format_i18n($results[5]['value']);

            // Store values as transients for 30 minutes
            set_transient('slimstat_your_content', $results, 1800);
        }

        return $results;
    }

    // ---- Goals & Funnels Query Methods ---- //

    /**
     * Returns a prepared SQL WHERE fragment for a single goal/step condition.
     * Uses the existing get_single_where_clause() which returns an already-prepared string.
     *
     * @param array  $goal   Goal definition with dimension, operator, value keys.
     * @param string $alias  Table alias (e.g., 't1' or 'te').
     * @return string Prepared SQL WHERE fragment (e.g., "t1.resource = '/shop/'").
     */
    /**
     * AND a caller-supplied scope onto a WHERE — parenthesized, because the caller's
     * clause may contain OR (the email loop already ANDs a report's own `where` with
     * `author = %s` upstream). One owner for that invariant: a scoped aggregate that
     * appends without the parens is a silent precedence bug. Empty scope returns the
     * WHERE untouched, byte-for-byte.
     */
    private static function and_extra_where($where, $extra_where)
    {
        return '' === $extra_where ? $where : $where . ' AND (' . $extra_where . ')';
    }

    private static function build_goal_where($goal, $alias = '')
    {
        // Read keys defensively: legacy/malformed stored goals or funnel steps
        // may be missing a field, and the report render path must not emit
        // undefined-array-key notices. A missing dimension/operator yields no
        // clause (preserving the empty-where -> 0-results contract). (#6)
        $dimension = (string) ($goal['dimension'] ?? '');
        $operator  = (string) ($goal['operator'] ?? '');
        $value     = (string) ($goal['value'] ?? '');

        if ('' === $dimension || '' === $operator) {
            return '';
        }

        // Defense-in-depth: a value-bearing operator with an empty value makes
        // get_single_where_clause() return an unprepared fragment that still
        // contains a literal "%s" placeholder (it skips prepare() when the value
        // is empty). sanitize_goal() already rejects this at save time, but guard
        // the query layer too so such a clause can never reach $wpdb->query().
        // Only the valueless operators (is_empty / is_not_empty) may run without a value.
        if ('' === $value && !in_array($operator, self::$valueless_operators, true)) {
            return '';
        }

        // Event-based goals query the events table notes column
        if ($dimension === 'event_notes') {
            $dimension = 'notes';
            if (empty($alias)) {
                $alias = 'te';
            }
        }

        return self::get_single_where_clause($dimension, $operator, $value, $alias);
    }

    /**
     * Visitor identifier expression that handles NULL fingerprints:
     * COALESCE(fingerprint, HEX(vid_hash), 'v_'+visit_id, 'ip_'+ip). Used both to
     * populate the funnel temp tables (SELECT/INSERT) and, via
     * count_unique_visitors(), to count distinct goal visitors — so goals and
     * funnels share one identity and neither silently drops visitors that lack a
     * fingerprint. The expression is only ever used in SELECT output, never in a
     * WHERE clause.
     *
     * The vid_hash tier (D68/P2) sits between fingerprint and visit_id: for a
     * cookieless visitor it is the real 128-bit identity, where visit_id is a
     * sequential SESSION number — resolving identity through it counted one
     * person once per session, and before D68 merged strangers outright at 32
     * bits. HEX() because the ladder concatenates with string tiers; NULL rows
     * (all history, and consenting visitors) fall through exactly as before.
     */
    private static function visitor_id_expr($alias = '')
    {
        $prefix = !empty($alias) ? $alias . '.' : '';
        return sprintf(
            "COALESCE(%sfingerprint, HEX(%svid_hash), CONCAT('v_', %svisit_id), CONCAT('ip_', %sip))",
            $prefix,
            $prefix,
            $prefix,
            $prefix
        );
    }

    /**
     * Counts distinct visitors using the NULL-safe visitor identity
     * (COALESCE(fingerprint, visit_id, ip)) that funnels already use, so a
     * segment dominated by NULL-fingerprint rows — bots/crawlers,
     * consent-limited sessions, or rows recorded before the fingerprint feature
     * shipped — is no longer silently dropped (the symptom: "Country" goals
     * showing a correct Total but 0 Uniques). Goal uniques now agree with funnel
     * step-1 counts for the same rule.
     *
     * No "fingerprint IS NOT NULL" filter is needed because the COALESCE
     * expression is never NULL. Keeps the subquery-decomposition form (SELECT
     * COUNT(*) FROM (SELECT DISTINCT ...)) for the documented speedup over
     * COUNT(DISTINCT). (#3)
     *
     * @param string $from_clause  SQL FROM + JOIN.
     * @param string $where_clause SQL WHERE conditions (already prepared).
     * @param string $alias        Table alias the visitor columns live on.
     * @return int
     */
    private static function count_unique_visitors($from_clause, $where_clause, $alias = 't1')
    {
        return intval(wp_slimstat::$wpdb->get_var(sprintf(
            "SELECT COUNT(*) FROM (SELECT DISTINCT %s AS vid FROM %s WHERE %s) AS uv",
            self::visitor_id_expr($alias),
            $from_clause,
            $where_clause
        )));
    }

    /**
     * Get results for a single goal: total hits, unique visitors, conversion rate.
     *
     * @param array $goal Goal definition.
     * @return array ['total' => int, 'uniques' => int, 'cr' => float]
     */
    public static function get_goal_results($goal, $extra_where = '')
    {
        $table_stats  = $GLOBALS['wpdb']->prefix . 'slim_stats';
        $table_events = $GLOBALS['wpdb']->prefix . 'slim_events';
        $is_event     = ($goal['dimension'] === 'event_notes');

        $goal_where = self::build_goal_where($goal, $is_event ? 'te' : 't1');
        if (empty($goal_where)) {
            return ['total' => 0, 'uniques' => 0, 'cr' => 0.0, 'total_visitors' => 0];
        }

        $cache_ver = get_option('slimstat_goals_cache_ver', '0');
        // Built from the normalized filters and a bucketed range — see
        // results_cache_key(). The old key hashed get_combined_where()'s SQL, which
        // moved every second and every request. (D33)
        //
        // A caller-scoped WHERE must be IN the key, or the fix it exists for undoes
        // itself: the per-author email loop runs every author in ONE request, so
        // without the key component the first author's numbers would be served to all
        // the rest — from the memo below deterministically, from this transient for
        // five minutes. Empty extra keeps the exact pre-D58 key, so dashboards keep
        // their cache continuity.
        $goal_key  = (string) $goal['id'] . ('' === $extra_where ? '' : '|' . md5($extra_where));
        $cache_key = self::results_cache_key('goal', $goal_key, $cache_ver);

        // Per-request memo keyed by the result-determining signature (criteria +
        // filters + cache version), NOT the goal id — so several goals with the
        // same criteria run the COUNT/unique queries once per request instead of
        // once each, and a re-render reuses the result. Removes the duplicate
        // COUNT(*)/unique queries Query Monitor reported. (#12)
        //
        // Keyed on filters_signature() rather than the rendered WHERE so that WHERE
        // does not have to be built before the memo and transient are consulted.
        static $request_memo = [];
        $memo_key = md5($goal_where . '|' . self::filters_signature() . '|' . $cache_key);
        if (array_key_exists($memo_key, $request_memo)) {
            return $request_memo[$memo_key];
        }

        $result = get_transient($cache_key);

        if (false === $result) {
            // Built only after the cache miss — it drives the queries below, not the
            // key, so a memo or transient hit skips this get_combined_where() work
            // entirely — the shape get_funnel_results() already uses.
            $filters_where  = self::get_combined_where('', '*', true, 't1');
            $where_combined = self::and_extra_where($goal_where . ' AND ' . $filters_where, $extra_where);

            if ($is_event) {
                $from = sprintf('%s te INNER JOIN %s t1 ON te.id = t1.id', $table_events, $table_stats);
            } else {
                $from = sprintf('%s t1', $table_stats);
            }

            $total   = intval(wp_slimstat::$wpdb->get_var("SELECT COUNT(*) FROM $from WHERE $where_combined"));
            // NULL-safe distinct-visitor count (COALESCE id) so segments full of
            // NULL-fingerprint rows aren't reported as 0 uniques, and goal uniques
            // match funnel step-1 counts for the same rule. (#3)
            $uniques = self::count_unique_visitors($from, $where_combined);

            // The SAME scope as the numerator: a per-author conversion rate over the
            // whole site's visitors would be a ratio of two different populations.
            $total_visitors = self::get_total_unique_visitors($extra_where);
            $cr = ($total_visitors > 0) ? round(($uniques / $total_visitors) * 100, 2) : 0.0;

            // total_visitors is the CR denominator — returned so the card can show
            // "N of M uniques" and make the percentage legible without re-querying. (#13)
            $result = ['total' => $total, 'uniques' => $uniques, 'cr' => $cr, 'total_visitors' => $total_visitors];
            set_transient($cache_key, $result, 5 * MINUTE_IN_SECONDS);
        }

        $request_memo[$memo_key] = $result;
        return $result;
    }

    /**
     * Get total unique visitors in the current date range.
     * Cached as transient (15 min TTL) + in-request static var.
     *
     * @return int
     */
    private static function get_total_unique_visitors($extra_where = '')
    {
        // Keyed memo, not a scalar: the per-author email loop asks for a different
        // scope per author within one request, and a scalar memo would hand author
        // two the denominator of author one.
        static $request_cache = [];
        $memo_key = md5($extra_where);
        if (isset($request_cache[$memo_key])) {
            return $request_cache[$memo_key];
        }

        // Version-key like the goal/funnel transients so a CRUD cache bump (which
        // also runs the GC in clear_goals_cache()) rotates this denominator too.
        $cache_ver = get_option('slimstat_goals_cache_ver', '0');
        // Same shared builder as goals and funnels — this denominator is the single
        // most expensive statement on the goals screen, and it was recomputed on every
        // render for the same reason. (D37) Empty extra keeps the exact pre-D58 key.
        // The memo token IS the transient's scope token — one digest, computed once, so
        // the two layers cannot disagree about what a scope is.
        $cache_key = self::results_cache_key('uv', '' === $extra_where ? '' : $memo_key, $cache_ver);
        $cached    = get_transient($cache_key);

        if (false !== $cached) {
            $request_cache[$memo_key] = intval($cached);
            return $request_cache[$memo_key];
        }

        // Same NULL-safe visitor identity as the goal numerator (count_unique_visitors)
        // so the conversion-rate denominator and numerator stay consistent. (#3)
        // The WHERE is built only past the cache check — it drives the query, not the key.
        $total = self::count_unique_visitors(
            sprintf('%s t1', $GLOBALS['wpdb']->prefix . 'slim_stats'),
            self::and_extra_where(self::get_combined_where('', '*', true, 't1'), $extra_where)
        );

        set_transient($cache_key, $total, 15 * MINUTE_IN_SECONDS);
        $request_cache[$memo_key] = $total;
        return $total;
    }

    /**
     * Get raw goal results as flat array (for Export CSV / Email Reports).
     * Accepts standard $_args array like get_top().
     *
     * @param array $_args Callback args from report definition.
     * @return array Array of associative arrays with goal_name, uniques, total, cr keys.
     */
    public static function get_goals_raw($_args = [])
    {
        $goals   = get_option('slimstat_goals', []);
        $results = [];
        // Bounded by the tier maximum, as the widget renderer is. This runs on the
        // email-report cron, where an over-limit option (a Pro-to-free downgrade, an
        // import) would otherwise compute an aggregate per stored goal. (D14)
        $remaining = (int) apply_filters('slimstat_max_goals', 1);

        // The caller's WHERE, honoured at last. This function declared $_args and read
        // none of it, so the per-author email loop — which ANDs `author = %s` into the
        // args of every report it mails — sent every author the SITE-WIDE goal numbers
        // under their own name. (D58; the registered Expected Diff is R9: each author's
        // numbers FALL to their own.)
        $extra_where = empty($_args['where']) ? '' : (string) $_args['where'];

        foreach ($goals as $goal) {
            if (empty($goal['active']) || empty($goal['name']) || empty($goal['dimension'])) {
                continue;
            }
            if ($remaining <= 0) {
                break;
            }
            $remaining--;
            $data      = self::get_goal_results($goal, $extra_where);
            $results[] = [
                'goal_name' => $goal['name'],
                'uniques'   => $data['uniques'],
                'total'     => $data['total'],
                'cr'        => $data['cr'] . '%',
            ];
        }

        return $results;
    }

    /**
     * Reduce a funnel's steps to the fields that actually determine the query
     * result — dimension, operator, value, in order — so two funnels with the
     * same rules (ignoring id, name and per-step labels) hash to the same cache
     * signature and therefore return identical numbers. Order is significant:
     * A->B->C is a different journey than C->B->A, so steps are NOT sorted. The
     * fields mirror exactly what build_goal_where() reads, so a shared signature
     * guarantees a shared WHERE clause — never a wrong-result collision. (#19)
     *
     * @param array $steps
     * @return array<int,array{dimension:string,operator:string,value:string}>
     */
    private static function normalize_funnel_steps($steps)
    {
        return array_map(
            static fn($step) => [
                'dimension' => (string) ($step['dimension'] ?? ''),
                'operator'  => (string) ($step['operator'] ?? ''),
                'value'     => (string) ($step['value'] ?? ''),
            ],
            array_values((array) $steps)
        );
    }

    /**
     * Signature of the active global column filters, for the goal / funnel / uv
     * cache keys.
     *
     * Derived from the NORMALIZED filter array ([col => [operator, value]]), NOT
     * from get_combined_where()'s SQL: that SQL is run through $wpdb->prepare(),
     * whose per-request placeholder salt would make the signature unstable and
     * re-split a server-rendered funnel from its AJAX-loaded twin (the very #1
     * symptom). Serializing the normalized array is request-stable by construction
     * and mirrors normalize_funnel_steps(). An empty/absent filter set hashes to a
     * fixed baseline so an unfiltered render is a stable key. (#22)
     *
     * @return string
     */
    private static function filters_signature()
    {
        return md5(serialize(self::$filters_normalized['columns'] ?? []));
    }

    /**
     * Bucket size for the live end of a cached date range, in seconds.
     *
     * Mirrors the hour-bucketing in wp_slimstat_admin::build_filter_options_cache_key().
     * A plain literal rather than HOUR_IN_SECONDS so this class stays loadable without
     * WordPress, which the cache-key test relies on.
     */
    const CACHE_RANGE_BUCKET_SECONDS = 3600;

    /**
     * Build the cache key for a goals/funnels result set.
     *
     * One builder for all three kinds of cached result, because they share one hazard:
     * the date range's END is "now" for any live range, set with second precision by
     * init_filters(). A key that embeds it changes on every request, so a 5- or
     * 15-minute transient is written, never read back, and left to expire. Funnels
     * bucketed the end to the hour to fix that; goals and the unique-visitor
     * denominator did not, and each ran their queries uncached on every render while
     * writing two dead wp_options rows apiece. (D33, D37)
     *
     * Bucketing trades key churn for bounded staleness, and the bound still comes from
     * the transient's own TTL: within a bucket the key is stable, so the value
     * refreshes on the TTL as intended rather than never being reused at all. The cost
     * is that a render pair straddling a bucket boundary does not share a key and
     * misses once — a rare, few-second window that self-heals on the next render, and
     * a far better trade than a key that never changes and serves a stale window
     * indefinitely.
     *
     * The range and the filter signature are read here rather than passed in, so a
     * caller cannot pair one request's filters with another's window. The signature
     * comes from the NORMALIZED filter array, never from get_combined_where()'s SQL:
     * that SQL is run through $wpdb->prepare(), which wraps LIKE values in a
     * placeholder salt regenerated per request —
     *
     *     t1.browser LIKE '{d71290c0…}Chrome{d71290c0…}'
     *     t1.browser LIKE '{677774e4…}Chrome{677774e4…}'   <- same filter, next request
     *
     * so any "contains" filter would move the key every request even with the range
     * bucketed. That is a second, independent reason the goal key could never hit.
     *
     * The `slimstat_<prefix>_` shape is load-bearing: clear_goals_cache() and
     * uninstall.php both sweep these rows by LIKE prefix, and a key outside it would
     * accumulate forever.
     *
     * @param string     $prefix    Result type: goal, funnel, uv.
     * @param string     $scope     What distinguishes one result of that type from
     *                              another (goal id, funnel step signature); '' when
     *                              the type has a single result per filter set.
     * @param int|string $cache_ver slimstat_goals_cache_ver, bumped by any CRUD.
     * @return string
     */
    private static function results_cache_key($prefix, $scope, $cache_ver)
    {
        $start = (int) (self::$filters_normalized['utime']['start'] ?? 0);
        $end   = (int) (self::$filters_normalized['utime']['end'] ?? 0);
        $range = $start . ':' . (int) floor($end / self::CACHE_RANGE_BUCKET_SECONDS);

        return 'slimstat_' . $prefix . '_' . ('' === $scope ? '' : $scope . '_')
            . md5($range . '|' . self::filters_signature() . '|' . $cache_ver);
    }

    /**
     * Scope a funnel's cache key to its RULES rather than its id, so two funnels with
     * identical steps share one entry and can never disagree. (#1, #19)
     *
     * Everything else about the key — the bucketed window, the filter signature, the
     * cache version, the swept prefix — lives in results_cache_key().
     *
     * @param array      $steps
     * @param int|string $cache_ver
     * @return string
     */
    private static function funnel_cache_key($steps, $cache_ver)
    {
        return self::results_cache_key(
            'funnel',
            md5(serialize(self::normalize_funnel_steps($steps))),
            $cache_ver
        );
    }

    /**
     * Get funnel results: visitors at each step with drop-off.
     * Uses iterative PHP approach for MySQL 5.6 compatibility.
     *
     * Cached for 5 minutes via a version-keyed transient, invalidated by
     * wp_slimstat_admin::clear_goals_cache() on any goal/funnel CRUD.
     *
     * @param array $funnel Funnel definition with steps array.
     * @return array Array of step results: name, visitors, pct, dropoff, unreachable.
     */
    public static function get_funnel_results($funnel, $extra_where = '')
    {
        if (empty($funnel['steps']) || count($funnel['steps']) < 2) {
            return [];
        }

        $cache_ver = get_option('slimstat_goals_cache_ver', '0');
        // Keyed on the normalized step signature, not the funnel id, so two identical
        // funnels — and a server-rendered funnel plus its AJAX twin — share one
        // transient. See results_cache_key() for the window and filter handling.
        // (#1, #22, builds on #19)
        //
        // A caller-scoped WHERE joins the key for the same reason as in
        // get_goal_results() — and here the scope-blind serve would come via the memo
        // below deterministically. Empty extra keeps the exact pre-D58 key, so the
        // dashboard/AJAX sharing is untouched.
        $cache_key = self::funnel_cache_key($funnel['steps'], $cache_ver)
            . ('' === $extra_where ? '' : '_' . md5($extra_where));

        // Per-request memo: a funnel rendered (or re-rendered) twice in one
        // request reuses its result instead of rebuilding temp tables again. (#12)
        static $request_memo = [];
        if (array_key_exists($cache_key, $request_memo)) {
            return $request_memo[$cache_key];
        }

        $cached = get_transient($cache_key);
        if (false !== $cached) {
            $request_memo[$cache_key] = $cached;
            return $cached;
        }

        // Built only after the cache miss — it drives the step queries below, not the
        // cache key, so a memo/transient hit skips this get_combined_where() work.
        // The caller's scope rides inside $date_where because both step-query shapes
        // below embed it — one append covers every step of the chain.
        $date_where = self::and_extra_where(self::get_combined_where('', '*', true, 't1'), $extra_where);

        $table_stats  = $GLOBALS['wpdb']->prefix . 'slim_stats';
        $table_events = $GLOBALS['wpdb']->prefix . 'slim_events';
        $visitor_id   = self::visitor_id_expr('t1');

        // Two-table swap pattern: READ holds previous step's visitors,
        // WRITE receives current step's results. After each step, WRITE
        // is renamed to READ. This avoids the self-referencing temp table
        // bug where DROP + CREATE AS SELECT ... IN (SELECT vid FROM same_table) fails.
        // Fixed names are safe: TEMPORARY tables are session-scoped, a connection
        // serves one request at a time, and the preflight DROP IF EXISTS clears
        // any stale leftovers — so no cross-call/connection collision can occur.
        $temp_read  = $GLOBALS['wpdb']->prefix . 'slim_funnel_read';
        $temp_write = $GLOBALS['wpdb']->prefix . 'slim_funnel_write';

        $results     = [];
        $step1_count = 0;
        $use_temp    = false;
        $preflight   = false;
        $had_error   = false;

        // Each temp table row carries (vid, t, rid, rkind) — the visitor identifier, the
        // MIN(dt) at which they qualified for the preceding step, and which physical row
        // that was. The JOIN on step N+ enforces `new_row.dt >= r.t` so out-of-order
        // matches (visitor hit step N before step N-1) don't count as converted. `>=`
        // rather than `>` because dt has one-second granularity: two genuinely ordered
        // steps that land in the same second (fast SPA navigation, a pageview immediately
        // followed by an event row) must still count.
        //
        // But `>=` alone lets ONE physical pageview satisfy TWO steps whenever the rules
        // overlap — "contains shop" then "contains shop/cart" against a single visit to
        // /shop/cart — and report a conversion that never happened. This used to be
        // waved away with "distinct step rules keep the same physical row from satisfying
        // two steps at once"; nothing enforces that, and `ajax_save_funnel()` validates
        // step count and shape but never distinctness. Measured on scratch tables: a
        // visitor with exactly one pageview converted a two-step funnel.
        //
        // Tightening to `>` fixes that case and breaks a real one — measured in the same
        // run, a visitor with two SEPARATE pageviews in the same second stopped counting.
        // Neither timestamp comparison is the right test, because the question is not
        // "later" but "a different row". So the row identity travels with the timestamp
        // and step N+1 excludes exactly the row that satisfied step N.
        //
        // The id must be an ARGMIN — the id of the row that achieved MIN(dt) — not a second
        // MIN() beside it. See the note at the query itself for why the obvious
        // `MIN(dt), MIN(id)` pairing is wrong and how it reopens this very defect. (D54)
        foreach ($funnel['steps'] as $step_index => $step) {
            $is_event   = ($step['dimension'] === 'event_notes');
            $step_where = self::build_goal_where($step, $is_event ? 'te' : 't1');

            if (empty($step_where)) {
                $results[] = ['name' => $step['name'], 'visitors' => 0, 'pct' => 0, 'dropoff' => 0, 'unreachable' => false];
                $use_temp = false;
                wp_slimstat::$wpdb->query("DROP TEMPORARY TABLE IF EXISTS $temp_read");
                continue;
            }

            if ($step_index > 0 && !$use_temp) {
                // Previous step already returned zero — every downstream step
                // is unreachable, no point hitting SQL.
                $results[] = ['name' => $step['name'], 'visitors' => 0, 'pct' => 0, 'dropoff' => 0, 'unreachable' => false];
                continue;
            }

            // Event steps use te.dt (the event's own timestamp) as the ordering
            // time. Pageview steps use t1.dt.
            $dt_expr = $is_event ? 'te.dt' : 't1.dt';

            // Which physical row satisfied the step. Pageview steps are identified by the
            // pageview id, event steps by the event id.
            $row_id_expr = $is_event ? 'te.event_id' : 't1.id';

            // The id of the row that actually achieved MIN(dt) — an argmin, not a second
            // independent aggregate. `MIN(dt)` and `MIN(id)` computed side by side do NOT
            // describe the same row: they are evaluated independently over the group, so a
            // visitor with rows (id 5, dt 100) and (id 8, dt 90) yields t=90 with rid=5.
            // Then step N+1 excludes a row that never satisfied step N while the row that
            // did stays eligible — reopening the very defect this carries rid to close.
            // The two orders agree only if id and dt are co-monotonic, and they are not
            // under concurrent writers: dt is stamped by PHP before the INSERT, so a
            // request that starts later can still commit first and take a lower id.
            //
            // SUBSTRING_INDEX(GROUP_CONCAT(... ORDER BY dt, id), ',', 1) is the standard
            // argmin for MySQL 5.6, which has no window functions. Truncation at
            // group_concat_max_len drops from the END, so the first element — the only one
            // read — is always intact.
            $argmin_row_id = sprintf(
                "CAST(SUBSTRING_INDEX(GROUP_CONCAT(%s ORDER BY %s ASC, %s ASC), ',', 1) AS UNSIGNED)",
                $row_id_expr, $dt_expr, $row_id_expr
            );

            // Base FROM clause — joined for event steps, plain for pageview steps.
            $from_sql = $is_event
                ? sprintf('%s te INNER JOIN %s t1 ON te.id = t1.id', $table_events, $table_stats)
                : sprintf('%s t1', $table_stats);

            if ($step_index === 0) {
                // Step 1: per-visitor MIN(dt) within the date window, carrying the row that
                // achieved it.
                $select_sql = sprintf(
                    "SELECT %s AS vid, MIN(%s) AS t, %s AS rid FROM %s WHERE %s AND %s GROUP BY vid",
                    $visitor_id, $dt_expr, $argmin_row_id, $from_sql, $step_where, $date_where
                );
            } else {
                // Step N>1: JOIN temp_read and require the new row's dt at or after the
                // stored timestamp for the same visitor.
                //
                // The row-exclusion only applies when this step reads the same table as the
                // one before it. Pageview ids and event ids are independent counters, so
                // across a kind change any equality between them is a coincidence rather
                // than identity — and a pageview row and an event row are never the same
                // physical row anyway. Omitting the predicate there is both correct and
                // cheaper than carrying a table marker to make it provably false.
                $prev_is_event = ('event_notes' === ($funnel['steps'][$step_index - 1]['dimension'] ?? ''));
                $exclude_prev  = ($prev_is_event === $is_event)
                    ? sprintf(' AND %s <> r.rid', $row_id_expr)
                    : '';

                $select_sql = sprintf(
                    "SELECT %s AS vid, MIN(%s) AS t, %s AS rid FROM %s INNER JOIN %s r ON r.vid = %s"
                        . " WHERE %s AND %s AND %s >= r.t%s GROUP BY vid",
                    $visitor_id, $dt_expr, $argmin_row_id, $from_sql, $temp_read, $visitor_id,
                    $step_where, $date_where, $dt_expr, $exclude_prev
                );
            }

            // Lazy preflight: only drop stale temps on the first step that actually runs SQL.
            if (!$preflight) {
                wp_slimstat::$wpdb->query("DROP TEMPORARY TABLE IF EXISTS $temp_read");
                wp_slimstat::$wpdb->query("DROP TEMPORARY TABLE IF EXISTS $temp_write");
                $preflight = true;
            }

            // Create the per-step temp table once, then count from it — avoids
            // running the grouped subquery twice for the same step.
            //
            // The columns are DERIVED from the SELECT rather than declared. Declaring
            // `vid VARCHAR(64)` gave it the database's default collation, and step 2 then
            // joins that column against the visitor-identity expression, which carries the
            // source column's collation. When the two differ — the ordinary result of a
            // charset migration, where the table was created under one collation and the
            // database default is now another — MySQL refuses the comparison:
            //
            //   Illegal mix of collations (utf8mb4_unicode_520_ci,IMPLICIT)
            //                         and (utf8mb4_general_ci,IMPLICIT) for operation '='
            //
            // and every step from the second onward reports 0 visitors. Reproduced on
            // scratch tables; deriving the column fixes it because `vid` then inherits the
            // expression's own collation. (Note it is NOT enough for the database to be
            // utf8mb4 while the columns are utf8mb3 — that is a coercible superset and
            // joins fine, which is why this looked unreproducible at first.)
            //
            // Deriving also drops the VARCHAR(64) ceiling. Visitor identities on the
            // reference dataset reach 73 characters (740 rows over 64), and WordPress
            // clears STRICT_TRANS_TABLES, so the overflow truncated silently rather than
            // erroring — two identities sharing a 64-character prefix would have been
            // merged into one visitor. None do on that dataset, but the derived column is
            // VARCHAR(256) and the question no longer arises. (D53, D16)
            wp_slimstat::$wpdb->query("DROP TEMPORARY TABLE IF EXISTS $temp_write");
            $created = wp_slimstat::$wpdb->query("CREATE TEMPORARY TABLE $temp_write (KEY(vid)) AS $select_sql");

            // If CREATE … AS SELECT failed (malformed step rule, STRICT-mode
            // truncation, missing CREATE TEMPORARY privilege, deadlock), the
            // write table does not exist. Proceeding would COUNT a missing table
            // (→ 0), then DROP the previous valid READ and RENAME a missing WRITE,
            // silently zeroing this and every downstream step and presenting a
            // corrupt funnel as real data. Bail with the steps gathered so far,
            // flag this step as errored, and don't cache the partial result so a
            // transient failure self-heals on the next request.
            if (false === $created) {
                if ('on' == wp_slimstat::$settings['show_sql_debug'] && !empty(wp_slimstat::$wpdb->last_error)) {
                    self::$debug_message .= sprintf("<p class='debug'>Funnel step query failed: %s</p>", esc_html(wp_slimstat::$wpdb->last_error));
                }
                wp_slimstat::$wpdb->query("DROP TEMPORARY TABLE IF EXISTS $temp_read");
                wp_slimstat::$wpdb->query("DROP TEMPORARY TABLE IF EXISTS $temp_write");
                $results[] = ['name' => $step['name'], 'visitors' => 0, 'pct' => 0, 'dropoff' => 0, 'unreachable' => false];
                $had_error = true;
                break;
            }

            $visitor_count = intval(wp_slimstat::$wpdb->get_var("SELECT COUNT(*) FROM $temp_write"));

            if ($step_index === 0) {
                $step1_count = $visitor_count;
            }

            // Swap: drop old READ, rename WRITE → READ for next iteration.
            wp_slimstat::$wpdb->query("DROP TEMPORARY TABLE IF EXISTS $temp_read");
            wp_slimstat::$wpdb->query("ALTER TABLE $temp_write RENAME TO $temp_read");
            $use_temp = ($visitor_count > 0);

            $prev_count = ($step_index > 0 && !empty($results[$step_index - 1])) ? $results[$step_index - 1]['visitors'] : $visitor_count;
            $dropoff    = $prev_count - $visitor_count;

            // A step is "unreachable" when the previous step had visitors but none
            // carried through — usually a rule typo or an impossible ordering.
            $unreachable = ($step_index > 0 && $visitor_count === 0 && $prev_count > 0);

            $results[] = [
                'name'        => $step['name'],
                'visitors'    => $visitor_count,
                'pct'         => ($step1_count > 0) ? round(($visitor_count / $step1_count) * 100, 1) : 0,
                'dropoff'     => max(0, $dropoff),
                'unreachable' => $unreachable,
            ];
        }

        if ($preflight) {
            wp_slimstat::$wpdb->query("DROP TEMPORARY TABLE IF EXISTS $temp_read");
            wp_slimstat::$wpdb->query("DROP TEMPORARY TABLE IF EXISTS $temp_write");
        }

        // Don't cache a funnel whose query errored — let it recompute next time
        // in case the failure was transient (deadlock) or the rule was fixed.
        if (!$had_error) {
            set_transient($cache_key, $results, 5 * MINUTE_IN_SECONDS);
        }

        // Memo for the rest of THIS request even on error (the transient is
        // skipped above, so the next request still recomputes and self-heals).
        $request_memo[$cache_key] = $results;
        return $results;
    }

    /**
     * Get raw funnel results as flat array (for Export CSV / Email Reports).
     *
     * @param array $_args Callback args from report definition.
     * @return array Flat rows with funnel_name, step_name, step_order, visitors, pct, dropoff.
     */
    public static function get_funnels_raw($_args = [])
    {
        // Gated on the tier, like show_funnels() — this one was not, so a site that had
        // been Pro and moved to free still built every stored funnel's temp-table chain
        // on the email-report cron, for funnels the tier says do not exist. Bounded for
        // the same reason the widget is: however many the option happens to hold. (D40)
        $max_funnels = (int) apply_filters('slimstat_max_funnels', 0);
        if ($max_funnels <= 0) {
            return [];
        }

        $funnels   = array_slice(get_option('slimstat_funnels', []), 0, $max_funnels);
        $results   = [];

        // Same D58 repair as get_goals_raw(): the caller's WHERE — the per-author
        // email loop's `author = %s` — was declared and discarded here too.
        $extra_where = empty($_args['where']) ? '' : (string) $_args['where'];

        foreach ($funnels as $funnel) {
            if (empty($funnel['name']) || empty($funnel['steps'])) {
                continue;
            }
            $step_results = self::get_funnel_results($funnel, $extra_where);
            foreach ($step_results as $i => $step) {
                $results[] = [
                    'funnel_name' => $funnel['name'],
                    'step_name'   => $step['name'],
                    'step_order'  => $i + 1,
                    'visitors'    => $step['visitors'],
                    'pct'         => $step['pct'] . '%',
                    'dropoff'     => $step['dropoff'],
                ];
            }
        }

        return $results;
    }
}
