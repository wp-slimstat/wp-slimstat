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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Input accessor only; NotificationActions checks capability and wp_rest nonce before reading it.
        return isset($_GET[$key]) ? \sanitize_text_field(\wp_unslash($_GET[$key])) : $default;
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
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Input accessor only; mutation callers must verify their action nonce before using this value.
        return isset($_POST[$key]) ? \sanitize_text_field(\wp_unslash($_POST[$key])) : $default;
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Input accessor only; mutation callers must verify their action nonce before using this value.
        return isset($_REQUEST[$key]) ? \sanitize_text_field(\wp_unslash($_REQUEST[$key])) : $default;
    }
}
