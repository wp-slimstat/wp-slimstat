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
 * issue screenshots showed. Also pins the WooCommerce order-received match to
 * the endpoint segment (/order-received), which survives a localized/renamed
 * checkout page slug — see research #21.
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

    public function test_woocommerce_order_received_matches_endpoint_segment(): void
    {
        // WooCommerce's thank-you URL is the `order-received` endpoint nested under
        // the checkout page: /checkout/order-received/{id}/?key=... We match the
        // endpoint segment alone (`/order-received`) because WooCommerce does not
        // translate that slug, so it survives a localized/renamed checkout page
        // slug (e.g. /kasse/order-received/) that a /checkout-prefixed value would
        // miss. Verified against WooCommerce core + docs — research #21.
        $this->assertSame(
            2,
            substr_count($this->js, "value: '/order-received'"),
            'Both WooCommerce templates (purchase + checkout_completion) must match the /order-received endpoint segment'
        );
        // The /checkout-prefixed value is retired (breaks on localized checkout slugs).
        $this->assertStringNotContainsString(
            "value: '/checkout/order-received'",
            $this->js,
            'The checkout-prefixed order-received path must be retired (fails on localized/renamed checkout slugs)'
        );
    }
}
