<?php
/**
 * MU-Plugin: Custom DB Simulator
 *
 * Simulates the slimstat_custom_wpdb filter used by the external database addon.
 * When the WP option "slimstat_test_use_custom_db" is set to "yes", this plugin
 * hooks into the filter and returns a cloned wpdb instance with a different table
 * prefix (slimext_), simulating an external database configuration.
 *
 * Used exclusively by E2E tests for the DataBuckets custom-db accuracy spec.
 * Remove this file (or set the option to anything other than "yes") to disable.
 */

add_filter('slimstat_custom_wpdb', function ($current_wpdb) {
    if (get_option('slimstat_test_use_custom_db') !== 'yes') {
        return $current_wpdb;
    }

    // Use $GLOBALS to avoid PHP 8.2 fatal: cannot use global with same name as parameter
    $custom         = clone $GLOBALS['wpdb'];
    $custom->prefix = 'slimext_';

    // Reset the protected $result property to prevent "mysqli_result already closed" errors.
    // clone() creates a shallow copy: $custom->result still references the same mysqli_result
    // object as $GLOBALS['wpdb']->result. When the original wpdb frees that result on its next
    // query, $custom->result holds a dangling closed resource → fatal on next use.
    try {
        $ref = new ReflectionProperty('wpdb', 'result');
        $ref->setAccessible(true);
        $ref->setValue($custom, null);
    } catch (ReflectionException $e) {
        // If reflection fails, proceed anyway; the worst case is a query-monitor warning.
    }

    return $custom;
});
