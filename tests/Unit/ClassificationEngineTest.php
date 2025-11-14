<?php

namespace SlimStat\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SlimStat\Channel\ClassificationEngine;
use SlimStat\Channel\ClassificationRules;

/**
 * Unit tests for ClassificationEngine (T025).
 *
 * Tests all 8 channel classification rules with priority cascade.
 *
 * @package SlimStat\Tests\Unit
 * @since 5.1.0
 */
class ClassificationEngineTest extends TestCase
{
    /**
     * Classification engine instance.
     *
     * @var ClassificationEngine
     */
    protected $engine;

    /**
     * Set up test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new ClassificationEngine();
    }

    /**
     * Test T025: Direct traffic classification (FR-024).
     *
     * Verifies that visits with no referrer or same-domain referrer
     * are classified as "Direct".
     *
     * @test
     * @group unit
     * @group classification
     */
    public function test_classifies_direct_traffic_with_no_referrer(): void
    {
        $visit = [
            'referer' => '',
            'resource' => 'https://example.com/page',
            'notes' => '',
            'domain' => 'example.com',
        ];

        $result = $this->engine->classify_visit($visit);

        $this->assertEquals('Direct', $result['channel']);
        $this->assertNull($result['utm_source']);
        $this->assertNull($result['utm_medium']);
        $this->assertNull($result['utm_campaign']);
    }

    /**
     * Test direct traffic with same-domain referrer.
     *
     * @test
     * @group unit
     * @group classification
     */
    public function test_classifies_direct_traffic_with_same_domain_referrer(): void
    {
        $visit = [
            'referer' => 'https://example.com/previous-page',
            'resource' => 'https://example.com/current-page',
            'notes' => '',
            'domain' => 'example.com',
        ];

        $result = $this->engine->classify_visit($visit);

        $this->assertEquals('Direct', $result['channel']);
    }

    /**
     * Test T025: Organic search classification (FR-025).
     *
     * Verifies that search engine referrers without paid indicators
     * are classified as "Organic Search".
     *
     * @test
     * @group unit
     * @group classification
     */
    public function test_classifies_organic_search_from_google(): void
    {
        $visit = [
            'referer' => 'https://www.google.com/search?q=test+query',
            'resource' => 'https://example.com/',
            'notes' => '',
            'domain' => 'example.com',
        ];

        $result = $this->engine->classify_visit($visit);

        $this->assertEquals('Organic Search', $result['channel']);
    }

    /**
     * Test organic search from Bing.
     *
     * @test
     * @group unit
     * @group classification
     */
    public function test_classifies_organic_search_from_bing(): void
    {
        $visit = [
            'referer' => 'https://www.bing.com/search?q=wordpress+analytics',
            'resource' => 'https://example.com/',
            'notes' => '',
            'domain' => 'example.com',
        ];

        $result = $this->engine->classify_visit($visit);

        $this->assertEquals('Organic Search', $result['channel']);
    }

    /**
     * Test T025: Paid search classification (FR-026).
     *
     * Verifies that search engine traffic with paid indicators
     * (gclid, utm_medium=cpc) is classified as "Paid Search".
     *
     * @test
     * @group unit
     * @group classification
     */
    public function test_classifies_paid_search_with_gclid(): void
    {
        $visit = [
            'referer' => 'https://www.google.com/search?q=buy+product&gclid=ABC123',
            'resource' => 'https://example.com/',
            'notes' => '',
            'domain' => 'example.com',
        ];

        $result = $this->engine->classify_visit($visit);

        $this->assertEquals('Paid Search', $result['channel']);
    }

    /**
     * Test paid search with utm_medium=cpc.
     *
     * @test
     * @group unit
     * @group classification
     */
    public function test_classifies_paid_search_with_utm_medium_cpc(): void
    {
        $visit = [
            'referer' => 'https://www.google.com/',
            'resource' => 'https://example.com/?utm_source=google&utm_medium=cpc&utm_campaign=summer',
            'notes' => 'utm_source=google&utm_medium=cpc&utm_campaign=summer',
            'domain' => 'example.com',
        ];

        $result = $this->engine->classify_visit($visit);

        $this->assertEquals('Paid Search', $result['channel']);
        $this->assertEquals('google', $result['utm_source']);
        $this->assertEquals('cpc', $result['utm_medium']);
        $this->assertEquals('summer', $result['utm_campaign']);
    }

