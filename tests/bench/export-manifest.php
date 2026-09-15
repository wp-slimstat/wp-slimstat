<?php
// Harness-owned v5-era export schema. Do not derive this from either installed arm.
$column = static function ($name, $type, $nullable = true) {
    return [$name, $type, $nullable];
};

return [
    'slim_stats' => [
        'order_by' => 'id',
        'columns' => [
            $column('id', 'INT UNSIGNED', false), $column('ip', 'VARCHAR(39)'),
            $column('other_ip', 'VARCHAR(39)'), $column('username', 'VARCHAR(256)'),
            $column('email', 'VARCHAR(256)'), $column('country', 'VARCHAR(16)'),
            $column('location', 'VARCHAR(36)'), $column('city', 'VARCHAR(256)'),
            $column('referer', 'VARCHAR(2048)'), $column('resource', 'VARCHAR(2048)'),
            $column('searchterms', 'VARCHAR(2048)'), $column('notes', 'VARCHAR(2048)'),
            $column('visit_id', 'INT UNSIGNED', false), $column('server_latency', 'INT(10) UNSIGNED'),
            $column('page_performance', 'INT(10) UNSIGNED'), $column('browser', 'VARCHAR(40)'),
            $column('browser_version', 'VARCHAR(15)'), $column('browser_type', 'TINYINT UNSIGNED'),
            $column('platform', 'VARCHAR(15)'), $column('language', 'VARCHAR(5)'),
            $column('fingerprint', 'VARCHAR(256)'), $column('user_agent', 'VARCHAR(2048)'),
            $column('resolution', 'VARCHAR(12)'), $column('screen_width', 'SMALLINT UNSIGNED'),
            $column('screen_height', 'SMALLINT UNSIGNED'), $column('content_type', 'VARCHAR(64)'),
            $column('category', 'VARCHAR(256)'), $column('author', 'VARCHAR(64)'),
            $column('content_id', 'BIGINT(20) UNSIGNED'), $column('outbound_resource', 'VARCHAR(2048)'),
            $column('tz_offset', 'SMALLINT'), $column('dt_out', 'INT(10) UNSIGNED'),
            $column('dt', 'INT(10) UNSIGNED'),
        ],
    ],
    'slim_events' => [
        'order_by' => 'event_id',
        'columns' => [
            $column('event_id', 'INT(10)', false), $column('type', 'TINYINT UNSIGNED'),
            $column('event_description', 'VARCHAR(64)'), $column('notes', 'VARCHAR(256)'),
            $column('position', 'VARCHAR(32)'), $column('id', 'INT UNSIGNED', false),
            $column('dt', 'INT(10) UNSIGNED'),
        ],
    ],
];
