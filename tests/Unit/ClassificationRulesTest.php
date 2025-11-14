<?php

namespace SlimStat\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SlimStat\Channel\ClassificationRules;

/**
 * Unit tests for ClassificationRules (T026-T028).
 *
 * Tests priority cascade with conflicting rules, UTM extraction,
 * and PII validation.
 *
 * @package SlimStat\Tests\Unit
 * @since 5.1.0
 */
class ClassificationRulesTest extends TestCase
{
    /**
     * Classification rules instance.
     *
     * @var ClassificationRules
     */
    protected $rules;

    /**
     * Set up test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->rules = new ClassificationRules('example.com');
    }

    /**
     * Test T026: Priority cascade with conflicting rules.
     *
     * Verifies that when multiple classification signals are present,
     * the highest priority rule wins (UTM > AI > Social > Search > Referral > Direct).
     *
     * @test
     * @group unit
     * @group priority-cascade
     */
    public function test_utm_medium_email_overrides_social_referrer(): void
    {
        // Facebook referrer + email UTM medium → Email (not Social)
        $visit = [
            'referer' => 'https://www.facebook.com/',
            'resource' => 'https://example.com/',
            'notes' => 'utm_source=newsletter&utm_medium=email&utm_campaign=weekly',
            'domain' => 'example.com',
        ];

        $result = $this->rules->classify($visit);

        $this->assertEquals('Email', $result['channel'], 'UTM medium=email should override Facebook (Social) referrer');
        $this->assertEquals('newsletter', $result['utm_source']);
        $this->assertEquals('email', $result['utm_medium']);
    }

    /**
     * Test UTM paid search overrides organic search referrer.
     *
     * @test
     * @group unit
     * @group priority-cascade
     */
    public function test_utm_medium_cpc_overrides_organic_search_referrer(): void
    {
        // Google referrer (organic) + utm_medium=cpc → Paid Search
        $visit = [
            'referer' => 'https://www.google.com/search?q=test',
            'resource' => 'https://example.com/',
            'notes' => 'utm_source=google&utm_medium=cpc&utm_campaign=brand',
            'domain' => 'example.com',
        ];

        $result = $this->rules->classify($visit);

        $this->assertEquals('Paid Search', $result['channel'], 'UTM medium=cpc should override organic Google referrer');
    }

    /**
     * Test AI detection takes priority over social.
     *
     * This tests the priority order: AI (priority 2) > Social (priority 3).
     *
     * @test
     * @group unit
     * @group priority-cascade
     */
    public function test_ai_detection_priority_over_social(): void
    {
        // If a site were in both AI and Social lists (hypothetically),
        // AI should win. Let's test with ChatGPT which is AI.
        $visit = [
            'referer' => 'https://chat.openai.com/',
            'resource' => 'https://example.com/',
            'notes' => '',
            'domain' => 'example.com',
        ];

        $result = $this->rules->classify($visit);

        $this->assertEquals('AI', $result['channel']);
    }

    /**
     * Test social detection takes priority over search.
     *
     * Priority order: Social (3) > Search (4).
     *
     * @test
     * @group unit
     * @group priority-cascade
     */
    public function test_social_detection_priority_over_referral(): void
    {
        // LinkedIn is social, should classify as Social not Referral
        $visit = [
            'referer' => 'https://www.linkedin.com/',
            'resource' => 'https://example.com/',
            'notes' => '',
            'domain' => 'example.com',
        ];

        $result = $this->rules->classify($visit);

        $this->assertEquals('Social', $result['channel'], 'Social should take priority over generic Referral');
    }

    /**
     * Test search detection takes priority over referral.
     *
     * Priority order: Search (4) > Referral (5).
     *
     * @test
     * @group unit
     * @group priority-cascade
     */
    public function test_search_detection_priority_over_referral(): void
    {
        // DuckDuckGo is a search engine, should classify as Organic Search not Referral
        $visit = [
            'referer' => 'https://duckduckgo.com/?q=wordpress+analytics',
            'resource' => 'https://example.com/',
            'notes' => '',
            'domain' => 'example.com',
        ];

        $result = $this->rules->classify($visit);

        $this->assertEquals('Organic Search', $result['channel'], 'Search should take priority over Referral');
    }

    /**
     * Test referral takes priority over direct.
     *
     * Priority order: Referral (5) > Direct (6).
     *
     * @test
     * @group unit
     * @group priority-cascade
     */
    public function test_referral_priority_over_direct(): void
    {
        // External domain referrer should be Referral, not Direct
        $visit = [
            'referer' => 'https://partner-site.com/',
            'resource' => 'https://example.com/',
            'notes' => '',
            'domain' => 'example.com',
        ];

        $result = $this->rules->classify($visit);

        $this->assertEquals('Referral', $result['channel']);
    }

