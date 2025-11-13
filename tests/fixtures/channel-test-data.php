<?php

/**
 * Test data generator for Traffic Channel Report feature.
 *
 * Generates 10,000 sample visit records with diverse referrers representing
 * all 8 channel categories for performance testing and feature validation.
 *
 * **Usage**:
 * ```php
 * require_once 'tests/fixtures/channel-test-data.php';
 * \SlimStat\Tests\Fixtures\ChannelTestData::generate(10000);
 * ```
 *
 * @package SlimStat\Tests\Fixtures
 * @since 5.1.0
 */

namespace SlimStat\Tests\Fixtures;

class ChannelTestData
{
    /**
     * Generate sample visit records in wp_slim_stats table.
     *
     * Creates test visits with diverse referrers covering all 8 channel categories:
     * - Direct: no referrer or same domain
     * - Organic Search: google.com, bing.com without UTM paid parameters
     * - Paid Search: google.com with utm_medium=cpc or gclid parameter
     * - Social: facebook.com, twitter.com, linkedin.com, etc.
     * - Email: utm_medium=email or webmail referrers
     * - AI: chatgpt.com, claude.ai, perplexity.ai, etc.
     * - Referral: external domains not in other categories
     * - Other: unclassifiable edge cases
     *
     * @param int $count Number of sample visits to generate (default: 10000)
     * @param bool $random_timestamps Generate visits over last 30 days (default: false = all current time)
     * @return int Number of visits generated successfully
     */
    public static function generate(int $count = 10000, bool $random_timestamps = false): int
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'slim_stats';
        $site_domain = parse_url(home_url(), PHP_URL_HOST);

        // Distribution across channels (realistic mix)
        $channel_distribution = [
            'direct' => 0.20, // 20%
            'organic' => 0.30, // 30%
            'paid' => 0.10, // 10%
            'social' => 0.15, // 15%
            'email' => 0.05, // 5%
            'ai' => 0.05, // 5%
            'referral' => 0.10, // 10%
            'other' => 0.05, // 5%
        ];

        $referrers = self::get_sample_referrers($site_domain);
        $generated = 0;
        $base_time = time();

        // Generate visits in batches of 100 for performance
        $batch_size = 100;
        $batches = ceil($count / $batch_size);

        for ($batch = 0; $batch < $batches; $batch++) {
            $values = [];
            $current_batch_size = min($batch_size, $count - ($batch * $batch_size));

            for ($i = 0; $i < $current_batch_size; $i++) {
                // Select channel based on distribution
                $channel_type = self::select_channel_by_distribution($channel_distribution);
                $referrer_data = self::get_referrer_for_channel($channel_type, $referrers, $site_domain);

                // Generate timestamp (either current or random within last 30 days)
                $timestamp = $random_timestamps
                    ? $base_time - rand(0, 30 * 24 * 60 * 60)
                    : $base_time;

                // Build visit record (simplified for testing purposes)
                $values[] = $wpdb->prepare(
                    '(%d, %s, %s, %s, %s, %d, %s, %s, %d, %d)',
                    $timestamp, // dt (Unix timestamp)
                    '127.0.0.1', // ip (anonymized for testing)
                    $referrer_data['referer'], // referer URL
                    '/', // resource (page visited)
                    'Mozilla/5.0 (Test Agent)', // user agent
                    200, // response code
                    'GET', // request method
                    $referrer_data['notes'], // notes field (may contain UTM params)
                    rand(0, 1), // is_known_user
                    rand(100, 10000) // visit_id (simulated)
                );
            }

            // Insert batch
            $sql = "INSERT INTO {$table_name}
                    (dt, ip, referer, resource, user_agent, response_code, request_method, notes, is_known_user, visit_id)
                    VALUES " . implode(', ', $values);

            $result = $wpdb->query($sql);

            if ($result) {
                $generated += $current_batch_size;
            }
        }

