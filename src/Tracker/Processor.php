<?php

namespace SlimStat\Tracker;

use SlimStat\Utils\Query;
use SlimStat\Services\Browscap;
use SlimStat\Services\Privacy;
use SlimStat\Services\Geolocation\GeolocationService;
use SlimStat\Providers\IPHashProvider;
use SlimStat\Utils\Consent;

class Processor
{
    /**
     * Check if the current WordPress user should be excluded from tracking.
     *
     * Uses wp_get_current_user() defensively to ensure the user object is fully
     * resolved, even in edge-case environments where $GLOBALS['current_user']
     * may not be initialized by the 'wp' hook (e.g., object caching plugins,
     * multisite, or custom authentication flows).
     *
     * @since 5.4.5
     * @return bool True if the current user should be excluded.
     */
    public static function isUserExcluded(): bool
    {
        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;

        if (empty($user) || empty($user->ID)) {
            return false;
        }

        // Check "Exclude all WP Users" toggle
        if ('on' == \wp_slimstat::$settings['ignore_wp_users']) {
            return true;
        }

        // Check role/capability blacklist — matches both role slugs (e.g. "editor")
        // and capability keys (e.g. "manage_options", "edit_posts") so admins can
        // exclude users by either mechanism via the ignore_capabilities setting.
        $capSetting = \wp_slimstat::$settings['ignore_capabilities'] ?? '';
        if (!empty($capSetting)) {
            // Check role slugs first
            if (!empty($user->roles)) {
                foreach ($user->roles as $role) {
                    if (Utils::isBlacklisted($role, $capSetting)) {
                        return true;
                    }
                }
            }

            // Check individual capability keys (e.g. manage_options, edit_posts)
            if (!empty($user->allcaps) && is_array($user->allcaps)) {
                foreach ($user->allcaps as $cap => $granted) {
                    if ($granted && Utils::isBlacklisted($cap, $capSetting)) {
                        return true;
                    }
                }
            }
        }

        // Check username blacklist
        if (!empty(\wp_slimstat::$settings['ignore_users'])
            && !empty($user->data->user_login)
            && Utils::isBlacklisted($user->data->user_login, \wp_slimstat::$settings['ignore_users'])) {
            return true;
        }

        return false;
    }

