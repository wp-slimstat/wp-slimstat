<?php

namespace SlimStat\Tracker;

use SlimStat\Utils\Consent;

class Ajax
{
    /**
     * Sanitize click position to "x,y" format with 1-5 digit coordinates.
     *
     * @param mixed $raw Raw position value from client.
     * @return string Sanitized "x,y" or empty string if invalid.
     */
    public static function sanitizePosition($raw): string
    {
        if (!is_string($raw)) {
            return '';
        }
        $position = preg_replace('/[^0-9,]/', '', $raw);
        if (!empty($position) && !preg_match('/^\d{1,5},\d{1,5}$/', $position)) {
            $position = '';
        }
        return $position;
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
        $remote_ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        if (!empty($remote_ip)) {
            $key        = 'slimstat_rl_' . md5($remote_ip);
            $hits_in_5s = (int) get_transient($key);
            if ($hits_in_5s >= 10) {
                return Utils::logError(429);
            }

            set_transient($key, $hits_in_5s + 1, 5);
        }

        if ('on' != \wp_slimstat::$settings['is_tracking']) {
            return Utils::logError(204);
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
            $referer = Utils::base64UrlDecode($data_js['ref']);
            $parsed_ref = parse_url($referer ?: '');

            // Security: Validate referer format
            if (false === $parsed_ref) {
                // Invalid referer format - reject request
                return Utils::logError(201);
            }

            // Security: Validate host (if present) - allow external domains for referer
            // Referer can be from external sites, but we should validate the format
            if (!empty($parsed_ref['host'])) {
                // Validate host format (prevent injection)
                if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $parsed_ref['host'])) {
                    // Invalid host format - reject request
                    return Utils::logError(201);
                }
            }

            // Security: Limit referer length to prevent DoS
            if (strlen($referer) > 2048) {
                $referer = substr($referer, 0, 2048);
            }

            $stat['referer'] = sanitize_url($referer);
        }

        // Update stat after referer processing
        \wp_slimstat::set_stat($stat);

        if (!empty($data_js['id'])) {
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

                $stat = Utils::getClientInfo($data_js, $stat);

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

                $id = Storage::updateRow($stat);
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
                    Storage::insertRow($event_info, $GLOBALS['wpdb']->prefix . 'slim_events');
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
                        $id = Storage::updateRow($stat);
                    }
                } else {
                    $stat['dt_out'] = \wp_slimstat::date_i18n('U');

                    // Update stat before storage
                    \wp_slimstat::set_stat($stat);
                    $id = Storage::updateRow($stat);
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
