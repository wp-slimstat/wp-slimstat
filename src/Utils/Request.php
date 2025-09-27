<?php

namespace SlimStat\Utils;

class Request
{
    /**
     * Get a value from $_GET array
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get($key, $default = null)
    {
        return isset($_GET[$key]) ? \sanitize_text_field($_GET[$key]) : $default;
    }

    /**
     * Get a value from $_POST array
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function post($key, $default = null)
    {
        return isset($_POST[$key]) ? \sanitize_text_field($_POST[$key]) : $default;
    }

    /**
     * Get a value from $_REQUEST array
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function request($key, $default = null)
    {
        return isset($_REQUEST[$key]) ? \sanitize_text_field($_REQUEST[$key]) : $default;
    }
}