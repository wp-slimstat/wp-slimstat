<?php

namespace WpSlimstat\Tests\Unit\Tracker;

use PHPUnit\Framework\TestCase;
use SlimStat\Tracker\Acquisition;

class AcquisitionTest extends TestCase
{
    /** @dataProvider channels */
    public function test_channel_precedence_and_host_boundaries(string $query, string $ref, string $ua, int $bot, string $expected): void
    {
        $result = Acquisition::classify(Acquisition::parameters('/?' . $query), $ref, $ua, $bot, 'https://example.org');
        self::assertSame($expected, $result['traffic_channel']);
    }

    public static function channels(): array
    {
        $rows = [
            ['', '', '', 0, 'direct'],
            ['', 'https://example.org/path', '', 0, 'internal'],
            ['', 'https://WWW.EXAMPLE.ORG./path', '', 0, 'internal'],
            ['', 'https://example.org.evil.test/path', '', 0, 'referral'],
            ['', 'https://example.org@evil.test', '', 0, 'referral'],
            ['', 'https://www.google.com/search?q=hello', '', 0, 'organic_search'],
            ['', 'https://www.google.co.uk/search', '', 0, 'organic_search'],
            ['', 'https://www.google.de/search', '', 0, 'organic_search'],
            ['', 'https://google.com.evil.test/', '', 0, 'referral'],
            ['', 'https://notgoogle.com/', '', 0, 'referral'],
            ['', 'https://evil.test/?next=https://chatgpt.com', '', 0, 'referral'],
            ['', 'https://mail.google.com/mail/', '', 0, 'email'],
            ['', 'https://docs.google.com/document', '', 0, 'referral'],
            ['', 'https://gemini.google.com/app', '', 0, 'ai_assistant'],
            ['', 'https://kagi.com/search', '', 0, 'organic_search'],
            ['', 'https://assistant.kagi.com/', '', 0, 'ai_assistant'],
            ['', 'https://l.facebook.com/', '', 0, 'organic_social'],
            ['', 'https://youtu.be/123', '', 0, 'organic_video'],
            ['', 'https://amazon.de/', '', 0, 'organic_shopping'],
            ['', 'https://t.me/channel', '', 0, 'messaging'],
            ['', 'android-app://com.google.android.googlequicksearchbox/', '', 0, 'organic_search'],
            ['', 'android-app://com.google.android.gm/', '', 0, 'email'],
            ['', 'javascript:alert(1)', '', 0, 'direct'],
            ['', '//chatgpt.com/', '', 0, 'direct'],
            ['', 'https://chatgpt.com/', 'GPTBot/1.4', 0, 'ai_crawler'],
            ['', '', 'Mozilla/5.0 (compatible; ChatGPT-User/1.0; +https://openai.com/bot)', 1, 'ai_fetcher'],
            ['', '', 'Mozilla/5.0 [FBAN/FBIOS;FBAV/1]', 0, 'organic_social'],
            ['', '', 'Mozilla/5.0 Instagram 320.0', 0, 'organic_social'],
            ['', 'https://example.org/path', 'Mozilla/5.0 Instagram 320.0', 0, 'internal'],
            ['', '', 'NotGPTBot/1.0', 0, 'direct'],
            ['', '', 'Googlebot/2.1', 1, 'bot'],
            ['utm_source=newsletter&utm_medium=email', 'https://google.com', 'GPTBot/1.0', 0, 'ai_crawler'],
            ['utm_source=newsletter&utm_medium=EMAIL', 'https://google.com', '', 0, 'email'],
            ['utm_source=Google&utm_medium=CPC', 'https://facebook.com', '', 0, 'paid_search'],
            ['utm_source=facebook&utm_medium=paid', '', '', 0, 'paid_social'],
            ['utm_source=youtube&utm_medium=ppc', '', '', 0, 'paid_video'],
            ['utm_source=amazon&utm_medium=cpc', '', '', 0, 'paid_shopping'],
            ['utm_source=unknown&utm_medium=cpc', 'https://google.com', '', 0, 'paid_other'],
            ['utm_medium=cpc', 'https://google.com', '', 0, 'paid_other'],
            ['utm_medium=email', 'https://example.org', '', 0, 'email'],
            ['utm_source=chatgpt.com', '', '', 0, 'ai_assistant'],
            ['utm_source=claude&utm_medium=referral', '', '', 0, 'ai_assistant'],
            ['utm_source=chatgpt&utm_medium=cpc', '', '', 0, 'paid_other'],
            ['utm_source=google&utm_medium=odd', '', '', 0, 'unassigned'],
            ['utm_campaign=OnlyCampaign', 'https://google.com', '', 0, 'unassigned'],
            ['utm_content=0', '', '', 0, 'unassigned'],
            ['gclid=opaque', 'https://google.com', '', 0, 'paid_other'],
            ['msclkid=opaque', '', '', 0, 'paid_other'],
            ['wbraid=opaque', '', '', 0, 'paid_other'],
            ['gbraid=opaque', '', '', 0, 'paid_other'],
            ['dclid=opaque', '', '', 0, 'display'],
            ['fbclid=opaque', 'https://facebook.com', '', 0, 'organic_social'],
            ['fbclid=opaque', '', '', 0, 'direct'],
        ];
        foreach (['paid_social', 'paid_search', 'paid_video', 'paid_shopping', 'display', 'audio', 'sms', 'push', 'email', 'affiliate', 'messaging'] as $medium) {
            $rows[] = ['utm_medium=' . $medium, '', '', 0, $medium];
        }
        foreach (['social' => 'organic_social', 'organic' => 'organic_search', 'video' => 'organic_video', 'ai-assistant' => 'ai_assistant', 'cross-network' => 'cross_network', 'referral' => 'referral'] as $medium => $channel) {
            $rows[] = ['utm_medium=' . $medium, '', '', 0, $channel];
        }
        foreach (['chatgpt.com', 'chat.openai.com', 'perplexity.ai', 'perplexity.com', 'claude.ai', 'copilot.microsoft.com', 'grok.com', 'chat.deepseek.com', 'chat.mistral.ai', 'chat.qwen.ai', 'poe.com', 'you.com', 'meta.ai', 'phind.com'] as $host) {
            $rows[] = ['', 'https://' . $host, '', 0, 'ai_assistant'];
            $rows[] = ['', 'https://' . $host . '.evil.test', '', 0, 'referral'];
        }
        foreach (['GPTBot', 'OAI-SearchBot', 'OAI-AdsBot', 'ClaudeBot', 'Claude-SearchBot', 'PerplexityBot', 'Bytespider', 'CCBot'] as $bot) {
            $rows[] = ['', '', $bot . '/1.0', 0, 'ai_crawler'];
        }
        foreach (['ChatGPT-User', 'Claude-User', 'Perplexity-User'] as $bot) {
            $rows[] = ['', '', $bot . '/1.0', 0, 'ai_fetcher'];
        }
        return $rows;
    }

