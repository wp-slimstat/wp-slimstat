<?php

namespace SlimStat\Channel;

/**
 * Interface for traffic channel classification.
 *
 * Defines the contract for classifying website visits into traffic channels
 * based on referrer, UTM parameters, and domain matching rules.
 *
 * @package SlimStat\Channel
 * @since 5.1.0
 */
interface ChannelClassifier
{
    /**
     * Classify a visit record into a traffic channel.
     *
     * Analyzes visit data (referrer, UTM parameters, notes field) and returns
     * the appropriate channel category according to FR-024 to FR-032 classification rules.
     *
     * @param array $visit_data Visit record from wp_slim_stats with keys:
     *                          - 'referer' (string): Referrer URL
     *                          - 'notes' (string): Notes field (may contain UTM parameters)
     *                          - 'resource' (string): Visited URL on this site
     *                          - 'domain' (string): Current site domain (for direct traffic detection)
     * @return array Classification result with keys:
     *               - 'channel' (string): Channel category name (Direct, Organic Search, etc.)
     *               - 'utm_source' (string|null): UTM source parameter (if present)
     *               - 'utm_medium' (string|null): UTM medium parameter (if present)
     *               - 'utm_campaign' (string|null): UTM campaign parameter (if present)
     */
    public function classify(array $visit_data): array;
}
