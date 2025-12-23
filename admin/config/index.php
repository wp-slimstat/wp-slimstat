<?php

// Avoid direct access to this piece of code
if (!function_exists('add_action')) {
    exit(0);
}

// Determine what tab is currently being displayed
$current_tab = empty($_GET['tab']) ? 1 : intval($_GET['tab']);

// Retrieve any tracker errors for display
$last_tracker_error = get_option('slimstat_tracker_error', []);

// Retrieve any geoip errors for display
$last_geoip_error = get_option('slimstat_geoip_error', []);

// Build General → Tracker rows, conditionally adding Tracking Request Method under Tracking Mode
$general_rows = [
    // General - Tracker
    'general_tracking_header' => [
        'title' => __('Tracker', 'wp-slimstat'),
        'type'  => 'section_header',
    ],
    'is_tracking' => [
        'title'       => __('Enable Tracking', 'wp-slimstat'),
        'type'        => 'toggle',
        'description' => __('Turn the tracker on or off, while keeping the reports accessible.', 'wp-slimstat'),
    ],
    'track_admin_pages' => [
        'title'       => __('Track Backend', 'wp-slimstat'),
        'type'        => 'toggle',
        'description' => __("Enable this option to track your users' activity within the WordPress admin.", 'wp-slimstat'),
    ],
    'javascript_mode' => [
        'title'            => __('Tracking Mode', 'wp-slimstat'),
        'type'             => 'toggle',
        'custom_label_on'  => __('Client', 'wp-slimstat'),
        'custom_label_off' => __('Server', 'wp-slimstat'),
        'description'      => __("Select <strong>Client</strong> if you are using a caching plugin (W3 Total Cache, WP SuperCache, HyperCache, etc). Slimstat will behave pretty much like Google Analytics, and visitors whose browser doesn't support Javascript will be ignored. Select <strong>Server</strong> if you are not using a caching tool on your website, and would like to track <em>every single visit</em> to your site.", 'wp-slimstat'),
    ],
    'tracking_request_method' => [
        'title'         => __('Tracking Request Method', 'wp-slimstat'),
        'type'          => 'select',
        'description'   => __('Choose how Slimstat sends tracking requests to the server. Fallback logic is always enabled: if the selected method fails, Slimstat will automatically try the next available method.<br /><strong>Note:</strong> that some ad blockers may block tracking requests sent via the REST API or admin-ajax.php. If you are using one of these methods and notice that some visits are not being tracked, consider using the Ad-Blocker Bypass method.', 'wp-slimstat'),
        'select_values' => [
            'rest'           => __('REST API – Fast, falls back to Admin-AJAX if the request fails', 'wp-slimstat'),
            'ajax'           => __('Admin-AJAX – Compatible, but may be blocked by ad blockers too (recommended)', 'wp-slimstat'),
            'adblock_bypass' => __('Ad-Blocker Bypass – Most reliable, avoids ad blockers', 'wp-slimstat'),
        ],
    ],
];

