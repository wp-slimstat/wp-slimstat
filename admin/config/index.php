<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included by a report/admin rendering method; these are local template variables, not plugin globals.

if (!defined('ABSPATH')) {
    exit;
}

// Determine what tab is currently being displayed
$current_tab = isset($_GET['tab']) && is_string($_GET['tab']) ? absint($_GET['tab']) : 1;

// Retrieve the last tracker message for display. Codes 300-399 and 429 are pageviews skipped
// on purpose (a bot, an excluded IP): SlimStat working as configured, so they render as a neutral
// note rather than a red failure, and as a sentence rather than a raw code.
$last_tracker_error = get_option('slimstat_tracker_error', []);
$tracker_message    = '';
if (!empty($last_tracker_error)) {
    $tracker_code    = (int) $last_tracker_error[0];
    $tracker_label   = \SlimStat\Tracker\Utils::getTrackerCodeLabel($tracker_code);
    $tracker_is_note = ($tracker_code >= 300 && $tracker_code < 400) || 429 === $tracker_code;
    $tracker_message = '<span class="slimstat-tracker-message' . ($tracker_is_note ? ' is-note' : '') . '">'
        . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_tracker_error[1], true)) . ': '
        /* translators: %d: internal tracker status code, shown only when no description exists for it */
        . esc_html('' !== $tracker_label ? $tracker_label : sprintf(__('Unknown tracker status %d.', 'wp-slimstat'), $tracker_code))
        . '</span>';
}

// Retrieve any geoip errors for display
$last_geoip_error = get_option('slimstat_geoip_error', []);

// Lazy-migrate: populate geolocation_provider from legacy flag for correct <select> rendering.
// Persists on next save (line 939 saves entire $settings array).
if (!isset(wp_slimstat::$settings['geolocation_provider'])) {
    $resolved = wp_slimstat::resolve_geolocation_provider();
    wp_slimstat::$settings['geolocation_provider'] = false !== $resolved ? $resolved : 'disable';
}
// Tabs are grouped by goal. Tab ids are stable (they are in bookmarks, deep links and the
// slimstat_options_on_page filter); only their order and labels change. Rows keep their option keys.
$banner_conditional = [
    'field' => 'gdpr_enabled,consent_integration',
    'type'  => 'checked,equals',
    'value' => '|||slimstat_banner',
];

