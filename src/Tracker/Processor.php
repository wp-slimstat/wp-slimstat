<?php

namespace SlimStat\Tracker;

use SlimStat\Utils\Query;
use SlimStat\Services\Browscap;
use SlimStat\Services\Privacy;
use SlimStat\Services\GeoService;
use SlimStat\Services\GeoIP;
use SlimStat\Providers\IPHashProvider;
use SlimStat\Utils\Consent;

class Processor
{
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

        // Set processing timestamp context for Query builder caching decisions.
        // This ensures caching is based on the event timestamp, not current server time.
        // Critical for avoiding race conditions at midnight.
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
        // Prioritize other_ip (actual client IP from proxy headers) for better accuracy
        // This matches the IP selection logic in Session::generateAnonymousVisitId()
        $originalIpForGeo = !empty($stat['other_ip']) ? $stat['other_ip'] : $stat['ip'];

        // Process IP address with anonymization and hashing (for GDPR compliance)
        $stat = IPHashProvider::processIp($stat);

        if (!isset($stat['resource'])) {
            $stat['resource'] = \wp_slimstat::get_request_uri();
        }

        $stat['resource'] = sanitize_text_field(urldecode($stat['resource']));
        $stat['resource'] = preg_replace_callback('/[^\x20-\x7E]/', function ($m) {
            return '%' . bin2hex($m[0]);
        }, $stat['resource']);
        $parsed_url = parse_url($stat['resource']);
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
            $parsed_url = parse_url($stat['referer']);
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
            $stat['searchterms'] = sanitize_text_field(str_replace('\\', '', $_REQUEST['s']));
        }

        if (!isset($stat['content_type'])) {
            $content_info = Utils::getContentInfo();
            if (!empty(\wp_slimstat::$settings['ignore_content_types']) && Utils::isBlacklisted($content_info['content_type'], \wp_slimstat::$settings['ignore_content_types'])) {
                Query::setProcessingTimestamp(null);
                return false;
            }

            if (is_array($content_info)) {
                $stat += $content_info;
            }
        }

        if ((is_archive() || is_search()) && !empty($GLOBALS['wp_query']->found_posts)) {
            $stat['notes'][] = 'results:' . intval($GLOBALS['wp_query']->found_posts);
        }

        if ((isset($stat['resource']) && ($stat['resource'] !== '' && $stat['resource'] !== '0') && false !== strpos($stat['resource'], 'wp-admin/admin-ajax.php')) || (!empty($_GET['page']) && false !== strpos($_GET['page'], 'slimview'))) {
            Query::setProcessingTimestamp(null);
            return false;
        }

        // Only collect PII (username, email) if consent allows it
        $piiAllowed = Consent::piiAllowed();

        if (!empty($GLOBALS['current_user']->ID)) {
            if ('on' == \wp_slimstat::$settings['ignore_wp_users']) {
                Query::setProcessingTimestamp(null);
                return false;
            }

            foreach ($GLOBALS['current_user']->roles as $capability) {
                if (Utils::isBlacklisted($capability, \wp_slimstat::$settings['ignore_capabilities'])) {
                    Query::setProcessingTimestamp(null);
                    return false;
                }
            }

            if (!empty(\wp_slimstat::$settings['ignore_users']) && Utils::isBlacklisted($GLOBALS['current_user']->data->user_login, \wp_slimstat::$settings['ignore_users'])) {
                Query::setProcessingTimestamp(null);
                return false;
            }

            // Only store username/email if PII is allowed (consent granted in anonymous mode)
            if ($piiAllowed) {
                $stat['username'] = $GLOBALS['current_user']->data->user_login;
                $stat['email']    = $GLOBALS['current_user']->data->user_email;
                $stat['notes'][]  = 'user:' . $GLOBALS['current_user']->data->ID;
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

        // GDPR Compliance: GeoIP lookup requires PII consent in anonymous mode
        // GeoIP data (country, city, location) is considered PII and should only be collected with consent
        $geographicProvider = new GeoService();
        if ($geographicProvider->isGeoIPEnabled() && Consent::piiAllowed()) {
            try {
                // Use original IP (before hashing) for GeoIP lookup
                // Only perform lookup if PII is allowed (consent granted in anonymous mode)
                $geolocation_data = GeoIP::loader($originalIpForGeo);
            } catch (\Exception $e) {
                Query::setProcessingTimestamp(null);
                Utils::logError(205);
                return false;
            }


            if (!empty($geolocation_data['country']['iso_code']) && 'xx' != $geolocation_data['country']['iso_code']) {
                $stat['country'] = strtolower($geolocation_data['country']['iso_code']);
                if (!empty($geolocation_data['city']['names']['en'])) {
                    $stat['city'] = $geolocation_data['city']['names']['en'];
                }

                if (!empty($geolocation_data['subdivisions'][0]['iso_code']) && !empty($stat['city'])) {
                    $stat['city'] .= ' (' . $geolocation_data['subdivisions'][0]['iso_code'] . ')';
                }

                if (!empty($geolocation_data['location']['latitude']) && !empty($geolocation_data['location']['longitude'])) {
                    $stat['location'] = $geolocation_data['location']['latitude'] . ',' . $geolocation_data['location']['longitude'];
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
        $cookie_has_been_set = Session::ensureVisitId(false);
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

        // In Anonymous Tracking Mode without PII, simulate normal session behavior
        // GDPR Compliance:
        // - When PII is NOT allowed: No cookies are set (GDPR-compliant)
        //   → We need to check for duplicates manually since cookies aren't available
        // - When PII IS allowed: Cookies are set (after explicit consent)
        //   → No need to check duplicates - cookies handle this automatically
        // This matches the behavior of normal tracking mode where cookies prevent duplicate records
        $isAnonymousTracking = ('on' === (\wp_slimstat::$settings['anonymous_tracking'] ?? 'off'));
        $piiAllowed = Consent::piiAllowed();

        // Only perform duplicate check if:
        // 1. Anonymous tracking mode is enabled
        // 2. PII is NOT allowed (no cookies available)
        // 3. Visit ID and resource are available
        // This ensures we only check duplicates when cookies aren't available (GDPR-compliant mode)
        if ($isAnonymousTracking && !$piiAllowed && !empty($stat['visit_id']) && !empty($stat['resource'])) {
            // Use session_duration (default 30 minutes) to match normal tracking behavior
            // In normal mode, cookies persist for session_duration, so we replicate that here
            $session_duration = !empty(\wp_slimstat::$settings['session_duration']) ? intval(\wp_slimstat::$settings['session_duration']) : 1800;
            $table = $GLOBALS['wpdb']->prefix . 'slim_stats';
            $min_timestamp = $stat['dt'] - $session_duration;

            // Check if a record with the same visit_id and resource exists within the session duration
            // Also check fingerprint if available for more accurate duplicate detection
            // This prevents duplicate records from page refreshes while still allowing:
            // - New visits to different pages (different resource)
            // - New sessions after session_duration expires
            $query = Query::select('id, dt')
                ->from($table)
                ->where('visit_id', '=', $stat['visit_id'])
                ->where('resource', '=', $stat['resource'])
                ->where('dt', '>=', $min_timestamp)
                ->where('dt', '<=', $stat['dt']);

            // If fingerprint is available, also check it for more accurate duplicate detection
            // This ensures the same user doesn't get duplicate records when navigating between pages
            if (!empty($stat['fingerprint'])) {
                $query->where('fingerprint', '=', $stat['fingerprint']);
            }

            $existing_record = $query->orderBy('dt', 'DESC')
                ->limit(1)
                ->getRow();

            if (!empty($existing_record)) {
                // Duplicate found within session - return existing record ID
                // This matches normal behavior where cookies prevent duplicate pageviews
                // Note: This only runs when cookies aren't available (GDPR-compliant mode)
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
