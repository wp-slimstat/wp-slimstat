<?php

namespace SlimStat\Channel;

/**
 * Traffic channel classification rules with priority cascade.
 *
 * Implements the 8-channel classification logic according to FR-024 to FR-032
 * with priority order: UTM → AI → Social → Search → Referral → Direct → Other.
 *
 * @package SlimStat\Channel
 * @since 5.1.0
 */
class ClassificationRules implements ChannelClassifier
{
    /**
     * Domain lists for classification (lazy-loaded from config).
     *
     * @var array|null
     */
    private ?array $domain_lists = null;

    /**
     * Current site domain for direct traffic detection.
     *
     * @var string
     */
    private string $site_domain;

    /**
     * Constructor.
     *
     * @param string|null $site_domain Site domain (defaults to home_url() host)
     */
    public function __construct(?string $site_domain = null)
    {
        $this->site_domain = $site_domain ?? parse_url(home_url(), PHP_URL_HOST);
    }

    /**
     * Classify a visit into a traffic channel.
     *
     * Implements priority cascade (FR-032):
     * 1. UTM parameters (if present) override all other signals
     * 2. AI detection (chatgpt.com, claude.ai, etc.)
     * 3. Social detection (facebook.com, twitter.com, etc.)
     * 4. Search engine detection (google.com, bing.com, etc.)
     * 5. Referral (external domain not in other categories)
     * 6. Direct (no referrer or same domain)
     * 7. Other (catchall for unclassified - FR-040)
     *
     * @param array $visit_data Visit record with keys: referer, notes, resource, domain
     * @return array ['channel' => string, 'utm_source' => ?string, 'utm_medium' => ?string, 'utm_campaign' => ?string]
     */
    public function classify(array $visit_data): array
    {
        // Extract and normalize inputs
        $referer = $visit_data['referer'] ?? '';
        $notes = $visit_data['notes'] ?? '';
        $site_domain = $visit_data['domain'] ?? $this->site_domain;

        // Extract UTM parameters (check notes field first, then resource URL)
        $utm = $this->extract_utm_parameters($notes, $visit_data['resource'] ?? '');

        // Priority 1: UTM-based classification (FR-032)
        if (!empty($utm['utm_medium'])) {
            $channel = $this->classify_by_utm($utm, $referer);
            if ($channel !== null) {
                return array_merge(['channel' => $channel], $utm);
            }
        }

        // Priority 2: AI detection (FR-029)
        if ($this->matches_domain_list($referer, 'AI_DOMAINS')) {
            return array_merge(['channel' => 'AI'], $utm);
        }

        // Priority 3: Social detection (FR-027)
        if ($this->matches_domain_list($referer, 'SOCIAL_DOMAINS')) {
            return array_merge(['channel' => 'Social'], $utm);
        }

        // Priority 4: Search engine detection (FR-025, FR-026)
        if ($this->matches_domain_list($referer, 'SEARCH_DOMAINS')) {
            // Check for paid search indicators (gclid, msclkid parameters)
            if ($this->is_paid_search($referer, $utm)) {
                return array_merge(['channel' => 'Paid Search'], $utm);
            }
            return array_merge(['channel' => 'Organic Search'], $utm);
        }

        // Priority 5: Referral (FR-030) - external domain not in other categories
        if (!empty($referer) && !$this->is_same_domain($referer, $site_domain)) {
            return array_merge(['channel' => 'Referral'], $utm);
        }

        // Priority 6: Direct (FR-024) - no referrer or same domain
        if (empty($referer) || $this->is_same_domain($referer, $site_domain)) {
            return array_merge(['channel' => 'Direct'], $utm);
        }

        // Priority 7: Other (FR-031, FR-040) - catchall for unclassified
        return array_merge(['channel' => 'Other'], $utm);
    }

    /**
     * Extract UTM parameters from notes field and resource URL.
     *
     * Checks notes field first (T013), then falls back to parsing resource URL.
     * Normalizes values (lowercase, trim) and validates against PII patterns (T014).
     *
     * @param string $notes Notes field from wp_slim_stats
     * @param string $resource Resource URL (visited page)
     * @return array ['utm_source' => ?string, 'utm_medium' => ?string, 'utm_campaign' => ?string]
     */
    public function extract_utm_parameters(string $notes, string $resource): array
    {
        $utm = [
            'utm_source' => null,
            'utm_medium' => null,
            'utm_campaign' => null,
        ];

        // Parse notes field (priority)
        if (!empty($notes)) {
            parse_str($notes, $notes_params);
            $utm['utm_source'] = $notes_params['utm_source'] ?? null;
            $utm['utm_medium'] = $notes_params['utm_medium'] ?? null;
            $utm['utm_campaign'] = $notes_params['utm_campaign'] ?? null;
        }

        // Fallback to resource URL if notes field doesn't contain UTM params
        if (empty($utm['utm_source']) && !empty($resource)) {
            $parsed_url = parse_url($resource);
            if (isset($parsed_url['query'])) {
                parse_str($parsed_url['query'], $query_params);
                $utm['utm_source'] = $utm['utm_source'] ?? ($query_params['utm_source'] ?? null);
                $utm['utm_medium'] = $utm['utm_medium'] ?? ($query_params['utm_medium'] ?? null);
                $utm['utm_campaign'] = $utm['utm_campaign'] ?? ($query_params['utm_campaign'] ?? null);
            }
        }

        // Normalize and validate UTM parameters (T014)
        foreach ($utm as $key => $value) {
            if ($value !== null) {
                // Normalize: lowercase and trim
                $value = strtolower(trim($value));

                // Validate: Reject PII patterns (emails, phone numbers)
                if ($this->contains_pii($value)) {
                    $utm[$key] = null;
                } else {
                    $utm[$key] = $value;
                }
            }
        }

        return $utm;
    }

