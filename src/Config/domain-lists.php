<?php

/**
 * Domain lists for traffic channel classification.
 *
 * This configuration file contains domain patterns for identifying search engines,
 * social media platforms, AI tools, and webmail providers. Supports wildcard matching
 * via fnmatch() for flexible domain detection (e.g., "*.google.*" matches google.com,
 * google.co.uk, etc.).
 *
 * **Maintenance**: Update these lists when new platforms emerge or existing domains change.
 * Lists should be reviewed quarterly as part of plugin maintenance.
 *
 * @package SlimStat\Config
 * @since 5.1.0
 */

return [
    /**
     * Search engine domains (FR-025).
     *
     * Domains matching this list will be classified as "Organic Search" unless
     * utm_medium indicates paid traffic (cpc/ppc/paid).
     *
     * Wildcards supported for subdomain matching (e.g., "*.google.*").
     */
    'SEARCH_DOMAINS' => [
        // Google ecosystem
        'google.com',
        'google.co.uk',
        'google.ca',
        'google.de',
        'google.fr',
        'google.com.br',
        'google.com.au',
        'google.co.in',
        'google.co.jp',
        'google.es',
        'google.it',
        'google.nl',
        'google.pl',
        'google.ru',
        '*.google.*', // Catch-all for other Google TLDs

        // Microsoft Bing
        'bing.com',
        '*.bing.*',

        // Yahoo
        'yahoo.com',
        'search.yahoo.com',
        '*.yahoo.*',

        // DuckDuckGo
        'duckduckgo.com',
        '*.duckduckgo.*',

        // Baidu (China)
        'baidu.com',
        'www.baidu.com',
        '*.baidu.*',

        // Yandex (Russia)
        'yandex.ru',
        'yandex.com',
        '*.yandex.*',

        // Other search engines
        'ask.com',
        'aol.com',
        'search.aol.com',
        'ecosia.org', // Eco-friendly search
        'qwant.com', // Privacy-focused search
        'startpage.com', // Privacy-focused search
        'brave.com',
        'search.brave.com',
        'swisscows.com',
        'mojeek.com',
    ],

    /**
     * Social media platform domains (FR-027).
     *
     * Domains matching this list will be classified as "Social".
     */
    'SOCIAL_DOMAINS' => [
        // Facebook ecosystem
        'facebook.com',
        'fb.com',
        'm.facebook.com',
        'l.facebook.com', // Link shortener
        'lm.facebook.com', // Mobile link shortener
        '*.facebook.*',

        // Twitter/X ecosystem
        'twitter.com',
        'x.com',
        't.co', // Twitter shortener
        'mobile.twitter.com',
        '*.twitter.*',

        // LinkedIn
        'linkedin.com',
        'lnkd.in', // LinkedIn shortener
        '*.linkedin.*',

        // Instagram
        'instagram.com',
        'instagr.am',
        '*.instagram.*',

        // TikTok
        'tiktok.com',
        '*.tiktok.*',

        // Pinterest
        'pinterest.com',
        'pin.it', // Pinterest shortener
        '*.pinterest.*',

        // Reddit
        'reddit.com',
        'redd.it', // Reddit shortener
        '*.reddit.*',

        // YouTube
        'youtube.com',
        'youtu.be', // YouTube shortener
        'm.youtube.com',
        '*.youtube.*',

        // Other social platforms
        'snapchat.com',
        'whatsapp.com',
        'telegram.org',
        'telegram.me',
        'discord.com',
        'discord.gg',
        'tumblr.com',
        'vk.com', // VKontakte (Russia)
        'weibo.com', // Sina Weibo (China)
        'line.me', // LINE (Japan)
        'kakao.com', // KakaoTalk (South Korea)
        'threads.net', // Threads (Meta)
        'mastodon.social',
        'pixelfed.social',
    ],

    /**
     * AI tool and platform domains (FR-029).
     *
     * Domains matching this list will be classified as "AI". Includes both
     * direct AI tool interfaces (ChatGPT, Claude) and AI-powered search
     * results (Bing Chat, Perplexity, SearchGPT).
     */
    'AI_DOMAINS' => [
        // OpenAI ecosystem
        'chat.openai.com',
        'chatgpt.com',
        'openai.com',

        // Anthropic Claude
        'claude.ai',
        'anthropic.com',

        // Bing Chat (Microsoft Copilot)
        'bing.com/chat',
        'copilot.microsoft.com',

        // Perplexity AI
        'perplexity.ai',
        'www.perplexity.ai',

        // SearchGPT (OpenAI)
        'searchgpt.com',

        // You.com (AI search)
        'you.com',

        // Phind (AI code search)
        'phind.com',
        'www.phind.com',

        // Google Bard/Gemini
        'bard.google.com',
        'gemini.google.com',

        // Other AI assistants
        'huggingface.co',
        'character.ai',
        'poe.com', // Quora's AI platform
        'writesonic.com',
        'jasper.ai',
    ],

    /**
     * Webmail provider domains (FR-028).
     *
     * Domains matching this list will be classified as "Email" when appearing
     * as referrers. Note: utm_medium="email" takes priority over referrer detection.
     */
    'WEBMAIL_DOMAINS' => [
        // Gmail
        'mail.google.com',
        'gmail.com',

        // Outlook / Hotmail
        'outlook.live.com',
        'outlook.com',
        'hotmail.com',
        'live.com',

        // Yahoo Mail
        'mail.yahoo.com',
        'mail.yahoo.co.uk',
        '*.mail.yahoo.*',

        // Apple iCloud Mail
        'icloud.com',
        'me.com',
        'mac.com',

        // ProtonMail
        'protonmail.com',
        'proton.me',

        // AOL Mail
        'mail.aol.com',
        'aol.com',

        // Other webmail providers
        'mail.com',
        'gmx.com',
        'gmx.net',
        'zoho.com',
        'yandex.mail',
        'mail.ru',
    ],
];