    public static function process()
    {
        // Consent gate: delegate to external CMPs via filter; SlimStat does not manage consent.
        if (!Consent::canTrack()) {
            return false;
        }

        // Get current stat with validation
        $stat = \wp_slimstat::get_stat();

        $stat['dt'] = \wp_slimstat::date_i18n('U');
        if (empty($stat['notes'])) {
            $stat['notes'] = [];
        }

        // Set processing timestamp for Query builder caching (avoids race conditions at midnight)
        Query::setProcessingTimestamp($stat['dt']);

        $stat = apply_filters('slimstat_filter_pageview_stat_init', $stat);
        if ($stat === [] || empty($stat['dt'])) {
            // Clear processing timestamp context if processing is aborted
            Query::setProcessingTimestamp(null);
            return false;
        }

        unset($stat['id']);

        // Remove legacy cookie-based opt-in/opt-out handling. CMPs should control tracking via hooks.

        [$stat['ip'], $stat['other_ip']] = Utils::getRemoteIp();
        if (empty($stat['ip']) || '0.0.0.0' == $stat['ip']) {
            Query::setProcessingTimestamp(null);
            Utils::logError(202);
            return false;
        }

        foreach (\wp_slimstat::string_to_array(\wp_slimstat::$settings['ignore_ip']) as $ipRange) {
            $ipToIgnore = $ipRange;
            if (false !== strpos($ipToIgnore, '/')) {
                [$ipToIgnore, $cidr_mask] = explode('/', trim($ipToIgnore));
            } else {
                $cidr_mask = Utils::getMaskLength($ipToIgnore);
            }

            $longMaskedToIgnore  = substr(Utils::dtrPton($ipToIgnore), 0, $cidr_mask);
            $longMaskedUserIp    = substr(Utils::dtrPton($stat['ip']), 0, $cidr_mask);
            $longMaskedUserOther = substr(Utils::dtrPton($stat['other_ip']), 0, $cidr_mask);
            if ($longMaskedUserIp === $longMaskedToIgnore || $longMaskedUserOther === $longMaskedToIgnore) {
                Query::setProcessingTimestamp(null);
                return false;
            }
        }

        // Store original IP for GeoIP lookup (before hashing)
        $originalIpForGeo = !empty($stat['other_ip']) ? $stat['other_ip'] : $stat['ip'];

        // Cloudflare: prefer CF-Connecting-IP for geolocation when request is verified
        // as coming through CF (CF-Ray header present). This handles the edge case where
        // mod_remoteip restores real IP to REMOTE_ADDR but other_ip picks up the CF edge IP.
        $cfIp = Utils::getCfClientIp();
        if ($cfIp) {
            $originalIpForGeo = $cfIp;
        }

        // Store original IP before processing (needed for consent upgrade lookup)
        $originalIpBeforeProcessing = $stat['ip'];
        $originalOtherIpBeforeProcessing = $stat['other_ip'] ?? '';

        // Check if this is a consent upgrade request (needed for session management)
        $data_js = \wp_slimstat::get_data_js();
        $isConsentUpgrade = !empty($data_js['consent_upgrade']) && '1' === $data_js['consent_upgrade'];

        // Determine if PII is allowed based on cookie and CMP consent
        // This ensures that after consent is granted, subsequent requests (without consent_upgrade=1)
        // still use full IP instead of hashing
        // Pass $isConsentUpgrade to piiAllowed so it knows this is an explicit consent signal
        // But also check if PII is already allowed via cookie/CMP (for subsequent requests)
        $piiAllowedForIp = Consent::piiAllowed($isConsentUpgrade);

        // Process IP address with anonymization and hashing (for GDPR compliance)
        // Pass explicit consent flag: true if this is a consent upgrade request OR if PII is already allowed via cookie/CMP
        // This ensures IP is not hashed in subsequent requests after consent is granted
        $explicitConsentForIp = $isConsentUpgrade || $piiAllowedForIp;
        $stat = IPHashProvider::processIp($stat, $explicitConsentForIp);

        if (!isset($stat['resource'])) {
            $stat['resource'] = \wp_slimstat::get_request_uri();
        }

        $stat['resource'] = sanitize_text_field(urldecode($stat['resource']));
        $stat['resource'] = preg_replace_callback('/[^\x20-\x7E]/', function ($m) {
            return '%' . bin2hex($m[0]);
        }, $stat['resource']);
        $parsed_url = parse_url($stat['resource'] ?? '');
        if (!$parsed_url) {
            Query::setProcessingTimestamp(null);
            Utils::logError(203);
            return false;
        }


        $stat['resource'] = $parsed_url['path'] . (empty($parsed_url['query']) ? '' : '?' . $parsed_url['query']) . (empty($parsed_url['fragment']) ? '' : '#' . $parsed_url['fragment']);
        if (!empty(\wp_slimstat::$settings['ignore_resources']) && Utils::isBlacklisted($stat['resource'], \wp_slimstat::$settings['ignore_resources'])) {
            Query::setProcessingTimestamp(null);
            return false;
        }

        if (empty($stat['referer']) && !empty($_SERVER['HTTP_REFERER'])) {
            $stat['referer'] = sanitize_url(wp_unslash($_SERVER['HTTP_REFERER']));
        }


        if (!empty($stat['referer'])) {
            $parsed_url = parse_url($stat['referer'] ?? '');
            if (!$parsed_url) {
                Query::setProcessingTimestamp(null);
                Utils::logError(201);
                return false;
            }

            if (isset($parsed_url['scheme']) && ('' !== $parsed_url['scheme'] && '0' !== $parsed_url['scheme']) && !in_array(strtolower($parsed_url['scheme']), ['http', 'https', 'android-app'])) {
                $stat['notes'][] = sprintf(__('Attempted XSS Injection: %s', 'wp-slimstat'), $stat['referer']);
                unset($stat['referer']);
            }

            if (!empty(\wp_slimstat::$settings['ignore_referers']) && Utils::isBlacklisted($stat['referer'], \wp_slimstat::$settings['ignore_referers'])) {
                Query::setProcessingTimestamp(null);
                return false;
            }


            $stat['searchterms'] = Utils::getSearchTerms($stat['referer']);
            $parsed_site_url = parse_url(get_site_url(), PHP_URL_HOST);
            if (isset($parsed_url['host']) && ('' !== $parsed_url['host'] && '0' !== $parsed_url['host']) && $parsed_url['host'] == $parsed_site_url && 'on' != \wp_slimstat::$settings['track_same_domain_referers']) {
                unset($stat['referer']);
            }
        }

        if (empty($stat['searchterms']) && !empty($_POST['s'])) {
            $stat['searchterms'] = sanitize_text_field(str_replace('\\', '', wp_unslash($_POST['s'])));
        }

        if (!isset($stat['content_type'])) {
            $content_info = Utils::getContentInfo();

            if (is_array($content_info)) {
                $stat += $content_info;
            }
        }

        // Check content_type exclusions AFTER content_type is resolved — whether
        // from server-side detection (above) or from the JS tracker's ci payload
        // (Ajax.php). This ensures ignore_content_types works in both modes (#236).
        $ignore_content_types = \wp_slimstat::$settings['ignore_content_types'] ?? '';
        if ('' !== $ignore_content_types) {
            // Normalize legacy ignore_content_types=attachment to match the
            // cpt:attachment format introduced in the #236 fix.
            $ignore_content_types = implode(',', array_unique(array_merge(
                \wp_slimstat::string_to_array($ignore_content_types),
                array_map(
                    function ($v) { return 'cpt:' . $v; },
                    array_filter(
                        \wp_slimstat::string_to_array($ignore_content_types),
                        function ($v) { return 'attachment' === $v; }
                    )
                )
            )));
        }

        if (!empty($ignore_content_types) && !empty($stat['content_type']) && Utils::isBlacklisted($stat['content_type'], $ignore_content_types)) {
            Query::setProcessingTimestamp(null);
            return false;
        }

        if ((is_archive() || is_search()) && !empty($GLOBALS['wp_query']->found_posts)) {
            $stat['notes'][] = 'results:' . intval($GLOBALS['wp_query']->found_posts);
        }

        if ((isset($stat['resource']) && ($stat['resource'] !== '' && $stat['resource'] !== '0') && false !== strpos($stat['resource'], 'wp-admin/admin-ajax.php')) || (!empty($_GET['page']) && false !== strpos($_GET['page'], 'slimview'))) {
            Query::setProcessingTimestamp(null);
            return false;
        }

        // Only collect PII (username, email) if consent allows it
        // If this is a consent upgrade request, pass explicit consent flag
        $piiAllowed = Consent::piiAllowed($isConsentUpgrade);

        // User exclusion: uses wp_get_current_user() defensively to ensure the
        // user object is resolved even in edge-case environments (#246).
        if (self::isUserExcluded()) {
            Query::setProcessingTimestamp(null);
            return false;
        }

        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        if (!empty($user) && !empty($user->ID)) {
            // Only store username/email if PII is allowed (consent granted in anonymous mode)
            if ($piiAllowed) {
                $stat['username'] = $user->data->user_login;
                $stat['email']    = $user->data->user_email;
                $stat['notes'][]  = 'user:' . $user->data->ID;
            }
            $not_spam = true;
        } elseif ($piiAllowed && isset($_COOKIE['comment_author_' . COOKIEHASH])) {
            // Only check comment cookies if PII is allowed
            // Use original IP (before hashing) for spam check with Query builder
            $spam_comment = Query::select('comment_author, comment_author_email, COUNT(*) as comment_count')
                ->from(DB_NAME . '.' . $GLOBALS['wpdb']->comments)
                ->where('comment_author_IP', '=', $originalIpForGeo)
                ->where('comment_approved', '=', 'spam')
                ->groupBy('comment_author')
                ->limit(1)
                ->getRow();

            if (!empty($spam_comment)) {
                if ('on' == \wp_slimstat::$settings['ignore_spammers']) {
                    Query::setProcessingTimestamp(null);
                    return false;
                }

                $stat['notes'][]  = 'spam:yes';
                $stat['username'] = $spam_comment->comment_author;
                $stat['email']    = $spam_comment->comment_author_email;
            } else {
                if (!empty($_COOKIE['comment_author_' . COOKIEHASH])) {
                    $stat['username'] = sanitize_user($_COOKIE['comment_author_' . COOKIEHASH]);
                }

                if (!empty($_COOKIE['comment_author_email_' . COOKIEHASH])) {
                    $stat['email'] = sanitize_email($_COOKIE['comment_author_email_' . COOKIEHASH]);
                }
            }
        }

        $stat['language'] = Utils::getLanguage();
        if (!empty($stat['language']) && !empty(\wp_slimstat::$settings['ignore_languages']) && false !== stripos(\wp_slimstat::$settings['ignore_languages'], (string) $stat['language'])) {
            Query::setProcessingTimestamp(null);
            return false;
        }

        // GeoIP lookup requires PII consent (GeoIP data is PII)
        $provider = \wp_slimstat::resolve_geolocation_provider();
        if (false !== $provider && Consent::piiAllowed()) {
            try {
                $precision = \wp_slimstat::get_geolocation_precision();
                $geoService = new GeolocationService($provider, ['precision' => $precision]);
                $geolocation_data = $geoService->locate($originalIpForGeo);
            } catch (\Exception $e) {
                Query::setProcessingTimestamp(null);
                Utils::logError(205);
                return false;
            }

            if (!empty($geolocation_data) && !empty($geolocation_data['country_code']) && 'xx' != $geolocation_data['country_code']) {
                $stat['country'] = strtolower($geolocation_data['country_code']);
                if (!empty($geolocation_data['city'])) {
                    $stat['city'] = $geolocation_data['city'];
                }

                if (!empty($geolocation_data['subdivision']) && !empty($stat['city'])) {
                    $stat['city'] .= ' (' . $geolocation_data['subdivision'] . ')';
                }

                if (!empty($geolocation_data['latitude']) && !empty($geolocation_data['longitude'])) {
                    $stat['location'] = $geolocation_data['latitude'] . ',' . $geolocation_data['longitude'];
                }
            }

            if (isset($stat['country']) && ($stat['country'] !== '' && $stat['country'] !== '0') && !empty(\wp_slimstat::$settings['ignore_countries']) && false !== stripos(\wp_slimstat::$settings['ignore_countries'], (string) $stat['country'])) {
                Query::setProcessingTimestamp(null);
                return false;
            }
        }

        if ((isset($_SERVER['HTTP_X_MOZ']) && ('prefetch' === strtolower($_SERVER['HTTP_X_MOZ']))) || (isset($_SERVER['HTTP_X_PURPOSE']) && ('preview' === strtolower($_SERVER['HTTP_X_PURPOSE'])))) {
            if ('on' == \wp_slimstat::$settings['ignore_prefetch']) {
                Query::setProcessingTimestamp(null);
                return false;
            } else {
                $stat['notes'][] = 'pre:yes';
            }
        }

        $browser = Browscap::get_browser();
        if ('on' == \wp_slimstat::$settings['ignore_bots'] && 1 == $browser['browser_type']) {
            Query::setProcessingTimestamp(null);
            return false;
        }

        if (!empty(\wp_slimstat::$settings['ignore_browsers']) && Utils::isBlacklisted([$browser['browser'], $browser['user_agent']], \wp_slimstat::$settings['ignore_browsers'])) {
            Query::setProcessingTimestamp(null);
            return false;
        }

        if (!empty(\wp_slimstat::$settings['ignore_platforms']) && Utils::isBlacklisted($browser['platform'], \wp_slimstat::$settings['ignore_platforms'])) {
            Query::setProcessingTimestamp(null);
            return false;
        }

        $stat += $browser;

        // Update stat before ensureVisitId (which may need to read it)
        \wp_slimstat::set_stat($stat);
        // If this is a consent upgrade request, only force assignment if no cookie exists
        // If cookie exists, use it to maintain session continuity
        $forceVisitIdAssign = false;
        if ($isConsentUpgrade && !isset($_COOKIE['slimstat_tracking_code'])) {
            // No cookie exists, force assignment to create new visit_id and set cookie
            $forceVisitIdAssign = true;
        }
        $cookie_has_been_set = Session::ensureVisitId($forceVisitIdAssign);
        $stat = \wp_slimstat::get_stat(); // Get updated stat after ensureVisitId

        $stat = apply_filters('slimstat_filter_pageview_stat', $stat);
        do_action('slimstat_track_pageview', $stat);
        if ($stat === [] || empty($stat['dt'])) {
            Query::setProcessingTimestamp(null);
            return false;
        }

        if (isset($stat['notes']) && $stat['notes'] !== []) {
            $stat['notes'] = '[' . implode('][', $stat['notes']) . ']';
        }

        $stat = array_filter($stat);

        // Update before insert
        \wp_slimstat::set_stat($stat);

        // consent_upgrade already checked above, reuse the variable

		// If this is a consent upgrade request, try to find and upgrade existing anonymous pageview
		try {
			if ($isConsentUpgrade) {
				$isAnonymousTracking = ('on' === (\wp_slimstat::$settings['anonymous_tracking'] ?? 'off'));

				$piiAllowed = Consent::piiAllowed(true);

				// Allow explicit visit_id from client to target original anonymous record
				// Security: Only accept visit_id with valid checksum to prevent targeting arbitrary records
				$requestedVisitId = 0;
				if (!empty($_REQUEST['visit_id'])) {
					$visitIdRaw = sanitize_text_field(wp_unslash($_REQUEST['visit_id']));
					$visitIdValue = Utils::getValueWithoutChecksum($visitIdRaw);
					if (false !== $visitIdValue) {
						$requestedVisitId = intval($visitIdValue);
					}
				} elseif (!empty($data_js['visit_id'])) {
					$visitIdValue = Utils::getValueWithoutChecksum($data_js['visit_id']);
					if (false !== $visitIdValue) {
						$requestedVisitId = intval($visitIdValue);
					}
				}

				if ($requestedVisitId > 0) {
					$stat['visit_id'] = $requestedVisitId;
					\wp_slimstat::set_stat($stat);
				}

				// Only upgrade if we're in anonymous mode and consent is now granted
				if ($isAnonymousTracking && $piiAllowed) {
					$table = $GLOBALS['wpdb']->prefix . 'slim_stats';

					// Use ID from request if available (most reliable for upgrade), otherwise fallback to visit_id
					$requestId = 0;
					if (!empty($data_js['id'])) {
						// ID in data_js usually has checksum, verify and strip it
						$requestId = Utils::getValueWithoutChecksum($data_js['id']);
					}

					$query = Query::select('id, ip, visit_id, fingerprint, username, email, notes')
						->from($table);
					if ($requestId > 0) {
						// Lookup by specific Pageview ID
						$query->where('id', '=', $requestId);
					} else {
						// Fallback: Lookup by session attributes (less reliable if VisitID changed)
						$session_duration = !empty(\wp_slimstat::$settings['session_duration']) ? intval(\wp_slimstat::$settings['session_duration']) : 1800;
						$min_timestamp    = $stat['dt'] - $session_duration;

						$shouldFilterByVisitId = !empty($stat['visit_id']) && !$isConsentUpgrade;

						if ($requestedVisitId > 0) {
							$query->where('visit_id', '=', $requestedVisitId);
						} elseif ($shouldFilterByVisitId) {
							$query->where('visit_id', '=', $stat['visit_id']);
						}

						// Filter by IP to ensure we find the correct record
						// We need to reconstruct the IP as it was stored (hashed in anonymous mode)
						$searchIp = $stat['ip'];
						$hashedIp = '';

						// If IP is not hashed (looks like real IP), hash it to match anonymous records
						if (!empty($searchIp) && strlen($searchIp) < 30) {
							$hashedStat = ['ip' => $originalIpBeforeProcessing, 'other_ip' => $originalOtherIpBeforeProcessing];
							$hashedStat = IPHashProvider::hashIP($hashedStat, $originalIpBeforeProcessing, $originalOtherIpBeforeProcessing);
							$hashedIp = $hashedStat['ip'] ?? '';
							$searchIp = $hashedIp;
						}

						// Calculate the expected Anonymous Visit ID based on current IP/UA
						// This helps find the session even if IP hashing doesn't match perfectly or if lookup needs to be more robust
						$anonymousVisitId = Session::generateAnonymousVisitId();

						// Build complex WHERE clause: (ID match) OR (VisitID match) OR (IP match)
						// Note: We effectively group conditions here

						$whereClause = [];

						if ($requestedVisitId > 0) {
							$whereClause[] = $GLOBALS['wpdb']->prepare("visit_id = %d", $requestedVisitId);
						} elseif ($shouldFilterByVisitId) {
							$whereClause[] = $GLOBALS['wpdb']->prepare("visit_id = %d", $stat['visit_id']);
						}

						if ($anonymousVisitId > 0) {
							// Use %s to handle large integers correctly on all platforms
							$whereClause[] = $GLOBALS['wpdb']->prepare("visit_id = %s", (string)$anonymousVisitId);
						}

						if (!empty($searchIp)) {
							$whereClause[] = $GLOBALS['wpdb']->prepare("ip = %s", $searchIp);
						}

						// Apply the OR conditions for Identity
						if (!empty($whereClause)) {
							$query->whereRaw('(' . implode(' OR ', $whereClause) . ')');
						}

						if (!empty($stat['resource'])) {
							$query->where('resource', '=', $stat['resource']);
						}

						$query->where('dt', '>=', $min_timestamp)
							->where('dt', '<=', $stat['dt']);

						// If fingerprint is available, also check it
						if (!empty($stat['fingerprint'])) {
							$query->where('fingerprint', '=', $stat['fingerprint']);
						}
					}

                    $existing_record = $query->orderBy('dt', 'DESC')
                        ->limit(1)
                        ->getRow();

                    if (!empty($existing_record)) {
                        // Found existing anonymous pageview - upgrade it
                        $existing_id = intval($existing_record->id);

                       	// Use visit_id from existing record if not provided in request
                       	if (empty($requestedVisitId) && !empty($existing_record->visit_id)) {
                       		$stat['visit_id'] = intval($existing_record->visit_id);
                       		\wp_slimstat::set_stat($stat);
                       	}

                        // Get real IP (before hashing) for upgrade
                        [$realIp, $realOtherIp] = Utils::getRemoteIp();

                        // Prepare update data
                        $update_data = [];

                        // Check hash_ip setting to determine if we should upgrade IP
                        $hashIpEnabled = ('on' === (\wp_slimstat::$settings['hash_ip'] ?? 'off'));
                        $anonymizeIpEnabled = ('on' === (\wp_slimstat::$settings['anonymize_ip'] ?? 'off'));

                        // In anonymous mode, IP was hashed. After consent, we upgrade to real IP
                        // UNLESS hash_ip setting is explicitly enabled (user wants to keep hashing even with consent)
                        if (!$hashIpEnabled) {
                            // Upgrade from hash to real IP (or anonymized if anonymize_ip is enabled)
                            if ($anonymizeIpEnabled) {
                                // Anonymize IP if setting is enabled
                                $update_data['ip'] = IPHashProvider::anonymizeIP($realIp);
                                if (!empty($realOtherIp)) {
                                    $update_data['other_ip'] = IPHashProvider::anonymizeIP($realOtherIp);
                                } else {
                                    $update_data['other_ip'] = '';
                                }
                            } else {
                                // Store full real IP (consent granted, no anonymization needed)
                                $update_data['ip'] = $realIp;
                                if (!empty($realOtherIp)) {
                                    $update_data['other_ip'] = $realOtherIp;
                                } else {
                                    $update_data['other_ip'] = '';
                                }
                            }
                        }

                        // Add username and email if logged in and PII allowed
                        // Use wp_get_current_user() defensively (#246) — $GLOBALS['current_user']
                        // may not be resolved in edge-case environments (object caching, multisite).
                        $upgradeUser = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
                        if (!empty($upgradeUser) && !empty($upgradeUser->ID)) {
                            $update_data['username'] = $upgradeUser->data->user_login;
                            $update_data['email']    = $upgradeUser->data->user_email;
                            $user_note = '[user:' . $upgradeUser->data->ID . ']';
                            if (empty($existing_record->notes) || false === strpos($existing_record->notes, $user_note)) {
                                $update_data['notes'] = $user_note;
                            }
                        }

                        // Update fingerprint if available (may not have been sent in anonymous mode)
                        if (!empty($stat['fingerprint'])) {
                            $update_data['fingerprint'] = $stat['fingerprint'];
                        }

                        // Perform GeoIP lookup if enabled and PII allowed
                        // Only do GeoIP lookup if we're updating IP (not keeping hash)
                        if (!$hashIpEnabled && !empty($update_data['ip'])) {
                            $provider = \wp_slimstat::resolve_geolocation_provider();
                            if (false !== $provider) {
                                try {
                                    $precision = \wp_slimstat::get_geolocation_precision();
                                    // Prefer CF-Connecting-IP (verified by Cloudflare) when available,
                                    // else fall back to the best proxy-header IP, then REMOTE_ADDR.
                                    $geoIp = Utils::getCfClientIp() ?? (!empty($realOtherIp) ? $realOtherIp : $realIp);

                                    $geoService = new GeolocationService($provider, ['precision' => $precision]);
                                    $geolocation_data = $geoService->locate($geoIp);
                                    if (!empty($geolocation_data) && !empty($geolocation_data['country_code']) && 'xx' != $geolocation_data['country_code']) {
                                        $update_data['country'] = strtolower($geolocation_data['country_code']);
                                        if (!empty($geolocation_data['city'])) {
                                            $update_data['city'] = $geolocation_data['city'];
                                        }
                                        if (!empty($geolocation_data['subdivision']) && !empty($update_data['city'])) {
                                            $update_data['city'] .= ' (' . $geolocation_data['subdivision'] . ')';
                                        }
                                        if (!empty($geolocation_data['latitude']) && !empty($geolocation_data['longitude'])) {
                                            $update_data['location'] = $geolocation_data['latitude'] . ',' . $geolocation_data['longitude'];
                                        }
                                    }
                                } catch (\Exception $e) {
                                    // Ignore GeoIP errors
                                }
                            }
                        }

                        // Use atomic counter for thread-safe visit ID generation (O(1) instead of O(n))
                        $next_visit_id = VisitIdGenerator::generateNextVisitId();
                        if ($next_visit_id <= 0) {
                            $next_visit_id = time();
                        }

                        $stat['visit_id'] = intval($next_visit_id);

                        // Sync visit_id to ensure session continuity
                        if (!empty($stat['visit_id']) && isset($existing_record->visit_id) && $stat['visit_id'] != $existing_record->visit_id) {
                            $update_data['visit_id'] = $stat['visit_id'];
                        }

                        // Update the existing record
                        if (!empty($update_data)) {
                            $update_query = Query::update($table);

                            // Handle notes separately (append if needed)
                            if (!empty($update_data['notes'])) {
                                $notes_to_append = $update_data['notes'];
                                unset($update_data['notes']);
                                $update_query->setRaw('notes', "CONCAT(IFNULL(notes, ''), %s)", [$notes_to_append]);
                            }

                            if (!empty($update_data)) {
                                $update_query->set($update_data);
                            }

                            $session_duration = !empty(\wp_slimstat::$settings['session_duration']) ? intval(\wp_slimstat::$settings['session_duration']) : 1800;
                            $update_min_ts = $stat['dt'] - $session_duration;

                            if (!empty($existing_record->visit_id) && $existing_record->visit_id > 0) {
                                // Upgrade ALL records for this session (same visit_id + same anonymous IP)
                                $update_query->where('visit_id', '=', intval($existing_record->visit_id));
                                $update_query->where('ip', '=', $existing_record->ip);
                                $update_query->where('dt', '>=', $update_min_ts);
                            } else {
                                // Fallback: Upgrade only this specific record
                                $update_query->where('id', '=', $existing_id);
                            }

                            $update_query->execute();

                            // Return existing ID (upgraded record)
                            $stat['id'] = $existing_id;
                            \wp_slimstat::set_stat($stat);
                            Query::setProcessingTimestamp(null);

                            // Ensure tracking cookie is set after upgrade
                            if (empty($stat['visit_id']) && !empty($stat['id'])) {
                                Session::setTrackingCookie($stat['id'], 'id', 2678400);
                            }

                            if ($isConsentUpgrade) {
                                /**
                                 * Fires when a consent upgrade request is processed.
                                 *
                                 * @param int   $pageview_id Upgraded pageview ID.
                                 * @param array $stat        Current tracking stat array.
                                 */
                                do_action('slimstat_consent_granted', $stat['id'], $stat);
                            }

                            return $stat['id'];
                        } else {
                            // No update data but record found - just return existing ID
                            $stat['id'] = $existing_id;
                            \wp_slimstat::set_stat($stat);
                            Query::setProcessingTimestamp(null);
                            if ($isConsentUpgrade) {
                                do_action('slimstat_consent_granted', $stat['id'], $stat);
                            }
                            return $stat['id'];
                        }
                    }
                    // If no existing record found, continue with normal insert flow below
                }
            }
		} catch (\Exception $e) {
			// Ignore consent upgrade errors to avoid breaking tracking.
        }

        // Duplicate check for anonymous mode without PII (no cookies available)
        $isAnonymousTracking = ('on' === (\wp_slimstat::$settings['anonymous_tracking'] ?? 'off'));
        $piiAllowed = Consent::piiAllowed();
        if ($isAnonymousTracking && !$piiAllowed && !empty($stat['visit_id']) && !empty($stat['resource'])) {
            // Use session_duration (default 30 minutes) to match normal tracking behavior
            // In normal mode, cookies persist for session_duration, so we replicate that here
            $session_duration = !empty(\wp_slimstat::$settings['session_duration']) ? intval(\wp_slimstat::$settings['session_duration']) : 1800;
            $table = $GLOBALS['wpdb']->prefix . 'slim_stats';
            $min_timestamp = $stat['dt'] - $session_duration;

            // Check for duplicate within session duration
            $query = Query::select('id, dt')
                ->from($table)
                ->where('visit_id', '=', $stat['visit_id'])
                ->where('resource', '=', $stat['resource'])
                ->where('dt', '>=', $min_timestamp)
                ->where('dt', '<=', $stat['dt']);

            if (!empty($stat['fingerprint'])) {
                $query->where('fingerprint', '=', $stat['fingerprint']);
            }

            $existing_record = $query->orderBy('dt', 'DESC')
                ->limit(1)
                ->getRow();

            if (!empty($existing_record)) {
                $stat['id'] = intval($existing_record->id);
                \wp_slimstat::set_stat($stat);
                Query::setProcessingTimestamp(null);
                return $stat['id'];
            }
        }

        $stat['id'] = Storage::insertRow($stat, $GLOBALS['wpdb']->prefix . 'slim_stats');

        if (empty($stat['id'])) {
            include_once(SLIMSTAT_ANALYTICS_DIR . 'admin/index.php');
            \wp_slimstat_admin::init_environment();
            $stat['id'] = Storage::insertRow($stat, $GLOBALS['wpdb']->prefix . 'slim_stats');
            if (empty($stat['id'])) {
                Query::setProcessingTimestamp(null);
                Utils::logError(200);
                return false;
            }
        }

        // Update stat after getting ID
        \wp_slimstat::set_stat($stat);

        // Handle cookie setting using centralized Session class
        // Cookies are ONLY set when consent allows (handled internally by Session::setTrackingCookie)
        if (empty($stat['visit_id']) && !empty($stat['id'])) {
            // No visit ID assigned yet - set cookie with pageview ID
            // Cookie expires in 31 days (2678400 seconds)
            Session::setTrackingCookie($stat['id'], 'id', 2678400);
        } elseif (!$cookie_has_been_set && 'on' == \wp_slimstat::$settings['extend_session'] && $stat['visit_id'] > 0) {
            // Extend existing session cookie
            Session::setTrackingCookie($stat['visit_id'], 'visit');
        }

        // Clear processing timestamp context after successful processing
        Query::setProcessingTimestamp(null);

        if ($isConsentUpgrade) {
            do_action('slimstat_consent_granted', $stat['id'], $stat);
        }

        return $stat['id'];
    }

    public static function updateContentType($status = 301, $location = '')
    {
        if ($status >= 300 && $status < 400) {
            $stat = \wp_slimstat::get_stat();
            $stat['content_type'] = 'redirect:' . intval($status);
            \wp_slimstat::set_stat($stat);
            Storage::updateRow($stat);
        }

        return $status;
    }

}
