<?php
/**
 * @package wp-slimstat
 * @license GPL-2.0-or-later
 *
 * Copyright (C) 2026 VeronaLabs <info@veronalabs.com>
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or any later version.
 * This program is distributed WITHOUT ANY WARRANTY; without even the implied
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See <https://www.gnu.org/licenses/> for the full license.
 */
/**
 * Known visitors and authors exclude missing identities before grouping and limiting.
 * Runs the registered callbacks and real Query split/merge against an in-memory database.
 * Run: php tests/known-identity-reports-test.php
 */

define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
function __($text, $domain = '') { return $text; }
function apply_filters($hook, $value, ...$args) { return $value; }
function get_transient($key) { return false; }
function set_transient($key, $value, $expiration = 0) { return true; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }

class wp_slimstat
{
    public static $wpdb;
    public static function now() { return 172800 + 3600; }
}

require_once dirname(__DIR__) . '/src/Utils/NetworkMerge.php';
require_once dirname(__DIR__) . '/src/Utils/Query.php';
require_once dirname(__DIR__) . '/admin/view/wp-slimstat-db.php';
require_once dirname(__DIR__) . '/admin/view/wp-slimstat-reports.php';

$sqlite = new SQLite3(':memory:');
$sqlite->enableExceptions(true);
$sqlite->exec('CREATE TABLE wp_slim_stats (id INTEGER PRIMARY KEY, username TEXT, author TEXT, language TEXT, dt INTEGER)');
$GLOBALS['wpdb'] = wp_slimstat::$wpdb = new class($sqlite) {
    public $prefix = 'wp_';
    private $db;
    public function __construct($db) { $this->db = $db; }
    public function prepare($sql, ...$args)
    {
        $args = is_array($args[0] ?? null) ? $args[0] : $args;
        return preg_replace_callback('/%[sd]/', static function ($match) use (&$args) {
            $value = array_shift($args);
            return '%d' === $match[0] ? (string) (int) $value : "'" . SQLite3::escapeString((string) $value) . "'";
        }, $sql);
    }
    public function get_results($sql, $format)
    {
        $result = $this->db->query($sql);
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $rows[] = $row; }
        return $rows;
    }
};

// Read the registered literal callback args without executing source expressions.
// These two reports use scalar strings and a raw callback tuple; reject other shapes.
$tokens = token_get_all(file_get_contents(dirname(__DIR__) . '/admin/view/wp-slimstat-reports.php'));
$report = static function ($id) use ($tokens) {
    $found = false;
    $callback = false;
    $depth = 0;
    $json = '';
    foreach ($tokens as $token) {
        if (!$found) {
            $found = is_array($token) && T_CONSTANT_ENCAPSED_STRING === $token[0] && "'{$id}'" === $token[1];
            continue;
        }
        if (!$callback) {
            $callback = is_array($token) && T_CONSTANT_ENCAPSED_STRING === $token[0] && "'callback_args'" === $token[1];
            continue;
        }
        if ('[' === $token) {
            $json .= 0 === $depth++ ? '{' : '[';
        } elseif (']' === $token) {
            $json = rtrim($json, ',') . (0 === --$depth ? '}' : ']');
            if (0 === $depth) { return json_decode($json, true, 512, JSON_THROW_ON_ERROR); }
        } elseif (0 === $depth || (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) {
            continue;
        } elseif (',' === $token) {
            $json .= ',';
        } elseif (is_array($token) && T_DOUBLE_ARROW === $token[0] && 1 === $depth) {
            $json .= ':';
        } elseif (is_array($token) && T_CONSTANT_ENCAPSED_STRING === $token[0]) {
            $body = substr($token[1], 1, -1);
            $value = '"' === $token[1][0] ? stripcslashes($body) : str_replace(["\\\\", "\\'"], ["\\", "'"], $body);
            $json .= json_encode($value, JSON_THROW_ON_ERROR);
        } else {
            throw new RuntimeException('Unsupported callback argument in ' . $id);
        }
    }
    throw new RuntimeException('Missing registration: ' . $id);
};

// Missing identities dominate both halves; named commenters and deleted accounts remain valid.
foreach ([172799, 172800] as $dt) {
    foreach ([null, null, null, null, '', '', '', 'commenter', 'commenter', 'deleted-account', '0'] as $name) {
        $value = null === $name ? 'NULL' : "'" . SQLite3::escapeString($name) . "'";
        $sqlite->exec("INSERT INTO wp_slim_stats (username, author, language, dt) VALUES ($value, $value, NULL, $dt)");
    }
}
$failures = [];
$check = static function ($label, $got, $want) use (&$failures) {
    if ($got !== $want) { $failures[] = $label . ': ' . json_encode(['got' => $got, 'want' => $want]); }
};
foreach (['historical' => [86400, 172799, 1], 'live' => [172800, 176400, 1], 'split' => [86400, 176400, 2]] as $window => $range) {
    wp_slimstat_db::$filters_normalized = [
        'utime' => ['start' => $range[0], 'end' => $range[1]],
        'misc' => ['limit_results' => 200], 'columns' => [],
    ];
    foreach (['slim_p1_11' => 'username', 'slim_p4_18' => 'author'] as $id => $column) {
        $args = $report($id);
        $rows = call_user_func($args['raw'], $args);
        $check("$window $id names", array_column($rows, $column), ['commenter', '0', 'deleted-account']);
        $check("$window $id hits", array_map('intval', array_column($rows, 'counthits')), [2 * $range[2], $range[2], $range[2]]);
        wp_slimstat_db::$filters_normalized['misc']['limit_results'] = 1;
        $check("$window $id limit", array_column(call_user_func($args['raw'], $args), $column), ['commenter']);
        wp_slimstat_db::$filters_normalized['misc']['limit_results'] = 200;
        // Pro's author-scoped email report appends its restriction to these same args.
        $args['where'] = '(' . ($args['where'] ?? '1=1') . ") AND author = 'deleted-account'";
        $check("$window $id scoped", array_column(call_user_func($args['raw'], $args), $column), ['deleted-account']);
    }
    // NULL is still a legitimate group for reports such as languages; do not undo D5.
    $rows = wp_slimstat_db::get_top('language');
    $check("$window language keys", array_column($rows, 'language'), [null]);
    $check("$window all pageviews", (int) ($rows[0]['counthits'] ?? 0), 11 * $range[2]);
}
// Content authors and visitor identities are independent: do not filter on the other field.
$sqlite->exec('DELETE FROM wp_slim_stats');
$sqlite->exec("INSERT INTO wp_slim_stats (username, author, dt) VALUES (NULL, 'content-author', 172800), ('known-visitor', NULL, 172800)");
$check('anonymous visitor to authored content', array_column(wp_slimstat_db::get_top($report('slim_p4_18')), 'author'), ['content-author']);
$check('known visitor to unassigned content', array_column(wp_slimstat_db::get_top($report('slim_p1_11')), 'username'), ['known-visitor']);
foreach (["(NULL, NULL, 172800), ('', '', 172800)", ''] as $missing) {
    $sqlite->exec('DELETE FROM wp_slim_stats');
    if ($missing) { $sqlite->exec('INSERT INTO wp_slim_stats (username, author, dt) VALUES ' . $missing); }
    foreach (['slim_p1_11', 'slim_p4_18'] as $id) {
        $check("$id without named rows", wp_slimstat_db::get_top($report($id)), []);
    }
}
if ($failures) {
    fwrite(STDERR, "FAIL\n" . implode("\n", $failures) . "\n");
    exit(1);
}
echo "PASS: known identities, limits, author scopes and NULL control across historical/live/split ranges\n";