    /**
     * Classify channel based on UTM parameters.
     *
     * Implements FR-026 (paid search), FR-028 (email).
     *
     * @param array $utm UTM parameters
     * @param string $referer Referrer URL
     * @return string|null Channel name, or null if no UTM-based classification
     */
    private function classify_by_utm(array $utm, string $referer): ?string
    {
        $medium = $utm['utm_medium'] ?? '';

        // FR-026: Paid search (utm_medium = cpc/ppc/paid)
        if (in_array($medium, ['cpc', 'ppc', 'paid'], true)) {
            return 'Paid Search';
        }

        // FR-028: Email (utm_medium = email)
        if ('email' === $medium) {
            return 'Email';
        }

        // If utm_medium is present but doesn't match specific rules, continue to referrer-based classification
        return null;
    }

    /**
     * Check if referrer indicates paid search.
     *
     * Detects paid search via gclid (Google Ads) or msclkid (Microsoft Advertising) parameters.
     *
     * @param string $referer Referrer URL
     * @param array $utm UTM parameters
     * @return bool True if paid search indicators present
     */
    private function is_paid_search(string $referer, array $utm): bool
    {
        // Check for paid search click IDs in referrer
        if (strpos($referer, 'gclid=') !== false || strpos($referer, 'msclkid=') !== false) {
            return true;
        }

        // Already handled by classify_by_utm(), but double-check for safety
        $medium = $utm['utm_medium'] ?? '';
        return in_array($medium, ['cpc', 'ppc', 'paid'], true);
    }

    /**
     * Check if referrer matches a domain list.
     *
     * Uses fnmatch() for wildcard support (e.g., "*.google.*" matches google.com, google.co.uk).
     *
     * @param string $referer Referrer URL
     * @param string $list_name Domain list name (SEARCH_DOMAINS, SOCIAL_DOMAINS, etc.)
     * @return bool True if referrer domain matches any pattern in the list
     */
    public function matches_domain_list(string $referer, string $list_name): bool
    {
        if (empty($referer)) {
            return false;
        }

        // Lazy-load domain lists
        if ($this->domain_lists === null) {
            $this->domain_lists = require dirname(__DIR__) . '/Config/domain-lists.php';
        }

        $domains = $this->domain_lists[$list_name] ?? [];
        $referer_host = parse_url($referer, PHP_URL_HOST);

        if (empty($referer_host)) {
            return false;
        }

        foreach ($domains as $pattern) {
            // fnmatch() supports wildcards: *.google.* matches google.com, google.co.uk, etc.
            if (fnmatch($pattern, $referer_host)) {
                return true;
            }

            // Also check exact match for non-wildcard patterns
            if ($pattern === $referer_host) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if referrer is the same domain as the site (direct traffic).
     *
     * Implements FR-024 direct traffic detection.
     *
     * @param string $referer Referrer URL
     * @param string $site_domain Current site domain
     * @return bool True if same domain
     */
    public function is_same_domain(string $referer, string $site_domain): bool
    {
        if (empty($referer)) {
            return true; // No referrer = direct traffic
        }

        $referer_host = parse_url($referer, PHP_URL_HOST);

        if (empty($referer_host)) {
            return true; // Invalid referrer = treat as direct
        }

        // Normalize domains (remove www. prefix for comparison)
        $referer_host = preg_replace('/^www\./', '', $referer_host);
        $site_domain = preg_replace('/^www\./', '', $site_domain);

        return $referer_host === $site_domain;
    }

    /**
     * Validate if a string contains PII (email, phone number).
     *
     * Implements FR-038 privacy compliance by rejecting UTM parameters that contain PII.
     *
     * @param string $value Value to validate
     * @return bool True if PII detected
     */
    private function contains_pii(string $value): bool
    {
        // Email pattern detection
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return true;
        }

        // Phone number pattern detection (basic patterns: +1234567890, (123) 456-7890, etc.)
        if (preg_match('/(?:\+?\d{1,3}[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', $value)) {
            return true;
        }

        return false;
    }
}
