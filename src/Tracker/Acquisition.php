<?php

namespace SlimStat\Tracker;

/** Pageview-scoped attribution. No network calls, cookies or database lookups. */
class Acquisition
{
    public const UTM_FIELDS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id'];
    public const COLUMNS = ['traffic_channel', 'traffic_source', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id'];

    // Exact hosts or their subdomains only. Never match arbitrary substrings in a URL.
    private const SOURCES = [
        'ai_assistant' => [
            'chatgpt.com', 'chat.openai.com', 'perplexity.ai', 'perplexity.com', 'claude.ai',
            'gemini.google.com', 'bard.google.com', 'copilot.microsoft.com', 'grok.com',
            'you.com', 'phind.com', 'chat.deepseek.com', 'chat.mistral.ai', 'chat.qwen.ai',
            'meta.ai', 'poe.com', 'assistant.kagi.com',
        ],
        'organic_video' => ['youtube.com', 'youtu.be', 'tiktok.com', 'vimeo.com', 'dailymotion.com', 'twitch.tv', 'bilibili.com', 'rumble.com'],
        'organic_social' => [
            'facebook.com', 'fb.com', 'fb.me', 'instagram.com', 'threads.net', 'threads.com',
            'twitter.com', 'x.com', 't.co', 'linkedin.com', 'lnkd.in', 'pinterest.com',
            'pin.it', 'reddit.com', 'redd.it', 'snapchat.com', 'bsky.app', 'bsky.social',
            'mastodon.social', 'tumblr.com', 'vk.com', 'weibo.com', 'quora.com',
        ],
        'organic_shopping' => ['amazon.com', 'amazon.co.uk', 'amazon.de', 'ebay.com', 'ebay.co.uk', 'ebay.de', 'etsy.com', 'aliexpress.com', 'shopping.google.com'],
        'organic_search' => ['google.com', 'bing.com', 'duckduckgo.com', 'search.yahoo.com', 'yahoo.co.jp', 'baidu.com', 'yandex.com', 'yandex.ru', 'ecosia.org', 'search.brave.com', 'startpage.com', 'qwant.com', 'naver.com', 'search.daum.net', 'seznam.cz', 'sogou.com', 'so.com', 'kagi.com'],
        'email' => ['mail.google.com', 'outlook.live.com', 'outlook.office.com', 'mail.yahoo.com', 'mail.proton.me'],
        'referral' => ['docs.google.com', 'drive.google.com', 'calendar.google.com', 'forms.google.com', 'meet.google.com'],
        'messaging' => ['web.whatsapp.com', 'whatsapp.com', 'telegram.org', 't.me', 'web.telegram.org', 'discord.com', 'discord.gg', 'slack.com', 'teams.microsoft.com', 'messenger.com'],
    ];

    private const ALIASES = [
        'chatgpt' => 'chatgpt.com', 'openai' => 'chatgpt.com', 'perplexity' => 'perplexity.ai',
        'claude' => 'claude.ai', 'gemini' => 'gemini.google.com', 'copilot' => 'copilot.microsoft.com',
        'grok' => 'grok.com', 'deepseek' => 'chat.deepseek.com', 'mistral' => 'chat.mistral.ai',
        'qwen' => 'chat.qwen.ai', 'google' => 'google.com', 'bing' => 'bing.com',
        'yahoo' => 'search.yahoo.com', 'duckduckgo' => 'duckduckgo.com', 'baidu' => 'baidu.com',
        'yandex' => 'yandex.ru', 'ecosia' => 'ecosia.org', 'brave' => 'search.brave.com',
        'facebook' => 'facebook.com', 'fb' => 'facebook.com', 'instagram' => 'instagram.com',
        'ig' => 'instagram.com', 'linkedin' => 'linkedin.com', 'twitter' => 'twitter.com',
        'pinterest' => 'pinterest.com', 'reddit' => 'reddit.com', 'snapchat' => 'snapchat.com',
        'youtube' => 'youtube.com', 'tiktok' => 'tiktok.com', 'vimeo' => 'vimeo.com',
        'amazon' => 'amazon.com', 'ebay' => 'ebay.com', 'etsy' => 'etsy.com',
    ];

    /** The marker describes this analytics destination, including custom databases. */
    public static function readinessKey(): string
    {
        $db = \wp_slimstat::$wpdb ?? $GLOBALS['wpdb'];
        return 'slimstat_acquisition_' . md5(($db->dbhost ?? '') . '|' . ($db->dbname ?? '') . '|' . $GLOBALS['wpdb']->prefix);
    }

    /** Admin/report path only. Tracking reads an autoloaded marker, never probes schema. */
    public static function checkSchema(): bool
    {
        $db = \wp_slimstat::$wpdb ?? $GLOBALS['wpdb'];
        $table = str_replace('`', '', $GLOBALS['wpdb']->prefix . 'slim_stats');
        $suppressed = $db->suppress_errors(true);
        try {
            $columns = $db->get_col("SHOW COLUMNS FROM `{$table}`");
            $ready = '' === (string) $db->last_error && is_array($columns) && !array_diff(self::COLUMNS, $columns);
        } finally {
            $db->suppress_errors($suppressed);
        }
        update_option(self::readinessKey(), $ready ? '1' : '0', true);
        return $ready;
    }

    public static function labels(): array
    {
        return [
            'direct' => __('Direct / unknown', 'wp-slimstat'),
            'organic_search' => __('Organic Search', 'wp-slimstat'),
            'paid_search' => __('Paid Search', 'wp-slimstat'),
            'organic_social' => __('Organic Social', 'wp-slimstat'),
            'paid_social' => __('Paid Social', 'wp-slimstat'),
            'organic_video' => __('Organic Video', 'wp-slimstat'),
            'paid_video' => __('Paid Video', 'wp-slimstat'),
            'organic_shopping' => __('Organic Shopping', 'wp-slimstat'),
            'paid_shopping' => __('Paid Shopping', 'wp-slimstat'),
            'email' => __('Email', 'wp-slimstat'),
            'referral' => __('Referral', 'wp-slimstat'),
            'affiliate' => __('Affiliates', 'wp-slimstat'),
            'display' => __('Display', 'wp-slimstat'),
            'audio' => __('Audio', 'wp-slimstat'),
            'sms' => __('SMS', 'wp-slimstat'),
            'push' => __('Push Notifications', 'wp-slimstat'),
            'messaging' => __('Messaging', 'wp-slimstat'),
            'cross_network' => __('Cross-network', 'wp-slimstat'),
            'paid_other' => __('Paid Other', 'wp-slimstat'),
            'ai_assistant' => __('AI Assistants', 'wp-slimstat'),
            'ai_crawler' => __('AI Crawlers', 'wp-slimstat'),
            'ai_fetcher' => __('AI User-requested Fetches', 'wp-slimstat'),
            'bot' => __('Other Bots', 'wp-slimstat'),
            'internal' => __('Internal Navigation', 'wp-slimstat'),
            'unassigned' => __('Unassigned', 'wp-slimstat'),
        ];
    }

    /** Sanitize decoded values without stripping literal percent sequences or changing case. */
    public static function clean($value): string
    {
        if (!is_string($value) || !preg_match('//u', $value)) {
            return '';
        }
        $value = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', wp_strip_all_tags($value)));
        // UTF-8 safe on PHP installations without mbstring, at most 191 characters.
        preg_match('/^.{0,191}/us', $value, $match);
        return $match[0] ?? '';
    }

    /** Decode once, preserve '+' semantics, reject arrays and ambiguous repeated parameters. */
    public static function parameters(string $resource): array
    {
        $query = wp_parse_url($resource, PHP_URL_QUERY);
        if (!is_string($query) || strlen($query) > 16384) {
            return [];
        }
        $allowed = array_merge(self::UTM_FIELDS, ['gclid', 'dclid', 'msclkid', 'gbraid', 'wbraid']);
        $values = [];
        $seen = [];
        // Oversized queries are unknown; never attribute a truncated, ambiguous parameter set.
        foreach (explode('&', $query) as $pair) {
            $parts = explode('=', $pair, 2);
            $key = urldecode($parts[0]);
            $root = strstr($key, '[', true);
            if (false !== $root && in_array($root, $allowed, true)) {
                $seen[$root] = true;
                unset($values[$root]);
                continue;
            }
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            if (isset($seen[$key])) {
                unset($values[$key]);
                continue;
            }
            $seen[$key] = true;
            $value = self::clean(urldecode($parts[1] ?? ''));
            if ('' !== $value) {
                $values[$key] = $value;
            }
        }
        return $values;
    }

    /** Claimed agent identity, not IP-verified bot authentication. */
    public static function aiAgent(string $agent): array
    {
        if (preg_match('/(?:^|[\s;(])((?:ChatGPT|Claude|Perplexity)-User)(?:\/|[\s;)]|$)/i', $agent, $m)) {
            return ['ai_fetcher', strtolower($m[1])];
        }
        if (preg_match('/(?:^|[\s;(])(GPTBot|OAI-SearchBot|OAI-AdsBot|ClaudeBot|Claude-SearchBot|anthropic-ai|PerplexityBot|cohere-ai|cohere-training-data-crawler|CCBot|Bytespider|Amazonbot)(?:\/|[\s;)]|$)/i', $agent, $m)) {
            return ['ai_crawler', strtolower($m[1])];
        }
        return [];
    }

    private static function host(string $url): string
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['https', 'http', 'android-app'], true)) {
            return '';
        }
        return strtolower(rtrim($parts['host'] ?? '', '.'));
    }

    private static function matches(string $host, string $domain): bool
    {
        return $host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain;
    }

    private static function category(string $source): string
    {
        $source = self::ALIASES[$source] ?? $source;
        // Specific subdomains win over broad domains, e.g. Gmail before google.com.
        $category = '';
        $length = 0;
        foreach (self::SOURCES as $name => $domains) {
            foreach ($domains as $domain) {
                if (strlen($domain) > $length && self::matches($source, $domain)) {
                    $category = $name;
                    $length = strlen($domain);
                }
            }
        }
        if ('' !== $category) {
            return $category;
        }
        // Country-domain engines: anchored and restricted to their real suffix shapes.
        if (preg_match('/^(?:www\.)?(?:google\.(?:[a-z]{2}|co\.[a-z]{2}|com\.[a-z]{2})|yandex\.(?:by|kz|com\.tr))$/D', $source)) {
            return 'organic_search';
        }
        return '';
    }

    public static function classify(array $params, string $referer, string $agent, int $browserType, string $siteUrl): array
    {
        $ai = self::aiAgent($agent);
        if ($ai) {
            return ['traffic_channel' => $ai[0], 'traffic_source' => $ai[1]];
        }
        if (1 === $browserType) {
            return ['traffic_channel' => 'bot', 'traffic_source' => ''];
        }

        $host = self::host($referer);
        $site = self::host($siteUrl);
        $internal = '' !== $host && preg_replace('/^www\./', '', $host) === preg_replace('/^www\./', '', $site);
        $source = strtolower($params['utm_source'] ?? '');
        $medium = strtolower($params['utm_medium'] ?? '');
        $hasUtm = (bool) array_intersect_key($params, array_flip(self::UTM_FIELDS));
        // Explicit tagging must not borrow a conflicting referrer's category.
        $source = '' !== $source ? $source : ($hasUtm || $internal ? '' : $host);
        if (!$hasUtm && '' === $host) {
            // In-app browsers identify the app, never whether its link was an ad.
            if (preg_match('/\bFB(?:AN|AV)[\/;]/i', $agent)) {
                $source = 'facebook.com';
            } elseif (preg_match('/\bInstagram[\/ ]/i', $agent)) {
                $source = 'instagram.com';
            }
        }
        $category = self::category(self::host($source) ?: $source);
        $channel = '';

        $mediumChannels = [
            'email' => 'email', 'e-mail' => 'email', 'e_mail' => 'email', 'e mail' => 'email',
            'affiliate' => 'affiliate', 'affiliates' => 'affiliate', 'audio' => 'audio', 'sms' => 'sms',
            'push' => 'push', 'mobile_push' => 'push', 'notification' => 'push',
            'display' => 'display', 'banner' => 'display', 'cpm' => 'display', 'interstitial' => 'display',
            'paid_social' => 'paid_social', 'paid-social' => 'paid_social', 'paidsocial' => 'paid_social',
            'paid_search' => 'paid_search', 'paid-search' => 'paid_search',
            'paid_video' => 'paid_video', 'paid-video' => 'paid_video',
            'paid_shopping' => 'paid_shopping', 'paid-shopping' => 'paid_shopping',
            'cross-network' => 'cross_network', 'cross_network' => 'cross_network',
            'ai-assistant' => 'ai_assistant', 'ai_assistant' => 'ai_assistant',
            'messaging' => 'messaging', 'messenger' => 'messaging',
        ];
        if (isset($mediumChannels[$medium])) {
            $channel = $mediumChannels[$medium];
        } elseif (preg_match('/^(?:cpc|ppc|cpa|cpv|cpe|paid|paid.*)$/D', $medium)) {
            $paid = ['organic_search' => 'paid_search', 'organic_social' => 'paid_social', 'organic_video' => 'paid_video', 'organic_shopping' => 'paid_shopping'];
            $channel = $paid[$category] ?? 'paid_other';
        } elseif ('organic' === $medium) {
            $channel = 'organic_search';
        } elseif (in_array($medium, ['social', 'social-network', 'social-media', 'sm', 'social network', 'social media'], true)) {
            $channel = 'organic_social';
        } elseif ('video' === $medium) {
            $channel = 'organic_video';
        } elseif (in_array($medium, ['referral', 'app', 'link'], true)) {
            $channel = 'ai_assistant' === $category ? $category : 'referral';
        } elseif ('' !== $medium && !in_array($medium, ['(none)', 'none', '(not set)'], true)) {
            $channel = 'unassigned';
        } elseif (isset($params['gclid']) || isset($params['gbraid']) || isset($params['wbraid']) || isset($params['msclkid']) || isset($params['dclid'])) {
            // Click IDs prove paid traffic, not the ad network/campaign type.
            $channel = isset($params['dclid']) ? 'display' : 'paid_other';
            $source = '' !== $source ? $source : (isset($params['msclkid']) ? 'bing' : 'google');
        } elseif ('' !== $category) {
            $channel = $category;
        } elseif (in_array($source, ['email', 'e-mail', 'e_mail', 'e mail', 'sms', 'firebase'], true)) {
            $channel = 'firebase' === $source ? 'push' : ('sms' === $source ? 'sms' : 'email');
        } elseif ($hasUtm) {
            $channel = 'unassigned';
        } elseif ($internal) {
            $channel = 'internal';
        } elseif ('' !== $host) {
            $channel = 'referral';
            // Android package names are not DNS domains.
            $apps = ['com.google.android.googlequicksearchbox' => 'organic_search', 'com.google.android.gm' => 'email', 'com.facebook.katana' => 'organic_social', 'com.instagram.android' => 'organic_social'];
            if (0 === strpos(strtolower($referer), 'android-app://')) {
                $channel = $apps[$host] ?? 'referral';
            }
        } else {
            $channel = 'direct';
        }

        return ['traffic_channel' => $channel, 'traffic_source' => self::clean($source)];
    }
}
