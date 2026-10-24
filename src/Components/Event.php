<?php

namespace SlimStat\Components;

class Event
{
    /**
     * Schedule a cron event
     *
     * @param string $hook
     * @param int $timestamp
     * @param string $recurrence
     * @param callable $callback
     */
    public static function schedule($hook, $timestamp, $recurrence, $callback)
    {
        $next = wp_next_scheduled($hook);
        // Repair duplicate legacy schedules without moving the next due check.
        if ($next) {
            foreach (_get_cron_array() as $due => $events) {
                foreach ($events[$hook] ?? [] as $event) {
                    if ((int) $due !== $next || !empty($event['args'])) {
                        wp_unschedule_event($due, $hook, $event['args']);
                    }
                }
            }
        }
        if (!$next) {
            wp_schedule_event($timestamp, $recurrence, $hook);
        }

        add_action($hook, $callback);
    }

    /**
     * Unschedule a cron event
     *
     * @param string $hook
     */
    public static function unschedule($hook)
    {
        $timestamp = wp_next_scheduled($hook);
        if ($timestamp) {
            wp_unschedule_event($timestamp, $hook);
        }
    }
}