    /**
     * Test T027: UTM extraction from notes field (priority).
     *
     * Verifies that notes field is checked first for UTM parameters.
     *
     * @test
     * @group unit
     * @group utm-extraction
     */
    public function test_extract_utm_from_notes_field_priority(): void
    {
        $notes = 'utm_source=email_newsletter&utm_medium=email&utm_campaign=spring_2024';
        $resource = 'https://example.com/landing?utm_source=wrong&utm_medium=wrong';

        $utm = $this->rules->extract_utm_parameters($notes, $resource);

        // Notes field should take priority over resource URL
        $this->assertEquals('email_newsletter', $utm['utm_source']);
        $this->assertEquals('email', $utm['utm_medium']);
        $this->assertEquals('spring_2024', $utm['utm_campaign']);
    }

    /**
     * Test T027: UTM extraction from resource URL (fallback).
     *
     * Verifies that if notes field is empty, UTM parameters are extracted from resource URL.
     *
     * @test
     * @group unit
     * @group utm-extraction
     */
    public function test_extract_utm_from_resource_url_fallback(): void
    {
        $notes = ''; // Empty notes field
        $resource = 'https://example.com/page?utm_source=google&utm_medium=cpc&utm_campaign=brand&foo=bar';

        $utm = $this->rules->extract_utm_parameters($notes, $resource);

        $this->assertEquals('google', $utm['utm_source']);
        $this->assertEquals('cpc', $utm['utm_medium']);
        $this->assertEquals('brand', $utm['utm_campaign']);
    }

    /**
     * Test UTM extraction handles missing parameters gracefully.
     *
     * @test
     * @group unit
     * @group utm-extraction
     */
    public function test_extract_utm_handles_partial_parameters(): void
    {
        $notes = 'utm_source=facebook&utm_campaign=summer';
        $resource = 'https://example.com/';

        $utm = $this->rules->extract_utm_parameters($notes, $resource);

        $this->assertEquals('facebook', $utm['utm_source']);
        $this->assertNull($utm['utm_medium'], 'Missing utm_medium should be null');
        $this->assertEquals('summer', $utm['utm_campaign']);
    }

    /**
     * Test UTM extraction normalizes values (lowercase, trim).
     *
     * @test
     * @group unit
     * @group utm-extraction
     * @group normalization
     */
    public function test_extract_utm_normalizes_values(): void
    {
        $notes = 'utm_source=  Facebook  &utm_medium=SOCIAL&utm_campaign=SUMMER_SALE';
        $resource = '';

        $utm = $this->rules->extract_utm_parameters($notes, $resource);

        // Should be lowercase and trimmed
        $this->assertEquals('facebook', $utm['utm_source'], 'utm_source should be lowercased and trimmed');
        $this->assertEquals('social', $utm['utm_medium'], 'utm_medium should be lowercased');
        $this->assertEquals('summer_sale', $utm['utm_campaign'], 'utm_campaign should be lowercased');
    }

    /**
     * Test T028: PII validation rejects email addresses.
     *
     * Verifies that UTM parameters containing email addresses are rejected (FR-038).
     *
     * @test
     * @group unit
     * @group pii-validation
     */
    public function test_utm_extraction_rejects_email_addresses(): void
    {
        // UTM parameter contains an email address (PII)
        $notes = 'utm_source=user@example.com&utm_medium=email&utm_campaign=test';
        $resource = '';

        $utm = $this->rules->extract_utm_parameters($notes, $resource);

        $this->assertNull($utm['utm_source'], 'Email address in utm_source should be rejected');
        $this->assertEquals('email', $utm['utm_medium'], 'Valid utm_medium should not be rejected');
        $this->assertEquals('test', $utm['utm_campaign']);
    }

    /**
     * Test T028: PII validation rejects phone numbers.
     *
     * Verifies that UTM parameters containing phone numbers are rejected (FR-038).
     *
     * @test
     * @group unit
     * @group pii-validation
     */
    public function test_utm_extraction_rejects_phone_numbers(): void
    {
        // UTM parameter contains a phone number (PII)
        $notes = 'utm_source=newsletter&utm_medium=sms&utm_campaign=+1-555-123-4567';
        $resource = '';

        $utm = $this->rules->extract_utm_parameters($notes, $resource);

        $this->assertEquals('newsletter', $utm['utm_source']);
        $this->assertEquals('sms', $utm['utm_medium']);
        $this->assertNull($utm['utm_campaign'], 'Phone number in utm_campaign should be rejected');
    }

    /**
     * Test PII validation rejects various phone formats.
     *
     * @test
     * @group unit
     * @group pii-validation
     */
    public function test_utm_extraction_rejects_various_phone_formats(): void
    {
        $phone_formats = [
            '+1-555-123-4567',
            '(555) 123-4567',
            '555.123.4567',
            '5551234567',
            '+15551234567',
        ];

        foreach ($phone_formats as $phone) {
            $notes = "utm_campaign={$phone}";
            $utm = $this->rules->extract_utm_parameters($notes, '');

            $this->assertNull(
                $utm['utm_campaign'],
                "Phone format '{$phone}' should be rejected as PII"
            );
        }
    }

