<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

/**
 * AJAX contract tests for Goals & Funnels handlers.
 *
 * Covers:
 *   - ajax_save_goal (create, update-by-id, nonce, capability, limit-with-active-count)
 *   - ajax_delete_goal
 *   - ajax_save_funnel (2-step, 5-step, reject <2, reject >5, update-by-id)
 *   - ajax_delete_funnel
 *
 * Covers the Architectural decisions 6-7 from the 5.5.0 redesign: capability
 * gating, nonce reuse, and the paused-goal limit-count fix.
 */
class GoalsFunnelsAjaxTest extends IntegrationTestCase
{
    private const VALID_GOAL_POST = [
        'security'  => 'x',
        'name'      => 'Pricing View',
        'dimension' => 'resource',
        'operator'  => 'contains',
        'value'     => '/pricing',
        'active'    => 1,
    ];

    private function callHandler(string $method): WpAjaxDie
    {
        try {
            \wp_slimstat_admin::$method();
        } catch (WpAjaxDie $die) {
            return $die;
        }
        $this->fail('Handler did not call wp_send_json_* — no die thrown.');
    }

    // ---- ajax_save_goal ------------------------------------------------

    public function test_save_goal_creates_new_goal(): void
    {
        $this->setMaxGoals(1);
        $_POST = self::VALID_GOAL_POST;

        $die = $this->callHandler('ajax_save_goal');

        $this->assertSame('success', $die->outcome());
        $this->assertCount(1, $this->optionStore['slimstat_goals']);
        $this->assertSame('Pricing View', $this->optionStore['slimstat_goals'][0]['name']);
        $this->assertTrue($this->optionStore['slimstat_goals'][0]['active']);
    }

    public function test_save_goal_updates_existing_by_id(): void
    {
        $this->setMaxGoals(5);
        $this->setGoals([
            ['id' => 42, 'name' => 'Old', 'dimension' => 'resource', 'operator' => 'equals', 'value' => '/old', 'active' => true],
        ]);
        $_POST = array_merge(self::VALID_GOAL_POST, ['id' => 42, 'name' => 'Renamed']);

        $die = $this->callHandler('ajax_save_goal');

        $this->assertSame('success', $die->outcome());
        $this->assertCount(1, $this->optionStore['slimstat_goals']);
        $this->assertSame('Renamed', $this->optionStore['slimstat_goals'][0]['name']);
        $this->assertSame(42, $this->optionStore['slimstat_goals'][0]['id']);
    }

    public function test_save_goal_rejects_when_nonce_invalid(): void
    {
        $this->nonceValid = false;
        $this->setMaxGoals(1);
        $_POST = self::VALID_GOAL_POST;

        $die = $this->callHandler('ajax_save_goal');

        $this->assertSame('nonce_invalid', $die->outcome());
        $this->assertArrayNotHasKey('slimstat_goals', $this->optionStore);
    }

    public function test_save_goal_rejects_without_admin_capability(): void
    {
        $this->capability = false;
        $this->setMaxGoals(1);
        $_POST = self::VALID_GOAL_POST;

        $die = $this->callHandler('ajax_save_goal');

        $this->assertSame('error', $die->outcome());
        $this->assertStringContainsString('Insufficient', $die->payload['message']);
    }

    public function test_save_goal_rejects_at_max_active_limit(): void
    {
        $this->setMaxGoals(1);
        $this->setGoals([
            ['id' => 1, 'name' => 'A', 'dimension' => 'resource', 'operator' => 'equals', 'value' => '/a', 'active' => true],
        ]);
        $_POST = self::VALID_GOAL_POST;

        $die = $this->callHandler('ajax_save_goal');

        $this->assertSame('error', $die->outcome());
        $this->assertStringContainsString('limit reached', $die->payload['message']);
        $this->assertCount(1, $this->optionStore['slimstat_goals']);
    }