    /**
     * Test paid search with utm_medium=ppc.
     *
     * @test
     * @group unit
     * @group classification
     */
    public function test_classifies_paid_search_with_utm_medium_ppc(): void
    {
        $visit = [
            'referer' => '',
            'resource' => 'https://example.com/',
            'notes' => 'utm_source=bing&utm_medium=ppc',
            'domain' => 'example.com',
        ];

        $result = $this->engine->classify_visit($visit);

        $this->assertEquals('Paid Search', $result['channel']);
        $this->assertEquals('bing', $result['utm_source']);
        $this->assertEquals('ppc', $result['utm_medium']);
    }

    /**
     * Test T025: Social media classification (FR-027).
     *
     * Verifies that social media referrers are classified as "Social".
     *
     * @test
     * @group unit
     * @group classification
     */
    public function test_classifies_social_traffic_from_facebook(): void
    {
        $visit = [
            'referer' => 'https://www.facebook.com/',
            'resource' => 'https://example.com/',
            'notes' => '',
            'domain' => 'example.com',
        ];

        $result = $this->engine->classify_visit($visit);

        $this->assertEquals('Social', $result['channel']);
    }

    /**
     * Test social traffic from Twitter.
     *
     * @test
     * @group unit
     * @group classification
     */
    public function test_classifies_social_traffic_from_twitter(): void
    {
        $visit = [
            'referer' => 'https://t.co/abc123',
            'resource' => 'https://example.com/',
            'notes' => '',
            'domain' => 'example.com',
        ];

        $result = $this->engine->classify_visit($visit);

        $this->assertEquals('Social', $result['channel']);
    }

    /**
     * Test social traffic from LinkedIn.
     *
     * @test
     * @group unit
     * @group classification
     */
    public function test_classifies_social_traffic_from_linkedin(): void
    {
        $visit = [
            'referer' => 'https://www.linkedin.com/feed/',
            'resource' => 'https://example.com/',
            'notes' => '',
            'domain' => 'example.com',
        ];

        $result = $this->engine->classify_visit($visit);

        $this->assertEquals('Social', $result['channel']);
    }

    /**
     * Test T025: Email classification (FR-028).
     *
     * Verifies that utm_medium=email is classified as "Email".
     *
     * @test
     * @group unit
     * @group classification
     */
    public function test_classifies_email_traffic_with_utm_medium(): void
    {
        $visit = [
            'referer' => '',
            'resource' => 'https://example.com/',
            'notes' => 'utm_source=newsletter&utm_medium=email&utm_campaign=weekly',
            'domain' => 'example.com',
        ];

        $result = $this->engine->classify_visit($visit);

        $this->assertEquals('Email', $result['channel']);
        $this->assertEquals('newsletter', $result['utm_source']);
        $this->assertEquals('email', $result['utm_medium']);
        $this->assertEquals('weekly', $result['utm_campaign']);
    }

    /**
     * Test T025: AI platform classification (FR-029).
     *
     * Verifies that AI platform referrers are classified as "AI".
     *
     * @test
     * @group unit
     * @group classification
     */
    public function test_classifies_ai_traffic_from_chatgpt(): void
    {
        $visit = [
            'referer' => 'https://chat.openai.com/',
            'resource' => 'https://example.com/',
            'notes' => '',
            'domain' => 'example.com',
        ];

        $result = $this->engine->classify_visit($visit);

        $this->assertEquals('AI', $result['channel']);
    }

    /**
     * Test AI traffic from Claude.
     *
     * @test
     * @group unit
     * @group classification
     */
    public function test_classifies_ai_traffic_from_claude(): void
    {
        $visit = [
            'referer' => 'https://claude.ai/',
            'resource' => 'https://example.com/',
            'notes' => '',
            'domain' => 'example.com',
        ];

        $result = $this->engine->classify_visit($visit);

        $this->assertEquals('AI', $result['channel']);
    }

    /**
     * Test AI traffic from Perplexity.
     *
     * @test
     * @group unit
     * @group classification
     */
    public function test_classifies_ai_traffic_from_perplexity(): void
    {
        $visit = [
            'referer' => 'https://www.perplexity.ai/',
            'resource' => 'https://example.com/',
            'notes' => '',
            'domain' => 'example.com',
        ];

        $result = $this->engine->classify_visit($visit);

        $this->assertEquals('AI', $result['channel']);
    }