    /**
     * Test PII validation allows valid UTM values.
     *
     * Verifies that legitimate UTM values are not incorrectly flagged as PII.
     *
     * @test
     * @group unit
     * @group pii-validation
     */
    public function test_utm_extraction_allows_valid_values(): void
    {
        $valid_values = [
            'newsletter',
            'google-ads',
            'facebook-campaign',
            'summer_sale_2024',
            'blog-post-123',
            'homepage-banner',
        ];

        foreach ($valid_values as $value) {
            $notes = "utm_source={$value}";
            $utm = $this->rules->extract_utm_parameters($notes, '');

            $this->assertEquals(
                $value,
                $utm['utm_source'],
                "Valid value '{$value}' should not be rejected"
            );
        }
    }

    /**
     * Test domain matching with wildcards.
     *
     * Verifies that fnmatch() wildcards work correctly for domain lists.
     *
     * @test
     * @group unit
     * @group domain-matching
     */
    public function test_matches_domain_list_with_wildcards(): void
    {
        // Test that Google domains match the wildcard pattern
        $google_urls = [
            'https://www.google.com/',
            'https://www.google.co.uk/',
            'https://www.google.de/',
            'https://google.com/',
        ];

        foreach ($google_urls as $url) {
            $matches = $this->rules->matches_domain_list($url, 'SEARCH_DOMAINS');
            $this->assertTrue($matches, "URL '{$url}' should match SEARCH_DOMAINS");
        }
    }

    /**
     * Test same-domain detection with www normalization.
     *
     * Verifies that www. prefix is ignored when comparing domains.
     *
     * @test
     * @group unit
     * @group domain-matching
     */
    public function test_is_same_domain_normalizes_www_prefix(): void
    {
        $test_cases = [
            ['https://example.com/', 'example.com', true],
            ['https://www.example.com/', 'example.com', true],
            ['https://example.com/', 'www.example.com', true],
            ['https://www.example.com/', 'www.example.com', true],
            ['https://other-site.com/', 'example.com', false],
        ];

        foreach ($test_cases as [$referer, $site_domain, $expected]) {
            $result = $this->rules->is_same_domain($referer, $site_domain);
            $this->assertEquals(
                $expected,
                $result,
                "Referer '{$referer}' vs site '{$site_domain}' should be " . ($expected ? 'same' : 'different')
            );
        }
    }

    /**
     * Test classification with malformed referrer URLs.
     *
     * Verifies that invalid/malformed referrers don't cause errors.
     *
     * @test
     * @group unit
     * @group edge-cases
     */
    public function test_classify_handles_malformed_referrer(): void
    {
        $malformed_referrers = [
            'not-a-url',
            'http://',
            '://missing-protocol',
            '',
            null,
        ];

        foreach ($malformed_referrers as $referer) {
            $visit = [
                'referer' => $referer,
                'resource' => 'https://example.com/',
                'notes' => '',
                'domain' => 'example.com',
            ];

            $result = $this->rules->classify($visit);

            // Should classify as Direct (malformed = no valid referrer)
            $this->assertIsArray($result);
            $this->assertArrayHasKey('channel', $result);
            $this->assertEquals('Direct', $result['channel'], "Malformed referrer '{$referer}' should classify as Direct");
        }
    }

    /**
     * Test paid search detection via gclid parameter.
     *
     * @test
     * @group unit
     * @group paid-search
     */
    public function test_paid_search_detection_with_gclid(): void
    {
        $visit = [
            'referer' => 'https://www.google.com/search?q=test&gclid=ABC123XYZ',
            'resource' => 'https://example.com/',
            'notes' => '',
            'domain' => 'example.com',
        ];

        $result = $this->rules->classify($visit);

        $this->assertEquals('Paid Search', $result['channel'], 'gclid parameter should indicate Paid Search');
    }

    /**
     * Test paid search detection via msclkid parameter.
     *
     * @test
     * @group unit
     * @group paid-search
     */
    public function test_paid_search_detection_with_msclkid(): void
    {
        $visit = [
            'referer' => 'https://www.bing.com/search?q=test&msclkid=ABC123XYZ',
            'resource' => 'https://example.com/',
            'notes' => '',
            'domain' => 'example.com',
        ];

        $result = $this->rules->classify($visit);

        $this->assertEquals('Paid Search', $result['channel'], 'msclkid parameter should indicate Paid Search');
    }

    /**
     * Test complete classification flow with all parameters.
     *
     * Integration-style test within unit test suite.
     *
     * @test
     * @group unit
     * @group integration
     */
    public function test_complete_classification_flow_with_all_data(): void
    {
        $visit = [
            'referer' => 'https://www.google.com/search?q=wordpress+analytics',
            'resource' => 'https://example.com/landing?utm_source=google&utm_medium=organic&utm_campaign=seo',
            'notes' => 'utm_source=google&utm_medium=organic&utm_campaign=seo',
            'domain' => 'example.com',
        ];

        $result = $this->rules->classify($visit);

        $this->assertEquals('Organic Search', $result['channel']);
        $this->assertEquals('google', $result['utm_source']);
        $this->assertEquals('organic', $result['utm_medium']);
        $this->assertEquals('seo', $result['utm_campaign']);
    }
}
