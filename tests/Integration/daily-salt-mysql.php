<?php
/** Exercise the first-mint race using MySQL's actual duplicate-key result. */
global $wpdb;
if (!defined('WP_CLI') || !WP_CLI || !preg_match('/^(tests?[-_]|wordpress$)/', DB_NAME)) {
    throw new RuntimeException('Disposable database required');
}
$original = $wpdb->options;
$table = 'slimstat_salt_' . bin2hex(random_bytes(6));
if (false === $wpdb->query("CREATE TEMPORARY TABLE `$table` LIKE `$original`")) {
    throw new RuntimeException($wpdb->last_error);
}
try {
    $wpdb->options = $table;
    $winner = ['date' => gmdate('Y-m-d'), 'salt' => 'first-mint-winner'];
    if (false === $wpdb->insert($table, ['option_name' => 'slimstat_daily_salt', 'option_value' => serialize($winner), 'autoload' => 'yes'])) {
        throw new RuntimeException($wpdb->last_error);
    }
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('notoptions', 'options');
    wp_cache_delete('slimstat_daily_salt', 'options');
    // B read no row; A inserted the winner before B could claim it.
    $claim = new ReflectionMethod(\SlimStat\Providers\IPHashProvider::class, 'claimSaltFor');
    $claim->setAccessible(true);
    $actual = $claim->invoke(null, $winner['date'], false);
    if ($actual !== $winner['salt'] || get_option('slimstat_daily_salt') !== $winner) {
        throw new RuntimeException('First-mint loser must adopt the winner without replacing it: ' . $wpdb->last_error);
    }
    echo "PASS: first daily-salt race retains one shared identity salt\n";
} finally {
    $wpdb->options = $original;
    $wpdb->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('notoptions', 'options');
    wp_cache_delete('slimstat_daily_salt', 'options');
}
