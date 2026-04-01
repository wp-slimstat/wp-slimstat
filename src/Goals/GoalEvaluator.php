<?php

namespace SlimStat\Goals;

use SlimStat\Tracker\Storage;

class GoalEvaluator
{
    private static $goals = null;

    public static function init()
    {
        add_action('slimstat_track_success', [self::class, 'evaluate']);
    }

    public static function get_goals()
    {
        if (self::$goals === null) {
            self::$goals = get_option('slimstat_goals', []);
            if (!is_array(self::$goals)) {
                self::$goals = [];
            }
        }
        return self::$goals;
    }

    public static function save_goals($goals)
    {
        self::$goals = $goals;
        update_option('slimstat_goals', $goals);
    }

    public static function evaluate()
    {
        $goals = self::get_goals();
        if (empty($goals)) {
            return;
        }

        $stat = \wp_slimstat::get_stat();
        if (empty($stat) || empty($stat['id'])) {
            return;
        }

        foreach ($goals as $goal) {
            if (empty($goal['active'])) {
                continue;
            }

            if (self::matches($goal, $stat)) {
                self::record($goal, $stat);
            }
        }
    }

    private static function matches($goal, $stat)
    {
        if (empty($goal['conditions']) || !is_array($goal['conditions'])) {
            return false;
        }

        foreach ($goal['conditions'] as $condition) {
            if (!self::evaluate_condition($condition, $stat)) {
                return false;
            }
        }

        return true;
    }

    private static function evaluate_condition($condition, $stat)
    {
        $field    = $condition['field'] ?? '';
        $operator = $condition['operator'] ?? 'equals';
        $value    = $condition['value'] ?? '';

        if (empty($field) || !isset($stat[$field])) {
            return false;
        }

        $actual = $stat[$field];

        switch ($operator) {
            case 'equals':
                return $actual === $value;

            case 'contains':
                return false !== strpos($actual, $value);

            case 'starts_with':
                return 0 === strpos($actual, $value);

            case 'ends_with':
                return substr($actual, -strlen($value)) === $value;

            case 'matches':
                return (bool) @preg_match('#' . $value . '#', $actual);

            case 'not_equals':
                return $actual !== $value;

            case 'not_contains':
                return false === strpos($actual, $value);

            default:
                return false;
        }
    }

    private static function record($goal, $stat)
    {
        global $wpdb;

        $event_info = [
            'type'              => 1,
            'event_description' => sanitize_text_field(mb_substr($goal['name'] ?? 'Goal', 0, 64)),
            'notes'             => wp_json_encode([
                'goal_id'   => $goal['id'] ?? 0,
                'goal_type' => $goal['type'] ?? 'page_visit',
                'type'      => 'goal',
            ]),
            'id' => intval($stat['id']),
            'dt' => \wp_slimstat::date_i18n('U'),
        ];

        Storage::insertRow($event_info, $wpdb->prefix . 'slim_events');
    }
}