    /**
     * BUG GUARD — paused goals must not consume a slot.
     *
     * Before the fix: ajax_save_goal counts array_count($goals) against $max_goals,
     * so a single paused goal blocks creating any active one. After the fix: only
     * active goals count toward the limit.
     *
     * Plan: §Architectural decisions #7, item in admin/index.php:1685.
     */
    public function test_save_goal_allows_create_when_only_paused_goals_exist(): void
    {
        $this->setMaxGoals(1);
        $this->setGoals([
            ['id' => 1, 'name' => 'Paused', 'dimension' => 'resource', 'operator' => 'equals', 'value' => '/x', 'active' => false],
        ]);
        $_POST = self::VALID_GOAL_POST;

        $die = $this->callHandler('ajax_save_goal');

        $this->assertSame('success', $die->outcome(), 'Paused goals must not block new-goal creation');
        $this->assertCount(2, $this->optionStore['slimstat_goals']);
    }

    /**
     * BUG GUARD (M1) — a value-bearing operator saved with an empty value used to
     * pass sanitize_goal(), then produce an unprepared "%s" placeholder in the SQL
     * (a broken query that silently reported 0). It must now be rejected at save.
     */
    public function test_save_goal_rejects_value_bearing_operator_with_empty_value(): void
    {
        $this->setMaxGoals(1);
        $_POST = array_merge(self::VALID_GOAL_POST, ['operator' => 'equals', 'value' => '']);

        $die = $this->callHandler('ajax_save_goal');

        $this->assertSame('error', $die->outcome());
        $this->assertStringContainsString('Invalid goal definition', $die->payload['message']);
        $this->assertArrayNotHasKey('slimstat_goals', $this->optionStore);
    }

    /** A valueless operator (is_empty) is still allowed to save with no value. */
    public function test_save_goal_allows_valueless_operator_with_empty_value(): void
    {
        $this->setMaxGoals(1);
        $_POST = array_merge(self::VALID_GOAL_POST, ['operator' => 'is_empty', 'value' => '']);

        $die = $this->callHandler('ajax_save_goal');

        $this->assertSame('success', $die->outcome());
        $this->assertCount(1, $this->optionStore['slimstat_goals']);
    }

    /**
     * BUG GUARD (A3) — new goals get a server-assigned id; a client-supplied id
     * that matches no existing goal must be ignored (can't force a collision/overwrite).
     */
    public function test_save_goal_assigns_server_id_and_ignores_client_id_for_new_record(): void
    {
        $this->setMaxGoals(1);
        $_POST = array_merge(self::VALID_GOAL_POST, ['id' => 999999]);

        $die = $this->callHandler('ajax_save_goal');

        $this->assertSame('success', $die->outcome());
        $this->assertSame(1, $this->optionStore['slimstat_goals'][0]['id'], 'New goal must get a server id, not the client-sent 999999');
    }

    /** BUG GUARD (A3) — sequential creates get distinct ids (no microtime collision). */
    public function test_save_goal_assigns_sequential_unique_ids(): void
    {
        $this->setMaxGoals(5);

        $_POST = array_merge(self::VALID_GOAL_POST, ['name' => 'First']);
        $this->callHandler('ajax_save_goal');
        $_POST = array_merge(self::VALID_GOAL_POST, ['name' => 'Second']);
        $this->callHandler('ajax_save_goal');

        $ids = array_column($this->optionStore['slimstat_goals'], 'id');
        $this->assertSame($ids, array_unique($ids), 'Goal ids must be unique');
        $this->assertSame([1, 2], $ids);
    }

    /**
     * BUG GUARD (A4) — paused goals don't count against the active-tier limit, so a
     * hard cap on total stored goals prevents unbounded growth of the option.
     */
    public function test_save_goal_rejects_when_hard_cap_reached(): void
    {
        $this->setMaxGoals(1);
        $this->filterValues['slimstat_goals_hard_cap'] = 2;
        $this->setGoals([
            ['id' => 1, 'name' => 'P1', 'dimension' => 'resource', 'operator' => 'equals', 'value' => '/1', 'active' => false],
            ['id' => 2, 'name' => 'P2', 'dimension' => 'resource', 'operator' => 'equals', 'value' => '/2', 'active' => false],
        ]);
        $_POST = self::VALID_GOAL_POST;

        $die = $this->callHandler('ajax_save_goal');

        $this->assertSame('error', $die->outcome());
        $this->assertStringContainsString('Too many goals stored', $die->payload['message']);
        $this->assertCount(2, $this->optionStore['slimstat_goals']);
    }

