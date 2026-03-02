<?php

namespace SlimStat\Components;

class Ajax
{
    /**
     * Register an AJAX action
     *
     * @param string $action
     * @param callable $callback
     */
    public static function register($action, $callback)
    {
        \add_action('wp_ajax_slimstat_' . $action, $callback);
        \add_action('wp_ajax_nopriv_slimstat_' . $action, $callback);
    }
}