        return $generated;
    }

    /**
     * Get sample referrers organized by channel type.
     *
     * @param string $site_domain Current site domain for direct traffic
     * @return array Referrer patterns by channel type
     */
    private static function get_sample_referrers(string $site_domain): array
    {
        return [
            'direct' => [
                '', // No referrer
                "https://{$site_domain}/", // Same domain
            ],
            'organic' => [
                'https://www.google.com/search?q=wordpress+analytics',
                'https://www.bing.com/search?q=slimstat+plugin',
                'https://duckduckgo.com/?q=web+analytics',
                'https://search.yahoo.com/search?p=wordpress+stats',
            ],
            'paid' => [
                'https://www.google.com/search?q=analytics&gclid=abc123', // Google Ads
                'https://www.google.com/aclk?sa=l&ai=abc', // Google display ads
                'https://www.bing.com/aclk?ld=abc', // Bing Ads
            ],
            'social' => [
                'https://www.facebook.com/',
                'https://t.co/abc123', // Twitter shortener
                'https://www.linkedin.com/feed/',
                'https://www.instagram.com/',
                'https://www.tiktok.com/',
                'https://www.reddit.com/r/wordpress/',
                'https://www.youtube.com/',
            ],
            'email' => [
                'https://mail.google.com/mail/u/0/',
                'https://outlook.live.com/mail/inbox',
                'https://mail.yahoo.com/',
            ],
            'ai' => [
                'https://chat.openai.com/',
                'https://chatgpt.com/',
                'https://claude.ai/',
                'https://www.perplexity.ai/',
                'https://www.you.com/',
                'https://www.phind.com/',
            ],
            'referral' => [
                'https://www.example.com/blog/',
                'https://news.ycombinator.com/',
                'https://www.producthunt.com/',
                'https://techcrunch.com/',
                'https://anothersite.org/',
            ],
            'other' => [
                'android-app://com.example.app/', // Mobile app
                'https://unknown-source.invalid/', // Invalid domain
            ],
        ];
    }

    /**
     * Get referrer data for a specific channel type.
     *
     * @param string $channel_type Channel type (direct, organic, paid, etc.)
     * @param array $referrers Referrer data by channel
     * @param string $site_domain Current site domain
     * @return array ['referer' => string, 'notes' => string]
     */
    private static function get_referrer_for_channel(string $channel_type, array $referrers, string $site_domain): array
    {
        $referrer_list = $referrers[$channel_type] ?? [];
        $referer = $referrer_list[array_rand($referrer_list)];
        $notes = '';

        // Add UTM parameters based on channel type
        switch ($channel_type) {
            case 'paid':
                $notes = 'utm_source=google&utm_medium=cpc&utm_campaign=winter_sale';
                break;
            case 'email':
                // 50% chance of UTM parameters, 50% webmail referrer only
                if (rand(0, 1) === 1) {
                    $notes = 'utm_source=newsletter&utm_medium=email&utm_campaign=weekly_digest';
                    $referer = ''; // UTM parameters override referrer for classification
                }
                break;
            case 'social':
                // Occasional UTM tracking for social campaigns
                if (rand(0, 4) === 0) { // 20% chance
                    $notes = 'utm_source=facebook&utm_medium=social&utm_campaign=product_launch';
                }
                break;
        }

        return [
            'referer' => $referer,
            'notes' => $notes,
        ];
    }

    /**
     * Select a channel type based on distribution percentages.
     *
     * @param array $distribution Channel distribution (channel => percentage)
     * @return string Selected channel type
     */
    private static function select_channel_by_distribution(array $distribution): string
    {
        $rand = mt_rand(1, 100) / 100; // Random float between 0.00 and 1.00
        $cumulative = 0.0;

        foreach ($distribution as $channel => $percentage) {
            $cumulative += $percentage;
            if ($rand <= $cumulative) {
                return $channel;
            }
        }

        return 'other'; // Fallback
    }

    /**
     * Delete all test data generated by this script.
     *
     * **WARNING**: This deletes ALL visits from wp_slim_stats. Use with caution!
     *
     * @return int Number of visits deleted
     */
    public static function cleanup(): int
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'slim_stats';
        $result = $wpdb->query("TRUNCATE TABLE {$table_name}");

        return $result !== false ? $result : 0;
    }
}
