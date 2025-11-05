<?php

namespace SlimStat\Tracker;

use SlimStat\Utils\Consent;

class Ajax
{
    public static function handle()
    {
        $remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!empty($remote_ip)) {
            $key        = 'slimstat_rl_' . md5($remote_ip);
            $hits_in_5s = (int) get_transient($key);
            if ($hits_in_5s >= 10) {
                exit(Utils::logError(429));
            }

            set_transient($key, $hits_in_5s + 1, 5);
        }

        if ('on' != \wp_slimstat::$settings['is_tracking']) {
            exit(Utils::logError(204));
        }

        $id = 0;

        // Use setter with validation
        \wp_slimstat::set_data_js(apply_filters('slimstat_filter_pageview_data_js', \wp_slimstat::$raw_post_array));
        $data_js   = \wp_slimstat::get_data_js();
        $stat      = \wp_slimstat::get_stat();
        $site_host = parse_url(get_site_url(), PHP_URL_HOST);

        // GDPR Compliance: Ensure IP is always fresh from $_SERVER for navigation requests
        // In anonymous mode, get_stat() may contain a hashed IP from previous requests
        // We need to get the real IP from $_SERVER and then process it according to consent
        [$stat['ip'], $stat['other_ip']] = Utils::getRemoteIp();

        // Security: Validate and sanitize referer URL
        $stat['referer'] = '';
        if (!empty($data_js['ref'])) {
            $referer = Utils::base64UrlDecode($data_js['ref']);
            $parsed_ref = parse_url($referer);

            // Security: Validate referer format
            if (false === $parsed_ref) {
                // Invalid referer format - reject request
                exit(Utils::logError(201));
            }

            // Security: Validate host (if present) - allow external domains for referer
            // Referer can be from external sites, but we should validate the format
            if (!empty($parsed_ref['host'])) {
                // Validate host format (prevent injection)
                if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $parsed_ref['host'])) {
                    // Invalid host format - reject request
                    exit(Utils::logError(201));
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
                exit(Utils::logError(101));
            }

            $stat['id'] = intval($data_js['id']);
            if ($stat['id'] < 0) {
                do_action('slimstat_track_exit_' . abs($stat['id']));
                exit(Utils::getValueWithChecksum($stat['id']));
            }

            // GDPR Compliance: Process IP according to consent status
            // For navigation requests, we need to ensure IP is processed correctly based on current consent
            //
            // IMPORTANT: In anonymous mode, we NEVER set tracking cookie here in navigation requests.
            // The tracking cookie should ONLY be set by the consent upgrade AJAX handler (ConsentHandler::handleConsentGranted())
            // when the user explicitly grants consent. This ensures GDPR compliance.
            //
            // If consent was granted, the cookie will already exist from the consent upgrade handler.
            // If consent was NOT granted, we should NOT set the cookie here, as that would bypass consent.

