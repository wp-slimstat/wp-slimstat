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
}
