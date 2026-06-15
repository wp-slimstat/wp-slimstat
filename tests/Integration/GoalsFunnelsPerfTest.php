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
}
