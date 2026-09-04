<?php

namespace SlimStat\Tracker;

use SlimStat\Services\Browscap;
use SlimStat\Utils\Consent;

class Ajax
{
    /** Requests one client may make inside RATE_LIMIT_WINDOW before being refused. */
    private const RATE_LIMIT_HITS = 10;

    /** Length of the rate-limiting window, in seconds. */
    private const RATE_LIMIT_WINDOW = 5;

    /** Object-cache group for the counters. Kept out of 'default' so a flush is targeted. */
    private const RATE_LIMIT_GROUP = 'slimstat_rl';

    /**
     * Whether this client has exceeded the tracking rate limit.
     *
     * The counter lives in the object cache, never in the options table. It used to be
     * a transient with a five-second TTL, which is shorter than the gap between most
     * visitors' hits — so the timeout row was normally expired, WordPress deleted both
     * rows and re-inserted them, and the measured cost was 2 to 4 `wp_options` writes
     * on *every tracked hit*. That is several times the write work of storing the
     * pageview, spent to save roughly six queries on the rare request it refuses. (D29)
     *
     * Without a persistent object cache there is nowhere free to keep a cross-request
     * counter, so the limiter stands down rather than charging every site a write per
     * hit for a counter it cannot afford. Two things make that the right trade rather
     * than a silent loss of protection:
     *
     *   - by the time this runs, the request has already paid for the full WordPress
     *     bootstrap, so refusing it saves a fraction of its cost. A cap that matters
     *     belongs at the edge, ahead of PHP;
     *   - `slimstat_rate_limit_enabled` lets a site turn it back on regardless.
     *
     * The counter is keyed on the raw REMOTE_ADDR, unchanged. Behind a CDN or a NAT
     * gateway that is one bucket for everyone behind it, which is a real limitation —
     * `slimstat_rate_limit_key` exists so such a site can supply the client address it
     * trusts. Resolving forwarded headers here by default would let anyone evade the
     * limit by varying a header, which is a worse trade than the one it fixes.
     *
     * @param string $ip Client address, already validated by the caller.
     * @return bool True when the request should be refused.
     */
    /**
     * Whether rate limiting is in effect on this site.
     *
     * Exposed so the Tracker Health endpoint can report it: a protection that turns
     * itself off according to the hosting environment is one a site owner has to be
     * able to see.
     *
     * @param string $ip Client address, when the decision is being made for a request.
     * @return bool
     */
    public static function isRateLimitingActive(string $ip = ''): bool
    {
        /**
         * Filters whether tracking requests are rate limited at all.
         *
         * @param bool   $enabled Defaults to true only when a persistent object cache
         *                        can hold the counter without a database write.
         * @param string $ip      Client address, or '' when queried for reporting.
         */
        // function_exists guarded like every other wp_using_ext_object_cache() call in
        // the plugin: the tracker can run before the full object-cache API is loaded.
        $has_persistent_cache = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();

        return (bool) apply_filters('slimstat_rate_limit_enabled', $has_persistent_cache, $ip);
    }

    public static function isRateLimited(string $ip): bool
    {
        if (!self::isRateLimitingActive($ip)) {
            return false;
        }

        /**
         * Filters the identity a rate-limit budget is tracked against.
         *
         * @param string $ip Client address as seen by PHP.
         */
        $key = 'rl_' . md5((string) apply_filters('slimstat_rate_limit_key', $ip));

        $hits = wp_cache_incr($key, 1, self::RATE_LIMIT_GROUP);
        if (false === $hits) {
            // No counter yet, or the window just expired. `add` rather than `set` so
            // two concurrent requests cannot each reset the other's window; the loser
            // undercounts by one, which a rate limiter can afford.
            wp_cache_add($key, 1, self::RATE_LIMIT_GROUP, self::RATE_LIMIT_WINDOW);
            $hits = 1;
        }

        return $hits > self::RATE_LIMIT_HITS;
    }

