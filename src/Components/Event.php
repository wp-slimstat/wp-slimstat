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
        if (!wp_next_scheduled($hook)) {
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