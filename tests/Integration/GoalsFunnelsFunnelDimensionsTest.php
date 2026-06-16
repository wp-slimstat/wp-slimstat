<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

/**
 * Funnel steps are restricted to action-oriented dimensions (Page URL, Content
 * Type, Content ID, Search Terms, Event). Attribute dimensions (Country, Browser,
 * Operating System, Referer, Username) describe WHO a visitor is, not what they
 * DID, so they stay reserved for goals — a "Country = gb" goal is legitimate, a
 * "Homepage -> Chrome -> Checkout" funnel is not. Enforced in the builder dropdown
 * and server-side on save + test. (#17)
 */
class GoalsFunnelsFunnelDimensionsTest extends IntegrationTestCase
{
    public function test_funnel_step_dimensions_are_the_action_oriented_subset(): void
    {
        $dims = \wp_slimstat_admin::get_funnel_step_dimensions();
        $this->assertSame(
            ['resource', 'content_type', 'content_id', 'searchterms', 'event_notes'],
            array_keys($dims),
            'Funnel steps must offer exactly the 5 action-oriented dimensions, in canonical order'
        );
        foreach (['country', 'browser', 'platform', 'referer', 'username'] as $attr) {
            $this->assertArrayNotHasKey($attr, $dims, "$attr is an attribute, not an action, and must not be a funnel step");
        }
    }

    public function test_funnel_step_dimensions_reuse_goal_dimension_labels(): void
    {
        // Sliced from get_goal_dimensions() so labels never drift from the canonical list.
        $goal   = \wp_slimstat_admin::get_goal_dimensions();
        $funnel = \wp_slimstat_admin::get_funnel_step_dimensions();
        foreach ($funnel as $key => $label) {
            $this->assertArrayHasKey($key, $goal);
            $this->assertSame($goal[$key], $label, "Funnel dimension '$key' label must match the goal list");
        }
    }

    public function test_save_funnel_rejects_an_attribute_dimension_step(): void
    {
        $this->setMaxFunnels(3);
        $_POST = [
            'security'    => 'x',
            'funnel_name' => 'Bad',
            'steps'       => [
                ['name' => 'Home',    'dimension' => 'resource', 'operator' => 'contains', 'value' => '/',      'active' => 1],
                ['name' => 'Browser', 'dimension' => 'browser',  'operator' => 'contains', 'value' => 'Chrome', 'active' => 1],
            ],
        ];

        try {
            \wp_slimstat_admin::ajax_save_funnel();
            $this->fail('Expected ajax_save_funnel to reject the attribute-dimension step');
        } catch (WpAjaxDie $die) {
            $this->assertSame('error', $die->outcome(), 'A funnel step using an attribute dimension must be rejected');
        }
        $this->assertArrayNotHasKey('slimstat_funnels', $this->optionStore, 'Nothing must persist when a step is invalid');
    }

    public function test_save_funnel_accepts_action_dimension_steps(): void
    {
        $this->setMaxFunnels(3);
        $_POST = [
            'security'    => 'x',
            'funnel_name' => 'Good',
            'steps'       => [
                ['name' => 'Home',   'dimension' => 'resource',    'operator' => 'contains', 'value' => '/',       'active' => 1],
                ['name' => 'Search', 'dimension' => 'searchterms', 'operator' => 'contains', 'value' => 'pricing', 'active' => 1],
            ],
        ];

        try {
            \wp_slimstat_admin::ajax_save_funnel();
            $this->fail('Expected ajax_save_funnel to die with success');
        } catch (WpAjaxDie $die) {
            $this->assertSame('success', $die->outcome(), 'Action-oriented funnel steps must save');
        }
    }

    public function test_goals_still_accept_attribute_dimensions(): void
    {
        // Goals keep the full dimension list — "Country = gb" is a valid goal.
        $this->setMaxGoals(5);
        $_POST = [
            'security'  => 'x',
            'name'      => 'UK visitors',
            'dimension' => 'country',
            'operator'  => 'equals',
            'value'     => 'gb',
            'active'    => 1,
        ];

        try {
            \wp_slimstat_admin::ajax_save_goal();
            $this->fail('Expected ajax_save_goal to die with success');
        } catch (WpAjaxDie $die) {
            $this->assertSame('success', $die->outcome(), 'Goals must still accept attribute dimensions like country');
        }
    }

    public function test_funnel_builder_template_uses_the_restricted_list(): void
    {
        // Wiring guard: the builder's step dropdown iterates the restricted set,
        // and the DOM printer hands it that set.
        $builder = (string) file_get_contents(
            dirname(__DIR__, 2) . '/admin/view/partials/goals-funnels/funnel-builder.php'
        );
        $this->assertStringContainsString('foreach ($funnel_step_dimensions', $builder);
        $this->assertStringNotContainsString('foreach ($dimensions', $builder, 'builder must not fall back to the full goal list');

        $admin = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/index.php');
        $this->assertStringContainsString(
            '$funnel_step_dimensions = self::get_funnel_step_dimensions();',
            $admin,
            'print_goals_funnels_dom must pass the restricted list to the builder'
        );
    }
}