    public function test_save_goal_invalidates_cache_version(): void
    {
        $this->optionStore['slimstat_goals_cache_ver'] = '0';
        $this->setMaxGoals(1);
        $_POST = self::VALID_GOAL_POST;

        $this->callHandler('ajax_save_goal');

        $this->assertNotSame('0', (string) $this->optionStore['slimstat_goals_cache_ver']);
    }

    // ---- ajax_delete_goal ----------------------------------------------

    public function test_delete_goal_removes_by_id(): void
    {
        $this->setGoals([
            ['id' => 1, 'name' => 'A', 'dimension' => 'resource', 'operator' => 'equals', 'value' => '/a', 'active' => true],
            ['id' => 2, 'name' => 'B', 'dimension' => 'resource', 'operator' => 'equals', 'value' => '/b', 'active' => true],
        ]);
        $_POST = ['security' => 'x', 'goal_id' => '2'];

        $die = $this->callHandler('ajax_delete_goal');

        $this->assertSame('success', $die->outcome());
        $this->assertCount(1, $this->optionStore['slimstat_goals']);
        $this->assertSame(1, $this->optionStore['slimstat_goals'][0]['id']);
    }

    public function test_delete_goal_rejects_when_nonce_invalid(): void
    {
        $this->nonceValid = false;
        $_POST = ['security' => 'x', 'goal_id' => '1'];

        $die = $this->callHandler('ajax_delete_goal');

        $this->assertSame('nonce_invalid', $die->outcome());
    }

    // ---- ajax_save_funnel ----------------------------------------------

    private function validFunnelSteps(int $n): array
    {
        $steps = [];
        for ($i = 0; $i < $n; $i++) {
            $steps[] = [
                'name'      => 'Step ' . ($i + 1),
                'dimension' => 'resource',
                'operator'  => 'contains',
                'value'     => '/s' . ($i + 1),
                'active'    => 1,
            ];
        }
        return $steps;
    }

    public function test_save_funnel_creates_with_2_steps(): void
    {
        $this->setMaxFunnels(3);
        $_POST = [
            'security'    => 'x',
            'funnel_name' => 'Checkout',
            'steps'       => $this->validFunnelSteps(2),
        ];

        $die = $this->callHandler('ajax_save_funnel');

        $this->assertSame('success', $die->outcome());
        $this->assertCount(1, $this->optionStore['slimstat_funnels']);
        $this->assertCount(2, $this->optionStore['slimstat_funnels'][0]['steps']);
    }

    public function test_save_funnel_creates_with_5_steps(): void
    {
        $this->setMaxFunnels(3);
        $_POST = [
            'security'    => 'x',
            'funnel_name' => 'Long Flow',
            'steps'       => $this->validFunnelSteps(5),
        ];

        $die = $this->callHandler('ajax_save_funnel');

        $this->assertSame('success', $die->outcome());
        $this->assertCount(5, $this->optionStore['slimstat_funnels'][0]['steps']);
    }

    public function test_save_funnel_rejects_fewer_than_2_steps(): void
    {
        $this->setMaxFunnels(3);
        $_POST = [
            'security'    => 'x',
            'funnel_name' => 'Too Short',
            'steps'       => $this->validFunnelSteps(1),
        ];

        $die = $this->callHandler('ajax_save_funnel');

        $this->assertSame('error', $die->outcome());
        $this->assertStringContainsString('2-5 steps', $die->payload['message']);
    }

    public function test_save_funnel_rejects_more_than_5_steps(): void
    {
        $this->setMaxFunnels(3);
        $_POST = [
            'security'    => 'x',
            'funnel_name' => 'Too Long',
            'steps'       => $this->validFunnelSteps(6),
        ];

        $die = $this->callHandler('ajax_save_funnel');

        $this->assertSame('error', $die->outcome());
    }

    public function test_save_funnel_requires_pro(): void
    {
        $this->setMaxFunnels(0);
        $_POST = [
            'security'    => 'x',
            'funnel_name' => 'X',
            'steps'       => $this->validFunnelSteps(2),
        ];

        $die = $this->callHandler('ajax_save_funnel');

        $this->assertSame('error', $die->outcome());
        $this->assertStringContainsString('Pro', $die->payload['message']);
    }