// Define all the options
$settings = [
    1 => [
        'title' => __('General', 'wp-slimstat'),
        'rows'  => $general_rows + [
            // General - WordPress Integration
            'general_integration_header' => [
                'title' => __('WordPress Integration', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'add_dashboard_widgets' => [
                'title'       => __('Dashboard Widgets', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Enable this option if you want to add reports to your WordPress Dashboard. Use the Customizer to choose which ones to display.', 'wp-slimstat'),
            ],
            'use_separate_menu' => [
                'title'       => __('Use Admin Bar', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Choose if you want to display the Slimstat menu in the sidebar or as a drop down in the admin bar (if visible).', 'wp-slimstat'),
            ],
            'add_posts_column' => [
                'title'       => __('Posts and Pages', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Add a new column to the Edit Posts/Pages screens, which will contain the hit count or unique visits per post. You can customize the default timeframe in Settings > Reports > Report Interval.', 'wp-slimstat'),
            ],
            'posts_column_pageviews' => [
                'title'            => __('Report Type', 'wp-slimstat'),
                'type'             => 'toggle',
                'custom_label_on'  => __('Hits', 'wp-slimstat'),
                'custom_label_off' => __('IPs', 'wp-slimstat'),
                'description'      => __('Customize the information displayed when activating the option here above: <strong>hits</strong> refers to the total amount of pageviews, regardless of the user; <strong>(unique) IPs</strong> displays the amount of distinct IP addresses tracked in the given time range.', 'wp-slimstat'),
            ],

            'display_notifications' => [
                'title'       => __('Slimstat Notifications', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Display important notifications inside the plugin, such as new version releases, feature updates, news, and special offers.', 'wp-slimstat'),
            ],
        ],
    ],

    2 => [
        'title' => __('Tracker', 'wp-slimstat'),
        'rows'  => [
            // Tracker - Consent Management
            'consent_management_header' => [
                'title' => __('Consent Management', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
			'gdpr_enabled' => [
				'title'       => __('GDPR Compliance Mode', 'wp-slimstat'),
				'type'        => 'toggle',
				'description' => __('<strong>GDPR Compliance:</strong> When enabled, SlimStat requires user consent before tracking (except in Anonymous Tracking mode). When disabled, tracking operates normally without consent checks.<br/><br/><strong>Enabled:</strong> (Recommended for EU/EEA) Tracking requires consent unless Anonymous Tracking mode is active. This ensures GDPR compliance.<br/><strong>Disabled:</strong> Normal tracking without consent checks. Use this only if you are not subject to GDPR regulations (e.g., non-EU websites with no EU visitors).', 'wp-slimstat'),
			],
			'consent_integration' => [
				'title'         => __('Consent Plugin Integration', 'wp-slimstat'),
				'type'          => 'select',
				'description'   => __('<strong>GDPR Compliance:</strong> Integrate with a Consent Management Platform (CMP) to ensure tracking only occurs with user consent.<br/><br/><strong>SlimStat Consent Banner:</strong> (Recommended) Use SlimStat\'s built-in banner with customizable messaging and server-side consent tracking.<br/><strong>Via WP Consent API:</strong> Integrates with CMPs supporting WordPress Consent API (Complianz, CookieYes, etc.). Server-side consent checking available for both modes.', 'wp-slimstat'),
				'select_values' => [
					'slimstat_banner'    => __('SlimStat Consent Banner (Recommended)', 'wp-slimstat'),
					'wp_consent_api'     => __('Via WP Consent API', 'wp-slimstat'),
					// 'real_cookie_banner' => __('Real Cookie Banner', 'wp-slimstat'),
				],
				'conditional' => [
					'field' => 'gdpr_enabled',
					'type' => 'checked',
				],
			],
			'slimstat_banner_header' => [
				'title' => __('SlimStat Consent Banner', 'wp-slimstat'),
				'type'  => 'section_header',
				'conditional' => [
					'field' => 'gdpr_enabled,consent_integration',
					'type' => 'checked,equals',
					'value' => '|||slimstat_banner',
				],
			],
            'opt_out_cookie_names' => [
                'title'       => __('Opt-out Cookies', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __("If you are already using another tool to monitor which users opt-out of tracking, and assuming that this tool sets its own cookie to remember their selection, you can enter the cookie names and values in this field to let Slimstat comply with their choice. Please use the following format: <code>cookie_name=value</code>. Slimstat will track any visitors who either don't send a cookie with that name, or send a cookie whose value <strong>does not CONTAIN</strong> the string you specified. If your tool uses structured values like JSON or similar encodings, find the substring related to tracking and enter that as the value here below. For example, <a href='https://wordpress.org/plugins/smart-cookie-kit/' target='_blank'>Smart Cookie Kit</a> uses something like <code>{\"settings\":{\"technical\":true,\"slimstat\":false,\"profiling\":false},\"ver\":\"2.0.0\"}</code>, so your pair should look like: <code>CookiePreferences-your.website.here=\"slimstat\":false</code>. Separate multiple pairs with commas.", 'wp-slimstat'),
                'conditional' => [
                    'field' => 'gdpr_enabled,consent_integration',
                    'type' => 'checked,equals',
                    'value' => '|||',
                ],
            ],
            'opt_in_cookie_names' => [
                'title'       => __('Opt-in Cookies', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __('Similarly to the option here above, you can configure Slimstat to work with an opt-in mechanism. Please use the following format: <code>cookie_name=value</code>. Slimstat will only track visitors who send a cookie whose value <strong>CONTAINS</strong> the string you specified. Separate multiple pairs with commas.', 'wp-slimstat'),
                'conditional' => [
                    'field' => 'gdpr_enabled,consent_integration',
                    'type' => 'checked,equals',
                    'value' => '|||',
                ],
            ],
			'opt_out_message' => [
				'title'             => __('Consent Banner Message', 'wp-slimstat'),
				'type'              => 'rich_text',
				'after_input_field' => '',
				'description'       => __('Content displayed inside the SlimStat consent banner. Basic HTML (p, a, strong, em) is allowed. Use the editor above to format your message.', 'wp-slimstat'),
				'conditional' => [
					'field' => 'gdpr_enabled,consent_integration',
					'type' => 'checked,equals',
					'value' => '|||slimstat_banner',
				],
			],
			'gdpr_accept_button_text' => [
				'title'              => __('Accept Button Label', 'wp-slimstat'),
				'type'               => 'text',
				'before_input_field' => '',
				'after_input_field'  => '',
				'description'        => __('Leave empty to use the default "Accept" text.', 'wp-slimstat'),
				'conditional' => [
					'field' => 'gdpr_enabled,consent_integration',
					'type' => 'checked,equals',
					'value' => '|||slimstat_banner',
				],
			],
			'gdpr_decline_button_text' => [
				'title'              => __('Decline Button Label', 'wp-slimstat'),
				'type'               => 'text',
				'before_input_field' => '',
				'after_input_field'  => '',
				'description'        => __('Leave empty to use the default "Deny" text.', 'wp-slimstat'),
				'conditional' => [
					'field' => 'gdpr_enabled,consent_integration',
					'type' => 'checked,equals',
					'value' => '|||slimstat_banner',
				],
			],
            'gdpr_theme_mode' => [
                'title'         => __('Banner Theme Mode', 'wp-slimstat'),
                'type'          => 'select',
                'description'   => __("Choose the theme mode for the GDPR consent banner. <strong>Light</strong> uses light colors, <strong>Dark</strong> uses dark colors, and <strong>Auto</strong> follows the user's system preference.", 'wp-slimstat'),
                'select_values' => [
                    'light' => __('Light Mode', 'wp-slimstat'),
                    'dark'  => __('Dark Mode', 'wp-slimstat'),
                    'auto'  => __('Auto (Follow System)', 'wp-slimstat'),
                ],
                'conditional' => [
					'field' => 'gdpr_enabled,consent_integration',
					'type' => 'checked,equals',
					'value' => '|||slimstat_banner',
				],
            ],
/*
            'consent_level_integration' => [
                'title'         => __('Consent Category', 'wp-slimstat'),
                'type'          => 'select',
                'description'   => __('Select the consent category SlimStat should belong to. Tracking will only occur if the visitor grants consent for this specific category.<br/><br/><strong>Functional:</strong> Essential website functionality. Not typically used for analytics.<br/><strong>Statistics-Anonymous:</strong> Anonymous analytics only. Use this if you have enabled Anonymous Tracking mode OR configured SlimStat to be cookie-less with anonymized/hashed IPs.<br/><strong>Statistics:</strong> (Default & Recommended) Standard analytics tracking. Appropriate for both anonymous and standard tracking modes. Real Cookie Banner and most CMPs recommend this category for analytics plugins.<br/><strong>Marketing:</strong> Advertising and user profiling. Not applicable to SlimStat core functionality.<br/><br/><strong>Note for Real Cookie Banner:</strong> Make sure to configure SlimStat in the Real Cookie Banner plugin settings and assign it to the same category selected here.', 'wp-slimstat'),
                'select_values' => [
                    'functional'            => __('Functional', 'wp-slimstat'),
                    'statistics-anonymous'  => __('Statistics-Anonymous', 'wp-slimstat'),
                    'statistics'            => __('Statistics', 'wp-slimstat'),
                    'marketing'             => __('Marketing', 'wp-slimstat'),
                ],
                'conditional' => [
					'field' => 'gdpr_enabled,consent_integration',
					'type' => 'checked,in',
					'value' => '|||wp_consent_api,real_cookie_banner',
				],
            ],
*/

            // Tracker - Data Protection
            'privacy_header' => [
                'title' => __('Data Protection', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
/*
            'anonymous_tracking' => [
                'title'       => __('Anonymous Tracking Mode', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('<strong>GDPR-Safe Mode:</strong> When enabled, SlimStat operates in strict GDPR-compliant mode.<br/><br/><strong>Before Consent:</strong> Tracks anonymously (hashed IPs, no cookies, no username/email)<br/><strong>After Consent:</strong> Upgrades to full tracking (real IPs, cookies, user identification)<br/><br/>This mode is recommended if you want to track all visitors while staying GDPR-compliant. Anonymous data is collected without consent, then upgraded when consent is granted.', 'wp-slimstat'),
                'conditional' => [
					'field' => 'gdpr_enabled',
					'type' => 'checked',
				],
            ],
            'do_not_track' => [
                'title'       => __('Respect Do Not Track (DNT)', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('<strong>Privacy Enhancement:</strong> Honor the DNT browser header. When a visitor has DNT enabled in their browser, NO tracking occurs (not even anonymous tracking).<br/><br/>GDPR does not require this, but it demonstrates respect for user privacy preferences. Recommended for privacy-focused websites.', 'wp-slimstat'),
                'conditional' => [
					'field' => 'gdpr_enabled',
					'type' => 'checked',
				],
            ],
*/
            'anonymize_ip' => [
                'title'       => __('Anonymize IP Addresses', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('<strong>GDPR Privacy Protection:</strong> Masks IP addresses before storage (IPv4: 192.168.1.x → 192.168.1.0 / IPv6: last 80 bits removed).<br/><br/>Anonymized IPs cannot identify individual users but still provide useful geographic and network data. <strong>Recommended</strong> for GDPR compliance when not using IP hashing.', 'wp-slimstat'),
            ],
            'hash_ip' => [
                'title'       => __('Hash IP Addresses', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('<strong>GDPR-Compliant Visitor Counting:</strong> Creates one-way hash from IP + User Agent + daily salt. Hash changes daily, preventing long-term tracking.<br/><br/><strong>Benefits:</strong> Count unique visitors without storing real IPs or using cookies. Original IP cannot be recovered from hash. <strong>Recommended</strong> for GDPR compliance.', 'wp-slimstat'),
            ],
            'set_tracker_cookie' => [
                'title'       => __('Set Tracking Cookie', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('<strong>PII Warning:</strong> Cookies are Personally Identifiable Information under GDPR. Enabling this option requires user consent.<br/><br/><strong>When Disabled:</strong> Cookie-less tracking (more privacy, less accurate return visitor detection)<br/><strong>When Enabled:</strong> Sets a cookie to track returning visitors (better accuracy, requires consent)<br/><br/>Cookies automatically respect consent settings and use Secure, HttpOnly, and SameSite flags for security.', 'wp-slimstat'),
            ],

            // Tracker - Link Tracking
            'filters_outbound_header' => [
                'title' => __('Link Tracking', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'track_same_domain_referers' => [
                'title'       => __('Same-Domain Referrers', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __("By default, when a referrer's domain's pageview is the same as the current site, that information is not saved in the database. However, if you are running a multisite network with subfolders, you might need to enable this option to track same-domain referrers from one site to another, as they are technically 'independent' websites.", 'wp-slimstat'),
            ],
            'extensions_to_track' => [
                'title'       => __('Downloads', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __('List all the file extensions that you want to be identified as Downloads. Please note that links pointing to external resources (i.e. PDFs on an external website) will be tracked as Downloads and not Outbound Links, if they match one of the extensions listed here below.', 'wp-slimstat'),
            ],

            // Maintenance - Third-party Libraries
            'maintenance_third_party_header' => [
                'title' => __('Third-party Libraries', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
			'geolocation_provider' => [
				'title'         => __('Geolocation Provider', 'wp-slimstat'),
				'type'          => 'select',
				'select_values' => [
					'maxmind'    => __('MaxMind GeoLite2 (recommended)', 'wp-slimstat'),
					'dbip'       => __('DB-IP City Lite (free)', 'wp-slimstat'),
					'cloudflare' => __('Cloudflare Header', 'wp-slimstat'),
				],
                'description' => __('<strong>Choose how Slimstat resolves visitor locations:</strong><br /><strong>DB-IP City Lite</strong> – Free, no license required. Slimstat downloads a local database and updates it automatically in the background after you save settings. You can also run the update manually using the button below. Works for arbitrary IPs in reports.<br /><strong>MaxMind GeoLite2</strong> – Requires a free MaxMind license key. City vs Country precision affects database size and download time. Updates run in the background after saving; you can also update manually. If PHP Phar is disabled on your server, please upload the .mmdb file manually to wp-content/uploads/wp-slimstat/.<br /><strong>Cloudflare Header</strong> – No database needed. Slimstat reads the HTTP_CF_IPCOUNTRY header set by Cloudflare for the current request only. It won\'t resolve arbitrary test IPs (like 8.8.8.8). Make sure "IP Geolocation" is enabled in your Cloudflare dashboard and your site is actually proxied through Cloudflare.', 'wp-slimstat'),
            ],
            'maxmind_license_key' => [
                'title'       => __('MaxMind License Key', 'wp-slimstat'),
                'type'        => 'text',
                'description' => __('Enter your MaxMind license key to enable automatic downloads of the GeoLite2 database. The license key should be 16-40 characters containing only letters, numbers, and underscores. Required only if you select MaxMind as the provider. <strong>Important:</strong> If the PHP Phar extension is not available on your server, automatic extraction will fail—upload the .mmdb file manually to wp-content/uploads/wp-slimstat/.', 'wp-slimstat'),
            ],
            'geolocation_db_actions' => [
                'title'             => __('Geolocation Database', 'wp-slimstat'),
                'after_input_field' => '<input type="hidden" id="slimstat-geoip-nonce" value="' . wp_create_nonce('wp_rest') . '" /><a href="#" id="slimstat-update-geoip-database" class="button-secondary noslimstat" style="vertical-align: middle" data-error-message="' . __('An error occurred while updating the GeoIP database.', 'wp-slimstat') . '">' . __('Update Database', 'wp-slimstat') . '</a> <a href="#" id="slimstat-check-geoip-database" class="button-secondary noslimstat" style="vertical-align: middle" data-error-message="' . __('An error occurred while updating the GeoIP database.', 'wp-slimstat') . '">' . __('Check Database', 'wp-slimstat') . '</a>',
                'type'              => 'plain-text',
					'description'       => __('Download or refresh the selected geolocation database. <strong>DB-IP/MaxMind only</strong>: "Update Database" runs it now; after saving settings, Slimstat also schedules a background update. "Check Database" verifies that the file exists and is readable. <strong>Cloudflare</strong>: No database is required—the header is used at request time.', 'wp-slimstat'),
            ],
            'enable_browscap' => [
                'title'       => __('Browscap Library', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __("We are contributing to the <a href='https://browscap.org/' target='_blank'>Browscap Capabilities Project</a>, which we use to decode your visitors' user agent string into browser name and operating system. We use an <a href='https://github.com/slimstat/browscap-cache' target='_blank'>optimized version of their data structure</a>, for improved performance. When enabled, Slimstat uses this library in addition to the built-in heuristic function, to determine your visitors' browser information. Updates are downloaded automatically every week, when available.", 'wp-slimstat') . (empty(\SlimStat\Services\Browscap::$browscap_local_version) ? '' : ' ' . sprintf(__('You are currently using version %s.', 'wp-slimstat'), '<strong>' . \SlimStat\Services\Browscap::$browscap_local_version . '</strong>')),
            ],

            // Tracker - Advanced Options
            'advanced_tracker_header' => [
                'title' => __('Advanced Options', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'geolocation_country' => [
                'title'            => __('Geolocation Precision', 'wp-slimstat'),
                'type'             => 'toggle',
                'custom_label_on'  => __('Country', 'wp-slimstat'),
                'custom_label_off' => __('City', 'wp-slimstat'),
                'description'      => __('Choose between Country and City precision. City uses a larger database and may take longer to download (and more disk space). Country is smaller and faster. Applies to DB‑IP and MaxMind; Cloudflare always provides country only.', 'wp-slimstat'),
            ],
            'session_duration' => [
                'title'             => __('Visit Duration', 'wp-slimstat'),
                'type'              => 'integer',
                'after_input_field' => __('seconds', 'wp-slimstat'),
                'description'       => __('How many seconds should a human visit last? Google Analytics sets it to 1800 seconds.', 'wp-slimstat'),
            ],
            'extend_session' => [
                'title'       => __('Extend Duration', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __("Reset your visitors' visit duration every time they access a new page within the current visit.", 'wp-slimstat'),
            ],

            // Tracker - Performance
            'performance_header' => [
                'title' => __('Performance', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'enable_cdn' => [
                'title'       => __('Enable CDN', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __("Use <a href='https://www.jsdelivr.com/' target='_blank'>JSDelivr</a>'s CDN, by serving our tracking code from their fast and reliable network (free service).", 'wp-slimstat'),
            ],
            'ajax_relative_path' => [
                'title'       => __('Relative Ajax', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Try enabling this option if you are experiencing issues related to the header field X-Requested-With not being allowed by Access-Control-Allow-Headers in preflight response (or similar).', 'wp-slimstat'),
            ],

            // Tracker - External Pages
            'advanced_external_pages_header' => [
                'title' => __('External Pages', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'external_domains' => [
                'title'       => __('Allowed Domains', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __("If you are getting an error saying that no 'Access-Control-Allow-Origin' header is present on the requested resource, when using the external tracking code here above, list the domains (complete with scheme) you would like to allow. For example: <code>https://my.domain.ext</code> (no trailing slash). Please see <a href='https://www.w3.org/TR/cors/#security' target='_blank'>this W3 resource</a> for more information on the security implications of allowing CORS requests.", 'wp-slimstat'),
            ],
            'external_pages_script' => [
                'type'   => 'custom',
                'title'  => __('Add the following code to all the non-WordPress pages you would like to track, right before the closing BODY tag. Please make sure to change the protocol of all the URLs to HTTPS, if you external site is using a secure channel.', 'wp-slimstat'),
                'markup' => '<pre style="max-width:100%">&lt;script type="text/javascript"&gt;\n/* &lt;![CDATA[ */\nvar SlimStatParams = { ajaxurl: "' . ((('on' == (wp_slimstat::$settings['ajax_relative_path'] ?? '')) ? admin_url('admin-ajax.php', 'relative') : admin_url('admin-ajax.php'))) . '" };\n/* ]]&gt; */\n&lt;/script&gt;\n&lt;script type="text/javascript" src="https://cdn.jsdelivr.net/wp/wp-slimstat/trunk/wp-slimstat.min.js"&gt;&lt;/script&gt;</pre>',
            ],

            // Tracker - Third-party Libraries
            'third_party_libraries_header' => [
                'title' => __('Third-party Libraries', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'enable_maxmind' => [
                'title'             => __('GeoIP Database Source', 'wp-slimstat'),
                'after_input_field' => ((!empty($_POST['options']['enable_maxmind']) && 'disable' != sanitize_text_field($_POST['options']['enable_maxmind'])) || (empty($_POST['options']['enable_maxmind']) && 'disable' != wp_slimstat::$settings['enable_maxmind'])) ? '<input type="hidden" id="slimstat-geoip-nonce" value="' . wp_create_nonce('wp_rest') . '" /><a href="#" id="slimstat-update-geoip-database" class="button-secondary noslimstat" style="vertical-align: middle" data-error-message="' . __('An error occurred while updating the GeoIP database.', 'wp-slimstat') . '">' . __('Update Database', 'wp-slimstat') . '</a> <a href="#" id="slimstat-check-geoip-database" class="button-secondary noslimstat" style="vertical-align: middle" data-error-message="' . __('An error occurred while updating the GeoIP database.', 'wp-slimstat') . '">' . __('Check Database', 'wp-slimstat') . '</a>' : '',
                'type'              => 'select',
                'select_values'     => [
                    'disable' => __('Disable', 'wp-slimstat'),
                    'no'      => __('Use the JsDelivr', 'wp-slimstat'),
                    'on'      => __('Use the MaxMind server with your own license key', 'wp-slimstat'),
                ],
                'description' => __('Choose a service to update the GeoIP database to ensure your geographic information is accurate and up-to-date.', 'wp-slimstat') . '<br />' . __('<b>Note: </b>If the database file is missing, it will be downloaded when you save the settings.', 'wp-slimstat'),
            ],
            'maxmind_license_key' => [
                'title'       => __('MaxMind License Key', 'wp-slimstat'),
                'type'        => 'text',
                'description' => __('To be able to automatically download and update the MaxMind GeoLite2 database, you must sign up on <a href="https://dev.maxmind.com/geoip/geoip2/geolite2/" target="_blank">MaxMind GeoLite2</a> and create a license key. Then enter your license key in this field. Disable- and re-enable MaxMind Geolocation above to activate the license key. Note: It takes a couple of minutes after you created the license key to get it activated on the MaxMind website.', 'wp-slimstat'),
            ],
            'enable_browscap' => [
                'title'       => __('Browscap Library', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __("We are contributing to the <a href='https://browscap.org/' target='_blank'>Browscap Capabilities Project</a>, which we use to decode your visitors' user agent string into browser name and operating system. We use an <a href='https://github.com/slimstat/browscap-cache' target='_blank'>optimized version of their data structure</a>, for improved performance. When enabled, Slimstat uses this library in addition to the built-in heuristic function, to determine your visitors' browser information. Updates are downloaded automatically every week, when available.", 'wp-slimstat') . (empty(\SlimStat\Services\Browscap::$browscap_local_version) ? '' : ' ' . sprintf(__('You are currently using version %s.', 'wp-slimstat'), '<strong>' . \SlimStat\Services\Browscap::$browscap_local_version . '</strong>')),
            ],
        ],
    ],

    3 => [
        'title' => __('Reports', 'wp-slimstat'),
        'rows'  => [
            // Reports - Functionality
            'reports_functionality_header' => [
                'title' => __('Functionality', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'use_current_month_timespan' => [
                'title'       => __('Current Month', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Determine what time window to use for the reports. Enable this option to default to the current month, disable it to use the past X number of days (see option here below). Use the date and time filters for a more granular analysis.', 'wp-slimstat'),
            ],
            'posts_column_day_interval' => [
                'title'             => __('Time Range', 'wp-slimstat'),
                'type'              => 'integer',
                'after_input_field' => __('days', 'wp-slimstat'),
                'description'       => __('Default number of days in the time window used to generate all the reports. We set it to 4 weeks so that the comparison charts will overlap nicely (i.e. Monday over Monday) for a more meaningful analysis. This value is ignored if the option here above is turned on.', 'wp-slimstat'),
            ],
            'rows_to_show' => [
                'title'             => __('Rows to Display', 'wp-slimstat'),
                'type'              => 'integer',
                'after_input_field' => __('rows', 'wp-slimstat'),
                'description'       => __('Define the number of rows to display in Top and Recent reports. You can adjust this number to improve your server performance.', 'wp-slimstat'),
            ],
            'ip_lookup_service' => [
                'title'       => __('IP Geolocation', 'wp-slimstat'),
                'type'        => 'text',
                'description' => __('Customize the URL of the geolocation service to be used in the Access Log. Default value: <code>https://whatismyipaddress.com/ip/</code>', 'wp-slimstat'),
            ],
            'comparison_chart' => [
                'title'       => __('Comparison Chart', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Slimstat displays two sets of charts, allowing you to compare the current time window with the previous one. Disable this option if you find those four charts confusing, and prefer seeing only the selected time range. Please keep in mind that you can always temporarily hide one series by clicking on the corresponding entry in the legend.', 'wp-slimstat'),
            ],
            'show_display_name' => [
                'title'       => __('Use Display Name', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('By default, users are listed by their usernames. Enable this option to show their display names instead.', 'wp-slimstat'),
            ],
            'convert_resource_urls_to_titles' => [
                'title'       => __('Display Titles', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('For improved legibility, most reports list post and page titles instead of their permalinks. Use this option to change this behavior.', 'wp-slimstat'),
            ],
            'show_hits' => [
                'title'       => __('Display Hits', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('By default, Top and Recent reports display the percentage of pageviews compared to the total for each entry, and the actual number of hits on hover in a tooltip. Enable this feature if you prefer to see the number of hits directly and the percentage in the tooltip.', 'wp-slimstat'),
            ],
            'convert_ip_addresses' => [
                'title'       => __('Show Hostnames', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Enable this option to display the hostname associated to each IP address. Please note that this might affect performance, as Slimstat will need to query your DNS server for each address.', 'wp-slimstat'),
            ],

            // Reports - Access Log and World Map
            'reports_right_now_header' => [
                'title' => __('Access Log and World Map', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'refresh_interval' => [
                'title'             => __('Auto Refresh', 'wp-slimstat'),
                'type'              => 'integer',
                'after_input_field' => __('seconds', 'wp-slimstat'),
                'description'       => __('When a value greater than zero is entered, the Access Log view will refresh every X seconds. Enter <strong>0</strong> (the number zero) if you would like to deactivate this feature.', 'wp-slimstat'),
            ],
            'number_results_raw_data' => ['title' => __('Rows to Display', 'wp-slimstat'),
                'type'                            => 'integer',
                'description'                     => __('Define the number of rows to visualize in the Access Log.', 'wp-slimstat'),
                'after_input_field'               => __('rows', 'wp-slimstat'),
            ],
            'max_dots_on_map' => ['title' => __('Map Data Points', 'wp-slimstat'),
                'type'                    => 'integer',
                'description'             => __('Customize the maximum number of data points displayed on the world map. Please note that larger numbers might negatively affect rendering times.', 'wp-slimstat'),
                'after_input_field'       => __('points', 'wp-slimstat'),
            ],

            // Reports - Miscellaneous
            'reports_miscellaneous_header' => [
                'title' => __('Miscellaneous', 'wp-slimstat'),
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
                'title'       => __('Chart Colors', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __('Customize the look and feel of your charts by assigning your own colors to each metric. List four hex colors, in the following order: metric 1 previous, metric 2 previous, metric 1 current, metric 2 current. For example: <code>#ccc, #999, #bbcc44, #21759b</code>.', 'wp-slimstat'),
            ],
            'mozcom_access_id' => ['title' => __('Mozscape Access ID', 'wp-slimstat'),
                'type'                     => 'text',
                'description'              => __('Get accurate rankings for your website through the <a href="https://moz.com/community/join?redirect=/products/api/keys" target="_blank">Mozscape API</a>. Sign up for a free community account to get started. Then enter your personal identification code in this field.', 'wp-slimstat'),
            ],
            'mozcom_secret_key' => ['title' => __('Mozscape Secret Key', 'wp-slimstat'),
                'type'                      => 'text',
                'description'               => __('This key is needed to query the Mozscape API (see option here above). Treat it like a password and do not share it with anyone, or they will be able to make API requests using your account.', 'wp-slimstat'),
            ],
            'show_complete_user_agent_tooltip' => [
                'title'       => __('Show User Agent', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Enable this option if you want to see the full user agent string when hovering over each browser icon in the Access Log and elsewhere.', 'wp-slimstat'),
            ],
            'async_load' => [
                'title'       => __('Async Mode', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Activate this feature if your reports take a while to load. It breaks down the load on your server into multiple smaller requests, thus avoiding memory issues and performance problems.', 'wp-slimstat'),
            ],
            'limit_results' => [
                'title'             => __('SQL Limit', 'wp-slimstat'),
                'type'              => 'integer',
                'after_input_field' => __('rows', 'wp-slimstat'),
                'description'       => __("You can limit the number of records that each SQL query will take into consideration when crunching aggregate values (maximum, average, etc). You might need to adjust this value if you're getting an error saying that you exceeded your PHP memory limit while accessing the slimstat.", 'wp-slimstat'),
            ],
            'enable_sov' => [
                'title'       => __('Enable SOV', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('In linguistic typology, a subject-object-verb (SOV) language is one in which the subject, object, and verb of a sentence appear in that order, like in Japanese.', 'wp-slimstat'),
            ],
        ],
    ],

    4 => [
        'title' => __('Exclusions', 'wp-slimstat'),
        'rows'  => [
            // Exclusions - User Properties
            'filters_users_header' => [
                'title' => __('User Properties', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'ignore_wp_users' => [
                'title'       => __('WP Users', 'wp-slimstat'),
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
                'description' => __('If enabled, pageviews generated by crawlers, spiders, search engine bots, and other automated tools will not be tracked. Please note that if the tracker is set to work in Client mode, some of those pageviews might not be tracked anyway, since these tools usually do not run any embedded Javascript code.', 'wp-slimstat'),
            ],
            'ignore_prefetch' => [
                'title'       => __('Prefetch Requests', 'wp-slimstat'),
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
                'title'       => __('IP Addresses', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __("Enter a list of IP addresses that should not be tracked. Each subnet <strong>must</strong> be defined using the <a href='https://www.iplocation.net/subnet-mask' target='_blank'>CIDR notation</a> (i.e. <em>192.168.0.0/24</em>). This filter applies both to the public IP address and the originating IP address, if available. Using the CIDR notation, you will use octets to determine the mask. For example, 54.0.0.0/8 matches any address that has 54 as the first number; 54.12.0.0/16 matches any address that starts with 54.12, and so on.", 'wp-slimstat'),
            ],
            'ignore_countries' => [
                'title'       => __('Countries', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __('Enter a list of lowercase <a href="https://en.wikipedia.org/wiki/ISO_3166-1_alpha-2" target="_blank">ISO 3166-1 country codes</a> (i.e.: <code>us, it, es</code>) that should not be tracked. Please note: this field does not allow wildcards.', 'wp-slimstat'),
            ],
            'ignore_languages' => [
                'title'       => __('Languages', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __('Enter a list of lowercase <a href="http://www.lingoes.net/en/translator/langcode.htm" target="_blank">ISO 639-1 language codes</a> (i.e.: <code>en-us, fr-ca, zh-cn</code>) that should not be tracked. Please note: this field does not allow wildcards.', 'wp-slimstat'),
            ],
            'ignore_browsers' => [
                'title'       => __('User Agents', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __("Enter a list of browser names that should not be tracked. You can specify the browser's version adding a slash after the name (i.e. <em>Firefox/36</em>). Technically speaking, Slimstat will match your list against the visitor's user agent string. Strings are case-insensitive. See note at the bottom of this page for more information on how to use wildcards.", 'wp-slimstat'),
            ],
            'ignore_platforms' => [
                'title'       => __('Operating Systems', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __('Enter a list of operating system codes that should not be tracked. Please refer to <a href="https://wp-slimstat.com/knowledge-base/" target="_blank">this page</a> in our knowledge base to learn more about which codes can be used. See note at the bottom of this page for more information on how to use wildcards.', 'wp-slimstat'),
            ],

            // Exclusions - Page Properties
            'filters_pageview_header' => [
                'title' => __('Page Properties', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'ignore_resources' => [
                'title'       => __('Permalinks', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __('Enter a list of permalinks that should not be tracked. Do not include your website domain name: <code>/about, ?p=1</code>, etc. See note at the bottom of this page for more information on how to use wildcards. Strings are case-insensitive.', 'wp-slimstat'),
            ],
            'do_not_track_outbound_classes_rel_href' => [
                'title'       => __('Link Attributes: class names, REL and HREF', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __('Do not track events on page elements whose class names, <em>rel</em> attributes or <em>href</em> attribute contain one of the following strings. Please keep in mind that the class <code>noslimstat</code> is used to avoid tracking interactive links throughout the reports. If you remove it from this list, some features might not work as expected.', 'wp-slimstat'),
            ],
            'ignore_referers' => [
                'title'       => __('Referring Sites', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __('Enter a list of referring URLs that should not be tracked: <code>https://mysite.com*</code>, <code>*/ignore-me-please</code>, etc. See note at the bottom of this page for more information on how to use wildcards. Strings are case-insensitive and must include the protocol (https://, https://).', 'wp-slimstat'),
            ],
            'ignore_content_types' => [
                'title'       => __('Content Types', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __('Enter a list of Slimstat content types that should not be tracked: <code>post, page, attachment, tag, 404, taxonomy, author, archive, search, feed, login</code>, etc. See note at the bottom of this page for more information on how to use wildcards. String should be entered in lowercase.', 'wp-slimstat'),
            ],
            'wildcards_description' => ['Wildcards',
                'type'   => 'custom',
                'title'  => '<p class="description">' . __('<strong>Wildcards</strong><br>You can use the character <code>*</code> to match <em>any string, including the empty string</em>, and the character <code>!</code> to match <em>any character, including no character</em>. For example, <code>user*</code> matches user12 and userfoo, <code>u*100</code> matches user100 and ur100, <code>user!0</code> matches user10, user0 and user90, but not user100.', 'wp-slimstat') . '</p>',
                'markup' => '',
            ],
        ],
    ],

    5 => [
        'title' => __('Access Control', 'wp-slimstat'),
        'rows'  => [
            // Access Control - Reports
            'permissions_reports_header' => [
                'title' => __('Reports', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'restrict_authors_view' => [
                'title'       => __('Restrict Authors', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Enable this option if you want your authors to only see slimstat related to their own content.', 'wp-slimstat'),
            ],
            'capability_can_view' => [
                'title'         => __('Minimum Capability', 'wp-slimstat'),
                'type'          => isset($GLOBALS['wp_roles']->role_objects['administrator']->capabilities) ? 'select' : 'text',
                'select_values' => array_combine(array_keys($GLOBALS['wp_roles']->role_objects['administrator']->capabilities), array_keys($GLOBALS['wp_roles']->role_objects['administrator']->capabilities)),
                'description'   => __("Specify the minimum <a href='https://wordpress.org/support/article/roles-and-capabilities/' target='_new'>capability</a> your WordPress users must have to have to access the reports (default: <code>manage_options</code>). The field here below can be used to override this option for specific users.", 'wp-slimstat'),
            ],
            'can_view' => [
                'title'       => __('Usernames', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __("Enter a list of usernames who should have access to the slimstat. Administrators are implicitly allowed, so you don't need to list them here below. Usernames are case sensitive. Wildcards are not allowed.", 'wp-slimstat'),
            ],

            // Access Control - Customizer
            'permissions_customize_header' => [
                'title' => __('Customizer', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'capability_can_customize' => [
                'title'         => __('Minimum Capability', 'wp-slimstat'),
                'type'          => isset($GLOBALS['wp_roles']->role_objects['administrator']->capabilities) ? 'select' : 'text',
                'select_values' => array_combine(array_keys($GLOBALS['wp_roles']->role_objects['administrator']->capabilities), array_keys($GLOBALS['wp_roles']->role_objects['administrator']->capabilities)),
                'description'   => __("Specify the minimum <a href='https://wordpress.org/support/article/roles-and-capabilities/' target='_new'>capability</a> your WordPress users must have to access the Customizer (default: <code>manage_options</code>). The field here below can be used to override this option for specific users.", 'wp-slimstat'),
            ],
            'can_customize' => [
                'title'       => __('Usernames', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __("Enter a list of usernames who should have access to the customizer. Administrators are implicitly allowed, so you don't need to list them here below. Usernames are case sensitive. Wildcards are not allowed.", 'wp-slimstat'),
            ],

            // Access Control - Settings
            'permissions_config_header' => [
                'title' => __('Settings', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'capability_can_admin' => [
                'title'         => __('Minimum Capability', 'wp-slimstat'),
                'type'          => isset($GLOBALS['wp_roles']->role_objects['administrator']->capabilities) ? 'select' : 'text',
                'select_values' => array_combine(array_keys($GLOBALS['wp_roles']->role_objects['administrator']->capabilities), array_keys($GLOBALS['wp_roles']->role_objects['administrator']->capabilities)),
                'description'   => __("Specify the minimum <a href='https://wordpress.org/support/article/roles-and-capabilities/' target='_new'>capability</a> your WordPress users must have to configure Slimstat (default: <code>manage_options</code>). The field here below can be used to override this option for specific users.", 'wp-slimstat'),
            ],
            'can_admin' => [
                'title'       => __('Usernames', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __('Enter a list of usernames who should have access to the plugin settings. Please be advised that administrators <strong>are not</strong> implicitly allowed, so do not forget to include yourself! Usernames are case sensitive. Wildcards are not allowed.', 'wp-slimstat'),
            ],

            // Access Control - REST API
            'rest_api_header' => [
                'title' => __('REST API', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'rest_api_tokens' => [
                'title'       => __('Tokens', 'wp-slimstat'),
                'type'        => 'textarea',
                'description' => __("In order to send requests to the Slimstat REST API, you will need to pass a valid token to the endpoint (param ?token=XXX). Using the field here below, you can define as many tokens as you like, and distribute them to your API users. Please note: treat these tokens as passwords, as they will grant read access to your reports to anyone who knows them. Use a service like <a href='https://randomkeygen.com/#ci_key' target='_blank'>RandomKeyGen.com</a> to generate unique secure tokens.", 'wp-slimstat'),
            ],
        ],
    ],

    6 => [
        'title' => __('Maintenance', 'wp-slimstat'),
        'rows'  => [
            // Maintenance - Data Retention
            'maintenance_data_retention_header' => [
                'title' => __('Data Retention & Auto-Purge', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'auto_purge' => [
                'title'             => __('Retention Period', 'wp-slimstat'),
                'type'              => 'integer',
                'after_input_field' => __('days', 'wp-slimstat'),
                'description'       => __('<strong>GDPR Compliance:</strong> Automatically purge data older than the specified number of days. This process runs twice daily via WordPress cron to keep your database clean and maintain GDPR compliance.<br/><br/><strong>Recommended:</strong> <strong>420 days (14 months)</strong> - Complies with ePrivacy Directive and most GDPR interpretations. This ensures data is automatically removed after a reasonable retention period.<br/><strong>Warning:</strong> Retaining data longer than 14 months may require additional legal justification and a clear Data Processing Agreement (DPA) under GDPR Article 5(1)(e) (Storage Limitation Principle). Failing to comply can result in significant fines.<br/><br/>Set to <strong>0</strong> to disable automatic purging (<strong>strongly discouraged</strong> for GDPR compliance, as unlimited retention requires a very strong and documented legal justification).', 'wp-slimstat'),
            ],
            'auto_purge_delete' => [
                'title'       => __('Archive Mode', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('<strong>How to handle old data:</strong><br/><br/><strong>Enabled (Archive):</strong> Old records are moved to separate archive tables (<code>wp_slim_stats_archive</code>, <code>wp_slim_events_archive</code>) instead of being permanently deleted. This improves query performance by keeping the main tables smaller, while still allowing you to access historical data if needed. <strong>Note:</strong> Archived data still counts as data retention under GDPR requirements.<br/><br/><strong>Disabled (Delete):</strong> Old records are permanently deleted from the database. This is the most GDPR-compliant approach and frees up database space immediately. <strong>Warning:</strong> Deleted data cannot be recovered.<br/><br/><strong>Important:</strong> Archive tables are <strong>permanently deleted</strong> when you uninstall SlimStat. Always <strong>backup your data</strong> before uninstalling if you need to retain it.', 'wp-slimstat'),
            ],

            // Maintenance - Troubleshooting
            'maintenance_troubleshooting_header' => [
                'title' => __('Troubleshooting', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'last_tracker_error' => [
                'title'             => __('Tracker Error', 'wp-slimstat'),
                'type'              => 'plain-text',
                'after_input_field' => empty($last_tracker_error) ? __('So far so good.', 'wp-slimstat') : '<strong>[' . date_i18n(get_option('date_format'), $last_tracker_error[1], true) . ' ' . date_i18n(get_option('time_format'), $last_tracker_error[1], true) . '] ' . $last_tracker_error[0] . ' ' . wp_slimstat_i18n::get_string('e-' . $last_tracker_error[0]) . '</strong><a class="slimstat-font-cancel" title="' . htmlentities(__('Reset this error', 'wp-slimstat'), ENT_QUOTES, 'UTF-8') . '" href="' . wp_slimstat_admin::$config_url . $current_tab . '&amp;action=reset-tracker-error&amp;slimstat_update_settings=' . wp_create_nonce('slimstat_update_settings') . '"></a>',
                'description'       => __('The information here above is useful to troubleshoot issues with the tracker. <strong>Errors</strong> are returned when the tracker could not record a page view for some reason, and are indicative of some kind of malfunction.', 'wp-slimstat'),
            ],
            'last_geoip_error' => [
                'title'             => __('GeoIP Database Error', 'wp-slimstat'),
                'type'              => 'plain-text',
                'after_input_field' => empty($last_geoip_error) ? __('So far so good.', 'wp-slimstat') : '<strong>[' . date_i18n(get_option('date_format'), $last_geoip_error['time'], true) . ' ' . date_i18n(get_option('time_format'), $last_geoip_error['time'], true) . '] ' . $last_geoip_error['error'] . '</strong><a class="slimstat-font-cancel" title="' . htmlentities(__('Reset this error', 'wp-slimstat'), ENT_QUOTES, 'UTF-8') . '" href="' . wp_slimstat_admin::$config_url . $current_tab . '&amp;action=reset-geoip-error&amp;slimstat_update_settings=' . wp_create_nonce('slimstat_update_settings') . '"></a>',
                'description'       => __("The information here above is useful to troubleshoot issues with the GeoIP Database. <strong>Errors</strong> are returned when the GeoIP Database can't update or retrieve a visitor's location, indicating some malfunction.", 'wp-slimstat'),
            ],
            'show_sql_debug' => [
                'title'       => __('SQL Debug', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Enable this option to display the SQL code associated to each report. This can be useful to troubleshoot issues with data consistency or missing pageviews.', 'wp-slimstat'),
            ],
            'db_indexes' => [
                'title'       => __('Increase Performance', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Enable this option to add column indexes to the main Slimstat table. This will make SQL queries faster and increase the size of the table by about 30%.', 'wp-slimstat'),
            ],

            // Maintenance - Danger Zone
            'maintenance_danger_zone_header' => [
                'title' => __('Danger Zone', 'wp-slimstat'),
                'type'  => 'section_header',
            ],
            'delete_all_records' => [
                'title'             => __('Data', 'wp-slimstat'),
                'type'              => 'plain-text',
                'after_input_field' => '<a class="button-primary" href="' . wp_slimstat_admin::$config_url . $current_tab . '&amp;action=truncate-table&amp;slimstat_update_settings=' . wp_create_nonce('slimstat_update_settings') . '" onclick="return( confirm( \'' . __('Please confirm that you want to PERMANENTLY DELETE ALL the records from your database.', 'wp-slimstat') . '\' ) )">' . __('Delete Records', 'wp-slimstat') . '</a>',
                'description'       => __('Delete all the information collected by Slimstat so far, but not the archived records (stored in <code>wp_slim_stats_archive</code>). This operation <strong>does not</strong> reset your settings and it can be undone by manually copying your records from the archive table, if you have the corresponding option enabled.', 'wp-slimstat'),
            ],
            'reset_all_settings' => [
                'title'             => __('Settings', 'wp-slimstat'),
                'type'              => 'plain-text',
                'after_input_field' => '<a class="button-primary" href="' . wp_slimstat_admin::$config_url . $current_tab . '&amp;action=reset-settings&amp;slimstat_update_settings=' . wp_create_nonce('slimstat_update_settings') . '" onclick="return( confirm( \'' . __('Please confirm that you want to RESET your settings.', 'wp-slimstat') . '\' ) )">' . __('Factory Reset', 'wp-slimstat') . '</a>',
                'description'       => __('Restore all the settings to their default value. This action DOES NOT delete any records collected by the plugin.', 'wp-slimstat'),
            ],
            'delete_data_on_uninstall' => [
                'title'       => __('Delete Data on Uninstall', 'wp-slimstat'),
                'type'        => 'toggle',
                'description' => __('Delete all settings and slimstat on plugin uninstall. Warning! If you enable this feature, all slimstat and plugin settings will be permanently deleted from the database.', 'wp-slimstat'),
            ],
        ],
    ],

    7 => [
        'title' => __('Pro Options', 'wp-slimstat'),
    ],

    8 => [
        'title' => __('License', 'wp-slimstat'),
    ],
];


// Allow third-party tools to add their own settings
$settings = apply_filters('slimstat_options_on_page', $settings);

// Save options
$save_messages = [];
if (!empty($settings) && !empty($_REQUEST['slimstat_update_settings']) && wp_verify_nonce($_REQUEST['slimstat_update_settings'], 'slimstat_update_settings')) {
    if (!empty($_GET['action'])) {
        switch ($_GET['action']) {
            case 'reset-tracker-error':
                $settings[6]['rows']['last_tracker_error']['after_input_field'] = __('So far so good.', 'wp-slimstat');
                wp_slimstat::update_option('slimstat_tracker_error', []);
                break;

            case 'reset-geoip-error':
                $settings[6]['rows']['last_geoip_error']['after_input_field'] = __('So far so good.', 'wp-slimstat');
                wp_slimstat::update_option('slimstat_geoip_error', []);
                break;

            case 'reset-settings':
                wp_slimstat::update_option('slimstat_options', wp_slimstat::init_options());
                wp_slimstat_admin::show_message(__('All settings were successfully reset to their default values.', 'wp-slimstat'));
                break;

            case 'truncate-table':
                wp_slimstat::$wpdb->query(sprintf('DELETE te FROM %sslim_events te', $GLOBALS[ 'wpdb' ]->prefix));
                wp_slimstat::$wpdb->query(sprintf('OPTIMIZE TABLE %sslim_events', $GLOBALS[ 'wpdb' ]->prefix));
                wp_slimstat::$wpdb->query(sprintf('DELETE t1 FROM %sslim_stats t1', $GLOBALS[ 'wpdb' ]->prefix));
                wp_slimstat::$wpdb->query(sprintf('OPTIMIZE TABLE %sslim_stats', $GLOBALS[ 'wpdb' ]->prefix));
                wp_slimstat_admin::show_message(__('All your records were successfully deleted.', 'wp-slimstat'));
                break;

            default:
                break;
        }
    }

    if (! current_user_can('manage_options')) {
        wp_die(__('Insufficient permissions.', 'wp-slimstat'));
    }

    // Some of them require extra processing
    if (!empty($_POST['options'])) {

        if (!check_admin_referer('slimstat_save_settings')) {
            wp_die(__('Sorry, you are not allowed to access this page.', 'wp-slimstat'));
        }
        // DB Indexes
        if (!empty($_POST['options']['db_indexes'])) {
            if ('on' == $_POST['options']['db_indexes'] && 'no' == wp_slimstat::$settings['db_indexes']) {
                wp_slimstat::$wpdb->query(sprintf('ALTER TABLE %sslim_stats ADD INDEX %sstats_resource_idx( resource( 20 ) )', $GLOBALS[ 'wpdb' ]->prefix, $GLOBALS[ 'wpdb' ]->prefix));
                wp_slimstat::$wpdb->query(sprintf('ALTER TABLE %sslim_stats ADD INDEX %sstats_browser_idx( browser( 10 ) )', $GLOBALS[ 'wpdb' ]->prefix, $GLOBALS[ 'wpdb' ]->prefix));
                wp_slimstat::$wpdb->query(sprintf('ALTER TABLE %sslim_stats ADD INDEX %sstats_searchterms_idx( searchterms( 15 ) )', $GLOBALS[ 'wpdb' ]->prefix, $GLOBALS[ 'wpdb' ]->prefix));
                wp_slimstat::$wpdb->query(sprintf('ALTER TABLE %sslim_stats ADD INDEX %sstats_fingerprint_idx( fingerprint( 20 ) )', $GLOBALS[ 'wpdb' ]->prefix, $GLOBALS[ 'wpdb' ]->prefix));
                $save_messages[]                     = __('Congratulations! Slimstat Analytics is now optimized for <a href="https://www.youtube.com/watch?v=ygE01sOhzz0" target="_blank">ludicrous speed</a>.', 'wp-slimstat');
                wp_slimstat::$settings['db_indexes'] = 'on';
            } elseif ('no' == $_POST['options']['db_indexes'] && 'on' == wp_slimstat::$settings['db_indexes']) {
                // An empty value means that the toggle has been switched to "Off"
                wp_slimstat::$wpdb->query(sprintf('ALTER TABLE %sslim_stats DROP INDEX %sstats_resource_idx', $GLOBALS[ 'wpdb' ]->prefix, $GLOBALS[ 'wpdb' ]->prefix));
                wp_slimstat::$wpdb->query(sprintf('ALTER TABLE %sslim_stats DROP INDEX %sstats_browser_idx', $GLOBALS[ 'wpdb' ]->prefix, $GLOBALS[ 'wpdb' ]->prefix));
                wp_slimstat::$wpdb->query(sprintf('ALTER TABLE %sslim_stats DROP INDEX %sstats_searchterms_idx', $GLOBALS[ 'wpdb' ]->prefix, $GLOBALS[ 'wpdb' ]->prefix));
                wp_slimstat::$wpdb->query(sprintf('ALTER TABLE %sslim_stats DROP INDEX %sstats_fingerprint_idx', $GLOBALS[ 'wpdb' ]->prefix, $GLOBALS[ 'wpdb' ]->prefix));
                $save_messages[]                     = __('Table indexes have been disabled. Enjoy the extra database space!', 'wp-slimstat');
                wp_slimstat::$settings['db_indexes'] = 'no';
            }
        }

		// Geolocation settings save (provider-based)
		if (isset($_POST['options']['geolocation_country']) || isset($_POST['options']['geolocation_provider']) || isset($_POST['options']['maxmind_license_key'])) {
			$prevProvider = wp_slimstat::$settings['geolocation_provider'] ?? 'maxmind';
			$provider     = sanitize_text_field($_POST['options']['geolocation_provider'] ?? $prevProvider);
            $precision    = ('on' === ($_POST['options']['geolocation_country'] ?? (wp_slimstat::$settings['geolocation_country'] ?? 'on'))) ? 'country' : 'city';
            $license      = sanitize_text_field($_POST['options']['maxmind_license_key'] ?? (wp_slimstat::$settings['maxmind_license_key'] ?? ''));

            // Save settings
            wp_slimstat::$settings['geolocation_provider'] = $provider;
            wp_slimstat::$settings['geolocation_country']  = 'country' === $precision ? 'on' : 'no';
            wp_slimstat::$settings['maxmind_license_key']  = $license;

            // If provider needs a DB, schedule a background update to avoid timeouts during save
            if ('cloudflare' !== $provider) {
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
        if (!empty($_POST['options']['enable_browscap'])) {
            if ('on' == $_POST['options']['enable_browscap'] && 'no' == wp_slimstat::$settings['enable_browscap']) {
                $error = \SlimStat\Services\Browscap::update_browscap_database(true);
                if (0 == $error[0]) {
                    wp_slimstat::$settings['enable_browscap'] = 'on';
                }
                $save_messages[] = $error[1];
            } elseif ('no' == $_POST['options']['enable_browscap'] && 'on' == wp_slimstat::$settings['enable_browscap']) {
                if (wp_slimstat_admin::rmdir(wp_slimstat::$upload_dir . '/browscap-cache-master')) {
                    $save_messages[]                          = __('The Browscap data file has been uninstalled from your server.', 'wp-slimstat');
                    wp_slimstat::$settings['enable_browscap'] = 'no';
                } else {
                    $save_messages[] = __('There was an error deleting the Browscap data folder on your server. Please check your permissions.', 'wp-slimstat');
                }
            }
        }

        // Refresh WP permalinks, in case the user has changed the tracking method
        if (isset($_POST['options']['tracking_request_method']) && wp_slimstat::$settings['tracking_request_method'] != $_POST['options']['tracking_request_method']) {
            update_option('slimstat_permalink_structure_updated', true); // This will trigger a rewrite rules flush
        }

        // All other options
        foreach ($_POST['options'] as $a_post_slug => $a_post_value) {
            if (empty($settings[$current_tab]['rows'][$a_post_slug]) || !empty($settings[$current_tab]['rows'][$a_post_slug]['readonly']) || in_array($settings[$current_tab]['rows'][$a_post_slug]['type'], ['section_header', 'plain-text']) || in_array($a_post_slug, ['enable_maxmind', 'enable_browscap'])) {
                continue;
            }

            if (isset($a_post_value)) {
                if ('rich_text' === $settings[$current_tab]['rows'][$a_post_slug]['type']) {
                    // Rich text editor: use wp_kses_post to sanitize HTML
                    wp_slimstat::$settings[$a_post_slug] = wp_kses_post($a_post_value);
                } elseif (empty($settings[$current_tab]['rows'][$a_post_slug]['use_code_editor'])) {
                    wp_slimstat::$settings[$a_post_slug] = htmlspecialchars(sanitize_text_field($a_post_value));
                } else {
                    wp_slimstat::$settings[$a_post_slug] = $a_post_value;
                }
            }

            // If the Network Settings add-on is enabled, there might be a switch to decide if this option needs to override what single sites have set
            if (is_network_admin()) {
                if ('on' == $_POST['options']['addon_network_settings_' . $a_post_slug]) {
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

        if ([] !== $save_messages) {
            wp_slimstat_admin::show_message(implode(' ', $save_messages), 'warning');
        } else {
            wp_slimstat_admin::show_message(__('Your new settings have been saved.', 'wp-slimstat'), 'info');
        }
    }
}

$index_enabled = wp_slimstat::$wpdb->get_results(
    sprintf("SHOW INDEX FROM %sslim_stats WHERE Key_name = '%sstats_resource_idx'", $GLOBALS[ 'wpdb' ]->prefix, $GLOBALS[ 'wpdb' ]->prefix)
);

$index_names = [
    $GLOBALS[ 'wpdb' ]->prefix . 'stats_resource_idx',
    $GLOBALS[ 'wpdb' ]->prefix . 'stats_browser_idx',
    $GLOBALS[ 'wpdb' ]->prefix . 'stats_searchterms_idx',
    $GLOBALS[ 'wpdb' ]->prefix . 'stats_fingerprint_idx',
];
$missing_indexes = [];
foreach ($index_names as $idx) {
    $exists = wp_slimstat::$wpdb->get_results(sprintf("SHOW INDEX FROM %sslim_stats WHERE Key_name = '%s'", $GLOBALS[ 'wpdb' ]->prefix, $idx));
    if (empty($exists)) {
        $missing_indexes[] = $idx;
    }
}
if ([] !== $missing_indexes) {
    echo '<div class="notice notice-warning"><b>' . esc_html__('Performance Notice:', 'wp-slimstat') . '</b> ' . sprintf(
        esc_html__('The following DB indexes are missing and should be created for optimal performance: %s. Please visit the Slimstat settings or re-activate the plugin to trigger index creation.', 'wp-slimstat'),
        '<code>' . esc_html(implode(', ', $missing_indexes)) . '</code>'
    ) . '</div>';
}

$tabs_html = '';
foreach ($settings as $a_tab_id => $a_tab_info) {
    if (!empty($a_tab_info['rows'])) {
        $tabs_html .= "<li class='nav-tab nav-tab" . (($current_tab == $a_tab_id) ? '-active' : '-inactive') . "'><a href='" . wp_slimstat_admin::$config_url . $a_tab_id . sprintf("'>%s</a></li>", $a_tab_info[ 'title' ]);
    }
}

?>
<div class="backdrop-container">
    <div class="wrap slimstat-config">
        <?php wp_slimstat_admin::get_template('header', ['is_pro' => wp_slimstat::pro_is_installed()]); ?>
        <h2><?php _e('Settings', 'wp-slimstat') ?></h2>
        <ul class="nav-tabs">
            <?php echo $tabs_html ?>
        </ul>

        <div class="notice slimstat-notice slimstat-tooltip-content" style="background-color:#ffa;border:0;padding:10px">
            <?php _e('<strong>AdBlock browser extension detected</strong> - If you see this notice, it means that your browser is not loading our stylesheet and/or Javascript files correctly. This could be caused by an overzealous ad blocker feature enabled in your browser (AdBlock Plus and friends). <a href="https://wp-slimstat.com/resources/the-reports-are-not-being-rendered-correctly-or-buttons-do-not-work" target="_blank">Please make sure to add an exception</a> to your configuration and allow the browser to load these assets.', 'wp-slimstat') ?>
        </div>

        <?php if (!empty($settings[$current_tab]['rows'])) : ?>

            <form action="<?php echo wp_slimstat_admin::$config_url . $current_tab ?>" method="post" id="slimstat-options-<?php echo $current_tab ?>">
                <?php wp_nonce_field('slimstat_update_settings', 'slimstat_update_settings'); ?>
                <?php wp_nonce_field('slimstat_save_settings'); ?>
                <table class="form-table widefat <?php echo $GLOBALS['wp_locale']->text_direction ?>">
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
                $use_code_editor = (('' === $is_readonly || '0' === $is_readonly) && !empty($a_setting_info['use_code_editor'])) ? ' data-code-editor="' . $a_setting_info['use_code_editor'] . '"' : '';

                $network_override_checkbox = is_network_admin() ? '
				<input type="hidden" value="no" name="options[addon_network_settings_' . $a_setting_slug . ']" id="addon_network_settings_' . $a_setting_slug . '">
				<input class="slimstat-checkbox-toggle"
					type="checkbox"
					name="options[addon_network_settings_' . $a_setting_slug . ']"' .
                    ((!empty(wp_slimstat::$settings['addon_network_settings_' . $a_setting_slug]) && 'on' == wp_slimstat::$settings['addon_network_settings_' . $a_setting_slug]) ? ' checked="checked"' : '') . '
					id="addon_network_settings_' . $a_setting_slug . '"
					data-size="mini" data-handle-width="50" data-on-color="warning" data-on-text="Network" data-off-text="Site">' : '';

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

                echo '<tr' . (0 == $i % 2 ? ' class="alternate"' : '') . $conditional_attrs . '>';
                switch ($a_setting_info['type']) {
                    case 'section_header':
                        echo '<td colspan="2" class="slimstat-options-section-header"' . $conditional_attrs . ' id="wp-slimstat-' . sanitize_title($a_setting_info['title']) . '">' . $a_setting_info['title'] . '</td>';
                        break;

                    case 'toggle':
                        echo '<th scope="row"><label for="' . $a_setting_slug . '">' . $a_setting_info['title'] . '</label></th>
					<td>
						<input type="hidden" value="no" name="options[' . $a_setting_slug . ']">
						<span class="block-element">
							<input class="slimstat-checkbox-toggle" type="checkbox"' . $is_readonly . '
								name="options[' . $a_setting_slug . ']"
								id="' . $a_setting_slug . '"
								data-size="mini" data-handle-width="50" data-on-color="success"' .
                            ((!empty(wp_slimstat::$settings[$a_setting_slug]) && 'on' == wp_slimstat::$settings[$a_setting_slug]) ? ' checked="checked"' : '') . '
								data-on-text="' . (empty($a_setting_info['custom_label_on']) ? __('On', 'wp-slimstat') : $a_setting_info['custom_label_on']) . '"
								data-off-text="' . (empty($a_setting_info['custom_label_off']) ? __('Off', 'wp-slimstat') : $a_setting_info['custom_label_off']) . '">' .
                            $network_override_checkbox . '
						</span>
						<span class="description">' . $a_setting_info['description'] . '</span>
					</td>';
                        // ( is_network_admin() ? ' data-indeterminate="true"' : '' ) . '>
                        break;

                    case 'select':
                        echo '<th scope="row"><label for="' . $a_setting_slug . '">' . $a_setting_info['title'] . '</label></th>
					<td>
						<span class="block-element">
							<select' . $is_readonly . ' name="options[' . $a_setting_slug . ']" id="' . $a_setting_slug . '">';
                        foreach ($a_setting_info['select_values'] as $a_key => $a_value) {
                            $is_selected = (!empty(wp_slimstat::$settings[$a_setting_slug]) && wp_slimstat::$settings[$a_setting_slug] == $a_key) ? ' selected' : '';
                            echo '<option' . $is_selected . ' value="' . $a_key . '">' . $a_value . '</option>';
                        }
                        echo '</select> ' . $a_setting_info['after_input_field'] .
                            $network_override_checkbox . '
						</span>
						<span class="description">' . $a_setting_info['description'] . '</span>
					</td>';
                        break;

                    case 'text':
                    case 'integer':
                        $empty_value = ('text' == $a_setting_info['type']) ? '' : '0';
                        echo '<th scope="row"><label for="' . $a_setting_slug . '">' . $a_setting_info['title'] . '</label></th>
					<td>
						<span class="block-element"> ' .
                            $a_setting_info['before_input_field'] . '
							<input class="' . (('integer' == $a_setting_info['type']) ? 'small-text' : 'regular-text') . '"' . $is_readonly . '
								type="' . (('integer' == $a_setting_info['type']) ? 'number' : 'text') . '"
								name="options[' . $a_setting_slug . ']"
								id="' . $a_setting_slug . '"
								value="' . (empty(wp_slimstat::$settings[$a_setting_slug]) ? $empty_value : esc_attr(wp_slimstat::$settings[$a_setting_slug])) . '"> ' . $a_setting_info['after_input_field'] .
                            $network_override_checkbox . '
						</span>
						<span class="description">' . $a_setting_info['description'] . '</span>
					</td>';
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
                        echo '
					<td colspan="2">
						<label for="' . $a_setting_slug . '">' . $a_setting_info['title'] . $network_override_checkbox . '</label>
						<p class="description">' . $a_setting_info['description'] . '</p>
						<p>';
                        wp_editor($editor_content, $a_setting_slug, $editor_settings);
                        echo '
							<span class="description">' . $a_setting_info['after_input_field'] . '</span>
						</p>
					</td>';
                        break;

                    case 'textarea':
                        echo '
					<td colspan="2">
						<label for="' . $a_setting_slug . '">' . $a_setting_info['title'] . $network_override_checkbox . '</label>
						<p class="description">' . $a_setting_info['description'] . '</p>
						<p>
							<textarea class="large-text code' . $use_tag_list . '"' . $is_readonly . $use_code_editor . '
								id="' . $a_setting_slug . '"
								rows="' . ($a_setting_info['rows'] ?? 4) . '"
								name="options[' . $a_setting_slug . ']">' . (empty(wp_slimstat::$settings[$a_setting_slug]) ? '' : stripslashes(wp_slimstat::$settings[$a_setting_slug])) . '</textarea>
							<span class="description">' . $a_setting_info['after_input_field'] . '</span>
						</p>
					</td>';
                        break;

                    case 'plain-text':
                        echo '<th scope="row"><label for="' . $a_setting_slug . '">' . $a_setting_info['title'] . '</label></th>
					<td>
						<span class="block-element">' . $a_setting_info['after_input_field'] . '</span>
						<span class="description">' . $a_setting_info['description'] . '</span>
					</td>';
                        break;

                    case 'custom':
                        echo '<td colspan="2">' . $a_setting_info['title'] . '<br/><br/>' . $a_setting_info['markup'] . '</td>';
                        break;

                    default:
                }
                echo '</tr>';
            }
            ?></tbody>
                </table>

                <p class="submit">
                    <input type="submit" value="<?php _e('Save Changes', 'wp-slimstat') ?>" class="button-primary slimstat-settings-button" name="Submit">
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
        if(!hasWpConsent){ $ci.find('option[value="wp_consent_api"]').prop('disabled', true); }
        // if(!hasRCB){ $ci.find('option[value="real_cookie_banner"]').prop('disabled', true); }

        // Initialize conditional fields system (from admin.js)
        if (typeof window.SlimStatConditionalFields !== 'undefined') {
            window.SlimStatConditionalFields.init();
        }
    });
})(jQuery);
</script>
