<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Anti-drift guard for the funnel templates (#5).
 *
 * The step definitions live in JS (FUNNEL_TEMPLATES in goals-funnels.js) while
 * the human-readable cards live in PHP ($template_cards in funnels-card.php).
 * The card's data-template key must map 1:1 to a JS template, or clicking a card
 * opens the blank fallback instead of the advertised steps — the symptom the
 * issue screenshots showed. Also pins the corrected WooCommerce order-received
 * path (/checkout/order-received, WooCommerce's nested default).
 */
class GoalsFunnelsTemplatesTest extends TestCase
{
    private string $js;
    private string $card;

    protected function setUp(): void
    {
        parent::setUp();
        $root       = dirname(__DIR__, 2);
        $this->js   = (string) file_get_contents($root . '/admin/assets/js/goals-funnels.js');
        $this->card = (string) file_get_contents($root . '/admin/view/partials/goals-funnels/funnels-card.php');
        if ($this->js === '' || $this->card === '') {
            $this->fail('Could not read goals-funnels.js / funnels-card.php');
        }
    }

    /** Keys defined in the JS FUNNEL_TEMPLATES object. */
    private function jsTemplateKeys(): array
    {
        if (!preg_match('/var FUNNEL_TEMPLATES = \{(.*?)\n    \};/s', $this->js, $m)) {
            $this->fail('Could not extract FUNNEL_TEMPLATES');
        }
        preg_match_all('/^\s{8}([a-z_]+):\s*\{/m', $m[1], $keys);
        return $keys[1];
    }

    /** data-template keys referenced by the PHP cards. */
    private function cardTemplateKeys(): array
    {
        preg_match_all("/'key'\s*=>\s*'([a-z_]+)'/", $this->card, $keys);
        return $keys[1];
    }

    public function test_every_card_maps_to_a_js_template(): void
    {
        $jsKeys = $this->jsTemplateKeys();
        $this->assertNotEmpty($jsKeys, 'FUNNEL_TEMPLATES must define templates');
        foreach ($this->cardTemplateKeys() as $key) {
            $this->assertContains($key, $jsKeys, "Card data-template '{$key}' has no matching FUNNEL_TEMPLATES entry — clicking it would fall back to blank");
        }
    }

    public function test_expected_template_set_present(): void
    {
        $jsKeys = $this->jsTemplateKeys();
        foreach (['woocommerce_purchase', 'checkout_completion', 'landing_to_contact', 'pricing_to_checkout', 'landing_to_thanks', 'blank'] as $expected) {
            $this->assertContains($expected, $jsKeys, "Template '{$expected}' missing");
        }
    }

    public function test_woocommerce_order_received_uses_nested_checkout_path(): void
    {
        // WooCommerce's default thank-you URL is /checkout/order-received/{id}/...
        $this->assertStringContainsString(
            "value: '/checkout/order-received'",
            $this->js,
            'WooCommerce template Order received step must target the nested /checkout/order-received path'
        );
        $this->assertStringNotContainsString(
            "value: '/order-received'",
            $this->js,
            'Bare /order-received (wrong path) must be retired'
        );
    }
}