    public function test_save_funnel_rejects_at_max_limit(): void
    {
        $this->setMaxFunnels(1);
        $this->setFunnels([
            ['id' => 1, 'name' => 'Existing', 'steps' => $this->validFunnelSteps(2)],
        ]);
        $_POST = [
            'security'    => 'x',
            'funnel_name' => 'New',
            'steps'       => $this->validFunnelSteps(2),
        ];

        $die = $this->callHandler('ajax_save_funnel');

        $this->assertSame('error', $die->outcome());
        $this->assertStringContainsString('limit reached', $die->payload['message']);
    }

    public function test_save_funnel_updates_existing_by_id(): void
    {
        $this->setMaxFunnels(3);
        $this->setFunnels([
            ['id' => 42, 'name' => 'Old', 'steps' => $this->validFunnelSteps(2)],
        ]);
        $_POST = [
            'security'    => 'x',
            'funnel_id'   => 42,
            'funnel_name' => 'Renamed',
            'steps'       => $this->validFunnelSteps(3),
        ];

        $die = $this->callHandler('ajax_save_funnel');

        $this->assertSame('success', $die->outcome());
        $this->assertCount(1, $this->optionStore['slimstat_funnels']);
        $this->assertSame('Renamed', $this->optionStore['slimstat_funnels'][0]['name']);
        $this->assertCount(3, $this->optionStore['slimstat_funnels'][0]['steps']);
    }

    /**
     * BUG GUARD (A3) — new funnels get a server-assigned id; a client-supplied id
     * matching no existing funnel must be ignored (no microtime, no forced collision).
     */
    public function test_save_funnel_assigns_server_id_and_ignores_client_id_for_new_record(): void
    {
        $this->setMaxFunnels(3);
        $_POST = [
            'security'    => 'x',
            'funnel_id'   => 999999,
            'funnel_name' => 'New',
            'steps'       => $this->validFunnelSteps(2),
        ];

        $die = $this->callHandler('ajax_save_funnel');

        $this->assertSame('success', $die->outcome());
        $this->assertSame(1, $this->optionStore['slimstat_funnels'][0]['id'], 'New funnel must get a server id, not the client-sent 999999');
    }

    /** BUG GUARD (M1) — a funnel step with a value-bearing operator + empty value is rejected. */
    public function test_save_funnel_rejects_step_with_value_bearing_operator_empty_value(): void
    {
        $this->setMaxFunnels(3);
        $steps = $this->validFunnelSteps(2);
        $steps[1]['operator'] = 'equals';
        $steps[1]['value']    = '';
        $_POST = [
            'security'    => 'x',
            'funnel_name' => 'Broken',
            'steps'       => $steps,
        ];

        $die = $this->callHandler('ajax_save_funnel');

        $this->assertSame('error', $die->outcome());
        $this->assertStringContainsString('Invalid step definition', $die->payload['message']);
    }

    // ---- ajax_delete_funnel --------------------------------------------

    public function test_delete_funnel_removes_by_id(): void
    {
        $this->setFunnels([
            ['id' => 1, 'name' => 'A', 'steps' => $this->validFunnelSteps(2)],
            ['id' => 2, 'name' => 'B', 'steps' => $this->validFunnelSteps(3)],
        ]);
        $_POST = ['security' => 'x', 'funnel_id' => '2'];

        $die = $this->callHandler('ajax_delete_funnel');

        $this->assertSame('success', $die->outcome());
        $this->assertCount(1, $this->optionStore['slimstat_funnels']);
        $this->assertSame(1, $this->optionStore['slimstat_funnels'][0]['id']);
    }

    // ---- ajax_load_funnel_data -----------------------------------------