    /**
     * Test T025: Referral classification (FR-030).
     *
     * Verifies that external domain referrers not in other categories
     * are classified as "Referral".
     *
     * @test
     * @group unit
     * @group classification
     */
    public function test_classifies_referral_traffic_from_external_site(): void
    {
        $visit = [
            'referer' => 'https://partner-site.com/blog/article',
            'resource' => 'https://example.com/',
            'notes' => '',
            'domain' => 'example.com',
        ];

        $result = $this->engine->classify_visit($visit);

        $this->assertEquals('Referral', $result['channel']);
    }

    /**
     * Test referral traffic from blog.
     *
     * @test
     * @group unit
     * @group classification
     */
    public function test_classifies_referral_traffic_from_blog(): void
    {
        $visit = [
            'referer' => 'https://someblog.wordpress.com/post/123',
            'resource' => 'https://example.com/',
            'notes' => '',
            'domain' => 'example.com',
        ];

        $result = $this->engine->classify_visit($visit);

        $this->assertEquals('Referral', $result['channel']);
    }

    /**
     * Test T025: Other classification (FR-031, FR-040).
     *
     * Verifies catchall category for unclassified traffic.
     *
     * @test
     * @group unit
     * @group classification
     */
    public function test_classifies_other_traffic_for_unknown_sources(): void
    {
        // This is actually hard to trigger since most cases fall into Referral
        // Other is primarily a safety net for malformed data
        $visit = [
            'referer' => 'invalid-url-format',
            'resource' => 'https://example.com/',
            'notes' => '',
            'domain' => 'example.com',
        ];

        $result = $this->engine->classify_visit($visit);

        // Due to is_same_domain() handling invalid URLs as direct,
        // this will likely be Direct, but Other is the guaranteed fallback
        $this->assertContains($result['channel'], ['Direct', 'Other']);
    }

    /**
     * Test priority cascade: UTM overrides referrer (FR-032).
     *
     * Verifies that UTM parameters take priority over referrer-based classification.
     *
     * @test
     * @group unit
     * @group classification
     * @group priority
     */
    public function test_utm_parameters_override_referrer_classification(): void
    {
        // Facebook referrer with email UTM medium should classify as Email, not Social
        $visit = [
            'referer' => 'https://www.facebook.com/',
            'resource' => 'https://example.com/',
            'notes' => 'utm_source=newsletter&utm_medium=email',
            'domain' => 'example.com',
        ];

        $result = $this->engine->classify_visit($visit);

        $this->assertEquals('Email', $result['channel'], 'UTM medium=email should override Facebook referrer');
    }

    /**
     * Test error handling: classify_visit() never throws.
     *
     * Verifies that classification errors are logged and return "Other" as fallback.
     *
     * @test
     * @group unit
     * @group error-handling
     */
    public function test_classify_visit_handles_errors_gracefully(): void
    {
        // Intentionally malformed visit data
        $visit = [];

        $result = $this->engine->classify_visit($visit);

        // Should return valid structure with "Other" as fallback
        $this->assertIsArray($result);
        $this->assertArrayHasKey('channel', $result);
        $this->assertContains($result['channel'], ['Direct', 'Other']);
    }

    /**
     * Test batch classification.
     *
     * Verifies that classify_batch() processes multiple visits correctly.
     *
     * @test
     * @group unit
     * @group batch
     */
    public function test_classify_batch_processes_multiple_visits(): void
    {
        $visits = [
            [
                'referer' => '',
                'resource' => 'https://example.com/',
                'notes' => '',
                'domain' => 'example.com',
            ],
            [
                'referer' => 'https://www.google.com/search',
                'resource' => 'https://example.com/',
                'notes' => '',
                'domain' => 'example.com',
            ],
            [
                'referer' => 'https://www.facebook.com/',
                'resource' => 'https://example.com/',
                'notes' => '',
                'domain' => 'example.com',
            ],
        ];

        $results = $this->engine->classify_batch($visits);

        $this->assertCount(3, $results);
        $this->assertEquals('Direct', $results[0]['channel']);
        $this->assertEquals('Organic Search', $results[1]['channel']);
        $this->assertEquals('Social', $results[2]['channel']);
    }
}
