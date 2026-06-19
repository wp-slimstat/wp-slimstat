<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the goals/funnels query-path optimizations (#12).
 *
 * Query Monitor showed get_goal_results()/count_unique_*() running the same
 * COUNT(*) several times per page load and get_funnel_results() rebuilding temp
 * tables repeatedly. The fix adds per-request memoization so a goal/funnel
 * result is computed once per request and reused (identical-criteria goals
 * dedupe to a single query).
 *
 * Source-shape test (mirrors FunnelOrderingSqlTest) — no live MySQL.
 */
class GoalsFunnelsPerfTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        parent::setUp();
        $this->src = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/view/wp-slimstat-db.php');
        if ($this->src === '') {
            $this->fail('Could not read admin/view/wp-slimstat-db.php');
        }
    }

    private function methodBody(string $name): string
    {
        if (!preg_match('/function ' . preg_quote($name, '/') . '\([^{]*\{.*?^    \}/sm', $this->src, $m)) {
            $this->fail("Could not extract {$name}() body");
        }
        return $m[0];
    }

    public function test_goal_results_are_memoized_per_request(): void
    {
        $body = $this->methodBody('get_goal_results');
        $this->assertStringContainsString('static $request_memo', $body, 'get_goal_results must memoize within the request');
        // Memo key is the result-determining signature (criteria + filters +
        // cache version), so identical-criteria goals share one query.
        $this->assertStringContainsString('$goal_where', $body);
        $this->assertMatchesRegularExpression('/array_key_exists\(\s*\$memo_key/', $body, 'Memo lookup must guard before the query');
    }

    public function test_funnel_results_are_memoized_per_request(): void
    {
        $body = $this->methodBody('get_funnel_results');
        $this->assertStringContainsString('static $request_memo', $body, 'get_funnel_results must memoize within the request');
        $this->assertMatchesRegularExpression('/array_key_exists\(\s*\$cache_key/', $body, 'Funnel memo must key on the cache key');
    }

    public function test_funnel_cache_key_is_step_signature_not_id(): void
    {
        // Two funnels with identical steps MUST return identical numbers, so the
        // cache key is derived from the normalized step signature, NOT the funnel id.
        // The derivation now lives in the funnel_cache_key() helper. (#19)
        $caller = $this->methodBody('get_funnel_results');
        $this->assertStringContainsString('self::funnel_cache_key(', $caller, 'get_funnel_results must build its key via funnel_cache_key()');
        $this->assertStringContainsString("\$funnel['steps']", $caller, 'funnel_cache_key must be fed the step rules');
        $this->assertDoesNotMatchRegularExpression(
            "/\\\$cache_key\\s*=\\s*'slimstat_funnel_'\\s*\\.\\s*\\(isset\\(\\\$funnel\\['id'\\]\\)/",
            $caller,
            'Funnel cache key must not be derived from the funnel id'
        );

        $helper = $this->methodBody('funnel_cache_key');
        $this->assertStringContainsString('normalize_funnel_steps($steps)', $helper, 'funnel_cache_key must derive from the normalized step signature');
    }

    public function test_funnel_cache_key_buckets_the_date_range(): void
    {
        // Residual #1 bug: the key hashed the rendered $date_where SQL, whose live
        // "end = now" has second precision — so a server-rendered funnel and its
        // AJAX-loaded twin (computed seconds apart) missed each other's transient and
        // recomputed against live traffic (995 vs 991). The key now hour-buckets the
        // window so identical funnels in the same hour share one entry. (#1)
        $caller = $this->methodBody('get_funnel_results');
        $this->assertStringNotContainsString('md5($date_where . $cache_ver)', $caller, 'Cache key must no longer hash the raw $date_where SQL');

        $helper = $this->methodBody('funnel_cache_key');
        $this->assertMatchesRegularExpression('/floor\(\s*\(int\)\s*\$range_end\s*\/\s*3600\s*\)/', $helper, 'funnel_cache_key must hour-bucket the window end');
    }

    public function test_normalize_funnel_steps_is_result_determining_and_ordered(): void
    {
        $body = $this->methodBody('normalize_funnel_steps');
        // Captures exactly the fields build_goal_where() reads, so a shared
        // signature guarantees a shared WHERE clause (no wrong-result collision).
        $this->assertStringContainsString("'dimension'", $body);
        $this->assertStringContainsString("'operator'", $body);
        $this->assertStringContainsString("'value'", $body);
        // Ignores the per-step label — two funnels differing only by step names
        // are the same funnel for results purposes.
        $this->assertStringNotContainsString("'name'", $body, 'normalize must ignore the step label');
        // Step order is significant (A->B->C is not C->B->A), so steps are not sorted.
        $this->assertStringNotContainsString('sort(', $body, 'funnel steps must not be reordered');
    }
}