    private function stubWpSlimstatDb(array $stepResults): void
    {
        if (class_exists('wp_slimstat_db', false)) {
            // If the real wp_slimstat_db has been loaded by an earlier test in the
            // same process, our alias was never installed — the handler would hit
            // the real DB (no test double). Fail loudly instead of letting tests
            // silently become order-dependent.
            if (!class_exists(FakeWpSlimstatDb::class, false)
                || !is_subclass_of('wp_slimstat_db', FakeWpSlimstatDb::class)
                   && \get_parent_class('wp_slimstat_db') !== FakeWpSlimstatDb::class) {
                // Accept aliased fakes. Reflection for alias names isn't reliable,
                // so the cheapest guard is: verify our fake is exposing get_funnel_results.
                if (!method_exists('wp_slimstat_db', 'get_funnel_results')
                    || (new \ReflectionClass('wp_slimstat_db'))->getFileName() !== (new \ReflectionClass(FakeWpSlimstatDb::class))->getFileName()) {
                    throw new \RuntimeException(
                        'The real wp_slimstat_db is already loaded; stubWpSlimstatDb() cannot intercept it. Run this test in isolation.'
                    );
                }
            }
            FakeWpSlimstatDb::$next = $stepResults;
            return;
        }
        // Load our fake class before the handler's include_once fires for the real one.
        require_once __DIR__ . '/FakeWpSlimstatDb.php';
        class_alias(FakeWpSlimstatDb::class, 'wp_slimstat_db');
        \Brain\Monkey\Functions\when('plugin_dir_path')->alias(static fn($f) => dirname($f) . '/');
        FakeWpSlimstatDb::$next = $stepResults;
    }

    public function test_load_funnel_data_returns_steps_and_summary(): void
    {
        $this->setFunnels([
            ['id' => 42, 'name' => 'Checkout', 'steps' => $this->validFunnelSteps(2)],
        ]);
        $this->stubWpSlimstatDb([
            ['name' => 'Step 1', 'visitors' => 100, 'pct' => 100,  'dropoff' => 0],
            ['name' => 'Step 2', 'visitors' => 34,  'pct' => 34.0, 'dropoff' => 66],
        ]);
        $_POST = ['security' => 'x', 'funnel_id' => '42'];

        $die = $this->callHandler('ajax_load_funnel_data');

        $this->assertSame('success', $die->outcome());
        $this->assertSame(42, $die->payload['funnel_id']);
        $this->assertCount(2, $die->payload['steps']);
        $this->assertSame(2, $die->payload['summary']['step_count']);
        $this->assertSame(34.0, $die->payload['summary']['total_cr']);
    }

    public function test_test_funnel_step_returns_unique_visitor_count(): void
    {
        // The builder Test must preview UNIQUE VISITORS (the unit the funnel step
        // counts), not raw pageviews — that mismatch (5,556 vs 995) confused QA. The
        // server returns both; `visitors` is what the UI shows. (#1, #3)
        $this->stubWpSlimstatDb([]);
        FakeWpSlimstatDb::$getGoalResults = ['uniques' => 995, 'total' => 5556, 'cr' => 17.9];
        $_POST = [
            'security'  => 'x',
            'name'      => 'Pricing',
            'dimension' => 'resource',
            'operator'  => 'contains',
            'value'     => '/pricing',
        ];

        $die = $this->callHandler('ajax_test_funnel_step');

        $this->assertSame('success', $die->outcome());
        $this->assertSame(995, $die->payload['visitors'], 'Test previews unique visitors (the funnel unit)');
        $this->assertSame(5556, $die->payload['total'], 'total stays in the payload for the server contract');
    }

    public function test_load_funnel_data_returns_null_cr_when_step_one_empty(): void
    {
        $this->setFunnels([
            ['id' => 7, 'name' => 'Empty', 'steps' => $this->validFunnelSteps(2)],
        ]);
        $this->stubWpSlimstatDb([
            ['name' => 'Step 1', 'visitors' => 0, 'pct' => 0, 'dropoff' => 0],
            ['name' => 'Step 2', 'visitors' => 0, 'pct' => 0, 'dropoff' => 0],
        ]);
        $_POST = ['security' => 'x', 'funnel_id' => '7'];

        $die = $this->callHandler('ajax_load_funnel_data');

        $this->assertSame('success', $die->outcome());
        $this->assertNull($die->payload['summary']['total_cr'], 'No-visitors case must surface null, not fake 100%.');
    }

    public function test_load_funnel_data_rejects_unknown_id(): void
    {
        $this->setFunnels([
            ['id' => 1, 'name' => 'A', 'steps' => $this->validFunnelSteps(2)],
        ]);
        $_POST = ['security' => 'x', 'funnel_id' => '999'];

        $die = $this->callHandler('ajax_load_funnel_data');

        $this->assertSame('error', $die->outcome());
        $this->assertStringContainsString('not found', $die->payload['message']);
    }