            // Process IP according to consent status
            // In anonymous mode, processIp() will:
            // - Hash IP if consent not granted (piiAllowed() returns false)
            // - Keep real IP if consent granted (piiAllowed() returns true)
            // This ensures IP is always processed correctly based on current consent status
            //
            // DEBUG: Log consent status for troubleshooting (only in debug mode)
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $isAnonymousTracking = 'on' === (\wp_slimstat::$settings['anonymous_tracking'] ?? 'off');
                $integrationKey = \wp_slimstat::$settings['consent_integration'] ?? '';
                $piiAllowedBefore = Consent::piiAllowed();
                $hasCookie = isset($_COOKIE['slimstat_tracking_code']);
                if ($isAnonymousTracking && 'wp_consent_api' === $integrationKey && function_exists('wp_has_consent')) {
                    $wpConsentCategory = (string) (\wp_slimstat::$settings['consent_level_integration'] ?? 'statistics');
                    $hasCmpConsent = false;
                    try {
                        $hasCmpConsent = (bool) \wp_has_consent($wpConsentCategory);
                    } catch (\Throwable $e) {
                        // Ignore
                    }
                    error_log(sprintf(
                        'SlimStat Ajax: anonymous=%s, piiAllowed=%s, hasCookie=%s, hasCmpConsent=%s, IP=%s',
                        $isAnonymousTracking ? 'yes' : 'no',
                        $piiAllowedBefore ? 'yes' : 'no',
                        $hasCookie ? 'yes' : 'no',
                        $hasCmpConsent ? 'yes' : 'no',
                        $stat['ip'] ?? 'N/A'
                    ));
                }
            }

            $stat = \SlimStat\Providers\IPHashProvider::processIp($stat);

            // DEBUG: Log IP after processing (only in debug mode)
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $isAnonymousTracking = 'on' === (\wp_slimstat::$settings['anonymous_tracking'] ?? 'off');
                if ($isAnonymousTracking) {
                    $isHashed = !empty($stat['ip']) && strlen($stat['ip']) === 64 && ctype_xdigit($stat['ip']);
                    error_log(sprintf(
                        'SlimStat Ajax: IP after processIp=%s, isHashed=%s',
                        $stat['ip'] ?? 'N/A',
                        $isHashed ? 'yes' : 'no'
                    ));
                }
            }
            if (Consent::piiAllowed(true)) {
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
                    $parsed_resource = parse_url($resource);

                    // Security: Validate host is from current site domain
                    $site_host = parse_url(get_site_url(), PHP_URL_HOST);
                    if (false !== $parsed_resource && !empty($parsed_resource['host'])) {
                        // Security: Whitelist validation - only allow current site domain
                        if ($parsed_resource['host'] !== $site_host) {
                            // Invalid host - reject request
                            exit(Utils::logError(203));
                        }

                        // Security: Validate path format (prevent path traversal attacks)
                        $path = !empty($parsed_resource['path']) ? $parsed_resource['path'] : '/';
                        // Remove any path traversal attempts
                        $path = str_replace(['../', '..\\', '%2e%2e', '%2E%2E'], '', $path);
                        // Validate path contains only safe characters
                        if (!preg_match('#^[/\w\-\.~!*\'();:@&=+$,?#\[\]%]*$#', $path)) {
                            // Invalid path format - reject request
                            exit(Utils::logError(203));
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

                // If resource not set, use default from get_stat()
                if (empty($stat['resource'])) {
                    $stat['resource'] = \wp_slimstat::get_request_uri();
                }

                // Security: Ensure visit ID is generated successfully
                $visitIdAssigned = Session::ensureVisitId(true);
                $stat = \wp_slimstat::get_stat();

                // Security: Validate visit_id exists - exit if generation failed
                if (empty($stat['visit_id']) || $stat['visit_id'] <= 0) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('SlimStat Ajax: Failed to generate visit_id');
                    }
                    exit(Utils::logError(500));
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

                // Only perform duplicate check if:
                // 1. Anonymous tracking mode is enabled
                // 2. PII is NOT allowed (no cookies available)
                // 3. Visit ID and resource are available
                // This ensures we only check duplicates when cookies aren't available (GDPR-compliant mode)
                if ($isAnonymousTracking && !$piiAllowed && !empty($stat['visit_id']) && !empty($stat['resource'])) {
                    $session_duration = !empty(\wp_slimstat::$settings['session_duration']) ? intval(\wp_slimstat::$settings['session_duration']) : 1800;
                    $table = $GLOBALS['wpdb']->prefix . 'slim_stats';
                    $min_timestamp = $stat['dt'] - $session_duration;

                    // Security: Race Condition Prevention - Use transaction with row-level locking
                    // This prevents duplicate records when two requests arrive simultaneously
                    $GLOBALS['wpdb']->query('START TRANSACTION');

                    try {
                        // Build query with fingerprint check if available
                        // This ensures the same user doesn't get duplicate records when navigating between pages
                        $fingerprint_check = '';
                        $fingerprint_value = null;
                        if (!empty($stat['fingerprint'])) {
                            $fingerprint_check = ' AND fingerprint = %s';
                            $fingerprint_value = $stat['fingerprint'];
                        }

                        // Use SELECT ... FOR UPDATE to lock the row and prevent race conditions
                        // This ensures only one request can check/insert at a time
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
                            // Duplicate found within session - use existing record ID
                            // This matches normal behavior where cookies prevent duplicate pageviews
                            // Note: This only runs when cookies aren't available (GDPR-compliant mode)
                            $stat['id'] = intval($existing_record->id);
                            \wp_slimstat::set_stat($stat);
                            $GLOBALS['wpdb']->query('COMMIT');
                            // Return existing record ID (don't create new record)
                            exit(Utils::getValueWithChecksum($stat['id']));
                        }

                        // No duplicate found - commit transaction and continue
                        $GLOBALS['wpdb']->query('COMMIT');
                    } catch (\Exception $e) {
                        // Rollback on error
                        $GLOBALS['wpdb']->query('ROLLBACK');
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('SlimStat Ajax: Duplicate check transaction error - ' . $e->getMessage());
                        }
                        // Continue without duplicate check (fail open for availability)
                    }
                }

                $id = Storage::updateRow($stat);
            } else {
                // Security: Validate and sanitize event data
                $position = !empty($data_js['pos']) ? $data_js['pos'] : '';
                // Security: Validate position format (alphanumeric, dash, underscore only)
                $position = preg_replace('/[^a-zA-Z0-9\-_]/', '', $position);
                // Security: Limit position length
                if (strlen($position) > 32) {
                    $position = substr($position, 0, 32);
                }

                $event_info = [
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
                    $parsed_resource = parse_url($resource);
                    if (false === $parsed_resource || empty($parsed_resource['host'])) {
                        exit(Utils::logError(203));
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
                    } elseif ($parsed_resource['host'] != $site_host) {
                        $stat['outbound_resource'] = $resource;
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
                if (false === parse_url($stat['resource'])) {
                    exit(Utils::logError(203));
                }
            }

            $stat = Utils::getClientInfo($data_js, $stat);
            if (!empty($data_js['ci'])) {
                $data_js['ci'] = Utils::getValueWithoutChecksum($data_js['ci']);
                if (false === $data_js['ci']) {
                    exit(Utils::logError(102));
                }

                $content_info = @unserialize(Utils::base64UrlDecode($data_js['ci']));
                if (empty($content_info) || !is_array($content_info)) {
                    exit(Utils::logError(103));
                }

                foreach (['content_type', 'category', 'content_id', 'author'] as $a_key) {
                    if (!empty($content_info[$a_key]) && 'content_id' !== $a_key) {
                        $stat[$a_key] = sanitize_text_field($content_info[$a_key]);
                    } elseif (!empty($content_info[$a_key])) {
                        $stat[$a_key] = absint($content_info[$a_key]);
                    }
                }
            } else {
                $stat['content_type'] = 'external';
            }

            if (!empty($stat['fingerprint']) && Utils::isNewVisitor($stat['fingerprint'])) {
                $stat['notes'] = ['new:yes'];
            }

            // Update stat before processing
            \wp_slimstat::set_stat($stat);
            $id = Processor::process();
        }

        if (empty($id)) {
            exit(0);
        }

        do_action('slimstat_track_success');
        exit(Utils::getValueWithChecksum($id));
    }
}
