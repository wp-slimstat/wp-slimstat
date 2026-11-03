<?php
/** @license GPL-2.0-or-later */
namespace WpSlimstat\Tests\Unit\Reports;
use Brain\Monkey\Functions;
use SlimStat\Reports\AiPromptButton;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;
if (!defined('ABSPATH')) { exit; }
class AiPromptButtonTest extends WpSlimstatTestCase
{
    private const NO_FILTERS = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();
        Functions\when('wp_strip_all_tags')->alias(static fn($text) => trim(strip_tags((string) $text)));
        Functions\when('sanitize_key')->alias(static fn($key) => preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)));
        Functions\when('admin_url')->alias(static fn($path = '') => 'https://example.test/wp-admin/' . $path);
        Functions\when('add_query_arg')->alias(static fn(array $args, string $url) => $url . '&' . http_build_query($args));
        Functions\when('current_user_can')->justReturn(true);
    }

    public function test_free_report_gets_a_copy_button_with_escaped_report_context(): void
    {
        $report = ['title' => 'Top <b>Pages</b>', 'tooltip' => 'Pages <strong>"people"</strong> read.<br>Bots excluded.', 'callback_args' => ['columns' => 'resource', 'raw' => 'x']];
        $html = AiPromptButton::html('slim_p1_08', $report, ['browser' => ['equals', 'Firefox']], false);
        self::assertStringStartsWith('<button type="button" class="slimstat-ai-prompt noslimstat"', $html);
        self::assertStringContainsString('Copy AI Prompt', $html);
        self::assertStringContainsString('title="Click to copy this report as an AI prompt, then paste it into ChatGPT, Claude, Grok, or any AI tool you use."', $html);
        self::assertStringContainsString('aria-description="Click to copy', $html);
        self::assertStringContainsString('data-title="Top Pages"', $html);
        self::assertStringContainsString('data-about="Pages &quot;people&quot; read. Bots excluded."', $html);
        self::assertStringNotContainsString('<b>', $html);
    }

    public function test_ecommerce_and_heatmap_summaries_keep_the_personal_filter_guard(): void
    {
        foreach (['slim_p10_01', 'slimheatmap'] as $id) {
            self::assertStringContainsString('<button', AiPromptButton::html($id, ['title' => 'Summary'], [], false));
            self::assertSame('', AiPromptButton::html($id, ['title' => 'Summary'], ['email' => ['equals', 'person@example.test']], true));
            self::assertSame('', AiPromptButton::html($id, ['title' => 'Summary', 'callback_args' => ['columns' => 'email']], [], true));
        }
    }

    public function test_reports_about_individual_visitors_get_no_button(): void
    {
        foreach ([
            'listed staff report' => ['slim_p7_02', ['title' => 'Access Log', 'callback_args' => ['columns' => 'id']]],
            'personal column'     => ['slim_x', ['title' => 'IPs', 'callback_args' => ['columns' => 'ip']]],
            'every column'        => ['slim_y', ['title' => 'Rows', 'callback_args' => ['columns' => '*']]],
        ] as $case => [$id, $report]) {
            self::assertSame('', AiPromptButton::html($id, $report, self::NO_FILTERS, true), $case);
        }
        // A view narrowed to one visitor is about that visitor, whatever the report.
        foreach (['ip' => '192.0.2.1', 'visit_id' => '42', 'notes' => 'user:777', 'location' => '52.52,13.40'] as $column => $value) {
            self::assertSame('', AiPromptButton::html('slim_p1_08', ['title' => 'Top Pages'], [$column => ['equals', $value]], true), $column);
        }
        self::assertSame('', AiPromptButton::html('slim_p1_08', [], self::NO_FILTERS, true), 'unknown report');
    }

    public function test_pro_report_is_locked_without_pro_and_works_with_it(): void
    {
        $funnels = ['title' => 'Funnels', 'callback_args' => []];
        $locked = AiPromptButton::html('slim_p9_02', $funnels, self::NO_FILTERS, false);
        self::assertStringStartsWith('<a class="slimstat-ai-prompt slimstat-ai-prompt-locked noslimstat" href="', $locked);
        self::assertStringContainsString('page=slimpro&utm_campaign=ai-prompt&utm_content=slim_p9_02"', $locked);
        self::assertStringNotContainsString('data-', $locked, 'a locked button carries no report data');
        self::assertStringStartsWith('<button', AiPromptButton::html('slim_p9_02', $funnels, self::NO_FILTERS, true));

        // No purchase link for someone who cannot buy.
        Functions\when('current_user_can')->justReturn(false);
        self::assertSame('', AiPromptButton::html('slim_p9_02', $funnels, self::NO_FILTERS, false));
    }
}