    public function test_encoded_values_and_case_survive_exactly_once(): void
    {
        self::assertSame([
            'utm_source' => 'A&B', 'utm_medium' => 'Email', 'utm_campaign' => 'Summer + 20% off',
            'utm_term' => 'café 日本', 'utm_content' => '0', 'utm_id' => '%20',
        ], Acquisition::parameters('/?utm_source=A%26B&utm_medium=Email&utm_campaign=Summer+%2B+20%25+off&utm_term=caf%C3%A9+%E6%97%A5%E6%9C%AC&utm_content=0&utm_id=%2520#utm_source=bad'));
    }

    public function test_arrays_duplicate_tags_fragments_and_invalid_utf8_are_not_trusted(): void
    {
        self::assertSame([], Acquisition::parameters('/?utm_source[]=evil&utm_medium=x&utm_medium=y&utm_medium=z&utm_campaign=%FF#utm_term=bad'));
        self::assertSame([], Acquisition::parameters('/#utm_source=google'));
        self::assertSame([], Acquisition::parameters('/?UTM_SOURCE=google'));
        self::assertSame([], Acquisition::parameters('/?utm_source=google&utm_source[]=evil'));
        self::assertSame([], Acquisition::parameters('/?utm_source[]=evil&utm_source=google'));
        self::assertSame('', Acquisition::clean(['malformed']));
    }

    public function test_values_are_bounded_and_html_is_removed(): void
    {
        self::assertSame(str_repeat('界', 191), Acquisition::clean(str_repeat('界', 250)));
        self::assertSame('Sale 2026', Acquisition::clean('<b>Sale</b>' . "\n" . '2026'));
        self::assertSame('A%20B', Acquisition::clean('A%20B'));
        self::assertSame([], Acquisition::parameters('/?utm_source=google&padding=' . str_repeat('x', 16384) . '&utm_source=other'));
        self::assertSame([], Acquisition::aiAgent('Applebot-Extended'));
    }

    public function test_raw_source_case_is_not_lost_by_channel_normalization(): void
    {
        $params = Acquisition::parameters('/?utm_source=GOOGLE&utm_medium=CPC');
        $result = Acquisition::classify($params, '', '', 0, 'https://example.org');
        self::assertSame('GOOGLE', $params['utm_source']);
        self::assertSame('google', $result['traffic_source']);
        $zero = Acquisition::classify(['utm_source' => '0', 'gclid' => 'opaque'], '', '', 0, 'https://example.org');
        self::assertSame('0', $zero['traffic_source']);
    }
}