// Define all the options
$settings = [
    1 => [
        'title' => __('General', 'wp-slimstat'),
        'rows'  => [
            // Not "Tracker": that was the old name of the Tracking tab (audit B4)
            'general_tracking_header' => [
                'title' => __('Tracking', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'is_tracking' => [
                'title'       => __('Enable tracking', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Turns visitor tracking on or off. Reports and collected data stay available while it is off.', 'wp-slimstat'),
            ],
            'general_integration_header' => [
                'title' => __('WordPress integration', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'add_dashboard_widgets' => [
                'title'       => __('Dashboard widgets', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Shows SlimStat reports on the WordPress Dashboard. Choose which ones in the Customizer.', 'wp-slimstat'),
            ],
            'use_separate_menu' => [
                'title'       => __('Menu in the admin bar', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('On: the SlimStat menu moves to the top admin bar, when it is visible. Off: it stays in the sidebar.', 'wp-slimstat'),
            ],
            'add_posts_column' => [
                'title'       => __('Stats column in posts and pages', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Adds a column to the Posts and Pages lists with the views of each item. The time range follows Reports & display, Time range.', 'wp-slimstat'),
            ],
            'posts_column_pageviews' => [
                'title'            => __('Column shows', 'wp-slimstat'),
                'type'             => 'toggle',
                'custom_label_on'  => __('Pageviews', 'wp-slimstat'),
                'custom_label_off' => __('IPs', 'wp-slimstat'),
                'description'      => __('Pageviews counts every view. IPs counts distinct IP addresses in the time range.', 'wp-slimstat'),
            ],
            'display_notifications' => [
                'title'       => __('SlimStat notifications', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Shows the notification bell inside SlimStat, with release notes, feature news and offers.', 'wp-slimstat'),
            ],
        ],
    ],

    9 => [
        'title' => __('Privacy & GDPR', 'wp-slimstat'),
        'rows'  => [
            'consent_management_header' => [
                'title' => __('Consent', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'gdpr_enabled' => [
                'title'       => __('Require visitor consent', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('When enabled, identifying analytics requires consent from the selected integration. Anonymous or cookieless settings can collect limited counts without identifiers. If the integration is missing, SlimStat collects limited counts only. Disabling this setting permits identifying tracking without a consent check. Review consent, retention and privacy disclosures for your site.', 'wp-slimstat'),
            ],
            'consent_integration' => [
                'title'         => __('Consent source', 'wp-slimstat'),
                'type'          => 'select',
                'description'   => __('Where SlimStat reads each visitor\'s consent. <strong>SlimStat consent banner:</strong> built in, no other plugin needed. <strong>WP Consent API:</strong> uses a consent plugin that supports the WP Consent API, such as Complianz or CookieYes.', 'wp-slimstat'),
                'select_values' => [
                    ''                => __('Select a consent integration', 'wp-slimstat'),
                    'slimstat_banner' => __('SlimStat consent banner (recommended)', 'wp-slimstat'),
                    'wp_consent_api'  => __('WP Consent API', 'wp-slimstat'),
                ],
                'conditional' => [
                    'field' => 'gdpr_enabled',
                    'type'  => 'checked',
                ],
            ],
            'slimstat_banner_header' => [
                'title'       => __('SlimStat consent banner', 'wp-slimstat'),
                'type'        => 'section_header',
                'conditional' => $banner_conditional,
            ],
            'opt_out_message' => [
                'title'             => __('Consent banner message', 'wp-slimstat'),
                'type'              => 'rich_text',
                'after_input_field' => '',
                'description'       => __('Content displayed inside the SlimStat consent banner. Basic HTML (p, a, strong, em) is allowed. Links must use full URLs (anchor-only links are stripped). Use the editor above to format your message.', 'wp-slimstat'),
                'conditional'       => $banner_conditional,
            ],
            'gdpr_accept_button_text' => [
                'title'              => __('Accept button label', 'wp-slimstat'),
                'type'               => 'text',
                'before_input_field' => '',
                'after_input_field'  => '',
                'description'        => __('Leave empty to use the default "Accept" text.', 'wp-slimstat'),
                'conditional'        => $banner_conditional,
            ],
            'gdpr_decline_button_text' => [
                'title'              => __('Decline button label', 'wp-slimstat'),
                'type'               => 'text',
                'before_input_field' => '',
                'after_input_field'  => '',
                'description'        => __('Leave empty to use the default "Decline" text.', 'wp-slimstat'),
                'conditional'        => $banner_conditional,
            ],
            'gdpr_theme_mode' => [
                'title'         => __('Banner colors', 'wp-slimstat'),
                'type'          => 'select',
                'description'   => __('Auto follows the light or dark preference of each visitor\'s device.', 'wp-slimstat'),
                'select_values' => [
                    'light' => __('Light', 'wp-slimstat'),
                    'dark'  => __('Dark', 'wp-slimstat'),
                    'auto'  => __('Auto', 'wp-slimstat'),
                ],
                'conditional' => $banner_conditional,
            ],

            'privacy_header' => [
                'title' => __('Visitor identifiers', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'do_not_track' => [
                'title'       => __('Respect DNT and Global Privacy Control', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Honor Do Not Track and Global Privacy Control browser signals. When either signal is enabled, no tracking occurs, including anonymous pageviews.', 'wp-slimstat'),
            ],
            'anonymize_ip' => [
                'title'       => __('Anonymize IP addresses', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Removes the last part of each IP address before it is stored: 192.168.1.23 becomes 192.168.1.0, and IPv6 addresses lose their last 80 bits. Country and network reports keep working. Off: full IP addresses are stored.', 'wp-slimstat'),
            ],
            'hash_ip' => [
                'title'       => __('Hash IP addresses', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Stores a one-way hash of the IP address, browser and a daily salt instead of the IP. Unique visitors can be counted without storing IP addresses or cookies. The hash changes every day and cannot be turned back into an IP. Takes priority over anonymization.', 'wp-slimstat'),
            ],
            'set_tracker_cookie' => [
                'title'       => __('Use cookies to recognize returning visitors', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('On: a first-party cookie recognizes returning visitors more accurately. Cookies can identify a person, so many regions require consent for them; they follow the consent settings above. Off: cookieless tracking, and returning visitors are estimated.', 'wp-slimstat'),
            ],
            'mask_personal_data' => [
                'title'       => __('Mask personal data in reports', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Hide visitor IPs, names, emails, fingerprints and notes from report viewers who cannot manage WordPress options, including their exports. Counts and report calculations stay unchanged. Scheduled reports also omit these identifiers.', 'wp-slimstat'),
            ],

            'maintenance_data_retention_header' => [
                'title' => __('Data retention', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'auto_purge' => [
                'title'             => __('Retention period', 'wp-slimstat'),
                'type'              => 'integer',
                'after_input_field' => __('days', 'wp-slimstat'),
                'description'       => __('Age in days at which live analytics is deleted or archived, according to Archive mode. Set 0 to disable live-data cleanup. Choose a retention period appropriate for your site. Archived records have a separate lifetime and still count as retained data.', 'wp-slimstat'),
            ],
            'auto_purge_delete' => [
                'title'       => __('Archive mode', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('On archives expired live pageviews and events. Off permanently deletes them. Archived records remain stored until their separate archive retention period expires.', 'wp-slimstat'),
            ],
            'archive_retention_days' => [
                'title'       => __('Archive retention (days)', 'wp-slimstat'),
                'type'        => 'text',
                'description' => __('Permanently delete archived pageviews and events older than this many days, measured from the original visit. Set 0 to keep archives indefinitely. Existing archives are preserved until you choose a period. Back up data you need before enabling expiration.', 'wp-slimstat'),
            ],
        ],
    ],

    2 => [
        'title' => __('Tracking', 'wp-slimstat'),
        'rows'  => [
            'tracking_method_header' => [
                'title' => __('How tracking works', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'track_admin_pages' => [
                'title'       => __('Track WordPress admin activity', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Records the wp-admin pages your logged-in users open. Off: only the public site is tracked.', 'wp-slimstat'),
            ],
            'javascript_mode' => [
                'title'            => __('Tracking mode', 'wp-slimstat'),
                'type'             => 'toggle',
                'custom_label_on'  => __('Client', 'wp-slimstat'),
                'custom_label_off' => __('Server', 'wp-slimstat'),
                'description'      => __('<strong>Client:</strong> the visitor\'s browser reports each pageview. Use it with caching plugins; visitors without JavaScript are not counted. <strong>Server:</strong> WordPress records every request it serves. Use it only when pages are not cached.', 'wp-slimstat'),
            ],
            'tracking_request_method' => [
                'title'         => __('Tracking request method', 'wp-slimstat'),
                'type'          => 'select',
                'description'   => __('How the browser sends tracking data. If the selected method fails, SlimStat tries the next one. Some ad blockers block REST API and Admin-AJAX requests; if visits go missing, try the ad-blocker bypass.', 'wp-slimstat'),
                'select_values' => [
                    'rest'           => __('REST API: fast, falls back to Admin-AJAX if the request fails', 'wp-slimstat'),
                    'ajax'           => __('Admin-AJAX: compatible, but ad blockers may block it too (recommended)', 'wp-slimstat'),
                    'adblock_bypass' => __('Ad-blocker bypass: most reliable, avoids ad blockers', 'wp-slimstat'),
                ],
            ],

            'visits_header' => [
                'title' => __('Visits', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'session_duration' => [
                'title'             => __('When should a visit end?', 'wp-slimstat'),
                'type'              => 'integer',
                'after_input_field' => __('seconds', 'wp-slimstat'),
                'description'       => __('How long one visit lasts. 1800 seconds (30 minutes) is the common standard. Lower values split the same activity into more visits.', 'wp-slimstat'),
            ],
            'extend_session' => [
                'title'       => __('Restart the timer on each page', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('On: every new pageview restarts the visit timer, so an active visitor stays in one visit. Off: a visit ends a fixed time after it starts.', 'wp-slimstat'),
            ],

            'filters_outbound_header' => [
                'title' => __('Links and downloads', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'track_same_domain_referers' => [
                'title'       => __('Same-domain referrers', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Records referrers from your own domain, which are skipped by default. Turn it on for a multisite network in subfolders, where each site counts as a separate website.', 'wp-slimstat'),
            ],
            'extensions_to_track' => [
                'title'       => __('Downloads', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __('File extensions to count as downloads. Links to matching files on other websites also count as downloads, not outbound links.', 'wp-slimstat'),
            ],

            // Anchor kept: reports, notices and Pro link to #wp-slimstat-third-party-libraries.
            'maintenance_third_party_header' => [
                'title'  => __('Visitor location and browsers', 'wp-slimstat'),
                'type'   => 'section_header',
                'anchor' => 'third-party-libraries',
            ],
            'geolocation_provider' => [
                'title'         => __('Geolocation provider', 'wp-slimstat'),
                'type'          => 'select',
                'select_values' => [
                    'disable'    => __('Disabled', 'wp-slimstat'),
                    'maxmind'    => __('MaxMind GeoLite2 (recommended)', 'wp-slimstat'),
                    'dbip'       => __('DB-IP City Lite (free)', 'wp-slimstat'),
                    'cloudflare' => __('Cloudflare Header', 'wp-slimstat'),
                ],
                'description' => __('<strong>Choose how SlimStat resolves visitor locations:</strong><br /><strong>DB-IP City Lite</strong>: free, no license required. SlimStat downloads a local database and updates it automatically in the background after you save settings. You can also run the update manually using the button below. Works for arbitrary IPs in reports.<br /><strong>MaxMind GeoLite2</strong>: requires a free MaxMind license key. City vs Country precision affects database size and download time. Updates run in the background after saving; you can also update manually. If PHP Phar is disabled on your server, please upload the .mmdb file manually to wp-content/uploads/wp-slimstat/.<br /><strong>Cloudflare Header</strong>: no database needed. SlimStat reads the HTTP_CF_IPCOUNTRY header set by Cloudflare for the current request only. It won\'t resolve arbitrary test IPs (like 8.8.8.8). Make sure "IP Geolocation" is enabled in your Cloudflare dashboard and your site is actually proxied through Cloudflare.', 'wp-slimstat'),
            ],
            'maxmind_license_key' => [
                'title'       => __('MaxMind license key', 'wp-slimstat'),
                'type'        => 'text',
                'description' => __('Enter your MaxMind license key to enable automatic downloads of the GeoLite2 database. The license key should be 16-40 characters containing only letters, numbers, and underscores. Required only if you select MaxMind as the provider. <strong>Important:</strong> If the PHP Phar extension is not available on your server, automatic extraction will fail, so upload the .mmdb file manually to wp-content/uploads/wp-slimstat/.', 'wp-slimstat'),
            ],
            'geolocation_db_actions' => [
                'title'             => __('Geolocation database', 'wp-slimstat'),
                'after_input_field' => '<input type="hidden" id="slimstat-geoip-nonce" value="' . esc_attr(wp_create_nonce('slimstat_geoip_action')) . '" /><a href="#" id="slimstat-update-geoip-database" class="button-secondary noslimstat" style="vertical-align: middle" data-error-message="' . esc_attr__('An error occurred while updating the geolocation database.', 'wp-slimstat') . '">' . esc_html__('Update Database', 'wp-slimstat') . '</a> <a href="#" id="slimstat-check-geoip-database" class="button-secondary noslimstat" style="vertical-align: middle" data-error-message="' . esc_attr__('An error occurred while updating the geolocation database.', 'wp-slimstat') . '">' . esc_html__('Check Database', 'wp-slimstat') . '</a>',
                'type'              => 'plain-text',
                'description'       => __('Download or refresh the selected geolocation database. <strong>DB-IP/MaxMind only</strong>: "Update Database" runs it now; after saving settings, SlimStat also schedules a background update. "Check Database" verifies that the file exists and is readable. <strong>Cloudflare</strong>: no database is required. The header is used at request time.', 'wp-slimstat'),
            ],
            'geolocation_country' => [
                'title'            => __('Geolocation precision', 'wp-slimstat'),
                'type'             => 'toggle',
                'custom_label_on'  => __('Country', 'wp-slimstat'),
                'custom_label_off' => __('City', 'wp-slimstat'),
                'description'      => __('Choose between Country and City precision. For DB‑IP and MaxMind, City uses a larger database. For Cloudflare, city-level data requires the <strong>Add visitor location headers</strong> Managed Transform enabled in your Cloudflare dashboard (Rules &gt; Transform Rules &gt; Managed Transforms). Without it, only country is available.', 'wp-slimstat'),
            ],
            'enable_browscap' => [
                'title'       => __('Browscap library', 'wp-slimstat'),
                'type'        => 'toggle',
                /* translators: %s: installed Browscap data version, wrapped in strong tags. */
                'description' => __("We are contributing to the <a href='https://browscap.org/' target='_blank'>Browscap Capabilities Project</a>, which we use to decode your visitors' user agent string into browser name and operating system. We use an <a href='https://github.com/slimstat/browscap-cache' target='_blank'>optimized version of their data structure</a>, for improved performance. When enabled, SlimStat uses this library in addition to the built-in heuristic function, to determine your visitors' browser information. Updates are downloaded automatically every week, when available.", 'wp-slimstat') . (empty(\SlimStat\Services\Browscap::$browscap_local_version) ? '' : ' ' . sprintf(__('You are currently using version %s.', 'wp-slimstat'), '<strong>' . \SlimStat\Services\Browscap::$browscap_local_version . '</strong>')),
            ],
        ],
    ],

    3 => [
        'title' => __('Reports & display', 'wp-slimstat'),
        'rows'  => [
            'reports_functionality_header' => [
                'title' => __('Report defaults', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'use_current_month_timespan' => [
                'title'       => __('Current month', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Determine what time window to use for the reports. Enable this option to default to the current month, disable it to use the past X number of days (see option below). Use the date and time filters for a more granular analysis.', 'wp-slimstat'),
            ],
            'posts_column_day_interval' => [
                'title'             => __('Time range', 'wp-slimstat'),
                'type'              => 'integer',
                'after_input_field' => __('days', 'wp-slimstat'),
                'description'       => __('Default number of days in the time window used to generate all the reports. We set it to 4 weeks so that the comparison charts will overlap nicely (i.e. Monday over Monday) for a more meaningful analysis. This value is ignored if the option above is turned on.', 'wp-slimstat'),
            ],
            'rows_to_show' => [
                'title'             => __('Rows to display', 'wp-slimstat'),
                'type'              => 'integer',
                'after_input_field' => __('rows', 'wp-slimstat'),
                'description'       => __('Define the number of rows to display in Top and Recent reports. You can adjust this number to improve your server performance.', 'wp-slimstat'),
            ],
            'ip_lookup_service' => [
                'title'       => __('IP geolocation', 'wp-slimstat'),
                'type'        => 'text',
                'description' => __('Customize the URL of the geolocation service to be used in the Access Log. Default value: <code>https://ip-api.com/#</code>', 'wp-slimstat'),
            ],
            'comparison_chart' => [
                'title'       => __('Comparison chart', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('SlimStat displays two sets of charts, allowing you to compare the current time window with the previous one. Disable this option if you find those four charts confusing, and prefer seeing only the selected time range. Please keep in mind that you can always temporarily hide one series by clicking on the corresponding entry in the legend.', 'wp-slimstat'),
            ],
            'show_display_name' => [
                'title'       => __('Use display name', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('By default, users are listed by their usernames. Enable this option to show their display names instead.', 'wp-slimstat'),
            ],
            'convert_resource_urls_to_titles' => [
                'title'       => __('Display titles', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('For improved legibility, most reports list post and page titles instead of their permalinks. Use this option to change this behavior.', 'wp-slimstat'),
            ],
            'convert_ip_addresses' => [
                'title'       => __('Show hostnames', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Enable this option to display the hostname associated to each IP address. Please note that this might affect performance, as SlimStat will need to query your DNS server for each address.', 'wp-slimstat'),
            ],

            'reports_right_now_header' => [
                'title' => __('Access log and world map', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'refresh_interval' => [
                'title'             => __('Auto refresh', 'wp-slimstat'),
                'type'              => 'integer',
                'after_input_field' => __('seconds', 'wp-slimstat'),
                'description'       => __('When a value greater than zero is entered, the Access Log view will refresh every X seconds. Enter <strong>0</strong> (the number zero) if you would like to deactivate this feature.', 'wp-slimstat'),
            ],
            'number_results_raw_data' => [
                'title'             => __('Rows to display', 'wp-slimstat'),
                'type'              => 'integer',
                'description'       => __('Define the number of rows to visualize in the Access Log.', 'wp-slimstat'),
                'after_input_field' => __('rows', 'wp-slimstat'),
            ],
            'max_dots_on_map' => [
                'title'             => __('Map data points', 'wp-slimstat'),
                'type'              => 'integer',
                'description'       => __('Customize the maximum number of data points displayed on the world map. Please note that larger numbers might negatively affect rendering times.', 'wp-slimstat'),
                'after_input_field' => __('points', 'wp-slimstat'),
            ],

            'reports_miscellaneous_header' => [
                'title' => __('Appearance', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'custom_css' => [
                'title'           => __('Custom CSS', 'wp-slimstat'),
                'type'            => 'textarea',
                'use_tag_list'    => false,
                'use_code_editor' => 'css',
                'description'     => __("Enter your own stylesheet definitions to customize the way your reports look. <a href='https://wp-slimstat.com/faq/how-can-i-change-the-colors-associated-to-color-coded-pageviews-known-user-known-visitors-search-engines-etc/' target='_blank'>Check our FAQs</a> for more information on how to use this option.", 'wp-slimstat'),
            ],
            'chart_colors' => [
                'title'       => __('Chart colors', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __('Customize the look and feel of your charts by assigning your own colors to each metric. List four hex colors, in the following order: metric 1 previous, metric 2 previous, metric 1 current, metric 2 current. For example: <code>#ccc, #999, #bbcc44, #21759b</code>.', 'wp-slimstat'),
            ],
            'show_complete_user_agent_tooltip' => [
                'title'       => __('Show user agent', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Enable this option if you want to see the full user agent string when hovering over each browser icon in the Access Log and elsewhere.', 'wp-slimstat'),
            ],
            'enable_sov' => [
                'title'       => __('Reverse the filter bar order', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Show the filter bar as value, condition, dimension, for subject-object-verb languages such as Japanese.', 'wp-slimstat'),
            ],
        ],
    ],

    4 => [
        'title' => __('Exclusions', 'wp-slimstat'),
        'rows'  => [
            'filters_users_header' => [
                'title' => __('Visitors', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'ignore_wp_users' => [
                'title'       => __('WP users', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('If enabled, logged in WordPress users will not be tracked, neither on the website nor in the backend.', 'wp-slimstat'),
            ],
            'ignore_spammers' => [
                'title'       => __('Spammers', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('If enabled, visits from users identified as spammers by third-party tools like Akismet will not be tracked. Pageviews generated by users whose comments are later marked as spam, will also be removed from the database on a daily basis.', 'wp-slimstat'),
            ],
            'ignore_bots' => [
                'title'       => __('Bots', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('If enabled, pageviews generated by crawlers, spiders, search engine bots, and other automated tools will not be tracked. Please note that if the tracker is set to work in Client mode, some of those pageviews might not be tracked anyway, since these tools usually do not run any embedded JavaScript code.', 'wp-slimstat'),
            ],
            'ignore_prefetch' => [
                'title'       => __('Prefetch requests', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('<a href="https://en.wikipedia.org/wiki/Link_prefetching" target="_blank">Link Prefetching</a> is a technique that allows web browsers to pre-load resources, before the user clicks on the corresponding link. If enabled, this kind of requests will not be tracked.', 'wp-slimstat'),
            ],
            'ignore_users' => [
                'title'       => __('Usernames', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __('Enter a list of usernames that should not be tracked. Please note that spaces are <em>not</em> ignored and that usernames are case sensitive. See note at the bottom of this page for more information on how to use wildcards.', 'wp-slimstat'),
            ],
            'ignore_capabilities' => [
                'title'       => __('Capabilities', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __('Enter a list of <a href="https://wordpress.org/support/article/roles-and-capabilities/" target="_new">WordPress capabilities</a>, so that users who have any of them assigned to their role will not be tracked. Please note that although capabilities are case-insensitive, it is recommended to enter them all in lowercase. See note at the bottom of this page for more information on how to use wildcards.', 'wp-slimstat'),
            ],
            'ignore_ip' => [
                'title'       => __('IP addresses', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __("Enter a list of IP addresses that should not be tracked. Each subnet <strong>must</strong> be defined using the <a href='https://www.iplocation.net/subnet-mask' target='_blank'>CIDR notation</a> (e.g. <em>192.168.0.0/24</em>). This filter applies both to the public IP address and the originating IP address, if available. Using the CIDR notation, you will use octets to determine the mask. For example, 54.0.0.0/8 matches any address that has 54 as the first number; 54.12.0.0/16 matches any address that starts with 54.12, and so on.", 'wp-slimstat'),
            ],
            'ignore_countries' => [
                'title'       => __('Countries', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __('Enter a list of lowercase <a href="https://en.wikipedia.org/wiki/ISO_3166-1_alpha-2" target="_blank">ISO 3166-1 country codes</a> (e.g. <code>us, it, es</code>) that should not be tracked. Please note: this field does not allow wildcards.', 'wp-slimstat'),
            ],
            'ignore_languages' => [
                'title'       => __('Languages', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __('Enter a list of lowercase <a href="http://www.lingoes.net/en/translator/langcode.htm" target="_blank">ISO 639-1 language codes</a> (e.g. <code>en-us, fr-ca, zh-cn</code>) that should not be tracked. Please note: this field does not allow wildcards.', 'wp-slimstat'),
            ],
            'ignore_browsers' => [
                'title'       => __('User agents', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __("Enter a list of browser names that should not be tracked. You can specify the browser's version adding a slash after the name (e.g. <em>Firefox/36</em>). Technically speaking, SlimStat will match your list against the visitor's user agent string. Strings are case-insensitive. See note at the bottom of this page for more information on how to use wildcards.", 'wp-slimstat'),
            ],
            'ignore_platforms' => [
                'title'       => __('Operating systems', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __('Enter a list of operating system codes that should not be tracked. Please refer to <a href="https://wp-slimstat.com/knowledge-base/" target="_blank">this page</a> in our knowledge base to learn more about which codes can be used. See note at the bottom of this page for more information on how to use wildcards.', 'wp-slimstat'),
            ],

            'filters_pageview_header' => [
                'title' => __('Pages and links', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'ignore_resources' => [
                'title'       => __('Permalinks', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __('Enter a list of permalinks that should not be tracked. Do not include your website domain name: <code>/about, ?p=1</code>, etc. See note at the bottom of this page for more information on how to use wildcards. Strings are case-insensitive.', 'wp-slimstat'),
            ],
            'ignore_resource_parameters' => [
                'title'       => __('Ignore resource query parameters', 'wp-slimstat'),
                'type'        => 'text',
                'description' => __('Optional comma-separated query parameter names, such as fbclid or gclid, to omit from newly tracked page URLs. Names are case-sensitive. Leave empty to keep all parameters. Campaign attribution is extracted first; historical URLs are unchanged. Do not include parameters that identify different pages or products.', 'wp-slimstat'),
            ],
            'do_not_track_outbound_classes_rel_href' => [
                'title'       => __('Link attributes: class names, rel and href', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __('Do not track events on page elements whose class names, <em>rel</em> attributes or <em>href</em> attribute contain one of the following strings. Please keep in mind that the class <code>noslimstat</code> is used to avoid tracking interactive links throughout the reports. If you remove it from this list, some features might not work as expected.', 'wp-slimstat'),
            ],
            'ignore_referers' => [
                'title'       => __('Referring sites', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __('Enter a list of referring URLs that should not be tracked: <code>https://mysite.com*</code>, <code>*/ignore-me-please</code>, etc. See note at the bottom of this page for more information on how to use wildcards. Strings are case-insensitive and must include the protocol (http:// or https://).', 'wp-slimstat'),
            ],
            'ignore_content_types' => [
                'title'       => __('Content types', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __('Enter a list of SlimStat content types that should not be tracked: <code>post, page, cpt:attachment, tag, 404, taxonomy, author, archive, search, feed, login</code>, etc. For custom post types, use the <code>cpt:</code> prefix (e.g., <code>cpt:product</code>, <code>cpt:portfolio</code>, <code>cpt:attachment</code>). See note at the bottom of this page for more information on how to use wildcards. Strings are case-insensitive.', 'wp-slimstat'),
            ],
            'wildcards_description' => [
                'type'   => 'custom',
                'title'  => '<p class="description">' . __('<strong>Wildcards</strong><br>You can use the character <code>*</code> to match <em>any string, including the empty string</em>, and the character <code>!</code> to match <em>any character, including no character</em>. For example, <code>user*</code> matches user12 and userfoo, <code>u*100</code> matches user100 and ur100, <code>user!0</code> matches user10, user0 and user90, but not user100.', 'wp-slimstat') . '</p>',
                'markup' => '',
            ],
        ],
    ],

    5 => [
        'title' => __('Access & security', 'wp-slimstat'),
        'rows'  => [
            'permissions_reports_header' => [
                'title' => __('Who can see reports', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'restrict_authors_view' => [
                'title'       => __('Restrict authors', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Enable this option if you want your authors to only see SlimStat reports about their own content.', 'wp-slimstat'),
            ],
            'capability_can_view' => [
                'title'         => __('Minimum capability', 'wp-slimstat'),
                'type'          => isset($GLOBALS['wp_roles']->role_objects['administrator']->capabilities) ? 'select' : 'text',
                'select_values' => array_combine(array_keys($GLOBALS['wp_roles']->role_objects['administrator']->capabilities), array_keys($GLOBALS['wp_roles']->role_objects['administrator']->capabilities)),
                'description'   => __("Specify the minimum <a href='https://wordpress.org/support/article/roles-and-capabilities/' target='_new'>capability</a> your WordPress users must have to access the reports (default: <code>manage_options</code>). The field below can be used to override this option for specific users.", 'wp-slimstat'),
            ],
            'can_view' => [
                'title'       => __('Usernames', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __("Enter a list of usernames who should have access to SlimStat. Administrators are implicitly allowed, so you don't need to list them below. Usernames are case sensitive. Wildcards are not allowed.", 'wp-slimstat'),
            ],

            'permissions_customize_header' => [
                'title' => __('Who can customize reports', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'capability_can_customize' => [
                'title'         => __('Minimum capability', 'wp-slimstat'),
                'type'          => isset($GLOBALS['wp_roles']->role_objects['administrator']->capabilities) ? 'select' : 'text',
                'select_values' => array_combine(array_keys($GLOBALS['wp_roles']->role_objects['administrator']->capabilities), array_keys($GLOBALS['wp_roles']->role_objects['administrator']->capabilities)),
                'description'   => __("Specify the minimum <a href='https://wordpress.org/support/article/roles-and-capabilities/' target='_new'>capability</a> your WordPress users must have to access the Customizer (default: <code>manage_options</code>). The field below can be used to override this option for specific users.", 'wp-slimstat'),
            ],
            'can_customize' => [
                'title'       => __('Usernames', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __("Enter a list of usernames who should have access to the customizer. Administrators are implicitly allowed, so you don't need to list them below. Usernames are case sensitive. Wildcards are not allowed.", 'wp-slimstat'),
            ],

            'permissions_config_header' => [
                'title' => __('Who can change settings', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'capability_can_admin' => [
                'title'         => __('Minimum capability', 'wp-slimstat'),
                'type'          => isset($GLOBALS['wp_roles']->role_objects['administrator']->capabilities) ? 'select' : 'text',
                'select_values' => array_combine(array_keys($GLOBALS['wp_roles']->role_objects['administrator']->capabilities), array_keys($GLOBALS['wp_roles']->role_objects['administrator']->capabilities)),
                'description'   => __("Specify the minimum <a href='https://wordpress.org/support/article/roles-and-capabilities/' target='_new'>capability</a> your WordPress users must have to configure SlimStat (default: <code>manage_options</code>). The field below can be used to override this option for specific users.", 'wp-slimstat'),
            ],
            'can_admin' => [
                'title'       => __('Usernames', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __('Enter a list of usernames who should have access to the plugin settings. Please be advised that administrators <strong>are not</strong> implicitly allowed, so do not forget to include yourself! Usernames are case sensitive. Wildcards are not allowed.', 'wp-slimstat'),
            ],

            'rest_api_header' => [
                'title' => __('REST API', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'rest_api_tokens' => [
                'title'       => __('Tokens', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __('Configure a strong random token to enable report API access. Send it in the Authorization: Bearer TOKEN header over HTTPS. Treat tokens as passwords: they grant access to visitor reports. Leaving this field empty disables token access. Query-string tokens remain supported for existing integrations, but can be exposed in server logs and should be replaced with headers.', 'wp-slimstat'),
            ],
        ],
    ],

    6 => [
        'title' => __('Data & maintenance', 'wp-slimstat'),
        'rows'  => [
            'maintenance_database_header' => [
                'title' => __('Database', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'auto_purge_optimize' => [
                'title'       => __('Automatic table optimization', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Disabled by default. Deleted rows leave space that the database can reuse. Enable only with your hosting provider\'s agreement: rebuilding live tables may reclaim disk space but can delay tracking while locks are held. Runs at most once every 30 days after removing pageviews; archive tables are never optimized.', 'wp-slimstat'),
            ],
            'db_indexes' => [
                'title'       => __('Increase performance', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Enable this option to add column indexes to the main SlimStat table. This will make SQL queries faster and increase the size of the table by about 30%.', 'wp-slimstat'),
            ],

            'maintenance_troubleshooting_header' => [
                'title' => __('Troubleshooting', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'last_tracker_error' => [
                'title'             => __('Last tracker message', 'wp-slimstat'),
                'type'              => 'plain-text',
                'after_input_field' => '' === $tracker_message ? __('No errors.', 'wp-slimstat') : $tracker_message . '<a class="slimstat-font-cancel" title="' . esc_attr__('Clear this message', 'wp-slimstat') . '" href="' . wp_slimstat_admin::$config_url . $current_tab . '&amp;action=reset-tracker-error&amp;slimstat_update_settings=' . wp_create_nonce('slimstat_update_settings') . '"></a>',
                'description'       => __('The last thing the tracker reported. A pageview skipped on purpose, such as a bot or an excluded IP, is shown in grey. A red message means a pageview could not be recorded.', 'wp-slimstat'),
            ],
            'last_geoip_error' => [
                'title'             => __('Geolocation database errors', 'wp-slimstat'),
                'type'              => 'plain-text',
                'after_input_field' => empty($last_geoip_error) ? __('No errors.', 'wp-slimstat') : '<strong>[' . esc_html(date_i18n(get_option('date_format'), $last_geoip_error['time'], true)) . ' ' . esc_html(date_i18n(get_option('time_format'), $last_geoip_error['time'], true)) . '] ' . esc_html($last_geoip_error['error']) . '</strong><a class="slimstat-font-cancel" title="' . esc_attr__('Reset this error', 'wp-slimstat') . '" href="' . wp_slimstat_admin::$config_url . $current_tab . '&amp;action=reset-geoip-error&amp;slimstat_update_settings=' . wp_create_nonce('slimstat_update_settings') . '"></a>',
                'description'       => __("The last error from the geolocation database, if any: it could not update, or could not look up a visitor's location.", 'wp-slimstat'),
            ],
            'last_geoip_dl' => [
                'title'             => __('Geolocation database updated', 'wp-slimstat'),
                'type'              => 'plain-text',
                // #77: surface the last successful GeoIP download. The option stores
                // a unix timestamp (time()); guard the 0/never-downloaded default so
                // it shows "Never" rather than the epoch (Jan 1 1970).
                'after_input_field' => ($geoip_dl_ts = (int) get_option('slimstat_last_geoip_dl', 0)) > 0
                    ? '<strong>' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $geoip_dl_ts, true) . '</strong>'
                    : __('Never', 'wp-slimstat'),
                'description'       => __('When the geolocation database was last downloaded or refreshed.', 'wp-slimstat'),
            ],
            'show_sql_debug' => [
                'title'       => __('SQL debug', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Enable this option to display the SQL code associated to each report. This can be useful to troubleshoot issues with data consistency or missing pageviews.', 'wp-slimstat'),
            ],
            'slimstat_debug' => [
                'title'       => __('Tracker debug mode', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Enable detailed tracker diagnostics. When active, tracking responses include debug headers (X-SlimStat-Transport, X-SlimStat-Outcome, X-SlimStat-Error-Code) and the browser records transport attempts in window.__slimstatDebug. Disable after troubleshooting.', 'wp-slimstat'),
            ],

            'maintenance_danger_zone_header' => [
                'title' => __('Danger zone', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'delete_all_records' => [
                'title'             => __('Delete all records', 'wp-slimstat'),
                'type'              => 'plain-text',
                'after_input_field' => '<a class="button-link button-link-delete" href="' . wp_slimstat_admin::$config_url . $current_tab . '&amp;action=truncate-table&amp;slimstat_update_settings=' . wp_create_nonce('slimstat_update_settings') . '" onclick="return( confirm( \'' . esc_js(__('Delete all records from your database? This cannot be undone.', 'wp-slimstat')) . '\' ) )">' . esc_html__('Delete Records', 'wp-slimstat') . '</a>',
                'description'       => __('Permanently delete active pageview and event records, including linked full Heatmaps records through database relationships. Archives, Ecommerce order summaries, and settings remain. Archive tables may contain older records, but are not a backup of the records being deleted. Restore a database backup to recover deleted records.', 'wp-slimstat'),
            ],
            'reset_all_settings' => [
                'title'             => __('Reset all settings', 'wp-slimstat'),
                'type'              => 'plain-text',
                'after_input_field' => '<a class="button-link button-link-delete" href="' . wp_slimstat_admin::$config_url . $current_tab . '&amp;action=reset-settings&amp;slimstat_update_settings=' . wp_create_nonce('slimstat_update_settings') . '" onclick="return( confirm( \'' . esc_js(__('Reset all settings to their defaults?', 'wp-slimstat')) . '\' ) )">' . esc_html__('Factory Reset', 'wp-slimstat') . '</a>',
                'description'       => __('Restore all the settings to their default value. This <strong>does not</strong> delete any records collected by the plugin.', 'wp-slimstat'),
            ],
            'delete_data_on_uninstall' => [
                'title'       => __('Delete data on uninstall', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('<strong>Off (default):</strong> deleting the plugin keeps analytics tables and report settings. Scheduled jobs and cached files are removed. Custom database credentials are cleared and must be entered again after reinstalling. <strong>On:</strong> deleting the plugin <strong>permanently</strong> erases every SlimStat table, all collected visits and all plugin settings. This cannot be undone. Turn it on only if you really want the data gone.', 'wp-slimstat'),
            ],
        ],
    ],

    10 => [
        'title' => __('Advanced', 'wp-slimstat'),
        'rows'  => [
            'performance_header' => [
                'title' => __('Performance', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'async_load' => [
                'title'       => __('Async mode', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Loads each report in its own request instead of one large page. Turn it on if reports load slowly or run out of memory.', 'wp-slimstat'),
            ],
            'limit_results' => [
                'title'             => __('Rows per summary query', 'wp-slimstat'),
                'type'              => 'integer',
                'after_input_field' => __('rows', 'wp-slimstat'),
                'description'       => __('The most rows one database query reads when it calculates totals, averages and other summaries. Lower it only if reports fail with a PHP memory error. On busy sites, a lower value can make those numbers less complete.', 'wp-slimstat'),
            ],
            'ajax_relative_path' => [
                'title'       => __('Relative Ajax', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Try enabling this option if you are experiencing issues related to the header field X-Requested-With not being allowed by Access-Control-Allow-Headers in preflight response (or similar).', 'wp-slimstat'),
            ],

            'advanced_external_pages_header' => [
                'title' => __('External pages', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'external_domains' => [
                'title'       => __('Allowed domains', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __("If you are getting an error saying that no 'Access-Control-Allow-Origin' header is present on the requested resource, when using the external tracking code above, list the domains (complete with scheme) you would like to allow. For example: <code>https://my.domain.ext</code> (no trailing slash). Please see <a href='https://www.w3.org/TR/cors/#security' target='_blank'>this W3 resource</a> for more information on the security implications of allowing CORS requests.", 'wp-slimstat'),
            ],
            'external_pages_script' => [
                'type'   => 'custom',
                'title'  => __('Add the following code to all the non-WordPress pages you would like to track, right before the closing BODY tag. Please make sure to change the protocol of all the URLs to HTTPS, if your external site is using a secure channel.', 'wp-slimstat'),
                'markup' => str_replace('\\n', "\n", '<pre style="max-width:100%">&lt;script type="text/javascript"&gt;\n/* &lt;![CDATA[ */\nvar SlimStatParams = {\n  transport: "ajax",\n  ajaxurl: "' . ((('on' == (wp_slimstat::$settings['ajax_relative_path'] ?? '')) ? admin_url('admin-ajax.php', 'relative') : admin_url('admin-ajax.php'))) . '",\n  ajaxurl_ajax: "' . ((('on' == (wp_slimstat::$settings['ajax_relative_path'] ?? '')) ? admin_url('admin-ajax.php', 'relative') : admin_url('admin-ajax.php'))) . '"\n};\n/* ]]&gt; */\n&lt;/script&gt;\n&lt;script type="text/javascript" src="' . esc_url(plugins_url('/wp-slimstat.min.js', dirname(__DIR__))) . '"&gt;&lt;/script&gt;</pre>'),
            ],

            'third_party_services_header' => [
                'title' => __('Third-party services', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'mozcom_access_id' => [
                'title'       => __('Mozscape access ID', 'wp-slimstat'),
                'type'        => 'text',
                'description' => __('Get accurate rankings for your website through the <a href="https://moz.com/community/join?redirect=/products/api/keys" target="_blank">Mozscape API</a>. Sign up for a free community account to get started. Then enter your personal identification code in this field.', 'wp-slimstat'),
            ],
            'mozcom_secret_key' => [
                'title'       => __('Mozscape secret key', 'wp-slimstat'),
                'type'        => 'text',
                'description' => __('This key is needed to query the Mozscape API (see option above). Treat it like a password and do not share it with anyone, or they will be able to make API requests using your account.', 'wp-slimstat'),
            ],
        ],
    ],

    7 => [
        'title' => __('Pro options', 'wp-slimstat'),
    ],

    8 => [
        'title' => __('License', 'wp-slimstat'),
    ],
];


// Allow third-party tools to add their own settings
$settings = apply_filters('slimstat_options_on_page', $settings);

// Save options
$save_messages = [];
if (!empty($settings) && isset($_REQUEST['slimstat_update_settings']) && is_string($_REQUEST['slimstat_update_settings']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['slimstat_update_settings'])), 'slimstat_update_settings')) {
    // Authorize before destructive GET actions as well as ordinary settings saves.
    if (!current_user_can(is_network_admin() ? 'manage_network_options' : 'manage_options')) {
        wp_die(esc_html__('Insufficient permissions.', 'wp-slimstat'));
    }
    $posted_options = [];
    if (isset($_POST['options'])) {
        if (!is_array($_POST['options'])) {
            wp_die(esc_html__('Invalid settings data.', 'wp-slimstat'));
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce/capability checked above; settings are shape-checked and sanitized per control type below.
        $posted_options = wp_unslash($_POST['options']);
        foreach ($posted_options as $slug => $value) {
            // Unknown/custom payloads belong to extension hooks. Built-in controls
            // and the special handlers below accept scalar HTML form values only.
            $type = $settings[$current_tab]['rows'][$slug]['type'] ?? '';
            if (!is_string($value) && (in_array($type, ['toggle', 'select', 'text', 'integer', 'textarea', 'rich_text'], true)
                || in_array($slug, ['db_indexes', 'enable_browscap', 'geolocation_country', 'geolocation_provider', 'maxmind_license_key', 'tracking_request_method'], true))) {
                wp_die(esc_html__('Invalid settings data.', 'wp-slimstat'));
            }
        }
    }
    if (!empty($_GET['action']) && is_string($_GET['action'])) {
        switch (sanitize_key(wp_unslash($_GET['action']))) {
            case 'reset-tracker-error':
                $settings[6]['rows']['last_tracker_error']['after_input_field'] = __('No errors.', 'wp-slimstat');
                \SlimStat\Tracker\Utils::clearDiagnostic('slimstat_tracker_error');
                break;

            case 'reset-geoip-error':
                $settings[6]['rows']['last_geoip_error']['after_input_field'] = __('No errors.', 'wp-slimstat');
                \SlimStat\Tracker\Utils::clearDiagnostic('slimstat_geoip_error');
                break;

            case 'privacy-reviewed':
                // Marks the review task complete. No privacy setting changes here.
                wp_slimstat::$settings['privacy_review_notice'] = 'no';
                wp_slimstat::update_option('slimstat_options', wp_slimstat::$settings);
                wp_slimstat_admin::show_message(__('Privacy review marked as complete. No settings were changed.', 'wp-slimstat'), 'info');
                break;

            case 'reset-settings':
                wp_slimstat::update_option('slimstat_options', wp_slimstat::get_fresh_defaults());
                wp_slimstat_admin::show_message(__('All settings were successfully reset to their default values.', 'wp-slimstat'));
                break;

            case 'truncate-table':
                $slimstat_deleted = true;
                foreach (['DELETE te FROM %sslim_events te', 'OPTIMIZE TABLE %sslim_events', 'DELETE t1 FROM %sslim_stats t1', 'OPTIMIZE TABLE %sslim_stats'] as $slimstat_statement) {
                    // phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Trusted core table prefix and schema-manifest identifiers; dynamic values are bound on the analytics connection.
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Nonce/capability-protected maintenance uses core-prefix and manifest identifiers; schema probes and mutations require fresh state.
                    $slimstat_result = wp_slimstat::$wpdb->query(sprintf($slimstat_statement, $GLOBALS['wpdb']->prefix));
                    if ($slimstat_result > 0 && 0 === strpos($slimstat_statement, 'DELETE ')) { wp_slimstat::bump_data_version(); }
                    if (false === $slimstat_result) {
                        $slimstat_deleted = false;
                    // phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared
                        break;
                    }
                }
                wp_slimstat_admin::show_message(
                    $slimstat_deleted
                        ? __('All your records were successfully deleted.', 'wp-slimstat')
                        : __('Database maintenance failed. Some records may already have been deleted. Check the database connection and retry.', 'wp-slimstat'),
                    $slimstat_deleted ? 'updated' : 'error'
                );
                break;

            default:
                break;
        }
    }

    // Some of them require extra processing
    if (!empty($posted_options)) {

        if (!check_admin_referer('slimstat_save_settings')) {
            wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'wp-slimstat'));
        }
        // DB Indexes
        if (!empty($posted_options['db_indexes'])) {
            // Both arms iterate the SAME manifest group, so the toggle cannot add one set and
            // remove another — which is what a hand-maintained pair of lists eventually does.
            // The group is declared in Schema::OPTIONAL_INDEXES, which Schema::ensure() also
            // consults, so reconciliation cannot silently rebuild what this just dropped.
            //
            // Table-qualified, and the ADD goes through Schema::createIndexSql(). Hardcoding
            // `slim_stats` here would ALTER the wrong table the day an optional index is
            // declared on slim_events, and hand-building the DDL would leave a second index
            // emitter alive in the one seam that exists to remove them.
            $slimstat_prefix       = $GLOBALS['wpdb']->prefix;
            $slimstat_toggle_group = \SlimStat\Schema\Schema::optionalGroup('db_indexes');

            if (in_array($posted_options['db_indexes'], ['on', 'no'], true) && $posted_options['db_indexes'] !== wp_slimstat::$settings['db_indexes']) {
                $slimstat_indexes_changed = true;
                foreach ($slimstat_toggle_group as [$slimstat_suffix, $slimstat_index]) {
                    $slimstat_state = \SlimStat\Schema\Schema::indexState(wp_slimstat::$wpdb, $slimstat_suffix, $slimstat_prefix);
                    $slimstat_resolved = \SlimStat\Schema\Schema::resolve($slimstat_index, $slimstat_prefix);
                    $slimstat_present = in_array($slimstat_resolved, $slimstat_state['present'], true);
                    $slimstat_missing = in_array($slimstat_index, $slimstat_state['missing'], true);
                    $slimstat_malformed = in_array($slimstat_index, $slimstat_state['malformed'], true);
                    if (!$slimstat_present && !$slimstat_missing && !$slimstat_malformed) {
                        $slimstat_indexes_changed = false;
                        break;
                    }
                    if ('on' === $posted_options['db_indexes']) {
                        // phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Trusted core table prefix and schema-manifest identifiers; dynamic values are bound on the analytics connection.
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Nonce/capability-protected maintenance uses core-prefix and manifest identifiers; schema probes and mutations require fresh state.
                        if ($slimstat_malformed || ($slimstat_missing && false === wp_slimstat::$wpdb->query(\SlimStat\Schema\Schema::createIndexSql($slimstat_suffix, $slimstat_index, $slimstat_prefix)))) {
                            $slimstat_indexes_changed = false;
                        // phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared
                            break;
                        }
                    // phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Trusted core table prefix and schema-manifest identifiers; dynamic values are bound on the analytics connection.
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Nonce/capability-protected maintenance uses core-prefix and manifest identifiers; schema probes and mutations require fresh state.
                    } elseif (!$slimstat_missing && false === wp_slimstat::$wpdb->query(sprintf('ALTER TABLE %s DROP INDEX %s', $slimstat_prefix . $slimstat_suffix, $slimstat_resolved))) {
                        $slimstat_indexes_changed = false;
                    // phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared
                        break;
                    }
                }
                if ($slimstat_indexes_changed) {
                    wp_slimstat::$settings['db_indexes'] = $posted_options['db_indexes'];
                    $save_messages[] = 'on' === $posted_options['db_indexes']
                        ? __('Congratulations! SlimStat Analytics is now optimized for <a href="https://www.youtube.com/watch?v=ygE01sOhzz0" target="_blank">ludicrous speed</a>.', 'wp-slimstat')
                        : __('Table indexes have been disabled. Enjoy the extra database space!', 'wp-slimstat');
                } else {
                    $save_messages[] = __('The database index change could not be completed. The saved preference is unchanged; check the database and retry.', 'wp-slimstat');
                }
            }
        }

		// Geolocation settings save (provider-based)
		if (isset($posted_options['geolocation_country']) || isset($posted_options['geolocation_provider']) || isset($posted_options['maxmind_license_key'])) {
			$resolved_prev = wp_slimstat::resolve_geolocation_provider();
			$prevProvider  = false !== $resolved_prev ? $resolved_prev : 'disable';
			$provider     = sanitize_text_field($posted_options['geolocation_provider'] ?? $prevProvider);
            $precision    = ('on' === ($posted_options['geolocation_country'] ?? (wp_slimstat::$settings['geolocation_country'] ?? 'on'))) ? 'country' : 'city';
            $license      = sanitize_text_field($posted_options['maxmind_license_key'] ?? (wp_slimstat::$settings['maxmind_license_key'] ?? ''));

            // Save settings
            wp_slimstat::$settings['geolocation_provider'] = $provider;
            wp_slimstat::$settings['geolocation_country']  = 'country' === $precision ? 'on' : 'no';
            wp_slimstat::$settings['maxmind_license_key']  = $license;

            // Sync legacy flag for tracker (Processor.php) and PRO compatibility
            if ('maxmind' === $provider) {
                wp_slimstat::$settings['enable_maxmind'] = 'on';
            } elseif ('dbip' === $provider || 'cloudflare' === $provider) {
                wp_slimstat::$settings['enable_maxmind'] = 'no';
            } elseif ('disable' === $provider) {
                wp_slimstat::$settings['enable_maxmind'] = 'disable';
            }

            // If provider needs a DB, schedule a background update to avoid timeouts during save
            if (in_array($provider, \SlimStat\Services\GeoService::DB_PROVIDERS, true)) {
                try {
                    // Pass new settings explicitly since they haven't been saved to wp_slimstat::$settings yet
                    $service = new \SlimStat\Services\Geolocation\GeolocationService($provider, [
                        'dbPath'    => \wp_slimstat::$upload_dir,
                        'license'   => $license,
                        'precision' => $precision,
                    ]);
                    $dbExists = file_exists($service->getProvider()->getDbPath());

                    if (!$dbExists || $provider !== $prevProvider) {
                        // Schedule a single-run background job shortly after save
                        if (!wp_next_scheduled('wp_slimstat_update_geoip_database')) {
                            wp_schedule_single_event(time() + 10, 'wp_slimstat_update_geoip_database');
                        }
                        $save_messages[] = __('The geolocation database update has been scheduled in the background. You can also use the Update Database button below to start it now.', 'wp-slimstat');
                    }
                } catch (\Exception $e) {
                    $save_messages[] = $e->getMessage();
                }
            }
        }

        // Browscap Library
        if (!empty($posted_options['enable_browscap'])) {
            if ('on' == $posted_options['enable_browscap'] && ('no' == wp_slimstat::$settings['enable_browscap'] || !is_readable(wp_slimstat::$upload_dir . '/browscap-cache-master/version.txt'))) {
                $error = \SlimStat\Services\Browscap::update_browscap_database(true);
                if (0 == $error[0]) {
                    wp_slimstat::$settings['enable_browscap'] = 'on';
                }
                $save_messages[] = $error[1];
            } elseif ('no' == $posted_options['enable_browscap'] && 'on' == wp_slimstat::$settings['enable_browscap']) {
                if (wp_slimstat_admin::rmdir(wp_slimstat::$upload_dir . '/browscap-cache-master')) {
                    $save_messages[]                          = __('The Browscap data file has been uninstalled from your server.', 'wp-slimstat');
                    wp_slimstat::$settings['enable_browscap'] = 'no';
                } else {
                    $save_messages[] = __('There was an error deleting the Browscap data folder on your server. Please check your permissions.', 'wp-slimstat');
                }
            }
        }

        $tracking_method_changed = isset($posted_options['tracking_request_method'])
            && wp_slimstat::$settings['tracking_request_method'] != $posted_options['tracking_request_method'];

        // All other options
        foreach ($posted_options as $a_post_slug => $a_post_value) {
            if (empty($settings[$current_tab]['rows'][$a_post_slug]) || !empty($settings[$current_tab]['rows'][$a_post_slug]['readonly']) || in_array($settings[$current_tab]['rows'][$a_post_slug]['type'], ['section_header', 'plain-text']) || in_array($a_post_slug, ['enable_maxmind', 'enable_browscap', 'db_indexes'])) {
                continue;
            }

            if (isset($a_post_value)) {
                if ('rich_text' === $settings[$current_tab]['rows'][$a_post_slug]['type']) {
                    // Rich text editor: use wp_kses_post to sanitize HTML
                    wp_slimstat::$settings[$a_post_slug] = wp_kses_post($a_post_value);
                } elseif (empty($settings[$current_tab]['rows'][$a_post_slug]['use_code_editor'])) {
                    wp_slimstat::$settings[$a_post_slug] = sanitize_text_field($a_post_value);
                } else {
                    // Code editor content: strip all tags to prevent XSS
                    wp_slimstat::$settings[$a_post_slug] = wp_strip_all_tags($a_post_value);
                }
            }

            // If the Network Settings add-on is enabled, there might be a switch to decide if this option needs to override what single sites have set
            if (is_network_admin()) {
                if ('on' === ($posted_options['addon_network_settings_' . $a_post_slug] ?? 'no')) {
                    wp_slimstat::$settings['addon_network_settings_' . $a_post_slug] = 'on';
                } else {
                    wp_slimstat::$settings['addon_network_settings_' . $a_post_slug] = 'no';
                }
            } elseif (isset(wp_slimstat::$settings['addon_network_settings_' . $a_post_slug])) {
                // Keep settings clean
                unset(wp_slimstat::$settings['addon_network_settings_' . $a_post_slug]);
            }
        }

        // Keep legacy banner toggle in sync with the selected consent integration.
        $current_consent_integration = wp_slimstat::$settings['consent_integration'] ?? '';
        if ('slimstat_banner' === $current_consent_integration) {
            wp_slimstat::$settings['use_slimstat_banner'] = 'on';
        } else {
            wp_slimstat::$settings['use_slimstat_banner'] = 'off';
        }

        // Allow third-party functions to manipulate the options right before they are saved
        wp_slimstat::$settings = apply_filters('slimstat_save_options', wp_slimstat::$settings);

        // Save the new values in the database
        wp_slimstat::update_option('slimstat_options', wp_slimstat::$settings);

        if ($tracking_method_changed) {
            // Persist the method before another request can rebuild its rules.
            delete_option('rewrite_rules');
        }

        // Register GDPR banner strings for WPML/Polylang translation
        $gdpr_translatable = [
            'opt_out_message'          => wp_slimstat::$settings['opt_out_message'] ?? '',
            'gdpr_accept_button_text'  => wp_slimstat::$settings['gdpr_accept_button_text'] ?? '',
            'gdpr_decline_button_text' => wp_slimstat::$settings['gdpr_decline_button_text'] ?? '',
        ];

        foreach ($gdpr_translatable as $name => $value) {
            if (empty($value)) {
                continue;
            }

            // WPML registration
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML compatibility API requires this exact external hook name.
            do_action('wpml_register_single_string', 'wp-slimstat', $name, $value);

            // Native Polylang registration
            if (function_exists('pll_register_string')) {
                pll_register_string($name, $value, 'wp-slimstat', ($name === 'opt_out_message'));
            }
        }

        if ([] !== $save_messages) {
            wp_slimstat_admin::show_message(implode(' ', $save_messages), 'warning');
        } else {
            wp_slimstat_admin::show_message(__('Your new settings have been saved.', 'wp-slimstat'), 'info');
        }
    }
}

$index_names = [
    $GLOBALS[ 'wpdb' ]->prefix . 'stats_resource_idx',
    $GLOBALS[ 'wpdb' ]->prefix . 'stats_browser_idx',
    $GLOBALS[ 'wpdb' ]->prefix . 'stats_searchterms_idx',
    $GLOBALS[ 'wpdb' ]->prefix . 'stats_fingerprint_idx',
];
$missing_indexes = [];
foreach ($index_names as $idx) {
    // phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Trusted core table prefix and schema-manifest identifiers; dynamic values are bound on the analytics connection.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nonce/capability-protected maintenance uses core-prefix and manifest identifiers; schema probes and mutations require fresh state.
    $exists = wp_slimstat::$wpdb->get_results(wp_slimstat::$wpdb->prepare("SHOW INDEX FROM {$GLOBALS['wpdb']->prefix}slim_stats WHERE Key_name = %s", $idx));
    // phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared
    if (empty($exists)) {
        $missing_indexes[] = $idx;
    }
}
if ([] !== $missing_indexes) {
    echo '<div class="notice notice-warning"><b>' . esc_html__('Performance Notice:', 'wp-slimstat') . '</b> ' . sprintf(
        /* translators: %s: comma-separated list of missing database index names. */
        esc_html__('The following DB indexes are missing and should be created for optimal performance: %s. Please visit the SlimStat settings or re-activate the plugin to trigger index creation.', 'wp-slimstat'),
        '<code>' . esc_html(implode(', ', $missing_indexes)) . '</code>'
    ) . '</div>';
}

$tabs_html = '';
$search_index = [];
// Extra words people search for. Comma-separated; translators may add their own.
$search_keywords = [
    'is_tracking'              => __('enable, disable, pause, stop, on, off', 'wp-slimstat'),
    'gdpr_enabled'             => __('consent, gdpr, cookie banner, ccpa, opt-in', 'wp-slimstat'),
    'consent_integration'      => __('cmp, consent api, cookie plugin, banner', 'wp-slimstat'),
    'anonymize_ip'             => __('ip, mask, privacy, personal data', 'wp-slimstat'),
    'hash_ip'                  => __('ip, hash, salt, pseudonymize', 'wp-slimstat'),
    'set_tracker_cookie'       => __('cookie, returning visitors, cookieless', 'wp-slimstat'),
    'do_not_track'             => __('dnt, gpc, global privacy control', 'wp-slimstat'),
    'auto_purge'               => __('retention, delete old data, purge, cleanup, archive', 'wp-slimstat'),
    'track_admin_pages'        => __('backend, wp-admin, dashboard', 'wp-slimstat'),
    'session_duration'         => __('session, timeout, visit length', 'wp-slimstat'),
    'geolocation_provider'     => __('maxmind, geoip, dbip, country, city, location', 'wp-slimstat'),
    'ignore_ip'                => __('exclude, block, filter, ip address', 'wp-slimstat'),
    'ignore_wp_users'          => __('exclude, logged in, admins, myself', 'wp-slimstat'),
    'ignore_bots'              => __('crawlers, spiders, robots', 'wp-slimstat'),
    'capability_can_view'      => __('permissions, roles, access, who can see', 'wp-slimstat'),
    'limit_results'            => __('sql, query limit, rows, performance', 'wp-slimstat'),
    'delete_data_on_uninstall' => __('remove, erase, uninstall, delete everything', 'wp-slimstat'),
    'rest_api_tokens'          => __('api, token, key, integration', 'wp-slimstat'),
];
foreach ($settings as $a_tab_id => $a_tab_info) {
    if (!empty($a_tab_info['rows']) || (current_user_can('manage_options') && in_array($a_tab_id, [7, 8], true))) {
        $tabs_html .= "<li class='nav-tab nav-tab" . (($current_tab == $a_tab_id) ? '-active' : '-inactive') . "'><a href='" . esc_url(wp_slimstat_admin::$config_url . $a_tab_id) . "'" . ($current_tab == $a_tab_id ? " aria-current='page'" : '') . sprintf('>%s</a></li>', esc_html($a_tab_info['title']));
        // Search index: labels and help text only, never stored values.
        $a_section = '';
        foreach ((array) ($a_tab_info['rows'] ?? []) as $a_slug => $a_row) {
            $a_label = trim(wp_strip_all_tags((string) ($a_row['title'] ?? '')));
            if ('' === $a_label) {
                continue;
            }
            $a_header = 'section_header' === ($a_row['type'] ?? '');
            if ($a_header) {
                $a_section = $a_label;
            }
            $search_index[] = [
                't'  => (int) $a_tab_id,
                'g'  => wp_strip_all_tags($a_tab_info['title']),
                's'  => $a_header ? '' : $a_section,
                'l'  => $a_label,
                'd'  => wp_html_excerpt(wp_strip_all_tags((string) ($a_row['description'] ?? '')), 160, '…'),
                'k'  => $search_keywords[$a_slug] ?? '',
                'id' => $a_header ? 'wp-slimstat-' . ($a_row['anchor'] ?? sanitize_title($a_row['title'])) : 'slimstat-setting-' . $a_slug,
                'a'  => (string) $a_slug,
            ];
        }
    }
}

?>
<div class="backdrop-container">
    <div class="wrap-slimstat slimstat-config">
        <?php wp_slimstat_admin::get_template('header', ['is_pro' => wp_slimstat::pro_is_installed(), 'title' => __('Settings', 'wp-slimstat'), 'license_detail' => in_array($current_tab, [7, 8], true)]); ?>
        <ul class="nav-tabs">
            <?php echo wp_kses_post($tabs_html) ?>
        </ul>

        <div class="slimstat-settings-search" role="search" hidden>
            <label for="slimstat-settings-search-input" class="screen-reader-text"><?php esc_html_e('Search settings', 'wp-slimstat'); ?></label>
            <input type="search" id="slimstat-settings-search-input" placeholder="<?php esc_attr_e('Search settings', 'wp-slimstat'); ?>" autocomplete="off" spellcheck="false" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="slimstat-settings-search-results">
            <ul id="slimstat-settings-search-results" class="slimstat-settings-search__results" role="listbox" aria-label="<?php esc_attr_e('Matching settings', 'wp-slimstat'); ?>" hidden></ul>
            <span class="screen-reader-text" role="status" aria-live="polite"></span>
        </div>
        <?php wp_add_inline_script('slimstat-settings', 'window.slimstatSettingsIndex = ' . wp_json_encode(['url' => wp_slimstat_admin::$config_url, 'rows' => $search_index], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ';', 'before'); ?>

        <?php
        if (in_array($current_tab, [1, 9], true) && current_user_can('manage_options')) :
            $has_consent_source = \SlimStat\Utils\Consent::hasConsentSource();
            $status_rows        = 1 === $current_tab
                ? \SlimStat\Services\SettingsStatus::setupRows(wp_slimstat::$settings, (array) $last_tracker_error, !empty($last_geoip_error), $has_consent_source)
                : \SlimStat\Services\SettingsStatus::privacyRows(wp_slimstat::$settings, $has_consent_source);
            $review_pending     = \SlimStat\Services\SettingsStatus::privacyReviewPending(wp_slimstat::$settings);
            ?>
            <section class="slimstat-settings-status" aria-labelledby="slimstat-settings-status-title">
                <h2 id="slimstat-settings-status-title"><?php echo esc_html(1 === $current_tab ? __('Setup status', 'wp-slimstat') : __('What your privacy settings do', 'wp-slimstat')); ?></h2>
                <?php if (9 === $current_tab) : ?>
                    <p class="slimstat-settings-status__intro"><?php esc_html_e('A plain summary of how SlimStat treats visitor data with the settings saved below. It is not legal advice: what you must do depends on your site, your visitors and the laws that apply to you.', 'wp-slimstat'); ?></p>
                <?php endif; ?>
                <dl class="slimstat-settings-status__list">
                    <?php foreach ($status_rows as $status_row) :
                        $status_target = '' === $status_row['anchor'] ? '' : '#' . (isset($settings[$status_row['tab']]['rows'][$status_row['anchor']]) ? 'slimstat-setting-' : 'wp-slimstat-') . $status_row['anchor'];
                        ?>
                        <div class="slimstat-settings-status__row is-<?php echo esc_attr($status_row['state']); ?>">
                            <dt><?php echo esc_html($status_row['label']); ?></dt>
                            <dd>
                                <?php if ('attention' === $status_row['state']) : ?><span class="screen-reader-text"><?php esc_html_e('Needs attention:', 'wp-slimstat'); ?> </span><?php endif; ?>
                                <a href="<?php echo esc_url(wp_slimstat_admin::$config_url . $status_row['tab'] . $status_target); ?>"><?php echo esc_html($status_row['value']); ?></a>
                            </dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
                <?php if (9 === $current_tab && $review_pending) : ?>
                    <form method="post" action="<?php echo esc_url(wp_slimstat_admin::$config_url . '9&action=privacy-reviewed'); ?>" class="slimstat-settings-status__task">
                        <?php wp_nonce_field('slimstat_update_settings', 'slimstat_update_settings', false); ?>
                        <p><?php esc_html_e('Checked the summary and the settings below? Mark the review complete to stop the reminder. Your settings stay as they are.', 'wp-slimstat'); ?></p>
                        <button type="submit" class="button"><?php esc_html_e('Mark privacy review as complete', 'wp-slimstat'); ?></button>
                    </form>
                <?php elseif (9 === $current_tab) : ?>
                    <p class="slimstat-settings-status__task"><?php esc_html_e('Privacy review completed.', 'wp-slimstat'); ?></p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <div class="notice slimstat-notice slimstat-tooltip-content" style="background-color:#ffa;border:0;padding:10px">
            <?php echo wp_kses_post(__('<strong>AdBlock browser extension detected.</strong> If you see this notice, it means that your browser is not loading our stylesheet and/or JavaScript files correctly. This could be caused by an overzealous ad blocker feature enabled in your browser (AdBlock Plus and friends). <a href="https://wp-slimstat.com/resources/the-reports-are-not-being-rendered-correctly-or-buttons-do-not-work" target="_blank">Please make sure to add an exception</a> to your configuration and allow the browser to load these assets.', 'wp-slimstat')); ?>
        </div>

        <?php if (!wp_slimstat::pro_plugin_active() && current_user_can('manage_options') && in_array($current_tab, [7, 8], true)) : ?>
            <section class="slimstat-settings-preview">
                <h2><?php echo esc_html(8 === $current_tab ? __('License', 'wp-slimstat') : __('Pro settings', 'wp-slimstat')); ?></h2>
                <p><?php echo esc_html(8 === $current_tab
                    ? __('Install and activate the Pro plugin, then enter your license key here. Activating the plugin and activating a license are separate steps. Your current Free settings stay unchanged.', 'wp-slimstat')
                    : __('Install and activate the Pro plugin to configure these settings. Your current Free settings stay unchanged.', 'wp-slimstat')); ?></p>
                <?php wp_slimstat_admin::get_template('pro-adds', [
                    'text' => 8 === $current_tab
                        ? __('A Pro license gives you plugin updates and support.', 'wp-slimstat')
                        : __('With Pro, choose which pages heatmaps track and how often, use an internal IP location lookup and keep stats in an external database.', 'wp-slimstat'),
                    'campaign' => 'settings', 'content' => 8 === $current_tab ? 'license-tab' : 'pro-tab',
                ]); ?>
            </section>
        <?php elseif (!empty($settings[$current_tab]['rows'])) : ?>

            <form action="<?php echo esc_url(wp_slimstat_admin::$config_url . $current_tab) ?>" method="post" id="slimstat-options-<?php echo esc_attr((string) $current_tab) ?>">
                <?php wp_nonce_field('slimstat_update_settings', 'slimstat_update_settings'); ?>
                <?php wp_nonce_field('slimstat_save_settings'); ?>
                <table class="form-table widefat <?php echo esc_attr($GLOBALS['wp_locale']->text_direction) ?>">
                    <tbody><?php
                    $i = 0;

            foreach ($settings[$current_tab]['rows'] as $a_setting_slug => $a_setting_info) {
                $i++;
                $a_setting_info = array_merge([
                    'title'              => '',
                    'type'               => '',
                    'rows'               => 4,
                    'description'        => '',
                    'before_input_field' => '',
                    'after_input_field'  => '',
                    'custom_label_yes'   => '',
                    'custom_label_no'    => '',
                    'use_tag_list'       => true,
                    'use_code_editor'    => '',
                    'select_values'      => [],
                ], $a_setting_info);

                // Note: $a_setting_info[ 'readonly' ] is set to true by the Network Analytics add-on
                $is_readonly     = (empty($a_setting_info['readonly'])) ? '' : ' readonly';
                $use_tag_list    = (('' === $is_readonly || '0' === $is_readonly) && !empty($a_setting_info['use_tag_list']) && true === $a_setting_info['use_tag_list']) ? ' slimstat-taglist' : '';
                $use_code_editor = (('' === $is_readonly || '0' === $is_readonly) && !empty($a_setting_info['use_code_editor'])) ? ' data-code-editor="' . esc_attr($a_setting_info['use_code_editor']) . '"' : '';

                // Native controls (audit A10): a checkbox with role="switch" and its state text beside it.
                // Readonly (Network Analytics add-on) blocks the click instead of disabling the input,
                // so the submitted value is still the current one, not the hidden "no".
                $readonly_switch           = '' === $is_readonly ? '' : ' aria-readonly="true" onclick="return false"';
                $network_override_checkbox = is_network_admin() ? '
				<input type="hidden" value="no" name="options[addon_network_settings_' . esc_attr($a_setting_slug) . ']" id="addon_network_settings_' . esc_attr($a_setting_slug) . '">
				<input class="slimstat-switch" type="checkbox" role="switch"
					aria-label="' . esc_attr__('Network-wide', 'wp-slimstat') . '"
					name="options[addon_network_settings_' . esc_attr($a_setting_slug) . ']"' .
                    ((!empty(wp_slimstat::$settings['addon_network_settings_' . $a_setting_slug]) && 'on' == wp_slimstat::$settings['addon_network_settings_' . $a_setting_slug]) ? ' checked="checked"' : '') . '
					id="addon_network_settings_' . esc_attr($a_setting_slug) . '"><span class="slimstat-switch__state" aria-hidden="true" data-on="' . esc_attr__('Network', 'wp-slimstat') . '" data-off="' . esc_attr__('Site', 'wp-slimstat') . '"></span>' : '';

                // Build conditional data attributes
                $conditional_attrs = '';
                if (!empty($a_setting_info['conditional'])) {
                    $cond = $a_setting_info['conditional'];
                    $conditional_attrs = ' data-conditional-field="' . esc_attr($cond['field']) . '"';
                    if (!empty($cond['type'])) {
                        $conditional_attrs .= ' data-conditional-type="' . esc_attr($cond['type']) . '"';
                    }
                    if (isset($cond['value'])) {
                        $conditional_attrs .= ' data-conditional-value="' . esc_attr($cond['value']) . '"';
                    }
                }

                // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Literal attributes and extension-provided form markup; dynamic attribute values are escaped when assembled above. KSES post rules would remove required form controls.
                echo '<tr id="slimstat-setting-' . esc_attr($a_setting_slug) . '"' . (0 == $i % 2 ? ' class="alternate"' : '') . $conditional_attrs . '>';
                // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
                switch ($a_setting_info['type']) {
                    case 'section_header':
                        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Literal attributes and extension-provided form markup; dynamic attribute values are escaped when assembled above. KSES post rules would remove required form controls.
                        echo '<td colspan="2" class="slimstat-options-section-header"' . $conditional_attrs . ' id="wp-slimstat-' . esc_attr($a_setting_info['anchor'] ?? sanitize_title($a_setting_info['title'])) . '">' . wp_kses_post($a_setting_info['title']) . '</td>';
                        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
                        break;

                    case 'toggle':
                        $is_on = !empty(wp_slimstat::$settings[$a_setting_slug]) && 'on' == wp_slimstat::$settings[$a_setting_slug];
                        if (!empty($a_setting_info['custom_label_on'])) {
                            // A choice between two named options (Client/Server): a segmented radio group.
                            // The "on" radio keeps the setting's id, so #slug:checked still means "on".
                            $th      = '<th scope="row"><span id="' . esc_attr($a_setting_slug) . '-label">' . wp_kses_post($a_setting_info['title']) . '</span></th>';
                            $control = '<span class="slimstat-segmented" role="radiogroup" aria-labelledby="' . esc_attr($a_setting_slug) . '-label">
								<label><input type="radio" class="slimstat-checkbox-toggle" name="options[' . esc_attr($a_setting_slug) . ']" id="' . esc_attr($a_setting_slug) . '" value="on"' . ($is_on ? ' checked="checked"' : '') . $readonly_switch . '><span>' . esc_html($a_setting_info['custom_label_on']) . '</span></label>
								<label><input type="radio" name="options[' . esc_attr($a_setting_slug) . ']" id="' . esc_attr($a_setting_slug) . '-off" value="no"' . ($is_on ? '' : ' checked="checked"') . $readonly_switch . '><span>' . esc_html($a_setting_info['custom_label_off']) . '</span></label>
							</span>';
                        } else {
                            $th      = '<th scope="row"><label for="' . esc_attr($a_setting_slug) . '">' . wp_kses_post($a_setting_info['title']) . '</label></th>';
                            $control = '<input class="slimstat-checkbox-toggle slimstat-switch" type="checkbox" role="switch"
								name="options[' . esc_attr($a_setting_slug) . ']"
								id="' . esc_attr($a_setting_slug) . '"' . ($is_on ? ' checked="checked"' : '') . $readonly_switch . '><span class="slimstat-switch__state" aria-hidden="true" data-on="' . esc_attr__('On', 'wp-slimstat') . '" data-off="' . esc_attr__('Off', 'wp-slimstat') . '"></span>';
                        }
                        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Literal attributes and extension-provided form markup; dynamic attribute values are escaped when assembled above. KSES post rules would remove required form controls.
                        echo $th . '
					<td>
						<input type="hidden" value="no" name="options[' . esc_attr($a_setting_slug) . ']">
						<span class="block-element">' . $control . $network_override_checkbox . '</span>
						<span class="description">' . wp_kses_post($a_setting_info['description']) . '</span>
					</td>';
                        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
                        break;

                    case 'select':
                        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Literal attributes and extension-provided form markup; dynamic attribute values are escaped when assembled above. KSES post rules would remove required form controls.
                        echo '<th scope="row"><label for="' . esc_attr($a_setting_slug) . '">' . wp_kses_post($a_setting_info['title']) . '</label></th>
					<td>
						<span class="block-element">
							<select' . $is_readonly . ' name="options[' . esc_attr($a_setting_slug) . ']" id="' . esc_attr($a_setting_slug) . '">';
                        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
                        foreach ($a_setting_info['select_values'] as $a_key => $a_value) {
                            $is_selected = (!empty(wp_slimstat::$settings[$a_setting_slug]) && wp_slimstat::$settings[$a_setting_slug] == $a_key) ? ' selected' : '';
                            // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Literal attributes and extension-provided form markup; dynamic attribute values are escaped when assembled above. KSES post rules would remove required form controls.
                            echo '<option' . $is_selected . ' value="' . esc_attr($a_key) . '">' . esc_html($a_value) . '</option>';
                            // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
                        }
                        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Literal attributes and extension-provided form markup; dynamic attribute values are escaped when assembled above. KSES post rules would remove required form controls.
                        echo '</select> ' . $a_setting_info['after_input_field'] .
                            $network_override_checkbox . '
						</span>
						<span class="description">' . wp_kses_post($a_setting_info['description']) . '</span>
					</td>';
                        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
                        break;

                    case 'text':
                    case 'integer':
                        $empty_value = ('text' == $a_setting_info['type']) ? '' : '0';
                        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Literal attributes and extension-provided form markup; dynamic attribute values are escaped when assembled above. KSES post rules would remove required form controls.
                        echo '<th scope="row"><label for="' . esc_attr($a_setting_slug) . '">' . wp_kses_post($a_setting_info['title']) . '</label></th>
					<td>
						<span class="block-element"> ' .
                            $a_setting_info['before_input_field'] . '
							<input class="' . (('integer' == $a_setting_info['type']) ? 'small-text' : 'regular-text') . '"' . $is_readonly . (('integer' == $a_setting_info['type'] && (int) (wp_slimstat::$settings[$a_setting_slug] ?? 0) >= 0) ? ' min="0"' : '') . '
								type="' . (('integer' == $a_setting_info['type']) ? 'number' : 'text') . '"
								name="options[' . esc_attr($a_setting_slug) . ']"
								id="' . esc_attr($a_setting_slug) . '"
								value="' . (empty(wp_slimstat::$settings[$a_setting_slug]) ? $empty_value : esc_attr(wp_slimstat::$settings[$a_setting_slug])) . '"> ' . $a_setting_info['after_input_field'] .
                            $network_override_checkbox . '
						</span>
						<span class="description">' . wp_kses_post($a_setting_info['description']) . '</span>
					</td>';
                        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
                        break;

                    case 'rich_text':
                        $editor_content = empty(wp_slimstat::$settings[$a_setting_slug]) ? '' : wp_kses_post(wp_slimstat::$settings[$a_setting_slug]);
                        $editor_settings = [
                            'textarea_name' => 'options[' . $a_setting_slug . ']',
                            'textarea_rows' => 8,
                            'media_buttons' => false,
                            'teeny' => true,
                            'tinymce' => [
                                'toolbar1' => 'bold,italic,underline,link,unlink,removeformat',
                                'toolbar2' => '',
                            ],
                        ];
                        if (!empty($is_readonly)) {
                            $editor_settings['readonly'] = true;
                        }
                        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Literal attributes and extension-provided form markup; dynamic attribute values are escaped when assembled above. KSES post rules would remove required form controls.
                        echo '
					<td colspan="2">
						<label for="' . esc_attr($a_setting_slug) . '">' . wp_kses_post($a_setting_info['title']) . $network_override_checkbox . '</label>
						<p class="description">' . wp_kses_post($a_setting_info['description']) . '</p>
						<p>';
                        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
                        wp_editor($editor_content, $a_setting_slug, $editor_settings);
                        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Literal attributes and extension-provided form markup; dynamic attribute values are escaped when assembled above. KSES post rules would remove required form controls.
                        echo '
							<span class="description">' . $a_setting_info['after_input_field'] . '</span>
						</p>
					</td>';
                        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
                        break;

                    case 'textarea':
                        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Literal attributes and extension-provided form markup; dynamic attribute values are escaped when assembled above. KSES post rules would remove required form controls.
                        echo '
					<td colspan="2">
						<label for="' . esc_attr($a_setting_slug) . '">' . wp_kses_post($a_setting_info['title']) . $network_override_checkbox . '</label>
						<p class="description">' . wp_kses_post($a_setting_info['description']) . '</p>
						<p>
							<textarea class="large-text code' . $use_tag_list . '"' . $is_readonly . $use_code_editor . '
								id="' . esc_attr($a_setting_slug) . '"
								rows="' . esc_attr($a_setting_info['rows'] ?? 4) . '"
								name="options[' . esc_attr($a_setting_slug) . ']">' . (empty(wp_slimstat::$settings[$a_setting_slug]) ? '' : esc_textarea(wp_slimstat::$settings[$a_setting_slug])) . '</textarea>
							<span class="description">' . $a_setting_info['after_input_field'] . '</span>
						</p>
					</td>';
                        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
                        break;

                    case 'plain-text':
                        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Literal attributes and extension-provided form markup; dynamic attribute values are escaped when assembled above. KSES post rules would remove required form controls.
                        echo '<th scope="row"><label for="' . esc_attr($a_setting_slug) . '">' . wp_kses_post($a_setting_info['title']) . '</label></th>
					<td>
						<span class="block-element">' . $a_setting_info['after_input_field'] . '</span>
						<span class="description">' . wp_kses_post($a_setting_info['description']) . '</span>
					</td>';
                        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
                        break;

                    case 'custom':
                        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Literal attributes and extension-provided form markup; dynamic attribute values are escaped when assembled above. KSES post rules would remove required form controls.
                        echo '<td colspan="2">' . wp_kses_post($a_setting_info['title']) . '<br/><br/>' . $a_setting_info['markup'] . '</td>';
                        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
                        break;

                    default:
                }
                echo '</tr>';
            }
            ?></tbody>
                </table>

                <p class="submit slimstat-save-bar">
                    <input type="submit" value="<?php esc_attr_e('Save Changes', 'wp-slimstat'); ?>" class="button-primary slimstat-settings-button" name="Submit">
                    <span class="slimstat-save-bar__status" role="status" aria-live="polite"></span>
                </p>
            </form>

        <?php endif ?>
    </div>
</div>

<?php
// Detect companion consent plugins to disable unavailable options in UI
if (!function_exists('is_plugin_active')) {
    include_once ABSPATH . 'wp-admin/includes/plugin.php';
}
$has_wp_consent_api     = function_exists('is_plugin_active') && is_plugin_active('wp-consent-api/wp-consent-api.php');
// $has_real_cookie_banner = function_exists('is_plugin_active') && is_plugin_active('real-cookie-banner/index.php');
?>
<script>
(function($){
    $(function(){
        // Disable integrations that are not installed
        var hasWpConsent = <?php echo $has_wp_consent_api ? 'true' : 'false'; ?>;
        // var hasRCB       = <?php echo isset($has_real_cookie_banner) && $has_real_cookie_banner ? 'true' : 'false'; ?>;
        var $ci = $('#consent_integration');
        if(!hasWpConsent){
            $ci.find('option[value="wp_consent_api"]').prop('disabled', true);
            // If currently selected value is wp_consent_api but plugin is not installed, reset to default
            if($ci.val() === 'wp_consent_api') {
                $ci.val('slimstat_banner').trigger('change');
            }
        }
        // if(!hasRCB){ $ci.find('option[value="real_cookie_banner"]').prop('disabled', true); }

        // Initialize conditional fields system (from admin.js)
        if (typeof window.SlimStatConditionalFields !== 'undefined') {
            window.SlimStatConditionalFields.init();
        }
    });
})(jQuery);
</script>