    /**
     * Validate click position as strict "x,y" format with 1-5 digit coordinates.
     *
     * Rejects any value that does not match after whitespace trimming.
     * No character stripping — tampered payloads are rejected outright
     * so GDPR exports never contain repaired/synthetic coordinates.
     *
     * @param mixed $raw Raw position value from client.
     * @return string Validated "x,y" or empty string if invalid.
     */
    public static function sanitizePosition($raw): string
    {
        if (!is_string($raw)) {
            return '';
        }
        $position = trim($raw);
        if ($position !== '' && !preg_match('/^\d{1,5},\d{1,5}$/', $position)) {
            return '';
        }
        return $position;
    }

    /**
     * Validate and sanitize a base64url-encoded referer from the JS tracker payload.
     *
     * @internal Extracted from handle() (#306) to provide a unit-testable seam.
     *
     * Uses sanitize_url() with `android-app` added to the allow-list
     * (Processor::REFERER_ALLOWED_SCHEMES) rather than the default wp_allowed_protocols():
     *   - app-scheme referers such as `android-app://com.google.android.googlequicksearchbox/`
     *     (Google Discover) survive — the original #306 bug was the default list emptying them;
     *   - disallowed schemes (javascript:, data:) are emptied here, at the boundary, so they can
     *     never reach storage even on the follow-up-event path that skips Processor::process();
     *   - unlike sanitize_text_field, percent-encoded query octets (%XX) are preserved, so
     *     getSearchTerms() can still decode non-Latin / spaced search terms downstream.
     * The host-format check below and the post-storage scheme check in Processor::process()
     * remain as defense in depth.
     *
     * @param mixed $rawEncoded Raw base64url ref value from the client payload.
     * @return string|false Sanitized referer (possibly empty), or false when the referer is
     *                      malformed and the whole request must be rejected.
     */
    public static function sanitizeReferer($rawEncoded)
    {
        $referer    = Utils::base64UrlDecode($rawEncoded);
        $parsed_ref = parse_url($referer ?: '');

        // Security: Validate referer format
        if (false === $parsed_ref) {
            return false;
        }

        // Security: Validate host (if present) - allow external domains for referer,
        // but validate the host format to prevent injection. Accept either a DNS
        // hostname or a bracketed IPv6 literal (parse_url keeps the brackets, e.g.
        // "[2001:db8::1]"); otherwise a valid IPv6 referer would fail the check and
        // drop the entire hit. filter_var validates the IPv6 structure (same
        // FILTER_FLAG_IPV6 pattern Utils uses) and runs only when the host is not a
        // plain hostname.
        if (!empty($parsed_ref['host'])) {
            $host        = $parsed_ref['host'];
            $is_hostname = (bool) preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $host);
            $is_ipv6     = !$is_hostname
                && $host[0] === '[' && substr($host, -1) === ']'
                && false !== filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
            if (!$is_hostname && !$is_ipv6) {
                return false;
            }
        }

        // Security: Limit referer length to prevent DoS
        if (strlen($referer) > 2048) {
            $referer = substr($referer, 0, 2048);
        }