    public function test_load_funnel_data_rejects_without_view_capability(): void
    {
        $this->capability = false;
        $this->setFunnels([
            ['id' => 1, 'name' => 'A', 'steps' => $this->validFunnelSteps(2)],
        ]);
        $_POST = ['security' => 'x', 'funnel_id' => '1'];

        $die = $this->callHandler('ajax_load_funnel_data');

        $this->assertSame('error', $die->outcome());
    }

    public function test_load_funnel_data_rejects_when_nonce_invalid(): void
    {
        $this->nonceValid = false;
        $_POST = ['security' => 'x', 'funnel_id' => '1'];

        $die = $this->callHandler('ajax_load_funnel_data');

        $this->assertSame('nonce_invalid', $die->outcome());
    }

    public function test_load_funnel_data_exposes_unreachable_count_in_summary(): void
    {
        $this->setFunnels([
            ['id' => 9, 'name' => 'Broken', 'steps' => $this->validFunnelSteps(3)],
        ]);
        $this->stubWpSlimstatDb([
            ['name' => 'Step 1', 'visitors' => 100, 'pct' => 100, 'dropoff' => 0,  'unreachable' => false],
            ['name' => 'Step 2', 'visitors' => 0,   'pct' => 0,   'dropoff' => 100, 'unreachable' => true],
            ['name' => 'Step 3', 'visitors' => 0,   'pct' => 0,   'dropoff' => 0,  'unreachable' => false],
        ]);
        $_POST = ['security' => 'x', 'funnel_id' => '9'];

        $die = $this->callHandler('ajax_load_funnel_data');

        $this->assertSame('success', $die->outcome());
        $this->assertSame(1, $die->payload['summary']['unreachable_count']);
        $this->assertTrue($die->payload['steps'][1]['unreachable']);
    }

    // ---- ajax_test_funnel_step -----------------------------------------

    public function test_test_funnel_step_returns_visitor_count(): void
    {
        $this->stubWpSlimstatDb([]);
        // Ensure the class alias exists so the handler's include_once no-ops.
        \WpSlimstat\Tests\Integration\FakeWpSlimstatDb::$getGoalResults = ['uniques' => 42, 'total' => 100, 'cr' => 50];
        $_POST = [
            'security'  => 'x',
            'name'      => 'Cart',
            'dimension' => 'resource',
            'operator'  => 'contains',
            'value'     => '/cart',
            'active'    => 1,
        ];

        $die = $this->callHandler('ajax_test_funnel_step');

        $this->assertSame('success', $die->outcome());
        $this->assertSame(42, $die->payload['visitors']);
    }

    public function test_test_funnel_step_rejects_invalid_step(): void
    {
        $_POST = ['security' => 'x']; // missing name/dimension/operator

        $die = $this->callHandler('ajax_test_funnel_step');

        $this->assertSame('error', $die->outcome());
        $this->assertStringContainsString('required', $die->payload['message']);
    }

    /**
     * BUG GUARD (A9) — the live "Test step" affordance runs an arbitrary admin rule
     * (incl. REGEXP) against slim_stats, so it requires the admin capability rather
     * than the broader view capability.
     */
    public function test_test_funnel_step_rejects_without_admin_capability(): void
    {
        $this->capability = false;
        $_POST = [
            'security'  => 'x',
            'name'      => 'Step',
            'dimension' => 'resource',
            'operator'  => 'contains',
            'value'     => '/x',
        ];

        $die = $this->callHandler('ajax_test_funnel_step');

        $this->assertSame('error', $die->outcome());
        $this->assertStringContainsString('Insufficient', $die->payload['message']);
    }

    public function test_test_funnel_step_rejects_when_nonce_invalid(): void
    {
        $this->nonceValid = false;
        $_POST = ['security' => 'x', 'name' => 'X', 'dimension' => 'resource', 'operator' => 'equals'];

        $die = $this->callHandler('ajax_test_funnel_step');

        $this->assertSame('nonce_invalid', $die->outcome());
    }
}
