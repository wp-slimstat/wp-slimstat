<?php

/**
 * Traffic channel taxonomy configuration.
 *
 * Defines the 8 channel categories used for traffic classification with metadata
 * for display, description, and styling purposes.
 *
 * @package SlimStat\Config
 * @since 5.1.0
 */

return [
    'Direct' => [
        'name' => __('Direct', 'wp-slimstat'),
        'description' => __('Visitors who typed your URL directly, used a bookmark, or came from sources without referrer information.', 'wp-slimstat'),
        'icon' => 'dashicons-admin-home',
        'color' => '#2271b1', // WordPress blue
        'order' => 1,
        'examples' => [
            'Browser bookmark',
            'Typed URL directly',
            'Mobile app (non-browser)',
            'Referrer blocked by privacy settings',
        ],
    ],

    'Organic Search' => [
        'name' => __('Organic Search', 'wp-slimstat'),
        'description' => __('Visitors who found your site through unpaid search engine results (Google, Bing, etc.).', 'wp-slimstat'),
        'icon' => 'dashicons-search',
        'color' => '#00a32a', // Green
        'order' => 2,
        'examples' => [
            'Google organic results',
            'Bing natural search',
            'DuckDuckGo search',
            'Yahoo search results',
        ],
    ],

    'Paid Search' => [
        'name' => __('Paid Search', 'wp-slimstat'),
        'description' => __('Visitors who clicked on paid search ads (Google Ads, Microsoft Advertising, etc.).', 'wp-slimstat'),
        'icon' => 'dashicons-money-alt',
        'color' => '#d63638', // Red
        'order' => 3,
        'examples' => [
            'Google Ads (CPC)',
            'Bing Ads',
            'Search campaigns with utm_medium=cpc',
            'Paid search with gclid parameter',
        ],
    ],

    'Social' => [
        'name' => __('Social', 'wp-slimstat'),
        'description' => __('Visitors from social media platforms (Facebook, Twitter/X, LinkedIn, Instagram, etc.).', 'wp-slimstat'),
        'icon' => 'dashicons-share',
        'color' => '#8e44ad', // Purple
        'order' => 4,
        'examples' => [
            'Facebook post link',
            'Twitter/X tweet',
            'LinkedIn share',
            'Instagram bio link',
        ],
    ],

    'Email' => [
        'name' => __('Email', 'wp-slimstat'),
        'description' => __('Visitors who clicked links in email campaigns or webmail interfaces.', 'wp-slimstat'),
        'icon' => 'dashicons-email-alt',
        'color' => '#e67e22', // Orange
        'order' => 5,
        'examples' => [
            'Newsletter campaign (utm_medium=email)',
            'Gmail webmail link',
            'Outlook email link',
            'Email signature link',
        ],
    ],

    'AI' => [
        'name' => __('AI', 'wp-slimstat'),
        'description' => __('Visitors from AI assistants and AI-powered search tools (ChatGPT, Claude, Perplexity, SearchGPT, etc.).', 'wp-slimstat'),
        'icon' => 'dashicons-lightbulb',
        'color' => '#00d9ff', // Cyan
        'order' => 6,
        'examples' => [
            'ChatGPT chat interface',
            'Claude AI assistant',
            'Bing Chat / Copilot',
            'Perplexity AI search',
        ],
    ],

    'Referral' => [
        'name' => __('Referral', 'wp-slimstat'),
        'description' => __('Visitors from other websites, blogs, forums, or directories that linked to your content.', 'wp-slimstat'),
        'icon' => 'dashicons-admin-links',
        'color' => '#16a085', // Teal
        'order' => 7,
        'examples' => [
            'Blog post backlink',
            'News article mention',
            'Forum discussion link',
            'Directory listing',
        ],
    ],

    'Other' => [
        'name' => __('Other', 'wp-slimstat'),
        'description' => __('Visitors from sources that don\'t fit into other categories. Catchall for unclassified traffic.', 'wp-slimstat'),
        'icon' => 'dashicons-warning',
        'color' => '#7e8c8d', // Gray
        'order' => 8,
        'examples' => [
            'Unknown referrer patterns',
            'New platforms not yet categorized',
            'Edge cases in classification logic',
            'Fallback for unmatched traffic',
        ],
    ],
];