        return sanitize_url($referer, Processor::REFERER_ALLOWED_SCHEMES);
    }

    /**
     * Handle AJAX tracking request with exit (for admin-ajax.php).
     * This wrapper calls process() and exits with the result.
     */
    public static function handle()
    {
        $result = self::process();
        Utils::sendTrackingHeaders('ajax', $result);
        echo $result;
        exit;
    }

    /**
     * Process tracking request and return result (for REST API and other contexts).
     * Returns the tracking result without calling exit().
     *
     * @return string|int The tracking result (record ID with checksum, error code, or 0)
     */
    public static function process()
    {
        // Tracking-disabled is checked first: it is an array read, so a site with
        // tracking off short-circuits without touching the object cache at all.
        if ('on' != \wp_slimstat::$settings['is_tracking']) {
            return Utils::logError(204);
        }

        $remote_ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        if (!empty($remote_ip) && self::isRateLimited($remote_ip)) {
            return Utils::logError(429);
        }

        $id = 0;

        // Use setter with validation
        \wp_slimstat::set_data_js(apply_filters('slimstat_filter_pageview_data_js', \wp_slimstat::$raw_post_array));
        $data_js   = \wp_slimstat::get_data_js();
        $stat      = \wp_slimstat::get_stat();

        $site_host = parse_url(get_site_url(), PHP_URL_HOST);
        $home_host = parse_url(home_url(), PHP_URL_HOST);
        $http_host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $allowed_hosts = array_filter([$site_host, $home_host, $http_host]);
        $normalize_host = static function ($host) {
            $host = strtolower((string) $host);
            $host = preg_replace('/:\\d+$/', '', $host);
            if (0 === strpos($host, 'www.')) {
                $host = substr($host, 4);
            }
            return $host;
        };
        $allowed_hosts = array_unique(array_map($normalize_host, $allowed_hosts));
        $is_allowed_host = static function ($host) use ($allowed_hosts, $normalize_host) {
            if (empty($host)) {
                return false;
            }
            return in_array($normalize_host($host), $allowed_hosts, true);
        };

        // Check if this is a consent upgrade request (needed for IP processing and later checks)
        $isConsentUpgrade = !empty($data_js['consent_upgrade']) && '1' === $data_js['consent_upgrade'];

        // GDPR Compliance: Ensure IP is always fresh from $_SERVER for navigation requests
        // In anonymous mode, get_stat() may contain a hashed IP from previous requests
        // We need to get the real IP from $_SERVER and then process it according to consent
        [$stat['ip'], $stat['other_ip']] = Utils::getRemoteIp();

        // Security: Validate and sanitize referer URL
        $stat['referer'] = '';
        if (!empty($data_js['ref'])) {
            $referer = self::sanitizeReferer($data_js['ref']);
            if (false === $referer) {
                // Invalid referer format - reject request
                return Utils::logError(201);
            }
            $stat['referer'] = $referer;
        }

        // Update stat after referer processing
        \wp_slimstat::set_stat($stat);

        if (!empty($data_js['id'])) {
            // Defense-in-depth: check bot status even for follow-up AJAX events.
            // The initial pageview (id=empty) goes through Processor::process() which
            // has the full bot check, but follow-up events skip Processor entirely.
            // This ensures bots executing JS are still blocked on updates. See #291.
            if ('on' == \wp_slimstat::$settings['ignore_bots']) {
                $browser = Browscap::get_browser();
                if (1 == $browser['browser_type']) {
                    return Utils::logError(313);
                }
            }

            $data_js['id'] = Utils::getValueWithoutChecksum($data_js['id']);
            if (false === $data_js['id']) {
                return Utils::logError(101);
            }

            $stat['id'] = intval($data_js['id']);
            if ($stat['id'] < 0) {
                do_action('slimstat_track_exit_' . abs($stat['id']));
                return Utils::getValueWithChecksum($stat['id']);
            }

            // Process IP according to consent status (cookie set only by consent upgrade handler)
            // $isConsentUpgrade already defined above
            // Pass explicit consent flag if this is a consent upgrade request
            $stat = \SlimStat\Providers\IPHashProvider::processIp($stat, $isConsentUpgrade);

            if (Consent::piiAllowed($isConsentUpgrade)) {
                if (!empty($GLOBALS['current_user']->ID)) {
                    $stat['username'] = $GLOBALS['current_user']->data->user_login;
                    $stat['email']    = $GLOBALS['current_user']->data->user_email;
                    $stat['notes'][]  = 'user:' . $GLOBALS['current_user']->data->ID;
                } elseif (isset($_COOKIE['comment_author_' . COOKIEHASH])) {
                    if (!empty($_COOKIE['comment_author_' . COOKIEHASH])) {
                        $stat['username'] = sanitize_user($_COOKIE['comment_author_' . COOKIEHASH]);
                    }

                    if (!empty($_COOKIE['comment_author_email_' . COOKIEHASH])) {
                        $stat['email'] = sanitize_email($_COOKIE['comment_author_email_' . COOKIEHASH]);
                    }
                }
            }

            if (empty($data_js['pos'])) {
                // Security: Validate and sanitize resource URL from JavaScript data
                // This ensures we track the correct page for navigation requests while preventing injection attacks
                if (!empty($data_js['res'])) {
                    $resource = Utils::base64UrlDecode($data_js['res']);
                    $parsed_resource = parse_url($resource ?: '');

                    // Security: Validate host is from current site domain
                    $site_host = parse_url(get_site_url(), PHP_URL_HOST);
                    if (false !== $parsed_resource && !empty($parsed_resource['host'])) {
                        // Security: Whitelist validation - only allow current site domain
                        if (!$is_allowed_host($parsed_resource['host'])) {
                            // Invalid host - reject request
                            return Utils::logError(203);
                        }

                        // Security: Validate path format (prevent path traversal attacks)
                        $path = !empty($parsed_resource['path']) ? $parsed_resource['path'] : '/';
                        // Remove any path traversal attempts
                        $path = str_replace(['../', '..\\', '%2e%2e', '%2E%2E'], '', $path);
                        // Validate path contains only safe characters
                        if (!preg_match('#^[/\w\-\.~!*\'();:@&=+$,?#\[\]%]*$#', $path)) {
                            // Invalid path format - reject request
                            return Utils::logError(203);
                        }

                        // Extract path from resource URL
                        $stat['resource'] = $path . (empty($parsed_resource['query']) ? '' : '?' . $parsed_resource['query']);
                        $stat['resource'] = sanitize_text_field(urldecode($stat['resource']));
                        $stat['resource'] = preg_replace_callback('/[^\x20-\x7E]/', function ($m) {
                            return '%' . bin2hex($m[0]);
                        }, $stat['resource']);

                        // Security: Limit resource length to prevent DoS
                        if (strlen($stat['resource']) > 2048) {
                            $stat['resource'] = substr($stat['resource'], 0, 2048);
                        }
                    }
                }

                // Update path: if no explicit resource was provided by JS, do NOT fall back to
                // REQUEST_URI. REQUEST_URI here is the tracking endpoint itself
                // (/wp-json/slimstat/v1/hit or /wp-admin/admin-ajax.php), not the page the
                // visitor is on. Unsetting ensures Storage::updateRow()'s array_filter() omits
                // the resource column so the DB value set on the initial pageview is preserved.
                if (empty($stat['resource'])) {
                    unset($stat['resource']);
                }

                // Client info BEFORE ensureVisitId — this order is load-bearing (D68
                // mechanism c). The other way round, the anonymous branch derived the
                // identity without the fingerprint, fell to the weaker IP+UA formula,
                // produced a different id for the same person, and updateRow() then
                // rewrote the original row's visit_id with it.
                $stat = Utils::getClientInfo($data_js, $stat);

                // Sync local stat (including id from client) to global before ensureVisitId,
                // which calls get_stat()/set_stat() internally and would lose the id otherwise.
                // See: https://github.com/wp-slimstat/wp-slimstat/issues/242
                \wp_slimstat::set_stat($stat);

                // Security: Ensure visit ID is generated successfully
                $visitIdAssigned = Session::ensureVisitId(true);
                $stat = \wp_slimstat::get_stat();

                // Security: Validate visit_id exists - return error if generation failed
                if (empty($stat['visit_id']) || $stat['visit_id'] <= 0) {
                    return Utils::logError(500);
                }

                if (empty($stat['resolution'])) {
                    $stat['dt_out'] = \wp_slimstat::date_i18n('U');
                }

                if (!empty($stat['fingerprint']) && Utils::isNewVisitor($stat['fingerprint'])) {
                    $stat['notes'] = ['new:yes'];
                }

                // Update stat before storage
                \wp_slimstat::set_stat($stat);

                // GDPR Compliance: Duplicate check for anonymous mode (same as Processor.php)
                // In Anonymous Tracking Mode without PII, simulate normal session behavior
                // This prevents duplicate records from page refreshes while still allowing:
                // - New visits to different pages (different resource)
                // - New sessions after session_duration expires
                // - New visits from different browsers/devices (different visit_id)
                $isAnonymousTracking = ('on' === (\wp_slimstat::$settings['anonymous_tracking'] ?? 'off'));
                $piiAllowed = Consent::piiAllowed();

                if ($isAnonymousTracking && !$piiAllowed && !empty($stat['visit_id']) && !empty($stat['resource'])) {
                    $session_duration = !empty(\wp_slimstat::$settings['session_duration']) ? intval(\wp_slimstat::$settings['session_duration']) : 1800;
                    $table = $GLOBALS['wpdb']->prefix . 'slim_stats';
                    $min_timestamp = $stat['dt'] - $session_duration;

                    $GLOBALS['wpdb']->query('START TRANSACTION');

                    try {
                        $fingerprint_check = '';
                        $fingerprint_value = null;
                        if (!empty($stat['fingerprint'])) {
                            $fingerprint_check = ' AND fingerprint = %s';
                            $fingerprint_value = $stat['fingerprint'];
                        }
                        $sql = "SELECT id, dt FROM {$table}
                                WHERE visit_id = %d
                                AND resource = %s
                                AND dt >= %d
                                AND dt <= %d
                                {$fingerprint_check}
                                ORDER BY dt DESC
                                LIMIT 1
                                FOR UPDATE";

                        $prepare_args = [
                            $stat['visit_id'],
                            $stat['resource'],
                            $min_timestamp,
                            $stat['dt']
                        ];

                        if ($fingerprint_value !== null) {
                            $prepare_args[] = $fingerprint_value;
                        }

                        $existing_record = $GLOBALS['wpdb']->get_row(
                            $GLOBALS['wpdb']->prepare($sql, ...$prepare_args),
                            OBJECT
                        );

                        if (!empty($existing_record)) {
                            $stat['id'] = intval($existing_record->id);
                            \wp_slimstat::set_stat($stat);
                            $GLOBALS['wpdb']->query('COMMIT');
                            return Utils::getValueWithChecksum($stat['id']);
                        }

                        $GLOBALS['wpdb']->query('COMMIT');
                    } catch (\Exception $e) {
                        // Rollback on error
                        $GLOBALS['wpdb']->query('ROLLBACK');
                    }
                }

                $id = Storage::updateRow($stat)->id();
            } else {
                // Security: Validate and sanitize event position (x,y coordinates)
                $position = self::sanitizePosition($data_js['pos'] ?? '');

                $event_info = [
                    // Defense-in-depth: sanitizePosition already guarantees digit-comma-digit
                    'position' => sanitize_text_field($position),
                    'id'       => $stat['id'],
                    'dt'       => \wp_slimstat::date_i18n('U'),
                ];

                // Security: Validate and sanitize event notes
                if (!empty($data_js['no'])) {
                    $notes = Utils::base64UrlDecode($data_js['no']);
                    // Security: Limit notes length
                    if (strlen($notes) > 256) {
                        $notes = substr($notes, 0, 256);
                    }
                    $event_info['notes'] = sanitize_text_field($notes);
                }

                $shouldEventBeTracked = apply_filters('slimstat_track_event_enabled', true, $event_info);
                if ($shouldEventBeTracked) {
                    // C30's sharpest edge lands here: slim_events carries a FOREIGN KEY onto
                    // slim_stats, so a pageview id of 0 made this insert fail — and the
                    // failure was discarded, so the event vanished with no trace anywhere.
                    $eventWrite = Storage::insertRow($event_info, $GLOBALS['wpdb']->prefix . 'slim_events');

                    // NOT isFailed(): under INSERT IGNORE an FK refusal is downgraded to a
                    // warning — rows_affected 0, last_error empty — so it arrives as IGNORED,
                    // not FAILED. Asking "did it fail" would have missed the exact case this
                    // guard exists for, which is a pageview id that no longer references a row.
                    if (!$eventWrite->isStored()) {
                        \wp_slimstat::record_degradation(
                            'event insert stored no row',
                            $eventWrite->error() ?: 'no matching pageview (foreign key)',
                            \wp_slimstat::DEGRADATION_OPERATIONAL
                        );
                    }
                }

                if (!empty($data_js['res'])) {
                    $resource        = Utils::base64UrlDecode($data_js['res']);
                    $parsed_resource = parse_url($resource ?: '');
                    if (false === $parsed_resource || empty($parsed_resource['host'])) {
                        return Utils::logError(203);
                    }

                    if (!empty($parsed_resource['path']) && in_array(pathinfo($parsed_resource['path'], PATHINFO_EXTENSION), \wp_slimstat::string_to_array(\wp_slimstat::$settings['extensions_to_track']))) {
                        $stat['resource']     = $parsed_resource['path'] . (empty($parsed_resource['query']) ? '' : '?' . $parsed_resource['query']);
                        $stat['content_type'] = 'download';
                        // Security: Validate and sanitize fingerprint
                        if (!empty($data_js['fh'])) {
                            $fingerprint = $data_js['fh'];
                            // Security: Validate fingerprint format (alphanumeric, dash, underscore only)
                            $fingerprint = preg_replace('/[^a-zA-Z0-9\-_]/', '', $fingerprint);
                            // Security: Limit fingerprint length
                            if (strlen($fingerprint) > 256) {
                                $fingerprint = substr($fingerprint, 0, 256);
                            }
                            $stat['fingerprint'] = sanitize_text_field($fingerprint);
                        }

                        // Update stat before processing
                        \wp_slimstat::set_stat($stat);
                        $id = Processor::process();
                    } elseif (!$is_allowed_host($parsed_resource['host'])) {
                        $sanitized_url = sanitize_url($resource);
                        $stat['outbound_resource'] = !empty($sanitized_url) ? $sanitized_url : '';
                        $stat['dt_out']             = \wp_slimstat::date_i18n('U');

                        // Update stat before storage
                        \wp_slimstat::set_stat($stat);
                        $id = Storage::updateRow($stat)->id();
                    }
                } else {
                    $stat['dt_out'] = \wp_slimstat::date_i18n('U');

                    // Update stat before storage
                    \wp_slimstat::set_stat($stat);
                    $id = Storage::updateRow($stat)->id();
                }
            }
        } else {
            $stat['resource'] = '';
            if (!empty($data_js['res'])) {
                $stat['resource'] = Utils::base64UrlDecode($data_js['res']);
                if (false === parse_url($stat['resource'] ?: '')) {
                    return Utils::logError(203);
                }
            }

            $stat = Utils::getClientInfo($data_js, $stat);
            if (!empty($data_js['ci'])) {
                $validated_ci = Utils::getValueWithoutChecksum($data_js['ci']);
                if (false === $validated_ci) {
                    Utils::logWarning(102);
                    $data_js['ci'] = '';
                } else {
                    $data_js['ci'] = $validated_ci;
                }
            }

            if (!empty($data_js['ci'])) {
                $decoded_ci = Utils::base64UrlDecode($data_js['ci']);
                $content_info = json_decode($decoded_ci, true);
                // Security: Only accept JSON-encoded content info, reject serialized data.
                // If the payload is stale or malformed, continue without trusting its metadata.
                if (empty($content_info) || !is_array($content_info)) {
                    Utils::logWarning(103);
                    $stat['content_type'] = 'external';
                } else {
                    foreach (['content_type', 'category', 'content_id', 'author'] as $a_key) {
                        if (!empty($content_info[$a_key]) && 'content_id' !== $a_key) {
                            $stat[$a_key] = sanitize_text_field($content_info[$a_key]);
                        } elseif (!empty($content_info[$a_key])) {
                            $stat[$a_key] = absint($content_info[$a_key]);
                        }
                    }
                }
            } else {
                $stat['content_type'] = 'external';
            }

            if (!empty($stat['fingerprint']) && Utils::isNewVisitor($stat['fingerprint'])) {
                $stat['notes'] = ['new:yes'];
            }

            // consent_upgrade already checked above, reuse the variable
            if ($isConsentUpgrade) {
                // Pass consent_upgrade flag to Processor via data_js
                // Processor will handle the upgrade logic
            }

            // Update stat before processing
            \wp_slimstat::set_stat($stat);
            $id = Processor::process();
        }

        $isErrorCode = is_int($id) && $id < 0;
        if (empty($id) || $isErrorCode) {
            return $isErrorCode ? $id : 0;
        }

        do_action('slimstat_track_success');
        return Utils::getValueWithChecksum($id);
    }
}
